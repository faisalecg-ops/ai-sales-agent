<?php
declare(strict_types=1);

/**
 * Dashboard. Answers the questions an administrator opens the panel with:
 * is the assistant working, what came in, and what needs me.
 */

require __DIR__ . '/_admin.php';

admin_require_access('view');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$since = gmdate('Y-m-d H:i:s', time() - (30 * 86400));
$count = static fn(string $sql, array $p = []): int => (int)db()->value($sql, $p);

$stats = [
    'conversations' => $count('SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND created_at > ?', [$agentId, $since]),
    'leads'         => $count('SELECT COUNT(*) FROM leads WHERE agent_id = ? AND created_at > ? AND merged_into_id IS NULL', [$agentId, $since]),
    'qualified'     => $count("SELECT COUNT(*) FROM leads WHERE agent_id = ? AND created_at > ? AND status IN ('qualified','pricing_discussed','interested','converted') AND merged_into_id IS NULL", [$agentId, $since]),
    'handoffs'      => $count('SELECT COUNT(*) FROM leads WHERE agent_id = ? AND created_at > ? AND handoff_required = 1 AND merged_into_id IS NULL', [$agentId, $since]),
    'pricing'       => $count('SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND created_at > ? AND pricing_shown = 1', [$agentId, $since]),
];

$regions = [
    'international' => $count("SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND created_at > ? AND pricing_country = 'international'", [$agentId, $since]),
    'pakistan'      => $count("SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND created_at > ? AND pricing_country = 'pakistan'", [$agentId, $since]),
    'undetermined'  => $count("SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND created_at > ? AND pricing_country = 'undetermined'", [$agentId, $since]),
];

$health   = CircuitBreaker::statusFor($agent);
$pending  = ReviewWorkflow::pendingCount($agentId);
$approved = KnowledgeRetriever::approvedCount($agentId);
$prices   = $count("SELECT COUNT(*) FROM stall_packages WHERE agent_id = ? AND status = 'approved' AND active = 1", [$agentId])
          + $count("SELECT COUNT(*) FROM sponsorship_packages WHERE agent_id = ? AND status = 'approved' AND active = 1", [$agentId]);

$recentLeads = db()->all(
    "SELECT * FROM leads WHERE agent_id = ? AND merged_into_id IS NULL ORDER BY id DESC LIMIT 8",
    [$agentId]
);

// Questions the assistant could not answer, worth knowing about.
$gaps = db()->all(
    "SELECT v.content, v.created_at
     FROM messages a
     JOIN messages v ON v.conversation_id = a.conversation_id
                    AND v.sequence_number = a.sequence_number - 1
                    AND v.role = 'visitor'
     WHERE a.agent_id = ? AND a.role = 'agent' AND a.is_fallback = 1 AND a.created_at > ?
     ORDER BY a.id DESC LIMIT 6",
    [$agentId, $since]
);

admin_layout_start('Dashboard');

// ---- Anything that needs attention comes first ----
$warnings = [];
if ($health['status'] === CircuitBreaker::UNAVAILABLE) {
    $warnings[] = ['error', 'The assistant is offline. Visitors are seeing the standard offline message and a contact form, so enquiries are still being captured. Last problem: ' . ($health['last_error'] ?? 'unknown') . '.'];
}
if ($approved['articles'] === 0 && $approved['faqs'] === 0) {
    $warnings[] = ['warn', 'No approved knowledge yet. The assistant can only say it does not have the information.'];
}
if ($prices === 0) {
    $warnings[] = ['warn', 'No approved pricing yet. The assistant will not quote a figure to anyone.'];
}
if (trim((string)$agent['allowed_domains']) === '') {
    $warnings[] = ['error', 'No website addresses are listed for this agent, so every request is refused. Add them under Agents and embed.'];
}
if (!LlmProviderFactory::make((string)$agent['llm_provider'])->isConfigured()) {
    $providerProblem = LlmCatalogue::configurationProblem((string)$agent['llm_provider'])
        ?? ('No API key is set for ' . LlmCatalogue::label((string)$agent['llm_provider']) . '.');
    $warnings[] = ['error', $providerProblem . ' Add it under Settings, then use the test button there '
                          . 'to check it works.'];
}

// Staff cannot open Settings or the exhibition's set-up, so point them to
// the person who can.
if (!admin_is_super()) {
    $warnings = array_map(static fn(array $w): array => [$w[0], preg_replace(
        '/ (Add it under Settings, then use the test button there\s*to check it works|Add them under Agents and embed)\.$/',
        ' Please tell the administrator.', $w[1]) ?? $w[1]], $warnings);
}

foreach ($warnings as [$kind, $text]) {
    echo '<div class="alert alert-' . e($kind) . '"><p>' . e($text) . '</p></div>';
}

