<?php
declare(strict_types=1);

/**
 * Comparing what the documents said, and deciding what to ask about.
 *
 * This runs in PHP, not in the model, and that is the whole point. Detecting
 * that two documents state different prices is a comparison, not a judgement,
 * and a comparison should be exact and repeatable. Asking a model which price
 * is right would invite it to pick one confidently and wrongly.
 *
 * So: PHP groups the findings, normalises the values, and where they disagree
 * it raises a question for the operator. The model is never asked to choose.
 */
final class FindingReconciler
{
    /**
     * Claims that always go to the operator when documents disagree.
     *
     * These are the ones where being wrong costs money or credibility: a
     * quoted price, a tax rate, a date someone books travel around. No
     * automatic rule is trusted with them, however confident it looks.
     */
    private const ALWAYS_ASK = [
        'price', 'rate_per_sqm', 'currency', 'min_sqm', 'increment_sqm', 'max_sqm',
        'tax_rate_percent', 'tax_label', 'tax_treatment', 'quantity_available',
        'included_space', 'dates', 'deadline', 'booking_terms', 'payment_terms',
    ];

    /**
     * Run the comparison for a whole import.
     *
     * @return array{groups:int,duplicates:int,questions:int}
     */
    public static function reconcile(array $run): array
    {
        $runId = (int)$run['id'];

        // Start from a clean slate: reconciling twice must not double the
        // questions, and the operator may have added a document and re-run.
        db()->exec("DELETE FROM import_questions WHERE run_id = ? AND status = 'open'", [$runId]);
        db()->exec("UPDATE import_findings SET status = 'candidate' WHERE run_id = ? AND status <> 'discarded'", [$runId]);

        $documents = [];
        foreach (ImportRun::documents($runId) as $doc) {
            $documents[(int)$doc['id']] = $doc;
        }

        $findings = db()->all(
            "SELECT * FROM import_findings WHERE run_id = ? AND status <> 'discarded' ORDER BY id ASC",
            [$runId]
        );

        // Decisions already taken are never re-opened. Without this, applying an
        // answer and comparing again would ask the same question forever.
        $decided = [];
        $answered = [];
        foreach (db()->all(
            "SELECT subject_key, claim_key, answer_value, status FROM import_questions
             WHERE run_id = ? AND status <> 'open'",
            [$runId]
        ) as $row) {
            $key = (string)$row['subject_key'] . '|' . (string)($row['claim_key'] ?? '');
            $decided[$key] = true;
            if ((string)$row['status'] === 'answered' && $row['answer_value'] !== null) {
                $answered[(string)$row['subject_key']][(string)($row['claim_key'] ?? '')] = (string)$row['answer_value'];
            }
        }

        $duplicates = self::markDuplicates($findings);

        // Re-read after duplicates are marked, so groups only contain keepers.
        $findings = db()->all(
            "SELECT * FROM import_findings
             WHERE run_id = ? AND status NOT IN ('discarded','duplicate') ORDER BY id ASC",
            [$runId]
        );

        $groups = [];
        foreach ($findings as $finding) {
            $groups[(string)$finding['subject_key']][] = $finding;
        }

        $order     = 0;
        $questions = 0;

        foreach ($groups as $subjectKey => $members) {
            $questions += self::examineGroup(
                $run, $subjectKey, $members, $documents, $order,
                $decided, $answered[$subjectKey] ?? []
            );
        }

        ImportRun::refreshCounts($runId);

        return ['groups' => count($groups), 'duplicates' => $duplicates, 'questions' => $questions];
    }

    // -----------------------------------------------------------------
    // Duplicates
    // -----------------------------------------------------------------

    /**
     * The same fact stated twice is not a conflict, it is repetition —
     * brochures repeat their own headline figures on several pages. Keep the
     * first, mark the rest, and remember which documents agreed.
     */
    private static function markDuplicates(array $findings): int
    {
        $seen  = [];
        $count = 0;

        foreach ($findings as $finding) {
            $fingerprint = self::fingerprint($finding);
            if (isset($seen[$fingerprint])) {
                db()->update('import_findings', ['status' => 'duplicate'], 'id = ?', [(int)$finding['id']]);
                $count++;
                continue;
            }
            $seen[$fingerprint] = (int)$finding['id'];
        }

        return $count;
    }

