#!/usr/bin/env python3
"""
Pharma Asia: from the finished 24th edition (October 2026) to selling the 25th
(7-9 December 2027). Writes pharmaasia-2027-sales-agent.sql for phpMyAdmin.

Approved by the owner on 10 November 2026 (Claude Doc "Pharma Asia 2026
Post-Event Update Plan"). Authority for 2026 facts, highest first:
  1. Post-Event Fact Sheet   2. Closing Day Press Release   3. Pre-Event Press Release
Rates: https://pharmaasia.com.pk/participation-tariff/ as read on 10 November 2026.

What the SQL does, for the exhibition with short code "pharmaasia" only:
  - Dates & venue card -> 7-9 December 2027.
  - The two draft stand prices get the published rates; Outdoor Exhibit Space is added.
  - The 13 draft knowledge articles and 19 draft FAQs written before the show are
    brought up to date, and new ones are added.
  - Extra instructions for the assistant, only if that box is still empty.
  - /social: pre-show topic suggestions are dismissed, post-show ones are added.
Everything stays a DRAFT. Nothing reaches a visitor until it is approved in the admin.
A row is only changed while it is still a draft with the title it had on 10 November 2026,
so anything edited or approved in the admin since then is left alone.

No money amount is written with "USD" or "$" in knowledge or FAQs: the platform
discards any reply that carries such a figure unless it comes from a price record.
The value of MoUs and business is therefore written "350 million US dollars".
"""
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pharmaasia-2027-sales-agent.sql')

EDITION = '25th Pharma Asia, Karachi Expo Centre, 7-9 December 2027'
TARIFF_SRC = 'pharmaasia.com.pk/participation-tariff/, read 10 November 2026'
FACT = 'Post-Event Fact Sheet 2026'
CLOSING = 'Closing Day Press Release, 22 October 2026'
PRE = 'Pre-Event Press Release, October 2026 (confirmed by the owner, 10 November 2026)'
MINISTER = 'Syed Mustafa Kamal, Federal Minister for National Health Services, Regulations and Coordination'


def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


sql = []
w = sql.append

w('-- Pharma Asia: selling the 25th edition (7-9 December 2027)')
w('-- Built by tools/pharmaasia-2027/build_sql.py. Import once, in phpMyAdmin, into the sales agent database,')
w('-- AFTER uploading the new files and running Admin > Database upgrade.')
w('SET NAMES utf8mb4;')
w("SET @a := (SELECT id FROM agents WHERE slug = 'pharmaasia' LIMIT 1);")
w('')
w('-- Outdoor space as a kind of stand (the same change Admin > Database upgrade makes; harmless if already done).')
w("ALTER TABLE stall_packages MODIFY COLUMN package_type ENUM('shell','bare','outdoor') NOT NULL DEFAULT 'shell';")
w('')

# ---------------------------------------------------------------- dates & venue
w('-- Dates & venue card (read by Pharma Asia Pulse and the Social Media Expert).')
w("UPDATE agents SET event_starts_on = '2027-12-07', event_ends_on = '2027-12-09',\n"
  "       event_tagline = " + q('25th Pharma Asia International Exhibition & Conferences, incorporating the International Pharma Convention') + ",\n"
  "       register_url = 'https://pharmaasia.com.pk/register-to-visit/',\n"
  "       book_stand_url = 'https://pharmaasia.com.pk/book-a-stand/'\n"
  " WHERE id = @a AND event_starts_on = '2026-10-20';")
w('')

# ---------------------------------------------------------------- extra instructions
RULES = """Pharma Asia: where things stand (10 November 2026)
- The 24th Pharma Asia (20 to 22 October 2026, Karachi Expo Centre) has finished. Never invite anyone to visit, register for or book the 2026 edition.
- You are now helping companies take part in the 25th Pharma Asia, 7 to 9 December 2027, Karachi Expo Centre.
- Results of the 2026 edition are stated in the past tense and only as written in the approved knowledge: 24,697 visitors, 378 participating companies (198 foreign, 180 local), 25 countries, 821 booths.
- Never give a figure that was only expected before the show, and never give a number for the Chinese delegation. You may say China led the international participation.
- Do not call 2026 a record or the biggest edition, and do not compare its figures with earlier editions.
- The value of MoUs and business is always written exactly as "350 million US dollars in MoUs signed and business conducted during the exhibition". Never write it with a currency code or a currency sign.
- Never give a percentage for 2027 bookings and never say how much space is left. You may say that many exhibitors have already rebooked for 2027; the team confirms availability.
- Never quote anything said by a minister or any other guest.
- Use one or two results in a reply where they help the visitor decide. Do not recite the whole list.
- A visitor in Pakistan is given the options, sizes and what is included, but no price. At the pricing stage, pass them to the local sales representative."""
w('-- Extra instructions for the assistant: only if that box is still empty.')
w("UPDATE agents SET system_prompt_extra = " + q(RULES) + "\n WHERE id = @a AND (system_prompt_extra IS NULL OR TRIM(system_prompt_extra) = '');")
w('')

