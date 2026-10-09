<?php
declare(strict_types=1);

/**
 * Everything the platform knows about one person, in one place.
 *
 * The same person reaches us several ways: they register, chat on the
 * website, answer on WhatsApp, become a lead, and their company is
 * researched. Each lives in its own table for good reasons. This class puts
 * them back together for the team, and nothing else: it reads, it never
 * merges or changes a record.
 *
 * Records are joined only on things that identify one person — the same
 * mobile number, the same personal email address, an explicit link (a
 * conversation's lead, a registration's lead), or the same browser. Shared
 * mailboxes such as info@ or sales@ are not used to join, because they
 * belong to a whole company, and showing one person another's conversation
 * is worse than showing two pages for one person.
 */
final class PersonView
{
    /** Mailboxes that belong to a company rather than a person. */
    private const SHARED_MAILBOXES = [
        'info', 'sales', 'contact', 'contactus', 'admin', 'office', 'marketing', 'hello', 'enquiries',
        'enquiry', 'inquiries', 'inquiry', 'support', 'mail', 'team', 'export', 'exports', 'general',
        'reception', 'hr', 'accounts', 'noreply', 'no-reply', 'business', 'service', 'customerservice',
    ];

    /** However far the links reach, a page shows at most this many of each. */
    private const MAX_EACH = 25;

    public const WHO_LABELS = [
        'visitor' => 'Visitor',
        'ai'      => 'AI',
        'team'    => 'Team',
        'system'  => 'System',
    ];

    // -----------------------------------------------------------------
    // Who belongs together
    // -----------------------------------------------------------------

