# Sales agent update — 14 November 2026: the Pharma Asia transcript review

The owner sent a live demo chat (a China-based maker of reactor tanks and pressure vessels) and asked to
apply `docs/PHARMA_ASIA_CHAT_TRANSCRIPT_REVIEW_AND_RECOMMENDATIONS.md` (on `main`). Built by the AI Sales
Agent chat on branch `pharma-asia-2027`, inside the same final package
(`salesagent-update-2026-11-final.zip`, guide `docs/install-2026-11-final.md`). No database step.

## P0 — what happened with the quotation, and the fix

In the demo, the chat asked for an email address "to send the Platinum quotation". Once it had the address,
it said it could not send one. Had the chat really been sending quotations, the program would have added
"Quotation PA27-Q-… is on its way". That line is missing, so on the live site the chat could not send.
One of three things was true:
- quotations were not switched on;
- the exhibition's mail account was not set;
- the 5-a-day limit for that email address had been reached by earlier tests.

In each case the AI improvised the offer itself. Now:

1. **Only the program says something was sent or passed on.** A new honesty filter,
   `Quotation::stripClaims`, takes sentences out of the AI's reply. It removes any "is being emailed", "has
   been sent" or "I have passed it on". When the chat cannot send quotations, it also removes any "I can
   arrange / send / prepare the quotation" and "what address should I send it to". The prompt's new system
   rule 12 says the same, in every language.
2. **When the chat cannot send quotations itself** (switched off, no mail account, WhatsApp), the request
   goes to the sales team:
   - The prompt section is **QUOTATION (prepared by the sales team, not by this chat)**. The AI still gives
     the prices. Without an email address, it asks for one once, saying the sales team will email the
     quotation.
   - Once the address is saved on the lead, the conversation is handed over with the reason "Asked for a
     quotation for … by email to …; the chat cannot send quotations for this exhibition. Please send it."
   - The team's email now starts with **Why they need you**, followed by that reason.
   - Only then does the program add: "I have passed your request for a quotation to our sales team. They
     will email it to …" (in the chat's language).
3. **When the chat's limit is reached** (3 per chat, 5 per address per day), the same team route applies.
   The AI is told not to promise anything.
4. **When making the PDF fails**, the program says so honestly and passes the request to the team (line
   `quote_failed`).
5. **When everything is ready**, the AI is no longer told to say "it is being emailed now". It says nothing
   about sending; the program's "is on its way" line comes only after the quotation has really been made.
   The email itself goes out straight after, through the exhibition's own account, with retries, and the
   team's copy carries the PDF.

## P1

- **All tiers at once.** "Sponsorship prices" now gives every tier's price line in one reply, lowest first,
  instead of a range. "All options" or "all prices" gives every stand option and every tier together
  (`ChatGuide::asksForAll`, also in the other 15 languages).
- **Several options in one quotation.** "Give all 4 quotations" or "a quotation for all sponsorship tiers"
  makes ONE quotation with up to four options:
  - each option has its own price, VAT and total, and its full list of what is included, cheapest first;
  - the PDF runs over several pages, each continued list is labelled, and the PDF notes that the options
    are alternatives;
  - the email lists each option with its total;
  - it counts as one quotation towards the limits.

  The visitor's choice is kept across turns until the address comes (`lead_state.quote_names`).
- **Exact names.** The assistant is given the options' exact names when it asks "which option", and it is
  told always to use them (no "Title Partner" for "Title Sponsor").
- **Stand and sponsorship together.** The prompt explains that the two do different jobs and can be
  combined. The chat may say a tier includes stand space, passes or anything else only when that tier's
  list says so; otherwise it says "not stated, the team can confirm".

## P2

- **Large machinery.** When what they make is machinery (reactor, vessel, tank, packaging, filling,
  blister, dryer…), the chat first asks, as its one question, the size of the largest unit and whether
  they bring it full size or as a model. Then it suggests a fitting option (bare or outdoor space suits
  heavy or tall machines), adding that floor load, power and access are confirmed with the organiser.
- **The right link.** The stand booking page is only for stands. For sponsorship, the next step is a
  quotation or the sales team, unless a sponsorship page is known.
- **"Not ready to book yet, only comparing"** is no longer read as a "not this year" concern.

## Real-model tests

Chats 42–48 in `tools/chat-tests/chats.php` follow the review's tests 1–10:
- all tiers in one reply;
- all ways to take part;
- reactor tanks;
- all prices plus a quotation, then the email address;
- "not ready, only comparing";
- going ahead with Platinum;
- "does Gold include a stand", and the total with VAT.

Like 34–41, they need a working test key. Run them one at a time.

## Checks on the live site (owner)

- Agents and embed → Pharma Asia → **Quotations** must show **On** (logo uploaded, box ticked), and
  **Prospect emails** must have the mail account. Otherwise every quotation request now goes to the team,
  honestly.
- Testing with your own address: the limit is 5 quotations per address per day. After that, the request
  goes to the team. Use different addresses, or test on different days.
- The visitor figure "24,697" comes from the approved Pharma Asia fact sheet article. Change it there if
  a different figure should be used.

## Safeguards unchanged

These were not touched:
- prices and the price guard;
- the Pakistan rule;
- the country order;
- USD only;
- blocked countries;
- exhibition separation;
- lead dedupe.

Every figure in a quotation still comes from the price list.

## Files

Changed:
- `app/domain/Quotation.php` (several options, honesty filter, prompt);
- `ChatOrchestrator.php` (team route, several options, filter);
- `ChatGuide.php` ("all");
- `PromptBuilder.php` (rule 12, all tiers, stand and sponsorship, machinery, links);
- `Lang.php` (`quote_team`, `quote_team_ask`, `quote_failed` in 16 languages);
- `LeadBriefing.php` ("Why they need you");
- `Concerns.php`.

Tests:
- `tests/acceptance.php`: 1,710 pass (+28);
- `tests/access-http.php`: 82 pass;
- `tools/chat-tests/chats.php`: chats 42–48.
