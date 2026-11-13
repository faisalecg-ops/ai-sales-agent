# Sales agent status — 11 November 2026 (Pharma Asia)

Read this before the next piece of Pharma Asia work. Plan and running notes: Claude Doc
"Pharma Asia 2026 Post-Event Update Plan" (sections "Delivered on 10 November 2026" and
"Agent testing notes").

## Where things stand

- Branch `pharma-asia-2027` (not merged to `main`). Two deliveries:
  1. 10 Nov — `salesagent-upgrade-2026-11-pharma-asia.zip` + `pharmaasia-2027-sales-agent.sql`
     + `pharmaasia-pulse-post-event.sql` (see `docs/upgrade-2026-11-pharma-asia.md`).
     The owner installed it and approved the Pharma Asia content (stands, sponsorship tiers,
     knowledge, FAQs are live).
  2. 11 Nov — `salesagent-update-2026-11-11-packages.zip` (PricingEngine, ChatOrchestrator,
     PromptBuilder) + `pharmaasia-sponsorship-2027.sql`. Installed by the owner; his test
     answer to "brief me the participation packages and sponsorship options" named all three
     stand options and all four tiers.
- Owner's model choice: GPT-6 Luna for the website chat, GPT-6.1 Sol for prospect emails.
- Sponsorship prices (Title 75,000 / Platinum 40,000 / Gold 20,000 / Silver 10,000 USD + 15%
  VAT) are the Build Asia chart carried over; the owner has not confirmed them for 2027.
- No local or international sales representative is saved for Pharma Asia yet.

## Cron jobs (added by the owner, 11 Nov 2026, all tested)

| Account | Job | Schedule |
|---|---|---|
| `pharmaasiacom` | `/home/pharmaasiacom/public_html/pulse/cron.php` | `*/5 * * * *` |
| `pharmaasiacom` | `/home/pharmaasiacom/pharmaasia/app/cron.php` | `17 6 * * *` |
| `salesagent` | `/home/salesagent/public_html/social/cron.php` | `*/5 * * * *` (already there) |
| `salesagent` | `/home/salesagent/cron/whatsapp.php` | `*/5 * * * *` (new) |
| `salesagent` | `/home/salesagent/cron/tasks.php` | `0 * * * *` (new) |

PHP is `/usr/local/bin/php`. The sales agent code lives in `/home/salesagent/` (not a
`salesagent/` subfolder). The first hourly run collected 200 Build Asia registrations: the
hourly job had not been running before. The owner was asked to check WhatsApp sending for
Build Asia before those 200 are contacted.

## Chat fix plan — built 11 Nov 2026, owner to install

The owner approved the fix plan (steps 1 to 6) on 11 Nov 2026. The AI Sales Agent chat built it the same day:

- update package: `salesagent-update-2026-11-chat-fixes.zip`;
- install note: `docs/upgrade-2026-11-chat-fixes.md`;
- details: the "Done" section of `docs/handover/2026-11-11-chat-review-and-fix-plan.md`.

Findings 2 to 10 below are fixed by it. Finding 3's admin badge now shows "Expired".

Still to do:
- the owner installs the package, approves the Pharma Asia content, and re-tests with `docs/chat-test-script.md`;
- the 15 test chats were run with the real GPT-6 Luna and Tavily on 12 Nov 2026: 31 of 31 replies pass (`docs/reference/chat-tests-2026-11-12-gpt-6-luna.txt`).
- Check that the live OpenAI account has a payment method: a new account allows only 50 requests a day per model.

Representatives: the owner's screenshot of 11 Nov shows both entered:
- Local: Farhan Mobeen.
- International: Rabia Basri, WeChat rabiaecg.

## Testing findings (collected 11 Nov 2026; fixed by the chat fix plan)

The owner and Claude are testing the live Pharma Asia chat together. Findings are logged;
nothing below is to be changed until testing is finished and the owner decides.

