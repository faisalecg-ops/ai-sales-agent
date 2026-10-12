<?php
declare(strict_types=1);

/**
 * Creates and upgrades the blog's tables. Upgrades run automatically the
 * first time the admin is opened after a new package is uploaded.
 */
final class Schema
{
    /** Increase when a new step is added to upgrades(). */
    public const VERSION = 3;

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

    private static function addColumn(string $table, string $column, string $definition): void
    {
        $t = db()->t($table);
        if (!db()->value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $column])) {
            db()->raw()->query("ALTER TABLE `$t` ADD COLUMN `$column` $definition");
        }
    }

    private static function addIndex(string $table, string $index, string $columns): void
    {
        $t = db()->t($table);
        if (!db()->value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $index])) {
            db()->raw()->query("ALTER TABLE `$t` ADD INDEX `$index` $columns");
        }
    }

    /** @return array<int, callable> column changes for later versions */
    private static function upgrades(): array
    {
        return [
            1 => static function (): void {
            },
            // 1.3.0: Industry Voices and sponsored articles.
            2 => static function (): void {
                $cols = [
                    'contributor_id'  => "INT UNSIGNED NULL AFTER approved_by",
                    'sponsor_name'    => "VARCHAR(160) NOT NULL DEFAULT '' AFTER contributor_id",
                    'sponsor_label'   => "ENUM('sponsored','partner') NOT NULL DEFAULT 'sponsored' AFTER sponsor_name",
                    'sponsor_url'     => "VARCHAR(500) NOT NULL DEFAULT '' AFTER sponsor_label",
                    'sponsor_logo_id' => "INT UNSIGNED NULL AFTER sponsor_url",
                    'sponsor_starts'  => "DATETIME NULL AFTER sponsor_logo_id",
                    'sponsor_ends'    => "DATETIME NULL AFTER sponsor_starts",
                    'sponsor_after'   => "ENUM('keep','archive') NOT NULL DEFAULT 'keep' AFTER sponsor_ends",
                    'sponsor_email'   => "VARCHAR(190) NOT NULL DEFAULT '' AFTER sponsor_after",
                ];
                foreach ($cols as $col => $def) {
                    self::addColumn('articles', $col, $def);
                }
                self::addIndex('articles', 'idx_art_contrib', '(contributor_id, status)');
                self::addIndex('articles', 'idx_art_sponsor', '(sponsor_name)');
                // New home page sections, switched on after "Latest articles" (they only show once there is something to show).
                $sections = (array)Settings::get('home_sections', []);
                $keys = array_column($sections, 'key');
                $out = [];
                foreach ($sections as $sec) {
                    $out[] = $sec;
                    if (($sec['key'] ?? '') === 'latest') {
                        foreach (['voices', 'partner'] as $k) {
                            if (!in_array($k, $keys, true)) {
                                $out[] = ['key' => $k, 'enabled' => true];
                            }
                        }
                    }
                }
                foreach (['voices', 'partner'] as $k) {
                    if (!in_array($k, array_column($out, 'key'), true)) {
                        $out[] = ['key' => $k, 'enabled' => true];
                    }
                }
                Settings::set('home_sections', $out);
            },
            // 1.4.0: visits from tagged links ({p}visit_sources, created by schema.sql above).
            3 => static function (): void {
            },
        ];
    }
}
