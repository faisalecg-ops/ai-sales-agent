<?php
declare(strict_types=1);

/**
 * Everything search engines and social networks read: titles,
 * descriptions, canonical addresses, share previews (Open Graph and X
 * cards), structured data (JSON-LD), the sitemap and the RSS feed.
 */
final class Seo
{
    public static function siteName(): string
    {
        return (string)Settings::get('site_name', 'Event Blog');
    }

    public static function logoUrl(): string
    {
        $m = Media::find((int)Settings::get('logo_light', 0));
        return $m ? Media::url($m, 400) : '';
    }

    public static function defaultShareImage(): ?array
    {
        return Media::find((int)Settings::get('share_image', 0)) ?: Media::find((int)Settings::get('logo_light', 0));
    }

    /**
     * The <head> tags for a page.
     * @param array{title:string, description?:string, canonical:string, type?:string, image?:?array,
     *              published?:?string, modified?:?string, author?:string, section?:string, tags?:array,
     *              noindex?:bool, prev?:?string, next?:?string} $p
     */
    public static function head(array $p): string
    {
        $site = self::siteName();
        $title = trim($p['title']);
        $full = $title === '' || $title === $site ? $site : $title . ' | ' . $site;
        $desc = excerpt(trim((string)($p['description'] ?? '')), 160);
        $img = $p['image'] ?? self::defaultShareImage();
        $noindex = !empty($p['noindex']) || Settings::get('hide_from_search', false);

        $h = '<title>' . e($full) . '</title>';
        if ($desc !== '') {
            $h .= '<meta name="description" content="' . e($desc) . '">';
        }
        $h .= '<link rel="canonical" href="' . e($p['canonical']) . '">';
        $h .= '<meta name="robots" content="' . ($noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large') . '">';
        foreach (['prev', 'next'] as $rel) {
            if (!empty($p[$rel])) {
                $h .= '<link rel="' . $rel . '" href="' . e($p[$rel]) . '">';
            }
        }
        $og = [
            'og:site_name' => $site, 'og:title' => $title ?: $site, 'og:type' => $p['type'] ?? 'website',
            'og:url' => $p['canonical'], 'og:locale' => str_replace('-', '_', Lang::locale() === 'en' ? 'en_US' : Lang::locale()),
        ];
        if ($desc !== '') {
            $og['og:description'] = $desc;
        }
        if ($img) {
            $og['og:image'] = Media::url($img, 1200);
            $og['og:image:alt'] = (string)($img['alt'] ?: $title);
            $list = json_decode((string)$img['sizes'], true) ?: [];
            $w = 0;
            $hgt = 0;
            foreach ($list as $sw => $s) {
                if ((int)$sw >= 1200 || !$w) {
                    $w = (int)$sw;
                    $hgt = (int)($s['h'] ?? 0);
                    if ((int)$sw >= 1200) {
                        break;
                    }
                }
            }
            if ($w && $hgt) {
                $og['og:image:width'] = (string)$w;
                $og['og:image:height'] = (string)$hgt;
            }
        }
        if (($p['type'] ?? '') === 'article') {
            if (!empty($p['published'])) {
                $og['article:published_time'] = gmdate('c', strtotime($p['published'] . ' UTC'));
            }
            if (!empty($p['modified'])) {
                $og['article:modified_time'] = gmdate('c', strtotime($p['modified'] . ' UTC'));
            }
            if (!empty($p['section'])) {
                $og['article:section'] = $p['section'];
            }
        }
        foreach ($og as $k => $v) {
            $h .= '<meta property="' . $k . '" content="' . e($v) . '">';
        }
        foreach ($p['tags'] ?? [] as $tag) {
            $h .= '<meta property="article:tag" content="' . e($tag) . '">';
        }
        $h .= '<meta name="twitter:card" content="' . ($img ? 'summary_large_image' : 'summary') . '">';
        $handle = self::xHandle();
        if ($handle !== '') {
            $h .= '<meta name="twitter:site" content="@' . e($handle) . '">';
        }
        $verify = trim((string)Settings::get('gsc_verification', ''));
        if ($verify !== '') {
            $h .= '<meta name="google-site-verification" content="' . e($verify) . '">';
        }
        $h .= '<link rel="alternate" type="application/rss+xml" title="' . e($site) . '" href="' . e(url('feed')) . '">';
        return $h;
    }

    private static function xHandle(): string
    {
        $x = (string)(Settings::get('social_links', [])['x'] ?? '');
        return preg_match('#(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})#', $x, $m) ? $m[1] : '';
    }

    public static function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
    }

