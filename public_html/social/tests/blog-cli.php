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
if ($cmd === 'own') {
    // An article written and published in the blog admin by a person (optionally dated in the past).
    $cat = (int)db()->value("SELECT id FROM {p}categories WHERE slug = 'building-materials'");
    $a = publish_article((string)$argv[2], $cat, ['body' => '<h2>On site</h2><p>' . str_repeat('New tile plants opened in Lahore this year. ', 10) . '</p>', 'image_id' => test_image('own' . mt_rand(1, 99999))]);
    if (!empty($argv[3])) {
        db()->update('articles', ['published_at' => gmdate('Y-m-d H:i:s', time() - (int)$argv[3])], 'id = ?', [(int)$a['id']]);
    }
    echo json_encode(['id' => (int)$a['id'], 'url' => Article::urlFor(Article::find((int)$a['id']) + ['cat' => 'building-materials'])]);
    exit;
}
if ($cmd === 'same-second') {
    // Give article B exactly the publishing time of article A.
    $at = (string)db()->value('SELECT published_at FROM {p}articles WHERE id = ?', [(int)$argv[2]]);
    db()->update('articles', ['published_at' => $at], 'id = ?', [(int)$argv[3]]);
    echo json_encode(['at' => $at]);
    exit;
}
if ($cmd === 'pending') {
    $cat = (int)db()->value("SELECT id FROM {p}categories WHERE slug = 'building-materials'");
    $u = make_user('author', 'Sana Author');
    $id = Article::save(null, ['title' => (string)$argv[2], 'body' => '<p>' . str_repeat('Draft by the team. ', 20) . '</p>', 'category_id' => $cat, 'author_id' => (int)$u['id']], []);
    Article::transition($id, 'pending');
    echo json_encode(['id' => $id]);
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
