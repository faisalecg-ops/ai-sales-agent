<?php
declare(strict_types=1);

/**
 * Links inside articles.
 *
 * Allowed: links in the event's link library, other published articles of
 * this blog, and sources actually used (the social agent declares them).
 * Before approval the editor sees every link with its kind; anything not
 * from those three is flagged. Articles arriving through the API may not be
 * published automatically while a flagged link remains.
 */
final class LinkPolicy
{
    private static ?array $library = null;

    public static function normalise(string $url): string
    {
        $url = Html::stripTracking(trim($url));
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $host = preg_replace('/^www\./', '', strtolower($p['host']));
        $path = rtrim($p['path'] ?? '', '/');
        return $host . $path . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    public static function library(): array
    {
        if (self::$library === null || defined('BLOG_TESTING')) {
            self::$library = [];
            foreach (db()->all('SELECT * FROM {p}links WHERE active = 1 ORDER BY kind, label') as $row) {
                self::$library[self::normalise((string)$row['url'])] = $row;
            }
        }
        return self::$library;
    }

    public static function forget(): void
    {
        self::$library = null;
    }

    public static function isSponsored(string $url): bool
    {
        try {
            $row = self::library()[self::normalise($url)] ?? null;
        } catch (Throwable) {
            return false;
        }
        return $row !== null && (int)$row['sponsored'] === 1;
    }

    /** The published article an address points to, if any. */
    public static function articleFor(string $url): ?array
    {
        if (!is_internal_url($url)) {
            return null;
        }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($parts) !== 2) {
            return null;
        }
        return db()->one("SELECT id, title, slug FROM {p}articles WHERE slug = ? AND status = 'published'", [$parts[1]]);
    }

    /**
     * Every link in an article, each with its kind and whether it is allowed.
     *
     * @param string[] $sources addresses the writer declared as sources
     * @return array{links: array, flagged: int, count: int, warnings: string[]}
     */
    public static function review(string $html, array $sources = []): array
    {
        $sourceSet = [];
        foreach ($sources as $s) {
            if (is_string($s) && $s !== '') {
                $sourceSet[self::normalise($s)] = true;
            }
        }
        $library = self::library();
        $mainHost = preg_replace('/^www\./', '', strtolower((string)parse_url((string)Settings::get('main_site_url', ''), PHP_URL_HOST)));
        $rows = [];
        $flagged = 0;
        foreach (Html::links($html) as $link) {
            $norm = self::normalise($link['url']);
            $kind = 'unknown';
            $label = '';
            if (isset($library[$norm])) {
                $kind = 'library';
                $label = (string)$library[$norm]['label'];
            } elseif ($a = self::articleFor($link['url'])) {
                $kind = 'article';
                $label = (string)$a['title'];
            } elseif (isset($sourceSet[$norm])) {
                $kind = 'source';
            } elseif (is_internal_url($link['url'])) {
                $kind = 'blog_page';
            } elseif ($mainHost !== '' && preg_replace('/^www\./', '', strtolower((string)parse_url($link['url'], PHP_URL_HOST))) === $mainHost) {
                $kind = 'event_site';
            }
            $allowed = in_array($kind, ['library', 'article', 'source'], true);
            if (!$allowed) {
                $flagged++;
            }
            $rows[] = $link + ['kind' => $kind, 'label' => $label, 'allowed' => $allowed,
                'external' => !is_internal_url($link['url']), 'sponsored' => isset($library[$norm]) && (int)$library[$norm]['sponsored'] === 1];
        }
        $warnings = [];
        $n = count($rows);
        $words = str_word_count(plain_text($html));
        if ($words > 300 && $n < 3) {
            $warnings[] = 'Usually an article has 3–6 links. This one has ' . $n . '.';
        }
        if ($n > (int)Settings::get('max_links_warning', 8)) {
            $warnings[] = 'This article has ' . $n . ' links — that is more than usual. Consider removing some.';
        }
        if ($flagged > 0) {
            $warnings[] = $flagged . ' link(s) are not in the link library, not other articles of this blog, and not declared sources. Check them before approving.';
        }
        return ['links' => $rows, 'flagged' => $flagged, 'count' => $n, 'warnings' => $warnings];
    }

    /** Library links and related articles worth linking from this text. */
    public static function suggest(string $html, ?int $articleId = null, ?int $categoryId = null): array
    {
        $text = mb_strtolower(plain_text($html));
        $already = [];
        foreach (Html::links($html) as $l) {
            $already[self::normalise($l['url'])] = true;
        }
        $out = [];
        foreach (self::library() as $norm => $row) {
            if (isset($already[$norm])) {
                continue;
            }
            $words = array_filter(array_map('trim', explode(',', mb_strtolower((string)$row['keywords'] . ',' . $row['label']))));
            foreach ($words as $w) {
                if (mb_strlen($w) >= 4 && str_contains($text, $w)) {
                    $out[] = ['url' => $row['url'], 'label' => $row['label'], 'kind' => 'library', 'phrase' => $w];
                    break;
                }
            }
        }
        $sql = "SELECT a.id, a.title, a.slug, c.slug AS cat FROM {p}articles a LEFT JOIN {p}categories c ON c.id = a.category_id
                WHERE a.status = 'published'" . ($articleId ? ' AND a.id <> ' . (int)$articleId : '');
        $params = [];
        if ($categoryId) {
            $sql .= ' AND a.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY a.published_at DESC LIMIT 6';
        foreach (db()->all($sql, $params) as $a) {
            $u = Article::urlFor($a);
            if (!isset($already[self::normalise($u)])) {
                $out[] = ['url' => $u, 'label' => $a['title'], 'kind' => 'article', 'phrase' => ''];
            }
        }
        return $out;
    }
}
