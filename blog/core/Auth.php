<?php
declare(strict_types=1);

/**
 * Admin sign-in, roles and the CSRF guard.
 *
 * Roles:
 *   admin    — everything, including users, branding, settings and API keys
 *   approver — edits any article and approves / publishes
 *   editor   — edits any article and manages the home page, banners, links,
 *              categories and comments; sends articles for approval
 *   author   — writes own articles and sends them for approval
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    private const PERMISSIONS = [
        'articles.own'     => ['admin', 'approver', 'editor', 'author'],
        'articles.all'     => ['admin', 'approver', 'editor'],
        'articles.publish' => ['admin', 'approver'],
        'media'            => ['admin', 'approver', 'editor', 'author'],
        'content'          => ['admin', 'approver', 'editor'],
        'comments'         => ['admin', 'approver', 'editor'],
        'sponsors'         => ['admin', 'approver', 'editor'],
        'subscribers'      => ['admin'],
        'settings'         => ['admin'],
        'users'            => ['admin'],
        'api'              => ['admin'],
        'activity'         => ['admin'],
    ];

    public static function roles(): array
    {
        return [
            'admin'    => 'Admin — everything',
            'approver' => 'Approver — edits and publishes articles',
            'editor'   => 'Editor — edits articles, home page, banners; sends for approval',
            'author'   => 'Author — writes own articles; sends for approval',
        ];
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = request_is_https();
        // A name of its own, so the blog never shares a session with the main website.
        // Based on where the blog really is, so fixing the Blog address does not sign anyone out.
        session_name('blogadm_' . substr(hash('sha256', install_path() . app_key()), 0, 10));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => install_path() . '/admin/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        }
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int)($_SESSION['blog_uid'] ?? 0);
            if ($id > 0) {
                $u = db()->one("SELECT * FROM {p}users WHERE id = ? AND status = 'active'", [$id]);
                // Signed out automatically after 12 idle hours.
                if ($u && (time() - (int)($_SESSION['seen'] ?? 0)) < 43200) {
                    self::$user = $u;
                    $_SESSION['seen'] = time();
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !self::$loaded) {
            return null;
        }
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function role(): string
    {
        return (string)(self::user()['role'] ?? '');
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        return $role !== '' && in_array($role, self::PERMISSIONS[$permission] ?? [], true);
    }

    /** May the signed-in user edit this article? */
    public static function canEditArticle(array $article): bool
    {
        if (self::can('articles.all')) {
            return true;
        }
        return self::can('articles.own')
            && (int)$article['author_id'] === self::id()
            && in_array($article['status'], ['draft', 'pending'], true);
    }

    /** Is this sign-in allowed right now (too many failures lock it for 15 minutes)? */
    public static function loginAllowed(string $email): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $byEmail = (int)db()->value('SELECT COUNT(*) FROM {p}login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [strtolower($email), $since]);
        $byIp = (int)db()->value('SELECT COUNT(*) FROM {p}login_attempts WHERE ip_hash = ? AND success = 0 AND created_at > ?', [ip_hash(), $since]);
        return $byEmail < 5 && $byIp < 20;
    }

    public static function attempt(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (!self::loginAllowed($email)) {
            return ['ok' => false, 'error' => 'locked'];
        }
        $u = db()->one('SELECT * FROM {p}users WHERE email = ?', [$email]);
        $ok = $u && $u['status'] === 'active' && password_verify($password, (string)$u['password_hash']);
        db()->insert('login_attempts', ['email' => $email, 'ip_hash' => ip_hash(), 'success' => $ok ? 1 : 0]);
        if (!$ok) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if (password_needs_rehash((string)$u['password_hash'], PASSWORD_DEFAULT)) {
            db()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int)$u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['blog_uid'] = (int)$u['id'];
        $_SESSION['seen'] = time();
        db()->update('users', ['last_login_at' => now_utc()], 'id = ?', [(int)$u['id']]);
        self::$loaded = false;
        self::$user = null;
        Activity::log('login', (string)$u['email']);
        return ['ok' => true, 'user' => $u];
    }

    /** Used by tests to act as a user without a browser session. */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(32);
        }
        return (string)$_SESSION['csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    public static function checkCsrf(): bool
    {
        $sent = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $sent !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $sent);
    }

    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'The password must be at least 10 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'The password must contain letters and numbers.';
        }
        return null;
    }
}
