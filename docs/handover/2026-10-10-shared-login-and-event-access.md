# Change request for the AI Sales Agent: shared login, per-event staff, platform switcher

**From:** the Event Blog / Social Media Agent chat.
**Date:** 10 October 2026.
**Status:** **approved by the owner** on 10 October 2026, in his words:

> The same strategy must apply on the Sales agent platform: event staff see only their events in both platforms. Switcher must display on both sides.

This is an approved change to the sales agent. Read `CLAUDE.md` and `docs/sales-agent-status-2026-10-09.md` first. Change nothing else.

---

## 1. Why

A second platform, the **AI Social Media Expert Agent**, will live at `salesagent.ecgateway.net/social`. It is not built yet; its plan is waiting for approval.

The owner wants:
- one login for both platforms;
- a switcher at the top of both platforms;
- users who can be limited to specific exhibitions, on both platforms.

Today every sales agent account is a full admin (`admin_users.role` is only `'admin'`).

## 2. What the sales agent should have afterwards

### 2.1 Two kinds of user

| Kind | `admin_users.role` | Can do |
|---|---|---|
| **Super admin** | `admin` (all existing accounts stay this) | Everything on both platforms, on all exhibitions. Manages users. |
| **Staff** | `staff` (new value) | Only what their access rows allow (§2.2): per platform and per exhibition. |

### 2.2 Access rows: one shared table, owned by the sales agent

Create this table in a sales agent migration (for example step `2026_10_shared_access`):

```sql
CREATE TABLE IF NOT EXISTS admin_user_access (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,            -- admin_users.id
  platform   ENUM('sales','social') NOT NULL,
  agent_id   INT UNSIGNED NOT NULL,            -- agents.id (an exhibition)
  role       VARCHAR(32) NOT NULL,             -- see below
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access (user_id, platform, agent_id),
  KEY idx_agent (agent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Roles.**
- **Sales roles** (`platform = 'sales'`) are yours to define. A suggestion:
  - `manager`: everything for that exhibition, such as knowledge, FAQs, pricing, conversations, leads, people, WhatsApp, prospects and research.
  - `viewer`: read-only conversations, leads and analytics for that exhibition.
- **Social roles** (`platform = 'social'`) are stored by you but only interpreted by /social: `admin`, `approver`, `editor`, `viewer`. Just save whichever one is chosen.

**Pages that stay super-admin only:**
- platform settings and AI/API keys;
- Users;
- upgrade;
- the global emergency stop;
- creating or deleting exhibitions;
- anything that spans all exhibitions and can't be filtered.

### 2.3 Event scoping everywhere in the sales agent

For staff:
- **Lists:** `admin_agents()` returns only exhibitions with a `sales` access row.
- **Switching:** `admin_agent()` refuses an `?agent=` that isn't allowed. It falls back to the first allowed exhibition, or shows "You have no exhibitions on this platform".
- **Single records:** every page that opens a record by id must check that the record's `agent_id` is allowed. That covers conversation, lead, person, prospect, company, research, message and anything else. If it isn't allowed, show a "Not permitted" page, not the record.
- **Cross-exhibition pages** must filter to the allowed exhibitions: person search, analytics, activity log, registrations, suppression, outbound log.
- **Downloads and exports** follow the same rules.
- **Tests:** add tests proving that staff on exhibition A cannot see or change anything of exhibition B, both by URL and by form.

### 2.4 Users screen (super admin)

The super admin can:
- create staff users;
- tick **AI Sales Agent** and/or **AI Social Media Expert Agent**;
- for each ticked platform, choose the exhibitions and a role per exhibition.

Also on this screen:
- deactivate a user;
- reset a password using the existing reset mechanism.

**Login:** keep the existing `must_change_password` flow for new users.

### 2.5 Login, sign-out and the session: a contract /social will rely on

Keep these **stable**. /social reads them and never writes sales agent tables.

| Item | Value |
|---|---|
| Session cookie | name `aisa_admin`, path `/`, as `Security::startSession()` does today |
| Signed-in user | `$_SESSION['admin_id']` |
| Current exhibition (shared by both platforms) | `$_SESSION['agent_id']` |
| Timeouts | `Security::IDLE_TIMEOUT` and `Security::ABSOLUTE_TIMEOUT`, applied by `Security::startSession()` |
| Login page | `/admin/login.php?next=/social/...` must keep working. It already accepts same-site paths. |
| Sign-out | `/admin/logout.php` signs out of both platforms |

**After login:**
- A user with **no sales access but social access** goes to `/social/`.
- If such a user opens any `/admin/` page, redirect them to `/social/`.
- A user with no access at all sees "Your account has no access yet — ask the administrator".

The login page heading should be neutral, for example "Sign in" with "AI Sales Agent · AI Social Media Expert Agent" underneath.

### 2.6 A small read-only helper for /social

Add `app/lib/Access.php` (or put it where your conventions say). /social will `require` it and call it **read-only**:

```php
final class Access
{
    /** Is this user a super admin? */
    public static function isSuper(int $userId): bool;
    /** ['sales' => bool, 'social' => bool]; super admins get both true. */
    public static function platforms(int $userId): array;
    /** Allowed agent ids for a platform; super admins get every non-deleted agent. */
    public static function agentIds(int $userId, string $platform): array;
    /** Role on one exhibition for one platform; 'admin' for super admins, null when none. */
    public static function role(int $userId, string $platform, int $agentId): ?string;
}
```

It must only `SELECT`, and must not depend on admin page code (`_admin.php`). The sales agent's own scoping should use it too, so both platforms apply the same rules.

### 2.7 The platform switcher: same on both sides

**When it shows:**
- Only for users with access to **both** platforms.
- It sits at the very start of the top bar (`header.topbar`), before the page title.
- It is a two-part segmented control. The current platform is highlighted.

**Markup:** use this exact markup so both platforms look and behave the same.

```html
<nav class="platform-switch" aria-label="Platform">
  <a href="/admin/" aria-current="page">AI Sales Agent</a>
  <a href="/social/">AI Social Media Expert Agent</a>
