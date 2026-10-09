# Deploying the AI Sales Agent Platform on cPanel

This is a complete walkthrough. It assumes no prior experience with servers.
Follow it in order and do not skip a step.

You will need:

- cPanel access on the server that will host the platform
- The exhibition website (a different site, possibly on different hosting)
- An API key from Anthropic or OpenAI
- About 40 minutes

This guide installs the platform on a new, empty server. For using it day to
day, open `docs/user-manual.html` in any browser — it is a beginner's guide
to every screen, with pictures.

Throughout, replace `salesagent.ecgateway.net` with your own subdomain and
`youruser` with your actual cPanel username.

---

## Part 1 — Prepare the subdomain

**1.1** Sign in to cPanel on the server that will host the platform.

**1.2** Open **Domains** (older cPanel: **Subdomains**).

**1.3** Create the subdomain:

- Subdomain: `salesagent`
- Domain: `ecgateway.net`
- Document Root: change it to `salesagent/public_html`

That document root matters. It means the web server can only reach the
`public_html` folder, and everything sensitive sits alongside it, out of
reach. Do not accept the default.

**1.4** Click **Create**.

---

## Part 2 — Check the PHP version

**2.1** Open **Select PHP Version** (sometimes under **Software**).

**2.2** Choose the subdomain if asked, then set the version to **8.1 or
newer**. 8.2 or 8.3 is ideal.

**2.3** On the **Extensions** tab, make sure these are ticked:

`mysqli`, `curl`, `mbstring`, `openssl`, `json`

Also tick `zip`, `dom` and `fileinfo` if they are listed. Those three let the
document importer read Word files and check that an upload really is the type
it claims to be. Everything else works without them.

**2.4** Save.

---

## Part 3 — Create the database

**3.1** Open **MySQL Databases**.

**3.2** Under *Create New Database*, enter `salesagent` and click **Create
Database**. cPanel will prefix it, giving something like
`youruser_salesagent`. Write the full name down.

**3.3** Under *MySQL Users*, create a user, for example `agentapp`. Use the
password generator and **copy the password somewhere safe now** — it is not
shown again.

**3.4** Under *Add User To Database*, select your new user and your new
database, click **Add**, then tick **ALL PRIVILEGES** and click **Make
Changes**.

You now have three things written down: database name, username, password.

---

## Part 4 — Upload the files

**4.1** Open **File Manager** and navigate to the `salesagent` folder created
in Part 1.

**4.2** Click **Upload** and upload `ai-sales-agent.zip`.

**4.3** Back in File Manager, right-click the zip and choose **Extract**.

**4.4** Confirm the folder structure looks like this:

```
salesagent/
├── app/
├── config/
├── cron/
├── docs/
├── index.php
├── public_html/     <- the only folder the web can reach
├── storage/
└── tools/
```

If everything ended up inside an extra nested folder, move the contents up a
level so it matches exactly. If `public_html` is in the wrong place, the
subdomain will show the wrong thing.

**4.5** Delete the zip file.

---

## Part 5 — Configure

**5.1** In File Manager, open the `config` folder.

**5.2** Right-click `config.sample.php`, choose **Copy**, and save it in the
same folder as `config.php`. You should now have both files.

**5.3** Right-click `config.php` and choose **Edit**.

**5.4** Fill in the database details from Part 3:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'youruser_salesagent',
    'user' => 'youruser_agentapp',
    'pass' => 'the password you saved',
],
```

Use the full prefixed names exactly as cPanel shows them.

**5.5** Set the web address, with no trailing slash:

```php
'base_url' => 'https://salesagent.ecgateway.net',
```

**5.6** Generate an encryption key. This protects your API keys in the
database. Open **Terminal** in cPanel and run:

```
php -r "echo bin2hex(random_bytes(32));"
```

Copy the long string it prints into:

```php
'encryption_key' => 'paste the long string here',
```

If Terminal is not available on your hosting, any tool that produces 64
random hexadecimal characters will do. Do not reuse this key anywhere else,
and keep a copy: losing it means the stored API keys cannot be read back.

**5.7** Set the environment to live:

```php
'environment' => 'production',
'debug'       => false,
```

`debug` must be `false` on a live site. With it on, error messages could
reveal internal details to a visitor.

**5.8** Save and close.

---

## Part 6 — Turn on HTTPS

**6.1** Open **SSL/TLS Status**.

**6.2** Tick the subdomain and click **Run AutoSSL**. Wait a minute or two
for the certificate to be issued.

**6.3** Visit `https://salesagent.ecgateway.net` in a browser. Two things
should be true:

- The padlock appears in the address bar.
- You get a page headed **Almost there**, telling you the configuration file
  has not been created yet.

