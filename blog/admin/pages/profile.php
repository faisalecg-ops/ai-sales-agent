<?php
/** Your own name, author details and password. */
$me = Auth::user();
if (is_post()) {
    post_guard();
    $row = ['name' => in_str('name', 120) ?: $me['name'], 'title' => in_str('title', 160), 'bio' => in_str('bio', 1000), 'avatar_id' => (int)in('avatar_id') ?: null];
    $new = (string)in('new_password');
    if ($new !== '') {
        if (!password_verify((string)in('current_password'), (string)$me['password_hash'])) {
            flash('err', 'Your current password is not correct.');
            back_to('profile');
        }
        if ($p = Auth::passwordProblem($new)) {
            flash('err', $p);
            back_to('profile');
        }
        $row['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        Activity::log('password.changed');
    }
    db()->update('users', $row, 'id = ?', [(int)$me['id']]);
    Cache::clear();
    flash('ok', 'Your profile has been saved.');
    back_to('profile');
}
ob_start(); ?>
<form method="post" autocomplete="off">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>About you</h2>
    <div class="grid2">
      <?= f_text('name', 'Name', $me['name'], '', ['required' => true, 'maxlength' => 120]) ?>
      <?= f_text('title', 'Job title', (string)$me['title'], '', ['maxlength' => 160]) ?>
    </div>
    <?= f_area('bio', 'Short biography', (string)$me['bio'], 'Shown on your author page.', 3, ['maxlength' => 1000]) ?>
    <?= f_media('avatar_id', 'Photo', (int)$me['avatar_id']) ?>
  </section>
  <section class="card">
    <h2>Change password</h2>
    <div class="grid2">
      <?= f_text('current_password', 'Current password', '', '', ['type' => 'password', 'autocomplete' => 'current-password']) ?>
      <?= f_text('new_password', 'New password', '', 'At least 10 characters, letters and numbers.', ['type' => 'password', 'autocomplete' => 'new-password']) ?>
    </div>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save</button></div>
</form>
<?php admin_render('Your profile', (string)ob_get_clean(), 'profile');