// ---- What is waiting for someone ----
$waiting = admin_waiting($agentId);
$todo = [
    ['leads.php', $waiting['leads'], 'New leads', 'to call or email', 'view'],
    ['whatsapp.php', $waiting['whatsapp'], 'WhatsApp', 'to approve or answer', 'manage'],
    ['prospects.php', $waiting['prospects'], 'Prospect emails', 'to approve', 'manage'],
    ['review.php', $pending, 'Review queue', 'content to approve', 'manage'],
];
// Only what this person can open.
$todo = array_values(array_filter($todo, static fn(array $t): bool => admin_can($t[4])));
echo '<h2 class="dash-h">Waiting for you</h2><div class="todo-row">';
foreach ($todo as [$href, $n, $label, $what]) {
    echo '<a class="todo' . ($n > 0 ? ' has' : '') . '" href="' . e(url('admin/' . $href)) . '"><b>' . (int)$n . '</b>'
       . '<span>' . e($label) . '</span><small>' . ($n > 0 ? e($what) : 'Nothing waiting') . '</small></a>';
}
echo '</div>';

// ---- Status ----
echo '<div class="card"><div class="card-head"><h2>Assistant status</h2>'
   . '<span class="pill ' . ($health['status'] === 'normal' ? 'pill-ok' : 'pill-bad') . '">'
   . e($health['label']) . '</span></div>';
echo '<table class="table"><tbody>'
   . '<tr><td style="width:190px">AI provider</td><td>'
   . e(LlmCatalogue::label((string)$health['provider'])) . ' — ' . e((string)$agent['llm_model'])
   . (trim((string)($agent['llm_fallback_provider'] ?? '')) !== ''
        ? '<br><span class="small muted">Backup: ' . e(LlmCatalogue::label((string)$agent['llm_fallback_provider'])) . '</span>'
        : '')
   . '</td></tr>'
   . '<tr><td>Last successful reply</td><td>' . e(admin_time($health['last_success_at'])) . '</td></tr>'
   . '<tr><td>Last problem</td><td>' . e(admin_time($health['last_failure_at']))
   . ($health['last_error'] ? ' — ' . e((string)$health['last_error']) : '') . '</td></tr>'
   . '<tr><td>Approved content</td><td>' . (int)$approved['articles'] . ' articles, '
   . (int)$approved['faqs'] . ' FAQs, ' . $prices . ' price records</td></tr>'
   . '</tbody></table></div>';

// ---- Numbers ----
echo '<h2 class="dash-h">Last 30 days</h2><div class="grid grid-4" style="margin-bottom:18px">';
foreach ([
    ['conversations', 'Conversations', ''],
    ['leads', 'Leads captured', ''],
    ['qualified', 'Qualified', 'is-ok'],
    ['handoffs', 'Passed to sales', 'is-signal'],
] as [$key, $label, $cls]) {
    echo '<div class="stat ' . $cls . '"><b>' . (int)$stats[$key] . '</b><span>' . e($label) . '</span></div>';
}
echo '</div>';

echo '<div class="grid grid-3" style="margin-bottom:18px">';
echo '<div class="stat"><b>' . (int)$regions['international'] . '</b><span>International visitors</span></div>';
echo '<div class="stat is-signal"><b>' . (int)$regions['pakistan'] . '</b><span>Pakistan visitors, sent to your team</span></div>';
echo '<div class="stat is-danger"><b>' . (int)$regions['undetermined'] . '</b><span>Country never established</span></div>';
echo '</div>';

// ---- Recent leads ----
echo '<div class="card"><div class="card-head"><h2>Latest leads</h2>'
   . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/leads.php')) . '">See all</a></div>';

if (!$recentLeads) {
    echo '<div class="empty">No leads yet.</div>';
} else {
    echo '<div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Reference</th><th>Who</th><th>Region</th><th>Status</th><th>Captured</th><th></th>'
       . '</tr></thead><tbody>';
    foreach ($recentLeads as $l) {
        $who = trim(((string)$l['name']) . ' ' . ((string)$l['company'] !== '' ? '— ' . (string)$l['company'] : ''));
        echo '<tr><td class="mono">' . e((string)$l['reference']) . '</td>'
           . '<td>' . e($who !== '' ? $who : 'Not given') . '</td>'
           . '<td>' . e(ucfirst((string)$l['pricing_country'])) . '</td>'
           . '<td>' . e(LeadManager::STATUS_LABELS[(string)$l['status']] ?? (string)$l['status'])
           . ((int)$l['handoff_required'] === 1 ? ' <span class="pill pill-warn">Handoff</span>' : '')
           . ((int)$l['dedup_flag'] === 1 ? ' <span class="pill">Possible duplicate</span>' : '')
           . '</td>'
           . '<td>' . e(admin_time((string)$l['created_at'])) . '</td>'
           . '<td><a href="' . e(url('admin/lead.php?id=' . (int)$l['id'])) . '">Open</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

// ---- Knowledge gaps ----
echo '<div class="card"><h2>Questions the assistant could not answer</h2>'
   . '<p class="lede">Each of these produced a standard reply instead of a real answer. '
   . 'They are the fastest guide to what is missing from your knowledge base.</p>';

if (!$gaps) {
    echo '<div class="empty">Nothing in the last 30 days.</div>';
} else {
    echo '<table class="table"><tbody>';
    foreach ($gaps as $g) {
        echo '<tr><td>' . e(mb_substr((string)$g['content'], 0, 220)) . '</td>'
           . '<td class="num muted small">' . e(admin_time((string)$g['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

admin_layout_end();