That page is the platform confirming the files are in the right place and the
web server is reading them. If you have already completed Part 5, you will be
taken straight to the installer instead, which is also correct.

The chat widget refuses to send anything over an unencrypted connection, so
the padlock must be working before you go further.

**If you see "Forbidden" or "You don't have permission to access this
resource"**, the document root is wrong. See the first entry under *If
something goes wrong* at the end of this guide.

---

## Part 7 — Run the installer

**7.1** Go to:

```
https://salesagent.ecgateway.net/install/
```

Note the trailing slash. If you completed Part 6, visiting the site root will
have taken you here already.

**7.2** The first screen checks the setup. Each line reads one of three ways:

- **Ready** — nothing to do.
- **Must fix** — setup cannot continue until this is sorted. The commonest
  causes are a missing PHP extension (Part 2) or wrong database details
  (Part 5.4). Fix it and reload the page.
- **Recommended** — you can continue, but sort it out before visitors see the
  assistant. HTTPS shows here if the certificate is not detected, which can
  also happen when the site sits behind Cloudflare and is encrypted in a way
  the server cannot see from the inside.

**7.3** Click **Create the tables**. This creates every table the platform
needs, including those for WhatsApp, company research and prospecting.

**7.4** Create your administrator account. Use a password of at least 12
characters that you do not use anywhere else.

**7.5** Create your first agent:

- Exhibition name: `Build Asia`
- Short code: `buildasia`
- Website address: `https://www.buildasia.net`
- Websites allowed to show the chat:
  ```
  www.buildasia.net
  buildasia.net
  ```

That last box is a security control. The assistant refuses to answer requests
from any site not listed, so nobody can put your assistant on their own
website. List every address the exhibition site answers on, including the
`www` and non-`www` versions.

**7.6** Finish, then **delete the `public_html/install` folder**. In File
Manager, open `public_html`, right-click the `install` folder and choose
**Delete**.

This is the one part of the setup that has to sit inside `public_html`,
because it is a web page you visit. Everything else lives outside, where the
web cannot reach it. The installer also refuses to run a second time once an
administrator account exists, but removing it is better practice.

Once it is gone, the site root takes you to the sign-in page instead.

---

## Part 8 — Load the Build Asia content

This step loads the knowledge, FAQs and pricing prepared from your brochures,
partnership chart and post-show report.

**8.1** Open **Terminal** in cPanel.

**8.2** Run:

```
cd ~/salesagent
php tools/load-buildasia-content.php --agent=buildasia
```

**8.3** It will report what it loaded. Everything arrives as a **draft**, so
nothing is live yet. That is deliberate — you should look at it before
visitors do.

**8.4** If you want it live immediately without reviewing each item, run it
with `--approve` instead. Only do this if you accept the content as-is.

**8.5** For any other documents — future editions, updated price lists, a new
sponsorship chart — use **Conversation with AI** in the admin panel instead of
the command line. Upload the files, answer anything it asks about, and review
what it prepares. The operating manual explains it in full.

That page lets you choose which AI reads the documents, from whichever
providers you have set up. It does not have to be the one answering your
visitors — reading documents is a one-off job, so a free tier or a cheaper
provider is often the sensible choice for it.

If uploads fail because of file size, open **MultiPHP INI Editor** in cPanel,
choose this domain, and set `upload_max_filesize` to `16M` and `post_max_size`
to `20M`. The package ships a `public_html/.user.ini` that attempts this
automatically, but not every host honours it.

---

## Part 9 — Add your API key

**9.1** Sign in at `https://salesagent.ecgateway.net/admin/`.

**9.2** Go to **Settings**.

**9.3** Under **AI providers**, click the provider you want to use to open it,
paste its key into the box, then press **Save settings** at the bottom of the
screen. The key is encrypted before it is stored and is never displayed again.

You can choose from Claude, ChatGPT, Gemini, Grok, DeepSeek, Mistral,
OpenRouter, a model you run yourself with Ollama, or anything else that offers
an OpenAI-compatible endpoint. Set up as many as you like — each shows
**Ready** or **Not set up**.

**9.3a** Press the **Test** button inside that provider's box. It sends one small request and reports what came back. Do not skip this:
a mistyped key or a model name that does not exist on your account otherwise
surfaces only when a visitor is waiting.

If you are using Ollama, put the address it runs at in the box instead of a key
— typically `http://localhost:11434/v1`. Note that ordinary shared hosting
cannot run Ollama; it has to be reachable from this server.

**9.4** Go to **Agents and embed**, click **Edit** on Build Asia, and set the
**AI provider** to whichever you just set up. Leave **Model** blank to use that
provider's usual model.

