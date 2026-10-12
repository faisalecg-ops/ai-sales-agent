<?php
declare(strict_types=1);

/** Gathers what each public page needs and renders it. */
final class Pages
{
    public static function home(): string
    {
        $featuredCount = max(1, (int)Settings::get('featured_count', 3));
        $latestCount = max(3, (int)Settings::get('latest_count', 6));
        $featured = Article::featured($featuredCount);
        $exclude = array_map(static fn($a) => (int)$a['id'], $featured);
        $latest = Article::published($exclude ? 'a.id NOT IN (' . implode(',', $exclude) . ')' : '1=1', [], 'a.published_at DESC', $latestCount);
        // No editor's picks yet: lead with the newest articles.
        if (!$featured && $latest) {
            $featured = array_splice($latest, 0, min(3, count($latest)));
            $more = Article::published('a.id NOT IN (' . implode(',', array_map(static fn($a) => (int)$a['id'], $featured)) . ')', [], 'a.published_at DESC', $latestCount);
            $latest = $more;
        }
        $now = now_utc();
        $slides = db()->all('SELECT * FROM {p}slides WHERE active = 1 AND image_id IS NOT NULL AND (starts_at IS NULL OR starts_at <= ?)
            AND (ends_at IS NULL OR ends_at >= ?) ORDER BY sort, id', [$now, $now]);

        $catIds = array_filter(array_map('intval', (array)Settings::get('home_category_ids', [])));
        $cats = db()->all('SELECT c.* FROM {p}categories c WHERE EXISTS (SELECT 1 FROM {p}articles a WHERE a.category_id = c.id AND a.status = \'published\')'
            . ($catIds ? ' AND c.id IN (' . implode(',', $catIds) . ')' : '') . ' ORDER BY c.sort, c.name LIMIT 8');
        $blocks = [];
        foreach ($cats as $c) {
            $blocks[] = ['category' => $c, 'articles' => Article::published('a.category_id = ?', [(int)$c['id']], 'a.published_at DESC', 4)];
        }
        $tags = db()->all("SELECT t.name, t.slug, COUNT(*) n FROM {p}tags t JOIN {p}article_tags at ON at.tag_id = t.id
            JOIN {p}articles a ON a.id = at.article_id AND a.status = 'published' GROUP BY t.id ORDER BY n DESC, t.name LIMIT 24");

        $seo = [
            'title'       => trim((string)Settings::get('seo_home_title', '')) ?: Seo::siteName(),
            'description' => trim((string)Settings::get('seo_home_description', '')) ?: (string)Settings::get('site_tagline', ''),
            'canonical'   => url(),
            'ld'          => [Seo::organization(), Seo::website(), Seo::event()],
        ];
        $voices = Voices::enabled() ? Article::published('a.contributor_id IS NOT NULL', [], 'a.published_at DESC', max(2, min(8, (int)Settings::get('voices_home_count', 4)))) : [];
        return View::page('home', [
            'voices' => $voices, 'partner' => Sponsored::runningList(4),
            'sections' => (array)Settings::get('home_sections', []), 'slides' => $slides, 'featured' => $featured,
            'latest' => $latest, 'blocks' => $blocks, 'mostRead' => Article::mostRead(5), 'tags' => $tags, 'isHome' => true,
        ], $seo);
    }

    private static function listing(string $heading, string $intro, array $articles, int $total, int $page, string $baseUrl, array $seo, array $crumbs = []): string
    {
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $pages = max(1, (int)ceil($total / $per));
        $seo += [
            'prev' => $page > 1 ? ($page === 2 ? $baseUrl : $baseUrl . '?page=' . ($page - 1)) : null,
            'next' => $page < $pages ? $baseUrl . '?page=' . ($page + 1) : null,
        ];
        if ($page > 1) {
            $seo['title'] .= ' — ' . t('page_n', ['n' => $page]);
            $seo['canonical'] = $baseUrl . '?page=' . $page;
        }
        if ($crumbs) {
            $seo['ld'][] = Seo::breadcrumbs($crumbs);
        }
        return View::page('listing', ['heading' => $heading, 'intro' => $intro, 'articles' => $articles, 'page' => $page, 'pages' => $pages,
            'baseUrl' => $baseUrl, 'crumbs' => $crumbs], $seo);
    }

    public static function latest(int $page): ?string
    {
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $total = Article::countPublished();
        if ($page > 1 && ($page - 1) * $per >= $total) {
            return null;
        }
        return self::listing(t('latest'), '', Article::published('1=1', [], 'a.published_at DESC', $per, ($page - 1) * $per), $total, $page, url('latest'),
            ['title' => t('latest'), 'description' => (string)Settings::get('site_tagline', ''), 'canonical' => url('latest'), 'ld' => [Seo::organization()]],
            [[t('home'), url()], [t('latest'), url('latest')]]);
    }

    public static function category(string $slug, int $page, bool $trailing): ?string
    {
        $c = db()->one('SELECT * FROM {p}categories WHERE slug = ?', [$slug]);
        if (!$c) {
            return null;
        }
        if (!$trailing) {
            redirect(url($slug . '/') . ($page > 1 ? '?page=' . $page : ''), 301);
        }
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $total = Article::countPublished('a.category_id = ?', [(int)$c['id']]);
        if ($page > 1 && ($page - 1) * $per >= $total) {
            return null;
        }
        $rows = Article::published('a.category_id = ?', [(int)$c['id']], 'a.published_at DESC', $per, ($page - 1) * $per);
        $u = url($slug . '/');
        return self::listing((string)$c['name'], (string)$c['description'], $rows, $total, $page, $u,
            ['title' => trim((string)$c['seo_title']) ?: t('articles_in', ['name' => $c['name']]), 'description' => (string)($c['description'] ?: t('articles_in', ['name' => $c['name']])),
                'canonical' => $u, 'ld' => [Seo::organization()]],
            [[t('home'), url()], [(string)$c['name'], $u]]);
    }

    public static function tag(string $slug, int $page): ?string
    {
        $tag = db()->one('SELECT * FROM {p}tags WHERE slug = ?', [$slug]);
        if (!$tag) {
            return null;
        }
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $where = 'a.id IN (SELECT article_id FROM {p}article_tags WHERE tag_id = ?)';
        $total = Article::countPublished($where, [(int)$tag['id']]);
        if ($total === 0) {
            return null;
        }
        $u = url('tag/' . $slug);
        return self::listing(t('articles_tagged', ['name' => $tag['name']]), '', Article::published($where, [(int)$tag['id']], 'a.published_at DESC', $per, ($page - 1) * $per),
            $total, $page, $u, ['title' => t('articles_tagged', ['name' => $tag['name']]), 'canonical' => $u, 'ld' => [Seo::organization()],
                // Thin tag pages are kept out of search results; articles and categories carry the ranking.
                'noindex' => $total < 3],
            [[t('home'), url()], [(string)$tag['name'], $u]]);
    }

    public static function author(string $slug, int $page): ?string
    {
        $u = db()->one("SELECT * FROM {p}users WHERE slug = ? AND show_as_author = 1", [$slug]);
        if (!$u) {
            return null;
        }
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $total = Article::countPublished('a.author_id = ?', [(int)$u['id']]);
        if ($total === 0) {
            return null;
        }
        $link = url('author/' . $slug);
        $intro = trim(((string)$u['title'] !== '' ? $u['title'] . '. ' : '') . (string)$u['bio']);
        $person = ['@type' => 'Person', 'name' => $u['name'], 'url' => $link];
        if ($u['title']) {
            $person['jobTitle'] = $u['title'];
        }
        return self::listing(t('articles_by', ['name' => $u['name']]), $intro, Article::published('a.author_id = ?', [(int)$u['id']], 'a.published_at DESC', $per, ($page - 1) * $per),
            $total, $page, $link, ['title' => (string)$u['name'], 'description' => excerpt($intro !== '' ? $intro : t('articles_by', ['name' => $u['name']]), 160),
                'canonical' => $link, 'type' => 'profile', 'ld' => [Seo::organization(), ['@type' => 'ProfilePage', 'mainEntity' => $person]]],
            [[t('home'), url()], [(string)$u['name'], $link]]);
    }

    /** Industry Voices: every article by an outside expert, with the contributors. */
    public static function voices(int $page, bool $trailing): ?string
    {
        $u = Voices::sectionUrl();
        if (!$trailing) {
            redirect($u . ($page > 1 ? '?page=' . $page : ''), 301);
        }
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $where = 'a.contributor_id IS NOT NULL';
        $total = Article::countPublished($where);
        if ($page > 1 && ($page - 1) * $per >= $total) {
            return null;
        }
        $contributors = $page === 1 ? db()->all("SELECT ct.*, COUNT(a.id) n FROM {p}contributors ct JOIN {p}articles a ON a.contributor_id = ct.id AND a.status = 'published'
            WHERE ct.active = 1 GROUP BY ct.id ORDER BY MAX(a.published_at) DESC LIMIT 12") : [];
        $name = Voices::name();
        $per = max(1, $per);
        $pages = max(1, (int)ceil($total / $per));
        $crumbs = [[t('home'), url()], [$name, $u]];
        $seo = ['title' => $name . ($page > 1 ? ' — ' . t('page_n', ['n' => $page]) : ''), 'description' => (string)Settings::get('voices_intro', ''),
            'canonical' => $page > 1 ? $u . '?page=' . $page : $u, 'ld' => [Seo::organization(), Seo::breadcrumbs($crumbs)],
            'prev' => $page > 1 ? ($page === 2 ? $u : $u . '?page=' . ($page - 1)) : null, 'next' => $page < $pages ? $u . '?page=' . ($page + 1) : null];
        return View::page('voices', ['heading' => $name, 'intro' => (string)Settings::get('voices_intro', ''), 'contributors' => $contributors,
            'articles' => Article::published($where, [], 'a.published_at DESC', $per, ($page - 1) * $per), 'page' => $page, 'pages' => $pages,
            'baseUrl' => $u, 'crumbs' => $crumbs], $seo);
    }

    /** One contributor's profile and articles. */
    public static function contributor(string $slug, int $page): ?string
    {
        $c = db()->one('SELECT * FROM {p}contributors WHERE slug = ? AND active = 1', [$slug]);
        if (!$c) {
            return null;
        }
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        $total = Article::countPublished('a.contributor_id = ?', [(int)$c['id']]);
        if ($total === 0) {
            return null;
        }
        $link = Voices::profileUrl($c);
        $pages = max(1, (int)ceil($total / $per));
        $person = ['@type' => 'Person', 'name' => $c['name'], 'url' => $link];
        if ($c['designation'] !== '') {
            $person['jobTitle'] = $c['designation'];
        }
        if ($c['company'] !== '') {
            $person['worksFor'] = ['@type' => 'Organization', 'name' => $c['company']] + ($c['company_url'] !== '' ? ['url' => $c['company_url']] : []);
        }
        if ($c['linkedin_url'] !== '') {
            $person['sameAs'] = [$c['linkedin_url']];
        }
        $crumbs = [[t('home'), url()], [Voices::name(), Voices::sectionUrl()], [(string)$c['name'], $link]];
        $intro = trim(Voices::role($c) . '. ' . (string)$c['bio'], '. ');
        return View::page('contributor', ['c' => $c, 'articles' => Article::published('a.contributor_id = ?', [(int)$c['id']], 'a.published_at DESC', $per, ($page - 1) * $per),
            'page' => $page, 'pages' => $pages, 'baseUrl' => $link, 'crumbs' => $crumbs],
            ['title' => $c['name'] . ($c['company'] !== '' ? ' — ' . $c['company'] : ''), 'description' => excerpt($intro, 160), 'canonical' => $page > 1 ? $link . '?page=' . $page : $link,
                'type' => 'profile', 'image' => Media::find((int)$c['photo_id']),
                'ld' => [Seo::organization(), ['@type' => 'ProfilePage', 'mainEntity' => $person], Seo::breadcrumbs($crumbs)]]);
    }

    /** The public "Write for us" form. */
    public static function write(string $state): string
    {
        $u = url(Voices::writeSlug());
        $title = trim((string)Settings::get('write_title', '')) ?: 'Write for us';
        return View::page('write', ['state' => $state, 'heading' => $title, 'intro' => (string)Settings::get('write_intro', ''),
            'consent' => (string)Settings::get('write_consent', ''), 'action' => $u, 'crumbs' => [[t('home'), url()], [$title, $u]]],
            ['title' => $title, 'description' => excerpt((string)Settings::get('write_intro', ''), 160), 'canonical' => $u, 'ld' => [Seo::organization()]]);
    }

    public static function search(string $q, int $page): string
    {
        $per = max(1, (int)Settings::get('posts_per_page', 12));
        [$rows, $total] = Article::search($q, $per, ($page - 1) * $per);
        $q = trim(mb_substr($q, 0, 100));
        $base = url('search') . '?q=' . rawurlencode($q);
        $pages = max(1, (int)ceil($total / $per));
        return View::page('search', ['q' => $q, 'articles' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'baseUrl' => $base],
            ['title' => $q !== '' ? t('search_results', ['q' => $q]) : t('search'), 'canonical' => url('search'), 'noindex' => true, 'ld' => []]);
    }

    public static function article(string $catSlug, string $slug, ?array $preview = null): ?string
    {
        $a = $preview ?? Article::bySlug($catSlug, $slug);
        if (!$a) {
            return null;
        }
        $canonical = Article::urlFor($a);
        // The right address is the one with the article's current category.
        if (!$preview && ($a['cat'] ?? '') !== $catSlug) {
            redirect($canonical, 301);
        }
        [$body, $toc] = Html::withHeadingIds((string)$a['body']);
        $body = Html::enhanceImages($body);
        if (Sponsored::is($a) && !$preview) {
            $body = Sponsored::trackLinks($body, (int)$a['id']);
        }
        $contributor = Voices::find((int)($a['contributor_id'] ?? 0));
        $tags = Article::tags((int)$a['id']);
        $img = Media::find((int)$a['image_id']);
        $share = Media::find((int)$a['share_image_id']) ?: $img;
        $crumbs = [[t('home'), url()]];
        if ($a['category_name']) {
            $crumbs[] = [(string)$a['category_name'], url($a['cat'] . '/')];
        }
        $crumbs[] = [(string)$a['title'], $canonical];
        $seo = [
            'title' => trim((string)$a['seo_title']) ?: (string)$a['title'],
            'description' => trim((string)$a['meta_description']) ?: Article::summary($a, 160),
            'canonical' => $canonical, 'type' => 'article', 'image' => $share,
            'published' => $a['published_at'], 'modified' => $a['updated_at'], 'section' => (string)$a['category_name'],
            'tags' => array_column($tags, 'name'), 'noindex' => $preview !== null,
            'ld' => $preview ? [] : [Seo::organization(), Seo::article($a, $canonical), Seo::breadcrumbs($crumbs)],
        ];
        $comments = Settings::get('comments_mode', 'off') === 'approval' ? Reader::approvedComments((int)$a['id']) : null;
        return View::page('article', [
            'a' => $a, 'body' => $body, 'toc' => count($toc) >= 3 ? $toc : [], 'tags' => $tags, 'image' => $img,
            'related' => $preview ? [] : Article::related($a, 3), 'crumbs' => $crumbs, 'comments' => $comments,
            'isPreview' => $preview !== null, 'articleId' => $preview ? 0 : (int)$a['id'], 'contributor' => $contributor,
        ], $seo);
    }

    public static function notFound(): string
    {
        return View::page('notfound', ['latest' => Article::published('1=1', [], 'a.published_at DESC', 3)],
            ['title' => t('not_found_title'), 'canonical' => url(), 'noindex' => true, 'ld' => []]);
    }

    public static function message(string $text): string
    {
        return View::page('message', ['text' => $text], ['title' => Seo::siteName(), 'canonical' => url(), 'noindex' => true, 'ld' => []]);
    }
}
