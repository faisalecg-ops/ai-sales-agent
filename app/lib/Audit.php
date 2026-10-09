<?php
declare(strict_types=1);

/**
 * Audit trail (Section 48). Pricing and knowledge changes record the
 * individual field, old value and new value — not just "something changed".
 */
final class Audit
{
    private static ?string $actorOverride = null;
    private static ?int $actorId = null;

    public static function setActor(string $actor, ?int $adminUserId = null): void
    {
        self::$actorOverride = $actor;
        self::$actorId       = $adminUserId;
    }

    public static function log(
        string $event,
        ?int $agentId = null,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $detail = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null
    ): void {
        try {
            db()->insert('audit_log', [
                'agent_id'      => $agentId,
                'admin_user_id' => self::$actorId,
                'actor'         => self::$actorOverride ?? 'system',
                'event'         => mb_substr($event, 0, 64),
                'entity_type'   => $entityType ? mb_substr($entityType, 0, 48) : null,
                'entity_id'     => $entityId !== null ? mb_substr((string)$entityId, 0, 64) : null,
                'field'         => $field ? mb_substr($field, 0, 64) : null,
                'old_value'     => $oldValue !== null ? mb_substr($oldValue, 0, 2000) : null,
                'new_value'     => $newValue !== null ? mb_substr($newValue, 0, 2000) : null,
                'detail'        => $detail !== null ? mb_substr($detail, 0, 2000) : null,
                'ip'            => Security::clientIp(),
            ]);
        } catch (Throwable $ex) {
            app_log('audit', 'write failed', ['event' => $event, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * Compare two field maps and log one row per changed field.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param string[] $watch fields to compare
     */
    public static function logChanges(
        string $event,
        ?int $agentId,
        string $entityType,
        int|string $entityId,
        array $before,
        array $after,
        array $watch
    ): int {
        $count = 0;
        foreach ($watch as $field) {
            $old = array_key_exists($field, $before) ? (string)($before[$field] ?? '') : '';
            $new = array_key_exists($field, $after) ? (string)($after[$field] ?? '') : '';
            if ($old === $new) {
                continue;
            }
            self::log($event, $agentId, $entityType, $entityId, null, $field, $old, $new);
            $count++;
        }
        return $count;
    }

    /** Recent audit rows for an entity, for display in the admin panel. */
    public static function forEntity(string $entityType, int|string $entityId, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return db()->all(
            'SELECT * FROM audit_log WHERE entity_type = ? AND entity_id = ?
             ORDER BY id DESC LIMIT ' . $limit,
            [$entityType, (string)$entityId]
        );
    }
}
