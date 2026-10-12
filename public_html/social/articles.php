<?php
declare(strict_types=1);

/** Blog articles for this exhibition, by status. */
require __DIR__ . '/_social.php';

sm_page('viewer');
$aid = sm_agent_id();
$status = sm_in('status');
$labels = ['' => 'All', 'writing' => 'Being written', 'draft' => 'Drafts', 'in_review' => 'Waiting for approval', 'sent' => 'Sent to the blog', 'published' => 'Published', 'failed' => 'Failed', 'rejected' => 'Discarded'];
$where = 'agent_id = ?';
$params = [$aid];
if (isset($labels[$status]) && $status !== '') {
    $where .= ' AND status = ?';
    $params[] = $status;
} else {
    $where .= " AND status <> 'rejected'";
}
$rows = db()->all("SELECT * FROM sm_articles WHERE $where ORDER BY id DESC LIMIT 200", $params);

sm_layout_start('Blog articles', sm_can('editor') ? ['Write an article' => sm_url('plan.php')] : []);
echo '<nav class="set-nav">';
foreach ($labels as $k => $l) {
    echo '<a href="' . e(sm_url('articles.php', $k !== '' ? ['status' => $k] : [])) . '"' . ($k === $status ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';
if (!$rows) {
    echo '<div class="empty"><p>No articles here yet. Pick a topic on the <a href="' . e(sm_url('plan.php')) . '">Content plan</a>.</p></div>';
} else {
    echo '<div class="card"><table class="table"><thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . e(sm_url('article.php', ['id' => $r['id']])) . '"><strong>' . e((string)$r['title']) . '</strong></a>'
           . ($r['blog_url'] ? '<br><a class="small" href="' . e((string)$r['blog_url']) . '" target="_blank" rel="noopener">On the blog ↗</a>' : '') . '</td>'
           . '<td class="small">' . e(($r['origin'] ?? '') === 'blog' ? 'Written in the blog' : (SmEvent::PILLARS[$r['pillar']][0] ?? $r['pillar'])) . '</td><td>' . sm_pill((string)$r['status']) . '</td>'
           . '<td class="small">' . e(sm_time($r['updated_at'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
sm_layout_end();
