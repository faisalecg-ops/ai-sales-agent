<?php
/** Readers' comments: nothing appears on the blog until approved here. */
require_perm('comments');

if (is_post()) {
    post_guard();
    $cid = (int)in('id');
    $do = (string)in('do');
    $map = ['approve' => 'approved', 'spam' => 'spam', 'reject' => 'rejected'];
    if (isset($map[$do])) {
        db()->update('comments', ['status' => $map[$do], 'moderated_by' => Auth::id()], 'id = ?', [$cid]);
        Activity::log('comment.' . $map[$do], (string)$cid);
        flash('ok', ['approve' => 'Comment approved and now visible.', 'spam' => 'Marked as spam.', 'reject' => 'Comment rejected.'][$do]);
    } elseif ($do === 'delete') {
        db()->delete('comments', 'id = ?', [$cid]);
        flash('ok', 'Comment deleted.');
    }
    Cache::clear();
    back_to('comments', ['status' => (string)($_GET['status'] ?? 'pending')]);
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'spam', 'rejected'], true) ? (string)$_GET['status'] : 'pending';
$rows = db()->all('SELECT c.*, a.title, a.slug, a.category_id FROM {p}comments c JOIN {p}articles a ON a.id = c.article_id WHERE c.status = ? ORDER BY c.id DESC LIMIT 100', [$status]);
$counts = [];
foreach (db()->all('SELECT status, COUNT(*) n FROM {p}comments GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['n'];
}
$mode = Settings::get('comments_mode', 'off');

ob_start(); ?>
<?php if ($mode !== 'approval'): ?>
  <div class="flash flash--info">Comments are switched <strong>off</strong> on the blog. Switch them on in <a href="<?= e(aurl('settings')) ?>">Readers &amp; general</a>.</div>
<?php endif; ?>
<nav class="tabs">
  <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'spam' => 'Spam', 'rejected' => 'Rejected'] as $k => $l): ?>
    <a href="<?= e(aurl('comments', ['status' => $k])) ?>"<?= $status === $k ? ' class="is-active"' : '' ?>><?= e($l) ?> (<?= $counts[$k] ?? 0 ?>)</a>
  <?php endforeach; ?>
</nav>
<?php if (!$rows): ?><p class="empty">Nothing here.</p><?php endif; ?>
<?php foreach ($rows as $c): ?>
  <article class="card comment-card">
    <p class="small muted"><strong><?= e($c['name']) ?></strong> · <?= e($c['email']) ?> · <?= e(local_date($c['created_at'], 'j M Y, H:i')) ?> · on <a href="<?= e(Article::urlFor($c)) ?>" target="_blank" rel="noopener"><?= e($c['title']) ?></a></p>
    <div class="comment-card__body"><?= nl2br(e($c['body'])) ?></div>
    <form method="post" class="row-btns">
      <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <?php if ($status !== 'approved'): ?><button class="btn btn--small btn--primary" name="do" value="approve">Approve</button><?php endif; ?>
      <?php if ($status !== 'rejected'): ?><button class="btn btn--small btn--ghost" name="do" value="reject"><?= $status === 'approved' ? 'Hide' : 'Reject' ?></button><?php endif; ?>
      <?php if ($status !== 'spam'): ?><button class="btn btn--small btn--ghost" name="do" value="spam">Spam</button><?php endif; ?>
      <button class="btn btn--small btn--quiet" name="do" value="delete" onclick="return confirm('Delete this comment?')">Delete</button>
    </form>
  </article>
<?php endforeach; ?>
<?php admin_render('Comments', (string)ob_get_clean(), 'comments');
