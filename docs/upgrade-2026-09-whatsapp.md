# Upgrade: visitor registrations and WhatsApp

This upgrade connects the assistant to your visitor registrations and lets it
talk to registered visitors on WhatsApp — the same assistant, the same prices
and the same rules as the website chat.

It also adds **company research** (Part 11): serious exhibitors are looked up
on the web before the assistant pitches to them — and **prospecting
campaigns** (Part 12), which find companies that could exhibit, research
them, and email them from each exhibition's own address once you approve. And it includes every fix made
since the last full package: the exhibition
switcher, the chat not appearing on some pages, the chat being refused on
other websites, and the companies and do-not-contact screens.

Allow about an hour. Nothing is sent to any visitor until you reach the last
part and switch it on yourself.

---

## What will happen once it is running

1. Someone registers on the exhibition's registration form and ticks the
   WhatsApp box.
2. The assistant collects that registration a few minutes later.
3. It reads their job title and company, decides whether they are a possible
   exhibitor or a visitor, and writes them a short, personal first message.
4. **The message waits for you.** You read it under **WhatsApp**, change it
   if you want, and approve it.
5. It is sent from the exhibition's own WhatsApp number, within the hours you
   chose and the daily limit you set.
6. If they reply, the assistant answers straight away, day or night.
7. If they say stop, in English or Urdu, nothing is ever sent to them again.

People who registered at the venue, people who did not tick the box, and
anyone on the do-not-contact list are never messaged.

---

## Part 1 — Back up (5 minutes)

**1.1** In cPanel for **salesagent.ecgateway.net**, open **phpMyAdmin**, click
the platform's database on the left, then **Export**, then **Go**. Keep the
file it downloads.

**1.2** Open **File Manager**, right-click the `salesagent` folder and choose
**Compress**, then **Zip Archive**. Download the zip it makes.

If anything goes wrong later, these two files put everything back exactly as
it was.

---

## Part 2 — Upload the new files (10 minutes)

**2.1** In File Manager, go **into** the `salesagent` folder.

**2.2** Click **Upload** and upload `ai-sales-agent.zip`.

**2.3** Right-click the zip and choose **Extract**. When it asks, allow it to
**overwrite** existing files.

**2.4** Check that these two things are still there and were **not**
replaced — the package does not contain them, so they should be untouched:

- `config/config.php` — your settings and passwords
- `storage/` — logs and uploaded brochures

**2.5** The package contains the installer folder, `public_html/install`,
which you deleted after the first installation. It refuses to run once an
administrator exists, so it is harmless, but delete it again anyway: in File
Manager, open `public_html`, right-click `install`, and choose **Delete**.

**2.6** Delete the zip.

---

## Part 3 — Upgrade the database (2 minutes)

**3.1** Sign in to the admin panel at `https://salesagent.ecgateway.net/admin/`.

**3.2** In the menu, **Set-up → Database upgrade** will show a number.

**3.3** Open it. It lists each change in plain words. Press the button.

It only adds what is missing and never changes or deletes what you have, so it
is safe to press twice. When it finishes, the number disappears.

**3.4** Quick check that nothing broke: open the exhibition website in a
private browser window and open the chat.

**The chat now starts with four short questions** — first name, company,
country, and exhibiting or visiting — which visitors can skip. This is live on
the website as soon as the files are uploaded. Try both answers:
*Visiting* should thank you by name; *Exhibiting* should reply about
exhibiting. If you would rather not use it yet, switch it off under **Agents
and embed → Edit → Website chat**.

---

## Part 4 — Connect the registrations (15 minutes)

The assistant reads the registration table. It never writes to it, so it
cannot damage the registration system.

### 4a. Make a read-only database user

This is done in the cPanel of **forms.ecgexhibitions.com**, the account that
owns the registrations.

**4.1** Open **MySQL® Databases**.

**4.2** Under **Add New User**, create a user — for example `reader` — with a
long password. cPanel will show the full name, such as `ecgexhib_reader`.
Write down the full name and the password.

**4.3** Under **Add User To Database**, choose that user and the database
`ecgexhibitions_forms`, then press **Add**.

**4.4** On the privileges screen, tick **SELECT only**. Nothing else. Press
**Make Changes**.

Both accounts are on the same server, so the platform can use this user
directly.

### 4b. Tell the platform about it

**4.5** In the admin panel, open **Visitors → Registrations**.

**4.6** Fill in:

