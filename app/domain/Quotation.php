<?php
declare(strict_types=1);

/**
 * Quotations (guided selling plan, Part 3; owner approved 12 Nov 2026).
 *
 * A one-page PDF, emailed to the visitor through the exhibition's own mail
 * account, when they ask the chat for a quotation or when a member of staff
 * makes one from the lead page.
 *
 * The owner's decisions:
 *   - sent automatically by the chat;
 *   - never for a company in Pakistan, and never when the country is not
 *     known from the visitor's own words;
 *   - no payment details (they come later, on the invoice);
 *   - valid for 30 days (set per exhibition);
 *   - no BCC: the team's notification email carries the same PDF;
 *   - the organiser's logo in the footer.
 *
 * Every figure comes from PricingEngine and the live price list. Nothing the
 * AI writes reaches the PDF: the page is filled from the price record, the
 * lead record and the sales rep record.
 */
final class Quotation
{
    public const MAX_PER_CONVERSATION = 3;
    /** Options one quotation may hold (transcript review, 14 Nov 2026: "give all 4 quotations"). */
    public const MAX_OPTIONS = 4;
    public const MAX_PER_EMAIL_PER_DAY = 5;
    private const MAX_SEND_TRIES = 4;

    public const DEFAULT_TERMS =
        "This quotation is an offer. A stand is booked only after the signed contract and payment.\n"
      . "An invoice with payment details follows after your confirmation.\n"
      . "Stand location is confirmed by the organiser at booking.\n"
      . "Valid for {days} days. Rates apply to this edition only.";

    public const DEFAULT_FOOTER = 'Ecommerce Gateway Pakistan (Pvt) Ltd';

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public static function ready(): bool
    {
        static $ready = null;
        return $ready ??= Migrations::tableExists('quotations');
    }

