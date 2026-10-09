<?php
declare(strict_types=1);

/**
 * The weekly broken-link check. Run by cron a few addresses at a time, so it
 * never strains shared hosting. Results appear in the admin under Links.
 */
final class LinkCheck
{
    /** For tests: fn(string $url): array{status:int, error:string} */
    public static $checker = null;

    /** Collect every link from published articles and the link library. */
    public static function collect(): int
    {
        $map = [];
        foreach (db()->all("SELECT id, body FROM {p}articles WHERE status = 'published'") as $a) {
            foreach (Html::links((string)$a['body']) as $l) {
                if (is_web_url($l['url'])) {
                    $map[$l['url']][] = (int)$a['id'];
                }
            }
        }
        foreach (db()->all('SELECT url FROM {p}links WHERE active = 1') as $l) {
            $map[$l['url']] = $map[$l['url']] ?? [];
        }
        foreach ($map as $u => $ids) {
            db()->exec('INSERT INTO {p}link_checks (url, url_hash, article_ids) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE article_ids = VALUES(article_ids)',
                [mb_substr($u, 0, 500), sha1($u), mb_substr(implode(',', array_unique($ids)), 0, 500)]);
        }
        // Links no longer used anywhere are forgotten.
        $keep = array_map('sha1', array_keys($map));
        foreach (db()->all('SELECT id, url_hash FROM {p}link_checks') as $r) {
            if (!in_array($r['url_hash'], $keep, true)) {
                db()->delete('link_checks', 'id = ?', [(int)$r['id']]);
            }
        }
        return count($map);
    }

    /** Check the links not checked for a week. */
    public static function run(int $limit = 15): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $rows = db()->all('SELECT * FROM {p}link_checks WHERE checked_at IS NULL OR checked_at < ? ORDER BY checked_at IS NOT NULL, checked_at LIMIT ' . $limit, [$since]);
        foreach ($rows as $r) {
            $res = self::$checker ? (self::$checker)((string)$r['url']) : self::probe((string)$r['url']);
            $ok = $res['status'] >= 200 && $res['status'] < 400
                || in_array($res['status'], [401, 403, 405, 429], true);   // exists, but refuses robots
            db()->update('link_checks', ['status_code' => $res['status'], 'ok' => $ok ? 1 : 0, 'error' => mb_substr($res['error'], 0, 255),
                'checked_at' => now_utc()], 'id = ?', [(int)$r['id']]);
        }
        return count($rows);
    }

    private static function probe(string $url): array
    {
        $do = static function (bool $head) use ($url): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_NOBODY => $head, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_RANGE => $head ? null : '0-1024',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; EventBlog-LinkCheck/' . BLOG_VERSION . ')']);
            curl_exec($ch);
            $r = ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error' => curl_error($ch)];
            curl_close($ch);
            return $r;
        };
        $r = $do(true);
        if ($r['status'] === 0 || $r['status'] >= 400) {
            $r = $do(false);   // some servers refuse HEAD requests
        }
        return $r;
    }

    public static function broken(): array
    {
        return db()->all('SELECT * FROM {p}link_checks WHERE ok = 0 AND checked_at IS NOT NULL ORDER BY checked_at DESC');
    }
}