    /**
     * Gather the records that belong to one person, starting from any one
     * of them.
     *
     * @param array{lead?:int,registration?:int,conversation?:int,phone?:string,email?:string} $seed
     * @return array{leads:array,registrations:array,conversations:array,phones:array,emails:array,
     *               linked_by:array<int,string>}
     */
    public static function resolve(int $agentId, array $seed): array
    {
        $leadIds = $regIds = $convIds = [];
        $phones = $emails = $visitorIds = [];
        $linkedBy = [];

        if (!empty($seed['lead']))         { $leadIds[(int)$seed['lead']] = true; }
        if (!empty($seed['registration'])) { $regIds[(int)$seed['registration']] = true; }
        if (!empty($seed['conversation'])) { $convIds[(int)$seed['conversation']] = true; }
        if (!empty($seed['phone'])) {
            $p = Phone::fromInternational((string)$seed['phone']);
            if ($p !== null) { $phones[$p] = true; }
        }
        if (!empty($seed['email'])) {
            $e = self::personalEmail((string)$seed['email']);
            if ($e !== null) { $emails[$e] = true; }
        }

        $leads = $registrations = $conversations = [];

        // A few rounds: each record found may carry an identifier that finds
        // others. Three is enough for registration → WhatsApp → lead → chat.
        for ($round = 0; $round < 4; $round++) {
            $before = count($leadIds) + count($regIds) + count($convIds) + count($phones) + count($emails) + count($visitorIds);

            foreach (array_keys($leadIds) as $id) {
                if (isset($leads[$id]) || count($leads) >= self::MAX_EACH) { continue; }
                $lead = db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [$id, $agentId]);
                if ($lead === null) { unset($leadIds[$id]); continue; }
                $leads[$id] = $lead;
                self::addPhone($phones, $lead['phone'] ?? null, $lead['country'] ?? null);
                self::addEmail($emails, $lead['email'] ?? null);
                if (!empty($lead['visitor_id'])) { $visitorIds[(string)$lead['visitor_id']] = true; }
                if (!empty($lead['merged_into_id'])) { $leadIds[(int)$lead['merged_into_id']] = true; }
                foreach (db()->all('SELECT id FROM leads WHERE agent_id = ? AND merged_into_id = ? LIMIT 10', [$agentId, $id]) as $m) {
                    $leadIds[(int)$m['id']] = true;
                }
                foreach (db()->all('SELECT id FROM conversations WHERE agent_id = ? AND lead_id = ? LIMIT 25', [$agentId, $id]) as $c) {
                    $convIds[(int)$c['id']] = true;
                }
            }

            foreach (array_keys($regIds) as $id) {
                if (isset($registrations[$id]) || count($registrations) >= self::MAX_EACH) { continue; }
                $reg = db()->one('SELECT * FROM registrations WHERE id = ? AND agent_id = ?', [$id, $agentId]);
                if ($reg === null) { unset($regIds[$id]); continue; }
                $registrations[$id] = $reg;
                if (!empty($reg['phone_e164'])) { $phones[(string)$reg['phone_e164']] = true; }
                self::addEmail($emails, $reg['email'] ?? null);
                if (!empty($reg['lead_id'])) { $leadIds[(int)$reg['lead_id']] = true; }
            }

            foreach (array_keys($convIds) as $id) {
                if (isset($conversations[$id]) || count($conversations) >= self::MAX_EACH) { continue; }
                $conv = db()->one('SELECT * FROM conversations WHERE id = ? AND agent_id = ?', [$id, $agentId]);
                if ($conv === null) { unset($convIds[$id]); continue; }
                $conversations[$id] = $conv;
                if (!empty($conv['lead_id'])) { $leadIds[(int)$conv['lead_id']] = true; }
                if ((string)$conv['channel'] === 'whatsapp') {
                    $phone = WhatsAppEngine::phoneForConversation($agentId, $conv);
                    if ($phone !== null) { $phones[$phone] = true; }
                } elseif (!empty($conv['visitor_id'])) {
                    $visitorIds[(string)$conv['visitor_id']] = true;
                }
            }

            foreach (array_keys($phones) as $phone) {
                foreach (db()->all('SELECT id FROM registrations WHERE agent_id = ? AND phone_e164 = ? LIMIT 25', [$agentId, $phone]) as $r) {
                    $regIds[(int)$r['id']] = true;
                }
                $wa = db()->value('SELECT id FROM conversations WHERE agent_id = ? AND session_id = ?',
                    [$agentId, WhatsAppEngine::sessionIdFor($agentId, $phone)]);
                if ($wa !== null) { $convIds[(int)$wa] = true; }
                // Leads keep the number as typed; compared on its last ten
                // digits, which is the number itself without any prefix.
                $digits = preg_replace('/\D/', '', $phone) ?? '';
                if (strlen($digits) >= 10) {
                    foreach (db()->all(
                        "SELECT id FROM leads WHERE agent_id = ? AND phone IS NOT NULL
                           AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ? LIMIT 25",
                        [$agentId, substr($digits, -10)]
                    ) as $l) {
                        $leadIds[(int)$l['id']] = true;
                    }
                }
            }

            foreach (array_keys($emails) as $email) {
                foreach (db()->all('SELECT id FROM registrations WHERE agent_id = ? AND LOWER(email) = ? LIMIT 25', [$agentId, $email]) as $r) {
                    $regIds[(int)$r['id']] = true;
                }
                foreach (db()->all('SELECT id FROM leads WHERE agent_id = ? AND LOWER(email) = ? LIMIT 25', [$agentId, $email]) as $l) {
                    $leadIds[(int)$l['id']] = true;
                }
            }

            // The same browser coming back to the website chat.
            foreach (array_keys($visitorIds) as $vid) {
                foreach (db()->all(
                    "SELECT id FROM conversations WHERE agent_id = ? AND visitor_id = ? AND channel <> 'whatsapp' LIMIT 25",
                    [$agentId, $vid]
                ) as $c) {
                    $convIds[(int)$c['id']] = true;
                }
            }

            $after = count($leadIds) + count($regIds) + count($convIds) + count($phones) + count($emails) + count($visitorIds);
            if ($after === $before
                && count($leads) === count($leadIds) && count($registrations) === count($regIds)
                && count($conversations) === count($convIds)) {
                break;
            }
        }

