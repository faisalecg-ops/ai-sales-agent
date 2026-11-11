<?php
declare(strict_types=1);

/**
 * The short introduction at the start of a website chat.
 *
 * Four things, and only four: first name, company, the country the company is
 * based in, and whether they are here to exhibit or to visit. This is the
 * "light qualification" the owner asked for — enough to treat the visitor
 * properly, not so much that it feels like a form to get through. It can
 * always be skipped.
 *
 * Visiting: they are thanked, and — only as far as their relevance to the
 * exhibition justifies — lightly offered exhibiting or the conference. No
 * lead is created unless they take it up.
 *
 * Exhibiting: their company is researched first (waiting at most a few
 * seconds), then the answer goes through the ordinary chat engine, so
 * pricing, country rules, knowledge and handoff behave exactly as they do for
 * a typed message, and the first thing an exhibitor hears is already about
 * their business.
 */
final class ChatIntro
{
    public const INTERESTS = ['exhibit', 'visit'];

    /**
     * @return array the same shape as a chat reply, or ['ok' => false, 'error' => code, 'message' => text]
     */
    public static function submit(array $agent, array $input): array
    {
        $conversation = ConversationStore::loadOrCreate($agent, $input);
        $agentId      = (int)$agent['id'];
        $leadState    = ConversationStore::leadState($conversation);

        // Once per conversation. A second submission — a double click, a
        // reloaded page — changes nothing and says so.
        if (!empty($leadState['intro_done'])) {
            return ['ok' => false, 'error' => 'already_done',
                    'message' => 'You have already told us about yourself in this chat.'];
        }

        $firstName = self::text($input['first_name'] ?? null, 60);
        $company   = self::text($input['company'] ?? null, 160);
        $country   = strtoupper(trim((string)($input['country'] ?? '')));
        $interest  = (string)($input['interest'] ?? '');

        if ($firstName === null) {
            return self::refuse('Please tell us your first name.');
        }
        if (!in_array($interest, self::INTERESTS, true)) {
            return self::refuse('Please choose whether you are interested in exhibiting or visiting.');
        }
        if ($country !== '' && CountryData::name($country) === null) {
            return self::refuse('Please choose your country from the list.');
        }
        // An exhibitor's company and country decide what they are offered
        // and at what price, so for them both are needed.
        if ($interest === 'exhibit' && ($company === null || $country === '')) {
            return self::refuse('To help with exhibiting, we need your company and the country it is based in.');
        }

        $countryName = $country !== '' ? CountryData::name($country) : null;

        // Rule 2: a country the visitor gives us comes before anything the IP
        // address suggests. Applied before any reply, so pricing is right
        // from the first message.
        if ($country !== '') {
            $conversation = ChatOrchestrator::applyFormCountry($agent, $conversation, $country);
        }

        $leadState['intro_done'] = true;
        $leadState['name']       = $firstName;
        if ($company !== null) {
            $leadState['company'] = $company;
        }
        if ($countryName !== null) {
            $leadState['company_country'] = $countryName;
        }

        ConversationStore::update($conversation, [
            'lead_state' => json_encode($leadState, JSON_UNESCAPED_UNICODE),
            'intent'     => $interest === 'exhibit' ? 'exhibitor' : 'attendee',
        ]);
        $conversation = db()->one('SELECT * FROM conversations WHERE id = ?', [(int)$conversation['id']]) ?? $conversation;

        Audit::log('chat_intro', $agentId, 'conversation', (int)$conversation['id'],
            ($interest === 'exhibit' ? 'Exhibiting' : 'Visiting') . ($countryName ? ', ' . $countryName : ''));

        return $interest === 'visit'
            ? self::visiting($agent, $conversation, $firstName, $company, $countryName)
            : self::exhibiting($agent, $conversation, $input, $firstName, $company, $countryName);
    }

