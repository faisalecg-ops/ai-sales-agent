<?php
/** @var array $a @var string $variant @var bool $eager */
$link = Article::urlFor($a);
$img = Media::find((int)$a['image_id']);
$showTime = Settings::get('show_reading_time', true);
$sizes = match ($variant) {
    'lead'    => '(max-width: 900px) 100vw, 760px',
    'mini'    => '120px',
    default   => '(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 380px',
};
?>
<article class="card card--<?= e($variant) ?> reveal">
  <?php if ($img && $variant !== 'compact'): ?>
    <a class="card__media" href="<?= e($link) ?>" tabindex="-1" aria-hidden="true"><?= Media::picture($img, 'card__img', (string)$a['title'], $sizes, $eager) ?></a>
  <?php endif; ?>
  <div class="card__body">
    <?php if (Sponsored::is($a)): ?><span class="flag flag--sponsor"><?= e(Sponsored::label($a)) ?> · <?= e($a['sponsor_name']) ?></span><?php elseif (!empty($a['contributor_name']) && Voices::enabled()): ?><span class="flag flag--voice"><?= e(Voices::badge()) ?></span><?php endif; ?>
    <?php if (!empty($a['category_name'])): ?><a class="fly" href="<?= e(url($a['cat'] . '/')) ?>"><?= e($a['category_name']) ?></a><?php endif; ?>
    <h3 class="card__title"><a href="<?= e($link) ?>"><?= e($a['title']) ?></a></h3>
    <?php if ($variant !== 'mini' && $variant !== 'compact'): ?>
      <p class="card__sum"><?= e(Article::summary($a, $variant === 'lead' ? 240 : 150)) ?></p>
    <?php endif; ?>
    <p class="meta">
      <?php if (!empty($a['contributor_name']) && $variant !== 'mini'): ?><span class="meta__by"><?= e($a['contributor_name']) ?><?= !empty($a['contributor_company']) ? ', ' . e($a['contributor_company']) : '' ?></span> <span aria-hidden="true">·</span><?php endif; ?>
      <time datetime="<?= e(gmdate('c', strtotime($a['published_at'] . ' UTC'))) ?>"><?= e(local_date($a['published_at'], 'j M Y')) ?></time>
      <?php if ($showTime && $variant !== 'mini'): ?><span aria-hidden="true">·</span> <?= e(t('min_read', ['n' => (int)$a['reading_minutes']])) ?><?php endif; ?>
    </p>
  </div>
</article>
