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
    return $cached = rtrim($url, '/');
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
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
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
