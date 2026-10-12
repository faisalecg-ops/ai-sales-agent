<?php
declare(strict_types=1);

/**
 * Articles: saving, revisions, approval states and the lists the pages show.
 *
 * States: draft → pending (awaiting approval) → published, or scheduled for
 * a set time. Only approvers and admins can publish or schedule.
 */
final class Article
{
    public const STATUSES = [
        'draft'     => 'Draft',
        'pending'   => 'Awaiting approval',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'archived'  => 'Archived',
    ];

    /** Words used by the blog's own pages: never allowed as a category address. */
    public const RESERVED = ['admin', 'api', 'assets', 'uploads', 'install', 'tag', 'tags', 'author', 'authors', 'search',
        'page', 'feed', 'rss', 'sitemap', 'sitemap.xml', 'robots.txt', 'go', 'subscribe', 'confirm', 'unsubscribe',
        'comment', 'core', 'config', 'storage', 'lang', 'themes', 'cron.php', 'index.php', 'preview', 'theme.css', 'category'];

    public static function find(int $id): ?array
    {
        return db()->one('SELECT * FROM {p}articles WHERE id = ?', [$id]);
    }

    public static function urlFor(array $a): string
    {
        $cat = (string)($a['cat'] ?? $a['category_slug'] ?? '');
        if ($cat === '' && !empty($a['category_id'])) {
            $cat = (string)db()->value('SELECT slug FROM {p}categories WHERE id = ?', [(int)$a['category_id']]);
        }
        return url(($cat !== '' ? $cat : 'news') . '/' . $a['slug']);
    }

    public static function pathFor(array $a): string
    {
        return ltrim(substr(self::urlFor($a), strlen(base_url())), '/');
    }

