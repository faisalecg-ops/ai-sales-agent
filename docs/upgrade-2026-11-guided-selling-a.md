# Sales agent update — 12 November 2026: guided selling, Stage A

Approved by the owner on 12 November 2026 (Claude doc "AI Sales Agent — Guided selling,
quotations and email replies: plan", https://claude.ai/artifact/TVpaToSMHxL91yjL3SDpVC).
Stage A is parts 1, 2 and 5 of that plan. Built by the AI Sales Agent chat on branch
`pharma-asia-2027`.

**This package also contains the chat fixes of 11 November** (`docs/upgrade-2026-11-chat-fixes.md`).
Install this one instead of `salesagent-update-2026-11-chat-fixes.zip`. The two SQL files of that
update are still needed, once.

## What is new

### 1. The guided path (part 1)

With a possible exhibitor, sponsor or partner, the assistant now follows eight steps, one
question at a time:

1. Their company
2. What they would show
3. Their markets
4. Their aim
5. The opportunity (the Pakistan market, only from your Market article)
6. The recommendation (one stand or tier, with one reason)
7. Their questions
8. The next step

- Anything research or the visitor already answered is not asked again.
- A visitor who asks something else gets the answer first; the path continues after.
- Visitors (not exhibitors) are not put on the path.
- **Company in Pakistan asking a price or a quotation:** no price, as before. The reply now ends
  with the local sales rep's contact details.

### 2. The qualification record and the lead score (part 2)

- Nine facts are kept for each possible exhibitor: products they make, products they would show,
  sells abroad, main markets, experience in Pakistan or South Asia, what they look for, main aim,
  stand interest, the contact's role. They come from the visitor's own words and from research.
- A score out of 100, shown as **Hot** (90+), **Warm** (70–89), **Nurture** (50–69) or **Low**.
- Where you see it:
  - **Conversation page:** "Sales path" (step 1 to 8) and "Qualification" cards.
  - **Lead page:** "Qualification" card.
  - **Leads list:** a Score column and a Score filter; the CSV download has the score too.
  - **Lead notification email:** "Lead score" and "Qualification" rows.
- **Agents and embed → the exhibition → Lead score:** the points for each part (products match the
  sectors 30, sells abroad 20, target country 15, decision maker 15, completeness 20) and the list
  of **Target countries**, one per line. `France | 2` marks a second-priority country (counts 60%).
  Left empty, every company outside Pakistan counts 60%.

### 3. Website answers (part 5)

- Once a week the assistant reads the exhibition's website from its sitemap (up to 400 pages,
  a few at every cron run). Pharma Asia's sitemap also lists the Pulse articles.
- When a visitor asks about something on the website, it answers from the matching page and gives
  that page's link when they need to do something there (register, book, download).
- It only gives links to pages it has read. A made-up address is replaced by "our website".
- **Prices written on the website are never used.** Prices come only from Stand pricing and
  Sponsorship.
- New admin page **Content → Website pages:** the list of pages, when each was read, a **Check**
  note when a page shows a different year than Dates & venue, a switch to stop using a page, and
  **Read the website now**.
- **Agents and embed → the exhibition → Website answers:** on/off, and the sitemap address
  (leave empty for the usual `/sitemap.xml`).

## Files in `salesagent-update-2026-11-guided-selling-a.zip` (26)

New: `app/domain/Qualification.php`, `app/domain/SitePages.php`, `public_html/admin/website.php`,
`app/domain/ChatGuide.php` (from the chat fixes).

Changed for Stage A: `app/domain/ChatOrchestrator.php`, `PromptBuilder.php`, `LlmContract.php`,
`Migrations.php`, `LeadBriefing.php`, `cron/tasks.php`, `public_html/admin/_admin.php`,
`agents.php`, `conversation.php`, `lead.php`, `leads.php`.

From the chat fixes, unchanged since: `app/domain/ChatIntro.php`, `CompanyResearch.php`,
`ConversationState.php`, `ConversationStore.php`, `PricingEngine.php`, `ValueProposition.php`,
`app/lib/Sanitizer.php`, `public_html/admin/_content.php`, `settings.php`,
`public_html/api/intro.php`, `public_html/widget/widget.js`.

## How to install (about 15 minutes)

1. Back up the database (phpMyAdmin → Export).
2. Upload the files from the zip into `/home/salesagent/`, over the existing ones.
3. Admin → **Database upgrade** → run the two new steps:
   - `2026_11_qualification` (score and qualification columns);
   - `2026_11_site_pages` (the website pages table).
   Until they are run the chat works as before; the new parts stay switched off.
4. If not done yet: the chat-fixes steps 3 to 6 in `docs/upgrade-2026-11-chat-fixes.md`
   (import `pharmaasia-chat-fixes-2026-11.sql`, approve in the Review queue, sectors and tier guidance).
5. Agents and embed → Pharma Asia → **Lead score:** type the target countries. Save.
6. Content → **Website pages** → **Read the website now**. The first pages appear at once, the rest
   over the next cron runs. Switch off any page the assistant should not use.
7. Do the same for Build Asia when its new website is live.
8. Test on the live chat: chats 16 to 18 in `tools/chat-tests/chats.php` (a full guided chat, a
   Pakistan company asking for a quotation, a visitor asking how to register and where the floor plan is).

Nothing changes for /social or the Backup Manager: no folder moved, no upload folder added,
`config/config.php` untouched. One new table (`site_pages`) and new columns on `conversations`,
`leads` and `agents`.

## Tests

- `php tests/acceptance.php`: 1,379 pass (60 new: qualification, score, path, website answers).
- `php tests/access-http.php`: 82 pass.
- **Not yet run with the real AI.** Chats 16 to 18 are written but need an OpenAI and a Tavily key
  in the test copy (`OPENAI_API_KEY=… TAVILY_API_KEY=… php tools/chat-tests/run.php`). The earlier
  test keys were deleted after use, as promised.
