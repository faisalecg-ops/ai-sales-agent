<?php
declare(strict_types=1);

/**
 * Turning a chunk of document text into candidate facts.
 *
 * The model's job here is narrow: read the text and report what it says,
 * with a verbatim quote for each claim. It does not decide what is true,
 * which document wins, what a price works out to, or what tax applies. Those
 * are settled afterwards — by comparison in PHP, and by asking the operator.
 *
 * Every field that comes back is validated before it is stored, in the same
 * spirit as LlmContract: an unrecognised value is dropped rather than trusted.
 */
final class FindingExtractor
{
    public const KINDS = ['knowledge', 'faq', 'stall_package', 'sponsorship_package'];

    /** Claim keys the reconciler knows how to compare and describe. */
    public const CLAIM_LABELS = [
        'price'              => 'price',
        'rate_per_sqm'       => 'rate per square metre',
        'currency'           => 'currency',
        'min_sqm'            => 'minimum size',
        'increment_sqm'      => 'size increments',
        'max_sqm'            => 'maximum size',
        'tax_rate_percent'   => 'tax rate',
        'tax_label'          => 'tax name',
        'tax_treatment'      => 'whether tax is included',
        'quantity_available' => 'how many are available',
        'included_space'     => 'exhibition space included',
        'dates'              => 'event dates',
        'venue'              => 'venue',
        'city'               => 'city',
        'edition_number'     => 'edition number',
        'visitor_count'      => 'number of visitors',
        'exhibitor_count'    => 'number of exhibitors',
        'country_count'      => 'number of countries',
        'exhibition_area'    => 'exhibition area',
        'trade_passes'       => 'trade passes',
        'vip_passes'         => 'VIP passes',
        'catalogue_words'    => 'catalogue profile length',
        'deadline'           => 'deadline',
        'booking_terms'      => 'booking terms',
        'payment_terms'      => 'payment terms',
        'contact_email'      => 'contact email',
        'contact_phone'      => 'contact phone',
        'website'            => 'website',
        'organiser'          => 'organiser',
    ];

    /**
     * Analyse the next pending chunk of this run.
     *
     * @return array{done:bool,chunk:?array,findings:int,error:?string}
     */
    public static function analyseNext(array $agent, array $run): array
    {
        $runId = (int)$run['id'];

        $chunk = db()->one(
            "SELECT c.*, d.original_name, d.doc_kind, d.edition_label, d.extension
             FROM import_chunks c
             JOIN import_documents d ON d.id = c.document_id
             WHERE c.run_id = ? AND c.status = 'pending' AND c.attempts < 3
             ORDER BY c.id ASC LIMIT 1",
            [$runId]
        );

        if (!$chunk) {
            return ['done' => true, 'chunk' => null, 'findings' => 0, 'error' => null];
        }

        db()->exec('UPDATE import_chunks SET attempts = attempts + 1 WHERE id = ?', [(int)$chunk['id']]);

        // The import's own provider, which may differ from the one serving
        // visitors (see ImportRun::reader).
        $reader   = ImportRun::reader($run, $agent);
        $provider = LlmProviderFactory::make($reader['provider']);
        $prompt   = self::systemPrompt($chunk);

        $result = $provider->complete(
            $prompt,
            [['role' => 'user', 'content' => self::userMessage($chunk)]],
            [
                'model'       => $reader['model'],
                'temperature' => 0.0,   // extraction, not writing
                'max_tokens'  => 4000,
                'timeout'     => max(30, (int)$agent['llm_timeout_seconds']),
            ]
        );

        if (!$result['ok']) {
            db()->update('import_chunks', [
                'last_error' => mb_substr((string)($result['error'] ?? $result['status']), 0, 400),
            ], 'id = ?', [(int)$chunk['id']]);

            // A rejected credential will be rejected again, so it is not
            // retried; anything else gets three attempts before the section is
            // skipped rather than stalling the whole run.
            $permanent = in_array((string)$result['status'], ['auth_error'], true);
            if ($permanent || (int)$chunk['attempts'] + 1 >= 3) {
                db()->update('import_chunks', ['status' => 'failed'], 'id = ?', [(int)$chunk['id']]);
            }
            ImportRun::refreshCounts($runId);
            return ['done' => false, 'chunk' => $chunk, 'findings' => 0, 'error' => (string)$result['status']];
        }

        ImportRun::countTokens($runId, (int)$result['input_tokens'], (int)$result['output_tokens']);

        $parsed = self::parse($result['text']);
        if (!$parsed['ok']) {
            db()->update('import_chunks', [
                'last_error' => 'unreadable model output: ' . (string)$parsed['reason'],
            ], 'id = ?', [(int)$chunk['id']]);
            if ((int)$chunk['attempts'] + 1 >= 3) {
                db()->update('import_chunks', ['status' => 'failed'], 'id = ?', [(int)$chunk['id']]);
            }
            ImportRun::refreshCounts($runId);
            return ['done' => false, 'chunk' => $chunk, 'findings' => 0, 'error' => 'bad_output'];
        }

        $stored = 0;
        foreach ($parsed['findings'] as $finding) {
            if (self::store($run, $chunk, $finding)) {
                $stored++;
            }
        }

        db()->update('import_chunks', [
            'status'      => 'done',
            'analysed_at' => gmdate('Y-m-d H:i:s'),
            'last_error'  => null,
        ], 'id = ?', [(int)$chunk['id']]);

        ImportRun::refreshCounts($runId);

        return ['done' => false, 'chunk' => $chunk, 'findings' => $stored, 'error' => null];
    }

