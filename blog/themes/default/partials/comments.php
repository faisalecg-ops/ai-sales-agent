<?php
/** @var array $comments @var int $articleId */
$state = (string)($_GET['c'] ?? '');
$msg = match ($state) {
    'thanks' => ['ok', t('comment_thanks')],
    'missing' => ['err', t('comment_missing')],
    'too_many' => ['err', t('too_many')],
    default => null,
};
?>
<section class="comments" id="comments" aria-labelledby="h-comments">
  <h2 class="section-h" id="h-comments"><?= e(t('comments')) ?><?= $comments ? ' (' . count($comments) . ')' : '' ?></h2>
  <?php if (!$comments): ?><p class="muted"><?= e(t('comments_none')) ?></p><?php endif; ?>
  <ol class="comments__list">
    <?php foreach ($comments as $c): ?>
      <li class="comment"><p class="comment__who"><strong><?= e($c['name']) ?></strong> <time><?= e(local_date($c['created_at'], 'j M Y')) ?></time></p><div class="comment__body"><?= nl2br(e($c['body'])) ?></div></li>
    <?php endforeach; ?>
  </ol>
  <form class="comment-form" method="post" action="<?= e(url('comment/' . $articleId)) ?>">
    <h3><?= e(t('comment_leave')) ?></h3>
    <?php if ($msg): ?><p class="notice notice--<?= $msg[0] ?>" role="status"><?= e($msg[1]) ?></p><?php endif; ?>
    <div class="comment-form__row">
      <label><span><?= e(t('your_name')) ?></span><input name="name" required maxlength="120" autocomplete="name"></label>
      <label><span><?= e(t('your_email')) ?></span><input name="email" type="email" required maxlength="190" autocomplete="email"></label>
    </div>
    <label><span><?= e(t('your_comment')) ?></span><textarea name="comment" rows="5" required maxlength="3000"></textarea></label>
    <?= Reader::timestampField() ?>
    <p class="muted small"><?= e(t('comment_note')) ?></p>
    <button class="btn btn--primary" type="submit"><?= e(t('send_comment')) ?></button>
  </form>
</section>
