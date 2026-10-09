<?php
declare(strict_types=1);

/**
 * Starter content packs (content-packs/<name>/pack.json): categories,
 * researched articles with cover images and sources, and — only where the
 * blog has nothing yet — event details, logo, menus, counters, slider and
 * link library. Safe to run twice: nothing that already exists is changed.
 */
final class ContentPack
{
    public static function dir(): string
    {
        return BLOG_ROOT . '/content-packs';
    }

    /** @return array<string, array> name => pack */
    public static function available(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*/pack.json') ?: [] as $f) {
            $data = json_decode((string)file_get_contents($f), true);
            if (is_array($data) && !empty($data['articles'])) {
                $out[basename(dirname($f))] = $data;
            }
        }
        return $out;
    }

    private static function image(string $pack, string $file, string $alt = ''): ?int
    {
        $file = basename($file);
        $path = self::dir() . '/' . $pack . '/images/' . $file;
        if (!is_file($path)) {
            return null;
        }
        // Reuse an image already imported from this pack.
        $existing = db()->value('SELECT id FROM {p}media WHERE original_name = ? ORDER BY id LIMIT 1', ['pack-' . $pack . '-' . $file]);
        if ($existing) {
            return (int)$existing;
        }
        $res = Media::store($path, 'pack-' . $pack . '-' . $file, Auth::id(), null, ['alt' => $alt]);
        return $res['ok'] ? (int)$res['id'] : null;
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || $v === 0 || $v === '0';
    }

    /**
     * @param array{publish?:bool, settings?:bool} $opt
     * @return array{ok:bool, report:string[]}
     */
    public static function import(string $name, array $opt = []): array
    {
        $packs = self::available();
        if (!isset($packs[$name])) {
            return ['ok' => false, 'report' => ['That starter pack was not found.']];
        }
        $p = $packs[$name];
        $publish = $opt['publish'] ?? true;
        $withSettings = $opt['settings'] ?? true;
        $report = [];
        @set_time_limit(300);

        // Categories
        $catIds = [];
        $newCats = 0;
        foreach ($p['categories'] ?? [] as $i => $c) {
            $id = (int)db()->value('SELECT id FROM {p}categories WHERE slug = ?', [$c['slug']]);
            if (!$id) {
                $id = db()->insert('categories', ['name' => $c['name'], 'slug' => $c['slug'], 'description' => $c['description'] ?? null, 'sort' => $i]);
                $newCats++;
            }
            $catIds[$c['slug']] = $id;
        }
        $report[] = $newCats . ' new categor' . ($newCats === 1 ? 'y' : 'ies') . ' (' . (count($catIds) - $newCats) . ' already existed).';

        // Author shown on the articles
        $authorId = null;
        if (!empty($p['author']['name'])) {
            $slug = slugify((string)$p['author']['name']);
            $authorId = (int)db()->value('SELECT id FROM {p}users WHERE slug = ?', [$slug]);
            if (!$authorId) {
                $authorId = db()->insert('users', [
                    'name' => $p['author']['name'], 'slug' => $slug, 'title' => (string)($p['author']['title'] ?? ''), 'bio' => (string)($p['author']['bio'] ?? ''),
                    'email' => $slug . '@content.invalid', 'password_hash' => password_hash(random_token(32), PASSWORD_DEFAULT),
                    'role' => 'author', 'status' => 'disabled', 'show_as_author' => 1,
                ]);
                $report[] = 'Author “' . $p['author']['name'] . '” added (an author name only — it cannot sign in).';
            }
        }

        // Library links first, so article links are recognised.
        $newLinks = 0;
        foreach ($p['links'] ?? [] as $l) {
            if (!db()->value('SELECT id FROM {p}links WHERE url = ?', [$l['url']])) {
                db()->insert('links', ['label' => $l['label'], 'url' => $l['url'], 'kind' => $l['kind'] ?? 'other', 'keywords' => $l['keywords'] ?? '', 'sponsored' => 0]);
                $newLinks++;
            }
        }
        LinkPolicy::forget();
        $report[] = $newLinks . ' link(s) added to the link library.';

        // Articles
        $slugUrl = static function (string $slug) use ($p, $catIds): string {
            foreach ($p['articles'] as $a) {
                if ($a['slug'] === $slug) {
                    return url($a['category'] . '/' . $slug);
                }
            }
            return url();
        };
        $made = 0;
        $skipped = 0;
        $total = count($p['articles']);
        $now = time();
        foreach (array_values($p['articles']) as $i => $a) {
            if (db()->value('SELECT id FROM {p}articles WHERE slug = ?', [$a['slug']])) {
                $skipped++;
                continue;
            }
            $body = (string)$a['body'];
            $body = preg_replace_callback('/\{\{a:([a-z0-9-]+)\}\}/', static fn($m) => e($slugUrl($m[1])), $body) ?? $body;
            $body = str_replace('{{blog}}', e(url()), $body);
            $body = preg_replace_callback('/\{\{img:([^|}]+)\|?([^}]*)\}\}/', static function ($m) use ($name) {
                $mid = self::image($name, $m[1], $m[2]);
                $media = $mid ? Media::find($mid) : null;
                return $media ? '<img src="' . e(Media::url($media, 1200)) . '" alt="' . e($m[2]) . '" data-media-id="' . $mid . '" width="' . (int)$media['width'] . '" height="' . (int)$media['height'] . '">' : '';
            }, $body) ?? $body;
            $sources = $a['sources'] ?? [];
            if ($sources) {
                $body .= '<h2>Sources</h2><ul>';
                foreach ($sources as $s) {
                    $body .= '<li><a href="' . e($s['url']) . '">' . e($s['title']) . '</a></li>';
                }
                $body .= '</ul>';
            }
            $imageId = !empty($a['image']) ? self::image($name, (string)$a['image'], (string)$a['title']) : null;
            $id = Article::save(null, [
                'title' => $a['title'], 'slug' => $a['slug'], 'excerpt' => $a['excerpt'] ?? '', 'body' => $body,
                'category_id' => $catIds[$a['category']] ?? null, 'author_id' => $authorId ?: Auth::id(), 'image_id' => $imageId,
                'is_featured' => !empty($a['featured']) ? 1 : 0, 'featured_order' => (int)($a['featured'] ?? 0),
                'sources' => json_encode(array_column($sources, 'url'), JSON_UNESCAPED_SLASHES),
            ], $a['tags'] ?? [], 'Imported from the starter pack');
            if ($publish) {
                Article::transition($id, 'published', null, '');
                // Newest first in the order the pack lists them.
                db()->update('articles', ['published_at' => gmdate('Y-m-d H:i:s', $now - ($i + 1) * 300)], 'id = ?', [$id]);
            } else {
                Article::transition($id, 'pending', null, 'Starter article — review before publishing.');
            }
            $made++;
        }
        $report[] = $made . ' article(s) ' . ($publish ? 'published' : 'added, awaiting approval') . ($skipped ? '; ' . $skipped . ' already existed and were left as they are' : '') . ' (of ' . $total . ').';

        if ($withSettings) {
            $filled = [];
            foreach ($p['settings_if_empty'] ?? [] as $k => $v) {
                $current = Settings::get($k, '');
                $default = Settings::defaults()[$k] ?? null;
                if ($current !== $v && (self::isEmpty($current) || $current === $default)) {
                    Settings::set($k, $v);
                    $filled[] = $k;
                }
            }
            if (!empty($p['social_links'])) {
                $social = (array)Settings::get('social_links', []);
                foreach ($p['social_links'] as $k => $v) {
                    if (self::isEmpty($social[$k] ?? '')) {
                        $social[$k] = $v;
                        $filled[] = 'social:' . $k;
                    }
                }
                Settings::set('social_links', $social);
            }
            if (!empty($p['stats']) && !array_filter((array)Settings::get('stats', []))) {
                Settings::set('stats', $p['stats']);
                $filled[] = 'counters';
            }
            foreach ($p['branding'] ?? [] as $k => $file) {
                if ((int)Settings::get($k, 0) <= 0 && ($mid = self::image($name, $file, Event::name()))) {
                    Settings::set($k, $mid);
                    $filled[] = $k;
                }
            }
            $menu = static fn(array $items) => array_map(static fn($m) => ['label' => $m['label'], 'url' => isset($m['cat']) ? url($m['cat'] . '/') : $m['url']], $items);
            if (!empty($p['main_menu']) && !Settings::get('main_menu', [])) {
                Settings::set('main_menu', $menu($p['main_menu']));
                $filled[] = 'header menu';
            }
            if (!empty($p['footer_menu']) && !Settings::get('footer_menu', [])) {
                Settings::set('footer_menu', $menu($p['footer_menu']));
                $filled[] = 'footer menu';
            }
            if (!empty($p['slides']) && !(int)db()->value('SELECT COUNT(*) FROM {p}slides')) {
                foreach ($p['slides'] as $i => $s) {
                    db()->insert('slides', ['image_id' => self::image($name, $s['image'], $s['headline']), 'headline' => $s['headline'], 'text' => $s['text'] ?? '',
                        'button_label' => $s['button_label'] ?? '', 'button_url' => str_replace('{{blog}}', url(), (string)($s['button_url'] ?? '')),
                        'ends_at' => $s['ends_at'] ?? null, 'sort' => $i]);
                }
                $filled[] = 'slider';
            }
            $report[] = $filled ? 'Filled where empty: ' . implode(', ', $filled) . '.' : 'Settings: nothing filled — everything already had a value.';
        }
        Cache::clear();
        Activity::log('content_pack.imported', (string)$p['name'], ['articles' => $made]);
        return ['ok' => true, 'report' => $report];
    }
}
