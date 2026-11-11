<?php
declare(strict_types=1);

/**
 * The argument the agent makes, wherever it is talking.
 *
 * Every channel — the website assistant, WhatsApp, an emailed invitation —
 * reasons in the same four steps:
 *
 *   1. What does this company actually make or sell?
 *   2. Does that belong at this exhibition?
 *   3. Is there a market for it in Pakistan?
 *   4. So what would exhibiting get them?
 *
 * This class assembles those four steps from approved material and hands the
 * result to whichever channel is speaking. The wording differs between a chat
 * bubble and an email; the argument does not.
 *
 * Step three is the dangerous one. "What scope does your product have in the
 * Pakistani market" is exactly the question a language model will answer with
 * an invented statistic — a growth rate, an import figure, a market size —
 * fluent, plausible, and sent to a real company under your name. So market
 * claims are not generated here. They are retrieved from approved knowledge,
 * and anything the model writes is checked afterwards: every number in an
 * outgoing message must appear in the approved material it was given.
 *
 * Where nothing is approved for a sector, the agent falls back to the
 * strongest claim it will ever have — the exhibition's own audited audience
 * figures — and makes no market claim at all.
 */
final class ValueProposition
{
    /** Where each step of the argument comes from. */
    private const STEP_CATEGORIES = [
        'market'   => ['market', 'credibility', 'profile'],
        'benefits' => ['benefits', 'participation', 'sponsorship'],
        'audience' => ['credibility', 'event'],
        // Only what is approved about a conference. An exhibition with no
        // conference article has no conference, as far as any message goes.
        'conference' => ['conference'],
    ];

    /**
     * Assemble the case for one company.
     *
     * @param array $agent    the exhibition
     * @param array $profile  from VisitorProfile
     * @param array $facts    observed facts about the company, claim => value
     *
     * @return array{
     *   fit: array, market: array, benefits: array, audience: array,
     *   allowed_numbers: array, sources: array, gaps: array, may_pitch: bool
     * }
     */
    public static function build(array $agent, array $profile, array $facts = []): array
    {
        $agentId = (int)($agent['id'] ?? 0);
        $sector  = (string)($profile['relevance_match'] ?? '');

        $market   = $sector !== '' ? self::articles($agentId, self::STEP_CATEGORIES['market'], $sector) : [];
        $benefits = self::articles($agentId, self::STEP_CATEGORIES['benefits'], '');
        $audience = self::articles($agentId, self::STEP_CATEGORIES['audience'], '');
        $conference = self::articles($agentId, self::STEP_CATEGORIES['conference'], '', 2);

        $gaps = [];
        if ($sector === '') {
            $gaps[] = 'No exhibition sector was matched, so no market argument can be made.';
        } elseif (!$market) {
            $gaps[] = 'Nothing approved describes the Pakistani market for "' . $sector
                    . '", so the agent must not claim anything about it.';
        }
        if (!$benefits) {
            $gaps[] = 'No approved material describes what exhibitors get.';
        }
        if (!$audience) {
            $gaps[] = 'No approved audience figures, so the show cannot describe its own reach.';
        }

        $all = array_merge($market, $benefits, $audience, $conference);

        return [
            'fit' => [
                'sector'  => $sector,
                'reason'  => (string)($profile['relevance_reason'] ?? ''),
                'track'   => (string)($profile['track'] ?? 'visitor'),
                'company' => self::companyLine($facts),
            ],
            'market'          => $market,
            'benefits'        => $benefits,
            'audience'        => $audience,
            'conference'      => $conference,
            'allowed_numbers' => self::approvedNumbers($all),
            'sources'         => array_map(static fn(array $a) => (int)$a['id'], $all),
            'gaps'            => $gaps,
            // Only pitch exhibiting when there is both a sector match and
            // something approved to say about what exhibitors get.
            'may_pitch'       => $sector !== '' && $benefits !== []
                                 && (string)($profile['track'] ?? '') === 'exhibitor',
        ];
    }

    /**
     * What the company itself told us or was observed to do.
     *
     * Only observed facts. An inference about their business may inform how
     * the message is written; it may not be asserted back to them.
     */
    private static function companyLine(array $facts): string
    {
        foreach (['product', 'product_category', 'industry'] as $key) {
            if (!empty($facts[$key])) {
                return (string)$facts[$key];
            }
        }
        return '';
    }

