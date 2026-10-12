<?php
/** Who did what, and when. */
require_perm('activity');

$labels = [
    'login' => 'Signed in', 'logout' => 'Signed out', 'article.created' => 'Created article', 'article.published' => 'Published',
    'article.pending' => 'Sent for approval', 'article.draft' => 'Moved to draft', 'article.scheduled' => 'Scheduled',
    'article.archived' => 'Archived', 'article.deleted' => 'Deleted article', 'media.uploaded' => 'Uploaded image', 'media.deleted' => 'Deleted image',
    'branding.updated' => 'Changed branding', 'branding.undo' => 'Undid branding change', 'home.updated' => 'Changed home page',
    'api.article.created' => 'Agent sent an article', 'api.article.updated' => 'Agent updated an article',
    'submission.received' => '"Write for us" proposal received', 'submission.accepted' => 'Proposal turned into a draft', 'submission.declined' => 'Proposal declined',
    'contributor.saved' => 'Saved contributor', 'voices.settings' => 'Changed Industry Voices settings', 'sponsored.settings' => 'Changed sponsored-article wording',
];
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)db()->value('SELECT COUNT(*) FROM {p}activity');
$rows = db()->all('SELECT a.*, u.name AS user_name, k.name AS key_name FROM {p}activity a LEFT JOIN {p}users u ON u.id = a.user_id
    LEFT JOIN {p}api_keys k ON k.id = a.api_key_id ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50));
ob_start(); ?>
<section class="card card--flush">
  <table class="table table--list"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="small nowrap"><?= e(local_date($r['created_at'], 'j M Y, H:i')) ?></td>
      <td class="small"><?= e($r['user_name'] ?? ($r['key_name'] ? 'API: ' . $r['key_name'] : 'Automatic')) ?></td>
      <td><?= e($labels[$r['action']] ?? ucfirst(str_replace(['.', '_'], ' ', (string)$r['action']))) ?><?= $r['subject'] !== '' ? ': <span class="muted">' . e($r['subject']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?= pager_links($page, (int)ceil($total / 50), ['p' => 'activity']) ?>
<?php admin_render('Activity log', (string)ob_get_clean(), 'activity');
