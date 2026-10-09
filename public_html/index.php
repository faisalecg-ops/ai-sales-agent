<?php
declare(strict_types=1);

/**
 * The front door.
 *
 * Nothing is served from the web root itself, so without this file the web
 * server has nothing to return and answers 403 Forbidden. This file decides
 * where a person arriving at the root should go: to the installer if the
 * platform has not been set up, or to the admin panel if it has.
 *
 * It is deliberately careful about failure. Before config.php exists there is
 * no database and no bootstrap, so every check below has to work on its own
 * and produce a readable page rather than a blank screen or a PHP error.
 */

$root       = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$bootstrap  = $root . '/app/bootstrap.php';

/**
 * Logging that works even when the application never started.
 * Declared before it is used, because app_log() may not exist yet.
 */
function front_log(string $message, Throwable $ex): void
{
    if (function_exists('app_log')) {
        app_log('front', $message, ['error' => $ex->getMessage()]);
        return;
    }
    error_log('[ai-sales-agent] ' . $message . ': ' . $ex->getMessage());
}

/** A self-contained page, with no dependency on the application or its CSS. */
function front_page(string $title, string $body, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . $safeTitle . '</title><style>'
       . ':root{color-scheme:light}'
       . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F4F6F9;'
       . 'color:#0F2138;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
       . '.box{max-width:580px;width:100%;padding:20px;margin:20px}'
       . '.card{background:#fff;border:1px solid #DCE3EC;border-radius:8px;padding:26px 28px}'
       . 'p.eyebrow{margin:0 0 6px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;'
       . 'color:#7A8899;font-weight:700}'
       . 'h1{margin:0 0 14px;font-size:21px;line-height:1.3}'
       . 'p{margin:0 0 12px}p:last-child{margin-bottom:0}'
       . 'code{background:#F4F6F9;border:1px solid #DCE3EC;border-radius:3px;padding:1px 5px;'
       . 'font:13px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
       . 'ol{margin:10px 0 0;padding-left:22px}li{margin-bottom:7px}'
       . 'a{color:#1B4E8F}'
       . '</style></head><body><div class="box"><div class="card">'
       . '<p class="eyebrow">AI Sales Agent Platform</p>'
       . '<h1>' . $safeTitle . '</h1>'
       . $body
       . '</div></div></body></html>';
    exit;
}

// ---------------------------------------------------------------------
// The files are here, but nothing has been configured yet
// ---------------------------------------------------------------------
if (!is_file($configFile)) {
    front_page(
        'Almost there',
        '<p>The files are in the right place and the web server is reading them. '
        . 'The configuration file has not been created yet.</p>'
        . '<ol>'
        . '<li>In cPanel File Manager, open the <code>config</code> folder.</li>'
        . '<li>Copy <code>config.sample.php</code> to <code>config.php</code>, in that same folder.</li>'
        . '<li>Edit <code>config.php</code> and fill in the database details, the web address '
        . 'and the encryption key.</li>'
        . '<li>Reload this page.</li>'
        . '</ol>'
        . '<p>Part 5 of the deployment guide covers this step in full.</p>',
        503
    );
}

if (!is_file($bootstrap)) {
    front_page(
        'Some folders are in the wrong place',
        '<p>The <code>app</code> folder was not found next to <code>public_html</code>. '
        . 'This normally means the zip was extracted one level too deep.</p>'
        . '<p>The folders <code>app</code>, <code>config</code>, <code>storage</code> and '
        . '<code>public_html</code> all need to sit side by side.</p>',
        503
    );
}

// ---------------------------------------------------------------------
// Configured: start the application and work out where to send them
// ---------------------------------------------------------------------
try {
    require_once $bootstrap;
} catch (Throwable $ex) {
    front_log('bootstrap failed at the front door', $ex);
    front_page(
        'The platform could not start',
        '<p>The configuration file was found, but the application could not start with it.</p>'
        . '<p>The usual cause is a mistake in <code>config/config.php</code> — most often the '
        . 'database name, user or password. Remember that cPanel puts your account name at the '
        . 'front of both the database name and the database username, so both need the full '
        . 'prefixed version.</p>',
        503
    );
}

$installerPresent = is_file(__DIR__ . '/install/index.php');

try {
    $installed = (int)db()->value('SELECT COUNT(*) FROM admin_users WHERE active = 1') > 0;
} catch (Throwable $ex) {
    // Before the installer has run, the tables do not exist yet. That is the
    // expected state rather than an error, so send them to the installer.
    if ($installerPresent) {
        header('Location: ' . url('install/'));
        exit;
    }
    front_log('could not read the database', $ex);
    front_page(
        'Cannot reach the database',
        '<p>The application started, but the database could not be read.</p>'
        . '<p>Check the database name, user and password in <code>config/config.php</code>. '
        . 'cPanel puts your account name at the front of both the database name and the '
        . 'username, so both need the full prefixed version.</p>',
        503
    );
}

if (!$installed) {
    if ($installerPresent) {
        header('Location: ' . url('install/'));
        exit;
    }
    front_page(
        'No administrator account',
        '<p>The database is set up, but there is no active administrator account, and the '
        . '<code>public_html/install</code> folder has been removed.</p>'
        . '<p>Restore that folder from the original zip, reload this page to create an '
        . 'account, then delete it again.</p>',
        503
    );
}

// Set up and running. Nothing lives at the web root, so send people on to the
// place they were actually looking for.
header('Location: ' . url('admin/'));
exit;
