<?php
/** "Write for us": proposals from industry experts, waiting for an editor. */
require_perm('content');

if (is_post()) {
    post_guard();
    $sid = (int)in('id');
    $s = db()->one('SELECT * FROM {p}submissions WHERE id = ?', [$sid]);
    if (!$s) {
        flash('err', 'That proposal no longer exists.');
        back_to('submissions');
    }
    $do = (string)in('do');
    if ($do === 'accept') {
        try {
            $articleId = Voices::accept($sid);
        } catch (Throwable $ex) {
            blog_log('submissions', 'accept failed', ['error' => $ex->getMessage()]);
            flash('err', 'The draft could not be made. Please try again.');
            back_to('submissions', ['id' => $sid]);
        }
        flash('ok', 'A draft article was made from the proposal, with ' . $s['name'] . ' as its ' . Voices::badge() . ' author. Choose a category, add a photo, edit, then publish or send for approval.');
        back_to('article', ['id' => $articleId]);
    }
    if (in_array($do, ['decline', 'spam', 'reopen'], true)) {
        $to = ['decline' => 'declined', 'spam' => 'spam', 'reopen' => 'new'][$do];
        db()->update('submissions', ['status' => $to, 'handled_by' => Auth::id()], 'id = ?', [$sid]);
        if ($do === 'decline') {
            Activity::log('submission.declined', (string)$s['title'], ['id' => $sid]);
        }
        flash('ok', ['decline' => 'Marked as declined. Reply to the writer by email if you wish.', 'spam' => 'Marked as spam.', 'reopen' => 'Moved back to New.'][$do]);
        back_to('submissions', $do === 'reopen' ? ['id' => $sid] : []);
    }
    back_to('submissions');
}

$labels = ['new' => 'New', 'accepted' => 'Made into a draft', 'declined' => 'Declined', 'spam' => 'Spam'];
$id = (int)($_GET['id'] ?? 0);
ob_start();

if ($id && ($s = db()->one('SELECT * FROM {p}submissions WHERE id = ?', [$id]))):
    $known = $s['email'] !== '' ? db()->one('SELECT * FROM {p}contributors WHERE email = ? ORDER BY id LIMIT 1', [$s['email']]) : null; ?>
<div class="editor-page">
  <div class="editor-main">
    <section class="card">
      <p class="small muted"><?= e($s['kind'] === 'draft' ? 'Full draft' : 'Outline or idea') ?> · received <?= e(local_date($s['created_at'], 'j M Y, H:i')) ?></p>
      <h2><?= e($s['title']) ?></h2>
      <div class="submission-text"><?= nl2br(e($s['body'])) ?></div>
    </section>
  </div>
  <aside class="editor-side">
    <section class="card">
      <p class="status-line">Status: <span class="badge badge--<?= e($s['status'] === 'new' ? 'pending' : ($s['status'] === 'accepted' ? 'published' : 'archived')) ?>"><?= e($labels[$s['status']]) ?></span></p>
      <p><strong><?= e($s['name']) ?></strong><br><?= e(trim($s['designation'] . ', ' . $s['company'], ', ')) ?></p>
      <p class="small"><a href="mailto:<?= e($s['email']) ?>?subject=<?= rawurlencode('Your proposal: ' . $s['title']) ?>"><?= e($s['email']) ?></a><?= $s['phone'] !== '' ? '<br>' . e($s['phone']) : '' ?></p>
      <p class="small"><?php if ($s['linkedin_url'] !== ''): ?><a href="<?= e($s['linkedin_url']) ?>" target="_blank" rel="noopener nofollow">LinkedIn ↗</a> <?php endif; ?><?php if ($s['company_url'] !== ''): ?><a href="<?= e($s['company_url']) ?>" target="_blank" rel="noopener nofollow">Company website ↗</a><?php endif; ?></p>
      <?php if ($known): ?><p class="small muted">Already a contributor: <a href="<?= e(aurl('contributors', ['edit' => $known['id']])) ?>"><?= e($known['name']) ?></a> — the draft will use that profile.</p><?php endif; ?>
      <div class="actions">
        <?php if ($s['status'] === 'accepted' && $s['article_id']): ?>
          <a class="btn btn--primary btn--block" href="<?= e(aurl('article', ['id' => $s['article_id']])) ?>">Open the draft article</a>
        <?php elseif ($s['status'] === 'new'): ?>
          <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn--primary btn--block" name="do" value="accept">Make it a draft article</button></form>
          <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn--ghost btn--block" name="do" value="decline">Decline</button>
            <button class="btn btn--quiet btn--block" name="do" value="spam">Spam</button></form>
        <?php else: ?>
          <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn--ghost btn--block" name="do" value="reopen">Move back to New</button></form>
        <?php endif; ?>
      </div>
      <p class="help">“Make it a draft article” creates the writer's contributor profile (if needed) and a draft with their text. Nothing is published until an approver publishes it.</p>
    </section>
  </aside>
</div>
<?php
else:
    $status = isset($labels[$_GET['status'] ?? '']) ? (string)$_GET['status'] : 'new';
    $rows = db()->all('SELECT * FROM {p}submissions WHERE status = ? ORDER BY id DESC LIMIT 200', [$status]); ?>
<nav class="tabs"><?php foreach ($labels as $k => $l): ?><a href="<?= e(aurl('submissions', ['status' => $k])) ?>"<?= $k === $status ? ' class="is-active" aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></nav>
<?php if (!Voices::writeOpen()): ?><div class="flash flash--warn">The public “Write for us” form is switched off.<?php if (Auth::can('settings')): ?> <a href="<?= e(aurl('contributors', ['tab' => 'settings'])) ?>">Settings</a>.<?php endif; ?></div><?php endif; ?>
<section class="card card--flush">
  <?php if (!$rows): ?>
    <p class="empty">Nothing here.<?php if ($status === 'new' && Voices::writeOpen()): ?> Proposals sent through <a href="<?= e(url(Voices::writeSlug())) ?>" target="_blank" rel="noopener"><?= e(url(Voices::writeSlug())) ?></a> appear here.<?php endif; ?></p>
  <?php else: ?>
  <table class="table table--list"><thead><tr><th>Proposal</th><th class="hide-s">From</th><th class="hide-s">Received</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= e(aurl('submissions', ['id' => $r['id']])) ?>"><strong><?= e($r['title']) ?></strong></a><br><span class="small muted"><?= e($r['kind'] === 'draft' ? 'Full draft' : 'Outline') ?></span></td>
      <td class="hide-s"><?= e($r['name']) ?><br><span class="small muted"><?= e($r['company']) ?></span></td>
      <td class="hide-s small"><?= e(local_date($r['created_at'], 'j M Y')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php endif;
admin_render('Write for us', (string)ob_get_clean(), 'submissions');
