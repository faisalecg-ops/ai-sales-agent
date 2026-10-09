<?php
declare(strict_types=1);

/**
 * Country classification for pricing eligibility (Sections 10–14).
 *
 * This class alone decides pricing_country. The model may report what a
 * visitor said; it never decides what that means.
 *
 * Rules enforced here:
 *  - Geo-IP never runs at session start (Section 11) — only when asked.
 *  - A known country skips the IP lookup entirely (Section 11, 12).
 *  - A visitor-disclosed country overrides an earlier IP result, and the
 *    original IP result is retained internally for audit (Section 12).
 *  - The exhibiting company's location outranks the visitor's own
 *    location whenever the two differ (Section 14).
 *  - Anything unreliable resolves to Undetermined, which is treated
 *    exactly like Pakistan for pricing purposes (Section 4.4, 16).
 */
final class GeoClassifier
{
    public const PAKISTAN     = 'pakistan';
    public const INTERNATIONAL = 'international';
    public const UNDETERMINED  = 'undetermined';

    public const SOURCE_DISCLOSED = 'visitor_disclosed';
    public const SOURCE_IP        = 'ip_geo';
    public const SOURCE_NONE      = 'undetermined';

    public static function countryName(string $code): ?string
    {
        return CountryData::name($code);
    }

    /** Country code to pricing classification. */
    public static function classifyCode(?string $countryCode): string
    {
        if ($countryCode === null || $countryCode === '') {
            return self::UNDETERMINED;
        }
        return strtoupper($countryCode) === 'PK' ? self::PAKISTAN : self::INTERNATIONAL;
    }

