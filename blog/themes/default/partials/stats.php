<?php
/** The counter strip: figures that count up when they scroll into view (edited in Home page builder). */
$stats = array_values(array_filter((array)Settings::get('stats', []), static fn($s) => is_array($s) && (int)($s['value'] ?? 0) > 0 && trim((string)($s['label'] ?? '')) !== ''));
if (!$stats) {
    return;
}
$caption = trim((string)Settings::get('stats_caption', ''));
?>
<section class="stats section" aria-label="<?= e($caption !== '' ? $caption : Event::name()) ?>">
  <div class="wrap stats__in">
    <?php if ($caption !== ''): ?><p class="stats__caption reveal"><?= e(Event::fill($caption)) ?></p><?php endif; ?>
    <dl class="stats__grid">
      <?php foreach ($stats as $s): ?>
        <div class="stat reveal">
          <?php $suf = (string)($s['suffix'] ?? ''); ?>
          <dd class="stat__value"><span data-count="<?= (int)$s['value'] ?>"><?= e(number_format((int)$s['value'])) ?></span><?php if ($suf !== ''): ?><span class="stat__suffix<?= mb_strlen(trim($suf)) > 2 ? ' stat__suffix--long' : '' ?>"><?= e($suf) ?></span><?php endif; ?></dd>
          <dt class="stat__label"><?= e((string)$s['label']) ?></dt>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>
</section>
