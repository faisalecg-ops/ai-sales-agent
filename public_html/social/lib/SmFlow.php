<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * The content cycle: topic → article → approval → blog → a version per
 * platform → approval → Post now or Schedule → posted.
 *
 * PHP decides every state change; the AI only writes text.
 */
final class SmFlow
{
    // -----------------------------------------------------------------
    // Articles
    // -----------------------------------------------------------------

    /** Make the article row now; the writing happens in write(). */
    public static function startArticle(array $agent, array $topic, int $userId): int
    {
        $id = db()->insert('sm_articles', [
            'agent_id' => (int)$agent['id'], 'topic_id' => $topic['id'] ?? null, 'pillar' => (string)$topic['pillar'],
            'title' => mb_substr((string)$topic['title'], 0, 255), 'excerpt' => mb_substr((string)($topic['angle'] ?? ''), 0, 400),
            'status' => 'writing', 'written_by' => $userId,
        ]);
        if (!empty($topic['id'])) {
            db()->update('sm_topics', ['status' => 'used'], 'id = ?', [(int)$topic['id']]);
        }
        return $id;
    }

    /** Research, write, fact-check and check one article. */
    public static function write(array $agent, int $articleId): void
    {
        @set_time_limit(600);
        $aid = (int)$agent['id'];
        $a = db()->one('SELECT * FROM sm_articles WHERE id = ? AND agent_id = ?', [$articleId, $aid]);
        if (!$a || $a['status'] !== 'writing') {
            return;
        }
        $touch = static fn() => db()->exec("UPDATE sm_articles SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'writing'", [$articleId]);
        $topic = $a['topic_id'] ? db()->one('SELECT * FROM sm_topics WHERE id = ?', [(int)$a['topic_id']]) : null;
        $angle = (string)($topic['angle'] ?? $a['excerpt']);
        if ($topic !== null && preg_match('/\nSearch: (.+)$/s', $angle, $sm)) {
            $topic['search'] = trim($sm[1]);
            $angle = trim(substr($angle, 0, -strlen($sm[0])));
        }
        $topicArr = ['title' => $a['title'], 'angle' => $angle, 'pillar' => $a['pillar']];

        // Sources: approved knowledge first, then pages actually opened.
        $sources = SmSources::knowledge($aid, $a['title'] . ' ' . $topicArr['angle'], 5);
        $given = array_values(array_filter(array_map('trim', explode("\n", (string)($topic['source_urls'] ?? '')))));
        $pages = [];
        if ($given) {
            foreach (SmSources::extract($given) as $url => $text) {
                if (mb_strlen(trim($text)) >= 300) {
                    $pages[] = ['title' => parse_url($url, PHP_URL_HOST) . ' page', 'url' => $url, 'text' => mb_substr($text, 0, 9000)];
                }
            }
        }
        if (!in_array($a['pillar'], ['news', 'visitor', 'spotlight'], true) || !$sources) {
            $query = trim((string)($topic['search'] ?? '')) ?: ($a['title'] . ' Pakistan ' . date('Y'));
            $web = SmSources::web($aid, $query, 4);
            $pages = array_merge($pages, $web['pages']);
        }
        $all = array_merge($sources, $pages);
        $touch();
        if (!$all) {
            self::fail($articleId, 'No sources could be found or opened for this topic, so nothing was written. Add source addresses to the topic, or add knowledge in the sales agent.');
            return;
        }

        $blog = SmBlog::forAgent($aid);
        $links = $blog ? $blog->links() : [];
        $cats = $blog ? $blog->categories() : [];
        $out = SmWriter::article($agent, $topicArr, $all, $links, $cats);
        if (isset($out['_error'])) {
            self::fail($articleId, (string)$out['_error']);
            return;
        }
        $used = [];
        foreach ((array)($out['sources_used'] ?? []) as $n) {
            $i = (int)$n - 1;
            if (isset($all[$i])) {
                $used[] = ['title' => (string)$all[$i]['title'], 'url' => (string)($all[$i]['url'] ?? ''), 'ref' => (string)($all[$i]['ref'] ?? ''), 'text' => (string)$all[$i]['text']];
            }
        }
        if (!$used) {
            $used = array_map(static fn($s) => ['title' => (string)$s['title'], 'url' => (string)($s['url'] ?? ''), 'ref' => (string)($s['ref'] ?? ''), 'text' => (string)$s['text']], $all);
        }
        $body = SmHtml::clean((string)($out['body'] ?? ''));
        $article = [
            'id' => $articleId,
            'title' => mb_substr(trim((string)($out['title'] ?? $a['title'])), 0, 255),
            'excerpt' => mb_substr(trim((string)($out['excerpt'] ?? '')), 0, 400),
            'body' => $body,
        ];
        $touch();
        $check = SmWriter::factCheck($agent, $article, $used);
        $checks = SmChecks::article($agent, $article, self::allowedLinks($agent, $used, $links));
        $catSlugs = array_map(static fn($c) => (string)$c['slug'], $cats);
        $cat = (string)($out['category'] ?? '');
        $done = db()->update('sm_articles', [
            'title' => $article['title'], 'excerpt' => $article['excerpt'], 'body' => $body,
            'category' => $catSlugs && !in_array($cat, $catSlugs, true) ? ($catSlugs[0] ?? $cat) : $cat,
            'tags' => implode(', ', array_slice(array_map('strval', (array)($out['tags'] ?? [])), 0, 4)),
            'sources' => json_encode(['used' => $used, 'seo_title' => (string)($out['seo_title'] ?? ''), 'meta_description' => (string)($out['meta_description'] ?? ''), 'image_headline' => (string)($out['image_headline'] ?? '')], JSON_UNESCAPED_UNICODE),
            'fact_check' => json_encode(isset($check['_error']) ? ['verdict' => 'not_run', 'issues' => [], 'error' => $check['_error']] : $check, JSON_UNESCAPED_UNICODE),
            'checks' => json_encode($checks, JSON_UNESCAPED_UNICODE),
            'status' => 'draft', 'error' => null,
        ], "id = ? AND status = 'writing'", [$articleId]);
        if ($done > 0) {
            SmActivity::log($aid, 'article_written', $article['title'], ['id' => 0, 'full_name' => 'AI', 'username' => 'AI']);
        }
    }

