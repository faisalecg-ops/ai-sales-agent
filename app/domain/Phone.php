<?php
declare(strict_types=1);

/**
 * Phone numbers, reduced to one canonical form.
 *
 * Registration forms collect numbers written every way a person might type
 * one: with spaces, dashes, dots, brackets, a leading zero, a country code,
 * two numbers in one box, a capital O where a zero belongs, or — after a trip
 * through a spreadsheet — as 9.23323E+11.
 *
 * A messaging provider needs exactly one form, and a suppression list is
 * worthless if the same person fails to match because they typed it
 * differently last time. So everything is reduced to +<digits>.
 *
 * The rule throughout: repair only what is unambiguous, and refuse the rest.
 * A number guessed wrongly does not fail quietly — it sends a stranger a
 * message meant for somebody else.
 */
final class Phone
{
    /**
     * How a national number is written, per country.
     *
     * [dialling code, shortest national length, longest, mobile prefixes]
     *
     * Every row is a claim that we know how that country writes its mobile
     * numbers. A wrong row sends messages to the wrong people, so a country
     * is listed only when the pattern is settled. Where the mobile prefixes
     * are null we cannot tell a mobile from a landline and accept either.
     */
    private const COUNTRY_RULES = [
        'pakistan'             => ['92',  10, 10, ['3']],
        'united arab emirates' => ['971',  9,  9, ['5']],
        'saudi arabia'         => ['966',  9,  9, ['5']],
        'qatar'                => ['974',  8,  8, ['3', '5', '6', '7']],
        'oman'                 => ['968',  8,  8, ['7', '9']],
        'bahrain'              => ['973',  8,  8, ['3']],
        'kuwait'               => ['965',  8,  8, ['5', '6', '9']],
        'turkey'               => ['90',  10, 10, ['5']],
        'iran'                 => ['98',  10, 10, ['9']],
        'iraq'                 => ['964', 10, 10, ['7']],
        'jordan'               => ['962',  9,  9, ['7']],
        'egypt'                => ['20',  10, 10, ['1']],
        'afghanistan'          => ['93',   9,  9, ['7']],
        'india'                => ['91',  10, 10, ['6', '7', '8', '9']],
        'bangladesh'           => ['880', 10, 10, ['1']],
        'sri lanka'            => ['94',   9,  9, ['7']],
        'nepal'                => ['977', 10, 10, ['9']],
        'china'                => ['86',  11, 11, ['1']],
        'malaysia'             => ['60',   9, 10, ['1']],
        'indonesia'            => ['62',   9, 12, ['8']],
        'singapore'            => ['65',   8,  8, ['8', '9']],
        'thailand'             => ['66',   9,  9, ['6', '8', '9']],
        'vietnam'              => ['84',   9,  9, ['3', '5', '7', '8', '9']],
        'philippines'          => ['63',  10, 10, ['9']],
        'south korea'          => ['82',   9, 10, ['1']],
        'japan'                => ['81',  10, 10, ['7', '8', '9']],
        'united kingdom'       => ['44',  10, 10, ['7']],
        'united states'        => ['1',   10, 10, null],
        'canada'               => ['1',   10, 10, null],
        'germany'              => ['49',  10, 11, ['1']],
        'france'               => ['33',   9,  9, ['6', '7']],
        'italy'                => ['39',   9, 10, ['3']],
        'spain'                => ['34',   9,  9, ['6', '7']],
        'netherlands'          => ['31',   9,  9, ['6']],
        'belgium'              => ['32',   9,  9, ['4']],
        'poland'               => ['48',   9,  9, ['4', '5', '6', '7', '8']],
        'russia'               => ['7',   10, 10, ['9']],
        'ukraine'              => ['380',  9,  9, ['3', '5', '6', '9']],
        'australia'            => ['61',   9,  9, ['4']],
        'south africa'         => ['27',   9,  9, ['6', '7', '8']],
        'nigeria'              => ['234', 10, 10, ['7', '8', '9']],
        'kenya'                => ['254',  9,  9, ['1', '7']],
        'brazil'               => ['55',  10, 11, ['6', '7', '8', '9']],
        'sudan'                => ['249',  9,  9, ['9', '1']],
    ];

