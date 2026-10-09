<?php /** @var array $latest */ ?>
<div class="wrap">
  <header class="page-head page-head--center">
    <p class="fly">404</p>
    <h1 class="page-title"><?= e(t('not_found_title')) ?></h1>
    <p class="page-intro"><?= e(t('not_found_text')) ?></p>
    <form class="search search--big" action="<?= e(url('search')) ?>" method="get" role="search">
      <label class="sr" for="q3"><?= e(t('search')) ?></label>
      <input id="q3" name="q" type="search" placeholder="<?= e(t('search_placeholder')) ?>">
      <button class="btn btn--primary" type="submit"><?= e(t('search')) ?></button>
    </form>
    <p><a href="<?= e(url()) ?>"><?= e(t('back_home')) ?> →</a></p>
  </header>
  <?php if ($latest): ?>
  <section class="section"><h2 class="section-h"><?= e(t('latest')) ?></h2><div class="grid grid--3"><?php foreach ($latest as $a) echo View::card($a); ?></div></section>
  <?php endif; ?>
</div>
