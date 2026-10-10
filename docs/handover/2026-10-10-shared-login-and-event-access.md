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

---

## Done — what /social can rely on

**Built and tested by the sales agent chat, 10 October 2026.** The upgrade is
in GitHub, but **not yet installed on the live server**: the owner installs
`salesagent-upgrade-2026-10-shared-access.zip`, then runs Database upgrade.
Before then, `admin_user_access` does not exist and every account is a super
admin. `Access.php` handles that without errors: it reports no staff rows.

### Migration steps (run in this order by Database upgrade)

| Key | What it does |
|---|---|
| `2026_10_staff_role` | `admin_users.role` becomes `ENUM('admin','staff')`, default `'admin'`. Every existing account stays `'admin'`, a super admin. |
| `2026_10_shared_access` | Creates `admin_user_access` exactly as in §2.2: same columns, `UNIQUE (user_id, platform, agent_id)` and `KEY idx_agent`. |

A step was added compared with the request, because the `staff` value needed
its own check. The table step keeps the requested key.

To test whether the upgrade has run: `Migrations::tableExists('admin_user_access')`.

### Final role names (`admin_user_access.role`)

| Platform | Roles | Interpreted by |
|---|---|---|
| `sales` | `manager`, `viewer` | the sales agent |
| `social` | `admin`, `approver`, `editor`, `viewer` | **/social** (stored only; the sales agent never interprets them) |

- The Users screen only ever saves these values. Any other value posted to it
  is dropped.
- Labels are available as `Access::SALES_ROLES` and `Access::SOCIAL_ROLES`
  (key ⇒ label).
- A super admin needs no rows. `Access::role()` returns `'admin'` for them on
  both platforms. On `social`, `'admin'` is therefore both the super admin's
  answer and a storable staff role.

### `app/lib/Access.php`

Require `app/bootstrap.php` first; the class autoloads from `app/lib/`. It
only `SELECT`s and does not depend on `_admin.php` or any admin page code.

```php
final class Access
{
    public const PLATFORMS    = ['sales', 'social'];
    public const SALES_ROLES  = ['manager' => '…', 'viewer' => '…'];
    public const SOCIAL_ROLES = ['admin' => 'Admin', 'approver' => 'Approver', 'editor' => 'Editor', 'viewer' => 'Viewer'];

    public static function isSuper(int $userId): bool;
    public static function platforms(int $userId): array;   // ['sales' => bool, 'social' => bool]
    public static function agentIds(int $userId, string $platform): array; // list<int>, in exhibition name order
    public static function role(int $userId, string $platform, int $agentId): ?string;
    public static function forget(): void;                   // clears the per-request cache (tests only)
}
```

Behaviour worth knowing:

- **Deactivated or unknown users** (`active = 0`, or no row) get `isSuper` =
  false, both platforms false, `agentIds` = `[]` and `role` = `null`.
  Signed-in sessions of a deactivated user stop working at the next request
  on the sales agent. /social should check `active` (or `Access::platforms()`)
  on every request too.
- **Deleted exhibitions** (`agents.status = 'deleted'`) are never returned,
  and their roles are `null`, even if rows remain.
- **Disabled exhibitions** (`status = 'disabled'`) are still returned, as
  before. It is up to each platform whether to work on them.
- **Platform access** comes from rows: a staff user may use a platform when
  they have at least one row for a live exhibition on it.
- **Unknown platform names** return `[]` or `null`.
- **Caching:** results are cached for the rest of the PHP request.

### Session and sign-in contract (unchanged and confirmed by tests)

| Item | Value |
|---|---|
| Session cookie | `aisa_admin`, path `/`, started by `Security::startSession('aisa_admin')` |
| Signed-in user | `$_SESSION['admin_id']` |
| Current exhibition (shared) | `$_SESSION['agent_id']` |
| Timeouts | `Security::IDLE_TIMEOUT` (3600 s) and `Security::ABSOLUTE_TIMEOUT` (43200 s), applied by `Security::startSession()` |
| Sign-in | `/admin/login.php?next=/social/...` returns to that same-site path after signing in. It refuses `//host` and backslash tricks. If the person is already signed in, it redirects straight to `next`. |
| Forced password change | If `admin_users.must_change_password = 1`, sign-in goes to `/admin/account.php?next=…` first, then continues to `next`. **/social should do the same**: redirect such a user to `/admin/account.php?next=<this page>`. |
| Sign-out | `/admin/logout.php` destroys the shared session, so both platforms are signed out |
| Your account | `/admin/account.php` is open to every signed-in user, including people with social access only. Link to it from /social for "change my password". |