| Box | What to enter |
|---|---|
| Database host | `localhost` |
| Database name | `ecgexhibitions_forms` |
| Read-only username | the full name from 4.2, e.g. `ecgexhib_reader` |
| Password | the password from 4.2 |
| Table name | `registrations` |

Press **Save**, then **Test the connection**. It should say how many
registrations it can see, and that *the WhatsApp consent column was found*.
If it says the column was not found, ask the developer to check that
`whatsapp_optin` and `whatsapp_optin_at` exist in the table.

The test also lists the event numbers it found, with their city and how many
registrations each has.

**4.7** Under **Which events belong to Build Asia**, enter the event number(s)
for this exhibition in **Event numbers**, and save. For another exhibition,
change exhibition with the switcher at the top of the page first.

**4.8** Choose where to start:

- **Start from now** — recommended. Only people who register from today are
  collected. Older registrations did not agree to WhatsApp, so there is
  nothing to gain from them.
- **Collect everything from the beginning** — reads the whole history, over
  several runs. Use
  this only if you want the older registrations listed for reference; they
  still will not be messaged unless they ticked the box.

**4.9** Press **Collect now** to see it work. The breakdown underneath shows
how many people are in each group and why.

---

## Part 5 — The exhibition's WhatsApp settings (5 minutes)

Open **Set-up → Agents and embed**, click **Edit** on the exhibition, and scroll
to **WhatsApp**.

| Setting | Recommended to start |
|---|---|
| Number messages are sent from | `923008618200` for Build Asia |
| TextMeBot API key for this number | leave empty for now — added in Part 6 |
| Send WhatsApp messages for this exhibition | **leave off for now** — switched on in Part 9 |
| Let me read each first message and follow-up before it goes | **on** |
| Wait before the first message | 10 minutes |
| Most messages per day | **20** for the first two weeks, then raise slowly |
| Do not send before / after | 10:00 and 20:00 |
| Follow-ups without an answer | One |
| Wait before following up | 3 days |
| Last day of the exhibition | the show's final day — no follow-ups after it |

Further down the same page, fill in **Sales reps**: the local rep (companies
in Pakistan) and the international rep (companies elsewhere), with name,
designation, email, mobile, WhatsApp and WeChat. Numbers need the country
code. Visitors are given these details whenever they need a person — on
WhatsApp and in the website chat.

Also check **What this exhibition covers** further up the same page. That
list is how the assistant knows a cement supplier belongs at Build Asia but
not at Health Asia. Without it, everyone gets a general welcome and nobody is
invited to exhibit.

Save.

---

## Part 6 — Connect TextMeBot (10 minutes)

TextMeBot is the service that puts the messages on WhatsApp.

> **Please read this once.** TextMeBot is not Meta's official WhatsApp
> Business API. It works by linking a real WhatsApp account, the way WhatsApp
> Web does. That makes it cheap and quick, and it also means WhatsApp can
> block the number if people report the messages as spam. Everything in this
> platform is set up to avoid that — consent only, low daily limits, spaced-out
> sending, approval first, and stopping instantly when asked — but the risk is
> not zero. If Build Asia's main number is precious, consider a separate number
> for this.

**6.1** Create an account at textmebot.com.

**6.2** Follow their instructions to link the WhatsApp number: on the phone
that has the number, open WhatsApp → **Linked devices** → **Link a device**,
and scan the code TextMeBot shows you.

**6.3** Copy the **API key** TextMeBot gives you. **Every WhatsApp number has
its own key** — the key belongs to the phone you just linked.

**6.4** In the admin panel, open **Settings**, scroll to **WhatsApp**, choose
**Messaging service: TextMeBot**, and press **Save settings** at the bottom.
This is done once, for the whole platform.

**6.5** Open **Set-up → Agents and embed → Edit** on the exhibition, and paste
the key into **TextMeBot API key for this number**, directly under the
number. Save. The key is stored encrypted and shows only as dots from then on.

For another exhibition with its own number, link that phone in TextMeBot,
and paste *its* key under *that* exhibition. A key is never shared between
numbers: if an exhibition has no key of its own, it sends nothing rather than
borrowing another's. If you ever change an exhibition's number, its old key is
removed automatically, because it belongs to the old phone.

---

## Part 7 — Let replies come back (10 minutes)

Without this, messages go out but answers go nowhere.

**7.1** Still on **Settings**, find **Where WhatsApp replies come back to**
and press **Make the address**.

**7.2** A webhook address appears next to each exhibition. Use the one for
the exhibition whose number you linked in Part 6. It looks like:

```
https://salesagent.ecgateway.net/api/whatsapp.php?agent=buildasia&token=...
```

