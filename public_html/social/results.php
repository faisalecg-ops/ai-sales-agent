<?php
declare(strict_types=1);

/**
 * Results (Stage 4): what social media brought this month — posts, reactions,
 * visits to the blog, chats with the assistant and leads — and the monthly
 * management report, which is emailed early each month to the addresses in
 * Event settings → Monthly report. The page prints as the report (Print /
 * Save as PDF).
 */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$ym = SmStats::validMonth(sm_in('m', gmdate('Y-m')));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    if ($do === 'refresh' && sm_can('editor')) {
        $r = SmStats::refreshBlog($agent, $ym);
        sm_flash($r['message'], $r['ok'] ? 'ok' : 'error');
    }
    if ($do === 'send' && sm_can('admin')) {
        $r = SmStats::send($agent, $ym, (int)$user['id']);
        sm_flash($r['message'], $r['ok'] ? 'ok' : 'error');
    }
    sm_redirect('results.php', ['m' => $ym]);
}

$d = SmStats::month($agent, $ym);
$to = SmStats::recipients($aid);
$sent = db()->all('SELECT * FROM sm_reports WHERE agent_id = ? ORDER BY month DESC LIMIT 12', [$aid]);

sm_layout_start('Results');

echo '<div class="card sm-noprint"><form method="get" class="filters" style="align-items:end">'
   . '<input type="hidden" name="agent" value="' . $aid . '"><div class="field"><label for="m">Month</label><select class="input" id="m" name="m" data-autosubmit>';
$start = strtotime(gmdate('Y-m-15'));
for ($i = 0; $i < 13; $i++) {
    $opt = gmdate('Y-m', strtotime("-$i month", $start));
    echo '<option value="' . e($opt) . '"' . ($opt === $ym ? ' selected' : '') . '>' . e(gmdate('F Y', strtotime($opt . '-01')) . ($i === 0 ? ' (so far)' : '')) . '</option>';
}
echo '</select></div><button class="btn btn-small" type="submit" data-autosubmit-fallback>Show</button>'
   . '<button class="btn btn-small" type="button" data-print>Print or save as PDF</button></form>'
   . '<div class="btn-row">';
if (sm_can('editor')) {
    echo sm_button('results.php', ['do' => 'refresh', 'm' => $ym], 'Get the latest blog figures', 'btn btn-small btn-quiet');
}
if (sm_can('admin')) {
    echo sm_button('results.php', ['do' => 'send', 'm' => $ym], 'Email this report now', 'btn btn-small',
        $to ? 'Email the ' . $d['label'] . ' report to ' . implode(', ', $to) . '?' : '');
}
echo '</div><p class="small muted" style="margin-bottom:0">'
   . ($to ? 'The monthly report is emailed by itself early each month (for the month before) to: <strong>' . e(implode(', ', $to)) . '</strong>.'
          : 'No one receives the monthly report yet. ' . (sm_can('admin') ? 'Add the addresses under <a href="' . e(sm_url('settings.php')) . '">Event settings → Monthly report</a>.' : 'An admin can add the addresses under Event settings.'))
   . ($d['blog_fetched_at'] ? ' Blog figures from ' . e(sm_time($d['blog_fetched_at'])) . '.' : '') . '</p></div>';

echo '<div class="card"><div class="sm-report-wrap">' . SmStats::reportHtml($d) . '</div></div>';

if ($sent) {
    echo '<div class="card sm-noprint"><h2>Reports sent</h2><table class="table"><thead><tr><th>Month</th><th>Sent</th><th>To</th><th></th></tr></thead><tbody>';
    foreach ($sent as $r) {
        echo '<tr><td><a href="' . e(sm_url('results.php', ['m' => $r['month']])) . '">' . e(gmdate('F Y', strtotime($r['month'] . '-01'))) . '</a></td>'
           . '<td class="small">' . ($r['sent_at'] ? e(sm_time($r['sent_at'])) : '<span class="sm-warn">not sent</span>') . '</td>'
           . '<td class="small">' . e((string)$r['sent_to']) . '</td><td class="small muted">' . e((string)$r['error']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
sm_layout_end();