Optionally set a **Backup provider** as well. If the main one fails, the backup
is tried once before the assistant shows its offline message. Choose a different
company rather than a different model from the same one, or a single outage
takes both.

---

## Part 10 — Set up email

**10.1** Still in **Settings**, set the **From address** to an address on
your own domain, for example `noreply@ecgateway.net`. Using a Gmail address
here causes messages to be marked as spam.

**10.2** Leave **How email is sent** as the server's own mail function to
begin with.

**10.3** Enter your own address in the test box and click **Send test**.

**10.4** Check your inbox, and the spam folder. If nothing arrives, switch to
SMTP and enter the details of a real mailbox on your domain (cPanel shows
these under **Email Accounts** → **Connect Devices**).

**10.5** Go to **Agents and embed** → **Edit** and confirm the two
notification addresses are right:

- International enquiries: `farhanecg@gmail.com, kaleemecg@gmail.com`
- Local and undetermined enquiries: `farhanecg@gmail.com, kaleemecg@gmail.com`

---

## Part 11 — Schedule the two background tasks

The platform does its background work through two scheduled tasks. Both are
needed.

**11.1** Open **Cron Jobs**.

**11.2** Under *Common Settings*, choose **Once Per Hour**.

**11.3** In the Command box, enter:

```
php /home/youruser/salesagent/cron/tasks.php >/dev/null 2>&1
```

Replace `youruser` with your cPanel username. The exact path is shown at the
bottom of the **Settings** page in the admin panel — copy it from there to
avoid typing errors.

**11.4** Click **Add New Cron Job**.

**11.5** Add a second job, **Once Per Five Minutes** (`*/5` in the minute
box), running `cron/whatsapp.php` the same way:

```
php /home/youruser/salesagent/cron/whatsapp.php >/dev/null 2>&1
```

Despite its name, this one does more than WhatsApp: it also researches
companies, runs prospecting campaigns, sends approved prospect emails and
answers chat and WhatsApp messages the AI was too busy for. It does nothing
until those features are switched on, so add it now.

| Task | How often | What it does |
|---|---|---|
| `cron/tasks.php` | Once per hour | Queued emails, registrations, housekeeping, data retention |
| `cron/whatsapp.php` | Every 5 minutes | WhatsApp, follow-ups, research, prospecting, busy-AI retries |

---

## Part 12 — Review and publish the content

**12.1** In the admin panel, open **Review queue**. Everything loaded in
Part 8 is waiting there.

**12.2** Either approve items yourself by opening each one, or tick them all
and create a reviewer link to send to a colleague. They can approve without
an account.

**12.3** Nothing is answerable by the assistant until it is approved. Check
the **Dashboard** — the warnings at the top disappear once you have approved
knowledge and pricing.

---

## Part 13 — Put the chat on the exhibition website

This is the only step that happens on the exhibition website rather than
here. The exhibition site needs no database, no PHP changes and no software.

**13.1** In the admin panel, go to **Agents and embed** and click **Edit**.
Copy the embed code shown at the top. It looks like this:

```html
<script src="https://salesagent.ecgateway.net/widget/widget.js" data-agent="buildasia"></script>
```

**13.2** On the exhibition website, paste that line into the page template,
just before the closing `</body>` tag.

- **WordPress:** Appearance → Theme File Editor → `footer.php`, or use a
  plugin such as *Insert Headers and Footers*
- **Plain HTML:** add it to each page template
- **Wix, Squarespace, Shopify:** look for *Custom Code* or *Embed Code* in
  the site settings

**13.3** Visit the exhibition website. A chat button should appear in the
bottom-right corner.

**13.4** Optional adjustments, added to the same tag:

```html
data-position="left"          moves the button to the left
data-label="Talk to us"       changes the button wording
data-auto-open="true"         opens the panel automatically
```

---

## Part 14 — Test it properly

Work through this list before telling anyone the assistant is live.

**14.1** Open the exhibition website and click the chat button. A greeting
should appear within a second or two.

**14.2** Type: *I want to exhibit*. You should get a sensible reply asking
about your business.

**14.3** Type: *Our company is based in Dubai. What does a 9 sqm shell scheme
stand cost?* You should see a price card showing the participation charge,
the Sindh Sales Tax and the total as three separate lines.

**14.4** Type: *I'm a Pakistani company, what are your rates?* You should get
**no prices at all** — just an offer to have the team send them. This is the
most important test on the list.

**14.5** Type: *Can you give me that in dirhams?* It should decline to
convert.

**14.6** Ask for something you know is not in the knowledge base. It should
say it does not have that information rather than making something up.

**14.7** Leave your contact details. Check that the notification email
arrives at both Gmail addresses, with the full conversation in it.

