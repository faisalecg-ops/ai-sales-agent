<?php
declare(strict_types=1);

/**
 * Your account: choose a new password.
 *
 * Open to everyone signed in, on either platform. A new account, or one the
 * super admin gave a temporary password, is sent here first and cannot open
 * anything else until it has chosen its own password.
 */

require __DIR__ . '/_admin.php';

$user   = admin_require_login();
$forced = (int)($user['must_change_password'] ?? 0) === 1;
$next   = (string)($_GET['next'] ?? '');
$errors = [];

$goOn = static function () use ($next, $user): never {
    if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
        header('Location: ' . $next);
        exit;
    }
    $platforms = Access::platforms((int)$user['id']);
    header('Location: ' . (!$platforms['sales'] && $platforms['social'] && admin_social_installed()
        ? url('social/') : url('admin/')));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $again   = (string)($_POST['new_password_again'] ?? '');

    if (!password_verify($current, (string)$user['password_hash'])) {
        $errors[] = 'Your current password is not right.';
    }
    if (mb_strlen($new) < 12) {
        $errors[] = 'Use a new password of at least 12 characters.';
    }
    if ($new !== $again) {
        $errors[] = 'The two new passwords do not match.';
    }
    if (!$errors && password_verify($new, (string)$user['password_hash'])) {
        $errors[] = 'Choose a password different from the current one.';
    }

    if (!$errors) {
        db()->update('admin_users', [
            'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'reset_token_hash'     => null,
            'reset_expires_at'     => null,
        ], 'id = ?', [(int)$user['id']]);
        session_regenerate_id(true);
        Audit::log('admin_password_changed', null, 'admin_user', (int)$user['id'],
            $forced ? 'Chose own password after a temporary one' : 'Changed own password');
        if ($forced) {
            $goOn();
        }
        admin_flash('Your password has been changed.');
        header('Location: ' . url('admin/account.php'));
        exit;
    }
}

$flash = admin_flash_render();

echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>Your account</title>'
   . '<link rel="stylesheet" href="' . e(url('assets/admin.css') . '?v=' . (int)@filemtime(__DIR__ . '/../assets/admin.css')) . '">'
   . '</head><body class="signin-page"><div class="signin"><div class="card">';

echo '<h1>' . ($forced ? 'Choose your password' : 'Your account') . '</h1>'
   . '<p class="sub">' . e((string)($user['full_name'] ?: $user['username'])) . ' · ' . e((string)$user['username'])
   . ' · ' . (Access::isSuper((int)$user['id']) ? 'super admin' : 'staff') . '</p>';

echo $flash;
if ($forced) {
    echo '<div class="alert alert-warn"><p>Before you continue, replace the password you were given with one '
       . 'only you know.</p></div>';
}
foreach ($errors as $err) {
    echo '<div class="alert alert-error"><p>' . e($err) . '</p></div>';
}

echo '<form method="post" action="' . e(url('admin/account.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''))) . '">'
   . Security::csrfField()
   . f_text('current_password', $forced ? 'Password you were given' : 'Current password', '',
        ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
   . f_text('new_password', 'New password', '', ['type' => 'password', 'required' => true,
        'autocomplete' => 'new-password', 'hint' => 'At least 12 characters. A short sentence is easy to remember and hard to guess.'])
   . f_text('new_password_again', 'New password again', '', ['type' => 'password', 'required' => true,
        'autocomplete' => 'new-password'])
   . '<button class="btn" type="submit" style="width:100%">Save new password</button>'
   . '</form>';

$platforms = Access::platforms((int)$user['id']);
echo '<p class="small muted" style="margin:16px 0 0;display:flex;gap:14px;flex-wrap:wrap">';
if (!$forced) {
    if ($platforms['sales']) {
        echo '<a href="' . e(url('admin/')) . '">AI Sales Agent</a>';
    }
    if ($platforms['social'] && admin_social_installed()) {
        echo '<a href="' . e(url('social/')) . '">AI Social Media Expert Agent</a>';
    }
}
echo '<a href="' . e(url('admin/logout.php')) . '">Sign out</a></p>';

echo '</div></div></body></html>';
