<?php
/** Event Blog admin — every admin page is opened through here (?p=page). */
declare(strict_types=1);

require __DIR__ . '/_admin.php';

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard')) ?: 'dashboard';

if (!Auth::user()) {
    require __DIR__ . '/pages/login.php';
    exit;
}

$file = __DIR__ . '/pages/' . $page . '.php';
if ($page === 'login' || !is_file($file)) {
    $page = 'dashboard';
    $file = __DIR__ . '/pages/dashboard.php';
}
require $file;
