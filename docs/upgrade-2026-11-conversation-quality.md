# Sales agent update — 13 November 2026: conversation quality (concerns and opt-out)

The owner asked (13 Nov 2026) for improvements according to
`docs/SALES_CONVERSATION_QUALITY_AND_PROMPT_IMPROVEMENT_PLAN.md` (on `main`). Built by the AI Sales Agent
chat on branch `pharma-asia-2027`, inside the same final package
(`salesagent-update-2026-11-final.zip`, guide `docs/install-2026-11-final.md`). No database step.

## What was already there (not duplicated)

The plan was written against `main`, which is behind this branch. Most of it already exists:

| Plan | Already built |
|---|---|
| One useful question at a time; answer first | System rules "HOW YOU SOUND"; `Qualification::promptBlock` ("the visitor leads") |
| Discovery, next best question (§4 stages 2–4, §7) | The eight-step sales path and nine facts (`Qualification`, 12 Nov) |
| Grounded research, no guessing on same-name companies (§3.3, stage 3) | `CompanyResearch::promptBlock` (facts only, `match_level = poor` says nothing), Company website box |
| Value proposition in the main chat (stage 5) | `ChatOrchestrator::exhibitorCase` → `ValueProposition::build`, market notes only from approved articles |
| Recommend one option with a reason (stage 6) | `ChatGuide::offerQuestion` modes recommend/compare/detail |
| Pricing in PHP only, country rules, USD only (stage 7, §13) | `PricingEngine`, price guard, `GeoClassifier`; unchanged |
| Contact asked progressively, not repeatedly (stage 9) | `ChatGuide::mayAsk` (asked once, gap of 5, stops after a refusal) |
| Fit / intent / readiness / authority kept apart (§6.3) | Score parts (sector, sells abroad, target country, decision maker, completeness) + Hot/Warm/Nurture/Low label + path step; a non-decision-maker loses only 15 points |
| Handover briefing (§8) | `LeadBriefing` (research marked "not checked fact", qualification, quotations, language) |
| Research timing (§10.1) | Kept as the owner approved on 11 Nov: research before the first exhibitor reply, waiting at most 14 s, then the reply goes without it |
| Real-model evaluation set (§11) | `tools/chat-tests/` (now 41 chats) |

## What was added

1. **Concerns** (`app/domain/Concerns.php`, plan stage 8 and §5.5):
   - PHP reads the common concerns in English and Roman Urdu: too expensive, needs management approval,
     send me details, we already do other exhibitions, not ready this year, guarantee buyers / ROI, quality
     of visitors, and "don't contact me".
   - The AI also reports the concern it heard, in any language, in a new `concern` field of its reply
     (`LlmContract`, fixed list).
   - For each concern the prompt gets a short answer pattern (section WHEN THEY RAISE A CONCERN):
     acknowledge, find the real concern, answer honestly, one low-pressure next step. No discount, no
     pressure, no invented urgency or results.
   - For guarantee, audience, other shows and price concerns, the approved facts about who visits
     (`ValueProposition` audience articles) are given with "use these figures exactly".
   - Concerns are kept in `lead_state.concerns`. Later replies are reminded of them. The conversation page
     and the team's lead email show **Concerns raised**.
   - Research's own predicted concern and answer (`findings.objections`) is now given to the chat, for
     judgement only, used only if the visitor raises it.
2. **"Please don't contact me"** (plan scenario H):
   - When PHP reads it, the email address and phone number of the lead or chat go on the exhibition's
     **Do-not-contact list** (source opt_out). So no prospect email, WhatsApp message or follow-up reaches
     them. The chat stops asking for details and offering quotations. Every later reply is told to answer
     only and not sell.
   - When only the AI heard it (another language), the asking stops and the team is told to check the
     chat ("May not want contact") before adding them by hand.
   - The lead email starts with "They asked in the chat NOT to be contacted".
   - They can still ask for a quotation themselves; then it is sent.
3. **Prompt rules** (§3, §5.1, §5.8):
   - rule 11: never promise or imply guaranteed buyers, orders, sales, leads, meetings or ROI;
   - answer their question first and never hold information back until they give details;
   - the latest thing they said wins, and a change of mind (visiting ↔ exhibiting) is followed at once;
   - a visitor gets visitor help, not a pitch;
   - research: at most one or two facts per reply, never recited, and never "I researched you".
4. **Decision role** (stage 9, scenario D):
   - someone preparing options for management is "just as important"; the chat helps them build the
     summary and never asks for someone more senior;
   - when the role is unknown, the chat may ask once, naturally, at step 7.
5. **Lead email, "Still unknown"**: now also lists what they want from exhibiting, the stand size and
   whether this person decides, when the chat did not find out.

## Not done, and why

- **A timing field** ("when will you decide"): the 2027 edition is a year away, and "not ready this year"
  is already caught as a concern. It can be added to the nine facts later if the team wants it.
- **A conversion dashboard** (§11.4): Analytics already counts chats, leads, quotations and handovers. A
  per-stage drop-off report is a separate piece of work, best done after a few weeks of real chats.
- **Per-dimension 1–5 scoring of test chats** (§11.1): the test chats check concrete rules per reply
  (must / must not). Scoring by a second AI is not added.

## Safeguards unchanged

These were not touched:
- prices and the price guard;
- country order and the Pakistan rule;
- the USD-only rule;
- blocked countries;
- exhibition separation and staff access;
- lead dedup and manual status.

The new `concern` field cannot change a price, a state or a country. Only a PHP-read opt-out writes
anything, and that is the Do-not-contact list.

## Files

- New: `app/domain/Concerns.php`.
- Changed:
  - `app/domain/ChatOrchestrator.php`, `PromptBuilder.php`, `LlmContract.php`, `Qualification.php`,
    `CompanyResearch.php`, `LeadBriefing.php`;
  - `public_html/admin/conversation.php`.
- Tests: `tests/acceptance.php`; `tools/chat-tests/chats.php` (chats 34–41).

## Tests

- `php tests/acceptance.php`: 1,682 pass (46 new: concerns read in English and Roman Urdu, not read in
  ordinary questions, the prompt guidance, an opt-out put on the Do-not-contact list, the briefing, the
  decision-role guidance).
- `php tests/access-http.php`: 82 pass.
- Real-AI chats 34–41 have been written (price concern, collecting for a director, guarantee, other
  exhibitions, not ready, opt-out, a visitor changing to exhibiting, "send me details"), but not run yet.
  The earlier test keys no longer work because the owner changed them. Run them one at a time (they
  share one test database):
  `OPENAI_API_KEY=… TAVILY_API_KEY=… php tools/chat-tests/run.php --only=34`.
