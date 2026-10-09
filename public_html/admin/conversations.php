<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

admin_require_login();
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$region = Validator::inList($_GET['region'] ?? null, ['international', 'pakistan', 'undetermined'], null);
$state  = Validator::inList($_GET['state'] ?? null, ConversationState::ALL, null);
$priced = !empty($_GET['priced']);

$where  = ['agent_id = ?'];
$params = [$agentId];
if ($region !== null) { $where[] = 'pricing_country = ?'; $params[] = $region; }
if ($state !== null)  { $where[] = 'state = ?';           $params[] = $state; }
if ($priced)          { $where[] = 'pricing_shown = 1'; }

$sql = 'FROM conversations WHERE ' . implode(' AND ', $where);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$total   = (int)db()->value('SELECT COUNT(*) ' . $sql, $params);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);

$rows = db()->all(
    'SELECT * ' . $sql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

$stateOptions = ['' => 'Any'];
foreach (ConversationState::ALL as $s) {
    $stateOptions[$s] = ConversationState::label($s);
}

admin_layout_start('Conversations');

echo '<form method="get" class="filters">'
   . f_select('region', 'Region', ['' => 'Any', 'international' => 'International', 'pakistan' => 'Pakistan', 'undetermined' => 'Not established'], (string)$region)
   . f_select('state', 'Stage reached', $stateOptions, (string)$state)
   . '<label class="small"><input type="checkbox" name="priced" value="1"' . ($priced ? ' checked' : '') . '> Pricing was shown</label>'
   . '<button class="btn" type="submit">Filter</button>'
   . '<a class="btn btn-quiet" href="' . e(url('admin/conversations.php')) . '">Clear</a>'
   . '</form>';

echo '<p class="muted">' . $total . ' ' . ($total === 1 ? 'conversation' : 'conversations') . '</p>';

if (!$rows) {
    echo '<div class="empty">Nothing matches these filters.</div>';
} else {
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">Started</th><th>Messages</th><th>Stage reached</th>'
       . '<th>Region</th><th>Pricing shown</th><th>Lead</th><th></th></tr></thead><tbody>';

    foreach ($rows as $c) {
        $leadRef = $c['lead_id']
            ? (string)db()->value('SELECT reference FROM leads WHERE id = ?', [(int)$c['lead_id']])
            : '';
        echo '<tr><td style="padding-left:16px" class="small">' . e(admin_time((string)$c['created_at'])) . '</td>'
           . '<td class="num">' . (int)$c['message_count'] . '</td>'
           . '<td>' . e(ConversationState::label((string)$c['state'])) . '</td>'
           . '<td>' . e(ucfirst((string)$c['pricing_country'])) . '</td>'
           . '<td>' . ((int)$c['pricing_shown'] === 1 ? '<span class="pill pill-ok">Yes</span>' : '<span class="muted">No</span>') . '</td>'
           . '<td class="mono small">' . ($leadRef !== ''
                ? '<a href="' . e(url('admin/lead.php?id=' . (int)$c['lead_id'])) . '">' . e($leadRef) . '</a>'
                : '<span class="muted">None</span>') . '</td>'
           . '<td style="padding-right:16px"><a href="' . e(url('admin/conversation.php?id=' . (int)$c['id'])) . '">Read</a></td></tr>';
    }
    echo '</tbody></table></div></div>';

    if ($pages > 1) {
        echo '<div class="btn-row" style="margin-top:14px">';
        if ($page > 1) {
            echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/conversations.php' . admin_query(['page' => $page - 1]))) . '">Previous</a>';
        }
        echo '<span class="muted small">Page ' . $page . ' of ' . $pages . '</span>';
        if ($page < $pages) {
            echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/conversations.php' . admin_query(['page' => $page + 1]))) . '">Next</a>';
        }
        echo '</div>';
    }
}

admin_layout_end();
