<?php
declare(strict_types=1);

/**
 * Following up, on WhatsApp, with people who have gone quiet.
 *
 * A conversation marked "handed to sales" (the team was emailed about a warm
 * lead) is still followed up, because the assistant is still the one
 * talking. One a person has taken over is not.
 *
 * Two situations, and only two:
 *
 *  - nudge: a likely exhibitor was sent a first message and never replied.
 *    Ordinary visitors are not nudged: they were thanked for registering,
 *    and a second unanswered message to a visitor is noise.
 *  - quiet: someone was talking to us about exhibiting or sponsoring, and
 *    stopped replying.
 *
 * Everything that decides whether a follow-up goes is here, in PHP: how long
 * to wait, how many in a row without an answer, the total per person, the
 * exhibition's last day, a person having taken over, the do-not-contact
 * list, the emergency stop. The AI only writes the words — and may decline
 * to, when the conversation had plainly ended.
 *
 * Sending uses the same queue as first messages, so the daily limit, the
 * sending hours, the approval setting and the final check before sending
 * all apply unchanged.
 */
final class WhatsAppFollowUp
{
    /** However the settings are changed, nobody gets more than this, ever. */
    public const TOTAL_PER_PERSON = 4;

    /** The most unanswered follow-ups in a row the setting may allow. */
    public const MAX_IN_ROW = 3;

    /** The text kept on the line recording that no follow-up was written. */
    public const DECLINED_BODY = '(No follow-up written.)';

    public static function inRowAllowed(array $agent): int
    {
        return max(0, min(self::MAX_IN_ROW, (int)($agent['whatsapp_followups'] ?? 1)));
    }

    public static function waitDays(array $agent): int
    {
        return max(1, min(14, (int)($agent['whatsapp_followup_days'] ?? 3)));
    }

    /** Whether the exhibition's last day has passed, where the exhibition is. */
    public static function eventOver(array $agent, ?int $now = null): bool
    {
        $last = trim((string)($agent['event_ends_on'] ?? ''));
        if ($last === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
            return false;
        }
        try {
            $tz = new DateTimeZone((string)($agent['notify_timezone'] ?: 'Asia/Karachi'));
        } catch (Throwable) {
            $tz = new DateTimeZone('Asia/Karachi');
        }
        $today = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->format('Y-m-d');
        return $today > $last;
    }

    /**
     * Write the follow-ups that are due for one exhibition.
     *
     * @return array{queued: int, declined: int}
     */
    public static function queueDue(array $agent, int $limit = 20): array
    {
        $none = ['queued' => 0, 'declined' => 0];

        if (self::inRowAllowed($agent) === 0 || self::eventOver($agent)
            || OutboundSwitch::isStopped() || MessagingProviderFactory::problem($agent) !== null) {
            return $none;
        }

        $agentId = (int)$agent['id'];
        $cutoff  = gmdate('Y-m-d H:i:s', time() - self::waitDays($agent) * 86400);
        $limit   = max(1, min(100, $limit));

        // Conversations about exhibiting or sponsoring that have gone quiet.
        $candidates = [];
        foreach (db()->all(
            "SELECT * FROM conversations
              WHERE agent_id = ? AND channel = 'whatsapp' AND intent IN ('exhibitor','sponsor')
                AND automation_paused_at IS NULL AND status NOT IN ('completed','blocked')
                AND last_activity_at <= ?
              ORDER BY last_activity_at ASC LIMIT " . ($limit * 3),
            [$agentId, $cutoff]
        ) as $conversation) {
            $phone = WhatsAppEngine::phoneForConversation($agentId, $conversation);
            if ($phone !== null) {
                $candidates[$phone] = $conversation;
            }
        }

        // First messages that were never answered at all.
        foreach (db()->all(
            "SELECT o.phone_e164 FROM whatsapp_outbox o
              WHERE o.agent_id = ? AND o.kind = 'opening' AND o.status = 'sent' AND o.sent_at <= ?
                AND NOT EXISTS (SELECT 1 FROM whatsapp_inbox i WHERE i.agent_id = o.agent_id AND i.phone_e164 = o.phone_e164)
                AND NOT EXISTS (SELECT 1 FROM whatsapp_outbox f WHERE f.agent_id = o.agent_id
                                   AND f.phone_e164 = o.phone_e164 AND f.kind = 'followup')
              ORDER BY o.sent_at ASC LIMIT " . ($limit * 3),
            [$agentId, $cutoff]
        ) as $row) {
            $candidates[(string)$row['phone_e164']] ??= null;
        }

        $out = $none;
        foreach ($candidates as $phone => $conversation) {
            if ($out['queued'] + $out['declined'] >= $limit) {
                break;
            }
            $result = self::consider($agent, (string)$phone, $conversation);
            if ($result === 'queued') {
                $out['queued']++;
            } elseif ($result === 'declined') {
                $out['declined']++;
            }
        }
        return $out;
    }

