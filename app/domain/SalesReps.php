<?php
declare(strict_types=1);

/**
 * The two people a visitor can be put in touch with, for each exhibition:
 * one for companies in Pakistan, one for companies elsewhere.
 *
 * Shared with a visitor whenever a person is needed — they asked for one,
 * asked for a discount, complained, raised a contract or payment, or the
 * assistant could not answer. Never shared otherwise: these are real
 * people's direct numbers.
 *
 * Which one: the same split the notification emails use. Pakistan is local;
 * a known country elsewhere is international. When the visitor's country is
 * not known yet, both are given, each labelled — guessing wrong would send an
 * overseas company to the local desk, or the reverse.
 */
final class SalesReps
{
    public const REGIONS = ['local', 'international'];

    public const FIELDS = ['name', 'designation', 'email', 'mobile', 'whatsapp', 'wechat'];

    /** @return array<string,?string> the rep for one region, blank fields as null */
    public static function get(int $agentId, string $region): array
    {
        $row = db()->one(
            'SELECT * FROM agent_sales_reps WHERE agent_id = ? AND region = ?',
            [$agentId, $region]
        ) ?? [];

        $out = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string)($row[$field] ?? ''));
            $out[$field] = $value === '' ? null : $value;
        }
        return $out;
    }

    /**
     * Save one region's rep, checking each detail.
     *
     * @return array<int,string> problems, in plain words; empty when saved
     */
    public static function save(int $agentId, string $region, array $input): array
    {
        if (!in_array($region, self::REGIONS, true)) {
            return ['Unknown region.'];
        }

        $problems = self::check($region, $input);
        if ($problems) {
            return $problems;
        }
        $clean = [];

        $clean['name']        = self::text($input['name'] ?? null, 120);
        $clean['designation'] = self::text($input['designation'] ?? null, 120);
        $clean['wechat']      = self::text($input['wechat'] ?? null, 64);

        $email          = trim((string)($input['email'] ?? ''));
        $clean['email'] = $email === '' ? null : Validator::email($email);
        foreach (['mobile', 'whatsapp'] as $field) {
            $typed         = trim((string)($input[$field] ?? ''));
            $clean[$field] = $typed === '' ? null : Phone::fromInternational($typed);
        }

        db()->exec(
            'INSERT INTO agent_sales_reps (agent_id, region, name, designation, email, mobile, whatsapp, wechat)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), designation = VALUES(designation),
                email = VALUES(email), mobile = VALUES(mobile), whatsapp = VALUES(whatsapp),
                wechat = VALUES(wechat)',
            [$agentId, $region, $clean['name'], $clean['designation'], $clean['email'],
             $clean['mobile'], $clean['whatsapp'], $clean['wechat']]
        );

        return [];
    }

    /**
     * What is wrong with one region's details, without saving anything.
     *
     * @return array<int,string>
     */
    public static function check(string $region, array $input): array
    {
        $label    = $region === 'local' ? 'Local rep' : 'International rep';
        $problems = [];

        $email = trim((string)($input['email'] ?? ''));
        if ($email !== '' && Validator::email($email) === null) {
            $problems[] = $label . ': the email address does not look right.';
        }
        foreach (['mobile' => 'mobile number', 'whatsapp' => 'WhatsApp number'] as $field => $words) {
            $typed = trim((string)($input[$field] ?? ''));
            if ($typed !== '' && Phone::fromInternational($typed) === null) {
                $problems[] = $label . ': the ' . $words . ' could not be read. Include the country code, '
                            . 'for example +92 300 1234567.';
            }
        }
        $filled = array_filter(self::FIELDS, static fn($f) => trim((string)($input[$f] ?? '')) !== '');
        if ($filled && trim((string)($input['name'] ?? '')) === '') {
            $problems[] = $label . ': add a name — visitors are told who they are contacting.';
        }

        return $problems;
    }

    /** Whether a rep has enough to be worth giving to a visitor: a name and a way to reach them. */
    public static function isUsable(array $rep): bool
    {
        return ($rep['name'] ?? null) !== null
            && (($rep['email'] ?? null) !== null || ($rep['mobile'] ?? null) !== null
                || ($rep['whatsapp'] ?? null) !== null || ($rep['wechat'] ?? null) !== null);
    }

    /**
     * Which rep(s) this visitor should be given, labelled when there are two.
     *
     * @return array<int,array{label: ?string, rep: array}>
     */
    public static function forVisitor(int $agentId, ?string $pricingCountry): array
    {
        $local = self::get($agentId, 'local');
        $intl  = self::get($agentId, 'international');

        return match ((string)$pricingCountry) {
            GeoClassifier::PAKISTAN      => self::isUsable($local) ? [['label' => null, 'rep' => $local]] : [],
            GeoClassifier::INTERNATIONAL => self::isUsable($intl) ? [['label' => null, 'rep' => $intl]] : [],
            default => array_values(array_filter([
                self::isUsable($local) ? ['label' => 'For companies based in Pakistan', 'rep' => $local] : null,
                self::isUsable($intl) ? ['label' => 'For companies based outside Pakistan', 'rep' => $intl] : null,
            ])),
        };
    }

    /**
     * The rep details as a message.
     *
     * `$channel` is 'whatsapp' (bold with *…*) or 'web' (bold with **…**, which
     * the chat window renders). Empty when no rep is set up, so the caller's
     * message simply goes without it.
     */
    public static function textFor(int $agentId, ?string $pricingCountry, string $channel): string
    {
        $entries = self::forVisitor($agentId, $pricingCountry);
        if (!$entries) {
            return '';
        }

        $bold  = static fn(string $t): string => $channel === 'whatsapp' ? '*' . $t . '*' : '**' . $t . '**';
        $parts = [];

        foreach ($entries as $entry) {
            $rep   = $entry['rep'];
            $lines = [];

            if ($entry['label'] !== null) {
                $lines[] = $entry['label'] . ':';
            }

            $lines[] = $bold((string)$rep['name'])
                     . ($rep['designation'] !== null ? ', ' . $rep['designation'] : '');

            if ($rep['mobile'] !== null) {
                $lines[] = 'Mobile: ' . $rep['mobile'];
            }
            if ($rep['whatsapp'] !== null) {
                $lines[] = 'WhatsApp: ' . $rep['whatsapp'];
            }
            if ($rep['email'] !== null) {
                $lines[] = 'Email: ' . $rep['email'];
            }
            if ($rep['wechat'] !== null) {
                $lines[] = 'WeChat: ' . $rep['wechat'];
            }

            $parts[] = implode("\n", $lines);
        }

        $intro = count($entries) === 1 ? 'You can also reach them directly:' : 'You can reach our team directly:';

        return $intro . "\n\n" . implode("\n\n", $parts);
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
