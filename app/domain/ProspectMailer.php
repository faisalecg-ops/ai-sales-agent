<?php
declare(strict_types=1);

/**
 * Approving and sending prospect emails, and honouring unsubscribes.
 *
 * Sending is deliberately slow: one email per run at most, never sooner than
 * the exhibition's interval after the last, never more than its daily
 * number, always through its own mail server, and never to anyone who has
 * since unsubscribed, been marked do not contact, or had the company
 * blocked. The check at the moment of sending is the one that counts.
 */
final class ProspectMailer
{
    private const MAX_ATTEMPTS = 3;

    // -----------------------------------------------------------------
    // The team's decisions
    // -----------------------------------------------------------------

    public static function email(int $agentId, int $id): ?array
    {
        return db()->one('SELECT * FROM outreach_emails WHERE id = ? AND agent_id = ?', [$id, $agentId]);
    }

    public static function forTarget(int $targetId): ?array
    {
        return db()->one('SELECT * FROM outreach_emails WHERE target_id = ? ORDER BY id DESC LIMIT 1', [$targetId]);
    }

    /**
     * Save the team's changes to a draft. Checked like the AI's own drafts;
     * a problem is reported, and the person decides.
     *
     * @return ?string a warning, or null
     */
    public static function saveEdit(array $agent, int $id, string $subject, string $body, string $actor): ?string
    {
        $agentId = (int)$agent['id'];
        $email = self::email($agentId, $id);
        if ($email === null || !in_array((string)$email['status'], ['draft', 'approved', 'failed'], true)) {
            return 'This email can no longer be changed.';
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $body    = trim(str_replace("\r\n", "\n", $body));
        if ($subject === '' || $body === '') {
            return 'The subject and the message cannot be empty.';
        }
        db()->update('outreach_emails', [
            'subject' => mb_substr($subject, 0, 255), 'body' => mb_substr($body, 0, 8000),
            'status' => 'draft', 'edited_by' => mb_substr($actor, 0, 64), 'approved_by' => null, 'approved_at' => null,
        ], 'id = ?', [$id]);
        Audit::setActor($actor);
        Audit::log('outreach_edited', $agentId, 'outreach_email', $id, 'Edited by ' . $actor);

        $target = Prospecting::target($agentId, (int)$email['target_id']);
        if ($target !== null && (string)$target['state'] === 'approved') {
            Prospecting::setState($target, 'awaiting_approval', 'team', 'Edited — needs approving again.', [], $actor);
        }

        $case = ['allowed_numbers' => [], 'may_pitch' => true];
        $research = $target !== null && !empty($target['research_id']) ? CompanyResearch::find($agentId, (int)$target['research_id']) : null;
        $facts = $research !== null ? implode(' ', array_column((array)(CompanyResearch::findingsOf($research)['facts'] ?? []), 'claim')) : '';
        return OutreachWriter::check($agent, $subject, $body, $case,
            array_merge(ValueProposition::approvedNumbers(db()->all(
                "SELECT body FROM knowledge_articles WHERE agent_id = ? AND status = 'approved'", [$agentId])),
                ValueProposition::numbersIn($facts)));
    }

    public static function approve(int $agentId, int $id, string $actor): ?string
    {
        $email = self::email($agentId, $id);
        if ($email === null || (string)$email['status'] !== 'draft') {
            return 'Only a draft can be approved.';
        }
        if (WhatsAppEngine::blockedReason($agentId, null, (string)$email['to_email'], (int)$email['company_id']) !== null) {
            return 'This address or company is on the do-not-contact list.';
        }
        $t = Prospecting::target($agentId, (int)$email['target_id']);
        if ($t === null || in_array((string)$t['state'], Prospecting::FINAL_STATES, true)) {
            return 'This company is no longer to be contacted.';
        }
        db()->update('outreach_emails', [
            'status' => 'approved', 'approved_by' => mb_substr($actor, 0, 64), 'approved_at' => gmdate('Y-m-d H:i:s'),
        ], "id = ? AND status = 'draft'", [$id]);
        Audit::setActor($actor);
        Audit::log('outreach_approved', $agentId, 'outreach_email', $id, 'Approved by ' . $actor);
        $target = Prospecting::target($agentId, (int)$email['target_id']);
        if ($target !== null) {
            Prospecting::setState($target, 'approved', 'team', 'Approved by ' . $actor . '.', [], $actor);
        }
        return null;
    }

    /** The team rejects a company for this campaign; its draft is withdrawn. */
    public static function reject(int $agentId, int $targetId, string $actor, string $reason = ''): bool
    {
        $target = Prospecting::target($agentId, $targetId);
        if ($target === null || in_array((string)$target['state'], ['sent'], true)) {
            return false;
        }
        db()->update('outreach_emails', ['status' => 'cancelled', 'last_error' => 'Rejected by ' . $actor],
            "target_id = ? AND status IN ('draft','approved')", [$targetId]);
        Prospecting::setState($target, 'rejected', 'team', $reason !== '' ? $reason : 'Rejected by ' . $actor . '.', [], $actor);
        return true;
    }

    /** Never contact this company again, from any campaign. */
    public static function doNotContact(int $agentId, int $targetId, string $actor): bool
    {
        $target = Prospecting::target($agentId, $targetId);
        if ($target === null) {
            return false;
        }
        CompanyStore::setDoNotContact($agentId, (int)$target['company_id'], $actor, 'Marked from prospecting');
        db()->update('outreach_emails', ['status' => 'cancelled', 'last_error' => 'Company marked do not contact'],
            "company_id = ? AND agent_id = ? AND status IN ('draft','approved')", [(int)$target['company_id'], $agentId]);
        foreach (db()->all('SELECT * FROM prospect_targets WHERE agent_id = ? AND company_id = ?', [$agentId, (int)$target['company_id']]) as $t) {
            Prospecting::setState($t, 'do_not_contact', 'team', 'Marked do not contact by ' . $actor . '.', [], $actor);
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public static function emailsPerDay(array $agent): int
    {
        return max(1, (int)($agent['prospect_emails_per_day'] ?? 30));
    }

    public static function intervalMinutes(array $agent): int
    {
        return max(1, (int)($agent['prospect_email_interval_minutes'] ?? 10));
    }

    /** The marker kept on an email a campaign approved by itself. */
    public const AUTO_APPROVED = 'automatic (campaign setting)';

    /** Emails that went, or may have gone, since midnight where the exhibition is. */
    public static function sentToday(array $agent): int
    {
        return (int)db()->value(
            "SELECT COUNT(*) FROM outreach_emails WHERE agent_id = ? AND sent_at >= ?
               AND (status IN ('sent','sending') OR delivery_unknown = 1)",
            [(int)$agent['id'], WhatsAppEngine::dayStartUtc($agent)]
        );
    }

    /** Whether this address has been emailed — or may have been — by this exhibition. */
    public static function addressEmailed(int $agentId, string $email, int $exceptId = 0): bool
    {
        return db()->value(
            "SELECT id FROM outreach_emails WHERE agent_id = ? AND to_email = ? AND id <> ?
               AND (status IN ('sent','sending') OR delivery_unknown = 1) LIMIT 1",
            [$agentId, mb_strtolower(trim($email)), $exceptId]
        ) !== null;
    }

    /**
     * Send the next approved email, if the exhibition's limits allow.
     *
     * @return string a line for the report, or '' when nothing happened
     */
    public static function sendDue(array $agent): string
    {
        $agentId = (int)$agent['id'];
        if (OutboundSwitch::isStopped() || MailProfile::problem($agent) !== null) {
            return '';
        }
        if (self::sentToday($agent) >= self::emailsPerDay($agent)) {
            return '';
        }
        // Every attempt counts towards the interval, including one that
        // failed and will be tried again.
        $last = db()->value('SELECT MAX(sent_at) FROM outreach_emails WHERE agent_id = ? AND sent_at IS NOT NULL', [$agentId]);
        if ($last !== null && strtotime((string)$last . ' UTC') > time() - self::intervalMinutes($agent) * 60) {
            return '';
        }

        // A send that was cut off is not tried again: whether it reached
        // them is unknown, and a second copy is worse than none.
        foreach (db()->all("SELECT * FROM outreach_emails WHERE agent_id = ? AND status = 'sending' AND updated_at < ?",
                     [$agentId, gmdate('Y-m-d H:i:s', time() - 900)]) as $stuck) {
            self::markUnknown($agentId, $stuck, 'Sending was interrupted.');
        }

        // Only campaigns that are running today: paused, finished or past
        // their end date, nothing more goes out.
        $email = null;
        foreach (db()->all(
            "SELECT o.*, p.approval_mode, p.status AS campaign_status, p.starts_on, p.ends_on
               FROM outreach_emails o JOIN prospect_campaigns p ON p.id = o.campaign_id
              WHERE o.agent_id = ? AND o.status = 'approved' AND p.status = 'active'
              ORDER BY o.approved_at ASC, o.id ASC LIMIT 20",
            [$agentId]
        ) as $candidate) {
            if (!Prospecting::isLive(['status' => $candidate['campaign_status'], 'starts_on' => $candidate['starts_on'],
                    'ends_on' => $candidate['ends_on']], $agent)) {
                continue;
            }
            // Approved by the campaign itself, but the campaign has since been
            // set to need a person: it goes back to waiting for one.
            if ((string)$candidate['approval_mode'] === 'manual' && (string)$candidate['approved_by'] === self::AUTO_APPROVED) {
                db()->update('outreach_emails', ['status' => 'draft', 'approved_by' => null, 'approved_at' => null],
                    "id = ? AND status = 'approved'", [(int)$candidate['id']]);
                $t = Prospecting::target($agentId, (int)$candidate['target_id']);
                if ($t !== null) {
                    Prospecting::setState($t, 'awaiting_approval', 'system', 'The campaign now needs a person to approve each email.');
                }
                continue;
            }
            $email = $candidate;
            break;
        }
        if ($email === null) {
            return '';
        }
        $target = Prospecting::target($agentId, (int)$email['target_id']);

        $why = self::refuse($agent, $email, $target);
        if ($why !== null) {
            db()->update('outreach_emails', ['status' => 'cancelled', 'last_error' => $why], 'id = ?', [(int)$email['id']]);
            if ($target !== null && !in_array((string)$target['state'], Prospecting::FINAL_STATES, true)) {
                Prospecting::setState($target, 'do_not_contact', 'system', $why);
            }
            return 'prospect email to ' . $email['to_email'] . ' stopped: ' . $why;
        }

        // Claimed first, so two runs never send the same email.
        $claimed = db()->update('outreach_emails', ['status' => 'sending', 'sent_at' => gmdate('Y-m-d H:i:s'),
            'attempts' => (int)$email['attempts'] + 1], "id = ? AND status = 'approved'", [(int)$email['id']]) === 1;
        if (!$claimed) {
            return '';
        }

        $unsubscribe = self::unsubscribeUrl($agentId, (string)$email['to_email']);
        $chatLink = PersonalLink::url($agent, 'prospect', (int)$email['id']);
        $body = rtrim((string)$email['body'])
              . ($chatLink !== null ? "\n\n" . PersonalLink::line($chatLink) . "\n" : '')
              . "\n" . OutreachWriter::footer($agent, $target ?? [], $unsubscribe);

        $result = Mailer::sendWith(MailProfile::forAgent($agent), (string)$email['to_email'], (string)$email['subject'], $body, null, [
            'List-Unsubscribe'      => '<' . $unsubscribe . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        if ($result['ok']) {
            db()->update('outreach_emails', ['status' => 'sent', 'message_id' => $result['message_id'], 'last_error' => null],
                'id = ?', [(int)$email['id']]);
            Audit::log('outreach_sent', $agentId, 'outreach_email', (int)$email['id'],
                'Sent to ' . $email['to_email'] . ' from ' . MailProfile::sender($agent));
            if ($target !== null) {
                Prospecting::setState($target, 'sent', 'system', 'Sent to ' . $email['to_email'] . '.');
            }
            return 'prospect email sent to ' . $email['to_email'];
        }

        if (!empty($result['uncertain'])) {
            self::markUnknown($agentId, $email, 'The mail server stopped answering after the email was handed to it.');
            return 'prospect email to ' . $email['to_email'] . ': not known whether it arrived';
        }

        $attempts = (int)$email['attempts'] + 1;
        $final = $result['permanent'] || $attempts >= self::MAX_ATTEMPTS;
        // The attempt's time is kept, so the interval applies to a retry too.
        db()->update('outreach_emails', [
            'status'     => $final ? 'failed' : 'approved',
            'last_error' => mb_substr((string)$result['error'], 0, 400),
        ], 'id = ?', [(int)$email['id']]);
        Audit::log('outreach_failed', $agentId, 'outreach_email', (int)$email['id'], (string)$result['error']);
        if ($result['permanent']) {
            // The address does not exist: never tried again, from any campaign.
            Suppression::add($agentId, 'email', (string)$email['to_email'], 'The receiving server refused the address', 'bounce');
        }
        if ($final && $target !== null) {
            Prospecting::setState($target, 'email_failed', 'system', $result['permanent']
                ? 'The receiving server refused the address.' : 'The mail server did not accept it after ' . $attempts . ' tries.');
        }
        return 'prospect email to ' . $email['to_email'] . ' failed';
    }

    /**
     * An email that may or may not have arrived. Never sent again, and the
     * company counts as emailed, so no campaign writes to it a second time.
     */
    private static function markUnknown(int $agentId, array $email, string $why): void
    {
        db()->update('outreach_emails', [
            'status' => 'failed', 'delivery_unknown' => 1,
            'last_error' => $why . ' It is not known whether it arrived, so it will not be sent again. '
                . 'Check with the company before contacting them another way.',
        ], 'id = ?', [(int)$email['id']]);
        Audit::log('outreach_uncertain', $agentId, 'outreach_email', (int)$email['id'], $why);
        $target = Prospecting::target($agentId, (int)$email['target_id']);
        if ($target !== null) {
            Prospecting::setState($target, 'email_failed', 'system', 'Not known whether the email arrived. It will not be sent again.');
        }
    }

    /** Why this email must not go now, or null. */
    private static function refuse(array $agent, array $email, ?array $target): ?string
    {
        $agentId = (int)$agent['id'];
        if ($target === null) {
            return 'The company is no longer in the campaign.';
        }
        if (in_array((string)$target['state'], Prospecting::FINAL_STATES, true)) {
            return 'The company is now "' . Prospecting::STATES[(string)$target['state']] . '".';
        }
        $blocked = WhatsAppEngine::blockedReason($agentId, null, (string)$email['to_email'], (int)$email['company_id']);
        if ($blocked !== null) {
            return $blocked;
        }
        $other = db()->value(
            "SELECT id FROM outreach_emails WHERE agent_id = ? AND company_id = ? AND id <> ?
               AND (status IN ('sent','sending') OR delivery_unknown = 1) LIMIT 1",
            [$agentId, (int)$email['company_id'], (int)$email['id']]
        );
        if ($other !== null) {
            return 'This company has already been emailed.';
        }
        if (self::addressEmailed($agentId, (string)$email['to_email'], (int)$email['id'])) {
            return 'This address has already been emailed.';
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Unsubscribing
    // -----------------------------------------------------------------

    public static function unsubscribeToken(int $agentId, string $email): string
    {
        return substr(hash_hmac('sha256', 'unsub:' . $agentId . ':' . mb_strtolower(trim($email)), (string)cfg('encryption_key', '')), 0, 32);
    }

    public static function unsubscribeUrl(int $agentId, string $email): string
    {
        return url('unsubscribe.php') . '?a=' . $agentId
            . '&e=' . rtrim(strtr(base64_encode(mb_strtolower(trim($email))), '+/', '-_'), '=')
            . '&t=' . self::unsubscribeToken($agentId, $email);
    }

    /** The address in an unsubscribe link, if the link is genuine. */
    public static function readUnsubscribe(array $query): ?array
    {
        $agentId = (int)($query['a'] ?? 0);
        $raw = (string)($query['e'] ?? '');
        $email = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4), true);
        if ($agentId <= 0 || $email === false || Validator::email($email) === null) {
            return null;
        }
        if (!hash_equals(self::unsubscribeToken($agentId, $email), (string)($query['t'] ?? ''))) {
            return null;
        }
        $agent = db()->one('SELECT id, name FROM agents WHERE id = ?', [$agentId]);
        return $agent !== null ? ['agent' => $agent, 'email' => mb_strtolower($email)] : null;
    }

    /** Stop all further prospect email to this address, from this exhibition. */
    public static function unsubscribe(int $agentId, string $email): void
    {
        $email = mb_strtolower(trim($email));
        Suppression::add($agentId, 'email', $email, 'Unsubscribed from a prospect email', 'opt_out');
        db()->update('outreach_emails', ['status' => 'cancelled', 'last_error' => 'They unsubscribed'],
            "agent_id = ? AND to_email = ? AND status IN ('draft','approved')", [$agentId, $email]);
        foreach (db()->all('SELECT * FROM prospect_targets WHERE agent_id = ? AND contact_email = ?', [$agentId, $email]) as $t) {
            Prospecting::setState($t, 'unsubscribed', 'system', 'They used the unsubscribe link.');
        }
        Audit::log('outreach_unsubscribed', $agentId, 'suppression', null, $email);
    }
}
