<?php
/** @var string $q @var array $articles @var int $total @var int $page @var int $pages @var string $baseUrl */
?>
<div class="wrap">
  <header class="page-head">
    <h1 class="page-title"><?= e($q !== '' ? t('search_results', ['q' => $q]) : t('search')) ?></h1>
    <form class="search search--big" action="<?= e(url('search')) ?>" method="get" role="search">
      <label class="sr" for="q2"><?= e(t('search')) ?></label>
      <input id="q2" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(t('search_placeholder')) ?>">
      <button class="btn btn--primary" type="submit"><?= e(t('search')) ?></button>
    </form>
  </header>
  <?php if (mb_strlen($q) < 2): ?>
    <p class="empty"><?= e(t('search_short')) ?></p>
  <?php elseif (!$articles): ?>
    <p class="empty"><?= e(t('search_none', ['q' => $q])) ?></p>
  <?php else: ?>
    <div class="results"><?php foreach ($articles as $a) echo View::card($a, 'row'); ?></div>
    <?= View::render('partials/pager', ['page' => $page, 'pages' => $pages, 'baseUrl' => $baseUrl]) ?>
  <?php endif; ?>
</div>
