-- Pharma Asia: exhibition sectors and sponsorship guidance (chat fix plan, step 4).
-- The wording is in the Claude doc "AI Sales Agent chat — review and fix plan",
-- section "Step 4 drafts for your approval". Import this ONLY once you are happy with
-- that wording: these two settings have no draft stage and are used straight away.
-- You can also simply paste them into the admin instead.
--
-- * Exhibition sectors are filled only if the box is empty
--   (Admin -> Agents and embed -> Pharma Asia -> What this exhibition covers).
-- * The sponsorship guidance is added to the end of "How to sell", once.
-- Safe to import twice.
SET NAMES utf8mb4;
SET @a := (SELECT id FROM agents WHERE slug = 'pharmaasia' LIMIT 1);

UPDATE agents
   SET exhibition_sectors = 'Pharmaceutical machinery\nProcessing equipment\nPackaging machinery\nPackaging materials\nSpare parts\nPumps, valves and sensors\nInstrumentation and control systems\nAutomation\nAPIs\nExcipients\nIntermediates\nPharmaceutical raw materials\nLaboratory equipment\nAnalytical instruments\nQuality control\nCleanroom\nHVAC\nWater treatment\nPharma software\nTurnkey plants\nPharma consultants\nLabel printing\nMaterial handling\nStorage and racking\nNutraceuticals\nToll manufacturing\nPharmaceuticals'
 WHERE id = @a AND (exhibition_sectors IS NULL OR TRIM(exhibition_sectors) = '');

UPDATE agents
   SET sales_rules = CONCAT(COALESCE(NULLIF(TRIM(sales_rules), ''), ''), IF(TRIM(COALESCE(sales_rules, '')) = '', '', '\n\n'),
       'Recommending a sponsorship tier\n- Title Partner: a market leader that wants to be the face of the event, including the speaking slot at the International Pharma Convention.\n- Platinum: a large international or national supplier that wants top branding across the halls and a large stand.\n- Gold: an established supplier building its name in Pakistan that wants a good-sized stand with branding.\n- Silver: a specialist or first-time supplier that wants more visibility than a stand alone, on a smaller budget.\n- A company only looking to meet buyers, with no interest in branding, is better served by a stand.\n- Always give one reason from what you know about the company (size, aim, first time in Pakistan or not), then ask one question.\n- Describe only benefits that are in the tier''s own record. Prices come only from the price records, never from this guidance.')
 WHERE id = @a AND COALESCE(sales_rules, '') NOT LIKE '%Recommending a sponsorship tier%';
