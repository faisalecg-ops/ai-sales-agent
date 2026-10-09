<?php
/** The media library: upload, describe and remove images. Also answers the image picker. */
require_perm('media');

// Upload (one file per request; the page sends several in turn).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'upload' && is_post()) {
    post_guard();
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        json_out(['ok' => false, 'error' => in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than the hosting allows. Make it smaller (under ' . ini_get('upload_max_filesize') . ').' : 'The file did not arrive. Please try again.']);
    }
    $res = Media::store((string)$f['tmp_name'], (string)$f['name'], Auth::id(), null, ['alt' => in_str('alt', 255)]);
    if (!$res['ok']) {
        json_out($res);
    }
    Activity::log('media.uploaded', (string)$f['name']);
    $m = Media::find((int)$res['id']);
    json_out(['ok' => true, 'item' => media_item($m)]);
}

// List for the picker.
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $where = $q !== '' ? 'WHERE original_name LIKE ? OR alt LIKE ? OR caption LIKE ?' : '';
    $params = $q !== '' ? array_fill(0, 3, '%' . addcslashes($q, '%_\\') . '%') : [];
    $rows = db()->all("SELECT * FROM {p}media $where ORDER BY id DESC LIMIT 40 OFFSET " . (($page - 1) * 40), $params);
    json_out(['ok' => true, 'items' => array_map('media_item', $rows), 'more' => count($rows) === 40]);
}

function media_item(array $m): array
{
    return ['id' => (int)$m['id'], 'thumb' => Media::url($m, 400), 'url' => Media::url($m, 1200), 'alt' => (string)$m['alt'],
        'name' => (string)$m['original_name'], 'w' => (int)$m['width'], 'h' => (int)$m['height'], 'caption' => (string)$m['caption']];
}

if (is_post()) {
    post_guard();
    $mid = (int)in('id');
    if (in('do') === 'delete') {
        $res = Media::delete($mid);
        $res['ok'] ? flash('ok', 'The image has been deleted.') : flash('err', $res['error']);
        if ($res['ok']) {
            Activity::log('media.deleted', (string)$mid);
        }
        back_to('media');
    }
    db()->update('media', ['alt' => in_str('alt', 255), 'caption' => in_str('caption', 500), 'credit' => in_str('credit', 255)], 'id = ?', [$mid]);
    Cache::clear();
    flash('ok', 'Image details saved.');
    back_to('media', ['edit' => $mid]);
}

$edit = isset($_GET['edit']) ? Media::find((int)$_GET['edit']) : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)db()->value('SELECT COUNT(*) FROM {p}media');
$rows = db()->all('SELECT * FROM {p}media ORDER BY id DESC LIMIT 48 OFFSET ' . (($page - 1) * 48));

ob_start(); ?>
<?php if ($edit): $uses = Media::usage((int)$edit['id']); ?>
<section class="card media-edit">
  <div class="media-edit__img"><img src="<?= e(Media::url($edit, 800)) ?>" alt=""></div>
  <form method="post" class="media-edit__form">
    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <p class="small muted"><?= e($edit['original_name']) ?> · <?= (int)$edit['width'] ?> × <?= (int)$edit['height'] ?> px · <?= number_format((int)$edit['bytes'] / 1024) ?> KB</p>
    <?= f_text('alt', 'Description for blind readers and Google (alt text)', $edit['alt'], 'Say what the picture shows, e.g. "Visitors at the machinery hall, Build Asia 2026".', ['maxlength' => 255]) ?>
    <?= f_text('caption', 'Caption', $edit['caption'], 'Shown under the image in articles.', ['maxlength' => 500]) ?>
    <?= f_text('credit', 'Photo credit', $edit['credit'], 'e.g. "Photo: Build Asia".', ['maxlength' => 255]) ?>
    <p class="small"><?= $uses ? 'Used in: ' . e(implode(', ', $uses)) : 'Not used anywhere yet.' ?></p>
    <div class="row-btns"><button class="btn btn--primary">Save</button> <a class="btn btn--ghost" href="<?= e(aurl('media')) ?>">Close</a>
      <?php if (!$uses): ?><button class="btn btn--danger btn--small" name="do" value="delete" onclick="return confirm('Delete this image?')">Delete</button><?php endif; ?></div>
    <p class="small"><a href="<?= e(Media::url($edit, 1800)) ?>" target="_blank" rel="noopener">Open full size ↗</a></p>
  </form>
</section>
<?php endif; ?>

<section class="card dropzone" data-dropzone>
  <p><strong>Drop images here</strong> or <label class="btn btn--small">choose files<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden data-upload></label></p>
  <p class="help">JPEG, PNG, WebP or GIF, up to 12 MB. Each image is resized automatically for phones and computers.</p>
  <div class="upload-status" data-uploadstatus></div>
</section>

<div class="media-grid" data-mediagrid>
  <?php foreach ($rows as $m): ?>
    <a class="media-tile<?= $m['alt'] === '' ? ' media-tile--noalt' : '' ?>" href="<?= e(aurl('media', ['edit' => $m['id']])) ?>" title="<?= e($m['original_name']) ?>">
      <img src="<?= e(Media::url($m, 400)) ?>" alt="" loading="lazy"><?php if ($m['alt'] === ''): ?><span class="media-tile__flag">add description</span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><p class="empty">No images yet.</p><?php endif; ?>
<?= pager_links($page, (int)ceil($total / 48), ['p' => 'media']) ?>
<?php admin_render('Media library', (string)ob_get_clean(), 'media', ['wide' => true]);