    /** @return array{enabled:bool,prefix:string,days:int,terms:string,logo:?string,footer:string} */
    public static function settings(array $agent): array
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($agent['quote_prefix'] ?? '')) ?? '');
        if ($prefix === '') {
            // "Pharma Asia" in 2027 becomes PA27.
            preg_match_all('/\b[A-Za-z]/', (string)($agent['name'] ?? 'Q'), $m);
            $prefix = strtoupper(implode('', array_slice($m[0], 0, 3)));
            $year = substr((string)($agent['event_starts_on'] ?? ''), 2, 2);
            $prefix .= ctype_digit($year) ? $year : '';
        }
        $days = (int)($agent['quote_valid_days'] ?? 30);
        $logo = trim((string)($agent['quote_logo'] ?? ''));
        $terms = trim((string)($agent['quote_terms'] ?? ''));
        return [
            'enabled' => (int)($agent['quote_enabled'] ?? 0) === 1,
            'prefix'  => mb_substr($prefix !== '' ? $prefix : 'Q', 0, 12),
            'days'    => $days >= 1 && $days <= 365 ? $days : 30,
            'terms'   => $terms !== '' ? $terms : self::DEFAULT_TERMS,
            'logo'    => $logo !== '' && is_file(self::logoDir() . '/' . basename($logo)) ? self::logoDir() . '/' . basename($logo) : null,
            'footer'  => trim((string)($agent['quote_footer'] ?? '')) ?: self::DEFAULT_FOOTER,
        ];
    }

    public static function logoDir(): string
    {
        return STORAGE_PATH . '/uploads/quotation-logos';
    }

    /** The organiser's logo for the footer, shipped with the platform. */
    public static function organiserLogo(): ?string
    {
        $path = APP_ROOT . '/app/assets/ecommerce-gateway-logo.png';
        return is_file($path) ? $path : null;
    }

    /** Why the chat cannot send quotations for this exhibition, in plain words; null when it can. */
    public static function whyNot(array $agent): ?string
    {
        if (!self::ready()) {
            return 'The database upgrade for quotations has not been run yet.';
        }
        if (!self::settings($agent)['enabled']) {
            return 'Quotations are switched off for this exhibition.';
        }
        $mail = MailProfile::problem($agent);
        if ($mail !== null) {
            return 'This exhibition cannot send email yet: ' . $mail;
        }
        return null;
    }

    public static function available(array $agent): bool
    {
        return self::whyNot($agent) === null;
    }

    // -----------------------------------------------------------------
    // What the visitor asked
    // -----------------------------------------------------------------

    /** Does this message ask for a quotation in writing? */
    public static function wants(string $message): bool
    {
        if (Lang::has($message, 'quote')) {
            return true;            // the same request in the chat's other languages
        }
        return (bool)preg_match(
            '/\b(?:quotations?|quote|qoutations?|quatations?|proposals?|pro-?\s?forma|written\s+offer|offer\s+in\s+writing|'
            . '(?:send|e-?mail)\s+(?:me|us)\s+(?:the\s+|an?\s+|your\s+)?(?:offer|prices?|rates?|costs?|details)(?:\s+by\s+e-?mail)?)\b/i',
            $message
        ) && !preg_match('/\b(?:no|not|don.?t|do\s+not)\s+(?:need|want|send)\b[^.?!]{0,30}\b(?:quotation|quote|proposal)\b/i', $message);
    }

    /**
     * Take out of the assistant's reply any sentence that says a quotation
     * (or a proposal or request) has been sent, emailed or passed on, and,
     * when the chat cannot send quotations itself, any sentence promising to
     * send one (transcript review, 14 Nov 2026). The program adds the true
     * line itself, after the action. English wording; the prompt covers the
     * other languages.
     */
    public static function stripClaims(string $reply, bool $chatSends): string
    {
        $claim = '/\b(?:(?:is|are|has been|have been|was|were)\s+(?:now\s+)?(?:being\s+)?(?:sent|e-?mailed|emailed|forwarded|on\s+(?:its|their)\s+way)|'
            . 'I(?:\'ve|\s+have)\s+(?:just\s+)?(?:sent|e-?mailed|forwarded|passed|shared|submitted)|'
            . '(?:I|we)\s+(?:am|are)\s+(?:now\s+)?(?:sending|e-?mailing))\b[^.!?\n]*\b(?:quotation|quote|proposal|request|PDF)\b|'
            . '\b(?:quotation|quote|proposal|PDF)\b[^.!?\n]{0,80}\b(?:is|are|has been|have been)\s+(?:now\s+)?(?:being\s+)?(?:sent|e-?mailed|on\s+(?:its|their)\s+way)\b/i';
        $promise = '/\b(?:I(?:\'ll|\s+will|\s+can|\s+could|\s+am\s+going\s+to)|I\'m\s+going\s+to|let\s+me)\s+(?:now\s+)?(?:send|e-?mail|arrange|prepare|forward|issue)\b[^.!?\n]{0,90}\b(?:quotation|quote|proposal)\b|'
            . '\b(?:what|which)\s+(?:e-?mail\s+)?address\s+should\s+I\s+(?:send|e-?mail)\b/i';
        // Sentences, keeping the spaces and line breaks between them as they were.
        $parts = preg_split('/((?<=[.!?])[ \t]+(?=\S)|\n+)/u', $reply, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$reply];
        $out = '';
        $changed = false;
        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $part = $parts[$i];
            $sep  = $parts[$i + 1] ?? '';
            if ($part !== '' && (preg_match($claim, $part) || (!$chatSends && preg_match($promise, $part)))) {
                $changed = true;
                $out .= str_contains($sep, "\n") ? $sep : '';
                continue;
            }
            $out .= $part . $sep;
        }
        if (!$changed) {
            return $reply;
        }
        // May be empty: the caller then puts the program's own line in its place.
        return trim((string)preg_replace('/\n{3,}/', "\n\n", $out));
    }

    /** Does the assistant's reply offer one? */
    public static function offered(string $reply): bool
    {
        return (bool)preg_match('/\b(?:quotation|proposal)\b[^?]{0,120}\?/i', $reply);
    }

    public static function saysYes(string $message): bool
    {
        if (Lang::startsWith($message, 'yes')) {
            return true;
        }
        return (bool)preg_match(
            '/^\W*(?:yes|yeah|yep|sure|ok(?:ay)?|please(?:\s+do)?|go\s+ahead|that\s+would\s+be\s+(?:good|great|helpful)|sounds\s+good|'
            . 'yes\s+please|please\s+send(?:\s+it)?|send\s+it|please\s+(?:email|e-mail)\s+it|(?:email|e-mail)\s+it)\b/i',
            trim($message)
        );
    }

    // -----------------------------------------------------------------
    // Can it be made?
    // -----------------------------------------------------------------

    /** The live international price records a quotation may be made from. */
    public static function options(array $agent): array
    {
        $groups = GeoClassifier::regionGroupsFor(GeoClassifier::INTERNATIONAL);
        $agentId = (int)$agent['id'];
        $out = [];
        foreach (PricingEngine::stallPackages($agentId, $groups, 50) as $r) {
            $out[] = $r + ['_type' => PricingEngine::TYPE_STALL, '_name' => (string)$r['package_name']];
        }
        foreach (PricingEngine::sponsorshipPackages($agentId, $groups, 50) as $r) {
            $out[] = $r + ['_type' => PricingEngine::TYPE_SPONSORSHIP, '_name' => (string)$r['tier_name']];
        }
        // Only approved records: a price still waiting for review is never quoted in writing.
        return array_values(array_filter($out, static fn(array $r): bool => (string)($r['status'] ?? '') === 'approved'));
    }

    /**
     * Everything a quotation needs, or the one thing still missing.
     *
     * `missing` is one of: null (ready), 'pakistan', 'country', 'option',
     * 'size', 'size_unsellable', 'name', 'company', 'email', 'limit'.
     *
     * @param string[] $optionNames the option(s) in view, as named in the price list
     * @param ?string  $reference   "stall:12" or "sponsorship:3", when one record is already chosen
     * @return array{missing:?string,record:?array,sqm:?float,note:?string}
     */
    public static function assess(array $agent, array $conversation, ?array $lead, array $optionNames,
                                  ?string $reference, ?float $sqm): array
    {
        $none = ['missing' => null, 'record' => null, 'records' => [], 'sqm' => null, 'note' => null];

        $class = (string)($conversation['pricing_country'] ?? GeoClassifier::UNDETERMINED);
        if ($class === GeoClassifier::PAKISTAN) {
            return ['missing' => 'pakistan'] + $none;
        }
        $country = trim((string)($conversation['disclosed_company_country'] ?: ($conversation['disclosed_country'] ?? '')));
        if ($class !== GeoClassifier::INTERNATIONAL || $country === ''
            || (string)($conversation['country_source'] ?? '') !== GeoClassifier::SOURCE_DISCLOSED) {
            return ['missing' => 'country'] + $none;
        }
        if (BlockedCountries::blocksConversation($conversation)) {
            return ['missing' => 'pakistan'] + $none;      // treated the same: no quotation
        }

        // ---- The option(s) ----
        // One option, or several (up to four) in one quotation, so a visitor
        // who asks for "all four tiers" gets them together.
        $options = self::options($agent);
        $records = [];
        if ($reference !== null && preg_match('/^(stall|sponsorship):(\d+)$/', $reference, $m)) {
            foreach ($options as $o) {
                if ($o['_type'] === $m[1] && (int)$o['id'] === (int)$m[2]) {
                    $records[$o['_type'] . ':' . $o['id']] = $o;
                }
            }
        }
        if ($records === []) {
            foreach ($optionNames as $name) {
                foreach ($options as $o) {
                    if (strcasecmp(trim((string)$name), trim($o['_name'])) === 0) {
                        $records[$o['_type'] . ':' . $o['id']] = $o;
                    }
                }
            }
        }
        $records = array_slice(array_values($records), 0, self::MAX_OPTIONS);
        if ($records === []) {
            return ['missing' => 'option'] + $none;
        }

        // ---- A size that can be booked, for each stand priced by the square metre ----
        $useSqm = null;
        $kept = [];
        $sizeNote = null;
        foreach ($records as $record) {
            if ((string)($record['pricing_mode'] ?? 'fixed') !== 'per_sqm') {
                $kept[] = $record;
                continue;
            }
            if ($sqm === null || $sqm <= 0) {
                return ['missing' => 'size', 'record' => $record, 'records' => $records] + $none;
            }
            $check = PricingEngine::checkSize($record, $sqm);
            if ($check['ok']) {
                $kept[] = $record;
                $useSqm = $sqm;
            } else {
                $sizeNote ??= (string)($check['message'] ?? '');
            }
        }
        if ($kept === []) {
            return ['missing' => 'size_unsellable', 'record' => $records[0], 'records' => $records, 'note' => $sizeNote] + $none;
        }
        $records = $kept;
        $record = $records[0];

        // ---- Who it is for ----
        foreach (['name', 'company', 'email'] as $field) {
            $value = trim((string)($lead[$field] ?? ''));
            if ($value === '' || ($field === 'email' && Validator::email($value) === null)) {
                return ['missing' => $field, 'record' => $record, 'records' => $records, 'sqm' => $useSqm, 'note' => null];
            }
        }

        if (self::limitReason((int)$agent['id'], (int)($conversation['id'] ?? 0), (string)$lead['email']) !== null) {
            return ['missing' => 'limit', 'record' => $record, 'records' => $records, 'sqm' => $useSqm, 'note' => null];
        }

        return ['missing' => null, 'record' => $record, 'records' => $records, 'sqm' => $useSqm, 'note' => $sizeNote];
    }

    /**
     * A quotation made by hand from the lead page: may it be made?
     * The same rules as the chat, except that a member of staff vouches for
     * the country on the lead record. Returns the reason it may not, or null.
     */
    public static function staffProblem(array $agent, array $lead, ?array $record, ?float $sqm, string $email): ?string
    {
        if (!self::ready()) {
            return 'The database upgrade for quotations has not been run yet.';
        }
        $mail = MailProfile::problem($agent);
        if ($mail !== null) {
            return 'This exhibition cannot send email yet: ' . $mail;
        }
        $country = self::countryOf($lead, null);
        if ((string)($lead['pricing_country'] ?? '') === GeoClassifier::PAKISTAN
            || ($country !== '' && GeoClassifier::resolveDisclosed($country)['code'] === 'PK')) {
            return 'Quotations are not made for companies in Pakistan. The local sales representative gives them their prices.';
        }
        if ((string)($lead['pricing_country'] ?? '') !== GeoClassifier::INTERNATIONAL || $country === '') {
            return 'The company\'s country is not known yet. A quotation is only made once it is known and outside Pakistan.';
        }
        if (BlockedCountries::blocks($country)) {
            return 'This company is in a blocked country.';
        }
        if (trim((string)($lead['name'] ?? '')) === '' || trim((string)($lead['company'] ?? '')) === '') {
            return 'The lead needs a name and a company before a quotation can be made.';
        }
        if (Validator::email($email) === null) {
            return 'Enter the email address to send the quotation to.';
        }
        if ($record === null) {
            return 'Choose one stand option or sponsorship tier from the price list.';
        }
        if ((string)($record['pricing_mode'] ?? 'fixed') === 'per_sqm') {
            if ($sqm === null || $sqm <= 0) {
                return 'Enter the stand size in square metres.';
            }
            $check = PricingEngine::checkSize($record, $sqm);
            if (!$check['ok']) {
                return 'That size cannot be booked. ' . (string)($check['message'] ?? '');
            }
        }
        return null;
    }

    /**
     * The country a quotation is made out to: the company's country first,
     * then the person's, as they said it in the chat or as the lead records it.
     */
    public static function countryOf(array $lead, ?array $conversation): string
    {
        $tries = [
            $conversation['disclosed_company_country'] ?? '', $lead['company_country'] ?? '',
            $conversation['disclosed_country'] ?? '', $lead['country'] ?? '',
        ];
        if (!empty($lead['id'])) {
            $said = db()->one(
                "SELECT disclosed_company_country, disclosed_country FROM conversations
                  WHERE lead_id = ? AND country_source = 'visitor_disclosed' ORDER BY id DESC LIMIT 1",
                [(int)$lead['id']]
            );
            $tries[] = $said['disclosed_company_country'] ?? '';
            $tries[] = $said['disclosed_country'] ?? '';
        }
        foreach ($tries as $value) {
            if (trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return '';
    }

    /** Why no more quotations may go out right now, or null. */
    public static function limitReason(int $agentId, int $conversationId, string $email): ?string
    {
        if ($conversationId > 0 && (int)db()->value(
            "SELECT COUNT(*) FROM quotations WHERE agent_id = ? AND conversation_id = ? AND source = 'chat'",
            [$agentId, $conversationId]) >= self::MAX_PER_CONVERSATION) {
            return 'This conversation has already been sent ' . self::MAX_PER_CONVERSATION . ' quotations.';
        }
        if ((int)db()->value(
            "SELECT COUNT(*) FROM quotations WHERE to_email = ? AND source = 'chat' AND created_at >= ?",
            [strtolower(trim($email)), gmdate('Y-m-d H:i:s', time() - 86400)]) >= self::MAX_PER_EMAIL_PER_DAY) {
            return 'This email address has already been sent ' . self::MAX_PER_EMAIL_PER_DAY . ' quotations in the last day.';
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Making and sending
    // -----------------------------------------------------------------

    /**
     * Make the quotation, store its PDF and email it (used by staff, from the lead page).
     *
     * @param array $record a record from options()
     * @return array the quotations row, with `status` 'sent' or 'queued' (it will be tried again by cron)
     */
    public static function issue(array $agent, array $lead, ?array $conversation, array $record, ?float $sqm,
                                 string $source = 'chat', string $actor = 'assistant'): array
    {
        return self::dispatch($agent, self::prepare($agent, $lead, $conversation, $record, $sqm, $source, $actor));
    }

    /**
     * Email a prepared quotation to the visitor, and its copy to the team.
     * The chat calls this after the reply has been saved, so a slow mail
     * server never holds the conversation's record open.
     */
    public static function dispatch(array $agent, array $row): array
    {
        self::send($agent, $row);
        $row = db()->one('SELECT * FROM quotations WHERE id = ?', [(int)$row['id']]) ?? $row;
        if (!empty($row['lead_id'])) {
            $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$row['lead_id']]);
            if ($lead !== null) {
                // The team's copy: the usual lead notification, with the same PDF (no BCC).
                self::notifyTeam($agent, $lead, $row);
            }
        }
        return $row;
    }

    /**
     * Take a number, save the record and write the PDF. Nothing is emailed here.
     *
     * @param array $record a record from options()
     */
    public static function prepare(array $agent, array $lead, ?array $conversation, array $record, ?float $sqm,
                                   string $source = 'chat', string $actor = 'assistant', array $more = []): array
    {
        $agentId = (int)$agent['id'];
        $set     = self::settings($agent);
        $type    = (string)$record['_type'];

        // One block per option: its card (price, VAT, total) and everything it includes.
        $blocks = [];
        foreach (array_slice(array_merge([$record], $more), 0, self::MAX_OPTIONS) as $r) {
            $t = (string)$r['_type'];
            $useSqm = $t === PricingEngine::TYPE_STALL && (string)($r['pricing_mode'] ?? '') === 'per_sqm' ? $sqm : null;
            $c = PricingEngine::toCard($r, $t, $useSqm);
            // The page lists everything the option includes, not only what fits on a chat card.
            $full = PricingEngine::lines((string)($r[$t === PricingEngine::TYPE_STALL ? 'included_items' : 'benefits'] ?? ''));
            if ($full) {
                $c['items'] = array_slice($full, 0, 40);
            }
            $tot = (string)$c['price_text'];
            foreach ((array)$c['price_lines'] as $line) {
                if (!empty($line['emphasis'])) {
                    $tot = (string)$line['value'];
                }
            }
            $blocks[] = [
                'card'  => $c,
                'total' => $tot,
                'rate'  => $t === PricingEngine::TYPE_STALL && (string)($r['pricing_mode'] ?? '') === 'per_sqm'
                    ? PricingEngine::formatPrice((float)$r['rate_per_sqm'], strtoupper((string)$r['currency'])) . ' / sqm' : null,
            ];
        }
        // Lowest first, so the page reads like a price list.
        $amount = static fn(array $b): float => (float)preg_replace('/[^0-9.]/', '', str_replace(',', '', $b['total']));
        if (count($blocks) > 1) {
            usort($blocks, static fn(array $a, array $b): int => $amount($a) <=> $amount($b));
        }
        $card  = $blocks[0]['card'];
        $total = $blocks[0]['total'];
        $title = (string)$card['title'];
        if (count($blocks) > 1) {
            $title = implode(' / ', array_map(static fn(array $b): string => (string)$b['card']['title'], $blocks));
            $total = count($blocks) . ' options, ' . $blocks[0]['total'] . ' to ' . $blocks[count($blocks) - 1]['total'];
        }

        $rep = SalesReps::get($agentId, 'international');
        $tz  = self::zone($agent);
        $today = new DateTimeImmutable('now', $tz);
        $country = self::countryOf($lead, $conversation);

        $detail = [
            'event'    => self::eventLines($agent, $record),
            'to'       => ['name' => (string)$lead['name'], 'company' => (string)$lead['company'], 'country' => $country],
            'rep'      => SalesReps::isUsable($rep) ? $rep : null,
            'card'     => $card,
            'rate'     => $blocks[0]['rate'],
            // Several options in one quotation (14 Nov 2026); absent for one.
            'options'  => count($blocks) > 1 ? $blocks : null,
            'terms'    => str_replace('{days}', (string)$set['days'], $set['terms']),
            'footer'   => $set['footer'],
            'date'     => $today->format('j M Y'),
            'valid'    => $today->modify('+' . $set['days'] . ' days')->format('j M Y'),
        ];

        // The number: prefix-Q-0001, counted per exhibition. Two requests at
        // the same moment cannot take the same number (unique key, then retry).
        $row = null;
        for ($try = 0; $try < 5 && $row === null; $try++) {
            $seq = (int)db()->value('SELECT COALESCE(MAX(seq), 0) + 1 FROM quotations WHERE agent_id = ?', [$agentId]);
            $number = $set['prefix'] . '-Q-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            try {
                $id = db()->insert('quotations', [
                    'agent_id'        => $agentId,
                    'lead_id'         => (int)$lead['id'] ?: null,
                    'conversation_id' => $conversation !== null ? (int)$conversation['id'] : null,
                    'seq'             => $seq,
                    'number'          => $number,
                    'to_name'         => mb_substr((string)$lead['name'], 0, 160),
                    'to_company'      => mb_substr((string)$lead['company'], 0, 200),
                    'to_country'      => mb_substr($country, 0, 96),
                    'to_email'        => strtolower(trim((string)$lead['email'])),
                    'package_type'    => $type,
                    'package_id'      => (int)$record['id'],
                    'title'           => mb_substr($title, 0, 200),
                    'sqm'             => $card['sqm'] ?: null,
                    'total_text'      => mb_substr($total, 0, 64),
                    'detail'          => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'valid_until'     => $today->modify('+' . $set['days'] . ' days')->format('Y-m-d'),
                    'status'          => 'queued',
                    'source'          => $source === 'staff' ? 'staff' : 'chat',
                    'created_by'      => mb_substr($actor, 0, 64),
                ]);
                $row = db()->one('SELECT * FROM quotations WHERE id = ?', [$id]);
            } catch (Throwable $ex) {
                if (!str_contains($ex->getMessage(), 'Duplicate')) {
                    throw $ex;
                }
            }
        }
        if ($row === null) {
            throw new RuntimeException('Could not take a quotation number.');
        }

        // The PDF, kept outside the web folder.
        $pdf = self::render($agent, $row);
        $dir = STORAGE_PATH . '/quotations/' . $agentId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$row['number']) . '.pdf';
        if (@file_put_contents($file, $pdf) === false) {
            db()->update('quotations', ['status' => 'failed', 'last_error' => 'The storage folder is not writable.'], 'id = ?', [(int)$row['id']]);
            throw new RuntimeException('The quotation could not be saved. Check the storage folder is writable.');
        }
        db()->update('quotations', ['file_path' => 'quotations/' . $agentId . '/' . basename($file)], 'id = ?', [(int)$row['id']]);
        $row['file_path'] = 'quotations/' . $agentId . '/' . basename($file);

        Audit::log('quotation_made', $agentId, 'lead', (int)$lead['id'],
            $row['number'] . ' — ' . $row['title'] . ($row['sqm'] ? ', ' . (float)$row['sqm'] . ' sqm' : '') . ', ' . $total
            . ' — to ' . $row['to_email'] . ($source === 'staff' ? ' (by ' . $actor . ')' : ' (from the chat)'));

        return $row;
    }

    /**
     * What the assistant is told about a quotation request, for this reply.
     *
     * @param array{missing:?string,record:?array,sqm:?float,note:?string} $assess
     */
    public static function promptBlock(array $assess, array $optionNames = []): string
    {
        $records = (array)($assess['records'] ?? []);
        if ($records === [] && $assess['record'] !== null) {
            $records = [$assess['record']];
        }
        $names = array_map(static fn(array $r): string => '"' . $r['_name'] . '"', $records);
        $what = $names
            ? implode(', ', $names) . ($assess['sqm'] ? ' at ' . rtrim(rtrim(number_format((float)$assess['sqm'], 2), '0'), '.') . ' sqm' : '')
            : 'the option they choose';
        // Transcript review (14 Nov 2026): the assistant never says that
        // something has been sent. The program adds that line itself, and
        // only after the quotation has really been made.
        $head = "QUOTATION (this overrides the contact rule above)\n"
              . "The visitor wants a quotation in writing. The system makes the PDF itself, from the price list, and emails it. "
              . "One quotation can hold up to " . self::MAX_OPTIONS . " options. "
              . "You never write the quotation's figures, you never say a stand is booked or reserved, and you never say yourself "
              . "that a quotation has been or is being sent: the system adds that line under your reply only once it is really made. "
              . "If this message asks something else, answer that first, then deal with the quotation in one sentence.\n";
        $choices = $optionNames ? ' The options, by their exact names: ' . implode(', ', array_map(static fn($n) => '"' . $n . '"', $optionNames)) . '.' : '';
        return $head . match ($assess['missing']) {
            null => "Everything needed is known for " . $what . ". Do not ask for anything else, and do not confirm sending: the "
                  . "system's line under your reply does that.",
            'pakistan' => "No quotation can be sent from this chat for this company. Do not promise one. The sales representative, "
                  . "whose details are added to your reply, will help them.",
            'country' => "Still missing: the country their company is based in. Ask for that one thing only.",
            'option' => "Still missing: which option(s) the quotation is for. They may choose one, several, or all of a kind "
                  . "(up to " . self::MAX_OPTIONS . " in one quotation)." . $choices . " Ask which, as your only question.",
            'size' => "It is for " . $what . ". Still missing: the stand size in square metres. Ask for that one thing only.",
            'size_unsellable' => "It is for " . $what . ", but the size they named cannot be booked. "
                  . ($assess['note'] !== null && $assess['note'] !== '' ? $assess['note'] . ' ' : '')
                  . "Offer the nearest sizes and ask which one the quotation should be for. Ask for nothing else.",
            'name' => "It is for " . $what . ". Still missing: their name, for the quotation. Ask for that one thing only.",
            'company' => "It is for " . $what . ". Still missing: their company's name, for the quotation. Ask for that one thing only.",
            'email' => "It is for " . $what . ". Still missing: the email address the system will send it to. Ask for that one "
                  . "thing only. If they give it in this message, do not confirm anything: the system adds the line.",
            'limit' => "The system cannot email another quotation from this chat today (the limit for this chat or this "
                  . "address is reached). Do not promise one and do not say you will send it. The system passes the request "
                  . "to the sales team and adds a line saying so.",
            default => '',
        };
    }

    /** Email one quotation to the visitor, through the exhibition's own mail account. */
    public static function send(array $agent, array $row): bool
    {
        $file = self::path($row);
        if ($file === null) {
            db()->update('quotations', ['status' => 'failed', 'last_error' => 'The PDF file is missing.'], 'id = ?', [(int)$row['id']]);
            return false;
        }
        $problem = MailProfile::problem($agent);
        if ($problem !== null) {
            db()->update('quotations', ['attempts' => (int)$row['attempts'] + 1, 'last_error' => mb_substr($problem, 0, 400),
                'status' => (int)$row['attempts'] + 1 >= self::MAX_SEND_TRIES ? 'failed' : 'queued'], 'id = ?', [(int)$row['id']]);
            return false;
        }
        if (WhatsAppEngine::blockedReason((int)$agent['id'], null, (string)$row['to_email'], null, (string)$row['to_country']) !== null) {
            db()->update('quotations', ['status' => 'failed', 'last_error' => 'The address is on the do-not-contact list or in a blocked country.'],
                'id = ?', [(int)$row['id']]);
            return false;
        }

        $detail = (array)json_decode((string)$row['detail'], true);
        $event  = (string)($detail['event'][0] ?? $agent['name']);
        $first  = trim(explode(' ', trim((string)$row['to_name']))[0] ?? '');
        $what   = (string)$row['title'] . ($row['sqm'] ? ', ' . rtrim(rtrim(number_format((float)$row['sqm'], 2), '0'), '.') . ' sqm' : '');
        $rep    = (array)($detail['rep'] ?? []);

        $lines = [
            'Dear ' . ($first !== '' ? $first : 'Sir or Madam') . ',',
            '',
            'Thank you for your interest in ' . $event . '.',
            '',
            ...(!empty($detail['options'])
                ? array_merge(
                    ['Please find attached quotation ' . $row['number'] . ' for these options, each with its own total:'],
                    array_map(static fn(array $b): string => '- ' . (string)($b['card']['title'] ?? '') . ': ' . (string)($b['total'] ?? ''),
                        (array)$detail['options']),
                    ['The quotation is valid until ' . (string)($detail['valid'] ?? '') . '.'])
                : ['Please find attached quotation ' . $row['number'] . ' for: ' . $what . '.',
                   'Total: ' . $row['total_text'] . '. The quotation is valid until ' . (string)($detail['valid'] ?? '') . '.']),
            '',
            'This quotation is an offer; a stand is booked only after the signed contract and payment. '
            . 'To go ahead, or if you have any question, simply reply to this email.',
        ];
        if (!empty($rep['name'])) {
            $lines[] = '';
            $lines[] = 'Your contact: ' . $rep['name'] . (!empty($rep['designation']) ? ', ' . $rep['designation'] : '');
            foreach (['email' => 'Email', 'mobile' => 'Mobile', 'whatsapp' => 'WhatsApp', 'wechat' => 'WeChat'] as $k => $label) {
                if (!empty($rep[$k])) {
                    $lines[] = $label . ': ' . $rep[$k];
                }
            }
        }
        $chatLink = PersonalLink::url($agent, 'quote', (int)$row['id']);
        if ($chatLink !== null) {
            $lines[] = '';
            $lines[] = PersonalLink::line($chatLink);
        }
        $profile = MailProfile::forAgent($agent);
        $lines[] = '';
        $lines[] = 'Kind regards,';
        $lines[] = $profile['from_name'];
        $text = implode("\n", $lines);
        $html = '<div style="font:15px/1.55 Arial,Helvetica,sans-serif;color:#1a2233">'
              . implode('', array_map(static function (string $l) use ($chatLink): string {
                    if ($l === '') {
                        return '<div style="height:10px"></div>';
                    }
                    if ($chatLink !== null && $l === PersonalLink::line($chatLink)) {
                        return '<div><a href="' . e($chatLink) . '" style="color:#1E5AC8;font-weight:bold">Let\'s discuss more — click here</a></div>';
                    }
                    return '<div>' . e($l) . '</div>';
                }, $lines))
              . '</div>';

        $result = Mailer::sendWith($profile, (string)$row['to_email'],
            'Quotation ' . $row['number'] . ' — ' . $event, $text, $html,
            ['X-AISA-Quotation' => (string)$row['number']],
            [['name' => $row['number'] . '.pdf', 'type' => 'application/pdf', 'data' => (string)file_get_contents($file)]]);

        if ($result['ok']) {
            db()->update('quotations', ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null,
                'message_id' => mb_substr((string)($result['message_id'] ?? ''), 0, 255) ?: null, 'attempts' => (int)$row['attempts'] + 1],
                'id = ?', [(int)$row['id']]);
            return true;
        }
        $tries = (int)$row['attempts'] + 1;
        // Handed to the mail server before the failure: it may have arrived,
        // so it is not sent a second time.
        $final = !empty($result['uncertain']) || !empty($result['permanent']) || $tries >= self::MAX_SEND_TRIES;
        db()->update('quotations', ['attempts' => $tries, 'status' => $final ? 'failed' : 'queued',
            'last_error' => mb_substr((string)($result['error'] ?? 'unknown'), 0, 400)], 'id = ?', [(int)$row['id']]);
        app_log('quotation', 'email not sent', ['number' => $row['number'], 'error' => $result['error'] ?? null]);
        return false;
    }

    /** Cron: try again the quotations whose email did not go out. */
    public static function sendDue(int $limit = 10): int
    {
        if (!self::ready()) {
            return 0;
        }
        $sent = 0;
        $rows = db()->all("SELECT * FROM quotations WHERE status = 'queued' AND attempts < ? AND created_at < ? ORDER BY id ASC LIMIT " . max(1, min(50, $limit)),
            [self::MAX_SEND_TRIES, gmdate('Y-m-d H:i:s', time() - 60)]);
        foreach ($rows as $row) {
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$row['agent_id']]);
            if ($agent !== null && self::send($agent, $row)) {
                $sent++;
            }
        }
        return $sent;
    }

    private static function notifyTeam(array $agent, array $lead, array $row): void
    {
        $file = self::path($row);
        $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
        LeadManager::notifyNewLead($agent, $lead, 'quotation', (string)$row['number'],
            $file !== null ? ['path' => (string)$row['file_path'], 'name' => $row['number'] . '.pdf'] : null);
    }

    /** The PDF's place on disk, or null when it is not there. */
    public static function path(array $row): ?string
    {
        $rel = (string)($row['file_path'] ?? '');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $file = STORAGE_PATH . '/' . $rel;
        return is_file($file) ? $file : null;
    }

    /** @return array<int,array> newest first */
    public static function forLead(int $agentId, int $leadId): array
    {
        return self::ready()
            ? db()->all('SELECT * FROM quotations WHERE agent_id = ? AND lead_id = ? ORDER BY id DESC', [$agentId, $leadId])
            : [];
    }

    public static function statusLabel(array $row): string
    {
        return match ((string)$row['status']) {
            'sent'   => 'Emailed',
            'failed' => 'Not sent',
            default  => 'Waiting to be sent',
        };
    }

    private static function zone(array $agent): DateTimeZone
    {
        try {
            return new DateTimeZone((string)($agent['notify_timezone'] ?? '') ?: 'Asia/Karachi');
        } catch (Throwable) {
            return new DateTimeZone('Asia/Karachi');
        }
    }

    /** The exhibition's name, and its dates and venue, for the top of the page. */
    private static function eventLines(array $agent, array $record): array
    {
        $name = trim((string)($agent['name'] ?? ''));
        $second = '';
        $start = (string)($agent['event_starts_on'] ?? '');
        $end   = (string)($agent['event_ends_on'] ?? '');
        if ($start !== '' && strtotime($start) !== false) {
            $s = strtotime($start);
            $e = $end !== '' && strtotime($end) !== false ? strtotime($end) : $s;
            $second = date('Y-m', $s) === date('Y-m', $e)
                ? date('j', $s) . ($e !== $s ? '-' . date('j', $e) : '') . ' ' . date('F Y', $e)
                : date('j F', $s) . ' - ' . date('j F Y', $e);
            $venue = trim((string)($agent['event_venue'] ?? ''));
            if ($venue !== '') {
                $second .= ' - ' . $venue;
            }
        }
        // The price record's own edition wording, when there is one, names the edition ("25th Pharma Asia…").
        $edition = trim((string)($record['edition_label'] ?? ''));
        if ($second === '' && $edition !== '') {
            $second = $edition;
        }
        return [$name, $second];
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    /** The PDF of one quotation, drawn from what was saved when it was made. */
    public static function render(array $agent, array $row): string
    {
        $d    = (array)json_decode((string)$row['detail'], true);
        $card = (array)($d['card'] ?? []);
        $set  = self::settings($agent);

        $pdf = new PdfWriter();
        $pdf->setTitle('Quotation ' . $row['number']);
        $L = 48.0;
        $R = PdfWriter::W - 48.0;
        $navy = [0.07, 0.19, 0.43];
        $soft = [0.38, 0.42, 0.5];
        $y = 44.0;

        // ---- Top: the exhibition on the left, the quotation's number on the right ----
        $logoH = 0.0;
        if ($set['logo'] !== null) {
            $drawn = $pdf->image($set['logo'], $L, $y, 190, 62);
            $logoH = $drawn !== null ? $drawn[1] + 8 : 0.0;
        }
        $pdf->text($R, $y, 'QUOTATION', 22, true, 'R', $navy);
        $ry = $y + 32;
        foreach ([['No.', (string)$row['number']], ['Date', (string)($d['date'] ?? '')], ['Valid until', (string)($d['valid'] ?? '')]] as [$k, $v]) {
            $pdf->text($R - 92, $ry, $k, 9.5, false, 'R', $soft);
            $pdf->text($R, $ry, $v, 9.5, true, 'R');
            $ry += 14;
        }
        $ey = $y + $logoH;
        $event = (array)($d['event'] ?? []);
        foreach ($pdf->wrap((string)($event[0] ?? ''), 300, 13, true) as $line) {
            $pdf->text($L, $ey, $line, 13, true, 'L', $navy);
            $ey += 17;
        }
        foreach ($pdf->wrap((string)($event[1] ?? ''), 300, 9.5) as $line) {
            $pdf->text($L, $ey, $line, 9.5, false, 'L', $soft);
            $ey += 13;
        }
        $y = max($ey, $ry) + 14;
        $pdf->line($L, $y, $R, $y, 1.2, $navy);
        $y += 18;

        // ---- Prepared for / Your contact ----
        $mid = $L + 270;
        $pdf->text($L, $y, 'PREPARED FOR', 8, true, 'L', $soft);
        $pdf->text($mid, $y, 'YOUR CONTACT', 8, true, 'L', $soft);
        $y += 14;
        $to = (array)($d['to'] ?? []);
        $left = array_values(array_filter([(string)($to['name'] ?? ''), (string)($to['company'] ?? ''), (string)($to['country'] ?? '')], 'strlen'));
        $rep = (array)($d['rep'] ?? []);
        $right = [];
        if (!empty($rep['name'])) {
            $right[] = (string)$rep['name'];
            foreach (['designation', 'email'] as $k) {
                if (!empty($rep[$k])) {
                    $right[] = (string)$rep[$k];
                }
            }
            $reach = array_filter([
                !empty($rep['mobile']) ? (string)$rep['mobile'] : (!empty($rep['whatsapp']) ? 'WhatsApp ' . $rep['whatsapp'] : ''),
                !empty($rep['wechat']) ? 'WeChat ' . $rep['wechat'] : '',
            ], 'strlen');
            if ($reach) {
                $right[] = implode('  -  ', $reach);
            }
        }
        $ly = $ry2 = $y;
        foreach ($left as $i => $line) {
            foreach ($pdf->wrap($line, 250, 10.5, $i === 0) as $piece) {
                $pdf->text($L, $ly, $piece, 10.5, $i === 0);
                $ly += 14.5;
            }
        }
        foreach ($right as $i => $line) {
            foreach ($pdf->wrap($line, $R - $mid, 10.5, $i === 0) as $piece) {
                $pdf->text($mid, $ry2, $piece, 10.5, $i === 0);
                $ry2 += 14.5;
            }
        }
        $y = max($ly, $ry2) + 20;

        // ---- The price table and what is included: one block per option ----
        $footerTop = PdfWriter::H - 92;
        $newPage = static function () use ($pdf, &$y): void {
            $pdf->addPage();
            $y = 56.0;
        };
        $blocks = !empty($d['options']) && is_array($d['options'])
            ? array_values($d['options'])
            : [['card' => $card, 'rate' => $d['rate'] ?? null]];
        if (count($blocks) > 1) {
            foreach ($pdf->wrap(count($blocks) . ' options are quoted below. They are alternatives: each has its own total.', $R - $L, 10) as $line) {
                $pdf->text($L, $y, $line, 10, false, 'L', $soft);
                $y += 13.5;
            }
            $y += 8;
        }
        foreach ($blocks as $bi => $block) {
            $card = (array)($block['card'] ?? []);
            $rate = $block['rate'] ?? null;
            // A new option starts on a new page when the price table would not fit.
            if ($bi > 0 && $y + 120 > $footerTop - 10) {
                $newPage();
            }
            $isStall = (string)($card['type'] ?? '') === PricingEngine::TYPE_STALL;
            $cSize = $L + 230;
            $cRate = $L + 300;
            $pdf->box($L, $y, $R - $L, 22, $navy);
            $white = [1.0, 1.0, 1.0];
            $pdf->text($L + 8, $y + 6.5, 'Description', 9.5, true, 'L', $white);
            if ($isStall) {
                $pdf->text($cSize, $y + 6.5, 'Size', 9.5, true, 'L', $white);
                $pdf->text($cRate, $y + 6.5, 'Rate', 9.5, true, 'L', $white);
            }
            $pdf->text($R - 8, $y + 6.5, 'Amount', 9.5, true, 'R', $white);
            $y += 30;

            $priceLines = array_values((array)($card['price_lines'] ?? []));
            $titleLines = $pdf->wrap((string)($card['title'] ?? ''), $isStall ? 215 : 330, 11, true);
            $rowTop = $y;
            foreach ($titleLines as $line) {
                $pdf->text($L + 8, $y, $line, 11, true);
                $y += 15;
            }
            if ($isStall) {
                $sqm = (float)($card['sqm'] ?? 0);
                if ($sqm > 0) {
                    $pdf->text($cSize, $rowTop, rtrim(rtrim(number_format($sqm, 2), '0'), '.') . ' sqm', 10.5);
                }
                if (!empty($rate)) {
                    $pdf->text($cRate, $rowTop, (string)$rate, 10.5);
                }
            }
            $pdf->text($R - 8, $rowTop, (string)($priceLines[0]['value'] ?? ($card['price_text'] ?? '')), 11, false, 'R');
            $y = max($y, $rowTop + 15) + 6;
            $pdf->line($L, $y, $R, $y);
            $y += 9;
            foreach (array_slice($priceLines, 1) as $pl) {
                $strong = !empty($pl['emphasis']);
                if ($strong) {
                    $pdf->box($cRate - 10, $y - 4, $R - $cRate + 10, 22, [0.93, 0.95, 0.99]);
                }
                $pdf->text($cRate, $y + ($strong ? 2 : 0), (string)$pl['label'], $strong ? 11 : 10, $strong);
                $pdf->text($R - 8, $y + ($strong ? 2 : 0), (string)$pl['value'], $strong ? 11.5 : 10.5, $strong, 'R', $strong ? $navy : [0.1, 0.12, 0.16]);
                $y += $strong ? 26 : 17;
            }
            $taxNote = trim((string)($card['tax_note'] ?? ''));
            if ($taxNote !== '') {
                foreach ($pdf->wrap($taxNote, $R - $L, 8.5) as $line) {
                    $pdf->text($L, $y, $line, 8.5, false, 'L', $soft);
                    $y += 11.5;
                }
            }
            $y += 14;

            // ---- What is included, or the tier's benefits ----
            $items = array_values(array_filter(array_map('strval', (array)($card['items'] ?? [])), 'strlen'));
            if ($items) {
                $pdf->text($L, $y, $isStall ? 'What is included' : 'What the partnership includes', 11, true, 'L', $navy);
                $y += 18;
                foreach ($items as $item) {
                    $wrapped = $pdf->wrap($item, $R - $L - 14, 10);
                    if ($y + count($wrapped) * 13.5 > $footerTop - 10) {
                        $newPage();
                        if (count($blocks) > 1) {
                            // Which option this list belongs to, after a page break.
                            $pdf->text($L, $y, (string)($card['title'] ?? '') . ' (continued)', 10, true, 'L', $soft);
                            $y += 16;
                        }
                    }
                    $pdf->box($L + 2, $y + 4.2, 3, 3, $navy);
                    foreach ($wrapped as $line) {
                        $pdf->text($L + 14, $y, $line, 10);
                        $y += 13.5;
                    }
                    $y += 1.5;
                }
                $y += 12;
            }
        }

        // ---- Terms ----
        $terms = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($d['terms'] ?? '')) ?: []), 'strlen'));
        if ($terms) {
            $need = 18;
            foreach ($terms as $t) {
                $need += count($pdf->wrap($t, $R - $L - 14, 9.5)) * 12.5 + 1.5;
            }
            if ($y + $need > $footerTop - 6) {
                $newPage();
            }
            $pdf->text($L, $y, 'Terms', 11, true, 'L', $navy);
            $y += 18;
            foreach ($terms as $t) {
                $pdf->box($L + 2, $y + 4, 3, 3, $soft);
                foreach ($pdf->wrap(ltrim($t, "-• \t"), $R - $L - 14, 9.5) as $line) {
                    $pdf->text($L + 14, $y, $line, 9.5);
                    $y += 12.5;
                }
                $y += 1.5;
            }
        }

        // ---- Footer: the organiser, on the last page ----
        $fy = $footerTop;
        $pdf->line($L, $fy, $R, $fy);
        $fx = $L;
        $organiser = self::organiserLogo();
        if ($organiser !== null) {
            $drawn = $pdf->image($organiser, $L, $fy + 10, 70, 46);
            if ($drawn !== null) {
                $fx = $L + $drawn[0] + 14;
            }
        }
        $footLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($d['footer'] ?? self::DEFAULT_FOOTER)) ?: []), 'strlen'));
        $ty = $fy + 16;
        foreach (array_slice($footLines, 0, 4) as $i => $line) {
            foreach (array_slice($pdf->wrap($line, $R - $fx, $i === 0 ? 10 : 8.5, $i === 0), 0, 2) as $piece) {
                $pdf->text($fx, $ty, $piece, $i === 0 ? 10 : 8.5, $i === 0, 'L', $i === 0 ? $navy : $soft);
                $ty += $i === 0 ? 14 : 11.5;
            }
        }

        return $pdf->output();
    }
}
