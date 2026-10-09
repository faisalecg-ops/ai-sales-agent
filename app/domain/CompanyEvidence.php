<?php
declare(strict_types=1);

/**
 * What we know about a company, and how we came to know it.
 *
 * Every fact is stored as its own row carrying the page it came from, the
 * date it was read, and whether it was **observed** on that page or
 * **inferred** from what was observed. That distinction is the mechanism
 * behind the rule that personalisation must never become fabrication: a
 * proposal may state observed claims as fact, and may only ever phrase an
 * inference as an inference.
 *
 * Nothing here overwrites. Researching a company again adds a later round, so
 * a changed answer is visible as a change rather than replacing the old one
 * silently.
 */
final class CompanyEvidence
{
    /** Claims a proposal is allowed to treat as fact, when observed. */
    public const FACTUAL_CLAIMS = [
        'country', 'city', 'industry', 'product', 'product_category',
        'company_size', 'markets_served', 'certification', 'founded',
        'exhibition_history', 'website_language',
    ];

    public const CLAIM_LABELS = [
        'country'            => 'Country',
        'city'               => 'City or region',
        'industry'           => 'Industry',
        'product'            => 'Product or service',
        'product_category'   => 'Product category',
        'company_size'       => 'Size',
        'markets_served'     => 'Markets served',
        'certification'      => 'Certification',
        'founded'            => 'Founded',
        'exhibition_history' => 'Trade show history',
        'website_language'   => 'Website language',
        'relevance'          => 'Why this may be relevant',
        'summary'            => 'Summary',
    ];

    /**
     * Record one claim.
     *
     * A claim with no source is accepted only when it is marked as inferred:
     * an observation without a page to point at is not an observation.
     */
    public static function record(
        int $agentId,
        int $companyId,
        string $claimKey,
        string $claimValue,
        string $kind = 'observed',
        ?string $sourceUrl = null,
        ?string $sourceLabel = null,
        string $confidence = 'medium',
        int $round = 1
    ): ?int {
        $claimKey   = strtolower(trim($claimKey));
        $claimValue = trim($claimValue);

        if ($claimKey === '' || $claimValue === '') {
            return null;
        }
        if (!in_array($kind, ['observed', 'inferred'], true)) {
            $kind = 'inferred';
        }
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'medium';
        }
        if ($kind === 'observed' && ($sourceUrl === null || trim($sourceUrl) === '')) {
            // Downgraded rather than refused: the information may still be
            // useful, but it may not be stated as established fact.
            $kind = 'inferred';
        }

        return db()->insert('company_evidence', [
            'company_id'   => $companyId,
            'agent_id'     => $agentId,
            'claim_key'    => mb_substr($claimKey, 0, 64),
            'claim_value'  => mb_substr($claimValue, 0, 4000),
            'kind'         => $kind,
            'confidence'   => $confidence,
            'source_url'   => $sourceUrl !== null ? mb_substr($sourceUrl, 0, 500) : null,
            'source_label' => $sourceLabel !== null ? mb_substr($sourceLabel, 0, 160) : null,
            'observed_at'  => gmdate('Y-m-d H:i:s'),
            'round'        => max(1, $round),
        ]);
    }

    /** Everything recorded about a company, newest round first. */
    public static function forCompany(int $agentId, int $companyId): array
    {
        return db()->all(
            'SELECT * FROM company_evidence
              WHERE agent_id = ? AND company_id = ?
              ORDER BY round DESC, claim_key ASC, id ASC',
            [$agentId, $companyId]
        );
    }

    /**
     * The same evidence sorted into the three states an administrator needs
     * to tell apart, with missing claims named rather than left out.
     *
     * @return array{observed: array, inferred: array, missing: string[]}
     */
    public static function summarise(int $agentId, int $companyId, array $expected = []): array
    {
        $rows     = self::forCompany($agentId, $companyId);
        $observed = [];
        $inferred = [];
        $seen     = [];

        foreach ($rows as $row) {
            $key = (string)$row['claim_key'];
            $seen[$key] = true;
            if ((string)$row['kind'] === 'observed') {
                $observed[] = $row;
            } else {
                $inferred[] = $row;
            }
        }

        $expected = $expected ?: ['country', 'industry', 'product_category'];
        $missing  = [];
        foreach ($expected as $key) {
            if (!isset($seen[$key])) {
                $missing[] = $key;
            }
        }

        return ['observed' => $observed, 'inferred' => $inferred, 'missing' => $missing];
    }

    /**
     * The claims a proposal may state as fact: observed, sourced, and of a
     * kind that is about the company's business rather than our opinion of it.
     *
     * Anything not returned here may still inform the writing, but may not be
     * asserted.
     *
     * @return array<string, string> claim key to value
     */
    public static function statableFacts(int $agentId, int $companyId): array
    {
        $rows = db()->all(
            "SELECT claim_key, claim_value FROM company_evidence
              WHERE agent_id = ? AND company_id = ? AND kind = 'observed'
                AND source_url IS NOT NULL
              ORDER BY round DESC, id DESC",
            [$agentId, $companyId]
        );

        $facts = [];
        foreach ($rows as $row) {
            $key = (string)$row['claim_key'];
            if (!in_array($key, self::FACTUAL_CLAIMS, true)) {
                continue;
            }
            // Newest round wins, and within a round the first one recorded.
            if (!isset($facts[$key])) {
                $facts[$key] = (string)$row['claim_value'];
            }
        }
        return $facts;
    }

    /** The round number a fresh piece of research should write under. */
    public static function nextRound(int $agentId, int $companyId): int
    {
        $latest = db()->value(
            'SELECT MAX(round) FROM company_evidence WHERE agent_id = ? AND company_id = ?',
            [$agentId, $companyId]
        );
        return $latest === null ? 1 : ((int)$latest + 1);
    }

    /** The pages this company's information came from, newest first. */
    public static function sources(int $agentId, int $companyId): array
    {
        $rows = db()->all(
            'SELECT DISTINCT source_url, source_label, MAX(observed_at) AS seen_at
               FROM company_evidence
              WHERE agent_id = ? AND company_id = ? AND source_url IS NOT NULL
              GROUP BY source_url, source_label
              ORDER BY seen_at DESC',
            [$agentId, $companyId]
        );
        return $rows;
    }

    public static function label(string $claimKey): string
    {
        return self::CLAIM_LABELS[$claimKey] ?? ucfirst(str_replace('_', ' ', $claimKey));
    }
}
