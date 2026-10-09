# Exhibition Marketing Ecosystem — plan under discussion

**Status: DISCUSSION ONLY. Nothing in this document may be built until the
owner approves the final plan in writing ("go ahead", "develop", etc.).**
Last updated: 9 October 2026, from the discussion of 8–9 October 2026.

This document hands the discussion over to a new chat. Read it together with
`CLAUDE.md` (standing rules) before saying or doing anything about the blog or
the social media agent.

---

## 1. The goal (owner's words, summarised)

- Increase social media engagement, build each event's brand, and increase
  sales of the event (exhibitors, sponsors, visitors).
- Drive traffic to each event's own website through a blog at
  `event-website/blog`.
- Tone: industry welfare. Content must strengthen the exhibition's role in the
  development of its industry — useful industry content, not constant
  "visit us" advertising.

## 2. The ecosystem — three parts

| Part | Lives at | Its job |
|---|---|---|
| **Sales Agent** (exists, built) | salesagent.ecgateway.net | Approved knowledge and prices, website chat, WhatsApp, company research, prospecting, leads |
| **Event Blog** (to build, Phase 1) | each event site at `/blog` (e.g. buildasia.net/blog) | Standalone blog script with its own admin, home page, sponsors, branding, SEO, API |
| **Social Media Agent** (to build, later) | salesagent.ecgateway.net/social | Topics and research, writes articles into the blogs by API key, writes platform-specific social posts, approval, publishing or manual "Done" |

How they connect:
- The sales agent chat is embedded on **every** blog page.
- Event facts (dates, venue) come from the sales agent's approved information,
  with a manual override in the blog — one source of truth.
- One **brand kit** per event (logos, colours, fonts) used by the blog, the
  social media images and, later, the chat button.
- Results flow back: which article and platform brought visitors, chats, leads.

---

## 3. Phase 1 — the Event Blog (standalone script)

Owner's decision (8 Oct): the first phase is a **standalone, beautiful,
corporate, SEO-rich blog script** installed on each event website at `/blog`,
with its own admin panel and an API key system ready for the future social
media agent.

### 3.1 Installation and structure
- One script, installed separately on each event website (Build Asia, Health
  Asia, Pharma Asia, future events). Each site has its own database tables,
  admin, users and content. One package updates all of them.
- Admin at `/blog/admin`, with its own users.
- Same technical approach as the sales agent: PHP 8 + MySQL(i), no framework,
  no Composer, cPanel, cron only. (Depends on open question 1 — hosting.)

### 3.2 Home page — fully controllable from the admin
Every section can be switched on/off and reordered:
- **Hero slider**: per slide image, headline, short text, button, start/end
  dates (slides expire by themselves), order, transition style and speed.
- **Featured articles** (chosen by the editor).
- **Latest articles**.
- **Category blocks** (e.g. Machinery, Building Materials, Technology, Market
  Insights).
- **Sponsor banners**: positions top / middle / sidebar / footer; per banner
  image, link, sponsor name, start/end dates, click counting; rotation when
  several share a position.
- **Event panel**: countdown to the show, "Register to visit" and "Book a
  stand" buttons linking to the main website.
- **Most read** and **tags**.

Look and feel: corporate, clean, powerful CSS — slider transitions, gentle
scroll animations, hover effects. Respect "reduce motion" settings. Keep pages
fast (light animations, modern compressed images, lazy loading, page cache),
because speed affects Google ranking.

### 3.3 Branding per event (admin-controlled)
- Logos: main logo (light and dark versions), footer logo, favicon, default
  share image.
- Colours: primary, secondary, accent, background, text; presets; **live
  preview** before saving; **readability (contrast) warning**; previous theme
  kept for one-click undo.
- Fonts from a short professional list, stored on the site (no external font
  calls) for speed.
- Style: header style (light / dark / transparent over slider), button shape,
  corner roundness, card style, slider image shading.
- Event identity: name, tagline, dates, venue, city (feeds countdown, buttons
  and structured data); footer address, phone, email, social media icons.

