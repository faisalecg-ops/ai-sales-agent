<?php
declare(strict_types=1);

/**
 * Users — super admin only.
 *
 * One login serves both platforms: the AI Sales Agent and the AI Social
 * Media Expert Agent. A super admin may open everything. Staff are given
 * platforms and, on each, exhibitions with a role on every one. The rows
 * written here (admin_user_access) are read by both platforms through
 * app/lib/Access.php.
 */

require __DIR__ . '/_admin.php';

$me = admin_require_role('admin');

if (!Migrations::tableExists('admin_user_access')) {
    admin_layout_start('Users');
    echo '<div class="alert alert-warn"><p>Run <a href="' . e(url('admin/upgrade.php')) . '">Database upgrade</a> '
       . 'first. It adds what this page needs; every existing account stays a super admin.</p></div>';
    admin_layout_end();
    exit;
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

/** Readable temporary password: no 0/O, 1/l/I. */
$tempPassword = static function (): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $chunk;
    }
    return implode('-', $groups);
};

$activeSupers = static fn(): int => (int)db()->value("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1");
$exhibitions  = db()->all("SELECT id, name, status FROM agents WHERE status <> 'deleted' ORDER BY name ASC");
$exhibitionIds = array_map('intval', array_column($exhibitions, 'id'));
$platformNames = ['sales' => 'AI Sales Agent', 'social' => 'AI Social Media Expert Agent'];
$roleLists     = ['sales' => Access::SALES_ROLES, 'social' => Access::SOCIAL_ROLES];