Copy it exactly. Treat it like a password: anyone who has it can send
messages into the assistant.

**7.3** Give it to TextMeBot, using **that number's** API key. Either:

- open `https://api.textmebot.com/webhook.php?apikey=YOUR_API_KEY` in a browser
  and paste the address there, or
- email support@textmebot.com, give the number's API key, and ask them to set
  the webhook to that address.

Each number gets its own exhibition's address — Build Asia's number gets the
address ending `agent=buildasia…`, Health Asia's number the one for Health
Asia. That is how a reply reaches the right exhibition's assistant.

**7.4** If you ever think the address has leaked, press **Make a new address**
and give TextMeBot the new one. The old one stops working at once.

---

## Part 8 — The five-minute scheduled task (5 minutes)

**8.1** In cPanel for **salesagent.ecgateway.net**, open **Cron Jobs**.

**8.2** Under *Common Settings*, choose **Once Per Five Minutes**.

**8.3** In the Command box, enter the line shown under **Where WhatsApp
replies come back to** on the Settings page. It looks like:

```
php /home/youruser/salesagent/cron/whatsapp.php >/dev/null 2>&1
```

**8.4** Click **Add New Cron Job**.

Keep the hourly task you already have. They do not interfere with each other.

---

## Part 9 — Test with your own phone before anyone else

Do this before switching on for real.

**9.1** Switch WhatsApp **on** for the exhibition (Part 5), keeping
approval on.

**9.2** From your **personal** phone, send a WhatsApp message to the
exhibition's number: *What are the dates for Build Asia?*

Within a few seconds you should get an answer. In the admin panel,
**Visitors → WhatsApp → Replies received** shows your message marked
*Answered*.

**If nothing arrives,** see *If something goes wrong* below — it is almost
always the firewall or the webhook address.

**9.3** Now send *Stop*. You should get no answer. Your message shows as
*Opted out*, and your number appears under **Set-up → Do not contact**.

**9.4** Remove your own number from **Do not contact**, so you can keep
testing.

**9.4a** Send *Can someone call me?*. You should get one short message saying
a member of the team will reply. The conversation appears under **With a
person** on the WhatsApp screen, and the sales email address receives the
conversation. Reply to yourself from the screen, then press **Hand back to the
assistant**.

**9.5** Register yourself on the exhibition's registration form, tick the
WhatsApp box, and wait ten minutes (or press **Write to anyone new** on the
WhatsApp screen after ten minutes). Your first message appears under
**Waiting for your approval**. Open it, read it, approve it, and press
**Send what is due**. It should arrive on your phone.

If all of these worked, it is ready.

---

## Part 10 — Going live

Leave approval **on** for at least the first week. Each morning, open
**Visitors → WhatsApp**, read what is waiting, change anything that is not
right, and approve. The menu shows a number whenever something is waiting.

After two weeks with no problems, raise the daily limit gradually — 20, then
30, then 40. A new number that suddenly sends a lot is what WhatsApp blocks.

Replies are never held and never limited: when someone writes to you, they
are answered at once, at any hour.

---

## Part 11 — Company research with Tavily (10 minutes, optional)

Without this, everything works as before; exhibitors are just not researched.

1. Go to **https://app.tavily.com** and sign up (Google sign-in is fine).
   The free plan gives 1,000 credits a month — enough for well over 100
   companies. No card is needed to start.
2. On the Tavily dashboard, copy your **API key** (it starts with `tvly-`).
3. In the platform, open **Settings → Company research**:
   - **Research service:** Tavily
   - **API key:** paste it
   - leave *Searches per company* at 4 and *Most search credits per month*
     at 900
   - **Save settings**
4. Press **Test the research service**. You should see *"The research service
   is working"*. (This uses one credit.)
5. Try it: **Company research → Research a company**, type a company you know,
   and press **Research**. Refresh after a minute or two to see the result.

Once it is on, it also works on WhatsApp: when a registered visitor asks
about a stand, a price or booking, their company is researched within a few
minutes, and if it is a good fit the sales team is emailed.

If you later outgrow the free plan, Tavily charges $8 per 1,000 credits; raise
the monthly limit on the Settings page to match what you buy.

---

## Part 12 — Prospect emails (15 minutes per exhibition)

This sets up how each exhibition emails companies found by prospecting.
Nothing is sent until you start a campaign and approve its emails.

