<?php
declare(strict_types=1);

/** Social posts for this exhibition, and a new post without an article. */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && sm_in('do') === 'new') {
    $brief = mb_substr(sm_in('brief'), 0, 2000);
    if (mb_strlen($brief) < 15) {
        sm_flash('Write a sentence or two about what the post should say.', 'error');
        sm_redirect('posts.php', ['new' => 1]);
    }
    $photo = (int)sm_in('photo_id');
    if (!empty($_FILES['photo']['name'])) {
        $up = SmMedia::upload($aid, $_FILES['photo'], 'Photo for a post', (int)$user['id']);
        if (!$up['ok']) {
            sm_flash((string)$up['error'], 'error');
            sm_redirect('posts.php', ['new' => 1]);
        }
        $photo = (int)$up['id'];
    }
    $r = SmFlow::makeStandalone($agent, $brief, $photo ?: null, (int)$user['id']);
    sm_flash($r['message'], $r['ok'] ? 'ok' : 'error');
    sm_redirect('posts.php', ['status' => 'draft']);
}

$status = sm_in('status');
$platform = sm_in('platform');
$labels = ['' => 'All', 'draft' => 'Drafts', 'in_review' => 'Waiting for approval', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'due' => 'Due now', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];
$where = 'agent_id = ?';
$params = [$aid];
if (isset($labels[$status]) && $status !== '') {
    $where .= ' AND status = ?';
    $params[] = $status;
} else {
    $where .= " AND status <> 'cancelled'";
}
if (isset(SmEvent::PLATFORMS[$platform])) {
    $where .= ' AND platform = ?';
    $params[] = $platform;
}
$rows = db()->all("SELECT * FROM sm_posts WHERE $where ORDER BY COALESCE(scheduled_at, created_at) DESC, id DESC LIMIT 300", $params);

sm_layout_start('Social posts', sm_can('editor') ? ['New post without an article' => sm_url('posts.php', ['new' => 1])] : []);

if (isset($_GET['new']) && sm_can('editor')) {
    $imgs = db()->all("SELECT id, label FROM sm_media WHERE agent_id = ? AND kind = 'image' ORDER BY id DESC LIMIT 60", [$aid]);
    $opts = ['' => 'None'];
    foreach ($imgs as $m) {
        $opts[(string)$m['id']] = '#' . $m['id'] . ' ' . mb_strimwidth((string)$m['label'], 0, 60, '…');
    }
    echo '<div class="card"><h2>New post without an article</h2><p class="muted">For event reminders, show-day photos, announcements and thank-yous. The agent writes one version per switched-on platform.</p>'
       . '<form method="post" enctype="multipart/form-data">' . sm_csrf() . '<input type="hidden" name="do" value="new">'
       . f_area('brief', 'What should the post say?', '', ['rows' => 4, 'hint' => 'Facts only: the agent uses nothing else except the event\'s dates and venue. Example: "30 days to go. Over 350 exhibitors from 21 countries. Register free online."'])
       . '<div class="form-grid">' . f_select('photo_id', 'Photo from the library', $opts, '')
       . '<div class="field"><label for="f_photo">Or upload a photo or video</label><input type="file" id="f_photo" name="photo" accept="image/*,video/mp4,video/quicktime,video/webm"></div></div>'
       . '<button class="btn" type="submit" data-busy="Writing the posts…">Write the posts</button></form></div>';
}

echo '<nav class="set-nav">';
foreach ($labels as $k => $l) {
    echo '<a href="' . e(sm_url('posts.php', array_filter(['status' => $k, 'platform' => $platform]))) . '"' . ($k === $status ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';
if (!$rows) {
    echo '<div class="empty"><p>No posts here. Posts are written from each published blog article, or press "New post without an article".</p></div>';
} else {
    echo '<div class="card"><table class="table"><thead><tr><th>Platform</th><th>Post</th><th>Status</th><th>When</th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $when = match ((string)$p['status']) {
            'posted' => sm_time($p['posted_at']),
            'scheduled', 'due' => sm_time($p['scheduled_at']),
            default => '',
        };
        echo '<tr><td><span class="sm-plat sm-plat-' . e((string)$p['platform']) . '">' . e(SmEvent::PLATFORMS[$p['platform']]) . '</span></td>'
           . '<td><a href="' . e(sm_url('post.php', ['id' => $p['id']])) . '">' . e(mb_strimwidth((string)$p['body'], 0, 110, '…')) . '</a>'
           . ($p['article_id'] ? '<br><span class="small muted">From an article</span>' : '') . '</td><td>' . sm_pill((string)$p['status']) . '</td><td class="small">' . e($when) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
sm_layout_end();
