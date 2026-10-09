<?php
declare(strict_types=1);

/**
 * Messages waiting to be sent, and what happened to each one.
 *
 * Writing a message and sending it are separated on purpose. Writing needs
 * the language model and may fail or produce something that has to be
 * rejected; sending needs the network and a quota. Keeping them apart means
 * each retries on its own, an operator can read what is about to go out
 * before it does, and there is a permanent record of every message that
 * left — which is the first thing anyone asks for when a recipient
 * complains.
 *
 * The table carries a unique key on `dedupe_key`. That is what makes a
 * duplicated scheduled task, an overlapping run, or a webhook delivered
 * twice harmless: the second insert loses and no one is messaged twice.
 */
final class WhatsAppOutbox
{
    /**
     * Put a message in the queue.
     *
     * Returns the row id, or null when the same message is already queued —
     * not an error, and the caller treats it as "nothing to do".
     */
    public static function queue(array $agent, array $message): ?int
    {
        $agentId = (int)$agent['id'];

        $body = trim((string)($message['body'] ?? ''));
        if ($body === '') {
            return null;
        }

        $phone = (string)($message['phone_e164'] ?? '');
        if (!Phone::isReachable($phone)) {
            return null;
        }

        $kind = (string)($message['kind'] ?? 'opening');
        if (!in_array($kind, ['opening', 'reply', 'followup'], true)) {
            $kind = 'opening';
        }

        $dedupe = trim((string)($message['dedupe_key'] ?? ''));
        if ($dedupe === '') {
            // Without a caller-supplied key, the message itself is the key.
            $dedupe = $kind . ':' . $agentId . ':' . mb_substr(hash('sha256', $phone . '|' . $body), 0, 40);
        }

        try {
            return db()->insert('whatsapp_outbox', [
                'agent_id'        => $agentId,
                'registration_id' => isset($message['registration_id']) ? (int)$message['registration_id'] : null,
                'conversation_id' => isset($message['conversation_id']) ? (int)$message['conversation_id'] : null,
                'phone_e164'      => $phone,
                'body'            => mb_substr($body, 0, 4000),
                'kind'            => $kind,
                // A named member of the team, or null for the assistant.
                'sent_by'         => isset($message['sent_by']) && trim((string)$message['sent_by']) !== ''
                    ? mb_substr(trim((string)$message['sent_by']), 0, 64) : null,
                // Held messages wait for an operator; everything else is due.
                'status'          => !empty($message['hold']) ? 'held' : 'scheduled',
                'scheduled_for'   => (string)($message['scheduled_for'] ?? gmdate('Y-m-d H:i:s')),
                'used_fallback'   => !empty($message['used_fallback']) ? 1 : 0,
                'blocked_reason'  => isset($message['blocked_reason']) && $message['blocked_reason'] !== null
                    ? mb_substr((string)$message['blocked_reason'], 0, 255) : null,
                'dedupe_key'      => mb_substr($dedupe, 0, 96),
            ]);
        } catch (Throwable $ex) {
            // The unique key did its job. Anything else is worth knowing about.
            if (self::isDuplicate($ex)) {
                return null;
            }
            app_log('whatsapp', 'could not queue message', [
                'agent' => $agentId,
                'error' => $ex->getMessage(),
            ]);
            return null;
        }
    }

    private static function isDuplicate(Throwable $ex): bool
    {
        return $ex->getCode() === 1062 || str_contains($ex->getMessage(), 'Duplicate entry');
    }

