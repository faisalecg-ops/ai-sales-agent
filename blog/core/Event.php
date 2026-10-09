<?php
declare(strict_types=1);

/**
 * The exhibition's facts (name, dates, venue, links).
 *
 * One source of truth: the sales agent's approved information, fetched once a
 * day. Anything typed in the blog admin overrides it, so the blog still works
 * when the sales agent is not connected.
 */
final class Event
{
    private const FIELDS = [
        'event_name'     => 'name',
        'event_tagline'  => 'tagline',
        'event_start'    => 'start_date',
        'event_end'      => 'end_date',
        'event_venue'    => 'venue',
        'event_city'     => 'city',
        'event_country'  => 'country',
        'main_site_url'  => 'website_url',
        'register_url'   => 'register_url',
        'book_stand_url' => 'book_stand_url',
    ];

    /** For tests: fn(string $url): ?string */
    public static $fetcher = null;

    public static function get(string $field): string
    {
        $manual = trim((string)Settings::get($field, ''));
        if ($manual !== '') {
            return $manual;
        }
        if (!Settings::get('event_facts_from_agent', true)) {
            return '';
        }
        $remote = Settings::get('event_remote', []);
        $key = self::FIELDS[$field] ?? $field;
        return is_array($remote) ? trim((string)($remote[$key] ?? '')) : '';
    }

    /** Where a value comes from, for the admin: "manual", "sales agent" or "". */
    public static function source(string $field): string
    {
        if (trim((string)Settings::get($field, '')) !== '') {
            return 'manual';
        }
        $remote = Settings::get('event_remote', []);
        return is_array($remote) && trim((string)($remote[self::FIELDS[$field] ?? $field] ?? '')) !== '' ? 'sales agent' : '';
    }

    public static function name(): string
    {
        $n = self::get('event_name');
        return $n !== '' ? $n : (string)Settings::get('site_name', 'Event Blog');
    }

    /** "{event}" in admin wording becomes the event name. */
    public static function fill(string $text): string
    {
        return str_replace('{event}', self::name(), $text);
    }

    public static function startTime(): ?int
    {
        $d = self::get('event_start');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) {
            return null;
        }
        try {
            return (new DateTimeImmutable(substr($d, 0, 10) . ' 09:00', site_tz()))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /** "14–16 January 2027" style. */
    public static function dates(): string
    {
        $s = self::get('event_start');
        $e = self::get('event_end');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return '';
        }
        $sd = new DateTimeImmutable(substr($s, 0, 10));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $e) || substr($e, 0, 10) === substr($s, 0, 10)) {
            return $sd->format('j F Y');
        }
        $ed = new DateTimeImmutable(substr($e, 0, 10));
        if ($sd->format('Y-m') === $ed->format('Y-m')) {
            return $sd->format('j') . '–' . $ed->format('j F Y');
        }
        if ($sd->format('Y') === $ed->format('Y')) {
            return $sd->format('j F') . ' – ' . $ed->format('j F Y');
        }
        return $sd->format('j F Y') . ' – ' . $ed->format('j F Y');
    }

    public static function place(): string
    {
        return implode(', ', array_filter([self::get('event_venue'), self::get('event_city')]));
    }

    public static function isUpcoming(): bool
    {
        $end = self::get('event_end') ?: self::get('event_start');
        return $end !== '' && strtotime(substr($end, 0, 10) . ' 23:59:59') >= time();
    }

    /** Fetch the latest facts from the sales agent. */
    public static function refresh(): array
    {
        $agent = trim((string)Settings::get('chat_agent', ''));
        $base = rtrim(trim((string)Settings::get('chat_base_url', '')), '/');
        if ($agent === '' || $base === '' || !Settings::get('event_facts_from_agent', true)) {
            return ['ok' => false, 'error' => 'The sales agent is not set up in Chat settings.'];
        }
        $url = $base . '/api/event.php?agent=' . rawurlencode($agent);
        $body = self::$fetcher ? (self::$fetcher)($url) : self::httpGet($url);
        $data = $body ? json_decode($body, true) : null;
        if (!is_array($data) || empty($data['ok']) || !is_array($data['event'] ?? null)) {
            return ['ok' => false, 'error' => 'The sales agent did not return event facts.'];
        }
        $clean = [];
        foreach (self::FIELDS as $remoteKey) {
            $v = $data['event'][$remoteKey] ?? '';
            $clean[$remoteKey] = is_scalar($v) ? mb_substr(trim((string)$v), 0, 300) : '';
        }
        foreach (['website_url', 'register_url', 'book_stand_url'] as $u) {
            if ($clean[$u] !== '' && !is_web_url($clean[$u])) {
                $clean[$u] = '';
            }
        }
        Settings::set('event_remote', $clean);
        Settings::set('event_remote_at', now_utc());
        Cache::clear();
        return ['ok' => true, 'event' => $clean];
    }

    private static function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'EventBlog/' . BLOG_VERSION]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 && is_string($body) ? $body : null;
    }
}
