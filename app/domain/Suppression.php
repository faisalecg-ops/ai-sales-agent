<?php
declare(strict_types=1);

/**
 * The do-not-contact list.
 *
 * This is the one record in the platform that is meant to outlive everything
 * around it. It holds no link to a company, a lead or a campaign, because its
 * whole purpose is to remember a person or business whose record you may no
 * longer keep. Deleting someone's data must never quietly re-open the door to
 * contacting them.
 *
 * Entries are scoped to an exhibition: an opt-out from Build Asia covers every
 * Build Asia campaign, present and future, and says nothing about Health Asia.
 */
final class Suppression
{
    /**
     * Put a value beyond contact. Safe to call repeatedly.
     *
     * The first reason recorded is kept. An opt-out that is later re-added by
     * an administrator should still read as an opt-out.
     */
    public static function add(
        int $agentId,
        string $kind,
        string $value,
        ?string $reason = null,
        string $source = 'manual',
        ?string $actor = null
    ): bool {
        $kind  = self::normaliseKind($kind);
        $value = self::normaliseValue($kind, $value);

        if ($kind === null || $value === null) {
            return false;
        }
        if (!in_array($source, ['opt_out', 'manual', 'bounce', 'complaint', 'import'], true)) {
            $source = 'manual';
        }

        db()->exec(
            'INSERT INTO suppression_list (agent_id, kind, value, reason, source, created_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE value = value',
            [$agentId, $kind, $value, $reason, $source, $actor]
        );

        if ($actor !== null) {
            Audit::setActor($actor);
        }
        Audit::log('suppression_added', $agentId, 'suppression', null,
            $kind . ' ' . self::mask($kind, $value) . ' — ' . ($reason ?? $source));

        return true;
    }

    /** Take a value off the list. Always a deliberate administrator action. */
    public static function remove(int $agentId, int $id, string $actor): bool
    {
        $row = db()->one('SELECT * FROM suppression_list WHERE id = ? AND agent_id = ?', [$id, $agentId]);
        if ($row === null) {
            return false;
        }

        db()->exec('DELETE FROM suppression_list WHERE id = ? AND agent_id = ?', [$id, $agentId]);

        Audit::setActor($actor);
        Audit::log('suppression_removed', $agentId, 'suppression', $id,
            (string)$row['kind'] . ' ' . self::mask((string)$row['kind'], (string)$row['value'])
            . ' — removed by ' . $actor);

        return true;
    }

    /** Is this exact value suppressed? */
    public static function has(int $agentId, string $kind, string $value): bool
    {
        $kind  = self::normaliseKind($kind);
        $value = self::normaliseValue($kind, $value);
        if ($kind === null || $value === null) {
            return false;
        }

        $found = db()->value(
            'SELECT id FROM suppression_list WHERE agent_id = ? AND kind = ? AND value = ?',
            [$agentId, $kind, $value]
        );
        return $found !== null;
    }

    /**
     * Whether an email address may be written to.
     *
     * Checks the address itself and the domain it belongs to, because
     * blocking a company should block everyone at it without needing every
     * individual address to be listed.
     */
    public static function blocksEmail(int $agentId, string $email): bool
    {
        if (self::has($agentId, 'email', $email)) {
            return true;
        }
        $domain = CompanyStore::normaliseDomain(
            str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : ''
        );
        return $domain !== null && self::has($agentId, 'domain', $domain);
    }

    /** Whether a phone number may be messaged. */
    public static function blocksPhone(int $agentId, string $phone): bool
    {
        return self::has($agentId, 'phone', $phone);
    }

    public static function all(int $agentId, int $limit = 500): array
    {
        return db()->all(
            'SELECT * FROM suppression_list WHERE agent_id = ? ORDER BY created_at DESC LIMIT ' . (int)$limit,
            [$agentId]
        );
    }

    public static function count(int $agentId): int
    {
        return (int)db()->value('SELECT COUNT(*) FROM suppression_list WHERE agent_id = ?', [$agentId]);
    }

    // -----------------------------------------------------------------
    // Normalisation
    //
    // A suppression that fails to match because of a capital letter or a
    // space is worse than no suppression at all, so every value is reduced
    // to one canonical form on the way in and on the way out.
    // -----------------------------------------------------------------

    public static function normaliseKind(string $kind): ?string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, ['email', 'domain', 'phone'], true) ? $kind : null;
    }

    public static function normaliseValue(?string $kind, string $value): ?string
    {
        $value = trim($value);
        if ($kind === null || $value === '') {
            return null;
        }

        return match ($kind) {
            'email'  => self::normaliseEmail($value),
            'domain' => CompanyStore::normaliseDomain($value),
            'phone'  => self::normalisePhone($value),
            default  => null,
        };
    }

    private static function normaliseEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@') || mb_strlen($email) > 255) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * Digits only, with a leading plus.
     *
     * Numbers arrive written every possible way — with spaces, dashes,
     * brackets, a leading zero or a country code — and the same person must
     * match however they were typed.
     */
    private static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        // Strip an international dialling prefix written as 00.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }
        return '+' . $digits;
    }

    /** Enough of a value to recognise it in a log, without repeating it whole. */
    private static function mask(string $kind, string $value): string
    {
        if ($kind === 'domain') {
            return $value;
        }
        $len = mb_strlen($value);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, 3) . str_repeat('*', max(0, $len - 6)) . mb_substr($value, -3);
    }
}
