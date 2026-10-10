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
<h2 id="cron">The scheduled task (for whoever looks after the server)</h2>
<p>In cPanel → Cron Jobs, run this every 5 minutes (<code>*/5 * * * *</code>):</p>
<p><code class="sm-code"><?= e($cron) ?></code></p>
<p>It turns scheduled posts into "due" posts and sends the reminders, checks articles the blog is still holding, and finishes articles left half-written.</p>
</div>
<?php
sm_layout_end();
