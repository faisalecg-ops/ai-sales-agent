<?php
declare(strict_types=1);

/**
 * One company in one campaign: why it was found, what research showed, the
 * email written for it, and every decision about it.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_role('admin');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$target = Migrations::tableExists('prospect_targets') ? Prospecting::target($agentId, $id) : null;
if ($target === null) {
    admin_layout_start('Not found', ['Campaigns' => url('admin/prospects.php')]);
    echo '<div class="alert alert-error"><p>That company is not in any of this exhibition\'s campaigns.</p></div>';
    admin_layout_end();
    exit;
}

$guard = Security::csrfField() . '<input type="hidden" name="agent_id" value="' . $agentId . '">'
       . '<input type="hidden" name="id" value="' . $id . '">';
$back = 'admin/prospect.php?id=' . $id;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    if ((int)($_POST['agent_id'] ?? 0) !== $agentId) {
        admin_flash('That page was showing a different exhibition, so nothing was done.', 'error');
        admin_redirect('admin/prospects.php');
    }
    Audit::setActor($actor, (int)$user['id']);
    $action = (string)($_POST['action'] ?? '');
    $emailId = (int)($_POST['email_id'] ?? 0);

    switch ($action) {
        case 'save':
        case 'save_approve':
            $warning = ProspectMailer::saveEdit($agent, $emailId, (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? ''), $actor);
            if ($warning !== null && $action === 'save_approve') {
                admin_flash('Saved, but not approved: ' . $warning . ' Change it, or approve it as it is if you are sure.', 'warn');
                break;
            }
            if ($action === 'save_approve') {
                $why = ProspectMailer::approve($agentId, $emailId, $actor);
                admin_flash($why ?? 'Saved and approved. It will go out within this exhibition\'s pace.', $why !== null ? 'error' : 'ok');
            } else {
                admin_flash($warning !== null ? 'Saved. Worth a second look: ' . $warning : 'Saved.', $warning !== null ? 'warn' : 'ok');
            }
            break;

        case 'approve':
            $why = ProspectMailer::approve($agentId, $emailId, $actor);
            admin_flash($why ?? 'Approved. It will go out within this exhibition\'s pace.', $why !== null ? 'error' : 'ok');
            break;

        case 'rewrite':
            if (!in_array((string)$target['state'], ['qualified', 'awaiting_approval', 'approved', 'email_failed'], true)) {
                admin_flash('This company is now "' . (Prospecting::STATES[(string)$target['state']] ?? '') . '", so no email is written for it.', 'error');
                break;
            }
            @set_time_limit(120);
            $fresh = Prospecting::target($agentId, $id);
            $newId = $fresh !== null ? OutreachWriter::draft($agent, $fresh, $actor) : null;
            admin_flash($newId !== null ? 'Rewritten. Read it before approving.' : 'It could not be rewritten.', $newId !== null ? 'ok' : 'error');
            break;

        case 'reject':
            ProspectMailer::reject($agentId, $id, $actor, trim((string)($_POST['reason'] ?? '')));
            admin_flash('Rejected for this campaign. Nothing will be sent.');
            break;

        case 'do_not_contact':
            ProspectMailer::doNotContact($agentId, $id, $actor);
            admin_flash('Marked do not contact. No campaign will email this company.');
            break;

        case 'add_email':
            if (!in_array((string)$target['state'], ['no_email', 'low_fit', 'qualified'], true)) {
                admin_flash('This company is now "' . (Prospecting::STATES[(string)$target['state']] ?? '') . '", so no address can be added.', 'error');
                break;
            }
            $email = Validator::email((string)($_POST['contact_email'] ?? ''));
            if ($email === null) {
                admin_flash('That is not a valid email address.', 'error');
                break;
            }
            if (WhatsAppEngine::blockedReason($agentId, null, $email, (int)$target['company_id']) !== null) {
                admin_flash('That address or company is on the do-not-contact list.', 'error');
                break;
            }
            Prospecting::setState($target, 'qualified', 'team', 'Email address added by ' . $actor . '.', [
                'contact_email' => mb_strtolower($email), 'contact_source' => 'Added by ' . $actor,
                'contact_name'  => Validator::text($_POST['contact_name'] ?? null, 160) ?? $target['contact_name'],
            ], $actor);
            $fresh = Prospecting::target($agentId, $id);
            @set_time_limit(120);
            if ($fresh !== null && OutreachWriter::draft($agent, $fresh, $actor) !== null) {
                admin_flash('Address added and an email drafted. Read it before approving.');
            } else {
                admin_flash('Address added. The email will be drafted on the next run.');
            }
            break;

        case 'continue':
            if (in_array((string)$target['state'], ['duplicate_review', 'research_failed'], true)) {
                db()->update('prospect_targets', ['research_id' => null], 'id = ?', [$id]);
                Prospecting::setState($target, 'discovered', 'team', 'Sent on for research by ' . $actor . '.', [], $actor);
                admin_flash('It will be researched in turn.');
            } elseif ((string)$target['state'] === 'low_fit') {
                Prospecting::setState($target, !empty($target['contact_email']) ? 'qualified' : 'no_email', 'team',
                    'Taken forward despite the score, by ' . $actor . '.', [], $actor);
                admin_flash(!empty($target['contact_email']) ? 'An email will be drafted on the next run.' : 'Add an email address to continue.');
            }
            break;

        case 'outcome':
            $outcome = (string)($_POST['outcome'] ?? '');
            if (in_array($outcome, Prospecting::OUTCOME_STATES, true)) {
                Prospecting::setState($target, $outcome, 'team', trim((string)($_POST['note'] ?? '')) ?: null, [], $actor);
                admin_flash('Recorded: ' . Prospecting::STATES[$outcome] . '.');
            }
            break;
    }
    admin_redirect($back);
}

$target   = Prospecting::target($agentId, $id);
$email    = ProspectMailer::forTarget($id);
$research = !empty($target['research_id']) ? CompanyResearch::find($agentId, (int)$target['research_id']) : null;
$f        = $research !== null ? CompanyResearch::findingsOf($research) : [];
$sources  = $research !== null ? (json_decode((string)$research['sources'], true) ?: []) : [];
$sourceUrl = static function (?string $sid) use ($sources): ?string {
    foreach ($sources as $s) {
        if (($s['id'] ?? '') === $sid) { return (string)$s['url']; }
    }
    return null;
};

admin_layout_start((string)$target['company_name'], [
    'The campaign' => url('admin/prospects.php?campaign=' . (int)$target['campaign_id']),
]);

$stateLabel = Prospecting::STATES[(string)$target['state']] ?? (string)$target['state'];
echo '<div class="alert' . (in_array((string)$target['state'], Prospecting::FINAL_STATES, true) ? ' alert-warn' : '') . '"><p><strong>'
   . e($stateLabel) . '</strong> · set by ' . e(PersonView::WHO_LABELS[(string)$target['state_by']] ?? '')
   . ((string)($target['state_note'] ?? '') !== '' ? ' — ' . e((string)$target['state_note']) : '') . '</p></div>';

echo '<div class="grid grid-2">';

// ---- The company ----
echo '<div class="card"><h2>The company</h2><table class="table"><tbody>'
   . '<tr><td style="width:130px;color:var(--ink-soft)">Website</td><td>' . e((string)$target['company_domain']) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Country</td><td>' . e((string)($target['country'] ?? '')) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Category</td><td>' . e((string)($target['category'] ?? '')) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Campaign</td><td>' . e((string)$target['campaign_name']) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Found</td><td>'
   . ((string)($target['found_url'] ?? '') !== '' ? '<a href="' . e((string)$target['found_url']) . '" target="_blank" rel="noopener noreferrer">'
        . e(mb_strimwidth((string)$target['found_url'], 0, 70, '…', 'UTF-8')) . '</a>' : '—')
   . ((string)($target['found_reason'] ?? '') !== '' ? '<div class="small muted">The AI\'s reading: ' . e((string)$target['found_reason']) . '</div>' : '')
   . '</td></tr>'
   . '</tbody></table>'
   . '<p class="small"><a href="' . e(url('admin/companies.php?view=' . (int)$target['company_id'])) . '">The company record</a>'
   . ($research !== null ? ' · <a href="' . e(url('admin/research.php?view=' . (int)$research['id'])) . '">The full research and every source</a>' : '')
   . '</p></div>';

// ---- Research ----
echo '<div class="card"><h2>Why it may fit</h2>';
if ($research === null) {
    echo '<div class="empty">Not researched yet.</div>';
} elseif ((string)$research['status'] !== 'done') {
    echo '<p>' . e(ucfirst((string)$research['status'])) . ((string)($research['error'] ?? '') !== '' ? ': ' . e((string)$research['error']) : '') . '</p>';
} else {
    echo '<p><strong>' . (int)$research['score'] . '/100</strong> · ' . e(CompanyResearch::priorityLabel((string)$research['priority']))
       . ' · ' . e(match ((string)($f['match_level'] ?? '')) {
            'direct' => 'direct match', 'adjacent' => 'related field', 'poor' => 'poor match', default => 'match not judged' }) . '</p>';
    if (!empty($f['facts'])) {
        echo '<p class="small" style="margin:0 0 4px;color:var(--ink-soft)">Seen on their pages</p><ul class="small" style="margin:0 0 10px;padding-left:18px">';
        foreach (array_slice((array)$f['facts'], 0, 6) as $fact) {
            $u = $sourceUrl((string)($fact['source'] ?? ''));
            echo '<li>' . e((string)($fact['claim'] ?? '')) . ($u !== null ? ' <a href="' . e($u) . '" target="_blank" rel="noopener noreferrer">source</a>' : '') . '</li>';
        }
        echo '</ul>';
    }
    if (!empty($f['why_exhibit'])) {
        echo '<p class="small"><em>The AI\'s view, not checked fact:</em> ' . e((string)$f['why_exhibit']) . '</p>';
    }
    if (!empty($f['best_offer']['label'])) {
        echo '<p class="small">Suggested: <strong>' . e((string)$f['best_offer']['label']) . '</strong></p>';
    }
}
echo '<p class="small" style="margin-top:10px">Contact: ' . ((string)($target['contact_email'] ?? '') !== ''
        ? '<strong>' . e((string)$target['contact_email']) . '</strong>' . ((string)($target['contact_source'] ?? '') !== ''
            ? ' <span class="muted">— ' . (str_starts_with((string)$target['contact_source'], 'http')
                ? 'found on <a href="' . e((string)$target['contact_source']) . '" target="_blank" rel="noopener noreferrer">this page</a>'
                : e((string)$target['contact_source'])) . '</span>' : '')
        : '<span class="muted">no email address found</span>')
   . ((string)($target['contact_name'] ?? '') !== '' || (string)($target['contact_title'] ?? '') !== ''
        ? '<br>Person to approach: ' . e(trim((string)($target['contact_name'] ?? '') . ', ' . (string)($target['contact_title'] ?? ''), ', ')) : '')
   . '</p></div>';

echo '</div>';

// ---- What the team can do before there is an email ----
$state = (string)$target['state'];
if (in_array($state, ['no_email', 'low_fit'], true) || ($state === 'qualified' && $email === null)) {
    echo '<form method="post" class="card">' . $guard . '<input type="hidden" name="action" value="add_email">'
       . '<h2>' . ($state === 'no_email' ? 'Add an email address to continue' : 'Use a different email address') . '</h2>'
       . '<p class="lede small">Only an address you know belongs to this company — never a guessed one such as '
       . 'info@ plus their website. It is recorded as added by you.</p><div class="field-row">'
       . f_text('contact_email', 'Email address', (string)($target['contact_email'] ?? ''), ['type' => 'email'])
       . f_text('contact_name', 'Person (optional)', (string)($target['contact_name'] ?? ''))
       . '</div><button class="btn btn-small" type="submit">Save and write the email</button></form>';
}
if (in_array($state, ['duplicate_review', 'research_failed', 'low_fit'], true)) {
    echo '<form method="post" class="card">' . $guard . '<input type="hidden" name="action" value="continue">'
       . '<p style="margin:0 0 10px">' . match ($state) {
            'duplicate_review' => 'If this is not the same company as the one named above, send it on for research. '
                . 'If it is, merge the two under Companies and reject this one.',
            'research_failed'  => 'Try the research again.',
            default            => 'The score is low. You can still take it forward if you know better.',
         } . '</p><button class="btn btn-small btn-quiet" type="submit">'
       . e(match ($state) { 'duplicate_review' => 'Not a duplicate — continue', 'research_failed' => 'Research again', default => 'Take it forward' })
       . '</button></form>';
}

// ---- The email ----
if ($email !== null) {
    $unknown  = (int)($email['delivery_unknown'] ?? 0) === 1;
    $editable = in_array((string)$email['status'], ['draft', 'approved', 'failed'], true) && !$unknown;
    echo '<div class="card"><div class="card-head"><h2>The email</h2><span class="pill '
       . match ((string)$email['status']) { 'sent' => 'pill-ok', 'failed', 'cancelled' => 'pill-bad', 'approved' => 'pill-info', default => 'pill-warn' }
       . '">' . e(match ((string)$email['status']) {
            'draft' => 'Waiting for approval', 'approved' => 'Approved — waiting to send', 'sending' => 'Sending',
            'sent' => 'Sent', 'failed' => 'Could not be sent', 'cancelled' => 'Not sent', default => (string)$email['status'] })
       . '</span></div>';

    if ((int)$email['used_fallback'] === 1) {
        echo '<div class="alert alert-warn"><p>The AI\'s own drafts did not pass the checks, so the plain standard email is shown. '
           . 'Reason: ' . e((string)($email['check_note'] ?? '')) . '</p></div>';
    }
    if ((string)($email['last_error'] ?? '') !== '') {
        echo '<div class="alert alert-error"><p>' . e((string)$email['last_error']) . '</p></div>';
    }

    echo '<p class="small muted">From ' . e(MailProfile::sender($agent)) . ' · to <strong>' . e((string)$email['to_email']) . '</strong>'
       . ((string)($email['approved_by'] ?? '') !== '' ? ' · approved by ' . e((string)$email['approved_by']) : '')
       . ((string)($email['edited_by'] ?? '') !== '' ? ' · edited by ' . e((string)$email['edited_by']) : '')
       . ($email['sent_at'] !== null && (string)$email['status'] === 'sent' ? ' · sent ' . e(admin_time((string)$email['sent_at'])) : '') . '</p>';

    if ($editable) {
        echo '<form method="post">' . $guard . '<input type="hidden" name="email_id" value="' . (int)$email['id'] . '">'
           . f_text('subject', 'Subject', (string)$email['subject'])
           . f_area('body', 'Message', (string)$email['body'], ['rows' => 14])
           . '<div class="btn-row">'
           . ((string)$email['status'] === 'draft'
                ? '<button class="btn" type="submit" name="action" value="save_approve">Save and approve</button>' : '')
           . '<button class="btn btn-quiet" type="submit" name="action" value="save">Save changes</button>'
           . '</div></form>';
    } else {
        echo '<p><strong>' . e((string)$email['subject']) . '</strong></p>'
           . '<div class="turn-body" style="max-width:none">' . e((string)$email['body']) . '</div>';
    }

    echo '<p class="small" style="margin:14px 0 4px;color:var(--ink-soft)">Added to the foot of every email, by the platform:</p>'
       . '<div class="turn-body" style="max-width:none;font-size:13px;color:var(--ink-soft)">'
       . e(ltrim(OutreachWriter::footer($agent, $target, '[unsubscribe link]'))) . '</div>';

    if ($editable) {
        echo '<div class="btn-row" style="margin-top:14px">'
           . ((string)$email['status'] === 'draft'
                ? '<form method="post" style="margin:0">' . $guard . '<input type="hidden" name="action" value="approve">'
                  . '<input type="hidden" name="email_id" value="' . (int)$email['id'] . '">'
                  . '<button class="btn btn-small" type="submit">Approve as it is</button></form>' : '')
           . (!in_array($state, Prospecting::FINAL_STATES, true)
                ? '<form method="post" style="margin:0" data-confirm="Throw this draft away and have the AI write a new one?">' . $guard
                  . '<input type="hidden" name="action" value="rewrite"><button class="btn btn-small btn-quiet" type="submit">Rewrite with the AI</button></form>' : '')
           . '</div>';
    }
    echo '</div>';
}

// ---- Decisions ----
if (!in_array($state, ['sent', 'responded', 'interested', 'follow_up', 'not_interested', 'converted'], true)
    && !in_array($state, Prospecting::FINAL_STATES, true)) {
    echo '<div class="card"><h2>Not for this company</h2><div class="btn-row">'
       . '<form method="post" style="margin:0" data-confirm="Reject this company for this campaign? Nothing will be sent.">' . $guard
       . '<input type="hidden" name="action" value="reject"><button class="btn btn-small btn-quiet" type="submit">Reject for this campaign</button></form>'
       . '<form method="post" style="margin:0" data-confirm="Never email this company again, from any campaign?">' . $guard
       . '<input type="hidden" name="action" value="do_not_contact"><button class="btn btn-small btn-quiet" type="submit">Do not contact, ever</button></form>'
       . '</div></div>';
}

if (in_array($state, ['sent', 'responded', 'interested', 'follow_up', 'not_interested', 'converted'], true)) {
    $opts = [];
    foreach (Prospecting::OUTCOME_STATES as $o) {
        $opts[$o] = Prospecting::STATES[$o];
    }
    echo '<form method="post" class="card">' . $guard . '<input type="hidden" name="action" value="outcome">'
       . '<h2>What happened next</h2><p class="lede small">Replies arrive in the '
       . e(MailProfile::forAgent($agent)['reply_to'] ?? MailProfile::forAgent($agent)['from_email'])
       . ' mailbox, not here. Record what they said so the campaign\'s numbers stay true.</p><div class="field-row">'
       . f_select('outcome', 'They…', $opts, in_array($state, Prospecting::OUTCOME_STATES, true) ? $state : 'responded')
       . f_text('note', 'Note (optional)', '')
       . '</div><button class="btn btn-small" type="submit">Record</button></form>';
}

// ---- History ----
$history = db()->all(
    "SELECT * FROM audit_log WHERE (entity_type = 'prospect_target' AND entity_id = ?)
        OR (entity_type = 'outreach_email' AND entity_id = ?) ORDER BY id DESC LIMIT 60",
    [(string)$id, (string)($email['id'] ?? 0)]
);
echo '<div class="card"><h2>History</h2>';
if (!$history) {
    echo '<div class="empty">Nothing recorded yet.</div>';
} else {
    echo '<table class="table"><tbody>';
    foreach ($history as $h) {
        echo '<tr><td class="small muted" style="width:150px">' . e(admin_time((string)$h['created_at'])) . '</td>'
           . '<td>' . e((string)($h['detail'] ?? '')) . '</td>'
           . '<td class="small muted">' . e((string)$h['actor']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

admin_layout_end();
