<?php
declare(strict_types=1);

/**
 * What readers can send: comments (when switched on) and newsletter
 * sign-ups (when switched on). Both are protected from spam robots, and
 * sign-ups follow the same consent rules as the sales agent: a clear
 * consent line, confirmation by email, and one-click unsubscribe.
 */
final class Reader
{
    /** Robots fill hidden fields and submit instantly; people do neither. */
    public static function looksLikeRobot(array $post): bool
    {
        if (trim((string)($post['website'] ?? '')) !== '') {
            return true;
        }
        return time() - self::untimestamp((string)($post['_ts'] ?? '')) < 3;
    }

    public static function timestampField(): string
    {
        $t = time();
        return '<input type="hidden" name="_ts" value="' . e($t . '.' . substr(hash_hmac('sha256', (string)$t, app_key()), 0, 16)) . '">'
            . '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    }

    private static function untimestamp(string $v): int
    {
        if (preg_match('/^(\d+)\.([a-f0-9]{16})$/', $v, $m) && hash_equals(substr(hash_hmac('sha256', $m[1], app_key()), 0, 16), $m[2])) {
            return (int)$m[1];
        }
        return time();   // missing or forged: treat as instant
    }

    /** @return array{ok:bool, error?:string} */
    public static function addComment(int $articleId, array $post): array
    {
        if (Settings::get('comments_mode', 'off') !== 'approval') {
            return ['ok' => false, 'error' => 'closed'];
        }
        $a = db()->one("SELECT id FROM {p}articles WHERE id = ? AND status = 'published'", [$articleId]);
        if (!$a) {
            return ['ok' => false, 'error' => 'closed'];
        }
        if (self::looksLikeRobot($post)) {
            return ['ok' => true];   // say nothing to robots
        }
        $name = trim(mb_substr((string)($post['name'] ?? ''), 0, 120));
        $email = strtolower(trim((string)($post['email'] ?? '')));
        $body = trim(mb_substr((string)($post['comment'] ?? ''), 0, 3000));
        if ($name === '' || $body === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'missing'];
        }
        if (!RateLimit::hit('comment:' . ip_hash(), 5, 3600)) {
            return ['ok' => false, 'error' => 'too_many'];
        }
        $status = preg_match_all('#https?://#i', $body) > 2 ? 'spam' : 'pending';
        db()->insert('comments', ['article_id' => $articleId, 'name' => $name, 'email' => $email,
            'body' => $body, 'status' => $status, 'ip_hash' => ip_hash()]);
        return ['ok' => true];
    }

    public static function approvedComments(int $articleId): array
    {
        return db()->all("SELECT name, body, created_at FROM {p}comments WHERE article_id = ? AND status = 'approved' ORDER BY id", [$articleId]);
    }

    /** @return array{ok:bool, error?:string} */
    public static function subscribe(array $post, string $page): array
    {
        if (!Settings::get('newsletter_enabled', false)) {
            return ['ok' => false, 'error' => 'closed'];
        }
        if (self::looksLikeRobot($post)) {
            return ['ok' => true];
        }
        $email = strtolower(trim((string)($post['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return ['ok' => false, 'error' => 'email'];
        }
        if (empty($post['consent'])) {
            return ['ok' => false, 'error' => 'consent'];
        }
        if (!RateLimit::hit('subscribe:' . ip_hash(), 5, 3600)) {
            return ['ok' => false, 'error' => 'too_many'];
        }
        $existing = db()->one('SELECT * FROM {p}subscribers WHERE email = ?', [$email]);
        if ($existing && $existing['status'] === 'confirmed') {
            return ['ok' => true];   // already in; never reveal who is subscribed
        }
        $token = random_token(32);
        $row = ['status' => 'pending', 'token' => $token, 'name' => mb_substr(trim((string)($post['name'] ?? '')), 0, 120),
            'consent_text' => mb_substr((string)Settings::get('newsletter_consent', ''), 0, 500),
            'consent_page' => mb_substr($page, 0, 500), 'ip_hash' => ip_hash()];
        if ($existing) {
            db()->update('subscribers', $row, 'id = ?', [(int)$existing['id']]);
        } else {
            db()->insert('subscribers', $row + ['email' => $email]);
        }
        $event = Event::name();
        $confirm = url('confirm/' . $token);
        $text = t('nl_mail_body', ['event' => $event, 'link' => $confirm]);
        $html = '<p>' . nl2br(e(t('nl_mail_intro', ['event' => $event]))) . '</p><p><a href="' . e($confirm) . '" style="background:#111;color:#fff;padding:10px 18px;text-decoration:none;display:inline-block">'
            . e(t('nl_mail_button')) . '</a></p><p style="color:#666;font-size:13px">' . e(t('nl_mail_ignore')) . '</p>';
        Mailer::queue($email, t('nl_mail_subject', ['event' => $event]), $text, $html);
        Mailer::flush(3);
        return ['ok' => true];
    }

    public static function confirm(string $token): bool
    {
        $s = self::byToken($token);
        if (!$s || $s['status'] === 'unsubscribed') {
            return false;
        }
        db()->update('subscribers', ['status' => 'confirmed', 'confirmed_at' => $s['confirmed_at'] ?: now_utc()], 'id = ?', [(int)$s['id']]);
        return true;
    }

    public static function unsubscribe(string $token): bool
    {
        $s = self::byToken($token);
        if (!$s) {
            return false;
        }
        db()->update('subscribers', ['status' => 'unsubscribed', 'unsubscribed_at' => now_utc()], 'id = ?', [(int)$s['id']]);
        return true;
    }

    private static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
            return null;
        }
        return db()->one('SELECT * FROM {p}subscribers WHERE token = ?', [$token]);
    }
}
