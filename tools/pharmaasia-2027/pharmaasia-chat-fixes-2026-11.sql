-- Pharma Asia: content for the chat fix plan (owner, 11 November 2026).
-- Claude doc "AI Sales Agent chat — review and fix plan", step 4; build brief
-- docs/handover/2026-11-11-chat-review-and-fix-plan.md.
--
-- Import once, in phpMyAdmin, into the sales agent database, AFTER uploading the
-- new files. Safe to import twice. NOTHING here goes live by itself:
--   * the new market article arrives as a DRAFT;
--   * the corrected outdoor price and texts arrive "Awaiting review", and the
--     version that is live today keeps serving until you approve them.
-- Then: Admin -> Pharma Asia -> Review queue (or Stand pricing, Knowledge, FAQs) -> approve.
SET NAMES utf8mb4;
SET @a := (SELECT id FROM agents WHERE slug = 'pharmaasia' LIMIT 1);

-- ---------------------------------------------------------------------------
-- 1. Outdoor exhibit space is quoted from 80 sqm (owner, 11 Nov 2026).
--    Live record: the edit waits for approval and today's version stays live.
-- ---------------------------------------------------------------------------
UPDATE stall_packages
   SET approved_snapshot = JSON_OBJECT(
         'package_name', package_name, 'package_type', package_type, 'stall_size', stall_size, 'size_sqm', size_sqm,
         'currency', currency, 'price', price, 'price_basis', price_basis, 'pricing_mode', pricing_mode,
         'rate_per_sqm', rate_per_sqm, 'min_sqm', min_sqm, 'increment_sqm', increment_sqm, 'max_sqm', max_sqm,
         'edition_label', edition_label, 'region_group', region_group, 'description', description,
         'included_items', included_items, 'tax_included', tax_included, 'tax_rate_percent', tax_rate_percent,
         'tax_label', tax_label, 'tax_note', tax_note, 'valid_from', valid_from, 'valid_until', valid_until),
       status = 'pending_review',
       min_sqm = 80, size_sqm = 80, price = ROUND(rate_per_sqm * 80, 2),
       stall_size = 'Open area outside the halls, from 80 sqm',
       description = 'Open exhibit space outside the halls, charged per square metre, from 80 sqm.'
 WHERE agent_id = @a AND package_type = 'outdoor' AND status = 'approved' AND (min_sqm IS NULL OR min_sqm = 0);

-- Not yet approved: simply corrected.
UPDATE stall_packages
   SET min_sqm = 80, size_sqm = 80, price = ROUND(rate_per_sqm * 80, 2),
       stall_size = 'Open area outside the halls, from 80 sqm',
       description = 'Open exhibit space outside the halls, charged per square metre, from 80 sqm.'
 WHERE agent_id = @a AND package_type = 'outdoor' AND status IN ('draft', 'pending_review') AND (min_sqm IS NULL OR min_sqm = 0);

-- ---------------------------------------------------------------------------
-- 2. The same in the FAQs and the stand article (they said "no minimum").
--    Live ones wait for approval; the approved wording stays live meanwhile.
-- ---------------------------------------------------------------------------
UPDATE faq_entries
   SET approved_version_question = question, approved_version_answer = answer,
       answer = REPLACE(answer, 'The tariff gives no minimum for outdoor exhibit space.', 'Outdoor exhibit space starts at 80 square metres.'),
       status = 'pending_review'
 WHERE agent_id = @a AND status = 'approved' AND answer LIKE '%The tariff gives no minimum for outdoor exhibit space.%';
UPDATE faq_entries
   SET answer = REPLACE(answer, 'The tariff gives no minimum for outdoor exhibit space.', 'Outdoor exhibit space starts at 80 square metres.')
 WHERE agent_id = @a AND status IN ('draft', 'pending_review') AND answer LIKE '%The tariff gives no minimum for outdoor exhibit space.%';

UPDATE faq_entries
   SET approved_version_question = question, approved_version_answer = answer,
       answer = REPLACE(answer, 'Outdoor exhibit space is open area outside the halls, charged per square metre.',
                                'Outdoor exhibit space is open area outside the halls, charged per square metre, from 80 square metres.'),
       status = 'pending_review'
 WHERE agent_id = @a AND status = 'approved'
   AND answer LIKE '%Outdoor exhibit space is open area outside the halls, charged per square metre.%'
   AND answer NOT LIKE '%from 80 square metres%';
UPDATE faq_entries
   SET answer = REPLACE(answer, 'Outdoor exhibit space is open area outside the halls, charged per square metre.',
                                'Outdoor exhibit space is open area outside the halls, charged per square metre, from 80 square metres.')
 WHERE agent_id = @a AND status IN ('draft', 'pending_review')
   AND answer LIKE '%Outdoor exhibit space is open area outside the halls, charged per square metre.%'
   AND answer NOT LIKE '%from 80 square metres%';

