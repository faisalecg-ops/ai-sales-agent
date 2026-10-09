<?php
if (!Settings::get('newsletter_enabled', false)) {
    return;
}
$state = (string)($_GET['nl'] ?? '');
$msg = match ($state) {
    'thanks' => ['ok', t('nl_thanks')],
    'email' => ['err', t('nl_bad_email')],
    'consent' => ['err', t('nl_need_consent')],
    'too_many' => ['err', t('too_many')],
    default => null,
};
?>
<section class="newsletter reveal" id="newsletter">
  <div class="newsletter__text">
    <h2 class="section-h"><?= e((string)Settings::get('newsletter_title', '')) ?></h2>
    <p><?= e((string)Settings::get('newsletter_text', '')) ?></p>
  </div>
  <form class="newsletter__form" method="post" action="<?= e(url('subscribe')) ?>">
    <?php if ($msg): ?><p class="notice notice--<?= $msg[0] ?>" role="status"><?= e($msg[1]) ?></p><?php endif; ?>
    <div class="newsletter__row">
      <label class="sr" for="nl-email"><?= e(t('nl_email')) ?></label>
      <input id="nl-email" type="email" name="email" required placeholder="<?= e(t('nl_email')) ?>" autocomplete="email">
      <button class="btn btn--primary" type="submit"><?= e(t('nl_button')) ?></button>
    </div>
    <label class="check"><input type="checkbox" name="consent" value="1" required> <span><?= e((string)Settings::get('newsletter_consent', '')) ?></span></label>
    <?= Reader::timestampField() ?>
  </form>
</section>
