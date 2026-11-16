<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require __DIR__ . '/_content.php';

content_page('stall_package', [
    'title'    => 'Stand pricing',
    'singular' => 'price record',
    'file'     => 'pricing.php',
    'order'    => 'sort_order ASC, package_name ASC',
    'intro'    => 'Every price a visitor sees comes from here. The assistant is never allowed to work out a figure of its own — it receives these numbers already calculated.',
    'columns'  => [
        ['label' => 'Name', 'value' => static fn($i) => e((string)$i['package_name'])],
        ['label' => 'Type', 'value' => static fn($i) => e(PricingEngine::kindLabel((string)$i['package_type']))],
        ['label' => 'How it is priced', 'value' => static function ($i) {
            return (string)$i['pricing_mode'] === 'per_sqm'
                ? e(PricingEngine::formatPrice((float)$i['rate_per_sqm'], (string)$i['currency']) . ' per sqm'
                    . ((float)($i['min_sqm'] ?? 0) > 0
                        ? ', from ' . rtrim(rtrim(number_format((float)$i['min_sqm'], 2), '0'), '.') . ' sqm'
                        : ', no minimum'))
                : e(PricingEngine::formatPrice((float)$i['price'], (string)$i['currency']));
        }],
        ['label' => 'Tax', 'value' => static fn($i) => $i['tax_rate_percent'] !== null
            ? e(rtrim(rtrim(number_format((float)$i['tax_rate_percent'], 2), '0'), '.') . '%')
            : '<span class="muted">not set</span>'],
        ['label' => 'Region', 'value' => static fn($i) => e(ucfirst((string)$i['region_group']))],
    ],
    'validate' => static function (array $v, array $agent): array {
        $errors = [];
        $intl = strtoupper((string)($agent['international_currency'] ?? 'USD'));

        if (in_array((string)$v['region_group'], ['international', 'both'], true)
            && strtoupper((string)$v['currency']) !== $intl) {
            $errors[] = 'International pricing must be in ' . $intl . '. A record in any other currency would never be shown to a visitor.';
        }
        if ((string)$v['pricing_mode'] === 'per_sqm') {
            if ((float)($v['rate_per_sqm'] ?? 0) <= 0) {
                $errors[] = 'Enter the rate per square metre.';
            }
            // Outdoor space may be published with a rate and no minimum.
            if ((float)($v['min_sqm'] ?? 0) <= 0 && (string)($v['package_type'] ?? '') !== 'outdoor') {
                $errors[] = 'Enter the smallest size that can be booked.';
            }
        } elseif ((float)($v['price'] ?? 0) <= 0) {
            $errors[] = 'Enter a price greater than zero.';
        }
        if ((string)$v['tax_included'] === 'no'
            && ($v['tax_rate_percent'] === null || (float)$v['tax_rate_percent'] <= 0)) {
            $errors[] = 'Tax is marked as excluded, so enter the tax rate. Without it the assistant cannot show a total and will say the team must confirm the tax.';
        }
        return $errors;
    },
    'fields' => [
        'package_name' => ['type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 160, 'group' => 'What it is',
            'hint' => 'What a visitor would recognise, for example Shell Scheme Stand.'],
        'package_type' => ['type' => 'select', 'label' => 'Kind of stand', 'default' => 'shell', 'group' => 'What it is',
            'options' => ['shell' => 'Shell scheme (built for them)', 'bare' => 'Bare space (they build it)', 'outdoor' => 'Outdoor space (open area outside the halls)']],
        'stall_size' => ['type' => 'text', 'label' => 'Size description', 'max' => 64, 'group' => 'What it is',
            'hint' => 'Shown on the card, for example Built in 3m x 3m modules.'],
        'edition_label' => ['type' => 'text', 'label' => 'Which edition', 'max' => 160, 'group' => 'What it is',
            'hint' => 'For example 21st Build Asia, Karachi, 18-20 March 2027. Shown on the card so nobody confuses two editions.'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 3, 'max' => 2000, 'group' => 'What it is'],
        'included_items' => ['type' => 'textarea', 'label' => 'What is included', 'rows' => 8, 'max' => 4000, 'group' => 'What it is',
            'hint' => 'One item per line. These appear as a checklist on the price card.'],

        'pricing_mode' => ['type' => 'select', 'label' => 'How it is priced', 'default' => 'fixed', 'group' => 'Price',
            'options' => ['fixed' => 'One fixed price', 'per_sqm' => 'A rate per square metre'],
            'hint' => 'Choose the rate option when a visitor can book any qualifying size. The system multiplies it out.'],
        'currency' => ['type' => 'text', 'label' => 'Currency', 'max' => 3, 'default' => 'USD', 'required' => true, 'group' => 'Price',
            'hint' => 'Three letters. International records must be USD.'],
        'price' => ['type' => 'decimal', 'label' => 'Fixed price', 'group' => 'Price',
            'hint' => 'Used when the price is fixed. With a rate per square metre, put the price of the smallest bookable size here for reference.'],
        'rate_per_sqm' => ['type' => 'decimal', 'label' => 'Rate per square metre', 'group' => 'Price'],
        'min_sqm' => ['type' => 'decimal', 'label' => 'Smallest size (sqm)', 'group' => 'Price',
            'hint' => 'A visitor asking for less is told the minimum rather than given a figure. May be left blank for outdoor space when the tariff gives no minimum.'],
        'increment_sqm' => ['type' => 'decimal', 'label' => 'Sold in steps of (sqm)', 'group' => 'Price',
            'hint' => 'For example 9, so sizes go 9, 18, 27. A size in between is refused and the nearest two are offered instead.'],
        'max_sqm' => ['type' => 'decimal', 'label' => 'Largest size (sqm)', 'group' => 'Price',
            'hint' => 'Leave blank for no limit. Above this the assistant says the team will quote directly.'],
        'region_group' => ['type' => 'select', 'label' => 'Who may see it', 'default' => 'international', 'group' => 'Price',
            'options' => ['international' => 'International visitors', 'pakistan' => 'Pakistan visitors', 'both' => 'Everyone'],
            'hint' => 'Pakistan and undetermined visitors never see international pricing.'],

        'tax_included' => ['type' => 'select', 'label' => 'Tax treatment', 'default' => 'unspecified', 'group' => 'Tax',
            'options' => [
                'unspecified' => 'Not specified — the assistant will say the team must confirm',
                'no'          => 'Price excludes tax — show it as a separate line',
                'yes'         => 'Price already includes tax',
            ]],
        'tax_rate_percent' => ['type' => 'decimal', 'label' => 'Tax rate (%)', 'group' => 'Tax',
            'hint' => 'For example 15 for Sindh, 16 for Punjab. The system calculates the amount and the total.'],
        'tax_label' => ['type' => 'text', 'label' => 'Tax name', 'max' => 120, 'group' => 'Tax',
            'hint' => 'Shown on the card, for example Sindh Sales Tax (SST).'],
        'tax_note' => ['type' => 'text', 'label' => 'Note about tax', 'max' => 255, 'group' => 'Tax'],

        'valid_from' => ['type' => 'date', 'label' => 'Valid from', 'group' => 'Availability'],
        'valid_until' => ['type' => 'date', 'label' => 'Valid until', 'group' => 'Availability',
            'hint' => 'After this date the record stops being used, with no further action needed.'],
        'sort_order' => ['type' => 'int', 'label' => 'Order in the list', 'default' => 0, 'group' => 'Availability'],
        'source_reference' => ['type' => 'text', 'label' => 'Where this came from', 'max' => 255, 'group' => 'Availability'],
        'active' => ['type' => 'checkbox', 'label' => 'In use', 'default' => 1, 'group' => 'Availability'],
    ],
]);
