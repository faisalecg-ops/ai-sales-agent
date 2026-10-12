# AI Social Media Expert Agent — installation guide (Stage 1)

This goes on the sales agent's own server (salesagent.ecgateway.net). It
takes about 15 minutes. It changes nothing in the sales agent: it adds the
folder `public_html/social` and its own database tables (all named `sm_…`).

**Before you start:** the sales agent must have the shared-login upgrade of
10 October 2026 installed (**Set-up → Users** exists). It was installed on
10 October 2026.

---

## Step 1 — Upload (3 minutes)

cPanel → **File Manager** → open `public_html`:

1. **Upload** `social-agent-1.4.0.zip`, then right-click it → **Extract**.
   This creates the folder `public_html/social`.
2. Delete the zip file afterwards.

Updating later works the same way. Extract the new zip over the old folder;
pictures in `social/uploads` and all data are kept.

**Updating from 1.3.0 to 1.4.0 (12 Oct 2026, Stage 4 — results):** install
three packages, in this order:
1. Every event blog: `event-blog-1.4.0.zip` over `public_html/blog`, then open
   the blog admin once. (It counts visits that come from social posts and gives
   /social the reading figures.)
2. The AI Sales Agent: `salesagent-upgrade-2026-10-arrival.zip` (see
   `docs/upgrade-2026-10-arrival.md`), then Set-up → Database upgrade → run the
   new step. (It remembers which post brought each chat visitor.)
3. `social-agent-1.4.0.zip` over `public_html/social`, then open `/social` once.

New: **Results** in the menu — posts, likes, comments, shares, views, visits to
the blog, chats and leads per platform and per post, best posts, most-read
articles, exhibitor kits, AI use and the X cost — printable, and emailed by
itself early each month (for the month before) to Event settings → Monthly
report. Each posted post shows its own results; for posts made by hand you
type in the likes and comments. Figures of automatic posts are read 1, 7 and 28
days after posting; X charges about $0.005 per read, so Blog and platforms → X
has a monthly limit (default 300). The existing 5-minute cron job does it all.

**Updating from 1.2.0 to 1.3.0 (12 Oct 2026, Stage 3):** extract
`social-agent-1.3.0.zip` over `public_html/social` and open `/social` once (the
blog does not change). New: automatic posting to Facebook, Instagram, LinkedIn
and X once the platform apps are approved (Blog and platforms → Platform apps
and Connect; see the Claude doc "Stage 3 — Applying to Meta, LinkedIn and X");
Exhibitors and kits (list from the website, typed in or uploaded); the weekly
industry radar and curated news (Content plan); a public privacy page at
`/social/privacy.php` for the platforms' reviews. The existing 5-minute cron
job does all the scheduled work; nothing new to add in cPanel.

**Updating from 1.1.0 to 1.2.0 (12 Oct 2026):** first update every event blog
to **1.3.0**, then extract `social-agent-1.2.0.zip` over `public_html/social` and
open `/social` once. New: posts about Industry Voices articles credit the writer
and show whom to tag; posts about sponsored articles always start with
"Sponsored · [sponsor]" and cannot be approved without it; Event settings →
Monthly report holds the addresses that receive the monthly report.

**Updating from 1.0.0 to 1.1.0 (12 Oct 2026):**
1. First update the event blog to **1.2.3** (`event-blog-1.2.3.zip` over
   `public_html/blog`) — 1.1.0 reads the blog's new "published" and "waiting"
   lists.
2. Then extract `social-agent-1.1.0.zip` over `public_html/social`.
3. Open `/social` once; its tables update by themselves.

