<?php
declare(strict_types=1);

/**
 * Turning findings and answers into the records that will actually be created.
 *
 * By this point the documents have been read, compared, and the operator has
 * settled anything the documents disagreed about. What remains is mechanical:
 * merge each subject's fields, apply the answers on top, translate into the
 * shape the platform stores, and note anything the operator should look at.
 *
 * Nothing here invents a value. Where a needed field is still missing after
 * the answers have been applied, the record is proposed without it and the
 * gap is reported as a warning, because a visible gap is safer than a
 * plausible guess.
 */
final class ProposalBuilder
{
    /**
     * Build the proposals for a run, replacing any built earlier.
     *
     * @return array{proposals:int,excluded:int}
     */
    public static function build(array $run): array
    {
        $runId = (int)$run['id'];

        // Anything the operator has already edited is kept; the rest is rebuilt.
        db()->exec("DELETE FROM import_proposals WHERE run_id = ? AND status = 'proposed'", [$runId]);

        $documents = [];
        foreach (ImportRun::documents($runId) as $doc) {
            $documents[(int)$doc['id']] = $doc;
        }

        $findings = db()->all(
            "SELECT * FROM import_findings
             WHERE run_id = ? AND status NOT IN ('discarded','duplicate')
             ORDER BY kind ASC, subject_key ASC, id ASC",
            [$runId]
        );

        $answers = FindingReconciler::answersBySubject($runId);
        $skipped = self::skippedSubjects($runId);

        // Group by subject and edition together: a Karachi price and a Lahore
        // price for the same package are two records, not one.
        $groups = [];
        foreach ($findings as $finding) {
            $edition = FindingReconciler::normaliseEdition((string)($finding['edition_label'] ?? ''));
            $groups[(string)$finding['subject_key'] . '##' . $edition][] = $finding;
        }

        // An edition chosen in answer to an "outdated" question wins; the
        // others are dropped rather than loaded alongside it.
        $chosenEdition = [];
        foreach ($answers as $subjectKey => $claims) {
            if (isset($claims['edition'])) {
                $chosenEdition[$subjectKey] = FindingReconciler::normaliseEdition((string)$claims['edition']['value']);
            }
        }

        $order    = 0;
        $made     = 0;
        $excluded = 0;

        foreach ($groups as $groupKey => $members) {
            [$subjectKey, $edition] = array_pad(explode('##', $groupKey, 2), 2, '');

            if (isset($chosenEdition[$subjectKey])
                && $edition !== ''
                && $edition !== $chosenEdition[$subjectKey]) {
                $excluded++;
                continue;
            }

            $proposal = self::assemble($run, $subjectKey, $members, $documents, $answers[$subjectKey] ?? [], $skipped);

            if ($proposal === null) {
                $excluded++;
                continue;
            }

            $proposal['sort_order'] = $order++;
            self::store($run, $proposal);
            $made++;
        }

        ImportRun::refreshCounts($runId);

        return ['proposals' => $made, 'excluded' => $excluded];
    }

