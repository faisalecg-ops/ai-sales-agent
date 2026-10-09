# Running the AI Sales Agent — an operating manual

This explains how to use the platform day to day. It assumes no technical
knowledge. For installing it in the first place, see the deployment guide.

---

## What this thing actually does

A chat button sits on your exhibition website. A visitor clicks it and talks
to an assistant that knows about your event. The assistant answers questions,
works out whether the visitor is a potential exhibitor, shows them the right
prices, and captures their details so your team can follow up.

The important part is what it **cannot** do. It cannot invent a price. It
cannot convert a currency. It cannot promise a discount, confirm a booking,
or make up a fact about your event. Those limits are built into the software
rather than asked of the AI, so they hold even when the AI would rather be
helpful.

---

## The idea behind the design

An AI language model is good at conversation and bad at being trusted with
numbers. So the two jobs are split:

**The AI handles the conversation.** Understanding what someone means,
answering in a natural way, keeping the thread.

**The software handles everything that matters commercially.** Which country
a visitor is in. Whether they are allowed to see pricing. What the figures
are. What the tax comes to. What the total is. Whether a record has been
approved. What gets stored.

When the assistant shows someone a price, the AI did not produce that number.
The software looked it up, calculated the tax and the total, and put the card
on screen. The AI only wrote the sentence around it.

There is a check on top of that. Before any reply is shown, the software
scans it for figures. If a number appears that is not in the approved list,
the entire reply is thrown away and replaced with a standard message. A made
up price never reaches a visitor, even if the AI produces one.

---

## Your first week

**Day one.** Approve the content in the review queue. Put the chat on the
website. Have a real conversation with it yourself, as a customer would.

**Day two to five.** Read the transcripts. Every one, at first. You will
learn more from ten real conversations than from any amount of planning.

**Watch the dashboard section headed "Questions the assistant could not
answer."** Each entry is something a visitor asked that you have no content
for. Add an FAQ for each. This is the single most valuable habit.

**Filling the knowledge base in the first place.** Use *Conversation with AI*
rather than typing it all in. Upload your brochures and price lists, answer the
questions it asks about anything that disagrees, and review what it prepared.

**End of week one.** Check leads are reaching your team's inbox and nothing
is sitting in the email queue.

---

## The pages, and when to use them

### Dashboard

Where to start each day. It shows whether the assistant is working, what came
in over the last month, and anything needing attention. Warnings appear at
the top in colour.

The three region figures matter commercially:

- **International visitors** — these see USD pricing automatically
- **Pakistan visitors** — these never see international pricing, by design
- **Country never established** — treated exactly like Pakistan, because
  showing international pricing to someone whose location is unknown is the
  one mistake that cannot be undone

If that last number is high, visitors are reaching pricing questions without
saying where they are based. Usually a content problem, not a software one.

### Leads

Everyone who left contact details. Filter by status, region or date. Download
as CSV for your CRM or for a mail-out.

The first four statuses are set automatically as a conversation progresses.
The rest — Interested, Follow-up required, Converted, Not interested, Closed
— are yours. Once you set one of those, the software stops changing the
status, because you now know more than it does.

**Possible duplicate** means another lead shares the same email or phone.
Nothing is merged automatically, because merging cannot be undone. Open the
lead and decide.

### Conversations

Every conversation, whether or not it produced a lead. Worth reading
regularly. The transcript shows what the visitor saw, including the exact
price breakdown, plus the decisions the software made along the way — when it
checked their country, when it moved the conversation on, when it fell back
to a standard message.

### Review queue

Nothing written here reaches a visitor until it is approved. This is
deliberate. A wrong price or a wrong date in an exhibition is expensive.

Two ways to approve:

1. Open an item and approve it yourself.
2. Tick a batch, create a reviewer link, and send it to whoever should check
   it. They open the link, approve or send back each item with a comment, and
   need no account or password. The link expires and can be revoked.

**Editing something already live sends it back for review — but the old
version stays live in the meantime.** So a half-finished edit never takes your
pricing offline, and never goes out unchecked.

### Conversation with AI

This is how you fill the knowledge base from documents you already have —
brochures, price lists, sponsorship charts, post-show reports — instead of
typing everything in.

**Which AI reads the documents is your choice, and it need not be the one
answering your visitors.** Reading a pile of brochures is a one-off batch job:
it uses a lot of tokens in a short burst, and nobody is waiting on it. So you
might read documents with a cheap or free-tier provider while your visitors are
served by a different one. The picker on the import page lists only the
providers you have actually set up, and the choice applies to that import
alone — it never changes the agent.

If your agent's provider has no key saved, the import quietly picks one that
does and tells you it has done so, rather than refusing to start.

**How it goes.** You add the documents and say roughly what each one is. The
assistant reads them, pulls out the facts, and records the exact wording each
one came from. Then it compares the documents against each other. Where they
agree, it just gets on with it. Where they disagree, it stops and asks you.

