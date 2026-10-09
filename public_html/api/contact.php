<?php
declare(strict_types=1);

/**
 * POST /api/contact.php
 *
 * The contact form shown when the assistant is unavailable (Section 45) or
 * when a visitor asks for a person. It stores a lead with no model call, so
 * it keeps working during an outage.
 */

require __DIR__ . '/_api.php';

$ctx   = api_context();
$agent = $ctx['agent'];
$input = $ctx['input'];

$requestId = Validator::opaqueId($input['request_id'] ?? null);
if ($requestId === null) {
    api_fail('request_id_required', 400, $ctx['cors']);
}

// Idempotency (Section 32): the same submission never creates two leads.
$existing = db()->one(
    "SELECT state FROM request_log WHERE request_id = ? AND endpoint = 'contact' LIMIT 1",
    [$requestId]
);
if ($existing !== null) {
    api_respond(['ok' => true, 'stored' => true, 'replayed' => true], 200, $ctx['cors']);
}

if (trim((string)($input['company_website_hp'] ?? '')) !== '') {
    app_log('abuse', 'honeypot triggered on contact form', ['agent' => (int)$agent['id']]);
    api_respond(['ok' => true, 'stored' => true], 200, $ctx['cors']);
}

// ---- Validate the submitted fields --------------------------------------
$name    = Validator::text($input['name'] ?? null, 160);
$email   = Validator::email($input['email'] ?? null);
$phone   = Validator::phone($input['phone'] ?? null);
$company = Validator::text($input['company'] ?? null, 200);
$note    = Validator::paragraph($input['note'] ?? null, 1000);

// At least one contact channel is required (Section 7).
if ($email === null && $phone === null) {
    api_fail('contact_required', 422, $ctx['cors'], [
        'message' => 'Enter an email address or a phone number so the team can reach you.',
    ]);
}

db()->insert('request_log', [
    'request_id' => $requestId,
    'agent_id'   => (int)$agent['id'],
    'endpoint'   => 'contact',
    'state'      => 'in_progress',
]);

$visitorId = Validator::opaqueId($input['visitor_id'] ?? null);
$sessionId = Validator::opaqueId($input['session_id'] ?? null, 48);

db()->begin();
try {
    // Attach to the existing conversation where there is one.
    $conversation = null;
    if ($sessionId !== null) {
        $conversation = db()->one(
            'SELECT * FROM conversations WHERE session_id = ? AND agent_id = ? LIMIT 1',
            [$sessionId, (int)$agent['id']]
        );
    }

    if ($conversation) {
        $lead = LeadManager::ensureLead($agent, $conversation);
    } else {
        // No conversation: a standalone lead from the offline form.
        $prior = LeadManager::findByVisitorId((int)$agent['id'], $visitorId);
        if ($prior) {
            $lead = $prior;
        } else {
            $leadId = db()->insert('leads', [
                'agent_id'   => (int)$agent['id'],
                'reference'  => LeadManager::generateReference(),
                'status'     => 'new',
                'visitor_id' => $visitorId,
                'first_seen_ip' => Security::clientIp(),
                'source_url' => Validator::text($input['page_url'] ?? null, 500),
            ]);
            $lead = db()->one('SELECT * FROM leads WHERE id = ?', [$leadId]) ?? [];
        }
    }

    LeadManager::applyFields($agent, $lead, [
        'name'         => $name,
        'email'        => $email,
        'phone'        => $phone,
        'company'      => $company,
        'requirements' => $note,
    ], 'offline_contact_form');

    $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;

    LeadManager::advanceStatus($agent, $lead, 'contact_captured');
    LeadManager::markHandoff($agent, $lead, 'Submitted the contact form');
    LeadManager::refreshDedupFlag($agent, $lead);

    if ($conversation) {
        ConversationStore::appendMessage($conversation, 'system', 'Visitor submitted the contact form.', [
            'response_status' => 'contact_form',
        ]);
        ConversationStore::update($conversation, [
            'handoff_triggered' => 1,
            'handoff_reason'    => 'Submitted the contact form',
        ]);
    }

    db()->exec(
        "UPDATE request_log SET state = 'complete', completed_at = ? WHERE request_id = ? AND endpoint = 'contact'",
        [gmdate('Y-m-d H:i:s'), $requestId]
    );

    db()->commit();
} catch (Throwable $ex) {
    db()->rollback();
    app_log('contact', 'submission failed', ['error' => $ex->getMessage()]);
    api_fail('server_error', 500, $ctx['cors']);
}

$lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
LeadManager::notifyNewLead($agent, $lead, 'handoff');

api_respond([
    'ok'      => true,
    'stored'  => true,
    'message' => 'Thank you. A member of the team will be in touch.',
], 200, $ctx['cors']);