Routing as built:

- **After sign-in, with no `next`:** a user with social access but no sales
  access goes to `/social/`.
- **Opening any `/admin/` page** (except `account.php` and `logout.php`)
  without sales access: such a user is redirected to `/social/`.
- **No access at all:** the user sees "Your account has no access yet — ask
  the administrator".
- **Social access only, but /social not installed:** the user is told the
  social platform is not installed yet.

**/social must not send a signed-in user back to `login.php`.** That would
loop, because `login.php` sends signed-in users straight on. Show a
"no access" page instead.

### Exhibition switching

`$_SESSION['agent_id']` is shared.

- The sales agent uses it only when the user is allowed that exhibition on
  `sales`. Otherwise it takes their first allowed exhibition (in name order)
  and writes that back to the session.
- `?agent=<id>` for an exhibition the user may not open is ignored.
- /social should apply the same rule with `Access::agentIds($id, 'social')`.

### Platform switcher (deviation: also requires /social to be installed)

- **Markup:** exactly as §2.7. The links are built with `url('admin/')` and
  `url('social/')`, so a subfolder install still works.
- **CSS:** exactly as §2.7, in `public_html/assets/admin.css`.
- **Placement:** first child of `header.topbar`, before the `<h1>`.
- **When it shows:**
  - the user has both platforms; **and**
  - `public_html/social/index.php` exists.

  This keeps the owner from clicking a link that 404s before /social is
  built. Once /social puts its `index.php` in place, the switcher appears
  with no sales agent change. /social shows its own copy with
  `aria-current="page"` on its own link.

### Other deviations from the request

- **Password reset.** §2.4 assumed an existing reset mechanism and a
  `must_change_password` flow. Neither existed (the columns were unused), so
  both were built:
  - The Users screen's **"Give a temporary password"** sets a random
    temporary password, shows it once to the super admin, and sets
    `must_change_password = 1`.
  - `/admin/account.php` forces the change, needs at least 12 characters, and
    clears the flag.
  - No email-based reset was added.
- **Super admins on the Users screen.** A super admin can also create another
  super admin ("Kind of user"). Safeguards:
  - you cannot deactivate yourself;
  - you cannot make yourself staff;
  - you cannot deactivate or demote the last active super admin.
- **What stays super-admin only, beyond §2.2:**
  - **Agents and embed** (exhibition set-up, which holds each exhibition's
    WhatsApp key and SMTP password);
  - **the registration connection and event numbers** (a manager could
    otherwise collect another exhibition's visitors);
  - **resuming after the emergency stop.**
- **Viewer pages.** Viewers may read the dashboard, leads, conversations,
  person pages and analytics. Person pages were added to the suggested list
  because they combine a lead's records.
- **Viewers cannot submit forms.** Every form submitted by someone who is not
  a manager on the current exhibition is refused centrally, in
  `admin_require_login()`.

### Things /social can count on staying true

- **The sales agent never creates, alters or drops `sm_` tables.** A test
  checks this.
- **Upgrade and install packages never contain `public_html/social/`,** so
  installing them never touches /social.
- **Writes to `admin_users` and `admin_user_access` come only from the sales
  agent's Users screen and Your account page.** /social only reads them.

---

## Note from the /social chat (10 October 2026)

/social Stage 1 now exists in the repository at `public_html/social/`. This
affects one sales agent test file. Please fix it the next time you work on
the sales agent; it is test code only and nothing deployed changes.

- **The problem:** `tests/access-http.php` writes a placeholder
  `public_html/social/index.php` and **deletes** it, and the `social` folder,
  when it finishes. Run now, it deletes the real /social `index.php` in your
  working copy. Its checks for "no switch while /social is not installed"
  also no longer hold.
- **Suggested fix:** move any existing `public_html/social/index.php` aside
  before the placeholder test and restore it afterwards (never `rmdir` the
  folder). Alternatively, point those checks at a temporary copy of
  `public_html`.

**/social's own tests:** `php public_html/social/tests/acceptance.php` (113
checks).

- They use the same `satest` database and an extra test blog database,
  `smblog`.
- They remove their `sm_` tables when they finish, so your check that the
  sales agent never creates `sm_` tables still passes.
- `tests/acceptance.php`: 1,201 pass with /social present.