    // -----------------------------------------------------------------
    // Prompting
    // -----------------------------------------------------------------

    private static function systemPrompt(array $chunk): string
    {
        $kinds  = implode(' | ', self::KINDS);
        $claims = implode(', ', array_keys(self::CLAIM_LABELS));

        return <<<TXT
        You are reading one section of a document belonging to a business-to-business
        exhibition, so that its contents can be loaded into a sales assistant's
        knowledge base.

        Your only job is to report what this text says. You are not deciding what is
        correct, what is current, or which document should be believed. Something else
        does that afterwards.

        RULES

        1. Report only what is written in the text in front of you. If a detail is not
           stated, leave it out. Never fill a gap from general knowledge.
        2. Never calculate. Do not multiply a rate by an area, do not add tax to a
           price, do not work out a total, and do not convert a currency. Report only
           figures that appear literally in the text.
        3. Every finding must carry a "quote": the exact wording from the text that
           supports it, copied character for character. If you cannot quote it, do not
           report it.
        4. If the text states which edition, year, city or venue something applies to,
           put that in "edition". This matters: the same exhibition runs in different
           cities with different dates and different tax, and a price for one must
           never be mixed with another.
        5. Use "concerns" to say anything that makes a finding uncertain: a figure that
           is cut off, a price with no currency, a date with no year, a table that may
           have lost its column headings, a benefit that might belong to a different
           tier.
        6. Ignore navigation text, page numbers, decorative slogans and repeated
           headers or footers. Do not create findings from them.
        7. Do not report anything about stand numbers, floor positions or hall layout.

        WHAT TO REPORT

        "kind" is one of: {$kinds}

        - stall_package: an exhibition space option that a company can book, such as a
          shell scheme stand, bare floor space inside the halls, or outdoor space.
        - sponsorship_package: a sponsorship or partnership tier.
        - faq: a question a visitor might ask, where the text gives a direct answer.
        - knowledge: any other useful background — what the event is, who attends,
          what sectors exhibit, results of a past edition, the conference programme,
          contact details.

        "claims" is a flat object of the individual facts, so they can be compared
        against the other documents. Use these keys where they apply:
        {$claims}

        Write claim values as they appear, with the unit: "USD 400", "15%", "18-20
        March 2027", "9 sqm", "32000". One value per key. Omit a key entirely rather
        than guessing at it.

        RESPONSE FORMAT

        Reply with a single JSON object and nothing else. No prose, no code fences.

        {
          "findings": [
            {
              "kind": "stall_package",
              "subject": "Shell Scheme Stand",
              "edition": "21st Build Asia, Karachi, 18-20 March 2027",
              "claims": { "rate_per_sqm": "USD 400", "min_sqm": "9 sqm" },
              "payload": {
                "package_name": "Shell Scheme Stand",
                "package_type": "shell",
                "stall_size": "built in 3m x 3m modules",
                "currency": "USD",
                "rate_per_sqm": "400",
                "min_sqm": "9",
                "included_items": ["Carpeted floor", "Two chairs"],
                "tax_treatment": "excluded",
                "tax_rate_percent": "15",
                "tax_label": "Sindh Sales Tax"
              },
              "quote": "Shell Scheme: USD 400 per sqm, minimum 9 sqm",
              "confidence": "high",
              "concerns": []
            }
          ]
        }

        Payload fields by kind:
        - stall_package: package_name, package_type ("shell", "bare" or "outdoor"), stall_size,
          currency, price, rate_per_sqm, min_sqm, increment_sqm, max_sqm,
          included_items (array), tax_treatment ("included", "excluded" or "unstated"),
          tax_rate_percent, tax_label, description
        - sponsorship_package: tier_name, currency, price, benefits (array),
          quantity_available, tax_treatment, tax_rate_percent, tax_label, description
        - faq: question, answer, category
        - knowledge: title, body, category, keywords

        Return an empty "findings" array if this section contains nothing worth
        recording. That is a perfectly good answer.
        TXT;
    }

