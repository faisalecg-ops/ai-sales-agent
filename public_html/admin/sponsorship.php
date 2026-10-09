<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require __DIR__ . '/_content.php';

content_page('sponsorship_package', [
    'title'    => 'Sponsorship',
    'singular' => 'tier',
    'file'     => 'sponsorship.php',
    'order'    => 'sort_order ASC, price DESC',
    'intro'    => 'Partnership tiers. Set how many of each remain, and the assistant stops offering a tier once it reaches zero.',
    'columns'  => [
        ['label' => 'Tier', 'value' => static fn($i) => e((string)$i['tier_name'])],
        ['label' => 'Price', 'value' => static fn($i) => e(PricingEngine::formatPrice((float)$i['price'], (string)$i['currency'])), 'num' => true],
        ['label' => 'Tax', 'value' => static fn($i) => $i['tax_rate_percent'] !== null
            ? e(rtrim(rtrim(number_format((float)$i['tax_rate_percent'], 2), '0'), '.') . '%')
            : '<span class="muted">not set</span>'],
        ['label' => 'Remaining', 'value' => static function ($i) {
            if ($i['quantity_available'] === null || $i['quantity_available'] === '') {
                return '<span class="muted">not tracked</span>';
            }
            $n = (int)$i['quantity_available'];
            return $n <= 0
                ? '<span class="pill pill-bad">Fully booked</span>'
                : '<span class="pill pill-ok">' . $n . ' left</span>';
        }, 'num' => true],
        ['label' => 'Offered', 'value' => static fn($i) => (int)$i['active'] === 1
            ? '<span class="pill pill-ok">Yes</span>'
            : '<span class="pill">Switched off</span>'],
    ],
    'validate' => static function (array $v, array $agent): array {
        $errors = [];
        $intl = strtoupper((string)($agent['international_currency'] ?? 'USD'));
        if (in_array((string)$v['region_group'], ['international', 'both'], true)
            && strtoupper((string)$v['currency']) !== $intl) {
            $errors[] = 'International pricing must be in ' . $intl . '.';
        }
        if ((float)($v['price'] ?? 0) <= 0) {
            $errors[] = 'Enter a price greater than zero.';
        }
        if ((string)$v['tax_included'] === 'no'
            && ($v['tax_rate_percent'] === null || (float)$v['tax_rate_percent'] <= 0)) {
            $errors[] = 'Tax is marked as excluded, so enter the tax rate, otherwise no total can be shown.';
        }
        return $errors;
    },
    'fields' => [
        'tier_name' => ['type' => 'text', 'label' => 'Tier name', 'required' => true, 'max' => 160, 'group' => 'What it is',
            'hint' => 'For example Title Sponsor, Platinum Partner.'],
        'edition_label' => ['type' => 'text', 'label' => 'Which edition', 'max' => 160, 'group' => 'What it is'],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'rows' => 3, 'max' => 2000, 'group' => 'What it is'],
        'benefits' => ['type' => 'textarea', 'label' => 'What the partner gets', 'rows' => 12, 'max' => 8000, 'group' => 'What it is',
            'hint' => 'One benefit per line. If exhibition space is included, say so here so nobody reads it as a separate charge.'],

        'currency' => ['type' => 'text', 'label' => 'Currency', 'max' => 3, 'default' => 'USD', 'required' => true, 'group' => 'Price'],
        'price' => ['type' => 'decimal', 'label' => 'Price', 'required' => true, 'group' => 'Price'],
        'region_group' => ['type' => 'select', 'label' => 'Who may see it', 'default' => 'international', 'group' => 'Price',
            'options' => ['international' => 'International visitors', 'pakistan' => 'Pakistan visitors', 'both' => 'Everyone']],

        'tax_included' => ['type' => 'select', 'label' => 'Tax treatment', 'default' => 'unspecified', 'group' => 'Tax',
            'options' => [
                'unspecified' => 'Not specified — the assistant will say the team must confirm',
                'no'          => 'Price excludes tax — show it as a separate line',
                'yes'         => 'Price already includes tax',
            ]],
        'tax_rate_percent' => ['type' => 'decimal', 'label' => 'Tax rate (%)', 'group' => 'Tax'],
        'tax_label' => ['type' => 'text', 'label' => 'Tax name', 'max' => 120, 'group' => 'Tax'],
        'tax_note' => ['type' => 'text', 'label' => 'Note about tax', 'max' => 255, 'group' => 'Tax'],

        'quantity_available' => ['type' => 'int', 'label' => 'How many remain', 'group' => 'Availability',
            'hint' => 'Leave blank not to track it, and the assistant says nothing about availability. Enter a number and it says how many are left. Enter 0 and it stops offering this tier entirely.'],
        'valid_from' => ['type' => 'date', 'label' => 'Valid from', 'group' => 'Availability'],
        'valid_until' => ['type' => 'date', 'label' => 'Valid until', 'group' => 'Availability'],
        'sort_order' => ['type' => 'int', 'label' => 'Order in the list', 'default' => 0, 'group' => 'Availability'],
        'source_reference' => ['type' => 'text', 'label' => 'Where this came from', 'max' => 255, 'group' => 'Availability'],
        'active' => ['type' => 'checkbox', 'label' => 'Offer this tier', 'default' => 1, 'group' => 'Availability',
            'hint' => 'Switch off to withdraw the tier without deleting it.'],
    ],
]);