    /**
     * The next batch that is due.
     *
     * Ordered oldest first so a backlog drains in the order it built up, and
     * one number per batch so a single person is never sent two messages in
     * the same run.
     *
     * `$kinds` narrows it, because replies and first messages are governed by
     * different rules and are therefore fetched separately.
     *
     * @param array<int,string> $kinds
     */
    public static function due(int $agentId, int $limit = 25, array $kinds = []): array
    {
        $limit = max(1, min(200, $limit));

        $sql    = "SELECT * FROM whatsapp_outbox
                    WHERE agent_id = ? AND status = 'scheduled' AND scheduled_for <= ?";
        $params = [$agentId, gmdate('Y-m-d H:i:s')];

        $kinds = array_values(array_intersect($kinds, ['opening', 'reply', 'followup']));
        if ($kinds) {
            $sql .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
            foreach ($kinds as $kind) {
                $params[] = $kind;
            }
        }

        // Fetched wide, because duplicate numbers are dropped below.
        $sql .= ' ORDER BY scheduled_for ASC, id ASC LIMIT ' . ($limit * 3);

        $rows = db()->all($sql, $params);

        $out  = [];
        $seen = [];

        foreach ($rows as $row) {
            $phone = (string)$row['phone_e164'];
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Claim a message.
     *
     * The change is conditional on it still being scheduled, so if two runs
     * overlap only one of them gets the message and the other moves on. The
     * caller must not send unless this returned true.
     *
     * `scheduled_for` is moved to now, which makes it the time of the
     * attempt — the only record of when a claim was taken, and what
     * releaseStuck() reads to find a run that was cut off.
     */
    public static function markSending(int $id): bool
    {
        return db()->exec(
            "UPDATE whatsapp_outbox
                SET status = 'sending', attempts = attempts + 1, scheduled_for = ?
              WHERE id = ? AND status = 'scheduled'",
            [gmdate('Y-m-d H:i:s'), $id]
        ) === 1;
    }

    public static function markSent(int $id, string $provider, ?string $reference): void
    {
        db()->update('whatsapp_outbox', [
            'status'       => 'sent',
            'provider'     => mb_substr($provider, 0, 32),
            'provider_ref' => $reference !== null ? mb_substr($reference, 0, 160) : null,
            'sent_at'      => gmdate('Y-m-d H:i:s'),
            'last_error'   => null,
        ], 'id = ?', [$id]);
    }

    /**
     * Record a failure.
     *
     * Put back for another try until the attempt limit, then left as failed.
     * A failed row is never silently discarded: someone was meant to receive
     * this and the operator should be able to see that they did not.
     *
     * Only a message that is actually being sent can fail. Anything else —
     * one an operator stopped in the meantime — is left exactly as it is,
     * so a failure can never put a stopped message back in the queue.
     */
    public static function markFailed(int $id, string $error, int $maxAttempts = 3): void
    {
        $attempts = (int)db()->value('SELECT attempts FROM whatsapp_outbox WHERE id = ?', [$id]);

        if ($attempts >= $maxAttempts) {
            db()->update('whatsapp_outbox', [
                'status'     => 'failed',
                'last_error' => mb_substr($error, 0, 400),
            ], "id = ? AND status = 'sending'", [$id]);
            return;
        }

        // Backs off, so a service having a bad hour is not hammered.
        $wait = min(240, 5 * (2 ** max(0, $attempts - 1)));

        db()->update('whatsapp_outbox', [
            'status'        => 'scheduled',
            'scheduled_for' => gmdate('Y-m-d H:i:s', time() + ($wait * 60)),
            'last_error'    => mb_substr($error, 0, 400),
        ], "id = ? AND status = 'sending'", [$id]);
    }

    /**
     * Stop a message that has not gone yet.
     *
     * Scoped to the exhibition, so one exhibition's screen can never stop
     * another's message. A message already being sent cannot be stopped —
     * it is in the messaging service's hands by then.
     */
    public static function cancel(int $agentId, int $id, string $reason): bool
    {
        return db()->update('whatsapp_outbox', [
            'status'     => 'cancelled',
            'last_error' => mb_substr($reason, 0, 400),
        ], "id = ? AND agent_id = ? AND status IN ('held','scheduled')", [$id, $agentId]) === 1;
    }

    /** Stop everything still waiting for one number. Used on an opt-out. */
    public static function cancelPending(int $agentId, string $phone, string $reason): int
    {
        return db()->update('whatsapp_outbox', [
            'status'     => 'cancelled',
            'last_error' => mb_substr($reason, 0, 400),
        ], "agent_id = ? AND phone_e164 = ? AND status IN ('held','scheduled')", [$agentId, $phone]);
    }

    /**
     * Anything claimed but never finished — a run cut off mid-send.
     *
     * We cannot know whether it reached the phone: the service may have
     * taken it a moment before the run died. Sending it again risks the one
     * thing this system must never do, so it is set aside as failed with a
     * plain explanation, and a person decides. They can see the number and
     * ask, or send it again knowingly.
     */
    public static function releaseStuck(int $agentId, int $olderThanMinutes = 15): int
    {
        return db()->update('whatsapp_outbox', [
            'status'     => 'failed',
            'last_error' => 'Sending was interrupted, so it is not known whether this arrived. '
                          . 'It has not been sent again. Check before trying again.',
        ], "agent_id = ? AND status = 'sending' AND scheduled_for <= ?",
            [$agentId, gmdate('Y-m-d H:i:s', time() - ($olderThanMinutes * 60))]);
    }

    /**
     * Set aside replies that have waited too long to be worth sending.
     *
     * An answer to "what time does it open?" that arrives the next afternoon
     * reads as a fault, not a reply. Kept, not deleted, so the team can see
     * what was not answered. Messages typed by the team are left alone —
     * a person chose to send those.
     */
    public static function expireOldReplies(int $agentId, int $hours = 12): int
    {
        return db()->update('whatsapp_outbox', [
            'status'     => 'cancelled',
            'last_error' => 'Not sent: it was more than ' . $hours . ' hours old by the time it could go, '
                          . 'and the conversation had moved on.',
        ], "agent_id = ? AND kind = 'reply' AND status = 'scheduled' AND sent_by IS NULL AND created_at < ?",
            [$agentId, gmdate('Y-m-d H:i:s', time() - ($hours * 3600))]);
    }

    // -----------------------------------------------------------------
    // For the admin screens
    // -----------------------------------------------------------------

    /** @return array<string,int> */
    public static function counts(int $agentId): array
    {
        $out = ['held' => 0, 'scheduled' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];

        foreach (db()->all(
            'SELECT status, COUNT(*) AS n FROM whatsapp_outbox WHERE agent_id = ? GROUP BY status',
            [$agentId]
        ) as $row) {
            $out[(string)$row['status']] = (int)$row['n'];
        }

        return $out;
    }

    /** Everything sent today, replies included, in the exhibition's own day. */
    public static function sentToday(array $agent): int
    {
        return (int)db()->value(
            "SELECT COUNT(*) FROM whatsapp_outbox
              WHERE agent_id = ? AND status = 'sent' AND sent_at >= ?",
            [(int)$agent['id'], WhatsAppEngine::dayStartUtc($agent)]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $agentId, string $status = '', int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql    = 'SELECT * FROM whatsapp_outbox WHERE agent_id = ?';
        $params = [$agentId];

        if (in_array($status, ['held', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'], true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        return db()->all($sql, $params);
    }

    public static function find(int $agentId, int $id): ?array
    {
        return db()->one('SELECT * FROM whatsapp_outbox WHERE agent_id = ? AND id = ?', [$agentId, $id]);
    }

    /** An operator's edit before sending. Only while it is still waiting. */
    public static function rewrite(int $agentId, int $id, string $body, ?string $actor = null): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        $changed = db()->update('whatsapp_outbox', [
            'body'           => mb_substr($body, 0, 4000),
            'used_fallback'  => 0,
            'blocked_reason' => null,
        ], "id = ? AND agent_id = ? AND status IN ('held','scheduled')", [$id, $agentId]) === 1;

        if ($changed) {
            Audit::log('whatsapp_message_edited', $agentId, 'whatsapp_outbox', $id,
                'Edited before sending', null, null, mb_substr($body, 0, 500));
        }

        return $changed;
    }

    /**
     * Let a held message go.
     *
     * It joins the ordinary queue, so the daily limit and the sending hours
     * still apply to it — approving a message says it is fit to send, not
     * that it should jump the rules about when.
     */
    public static function approve(int $agentId, int $id, ?string $actor = null): bool
    {
        $done = db()->update('whatsapp_outbox', [
            'status'        => 'scheduled',
            'scheduled_for' => gmdate('Y-m-d H:i:s'),
        ], "id = ? AND agent_id = ? AND status = 'held'", [$id, $agentId]) === 1;

        if ($done) {
            Audit::log('whatsapp_message_approved', $agentId, 'whatsapp_outbox', $id,
                'Approved to send' . ($actor !== null ? ' by ' . $actor : ''));
        }

        return $done;
    }

    /** Approve everything held for one exhibition. Returns how many. */
    public static function approveAll(int $agentId, ?string $actor = null): int
    {
        $count = db()->update('whatsapp_outbox', [
            'status'        => 'scheduled',
            'scheduled_for' => gmdate('Y-m-d H:i:s'),
        ], "agent_id = ? AND status = 'held'", [$agentId]);

        if ($count > 0) {
            Audit::log('whatsapp_messages_approved', $agentId, 'whatsapp_outbox', null,
                $count . ' approved to send' . ($actor !== null ? ' by ' . $actor : ''));
        }

        return $count;
    }

    /**
     * Bring back a message that failed or was stopped.
     *
     * A failed message had already been cleared to go, so it returns to the
     * queue. A stopped one was stopped by a person, so it returns to waiting
     * for approval — "try again" must never become a way to send something
     * nobody approved. Neither comes back for a number that has since been
     * put on the do-not-contact list.
     *
     * @return string|null null when done, otherwise why not
     */
    public static function retry(int $agentId, int $id): ?string
    {
        $row = self::find($agentId, $id);
        if ($row === null) {
            return 'That message is not on this exhibition\'s list.';
        }

        if (Suppression::blocksPhone($agentId, (string)$row['phone_e164'])) {
            return 'That number is on the do-not-contact list, so it cannot be sent again.';
        }

        if ((string)$row['body'] === WhatsAppFollowUp::DECLINED_BODY) {
            return 'No follow-up was written for this person, so there is nothing to send.';
        }

        $from = (string)$row['status'];
        if ($from !== 'failed' && $from !== 'cancelled') {
            return 'Only a message that failed or was stopped can be brought back.';
        }

        $done = db()->update('whatsapp_outbox', [
            'status'        => $from === 'failed' ? 'scheduled' : 'held',
            'attempts'      => 0,
            'scheduled_for' => gmdate('Y-m-d H:i:s'),
            'last_error'    => null,
        ], 'id = ? AND agent_id = ? AND status = ?', [$id, $agentId, $from]) === 1;

        return $done ? null : 'It changed while you were looking at it. Reload and try again.';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'held'      => 'Waiting for your approval',
            'scheduled' => 'Waiting to go',
            'sending'   => 'Sending now',
            'sent'      => 'Sent',
            'failed'    => 'Could not be sent',
            'cancelled' => 'Stopped before sending',
            default     => $status,
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'opening'  => 'First message',
            'reply'    => 'Reply',
            'followup' => 'Follow-up',
            default    => $kind,
        };
    }
}
