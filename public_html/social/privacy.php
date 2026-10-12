<?php
declare(strict_types=1);

/**
 * Public privacy policy and data deletion instructions for the AI Social Media
 * Expert Agent — the pages Meta, LinkedIn and X ask for when they review our
 * apps. No sign-in needed.
 */
require_once __DIR__ . '/lib/boot.php';

$company = SmApps::company();
$email = SmApps::contactEmail();
$updated = '12 October 2026';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacy policy — AI Social Media Expert Agent</title>
<style>
body{font:16px/1.6 system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1b1f24;background:#f6f7f9;margin:0}
main{max-width:760px;margin:0 auto;padding:32px 20px 64px;background:#fff;min-height:100vh}
h1{font-size:1.8rem;margin:0 0 4px}h2{font-size:1.2rem;margin-top:2rem}p,li{color:#2c333a}.muted{color:#5b6570;font-size:.92rem}
</style></head><body><main>
<h1>Privacy policy</h1>
<p class="muted">AI Social Media Expert Agent, operated by <?= e($company) ?>. Last updated <?= e($updated) ?>.</p>

<p>The AI Social Media Expert Agent is an internal tool used by the organisers of trade exhibitions (for example Build Asia and Pharma Asia) to prepare, approve and publish posts on the exhibitions' <strong>own</strong> Facebook pages, Instagram business accounts, LinkedIn company pages and X accounts. It is not used by the public and does not collect information from people who read the posts.</p>

<h2>What we receive from the platforms</h2>
<ul>
  <li>When an organiser connects a page, the platform gives us a permission (an access token) to publish on that page, together with the page's name and number. The token is stored encrypted on our own server.</li>
  <li>The names of the pages the organiser manages, so the organiser can choose the event's page. Pages that are not chosen are not kept.</li>
  <li>The identifiers and addresses of the posts we publish, so the team can open them.</li>
</ul>
<p>We do not read or keep anyone's messages, friends, followers or personal profile, and we never see or store a password.</p>

<h2>How it is used</h2>
<p>Only to publish posts that a member of the organiser's team has approved, at the time they chose, on the page they connected — and to show them that the post went out. Nothing is sold, shared with advertisers or used for any other purpose.</p>

<h2>Who can see it</h2>
<p>Only the organiser's signed-in staff for that exhibition. Tokens are never shown on screen.</p>

<h2>How long it is kept</h2>
<p>A connection is kept until the organiser presses <em>Disconnect</em>, the permission is removed on the platform, or it expires. Records of published posts are kept for the exhibition's reporting.</p>

<h2 id="deletion">Deleting your data</h2>
<p>To remove the connection and everything received from a platform:</p>
<ol>
  <li>In the agent, open <em>Blog and platforms</em> and press <em>Disconnect</em> for that platform. The stored permission is deleted at once.</li>
  <li>On the platform itself you can also remove the app: Facebook → Settings → Business integrations; LinkedIn → Settings → Data privacy → Permitted services; X → Settings → Security and account access → Connected apps.</li>
  <li>Or email <?= $email !== '' ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : 'the organiser' ?> with the page's name and we will delete the connection and related records within 30 days and confirm by email.</li>
</ol>

<h2>Contact</h2>
<p><?= e($company) ?><?= $email !== '' ? ' · <a href="mailto:' . e($email) . '">' . e($email) . '</a>' : '' ?></p>
</main></body></html>