### 3.4 Main event website link — "courtesy" (owner, 9 Oct)
- Header: clear button, e.g. "Visit Build Asia ↗".
- Under every article: a "Courtesy of Build Asia" box with logo, one line
  about the exhibition, dates and venue, buttons Visit website / Register to
  visit / Book a stand.
- Footer: "Blog by Build Asia — buildasia.net" plus main-site links.
- On phones the header button folds into the menu; the article box stays.
- Wording, placement and on/off set in the admin.

### 3.5 Links inside article text (owner, 9 Oct)
- Link naturally on words in the text where relevant.
- **Link library per event** in the admin: approved URLs (register, book a
  stand, exhibitor list, conference, venue, sponsorship, key earlier
  articles). The AI may only link to these, to other published articles of
  the same blog, and to sources it actually used — never invented addresses.
- Editor suggests links while writing; before approval a list of all links in
  the article is shown.
- SEO rules: usually 3–6 links per article; internal links normal; external
  links open in a new tab; paid/sponsor links marked `rel="sponsored"`;
  weekly broken-link check.
- **No UTM tags on links inside articles** — UTM only on links shared on
  social media.

### 3.6 SEO (built in)
- Clean URLs (`/blog/category/article-slug`), automatic 301 redirect when a
  slug changes.
- Per article: SEO title, meta description, Google preview.
- Open Graph and X/Twitter cards (image + title when shared on Facebook,
  LinkedIn, WhatsApp, X).
- Structured data (JSON-LD): Article, BreadcrumbList, Organization, Event.
- Canonical URLs, breadcrumbs, related articles, author pages, proper 404.
- **Sitemap** at `/blog/sitemap.xml` (articles, categories, images) and an
  RSS feed.
- Correction agreed in discussion: a normal sitemap cannot contain another
  sitemap's link. Use one of: `Sitemap: https://site/blog/sitemap.xml` line in
  the main site's robots.txt (easiest), or turn the main sitemap into a
  sitemap index, and/or submit in Google Search Console. Recommended:
  robots.txt line + Search Console.

### 3.7 Sales agent chat on every page (owner, 8 Oct)
- The blog layout includes the sales agent embed line on every page (home,
  articles, categories, search, 404) automatically.
- Admin setting: which exhibition's assistant (agent slug) and an on/off
  switch.
- Same domain as the event site, so it is already on the agent's allowed
  domain list.
- Sticky banners, slider and animations must never cover the chat button
  (desktop or phone). Chat loads after the page (no speed penalty).
- Later option: the chat opens with a line relevant to the article being read.

### 3.8 Blog admin panel
- Articles: word-processor style editor, featured image, categories, tags,
  scheduled publishing, revision history, statuses draft → awaiting approval
  → published.
- Media library with automatic resizing.
- Home page builder (slider, sections, order, banners, event panel).
- Categories, authors, menus, SEO settings, branding, link library.
- Users and roles: admin, editor, author, approver.
- API keys: create, name, switch off; per key permission **"drafts only"**
  (arrive awaiting approval — the default for the agent) or **"can publish"**.
- Activity log.

### 3.9 Blog API (for the future social media agent)
- Create / update article, upload images, list categories, check status.
- On publish, the blog notifies the agent (webhook) with the published URL —
  the trigger for writing social posts.
- Keys stored hashed, HTTPS only, rate-limited.

---

## 4. Later phase — the Social Media Agent (at /social)

Owner's decisions (8 Oct):
- Hosted at `salesagent.ecgateway.net/social`; every file in that folder.
- Uses the **same database** as the sales agent with its own table prefix
  (`sm_`), never altering sales agent tables; reads (never modifies) the sales
  agent's config, AI keys and approved knowledge. Private code/config inside
  /social must be blocked from the web.
- Users per event: super admin creates users and gives access to specific
  events; event switcher like the sales agent. Suggested roles: admin, editor,
  approver, viewer. (Open: same logins as sales agent or separate.)

