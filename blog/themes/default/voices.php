<?php
/** @var string $heading @var string $intro @var array $contributors @var array $articles @var int $page @var int $pages @var string $baseUrl @var array $crumbs */
?>
<div class="wrap">
  <nav class="crumbs" aria-label="Breadcrumb"><ol><?php foreach ($crumbs as $i => [$name, $u]): ?><li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($u) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e($name) ?></span><?php endif; ?></li><?php endforeach; ?></ol></nav>
  <header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if (trim($intro) !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
  <?php if ($contributors): ?>
  <section class="section section--tight" aria-labelledby="h-contrib">
    <h2 class="section-h" id="h-contrib"><?= e(t('voices_contributors')) ?></h2>
    <ul class="voices-people">
      <?php foreach ($contributors as $c): $ph = Media::find((int)$c['photo_id']); ?>
        <li><a href="<?= e(Voices::profileUrl($c)) ?>">
          <span class="voices-people__img"><?php if ($ph): ?><img src="<?= e(Media::url($ph, 400)) ?>" alt="" loading="lazy"><?php else: ?><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$c['name'], 0, 1))) ?></span><?php endif; ?></span>
          <strong><?= e($c['name']) ?></strong><span><?= e(Voices::role($c)) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
  <?php if (!$articles): ?>
    <p class="empty"><?= e(t('no_articles')) ?></p>
  <?php else: ?>
    <div class="grid grid--3"><?php foreach ($articles as $a) echo View::card($a); ?></div>
  <?php endif; ?>
  <?= View::render('partials/pager', ['page' => $page, 'pages' => $pages, 'baseUrl' => $baseUrl]) ?>
  <?php if (Voices::writeOpen()): ?>
    <aside class="write-cta"><div><p class="write-cta__h"><?= e(t('write_cta')) ?></p><p><?= e(t('write_cta_text')) ?></p></div><a class="btn btn--primary" href="<?= e(url(Voices::writeSlug())) ?>"><?= e(t('write_cta')) ?> →</a></aside>
  <?php endif; ?>
</div>
