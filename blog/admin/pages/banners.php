<?php
/** Sponsor banners and the reports sent to sponsors. */
require_perm('sponsors');

// --- Sponsor report: a printable page (save as PDF) or a spreadsheet file ---
if (isset($_GET['report'])) {
    $sponsor = mb_substr((string)$_GET['report'], 0, 160);
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : gmdate('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : gmdate('Y-m-d');
    $r = Banner::report($sponsor, $from, $to);
    if (($_GET['format'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . slugify($sponsor) . '-report-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Views', 'Clicks']);
        foreach ($r['days'] as $d) {
            fputcsv($out, [$d['day'], (int)$d['views'], (int)$d['clicks']]);
        }
        fputcsv($out, ['Total', $r['views'], $r['clicks']]);
        exit;
    }
    require __DIR__ . '/../partials/sponsor_report.php';
    exit;
}

if (is_post()) {
    post_guard();
    if (in('do') === 'delete') {
        db()->delete('banners', 'id = ?', [(int)in('id')]);
        Cache::clear();
        flash('ok', 'Banner deleted. Its past figures stay in the sponsor report.');
        back_to('banners');
    }
    $bid = (int)in('id');
    $link = in_str('link', 500);
    $errors = [];
    if (in_str('sponsor_name', 160) === '') {
        $errors[] = 'Enter the sponsor\'s name.';
    }
    if (!is_web_url($link)) {
        $errors[] = 'The banner link must be a full web address starting with https://';
    }
    if ((int)in('image_id') <= 0) {
        $errors[] = 'Choose the banner image.';
    }
    if (!isset(Banner::POSITIONS[(string)in('position')])) {
        $errors[] = 'Choose where the banner appears.';
    }
    if ($errors) {
        flash('err', implode(' ', $errors));
        back_to('banners', ['edit' => $bid ?: 'new']);
    }
    $row = ['sponsor_name' => in_str('sponsor_name', 160), 'title' => in_str('title', 160), 'image_id' => (int)in('image_id'),
        'mobile_image_id' => (int)in('mobile_image_id') ?: null, 'link' => $link, 'position' => (string)in('position'),
        'starts_at' => local_to_utc(in_str('starts_at')), 'ends_at' => local_to_utc(in_str('ends_at')),
        'sort' => (int)in('sort'), 'active' => (int)(bool)in('active'), 'contact_email' => in_str('contact_email', 190)];
    $bid ? db()->update('banners', $row, 'id = ?', [$bid]) : db()->insert('banners', $row);
    Cache::clear();
    Activity::log('banner.saved', $row['sponsor_name']);
    flash('ok', 'Banner saved.');
    back_to('banners');
}

$banners = db()->all("SELECT b.*, COALESCE(SUM(s.views),0) v, COALESCE(SUM(s.clicks),0) c,
    COALESCE(SUM(CASE WHEN s.day >= ? THEN s.views END),0) v30, COALESCE(SUM(CASE WHEN s.day >= ? THEN s.clicks END),0) c30
    FROM {p}banners b LEFT JOIN {p}banner_stats s ON s.banner_id = b.id GROUP BY b.id ORDER BY b.active DESC, b.position, b.sort, b.id",
    [gmdate('Y-m-d', time() - 30 * 86400), gmdate('Y-m-d', time() - 30 * 86400)]);
$sponsors = array_values(array_unique(array_column($banners, 'sponsor_name')));
$editKey = (string)($_GET['edit'] ?? '');
$edit = $editKey === 'new'
    ? ['id' => 0, 'sponsor_name' => '', 'title' => '', 'image_id' => 0, 'mobile_image_id' => 0, 'link' => '', 'position' => 'middle', 'starts_at' => null, 'ends_at' => null, 'sort' => 0, 'active' => 1, 'contact_email' => '']
    : ($editKey !== '' ? db()->one('SELECT * FROM {p}banners WHERE id = ?', [(int)$editKey]) : null);

ob_start(); ?>
<?php if ($edit): ?>
<section class="card">
  <h2><?= $edit['id'] ? 'Edit banner' : 'New banner' ?></h2>
  <form method="post">
    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="grid2">
      <div>
        <?= f_text('sponsor_name', 'Sponsor name', $edit['sponsor_name'], 'Banners with the same sponsor name are reported together.', ['required' => true, 'maxlength' => 160, 'list' => 'sponsorlist']) ?>
        <datalist id="sponsorlist"><?php foreach ($sponsors as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        <?= f_text('title', 'Banner name (for you)', $edit['title'], 'e.g. "January campaign"', ['maxlength' => 160]) ?>
        <?= f_text('link', 'Link', $edit['link'], 'Where a click takes the reader. Marked as sponsored for Google automatically.', ['type' => 'url', 'required' => true, 'placeholder' => 'https://']) ?>
        <?= f_select('position', 'Where it appears', Banner::POSITIONS, (string)$edit['position']) ?>
        <p class="help">Image sizes: <?php foreach (Banner::SIZES as $k => $s): ?><?= e(ucfirst($k)) ?> <?= e($s) ?>; <?php endforeach; ?> When several banners share a place, they take turns.</p>
      </div>
      <div>
        <?= f_media('image_id', 'Banner image', (int)$edit['image_id']) ?>
        <?= f_media('mobile_image_id', 'Phone version (optional)', (int)$edit['mobile_image_id'], 'A narrower image for phones, e.g. 640 × 200 px.') ?>
      </div>
    </div>
    <div class="grid3">
      <?= f_text('starts_at', 'Start (optional)', utc_to_input($edit['starts_at']), 'Pakistan time', ['type' => 'datetime-local']) ?>
      <?= f_text('ends_at', 'End (optional)', utc_to_input($edit['ends_at']), 'Stops by itself', ['type' => 'datetime-local']) ?>
      <?= f_text('contact_email', 'Sponsor contact email (optional)', $edit['contact_email'], 'For sending them their report', ['type' => 'email']) ?>
    </div>
    <?= f_check('active', 'Banner is switched on', (bool)$edit['active']) ?>
    <div class="row-btns"><button class="btn btn--primary">Save banner</button> <a class="btn btn--ghost" href="<?= e(aurl('banners')) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>

<section class="card card--flush">
  <?php if (!$banners): ?><p class="empty">No sponsor banners yet.</p><?php else: ?>
  <table class="table table--list">
    <thead><tr><th></th><th>Sponsor</th><th>Place</th><th>Status</th><th class="right">Views<br><span class="small muted">30 days / all</span></th><th class="right">Clicks<br><span class="small muted">30 days / all</span></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($banners as $b): $m = Media::find((int)$b['image_id']);
        $now = time();
        $state = !$b['active'] ? ['archived', 'Off'] : (($b['starts_at'] && strtotime($b['starts_at'] . ' UTC') > $now) ? ['scheduled', 'Starts ' . local_date($b['starts_at'], 'j M')] : (($b['ends_at'] && strtotime($b['ends_at'] . ' UTC') < $now) ? ['draft', 'Ended'] : ['published', 'Running'])); ?>
      <tr><td class="thumbcell"><?php if ($m): ?><img src="<?= e(Media::url($m, 400)) ?>" alt=""><?php endif; ?></td>
        <td><strong><?= e($b['sponsor_name']) ?></strong><?= $b['title'] ? '<br><span class="small muted">' . e($b['title']) . '</span>' : '' ?></td>
        <td class="small"><?= e(ucfirst((string)$b['position'])) ?></td>
        <td><span class="badge badge--<?= $state[0] ?>"><?= e($state[1]) ?></span><?php if ($b['ends_at']): ?><br><span class="small muted">until <?= e(local_date($b['ends_at'], 'j M Y')) ?></span><?php endif; ?></td>
        <td class="right"><?= number_format((int)$b['v30']) ?> / <?= number_format((int)$b['v']) ?></td>
        <td class="right"><?= number_format((int)$b['c30']) ?> / <?= number_format((int)$b['c']) ?></td>
        <td class="right nowrap"><a class="btn btn--small btn--ghost" href="<?= e(aurl('banners', ['edit' => $b['id']])) ?>">Edit</a>
          <form method="post" class="inline"><?= Auth::csrfField() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn--small btn--quiet" onclick="return confirm('Delete this banner?')">Delete</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<?php if ($sponsors): ?>
<section class="card">
  <h2>Sponsor report</h2>
  <p class="help">A one-page report of views and clicks for one sponsor, ready to print or save as PDF, or as a spreadsheet file.</p>
  <form method="get" action="<?= e(url('admin/')) ?>" class="grid4" target="_blank">
    <input type="hidden" name="p" value="banners">
    <div class="field"><label for="r_s">Sponsor</label><select id="r_s" name="report"><?php foreach ($sponsors as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></div>
    <?= f_text('from', 'From', gmdate('Y-m-01'), '', ['type' => 'date']) ?>
    <?= f_text('to', 'To', gmdate('Y-m-d'), '', ['type' => 'date']) ?>
    <div class="field field--btn"><button class="btn btn--primary">Open report</button> <button class="btn btn--ghost" name="format" value="csv">Spreadsheet</button></div>
  </form>
  <p class="help">Views are counted when a banner is actually shown to a reader; clicks when a reader opens the sponsor's link. Search-engine robots are not counted.</p>
</section>
<?php endif; ?>
<?php admin_render('Sponsor banners', (string)ob_get_clean(), 'banners', ['wide' => true, 'actions' => '<a class="btn btn--primary" href="' . e(aurl('banners', ['edit' => 'new'])) . '">+ New banner</a>']);