    /**
     * Decide about one number, and write the follow-up if one is due.
     *
     * @return string queued, declined (the AI judged it unwelcome) or skipped
     */
    public static function consider(array $agent, string $phone, ?array $conversation): string
    {
        $agentId = (int)$agent['id'];
        $cutoff  = gmdate('Y-m-d H:i:s', time() - self::waitDays($agent) * 86400);

        // Anything still waiting to go, or being sent, for this number.
        if (db()->value(
            "SELECT id FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ?
               AND status IN ('held','scheduled','sending') LIMIT 1",
            [$agentId, $phone]
        ) !== null) {
            return 'skipped';
        }

        $lastIn = db()->one(
            'SELECT id, received_at FROM whatsapp_inbox WHERE agent_id = ? AND phone_e164 = ? ORDER BY id DESC LIMIT 1',
            [$agentId, $phone]
        );
        $lastOut = db()->value(
            "SELECT MAX(sent_at) FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ? AND status = 'sent'",
            [$agentId, $phone]
        );

        // We spoke last, long enough ago. If they spoke last, they are owed
        // a reply, not a follow-up.
        if ($lastOut === null || (string)$lastOut > $cutoff) {
            return 'skipped';
        }
        // Equal times mean our reply went in the same second, after theirs.
        if ($lastIn !== null && (string)$lastIn['received_at'] > (string)$lastOut) {
            return 'skipped';
        }

        $inRow = (int)db()->value(
            "SELECT COUNT(*) FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ? AND kind = 'followup'
               AND status = 'sent' AND sent_at > ?",
            [$agentId, $phone, $lastIn !== null ? (string)$lastIn['received_at'] : '1970-01-01 00:00:00']
        );
        if ($inRow >= self::inRowAllowed($agent)) {
            return 'skipped';
        }
        $total = (int)db()->value(
            "SELECT COUNT(*) FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ? AND kind = 'followup' AND status = 'sent'",
            [$agentId, $phone]
        );
        if ($total >= self::TOTAL_PER_PERSON) {
            return 'skipped';
        }

        // One decision per silence and step. Recorded even when the answer
        // was "do not follow up", so the AI is not asked again every run.
        $dedupe = 'fu:' . $agentId . ':' . $phone . ':' . ($lastIn !== null ? (int)$lastIn['id'] : 0) . ':' . ($inRow + 1);
        if (db()->value('SELECT id FROM whatsapp_outbox WHERE dedupe_key = ?', [$dedupe]) !== null) {
            return 'skipped';
        }

        if (WhatsAppEngine::personHasNumber($agentId, $phone)) {
            return 'skipped';
        }

        $registration = db()->one(
            'SELECT * FROM registrations WHERE agent_id = ? AND phone_e164 = ? ORDER BY id DESC LIMIT 1',
            [$agentId, $phone]
        );
        // Follow-ups are only for people who registered and agreed to
        // WhatsApp, and are still in a state that may be messaged.
        if ($registration === null || !in_array((string)$registration['whatsapp_state'], ['queued', 'sent'], true)) {
            return 'skipped';
        }
        if (WhatsAppEngine::blockedReason($agentId, $phone, $registration['email'] ?? null,
                !empty($registration['company_id']) ? (int)$registration['company_id'] : null) !== null) {
            return 'skipped';
        }

        $profile = VisitorProfile::fromRegistration($registration, $agent);
        $kind    = $conversation !== null ? 'quiet' : 'nudge';

        // Only likely exhibitors are nudged.
        if ($kind === 'nudge' && (string)($profile['track'] ?? '') !== 'exhibitor') {
            return 'skipped';
        }

        $facts = !empty($registration['company_id'])
            ? CompanyEvidence::statableFacts($agentId, (int)$registration['company_id']) : [];
        $case  = ValueProposition::build($agent, $profile, $facts);
        if ($kind === 'quiet') {
            // They raised exhibiting themselves.
            $case['may_pitch'] = true;
        }

        $history = [];
        if ($conversation !== null) {
            foreach (ConversationStore::transcript((int)$conversation['id']) as $m) {
                if (in_array((string)$m['role'], ['visitor', 'agent'], true)) {
                    $history[] = ['role' => (string)$m['role'], 'content' => (string)$m['content']];
                }
            }
        } else {
            foreach (db()->all(
                "SELECT body FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ? AND status = 'sent' ORDER BY sent_at ASC",
                [$agentId, $phone]
            ) as $sent) {
                $history[] = ['role' => 'agent', 'content' => (string)$sent['body']];
            }
        }

        $written = MessageComposer::composeFollowUp($agent, $registration, $case, $history, $kind);

        if ($written['skip']) {
            // Kept as a cancelled line, so the team can see the decision.
            $id = WhatsAppOutbox::queue($agent, [
                'registration_id' => (int)$registration['id'],
                'conversation_id' => $conversation !== null ? (int)$conversation['id'] : null,
                'phone_e164'      => $phone,
                'body'            => self::DECLINED_BODY,
                'kind'            => 'followup',
                'dedupe_key'      => $dedupe,
                'hold'            => true,
            ]);
            if ($id !== null) {
                WhatsAppOutbox::cancel($agentId, $id, 'Not sent: ' . (string)$written['blocked']);
            }
            return 'declined';
        }

        $id = WhatsAppOutbox::queue($agent, [
            'registration_id' => (int)$registration['id'],
            'conversation_id' => $conversation !== null ? (int)$conversation['id'] : null,
            'phone_e164'      => $phone,
            'body'            => (string)$written['message'],
            'kind'            => 'followup',
            'scheduled_for'   => gmdate('Y-m-d H:i:s'),
            'used_fallback'   => $written['used_fallback'],
            'blocked_reason'  => $written['blocked'],
            'dedupe_key'      => $dedupe,
            'hold'            => (int)($agent['whatsapp_approve_first'] ?? 1) === 1,
        ]);

        return $id !== null ? 'queued' : 'skipped';
    }

    /**
     * The last check before a follow-up leaves, on top of the checks every
     * message gets. Null when it may go.
     */
    public static function refuse(array $agent, array $message): ?string
    {
        if (self::inRowAllowed($agent) === 0) {
            return 'Follow-ups have been switched off for this exhibition.';
        }
        if (self::eventOver($agent)) {
            return 'The exhibition is over.';
        }
        $answered = db()->value(
            'SELECT id FROM whatsapp_inbox WHERE agent_id = ? AND phone_e164 = ? AND received_at >= ? LIMIT 1',
            [(int)$agent['id'], (string)$message['phone_e164'], (string)$message['created_at']]
        );
        if ($answered !== null) {
            return 'They wrote back before it went, so it was not needed.';
        }
        return null;
    }
}
