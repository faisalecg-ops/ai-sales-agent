<?php
declare(strict_types=1);

/**
 * The activity log: everything recorded for this exhibition, newest first,
 * each line marked with who did it — the AI, the platform itself, or a
 * named member of the team.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('manage');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

/** Events that are the AI's own work rather than a fixed rule of the platform. */
const ACTIVITY_AI_EVENTS = ['research_done', 'campaign_searched', 'outreach_drafted'];

const ACTIVITY_AREAS = [
    ''                  => 'Everything',
    'lead'              => 'Leads',
    'conversation'      => 'Conversations',
    'registration'      => 'Registrations',
    'whatsapp_outbox'   => 'WhatsApp messages',
    'prospect_campaign' => 'Campaigns',
    'prospect_target'   => 'Prospects',
    'outreach_email'    => 'Prospect emails',
    'company_research'  => 'Company research',
    'company'           => 'Companies',
    'suppression'       => 'Do not contact',
    'agent'             => 'Exhibition settings',
];

function activity_who(array $row): string
{
    if (!empty($row['admin_user_id']) || !in_array((string)$row['actor'], ['system', ''], true)) {
        return 'team';
    }
    $detail = (string)($row['detail'] ?? '');
    if (in_array((string)$row['event'], ACTIVITY_AI_EVENTS, true) && !str_contains($detail, 'request of')
        || str_contains($detail, '(AI)') || str_contains($detail, 'assistant judged')) {
        return 'ai';
    }
    return 'system';
}

$who  = in_array($_GET['who'] ?? '', ['ai', 'system', 'team'], true) ? (string)$_GET['who'] : '';
$area = array_key_exists((string)($_GET['area'] ?? ''), ACTIVITY_AREAS) ? (string)($_GET['area'] ?? '') : '';
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 100;

$where  = 'agent_id = ?';
$params = [$agentId];
if ($area !== '') {
    $where .= ' AND entity_type = ?';
    $params[] = $area;
}
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    // "lead status" finds lead_status_changed.
    $likeEvent = '%' . str_replace(['\\', '%', '_', ' '], ['\\\\', '\\%', '\\_', '\\_'], mb_strtolower($q)) . '%';
    $where .= ' AND (detail LIKE ? OR event LIKE ? OR actor LIKE ?)';
    array_push($params, $like, $likeEvent, $like);
}
if ($who === 'team') {
    $where .= " AND (admin_user_id IS NOT NULL OR actor NOT IN ('system',''))";
} elseif ($who !== '') {
    $where .= " AND admin_user_id IS NULL AND actor IN ('system','')";
}

// The AI / platform split is worked out per line, so those two are
// filtered after reading; a page may then hold fewer than a hundred.
$rows = db()->all('SELECT * FROM audit_log WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
$more = count($rows) > $per;
$rows = array_slice($rows, 0, $per);
if ($who === 'ai' || $who === 'system') {
    $rows = array_values(array_filter($rows, static fn($r) => activity_who($r) === $who));
}

admin_layout_start('Activity log');

echo '<form method="get" class="filters">'
   . f_select('who', 'Who', ['' => 'Anyone', 'ai' => 'The AI', 'system' => 'The platform', 'team' => 'The team'], $who)
   . f_select('area', 'Area', ACTIVITY_AREAS, $area)
   . f_text('q', 'Containing', $q, ['type' => 'search', 'placeholder' => 'a name, an email, an event'])
   . '<button class="btn" type="submit">Show</button></form>';

echo '<p class="lede small">Every change is recorded here with who made it: <strong>AI</strong> for what the assistant '
   . 'decided or wrote, <strong>Platform</strong> for the fixed rules (sending, limits, stops), and the name of '
   . 'the person for everything your team did. Nothing here can be edited or deleted.</p>';

if (!$rows) {
    echo '<div class="empty">Nothing matches.</div>';
} else {
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">When</th><th>Who</th><th>What</th><th>Details</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $w = activity_who($r);
        $detail = (string)($r['detail'] ?? '');
        if ((string)($r['field'] ?? '') !== '') {
            $detail = trim(ucfirst(str_replace('_', ' ', (string)$r['field'])) . ': '
                . mb_substr((string)$r['old_value'], 0, 60) . ' → ' . mb_substr((string)$r['new_value'], 0, 60) . ' ' . $detail);
        }
        echo '<tr><td style="padding-left:16px;white-space:nowrap" class="small muted">' . e(admin_time((string)$r['created_at'])) . '</td>'
           . '<td><span class="pill ' . ($w === 'team' ? 'pill-ok' : ($w === 'ai' ? 'pill-info' : '')) . '">'
           . e($w === 'team' ? (string)$r['actor'] : ($w === 'ai' ? 'AI' : 'Platform')) . '</span></td>'
           . '<td>' . e(PersonView::eventLabel((string)$r['event'])) . '<div class="small muted">'
           . e(ACTIVITY_AREAS[(string)$r['entity_type']] ?? ucfirst(str_replace('_', ' ', (string)$r['entity_type']))) . '</div></td>'
           . '<td class="small">' . e(mb_strimwidth($detail, 0, 220, '…', 'UTF-8')) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

$base = array_filter(['who' => $who, 'area' => $area, 'q' => $q]);
echo '<div class="btn-row" style="margin-top:12px">';
if ($page > 1) {
    echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/activity.php') . '?' . http_build_query($base + ['page' => $page - 1])) . '">Newer</a>';
}
if ($more) {
    echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/activity.php') . '?' . http_build_query($base + ['page' => $page + 1])) . '">Older</a>';
}
echo '</div>';

admin_layout_end();
