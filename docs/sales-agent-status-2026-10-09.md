# Sales agent — status note, 9 October 2026

Read this before any work on the sales agent.

## 1. Live platform: unchanged

Nothing has been installed on salesagent.ecgateway.net. The live sales agent is
exactly as delivered (1,179 tests, upgrade package and fresh-install package).

## 2. GitHub: one small sales agent change, not installed

Commit `6fae771` ("Event Blog API and cron; sales agent: show dates/venue fields
and read-only event facts endpoint") was made in the Event Blog chat so each
event blog could read show dates and venue from the sales agent. The owner had
not specifically asked for it. It is kept in the repository but **not installed**.

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
- Upgrade file: `salesagent-upgrade-2026-10-event-facts.zip` (not installed).

## 3. Rules for future sales agent work

- **Always start from this GitHub repository, never an old zip.** An old zip
  lacks these three changes, and uploading it would silently remove them.
- **To install the change:** upload the three files, run **Database upgrade**
  in the admin, then fill in **Exhibitions → edit → Dates & venue**.
- **To remove the change:** undo commit `6fae771` for these three files only;
  the same commit also contains blog files, which must stay. No database
  clean-up is needed unless the upgrade was installed. If it was, the seven
  columns may be dropped, but leaving them is harmless.
- **The Event Blog does not need this change.** Without it, each blog's event
  facts are typed in the blog admin (Event & courtesy).

## 4. Wider picture

- The **Event Blog** (Phase 1) is built in `blog/`. It is installed separately
  on each event website at `/blog`, with its own tables (prefix `blog_`).
- The blog shows the sales agent chat with the normal embed. The event website
  must be in the exhibition's "Websites allowed to show the chat".
- The **Social Media Agent** (`/social`) is discussion only. Agreed rule: it
  reuses the sales agent's files and database read-only, has its own `sm_`
  tables, and never changes the sales agent.
