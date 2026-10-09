<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

$user = admin_user();
if ($user !== null) {
    Audit::log('admin_signed_out', null, 'admin_user', (int)$user['id']);
}

Security::destroySession();
admin_redirect('admin/login.php');
