<?php
declare(strict_types=1);

/**
 * Analytics: where business comes from, and how each channel is doing.
 *
 * Charts are drawn here as SVG, so they need no scripts and print as they
 * look. Every figure has a table view beside it.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('view');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$preset = (string)($_GET['range'] ?? '30');
$custom = $preset === 'custom';
$p = Analytics::period($agent, $custom ? ($_GET['from'] ?? null) : null, $custom ? ($_GET['to'] ?? null) : null, $preset);
$f = [
    'campaign' => (int)($_GET['campaign'] ?? 0) ?: null,
    'country'  => trim((string)($_GET['country'] ?? '')) ?: null,
    'category' => trim((string)($_GET['category'] ?? '')) ?: null,
];
$by = in_array($_GET['by'] ?? '', ['campaign', 'country', 'category'], true) ? (string)$_GET['by'] : 'campaign';

// ---- Download ----------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Analytics::prospectBreakdown($agent, $p, $f, $by);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="prospecting-by-' . $by . '-' . $p['from'] . '-to-' . $p['to'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the accents correctly
    fputcsv($out, [ucfirst($by), 'Found', 'Qualified', 'Emailed', 'Replied', 'Interested', 'Booked']);
    foreach ($rows as $r) {
        // A leading = + - @ would be run as a formula by a spreadsheet.
        $name = preg_match('/^[=+\-@]/', $r['name']) ? "'" . $r['name'] : $r['name'];
        fputcsv($out, [$name, $r['found'], $r['qualified'], $r['sent'], $r['replied'], $r['interested'], $r['booked']]);
    }
    fclose($out);
    exit;
}

$sources = Analytics::sources($agent, $p);
$daily   = Analytics::daily($agent, $p);
$funnel  = Analytics::prospectFunnel($agent, $p, $f);
$filters = Analytics::prospectFilters($agent);
$breakdown = Analytics::prospectBreakdown($agent, $p, $f, $by);
$whatsapp = Analytics::whatsapp($agent, $p);
$chat     = Analytics::websiteChat($agent, $p);
$exhibitions = Analytics::exhibitions($p, admin_is_super() ? null : Access::agentIds((int)$user['id'], 'sales'));

$query = static function (array $change = []) use ($p, $f, $by, $preset): string {
    $custom = $preset === 'custom';
    $q = array_filter(array_merge([
        'range' => $preset,
        'from' => $custom ? $p['from'] : null, 'to' => $custom ? $p['to'] : null,
        'campaign' => $f['campaign'], 'country' => $f['country'], 'category' => $f['category'], 'by' => $by,
    ], $change), static fn($v) => $v !== null && $v !== '');
    return url('admin/analytics.php') . '?' . http_build_query($q);
};

admin_layout_start('Analytics');

// ---- Period ---------------------------------------------------------
echo '<form method="get" class="filters">'
   . f_select('range', 'Period', ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days',
        'month' => 'This month', '365' => 'Last 12 months', 'custom' => 'These dates →'], $preset)
   . f_text('from', 'From', $p['from'], ['type' => 'date']) . f_text('to', 'To', $p['to'], ['type' => 'date'])
   . '<button class="btn" type="submit">Show</button></form>';

echo '<p class="lede">' . e($p['label']) . ' · ' . e((string)$agent['name']) . ' · days as in '
   . e((string)($agent['notify_timezone'] ?: 'Asia/Karachi')) . '. Interest and qualification are the AI\'s reading, or what '
   . 'your team recorded — they are not bookings until a booking is recorded.</p>';

// ---- Headline --------------------------------------------------------
$total = array_sum(array_column($sources, 'opportunities'));
$booked = array_sum(array_column($sources, 'booked'));
echo '<div class="stat-tiles">'
   . '<div class="stat-tile"><p class="stat-label">Opportunities</p><p class="stat-value">' . number_format($total) . '</p>'
   . '<p class="stat-note">Leads from chat and WhatsApp, and prospects who replied</p></div>';
foreach (Analytics::SOURCES as $key => $label) {
    echo '<div class="stat-tile"><p class="stat-label"><span class="swatch sw-' . e($key) . '"></span>' . e($label) . '</p>'
       . '<p class="stat-value">' . number_format($sources[$key]['opportunities']) . '</p>'
       . '<p class="stat-note">' . number_format($sources[$key]['interested']) . ' interested · '
       . number_format($sources[$key]['booked']) . ' booked</p></div>';
}
echo '<div class="stat-tile"><p class="stat-label">Booked</p><p class="stat-value">' . number_format($booked) . '</p>'
   . '<p class="stat-note">Marked as booked by your team</p></div></div>';

// ---- Trend chart -------------------------------------------------------
/**
 * A line chart of opportunities per day (or week, for long periods), one
 * line per source, with a label at each line's end and a tooltip on every
 * point.
 */
