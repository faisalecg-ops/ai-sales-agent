<?php
/** @var array $c @var array $articles @var int $page @var int $pages @var string $baseUrl @var array $crumbs */
?>
<div class="wrap">
  <nav class="crumbs" aria-label="Breadcrumb"><ol><?php foreach ($crumbs as $i => [$name, $u]): ?><li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($u) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e($name) ?></span><?php endif; ?></li><?php endforeach; ?></ol></nav>
  <header class="page-head">
    <a class="flag flag--voice" href="<?= e(Voices::sectionUrl()) ?>"><?= e(Voices::badge()) ?></a>
  </header>
  <?= View::render('partials/contributor_box', ['c' => $c]) ?>
  <section class="section" aria-labelledby="h-by">
    <h1 class="section-h" id="h-by"><?= e(t('articles_by', ['name' => $c['name']])) ?></h1>
    <div class="grid grid--3"><?php foreach ($articles as $a) echo View::card($a); ?></div>
    <?= View::render('partials/pager', ['page' => $page, 'pages' => $pages, 'baseUrl' => $baseUrl]) ?>
  </section>
</div>