    public static function organization(): array
    {
        $org = ['@type' => 'Organization', '@id' => url() . '#organization',
            'name' => trim((string)Settings::get('organization_name', '')) ?: Event::name(),
            'url' => Event::get('main_site_url') ?: url()];
        if ($logo = self::logoUrl()) {
            $org['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }
        $same = array_values(array_filter((array)Settings::get('social_links', []), static fn($u) => is_string($u) && is_web_url($u)));
        if ($same) {
            $org['sameAs'] = $same;
        }
        return $org;
    }

    public static function website(): array
    {
        return ['@type' => 'WebSite', '@id' => url() . '#website', 'url' => url(), 'name' => self::siteName(),
            'publisher' => ['@id' => url() . '#organization'], 'inLanguage' => Lang::locale(),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('search') . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string']];
    }

    /** The exhibition as a Google "Event" (only when dates and venue are known). */
    public static function event(): ?array
    {
        $start = Event::get('event_start');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($start, 0, 10)) || Event::get('event_venue') === '') {
            return null;
        }
        $end = Event::get('event_end') ?: $start;
        $ev = ['@type' => 'Event', 'name' => Event::name(), 'startDate' => substr($start, 0, 10), 'endDate' => substr($end, 0, 10),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'eventStatus' => 'https://schema.org/EventScheduled',
            'location' => ['@type' => 'Place', 'name' => Event::get('event_venue'),
                'address' => ['@type' => 'PostalAddress', 'addressLocality' => Event::get('event_city'), 'addressCountry' => Event::get('event_country')]],
            'organizer' => ['@id' => url() . '#organization']];
        if ($t = Event::get('event_tagline')) {
            $ev['description'] = $t;
        }
        if ($u = Event::get('main_site_url')) {
            $ev['url'] = $u;
        }
        if ($img = self::defaultShareImage()) {
            $ev['image'] = [Media::url($img, 1200)];
        }
        return $ev;
    }

    public static function breadcrumbs(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $i => [$name, $u]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $u];
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    public static function article(array $a, string $canonical): array
    {
        $img = Media::find((int)$a['image_id']);
        $data = ['@type' => 'Article', '@id' => $canonical . '#article', 'headline' => mb_substr((string)$a['title'], 0, 110),
            'description' => Article::summary($a, 200), 'mainEntityOfPage' => $canonical,
            'datePublished' => gmdate('c', strtotime((string)$a['published_at'] . ' UTC')),
            'dateModified' => gmdate('c', strtotime((string)$a['updated_at'] . ' UTC')),
            'publisher' => ['@id' => url() . '#organization'], 'inLanguage' => (string)$a['lang'],
            'wordCount' => str_word_count(plain_text((string)$a['body']))];
        if ($img) {
            $data['image'] = [Media::url($img, 1200)];
        }
        if (!empty($a['contributor_name']) && !empty($a['contributor_slug'])) {
            $data['author'] = ['@type' => 'Person', 'name' => $a['contributor_name'], 'url' => Voices::profileUrl(['slug' => $a['contributor_slug']])]
                + (!empty($a['contributor_designation']) ? ['jobTitle' => $a['contributor_designation']] : []);
        } elseif (!empty($a['author_name']) && (int)($a['show_as_author'] ?? 1) === 1) {
            $data['author'] = ['@type' => 'Person', 'name' => $a['author_name'], 'url' => url('author/' . $a['author_slug'])];
        } else {
            $data['author'] = ['@id' => url() . '#organization'];
        }
        if (!empty($a['category_name'])) {
            $data['articleSection'] = $a['category_name'];
        }
        return $data;
    }

    public static function graph(array $items): string
    {
        return self::jsonLd(['@context' => 'https://schema.org', '@graph' => array_values(array_filter($items))]);
    }

    // ------------------------------------------------------------------

    public static function sitemap(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        $last = (string)db()->value("SELECT MAX(updated_at) FROM {p}articles WHERE status = 'published'");
        $x .= self::urlEntry(url(), $last ?: now_utc(), null);
        foreach (db()->all("SELECT c.slug, MAX(a.updated_at) m FROM {p}categories c JOIN {p}articles a ON a.category_id = c.id AND a.status = 'published' GROUP BY c.id") as $c) {
            $x .= self::urlEntry(url($c['slug'] . '/'), (string)$c['m'], null);
        }
        foreach (db()->all("SELECT a.id, a.slug, a.title, a.updated_at, a.image_id, c.slug AS cat FROM {p}articles a LEFT JOIN {p}categories c ON c.id = a.category_id
                WHERE a.status = 'published' ORDER BY a.published_at DESC LIMIT 45000") as $a) {
            $img = Media::find((int)$a['image_id']);
            $x .= self::urlEntry(Article::urlFor($a), (string)$a['updated_at'], $img ? [Media::url($img, 1200), (string)($img['alt'] ?: $a['title'])] : null);
        }
        foreach (db()->all("SELECT DISTINCT u.slug FROM {p}users u JOIN {p}articles a ON a.author_id = u.id AND a.status = 'published' WHERE u.show_as_author = 1") as $u) {
            $x .= self::urlEntry(url('author/' . $u['slug']), null, null);
        }
        if (Voices::enabled()) {
            $voices = db()->all("SELECT ct.slug, MAX(a.updated_at) m FROM {p}contributors ct JOIN {p}articles a ON a.contributor_id = ct.id AND a.status = 'published'
                WHERE ct.active = 1 GROUP BY ct.id");
            if ($voices) {
                $x .= self::urlEntry(Voices::sectionUrl(), (string)max(array_column($voices, 'm')), null);
            }
            foreach ($voices as $c) {
                $x .= self::urlEntry(Voices::profileUrl($c), (string)$c['m'], null);
            }
        }
        return $x . '</urlset>' . "\n";
    }

    private static function urlEntry(string $loc, ?string $mod, ?array $image): string
    {
        $s = '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>';
        if ($mod) {
            $s .= '<lastmod>' . gmdate('c', strtotime($mod . ' UTC')) . '</lastmod>';
        }
        if ($image) {
            $s .= '<image:image><image:loc>' . htmlspecialchars($image[0], ENT_XML1) . '</image:loc></image:image>';
        }
        return $s . "</url>\n";
    }

    public static function rss(): string
    {
        $items = Article::published('1=1', [], 'a.published_at DESC', 20);
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
            . '<title>' . htmlspecialchars(self::siteName(), ENT_XML1) . '</title>'
            . '<link>' . htmlspecialchars(url(), ENT_XML1) . '</link>'
            . '<description>' . htmlspecialchars((string)Settings::get('site_tagline', ''), ENT_XML1) . '</description>'
            . '<language>' . htmlspecialchars(Lang::locale(), ENT_XML1) . '</language>'
            . '<atom:link href="' . htmlspecialchars(url('feed'), ENT_XML1) . '" rel="self" type="application/rss+xml"/>';
        foreach ($items as $a) {
            $link = Article::urlFor($a);
            $x .= '<item><title>' . htmlspecialchars((string)$a['title'], ENT_XML1) . '</title>'
                . '<link>' . htmlspecialchars($link, ENT_XML1) . '</link><guid isPermaLink="true">' . htmlspecialchars($link, ENT_XML1) . '</guid>'
                . '<pubDate>' . gmdate('r', strtotime($a['published_at'] . ' UTC')) . '</pubDate>'
                . ($a['category_name'] ? '<category>' . htmlspecialchars((string)$a['category_name'], ENT_XML1) . '</category>' : '')
                . '<description>' . htmlspecialchars(Article::summary($a, 300), ENT_XML1) . '</description>';
            if ($img = Media::find((int)$a['image_id'])) {
                $x .= '<media:content url="' . htmlspecialchars(Media::url($img, 800), ENT_XML1) . '" medium="image"/>';
            }
            $x .= '</item>';
        }
        return $x . '</channel></rss>' . "\n";
    }
}
