<?php
declare(strict_types=1);

/** Read a configuration value with dot notation, e.g. cfg('db.host'). */
function cfg(string $key, mixed $default = null): mixed
{
    $node = $GLOBALS['BLOG_CONFIG'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function db(): Db
{
    return Db::get();
}

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A translated interface string. All visible wording lives in lang/<code>.php. */
function t(string $key, array $vars = []): string
{
    $text = Lang::get($key);
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

/** Read a site setting (stored in the database, edited in the admin). */
function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/** The blog's public address without a trailing slash, e.g. https://buildasia.net/blog */
function base_url(): string
{
    static $cached = null;
    if ($cached !== null && !defined('BLOG_TESTING')) {
        return $cached;
    }
    $url = '';
    try {
        $url = (string)Settings::get('site_url', '');
    } catch (Throwable) {
        $url = '';
    }
    if ($url === '') {
        $url = (string)cfg('base_url', '');
    }
    // The address was saved as http:// but this visit is secure: use https,
    // otherwise browsers block the styles and scripts ("mixed content").
    if (request_is_https() && str_starts_with($url, 'http://')
        && strcasecmp((string)parse_url($url, PHP_URL_HOST), request_host()) === 0) {
        $url = 'https://' . substr($url, 7);
    }
    return $cached = rtrim($url, '/');
}

function request_is_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** The host name of this visit, without a port (empty when run from cron). */
function request_host(): string
{
    return strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
}

/**
 * The folder the blog is really installed in, as seen by this visit (e.g. "/blog").
 * Styles, scripts and fonts are linked through it, so they always load from the
 * same address the page was opened at — even if the Blog address setting is out
 * of date (http instead of https, review domain instead of the live one).
 */
function install_path(): string
{
    static $p = null;
    if ($p !== null && !defined('BLOG_TESTING')) {
        return $p;
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $file = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $file !== '' ? realpath(dirname($file)) : false;
    $root = realpath(BLOG_ROOT);
    if ($script === '' || PHP_SAPI === 'cli' || $real === false || $root === false || !str_starts_with($real, $root)) {
        return $p = base_path();
    }
    $inside = str_replace('\\', '/', substr($real, strlen($root)));      // e.g. "/admin" or ""
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');            // e.g. "/blog/admin"
    if ($inside !== '' && str_ends_with($dir, $inside)) {
        $dir = substr($dir, 0, -strlen($inside));
    }
    return $p = rtrim($dir, '/');
}

/** Address of a file inside the blog folder, relative to this website. */
function local_url(string $path): string
{
    return install_path() . '/' . ltrim($path, '/');
}

/** True when the Blog address setting does not match the address this visit used. */
function address_mismatch(): ?string
{
    $host = request_host();
    if ($host === '' || PHP_SAPI === 'cli') {
        return null;
    }
    $hostPort = strtolower((string)($_SERVER['HTTP_HOST'] ?? $host));
    $https = request_is_https();
    $hostPort = (string)preg_replace($https ? '/:443$/' : '/:80$/', '', $hostPort);
    $seen = ($https ? 'https://' : 'http://') . $hostPort . install_path();
    $saved = rtrim((string)(Settings::get('site_url', '') ?: cfg('base_url', '')), '/');
    return strcasecmp($seen, $saved) === 0 ? null : $seen;
}

/** The path part of the blog address, e.g. "/blog". */
function base_path(): string
{
    $path = (string)(parse_url(base_url(), PHP_URL_PATH) ?? '');
    return rtrim($path, '/');
}

/** Absolute address of a page inside the blog. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return base_url() . '/' . $path;
}

/** Address of a static file, with a version stamp so browsers refresh after an update. */
function asset(string $path): string
{
    $file = BLOG_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string)filemtime($file) : BLOG_VERSION;
    return local_url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** The site's own time zone (Pakistan by default). */
function site_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null || defined('BLOG_TESTING')) {
        $name = 'Asia/Karachi';
        try {
            $name = (string)Settings::get('timezone', 'Asia/Karachi');
        } catch (Throwable) {
        }
        try {
            $tz = new DateTimeZone($name);
        } catch (Throwable) {
            $tz = new DateTimeZone('Asia/Karachi');
        }
    }
    return $tz;
}

/** Format a stored UTC time in the site's time zone. */
function local_date(?string $utc, string $format = 'j F Y'): string
{
    if (!$utc) {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(site_tz())->format($format);
    } catch (Throwable) {
        return '';
    }
}

/** Convert a local date-time typed in the admin into stored UTC. */
function local_to_utc(?string $local): ?string
{
    $local = trim((string)$local);
    if ($local === '') {
        return null;
    }
    try {
        $d = new DateTimeImmutable(str_replace('T', ' ', $local), site_tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/** A stored UTC time as a value for <input type="datetime-local">. */
function utc_to_input(?string $utc): string
{
    return $utc ? local_date($utc, 'Y-m-d\TH:i') : '';
}

/** Lower-case, hyphenated, ASCII-only address part. */
function slugify(string $text, int $max = 80): string
{
    $text = trim($text);
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        if (is_string($t)) {
            $text = $t;
        }
    } else {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($t)) {
            $text = $t;
        }
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if (strlen($text) > $max) {
        $text = rtrim(substr($text, 0, $max), '-');
        $cut = strrpos($text, '-');
        if ($cut !== false && $cut > $max / 2) {
            $text = substr($text, 0, $cut);
        }
    }
    return $text;
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** A one-way fingerprint of the visitor's address: lets us limit abuse without storing it. */
function ip_hash(?string $ip = null): string
{
    return hash_hmac('sha256', $ip ?? client_ip(), app_key());
}

function app_key(): string
{
    $k = (string)cfg('app_key', '');
    return $k !== '' ? $k : 'blog-default-key-change-me';
}

function blog_log(string $channel, string $message, array $context = []): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $channel . ': ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    @file_put_contents(BLOG_STORAGE . '/logs/blog.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Plain text from HTML, with spacing kept between blocks. */
function plain_text(string $html): string
{
    $html = preg_replace('#<(br|/p|/h[1-6]|/li|/blockquote|/div)[^>]*>#i', "$0 ", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

/** Shorten text at a word boundary. */
function excerpt(string $text, int $max = 160): string
{
    $text = trim($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, " ,.;:-") . '…';
}

/** Is the URL an http(s) address? */
function is_web_url(string $url): bool
{
    return (bool)preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** Same host as the blog (or the main event site)? */
function is_internal_url(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $own  = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
    if ($host === '') {
        return true;
    }
    return $host === $own || preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $own);
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