// ---------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? 'save');
    $id     = (int)($_POST['id'] ?? 0);
    $target = $id > 0 ? db()->one('SELECT * FROM admin_users WHERE id = ?', [$id]) : null;
    if ($id > 0 && !$target) {
        admin_flash('That user does not exist.', 'error');
        admin_redirect('admin/users.php');
    }
    $isMe = $target && (int)$target['id'] === (int)$me['id'];

    if ($action === 'temp_password' && $target) {
        $pw = $tempPassword();
        db()->update('admin_users', [
            'password_hash'        => password_hash($pw, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'reset_token_hash'     => null,
            'reset_expires_at'     => null,
        ], 'id = ?', [$id]);
        Audit::log('admin_temp_password', null, 'admin_user', $id, 'Temporary password given to ' . $target['username']);
        $_SESSION['users_temp_password'] = ['id' => $id, 'password' => $pw];
        admin_redirect('admin/users.php?edit=' . $id);
    }

    if ($action === 'active' && $target) {
        $makeActive = !empty($_POST['active']);
        if (!$makeActive && $isMe) {
            admin_flash('You cannot deactivate your own account.', 'error');
        } elseif (!$makeActive && (string)$target['role'] === 'admin' && (int)$target['active'] === 1 && $activeSupers() <= 1) {
            admin_flash('This is the only active super admin, so it cannot be deactivated.', 'error');
        } else {
            db()->update('admin_users', ['active' => $makeActive ? 1 : 0], 'id = ?', [$id]);
            Audit::log($makeActive ? 'admin_reactivated' : 'admin_deactivated', null, 'admin_user', $id,
                (string)$target['username']);
            admin_flash($makeActive
                ? $target['username'] . ' can sign in again.'
                : $target['username'] . ' is deactivated and is signed out of both platforms straight away.');
        }
        admin_redirect('admin/users.php?edit=' . $id);
    }

    // ---- save ----
    $errors   = [];
    $fullName = Validator::text($_POST['full_name'] ?? null, 160);
    $email    = Validator::email($_POST['email'] ?? null);
    $kind     = Validator::inList($_POST['kind'] ?? null, ['admin', 'staff'], 'staff');
    $username = $target ? (string)$target['username'] : Validator::slug($_POST['username'] ?? null, 32);

    if ($username === null) {
        $errors[] = 'Choose a username of lowercase letters, numbers or hyphens.';
    }
    if ($email === null) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($username !== null && !$target
        && (int)db()->value('SELECT COUNT(*) FROM admin_users WHERE username = ?', [$username]) > 0) {
        $errors[] = 'That username is already taken.';
    }
    if ($email !== null
        && (int)db()->value('SELECT COUNT(*) FROM admin_users WHERE email = ? AND id <> ?', [$email, $id]) > 0) {
        $errors[] = 'Another user already has that email address.';
    }
    if ($target && $kind === 'staff' && (string)$target['role'] === 'admin') {
        if ($isMe) {
            $errors[] = 'You cannot make your own account staff. Ask another super admin.';
        } elseif ((int)$target['active'] === 1 && $activeSupers() <= 1) {
            $errors[] = 'This is the only active super admin, so it must stay one.';
        }
    }

    // Which platforms and exhibitions, with which role. Anything not ticked,
    // not a real exhibition or not a real role is simply not saved.
    $wanted = [];
    if ($kind === 'staff') {
        foreach ($roleLists as $platform => $roles) {
            if (empty($_POST['platform'][$platform])) {
                continue;
            }
            foreach ((array)($_POST['access'][$platform] ?? []) as $agentId => $role) {
                $agentId = (int)$agentId;
                $role    = (string)$role;
                if (in_array($agentId, $exhibitionIds, true) && array_key_exists($role, $roles)) {
                    $wanted[] = [$platform, $agentId, $role];
                }
            }
        }
    }

    if ($errors) {
        foreach ($errors as $err) {
            admin_flash($err, 'error');
        }
        $_SESSION['users_form'] = $_POST;
        admin_redirect('admin/users.php?' . ($target ? 'edit=' . $id : 'new=1'));
    }

    $shownPassword = null;
    if ($target) {
        db()->update('admin_users', [
            'full_name' => $fullName,
            'email'     => $email,
            'role'      => $kind,
        ], 'id = ?', [$id]);
    } else {
        $shownPassword = $tempPassword();
        $id = db()->insert('admin_users', [
            'username'             => $username,
            'email'                => $email,
            'full_name'            => $fullName,
            'password_hash'        => password_hash($shownPassword, PASSWORD_DEFAULT),
            'role'                 => $kind,
            'active'               => 1,
            'must_change_password' => 1,
        ]);
        Audit::log('admin_created', null, 'admin_user', $id,
            'Created ' . $username . ' as ' . ($kind === 'admin' ? 'super admin' : 'staff'));
    }

    // Access rows: replaced as a whole, so what is saved is exactly what was
    // ticked. A super admin needs none.
    $before = db()->all('SELECT platform, agent_id, role FROM admin_user_access WHERE user_id = ? ORDER BY platform, agent_id', [$id]);
    db()->exec('DELETE FROM admin_user_access WHERE user_id = ?', [$id]);
    foreach ($wanted as [$platform, $agentId, $role]) {
        db()->insert('admin_user_access', [
            'user_id'  => $id,
            'platform' => $platform,
            'agent_id' => $agentId,
            'role'     => $role,
        ]);
    }
    $describe = static function (array $rows) use ($exhibitions): string {
        $names = array_column($exhibitions, 'name', 'id');
        $parts = [];
        foreach ($rows as $r) {
            $r = array_values((array)$r);
            $parts[] = $r[0] . ':' . ($names[(int)$r[1]] ?? ('#' . $r[1])) . '=' . $r[2];
        }
        return $parts ? implode(', ', $parts) : 'none';
    };
    $beforeText = $describe(array_map(static fn($r) => [$r['platform'], $r['agent_id'], $r['role']], $before));
    $afterText  = $describe($wanted);
    if ($target && ((string)$target['role'] !== $kind || $beforeText !== $afterText)) {
        Audit::log('admin_access_changed', null, 'admin_user', $id, (string)$target['username'],
            'access', ($target['role'] === 'admin' ? 'super admin' : 'staff') . ' — ' . $beforeText,
            ($kind === 'admin' ? 'super admin' : 'staff') . ' — ' . $afterText);
    } elseif (!$target) {
        Audit::log('admin_access_changed', null, 'admin_user', $id, (string)$username, 'access', '', $afterText);
    }
    Access::forget();

    if ($shownPassword !== null) {
        $_SESSION['users_temp_password'] = ['id' => $id, 'password' => $shownPassword, 'new' => true];
    } else {
        admin_flash('Saved. Changes apply on both platforms from the user\'s next click.');
    }
    admin_redirect('admin/users.php?edit=' . $id);
}