    private static function fingerprint(array $finding): string
    {
        $claims = json_decode((string)($finding['claims_json'] ?? '{}'), true);
        $claims = is_array($claims) ? $claims : [];
        ksort($claims);

        $canonical = [];
        foreach ($claims as $key => $value) {
            $canonical[$key] = self::canonicalValue((string)$key, (string)$value);
        }

        $payload = json_decode((string)$finding['payload_json'], true);
        $payload = is_array($payload) ? $payload : [];

        // Body text is normalised hard, because the same paragraph extracted
        // twice will differ in whitespace.
        $body = mb_strtolower((string)($payload['body'] ?? $payload['answer'] ?? ''));
        $body = preg_replace('/[^a-z0-9]+/', '', $body) ?? $body;

        return sha1(implode('|', [
            (string)$finding['kind'],
            (string)$finding['subject_key'],
            self::normaliseEdition((string)($finding['edition_label'] ?? '')),
            json_encode($canonical),
            mb_substr($body, 0, 400),
        ]));
    }

    // -----------------------------------------------------------------
    // One subject at a time
    // -----------------------------------------------------------------

    /**
     * @param array<int,array> $members findings sharing a subject
     * @param array<int,array> $documents keyed by id
     */
    private static function examineGroup(
        array $run,
        string $subjectKey,
        array $members,
        array $documents,
        int &$order,
        array $decided = [],
        array $answered = []
    ): int {
        $runId    = (int)$run['id'];
        $kind     = (string)$members[0]['kind'];
        $label    = (string)$members[0]['subject_label'];
        $asked    = 0;

        // Different editions are different things, not a disagreement. Compare
        // within an edition, and treat an unlabelled finding as belonging to
        // whichever edition the group mostly describes.
        $byEdition = [];
        foreach ($members as $member) {
            $byEdition[self::normaliseEdition((string)($member['edition_label'] ?? ''))][] = $member;
        }

        // A subject described for more than one edition is worth confirming,
        // because loading a Lahore price against a Karachi agent is exactly
        // the mistake this is meant to prevent.
        $realEditions = array_filter(array_keys($byEdition), static fn($e) => $e !== '');
        if (count($realEditions) > 1
            && in_array($kind, ['stall_package', 'sponsorship_package'], true)
            && !isset($decided[$subjectKey . '|edition'])) {
            $options = [];
            foreach ($realEditions as $edition) {
                $sources = self::sourceNames($byEdition[$edition], $documents);
                $options[] = [
                    'value'  => $edition,
                    'label'  => self::originalEdition($byEdition[$edition]),
                    'detail' => 'from ' . self::joinNames($sources),
                    'documents' => array_column($byEdition[$edition], 'document_id'),
                ];
            }

            self::ask($runId, [
                'kind'          => 'outdated',
                'subject_key'   => $subjectKey,
                'subject_label' => $label,
                'claim_key'     => 'edition',
                'question'      => 'The documents describe ' . $label . ' for more than one edition of the event. '
                                 . 'Which edition should this assistant quote?',
                'detail'        => 'Pricing for a different edition, city or year must not be mixed in. '
                                 . 'Anything you leave out stays out of the knowledge base.',
                'options'       => $options,
                'allow_free_text' => false,
                'sort_order'    => $order++,
            ]);
            $asked++;
        }

        foreach ($byEdition as $edition => $group) {
            $asked += self::examineClaims(
                $run, $subjectKey, $label, $kind, $edition, $group, $documents, $order, $decided
            );
            $asked += self::examineCompleteness(
                $run, $subjectKey, $label, $kind, $group, $documents, $order, $decided, $answered
            );
        }

        return $asked;
    }

