<?php
declare(strict_types=1);

/**
 * Personal chat links (owner, 13 Nov 2026).
 *
 * "Let's discuss more — click here" in a prospect email, a quotation email or
 * a WhatsApp message to a registered visitor. The link opens the exhibition's
 * website with the chat already open: the first card is skipped, the person
 * is welcomed by name, and the conversation is recorded on what we already
 * know about them (the lead, the company, the registration).
 *
 * The link carries no personal data: only which email or message it came
 * from, which exhibition, and when it stops working, signed so it cannot be
 * changed or made up. It works until the exhibition ends (owner's choice);
 * after that, or when it has been tampered with, it simply opens the normal
 * chat with the first card. Each kind can be switched off per exhibition.
 */
final class PersonalLink
{
    public const KINDS = [
        'prospect' => 'Prospect campaign emails',
        'quote'    => 'Quotation emails',
        'whatsapp' => 'WhatsApp messages to registered visitors',
    ];
    public const PARAM = 'aisa_p';
    private const FALLBACK_DAYS = 90;

    public static function ready(): bool
    {
        static $ready = null;
        return $ready ??= Migrations::columnExists('agents', 'chat_link_quote');
    }

    /** Is this kind of link switched on for the exhibition? */
    public static function enabled(array $agent, string $kind): bool
    {
        return isset(self::KINDS[$kind]) && self::ready()
            && (int)($agent['chat_link_' . $kind] ?? 0) === 1
            && trim((string)($agent['website_url'] ?? '')) !== '';
    }

    /** When links made now stop working: the end of the exhibition's last day, in its time zone. */
    public static function expiry(array $agent, ?int $now = null): ?int
    {
        $now ??= time();
        $end = trim((string)($agent['event_ends_on'] ?? ''));
        if ($end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            try {
                $tz = new DateTimeZone((string)($agent['notify_timezone'] ?? '') ?: 'Asia/Karachi');
            } catch (Throwable) {
                $tz = new DateTimeZone('Asia/Karachi');
            }
            $at = (new DateTimeImmutable($end . ' 23:59:59', $tz))->getTimestamp();
            return $at > $now ? $at : null;          // the exhibition is over: no new links
        }
        return $now + self::FALLBACK_DAYS * 86400;   // no end date typed yet
    }

    /**
     * The link, or null when this kind is switched off, the exhibition has no
     * website address, or the exhibition is over.
     */
    public static function url(array $agent, string $kind, int $id, ?int $now = null): ?string
    {
        if (!self::enabled($agent, $kind) || $id <= 0) {
            return null;
        }
        $exp = self::expiry($agent, $now);
        if ($exp === null) {
            return null;
        }
        $payload = self::b64(json_encode(['k' => $kind, 'i' => $id, 'a' => (int)$agent['id'], 'e' => $exp]));
        $token = $payload . '.' . self::b64(substr(Crypto::sign($payload), 0, 16));

        $site = rtrim(trim((string)$agent['website_url']), '/') . '/';
        $tags = http_build_query([
            self::PARAM    => $token,
            'utm_source'   => $kind === 'whatsapp' ? 'whatsapp' : 'email',
            'utm_medium'   => 'personal-link',
            'utm_campaign' => $kind === 'quote' ? 'quotation' : $kind,
        ]);
        return $site . (str_contains($site, '?') ? '&' : '?') . $tags;
    }

    /** The line added to an email or message. */
    public static function line(string $url, bool $whatsapp = false): string
    {
        return $whatsapp
            ? "Let's discuss more here: " . $url
            : "Let's discuss more — click here: " . $url;
    }

