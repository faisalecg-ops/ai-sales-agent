# Sales agent — status note, 10 October 2026

Read this, then `docs/sales-agent-status-2026-10-09.md`, before any work on the
sales agent.

## 1. Live platform

- **Live server:** the delivered version plus the event-facts change
  (installed 9 October 2026, see the 9 October note).
- **Installed:** the shared login / staff access upgrade below. The owner
  confirmed on 10 October 2026 that it is installed on the live server and
  the Database upgrade has been run.

## 2. Shared login, staff per exhibition, platform switch — installed 10 Oct 2026

The owner approved it on 10 October 2026. The request is in
`docs/handover/2026-10-10-shared-login-and-event-access.md`. The end of that
file, "Done — what /social can rely on", is the contract for the /social chat.

- **Upgrade note:** `docs/upgrade-2026-10-shared-access.md`
- **Package:** `salesagent-upgrade-2026-10-shared-access.zip`. It contains
  only the changed and new files. There is no `config/`, no
  `public_html/social/`, no blog.
- **Database upgrade:** two steps, `2026_10_staff_role` then
  `2026_10_shared_access`.
- **What it adds:**
  - two kinds of user: super admin (`admin_users.role = 'admin'`, every
    existing account) and staff (`'staff'`);
  - the table `admin_user_access` (user, platform `sales`/`social`,
    exhibition, role);
  - `app/lib/Access.php`, read-only, used by both platforms;
  - **Set-up → Users** and **Your account** (forced password change);
  - the platform switch, which shows only once `public_html/social/index.php`
    exists.
- **Tests:**
  - `php tests/acceptance.php`: 1,201 pass (22 new).
  - `php tests/access-http.php`: 82 pass. These are end-to-end checks with
    real sign-ins and forms; the file starts its own web server.
  - 265 earlier screen checks pass, plus 21 new ones.

## 3. Rules for future sales agent work

- Start from this GitHub repository, never an old zip.
- New admin pages must call `admin_require_access('view'|'manage')`, or
  `admin_require_role('admin')` for super-admin pages. They must also open
  records only within the current exhibition (`admin_agent()`), as every page
  does today. A page that looks across exhibitions must filter to
  `Access::agentIds($userId, 'sales')` for staff.
- Viewers can never submit a form. `admin_require_login()` refuses every POST
  from someone who is not a manager on the current exhibition. Pages need no
  code for this.
- Never create, alter or drop `sm_` tables. They belong to /social.
- The session contract in the handover file (cookie `aisa_admin`,
  `$_SESSION['admin_id']`, `$_SESSION['agent_id']`, login `?next=`, logout) must
  stay stable.

## 4. What /social uses from the sales agent — keep these stable (checked 12 Oct 2026)

/social (`public_html/social/`, 1.2.0) writes **only** its own `sm_` tables. It
changes no sales agent file and creates no sales agent table. Besides the
agreed contract (session, `Access.php`, login/account/logout, the switcher
markup), it also **calls or reads** the parts below. Change any of them only
together with /social, or /social breaks.

| Sales agent part | How /social uses it |
|---|---|
| `app/bootstrap.php`, `db()`, `url()`, `e()`, `cfg()` | loaded by `public_html/social/lib/boot.php` |
| `Security::startSession`, `csrfField`, `requireCsrf`, `htmlHeaders` | its pages and forms |
| `Access::*` | who may open which exhibition on `social` |
| `Audit::setActor` | names the user (it writes its own `sm_activity`, not `audit_log`) |
| `Settings::get('llm.openai.api_key')`, `Settings::get('research.tavily.api_key')` | AI images and web research (read only) |
| `LlmProviderFactory::make`, `AiModels::live`, `AiModels::backgroundProvider` | the AI writer, using each exhibition's provider and models |
| `MailProfile::forAgent`, `MailProfile::problem`, `Mailer::sendWith` | reminder emails sent through the exhibition's own mail server |
| `Crypto::encrypt` / `decrypt` | the blog key and hook secret it stores |
| `Http::request`, `Http::decode`, `Validator::email`, `Validator::url` | blog API calls, form checks |
| `agents` columns (name, slug, event facts, mail profile, AI provider) | read only |
| `admin_users`, `knowledge_articles`, `faq_entries` | read only |
| `public_html/assets/admin.css` and `admin.js` | /social uses the sales agent's look and its classes (cards, buttons, menu, top bar) |

**Test safety (12 Oct 2026):**
- `tests/harness.php` now refuses to run from a browser, and refuses to run
  when `config/config.php` says `'environment' => 'production'`. The tests
  empty their database, and /social's tests load the same harness, so this
  protects the live server in both cases.
- `tests/access-http.php` now runs its web server on a temporary copy of
  `public_html`. Before this fix it created and deleted
  `public_html/social/index.php` to simulate /social, and would have removed
  the real one.
