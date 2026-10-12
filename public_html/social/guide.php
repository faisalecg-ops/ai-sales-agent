<?php
declare(strict_types=1);

/** How it works, in plain words, for everyone who uses /social. */
require __DIR__ . '/_social.php';

sm_page('viewer');
sm_layout_start('How it works');
$cron = '/usr/local/bin/php ' . SM_ROOT . '/cron.php';
?>
<div class="card sm-guide">
<h2>The normal cycle</h2>
<ol>
  <li><strong>Pick a topic</strong> on the <a href="<?= e(sm_url('plan.php')) ?>">Content plan</a>. The agent suggests topics for the event's phase and content mix; you can also type your own, with the web pages it must be based on.</li>
  <li><strong>The agent writes the blog article.</strong> It reads the exhibition's approved knowledge from the sales agent, opens the best web sources, writes the article, and a second check compares every fact with its sources. Writing takes one to three minutes.</li>
  <li><strong>A person checks and approves it.</strong> The checks and the fact check are shown next to the text. Fix anything flagged, then press <em>Approve and publish on the blog</em>. Whoever asked for the article cannot approve it too, unless they are an admin.</li>
  <li><strong>It is published on the event's blog.</strong> A branded cover is made automatically if none was chosen.</li>
  <li><strong>The agent writes one post per platform</strong> — LinkedIn, Facebook, Instagram (and X when switched on) — each written for that platform, with a branded picture in the right size.</li>
  <li><strong>Each post is checked and approved</strong>, with the <em>Include the blog post link</em> box ticked or not. The link carries a tag, so visits from each post are counted.</li>
  <li><strong>Post now or Schedule.</strong> Until Meta and LinkedIn approve the direct connection, posting is by hand: at the time, the post shows <em>Copy the text</em>, <em>Download the picture</em> and <em>Open the page</em>. Post it, then press <em>Done — I posted it</em>. Scheduled posts remind the people listed in Event settings by email.</li>
</ol>
<h2>Articles written directly in the blog</h2>
<p>When the blog's own team writes and publishes an article in the blog admin, it appears here under Blog articles, marked <em>Written in the blog</em>, and its social media versions are written as drafts for the normal approval. Articles still waiting for an editor in the blog are listed on the dashboard, with a link to approve them there. (This can be switched off in Event settings.)</p>
<h2>Automatic posting</h2>
<p>Each platform is posted either <strong>by hand</strong> (the agent prepares the post and reminds you; you post it and press <em>Done</em>) or <strong>automatically</strong>. For automatic, an admin connects the event's page once under <a href="<?= e(sm_url('connections.php')) ?>">Blog and platforms</a>: you sign in on Facebook, LinkedIn or X and press "Allow" — no password is kept here. Then approved posts go out by themselves: <em>Post now</em> posts at once, scheduled posts go out at their time. If a platform is busy, the agent tries again up to three times; if it still fails, the post shows the reason with <em>Try again</em> and <em>Post it by hand instead</em>, and the reminder addresses are emailed. LinkedIn asks for the connection again every 60 days; the dashboard reminds you a week before. X charges for each automatic post, so Blog and platforms has a monthly limit for X.</p>
<h2>Exhibitor share kits</h2>
<p>Under <a href="<?= e(sm_url('exhibitors.php')) ?>">Exhibitors and kits</a>, the exhibitor list comes in the way an admin chose: read from the event website's exhibitor page (checked again every week), typed in, or uploaded as a spreadsheet. Tick exhibitors and press <em>Write kits</em>: each gets a ready post for LinkedIn and Facebook and a picture with their logo and stand. An approver approves and emails them from the exhibition's own address. Each kit has a private page with Copy and Download buttons, and a registration link that counts the visitors it brings; the report shows which exhibitors' posts worked.</p>
<h2>Industry radar and curated news</h2>
<p>Once a week (Event settings → Industry radar) the agent searches for new reports, big projects, growing sectors and news in the event's sectors and lists them on the <a href="<?= e(sm_url('plan.php')) ?>">Content plan</a> with their sources. Pick one to <em>Write an article</em>, or <em>Share as curated news</em>: a post for each platform in our own words, crediting the source and linking to the original. Everything waits for approval.</p>
<h2>Industry Voices and sponsored articles</h2>
<p>Articles the blog marks as <em>Industry Voices</em> (written by an outside expert) show the writer here, and their posts name the writer and their company. When you post, tag them: the posting steps show their LinkedIn and company links.</p>
<p>Articles the blog marks as <em>Sponsored</em> or <em>Partner content</em> are paid for. Every post about them starts with a line such as “Sponsored · Acme Chemicals”; a post without it cannot be approved. On Facebook and Instagram also switch on the <em>paid partnership</em> label when you post.</p>
<h2>Posts without an article</h2>
<p>Event reminders, show-day photos and announcements: <a href="<?= e(sm_url('posts.php', ['new' => 1])) ?>">New post without an article</a>. Write the facts in a sentence or two; the agent writes one version per platform.</p>
<h2>Pictures</h2>
<ul>
  <li><strong>Your own:</strong> upload finished pictures or videos — they are used as they are.</li>
  <li><strong>Branded:</strong> choose a photo and a headline; the event's colours, name, dates, logo and slanted band are added, in the right size for each platform.</li>
  <li><strong>AI pictures:</strong> general illustrations only (never real machinery, people or logos), within the monthly limit set in Event settings.</li>