    /** The many ways a country's name is typed into a form. */
    private const COUNTRY_ALIASES = [
        'uae' => 'united arab emirates', 'u.a.e' => 'united arab emirates',
        'u.a.e.' => 'united arab emirates', 'dubai' => 'united arab emirates',
        'abu dhabi' => 'united arab emirates', 'sharjah' => 'united arab emirates',
        'ksa' => 'saudi arabia', 'kingdom of saudi arabia' => 'saudi arabia',
        'saudia' => 'saudi arabia', 'saudi' => 'saudi arabia',
        'uk' => 'united kingdom', 'u.k' => 'united kingdom', 'britain' => 'united kingdom',
        'great britain' => 'united kingdom', 'england' => 'united kingdom',
        'usa' => 'united states', 'u.s.a' => 'united states', 'us' => 'united states',
        'united states of america' => 'united states', 'america' => 'united states',
        'pak' => 'pakistan', 'pakistán' => 'pakistan', 'islamic republic of pakistan' => 'pakistan',
        'korea' => 'south korea', 'republic of korea' => 'south korea',
        'holland' => 'netherlands', 'deutschland' => 'germany', 'türkiye' => 'turkey',
        'turkiye' => 'turkey', 'prc' => 'china', 'peoples republic of china' => 'china',
        'srilanka' => 'sri lanka', 'bangla desh' => 'bangladesh',
    ];

    /** Separators that mean "here is another number", not "part of this one". */
    private const NUMBER_SEPARATORS = ['/', ',', ';', '|', ' or ', ' and ', '&', '\\'];

    // -----------------------------------------------------------------
    // The main entry point
    // -----------------------------------------------------------------

    /**
     * Turn whatever was typed into +<digits>, or null.
     *
     * $country is what the person said about themselves. It is used only to
     * complete a number that carries no international prefix of its own, and
     * never to override one that does.
     *
     * $mobileOnly refuses landlines, which is right for messaging and wrong
     * for simply recording a contact number.
     */
    public static function toE164(?string $raw, ?string $country = null, bool $mobileOnly = true): ?string
    {
        return self::inspect($raw, $country, $mobileOnly)['number'];
    }

    /**
     * The same, but saying why when it fails.
     *
     * Worth having because a screen showing "no usable number" against two
     * thousand registrations is far less useful than one that separates the
     * landlines from the spreadsheet damage from the genuinely empty.
     *
     * @return array{number: ?string, reason: string, detail: string}
     */
    public static function inspect(?string $raw, ?string $country = null, bool $mobileOnly = true): array
    {
        $input = trim((string)$raw);
        if ($input === '') {
            return self::outcome(null, 'empty', 'Nothing was entered.');
        }

        $input = self::toAsciiDigits($input);

        // A number that has been through a spreadsheet and come out as
        // 9.23323E+11 has genuinely lost its digits. Nothing can recover it,
        // and the original form is the only place to look.
        if (preg_match('/\d(?:\.\d+)?\s*[eE]\s*\+?\s*\d+/', $input) === 1) {
            return self::outcome(null, 'spreadsheet',
                'Damaged by a spreadsheet, so the real number is gone.');
        }

        // Two numbers in one box is common. Take the first that reads as a
        // number rather than discarding both.
        foreach (self::candidates($input) as $index => $candidate) {
            $parsed = self::parseOne($candidate, $country, $mobileOnly);
            if ($parsed['number'] !== null) {
                if ($index > 0) {
                    $parsed['detail'] = 'Read the first of several numbers in the box.';
                }
                return $parsed;
            }
            $firstFailure = $firstFailure ?? $parsed;
        }

        return $firstFailure ?? self::outcome(null, 'unreadable', 'Could not be read as a number.');
    }

    // -----------------------------------------------------------------
    // One candidate number
    // -----------------------------------------------------------------

