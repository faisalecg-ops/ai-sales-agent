<?php
/** Sign-in page. */
$error = '';
$email = '';
if (is_post()) {
    if (!Auth::checkCsrf()) {
        $error = 'The page had expired. Please sign in again.';
    } else {
        $email = in_str('email', 190);
        $res = Auth::attempt($email, (string)in('password'));
        if ($res['ok']) {
            $next = (string)($_GET['next'] ?? '');
            redirect($next !== '' && (str_starts_with($next, url('admin/')) || str_starts_with($next, local_url('admin/'))) ? $next : aurl());
        }
        $error = $res['error'] === 'locked'
            ? 'Too many failed attempts. For safety, sign-in is paused for 15 minutes.'
            : 'The email or password is not correct.';
    }
}
$site = (string)Settings::get('site_name', 'Event Blog');
ob_start(); ?>
<div class="login">
  <div class="login__box">
    <p class="login__brand"><?= e($site) ?></p>
    <h1>Sign in</h1>
    <?php if (isset($_GET['installed'])): ?><div class="flash flash--ok">The blog is installed. Sign in with the admin account you just created.</div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash--err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(local_url('admin/') . (isset($_GET['next']) ? '?next=' . rawurlencode((string)$_GET['next']) : '')) ?>">
      <?= Auth::csrfField() ?>
      <?= f_text('email', 'Email', $email, '', ['type' => 'email', 'required' => true, 'autocomplete' => 'username', 'autofocus' => true]) ?>
      <?= f_text('password', 'Password', '', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
      <button class="btn btn--primary btn--block" type="submit">Sign in</button>
    </form>
    <p class="help">Forgotten your password? Ask another admin to set a new one in Users.</p>
  </div>
</div>
<?php admin_render('Sign in', (string)ob_get_clean());
