# Sales agent upgrade — November 2026: Pharma Asia after the 24th edition

Approved by the owner on 10 November 2026 (Claude Doc "Pharma Asia 2026
Post-Event Update Plan").

## What it adds

- **Outdoor exhibit space** as a third kind of stand in Stand pricing, next to
  shell scheme and bare space. It is charged per square metre and may be saved
  without a smallest size, because Pharma Asia's tariff publishes none. With no
  size asked, it is shown per single square metre (the published rate itself).
- **GPT-6 models from OpenAI** (`gpt-6-luna`, `gpt-6.1-sol`) in the model list,
  and the request settings they need (owner's choice, 10 November 2026: GPT-6
  Luna for the website chat, GPT-6.1 Sol for prospect emails). GPT-6 Luna
  answers without a reasoning delay; GPT-6.1 Sol reasons at its lowest setting.
  Checked against OpenAI's documentation on 10 November 2026, **not against the
  live service**: after saving the OpenAI key, press Test on the AI models page.
- **Pharma Asia content for the 2027 cycle** as a file to import
  (`pharmaasia-2027-sales-agent.sql`, built by `tools/pharmaasia-2027/build_sql.py`).

## Files changed

| File | Change |
|---|---|
| `app/domain/Migrations.php` | Upgrade step `2026_11_outdoor_package` |
| `app/schema.sql` | Same change for new installations |
| `app/domain/PricingEngine.php` | `kindLabel()`, `referenceSize()`; outdoor cards and facts |
| `app/domain/ConversationState.php` | "outdoor space" counts as naming an option |
| `app/domain/import/FindingExtractor.php`, `ImportPublisher.php`, `ProposalBuilder.php` | Documents may describe outdoor space |
| `app/domain/providers/LlmProviders.php` | GPT-6 models and their request settings |
| `public_html/admin/pricing.php` | "Outdoor space" in Kind of stand; smallest size optional for it |

## How to install

1. Back up the database (phpMyAdmin → Export).
2. Upload the files from `salesagent-upgrade-2026-11-pharma-asia.zip` over the
   existing ones (same folders).
3. Admin → **Database upgrade** → run the outstanding step.
4. phpMyAdmin → the sales agent database → Import `pharmaasia-2027-sales-agent.sql`.
5. Admin → Pharma Asia → review and approve: Stand pricing (3), Knowledge (16), FAQs (26).

## What the import does (exhibition "pharmaasia" only)

- Dates & venue: 7–9 December 2027; register and book-a-stand pages of the new website.
- Stand prices from https://pharmaasia.com.pk/participation-tariff/ (read 10 November
  2026): shell 375, bare space inside halls 325, outdoor 150 US dollars per sq m; 15% VAT;
  international visitors only. Visitors in Pakistan get no price and are passed to the
  local sales representative (owner, 10 November 2026) — this is how the platform already
  behaves when no Pakistan price list exists.
- Knowledge and FAQs written before the show are brought up to date from the Post-Event
  Fact Sheet (first authority), the Closing Day Press Release (second) and the Pre-Event
  Press Release (third). New: ceremonies and guests, Pharma Asia Pulse, booking for 2027.
- Extra instructions for the assistant (only if that box is empty): 2026 is over; no
  expected figures; no "record"; no percentage for 2027 bookings; no quotations.
- /social: six pre-show topic suggestions dismissed, ten post-show ones added.
- Everything stays a draft. Rows edited or approved since 10 November 2026 are not touched.

## Things to know

- The value of MoUs and business is written "350 million US dollars" in knowledge, never
  "USD 350 million": the platform discards any reply carrying a USD or $ amount that is
  not on a price record, and that guard was deliberately left as it is.
- The four sponsorship drafts for Pharma Asia are the Build Asia tiers labelled for the
  24th edition. They were left untouched; the 2027 sponsorship document is still needed.
- Pharma Asia has no local or international sales representative saved yet. Without them
  the assistant cannot give a name when it passes someone to the team.

## Tests

`php tests/acceptance.php` 1,235 pass (28 new); `php tests/access-http.php` 82 pass.
The upgrade step and the import were run on a copy of the live database of 10 November
2026, twice, with the same result both times.
