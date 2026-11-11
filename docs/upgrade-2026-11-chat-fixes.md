# Sales agent update — 11 November 2026: the website chat talks like a sales person

Approved by the owner on 11 November 2026 (Claude doc "AI Sales Agent chat — review
and fix plan", steps 1 to 6). Build brief: `docs/handover/2026-11-11-chat-review-and-fix-plan.md`.
Built by the AI Sales Agent chat on branch `pharma-asia-2027`.

## What changes for visitors

| Before (your test chat) | Now |
|---|---|
| "For a company based in Germany, the approved stand rates are…" | No "approved", "pricing facts", "stage" or "system", and no country next to a price. |
| Every price in one long sentence | One line per option, for example `Shell Scheme Stall, 9 sqm: USD 3,375 (USD 3,881.25 with 15% VAT)`. Only the option asked about. |
| "What are the rates?" got every figure | It first asks which option: a stand or sponsorship. |
| Outdoor space "1 sqm, USD 150" | From 80 sqm: `USD 12,000 (USD 13,800 with 15% VAT)`, once you approve the 80 sqm edit. |
| Asked for email in 4 of 6 replies | Asks once, when it helps (a proposal, a call, a price only the team gives). Then not again for at least 4 messages, never twice in a row. If they refuse, or ignore it twice, it stops and points to the Book a stand page. |
| "What do I get with Silver?" after a while got nothing | The stands and tiers stay in view for the whole chat. Tier names and follow-ups ("compare", "which one", "what do I get") are recognised. |
| Short, vague comparisons; no recommendation | One tier: its benefits in full, as a list. Two tiers: only the differences. "Which one for us?": one tier, with one reason, then one question. |
| Forgot earlier parts of the chat | A short summary of the whole chat goes with every reply (who they are, what they make, what was shown and quoted, how the contact question went). |
| Never researched Bartels, asked what they make | When someone chooses "exhibiting" on the card, their company is researched first: up to 18 seconds, with only the typing dots showing. If research is slower, the reply goes out normally and research finishes straight after, for the next reply. |
| No Pakistan market argument | For a possible exhibitor, the assistant builds the case in four steps: what they make, why it fits, the Pakistan market (only from your Market article), and the stand or tier that suits them. |

## What changes in the admin

- **Conversation page:** a new **Company research** card shows whether research ran, what it found (what they make, the best fit), or why it did not run: not an exhibitor, no company name, no research service set up, or the month's limit reached.
- **Settings → Company research:** new box **Seconds to wait before the first reply to an exhibitor** (0 to 20, set to 18). 0 switches the wait off.
- **Stand pricing and Sponsorship lists:** a price whose end date has passed shows **Expired** instead of "Live" (finding 3).

## Files in `salesagent-update-2026-11-chat-fixes.zip`

| File | Change |
|---|---|
| `app/domain/ChatGuide.php` (new) | Which option was named, the contact-asking rule, the one-line price layout |
| `app/domain/PromptBuilder.php` | New wording rules and headings, price lines, option guidance, the whole-chat summary, the case for exhibiting |
| `app/domain/ChatOrchestrator.php` | Keeps the offer in view, remembers what was said, applies the contact rule |
| `app/domain/ConversationState.php` | No longer stuck in "capturing contact details" |
| `app/domain/ConversationStore.php` | The summary of the whole chat, every turn |
| `app/domain/CompanyResearch.php` | Research before the first reply, better searches, one repeat, the status for the admin |
| `app/domain/ChatIntro.php` | Runs research before the first exhibitor reply |
| `app/domain/ValueProposition.php` | Finds the Market article for a sector named in several words |
| `app/domain/PricingEngine.php` | The ready-made price line |
| `app/domain/LlmContract.php` | Replies may carry a short list |
| `public_html/api/intro.php` | Allows the research wait |
| `public_html/widget/widget.js` | Typing dots, with nothing said, while the first reply is prepared |
| `public_html/admin/conversation.php`, `_content.php`, `settings.php` | Research card, "Expired", the wait setting |

**There is no Database upgrade step.** Nothing in the database changes shape.

## How to install (about 10 minutes)

1. Back up the database (phpMyAdmin → Export).
2. Upload the files from `salesagent-update-2026-11-chat-fixes.zip` into `/home/salesagent/`, over the existing ones.
3. phpMyAdmin → the sales agent database → **Import** `pharmaasia-chat-fixes-2026-11.sql`. It adds:
   - the Market article "Pakistan's pharmaceutical market: the opportunity for suppliers", as a **draft**;
   - outdoor space from 80 sqm, and the FAQ and stand-article wording that went with it, all **awaiting review**. Today's version stays live until you approve.
4. Admin → **Review queue** (Pharma Asia): read and approve the Market article, Outdoor Exhibit Space, and the edited FAQs and article.
5. **Sectors and sponsorship guidance** (the wording is in the plan doc, "Step 4 drafts"). Either:
   - import `pharmaasia-sectors-and-tier-guidance.sql`. It fills the sector box only if it is empty, and adds the guidance to the end of "How to sell", once; or
   - paste them yourself under Agents and embed → Pharma Asia.

   These two settings have no draft stage, so do this only once you are happy with the wording.
6. Settings → Company research: check that the Tavily key is saved and the meter is not at the month's limit. Press **Test the research service**.
7. Re-test on the live chat with `docs/chat-test-script.md`.

## Why Bartels was not researched

The old code only started research after the first reply. It then ran only if all of these were true:

- a research service was set up;
- the month's limit was not reached;
- the company could be told apart from others with the same name ("Bartels" alone is shared by several companies).

From now on, the conversation page's **Company research** card says which of these happened. For your Bartels test chat, it shows the reason as soon as this update is installed.

## Tests

- `php tests/acceptance.php`: 1,313 pass (68 new, one group per plan step).
- `php tests/access-http.php`: 82 pass.
- `php tools/chat-tests/run.php`: the 15 written test chats with the real AI model, on a test copy. It refuses a live installation. See `docs/chat-test-script.md`.