# ---------------------------------------------------------------- stand prices
TAX_NOTE = 'Participation charges are exclusive of tax. 15% VAT is applicable on all rates.'
w('-- Stand prices: the published 2027 tariff. Still drafts; approve them in Admin > Stand pricing.')
for pid, old_name, name, rate, minimum, size, desc in [
    (6, 'Shell Scheme Stand', 'Shell Scheme Stall', 375.00, 9, 'Built in 3m x 3m modules, from 9 sqm',
     'A ready-built stall with walls, fascia and basic furniture, so exhibitors arrive to a finished space. From 9 sqm, in 9 sqm steps.'),
    (7, 'Bare Space', 'Bare Space Inside Halls', 325.00, 18, 'Inside the halls, from 18 sqm',
     'Floor space inside the halls only, for exhibitors building their own stand. From 18 sqm, in 9 sqm steps.'),
]:
    w("UPDATE stall_packages SET package_name = " + q(name) + ", stall_size = " + q(size) + ", description = " + q(desc) + ",\n"
      "       pricing_mode = 'per_sqm', price_basis = 'per_sqm', currency = 'USD', rate_per_sqm = %.2f, min_sqm = %d, size_sqm = %d, increment_sqm = 9, max_sqm = NULL,\n"
      "       price = %.2f, region_group = 'international', tax_included = 'no', tax_rate_percent = 15.00, tax_label = 'VAT',\n"
      "       tax_note = %s, edition_label = %s, source_reference = %s, valid_from = '2026-11-10', valid_until = NULL\n"
      " WHERE id = %d AND agent_id = @a AND status = 'draft' AND package_name = %s;"
      % (rate, minimum, minimum, rate * minimum, q(TAX_NOTE), q(EDITION), q(TARIFF_SRC), pid, q(old_name)))
w("INSERT INTO stall_packages (agent_id, package_name, package_type, stall_size, size_sqm, currency, price, price_basis, pricing_mode, rate_per_sqm,\n"
  "       min_sqm, increment_sqm, max_sqm, edition_label, region_group, description, included_items, tax_included, tax_rate_percent, tax_label, tax_note,\n"
  "       active, status, source_reference, valid_from, valid_until, sort_order)\n"
  "SELECT @a, 'Outdoor Exhibit Space', 'outdoor', 'Open area outside the halls', NULL, 'USD', 150.00, 'per_sqm', 'per_sqm', 150.00,\n"
  "       NULL, NULL, NULL, " + q(EDITION) + ", 'international', " + q('Open exhibit space outside the halls, charged per square metre. The tariff gives no minimum size.') + ", '',\n"
  "       'no', 15.00, 'VAT', " + q(TAX_NOTE) + ", 1, 'draft', " + q(TARIFF_SRC) + ", '2026-11-10', NULL, 3\n"
  "  FROM DUAL WHERE @a IS NOT NULL AND NOT EXISTS (SELECT 1 FROM stall_packages WHERE agent_id = @a AND package_type = 'outdoor');")
w('')

