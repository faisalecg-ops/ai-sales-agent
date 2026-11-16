<?php
declare(strict_types=1);

/**
 * "Pharma Asia (chat test)": an exhibition with Pharma Asia's 2027 offer, for
 * the test chats. Figures follow the Post-Event Fact Sheet 2026 and the 2027
 * tariff; the tiers are the four carried into 2027 (owner, 11 Nov 2026).
 * Returns the agents row. Used by run.php only.
 */

global $provider, $model;

$old = db()->value("SELECT id FROM agents WHERE slug = 'chattest-pharma'");
if ($old !== null) {
    db()->exec("UPDATE agents SET slug = CONCAT('chattest-old-', id), status = 'deleted' WHERE id = ?", [(int)$old]);
}

$guidance = "Recommending a sponsorship tier\n"
    . "- Title Partner: a market leader that wants to be the face of the event, including the speaking slot at the International Pharma Convention.\n"
    . "- Platinum: a large international or national supplier that wants top branding across the halls and a large stand.\n"
    . "- Gold: an established supplier building its name in Pakistan that wants a good-sized stand with branding.\n"
    . "- Silver: a specialist or first-time supplier that wants more visibility than a stand alone, on a smaller budget.\n"
    . "- A company only looking to meet buyers, with no interest in branding, is better served by a stand.\n"
    . "- Always give one reason from what you know about the company (size, aim, first time in Pakistan or not), then ask one question.\n"
    . "- Describe only benefits that are in the tier's own record. Prices come only from the price records, never from this guidance.";

$id = db()->insert('agents', [
    'slug' => 'chattest-pharma', 'name' => 'Pharma Asia', 'status' => 'active',
    'website_url' => 'https://pharmaasia.com.pk', 'allowed_domains' => 'pharmaasia.com.pk',
    'persona' => AgentDefaults::PERSONA, 'sales_objectives' => AgentDefaults::OBJECTIVES,
    'sales_rules' => AgentDefaults::SALES_RULES . "\n\n" . $guidance,
    'system_prompt_extra' => "The 24th Pharma Asia (20 to 22 October 2026, Karachi Expo Centre) has finished. You are now helping companies take part in the 25th Pharma Asia, 7 to 9 December 2027, Karachi Expo Centre.",
    'exhibition_sectors' => "Pharmaceutical machinery\nProcessing equipment\nPackaging machinery\nPackaging materials\nSpare parts\nPumps, valves and sensors\nInstrumentation and control systems\nAutomation\nAPIs\nExcipients\nIntermediates\nPharmaceutical raw materials\nLaboratory equipment\nAnalytical instruments\nQuality control\nCleanroom\nHVAC\nWater treatment\nPharma software\nTurnkey plants\nPharma consultants\nLabel printing\nMaterial handling\nStorage and racking\nNutraceuticals\nToll manufacturing\nPharmaceuticals",
    'llm_provider' => $provider, 'llm_model' => $model, 'llm_temperature' => 0.4, 'llm_max_tokens' => 900,
    'llm_timeout_seconds' => 45, 'notify_email' => 'chat-tests@pharmaasia.com.pk', 'readiness_min_signals' => 2,
    'chat_intro_form' => 1, 'book_stand_url' => 'https://pharmaasia.com.pk/book-a-stand/',
    'register_url' => 'https://pharmaasia.com.pk/register-to-visit/',
    'event_starts_on' => '2027-12-07', 'event_ends_on' => '2027-12-09', 'event_venue' => 'Karachi Expo Centre',
    'event_city' => 'Karachi', 'event_country' => 'Pakistan',
]);
Fallbacks::seed($id);

$vat = ['currency' => 'USD', 'region_group' => 'international', 'active' => 1, 'status' => 'approved',
        'tax_included' => 'no', 'tax_rate_percent' => 15, 'tax_label' => 'VAT',
        'tax_note' => 'Participation charges are exclusive of tax. 15% VAT is applicable on all rates.',
        'edition_label' => '25th Pharma Asia, Karachi Expo Centre, 7-9 December 2027'];