    private static function userMessage(array $chunk): string
    {
        $name    = (string)$chunk['original_name'];
        $kind    = ImportRun::documentKindLabels()[(string)$chunk['doc_kind']] ?? 'Unknown type';
        $edition = trim((string)($chunk['edition_label'] ?? ''));

        $header = "Document: " . $name . "\n"
                . "The operator describes this document as: " . $kind . "\n";
        if ($edition !== '') {
            $header .= "The operator says it relates to: " . $edition . "\n";
        }
        $header .= "Section " . ((int)$chunk['chunk_index'] + 1) . " of this document.\n";

        // Fenced as data, so instruction-shaped text inside a brochure cannot
        // be read as an instruction.
        return $header . "\n<document_text>\n"
             . str_replace(['</document_text>', '<document_text>'], ['[/document_text]', '[document_text]'], (string)$chunk['body'])
             . "\n</document_text>";
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * @return array{ok:bool,reason:?string,findings:array}
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['ok' => false, 'reason' => 'empty', 'findings' => []];
        }

        $json = LlmContract::extractJson($raw);
        if ($json === null) {
            return ['ok' => false, 'reason' => 'no_json', 'findings' => []];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'reason' => 'malformed_json', 'findings' => []];
        }

        $list = $decoded['findings'] ?? null;
        if (!is_array($list)) {
            // An empty or absent list is a legitimate answer.
            return ['ok' => true, 'reason' => null, 'findings' => []];
        }

        $clean = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $validated = self::validateFinding($entry);
            if ($validated !== null) {
                $clean[] = $validated;
            }
            if (count($clean) >= 60) {
                break;   // a single section should never yield more than this
            }
        }

        return ['ok' => true, 'reason' => null, 'findings' => $clean];
    }

    private static function validateFinding(array $entry): ?array
    {
        $kind = Validator::inList(
            is_string($entry['kind'] ?? null) ? strtolower(trim($entry['kind'])) : null,
            self::KINDS,
            null
        );
        if ($kind === null) {
            return null;
        }

        $subject = Validator::text($entry['subject'] ?? null, 190);
        $quote   = Validator::paragraph($entry['quote'] ?? null, 1200);

        // A claim with no supporting quote is exactly the kind of thing that
        // should not reach a knowledge base.
        if ($quote === null || mb_strlen($quote) < 8) {
            return null;
        }

        $payload = self::validatePayload($kind, is_array($entry['payload'] ?? null) ? $entry['payload'] : []);
        if ($payload === null) {
            return null;
        }

        if ($subject === null || $subject === '') {
            $subject = self::subjectFromPayload($kind, $payload);
            if ($subject === null) {
                return null;
            }
        }

        return [
            'kind'       => $kind,
            'subject'    => $subject,
            'edition'    => Validator::text($entry['edition'] ?? null, 160),
            'claims'     => self::validateClaims($entry['claims'] ?? null),
            'payload'    => $payload,
            'quote'      => $quote,
            'confidence' => Validator::inList(
                is_string($entry['confidence'] ?? null) ? strtolower(trim($entry['confidence'])) : null,
                ['high', 'medium', 'low'],
                'medium'
            ),
            'concerns'   => self::validateConcerns($entry['concerns'] ?? null),
        ];
    }

    /** @return array<string,string> */
    private static function validateClaims(mixed $claims): array
    {
        if (!is_array($claims)) {
            return [];
        }
        $out = [];
        foreach ($claims as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalKey = self::normaliseClaimKey($key);
            if ($normalKey === '' || !isset(self::CLAIM_LABELS[$normalKey])) {
                continue;   // unknown claim keys are dropped, not guessed at
            }
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $clean = Validator::text((string)$value, 190);
            if ($clean !== null && $clean !== '') {
                $out[$normalKey] = $clean;
            }
            if (count($out) >= 25) {
                break;
            }
        }
        return $out;
    }

    public static function normaliseClaimKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');

        // A few spellings the model reaches for that mean the same thing.
        return match ($key) {
            'rate', 'per_sqm', 'sqm_rate', 'price_per_sqm' => 'rate_per_sqm',
            'minimum', 'minimum_size', 'minimum_sqm'       => 'min_sqm',
            'maximum', 'maximum_size', 'maximum_sqm'       => 'max_sqm',
            'increment', 'step', 'steps'                   => 'increment_sqm',
            'tax', 'tax_rate', 'sales_tax', 'sst'          => 'tax_rate_percent',
            'tax_name'                                     => 'tax_label',
            'tax_included', 'tax_status'                   => 'tax_treatment',
            'quantity', 'available', 'slots', 'inventory'  => 'quantity_available',
            'space', 'space_included', 'booths'            => 'included_space',
            'date', 'event_dates', 'show_dates'            => 'dates',
            'location'                                     => 'venue',
            'visitors', 'trade_visitors', 'footfall'       => 'visitor_count',
            'exhibitors'                                   => 'exhibitor_count',
            'countries'                                    => 'country_count',
            'area', 'space_area', 'exhibition_space'       => 'exhibition_area',
            'email'                                        => 'contact_email',
            'phone', 'telephone'                           => 'contact_phone',
            default                                        => $key,
        };
    }

    /** @return string[] */
    private static function validateConcerns(mixed $concerns): array
    {
        if (is_string($concerns)) {
            $concerns = [$concerns];
        }
        if (!is_array($concerns)) {
            return [];
        }
        $out = [];
        foreach ($concerns as $c) {
            if (!is_scalar($c)) {
                continue;
            }
            $clean = Validator::text((string)$c, 300);
            if ($clean !== null && $clean !== '') {
                $out[] = $clean;
            }
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /**
     * Payload validation per kind. Money and sizes are parsed strictly: a
     * value that is not a clean number is dropped rather than coerced, so a
     * mangled figure becomes a missing field the operator is asked about,
     * not a wrong price on a card.
     */
    private static function validatePayload(string $kind, array $payload): ?array
    {
        $str = static fn(string $key, int $max = 255): ?string
            => Validator::text($payload[$key] ?? null, $max);

        $num = static function (string $key) use ($payload): ?float {
            $raw = $payload[$key] ?? null;
            if ($raw === null || $raw === '' || is_array($raw)) {
                return null;
            }
            // Strip currency words, symbols and thousands separators.
            $clean = preg_replace('/[^0-9.\-]/', '', (string)$raw) ?? '';
            if ($clean === '' || !is_numeric($clean)) {
                return null;
            }
            $value = (float)$clean;
            return $value > 0 ? round($value, 2) : null;
        };

        $list = static function (string $key) use ($payload): array {
            $raw = $payload[$key] ?? null;
            if (is_string($raw)) {
                $raw = preg_split('/\r?\n|;/', $raw) ?: [];
            }
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $clean = Validator::text(preg_replace('/^[\-\*\x{2022}]\s*/u', '', trim((string)$item)) ?? '', 200);
                if ($clean !== null && $clean !== '') {
                    $out[] = $clean;
                }
                if (count($out) >= 30) {
                    break;
                }
            }
            return $out;
        };

        $currency = $str('currency', 12);
        if ($currency !== null) {
            $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', $currency) ?? '');
            if (strlen($currency) !== 3) {
                $currency = null;
            }
        }

        $taxTreatment = Validator::inList(
            is_string($payload['tax_treatment'] ?? null) ? strtolower(trim($payload['tax_treatment'])) : null,
            ['included', 'excluded', 'unstated'],
            'unstated'
        );

        switch ($kind) {
            case 'stall_package':
                $name = $str('package_name', 160);
                if ($name === null) {
                    return null;
                }
                return array_filter([
                    'package_name'     => $name,
                    'package_type'     => Validator::inList(
                        is_string($payload['package_type'] ?? null) ? strtolower(trim($payload['package_type'])) : null,
                        ['shell', 'bare', 'outdoor'],
                        null
                    ),
                    'stall_size'       => $str('stall_size', 64),
                    'currency'         => $currency,
                    'price'            => $num('price'),
                    'rate_per_sqm'     => $num('rate_per_sqm'),
                    'min_sqm'          => $num('min_sqm'),
                    'increment_sqm'    => $num('increment_sqm'),
                    'max_sqm'          => $num('max_sqm'),
                    'included_items'   => $list('included_items'),
                    'tax_treatment'    => $taxTreatment,
                    'tax_rate_percent' => $num('tax_rate_percent'),
                    'tax_label'        => $str('tax_label', 120),
                    'description'      => Validator::paragraph($payload['description'] ?? null, 1200),
                ], static fn($v) => $v !== null && $v !== [] && $v !== '');

            case 'sponsorship_package':
                $tier = $str('tier_name', 160);
                if ($tier === null) {
                    return null;
                }
                return array_filter([
                    'tier_name'          => $tier,
                    'currency'           => $currency,
                    'price'              => $num('price'),
                    'benefits'           => $list('benefits'),
                    'quantity_available' => $num('quantity_available'),
                    'tax_treatment'      => $taxTreatment,
                    'tax_rate_percent'   => $num('tax_rate_percent'),
                    'tax_label'          => $str('tax_label', 120),
                    'description'        => Validator::paragraph($payload['description'] ?? null, 1200),
                ], static fn($v) => $v !== null && $v !== [] && $v !== '');

            case 'faq':
                $question = Validator::paragraph($payload['question'] ?? null, 400);
                $answer   = Validator::paragraph($payload['answer'] ?? null, 4000);
                if ($question === null || $answer === null || mb_strlen($answer) < 10) {
                    return null;
                }
                return array_filter([
                    'question' => $question,
                    'answer'   => $answer,
                    'category' => $str('category', 80),
                    'keywords' => $str('keywords', 500),
                ], static fn($v) => $v !== null && $v !== '');

            case 'knowledge':
            default:
                $title = $str('title', 200);
                $body  = Validator::paragraph($payload['body'] ?? null, 12000);
                if ($title === null || $body === null || mb_strlen($body) < 25) {
                    return null;
                }
                return array_filter([
                    'title'    => $title,
                    'body'     => $body,
                    'category' => $str('category', 80),
                    'keywords' => $str('keywords', 500),
                ], static fn($v) => $v !== null && $v !== '');
        }
    }

    private static function subjectFromPayload(string $kind, array $payload): ?string
    {
        return match ($kind) {
            'stall_package'       => $payload['package_name'] ?? null,
            'sponsorship_package' => $payload['tier_name'] ?? null,
            'faq'                 => $payload['question'] ?? null,
            default               => $payload['title'] ?? null,
        };
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    private static function store(array $run, array $chunk, array $finding): bool
    {
        try {
            db()->insert('import_findings', [
                'run_id'        => (int)$run['id'],
                'document_id'   => (int)$chunk['document_id'],
                'chunk_id'      => (int)$chunk['id'],
                'kind'          => (string)$finding['kind'],
                'subject_key'   => self::subjectKey((string)$finding['kind'], (string)$finding['subject'], $finding['payload']),
                'subject_label' => mb_substr((string)$finding['subject'], 0, 190),
                'edition_label' => $finding['edition'] ?? ($chunk['edition_label'] ?? null),
                'payload_json'  => json_encode($finding['payload'], JSON_UNESCAPED_UNICODE),
                'claims_json'   => json_encode($finding['claims'], JSON_UNESCAPED_UNICODE),
                'source_quote'  => (string)$finding['quote'],
                'confidence'    => (string)$finding['confidence'],
                'concerns_json' => $finding['concerns'] ? json_encode($finding['concerns'], JSON_UNESCAPED_UNICODE) : null,
            ]);
            return true;
        } catch (Throwable $ex) {
            app_log('import', 'could not store finding', ['error' => $ex->getMessage()]);
            return false;
        }
    }

    /**
     * A stable identity for grouping the same thing across documents.
     *
     * Wording varies far more than substance. One document says "Shell
     * Scheme", another "Pre-Constructed Shell Scheme", a third "Shell Scheme
     * Stand" — all the same product, and a disagreement between them about
     * price has to be visible. So the key is built from what the thing IS
     * where that can be determined, and only falls back to the words when it
     * cannot.
     */
    public static function subjectKey(string $kind, string $subject, array $payload = []): string
    {
        $words = mb_strtolower(trim($subject));
        $words = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $words) ?? $words;
        $words = trim(preg_replace('/\s+/', ' ', $words) ?? $words);

        if ($kind === 'stall_package') {
            $type = self::stallType($words, $payload);
            if ($type !== null) {
                return 'stall_package:' . $type;
            }
        }

        if ($kind === 'sponsorship_package') {
            $tier = self::sponsorshipTier($words);
            if ($tier !== null) {
                return 'sponsorship_package:' . $tier;
            }
        }

        return mb_substr($kind . ':' . self::slug($words, $subject), 0, 190);
    }

    /**
     * Shell scheme means a built stand; bare space means floor only inside
     * the halls; outdoor space is open area outside them. Those are the
     * things being sold, whatever a given brochure calls them.
     */
    private static function stallType(string $words, array $payload): ?string
    {
        $declared = strtolower(trim((string)($payload['package_type'] ?? '')));
        if ($declared === 'shell' || $declared === 'bare' || $declared === 'outdoor') {
            return $declared;
        }
        // Checked first: "Outdoor Exhibit Space" is not bare space in a hall.
        if (preg_match('/\b(outdoor|outdoors|outside|open air)\b/', $words)) {
            return 'outdoor';
        }
        if (preg_match('/\b(shell|constructed|prefabricated|built|standard booth)\b/', $words)) {
            return 'shell';
        }
        if (preg_match('/\b(bare|raw|floor only|space only|open space)\b/', $words)) {
            return 'bare';
        }
        return null;
    }

    /** The tier word is the identity; everything around it is decoration. */
    private static function sponsorshipTier(string $words): ?string
    {
        foreach (['title', 'diamond', 'platinum', 'gold', 'silver', 'bronze', 'associate', 'supporting'] as $tier) {
            if (preg_match('/\b' . $tier . '\b/', $words)) {
                return $tier;
            }
        }
        return null;
    }

    /** A readable slug, with wrapper words removed. */
    private static function slug(string $words, string $original): string
    {
        $noise = ['package', 'packages', 'sponsorship', 'sponsor', 'partner', 'partnership',
                  'tier', 'level', 'option', 'scheme', 'stand', 'booth', 'the', 'a', 'an'];
        $parts = array_values(array_diff(explode(' ', $words), $noise));
        if ($parts === []) {
            $parts = explode(' ', $words);
        }
        $slug = implode('-', array_slice($parts, 0, 6));
        return $slug !== '' ? $slug : substr(sha1($original), 0, 12);
    }
}