    /** @return array{kind:string,id:int,agent:int,expires:int}|null when the token is genuine and still valid */
    public static function read(?string $token, ?int $now = null): ?array
    {
        $token = trim((string)$token);
        if ($token === '' || strlen($token) > 400 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        try {
            $expected = self::b64(substr(Crypto::sign($payload), 0, 16));
        } catch (Throwable) {
            return null;
        }
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode((string)self::unb64($payload), true);
        if (!is_array($data) || !isset(self::KINDS[$data['k'] ?? '']) || (int)($data['i'] ?? 0) <= 0
            || (int)($data['e'] ?? 0) < ($now ?? time())) {
            return null;
        }
        return ['kind' => (string)$data['k'], 'id' => (int)$data['i'], 'agent' => (int)($data['a'] ?? 0), 'expires' => (int)$data['e']];
    }

    /**
     * Open the chat as the person the link was sent to.
     *
     * @return array{text:string,language:string,kind:string}|null null when the link cannot be used: the normal chat opens
     */
    public static function open(array $agent, array $conversation, ?string $token): ?array
    {
        $link = self::read($token);
        if ($link === null || $link['agent'] !== (int)$agent['id'] || !self::enabled($agent, $link['kind'])) {
            return null;
        }
        $agentId = (int)$agent['id'];
        $who = self::person($agentId, $link);
        if ($who === null) {
            return null;
        }
        // Someone we have agreed not to contact, or in a blocked country: the normal chat.
        if (WhatsAppEngine::blockedReason($agentId, $who['phone'], $who['email'], $who['company_id'], $who['country']) !== null) {
            return null;
        }

        // What we knew from their last chat, so the assistant carries on from there.
        $earlier = $who['lead_id'] !== null
            ? db()->one('SELECT * FROM conversations WHERE lead_id = ? AND id <> ? ORDER BY id DESC LIMIT 1', [$who['lead_id'], (int)$conversation['id']])
            : null;
        $state = [];
        if ($earlier !== null) {
            $before = ConversationStore::leadState($earlier);
            foreach (['language', 'website', 'qual', 'offer_focus', 'offer_discussed', 'offers_mentioned', 'quote_last_ref',
                      'quotes_sent', 'prices_given', 'product_category', 'known_products'] as $k) {
                if (array_key_exists($k, $before)) {
                    $state[$k] = $before[$k];
                }
            }
        }
        $first = trim(explode(' ', trim($who['name']))[0] ?? '');
        $state = array_merge($state, array_filter([
            'name'            => $who['name'] !== '' ? $who['name'] : null,
            'company'         => $who['company'] !== '' ? $who['company'] : null,
            'company_country' => $who['country'] !== '' ? $who['country'] : null,
            'email'           => $who['email'],
            'website'         => $state['website'] ?? $who['website'],
        ], static fn($v) => $v !== null && $v !== ''));
        $state['intro_done']    = true;
        $state['personal_link'] = $link['kind'];
        $state['country_from']  = $link['kind'] === 'prospect' ? 'their company record' : 'what they told us before';

        $fields = [
            'lead_state' => json_encode($state, JSON_UNESCAPED_UNICODE),
            'intent'     => $link['kind'] === 'whatsapp' ? (string)($earlier['intent'] ?? 'attendee') : 'exhibitor',
        ];
        if ($who['company_id'] !== null && Migrations::columnExists('conversations', 'company_id')) {
            $fields['company_id'] = $who['company_id'];
        }
        if ($earlier !== null && trim((string)($earlier['summary'] ?? '')) !== '') {
            $fields['summary'] = (string)$earlier['summary'];
        }
        // The country on their record: what they told us (a quotation, their
        // registration form) or, for a prospect, their company's own website.
        $resolved = GeoClassifier::resolveDisclosed($who['country'] !== '' ? $who['country'] : null);
        if ($resolved['code'] !== null && empty($resolved['ambiguous'])) {
            $fields['pricing_country'] = GeoClassifier::classifyCode((string)$resolved['code']);
            $fields['country_source']  = GeoClassifier::SOURCE_DISCLOSED;
            $fields['disclosed_company_country'] = (string)$resolved['name'];
        }
        db()->update('conversations', $fields, 'id = ?', [(int)$conversation['id']]);
        $conversation = db()->one('SELECT * FROM conversations WHERE id = ?', [(int)$conversation['id']]) ?? $conversation;

        // The lead: theirs already, or a new one for a prospect who has come to talk.
        if ($who['lead_id'] !== null) {
            db()->update('conversations', ['lead_id' => $who['lead_id']], 'id = ?', [(int)$conversation['id']]);
            $conversation['lead_id'] = $who['lead_id'];
        } else {
            $lead = LeadManager::ensureLead($agent, $conversation);
            LeadManager::applyFields($agent, $lead, array_filter([
                'name' => $who['name'], 'company' => $who['company'], 'email' => $who['email'],
                'company_country' => $who['country'], 'website' => $who['website'],
            ], static fn($v) => $v !== null && $v !== ''), 'personal_link');
            $conversation['lead_id'] = (int)$lead['id'];
            if ($link['kind'] === 'whatsapp') {
                db()->update('registrations', ['lead_id' => (int)$lead['id']], 'id = ? AND agent_id = ? AND lead_id IS NULL', [$link['id'], $agentId]);
            }
        }

        $lang = Lang::of($state);
        $text = Lang::t($lang, 'link_' . $link['kind'], [
            'name' => $first !== '' ? $first : '', 'number' => $who['context'], 'exhibition' => (string)$agent['name'],
        ]);
        $text = trim(preg_replace('/\s+([,.])/u', '$1', $text) ?? $text);

        ConversationStore::appendMessage($conversation, 'system',
            'Opened their personal chat link from ' . self::KINDS[$link['kind']] . ($who['context'] !== '' ? ' (' . $who['context'] . ')' : '') . '.',
            ['response_status' => 'personal_link']);
        ConversationStore::appendMessage($conversation, 'agent', $text, ['response_status' => 'personal_link']);
        Audit::log('personal_link_opened', $agentId, 'conversation', (int)$conversation['id'],
            self::KINDS[$link['kind']] . ': ' . $who['name'] . ($who['company'] !== '' ? ', ' . $who['company'] : '')
            . ($who['context'] !== '' ? ' (' . $who['context'] . ')' : ''));

        return ['text' => $text, 'language' => $lang, 'kind' => $link['kind']];
    }

    /**
     * Who the link was sent to, from the email or message it was in.
     *
     * @return array{name:string,company:string,country:string,email:?string,phone:?string,website:?string,company_id:?int,lead_id:?int,context:string}|null
     */
    private static function person(int $agentId, array $link): ?array
    {
        $blank = ['name' => '', 'company' => '', 'country' => '', 'email' => null, 'phone' => null, 'website' => null,
                  'company_id' => null, 'lead_id' => null, 'context' => ''];
        if ($link['kind'] === 'quote') {
            if (!Quotation::ready()) {
                return null;
            }
            $q = db()->one('SELECT * FROM quotations WHERE id = ? AND agent_id = ?', [$link['id'], $agentId]);
            if ($q === null) {
                return null;
            }
            $lead = !empty($q['lead_id']) ? db()->one('SELECT * FROM leads WHERE id = ? AND agent_id = ?', [(int)$q['lead_id'], $agentId]) : null;
            return array_merge($blank, [
                'name' => (string)$q['to_name'], 'company' => (string)$q['to_company'], 'country' => (string)$q['to_country'],
                'email' => (string)$q['to_email'], 'website' => $lead['website'] ?? null,
                'lead_id' => $lead !== null ? (int)$lead['id'] : null, 'context' => (string)$q['number'],
            ]);
        }
        if ($link['kind'] === 'prospect') {
            $o = db()->one('SELECT * FROM outreach_emails WHERE id = ? AND agent_id = ?', [$link['id'], $agentId]);
            if ($o === null) {
                return null;
            }
            $c = db()->one('SELECT * FROM companies WHERE id = ? AND agent_id = ?', [(int)$o['company_id'], $agentId]);
            $lead = db()->one('SELECT * FROM leads WHERE agent_id = ? AND email = ? ORDER BY id DESC LIMIT 1', [$agentId, (string)$o['to_email']]);
            return array_merge($blank, [
                'name' => (string)($o['to_name'] ?? ''), 'company' => (string)($c['name'] ?? ''), 'country' => (string)($c['country'] ?? ''),
                'email' => (string)$o['to_email'], 'website' => !empty($c['domain']) ? (string)$c['domain'] : null,
                'company_id' => $c !== null ? (int)$c['id'] : null, 'lead_id' => $lead !== null ? (int)$lead['id'] : null,
            ]);
        }
        $r = db()->one('SELECT * FROM registrations WHERE id = ? AND agent_id = ?', [$link['id'], $agentId]);
        if ($r === null) {
            return null;
        }
        return array_merge($blank, [
            'name' => (string)($r['name'] ?? ''), 'company' => (string)($r['company_name'] ?? ''), 'country' => (string)($r['country'] ?? ''),
            'email' => $r['email'] ?? null, 'phone' => $r['phone_e164'] ?? null,
            'company_id' => !empty($r['company_id']) ? (int)$r['company_id'] : null,
            'lead_id' => !empty($r['lead_id']) ? (int)$r['lead_id'] : null,
        ]);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
