<?php
/**
 * Test web server router (not deployed): serves public_html like Apache, with
 * the stand-ins installed. Run: php -S 127.0.0.1:PORT tests/router.php
 */
declare(strict_types=1);
define('SM_TESTING', true);
$docroot = dirname(__DIR__, 2);
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/social/(lib|tests)(/|$)#', $path) || preg_match('#/\.#', $path) || $path === '/social/_social.php') {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = $docroot . $path;
if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
    $path = rtrim($path, '/') . '/index.php';
}
if (!is_file($file)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
if (!str_ends_with($file, '.php')) {
    return false;
}
if (str_starts_with($path, '/social/uploads/')) {
    http_response_code(403);   // uploads/.htaccess: nothing there runs
    return true;
}
require_once $docroot . '/social/lib/boot.php';
require_once __DIR__ . '/fixtures.php';
sm_install_fakes();
$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['SCRIPT_FILENAME'] = $file;
chdir(dirname($file));
require $file;
return true;
