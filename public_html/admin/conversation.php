<?php
declare(strict_types=1);

/**
 * One conversation, read end to end, with the decisions the system made
 * alongside it (Section 49).
 */

require __DIR__ . '/_admin.php';

admin_require_access('view');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$id = (int)($_GET['id'] ?? 0);
$conversation = db()->one('SELECT * FROM conversations WHERE id = ? AND agent_id = ?', [$id, $agentId]);

if (!$conversation) {
    admin_layout_start('Conversation not found');
    echo '<div class="alert alert-error"><p>That conversation does not exist for this agent.</p></div>';
    admin_layout_end();
    exit;
}

$messages = ConversationStore::transcript($id);
$lead     = $conversation['lead_id']
    ? db()->one('SELECT * FROM leads WHERE id = ?', [(int)$conversation['lead_id']])
    : null;
$signals  = (array)json_decode((string)$conversation['readiness_signals'], true);
$geo      = db()->all('SELECT * FROM geo_lookups WHERE conversation_id = ? ORDER BY id ASC', [$id]);

admin_layout_start('Conversation', [
    'Everything about this person' => PersonView::url('conversation', (int)$conversation['id']),
    'Back' => url('admin/conversations.php'),
]);

echo '<div class="grid grid-2">';

echo '<div class="card"><h2>About this conversation</h2><table class="table"><tbody>'
   . '<tr><td style="width:170px;color:var(--ink-soft)">Started</td><td>' . e(admin_time((string)$conversation['created_at'])) . '</td></tr>'
   . '<tr><td>Last activity</td><td>' . e(admin_time((string)$conversation['last_activity_at'])) . '</td></tr>'
   . '<tr><td>Messages</td><td>' . (int)$conversation['message_count'] . '</td></tr>'
   . '<tr><td>Stage reached</td><td>' . e(ConversationState::label((string)$conversation['state'])) . '</td></tr>'
   . '<tr><td>What they wanted</td><td>' . e(ucfirst((string)$conversation['intent'])) . '</td></tr>'
   . '<tr><td>Pricing region</td><td>' . e(ucfirst((string)$conversation['pricing_country'])) . '</td></tr>'
   . '<tr><td>How we knew</td><td>' . e(match ((string)$conversation['country_source']) {
        'visitor_disclosed' => !empty(ConversationStore::leadState($conversation)['country_from'])
            ? 'From ' . ConversationStore::leadState($conversation)['country_from'] . ' (personal chat link)' : 'They told us',
        'ip_geo'            => 'From their IP address',
        default             => 'Never established',
     }) . '</td></tr>'
   . ($conversation['disclosed_company_country'] ? '<tr><td>Company based in</td><td>' . e((string)$conversation['disclosed_company_country']) . '</td></tr>' : '')
   . (Lang::of(ConversationStore::leadState($conversation)) !== 'en'
        ? '<tr><td>Chat language</td><td>' . e(Lang::name(Lang::of(ConversationStore::leadState($conversation)))) . '</td></tr>' : '')
   . (BlockedCountries::blocksConversation($conversation)
        ? '<tr><td>Blocked country</td><td><span class="pill pill-bad">Blocked</span> <span class="small muted">Only the standard reply is given. No lead, no email to the team.</span></td></tr>' : '')
   . ($conversation['requested_sqm'] ? '<tr><td>Size asked about</td><td>' . e(rtrim(rtrim(number_format((float)$conversation['requested_sqm'], 2), '0'), '.')) . ' sqm</td></tr>' : '')
   . '<tr><td>Page</td><td class="small">' . e((string)$conversation['page_url']) . '</td></tr>'
   // The link that brought them (12 Oct 2026, docs/handover/2026-10-12-social-attribution.md).
   . (!empty($conversation['utm_source']) || !empty($conversation['referrer_host'])
        ? '<tr><td>Came from</td><td class="small">' . e(implode(' · ', array_filter([
            (string)($conversation['utm_source'] ?? ''), (string)($conversation['utm_medium'] ?? ''),
            (string)($conversation['utm_content'] ?? ''),
            !empty($conversation['referrer_host']) ? 'via ' . $conversation['referrer_host'] : '',
          ]))) . '</td></tr>'
        : '')
   . '</tbody></table></div>';

echo '<div>';
if ($lead) {
    echo '<div class="card"><h2>Lead</h2><p><a href="' . e(url('admin/lead.php?id=' . (int)$lead['id'])) . '">'
       . e((string)$lead['reference']) . '</a> — '
       . e(trim((string)$lead['name'] . ' ' . (string)$lead['company']) ?: 'no name given') . '</p>'
       . '<p class="muted small">Status: ' . e(LeadManager::STATUS_LABELS[(string)$lead['status']] ?? '') . '</p></div>';
}

$active = ConversationState::activeSignalLabels($signals);
echo '<div class="card"><h2>Buying signals</h2>';
if (!$active) {
    echo '<p class="muted">None recorded.</p>';
} else {
    foreach ($active as $s) {
        echo '<span class="pill pill-info" style="margin:0 5px 5px 0">' . e($s) . '</span>';
    }
}
echo '</div>';

