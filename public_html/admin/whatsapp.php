<?php
declare(strict_types=1);

/**
 * WhatsApp: what is about to be sent, what was sent, and what came back.
 *
 * The screen is built around one idea — that a message which has been
 * written but not yet sent is still the operator's to change or stop.
 * Everything visible here is real text about to go to a real phone, so the
 * queue is shown before the history rather than after it.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_role('admin');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];

Audit::setActor($actor);

// Every form on this page carries the exhibition it was drawn for. The
// exhibition being worked on is remembered per sign-in, so a tab left open
// on Build Asia while another tab switched to Health Asia would otherwise
// approve or block against the wrong one.
$guard = Security::csrfField() . '<input type="hidden" name="agent_id" value="' . $agentId . '">';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ((int)($_POST['agent_id'] ?? 0) !== $agentId) {
        admin_flash('That page was showing a different exhibition, so nothing was done. It has been '
            . 'reloaded for ' . (string)$agent['name'] . ' — check it and try again.', 'error');
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'write_openings') {
        $done = WhatsAppEngine::queueOpenings($agent, 100);
        if ($done['reason'] !== null) {
            admin_flash($done['reason'], 'error');
        } elseif ($done['queued'] === 0) {
            admin_flash('There is nobody new to write to. Registrations become eligible '
                . (int)$agent['whatsapp_first_delay_minutes'] . ' minutes after they register.');
        } else {
            admin_flash($done['queued'] . ' message(s) written. '
                . ((int)($agent['whatsapp_approve_first'] ?? 1) === 1
                    ? 'They are waiting for your approval below.'
                    : 'They will go out at the next run — read them below first if you want to.'));
        }
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'send_now') {
        // Replies, plus at most two first messages: each first message is
        // followed by a pause, and the page must answer before it times out.
        // The scheduled task sends the rest.
        $done = WhatsAppEngine::sendDue($agent, 25, 2);
        $said = [];
        if ($done['sent'] > 0)   { $said[] = $done['sent'] . ' sent'; }
        if ($done['failed'] > 0) { $said[] = $done['failed'] . ' could not be sent'; }
        if ($done['held'] > 0)   { $said[] = $done['held'] . ' stopped'; }
        admin_flash(
            ($said ? ucfirst(implode(', ', $said)) . '.' : 'Nothing was due to go out.')
            . ($done['reason'] !== null ? ' ' . $done['reason'] : ''),
            $done['failed'] > 0 ? 'warn' : 'ok'
        );
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'staff_reply') {
        $why = WhatsAppEngine::staffReply($agent, (int)($_POST['conversation_id'] ?? 0),
            (string)($_POST['reply_body'] ?? ''), $actor);
        admin_flash($why === null ? 'Reply sent.' : $why, $why === null ? 'ok' : 'error');
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'hand_back') {
        $ok = WhatsAppEngine::handBack($agentId, (int)($_POST['conversation_id'] ?? 0), $actor);
        admin_flash($ok
            ? 'Handed back. The assistant will answer their next message.'
            : 'That conversation was not with a person.', $ok ? 'ok' : 'error');
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'approve') {
        $ok = WhatsAppOutbox::approve($agentId, $id, $actor);
        admin_flash($ok
            ? 'Approved. It will go out at the next run, within the sending hours and the daily limit.'
            : 'That message is no longer waiting for approval.', $ok ? 'ok' : 'error');
        admin_redirect('admin/whatsapp.php?status=held');
    }

    if ($action === 'approve_all') {
        $count = WhatsAppOutbox::approveAll($agentId, $actor);
        admin_flash($count > 0
            ? $count . ' message(s) approved. They will go out over the next runs, within the sending hours '
              . 'and the daily limit.'
            : 'There was nothing waiting for approval.');
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'rewrite') {
        $ok = WhatsAppOutbox::rewrite($agentId, $id, (string)($_POST['body'] ?? ''), $actor);
        admin_flash($ok
            ? 'Message changed. That is what will be sent.'
            : 'It could not be changed — it may already have gone out.', $ok ? 'ok' : 'error');
        admin_redirect('admin/whatsapp.php?view=' . $id);
    }

    if ($action === 'cancel') {
        if (WhatsAppOutbox::cancel($agentId, $id, 'Stopped by ' . $actor . '.')) {
            Audit::log('whatsapp_message_cancelled', $agentId, 'whatsapp_outbox', $id, 'Stopped before sending');
            admin_flash('Stopped. It will not be sent.');
        } else {
            admin_flash('It could not be stopped — it may already have gone out.', 'error');
        }
        admin_redirect('admin/whatsapp.php');
    }

    if ($action === 'retry') {
        $was  = WhatsAppOutbox::find($agentId, $id);
        $why  = WhatsAppOutbox::retry($agentId, $id);
        if ($why === null) {
            Audit::log('whatsapp_message_retried', $agentId, 'whatsapp_outbox', $id, 'Brought back');
            admin_flash($was !== null && (string)$was['status'] === 'cancelled'
                ? 'Brought back. It is waiting for your approval again.'
                : 'Put back in the queue. It will be tried at the next run.');
        } else {
            admin_flash($why, 'error');
        }
        admin_redirect('admin/whatsapp.php?view=' . $id);
    }

    if ($action === 'block') {
        $phone = (string)($_POST['phone'] ?? '');
        Suppression::add($agentId, 'phone', $phone, 'Added by ' . $actor, 'manual', $actor);
        $stopped = WhatsAppOutbox::cancelPending($agentId, $phone, 'Number added to do not contact.');
        admin_flash('That number will not be contacted again'
            . ($stopped > 0 ? ', and ' . $stopped . ' waiting message(s) were stopped.' : '.'));
        admin_redirect('admin/whatsapp.php');
    }
}

$problem = MessagingProviderFactory::problem($agent);
$counts  = WhatsAppOutbox::counts($agentId);
// Open on whatever needs a person first.
$status  = (string)($_GET['status'] ?? (($counts['held'] ?? 0) > 0 ? 'held' : 'scheduled'));
$viewId  = (int)($_GET['view'] ?? 0);

$remaining = WhatsAppEngine::remainingToday($agent);
$inHours   = WhatsAppEngine::withinSendingHours($agent);

admin_layout_start('WhatsApp');

// ---------------------------------------------------------------------
// Whether anything can go out at all
// ---------------------------------------------------------------------
echo '<div class="wa-top">';
if ($problem !== null) {
    echo '<div class="card" style="border-left:3px solid var(--signal)">'
       . '<h2>Not sending</h2><p>' . e($problem) . '</p>'
       . '<div class="btn-row">'
       . '<a class="btn btn-small" href="' . e(url('admin/agents.php')) . '">This exhibition\'s settings</a>'
       . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/settings.php')) . '">Messaging service</a>'
       . '</div></div>';
} else {
    $sentToday = WhatsAppEngine::outreachSentToday($agent);
    echo '<div class="card"><div class="card-head"><h2 style="margin:0">Today</h2>'
       . ($inHours ? '<span class="pill pill-ok">Inside sending hours</span>'
                   : '<span class="pill pill-warn">Outside sending hours</span>')
       . '</div>'
       . '<div class="mini-stats">'
       . '<div><b>' . $sentToday . '</b><span>first messages and follow-ups sent</span></div>'
       . '<div><b>' . $remaining . '</b><span>left today, of ' . (int)$agent['whatsapp_daily_cap'] . '</span></div>'
       . '<div><b>' . e(substr((string)$agent['whatsapp_send_from'], 0, 5)) . '–'
       . e(substr((string)$agent['whatsapp_send_to'], 0, 5)) . '</b><span>sending hours, '
       . e((string)($agent['notify_timezone'] ?: 'Asia/Karachi')) . '</span></div>'
       . '</div>'
       . '<p class="hint" style="margin:0 0 12px">Sending from <strong>' . e((string)$agent['whatsapp_number'])
       . '</strong>. Replies are always answered and never count against the limit'
       . ($inHours ? '.' : '; outside sending hours only replies go out.') . '</p>'
       . '<div class="btn-row">'
       . '<form method="post" style="margin:0">' . $guard
       . '<input type="hidden" name="action" value="write_openings">'
       . '<button class="btn btn-small btn-quiet" type="submit">Write to anyone new</button></form>'
       . '<form method="post" style="margin:0" data-confirm="This sends the messages waiting below to real '
       . 'phone numbers. Continue?">' . $guard
       . '<input type="hidden" name="action" value="send_now">'
       . '<button class="btn btn-small" type="submit">Send what is due</button></form>'
       . '</div>'
       . '<p class="hint">Both happen on their own every few minutes. The buttons are for when you would rather '
       . 'not wait.</p>'
       . '</div>';
}

// ---------------------------------------------------------------------
// Emergency stop
// ---------------------------------------------------------------------
if (!OutboundSwitch::isStopped()) {
    echo '<div class="card stop-card"><h2>Emergency stop</h2>'
       . '<p class="hint" style="margin:0 0 12px">Stops every WhatsApp message and prospect email, on every '
       . 'exhibition, at once. Nothing waiting is lost; resume from the red banner at the top.</p>'
       . '<form method="post" action="' . e(url('admin/outbound.php')) . '" style="margin:0" '
       . 'data-confirm="Stop ALL outgoing messages, for every exhibition, until someone resumes them?">'
       . Security::csrfField()
       . '<input type="hidden" name="action" value="stop">'
       . '<input type="hidden" name="return" value="admin/whatsapp.php">'
       . f_text('note', 'Why (optional, shown to the team)', '', ['max' => 200])
       . '<button class="btn btn-danger" type="submit" style="width:100%">Stop all outgoing messages'
       . '</button></form></div>';
}
echo '</div>';

// ---------------------------------------------------------------------
// Conversations a person has taken over
// ---------------------------------------------------------------------
$withPerson = WhatsAppEngine::withPerson($agentId);

if ($withPerson) {
    echo '<div class="card" style="border-left:3px solid var(--signal)">'
       . '<h2>With a person (' . count($withPerson) . ')</h2>'
       . '<p class="lede">The assistant has stepped aside in these conversations and will not reply until you '
       . 'hand them back. Reply here, or on the WhatsApp phone itself — both reach the same person. '
       . 'Anything they send meanwhile appears in the conversation.</p>';

    foreach ($withPerson as $wp) {
        $who = trim((string)($wp['registration']['name'] ?? $wp['lead']['name'] ?? ''));
        $org = trim((string)($wp['registration']['company_name'] ?? $wp['lead']['company'] ?? ''));
        $role = trim((string)($wp['registration']['designation'] ?? ''));

        echo '<div style="border-top:1px solid var(--line);padding:12px 0">'
           . '<p style="margin:0 0 4px"><strong>' . e($who !== '' ? $who : 'Name not known') . '</strong>'
           . ($role !== '' ? ', ' . e($role) : '') . ($org !== '' ? ' — ' . e($org) : '')
           . ' · <span class="mono">' . e((string)($wp['phone'] ?? 'number unknown')) . '</span></p>'
           . '<p class="hint" style="margin:0 0 6px">' . e((string)$wp['automation_paused_reason'])
           . ' · taken over ' . e(admin_time((string)$wp['automation_paused_at'])) . '</p>'
           . ($wp['last_message'] !== ''
                ? '<p style="margin:0 0 8px">Last from them: “' . e(mb_strimwidth((string)$wp['last_message'], 0, 220, '…', 'UTF-8')) . '”</p>'
                : '')
           . '<form method="post" style="margin:0 0 8px">' . $guard
           . '<input type="hidden" name="action" value="staff_reply">'
           . '<input type="hidden" name="conversation_id" value="' . (int)$wp['id'] . '">'
           . f_area('reply_body', 'Reply as the team', '', ['rows' => 3,
                'hint' => 'Sent from the exhibition\'s WhatsApp number straight away. Written by you, so it is '
                        . 'not checked by the assistant — take care with prices and dates.'])
           . '<button class="btn btn-small" type="submit">Send reply</button></form>'
           . '<div class="btn-row">'
           . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/conversation.php?id=' . (int)$wp['id']))
           . '">The whole conversation</a>'
           . '<a class="btn btn-small btn-quiet" href="' . e(PersonView::url('conversation', (int)$wp['id']))
           . '">Everything about them</a>'
           . '<form method="post" style="margin:0" data-confirm="Let the assistant answer this person again?">'
           . $guard
           . '<input type="hidden" name="action" value="hand_back">'
           . '<input type="hidden" name="conversation_id" value="' . (int)$wp['id'] . '">'
           . '<button class="btn btn-small btn-quiet" type="submit">Hand back to the assistant</button></form>'
           . '</div></div>';
    }

    echo '</div>';
}

// ---------------------------------------------------------------------
// One message in full
// ---------------------------------------------------------------------
if ($viewId > 0) {
    $one = WhatsAppOutbox::find($agentId, $viewId);

    if ($one === null) {
        echo '<div class="card"><p>That message is not on this exhibition\'s list.</p></div>';
    } else {
        $held    = (string)$one['status'] === 'held';
        $waiting = $held || (string)$one['status'] === 'scheduled';

        echo '<div class="card"><div class="card-head">'
           . '<h2 style="margin:0">' . e(WhatsAppOutbox::kindLabel((string)$one['kind'])) . ' to '
           . e(Phone::mask((string)$one['phone_e164'])) . '</h2>'
           . status_pill((string)$one['status'])
           . '</div>';

        if (!empty($one['blocked_reason'])) {
            // Worth saying plainly: the assistant wrote something that broke
            // one of its own rules, so a plain version was used instead.
            echo '<p class="hint" style="border-left:3px solid var(--signal);padding-left:10px">'
               . 'The assistant\'s own draft was rejected — ' . e((string)$one['blocked_reason'])
               . ' — so the plain version below was used. It is worth reading before it goes.</p>';
        } elseif ((int)$one['used_fallback'] === 1) {
            echo '<p class="hint">Written without the AI, because it was unavailable at the time.</p>';
        }

        if ($waiting) {
            echo '<form method="post">' . $guard
               . '<input type="hidden" name="action" value="rewrite">'
               . '<input type="hidden" name="id" value="' . (int)$one['id'] . '">'
               . f_area('body', 'The message', (string)$one['body'], [
                    'rows' => 8,
                    'hint' => 'Whatever is here is what gets sent. Keep it short — this is a phone.',
                 ])
               . '<button class="btn btn-small" type="submit">Save the change</button></form>';
        } else {
            echo '<pre style="white-space:pre-wrap">' . e((string)$one['body']) . '</pre>';
        }

        echo '<table class="table"><tbody>'
           . '<tr><th>Number</th><td>' . e((string)$one['phone_e164'])
           . (!empty($one['registration_id']) || !empty($one['conversation_id'])
                ? ' · <a href="' . e(!empty($one['registration_id'])
                    ? PersonView::url('registration', (int)$one['registration_id'])
                    : PersonView::url('conversation', (int)$one['conversation_id'])) . '">Everything about them</a>'
                : '') . '</td></tr>'
           . '<tr><th>Written</th><td>' . e(admin_time((string)$one['created_at'])) . '</td></tr>'
           . '<tr><th>Due</th><td>' . e(admin_time((string)$one['scheduled_for'])) . '</td></tr>'
           . ($one['sent_at'] !== null
                ? '<tr><th>Sent</th><td>' . e(admin_time((string)$one['sent_at'])) . '</td></tr>' : '')
           . '<tr><th>Attempts</th><td>' . (int)$one['attempts'] . '</td></tr>'
           . ($one['last_error'] !== null
                ? '<tr><th>Last problem</th><td>' . e((string)$one['last_error']) . '</td></tr>' : '')
           . '</tbody></table>';

        echo '<div class="btn-row">';
        if ($held) {
            echo '<form method="post" style="margin:0">' . $guard
               . '<input type="hidden" name="action" value="approve">'
               . '<input type="hidden" name="id" value="' . (int)$one['id'] . '">'
               . '<button class="btn btn-small" type="submit">Approve and send</button></form>';
        }
        if ($waiting) {
            echo '<form method="post" style="margin:0" data-confirm="Stop this message being sent?">'
               . $guard
               . '<input type="hidden" name="action" value="cancel">'
               . '<input type="hidden" name="id" value="' . (int)$one['id'] . '">'
               . '<button class="btn btn-small btn-quiet" type="submit">Do not send it</button></form>';
        }
        if (in_array((string)$one['status'], ['failed', 'cancelled'], true)) {
            echo '<form method="post" style="margin:0">' . $guard
               . '<input type="hidden" name="action" value="retry">'
               . '<input type="hidden" name="id" value="' . (int)$one['id'] . '">'
               . '<button class="btn btn-small" type="submit">'
               . ((string)$one['status'] === 'cancelled' ? 'Bring it back for approval' : 'Try again')
               . '</button></form>';
        }
        echo '<form method="post" style="margin:0" data-confirm="This number will never be contacted again '
           . 'for this exhibition. Continue?">' . $guard
           . '<input type="hidden" name="action" value="block">'
           . '<input type="hidden" name="phone" value="' . e((string)$one['phone_e164']) . '">'
           . '<button class="btn btn-small btn-quiet" type="submit">Never contact this number</button></form>'
           . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/whatsapp.php')) . '">Back to the list</a>'
           . '</div></div>';
    }
}

// ---------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------
$tabs = [
    'held'      => 'Waiting for your approval',
    'scheduled' => 'Waiting to go',
    'sent'      => 'Sent',
    'failed'    => 'Could not be sent',
    'cancelled' => 'Stopped',
    ''          => 'Everything',
];

echo '<div class="card"><div class="card-head"><h2 style="margin:0">Messages</h2></div><div class="btn-row">';
foreach ($tabs as $key => $label) {
    $on = $status === $key;
    echo '<a class="btn btn-small' . ($on ? '' : ' btn-quiet') . '" href="'
       . e(url('admin/whatsapp.php') . ($key === '' ? '' : '?status=' . $key)) . '">'
       . e($label)
       . ($key !== '' && ($counts[$key] ?? 0) > 0 ? ' (' . (int)$counts[$key] . ')' : '')
       . '</a>';
}
echo '</div>';

$rows = WhatsAppOutbox::recent($agentId, $status, 60);

if ($status === 'held' && $rows) {
    echo '<p class="lede" style="margin-top:12px">These have been written but will not be sent until you '
       . 'approve them. Open one to read it in full, change it, or stop it. Approving does not skip the '
       . 'sending hours or the daily limit.</p>'
       . '<form method="post" style="margin:0 0 12px" data-confirm="Approve all '
       . (int)($counts['held'] ?? 0) . ' waiting message(s)? They will be sent to real phone numbers.">'
       . $guard
       . '<input type="hidden" name="action" value="approve_all">'
       . '<button class="btn btn-small" type="submit">Approve all ' . (int)($counts['held'] ?? 0)
       . '</button></form>';
}

if (!$rows) {
    echo '<p class="hint">Nothing here yet.</p>';
} else {
    echo '<table class="table"><thead><tr>'
       . '<th>Who</th><th>Kind</th><th>Message</th><th>When</th><th>State</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $when = (string)$row['status'] === 'sent' ? (string)$row['sent_at'] : (string)$row['scheduled_for'];

        echo '<tr>'
           . '<td>' . (!empty($row['registration_id']) || !empty($row['conversation_id'])
                ? '<a href="' . e(!empty($row['registration_id'])
                    ? PersonView::url('registration', (int)$row['registration_id'])
                    : PersonView::url('conversation', (int)$row['conversation_id'])) . '">'
                  . e(Phone::mask((string)$row['phone_e164'])) . '</a>'
                : e(Phone::mask((string)$row['phone_e164']))) . '</td>'
           . '<td>' . e(WhatsAppOutbox::kindLabel((string)$row['kind'])) . '</td>'
           . '<td>' . e(mb_strimwidth((string)$row['body'], 0, 90, '…', 'UTF-8'))
           . (!empty($row['blocked_reason']) || (int)$row['used_fallback'] === 1
                ? ' <span class="pill">plain version</span>' : '')
           . '</td>'
           . '<td class="nowrap">' . e(admin_time($when)) . '</td>'
           . '<td>' . status_pill((string)$row['status']) . '</td>'
           . '<td><a href="' . e(url('admin/whatsapp.php?view=' . (int)$row['id'])) . '">Open</a></td>'
           . '</tr>';
    }

    echo '</tbody></table>';
}
echo '</div>';

// ---------------------------------------------------------------------
// What came back
// ---------------------------------------------------------------------
$replies = db()->all(
    'SELECT * FROM whatsapp_inbox WHERE agent_id = ? ORDER BY id DESC LIMIT 30',
    [$agentId]
);

echo '<div class="card"><h2>Replies received</h2>';

if (!Settings::hasValue('whatsapp.webhook_secret')) {
    echo '<p class="hint" style="border-left:3px solid var(--signal);padding-left:10px">'
       . 'No webhook address has been made yet, so replies cannot reach the assistant. Messages will go out '
       . 'and answers will go nowhere. Set it up under '
       . '<a href="' . e(url('admin/settings.php')) . '">Settings</a>.</p>';
}

if (!$replies) {
    echo '<p class="hint">Nothing has come back yet.</p>';
} else {
    echo '<table class="table"><thead><tr>'
       . '<th>From</th><th>What they said</th><th>When</th><th>Answered</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($replies as $reply) {
        echo '<tr>'
           . '<td>' . e(Phone::mask((string)$reply['phone_e164'])) . '</td>'
           . '<td>' . e(mb_strimwidth((string)$reply['body'], 0, 90, '…', 'UTF-8')) . '</td>'
           . '<td class="nowrap">' . e(admin_time((string)$reply['received_at'])) . '</td>'
           . '<td>' . ((int)$reply['handled'] === 1
                ? e((string)($reply['note'] ?? 'Yes'))
                : '<span class="pill">no</span>') . '</td>'
           . '<td>' . (!empty($reply['conversation_id'])
                ? '<a href="' . e(url('admin/conversation.php?id=' . (int)$reply['conversation_id']))
                  . '">The conversation</a>'
                : '') . '</td>'
           . '</tr>';
    }

    echo '</tbody></table>';
}

echo '<p class="hint">A reply is answered by the same assistant that answers the website, using the same '
   . 'prices and the same rules. Anyone who asks to stop is taken off the list straight away and never '
   . 'answered.</p></div>';

admin_layout_end();
