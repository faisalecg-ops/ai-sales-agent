# AI Sales Agent Platform — standing instructions

Read this before any task on this project.

## Current status (10 October 2026) — read first

- The **Sales Agent is complete** (website chat, WhatsApp, follow-ups,
  research, prospecting, analytics, reorganised admin). 1,201 acceptance
  tests pass. Delivered as an upgrade package and a fresh-install package.
- **Event Blog (Phase 1) is built** (approved 9 Oct 2026; plan: Claude doc
  "Event Blog — Phase 1 Plan"). Code in `blog/` (v1.4.0: tagged-visit counting + `GET /stats` (Stage 4); 1.3.0: Industry Voices + sponsored articles (Stage 2), Build Asia look, complete looks, motion levels, Build Asia starter content in `blog/content-packs/`: 11 categories, 46 sourced articles) — a standalone PHP 8.1+ /
  MySQLi script installed per event website at `/blog` on cPanel **shared**
  hosting, table prefix `blog_`. Every reader option (comments, newsletter,
  sponsor reports, branding, language) is a setting in the blog admin.
  335 tests (run from the repo root): `php blog/tests/acceptance.php` (database `blogtest`, prefix
  `bt_`). Guides: `docs/blog-installation-guide.md`, `docs/blog-user-manual.html`,
  `docs/blog-api.md`. The sales agent gained `public_html/api/event.php` and
  a **Dates & venue** card (`docs/upgrade-2026-10-event-facts.md`) — the
  owner installed it on the live sales agent on 9 Oct 2026. Read
  `docs/sales-agent-status-2026-10-09.md` before any sales agent work.
  Never change the sales agent without the owner's explicit approval.
  **Approved sales agent change (10 Oct 2026) — installed live 10 Oct 2026 (owner):**
  shared login, per-event staff access on both platforms, and the platform
  switcher. Read `docs/sales-agent-status-2026-10-10.md`. The contract for
  /social is the "Done — what /social can rely on" section at the end of
  `docs/handover/2026-10-10-shared-login-and-event-access.md`. Sales agent
  tests now: `php tests/acceptance.php` (1,207) and `php tests/access-http.php`
  (82, end-to-end staff isolation). New admin pages must use
  `admin_require_access('view'|'manage')` or `admin_require_role('admin')`.
  **Approved sales agent change (12 Oct 2026) — built, owner to install:**
  "which link brought each chat" (widget keeps utm_* tags 30 days in
  localStorage `aisa_arrival`; 7 new `conversations` columns; "Came from" row).
  **The AI Sales Agent chat must read `docs/handover/2026-10-12-social-attribution.md`
  carefully** (files, reasons, purpose, what /social reads) and
  `docs/sales-agent-status-2026-10-12.md`. Package
  `salesagent-upgrade-2026-10-arrival.zip`, step `2026_10_conversation_arrival`.
