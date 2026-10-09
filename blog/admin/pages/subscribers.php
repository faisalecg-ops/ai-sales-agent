<?php
/** Newsletter subscribers, with their consent record. */
require_perm('subscribers');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Email', 'Name', 'Status', 'Signed up', 'Confirmed', 'Consent wording', 'Page', 'Unsubscribe link']);
    foreach (db()->all("SELECT * FROM {p}subscribers WHERE status = 'confirmed' ORDER BY id") as $s) {
        fputcsv($out, [$s['email'], $s['name'], $s['status'], $s['created_at'] . ' UTC', $s['confirmed_at'] ? $s['confirmed_at'] . ' UTC' : '',
            $s['consent_text'], $s['consent_page'], url('unsubscribe/' . $s['token'])]);
    }
    Activity::log('subscribers.exported');
    exit;
}
if (is_post()) {
    post_guard();
    $sid = (int)in('id');
    if (in('do') === 'delete') {
        db()->delete('subscribers', 'id = ?', [$sid]);
        Activity::log('subscriber.deleted', (string)$sid);
        flash('ok', 'Subscriber deleted completely.');
    } elseif (in('do') === 'unsubscribe') {
        db()->update('subscribers', ['status' => 'unsubscribed', 'unsubscribed_at' => now_utc()], 'id = ?', [$sid]);
        flash('ok', 'Subscriber unsubscribed.');
    }
    back_to('subscribers');
}

$status = in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'unsubscribed'], true) ? (string)$_GET['status'] : 'confirmed';
$rows = db()->all('SELECT * FROM {p}subscribers WHERE status = ? ORDER BY id DESC LIMIT 500', [$status]);
$counts = [];
foreach (db()->all('SELECT status, COUNT(*) n FROM {p}subscribers GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['n'];
}

ob_start(); ?>
<p class="help">Only confirmed subscribers (who ticked the consent box <em>and</em> clicked the link in their confirmation email) may be sent newsletters. Each row keeps the exact consent wording and the page where they signed up. Every newsletter must carry the person's unsubscribe link (included in the export).</p>
<nav class="tabs">
  <?php foreach (['confirmed' => 'Confirmed', 'pending' => 'Not yet confirmed', 'unsubscribed' => 'Unsubscribed'] as $k => $l): ?>
    <a href="<?= e(aurl('subscribers', ['status' => $k])) ?>"<?= $status === $k ? ' class="is-active"' : '' ?>><?= e($l) ?> (<?= $counts[$k] ?? 0 ?>)</a>
  <?php endforeach; ?>
</nav>
<section class="card card--flush">
  <?php if (!$rows): ?><p class="empty">Nobody here yet.</p><?php else: ?>
  <table class="table table--list"><thead><tr><th>Email</th><th class="hide-s">Signed up</th><th class="hide-s">Confirmed</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $s): ?>
    <tr><td><?= e($s['email']) ?><?= $s['name'] ? '<br><span class="small muted">' . e($s['name']) . '</span>' : '' ?></td>
      <td class="hide-s small"><?= e(local_date($s['created_at'], 'j M Y')) ?></td>
      <td class="hide-s small"><?= e(local_date($s['confirmed_at'], 'j M Y')) ?></td>
      <td class="right nowrap"><form method="post" class="inline"><?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <?php if ($s['status'] !== 'unsubscribed'): ?><button class="btn btn--small btn--ghost" name="do" value="unsubscribe">Unsubscribe</button><?php endif; ?>
        <button class="btn btn--small btn--quiet" name="do" value="delete" onclick="return confirm('Delete this person and their consent record completely?')">Delete</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php admin_render('Subscribers', (string)ob_get_clean(), 'subscribers', ['actions' => '<a class="btn" href="' . e(aurl('subscribers', ['export' => 'csv'])) . '">Download confirmed (spreadsheet)</a>']);
