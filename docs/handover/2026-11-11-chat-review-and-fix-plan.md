# Handover to the AI Sales Agent chat — make the website chat talk like a person (11 November 2026)

**AI Sales Agent chat: read this whole note before you start, then the Claude doc
"AI Sales Agent chat — review and fix plan"
(https://claude.ai/code/artifact/723bf221-9947-403a-ac4b-7c4749d64e74).** That doc is the
plan the owner approved. Its Part 1 lists every agreed function of the chat, and its Part 2
says which ones fail today. This note is the build brief for you, with code pointers and tests.

- **Approved by:** the owner (Kaleem), 11 November 2026: "Approve the plan (steps 1 to 6).
  Who builds it: the AI Sales Agent chat."
- **Written by:** the /social chat. It reviewed the live code (branch `pharma-asia-2027`,
  commit `1aaae97`) against the owner's test chat ("Creasida from Bartels, Germany").
- **This closes the owner's "collect, do not fix yet" pause** for testing findings 1–10 in
  `docs/sales-agent-status-2026-11-11.md`. All ten are covered below.

## The owner's decisions (11 Nov 2026)

| Question | Decision |
|---|---|
| Plan | Steps 1 to 6 approved |
| Who builds | The AI Sales Agent chat |
| Outdoor exhibit space | Quote from **80 sqm** (`min_sqm` = 80 on the outdoor record; `referenceSize()` then gives 80) |
| 2027 sponsorship prices | Keep Title 75,000 / Platinum 40,000 / Gold 20,000 / Silver 10,000 USD + 15% VAT |
| Representatives | Local: Farhan Mobeen, Senior Manager Sales, farhan@ecgateway.info, +923152228225 (mobile and WhatsApp). International: Rabia Basri, Project Communication Officer, info@pharmaasia.com.pk, +923000568730 (mobile and WhatsApp), WeChat rabiaecg. The owner entered these in the admin; check they are saved. |
| Research wait | 10 to 20 seconds before the first exhibitor reply, **with nothing said**: only the typing dots |
| "What are the rates?" with no option named | **Ask which option first** |
| Sources | The 2026 and 2027 documents describe the same scope. The Post-Event Fact Sheet 2026 wins wherever they disagree. |

The owner also confirmed the reply style in the doc's Step 1 example (a short list and one
suggestion, then one question).

## What to build

### Step 1: talk like a sales person (findings 2, 4, 5, 6, 10)

1. `PromptBuilder::pricing()`: rename the heading "APPROVED PRICING FACTS" to something
   neutral (for example "PRICES YOU MAY QUOTE"). Add wording rules to `systemRules()`. Never say
   "approved", "pricing facts", "stage", "system" or "records". Never mention the visitor's
   country when giving a price, and never imply prices differ by country. Remove "approved"
   from the stage guidance and the catalogue section wording too.
2. Price layout: one line per option (name, size, price, VAT, total). Quote only the option
   asked about. If they ask for "the rates" without naming one, ask which option first (owner).
   Replace "summarise briefly rather than listing every inclusion" with: list the inclusions
   when they ask what is included.
3. Outdoor: `min_sqm` 80 on Pharma Asia's outdoor record (data change, an SQL line, then
   approve). Check that `referenceSize()` uses it.
4. Contact details (finding 6): `ConversationState::next` keeps PRICING → LEAD_CAPTURE until
   contact is captured, and LEAD_CAPTURE guidance asks every turn. Change this:
   - ask when it helps them (a proposal, a call, a price only the team can give);
   - after asking, do not ask again for at least 4 visitor messages;
   - never ask in two replies in a row;
   - if they decline or ignore it twice, stop asking and point to the Book a stand page.

   Count the asks in `lead_state` (for example `contact_asked_at_msg`, `contact_declined`). Make
   `turnGuidance()` say "Do not ask for contact details in this reply" when the rule says so.

### Step 2: remember the whole conversation (findings 7, 8)

1. When stands or sponsorship have come up once in a conversation, give `catalogueForPrompt()`
   on **every later turn**, not only when `detectCatalogueRequest()` matches. Keep a flag in
   `lead_state`. For visitors who may see prices, keep the prices already shown available too.
2. `detectCatalogueRequest()` / `detectPricingRequest()`: recognise tier and option names
   (title, platinum, gold, silver, shell, bare, outdoor) and follow-ups ("which one", "compare",
   "what do I get", "what's included", "suggest", "recommend", "difference").
3. Add a running summary to the prompt every turn. Cover who they are, what they make (from
   chat and research), what they asked, and what was shown and quoted. `ConversationStore::buildSummary`
   already exists, so extend it and put it in the prompt. Nothing should be lost after the
   12-message history window.

### Step 3: research first, then a pitch built on it (owner's rule 1B; A3, A4 in the doc)