| No. | Finding | Cause (in code) |
|---|---|---|
| 1 | *Fixed 11 Nov.* "Brief me the packages" got no details | Records reached the AI only on a price word |
| 2 | "For a company based in Germany, the approved stand rates are…" | AI repeats the visitor's country and the heading "APPROVED PRICING FACTS"; no wording rule against it (`PromptBuilder::pricing`) |
| 3 | *Fixed 11 Nov (data).* Sponsorship tiers expired though admin said Live | `valid_until` 2026-10-22; `withinValidity()`. Still open: the admin list badge ignores the end date |
| 4 | Outdoor quoted as "1 sqm, USD 150 …" | No minimum → `referenceSize()` = 1 sqm |
| 5 | Every figure for every stand in one long sentence | Asked for all rates; no wording rule |
| 6 | Asks for email at the end of almost every reply | `ConversationState::next`: PRICING → LEAD_CAPTURE, which stays until contact is captured; the stage guidance asks for contact every turn |
| 7 | Follow-ups naming a tier ("What do I get with Silver?", "Compare Gold and Platinum", "Which one do you suggest?") get no tier details unless prices were shown earlier | `detectCatalogueRequest()` matches words like package/sponsor/stand only, not tier or package names; descriptions are given per turn, not kept for the conversation. The AI sees the last 12 messages but not earlier turns' fact lists |
| 8 | Comparisons come out short | Pricing section says "summarise briefly rather than listing every inclusion" |
| 9 | No reasoned tier recommendation | No guidance on which tier suits which company (owner to write) |
| 10 | "I can share the approved sponsorship rates" | Same as 2 |

Other open items: five more Pulse articles; UAE Consul General home page tile (owner's photo);
2027 sponsorship document; 2027 E-Pass form; final 378-company exhibitor list; Social Media
Expert not yet connected to Pulse.

## 12 November 2026 — guided selling, Stage A built

- Plan approved by the owner (Claude doc "AI Sales Agent — Guided selling, quotations and email replies: plan").
- Stage A (guided path, qualification record and lead score, website answers) is built and waits for the owner
  to install: `docs/upgrade-2026-11-guided-selling-a.md`. The package also carries the chat fixes.
- Tests: 1,379 acceptance, 82 access-http. Real-AI chats 16 to 18 not yet run (no keys in the workspace).
- /social and the Backup Manager are not affected (one new table `site_pages`, new columns only).
- Next: Stage B (quotation PDF), after the owner has tried Stage A.
- **Blocked countries** added the same day at the owner's request (one list for all exhibitions, polite standard
  reply then stop, no IP check): see section 4 of `docs/upgrade-2026-11-guided-selling-a.md`. Acceptance tests 1,413.
- Real-AI chats 1 to 18 were run after the owner gave test keys; result in the same note.

## 12 November 2026 (evening) — Stage B built: the quotation PDF

- Owner: "if Stage A is complete start the Stage B". Built: `docs/upgrade-2026-11-guided-selling-b.md`.
- One combined package for everything since the last install: `salesagent-update-2026-11-guided-selling-b.zip`.
- Also added the same day at the owner's request: the optional Company website box on the chat card.
- Tests: 1,525 acceptance, 82 access-http; real-AI chats 1 to 22 run.
- Backup Manager: note left in its `docs/handover/2026-11-12-sales-agent-new-folders.md`.
- /social: not affected (it reads `conversations.utm_*` and `leads.exhibitor_intent` only).
- Open: the owner asked whether the chat can answer in Chinese or Urdu; answered (not reliably today),
  a small plan was offered, nothing built. Chinese names cannot be printed on the PDF yet.
- Next: Stage C (email replies in the same thread), after the owner has tried Stage B.

## 13 November 2026 — languages, personal chat links, quotation offer

- The chat now offers a quotation itself (owner: "it does not offer the quotation on any stage").
- Owner asked for: a language choice on the chat card (English default + 9, incl. Roman Urdu and Chinese);
  quotation PDFs in English only; the personal "click here to chat" link in prospect, quotation and WhatsApp
  messages, each on/off, valid until the exhibition ends. All built: `docs/upgrade-2026-11-languages-and-chat-links.md`.
- One package for everything since the last install: `salesagent-update-2026-11-languages-links.zip`.
- Tests 1,618 + 82; real-AI chats in Chinese, Roman Urdu and Arabic pass.
- Next: Stage C (email replies in the same thread), after the owner has tried all this.
- Later on 13 Nov: Italian, Japanese, Persian, Thai, Bahasa Indonesia and Bahasa Melayu added (16 languages).
  The owner has not installed any November zip: one final package `salesagent-update-2026-11-final.zip` with one
  guide `docs/install-2026-11-final.md`. Tests 1,636 + 82.
- 13 Nov evening: the conversation-quality plan (owner's document on main) — concerns answered with care and
  remembered, "don't contact me" puts their details on Do-not-contact. Same final zip. Tests 1,682 + 82.
  Real-AI chats 34–41 wait for a working test key. See `docs/upgrade-2026-11-conversation-quality.md`.