# ---------------------------------------------------------------- knowledge
RESULTS = (
    "These are the confirmed results of the most recent edition, the 24th Pharma Asia International Exhibition & Conferences, "
    "held from 20 to 22 October 2026 at the Karachi Expo Centre, open 10:00 am to 7:00 pm each day. "
    "Use these figures whenever anyone asks about the size of the show or how many people attend. State them in the past tense. "
    "Do not compare them with earlier editions and do not call the edition a record.\n\n"
    "Total booths: 821, made up of 123 shell scheme booths and 698 bare space booths.\n"
    "Participating companies: 378, of which 198 were foreign and 180 were local.\n"
    "Participating countries: 25. The major ones were China, Germany, the United States, Japan, Russia, Malaysia, Spain, Switzerland, Italy and Korea.\n"
    "Visitors: 24,697, of which 416 were foreign and 24,281 were local.\n\n"
    "Every hall of the Karachi Expo Centre was sold out: all six halls, and the foyer between Halls 5 and 6 was opened as Hall 7.\n\n"
    "MoUs signed and business conducted during the exhibition came to 350 million US dollars. Always write this amount in exactly these words, never with a currency code or sign.\n\n"
    "More than 70 print and electronic media representatives covered the show.\n\n"
    "Pharma Asia started in 2004."
)

