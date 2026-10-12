# Sales agent — status note, 12 October 2026

**AI Sales Agent chat: read this, then
`docs/handover/2026-10-12-social-attribution.md` (carefully, all of it), then
`docs/sales-agent-status-2026-10-10.md`, before any work on the sales agent.**

## 1. What changed in the sales agent today

The owner approved one small change on 12 October 2026, made by the /social
chat for the Social Media Agent's Stage 4 (results):

**"Which link brought this chat?"** The chat widget keeps the `utm_*` tags of
the link a visitor arrived through (a social post, an exhibitor's share link)
for 30 days and sends them when a chat starts; they are stored on
`conversations` in 7 new columns and shown as **"Came from"** on the
conversation page. Nothing the assistant says or decides changes.

- Files: `public_html/widget/widget.js`, `public_html/api/session.php`,
  `public_html/admin/conversation.php`, `app/domain/ConversationStore.php`,
  `app/domain/Migrations.php`, `app/schema.sql`, `tests/acceptance.php`.
- Database upgrade step: `2026_10_conversation_arrival`.
- Package: `salesagent-upgrade-2026-10-arrival.zip`; owner's note:
  `docs/upgrade-2026-10-arrival.md`.
- Tests: `php tests/acceptance.php` 1,207 pass (6 new);
  `php tests/access-http.php` 82 pass.
- **Live status:** built and packaged 12 Oct 2026; the owner installs it
  together with blog 1.4.0 and /social 1.4.0. Until the database step is run,
  chats work exactly as before.

## 2. What /social now reads (read only)

In addition to the list in the 10 October note (§4): `conversations`
(`id, agent_id, lead_id, channel, intent, created_at, utm_source, utm_medium,
utm_campaign, utm_content, referrer_host`) and `leads` (`id, agent_id,
exhibitor_intent, status, created_at`). Keep these stable, and keep the
widget's `rememberArrival()` / `readArrival()`, the localStorage key
`aisa_arrival` and the `arrival` field sent to `session.php`.