Workflow:
1. Topic — from content plan, industry radar, news, or new approved knowledge.
2. AI writes blog draft → quality checks (facts against knowledge base,
   sources, tone, no copied text).
3. Human approval → published on the event blog via API.
4. AI writes a **separate, platform-specific post** for each social platform
   configured for that event (not "here is our blog post"): LinkedIn business
   angle, Facebook awareness, Instagram visual-first (image required), X short,
   YouTube/TikTok script only. Image + tracked (UTM) link back to the blog.
5. Each post approved → published automatically **or** manual mode.
6. Results tracked.

Manual mode (owner): when a platform cannot be connected, show the complete
post with image, Copy text and Download image buttons, a link to the event's
page on that platform, and a **"Done — I posted it"** button (optional box for
the post's link). Automatic connections can be switched on per platform later.

Images (owner): (1) upload final image or video — used as is; (2) upload a
photo + text — system builds a branded image from event templates (PHP GD,
no extra cost); (3) optional AI-generated images for general illustrations
only, never real machinery, people or logos (extra cost — open question).

News curation (owner): share relevant news/articles as highlight, review,
remarks — own summary and commentary, at most a short credited quote, always
a link to the original, never full text or their images; every curated post
shows its source and is approved by a person.

Industry radar (owner): weekly research (Tavily) for new reports, research,
boom sectors, big projects — shown as suggested topics with sources; nothing
written automatically from them.

Platform connection notes (verify before building): Meta (Facebook Pages,
Instagram Business) needs a Meta app and app review; LinkedIn company posting
needs LinkedIn approval; X posting is limited/paid; YouTube/TikTok need real
video. Paid aggregators (e.g. Buffer) are an alternative.

Suggested later order: Phase 2 — auto-connections (Facebook/Instagram first,
then LinkedIn), news curation, industry radar. Phase 3 — engagement reports
from platforms, comment monitoring with suggested replies, monthly management
report.

---

## 5. Reference: the owner's draft plan file

`docs/reference/marketing-agent-plan-draft.md` — a broad "International
Marketing Intelligence & Distribution Agent" plan. The owner said it is **for
discussion only and not fully agreed**. Useful parts already adopted: content
pillars and configurable weekly rhythm; "skip rather than post filler";
quality checks before approval; UTM tracking; source hierarchy and "never
invent" rules; approval states. Deferred: platform discovery, media and
association partnerships, competitor intelligence, B2B marketplaces,
newsletters, country landing pages.

---

## 6. Answers given in the new chat (9 October 2026)

1. **Hosting**: event websites are on **cPanel shared hosting** (not the
   sales agent's dedicated server). The blog must run within shared-hosting
   limits: PHP 8 + MySQL, cron via cPanel, file-based page cache, PHP GD for
   images, no long-running processes. Still to confirm per site: PHP ≥ 8.0,
   a new MySQL database allowed, cron allowed, and whether the main site is
   WordPress or plain HTML/PHP (so `/blog` does not clash with its rewrite
   rules).
2–6. Owner's answer: **"all controlled in admin panel."** Therefore:
   - **Comments**: built in, switch in the admin (off / on with approval).
   - **Sponsor reports**: views and clicks in the admin, plus a downloadable
     report per sponsor; switchable.
   - **Newsletter sign-up**: built in, switch in the admin, same consent and
     unsubscribe rules as the sales agent.
   - **Languages**: language set in the admin (scope to confirm — see below).
   - **Design**: fully in the admin (branding, colours, fonts, styles as in
     §3.3); logos and colours supplied per event at installation.

## 6a. Still open

- Languages: interface language only (English default, Urdu option with
  right-to-left layout), or articles in two languages side by side? The
  second is much larger work.
- Main event sites: WordPress or plain HTML/PHP?
- (Social agent, later) Same logins as the sales agent or separate? AI image
  generation wanted (extra cost)? Which social platforms per event? Target
  number of articles per event per week?

## 7. Next step

Continue the discussion in a new chat until the questions above are answered,
then write the final Phase 1 plan (Event Blog) for the owner and his boss to
approve. Only after written approval: develop.
