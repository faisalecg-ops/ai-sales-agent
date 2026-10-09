<?php
/**
 * AI Sales Agent Platform — local configuration
 *
 * Copy this file to config.php and fill in real values.
 * This file MUST live outside public_html. It is never served over HTTP.
 */

return [

    // ---------------------------------------------------------------
    // Database (cPanel: Databases > MySQL Databases)
    // ---------------------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'name'    => 'cpaneluser_salesagent',
        'user'    => 'cpaneluser_sauser',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ---------------------------------------------------------------
    // Public base URL of the platform, no trailing slash.
    // Subfolder-safe: e.g. https://salesagent.ecgateway.net
    //             or  https://example.com/salesagent
    // ---------------------------------------------------------------
    'base_url' => 'https://salesagent.ecgateway.net',

    // ---------------------------------------------------------------
    // Encryption key for secrets stored in the database.
    // Generate once with:  php -r "echo bin2hex(random_bytes(32));"
    // Changing this makes previously saved API keys unreadable.
    // ---------------------------------------------------------------
    'encryption_key' => '',

    // ---------------------------------------------------------------
    // Paths
    // ---------------------------------------------------------------
    'storage_path' => __DIR__ . '/../storage',   // logs, uploaded reference files

    // ---------------------------------------------------------------
    // Environment: 'production' hides all error detail from visitors.
    // ---------------------------------------------------------------
    'environment' => 'production',

    // Show PHP errors on screen (development only — never in production)
    'debug' => false,

    // ---------------------------------------------------------------
    // Outbound mail. 'mail' uses the cPanel local mail server.
    // 'smtp' uses the SMTP settings configured in the admin panel.
    // ---------------------------------------------------------------
    'mail_transport' => 'mail',
    'mail_from'      => 'no-reply@salesagent.ecgateway.net',
    'mail_from_name' => 'AI Sales Agent Platform',

    // ---------------------------------------------------------------
    // Data retention, in days (Section 44). 0 = keep indefinitely.
    // ---------------------------------------------------------------
    'retention' => [
        'raw_ip_days'        => 365,
        'conversation_days'  => 730,
        'lead_days'          => 0,
        'error_log_days'     => 90,
        'audit_days'         => 1095,
        'rate_event_hours'   => 24,
        'request_log_days'   => 7,
    ],
];
