<?php
/**
 * Event Blog — bootstrap.
 *
 * Included by every entry point: the public front controller, the admin,
 * the API, the installer and the cron script. Plain PHP 8 + MySQLi, built
 * for cPanel shared hosting: no framework, no Composer, no daemons.
 */

declare(strict_types=1);

if (defined('BLOG_BOOTSTRAPPED')) {
    return;
}
define('BLOG_BOOTSTRAPPED', true);
define('BLOG_VERSION', '1.0.0');
define('BLOG_ROOT', dirname(__DIR__));
define('BLOG_CORE', BLOG_ROOT . '/core');
define('BLOG_CONFIG_FILE', BLOG_ROOT . '/config/config.php');
define('BLOG_STORAGE', BLOG_ROOT . '/storage');
define('BLOG_UPLOADS', BLOG_ROOT . '/uploads');

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
error_reporting(E_ALL);

spl_autoload_register(static function (string $class): void {
    $file = BLOG_CORE . '/' . $class . '.php';
    if (preg_match('/^[A-Za-z0-9_]+$/', $class) && is_file($file)) {
        require $file;
    }
});

require BLOG_CORE . '/helpers.php';

/** True once the installer has written a configuration file. */
function blog_installed(): bool
{
    return is_file(BLOG_CONFIG_FILE);
}

$GLOBALS['BLOG_CONFIG'] = [];
if (blog_installed()) {
    $GLOBALS['BLOG_CONFIG'] = require BLOG_CONFIG_FILE;
}
// Tests point the blog at their own database without touching config.php.
if (isset($GLOBALS['BLOG_CONFIG_OVERRIDE']) && is_array($GLOBALS['BLOG_CONFIG_OVERRIDE'])) {
    $GLOBALS['BLOG_CONFIG'] = array_replace_recursive($GLOBALS['BLOG_CONFIG'], $GLOBALS['BLOG_CONFIG_OVERRIDE']);
}

$debug = (bool)cfg('debug', false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
foreach (['logs', 'cache', 'cache/pages', 'tmp'] as $sub) {
    if (!is_dir(BLOG_STORAGE . '/' . $sub)) {
        @mkdir(BLOG_STORAGE . '/' . $sub, 0750, true);
    }
}
ini_set('error_log', BLOG_STORAGE . '/logs/php-error.log');
