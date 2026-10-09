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
                ['key' => 'stats',         'enabled' => true],
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
            'stats_caption'     => '',
            'stats'             => [],      // [{value, suffix, label}] — e.g. 32000, "+", "Trade visitors"
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
        // Build Asia's look (21st edition banner): navy, royal blue, amber, Archivo.
        return [
            'preset'      => 'buildasia',
            'primary'     => '#1F4FBF',   // royal blue: labels, links, the top line
            'secondary'   => '#142A5C',   // navy: dark areas, main buttons, headings
            'accent'      => '#1F4FBF',   // links inside articles
            'highlight'   => '#F7B10B',   // amber: call-to-action buttons, counters, stripe
            'highlight_ink' => '#1D1500', // text on amber
            'background'  => '#FFFFFF',
            'surface'     => '#EEF3FB',
            'text'        => '#17203A',
            'muted'       => '#55607A',
            'heading_font'=> 'archivo',
            'body_font'   => 'archivo',
            'header_style'=> 'light',     // light | dark | transparent
            'button_shape'=> 'rounded',   // square | rounded | pill
            'radius'      => 8,           // px for cards and images
            'card_style'  => 'rule',      // rule | bordered | shadow
            'slider_shade'=> 45,          // % darkening over slider images
            'band'        => true,        // the slanted stripe under the header and above the footer
            'motion'      => 'full',      // full | gentle | off (always off for "reduce motion" visitors)
        ];
    }

    public static function presets(): array
    {
        return [
            'buildasia' => ['label' => 'Build Asia',     'primary' => '#1F4FBF', 'secondary' => '#142A5C', 'accent' => '#1F4FBF', 'highlight' => '#F7B10B', 'highlight_ink' => '#1D1500', 'background' => '#FFFFFF', 'surface' => '#EEF3FB', 'text' => '#17203A', 'muted' => '#55607A'],
            'editorial' => ['label' => 'Editorial red',  'primary' => '#E3120B', 'secondary' => '#1F2E3D', 'accent' => '#006BA2', 'highlight' => '#E3120B', 'highlight_ink' => '#FFFFFF', 'background' => '#FFFFFF', 'surface' => '#F6F5F2', 'text' => '#121212', 'muted' => '#595959'],
            'navy'      => ['label' => 'Corporate navy', 'primary' => '#0B3D91', 'secondary' => '#0A1F44', 'accent' => '#0B3D91', 'highlight' => '#F2A900', 'highlight_ink' => '#1A1300', 'background' => '#FFFFFF', 'surface' => '#F3F5F9', 'text' => '#111827', 'muted' => '#4B5563'],
            'forest'    => ['label' => 'Industry green', 'primary' => '#0F7B45', 'secondary' => '#123524', 'accent' => '#0F7B45', 'highlight' => '#E0A100', 'highlight_ink' => '#1A1300', 'background' => '#FFFFFF', 'surface' => '#F2F6F3', 'text' => '#121A15', 'muted' => '#4D5A52'],
            'teal'      => ['label' => 'Health teal',    'primary' => '#00808A', 'secondary' => '#0E3B43', 'accent' => '#00808A', 'highlight' => '#F28C28', 'highlight_ink' => '#1F1000', 'background' => '#FFFFFF', 'surface' => '#F0F6F7', 'text' => '#0F1F22', 'muted' => '#4A5A5D'],
            'minimal'   => ['label' => 'Black & white',  'primary' => '#111111', 'secondary' => '#111111', 'accent' => '#1D4ED8', 'highlight' => '#111111', 'highlight_ink' => '#FFFFFF', 'background' => '#FFFFFF', 'surface' => '#F4F4F4', 'text' => '#111111', 'muted' => '#555555'],
            'graphite'  => ['label' => 'Graphite',       'primary' => '#D14900', 'secondary' => '#1C1C1E', 'accent' => '#2563EB', 'highlight' => '#D14900', 'highlight_ink' => '#FFFFFF', 'background' => '#FFFFFF', 'surface' => '#F4F4F5', 'text' => '#18181B', 'muted' => '#52525B'],
        ];
    }

    /**
     * Complete looks: colours, fonts and style together, applied in one click
     * in Branding (each can be adjusted afterwards).
     */
    public static function looks(): array
    {
        return [
            'buildasia' => ['label' => 'Build Asia', 'note' => 'Navy and amber, Archivo, slanted stripe, full motion — like buildasia.net',
                'colours' => 'buildasia', 'heading_font' => 'archivo', 'body_font' => 'archivo', 'header_style' => 'light',
                'button_shape' => 'rounded', 'radius' => 8, 'card_style' => 'rule', 'slider_shade' => 45, 'band' => true, 'motion' => 'full'],
            'corporate' => ['label' => 'Corporate', 'note' => 'Navy and gold, clean modern font, boxed cards, dark header',
                'colours' => 'navy', 'heading_font' => 'inter', 'body_font' => 'inter', 'header_style' => 'dark',
                'button_shape' => 'rounded', 'radius' => 6, 'card_style' => 'bordered', 'slider_shade' => 50, 'band' => false, 'motion' => 'gentle'],
            'editorial' => ['label' => 'Editorial', 'note' => 'Red and black, newspaper serif, fine lines — like The Economist',
                'colours' => 'editorial', 'heading_font' => 'source-serif-4', 'body_font' => 'inter', 'header_style' => 'light',
                'button_shape' => 'square', 'radius' => 2, 'card_style' => 'rule', 'slider_shade' => 45, 'band' => false, 'motion' => 'gentle'],
            'medical'   => ['label' => 'Medical / Pharma', 'note' => 'Teal and orange, friendly font, soft shadows — for Pharma Asia or Health Asia',
                'colours' => 'teal', 'heading_font' => 'source-sans-3', 'body_font' => 'source-sans-3', 'header_style' => 'light',
                'button_shape' => 'pill', 'radius' => 12, 'card_style' => 'shadow', 'slider_shade' => 40, 'band' => false, 'motion' => 'gentle'],
            'minimal'   => ['label' => 'Modern minimal', 'note' => 'Black and white, clean font, square corners, no stripe',
                'colours' => 'minimal', 'heading_font' => 'inter', 'body_font' => 'inter', 'header_style' => 'light',
                'button_shape' => 'square', 'radius' => 0, 'card_style' => 'rule', 'slider_shade' => 50, 'band' => false, 'motion' => 'gentle'],
        ];
    }

    /** A complete look as theme values. */
    public static function lookTheme(string $key): ?array
    {
        $l = self::looks()[$key] ?? null;
        if (!$l) {
            return null;
        }
        $c = self::presets()[$l['colours']];
        unset($c['label']);
        $t = array_merge(self::defaultTheme(), $c);
        foreach (['heading_font', 'body_font', 'header_style', 'button_shape', 'radius', 'card_style', 'slider_shade', 'band', 'motion'] as $k) {
            $t[$k] = $l[$k];
        }
        $t['preset'] = $l['colours'];
        return $t;
    }

    public static function fonts(): array
    {
        return [
            'archivo'          => ['label' => 'Archivo (Build Asia website)',     'stack' => "Archivo, system-ui, -apple-system, 'Segoe UI', Arial, sans-serif", 'weights' => [400, 700],
                                   'variable' => true, 'stretch' => '112%', 'head_weight' => 850],
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
        $t = array_merge(self::defaultTheme(), is_array($theme) ? $theme : []);
        // Older saves stored motion as on/off.
        if ($t['motion'] === true) {
            $t['motion'] = 'full';
        } elseif ($t['motion'] === false) {
            $t['motion'] = 'off';
        }
        if (!in_array($t['motion'], ['full', 'gentle', 'off'], true)) {
            $t['motion'] = 'full';
        }
        return $t;
    }
}
