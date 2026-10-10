<?php
/**
 * Test helper (not deployed): drives a real Event Blog in its own test
 * database (smblog, prefix bt_) for the /social tests.
 *   php blog-cli.php setup <notice-url>   → JSON with keys
 *   php blog-cli.php publish <article-id>  → publishes an article held for approval
 *   php blog-cli.php article <article-id>  → JSON of the article row
 */
declare(strict_types=1);
putenv('BLOG_TEST_DB=smblog');
require dirname(__DIR__, 3) . '/blog/tests/harness.php';

$cmd = $argv[1] ?? '';
if ($cmd === 'setup') {
    reset_blog();
    foreach (['Market Insights', 'Building Materials', 'Exhibition News', 'Infrastructure & Projects'] as $c) {
        make_category($c);
    }
    db()->insert('links', ['label' => 'Register to visit', 'url' => 'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web', 'kind' => 'register', 'active' => 1]);
    db()->insert('links', ['label' => 'Book a stand', 'url' => 'https://buildasia.net/contact/?enquiry=stand', 'kind' => 'stand', 'active' => 1]);
    Settings::setMany(['webhook_url' => (string)($argv[2] ?? ''), 'webhook_secret' => 'testsecret']);
    $pub = ApiKey::create('Social agent', 'publish');
    $dra = ApiKey::create('Social agent drafts', 'drafts');
    echo json_encode(['publish' => $pub['key'], 'drafts' => $dra['key'], 'secret' => 'testsecret', 'base' => TEST_BASE]);
    exit;
}
if ($cmd === 'publish') {
    Article::transition((int)$argv[2], 'published');
    echo json_encode(['ok' => true]);
    exit;
}
if ($cmd === 'article') {
    echo json_encode(db()->one('SELECT id, title, status, image_id, category_id, sources, external_ref FROM {p}articles WHERE id = ?', [(int)$argv[2]]));
    exit;
}
fwrite(STDERR, "unknown command\n");
exit(1);