**14.8** In the admin panel, open **Leads** and confirm the record is there.

**14.9** Try the site on a phone. The panel should fill the screen.

---

## Part 15 — Switch on the other features (when you are ready)

The website chat is now live. The other features are switched on per
exhibition, when you want them. The step-by-step instructions are in
`docs/upgrade-2026-09-whatsapp.md` — on a new server **skip Parts 1 to 3**
(the files and database are already up to date) and start from Part 4:

| Feature | Parts of that guide | Time |
|---|---|---|
| WhatsApp for registered visitors | 4 to 10 | about 45 minutes |
| Company research (Tavily) | 11 | 10 minutes |
| Prospect emails and campaigns | 12 | 15 minutes per exhibition |
| Cheaper AI model for background work | 13 | 2 minutes |

To add another exhibition, such as Pharma Asia: **Agents and embed → New
agent**, then repeat Parts 12 to 14 for it. Each exhibition keeps its own
content, prices, WhatsApp number and email sender.

---

## If something goes wrong

**"Not Found" at `/install/`.** The `install` folder must be inside
`public_html`. In File Manager, open `public_html` and confirm you see
`install` there, alongside `admin`, `api`, `assets`, `review` and `widget`.

If `install` is sitting next to `public_html` rather than inside it, you are
running an older copy of the package. Move the folder into `public_html`, or
re-download the current zip.

Remember the trailing slash: `/install/`, not `/install`.

**Tables will not create, or you would rather do it by hand.** The database
structure is in `app/schema.sql`. You can import it directly instead of using
the installer: open **phpMyAdmin** in cPanel, select your database, choose the
**Import** tab, upload `app/schema.sql`, and click **Import**. Then go back to
`/install/` and continue from the account step. Once you have signed in,
open **Set-up → Database upgrade** and press the button: the file only holds
the original tables, and this adds everything added since.

**"Forbidden — you don't have permission to access this resource."** Almost
always the document root. The web server is being pointed at a folder it is
not allowed to list, and there is nothing there it can serve.

Check it in cPanel under **Domains** → edit the subdomain → **Document Root**.
It must read:

```
salesagent/public_html
```

Not `salesagent`, and not `public_html/salesagent`. If it says just
`salesagent`, add `/public_html` to the end and save.

Two ways to confirm which mistake it is:

- If the document root points at `salesagent`, you will see a page headed
  **The document root needs changing** once the subdomain reloads, telling you
  the same thing.
- If it points somewhere else entirely, you will keep getting Forbidden. In
  File Manager, confirm that `public_html` sits *inside* the `salesagent`
  folder, alongside `app`, `config` and `storage` — not somewhere else.

If the path is right and you still get Forbidden, check file permissions.
In File Manager, select the `salesagent` folder, choose **Permissions**, and
set folders to `755` and files to `644`. A folder set to `700` will produce
this error.

**The subdomain shows a file listing.** The document root is one level too
high and directory listing is switched on. Fix the document root as above.

**"Database connection failed" during install.** The details in
`config.php` do not match. cPanel prefixes both the database name and the
username with your account name — check you used the full prefixed versions.

**The chat button does not appear on the exhibition site.** Open the browser
console (F12) and look for a red error. If it mentions CORS or origin, the
website address is missing from the allowed list in **Agents and embed**.
Remember to add both the `www` and non-`www` versions.

**The assistant replies "I'm not able to respond just now".** The AI provider
is not answering. Check **Dashboard** for the reason. Usually the API key is
wrong or the account has no credit. Enquiries are still being captured
through the contact form while this is happening.

**No emails arrive.** Send a test from **Settings**. If that fails, switch to
SMTP with a real mailbox on your domain. Also check the queue count on the
Settings page — if messages are piling up, the cron job in Part 11 is not
running.

**Prices are not showing.** Check the price records are **Live** rather than
Draft on the **Stand pricing** page, and that the visitor's country was
established. The conversation transcript shows both.

---

## Keeping it secure

- Delete the `public_html/install` folder after setup.
- Keep `debug` set to `false` in `config.php`.
- Never move `config`, `app` or `storage` inside `public_html`.
- Use a long, unique password for the admin account.
- Keep a copy of the encryption key somewhere safe and separate.
- Back up the database regularly. cPanel's **Backup Wizard** does this.

---

## Moving to a different server later

1. Copy the whole `salesagent` folder.
2. Export the database from phpMyAdmin and import it on the new server.
3. Update the database details and `base_url` in `config.php`.
4. Carry the same `encryption_key` across, or the stored API keys will not
   decrypt and will need entering again.
5. Set up both scheduled tasks again (Part 11).
6. If the web address changed, update the embed code on the exhibition site.