    public static function uniqueSlug(string $wanted, ?int $exceptId = null): string
    {
        $base = slugify($wanted, 90) ?: 'article';
        $slug = $base;
        $n = 2;
        while (db()->value('SELECT id FROM {p}articles WHERE slug = ?' . ($exceptId ? ' AND id <> ' . (int)$exceptId : ''), [$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /**
     * Create or update an article. $data uses the column names; tags is a
     * list of names. Returns the article id.
     */
    public static function save(?int $id, array $data, ?array $tags = null, string $note = '', ?int $apiKeyId = null): int
    {
        $old = $id ? self::find($id) : null;
        $row = [];
        foreach (['title', 'excerpt', 'body', 'category_id', 'author_id', 'image_id', 'seo_title', 'meta_description',
                     'share_image_id', 'is_featured', 'featured_order', 'lang', 'external_ref', 'review_note', 'sources', 'contributor_id',
                     'sponsor_name', 'sponsor_label', 'sponsor_url', 'sponsor_logo_id', 'sponsor_starts', 'sponsor_ends', 'sponsor_after', 'sponsor_email'] as $k) {
            if (array_key_exists($k, $data)) {
                $row[$k] = $data[$k];
            }
        }
        if (isset($row['title'])) {
            $row['title'] = mb_substr(trim((string)$row['title']), 0, 255);
        }
        if (array_key_exists('body', $row)) {
            $row['body'] = Html::clean((string)$row['body']);
            $row['reading_minutes'] = Html::readingMinutes($row['body']);
        }
        foreach (['sponsor_name' => 160, 'sponsor_url' => 500, 'sponsor_email' => 190] as $k => $max) {
            if (array_key_exists($k, $row)) {
                $row[$k] = mb_substr(trim((string)$row[$k]), 0, $max);
            }
        }
        if (array_key_exists('sponsor_label', $row)) {
            $row['sponsor_label'] = $row['sponsor_label'] === 'partner' ? 'partner' : 'sponsored';
        }
        if (array_key_exists('sponsor_after', $row)) {
            $row['sponsor_after'] = $row['sponsor_after'] === 'archive' ? 'archive' : 'keep';
        }
        foreach (['sponsor_starts', 'sponsor_ends'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $row[$k] ? (string)$row[$k] : null;
            }
        }
        foreach (['category_id', 'author_id', 'image_id', 'share_image_id', 'contributor_id', 'sponsor_logo_id'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k] > 0 ? (int)$row[$k] : null;
            }
        }
        if (array_key_exists('excerpt', $row)) {
            $row['excerpt'] = trim(plain_text((string)$row['excerpt'])) ?: null;
        }
        foreach (['seo_title' => 190, 'meta_description' => 320] as $k => $max) {
            if (array_key_exists($k, $row)) {
                $row[$k] = mb_substr(trim((string)$row[$k]), 0, $max);
            }
        }
        $wantedSlug = array_key_exists('slug', $data) ? trim((string)$data['slug']) : null;

        db()->begin();
        try {
            if ($old === null) {
                $row['slug'] = self::uniqueSlug($wantedSlug ?: (string)($row['title'] ?? 'article'));
                $row['status'] = 'draft';
                $row['body'] = $row['body'] ?? '';
                $row['created_by'] = Auth::id();
                $row['updated_by'] = Auth::id();
                $row['source'] = $apiKeyId ? 'api' : 'admin';
                $row['api_key_id'] = $apiKeyId;
                $row['lang'] = $row['lang'] ?? (string)Settings::get('language', 'en');
                $id = db()->insert('articles', $row);
            } else {
                if ($wantedSlug !== null && $wantedSlug !== '' && slugify($wantedSlug, 90) !== $old['slug']) {
                    $row['slug'] = self::uniqueSlug($wantedSlug, $id);
                }
                $row['updated_by'] = Auth::id();
                db()->update('articles', $row, 'id = ?', [$id]);
                self::rememberOldAddress($old, self::find($id));
            }
            if ($tags !== null) {
                self::syncTags($id, $tags);
            }
            $fresh = self::find($id);
            self::revision($fresh, $note !== '' ? $note : ($old ? 'Edited' : 'Created'), $apiKeyId);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollback();
            throw $ex;
        }
        Cache::clear();
        return (int)$id;
    }

    /** When a published article's address changes, the old one sends readers (and Google) to the new one. */
    private static function rememberOldAddress(array $old, ?array $new): void
    {
        if (!$new || !$old['published_at']) {
            return;
        }
        $oldPath = self::pathFor($old);
        $newPath = self::pathFor($new);
        if ($oldPath === $newPath) {
            return;
        }
        db()->exec('INSERT INTO {p}redirects (old_path, article_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE article_id = VALUES(article_id), target = NULL',
            [$oldPath, (int)$new['id']]);
        // A new article may now live at an address that used to redirect.
        db()->delete('redirects', 'old_path = ?', [$newPath]);
    }

    /** All published articles of a category get redirects when the category's address changes. */
    public static function categoryRenamed(int $categoryId, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }
        foreach (db()->all('SELECT id, slug FROM {p}articles WHERE category_id = ? AND published_at IS NOT NULL', [$categoryId]) as $a) {
            db()->exec('INSERT INTO {p}redirects (old_path, article_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE article_id = VALUES(article_id), target = NULL',
                [$oldSlug . '/' . $a['slug'], (int)$a['id']]);
        }
        db()->exec('INSERT INTO {p}redirects (old_path, target) VALUES (?, ?) ON DUPLICATE KEY UPDATE target = VALUES(target), article_id = NULL',
            [$oldSlug, $newSlug . '/']);
        Cache::clear();
    }

    private static function revision(array $a, string $note, ?int $apiKeyId): void
    {
        $last = db()->one('SELECT title, excerpt, body, status FROM {p}article_revisions WHERE article_id = ? ORDER BY id DESC LIMIT 1', [(int)$a['id']]);
        if ($last && $last['title'] === $a['title'] && $last['body'] === $a['body'] && (string)$last['excerpt'] === (string)$a['excerpt'] && $last['status'] === $a['status']) {
            return;   // nothing changed in the text
        }
        db()->insert('article_revisions', [
            'article_id' => (int)$a['id'],
            'title'      => $a['title'],
            'excerpt'    => $a['excerpt'],
            'body'       => $a['body'],
            'status'     => $a['status'],
            'note'       => mb_substr($note, 0, 255),
            'user_id'    => Auth::id(),
            'api_key_id' => $apiKeyId,
        ]);
        // Keep the last 50 versions of each article.
        $keep = db()->value('SELECT id FROM {p}article_revisions WHERE article_id = ? ORDER BY id DESC LIMIT 1 OFFSET 49', [(int)$a['id']]);
        if ($keep) {
            db()->delete('article_revisions', 'article_id = ? AND id < ?', [(int)$a['id'], (int)$keep]);
        }
    }

    /** What is missing before an article can be published. */
    public static function readinessProblems(array $a): array
    {
        $p = [];
        if (trim((string)$a['title']) === '') {
            $p[] = 'It needs a title.';
        }
        if (mb_strlen(plain_text((string)$a['body'])) < 50) {
            $p[] = 'The article text is too short.';
        }
        if (empty($a['category_id'])) {
            $p[] = 'Choose a category.';
        }
        return array_merge($p, Sponsored::problems($a));
    }

    /**
     * Move an article to a new state.
     * @return array{ok:bool, error?:string}
     */
    public static function transition(int $id, string $to, ?string $publishAtUtc = null, string $note = '', ?int $apiKeyId = null): array
    {
        $a = self::find($id);
        if (!$a) {
            return ['ok' => false, 'error' => 'Article not found.'];
        }
        if (!isset(self::STATUSES[$to])) {
            return ['ok' => false, 'error' => 'Unknown state.'];
        }
        if (in_array($to, ['published', 'scheduled'], true)) {
            $problems = self::readinessProblems($a);
            if ($problems) {
                return ['ok' => false, 'error' => implode(' ', $problems)];
            }
        }
        // A sponsored article approved before its paid period starts goes live when it starts.
        if ($to === 'published' && Sponsored::is($a) && !empty($a['sponsor_starts']) && $a['sponsor_starts'] > now_utc() && $a['status'] !== 'published') {
            $to = 'scheduled';
            $publishAtUtc = (string)$a['sponsor_starts'];
        }
        $row = ['status' => $to];
        if ($to === 'scheduled') {
            if (!$publishAtUtc || strtotime($publishAtUtc . ' UTC') <= time()) {
                return ['ok' => false, 'error' => 'Choose a publishing time in the future.'];
            }
            $row['publish_at'] = $publishAtUtc;
        }
        if ($to === 'published') {
            $row['publish_at'] = null;
            $row['review_note'] = null;
            if (!$a['published_at']) {
                $row['published_at'] = now_utc();
            }
            if (Auth::id()) {
                $row['approved_by'] = Auth::id();
            }
        }
        if ($note !== '') {
            $row['review_note'] = mb_substr($note, 0, 2000);
        }
        db()->update('articles', $row, 'id = ?', [$id]);
        $fresh = self::find($id);
        self::revision($fresh, self::STATUSES[$to] . ($note !== '' ? ' — ' . $note : ''), $apiKeyId);
        Activity::log('article.' . $to, (string)$a['title'], ['id' => $id], $apiKeyId);
        if ($to === 'published' && $a['status'] !== 'published') {
            Webhook::queue('article.published', $fresh);
        }
        Cache::clear();
        return ['ok' => true, 'status' => $to];
    }

    /** Publish scheduled articles whose time has come. Runs from cron and, as a back-up, on page views. */
    public static function publishDue(): int
    {
        $n = 0;
        foreach (db()->all("SELECT id FROM {p}articles WHERE status = 'scheduled' AND publish_at <= ?", [now_utc()]) as $r) {
            $a = self::find((int)$r['id']);
            db()->update('articles', ['status' => 'published', 'published_at' => $a['published_at'] ?: $a['publish_at'], 'publish_at' => null], 'id = ?', [(int)$r['id']]);
            Activity::log('article.published', (string)$a['title'], ['id' => (int)$r['id'], 'by' => 'schedule']);
            Webhook::queue('article.published', self::find((int)$r['id']));
            $n++;
        }
        if ($n) {
            Cache::clear();
        }
        return $n;
    }

    public static function syncTags(int $articleId, array $names): void
    {
        db()->delete('article_tags', 'article_id = ?', [$articleId]);
        $seen = [];
        foreach ($names as $name) {
            $name = trim(mb_substr((string)$name, 0, 80));
            $slug = slugify($name, 90);
            if ($name === '' || $slug === '' || isset($seen[$slug]) || count($seen) >= 15) {
                continue;
            }
            $seen[$slug] = true;
            $tagId = (int)db()->value('SELECT id FROM {p}tags WHERE slug = ?', [$slug]);
            if (!$tagId) {
                $tagId = db()->insert('tags', ['name' => $name, 'slug' => $slug]);
            }
            db()->exec('INSERT IGNORE INTO {p}article_tags (article_id, tag_id) VALUES (?, ?)', [$articleId, $tagId]);
        }
    }

    public static function tags(int $articleId): array
    {
        return db()->all('SELECT t.* FROM {p}tags t JOIN {p}article_tags at ON at.tag_id = t.id WHERE at.article_id = ? ORDER BY t.name', [$articleId]);
    }

    // ------------------------------------------------------------------
    // Lists for the public pages (published only)
    // ------------------------------------------------------------------

    private const SELECT = "SELECT a.*, c.name AS category_name, c.slug AS cat, u.name AS author_name, u.slug AS author_slug, u.show_as_author,
        ct.name AS contributor_name, ct.slug AS contributor_slug, ct.designation AS contributor_designation, ct.company AS contributor_company
        FROM {p}articles a
        LEFT JOIN {p}categories c ON c.id = a.category_id
        LEFT JOIN {p}users u ON u.id = a.author_id
        LEFT JOIN {p}contributors ct ON ct.id = a.contributor_id";

    public static function published(string $where = '1=1', array $params = [], string $order = 'a.published_at DESC', int $limit = 12, int $offset = 0): array
    {
        return db()->all(self::SELECT . " WHERE a.status = 'published' AND ($where) ORDER BY $order LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    public static function countPublished(string $where = '1=1', array $params = []): int
    {
        return (int)db()->value("SELECT COUNT(*) FROM {p}articles a WHERE a.status = 'published' AND ($where)", $params);
    }

    public static function bySlug(string $catSlug, string $slug): ?array
    {
        return db()->one(self::SELECT . " WHERE a.slug = ? AND a.status = 'published'", [$slug]);
    }

    public static function forPreview(int $id): ?array
    {
        return db()->one(self::SELECT . ' WHERE a.id = ?', [$id]);
    }

    public static function featured(int $limit): array
    {
        return self::published('a.is_featured = 1', [], 'a.featured_order ASC, a.published_at DESC', $limit);
    }

    public static function mostRead(int $limit = 5): array
    {
        return self::published('1=1', [], 'a.views_30d DESC, a.views DESC, a.published_at DESC', $limit);
    }

    public static function related(array $a, int $limit = 3): array
    {
        $tagIds = array_map(static fn($t) => (int)$t['id'], self::tags((int)$a['id']));
        $rows = [];
        if ($tagIds) {
            $rows = db()->all(self::SELECT . " JOIN {p}article_tags at ON at.article_id = a.id
                WHERE a.status = 'published' AND a.id <> ? AND at.tag_id IN (" . implode(',', $tagIds) . ")
                GROUP BY a.id ORDER BY COUNT(*) DESC, a.published_at DESC LIMIT " . $limit, [(int)$a['id']]);
        }
        if (count($rows) < $limit && $a['category_id']) {
            $ids = array_merge([(int)$a['id']], array_map(static fn($r) => (int)$r['id'], $rows));
            $rows = array_merge($rows, self::published('a.category_id = ? AND a.id NOT IN (' . implode(',', $ids) . ')', [(int)$a['category_id']], 'a.published_at DESC', $limit - count($rows)));
        }
        if (count($rows) < $limit) {
            $ids = array_merge([(int)$a['id']], array_map(static fn($r) => (int)$r['id'], $rows));
            $rows = array_merge($rows, self::published('a.id NOT IN (' . implode(',', $ids) . ')', [], 'a.published_at DESC', $limit - count($rows)));
        }
        return $rows;
    }

    public static function search(string $q, int $limit, int $offset): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if (mb_strlen($q) < 2) {
            return [[], 0];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where = '(a.title LIKE ? OR a.excerpt LIKE ? OR a.body LIKE ?)';
        $params = [$like, $like, $like];
        $total = self::countPublished($where, $params);
        // Title matches first; the ORDER BY placeholder needs its own value.
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $rows = db()->all(self::SELECT . " WHERE a.status = 'published' AND $where ORDER BY (a.title LIKE ?) DESC, a.published_at DESC LIMIT $limit OFFSET $offset", array_merge($params, [$like]));
        return [$rows, $total];
    }

    /** Count a page view (once per visitor per article per day, roughly — bots excluded). */
    public static function countView(int $id): void
    {
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|linkedin|monitor|curl|wget|python|headless/', $ua)) {
            return;
        }
        db()->exec('UPDATE {p}articles SET views = views + 1 WHERE id = ?', [$id]);
        db()->exec('INSERT INTO {p}article_views (article_id, day, views) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$id, gmdate('Y-m-d')]);
    }

    /** Refresh the "most read in the last 30 days" figures (cron, daily). */
    public static function refreshMostRead(): void
    {
        $since = gmdate('Y-m-d', time() - 30 * 86400);
        db()->exec('UPDATE {p}articles SET views_30d = 0');
        db()->exec('UPDATE {p}articles a JOIN (SELECT article_id, SUM(views) v FROM {p}article_views WHERE day >= ? GROUP BY article_id) s ON s.article_id = a.id SET a.views_30d = s.v', [$since]);
        db()->delete('article_views', 'day < ?', [gmdate('Y-m-d', time() - 400 * 86400)]);
    }

    /** The summary shown on cards and in search results. */
    public static function summary(array $a, int $max = 180): string
    {
        $text = trim((string)$a['excerpt']);
        return excerpt($text !== '' ? $text : plain_text((string)$a['body']), $max);
    }
}
