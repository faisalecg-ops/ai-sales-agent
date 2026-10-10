<?php
/** All articles, with filters. Authors see only their own. */
require_perm('articles.own');

$status = (string)($_GET['status'] ?? '');
$cat = (int)($_GET['cat'] ?? 0);
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1'];
$params = [];
if (!Auth::can('articles.all')) {
    $where[] = 'a.author_id = ?';
    $params[] = (int)Auth::id();
}
if (isset(Article::STATUSES[$status])) {
    $where[] = 'a.status = ?';
    $params[] = $status;
} elseif ($status !== 'all') {
    $where[] = "a.status <> 'archived'";
}
if ($cat > 0) {
    $where[] = 'a.category_id = ?';
    $params[] = $cat;
}
if ($q !== '') {
    $where[] = 'a.title LIKE ?';
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}
$w = implode(' AND ', $where);
$total = (int)db()->value("SELECT COUNT(*) FROM {p}articles a WHERE $w", $params);
$rows = db()->all("SELECT a.*, c.name AS category_name, c.slug AS cat, u.name AS author_name
    FROM {p}articles a LEFT JOIN {p}categories c ON c.id = a.category_id LEFT JOIN {p}users u ON u.id = a.author_id
    WHERE $w ORDER BY FIELD(a.status,'pending','scheduled','draft','published','archived'), COALESCE(a.published_at, a.updated_at) DESC
    LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$cats = db()->all('SELECT id, name FROM {p}categories ORDER BY sort, name');

ob_start(); ?>
<form class="filters" method="get" action="<?= e(local_url('admin/')) ?>">
  <input type="hidden" name="p" value="articles">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search titles">
  <select name="status"><option value="">All except archived</option><?php foreach (Article::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?><option value="all"<?= $status === 'all' ? ' selected' : '' ?>>Everything</option></select>
  <select name="cat"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cat === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <button class="btn btn--small" type="submit">Filter</button>
</form>
<div class="card card--flush">
<?php if (!$rows): ?>
  <p class="empty">No articles found. <a href="<?= e(aurl('article')) ?>">Write the first one</a>.</p>
<?php else: ?>
  <table class="table table--list">
    <thead><tr><th>Title</th><th>Status</th><th class="hide-s">Category</th><th class="hide-s">Author</th><th class="hide-s">Date</th><th class="hide-s right">Views</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><a href="<?= e(aurl('article', ['id' => $a['id']])) ?>"><strong><?= e($a['title'] ?: '(no title)') ?></strong></a>
          <?php if ($a['source'] === 'api'): ?> <span class="tag tag--api" title="Written by the Social Media Agent">Agent</span><?php endif; ?>
          <?php if ($a['is_featured']): ?> <span class="tag" title="Shown in Featured on the home page">Featured</span><?php endif; ?>
          <?php if ($a['status'] === 'published'): ?><br><a class="small muted" href="<?= e(Article::urlFor($a)) ?>" target="_blank" rel="noopener">View on blog ↗</a><?php endif; ?></td>
        <td><?= badge((string)$a['status']) ?><?php if ($a['status'] === 'scheduled'): ?><br><span class="small muted"><?= e(local_date($a['publish_at'], 'j M, H:i')) ?></span><?php endif; ?></td>
        <td class="hide-s"><?= e($a['category_name'] ?? '—') ?></td>
        <td class="hide-s"><?= e($a['author_name'] ?? ($a['source'] === 'api' ? 'Agent' : '—')) ?></td>
        <td class="hide-s small"><?= e(local_date($a['published_at'] ?: $a['updated_at'], 'j M Y')) ?></td>
        <td class="hide-s right"><?= number_format((int)$a['views']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?= pager_links($page, (int)ceil($total / $per), ['p' => 'articles', 'status' => $status, 'cat' => $cat, 'q' => $q]) ?>
<?php admin_render('Articles', (string)ob_get_clean(), 'articles', ['actions' => '<a class="btn btn--primary" href="' . e(aurl('article')) . '">+ New article</a>']);
