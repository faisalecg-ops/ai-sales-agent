<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require __DIR__ . '/_content.php';

content_page('knowledge_article', [
    'title'    => 'Knowledge',
    'singular' => 'article',
    'file'     => 'knowledge.php',
    'order'    => 'category ASC, title ASC',
    'intro'    => 'What the assistant knows about your event. It answers only from what is here, and says it does not know when nothing matches.',
    'columns'  => [
        ['label' => 'Title',    'value' => static fn($i) => e((string)$i['title'])],
        ['label' => 'Category', 'value' => static fn($i) => e((string)$i['category'])],
        ['label' => 'Used at stage', 'value' => static fn($i) => e((string)$i['stage_tags'])],
    ],
    'fields' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'required' => true, 'max' => 200,
            'hint' => 'A short description of what this covers.'],
        'category' => ['type' => 'text', 'label' => 'Category', 'max' => 80, 'default' => 'general',
            'hint' => 'Your own grouping, for example event, benefits, participation.'],
        'body' => ['type' => 'textarea', 'label' => 'The information', 'required' => true, 'tall' => true, 'max' => 20000,
            'hint' => 'Write it as you would explain it to a customer. Do not put prices here — prices come from the pricing pages, so the assistant always quotes an approved figure.'],
        'keywords' => ['type' => 'text', 'label' => 'Search words', 'max' => 500,
            'hint' => 'Words a visitor might use. Helps the assistant find this article.'],
        'stage_tags' => ['type' => 'text', 'label' => 'Useful at which stage', 'max' => 200,
            'hint' => 'Optional. For example education, business_qualification. Leave blank to use it at any stage.'],
        'source_reference' => ['type' => 'text', 'label' => 'Where this came from', 'max' => 255,
            'hint' => 'Optional. The document or person this was taken from.'],
        'active' => ['type' => 'checkbox', 'label' => 'In use', 'default' => 1,
            'hint' => 'Switch off to stop the assistant using it, without deleting it.'],
    ],
]);
