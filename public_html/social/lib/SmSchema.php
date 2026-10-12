<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * /social's own tables, all named sm_… in the sales agent's database.
 *
 * The sales agent never touches them, and /social never creates, changes or
 * drops anything that is not sm_…. Every step is safe to run twice; the
 * version number only saves work on ordinary page loads.
 */
final class SmSchema
{
    public const VERSION = 4;

    public static function ensure(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $v = (int)db()->value("SELECT v FROM sm_meta WHERE k = 'schema_version'");
        } catch (Throwable) {
            $v = 0;
        }
        if ($v >= self::VERSION) {
            return;
        }
        foreach (self::statements() as $sql) {
            db()->raw()->query($sql);
        }
        // Version 2 (12 Oct 2026): articles written in the blog itself, and the blog's waiting list.
        self::addColumn('sm_articles', 'origin', "ENUM('agent','blog') NOT NULL DEFAULT 'agent' AFTER agent_id");
        self::addColumn('sm_events', 'blog_seen_at', 'DATETIME NULL');
        self::addColumn('sm_events', 'blog_seen_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('sm_events', 'blog_pending', 'MEDIUMTEXT NULL');
        self::addColumn('sm_events', 'blog_pending_at', 'DATETIME NULL');
        // Version 3 (12 Oct 2026): who wrote it (Industry Voices) and who paid for it (sponsored), from the blog.
        self::addColumn('sm_articles', 'voice', 'TEXT NULL');
        self::addColumn('sm_articles', 'sponsor', 'TEXT NULL');
        // Version 4 (12 Oct 2026, Stage 3): automatic posting, exhibitor kits, radar and curated news.
        self::addColumn('sm_posts', 'link_url', 'VARCHAR(500) NULL');
        self::addColumn('sm_posts', 'remote_id', 'VARCHAR(190) NULL');
        self::addColumn('sm_posts', 'attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
        self::addColumn('sm_posts', 'next_try_at', 'DATETIME NULL');
        self::addColumn('sm_posts', 'posting_since', 'DATETIME NULL');
        self::addColumn('sm_posts', 'posted_auto', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumn('sm_topics', 'radar_kind', 'VARCHAR(16) NULL');
        self::addColumn('sm_topics', 'source_name', 'VARCHAR(160) NULL');
        self::addColumn('sm_events', 'radar_ran_at', 'DATETIME NULL');
        self::addColumn('sm_events', 'exhibitors_checked_at', 'DATETIME NULL');
        if (!db()->value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sm_articles' AND index_name = 'uq_sm_blog_article'")) {
            try {
                db()->raw()->query('ALTER TABLE sm_articles ADD UNIQUE KEY uq_sm_blog_article (agent_id, blog_article_id)');
            } catch (Throwable) {
                // Old duplicate rows: importFromBlog() also checks before adding, so carry on without the key.
            }
        }
        db()->exec("INSERT INTO sm_meta (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string)self::VERSION]);
    }

    private static function addColumn(string $table, string $column, string $definition): void
    {
        $have = (int)db()->value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
        if ($have === 0) {
            db()->raw()->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return ['sm_meta', 'sm_events', 'sm_platforms', 'sm_topics', 'sm_articles', 'sm_posts', 'sm_media', 'sm_activity', 'sm_ai_usage',
            'sm_connections', 'sm_exhibitors', 'sm_kit_clicks'];
    }

    /** @return list<string> */
    private static function statements(): array
    {
        $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS sm_meta (
               k VARCHAR(64) NOT NULL, v TEXT NULL, PRIMARY KEY (k))$t",

            // One row per exhibition that uses /social. agent_id = agents.id.
            "CREATE TABLE IF NOT EXISTS sm_events (
               agent_id INT UNSIGNED NOT NULL,
               settings MEDIUMTEXT NULL,
               blog_url VARCHAR(255) NULL,
               blog_api_key TEXT NULL,
               blog_hook_secret TEXT NULL,
               blog_checked_at DATETIME NULL,
               blog_status VARCHAR(255) NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (agent_id))$t",

            "CREATE TABLE IF NOT EXISTS sm_platforms (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               platform ENUM('linkedin','facebook','instagram','x') NOT NULL,
               enabled TINYINT(1) NOT NULL DEFAULT 0,
               mode ENUM('manual','direct') NOT NULL DEFAULT 'manual',
               page_url VARCHAR(255) NULL,
               bio_url VARCHAR(255) NULL,
               posts_per_week TINYINT UNSIGNED NOT NULL DEFAULT 3,
               default_time CHAR(5) NOT NULL DEFAULT '11:00',
               hashtags VARCHAR(500) NULL,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id), UNIQUE KEY uq_sm_platform (agent_id, platform))$t",

            "CREATE TABLE IF NOT EXISTS sm_topics (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               pillar VARCHAR(32) NOT NULL,
               title VARCHAR(255) NOT NULL,
               angle TEXT NULL,
               origin ENUM('plan','radar','knowledge','typed') NOT NULL DEFAULT 'plan',
               source_urls TEXT NULL,
               status ENUM('suggested','picked','used','dismissed') NOT NULL DEFAULT 'suggested',
               created_by INT UNSIGNED NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_topics (agent_id, status))$t",

            "CREATE TABLE IF NOT EXISTS sm_articles (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               topic_id INT UNSIGNED NULL,
               pillar VARCHAR(32) NOT NULL,
               title VARCHAR(255) NOT NULL,
               excerpt VARCHAR(400) NULL,
               body MEDIUMTEXT NULL,
               category VARCHAR(120) NULL,
               tags VARCHAR(500) NULL,
               sources MEDIUMTEXT NULL,
               fact_check MEDIUMTEXT NULL,
               checks MEDIUMTEXT NULL,
               status ENUM('writing','draft','in_review','approved','sent','published','rejected','failed') NOT NULL DEFAULT 'writing',
               review_note TEXT NULL,
               cover_media_id INT UNSIGNED NULL,
               blog_article_id INT UNSIGNED NULL,
               blog_status VARCHAR(32) NULL,
               blog_url VARCHAR(500) NULL,
               written_by INT UNSIGNED NULL,
               approved_by INT UNSIGNED NULL,
               approved_at DATETIME NULL,
               approved_hash CHAR(40) NULL,
               edited_by INT UNSIGNED NULL,
               published_at DATETIME NULL,
               error TEXT NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_articles (agent_id, status))$t",

