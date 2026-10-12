# Event Blog — installation guide

For whoever sets up the blog on an event website's cPanel. About 20 minutes per
website. No technical knowledge beyond using cPanel is needed.

The same package is used for every event (Build Asia, Pharma Asia, Health Asia
and future events). Each website gets its own copy, its own database tables,
its own admin and its own content.

---

## What you need

- The package **event-blog-1.3.0.zip**.
- cPanel access for the event website.
- The exhibition's logo (PNG, ideally with a transparent background, plus a
  white version for dark backgrounds), its brand colours, and the addresses
  of its "register to visit" and "book a stand" pages.

## Step 1 — Check the hosting (2 minutes)

In cPanel → **Select PHP Version** (sometimes called *MultiPHP Manager*):

- Choose **PHP 8.1 or newer** (8.2 or 8.3 is best).
- Make sure these are ticked: `mysqli`, `gd`, `mbstring`, `dom`, `curl`,
  `sodium`, `fileinfo`. They usually are already.

The installer checks all of this again and tells you in plain words if
anything is missing.

## Step 2 — Create a database (3 minutes)

cPanel → **MySQL Databases**:

1. Create a new database, e.g. `buildasia_blog`.
2. Create a new user with a strong password, e.g. `buildasia_bloguser`.
3. Add the user to the database with **ALL PRIVILEGES**.

Write down the full database name, user name and password (cPanel adds the
account name in front, e.g. `cpaneluser_buildasia_blog`).

> The blog can also share the main website's database: its tables all start
> with `blog_`, so nothing clashes. A separate database is tidier.

## Step 3 — Upload the files (5 minutes)

cPanel → **File Manager** → open `public_html` (the main website's folder):

1. Create a folder named **`blog`**.
2. Open it, **Upload** `event-blog-1.3.0.zip`, then right-click it → **Extract**.
3. Delete the zip file afterwards.

You should now see `public_html/blog/index.php`, `admin`, `assets` and the
other folders. The main website is not touched.

## Step 4 — Run the installer (3 minutes)

Open **https://the-event-website/blog/install/** in a browser, for example
`https://buildasia.net/blog/install/`.

Fill in the database details from step 2, the exhibition name, the starting
categories (one per line — e.g. Machinery, Building Materials, Technology,
Market Insights) and the first admin's name, email and password. Press
**Install the blog**.

The installer creates the tables and the admin, writes the settings file and
then locks itself: it will not run again.

## Step 5 — The scheduled task (cron) (2 minutes)

cPanel → **Cron Jobs** → add a new job:

- Common setting: **Once Per Five Minutes** (`*/5 * * * *`)
- Command (change `ACCOUNT` to the cPanel user name):

  ```
  /usr/local/bin/php /home/ACCOUNT/public_html/blog/cron.php
  ```

The exact command is also shown in the blog admin under **Readers & general →
Scheduled tasks**, with a tick once it is running.

It publishes scheduled articles on time, tells the Social Media Agent about
new articles, sends newsletter confirmation emails, refreshes the event facts
from the sales agent and checks links once a week.

## Step 6 — Set up the blog in its admin (10 minutes)

Sign in at **https://the-event-website/blog/admin/**.

**For Build Asia, start with Settings → Starter content → Import.** In about a
minute it adds eleven categories and 46 researched articles, the logo, menus,
counter figures, slider, link library and the event details — so most of the
list below is already done. Check the event details, then switch on the chat.

The dashboard shows a **Finish setting up** list. Work through it:

| Page | What to do |
|---|---|
| **Branding** | Upload the logos and the tab icon. Pick a complete look (Build Asia, Corporate, Editorial, Medical / Pharma, Modern minimal) or set colours and fonts yourself. Watch the live preview, then save. |
| **Event & courtesy** | Check the dates, venue and the three website addresses (main site, register, book a stand), contact details and social media pages. |
| **Link library** | Add the approved addresses: register, book a stand, exhibitor list, conference, sponsorship, venue. |
| **Menus** | Add the categories to the header menu ("Quick add" lists them) and main-site pages to the footer. |
| **Sales agent chat** | Tick "Show the sales agent chat", enter the exhibition's agent name (e.g. `buildasia`), press **Save and test connection**. |
| **Email** | The sender for newsletter confirmations, e.g. "Build Asia Secretariat" `<info@buildasia.net>`. Use SMTP for reliable delivery. |
| **Users** | Add editors, authors and approvers. |

## Step 7 — Connect to Google (5 minutes)

1. **Add one line to the main website's `robots.txt`** (the file at
   `https://the-event-website/robots.txt`; create it in `public_html` if it
   does not exist):

   ```
   Sitemap: https://the-event-website/blog/sitemap.xml
   ```

