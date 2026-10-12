<?php
/** Categories (the sections of the blog) and tags. */
require_perm('content');

if (is_post()) {
    post_guard();
    $do = (string)in('do');
    if ($do === 'save_cat') {
        $cid = (int)in('id');
        $name = in_str('name', 120);
        $slug = slugify(in_str('slug', 120) ?: $name, 100);
        if ($name === '' || $slug === '') {
            flash('err', 'A category needs a name.');
            back_to('categories');
        }
        if (in_array($slug, Article::RESERVED, true) || in_array($slug, Voices::reservedSlugs(), true)) {
            flash('err', '"' . $slug . '" is used by the blog itself. Choose another web address.');
            back_to('categories', ['edit' => $cid]);
        }
        if (db()->value('SELECT id FROM {p}categories WHERE slug = ? AND id <> ?', [$slug, $cid])) {
            flash('err', 'Another category already uses the address "' . $slug . '".');
            back_to('categories', ['edit' => $cid]);
        }
        $row = ['name' => $name, 'slug' => $slug, 'description' => in_str('description', 1000), 'seo_title' => in_str('seo_title', 190), 'sort' => (int)in('sort')];
        if ($cid) {
            $old = db()->one('SELECT * FROM {p}categories WHERE id = ?', [$cid]);
            db()->update('categories', $row, 'id = ?', [$cid]);
            if ($old && $old['slug'] !== $slug) {
                Article::categoryRenamed($cid, (string)$old['slug'], $slug);
            }
            Activity::log('category.updated', $name);
        } else {
            db()->insert('categories', $row);
            Activity::log('category.created', $name);
        }
        Cache::clear();
        flash('ok', 'Category saved.');
        back_to('categories');
    }
    if ($do === 'delete_cat') {
        $cid = (int)in('id');
        $move = (int)in('move_to');
        $n = (int)db()->value('SELECT COUNT(*) FROM {p}articles WHERE category_id = ?', [$cid]);
        if ($n > 0 && (!$move || $move === $cid)) {
            flash('err', 'This category has ' . $n . ' article(s). Choose where to move them first.');
            back_to('categories', ['edit' => $cid]);
        }
        $old = db()->one('SELECT * FROM {p}categories WHERE id = ?', [$cid]);
        if ($n > 0) {
            $newSlug = (string)db()->value('SELECT slug FROM {p}categories WHERE id = ?', [$move]);
            Article::categoryRenamed($cid, (string)$old['slug'], $newSlug);
            db()->update('articles', ['category_id' => $move], 'category_id = ?', [$cid]);
        }
        db()->delete('categories', 'id = ?', [$cid]);
        Cache::clear();
        Activity::log('category.deleted', (string)($old['name'] ?? ''));
        flash('ok', 'Category deleted' . ($n ? ' and its articles moved. Old addresses forward to the new ones.' : '.'));
        back_to('categories');
    }
    if ($do === 'rename_tag') {
        $tid = (int)in('id');
        $name = in_str('name', 80);
        $slug = slugify($name, 90);
        $other = $slug !== '' ? (int)db()->value('SELECT id FROM {p}tags WHERE slug = ? AND id <> ?', [$slug, $tid]) : 0;
        if ($slug === '') {
            flash('err', 'A tag needs a name.');
        } elseif ($other) {
            // Same name as an existing tag: merge them.
            db()->exec('INSERT IGNORE INTO {p}article_tags (article_id, tag_id) SELECT article_id, ? FROM {p}article_tags WHERE tag_id = ?', [$other, $tid]);
            db()->delete('article_tags', 'tag_id = ?', [$tid]);
            db()->delete('tags', 'id = ?', [$tid]);
            flash('ok', 'Tag merged into "' . $name . '".');
        } else {
            db()->update('tags', ['name' => $name, 'slug' => $slug], 'id = ?', [$tid]);
            flash('ok', 'Tag renamed.');
        }
        Cache::clear();
        back_to('categories');
    }
    if ($do === 'delete_tag') {
        db()->delete('article_tags', 'tag_id = ?', [(int)in('id')]);
        db()->delete('tags', 'id = ?', [(int)in('id')]);
        Cache::clear();
        flash('ok', 'Tag removed from all articles.');
        back_to('categories');
    }
}

