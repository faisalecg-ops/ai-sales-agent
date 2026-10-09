<?php
/** @var int $page @var int $pages @var string $baseUrl */
if ($pages <= 1) {
    return;
}
$sep = str_contains($baseUrl, '?') ? '&' : '?';
$link = static fn(int $p) => $p === 1 ? $baseUrl : $baseUrl . $sep . 'page=' . $p;
?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?><a class="pager__btn" href="<?= e($link($page - 1)) ?>" rel="prev">← <?= e(t('newer')) ?></a><?php else: ?><span></span><?php endif; ?>
  <span class="pager__n"><?= e(t('page_n', ['n' => $page])) ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="pager__btn" href="<?= e($link($page + 1)) ?>" rel="next"><?= e(t('older')) ?> →</a><?php else: ?><span></span><?php endif; ?>
</nav>
