<?php
/** @var array $a @var string $body @var array $toc @var array $tags @var ?array $image @var array $related @var array $crumbs @var ?array $comments */
$canonical = Article::urlFor($a);
$sideBanner = Banner::render('sidebar');
$hasSide = $sideBanner !== '' || $toc;
?>
<div class="wrap">
  <nav class="crumbs" aria-label="Breadcrumb">
    <ol><?php foreach ($crumbs as $i => [$name, $u]): ?><li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($u) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e(excerpt($name, 60)) ?></span><?php endif; ?></li><?php endforeach; ?></ol>
  </nav>
</div>

<article class="post">
  <header class="wrap post__head<?= $hasSide ? ' has-side' : '' ?>">
    <?php if ($a['category_name']): ?><a class="fly" href="<?= e(url($a['cat'] . '/')) ?>"><?= e($a['category_name']) ?></a><?php endif; ?>
    <h1 class="post__title"><?= e($a['title']) ?></h1>
    <?php if (trim((string)$a['excerpt']) !== ''): ?><p class="post__standfirst"><?= e($a['excerpt']) ?></p><?php endif; ?>
    <p class="meta post__meta">
      <?php if (!empty($a['author_name']) && (int)$a['show_as_author'] === 1): ?>
        <a href="<?= e(url('author/' . $a['author_slug'])) ?>" rel="author"><?= e(t('by', ['name' => $a['author_name']])) ?></a> <span aria-hidden="true">·</span>
      <?php endif; ?>
      <?php if ($a['published_at']): ?><time datetime="<?= e(gmdate('c', strtotime($a['published_at'] . ' UTC'))) ?>"><?= e(local_date($a['published_at'])) ?></time><?php endif; ?>
      <?php if (Settings::get('show_reading_time', true)): ?><span aria-hidden="true">·</span> <?= e(t('min_read', ['n' => (int)$a['reading_minutes']])) ?><?php endif; ?>
    </p>
  </header>

  <?php if ($image): ?>
  <figure class="wrap post__hero">
    <?= Media::picture($image, 'post__img', (string)($image['alt'] ?: $a['title']), '(max-width: 1100px) 100vw, 1060px', true) ?>
    <?php if ($image['caption'] !== '' || $image['credit'] !== ''): ?>
      <figcaption><?= e($image['caption']) ?><?php if ($image['credit'] !== ''): ?> <span class="credit"><?= e($image['credit']) ?></span><?php endif; ?></figcaption>
    <?php endif; ?>
  </figure>
  <?php endif; ?>

  <div class="wrap post__layout<?= $hasSide ? ' has-side' : '' ?>">
    <div class="post__main">
      <?php if ($toc): ?>
        <details class="toc toc--inline"><summary><?= e(t('contents')) ?></summary><ol><?php foreach ($toc as $h): ?><li class="toc__l<?= $h['level'] ?>"><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach; ?></ol></details>
      <?php endif; ?>
      <div class="prose"><?= $body ?></div>

      <?php if ($tags): ?>
        <ul class="tags tags--post" aria-label="<?= e(t('tags')) ?>"><?php foreach ($tags as $tg): ?><li><a href="<?= e(url('tag/' . $tg['slug'])) ?>"><?= e($tg['name']) ?></a></li><?php endforeach; ?></ul>
      <?php endif; ?>

      <?php if (Settings::get('show_share_buttons', true) && empty($isPreview)): ?>
        <?= View::render('partials/share', ['u' => $canonical, 'title' => (string)$a['title']]) ?>
      <?php endif; ?>

      <?= View::render('partials/courtesy') ?>

      <?= Banner::render('article') ?>

      <?php if ($comments !== null): ?>
        <?= View::render('partials/comments', ['comments' => $comments, 'articleId' => (int)$a['id']]) ?>
      <?php endif; ?>
    </div>

    <?php if ($hasSide): ?>
    <aside class="post__side">
      <?php if ($toc): ?>
        <nav class="toc toc--side" aria-label="<?= e(t('contents')) ?>"><p class="toc__h"><?= e(t('contents')) ?></p><ol><?php foreach ($toc as $h): ?><li class="toc__l<?= $h['level'] ?>"><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach; ?></ol></nav>
      <?php endif; ?>
      <?= $sideBanner ?>
    </aside>
    <?php endif; ?>
  </div>
</article>

<?php if ($related): ?>
<section class="wrap section related" aria-labelledby="h-related">
  <h2 class="section-h" id="h-related"><?= e(t('related')) ?></h2>
  <div class="grid grid--3"><?php foreach ($related as $r) echo View::card($r); ?></div>
</section>
<?php endif; ?>
