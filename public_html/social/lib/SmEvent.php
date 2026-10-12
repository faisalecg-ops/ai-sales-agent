<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * One exhibition as /social sees it: the sales agent's facts (read-only) plus
 * /social's own settings for it.
 */
final class SmEvent
{
    /** The content types, in the order the owner listed them. */
    public const PILLARS = [
        'insight'     => ['Industry insight', 'Market data, materials, technology and practical guides, in an industry-welfare tone.'],
        'opportunity' => ['Growth sectors and opportunity', 'Demand, gaps, incentives and partners already active in the event\'s sectors — no explicit investment pitch.'],
        'news'        => ['Exhibition news', 'Dates, conference, pavilions, deadlines and results.'],
        'spotlight'   => ['Exhibitor and partner spotlights', '"Meet who\'s exhibiting".'],
        'visitor'     => ['Visitor help', 'Countdown, how to register, travel and what to see.'],
        'curated'     => ['Curated news', 'Other people\'s articles shared as a highlight, review or comment, in our own words with a credited link.'],
        'voices'      => ['Industry voices', 'Articles by industry leaders.'],
    ];

    public const PLATFORMS = [
        'linkedin'  => 'LinkedIn',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'x'         => 'X',
    ];

    /** Defaults for every setting on an exhibition's settings page. */
    public static function defaults(): array
    {
        return [
            'articles_per_week' => 2,
            'mix'               => ['insight' => 30, 'opportunity' => 20, 'news' => 20, 'spotlight' => 10, 'visitor' => 10, 'curated' => 10, 'voices' => 0],
            'sectors'           => '',
            'audience'          => 'Builders, contractors, architects, engineers, suppliers, dealers, investors and government buyers.',
            'voice'             => 'Neutral, helpful and industry-welfare: plain British English, no hype, facts before opinions.',
            'avoid'             => '',
            'allow_prices'      => false,
            'writing_model'     => 'main',
            'text_limit'        => 120,
            'image_limit'       => 20,
            'ai_images'         => true,
            'reminder_emails'   => '',
            'blog_link_default' => true,
            'blog_written_posts'=> true,
            'lead_minutes'      => 30,
            'brand_primary'     => '#142A5C',
            'brand_secondary'   => '#1F4FBF',
            'brand_accent'      => '#F7B10B',
            'logo_media_id'     => 0,
        ];
    }

    public static function settings(int $agentId): array
    {
        $raw = db()->value('SELECT settings FROM sm_events WHERE agent_id = ?', [$agentId]);
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $out = array_replace(self::defaults(), $saved);
        $out['mix'] = array_replace(self::defaults()['mix'], (array)($saved['mix'] ?? []));
        return $out;
    }

    public static function saveSettings(int $agentId, array $settings): void
    {
        self::row($agentId);
        db()->update('sm_events', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)], 'agent_id = ?', [$agentId]);
    }

    /** /social's own row for the exhibition, created on first use. */
    public static function row(int $agentId): array
    {
        $row = db()->one('SELECT * FROM sm_events WHERE agent_id = ?', [$agentId]);
        if ($row === null) {
            db()->exec('INSERT IGNORE INTO sm_events (agent_id, settings) VALUES (?, ?)', [$agentId, json_encode(self::defaults())]);
            foreach (array_keys(self::PLATFORMS) as $i => $p) {
                db()->exec('INSERT IGNORE INTO sm_platforms (agent_id, platform, enabled, default_time) VALUES (?, ?, ?, ?)',
                    [$agentId, $p, $p === 'x' ? 0 : 1, ['11:00', '13:00', '18:00', '12:00'][$i]]);
            }
            $row = db()->one('SELECT * FROM sm_events WHERE agent_id = ?', [$agentId]) ?? [];
        }
        return $row;
    }

    /** @return array<string,array> keyed by platform */
    public static function platforms(int $agentId, bool $enabledOnly = false): array
    {
        self::row($agentId);
        $out = [];
        foreach (db()->all('SELECT * FROM sm_platforms WHERE agent_id = ?', [$agentId]) as $p) {
            if (!$enabledOnly || (int)$p['enabled'] === 1) {
                $out[(string)$p['platform']] = $p;
            }
        }
        uksort($out, static fn($a, $b) => array_search($a, array_keys(self::PLATFORMS), true) <=> array_search($b, array_keys(self::PLATFORMS), true));
        return $out;
    }

    /** The sales agent's facts about the event, read-only. */
    public static function facts(array $agent): array
    {
        $start = (string)($agent['event_starts_on'] ?? '');
        $end = (string)($agent['event_ends_on'] ?? '');
        return [
            'name'      => (string)$agent['name'],
            'tagline'   => (string)($agent['event_tagline'] ?? ''),
            'starts'    => $start,
            'ends'      => $end,
            'dates'     => self::dateRange($start, $end),
            'venue'     => trim(implode(', ', array_filter([(string)($agent['event_venue'] ?? ''), (string)($agent['event_city'] ?? ''), (string)($agent['event_country'] ?? '')]))),
            'website'   => (string)($agent['website_url'] ?? ''),
            'register'  => (string)($agent['register_url'] ?? ''),
            'stand'     => (string)($agent['book_stand_url'] ?? ''),
            'timezone'  => trim((string)($agent['notify_timezone'] ?? '')) ?: 'Asia/Karachi',
        ];
    }

    public static function dateRange(string $start, string $end): string
    {
        if ($start === '') {
            return '';
        }
        $s = strtotime($start);
        $e = $end !== '' ? strtotime($end) : $s;
        if (!$s) {
            return '';
        }
        if (date('Y-m', $s) === date('Y-m', (int)$e)) {
            return date('j', $s) . ($e !== $s ? '–' . date('j', (int)$e) : '') . ' ' . date('F Y', $s);
        }
        return date('j F', $s) . ' – ' . date('j F Y', (int)$e);
    }

    /**
     * Where the event is in its life, which decides which content types lead.
     * @return array{key:string,label:string,lead:list<string>}
     */
    public static function phase(array $agent, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime((string)($agent['event_starts_on'] ?? '')) ?: 0;
        $end = strtotime((string)($agent['event_ends_on'] ?? '')) ?: $start;
        if ($start === 0) {
            return ['key' => 'unknown', 'label' => 'Event dates not set', 'lead' => ['insight', 'opportunity', 'news']];
        }
        $days = (int)floor(($start - $now) / 86400);
        if ($now > $end + 86400) {
            return ['key' => 'after', 'label' => 'After the show', 'lead' => ['news', 'insight', 'opportunity']];
        }
        if ($now >= $start) {
            return ['key' => 'show', 'label' => 'Show days', 'lead' => ['news', 'visitor', 'spotlight']];
        }
        if ($days <= 31) {
            return ['key' => 'final', 'label' => 'Last month before the show', 'lead' => ['visitor', 'news', 'spotlight']];
        }
        if ($days <= 92) {
            return ['key' => 'build', 'label' => 'Three months before the show', 'lead' => ['spotlight', 'news', 'visitor', 'insight']];
        }
        return ['key' => 'early', 'label' => 'Six months or more before the show', 'lead' => ['insight', 'opportunity', 'news']];
    }

    /** The exhibition's local time zone. */
    public static function tz(array $agent): DateTimeZone
    {
        try {
            return new DateTimeZone(self::facts($agent)['timezone']);
        } catch (Throwable) {
            return new DateTimeZone('Asia/Karachi');
        }
    }
}
