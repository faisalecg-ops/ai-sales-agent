<?php
declare(strict_types=1);

/**
 * Pricing engine (Sections 15–22).
 *
 * Every displayed price originates here, from an approved database record.
 * The model receives matched records as immutable facts and never supplies
 * a number itself. A package_reference returned by the model is re-resolved
 * against the database before anything is shown (Section 20).
 */
final class PricingEngine
{
    public const TYPE_STALL       = 'stall';
    public const TYPE_SPONSORSHIP = 'sponsorship';

    /** Currencies Build Asia must never quote (Section 15). */
    public const FORBIDDEN_CURRENCIES = ['AED', 'SAR', 'EUR', 'GBP', 'RMB', 'CNY', 'PKR', 'INR', 'QAR', 'OMR', 'KWD', 'BHD'];

    // -----------------------------------------------------------------
    // Eligibility
    // -----------------------------------------------------------------

    /**
     * May this conversation be shown any price at all?
     *
     * Undetermined is treated exactly as Pakistan: no international pricing.
     * Pakistan sees pricing only if an approved Pakistan price list exists
     * for the agent (Section 16).
     *
     * @return array{allowed:bool,reason:string,region_groups:string[]}
     */
    public static function eligibility(array $agent, string $classification): array
    {
        $groups = GeoClassifier::regionGroupsFor($classification);

        if ($classification === GeoClassifier::UNDETERMINED) {
            return ['allowed' => false, 'reason' => 'country_undetermined', 'region_groups' => []];
        }

        if ($classification === GeoClassifier::PAKISTAN) {
            $hasPakistanList = self::countApproved((int)$agent['id'], ['pakistan', 'both']) > 0;
            return $hasPakistanList
                ? ['allowed' => true, 'reason' => 'pakistan_list_configured', 'region_groups' => $groups]
                : ['allowed' => false, 'reason' => 'pakistan_pricing_not_published', 'region_groups' => []];
        }

        return ['allowed' => true, 'reason' => 'international', 'region_groups' => $groups];
    }

    private static function countApproved(int $agentId, array $groups): int
    {
        $marks = implode(',', array_fill(0, count($groups), '?'));

        $stall = db()->all(
            "SELECT * FROM stall_packages
             WHERE agent_id = ? AND active = 1 AND status IN ('approved','pending_review')
               AND region_group IN ($marks) LIMIT 60",
            array_merge([$agentId], $groups)
        );
        $spon = db()->all(
            "SELECT * FROM sponsorship_packages
             WHERE agent_id = ? AND active = 1 AND status IN ('approved','pending_review')
               AND region_group IN ($marks) LIMIT 60",
            array_merge([$agentId], $groups)
        );

        return count(self::servable($stall, self::TYPE_STALL))
             + count(self::servable($spon, self::TYPE_SPONSORSHIP));
    }

    // -----------------------------------------------------------------
    // Retrieval
    // -----------------------------------------------------------------

    /**
     * The version of a record that may be shown to a visitor right now.
     *
     * An approved record is served as-is. A record whose approved version has
     * been edited sits at pending_review, and the previously approved values
     * remain live until the edit is approved in turn (Section 24). A record
     * that has never been approved is not served at all.
     *
     * @return array|null null when nothing about this record is live
     */
    public static function liveVersion(array $row): ?array
    {
        $status = (string)($row['status'] ?? '');

        if ($status === 'approved') {
            return $row;
        }
        if ($status !== 'pending_review') {
            return null;   // draft or archived
        }

        $snapshot = json_decode((string)($row['approved_snapshot'] ?? ''), true);
        if (!is_array($snapshot) || !$snapshot) {
            return null;   // edited before it was ever approved
        }

        $merged = array_merge($row, $snapshot);
        $merged['id']            = $row['id'];
        $merged['agent_id']      = $row['agent_id'];
        $merged['status']        = 'approved';
        $merged['_pending_edit'] = true;
        return $merged;
    }

    /** Is this record inside its validity window today? */
    public static function withinValidity(array $row): bool
    {
        $today = gmdate('Y-m-d');
        $from  = trim((string)($row['valid_from'] ?? ''));
        $until = trim((string)($row['valid_until'] ?? ''));

        if ($from !== '' && $from > $today) {
            return false;
        }
        if ($until !== '' && $until < $today) {
            return false;
        }
        return true;
    }