    /**
     * Resolve free text a visitor used for a location into a country code.
     *
     * @return array{code:?string,name:?string,ambiguous:bool,matched:?string}
     */
    public static function resolveDisclosed(?string $text): array
    {
        $none = ['code' => null, 'name' => null, 'ambiguous' => false, 'matched' => null];
        if ($text === null) {
            return $none;
        }

        $norm = self::normalise($text);
        if ($norm === '') {
            return $none;
        }

        // An ambiguous place name must never classify pricing — ask instead.
        foreach (CountryData::AMBIGUOUS_PLACES as $place) {
            if (self::containsPhrase($norm, $place)) {
                // Unless the text also names a country outright, e.g. "Hyderabad, Pakistan".
                $country = self::matchCountry($norm);
                if ($country !== null) {
                    return [
                        'code' => $country, 'name' => CountryData::name($country),
                        'ambiguous' => false, 'matched' => $norm,
                    ];
                }
                return ['code' => null, 'name' => null, 'ambiguous' => true, 'matched' => $place];
            }
        }

        $country = self::matchCountry($norm);
        if ($country !== null) {
            return [
                'code' => $country, 'name' => CountryData::name($country),
                'ambiguous' => false, 'matched' => $norm,
            ];
        }

        // City lookup, longest phrase first so "kuwait city" beats "kuwait".
        $cities = CountryData::CITIES;
        uksort($cities, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($cities as $city => $code) {
            if (self::containsPhrase($norm, $city)) {
                return [
                    'code' => $code, 'name' => CountryData::name($code),
                    'ambiguous' => false, 'matched' => $city,
                ];
            }
        }

        return $none;
    }

    /** Match a country name, alias or demonym inside normalised text. */
    private static function matchCountry(string $norm): ?string
    {
        // Exact two-letter code only when the whole string is the code.
        if (preg_match('/^[a-z]{2}$/', $norm) && isset(CountryData::COUNTRIES[strtoupper($norm)])) {
            return strtoupper($norm);
        }

        $candidates = [];
        foreach (CountryData::COUNTRIES as $code => $name) {
            $candidates[self::normalise($name)] = $code;
        }
        foreach (CountryData::ALIASES as $alias => $code) {
            if ($code !== null) {
                $candidates[self::normalise($alias)] = $code;
            }
        }
        uksort($candidates, static fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($candidates as $phrase => $code) {
            if ($phrase !== '' && self::containsPhrase($norm, $phrase)) {
                return $code;
            }
        }
        return null;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** Whole-word phrase containment, so "us" does not match "business". */
    private static function containsPhrase(string $haystack, string $needle): bool
    {
        $needle = self::normalise($needle);
        if ($needle === '') {
            return false;
        }
        return (bool)preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $haystack);
    }

    /**
     * Apply a country the visitor stated, honouring the company-over-personal
     * rule. Returns the values to persist on the conversation.
     *
     * @return array{
     *   applied:bool, classification:string, source:string,
     *   country_code:?string, country_name:?string,
     *   needs_clarification:bool, clarification_reason:?string
     * }
     */
    public static function applyDisclosed(
        array $conversation,
        ?string $personalText,
        ?string $companyText
    ): array {
        $company  = self::resolveDisclosed($companyText);
        $personal = self::resolveDisclosed($personalText);

        // Company location governs whenever it is known (Section 14).
        $chosen = $company['code'] !== null ? $company : $personal;
        $usedCompany = $company['code'] !== null;

        if ($chosen['code'] === null) {
            $ambiguous = $company['ambiguous'] || $personal['ambiguous'];
            return [
                'applied'              => false,
                'classification'       => (string)($conversation['pricing_country'] ?? self::UNDETERMINED),
                'source'               => (string)($conversation['country_source'] ?? self::SOURCE_NONE),
                'country_code'         => null,
                'country_name'         => null,
                'needs_clarification'  => $ambiguous,
                'clarification_reason' => $ambiguous ? 'ambiguous_place_name' : null,
            ];
        }

        // Stated personal and company locations differ: company wins, but the
        // agent should confirm which applies when only the personal one was given
        // in a way that suggests a mismatch.
        $mismatch = $company['code'] !== null
            && $personal['code'] !== null
            && $company['code'] !== $personal['code'];

        return [
            'applied'              => true,
            'classification'       => self::classifyCode($chosen['code']),
            'source'               => self::SOURCE_DISCLOSED,
            'country_code'         => $chosen['code'],
            'country_name'         => $chosen['name'],
            'needs_clarification'  => false,
            'clarification_reason' => $mismatch ? 'company_location_used' : ($usedCompany ? null : null),
        ];
    }

    /**
     * Run an IP lookup. Only called by the pricing gate, never at session start.
     *
     * @return array{classification:string,source:string,country_code:?string,country_name:?string,status:string,failure_reason:?string,provider:string,latency_ms:int}
     */
    public static function lookupByIp(array $agent, array $conversation, string $ip): array
    {
        $provider = self::provider((string)($agent['geo_provider'] ?? 'ipwhois'));

        // A private or reserved address cannot be classified (Section 13).
        if (Security::isPrivateIp($ip)) {
            $result = [
                'country_code' => null, 'country_name' => null, 'status' => 'failed',
                'provider' => $provider->name(), 'timestamp' => gmdate('c'),
                'failure_reason' => 'private_or_reserved_ip', 'latency_ms' => 0,
            ];
        } else {
            try {
                $result = $provider->lookup($ip);
            } catch (Throwable $ex) {
                app_log('geo', 'provider threw', ['provider' => $provider->name(), 'error' => $ex->getMessage()]);
                $result = [
                    'country_code' => null, 'country_name' => null, 'status' => 'failed',
                    'provider' => $provider->name(), 'timestamp' => gmdate('c'),
                    'failure_reason' => 'provider_exception', 'latency_ms' => 0,
                ];
            }
        }

        $classification = $result['status'] === 'ok'
            ? self::classifyCode($result['country_code'])
            : self::UNDETERMINED;

        // Record the lookup for audit, with its own retention clock.
        $retainDays = (int)cfg('retention.raw_ip_days', 365);
        try {
            db()->insert('geo_lookups', [
                'agent_id'        => (int)$agent['id'],
                'conversation_id' => isset($conversation['id']) ? (int)$conversation['id'] : null,
                'ip'             => $retainDays > 0 ? $ip : null,
                'country_code'   => $result['country_code'],
                'country_name'   => $result['country_name'],
                'classification' => $classification,
                'provider'       => $result['provider'],
                'status'         => $result['status'],
                'failure_reason' => $result['failure_reason'],
                'latency_ms'     => $result['latency_ms'],
                'retain_until'   => $retainDays > 0 ? gmdate('Y-m-d', time() + ($retainDays * 86400)) : null,
            ]);
        } catch (Throwable $ex) {
            app_log('geo', 'lookup log failed', ['error' => $ex->getMessage()]);
        }

        if ($result['status'] !== 'ok') {
            app_log('geo', 'classification undetermined', [
                'agent'    => (int)$agent['id'],
                'provider' => $result['provider'],
                'reason'   => $result['failure_reason'],
            ]);
        }

        return [
            'classification' => $classification,
            'source'         => $result['status'] === 'ok' ? self::SOURCE_IP : self::SOURCE_NONE,
            'country_code'   => $result['country_code'],
            'country_name'   => $result['country_name'],
            'status'         => $result['status'],
            'failure_reason' => $result['failure_reason'],
            'provider'       => $result['provider'],
            'latency_ms'     => $result['latency_ms'],
        ];
    }

    public static function provider(string $name): GeoProvider
    {
        return match ($name) {
            'ipinfo'  => new IpInfoGeoProvider(),
            'ipapico' => new IpApiCoGeoProvider(),
            'none'    => new NullGeoProvider(),
            default   => new IpWhoIsGeoProvider(),
        };
    }

    /** True when the conversation already has a usable classification. */
    public static function isKnown(array $conversation): bool
    {
        $c = (string)($conversation['pricing_country'] ?? self::UNDETERMINED);
        $s = (string)($conversation['country_source'] ?? self::SOURCE_NONE);
        if ($s === self::SOURCE_DISCLOSED) {
            return true;
        }
        return $s === self::SOURCE_IP && $c !== self::UNDETERMINED;
    }

    /** May this classification see international pricing? (Sections 15, 16) */
    public static function maySeeInternationalPricing(string $classification): bool
    {
        return $classification === self::INTERNATIONAL;
    }

    /** Region groups whose records are eligible for this classification. */
    public static function regionGroupsFor(string $classification): array
    {
        return match ($classification) {
            self::INTERNATIONAL => ['international', 'both'],
            self::PAKISTAN      => ['pakistan', 'both'],
            default             => [],   // Undetermined sees no pricing at all
        };
    }
}
