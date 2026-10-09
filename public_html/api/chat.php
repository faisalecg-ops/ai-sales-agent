<?php
declare(strict_types=1);

/**
 * POST /api/chat.php
 *
 * One visitor turn. All business logic lives in ChatOrchestrator.
 */

require __DIR__ . '/_api.php';

$ctx   = api_context();
$agent = $ctx['agent'];
$input = $ctx['input'];

// ---- Request validation -------------------------------------------------
$requestId = Validator::opaqueId($input['request_id'] ?? null);
if ($requestId === null) {
    api_fail('request_id_required', 400, $ctx['cors']);
}

$sessionId = Validator::opaqueId($input['session_id'] ?? null, 48);
if ($sessionId === null) {
    api_fail('session_required', 400, $ctx['cors']);
}

$message = (string)($input['message'] ?? '');
$message = str_replace("\0", '', $message);
$message = trim($message);

if ($message === '') {
    api_fail('empty_message', 400, $ctx['cors']);
}

$maxChars = max(80, (int)$agent['max_input_chars']);
if (mb_strlen($message) > $maxChars) {
    api_fail('message_too_long', 413, $ctx['cors'], ['max_characters' => $maxChars]);
}

// Honeypot: the widget renders a field no human ever sees (Section 47).
if (trim((string)($input['company_website_hp'] ?? '')) !== '') {
    app_log('abuse', 'honeypot triggered', ['agent' => (int)$agent['id']]);
    api_fail('rate_limited', 429, $ctx['cors']);
}

// ---- Run the turn -------------------------------------------------------
// Room to wait for a busy AI (up to about 25 seconds) on top of the answer itself.
@set_time_limit(120);
try {
    $response = ChatOrchestrator::handle($agent, [
        'request_id'     => $requestId,
        'session_id'     => $sessionId,
        'session_secret' => (string)($input['session_secret'] ?? ''),
        'visitor_id'     => $input['visitor_id'] ?? null,
        'message'        => $message,
        'origin'         => $ctx['origin'],
        'retry'          => !empty($input['retry']),
    ]);
} catch (RuntimeException $ex) {
    $known = ['invalid_session', 'unknown_session'];
    if (in_array($ex->getMessage(), $known, true)) {
        api_fail('session_expired', 409, $ctx['cors']);
    }
    throw $ex;
}

api_respond($response, 200, $ctx['cors']);