- **Social Media Agent at `/social` — plan APPROVED by the owner (Kaleem,
  10 Oct 2026):** Claude doc "AI Social Media Expert Agent — Plan"
  (https://claude.ai/code/artifact/f54d7319-1688-4ef4-8d69-67690608eebf).
  - Four stages. **Stage 1 is BUILT and INSTALLED live (1.0.0, 10 Oct 2026):** the core
    cycle with manual posting, for Build Asia.
    - **1.1.0 built and INSTALLED live 12 Oct 2026** (with blog 1.2.3):
      articles written directly in the blog admin are picked up (signed notice
      or cron check with a (published_at, id) cursor that starts at the blog's
      newest article, so the archive is never posted) and get draft posts;
      setting `blog_written_posts`; dashboard card "Waiting for approval in the
      blog" from the blog's `GET /pending`. Blog-written articles are read-only
      in /social (`sm_articles.origin = 'blog'`).
    - **Stage 2 BUILT and INSTALLED live 12 Oct 2026 (owner):** blog 1.3.0 (Industry Voices:
      `core/Voices.php`, `{p}contributors`, `{p}submissions`, /industry-voices/,
      /write-for-us; sponsored articles: `core/Sponsored.php`, `sponsor_*` columns,
      `{p}article_clicks`, go/a/ signed click links, admin "Sponsored articles" +
      report) and /social 1.2.0 (credits from the blog payload `voice`/`sponsor`
      in `sm_articles.voice/sponsor`; sponsored posts must carry
      "Sponsored · [sponsor]"; tag reminders; Event settings `report_emails`).
    - Code: `public_html/social/`, version 1.4.0, package
      `social-agent-1.4.0.zip`, guide `docs/social-installation-guide.md`.
    - Pages are plain .php. Private code is in `lib/` (blocked from the web).
      Its own tables are named `sm_` (`lib/SmSchema.php`, created on first
      visit).
    - It reuses the sales agent's bootstrap, login (`aisa_admin` session),
      `Access`, AI providers, Tavily and mail profiles, all read-only, and
      never writes to sales agent tables.
    - It writes into the event blogs only through their API (`lib/SmBlog.php`).
      It receives the blog's signed notice at `hook.php`. Its cron job is
      `cron.php`.
    - Tests: `php public_html/social/tests/acceptance.php` (250; set `SM_SHOTS=1` for screenshots). They use real
      sign-ins, a real test blog (database `smblog`) and stand-in AI and
      research. An independent review was done and every finding was fixed.
  - **Stage 3 BUILT and INSTALLED live 12 Oct 2026 (/social 1.3.0, owner); the owner has
    done the Platform apps step and started the Meta/LinkedIn/X applications.** Owner's
    choices (12 Oct): X both ways (by hand free, or automatic pay-per-use with a
    monthly cap `x_auto_limit`); exhibitor list all three ways (website page read
    by AI with on-page checks + weekly refresh, typed, CSV/XLSX upload), admin
    chooses (`exhibitor_source`); build all five parts, then apply for approvals.
    Code: `lib/SmApps.php` (platform apps in sm_meta, encrypted; Graph v26.0,
    LinkedIn 202609, optional Meta config_id), `SmConnect.php` (OAuth, PKCE for X,
    encrypted tokens in `sm_connections`, refresh), `SmPublish.php` (FB/IG/LI/X,
    claim lock, 3 tries, failure email), `SmNet.php` (test hook, publicUrl SSRF
    guard), `SmExhibitors.php` + `exhibitors.php`, public `kit.php`, `r.php`
    (counted link → register_url with UTM), `SmRadar.php` (weekly, research limit
    `research_limit`, curated posts with `sm_posts.link_url`), `oauth.php`,
    public `privacy.php`. Approval guide: Claude doc "Stage 3 — Applying to Meta,
    LinkedIn and X" (https://claude.ai/code/artifact/c647753a-0b73-4ef4-afbf-2cd18471edb7).
    Facts checked
    12 Oct 2026: X has no free API tier since Feb 2026 (pay per use, about
    $0.015 a post, $0.20 a post with a link); LinkedIn company-page posting
    needs the Community Management API (business review, no fixed time);
    Meta needs app review (screencast, test account) and usually Business
    verification.
  - **Stage 4 BUILT 12 Oct 2026 (/social 1.4.0 + blog 1.4.0 + sales agent
    arrival upgrade); owner to install the three packages (order: blog, sales
    agent, social).** Owner's approvals (12 Oct): the sales agent change above;
    X paid reads ("yes, I X provide the monetization"). Code: `lib/SmStats.php`
    (figures read 1/7/28 days after automatic posts — FB reactions/comments/
    shares, IG like/comments_count, LinkedIn organizationalEntityShareStatistics
    with rw_organization_admin, X `GET /2/tweets?ids=…&tweet.fields=public_metrics`
    counted in `sm_stat_reads` against `x_read_limit` (default 300, ~$0.005/read);
    typed figures for hand posts; blog `GET /stats` cached in `sm_blog_stats`;
    chats/leads read from `conversations.utm_*` + `leads.exhibitor_intent`;
    month report emailed via the exhibition's mail profile, `sm_reports`),
    `results.php` (printable), Results card on post.php, dashboard "This month so
    far", cron (collectDue, refreshAllBlogs, sendDue: last month's report from
    the 1st, 8 am event time, until the 10th, retry every 6 h).
  - **Future plan (owner, 12 Oct 2026): Pharma Asia** as the second event — its
    website is not ready (the event is too close); add it when the renovated
    site exists (install the blog, connect /social, fill its Event settings).
  - **After Stage 4:** all four stages are built. Remaining: owner installs
    Stage 4; Meta/LinkedIn/X approvals in progress (then Connect pages).
  - **Choices made with the approval:**
    - Stage 3 waits for free direct approval from Meta and LinkedIn; no paid
      posting service.
    - AI-generated pictures are switched on, with a monthly limit per event.
    - **The monthly management report (Stage 4) goes to the admin** (owner,
      12 Oct 2026), at the email addresses typed in each event's /social
      Event settings → Monthly report (`report_emails`).
  - Agreed points
  and open questions: `docs/ecosystem-plan-discussion.md` (§4, §6b). The end
  of the earlier chat is saved in `docs/reference/previous-chat-2026-10-09.md`.
- Event websites are plain HTML/PHP on cPanel **shared** hosting. Build Asia's
  new site is being built in a separate chat (review copy at
  buildasia.ecgateway.net, then buildasia.net); Pharma Asia
  (pharmaasia.com.pk) is due for renovation, not yet started.
- The owner communicates in short, informal English and is not technical:
  reply in plain words, keep discussion answers focused, and ask only real
  decision questions.

## The owner

- Not technical. Their concern is operation and the admin screens. Take all
  technical decisions; explain outcomes in plain words.
- Only stop to ask at real decision points: business rules, trade-offs with
  commercial consequences, or things the spec does not cover.

## Fixed constraints

- PHP 8 + MySQLi, no framework, no Composer, no daemons, no root. cPanel.
  Cron is the only background mechanism.
- Platform on its own cPanel account (salesagent.ecgateway.net, dedicated
  server). Exhibition websites are elsewhere and only embed the widget.
- The AI converses. PHP and MySQL decide prices, country, eligibility, state,
  permissions, sending and audit.

## Universal rules — apply to every relevant future task

See `docs/universal-rules.md` for the full text. In short:

1. **Research before selling, scaled by channel and interest.**
   - Email: research the company and decision maker before every email;
     classify (exhibitor / sponsor / business prospect / other); pitch the
     best-fitting package, stall or sponsorship.
   - Website chat: start light — first name, company, country, and
     "interested in exhibiting / visiting". Visitors get thanks and help —
     then, starting from their relevance to the exhibition, at most a light
     suggestion of the conference (if the event has one) or of exhibiting
     (only if their business fits). Exhibitors trigger the same research and
     a tailored pitch.
   - WhatsApp: first message is a thank-you, with at most a light pitch if
     relevant; deep research only once they are a serious exhibitor.
2. **Country (chat and WhatsApp): what the visitor says first, form second,
   other reliable information third, IP address last** — and only when a
   price must be shown. Never use IP once the visitor has given a country.

## Business facts (given by the owner, 29 September 2026)

1. **Every WhatsApp number has its own API key.** A TextMeBot key belongs to
   one linked phone, so the key is stored per exhibition, next to its number —
   never one key for the whole platform.
2. **Local and international sales reps are different people**, each with
   their own contact details: name, designation, email, mobile number,
   WhatsApp number and WeChat. Whoever the platform points a visitor or lead
   to must match the visitor's region (Pakistan / undetermined = local,
   everyone else = international), the same split already used for
   notification emails.
   - **One local and one international rep per exhibition.**
   - **Visitors are given the rep's full details whenever any "needs a person"
     rule applies**: asked for a person or a call, discount, complaint,
     contract/invoice/payment/refund, or the assistant cannot answer — on
     WhatsApp and the website chat alike. When the country is not yet known,
     both reps are given, labelled. Never given for ordinary questions.

