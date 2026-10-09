<?php
/** The hero slider: slides with their own start and end dates. */
require_perm('content');

if (is_post()) {
    post_guard();
    $do = (string)in('do');
    if ($do === 'settings') {
        Settings::set('slider_transition', in('slider_transition') === 'slide' ? 'slide' : 'fade');
        Settings::set('slider_speed', max(3, min(20, (int)in('slider_speed', 6))));
        Cache::clear();
        flash('ok', 'Slider settings saved.');
        back_to('slides');
    }
    if ($do === 'delete') {
        db()->delete('slides', 'id = ?', [(int)in('id')]);
        Cache::clear();
        flash('ok', 'Slide deleted.');
        back_to('slides');
    }
    $sid = (int)in('id');
    $url = in_str('button_url', 500);
    if ($url !== '' && !is_web_url($url)) {
        flash('err', 'The button link must be a full web address starting with https://');
        back_to('slides', ['edit' => $sid ?: 'new']);
    }
    if ((int)in('image_id') <= 0) {
        flash('err', 'Choose an image for the slide.');
        back_to('slides', ['edit' => $sid ?: 'new']);
    }
    $row = ['image_id' => (int)in('image_id'), 'headline' => in_str('headline', 200), 'text' => in_str('text', 400),
        'button_label' => in_str('button_label', 60), 'button_url' => $url, 'starts_at' => local_to_utc(in_str('starts_at')),
        'ends_at' => local_to_utc(in_str('ends_at')), 'sort' => (int)in('sort'), 'active' => (int)(bool)in('active')];
    $sid ? db()->update('slides', $row, 'id = ?', [$sid]) : db()->insert('slides', $row);
    Cache::clear();
    Activity::log('slide.saved', $row['headline']);
    flash('ok', 'Slide saved.');
    back_to('slides');
}

$slides = db()->all('SELECT * FROM {p}slides ORDER BY sort, id');
$editKey = (string)($_GET['edit'] ?? '');
$edit = $editKey === 'new' ? ['id' => 0, 'image_id' => 0, 'headline' => '', 'text' => '', 'button_label' => '', 'button_url' => '', 'starts_at' => null, 'ends_at' => null, 'sort' => count($slides), 'active' => 1]
    : ($editKey !== '' ? db()->one('SELECT * FROM {p}slides WHERE id = ?', [(int)$editKey]) : null);

function slide_state(array $s): string
{
    $now = time();
    if (!$s['active']) {
        return '<span class="badge badge--archived">Off</span>';
    }
    if ($s['starts_at'] && strtotime($s['starts_at'] . ' UTC') > $now) {
        return '<span class="badge badge--scheduled">Starts ' . e(local_date($s['starts_at'], 'j M')) . '</span>';
    }
    if ($s['ends_at'] && strtotime($s['ends_at'] . ' UTC') < $now) {
        return '<span class="badge badge--draft">Expired</span>';
    }
    return '<span class="badge badge--published">Showing</span>';
}

ob_start(); ?>
<?php if ($edit): ?>
<section class="card">
  <h2><?= $edit['id'] ? 'Edit slide' : 'New slide' ?></h2>
  <form method="post">
    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="grid2">
      <div><?= f_media('image_id', 'Picture', (int)$edit['image_id'], 'Wide landscape photo, at least 1800 × 900 px. Text sits on the lower left.') ?></div>
      <div>
        <?= f_text('headline', 'Headline', $edit['headline'], 'Short and strong, under 60 characters.', ['maxlength' => 200]) ?>
        <?= f_area('text', 'Short text', $edit['text'], '', 2, ['maxlength' => 400]) ?>
        <div class="grid2"><?= f_text('button_label', 'Button text', $edit['button_label'], 'e.g. Register to visit', ['maxlength' => 60]) ?><?= f_text('button_url', 'Button link', $edit['button_url'], '', ['type' => 'url', 'placeholder' => 'https://']) ?></div>
      </div>
    </div>
    <div class="grid3">
      <?= f_text('starts_at', 'Show from (optional)', utc_to_input($edit['starts_at']), 'Pakistan time', ['type' => 'datetime-local']) ?>
      <?= f_text('ends_at', 'Stop showing after (optional)', utc_to_input($edit['ends_at']), 'The slide disappears by itself', ['type' => 'datetime-local']) ?>
      <?= f_text('sort', 'Order', (string)$edit['sort'], 'Lower first', ['type' => 'number']) ?>
    </div>
    <?= f_check('active', 'Slide is switched on', (bool)$edit['active']) ?>
    <div class="row-btns"><button class="btn btn--primary">Save slide</button> <a class="btn btn--ghost" href="<?= e(aurl('slides')) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>

<section class="card card--flush">
  <?php if (!$slides): ?><p class="empty">No slides yet. The slider is hidden until there is at least one.</p><?php else: ?>
  <table class="table table--list"><thead><tr><th></th><th>Headline</th><th>Status</th><th class="hide-s">Dates</th><th></th></tr></thead><tbody>
  <?php foreach ($slides as $s): $m = Media::find((int)$s['image_id']); ?>
    <tr><td class="thumbcell"><?php if ($m): ?><img src="<?= e(Media::url($m, 400)) ?>" alt=""><?php endif; ?></td>
      <td><strong><?= e($s['headline'] ?: '(no headline)') ?></strong><br><span class="small muted"><?= e($s['button_label']) ?></span></td>
      <td><?= slide_state($s) ?></td>
      <td class="hide-s small"><?= $s['starts_at'] ? e(local_date($s['starts_at'], 'j M Y')) : 'Now' ?> → <?= $s['ends_at'] ? e(local_date($s['ends_at'], 'j M Y')) : 'no end' ?></td>
      <td class="right nowrap"><a class="btn btn--small btn--ghost" href="<?= e(aurl('slides', ['edit' => $s['id']])) ?>">Edit</a>
        <form method="post" class="inline"><?= Auth::csrfField() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn--small btn--quiet" onclick="return confirm('Delete this slide?')">Delete</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Slider settings</h2>
  <form method="post" class="grid3">
    <?= Auth::csrfField() ?><input type="hidden" name="do" value="settings">
    <?= f_select('slider_transition', 'Transition', ['fade' => 'Fade', 'slide' => 'Slide sideways'], (string)Settings::get('slider_transition', 'fade')) ?>
    <?= f_text('slider_speed', 'Seconds per slide', (string)Settings::get('slider_speed', 6), '3–20', ['type' => 'number', 'min' => 3, 'max' => 20]) ?>
    <div class="field field--btn"><button class="btn">Save settings</button></div>
  </form>
  <p class="help">The slider pauses when a reader points at it, and does not move by itself for readers who have asked their device for less motion.</p>
</section>
<?php admin_render('Slider', (string)ob_get_clean(), 'slides', ['actions' => '<a class="btn btn--primary" href="' . e(aurl('slides', ['edit' => 'new'])) . '">+ New slide</a>']);
