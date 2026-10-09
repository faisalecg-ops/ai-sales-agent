<?php
declare(strict_types=1);

/**
 * Saved copies of public pages, so most visitors are served a ready-made
 * page without touching the database — important for speed (and Google
 * ranking) on shared hosting. Cleared whenever anything changes in the admin.
 */
final class Cache
{
    private const TTL = 900;   // 15 minutes at most, even without changes

    private static function dir(): string
    {
        return BLOG_STORAGE . '/cache/pages';
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha1', $key) . '.html';
    }

    public static function enabled(): bool
    {
        return !defined('BLOG_NO_CACHE') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
    }

    public static function get(string $key): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $f = self::file($key);
        if (!is_file($f) || filemtime($f) < time() - self::TTL) {
            return null;
        }
        $html = @file_get_contents($f);
        return $html === false ? null : $html;
    }

    public static function put(string $key, string $html): void
    {
        if (!self::enabled()) {
            return;
        }
        $tmp = self::file($key) . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, self::file($key));
        }
    }

    public static function clear(): void
    {
        foreach (glob(self::dir() . '/*.html') ?: [] as $f) {
            @unlink($f);
        }
    }
}