## Business facts (given by the owner, 30 September 2026) — Prospect Discovery

3. **Every exhibition has its own email sending profile**: From name, From
   email and its own mail server (SMTP) settings. Examples:
   - Build Asia — "Build Asia Secretariat" <info@buildasia.net>, its own SMTP
   - Pharma Asia — "Pharma Asia Secretariat" <info@pharmaasia.com.pk>, its own SMTP
   Never send one exhibition's email through another's profile.
4. **Pacing is set per exhibition, like the WhatsApp settings**:
   - Research: how many companies researched per day, and the gap between
     one search and the next.
   - Email: the interval between emails (minutes) and emails per day.

## Reference

- Extension spec: the owner's `AI_Sales_Agent_Extensions_Master_Prompt.md`
  (prospect discovery and WhatsApp).
- Research and prospecting method: `docs/reference/prospecting-method-example.md`
  (written for Pharma Asia, applied to every exhibition). Its scoring,
  fact/inference/recommendation split, source tiers, decision-maker order and
  "never guess a contact" rules shape the company research.
- Guides: `docs/deployment-guide.md`, `docs/operating-manual.md`,
  `docs/upgrade-2026-09-whatsapp.md`.
- Tests: `php tests/acceptance.php` (start MariaDB first). The suite
  truncates the database it runs on — never point it at a live installation.
- Beginner's user manual: `docs/user-manual.html`.
- Event Blog: `blog/` (see Current status). Its API is the only way the
  Social Media Agent writes into a blog.
- Ecosystem plan: `docs/ecosystem-plan-discussion.md`;
  the owner's draft (not agreed, reference only):
  `docs/reference/marketing-agent-plan-draft.md`.
