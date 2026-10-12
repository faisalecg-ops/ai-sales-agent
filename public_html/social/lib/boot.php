<?php
declare(strict_types=1);

/**
 * Loads the sales agent's application (read-only use: database, settings,
 * keys, Access) and /social's own classes. Used by pages, cron and the
 * blog's notice address.
 */

if (!defined('SM_BOOT')) {
    define('SM_BOOT', true);
    define('SM_ROOT', dirname(__DIR__));
    define('SM_VERSION', '1.4.0');

    require_once dirname(SM_ROOT, 2) . '/app/bootstrap.php';

    spl_autoload_register(static function (string $class): void {
        if (preg_match('/^Sm[A-Z][A-Za-z]+$/', $class) === 1) {
            $file = SM_ROOT . '/lib/' . $class . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    if (!function_exists('sm_url')) {
        function sm_url(string $page, array $query = []): string
        {
            return url('social/' . $page) . ($query ? '?' . http_build_query($query) : '');
        }
    }

    SmSchema::ensure();
}