// The sales path and the qualification record (plan approved 12 Nov 2026).
$convState = ConversationStore::leadState($conversation);
if (!empty($conversation['path_step']) && is_array($convState['qual'] ?? null)) {
    $stepNo = (int)$conversation['path_step'];
    echo '<div class="card"><h2>Sales path</h2><p><span class="pill pill-info">Step ' . $stepNo . ' of 8</span> '
       . e(Qualification::STEPS[$stepNo] ?? '') . '</p></div>';
    echo qualification_card($convState['qual'], $conversation['qual_pct'] ?? 0, $conversation['lead_label'] ?? null,
        $conversation['lead_score'] ?? null);
}

// Whether their company was researched, what was found, or why not
// (fix plan step 3, 11 Nov 2026).
$research = CompanyResearch::statusForConversation($conversation);
$rRow = $research['row'];
echo '<div class="card"><h2>Company research</h2><p><span class="pill '
   . e(match ($research['state']) { 'done' => 'pill-ok', 'failed', 'poor' => 'pill-bad', 'none' => '', default => 'pill-info' })
   . '">' . e($research['label']) . '</span>'
   . ($rRow !== null ? ' <span class="muted small">' . e((string)$rRow['company_name'])
        . ($rRow['company_country'] ? ', ' . e((string)$rRow['company_country']) : '') . '</span>' : '')
   . '</p>';
if ($research['detail'] !== '') {
    echo '<p class="small">' . e($research['detail']) . '</p>';
}
if ($rRow !== null && $research['state'] === 'done') {
    $rf = CompanyResearch::findingsOf($rRow);
    if (!empty($rf['products'])) {
        echo '<p class="small"><strong>Makes:</strong> ' . e(implode(', ', array_slice((array)$rf['products'], 0, 8))) . '</p>';
    }
    echo '<p class="small muted">Match: ' . e(ucfirst((string)($rRow['match_level'] ?? ''))) . ' · Score '
       . (int)($rRow['score'] ?? 0) . '/100'
       . (!empty($rf['best_offer']['label']) ? ' · Best fit: ' . e((string)$rf['best_offer']['label']) : '') . '</p>';
}
if ($rRow !== null) {
    echo '<p class="small"><a href="' . e(url('admin/research.php?view=' . (int)$rRow['id'])) . '">Open the research</a></p>';
}
echo '</div>';

if ($geo) {
    echo '<div class="card"><h2>Country checks</h2><table class="table"><tbody>';
    foreach ($geo as $g) {
        echo '<tr><td class="small">' . e(admin_time((string)$g['created_at'])) . '</td>'
           . '<td>' . e((string)($g['country_name'] ?: 'Not determined')) . '</td>'
           . '<td class="small muted">' . e((string)($g['failure_reason'] ?: $g['provider'])) . '</td></tr>';
    }
    echo '</tbody></table><p class="hint">A check only runs when pricing becomes relevant, and never if the visitor has already said where they are based.</p></div>';
}
echo '</div></div>';

// ---- Transcript ----
echo '<div class="card"><h2>Transcript</h2>';
if (!$messages) {
    echo '<div class="empty">No messages.</div>';
}

foreach ($messages as $m) {
    $role = (string)$m['role'];
    $who  = match ($role) { 'visitor' => 'Visitor', 'agent' => 'Assistant', default => 'System' };
    // A reply typed by a member of the team is not the assistant's.
    if ($role === 'agent' && str_starts_with((string)($m['response_status'] ?? ''), 'staff:')) {
        $who = 'Team (' . substr((string)$m['response_status'], 6) . ')';
    }
    $cls  = 'turn turn-' . $role . ((int)($m['is_fallback'] ?? 0) === 1 ? ' turn-fallback' : '');

    echo '<div class="' . $cls . '"><div class="turn-meta">'
       . '<strong>' . e($who) . '</strong> · ' . e(admin_time((string)$m['created_at'], 'j M, H:i:s'))
       . ((int)($m['is_fallback'] ?? 0) === 1 ? ' · <span style="color:var(--signal)">standard reply</span>' : '')
       . ((string)($m['stage_after'] ?? '') !== '' && (string)$m['stage_after'] !== (string)$m['stage_before']
            ? ' · moved to ' . e(ConversationState::label((string)$m['stage_after'])) : '')
       . ((int)($m['latency_ms'] ?? 0) > 0 ? ' · ' . (int)$m['latency_ms'] . ' ms' : '')
       . '</div><div class="turn-body">' . e((string)$m['content']);

    if (!empty($m['rendered_cards'])) {
        foreach ((array)json_decode((string)$m['rendered_cards'], true) as $card) {
            echo '<div style="margin-top:10px;padding:10px 12px;background:#fff;border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:4px">'
               . '<strong>' . e((string)($card['title'] ?? '')) . '</strong>'
               . '<table class="sums"><tbody>';
            foreach ((array)($card['price_lines'] ?? []) as $pl) {
                echo '<tr><td>' . e((string)$pl['label']) . '</td><td>' . e((string)$pl['value']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    if (!empty($m['error_information'])) {
        echo '<p class="small" style="margin-top:8px;color:var(--danger)">Problem: ' . e((string)$m['error_information']) . '</p>';
    }

    echo '</div></div>';
}
echo '</div>';

admin_layout_end();
