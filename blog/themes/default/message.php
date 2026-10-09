<?php /** @var string $text */ ?>
<div class="wrap">
  <header class="page-head page-head--center">
    <h1 class="page-title"><?= e($text) ?></h1>
    <p><a class="btn btn--primary" href="<?= e(url()) ?>"><?= e(t('back_home')) ?></a></p>
  </header>
</div>
