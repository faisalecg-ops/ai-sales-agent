<?php
declare(strict_types=1);

/**
 * Sending and receiving WhatsApp messages.
 *
 * Three jobs, each bounded so the scheduled task never runs long:
 *
 *  - write a first message for registrations that have become eligible
 *  - send whatever is due, within the day's limit and the sending hours
 *  - take a reply and put it through the same conversation engine the
 *    website uses
 *
 * Whether someone may be messaged was settled at intake, and is checked
 * again immediately before sending, because the answer can change in
 * between: an opt-out arrives, a company is blocked, the exhibition is
 * switched off. The check at sending is the one that counts.
 *
 * The rule that governs every choice below: when in doubt, do not send.
 * A message that should have gone and did not is a missed sale. A message
 * that should not have gone is a complaint, and complaints are what get a
 * number blocked for every exhibition that uses it.
 */
final class WhatsAppEngine
{
    private const MAX_ATTEMPTS = 3;

    /** States in which a registration may still receive a message from us. */
    private const SENDABLE_STATES = ['eligible', 'queued', 'sent'];

    /**
     * Seconds to wait between one first message and the next, chosen at
     * random within this range.
     *
     * A new number sending twenty identical-looking messages within a
     * minute is the pattern WhatsApp looks for. Spaced out and slightly
     * irregular, the same twenty look like a person working through a list.
     * Replies are never delayed — someone is waiting for them.
     *
     * Public so the automated tests can set it to nothing.
     *
     * @var array{0:int,1:int}
     */
    public static array $pauseSeconds = [8, 15];

    // -----------------------------------------------------------------
    // Who may not be contacted
    // -----------------------------------------------------------------