New in 1.1.0: articles the blog's own team publishes in the blog admin are
picked up (by the blog's notice, or the 5-minute check) and their social posts
are written as drafts for approval; articles waiting for an editor in the blog
are listed on the dashboard with an "Approve in the blog" link. Articles the
blog already had are never posted again. Switch it off in Event settings →
"Also write social posts for articles written directly in the blog".

## Step 2 — Open it once (1 minute)

Open **https://salesagent.ecgateway.net/social/** while signed in to the
sales agent.

- The tables are created automatically on this first visit.
- From now on the top bar of both platforms shows
  **AI Sales Agent | AI Social Media Expert Agent**.

## Step 3 — The scheduled task (2 minutes)

cPanel → **Cron Jobs** → add a job, **Once Per Five Minutes** (`*/5 * * * *`):

```
/usr/local/bin/php /home/ACCOUNT/public_html/social/cron.php
```

Change `ACCOUNT` to the cPanel user name. The exact line is also shown in
/social → **How it works**.

The task does four things:

- turns scheduled posts into "Post it now" reminders;
- checks articles the blog is still holding;
- finishes articles left half-written;
- re-sends approved articles that did not reach the blog.

## Step 4 — Connect the event blog (3 minutes)

1. **In the Build Asia blog admin → API keys:**
   - make a key named "Social Media Agent" with the **publish** permission;
   - copy the key, which is shown only once.
2. **In /social → Blog and platforms:**
   - **Blog address:** `https://buildasia.ecgateway.net/blog` (later `https://buildasia.net/blog`);
   - **API key:** paste the key;
   - copy the **notice address** shown there.
3. **Back in the blog admin → API keys → "Article published" notice:**
   - paste the notice address and save;
   - copy the **signing secret** shown there.
4. **In /social → Blog and platforms:**
   - paste the secret into **Signing secret**;
   - press **Save and test**. It should say "Connected".

## Step 5 — Platforms and settings (5 minutes)

**Blog and platforms:**
- tick LinkedIn, Facebook and Instagram;
- paste the address of each event page;
- set posts per week, the usual posting time and any fixed hashtags (for
  example `#BuildAsia2027`).

**Event settings:**
- articles per week and the content mix;
- the sectors to cover;
- the reminder email addresses;
- the monthly AI limits;
- the colours, if different from Build Asia's.

**Pictures and videos:**
- upload the event logo and press **Use as logo**;
- upload a few past show photos. They are used behind the branded images.

## Step 6 — Give people access (2 minutes)

In the sales agent → **Set-up → Users**:

1. Tick **AI Social Media Expert Agent** for each person.
2. Choose their exhibitions and a role for each:

   | Role | What it allows |
   |---|---|
   | Admin | Everything for that exhibition |
   | Approver | Approves and publishes |
   | Editor | Writes and edits |
   | Viewer | Reads only |

Super admins (the current accounts) already see everything.

---

## What the server needs

- **PHP 8.1 or newer** with **GD + FreeType**, used for the branded images. The
  sales agent's server already has them; /social says plainly if they are
  missing.
- **Upload size:** for videos, raise `upload_max_filesize` and
  `post_max_size` in cPanel → *Select PHP Version → Options*, e.g. 200M.
- **AI and research:** the keys already saved in the sales agent's Settings
  are used, read-only: the AI provider, and Tavily for web research.
- **AI pictures (optional):** need an **OpenAI key** saved in the sales
  agent's Settings. Pictures are counted against each exhibition's monthly
  limit.
- **Reminder emails** are sent through the exhibition's own email profile in
  the sales agent (Exhibitions → Email sending). Without one, reminders show
  only on the dashboard.

## If something goes wrong

- **"No access yet":** the person needs the AI Social Media Expert Agent
  ticked under Users.
- **The blog test fails:** check the blog address ends in `/blog` and starts
  with `https://`, and that the key has not been switched off in the blog
  admin.
- **"This key can only send drafts":** make a new key with the **publish**
  permission. Otherwise every article also waits for approval in the blog
  admin.
- **No reminders:** check the cron job (the dashboard says when it has not
  run), the reminder addresses, and the exhibition's email profile.
- **Error details** are written to the sales agent's `storage/logs/`.
