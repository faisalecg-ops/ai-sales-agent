<?php
$start = Event::startTime();
$dates = Event::dates();
if ($dates === '' && !Event::get('register_url') && !Event::get('book_stand_url')) {
    return;
}
$upcoming = Event::isUpcoming();
$compact = !empty($compact);
?>
<section class="event-panel<?= $compact ? ' event-panel--compact' : '' ?> reveal" aria-label="<?= e(Event::name()) ?>">
  <div class="event-panel__info">
    <p class="fly fly--light"><?= e(Event::name()) ?></p>
    <?php if ($tl = Event::get('event_tagline')): ?><h2 class="event-panel__title"><?= e($tl) ?></h2><?php endif; ?>
    <p class="event-panel__when"><?= e($dates) ?><?= Event::place() !== '' ? '<br>' . e(Event::place()) : '' ?></p>
  </div>
  <?php if ($start && $upcoming): ?>
    <div class="countdown" data-countdown="<?= (int)$start ?>" aria-label="<?= e(t('countdown_title')) ?>">
      <?php foreach (['days' => 'd', 'hours' => 'h', 'minutes' => 'm', 'seconds' => 's'] as $label => $k): ?>
        <div class="countdown__cell"><span class="countdown__n" data-<?= $k ?>>–</span><span class="countdown__l"><?= e(t($label)) ?></span></div>
      <?php endforeach; ?>
      <p class="countdown__live" hidden><?= e(t('show_on_now')) ?></p>
    </div>
  <?php endif; ?>
  <div class="event-panel__cta">
    <?php if ($r = Event::get('register_url')): ?><a class="btn btn--light" href="<?= e($r) ?>"><?= e(t('register_visit')) ?></a><?php endif; ?>
    <?php if ($s = Event::get('book_stand_url')): ?><a class="btn btn--outline-light" href="<?= e($s) ?>"><?= e(t('book_stand')) ?></a><?php endif; ?>
  </div>
</section>