    /**
     * Thanked — and then, depending on how relevant they are, a light
     * suggestion.
     *
     * Owner's rule: start from their relevance to the exhibition, then at
     * most a light try. Someone whose business fits the exhibition may be
     * asked, once, whether they would also consider exhibiting; someone the
     * conference suits may be told about it, if the exhibition has one on
     * record. Anyone else is simply thanked and offered help. This is the
     * same reasoning, and the same message writer with the same checks, as
     * the first WhatsApp message to a registered visitor.
     *
     * No sales lead is created here. If they take up the suggestion, the
     * ordinary conversation creates one, exactly as for anyone else.
     */
    private static function visiting(array $agent, array $conversation, string $firstName, ?string $company, ?string $country): array
    {
        ConversationStore::appendMessage($conversation, 'visitor', self::summary('visit', $firstName, $company, $country), [
            'response_status' => 'intro_form',
        ]);

        // What the chat told us, in the shape the relevance rules read.
        $person  = ['name' => $firstName, 'company_name' => (string)$company, 'country' => (string)$country];
        $profile = VisitorProfile::fromRegistration($person, $agent);
        $case    = ValueProposition::build($agent, $profile, []);

        $written = MessageComposer::compose($agent, $person, $profile, $case, 'chat');

        $text = empty($written['used_fallback'])
            ? (string)$written['message']
            : self::plainVisitingReply($agent, $firstName, $company, $profile, $case);

        ConversationStore::appendMessage($conversation, 'agent', $text, [
            'response_status' => 'intro_visiting',
            'is_fallback'     => empty($written['used_fallback']) ? 0 : 1,
            'stage_before'    => (string)$conversation['state'],
            'stage_after'     => ConversationState::GENERAL_VISITOR,
        ]);

        $leadState = ConversationStore::leadState($conversation);
        $leadState['visitor_track'] = (string)($profile['track'] ?? 'visitor');
        ConversationStore::update($conversation, [
            'state'      => ConversationState::GENERAL_VISITOR,
            'lead_state' => json_encode($leadState, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'ok'           => true,
            'blocks'       => Sanitizer::toBlocks($text, AgentResolver::approvedLinkDomains($agent)),
            'cards'        => [],
            'state'        => ConversationState::GENERAL_VISITOR,
            'handoff'      => false,
            'contact_form' => false,
            'session_id'   => (string)$conversation['session_id'],
        ];
    }

    /**
     * The approved thank-you, used when the AI is unavailable or its draft
     * broke a rule — with the same light suggestion, in fixed words.
     */
    private static function plainVisitingReply(array $agent, string $firstName, ?string $company, array $profile, array $case): string
    {
        $text = strtr(Fallbacks::get((int)$agent['id'], 'intro_visiting'), [
            '{name}'       => $firstName,
            '{exhibition}' => (string)$agent['name'],
        ]);

        if (!empty($case['may_pitch'])) {
            $text .= ' And if ' . ($company !== null ? $company : 'your company')
                   . ' would ever consider exhibiting, I would be glad to explain what is involved.';
        } elseif (!empty($case['conference'])) {
            $text .= ' There is also a conference programme alongside the exhibition — just ask if you would like the details.';
        }

        return $text;
    }

    /**
     * Through the ordinary chat engine, with what they told us.
     *
     * The card's answers are put to the assistant as the visitor's own words
     * — which they are — and also handed over as known details, so the lead
     * carries them exactly as typed rather than as the model read them.
     */
    private static function exhibiting(
        array $agent,
        array $conversation,
        array $input,
        string $firstName,
        ?string $company,
        ?string $country
    ): array {
        // Company research (owner's rule 1B; fix plan step 3, 11 Nov 2026)
        // runs here, before the reply, so the first thing an exhibitor hears
        // is already about their business. It waits at most the set number of
        // seconds (owner: 10 to 20) with nothing said — the widget shows only
        // the typing dots. If it is not finished by then, the reply goes out
        // as normal and research is finished straight after it, for the next
        // turn.
        $wait = CompanyResearch::firstReplyWait();
        if ($wait > 0) {
            $outcome = CompanyResearch::beforeFirstReply($agent, $conversation, $wait);
            app_log('research', 'before the first reply', [
                'agent' => (int)$agent['id'], 'conversation' => (int)$conversation['id'], 'outcome' => $outcome,
            ]);
        }

        $response = ChatOrchestrator::handle($agent, [
            'request_id'     => (string)($input['request_id'] ?? ('intro' . bin2hex(random_bytes(8)))),
            'session_id'     => (string)$conversation['session_id'],
            'session_secret' => (string)($input['session_secret'] ?? ''),
            'message'        => self::summary('exhibit', $firstName, $company, $country),
            'origin'         => $input['origin'] ?? null,
            'known_contact'  => array_filter([
                'name'            => $firstName,
                'company'         => $company,
                'company_country' => $country,
            ]),
        ]);

        return $response;
    }

    /**
     * What the visitor said, in their own words, for the conversation record
     * and — when exhibiting — for the assistant.
     */
    private static function summary(string $interest, string $firstName, ?string $company, ?string $country): string
    {
        $parts = ['My name is ' . $firstName . '.'];
        if ($company !== null) {
            $parts[] = 'I am from ' . $company . ($country !== null ? ', based in ' . $country : '') . '.';
        } elseif ($country !== null) {
            $parts[] = 'I am based in ' . $country . '.';
        }
        $parts[] = $interest === 'exhibit'
            ? 'I am interested in exhibiting.'
            : 'I am interested in visiting.';
        return implode(' ', $parts);
    }

    /** The country list for the card, most likely first. */
    public static function countryOptions(): array
    {
        $all = CountryData::COUNTRIES;
        asort($all, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [['PK', $all['PK'] ?? 'Pakistan']];
        foreach ($all as $code => $name) {
            if ($code !== 'PK') {
                $out[] = [$code, $name];
            }
        }
        return $out;
    }

    private static function refuse(string $message): array
    {
        return ['ok' => false, 'error' => 'invalid', 'message' => $message];
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