updates = [
    (26, 'Pharma Asia 2026 at a glance', 'Pharma Asia 2027 at a glance', 'event',
     'dates venue when where karachi expo centre 2027 december edition 25th next location 2026 october finished over',
     "The next edition is the 25th Pharma Asia International Exhibition and Conferences, from 7 to 9 December 2027 at the Karachi Expo Centre in Karachi, Pakistan.\n\n"
     "The 24th edition was held from 20 to 22 October 2026 at the same venue and has finished. Nobody can visit, register for or book the 2026 edition any more.\n\n"
     "Pharma Asia is the biggest pharma sector event of Pakistan and has been held since 2004. It is owned and organised by Ecommerce Gateway Pakistan (Pvt) Ltd, a member of UFI, the Global Association of the Exhibition Industry, and it has been declared an official event of the Pakistan Pharmaceutical Manufacturers Association (PPMA).\n\n"
     "The exhibition incorporates the International Pharma Convention, a conference programme held alongside Pharma Asia every year since 2014.\n\n"
     "Daily timings for December 2027 will be confirmed closer to the show; the 2026 edition was open from 10:00 am to 7:00 pm each day.\n\n"
     "The official website is www.pharmaasia.com.pk.",
     'pharmaasia.com.pk and ' + FACT),
    (27, 'Why exhibit at Pharma Asia', 'Why exhibit at Pharma Asia', 'benefits',
     'why exhibit benefits reasons value audience buyers brand launch distribution agreements market entry results sold out',
     "Pharma Asia has been held since 2004 and gathers the pharmaceutical industry's decision makers, professionals, buyers, local distributors and trade visitors in one place.\n\n"
     "Exhibitors use the show to conclude sales and distribution agreements, generate new business, meet new customers and strengthen relationships with existing clients, demonstrate products and new launches, increase brand exposure, and network with prospective partners and potential buyers. During the three days, business deals are negotiated and signed on the show floor.\n\n"
     "At the 24th edition in October 2026, every hall of the Karachi Expo Centre was sold out. 378 companies took part, 198 of them from abroad, from 25 countries, and 24,697 visitors attended. MoUs signed and business conducted during the exhibition came to 350 million US dollars.\n\n"
     "Many exhibitors have already rebooked for the 25th edition in December 2027. The team confirms what space is available.\n\n"
     "The International Pharma Convention runs alongside the exhibition, so exhibitors also hear from experts and regulators on the issues affecting the industry.\n\n"
     "Exhibitor survey from the December 2025 edition: 93% of exhibitors said they achieved their objective for the show, more than 1,050 meetings were confirmed between corporate buyers and exhibitors, 80% of clients reported already securing business deals, and 90% of exhibitors confirmed they intend to take part again.",
     FACT + '; ' + CLOSING + '; Pharma Asia Brochure 2026; Post Show Report 2025'),
    (28, 'Show size and results of the last edition', 'Results of the 24th Pharma Asia, October 2026', 'event',
     'how many visitors attendance exhibitors companies countries booths stands halls size footfall results statistics numbers last year 2026 foreign local business mou sold out',
     RESULTS, FACT + '; ' + CLOSING),
    (29, 'Shell scheme and bare space explained', 'Shell scheme, bare space and outdoor space explained', 'participation',
     'shell scheme bare space outdoor difference stand stall build what is included construction raw space open area outside',
     "There are three ways to take part in the exhibition.\n\n"
     "Shell scheme is a ready-built stall. The structure, fascia board and basic furniture are supplied, so an exhibitor arrives to a finished space and only needs to bring products and graphics. It starts at 9 square metres and increases in 9 square metre steps.\n\n"
     "Bare space inside the halls is floor area only, with no structure supplied. Exhibitors design and build their own stand, which suits companies wanting a custom design or displaying large machinery. Bare space starts at 18 square metres and increases in 9 square metre steps.\n\n"
     "Outdoor exhibit space is open area outside the halls, charged per square metre. The tariff gives no minimum size for it, and the team confirms what can be placed outdoors.\n\n"
     "Prices are never worked out from this article. They come only from the approved price records, and tax at 15% is added to every rate.",
     TARIFF_SRC + '; Pharma Asia Brochure 2026, Tariff panel'),
    (31, 'Who visits Pharma Asia', 'Who visits Pharma Asia', 'profile',
     'visitor profile who attends buyers audience decision makers seniority manufacturers distributors pharmacists foreign visitors',
     "The visitor profile includes pharmaceutical manufacturers, pharma exporters, pharma distributors, pharmacists, commercial attachés, trade and corporate visitors, foreign delegates, researchers and students, academics from pharma and medical institutions, officials of regulatory agencies and government, industrialists, and importers and exporters.\n\n"
     "At the 24th edition in October 2026 there were 24,697 visitors, of which 416 were foreign and 24,281 were local.\n\n"
     "Visitors attend from many lines of work: agents, dealers and distributors, government, manufacturers, investment and finance, technology, laboratories, education, pharmacy, clinicians and healthcare professionals, marketing, engineering, purchasing and procurement, operations, production and logistics, and finance.\n\n"
     "Seniority is high. Among the top four visitor job titles at the December 2025 edition, 35% were managing directors, senior vice presidents or executive vice presidents, 25% were CEOs, COOs, presidents or chairmen, 23% were managers without staff and 18% were managers with staff.\n\n"
     "The organiser's brochure reports that 85% of visitors attended with the intent to purchase and 85% attended two or more days of the show.",
     FACT + '; Pharma Asia Brochure 2026; Post Show Report 2025'),
    (32, 'The International Pharma Convention', 'The International Pharma Convention', 'conference',
     'conference convention seminar sessions speakers panels programme talks videos 14th 2026 2027 drap pics compliance track trace logistics',
     "The International Pharma Convention has been held every year since 2014 alongside Pharma Asia. It is endorsed and declared an official event of the Pakistan Pharmaceutical Manufacturers Association (PPMA). It brings together the industry, regulators, academia, quality control, researchers, production, supply chain, compliance, executive management and consultants to discuss current issues, new technologies and future challenges.\n\n"
     "The 14th International Pharma Convention was held on 20 October 2026 at the Karachi Expo Centre. Its inaugural session was “Resilient Pharma Pakistan: Quality, Self-Reliance & Sustainable Growth”. Talks published from it include “Building a Future-Ready Pharma Industry: Technology, Self-Reliance & Sustainability” by Mr. Muhammad Hanif Ajari, International Consultant; “Shaping Future Healthcare Logistics” by Nicole Rump and Syeda Bushra; “Fuelling Sustainable Growth: Capital, Capacity and Credibility”; and an address by Mr. Syed Musharraf Ali. The videos are on the convention page of the website, https://pharmaasia.com.pk/convention/2026/.\n\n"
     "The 13th convention in December 2025 covered Pakistan Pharma Vision 2030, the pathway to PIC/S compliance and DRAP's transformation initiatives, 2D barcoding and track and trace, quality compliance for exports, logistics and supply chain, Annex 1 compliance for sterile production, and AI in pharma.\n\n"
     "The next convention will be held with the 25th Pharma Asia, 7 to 9 December 2027. Its programme will be announced by the Pharma Asia team. Companies that want to propose a talk or sponsor a session are passed to the team.",
     FACT + '; Pharma Asia YouTube channel, 14th Pharma Convention talks; Post Show Report 2025'),
    (34, 'Exhibitors at Pharma Asia 2026', 'Who exhibited at Pharma Asia 2026', 'exhibitors',
     'exhibitor list 2026 who exhibited companies halls china pakistan uae booth stand number find company foreign countries',
     "378 companies took part in the 24th Pharma Asia in October 2026: 198 foreign and 180 local, from 25 countries. The major countries were China, Germany, the United States, Japan, Russia, Malaysia, Spain, Switzerland, Italy and Korea. China led the international participation.\n\n"
     "The exhibitor directory on the Pharma Asia website lists 233 of these companies with their hall and booth numbers from the October 2026 show, across Halls 1 to 7.\n\n"
     "Chinese participants included API, excipient and pharmaceutical manufacturers, packaging material makers and machinery builders, among them Truking Technology, Tofflon Science & Technology Group, Zhejiang Jingxin Pharmaceutical, Apeloa Pharmaceutical and Reyoung Pharmaceutical.\n\n"
     "Pakistani exhibitors covered raw material importers and distributors, machinery and engineering firms, laboratory and scientific suppliers, packaging and printing companies, cleanroom and HVAC specialists, capsule makers, logistics companies (including Maersk Pakistan and DHL Global Forwarding Pakistan) and pharmaceutical manufacturers.\n\n"
     "The exhibitor list for December 2027 will be published closer to the show.",
     FACT + '; Pharma Asia Exhibitor List 2026'),
    (35, 'Visiting Pharma Asia: registration and timings', 'Visiting Pharma Asia: registration and timings', 'visiting',
     'register visit visitor pass e-pass free entry timings opening hours how to attend trade visitors 2027 2026 finished',
     "Pharma Asia is a trade exhibition for the pharmaceutical industry. The October 2026 edition has finished.\n\n"
     "The next edition is from 7 to 9 December 2027 at the Karachi Expo Centre, Karachi. Trade and corporate visitors register online through the Register to visit page of the website, https://pharmaasia.com.pk/register-to-visit/. The team confirms when registration for December 2027 opens and the daily timings.\n\n"
     "The International Pharma Convention runs alongside the exhibition. Questions about convention seats are passed to the Pharma Asia team.",
     'pharmaasia.com.pk'),
]
w('-- Knowledge articles written before the show, brought up to date. Still drafts.')
for kid, old_title, title, cat, kw, body, src in updates:
    w("UPDATE knowledge_articles SET title = %s, category = %s, keywords = %s, body = %s, source_reference = %s\n"
      " WHERE id = %d AND agent_id = @a AND status = 'draft' AND title = %s;"
      % (q(title), q(cat), q(kw), q(body), q(src), kid, q(old_title)))

