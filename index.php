<?php
declare(strict_types=1);

/**
 * Wrong-folder guard.
 *
 * This file sits at the root of the installation, which should NOT be
 * web-accessible. If a visitor can see this page, the domain's document root
 * is pointing one level too high — at the installation folder instead of at
 * public_html inside it.
 *
 * That arrangement is a security problem, not merely untidy: with the
 * document root here, the config, app and storage folders are also inside the
 * web root, and their protection then depends entirely on .htaccess rules the
 * host may not honour. So this page deliberately does not start the
 * application or touch the database. It explains what to change and stops.
 */

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$folder = basename(__DIR__);

?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Document root needs changing</title>
<style>
  :root{color-scheme:light}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F4F6F9;
    color:#0F2138;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .box{max-width:600px;width:100%;padding:20px;margin:20px}
  .card{background:#fff;border:1px solid #DCE3EC;border-radius:8px;padding:26px 28px}
  p.eyebrow{margin:0 0 6px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;
    color:#7A8899;font-weight:700}
  h1{margin:0 0 14px;font-size:21px;line-height:1.3}
  p{margin:0 0 12px}
  ol{margin:10px 0 14px;padding-left:22px}
  li{margin-bottom:8px}
  code{background:#F4F6F9;border:1px solid #DCE3EC;border-radius:3px;padding:1px 5px;
    font:13px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  .warn{background:#FBF3E6;border:1px solid #EBDCC2;border-left:3px solid #8A5100;
    border-radius:5px;padding:13px 15px;color:#8A5100;margin:0 0 16px}
  .warn p{margin:0}
</style></head>
<body><div class="box"><div class="card">
  <p class="eyebrow">AI Sales Agent Platform</p>
  <h1>The document root needs changing</h1>

  <div class="warn"><p>This folder should not be reachable from the web. Because it is,
  the configuration and application files are sitting inside your website's public area.</p></div>

  <p>The domain is currently pointing at the installation folder
  (<code><?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?></code>). It needs to point at
  the <code>public_html</code> folder inside it.</p>

  <p>To fix it in cPanel:</p>
  <ol>
    <li>Open <strong>Domains</strong> (on older versions, <strong>Subdomains</strong>).</li>
    <li>Find this subdomain and edit its <strong>Document Root</strong>.</li>
    <li>Add <code>/public_html</code> to the end of the path, so it reads
      <code><?= htmlspecialchars($folder, ENT_QUOTES, 'UTF-8') ?>/public_html</code>.</li>
    <li>Save, then reload this address.</li>
  </ol>

  <p>Everything else stays where it is — only the document root changes. Part 1 of the
  deployment guide covers this step.</p>
</div></div></body></html>
