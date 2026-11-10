<?php
declare(strict_types=1);

/**
 * Conversation state machine (Sections 5, 6, 30).
 *
 * The model may suggest a stage. This class decides what actually happens.
 * Nothing the model returns can move the conversation into PRICING without
 * the pricing gate in ChatOrchestrator having been satisfied first.
 */
final class ConversationState
{
    public const NEW_CONVERSATION  = 'new';
    public const INTENT_DISCOVERY  = 'intent_discovery';
    public const GENERAL_VISITOR   = 'general_visitor';
    public const BUSINESS_QUALIFICATION = 'business_qualification';
    public const EDUCATION         = 'education';
    public const SALES_READINESS   = 'sales_readiness';
    public const GEO_CLASSIFICATION = 'geo_classification';
    public const PRICING           = 'pricing';
    public const LEAD_CAPTURE      = 'lead_capture';
    public const OBJECTION_HANDLING = 'objection_handling';
    public const HANDOFF           = 'handoff';
    public const COMPLETED         = 'completed';

    public const ALL = [
        self::NEW_CONVERSATION, self::INTENT_DISCOVERY, self::GENERAL_VISITOR,
        self::BUSINESS_QUALIFICATION, self::EDUCATION, self::SALES_READINESS,
        self::GEO_CLASSIFICATION, self::PRICING, self::LEAD_CAPTURE,
        self::OBJECTION_HANDLING, self::HANDOFF, self::COMPLETED,
    ];

    /** Human-readable names for the admin transcript view. */
    public const LABELS = [
        self::NEW_CONVERSATION       => 'Started',
        self::INTENT_DISCOVERY       => 'Working out what they want',
        self::GENERAL_VISITOR        => 'General visitor',
        self::BUSINESS_QUALIFICATION => 'Qualifying the business',
        self::EDUCATION              => 'Explaining exhibiting',
        self::SALES_READINESS        => 'Sales ready',
        self::GEO_CLASSIFICATION     => 'Establishing country',
        self::PRICING                => 'Pricing discussed',
        self::LEAD_CAPTURE           => 'Capturing contact details',
        self::OBJECTION_HANDLING     => 'Handling questions',
        self::HANDOFF                => 'Handed to sales',
        self::COMPLETED              => 'Completed',
    ];

    /** Stages from which an exhibitor conversation may move toward pricing. */
    public const EXHIBITOR_STAGES = [
        self::INTENT_DISCOVERY, self::BUSINESS_QUALIFICATION, self::EDUCATION,
        self::SALES_READINESS, self::GEO_CLASSIFICATION, self::PRICING,
        self::LEAD_CAPTURE, self::OBJECTION_HANDLING,
    ];

    /** Signals the readiness rule is built from (Section 30). */
    public const READINESS_SIGNALS = [
        'asked_for_price'              => 'Asked about price',
        'asked_for_stall_or_sponsorship' => 'Asked about stands or sponsorship',
        'provided_company'             => 'Gave their company',
        'provided_industry'            => 'Gave their industry or products',
        'specified_size_or_tier'       => 'Named a stand size or sponsorship tier',
        'asked_about_booking'          => 'Asked about booking',
        'requested_contact'            => 'Asked to be contacted',
    ];

