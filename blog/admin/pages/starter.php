<?php
/** Starter content: load a researched pack of categories and articles in one click. */
require_perm('settings');

$packs = ContentPack::available();
if (is_post()) {
    post_guard();
    $res = ContentPack::import((string)in('pack'), ['publish' => in('mode') !== 'pending', 'settings' => (bool)in('settings')]);
    flash($res['ok'] ? 'ok' : 'err', implode(' ', $res['report']));
    back_to('starter');
}

ob_start(); ?>
<p class="help">A starter pack fills a new blog with real categories and researched articles, so it is useful to readers from day one. Every article cites its sources at the end. Importing never changes anything that already exists: articles with the same address, and settings that already have a value, are left as they are. Starter articles can be edited, unpublished or deleted like any other.</p>
<?php if (!$packs): ?><p class="empty">No starter packs are included in this installation.</p><?php endif; ?>
<?php foreach ($packs as $key => $p):
    $cats = [];
    foreach ($p['articles'] as $a) {
        $cats[$a['category']] = ($cats[$a['category']] ?? 0) + 1;
    }
    $names = array_column($p['categories'], 'name', 'slug');
    $exist = (int)db()->value('SELECT COUNT(*) FROM {p}articles WHERE slug IN (' . implode(',', array_fill(0, count($p['articles']), '?')) . ')', array_column($p['articles'], 'slug')); ?>
<section class="card">
  <h2><?= e($p['name']) ?></h2>
  <p><?= e($p['description'] ?? '') ?></p>
  <table class="table"><thead><tr><th>Category</th><th class="right">Articles</th></tr></thead><tbody>
    <?php foreach ($cats as $slug => $n): ?><tr><td><?= e($names[$slug] ?? $slug) ?></td><td class="right"><?= $n ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <details><summary class="small">See the article titles</summary><ul class="plain small">
    <?php foreach ($p['articles'] as $a): ?><li><?= e($a['title']) ?></li><?php endforeach; ?>
  </ul></details>
  <?php if ($exist === count($p['articles'])): ?>
    <p class="flash flash--ok">All articles from this pack are already in the blog.</p>
  <?php else: ?>
  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing… (about a minute)'">
    <?= Auth::csrfField() ?><input type="hidden" name="pack" value="<?= e($key) ?>">
    <?= f_select('mode', 'The articles', ['publish' => 'Publish them now', 'pending' => 'Add them as “awaiting approval”, to review first'], 'publish') ?>
    <?= f_check('settings', 'Also fill in what is still empty: event details, logo, header and footer menus, counter figures, slider, contact details, social pages and the sales agent chat name', true, 'Anything you have already set is kept.') ?>
    <button class="btn btn--primary">Import <?= count($p['articles']) - $exist ?> article(s)</button>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php admin_render('Starter content', (string)ob_get_clean(), 'starter');