    /**
     * Filter a fetched set down to what a visitor may actually be shown.
     */
    private static function servable(array $rows, string $type): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((int)($row['active'] ?? 0) !== 1) {
                continue;
            }
            $live = self::liveVersion($row);
            if ($live === null || !self::withinValidity($live) || !self::recordIsValid($live, $type)) {
                continue;
            }
            // A tier with its inventory set to zero is fully booked and is
            // not offered. A blank inventory means it is simply not tracked,
            // and nothing is said about availability either way.
            $qty = $live['quantity_available'] ?? null;
            if ($qty !== null && $qty !== '' && (int)$qty <= 0) {
                continue;
            }
            $out[] = $live;
        }
        return $out;
    }

    /**
     * Approved, active, in-date stall packages for the eligible regions.
     */
    public static function stallPackages(int $agentId, array $regionGroups, int $limit = 12): array
    {
        if (!$regionGroups) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($regionGroups), '?'));
        $rows  = db()->all(
            "SELECT * FROM stall_packages
             WHERE agent_id = ? AND active = 1
               AND status IN ('approved','pending_review')
               AND region_group IN ($marks)
             ORDER BY sort_order ASC, size_sqm ASC, price ASC
             LIMIT 60",
            array_merge([$agentId], $regionGroups)
        );
        return array_slice(self::servable($rows, self::TYPE_STALL), 0, max(1, min(50, $limit)));
    }

    public static function sponsorshipPackages(int $agentId, array $regionGroups, int $limit = 12): array
    {
        if (!$regionGroups) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($regionGroups), '?'));
        $rows  = db()->all(
            "SELECT * FROM sponsorship_packages
             WHERE agent_id = ? AND active = 1
               AND status IN ('approved','pending_review')
               AND region_group IN ($marks)
             ORDER BY sort_order ASC, price DESC
             LIMIT 60",
            array_merge([$agentId], $regionGroups)
        );
        return array_slice(self::servable($rows, self::TYPE_SPONSORSHIP), 0, max(1, min(50, $limit)));
    }

    /**
     * Resolve a package_reference returned by the model (Section 20).
     * Format: "stall:12" or "sponsorship:3". Anything else resolves to null.
     *
     * The agent_id and region eligibility are re-checked here, so a model
     * that names a package it should not have seen still yields nothing.
     */
    public static function resolveReference(
        ?string $reference,
        int $agentId,
        array $regionGroups
    ): ?array {
        if ($reference === null || $reference === '') {
            return null;
        }
        if (!preg_match('/^(stall|sponsorship)\s*[:#-]\s*(\d{1,10})$/i', trim($reference), $m)) {
            return null;
        }
        $type = strtolower($m[1]);
        $id   = (int)$m[2];
        if ($id <= 0 || !$regionGroups) {
            return null;
        }

        $table = $type === self::TYPE_STALL ? 'stall_packages' : 'sponsorship_packages';
        $marks = implode(',', array_fill(0, count($regionGroups), '?'));

        $row = db()->one(
            "SELECT * FROM {$table}
             WHERE id = ? AND agent_id = ? AND active = 1
               AND status IN ('approved','pending_review')
               AND region_group IN ($marks)
             LIMIT 1",
            array_merge([$id, $agentId], $regionGroups)
        );
        if (!$row) {
            return null;
        }

        $servable = self::servable([$row], $type);
        if (!$servable) {
            return null;
        }
        $live = $servable[0];
        $live['_type'] = $type;
        return $live;
    }

    // -----------------------------------------------------------------
    // Validation (Sections 15, 17)
    // -----------------------------------------------------------------

    /**
     * A record that violates the currency/region rule is never shown,
     * regardless of its status flags.
     *
     * @return string[] list of problems; empty means valid
     */
    public static function validateRecord(array $record, string $type, array $agent): array
    {
        $problems  = [];
        $currency  = strtoupper(trim((string)($record['currency'] ?? '')));
        $region    = (string)($record['region_group'] ?? '');
        $price     = (float)($record['price'] ?? 0);
        $intlCcy   = strtoupper((string)($agent['international_currency'] ?? 'USD'));

        if ($price <= 0) {
            $problems[] = 'Price must be greater than zero.';
        }
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            $problems[] = 'Currency must be a three-letter code.';
        }
        if (in_array($region, ['international', 'both'], true) && $currency !== $intlCcy) {
            $problems[] = "International pricing must be quoted in {$intlCcy}. This record uses {$currency}.";
        }
        if ($region === 'pakistan' && $currency === $intlCcy && $intlCcy === 'USD') {
            // Not fatal, but flagged: a Pakistan-region record priced in USD is
            // usually a data-entry mistake worth an administrator's attention.
            $problems[] = 'Pakistan-region record is priced in USD — confirm this is intended.';
        }

        $validFrom  = (string)($record['valid_from'] ?? '');
        $validUntil = (string)($record['valid_until'] ?? '');
        if ($validFrom !== '' && $validUntil !== '' && $validFrom > $validUntil) {
            $problems[] = 'Valid-from date is after the valid-until date.';
        }

        if ($type === self::TYPE_STALL) {
            if (trim((string)($record['stall_size'] ?? '')) === '') {
                $problems[] = 'Stall size is required.';
            }
        } else {
            if (trim((string)($record['tier_name'] ?? '')) === '') {
                $problems[] = 'Tier name is required.';
            }
        }

        return $problems;
    }

    /**
     * Runtime guard applied to every record before it reaches a visitor.
     * Deliberately stricter than validateRecord: it fails closed.
     */
    public static function recordIsValid(array $record, string $type): bool
    {
        $currency = strtoupper(trim((string)($record['currency'] ?? '')));
        $region   = (string)($record['region_group'] ?? '');
        $price    = (float)($record['price'] ?? 0);

        if ($price <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return false;
        }
        // International records must be USD. Nothing else is displayable.
        if (in_array($region, ['international', 'both'], true) && $currency !== 'USD') {
            app_log('pricing', 'blocked non-USD international record', [
                'type' => $type, 'id' => (int)($record['id'] ?? 0), 'currency' => $currency,
            ]);
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Size-based quoting (Section 2: PHP does the arithmetic, never the model)
    // -----------------------------------------------------------------

    /**
     * Read a requested floor area out of the visitor's own words.
     * Understands "27 sqm", "27 square metres", "6m x 6m" and "3 x 6".
     *
     * Returns null when nothing is stated, so a quote is never produced
     * from a number the visitor did not give.
     */
    public static function requestedSize(string $message): ?float
    {
        // Explicit area: "27 sqm", "27 m2", "27 square meters"
        if (preg_match('/\b(\d{1,4}(?:\.\d{1,2})?)\s*(?:sqm|sq\.?\s?m|m2|m²|square\s?met(?:re|er)s?)\b/i', $message, $m)) {
            $v = (float)$m[1];
            return $v > 0 ? $v : null;
        }
        // Dimensions: "6m x 6m", "3 x 6", "6 by 9"
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,2})?)\s*(?:m|meters?|metres?)?\s*(?:x|×|by)\s*(\d{1,3}(?:\.\d{1,2})?)\s*(?:m|meters?|metres?)?\b/i', $message, $m)) {
            $area = (float)$m[1] * (float)$m[2];
            return $area > 0 ? $area : null;
        }
        return null;
    }

    /**
     * Price a rate-based package at a particular size.
     *
     * Returns an explanation instead of a figure when the size cannot be
     * sold: below the minimum, not a whole number of modules, or above the
     * point where the team prices it by hand. No figure is ever guessed.
     *
     * @return array{ok:bool,sqm:float,reason:?string,message:?string}
     */
    public static function checkSize(array $record, float $sqm): array
    {
        $min  = (float)($record['min_sqm'] ?? 0);
        $inc  = (float)($record['increment_sqm'] ?? 0);
        $max  = (float)($record['max_sqm'] ?? 0);
        $name = (string)($record['package_name'] ?? 'this option');

        if ($sqm <= 0) {
            return ['ok' => false, 'sqm' => $sqm, 'reason' => 'invalid', 'message' => null];
        }

        if ($min > 0 && $sqm < $min) {
            return [
                'ok' => false, 'sqm' => $sqm, 'reason' => 'below_minimum',
                'message' => $name . ' starts at ' . self::sqmText($min) . '.',
            ];
        }

        if ($max > 0 && $sqm > $max) {
            return [
                'ok' => false, 'sqm' => $sqm, 'reason' => 'above_maximum',
                'message' => 'For ' . self::sqmText($sqm) . ' the team prepares the quotation directly.',
            ];
        }

        if ($inc > 0) {
            $units = $sqm / $inc;
            if (abs($units - round($units)) > 0.001) {
                $lower = max($min, floor($units) * $inc);
                $upper = ceil($units) * $inc;
                $how   = $min > 0 && $min !== $inc
                    ? $name . ' starts at ' . self::sqmText($min) . ' and increases in '
                        . self::sqmText($inc) . ' steps'
                    : $name . ' is sold in units of ' . self::sqmText($inc);
                return [
                    'ok' => false, 'sqm' => $sqm, 'reason' => 'not_a_whole_module',
                    'message' => $how . ', so the nearest sizes are ' . self::sqmText($lower)
                        . ' and ' . self::sqmText($upper) . '.',
                ];
            }
        }

        return ['ok' => true, 'sqm' => $sqm, 'reason' => null, 'message' => null];
    }

    /** The visitor-facing name of each kind of stand. */
    public static function kindLabel(string $packageType): string
    {
        return match ($packageType) {
            'shell'   => 'Shell scheme',
            'outdoor' => 'Outdoor space',
            default   => 'Bare space',
        };
    }

    /**
     * The size a rate-based record is shown at when the visitor has not
     * given one: its minimum. Outdoor space may be published with a rate and
     * no minimum; it is then shown per single square metre, which is the
     * published rate itself and not an invented size.
     */
    public static function referenceSize(array $record): float
    {
        $min = (float)($record['min_sqm'] ?? 0);
        if ($min > 0) {
            return $min;
        }
        return (string)($record['package_type'] ?? '') === 'outdoor' ? 1.0 : 0.0;
    }

    private static function sqmText(float $sqm): string
    {
        $whole = (fmod($sqm, 1.0) === 0.0);
        return number_format($sqm, $whole ? 0 : 2) . ' sqm';
    }

    /**
     * The base price of a record at a given size.
     * For a fixed package the stored price is used unchanged.
     */
    public static function basePrice(array $record, ?float $sqm = null): float
    {
        if ((string)($record['pricing_mode'] ?? 'fixed') !== 'per_sqm') {
            return (float)$record['price'];
        }
        $rate = (float)($record['rate_per_sqm'] ?? 0);
        $size = $sqm ?? self::referenceSize($record);
        if ($rate <= 0 || $size <= 0) {
            return (float)$record['price'];
        }
        return round($rate * $size, 2);
    }

    /**
     * Tax and total, calculated here in PHP from an approved rate.
     * Nothing is inferred: with no configured rate, no figure is produced
     * and the card says the treatment is unconfirmed (Section 21).
     *
     * @return array{mode:string,rate:?float,label:?string,tax:?float,total:?float}
     */
    public static function taxBreakdown(array $record, float $base): array
    {
        $included = (string)($record['tax_included'] ?? 'unspecified');
        $rate     = $record['tax_rate_percent'] ?? null;
        $rate     = ($rate === null || $rate === '') ? null : (float)$rate;
        $label    = trim((string)($record['tax_label'] ?? '')) ?: null;

        if ($included === 'yes') {
            return ['mode' => 'inclusive', 'rate' => $rate, 'label' => $label, 'tax' => null, 'total' => $base];
        }
        if ($included === 'no' && $rate !== null && $rate > 0) {
            $tax = round($base * ($rate / 100), 2);
            return [
                'mode'  => 'exclusive',
                'rate'  => $rate,
                'label' => $label,
                'tax'   => $tax,
                'total' => round($base + $tax, 2),
            ];
        }
        if ($included === 'no') {
            return ['mode' => 'exclusive_rate_unknown', 'rate' => null, 'label' => $label, 'tax' => null, 'total' => null];
        }
        return ['mode' => 'unspecified', 'rate' => null, 'label' => $label, 'tax' => null, 'total' => null];
    }


    /**
     * Build the visitor-facing card for a record. PHP composes every
     * number and currency symbol here; the model never supplies them.
     *
     * @param float|null $sqm size the visitor asked about, for rate-based records
     */
    public static function toCard(array $record, string $type, ?float $sqm = null): array
    {
        $currency = strtoupper((string)$record['currency']);
        $perSqm   = (string)($record['pricing_mode'] ?? 'fixed') === 'per_sqm';
        $size     = $perSqm ? ($sqm ?? self::referenceSize($record)) : null;
        $base     = self::basePrice($record, $size);
        $tax      = self::taxBreakdown($record, $base);

        if ($type === self::TYPE_STALL) {
            $title  = (string)$record['package_name'];
            $scheme = self::kindLabel((string)$record['package_type']);
            $items  = self::lines((string)($record['included_items'] ?? ''));
            $meta   = [$scheme];

            if ($perSqm && $size > 0) {
                $meta[] = self::sqmText($size);
                $meta[] = self::formatPrice((float)$record['rate_per_sqm'], $currency) . ' per sqm';
            } elseif (trim((string)$record['stall_size']) !== '') {
                $meta[] = trim((string)$record['stall_size']);
            }
        } else {
            $title = (string)$record['tier_name'];
            $items = self::lines((string)($record['benefits'] ?? ''));
            $meta  = [];
            $qty   = $record['quantity_available'] ?? null;
            if ($qty !== null && $qty !== '' && (int)$qty > 0) {
                $meta[] = (int)$qty === 1 ? '1 slot available' : ((int)$qty) . ' slots available';
            }
        }

        if (!empty($record['edition_label'])) {
            $meta[] = (string)$record['edition_label'];
        }

        // The price block. Each line is produced here, in PHP, from the
        // approved record — base, tax and total alike.
        $lines = [['label' => $perSqm ? 'Participation charge' : 'Price', 'value' => self::formatPrice($base, $currency)]];

        if ($tax['mode'] === 'exclusive') {
            $taxLabel = ($tax['label'] ?? 'Tax') . ' at ' . self::percentText((float)$tax['rate']);
            $lines[] = ['label' => $taxLabel, 'value' => self::formatPrice((float)$tax['tax'], $currency)];
            $lines[] = ['label' => 'Total', 'value' => self::formatPrice((float)$tax['total'], $currency), 'emphasis' => true];
        }

        return [
            'reference'   => $type . ':' . (int)$record['id'],
            'type'        => $type,
            'title'       => $title,
            'meta'        => array_values(array_filter($meta)),
            'price_text'  => self::formatPrice($base, $currency),
            'price_lines' => $lines,
            'price_basis' => $type === self::TYPE_STALL && !$perSqm
                ? self::basisLabel((string)($record['price_basis'] ?? ''))
                : null,
            'currency'    => $currency,
            'sqm'         => $size,
            'items'       => array_slice($items, 0, 12),
            'tax_note'    => self::taxStatement($record, $tax),
            'description' => Validator::paragraph((string)($record['description'] ?? ''), 400),
        ];
    }

    private static function percentText(float $rate): string
    {
        $whole = (fmod($rate, 1.0) === 0.0);
        return number_format($rate, $whole ? 0 : 2) . '%';
    }

    /** Money formatting. Tabular, no locale surprises. */
    public static function formatPrice(float $price, string $currency): string
    {
        $whole = (fmod($price, 1.0) === 0.0);
        return $currency . ' ' . number_format($price, $whole ? 0 : 2);
    }

    private static function basisLabel(string $basis): ?string
    {
        return match ($basis) {
            'per_sqm'   => 'per square metre',
            'per_stall' => 'per stall',
            default     => null,
        };
    }

    /**
     * Tax treatment is never guessed (Section 21).
     */
    public static function taxStatement(array $record, ?array $tax = null): string
    {
        $note = trim((string)($record['tax_note'] ?? ''));
        $tax ??= self::taxBreakdown($record, (float)$record['price']);

        return match ($tax['mode']) {
            'inclusive' => $note !== '' ? $note : 'Price includes applicable taxes.',
            'exclusive' => $note !== ''
                ? $note
                : ($tax['label'] ?? 'Government tax') . ' is shown above and is payable on the participation charge.',
            'exclusive_rate_unknown' => $note !== ''
                ? $note
                : 'Price excludes government taxes. The team will confirm the exact amount.',
            default => 'Tax treatment is not specified for this package — the team can confirm it.',
        };
    }

    /**
     * The immutable fact block handed to the model. Every figure appears
     * here already formatted, including the tax and total, so there is
     * nothing left for the model to compute.
     */
    public static function factsForPrompt(array $records, string $type, ?float $sqm = null): array
    {
        $facts = [];
        foreach ($records as $r) {
            $perSqm = (string)($r['pricing_mode'] ?? 'fixed') === 'per_sqm';

            // A rate-based record is quoted at the size the visitor asked
            // about, provided that size can actually be sold.
            $useSize = null;
            $sizeNote = null;
            if ($perSqm) {
                if ($sqm !== null) {
                    $check = self::checkSize($r, $sqm);
                    if ($check['ok']) {
                        $useSize = $sqm;
                    } else {
                        $sizeNote = $check['message'];
                    }
                }
                $useSize ??= self::referenceSize($r);
            }

            $card = self::toCard($r, $type, $useSize);

            $priceText = [];
            foreach ($card['price_lines'] as $line) {
                $priceText[] = $line['label'] . ': ' . $line['value'];
            }

            $fact = [
                'reference' => $card['reference'],
                'name'      => $card['title'],
                'details'   => implode(', ', $card['meta']),
                'price'     => implode(' | ', $priceText),
                // The whole price on one line, ready to copy (fix plan step 1).
                'line'      => ChatGuide::quoteLine($card),
                'includes'  => $card['items'],
                'tax'       => $card['tax_note'],
                'quantity'  => $type === self::TYPE_SPONSORSHIP ? ($r['quantity_available'] ?? null) : null,
            ];

            if ($perSqm) {
                $minimum = (float)($r['min_sqm'] ?? 0);
                $fact['sold_as'] = 'Charged per square metre. '
                    . ($minimum > 0 || (string)($r['package_type'] ?? '') !== 'outdoor'
                        ? 'Minimum ' . self::sqmText($minimum)
                        : 'No minimum size is published')
                    . ($r['increment_sqm'] ? ', in units of ' . self::sqmText((float)$r['increment_sqm']) : '')
                    . '. The figures above are for ' . self::sqmText((float)$card['sqm']) . '.';
            }
            if ($sizeNote !== null) {
                $fact['size_note'] = $sizeNote;
            }

            $facts[] = $fact;
        }
        return $facts;
    }

    /**
     * What is on offer, described without any price (approved 11 November 2026).
     *
     * A visitor who asks to be briefed on the packages or the sponsorship
     * options has not asked a price, so no price facts are prepared for that
     * turn; before this, the assistant then had nothing to describe. This gives
     * it the names, sizes, inclusions and tier benefits of every live record.
     * It is the same for every visitor, whatever their country: a visitor in
     * Pakistan is told what is offered and is passed to the local sales
     * representative when price comes up.
     *
     * Nothing here may carry a figure of money: anything that looks like one
     * is dropped, and the reply guard still discards any price in the answer.
     *
     * @return array<int, array{kind:string,name:string,details:string,includes:array,sold_as:string}>
     */
    public static function catalogueForPrompt(int $agentId): array
    {
        $money = '/(?:\b[A-Z]{3}\b\s?\d|[$€£¥₨]|\b(?:Rs|PKR|USD|EUR|GBP|AED)\b)/u';
        $out   = [];
        $seen  = [];

        foreach ([
            [self::TYPE_STALL, 'stall_packages', 'Participation option'],
            [self::TYPE_SPONSORSHIP, 'sponsorship_packages', 'Sponsorship tier'],
        ] as [$type, $table, $kind]) {
            $rows = db()->all(
                "SELECT * FROM {$table}
                 WHERE agent_id = ? AND active = 1 AND status IN ('approved','pending_review')
                 ORDER BY sort_order ASC, id ASC LIMIT 60",
                [$agentId]
            );
            foreach (self::servable($rows, $type) as $r) {
                $card = self::toCard($r, $type, null);
                $name = trim((string)$card['title']);
                $key  = $type . '|' . mb_strtolower($name);
                if ($name === '' || isset($seen[$key])) {
                    continue;   // the same package priced for another region
                }
                $seen[$key] = true;

                $clean = static fn(array $list): array => array_values(array_filter(
                    array_map(static fn($v) => trim((string)$v), $list),
                    static fn(string $v): bool => $v !== '' && !preg_match($money, $v)
                ));

                $soldAs = '';
                if ($type === self::TYPE_STALL && (string)($r['pricing_mode'] ?? 'fixed') === 'per_sqm') {
                    $minimum = (float)($r['min_sqm'] ?? 0);
                    $soldAs  = 'Charged per square metre. '
                        . ($minimum > 0 ? 'Minimum ' . self::sqmText($minimum) : 'No minimum size is published')
                        . (!empty($r['increment_sqm']) ? ', in units of ' . self::sqmText((float)$r['increment_sqm']) : '')
                        . '.';
                }

                $meta = $clean($card['meta'] ?? []);
                if ($soldAs !== '' && (float)($r['min_sqm'] ?? 0) <= 0) {
                    // No minimum: the card's "1 sqm" is only a pricing reference, not a size on offer.
                    $meta = array_values(array_filter($meta, static fn(string $v): bool => !preg_match('/^[\d.]+\s*sqm$/i', $v)));
                }

                $out[] = [
                    'kind'     => $kind,
                    'name'     => $name,
                    'details'  => implode(', ', $meta),
                    'includes' => $clean($card['items'] ?? []),
                    'sold_as'  => $soldAs,
                ];
            }
        }
        return $out;
    }

    /** Every figure a reply is permitted to contain, given these facts. */
    public static function allowedFigures(array $records, string $type, ?float $sqm = null): array
    {
        $allowed = [];
        foreach (self::factsForPrompt($records, $type, $sqm) as $fact) {
            foreach (explode(' | ', (string)$fact['price']) as $part) {
                $pos = strrpos($part, ': ');
                if ($pos !== false) {
                    $allowed[] = substr($part, $pos + 2);
                }
            }
        }
        return $allowed;
    }

    /**
     * Verify that any currency or number the model emitted in its reply
     * actually appears in the approved facts (Section 20, first option).
     * Returns the offending fragment, or null when the reply is clean.
     */
    public static function findUnauthorisedPrice(string $reply, array $allowedPriceStrings): ?string
    {
        // Any three-letter currency code the agent may not quote.
        foreach (self::FORBIDDEN_CURRENCIES as $ccy) {
            if (preg_match('/(?:^|[^A-Z])' . $ccy . '(?:[^A-Z]|$)/', strtoupper($reply))) {
                return $ccy;
            }
        }
        foreach (['€', '£', '₨', '₹', '﷼', 'د.إ'] as $symbol) {
            if (str_contains($reply, $symbol)) {
                return $symbol;
            }
        }

        // Any monetary amount must match one of the approved price strings.
        $allowedNumbers = [];
        foreach ($allowedPriceStrings as $p) {
            $digits = preg_replace('/[^0-9]/', '', $p) ?? '';
            if ($digits !== '') {
                $allowedNumbers[$digits] = true;
            }
        }

        if (preg_match_all('/(?:USD|\$)\s*([0-9][0-9,\.]*[0-9])/i', $reply, $m)) {
            foreach ($m[1] as $found) {
                $digits = preg_replace('/[^0-9]/', '', $found) ?? '';
                // Trailing ".00" is equivalent to the whole-number form.
                $trimmed = rtrim($digits, '0');
                if (!isset($allowedNumbers[$digits])
                    && !isset($allowedNumbers[rtrim($digits, '0')])
                    && !isset($allowedNumbers[$digits . '00'])
                    && $trimmed !== '') {
                    return 'USD ' . $found;
                }
            }
        }

        return null;
    }

    /** Split a newline/semicolon separated field into clean lines. */
    public static function lines(string $raw): array
    {
        $parts = preg_split('/[\r\n]+|;\s*/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/^[\-\*\x{2022}]\s*/u', '', trim($p)) ?? '');
            if ($p !== '') {
                $out[] = mb_substr($p, 0, 160);
            }
        }
        return $out;
    }
}
