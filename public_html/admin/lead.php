<?php
declare(strict_types=1);

/**
 * One lead: everything known about the person, the conversations they had,
 * status changes and duplicate handling.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('view');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$leadId = (int)($_GET['id'] ?? 0);
$lead   = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [$leadId, $agentId]);

if (!$lead) {
    admin_layout_start('Lead not found');
    echo '<div class="alert alert-error"><p>That lead does not exist for this agent.</p></div>';
    admin_layout_end();
    exit;
}

// ---- Actions -------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'status') {
        $new = (string)($_POST['status'] ?? '');
        if (LeadManager::setStatusManually($agent, $leadId, $new, (string)$user['username'])) {
            admin_flash('Status updated.');
        } else {
            admin_flash('That status is not valid.', 'error');
        }
        admin_redirect('admin/lead.php?id=' . $leadId);
    }

    if ($action === 'note') {
        $note = Validator::paragraph($_POST['internal_note'] ?? null, 4000);
        db()->update('leads', ['internal_note' => $note], 'id = ?', [$leadId]);
        Audit::log('lead_note_updated', $agentId, 'lead', $leadId, 'Internal note edited');
        admin_flash('Note saved.');
        admin_redirect('admin/lead.php?id=' . $leadId);
    }

    if ($action === 'merge') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        if (LeadManager::merge($agent, $leadId, $targetId, (string)$user['username'])) {
            admin_flash('Merged. This record now points at the one you kept.');
            admin_redirect('admin/lead.php?id=' . $targetId);
        }
        admin_flash('Could not merge those records.', 'error');
        admin_redirect('admin/lead.php?id=' . $leadId);
    }

    if ($action === 'dismiss_duplicate') {
        db()->update('leads', ['dedup_flag' => 0, 'dedup_note' => null], 'id = ?', [$leadId]);
        Audit::log('lead_dedup_dismissed', $agentId, 'lead', $leadId, 'Marked as not a duplicate');
        admin_flash('Marked as not a duplicate.');
        admin_redirect('admin/lead.php?id=' . $leadId);
    }
}

$conversations = db()->all('SELECT * FROM conversations WHERE lead_id = ? ORDER BY id ASC', [$leadId]);
$duplicates    = LeadManager::findPossibleDuplicates($agent, $lead);
$history       = Audit::forEntity('lead', $leadId, 40);
$packages      = (array)json_decode((string)$lead['package_interest'], true);

admin_layout_start('Lead ' . (string)$lead['reference'], [
    'Everything about this person' => PersonView::url('lead', $leadId),
    'Back to leads' => url('admin/leads.php'),
]);

if ((int)$lead['merged_into_id'] > 0) {
    echo '<div class="alert alert-warn"><p>This record was merged into another lead. '
       . '<a href="' . e(url('admin/lead.php?id=' . (int)$lead['merged_into_id'])) . '">Open the one that was kept</a>.</p></div>';
}

if ((int)$lead['handoff_required'] === 1) {
    echo '<div class="alert"><p><strong>Passed to sales.</strong> '
       . e((string)($lead['handoff_reason'] ?: 'Requested during the conversation')) . '</p></div>';
}

echo '<div class="grid grid-2">';

// ---- Details ----
echo '<div class="card"><h2>Details</h2><table class="table"><tbody>';
$rows = [
    'Name'            => (string)$lead['name'],
    'Company'         => (string)$lead['company'],
    'Email'           => (string)$lead['email'],
    'Phone'           => (string)$lead['phone'],
    'Website'         => (string)$lead['website'],
    'Industry'        => (string)$lead['industry'],
    'Products'        => (string)$lead['product_category'],
    'Company size'    => (string)$lead['company_size'],
    'Their location'  => (string)$lead['country'],
    'Company based in' => (string)$lead['company_country'],
    'Pricing region'  => ucfirst((string)$lead['pricing_country']),
    'How we know'     => match ((string)$lead['country_source']) {
        'visitor_disclosed' => 'They told us',
        'ip_geo'            => 'From their IP address',
        default             => 'Never established',
    },
    'Requirements'    => (string)$lead['requirements'],
    'First seen'      => admin_time((string)$lead['created_at']),
    'Last updated'    => admin_time((string)$lead['updated_at']),
    'Source page'     => (string)$lead['source_url'],
];
foreach ($rows as $label => $value) {
    if (trim((string)$value) === '') { continue; }
    echo '<tr><td style="width:150px;color:var(--ink-soft)">' . e($label) . '</td><td>' . e((string)$value) . '</td></tr>';
}
echo '</tbody></table></div>';

// ---- Status and note ----
echo '<div>';
$leadQual = json_decode((string)($lead['qualification'] ?? ''), true);
if (is_array($leadQual) && $leadQual) {
    echo qualification_card($leadQual, $lead['qual_pct'] ?? 0, $lead['lead_label'] ?? null, $lead['lead_score'] ?? null);
}
echo '<div class="card"><h2>Status</h2>'
   . '<form method="post">' . Security::csrfField()
   . '<input type="hidden" name="action" value="status">'
   . f_select('status', 'Current status', LeadManager::STATUS_LABELS, (string)$lead['status'],
        ['hint' => 'The first four are set automatically as the conversation progresses. The rest are yours to set.'])
   . '<button class="btn btn-small" type="submit">Save status</button></form></div>';

echo '<div class="card"><h2>Internal note</h2>'
   . '<form method="post">' . Security::csrfField()
   . '<input type="hidden" name="action" value="note">'
   . f_area('internal_note', '', (string)$lead['internal_note'], ['rows' => 5, 'hint' => 'Only your team sees this. Never shown to the visitor.'])
   . '<button class="btn btn-small" type="submit">Save note</button></form></div>';
echo '</div>';

echo '</div>';

// ---- Packages ----
if ($packages) {
    echo '<div class="card"><h2>Packages discussed</h2><table class="table"><tbody>';
    foreach ($packages as $p) {
        echo '<tr><td>' . e((string)($p['label'] ?? '')) . '</td>'
           . '<td class="num">' . e((string)($p['price'] ?? '')) . '</td>'
           . '<td class="num small muted">' . e(admin_time(str_replace(['T', 'Z'], [' ', ''], (string)($p['noted_at'] ?? '')))) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

// ---- Duplicates ----
if ($duplicates) {
    echo '<div class="card"><div class="card-head"><h2>Possible duplicates</h2>'
       . '<form method="post" style="margin:0">' . Security::csrfField()
       . '<input type="hidden" name="action" value="dismiss_duplicate">'
       . '<button class="btn btn-small btn-quiet" type="submit">Not a duplicate</button></form></div>'
       . '<p class="lede">These records share an email address or phone number. Nothing is merged automatically — '
       . 'merging cannot be undone, so it is left to you.</p>'
       . '<table class="table"><thead><tr><th>Reference</th><th>Company</th><th>Matched on</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($duplicates as $d) {
        echo '<tr><td class="mono"><a href="' . e(url('admin/lead.php?id=' . (int)$d['id'])) . '">' . e((string)$d['reference']) . '</a></td>'
           . '<td>' . e((string)($d['company'] ?? '')) . '</td>'
           . '<td>' . e((string)$d['match_on']) . '</td>'
           . '<td>' . e(LeadManager::STATUS_LABELS[(string)$d['status']] ?? (string)$d['status']) . '</td>'
           . '<td><form method="post" style="margin:0" data-confirm="Merge this lead into '
           . e((string)$d['reference']) . '? This cannot be undone.">'
           . Security::csrfField()
           . '<input type="hidden" name="action" value="merge">'
           . '<input type="hidden" name="target_id" value="' . (int)$d['id'] . '">'
           . '<button class="btn btn-small btn-quiet" type="submit">Merge into this</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}

// ---- Conversations ----
echo '<div class="card"><h2>Conversations</h2>';
if (!$conversations) {
    echo '<div class="empty">This lead came from the contact form rather than a conversation.</div>';
} else {
    echo '<table class="table"><thead><tr><th>Started</th><th>Messages</th><th>Stage reached</th><th>Region</th><th></th></tr></thead><tbody>';
    foreach ($conversations as $c) {
        echo '<tr><td>' . e(admin_time((string)$c['created_at'])) . '</td>'
           . '<td class="num">' . (int)$c['message_count'] . '</td>'
           . '<td>' . e(ConversationState::label((string)$c['state'])) . '</td>'
           . '<td>' . e(ucfirst((string)$c['pricing_country'])) . '</td>'
           . '<td><a href="' . e(url('admin/conversation.php?id=' . (int)$c['id'])) . '">Read transcript</a></td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ---- History ----
echo '<div class="card"><h2>History</h2>';
if (!$history) {
    echo '<div class="empty">Nothing recorded yet.</div>';
} else {
    echo '<table class="table"><thead><tr><th>When</th><th>What happened</th><th>Changed from</th><th>To</th><th>By</th></tr></thead><tbody>';
    foreach ($history as $h) {
        echo '<tr><td class="small muted">' . e(admin_time((string)$h['created_at'])) . '</td>'
           . '<td>' . e((string)$h['event'] === 'lead_field_updated' && (string)$h['field'] !== ''
                ? ucfirst(str_replace('_', ' ', (string)$h['field'])) . ' recorded'
                : PersonView::eventLabel((string)$h['event'])
                  . ((string)$h['field'] !== '' ? ' (' . str_replace('_', ' ', (string)$h['field']) . ')' : '')) . '</td>'
           . '<td class="small">' . e(mb_substr((string)$h['old_value'], 0, 60)) . '</td>'
           . '<td class="small">' . e(mb_substr((string)$h['new_value'], 0, 60)) . '</td>'
           . '<td class="small muted">' . e(in_array((string)$h['actor'], ['system', ''], true) ? 'Platform' : (string)$h['actor']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

admin_layout_end();