foreach ([
    ['Shell Scheme Stall', 'shell', 'Built in 3m x 3m modules, from 9 sqm', 375, 9, 9, "Walls and fascia board with company name\nCarpet\nOne table and two chairs\nLights and a power socket", 1],
    ['Bare Space Inside Halls', 'bare', 'Inside the halls, from 18 sqm', 325, 18, 9, "Floor space only, for exhibitors building their own stand", 2],
    ['Outdoor Exhibit Space', 'outdoor', 'Open area outside the halls, from 80 sqm', 150, 80, null, '', 3],
] as [$name, $type, $size, $rate, $min, $inc, $items, $order]) {
    db()->insert('stall_packages', $vat + [
        'agent_id' => $id, 'package_name' => $name, 'package_type' => $type, 'stall_size' => $size, 'size_sqm' => $min,
        'price' => $rate * $min, 'price_basis' => 'per_sqm', 'pricing_mode' => 'per_sqm', 'rate_per_sqm' => $rate,
        'min_sqm' => $min, 'increment_sqm' => $inc, 'included_items' => $items, 'sort_order' => $order,
    ]);
}
foreach ([
    ['Title Partner', 75000, "Prime exhibition space included in the partnership fee: 16 booths, 144 sqm\nSpeaking slot at the International Pharma Convention\nTitle branding on all event material\nLogo on 20,000 trade passes\n200 exhibition VIP passes", 1],
    ['Platinum Partner', 40000, "Prime exhibition space included in the partnership fee: 8 booths, 72 sqm\nFour outer grill panels at the Expo Centre\nOne vinyl banner at the Expo Centre\nLogo on the partner banner and main entrance backdrop\n100 exhibition VIP passes", 2],
    ['Gold Partner', 20000, "Prime exhibition space included in the partnership fee: 4 booths, 36 sqm\nTwo outer grill panels at the Expo Centre\nTwo corridor panels\nLogo on the partner banner\n50 exhibition VIP passes", 3],
    ['Silver Partner', 10000, "Exhibition space included in the partnership fee: 2 booths, 18 sqm\nOne corridor panel\nLogo on the partner banner\n25 exhibition VIP passes", 4],
] as [$tier, $price, $benefits, $order]) {
    db()->insert('sponsorship_packages', $vat + [
        'agent_id' => $id, 'tier_name' => $tier, 'price' => $price, 'benefits' => $benefits, 'sort_order' => $order,
    ]);
}

foreach ([
    ['local', 'Farhan Mobeen', 'Senior Manager Sales', 'farhan@ecgateway.info', '+923152228225', '+923152228225', null],
    ['international', 'Rabia Basri', 'Project Communication Officer', 'info@pharmaasia.com.pk', '+923000568730', '+923000568730', 'rabiaecg'],
] as [$region, $name, $title, $email, $mobile, $wa, $wechat]) {
    db()->insert('agent_sales_reps', ['agent_id' => $id, 'region' => $region, 'name' => $name, 'designation' => $title,
        'email' => $email, 'mobile' => $mobile, 'whatsapp' => $wa, 'wechat' => $wechat]);
}

$article = static function (string $title, string $category, string $body, string $keywords) use ($id): void {
    db()->insert('knowledge_articles', ['agent_id' => $id, 'title' => $title, 'category' => $category, 'body' => $body,
        'keywords' => $keywords, 'status' => 'approved', 'active' => 1]);
};
$article('Pharma Asia 2027 at a glance', 'event',
    "The next edition is the 25th Pharma Asia International Exhibition and Conferences, from 7 to 9 December 2027 at the Karachi Expo Centre in Karachi, Pakistan. The 24th edition took place from 20 to 22 October 2026.",
    'dates venue when where karachi expo centre 2027 december edition next location');
$article('Results of the 24th Pharma Asia, October 2026', 'event',
    "The 24th Pharma Asia had 24,697 visitors and 378 participating companies (198 foreign and 180 local) from 25 countries, in 821 booths. China led the international participation.",
    'how many visitors exhibitors companies countries booths results statistics last year 2026 took part');
$article('Why exhibit at Pharma Asia', 'benefits',
    "Pharma Asia has been held since 2004 and gathers the pharmaceutical industry's decision makers, buyers, local distributors and trade visitors in one place. Exhibitors meet manufacturers looking for machinery, raw materials, packaging and services, appoint distributors and launch products.",
    'why exhibit benefits reasons value audience buyers brand launch distribution');