**The asking is the important part.** If your sponsorship chart says Gold is
USD 20,000 and an older brochure says USD 18,000, it does not average them,
pick the newer file, or quietly prefer one. It shows you both figures, tells
you which document each came from, quotes the line, and waits. Your answer is
recorded permanently as the reason the knowledge base says what it says.

It also asks when something is missing rather than merely inconsistent:

- A price with no currency next to it.
- A price in a currency the assistant is not allowed to quote.
- Prices that do not say whether tax is included — and then, once you say tax
  is added on top, what the rate is.
- A stand option described with no price at all.
- The same package described for two different editions, where it needs to
  know which one this agent is selling.

**What it will not do.** It never calculates. It does not multiply a rate by an
area, add tax, or convert a currency while reading — it only reports figures
that appear literally in the document. The arithmetic happens afterwards, in
the pricing engine, from values you have confirmed.

**Reviewing before anything is added.** When it has finished you get a list of
everything it prepared. For pricing you see the actual card a visitor would
see, with the tax and total worked out, so you are checking the real thing
rather than a description of it. Anything questionable carries a note: a figure
read with low confidence, a benefit that might belong to another tier, a price
written into prose where it does not belong. Edit anything, leave out anything,
then add the lot.

**Everything arrives unpublished.** Imported content is a draft like any other.
You can add it as drafts, or add it and send it straight to the review queue,
which is easier when one person is going to check the whole batch. Either way
no visitor sees any of it until it is approved.

**If a provider runs out mid-import.** Open "Use a different AI for this
import", pick another, and carry on. Sections already read are kept — only what
is left is read by the new one.

**If a PDF gives up nothing.** Some PDFs are scanned images with no text in
them. There is no way to read words that are not there, so it tells you which
file it could not read and why, and you can paste the text in instead — the
result is identical.

**On upload sizes.** Exhibition brochures are often 2–5 MB and many servers
cap uploads at 2 MB. The page shows the real limit for your server and how to
raise it. Pasting the text always works regardless.

### Knowledge and FAQs

What the assistant knows. If it is not here, the assistant does not know it
and will say so rather than guess.

**FAQs** are for direct questions with direct answers. When a visitor asks
something close to one, your exact wording is used.

**Knowledge** is for longer background the assistant draws on: why exhibit,
what the audience is like, what happened last year.

**Never put prices in either.** Prices belong in the pricing pages, where
they are approved, dated and calculated. A price written into an FAQ is a
price nobody is checking.

### Stand pricing

Every figure a visitor sees comes from here.

Two ways to price:

- **One fixed price** — a set amount for a set package
- **A rate per square metre** — the visitor's size, multiplied out by the
  software

With the rate option you also set the smallest bookable size and the steps it
is sold in. Set the step to 9 and sizes go 9, 18, 27, 36. Ask for 12 and the
assistant does not quote — it says the nearest sizes are 9 and 18. That is
the correct answer, and it avoids quoting something you would not honour.

**Tax.** Set the treatment to "excludes tax" and give the rate, and the card
shows three lines: the participation charge, the tax, and the total. Leave
the rate blank and the assistant says the team will confirm the tax. It never
guesses a rate.

**Who may see it.** International records must be in USD. Pakistan and
undetermined visitors never see them, whatever they ask.

### Sponsorship

The partnership tiers, with one extra control: **How many remain.**

- Leave it blank and the assistant says nothing about availability
- Enter a number and it says how many are left
- Enter zero and it stops offering that tier entirely

Use the **Offer this tier** switch to withdraw a tier without deleting it.

### Agents and embed

Settings for the event itself, plus the embed code for your website.

**Websites allowed to show the chat** is a security control, not a
convenience. The assistant refuses requests from any site not listed. Add
every address your site answers on, including `www` and non-`www`.

**How it talks** — persona, objectives and sales rules — is yours to edit.
The safety rules about prices, tax, bookings and discounts are not editable
and always apply.

**Website chat → Ask four short questions when the chat opens.** On by
default. When a visitor opens the chat, they are asked their first name,
their company, the country their company is based in, and whether they are
interested in **exhibiting** or **visiting**. They can skip it and just type
a question.

- **Visiting:** they are thanked by name. Then the assistant looks at how
  relevant they are to the exhibition, and makes at most one light
  suggestion:
  - their company fits the exhibition's sectors (**What this exhibition
    covers**) → it asks, once, whether they would also consider exhibiting
  - otherwise, if the exhibition has a conference in its approved knowledge
    (category *conference*) → it mentions the conference
  - otherwise → it simply asks whether they need help with anything else

  No sales lead is created unless they take the suggestion up. If the AI is
  unavailable, the approved wording under Standard messages → *Website chat:
  visitor chose "visiting"* is used, with the same light suggestion in fixed
  words.
- **Exhibiting:** company and country are required. The assistant replies
  straight away about exhibiting, a lead is created with their name and
  company, and pricing follows the country they chose — without any IP
  lookup. If they later say in the chat that their company is somewhere
  else, what they say wins.

