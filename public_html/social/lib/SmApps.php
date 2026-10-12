<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Our own apps on Meta, LinkedIn and X — set once for the whole platform by a
 * super admin. Secrets are encrypted. Each exhibition then connects its own
 * pages to these apps ("Connect" on Blog and platforms).
 */
final class SmApps
{
    public const LABELS = [
        'meta'     => 'Meta (Facebook and Instagram)',
        'linkedin' => 'LinkedIn',
        'x'        => 'X',
    ];

    /** API versions; an admin can move them on when a platform retires one. */
    public const DEFAULT_GRAPH_VERSION = 'v26.0';
    public const DEFAULT_LINKEDIN_VERSION = '202609';

    /** @return array{id:string, secret:string} */
    public static function get(string $app): array
    {
        $raw = (string)db()->value('SELECT v FROM sm_meta WHERE k = ?', ['app_' . $app]);
        $d = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $secret = !empty($d['secret']) ? (string)(Crypto::decrypt((string)$d['secret']) ?? '') : '';
        return ['id' => (string)($d['id'] ?? ''), 'secret' => $secret];
    }

    public static function ready(string $app): bool
    {
        $a = self::get($app);
        return $a['id'] !== '' && $a['secret'] !== '';
    }

    public static function save(string $app, string $id, ?string $secret): void
    {
        $old = (string)db()->value('SELECT v FROM sm_meta WHERE k = ?', ['app_' . $app]);
        $d = $old !== '' ? (json_decode($old, true) ?: []) : [];
        $d['id'] = mb_substr(trim($id), 0, 190);
        if ($secret !== null && trim($secret) !== '') {
            $d['secret'] = Crypto::encrypt(trim($secret));
        }
        db()->exec('INSERT INTO sm_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['app_' . $app, json_encode($d)]);
    }

    public static function setting(string $key, string $default): string
    {
        $v = (string)db()->value('SELECT v FROM sm_meta WHERE k = ?', ['set_' . $key]);
        return $v !== '' ? $v : $default;
    }

    public static function saveSetting(string $key, string $value): void
    {
        db()->exec('INSERT INTO sm_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['set_' . $key, mb_substr($value, 0, 500)]);
    }

    public static function graphVersion(): string
    {
        $v = self::setting('graph_version', self::DEFAULT_GRAPH_VERSION);
        return preg_match('/^v\d{2,3}\.\d$/', $v) ? $v : self::DEFAULT_GRAPH_VERSION;
    }

    public static function linkedinVersion(): string
    {
        $v = self::setting('linkedin_version', self::DEFAULT_LINKEDIN_VERSION);
        return preg_match('/^20\d{4}$/', $v) ? $v : self::DEFAULT_LINKEDIN_VERSION;
    }

    /** The address each app must list as its redirect / callback URL. */
    public static function redirectUri(): string
    {
        return url('social/oauth.php');
    }

    /** Company named on the public privacy page. */
    public static function company(): string
    {
        return self::setting('company_name', 'Ecommerce Gateway Pakistan (Pvt) Ltd');
    }

    public static function contactEmail(): string
    {
        return self::setting('contact_email', '');
    }
}