        if ($phones) {
            $linkedBy[] = 'Mobile number ' . implode(', ', array_keys($phones));
        }
        if ($emails) {
            $linkedBy[] = 'Email ' . implode(', ', array_keys($emails));
        }
        if ($visitorIds) {
            $linkedBy[] = 'The same browser returning to the website chat';
        }

        ksort($leads);
        ksort($registrations);
        ksort($conversations);

        return [
            'leads'         => array_values($leads),
            'registrations' => array_values($registrations),
            'conversations' => array_values($conversations),
            'phones'        => array_keys($phones),
            'emails'        => array_keys($emails),
            'linked_by'     => $linkedBy,
        ];
    }

    /** A personal address, lower-cased, or null for a shared mailbox or nonsense. */
    public static function personalEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string)$email));
        if ($email === '' || !str_contains($email, '@') || Validator::email($email) === null) {
            return null;
        }
        $local = explode('@', $email)[0];
        return in_array($local, self::SHARED_MAILBOXES, true) ? null : $email;
    }

    private static function addEmail(array &$emails, ?string $email): void
    {
        $e = self::personalEmail($email);
        if ($e !== null) {
            $emails[$e] = true;
        }
    }

    private static function addPhone(array &$phones, ?string $raw, ?string $country): void
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return;
        }
        $p = Phone::fromInternational($raw) ?? Phone::toE164($raw, $country, false);
        if ($p !== null) {
            $phones[$p] = true;
        }
    }

    // -----------------------------------------------------------------
    // At a glance
    // -----------------------------------------------------------------

    /**
     * The short answer to "who is this, and where are we with them" — the
     * summary the spec describes: registration, website, WhatsApp, interest,
     * lead, research, whether a person needs to act, and whether they may be
     * contacted at all.
     */
    public static function summary(array $agent, array $p): array
    {
        $agentId = (int)$agent['id'];
        $lead = $p['leads'] ? self::primaryLead($p['leads']) : null;
        $reg  = $p['registrations'] ? end($p['registrations']) : null;

        $pick = static function (string ...$values): string {
            foreach ($values as $v) {
                if (trim($v) !== '') {
                    return trim($v);
                }
            }
            return '';
        };

        $web = array_values(array_filter($p['conversations'], static fn($c) => (string)$c['channel'] !== 'whatsapp'));
        $wa  = array_values(array_filter($p['conversations'], static fn($c) => (string)$c['channel'] === 'whatsapp'));

        // Interest, from everything they did — never only one label.
        $interests = [];
        foreach ($p['conversations'] as $c) {
            $label = match ((string)$c['intent']) {
                'exhibitor' => 'Exhibiting',
                'sponsor'   => 'Sponsorship',
                'attendee'  => 'Visiting',
                'partner'   => 'Partnership',
                'media'     => 'Media',
                default     => null,
            };
            if ($label !== null) { $interests[$label] = true; }
        }
        foreach ($p['registrations'] as $r) {
            $interests['Visiting'] = true;
            $said = mb_strtolower((string)($r['interested_in'] ?? '') . ' ' . (string)($r['reg_type'] ?? '') . ' ' . (string)($r['purpose'] ?? ''));
            if (preg_match('/conference|symposium|seminar|summit|workshop/u', $said)) { $interests['Conference'] = true; }
            if (preg_match('/exhibit|stall|stand|booth/u', $said)) { $interests['Exhibiting'] = true; }
            if (str_contains($said, 'sponsor')) { $interests['Sponsorship'] = true; }
        }
        foreach ($p['leads'] as $l) {
            if ((int)($l['exhibitor_intent'] ?? 0) === 1) { $interests['Exhibiting'] = true; }
        }
        // Exhibiting outranks visiting in the order shown.
        $order = ['Exhibiting', 'Sponsorship', 'Conference', 'Partnership', 'Media', 'Visiting'];
        $interestList = array_values(array_filter($order, static fn($i) => isset($interests[$i])));

        // WhatsApp, in one phrase.
        $waStatus = 'Not contacted';
        $phone = $p['phones'][0] ?? null;
        if ($phone !== null) {
            $counts = db()->one(
                "SELECT SUM(status = 'sent') AS sent, SUM(status = 'held') AS held,
                        SUM(status IN ('scheduled','sending')) AS waiting, SUM(status = 'failed') AS failed
                   FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 IN (" . self::marks($p['phones']) . ")",
                array_merge([$agentId], $p['phones'])
            ) ?? [];
            $replied = (int)db()->value(
                'SELECT COUNT(*) FROM whatsapp_inbox WHERE agent_id = ? AND phone_e164 IN (' . self::marks($p['phones']) . ')',
                array_merge([$agentId], $p['phones'])
            );
            $paused = false;
            foreach ($wa as $c) {
                if (!empty($c['automation_paused_at'])) { $paused = true; }
            }
            $optedOut = false;
            foreach ($p['registrations'] as $r) {
                if ((string)$r['whatsapp_state'] === 'opted_out') { $optedOut = true; }
            }
            $waStatus = match (true) {
                $optedOut                   => 'Asked us to stop',
                $paused                     => 'With a person from the team',
                $replied > 0                => 'In conversation (' . $replied . ' message' . ($replied === 1 ? '' : 's') . ' from them)',
                (int)($counts['sent'] ?? 0) > 0 => 'Messaged, no reply yet',
                (int)($counts['held'] ?? 0) > 0 => 'First message waiting for approval',
                (int)($counts['waiting'] ?? 0) > 0 => 'First message about to go',
                (int)($counts['failed'] ?? 0) > 0 => 'Message could not be sent',
                $reg !== null               => RegistrationIntake::stateLabel((string)$reg['whatsapp_state']),
                default                     => 'Not contacted',
            };
        }

        // Research: the latest finished one for any of their conversations
        // or their company.
        $research = null;
        if ($p['conversations'] || $p['leads']) {
            $convIds = array_map(static fn($c) => (int)$c['id'], $p['conversations']);
            $companyIds = array_values(array_filter(array_merge(
                array_map(static fn($c) => (int)($c['company_id'] ?? 0), $p['conversations']),
                array_map(static fn($l) => (int)($l['company_id'] ?? 0), $p['leads']),
                array_map(static fn($r) => (int)($r['company_id'] ?? 0), $p['registrations'])
            )));
            $research = self::latestResearch($agentId, $convIds, $companyIds);
        }

        // Does a person need to act?
        $needsPerson = null;
        foreach ($wa as $c) {
            if (!empty($c['automation_paused_at'])) {
                $needsPerson = 'Taken over on WhatsApp: ' . (string)($c['automation_paused_reason'] ?? '');
            }
        }
        if ($needsPerson === null && $lead !== null && (int)$lead['handoff_required'] === 1
            && !in_array((string)$lead['status'], ['converted', 'not_interested', 'closed', 'spam'], true)) {
            $needsPerson = 'Passed to sales: ' . (string)($lead['handoff_reason'] ?: 'during the conversation');
        }

        // May they be contacted?
        $blocked = null;
        foreach ($p['phones'] as $ph) {
            $blocked ??= WhatsAppEngine::blockedReason($agentId, $ph, null, null);
        }
        foreach ($p['emails'] as $em) {
            $blocked ??= WhatsAppEngine::blockedReason($agentId, null, $em, null);
        }

        return [
            'name'      => $pick((string)($lead['name'] ?? ''), (string)($reg['name'] ?? '')),
            'company'   => $pick((string)($lead['company'] ?? ''), (string)($reg['company_name'] ?? '')),
            'job'       => (string)($reg['designation'] ?? ''),
            'email'     => $pick((string)($lead['email'] ?? ''), (string)($reg['email'] ?? '')),
            'phone'     => $pick((string)($p['phones'][0] ?? ''), (string)($lead['phone'] ?? '')),
            'country'   => $pick((string)($lead['company_country'] ?? ''), (string)($lead['country'] ?? ''), (string)($reg['country'] ?? '')),
            'registration' => $reg === null ? 'Not registered'
                : trim(((string)($reg['reg_type'] ?? '') !== '' ? (string)$reg['reg_type'] . ', ' : '')
                    . 'registered ' . substr((string)($reg['registered_at'] ?? $reg['imported_at'] ?? ''), 0, 10))
                  . (count($p['registrations']) > 1 ? ' (' . count($p['registrations']) . ' registrations)' : ''),
            'website'   => $web ? count($web) . ' conversation' . (count($web) === 1 ? '' : 's') . ', last '
                                  . substr((string)end($web)['last_activity_at'], 0, 10)
                                : 'No website chat',
            'whatsapp'  => $waStatus,
            'interests' => $interestList,
            'lead'      => $lead,
            'research'  => $research,
            'needs_person' => $needsPerson,
            'blocked'   => $blocked,
        ];
    }

    /** The lead the team works from: the one others were merged into, else the newest. */
    private static function primaryLead(array $leads): array
    {
        foreach (array_reverse($leads) as $l) {
            if (empty($l['merged_into_id'])) {
                return $l;
            }
        }
        return end($leads);
    }

    private static function latestResearch(int $agentId, array $convIds, array $companyIds): ?array
    {
        if (!Migrations::tableExists('company_research')) {
            return null;
        }
        $where = [];
        $params = [$agentId];
        if ($convIds) {
            $where[] = 'conversation_id IN (' . self::marks($convIds) . ')';
            $params = array_merge($params, $convIds);
        }
        if ($companyIds) {
            $where[] = 'company_id IN (' . self::marks($companyIds) . ')';
            $params = array_merge($params, $companyIds);
        }
        if (!$where) {
            return null;
        }
        return db()->one(
            "SELECT * FROM company_research WHERE agent_id = ? AND status = 'done' AND (" . implode(' OR ', $where) . ')
              ORDER BY id DESC LIMIT 1',
            $params
        );
    }

    // -----------------------------------------------------------------
    // What happened, in order
    // -----------------------------------------------------------------

    /**
     * One history across every channel, oldest first, each entry saying who
     * acted: the visitor, the AI, someone on the team, or the platform
     * itself.
     *
     * @return array<int,array{at:string,who:string,channel:string,what:string,text:string,link:?string}>
     */
    public static function timeline(array $agent, array $p, int $limit = 400): array
    {
        $agentId = (int)$agent['id'];
        $events  = [];
        $add = static function (?string $at, string $who, string $channel, string $what, string $text = '', ?string $link = null) use (&$events): void {
            if ($at === null || $at === '') {
                return;
            }
            $events[] = ['at' => $at, 'who' => $who, 'channel' => $channel, 'what' => $what,
                         'text' => mb_substr(trim($text), 0, 600), 'link' => $link];
        };

        foreach ($p['registrations'] as $r) {
            $bits = array_filter([
                (string)($r['reg_type'] ?? ''),
                trim((string)($r['designation'] ?? '') . ((string)($r['company_name'] ?? '') !== '' ? ' at ' . $r['company_name'] : '')),
                (string)($r['interested_in'] ?? '') !== '' ? 'Interested in: ' . $r['interested_in'] : '',
            ]);
            $add((string)($r['registered_at'] ?? $r['imported_at']), 'visitor', 'Registration', 'Registered', implode(' · ', $bits));
            $add((string)$r['imported_at'], 'system', 'Registration', 'Collected by the platform',
                'WhatsApp: ' . RegistrationIntake::stateLabel((string)$r['whatsapp_state']));
        }

        // Website chats, message by message.
        foreach ($p['conversations'] as $c) {
            if ((string)$c['channel'] === 'whatsapp') {
                // Only the platform's own notes: the messages themselves come
                // from the WhatsApp records below, which also say whether
                // each one actually went.
                foreach (db()->all("SELECT * FROM messages WHERE conversation_id = ? AND role = 'system' ORDER BY sequence_number", [(int)$c['id']]) as $m) {
                    $add((string)$m['created_at'], 'system', 'WhatsApp', 'Note', (string)$m['content']);
                }
                continue;
            }
            $link = url('admin/conversation.php?id=' . (int)$c['id']);
            $add((string)$c['created_at'], 'visitor', 'Website chat', 'Opened the chat',
                (string)($c['page_url'] ?? '') !== '' ? 'On ' . $c['page_url'] : '', $link);
            foreach (db()->all('SELECT * FROM messages WHERE conversation_id = ? ORDER BY sequence_number', [(int)$c['id']]) as $m) {
                $role = (string)$m['role'];
                $add((string)$m['created_at'],
                    $role === 'visitor' ? 'visitor' : ($role === 'agent' ? 'ai' : 'system'),
                    'Website chat',
                    $role === 'visitor' ? 'Wrote' : ($role === 'agent' ? (!empty($m['is_fallback']) ? 'Standard reply' : 'Replied') : 'Note'),
                    (string)$m['content'], $link);
            }
        }

        // WhatsApp, both ways.
        if ($p['phones']) {
            $in = self::marks($p['phones']);
            foreach (db()->all(
                'SELECT * FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 IN (' . $in . ') ORDER BY id',
                array_merge([$agentId], $p['phones'])
            ) as $o) {
                $kind = WhatsAppOutbox::kindLabel((string)$o['kind']);
                $who  = trim((string)($o['sent_by'] ?? '')) !== '' ? 'team' : 'ai';
                $byTeam = $who === 'team' ? ' by ' . $o['sent_by'] : '';
                switch ((string)$o['status']) {
                    case 'sent':
                        $add((string)$o['sent_at'], $who, 'WhatsApp', $kind . ' sent' . $byTeam, (string)$o['body']);
                        break;
                    case 'failed':
                        $add((string)$o['created_at'], 'system', 'WhatsApp', $kind . ' could not be sent',
                            (string)($o['last_error'] ?? ''));
                        break;
                    case 'cancelled':
                        $add((string)$o['created_at'], 'system', 'WhatsApp',
                            (string)$o['body'] === WhatsAppFollowUp::DECLINED_BODY ? 'No follow-up written' : $kind . ' stopped',
                            (string)($o['last_error'] ?? ''));
                        break;
                    case 'held':
                        $add((string)$o['created_at'], $who, 'WhatsApp', $kind . ' waiting for approval', (string)$o['body']);
                        break;
                    default:
                        $add((string)$o['created_at'], $who, 'WhatsApp', $kind . ' waiting to go', (string)$o['body']);
                }
            }
            foreach (db()->all(
                'SELECT * FROM whatsapp_inbox WHERE agent_id = ? AND phone_e164 IN (' . $in . ') ORDER BY id',
                array_merge([$agentId], $p['phones'])
            ) as $i) {
                $add((string)$i['received_at'], 'visitor', 'WhatsApp', 'Wrote', (string)($i['body'] ?? ''));
            }
        }

        // Research of their company.
        $convIds = array_map(static fn($c) => (int)$c['id'], $p['conversations']);
        $researchIds = [];
        if ($convIds && Migrations::tableExists('company_research')) {
            foreach (db()->all(
                'SELECT * FROM company_research WHERE agent_id = ? AND conversation_id IN (' . self::marks($convIds) . ') ORDER BY id',
                array_merge([$agentId], $convIds)
            ) as $r) {
                $researchIds[] = (int)$r['id'];
                $link = url('admin/research.php?view=' . (int)$r['id']);
                if ((string)$r['status'] === 'done') {
                    $add((string)$r['finished_at'], 'ai', 'Research', 'Company researched',
                        (string)$r['company_name'] . ': ' . (int)$r['score'] . '/100, ' . (string)$r['priority'], $link);
                } elseif ((string)$r['status'] === 'failed') {
                    $add((string)($r['finished_at'] ?? $r['created_at']), 'system', 'Research', 'Research failed',
                        (string)($r['error'] ?? ''), $link);
                } else {
                    $add((string)$r['created_at'], 'system', 'Research', 'Research requested', (string)$r['company_name'], $link);
                }
            }
        }

        // What people and the platform did to their records.
        $entities = [
            'lead'             => array_map(static fn($l) => (int)$l['id'], $p['leads']),
            'conversation'     => $convIds,
            'registration'     => array_map(static fn($r) => (int)$r['id'], $p['registrations']),
        ];
        if ($p['phones']) {
            $entities['whatsapp_outbox'] = array_map('intval', array_column(db()->all(
                'SELECT id FROM whatsapp_outbox WHERE agent_id = ? AND phone_e164 IN (' . self::marks($p['phones']) . ')',
                array_merge([$agentId], $p['phones'])
            ), 'id'));
        }
        foreach ($entities as $type => $ids) {
            if (!$ids) {
                continue;
            }
            foreach (db()->all(
                'SELECT * FROM audit_log WHERE entity_type = ? AND entity_id IN (' . self::marks($ids) . ') ORDER BY id LIMIT 300',
                array_merge([$type], array_map('strval', $ids))
            ) as $a) {
                // Already shown as a message, or too routine to be news.
                if (in_array((string)$a['event'], ['conversation_started', 'whatsapp_staff_reply', 'lead_package_interest'], true)) {
                    continue;
                }
                $who = !empty($a['admin_user_id']) || !in_array((string)$a['actor'], ['system', ''], true) ? 'team' : 'system';
                $text = (string)($a['detail'] ?? '');
                if ((string)($a['field'] ?? '') !== '') {
                    $old = (string)$a['old_value'];
                    $new = (string)$a['new_value'];
                    if ((string)$a['field'] === 'status') {
                        $old = LeadManager::STATUS_LABELS[$old] ?? $old;
                        $new = LeadManager::STATUS_LABELS[$new] ?? $new;
                    }
                    $text = trim(self::fieldLabel((string)$a['field']) . ': ' . mb_substr($old, 0, 60)
                        . ' → ' . mb_substr($new, 0, 60));
                }
                $add((string)$a['created_at'], $who, self::channelFor($type), self::eventLabel((string)$a['event'])
                    . ($who === 'team' && (string)$a['actor'] !== '' ? ' (' . $a['actor'] . ')' : ''), $text);
            }
        }

        // Within the same second, what the visitor said comes before the
        // answer to it.
        $rank = ['visitor' => 0, 'ai' => 1, 'team' => 1, 'system' => 2];
        usort($events, static fn($x, $y) => [$x['at'], $rank[$x['who']]] <=> [$y['at'], $rank[$y['who']]]);
        return array_slice($events, -$limit);
    }

    private static function channelFor(string $entityType): string
    {
        return match ($entityType) {
            'lead'            => 'Lead',
            'registration'    => 'Registration',
            'whatsapp_outbox' => 'WhatsApp',
            default           => 'Conversation',
        };
    }

    private static function fieldLabel(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    /** Audit event names, in words the team uses. */
    public static function eventLabel(string $event): string
    {
        return match ($event) {
            'lead_created'              => 'Lead created',
            'lead_handoff'              => 'Passed to sales',
            'lead_status_changed'       => 'Lead status changed',
            'lead_note_updated'         => 'Internal note edited',
            'lead_merged'               => 'Lead merged',
            'lead_dedup_flagged'        => 'Flagged as a possible duplicate',
            'lead_dedup_dismissed'      => 'Marked as not a duplicate',
            'chat_intro'                => 'Answered the chat introduction',
            'pricing_blocked'           => 'A price was held back',
            'conversation_blocked'      => 'Conversation blocked',
            'whatsapp_handover'         => 'Handed to a person',
            'whatsapp_handback'         => 'Handed back to the assistant',
            'whatsapp_opt_out'          => 'Asked to stop WhatsApp messages',
            'whatsapp_message_approved' => 'Message approved',
            'whatsapp_messages_approved' => 'Messages approved',
            'whatsapp_message_cancelled' => 'Message cancelled',
            'whatsapp_message_edited'   => 'Message edited',
            'whatsapp_message_retried'  => 'Message tried again',
            'campaign_created'          => 'Campaign created',
            'campaign_updated'          => 'Campaign changed',
            'campaign_active'           => 'Campaign started',
            'campaign_paused'           => 'Campaign paused',
            'campaign_finished'         => 'Campaign finished',
            'campaign_searched'         => 'Searched for companies',
            'prospect_state'            => 'Prospect moved on',
            'outreach_drafted'          => 'Email written',
            'outreach_edited'           => 'Email edited',
            'outreach_approved'         => 'Email approved',
            'outreach_sent'             => 'Email sent',
            'outreach_failed'           => 'Email could not be sent',
            'outreach_uncertain'        => 'Email may or may not have arrived',
            'outreach_unsubscribed'     => 'Unsubscribed',
            'research_requested'        => 'Research asked for',
            'research_done'             => 'Company researched',
            'mail_profile_tested'       => 'Test email sent',
            'mail_password_changed'     => 'Mail server password changed',
            default                     => ucfirst(str_replace('_', ' ', $event)),
        };
    }

    // -----------------------------------------------------------------
    // Finding someone
    // -----------------------------------------------------------------

    /**
     * Search by name, company, email or phone across leads and
     * registrations. Each result carries how to open their page.
     *
     * @return array<int,array{name:string,company:string,contact:string,source:string,seed:string,at:string}>
     */
    public static function search(int $agentId, string $q, int $limit = 40): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like   = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $byPhone = strlen($digits) >= 6 ? '%' . substr($digits, -10) . '%' : null;

        $out = [];
        foreach (db()->all(
            "SELECT id, name, company, email, phone, created_at FROM leads
              WHERE agent_id = ? AND (merged_into_id IS NULL OR merged_into_id = 0)
                AND (LOWER(name) LIKE ? OR LOWER(company) LIKE ? OR LOWER(email) LIKE ? OR LOWER(reference) LIKE ?"
                . ($byPhone !== null ? " OR REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?" : '') . ')
              ORDER BY id DESC LIMIT ' . $limit,
            array_merge([$agentId, $like, $like, $like, $like], $byPhone !== null ? [$byPhone] : [])
        ) as $l) {
            $out[] = ['name' => (string)$l['name'], 'company' => (string)$l['company'],
                      'contact' => trim((string)$l['email'] . ' ' . (string)$l['phone']),
                      'source' => 'Lead', 'seed' => 'lead=' . (int)$l['id'], 'at' => (string)$l['created_at']];
        }
        foreach (db()->all(
            "SELECT id, name, company_name, email, phone_e164, imported_at FROM registrations
              WHERE agent_id = ?
                AND (LOWER(name) LIKE ? OR LOWER(company_name) LIKE ? OR LOWER(email) LIKE ?"
                . ($byPhone !== null ? ' OR phone_e164 LIKE ?' : '') . ')
              ORDER BY id DESC LIMIT ' . $limit,
            array_merge([$agentId, $like, $like, $like], $byPhone !== null ? [$byPhone] : [])
        ) as $r) {
            $out[] = ['name' => (string)$r['name'], 'company' => (string)$r['company_name'],
                      'contact' => trim((string)$r['email'] . ' ' . (string)$r['phone_e164']),
                      'source' => 'Registration', 'seed' => 'registration=' . (int)$r['id'], 'at' => (string)$r['imported_at']];
        }
        return array_slice($out, 0, $limit * 2);
    }

    /** The address of someone's page, from any record of theirs. */
    public static function url(string $kind, int $id): string
    {
        return url('admin/person.php?' . $kind . '=' . $id);
    }

    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}
