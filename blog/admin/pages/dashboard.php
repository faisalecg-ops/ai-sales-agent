<?php
/** Dashboard: what needs attention, how the blog is doing, and setup steps still open. */
$counts = [];
foreach (['published', 'pending', 'draft', 'scheduled'] as $s) {
    $counts[$s] = (int)db()->value('SELECT COUNT(*) FROM {p}articles WHERE status = ?', [$s]);
}
$pending = Auth::can('articles.publish')
    ? db()->all("SELECT a.*, u.name AS author_name FROM {p}articles a LEFT JOIN {p}users u ON u.id = a.author_id WHERE a.status = 'pending' ORDER BY a.updated_at DESC LIMIT 10")
    : [];
$mine = db()->all("SELECT * FROM {p}articles WHERE author_id = ? AND status IN ('draft','pending') ORDER BY updated_at DESC LIMIT 6", [(int)Auth::id()]);
$views30 = (int)db()->value('SELECT COALESCE(SUM(views),0) FROM {p}article_views WHERE day >= ?', [gmdate('Y-m-d', time() - 30 * 86400)]);
$top = db()->all("SELECT a.id, a.title, SUM(v.views) n FROM {p}article_views v JOIN {p}articles a ON a.id = v.article_id WHERE v.day >= ? GROUP BY a.id ORDER BY n DESC LIMIT 5", [gmdate('Y-m-d', time() - 30 * 86400)]);
$clicks = db()->one('SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(clicks),0) c FROM {p}banner_stats WHERE day >= ?', [gmdate('Y-m-01')]);
$comments = (int)db()->value("SELECT COUNT(*) FROM {p}comments WHERE status = 'pending'");
$broken = count(LinkCheck::broken());
$subs = (int)db()->value("SELECT COUNT(*) FROM {p}subscribers WHERE status = 'confirmed'");

$setup = [];
if (Auth::can('settings')) {
    $lastCron = (string)Settings::get('last_cron', '');
    $setup = [
        ['Logo uploaded', (int)Settings::get('logo_light', 0) > 0, aurl('branding')],
        ['Event dates and venue', Event::dates() !== '' && Event::get('event_venue') !== '', aurl('event')],
        ['Main event website address', Event::get('main_site_url') !== '', aurl('event')],
        ['Register and book-a-stand links', Event::get('register_url') !== '' && Event::get('book_stand_url') !== '', aurl('event')],
        ['Link library has approved links', (int)db()->value('SELECT COUNT(*) FROM {p}links WHERE active = 1') >= 2, aurl('links')],
        ['Main menu set', count((array)Settings::get('main_menu', [])) > 0, aurl('menus')],
        ['Sales agent chat switched on', (bool)Settings::get('chat_enabled', false), aurl('chat')],
        ['Cron job running (scheduled tasks)', $lastCron !== '' && strtotime($lastCron . ' UTC') > time() - 3600, aurl('settings') . '#cron'],
        ['Visible to Google', !Settings::get('hide_from_search', false), aurl('seo')],
    ];
}
$open = array_filter($setup, static fn($s) => !$s[1]);

ob_start(); ?>
<div class="stats">
  <a class="stat" href="<?= e(aurl('articles', ['status' => 'published'])) ?>"><span class="stat__n"><?= $counts['published'] ?></span><span class="stat__l">Published</span></a>
  <a class="stat<?= $counts['pending'] ? ' stat--alert' : '' ?>" href="<?= e(aurl('articles', ['status' => 'pending'])) ?>"><span class="stat__n"><?= $counts['pending'] ?></span><span class="stat__l">Awaiting approval</span></a>
  <a class="stat" href="<?= e(aurl('articles', ['status' => 'scheduled'])) ?>"><span class="stat__n"><?= $counts['scheduled'] ?></span><span class="stat__l">Scheduled</span></a>
  <a class="stat" href="<?= e(aurl('articles', ['status' => 'draft'])) ?>"><span class="stat__n"><?= $counts['draft'] ?></span><span class="stat__l">Drafts</span></a>
  <div class="stat"><span class="stat__n"><?= number_format($views30) ?></span><span class="stat__l">Article views, last 30 days</span></div>
  <?php if (Auth::can('sponsors')): ?><a class="stat" href="<?= e(aurl('banners')) ?>"><span class="stat__n"><?= number_format((int)$clicks['c']) ?></span><span class="stat__l">Sponsor clicks this month</span></a><?php endif; ?>
</div>

<?php if ($open): ?>
<section class="card">
  <h2>Finish setting up</h2>
  <ul class="checklist">
    <?php foreach ($setup as [$label, $done, $link]): ?>
      <li class="<?= $done ? 'is-done' : '' ?>"><?= $done ? '✓' : '○' ?> <?= $done ? e($label) : '<a href="' . e($link) . '">' . e($label) . '</a>' ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<div class="cols">
  <div>
    <?php if ($pending): ?>
    <section class="card">
      <h2>Waiting for your approval</h2>
      <table class="table"><tbody>
        <?php foreach ($pending as $a): ?>
          <tr><td><a href="<?= e(aurl('article', ['id' => $a['id']])) ?>"><strong><?= e($a['title'] ?: '(no title)') ?></strong></a><br><span class="muted small"><?= $a['source'] === 'api' ? 'From the Social Media Agent' : 'By ' . e($a['author_name'] ?? '—') ?> · <?= e(local_date($a['updated_at'], 'j M, H:i')) ?></span></td>
          <td class="right"><a class="btn btn--small" href="<?= e(aurl('article', ['id' => $a['id']])) ?>">Review</a></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </section>
    <?php endif; ?>
    <section class="card">
      <div class="card__head"><h2>Your drafts</h2><a class="btn btn--small btn--primary" href="<?= e(aurl('article')) ?>">+ New article</a></div>
      <?php if (!$mine): ?><p class="muted">Nothing in progress.</p><?php else: ?>
      <table class="table"><tbody>
        <?php foreach ($mine as $a): ?><tr><td><a href="<?= e(aurl('article', ['id' => $a['id']])) ?>"><?= e($a['title'] ?: '(no title)') ?></a></td><td class="right"><?= badge((string)$a['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </section>
  </div>
  <div>
    <section class="card">
      <h2>Most read, last 30 days</h2>
      <?php if (!$top): ?><p class="muted">Views appear here once readers arrive.</p><?php else: ?>
      <ol class="toplist"><?php foreach ($top as $t): ?><li><a href="<?= e(aurl('article', ['id' => $t['id']])) ?>"><?= e($t['title']) ?></a> <span class="muted"><?= number_format((int)$t['n']) ?></span></li><?php endforeach; ?></ol>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Needs attention</h2>
      <ul class="plain">
        <li><?= $comments ? '<a href="' . e(aurl('comments')) . '">' . $comments . ' comment(s) waiting for approval</a>' : 'No comments waiting.' ?></li>
        <li><?= $broken ? '<a href="' . e(aurl('links', ['tab' => 'check'])) . '">' . $broken . ' broken link(s) found</a>' : 'No broken links found.' ?></li>
        <?php if (Auth::can('subscribers')): ?><li><?= $subs ?> confirmed newsletter subscriber(s).</li><?php endif; ?>
      </ul>
    </section>
  </div>
</div>
<?php admin_render('Dashboard', (string)ob_get_clean(), 'dashboard');
