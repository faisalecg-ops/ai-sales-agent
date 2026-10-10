<?php
declare(strict_types=1);

/**
 * The blog's "Article published" notice (docs/blog-api.md). Trusted only when
 * its signature matches the secret saved for that exhibition's blog.
 */
define('AISA_JSON_ENDPOINT', true);
require __DIR__ . '/lib/boot.php';

header('Content-Type: application/json; charset=utf-8');
$out = static function (int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(405, ['ok' => false]);
}
$aid = (int)($_GET['agent'] ?? 0);
$row = $aid > 0 ? db()->one('SELECT * FROM sm_events WHERE agent_id = ?', [$aid]) : null;
$secret = $row && $row['blog_hook_secret'] ? Crypto::decrypt((string)$row['blog_hook_secret']) : null;
$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_BLOG_SIGNATURE'] ?? '');
if (!$secret || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
    $out(401, ['ok' => false, 'error' => 'bad_signature']);
}
$data = json_decode($raw, true);
$sent = is_array($data) ? strtotime((string)($data['sent_at'] ?? '')) : false;
if ($sent !== false && $sent < time() - 4 * 86400) {
    $out(200, ['ok' => true, 'note' => 'too old']);   // the blog stops retrying after about two days
}
$remote = is_array($data) ? (array)($data['article'] ?? []) : [];
$ref = (string)($remote['external_ref'] ?? '');
if (($data['event'] ?? '') !== 'article.published' || !preg_match('/^sm-' . $aid . '-(\d+)$/', $ref, $m)) {
    $out(200, ['ok' => true, 'note' => 'not ours']);   // published by a person in the blog: nothing to do
}
$agent = db()->one('SELECT * FROM agents WHERE id = ?', [$aid]);
$article = db()->one('SELECT id, title FROM sm_articles WHERE id = ? AND agent_id = ?', [(int)$m[1], $aid]);
if (!$agent || !$article) {
    $out(200, ['ok' => true, 'note' => 'unknown article']);
}
$remote['status'] = 'published';
SmActivity::log($aid, 'blog_notice', 'Published: ' . $article['title'], ['id' => 0, 'full_name' => 'Blog', 'username' => 'blog']);
// Answer the blog at once; writing the platform versions takes a little while.
if (function_exists('fastcgi_finish_request')) {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    fastcgi_finish_request();
    SmFlow::applyRemote($agent, (int)$article['id'], $remote);
    exit;
}
SmFlow::applyRemote($agent, (int)$article['id'], $remote);
$out(200, ['ok' => true]);
