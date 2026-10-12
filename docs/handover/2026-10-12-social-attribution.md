# Handover to the AI Sales Agent chat — "Which link brought this chat?" (12 October 2026)

**AI Sales Agent chat: please read this whole document carefully before your
next piece of work on the sales agent.** It describes a small change that the
/social chat made *in the sales agent's own code*, with the owner's explicit
approval. You own the sales agent, so you need to know exactly what changed,
why, and what must stay stable from now on.

- **Approved by:** the owner (Kaleem), 12 October 2026, in the /social chat:
  "I Approve it, but leave the proper note for AI Sales Agent chat what files
  you had make changes, reason and purpose …".
- **Made by:** the /social chat, in the same GitHub repository.
- **Upgrade package:** `salesagent-upgrade-2026-10-arrival.zip` (only the
  changed files). Owner's install note: `docs/upgrade-2026-10-arrival.md`.
- **Database upgrade:** one new step, `2026_10_conversation_arrival`.
- **Tests:** `php tests/acceptance.php` → 1,207 pass (6 new, at the end of
  the file, before the access clean-up). `php tests/access-http.php` → 82
  pass. Nothing else in the suite changed.

---

## 1. What we want to achieve (the purpose)

The Social Media Agent's **Stage 4 ("results")** must show the owner, per
exhibition and per month, what social media actually brought:

```
social post  →  visit to the blog/website  →  chat with the assistant  →  lead
```

and send it as a **monthly management report** by email (to the addresses in
/social → Event settings → Monthly report).

The first two steps are already measurable without you:

- Every link /social puts in a post carries tags, e.g.
  `…/blog/article/?utm_source=facebook&utm_medium=social&utm_campaign=buildasia&utm_content=post-42`
  (`utm_campaign` = the exhibition's slug, `utm_content` = `post-<id>` of the
  /social post). Exhibitor-kit links use
  `utm_medium=exhibitor-share`, `utm_content=exhibitor-<id>`.
- The Event Blog counts visits with those tags (blog 1.4.0).

**The gap was the third step.** A visitor lands on a blog article with the
tags, reads, perhaps clicks to another page, then opens the chat. The sales
agent stored only `conversations.page_url` (the page where the chat was
opened), so the tags were usually lost and no chat or lead could be credited
to a post. That is why this change was needed.

## 2. What changed — every file, with the reason

| File | Change | Reason |
|---|---|---|
| `public_html/widget/widget.js` | New functions `rememberArrival()` and `readArrival()` (just above `el()`), called once when the script loads. `startSession()` now also sends `arrival: readArrival()` to `session.php`. | When a page is opened with `utm_*` tags, the widget keeps them in the website's own localStorage under the key **`aisa_arrival`** for **30 days**, so they are still known when the chat starts on a later page. |
| `public_html/api/session.php` | Passes `'arrival' => $input['arrival'] ?? null` to `ConversationStore::start`. | Hands the tags to the store. |
| `app/domain/ConversationStore.php` | New `public static function arrivalFields(mixed $a): array`. `start()` merges its result into the `conversations` insert. | Cleans the values (see §3) and writes them only when the upgrade step has run. |
| `app/domain/Migrations.php` | New last step `2026_10_conversation_arrival`: adds 7 columns and one index to `conversations`. | New columns on a live database. |
| `app/schema.sql` | The same 7 columns and index in `CREATE TABLE conversations`. | Fresh installs. |
| `public_html/admin/conversation.php` | One extra row, **"Came from"**, in the conversation details (e.g. `facebook · social · post-42 · via l.facebook.com`). Shown only when known. | So the team can see it too. |
| `tests/acceptance.php` | 6 new checks (columns exist, tags stored, odd/old values dropped, nothing stored without tags, the widget sends them). | Proof. |

**Nothing else changed.** No change to prompts, pricing, country rules, lead
rules, WhatsApp, emails, permissions or any other page. `contact.php` is
unchanged (a contact form inside a chat is already linked to that chat).

### The new columns on `conversations`

| Column | Type | Meaning |
|---|---|---|
| `utm_source` | VARCHAR(100) NULL | e.g. `facebook`, `instagram`, `linkedin`, `x`, `whatsapp`, `email` |
| `utm_medium` | VARCHAR(100) NULL | `social` (our posts), `exhibitor-share` (exhibitor kits), `share` (blog share buttons) |
| `utm_campaign` | VARCHAR(150) NULL | the exhibition's slug (`agents.slug`) |
| `utm_content` | VARCHAR(150) NULL | `post-<sm_posts.id>` or `exhibitor-<sm_exhibitors.id>` |
| `landing_url` | VARCHAR(500) NULL | the page they first landed on (no query string) |
| `referrer_host` | VARCHAR(190) NULL | outside site that sent them, e.g. `l.facebook.com`, `t.co` |
| `arrived_at` | DATETIME NULL (UTC) | when they landed |
| index `idx_conv_campaign` | (agent_id, utm_campaign, utm_content) | fast counting per post |

## 3. Rules the widget and the store follow

- **Which visit counts:** the most recent visit that carried tags, within 30
  days ("last tagged visit"). A visit with no tags never removes tags already
  kept. A visit from an outside site with no tags is kept only when no tags
  are held (so "came from facebook.com" still shows for people who shared a
  link by hand).
- **Same shape everywhere:** `{"utm_source","utm_medium","utm_campaign",
  "utm_content","landing_url","referrer_host","at"}` where `at` is Unix
  seconds. The Event Blog (1.4.0) writes the same key in the same shape the
  moment an article opens, before the chat script loads, so a quick reader is
  not missed.
- **Cleaning (server):** a tag is kept only if it is letters, digits, spaces
  and `._~:/+-`, cut to the column size; `landing_url` must start with
  `http(s)://`; `referrer_host` must look like a host name; `at` must be within
  the last 31 days. Anything else becomes NULL. If neither a source nor a
  referrer remains, nothing is written.
- **Before the database upgrade is run,** `arrivalFields()` returns nothing,
  so chats keep working exactly as before.
- **Privacy:** only link tags and a host name are kept — no personal data, no
  cookies, nothing sent to a third party. The website's own localStorage is
  used, as for `aisa_visitor_id`.

## 4. What /social reads (read only) — please keep stable

/social still never writes to sales agent tables. For the report it now
**reads**:

- `conversations`: `id, agent_id, lead_id, channel, intent, created_at,
  utm_source, utm_medium, utm_campaign, utm_content, referrer_host`
- `leads`: `id, agent_id, exhibitor_intent, status, created_at`

It counts, per exhibition and month: chats whose `utm_campaign` is the
exhibition's slug and whose `utm_medium` is `social` or `exhibitor-share`;
how many became leads (`lead_id` not null); and splits them into exhibitor
interest (`exhibitor_intent` in exhibitor/sponsor/partner) and the rest.

**Please do not rename or remove these columns, or change the meaning of
`utm_*`, without telling the /social chat.** If you ever rebuild the widget,
keep `rememberArrival()`/`readArrival()`, the key `aisa_arrival`, and the
`arrival` field sent to `session.php`. Adding things is fine.

## 5. Open points for you (not done, not needed now)

- Leads created by the offline contact form *without* a chat have no tags.
  They are rare (the form appears without a chat only when the assistant is
  unavailable). If the owner later wants them counted, the same
  `arrivalFields()` values could be stored on `leads`.
- WhatsApp chats have no web link, so they are never credited to a post.
