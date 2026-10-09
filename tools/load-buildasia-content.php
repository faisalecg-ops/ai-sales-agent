<?php
declare(strict_types=1);

/**
 * Build Asia content loader.
 *
 * Loads the knowledge, FAQs and pricing taken from the supplied brochures,
 * partnership chart and post-show report into an agent.
 *
 * Everything is loaded as a DRAFT. Nothing reaches a visitor until it has
 * been through the review workflow, which is the whole point of Section 24.
 * Run with --approve only when the content has actually been checked.
 *
 *   php tools/load-buildasia-content.php --agent=buildasia
 *   php tools/load-buildasia-content.php --agent=buildasia --approve
 *
 * Every figure below is transcribed from the source documents. Where a
 * document did not state something, nothing has been invented — the gap is
 * listed in docs/content-questions.md instead.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This loader runs from the command line only.\n");
}

$options = getopt('', ['agent::', 'approve', 'replace']);
$slug    = (string)($options['agent'] ?? 'buildasia');
$approve = array_key_exists('approve', $options);
$replace = array_key_exists('replace', $options);
$status  = $approve ? 'approved' : 'draft';

$agent = db()->one('SELECT * FROM agents WHERE slug = ? LIMIT 1', [$slug]);
if (!$agent) {
    exit("No agent with the short code '{$slug}'. Create it first in the admin panel.\n");
}
$agentId = (int)$agent['id'];
Audit::setActor('content-loader');

// =====================================================================
// Editions
// =====================================================================
const KARACHI_EDITION = '21st Build Asia, Karachi Expo Centre, 18-20 March 2027';
const KARACHI_TAX_RATE  = 15.00;
const KARACHI_TAX_LABEL = 'Sindh Sales Tax (SST)';

// =====================================================================
// Stall pricing (Section 17)
//
// Both options are charged per square metre, so PHP calculates the figure
// for whatever size a visitor asks about. The model never multiplies.
// =====================================================================
$stalls = [
    [
        'package_name'   => 'Shell Scheme Stand',
        'package_type'   => 'shell',
        'stall_size'     => 'Built in 3m x 3m modules',
        'size_sqm'       => 9,
        'pricing_mode'   => 'per_sqm',
        'rate_per_sqm'   => 400.00,
        'min_sqm'        => 9,
        'increment_sqm'  => 9,      // 9, 18, 27, 36 ...
        'max_sqm'        => null,   // no upper limit
        'price'          => 3600.00,     // the minimum bookable size, for reference
        'price_basis'    => 'per_sqm',
        'currency'       => 'USD',
        'region_group'   => 'international',
        'description'    => 'A ready-built stand with walls, fascia and basic furniture, so exhibitors arrive to a finished space. Sold in 3m x 3m modules from 9 sqm upwards.',
        'included_items' => implode("\n", [
            'Floor carpets within the stand area',
            'Two (02) chairs',
            'Three (03) spot lights, 100 watts each',
            'One (01) information counter',
            'Fascia board carrying your company or brand name, maximum 20 characters',
            'One (01) 5 ampere / 220 volt single phase power socket',
            'Printing of a 200 word profile of your organisation in the exhibition catalogue',
        ]),
        'tax_included'     => 'no',
        'tax_rate_percent' => KARACHI_TAX_RATE,
        'tax_label'        => KARACHI_TAX_LABEL,
        'tax_note'         => 'Participation charges are exclusive of all government taxes. Sindh Sales Tax at 15% applies to the tariff.',
        'edition_label'    => KARACHI_EDITION,
        'source_reference' => 'Build Asia Brochure 2027, participation page',
        'sort_order'       => 1,
    ],
    [
        'package_name'   => 'Bare Space',
        'package_type'   => 'bare',
        'stall_size'     => 'Built in 3m x 6m modules',
        'size_sqm'       => 18,
        'pricing_mode'   => 'per_sqm',
        'rate_per_sqm'   => 350.00,
        'min_sqm'        => 18,
        'increment_sqm'  => 9,      // 18, 27, 36, 45 ... confirmed by the organiser
        'max_sqm'        => null,   // no upper limit
        'price'          => 6300.00,
        'price_basis'    => 'per_sqm',
        'currency'       => 'USD',
        'region_group'   => 'international',
        'description'    => 'Floor space only, for exhibitors building their own custom stand. Bare space starts at 18 sqm and increases in 9 sqm steps.',
        'included_items' => implode("\n", [
            'Floor space only, with no stand structure supplied',
            'Starts at 18 sqm and increases in 9 sqm steps',
            'Printing of a 200 word profile of your organisation in the exhibition catalogue',
        ]),
        'tax_included'     => 'no',
        'tax_rate_percent' => KARACHI_TAX_RATE,
        'tax_label'        => KARACHI_TAX_LABEL,
        'tax_note'         => 'Participation charges are exclusive of all government taxes. Sindh Sales Tax at 15% applies to the tariff.',
        'edition_label'    => KARACHI_EDITION,
        'source_reference' => 'Build Asia Brochure 2027, participation page',
        'sort_order'       => 2,
    ],
];

// =====================================================================
// Sponsorship tiers (Section 17)
// Figures from the Partnership Benefits Chart for the 21st edition.
// =====================================================================
$sponsorships = [
    [
        'tier_name' => 'Title Sponsor',
        'price'     => 75000.00,
        'quantity_available' => 1,
        'benefits'  => implode("\n", [
            'Prime exhibition space included in the partnership fee: 10 booths, 90 sqm (15m x 6m)',
            'Two podium outside banners at Karachi Expo Centre',
            'Two main building panels at the Expo Centre',
            'Ten outer grill panels at the Expo Centre',
            'Four vinyl banners at the Expo Centre',
            'Four corridor panels at Karachi Expo Centre',
            'Four colour advertisements in the exhibition catalogue',
            '500 word partner profile in the exhibition catalogue',
            'Facilitation for press releases',
            'Speaking slot at the conference',
            'Title partner logo on stage backdrops',
            'Partner logo on the partner banner and main entrance backdrop',
            'Logo on 20,000 trade passes',
            'Acknowledgement in event promotional material and social media',
            'Banner and hyperlink on the event website',
            'Company profile and biography on the event website',
            'Electrical access, 15 amp',
            '10,000 Build Asia trade passes',
            '200 exhibition VIP passes',
        ]),
        'sort_order' => 1,
    ],
    [
        'tier_name' => 'Platinum Partner',
        'price'     => 40000.00,
        'benefits'  => implode("\n", [
            'Prime exhibition space included in the partnership fee: 8 booths, 72 sqm (12m x 6m)',
            'Four outer grill panels at the Expo Centre',
            'One vinyl banner at the Expo Centre',
            'Three corridor panels at Karachi Expo Centre',
            'One colour advertisement in the exhibition catalogue',
            '400 word partner profile in the exhibition catalogue',
            'Partner logo on the partner banner and main entrance backdrop',
            'Logo on 20,000 trade passes',
            'Acknowledgement on event social media pages',
            'Company profile and biography on the event website',
            'Electrical access, 15 amp',
            '3,000 Build Asia trade passes',
            '100 exhibition VIP passes',
        ]),
        'sort_order' => 2,
    ],
    [
        'tier_name' => 'Gold Partner',
        'price'     => 20000.00,
        'benefits'  => implode("\n", [
            'Prime exhibition space included in the partnership fee: 4 booths, 36 sqm (6m x 6m)',
            'Two outer grill panels at the Expo Centre',
            'Two corridor panels at Karachi Expo Centre',
            'One colour advertisement in the exhibition catalogue',
            '300 word partner profile in the exhibition catalogue',
            'Partner logo on the partner banner and main entrance backdrop',
            'Logo on 20,000 trade passes',
            'Acknowledgement on event social media pages',
            'Company profile and biography on the event website',
            'Electrical access, 15 amp',
            '2,000 Build Asia trade passes',
            '50 exhibition VIP passes',
        ]),
        'sort_order' => 3,
    ],
    [
        'tier_name' => 'Silver Partner',
        'price'     => 10000.00,
        'benefits'  => implode("\n", [
            'Prime exhibition space included in the partnership fee: 2 booths, 18 sqm (3m x 6m)',
            'One outer grill panel at the Expo Centre',
            'One corridor panel at Karachi Expo Centre',
            'One colour advertisement in the exhibition catalogue',
            '200 word partner profile in the exhibition catalogue',
            'Partner logo on the partner banner and main entrance backdrop',
            'Acknowledgement on event social media pages',
            'Company profile and biography on the event website',
            'Electrical access, 15 amp',
            '1,000 Build Asia trade passes',
            '25 exhibition VIP passes',
        ]),
        'sort_order' => 4,
    ],
];

// =====================================================================
// Knowledge articles (Section 23)
// Deliberately contain no prices: pricing lives only in the records above.
// =====================================================================
$articles = [
    [
        'title'    => 'Build Asia 2027 at a glance',
        'category' => 'event',
        'stage_tags' => 'intent_discovery,general_visitor,education',
        'keywords' => 'dates venue when where karachi expo centre 2027 edition 21st location',
        'body' => "The 21st Build Asia International Exhibition and Conference takes place from 18 to 20 March 2027 at the Karachi Expo Centre in Karachi, Pakistan.\n\n"
                . "Build Asia is Pakistan's premier construction industry event. It is owned and organised by Ecommerce Gateway Pakistan (Pvt) Ltd, a member of UFI, the Global Association of the Exhibition Industry.\n\n"
                . "The exhibition incorporates five co-located shows: Property Asia, CPEC Construction Expo, Coating and Paint Asia, Stonefair Asia, and Wood and Furniture Asia.\n\n"
                . "The official website is www.buildasia.net.",
        'source_reference' => 'Build Asia Brochure 2027',
    ],
    [
        'title'    => 'Why exhibit at Build Asia',
        'category' => 'benefits',
        'stage_tags' => 'education,business_qualification,sales_readiness',
        'keywords' => 'why exhibit benefits reasons value audience buyers brand launch market entry',
        'body' => "Build Asia is the largest platform in Pakistan for the construction, engineering and infrastructure sectors, bringing together industry leaders, innovators and stakeholders from across the globe.\n\n"
                . "The exhibition gives exhibitors an opportunity to showcase their brand, establish business connections and explore markets in Pakistan and the surrounding region.\n\n"
                . "It attracts decision-makers, government representatives and industry professionals looking for advances in construction technology, engineering and infrastructure development. It suits companies launching new products, entering a competitive market, or reinforcing an established leadership position.",
        'source_reference' => 'Build Asia Brochure 2027, Why Exhibit page',
    ],
    [
        'title'    => 'The business case for Pakistan',
        'category' => 'market',
        'stage_tags' => 'education,business_qualification',
        'keywords' => 'pakistan market opportunity economy construction growth cpec infrastructure investment',
        'body' => "Pakistan is one of the fastest-growing economies in South Asia and is experiencing a boom in construction, housing and infrastructure development. Growth is driven by rapid urbanisation, government incentives and major projects including the China-Pakistan Economic Corridor.\n\n"
                . "Key opportunity areas are:\n"
                . "Construction and real estate, with growing demand for housing, commercial space and smart city developments.\n"
                . "Infrastructure development, including new highways, rail networks, airports and seaports.\n"
                . "Energy and sustainability, with investment in renewable energy, energy-efficient buildings and green construction practices.\n\n"
                . "The country has a population of over 240 million and rising foreign investment in these sectors.",
        'source_reference' => 'Build Asia Brochure 2027, Business Potential page',
    ],
    [
        'title'    => 'Show size and attendance',
        'category' => 'event',
        'stage_tags' => 'education,objection_handling,sales_readiness,business_qualification',
        'keywords' => 'how many visitors attendance exhibitors countries space size footfall results statistics numbers',
        'body' => "These are the confirmed figures for Build Asia, taken from the post show report for the most recent Karachi edition, held from 22 to 24 January 2026 at the Karachi Expo Centre. Use these figures whenever anyone asks about the size of the show or how many people attend.\n\n"
                . "More than 32,000 trade visitors.\n"
                . "More than 350 international and local exhibitors.\n"
                . "Participation from over 21 countries.\n"
                . "Over 190,000 square feet of exhibition space, plus more than 50,000 square feet of outdoor area for heavy machinery.\n\n"
                . "The exhibition occupied roughly 18,000 square metres of indoor space and a further 5,000 square metres outdoors for heavy machinery.\n\n"
                . "The edition was inaugurated by senior figures from the Association of Builders and Developers, along with the Consuls General of Oman and Iran, the Deputy Trade Representative of the Russian Federation Trade Mission, and the Principal Secretary to the Governor of Sindh. The closing ceremony was attended by the Minister for Local Government, Sindh.\n\n"
                . "The event was supported by the Ministry of Industries and Production, the Trade Development Authority of Pakistan, and the Pakistan Engineering Council.",
        'source_reference' => 'Build Asia 2026 Post Show Report',
    ],
    [
        'title'    => 'Shell scheme and bare space explained',
        'category' => 'participation',
        'stage_tags' => 'education,sales_readiness,pricing',
        'keywords' => 'shell scheme bare space difference stand build what is included construction raw space',
        'body' => "There are two ways to take part in the exhibition.\n\n"
                . "Shell scheme is a ready-built stand. The structure, fascia board and basic furniture are supplied, so an exhibitor arrives to a finished space and only needs to bring products and graphics. It starts at 9 square metres and increases in 9 square metre steps.\n\n"
                . "Bare space is floor area only, with no structure supplied. Exhibitors design and build their own stand, which suits companies wanting a custom design or displaying large machinery. Bare space starts at 18 square metres and increases in 9 square metre steps.\n\n"
                . "Both options include the printing of a 200 word company profile in the official exhibition catalogue.",
        'source_reference' => 'Build Asia Brochure 2027, participation page',
    ],
    [
        'title'    => 'Who exhibits at Build Asia',
        'category' => 'profile',
        'stage_tags' => 'business_qualification,education',
        'keywords' => 'exhibitor profile sectors who exhibits categories suitable industries products',
        'body' => "Build Asia covers the construction, building materials and allied industries. Exhibitor categories include:\n\n"
                . "Cranes, earthmoving machinery, hoisting machinery and drilling and mining equipment.\n"
                . "Pre-fabricated building components, pre-engineered steel buildings and prefab housing systems.\n"
                . "Construction equipment, tools and hardware.\n"
                . "Escalators, elevators and lifts.\n"
                . "Construction chemicals and concrete technology.\n"
                . "Pumps, pipes and fittings, and wire and cables.\n"
                . "Ceramic, granite and marble products, tiles and marbles, and marble quarrying and finishing machinery.\n"
                . "Steel bars, M.S. bar and section manufacturers, and steel and re-rolling mills.\n"
                . "Wood and timber, both hardwood and softwood, and lamination machines.\n"
                . "Paints, varnishes and coatings.\n"
                . "Aluminium and PVC windows and doors, glazing, glass partitions and curtain walls.\n"
                . "False ceilings, wall covers, carpets and architectural finishes and facades.\n"
                . "Home and office furniture and furnishings, kitchen cabinets, lighting and ceiling fans.\n"
                . "Generators, solar industry, UPS systems and electrical cables.\n"
                . "Fire fighting and alarm systems, safety equipment, and security systems.\n"
                . "Builders and developers, contractors, consulting engineers, architects and town planners.\n"
                . "Banks, development finance institutions, housing loan financers and insurance companies.\n"
                . "Real estate agents, property dealers and project management consultants.\n"
                . "Water purification, waterproofing, insulation, sealants and adhesives, and solid waste management.",
        'source_reference' => 'Build Asia Brochure 2027, Exhibitors Profile page',
    ],
    [
        'title'    => 'Who visits Build Asia',
        'category' => 'profile',
        'stage_tags' => 'business_qualification,education,objection_handling',
        'keywords' => 'visitor profile who attends buyers audience decision makers architects contractors',
        'body' => "The visitor profile includes:\n\n"
                . "Architects, engineers and town planners.\n"
                . "Manufacturers.\n"
                . "Interior designers and engineers.\n"
                . "Builders, contractors and developers.\n"
                . "Distributors and investors.\n"
                . "Government authorities, agencies and decision-makers.\n"
                . "Associations and trade bodies.\n"
                . "Project management consultants.\n"
                . "Legal professionals.\n"
                . "Urban planners and surveyors.\n"
                . "Real estate agents.\n"
                . "Building materials dealers, suppliers and distributors.\n"
                . "Foreign companies seeking joint ventures.\n"
                . "Landscape architects, construction managers and interior designers.\n"
                . "Academia, and technology and innovation enthusiasts.",
        'source_reference' => 'Build Asia Brochure 2027, Visitors Profile page',
    ],
    [
        'title'    => 'Major product categories on display',
        'category' => 'profile',
        'stage_tags' => 'education,business_qualification',
        'keywords' => 'product categories what is displayed machinery materials sectors shown',
        'body' => "Major product categories at the exhibition are cranes, earthmoving machinery, pre-fabricated building components, construction equipment tools, hardware, escalators, elevators and lifts, construction chemicals and concrete technology, pumps, pipes and fittings, hoisting machinery, generators, ceramic, granite and marble products, steel bars, lamination machines, wood and timber in hardwood and softwood, wire and cables, paints and varnishes, aluminium and PVC windows and doors, false ceilings and wall covers, tiles and marbles, drilling and mining equipment, and home and office furniture and furnishings.",
        'source_reference' => 'Build Asia Brochure 2027, Major Product Categories page',
    ],
    [
        'title'    => 'The conference programme',
        'category' => 'conference',
        'stage_tags' => 'education,general_visitor',
        'keywords' => 'conference proptech convention sessions speakers panels seminars programme',
        'body' => "Build Asia runs a conference programme alongside the exhibition, including the PROPTECH Convention.\n\n"
                . "At the January 2026 edition, sessions covered affordable housing, responsible coastal development, the future of branded residences, what property buyers want compared with what the market offers, REITs and tokenisation, integrating digital payments with property technology platforms, design and structure, industrial growth in Karachi, women leaders across industries, non-banking financial businesses, and the role of social media in scaling real estate businesses.\n\n"
                . "Speakers came from developers, REITs, banks including the State Bank of Pakistan, architecture practices, and international real estate firms.\n\n"
                . "The programme for the 2027 edition is expected to be announced shortly.",
        'source_reference' => 'Build Asia 2026 Post Show Report, PROPTECH Convention section',
    ],
    [
        'title'    => 'Supporters and official backing',
        'category' => 'credibility',
        'stage_tags' => 'objection_handling,education',
        'keywords' => 'supporters government backing endorsement official associations credibility ufi',
        'body' => "Build Asia is owned and organised by Ecommerce Gateway Pakistan (Pvt) Ltd, a member of UFI, the Global Association of the Exhibition Industry.\n\n"
                . "The strategic supporter is Rising Pakistan.\n\n"
                . "Supporters include the Ministry of Industries and Production, the Trade Development Authority of Pakistan, PASDEC, the All Pakistan Marble Industries Association, the All Karachi Marble Industries Association, the Pakistan Engineering Council, and the Karachi Chamber of Commerce and Industry. The Lahore edition is additionally supported by the Punjab Board of Investment and Trade.\n\n"
                . "The digital partner is Ecommerce (Pvt) Ltd and the buyer's database partner is Jamal's.",
        'source_reference' => 'Build Asia brochures and Post Show Report',
    ],
    [
        'title'    => 'The Lahore edition',
        'category' => 'event',
        'stage_tags' => 'general_visitor,education',
        'keywords' => 'lahore punjab edition 20th expo centre second city other edition',
        'body' => "Build Asia runs a Lahore edition in addition to the Karachi edition. The 20th edition was held at the Lahore Expo Centre from 6 to 8 August 2026.\n\n"
                . "The Lahore edition uses the same participation specifications as Karachi. The differences between the two editions are the dates, the venue, the post-show results, and the rate of provincial sales tax that applies, since Punjab and Sindh set their own rates.\n\n"
                . "Dates for the next Lahore edition are expected to be announced shortly. Enquiries about Lahore participation are passed to the team, who confirm the dates and the applicable Punjab sales tax.",
        'source_reference' => 'Build Asia Karachi and Lahore brochure',
    ],
    [
        'title'    => 'How to reach the Build Asia team',
        'category' => 'contact',
        'stage_tags' => 'lead_capture,handoff,general_visitor',
        'keywords' => 'contact secretariat address phone email who to call office',
        'body' => "The Build Asia Secretariat is at Ecommerce Gateway Pakistan (Pvt) Ltd, 8 Kokan Society, Dr Azhar Hussain Road, near Kokan Park, off Shaheed-e-Millat Road, Karachi 74000, Pakistan.\n\n"
                . "Telephone: (92-21) 111-222-444 and (92-21) 3870 9970.\n"
                . "Email: info@buildasia.net.\n"
                . "Website: www.buildasia.net.",
        'source_reference' => 'Build Asia Brochure 2027, Secretariat panel',
    ],
];

// =====================================================================
// FAQs (Section 23)
// =====================================================================
$faqs = [
    [
        'question' => 'When and where does Build Asia 2027 take place?',
        'answer'   => 'The 21st Build Asia International Exhibition and Conference runs from 18 to 20 March 2027 at the Karachi Expo Centre in Karachi, Pakistan.',
        'keywords' => 'when where dates venue 2027 karachi expo centre location',
        'category' => 'event',
    ],
    [
        'question' => 'Which edition is this?',
        'answer'   => 'The March 2027 Karachi show is the 21st edition. The 20th edition was held in Lahore in August 2026, and the 19th edition was held in Karachi in January 2026.',
        'keywords' => 'edition number 21st 20th 19th which how many years',
        'category' => 'event',
    ],
    [
        'question' => 'What is the difference between shell scheme and bare space?',
        'answer'   => 'Shell scheme is a ready-built stand with structure, fascia and basic furniture supplied, starting at 9 square metres. Bare space is floor area only, for exhibitors building their own stand, starting at 18 square metres. Both increase in 9 square metre steps.',
        'keywords' => 'shell scheme bare space difference raw space custom stand build',
        'category' => 'participation',
    ],
    [
        'question' => 'What is included in a shell scheme stand?',
        'answer'   => 'A shell scheme stand includes floor carpeting within the stand area, two chairs, three 100 watt spot lights, one information counter, a fascia board carrying your company or brand name up to 20 characters, and one 5 ampere 220 volt single phase power socket. It also includes a 200 word profile of your organisation in the exhibition catalogue.',
        'keywords' => 'shell scheme included inclusions what do i get furniture lights carpet fascia',
        'category' => 'participation',
    ],
    [
        'question' => 'What is the smallest stand I can book?',
        'answer'   => 'Shell scheme starts at 9 square metres and increases in 9 square metre steps, so 9, 18, 27, 36 and so on. Bare space starts at 18 square metres and also increases in 9 square metre steps, so 18, 27, 36, 45 and so on. There is no upper limit on the space you can take.',
        'keywords' => 'minimum smallest size sqm stand smallest booth space',
        'category' => 'participation',
    ],
    [
        'question' => 'Is tax included in the participation charge?',
        'answer'   => 'No. Participation charges are exclusive of all government taxes. For the Karachi edition, Sindh Sales Tax at 15 per cent applies to the tariff. It applies to overseas exhibitors as well as local ones, and it is shown as a separate line on every quotation, alongside the participation charge and the total.',
        'keywords' => 'tax sst sales tax included exclusive vat government tax percent',
        'category' => 'pricing',
    ],
    [
        'question' => 'Why does the tax rate differ between Karachi and Lahore?',
        'answer'   => 'Sales tax on services is set by each province. The Karachi edition falls under Sindh and the Lahore edition under Punjab, so the applicable rate differs between the two. Both apply to overseas exhibitors.',
        'keywords' => 'tax difference karachi lahore sindh punjab province rate why',
        'category' => 'pricing',
    ],
    [
        'question' => 'How do I book a stand?',
        'answer'   => 'Booking begins with an enquiry. Once the team understands the space and option you need, they prepare and send an official proposal and the participation contract. Nothing is reserved until that paperwork is completed with the team.',
        'keywords' => 'book booking reserve how to apply process contract next step sign up',
        'category' => 'participation',
    ],
    [
        'question' => 'Who organises Build Asia?',
        'answer'   => 'Build Asia is owned and organised by Ecommerce Gateway Pakistan (Pvt) Ltd, a member of UFI, the Global Association of the Exhibition Industry.',
        'keywords' => 'organiser owner who runs company ufi ecommerce gateway',
        'category' => 'credibility',
    ],
    [
        'question' => 'How many people visit Build Asia?',
        'answer'   => 'The most recent Karachi edition reported more than 32,000 trade visitors and over 350 international and local exhibitors, with participation from more than 21 countries. The show occupied over 190,000 square feet indoors plus more than 50,000 square feet of outdoor area for heavy machinery.',
        'keywords' => 'visitors attendance how many footfall numbers last year results previous',
        'category' => 'event',
    ],
    [
        'question' => 'Which other shows run alongside Build Asia?',
        'answer'   => 'Build Asia incorporates five co-located shows: Property Asia, CPEC Construction Expo, Coating and Paint Asia, Stonefair Asia, and Wood and Furniture Asia.',
        'keywords' => 'incorporating co-located other shows property asia cpec stonefair wood furniture coating paint',
        'category' => 'event',
    ],
    [
        'question' => 'Is there a conference as well as the exhibition?',
        'answer'   => 'Yes. Build Asia runs a conference programme alongside the exhibition, including the PROPTECH Convention, with panel sessions and speakers from across the construction and real estate sectors. The 2027 programme is expected to be announced shortly.',
        'keywords' => 'conference seminar proptech convention sessions speakers programme talks',
        'category' => 'conference',
    ],
    [
        'question' => 'Do you have a Lahore edition?',
        'answer'   => 'Yes. Build Asia runs a Lahore edition at the Lahore Expo Centre, most recently in August 2026. Participation specifications match Karachi, while the dates, venue and applicable provincial tax differ. Dates for the next Lahore edition are expected to be announced shortly.',
        'keywords' => 'lahore punjab other city second edition when',
        'category' => 'event',
    ],
    [
        'question' => 'Will my company be listed in the exhibition catalogue?',
        'answer'   => 'Yes. Both shell scheme and bare space participation include the printing of a 200 word profile of your organisation in the official exhibition catalogue.',
        'keywords' => 'catalogue listing profile directory 200 words printed publication',
        'category' => 'participation',
    ],
    [
        'question' => 'Does a sponsorship package include exhibition space?',
        'answer'   => 'Yes. Each partnership tier includes prime exhibition space as part of the fee, with the size set by the tier. There is no separate stand charge on top of the partnership fee.',
        'keywords' => 'sponsorship include stand space booth extra separate charge partner',
        'category' => 'sponsorship',
    ],
    [
        'question' => 'Can I choose where my stand is located in the hall?',
        'answer'   => 'Stand locations are allocated by the team rather than confirmed over chat. Once you have told us the space and option you need, the team will discuss available positions with you as part of the official proposal.',
        'keywords' => 'location position floor plan where hall stand number map allocation corner',
        'category' => 'participation',
    ],
];

// =====================================================================
// Load
// =====================================================================
if ($replace) {
    foreach (['stall_packages', 'sponsorship_packages', 'knowledge_articles', 'faq_entries'] as $t) {
        db()->exec("DELETE FROM {$t} WHERE agent_id = ? AND source_reference IS NOT NULL", [$agentId]);
    }
    echo "Removed previously loaded content.\n";
}

$counts = ['stalls' => 0, 'sponsorships' => 0, 'articles' => 0, 'faqs' => 0];

foreach ($stalls as $row) {
    $row['agent_id'] = $agentId;
    $row['status']   = $status;
    $row['active']   = 1;
    db()->insert('stall_packages', $row);
    Audit::log('pricing_changed', $agentId, 'stall_package', db()->lastId(), 'Loaded from supplied documents');
    $counts['stalls']++;
}

foreach ($sponsorships as $row) {
    $row['agent_id']         = $agentId;
    $row['status']           = $status;
    $row['active']           = 1;
    $row['currency']         = 'USD';
    $row['region_group']     = 'international';
    $row['tax_included']     = 'no';
    $row['tax_rate_percent'] = KARACHI_TAX_RATE;
    $row['tax_label']        = KARACHI_TAX_LABEL;
    $row['tax_note']         = 'Rates are exclusive of government taxes. Sindh Sales Tax at 15% applies to the tariff. Rates apply to a single event and are subject to change.';
    $row['edition_label']    = KARACHI_EDITION;
    $row['source_reference'] = 'Partnership Benefits Chart, 21st Build Asia';
    db()->insert('sponsorship_packages', $row);
    Audit::log('pricing_changed', $agentId, 'sponsorship_package', db()->lastId(), 'Loaded from supplied documents');
    $counts['sponsorships']++;
}

foreach ($articles as $row) {
    $row['agent_id'] = $agentId;
    $row['status']   = $status;
    $row['active']   = 1;
    db()->insert('knowledge_articles', $row);
    Audit::log('knowledge_changed', $agentId, 'knowledge_article', db()->lastId(), 'Loaded from supplied documents');
    $counts['articles']++;
}

foreach ($faqs as $row) {
    $row['agent_id']         = $agentId;
    $row['status']           = $status;
    $row['active']           = 1;
    $row['source_reference'] = 'Build Asia supplied documents';
    db()->insert('faq_entries', $row);
    Audit::log('knowledge_changed', $agentId, 'faq', db()->lastId(), 'Loaded from supplied documents');
    $counts['faqs']++;
}

Fallbacks::seed($agentId);

echo "Loaded for '{$slug}' as {$status}:\n";
echo "  {$counts['stalls']} stand pricing records\n";
echo "  {$counts['sponsorships']} sponsorship tiers\n";
echo "  {$counts['articles']} knowledge articles\n";
echo "  {$counts['faqs']} FAQ entries\n";

if (!$approve) {
    echo "\nEverything is in Draft. Send it for review in the admin panel, or\n"
       . "re-run with --approve once the content has been checked.\n";
}
