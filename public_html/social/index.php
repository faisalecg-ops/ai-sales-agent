<?php
declare(strict_types=1);

/** Dashboard: what needs a person today, and how the month is going. */
require __DIR__ . '/_social.php';

sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$facts = SmEvent::facts($agent);
$phase = SmEvent::phase($agent);
$count = static fn(string $sql, array $p = []) => (int)db()->value($sql, array_merge([$aid], $p));
$month = gmdate('Y-m-01 00:00:00');

$due = db()->all("SELECT * FROM sm_posts WHERE agent_id = ? AND status IN ('due','failed') ORDER BY scheduled_at", [$aid]);
$reviewA = db()->all("SELECT * FROM sm_articles WHERE agent_id = ? AND status = 'in_review' ORDER BY updated_at", [$aid]);
$reviewP = db()->all("SELECT * FROM sm_posts WHERE agent_id = ? AND status = 'in_review' ORDER BY updated_at", [$aid]);
$tz = SmEvent::tz($agent);
$todayStart = (new DateTimeImmutable('today', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$todayEnd = (new DateTimeImmutable('tomorrow', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$today = db()->all("SELECT * FROM sm_posts WHERE agent_id = ? AND status = 'scheduled' AND scheduled_at >= ? AND scheduled_at < ? ORDER BY scheduled_at", [$aid, $todayStart, $todayEnd]);
$row = SmEvent::row($aid);
$settings = SmEvent::settings($aid);
$lastCron = (string)db()->value("SELECT v FROM sm_meta WHERE k = 'last_cron'");

sm_layout_start('Dashboard', sm_can('editor') ? ['Write an article' => sm_url('plan.php'), 'New post' => sm_url('posts.php', ['new' => 1])] : []);

// Set-up still open
$todo = [];
if (empty($row['blog_url']) || empty($row['blog_api_key'])) {
    $todo[] = ['Connect the event blog', sm_url('connections.php')];
}
if (!SmEvent::platforms($aid, true)) {
    $todo[] = ['Switch on at least one social platform', sm_url('connections.php')];
}
if ($facts['starts'] === '') {
    $todo[] = ['Event dates are missing — add them in the sales agent (Agents and embed → Dates & venue)', url('admin/agents.php')];
}
if ($lastCron === '' || strtotime($lastCron . ' UTC') < time() - 3600) {
    $todo[] = ['The scheduled task (cron) is not running yet, so scheduled posts will not remind anyone', sm_url('guide.php') . '#cron'];
}
if (trim((string)$settings['reminder_emails']) === '') {
    $todo[] = ['Add the email addresses that get "time to post" reminders', sm_url('settings.php') . '#people'];
}
if ($todo) {
    echo '<div class="card"><h2>Finish setting up</h2><ul class="plain">';
    foreach ($todo as [$label, $href]) {
        echo '<li>○ ' . (sm_can('admin') ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label)) . '</li>';
    }
    echo '</ul></div>';
}

echo '<div class="stat-tiles">';
$tiles = [
    ['Waiting for approval', count($reviewA) + count($reviewP), sm_url('articles.php', ['status' => 'in_review'])],
    ['Due to post now', count($due), sm_url('posts.php', ['status' => 'due'])],
    ['Articles published this month', $count("SELECT COUNT(*) FROM sm_articles WHERE agent_id = ? AND status = 'published' AND published_at >= ?", [$month]), sm_url('articles.php', ['status' => 'published'])],
    ['Posts posted this month', $count("SELECT COUNT(*) FROM sm_posts WHERE agent_id = ? AND status = 'posted' AND posted_at >= ?", [$month]), sm_url('posts.php', ['status' => 'posted'])],
];
foreach ($tiles as [$label, $n, $href]) {
    echo '<a class="stat-tile" href="' . e($href) . '"><span class="stat-value">' . (int)$n . '</span><span class="stat-label">' . e($label) . '</span></a>';
}
echo '</div>';

if ($due) {
    echo '<div class="card"><h2>Post these now</h2><p class="muted">Their scheduled time has come. Open each one, post it on the platform, then press "Done — I posted it".</p><ul class="plain">';
    foreach ($due as $p) {
        echo '<li>' . sm_pill($p['status']) . ' <a href="' . e(sm_url('post.php', ['id' => $p['id']])) . '">' . e(SmEvent::PLATFORMS[$p['platform']]) . ' — ' . e(mb_strimwidth((string)$p['body'], 0, 90, '…')) . '</a> <span class="muted small">' . e(sm_time($p['scheduled_at'])) . '</span></li>';
    }
    echo '</ul></div>';
}

echo '<div class="grid grid-2">';
echo '<div class="card"><h2>Waiting for approval</h2>';
if (!$reviewA && !$reviewP) {
    echo '<p class="muted">Nothing is waiting.</p>';
} else {
    echo '<ul class="plain">';
    foreach ($reviewA as $a) {
        echo '<li>Article: <a href="' . e(sm_url('article.php', ['id' => $a['id']])) . '">' . e((string)$a['title']) . '</a></li>';
    }
    foreach ($reviewP as $p) {
        echo '<li>' . e(SmEvent::PLATFORMS[$p['platform']]) . ': <a href="' . e(sm_url('post.php', ['id' => $p['id']])) . '">' . e(mb_strimwidth((string)$p['body'], 0, 80, '…')) . '</a></li>';
    }
    echo '</ul>';
}
echo '</div>';

echo '<div class="card"><h2>Scheduled for today</h2>';
if (!$today) {
    echo '<p class="muted">Nothing is scheduled today. <a href="' . e(sm_url('calendar.php')) . '">See the calendar</a>.</p>';
} else {
    echo '<ul class="plain">';
    foreach ($today as $p) {
        echo '<li>' . e(sm_time($p['scheduled_at'], 'H:i')) . ' · ' . e(SmEvent::PLATFORMS[$p['platform']]) . ' — <a href="' . e(sm_url('post.php', ['id' => $p['id']])) . '">' . e(mb_strimwidth((string)$p['body'], 0, 70, '…')) . '</a></li>';
    }
    echo '</ul>';
}
echo '</div></div>';

echo '<div class="grid grid-2"><div class="card"><h2>' . e($facts['name']) . '</h2><p>' . e($facts['dates'] !== '' ? $facts['dates'] : 'Dates not set') . ($facts['venue'] !== '' ? ' · ' . e($facts['venue']) : '') . '</p>'
   . '<p><strong>Now:</strong> ' . e($phase['label']) . '. Leading content: ' . e(implode(', ', array_map(static fn($k) => SmEvent::PILLARS[$k][0], $phase['lead']))) . '.</p></div>';
echo '<div class="card"><h2>AI use this month</h2>';
foreach (['text' => 'Writing jobs', 'image' => 'AI pictures'] as $k => $label) {
    $used = SmAi::used($aid, $k);
    $limit = SmAi::limit($aid, $k);
    $pct = $limit > 0 ? min(100, (int)round($used * 100 / $limit)) : 100;
    echo '<p style="margin:.4rem 0 .2rem">' . e($label) . ': ' . $used . ' of ' . $limit . '</p><div class="meter"><span style="width:' . $pct . '%"></span></div>';
}
echo '</div></div>';

sm_layout_end();
