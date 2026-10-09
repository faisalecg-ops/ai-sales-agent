<?php
declare(strict_types=1);

/**
 * Rate limiting and abuse prevention (Section 46, 47).
 * Database-backed, because shared hosting offers no Redis and no daemon.
 */
final class RateLimiter
{
    /**
     * Record a hit and report whether the bucket is now over its limit.
     *
     * @return bool true when the request is allowed
     */
    public static function allow(string $bucket, int $limit, int $windowSeconds, ?int $agentId = null): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int)db()->value(
            'SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at >= ?',
            [$bucket, $since]
        );
        if ($count >= $limit) {
            return false;
        }
        db()->insert('rate_events', ['bucket' => $bucket, 'agent_id' => $agentId]);
        return true;
    }

    /** How many hits remain in the window, for diagnostics. */
    public static function remaining(string $bucket, int $limit, int $windowSeconds): int
    {
        $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
        $count = (int)db()->value(
            'SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at >= ?',
            [$bucket, $since]
        );
        return max(0, $limit - $count);
    }

    /**
     * Daily API budget (Section 46). Returns true when the agent is still
     * within both its request and token budgets for today.
     */
    public static function withinDailyBudget(array $agent): bool
    {
        $row = db()->one(
            'SELECT requests, input_tokens, output_tokens FROM usage_daily WHERE agent_id = ? AND usage_date = ?',
            [(int)$agent['id'], gmdate('Y-m-d')]
        );
        if (!$row) {
            return true;
        }
        $reqBudget = (int)($agent['daily_request_budget'] ?? 0);
        $tokBudget = (int)($agent['daily_token_budget'] ?? 0);
        if ($reqBudget > 0 && (int)$row['requests'] >= $reqBudget) {
            return false;
        }
        if ($tokBudget > 0 && ((int)$row['input_tokens'] + (int)$row['output_tokens']) >= $tokBudget) {
            return false;
        }
        return true;
    }

    public static function recordUsage(int $agentId, int $inputTokens, int $outputTokens, bool $error = false): void
    {
        db()->exec(
            'INSERT INTO usage_daily (agent_id, usage_date, requests, input_tokens, output_tokens, errors)
             VALUES (?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                requests = requests + 1,
                input_tokens = input_tokens + VALUES(input_tokens),
                output_tokens = output_tokens + VALUES(output_tokens),
                errors = errors + VALUES(errors)',
            [$agentId, gmdate('Y-m-d'), $inputTokens, $outputTokens, $error ? 1 : 0]
        );
    }

    /** Remove expired rate rows. Cheap enough to run opportunistically. */
    public static function prune(int $olderThanHours = 24): void
    {
        db()->exec(
            'DELETE FROM rate_events WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', time() - ($olderThanHours * 3600))]
        );
    }

    /**
     * Rule-based bot and abuse signals (Section 47).
     * The model is never the sole spam detector.
     *
     * @return string|null reason when the message looks abusive
     */
    public static function abuseReason(string $message, array $conversation): ?string
    {
        $len = mb_strlen($message);
        if ($len === 0) {
            return 'empty_message';
        }
        // A single character or token repeated to fill the box.
        if ($len > 40 && preg_match('/(.)\1{39,}/u', $message)) {
            return 'repeated_characters';
        }
        // Almost no letters in a long message.
        if ($len > 60) {
            $letters = preg_match_all('/\p{L}/u', $message);
            if ($letters !== false && $letters < ($len * 0.2)) {
                return 'low_text_content';
            }
        }
        // Many URLs in one message.
        if (preg_match_all('~https?://~i', $message) >= 4) {
            return 'link_spam';
        }
        // Identical to the visitor's previous message, repeatedly.
        if (!empty($conversation['id'])) {
            $prev = db()->all(
                'SELECT content FROM messages WHERE conversation_id = ? AND role = ?
                 ORDER BY sequence_number DESC LIMIT 3',
                [(int)$conversation['id'], 'visitor']
            );
            if (count($prev) === 3) {
                $same = array_filter($prev, static fn($r) => trim((string)$r['content']) === trim($message));
                if (count($same) === 3) {
                    return 'duplicate_flooding';
                }
            }
        }
        return null;
    }
}