    /**
     * Why this person may not be contacted, or null if nothing stops it.
     *
     * Every kind of block in one place, so intake, writing and sending all
     * apply the same test: the number, the email address, the email's
     * domain, and the company as a whole.
     */
    public static function blockedReason(int $agentId, ?string $phone, ?string $email, ?int $companyId, ?string $country = null): ?string
    {
        // Blocked countries (owner, 12 Nov 2026): the country on the record,
        // or the number's dialling code.
        if (BlockedCountries::blocks($country) || BlockedCountries::blocksPhone($phone)) {
            return 'They are in a blocked country.';
        }

        if ($phone !== null && $phone !== '' && Suppression::blocksPhone($agentId, $phone)) {
            return 'The number is on the do-not-contact list.';
        }

        if ($email !== null && trim($email) !== '' && Suppression::blocksEmail($agentId, $email)) {
            return 'Their email address or its company is on the do-not-contact list.';
        }

        if ($companyId !== null && $companyId > 0) {
            $company = db()->one('SELECT * FROM companies WHERE id = ? AND agent_id = ?', [$companyId, $agentId]);
            if ($company !== null && BlockedCountries::blocksCompany($agentId, $company)) {
                return 'Their company is in a blocked country.';
            }
            if ($company !== null && !CompanyStore::isContactable($agentId, $company)) {
                return 'Their company is marked do not contact.';
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // 1. Write first messages
    // -----------------------------------------------------------------

    /**
     * Find registrations whose waiting time has passed and write their first
     * message.
     *
     * Every registration looked at leaves the pool, whatever happens to it:
     * written, already written for that number, or blocked. Nothing is left
     * behind to be looked at again on the next run, so a message that is
     * later cancelled or fails cannot stand in front of everyone who
     * registered after it.
     *
     * @return array{queued: int, skipped: int, reason: ?string}
     */
    public static function queueOpenings(array $agent, int $limit = 50): array
    {
        $agentId = (int)$agent['id'];

        $problem = MessagingProviderFactory::problem($agent);
        if ($problem !== null) {
            return ['queued' => 0, 'skipped' => 0, 'reason' => $problem];
        }

        // Nothing new is written while everything is stopped: no AI time is
        // spent on messages that cannot go, and none pile up to be sent in a
        // rush the moment sending resumes.
        if (OutboundSwitch::isStopped()) {
            return ['queued' => 0, 'skipped' => 0, 'reason' => OutboundSwitch::REASON];
        }

        $delay  = max(0, (int)($agent['whatsapp_first_delay_minutes'] ?? 10));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($delay * 60));

        $rows = db()->all(
            "SELECT * FROM registrations
              WHERE agent_id = ? AND whatsapp_state = 'eligible'
                AND phone_e164 IS NOT NULL
                AND COALESCE(registered_at, imported_at) <= ?
              ORDER BY id ASC LIMIT " . max(1, min(200, $limit)),
            [$agentId, $cutoff]
        );

        $queued  = 0;
        $skipped = 0;

        foreach ($rows as $registration) {
            if (self::queueOpening($agent, $registration) !== null) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'reason' => null];
    }

    /**
     * Write and queue one first message. Returns the outbox id, or null.
     *
     * One first message per number per exhibition, ever. Someone who
     * registers twice — for two days, or once as a visitor and once for the
     * conference — is one person with one phone.
     */
    public static function queueOpening(array $agent, array $registration): ?int
    {
        $agentId        = (int)$agent['id'];
        $registrationId = (int)$registration['id'];
        $phone          = (string)($registration['phone_e164'] ?? '');

        if (!Phone::isReachable($phone)) {
            self::settleRegistration($agentId, $registrationId, 'no_number');
            return null;
        }

        $blocked = self::blockedReason(
            $agentId,
            $phone,
            $registration['email'] ?? null,
            isset($registration['company_id']) ? (int)$registration['company_id'] : null,
            $registration['country'] ?? null
        );
        if ($blocked !== null) {
            self::settleRegistration($agentId, $registrationId, 'suppressed');
            return null;
        }

        if (self::numberAlreadyWrittenTo($agentId, $phone)) {
            self::settleRegistration($agentId, $registrationId, 'queued');
            return null;
        }

        $profile = VisitorProfile::fromRegistration($registration, $agent);

        $facts = [];
        if (!empty($registration['company_id'])) {
            $facts = CompanyEvidence::statableFacts($agentId, (int)$registration['company_id']);
        }

        $case    = ValueProposition::build($agent, $profile, $facts);
        $written = MessageComposer::compose($agent, $registration, $profile, $case, 'whatsapp');

        $id = WhatsAppOutbox::queue($agent, [
            'registration_id' => $registrationId,
            'phone_e164'      => $phone,
            'body'            => (string)$written['message'],
            'kind'            => 'opening',
            'scheduled_for'   => gmdate('Y-m-d H:i:s'),
            'used_fallback'   => !empty($written['used_fallback']),
            'blocked_reason'  => $written['blocked'] ?? null,
            // Keyed on the number, not the registration, so the unique index
            // itself refuses a second first message to the same phone.
            'dedupe_key'      => 'open:' . $agentId . ':' . $phone,
            // A first message to someone who has not written to us waits for
            // a person to read it, unless the exhibition has chosen otherwise.
            'hold'            => (int)($agent['whatsapp_approve_first'] ?? 1) === 1,
        ]);

        // Settled either way. If the insert lost to the unique key, another
        // run wrote to this number first — the outcome is the same.
        self::settleRegistration($agentId, $registrationId, 'queued');

        return $id;
    }

    private static function numberAlreadyWrittenTo(int $agentId, string $phone): bool
    {
        return db()->value(
            "SELECT id FROM whatsapp_outbox
              WHERE agent_id = ? AND phone_e164 = ? AND kind IN ('opening','followup') LIMIT 1",
            [$agentId, $phone]
        ) !== null;
    }

    /** Move a registration out of the eligible pool. Never moves it back in. */
    private static function settleRegistration(int $agentId, int $registrationId, string $state): void
    {
        db()->update('registrations', ['whatsapp_state' => $state],
            "id = ? AND agent_id = ? AND whatsapp_state = 'eligible'",
            [$registrationId, $agentId]);
    }

    // -----------------------------------------------------------------
    // 2. Send what is due
    // -----------------------------------------------------------------

    /**
     * Send what is due.
     *
     * `$outreachPerRun` caps first messages in this one run, separately from
     * the daily limit, because each is followed by a pause. The scheduled
     * task sends a handful every few minutes; the button on the screen sends
     * one or two so the page does not time out while it waits.
     *
     * @return array{sent: int, failed: int, held: int, reason: ?string}
     */
    public static function sendDue(array $agent, int $limit = 25, int $outreachPerRun = 8): array
    {
        $agentId = (int)$agent['id'];

        $problem = MessagingProviderFactory::problem($agent);
        if ($problem !== null) {
            return ['sent' => 0, 'failed' => 0, 'held' => 0, 'reason' => $problem];
        }

        if (OutboundSwitch::isStopped()) {
            return ['sent' => 0, 'failed' => 0, 'held' => 0, 'reason' => OutboundSwitch::REASON];
        }

        // One sending run per exhibition at a time. Without this, the
        // five-minute task, the hourly one and the button on the screen could
        // each read the same "40 left today" and send 40 apiece. The lock is
        // released when this request ends, however it ends.
        if (!self::takeSendingLock($agentId)) {
            return ['sent' => 0, 'failed' => 0, 'held' => 0,
                    'reason' => 'Another run is sending for this exhibition right now.'];
        }

        try {
            return self::sendDueLocked($agent, $limit, $outreachPerRun);
        } finally {
            self::releaseSendingLock($agentId);
        }
    }

    private static function sendDueLocked(array $agent, int $limit, int $outreachPerRun): array
    {
        $agentId = (int)$agent['id'];

        // A run that was cut off mid-send leaves rows claimed and unfinished.
        // They are set aside for a person, never sent again automatically.
        WhatsAppOutbox::releaseStuck($agentId);

        // A reply that could not go for half a day — the service was down, or
        // everything was stopped — answers a question the person asked long
        // ago. Sending it now would be confusing, so it is set aside.
        WhatsAppOutbox::expireOldReplies($agentId, 12);

        // Answering someone who has just written to us is not outreach, so
        // the daily limit and the sending hours do not apply to a reply. They
        // exist to stop us interrupting people; a reply is the opposite of an
        // interruption, and holding one back until ten in the morning would
        // leave a live conversation unanswered overnight.
        $batch = WhatsAppOutbox::due($agentId, $limit, ['reply']);

        $reason = null;

        if (!self::withinSendingHours($agent)) {
            $reason = 'Outside this exhibition\'s sending hours, so only replies were sent.';
        } else {
            $remaining = self::remainingToday($agent);
            if ($remaining <= 0) {
                $reason = 'The daily limit for this exhibition has been reached, so only replies were sent.';
            } elseif (count($batch) < $limit) {
                $batch = array_merge($batch, WhatsAppOutbox::due(
                    $agentId,
                    max(1, min($limit - count($batch), $remaining, max(1, $outreachPerRun))),
                    ['opening', 'followup']
                ));
            }
        }

        // One message per number per run, across replies and first messages
        // together. A reply is kept in preference, being first in the list.
        $seen  = [];
        $final = [];
        foreach ($batch as $message) {
            $phone = (string)$message['phone_e164'];
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $final[] = $message;
        }

        $sent = 0;
        $failed = 0;
        $held = 0;
        $outreachDone = 0;

        foreach ($final as $message) {
            $isOutreach = (string)$message['kind'] !== 'reply';

            if ($isOutreach && $outreachDone > 0) {
                self::pause();
            }

            $outcome = self::sendOne($agent, $message);

            if ($isOutreach && $outcome !== 'taken' && $outcome !== 'held') {
                $outreachDone++;
            }
            if ($outcome === 'sent') {
                $sent++;
            } elseif ($outcome === 'failed') {
                $failed++;
            } elseif ($outcome === 'held') {
                $held++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'held' => $held, 'reason' => $reason];
    }

    private static function pause(): void
    {
        [$min, $max] = self::$pauseSeconds + [0, 0];
        $min = max(0, (int)$min);
        $max = max($min, (int)$max);
        if ($max > 0) {
            sleep(random_int($min, $max));
        }
    }

    private static function takeSendingLock(int $agentId): bool
    {
        try {
            return (int)db()->value('SELECT GET_LOCK(?, 0)', ['aisa_wa_send_' . $agentId]) === 1;
        } catch (Throwable) {
            // A database that cannot lock is not a reason to send nothing
            // forever; the per-message claim still prevents double sending.
            return true;
        }
    }

    private static function releaseSendingLock(int $agentId): void
    {
        try {
            db()->value('SELECT RELEASE_LOCK(?)', ['aisa_wa_send_' . $agentId]);
        } catch (Throwable) {
            // Released anyway when the connection closes.
        }
    }

    /**
     * Send one queued message.
     *
     * @return string sent, failed, held, or taken (claimed by another run)
     */
    private static function sendOne(array $agent, array $message): string
    {
        $agentId = (int)$agent['id'];
        $id      = (int)$message['id'];

        // Pressed while a run was already under way: everything not yet
        // sent stays exactly where it is, to go when sending resumes.
        if (OutboundSwitch::isStopped()) {
            return 'taken';
        }

        $stop = self::refuseNow($agent, $message);
        if ($stop !== null) {
            WhatsAppOutbox::cancel($agentId, $id, $stop);
            return 'held';
        }

        // Another run may have taken this one between the read and here.
        if (!WhatsAppOutbox::markSending($id)) {
            return 'taken';
        }

        $provider = MessagingProviderFactory::make($agent);

        try {
            $result = $provider->send((string)$message['phone_e164'], (string)$message['body']);
        } catch (Throwable $ex) {
            // Thrown before a request could complete, so nothing went out.
            app_log('whatsapp', 'send threw', ['agent' => $agentId, 'error' => $ex->getMessage()]);
            WhatsAppOutbox::markFailed($id, 'The messaging service could not be used just now.', self::MAX_ATTEMPTS);
            return 'failed';
        }

        if ($result['ok']) {
            // If recording the success fails, the row stays "sending" and is
            // later set aside as uncertain — never sent a second time.
            WhatsAppOutbox::markSent($id, $provider->name(), $result['reference']);
            self::recordSentAgainstRegistration($agentId, $message);
            if (in_array((string)$message['kind'], ['opening', 'followup'], true)) {
                self::recordOutreach($agentId, $message);
            }
            return 'sent';
        }

        WhatsAppOutbox::markFailed($id, (string)$result['error'], self::MAX_ATTEMPTS);
        return 'failed';
    }

    /**
     * Send one message straight away, by id.
     *
     * Used for a reply while the webhook request is still open, so someone
     * who writes to us gets an answer in seconds rather than whenever the
     * scheduled task next runs. If it does not go, it stays in the queue and
     * the scheduled task tries again — nothing is lost by trying here.
     */
    public static function sendNow(array $agent, int $outboxId): bool
    {
        if (MessagingProviderFactory::problem($agent) !== null || OutboundSwitch::isStopped()) {
            return false;
        }

        $message = WhatsAppOutbox::find((int)$agent['id'], $outboxId);
        if ($message === null || (string)$message['status'] !== 'scheduled') {
            return false;
        }

        return self::sendOne($agent, $message) === 'sent';
    }

    /**
     * A last check before the message actually leaves.
     *
     * Everything here was true when the message was written and may not be
     * now. This is the check that counts, because it is the one that happens
     * at the moment of sending. It asks what would permit the message, not
     * what would forbid it, so a state nobody anticipated stops a message
     * rather than letting it through.
     */
    private static function refuseNow(array $agent, array $message): ?string
    {
        $agentId = (int)$agent['id'];
        $phone   = (string)$message['phone_e164'];

        if (!Phone::isReachable($phone)) {
            return 'The number is not usable.';
        }

        // A person has this conversation. Anything the assistant queued is
        // held back. Two things still go: what the team types, and the one
        // message telling the visitor a person is taking over.
        $fromTeam     = trim((string)($message['sent_by'] ?? '')) !== '';
        $isHandoverAck = str_starts_with((string)($message['dedupe_key'] ?? ''), 'handover:');
        if (!$fromTeam && !$isHandoverAck && self::personHasNumber($agentId, $phone)) {
            return 'A person has taken over this conversation.';
        }

        if ((string)$message['kind'] === 'followup') {
            $why = WhatsAppFollowUp::refuse($agent, $message);
            if ($why !== null) {
                return $why;
            }
        }

        $registration = null;
        if (!empty($message['registration_id'])) {
            $registration = db()->one(
                'SELECT whatsapp_state, email, company_id, country FROM registrations WHERE id = ? AND agent_id = ?',
                [(int)$message['registration_id'], $agentId]
            );
            if ($registration === null) {
                return 'The registration it was written for no longer exists.';
            }
            if (!in_array((string)$registration['whatsapp_state'], self::SENDABLE_STATES, true)) {
                return 'The registration is no longer one that may be messaged ('
                    . RegistrationIntake::stateLabel((string)$registration['whatsapp_state']) . ').';
            }
        }

        $blocked = self::blockedReason(
            $agentId,
            $phone,
            $registration['email'] ?? null,
            isset($registration['company_id']) ? (int)$registration['company_id'] : null,
            $registration['country'] ?? null
        );
        if ($blocked !== null) {
            return $blocked;
        }

        return null;
    }

    /** Whether a person has taken over the conversation with this number. */
    public static function personHasNumber(int $agentId, string $phone): bool
    {
        return db()->value(
            "SELECT c.id FROM conversations c
               JOIN whatsapp_inbox i ON i.conversation_id = c.id AND i.agent_id = c.agent_id
              WHERE c.agent_id = ? AND c.channel = 'whatsapp' AND c.automation_paused_at IS NOT NULL
                AND i.phone_e164 = ?
              LIMIT 1",
            [$agentId, $phone]
        ) !== null
        || db()->value(
            "SELECT id FROM conversations
              WHERE agent_id = ? AND session_id = ? AND automation_paused_at IS NOT NULL LIMIT 1",
            [$agentId, self::sessionIdFor($agentId, $phone)]
        ) !== null;
    }

    private static function recordSentAgainstRegistration(int $agentId, array $message): void
    {
        if (empty($message['registration_id'])) {
            return;
        }
        db()->update('registrations', ['whatsapp_state' => 'sent'],
            "id = ? AND agent_id = ? AND whatsapp_state IN ('eligible','queued')",
            [(int)$message['registration_id'], $agentId]);
    }

    /**
     * How many more first messages may go out today for this exhibition.
     *
     * Replies are not counted. The limit is there to pace outreach, and a
     * busy day of conversations should not stop new visitors being welcomed.
     */
    public static function remainingToday(array $agent): int
    {
        $cap = max(0, (int)($agent['whatsapp_daily_cap'] ?? 40));
        return max(0, $cap - self::outreachSentToday($agent));
    }

    /** First messages and follow-ups sent since midnight where the exhibition is. */
    public static function outreachSentToday(array $agent): int
    {
        return (int)db()->value(
            "SELECT COUNT(*) FROM whatsapp_outbox
              WHERE agent_id = ? AND kind IN ('opening','followup')
                AND (status = 'sent' OR status = 'sending')
                AND COALESCE(sent_at, scheduled_for) >= ?",
            [(int)$agent['id'], self::dayStartUtc($agent)]
        );
    }

    /**
     * Midnight today in the exhibition's timezone, written in UTC.
     *
     * "Today" is the exhibition's day, not the server's. Counted from UTC
     * midnight, an exhibition in New York would get a fresh allowance at
     * eight in the evening and could send twice its limit in one local day.
     */
    public static function dayStartUtc(array $agent, ?int $now = null): string
    {
        $tz = self::zoneOf($agent);
        $local = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->setTime(0, 0, 0);
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function zoneOf(array $agent): DateTimeZone
    {
        try {
            return new DateTimeZone((string)($agent['notify_timezone'] ?: 'Asia/Karachi'));
        } catch (Throwable) {
            return new DateTimeZone('Asia/Karachi');
        }
    }

    /**
     * Whether it is a reasonable hour where the recipient is.
     *
     * Judged in the exhibition's own timezone, which is the closest thing we
     * have to the visitor's. A message at two in the morning is a complaint
     * waiting to happen, and complaints are what get a number blocked.
     *
     * The same time at both ends is a window of no length, and sends nothing.
     * The settings screen refuses to save one, so this only arises from a
     * database edited by hand — and then silence is the right failure.
     */
    public static function withinSendingHours(array $agent, ?int $now = null): bool
    {
        $at = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(self::zoneOf($agent));

        $from = substr((string)($agent['whatsapp_send_from'] ?? '10:00:00'), 0, 5);
        $to   = substr((string)($agent['whatsapp_send_to'] ?? '20:00:00'), 0, 5);
        $hhmm = $at->format('H:i');

        if ($from === $to) {
            return false;
        }

        // A window that wraps past midnight is treated as it reads.
        return $from < $to
            ? ($hhmm >= $from && $hhmm < $to)
            : ($hhmm >= $from || $hhmm < $to);
    }

    /** Minutes to wait before each new try at a message the AI was too busy for. */
    private const BUSY_RETRY_MINUTES = [2, 5, 10, 15];

    /**
     * Answer the messages the AI was too busy for, now that some time has
     * passed. A few per run; each is tried again later if still busy.
     */
    public static function retryBusy(array $agent, int $limit = 10): int
    {
        if (!Migrations::columnExists('whatsapp_inbox', 'ai_attempts') || OutboundSwitch::isStopped()
            || MessagingProviderFactory::problem($agent) !== null) {
            return 0;
        }
        $agentId = (int)$agent['id'];
        $done = 0;
        foreach (db()->all(
            'SELECT * FROM whatsapp_inbox WHERE agent_id = ? AND handled = 0 AND ai_attempts > 0 AND retry_after <= ?
              ORDER BY id ASC LIMIT ' . max(1, $limit),
            [$agentId, gmdate('Y-m-d H:i:s')]
        ) as $row) {
            // Claimed first, so two runs never answer it twice.
            if (db()->update('whatsapp_inbox', ['retry_after' => gmdate('Y-m-d H:i:s', time() + 600)],
                    'id = ? AND retry_after = ?', [(int)$row['id'], (string)$row['retry_after']]) !== 1) {
                continue;
            }
            $phone = (string)$row['phone_e164'];

            // They wrote again since, and that later message was answered with
            // this one in view: nothing more to do.
            $later = db()->value('SELECT id FROM whatsapp_inbox WHERE agent_id = ? AND phone_e164 = ? AND id > ? AND handled = 1 LIMIT 1',
                [$agentId, $phone, (int)$row['id']]);
            if ($later !== null) {
                db()->update('whatsapp_inbox', ['handled' => 1, 'note' => 'Answered together with a later message.'], 'id = ?', [(int)$row['id']]);
                continue;
            }
            // Stopped, blocked, or taken over by a person in the meantime.
            if (Suppression::blocksPhone($agentId, $phone) || self::personHasNumber($agentId, $phone)) {
                db()->update('whatsapp_inbox', ['ai_attempts' => 0, 'note' => 'No longer answered automatically.'], 'id = ?', [(int)$row['id']]);
                continue;
            }

            self::answerWithAssistant($agent, self::conversationFor($agent, $phone), $phone, (string)$row['body'],
                (string)$row['provider_ref'], (int)$row['id'], (int)$row['ai_attempts']);
            $done++;
        }
        return $done;
    }

    /**
     * One pass over every active exhibition: write what is due, send what is
     * ready. This is what the scheduled task calls.
     *
     * Bounded on purpose. A backlog is worked through over several runs
     * rather than one long run that risks being cut off half way. One
     * exhibition failing never stops the others.
     *
     * @return array<int,string> lines for the scheduled task's report
     */
    public static function runAll(int $queueLimit = 50, int $sendLimit = 25): array
    {
        $report = [];

        foreach (db()->all("SELECT * FROM agents WHERE status = 'active' AND whatsapp_enabled = 1") as $agent) {
            $name = (string)$agent['name'];

            try {
                $queued = self::queueOpenings($agent, $queueLimit);
                if ($queued['reason'] !== null) {
                    $report[] = $name . ': WhatsApp — ' . $queued['reason'];
                    continue;   // nothing can be sent either
                }
                if ($queued['queued'] > 0) {
                    $report[] = $name . ': ' . $queued['queued'] . ' WhatsApp message(s) written'
                        . ((int)($agent['whatsapp_approve_first'] ?? 1) === 1 ? ', waiting for approval' : '');
                }

                $retried = self::retryBusy($agent);
                if ($retried > 0) {
                    $report[] = $name . ': ' . $retried . ' WhatsApp message(s) answered after the AI was busy';
                }

                $followUps = WhatsAppFollowUp::queueDue($agent);
                if ($followUps['queued'] > 0) {
                    $report[] = $name . ': ' . $followUps['queued'] . ' WhatsApp follow-up(s) written'
                        . ((int)($agent['whatsapp_approve_first'] ?? 1) === 1 ? ', waiting for approval' : '');
                }

                $sent = self::sendDue($agent, $sendLimit);
                $parts = [];
                if ($sent['sent'] > 0) {
                    $parts[] = $sent['sent'] . ' sent';
                }
                if ($sent['failed'] > 0) {
                    $parts[] = $sent['failed'] . ' failed';
                }
                if ($sent['held'] > 0) {
                    $parts[] = $sent['held'] . ' stopped';
                }
                if ($parts) {
                    $report[] = $name . ': WhatsApp — ' . implode(', ', $parts);
                }
            } catch (Throwable $ex) {
                $report[] = $name . ': WhatsApp run failed';
                app_log('whatsapp', 'scheduled run failed', [
                    'agent' => (int)$agent['id'],
                    'error' => $ex->getMessage(),
                    'file'  => basename($ex->getFile()) . ':' . $ex->getLine(),
                ]);
                if (db()->inTransaction()) {
                    db()->rollback();
                }
            }
        }

        return $report;
    }

    // -----------------------------------------------------------------
    // 3. A reply arrives
    // -----------------------------------------------------------------

    /**
     * Handle one inbound message.
     *
     * The reply goes through the same conversation engine as the website, so
     * the rules about prices, geography, knowledge and handoff are the ones
     * already in use rather than a second set written for this channel.
     *
     * Every message is recorded and every request to stop is obeyed, even
     * when no answer can be given — WhatsApp switched off for the
     * exhibition, too many messages arriving at once, or a sender on the
     * do-not-contact list. `$mayReply` false means record and obey, but do
     * not answer; `$whyNot` is what the operator is told about it.
     *
     * @return array{handled: bool, note: string, reply: ?string}
     */
    public static function receive(
        array $agent,
        string $fromPhone,
        string $body,
        string $providerRef,
        bool $mayReply = true,
        string $whyNot = ''
    ): array {
        $agentId = (int)$agent['id'];
        $phone   = Phone::toE164($fromPhone, null, false) ?? Phone::fromInternational($fromPhone);

        if ($phone === null) {
            return ['handled' => false, 'note' => 'The sender\'s number could not be read.', 'reply' => null];
        }

        // Some bridges pass back a copy of what we sent. Answering our own
        // message would start a conversation with ourselves.
        $own = Phone::fromInternational((string)($agent['whatsapp_number'] ?? ''));
        if ($own !== null && $own === $phone) {
            return ['handled' => true, 'note' => 'A copy of our own message; ignored.', 'reply' => null];
        }

        // The provider may deliver the same message more than once.
        $already = db()->value(
            'SELECT id FROM whatsapp_inbox WHERE agent_id = ? AND provider_ref = ?',
            [$agentId, $providerRef]
        );
        if ($already !== null) {
            return ['handled' => true, 'note' => 'Already received.', 'reply' => null];
        }

        try {
            $inboxId = db()->insert('whatsapp_inbox', [
                'agent_id'     => $agentId,
                'phone_e164'   => $phone,
                'body'         => mb_substr($body, 0, 4000),
                'provider_ref' => mb_substr($providerRef, 0, 160),
            ]);
        } catch (Throwable $ex) {
            // Two deliveries of the same message arriving together: the
            // unique key lets one through, and this is the other.
            if ($ex->getCode() === 1062 || str_contains($ex->getMessage(), 'Duplicate entry')) {
                return ['handled' => true, 'note' => 'Already received.', 'reply' => null];
            }
            throw $ex;
        }

        // A clear stop is obeyed before anything else happens with it, and
        // whatever else is true — switched off, busy, or already blocked.
        if (self::isOptOut($body)) {
            self::recordOptOut($agentId, $phone, $inboxId);
            return ['handled' => true, 'note' => 'They asked to stop, and nothing further will be sent.',
                    'reply' => null];
        }

        // The emergency stop silences every automatic answer, platform-wide.
        if ($mayReply && OutboundSwitch::isStopped()) {
            $mayReply = false;
            $whyNot   = 'Received while all outgoing messages were stopped, so not answered.';
        }

        if (!$mayReply) {
            $note = $whyNot !== '' ? $whyNot : 'Received, but not answered automatically.';
            db()->update('whatsapp_inbox', ['handled' => 0, 'note' => mb_substr($note, 0, 255)],
                'id = ?', [$inboxId]);
            return ['handled' => false, 'note' => $note, 'reply' => null];
        }

        // Someone we have agreed never to contact has written to us. That is
        // their choice, but an automatic answer is still us contacting them,
        // so a person decides — and no AI time is spent on it.
        $blocked = self::blockedReason($agentId, $phone, null, null);
        if ($blocked !== null) {
            $note = BlockedCountries::blocksPhone($phone)
                ? 'The number is from a blocked country, so it is not answered.'
                : 'On the do-not-contact list, so not answered automatically. A person should decide.';
            db()->update('whatsapp_inbox', ['handled' => 0, 'note' => $note], 'id = ?', [$inboxId]);
            return ['handled' => false, 'note' => $note, 'reply' => null];
        }

        $conversation = self::conversationFor($agent, $phone);
        $conversationId = (int)$conversation['conversation']['id'];

        // A person has this conversation. The assistant stays out of it: the
        // message is kept in the conversation for the team to read, and
        // nothing is sent back automatically.
        if (!empty($conversation['conversation']['automation_paused_at'])) {
            self::recordInTranscript($conversation['conversation'], 'visitor', $body);
            $note = 'A person is handling this conversation, so the assistant did not answer.';
            db()->update('whatsapp_inbox', [
                'handled' => 0, 'note' => $note, 'conversation_id' => $conversationId,
            ], 'id = ?', [$inboxId]);
            return ['handled' => false, 'note' => $note, 'reply' => null];
        }

        // Asked for a person, complaining, or raising something only the team
        // can decide. Recognised here, before any AI is involved, so the
        // decision does not depend on how a model happens to read it.
        $needs = self::needsPerson($body);
        if ($needs !== null) {
            self::recordInTranscript($conversation['conversation'], 'visitor', $body);
            db()->update('whatsapp_inbox', ['conversation_id' => $conversationId], 'id = ?', [$inboxId]);
            return self::handOver($agent, $conversation['conversation'], $phone, $needs, $inboxId);
        }

        return self::answerWithAssistant($agent, $conversation, $phone, $body, $providerRef, $inboxId, 0);
    }

    /**
     * Put a message through the assistant and send the answer. Used for a
     * message as it arrives, and again for one the AI was too busy to answer.
     */
    private static function answerWithAssistant(array $agent, array $conversation, string $phone, string $body,
                                                string $providerRef, int $inboxId, int $attempt): array
    {
        $agentId = (int)$agent['id'];
        $conversationId = (int)$conversation['conversation']['id'];

        $response = ChatOrchestrator::handle($agent, [
            'request_id'     => 'wa_' . mb_substr(hash('sha256', $agentId . ':' . $providerRef . ($attempt > 0 ? ':' . $attempt : '')), 0, 32),
            'session_id'     => (string)$conversation['session_id'],
            'session_secret' => (string)$conversation['session_secret'],
            'message'        => mb_substr($body, 0, max(80, (int)($agent['max_input_chars'] ?? 1200))),
            'channel'        => 'whatsapp',
            'known_contact'  => self::knownContactFor($agentId, $phone),
            'retry'          => $attempt > 0,
        ]);

        db()->update('whatsapp_inbox', ['conversation_id' => $conversationId], 'id = ?', [$inboxId]);

        // The AI was busy even after waiting. Nothing is wrong with it and
        // nobody needs to step in: the message waits, and the scheduled task
        // answers it a few minutes later. Only if the AI stays busy for about
        // half an hour does a person take over.
        if ((string)($response['fallback'] ?? '') === 'llm_busy' && Migrations::columnExists('whatsapp_inbox', 'ai_attempts')) {
            $tries = $attempt + 1;
            if ($tries > count(self::BUSY_RETRY_MINUTES)) {
                $fresh = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId]) ?? $conversation['conversation'];
                return self::handOver($agent, $fresh, $phone, 'The AI stayed busy for too long', $inboxId);
            }
            db()->update('whatsapp_inbox', [
                'handled'     => 0,
                'ai_attempts' => $tries,
                'retry_after' => gmdate('Y-m-d H:i:s', time() + self::BUSY_RETRY_MINUTES[$tries - 1] * 60),
                'note'        => 'The AI was busy, so this will be answered in a few minutes (try ' . $tries . ').',
            ], 'id = ?', [$inboxId]);
            return ['handled' => false, 'note' => 'Waiting for the AI.', 'reply' => null];
        }

        // The assistant could not answer reliably — no AI available, a
        // technical fault, or a conversation that has run past its length.
        // On the website that shows a contact form. Here we already have
        // their number, so a person simply takes over.
        $fallback = (string)($response['fallback'] ?? '');
        if (in_array($fallback, ['llm_unavailable', 'agent_unavailable', 'technical_problem', 'conversation_limit'], true)) {
            $fresh = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId])
                ?? $conversation['conversation'];
            return self::handOver($agent, $fresh, $phone, match ($fallback) {
                'conversation_limit' => 'The conversation reached its automatic length',
                default              => 'The assistant could not answer',
            }, $inboxId);
        }

        // Too many messages at once from this person. Nothing is sent back:
        // on a phone, being told to slow down is worse than a short silence.
        if ($fallback === 'rate_limited') {
            $note = 'Sent too many messages at once, so this one was not answered.';
            db()->update('whatsapp_inbox', ['handled' => 0, 'note' => $note], 'id = ?', [$inboxId]);
            return ['handled' => false, 'note' => $note, 'reply' => null];
        }

        // The assistant itself judged that a person is needed, for a reason
        // the words above did not catch.
        $after = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId]);
        if ($after !== null
            && (string)($after['handoff_reason'] ?? '') !== (string)($conversation['conversation']['handoff_reason'] ?? '')
            && self::reasonMeansPerson((string)$after['handoff_reason'])) {
            return self::handOver($agent, $after, $phone, 'The assistant judged a person was needed: '
                . mb_substr((string)$after['handoff_reason'], 0, 120), $inboxId);
        }

        $reply = self::flatten($response);

        db()->update('whatsapp_inbox', [
            'handled' => 1,
            'note'    => $reply === '' ? 'Nothing to reply with' : 'Answered',
        ], 'id = ?', [$inboxId]);

        if ($reply === '') {
            return ['handled' => true, 'note' => 'Nothing to reply with.', 'reply' => null];
        }

        $queued = WhatsAppOutbox::queue($agent, [
            'conversation_id' => $conversationId,
            'phone_e164'      => $phone,
            'body'            => $reply,
            'kind'            => 'reply',
            'scheduled_for'   => gmdate('Y-m-d H:i:s'),
            'dedupe_key'      => 'reply:' . $agentId . ':' . mb_substr(hash('sha256', $providerRef), 0, 40),
        ]);

        // Queued first, then sent, so a reply is never lost if the send
        // fails — the scheduled task will find it still waiting. Sent here
        // rather than left for that task because the person is at their
        // phone now.
        if ($queued !== null) {
            self::sendNow($agent, $queued);
        }

        return ['handled' => true, 'note' => 'Answered.', 'reply' => $reply];
    }

    // -----------------------------------------------------------------
    // 4. A person takes over
    // -----------------------------------------------------------------

    /**
     * Whether a message needs a person rather than the assistant, and why.
     *
     * Three kinds, all read as words rather than letters:
     *  - they asked for a person, or to be called
     *  - they are complaining or being abusive
     *  - they raised something only the team can decide: a contract, an
     *    invoice, payment, a refund, a discount
     *
     * A request to stop is not here. It is handled before this and ends
     * the conversation outright.
     */
    public static function needsPerson(string $body): ?string
    {
        return PersonRequest::detect($body);
    }

    /** Whether a reason the assistant gave for a handoff means "a person". */
    private static function reasonMeansPerson(string $reason): bool
    {
        return preg_match('/\b(human|person|someone|representative|call|phone|complain|complaint|upset|'
            . 'angry|legal|contract|invoice|payment|refund|discount|manager|team member)\b/i', $reason) === 1;
    }

    /**
     * Step aside for a person.
     *
     * One short message to the visitor so they are not left wondering, an
     * email to the team with the whole conversation, and silence from the
     * assistant for this number until someone hands it back.
     *
     * @return array{handled: bool, note: string, reply: ?string}
     */
    public static function handOver(array $agent, array $conversation, string $phone, string $reason, ?int $inboxId = null): array
    {
        $agentId        = (int)$agent['id'];
        $conversationId = (int)$conversation['id'];

        // Taken once. A second trigger on a conversation already with a
        // person changes nothing and sends nothing.
        $taken = db()->update('conversations', [
            'automation_paused_at'     => gmdate('Y-m-d H:i:s'),
            'automation_paused_reason' => mb_substr($reason, 0, 255),
            'state'                    => ConversationState::HANDOFF,
        ], 'id = ? AND agent_id = ? AND automation_paused_at IS NULL', [$conversationId, $agentId]) === 1;

        if (!$taken) {
            if ($inboxId !== null) {
                db()->update('whatsapp_inbox', [
                    'handled' => 0,
                    'note'    => 'A person is handling this conversation, so the assistant did not answer.',
                ], 'id = ?', [$inboxId]);
            }
            return ['handled' => false, 'note' => 'Already with a person.', 'reply' => null];
        }

        // Anything automatic still waiting for this number is stopped. A
        // first message or follow-up arriving after "let me speak to someone"
        // would read as the assistant ignoring them.
        db()->update('whatsapp_outbox', [
            'status'     => 'cancelled',
            'last_error' => 'A person took over the conversation.',
        ], "agent_id = ? AND phone_e164 = ? AND status IN ('held','scheduled') AND sent_by IS NULL",
            [$agentId, $phone]);

        // The lead the team will work from, with what we already know.
        $lead = LeadManager::ensureLead($agent, $conversation);
        $known = [];
        foreach (self::knownContactFor($agentId, $phone) as $field => $value) {
            if (trim((string)($lead[$field] ?? '')) === '' && trim((string)$value) !== '') {
                $known[$field] = (string)$value;
            }
        }
        if ($known) {
            LeadManager::applyFields($agent, $lead, $known, 'registration');
        }
        $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
        LeadManager::markHandoff($agent, $lead, 'WhatsApp: ' . $reason);

        Audit::log('whatsapp_handover', $agentId, 'conversation', $conversationId, $reason);

        // Identifies this handover and no other. Repeats are already ruled
        // out by the pause above being taken only once, so this only has to
        // keep a later handover from looking like a copy of an earlier one.
        $occasion = 'wa' . $conversationId . '-' . bin2hex(random_bytes(6));

        // The right rep for this visitor, so they can reach a person directly
        // rather than waiting to be contacted.
        $ack  = Fallbacks::get($agentId, 'whatsapp_handover');
        $reps = SalesReps::textFor($agentId, $conversation['pricing_country'] ?? null, 'whatsapp');
        if ($reps !== '') {
            $ack .= "\n\n" . $reps;
        }
        self::recordInTranscript($conversation, 'agent', $ack);
        self::recordInTranscript($conversation, 'system', 'Handed to the team: ' . $reason . '.');

        // Keyed to this handover, so a person asked for again after the
        // conversation was handed back reaches the team again.
        $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
        LeadManager::notifyNewLead($agent, $lead, 'handoff', $occasion);

        if ($inboxId !== null) {
            db()->update('whatsapp_inbox', [
                'handled' => 1,
                'note'    => 'Handed to a person: ' . mb_substr($reason, 0, 200),
            ], 'id = ?', [$inboxId]);
        }

        $queued = WhatsAppOutbox::queue($agent, [
            'conversation_id' => $conversationId,
            'phone_e164'      => $phone,
            'body'            => $ack,
            'kind'            => 'reply',
            'scheduled_for'   => gmdate('Y-m-d H:i:s'),
            'dedupe_key'      => 'handover:' . $agentId . ':' . $occasion,
        ]);
        if ($queued !== null) {
            self::sendNow($agent, $queued);
        }

        return ['handled' => true, 'note' => 'Handed to a person: ' . $reason . '.', 'reply' => $ack];
    }

    /** Give a conversation back to the assistant. */
    public static function handBack(int $agentId, int $conversationId, string $actor): bool
    {
        $done = db()->update('conversations', [
            'automation_paused_at'     => null,
            'automation_paused_reason' => null,
            'state'                    => ConversationState::BUSINESS_QUALIFICATION,
        ], "id = ? AND agent_id = ? AND channel = 'whatsapp' AND automation_paused_at IS NOT NULL",
            [$conversationId, $agentId]) === 1;

        if ($done) {
            $conversation = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId]);
            if ($conversation !== null) {
                self::recordInTranscript($conversation, 'system', 'Handed back to the assistant by ' . $actor . '.');
            }
            Audit::log('whatsapp_handback', $agentId, 'conversation', $conversationId, 'Handed back by ' . $actor);
        }

        return $done;
    }

    /**
     * A reply typed by a member of the team.
     *
     * Allowed while a person has the conversation — that is the point of
     * it. Still refused for a number that has opted out, and while all
     * outgoing messages are stopped.
     *
     * @return string|null null when sent or queued, otherwise why not
     */
    public static function staffReply(array $agent, int $conversationId, string $body, string $actor): ?string
    {
        $agentId = (int)$agent['id'];
        $body    = trim($body);

        if ($body === '') {
            return 'Type a message first.';
        }
        if (OutboundSwitch::isStopped()) {
            return OutboundSwitch::REASON;
        }

        $conversation = db()->one(
            "SELECT * FROM conversations WHERE id = ? AND agent_id = ? AND channel = 'whatsapp'",
            [$conversationId, $agentId]
        );
        if ($conversation === null) {
            return 'That conversation is not on this exhibition\'s list.';
        }

        $phone = self::phoneForConversation($agentId, $conversation);
        if ($phone === null) {
            return 'The number for this conversation could not be found.';
        }
        if (Suppression::blocksPhone($agentId, $phone)) {
            return 'This number is on the do-not-contact list.';
        }

        $id = WhatsAppOutbox::queue($agent, [
            'conversation_id' => $conversationId,
            'phone_e164'      => $phone,
            'body'            => $body,
            'kind'            => 'reply',
            'sent_by'         => $actor,
            'scheduled_for'   => gmdate('Y-m-d H:i:s'),
            'dedupe_key'      => 'staff:' . $agentId . ':' . $conversationId . ':'
                                 . mb_substr(hash('sha256', $body . microtime(true)), 0, 24),
        ]);
        if ($id === null) {
            return 'The message could not be queued.';
        }

        self::recordInTranscript($conversation, 'agent', $body, ['response_status' => 'staff:' . mb_substr($actor, 0, 40)]);
        Audit::log('whatsapp_staff_reply', $agentId, 'conversation', $conversationId, 'Reply sent by ' . $actor);

        self::sendNow($agent, $id);
        return null;
    }

    /**
     * Conversations a person has, newest first, with what the team needs to
     * pick them up: who, why, and the last thing they said.
     */
    public static function withPerson(int $agentId): array
    {
        $rows = db()->all(
            "SELECT c.id, c.session_id, c.lead_id, c.automation_paused_at, c.automation_paused_reason,
                    c.last_activity_at
               FROM conversations c
              WHERE c.agent_id = ? AND c.channel = 'whatsapp' AND c.automation_paused_at IS NOT NULL
              ORDER BY c.automation_paused_at DESC LIMIT 100",
            [$agentId]
        );

        foreach ($rows as &$row) {
            $row['phone'] = self::phoneForConversation($agentId, $row);
            $row['last_message'] = (string)db()->value(
                "SELECT content FROM messages WHERE conversation_id = ? AND role = 'visitor'
                  ORDER BY sequence_number DESC LIMIT 1",
                [(int)$row['id']]
            );
            $row['registration'] = $row['phone'] !== null ? db()->one(
                'SELECT name, company_name, designation, country FROM registrations
                  WHERE agent_id = ? AND phone_e164 = ? ORDER BY id DESC LIMIT 1',
                [$agentId, $row['phone']]
            ) : null;
            $row['lead'] = !empty($row['lead_id'])
                ? db()->one('SELECT name, company, reference FROM leads WHERE id = ?', [(int)$row['lead_id']])
                : null;
        }
        unset($row);

        return $rows;
    }

    /** The number a WhatsApp conversation belongs to, from what it has received. */
    public static function phoneForConversation(int $agentId, array $conversation): ?string
    {
        $phone = db()->value(
            'SELECT phone_e164 FROM whatsapp_inbox WHERE agent_id = ? AND conversation_id = ?
              ORDER BY id DESC LIMIT 1',
            [$agentId, (int)$conversation['id']]
        );
        if (is_string($phone) && $phone !== '') {
            return $phone;
        }
        $phone = db()->value(
            'SELECT phone_e164 FROM whatsapp_outbox WHERE agent_id = ? AND conversation_id = ?
              ORDER BY id DESC LIMIT 1',
            [$agentId, (int)$conversation['id']]
        );
        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * What the registration already told us about this person, in the
     * lead's own field names. Nothing is guessed: a field is present only
     * when the visitor gave it.
     *
     * @return array<string,string>
     */
    public static function knownContactFor(int $agentId, string $phone): array
    {
        $registration = db()->one(
            'SELECT name, email, company_name, country, city, website FROM registrations
              WHERE agent_id = ? AND phone_e164 = ? ORDER BY id DESC LIMIT 1',
            [$agentId, $phone]
        );

        $known = ['phone' => $phone];
        if ($registration !== null) {
            foreach ([
                'name' => 'name', 'email' => 'email', 'company' => 'company_name',
                'country' => 'country', 'city' => 'city', 'website' => 'website',
            ] as $leadField => $column) {
                $value = trim((string)($registration[$column] ?? ''));
                if ($value !== '') {
                    $known[$leadField] = $value;
                }
            }
        }

        return $known;
    }

    /**
     * A first message or follow-up, added to the conversation with that
     * number if there is one — so the assistant knows what it last said, and
     * the team sees the whole exchange. When there is no conversation yet,
     * it is added when one starts (see conversationFor).
     */
    private static function recordOutreach(int $agentId, array $message): void
    {
        try {
            $conversation = db()->one('SELECT * FROM conversations WHERE agent_id = ? AND session_id = ?',
                [$agentId, self::sessionIdFor($agentId, (string)$message['phone_e164'])]);
            if ($conversation !== null) {
                self::recordInTranscript($conversation, 'agent', (string)$message['body']);
            }
        } catch (Throwable $ex) {
            app_log('whatsapp', 'could not record outreach', ['error' => $ex->getMessage()]);
        }
    }

    /** Add a line to the conversation the team reads, never failing the caller. */
    private static function recordInTranscript(array $conversation, string $role, string $text, array $extra = []): void
    {
        try {
            ConversationStore::appendMessage($conversation, $role, mb_substr($text, 0, 4000), $extra);
        } catch (Throwable $ex) {
            app_log('whatsapp', 'could not add to transcript', ['error' => $ex->getMessage()]);
        }
    }

    /** Everything that follows from someone asking us to stop. */
    private static function recordOptOut(int $agentId, string $phone, ?int $inboxId): void
    {
        Suppression::add($agentId, 'phone', $phone, 'Asked to stop on WhatsApp', 'opt_out', 'visitor');

        db()->exec(
            "UPDATE registrations SET whatsapp_state = 'opted_out' WHERE agent_id = ? AND phone_e164 = ?",
            [$agentId, $phone]
        );

        WhatsAppOutbox::cancelPending($agentId, $phone, 'They asked to stop.');

        if ($inboxId !== null) {
            db()->update('whatsapp_inbox', ['handled' => 1, 'note' => 'Opted out'], 'id = ?', [$inboxId]);
        }

        Audit::log('whatsapp_opt_out', $agentId, 'registration', null, Phone::mask($phone));
    }

    /**
     * The conversation this phone number belongs to.
     *
     * One continuing conversation per number, so someone who replies a week
     * later is still talking to an assistant that remembers them rather than
     * starting again.
     *
     * A website visitor is issued a random session and a secret the browser
     * keeps. There is no browser here and nothing to keep it in, so both are
     * derived from the number and the platform key: the same number always
     * resolves to the same conversation, and the secret can be recomputed
     * next week without ever being stored. The derivation is server-side
     * only — nothing a sender controls reaches it except a number that has
     * already been through the phone parser.
     */
    public static function conversationFor(array $agent, string $phone): array
    {
        $agentId   = (int)$agent['id'];
        $sessionId = self::sessionIdFor($agentId, $phone);
        $secret    = self::sessionSecretFor($agentId, $phone);

        $existing = db()->one(
            'SELECT * FROM conversations WHERE agent_id = ? AND session_id = ?',
            [$agentId, $sessionId]
        );

        if ($existing !== null) {
            return ['conversation' => $existing, 'session_id' => $sessionId, 'session_secret' => $secret];
        }

        $registration = db()->one(
            'SELECT * FROM registrations WHERE agent_id = ? AND phone_e164 = ? ORDER BY id DESC LIMIT 1',
            [$agentId, $phone]
        );

        // Started the ordinary way, so returning-visitor recognition, the
        // prompt version and the audit entry all behave as they do on the
        // website, then given the derived identity.
        $made = ConversationStore::start($agent, [
            'visitor_id' => self::visitorIdFor($phone),
            'channel'    => 'whatsapp',
        ]);

        $conversationId = (int)$made['conversation']['id'];

        $fields = [
            'session_id'          => $sessionId,
            'session_secret_hash' => hash('sha256', $secret),
        ];

        // What they gave us at registration is the same thing a website
        // visitor would otherwise be asked for, so they are not asked again.
        if ($registration !== null) {
            if (!empty($registration['company_id'])) {
                $fields['company_id'] = (int)$registration['company_id'];
            }
            $resolved = GeoClassifier::resolveDisclosed($registration['country'] ?? null);
            if ($resolved['code'] !== null) {
                $fields['pricing_country'] = GeoClassifier::classifyCode($resolved['code']);
                $fields['country_source']  = GeoClassifier::SOURCE_DISCLOSED;
                $fields['disclosed_company_country'] = (string)$resolved['name'];
            }
        }

        db()->update('conversations', $fields, 'id = ?', [$conversationId]);

        $conversation = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId]) ?? [];

        // What we already sent them — the first message, and any follow-up —
        // opens the conversation, so their reply is read in its context.
        foreach (db()->all(
            "SELECT body FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 = ?
               AND kind IN ('opening','followup') AND status = 'sent' ORDER BY sent_at ASC, id ASC",
            [$agentId, $phone]
        ) as $sent) {
            self::recordInTranscript($conversation, 'agent', (string)$sent['body']);
        }

        return ['conversation' => $conversation, 'session_id' => $sessionId, 'session_secret' => $secret];
    }

    /** 48 characters, which is what the session validator accepts. */
    public static function sessionIdFor(int $agentId, string $phone): string
    {
        return 'wa' . mb_substr(hash('sha256', 'wa-session:' . $agentId . ':' . $phone), 0, 46);
    }

    private static function sessionSecretFor(int $agentId, string $phone): string
    {
        return hash_hmac('sha256', 'wa-secret:' . $agentId . ':' . $phone, (string)cfg('encryption_key', ''));
    }

    private static function visitorIdFor(string $phone): string
    {
        return mb_substr(hash('sha256', 'wa-visitor:' . $phone), 0, 40);
    }

    /**
     * The assistant's reply as plain text.
     *
     * The website is given the reply as blocks and prices as cards, which it
     * draws as paragraphs, bullet lists and price panels. A phone has none of
     * that, so the same content is written out: paragraphs as paragraphs,
     * lists as bullets, and each price card as a short block of lines.
     *
     * The figures are not recalculated here. They arrive already formatted by
     * the pricing engine, having passed the geography and approval rules, and
     * are copied across as they stand. This method decides layout and
     * nothing else.
     */
    public static function flatten(array $response): string
    {
        $parts = [];

        foreach ((array)($response['blocks'] ?? []) as $block) {
            $type = (string)($block['type'] ?? '');

            if ($type === 'p') {
                $text = self::spansToText((array)($block['spans'] ?? []));
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }

            if ($type === 'ul' || $type === 'ol') {
                $lines = [];
                $n = 0;
                foreach ((array)($block['items'] ?? []) as $item) {
                    $text = self::spansToText((array)$item);
                    if ($text === '') {
                        continue;
                    }
                    $n++;
                    $lines[] = ($type === 'ol' ? $n . '. ' : '• ') . $text;
                }
                if ($lines) {
                    $parts[] = implode("\n", $lines);
                }
            }
        }

        // Prices travel separately from the words, exactly as they do to the
        // website, so that nothing between here and the pricing engine can
        // rewrite a figure.
        foreach ((array)($response['cards'] ?? []) as $card) {
            $lines = [];

            if (trim((string)($card['title'] ?? '')) !== '') {
                $lines[] = '*' . trim((string)$card['title']) . '*';
            }

            foreach ((array)($card['meta'] ?? []) as $meta) {
                if (trim((string)$meta) !== '') {
                    $lines[] = trim((string)$meta);
                }
            }

            $priceLines = (array)($card['price_lines'] ?? []);
            if ($priceLines) {
                foreach ($priceLines as $line) {
                    $label = trim((string)($line['label'] ?? ''));
                    $value = trim((string)($line['value'] ?? ''));
                    if ($label !== '' && $value !== '') {
                        $lines[] = $label . ': ' . $value;
                    } elseif ($value !== '') {
                        $lines[] = $value;
                    }
                }
            } elseif (trim((string)($card['price_text'] ?? '')) !== '') {
                $basis = trim((string)($card['price_basis'] ?? ''));
                $lines[] = trim((string)$card['price_text']) . ($basis !== '' ? ' ' . $basis : '');
            }

            foreach (array_slice((array)($card['items'] ?? []), 0, 8) as $included) {
                if (trim((string)$included) !== '') {
                    $lines[] = '• ' . trim((string)$included);
                }
            }

            // Never dropped: what is and is not included in a figure is part
            // of the figure.
            if (trim((string)($card['tax_note'] ?? '')) !== '') {
                $lines[] = trim((string)$card['tax_note']);
            }

            if ($lines) {
                $parts[] = implode("\n", $lines);
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * A block's spans as text.
     *
     * A link is written as "label (address)", because a phone cannot show a
     * label that hides where it goes — and hiding it is how a message comes
     * to look like a scam.
     */
    private static function spansToText(array $spans): string
    {
        $out = '';

        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            $value = (string)($span['v'] ?? '');
            if ($value === '') {
                continue;
            }

            $kind = (string)($span['t'] ?? 'text');

            if ($kind === 'a') {
                $href = trim((string)($span['href'] ?? ''));
                $out .= $href !== '' && $href !== $value ? $value . ' (' . $href . ')' : $value;
                continue;
            }
            if ($kind === 'strong' || $kind === 'b') {
                $out .= '*' . $value . '*';
                continue;
            }

            $out .= $value;
        }

        return trim(preg_replace('/[ \t]+/', ' ', $out) ?? $out);
    }

    /**
     * Whether a reply is a request to stop.
     *
     * Read generously — someone who wants to be left alone should not have
     * to guess the magic word, and stopping when they did not mean it costs
     * far less than carrying on when they did. But read as words, not as
     * letters: "Block B is where our stand is" and "no more than two
     * booths" are not requests to stop, and treating them as such would
     * silently end conversations with people trying to buy.
     *
     * Three kinds of match:
     *  - the whole message is a stop word ("STOP", "Unsubscribe", "بند کرو")
     *  - it contains an unmistakable phrase ("remove my number")
     *  - it is short and says not interested ("Not interested, thanks")
     */
    public static function isOptOut(string $body): bool
    {
        $text = mb_strtolower(trim($body), 'UTF-8');
        if ($text === '') {
            return false;
        }

        // Apostrophes vanish rather than becoming spaces, so "don't" reads as
        // "dont" whichever apostrophe the phone used.
        $text = str_replace(["'", "\u{2019}", "\u{2018}", '`', "\u{00B4}"], '', $text);
        // Other punctuation becomes a space. Letters in any script are kept,
        // so a reply in Urdu is read, not erased.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return false;
        }

        // The whole message, and nothing else.
        $whole = [
            'stop', 'stopp', 'stop it', 'stop please', 'please stop', 'pls stop', 'plz stop', 'stop now',
            'unsubscribe', 'unsub', 'opt out', 'optout', 'cancel', 'end', 'quit',
            'remove', 'remove me', 'block', 'block me',
            'no thanks stop', 'leave me alone',
            // Roman Urdu, as people actually type it.
            'band karo', 'bnd karo', 'bnd kro', 'band kro', 'mat bhejo', 'mat bhejein', 'na bhejo',
            // Urdu.
            'بند کرو', 'بند کریں', 'مت بھیجو', 'مت بھیجیں', 'روک دو',
        ];
        if (in_array($text, $whole, true)) {
            return true;
        }

        // Phrases that mean stop wherever they appear.
        $phrases = [
            'stop messaging', 'stop sending', 'stop texting', 'stop contacting', 'stop calling',
            'stop these messages', 'stop this', 'stop spamming', 'stop spam',
            'unsubscribe', 'opt out', 'opt me out',
            'remove me', 'remove my number', 'remove my contact', 'remove my name', 'remove from your list',
            'take me off', 'delete my number',
            'do not contact', 'dont contact', 'do not message', 'dont message',
            'do not text', 'dont text', 'do not send', 'dont send', 'do not call', 'dont call',
            'do not whatsapp', 'dont whatsapp',
            'no more messages', 'no more texts', 'no more whatsapp',
            'leave me alone', 'this is spam', 'reported as spam', 'reporting you', 'report you',
            'blocking you', 'block you', 'will block',
            'message mat karo', 'msg mat karo', 'mat karo message', 'dobara message', 'dubara message',
            'رابطہ نہ کریں', 'پیغام نہ بھیجیں', 'میسج نہ کریں',
        ];
        foreach ($phrases as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        // "I do not want these messages", "dont want any more texts".
        if (preg_match('/\b(do not|dont|dnt) want (any |these |your |more |any more |this )?'
                     . '(messages?|msgs?|texts?|whatsapps?|spam)\b/u', $text) === 1) {
            return true;
        }

        // Not interested, said briefly. A long message that happens to say
        // "not interested in the bare space, but tell me about shell scheme"
        // is someone still talking to us.
        if (preg_match('/\bnot interested\b/u', $text) === 1
            && count(explode(' ', $text)) <= 6) {
            return true;
        }

        return false;
    }
}