$days = $daily['days'];
$series = $daily['series'];
$bucket = count($days) > 62 ? 7 : 1;
$labels = [];
$values = [];
foreach ($series as $key => $vals) {
    $values[$key] = [];
}
for ($i = 0; $i < count($days); $i += $bucket) {
    $labels[] = $days[$i];
    foreach ($series as $key => $vals) {
        $values[$key][] = array_sum(array_slice($vals, $i, $bucket));
    }
}
$max = max(1, ...array_values(array_map(static fn($v) => $v ? max($v) : 0, $values)));
$step = max(1, (int)ceil($max / 4));
$top = $step * 4;
$W = 760; $H = 260; $L = 36; $R = 110; $T = 14; $B = 30;
$pw = $W - $L - $R; $ph = $H - $T - $B;
$n = count($labels);
$x = static fn(int $i) => $L + ($n <= 1 ? $pw / 2 : $i * $pw / ($n - 1));
$y = static fn(float $v) => $T + $ph - ($v / $top) * $ph;

echo '<div class="card"><div class="card-head"><h2>New opportunities ' . ($bucket === 7 ? 'per week' : 'per day') . '</h2></div>';
echo '<div class="chart-legend">';
foreach (Analytics::SOURCES as $key => $label) {
    echo '<span><span class="swatch sw-' . e($key) . '"></span>' . e($label) . '</span>';
}
echo '</div>';
echo '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="New opportunities over time, by source">';
for ($g = 0; $g <= 4; $g++) {
    $gy = $y($g * $step);
    echo '<line class="grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($gy, 1) . '" y2="' . round($gy, 1) . '"/>'
       . '<text class="tick" x="' . ($L - 8) . '" y="' . round($gy + 4, 1) . '" text-anchor="end">' . ($g * $step) . '</text>';
}
$every = max(1, (int)ceil($n / 7));
foreach ($labels as $i => $d) {
    if ($i % $every === 0 || $i === $n - 1) {
        echo '<text class="tick" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="' . ($i === $n - 1 ? 'end' : ($i === 0 ? 'start' : 'middle')) . '">'
           . e(date('j M', strtotime($d))) . '</text>';
    }
}
$ends = [];
foreach ($values as $key => $vals) {
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
    }
    echo '<polyline class="line ln-' . e($key) . '" points="' . implode(' ', $pts) . '"/>';
    foreach ($vals as $i => $v) {
        echo '<g class="pt"><circle class="hit" cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="9"/>'
           . '<circle class="dot dt-' . e($key) . '" cx="' . round($x($i), 1) . '" cy="' . round($y($v), 1) . '" r="4"/>'
           . '<title>' . e(Analytics::SOURCES[$key]) . ' · ' . e(date('j M Y', strtotime($labels[$i]))) . ($bucket === 7 ? ' (week)' : '')
           . ': ' . $v . '</title></g>';
    }
    $ends[$key] = $y((float)end($vals));
}
// End labels name each line. Placed from the bottom up, 15 apart, and never
// below the plot, so they neither overlap each other nor the dates.
arsort($ends);
$floor = $T + $ph;
$placed = [];
foreach ($ends as $key => $ly) {
    $ly = min($ly, $floor - 2);
    if ($placed) {
        $ly = min($ly, end($placed) - 15);
    }
    $placed[$key] = $ly;
}
foreach ($placed as $key => $ly) {
    echo '<text class="end-label" x="' . ($W - $R + 8) . '" y="' . round($ly + 4, 1) . '">' . e(Analytics::SOURCES[$key]) . '</text>';
}
echo '</svg>';
echo '<details class="small"><summary>Show as a table</summary><div class="table-wrap"><table class="table"><thead><tr><th>'
   . ($bucket === 7 ? 'Week starting' : 'Day') . '</th>';
foreach (Analytics::SOURCES as $label) { echo '<th class="num">' . e($label) . '</th>'; }
echo '</tr></thead><tbody>';
foreach ($labels as $i => $d) {
    echo '<tr><td>' . e(date('D j M Y', strtotime($d))) . '</td>';
    foreach ($values as $vals) { echo '<td class="num">' . (int)$vals[$i] . '</td>'; }
    echo '</tr>';
}
echo '</tbody></table></div></details></div>';

