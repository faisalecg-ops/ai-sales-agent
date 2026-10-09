# AI Sales Agent Platform — standing instructions

Read this before any task on this project.

## Current status (9 October 2026) — read first

- The **Sales Agent is complete** (website chat, WhatsApp, follow-ups,
  research, prospecting, analytics, reorganised admin). 1,179 acceptance
  tests pass. Delivered as an upgrade package and a fresh-install package.
- The owner is now **planning a new ecosystem**: a standalone Event Blog on
  each event website at `/blog` (Phase 1), then a Social Media Agent at
  `/social`. **This is discussion only — build nothing for it until the owner
  approves the final plan in writing.** Everything agreed so far, plus the
  open questions, is in `docs/ecosystem-plan-discussion.md`. Read it before
  discussing the blog or the social agent.
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
- Ecosystem plan under discussion: `docs/ecosystem-plan-discussion.md`;
  the owner's draft (not agreed, reference only):
  `docs/reference/marketing-agent-plan-draft.md`.
