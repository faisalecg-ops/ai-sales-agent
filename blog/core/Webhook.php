<?php
declare(strict_types=1);

/**
 * Tells the Social Media Agent when an article is published (the trigger
 * for writing its social posts). Queued first, then sent by cron with
 * retries, so a slow agent never slows down the person pressing Publish.
 * Each message is signed with the shared secret so the agent can trust it.
 */
final class Webhook
{
    private const BACKOFF = [60, 300, 1800, 7200, 43200, 86400];

    /** For tests: replaces the HTTP call. fn(string $url, string $body, array $headers): array{status:int, error:string} */
    public static $transport = null;

    public static function queue(string $event, array $article): void
    {
        if (trim((string)Settings::get('webhook_url', '')) === '') {
            return;
        }
        $payload = [
            'event'   => $event,
            'site'    => base_url(),
            'sent_at' => gmdate('c'),
            'article' => self::articlePayload($article),
        ];
        db()->insert('webhook_outbox', [
            'event'      => $event,
            'article_id' => (int)$article['id'],
            'payload'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function articlePayload(array $a): array
    {
        $cat = $a['category_id'] ? db()->one('SELECT name, slug FROM {p}categories WHERE id = ?', [(int)$a['category_id']]) : null;
        $img = Media::find((int)($a['image_id'] ?? 0));
        return [
            'id'           => (int)$a['id'],
            'external_ref' => $a['external_ref'],
            'title'        => $a['title'],
            'status'       => $a['status'],
            'url'          => Article::urlFor($a + ['cat' => $cat['slug'] ?? '']),
            'published_at' => $a['published_at'] ? gmdate('c', strtotime($a['published_at'] . ' UTC')) : null,
            'category'     => $cat['name'] ?? null,
            'tags'         => array_column(Article::tags((int)$a['id']), 'name'),
            'excerpt'      => Article::summary($a, 300),
            'image_url'    => $img ? Media::url($img, 1200) : null,
        ];
    }

    public static function sign(string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, (string)Settings::get('webhook_secret', ''));
    }

    /** Send what is due. Returns the number sent. */
    public static function flush(int $limit = 10): int
    {
        $url = trim((string)Settings::get('webhook_url', ''));
        if ($url === '') {
            return 0;
        }
        $sent = 0;
        foreach (db()->all("SELECT * FROM {p}webhook_outbox WHERE status = 'queued' AND next_try_at <= ? ORDER BY id LIMIT " . $limit, [now_utc()]) as $job) {
            $body = (string)$job['payload'];
            $headers = ['Content-Type: application/json', 'X-Blog-Event: ' . $job['event'], 'X-Blog-Signature: ' . self::sign($body),
                'User-Agent: EventBlog/' . BLOG_VERSION];
            $res = self::$transport ? (self::$transport)($url, $body, $headers) : self::post($url, $body, $headers);
            $attempts = (int)$job['attempts'] + 1;
            if ($res['status'] >= 200 && $res['status'] < 300) {
                db()->update('webhook_outbox', ['status' => 'sent', 'attempts' => $attempts, 'sent_at' => now_utc(), 'last_error' => ''], 'id = ?', [(int)$job['id']]);
                $sent++;
            } else {
                $failed = $attempts >= count(self::BACKOFF);
                db()->update('webhook_outbox', [
                    'status'      => $failed ? 'failed' : 'queued',
                    'attempts'    => $attempts,
                    'next_try_at' => gmdate('Y-m-d H:i:s', time() + self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)]),
                    'last_error'  => mb_substr(($res['status'] ? 'HTTP ' . $res['status'] . ' ' : '') . $res['error'], 0, 255),
                ], 'id = ?', [(int)$job['id']]);
            }
        }
        return $sent;
    }

    private static function post(string $url, string $body, array $headers): array
    {
        if (!str_starts_with($url, 'https://') && !defined('BLOG_TESTING')) {
            return ['status' => 0, 'error' => 'The webhook address must start with https://'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'error' => $error];
    }
}