    /**
     * Compare each individual claim across the documents in a group.
     */
    private static function examineClaims(
        array $run,
        string $subjectKey,
        string $label,
        string $kind,
        string $edition,
        array $group,
        array $documents,
        int &$order,
        array $decided = []
    ): int {
        $runId = (int)$run['id'];
        $asked = 0;

        // claim key => canonical value => ['display' => ..., 'findings' => [...]]
        $byClaim = [];

        foreach ($group as $finding) {
            $claims = json_decode((string)($finding['claims_json'] ?? '{}'), true);
            if (!is_array($claims)) {
                continue;
            }
            foreach ($claims as $key => $value) {
                $key   = (string)$key;
                $value = (string)$value;
                $canonical = self::canonicalValue($key, $value);
                if ($canonical === '') {
                    continue;
                }
                $byClaim[$key][$canonical]['display'] = $value;
                $byClaim[$key][$canonical]['findings'][] = $finding;
            }
        }

        foreach ($byClaim as $claimKey => $values) {
            if (count($values) < 2) {
                continue;   // everything agrees, or only one document mentioned it
            }
            if (isset($decided[$subjectKey . '|' . $claimKey])) {
                continue;   // the operator has already settled this one
            }

            $options = [];
            foreach ($values as $canonical => $entry) {
                $sources = self::sourceNames($entry['findings'], $documents);
                $options[] = [
                    'value'     => (string)$entry['display'],
                    'label'     => (string)$entry['display'],
                    'detail'    => 'stated in ' . self::joinNames($sources),
                    'quote'     => mb_substr((string)$entry['findings'][0]['source_quote'], 0, 300),
                    'documents' => array_values(array_unique(array_column($entry['findings'], 'document_id'))),
                    'authority' => self::bestAuthority($entry['findings'], $documents),
                ];
            }

            // A descriptive detail can be settled by which document is more
            // authoritative. Anything commercial is always put to the operator.
            $autoResolved = null;
            if (!in_array($claimKey, self::ALWAYS_ASK, true)) {
                $autoResolved = self::resolveByAuthority($options);
            }

            if ($autoResolved !== null) {
                foreach ($group as $finding) {
                    db()->update('import_findings', ['status' => 'resolved'], 'id = ?', [(int)$finding['id']]);
                }
                continue;
            }

            $claimLabel = FindingExtractor::CLAIM_LABELS[$claimKey] ?? str_replace('_', ' ', $claimKey);
            $editionNote = $edition !== '' ? ' (' . self::originalEdition($group) . ')' : '';

            self::ask($runId, [
                'kind'          => 'conflict',
                'subject_key'   => $subjectKey,
                'subject_label' => $label,
                'claim_key'     => $claimKey,
                'question'      => self::conflictQuestion($label . $editionNote, $claimLabel, $options),
                'detail'        => 'Nothing will be quoted to a visitor until you decide. '
                                 . 'You can also type a different value if neither is right.',
                'options'       => $options,
                'allow_free_text' => true,
                'sort_order'    => $order++,
            ]);

            foreach ($group as $finding) {
                db()->update('import_findings', ['status' => 'conflicted'], 'id = ?', [(int)$finding['id']]);
            }
            $asked++;
        }

        return $asked;
    }

    /**
     * Phrased the way a colleague would put it: what was found, where each
     * version came from, and what is being asked.
     */
    private static function conflictQuestion(string $subject, string $claimLabel, array $options): string
    {
        $parts = [];
        foreach ($options as $option) {
            $parts[] = '"' . (string)$option['value'] . '" ' . (string)$option['detail'];
        }

        $count = count($options) === 2 ? 'two different' : count($options) . ' different';

        return 'I found ' . $count . ' values for the ' . $claimLabel . ' of ' . $subject . ': '
             . self::joinNames($parts) . '. Which one should the assistant use?';
    }

