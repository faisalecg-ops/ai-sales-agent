-- Pharma Asia: sponsorship tiers carried into the 25th edition (owner, 11 November 2026).
-- The four tiers were approved in the admin but still ended on 22 October 2026, the last day
-- of the 24th edition, so the platform treated them as expired and the assistant could not
-- offer them. This removes the end date and labels them for December 2027. Prices and
-- benefits are NOT changed. Tax wording follows the participation tariff: 15% VAT.
-- Import once, in phpMyAdmin, into the sales agent database. Safe to import twice.
SET NAMES utf8mb4;
SET @a := (SELECT id FROM agents WHERE slug = 'pharmaasia' LIMIT 1);

UPDATE sponsorship_packages
   SET valid_until   = NULL,
       valid_from    = '2026-11-11',
       edition_label = '25th Pharma Asia, Karachi Expo Centre, 7-9 December 2027',
       tax_label     = 'VAT',
       tax_note      = 'Rates are exclusive of tax. 15% VAT is applicable on all rates. Rates apply to a single event and are subject to change.'
 WHERE agent_id = @a AND valid_until = '2026-10-22';
