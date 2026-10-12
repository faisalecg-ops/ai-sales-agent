<?php
/** Industry Voices: outside experts' profiles, and how the section looks (admins). */
require_perm('content');

if (is_post()) {
    post_guard();
    $do = (string)in('do', 'save');

    if ($do === 'settings') {
        require_perm('settings');
        $slug = slugify(in_str('voices_slug', 60), 60) ?: 'industry-voices';
        $wslug = slugify(in_str('write_slug', 60), 60) ?: 'write-for-us';
        $taken = static fn(string $s) => in_array($s, Article::RESERVED, true) || db()->value('SELECT id FROM {p}categories WHERE slug = ?', [$s]);
        if ($taken($slug) || $taken($wslug) || $slug === $wslug) {
            flash('err', 'That web address is already used by a category or by the blog itself. Choose another.');
            back_to('contributors', ['tab' => 'settings']);
        }
        $emails = array_filter(array_map('trim', preg_split('/[,;\s]+/', in_str('write_notify', 600)) ?: []), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        Settings::setMany([
            'voices_enabled' => (bool)in('voices_enabled'), 'voices_name' => in_str('voices_name', 60) ?: 'Industry Voices', 'voices_slug' => $slug,
            'voices_badge' => in_str('voices_badge', 40) ?: 'Industry Voice', 'voices_intro' => in_str('voices_intro', 400),
            'voices_disclaimer' => in_str('voices_disclaimer', 300), 'voices_menu' => (bool)in('voices_menu'),
            'voices_home_count' => max(2, min(8, (int)in('voices_home_count'))),
            'write_enabled' => (bool)in('write_enabled'), 'write_slug' => $wslug, 'write_title' => in_str('write_title', 80) ?: 'Write for us',
            'write_intro' => in_str('write_intro', 800), 'write_consent' => in_str('write_consent', 400), 'write_notify' => implode(', ', array_unique($emails)),
        ]);
        Cache::clear();
        Activity::log('voices.settings');
        flash('ok', 'Industry Voices settings saved.');
        back_to('contributors', ['tab' => 'settings']);
    }

    $cid = (int)in('id');
    $data = [];
    foreach (['name', 'designation', 'company', 'company_url', 'linkedin_url', 'bio', 'email', 'phone', 'photo_id', 'company_logo_id', 'user_id', 'active'] as $k) {
        $data[$k] = in($k);
    }
    if (trim((string)$data['name']) === '') {
        flash('err', 'Enter the contributor\'s name.');
        back_to('contributors', ['edit' => $cid ?: 'new']);
    }
    foreach (['company_url' => 'company website', 'linkedin_url' => 'LinkedIn profile'] as $k => $label) {
        if (trim((string)$data[$k]) !== '' && !is_web_url(trim((string)$data[$k]))) {
            flash('err', 'The ' . $label . ' must be a full web address starting with https://');
            back_to('contributors', ['edit' => $cid ?: 'new']);
        }
    }
    $uid = (int)$data['user_id'];
    if ($uid && db()->value('SELECT id FROM {p}contributors WHERE user_id = ? AND id <> ?', [$uid, $cid])) {
        flash('err', 'That author account is already linked to another contributor.');
        back_to('contributors', ['edit' => $cid ?: 'new']);
    }
    $cid = Voices::save($cid ?: null, $data);
    Activity::log('contributor.saved', (string)$data['name']);
    flash('ok', 'Contributor saved.');
    back_to('contributors');
}

$tab = (string)($_GET['tab'] ?? '');
$edit = $_GET['edit'] ?? null;
ob_start();

if ($edit !== null):
    $c = $edit === 'new' ? null : Voices::find((int)$edit);
    $v = $c ?? ['id' => 0, 'name' => '', 'designation' => '', 'company' => '', 'company_url' => '', 'linkedin_url' => '', 'bio' => '', 'email' => '', 'phone' => '',
        'photo_id' => 0, 'company_logo_id' => 0, 'user_id' => 0, 'active' => 1];
    $authors = db()->all("SELECT id, name, email FROM {p}users WHERE role = 'author' AND status = 'active' ORDER BY name"); ?>
<form method="post" class="card">
  <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
  <h2><?= $c ? 'Edit contributor' : 'New contributor' ?></h2>
  <p class="help">An industry leader who writes for the blog. They need no login: your team publishes their articles for them, choosing them under “<?= e(Voices::name()) ?>” in the article editor.</p>
  <div class="grid2">
    <?= f_text('name', 'Name', (string)$v['name'], '', ['required' => true, 'maxlength' => 120]) ?>
    <?= f_text('designation', 'Designation', (string)$v['designation'], 'e.g. Chief Executive Officer', ['maxlength' => 160]) ?>
    <?= f_text('company', 'Company', (string)$v['company'], '', ['maxlength' => 160]) ?>
    <?= f_text('company_url', 'Company website', (string)$v['company_url'], '', ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://']) ?>
    <?= f_text('linkedin_url', 'LinkedIn profile', (string)$v['linkedin_url'], 'Also used by the Social Media Agent to tag them.', ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://www.linkedin.com/in/…']) ?>
  </div>
  <?= f_area('bio', 'Short biography', (string)$v['bio'], 'Two or three sentences, shown under their articles.', 3, ['maxlength' => 1500]) ?>
  <div class="grid2">
    <?= f_media('photo_id', 'Photo', (int)$v['photo_id'], 'A square head-and-shoulders photo works best.') ?>
    <?= f_media('company_logo_id', 'Company logo', (int)$v['company_logo_id']) ?>
  </div>
  <h3>Private (never shown on the blog)</h3>
  <div class="grid2">
    <?= f_text('email', 'Email', (string)$v['email'], '', ['type' => 'email', 'maxlength' => 190]) ?>
    <?= f_text('phone', 'Phone', (string)$v['phone'], '', ['maxlength' => 40]) ?>
  </div>
  <?= f_select('user_id', 'Author account (optional)', ['' => '— none: the editorial team publishes for them —'] + array_map(static fn($u) => $u['name'] . ' · ' . $u['email'], array_column($authors, null, 'id')), (string)($v['user_id'] ?: ''),
      'If they write in the admin themselves, give them a user with the <strong>Author</strong> role (Users) and link it here. Their articles are then marked as theirs automatically, and they can only send them for approval.') ?>
  <?= f_check('active', 'Active (shown on the blog and offered in the article editor)', (bool)$v['active']) ?>
  <div class="savebar"><button class="btn btn--primary">Save contributor</button> <a class="btn btn--ghost" href="<?= e(aurl('contributors')) ?>">Cancel</a>
    <?php if ($c && $c['active']): ?><a class="btn btn--ghost" href="<?= e(Voices::profileUrl($c)) ?>" target="_blank" rel="noopener">View profile ↗</a><?php endif; ?></div>
</form>
<?php
elseif ($tab === 'settings' && Auth::can('settings')): ?>
<form method="post">
  <?= Auth::csrfField() ?><input type="hidden" name="do" value="settings">
  <section class="card">
    <h2>The section</h2>
    <?= f_check('voices_enabled', 'Show Industry Voices on the blog', (bool)Settings::get('voices_enabled', true), 'Off: no section, badge, profile pages or “Write for us” page.') ?>
    <div class="grid2">
      <?= f_text('voices_name', 'Section name', (string)Settings::get('voices_name', 'Industry Voices'), 'Shown as the menu item and the heading.', ['maxlength' => 60]) ?>
      <?= f_text('voices_slug', 'Web address', Voices::slug(), 'The section lives at ' . e(url('')) . '<strong>' . e(Voices::slug()) . '</strong>/', ['maxlength' => 60, 'pattern' => '[a-z0-9-]+']) ?>
      <?= f_text('voices_badge', 'Badge on cards and articles', (string)Settings::get('voices_badge', 'Industry Voice'), '', ['maxlength' => 40]) ?>
      <?= f_text('voices_home_count', 'Articles in the home page section', (string)Settings::get('voices_home_count', 4), '2–8. Place the section in the Home page builder.', ['type' => 'number', 'min' => 2, 'max' => 8]) ?>
    </div>
    <?= f_area('voices_intro', 'Introduction on the section page', (string)Settings::get('voices_intro', ''), '', 2, ['maxlength' => 400]) ?>
    <?= f_area('voices_disclaimer', 'Line under every Industry Voices article', (string)Settings::get('voices_disclaimer', ''), '{event} becomes the exhibition name.', 2, ['maxlength' => 300]) ?>
    <?= f_check('voices_menu', 'Add the section to the header menu automatically (once it has an article)', (bool)Settings::get('voices_menu', true)) ?>
  </section>
  <section class="card">
    <h2>“Write for us” page</h2>
    <?= f_check('write_enabled', 'Let industry experts send an outline or a draft through a public form', (bool)Settings::get('write_enabled', true), 'Every proposal waits in Industry Voices → Write for us. Nothing is published without an editor.') ?>
    <div class="grid2">
      <?= f_text('write_title', 'Page title', (string)Settings::get('write_title', 'Write for us'), '', ['maxlength' => 80]) ?>
      <?= f_text('write_slug', 'Web address', Voices::writeSlug(), 'The form lives at ' . e(url('')) . '<strong>' . e(Voices::writeSlug()) . '</strong>', ['maxlength' => 60, 'pattern' => '[a-z0-9-]+']) ?>
    </div>
    <?= f_area('write_intro', 'Introduction', (string)Settings::get('write_intro', ''), '', 3, ['maxlength' => 800]) ?>
    <?= f_area('write_consent', 'Consent line the writer must tick', (string)Settings::get('write_consent', ''), '', 2, ['maxlength' => 400]) ?>
    <?= f_text('write_notify', 'Tell these people about new proposals', (string)Settings::get('write_notify', ''), 'Email addresses, separated by commas. Empty = the contact email in Event &amp; courtesy.', ['maxlength' => 600]) ?>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save settings</button>
    <?php if (Voices::writeOpen()): ?><a class="btn btn--ghost" href="<?= e(url(Voices::writeSlug())) ?>" target="_blank" rel="noopener">View the form ↗</a><?php endif; ?></div>
</form>
<?php
else:
    $rows = db()->all("SELECT ct.*, (SELECT COUNT(*) FROM {p}articles a WHERE a.contributor_id = ct.id AND a.status = 'published') AS published,
        u.name AS user_name FROM {p}contributors ct LEFT JOIN {p}users u ON u.id = ct.user_id ORDER BY ct.active DESC, ct.name"); ?>
<?php if (!Voices::enabled()): ?><div class="flash flash--warn">Industry Voices is switched off, so readers see none of it. <?php if (Auth::can('settings')): ?><a href="<?= e(aurl('contributors', ['tab' => 'settings'])) ?>">Switch it on</a>.<?php endif; ?></div><?php endif; ?>
<section class="card card--flush">
  <?php if (!$rows): ?>
    <p class="empty">No contributors yet. Add an industry leader, then choose them in the article editor — or accept a proposal sent through “Write for us”.</p>
  <?php else: ?>
  <table class="table table--list"><thead><tr><th>Name</th><th class="hide-s">Company</th><th class="hide-s right">Published</th><th class="hide-s">Author account</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><strong><?= e($r['name']) ?></strong><?= $r['active'] ? '' : ' <span class="tag">Hidden</span>' ?><br><span class="small muted"><?= e($r['designation']) ?></span></td>
      <td class="hide-s"><?= e($r['company']) ?></td><td class="hide-s right"><?= (int)$r['published'] ?></td>
      <td class="hide-s small"><?= e($r['user_name'] ?? '—') ?></td>
      <td class="right nowrap"><a class="btn btn--small btn--ghost" href="<?= e(aurl('contributors', ['edit' => $r['id']])) ?>">Edit</a>
        <?php if ($r['published'] > 0 && $r['active']): ?><a class="btn btn--small btn--quiet" href="<?= e(Voices::profileUrl($r)) ?>" target="_blank" rel="noopener">Profile ↗</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php endif;

$actions = '<a class="btn btn--primary" href="' . e(aurl('contributors', ['edit' => 'new'])) . '">+ New contributor</a>'
    . (Auth::can('settings') ? ' <a class="btn btn--ghost" href="' . e(aurl('contributors', ['tab' => 'settings'])) . '">Section settings</a>' : '');
admin_render(Voices::name() . ' — contributors', (string)ob_get_clean(), 'contributors', ['actions' => $actions]);