    /**
     * Fields the platform needs before it can quote anything. A gap here
     * becomes a question rather than a silently incomplete record.
     */
    private static function examineCompleteness(
        array $run,
        string $subjectKey,
        string $label,
        string $kind,
        array $group,
        array $documents,
        int &$order,
        array $decided = [],
        array $answered = []
    ): int {
        if (!in_array($kind, ['stall_package', 'sponsorship_package'], true)) {
            return 0;
        }

        $runId  = (int)$run['id'];
        $asked  = 0;
        $merged = self::mergePayloads($group);
        $sources = self::joinNames(self::sourceNames($group, $documents));

        // Answers already given count as facts from here on, so a gap the
        // operator has just filled is not asked about again — and a gap their
        // answer newly exposes, such as a tax rate, is.
        foreach ($answered as $claim => $value) {
            self::applyAnsweredClaim($merged, (string)$claim, (string)$value);
        }

        $ask = static fn(string $claim): bool => !isset($decided[$subjectKey . '|' . $claim]);

        // A price with no currency cannot be displayed at all.
        if (($merged['price'] ?? null) !== null || ($merged['rate_per_sqm'] ?? null) !== null) {
            if (empty($merged['currency']) && $ask('currency')) {
                self::ask($runId, [
                    'kind'          => 'incomplete',
                    'subject_key'   => $subjectKey,
                    'subject_label' => $label,
                    'claim_key'     => 'currency',
                    'question'      => 'What currency is the ' . $label . ' price in? '
                                     . 'The figure is in ' . $sources . ' but the currency is not stated next to it.',
                    'detail'        => 'International pricing has to be in US dollars. A price in any other '
                                     . 'currency is only ever shown to visitors in that price list\'s own region.',
                    'options'       => [
                        ['value' => 'USD', 'label' => 'US dollars (USD)', 'detail' => 'for international exhibitors'],
                        ['value' => 'PKR', 'label' => 'Pakistan rupees (PKR)', 'detail' => 'for local exhibitors only'],
                    ],
                    'allow_free_text' => true,
                    'sort_order'    => $order++,
                ]);
                $asked++;
            } elseif (!in_array(strtoupper((string)$merged['currency']), ['USD', 'PKR'], true) && $ask('currency')) {
                // Section 15: international pricing is US dollars only, and no
                // conversion is ever performed, so another currency has to be
                // resolved by a person before it can be used.
                self::ask($runId, [
                    'kind'          => 'currency',
                    'subject_key'   => $subjectKey,
                    'subject_label' => $label,
                    'claim_key'     => 'currency',
                    'question'      => 'The ' . $label . ' price is in ' . strtoupper((string)$merged['currency'])
                                     . ', taken from ' . $sources . '. This assistant quotes international pricing '
                                     . 'in US dollars only and never converts between currencies. How should this be handled?',
                    'detail'        => 'If you have the US dollar figure, type it in. Otherwise leave this out and '
                                     . 'the team can quote it by hand.',
                    'options'       => [
                        ['value' => 'exclude', 'label' => 'Leave this price out', 'detail' => 'recommended if you do not have a USD figure'],
                    ],
                    'allow_free_text' => true,
                    'sort_order'    => $order++,
                ]);
                $asked++;
            }
        }

        // Tax. Section 21 forbids guessing the treatment, and a total cannot
        // be shown without a rate, so both are asked about explicitly.
        $treatment = (string)($merged['tax_treatment'] ?? 'unstated');
        $hasRate   = ($merged['tax_rate_percent'] ?? null) !== null;

        if ($treatment === 'unstated' && $ask('tax_treatment')) {
            self::ask($runId, [
                'kind'          => 'tax',
                'subject_key'   => $subjectKey,
                'subject_label' => $label,
                'claim_key'     => 'tax_treatment',
                'question'      => 'Do the ' . $label . ' prices include tax? '
                                 . $sources . ' ' . (count($group) > 1 || str_contains($sources, ' and ')
                                     ? 'do not say' : 'does not say') . ' either way.',
                'detail'        => 'If tax is added on top, give the rate and the assistant will show the charge, '
                                 . 'the tax and the total as separate lines. If you are not sure, choose the last '
                                 . 'option and the assistant will say the team will confirm it.',
                'options'       => [
                    ['value' => 'excluded', 'label' => 'Tax is added on top', 'detail' => 'you will be asked for the rate next'],
                    ['value' => 'included', 'label' => 'The price already includes tax', 'detail' => ''],
                    ['value' => 'unstated', 'label' => 'Not sure', 'detail' => 'the assistant will say the team must confirm'],
                ],
                'allow_free_text' => false,
                'sort_order'    => $order++,
            ]);
            $asked++;
        } elseif ($treatment === 'excluded' && !$hasRate && $ask('tax_rate_percent')) {
            self::ask($runId, [
                'kind'          => 'tax',
                'subject_key'   => $subjectKey,
                'subject_label' => $label,
                'claim_key'     => 'tax_rate_percent',
                'question'      => $sources . ' says the ' . $label . ' price excludes tax, but does not give the rate. '
                                 . 'What rate applies?',
                'detail'        => 'Without a rate the assistant cannot show a total, and will tell visitors the '
                                 . 'team will confirm the tax instead.',
                'options'       => [
                    ['value' => '15', 'label' => '15%', 'detail' => 'Sindh Sales Tax, for Karachi'],
                    ['value' => '16', 'label' => '16%', 'detail' => 'Punjab Sales Tax, for Lahore'],
                ],
                'allow_free_text' => true,
                'sort_order'    => $order++,
            ]);
            $asked++;
        }

        // A stand option with no figure at all is not usable.
        if ($kind === 'stall_package'
            && ($merged['price'] ?? null) === null
            && ($merged['rate_per_sqm'] ?? null) === null
            && $ask('price')) {
            self::ask($runId, [
                'kind'          => 'incomplete',
                'subject_key'   => $subjectKey,
                'subject_label' => $label,
                'claim_key'     => 'price',
                'question'      => 'No price was found for ' . $label . ', although ' . $sources
                                 . ' describes it. What should it cost?',
                'detail'        => 'Give either a single price, or a rate per square metre written as '
                                 . '"400 per sqm". Leave it blank to load the description without a price, '
                                 . 'in which case the assistant will not quote a figure for it.',
                'options'       => [
                    ['value' => 'exclude', 'label' => 'Load the description only, with no price', 'detail' => ''],
                ],
                'allow_free_text' => true,
                'sort_order'    => $order++,
            ]);
            $asked++;
        }

        return $asked;
    }