Company research before the exhibitor's first reply is the next step to be
built; until then the assistant works from what the visitor told it and the
exhibition's approved knowledge.

### Standard messages

The exact words shown when the assistant cannot answer normally: when the AI
service is down, when someone asks for a price you do not publish, when a
Pakistan company asks about rates.

Worth reading through once and putting into your own voice. These are the
messages a visitor sees at the least convenient moments, and they are the
ones that keep working when nothing else does.

### Settings

API keys, email, and the housekeeping task. Keys are encrypted and never
shown again after saving.

**Choosing an AI provider.** You are not tied to one. The platform can use:

| | What it is | Notes |
|---|---|---|
| Claude (Anthropic) | The default | Strong at following the safety rules this platform depends on |
| ChatGPT (OpenAI) | Widely available | Billed per token on your OpenAI account |
| Gemini (Google) | Has a free tier | Useful for testing; check the rate limits |
| Grok (xAI) | | Same request format as OpenAI |
| DeepSeek | Inexpensive | Your conversations leave your own region |
| Mistral | Hosted in the EU | Matters if you have data-residency rules |
| OpenRouter | One key, most models | Handy for trying several without separate accounts |
| Ollama | A model on your own server | Nothing leaves your machine, no per-token cost |
| Anything else | OpenAI-compatible | Groq, Together, Azure OpenAI, LM Studio, vLLM |

Set up as many as you like. Each has its own key box, and a **Ready** or **Not
set up** badge so you can see at a glance which are usable.

**Always press Test.** Below the keys there is a test button for each provider.
It sends one tiny request and tells you exactly what came back. A wrong key or
a model name that does not exist on your account otherwise shows up only when a
visitor is waiting for an answer. The test also reports how long the reply took,
which is worth knowing before you commit to a provider.

**Choosing per agent.** The provider is set on the agent, not globally, under
*Agents and embed*. So you can run one exhibition on Claude and another on
Gemini, or move an agent to a different provider without touching anything else.
Leave the model box blank to use that provider's usual model.

**A backup provider.** Each agent can name a second provider. If the main one
fails, the backup is tried once before the assistant falls back to its offline
message. Worth setting if you would rather not have one account be a single
point of failure — pick a different company, not just a different model, or an
outage takes both.

**About Ollama.** This runs a model on hardware you control, so no conversation
text leaves your network and there is no per-token bill. Two caveats. It will
not work on ordinary shared hosting: Ollama has to be running somewhere this
server can reach, which in practice means a VPS or a machine on the same
network. And a small local model follows instructions less reliably than a large
hosted one — the platform's own guards still hold, but you should read more
transcripts before trusting it with visitors.

**On plain HTTP.** A local model is usually reached at an unencrypted
`http://localhost` address, which is fine because nothing crosses the public
internet. The platform allows that only for addresses on this machine or your
own network. An `http://` address on the public internet is refused outright
rather than sending your API key in clear text.

---

## Analytics and the activity log

### Analytics

**Day to day → Analytics** shows, for the period you choose (the last 7, 30
or 90 days, this month, the last 12 months, or any two dates):

- **Opportunities by source** — leads from the website chat, leads from
  WhatsApp, and prospects who replied to an email — with how many are
  interested and how many your team marked as booked.
- **New opportunities per day** (per week for long periods), one line per
  source. Point at the chart for exact numbers, or open *Show as a table*.
- **Prospecting** — for companies found in the period: found, qualified,
  set aside, emails written, approved and sent, replied, interested, passed
  to a person, booked. Narrow it to one campaign, country or category, and
  see the same figures by campaign, country or category with each one's
  reply rate. *Download as a spreadsheet* gives that table as a CSV file.
- **WhatsApp** — registrations collected, those who could be messaged,
  first messages and follow-ups sent, people who replied, interest in
  exhibiting and in the conference, leads, handovers, and people who asked
  to stop.
- **Website chat** — chats, interest in exhibiting, prices shown, leads,
  handovers to sales.
- **Every exhibition** side by side for the same period.

Days are the exhibition's own. "Interested" and "qualified" are the AI's
reading or what your team recorded — only *booked* means a booking. Replies
and bookings from prospecting count only once someone records them on the
company's page, so keep those up to date.

### Activity log

**Set-up → Activity log** lists everything recorded for the exhibition,
newest first. Each line says who did it: **AI** (what the assistant decided
or wrote — research, companies found, emails drafted), **Platform** (the
fixed rules — sending, limits, stops, cancellations) or the **name** of the
person on your team. Filter by who, by area, or by a word such as a company
name or email address. Nothing in it can be edited or deleted.

## Companies and the do-not-contact list

Two screens appeared with the groundwork for prospecting. Neither does
anything on its own yet; they are the records the later work writes into, and
they are useful now for anything you want to keep track of by hand.

### Companies

One record per company, whatever brought them to your attention. A company
belongs to one exhibition and is never shared with the others.

