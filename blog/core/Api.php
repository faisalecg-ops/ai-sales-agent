<?php
declare(strict_types=1);

/** The blog's API for the Social Media Agent. See docs/blog-api.md. */
final class Api
{
    private static array $key = [];

    public static function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $pos = strpos($path, '/api/');
        $route = trim($pos === false ? '' : substr($path, $pos + 5), '/');
        try {
            self::dispatch($method, $route);
        } catch (Throwable $ex) {
            blog_log('api', 'error', ['route' => $route, 'error' => $ex->getMessage()]);
            self::fail('server_error', 'Something went wrong on the blog. Try again later.', 500);
        }
    }

    public static function fail(string $code, string $message, int $status, array $extra = []): never
    {
        json_out(['ok' => false, 'error' => $code, 'message' => $message] + $extra, $status);
    }

    private static function secure(): bool
    {
        if (cfg('api_allow_http', false)) {
            return true;   // local testing only
        }
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private static function authenticate(): void
    {
        if (!self::secure()) {
            self::fail('https_required', 'The API only works over https://', 403);
        }
        $ipBucket = 'apifail:' . ip_hash();
        $since = gmdate('Y-m-d H:i:s', time() - 600);
        if ((int)db()->value('SELECT COUNT(*) FROM {p}rate_events WHERE bucket = ? AND created_at > ?', [$ipBucket, $since]) >= 20) {
            self::fail('rate_limited', 'Too many failed attempts. Wait ten minutes.', 429);
        }
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        $row = $key !== '' ? ApiKey::verify($key) : null;
        if (!$row) {
            RateLimit::hit($ipBucket, 1000, 600);
            self::fail('unauthorized', 'Missing, wrong or switched-off API key.', 401);
        }
        if (!RateLimit::hit('api:' . $row['id'], 60, 60)) {
            header('Retry-After: 60');
            self::fail('rate_limited', 'At most 60 requests a minute per key.', 429);
        }
        self::$key = $row;
    }

    private static function input(): array
    {
        $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
            return $_POST;
        }
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::fail('bad_json', 'The request body is not valid JSON.', 400);
        }
        return $data;
    }

    private static function dispatch(string $method, string $route): void
    {
        $parts = explode('/', $route);
        if (($parts[0] ?? '') !== 'v1') {
            self::fail('not_found', 'Unknown address. The API lives at /api/v1/.', 404);
        }
        array_shift($parts);
        self::authenticate();
        $r = implode('/', $parts);

        match (true) {
            $method === 'GET' && $r === 'ping' => json_out(['ok' => true, 'site' => base_url(), 'event' => Event::name(), 'version' => BLOG_VERSION,
                'permission' => self::$key['permission']]),
            $method === 'GET' && $r === 'categories' => json_out(['ok' => true, 'categories' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'slug' => $c['slug']],
                db()->all('SELECT id, name, slug FROM {p}categories ORDER BY sort, name'))]),
            $method === 'GET' && $r === 'links' => json_out(['ok' => true, 'links' => array_values(array_map(static fn($l) => ['label' => $l['label'], 'url' => $l['url'], 'kind' => $l['kind'],
                'sponsored' => (bool)$l['sponsored'], 'keywords' => $l['keywords']], LinkPolicy::library()))]),
            $method === 'POST' && $r === 'media' => self::uploadMedia(),
            $method === 'POST' && $r === 'articles' => self::createArticle(),
            $method === 'GET' && $r === 'articles' => self::listArticles(),
            $method === 'GET' && (bool)preg_match('#^articles/(\d+)$#', $r, $m) => json_out(['ok' => true, 'article' => self::articleOut(self::ownArticle((int)$m[1]))]),
            in_array($method, ['PATCH', 'PUT', 'POST'], true) && (bool)preg_match('#^articles/(\d+)$#', $r, $m) => self::updateArticle((int)$m[1]),
            default => self::fail('not_found', 'Unknown address or method.', 404),
        };
    }

    private static function uploadMedia(): never
    {
        $tmp = null;
        $name = 'image.jpg';
        $in = [];
        if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $tmp = (string)$_FILES['file']['tmp_name'];
            $name = (string)$_FILES['file']['name'];
            $in = $_POST;
        } else {
            $in = self::input();
            $data = (string)($in['data'] ?? '');
            if (str_contains($data, ',') && str_starts_with($data, 'data:')) {
                $data = substr($data, strpos($data, ',') + 1);
            }
            $bin = base64_decode($data, true);
            if ($bin === false || $bin === '') {
                self::fail('invalid', 'Send the image as a multipart "file", or as JSON with base64 "data".', 422);
            }
            if (strlen($bin) > Media::MAX_BYTES) {
                self::fail('too_large', 'Images must be smaller than 12 MB.', 413);
            }
            $tmp = tempnam(BLOG_STORAGE . '/tmp', 'api');
            file_put_contents($tmp, $bin);
            $name = (string)($in['filename'] ?? 'image.jpg');
        }
        $res = Media::store($tmp, $name, null, (int)self::$key['id'], [
            'alt' => (string)($in['alt'] ?? ''), 'caption' => (string)($in['caption'] ?? ''), 'credit' => (string)($in['credit'] ?? '')]);
        if (empty($_FILES['file']) && $tmp) {
            @unlink($tmp);
        }
        if (!$res['ok']) {
            self::fail('invalid_image', (string)$res['error'], 422);
        }
        $m = Media::find((int)$res['id']);
        Activity::log('api.media.uploaded', $name, [], (int)self::$key['id']);
        json_out(['ok' => true, 'media' => ['id' => (int)$m['id'], 'url' => Media::url($m, 1200), 'width' => (int)$m['width'], 'height' => (int)$m['height']]], 201);
    }

    /** Turn API fields into article columns, with checks. */
    private static function fields(array $in, bool $creating): array
    {
        $d = [];
        $errors = [];
        if ($creating || array_key_exists('title', $in)) {
            $d['title'] = trim(mb_substr((string)($in['title'] ?? ''), 0, 255));
            if ($d['title'] === '') {
                $errors[] = 'title is required';
            }
        }
        if ($creating || array_key_exists('body', $in)) {
            $d['body'] = (string)($in['body'] ?? '');
            if (mb_strlen(plain_text(Html::clean($d['body']))) < 50) {
                $errors[] = 'body is too short (HTML or plain text, at least 50 characters)';
            }
        }
        foreach (['excerpt' => 600, 'seo_title' => 190, 'meta_description' => 320] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $d[$k] = mb_substr(trim((string)$in[$k]), 0, $max);
            }
        }
        if (array_key_exists('category', $in)) {
            $c = $in['category'];
            $cat = is_int($c) || ctype_digit((string)$c)
                ? db()->one('SELECT id FROM {p}categories WHERE id = ?', [(int)$c])
                : db()->one('SELECT id FROM {p}categories WHERE slug = ? OR name = ?', [slugify((string)$c), (string)$c]);
            $cat ? $d['category_id'] = (int)$cat['id'] : $errors[] = 'category not found (use an id or slug from GET /categories)';
        } elseif ($creating) {
            $errors[] = 'category is required';
        }
        foreach (['image_id', 'share_image_id'] as $k) {
            if (array_key_exists($k, $in) && $in[$k] !== null) {
                Media::find((int)$in[$k]) ? $d[$k] = (int)$in[$k] : $errors[] = $k . ' does not exist (upload with POST /media first)';
            }
        }
        if (array_key_exists('sources', $in)) {
            $src = array_values(array_filter((array)$in['sources'], static fn($s) => is_string($s) && is_web_url($s)));
            $d['sources'] = json_encode(array_slice($src, 0, 30), JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('external_ref', $in)) {
            $d['external_ref'] = mb_substr((string)$in['external_ref'], 0, 120) ?: null;
        }
        if ($errors) {
            self::fail('invalid', implode('; ', $errors) . '.', 422, ['errors' => $errors]);
        }
        return $d;
    }

    private static function tagsFrom(array $in): ?array
    {
        if (!array_key_exists('tags', $in)) {
            return null;
        }
        $t = $in['tags'];
        return is_array($t) ? array_map('strval', $t) : array_map('trim', explode(',', (string)$t));
    }

    private static function createArticle(): never
    {
        $in = self::input();
        $keyId = (int)self::$key['id'];
        if (!empty($in['external_ref'])) {
            $dup = db()->one('SELECT id FROM {p}articles WHERE api_key_id = ? AND external_ref = ?', [$keyId, mb_substr((string)$in['external_ref'], 0, 120)]);
            if ($dup) {
                self::fail('duplicate', 'An article with this external_ref already exists.', 409, ['id' => (int)$dup['id']]);
            }
        }
        $d = self::fields($in, true);
        $id = Article::save(null, $d, self::tagsFrom($in), 'Received from the Social Media Agent', $keyId);
        Activity::log('api.article.created', (string)$d['title'], ['id' => $id], $keyId);
        $note = self::decide($id, $in);
        json_out(['ok' => true, 'article' => self::articleOut(Article::find($id)), 'note' => $note], 201);
    }

    /**
     * Where a new or changed article goes. A drafts-only key: awaiting approval.
     * A publishing key may publish or schedule — unless a link needs a person's check.
     */
    private static function decide(int $id, array $in): string
    {
        $keyId = (int)self::$key['id'];
        $a = Article::find($id);
        $wantsPublish = !empty($in['publish']) || !empty($in['publish_at']);
        if (self::$key['permission'] !== 'publish' || !$wantsPublish) {
            Article::transition($id, 'pending', null, '', $keyId);
            return 'Awaiting approval by a person.';
        }
        $review = LinkPolicy::review((string)$a['body'], json_decode((string)$a['sources'], true) ?: []);
        if ($review['flagged'] > 0) {
            Article::transition($id, 'pending', null, 'Held for approval: ' . $review['flagged'] . ' link(s) are not in the link library, not articles of this blog and not declared sources.', $keyId);
            return 'Held for approval because of unapproved links.';
        }
        if (!empty($in['publish_at'])) {
            $t = strtotime((string)$in['publish_at']);
            if ($t === false || $t <= time()) {
                Article::transition($id, 'pending', null, 'Held: the publish time given was not in the future.', $keyId);
                return 'Held for approval: publish_at must be a future time (ISO 8601).';
            }
            $res = Article::transition($id, 'scheduled', gmdate('Y-m-d H:i:s', $t), '', $keyId);
            return $res['ok'] ? 'Scheduled.' : 'Held: ' . $res['error'];
        }
        $res = Article::transition($id, 'published', null, '', $keyId);
        if (!$res['ok']) {
            Article::transition($id, 'pending', null, $res['error'], $keyId);
            return 'Held for approval: ' . $res['error'];
        }
        return 'Published.';
    }

    private static function ownArticle(int $id): array
    {
        $a = db()->one('SELECT * FROM {p}articles WHERE id = ? AND api_key_id IS NOT NULL', [$id]);
        if (!$a) {
            self::fail('not_found', 'No article with this id was created through the API.', 404);
        }
        return $a;
    }

    private static function updateArticle(int $id): never
    {
        $a = self::ownArticle($id);
        if (in_array($a['status'], ['published', 'scheduled', 'archived'], true)) {
            self::fail('locked', 'This article is ' . $a['status'] . '. Changes to it are made by a person in the blog admin.', 409);
        }
        $in = self::input();
        $d = self::fields($in, false);
        unset($d['external_ref']);
        Article::save($id, $d, self::tagsFrom($in), 'Updated by the Social Media Agent', (int)self::$key['id']);
        Activity::log('api.article.updated', (string)$a['title'], ['id' => $id], (int)self::$key['id']);
        $note = self::decide($id, $in);
        json_out(['ok' => true, 'article' => self::articleOut(Article::find($id)), 'note' => $note]);
    }

    private static function listArticles(): never
    {
        $where = 'api_key_id IS NOT NULL';
        $params = [];
        if (!empty($_GET['external_ref'])) {
            $where .= ' AND external_ref = ?';
            $params[] = mb_substr((string)$_GET['external_ref'], 0, 120);
        }
        if (!empty($_GET['status']) && isset(Article::STATUSES[(string)$_GET['status']])) {
            $where .= ' AND status = ?';
            $params[] = (string)$_GET['status'];
        }
        $rows = db()->all("SELECT * FROM {p}articles WHERE $where ORDER BY id DESC LIMIT 50", $params);
        json_out(['ok' => true, 'articles' => array_map([self::class, 'articleOut'], $rows)]);
    }

    public static function articleOut(array $a): array
    {
        $out = Webhook::articlePayload($a);
        $out['status'] = $a['status'];
        $out['status_label'] = Article::STATUSES[$a['status']] ?? $a['status'];
        $out['review_note'] = $a['review_note'];
        $out['publish_at'] = $a['publish_at'] ? gmdate('c', strtotime($a['publish_at'] . ' UTC')) : null;
        if ($a['status'] !== 'published') {
            $out['url'] = null;
        }
        return $out;
    }
}
