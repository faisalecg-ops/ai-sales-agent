<?php
declare(strict_types=1);

/**
 * Companies — the record that prospects, leads and registrations all point at.
 *
 * The rule that governs everything here: a domain is an identity, a name is
 * not. Two records sharing a website are the same company and are joined
 * automatically. Two records with similar names are a question for a person,
 * and this class will only ever raise the question.
 *
 * Companies belong to one exhibition, exactly as leads and knowledge do. A
 * firm researched for Build Asia is invisible to Health Asia, and every query
 * in this class is filtered by agent to keep that true.
 */
final class CompanyStore
{
    /**
     * Mailbox providers. An address at one of these says nothing about which
     * company the person works for, so it must never be used to match one.
     */
    private const PUBLIC_MAILBOXES = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.co.in',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com',
        'aol.com', 'icloud.com', 'me.com', 'mail.com', 'gmx.com', 'gmx.net',
        'protonmail.com', 'proton.me', 'yandex.com', 'zoho.com',
        'rediffmail.com', 'hotmail.fr', 'qq.com', '163.com', '126.com',
    ];

    /** Words that decorate a company name without identifying it. */
    private const NAME_NOISE = [
        'ltd', 'limited', 'llc', 'l.l.c', 'inc', 'incorporated', 'co', 'company',
        'corp', 'corporation', 'plc', 'gmbh', 'ag', 'sa', 'srl', 'bv', 'nv',
        'pvt', 'private', 'pte', 'fze', 'fzco', 'fzc', 'dmcc', 'wll', 'est',
        'establishment', 'group', 'holdings', 'holding', 'international',
        'industries', 'industrial', 'enterprises', 'enterprise', 'trading',
        'general', 'and', 'the', 'of',
    ];

    // -----------------------------------------------------------------
    // Normalisation
    // -----------------------------------------------------------------

    /**
     * Reduce anything that looks like a web address to a bare hostname.
     *
     * Returns null when there is nothing usable, which is common and not an
     * error: plenty of small firms have no website.
     */
    public static function normaliseDomain(?string $input): ?string
    {
        $raw = strtolower(trim((string)$input));
        if ($raw === '') {
            return null;
        }

        // Accept a bare domain as readily as a full URL.
        if (!str_contains($raw, '://')) {
            $raw = 'http://' . $raw;
        }

        $host = parse_url($raw, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // A hostname, not an address and not a stray fragment of text.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
            return null;
        }
        if (strlen($host) > 190) {
            return null;
        }

        return $host;
    }

    /** The domain part of an email, unless it is a public mailbox. */
    public static function companyDomainFromEmail(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        $domain = substr($email, strrpos($email, '@') + 1);
        $domain = self::normaliseDomain($domain);
        if ($domain === null || in_array($domain, self::PUBLIC_MAILBOXES, true)) {
            return null;
        }
        return $domain;
    }

    /**
     * A company name reduced to the words that actually distinguish it, so
     * that "Al Noor Trading LLC" and "Al-Noor Trading Co." compare equal.
     */
    public static function normaliseName(?string $name): string
    {
        $name = strtolower(trim((string)$name));
        if ($name === '') {
            return '';
        }

        $name = str_replace(['&', '+'], ' and ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';

        $kept = [];
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
            if ($word === '' || in_array($word, self::NAME_NOISE, true)) {
                continue;
            }
            $kept[] = $word;
        }

        // A name made entirely of noise words keeps its original shape rather
        // than becoming empty and matching everything.
        return $kept ? implode(' ', $kept) : trim($name);
    }

    // -----------------------------------------------------------------
    // Finding and creating
    // -----------------------------------------------------------------

    public static function find(int $agentId, int $companyId): ?array
    {
        return db()->one(
            'SELECT * FROM companies WHERE id = ? AND agent_id = ?',
            [$companyId, $agentId]
        );
    }

    public static function findByDomain(int $agentId, ?string $domain): ?array
    {
        $domain = self::normaliseDomain($domain);
        if ($domain === null) {
            return null;
        }
        return db()->one(
            'SELECT * FROM companies WHERE agent_id = ? AND domain = ?',
            [$agentId, $domain]
        );
    }

    /** The company that owns an email address, by its domain alone. */
    public static function findByEmail(int $agentId, ?string $email): ?array
    {
        return self::findByDomain($agentId, self::companyDomainFromEmail($email));
    }

    /**
     * Get the company for these details, creating it only if no existing
     * record shares its domain.
     *
     * A record without a domain is always created rather than matched by
     * name, because matching on a name is exactly the guess this design
     * refuses to make.
     *
     * @return array{company: array, created: bool, duplicates: array}
     */
    public static function findOrCreate(int $agentId, array $fields, string $source = 'manual'): array
    {
        $domain = self::normaliseDomain($fields['website'] ?? $fields['domain'] ?? null);

        if ($domain !== null) {
            $existing = self::findByDomain($agentId, $domain);
            if ($existing !== null) {
                return ['company' => $existing, 'created' => false, 'duplicates' => []];
            }
        }

        $name = trim((string)($fields['name'] ?? ''));
        if ($name === '') {
            $name = $domain ?? 'Unnamed company';
        }

        $id = db()->insert('companies', [
            'agent_id'    => $agentId,
            'name'        => mb_substr($name, 0, 200),
            'website'     => $fields['website'] ?? null,
            'domain'      => $domain,
            'country'     => $fields['country'] ?? null,
            'city'        => $fields['city'] ?? null,
            'industry'    => $fields['industry'] ?? null,
            'description' => $fields['description'] ?? null,
            'source'      => in_array($source, ['prospecting', 'website', 'registration', 'whatsapp', 'manual'], true)
                ? $source : 'manual',
            'origin_note' => $fields['origin_note'] ?? null,
        ]);

        Audit::log('company_created', $agentId, 'company', $id, $name . ' (' . $source . ')');

        $company = self::find($agentId, $id) ?? [];

        // Created, then checked. A new record that resembles an existing one
        // is surfaced immediately rather than discovered months later.
        return [
            'company'    => $company,
            'created'    => true,
            'duplicates' => $company ? self::possibleDuplicates($agentId, $company) : [],
        ];
    }

    // -----------------------------------------------------------------
    // Suspected duplicates
    // -----------------------------------------------------------------

    /**
     * Other companies that might be this one.
     *
     * Deliberately returns candidates and a reason rather than acting. A
     * near-identical name in the same country is a strong hint and still not
     * proof: two unrelated firms can share a name, and merging them would put
     * one company's conversation in front of another.
     *
     * @return list<array{company: array, reason: string, strength: string}>
     */
    public static function possibleDuplicates(int $agentId, array $company, int $limit = 10): array
    {
        $selfId = (int)($company['id'] ?? 0);
        $target = self::normaliseName((string)($company['name'] ?? ''));
        if ($target === '') {
            return [];
        }

        $rows = db()->all(
            "SELECT * FROM companies
              WHERE agent_id = ? AND id <> ? AND status <> 'archived'
              LIMIT 500",
            [$agentId, $selfId]
        );

        $country = strtolower(trim((string)($company['country'] ?? '')));
        $out     = [];

        foreach ($rows as $row) {
            $other = self::normaliseName((string)$row['name']);
            if ($other === '') {
                continue;
            }

            $sameCountry = $country !== ''
                && strtolower(trim((string)($row['country'] ?? ''))) === $country;

            if ($other === $target) {
                $out[] = [
                    'company'  => $row,
                    'reason'   => $sameCountry
                        ? 'Same name, same country'
                        : 'Same name, different country',
                    'strength' => $sameCountry ? 'likely' : 'possible',
                ];
                continue;
            }

            if (self::nameSimilarity($target, $other) >= 0.90) {
                $out[] = [
                    'company'  => $row,
                    'reason'   => $sameCountry
                        ? 'Very similar name, same country'
                        : 'Very similar name, different country',
                    'strength' => $sameCountry ? 'possible' : 'weak',
                ];
            }
        }

        usort($out, static function (array $a, array $b): int {
            $rank = ['likely' => 0, 'possible' => 1, 'weak' => 2];
            return ($rank[$a['strength']] ?? 3) <=> ($rank[$b['strength']] ?? 3);
        });

        return array_slice($out, 0, $limit);
    }

    /** 0 to 1. Both names must already be normalised. */
    public static function nameSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        // Levenshtein is capped at 255 bytes and these are short strings; a
        // longer name is compared on its opening, which is where a company
        // name carries its identity.
        $a = mb_substr($a, 0, 120);
        $b = mb_substr($b, 0, 120);

        $distance = levenshtein($a, $b);
        $longest  = max(strlen($a), strlen($b));

        return $longest === 0 ? 0.0 : 1.0 - ($distance / $longest);
    }

    // -----------------------------------------------------------------
    // Merging
    // -----------------------------------------------------------------

    /**
     * Fold one company into another. Only ever called because a person said so.
     *
     * Evidence, contacts, leads and conversations move across; the source
     * record is archived rather than deleted, so the decision stays visible
     * and a mistake can be understood later.
     */
    public static function merge(int $agentId, int $sourceId, int $targetId, string $actor): bool
    {
        if ($sourceId === $targetId) {
            return false;
        }

        $source = self::find($agentId, $sourceId);
        $target = self::find($agentId, $targetId);
        if ($source === null || $target === null) {
            return false;
        }

        db()->begin();
        try {
            db()->exec('UPDATE company_evidence SET company_id = ? WHERE company_id = ? AND agent_id = ?',
                [$targetId, $sourceId, $agentId]);

            // A contact already held by the target would break the uniqueness
            // rule, so those are dropped rather than duplicated.
            foreach (db()->all('SELECT * FROM company_contacts WHERE company_id = ?', [$sourceId]) as $contact) {
                $clash = db()->value(
                    'SELECT id FROM company_contacts WHERE company_id = ? AND kind = ? AND normalised = ?',
                    [$targetId, $contact['kind'], $contact['normalised']]
                );
                if ($clash) {
                    db()->exec('DELETE FROM company_contacts WHERE id = ?', [(int)$contact['id']]);
                } else {
                    db()->exec('UPDATE company_contacts SET company_id = ? WHERE id = ?',
                        [$targetId, (int)$contact['id']]);
                }
            }

            db()->exec('UPDATE leads SET company_id = ? WHERE company_id = ? AND agent_id = ?',
                [$targetId, $sourceId, $agentId]);
            db()->exec('UPDATE conversations SET company_id = ? WHERE company_id = ? AND agent_id = ?',
                [$targetId, $sourceId, $agentId]);

            // Do-not-contact is the one field that must survive a merge in
            // the stricter direction: if either side was blocked, both are.
            if ((string)$source['status'] === 'do_not_contact') {
                db()->update('companies', ['status' => 'do_not_contact'], 'id = ?', [$targetId]);
            }

            db()->update('companies', [
                'status'      => 'archived',
                'origin_note' => 'Merged into company #' . $targetId,
            ], 'id = ?', [$sourceId]);

            db()->commit();
        } catch (Throwable $ex) {
            db()->rollback();
            app_log('company', 'merge failed', ['error' => $ex->getMessage()]);
            return false;
        }

        Audit::setActor($actor);
        Audit::log('company_merged', $agentId, 'company', $targetId,
            'Merged #' . $sourceId . ' (' . (string)$source['name'] . ') into #' . $targetId);

        return true;
    }

    // -----------------------------------------------------------------
    // Contact status
    // -----------------------------------------------------------------

    /** Block a company outright. Travels with it into every campaign. */
    public static function setDoNotContact(int $agentId, int $companyId, string $actor, ?string $reason = null): bool
    {
        $company = self::find($agentId, $companyId);
        if ($company === null) {
            return false;
        }

        db()->update('companies', ['status' => 'do_not_contact'], 'id = ?', [$companyId]);

        // Its contact points go on the suppression list too, so the block
        // still holds if the company record is later removed.
        foreach (db()->all('SELECT * FROM company_contacts WHERE company_id = ?', [$companyId]) as $contact) {
            Suppression::add(
                $agentId,
                (string)$contact['kind'] === 'phone' ? 'phone' : 'email',
                (string)$contact['normalised'],
                $reason ?? 'Company marked do not contact',
                'manual',
                $actor
            );
        }
        if (($company['domain'] ?? null) !== null) {
            Suppression::add($agentId, 'domain', (string)$company['domain'],
                $reason ?? 'Company marked do not contact', 'manual', $actor);
        }

        Audit::setActor($actor);
        Audit::log('company_do_not_contact', $agentId, 'company', $companyId,
            $reason ?? 'Marked do not contact');

        return true;
    }

    /**
     * Whether this company may be contacted at all.
     *
     * Checked at the moment of sending rather than at drafting time, because
     * a company can be blocked in between.
     */
    public static function isContactable(int $agentId, array $company): bool
    {
        if ((string)($company['status'] ?? '') !== 'active') {
            return false;
        }
        if (($company['domain'] ?? null) !== null
            && Suppression::has($agentId, 'domain', (string)$company['domain'])) {
            return false;
        }
        return true;
    }
}
