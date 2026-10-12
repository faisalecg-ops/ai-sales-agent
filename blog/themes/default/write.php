<?php
/** @var string $state @var string $heading @var string $intro @var string $consent @var string $action @var array $crumbs */
$msg = match ($state) {
    'thanks' => ['ok', t('write_thanks')],
    'missing' => ['err', t('write_missing')],
    'consent' => ['err', t('write_consent_needed')],
    'too_many' => ['err', t('too_many')],
    default => null,
};
?>
<div class="wrap wrap--narrow">
  <nav class="crumbs" aria-label="Breadcrumb"><ol><?php foreach ($crumbs as $i => [$name, $u]): ?><li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($u) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e($name) ?></span><?php endif; ?></li><?php endforeach; ?></ol></nav>
  <header class="page-head">
    <h1 class="page-title"><?= e($heading) ?></h1>
    <?php if (trim($intro) !== ''): ?><p class="page-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
  <form class="comment-form write-form" id="write" method="post" action="<?= e($action) ?>">
    <?php if ($msg): ?><p class="notice notice--<?= $msg[0] ?>" role="status"><?= e($msg[1]) ?></p><?php endif; ?>
    <?php if ($state !== 'thanks'): ?>
    <div class="comment-form__row">
      <label><span><?= e(t('write_name')) ?> *</span><input name="name" required maxlength="120" autocomplete="name"></label>
      <label><span><?= e(t('write_email')) ?> *</span><input name="email" type="email" required maxlength="190" autocomplete="email"></label>
    </div>
    <div class="comment-form__row">
      <label><span><?= e(t('write_designation')) ?> *</span><input name="designation" required maxlength="160" autocomplete="organization-title"></label>
      <label><span><?= e(t('write_company')) ?> *</span><input name="company" required maxlength="160" autocomplete="organization"></label>
    </div>
    <div class="comment-form__row">
      <label><span><?= e(t('write_company_url')) ?></span><input name="company_url" type="url" maxlength="500" placeholder="https://"></label>
      <label><span><?= e(t('write_linkedin')) ?></span><input name="linkedin_url" type="url" maxlength="500" placeholder="https://www.linkedin.com/in/…"></label>
    </div>
    <label><span><?= e(t('write_phone')) ?></span><input name="phone" maxlength="40" autocomplete="tel"></label>
    <fieldset class="write-kind"><legend><?= e(t('write_kind')) ?></legend>
      <label><input type="radio" name="kind" value="outline" checked> <?= e(t('write_outline')) ?></label>
      <label><input type="radio" name="kind" value="draft"> <?= e(t('write_draft')) ?></label>
    </fieldset>
    <label><span><?= e(t('write_title')) ?> *</span><input name="title" required maxlength="255"></label>
    <label><span><?= e(t('write_body')) ?> *</span><textarea name="body" rows="14" required minlength="40" maxlength="30000"></textarea><small class="muted"><?= e(t('write_body_help')) ?></small></label>
    <label class="consent"><input type="checkbox" name="consent" value="1" required> <?= e($consent) ?></label>
    <?= Reader::timestampField() ?>
    <button class="btn btn--primary" type="submit"><?= e(t('write_send')) ?></button>
    <?php endif; ?>
  </form>
</div>
