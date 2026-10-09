<?php
declare(strict_types=1);

/**
 * Sponsor banners: positions, dates, rotation, and the views and clicks
 * that go into each sponsor's report.
 */
final class Banner
{
    public const POSITIONS = [
        'top'     => 'Top of the home page (wide)',
        'middle'  => 'Middle of the home page (wide)',
        'sidebar' => 'Sidebar next to articles (square or tall)',
        'article' => 'Inside articles, after the text (wide)',
        'footer'  => 'Above the footer on every page (wide)',
    ];

    public const SIZES = [
        'top' => '1200 × 200 px', 'middle' => '1200 × 200 px', 'footer' => '1200 × 150 px',
        'article' => '760 × 160 px', 'sidebar' => '300 × 250 px or 300 × 600 px',
    ];

    /** Banners running now in a position (all of them: the page rotates them). */
    public static function live(string $position): array
    {
        $now = now_utc();
        return db()->all("SELECT * FROM {p}banners WHERE position = ? AND active = 1 AND image_id IS NOT NULL
            AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY sort, id", [$position, $now, $now]);
    }

    public static function render(string $position): string
    {
        $list = self::live($position);
        if (!$list) {
            return '';
        }
        $html = '<aside class="sponsor sponsor--' . e($position) . '" aria-label="' . e(t('sponsor_label')) . '" data-rotate>';
        foreach ($list as $i => $b) {
            $img = Media::find((int)$b['image_id']);
            if (!$img) {
                continue;
            }
            $mobile = $b['mobile_image_id'] ? Media::find((int)$b['mobile_image_id']) : null;
            $sizes = $position === 'sidebar' ? '300px' : '(max-width: 1240px) 100vw, 1200px';
            $pic = $mobile
                ? '<picture><source media="(max-width: 640px)" srcset="' . e(Media::url($mobile, 800)) . '">' . substr(Media::picture($img, '', (string)$b['sponsor_name'], $sizes), 9)
                : Media::picture($img, '', (string)$b['sponsor_name'], $sizes);
            $html .= '<div class="sponsor__item"' . ($i > 0 ? ' hidden' : '') . ' data-banner="' . (int)$b['id'] . '">'
                . '<span class="sponsor__tag">' . e(t('sponsored')) . '</span>'
                . '<a href="' . e(url('go/b/' . (int)$b['id'])) . '" rel="sponsored noopener" target="_blank">' . $pic . '</a></div>';
        }
        return $html . '</aside>';
    }

    public static function countViews(array $ids): void
    {
        $day = gmdate('Y-m-d');
        foreach (array_slice(array_unique(array_map('intval', $ids)), 0, 10) as $id) {
            if ($id > 0) {
                db()->exec('INSERT INTO {p}banner_stats (banner_id, day, views) SELECT id, ?, 1 FROM {p}banners WHERE id = ? ON DUPLICATE KEY UPDATE views = views + 1', [$day, $id]);
            }
        }
    }

    /** Count a click and return where to send the reader. */
    public static function click(int $id): ?string
    {
        $b = db()->one('SELECT * FROM {p}banners WHERE id = ?', [$id]);
        if (!$b || !is_web_url((string)$b['link'])) {
            return null;
        }
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!preg_match('/bot|crawl|spider|preview/', $ua)) {
            db()->exec('INSERT INTO {p}banner_stats (banner_id, day, clicks) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', [$id, gmdate('Y-m-d')]);
        }
        return (string)$b['link'];
    }

    /** Totals and daily figures for one sponsor's banners between two dates. */
    public static function report(string $sponsor, string $from, string $to): array
    {
        $banners = db()->all('SELECT * FROM {p}banners WHERE sponsor_name = ? ORDER BY id', [$sponsor]);
        $ids = array_map(static fn($b) => (int)$b['id'], $banners) ?: [0];
        $days = db()->all('SELECT day, SUM(views) views, SUM(clicks) clicks FROM {p}banner_stats WHERE banner_id IN (' . implode(',', $ids) . ')
            AND day BETWEEN ? AND ? GROUP BY day ORDER BY day', [$from, $to]);
        $per = [];
        foreach (db()->all('SELECT banner_id, SUM(views) views, SUM(clicks) clicks FROM {p}banner_stats WHERE banner_id IN (' . implode(',', $ids) . ')
            AND day BETWEEN ? AND ? GROUP BY banner_id', [$from, $to]) as $r) {
            $per[(int)$r['banner_id']] = $r;
        }
        $views = array_sum(array_column($days, 'views'));
        $clicks = array_sum(array_column($days, 'clicks'));
        return ['sponsor' => $sponsor, 'from' => $from, 'to' => $to, 'banners' => $banners, 'per_banner' => $per,
            'days' => $days, 'views' => (int)$views, 'clicks' => (int)$clicks,
            'ctr' => $views > 0 ? round($clicks / $views * 100, 2) : 0.0];
    }
}
