<?php
declare(strict_types=1);

/** Who did what, when, on this exhibition. */
require __DIR__ . '/_social.php';

sm_page('viewer');
$aid = sm_agent_id();
$rows = db()->all('SELECT * FROM sm_activity WHERE agent_id = ? ORDER BY id DESC LIMIT 300', [$aid]);
$labels = [
    'topics_suggested' => 'Topics suggested', 'topic_added' => 'Topic added', 'article_started' => 'Article asked for', 'article_written' => 'Article written',
    'article_edited' => 'Article edited', 'article_submitted' => 'Article sent for approval', 'article_approved' => 'Article approved', 'article_sent_back' => 'Article sent back',
    'article_sent_to_blog' => 'Article sent to the blog', 'article_discarded' => 'Article discarded', 'posts_written' => 'Posts written', 'post_edited' => 'Post edited',
    'post_submitted' => 'Post sent for approval', 'post_approved' => 'Post approved', 'post_sent_back' => 'Post sent back', 'post_now' => 'Post now', 'post_scheduled' => 'Post scheduled',
    'post_unscheduled' => 'Post taken off the schedule', 'post_done' => 'Posted', 'post_cancelled' => 'Post cancelled', 'reminder' => 'Reminder', 'media_uploaded' => 'Files uploaded',
    'settings_saved' => 'Settings saved', 'blog_connection_saved' => 'Blog connection saved', 'platforms_saved' => 'Platforms saved', 'blog_notice' => 'Notice from the blog', 'blog_article_found' => 'Article written in the blog picked up',
    'post_auto' => 'Posted automatically', 'post_failed' => 'Automatic posting failed', 'post_by_hand' => 'Switched to posting by hand', 'connected' => 'Page connected',
    'disconnected' => 'Page disconnected', 'connection_failed' => 'Connecting failed', 'apps_saved' => 'Platform apps saved', 'radar' => 'Industry radar', 'curated_shared' => 'Curated news written',
    'exhibitors_found' => 'Exhibitors found on the website', 'exhibitors_uploaded' => 'Exhibitor list uploaded', 'kits_made' => 'Exhibitor kits written', 'kits_approved' => 'Exhibitor kits approved', 'kits_sent' => 'Exhibitor kits emailed',
    'report_sent' => 'Monthly report emailed', 'stats_checked' => 'Post figures checked', 'stats_typed' => 'Post figures typed in',
];
sm_layout_start('Activity log');
if (!$rows) {
    echo '<div class="empty"><p>Nothing has happened yet.</p></div>';
} else {
    echo '<div class="card"><table class="table"><thead><tr><th>When</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="small" style="white-space:nowrap">' . e(sm_time($r['created_at'])) . '</td><td class="small">' . e((string)$r['who']) . '</td><td>' . e($labels[$r['action']] ?? (string)$r['action']) . '</td><td class="small muted">' . e((string)$r['subject']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
sm_layout_end();