2. **Google Search Console** → the website's property → **Sitemaps** → add
   `blog/sitemap.xml`. (If the website is not yet in Search Console, add it
   and use the verification code box in **SEO & Google**.)

## Step 8 — In the sales agent (2 minutes)

In the sales agent admin (salesagent.ecgateway.net/admin) → **Exhibitions** →
edit the exhibition:

- **Dates & venue**: fill in the first and last day, one-line description,
  venue, city, country, and the register / book-a-stand pages. The blog reads
  these once a day (anything typed in the blog's own Event page wins).
- **Websites allowed to show the chat**: make sure the event website is
  listed (e.g. `buildasia.net`). The blog lives on the same website, so it is
  usually there already.

> The sales agent needs the October 2026 upgrade for the **Dates & venue**
> card — see `docs/upgrade-2026-10-event-facts.md`.

---

## A review copy first (Build Asia)

To let management review the blog before it goes live, install it on the
review website first (e.g. `buildasia.ecgateway.net/blog`) and tick **SEO &
Google → Hide the blog from Google**.

When the new website goes live at buildasia.net, there are two ways:

- **Install fresh** on buildasia.net (simplest if the review copy had only
  test articles), or
- **Move the review copy**: copy the whole `blog` folder and the database to
  the live account, then in the blog admin → **Readers & general**, change
  the **Blog address** to `https://buildasia.net/blog`, untick **Hide the blog
  from Google**, and update `config/config.php` if the database details differ.

Either way, the new main website should leave the `/blog` folder alone and
link to it in its menu.

## Pharma Asia's renovation

The blog can be installed on the current pharmaasia.com.pk now. When the new
website replaces the old one, keep the `public_html/blog` folder (and its
database) as it is — the blog carries on unchanged under the new site.

---

## Updating the blog later

Upload the new **event-blog-1.3.0.zip** into `public_html/blog` and extract it,
replacing files. Your settings (`config/config.php`), uploaded images
(`uploads/`) and saved data are not in the package, so they are never
overwritten. Then open the admin once: any database changes are applied
automatically.

**New in 1.3.0 (12 Oct 2026):** Industry Voices (contributor profiles, the
section page, badges, the public "Write for us" form) and sponsored articles
(labels, disclosure line, paid period, click counting and the sponsor report).
Both new home page sections are switched on and only appear once they have
something to show. If a category already uses the address `industry-voices`
or `write-for-us`, change the section's address in Industry Voices →
Contributors → Section settings.

To sell sponsored articles through the chat, add the package and its price in
the AI Sales Agent under Content → Sponsorship (a sponsorship tier). Prices
written in Knowledge are not quoted by the chat.

## If something goes wrong

- **"Configuration missing" or the installer appears again**: the file
  `blog/config/config.php` is missing — restore it from a backup.
- **Pages show without styling** (plain text, blue links):
  - From version 1.2.2 the styles always load from the address you opened.
  - If the **Blog address** saved in the admin is out of date (for example
    `http://` when the site now uses `https://`, or the review copy's
    domain), the dashboard shows a red box. Press its button to fix the address.
  - If the styling is still missing, the `.htaccess` file in `blog/` was not
    uploaded. It is hidden in File Manager; tick *Show Hidden Files*.
- **Images do not upload**: make `blog/uploads` writable (permission 755) and
  check the upload size limit in *Select PHP Version → Options*
  (`upload_max_filesize` 16M, `post_max_size` 20M).
- **Error details** are written to `blog/storage/logs/` (not visible to
  visitors).
