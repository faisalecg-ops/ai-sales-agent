<?php
declare(strict_types=1);

/**
 * The scheduled task. Run every 5 minutes from cPanel → Cron Jobs:
 *   /usr/local/bin/php /home/ACCOUNT/public_html/social/cron.php
 *
 * - scheduled posts whose time has come become "due" and the team is reminded;
 * - articles the blog is still holding are checked;
 * - articles left half-written are finished.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This runs from the server\'s scheduled tasks only.');
}

require __DIR__ . '/lib/boot.php';

$lock = fopen(STORAGE_PATH . '/social-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is still busy.\n";
    exit(0);
}

$due = SmFlow::dueNow();   // also posts automatically where a page is connected
$polled = SmFlow::pollBlog();
$resumed = SmFlow::resumeWriting();
$retried = SmFlow::retryApproved();
$found = SmFlow::syncAllBlogs();
$radar = SmRadar::runDue();
$exhibitors = SmExhibitors::refreshAll();
db()->exec("INSERT INTO sm_meta (k, v) VALUES ('last_cron', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [gmdate('Y-m-d H:i:s')]);
echo "$due post(s) became due; $polled article(s) checked with the blog; $resumed article(s) finished; $retried approved article(s) sent again; $found article(s) written in the blog picked up; $radar radar item(s); $exhibitors new exhibitor(s) from websites.\n";
flock($lock, LOCK_UN);
