# Sales agent upgrade — October 2026: show dates and venue for the event blogs

A small upgrade. Nothing the assistant says changes.

## What it adds

- In **Exhibitions → edit**, a new card **Dates & venue**: first and last day
  of the exhibition, a one-line description, venue, city, country, and the
  "register to visit" and "book a stand" pages. (The last day used to sit with
  the WhatsApp follow-up settings; it now lives here, and still stops
  follow-ups after the show.)
- A read-only address each event blog reads once a day:
  `https://salesagent.ecgateway.net/api/event.php?agent=buildasia`.
  It returns only these public facts — never prices, leads or settings.

## Files changed

| File | Change |
|---|---|
| `app/domain/Migrations.php` | New upgrade step `2026_10_event_facts` (seven new columns on `agents`) |
| `public_html/admin/agents.php` | The **Dates & venue** card and saving it |
| `public_html/api/event.php` | New file: the read-only event facts address |

## How to install

1. Back up the database (cPanel → phpMyAdmin → Export) as usual.
2. Upload the three files from `salesagent-upgrade-2026-10-event-facts.zip`
   over the existing ones (same folders).
3. Open the admin → **Database upgrade** and run the outstanding step.
4. Edit each exhibition → **Dates & venue** → fill in and save.

All 1,179 acceptance tests still pass after the change.