    /** @return array{number: ?string, reason: string, detail: string} */
    private static function parseOne(string $input, ?string $country, bool $mobileOnly): array
    {
        $hadPlus = str_starts_with(ltrim($input), '+');
        $digits  = preg_replace('/[^0-9]/', '', $input) ?? '';

        if ($digits === '') {
            return self::outcome(null, 'unreadable', 'No digits in it.');
        }

        // 00 is how much of the world writes +.
        if (!$hadPlus && str_starts_with($digits, '00')) {
            $digits  = substr($digits, 2);
            $hadPlus = true;
        }

        if (strlen($digits) > 15) {
            return self::outcome(null, 'too_long',
                'Too many digits — probably two numbers run together.');
        }

        $rule = self::ruleFor($country);

        // Already international.
        if ($hadPlus) {
            return self::finish($digits, $mobileOnly);
        }

        // Carries its country's dialling code but no plus. Checked before the
        // national reading, because "923001234567" is the former.
        //
        // The trunk zero is sometimes written after the code as well —
        // "92-0300-1234567" — so that form is tried too. The length check is
        // what keeps a bare national number from being misread as this: an
        // Indian mobile starting 91 is ten digits, the international form is
        // twelve.
        if ($rule !== null) {
            [$code] = $rule;
            if (str_starts_with($digits, $code)) {
                foreach ([$digits, $code . ltrim(substr($digits, strlen($code)), '0')] as $attempt) {
                    if (self::fitsRule($attempt, $rule, $mobileOnly)) {
                        return self::finish($attempt, $mobileOnly);
                    }
                }
            }
        }

        // A national number, with or without its trunk zero.
        if ($rule !== null) {
            [$code, $min, $max, $mobilePrefixes] = $rule;
            $national = ltrim($digits, '0');

            if (strlen($national) < $min || strlen($national) > $max) {
                return self::outcome(null, 'wrong_length',
                    'Has ' . strlen($national) . ' digits; a number there has '
                    . ($min === $max ? (string)$min : $min . ' to ' . $max) . '.');
            }

            if ($mobileOnly && $mobilePrefixes !== null && !self::startsWithAny($national, $mobilePrefixes)) {
                return self::outcome(null, 'landline',
                    'Looks like a landline, which WhatsApp cannot reach.');
            }

            return self::finish($code . $national, $mobileOnly);
        }

        // Nothing to complete it with.
        return self::outcome(null, 'no_country',
            'No country code, and their country was not given, so it cannot be completed.');
    }

    /**
     * Final checks on a number that already carries its dialling code.
     *
     * The "(0)" convention — +92 (0) 300 1234567 — writes the country code
     * and the trunk zero together, which is meaningless internationally. Left
     * alone it produces +9203001234567: a number of plausible length that
     * belongs to nobody.
     *
     * @return array{number: ?string, reason: string, detail: string}
     */
    private static function finish(string $digits, bool $mobileOnly): array
    {
        $country = self::countryOf($digits);

        if ($country !== null) {
            $rule = self::COUNTRY_RULES[$country];
            [$code, $min, $max, $mobilePrefixes] = $rule;

            $national = substr($digits, strlen($code));

            // Drop a trunk zero written after the country code.
            if (str_starts_with($national, '0')) {
                $national = ltrim($national, '0');
                $digits   = $code . $national;
            }

            if (strlen($national) < $min || strlen($national) > $max) {
                return self::outcome(null, 'wrong_length',
                    'Has ' . strlen($national) . ' digits after the country code; '
                    . 'a number there has ' . ($min === $max ? (string)$min : $min . ' to ' . $max) . '.');
            }

            if ($mobileOnly && $mobilePrefixes !== null && !self::startsWithAny($national, $mobilePrefixes)) {
                return self::outcome(null, 'landline',
                    'Looks like a landline, which WhatsApp cannot reach.');
            }
        }

        $final = self::validate($digits);

        return $final === null
            ? self::outcome(null, 'wrong_length', 'Not a plausible length for a phone number.')
            : self::outcome($final, 'ok', $country === null
                ? 'Read as an international number.'
                : 'Read as a ' . ucwords($country) . ' number.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The separate numbers in one box.
     *
     * Only obvious separators are honoured. A space is not one of them: it
     * appears inside numbers far more often than between them, and splitting
     * on it would turn "0300 1234567" into two useless fragments.
     */
    private static function candidates(string $input): array
    {
        $working = ' ' . mb_strtolower($input) . ' ';
        foreach (self::NUMBER_SEPARATORS as $separator) {
            $working = str_replace($separator, "\x1F", $working);
        }

        $parts = [];
        foreach (explode("\x1F", $working) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/\d/', $part) === 1) {
                $parts[] = $part;
            }
        }

        return $parts ?: [$input];
    }

    /**
     * Arabic-Indic and Persian digits written into a Latin form.
     *
     * Someone filling in a form on an Arabic or Urdu keyboard produces digits
     * that are numerals to them and punctuation to a naive parser.
     */
    private static function toAsciiDigits(string $text): string
    {
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];
        $text = strtr($text, $map);

        // A capital O typed for a zero is common, but only inside a number.
        // Replacing every O would turn the word "or" — which separates two
        // numbers in one box — into "0r", and lose the second number.
        return preg_replace('/(?<=\d)[Oo]|[Oo](?=\d)/u', '0', $text) ?? $text;
    }

