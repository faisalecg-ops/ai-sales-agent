<?php
declare(strict_types=1);

/**
 * Platform-wide settings. Secret values are encrypted at rest and are
 * never returned to the admin UI after saving (Section 43).
 */
final class Settings
{
    private static array $cache = [];

    /** Keys treated as secrets: encrypted, never displayed back. */
    public const SECRET_KEYS = [
        'geo.ipinfo.token',
        'geo.ipgeolocation.key',
        'geo.maxmind.key',
        'mail.smtp.password',
        'regsource.db_pass',
        'whatsapp.webhook_secret',
    ];

    /**
     * Anything holding a credential is encrypted at rest and never shown back.
     * The AI provider keys come from the catalogue rather than being listed
     * here, so adding a provider cannot accidentally leave its key in clear.
     */
    public static function isSecret(string $key): bool
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            return true;
        }
        if (str_starts_with($key, 'llm.') && str_ends_with($key, '.api_key')) {
            return true;
        }
        if (in_array($key, LlmCatalogue::secretKeys(), true)) {
            return true;
        }
        if (in_array($key, ResearchCatalogue::secretKeys(), true)) {
            return true;
        }
        return in_array($key, MessagingCatalogue::secretKeys(), true);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $row = db()->one('SELECT setting_value, is_secret FROM settings WHERE setting_key = ?', [$key]);
        if (!$row) {
            return self::$cache[$key] = $default;
        }
        $val = (string)($row['setting_value'] ?? '');
        if ((int)$row['is_secret'] === 1 && $val !== '') {
            $val = Crypto::decrypt($val) ?? '';
        }
        return self::$cache[$key] = ($val === '' ? $default : $val);
    }

    public static function set(string $key, ?string $value): void
    {
        $secret = self::isSecret($key);
        $stored = (string)$value;
        if ($secret && $stored !== '') {
            $stored = Crypto::encrypt($stored);
        }
        db()->exec(
            'INSERT INTO settings (setting_key, setting_value, is_secret) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)',
            [$key, $stored, $secret ? 1 : 0]
        );
        unset(self::$cache[$key]);
    }

    /** True when a secret exists, without revealing it. */
    public static function hasValue(string $key): bool
    {
        $v = db()->value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        return is_string($v) && $v !== '';
    }

    public static function forget(string $key): void
    {
        db()->exec('DELETE FROM settings WHERE setting_key = ?', [$key]);
        unset(self::$cache[$key]);
    }

    /** Non-secret settings only, for rendering admin forms. */
    public static function allPublic(): array
    {
        $out = [];
        foreach (db()->all('SELECT setting_key, setting_value FROM settings WHERE is_secret = 0') as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }
}