# Small wording fixes in articles that otherwise stand.
w("UPDATE knowledge_articles SET body = REPLACE(body, 'Details of the 2026 convention programme are confirmed by the Pharma Asia team.', '')\n"
  " WHERE agent_id = @a AND status = 'draft';")

new_articles = [
    ('Ceremonies and guests of the 24th Pharma Asia', 'event', 'education,general_visitor,objection_handling',
     'inaugurated inauguration opened who chief guest closing ceremony minister health hirani ppma consul general uae guests dignitaries',
     "The 24th Pharma Asia was inaugurated on 20 October 2026 by Mr. Adnan Hirani, Chairman of the Pakistan Pharmaceutical Manufacturers' Association (PPMA).\n\n"
     "The chief guest at the closing ceremony on 22 October 2026 was " + MINISTER + ". After the first mention he may be called the Federal Health Minister.\n\n"
     "H.E. Dr. Bakheet Ateeq Al Remeithi, Consul General of the United Arab Emirates in Karachi, visited the exhibition.\n\n"
     "The exhibition was organised by Ecommerce Gateway Pakistan (Pvt) Ltd in collaboration with PPMA, with strategic support from the Special Investment Facilitation Council (SIFC).\n\n"
     "The inauguration and the closing ceremony had different chief guests; never mix them up. There are no approved quotations from any guest, so never quote what a guest said.",
     CLOSING + '; ' + PRE),
    ('Pharma Asia Pulse', 'credibility', 'education,general_visitor',
     'pulse news insight articles blog magazine publication industry news read',
     "Pharma Asia Pulse is Pharma Asia's news and insight publication for Pakistan's pharmaceutical industry. It was launched at the 24th Pharma Asia on 22 October 2026 by the Federal Health Minister.\n\n"
     "It covers industry news, regulation and quality, market and trade, investment and opportunities, machinery and technology, APIs and raw materials, and news from the exhibition.\n\n"
     "It is free to read at https://pharmaasia.com.pk/pulse/.",
     'pharmaasia.com.pk (statement of the organiser)'),
    ('Booking for December 2027: how it works', 'participation', 'sales_readiness,lead_capture,objection_handling',
     'book booking 2027 reserve availability space left early rebooked how to apply next step contract proposal',
     "Booking for the 25th Pharma Asia, 7 to 9 December 2027, is open. It begins with an enquiry through the Book a stand page, https://pharmaasia.com.pk/book-a-stand/, or in this chat.\n\n"
     "Once the team understands the space and option a company needs, they prepare and send an official proposal and the participation contract. Nothing is reserved until that paperwork is completed with the team.\n\n"
     "Every hall was sold out in October 2026 and many exhibitors have already rebooked for 2027. Never state how much space is left and never give a percentage: availability and stand positions are confirmed only by the team.",
     'pharmaasia.com.pk; decision of the owner, 10 November 2026'),
]
w('')
w('-- New knowledge articles. Drafts.')
for title, cat, tags, kw, body, src in new_articles:
    w("INSERT INTO knowledge_articles (agent_id, title, category, body, keywords, stage_tags, active, status, source_reference)\n"
      "SELECT @a, %s, %s, %s, %s, %s, 1, 'draft', %s FROM DUAL\n"
      " WHERE @a IS NOT NULL AND NOT EXISTS (SELECT 1 FROM knowledge_articles WHERE agent_id = @a AND title = %s);"
      % (q(title), q(cat), q(body), q(kw), q(tags), q(src), q(title)))
