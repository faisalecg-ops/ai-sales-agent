# Sales agent upgrade — October 2026: one login, staff per exhibition, platform switch

Approved by the owner on 10 October 2026. Nothing the assistant says to
visitors changes, and nothing changes for anyone on the day you install it:
every existing account becomes a **super admin** and keeps full access.

## What it adds

- **Two kinds of user.**
  - **Super admin**: everything, on both platforms, on every exhibition. Every
    account that exists today becomes one.
  - **Staff**: only the platforms and exhibitions you give them.
- **Roles on the AI Sales Agent, per exhibition.**
  - **Manager**: everything for that exhibition. That covers knowledge, FAQs,
    prices, sponsorship, the review queue, conversations, leads, people,
    registrations (collecting only), WhatsApp, prospecting, companies,
    research, standard messages, do-not-contact and the activity log.
  - **Viewer**: reads the dashboard, leads, conversations, people and
    analytics, and can change nothing.
- **Roles on the AI Social Media Expert Agent, per exhibition**: admin,
  approver, editor or viewer. They are saved here and used by that platform
  once it is installed.
- **Set-up → Users** (super admin only). There you can:
  - create users and tick the platforms they may use;
  - choose their exhibitions and a role on each one;
  - deactivate someone, which signs them out of both platforms at their next
    click;
  - give a **temporary password** to someone who has forgotten theirs.
- **Choosing a password.** A new user, or one given a temporary password, must
  choose their own password at their first sign-in. Everyone can change their
  password under **Your account**, at the bottom of the menu.
- **One sign-in for both platforms.** The sign-in page now says "Sign in —
  AI Sales Agent · AI Social Media Expert Agent". Signing out signs out of
  both.
- **The platform switch.** "AI Sales Agent | AI Social Media Expert Agent"
  appears at the top of every page. It only shows for people who may use both
  platforms, and only once the social platform is installed in
  `public_html/social/`. Until then nothing new shows at the top.

## What stays for the super admin only

- Settings: AI keys and the other platform settings.
- Users.
- Database upgrade.
- The emergency stop, and resuming after it.
- **Agents and embed**: creating and editing exhibitions. This page holds each
  exhibition's WhatsApp key and mail password.
- The registration database connection, and which event numbers belong to each
  exhibition. These decide whose visitors are collected, so a manager can't
  change them.

## What staff see

- **Only their own exhibitions.** The exhibition switcher lists only theirs,
  and typing another exhibition's address shows nothing of it.
- **A shorter menu.** It shows only the pages they may open. A page they may
  not open says "Not permitted".
- **Plain warnings.** Where a warning needs a setting they can't reach, it says
  "Please tell the administrator".

## Files changed

| File | Change |
|---|---|
| `app/domain/Migrations.php` | Two new upgrade steps: `2026_10_staff_role` (adds the staff kind of user) and `2026_10_shared_access` (new table `admin_user_access`) |
| `app/lib/Access.php` | **New.** Read-only rules for who may open which exhibition, used by both platforms |
| `app/domain/Analytics.php` | The "every exhibition" table shows staff only their own |
| `public_html/admin/_admin.php` | Exhibition limits, read-only viewers, menu, platform switch, "Your account" link |
| `public_html/admin/users.php` | **New.** The Users screen |
| `public_html/admin/account.php` | **New.** Your account / choose your password |
| `public_html/admin/login.php` | Neutral heading; goes to the right platform after signing in |
| `public_html/admin/index.php` | Dashboard shows only tiles the person can open; staff-friendly warnings |
| `public_html/admin/registrations.php` | Connection and event numbers for the super admin only |
| `public_html/admin/whatsapp.php` | Emergency stop button for the super admin only |
| `public_html/admin/analytics.php` | Passes the person's exhibitions to the "every exhibition" table |
| 17 other admin pages | One line each: which level of access opens them |
| `public_html/assets/admin.css` | Styles for the platform switch and sign-in page |
| `tests/` | 22 new rule checks, and `tests/access-http.php`: 82 end-to-end checks |

The package contains **no** `public_html/social/` folder. Unzipping it over
the site never touches the social platform, and never touches `config/`.

## How to install

1. Back up the database (cPanel → phpMyAdmin → Export) as usual.
2. Upload `salesagent-upgrade-2026-10-shared-access.zip` to the platform folder
   (the one containing `app`, `public_html` and `config`) and extract it
   there, replacing the existing files.
3. Sign in. Open **Set-up → Database upgrade** and press the button. It shows
   **2** steps.
4. Open **Set-up → Users**. Your own account is listed as a super admin. Add
   staff when you're ready.

## Tested

- All 1,179 earlier acceptance checks pass, plus 22 new ones.
- 82 end-to-end checks sign in as staff and try to reach another exhibition's
  leads, conversations, people, companies, knowledge, do-not-contact entries,
  activity and analytics. They try both by typing addresses and by submitting
  forms. All were refused.
- All 265 earlier screen checks pass, plus 21 new ones for the new screens, on
  desktop and phone.
- The upgrade was proven on a copy of the database in today's live state: 2
  steps, existing accounts stay super admins, and a second run changes nothing.
- A fresh install still works.