$cats = db()->all("SELECT c.*, (SELECT COUNT(*) FROM {p}articles a WHERE a.category_id = c.id AND a.status = 'published') n FROM {p}categories c ORDER BY c.sort, c.name");
$edit = isset($_GET['edit']) ? db()->one('SELECT * FROM {p}categories WHERE id = ?', [(int)$_GET['edit']]) : null;
$tags = db()->all('SELECT t.*, COUNT(at.article_id) n FROM {p}tags t LEFT JOIN {p}article_tags at ON at.tag_id = t.id GROUP BY t.id ORDER BY n DESC, t.name');
$c = $edit ?? ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'seo_title' => '', 'sort' => count($cats)];

ob_start(); ?>
<div class="cols">
  <section class="card">
    <h2>Categories</h2>
    <table class="table"><thead><tr><th>Name</th><th>Address</th><th class="right">Published</th><th></th></tr></thead><tbody>
    <?php foreach ($cats as $row): ?>
      <tr><td><strong><?= e($row['name']) ?></strong></td><td class="small">/<?= e($row['slug']) ?>/</td><td class="right"><?= (int)$row['n'] ?></td>
        <td class="right"><a class="btn btn--small btn--ghost" href="<?= e(aurl('categories', ['edit' => $row['id']])) ?>">Edit</a></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="card">
    <h2><?= $edit ? 'Edit “' . e($edit['name']) . '”' : 'Add a category' ?></h2>
    <form method="post">
      <?= Auth::csrfField() ?><input type="hidden" name="do" value="save_cat"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <?= f_text('name', 'Name', $c['name'], '', ['required' => true, 'maxlength' => 120]) ?>
      <?= f_text('slug', 'Web address', $c['slug'], 'Made from the name if left empty. Changing it later keeps old links working.', ['maxlength' => 120]) ?>
      <?= f_area('description', 'Description', (string)$c['description'], 'Shown at the top of the category page and used by Google.', 3) ?>
      <?= f_text('seo_title', 'SEO title (optional)', $c['seo_title'], '', ['maxlength' => 190]) ?>
      <?= f_text('sort', 'Order', (string)$c['sort'], 'Lower numbers come first on the home page.', ['type' => 'number']) ?>
      <div class="row-btns"><button class="btn btn--primary">Save category</button><?php if ($edit): ?> <a class="btn btn--ghost" href="<?= e(aurl('categories')) ?>">Cancel</a><?php endif; ?></div>
    </form>
    <?php if ($edit): ?>
      <form method="post" class="danger-zone">
        <?= Auth::csrfField() ?><input type="hidden" name="do" value="delete_cat"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <p class="small"><strong>Delete this category.</strong> Its articles move to:</p>
        <select name="move_to"><option value="0">— choose —</option><?php foreach ($cats as $row): if ((int)$row['id'] === (int)$edit['id']) continue; ?><option value="<?= (int)$row['id'] ?>"><?= e($row['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn--small btn--danger" onclick="return confirm('Delete this category?')">Delete</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Tags</h2>
  <p class="help">Tags are added while writing articles. Here you can rename them; giving a tag the same name as another merges the two.</p>
  <?php if (!$tags): ?><p class="muted">No tags yet.</p><?php else: ?>
  <table class="table"><tbody>
  <?php foreach ($tags as $tg): ?>
    <tr><td><form method="post" class="inline-form"><?= Auth::csrfField() ?><input type="hidden" name="do" value="rename_tag"><input type="hidden" name="id" value="<?= (int)$tg['id'] ?>">
      <input name="name" value="<?= e($tg['name']) ?>" maxlength="80" aria-label="Tag name"><button class="btn btn--small btn--ghost">Rename</button></form></td>
      <td class="small muted"><?= (int)$tg['n'] ?> article(s)</td>
      <td class="right"><form method="post"><?= Auth::csrfField() ?><input type="hidden" name="do" value="delete_tag"><input type="hidden" name="id" value="<?= (int)$tg['id'] ?>"><button class="btn btn--small btn--quiet" onclick="return confirm('Remove this tag from all articles?')">Remove</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php admin_render('Categories & tags', (string)ob_get_clean(), 'categories');