// ---------------------------------------------------------------------
// One user
// ---------------------------------------------------------------------
if ($editId > 0 || $isNew) {
    $u = $editId > 0 ? db()->one('SELECT * FROM admin_users WHERE id = ?', [$editId]) : null;
    if ($editId > 0 && !$u) {
        admin_flash('That user does not exist.', 'error');
        admin_redirect('admin/users.php');
    }

    $form = $_SESSION['users_form'] ?? null;
    unset($_SESSION['users_form']);

    $rows = [];
    if ($u) {
        foreach (db()->all('SELECT platform, agent_id, role FROM admin_user_access WHERE user_id = ?', [(int)$u['id']]) as $r) {
            $rows[(string)$r['platform']][(int)$r['agent_id']] = (string)$r['role'];
        }
    }
    if (is_array($form)) {
        $rows = [];
        foreach ($roleLists as $platform => $roles) {
            foreach ((array)($form['access'][$platform] ?? []) as $agentId => $role) {
                if ((string)$role !== '') {
                    $rows[$platform][(int)$agentId] = (string)$role;
                }
            }
        }
    }
    $val = static fn(string $k, string $fallback = ''): string => is_array($form) && isset($form[$k])
        ? (string)$form[$k] : (string)($u[$k] ?? $fallback);
    $kind = is_array($form) ? (string)($form['kind'] ?? 'staff') : (string)($u['role'] ?? 'staff');
    $platformOn = static function (string $platform) use ($form, $rows): bool {
        if (is_array($form)) {
            return !empty($form['platform'][$platform]);
        }
        return !empty($rows[$platform]);
    };

    admin_layout_start($u ? 'User: ' . $u['username'] : 'New user', ['Back to users' => url('admin/users.php')]);

    // A password is shown once, right after it is made, and never again.
    $temp = $_SESSION['users_temp_password'] ?? null;
    if (is_array($temp) && $u && (int)$temp['id'] === (int)$u['id']) {
        unset($_SESSION['users_temp_password']);
        echo '<div class="alert alert-warn"><p><strong>' . (!empty($temp['new']) ? 'User created.' : 'Temporary password made.')
           . '</strong> Give ' . e((string)$u['username']) . ' this temporary password: '
           . '<code style="font-size:15px">' . e((string)$temp['password']) . '</code><br>It is shown only now. '
           . 'They sign in at <code>' . e(url('admin/login.php')) . '</code> and choose their own password straight away.</p></div>';
    }

    if ($u && (int)$u['active'] !== 1) {
        echo '<div class="alert alert-error"><p>This account is deactivated and cannot sign in.</p></div>';
    }

    echo '<form method="post" class="settings-form">' . Security::csrfField()
       . '<input type="hidden" name="action" value="save">'
       . '<input type="hidden" name="id" value="' . (int)($u['id'] ?? 0) . '">';

    echo admin_card_open('u-who', 'Who', 'Their name and how they sign in. One login works on both platforms.')
       . '<div class="field-row">'
       . f_text('full_name', 'Full name', $val('full_name'), ['max' => 160])
       . f_text('email', 'Email', $val('email'), ['type' => 'email', 'required' => true])
       . '</div>';
    if ($u) {
        echo '<p class="hint" style="margin:0 0 14px">Username: <strong>' . e((string)$u['username']) . '</strong> (cannot be changed)'
           . ($u['last_login_at'] ? ' · last signed in ' . e(admin_time((string)$u['last_login_at'])) : ' · has not signed in yet')
           . '</p>';
    } else {
        echo f_text('username', 'Username', $val('username'), ['required' => true,
            'hint' => 'Lowercase letters, numbers or hyphens, for example ayesha-khan. A temporary password is made for you to give them.']);
    }
    echo f_select('kind', 'Kind of user', [
            'staff' => 'Staff — only the platforms and exhibitions ticked below',
            'admin' => 'Super admin — everything on both platforms, including users and settings',
        ], $kind)
       . admin_card_close();

    foreach ($platformNames as $platform => $label) {
        echo admin_card_open('u-' . $platform, $label, $platform === 'sales'
                ? 'Which exhibitions they may open in the AI Sales Agent, and what they may do there.'
                : 'Which exhibitions they may open in the AI Social Media Expert Agent. These roles are used by that platform.')
           . '<p class="hint" style="margin:0 0 10px">Ignored for a super admin, who may open everything.</p>'
           . f_check('platform[' . $platform . ']', 'Can use the ' . $label, $platformOn($platform));
        if (!$exhibitions) {
            echo '<p class="hint">No exhibitions yet.</p>';
        } else {
            echo '<div class="table-wrap"><table class="table"><thead><tr><th>Exhibition</th><th>Access</th></tr></thead><tbody>';
            foreach ($exhibitions as $ex) {
                $current = $rows[$platform][(int)$ex['id']] ?? '';
                echo '<tr><td>' . e((string)$ex['name'])
                   . ((string)$ex['status'] !== 'active' ? ' <span class="pill">' . e((string)$ex['status']) . '</span>' : '')
                   . '</td><td><select class="input" style="width:auto;min-width:240px" name="access[' . $platform . '][' . (int)$ex['id'] . ']" aria-label="'
                   . e($label . ' — ' . (string)$ex['name']) . '"><option value="">No access</option>';
                foreach ($roleLists[$platform] as $roleKey => $roleLabel) {
                    echo '<option value="' . e($roleKey) . '"' . ($current === $roleKey ? ' selected' : '') . '>'
                       . e($roleLabel) . '</option>';
                }
                echo '</select></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo admin_card_close();
    }

    echo '<div class="save-bar"><span class="save-note">Changes apply on both platforms from the user\'s next click.</span>'
       . '<a class="btn btn-quiet" href="' . e(url('admin/users.php')) . '">Cancel</a>'
       . '<button class="btn" type="submit">' . ($u ? 'Save user' : 'Create user') . '</button></div></form>';

    if ($u) {
        echo '<div class="card"><h2>Password and sign-in</h2>'
           . '<p class="lede">If they have forgotten their password, make a temporary one and give it to them. '
           . 'They must choose their own the next time they sign in.</p><div class="btn-row">'
           . '<form method="post" style="margin:0" data-confirm="Make a temporary password for ' . e((string)$u['username'])
           . '? Their current password stops working.">' . Security::csrfField()
           . '<input type="hidden" name="action" value="temp_password"><input type="hidden" name="id" value="' . (int)$u['id'] . '">'
           . '<button class="btn btn-small" type="submit">Give a temporary password</button></form>';
        if ((int)$u['id'] !== (int)$me['id']) {
            $on = (int)$u['active'] === 1;
            echo '<form method="post" style="margin:0"' . ($on ? ' data-confirm="Deactivate ' . e((string)$u['username'])
                   . '? They are signed out of both platforms straight away."' : '') . '>' . Security::csrfField()
               . '<input type="hidden" name="action" value="active"><input type="hidden" name="id" value="' . (int)$u['id'] . '">'
               . ($on ? '' : '<input type="hidden" name="active" value="1">')
               . '<button class="btn btn-small ' . ($on ? 'btn-danger' : 'btn-quiet') . '" type="submit">'
               . ($on ? 'Deactivate' : 'Reactivate') . '</button></form>';
        }
        echo '</div></div>';
    }

    admin_layout_end();
    exit;
}

// ---------------------------------------------------------------------
// Everyone
// ---------------------------------------------------------------------
$users = db()->all('SELECT * FROM admin_users ORDER BY active DESC, role ASC, username ASC');
$counts = [];
foreach (db()->all('SELECT user_id, platform, COUNT(*) AS n FROM admin_user_access GROUP BY user_id, platform') as $r) {
    $counts[(int)$r['user_id']][(string)$r['platform']] = (int)$r['n'];
}

admin_layout_start('Users', ['New user' => url('admin/users.php?new=1')]);

echo '<p class="lede">One login works on both platforms: the AI Sales Agent and the AI Social Media Expert Agent. '
   . 'A super admin may open everything. Staff see only the exhibitions they are given, on each platform.</p>';

echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
   . '<th style="padding-left:16px">User</th><th>Kind</th><th>AI Sales Agent</th><th>AI Social Media Expert Agent</th>'
   . '<th>Last signed in</th><th></th></tr></thead><tbody>';
foreach ($users as $u) {
    $super = (string)$u['role'] === 'admin';
    $show  = static fn(string $p): string => $super ? 'All exhibitions'
        : (isset($counts[(int)$u['id']][$p]) ? $counts[(int)$u['id']][$p] . ' exhibition' . ($counts[(int)$u['id']][$p] === 1 ? '' : 's') : '—');
    echo '<tr><td style="padding-left:16px"><strong>' . e((string)$u['username']) . '</strong>'
       . ($u['full_name'] ? '<br><span class="small muted">' . e((string)$u['full_name']) . '</span>' : '') . '</td>'
       . '<td>' . ($super ? 'Super admin' : 'Staff')
       . ((int)$u['active'] !== 1 ? ' <span class="pill">Deactivated</span>' : '')
       . ((int)$u['must_change_password'] === 1 && (int)$u['active'] === 1 ? ' <span class="pill pill-warn">Password to choose</span>' : '')
       . '</td><td class="small">' . e($show('sales')) . '</td><td class="small">' . e($show('social')) . '</td>'
       . '<td class="small muted">' . e($u['last_login_at'] ? admin_time((string)$u['last_login_at']) : 'Never') . '</td>'
       . '<td><a href="' . e(url('admin/users.php?edit=' . (int)$u['id'])) . '">Open</a></td></tr>';
}
echo '</tbody></table></div></div>';

admin_layout_end();
