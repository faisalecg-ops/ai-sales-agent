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
