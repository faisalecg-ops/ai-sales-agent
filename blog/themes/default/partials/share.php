<?php
/** @var string $u @var string $title */
$tag = static fn(string $src) => $u . (str_contains($u, '?') ? '&' : '?') . 'utm_source=' . $src . '&utm_medium=share';
$links = [
    'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($tag('linkedin')),
    'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($tag('facebook')),
    'X'        => 'https://x.com/intent/post?url=' . rawurlencode($tag('x')) . '&text=' . rawurlencode($title),
    'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($title . ' ' . $tag('whatsapp')),
    'Email'    => 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($tag('email')),
];
?>
<div class="share">
  <span class="share__label"><?= e(t('share')) ?></span>
  <?php foreach ($links as $name => $href): ?>
    <a class="share__btn" href="<?= e($href) ?>" <?= $name !== 'Email' ? 'target="_blank" rel="noopener"' : '' ?> aria-label="<?= e(t('share_on', ['site' => $name])) ?>"><?= e($name) ?></a>
  <?php endforeach; ?>
  <button type="button" class="share__btn" data-copy="<?= e($u) ?>"><?= e(t('copy_link')) ?></button>
</div>
