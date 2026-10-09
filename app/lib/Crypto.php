<?php
declare(strict_types=1);

/**
 * Symmetric encryption for secrets stored in the database.
 * The key lives in config/config.php, outside the webroot (Section 43).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $hex = (string)cfg('encryption_key', '');
        if (strlen($hex) < 64) {
            throw new RuntimeException('encryption_key_missing');
        }
        $key = @hex2bin(substr($hex, 0, 64));
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('encryption_key_invalid');
        }
        return $key;
    }

    public static function isConfigured(): bool
    {
        try {
            self::key();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('encrypt_failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($payload, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            return null;
        }
        $iv  = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $ct  = substr($bin, 28);
        try {
            $plain = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        } catch (Throwable) {
            return null;
        }
        return $plain === false ? null : $plain;
    }

    /** Generate a URL-safe random token. */
    public static function token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Constant-time hash used for tokens stored in the database. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
