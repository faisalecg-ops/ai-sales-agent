<?php
/** The people who can sign in to this blog's admin, and their roles. */
require_perm('users');

if (is_post()) {
    post_guard();
    $uid = (int)in('id');
    $name = in_str('name', 120);
    $email = strtolower(in_str('email', 190));
    $role = array_key_exists((string)in('role'), Auth::roles()) ? (string)in('role') : 'author';
    $status = in('status') === 'disabled' ? 'disabled' : 'active';
    $pass = (string)in('password');
    $back = static fn() => back_to('users', ['edit' => $uid ?: 'new']);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('err', 'Enter a name and a valid email.');
        $back();
    }
    if (db()->value('SELECT id FROM {p}users WHERE email = ? AND id <> ?', [$email, $uid])) {
        flash('err', 'Another user already has this email.');
        $back();
    }
    if (!$uid && $pass === '') {
        flash('err', 'Set a password for the new user.');
        $back();
    }
    if ($pass !== '' && ($p = Auth::passwordProblem($pass))) {
        flash('err', $p);
        $back();
    }
    // Never leave the blog without an active admin.
    if ($uid) {
        $old = db()->one('SELECT * FROM {p}users WHERE id = ?', [$uid]);
        $admins = (int)db()->value("SELECT COUNT(*) FROM {p}users WHERE role = 'admin' AND status = 'active'");
        if ($old && $old['role'] === 'admin' && $old['status'] === 'active' && ($role !== 'admin' || $status !== 'active') && $admins <= 1) {
            flash('err', 'This is the only active admin. Make someone else an admin first.');
            $back();
        }
        if ($uid === Auth::id() && $status === 'disabled') {
            flash('err', 'You cannot switch off your own account.');
            $back();
        }
    }
    $slug = slugify($name) ?: 'user';
    $base = $slug;
    $n = 2;
    while (db()->value('SELECT id FROM {p}users WHERE slug = ? AND id <> ?', [$slug, $uid])) {
        $slug = $base . '-' . $n++;
    }
    $row = ['name' => $name, 'email' => $email, 'role' => $role, 'status' => $status, 'slug' => $slug,
        'title' => in_str('title', 160), 'bio' => in_str('bio', 1000), 'avatar_id' => (int)in('avatar_id') ?: null,
        'show_as_author' => (int)(bool)in('show_as_author')];
    if ($pass !== '') {
        $row['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
    }
    if ($uid) {
        db()->update('users', $row, 'id = ?', [$uid]);
        Activity::log('user.updated', $email, ['role' => $role, 'status' => $status]);
    } else {
        db()->insert('users', $row);
        Activity::log('user.created', $email, ['role' => $role]);
    }
    Cache::clear();
    flash('ok', 'User saved.' . (!$uid ? ' Send them the admin address: ' . url('admin/') : ''));
    back_to('users');
}

$users = db()->all("SELECT u.*, (SELECT COUNT(*) FROM {p}articles a WHERE a.author_id = u.id AND a.status = 'published') n FROM {p}users u ORDER BY u.status, u.role, u.name");
$editKey = (string)($_GET['edit'] ?? '');
$edit = $editKey === 'new' ? ['id' => 0, 'name' => '', 'email' => '', 'role' => 'author', 'status' => 'active', 'title' => '', 'bio' => '', 'avatar_id' => 0, 'show_as_author' => 1]
    : ($editKey !== '' ? db()->one('SELECT * FROM {p}users WHERE id = ?', [(int)$editKey]) : null);

ob_start(); ?>
<?php if ($edit): ?>
<section class="card">
  <h2><?= $edit['id'] ? 'Edit ' . e($edit['name']) : 'New user' ?></h2>
  <form method="post" autocomplete="off">
    <?= Auth::csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="grid2">
      <?= f_text('name', 'Name', $edit['name'], 'Shown as the author of their articles.', ['required' => true, 'maxlength' => 120]) ?>
      <?= f_text('email', 'Email (used to sign in)', $edit['email'], '', ['type' => 'email', 'required' => true]) ?>
      <?= f_select('role', 'Role', Auth::roles(), (string)$edit['role']) ?>
      <?= f_select('status', 'Account', ['active' => 'Active', 'disabled' => 'Switched off (cannot sign in)'], (string)$edit['status']) ?>
      <?= f_text('password', $edit['id'] ? 'New password (leave empty to keep)' : 'Password', '', 'At least 10 characters, letters and numbers.', ['type' => 'password', 'autocomplete' => 'new-password']) ?>
      <?= f_text('title', 'Job title (optional)', (string)$edit['title'], 'Shown on their author page, e.g. "Editor, Build Asia".', ['maxlength' => 160]) ?>
    </div>
    <?= f_area('bio', 'Short biography (optional)', (string)$edit['bio'], 'Helps readers and Google trust the articles.', 3, ['maxlength' => 1000]) ?>
    <?= f_media('avatar_id', 'Photo (optional)', (int)$edit['avatar_id']) ?>
    <?= f_check('show_as_author', 'Show this person\'s name on articles and give them an author page', (bool)$edit['show_as_author'], 'Untick for staff accounts; articles then show the exhibition as the author.') ?>
    <div class="row-btns"><button class="btn btn--primary">Save user</button> <a class="btn btn--ghost" href="<?= e(aurl('users')) ?>">Cancel</a></div>
  </form>
</section>
<?php endif; ?>
<section class="card card--flush">
  <table class="table table--list"><thead><tr><th>Name</th><th>Role</th><th class="hide-s">Last sign-in</th><th class="hide-s right">Articles</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): ?>
    <tr<?= $u['status'] === 'disabled' ? ' class="is-off"' : '' ?>><td><strong><?= e($u['name']) ?></strong><br><span class="small muted"><?= e($u['email']) ?></span></td>
      <td><?= e(ucfirst((string)$u['role'])) ?><?= $u['status'] === 'disabled' ? ' <span class="tag">Off</span>' : '' ?></td>
      <td class="hide-s small"><?= $u['last_login_at'] ? e(local_date($u['last_login_at'], 'j M Y, H:i')) : 'Never' ?></td>
      <td class="hide-s right"><?= (int)$u['n'] ?></td>
      <td class="right"><a class="btn btn--small btn--ghost" href="<?= e(aurl('users', ['edit' => $u['id']])) ?>">Edit</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<section class="card"><h2>What each role can do</h2><ul class="plain"><?php foreach (Auth::roles() as $r => $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul></section>
<?php admin_render('Users', (string)ob_get_clean(), 'users', ['actions' => '<a class="btn btn--primary" href="' . e(aurl('users', ['edit' => 'new'])) . '">+ New user</a>']);