w('')

# ---------------------------------------------------------------- FAQs
faq_updates = [
    (37, 'When and where does Pharma Asia 2026 take place?', 'When and where is the next Pharma Asia?',
     'when where dates venue 2027 december karachi expo centre location next edition',
     'The next edition is the 25th Pharma Asia International Exhibition and Conferences, from 7 to 9 December 2027 at the Karachi Expo Centre in Karachi, Pakistan. The 24th edition, held from 20 to 22 October 2026, has finished.'),
    (38, 'Which edition is this?', 'Which edition is this?',
     'edition number 25th 24th which how many years since 2004',
     'The December 2027 Karachi show is the 25th edition of Pharma Asia, which has been held since 2004. The 24th edition was held at the Karachi Expo Centre from 20 to 22 October 2026.'),
    (39, 'What are the opening hours?', 'What are the opening hours?',
     'timings opening hours time open close what time',
     'The 2026 edition was open from 10:00 am to 7:00 pm on each of its three days. Timings for 7 to 9 December 2027 will be confirmed closer to the show.'),
    (40, 'How do I register to visit?', 'How do I register to visit?',
     'register registration visitor pass e-pass entry ticket badge visit 2027',
     'The October 2026 edition has finished. For the 25th edition, 7 to 9 December 2027, trade and corporate visitors register online through the Register to visit page at https://pharmaasia.com.pk/register-to-visit/. The team can confirm when registration for 2027 opens.'),
    (41, 'What is the difference between shell scheme and bare space?', 'What is the difference between shell scheme, bare space and outdoor space?',
     'shell scheme bare space outdoor difference raw space custom stand build open area',
     'Shell scheme is a ready-built stall with structure, fascia and basic furniture supplied, starting at 9 square metres. Bare space is floor area inside the halls only, for exhibitors building their own stand, starting at 18 square metres. Both increase in 9 square metre steps. Outdoor exhibit space is open area outside the halls, charged per square metre.'),
    (43, 'What is the smallest stand I can book?', 'What is the smallest stand I can book?',
     'minimum smallest size sqm stand smallest booth space outdoor',
     'Shell scheme starts at 9 square metres and increases in 9 square metre steps (9, 18, 27 and so on). Bare space inside the halls starts at 18 square metres and increases in 9 square metre steps (18, 27, 36 and so on). The tariff gives no minimum for outdoor exhibit space. There is no upper limit on the space you can take.'),
    (44, 'Is tax included in the participation charge?', 'Is tax included in the participation charge?',
     'tax vat sales tax included exclusive government tax percent',
     'No. Participation charges are exclusive of tax. 15 per cent VAT is applicable on all rates, and it is shown as a separate line on every quotation.'),
    (47, 'How many people visit Pharma Asia?', 'How many people visit Pharma Asia?',
     'visitors attendance how many footfall numbers last year results previous 2026 companies booths',
     'The 24th edition in October 2026 had 24,697 visitors, of which 416 were foreign and 24,281 were local. 378 companies took part, 198 foreign and 180 local, from 25 countries, on 821 booths.'),
    (48, 'Is there a conference as well as the exhibition?', 'Is there a conference as well as the exhibition?',
     'conference seminar convention sessions speakers programme talks videos',
     'Yes. The International Pharma Convention runs alongside the exhibition and has been held every year since 2014. The 14th convention was held on 20 October 2026, with the inaugural session “Resilient Pharma Pakistan: Quality, Self-Reliance & Sustainable Growth”. Videos of its talks are at https://pharmaasia.com.pk/convention/2026/. The programme for December 2027 will be announced by the team.'),
    (49, 'Which countries take part?', 'Which countries take part?',
     'countries international foreign participation china pavilion overseas exhibitors how many',
     'The 24th edition in October 2026 had participation from 25 countries. The major ones were China, Germany, the United States, Japan, Russia, Malaysia, Spain, Switzerland, Italy and Korea. 198 of the 378 participating companies were foreign, and China led the international participation.'),
    (50, 'Is a particular company exhibiting, and where is its stand?', 'Did a particular company exhibit in 2026, and who is exhibiting in 2027?',
     'is company exhibiting exhibited where stand booth hall number find exhibitor list 2026 2027',
     'The exhibitor directory on the Pharma Asia website lists companies from the October 2026 show with their hall and booth numbers. The exhibitor list for December 2027 will be published closer to the show; the team can confirm whether a company has booked.'),
    (51, 'Will my company be listed in the exhibition catalogue?', 'Will my company be listed in the exhibition catalogue?',
     'catalogue listing profile directory 200 words printed publication',
     'Yes. Shell scheme and bare space participation include a 200 word profile of your organisation in the official exhibition catalogue.'),
]
w('-- FAQs written before the show, brought up to date. Still drafts.')
for fid, old_q, new_q, kw, ans in faq_updates:
    w("UPDATE faq_entries SET question = %s, keywords = %s, answer = %s, source_reference = 'Pharma Asia post-event documents and tariff, 10 November 2026'\n"
      " WHERE id = %d AND agent_id = @a AND status = 'draft' AND question = %s;" % (q(new_q), q(kw), q(ans), fid, q(old_q)))

