<?php
/** @var string $heading @var string $intro @var array $articles @var int $page @var int $pages @var string $baseUrl @var array $crumbs */
?>
<div class="wrap">
  <?php if ($crumbs): ?>
  <nav class="crumbs" aria-label="Breadcrumb"><ol><?php foreach ($crumbs as $i => [$name, $u]): ?><li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($u) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e($name) ?></span><?php endif; ?></li><?php endforeach; ?></ol></nav>
  <?php endif; ?>
  <header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if (trim($intro) !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
  <?= $page === 1 ? Banner::render('top') : '' ?>
  <?php if (!$articles): ?>
    <p class="empty"><?= e(t('no_articles')) ?></p>
  <?php else: ?>
    <?php if ($page === 1 && count($articles) > 3): ?>
      <div class="listing-lead"><?= View::card($articles[0], 'lead', true) ?></div>
      <div class="grid grid--3"><?php foreach (array_slice($articles, 1) as $a) echo View::card($a); ?></div>
    <?php else: ?>
      <div class="grid grid--3"><?php foreach ($articles as $a) echo View::card($a); ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <?= View::render('partials/pager', ['page' => $page, 'pages' => $pages, 'baseUrl' => $baseUrl]) ?>
</div>
