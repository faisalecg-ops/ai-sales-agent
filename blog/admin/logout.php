<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

if (Auth::user()) {
    Activity::log('logout');
}
Auth::logout();
redirect(url('admin/'));
