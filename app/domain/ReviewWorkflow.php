<?php
declare(strict_types=1);

/**
 * Knowledge and pricing review workflow (Section 24).
 *
 * Draft -> Pending Review -> Approved -> Archived. Nothing reaches a visitor
 * until it is approved. Editing an already-approved item sends it back for
 * review while the previously approved version stays live, so a mistaken
 * edit cannot quietly go out.
 *
 * Reviewers use a token link and need no account of any kind.
 */
final class ReviewWorkflow
{
    public const TYPES = [
        'knowledge_article'   => ['table' => 'knowledge_articles',   'label' => 'Knowledge article'],
        'faq'                 => ['table' => 'faq_entries',          'label' => 'FAQ'],
        'stall_package'       => ['table' => 'stall_packages',       'label' => 'Stand package'],
        'sponsorship_package' => ['table' => 'sponsorship_packages', 'label' => 'Sponsorship package'],
    ];

    /** Fields snapshotted so the approved version can keep serving. */
    private const SNAPSHOT_FIELDS = [
        'knowledge_article'   => ['title', 'body', 'category', 'keywords', 'stage_tags'],
        'faq'                 => ['question', 'answer', 'keywords', 'category'],
        'stall_package'       => ['package_name', 'package_type', 'stall_size', 'size_sqm', 'currency',
                                  'price', 'price_basis', 'pricing_mode', 'rate_per_sqm', 'min_sqm',
                                  'increment_sqm', 'max_sqm', 'edition_label', 'region_group',
                                  'description', 'included_items', 'tax_included', 'tax_rate_percent',
                                  'tax_label', 'tax_note', 'valid_from', 'valid_until'],
        'sponsorship_package' => ['tier_name', 'currency', 'price', 'benefits', 'quantity_available',
                                  'region_group', 'description', 'edition_label', 'tax_included',
                                  'tax_rate_percent', 'tax_label', 'tax_note',
                                  'valid_from', 'valid_until'],
    ];

