<?php
declare(strict_types=1);

/**
 * Housekeeping, run once an hour from cPanel's Cron Jobs.
 *
 *   php /home/USER/salesagent/cron/tasks.php
 *
 * It does two jobs: send anything still queued for email, and delete data
 * that has passed the retention period set in config.php (Section 42).
 * Everything here is safe to run more often than needed.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This task runs from the command line only.\n");
}

$startedAt = microtime(true);
$report    = [];

// ---------------------------------------------------------------------
// 0. Collect new visitor registrations
//
// Bounded per exhibition, so a large backlog is worked through over several
// runs rather than one long one that risks being cut off. Nothing here writes
// to the registration system; it only reads.
// ---------------------------------------------------------------------
if (RegistrationSource::isConfigured()) {
    foreach (db()->all("SELECT * FROM agents WHERE status = 'active'") as $cronAgent) {
        if (!RegistrationSource::eventIds((int)$cronAgent['id'])) {
            continue;   // no events mapped to this exhibition yet
        }
        try {
            $batch = RegistrationIntake::importBatch($cronAgent, 200);
            if ($batch['error'] !== null) {
                $report[] = (string)$cronAgent['name'] . ': registrations — ' . $batch['error'];
            } elseif ($batch['imported'] > 0) {
                $report[] = (string)$cronAgent['name'] . ': ' . $batch['imported'] . ' registration(s) collected';
            }
        } catch (Throwable $ex) {
            $report[] = (string)$cronAgent['name'] . ': registration collection failed';
            app_log('cron', 'registration intake failed', [
                'agent' => (int)$cronAgent['id'], 'error' => $ex->getMessage(),
            ]);
        }
    }
}

// ---------------------------------------------------------------------
// 0b. WhatsApp
//
// Also done here so a single hourly cron entry is enough to have a working
// platform. Adding cron/whatsapp.php every five minutes makes it prompter;
// running both is harmless.
// ---------------------------------------------------------------------
try {
    foreach (WhatsAppEngine::runAll(50, 25) as $line) {
        $report[] = $line;
    }
} catch (Throwable $ex) {
    app_log('cron', 'whatsapp run failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 0c. Company research waiting in the queue
// ---------------------------------------------------------------------
try {
    if (Migrations::tableExists('company_research')) {
        foreach (CompanyResearch::runQueued(3) as $line) {
            $report[] = $line;
        }
    }
} catch (Throwable $ex) {
    app_log('cron', 'research run failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 0d. The exhibition websites' pages (weekly, continued each run)
// ---------------------------------------------------------------------
try {
    if (SitePages::ready()) {
        foreach (db()->all("SELECT * FROM agents WHERE status = 'active'") as $siteAgent) {
            $line = SitePages::cron($siteAgent, 15);
            if ($line !== null) {
                echo $line, "\n";
            }
        }
    }
} catch (Throwable $ex) {
    app_log('cron', 'website reading failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 1. Send queued notifications
// ---------------------------------------------------------------------
try {
    $sent = Mailer::flush(50);
    $report[] = $sent . ' queued email(s) sent';
} catch (Throwable $ex) {
    $report[] = 'email sending failed: ' . $ex->getMessage();
    app_log('cron', 'mail flush failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 2. Retention (Section 42)
// ---------------------------------------------------------------------
$deleteOlderThan = static function (string $table, string $column, int $days, string $label) use (&$report): void {
    if ($days <= 0) {
        return;   // zero means keep indefinitely
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    try {
        $affected = db()->exec("DELETE FROM {$table} WHERE {$column} < ?", [$cutoff]);
        if ($affected > 0) {
            $report[] = $affected . ' ' . $label . ' removed';
        }
    } catch (Throwable $ex) {
        app_log('cron', 'retention failed', ['table' => $table, 'error' => $ex->getMessage()]);
    }
};

// Raw IP addresses are cleared from leads and lookups first, because they
// are the most sensitive thing stored and have the shortest life.
$ipDays = (int)cfg('retention.raw_ip_days', 365);
if ($ipDays > 0) {
    $cutoff = gmdate('Y-m-d', time());
    try {
        $cleared = db()->exec(
            'UPDATE leads SET first_seen_ip = NULL
             WHERE first_seen_ip IS NOT NULL AND ip_retained_until IS NOT NULL AND ip_retained_until < ?',
            [$cutoff]
        );
        if ($cleared > 0) {
            $report[] = $cleared . ' lead IP address(es) cleared';
        }
        $clearedGeo = db()->exec(
            'UPDATE geo_lookups SET ip = NULL
             WHERE ip IS NOT NULL AND retain_until IS NOT NULL AND retain_until < ?',
            [$cutoff]
        );
        if ($clearedGeo > 0) {
            $report[] = $clearedGeo . ' lookup IP address(es) cleared';
        }
    } catch (Throwable $ex) {
        app_log('cron', 'ip clearing failed', ['error' => $ex->getMessage()]);
    }
}

// Conversations, and their messages with them.
$conversationDays = (int)cfg('retention.conversation_days', 730);
if ($conversationDays > 0) {
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($conversationDays * 86400));
    try {
        $old = db()->all('SELECT id FROM conversations WHERE created_at < ? LIMIT 500', [$cutoff]);
        if ($old) {
            $ids   = array_map(static fn($r) => (int)$r['id'], $old);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db()->exec("DELETE FROM messages WHERE conversation_id IN ($marks)", $ids);
            db()->exec("DELETE FROM conversations WHERE id IN ($marks)", $ids);
            $report[] = count($ids) . ' old conversation(s) removed';
        }
    } catch (Throwable $ex) {
        app_log('cron', 'conversation retention failed', ['error' => $ex->getMessage()]);
    }
}

$deleteOlderThan('leads', 'created_at', (int)cfg('retention.lead_days', 0), 'old lead(s)');
$deleteOlderThan('audit_log', 'created_at', (int)cfg('retention.audit_days', 1095), 'audit entries');
$deleteOlderThan('request_log', 'created_at', (int)cfg('retention.request_log_days', 7), 'request records');
$deleteOlderThan('geo_lookups', 'created_at', (int)cfg('retention.conversation_days', 730), 'country lookups');
$deleteOlderThan('login_attempts', 'attempted_at', 1, 'sign-in attempts');

// Sent email older than a week, and rate-limit counters.
$deleteOlderThan('email_outbox', 'created_at', 7, 'delivered email records');
try {
    RateLimiter::prune((int)cfg('retention.rate_event_hours', 24));
} catch (Throwable $ex) {
    app_log('cron', 'rate prune failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 3. Old log files
// ---------------------------------------------------------------------
$logDays = (int)cfg('retention.error_log_days', 90);
if ($logDays > 0) {
    $cutoff = time() - ($logDays * 86400);
    foreach (glob(STORAGE_PATH . '/logs/*.log') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
            $report[] = 'removed old log ' . basename($file);
        }
    }
}

// ---------------------------------------------------------------------
// 4. Abandoned document imports
//
// An operator who closes the tab mid-import leaves uploaded brochures in
// storage. They are only needed while the run is being worked on, so a run
// left untouched for a week is closed and its files deleted.
// ---------------------------------------------------------------------
try {
    $stale = db()->all(
        "SELECT id, agent_id FROM import_runs
         WHERE status NOT IN ('published','cancelled')
           AND updated_at < ?
         LIMIT 50",
        [gmdate('Y-m-d H:i:s', time() - (7 * 86400))]
    );
    foreach ($stale as $run) {
        $removed = ImportRun::purgeFiles((int)$run['id']);
        db()->update('import_runs', [
            'status'       => 'cancelled',
            'stage_detail' => 'Abandoned, closed automatically after a week.',
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$run['id']]);
        $report[] = 'closed abandoned import ' . (int)$run['id']
                  . ($removed > 0 ? ' and deleted ' . $removed . ' file(s)' : '');
    }

    // Files belonging to runs that no longer exist at all.
    $dir = rtrim(STORAGE_PATH, '/') . '/uploads/imports';
    if (is_dir($dir)) {
        $orphans = 0;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (!is_file($file) || filemtime($file) > time() - 86400) {
                continue;
            }
            if (!preg_match('/^run(\d+)-/', basename($file), $m)) {
                continue;
            }
            $stillWanted = (int)db()->value(
                "SELECT COUNT(*) FROM import_runs
                 WHERE id = ? AND status NOT IN ('published','cancelled')",
                [(int)$m[1]]
            );
            if ($stillWanted === 0 && @unlink($file)) {
                $orphans++;
            }
        }
        if ($orphans > 0) {
            $report[] = $orphans . ' leftover import file(s) deleted';
        }
    }
} catch (Throwable $ex) {
    app_log('cron', 'import cleanup failed', ['error' => $ex->getMessage()]);
}

// ---------------------------------------------------------------------
// 5. Expired review links
// ---------------------------------------------------------------------
try {
    $closed = db()->exec(
        "UPDATE knowledge_review_tokens SET status = 'expired'
         WHERE status = 'active' AND expires_at < UTC_TIMESTAMP()"
    );
    if ($closed > 0) {
        $report[] = $closed . ' review link(s) expired';
    }
} catch (Throwable $ex) {
    app_log('cron', 'token expiry failed', ['error' => $ex->getMessage()]);
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

app_log('cron', 'housekeeping finished', ['summary' => $summary, 'ms' => $elapsed]);
echo gmdate('Y-m-d H:i:s') . ' UTC — ' . $summary . ' (' . $elapsed . " ms)\n";