1. `ChatIntro::exhibiting()` (its comment already says research "will run here, before the
   reply, once it exists"): run `CompanyResearch` **before** the first reply. Wait at most 20
   seconds and say nothing; the widget shows its typing dots. If it is not done by then, reply
   normally and use the research from the next turn. Mind the PHP time limit and the widget's
   request timeout; test both.
2. `CompanyResearch::queries()`: add what the visitor said (products, website, country) to the
   first search, so a short name like "Bartels" finds the right company
   (bartels-mikrotechnik.de: micropumps, sensors, valves, microfluidics). Re-run research once
   if the visitor later gives a website or clearly different products.
3. `CompanyResearch::promptBlock()`: also tell the AI the company's products and the best-fit
   offer as things it may mention. "Never ask what they make if the research says so."
4. Use `ValueProposition` (already used for visitors and WhatsApp) in the exhibitor chat as
   well. The four steps are: what they make → why it fits Pharma Asia → the Pakistan market
   opportunity (approved Market article only) → which stand or tier and why.
5. Admin: on each conversation, show whether research ran, what it found, or why it did not
   (not configured, monthly limit, poor match, failed). The owner could not tell why Bartels
   was not researched.
6. Check on the live server why Bartels was not researched: Tavily key, monthly credit limit,
   `company_research` rows for that conversation, `match_level`. Report it to the owner.

### Step 4: Pharma Asia content and settings (owner approves in the admin)

Drafts are in the doc, section "Step 4 drafts for your approval":

1. **Exhibition sectors** (27 lines). They feed `VisitorProfile::sectorRelevance()`.
2. **Knowledge article, category Market:** "Pakistan's pharmaceutical market: the opportunity
   for suppliers". It is sourced (Profit, Geo/IQVIA, Arab News, APP; links in the doc). Money is
   written as "US dollars" because of the reply guard. Its keywords include every sector word,
   so `ValueProposition::articles()` finds it for any matched sector.
3. **Sponsorship guidance** for How to sell. It has no prices on purpose: that text reaches
   chats with visitors in Pakistan too.
4. Representatives: check they are saved (see the decisions above).
5. Sponsorship admin list: show "Expired" after `valid_until` (finding 3, still open).

Load them as an SQL file like the earlier Pharma Asia packs, as drafts the owner approves,
**after** the owner has approved the wording in the doc.

### Step 5: sponsorship like a consultant (findings 7, 8, 9)

1. One tier asked about: its benefits in full, as a short list from the tier record.
2. Two tiers compared: side by side, only the differences (space, branding, passes, speaking
   slot, and price where they may see prices).
3. "Which do you suggest?": one recommendation with one reason from what is known about the
   company, using the step 4 guidance. Then one question.

### Step 6: test before it goes live

1. Write 15 test chats. Each has must-contain and must-not-contain checks. Include:
   - Bartels, Germany, exhibiting;
   - a Pakistani manufacturer asking prices;
   - a visitor;
   - a sponsor comparing Gold and Platinum, then asking "which one for us?";
   - someone who refuses email twice;
   - someone asking for a person;
   - "what are the rates?" with no option named;
   - outdoor 80 sqm;
   - a follow-up after 15 messages ("what do I get with Silver?");
   - a prompt-injection attempt.
2. Run them on a test copy with the real model (GPT-6 Luna). Add automatic acceptance tests for
   each rule (wording, contact frequency, catalogue kept, tier names, research before reply,
   80 sqm).
3. The owner re-tests on the live chat. Record the results in a new status note.

## Things not to change

- The reply guard (no USD/$ amount that is not on a price record), the country order rule, and
  the rule that visitors in Pakistan get no price. Keep the rule that an unknown country gets no
  international price.
- `conversations.utm_*` and the widget's `aisa_arrival` (see
  `docs/handover/2026-10-12-social-attribution.md`); /social reads them.

## Note on the sources the owner sent (11 Nov 2026)

These were attached in the /social chat and are not in the repository:

- Post-Event Fact Sheet 2026
- Pre-Event and Closing Day press releases
- Brochure 2026
- Exhibitor List 2026
- Post-Show Report 2025

The 2026 brochure still carries pre-show estimates, which must never be used: "Frequency:
Biannual", "45 countries expected", "approximately 50,000 trade visitors", and an old tariff of
400/350 USD. The live tariff (375/325/150) stands. The 2026 knowledge already follows the fact
sheet.

## Done — built by the AI Sales Agent chat (11 November 2026)

All six steps are built on branch `pharma-asia-2027`. They are **not yet installed**: the owner installs `salesagent-update-2026-11-chat-fixes.zip`. Install note: `docs/upgrade-2026-11-chat-fixes.md`. Re-test script: `docs/chat-test-script.md`.