    /** Subjects where the operator chose to leave something out. */
    private static function skippedSubjects(int $runId): array
    {
        $out = [];
        foreach (FindingReconciler::questions($runId) as $question) {
            if ((string)$question['status'] === 'skipped') {
                $out[(string)$question['subject_key']][] = (string)($question['claim_key'] ?? '');
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Assembly
    // -----------------------------------------------------------------

    private static function assemble(
        array $run,
        string $subjectKey,
        array $members,
        array $documents,
        array $answers,
        array $skipped
    ): ?array {
        $kind     = (string)$members[0]['kind'];
        $label    = (string)$members[0]['subject_label'];
        $merged   = FindingReconciler::mergePayloads($members);
        $warnings = [];

        // The operator can decide a whole subject is not wanted.
        foreach ($answers as $claim => $answer) {
            if (strtolower(trim((string)$answer['value'])) === 'exclude' && in_array($claim, ['price', 'currency'], true)) {
                if ($claim === 'currency') {
                    return null;   // a price we may not quote, so the record goes no further
                }
                $merged['price'] = null;
                $merged['rate_per_sqm'] = null;
                $warnings[] = 'Loaded without a price, as you asked. The assistant will not quote a figure for this.';
            }
        }

        // Apply each answer over the merged fields.
        foreach ($answers as $claim => $answer) {
            $value = trim((string)$answer['value']);
            if ($value === '' || $claim === 'edition') {
                continue;
            }
            self::applyAnswer($merged, $claim, $value, $warnings);
        }

        foreach ($skipped[$subjectKey] ?? [] as $claim) {
            $label2 = FindingExtractor::CLAIM_LABELS[$claim] ?? str_replace('_', ' ', (string)$claim);
            $warnings[] = 'You skipped the question about the ' . $label2 . ', so the documents\' own value is used.';
        }

        // Anything the model was unsure about travels with the proposal.
        foreach ($members as $finding) {
            if ((string)$finding['confidence'] === 'low') {
                $warnings[] = 'Read with low confidence from ' . self::documentName($documents, (int)$finding['document_id']) . '.';
            }
            $concerns = json_decode((string)($finding['concerns_json'] ?? '[]'), true);
            foreach (is_array($concerns) ? $concerns : [] as $concern) {
                $warnings[] = (string)$concern;
            }
        }

        $sources = self::sourceSummary($members, $documents);
        $edition = self::editionLabel($members, $answers);

        return match ($kind) {
            'stall_package'       => self::stallProposal($subjectKey, $label, $merged, $sources, $edition, $warnings, $answers),
            'sponsorship_package' => self::sponsorshipProposal($subjectKey, $label, $merged, $sources, $edition, $warnings, $answers),
            'faq'                 => self::faqProposal($subjectKey, $merged, $sources, $warnings, $answers),
            default               => self::knowledgeProposal($subjectKey, $merged, $sources, $edition, $warnings, $answers),
        };
    }

    /**
     * An answer replaces whatever the documents said. A rate is recognised
     * from wording like "400 per sqm", because that is how an operator
     * naturally types it.
     */
    private static function applyAnswer(array &$merged, string $claim, string $value, array &$warnings): void
    {
        switch ($claim) {
            case 'price':
                if (preg_match('/per\s*(?:sqm|sq\.?\s?m|square)/i', $value)) {
                    $rate = FindingReconciler::numberIn($value);
                    if ($rate !== null) {
                        $merged['rate_per_sqm'] = $rate;
                        $merged['price'] = null;
                    }
                } else {
                    $amount = FindingReconciler::numberIn($value);
                    if ($amount !== null) {
                        $merged['price'] = $amount;
                    }
                }
                $currency = FindingReconciler::currencyIn($value);
                if ($currency !== null) {
                    $merged['currency'] = $currency;
                }
                break;

            case 'rate_per_sqm':
                $rate = FindingReconciler::numberIn($value);
                if ($rate !== null) {
                    $merged['rate_per_sqm'] = $rate;
                }
                $currency = FindingReconciler::currencyIn($value);
                if ($currency !== null) {
                    $merged['currency'] = $currency;
                }
                break;

            case 'currency':
                $currency = FindingReconciler::currencyIn($value) ?? strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
                if (strlen($currency) === 3) {
                    $merged['currency'] = $currency;
                }
                break;

            case 'tax_rate_percent':
                $rate = FindingReconciler::numberIn($value);
                if ($rate !== null && $rate <= 100) {
                    $merged['tax_rate_percent'] = $rate;
                    $merged['tax_treatment'] = 'excluded';
                }
                break;

            case 'tax_treatment':
                $merged['tax_treatment'] = FindingReconciler::canonicalValue('tax_treatment', $value);
                break;

            case 'min_sqm':
            case 'increment_sqm':
            case 'max_sqm':
            case 'quantity_available':
                $number = FindingReconciler::numberIn($value);
                if ($number !== null) {
                    $merged[$claim] = $number;
                }
                break;

            case 'tax_label':
                $merged['tax_label'] = mb_substr($value, 0, 120);
                break;

            default:
                // A descriptive answer is recorded so the operator can see it
                // was taken into account, even where there is no field for it.
                $warnings[] = 'Your answer about the '
                    . (FindingExtractor::CLAIM_LABELS[$claim] ?? str_replace('_', ' ', $claim))
                    . ' was "' . mb_substr($value, 0, 120) . '". Check the wording below reflects it.';
                break;
        }
    }

    // -----------------------------------------------------------------
    // Per-type proposals
    // -----------------------------------------------------------------

    private static function stallProposal(
        string $subjectKey,
        string $label,
        array $merged,
        string $sources,
        ?string $edition,
        array $warnings,
        array $answers
    ): ?array {
        $rate  = isset($merged['rate_per_sqm']) ? (float)$merged['rate_per_sqm'] : null;
        $price = isset($merged['price']) ? (float)$merged['price'] : null;

        $currency = strtoupper((string)($merged['currency'] ?? ''));
        if (strlen($currency) !== 3) {
            $currency = 'USD';
            if ($rate !== null || $price !== null) {
                $warnings[] = 'No currency was stated, so US dollars is assumed. Check this before approving.';
            }
        }

        // Section 15: international pricing is US dollars only, so anything
        // else can only belong to a local price list.
        $region = $currency === 'USD' ? 'international' : 'pakistan';
        if (!in_array($currency, ['USD', 'PKR'], true)) {
            return null;
        }

        [$taxIncluded, $taxWarning] = self::taxFields($merged);
        if ($taxWarning !== null) {
            $warnings[] = $taxWarning;
        }

        $mode = $rate !== null ? 'per_sqm' : 'fixed';
        $min  = isset($merged['min_sqm']) ? (float)$merged['min_sqm'] : null;

        if ($mode === 'per_sqm' && $min === null) {
            $warnings[] = 'This is charged per square metre but no minimum size was found. '
                        . 'Set a minimum before approving, or the assistant cannot quote a size.';
        }
        if ($mode === 'per_sqm' && $min !== null && !isset($merged['increment_sqm'])) {
            $warnings[] = 'No size increment was found, so any size at or above the minimum can be quoted. '
                        . 'Set one if space is only sold in fixed steps.';
        }

        $payload = [
            'package_name'     => mb_substr((string)($merged['package_name'] ?? $label), 0, 160),
            'package_type'     => (string)($merged['package_type'] ?? 'shell'),
            'stall_size'       => mb_substr((string)($merged['stall_size'] ?? ''), 0, 64),
            'pricing_mode'     => $mode,
            'currency'         => $currency,
            'price'            => $price ?? ($rate !== null && $min !== null ? round($rate * $min, 2) : null),
            'rate_per_sqm'     => $rate,
            'min_sqm'          => $min,
            'increment_sqm'    => isset($merged['increment_sqm']) ? (float)$merged['increment_sqm'] : null,
            'max_sqm'          => isset($merged['max_sqm']) ? (float)$merged['max_sqm'] : null,
            'price_basis'      => $mode === 'per_sqm' ? 'per_sqm' : 'per_stall',
            'region_group'     => $region,
            'included_items'   => implode("\n", (array)($merged['included_items'] ?? [])),
            'description'      => (string)($merged['description'] ?? ''),
            'tax_included'     => $taxIncluded,
            'tax_rate_percent' => isset($merged['tax_rate_percent']) ? (float)$merged['tax_rate_percent'] : null,
            'tax_label'        => mb_substr((string)($merged['tax_label'] ?? ''), 0, 120),
            'edition_label'    => $edition,
        ];

        if ($payload['price'] === null && $payload['rate_per_sqm'] === null) {
            $warnings[] = 'No price on this record, so the assistant will describe it but not quote it.';
        }

        return [
            'target_type'    => 'stall_package',
            'subject_key'    => $subjectKey,
            'title'          => (string)$payload['package_name'],
            'payload'        => $payload,
            'source_summary' => $sources,
            'clarified'      => $answers !== [],
            'warnings'       => $warnings,
        ];
    }

    private static function sponsorshipProposal(
        string $subjectKey,
        string $label,
        array $merged,
        string $sources,
        ?string $edition,
        array $warnings,
        array $answers
    ): ?array {
        $price    = isset($merged['price']) ? (float)$merged['price'] : null;
        $currency = strtoupper((string)($merged['currency'] ?? ''));

        if (strlen($currency) !== 3) {
            $currency = 'USD';
            if ($price !== null) {
                $warnings[] = 'No currency was stated, so US dollars is assumed. Check this before approving.';
            }
        }
        if (!in_array($currency, ['USD', 'PKR'], true)) {
            return null;
        }

        [$taxIncluded, $taxWarning] = self::taxFields($merged);
        if ($taxWarning !== null) {
            $warnings[] = $taxWarning;
        }

        if ($price === null) {
            $warnings[] = 'No price was found for this tier, so it will not be quoted until you add one.';
        }
        if (!isset($merged['quantity_available'])) {
            $warnings[] = 'How many of this tier remain was not stated, so the assistant will say nothing '
                        . 'about availability. Set a number if you want it to.';
        }

        $payload = [
            'tier_name'          => mb_substr((string)($merged['tier_name'] ?? $label), 0, 160),
            'currency'           => $currency,
            'price'              => $price,
            'benefits'           => implode("\n", (array)($merged['benefits'] ?? [])),
            'quantity_available' => isset($merged['quantity_available']) ? (int)$merged['quantity_available'] : null,
            'region_group'       => $currency === 'USD' ? 'international' : 'pakistan',
            'description'        => (string)($merged['description'] ?? ''),
            'tax_included'       => $taxIncluded,
            'tax_rate_percent'   => isset($merged['tax_rate_percent']) ? (float)$merged['tax_rate_percent'] : null,
            'tax_label'          => mb_substr((string)($merged['tax_label'] ?? ''), 0, 120),
            'edition_label'      => $edition,
        ];

        return [
            'target_type'    => 'sponsorship_package',
            'subject_key'    => $subjectKey,
            'title'          => (string)$payload['tier_name'],
            'payload'        => $payload,
            'source_summary' => $sources,
            'clarified'      => $answers !== [],
            'warnings'       => $warnings,
        ];
    }

    private static function faqProposal(
        string $subjectKey,
        array $merged,
        string $sources,
        array $warnings,
        array $answers
    ): ?array {
        $question = trim((string)($merged['question'] ?? ''));
        $answer   = trim((string)($merged['answer'] ?? ''));

        if ($question === '' || mb_strlen($answer) < 10) {
            return null;
        }

        $warnings = array_merge($warnings, self::priceInProseWarnings($answer));

        return [
            'target_type'    => 'faq',
            'subject_key'    => $subjectKey,
            'title'          => mb_substr($question, 0, 255),
            'payload'        => [
                'question' => mb_substr($question, 0, 400),
                'answer'   => $answer,
                'category' => mb_substr((string)($merged['category'] ?? ''), 0, 80),
                'keywords' => mb_substr((string)($merged['keywords'] ?? ''), 0, 500),
            ],
            'source_summary' => $sources,
            'clarified'      => $answers !== [],
            'warnings'       => $warnings,
        ];
    }

    private static function knowledgeProposal(
        string $subjectKey,
        array $merged,
        string $sources,
        ?string $edition,
        array $warnings,
        array $answers
    ): ?array {
        $title = trim((string)($merged['title'] ?? ''));
        $body  = trim((string)($merged['body'] ?? ''));

        if ($title === '' || mb_strlen($body) < 25) {
            return null;
        }

        $warnings = array_merge($warnings, self::priceInProseWarnings($body));

        return [
            'target_type'    => 'knowledge_article',
            'subject_key'    => $subjectKey,
            'title'          => mb_substr($title, 0, 255),
            'payload'        => [
                'title'      => mb_substr($title, 0, 200),
                'body'       => $body,
                'category'   => mb_substr((string)($merged['category'] ?? ''), 0, 80),
                'keywords'   => mb_substr((string)($merged['keywords'] ?? ''), 0, 500),
                'stage_tags' => '',
            ],
            'source_summary' => $sources,
            'clarified'      => $answers !== [],
            'warnings'       => $warnings,
        ];
    }

    /**
     * A figure written into prose is not governed by the pricing records, so
     * it cannot be kept current, cannot carry tax, and cannot be withheld
     * from visitors who are not allowed to see pricing. The reply-level check
     * would suppress it anyway, which just makes the assistant look broken.
     * So it is called out here, where it can still be removed.
     */
    private static function priceInProseWarnings(string $text): array
    {
        $warnings = [];

        if (preg_match('/(?:USD|PKR|AED|EUR|GBP|\$|€|£|Rs\.?)\s*[0-9][0-9,\.]*/i', $text, $m)) {
            $warnings[] = 'This text contains a price ("' . trim($m[0]) . '"). Prices belong in the pricing '
                        . 'pages, not in wording — the assistant will refuse to repeat a figure that is not '
                        . 'in an approved price record. Remove it before approving.';
        }
        if (preg_match('/\b(?:stand|stall|booth)\s*(?:no\.?|number|#)\s*[A-Z0-9\-]+/i', $text, $m)) {
            $warnings[] = 'This text mentions a stand position ("' . trim($m[0]) . '"). The assistant is not '
                        . 'allowed to discuss stand numbers or floor positions, so remove it.';
        }

        return $warnings;
    }

    /**
     * Tax treatment, never guessed (Section 21). A rate without a stated
     * treatment is taken as exclusive, because that is what quoting a rate
     * separately means; the opposite is never assumed.
     */
    private static function taxFields(array $merged): array
    {
        $treatment = (string)($merged['tax_treatment'] ?? 'unstated');
        $rate      = isset($merged['tax_rate_percent']) ? (float)$merged['tax_rate_percent'] : null;

        if ($treatment === 'included') {
            return ['yes', null];
        }
        if ($treatment === 'excluded') {
            return $rate !== null
                ? ['no', null]
                : ['no', 'Tax is excluded but no rate was found, so the assistant will say the team must '
                        . 'confirm the tax instead of showing a total. Add the rate to fix that.'];
        }
        if ($rate !== null) {
            return ['no', 'The documents gave a tax rate but did not say whether it is already included. '
                        . 'It has been treated as added on top — check that is right.'];
        }

        return ['unspecified', 'The documents do not say whether tax is included. The assistant will tell '
                             . 'visitors the team will confirm it.'];
    }

    // -----------------------------------------------------------------
    // Provenance
    // -----------------------------------------------------------------

    private static function sourceSummary(array $members, array $documents): string
    {
        $names = [];
        foreach ($members as $member) {
            $names[(int)$member['document_id']] = self::documentName($documents, (int)$member['document_id']);
        }
        return mb_substr(implode(', ', array_values($names)), 0, 500);
    }

    private static function documentName(array $documents, int $id): string
    {
        return (string)($documents[$id]['original_name'] ?? 'an uploaded document');
    }

    private static function editionLabel(array $members, array $answers): ?string
    {
        if (isset($answers['edition'])) {
            $chosen = trim((string)$answers['edition']['value']);
            if ($chosen !== '') {
                return mb_substr($chosen, 0, 160);
            }
        }
        foreach ($members as $member) {
            $label = trim((string)($member['edition_label'] ?? ''));
            if ($label !== '') {
                return mb_substr($label, 0, 160);
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------

    private static function store(array $run, array $proposal): void
    {
        $warnings = array_values(array_unique(array_filter($proposal['warnings'])));

        db()->insert('import_proposals', [
            'run_id'         => (int)$run['id'],
            'agent_id'       => (int)$run['agent_id'],
            'target_type'    => (string)$proposal['target_type'],
            'subject_key'    => mb_substr((string)$proposal['subject_key'], 0, 190),
            'title'          => mb_substr((string)$proposal['title'], 0, 255),
            'payload_json'   => json_encode($proposal['payload'], JSON_UNESCAPED_UNICODE),
            'source_summary' => $proposal['source_summary'],
            'clarified'      => !empty($proposal['clarified']) ? 1 : 0,
            'warnings_json'  => $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null,
            'sort_order'     => (int)($proposal['sort_order'] ?? 0),
        ]);
    }

    public static function proposals(int $runId, ?string $targetType = null): array
    {
        $sql    = "SELECT * FROM import_proposals WHERE run_id = ? AND status <> 'excluded'";
        $params = [$runId];
        if ($targetType !== null) {
            $sql .= ' AND target_type = ?';
            $params[] = $targetType;
        }
        return db()->all($sql . ' ORDER BY target_type ASC, sort_order ASC, id ASC', $params);
    }

    public static function proposal(int $id, int $runId): ?array
    {
        return db()->one(
            'SELECT * FROM import_proposals WHERE id = ? AND run_id = ? LIMIT 1',
            [$id, $runId]
        ) ?: null;
    }

    public static function exclude(int $id, int $runId): bool
    {
        return db()->exec(
            "UPDATE import_proposals SET status = 'excluded' WHERE id = ? AND run_id = ?",
            [$id, $runId]
        ) > 0;
    }

    public static function restore(int $id, int $runId): bool
    {
        return db()->exec(
            "UPDATE import_proposals SET status = 'proposed' WHERE id = ? AND run_id = ? AND status = 'excluded'",
            [$id, $runId]
        ) > 0;
    }

    /** Save an operator's edit to a proposal before it is published. */
    public static function saveEdit(int $id, int $runId, array $fields): bool
    {
        $proposal = self::proposal($id, $runId);
        if (!$proposal) {
            return false;
        }

        $payload = json_decode((string)$proposal['payload_json'], true);
        $payload = is_array($payload) ? $payload : [];

        foreach ($fields as $key => $value) {
            if (!array_key_exists($key, $payload) && !in_array($key, [
                'title', 'body', 'question', 'answer', 'category', 'keywords',
                'package_name', 'tier_name', 'included_items', 'benefits',
                'price', 'rate_per_sqm', 'min_sqm', 'increment_sqm', 'max_sqm',
                'currency', 'tax_included', 'tax_rate_percent', 'tax_label',
                'quantity_available', 'stall_size', 'description', 'edition_label',
            ], true)) {
                continue;
            }
            $payload[$key] = $value;
        }

        $title = (string)($payload['package_name'] ?? $payload['tier_name'] ?? $payload['title'] ?? $payload['question'] ?? $proposal['title']);

        db()->update('import_proposals', [
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'title'        => mb_substr($title, 0, 255),
            'status'       => 'edited',
        ], 'id = ?', [$id]);

        return true;
    }
}