            "CREATE TABLE IF NOT EXISTS sm_posts (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               article_id INT UNSIGNED NULL,
               platform ENUM('linkedin','facebook','instagram','x') NOT NULL,
               body TEXT NOT NULL,
               hashtags VARCHAR(500) NULL,
               include_link TINYINT(1) NOT NULL DEFAULT 1,
               media_id INT UNSIGNED NULL,
               status ENUM('draft','in_review','approved','scheduled','due','posted','failed','cancelled') NOT NULL DEFAULT 'draft',
               scheduled_at DATETIME NULL,
               reminder_sent_at DATETIME NULL,
               posted_at DATETIME NULL,
               posted_url VARCHAR(500) NULL,
               posted_by INT UNSIGNED NULL,
               written_by INT UNSIGNED NULL,
               edited_by INT UNSIGNED NULL,
               approved_by INT UNSIGNED NULL,
               fail_reason TEXT NULL,
               review_note TEXT NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_posts (agent_id, status), KEY idx_sm_posts_due (status, scheduled_at),
               KEY idx_sm_posts_article (article_id))$t",

            "CREATE TABLE IF NOT EXISTS sm_media (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               kind ENUM('image','video') NOT NULL DEFAULT 'image',
               origin ENUM('upload','built','ai') NOT NULL DEFAULT 'upload',
               file VARCHAR(255) NOT NULL,
               mime VARCHAR(64) NOT NULL,
               width SMALLINT UNSIGNED NULL,
               height SMALLINT UNSIGNED NULL,
               bytes INT UNSIGNED NOT NULL DEFAULT 0,
               label VARCHAR(255) NULL,
               created_by INT UNSIGNED NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_media (agent_id))$t",

            "CREATE TABLE IF NOT EXISTS sm_activity (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NULL,
               user_id INT UNSIGNED NULL,
               who VARCHAR(160) NOT NULL DEFAULT 'system',
               action VARCHAR(64) NOT NULL,
               subject VARCHAR(255) NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_activity (agent_id, created_at))$t",

            // A connected social account for automatic posting (tokens encrypted).
            "CREATE TABLE IF NOT EXISTS sm_connections (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               platform ENUM('linkedin','facebook','instagram','x') NOT NULL,
               account_id VARCHAR(190) NOT NULL,
               account_name VARCHAR(255) NULL,
               account_url VARCHAR(500) NULL,
               token TEXT NOT NULL,
               refresh_token TEXT NULL,
               expires_at DATETIME NULL,
               extra TEXT NULL,
               status ENUM('ok','expired','error') NOT NULL DEFAULT 'ok',
               last_error VARCHAR(500) NULL,
               connected_by INT UNSIGNED NULL,
               connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id), UNIQUE KEY uq_sm_conn (agent_id, platform))$t",

            // Exhibitors of an event and their share kits.
            "CREATE TABLE IF NOT EXISTS sm_exhibitors (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               company VARCHAR(200) NOT NULL,
               company_key VARCHAR(200) NOT NULL,
               stand VARCHAR(60) NULL,
               hall VARCHAR(60) NULL,
               website VARCHAR(500) NULL,
               email VARCHAR(190) NULL,
               contact_name VARCHAR(160) NULL,
               logo_media_id INT UNSIGNED NULL,
               source ENUM('website','typed','upload') NOT NULL DEFAULT 'typed',
               kit_token CHAR(40) NULL,
               kit_status ENUM('none','ready','approved','sent') NOT NULL DEFAULT 'none',
               kit_texts MEDIUMTEXT NULL,
               kit_media_wide INT UNSIGNED NULL,
               kit_media_square INT UNSIGNED NULL,
               kit_approved_by INT UNSIGNED NULL,
               kit_sent_at DATETIME NULL,
               kit_opened_at DATETIME NULL,
               last_error VARCHAR(500) NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id), UNIQUE KEY uq_sm_exh (agent_id, company_key), UNIQUE KEY uq_sm_exh_token (kit_token))$t",

            "CREATE TABLE IF NOT EXISTS sm_kit_clicks (
               exhibitor_id INT UNSIGNED NOT NULL,
               day DATE NOT NULL,
               clicks INT UNSIGNED NOT NULL DEFAULT 0,
               PRIMARY KEY (exhibitor_id, day))$t",

            "CREATE TABLE IF NOT EXISTS sm_ai_usage (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               agent_id INT UNSIGNED NOT NULL,
               kind ENUM('text','image','research') NOT NULL,
               purpose VARCHAR(48) NOT NULL,
               model VARCHAR(96) NULL,
               input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
               output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
               ok TINYINT(1) NOT NULL DEFAULT 1,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id), KEY idx_sm_ai_usage (agent_id, kind, created_at))$t",
        ];
    }
}
