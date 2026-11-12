<?php
declare(strict_types=1);

/**
 * Lead capture and lifecycle (Sections 7, 8, 9, 34).
 *
 * A lead is created only once there is something worth following up.
 * Field corrections keep the latest visitor-confirmed value and push the
 * previous one into the audit trail rather than discarding it.
 */
final class LeadManager
{
    /** Statuses the application may set automatically (Section 8). */
    public const AUTO_STATUSES = ['new', 'contact_captured', 'qualified', 'pricing_discussed'];

    /** Statuses only an administrator sets. */
    public const MANUAL_STATUSES = ['interested', 'follow_up_required', 'converted', 'not_interested', 'closed', 'spam'];

    public const STATUS_LABELS = [
        'new'                => 'New',
        'contact_captured'   => 'Contact captured',
        'qualified'          => 'Qualified',
        'pricing_discussed'  => 'Pricing discussed',
        'interested'         => 'Interested',
        'follow_up_required' => 'Follow-up required',
        'converted'          => 'Converted',
        'not_interested'     => 'Not interested',
        'closed'             => 'Closed',
        'spam'               => 'Spam',
    ];

    /** Lead fields the conversation may carry and update. */
    public const TRACKED_FIELDS = [
        'name', 'email', 'phone', 'country', 'city', 'company', 'industry',
        'product_category', 'company_size', 'website', 'company_country', 'requirements',
    ];

    // -----------------------------------------------------------------
    // Returning visitors (Section 9)
    // -----------------------------------------------------------------

    /**
     * Find a prior lead for this browser, so known fields are reused
     * silently and never re-asked. Past messages are never returned.
     */
    public static function findByVisitorId(int $agentId, ?string $visitorId): ?array
    {
        $visitorId = Validator::opaqueId($visitorId);
        if ($visitorId === null) {
            return null;
        }
        return db()->one(
            "SELECT * FROM leads
             WHERE agent_id = ? AND visitor_id = ? AND status <> 'spam' AND merged_into_id IS NULL
             ORDER BY updated_at DESC LIMIT 1",
            [$agentId, $visitorId]
        ) ?: null;
    }

    /**
     * Structured lead state to preload into a new conversation for a
     * returning visitor. Contains fields only — never message history.
     */
    public static function stateFromLead(array $lead): array
    {
        $state = [];
        foreach (self::TRACKED_FIELDS as $f) {
            $v = $lead[$f] ?? null;
            if ($v !== null && $v !== '') {
                $state[$f] = (string)$v;
            }
        }
        if (!empty($lead['exhibitor_intent']) && $lead['exhibitor_intent'] !== 'unknown') {
            $state['intent'] = (string)$lead['exhibitor_intent'];
        }
        return $state;
    }

    // -----------------------------------------------------------------
    // Creation and updates
    // -----------------------------------------------------------------

    /**
     * Ensure a lead exists for this conversation, creating one if needed.
     */
    public static function ensureLead(array $agent, array $conversation): array
    {
        if (!empty($conversation['lead_id'])) {
            $lead = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [
                (int)$conversation['lead_id'], (int)$agent['id'],
            ]);
            if ($lead) {
                return $lead;
            }
        }

        $reference = self::generateReference();
        $leadId    = db()->insert('leads', [
            'agent_id'        => (int)$agent['id'],
            'reference'       => $reference,
            'status'          => 'new',
            'pricing_country' => (string)($conversation['pricing_country'] ?? 'undetermined'),
            'country_source'  => (string)($conversation['country_source'] ?? 'undetermined'),
            'visitor_id'      => $conversation['visitor_id'] ?? null,
            'first_seen_ip'   => $conversation['visitor_ip'] ?? null,
            'ip_retained_until' => self::ipRetentionDate(),
            'source_url'      => $conversation['page_url'] ?? null,
        ]);

        db()->update('conversations', ['lead_id' => $leadId], 'id = ?', [(int)$conversation['id']]);

        Audit::log('lead_created', (int)$agent['id'], 'lead', $leadId, 'Created from conversation ' . (int)$conversation['id']);

