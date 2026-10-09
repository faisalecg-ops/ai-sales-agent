# Sales agent — status note, 9 October 2026

Read this before any work on the sales agent.

## 1. Live platform: event-facts upgrade INSTALLED (9 October 2026)

**Update, 9 October 2026 evening:** the owner installed
`salesagent-upgrade-2026-10-event-facts.zip` on the live sales agent
(salesagent.ecgateway.net). He also gave this note to the sales agent chat,
which ran its own tests successfully. The live sales agent is now the delivered
version **plus** the change below. GitHub and the live server match.

After uploading, two steps must be done in the live admin. Confirm both:
**Database upgrade** (step `2026_10_event_facts`), and filling in
**Exhibitions → edit → Dates & venue** for each exhibition.

## 2. The change

Commit `6fae771` ("Event Blog API and cron; sales agent: show dates/venue fields
and read-only event facts endpoint") was made in the Event Blog chat so each
event blog could read show dates and venue from the sales agent. The owner had
not specifically asked for it at first; he accepted it and installed it on
9 October 2026.

Three files changed:

### `app/domain/Migrations.php`

- New last step, key `2026_10_event_facts`, label "Show dates, venue and
  booking pages for the event blogs".
- Check: `columnExists('agents', 'event_starts_on')`.
- Adds seven columns to `agents`:
  - `event_starts_on` DATE NULL
  - `event_tagline` VARCHAR(300) NULL
  - `event_venue` VARCHAR(200) NULL
  - `event_city` VARCHAR(120) NULL
  - `event_country` VARCHAR(96) NULL
  - `register_url` VARCHAR(255) NULL
  - `book_stand_url` VARCHAR(255) NULL
- No existing column or table changed.

### `public_html/admin/agents.php`

- New card `set-show`, "Show dates and venue" (menu label "Dates & venue"),
  placed after "The exhibition" card.
- Fields: first day, last day, one-line description, venue, city, country,
  "Register to visit" page, "Book a stand" page.
- The existing **"Last day of the exhibition" (`event_ends_on`)** field was
  **moved** here from the WhatsApp follow-ups card, which now shows a hint
  linking to it.
- `event_ends_on` still stops WhatsApp follow-ups after the show
  (`WhatsAppFollowUp.php` is unchanged).
- The new fields are saved only once the upgrade step has run.
- If the last day is before the first day, the form shows an error.

### `public_html/api/event.php` (new)

- `GET /api/event.php?agent=<slug>`, active agents only.
- Returns `{"ok":true,"event":{name, tagline, start_date, end_date, venue, city,
  country, website_url, register_url, book_stand_url}}`.
- Unknown slug: 404. Not GET: 405.
- Public and read-only: no prices, leads or settings. Cached for one hour.
- Does not use `_api.php` or the origin check, because the blog calls it
  server to server.

Nothing else changed: AI, prompts, pricing, chat, WhatsApp, prospecting and
email are untouched, and what the assistant says is unchanged. All 1,179
acceptance tests pass with the change.

- Upgrade note: `docs/upgrade-2026-10-event-facts.md`.
- Upgrade file: `salesagent-upgrade-2026-10-event-facts.zip` (installed live, 9 Oct 2026).

## 3. Rules for future sales agent work

- **Always start from this GitHub repository, never an old zip.** An old zip
  lacks these three changes; uploading it now would break the live **Dates &
  venue** card and the blogs' event facts.
- Any new sales agent package (upgrade or fresh install) must include these
  three files and the `2026_10_event_facts` upgrade step.
- **The Event Blog does not depend on it.** Anything typed in a blog's own
  admin (Event & courtesy) takes priority over the sales agent's facts.

## 4. Wider picture

- The **Event Blog** (Phase 1) is built in `blog/`. It is installed separately
  on each event website at `/blog`, with its own tables (prefix `blog_`).
- The blog shows the sales agent chat with the normal embed. The event website
  must be in the exhibition's "Websites allowed to show the chat".
- The **Social Media Agent** (`/social`) is discussion only. Agreed rule: it
  reuses the sales agent's files and database read-only, has its own `sm_`
  tables, and never changes the sales agent.
