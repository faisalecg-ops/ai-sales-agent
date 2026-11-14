# Install guide — sales agent, November 2026 (everything in one go)

**One package:** `salesagent-update-2026-11-final.zip`. It contains every change made since the version
you installed on 11 November 2026. Ignore all earlier November zips.

| What | Details in |
|---|---|
| Chat fixes (talks like a sales person, remembers the chat, researches first) | `docs/upgrade-2026-11-chat-fixes.md` |
| Guided path, lead score, website answers, blocked countries, company website box | `docs/upgrade-2026-11-guided-selling-a.md` |
| Quotation PDF, offered and sent by the chat, made by hand on the lead page | `docs/upgrade-2026-11-guided-selling-b.md` |
| 16 chat languages, stronger price check, personal "Let's discuss more" links | `docs/upgrade-2026-11-languages-and-chat-links.md` |
| Concerns answered with care ("too expensive", "ask my manager", "guarantee buyers"…), "don't contact me" respected | `docs/upgrade-2026-11-conversation-quality.md` |
| The demo-chat review: no false "I'll send it", quotation requests reach the team, all tiers at once, up to 4 options in one quotation, machinery sizes | `docs/upgrade-2026-11-transcript-review.md` |

Also needed, once: two SQL files for Pharma Asia, `pharmaasia-chat-fixes-2026-11.sql` and
`pharmaasia-sectors-and-tier-guidance.sql`.

## Steps (about 30 minutes)

### A. Upload and upgrade

1. **Back up the database:** phpMyAdmin → the sales agent database → Export.
2. **Upload** the files from `salesagent-update-2026-11-final.zip` into `/home/salesagent/`, over the
   existing ones (cPanel File Manager → Upload, then Extract).
3. Admin → **Database upgrade** → run every step it lists. There are four:
   `2026_11_qualification`, `2026_11_site_pages`, `2026_11_quotations`, `2026_11_personal_links`.
   Until they have run, the new parts stay switched off and the chat works as before.

### B. Pharma Asia content (once)

4. phpMyAdmin → the sales agent database → **Import** `pharmaasia-chat-fixes-2026-11.sql`.
   It adds the Market article as a draft, and outdoor space from 80 sqm with its FAQ and article
   wording, awaiting review.
5. Admin → **Review queue** (Pharma Asia): read and approve them.
6. phpMyAdmin → **Import** `pharmaasia-sectors-and-tier-guidance.sql` (the sector list and the
   sponsorship guidance; these go live at once, with no review step).

### C. Settings — Agents and embed → Pharma Asia

7. **Exhibition:** check the website address. The website answers and the personal links use it.
8. **Dates & venue:** check the last day. Personal links stop working after it.
9. **Lead score:** type the target countries, one per line (for example China, Germany, Türkiye).
10. **Quotations:**
    - upload the Pharma Asia logo;
    - check the number prefix (PA27) and the days (30);
    - type the footer lines: company name, address, phone, website;
    - leave the terms empty for the standard four;
    - tick **Let the chat email quotations by itself**.
    The card shows **On**. Until it does, the chat never sends a quotation itself: a request goes to the
    sales team, and the chat tells the visitor so.
11. **Personal chat links:** all three boxes are ticked; untick any you do not want.
12. **Sales reps → International** must be filled: that person is "Your contact" on every quotation.
13. **Prospect emails** must have Pharma Asia's own mail account: quotations are sent through it.
14. Press **Save**.

### D. Other screens

15. Content → **Website pages** → **Read the website now**.
16. Set-up → **Blocked countries**: tick the countries, save. Tick the same ones on the Pharma Asia
    website (Admin → Forms → Blocked countries).
17. Settings → Company research: check the Tavily key is saved and press **Test the research service**.
18. Make sure the OpenAI account used by the sales agent has a payment method (Tier 1 or above),
    otherwise the chat stops after about 50 messages a day.

### E. Try it

19. Clear your browser cache once (the chat window is new, version 1.2.0).
20. On pharmaasia.com.pk open the chat:
    - choose a language, for example 中文 or Bahasa Indonesia, as a company outside Pakistan, and ask
      the price of an 18 sqm shell scheme stall. You should get the price and the offer of a quotation;
      reply "yes" and give your own email address;
    - open the quotation email, read the PDF, then click **Let's discuss more — click here**. The chat
      should open, greeting you by name.
21. As a company in Pakistan, ask a price: you should get no price, and the local sales rep's details.
22. Admin → Leads: the lead shows its score, its quotation and the chat language.
23. In a test chat, say "that is too expensive" and then "please do not contact me again". The chat should
    accept it without offering a discount and without asking anything more. The conversation page shows
    **Concerns raised** and **Do not contact**. Your email is then on Set-up → Do not contact: remove it
    there after the test.

If something does not look right, tell me what you saw, with a screenshot if you can.

## Languages in the chat

English (default), 中文 Chinese, Urdu (Roman), العربية Arabic, Türkçe, Русский, Español, Français,
Deutsch, 한국어 Korean, Italiano, 日本語 Japanese, فارسی Persian, ไทย Thai, Bahasa Indonesia,
Bahasa Melayu — 16 in all. Quotation PDFs and emails stay in English.

## Tests

- `php tests/acceptance.php`: 1,710 pass. `php tests/access-http.php`: 82 pass.
- Real GPT-6 Luna and Tavily (12–13 Nov 2026): the English test chats, quotation chats, and one or more
  chats in each new language. Transcripts in `docs/reference/`.
