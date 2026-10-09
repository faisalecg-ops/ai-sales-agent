<?php
declare(strict_types=1);

/**
 * Creates and upgrades the blog's tables. Upgrades run automatically the
 * first time the admin is opened after a new package is uploaded.
 */
final class Schema
{
    /** Increase when a new step is added to upgrades(). */
    public const VERSION = 1;

    public static function install(): void
    {
        db()->script((string)file_get_contents(BLOG_CORE . '/schema.sql'));
        Settings::flush();
        Settings::set('schema_version', self::VERSION);
    }

    /** Bring an older installation up to date. Safe to run repeatedly. */
    public static function upgrade(): bool
    {
        $current = (int)Settings::get('schema_version', 0);
        if ($current >= self::VERSION) {
            return false;
        }
        // Tables are all CREATE IF NOT EXISTS, so new tables appear here.
        db()->script((string)file_get_contents(BLOG_CORE . '/schema.sql'));
        foreach (self::upgrades() as $version => $step) {
            if ($version > $current) {
                $step();
            }
        }
        Settings::set('schema_version', self::VERSION);
        return true;
    }

    /** @return array<int, callable> column changes for later versions */
    private static function upgrades(): array
    {
        return [
            1 => static function (): void {
            },
        ];
    }
}
