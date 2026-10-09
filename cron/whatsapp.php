<?php
declare(strict_types=1);

/**
 * WhatsApp sending, run every five minutes from cPanel's Cron Jobs.
 *
 *   php /home/USER/salesagent/cron/whatsapp.php
 *
 * Separate from tasks.php because it wants to run far more often. Retention
 * and log tidying are happy once an hour; a message waiting to go out is not,
 * and neither is someone who has just replied.
 *
 * Safe to run as often as you like. It sends only what is due, only inside
 * the sending hours you set for each exhibition, and only up to that
 * exhibition's daily limit. Two copies running at once do not double up: a
 * message is claimed before it is sent, and only one run can claim it.
 *
 * If you would rather have a single cron entry, leave this out — tasks.php
 * does the same work once an hour.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This task runs from the command line only.\n");
}

$startedAt = microtime(true);

$report = WhatsAppEngine::runAll(
    (int)cfg('whatsapp.queue_per_run', 50),
    (int)cfg('whatsapp.send_per_run', 25)
);

// Company research waiting in the queue — a few each run, so a run stays short.
try {
    if (Migrations::tableExists('company_research')) {
        foreach (CompanyResearch::runQueued((int)cfg('research.per_run', 2)) as $line) {
            $report[] = $line;
        }
    }
} catch (Throwable $ex) {
    app_log('cron', 'research run failed', ['error' => $ex->getMessage()]);
}

// Prospecting: find, research, draft and send — each step paced by the
// exhibition's own settings, so running this often does not send faster.
try {
    foreach (Prospecting::runAll() as $line) {
        $report[] = $line;
    }
} catch (Throwable $ex) {
    app_log('cron', 'prospecting run failed', ['error' => $ex->getMessage()]);
}

$elapsed = round((microtime(true) - $startedAt) * 1000);
$summary = $report ? implode('; ', $report) : 'nothing to do';

app_log('cron', 'whatsapp run finished', ['summary' => $summary, 'ms' => $elapsed]);
echo gmdate('Y-m-d H:i:s') . ' UTC — ' . $summary . ' (' . $elapsed . " ms)\n";