    /**
     * Approved articles in these categories, most relevant first.
     *
     * Only ever status 'approved' and active, exactly as the website
     * assistant retrieves knowledge. Nothing in draft or awaiting review can
     * reach a customer through this route either.
     */
    private static function articles(int $agentId, array $categories, string $sector, int $limit = 6): array
    {
        if (!$categories) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $params       = array_merge([$agentId], $categories);

        $rows = db()->all(
            "SELECT id, title, category, body, keywords FROM knowledge_articles
              WHERE agent_id = ? AND status = 'approved' AND active = 1
                AND category IN ({$placeholders})
              ORDER BY id ASC",
            $params
        );

        if ($sector === '' || !$rows) {
            return array_slice($rows, 0, $limit);
        }

        // With a sector in hand, prefer the articles that actually mention it:
        // the whole phrase, or every distinctive word of it, so "pumps valves
        // and sensors" is found in an article whose keywords list "pumps
        // valves sensors" (fix plan step 3, 11 Nov 2026).
        $needle  = strtolower($sector);
        $words   = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $needle) ?: [],
            static fn(string $w): bool => strlen($w) >= 4 && !in_array($w, ['and', 'with', 'for', 'the', 'other'], true)
        ));
        $matched = [];
        foreach ($rows as $row) {
            $haystack = strtolower((string)$row['title'] . ' ' . (string)$row['keywords'] . ' ' . (string)$row['body']);
            $all = $words !== [];
            foreach ($words as $w) {
                if (!str_contains($haystack, $w)) {
                    $all = false;
                    break;
                }
            }
            if (str_contains($haystack, $needle) || $all) {
                $matched[] = $row;
            }
        }

        return array_slice($matched ?: [], 0, $limit);
    }

    // -----------------------------------------------------------------
    // The guard
    // -----------------------------------------------------------------

    /**
     * Every number that appears in the approved material.
     *
     * This is the allowlist a generated message is checked against. It is
     * built from the same articles the model was given, so the model can only
     * legitimately produce figures that were in front of it.
     */
    public static function approvedNumbers(array $articles): array
    {
        $allowed = [];
        foreach ($articles as $article) {
            $text = (string)($article['title'] ?? '') . ' ' . (string)($article['body'] ?? '');
            foreach (self::numbersIn($text) as $number) {
                $allowed[$number] = true;
            }
        }
        return array_map('strval', array_keys($allowed));
    }

    /**
     * The first figure in a message that no approved article supports, or null.
     *
     * Deliberately strict: any number at all must be traceable. "Over 30,000
     * trade visitors" is caught when the approved figure is 32,000, which is
     * the whole point — a rounded-down invention is still an invention, and
     * a company that later finds the real number was different has been
     * misled by exactly the amount nobody checked.
     *
     * @param array $allowed from approvedNumbers()
     * @param array $alsoAllowed figures cleared elsewhere, such as approved prices
     */
    public static function unsupportedFigure(string $message, array $allowed, array $alsoAllowed = []): ?string
    {
        $permitted = [];
        foreach (array_merge($allowed, $alsoAllowed) as $value) {
            foreach (self::numbersIn((string)$value) as $number) {
                $permitted[$number] = true;
            }
        }

        foreach (self::numbersIn($message) as $number) {
            if (!isset($permitted[$number])) {
                return $number;
            }
        }
        return null;
    }

    /**
     * Numbers found in a piece of text, in a comparable form.
     *
     * Thousands separators and a trailing plus are removed, so "32,000+" and
     * "32000" are the same figure. A decimal keeps its point, because 9 and
     * 9.5 square metres are not the same thing.
     */
    public static function numbersIn(string $text): array
    {
        // Skip anything inside a web address or email, where digits are part
        // of a name rather than a claim about the world.
        $text = preg_replace('#\bhttps?://\S+#i', ' ', $text) ?? $text;
        $text = preg_replace('/\S+@\S+/', ' ', $text) ?? $text;

        if (!preg_match_all('/\d[\d,\.]*/', $text, $matches)) {
            return [];
        }

        $out = [];
        foreach ($matches[0] as $raw) {
            $clean = rtrim(str_replace(',', '', $raw), '.');
            if ($clean === '') {
                continue;
            }
            // Drop a pointless trailing ".0" so 9 and 9.0 compare equal.
            if (str_contains($clean, '.')) {
                $clean = rtrim(rtrim($clean, '0'), '.');
            }
            if ($clean !== '') {
                $out[$clean] = true;
            }
        }
        // Cast back to strings: PHP turns numeric array keys into integers,
        // and "9.5" must not come back as something else.
        return array_map('strval', array_keys($out));
    }

    /**
     * A plain summary of what the agent is allowed to argue, for the review
     * screen and for the prompt.
     */
    public static function explain(array $case): array
    {
        $lines = [];

        if (($case['fit']['sector'] ?? '') !== '') {
            $lines[] = 'Fit: ' . $case['fit']['reason'];
        } else {
            $lines[] = 'Fit: not established, so no exhibiting pitch.';
        }

        $lines[] = 'Market: ' . (count($case['market']) > 0
            ? count($case['market']) . ' approved note(s) about this sector'
            : 'nothing approved — the agent must make no market claim');

        $lines[] = 'Benefits: ' . (count($case['benefits']) > 0
            ? count($case['benefits']) . ' approved item(s)'
            : 'nothing approved');

        $lines[] = 'Audience: ' . (count($case['audience']) > 0
            ? count($case['audience']) . ' approved figure set(s)'
            : 'nothing approved');

        foreach ($case['gaps'] as $gap) {
            $lines[] = 'Gap: ' . $gap;
        }

        return $lines;
    }
}
