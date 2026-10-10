<?php
declare(strict_types=1);

/**
 * The review queue, and the reviewer links that let someone approve content
 * without an account (Section 24).
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('manage');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_link') {
        $selected = [];
        foreach ((array)($_POST['items'] ?? []) as $raw) {
            [$type, $id] = array_pad(explode(':', (string)$raw, 2), 2, '');
            if (isset(ReviewWorkflow::TYPES[$type]) && (int)$id > 0) {
                $selected[$type][] = (int)$id;
            }
        }

        if (!$selected) {
            admin_flash('Tick at least one item to send.', 'error');
            admin_redirect('admin/review.php');
        }

        $result = ReviewWorkflow::createToken(
            $agent,
            $selected,
            Validator::text($_POST['reviewer_name'] ?? null, 160),
            Validator::email($_POST['reviewer_email'] ?? null),
            (int)($_POST['days'] ?? 7),
            (string)$user['username']
        );

        $email = Validator::email($_POST['reviewer_email'] ?? null);
        if ($email !== null) {
            ReviewWorkflow::emailReviewLink(
                $agent, $result, $email,
                Validator::text($_POST['reviewer_name'] ?? null, 160),
                array_sum(array_map('count', $selected))
            );
            admin_flash('Link created and emailed to ' . $email . '.');
        }
        // Shown once, here and nowhere else: only a hash is stored, so the
        // link cannot be recovered later.
        $_SESSION['new_review_link'] = (string)$result['url'];
        admin_redirect('admin/review.php');
    }

    if ($action === 'revoke') {
        ReviewWorkflow::revokeToken($agent, (int)($_POST['token_id'] ?? 0), (string)$user['username']);
        admin_flash('Link revoked. It no longer works.');
        admin_redirect('admin/review.php');
    }

    if ($action === 'approve' || $action === 'reject') {
        $type = (string)($_POST['type'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);
        $note = Validator::paragraph($_POST['comment'] ?? null, 2000);
        if ($action === 'approve') {
            ReviewWorkflow::approve($agent, $type, $id, (string)$user['username'], $note);
            admin_flash('Approved and live.');
        } else {
            ReviewWorkflow::reject($agent, $type, $id, (string)$user['username'], $note);
            admin_flash('Sent back as a draft.');
        }
        admin_redirect('admin/review.php');
    }
}

$pending = ReviewWorkflow::pendingItems($agentId);
$tokens  = db()->all(
    "SELECT * FROM knowledge_review_tokens WHERE agent_id = ? ORDER BY id DESC LIMIT 20",
    [$agentId]
);

admin_layout_start('Review queue');

if (!empty($_SESSION['new_review_link'])) {
    $link = (string)$_SESSION['new_review_link'];
    unset($_SESSION['new_review_link']);
    echo '<div class="alert alert-ok"><p><strong>Here is the reviewer link.</strong> '
       . 'Copy it now — for security it is never stored, so this is the only time it can be shown.</p>'
       . '<pre style="margin:10px 0 0">' . e($link) . '</pre></div>';
}

echo '<p class="lede">Nothing here is visible to a visitor yet. Approve an item to put it live, '
   . 'or send the whole batch to someone else to check.</p>';

if (!$pending) {
    echo '<div class="empty">Nothing is waiting for review.</div>';
} else {
    echo '<form method="post"><div class="card" style="padding:0">' . Security::csrfField()
       . '<input type="hidden" name="action" value="create_link">'
       . '<div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px;width:34px"></th><th>Item</th><th>Kind</th><th>Changed</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($pending as $row) {
        $item = $row['item'];
        $key  = $row['type'] . ':' . (int)$item['id'];
        $edit = match ($row['type']) {
            'knowledge_article'   => 'knowledge.php',
            'faq'                 => 'faqs.php',
            'stall_package'       => 'pricing.php',
            'sponsorship_package' => 'sponsorship.php',
            default               => 'index.php',
        };

        echo '<tr><td style="padding-left:16px"><input type="checkbox" name="items[]" value="' . e($key) . '" checked></td>'
           . '<td>' . e(ReviewWorkflow::titleOf($row['type'], $item))
           . (!empty($item['approved_snapshot']) || !empty($item['approved_version_body']) || !empty($item['approved_version_answer'])
                ? ' <span class="pill pill-info">Edit to a live item</span>' : '')
           . '</td>'
           . '<td>' . e($row['label']) . '</td>'
           . '<td class="small muted">' . e(admin_time((string)$item['updated_at'])) . '</td>'
           . '<td><a href="' . e(url('admin/' . $edit . '?edit=' . (int)$item['id'])) . '">Open</a></td></tr>';
    }

    echo '</tbody></table></div>'
       . '<div style="padding:16px;border-top:1px solid var(--line)">'
       . '<h3>Send these to someone to check</h3>'
       . '<p class="hint" style="margin-bottom:12px">They get a link that opens straight onto the items. '
       . 'No account or password is needed, and the link expires.</p>'
       . '<div class="field-row">'
       . f_text('reviewer_name', 'Their name', '')
       . f_text('reviewer_email', 'Their email', '', ['type' => 'email', 'hint' => 'Leave blank to copy the link yourself.'])
       . f_text('days', 'Link lasts (days)', '7', ['type' => 'number'])
       . '</div>'
       . '<button class="btn" type="submit">Create the reviewer link</button>'
       . '</div></div></form>';
}

// ---- Existing links ----
echo '<div class="card"><h2>Reviewer links</h2>';
if (!$tokens) {
    echo '<div class="empty">None created yet.</div>';
} else {
    echo '<div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Reviewer</th><th>Items</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody>';
    foreach ($tokens as $t) {
        $map   = (array)json_decode((string)$t['item_ids'], true);
        $count = array_sum(array_map('count', $map));
        $expired = strtotime((string)$t['expires_at'] . ' UTC') < time();
        $state = match (true) {
            (string)$t['status'] === 'revoked' => '<span class="pill pill-bad">Revoked</span>',
            (string)$t['status'] === 'used'    => '<span class="pill pill-ok">Finished</span>',
            $expired                           => '<span class="pill">Expired</span>',
            default                            => '<span class="pill pill-warn">Active</span>',
        };
        echo '<tr><td>' . e((string)($t['reviewer_name'] ?: 'Not named'))
           . ((string)$t['reviewer_email'] !== '' ? '<br><span class="small muted">' . e((string)$t['reviewer_email']) . '</span>' : '')
           . '</td>'
           . '<td class="num">' . (int)$count . '</td>'
           . '<td>' . $state . '</td>'
           . '<td class="small muted">' . e(admin_time((string)$t['expires_at'])) . '</td>'
           . '<td>';
        if ((string)$t['status'] === 'active' && !$expired) {
            echo '<form method="post" style="margin:0">' . Security::csrfField()
               . '<input type="hidden" name="action" value="revoke">'
               . '<input type="hidden" name="token_id" value="' . (int)$t['id'] . '">'
               . '<button class="btn btn-small btn-quiet" type="submit">Revoke</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>'
       . '<p class="hint">For security the link itself is never stored, so it cannot be shown again. '
       . 'If it is lost, revoke it and create another.</p>';
}
echo '</div>';

admin_layout_end();
