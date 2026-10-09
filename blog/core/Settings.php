<?php
declare(strict_types=1);

/**
 * Site settings, edited in the admin and stored as JSON values in {p}settings.
 * Every option the owner asked to control from the admin lives here, with a
 * sensible default so a fresh install already looks and works right.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            // General
            'site_url'           => '',
            'site_name'          => 'Event Blog',
            'site_tagline'       => 'Industry news, insights and ideas',
            'timezone'           => 'Asia/Karachi',
            'language'           => 'en',
            'posts_per_page'     => 12,
            'hide_from_search'   => false,   // for a review copy that Google must not index

            // Event identity (manual values override the sales agent's facts)
            'event_name'         => '',
            'event_tagline'      => '',
            'event_start'        => '',      // Y-m-d
            'event_end'          => '',
            'event_venue'        => '',
            'event_city'         => '',
            'event_country'      => 'Pakistan',
            'event_about'        => '',      // one line used in the courtesy box
            'main_site_url'      => '',
            'register_url'       => '',
            'book_stand_url'     => '',
            'event_remote'       => [],      // last facts fetched from the sales agent
            'event_remote_at'    => '',
            'contact_address'    => '',
            'contact_phone'      => '',
            'contact_email'      => '',
            'social_links'       => ['facebook' => '', 'linkedin' => '', 'instagram' => '', 'x' => '', 'youtube' => '', 'tiktok' => ''],

            // Courtesy links to the main event website
            'header_button_enabled' => true,
            'header_button_text'    => 'Visit {event} ↗',
            'courtesy_enabled'      => true,
            'courtesy_title'        => 'Courtesy of {event}',
            'courtesy_text'         => '',
            'footer_credit_enabled' => true,
            'footer_credit_text'    => 'Blog by {event}',

            // Branding
            'theme' => self::defaultTheme(),
            'theme_previous' => null,
            'logo_light'   => 0,
            'logo_dark'    => 0,
            'logo_footer'  => 0,
            'favicon'      => 0,
            'share_image'  => 0,

            // Home page
            'home_sections' => [
                ['key' => 'slider',        'enabled' => true],
                ['key' => 'banner_top',    'enabled' => true],
                ['key' => 'featured',      'enabled' => true],
                ['key' => 'event_panel',   'enabled' => true],
                ['key' => 'latest',        'enabled' => true],
                ['key' => 'banner_middle', 'enabled' => true],
                ['key' => 'categories',    'enabled' => true],
                ['key' => 'most_read',     'enabled' => true],
                ['key' => 'tags',          'enabled' => true],
                ['key' => 'newsletter',    'enabled' => true],
            ],
            'slider_transition' => 'fade',   // fade | slide
            'slider_speed'      => 6,        // seconds per slide
            'home_category_ids' => [],       // empty = all categories with articles
            'featured_count'    => 3,
            'latest_count'      => 6,

            // Menus
            'main_menu'   => [],
            'footer_menu' => [],

            // Reader features
            'comments_mode'      => 'off',   // off | approval
            'newsletter_enabled' => false,
            'newsletter_title'   => 'Industry insights in your inbox',
            'newsletter_text'    => 'Occasional updates on the industry and the exhibition. Unsubscribe at any time.',
            'newsletter_consent' => 'I agree to receive email updates and can unsubscribe at any time.',
            'show_reading_time'  => true,
            'show_share_buttons' => true,

            // Sales agent chat
            'chat_enabled'  => false,
            'chat_agent'    => '',
            'chat_base_url' => 'https://salesagent.ecgateway.net',
            'event_facts_from_agent' => true,

            // SEO
            'seo_home_title'       => '',
            'seo_home_description' => '',
            'gsc_verification'     => '',
            'organization_name'    => '',

            // Links
            'max_links_warning' => 8,

            // API webhook to the Social Media Agent
            'webhook_url'    => '',
            'webhook_secret' => '',

            // Email (newsletter confirmations)
            'mail_from_name'      => '',
            'mail_from_email'     => '',
            'mail_transport'      => 'mail',  // mail | smtp
            'smtp_host'           => '',
            'smtp_port'           => 587,
            'smtp_encryption'     => 'tls',
            'smtp_username'       => '',
            'smtp_password'       => '',

            // Internal
            'schema_version' => 0,
            'last_cron'      => '',
            'last_link_check'=> '',
        ];
    }

    public static function defaultTheme(): array
    {
        return [
            'preset'      => 'editorial',
            'primary'     => '#E3120B',
            'secondary'   => '#1F2E3D',
            'accent'      => '#006BA2',
            'background'  => '#FFFFFF',
            'surface'     => '#F6F5F2',
            'text'        => '#121212',
            'muted'       => '#595959',
            'heading_font'=> 'source-serif-4',
            'body_font'   => 'inter',
            'header_style'=> 'light',     // light | dark | transparent
            'button_shape'=> 'square',    // square | rounded | pill
            'radius'      => 2,           // px for cards and images
            'card_style'  => 'rule',      // rule | bordered | shadow
            'slider_shade'=> 45,          // % darkening over slider images
        ];
    }

    public static function presets(): array
    {
        return [
            'editorial' => ['label' => 'Editorial red',  'primary' => '#E3120B', 'secondary' => '#1F2E3D', 'accent' => '#006BA2', 'background' => '#FFFFFF', 'surface' => '#F6F5F2', 'text' => '#121212', 'muted' => '#595959'],
            'navy'      => ['label' => 'Corporate navy', 'primary' => '#0B3D91', 'secondary' => '#0A1F44', 'accent' => '#F2A900', 'background' => '#FFFFFF', 'surface' => '#F3F5F9', 'text' => '#111827', 'muted' => '#4B5563'],
            'forest'    => ['label' => 'Industry green', 'primary' => '#0F7B45', 'secondary' => '#123524', 'accent' => '#C98A00', 'background' => '#FFFFFF', 'surface' => '#F2F6F3', 'text' => '#121A15', 'muted' => '#4D5A52'],
            'teal'      => ['label' => 'Health teal',    'primary' => '#00808A', 'secondary' => '#0E3B43', 'accent' => '#D9480F', 'background' => '#FFFFFF', 'surface' => '#F0F6F7', 'text' => '#0F1F22', 'muted' => '#4A5A5D'],
            'graphite'  => ['label' => 'Graphite',       'primary' => '#D14900', 'secondary' => '#1C1C1E', 'accent' => '#2563EB', 'background' => '#FFFFFF', 'surface' => '#F4F4F5', 'text' => '#18181B', 'muted' => '#52525B'],
        ];
    }

    public static function fonts(): array
    {
        return [
            'source-serif-4'   => ['label' => 'Source Serif (classic newspaper)', 'stack' => "'Source Serif 4', Georgia, 'Times New Roman', serif", 'weights' => [400, 600, 700]],
            'playfair-display' => ['label' => 'Playfair Display (elegant)',       'stack' => "'Playfair Display', Georgia, serif",               'weights' => [400, 600, 700]],
            'merriweather'     => ['label' => 'Merriweather (sturdy serif)',      'stack' => "Merriweather, Georgia, serif",                     'weights' => [400, 600, 700]],
            'lora'             => ['label' => 'Lora (warm serif)',                'stack' => "Lora, Georgia, serif",                             'weights' => [400, 600, 700]],
            'inter'            => ['label' => 'Inter (clean modern)',             'stack' => "Inter, system-ui, -apple-system, 'Segoe UI', Arial, sans-serif", 'weights' => [400, 600, 700]],
            'source-sans-3'    => ['label' => 'Source Sans (friendly)',           'stack' => "'Source Sans 3', system-ui, Arial, sans-serif",    'weights' => [400, 600, 700]],
            'open-sans'        => ['label' => 'Open Sans (neutral)',              'stack' => "'Open Sans', system-ui, Arial, sans-serif",        'weights' => [400, 600, 700]],
            'lato'             => ['label' => 'Lato (rounded)',                   'stack' => "Lato, system-ui, Arial, sans-serif",               'weights' => [400, 700]],
        ];
    }

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (db()->all('SELECT name, value FROM {p}settings') as $row) {
                self::$cache[$row['name']] = json_decode((string)$row['value'], true);
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::load();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        $defaults = self::defaults();
        return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        db()->exec('INSERT INTO {p}settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $json]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set((string)$k, $v);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** The theme with any missing keys filled from the defaults. */
    public static function theme(): array
    {
        $theme = self::get('theme');
        return array_merge(self::defaultTheme(), is_array($theme) ? $theme : []);
    }
}
