<?php
/**
 * Event Blog — public front controller. Every public address of the blog
 * comes here (see .htaccess) and is answered from the routes below.
 */
declare(strict_types=1);

require __DIR__ . '/core/bootstrap.php';

if (!blog_installed()) {
    header('Location: install/');
    exit;
}

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode($path);
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = trim($path, '/');
$trailing = str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

Router::dispatch($path, $trailing);