$article('Who visits Pharma Asia', 'profile',
    "The visitor profile includes pharmaceutical manufacturers, exporters, distributors, pharmacists, commercial attachés, trade and corporate visitors.",
    'visitor profile who attends buyers audience decision makers manufacturers');
$article('The International Pharma Convention', 'conference',
    "The International Pharma Convention has been held every year since 2014 alongside Pharma Asia, with sessions on regulation, compliance, track and trace and logistics.",
    'conference convention seminar sessions speakers');
$article("Pakistan's pharmaceutical market: the opportunity for suppliers", 'market',
    "Pakistan has more than 650 pharmaceutical manufacturers serving a population of 250 million, and they make around 85 per cent of the medicines the country needs.\n\nThe market keeps growing in value: retail medicine sales reached 1.049 trillion rupees in the 12 months to March 2025, up 20.62 per cent.\n\nMost raw materials are bought abroad. The Federal Health Minister said in May 2026 that Pakistan imports 80 to 90 per cent of its pharmaceutical raw materials.\n\nFactories are being upgraded for export. Manufacturers are investing more than 500 million US dollars to bring their plants up to international standards. Exports reached 457 million US dollars in the year to June 2025, up 34 per cent, and Pakistani medicines go to 52 countries.\n\nWhat this means for suppliers:\n- Machinery, spare parts, pumps, valves, sensors and automation: plants upgrading to international standards need new equipment, parts and service.\n- Cleanroom, HVAC and water treatment: needed to meet those standards.\n- Laboratory, analytical and quality control equipment.\n- APIs, excipients and intermediates: 80 to 90 per cent are imported.\n- Packaging machinery and materials.",
    'pakistan market opportunity growth exports manufacturers factories upgrade machinery packaging spare parts pumps valves sensors automation apis excipients laboratory cleanroom hvac software');

db()->insert('faq_entries', ['agent_id' => $id, 'question' => 'Do you help with hotels and travel?',
    'answer' => 'The team can recommend hotels near the Karachi Expo Centre.',
    'keywords' => 'hotel travel visa accommodation', 'status' => 'approved', 'active' => 1]);

// Website pages, as the weekly reading would store them (Stage A, 12 Nov 2026).
if (SitePages::ready()) {
    db()->update('agents', ['site_pages_enabled' => 1], 'id = ?', [$id]);
    foreach ([
        ['https://pharmaasia.com.pk/register-to-visit/', 'Register to visit', "Trade visitors register free of charge online. Fill in the form with your name, company and mobile number; your visitor badge is sent by email and is scanned at the entrance."],
        ['https://pharmaasia.com.pk/book-a-stand/', 'Book a stand', "Companies book a stand by sending the booking form on this page. The team replies with the floor plan and the stands still free."],
        ['https://pharmaasia.com.pk/floor-plan/', 'Floor plan', "The floor plan of the halls at the Karachi Expo Centre, with the stands already booked. It can be downloaded as a PDF from this page."],
        ['https://pharmaasia.com.pk/exhibitor-list/', 'Exhibitor list', "The list of companies exhibiting, by country and by product group."],
    ] as [$u, $t, $b]) {
        db()->insert('site_pages', ['agent_id' => $id, 'url' => $u, 'url_hash' => sha1($u), 'title' => $t,
            'body' => $b, 'content_hash' => sha1($b), 'active' => 1, 'fetched_at' => date('Y-m-d H:i:s')]);
    }
}

// Quotations (Stage B, 12 Nov 2026): switched on, with a mail account. The
// runner replaces the mail transport, so nothing is really sent.
if (Quotation::ready()) {
    db()->update('agents', ['quote_enabled' => 1, 'quote_prefix' => 'PA27', 'mail_from_email' => 'info@pharmaasia.example',
        'mail_from_name' => 'Pharma Asia Secretariat', 'mail_smtp_host' => 'mail.pharmaasia.example'], 'id = ?', [$id]);
}

return db()->one('SELECT * FROM agents WHERE id = ?', [$id]) ?? [];