    public static function isValid(?string $state): bool
    {
        return $state !== null && in_array($state, self::ALL, true);
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    // -----------------------------------------------------------------
    // Readiness (Section 30) — computed by PHP from concrete signals
    // -----------------------------------------------------------------

    /**
     * Recompute readiness signals from what is actually known.
     *
     * @param array $stored      previously accumulated signals
     * @param array $leadState   structured lead fields captured so far
     * @param string $message    visitor's latest message
     * @param array $llmSignals  validated model output for this turn
     * @return array<string,bool>
     */
    public static function computeSignals(array $stored, array $leadState, string $message, array $llmSignals): array
    {
        $signals = [];
        foreach (array_keys(self::READINESS_SIGNALS) as $key) {
            $signals[$key] = !empty($stored[$key]);
        }

        $text = mb_strtolower($message);

        // Signals derived from the visitor's own words. Once true, they stay true.
        if (self::mentions($text, ['price', 'pricing', 'cost', 'costs', 'rate', 'rates', 'how much', 'quotation', 'quote', 'charges', 'fee', 'fees', 'budget'])) {
            $signals['asked_for_price'] = true;
        }
        if (self::mentions($text, ['stall', 'stand', 'booth', 'exhibit', 'exhibiting', 'space', 'sponsor', 'sponsorship', 'pavilion'])) {
            $signals['asked_for_stall_or_sponsorship'] = true;
        }
        if (self::mentions($text, ['book', 'booking', 'reserve', 'reservation', 'sign up', 'apply', 'application', 'contract'])) {
            $signals['asked_about_booking'] = true;
        }
        if (self::mentions($text, ['call me', 'contact me', 'get in touch', 'reach me', 'email me', 'speak to someone', 'talk to someone', 'sales team', 'representative'])) {
            $signals['requested_contact'] = true;
        }
        if (preg_match('/\b\d{1,3}\s?(?:sqm|sq\.?\s?m|square\s?met(?:re|er)s?|m2|x\s?\d{1,3})\b/i', $message)) {
            $signals['specified_size_or_tier'] = true;
        }
        if (self::mentions($text, ['platinum', 'gold', 'silver', 'bronze', 'diamond', 'title sponsor', 'shell scheme', 'bare space', 'raw space', 'outdoor space', 'outdoor exhibit'])) {
            $signals['specified_size_or_tier'] = true;
        }

        // Signals derived from captured lead fields.
        if (!empty($leadState['company'])) {
            $signals['provided_company'] = true;
        }
        if (!empty($leadState['industry']) || !empty($leadState['product_category'])) {
            $signals['provided_industry'] = true;
        }

        // The model's pricing_request flag counts as an explicit price ask.
        if (!empty($llmSignals['pricing_request'])) {
            $signals['asked_for_price'] = true;
        }
        if (!empty($llmSignals['handoff_required'])) {
            $signals['requested_contact'] = true;
        }

        return $signals;
    }

    private static function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate the agent's configured readiness rule.
     * Never the model asserting readiness — always this calculation.
     */
    public static function isSalesReady(array $agent, array $signals): bool
    {
        $required = array_filter(array_map('trim', explode(',', (string)($agent['readiness_required_signals'] ?? ''))));
        foreach ($required as $key) {
            if (empty($signals[$key])) {
                return false;
            }
        }
        $min   = max(1, (int)($agent['readiness_min_signals'] ?? 2));
        $count = count(array_filter($signals));
        return $count >= $min;
    }

    public static function activeSignalLabels(array $signals): array
    {
        $out = [];
        foreach ($signals as $key => $on) {
            if ($on && isset(self::READINESS_SIGNALS[$key])) {
                $out[] = self::READINESS_SIGNALS[$key];
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    /**
     * Decide the next state. PHP-authoritative (Section 6).
     *
     * @param array $ctx {
     *   intent: string, pricing_requested: bool, country_known: bool,
     *   pricing_allowed: bool, pricing_shown: bool, handoff: bool,
     *   sales_ready: bool, contact_captured: bool, suggested: ?string
     * }
     */
    public static function next(string $current, array $ctx): string
    {
        $intent       = (string)($ctx['intent'] ?? 'unknown');
        $handoff      = (bool)($ctx['handoff'] ?? false);
        $priceAsked   = (bool)($ctx['pricing_requested'] ?? false);
        $countryKnown = (bool)($ctx['country_known'] ?? false);
        $priceAllowed = (bool)($ctx['pricing_allowed'] ?? false);
        $priceShown   = (bool)($ctx['pricing_shown'] ?? false);
        $ready        = (bool)($ctx['sales_ready'] ?? false);
        $suggested    = $ctx['suggested'] ?? null;

        if (!self::isValid($current)) {
            $current = self::NEW_CONVERSATION;
        }

        // A handoff outranks everything else — but only once there is a way
        // to reach the person. Until then the conversation stays in lead
        // capture, because a handoff with no contact details is no handoff.
        if ($handoff) {
            return (bool)($ctx['contact_captured'] ?? false)
                ? self::HANDOFF
                : self::LEAD_CAPTURE;
        }

        // Explicit pricing request short-circuits the normal progression
        // from any exhibitor-related stage (Section 5).
        if ($priceAsked && self::isExhibitorTrack($current, $intent)) {
            if (!$countryKnown) {
                return self::GEO_CLASSIFICATION;
            }
            return $priceAllowed ? self::PRICING : self::LEAD_CAPTURE;
        }

        // Non-exhibitor visitors stay on the general branch until exhibitor
        // intent becomes clear (Section 6).
        if (in_array($intent, ['attendee', 'media', 'job_seeker'], true)) {
            return self::GENERAL_VISITOR;
        }

        return match ($current) {
            self::NEW_CONVERSATION => self::INTENT_DISCOVERY,

            self::INTENT_DISCOVERY => in_array($intent, ['exhibitor', 'sponsor', 'partner'], true)
                ? self::BUSINESS_QUALIFICATION
                : self::INTENT_DISCOVERY,

            self::GENERAL_VISITOR => in_array($intent, ['exhibitor', 'sponsor', 'partner'], true)
                ? self::BUSINESS_QUALIFICATION
                : self::GENERAL_VISITOR,

            self::BUSINESS_QUALIFICATION => $ready
                ? self::SALES_READINESS
                : (self::suggestedOr($suggested, [self::EDUCATION], self::BUSINESS_QUALIFICATION)),

            self::EDUCATION => $ready ? self::SALES_READINESS : self::EDUCATION,

            self::SALES_READINESS => !$countryKnown
                ? self::GEO_CLASSIFICATION
                : ($priceAllowed ? self::PRICING : self::LEAD_CAPTURE),

            self::GEO_CLASSIFICATION => !$countryKnown
                ? self::GEO_CLASSIFICATION
                : ($priceAllowed ? self::PRICING : self::LEAD_CAPTURE),

            self::PRICING => self::suggestedOr(
                $suggested,
                [self::OBJECTION_HANDLING, self::LEAD_CAPTURE, self::HANDOFF],
                self::PRICING
            ),

            self::LEAD_CAPTURE => (bool)($ctx['contact_captured'] ?? false)
                ? self::suggestedOr($suggested, [self::OBJECTION_HANDLING, self::HANDOFF, self::COMPLETED], self::LEAD_CAPTURE)
                : self::LEAD_CAPTURE,

            self::OBJECTION_HANDLING => self::suggestedOr(
                $suggested,
                [self::PRICING, self::LEAD_CAPTURE, self::HANDOFF, self::COMPLETED],
                self::OBJECTION_HANDLING
            ),

            self::HANDOFF => self::suggestedOr($suggested, [self::COMPLETED], self::HANDOFF),

            default => $current,
        };
    }

    /**
     * Accept a model-suggested stage only when it is one of a small set of
     * sensible next steps from the current state.
     */
    private static function suggestedOr(?string $suggested, array $permitted, string $default): string
    {
        if ($suggested !== null && in_array($suggested, $permitted, true)) {
            return $suggested;
        }
        return $default;
    }

    private static function isExhibitorTrack(string $current, string $intent): bool
    {
        if (in_array($intent, ['exhibitor', 'sponsor', 'partner'], true)) {
            return true;
        }
        // An attendee who suddenly asks about stand prices is showing
        // exhibitor intent regardless of the earlier classification.
        return in_array($current, self::EXHIBITOR_STAGES, true);
    }

    /**
     * Stages at which the pricing gate may run a geo lookup.
     * Deliberately excludes the general-visitor branch (Section 11).
     */
    public static function mayTriggerGeo(string $state, string $intent, bool $pricingRequested): bool
    {
        if ($pricingRequested && !in_array($intent, ['attendee', 'media', 'job_seeker'], true)) {
            return true;
        }
        if ($pricingRequested && in_array($state, self::EXHIBITOR_STAGES, true)) {
            return true;
        }
        return in_array($state, [self::SALES_READINESS, self::GEO_CLASSIFICATION, self::PRICING], true);
    }
}