    /**
     * Fold an answer into the merged fields, so the completeness checks see
     * the world as it will be once the answer is applied.
     */
    private static function applyAnsweredClaim(array &$merged, string $claim, string $value): void
    {
        if (trim($value) === '') {
            return;
        }
        switch ($claim) {
            case 'currency':
                $currency = self::currencyIn($value) ?? strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
                if (strlen($currency) === 3) {
                    $merged['currency'] = $currency;
                }
                break;
            case 'tax_treatment':
                $merged['tax_treatment'] = self::canonicalValue('tax_treatment', $value);
                break;
            case 'tax_rate_percent':
                $rate = self::numberIn($value);
                if ($rate !== null) {
                    $merged['tax_rate_percent'] = $rate;
                    $merged['tax_treatment'] = 'excluded';
                }
                break;
            case 'price':
                if (strtolower(trim($value)) === 'exclude') {
                    $merged['price'] = null;
                    $merged['rate_per_sqm'] = null;
                    break;
                }
                $amount = self::numberIn($value);
                if ($amount !== null) {
                    if (preg_match('/per\s*(?:sqm|sq\.?\s?m|square)/i', $value)) {
                        $merged['rate_per_sqm'] = $amount;
                    } else {
                        $merged['price'] = $amount;
                    }
                }
                break;
            default:
                $number = self::numberIn($value);
                if ($number !== null && in_array($claim, ['min_sqm', 'increment_sqm', 'max_sqm', 'quantity_available'], true)) {
                    $merged[$claim] = $number;
                }
                break;
        }
    }

    // -----------------------------------------------------------------
    // Value handling
    // -----------------------------------------------------------------

    /**
     * Reduce a stated value to something comparable.
     *
     * "USD 20,000", "$20000" and "USD 20000.00" are the same number, and must
     * not be reported as three different answers. Equally, "15%" and "15" are
     * the same rate. Anything not recognised is compared as normalised text.
     */
    public static function canonicalValue(string $claimKey, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $moneyKeys = ['price', 'rate_per_sqm'];
        $numberKeys = ['min_sqm', 'increment_sqm', 'max_sqm', 'quantity_available',
                       'visitor_count', 'exhibitor_count', 'country_count',
                       'trade_passes', 'vip_passes', 'catalogue_words'];

        if (in_array($claimKey, $moneyKeys, true)) {
            $currency = self::currencyIn($value);
            $amount   = self::numberIn($value);
            if ($amount === null) {
                return self::normaliseText($value);
            }
            return ($currency ?? '') . ':' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        }

        if ($claimKey === 'tax_rate_percent') {
            $amount = self::numberIn($value);
            return $amount === null ? self::normaliseText($value) : rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        }

        if (in_array($claimKey, $numberKeys, true)) {
            $amount = self::numberIn($value);
            return $amount === null ? self::normaliseText($value) : rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        }

        if ($claimKey === 'currency') {
            $currency = self::currencyIn($value);
            return $currency ?? self::normaliseText($value);
        }

        if ($claimKey === 'tax_treatment') {
            $lower = mb_strtolower($value);
            if (str_contains($lower, 'exclu') || str_contains($lower, 'plus') || str_contains($lower, 'extra')) {
                return 'excluded';
            }
            if (str_contains($lower, 'inclu')) {
                return 'included';
            }
            return 'unstated';
        }

        return self::normaliseText($value);
    }

