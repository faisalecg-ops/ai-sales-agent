<?php
declare(strict_types=1);

/**
 * Security primitives shared by the admin panel, review pages and public API.
 */
final class Security
{
    // -----------------------------------------------------------------
    // Response headers (Section 43)
    // -----------------------------------------------------------------

    /** Headers for admin/review HTML pages. */
    public static function htmlHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; form-action 'self'"
        );
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Headers for the public JSON API. */
    public static function apiHeaders(?string $allowOrigin = null): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
        if ($allowOrigin !== null && $allowOrigin !== '') {
            header('Access-Control-Allow-Origin: ' . $allowOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    // -----------------------------------------------------------------
    // Sessions (Section 42)
    // -----------------------------------------------------------------

    public const IDLE_TIMEOUT     = 3600;      // 1 hour of inactivity
    public const ABSOLUTE_TIMEOUT = 43200;     // 12 hours maximum

    public static function startSession(string $name = 'aisa_admin'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        if (isset($_SESSION['_started_at'])) {
            $idleExpired = ($now - (int)($_SESSION['_last_seen'] ?? $now)) > self::IDLE_TIMEOUT;
            $absExpired  = ($now - (int)$_SESSION['_started_at']) > self::ABSOLUTE_TIMEOUT;
            if ($idleExpired || $absExpired) {
                self::destroySession();
                session_start();
                $_SESSION['_timeout'] = $absExpired ? 'absolute' : 'idle';
            }
        }
        $_SESSION['_started_at'] ??= $now;
        $_SESSION['_last_seen'] = $now;
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    // -----------------------------------------------------------------
    // CSRF (Section 42, 43)
    // -----------------------------------------------------------------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Crypto::token(24);
        }
        return (string)$_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    public static function checkCsrf(): bool
    {
        $sent = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $have = (string)($_SESSION['_csrf'] ?? '');
        return $have !== '' && $sent !== '' && hash_equals($have, $sent);
    }

    /** Abort a POST that fails CSRF validation. */
    public static function requireCsrf(): void
    {
        if (!self::checkCsrf()) {
            http_response_code(419);
            header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta charset="utf-8"><p style="font:16px system-ui;padding:2rem">'
               . 'Your session expired before this form was submitted. Go back, reload the page and try again.</p>');
        }
    }

    // -----------------------------------------------------------------
    // Request context
    // -----------------------------------------------------------------

    /**
     * Resolve the visitor's public IP.
     * Proxy headers are only trusted when the platform is configured
     * to sit behind a known proxy (Cloudflare etc.).
     */
    public static function clientIp(): string
    {
        $trustProxy = Settings::get('security.trust_proxy', '0') === '1';
        if ($trustProxy) {
            $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
            $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                foreach (explode(',', $xff) as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        return $candidate;
                    }
                }
            }
        }
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /** True for private/reserved addresses (Section 13 — undetermined during testing). */
    public static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function userAgent(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    }

    /**
     * The Origin header itself (scheme://host[:port]), validated and
     * normalised, or null. Used only to answer a CORS pre-flight; the
     * request that follows is still authorised on its own merits.
     */
    public static function originHeader(): ?string
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '' || strlen($origin) > 255) {
            return null;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        // An Origin carries nothing but scheme, host and port. Anything else
        // present means the header was malformed or deliberately crafted, so
        // it is refused rather than tidied up and echoed back.
        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $unexpected) {
            if (isset($parts[$unexpected]) && (string)$parts[$unexpected] !== '') {
                return null;
            }
        }
        $host = strtolower((string)$parts['host']);
        // Hostname, or a bracketed IPv6 literal. Nothing else is echoed.
        $isHost = preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) === 1;
        $isIpv6 = preg_match('/^\[[0-9a-f:.]+\]$/', $host) === 1;
        if (!$isHost && !$isIpv6) {
            return null;
        }
        // Rebuilt from its parts so nothing but a bare origin is ever echoed
        // back into a response header.
        $out = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $out .= ':' . (int)$parts['port'];
        }
        return $out;
    }

    /** Hostname of the Origin header, or null. */
    public static function originHost(): ?string
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return null;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : null;
    }

    /** Hostname of the Referer header — supplementary only (Section 40). */
    public static function refererHost(): ?string
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') {
            return null;
        }
        $host = parse_url($ref, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : null;
    }

    // -----------------------------------------------------------------
    // Sign-in throttling (Section 38)
    // -----------------------------------------------------------------

    /** Attempts allowed before a username or address is made to wait. */
    private const LOGIN_MAX_ATTEMPTS = 6;
    private const LOGIN_WINDOW_SECONDS = 900;

    /**
     * Both the username and the address are throttled, so neither guessing
     * one password many times nor many passwords from one machine works.
     */
    public static function loginAllowed(string $username, string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOGIN_WINDOW_SECONDS);

        $byUser = (int)db()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND successful = 0 AND attempted_at > ?',
            [mb_substr($username, 0, 64), $since]
        );
        if ($byUser >= self::LOGIN_MAX_ATTEMPTS) {
            return false;
        }

        $byIp = (int)db()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND successful = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        return $byIp < (self::LOGIN_MAX_ATTEMPTS * 3);
    }

    public static function recordLogin(string $username, string $ip, bool $success): void
    {
        try {
            db()->insert('login_attempts', [
                'identifier' => mb_substr($username, 0, 64),
                'ip'         => $ip,
                'successful' => $success ? 1 : 0,
            ]);
            if ($success) {
                // A good sign-in clears the slate for that username.
                db()->exec('DELETE FROM login_attempts WHERE identifier = ? AND successful = 0',
                    [mb_substr($username, 0, 64)]);
            }
            if (random_int(1, 25) === 1) {
                db()->exec('DELETE FROM login_attempts WHERE attempted_at < ?',
                    [gmdate('Y-m-d H:i:s', time() - 86400)]);
            }
        } catch (Throwable $ex) {
            app_log('security', 'could not record sign-in attempt', ['error' => $ex->getMessage()]);
        }
    }
}