</nav>
```

**Styling:**
```css
.platform-switch{display:inline-flex;border:1px solid var(--line,#d0d5dd);border-radius:999px;overflow:hidden;margin-right:12px;flex-shrink:0}
.platform-switch a{padding:6px 14px;font-size:13px;font-weight:600;text-decoration:none;color:inherit;white-space:nowrap}
.platform-switch a[aria-current=page]{background:var(--accent,#1f4fbf);color:#fff}
@media (max-width:700px){.platform-switch a{padding:6px 10px;font-size:12px}}
```

Put `aria-current="page"` on the link for the platform you are on. On small screens it may wrap above the title.

**Keeping the exhibition:** the current exhibition is `$_SESSION['agent_id']`, which both platforms share. Switching therefore keeps the same exhibition, provided the user is allowed it on the other platform. If they aren't, the other platform falls back to the first one they are allowed.

### 2.8 Things not to do

- Do not create, alter or drop any table starting with `sm_`. Those belong to /social, in the same database.
- Do not add anything about social media to the sales agent beyond the switcher, the Users screen ticks and `Access.php`.
- The `/social` folder will sit in `public_html/social/`. The sales agent's upgrade and install packages must not delete or overwrite it.

## 3. Delivery

- Deliver it as a normal sales agent upgrade:
  - existing accounts become super admins automatically, so nothing changes for the owner on day one;
  - Database upgrade adds the table and the `staff` role;
  - add an upgrade note `docs/upgrade-2026-10-shared-access.md` in plain words.
- All existing tests must still pass, plus the new access tests.
- Update `docs/sales-agent-status-2026-10-09.md` (or a new dated status note) and `CLAUDE.md`.
- Commit to GitHub (`faisalecg-ops/ai-sales-agent`).
- Finish by adding a short section **"Done — what /social can rely on"** at the end of this file. It should give:
  - the final role names;
  - the migration step name;
  - the `Access.php` path and signatures, plus any deviation from this request.

  The /social chat will read that section before building.
