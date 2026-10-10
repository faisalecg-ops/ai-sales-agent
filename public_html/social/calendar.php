<?php
declare(strict_types=1);

/** Month calendar of scheduled, due and posted posts and published articles. */
require __DIR__ . '/_social.php';

sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$tz = SmEvent::tz($agent);
$m = sm_in('month');
$ym = preg_match('/^(\d{4})-(\d{2})$/', $m, $mm) && checkdate((int)$mm[2], 1, (int)$mm[1]) ? $m : (new DateTimeImmutable('now', $tz))->format('Y-m');
$first = new DateTimeImmutable($ym . '-01 00:00', $tz);
$next = $first->modify('+1 month');
$utc = new DateTimeZone('UTC');
$from = $first->setTimezone($utc)->format('Y-m-d H:i:s');
$to = $next->setTimezone($utc)->format('Y-m-d H:i:s');

$items = [];
$put = static function (?string $utcTime, array $item) use (&$items, $tz): void {
    if (!$utcTime) {
        return;
    }
    $local = (new DateTimeImmutable($utcTime . ' UTC'))->setTimezone($tz);
    $item['time'] = $local->format('H:i');
    $items[$local->format('Y-m-d')][] = $item;
};
foreach (db()->all("SELECT * FROM sm_posts WHERE agent_id = ? AND status IN ('scheduled','due','posted')
                    AND COALESCE(posted_at, scheduled_at) >= ? AND COALESCE(posted_at, scheduled_at) < ?", [$aid, $from, $to]) as $p) {
    $put($p['status'] === 'posted' ? $p['posted_at'] : $p['scheduled_at'], [
        'kind' => $p['status'], 'label' => SmEvent::PLATFORMS[$p['platform']], 'text' => (string)$p['body'], 'href' => sm_url('post.php', ['id' => $p['id']]), 'platform' => $p['platform'],
    ]);
}
foreach (db()->all("SELECT * FROM sm_articles WHERE agent_id = ? AND status = 'published' AND published_at >= ? AND published_at < ?", [$aid, $from, $to]) as $a) {
    $put($a['published_at'], ['kind' => 'article', 'label' => 'Blog', 'text' => (string)$a['title'], 'href' => sm_url('article.php', ['id' => $a['id']]), 'platform' => 'blog']);
}
foreach ($items as &$day) {
    usort($day, static fn($x, $y) => strcmp($x['time'], $y['time']));
}
unset($day);

sm_layout_start('Calendar');
echo '<div class="btn-row sm-cal-nav"><a class="btn btn-small btn-quiet" href="' . e(sm_url('calendar.php', ['month' => $first->modify('-1 month')->format('Y-m')])) . '">← ' . e($first->modify('-1 month')->format('F')) . '</a>'
   . '<strong style="font-size:18px">' . e($first->format('F Y')) . '</strong>'
   . '<a class="btn btn-small btn-quiet" href="' . e(sm_url('calendar.php', ['month' => $next->format('Y-m')])) . '">' . e($next->format('F')) . ' →</a>'
   . '<span class="muted small">Times are ' . e($tz->getName()) . '.</span></div>';

$start = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
$todayKey = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
echo '<div class="sm-cal" role="table" aria-label="' . e($first->format('F Y')) . '">';
foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) {
    echo '<div class="sm-cal-h" role="columnheader">' . $d . '</div>';
}
for ($d = $start; $d < $next || (int)$d->format('N') !== 1; $d = $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    $in = $d->format('Y-m') === $ym;
    echo '<div class="sm-cal-d' . ($in ? '' : ' is-out') . ($key === $todayKey ? ' is-today' : '') . '" role="cell"><span class="sm-cal-n">' . $d->format('j') . '</span>';
    foreach ($items[$key] ?? [] as $it) {
        echo '<a class="sm-cal-i sm-k-' . e($it['kind']) . '" href="' . e($it['href']) . '" title="' . e(mb_strimwidth($it['text'], 0, 200, '…')) . '">'
           . '<b>' . e($it['time']) . '</b> ' . e($it['label']) . '</a>';
    }
    echo '</div>';
}
echo '</div><p class="small muted sm-legend"><span class="sm-cal-i sm-k-scheduled">Scheduled</span> <span class="sm-cal-i sm-k-due">Due now</span> <span class="sm-cal-i sm-k-posted">Posted</span> <span class="sm-cal-i sm-k-article">Blog article published</span></p>';
sm_layout_end();
