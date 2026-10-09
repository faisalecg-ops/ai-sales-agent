<?php
declare(strict_types=1);

/** Simple sliding-window limits kept in the database (works on shared hosting). */
final class RateLimit
{
    /** Record one event and say whether the limit still allows it. */
    public static function hit(string $bucket, int $max, int $seconds): bool
    {
        $bucket = mb_substr($bucket, 0, 80);
        $since = gmdate('Y-m-d H:i:s', time() - $seconds);
        $count = (int)db()->value('SELECT COUNT(*) FROM {p}rate_events WHERE bucket = ? AND created_at > ?', [$bucket, $since]);
        if ($count >= $max) {
            return false;
        }
        db()->exec('INSERT INTO {p}rate_events (bucket, created_at) VALUES (?, ?)', [$bucket, now_utc()]);
        return true;
    }

    public static function prune(): void
    {
        db()->exec('DELETE FROM {p}rate_events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 2)]);
    }
}