    public static function table(string $type): ?string
    {
        return self::TYPES[$type]['table'] ?? null;
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public static function fetchItem(string $type, int $id, ?int $agentId = null): ?array
    {
        $table = self::table($type);
        if ($table === null) {
            return null;
        }
        $sql    = "SELECT * FROM {$table} WHERE id = ?";
        $params = [$id];
        if ($agentId !== null) {
            $sql .= ' AND agent_id = ?';
            $params[] = $agentId;
        }
        return db()->one($sql . ' LIMIT 1', $params) ?: null;
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    /**
     * Called after an administrator saves an edit.
     *
     * If the item was already approved, its approved values are snapshotted
     * and the status drops to pending_review. Every changed field is written
     * to the audit log with its old and new value (Section 48).
     */
    public static function recordEdit(
        array $agent,
        string $type,
        int $id,
        array $before,
        array $after,
        string $actor
    ): void {
        $table = self::table($type);
        if ($table === null) {
            return;
        }

        $watch = self::SNAPSHOT_FIELDS[$type] ?? array_keys($after);
        Audit::setActor($actor);
        $changes = Audit::logChanges(
            $type === 'stall_package' || $type === 'sponsorship_package' ? 'pricing_changed' : 'knowledge_changed',
            (int)$agent['id'],
            $type,
            $id,
            $before,
            $after,
            $watch
        );

        if ($changes === 0) {
            return;
        }

        $current = self::fetchItem($type, $id, (int)$agent['id']);
        if ($current === null) {
            return;
        }

        // The status is read from the record itself rather than from the
        // supplied "before" array, so a caller that passes only the changed
        // fields still gets the correct behaviour.
        $wasApproved = (string)$current['status'] === 'approved';
        if (!$wasApproved) {
            return;   // a draft stays a draft; nothing was live to protect
        }

        // The snapshot is the current row with the old values written back
        // over the fields that changed. That reconstructs the version that
        // was approved, even when only part of the record was edited.
        $snapshot = [];
        foreach ($watch as $field) {
            $snapshot[$field] = array_key_exists($field, $before)
                ? $before[$field]
                : ($current[$field] ?? null);
        }

        $update = ['status' => 'pending_review', 'reviewer_comment' => null];
        if (in_array($type, ['stall_package', 'sponsorship_package'], true)) {
            $update['approved_snapshot'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'knowledge_article') {
            $update['approved_version_title'] = (string)($before['title'] ?? '');
            $update['approved_version_body']  = (string)($before['body'] ?? '');
        } elseif ($type === 'faq') {
            $update['approved_version_question'] = (string)($before['question'] ?? '');
            $update['approved_version_answer']   = (string)($before['answer'] ?? '');
        }

        db()->update($table, $update, 'id = ? AND agent_id = ?', [$id, (int)$agent['id']]);

        Audit::log('review_status_changed', (int)$agent['id'], $type, $id,
            'Edited after approval; returned for review', 'status', 'approved', 'pending_review');
    }

    /** Move an item into the review queue. */
    public static function submitForReview(array $agent, string $type, int $id, string $actor): bool
    {
        $table = self::table($type);
        $item  = self::fetchItem($type, $id, (int)$agent['id']);
        if ($table === null || $item === null) {
            return false;
        }
        if ((string)$item['status'] === 'approved') {
            return true;
        }
        db()->update($table, ['status' => 'pending_review'], 'id = ?', [$id]);
        Audit::setActor($actor);
        Audit::log('review_status_changed', (int)$agent['id'], $type, $id, 'Submitted for review',
            'status', (string)$item['status'], 'pending_review');
        return true;
    }

    /** Approve directly from the admin panel. */
    public static function approve(array $agent, string $type, int $id, string $actor, ?string $comment = null): bool
    {
        return self::setDecision($agent, $type, $id, 'approved', $comment, $actor, null);
    }

    public static function reject(array $agent, string $type, int $id, string $actor, ?string $comment = null): bool
    {
        return self::setDecision($agent, $type, $id, 'rejected', $comment, $actor, null);
    }

    public static function archive(array $agent, string $type, int $id, string $actor): bool
    {
        $table = self::table($type);
        $item  = self::fetchItem($type, $id, (int)$agent['id']);
        if ($table === null || $item === null) {
            return false;
        }
        db()->update($table, ['status' => 'archived', 'active' => 0], 'id = ?', [$id]);
        Audit::setActor($actor);
        Audit::log('review_status_changed', (int)$agent['id'], $type, $id, 'Archived',
            'status', (string)$item['status'], 'archived');
        return true;
    }

    /**
     * Apply an approve or reject decision.
     * Approval clears the snapshot, because the edit is now the live version.
     */
    private static function setDecision(
        array $agent,
        string $type,
        int $id,
        string $decision,
        ?string $comment,
        string $actor,
        ?int $tokenId
    ): bool {
        $table = self::table($type);
        $item  = self::fetchItem($type, $id, (int)$agent['id']);
        if ($table === null || $item === null) {
            return false;
        }

        $old = (string)$item['status'];
        $new = $decision === 'approved' ? 'approved' : 'draft';

        $update = [
            'status'           => $new,
            'reviewer_comment' => $comment !== null ? mb_substr($comment, 0, 2000) : null,
        ];

        if ($decision === 'approved') {
            // The edited values are now the approved values.
            if (in_array($type, ['stall_package', 'sponsorship_package'], true)) {
                $update['approved_snapshot'] = null;
            } elseif ($type === 'knowledge_article') {
                $update['approved_version_title'] = null;
                $update['approved_version_body']  = null;
            } elseif ($type === 'faq') {
                $update['approved_version_question'] = null;
                $update['approved_version_answer']   = null;
            }
        }

        db()->update($table, $update, 'id = ? AND agent_id = ?', [$id, (int)$agent['id']]);

        Audit::setActor($actor);
        Audit::log('review_decision', (int)$agent['id'], $type, $id,
            $decision . ($comment ? ': ' . $comment : ''), 'status', $old, $new);

        if ($tokenId !== null) {
            db()->insert('review_actions', [
                'token_id'    => $tokenId,
                'agent_id'    => (int)$agent['id'],
                'item_type'   => $type,
                'item_id'     => $id,
                'decision'    => $decision,
                'comment'     => $comment !== null ? mb_substr($comment, 0, 2000) : null,
                'reviewer_ip' => Security::clientIp(),
            ]);
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Reviewer tokens
    // -----------------------------------------------------------------

    /**
     * Issue a read-only review link for a batch of pending items.
     * Only the hash is stored, so the database never holds a usable link.
     *
     * @param array<string,int[]> $itemsByType
     * @return array{id:int,token:string,url:string,expires_at:string}
     */
    public static function createToken(
        array $agent,
        array $itemsByType,
        ?string $reviewerName,
        ?string $reviewerEmail,
        int $days,
        string $actor
    ): array {
        $clean = [];
        foreach ($itemsByType as $type => $ids) {
            if (!isset(self::TYPES[$type])) {
                continue;
            }
            $valid = [];
            foreach ((array)$ids as $id) {
                $id = (int)$id;
                if ($id > 0 && self::fetchItem($type, $id, (int)$agent['id']) !== null) {
                    $valid[] = $id;
                }
            }
            if ($valid) {
                $clean[$type] = array_values(array_unique($valid));
            }
        }

        $days     = max(1, min(60, $days));
        $token    = Crypto::token(32);
        $expires  = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
        $itemType = count($clean) === 1 ? (string)array_key_first($clean) : 'mixed';

        $id = db()->insert('knowledge_review_tokens', [
            'agent_id'       => (int)$agent['id'],
            'token_hash'     => Crypto::hashToken($token),
            'token_hint'     => mb_substr($token, 0, 8),
            'item_type'      => $itemType,
            'item_ids'       => json_encode($clean),
            'reviewer_name'  => Validator::text($reviewerName, 160),
            'reviewer_email' => Validator::email($reviewerEmail),
            'status'         => 'active',
            'expires_at'     => $expires,
        ]);

        Audit::setActor($actor);
        Audit::log('review_token_created', (int)$agent['id'], 'review_token', $id,
            'Batch of ' . array_sum(array_map('count', $clean)) . ' items, expires ' . $expires);

        return [
            'id'         => $id,
            'token'      => $token,
            'url'        => url('review/?t=' . rawurlencode($token)),
            'expires_at' => $expires,
        ];
    }

    /** Resolve a raw token to its active, unexpired record. */
    public static function resolveToken(?string $token): ?array
    {
        $token = trim((string)$token);
        if ($token === '' || strlen($token) < 20 || strlen($token) > 128) {
            return null;
        }
        $row = db()->one(
            "SELECT * FROM knowledge_review_tokens
             WHERE token_hash = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP()
             LIMIT 1",
            [Crypto::hashToken($token)]
        );
        return $row ?: null;
    }

    /** Items covered by a review link, with their current values. */
    public static function itemsForToken(array $token): array
    {
        $map  = json_decode((string)$token['item_ids'], true);
        $out  = [];
        if (!is_array($map)) {
            return $out;
        }
        foreach ($map as $type => $ids) {
            if (!isset(self::TYPES[$type])) {
                continue;
            }
            foreach ((array)$ids as $id) {
                $item = self::fetchItem($type, (int)$id, (int)$token['agent_id']);
                if ($item === null) {
                    continue;
                }
                $decision = db()->one(
                    'SELECT decision, comment FROM review_actions
                     WHERE token_id = ? AND item_type = ? AND item_id = ? ORDER BY id DESC LIMIT 1',
                    [(int)$token['id'], $type, (int)$id]
                );
                $out[] = [
                    'type'     => $type,
                    'label'    => self::label($type),
                    'item'     => $item,
                    'decided'  => $decision !== null,
                    'decision' => $decision['decision'] ?? null,
                    'comment'  => $decision['comment'] ?? null,
                ];
            }
        }
        return $out;
    }

    /** Record a reviewer's decision made through a token link. */
    public static function applyDecision(
        array $token,
        string $type,
        int $id,
        string $decision,
        ?string $comment,
        string $ip
    ): bool {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return false;
        }

        // The item must actually belong to this link.
        $map = json_decode((string)$token['item_ids'], true);
        if (!is_array($map) || !in_array($id, array_map('intval', (array)($map[$type] ?? [])), true)) {
            return false;
        }

        $agent = AgentResolver::byIdForAdmin((int)$token['agent_id']);
        if ($agent === null) {
            return false;
        }

        $reviewer = (string)($token['reviewer_name'] ?? '') !== ''
            ? 'reviewer:' . (string)$token['reviewer_name']
            : 'reviewer';

        return self::setDecision($agent, $type, $id, $decision, $comment, $reviewer, (int)$token['id']);
    }

    /** Close a link once the reviewer has finished with it. */
    public static function closeToken(array $token): void
    {
        db()->update('knowledge_review_tokens', [
            'status'  => 'used',
            'used_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$token['id']]);

        Audit::log('review_token_closed', (int)$token['agent_id'], 'review_token', (int)$token['id'], 'Reviewer finished');
    }

    public static function revokeToken(array $agent, int $tokenId, string $actor): bool
    {
        $row = db()->one('SELECT * FROM knowledge_review_tokens WHERE id = ? AND agent_id = ?',
            [$tokenId, (int)$agent['id']]);
        if (!$row) {
            return false;
        }
        db()->update('knowledge_review_tokens', ['status' => 'revoked'], 'id = ?', [$tokenId]);
        Audit::setActor($actor);
        Audit::log('review_token_revoked', (int)$agent['id'], 'review_token', $tokenId, 'Revoked by administrator');
        return true;
    }

    /** Notify the reviewer by email that a batch is waiting. */
    public static function emailReviewLink(array $agent, array $tokenResult, string $email, ?string $name, int $itemCount): void
    {
        $body = implode("\n", [
            trim(($name !== null && $name !== '' ? 'Hello ' . $name : 'Hello')) . ',',
            '',
            'Content for ' . (string)$agent['name'] . ' is ready for you to check before it goes live.',
            '',
            $itemCount === 1 ? 'There is 1 item to review.' : 'There are ' . $itemCount . ' items to review.',
            '',
            'Open this link to approve or reject each one. No login is needed:',
            (string)$tokenResult['url'],
            '',
            'The link stops working on ' . gmdate('j F Y', strtotime((string)$tokenResult['expires_at'])) . '.',
        ]);

        Mailer::queue($email, 'Please review content for ' . (string)$agent['name'], $body, (int)$agent['id']);
    }

    // -----------------------------------------------------------------
    // Queue
    // -----------------------------------------------------------------

    /** Everything currently awaiting review, for the admin queue. */
    public static function pendingItems(int $agentId): array
    {
        $out = [];
        foreach (self::TYPES as $type => $meta) {
            $rows = db()->all(
                "SELECT * FROM {$meta['table']} WHERE agent_id = ? AND status = 'pending_review' ORDER BY updated_at DESC",
                [$agentId]
            );
            foreach ($rows as $row) {
                $out[] = ['type' => $type, 'label' => $meta['label'], 'item' => $row];
            }
        }
        return $out;
    }

    public static function pendingCount(int $agentId): int
    {
        $n = 0;
        foreach (self::TYPES as $meta) {
            $n += (int)db()->value(
                "SELECT COUNT(*) FROM {$meta['table']} WHERE agent_id = ? AND status = 'pending_review'",
                [$agentId]
            );
        }
        return $n;
    }

    /** A short human-readable title for an item of any type. */
    public static function titleOf(string $type, array $item): string
    {
        return match ($type) {
            'knowledge_article'   => (string)$item['title'],
            'faq'                 => (string)$item['question'],
            'stall_package'       => (string)$item['package_name'] . ' (' . (string)$item['stall_size'] . ')',
            'sponsorship_package' => (string)$item['tier_name'],
            default               => 'Item ' . (string)$item['id'],
        };
    }
}