new_faqs = [
    ('Who inaugurated Pharma Asia 2026?', 'event', 'inaugurated inauguration opened who opening ceremony hirani ppma chairman',
     "The 24th Pharma Asia was inaugurated on 20 October 2026 by Mr. Adnan Hirani, Chairman of the Pakistan Pharmaceutical Manufacturers' Association (PPMA)."),
    ('Who was the chief guest at the closing ceremony of Pharma Asia 2026?', 'event', 'chief guest closing ceremony minister health who closed mustafa kamal',
     'The chief guest at the closing ceremony on 22 October 2026 was ' + MINISTER + '.'),
    ('How much business was done at Pharma Asia 2026?', 'event', 'business mou deals value signed how much million results',
     'MoUs signed and business conducted during the 24th Pharma Asia came to 350 million US dollars.'),
    ('Can I still visit or book for the October 2026 show?', 'event', '2026 october still register visit book finished over missed',
     'No. The 24th Pharma Asia, 20 to 22 October 2026, has finished. The next edition is from 7 to 9 December 2027 at the Karachi Expo Centre, and booking for it is open.'),
    ('Is space still available for December 2027?', 'participation', 'availability space left sold out 2027 remaining still available rebooked',
     'Booking for the 25th edition is open. Every hall was sold out in 2026 and many exhibitors have already rebooked, so early enquiry is sensible. The team confirms what space and positions are available.'),
    ('Is outdoor exhibit space available?', 'participation', 'outdoor outside open area exhibit space available',
     'Yes. Outdoor exhibit space outside the halls is offered, charged per square metre. The team confirms what can be placed outdoors and where.'),
    ('What is Pharma Asia Pulse?', 'credibility', 'pulse news insight articles blog publication read',
     "Pharma Asia Pulse is Pharma Asia's news and insight publication for Pakistan's pharmaceutical industry, free to read at https://pharmaasia.com.pk/pulse/."),
]
w('')
w('-- New FAQs. Drafts.')
for question, cat, kw, ans in new_faqs:
    w("INSERT INTO faq_entries (agent_id, question, answer, keywords, category, active, status, source_reference)\n"
      "SELECT @a, %s, %s, %s, %s, 1, 'draft', 'Pharma Asia post-event documents and tariff, 10 November 2026' FROM DUAL\n"
      " WHERE @a IS NOT NULL AND NOT EXISTS (SELECT 1 FROM faq_entries WHERE agent_id = @a AND question = %s);"
      % (q(question), q(ans), q(kw), q(cat), q(question)))
