# Sales agent update — 13 November 2026: chat languages and personal chat links

Asked for by the owner on 13 November 2026:

1. a language choice on the chat card: English by default, and 8 to 10 major languages including
   Urdu (in Roman letters) and Chinese;
2. quotation PDFs stay in English only;
3. the personal "Let's discuss more — click here" link in prospect emails, quotation emails and
   WhatsApp messages, each switched on or off in the admin, working until the exhibition ends.

Built by the AI Sales Agent chat on branch `pharma-asia-2027`.

**This package contains everything since the last installed version:** the chat fixes (11 Nov), Stage A
(guided path, lead score, website answers), blocked countries, the company website box, Stage B
(quotations, and the chat offering them), and this update. Install only
`salesagent-update-2026-11-languages-links.zip`; ignore the earlier November zips.

## 1. Languages

The chat card starts with **Language**: English, 中文 (Chinese), Urdu (Roman), العربية (Arabic),
Türkçe (Turkish), Русский (Russian), Español (Spanish), Français (French), Deutsch (German),
한국어 (Korean), Italiano (Italian), 日本語 (Japanese), فارسی (Persian), ไทย (Thai) — 14 in all. The last
four were added the same day at the owner's request. Singapore needs no separate language: its companies
use English, and Chinese is on the list.

- Choosing a language changes the card and the chat window at once (labels, buttons, messages).
  Arabic and Persian are shown right to left.
- The assistant replies in that language. Roman Urdu is written in Latin letters, never Urdu script.
- Prices, stand and tier names, sizes, email addresses and links stay exactly as in the price list
  (for example "USD 6,750").
- The lines the program writes itself come in the chosen language: the quotation offer, "Quotation …
  is on its way", the sales rep's details, and the welcome from a personal link.
- The program understands, in all fourteen languages: a price question, "send me a quotation", a short
  "yes" or "no", "what is the next step?", "I want to speak to a person", discount/contract/payment
  questions, and sizes ("18平方米", "18 metrekare", "27 кв. м"…).
- If a visitor writes in another language than the one chosen, the assistant answers in the language
  they write in.
- **Your team:** the conversation page and the lead email show "Chat language: Chinese" (the transcript
  is in that language).
- **Still in English:** the greeting, the AI notice and the quick-reply buttons (they are your own
  settings), the standard messages under Standard messages, all emails, and the quotation PDF.

**The price guard is stronger** (all languages, English too). Before, it only checked prices written as
"USD 6,750" or "$6,750". Now any amount written with dollars in any of these languages ("6,750 dollars",
"6.750 dólares", "6 750 долларов", "6,750美元") must be one of your approved prices, or the reply is
replaced by the standard message. Other currencies written as words (euros, yuan, roubles…) are refused
too (also tomans, rials, baht). Rupees are allowed, because the Pakistan market article quotes medicine
sales in rupees. Prices written with Persian, Arabic or Thai digits ("۶٬۷۵۰") are checked the same way.

## 2. Personal chat links

At the end of each email or WhatsApp message:

- prospect campaign email: *"Let's discuss more — click here: …"*
- quotation email: the same, as a link
- WhatsApp first message and follow-up to a registered visitor: *"Let's discuss more here: …"*

Clicking it opens the exhibition's website with the chat already open:

- the first card is skipped;
- they are welcomed by name: *"Welcome back, Anna. Thank you for following up on quotation PA27-Q-0007.
  How can I help you further?"*, in the language they chose before, if any;
- what we know is already there: name, company, country, email, and what they discussed last time;
- the conversation is recorded on their lead (a prospect who comes to talk gets a new lead; a
  registered visitor's registration is linked to it);
- a small **"Not you? Start a new chat"** link starts an ordinary chat, for a forwarded link.

Safety:

- The link holds no name or address, only a signed reference that cannot be changed or made up.
- It is taken out of the address bar as soon as the page opens, so copying the address does not
  share it.
- It works until the last day of the exhibition (Dates & venue), then opens the normal chat. With no
  end date typed, it works for 90 days.
- Someone on the Do-not-contact list, or from a blocked country, gets the normal chat.
- A company in Pakistan still gets no prices.
- The conversation page shows "Came from: email · personal-link · quotation" (or prospect / whatsapp).

**Admin:** Agents and embed → the exhibition → **Personal chat links**: three tick boxes, all on after
the upgrade. The link opens the exhibition's **website address** (Agents and embed → Exhibition), so
the chat must be on that page.

## Files

New: `app/domain/Lang.php`, `app/domain/PersonalLink.php`.
Changed: `app/lib/Crypto.php`, `app/domain/ChatOrchestrator.php`, `ChatIntro.php`, `ChatGuide.php`,
`PromptBuilder.php`, `PricingEngine.php`, `Quotation.php`, `Qualification.php`, `PersonRequest.php`,
`SalesReps.php`, `MessageComposer.php`, `LeadBriefing.php`, `Migrations.php`, `ProspectMailer.php`,
`WhatsAppEngine.php`, `public_html/api/session.php`, `intro.php`, `chat.php`,
`public_html/widget/widget.js` (version 1.2.0), `public_html/admin/agents.php`, `conversation.php`.
Plus every file from the earlier November updates (see `docs/upgrade-2026-11-guided-selling-b.md`).

No new folders. `config/config.php` is unchanged (the link signature uses the existing encryption key).

## How to install

1. Back up the database (phpMyAdmin → Export).
2. Upload the files from `salesagent-update-2026-11-languages-links.zip` into `/home/salesagent/`,
   over the existing ones.
3. Admin → **Database upgrade** → run every step it lists. From the November updates there are four:
   `2026_11_qualification`, `2026_11_site_pages`, `2026_11_quotations`, `2026_11_personal_links`.
4. Everything else from `docs/upgrade-2026-11-guided-selling-b.md` (SQL files, Review queue, target
   countries, website reading, blocked countries, quotations switched on), if not done yet.
5. Agents and embed → Pharma Asia: check the **website address** and **Dates & venue** (last day), then
   **Personal chat links**: leave the three boxes ticked or untick what you do not want. Save.
6. Clear the browser cache once (the chat window is new: version 1.2.0).
7. Test: open the chat on the website, choose 中文 or Urdu (Roman) and ask a price. Then make a quotation
   to your own address from a foreign lead's page and click the link in the email.

## Tests

- `php tests/acceptance.php`: 1,631 pass (97 new: languages, price guard, personal links).
- `php tests/access-http.php`: 82 pass.
- In a real browser: the card in Chinese and Arabic (right to left), and a personal link opening the
  chat with the welcome and "Not you?".
- Real GPT-6 Luna and Tavily, 13 Nov 2026: chats in Chinese, Roman Urdu and Arabic, 7 of 7 replies pass;
  then Italian, Japanese, Persian and Thai, 8 of 8 (right language, right prices, the quotation offered;
  fixed on the way: the Italian word "quotazione" was not known, so the offer was made twice);
  the English quotation chats 20 and 23 still pass (7 of 7). Fixed on the way: in Chinese the AI offered
  the quotation and the program added a second offer. Transcript:
  `docs/reference/chat-tests-2026-11-13-languages.txt`.
