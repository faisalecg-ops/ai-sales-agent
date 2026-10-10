<?php
/**
 * Router for PHP's built-in server during tests: behaves like the blog's
 * .htaccess with the blog installed at /blog, and points it at the test
 * database. Not part of the deployed package.
 */
$GLOBALS['BLOG_CONFIG_OVERRIDE'] = [
    'db' => ['host' => 'localhost', 'name' => getenv('BLOG_TEST_DB') ?: 'blogtest', 'user' => 'blog', 'pass' => 'blogpass', 'port' => 3306],
    'prefix' => 'bt_', 'base_url' => 'http://127.0.0.1:8097/blog', 'app_key' => str_repeat('ab', 32), 'debug' => false, 'api_allow_http' => true,
];
define('BLOG_TEST_SERVER', true);
$root = dirname(__DIR__);
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!str_starts_with($path, '/blog')) {
    http_response_code(404);
    return true;
}
$rel = substr($path, 5);
if (preg_match('#^/(core|config|storage|lang|themes|tests)(/|$)#', $rel) || preg_match('#/\.#', $rel)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
$file = $root . $rel;
if ($rel !== '' && $rel !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;   // let the server send static files
}
if (str_starts_with($rel, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/blog/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/index.php';
    require $root . '/api/index.php';
    return true;
}
if (preg_match('#^/(admin|install)(/.*)?$#', $rel, $m)) {
    $f = $root . '/' . $m[1] . (isset($m[2]) && str_ends_with($m[2], '.php') ? $m[2] : '/index.php');
    if (!is_file($f)) {
        $f = $root . '/' . $m[1] . '/index.php';
    }
    $_SERVER['SCRIPT_NAME'] = '/blog/' . $m[1] . '/' . basename($f);
    $_SERVER['SCRIPT_FILENAME'] = $f;
    require $f;
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
return true;
