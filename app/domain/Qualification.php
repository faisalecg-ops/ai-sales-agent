<?php
declare(strict_types=1);

/**
 * The qualification record, the lead score and the guided sales path
 * (owner's plan approved 12 November 2026: Claude doc "AI Sales Agent —
 * Guided selling, quotations and email replies: plan", parts 1 and 2).
 *
 * For every possible exhibitor the conversation keeps nine facts. The
 * assistant reports them as it hears them; PHP validates each one, keeps
 * only what the visitor actually said, works out how complete the record
 * is, scores the lead, and decides which step of the sales path the chat
 * is on. The AI never decides the step, the score, a price or a country.
 */
final class Qualification
{
    /** The nine facts, in the order they are shown. */
    public const FIELDS = [
        'products_made'    => 'Products they make',
        'products_to_show' => 'Products they would show',
        'sells_abroad'     => 'Sells abroad',
        'main_markets'     => 'Main markets',
        'south_asia'       => 'Experience in Pakistan or South Asia',
        'seeking'          => 'Looking for',
        'main_aim'         => 'Main aim',
        'stand_interest'   => 'Stand interest',
        'contact_role'     => "Contact's role",
    ];

    public const CHOICES = [
        'south_asia'     => ['none' => 'None yet', 'sells' => 'Already sells there', 'exhibited' => 'Has exhibited there',
                             'both' => 'Sells and has exhibited there'],
        'seeking'        => ['distributors' => 'Distributors', 'direct_customers' => 'Direct buyers', 'both' => 'Distributors and direct buyers'],
        'main_aim'       => ['brand_awareness' => 'Brand awareness', 'distributor_search' => 'Finding distributors',
                             'direct_sales' => 'Direct sales', 'partnerships' => 'Partnerships'],
        'stand_interest' => ['small' => 'Small stand', 'medium' => 'Medium stand', 'large' => 'Large stand',
                             'sponsorship' => 'Sponsorship'],
    ];

    private const LIST_FIELDS = ['products_made', 'products_to_show', 'main_markets'];

    /** The eight steps of the sales path. */
    public const STEPS = [
        1 => 'Their company',
        2 => 'What they would show',
        3 => 'Their markets',
        4 => 'Their aim',
        5 => 'The opportunity',
        6 => 'The recommendation',
        7 => 'Their questions',
        8 => 'The next step',
    ];

    public const DEFAULT_WEIGHTS = [
        'sector_match'   => 30,
        'sells_abroad'   => 20,
        'target_country' => 15,
        'decision_maker' => 15,
        'completeness'   => 20,
    ];

    // -----------------------------------------------------------------
    // Reading what the assistant reported
    // -----------------------------------------------------------------

    /**
     * Validate the "qualification" object of a model reply. Unknown keys and
     * values outside a fixed set are dropped.
     */
    public static function fromModel(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach (self::LIST_FIELDS as $key) {
            $items = $value[$key] ?? null;
            if (is_string($items)) {
                $items = preg_split('/\s*[,;]\s*/', $items) ?: [];
            }
            if (!is_array($items)) {
                continue;
            }
            $clean = [];
            foreach ($items as $item) {
                $t = is_string($item) ? Validator::text($item, 80) : null;
                if ($t !== null && !self::isPlaceholder($t)) {
                    $clean[] = $t;
                }
            }
            if ($clean) {
                $out[$key] = array_slice(array_values(array_unique($clean)), 0, 8);
            }
        }
        if (is_bool($value['sells_abroad'] ?? null)) {
            $out['sells_abroad'] = $value['sells_abroad'];
        }
        foreach (self::CHOICES as $key => $allowed) {
            $v = $value[$key] ?? null;
            if (is_string($v) && isset($allowed[strtolower(trim($v))])) {
                $out[$key] = strtolower(trim($v));
            }
        }
        $sqm = $value['stand_sqm'] ?? null;
        if (is_numeric($sqm) && (float)$sqm > 0 && (float)$sqm <= 5000) {
            $out['stand_sqm'] = (float)$sqm;
        }
        $role = is_string($value['contact_role'] ?? null) ? Validator::text($value['contact_role'], 80) : null;
        if ($role !== null && !self::isPlaceholder($role)) {
            $out['contact_role'] = $role;
        }
        return $out;
    }

