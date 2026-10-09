<?php
declare(strict_types=1);

/**
 * Database upgrades, applied one step at a time.
 *
 * The platform is already live with real data, so an upgrade can never be a
 * matter of re-running schema.sql. Each step here checks the database first
 * and does nothing if the change is already present, which makes the whole
 * set safe to run repeatedly — including halfway through, after a timeout,
 * or twice by an operator who was not sure the first one worked.
 *
 * Steps are never edited once shipped. A correction is a new step.
 */
final class Migrations
{
    /**
     * Every step, oldest first.
     *
     * check  — returns true when the change is already in place
     * sql    — statements to run, in order, when it is not
     */
    public static function steps(): array
    {
        return [
            [
                'key'   => '2026_09_schema_migrations',
                'label' => 'Record of which upgrades have run',
                'check' => static fn(): bool => self::tableExists('schema_migrations'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS schema_migrations (
                        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        step_key    VARCHAR(64) NOT NULL,
                        applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        applied_by  VARCHAR(64) NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_sm_key (step_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_companies',
                'label' => 'Company records',
                'check' => static fn(): bool => self::tableExists('companies'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS companies (
                        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id      INT UNSIGNED NOT NULL,
                        name          VARCHAR(200) NOT NULL,
                        website       VARCHAR(255) NULL,
                        domain        VARCHAR(190) NULL,      -- normalised; the identity signal
                        country       VARCHAR(96) NULL,
                        city          VARCHAR(96) NULL,
                        industry      VARCHAR(160) NULL,
                        categories    TEXT NULL,              -- JSON list of matched exhibition categories
                        description   MEDIUMTEXT NULL,
                        status        ENUM('active','do_not_contact','archived') NOT NULL DEFAULT 'active',
                        source        ENUM('prospecting','website','registration','whatsapp','manual') NOT NULL DEFAULT 'manual',
                        origin_note   VARCHAR(255) NULL,
                        research_state ENUM('none','partial','complete','failed') NOT NULL DEFAULT 'none',
                        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_companies_domain (agent_id, domain),
                        KEY idx_companies_agent_status (agent_id, status),
                        KEY idx_companies_name (agent_id, name),
                        CONSTRAINT fk_companies_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_company_evidence',
                'label' => 'Evidence behind each company fact',
                'check' => static fn(): bool => self::tableExists('company_evidence'),
                'sql'   => [
                    // One row per claim, not per company, so that "where did
                    // this come from" has an answer for every single fact.
                    "CREATE TABLE IF NOT EXISTS company_evidence (
                        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        company_id    INT UNSIGNED NOT NULL,
                        agent_id      INT UNSIGNED NOT NULL,
                        claim_key     VARCHAR(64) NOT NULL,   -- country, industry, product, ...
                        claim_value   TEXT NOT NULL,
                        kind          ENUM('observed','inferred') NOT NULL DEFAULT 'observed',
                        confidence    ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
                        source_url    VARCHAR(500) NULL,
                        source_label  VARCHAR(160) NULL,
                        observed_at   DATETIME NULL,
                        round         SMALLINT UNSIGNED NOT NULL DEFAULT 1,  -- re-research adds a round
                        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_ce_company (company_id, claim_key),
                        KEY idx_ce_agent (agent_id),
                        CONSTRAINT fk_ce_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
                        CONSTRAINT fk_ce_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_company_contacts',
                'label' => 'Business contact points',
                'check' => static fn(): bool => self::tableExists('company_contacts'),
                'sql'   => [
                    // Kept apart from company facts so a deletion request can
                    // remove the contact details without destroying research.
                    "CREATE TABLE IF NOT EXISTS company_contacts (
                        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        company_id    INT UNSIGNED NOT NULL,
                        agent_id      INT UNSIGNED NOT NULL,
                        kind          ENUM('email','phone') NOT NULL,
                        value         VARCHAR(255) NOT NULL,
                        normalised    VARCHAR(255) NOT NULL,
                        role_label    VARCHAR(120) NULL,
                        source_url    VARCHAR(500) NULL,
                        consent_basis VARCHAR(64) NULL,
                        status        ENUM('active','bounced','invalid','suppressed') NOT NULL DEFAULT 'active',
                        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_cc_value (company_id, kind, normalised),
                        KEY idx_cc_agent (agent_id, kind, normalised),
                        CONSTRAINT fk_cc_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
                        CONSTRAINT fk_cc_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_suppression',
                'label' => 'Do-not-contact list',
                'check' => static fn(): bool => self::tableExists('suppression_list'),
                'sql'   => [
                    // Deliberately holds no link to a company: the point of a
                    // suppression entry is to remember someone whose record
                    // you may no longer keep.
                    "CREATE TABLE IF NOT EXISTS suppression_list (
                        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id    INT UNSIGNED NOT NULL,
                        kind        ENUM('email','domain','phone') NOT NULL,
                        value       VARCHAR(255) NOT NULL,     -- already normalised
                        reason      VARCHAR(255) NULL,
                        source      ENUM('opt_out','manual','bounce','complaint','import') NOT NULL DEFAULT 'manual',
                        created_by  VARCHAR(64) NULL,
                        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_sl_value (agent_id, kind, value),
                        CONSTRAINT fk_sl_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_conversation_channel',
                'label' => 'Channel on conversations',
                'check' => static fn(): bool => self::columnExists('conversations', 'channel'),
                'sql'   => [
                    // Existing rows are all website conversations, which is
                    // exactly what the default gives them.
                    "ALTER TABLE conversations
                        ADD COLUMN channel ENUM('web','whatsapp') NOT NULL DEFAULT 'web' AFTER agent_id",
                    "ALTER TABLE conversations
                        ADD INDEX idx_conv_channel (agent_id, channel)",
                ],
            ],

            [
                'key'   => '2026_09_conversation_company',
                'label' => 'Company link on conversations',
                'check' => static fn(): bool => self::columnExists('conversations', 'company_id'),
                'sql'   => [
                    "ALTER TABLE conversations
                        ADD COLUMN company_id INT UNSIGNED NULL AFTER lead_id",
                    "ALTER TABLE conversations
                        ADD INDEX idx_conv_company (company_id)",
                ],
            ],

            [
                'key'   => '2026_09_exhibition_sectors',
                'label' => 'What each exhibition is about',
                'check' => static fn(): bool => self::columnExists('agents', 'exhibition_sectors'),
                'sql'   => [
                    // Without this the platform can tell that a company is a
                    // manufacturer, but not whether it manufactures anything
                    // relevant to this particular show.
                    "ALTER TABLE agents
                        ADD COLUMN exhibition_sectors TEXT NULL AFTER sales_objectives",
                ],
            ],

            [
                'key'   => '2026_09_whatsapp_agent',
                'label' => 'WhatsApp settings per exhibition',
                'check' => static fn(): bool => self::columnExists('agents', 'whatsapp_number'),
                'sql'   => [
                    // Each exhibition messages from its own number, so one
                    // being blocked never takes the others with it.
                    "ALTER TABLE agents
                        ADD COLUMN whatsapp_number VARCHAR(24) NULL AFTER notify_timezone,
                        ADD COLUMN whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_number,
                        ADD COLUMN whatsapp_first_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10
                            AFTER whatsapp_enabled,
                        ADD COLUMN whatsapp_daily_cap SMALLINT UNSIGNED NOT NULL DEFAULT 40
                            AFTER whatsapp_first_delay_minutes",
                ],
            ],

            [
                'key'   => '2026_09_registrations',
                'label' => 'Visitor registrations',
                'check' => static fn(): bool => self::tableExists('registrations'),
                'sql'   => [
                    // A copy of what the visitor submitted, kept as submitted.
                    // source_id is their row id, and the uniqueness rule on it
                    // is what makes re-reading the table harmless.
                    "CREATE TABLE IF NOT EXISTS registrations (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        source_id       INT UNSIGNED NOT NULL,
                        source_event_id INT UNSIGNED NULL,
                        name            VARCHAR(200) NULL,
                        designation     VARCHAR(160) NULL,
                        company_name    VARCHAR(200) NULL,
                        email           VARCHAR(255) NULL,
                        website         VARCHAR(255) NULL,
                        phone_raw       VARCHAR(120) NULL,
                        phone_e164      VARCHAR(24) NULL,
                        city            VARCHAR(96) NULL,
                        country         VARCHAR(96) NULL,
                        reg_type        VARCHAR(64) NULL,
                        interested_in   VARCHAR(255) NULL,
                        purpose         VARCHAR(255) NULL,
                        product_solution VARCHAR(255) NULL,
                        regsite         VARCHAR(64) NULL,
                        onsite          TINYINT(1) NOT NULL DEFAULT 0,
                        consent_whatsapp ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
                        whatsapp_state  ENUM('eligible','no_consent','no_number','onsite','suppressed','sent','opted_out')
                                        NOT NULL DEFAULT 'no_consent',
                        company_id      INT UNSIGNED NULL,
                        lead_id         INT UNSIGNED NULL,
                        registered_at   DATETIME NULL,
                        imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_reg_source (agent_id, source_id),
                        KEY idx_reg_agent_state (agent_id, whatsapp_state),
                        KEY idx_reg_email (agent_id, email),
                        KEY idx_reg_phone (agent_id, phone_e164),
                        CONSTRAINT fk_reg_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_consent_at',
                'label' => 'When consent was given',
                'check' => static fn(): bool => self::columnExists('registrations', 'consent_at'),
                'sql'   => [
                    // "We had consent" only holds up if you can say when.
                    "ALTER TABLE registrations
                        ADD COLUMN consent_at DATETIME NULL AFTER consent_whatsapp",
                ],
            ],

            [
                'key'   => '2026_09_phone_problem',
                'label' => 'Why a number could not be used',
                'check' => static fn(): bool => self::columnExists('registrations', 'phone_problem'),
                'sql'   => [
                    // "No usable number" against two thousand registrations
                    // tells an operator nothing. Which two thousand?
                    "ALTER TABLE registrations
                        ADD COLUMN phone_problem VARCHAR(24) NULL AFTER phone_e164",
                ],
            ],

            [
                'key'   => '2026_09_whatsapp_hours',
                'label' => 'Quiet hours for WhatsApp',
                'check' => static fn(): bool => self::columnExists('agents', 'whatsapp_send_from'),
                'sql'   => [
                    // A message at two in the morning is a complaint waiting
                    // to happen, and complaints are what get a number blocked.
                    "ALTER TABLE agents
                        ADD COLUMN whatsapp_send_from TIME NOT NULL DEFAULT '10:00:00' AFTER whatsapp_daily_cap,
                        ADD COLUMN whatsapp_send_to TIME NOT NULL DEFAULT '20:00:00' AFTER whatsapp_send_from,
                        ADD COLUMN whatsapp_followups TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER whatsapp_send_to",
                ],
            ],

            [
                'key'   => '2026_09_whatsapp_outbox',
                'label' => 'WhatsApp messages waiting to go',
                'check' => static fn(): bool => self::tableExists('whatsapp_outbox'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS whatsapp_outbox (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        registration_id INT UNSIGNED NULL,
                        conversation_id INT UNSIGNED NULL,
                        phone_e164      VARCHAR(24) NOT NULL,
                        body            MEDIUMTEXT NOT NULL,
                        kind            ENUM('opening','reply','followup') NOT NULL DEFAULT 'opening',
                        status          ENUM('scheduled','sending','sent','failed','cancelled')
                                        NOT NULL DEFAULT 'scheduled',
                        scheduled_for   DATETIME NOT NULL,
                        attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        last_error      VARCHAR(400) NULL,
                        provider        VARCHAR(32) NULL,
                        provider_ref    VARCHAR(160) NULL,
                        used_fallback   TINYINT(1) NOT NULL DEFAULT 0,
                        blocked_reason  VARCHAR(255) NULL,
                        dedupe_key      VARCHAR(96) NOT NULL,
                        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        sent_at         DATETIME NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_wo_dedupe (dedupe_key),
                        KEY idx_wo_due (agent_id, status, scheduled_for),
                        KEY idx_wo_phone (agent_id, phone_e164),
                        CONSTRAINT fk_wo_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_whatsapp_inbox',
                'label' => 'WhatsApp replies received',
                'check' => static fn(): bool => self::tableExists('whatsapp_inbox'),
                'sql'   => [
                    // The provider may deliver the same message twice. The
                    // unique reference is what stops it being answered twice.
                    "CREATE TABLE IF NOT EXISTS whatsapp_inbox (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        phone_e164      VARCHAR(24) NOT NULL,
                        body            MEDIUMTEXT NULL,
                        provider_ref    VARCHAR(160) NOT NULL,
                        conversation_id INT UNSIGNED NULL,
                        handled         TINYINT(1) NOT NULL DEFAULT 0,
                        note            VARCHAR(255) NULL,
                        received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_wi_ref (agent_id, provider_ref),
                        KEY idx_wi_phone (agent_id, phone_e164),
                        CONSTRAINT fk_wi_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                // Separate from the outbox step so it applies whether or not
                // that table already exists on this server.
                'key'   => '2026_09_whatsapp_approval',
                'label' => 'Approving first WhatsApp messages before they go',
                'check' => static fn(): bool => self::columnExists('agents', 'whatsapp_approve_first'),
                'sql'   => [
                    // On by default. A new number sending messages nobody has
                    // read is how a number gets blocked in its first week.
                    "ALTER TABLE agents
                        ADD COLUMN whatsapp_approve_first TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_followups",
                    "ALTER TABLE whatsapp_outbox
                        MODIFY status ENUM('held','scheduled','sending','sent','failed','cancelled')
                        NOT NULL DEFAULT 'scheduled'",
                ],
            ],

            [
                // A registration whose first message has been written is taken
                // out of the pool, so the same people are never looked at
                // again on every run — and so a cancelled or failed message
                // cannot sit in front of everyone registered after it.
                'key'   => '2026_09_registration_queued',
                'label' => 'Marking registrations whose first message is written',
                'check' => static fn(): bool => self::enumAllows('registrations', 'whatsapp_state', 'queued'),
                'sql'   => [
                    "ALTER TABLE registrations
                        MODIFY whatsapp_state
                        ENUM('eligible','queued','no_consent','no_number','onsite','suppressed','sent','opted_out')
                        NOT NULL DEFAULT 'no_consent'",
                ],
            ],

            [
                // A conversation a person has taken over, and who wrote each
                // message — the assistant or a named member of the team.
                'key'   => '2026_09_whatsapp_handover',
                'label' => 'Letting a person take over a WhatsApp conversation',
                'check' => static fn(): bool => self::columnExists('conversations', 'automation_paused_at'),
                'sql'   => [
                    "ALTER TABLE conversations
                        ADD COLUMN automation_paused_at DATETIME NULL AFTER handoff_reason,
                        ADD COLUMN automation_paused_reason VARCHAR(255) NULL AFTER automation_paused_at",
                    "ALTER TABLE whatsapp_outbox
                        ADD COLUMN sent_by VARCHAR(64) NULL AFTER kind",
                ],
            ],

            [
                // Each WhatsApp number has its own key: a TextMeBot key belongs
                // to the one phone it was linked with. Stored encrypted.
                'key'   => '2026_09_whatsapp_key_per_exhibition',
                'label' => 'A separate WhatsApp API key for each exhibition\'s number',
                'check' => static fn(): bool => self::columnExists('agents', 'whatsapp_api_key'),
                'sql'   => [
                    "ALTER TABLE agents
                        ADD COLUMN whatsapp_api_key TEXT NULL AFTER whatsapp_number",
                ],
            ],

            [
                // One local and one international rep per exhibition, with the
                // details a visitor needs to reach them directly.
                'key'   => '2026_09_sales_reps',
                'label' => 'Local and international sales rep for each exhibition',
                'check' => static fn(): bool => self::tableExists('agent_sales_reps'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS agent_sales_reps (
                        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id    INT UNSIGNED NOT NULL,
                        region      ENUM('local','international') NOT NULL,
                        name        VARCHAR(120) NULL,
                        designation VARCHAR(120) NULL,
                        email       VARCHAR(190) NULL,
                        mobile      VARCHAR(24) NULL,
                        whatsapp    VARCHAR(24) NULL,
                        wechat      VARCHAR(64) NULL,
                        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_rep_region (agent_id, region),
                        CONSTRAINT fk_rep_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                // The short "who are you" card at the start of a website chat.
                'key'   => '2026_09_chat_intro',
                'label' => 'The short introduction card at the start of the website chat',
                'check' => static fn(): bool => self::columnExists('agents', 'chat_intro_form'),
                'sql'   => [
                    "ALTER TABLE agents
                        ADD COLUMN chat_intro_form TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_disclosure_text",
                ],
            ],

            [
                // One row per research run on one company, with everything the
                // team needs to judge it: score in parts, facts with sources,
                // what was only inferred, and what could not be found.
                'key'   => '2026_09_company_research',
                'label' => 'Company research results',
                'check' => static fn(): bool => self::tableExists('company_research'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS company_research (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        company_id      INT UNSIGNED NULL,
                        company_name    VARCHAR(200) NOT NULL,
                        company_country VARCHAR(96) NULL,
                        company_website VARCHAR(255) NULL,
                        conversation_id INT UNSIGNED NULL,
                        trigger_source  ENUM('chat','whatsapp','admin','campaign') NOT NULL DEFAULT 'admin',
                        status          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
                        provider        VARCHAR(32) NULL,
                        credits_used    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                        score           TINYINT UNSIGNED NULL,
                        priority        VARCHAR(24) NULL,
                        match_level     ENUM('direct','strong','indirect','poor') NULL,
                        prospect_type   ENUM('exhibitor','sponsor','both','visitor_buyer','distributor') NULL,
                        propensity      ENUM('high','medium','low') NULL,
                        findings        MEDIUMTEXT NULL,
                        sources         MEDIUMTEXT NULL,
                        error           VARCHAR(400) NULL,
                        requested_by    VARCHAR(64) NULL,
                        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        started_at      DATETIME NULL,
                        finished_at     DATETIME NULL,
                        PRIMARY KEY (id),
                        KEY idx_cr_agent_status (agent_id, status),
                        KEY idx_cr_company (agent_id, company_id),
                        KEY idx_cr_conversation (conversation_id),
                        CONSTRAINT fk_cr_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_09_lead_company',
                'label' => 'Company link on leads',
                'check' => static fn(): bool => self::columnExists('leads', 'company_id'),
                'sql'   => [
                    "ALTER TABLE leads
                        ADD COLUMN company_id INT UNSIGNED NULL AFTER agent_id",
                    "ALTER TABLE leads
                        ADD INDEX idx_leads_company (company_id)",
                ],
            ],

            [
                'key'   => '2026_10_whatsapp_followups',
                'label' => 'WhatsApp follow-ups: how long to wait, and the exhibition\'s last day',
                'check' => static fn(): bool => self::columnExists('agents', 'whatsapp_followup_days'),
                'sql'   => [
                    // After the last day there is nothing left to follow up
                    // about, so nothing automatic is sent.
                    "ALTER TABLE agents
                        ADD COLUMN whatsapp_followup_days TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER whatsapp_followups,
                        ADD COLUMN event_ends_on DATE NULL AFTER whatsapp_followup_days",
                ],
            ],

            [
                'key'   => '2026_10_mail_profile',
                'label' => 'Each exhibition\'s own email sender and mail server, and its prospecting pace',
                'check' => static fn(): bool => self::columnExists('agents', 'mail_from_email'),
                'sql'   => [
                    // Prospect emails go out as the exhibition, through its
                    // own mail server; the password is stored encrypted.
                    "ALTER TABLE agents
                        ADD COLUMN mail_from_name VARCHAR(120) NULL,
                        ADD COLUMN mail_from_email VARCHAR(190) NULL,
                        ADD COLUMN mail_reply_to VARCHAR(190) NULL,
                        ADD COLUMN mail_smtp_host VARCHAR(190) NULL,
                        ADD COLUMN mail_smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
                        ADD COLUMN mail_smtp_encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
                        ADD COLUMN mail_smtp_username VARCHAR(190) NULL,
                        ADD COLUMN mail_smtp_password TEXT NULL,
                        ADD COLUMN mail_postal_address VARCHAR(400) NULL,
                        ADD COLUMN prospect_emails_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                        ADD COLUMN prospect_email_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
                        ADD COLUMN prospect_research_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 20,
                        ADD COLUMN prospect_research_gap_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5",
                ],
            ],

            [
                'key'   => '2026_10_prospect_campaigns',
                'label' => 'Prospecting campaigns',
                'check' => static fn(): bool => self::tableExists('prospect_campaigns'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS prospect_campaigns (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        name            VARCHAR(160) NOT NULL,
                        objective       TEXT NULL,
                        countries       TEXT NULL,      -- JSON list, in priority order; empty = anywhere
                        countries_only  TINYINT(1) NOT NULL DEFAULT 1,
                        categories      TEXT NULL,      -- JSON list, in priority order
                        categories_only TINYINT(1) NOT NULL DEFAULT 1,
                        company_types   VARCHAR(400) NULL,
                        notes           TEXT NULL,
                        status          ENUM('draft','active','paused','finished') NOT NULL DEFAULT 'draft',
                        starts_on       DATE NULL,
                        ends_on         DATE NULL,
                        max_companies   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                        approval_mode   ENUM('manual','automatic') NOT NULL DEFAULT 'manual',
                        search_cursor   INT UNSIGNED NOT NULL DEFAULT 0,
                        created_by      VARCHAR(64) NULL,
                        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        KEY idx_pc_agent (agent_id, status),
                        CONSTRAINT fk_pc_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_10_prospect_targets',
                'label' => 'Companies found by each campaign, and where each one stands',
                'check' => static fn(): bool => self::tableExists('prospect_targets'),
                'sql'   => [
                    // One company is one record; it can appear in several
                    // campaigns, each with its own progress.
                    "CREATE TABLE IF NOT EXISTS prospect_targets (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        campaign_id     INT UNSIGNED NOT NULL,
                        company_id      INT UNSIGNED NOT NULL,
                        state           VARCHAR(32) NOT NULL DEFAULT 'discovered',
                        state_by        ENUM('ai','system','team') NOT NULL DEFAULT 'system',
                        state_note      VARCHAR(400) NULL,
                        country         VARCHAR(96) NULL,
                        category        VARCHAR(160) NULL,
                        priority_rank   SMALLINT UNSIGNED NOT NULL DEFAULT 999,
                        found_url       VARCHAR(500) NULL,
                        found_reason    VARCHAR(600) NULL,
                        research_id     INT UNSIGNED NULL,
                        contact_email   VARCHAR(190) NULL,
                        contact_name    VARCHAR(160) NULL,
                        contact_title   VARCHAR(160) NULL,
                        contact_source  VARCHAR(500) NULL,
                        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_pt_campaign_company (campaign_id, company_id),
                        KEY idx_pt_state (agent_id, state),
                        KEY idx_pt_company (agent_id, company_id),
                        CONSTRAINT fk_pt_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                        CONSTRAINT fk_pt_campaign FOREIGN KEY (campaign_id) REFERENCES prospect_campaigns(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_10_outreach_emails',
                'label' => 'Prospect emails: drafts, approvals and what was sent',
                'check' => static fn(): bool => self::tableExists('outreach_emails'),
                'sql'   => [
                    "CREATE TABLE IF NOT EXISTS outreach_emails (
                        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        agent_id        INT UNSIGNED NOT NULL,
                        campaign_id     INT UNSIGNED NOT NULL,
                        target_id       INT UNSIGNED NOT NULL,
                        company_id      INT UNSIGNED NOT NULL,
                        to_email        VARCHAR(190) NOT NULL,
                        to_name         VARCHAR(160) NULL,
                        subject         VARCHAR(255) NOT NULL,
                        body            MEDIUMTEXT NOT NULL,
                        status          ENUM('draft','approved','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
                        used_fallback   TINYINT(1) NOT NULL DEFAULT 0,
                        check_note      VARCHAR(400) NULL,
                        edited_by       VARCHAR(64) NULL,
                        approved_by     VARCHAR(64) NULL,
                        approved_at     DATETIME NULL,
                        sent_at         DATETIME NULL,
                        message_id      VARCHAR(190) NULL,
                        attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        last_error      VARCHAR(400) NULL,
                        -- The mail server may have taken it: never sent again, and
                        -- counted as emailed, because a second copy is worse than none.
                        delivery_unknown TINYINT(1) NOT NULL DEFAULT 0,
                        dedupe_key      VARCHAR(96) NOT NULL,
                        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        UNIQUE KEY uq_oe_dedupe (dedupe_key),
                        KEY idx_oe_status (agent_id, status),
                        KEY idx_oe_target (target_id),
                        KEY idx_oe_to (agent_id, to_email),
                        CONSTRAINT fk_oe_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
            ],

            [
                'key'   => '2026_10_background_model',
                'label' => 'A cheaper AI model for background work',
                'check' => static fn(): bool => self::columnExists('agents', 'llm_background_model'),
                'sql'   => [
                    "ALTER TABLE agents
                        ADD COLUMN llm_background_provider VARCHAR(32) NULL,
                        ADD COLUMN llm_background_model VARCHAR(100) NULL",
                ],
            ],

            [
                'key'   => '2026_10_whatsapp_ai_retry',
                'label' => 'Answering WhatsApp a few minutes later when the AI is busy',
                'check' => static fn(): bool => self::columnExists('whatsapp_inbox', 'ai_attempts'),
                'sql'   => [
                    // A message the AI was too busy to answer waits here and is
                    // answered by the scheduled task a few minutes later.
                    "ALTER TABLE whatsapp_inbox
                        ADD COLUMN ai_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        ADD COLUMN retry_after DATETIME NULL",
                ],
            ],

            [
                'key'   => '2026_10_event_facts',
                'label' => 'Show dates, venue and booking pages for the event blogs',
                'check' => static fn(): bool => self::columnExists('agents', 'event_starts_on'),
                'sql'   => [
                    // Read by each event's blog (countdown, buttons, Google's
                    // event listing) through api/event.php. The assistant's own
                    // answers still come from approved knowledge only.
                    "ALTER TABLE agents
                        ADD COLUMN event_starts_on DATE NULL,
                        ADD COLUMN event_tagline VARCHAR(300) NULL,
                        ADD COLUMN event_venue VARCHAR(200) NULL,
                        ADD COLUMN event_city VARCHAR(120) NULL,
                        ADD COLUMN event_country VARCHAR(96) NULL,
                        ADD COLUMN register_url VARCHAR(255) NULL,
                        ADD COLUMN book_stand_url VARCHAR(255) NULL",
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Running
    // -----------------------------------------------------------------

    /**
     * A cheap check for the navigation badge.
     *
     * The full pending() check asks the database about every table and column
     * in turn, which is fine on the upgrade page and wasteful on every page
     * load. This compares what has been recorded against what exists, in one
     * query, and errs towards saying an upgrade is needed.
     */
    public static function outstandingCount(): int
    {
        $total = count(self::steps());

        if (!self::tableExists('schema_migrations')) {
            return $total;
        }

        $done = (int)db()->value('SELECT COUNT(*) FROM schema_migrations');
        return max(0, $total - $done);
    }

    /** Steps that still need to run, in order. */
    public static function pending(): array
    {
        $out = [];
        foreach (self::steps() as $step) {
            if (!($step['check'])()) {
                $out[] = $step;
            }
        }
        return $out;
    }

    /**
     * Apply everything outstanding.
     *
     * Each step is checked again immediately before it runs, so two operators
     * pressing the button at the same moment cannot apply the same change
     * twice. A failure stops the run and is reported with the step that broke,
     * leaving earlier steps applied — which is safe, because the next run
     * simply resumes from there.
     *
     * @return array{applied: string[], failed: ?string, error: ?string}
     */
    public static function run(?string $actor = null): array
    {
        $applied = [];

        foreach (self::steps() as $step) {
            if (($step['check'])()) {
                self::record((string)$step['key'], $actor);
                continue;
            }

            try {
                foreach ($step['sql'] as $sql) {
                    db()->exec($sql);
                }
            } catch (Throwable $ex) {
                return [
                    'applied' => $applied,
                    'failed'  => (string)$step['label'],
                    'error'   => $ex->getMessage(),
                ];
            }

            self::record((string)$step['key'], $actor);
            $applied[] = (string)$step['label'];

            Audit::log('schema_upgraded', null, 'migration', null,
                'Applied: ' . (string)$step['label']);
        }

        return ['applied' => $applied, 'failed' => null, 'error' => null];
    }

    private static function record(string $key, ?string $actor): void
    {
        if (!self::tableExists('schema_migrations')) {
            return;
        }
        try {
            db()->exec(
                'INSERT INTO schema_migrations (step_key, applied_by) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE step_key = step_key',
                [$key, $actor]
            );
        } catch (Throwable) {
            // The record is a convenience; the checks above are the truth.
        }
    }

    // -----------------------------------------------------------------
    // Introspection
    //
    // Asking the database what it has is more reliable than assuming, and
    // works the same on MySQL and MariaDB, which "ADD COLUMN IF NOT EXISTS"
    // does not.
    // -----------------------------------------------------------------

    public static function tableExists(string $table): bool
    {
        $found = db()->value(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        return (int)$found > 0;
    }

    public static function columnExists(string $table, string $column): bool
    {
        $found = db()->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return (int)$found > 0;
    }

    /**
     * Whether a list-of-values column already accepts a value.
     *
     * Read from the column's own definition, so it answers correctly on
     * MySQL and MariaDB alike and on a database that was upgraded by hand.
     */
    public static function enumAllows(string $table, string $column, string $value): bool
    {
        $type = db()->value(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return is_string($type) && str_contains($type, "'" . $value . "'");
    }
}
