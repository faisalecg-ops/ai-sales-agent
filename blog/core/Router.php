<?php
declare(strict_types=1);

/** Answers every public address of the blog. */
final class Router
{
    public static function dispatch(string $path, bool $trailing = false): void
    {
        self::headers();
        self::publishDueBackup();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $parts = $path === '' ? [] : explode('/', $path);
        $n = count($parts);

        // Fixed addresses first.
        if ($path === 'sitemap.xml') {
            self::cached('sitemap', 'application/xml; charset=utf-8', static fn() => Seo::sitemap());
        }
        if ($path === 'feed' || $path === 'rss.xml' || $path === 'feed.xml') {
            self::cached('feed', 'application/rss+xml; charset=utf-8', static fn() => Seo::rss());
        }
        if ($path === 'theme.css') {
            header('Content-Type: text/css; charset=utf-8');
            header('Cache-Control: public, max-age=300');
            echo Theme::css();
            exit;
        }
        if ($n === 3 && $parts[0] === 'go' && $parts[1] === 'b' && ctype_digit($parts[2])) {
            $to = Banner::click((int)$parts[2]);
            header('X-Robots-Tag: noindex');
            $to ? redirect($to, 302) : self::notFound();
        }
        if ($n === 3 && $parts[0] === 'go' && $parts[1] === 'a' && ctype_digit($parts[2])) {
            $to = Sponsored::click((int)$parts[2], (string)($_GET['u'] ?? ''), (string)($_GET['s'] ?? ''));
            header('X-Robots-Tag: noindex');
            $to ? redirect($to, 302) : self::notFound();
        }
        if ($n === 1 && $parts[0] === Voices::writeSlug() && Voices::writeOpen()) {
            if ($method === 'POST') {
                $res = Voices::submit($_POST);
                redirect(url(Voices::writeSlug()) . '?w=' . ($res['ok'] ? 'thanks' : ($res['error'] ?? 'error')) . '#write', 303);
            }
            header('Cache-Control: no-store');
            echo Pages::write((string)($_GET['w'] ?? ''));
            exit;
        }
        if ($path === 'go/beacon' && $method === 'POST') {
            self::beacon();
        }
        if ($path === 'subscribe' && $method === 'POST') {
            self::subscribe();
        }
        if ($n === 2 && in_array($parts[0], ['confirm', 'unsubscribe'], true)) {
            self::subscription($parts[0], $parts[1]);
        }
        if ($n === 2 && $parts[0] === 'comment' && ctype_digit($parts[1]) && $method === 'POST') {
            self::comment((int)$parts[1]);
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            http_response_code(405);
            exit;
        }

        // Pages that are the same for every visitor are served from the page cache.
        $page = max(1, (int)($_GET['page'] ?? 1));
        $cacheable = $path !== 'search' && array_diff(array_keys($_GET), ['page']) === [];
        $key = 'page:' . $path . ($trailing ? '/' : '') . ':' . $page;
        if ($cacheable && ($hit = Cache::get($key)) !== null) {
            header('X-Cache: HIT');
            echo $hit;
            exit;
        }

        $html = match (true) {
            $n === 0 => Pages::home(),
            $path === 'latest' => Pages::latest($page),
            $path === 'search' => Pages::search((string)($_GET['q'] ?? ''), $page),
            $n === 2 && $parts[0] === 'tag' => Pages::tag($parts[1], $page),
            $n === 2 && $parts[0] === 'author' => Pages::author($parts[1], $page),
            $n === 1 && $parts[0] === Voices::slug() && Voices::enabled() => Pages::voices($page, $trailing),
            $n === 2 && $parts[0] === Voices::slug() && Voices::enabled() => Pages::contributor($parts[1], $page),
            $n === 1 => Pages::category($parts[0], $page, $trailing),
            $n === 2 => Pages::article($parts[0], $parts[1]),
            default => null,
        };
        if ($html === null) {
            self::redirectOrNotFound($path);
        }
        if ($cacheable) {
            Cache::put($key, $html);
        }
        header('X-Cache: MISS');
        echo $html;
        exit;
    }

    private static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    private static function cached(string $key, string $type, callable $make): never
    {
        $body = Cache::get($key);
        if ($body === null) {
            $body = $make();
            Cache::put($key, $body);
        }
        header('Content-Type: ' . $type);
        echo $body;
        exit;
    }

    /** If cron is not running, scheduled articles still go out on time (checked at most once a minute). */
    private static function publishDueBackup(): void
    {
        $marker = BLOG_STORAGE . '/cache/schedule.check';
        if (is_file($marker) && filemtime($marker) > time() - 60) {
            return;
        }
        @touch($marker);
        try {
            Article::publishDue();
        } catch (Throwable $ex) {
            blog_log('schedule', $ex->getMessage());
        }
    }

    public static function redirectOrNotFound(string $path): never
    {
        $r = db()->one('SELECT * FROM {p}redirects WHERE old_path = ?', [$path]);
        if ($r) {
            db()->exec('UPDATE {p}redirects SET hits = hits + 1 WHERE id = ?', [(int)$r['id']]);
            if ($r['article_id']) {
                $a = db()->one("SELECT a.slug, a.category_id, a.status FROM {p}articles a WHERE a.id = ?", [(int)$r['article_id']]);
                if ($a && $a['status'] === 'published') {
                    redirect(Article::urlFor($a), 301);
                }
            } elseif ($r['target'] !== null) {
                redirect(url((string)$r['target']), 301);
            }
        }
        self::notFound();
    }

    public static function notFound(): never
    {
        http_response_code(404);
        echo Pages::notFound();
        exit;
    }

    /** Counts article views and banner views sent by the page's script (works with cached pages). */
    private static function beacon(): never
    {
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($data)) {
            if (!empty($data['a']) && is_int($data['a'])) {
                Article::countView($data['a']);
            }
            if (!empty($data['b']) && is_array($data['b'])) {
                Banner::countViews(array_filter($data['b'], 'is_int'));
            }
        }
        http_response_code(204);
        exit;
    }

    private static function back(string $fallback, string $flag): never
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $to = $ref !== '' && is_internal_url($ref) ? preg_replace('/[?#].*$/', '', $ref) : $fallback;
        redirect($to . '?' . $flag . '#' . (str_starts_with($flag, 'c=') ? 'comments' : 'newsletter'), 303);
    }

    private static function subscribe(): never
    {
        $res = Reader::subscribe($_POST, (string)($_SERVER['HTTP_REFERER'] ?? ''));
        self::back(url(), 'nl=' . ($res['ok'] ? 'thanks' : ($res['error'] ?? 'error')));
    }

    private static function subscription(string $action, string $token): never
    {
        $ok = $action === 'confirm' ? Reader::confirm($token) : Reader::unsubscribe($token);
        header('X-Robots-Tag: noindex');
        echo Pages::message($ok ? t($action === 'confirm' ? 'nl_confirmed' : 'nl_unsubscribed') : t('nl_bad_link'));
        exit;
    }

    private static function comment(int $articleId): never
    {
        $res = Reader::addComment($articleId, $_POST);
        $a = db()->one('SELECT slug, category_id FROM {p}articles WHERE id = ?', [$articleId]);
        $fallback = $a ? Article::urlFor($a) : url();
        self::back($fallback, 'c=' . ($res['ok'] ? 'thanks' : ($res['error'] ?? 'error')));
    }
}
