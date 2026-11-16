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

    // A quotation made by hand: the price list only, never typed figures,
    // and never for a company in Pakistan (owner, 12 Nov 2026).
    if ($action === 'quotation' || $action === 'quotation-resend') {
        admin_require_access('manage');
        if ($action === 'quotation-resend') {
            $row = db()->one('SELECT * FROM quotations WHERE id = ? AND agent_id = ? AND lead_id = ?',
                [(int)($_POST['quotation_id'] ?? 0), $agentId, $leadId]);
            if ($row !== null && (string)$row['status'] !== 'sent') {
                db()->update('quotations', ['attempts' => 0, 'status' => 'queued'], 'id = ?', [(int)$row['id']]);
                $row['attempts'] = 0;
                Quotation::send($agent, $row)
                    ? admin_flash('Quotation ' . $row['number'] . ' was emailed to ' . $row['to_email'] . '.')
                    : admin_flash('It could not be sent: ' . (string)db()->value('SELECT last_error FROM quotations WHERE id = ?', [(int)$row['id']]), 'error');
            }
            admin_redirect('admin/lead.php?id=' . $leadId . '#quotations');
        }
        $record = null;
        foreach (Quotation::options($agent) as $o) {
            if ($o['_type'] . ':' . $o['id'] === (string)($_POST['option'] ?? '')) {
                $record = $o;
            }
        }
        $sqm   = trim((string)($_POST['sqm'] ?? '')) !== '' ? (float)$_POST['sqm'] : null;
        $email = trim((string)($_POST['email'] ?? ''));
        $problem = Quotation::staffProblem($agent, $lead, $record, $sqm, $email);
        if ($problem !== null) {
            admin_flash($problem, 'error');
        } else {
            try {
                $perSqm = (string)($record['pricing_mode'] ?? 'fixed') === 'per_sqm';
                $made = Quotation::issue($agent, ['email' => $email] + $lead, null, $record, $perSqm ? $sqm : null, 'staff', (string)$user['username']);
                (string)$made['status'] === 'sent'
                    ? admin_flash('Quotation ' . $made['number'] . ' was emailed to ' . $made['to_email'] . '. Your team has a copy.')
                    : admin_flash('Quotation ' . $made['number'] . ' is made, but the email has not gone out yet: '
                        . (string)($made['last_error'] ?? 'it will be tried again in a few minutes') . '.', 'warn');
            } catch (Throwable $ex) {
                app_log('quotation', 'staff quotation failed', ['error' => $ex->getMessage()]);
                admin_flash('The quotation could not be made: ' . $ex->getMessage(), 'error');
            }
        }
        admin_redirect('admin/lead.php?id=' . $leadId . '#quotations');
    }

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

// ---- Quotations (plan part 3) ----
if (Quotation::ready()) {
    $quotes = Quotation::forLead($agentId, $leadId);
    echo '<div class="card" id="quotations"><h2>Quotations</h2>';
    if (!$quotes) {
        echo '<p class="muted">None yet.</p>';
    } else {
        echo '<table class="table"><thead><tr><th>Number</th><th>For</th><th>Total</th><th>Sent to</th><th>Status</th><th>Made</th><th></th></tr></thead><tbody>';
        foreach ($quotes as $q) {
            $state = (string)$q['status'];
            echo '<tr><td><strong>' . e((string)$q['number']) . '</strong></td>'
               . '<td>' . e((string)$q['title']) . ($q['sqm'] ? ', ' . e(rtrim(rtrim(number_format((float)$q['sqm'], 2), '0'), '.')) . ' sqm' : '') . '</td>'
               . '<td>' . e((string)$q['total_text']) . '</td>'
               . '<td class="small">' . e((string)$q['to_email']) . '</td>'
               . '<td><span class="pill ' . ($state === 'sent' ? 'pill-ok' : ($state === 'failed' ? 'pill-bad' : 'pill-info')) . '">'
               . e(Quotation::statusLabel($q)) . '</span>'
               . ($state !== 'sent' && !empty($q['last_error']) ? '<div class="small muted">' . e((string)$q['last_error']) . '</div>' : '') . '</td>'
               . '<td class="small">' . e(admin_time((string)$q['created_at'])) . '<div class="muted">'
               . e((string)$q['source'] === 'staff' ? 'by ' . (string)$q['created_by'] : 'by the chat') . '</div></td>'
               . '<td style="white-space:nowrap">'
               . (Quotation::path($q) !== null ? '<a class="btn btn-small" href="' . e(url('admin/quotation.php?id=' . (int)$q['id'])) . '">Open PDF</a> ' : '')
               . ($state !== 'sent' && admin_can('manage')
                    ? '<form method="post" style="display:inline">' . Security::csrfField()
                      . '<input type="hidden" name="action" value="quotation-resend"><input type="hidden" name="quotation_id" value="' . (int)$q['id'] . '">'
                      . '<button class="btn btn-small" type="submit">Send again</button></form>' : '')
               . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    if (admin_can('manage')) {
        $options = Quotation::options($agent);
        $block   = Quotation::staffProblem($agent, $lead, $options[0] ?? null, 1.0, (string)($lead['email'] ?: 'x@example.com'));
        $hard    = $block !== null && !str_contains($block, 'size') && !str_contains($block, 'Choose one');
        echo '<h3 style="margin-top:18px">Make a quotation</h3>';
        if ($hard) {
            echo '<p class="muted">' . e($block) . '</p>';
        } elseif (!$options) {
            echo '<p class="muted">There is no live international price in the price list to quote from.</p>';
        } else {
            $choices = [];
            foreach ($options as $o) {
                $choices[$o['_type'] . ':' . $o['id']] = $o['_name'] . ((string)($o['pricing_mode'] ?? 'fixed') === 'per_sqm'
                    ? ' (' . PricingEngine::formatPrice((float)$o['rate_per_sqm'], strtoupper((string)$o['currency'])) . ' per sqm)'
                    : ' (' . PricingEngine::formatPrice((float)$o['price'], strtoupper((string)$o['currency'])) . ')');
            }
            echo '<form method="post">' . Security::csrfField() . '<input type="hidden" name="action" value="quotation">'
               . '<div class="grid grid-3">'
               . f_select('option', 'Stand option or sponsorship tier', $choices, null)
               . f_text('sqm', 'Size in sqm', '', ['hint' => 'Only for a stand priced per sqm.'])
               . f_text('email', 'Send to', (string)$lead['email'], ['type' => 'email'])
               . '</div><p class="hint">The figures come from the price list and cannot be typed. The PDF is emailed to this address '
               . 'from the exhibition\'s own mail account, and your team gets the same PDF.</p>'
               . '<button class="btn btn-small" type="submit">Make and email the quotation</button></form>';
        }
    }
    echo '</div>';
}

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