w('')

# ---------------------------------------------------------------- /social topics
w('-- AI Social Media Expert: pre-show topic suggestions that no longer apply are dismissed; post-show ones are added.')
w("UPDATE sm_topics SET status = 'dismissed' WHERE agent_id = @a AND status = 'suggested' AND id IN (15, 16, 17, 18, 20, 28);")
w("UPDATE sm_topics SET title = 'China at Pharma Asia 2026: APIs, packaging and machinery', angle = " +
  q('China led the international participation in October 2026. Use the exhibitor directory for company names; give no delegation number. [PA2027]') +
  " WHERE id = 19 AND agent_id = @a AND status = 'suggested';")
w("UPDATE sm_topics SET title = 'Shell scheme, bare space or outdoor: choosing how to exhibit at Pharma Asia 2027', angle = " +
  q('Explain the three participation options (no prices); link to the participation tariff page. [PA2027]') +
  " WHERE id = 27 AND agent_id = @a AND status = 'suggested';")
topics = [
    ('news', 'Thank you: the 24th Pharma Asia closes at Karachi Expo Centre',
     'Thanks to exhibitors, visitors and partners. Closing ceremony chief guest: the Federal Minister for National Health Services, Regulations and Coordination. No quotations.'),
    ('news', 'Pharma Asia 2026 in numbers: 821 booths, 378 companies, 25 countries, 24,697 visitors',
     'Fact sheet figures only, past tense. No comparison with earlier editions, no "record".'),
    ('news', '350 million US dollars in MoUs and business at Pharma Asia 2026',
     'Worded as MoUs signed and business conducted during the exhibition. Source: closing day press release.'),
    ('news', 'From inauguration to closing ceremony: three days at Pharma Asia 2026',
     'Inaugurated by Mr. Adnan Hirani, Chairman PPMA, on 20 October; closing ceremony on 22 October; visit of the UAE Consul General.'),
    ('spotlight', '198 foreign companies: the international side of Pharma Asia 2026',
     'More than half of the 378 participating companies were foreign. Major countries from the fact sheet.'),
    ('news', 'Every hall sold out: how Pharma Asia 2026 filled Karachi Expo Centre',
     'All six halls, and the foyer between Halls 5 and 6 opened as Hall 7. No percentage for 2027 bookings.'),
    ('insight', 'Talks from the 14th International Pharma Convention',
     'One post per talk, linking to pharmaasia.com.pk/convention/2026/: future-ready pharma industry; healthcare logistics; capital, capacity and credibility.'),
    ('news', 'Pharma Asia Pulse: news and insight for the industry, all year',
     'What Pulse covers and where to read it.'),
    ('spotlight', 'Hear it from the exhibitors: voices from the show floor',
     'The exhibitor videos on the website home page, one company per post.'),
    ('visitor', 'Save the date: 25th Pharma Asia, 7 to 9 December 2027',
     'Dates and venue; booking is open; many exhibitors have already rebooked. No percentage, no claim about remaining space.'),
]
for pillar, title, angle in topics:
    w("INSERT INTO sm_topics (agent_id, pillar, title, angle, origin, status)\n"
      "SELECT @a, %s, %s, %s, 'plan', 'suggested' FROM DUAL\n"
      " WHERE @a IS NOT NULL AND NOT EXISTS (SELECT 1 FROM sm_topics WHERE agent_id = @a AND title = %s);"
      % (q(pillar), q(title), q(angle + ' [PA2027]'), q(title)))
w('')

with open(OUT, 'w', encoding='utf-8') as f:
    f.write('\n'.join(sql) + '\n')
print('wrote', OUT, len(sql), 'statements/lines')
