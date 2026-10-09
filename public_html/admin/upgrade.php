<?php
declare(strict_types=1);

/**
 * Database upgrades, run from the panel.
 *
 * The alternative is pasting SQL into phpMyAdmin, which works but gives no
 * check that it was needed, no record that it happened, and no safe answer to
 * "did that already run?". This page asks the database what it has, shows
 * what is missing, and applies only that.
 */

require __DIR__ . '/_admin.php';

$user = admin_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $result = Migrations::run((string)$user['username']);

    if ($result['failed'] !== null) {
        admin_flash('Stopped at "' . $result['failed'] . '": ' . $result['error']
            . ' Anything applied before it is in place, and running this again will carry on from there.', 'error');
    } elseif ($result['applied']) {
        admin_flash(count($result['applied']) . ' change(s) applied: ' . implode(', ', $result['applied']));
    } else {
        admin_flash('Nothing needed doing. The database is already up to date.');
    }
    admin_redirect('admin/upgrade.php');
}

$pending = Migrations::pending();

admin_layout_start('Database upgrade');

if (!$pending) {
    echo '<div class="card"><h2>Up to date</h2>'
       . '<p class="lede">Every change this version needs is already in place. '
       . 'There is nothing to do here.</p>'
       . '<p class="hint">This page is safe to open at any time. It only ever adds what is '
       . 'missing, so it can be run twice without harm.</p></div>';
    admin_layout_end();
    exit;
}

echo '<div class="card"><h2>' . count($pending) . ' change(s) to apply</h2>'
   . '<p class="lede">These add new records the platform needs. Nothing existing is changed '
   . 'or deleted, and your knowledge, pricing, leads and conversations are untouched.</p>';

echo '<div class="table-wrap"><table class="table"><thead><tr>'
   . '<th>Change</th></tr></thead><tbody>';
foreach ($pending as $step) {
    echo '<tr><td>' . e((string)$step['label']) . '</td></tr>';
}
echo '</tbody></table></div>';

echo '<p class="hint">Take a database backup first if you have not got a recent one. '
   . 'cPanel can make one from Backup Wizard in about a minute.</p>';

echo '<form method="post">' . Security::csrfField()
   . '<div class="btn-row"><button class="btn" type="submit">Apply the changes</button></div>'
   . '</form></div>';

admin_layout_end();