UPDATE faq_entries
   SET approved_version_question = question, approved_version_answer = answer,
       answer = REPLACE(answer, 'Outdoor exhibit space outside the halls is offered, charged per square metre.',
                                'Outdoor exhibit space outside the halls is offered, charged per square metre, from 80 square metres.'),
       status = 'pending_review'
 WHERE agent_id = @a AND status = 'approved'
   AND answer LIKE '%Outdoor exhibit space outside the halls is offered, charged per square metre.%'
   AND answer NOT LIKE '%from 80 square metres%';
UPDATE faq_entries
   SET answer = REPLACE(answer, 'Outdoor exhibit space outside the halls is offered, charged per square metre.',
                                'Outdoor exhibit space outside the halls is offered, charged per square metre, from 80 square metres.')
 WHERE agent_id = @a AND status IN ('draft', 'pending_review')
   AND answer LIKE '%Outdoor exhibit space outside the halls is offered, charged per square metre.%'
   AND answer NOT LIKE '%from 80 square metres%';

-- The stand article: 80 sqm, and no "approved" wording for the assistant to repeat.
UPDATE knowledge_articles
   SET approved_version_title = title, approved_version_body = body,
       body = REPLACE(REPLACE(body,
                'The tariff gives no minimum size for it, and the team confirms what can be placed outdoors.',
                'It starts at 80 square metres, and the team confirms what can be placed outdoors.'),
                'They come only from the approved price records, and tax at 15% is added to every rate.',
                'They come only from the price list, and 15% VAT is added to every rate.'),
       status = 'pending_review'
 WHERE agent_id = @a AND status = 'approved'
   AND body LIKE '%The tariff gives no minimum size for it, and the team confirms what can be placed outdoors.%';
UPDATE knowledge_articles
   SET body = REPLACE(REPLACE(body,
                'The tariff gives no minimum size for it, and the team confirms what can be placed outdoors.',
                'It starts at 80 square metres, and the team confirms what can be placed outdoors.'),
                'They come only from the approved price records, and tax at 15% is added to every rate.',
                'They come only from the price list, and 15% VAT is added to every rate.')
 WHERE agent_id = @a AND status IN ('draft', 'pending_review')
   AND body LIKE '%The tariff gives no minimum size for it, and the team confirms what can be placed outdoors.%';

-- ---------------------------------------------------------------------------
-- 3. Knowledge article, category Market: the Pakistan market opportunity.
--    A DRAFT. Money is written as "US dollars" on purpose: the chat discards any
--    reply with "USD" or "$" that is not a stand or sponsorship price.
-- ---------------------------------------------------------------------------
INSERT INTO knowledge_articles (agent_id, title, category, body, keywords, stage_tags, active, status, source_reference)
SELECT @a, 'Pakistan''s pharmaceutical market: the opportunity for suppliers', 'market',
       'Pakistan has more than 650 pharmaceutical manufacturers serving a population of 250 million, and they make around 85 per cent of the medicines the country needs.\n\nThe market keeps growing in value: retail medicine sales reached 1.049 trillion rupees in the 12 months to March 2025, up 20.62 per cent.\n\nMost raw materials are bought abroad. The Federal Health Minister said in May 2026 that Pakistan imports 80 to 90 per cent of its pharmaceutical raw materials.\n\nFactories are being upgraded for export. Manufacturers are investing more than 500 million US dollars to bring their plants up to international standards. Exports reached 457 million US dollars in the year to June 2025, up 34 per cent, and Pakistani medicines go to 52 countries. The government is working towards WHO Maturity Level 3 for its medicines regulator by April 2027, which the Health Minister said would open more than 100 markets.\n\nWhat this means for suppliers:\n- Machinery, spare parts, pumps, valves, sensors and automation: plants upgrading to international standards need new equipment, parts and service.\n- Cleanroom, HVAC and water treatment: needed to meet those standards.\n- Laboratory, analytical and quality control equipment: testing for registration and for export markets.\n- APIs, excipients and intermediates: 80 to 90 per cent are imported.\n- Packaging machinery and materials: exporters need packaging that export markets accept.\n- Software, serialisation and traceability: Pharma 4.0, AI and traceability were topics at the 2025 International Pharma Convention.',
       'pakistan market opportunity growth exports manufacturers factories upgrade gmp machinery processing packaging spare parts pumps valves sensors instrumentation automation apis excipients intermediates raw materials laboratory analytical quality control cleanroom hvac water treatment software consultants turnkey nutraceuticals',
       '', 1, 'draft',
       'Profit 24 Sep 2025 and 23 Jul 2025; Geo News (IQVIA); Arab News 19 May 2026; APP 25 Aug 2026; Pharma Asia Post-Show Report 2025'
  FROM DUAL
 WHERE @a IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM knowledge_articles WHERE agent_id = @a AND title = 'Pakistan''s pharmaceutical market: the opportunity for suppliers');
