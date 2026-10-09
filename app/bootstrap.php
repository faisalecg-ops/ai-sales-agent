<?php
/**
 * Application bootstrap.
 * Included by every entry point (api, admin, review, cron).
 */

declare(strict_types=1);

if (defined('AISA_BOOTSTRAPPED')) {
    return;
}
define('AISA_BOOTSTRAPPED', true);

define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', APP_ROOT . '/app');
define('CONFIG_PATH', APP_ROOT . '/config');

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------
$configFile = CONFIG_PATH . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configuration missing. Copy config/config.sample.php to config/config.php and complete it.\n");
}
$AISA_CONFIG = require $configFile;

define('BASE_URL', rtrim((string)($AISA_CONFIG['base_url'] ?? ''), '/'));
define('STORAGE_PATH', (string)($AISA_CONFIG['storage_path'] ?? APP_ROOT . '/storage'));
define('APP_ENV', (string)($AISA_CONFIG['environment'] ?? 'production'));
define('APP_DEBUG', (bool)($AISA_CONFIG['debug'] ?? false));

// ---------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH, 0750, true);
}
foreach (['logs', 'uploads'] as $sub) {
    if (!is_dir(STORAGE_PATH . '/' . $sub)) {
        @mkdir(STORAGE_PATH . '/' . $sub, 0750, true);
    }
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

// ---------------------------------------------------------------------
// Autoloader (no Composer — simple PSR-4-ish map over app/)
// ---------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    // Files that deliberately hold a family of related classes, so the
    // interface and its implementations stay readable side by side.
    static $grouped = [
        'LlmProvider'            => 'domain/providers/LlmProviders.php',
        'BaseLlmProvider'        => 'domain/providers/LlmProviders.php',
        'LlmCatalogue'           => 'domain/providers/LlmProviders.php',
        'AnthropicLlmProvider'   => 'domain/providers/LlmProviders.php',
        'GeminiLlmProvider'      => 'domain/providers/LlmProviders.php',
        'OpenAiCompatibleProvider' => 'domain/providers/LlmProviders.php',
        'OpenAiLlmProvider'      => 'domain/providers/LlmProviders.php',
        'NullLlmProvider'        => 'domain/providers/LlmProviders.php',
        'LlmProviderFactory'     => 'domain/providers/LlmProviders.php',
        'GeoProvider'            => 'domain/providers/GeoProvider.php',
        'BaseGeoProvider'        => 'domain/providers/GeoProvider.php',
        'IpApiCoGeoProvider'     => 'domain/providers/GeoProviders.php',
        'IpInfoGeoProvider'      => 'domain/providers/GeoProviders.php',
        'IpWhoIsGeoProvider'     => 'domain/providers/GeoProviders.php',
        'NullGeoProvider'        => 'domain/providers/GeoProviders.php',
        'GeoProviderFactory'     => 'domain/providers/GeoProviders.php',
        'MessagingProvider'      => 'domain/providers/MessagingProviders.php',
        'BaseMessagingProvider'  => 'domain/providers/MessagingProviders.php',
        'MessagingCatalogue'     => 'domain/providers/MessagingProviders.php',
        'TextMeBotProvider'      => 'domain/providers/MessagingProviders.php',
        'NullMessagingProvider'  => 'domain/providers/MessagingProviders.php',
        'MessagingProviderFactory' => 'domain/providers/MessagingProviders.php',
        'ResearchProvider'       => 'domain/providers/ResearchProviders.php',
        'BaseResearchProvider'   => 'domain/providers/ResearchProviders.php',
        'ResearchCatalogue'      => 'domain/providers/ResearchProviders.php',
        'TavilyResearchProvider' => 'domain/providers/ResearchProviders.php',
        'NullResearchProvider'   => 'domain/providers/ResearchProviders.php',
        'ResearchProviderFactory' => 'domain/providers/ResearchProviders.php',
    ];

    if (isset($grouped[$class])) {
        $path = APP_PATH . '/' . $grouped[$class];
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }

    $dirs = [
        APP_PATH . '/lib/',
        APP_PATH . '/domain/',
        APP_PATH . '/domain/providers/',
        APP_PATH . '/domain/import/',
    ];
    $file = str_replace('\\', '/', $class) . '.php';
    foreach ($dirs as $dir) {
        $path = $dir . $file;
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

// ---------------------------------------------------------------------
// Global config accessor
// ---------------------------------------------------------------------
$GLOBALS['AISA_CONFIG'] = $AISA_CONFIG;

/**
 * Read a dotted key from the file configuration.
 */
function cfg(string $key, mixed $default = null): mixed
{
    $parts = explode('.', $key);
    $node  = $GLOBALS['AISA_CONFIG'];
    foreach ($parts as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

/**
 * Shorthand database accessor. Defined here rather than alongside the Db
 * class so it is always available, even before that class is autoloaded.
 */
function db(): Db
{
    return Db::get();
}

/**
 * HTML-escape for output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build a URL under the platform base.
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Write an application log line (never contains visitor message bodies).
 */
function app_log(string $channel, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] %s: %s %s\n",
        gmdate('Y-m-d H:i:s'),
        strtoupper($channel),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents(STORAGE_PATH . '/logs/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------
// Fatal error handling — never leak internals to a visitor
// ---------------------------------------------------------------------
set_exception_handler(static function (Throwable $ex): void {
    app_log('fatal', $ex->getMessage(), [
        'file' => basename($ex->getFile()),
        'line' => $ex->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (APP_DEBUG) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $ex->getMessage() . "\n" . $ex->getTraceAsString();
        return;
    }
    if (defined('AISA_JSON_ENDPOINT')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'server_error']);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<p style="font:16px/1.5 system-ui,sans-serif;padding:2rem">'
       . 'Something went wrong on our side. Try again, or contact your administrator if it keeps happening.</p>';
});
