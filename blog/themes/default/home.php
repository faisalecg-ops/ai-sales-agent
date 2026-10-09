<?php
/** @var array $sections @var array $slides @var array $featured @var array $latest @var array $blocks @var array $mostRead @var array $tags */
$transition = Settings::get('slider_transition', 'fade') === 'slide' ? 'slide' : 'fade';
$speed = max(3, min(20, (int)Settings::get('slider_speed', 6)));
$first = true;
foreach ($sections as $section):
    if (empty($section['enabled'])) {
        continue;
    }
    switch ($section['key'] ?? ''):

    case 'slider':
        if (!$slides) {
            break;
        } ?>
<section class="slider slider--<?= $transition ?>" data-slider data-speed="<?= $speed ?>" aria-roledescription="carousel" aria-label="Highlights">
  <div class="slider__track">
  <?php foreach ($slides as $i => $s): $img = Media::find((int)$s['image_id']); ?>
    <div class="slide<?= $i === 0 ? ' is-active' : '' ?>" role="group" aria-roledescription="slide" aria-label="<?= $i + 1 ?> / <?= count($slides) ?>"<?= $i > 0 ? ' aria-hidden="true"' : '' ?>>
      <?= Media::picture($img, 'slide__img', (string)$s['headline'], '100vw', $i === 0) ?>
      <div class="slide__shade"></div>
      <div class="wrap slide__content">
        <?php if ($s['headline'] !== ''): ?><h2 class="slide__title"><?= e($s['headline']) ?></h2><?php endif; ?>
        <?php if ($s['text'] !== ''): ?><p class="slide__text"><?= e($s['text']) ?></p><?php endif; ?>
        <?php if ($s['button_label'] !== '' && $s['button_url'] !== ''): ?><a class="btn btn--primary" href="<?= e($s['button_url']) ?>"<?= $i > 0 ? ' tabindex="-1"' : '' ?>><?= e($s['button_label']) ?></a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if (count($slides) > 1): ?>
  <div class="slider__controls wrap">
    <button type="button" class="slider__btn" data-prev aria-label="Previous slide"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2"/></svg></button>
    <div class="slider__dots"><?php foreach ($slides as $i => $s): ?><button type="button" class="slider__dot<?= $i === 0 ? ' is-active' : '' ?>" data-dot="<?= $i ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?></div>
    <button type="button" class="slider__btn" data-next aria-label="Next slide"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2"/></svg></button>
    <button type="button" class="slider__btn" data-pause aria-label="Pause slides"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M8 5v14M16 5v14" stroke="currentColor" stroke-width="2.5"/></svg></button>
  </div>
  <?php endif; ?>
</section>
<?php   break;

    case 'banner_top':
    case 'banner_middle':
        $b = Banner::render($section['key'] === 'banner_top' ? 'top' : 'middle');
        if ($b !== '') {
            echo '<div class="wrap section section--tight">' . $b . '</div>';
        }
        break;

    case 'featured':
        if (!$featured) {
            break;
        }
        $lead = $featured[0];
        $rest = array_slice($featured, 1); ?>
<section class="wrap section featured" aria-labelledby="h-featured">
  <h2 class="section-h" id="h-featured"><?= e(t('featured')) ?></h2>
  <div class="featured__grid">
    <div class="featured__lead"><?= View::card($lead, 'lead', $first && empty($slides)) ?></div>
    <?php if ($rest): ?><div class="featured__side"><?php foreach ($rest as $a) echo View::card($a, 'side'); ?></div><?php endif; ?>
  </div>
</section>
<?php   break;

    case 'event_panel':
        echo '<div class="wrap section">' . View::render('partials/event_panel') . '</div>';
        break;

    case 'latest':
        if (!$latest) {
            break;
        } ?>
<section class="wrap section" aria-labelledby="h-latest">
  <div class="section-head"><h2 class="section-h" id="h-latest"><?= e(t('latest')) ?></h2><a class="more" href="<?= e(url('latest')) ?>"><?= e(t('view_all')) ?> →</a></div>
  <div class="grid grid--3"><?php foreach ($latest as $a) echo View::card($a); ?></div>
</section>
<?php   break;

    case 'categories':
        foreach ($blocks as $blk):
            $c = $blk['category'];
            $arts = $blk['articles'];
            if (!$arts) continue; ?>
<section class="wrap section cat-block" aria-labelledby="h-cat-<?= (int)$c['id'] ?>">
  <div class="section-head"><h2 class="section-h" id="h-cat-<?= (int)$c['id'] ?>"><a href="<?= e(url($c['slug'] . '/')) ?>"><?= e($c['name']) ?></a></h2><a class="more" href="<?= e(url($c['slug'] . '/')) ?>"><?= e(t('view_all')) ?> →</a></div>
  <div class="grid grid--4"><?php foreach ($arts as $a) echo View::card($a); ?></div>
</section>
<?php   endforeach;
        break;

    case 'most_read':
        if (!$mostRead) {
            break;
        } ?>
<section class="wrap section" aria-labelledby="h-most">
  <h2 class="section-h" id="h-most"><?= e(t('most_read')) ?></h2>
  <ol class="most-read">
    <?php foreach ($mostRead as $i => $a): ?>
      <li class="reveal"><span class="most-read__n"><?= $i + 1 ?></span><div><?php if ($a['category_name']): ?><span class="fly"><?= e($a['category_name']) ?></span><?php endif; ?><a href="<?= e(Article::urlFor($a)) ?>"><?= e($a['title']) ?></a></div></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php   break;

    case 'tags':
        if (!$tags) {
            break;
        } ?>
<section class="wrap section" aria-labelledby="h-tags">
  <h2 class="section-h" id="h-tags"><?= e(t('topics')) ?></h2>
  <ul class="tags"><?php foreach ($tags as $tg): ?><li><a href="<?= e(url('tag/' . $tg['slug'])) ?>"><?= e($tg['name']) ?></a></li><?php endforeach; ?></ul>
</section>
<?php   break;

    case 'newsletter':
        $nl = View::render('partials/newsletter');
        if (trim($nl) !== '') {
            echo '<div class="wrap section">' . $nl . '</div>';
        }
        break;
    endswitch;
    $first = false;
endforeach;
if (!$featured && !$latest): ?>
<section class="wrap section"><p class="empty"><?= e(t('no_articles')) ?></p></section>
<?php endif; ?>