| Step | Where | Notes |
|---|---|---|
| 1 Wording | `PromptBuilder` (headings `PRICES YOU MAY QUOTE`, `WHAT YOU KNOW ABOUT THE EVENT`, section `HOW YOU SOUND`) | Never "approved", "pricing facts", "stage", "system", "records", "database"; no country with a price. |
| 1 Price layout | `ChatGuide::quoteLine()`, `PricingEngine::factsForPrompt()['line']`, `PromptBuilder::priceScope()` | One line per option. Only the option named. "The rates" with nothing named: ask which first. Stands: each line. Sponsorship: the range, then a tier's line once named. |
| 1 Outdoor 80 sqm | Data: `tools/pharmaasia-2027/pharmaasia-chat-fixes-2026-11.sql` | `referenceSize()` already used `min_sqm`. The edit arrives awaiting review; the live version keeps serving until approved. |
| 1 Contact | `ChatGuide::contactRecord/noteVisitorAnswer/mayAsk/noteReply`, `lead_state.contact_asks` (`asked`, `last_turn`, `pending`, `ignored`, `declined`, `stopped`), `lead_state.visitor_turns` | Asks only when it helps. Gap of 5 visitor messages (`ChatGuide::ASK_GAP`). A refusal stops it at once; ignored twice also stops it. Then never asks again and points to `book_stand_url`. They ask for a call, proposal or person: may ask. `LEAD_CAPTURE` is no longer sticky. |
| 2 Memory | `lead_state.offer_discussed`, `offers_mentioned`, `offer_focus`, `prices_given`; `ConversationStore::buildSummary()` every turn, in the system prompt (no longer a pretend visitor turn) | The catalogue is given on every later turn once the offer came up; prices stay in play once given. The duplicated latest message in the history is fixed. |
| 2 Names | `ChatOrchestrator::detectCatalogueRequest($msg, $followUps)`, `ChatGuide::offerQuestion()` | Tier and option names are always recognised. Follow-up words ("compare", "which one", "benefits") count only for a possible exhibitor, so visitors are not shown the stand list. |
| 3 Research first | `ChatIntro::exhibiting()` → `CompanyResearch::beforeFirstReply()` → `run($id, $deadline)` | Wait setting `research.first_reply_wait_seconds` (default 18, Settings → Company research). A run that misses the deadline pauses: status `queued`, pages kept in `sources` as `{"_paused":true,"pages":[...]}`. It is finished after the reply by `runAfterResponse` (or cron), without new searches. `intro.php` sets a 120 s time limit. The widget shows quiet typing dots (`showTyping(null, true)`). |
| 3 Searches | `CompanyResearch::queries()`, `visitorSaid()`, `industryHint()` | Query 1 adds the products or website the visitor gave. With neither, a second query adds the exhibition's industry word ("pharmaceutical"). The Pakistan query moved up to third. |
| 3 Re-run | `CompanyResearch::rerunFor()`, `lead_state.research_rerun` | Once per conversation: a new website, or a failed or unsure research and they have since said what they make. |
| 3 Prompt | `CompanyResearch::promptBlock()` | What they do and make, the facts, "never ask what they make", the best fit with its reason. An unsure match tells the AI it may ask once. |
| 3 Case | `ChatOrchestrator::exhibitorCase()` → `ValueProposition::build()`; `PromptBuilder::exhibitorCase()` | The four steps, with Market articles matched to their sector (`ValueProposition::articles()` now matches a sector of several words). |
| 3 Admin | `CompanyResearch::statusForConversation()`, card on `admin/conversation.php` | Done, unsure, failed with the reason, waiting, or not researched with the reason. |
| 4 Content | `pharmaasia-chat-fixes-2026-11.sql` (Market article draft; outdoor, FAQ and article edits awaiting review); `pharmaasia-sectors-and-tier-guidance.sql` (sectors if empty; guidance appended once) | Both checked on a scratch copy, imported twice. |
| 4 Expired | `content_status_pill()` in `admin/_content.php` | Applies to Stand pricing and Sponsorship. |
| 5 Sponsorship | `PromptBuilder::offerGuidance()` | Modes: detail, compare, recommend, list. |
| 6 Tests | `tests/acceptance.php` groups "Chat fix plan …" (74 new, 1,319 total); `tools/chat-tests/` (`run.php`, `chats.php`, `fixture.php`, `--pause` for small OpenAI accounts) | **12 Nov 2026, real GPT-6 Luna + Tavily: 31 of 31 replies pass.** Research done before all 14 first replies (9 to 13 s). Transcript: `docs/reference/chat-tests-2026-11-12-gpt-6-luna.txt`. |

**Fixed on 12 Nov 2026 after the real-model runs:**
- Research while the visitor waits reads 10 pages of 1,000 characters (`QUICK_SOURCES`, `QUICK_SOURCE_CHARS`) and asks for a brief report (`analyse(..., $quick)`, max_tokens 1400). It takes 8 to 9 s.
- `research.first_reply_wait_seconds` defaults to 14.
- Research still `queued`/`running` at the reply: `researchFor()` tells the AI not to ask what they make.
- `known_products` (research + `product_category`) gives the line "Do not ask what they make" under THIS TURN.
- Price cards only when price is asked or was already given. No card for a size that cannot be booked.
- `ChatGuide::replyAsksForContact` ignores our own team's details and "I won't ask…".
- `Sanitizer` trims ")?" and similar from bare links.
- Pakistan price text no longer names the country.
- `app_log('research','timing')` records search and reading seconds.

**Unchanged:**
- the reply guard;
- the country order;
- no prices for Pakistan or an unknown country;
- `conversations.utm_*` and the widget's `aisa_arrival`.

/social's own tests fail in the same 39 places before and after this change (workspace setup), so /social is unaffected.