    /** Which country a set of digits beginning with a dialling code belongs to. */
    private static function countryOf(string $digits): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach (self::COUNTRY_RULES as $name => [$code, $min, $max, $prefixes]) {
            if (!str_starts_with($digits, $code)) {
                continue;
            }
            // The longest matching code wins, so 971 is not read as 97.
            if (strlen($code) > $bestLength) {
                $best = $name;
                $bestLength = strlen($code);
            }
        }

        return $best;
    }

    private static function fitsRule(string $digits, array $rule, bool $mobileOnly): bool
    {
        [$code, $min, $max, $mobilePrefixes] = $rule;
        $national = substr($digits, strlen($code));

        if (strlen($national) < $min || strlen($national) > $max) {
            return false;
        }
        if ($mobileOnly && $mobilePrefixes !== null && !self::startsWithAny($national, $mobilePrefixes)) {
            return false;
        }
        return true;
    }

    private static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:int,2:int,3:?array}|null */
    private static function ruleFor(?string $country): ?array
    {
        $key = mb_strtolower(trim((string)$country));
        if ($key === '') {
            return null;
        }
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;
        $key = self::COUNTRY_ALIASES[$key] ?? $key;

        return self::COUNTRY_RULES[$key] ?? null;
    }

    private static function validate(string $digits): ?string
    {
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            return null;
        }
        return '+' . $digits;
    }

    /** @return array{number: ?string, reason: string, detail: string} */
    private static function outcome(?string $number, string $reason, string $detail): array
    {
        return ['number' => $number, 'reason' => $reason, 'detail' => $detail];
    }

    // -----------------------------------------------------------------
    // Public odds and ends
    // -----------------------------------------------------------------

    /**
     * A number that already carries its country code, however it was typed.
     *
     * Used for the exhibition's own WhatsApp number, where there is no
     * registration country to fall back on and the operator is expected to
     * include the code.
     */
    public static function fromInternational(?string $raw): ?string
    {
        $input = self::toAsciiDigits(trim((string)$raw));
        if ($input === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $input) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        // Landlines are allowed here: it is an office number, not a target.
        return self::finish($digits, false)['number'];
    }

    /** True when a number is in a form a messaging provider will accept. */
    public static function isReachable(?string $e164): bool
    {
        return $e164 !== null && preg_match('/^\+[1-9][0-9]{7,14}$/', $e164) === 1;
    }

    /**
     * The country a number belongs to, by its dialling code.
     *
     * A hint for routing an enquiry to the right desk, and never a basis for
     * deciding which prices someone may see — people carry numbers from
     * countries they no longer live in.
     */
    public static function countryHint(?string $e164): ?string
    {
        if (!self::isReachable($e164)) {
            return null;
        }
        $country = self::countryOf(ltrim((string)$e164, '+'));
        return $country === null ? null : ucwords($country);
    }

    /** Plain wording for why a number could not be used. */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'ok'           => 'Usable',
            'empty'        => 'No number given',
            'spreadsheet'  => 'Damaged by a spreadsheet',
            'landline'     => 'Landline, not a mobile',
            'wrong_length' => 'Wrong number of digits',
            'too_long'     => 'Two numbers in one box',
            'no_country'   => 'No country code given',
            default        => 'Could not be read',
        };
    }

    /** Enough to recognise a number in a list, without printing it whole. */
    public static function mask(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }
        $len = strlen($e164);
        if ($len <= 7) {
            return $e164;
        }
        return substr($e164, 0, 4) . str_repeat('*', $len - 7) . substr($e164, -3);
    }
}