    private static function isPlaceholder(string $t): bool
    {
        return in_array(mb_strtolower(trim($t)), ['null', 'none', 'unknown', 'n/a', 'not provided', 'unspecified',
            'undecided', 'not stated', '-'], true);
    }

    /**
     * Keep only what the visitor's own words support (plan, part 2: "a fact
     * with no support in the conversation is not saved"). A free-text value
     * must share a meaningful word with what the visitor wrote. Research may
     * support "products they make".
     *
     * @param string $visitorText everything the visitor has written so far
     * @param string $researchText what research found (products, summary)
     */
    public static function supported(array $update, string $visitorText, string $researchText = ''): array
    {
        $said     = ' ' . mb_strtolower($visitorText) . ' ';
        $research = ' ' . mb_strtolower($researchText) . ' ';
        $has = static function (string $value, string $haystack): bool {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value)) ?: [] as $w) {
                if (mb_strlen($w) >= 3 && str_contains($haystack, $w)) {
                    return true;
                }
            }
            return false;
        };

        foreach (self::LIST_FIELDS as $key) {
            if (!isset($update[$key])) {
                continue;
            }
            $kept = array_values(array_filter(
                $update[$key],
                static fn(string $item): bool => $has($item, $said) || ($key === 'products_made' && $has($item, $research))
            ));
            if ($kept) {
                $update[$key] = $kept;
            } else {
                unset($update[$key]);
            }
        }
        if (isset($update['contact_role']) && !$has($update['contact_role'], $said)) {
            unset($update['contact_role']);
        }
        if (isset($update['stand_sqm']) && !preg_match('/\d/', $visitorText)) {
            unset($update['stand_sqm']);
        }
        return $update;
    }

    /** New facts are added; a known fact changes only when reported again. */
    public static function merge(array $existing, array $update): array
    {
        foreach ($update as $key => $value) {
            if (isset(self::FIELDS[$key]) || $key === 'stand_sqm') {
                $existing[$key] = $value;
            }
        }
        return $existing;
    }

    /** What research is sure the company makes fills an empty "products they make". */
    public static function withResearch(array $qual, array $researchProducts): array
    {
        if (empty($qual['products_made']) && $researchProducts) {
            $qual['products_made'] = array_slice(array_values($researchProducts), 0, 6);
            $qual['_from_research'] = true;
        }
        return $qual;
    }

    public static function isKnown(array $qual, string $key): bool
    {
        if (!array_key_exists($key, $qual)) {
            return false;
        }
        $v = $qual[$key];
        return is_bool($v) || (is_array($v) ? $v !== [] : trim((string)$v) !== '');
    }

    /** How many of the nine facts are known, as a percentage. */
    public static function completeness(array $qual): int
    {
        $known = 0;
        foreach (array_keys(self::FIELDS) as $key) {
            if (self::isKnown($qual, $key)) {
                $known++;
            }
        }
        return (int)round(100 * $known / count(self::FIELDS));
    }

    /** A fact as shown to staff. */
    public static function display(array $qual, string $key): string
    {
        if (!self::isKnown($qual, $key)) {
            return '';
        }
        $v = $qual[$key];
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_array($v)) {
            return implode(', ', array_map('strval', $v));
        }
        $text = self::CHOICES[$key][(string)$v] ?? (string)$v;
        if ($key === 'stand_interest' && !empty($qual['stand_sqm'])) {
            $sqm = (float)$qual['stand_sqm'];
            $text .= ', about ' . rtrim(rtrim(number_format($sqm, 1, '.', ''), '0'), '.') . ' sqm';
        }
        return $text;
    }

    // -----------------------------------------------------------------
    // Lead score
    // -----------------------------------------------------------------

    /** The exhibition's weights, falling back to the defaults. */
    public static function weights(array $agent): array
    {
        $saved = json_decode((string)($agent['score_weights'] ?? ''), true);
        $out = [];
        foreach (self::DEFAULT_WEIGHTS as $key => $default) {
            $out[$key] = is_array($saved) && is_numeric($saved[$key] ?? null)
                ? max(0, min(100, (int)$saved[$key])) : $default;
        }
        return $out;
    }

    /**
     * Target countries, one per line; "Country | 2" marks a second-tier
     * target, which counts for 60 per cent.
     *
     * @return array<string,int> lower-case country => tier
     */
    public static function targetCountries(array $agent): array
    {
        $out = [];
        foreach (preg_split('/\R+/', (string)($agent['target_countries'] ?? '')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] !== '') {
                $out[mb_strtolower($parts[0])] = (isset($parts[1]) && (int)$parts[1] === 2) ? 2 : 1;
            }
        }
        return $out;
    }

    public static function isDecisionMaker(?string $role): bool
    {
        return $role !== null && (bool)preg_match(
            '/\b(?:owner|founder|ceo|coo|cfo|cmo|chief|president|chairman|managing\s+director|director|partner|'
            . 'general\s+manager|head\s+of|vp|vice\s+president|export\s+manager|sales\s+manager|business\s+development|'
            . 'country\s+manager|regional\s+manager|marketing\s+manager|proprietor)\b/i',
            $role
        );
    }

    /**
     * @param string|null $companyCountry the company's country by name, as told by the visitor
     * @return array{score:float,label:string,parts:array<string,float>}
     */
    public static function score(array $agent, array $qual, ?string $companyCountry): array
    {
        $w = self::weights($agent);
        $parts = [];

        $about = implode(' ', array_merge((array)($qual['products_made'] ?? []), (array)($qual['products_to_show'] ?? [])));
        $match = trim($about) !== '' ? VisitorProfile::sectorRelevance($about, $agent) : null;
        $parts['sector_match'] = (is_array($match) && ($match[0] ?? '') === 'relevant') ? (float)$w['sector_match'] : 0.0;

        $parts['sells_abroad'] = !empty($qual['sells_abroad']) ? (float)$w['sells_abroad'] : 0.0;

        $targets = self::targetCountries($agent);
        $country = mb_strtolower(trim((string)$companyCountry));
        if ($country === '' || $country === 'pakistan') {
            $parts['target_country'] = 0.0;
        } elseif (!$targets) {
            // No list set: any company from abroad counts as second tier.
            $parts['target_country'] = round($w['target_country'] * 0.6, 2);
        } else {
            $tier = $targets[$country] ?? null;
            $parts['target_country'] = $tier === 1 ? (float)$w['target_country']
                : ($tier === 2 ? round($w['target_country'] * 0.6, 2) : 0.0);
        }

        $parts['decision_maker'] = self::isDecisionMaker($qual['contact_role'] ?? null) ? (float)$w['decision_maker'] : 0.0;
        $parts['completeness']   = round($w['completeness'] * self::completeness($qual) / 100, 2);

        $total = array_sum($w);
        $score = $total > 0 ? round(100 * array_sum($parts) / $total, 2) : 0.0;

        return ['score' => $score, 'label' => self::label($score), 'parts' => $parts];
    }

    public static function label(float $score): string
    {
        return match (true) {
            $score >= 90 => 'hot',
            $score >= 70 => 'warm',
            $score >= 50 => 'nurture',
            default      => 'low',
        };
    }

    public static function labelText(?string $label): string
    {
        return match ($label) {
            'hot' => 'Hot', 'warm' => 'Warm', 'nurture' => 'Nurture', 'low' => 'Low', default => '',
        };
    }

    // -----------------------------------------------------------------
    // The sales path
    // -----------------------------------------------------------------

    /**
     * Which step the conversation is on. Steps 1 to 4 are done when their
     * facts are known; 5 and 6 when the assistant has presented the
     * opportunity and made a recommendation; 7 lasts until the visitor asks
     * for the next step.
     *
     * @param array $path flags kept in lead_state: opportunity, recommended, next_step
     */
    public static function step(array $qual, array $path): int
    {
        if (!empty($path['next_step'])) {
            return 8;
        }
        if (!self::isKnown($qual, 'products_made')) {
            return 1;
        }
        if (!self::isKnown($qual, 'products_to_show')) {
            return 2;
        }
        if (!self::isKnown($qual, 'sells_abroad') || !self::isKnown($qual, 'south_asia')) {
            return 3;
        }
        if (!self::isKnown($qual, 'seeking') && !self::isKnown($qual, 'main_aim')) {
            return 4;
        }
        if (empty($path['opportunity'])) {
            return 5;
        }
        if (empty($path['recommended'])) {
            return 6;
        }
        return 7;
    }

    /**
     * After a reply: what this turn completed. The opportunity counts as
     * presented once a reply was written at step 5; the recommendation once a
     * reply at step 6 or later named an option.
     */
    public static function advance(array $path, int $stepBefore, bool $replyNamedOffer, bool $asksNextStep): array
    {
        if ($stepBefore === 5) {
            $path['opportunity'] = true;
        }
        if ($stepBefore >= 6 && $replyNamedOffer) {
            $path['recommended'] = true;
        }
        if ($asksNextStep) {
            $path['next_step'] = true;
        }
        return $path;
    }

    /** The guidance the assistant is given for this turn. */
    public static function promptBlock(array $qual, int $step, bool $pricesAllowed): string
    {
        $known = [];
        $unknown = [];
        foreach (self::FIELDS as $key => $label) {
            if (self::isKnown($qual, $key)) {
                $known[] = '- ' . $label . ': ' . self::display($qual, $key)
                    . ($key === 'products_made' && !empty($qual['_from_research']) ? ' (from research; confirm lightly, do not ask)' : '');
            } else {
                $unknown[] = $label;
            }
        }

        $now = match ($step) {
            1 => "Find out what their company makes. If research already says, confirm it in a few words instead of asking.",
            2 => "Find out which of their products they would want to show at this exhibition.",
            3 => "Find out where they sell today, and whether they already sell or have exhibited in Pakistan or South Asia. "
               . "Ask about one of these at a time.",
            4 => "Find out what they want from exhibiting: distributors, direct buyers, brand awareness or partnerships.",
            5 => "Present the opportunity: why Pakistan's market suits their products, using only the market notes in this "
               . "prompt. Two or three sentences, tied to what they make and what they are looking for.",
            6 => "Recommend ONE stand option or sponsorship tier that fits what you now know about them, with one reason. "
               . "Then ask if they would like the details.",
            7 => "Answer their questions about sizes, what is included, " . ($pricesAllowed ? "prices, " : "") . "dates and "
               . "logistics. When they seem satisfied, offer the next step.",
            default => $pricesAllowed
                ? "Help them take the next step they chose: a call with the representative, a proposal from the team, or "
                  . "the Book a stand page."
                : "Help them take the next step: the sales representative, whose details are added to your reply, will "
                  . "give them prices and a proposal. Do not promise a quotation from this chat.",
        };

        $lines = [
            "THE SALES PATH (for you only; never mention steps or this list)",
            "You lead this conversation like an experienced sales person, through eight steps in order: "
            . implode(' > ', array_map(static fn(int $n, string $s): string => $n . ' ' . $s, array_keys(self::STEPS), self::STEPS)) . ".",
            "You are at step " . $step . " of 8: " . self::STEPS[$step] . ".",
            "Now: " . $now,
            "Rules: the visitor leads. If they ask something, answer it fully first, then return to the path with at most "
            . "one question. Never ask for something listed as known below. Never ask two things in one reply.",
        ];
        if ($known) {
            $lines[] = "";
            $lines[] = "KNOWN ABOUT THEM";
            array_push($lines, ...$known);
        }
        if ($unknown) {
            $lines[] = "";
            $lines[] = "STILL UNKNOWN (listen for these; ask only when the path reaches them): " . implode('; ', $unknown) . ".";
        }
        return implode("\n", $lines);
    }

    /** Does the visitor ask for the next step: a quotation, a proposal, a call, a booking? */
    public static function asksNextStep(string $message): bool
    {
        return (bool)preg_match(
            '/\b(?:quotation|quote|proposal|pro-?forma|invoice|contract|book(?:ing)?\b|reserve|sign\s+up|go\s+ahead|proceed|'
            . 'next\s+steps?|call\s+(?:me|us)|speak\s+to|talk\s+to|meeting|how\s+do\s+(?:we|i)\s+(?:book|apply|register\s+as))\b/i',
            $message
        );
    }
}
