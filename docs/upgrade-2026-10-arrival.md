# Sales agent upgrade — October 2026: which link brought each chat

Approved by the owner on 12 October 2026. Nothing the assistant says to
visitors changes.

## What it does

When someone clicks one of your social media posts (or an exhibitor's share
link) and later opens the chat on your website, the sales agent now remembers
which post brought them. The Social Media Agent uses this for its monthly
report: **post → visit → chat → lead**.

You can also see it yourself: open any conversation and look for the new row
**"Came from"** (for example `facebook · social · post-42`).

## How to install

1. Upload `salesagent-upgrade-2026-10-arrival.zip` in cPanel → File Manager to
   the folder that holds `app` and `public_html`, and choose **Extract**
   (allow it to replace files).
2. Sign in to the sales agent as a super admin and open **Set-up → Database
   upgrade**. Run the one new step: *"Remember which link (social post,
   exhibitor kit, email) brought each chat visitor"*.
3. That's all. Chats started from now on show where they came from.

The zip contains only these files:

- `public_html/widget/widget.js`
- `public_html/api/session.php`
- `public_html/admin/conversation.php`
- `app/domain/ConversationStore.php`
- `app/domain/Migrations.php`
- `app/schema.sql`

Details for the AI Sales Agent chat:
`docs/handover/2026-10-12-social-attribution.md`.
