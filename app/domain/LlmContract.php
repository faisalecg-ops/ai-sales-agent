<?php
declare(strict_types=1);

/**
 * LLM structured output contract (Section 27).
 *
 * Everything the model returns is validated here before any of it reaches
 * business logic. An unrecognised value becomes null rather than being
 * trusted; malformed JSON is rejected outright with no partial salvage.
 *
 * Two fields extend the spec's recommended shape:
 *   company_country_disclosed — required to implement Section 14, where the
 *     exhibiting company's location outranks the visitor's own location.
 *     Without a separate field the two cannot be told apart.
 *   location_ambiguous — lets the model flag that it could not tell which
 *     location applies, so PHP asks instead of guessing.
 */
final class LlmContract
{
    public const INTENTS = [
        'unknown', 'attendee', 'exhibitor', 'sponsor', 'partner', 'media', 'job_seeker', 'other',
    ];

    /**
     * Parse and validate raw model output.
     *
     * @return array{ok:bool,reason:?string,data:?array}
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['ok' => false, 'reason' => 'empty_output', 'data' => null];
        }

        $json = self::extractJson($raw);
        if ($json === null) {
            return ['ok' => false, 'reason' => 'no_json_object', 'data' => null];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'reason' => 'malformed_json', 'data' => null];
        }

        $reply = $decoded['reply'] ?? null;
        if (!is_string($reply) || trim($reply) === '') {
            return ['ok' => false, 'reason' => 'missing_reply', 'data' => null];
        }

        $data = [
            'reply'   => mb_substr(trim($reply), 0, 4000),
            'intent'  => Validator::inList(
                is_string($decoded['intent'] ?? null) ? $decoded['intent'] : null,
                self::INTENTS,
                null
            ),
            'stage_signal' => self::stage($decoded['stage_signal'] ?? null),
            'pricing_request'   => self::bool($decoded['pricing_request'] ?? null),
            'handoff_required'  => self::bool($decoded['handoff_required'] ?? null),
            'handoff_reason'    => self::str($decoded['handoff_reason'] ?? null, 255),
            'package_reference' => self::str($decoded['package_reference'] ?? null, 64),
            'location_ambiguous' => self::bool($decoded['location_ambiguous'] ?? null),
            'lead_fields'       => self::leadFields($decoded['lead_fields'] ?? null),
        ];

        return ['ok' => true, 'reason' => null, 'data' => $data];
    }

    /**
     * Pull the JSON object out of a response that may carry stray prose
     * or code fences around it.
     *
     * Public because the document importer has exactly the same problem with
     * exactly the same causes, and one scanner that is known to be correct
     * beats two that are nearly correct.
     */
    public static function extractJson(string $raw): ?string
    {
        $raw = trim($raw);

        // Strip ```json fences.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $raw, $m)) {
            return $m[1];
        }
        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            return $raw;
        }

        // Balanced scan for the first complete top-level object.
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }
        $depth    = 0;
        $inString = false;
        $escape   = false;
        $len      = strlen($raw);

        for ($i = $start; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private static function stage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = strtolower(trim($value));
        return ConversationState::isValid($v) ? $v : null;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', 'yes', '1'], true);
        }
        return $value === 1;
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '' || strtolower($v) === 'null') {
            return null;
        }
        return mb_substr($v, 0, $max);
    }

    /**
     * Lead fields are validated in exactly the same way as visitor-typed
     * input. A malformed email the model produced is discarded, not stored.
     */
    private static function leadFields(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $get = static fn(string $k): ?string => isset($value[$k]) && is_string($value[$k]) ? $value[$k] : null;

        $fields = [
            'name'             => Validator::text($get('name'), 160),
            'company'          => Validator::text($get('company'), 200),
            'industry'         => Validator::text($get('industry'), 160),
            'product_category' => Validator::text($get('product_category') ?? $get('product'), 200),
            'company_size'     => Validator::text($get('company_size'), 64),
            'website'          => Validator::url($get('website')),
            'email'            => Validator::email($get('email')),
            'phone'            => Validator::phone($get('phone')),
            'requirements'     => Validator::paragraph($get('requirements'), 1000),
            // Raw disclosed location text. PHP resolves it to a country;
            // the model never decides what country a place belongs to.
            'country_disclosed'         => Validator::text($get('country_disclosed') ?? $get('country'), 120),
            'company_country_disclosed' => Validator::text($get('company_country_disclosed') ?? $get('company_country'), 120),
        ];

        // Reject a "name" that is obviously a placeholder or an instruction echo.
        if ($fields['name'] !== null) {
            $lower = mb_strtolower($fields['name']);
            foreach (['null', 'none', 'unknown', 'n/a', 'not provided', 'visitor', 'the user'] as $bad) {
                if ($lower === $bad) {
                    $fields['name'] = null;
                    break;
                }
            }
        }

        return array_filter($fields, static fn($v) => $v !== null);
    }

    /** The contract description injected into the system prompt. */
    public static function instructions(): string
    {
        $stages = implode(' | ', ConversationState::ALL);
        $intents = implode(' | ', self::INTENTS);

        return <<<TXT
        RESPONSE FORMAT

        Reply with a single JSON object and nothing else. No prose before or
        after it, no code fences.

        {
          "reply": "what to say to the visitor",
          "intent": "{$intents}",
          "stage_signal": "{$stages}, or null",
          "pricing_request": true or false,
          "lead_fields": {
            "name": string or null,
            "company": string or null,
            "industry": string or null,
            "product_category": string or null,
            "company_size": string or null,
            "website": string or null,
            "email": string or null,
            "phone": string or null,
            "requirements": string or null,
            "country_disclosed": string or null,
            "company_country_disclosed": string or null
          },
          "package_reference": "stall:ID or sponsorship:ID, or null",
          "handoff_required": true or false,
          "handoff_reason": string or null,
          "location_ambiguous": true or false
        }

        Field notes:
        - "reply" is the only required field. Normally 2-4 sentences, plus a short
          list (one item per line, starting with "- ") where the rules ask for one.
        - "pricing_request": true whenever the visitor has asked about price,
          cost, rates or budget in this message, even indirectly.
        - "lead_fields": include a field only when the visitor stated it in
          this conversation. Never guess, never fill from assumption, and
          never repeat a value you were given as context unless the visitor
          has just restated or corrected it.
        - "country_disclosed" is where the person is. "company_country_disclosed"
          is where their business is based. If they differ, report both.
        - "location_ambiguous": true when a place they named could belong to
          more than one country and you could not tell which they meant.
        - "package_reference": only a Reference from the prices supplied to
          you in this turn. Never invent one.
        TXT;
    }
}