    public static function fail(int $articleId, string $why): void
    {
        db()->update('sm_articles', ['status' => 'failed', 'error' => mb_substr($why, 0, 2000)], "id = ? AND status = 'writing'", [$articleId]);
    }

    /** Every address an article may link to. */
    public static function allowedLinks(array $agent, array $sources, array $blogLinks): array
    {
        $f = SmEvent::facts($agent);
        $row = SmEvent::row((int)$agent['id']);
        return array_values(array_filter(array_merge(
            array_column($sources, 'url'),
            array_column($blogLinks, 'url'),
            [$f['register'], $f['stand'], $f['website'], (string)($row['blog_url'] ?? '')]
        )));
    }

    /** Re-run the PHP checks after a person edits the text. */
    public static function recheck(array $agent, array $article): array
    {
        $src = json_decode((string)$article['sources'], true) ?: [];
        $blog = SmBlog::forAgent((int)$agent['id']);
        return SmChecks::article($agent, $article, self::allowedLinks($agent, (array)($src['used'] ?? []), $blog ? $blog->links() : []));
    }

    /**
     * Approved: send to the blog with publish permission. The blog publishes
     * it (key with "publish" permission) or holds it for its own approval.
     */
    public static function sendToBlog(array $agent, int $articleId, int $userId): array
    {
        $aid = (int)$agent['id'];
        $a = db()->one('SELECT * FROM sm_articles WHERE id = ? AND agent_id = ?', [$articleId, $aid]);
        if (!$a || !in_array($a['status'], ['approved', 'failed'], true) || !$a['approved_by']) {
            return ['ok' => false, 'message' => 'Only an approved article can be sent to the blog.'];
        }
        $blog = SmBlog::forAgent($aid);
        if (!$blog) {
            db()->update('sm_articles', ['status' => 'failed', 'error' => 'The blog is not connected yet. An admin can connect it under "Blog and platforms", then press "Try sending to the blog again".'], 'id = ?', [$articleId]);
            return ['ok' => false, 'message' => 'The blog is not connected yet. An admin can connect it under "Blog and platforms".'];
        }
        $src = json_decode((string)$a['sources'], true) ?: [];
        $imageId = null;
        $cover = $a['cover_media_id'] ? SmMedia::find($aid, (int)$a['cover_media_id']) : null;
        if (!$cover) {
            $built = SmMedia::build($agent, (string)(($src['image_headline'] ?? '') ?: $a['title']), 'cover', null, $userId);
            if (!empty($built['ok'])) {
                db()->update('sm_articles', ['cover_media_id' => $built['id']], 'id = ?', [$articleId]);
                $cover = SmMedia::find($aid, (int)$built['id']);
            }
        }
        if ($cover && $cover['kind'] === 'image') {
            $up = $blog->uploadImage((string)file_get_contents(SmMedia::path($cover)), basename((string)$cover['file']), (string)$a['title']);
            if ($up['ok']) {
                $imageId = (int)($up['data']['media']['id'] ?? 0) ?: null;
            }
        }
        $payload = [
            'title' => $a['title'], 'body' => $a['body'], 'excerpt' => (string)$a['excerpt'],
            'category' => (string)($a['category'] ?: 'news'),
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string)$a['tags'])))),
            'sources' => array_values(array_filter(array_column((array)($src['used'] ?? []), 'url'))),
            'seo_title' => mb_substr((string)($src['seo_title'] ?? ''), 0, 70),
            'meta_description' => mb_substr((string)($src['meta_description'] ?? ''), 0, 160),
            'external_ref' => 'sm-' . $aid . '-' . $articleId,
            'publish' => true,
        ];
        if ($imageId) {
            $payload['image_id'] = $imageId;
        }
        $res = $a['blog_article_id'] ? $blog->updateArticle((int)$a['blog_article_id'], $payload) : $blog->createArticle($payload);
        if (!$res['ok'] && $res['status'] === 409 && !empty($res['data']['id'])) {
            $res = $blog->getArticle((int)$res['data']['id']);
        }
        if (!$res['ok']) {
            db()->update('sm_articles', ['status' => 'failed', 'error' => 'The blog did not accept it: ' . $res['message']], 'id = ?', [$articleId]);
            return ['ok' => false, 'message' => 'The blog did not accept it: ' . $res['message']];
        }
        $remote = (array)($res['data']['article'] ?? []);
        self::applyRemote($agent, $articleId, $remote);
        SmActivity::log($aid, 'article_sent_to_blog', (string)$a['title']);
        $now = db()->one('SELECT status FROM sm_articles WHERE id = ?', [$articleId]);
        return ['ok' => true, 'message' => ($now['status'] ?? '') === 'published'
            ? 'Published on the blog. The social media versions are being written.'
            : 'Sent to the blog. It is waiting for approval in the blog\'s own admin' . (!empty($res['data']['note']) ? ' (' . $res['data']['note'] . ')' : '') . '.'];
    }

    /** Store what the blog says about the article; on first publish, write the platform versions. */
    public static function applyRemote(array $agent, int $articleId, array $remote): void
    {
        $status = (string)($remote['status'] ?? '');
        $blogId = (int)($remote['id'] ?? 0) ?: null;
        if ($status === 'published' && !empty($remote['url'])) {
            // Claimed in one statement: only the first caller (notice, cron or a button) writes the versions.
            $claimed = db()->update('sm_articles', [
                'blog_article_id' => $blogId, 'blog_status' => 'published', 'blog_url' => mb_substr((string)$remote['url'], 0, 500),
                'status' => 'published', 'error' => null,
                'published_at' => gmdate('Y-m-d H:i:s', strtotime((string)($remote['published_at'] ?? 'now')) ?: time()),
            ], "id = ? AND status <> 'published'", [$articleId]);
            if ($claimed === 1) {
                self::makeVersions($agent, $articleId, null);
            }
            return;
        }
        $data = ['blog_article_id' => $blogId, 'blog_status' => mb_substr($status, 0, 32)];
        if ($status === 'draft') {
            // The blog's editor sent it back: it must be edited and approved again here.
            $data += ['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'approved_hash' => null,
                      'review_note' => 'The blog\'s editor sent it back: ' . (string)($remote['review_note'] ?? 'no reason given')];
        } elseif ($status === 'archived') {
            $data += ['status' => 'failed', 'error' => 'The blog archived this article, so it was not published.'];
        } else {
            $data += ['status' => 'sent', 'error' => null];
        }
        db()->update('sm_articles', $data, "id = ? AND status <> 'published'", [$articleId]);
    }

    // -----------------------------------------------------------------
    // Posts
    // -----------------------------------------------------------------

    /** Write one post per enabled platform for a published article. */
    public static function makeVersions(array $agent, int $articleId, ?int $userId): array
    {
        $aid = (int)$agent['id'];
        $a = db()->one('SELECT * FROM sm_articles WHERE id = ? AND agent_id = ?', [$articleId, $aid]);
        if (!$a) {
            return ['ok' => false, 'message' => 'Article not found.'];
        }
        $lock = 'sm_versions_' . $articleId;
        if ((int)db()->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['ok' => false, 'message' => 'The versions are being written right now. Refresh in a minute.'];
        }
        try {
            $platforms = SmEvent::platforms($aid, true);
            $have = array_column(db()->all("SELECT platform FROM sm_posts WHERE article_id = ? AND status <> 'cancelled'", [$articleId]), 'platform');
            $platforms = array_diff_key($platforms, array_flip($have));
            if (!$platforms) {
                return ['ok' => true, 'message' => 'Every switched-on platform already has a version.'];
            }
            $out = SmWriter::versions($agent, $a, $platforms);
            if (isset($out['_error'])) {
                return ['ok' => false, 'message' => (string)$out['_error']];
            }
            return self::savePosts($agent, $out, $platforms, $articleId, $userId ?? ((int)$a['written_by'] ?: null), (int)($a['cover_media_id'] ?? 0));
        } finally {
            db()->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function makeStandalone(array $agent, string $brief, ?int $photoId, int $userId): array
    {
        $platforms = SmEvent::platforms((int)$agent['id'], true);
        if (!$platforms) {
            return ['ok' => false, 'message' => 'No platform is switched on. An admin can switch them on under "Blog and platforms".'];
        }
        $out = SmWriter::standalone($agent, $brief, $platforms);
        if (isset($out['_error'])) {
            return ['ok' => false, 'message' => (string)$out['_error']];
        }
        return self::savePosts($agent, $out, $platforms, null, $userId, (int)$photoId);
    }

    private static function savePosts(array $agent, array $out, array $platforms, ?int $articleId, ?int $userId, int $photoId): array
    {
        $aid = (int)$agent['id'];
        $headline = (string)($out['image_headline'] ?? '');
        $made = 0;
        $images = [];
        $photo = $photoId > 0 ? SmMedia::find($aid, $photoId) : null;
        // The article's cover is already a built image; build fresh ones on the plain background.
        $bg = $photo && $photo['origin'] !== 'built' ? (int)$photo['id'] : null;
        $default = SmEvent::settings($aid)['blog_link_default'];
        foreach ($platforms as $p => $row) {
            $post = (array)($out['posts'][$p] ?? []);
            $text = trim((string)($post['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $shape = SmMedia::PLATFORM_SHAPE[$p];
            if ($photo && $photo['kind'] === 'video') {
                $images[$shape] = (int)$photo['id'];   // a video is posted as it is
            } elseif (!isset($images[$shape]) && $headline !== '') {
                $b = SmMedia::build($agent, $headline, $shape, $bg, $userId);
                $images[$shape] = !empty($b['ok']) ? (int)$b['id'] : null;
            }
            db()->insert('sm_posts', [
                'agent_id' => $aid, 'article_id' => $articleId, 'platform' => $p, 'body' => $text,
                'hashtags' => mb_substr(trim((string)($post['hashtags'] ?? '')), 0, 500),
                'include_link' => $articleId && $default ? 1 : 0,
                'media_id' => $images[$shape] ?? ($photo ? (int)$photo['id'] : null),
                'status' => 'draft', 'written_by' => $userId,
            ]);
            $made++;
        }
        SmActivity::log($aid, 'posts_written', $made . ' platform version(s)' . ($articleId ? ' for article #' . $articleId : ''));
        return ['ok' => $made > 0, 'message' => $made > 0 ? "$made post(s) written, one per platform. Check each one, then send it for approval." : 'The AI wrote no usable posts. Try again.'];
    }

    /** The tracked link a post carries, or null. */
    public static function link(array $agent, array $post): ?string
    {
        if ((int)$post['include_link'] !== 1 || empty($post['article_id'])) {
            return null;
        }
        $url = (string)db()->value('SELECT blog_url FROM sm_articles WHERE id = ?', [(int)$post['article_id']]);
        if ($url === '') {
            return null;
        }
        $q = http_build_query(['utm_source' => $post['platform'], 'utm_medium' => 'social', 'utm_campaign' => (string)$agent['slug'], 'utm_content' => 'post-' . $post['id']]);
        return $url . (str_contains($url, '?') ? '&' : '?') . $q;
    }

    /** Can this post be approved / posted now? null when yes. */
    public static function blocker(array $agent, array $post): ?string
    {
        if ((int)$post['include_link'] === 1 && !empty($post['article_id']) && self::link($agent, $post) === null) {
            return 'The blog article is not published yet, so there is no link. Wait for it, or untick "Include the blog post link".';
        }
        foreach (SmChecks::post($agent, $post, self::link($agent, $post)) as $c) {
            if ($c['level'] === 'bad') {
                return $c['message'];
            }
        }
        return null;
    }

    /** Scheduled posts whose time has come become "due"; the team is reminded. */
    public static function dueNow(?int $now = null): int
    {
        $now ??= time();
        $rows = db()->all("SELECT p.* FROM sm_posts p JOIN agents g ON g.id = p.agent_id AND g.status <> 'deleted'
                           WHERE p.status = 'scheduled' AND p.scheduled_at <= ?", [gmdate('Y-m-d H:i:s', $now)]);
        $n = 0;
        foreach ($rows as $p) {
            if (db()->update('sm_posts', ['status' => 'due'], 'id = ? AND status = ?', [(int)$p['id'], 'scheduled']) === 1) {
                self::remind($p);
                $n++;
            }
        }
        return $n;
    }

    /** One email per due post to the exhibition's reminder addresses. */
    public static function remind(array $post): void
    {
        $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$post['agent_id']]);
        if (!$agent || !empty($post['reminder_sent_at'])) {
            return;
        }
        $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)SmEvent::settings((int)$agent['id'])['reminder_emails']) ?: []));
        $platform = SmEvent::PLATFORMS[$post['platform']] ?? $post['platform'];
        $link = url('social/post.php?id=' . (int)$post['id'] . '&agent=' . (int)$agent['id']);
        $subject = $agent['name'] . ': time to post on ' . $platform;
        $body = "A scheduled post is due on $platform for {$agent['name']}.\n\n"
            . "Open it, copy the text, download the picture, post it on the event's $platform page, then press \"Done — I posted it\":\n$link\n\n"
            . "— AI Social Media Expert Agent";
        $sent = false;
        // Only through this exhibition's own email profile (never another's); without one, the dashboard shows it.
        if (MailProfile::problem($agent) === null) {
            $profile = MailProfile::forAgent($agent);
            foreach ($to as $addr) {
                if (Validator::email($addr) !== null) {
                    $sent = (bool)Mailer::sendWith($profile, $addr, $subject, $body)['ok'] || $sent;
                }
            }
        }
        db()->update('sm_posts', ['reminder_sent_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [(int)$post['id']]);
        SmActivity::log((int)$agent['id'], 'reminder', $platform . ' post #' . $post['id'] . ($sent ? ' — emailed' : ' — shown on the dashboard'), ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
    }

    /** Check articles the blog is still holding. */
    public static function pollBlog(): int
    {
        $n = 0;
        foreach (db()->all("SELECT a.*, g.slug FROM sm_articles a JOIN agents g ON g.id = a.agent_id AND g.status <> 'deleted'
                            WHERE a.status = 'sent' AND a.blog_article_id IS NOT NULL ORDER BY a.updated_at ASC LIMIT 30") as $a) {
            db()->exec('UPDATE sm_articles SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [(int)$a['id']]);
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$a['agent_id']]);
            $blog = SmBlog::forAgent((int)$a['agent_id']);
            if (!$agent || !$blog) {
                continue;
            }
            $r = $blog->getArticle((int)$a['blog_article_id']);
            if ($r['ok']) {
                self::applyRemote($agent, (int)$a['id'], (array)($r['data']['article'] ?? []));
                $n++;
            }
        }
        return $n;
    }

    // -----------------------------------------------------------------
    // Articles written directly in the blog
    // -----------------------------------------------------------------

    /**
     * A published blog article that /social did not write: add it to Blog
     * articles (marked "Written in the blog") and write a draft post for each
     * platform. Nothing is posted until a person approves it.
     * @return int the /social article id, or 0 when it was skipped
     */
    public static function importFromBlog(array $agent, array $remote): int
    {
        $aid = (int)$agent['id'];
        $blogId = (int)($remote['id'] ?? 0);
        if ($blogId <= 0 || ($remote['status'] ?? 'published') !== 'published' || empty($remote['url'])) {
            return 0;
        }
        if (preg_match('/^sm-' . $aid . '-\d+$/', (string)($remote['external_ref'] ?? '')) === 1) {
            return 0;   // one of ours: applyRemote() handles it
        }
        if (!SmEvent::settings($aid)['blog_written_posts']) {
            return 0;
        }
        if (db()->value('SELECT id FROM sm_articles WHERE agent_id = ? AND blog_article_id = ? LIMIT 1', [$aid, $blogId])) {
            return 0;   // already known
        }
        $done = db()->exec('INSERT IGNORE INTO sm_articles (agent_id, origin, pillar, title, excerpt, body, category, tags, status, blog_article_id, blog_status, blog_url, published_at)
                            VALUES (?, \'blog\', \'insight\', ?, ?, ?, ?, ?, \'published\', ?, \'published\', ?, ?)', [
            $aid, mb_substr((string)($remote['title'] ?? 'Untitled'), 0, 255), mb_substr(strip_tags((string)($remote['excerpt'] ?? '')), 0, 400),
            SmHtml::clean((string)($remote['body'] ?? '')), mb_substr((string)($remote['category'] ?? ''), 0, 120),
            mb_substr(implode(', ', array_map('strval', (array)($remote['tags'] ?? []))), 0, 500), $blogId, mb_substr((string)$remote['url'], 0, 500),
            gmdate('Y-m-d H:i:s', strtotime((string)($remote['published_at'] ?? 'now')) ?: time()),
        ]);
        if ($done !== 1) {
            return 0;   // already known
        }
        $id = (int)db()->value('SELECT id FROM sm_articles WHERE agent_id = ? AND blog_article_id = ?', [$aid, $blogId]);
        SmActivity::log($aid, 'blog_article_found', (string)($remote['title'] ?? ''), ['id' => 0, 'full_name' => 'Blog', 'username' => 'blog']);
        // Its cover picture goes into the library and behind the post images.
        $blog = SmBlog::forAgent($aid);
        if ($blog && !empty($remote['image_url']) && preg_match('#^https?://#i', (string)$remote['image_url'])) {
            $bytes = $blog->download((string)$remote['image_url']);
            if ($bytes !== null) {
                $st = SmMedia::store($aid, $bytes, 'From the blog: ' . mb_substr((string)$remote['title'], 0, 150), 'upload', null);
                if (!empty($st['ok'])) {
                    db()->update('sm_articles', ['cover_media_id' => (int)$st['id']], 'id = ?', [$id]);
                }
            }
        }
        if (SmAi::blocked($aid, 'text') === null) {
            self::makeVersions($agent, $id, null);
        }
        return $id;
    }

    /**
     * Every few minutes (cron): pick up articles published in the blog since
     * the last look, and refresh the list of articles waiting in the blog.
     */
    public static function syncBlog(array $agent): array
    {
        $aid = (int)$agent['id'];
        $blog = SmBlog::forAgent($aid);
        if (!$blog) {
            return ['found' => 0, 'pending' => null];
        }
        $row = SmEvent::row($aid);
        $found = 0;
        $seen = (string)($row['blog_seen_at'] ?? '');
        if ($seen === '') {
            self::startBlogCursor($aid, $blog);
        } else {
            $r = $blog->published(gmdate('c', strtotime($seen . ' UTC')), (int)($row['blog_seen_id'] ?? 0), 20);
            if ($r['ok']) {
                $last = [$seen, (int)($row['blog_seen_id'] ?? 0)];
                foreach ((array)($r['data']['articles'] ?? []) as $art) {
                    if (self::importFromBlog($agent, (array)$art) > 0) {
                        $found++;
                    }
                    $t = strtotime((string)($art['published_at'] ?? ''));
                    if ($t) {
                        $last = [gmdate('Y-m-d H:i:s', $t), (int)($art['id'] ?? 0)];   // the list is oldest first
                    }
                }
                db()->update('sm_events', ['blog_seen_at' => $last[0], 'blog_seen_id' => $last[1]], 'agent_id = ?', [$aid]);
            }
        }
        return ['found' => $found, 'pending' => self::refreshPending($aid, $blog)];
    }

    /**
     * First look at a newly connected blog: remember its newest published article,
     * so everything it already had is left alone and only later articles are picked up.
     * Uses the blog's own clock. Nothing is remembered if the blog cannot be reached.
     */
    public static function startBlogCursor(int $aid, ?SmBlog $blog = null): bool
    {
        $blog ??= SmBlog::forAgent($aid);
        $r = $blog ? $blog->newestPublished() : ['ok' => false];
        if (!$r['ok']) {
            return false;
        }
        $top = ((array)($r['data']['articles'] ?? []))[0] ?? null;
        $t = $top ? strtotime((string)($top['published_at'] ?? '')) : false;
        db()->update('sm_events', [
            'blog_seen_at' => $t ? gmdate('Y-m-d H:i:s', $t) : '1970-01-01 00:00:01',
            'blog_seen_id' => $t ? (int)$top['id'] : 0,
        ], 'agent_id = ?', [$aid]);
        return true;
    }

    /** The blog's own waiting list, kept for the dashboard. */
    public static function refreshPending(int $aid, ?SmBlog $blog = null): ?int
    {
        $blog ??= SmBlog::forAgent($aid);
        if (!$blog) {
            return null;
        }
        $r = $blog->pending();
        if (!$r['ok']) {
            return null;
        }
        $list = array_values(array_map(static fn($a) => [
            'id' => (int)($a['id'] ?? 0), 'title' => (string)($a['title'] ?? ''), 'author' => (string)($a['author'] ?? ''),
            'from_api' => (bool)($a['from_api'] ?? false), 'updated_at' => (string)($a['updated_at'] ?? ''), 'admin_url' => (string)($a['admin_url'] ?? ''),
        ], (array)($r['data']['articles'] ?? [])));
        db()->update('sm_events', ['blog_pending' => json_encode($list, JSON_UNESCAPED_UNICODE), 'blog_pending_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [$aid]);
        return count($list);
    }

    /** Run syncBlog() for every exhibition with a connected blog. */
    public static function syncAllBlogs(): int
    {
        $n = 0;
        foreach (db()->all("SELECT g.* FROM sm_events e JOIN agents g ON g.id = e.agent_id AND g.status <> 'deleted'
                            WHERE e.blog_url IS NOT NULL AND e.blog_api_key IS NOT NULL") as $agent) {
            $n += self::syncBlog($agent)['found'];
        }
        return $n;
    }

    /** Approved articles that never reached the blog (a timeout, a blog that was down). */
    public static function retryApproved(): int
    {
        $n = 0;
        foreach (db()->all("SELECT a.id, a.agent_id, a.approved_by FROM sm_articles a JOIN agents g ON g.id = a.agent_id AND g.status <> 'deleted'
                            WHERE a.status = 'approved' AND a.approved_by IS NOT NULL AND a.updated_at < ? LIMIT 5", [gmdate('Y-m-d H:i:s', time() - 600)]) as $r) {
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$r['agent_id']]);
            if ($agent) {
                self::sendToBlog($agent, (int)$r['id'], (int)$r['approved_by']);
                $n++;
            }
        }
        return $n;
    }

    /** Finish articles left half-written (a closed browser, a timeout). */
    public static function resumeWriting(): int
    {
        $stale = gmdate('Y-m-d H:i:s', time() - 900);
        $rows = db()->all("SELECT a.id, a.agent_id FROM sm_articles a JOIN agents g ON g.id = a.agent_id AND g.status <> 'deleted'
                           WHERE a.status = 'writing' AND a.updated_at < ? LIMIT 3", [$stale]);
        $n = 0;
        foreach ($rows as $r) {
            // Claim it, so two runs never write the same article.
            if (db()->exec("UPDATE sm_articles SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'writing' AND updated_at < ?", [(int)$r['id'], $stale]) !== 1) {
                continue;
            }
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$r['agent_id']]);
            if ($agent) {
                self::write($agent, (int)$r['id']);
                $n++;
            }
        }
        return $n;
    }
}
