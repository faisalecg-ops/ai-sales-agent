# Sales agent update — 12 November 2026: guided selling, Stage B — the quotation PDF

**Superseded package:** install `salesagent-update-2026-11-languages-links.zip` instead (it contains these files
and the 13 November update). The steps below still apply.

Part 3 of the plan the owner approved on 12 November 2026 (Claude doc "AI Sales Agent — Guided selling,
quotations and email replies: plan", https://claude.ai/artifact/TVpaToSMHxL91yjL3SDpVC). Built by the
AI Sales Agent chat on branch `pharma-asia-2027`.

**This package contains everything since the last installed version:** the chat fixes of 11 November,
Stage A (guided path, lead score, website answers), blocked countries, the company website box, and
Stage B. Install this one only; ignore the earlier zips. `docs/upgrade-2026-11-guided-selling-a.md` and
`docs/upgrade-2026-11-chat-fixes.md` describe the earlier parts.

## What is new

### The quotation

A one-page PDF (a second page only when a tier has a long benefit list):

- exhibition logo, name, dates and venue; **QUOTATION**, its number, date and "valid until";
- **Prepared for:** the visitor's name, company and country;
- **Your contact:** the international sales rep, from Agents and embed → Sales reps;
- the option, size, rate, amount, VAT and total, all from the price list;
- what is included (for a tier: its full benefit list);
- the terms; **no payment details**;
- footer: the Ecommerce Gateway Pakistan (Pvt) Ltd logo and the organiser's lines.

Numbers run per exhibition: `PA27-Q-0001`, `PA27-Q-0002`.

### When the chat sends one by itself

The visitor asks for a quotation, a proposal or a pro forma, or says yes when the assistant offers one.
All of these must be true:

1. the company's country is known **from the visitor's own words** and is outside Pakistan;
2. one stand option with a size that can be booked, or one sponsorship tier;
3. that price is live and approved in the price list;
4. name, company and email address are known.

**The chat offers a quotation itself** (added 13 Nov 2026, after the owner found it never offered one):
after it shows a price to a company outside Pakistan, and when the visitor asks how to go ahead. If the
AI's reply does not offer it, the program adds: *"I can also email you an official quotation for this.
Just reply "yes"."* A plain "yes" then sends the quotation for the option and size just priced. It is
offered at most twice in a chat, and never again once the visitor has declined to give their details.

If one thing is missing, the assistant asks for that one thing. A size that cannot be booked (20 sqm):
it offers the nearest sizes first. The request is remembered, so an email address given two messages
later still sends the quotation.

The reply ends with a line the program writes: *"Quotation PA27-Q-0001 is on its way to name@company.com.
It is valid until 12 Dec 2026."* The assistant never says a stand is booked or reserved.

- **Company in Pakistan:** never a quotation and never a price. The local rep's details are given.
- **Limits:** 3 per conversation, 5 per email address per day. After that the visitor is given the
  sales rep.
- **Your team's copy:** no BCC. The lead notification "Quotation sent — name, company" carries the same PDF.
- **Mail server down:** the quotation is kept and tried again by the cron job (4 tries).
- **Blocked countries and Do not contact** stop a quotation too.

### In the admin

- **Agents and embed → the exhibition → Quotations:** the on/off switch (off until you switch it on),
  number prefix, valid days, the terms, the footer lines, and the exhibition logo (PNG or JPG, 1 MB).
- **Lead page → Quotations:** each quotation with its number, total, address, status, **Open PDF**, and
  **Send again** for one that did not go out. **Make a quotation** (managers): choose the option from
  the price list, type the size, check the address. Figures cannot be typed. Not offered for a company
  in Pakistan, a blocked country, or a lead whose country is not known.
- **Lead email:** a "Quotations" row.
- **Analytics → Website chat:** "Quotations emailed".
- A plain request for a quotation no longer adds the sales rep's details to the reply, because the chat
  now handles it. Discounts, contracts, invoices and payments still do.

### Known limit

The PDF uses the fonts every PDF reader has. They draw English and accented European letters. A name
typed in Chinese, Urdu, Arabic or Russian letters cannot be drawn and is left out of the PDF. Company
names are normally given in English in the chat; if you need Chinese names on quotations, say so and
I will add a Chinese font (it makes each PDF larger).

## Files

New: `app/domain/Quotation.php`, `app/lib/PdfWriter.php`, `app/assets/ecommerce-gateway-logo.png`,
`public_html/admin/quotation.php`.

Changed for Stage B: `app/lib/Mailer.php` (emails can carry a file), `app/domain/Migrations.php`,
`ChatOrchestrator.php`, `PromptBuilder.php`, `Qualification.php`, `LeadManager.php`, `LeadBriefing.php`,
`Analytics.php`, `cron/tasks.php`, `public_html/admin/agents.php`, `lead.php`.

Everything from the earlier 11 and 12 November updates is in the zip too (43 files in all).

New folders, created by the program when first needed, both outside the web folder:
`storage/quotations/<exhibition id>/` (the PDFs) and `storage/uploads/quotation-logos/`.
The Backup Manager has a note about them.

## How to install (about 20 minutes)

1. Back up the database (phpMyAdmin → Export).
2. Upload the files from `salesagent-update-2026-11-guided-selling-b.zip` into `/home/salesagent/`,
   over the existing ones.
3. Admin → **Database upgrade** → run the three new steps: `2026_11_qualification`,
   `2026_11_site_pages`, `2026_11_quotations`.
4. If not done yet, import the two SQL files of the chat fixes and approve the items in the Review queue
   (steps 3 to 5 of `docs/upgrade-2026-11-chat-fixes.md`).
5. Stage A settings, if not done yet: target countries (Lead score), **Read the website now**
   (Website pages), **Blocked countries**.
6. Agents and embed → Pharma Asia → **Quotations:**
   - upload the Pharma Asia logo;
   - check the prefix (PA27) and the days (30);
   - type the footer lines: company name, address, phone, website;
   - leave the terms empty for the standard four, or type your own;
   - tick **Let the chat email quotations by itself**. Save. The card then shows **On**.
     **Until this box is ticked, the chat never offers or sends a quotation.**
7. Check **Sales reps → International** is filled: that person is "Your contact" on every quotation.
8. Check **Prospect emails** has Pharma Asia's own mail account: quotations are sent through it.
9. Test: open a lead from outside Pakistan → Quotations → make one to your own address. Read the PDF.
   Then ask the live chat for a quotation as a foreign company (test chats 20 to 22 in
   `tools/chat-tests/chats.php`).
10. Repeat step 6 for Build Asia when you want quotations there.

## Tests

- `php tests/acceptance.php`: 1,534 pass (117 new for Stage B).
- `php tests/access-http.php`: 82 pass.
- Sample PDFs checked by eye: a stand and a sponsorship tier, with the Pharma Asia logo.
- Real GPT-6 Luna and Tavily, 12 Nov 2026: all 22 chats, 45 of 49 replies passed on the full run.
  The three quotation chats (20 to 22) passed every reply. The four misses, and what was done:
  - chat 5 (two replies): after "I'd rather not share my email" the assistant kept asking for the stand size for
    the proposal. **Fixed:** a quotation request is dropped as soon as the visitor declines, it is let go if the
    missing detail has not come after two replies, and a quotation is not offered again to someone who declined.
    Re-run: the behaviour is right (one re-run was marked as a miss only because the checker read "I won't ask
    again for your email" as asking; the checker is corrected).
  - chat 19: "Bartels" with its website reused the research chat 1 had just made for the other Bartels.
    **Fixed:** an earlier research on the same name is not reused when a website is given. Re-run: pass.
  - chat 1: "Bartels" with no website; research did not finish inside the 14-second wait (the search service
    took 6.6 s tonight instead of about 3 s), so the first reply asked about their aim instead. Not a fault of
    this update; the research finished straight after and was used from the next reply.
  13 Nov 2026, after adding the offer: chats 16, 20, 23 and 24 run again, 15 of 15 replies pass. The assistant
  offered the quotation after the price and after "what is the next step?", and "yes" sent it.
  Transcript: `docs/reference/chat-tests-2026-11-12-quotations.txt`.