    /** Largest number in a string, ignoring thousands separators. */
    public static function numberIn(string $value): ?float
    {
        // Remove separators between digits, then find numeric runs.
        $cleaned = preg_replace('/(?<=\d)[,\s](?=\d{3}\b)/', '', $value) ?? $value;
        if (!preg_match_all('/\d+(?:\.\d+)?/', $cleaned, $matches)) {
            return null;
        }
        $best = 0.0;
        foreach ($matches[0] as $candidate) {
            $best = max($best, (float)$candidate);
        }
        return $best > 0 ? $best : null;
    }

    /** Recognise a currency from a code or a symbol. */
    public static function currencyIn(string $value): ?string
    {
        if (preg_match('/\b(USD|PKR|AED|SAR|EUR|GBP|CNY|RMB|INR|QAR|OMR|KWD|BHD)\b/i', $value, $m)) {
            return strtoupper($m[1]);
        }
        foreach (['$' => 'USD', '€' => 'EUR', '£' => 'GBP', 'Rs' => 'PKR', '₨' => 'PKR', '₹' => 'INR'] as $symbol => $code) {
            if (str_contains($value, $symbol)) {
                return $code;
            }
        }
        return null;
    }

    private static function normaliseText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\x{2010}-\x{2015}]/u', '-', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /** Editions are compared loosely, so "21st Karachi" matches "Karachi 21st". */
    public static function normaliseEdition(string $edition): string
    {
        $edition = mb_strtolower(trim($edition));
        if ($edition === '') {
            return '';
        }
        $edition = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $edition) ?? $edition;
        $words   = array_filter(explode(' ', trim($edition)), static fn($w) => $w !== '');
        sort($words);
        return implode(' ', $words);
    }

    private static function originalEdition(array $group): string
    {
        foreach ($group as $finding) {
            $label = trim((string)($finding['edition_label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }
        return 'edition not stated';
    }

    /** Merge the payloads in a group, first non-empty value winning. */
    public static function mergePayloads(array $group): array
    {
        $merged = [];
        foreach ($group as $finding) {
            $payload = json_decode((string)$finding['payload_json'], true);
            if (!is_array($payload)) {
                continue;
            }
            foreach ($payload as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                // "unstated" is the absence of information, not a value. A
                // document that says nothing about tax must not override one
                // that spells it out.
                if ($key === 'tax_treatment' && $value === 'unstated' && !empty($merged[$key])) {
                    continue;
                }
                if ($key === 'tax_treatment' && ($merged[$key] ?? null) === 'unstated' && $value !== 'unstated') {
                    $merged[$key] = $value;
                    continue;
                }
                if (!array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                    continue;
                }
                // Lists are unioned, because two documents often each list
                // part of what a package includes.
                if (is_array($merged[$key]) && is_array($value)) {
                    foreach ($value as $item) {
                        if (!in_array($item, $merged[$key], true)) {
                            $merged[$key][] = $item;
                        }
                    }
                }
            }
        }
        return $merged;
    }

    // -----------------------------------------------------------------
    // Sources and authority
    // -----------------------------------------------------------------

    /** @return string[] */
    private static function sourceNames(array $findings, array $documents): array
    {
        $names = [];
        foreach ($findings as $finding) {
            $id = (int)$finding['document_id'];
            $names[$id] = (string)($documents[$id]['original_name'] ?? 'an uploaded document');
        }
        return array_values($names);
    }

    private static function bestAuthority(array $findings, array $documents): int
    {
        $best = 0;
        foreach ($findings as $finding) {
            $id = (int)$finding['document_id'];
            $best = max($best, (int)($documents[$id]['authority'] ?? 5));
        }
        return $best;
    }

    /**
     * Settle a descriptive difference automatically only when one source is
     * clearly more authoritative than every other. A narrow margin is not
     * good enough, because that is precisely the uncertain case.
     */
    private static function resolveByAuthority(array $options): ?array
    {
        usort($options, static fn($a, $b) => (int)$b['authority'] <=> (int)$a['authority']);
        if (count($options) < 2) {
            return null;
        }
        $top = (int)$options[0]['authority'];
        $next = (int)$options[1]['authority'];
        return ($top - $next) >= 2 ? $options[0] : null;
    }

    // -----------------------------------------------------------------
    // Asking
    // -----------------------------------------------------------------

    private static function ask(int $runId, array $question): void
    {
        db()->insert('import_questions', [
            'run_id'          => $runId,
            'kind'            => (string)$question['kind'],
            'subject_key'     => mb_substr((string)$question['subject_key'], 0, 190),
            'subject_label'   => mb_substr((string)$question['subject_label'], 0, 190),
            'claim_key'       => isset($question['claim_key']) ? mb_substr((string)$question['claim_key'], 0, 80) : null,
            'question_text'   => (string)$question['question'],
            'detail_text'     => $question['detail'] ?? null,
            'options_json'    => !empty($question['options'])
                ? json_encode($question['options'], JSON_UNESCAPED_UNICODE)
                : null,
            'allow_free_text' => !empty($question['allow_free_text']) ? 1 : 0,
            'sort_order'      => (int)($question['sort_order'] ?? 0),
        ]);
    }

    private static function joinNames(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn($i) => $i !== ''));
        if ($items === []) {
            return 'an uploaded document';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    // -----------------------------------------------------------------
    // Answers
    // -----------------------------------------------------------------

    public static function questions(int $runId, ?string $status = null): array
    {
        $sql    = 'SELECT * FROM import_questions WHERE run_id = ?';
        $params = [$runId];
        if ($status !== null) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        return db()->all($sql . ' ORDER BY sort_order ASC, id ASC', $params);
    }

    public static function nextOpenQuestion(int $runId): ?array
    {
        return db()->one(
            "SELECT * FROM import_questions WHERE run_id = ? AND status = 'open'
             ORDER BY sort_order ASC, id ASC LIMIT 1",
            [$runId]
        ) ?: null;
    }

    /**
     * Record the operator's decision. This is the authoritative answer from
     * here on, and it is kept permanently as the reason the knowledge base
     * says what it says.
     */
    public static function answer(array $run, int $questionId, ?string $value, ?string $source, int $adminId, ?string $note = null): bool
    {
        $question = db()->one(
            'SELECT * FROM import_questions WHERE id = ? AND run_id = ? LIMIT 1',
            [$questionId, (int)$run['id']]
        );
        if (!$question) {
            return false;
        }

        $clean = Validator::text($value, 400);
        $skip  = ($source === 'skip') || ($clean === null && $source === null);

        db()->update('import_questions', [
            'answer_value' => $skip ? null : $clean,
            'answer_source' => mb_substr((string)($source ?? 'typed'), 0, 190),
            'answer_note'  => Validator::paragraph($note, 1000),
            'status'       => $skip ? 'skipped' : 'answered',
            'answered_by'  => $adminId,
            'answered_at'  => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$questionId]);

        Audit::log(
            'import_clarified',
            (int)$run['agent_id'],
            'import_question',
            $questionId,
            (string)$question['subject_label'] . ' / ' . (string)($question['claim_key'] ?? 'general'),
            (string)($question['claim_key'] ?? 'answer'),
            null,
            $skip ? '(skipped)' : (string)$clean
        );

        ImportRun::refreshCounts((int)$run['id']);
        return true;
    }

    /** Answers indexed for the proposal builder. */
    public static function answersBySubject(int $runId): array
    {
        $out = [];
        foreach (self::questions($runId) as $question) {
            if ((string)$question['status'] !== 'answered') {
                continue;
            }
            $key = (string)$question['subject_key'];
            $claim = (string)($question['claim_key'] ?? '');
            $out[$key][$claim] = [
                'value'    => (string)$question['answer_value'],
                'question' => (string)$question['question_text'],
                'kind'     => (string)$question['kind'],
            ];
        }
        return $out;
    }
}
