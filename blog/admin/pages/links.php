<?php
/** The link library (approved addresses for article links) and the broken-link check. */
require_perm('content');

$kinds = ['register' => 'Register to visit', 'stand' => 'Book a stand', 'exhibitors' => 'Exhibitor list', 'conference' => 'Conference',
    'sponsorship' => 'Sponsorship', 'venue' => 'Venue', 'article' => 'Key article', 'source' => 'Trusted source', 'other' => 'Other'];

if (is_post()) {
    post_guard();
    $do = (string)in('do');
    if ($do === 'check') {
        $n = LinkCheck::collect();
        LinkCheck::run(25);
        Settings::set('last_link_check', now_utc());
        flash('ok', 'Checked up to 25 links now (of ' . $n . '). The rest are checked by the weekly automatic check.');
        back_to('links', ['tab' => 'check']);
    }
    if ($do === 'delete') {
        db()->delete('links', 'id = ?', [(int)in('id')]);
        LinkPolicy::forget();
        Cache::clear();
        flash('ok', 'Link removed from the library. Articles that already use it keep it.');
        back_to('links');
    }
    $lid = (int)in('id');
    $u = in_str('url', 500);
    if (!is_web_url($u) || in_str('label', 160) === '') {
        flash('err', 'Enter a name and a full web address starting with https://');
        back_to('links', ['edit' => $lid ?: 'new']);
    }
    $row = ['label' => in_str('label', 160), 'url' => Html::stripTracking($u), 'kind' => isset($kinds[(string)in('kind')]) ? (string)in('kind') : 'other',
        'keywords' => in_str('keywords', 255), 'sponsored' => (int)(bool)in('sponsored'), 'active' => (int)(bool)in('active')];
    $lid ? db()->update('links', $row, 'id = ?', [$lid]) : db()->insert('links', $row);
    LinkPolicy::forget();
    Cache::clear();
    Activity::log('link.saved', $row['label']);
    flash('ok', 'Link saved.');
    back_to('links');
}

$tab = ($_GET['tab'] ?? '') === 'check' ? 'check' : 'library';
$links = db()->all('SELECT * FROM {p}links ORDER BY active DESC, kind, label');
$editKey = (string)($_GET['edit'] ?? '');
$edit = $editKey === 'new' ? ['id' => 0, 'label' => '', 'url' => '', 'kind' => 'other', 'keywords' => '', 'sponsored' => 0, 'active' => 1]
    : ($editKey !== '' ? db()->one('SELECT * FROM {p}links WHERE id = ?', [(int)$editKey]) : null);

ob_start(); ?>
<nav class="tabs"><a href="<?= e(aurl('links')) ?>"<?= $tab === 'library' ? ' class="is-active"' : '' ?>>Link library</a><a href="<?= e(aurl('links', ['tab' => 'check'])) ?>"<?= $tab === 'check' ? ' class="is-active"' : '' ?>>Broken-link check</a></nav>

<?php if ($tab === 'library'): ?>
  <p class="help">The approved addresses writers (and later the Social Media Agent) may link to: registration, booking, exhibitor list, conference, sponsorship, venue and key articles. The editor suggests these while writing when its key words appear in the text.</p>
  <?php if ($edit): ?>
  <section class="card">
    <h2><?= $edit['id'] ? 'Edit link' : 'New link' ?></h2>
    <form method="post">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="grid2">
        <?= f_text('label', 'Name', $edit['label'], 'e.g. Register to visit', ['required' => true, 'maxlength' => 160]) ?>
        <?= f_text('url', 'Web address', $edit['url'], '', ['type' => 'url', 'required' => true, 'placeholder' => 'https://']) ?>
        <?= f_select('kind', 'Kind', $kinds, (string)$edit['kind']) ?>
        <?= f_text('keywords', 'Key words for suggestions', $edit['keywords'], 'Separate with commas, e.g. register, visitor pass, free entry', ['maxlength' => 255]) ?>
      </div>
      <?= f_check('sponsored', 'This is a paid / sponsor link (marked as sponsored for Google)', (bool)$edit['sponsored']) ?>
      <?= f_check('active', 'Available to writers', (bool)$edit['active']) ?>
      <div class="row-btns"><button class="btn btn--primary">Save link</button> <a class="btn btn--ghost" href="<?= e(aurl('links')) ?>">Cancel</a></div>
    </form>
  </section>
  <?php endif; ?>
  <section class="card card--flush">
    <?php if (!$links): ?><p class="empty">The library is empty. Add the register and book-a-stand pages first.</p><?php else: ?>
    <table class="table table--list"><thead><tr><th>Name</th><th>Kind</th><th class="hide-s">Address</th><th></th></tr></thead><tbody>
      <?php foreach ($links as $l): ?>
        <tr<?= $l['active'] ? '' : ' class="is-off"' ?>><td><strong><?= e($l['label']) ?></strong><?= $l['sponsored'] ? ' <span class="tag">Sponsored</span>' : '' ?><?= $l['active'] ? '' : ' <span class="tag">Off</span>' ?></td>
          <td class="small"><?= e($kinds[$l['kind']] ?? $l['kind']) ?></td>
          <td class="hide-s small"><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= e(excerpt(preg_replace('#^https?://#', '', (string)$l['url']), 60)) ?></a></td>
          <td class="right nowrap"><a class="btn btn--small btn--ghost" href="<?= e(aurl('links', ['edit' => $l['id']])) ?>">Edit</a>
            <form method="post" class="inline"><?= Auth::csrfField() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn btn--small btn--quiet" onclick="return confirm('Remove this link from the library?')">Remove</button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
<?php else:
    $broken = LinkCheck::broken();
    $checked = (int)db()->value('SELECT COUNT(*) FROM {p}link_checks WHERE checked_at IS NOT NULL');
    $last = (string)Settings::get('last_link_check', ''); ?>
  <section class="card">
    <div class="card__head"><h2>Broken links</h2>
      <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="do" value="check"><button class="btn btn--small">Check now</button></form></div>
    <p class="help">Every link in published articles and in the library is checked once a week automatically. <?= $checked ?> link(s) checked so far<?= $last ? ', last run ' . e(local_date($last, 'j M, H:i')) : '' ?>.</p>
    <?php if (!$broken): ?><p>✓ No broken links found.</p><?php else: ?>
    <table class="table"><thead><tr><th>Address</th><th>Problem</th><th>Used in</th></tr></thead><tbody>
      <?php foreach ($broken as $b):
          $ids = array_filter(array_map('intval', explode(',', (string)$b['article_ids'])));
          $arts = $ids ? db()->all('SELECT id, title FROM {p}articles WHERE id IN (' . implode(',', $ids) . ')') : []; ?>
        <tr><td class="small"><a href="<?= e($b['url']) ?>" target="_blank" rel="noopener"><?= e(excerpt((string)$b['url'], 70)) ?></a></td>
          <td class="small"><?= $b['status_code'] ? 'Error ' . (int)$b['status_code'] . ($b['status_code'] == 404 ? ' (page not found)' : '') : e($b['error'] ?: 'No answer') ?></td>
          <td class="small"><?php foreach ($arts as $a): ?><a href="<?= e(aurl('article', ['id' => $a['id']])) ?>"><?= e($a['title']) ?></a><br><?php endforeach; ?><?= !$arts ? 'Link library' : '' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php admin_render('Link library', (string)ob_get_clean(), 'links', ['actions' => $tab === 'library' ? '<a class="btn btn--primary" href="' . e(aurl('links', ['edit' => 'new'])) . '">+ New link</a>' : '']);
