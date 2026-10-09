<?php
/**
 * Event Blog API (v1) — used by the Social Media Agent.
 * Documentation: docs/blog-api.md. JSON in, JSON out.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

if (!blog_installed()) {
    json_out(['ok' => false, 'error' => 'not_installed'], 503);
}
Api::handle();
