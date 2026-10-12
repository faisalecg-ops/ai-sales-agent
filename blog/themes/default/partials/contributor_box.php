<?php
/** @var array $c  A contributor's short profile (Industry Voices). */
$photo = Media::find((int)$c['photo_id']);
$logo = Media::find((int)$c['company_logo_id']);
?>
<aside class="voice-box" aria-label="<?= e(t('about_author')) ?>">
  <?php if ($photo): ?><a class="voice-box__photo" href="<?= e(Voices::profileUrl($c)) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(Media::url($photo, 400)) ?>" alt="" loading="lazy"></a><?php endif; ?>
  <div class="voice-box__body">
    <p class="voice-box__h"><?= e(t('about_author')) ?></p>
    <p class="voice-box__name"><a href="<?= e(Voices::profileUrl($c)) ?>"><?= e($c['name']) ?></a></p>
    <?php if (Voices::role($c) !== ''): ?><p class="voice-box__role"><?= e(Voices::role($c)) ?></p><?php endif; ?>
    <?php if (trim((string)$c['bio']) !== ''): ?><p class="voice-box__bio"><?= e($c['bio']) ?></p><?php endif; ?>
    <p class="voice-box__links">
      <?php if ($c['linkedin_url'] !== ''): ?><a href="<?= e($c['linkedin_url']) ?>" rel="nofollow noopener" target="_blank">LinkedIn ↗</a><?php endif; ?>
      <?php if ($c['company_url'] !== ''): ?><a href="<?= e($c['company_url']) ?>" rel="nofollow noopener" target="_blank"><?= e($c['company'] !== '' ? $c['company'] : preg_replace('#^https?://(www\.)?#', '', $c['company_url'])) ?> ↗</a><?php endif; ?>
    </p>
  </div>
  <?php if ($logo): ?><div class="voice-box__logo"><img src="<?= e(Media::url($logo, 400)) ?>" alt="<?= e($c['company']) ?>" loading="lazy"></div><?php endif; ?>
</aside>
