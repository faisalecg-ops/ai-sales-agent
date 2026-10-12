# Event Blog API (v1)

For the Social Media Agent (salesagent.ecgateway.net/social). Each event blog
has its own API at `https://<event-site>/blog/api/v1/`, its own keys and its own
content.

## Basics

- **HTTPS only.** Plain http is refused (`403 https_required`).
- **Key:** created in the blog admin → **API keys**, shown once. Send it as
  `Authorization: Bearer eb_…`. Some cPanel servers drop that header; the blog's
  `.htaccess` restores it, and `X-Api-Key: eb_…` works as a fallback.
- **Permissions per key:**
  - `drafts` (default): every article arrives **awaiting approval**.
  - `publish`: articles may be published or scheduled when the request asks
    (`publish: true` or `publish_at`) **and** every link in the text is from the
    link library, another published article of this blog, or a URL listed in
    `sources`. Otherwise the article is held for approval and `review_note`
    says why.
- **Limits:** 60 requests per minute per key (`429`, `Retry-After: 60`).
  20 failed key attempts from one address block it for 10 minutes.
- **Format:** JSON in and out, UTF-8. Errors:
  `{"ok": false, "error": "<code>", "message": "<plain words>"}`.

| Status | Meaning |
|---|---|
| 200 / 201 | Done |
| 401 | Missing, wrong or switched-off key |
| 403 | Not https |
| 404 | Unknown address, or the article was not created through the API |
| 409 | Duplicate `external_ref`, or the article is published/scheduled (a person must change it) |
| 413 / 422 | Image too large / invalid input (`errors` lists each problem) |
| 429 | Rate limited |

## Endpoints

### `GET /ping`
`{"ok":true,"site":"https://buildasia.net/blog","event":"Build Asia","version":"1.0.0","permission":"drafts"}`

### `GET /categories`
`{"ok":true,"categories":[{"id":1,"name":"Machinery","slug":"machinery"}, …]}`

### `GET /links`
The link library — the approved addresses the article may link to (besides
other articles of the blog and declared sources):
`{"ok":true,"links":[{"label":"Register to visit","url":"https://…","kind":"register","sponsored":false,"keywords":"register, visitor pass"}]}`

Rules for links inside articles: 3–6 is normal; no UTM tags (they are removed
automatically); never invent addresses.

### `POST /media`
Either `multipart/form-data` with `file` (+ optional `alt`, `caption`, `credit`),
or JSON `{"filename":"crane.jpg","data":"<base64>","alt":"…","caption":"…","credit":"…"}`.
JPEG, PNG, WebP or GIF, at most 12 MB. Returns
`{"ok":true,"media":{"id":12,"url":"https://…","width":1800,"height":1000}}` (201).

### `POST /articles`
```json
{
  "title": "Cement exports climb in September",
  "body": "<p>HTML (headings h2–h4, paragraphs, lists, links, quotes, tables, images) or plain text</p>",
  "excerpt": "One or two sentences shown under the title and on cards",
  "category": "market-insights",
  "tags": ["Cement", "Exports"],
  "image_id": 12,
  "seo_title": "Optional, under 60 characters",
  "meta_description": "Optional, 120–155 characters",
  "sources": ["https://source-actually-used.example/report"],
  "external_ref": "sm-2026-10-0042",
  "publish": false,
  "publish_at": "2026-10-12T09:00:00+05:00"
}
```
Required: `title`, `body` (at least 50 characters of text), `category` (id,
slug or name). `external_ref` is the agent's own id: sending the same one
twice returns `409` with the existing `id`. The text is cleaned (no scripts,
styles or unknown embeds; YouTube/Vimeo allowed).

Returns `201` with `{"ok":true,"article":{…},"note":"Awaiting approval by a person."}`.

### `PATCH /articles/{id}`
Same fields, all optional. Only for articles created through the API that are
not yet published or scheduled (otherwise `409 locked`). The article returns
to *awaiting approval* (or is published, under the same rules as above).

### `GET /articles/{id}` and `GET /articles?external_ref=…&status=…`
```json
{"ok":true,"article":{"id":42,"external_ref":"sm-2026-10-0042","title":"…","status":"published",
 "status_label":"Published","url":"https://buildasia.net/blog/market-insights/cement-exports-climb",
 "published_at":"2026-10-12T04:00:00+00:00","publish_at":null,"category":"Market Insights",
 "tags":["Cement"],"excerpt":"…","image_url":"https://…","review_note":null}}
```
`url` is `null` until the article is published. Statuses: `draft` (a person
sent it back — see `review_note`), `pending`, `scheduled`, `published`,
`archived`.

### `GET /published?since=…&after_id=…&limit=…` (from version 1.2.3, read-only)
Every **published** article — including those written by people in the blog
admin — after a position, oldest first (`limit` up to 50, default 20).
`since` is an ISO date-time; with `after_id`, articles published in exactly
that second are listed only if their id is higher, so nothing is skipped.
`GET /published?newest=1` returns just the most recent one (used to start
reading "from now" by the blog's own clock).
```json
{"ok":true,"articles":[{ …same fields as the notice's article…, "body":"<p>…</p>",
 "from_api":false,"author":"Sana Author"}]}
```
`from_api` is `true` when the article was sent through this API.

### `GET /published/{id}` (from 1.2.3)
One published article, with its text. `404` if it is not published.

### `GET /pending` (from 1.2.3)
Articles waiting for an editor in the blog admin — titles only, never the text:
```json
{"ok":true,"articles":[{"id":51,"title":"…","author":"Sana Author","from_api":false,
 "updated_at":"2026-10-12T08:00:00+00:00","admin_url":"https://buildasia.net/blog/admin/?p=article&id=51"}]}
```
These three views only read; approving still happens in the blog admin.

## "Article published" notice (webhook)

Set the agent's address in the blog admin → **API keys → "Article published"
notice**. When any article is published (by a person, by the schedule, or via
the API), the blog sends:

```
POST <notice address>
Content-Type: application/json
X-Blog-Event: article.published
X-Blog-Signature: sha256=<HMAC-SHA256 of the raw body with the signing secret>

{"event":"article.published","site":"https://buildasia.net/blog","sent_at":"2026-10-12T04:00:03+00:00",
 "article":{ …same fields as GET /articles/{id}, without status_label/review_note… }}
```

Verify the signature before trusting the notice. Answer with any 2xx. Failed
deliveries are retried after 1 minute, 5 minutes, 30 minutes, 2 hours, 12
hours and 24 hours (sent by the blog's cron job). Social posts link back to
`article.url` with UTM tags added by the agent; the blog never adds them.
