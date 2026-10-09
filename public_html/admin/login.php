<?php
declare(strict_types=1);

/**
 * Sign in. Failed attempts are throttled per username and per IP, so a
 * guessed password cannot be found by brute force (Section 38).
 */

require __DIR__ . '/_admin.php';

if (admin_user() !== null) {
    admin_redirect('admin/');
}

$errors = [];
$next   = (string)($_GET['next'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip       = Security::clientIp();

    if (!Security::loginAllowed($username, $ip)) {
        $errors[] = 'Too many attempts. Wait a few minutes and try again.';
    } elseif ($username === '' || $password === '') {
        $errors[] = 'Enter your username and password.';
    } else {
        $user = db()->one(
            'SELECT * FROM admin_users WHERE username = ? AND active = 1 LIMIT 1',
            [$username]
        );

        // The hash is always verified, even when no such user exists, so the
        // response takes the same time either way.
        $hash = (string)($user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        if ($user && password_verify($password, $hash)) {
            Security::recordLogin($username, $ip, true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$user['id'];
            db()->update('admin_users', [
                'last_login_at' => gmdate('Y-m-d H:i:s'),
                'last_login_ip' => $ip,
            ], 'id = ?', [(int)$user['id']]);
            Audit::setActor((string)$user['username']);
            Audit::log('admin_signed_in', null, 'admin_user', (int)$user['id'], 'From ' . $ip);

            // Only same-site paths are accepted as a redirect target.
            if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                header('Location: ' . $next);
                exit;
            }
            admin_redirect('admin/');
        }

        Security::recordLogin($username, $ip, false);
        Audit::log('admin_sign_in_failed', null, 'admin_user', null, 'username=' . $username . ' ip=' . $ip);
        $errors[] = 'That username and password did not match.';
    }
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>Sign in — AI Sales Agent</title>'
   . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '">'
   . '</head><body class="signin-page"><div class="signin">';

echo '<div class="card"><h1>Sign in</h1>';

// The most important place for this warning: if the address is wrong, the
// sign-in button itself does nothing and there is no way to get past it.
echo admin_address_warning();

foreach ($errors as $err) {
    echo '<div class="alert alert-error"><p>' . e($err) . '</p></div>';
}

echo '<form method="post" action="' . e(url('admin/login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''))) . '">'
   . Security::csrfField()
   . f_text('username', 'Username', (string)($_POST['username'] ?? ''), ['required' => true])
   . f_text('password', 'Password', '', ['type' => 'password', 'required' => true])
   . '<button class="btn" type="submit" style="width:100%">Sign in</button>'
   . '</form></div></div></body></html>';
