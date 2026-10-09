<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require __DIR__ . '/_content.php';

content_page('faq', [
    'title'    => 'FAQs',
    'singular' => 'FAQ',
    'file'     => 'faqs.php',
    'order'    => 'category ASC, question ASC',
    'intro'    => 'Direct answers to common questions. When a visitor asks something close to one of these, the assistant uses your wording.',
    'columns'  => [
        ['label' => 'Question', 'value' => static fn($i) => e(mb_substr((string)$i['question'], 0, 90))],
        ['label' => 'Category', 'value' => static fn($i) => e((string)$i['category'])],
    ],
    'fields' => [
        'question' => ['type' => 'text', 'label' => 'Question', 'required' => true, 'max' => 400,
            'hint' => 'Phrase it the way a visitor would ask.'],
        'answer' => ['type' => 'textarea', 'label' => 'Answer', 'required' => true, 'rows' => 7, 'max' => 6000,
            'hint' => 'Keep it short and factual. No prices — those come from the pricing pages.'],
        'keywords' => ['type' => 'text', 'label' => 'Search words', 'max' => 500,
            'hint' => 'Other ways someone might ask the same thing.'],
        'category' => ['type' => 'text', 'label' => 'Category', 'max' => 80, 'default' => 'general'],
        'active' => ['type' => 'checkbox', 'label' => 'In use', 'default' => 1],
    ],
]);
