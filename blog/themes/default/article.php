<?php
/** @var array $a @var string $body @var array $toc @var array $tags @var ?array $image @var array $related @var array $crumbs @var ?array $comments @var ?array $contributor */
$sponsored = Sponsored::is($a);
$voice = $contributor && Voices::enabled() ? $contributor : null;
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
    <?php if ($sponsored): ?>
      <div class="sponsor-bar">
        <span class="flag flag--sponsor"><?= e(Sponsored::label($a)) ?></span>
        <?php $slogo = Media::find((int)$a['sponsor_logo_id']); ?>
        <?php if ($a['sponsor_url'] !== ''): ?><a class="sponsor-bar__who" href="<?= e(Sponsored::goUrl((int)$a['id'], (string)$a['sponsor_url'])) ?>" rel="sponsored noopener" target="_blank"><?php else: ?><span class="sponsor-bar__who"><?php endif; ?>
          <?php if ($slogo): ?><img src="<?= e(Media::url($slogo, 400)) ?>" alt="<?= e($a['sponsor_name']) ?>" class="sponsor-bar__logo"><?php else: ?><?= e($a['sponsor_name']) ?><?php endif; ?>
        <?php if ($a['sponsor_url'] !== ''): ?></a><?php else: ?></span><?php endif; ?>
      </div>
    <?php elseif ($voice): ?>
      <a class="flag flag--voice" href="<?= e(Voices::sectionUrl()) ?>"><?= e(Voices::badge()) ?></a>
    <?php endif; ?>
    <?php if ($a['category_name']): ?><a class="fly" href="<?= e(url($a['cat'] . '/')) ?>"><?= e($a['category_name']) ?></a><?php endif; ?>
    <h1 class="post__title"><?= e($a['title']) ?></h1>
    <?php if (trim((string)$a['excerpt']) !== ''): ?><p class="post__standfirst"><?= e($a['excerpt']) ?></p><?php endif; ?>
    <p class="meta post__meta">
      <?php if ($voice): ?>
        <a href="<?= e(Voices::profileUrl($voice)) ?>" rel="author"><?= e(t('by_voice', ['name' => $voice['name']])) ?></a><?php if (Voices::role($voice) !== ''): ?>, <?= e(Voices::role($voice)) ?><?php endif; ?> <span aria-hidden="true">·</span>
      <?php elseif (!empty($a['author_name']) && (int)$a['show_as_author'] === 1): ?>
        <a href="<?= e(url('author/' . $a['author_slug'])) ?>" rel="author"><?= e(t('by', ['name' => $a['author_name']])) ?></a> <span aria-hidden="true">·</span>
      <?php endif; ?>
      <?php if ($a['published_at']): ?><time datetime="<?= e(gmdate('c', strtotime($a['published_at'] . ' UTC'))) ?>"><?= e(local_date($a['published_at'])) ?></time><?php endif; ?>
      <?php if (Settings::get('show_reading_time', true)): ?><span aria-hidden="true">·</span> <?= e(t('min_read', ['n' => (int)$a['reading_minutes']])) ?><?php endif; ?>
    </p>
    <?php if ($sponsored): ?><p class="disclosure"><?= e(Sponsored::disclosure($a)) ?></p><?php endif; ?>
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

      <?php if ($voice): ?>
        <?php if (Voices::disclaimer() !== ''): ?><p class="disclaimer"><?= e(Voices::disclaimer()) ?></p><?php endif; ?>
        <?= View::render('partials/contributor_box', ['c' => $voice]) ?>
      <?php endif; ?>

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
  <h2 class="section-h reveal" id="h-related"><?= e(t('related')) ?></h2>
  <div class="grid grid--3"><?php foreach ($related as $r) echo View::card($r); ?></div>
</section>
<?php endif; ?>