// ---- Funnel helper ---------------------------------------------------
$bars = static function (array $rows): string {
    $max = max(1, ...array_values(array_map(static fn($r) => (int)$r['count'], $rows ?: [['count' => 0]])));
    $h = '<div class="bars">';
    foreach ($rows as $r) {
        $pct = $r['count'] > 0 ? max(1.5, 100 * $r['count'] / $max) : 0;
        $h .= '<div class="bar-row" title="' . e($r['label'] . ': ' . number_format((int)$r['count'])) . '">'
            . '<div class="bar-label">' . e($r['label'])
            . (!empty($r['note']) ? '<span class="bar-note">' . e($r['note']) . '</span>' : '') . '</div>'
            . '<div class="bar-track"><div class="bar-fill" style="width:' . round($pct, 1) . '%"></div></div>'
            . '<div class="bar-value">' . number_format((int)$r['count']) . '</div></div>';
    }
    return $h . '</div>';
};

// ---- Prospecting -------------------------------------------------------
echo '<div class="card"><div class="card-head"><h2>Prospecting</h2></div>';
if (!$filters['campaigns']) {
    echo '<div class="empty">No campaigns yet. <a href="' . e(url('admin/prospects.php?new=1')) . '">Start one</a>.</div>';
} else {
    echo '<form method="get" class="filters" style="margin-bottom:14px">'
       . '<input type="hidden" name="range" value="' . e($preset) . '">'
       . ($custom ? '<input type="hidden" name="from" value="' . e($p['from']) . '"><input type="hidden" name="to" value="' . e($p['to']) . '">' : '')
       . f_select('campaign', 'Campaign', ['' => 'All'] + $filters['campaigns'], (string)($f['campaign'] ?? ''))
       . f_select('country', 'Country', ['' => 'All'] + array_combine($filters['countries'], $filters['countries']), (string)($f['country'] ?? ''))
       . f_select('category', 'Category', ['' => 'All'] + array_combine($filters['categories'], $filters['categories']), (string)($f['category'] ?? ''))
       . '<button class="btn btn-quiet" type="submit">Narrow</button></form>'
       . '<p class="small muted" style="margin:0 0 10px">Companies found in this period, and how far each has got.</p>'
       . $bars($funnel);

    echo '<h3 style="margin:22px 0 8px">By ' . e($by) . '</h3><p class="small" style="margin:0 0 8px">';
    foreach (['campaign' => 'Campaign', 'country' => 'Country', 'category' => 'Category'] as $k => $lbl) {
        echo $k === $by ? '<strong>' . e($lbl) . '</strong> · ' : '<a href="' . e($query(['by' => $k])) . '">' . e($lbl) . '</a> · ';
    }
    echo '<a href="' . e($query(['export' => 'csv'])) . '">Download as a spreadsheet (CSV)</a></p>';
    if (!$breakdown) {
        echo '<div class="empty">Nothing found in this period.</div>';
    } else {
        echo '<div class="table-wrap"><table class="table"><thead><tr><th>' . e(ucfirst($by)) . '</th><th class="num">Found</th>'
           . '<th class="num">Qualified</th><th class="num">Emailed</th><th class="num">Replied</th><th class="num">Interested</th>'
           . '<th class="num">Booked</th><th class="num">Reply rate</th></tr></thead><tbody>';
        foreach ($breakdown as $r) {
            echo '<tr><td>' . e($r['name']) . '</td><td class="num">' . $r['found'] . '</td><td class="num">' . $r['qualified'] . '</td>'
               . '<td class="num">' . $r['sent'] . '</td><td class="num">' . $r['replied'] . '</td><td class="num">' . $r['interested'] . '</td>'
               . '<td class="num">' . $r['booked'] . '</td><td class="num">'
               . ($r['sent'] > 0 ? round(100 * $r['replied'] / $r['sent']) . '%' : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
echo '</div>';

// ---- WhatsApp and website chat -------------------------------------------
echo '<div class="grid grid-2">';
echo '<div class="card"><h2>WhatsApp</h2>' . ($whatsapp ? $bars($whatsapp) : '<div class="empty">Not set up.</div>') . '</div>';
echo '<div class="card"><h2>Website chat</h2>' . $bars($chat) . '</div>';
echo '</div>';

// ---- All exhibitions ---------------------------------------------------
if (count($exhibitions) > 1) {
    echo '<div class="card"><h2>Every exhibition, same period</h2><div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Exhibition</th><th class="num">Chat leads</th><th class="num">WhatsApp leads</th><th class="num">Prospect emails sent</th>'
       . '<th class="num">Prospects replied</th><th class="num">Booked</th></tr></thead><tbody>';
    foreach ($exhibitions as $ex) {
        echo '<tr><td>' . e($ex['name']) . '</td><td class="num">' . $ex['chat_leads'] . '</td><td class="num">' . $ex['wa_leads'] . '</td>'
           . '<td class="num">' . $ex['emails'] . '</td><td class="num">' . $ex['replies'] . '</td><td class="num">' . $ex['booked'] . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

admin_layout_end();
