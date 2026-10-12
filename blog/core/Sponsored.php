<?php
declare(strict_types=1);

/**
 * Sponsored articles (paid content): the label and disclosure readers see,
 * the paid period, links marked for Google, click counting, and the report
 * for the sponsor. An article is sponsored when it has a sponsor name.
 */
final class Sponsored
{
    public static function is(array $a): bool
    {
        return trim((string)($a['sponsor_name'] ?? '')) !== '';
    }

    /** "Sponsored" or "Partner content" (wording set in the admin). */
    public static function label(array $a): string
    {
        $partner = ($a['sponsor_label'] ?? 'sponsored') === 'partner';
        $text = trim((string)Settings::get($partner ? 'partner_text' : 'sponsored_text', ''));
        return $text !== '' ? $text : ($partner ? 'Partner content' : 'Sponsored');
    }

    /** The line that tells readers the article is paid for. */
    public static function disclosure(array $a): string
    {
        $key = ($a['sponsor_label'] ?? 'sponsored') === 'partner' ? 'partner_disclosure' : 'sponsored_disclosure';
        return strtr((string)Settings::get($key, ''), ['{sponsor}' => (string)$a['sponsor_name'], '{event}' => Event::name()]);
    }

    /** Inside its paid period (no dates = always). */
    public static function running(array $a, ?string $now = null): bool
    {
        $now ??= now_utc();
        return self::is($a) && (empty($a['sponsor_starts']) || $a['sponsor_starts'] <= $now) && (empty($a['sponsor_ends']) || $a['sponsor_ends'] >= $now);
    }

    /** What is missing before a sponsored article can be published. */
    public static function problems(array $a): array
    {
        if (!self::is($a)) {
            return [];
        }
        $p = [];
        if (trim((string)$a['sponsor_url']) !== '' && !is_web_url((string)$a['sponsor_url'])) {
            $p[] = 'The sponsor\'s website must be a full address starting with https://';
        }
        if (!empty($a['sponsor_starts']) && !empty($a['sponsor_ends']) && $a['sponsor_ends'] <= $a['sponsor_starts']) {
            $p[] = 'The sponsorship must end after it starts.';
        }
        return $p;
    }

    /**
     * Every link to another website in a sponsored article: marked "sponsored"
     * for Google and sent through the blog so clicks are counted.
     */
    public static function trackLinks(string $html, int $articleId): string
    {
        return (string)preg_replace_callback('#<a\b([^>]*)>#i', static function ($m) use ($articleId) {
            $attrs = $m[1];
            if (!preg_match('#\bhref="([^"]+)"#i', $attrs, $h)) {
                return $m[0];
            }
            $href = html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5);
            if (!is_web_url($href) || is_internal_url($href)) {
                return $m[0];
            }
            $attrs = preg_replace('#\bhref="[^"]*"#i', 'href="' . e(self::goUrl($articleId, $href)) . '"', $attrs);
            $attrs = preg_replace('#\s*\brel="[^"]*"#i', '', $attrs);
            $attrs = preg_replace('#\s*\btarget="[^"]*"#i', '', $attrs);
            return '<a' . $attrs . ' rel="sponsored noopener" target="_blank">';
        }, $html);
    }

    /** A signed counting address, so it cannot be used to send people anywhere else. */
    public static function goUrl(int $articleId, string $to): string
    {
        return url('go/a/' . $articleId) . '?u=' . rawurlencode($to) . '&s=' . substr(hash_hmac('sha256', $articleId . '|' . $to, app_key()), 0, 20);
    }

    /** Count a click from a sponsored article and return where to send the reader. */
    public static function click(int $articleId, string $to, string $sig): ?string
    {
        if (!is_web_url($to) || !hash_equals(substr(hash_hmac('sha256', $articleId . '|' . $to, app_key()), 0, 20), $sig)) {
            return null;
        }
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua !== '' && !preg_match('/bot|crawl|spider|preview|slurp|facebookexternalhit|whatsapp|linkedin/', $ua)) {
            db()->exec('INSERT INTO {p}article_clicks (article_id, day, clicks) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', [$articleId, gmdate('Y-m-d')]);
        }
        return $to;
    }

    /** Sponsored articles currently in their paid period, newest first (home page section). */
    public static function runningList(int $limit = 4): array
    {
        $now = now_utc();
        return Article::published("a.sponsor_name <> '' AND (a.sponsor_starts IS NULL OR a.sponsor_starts <= ?) AND (a.sponsor_ends IS NULL OR a.sponsor_ends >= ?)",
            [$now, $now], 'a.published_at DESC', $limit);
    }

    /** At the end of the paid period, articles set to "take it off" are archived. Runs from cron. */
    public static function endDue(): int
    {
        $n = 0;
        foreach (db()->all("SELECT id FROM {p}articles WHERE status = 'published' AND sponsor_name <> '' AND sponsor_after = 'archive'
                AND sponsor_ends IS NOT NULL AND sponsor_ends < ?", [now_utc()]) as $r) {
            if (Article::transition((int)$r['id'], 'archived', null, 'Sponsorship ended')['ok']) {
                $n++;
            }
        }
        return $n;
    }

    /** Views and clicks of one sponsored article between two dates (Y-m-d). */
    public static function report(int $articleId, string $from, string $to): array
    {
        $a = Article::find($articleId) ?? [];
        $views = [];
        foreach (db()->all('SELECT day, views FROM {p}article_views WHERE article_id = ? AND day BETWEEN ? AND ?', [$articleId, $from, $to]) as $r) {
            $views[(string)$r['day']] = (int)$r['views'];
        }
        $clicks = [];
        foreach (db()->all('SELECT day, clicks FROM {p}article_clicks WHERE article_id = ? AND day BETWEEN ? AND ?', [$articleId, $from, $to]) as $r) {
            $clicks[(string)$r['day']] = (int)$r['clicks'];
        }
        $days = [];
        foreach (array_unique(array_merge(array_keys($views), array_keys($clicks))) as $d) {
            $days[] = ['day' => $d, 'views' => $views[$d] ?? 0, 'clicks' => $clicks[$d] ?? 0];
        }
        usort($days, static fn($x, $y) => strcmp($x['day'], $y['day']));
        $v = array_sum($views);
        $c = array_sum($clicks);
        return ['article' => $a, 'from' => $from, 'to' => $to, 'days' => $days, 'views' => $v, 'clicks' => $c,
            'ctr' => $v > 0 ? round($c / $v * 100, 2) : 0.0];
    }

    /** The paid period as report dates, or this month so far. */
    public static function defaultPeriod(array $a): array
    {
        $from = !empty($a['sponsor_starts']) ? substr((string)$a['sponsor_starts'], 0, 10) : (!empty($a['published_at']) ? substr((string)$a['published_at'], 0, 10) : gmdate('Y-m-01'));
        $to = !empty($a['sponsor_ends']) && $a['sponsor_ends'] < now_utc() ? substr((string)$a['sponsor_ends'], 0, 10) : gmdate('Y-m-d');
        return [$from, max($from, $to)];
    }
}
