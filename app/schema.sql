-- =====================================================================
-- AI Sales Agent Platform — Database Schema
-- Spec v2.3  |  MySQL 5.7+ / MariaDB 10.3+  |  InnoDB, utf8mb4
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- agents  (Section 35, 36, 38)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agents (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                 VARCHAR(64)  NOT NULL,          -- public lookup key, never authorization
  name                 VARCHAR(160) NOT NULL,
  status               ENUM('active','disabled','deleted') NOT NULL DEFAULT 'active',
  website_url          VARCHAR(255) NULL,
  allowed_domains      TEXT NULL,                       -- newline separated hostnames
  persona              MEDIUMTEXT NULL,
  sales_rules          MEDIUMTEXT NULL,
  system_prompt_extra  MEDIUMTEXT NULL,
  sales_objectives     MEDIUMTEXT NULL,

  -- LLM configuration (Section 45)
  llm_provider         VARCHAR(32)  NOT NULL DEFAULT 'anthropic',
  llm_model            VARCHAR(96)  NOT NULL DEFAULT 'claude-sonnet-4-5',
  llm_temperature      DECIMAL(3,2) NOT NULL DEFAULT 0.30,
  llm_max_tokens       SMALLINT UNSIGNED NOT NULL DEFAULT 700,
  llm_timeout_seconds  SMALLINT UNSIGNED NOT NULL DEFAULT 25,
  llm_fallback_provider VARCHAR(32) NULL,
  llm_fallback_model   VARCHAR(96)  NULL,

  -- Geo configuration (Section 13)
  geo_provider         VARCHAR(32)  NOT NULL DEFAULT 'ipapi',

  -- Pricing policy (Section 15, 38)
  international_currency CHAR(3) NOT NULL DEFAULT 'USD',
  currency_locked      TINYINT(1) NOT NULL DEFAULT 1,   -- 1 = may not be changed in UI

  -- Conversation / cost control (Section 31, 46, 47)
  max_messages_per_conversation SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  max_recent_messages  SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  max_input_chars      SMALLINT UNSIGNED NOT NULL DEFAULT 1200,
  daily_request_budget  INT UNSIGNED NOT NULL DEFAULT 3000,
  daily_token_budget    INT UNSIGNED NOT NULL DEFAULT 2000000,
  rate_per_ip_per_min   SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  rate_per_session_per_min SMALLINT UNSIGNED NOT NULL DEFAULT 8,

  -- Circuit breaker (Section 45)
  breaker_failure_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  breaker_cooldown_seconds  INT UNSIGNED NOT NULL DEFAULT 300,

  -- Sales readiness rule (Section 30) — PHP evaluated, configurable threshold
  readiness_min_signals SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  readiness_required_signals VARCHAR(255) NOT NULL DEFAULT '',  -- csv of signals that must all be present

  -- Notification (Section 33)
  notify_email         VARCHAR(500) NULL,          -- international enquiries, comma separated
  notify_email_local   VARCHAR(500) NULL,          -- local / Pakistan enquiries, comma separated
  notify_timezone      VARCHAR(64) NOT NULL DEFAULT 'Asia/Karachi',  -- timezone used in notification emails
  notify_on_lead       TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_handoff    TINYINT(1) NOT NULL DEFAULT 1,

  -- Privacy / disclosure (Section 44)
  ai_disclosure_text   VARCHAR(500) NOT NULL DEFAULT '',
  privacy_url          VARCHAR(255) NULL,

  prompt_version       VARCHAR(32) NOT NULL DEFAULT 'v1',
  config_version       INT UNSIGNED NOT NULL DEFAULT 1,

  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agents_slug (slug),
  KEY idx_agents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- knowledge_articles  (Section 23, 24)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS knowledge_articles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL,
  category      VARCHAR(64)  NOT NULL DEFAULT 'general',
  body          MEDIUMTEXT NOT NULL,
  keywords      VARCHAR(500) NOT NULL DEFAULT '',
  stage_tags    VARCHAR(255) NOT NULL DEFAULT '',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  status        ENUM('draft','pending_review','approved','archived') NOT NULL DEFAULT 'draft',
  approved_version_body MEDIUMTEXT NULL,   -- last approved copy stays live while an edit is re-reviewed
  approved_version_title VARCHAR(255) NULL,
  reviewer_comment TEXT NULL,
  source_reference VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ka_agent (agent_id),
  KEY idx_ka_active (active),
  KEY idx_ka_status (status),
  KEY idx_ka_lookup (agent_id, status, active),
  CONSTRAINT fk_ka_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- faq_entries  (Section 23, 24)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faq_entries (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      INT UNSIGNED NOT NULL,
  question      VARCHAR(500) NOT NULL,
  answer        MEDIUMTEXT NOT NULL,
  keywords      VARCHAR(500) NOT NULL DEFAULT '',
  category      VARCHAR(64)  NOT NULL DEFAULT 'general',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  status        ENUM('draft','pending_review','approved','archived') NOT NULL DEFAULT 'draft',
  approved_version_answer MEDIUMTEXT NULL,
  approved_version_question VARCHAR(500) NULL,
  reviewer_comment TEXT NULL,
  source_reference VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faq_agent (agent_id),
  KEY idx_faq_lookup (agent_id, status, active),
  CONSTRAINT fk_faq_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- stall_packages  (Section 17)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stall_packages (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id       INT UNSIGNED NOT NULL,
  package_name   VARCHAR(160) NOT NULL,
  package_type   ENUM('shell','bare') NOT NULL DEFAULT 'shell',
  stall_size     VARCHAR(64) NOT NULL,
  size_sqm       DECIMAL(10,2) NULL,
  currency       CHAR(3) NOT NULL DEFAULT 'USD',
  price          DECIMAL(12,2) NOT NULL,
  price_basis    VARCHAR(64) NOT NULL DEFAULT 'per_stall',  -- per_stall | per_sqm
  pricing_mode   ENUM('fixed','per_sqm') NOT NULL DEFAULT 'fixed',
  rate_per_sqm   DECIMAL(12,2) NULL,           -- used when pricing_mode = per_sqm
  min_sqm        DECIMAL(10,2) NULL,           -- smallest space that may be booked
  increment_sqm  DECIMAL(10,2) NULL,           -- space is sold in multiples of this
  max_sqm        DECIMAL(10,2) NULL,           -- above this the team quotes manually
  edition_label  VARCHAR(160) NULL,            -- which edition this price belongs to
  region_group   ENUM('international','pakistan','both') NOT NULL DEFAULT 'international',
  description    TEXT NULL,
  included_items TEXT NULL,                    -- one inclusion per line
  tax_included   ENUM('yes','no','unspecified') NOT NULL DEFAULT 'unspecified',
  tax_rate_percent DECIMAL(5,2) NULL,          -- PHP computes the tax; never the model
  tax_label      VARCHAR(120) NULL,            -- e.g. Sindh Sales Tax (SST)
  tax_note       VARCHAR(255) NULL,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  status         ENUM('draft','pending_review','approved','archived') NOT NULL DEFAULT 'draft',
  approved_snapshot MEDIUMTEXT NULL,           -- JSON of last approved values (stays live during re-review)
  reviewer_comment TEXT NULL,
  source_reference VARCHAR(255) NULL,
  valid_from     DATE NULL,
  valid_until    DATE NULL,
  sort_order     SMALLINT NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sp_agent (agent_id),
  KEY idx_sp_region (region_group),
  KEY idx_sp_active (active),
  KEY idx_sp_status (status),
  KEY idx_sp_lookup (agent_id, region_group, status, active),
  CONSTRAINT fk_sp_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- sponsorship_packages  (Section 18)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sponsorship_packages (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id           INT UNSIGNED NOT NULL,
  tier_name          VARCHAR(160) NOT NULL,
  currency           CHAR(3) NOT NULL DEFAULT 'USD',
  price              DECIMAL(12,2) NOT NULL,
  benefits           TEXT NULL,                -- one benefit per line
  quantity_available SMALLINT NULL,
  region_group       ENUM('international','pakistan','both') NOT NULL DEFAULT 'international',
  description        TEXT NULL,
  tax_included       ENUM('yes','no','unspecified') NOT NULL DEFAULT 'unspecified',
  tax_rate_percent   DECIMAL(5,2) NULL,
  tax_label          VARCHAR(120) NULL,
  tax_note           VARCHAR(255) NULL,
  edition_label      VARCHAR(160) NULL,
  active             TINYINT(1) NOT NULL DEFAULT 1,
  status             ENUM('draft','pending_review','approved','archived') NOT NULL DEFAULT 'draft',
  approved_snapshot  MEDIUMTEXT NULL,
  reviewer_comment   TEXT NULL,
  source_reference   VARCHAR(255) NULL,
  valid_from         DATE NULL,
  valid_until        DATE NULL,
  sort_order         SMALLINT NOT NULL DEFAULT 0,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_spon_agent (agent_id),
  KEY idx_spon_region (region_group),
  KEY idx_spon_active (active),
  KEY idx_spon_status (status),
  KEY idx_spon_lookup (agent_id, region_group, status, active),
  CONSTRAINT fk_spon_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- knowledge_review_tokens  (Section 24)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS knowledge_review_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id    INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,                -- sha256 of the emailed token; raw token never stored
  token_hint  VARCHAR(12) NOT NULL DEFAULT '',  -- first chars, for admin display only
  item_type   ENUM('knowledge_article','faq','stall_package','sponsorship_package','mixed') NOT NULL,
  item_ids    TEXT NOT NULL,                    -- JSON: {"knowledge_article":[1,2],"faq":[5]}
  reviewer_name  VARCHAR(160) NULL,
  reviewer_email VARCHAR(255) NULL,
  status      ENUM('active','used','expired','revoked') NOT NULL DEFAULT 'active',
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_krt_token (token_hash),
  KEY idx_krt_agent (agent_id),
  KEY idx_krt_status (status),
  CONSTRAINT fk_krt_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual reviewer decisions, so comments survive after the token expires
CREATE TABLE IF NOT EXISTS review_actions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id    INT UNSIGNED NOT NULL,
  agent_id    INT UNSIGNED NOT NULL,
  item_type   VARCHAR(32) NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  decision    ENUM('approved','rejected') NOT NULL,
  comment     TEXT NULL,
  reviewer_ip VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ra_token (token_id),
  KEY idx_ra_item (item_type, item_id),
  KEY idx_ra_agent (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leads  (Section 9)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id        INT UNSIGNED NOT NULL,
  reference       VARCHAR(24) NOT NULL,
  -- Contact
  name            VARCHAR(160) NULL,
  email           VARCHAR(255) NULL,
  phone           VARCHAR(64) NULL,
  country         VARCHAR(96) NULL,
  city            VARCHAR(96) NULL,
  -- Business
  company         VARCHAR(200) NULL,
  industry        VARCHAR(160) NULL,
  product_category VARCHAR(200) NULL,
  company_size    VARCHAR(64) NULL,
  website         VARCHAR(255) NULL,
  company_country VARCHAR(96) NULL,
  -- Sales
  exhibitor_intent ENUM('unknown','attendee','exhibitor','sponsor','partner','media','job_seeker','other') NOT NULL DEFAULT 'unknown',
  requirements    TEXT NULL,
  package_interest TEXT NULL,          -- JSON list of {type,id,label}
  internal_note    TEXT NULL,          -- notes for the team, never shown to a visitor
  pricing_discussed TINYINT(1) NOT NULL DEFAULT 0,
  objections      TEXT NULL,
  status          ENUM('new','contact_captured','qualified','pricing_discussed','interested','follow_up_required','converted','not_interested','closed','spam') NOT NULL DEFAULT 'new',
  follow_up_notes MEDIUMTEXT NULL,
  handoff_required TINYINT(1) NOT NULL DEFAULT 0,
  handoff_reason  VARCHAR(255) NULL,
  -- Technical / geo
  pricing_country ENUM('pakistan','international','undetermined') NOT NULL DEFAULT 'undetermined',
  country_source  ENUM('visitor_disclosed','ip_geo','undetermined') NOT NULL DEFAULT 'undetermined',
  visitor_id      VARCHAR(64) NULL,
  first_seen_ip   VARCHAR(45) NULL,
  ip_retained_until DATE NULL,
  dedup_flag      TINYINT(1) NOT NULL DEFAULT 0,
  dedup_note      VARCHAR(255) NULL,
  merged_into_id  INT UNSIGNED NULL,
  source_url      VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lead_ref (reference),
  KEY idx_lead_agent (agent_id),
  KEY idx_lead_email (email),
  KEY idx_lead_phone (phone),
  KEY idx_lead_status (status),
  KEY idx_lead_visitor (agent_id, visitor_id),
  KEY idx_lead_created (created_at),
  CONSTRAINT fk_lead_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- conversations  (Section 6, 9, 37)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id         INT UNSIGNED NOT NULL,
  lead_id          INT UNSIGNED NULL,
  session_id       CHAR(48) NOT NULL,
  visitor_id       VARCHAR(64) NULL,
  session_secret_hash CHAR(64) NOT NULL,      -- proves the caller owns this session
  state            VARCHAR(32) NOT NULL DEFAULT 'new',
  intent           VARCHAR(24) NOT NULL DEFAULT 'unknown',
  pricing_country  ENUM('pakistan','international','undetermined') NOT NULL DEFAULT 'undetermined',
  country_source   ENUM('visitor_disclosed','ip_geo','undetermined') NOT NULL DEFAULT 'undetermined',
  disclosed_country VARCHAR(96) NULL,
  disclosed_company_country VARCHAR(96) NULL,
  ip_country_code  CHAR(2) NULL,
  ip_country_name  VARCHAR(96) NULL,
  ip_geo_status    VARCHAR(32) NULL,
  ip_geo_failure   VARCHAR(160) NULL,
  geo_checked_at   DATETIME NULL,
  visitor_ip       VARCHAR(45) NULL,
  user_agent       VARCHAR(400) NULL,
  origin_domain    VARCHAR(160) NULL,
  page_url         VARCHAR(500) NULL,
  readiness_signals TEXT NULL,                -- JSON object of computed signals
  requested_sqm     DECIMAL(10,2) NULL,       -- size the visitor asked about, for rate-based quoting
  lead_state       MEDIUMTEXT NULL,           -- JSON of structured lead fields carried in the conversation
  summary          MEDIUMTEXT NULL,
  message_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pricing_shown    TINYINT(1) NOT NULL DEFAULT 0,
  handoff_triggered TINYINT(1) NOT NULL DEFAULT 0,
  handoff_reason   VARCHAR(255) NULL,
  prompt_version   VARCHAR(32) NULL,
  config_version   INT UNSIGNED NULL,
  status           ENUM('open','completed','abandoned','blocked') NOT NULL DEFAULT 'open',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_session (session_id),
  KEY idx_conv_agent (agent_id),
  KEY idx_conv_lead (lead_id),
  KEY idx_conv_visitor (agent_id, visitor_id),
  KEY idx_conv_started (created_at),
  CONSTRAINT fk_conv_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- messages  (Section 32, 37)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  agent_id        INT UNSIGNED NOT NULL,
  sequence_number INT UNSIGNED NOT NULL,
  role            ENUM('visitor','agent','system') NOT NULL,
  content         MEDIUMTEXT NOT NULL,
  rendered_cards  MEDIUMTEXT NULL,            -- JSON of package cards PHP attached to this turn
  stage_before    VARCHAR(32) NULL,
  stage_after     VARCHAR(32) NULL,
  llm_raw_signals MEDIUMTEXT NULL,            -- validated structured output, minus reply text
  model           VARCHAR(96) NULL,
  provider        VARCHAR(32) NULL,
  input_tokens    INT UNSIGNED NULL,
  output_tokens   INT UNSIGNED NULL,
  latency_ms      INT UNSIGNED NULL,
  request_id      VARCHAR(64) NULL,
  response_status VARCHAR(32) NULL,
  error_information VARCHAR(500) NULL,
  is_fallback     TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_seq (conversation_id, sequence_number),
  KEY idx_msg_conv (conversation_id),
  KEY idx_msg_request (request_id),
  KEY idx_msg_agent (agent_id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- admin_users  (Section 42)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(64) NOT NULL,
  email          VARCHAR(255) NOT NULL,
  full_name      VARCHAR(160) NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin') NOT NULL DEFAULT 'admin',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at  DATETIME NULL,
  last_login_ip  VARCHAR(45) NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username),
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier  VARCHAR(160) NOT NULL,    -- username or ip
  ip          VARCHAR(45) NOT NULL,
  successful  TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_identifier (identifier, attempted_at),
  KEY idx_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- audit_log  (Section 48)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id     INT UNSIGNED NULL,
  admin_user_id INT UNSIGNED NULL,
  actor        VARCHAR(96) NOT NULL DEFAULT 'system',  -- admin username | 'system' | 'reviewer' | 'visitor'
  event        VARCHAR(64) NOT NULL,
  entity_type  VARCHAR(48) NULL,
  entity_id    VARCHAR(64) NULL,
  field        VARCHAR(64) NULL,
  old_value    TEXT NULL,
  new_value    TEXT NULL,
  detail       TEXT NULL,
  ip           VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_agent (agent_id),
  KEY idx_audit_event (event),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- agent_provider_health  (Section 45 circuit breaker)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agent_provider_health (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id             INT UNSIGNED NOT NULL,
  provider             VARCHAR(32) NOT NULL,
  status               ENUM('normal','unavailable') NOT NULL DEFAULT 'normal',
  consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_failure_at      DATETIME NULL,
  last_success_at      DATETIME NULL,
  last_checked_at      DATETIME NULL,
  status_changed_at    DATETIME NULL,
  last_error           VARCHAR(400) NULL,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aph (agent_id, provider),
  CONSTRAINT fk_aph_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Supporting tables
-- ---------------------------------------------------------------------

-- Idempotency (Section 32): replay the original result for a repeated request_id
CREATE TABLE IF NOT EXISTS request_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id      VARCHAR(64) NOT NULL,
  agent_id        INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  endpoint        VARCHAR(48) NOT NULL,
  state           ENUM('in_progress','complete','failed') NOT NULL DEFAULT 'in_progress',
  response_json   MEDIUMTEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rl_request (request_id, endpoint),
  KEY idx_rl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting without Redis (Section 47)
CREATE TABLE IF NOT EXISTS rate_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket     VARCHAR(96) NOT NULL,      -- e.g. ip:1.2.3.4 | sess:abc | agent:1
  agent_id   INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_re_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Geo lookups, kept for audit (Section 12, 13)
CREATE TABLE IF NOT EXISTS geo_lookups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id        INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NULL,
  ip              VARCHAR(45) NULL,
  country_code    CHAR(2) NULL,
  country_name    VARCHAR(96) NULL,
  classification  ENUM('pakistan','international','undetermined') NOT NULL,
  provider        VARCHAR(32) NOT NULL,
  status          VARCHAR(32) NOT NULL,
  failure_reason  VARCHAR(255) NULL,
  latency_ms      INT UNSIGNED NULL,
  retain_until    DATE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_geo_conv (conversation_id),
  KEY idx_geo_agent (agent_id),
  KEY idx_geo_retain (retain_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily usage counters (Section 46)
CREATE TABLE IF NOT EXISTS usage_daily (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      INT UNSIGNED NOT NULL,
  usage_date    DATE NOT NULL,
  requests      INT UNSIGNED NOT NULL DEFAULT 0,
  input_tokens  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
  errors        INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ud (agent_id, usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform settings + encrypted secrets (Section 43)
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(96) NOT NULL,
  setting_value MEDIUMTEXT NULL,
  is_secret     TINYINT(1) NOT NULL DEFAULT 0,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approved fallback messages (Section 30)
CREATE TABLE IF NOT EXISTS fallback_messages (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id   INT UNSIGNED NOT NULL,
  message_key VARCHAR(48) NOT NULL,
  body       TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fb (agent_id, message_key),
  CONSTRAINT fk_fb_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference attachments for provenance (Section 24) — stored, never parsed
CREATE TABLE IF NOT EXISTS reference_attachments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id      INT UNSIGNED NOT NULL,
  item_type     VARCHAR(32) NOT NULL,
  item_id       INT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(96) NULL,
  size_bytes    INT UNSIGNED NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_att_item (item_type, item_id),
  KEY idx_att_agent (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound email queue (Section 33, 46 — no daemon; flushed inline or by cPanel cron)
CREATE TABLE IF NOT EXISTS email_outbox (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id     INT UNSIGNED NULL,
  to_email     VARCHAR(255) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body         MEDIUMTEXT NOT NULL,          -- plain text version
  body_html    MEDIUMTEXT NULL,              -- formatted version, when there is one
  status       ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(400) NULL,
  dedupe_key   VARCHAR(96) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_eo_status (status, created_at),
  UNIQUE KEY uq_eo_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Document import ("Conversation with AI")
--
-- Turning uploaded brochures and price lists into knowledge is a staged
-- process, and every stage is recorded so the operator can see where a
-- fact came from and why a value was chosen. Nothing here ever reaches a
-- visitor directly: the last stage creates ordinary DRAFT records, which
-- still go through the normal review workflow (Section 24).
-- =====================================================================

-- ---------------------------------------------------------------------
-- import_runs — one upload-and-analyse session
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_runs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id        INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED NULL,
  title           VARCHAR(190) NULL,
  status          ENUM('collecting','extracting','analysing','reconciling',
                       'clarifying','proposing','review','published','cancelled','failed')
                  NOT NULL DEFAULT 'collecting',
  stage_detail    VARCHAR(255) NULL,          -- what it is doing right now, for the progress line
  -- Which AI reads the documents. Deliberately separate from the agent's own
  -- provider: reading a pile of brochures is a one-off batch job, and the
  -- cheapest or fastest provider for that is often not the one you want
  -- answering visitors. NULL means "whatever the agent uses".
  llm_provider    VARCHAR(32) NULL,
  llm_model       VARCHAR(96) NULL,
  documents_total   SMALLINT NOT NULL DEFAULT 0,
  documents_done    SMALLINT NOT NULL DEFAULT 0,
  chunks_total      SMALLINT NOT NULL DEFAULT 0,
  chunks_done       SMALLINT NOT NULL DEFAULT 0,
  findings_total     SMALLINT NOT NULL DEFAULT 0,
  questions_total    SMALLINT NOT NULL DEFAULT 0,
  questions_answered SMALLINT NOT NULL DEFAULT 0,
  proposals_total     SMALLINT NOT NULL DEFAULT 0,
  published_total     SMALLINT NOT NULL DEFAULT 0,
  input_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(500) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ir_agent (agent_id, status),
  CONSTRAINT fk_ir_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_documents — the uploaded files
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_documents (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          INT UNSIGNED NOT NULL,
  agent_id        INT UNSIGNED NOT NULL,
  original_name   VARCHAR(255) NOT NULL,
  stored_name     VARCHAR(190) NOT NULL,      -- random name inside storage/uploads
  extension       VARCHAR(10) NOT NULL,
  mime_type       VARCHAR(120) NULL,
  byte_size       INT UNSIGNED NOT NULL DEFAULT 0,
  sha256          CHAR(64) NULL,              -- so the same file twice is spotted
  -- What the operator says this document is, which decides how much weight
  -- its claims carry when two documents disagree.
  doc_kind        ENUM('unknown','brochure','price_list','sponsorship','post_show_report',
                       'terms','floor_plan','other')
                  NOT NULL DEFAULT 'unknown',
  edition_label   VARCHAR(160) NULL,          -- which edition it describes, if known
  authority       TINYINT NOT NULL DEFAULT 5, -- 1-9; higher wins a tie automatically
  published_on    DATE NULL,                  -- document date, used to spot outdated content
  extraction_status ENUM('pending','ok','empty','failed') NOT NULL DEFAULT 'pending',
  extraction_method VARCHAR(80) NULL,
  extraction_problem VARCHAR(500) NULL,
  extracted_chars INT UNSIGNED NOT NULL DEFAULT 0,
  page_count      SMALLINT NULL,
  text_body       MEDIUMTEXT NULL,            -- the extracted text, kept for review
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_id_run (run_id),
  KEY idx_id_agent (agent_id),
  CONSTRAINT fk_id_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_chunks — text split into analysable pieces
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_chunks (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          INT UNSIGNED NOT NULL,
  document_id     INT UNSIGNED NOT NULL,
  chunk_index     SMALLINT NOT NULL DEFAULT 0,
  char_offset     INT UNSIGNED NOT NULL DEFAULT 0,
  body            MEDIUMTEXT NOT NULL,
  status          ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error      VARCHAR(400) NULL,
  analysed_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_ic_run (run_id, status),
  KEY idx_ic_doc (document_id),
  CONSTRAINT fk_ic_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_doc FOREIGN KEY (document_id) REFERENCES import_documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_findings — one candidate fact, with where it came from
--
-- claims_json holds the atomic assertions as {key: value} pairs, which is
-- what makes disagreement between documents detectable in PHP rather than
-- left to the model's judgement.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_findings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          INT UNSIGNED NOT NULL,
  document_id     INT UNSIGNED NOT NULL,
  chunk_id        INT UNSIGNED NULL,
  kind            ENUM('knowledge','faq','stall_package','sponsorship_package') NOT NULL,
  subject_key     VARCHAR(190) NOT NULL,      -- normalised identity, for grouping
  subject_label   VARCHAR(190) NOT NULL,      -- readable version of the same
  edition_label   VARCHAR(160) NULL,
  payload_json    MEDIUMTEXT NOT NULL,        -- the proposed content
  claims_json     MEDIUMTEXT NULL,            -- comparable {key: value} assertions
  source_quote    TEXT NULL,                  -- verbatim snippet, so a claim can be checked
  confidence      ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  concerns_json   TEXT NULL,
  status          ENUM('candidate','duplicate','conflicted','resolved','discarded')
                  NOT NULL DEFAULT 'candidate',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_if_run (run_id, kind),
  KEY idx_if_subject (run_id, subject_key),
  CONSTRAINT fk_if_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_questions — clarifications put to the operator
--
-- The permanent record of what was asked, what the options were, and what
-- the operator decided.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_questions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          INT UNSIGNED NOT NULL,
  kind            ENUM('conflict','ambiguous','incomplete','outdated','currency','tax','policy')
                  NOT NULL DEFAULT 'conflict',
  subject_key     VARCHAR(190) NOT NULL,
  subject_label   VARCHAR(190) NOT NULL,
  claim_key       VARCHAR(80) NULL,           -- which assertion is in dispute
  question_text   TEXT NOT NULL,
  detail_text     TEXT NULL,                  -- why it is being asked
  options_json    MEDIUMTEXT NULL,            -- [{value,label,source_document_ids,quote}]
  allow_free_text TINYINT(1) NOT NULL DEFAULT 1,
  answer_value    TEXT NULL,
  answer_source   VARCHAR(190) NULL,          -- which option was picked, or "typed"
  answer_note     TEXT NULL,
  status          ENUM('open','answered','skipped') NOT NULL DEFAULT 'open',
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  answered_by     INT UNSIGNED NULL,
  answered_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_iq_run (run_id, status),
  CONSTRAINT fk_iq_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- import_proposals — what will actually be created, after review
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_proposals (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id          INT UNSIGNED NOT NULL,
  agent_id        INT UNSIGNED NOT NULL,
  target_type     ENUM('knowledge_article','faq','stall_package','sponsorship_package') NOT NULL,
  subject_key     VARCHAR(190) NOT NULL,
  title           VARCHAR(255) NOT NULL,
  payload_json    MEDIUMTEXT NOT NULL,
  source_summary  VARCHAR(500) NULL,          -- which documents it came from
  clarified       TINYINT(1) NOT NULL DEFAULT 0,  -- whether an answer settled it
  warnings_json   TEXT NULL,                  -- anything the operator should look at
  status          ENUM('proposed','edited','excluded','published','failed')
                  NOT NULL DEFAULT 'proposed',
  created_entity_id INT UNSIGNED NULL,        -- the draft record it became
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_run (run_id, status),
  KEY idx_ip_agent (agent_id),
  CONSTRAINT fk_ip_run FOREIGN KEY (run_id) REFERENCES import_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
