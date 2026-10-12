<?php
/**
 * Event Blog — scheduled tasks. Run by a cPanel cron job every 5 minutes:
 *   /usr/local/bin/php /home/ACCOUNT/public_html/blog/cron.php
 *
 * Every 5 minutes: publish scheduled articles, send "article published"
 *                  notices to the Social Media Agent, send queued emails.
 * Once a day:      refresh event facts from the sales agent, update the
 *                  "most read" figures, tidy old records.
 * Through the week: check links a few at a time (each link once a week).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from cron only.');
}
require __DIR__ . '/core/bootstrap.php';
if (!blog_installed()) {
    exit("Not installed.\n");
}

// One run at a time.
$lock = fopen(BLOG_STORAGE . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Already running.\n");
}

$report = [];
$step = static function (string $name, callable $fn) use (&$report): void {
    try {
        $report[] = $name . ': ' . $fn();
    } catch (Throwable $ex) {
        blog_log('cron', $name . ' failed', ['error' => $ex->getMessage()]);
        $report[] = $name . ': FAILED (' . $ex->getMessage() . ')';
    }
};

Schema::upgrade();
$step('scheduled articles published', static fn() => Article::publishDue());
$step('sponsorships ended (taken off)', static fn() => Sponsored::endDue());
$step('notices sent to the social agent', static fn() => Webhook::flush(20));
$step('emails sent', static fn() => Mailer::flush(20));

$today = gmdate('Y-m-d');
if ((string)Settings::get('cron_daily', '') !== $today) {
    Settings::set('cron_daily', $today);
    $step('event facts', static function () {
        if (!Settings::get('chat_agent', '')) {
            return 'not connected';
        }
        $r = Event::refresh();
        return $r['ok'] ? 'updated' : $r['error'];
    });
    $step('most read', static function () {
        Article::refreshMostRead();
        return 'updated';
    });
    $step('tidy', static function () {
        RateLimit::prune();
        db()->delete('login_attempts', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        db()->delete('webhook_outbox', "status = 'sent' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        db()->delete('mail_outbox', "status = 'sent' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        db()->delete('activity', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - 365 * 86400)]);
        db()->delete('subscribers', "status = 'pending' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        foreach (glob(BLOG_STORAGE . '/tmp/*') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return 'done';
    });
    $step('links collected', static fn() => LinkCheck::collect());
}
$step('links checked', static function () {
    $n = LinkCheck::run(8);
    if ($n) {
        Settings::set('last_link_check', now_utc());
    }
    return $n;
});

Settings::set('last_cron', now_utc());
flock($lock, LOCK_UN);
echo implode("\n", $report) . "\n";
