-- Event Blog — database tables. {p} is the table prefix chosen at install.

CREATE TABLE IF NOT EXISTS {p}settings (
  name   VARCHAR(64) NOT NULL,
  value  MEDIUMTEXT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','approver','editor','author') NOT NULL DEFAULT 'author',
  slug          VARCHAR(120) NOT NULL,
  title         VARCHAR(160) NOT NULL DEFAULT '',
  bio           TEXT NULL,
  avatar_id     INT UNSIGNED NULL,
  show_as_author TINYINT(1) NOT NULL DEFAULT 1,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  ip_hash    CHAR(64) NOT NULL,
  success    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_email (email, created_at),
  KEY idx_la_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}media (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  file         VARCHAR(190) NOT NULL,        -- path under uploads/, e.g. 2026/10/photo.jpg
  original_name VARCHAR(190) NOT NULL DEFAULT '',
  mime         VARCHAR(64) NOT NULL,
  width        INT UNSIGNED NOT NULL DEFAULT 0,
  height       INT UNSIGNED NOT NULL DEFAULT 0,
  bytes        INT UNSIGNED NOT NULL DEFAULT 0,
  sizes        TEXT NULL,                     -- JSON: width => {jpg, webp}
  alt          VARCHAR(255) NOT NULL DEFAULT '',
  caption      VARCHAR(500) NOT NULL DEFAULT '',
  credit       VARCHAR(255) NOT NULL DEFAULT '',
  uploaded_by  INT UNSIGNED NULL,
  api_key_id   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_media_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(120) NOT NULL,
  description TEXT NULL,
  seo_title   VARCHAR(190) NOT NULL DEFAULT '',
  sort        INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}tags (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(80) NOT NULL,
  slug  VARCHAR(90) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tag_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}articles (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(190) NOT NULL,
  category_id      INT UNSIGNED NULL,
  author_id        INT UNSIGNED NULL,
  excerpt          TEXT NULL,
  body             MEDIUMTEXT NOT NULL,
  image_id         INT UNSIGNED NULL,
  status           ENUM('draft','pending','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  publish_at       DATETIME NULL,              -- for scheduled articles (UTC)
  published_at     DATETIME NULL,
  seo_title        VARCHAR(190) NOT NULL DEFAULT '',
  meta_description VARCHAR(320) NOT NULL DEFAULT '',
  share_image_id   INT UNSIGNED NULL,
  is_featured      TINYINT(1) NOT NULL DEFAULT 0,
  featured_order   INT NOT NULL DEFAULT 0,
  lang             VARCHAR(8) NOT NULL DEFAULT 'en',
  reading_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  views            INT UNSIGNED NOT NULL DEFAULT 0,
  views_30d        INT UNSIGNED NOT NULL DEFAULT 0,
  source           ENUM('admin','api') NOT NULL DEFAULT 'admin',
  api_key_id       INT UNSIGNED NULL,
  external_ref     VARCHAR(120) NULL,           -- the social agent's own id for this article
  sources          TEXT NULL,                   -- JSON list of source addresses the writer used
  review_note      TEXT NULL,
  approved_by      INT UNSIGNED NULL,
  contributor_id   INT UNSIGNED NULL,           -- Industry Voices: the outside expert who wrote it
  sponsor_name     VARCHAR(160) NOT NULL DEFAULT '',  -- not empty = a sponsored article
  sponsor_label    ENUM('sponsored','partner') NOT NULL DEFAULT 'sponsored',
  sponsor_url      VARCHAR(500) NOT NULL DEFAULT '',
  sponsor_logo_id  INT UNSIGNED NULL,
  sponsor_starts   DATETIME NULL,               -- paid period (UTC)
  sponsor_ends     DATETIME NULL,
  sponsor_after    ENUM('keep','archive') NOT NULL DEFAULT 'keep',
  sponsor_email    VARCHAR(190) NOT NULL DEFAULT '',
  created_by       INT UNSIGNED NULL,
  updated_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_art_slug (slug),
  KEY idx_art_status (status, published_at),
  KEY idx_art_cat (category_id, status, published_at),
  KEY idx_art_author (author_id),
  KEY idx_art_featured (is_featured, featured_order),
  KEY idx_art_ext (api_key_id, external_ref),
  KEY idx_art_contrib (contributor_id, status),
  KEY idx_art_sponsor (sponsor_name),
  FULLTEXT KEY ft_art (title, excerpt, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}article_tags (
  article_id INT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (article_id, tag_id),
  KEY idx_at_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}article_revisions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id  INT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  excerpt     TEXT NULL,
  body        MEDIUMTEXT NOT NULL,
  status      VARCHAR(16) NOT NULL,
  note        VARCHAR(255) NOT NULL DEFAULT '',
  user_id     INT UNSIGNED NULL,
  api_key_id  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rev_article (article_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}redirects (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  old_path   VARCHAR(255) NOT NULL,      -- path inside the blog, e.g. machinery/old-title
  article_id INT UNSIGNED NULL,
  target     VARCHAR(255) NULL,          -- or a fixed path when not an article
  hits       INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redirect_path (old_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}slides (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  image_id     INT UNSIGNED NULL,
  headline     VARCHAR(200) NOT NULL DEFAULT '',
  text         VARCHAR(400) NOT NULL DEFAULT '',
  button_label VARCHAR(60) NOT NULL DEFAULT '',
  button_url   VARCHAR(500) NOT NULL DEFAULT '',
  starts_at    DATETIME NULL,
  ends_at      DATETIME NULL,
  sort         INT NOT NULL DEFAULT 0,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}banners (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sponsor_name VARCHAR(160) NOT NULL,
  title        VARCHAR(160) NOT NULL DEFAULT '',
  image_id     INT UNSIGNED NULL,
  mobile_image_id INT UNSIGNED NULL,
  link         VARCHAR(500) NOT NULL DEFAULT '',
  position     ENUM('top','middle','sidebar','footer','article') NOT NULL DEFAULT 'sidebar',
  starts_at    DATETIME NULL,
  ends_at      DATETIME NULL,
  sort         INT NOT NULL DEFAULT 0,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  contact_email VARCHAR(190) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_banner_pos (position, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}banner_stats (
  banner_id  INT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  views      INT UNSIGNED NOT NULL DEFAULT 0,
  clicks     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (banner_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}links (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label       VARCHAR(160) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  kind        VARCHAR(40) NOT NULL DEFAULT 'other',   -- register, stand, exhibitors, conference, sponsorship, venue, article, source, other
  keywords    VARCHAR(255) NOT NULL DEFAULT '',       -- words the editor's suggestions look for
  sponsored   TINYINT(1) NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}link_checks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  url         VARCHAR(500) NOT NULL,
  url_hash    CHAR(40) NOT NULL,
  status_code SMALLINT NOT NULL DEFAULT 0,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  error       VARCHAR(255) NOT NULL DEFAULT '',
  article_ids VARCHAR(500) NOT NULL DEFAULT '',
  checked_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lc_hash (url_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}comments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  body        TEXT NOT NULL,
  status      ENUM('pending','approved','spam','rejected') NOT NULL DEFAULT 'pending',
  ip_hash     CHAR(64) NOT NULL,
  moderated_by INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comment_article (article_id, status),
  KEY idx_comment_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}subscribers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL DEFAULT '',
  status        ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending',
  token         CHAR(43) NOT NULL,
  consent_text  VARCHAR(500) NOT NULL DEFAULT '',
  consent_page  VARCHAR(500) NOT NULL DEFAULT '',
  ip_hash       CHAR(64) NOT NULL DEFAULT '',
  confirmed_at  DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_email (email),
  UNIQUE KEY uq_sub_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}api_keys (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  key_prefix  CHAR(12) NOT NULL,
  key_hash    CHAR(64) NOT NULL,
  permission  ENUM('drafts','publish') NOT NULL DEFAULT 'drafts',
  active      TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}rate_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket      VARCHAR(80) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}webhook_outbox (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event       VARCHAR(40) NOT NULL,
  article_id  INT UNSIGNED NULL,
  payload     MEDIUMTEXT NOT NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error  VARCHAR(255) NOT NULL DEFAULT '',
  sent_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wh_status (status, next_try_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}mail_outbox (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email    VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body_text   MEDIUMTEXT NOT NULL,
  body_html   MEDIUMTEXT NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mail_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}activity (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  api_key_id  INT UNSIGNED NULL,
  action      VARCHAR(64) NOT NULL,
  subject     VARCHAR(255) NOT NULL DEFAULT '',
  details     TEXT NULL,
  ip_hash     CHAR(64) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_act_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}article_views (
  article_id INT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  views      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Industry Voices: outside experts who write for the blog (no login needed).
CREATE TABLE IF NOT EXISTS {p}contributors (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(120) NOT NULL,
  designation   VARCHAR(160) NOT NULL DEFAULT '',
  company       VARCHAR(160) NOT NULL DEFAULT '',
  company_url   VARCHAR(500) NOT NULL DEFAULT '',
  company_logo_id INT UNSIGNED NULL,
  photo_id      INT UNSIGNED NULL,
  linkedin_url  VARCHAR(500) NOT NULL DEFAULT '',
  bio           TEXT NULL,
  email         VARCHAR(190) NOT NULL DEFAULT '',   -- private, never shown
  phone         VARCHAR(40) NOT NULL DEFAULT '',    -- private, never shown
  user_id       INT UNSIGNED NULL,                  -- when they also have an author account
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_contrib_slug (slug),
  KEY idx_contrib_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Write for us": what outside experts send, waiting for an editor.
CREATE TABLE IF NOT EXISTS {p}submissions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40) NOT NULL DEFAULT '',
  designation   VARCHAR(160) NOT NULL DEFAULT '',
  company       VARCHAR(160) NOT NULL DEFAULT '',
  company_url   VARCHAR(500) NOT NULL DEFAULT '',
  linkedin_url  VARCHAR(500) NOT NULL DEFAULT '',
  kind          ENUM('outline','draft') NOT NULL DEFAULT 'outline',
  title         VARCHAR(255) NOT NULL,
  body          MEDIUMTEXT NOT NULL,
  status        ENUM('new','accepted','declined','spam') NOT NULL DEFAULT 'new',
  article_id    INT UNSIGNED NULL,
  contributor_id INT UNSIGNED NULL,
  handled_by    INT UNSIGNED NULL,
  consent_text  VARCHAR(500) NOT NULL DEFAULT '',
  ip_hash       CHAR(64) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clicks from sponsored articles to outside websites, per day (for the sponsor's report).
CREATE TABLE IF NOT EXISTS {p}article_clicks (
  article_id INT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  clicks     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (article_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
