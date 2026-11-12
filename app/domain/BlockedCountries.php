<?php
declare(strict_types=1);

/**
 * Blocked countries (owner, 12 Nov 2026).
 *
 * One list for the whole platform, kept under Set-up → Blocked countries. A
 * country on it is closed in every part of the sales agent:
 *   - it is not offered on the chat's first card;
 *   - a chat or WhatsApp conversation from it gets one polite fixed reply and
 *     nothing else: no AI, no research, no prices, no lead, no email to the team;
 *   - its companies are never researched for a campaign, emailed or sent a
 *     WhatsApp message, and its registrations get no first message or follow-up.
 *
 * It goes by what the person said or chose and by the country on a company or
 * registration record. The IP address is never used (owner's choice).
 *
 * Stored as ISO codes in the setting `blocked_countries` (JSON list).
 */
final class BlockedCountries
{
    private const KEY = 'blocked_countries';

    /** @var list<string>|null */
    private static ?array $codes = null;

    /** @return list<string> ISO codes, upper case */
    public static function codes(): array
    {
        if (self::$codes !== null) {
            return self::$codes;
        }
        $list = json_decode((string)Settings::get(self::KEY, '[]'), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $code) {
            $code = strtoupper(trim((string)$code));
            if (isset(CountryData::COUNTRIES[$code])) {
                $out[$code] = true;
            }
        }
        return self::$codes = array_keys($out);
    }

    /** @return list<string> country names, A to Z */
    public static function names(): array
    {
        $names = array_map(static fn(string $c): string => (string)CountryData::name($c), self::codes());
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** @param array<int,mixed> $codes */
    public static function save(array $codes): array
    {
        $before = self::codes();
        $keep = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string)$code));
            if (isset(CountryData::COUNTRIES[$code])) {
                $keep[$code] = true;
            }
        }
        $keep = array_keys($keep);
        sort($keep);
        Settings::set(self::KEY, json_encode($keep));
        self::$codes = $keep;

        $added   = array_diff($keep, $before);
        $removed = array_diff($before, $keep);
        if ($added || $removed) {
            Audit::log('blocked_countries_changed', null, 'settings', null,
                'Blocked countries now: ' . ($keep ? implode(', ', self::names()) : 'none'));
        }
        return ['added' => array_values($added), 'removed' => array_values($removed)];
    }

    /** For tests, and after the setting is changed elsewhere. */
    public static function forget(): void
    {
        self::$codes = null;
    }

    /** The ISO code of a country given as a code or a name; null when it is not a country. */
    public static function codeOf(?string $country): ?string
    {
        $country = trim((string)$country);
        if ($country === '') {
            return null;
        }
        if (strlen($country) === 2 && isset(CountryData::COUNTRIES[strtoupper($country)])) {
            return strtoupper($country);
        }
        $resolved = GeoClassifier::resolveDisclosed($country);
        return $resolved['code'] !== null && empty($resolved['ambiguous']) ? (string)$resolved['code'] : null;
    }

    /** Is this country (a code or a name) on the list? */
    public static function blocks(?string $country): bool
    {
        if (self::codes() === []) {
            return false;
        }
        $code = self::codeOf($country);
        return $code !== null && in_array($code, self::codes(), true);
    }

    /** A number whose dialling code belongs to a blocked country. */
    public static function blocksPhone(?string $e164): bool
    {
        if (self::codes() === [] || $e164 === null || $e164 === '') {
            return false;
        }
        return self::blocks(Phone::countryHint($e164));
    }

    /**
     * A conversation counts by the company's country when it is known, and by
     * the person's own country otherwise. Never by the IP address.
     */
    public static function blocksConversation(array $conversation): bool
    {
        if (self::codes() === []) {
            return false;
        }
        $company = trim((string)($conversation['disclosed_company_country'] ?? ''));
        if ($company !== '') {
            return self::blocks($company);
        }
        return self::blocks((string)($conversation['disclosed_country'] ?? ''));
    }

    /** A company record: its own country, or the country research found for it. */
    public static function blocksCompany(int $agentId, array $company): bool
    {
        if (self::codes() === []) {
            return false;
        }
        if (self::blocks((string)($company['country'] ?? ''))) {
            return true;
        }
        $id = (int)($company['id'] ?? 0);
        if ($id > 0 && trim((string)($company['country'] ?? '')) === '') {
            $found = db()->value(
                "SELECT company_country FROM company_research
                  WHERE agent_id = ? AND company_id = ? AND company_country IS NOT NULL AND company_country <> ''
                  ORDER BY id DESC LIMIT 1",
                [$agentId, $id]
            );
            return self::blocks($found === null ? null : (string)$found);
        }
        return false;
    }
}