        return db()->one('SELECT * FROM leads WHERE id = ?', [$leadId]) ?? [];
    }

    private static function ipRetentionDate(): ?string
    {
        $days = (int)cfg('retention.raw_ip_days', 365);
        return $days > 0 ? gmdate('Y-m-d', time() + ($days * 86400)) : null;
    }

    public static function generateReference(): string
    {
        for ($i = 0; $i < 6; $i++) {
            $ref = 'L' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $exists = db()->value('SELECT id FROM leads WHERE reference = ?', [$ref]);
            if (!$exists) {
                return $ref;
            }
        }
        return 'L' . gmdate('ymdHis') . '-' . random_int(100, 999);
    }

    /**
     * Apply validated field values to a lead.
     * The latest visitor-confirmed value wins; the previous value is
     * recorded in the audit trail (Section 9).
     *
     * @param array<string,string|null> $fields already validated
     * @return string[] names of fields that changed
     */
    public static function applyFields(array $agent, array $lead, array $fields, string $source = 'conversation'): array
    {
        $update  = [];
        $changed = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $new = $fields[$field];
            if ($new === null || $new === '') {
                continue;
            }
            $old = (string)($lead[$field] ?? '');
            if (self::sameValue($old, (string)$new)) {
                continue;
            }
            $update[$field] = $new;
            $changed[]      = $field;

            Audit::log(
                'lead_field_updated',
                (int)$agent['id'],
                'lead',
                (int)$lead['id'],
                'source=' . $source,
                $field,
                $old === '' ? null : $old,
                (string)$new
            );
        }

        if (!$update) {
            return [];
        }

        db()->update('leads', $update, 'id = ? AND agent_id = ?', [(int)$lead['id'], (int)$agent['id']]);
        return $changed;
    }

    private static function sameValue(string $a, string $b): bool
    {
        $norm = static fn(string $s): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
        return $norm($a) === $norm($b);
    }

    /** True once at least one valid contact channel exists (Section 7). */
    public static function hasContact(array $lead): bool
    {
        return Validator::email($lead['email'] ?? null) !== null
            || Validator::phone($lead['phone'] ?? null) !== null;
    }

    /**
     * Advance the automatic part of the pipeline. Manual, business-outcome
     * statuses set by an administrator are never overwritten (Section 8).
     */
    public static function advanceStatus(array $agent, array $lead, string $candidate): ?string
    {
        if (!in_array($candidate, self::AUTO_STATUSES, true)) {
            return null;
        }
        $current = (string)($lead['status'] ?? 'new');
        if (in_array($current, self::MANUAL_STATUSES, true)) {
            return null;   // an administrator has taken ownership of this lead
        }

        $rank = array_flip(self::AUTO_STATUSES);
        if (($rank[$candidate] ?? 0) <= ($rank[$current] ?? 0)) {
            return null;   // never move a lead backwards automatically
        }

        db()->update('leads', ['status' => $candidate], 'id = ?', [(int)$lead['id']]);
        Audit::log('lead_status_changed', (int)$agent['id'], 'lead', (int)$lead['id'], 'automatic', 'status', $current, $candidate);
        return $candidate;
    }

    /** Administrator-set status change. */
    public static function setStatusManually(array $agent, int $leadId, string $status, string $actor): bool
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            return false;
        }
        $lead = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [$leadId, (int)$agent['id']]);
        if (!$lead) {
            return false;
        }
        $old = (string)$lead['status'];
        if ($old === $status) {
            return true;
        }
        db()->update('leads', ['status' => $status], 'id = ?', [$leadId]);
        Audit::log('lead_status_changed', (int)$agent['id'], 'lead', $leadId, 'manual by ' . $actor, 'status', $old, $status);
        return true;
    }

    // -----------------------------------------------------------------
    // Deduplication (Section 9) — flag only, never auto-merge
    // -----------------------------------------------------------------

    /**
     * Look for other leads sharing this lead's email or phone.
     * The administrator decides what to do; nothing is merged automatically.
     */
    public static function refreshDedupFlag(array $agent, array $lead): void
    {
        $matches = self::findPossibleDuplicates($agent, $lead);
        $flag    = $matches ? 1 : 0;
        $note    = null;

        if ($matches) {
            $refs = array_map(static fn($m) => (string)$m['reference'], array_slice($matches, 0, 4));
            $note = 'Same ' . implode(' / ', array_unique(array_column($matches, 'match_on')))
                  . ' as ' . implode(', ', $refs);
        }

        if ((int)($lead['dedup_flag'] ?? 0) === $flag && (string)($lead['dedup_note'] ?? '') === (string)$note) {
            return;
        }

        db()->update('leads', [
            'dedup_flag' => $flag,
            'dedup_note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ], 'id = ?', [(int)$lead['id']]);

        if ($flag) {
            Audit::log('lead_dedup_flagged', (int)$agent['id'], 'lead', (int)$lead['id'], $note);
        }
    }

    /**
     * @return array<int,array{id:int,reference:string,match_on:string,created_at:string,company:?string,status:string}>
     */
    public static function findPossibleDuplicates(array $agent, array $lead): array
    {
        $out   = [];
        $email = Validator::email($lead['email'] ?? null);
        $phone = Validator::phone($lead['phone'] ?? null);
        $id    = (int)($lead['id'] ?? 0);

        if ($email !== null) {
            foreach (db()->all(
                "SELECT id, reference, created_at, company, status FROM leads
                 WHERE agent_id = ? AND email = ? AND id <> ? AND merged_into_id IS NULL
                 ORDER BY id DESC LIMIT 10",
                [(int)$agent['id'], $email, $id]
            ) as $r) {
                $r['match_on'] = 'email';
                $out[(int)$r['id']] = $r;
            }
        }

        if ($phone !== null) {
            foreach (db()->all(
                "SELECT id, reference, created_at, company, status FROM leads
                 WHERE agent_id = ? AND phone = ? AND id <> ? AND merged_into_id IS NULL
                 ORDER BY id DESC LIMIT 10",
                [(int)$agent['id'], $phone, $id]
            ) as $r) {
                $r['match_on'] = isset($out[(int)$r['id']]) ? 'email + phone' : 'phone';
                $out[(int)$r['id']] = $r;
            }
        }

        return array_values($out);
    }

    /** Manual merge, performed only by an administrator. */
    public static function merge(array $agent, int $sourceId, int $targetId, string $actor): bool
    {
        if ($sourceId === $targetId) {
            return false;
        }
        $source = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [$sourceId, (int)$agent['id']]);
        $target = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [$targetId, (int)$agent['id']]);
        if (!$source || !$target) {
            return false;
        }

        db()->begin();
        try {
            // Fill blanks on the target from the source; never overwrite.
            $fill = [];
            foreach (self::TRACKED_FIELDS as $f) {
                if ((string)($target[$f] ?? '') === '' && (string)($source[$f] ?? '') !== '') {
                    $fill[$f] = $source[$f];
                }
            }
            if ($fill) {
                db()->update('leads', $fill, 'id = ?', [$targetId]);
            }

            // Move conversations across, then retire the source.
            db()->exec('UPDATE conversations SET lead_id = ? WHERE lead_id = ?', [$targetId, $sourceId]);
            db()->update('leads', [
                'merged_into_id' => $targetId,
                'status'         => 'closed',
                'dedup_flag'     => 0,
                'dedup_note'     => 'Merged into ' . (string)$target['reference'],
            ], 'id = ?', [$sourceId]);

            Audit::log('lead_merged', (int)$agent['id'], 'lead', $sourceId,
                'Merged into ' . (string)$target['reference'] . ' by ' . $actor);
            db()->commit();
            return true;
        } catch (Throwable $ex) {
            db()->rollback();
            app_log('lead', 'merge failed', ['error' => $ex->getMessage()]);
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Package interest and handoff (Section 34)
    // -----------------------------------------------------------------

    public static function addPackageInterest(array $agent, array $lead, array $card): void
    {
        $existing = json_decode((string)($lead['package_interest'] ?? '[]'), true);
        if (!is_array($existing)) {
            $existing = [];
        }
        foreach ($existing as $e) {
            if (($e['reference'] ?? '') === ($card['reference'] ?? '')) {
                return;
            }
        }
        $existing[] = [
            'reference' => (string)$card['reference'],
            'type'      => (string)$card['type'],
            'label'     => (string)$card['title'],
            // The figure a salesperson needs is what the visitor would pay,
            // so record the total where the card has one.
            'price'     => (string)(!empty($card['price_lines'])
                ? end($card['price_lines'])['value']
                : $card['price_text']),
            'noted_at'  => gmdate('c'),
        ];
        $existing = array_slice($existing, -10);

        db()->update('leads', [
            'package_interest' => json_encode($existing, JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [(int)$lead['id']]);

        Audit::log('lead_package_interest', (int)$agent['id'], 'lead', (int)$lead['id'], (string)$card['title']);
    }

    public static function markHandoff(array $agent, array $lead, string $reason): void
    {
        if ((int)($lead['handoff_required'] ?? 0) === 1) {
            return;
        }
        db()->update('leads', [
            'handoff_required' => 1,
            'handoff_reason'   => mb_substr($reason, 0, 255),
        ], 'id = ?', [(int)$lead['id']]);
        Audit::log('lead_handoff', (int)$agent['id'], 'lead', (int)$lead['id'], $reason);
    }

    public static function markPricingDiscussed(array $agent, array $lead): void
    {
        if ((int)($lead['pricing_discussed'] ?? 0) === 1) {
            return;
        }
        db()->update('leads', ['pricing_discussed' => 1], 'id = ?', [(int)$lead['id']]);
    }

    /** Keep the lead's geo fields aligned with the conversation. */
    public static function syncGeo(array $lead, array $conversation): void
    {
        $update = [];
        if ((string)$lead['pricing_country'] !== (string)$conversation['pricing_country']) {
            $update['pricing_country'] = (string)$conversation['pricing_country'];
        }
        if ((string)$lead['country_source'] !== (string)$conversation['country_source']) {
            $update['country_source'] = (string)$conversation['country_source'];
        }
        if ($update) {
            db()->update('leads', $update, 'id = ?', [(int)$lead['id']]);
        }
    }

    // -----------------------------------------------------------------
    // Notifications (Section 33)
    // -----------------------------------------------------------------

    /**
     * `$occasion` separates notifications that would otherwise look like
     * repeats — a second, separate handover on the same lead. Without it,
     * the email queue treats it as already sent.
     */
    public static function notifyNewLead(array $agent, array $lead, string $trigger, ?string $occasion = null,
                                         ?array $attachment = null): void
    {
        // Local enquiries go to the people who handle Pakistan business;
        // everything else goes to the international list. A visitor whose
        // country is still unknown is treated as local, because that is the
        // team who can establish it (Section 16).
        $region  = (string)($lead['pricing_country'] ?? 'undetermined');
        $isLocal = in_array($region, [GeoClassifier::PAKISTAN, GeoClassifier::UNDETERMINED], true);

        $recipients = self::recipients($agent, $isLocal);
        if (!$recipients) {
            return;
        }

        if (in_array($trigger, ['contact', 'researched'], true) && (int)($agent['notify_on_lead'] ?? 1) !== 1) {
            return;
        }
        if ($trigger === 'handoff' && (int)($agent['notify_on_handoff'] ?? 1) !== 1) {
            return;
        }

        $briefing = LeadBriefing::build($agent, $lead, $trigger, $isLocal);

        foreach ($recipients as $to) {
            Mailer::queue(
                $to,
                $briefing['subject'],
                $briefing['text'],
                (int)$agent['id'],
                'lead-' . (int)$lead['id'] . '-' . $trigger
                    . ($occasion !== null ? '-' . substr(md5($occasion), 0, 10) : '') . '-' . md5($to),
                $briefing['html'],
                $attachment
            );
        }
    }

    /**
     * Notification recipients for this kind of enquiry. Both fields accept
     * several addresses separated by commas, so a whole desk can be copied.
     */
    public static function recipients(array $agent, bool $isLocal): array
    {
        $parse = static function (?string $raw): array {
            $out = [];
            foreach (preg_split('/[,;\s]+/', (string)$raw) ?: [] as $candidate) {
                $valid = Validator::email($candidate);
                if ($valid !== null) {
                    $out[] = $valid;
                }
            }
            return $out;
        };

        $primary = $isLocal
            ? $parse($agent['notify_email_local'] ?? null)
            : $parse($agent['notify_email'] ?? null);

        if ($primary) {
            return array_values(array_unique($primary));
        }

        // Fall back to the other list rather than losing the lead entirely.
        $fallback = $isLocal
            ? $parse($agent['notify_email'] ?? null)
            : $parse($agent['notify_email_local'] ?? null);

        return array_values(array_unique($fallback));
    }

}