</ul>
<h2>Roles</h2>
<table class="table"><tbody>
<tr><td><strong>Admin</strong></td><td>Everything for the exhibition, including Event settings and Blog and platforms.</td></tr>
<tr><td><strong>Approver</strong></td><td>Approves or sends back articles and posts; Post now, Schedule, Done.</td></tr>
<tr><td><strong>Editor</strong></td><td>Asks for articles and posts, edits them, adds pictures, sends them for approval, presses Done.</td></tr>
<tr><td><strong>Viewer</strong></td><td>Reads everything; changes nothing.</td></tr>
</tbody></table>
<p class="muted small">People and their exhibitions are managed in the AI Sales Agent → Set-up → Users.</p>
<h2>Results and the monthly report</h2>
<p><a href="<?= e(sm_url('results.php')) ?>">Results</a> shows, for each month, what social media brought: posts published, likes, comments, shares and views, <strong>visits to the blog</strong>, <strong>chats with the assistant</strong> and the <strong>leads</strong> they became (exhibiting or sponsoring, visiting, not yet known), the best posts, the most-read articles, exhibitor kits, AI use and the cost of X.</p>
<ul>
<li><strong>Likes and comments</strong> of automatic posts are read by themselves 1, 7 and 28 days after posting. X charges about $0.005 for each read, so there is a monthly limit (Blog and platforms → X). For posts made by hand, open the post about a week later and type in the numbers.</li>
<li><strong>Visits</strong> come from the blog: every link in a post carries a tag naming the post.</li>
<li><strong>Chats and leads</strong> come from the AI Sales Agent, which remembers which post brought each visitor.</li>
</ul>
<p>Early each month the report for the month before is emailed to the addresses in <a href="<?= e(sm_url('settings.php')) ?>#report">Event settings → Monthly report</a>, from the exhibition's own email address. An admin can also email it any time, and anyone can print it or save it as a PDF.</p>
<h2 id="cron">The scheduled task (for whoever looks after the server)</h2>
<p>In cPanel → Cron Jobs, run this every 5 minutes (<code>*/5 * * * *</code>):</p>
<p><code class="sm-code"><?= e($cron) ?></code></p>
<p>It turns scheduled posts into "due" posts and sends the reminders, checks articles the blog is still holding, finishes articles left half-written, reads the figures of posts and blog visits, and emails the monthly report.</p>
</div>
<?php
sm_layout_end();