1. In **cPanel** for the exhibition's own domain (e.g. buildasia.net), open
   **Email Accounts**, find info@buildasia.net (create it if needed) and
   press **Connect Devices**. Note the **Outgoing Server** name and the
   **SMTP port** (587 or 465).
   Sending through the domain's own mail server matters: that server is the
   one the domain's SPF and DKIM records vouch for, so emails are far less
   likely to land in spam than if they came from the platform's server.
2. In the platform: **Agents and embed → Edit (Build Asia) → Prospect emails**:
   - From name: `Build Asia Secretariat`
   - From email: `info@buildasia.net`
   - Mail server: the Outgoing Server name · Port: 587 · Encryption: STARTTLS
     (or 465 with SSL)
   - User name: `info@buildasia.net` · Password: that mailbox's password
   - Organiser's postal address
   - Pace: start with **20 emails a day, 15 minutes apart**, and 20
     researches a day, 5 minutes apart
   - **Save agent**, then **Send a test email** to yourself.
3. Do the same for Pharma Asia with *Pharma Asia Secretariat*,
   info@pharmaasia.com.pk and its own mail server.
4. **Prospecting → Campaigns → New campaign.** Add countries and categories
   in order of importance, keep "A person approves every email", save, and
   press **Start**. Within a few minutes companies appear; drafts follow as
   they are researched.

The five-minute scheduled task from Part 8 does all of this; there is
nothing more to add in cPanel.

## Part 13 — A cheaper model for background work (2 minutes, optional)

Research, follow-ups and prospect email drafts can use a cheaper AI model,
while the website chat and WhatsApp replies keep the better one.

1. **Agents and embed → Edit → AI and limits.** Under *Background work*,
   choose the provider and type a cheaper model name (for example a "mini",
   "haiku" or "flash" model from the same provider).
2. **Save agent.** Leave it empty to keep using the main model for everything.

When the AI service is busy, the chat now waits and tries again by itself,
and WhatsApp replies are answered a few minutes later — nobody is sent to
the contact form just because the AI was busy. There is nothing to set up.

## The user manual

`docs/user-manual.html` is a complete beginner's guide with screenshots.
Open it in any browser — it works offline, on a phone, and prints cleanly.

---

## The consent box on the registration form

The form now stores the answer (`whatsapp_optin`), which is what the platform
reads. The wording beside the box matters: it is what makes the first message
welcome rather than a surprise. Keep it short and specific, for example:

> ☐ Send me updates about Build Asia on WhatsApp. I can reply STOP at any time.

Leave it **unticked** by default. A pre-ticked box is not agreement.

---

## If something goes wrong

**My test message got no answer, and nothing shows under Replies received.**
The message is not reaching the platform.

- Check the webhook address in TextMeBot matches the one on the Settings page
  exactly, including the `token=` part.
- The server's firewall may be stopping it. On the dedicated server, in
  **WHM → Terminal** (or ask the hosting provider), run:
  ```
  imunify360-agent whitelist domain list
  ```
  `salesagent.ecgateway.net` should be listed. If it is not, add it:
  ```
  imunify360-agent whitelist domain add salesagent.ecgateway.net
  ```
  This is the same fix used earlier for the website chat.

**My message shows under Replies received, but no answer came.**
Open the reply in **Waiting to go** or **Could not be sent** and read the
reason. The common ones:

- *The API key was refused* — paste the number's key again under Agents and embed → Edit → WhatsApp.
- *Could not reach TextMeBot* — the server is not allowed to make outgoing
  connections, or TextMeBot is down. The hosting provider can tell you which.
- *The subscription appears to have expired* — renew at TextMeBot.

**First messages are written but never sent.**
Look at the top of the WhatsApp screen. It says whether you are outside the
sending hours or have reached today's limit. If approval is on, they wait under
**Waiting for your approval** until you approve them.

**Registrations are not being collected.**
Open **Visitors → Registrations** and press **Test the connection**. The
message says exactly what is wrong — a wrong password, a user without SELECT
permission, or the wrong table name. Also check the event numbers for the
exhibition are filled in.

**A message says "Sending was interrupted, so it is not known whether this
arrived."**
The platform was cut off in the middle of sending it. It does not send it
again automatically, because sending twice is worse than not sending. Check
with the person if it matters, and press **Try again** only if they did not
get it.

**Someone complained, or the number was blocked.**
Press **Stop all outgoing messages** on the WhatsApp screen at once. It stops
everything, on every exhibition, until someone resumes it. Then switch
WhatsApp off for that exhibition. Nothing more will be sent;
replies that arrive are still recorded, and requests to stop are still obeyed.