The screen keeps three things deliberately apart, and the distinction matters
more than it looks:

- **Observed** — read on a page we can link to, with the date
- **Concluded by the assistant** — worked out rather than read, and never
  stated as fact in a message to that company
- **Not known** — shown as missing rather than filled in with a guess

That separation is what stops an outbound message claiming something untrue.
When prospecting starts writing proposals, only observed facts may be stated.

**Duplicates.** Two records sharing a website are treated as one company
automatically, because a website is an identity. Two similar names are only
ever *suggested* as a possible match, because "Al Noor Trading" in Dubai and
"Al Noor Trading LLC" in Sharjah may be one firm or two, and a wrong merge
puts one company's conversation in front of another. You decide; the system
will not.

**Do not contact** blocks a company across every campaign for that exhibition,
now and in future, and adds its addresses to the list below.

### Do not contact

Checked at the moment anything is sent, not when it is written — so adding
someone here stops a message that was already approved.

Entries arrive four ways: somebody opted out, you blocked a company, a message
bounced repeatedly, or you added it by hand. Blocking a website blocks
everyone at that company without listing each address.

Entries stay even if the company record is deleted. That is deliberate:
forgetting that someone asked not to be contacted would be the worst possible
outcome, so the list is kept separately from everything it refers to.

### Company research

Before the assistant sells to a serious prospect, it looks the company up on
the web and works out how well it fits this exhibition — the method in
`docs/reference/prospecting-method-example.md`, applied to every exhibition.

**When it happens.**
- **Website chat:** when a visitor chooses *Exhibiting* on the introduction
  card, or later shows real interest in exhibiting or sponsoring. The visitor
  is answered at once; the research runs just after, and the assistant uses it
  from their next message. Ordinary visitors are never researched.
- **WhatsApp:** not for every registered visitor who replies. Only once the
  assistant sees them as a possible exhibitor or sponsor **and** they have
  asked, in their own words, about a stand, a price, a size or booking. It
  runs on the next five-minute scheduled task, and the assistant uses it from
  their following message.
- **By hand:** **Company research → Research a company**, or *Research again*
  on a company's page.
- The same company is not researched twice within 30 days.

**What you see** (open a company in **Company research**):
- a score out of 100, from six parts — product fit 25, international
  expansion 20, trade-show habit 20, Pakistan opportunity 15, company strength
  10, how reachable they are 10 — with the reason and source for each;
- a priority: *strategic* (90+), *high* (80+), *qualified* (70+), *medium*
  (60+), *low*, or *rejected* when they do not fit the exhibition at all;
- why they might exhibit, which of your approved packages suits them, who to
  contact, likely objections with answers, and every page used.

**The team is told.** When research of someone the assistant is talking to
finishes and the company scores *qualified* or better, the sales team gets an
email headed *"exhibitor researched"*, with the score, who to approach, what
was found and the conversation so far. On WhatsApp this is often the first
the team hears of them, so a lead is created from their registration details.
A poor fit is researched but nobody is emailed. Any later lead or handover
email for that person carries the research too. The emails follow the
exhibition's *notify on new lead* setting and go to the local or
international address as usual.

**What it will not do — by design.**
- A fact is kept only if it was read on a page it can point to. Anything
  worked out is shown as an *inference* and the assistant never states it as
  fact. What could not be checked is listed as *not verified*.
- An email address, phone number or person's name is kept only if it appears,
  whole, on one of the pages found. Nothing is guessed.
- It only recommends packages you have approved, for the company's region.
  It never changes a price and never promises results.
- A company record is created only once its own website has been found —
  not from a LinkedIn page, a directory or another exhibition's list.

