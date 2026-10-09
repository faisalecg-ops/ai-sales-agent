<?php
if (!Settings::get('courtesy_enabled', true)) {
    return;
}
$event = Event::name();
$site = Event::get('main_site_url');
$logo = Media::find((int)Settings::get('logo_light', 0));
$about = trim((string)Settings::get('courtesy_text', '')) ?: (Event::get('event_tagline') ?: (string)Settings::get('event_about', ''));
$dates = Event::dates();
?>
<aside class="courtesy reveal" aria-label="<?= e(Event::fill((string)Settings::get('courtesy_title', 'Courtesy of {event}'))) ?>">
  <div class="courtesy__brand">
    <?php if ($logo): ?><img src="<?= e(Media::url($logo, 400)) ?>" alt="<?= e($event) ?>" loading="lazy" width="<?= (int)$logo['width'] ?>" height="<?= (int)$logo['height'] ?>"><?php else: ?><span class="brand__box"><?= e($event) ?></span><?php endif; ?>
  </div>
  <div class="courtesy__body">
    <p class="courtesy__title"><?= e(Event::fill((string)Settings::get('courtesy_title', 'Courtesy of {event}'))) ?></p>
    <?php if ($about !== ''): ?><p class="courtesy__about"><?= e(Event::fill($about)) ?></p><?php endif; ?>
    <?php if ($dates !== '' || Event::place() !== ''): ?>
      <p class="courtesy__when"><?= e($dates) ?><?= $dates !== '' && Event::place() !== '' ? ' · ' : '' ?><?= e(Event::place()) ?></p>
    <?php endif; ?>
    <div class="courtesy__cta">
      <?php if ($site): ?><a class="btn btn--primary" href="<?= e($site) ?>"><?= e(t('visit_website')) ?></a><?php endif; ?>
      <?php if ($r = Event::get('register_url')): ?><a class="btn btn--ghost" href="<?= e($r) ?>"><?= e(t('register_visit')) ?></a><?php endif; ?>
      <?php if ($s = Event::get('book_stand_url')): ?><a class="btn btn--ghost" href="<?= e($s) ?>"><?= e(t('book_stand')) ?></a><?php endif; ?>
    </div>
  </div>
</aside>
