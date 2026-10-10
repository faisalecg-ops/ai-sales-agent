<?php
declare(strict_types=1);

/**
 * Sign in. Failed attempts are throttled per username and per IP, so a
 * guessed password cannot be found by brute force (Section 38).
 */

require __DIR__ . '/_admin.php';

$errors = [];
$next   = (string)($_GET['next'] ?? '');

/**
 * Where to go after signing in. A same-site path asked for in ?next= wins
 * (/social uses this). Otherwise the user's own platform: the sales agent, or
 * /social for someone who only has access there. Someone with no access at
 * all reaches the sales agent, which tells them so.
 */
$destination = static function (array $user) use ($next): string {
    if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
        return $next;
    }
    $platforms = Access::platforms((int)$user['id']);
    if (!$platforms['sales'] && $platforms['social'] && admin_social_installed()) {
        return url('social/');
    }
    return url('admin/');
};

if (($signedIn = admin_user()) !== null) {
    header('Location: ' . ((int)($signedIn['must_change_password'] ?? 0) === 1
        ? url('admin/account.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''))
        : $destination($signedIn)));
    exit;
}

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

            // A new password comes first; then the page that was asked for.
            if ((int)($user['must_change_password'] ?? 0) === 1) {
                header('Location: ' . url('admin/account.php' . ($next !== '' ? '?next=' . rawurlencode($next) : '')));
                exit;
            }
            header('Location: ' . $destination($user));
            exit;
        }

        Security::recordLogin($username, $ip, false);
        Audit::log('admin_sign_in_failed', null, 'admin_user', null, 'username=' . $username . ' ip=' . $ip);
        $errors[] = 'That username and password did not match.';
    }
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>Sign in</title>'
   . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '">'
   . '</head><body class="signin-page"><div class="signin">';

echo '<div class="card"><h1>Sign in</h1>'
   . '<p class="sub">AI Sales Agent · AI Social Media Expert Agent</p>';

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