**Cost.** Each company uses at most the *Searches per company* set under
Settings (4 by default, about 4–8 Tavily credits). Research stops for the
month at the limit you set (900 by default, inside Tavily's free 1,000). The
Settings page shows how much has been used this month, including tests.

**If a research says *failed*,** the reason is shown in plain words (no key,
the month's limit, the service unreachable). Press *Try again* once it is
sorted. A research cut off part-way (for example by the server) is marked
failed automatically after 10 minutes.

**Switching service later.** Settings lists the other services — Claude's
and ChatGPT's own web search, Gemini with Google Search, Exa, Perplexity,
Brave and Serper — with prices and trade-offs. Only Tavily is connected
today; adding another is a small technical job that changes nothing else.

### Prospecting campaigns

**Prospecting → Campaigns** finds companies that could exhibit, researches
each one, writes a first email from what was verified, and — once a person
approves it — sends it from the exhibition's own address.

**Before the first campaign**, for each exhibition, under **Agents and embed →
Edit → Prospect emails**:
- **From name / From email** — e.g. *Build Asia Secretariat*,
  info@buildasia.net. Pharma Asia has its own. An exhibition never sends
  as another.
- **Mail server** — the exhibition's own (in cPanel: *Email Accounts →
  Connect Devices*, "Outgoing Server"), port 587 with STARTTLS or 465 with
  SSL, the full email address as user name, and its password. The password
  is stored encrypted and never shown again; if you change the server, it
  is removed and must be typed again.
- **Organiser's postal address** — shown at the foot of every email.
- **Pace** — emails per day, minutes between emails, companies researched
  per day, minutes between researches.
- Press **Send a test email** to check it arrives (look in spam too).

**A campaign** says what to look for: countries and product categories,
each in order of importance (both optional — leave countries empty to
search anywhere, categories empty to use what the exhibition covers), the
kinds of company, how many to find, dates, and whether a person approves
every email (recommended) or checked emails go automatically. Nothing
happens until you press **Start**.

**What happens, every few minutes, within the pace you set:**
1. **Find** — one web search at a time, most important country and category
   first. The AI lists the companies in the results; the platform keeps only
   those whose own website appears in the results and whose name is on the
   page — never a directory, marketplace, news site, or another exhibition.
   A company already on file is not added twice; one that looks like an
   existing record is flagged for you to check.
2. **Research** — the same research as the chat, scored out of 100.
3. **Sort** — *not relevant*, *weak fit* (you decide), *qualified — no email
   found* (add one you know is right; nothing is guessed), or *qualified*.
   An email address is used only if it was on the company's own pages and
   at the company's own website.
4. **Write** — the AI writes from the facts seen on their pages and what the
   exhibition has approved about itself. The platform refuses a draft with
   a price, a figure nobody approved, flattery, pressure, a pretended earlier
   meeting, or a link to anywhere but the exhibition's website — it is
   rewritten, and if that fails a plain, true email is used instead.
5. **You approve** — the menu shows how many wait. Open one to see the
   research and sources, then edit, **Rewrite with the AI**, **Approve**,
   **Reject for this campaign**, or **Do not contact, ever**.
6. **Send** — one at a time, never sooner than the interval, never more than
   the day's number, only while the campaign is running, and never to anyone
   who has since unsubscribed or been blocked.

**Every email ends with**, added by the platform: the sender, the exhibition
and its website, your postal address, why the company was contacted, and an
unsubscribe link. Mail programs' own *Unsubscribe* button works too. Replies
go to the exhibition's mailbox, not here — when someone replies, open their
company and record what they said (*replied*, *interested*, *follow-up
required*, *not interested*, *booked*). If someone replies "unsubscribe",
press **Do not contact, ever**.

**Never twice.** A company gets one first email per exhibition, whichever
campaign found it, and an address is never written to twice. If the mail
server stops answering after taking an email, it is marked *not known
whether it arrived* and never sent again — check with the company another
way.

**Stopping.** **Pause** or **Finish** stops a campaign's emails at once; the
emergency stop stops everything. Switching a campaign to "a person approves"
sends anything it had approved by itself back to wait for you.

### Database upgrade

Under Set-up. When a new version needs a change to the database, a number
appears next to it. Open it, read what it will do, and press the button.

It asks the database what it already has and applies only what is missing, so
it is safe to run twice, and safe to run again if it stopped halfway. It never
changes or deletes anything that exists. Take a backup first anyway.

---

## Visitor registrations and WhatsApp

Setting these up for the first time is covered step by step in
`docs/upgrade-2026-09-whatsapp.md`. This section is about using them.

### The idea

Every message the assistant sends, on any channel, makes the same four-step
argument: what this company makes, whether that has a market in Pakistan,
what exhibiting would do for them, and what to do next. It says only what it
can support — facts it has read, figures from your approved pricing — and
when it knows little, it says less rather than inventing.

Before any of that, it asks whether the person belongs at this exhibition at
all. A procurement manager at a textile mill is a visitor to Health Asia, not
an exhibitor, and is sent a general welcome rather than a sales pitch.

### Find a person

**Visitors → Find a person** searches everyone who registered, chatted on the
website or became a lead — by name, company, email, phone (typed any way,
such as 0333 1112233) or lead reference. Opening someone shows everything
about them on one page:

- **Who they are** — company, job, email, mobile, country, and every
  interest they showed (exhibiting, sponsorship, the conference, visiting),
  not just one label.
- **Where things stand** — their registration, website chats, WhatsApp
  status, lead and status, and company research score.
- A red bar if they are on the do-not-contact list, and an amber one if a
  person from the team is needed.
- **Everything that happened**, in date order across every channel, each
  entry marked **Visitor**, **AI**, **Team** (with the name) or **System**.
  The links above it narrow it to one channel.
- **The records behind this page** — and what linked them.

Records are put together only by the same mobile number, the same personal
email address, an explicit link, or the same browser returning to the chat.
Shared company mailboxes such as info@ or sales@ are never used, because
they would mix colleagues together. Nothing is merged or changed: the page
only reads.

The same page opens from **Everything about this person** on a lead or a
conversation, from a name in the registrations list, from a number in the
WhatsApp list, and from a company research.

### Registrations

Under **Visitors → Registrations**. The assistant reads the shared registration
table every few minutes and keeps a copy of the people who registered for this
exhibition. It never writes to the registration system.

The breakdown shows everyone collected and why each person can or cannot be
messaged:

| Group | Meaning |
|---|---|
| Can be messaged | Ticked the WhatsApp box, has a working number, not blocked |
| First message written | Their first message exists — waiting, sent, or stopped |
| Message sent | It went |
| No consent recorded | Did not tick the box. Never messaged |
| Registered at the venue | Registered on site. Never messaged |
| No usable number | The number could not be read |
| On the do-not-contact list | Their number, address or company is blocked |
| Opted out | They asked to stop |

**Numbers that could not be used** lists why — too short, letters in it, a
landline, and so on. Many of these were damaged when the data passed through
Excel. **Read these numbers again** tries each one a second time after any
improvement to the number reader; it never reopens someone who opted out.

### WhatsApp

Under **Visitors → WhatsApp**. The number in the menu is how many messages
need you: waiting for approval, or failed.

**Waiting for your approval.** First messages the assistant has written. Open
one to read it in full. You can change the wording, approve it, stop it, or
block the number for good. **Approve all** approves everything on the list.
Approving does not skip the sending hours or the daily limit — it means *this
is fit to send*, not *send it this minute*.

A message marked **plain version** is one where the assistant's own draft
broke one of its rules — usually a figure it could not support — and a plain,
safe message was used instead. Worth a read before approving.

**Waiting to go.** Approved, and due at the next run.

**Could not be sent.** Each shows why. **Try again** puts it back in the queue.
A message marked *sending was interrupted* may or may not have arrived — it
is never sent again automatically, because a person receiving it twice is
worse than not at all. Check before trying again.

**Stopped.** Stopped by you, or because the person opted out. **Bring it back
for approval** returns it to the approval list, never straight to sending. A
number that has opted out cannot be brought back.

**Replies received.** Everything people wrote back, and what happened to it.
The assistant answers replies at once, at any hour, using exactly the same
knowledge and prices as the website. A reply is never held for approval and
never counts against the daily limit — someone is waiting for it.

Some replies are recorded but deliberately not answered, and the reason is
shown next to each: WhatsApp was switched off for the exhibition, too many
arrived in the same minute, or the person is on the do-not-contact list. In
that last case a person should decide whether to answer by hand.

### Follow-ups

When someone goes quiet, the assistant can follow up once (or up to three
times, if you choose) — but only in two situations:

- **A likely exhibitor never answered the first message.** They get one
  gentle nudge, never more.
- **Someone was talking about exhibiting or sponsoring and stopped
  replying.** They get a short message picking up where the conversation
  left off.

**Ordinary visitors are never chased.** They were thanked for registering;
a second unanswered message to a visitor is noise.

Set it per exhibition under **Agents and embed → WhatsApp → Follow-ups**:
how many in a row without an answer (none, one, two or three), how many days
of silence first (3 by default), and the **last day of the exhibition** —
nothing is followed up after it. When they write back, the count starts
again; nobody ever gets more than four in all.

A follow-up is never sent if, by the time it would go, they have written
back, said stop, been taken over by a person, or the exhibition has ended.
It counts towards the daily limit and keeps to the sending hours, and if
first messages wait for your approval, so do follow-ups.

The assistant writes each one from the conversation itself, under the same
rules as first messages: no prices, no invented figures, no pressure. If it
judges a follow-up would not be welcome — they said *not this year*, or said
goodbye — it writes nothing, and the list shows *Not sent* with its reason.
If the AI is unavailable, a plain standard follow-up is used (you can edit
it under **Standard messages**).

Every first message and follow-up now also appears in the conversation, so
the team sees the whole exchange and the assistant knows what it last said.

### When a person takes over

The assistant steps aside and hands the conversation to your team when the
visitor:

- asks for a person, or asks to be called
- complains, or is upset or abusive
- asks about a contract, invoice, payment, refund or discount — things only
  the team can decide
- asks something the assistant cannot answer reliably (the AI is unavailable,
  or the conversation has gone on too long)

When that happens, the visitor gets one short message saying a member of the
team will reply personally. You can change the wording under **Standard
messages → WhatsApp: a person is taking over**. The team is emailed with the
whole conversation, and a lead is created with their name, company and number
from the registration.

From then on the assistant stays silent for that person, whatever they send.
Their messages still appear in the conversation.

These conversations are listed at the top of **Visitors → WhatsApp**, under
**With a person**, with who they are, their full number, why it was handed
over, and their last message. From there you can:

- **Send reply** — sent straight away from the exhibition's number. It is
  written by you, so the assistant does not check it; take care with prices
  and dates. You can also simply reply from the WhatsApp phone itself.
- **The whole conversation** — everything said, including your replies,
  marked with your name.
- **Hand back to the assistant** — when you are done, so it answers their
  next message.

**The visitor is also given your sales rep's details** — name, designation,
mobile, WhatsApp, email and WeChat, whichever you have filled in. A company in
Pakistan is given the local rep, a company elsewhere the international rep.
If their country is not known yet, they are given both, each labelled.

The same happens in the **website chat**. When a visitor asks for a person,
asks for a discount, complains, raises a contract or payment, or the
assistant cannot answer, the rep details are added to the reply. On the
website the conversation then carries on as normal.

Set the two reps on each exhibition's page, under **Agents and embed → Edit →
Sales reps**. Numbers must include the country code, so visitors abroad can
dial them. A rep left empty is never given to anyone, and a Pakistani company
is never given the international rep as a stand-in.

A sales signal on its own, such as someone asking for a price or saying they
want to book, does **not** silence the assistant. The team is told, and the
conversation carries on.

### The emergency stop

On **Visitors → WhatsApp**, **Stop all outgoing messages** halts everything
sent to visitors, on every exhibition, at once. Use it for anything that
looks wrong: an unexpected message, a complaint, or a warning from WhatsApp.

While it is on:

- nothing is sent, including replies typed by the team
- incoming messages are still recorded, and requests to stop are still obeyed
- nothing waiting is lost
- a red banner shows on every admin page, saying who stopped it, when and why

Press **Resume sending** on the banner to start again. Waiting messages go
out within the next few minutes, within each exhibition's hours and limits.
Automatic replies more than 12 hours old are not sent, because the
conversation has moved on by then.

### Stopping

Anyone who replies *stop*, *unsubscribe*, *remove me*, *don't message me*,
*band karo*, *بند کرو*, or a short *not interested* is taken off at once:
their number goes on the do-not-contact list, anything waiting for them is
stopped, and nothing is sent to them again. This works even while WhatsApp is
switched off.

The assistant reads these as words, not letters. *Block B is where our stand
is* and *no more than two booths* are people still talking to you, and are
answered normally.

### The limits, and why they are there

The number is linked through TextMeBot, which is not Meta's official API. If
enough people report messages as spam, WhatsApp blocks the number. So:

- **Only people who ticked the box.** No exceptions, and none can be made
  from the screens.
- **A daily limit on first messages.** Start at 20 and raise it slowly over
  weeks. A new number that suddenly sends a lot looks like the thing WhatsApp
  blocks.
- **Sending hours**, in the exhibition's own timezone. Nobody is woken up.
- **Spaced out.** First messages go several seconds apart, irregularly, a
  few every five minutes — not in a burst.
- **One first message per number, ever.** Someone who registers twice is
  written to once.

**If the number is ever blocked,** press **Stop all outgoing messages**
straight away, then switch WhatsApp off for that exhibition under Agents and
embed. Nothing more is sent; replies that arrive are still
recorded and requests to stop are still obeyed.

---

## Adding another exhibition (Health Asia, Pharma Asia)

The platform is built for several exhibitions on one installation. Each one is
an **agent**, and agents share nothing: separate knowledge, separate prices,
separate leads, separate conversations. An exhibitor asking the Health Asia
assistant about Build Asia stand rates gets nothing, because the Health Asia
assistant cannot see them. That separation is enforced in the database, not by
careful wording in the prompt.

**Step 1 — create it.** Agents → **New agent** (top right). Fill in:

- **Exhibition name** — `Health Asia`. Shown to nobody; it is how you tell them
  apart in the panel.
- **Short code** — `healthasia`. Lowercase letters, numbers and hyphens. This
  goes into the embed code, so choose it once and leave it alone: changing it
  later stops the chat appearing on any site where the code is already
  installed.
- **Websites allowed to show the chat** — `www.healthasia.net`, one per line.
  Requests from any other site are refused, so nobody can put your assistant on
  their own website. Add the plain and `www.` versions if both are in use.

Everything else arrives already filled in — how the assistant talks, what it is
trying to achieve, the disclosure wording, the AI provider and the lead
routing, all copied from your existing event. Read it through and change what
does not fit. A medical or pharmaceutical audience usually needs different
wording under *What it is trying to achieve* than a construction one.

**Step 2 — give it content.** A new agent knows nothing, so it will politely
say it cannot help. Switch to it using the dropdown at the top of the page,
then open **Conversation with AI** and upload that event's brochure, floor plan
and rate card. It reads them, asks you about anything that contradicts itself
or is missing, and proposes the prices, knowledge and FAQs for that event only.

**Step 3 — approve it.** The proposals land in **Review** as pending. Nothing
reaches a visitor until you approve it. Read the prices especially closely:
after approval the assistant will quote them to real customers.

**Step 4 — set the tax.** Under Pricing, check the sales tax rate and label on
each package. Sindh and Punjab differ, and the platform never guesses a tax
rate — it only shows one you have entered.

**Step 5 — install it.** Agents → Edit that agent. The embed code is at the
top:

```
<script src="https://salesagent.ecgateway.net/widget/widget.js" data-agent="healthasia"></script>
```

Paste it once, just before `</body>`, on the Health Asia website. Note the
`data-agent` value — that is the only thing that differs between events, and it
is what decides which assistant answers.

**Switching between events.** Once a second agent exists, a dropdown appears at
the top of every page. Everything you then look at — leads, conversations,
prices, review queue — belongs to the event named in that dropdown. It is worth
glancing at before approving a price.

**A shared AI key is fine.** All your agents can use one Anthropic or OpenAI
key; it is set once under Settings and applies to the whole installation.
**WhatsApp is the exception:** each exhibition's number has its own TextMeBot
key, entered next to the number on that exhibition's page. Usage
limits, however, are per agent, so a busy event cannot exhaust another's
budget.

**Turning one off.** Set its status to Disabled. The chat stops appearing
immediately, everywhere, while the leads and conversations already collected
stay exactly where they are. Use this between editions rather than deleting
anything.

---

## Things that will happen, and what they mean

### Changing AI provider

Set the key under Settings, press Test, then change the provider on the agent.
Nothing else needs touching — your knowledge, pricing and leads are unaffected,
because the provider only supplies the conversation.

If the new provider starts failing, the dashboard says so and the assistant
falls back to its approved offline message, so enquiries keep being captured
while you sort it out.

### A cheaper model for background work

Under **Agents and embed → Edit → Technical**, *Background work* can use a
cheaper model than the live chat — for example a "mini", "haiku" or
"flash" model. It is used for company research, finding prospects, first
WhatsApp messages, follow-ups and prospect emails: work nobody is waiting
for, and all of it checked by the platform afterwards. Live website chat and
WhatsApp replies always use the main model. Leave it blank to use the main
model for everything. If the background provider's key is not saved, the
main model is used instead, so the work never stops.

### When the AI is busy

AI services are sometimes overloaded for a few seconds or minutes. That is
treated as *busy*, not *broken*:

- **Website chat** — the platform waits and tries again for up to about 25
  seconds. After a few seconds the visitor sees "Still working on your
  answer…". If it is still busy, the chat says the answer is on its way and
  tries again by itself every few seconds, up to five times, then offers a
  *Try again* button. The question is never stored twice, and the visitor is
  not sent to the contact form.
- **WhatsApp** — the message waits and is answered by the five-minute
  scheduled task a few minutes later. If the AI is still busy after about
  half an hour, a person takes over as usual.
- Being busy never switches the assistant off. Only real failures — a wrong
  key, the account out of credit, the service down — count towards the
  automatic offline switch described below.

### "Our assistant is temporarily offline"

The AI provider stopped responding, so the software took the assistant
offline by itself. Visitors get a contact form instead, so enquiries are
still captured.

It recovers on its own. The next visitor after a short cooldown becomes a
quiet health check, and if the provider is back the assistant returns to
normal with no action from you.

Check the dashboard for the cause. Usually an expired API key or an account
out of credit.

### A visitor said something the assistant got wrong

Read the transcript. Then either add an FAQ with the right answer, or adjust
the sales rules on the agent page. Do not try to fix it by arguing with the
AI — fix the content it reads from.

### Someone asked for a price and got none

Check the transcript for their region. If it says Pakistan or not
established, that is working as intended. If it says international and they
still got nothing, check that the relevant price record is Live rather than
Draft.

### The same question keeps appearing in the "could not answer" list

Write one FAQ. That is exactly what the list is for.

---

## What the notification email contains

When a lead is captured, your team gets a full briefing rather than a one
line alert:

- A suggested next step, worked out from what actually happened in the
  conversation
- The contact details and what is known about the company
- Which packages were discussed, with the payable total
- The buying signals that were detected
- **Any question the assistant could not answer** — usually the first thing
  to address when you call
- The complete conversation, with a timestamp on every message

The suggestion is produced by the software, not the AI, so it cannot invent a
commitment for you to honour.

Local and international enquiries can go to different addresses. The subject
line says which.

---

## Privacy and data

Visitors are told they are talking to an AI, in a line at the top of the
panel that is always visible. You set that wording.

Conversations are stored so your team can follow up. IP addresses are kept
for a limited period and then automatically cleared. Old conversations are
deleted after their retention period. All of this is handled by the hourly
housekeeping task without you doing anything.

If someone asks you to delete their data, find the lead, note the reference,
and delete it. The conversations attached to it can be deleted from the
conversation page.

---

## Getting the most out of it

**Read transcripts weekly.** Not the summaries. The actual conversations.

**Treat the "could not answer" list as a to-do list.** Every entry is a real
question from a real potential customer that you failed to answer.

**Keep pricing records current.** Set the valid-until date when a rate has a
deadline, and the record retires itself.

**Do not over-instruct the AI.** If it keeps getting something wrong, the
answer is almost always missing or unclear content, not more rules.

**Check the dashboard after any content change.** The warnings at the top are
the quickest way to spot that something did not go live.
