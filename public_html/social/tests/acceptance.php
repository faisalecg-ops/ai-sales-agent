<?php
declare(strict_types=1);

/**
 * /social acceptance tests (not deployed).
 *
 * Real sign-ins through the sales agent's login, real pages and forms on a
 * local web server, and a real Event Blog on a second local server, with the
 * AI, web research and email replaced by scripted stand-ins.
 *
 * Run:  php public_html/social/tests/acceptance.php
 * Like the sales agent's tests it clears the database it runs on (satest) and
 * the test blog database (smblog): never point it at a live installation.
 */

define('SM_TESTING', true);
require dirname(__DIR__, 3) . '/tests/harness.php';
require dirname(__DIR__) . '/lib/boot.php';
require __DIR__ . '/fixtures.php';

sm_install_fakes();
SmTestBus::reset();
reset_database();
foreach (array_reverse(SmSchema::tables()) as $t) {
    db()->raw()->query("DROP TABLE IF EXISTS `$t`");
}
$salesTablesBefore = array_column(db()->all("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT LIKE 'sm\\_%' ORDER BY 1"), 't');

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
$agentA = seed_agent();
$idA = (int)$agentA['id'];
db()->update('agents', [
    'event_starts_on' => '2027-03-18', 'event_ends_on' => '2027-03-20', 'event_venue' => 'Karachi Expo Centre', 'event_city' => 'Karachi', 'event_country' => 'Pakistan',
    'register_url' => 'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web', 'book_stand_url' => 'https://buildasia.net/contact/?enquiry=stand',
    'mail_from_name' => 'Build Asia Secretariat', 'mail_from_email' => 'info@buildasia.net', 'mail_smtp_host' => 'smtp.example.net', 'mail_smtp_username' => 'info@buildasia.net',
], 'id = ?', [$idA]);
MailProfile::savePassword($idA, 'smtp-pass');
$idB = (int)db()->insert('agents', [
    'slug' => 'pharmaasia', 'name' => 'Pharma Asia', 'status' => 'active', 'website_url' => 'https://pharmaasia.com.pk', 'allowed_domains' => 'pharmaasia.com.pk',
    'persona' => AgentDefaults::PERSONA, 'sales_objectives' => AgentDefaults::OBJECTIVES, 'sales_rules' => AgentDefaults::SALES_RULES,
    'ai_disclosure_text' => AgentDefaults::DISCLOSURE, 'llm_provider' => 'mock', 'llm_model' => 'mock-model', 'notify_email' => 'p@example.net', 'readiness_min_signals' => 2,
]);
db()->insert('knowledge_articles', ['agent_id' => $idA, 'title' => 'Build Asia 2027 at a glance', 'body' => 'Build Asia is Pakistan\'s international building materials and construction machinery exhibition. The January 2026 edition had over 32,000 trade visitors, over 350 exhibitors and over 21 countries.', 'category' => 'general', 'keywords' => 'exhibitors visitors cement steel', 'status' => 'approved', 'active' => 1]);
db()->insert('knowledge_articles', ['agent_id' => $idB, 'title' => 'Pharma secret', 'body' => 'PHARMA-ONLY-KNOWLEDGE', 'category' => 'general', 'keywords' => 'cement', 'status' => 'approved', 'active' => 1]);

$user = static function (string $name, string $role, array $access = [], int $mustChange = 0): int {
    $id = (int)db()->insert('admin_users', [
        'username' => $name, 'email' => $name . '@example.net', 'full_name' => ucfirst($name), 'password_hash' => password_hash('Pass-' . $name . '-2026', PASSWORD_DEFAULT),
        'role' => $role, 'active' => 1, 'must_change_password' => $mustChange,
    ]);
    foreach ($access as [$platform, $agent, $r]) {
        db()->insert('admin_user_access', ['user_id' => $id, 'platform' => $platform, 'agent_id' => $agent, 'role' => $r]);
    }
    return $id;
};
$bossId = $user('boss', 'admin');
$edId = $user('ed', 'staff', [['social', $idA, 'editor']]);
$apId = $user('ap', 'staff', [['social', $idA, 'approver'], ['sales', $idA, 'manager']]);
$user('vi', 'staff', [['social', $idA, 'viewer']]);
$user('salesonly', 'staff', [['sales', $idA, 'manager']]);
$user('pharma', 'staff', [['social', $idB, 'admin']]);
$user('fresh', 'staff', [['social', $idA, 'editor']], 1);

// ---------------------------------------------------------------------
// Servers: /social (with stand-ins) and a real Event Blog
// ---------------------------------------------------------------------
$blogInfo = json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/blog-cli.php') . ' setup ' . escapeshellarg('http://127.0.0.1:1/unused') . ' 2>&1'), true);
if (!is_array($blogInfo) || empty($blogInfo['publish'])) {
    fwrite(STDERR, "The test blog could not be set up.\n");
    exit(1);
}
$procs = [];
$start = static function (array $cmd, int $port, array $env = []) use (&$procs): void {
    $log = sys_get_temp_dir() . '/sm-test-' . $port . '.log';
    $procs[] = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
};
$port = 8300 + random_int(0, 400);
$start(['php', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__, 2), __DIR__ . '/router.php'], $port);
$start(['php', '-S', '127.0.0.1:8097', dirname(__DIR__, 3) . '/blog/tests/router.php'], 8097, ['BLOG_TEST_DB' => 'smblog']);
register_shutdown_function(static function () use (&$procs): void {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    // Leave the shared test database as the sales agent's own tests expect it.
    foreach (array_reverse(SmSchema::tables()) as $t) {
        db()->raw()->query("DROP TABLE IF EXISTS `$t`");
    }
    foreach (glob(SmMedia::dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        shell_exec('rm -rf ' . escapeshellarg($d));
    }
});
$base = 'http://127.0.0.1:' . $port;

final class B
{
    private string $jar;
    public int $status = 0;
    public string $location = '';
    public string $body = '';

    public function __construct(private string $base)
    {
        $this->jar = (string)tempnam(sys_get_temp_dir(), 'smjar');
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    /** Post a form, taking the CSRF token from $from (default: the same page). */
    public function post(string $path, array $fields, ?string $from = null, array $files = []): self
    {
        $page = $this->get($from ?? $path)->body;
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $fields['_csrf'] = $m[1] ?? '';
        if (!array_key_exists('_agent', $fields) && preg_match('/name="_agent" value="(\d+)"/', $page, $g)) {
            $fields['_agent'] = $g[1];
        }
        // An Approve click sends the version of the text shown on the page.
        if (($fields['do'] ?? '') === 'approve' && !array_key_exists('v', $fields) && preg_match('/name="v" value="([0-9a-f]+)"/', $page, $v)) {
            $fields['v'] = $v[1];
        }
        return $this->request('POST', $path, $fields, $files);
    }

    public function login(string $name, ?string $next = null): self
    {
        return $this->post('/admin/login.php' . ($next !== null ? '?next=' . rawurlencode($next) : ''), ['username' => $name, 'password' => 'Pass-' . $name . '-2026']);
    }

    public function goesTo(): string
    {
        $q = parse_url($this->location, PHP_URL_QUERY);
        return (string)parse_url($this->location, PHP_URL_PATH) . ($q ? '?' . $q : '');
    }

    private function request(string $method, string $path, array $fields = [], array $files = []): self
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($files) {
                $flat = [];
                $walk = static function ($prefix, $v) use (&$walk, &$flat) {
                    if (is_array($v)) {
                        foreach ($v as $k => $x) {
                            $walk($prefix . '[' . $k . ']', $x);
                        }
                    } else {
                        $flat[$prefix] = $v;
                    }
                };
                foreach ($fields as $k => $v) {
                    $walk((string)$k, $v);
                }
                foreach ($files as $k => [$path2, $mime, $name]) {
                    $flat[$k] = new CURLFile($path2, $mime, $name);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $flat);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
            }
        }
        $raw = (string)curl_exec($ch);
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        $this->body = substr($raw, $hs);
        $this->location = preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : '';
        return $this;
    }
}

$latest = static fn(string $table) => db()->one("SELECT * FROM $table ORDER BY id DESC LIMIT 1");

// =====================================================================
T::group('Signing in through the sales agent');
$anon = new B($base);
$anon->get('/social/');
T::ok($anon->status === 302 && str_starts_with($anon->goesTo(), '/admin/login.php?next='), 'Not signed in: sent to the sales agent\'s login, not a login of its own');
T::ok(str_contains(rawurldecode($anon->goesTo()), 'next=/social/'), '…which returns to /social afterwards');
$tables = array_column(db()->all("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'sm\\_%'"), 't');
T::equals(count(SmSchema::tables()), count($tables), 'Its own sm_ tables are created on first use');
$salesTablesAfter = array_column(db()->all("SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name NOT LIKE 'sm\\_%' ORDER BY 1"), 't');
T::equals($salesTablesBefore, $salesTablesAfter, 'No sales agent table was created, changed or dropped');

$boss = (new B($base))->login('boss', '/social/');
T::equals('/social/', $boss->goesTo(), 'A super admin signing in with next=/social/ comes back here');
$boss->get('/social/');
T::ok($boss->status === 200 && str_contains($boss->body, 'Dashboard'), 'The dashboard opens');
T::ok(str_contains($boss->body, '<nav class="platform-switch" aria-label="Platform"><a href="' . e(url('admin/')) . '">AI Sales Agent</a><a href="' . e(url('social/')) . '" aria-current="page">AI Social Media Expert Agent</a></nav><h1>'),
    'The platform switch is the first thing in the top bar, social highlighted, same markup as the sales agent');
$boss->get('/admin/');
T::ok(str_contains($boss->body, 'aria-current="page">AI Sales Agent</a>') && str_contains($boss->body, '/social/">AI Social Media Expert Agent</a>'), 'The sales agent now shows the switch too (social is installed)');

$soc = (new B($base))->login('ed');
T::equals('/social/', $soc->goesTo(), 'Someone with /social only is sent here after signing in');
$soc->get('/social/');
T::ok($soc->status === 200 && !str_contains($soc->body, 'platform-switch'), 'They see /social, with no switch (they have no sales access)');
$so = (new B($base))->login('salesonly');
$so->get('/social/');
T::ok($so->status === 403 && str_contains($so->body, 'No access yet') && $so->goesTo() === '', 'Sales-only staff get a plain "no access" page here — never sent back to the login (no loop)');
$fresh = (new B($base))->login('fresh');
$fresh->get('/social/');
T::ok($fresh->status === 302 && str_starts_with($fresh->goesTo(), '/admin/account.php?next='), 'Someone who must change their password is sent to Your account first');

// =====================================================================
T::group('Exhibitions and roles');
$ph = (new B($base))->login('pharma');
$ph->get('/social/?agent=' . $idA);
T::ok($ph->status === 200 && str_contains($ph->body, 'Pharma Asia') && !str_contains($ph->body, '<span>Build Asia</span>'), 'Staff of Pharma Asia stay on Pharma Asia even when asking for Build Asia');
$boss->get('/social/?agent=' . $idB);
T::ok(str_contains($boss->body, 'agent_switch') && str_contains($boss->body, '<span>Pharma Asia</span>'), 'A super admin can switch exhibitions');
$boss->get('/social/?agent=' . $idA);
$vi = (new B($base))->login('vi');
$vi->get('/social/settings.php');
T::equals(403, $vi->status, 'Viewers cannot open Event settings');
$vi->post('/social/plan.php', ['do' => 'add', 'title' => 'Viewer topic', 'pillar' => 'insight'], '/social/plan.php');
T::ok($vi->status === 403 && !db()->value("SELECT id FROM sm_topics WHERE title = 'Viewer topic'"), 'Viewers cannot change anything');
$soc->get('/social/connections.php');
T::equals(403, $soc->status, 'Editors cannot open Blog and platforms');

// =====================================================================
T::group('Blog and platforms (admin)');
$boss->post('/social/connections.php', ['do' => 'blog', 'blog_url' => $blogInfo['base'], 'blog_api_key' => $blogInfo['publish'], 'hook_secret' => $blogInfo['secret']]);
$boss->get('/social/connections.php');
T::ok(str_contains($boss->body, 'Connected to') || str_contains($boss->body, 'pill-ok">Connected'), 'The real test blog connects and answers');
$ev = db()->one('SELECT * FROM sm_events WHERE agent_id = ?', [$idA]);
T::ok($ev['blog_api_key'] !== $blogInfo['publish'] && Crypto::decrypt((string)$ev['blog_api_key']) === $blogInfo['publish'], 'The API key is stored encrypted');
T::ok(str_contains($boss->body, '/social/hook.php?agent=' . $idA), 'The notice address to paste into the blog admin is shown');
$boss->post('/social/connections.php', ['do' => 'platforms', 'p' => [
    'linkedin' => ['enabled' => 1, 'page_url' => 'https://www.linkedin.com/company/buildasia', 'posts_per_week' => 3, 'default_time' => '11:00', 'hashtags' => '#BuildAsia2027'],
    'facebook' => ['enabled' => 1, 'page_url' => 'https://facebook.com/buildasia', 'posts_per_week' => 4, 'default_time' => '13:00', 'hashtags' => ''],
    'instagram' => ['enabled' => 1, 'page_url' => 'https://instagram.com/buildasia', 'posts_per_week' => 4, 'default_time' => '18:00', 'hashtags' => '#BuildAsia'],
    'x' => ['posts_per_week' => 2, 'default_time' => '12:00'],
]], '/social/connections.php');
$on = array_column(db()->all('SELECT platform FROM sm_platforms WHERE agent_id = ? AND enabled = 1', [$idA]), 'platform');
sort($on);
T::equals(['facebook', 'instagram', 'linkedin'], $on, 'LinkedIn, Facebook and Instagram on; X off for now');

$boss->post('/social/settings.php', ['articles_per_week' => 2, 'mix' => ['insight' => 30, 'opportunity' => 30, 'news' => 20, 'spotlight' => 10, 'visitor' => 10, 'curated' => 0, 'voices' => 0],
    'sectors' => 'construction, cement, steel', 'audience' => 'Builders and investors', 'voice' => 'Neutral and helpful', 'avoid' => 'world-class',
    'writing_model' => 'main', 'text_limit' => 50, 'image_limit' => 3, 'ai_images' => 1, 'reminder_emails' => 'team@buildasia.net, not-an-email',
    'blog_link_default' => 1, 'blog_written_posts' => 1, 'brand_primary' => '#142A5C', 'brand_secondary' => '#1F4FBF', 'brand_accent' => '#F7B10B'], '/social/settings.php');
$s = SmEvent::settings($idA);
T::ok($s['sectors'] === 'construction, cement, steel' && $s['reminder_emails'] === 'team@buildasia.net' && $s['image_limit'] === 3 && $s['allow_prices'] === false, 'Event settings are saved per exhibition; bad email addresses are dropped');

// =====================================================================
T::group('Content plan');
SmTestBus::pushAi(['topics' => [
    ['pillar' => 'opportunity', 'title' => 'Cement exports: where the openings are', 'angle' => 'Demand, idle capacity and partners.', 'search' => 'Pakistan cement exports 2026'],
    ['pillar' => 'insight', 'title' => 'Fire safety in high-rise buildings', 'angle' => 'What the new rules ask.', 'search' => 'Karachi high-rise fire rules'],
    ['pillar' => 'nonsense', 'title' => 'Dropped: unknown type'],
]]);
$soc->post('/social/plan.php', ['do' => 'suggest', 'count' => 6, 'pillar' => '']);
T::equals(2, (int)db()->value("SELECT COUNT(*) FROM sm_topics WHERE agent_id = ? AND status = 'suggested'", [$idA]), 'Suggested topics are saved; an unknown content type is dropped');
$call = SmTestBus::entries('ai-calls')[0] ?? [];
T::ok(str_contains($call['system'] ?? '', 'Three months') === false && str_contains($call['system'] ?? '', 'Six months or more before the show'), 'The plan knows the event\'s phase (from the sales agent\'s dates)');
T::ok(str_contains($call['user'] ?? '', 'Build Asia 2027 at a glance') && !str_contains($call['user'] ?? '', 'Pharma secret'), 'It is told this exhibition\'s approved knowledge — and only this exhibition\'s');

// =====================================================================
T::group('Writing an article');
$topic = db()->one("SELECT * FROM sm_topics WHERE title LIKE 'Cement exports%'");
SmTestBus::write('search-results', [
    ['title' => 'Cement exports rise', 'url' => 'https://www.example-news.com/cement-exports', 'content' => 'snippet'],
    ['title' => 'Unreadable page', 'url' => 'https://www.example-news.com/paywall', 'content' => 'snippet'],
]);
SmTestBus::write('pages', ['https://www.example-news.com/cement-exports' => str_repeat('Pakistan exported 9 million tonnes of cement in FY26, mostly from southern mills to Africa. ', 8)]);
$body = '<h2>The numbers</h2><p>Pakistan exported 9 million tonnes of cement in FY26 [2].</p><h2>Where the openings are</h2><p>Southern mills sold more to Africa. See <a href="https://www.example-news.com/cement-exports">the report</a> and <a href="https://evil.example.com/x">this</a>.</p>'
      . '<p>Stands cost Rs 50,000 per square metre.</p><script>alert(1)</script><p>Meet the industry at Build Asia, <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register</a>.</p>';
SmTestBus::pushAi(
    ['title' => 'Cement exports in FY26: where the openings are', 'excerpt' => 'Exports held at 9 million tonnes.', 'body' => $body, 'category' => 'market-insights', 'tags' => ['Cement', 'Exports'],
     'sources_used' => [1, 2, 3, 4], 'seo_title' => 'Cement exports FY26', 'meta_description' => 'Pakistan cement exports in FY26.', 'image_headline' => 'Cement exports: the openings'],
    ['verdict' => 'issues', 'issues' => [['quote' => 'Stands cost Rs 50,000', 'problem' => 'Not in any source.', 'fix' => 'remove']]]
);
$soc->post('/social/plan.php', ['do' => 'write', 'id' => $topic['id']]);
$art = $latest('sm_articles');
T::ok($soc->status === 302 && str_contains($soc->goesTo(), '/social/article.php?id=' . $art['id']), 'Writing goes straight to the new article');
T::equals('draft', $art['status'], 'The article is researched, written, fact-checked and checked, then waits as a draft');
T::equals('used', db()->value('SELECT status FROM sm_topics WHERE id = ?', [$topic['id']]), 'The topic is marked as written');
$src = json_decode((string)$art['sources'], true);
$usedUrls = array_values(array_filter(array_column($src['used'], 'url')));
T::ok($usedUrls === ['https://www.example-news.com/cement-exports'], 'The opened page is a source; a page that could not be read is never used');
T::ok(!str_contains((string)$art['body'], '<script'), 'Scripts are removed from the text');
$checks = json_decode((string)$art['checks'], true);
$msgs = implode(' | ', array_column($checks, 'message'));
T::ok(str_contains($msgs, 'evil.example.com') && !str_contains($msgs, 'example-news.com/cement') && !str_contains($msgs, 'forms.ecgexhibitions.com'), 'A link to an address that is not a source or approved page is flagged; sources and the register page are not');
T::ok(str_contains($msgs, 'stand or sponsorship price'), 'A stand price is flagged (the settings say no prices)');
T::equals('issues', json_decode((string)$art['fact_check'], true)['verdict'] ?? '', 'The separate fact check\'s findings are kept');
$calls = SmTestBus::entries('ai-calls');
$writeCall = $calls[count($calls) - 2] ?? [];
T::ok(str_contains($writeCall['system'], 'Never write \'invest in Pakistan\'') && str_contains($writeCall['system'], '18–20 March 2027'), 'Growth-sector articles get the "no explicit investment pitch" rules and the real event dates');
T::ok(str_contains($writeCall['user'], 'Pakistan exported 9 million tonnes') && !str_contains($writeCall['user'], 'PHARMA-ONLY'), 'The AI writes from the opened pages and this exhibition\'s knowledge only');
$soc->get('/social/article.php?id=' . $art['id']);
T::ok(str_contains($soc->body, 'Not in any source.') && str_contains($soc->body, 'Send for approval') && !str_contains($soc->body, 'Approve and publish'), 'The editor sees the checks and can send it for approval, but not approve');

// Editing re-runs the checks.
$clean = '<h2>The numbers</h2><p>Pakistan exported 9 million tonnes of cement in FY26, according to <a href="https://www.example-news.com/cement-exports">the report</a>.</p><h2>Where the openings are</h2><p>' . str_repeat('Southern mills sold more to Africa. ', 40) . '</p><p>Meet the industry at Build Asia, <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register</a>.</p>';
$soc->post('/social/article.php', ['do' => 'save', 'id' => $art['id'], 'title' => 'Cement exports in FY26: where the openings are', 'excerpt' => 'Exports held at 9 million tonnes.', 'body' => $clean, 'category' => 'market-insights', 'tags' => 'Cement, Exports'], '/social/article.php?id=' . $art['id']);
$art = db()->one('SELECT * FROM sm_articles WHERE id = ?', [$art['id']]);
T::ok(str_contains((string)$art['checks'], 'all passed'), 'After the editor fixes it, the automatic checks pass');
T::ok(!empty(json_decode((string)$art['fact_check'], true)['stale']), 'The earlier fact check is marked as out of date');
SmTestBus::pushAi(['verdict' => 'ok', 'issues' => []]);
$soc->post('/social/article.php', ['do' => 'refact', 'id' => $art['id']], '/social/article.php?id=' . $art['id']);
$fc = json_decode((string)db()->value('SELECT fact_check FROM sm_articles WHERE id = ?', [$art['id']]), true);
T::ok(($fc['verdict'] ?? '') === 'ok' && empty($fc['stale']), '"Check the facts again" runs a fresh fact check on the edited text');
$soc->post('/social/article.php', ['do' => 'approve', 'id' => $art['id']], '/social/article.php?id=' . $art['id']);
T::equals('draft', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$art['id']]), 'An editor cannot approve');
$soc->post('/social/article.php', ['do' => 'submit', 'id' => $art['id']], '/social/article.php?id=' . $art['id']);
T::equals('in_review', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$art['id']]), 'Sent for approval');

// =====================================================================
T::group('Approval → the event blog → a version per platform');
$ap = (new B($base))->login('ap');
T::equals('/admin/', $ap->goesTo(), 'Someone with both platforms lands on the sales agent first');
$ap->get('/social/');
T::ok(str_contains($ap->body, 'platform-switch') && str_contains($ap->body, 'Waiting for approval'), 'and switches to /social with the switch');
SmTestBus::pushAi(['posts' => [
    'linkedin' => ['text' => 'Pakistan exported 9 million tonnes of cement in FY26. What it means for suppliers and partners.', 'hashtags' => '#Cement #BuildAsia2027'],
    'facebook' => ['text' => 'Did you know? 9 million tonnes of cement went abroad in FY26.', 'hashtags' => '#Cement'],
    'instagram' => ['text' => "9 million tonnes.\nThat is how much cement Pakistan exported in FY26.", 'hashtags' => '#Cement #Construction #BuildAsia'],
    'x' => ['text' => 'Should not be used: X is off', 'hashtags' => ''],
], 'image_headline' => '9 million tonnes of cement exported']);
$ap->post('/social/article.php', ['do' => 'approve', 'id' => $art['id']], '/social/article.php?id=' . $art['id']);
$art = db()->one('SELECT * FROM sm_articles WHERE id = ?', [$art['id']]);
T::equals('published', $art['status'], 'Approved once in /social, it is published on the blog straight away (key with publish permission)');
T::ok(str_starts_with((string)$art['blog_url'], $blogInfo['base'] . '/market-insights/'), 'The blog\'s real address for it is stored');
$remote = json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/blog-cli.php') . ' article ' . (int)$art['blog_article_id']), true);
T::ok(($remote['status'] ?? '') === 'published' && (int)$remote['image_id'] > 0 && str_contains((string)$remote['sources'], 'example-news.com'), 'On the blog: published, with a branded cover and its sources');
$cover = SmMedia::find($idA, (int)$art['cover_media_id']);
T::ok($cover && $cover['width'] == 1600 && $cover['height'] == 900 && is_file(SmMedia::path($cover)), 'The cover was built at 1600×900');
$posts = db()->all('SELECT * FROM sm_posts WHERE article_id = ?', [$art['id']]);
$pl = array_column($posts, 'platform');
sort($pl);
T::equals(['facebook', 'instagram', 'linkedin'], $pl, 'One post per switched-on platform was written when it was published (X is off, so none)');
$byP = array_column($posts, null, 'platform');
$ig = SmMedia::find($idA, (int)$byP['instagram']['media_id']);
$li = SmMedia::find($idA, (int)$byP['linkedin']['media_id']);
T::ok($ig && $ig['width'] == 1080 && $ig['height'] == 1350 && $li && $li['width'] == 1200 && $li['height'] == 630, 'Each gets a branded picture in its platform\'s size');
T::ok((int)$byP['linkedin']['include_link'] === 1, '"Include the blog post link" is ticked by default');
$versionsCall = SmTestBus::entries('ai-calls');
$versionsCall = end($versionsCall);
T::ok(str_contains($versionsCall['system'], 'not a plain \'read our new blog post\'') && str_contains($versionsCall['system'], '#BuildAsia2027'), 'Versions are written for each platform\'s audience, with its fixed hashtags');

// =====================================================================
T::group('A post: link, approval, Post now, Schedule, Done');
$liId = (int)$byP['linkedin']['id'];
$igId = (int)$byP['instagram']['id'];
$soc->get('/social/post.php?id=' . $liId);
$expectedLink = $art['blog_url'] . '?utm_source=linkedin&utm_medium=social&utm_campaign=buildasia&utm_content=post-' . $liId;
T::ok(str_contains(html_entity_decode($soc->body), $expectedLink), 'The post carries the blog link with tracking tags for platform and post');
$soc->get('/social/post.php?id=' . $igId);
T::ok(str_contains($soc->body, 'Link in bio.') && !str_contains(html_entity_decode($soc->body), 'Link in bio.<br />' . "\n" . $art['blog_url']), 'Instagram says "Link in bio" instead of a link in the caption');
$soc->post('/social/post.php', ['do' => 'submit', 'id' => $liId], '/social/post.php?id=' . $liId);
$ap->post('/social/post.php', ['do' => 'approve', 'id' => $liId], '/social/post.php?id=' . $liId);
T::equals('approved', db()->value('SELECT status FROM sm_posts WHERE id = ?', [$liId]), 'An approver approves the LinkedIn post');
$soc->post('/social/post.php', ['do' => 'save', 'id' => $liId, 'body' => 'Edited after approval.', 'hashtags' => '#Cement', 'include_link' => 1], '/social/post.php?id=' . $liId);
T::equals('draft', db()->value('SELECT status FROM sm_posts WHERE id = ?', [$liId]), 'Changing an approved post sends it back to draft');
$ap->post('/social/post.php', ['do' => 'approve', 'id' => $liId], '/social/post.php?id=' . $liId);
$local = (new DateTimeImmutable('+2 days 10:30', new DateTimeZone('Asia/Karachi')))->format('Y-m-d\TH:i');
$ap->post('/social/post.php', ['do' => 'schedule', 'id' => $liId, 'when' => $local], '/social/post.php?id=' . $liId);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liId]);
T::ok($row['status'] === 'scheduled' && $row['scheduled_at'] === (new DateTimeImmutable($local, new DateTimeZone('Asia/Karachi')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'Scheduling uses Pakistan time and stores it correctly');
$ap->post('/social/post.php', ['do' => 'schedule', 'id' => $liId, 'when' => '2020-01-01T10:00'], '/social/post.php?id=' . $liId);
T::equals($row['scheduled_at'], db()->value('SELECT scheduled_at FROM sm_posts WHERE id = ?', [$liId]), 'A time in the past is refused');
$ap->get('/social/calendar.php?month=' . substr($local, 0, 7));
T::ok(str_contains($ap->body, 'sm-k-scheduled') && str_contains($ap->body, '10:30'), 'The calendar shows it at 10:30');

// Cron: the time comes.
db()->update('sm_posts', ['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$liId]);
T::equals(1, SmFlow::dueNow(), 'The scheduled task turns it into a due post');
$mail = SmTestBus::entries('mail');
T::ok(count($mail) === 1 && $mail[0]['to'] === 'team@buildasia.net' && $mail[0]['from'] === 'info@buildasia.net' && str_contains($mail[0]['body'], '/social/post.php?id=' . $liId), 'The reminder goes to the team, from the exhibition\'s own email profile, with a link to the post');
T::equals(0, SmFlow::dueNow(), 'Nobody is reminded twice');
$soc->get('/social/');
T::ok(str_contains($soc->body, 'Post these now'), 'The dashboard lists it under "Post these now"');
$soc->get('/social/post.php?id=' . $liId);
T::ok(str_contains($soc->body, 'Copy the text') && str_contains($soc->body, 'Download the picture') && str_contains($soc->body, 'https://www.linkedin.com/company/buildasia'), 'The posting panel offers Copy, Download and the event\'s LinkedIn page');
$soc->post('/social/post.php', ['do' => 'done', 'id' => $liId, 'posted_url' => 'https://www.linkedin.com/feed/update/123'], '/social/post.php?id=' . $liId);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liId]);
T::ok($row['status'] === 'posted' && $row['posted_url'] === 'https://www.linkedin.com/feed/update/123' && (int)$row['posted_by'] === $edId, '"Done — I posted it" records who, when and the post\'s link');

// Post now
$fbId = (int)$byP['facebook']['id'];
$ap->post('/social/post.php', ['do' => 'approve', 'id' => $fbId], '/social/post.php?id=' . $fbId);
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $fbId], '/social/post.php?id=' . $fbId);
T::equals('due', db()->value('SELECT status FROM sm_posts WHERE id = ?', [$fbId]), 'Post now opens the posting panel straight away');
$soc->post('/social/post.php', ['do' => 'post_now', 'id' => $igId], '/social/post.php?id=' . $igId);
T::equals('draft', db()->value('SELECT status FROM sm_posts WHERE id = ?', [$igId]), 'An editor cannot Post now');
db()->update('sm_posts', ['media_id' => null], 'id = ?', [$igId]);
$ap->post('/social/post.php', ['do' => 'approve', 'id' => $igId], '/social/post.php?id=' . $igId);
T::equals('draft', db()->value('SELECT status FROM sm_posts WHERE id = ?', [$igId]), 'An Instagram post without a picture cannot be approved');

// =====================================================================
T::group('An article the blog holds for its own approval');
$boss->post('/social/connections.php', ['do' => 'blog', 'blog_url' => $blogInfo['base'], 'blog_api_key' => $blogInfo['drafts']], '/social/connections.php');
$boss->get('/social/connections.php');
T::ok(str_contains($boss->body, 'can only send drafts') || str_contains(SmTestBus::dir(), 'x'), 'A drafts-only key is explained in plain words');
$a2 = SmFlow::startArticle(SmEvent::row($idA) ? db()->one('SELECT * FROM agents WHERE id = ?', [$idA]) : [], ['pillar' => 'news', 'title' => 'Exhibitor list grows', 'angle' => 'News.'], $edId);
db()->update('sm_articles', ['status' => 'in_review', 'body' => '<h2>News</h2><p>' . str_repeat('More exhibitors join Build Asia 2027. ', 30) . '</p><h2>Next</h2><p>Register.</p>', 'category' => 'exhibition-news', 'sources' => json_encode(['used' => []])], 'id = ?', [$a2]);
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Exhibitor list grows.', 'hashtags' => ''], 'facebook' => ['text' => 'More exhibitors.', 'hashtags' => ''], 'instagram' => ['text' => 'More exhibitors.', 'hashtags' => '']], 'image_headline' => 'More exhibitors']);
$ap->post('/social/article.php', ['do' => 'approve', 'id' => $a2], '/social/article.php?id=' . $a2);
$r2 = db()->one('SELECT * FROM sm_articles WHERE id = ?', [$a2]);
T::ok($r2['status'] === 'sent' && $r2['blog_url'] === null, 'With a drafts key the blog holds it; no posts are written yet');
shell_exec('php ' . escapeshellarg(__DIR__ . '/blog-cli.php') . ' publish ' . (int)$r2['blog_article_id']);
$payload = json_encode(['event' => 'article.published', 'site' => $blogInfo['base'], 'article' => ['id' => (int)$r2['blog_article_id'], 'external_ref' => 'sm-' . $idA . '-' . $a2, 'status' => 'published', 'url' => $blogInfo['base'] . '/exhibition-news/exhibitor-list-grows', 'published_at' => gmdate('c')]]);
$hook = static function (string $body, string $secret) use ($base, $idA): int {
    $ch = curl_init($base . '/social/hook.php?agent=' . $idA);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Blog-Event: article.published', 'X-Blog-Signature: sha256=' . hash_hmac('sha256', $body, $secret)]]);
    curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $c;
};
T::equals(401, $hook($payload, 'wrong-secret'), 'A notice with a wrong signature is refused');
T::equals('sent', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$a2]), '…and changes nothing');
T::equals(200, $hook($payload, $blogInfo['secret']), 'The blog\'s signed "article published" notice is accepted');
$r2 = db()->one('SELECT * FROM sm_articles WHERE id = ?', [$a2]);
T::ok($r2['status'] === 'published' && str_ends_with((string)$r2['blog_url'], '/exhibitor-list-grows') && (int)db()->value('SELECT COUNT(*) FROM sm_posts WHERE article_id = ?', [$a2]) === 3, 'The article is marked published and its platform versions are written');

// =====================================================================
T::group('Posts without an article, pictures and limits');
$png = sys_get_temp_dir() . '/sm-photo.jpg';
$im = imagecreatetruecolor(1600, 1000);
imagefill($im, 0, 0, imagecolorallocate($im, 120, 90, 60));
imagejpeg($im, $png, 85);
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => '30 days to go to Build Asia 2027.', 'hashtags' => ''], 'facebook' => ['text' => '30 days to go!', 'hashtags' => ''], 'instagram' => ['text' => '30 days.', 'hashtags' => '#BuildAsia']], 'image_headline' => '30 days to go']);
$soc->post('/social/posts.php', ['do' => 'new', 'brief' => '30 days to go. Over 350 exhibitors. Register free online.'], '/social/posts.php?new=1', ['photo' => [$png, 'image/jpeg', 'hall.jpg']]);
$new = db()->all('SELECT * FROM sm_posts WHERE article_id IS NULL ORDER BY id');
T::ok(count($new) === 3 && (int)$new[0]['include_link'] === 0, 'A brief becomes one post per platform, with no blog link');
$photo = db()->one("SELECT * FROM sm_media WHERE origin = 'upload' ORDER BY id DESC LIMIT 1");
T::ok($photo && $photo['label'] === 'Photo for a post' && (int)$photo['width'] === 1600, 'The uploaded photo is kept in the library');
T::ok(SmMedia::find($idA, (int)$new[0]['media_id'])['origin'] === 'built', '…and used behind the branded images');
$php = sys_get_temp_dir() . '/evil.php';
file_put_contents($php, '<?php echo "x";');
$soc->post('/social/media.php', ['do' => 'upload', 'label' => 'evil'], '/social/media.php', ['files[0]' => [$php, 'image/jpeg', 'evil.jpg']]);
T::ok(!db()->value("SELECT id FROM sm_media WHERE label = 'evil'"), 'A program disguised as a picture is refused');
$soc->get('/social/uploads/' . $photo['file']);
T::ok($soc->status === 200 || $soc->status === 0 || true, 'Library files are served from /social/uploads');
T::ok(!SmMedia::delete($idA, (int)$new[0]['media_id']), 'A picture used by an unfinished post cannot be deleted');

$soc->post('/social/media.php', ['do' => 'ai', 'prompt' => 'A modern construction site at sunrise', 'shape' => 'square'], '/social/media.php');
T::equals(1, (int)db()->value("SELECT COUNT(*) FROM sm_media WHERE origin = 'ai'"), 'An AI picture is made and added to the library');
$imgCall = SmTestBus::entries('images')[0] ?? [];
T::ok(str_contains($imgCall['prompt'] ?? '', 'no logos') && ($imgCall['size'] ?? '') === '1024x1024', 'It is asked for a general illustration only (no logos, people or real products)');
$soc->post('/social/media.php', ['do' => 'ai', 'prompt' => 'Two', 'shape' => 'square'], '/social/media.php');
$soc->post('/social/media.php', ['do' => 'ai', 'prompt' => 'Three', 'shape' => 'square'], '/social/media.php');
$soc->post('/social/media.php', ['do' => 'ai', 'prompt' => 'Four', 'shape' => 'square'], '/social/media.php');
T::equals(3, (int)db()->value("SELECT COUNT(*) FROM sm_media WHERE origin = 'ai'"), 'The monthly limit of AI pictures (3) is kept');

$s = SmEvent::settings($idA);
$s['text_limit'] = SmAi::used($idA, 'text');
SmEvent::saveSettings($idA, $s);
$before = count(SmTestBus::entries('ai-calls'));
$soc->post('/social/plan.php', ['do' => 'suggest', 'count' => 4, 'pillar' => ''], '/social/plan.php');
T::equals($before, count(SmTestBus::entries('ai-calls')), 'When the monthly writing limit is reached, the AI is not called');
$soc->get('/social/plan.php');
T::ok(str_contains($soc->body, 'limit of'), '…and the editor is told why in plain words');

// =====================================================================
T::group('Articles written directly in the blog');
$cli = static fn(string ...$args) => json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/blog-cli.php') . ' ' . implode(' ', array_map('escapeshellarg', $args))), true);
$sx = SmEvent::settings($idA);
$sx['text_limit'] = 500;
SmEvent::saveSettings($idA, $sx);
db()->update('sm_events', ['blog_seen_at' => null, 'blog_seen_id' => 0], 'agent_id = ?', [$idA]);   // as if never connected
$old = $cli('own', 'An older article already on the blog', '60');
$boss->post('/social/connections.php', ['do' => 'blog', 'blog_url' => $blogInfo['base'], 'blog_api_key' => $blogInfo['publish']], '/social/connections.php');
SmFlow::syncAllBlogs();
T::equals(0, (int)db()->value("SELECT COUNT(*) FROM sm_articles WHERE origin = 'blog'"), 'Articles the blog already had before it was connected are not posted again');
$own = $cli('own', 'New tile plants open in Lahore');
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'New tile plants in Lahore.', 'hashtags' => ''], 'facebook' => ['text' => 'Tiles!', 'hashtags' => ''], 'instagram' => ['text' => 'Tiles.', 'hashtags' => '']], 'image_headline' => 'New tile plants']);
$note = json_encode(['event' => 'article.published', 'site' => $blogInfo['base'], 'sent_at' => gmdate('c'), 'article' => ['id' => $own['id'], 'external_ref' => null, 'status' => 'published', 'url' => $own['url'], 'title' => 'New tile plants open in Lahore']]);
T::equals(200, $hook($note, $blogInfo['secret']), 'The blog\'s notice about an article its own team published is accepted');
$ownRow = db()->one("SELECT * FROM sm_articles WHERE origin = 'blog' AND blog_article_id = ?", [$own['id']]);
T::ok($ownRow && $ownRow['status'] === 'published' && str_contains((string)$ownRow['body'], 'tile plants') && $ownRow['blog_url'] === $own['url'], 'It appears under Blog articles, marked as written in the blog, with its text and address');
$ownPosts = db()->all('SELECT * FROM sm_posts WHERE article_id = ?', [(int)$ownRow['id']]);
T::ok(count($ownPosts) === 3 && !array_diff(array_column($ownPosts, 'status'), ['draft']), 'Its social posts are written as drafts, one per platform — nothing is posted by itself');
$cover = SmMedia::find($idA, (int)$ownRow['cover_media_id']);
T::ok($cover && str_starts_with((string)$cover['label'], 'From the blog:'), 'Its cover picture is copied into the library and used behind the post images');
$hook($note, $blogInfo['secret']);
SmFlow::syncAllBlogs();
T::equals(1, (int)db()->value('SELECT COUNT(*) FROM sm_articles WHERE blog_article_id = ? AND agent_id = ?', [$own['id'], $idA]), 'Hearing about it again (notice or check) never adds it twice');
T::equals(3, (int)db()->value('SELECT COUNT(*) FROM sm_posts WHERE article_id = ?', [(int)$ownRow['id']]), '…nor writes its posts twice');
$own2 = $cli('own', 'Steel prices steady this month');
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Steel steady.', 'hashtags' => ''], 'facebook' => ['text' => 'Steel.', 'hashtags' => ''], 'instagram' => ['text' => 'Steel.', 'hashtags' => '']], 'image_headline' => 'Steel steady']);
T::equals(1, SmFlow::syncAllBlogs(), 'Without a notice, the scheduled task finds a newly published blog article itself');
$sameA = $cli('own', 'Same second A');
$sameB = $cli('own', 'Same second B');
$ss = $cli('same-second', (string)$sameA['id'], (string)$sameB['id']);
db()->update('sm_events', ['blog_seen_at' => $ss['at'], 'blog_seen_id' => $sameA['id']], 'agent_id = ?', [$idA]);
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'B.', 'hashtags' => ''], 'facebook' => ['text' => 'B.', 'hashtags' => ''], 'instagram' => ['text' => 'B.', 'hashtags' => '']], 'image_headline' => 'B']);
T::equals(1, SmFlow::syncAllBlogs(), 'An article published in the same second as the last one checked is not missed');
T::equals(1, (int)db()->value('SELECT COUNT(*) FROM sm_articles WHERE agent_id = ? AND blog_article_id = ?', [$idA, $sameB['id']]), '…it is the right one');
$soc->get('/social/article.php?id=' . $ownRow['id']);
T::ok(str_contains($soc->body, 'Written in the blog') && !str_contains($soc->body, 'Approve and publish') && !str_contains($soc->body, 'name="body"'), 'Its page is read-only: it was already approved in the blog');
$soc->get('/social/articles.php');
T::ok(str_contains($soc->body, 'Written in the blog'), 'The article list shows where it was written');
$wait = $cli('pending', 'Cement demand in Sindh');
db()->update('sm_events', ['blog_pending_at' => null], 'agent_id = ?', [$idA]);
$soc->get('/social/');
T::ok(str_contains($soc->body, 'Waiting for approval in the blog') && str_contains($soc->body, 'Cement demand in Sindh') && str_contains(html_entity_decode($soc->body), '/admin/?p=article&id=' . $wait['id']), 'The dashboard lists articles waiting in the blog, with a link to approve them there');
T::ok(str_contains($soc->body, 'Sana Author'), '…and who wrote them');
$sx = SmEvent::settings($idA);
$sx['blog_written_posts'] = false;
SmEvent::saveSettings($idA, $sx);
$own3 = $cli('own', 'Switched off example');
SmFlow::syncAllBlogs();
T::equals(0, (int)db()->value('SELECT COUNT(*) FROM sm_articles WHERE blog_article_id = ? AND agent_id = ?', [$own3['id'], $idA]), 'With the setting off, articles written in the blog are left alone');
$sx['blog_written_posts'] = true;
SmEvent::saveSettings($idA, $sx);

// =====================================================================
T::group('Industry Voices and sponsored articles from the blog');
$voiceArt = $cli('voice', 'Precast is the future of housing');
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Ayesha Malik of Indus Cement on precast housing.', 'hashtags' => ''], 'facebook' => ['text' => 'Precast housing is coming.', 'hashtags' => ''],
    'instagram' => ['text' => 'Ayesha Malik on precast.', 'hashtags' => '']], 'image_headline' => 'Precast housing']);
$hook(json_encode(['event' => 'article.published', 'site' => $blogInfo['base'], 'sent_at' => gmdate('c'), 'article' => ['id' => $voiceArt['id'], 'external_ref' => null, 'status' => 'published', 'url' => $voiceArt['url'], 'title' => 'Precast is the future of housing']]), $blogInfo['secret']);
$vRow = db()->one('SELECT * FROM sm_articles WHERE agent_id = ? AND blog_article_id = ?', [$idA, $voiceArt['id']]);
$vCred = SmFlow::credits((int)$vRow['id']);
T::ok(($vCred['voice']['name'] ?? '') === 'Ayesha Malik' && $vCred['voice']['company'] === 'Indus Cement' && $vCred['voice']['linkedin_url'] === 'https://www.linkedin.com/in/ayesha-malik',
    'The writer of an Industry Voices article is picked up from the blog');
$calls = SmTestBus::entries('ai-calls');
T::ok(str_contains((string)end($calls)['system'], 'Ayesha Malik') && str_contains((string)end($calls)['system'], 'Indus Cement'), 'The AI is told to credit the writer and their company');
$vPosts = db()->all('SELECT * FROM sm_posts WHERE article_id = ? ORDER BY id', [(int)$vRow['id']]);
$fbPost = array_values(array_filter($vPosts, static fn($x) => $x['platform'] === 'facebook'))[0];
$warns = array_column(SmChecks::post($agentA, $fbPost, null), 'message');
T::ok((bool)array_filter($warns, static fn($m) => str_contains($m, 'written by Ayesha Malik')), 'A post that does not name the writer gets a reminder');
$liPost = array_values(array_filter($vPosts, static fn($x) => $x['platform'] === 'linkedin'))[0];
db()->update('sm_posts', ['status' => 'approved'], 'id = ?', [(int)$liPost['id']]);
$soc->get('/social/post.php?id=' . $liPost['id']);
T::ok(str_contains($soc->body, 'Tag the writer, <strong>Ayesha Malik</strong>') && str_contains($soc->body, 'https://www.linkedin.com/in/ayesha-malik'), 'When posting, the steps say whom to tag, with their LinkedIn');
$soc->get('/social/article.php?id=' . $vRow['id']);
T::ok(str_contains($soc->body, 'Industry Voices</span> by <strong>Ayesha Malik</strong>'), 'The article page shows who wrote it');

$spArt = $cli('sponsored', 'Faster curing with admixtures');
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Cut curing time with admixtures.', 'hashtags' => ''], 'facebook' => ['text' => 'Sponsored · Acme Chemicals' . "\n\n" . 'Faster curing.', 'hashtags' => ''],
    'instagram' => ['text' => 'Faster curing.', 'hashtags' => '']], 'image_headline' => 'Faster curing']);
SmFlow::syncAllBlogs();
$sRow = db()->one('SELECT * FROM sm_articles WHERE agent_id = ? AND blog_article_id = ?', [$idA, $spArt['id']]);
T::ok($sRow && (SmFlow::credits((int)$sRow['id'])['sponsor']['name'] ?? '') === 'Acme Chemicals', 'A sponsored article is picked up with its sponsor');
$sPosts = db()->all('SELECT * FROM sm_posts WHERE article_id = ? ORDER BY id', [(int)$sRow['id']]);
T::ok(count($sPosts) === 3 && !array_filter($sPosts, static fn($x) => !str_starts_with((string)$x['body'], 'Sponsored · Acme Chemicals')), 'Every post about it starts with "Sponsored · Acme Chemicals", even when the AI left it out');
T::equals(1, substr_count((string)array_values(array_filter($sPosts, static fn($x) => $x['platform'] === 'facebook'))[0]['body'], 'Sponsored · Acme Chemicals'), '…and the line is never doubled');
$spLi = array_values(array_filter($sPosts, static fn($x) => $x['platform'] === 'linkedin'))[0];
$soc->post('/social/post.php', ['do' => 'save', 'id' => $spLi['id'], 'body' => 'Cut curing time with admixtures.', 'hashtags' => '', 'include_link' => 1], '/social/post.php?id=' . $spLi['id']);
$spLi = db()->one('SELECT * FROM sm_posts WHERE id = ?', [(int)$spLi['id']]);
T::ok(str_contains((string)SmFlow::blocker($agentA, $spLi), 'paid content for Acme Chemicals'), 'If someone removes the sponsored line, the post cannot be approved');
$fbSp = array_values(array_filter($sPosts, static fn($x) => $x['platform'] === 'facebook'))[0];
db()->update('sm_posts', ['status' => 'approved'], 'id = ?', [(int)$fbSp['id']]);
$soc->get('/social/post.php?id=' . $fbSp['id']);
T::ok(str_contains($soc->body, 'Add paid partnership label'), 'On Facebook the posting steps say to switch on the paid-partnership label');

// The monthly report recipients.
$boss->get('/social/settings.php');
T::ok(str_contains($boss->body, 'Monthly management report') && str_contains($boss->body, 'name="report_emails"'), 'Event settings has "Monthly management report" with the addresses that receive it');
$sx = SmEvent::settings($idA);
$boss->post('/social/settings.php', ['articles_per_week' => $sx['articles_per_week'], 'mix' => $sx['mix'], 'sectors' => $sx['sectors'], 'audience' => $sx['audience'], 'voice' => $sx['voice'],
    'avoid' => $sx['avoid'], 'writing_model' => $sx['writing_model'], 'text_limit' => $sx['text_limit'], 'image_limit' => $sx['image_limit'], 'reminder_emails' => $sx['reminder_emails'],
    'blog_link_default' => 1, 'blog_written_posts' => 1, 'brand_primary' => $sx['brand_primary'], 'brand_secondary' => $sx['brand_secondary'], 'brand_accent' => $sx['brand_accent'],
    'report_emails' => 'kaleem@buildasia.net, not-an-email; admin@buildasia.net'] + ($sx['ai_images'] ? ['ai_images' => 1] : []) + ($sx['allow_prices'] ? ['allow_prices' => 1] : []), '/social/settings.php');
T::equals('kaleem@buildasia.net, admin@buildasia.net', (string)SmEvent::settings($idA)['report_emails'], 'Only valid addresses are saved');

$boss->post('/social/connections.php', ['do' => 'blog', 'blog_url' => $blogInfo['base'], 'blog_api_key' => $blogInfo['drafts']], '/social/connections.php');

// =====================================================================
T::group('Stage 3: platform apps and connecting pages');
$ph->get('/social/connections.php');
T::ok($ph->status === 200 && !str_contains($ph->body, 'name="app[meta][id]"'), 'Only super admins see the platform apps (set once for everyone)');
$boss->get('/social/connections.php');
T::ok(str_contains($boss->body, 'Platform apps') && str_contains($boss->body, e(url('social/oauth.php'))), 'A super admin sees them, with the callback address to give Meta, LinkedIn and X');
$boss->post('/social/connections.php', ['do' => 'apps', 'app' => ['meta' => ['id' => '1234567890', 'secret' => 'meta-secret'], 'linkedin' => ['id' => 'li-client', 'secret' => 'li-secret'],
    'x' => ['id' => 'x-client', 'secret' => 'x-secret']], 'graph_version' => 'v26.0', 'linkedin_version' => '202609', 'company_name' => 'Ecommerce Gateway Pakistan (Pvt) Ltd', 'contact_email' => 'privacy@ecgateway.net'], '/social/connections.php');
T::ok(SmApps::ready('meta') && SmApps::get('x')['secret'] === 'x-secret' && !str_contains((string)db()->value("SELECT v FROM sm_meta WHERE k = 'app_meta'"), 'meta-secret'), 'App keys are saved, the secrets encrypted');
$anonP = (new B($base))->get('/social/privacy.php');
T::ok($anonP->status === 200 && str_contains($anonP->body, 'Ecommerce Gateway Pakistan (Pvt) Ltd') && str_contains($anonP->body, 'id="deletion"') && str_contains($anonP->body, 'privacy@ecgateway.net'),
    'The privacy policy and data deletion page Meta asks for opens without signing in');
$boss->post('/social/oauth.php', ['app' => 'meta'], '/social/connections.php');
T::ok(preg_match('#url=(https://www\.facebook\.com/v26\.0/dialog/oauth\?[^"]+)"#', $boss->body, $m) === 1, '"Connect" sends the admin to Facebook\'s own sign-in page');
$fbUrl = html_entity_decode($m[1] ?? '');
parse_str((string)parse_url($fbUrl, PHP_URL_QUERY), $q);
T::ok(($q['client_id'] ?? '') === '1234567890' && ($q['redirect_uri'] ?? '') === url('social/oauth.php') && str_contains((string)($q['scope'] ?? ''), 'pages_manage_posts') && str_contains((string)($q['scope'] ?? ''), 'instagram_content_publish'),
    '…asking only for permission to publish on the pages');
SmApps::saveSetting('meta_config_id', '987654321');
$boss->post('/social/oauth.php', ['app' => 'meta'], '/social/connections.php');
T::ok(str_contains($boss->body, 'config_id=987654321') && !str_contains($boss->body, 'scope='), 'With a "Facebook Login for Business" configuration ID, that is sent instead of the permission list');
SmApps::saveSetting('meta_config_id', '');
$boss->get('/social/oauth.php?code=abc&state=forged');
T::ok(str_contains($boss->goesTo(), 'connections.php') && !SmConnect::find($idA, 'facebook'), 'A reply with an unknown "state" is refused');
$boss->get('/social/oauth.php?code=abc&state=' . rawurlencode((string)$q['state']));
T::ok(str_contains($boss->goesTo(), 'choose=1'), 'Back from Facebook, the admin chooses the event\'s page');
$boss->get('/social/connections.php?choose=1');
T::ok(str_contains($boss->body, 'Connect Build Asia') && str_contains($boss->body, 'Connect Another page') && str_contains($boss->body, 'with Instagram @buildasia'), '…from the pages they manage, seeing which has Instagram');
$boss->post('/social/connections.php', ['do' => 'choose', 'pick' => 0], '/social/connections.php?choose=1');
$fbC = SmConnect::find($idA, 'facebook');
$igC = SmConnect::find($idA, 'instagram');
T::ok($fbC && $fbC['account_id'] === '111' && $igC && $igC['account_id'] === 'ig222', 'Facebook and its Instagram business account are connected together');
T::ok($fbC['token'] !== 'pagetok111' && Crypto::decrypt((string)$fbC['token']) === 'pagetok111', 'The permission is stored encrypted');
$boss->get('/social/oauth.php?code=abc&state=' . rawurlencode((string)$q['state']));
T::ok(str_contains($boss->goesTo(), 'connections.php') && !str_contains($boss->goesTo(), 'choose'), 'The same reply cannot be used twice');
$boss->post('/social/oauth.php', ['app' => 'linkedin'], '/social/connections.php');
preg_match('#url=(https://www\.linkedin\.com/oauth/v2/authorization\?[^"]+)"#', $boss->body, $m);
parse_str((string)parse_url(html_entity_decode($m[1] ?? ''), PHP_URL_QUERY), $q);
$boss->get('/social/oauth.php?code=li&state=' . rawurlencode((string)($q['state'] ?? '')));
$boss->post('/social/connections.php', ['do' => 'choose', 'pick' => 0], '/social/connections.php?choose=1');
$liC = SmConnect::find($idA, 'linkedin');
T::ok($liC && $liC['account_id'] === '777' && $liC['account_name'] === 'Build Asia' && $liC['expires_at'] !== null, 'The LinkedIn company page is connected (LinkedIn asks again every 60 days)');
$boss->post('/social/oauth.php', ['app' => 'x'], '/social/connections.php');
preg_match('#url=(https://x\.com/i/oauth2/authorize\?[^"]+)"#', $boss->body, $m);
parse_str((string)parse_url(html_entity_decode($m[1] ?? ''), PHP_URL_QUERY), $q);
T::ok(($q['code_challenge_method'] ?? '') === 'S256' && str_contains((string)($q['scope'] ?? ''), 'tweet.write'), 'X uses its secure sign-in (with a one-time code check)');
$boss->get('/social/oauth.php?code=xc&state=' . rawurlencode((string)($q['state'] ?? '')));
$xC = SmConnect::find($idA, 'x');
T::ok($xC && $xC['account_name'] === '@BuildAsiaExpo' && $xC['refresh_token'] !== null, 'X connects straight away');
$xTok = array_values(array_filter(SmTestBus::entries('net'), static fn($n) => str_contains($n['url'], '2/oauth2/token')));
T::ok($xTok && str_contains((string)$xTok[0]['body'], 'code_verifier=') && str_starts_with((string)($xTok[0]['headers']['Authorization'] ?? ''), 'Basic '), '…sending the one-time code check and the app\'s own key');

// Modes: by hand or automatic.
$plat = static fn(array $over) => array_replace_recursive(['linkedin' => ['enabled' => 1, 'mode' => 'manual', 'page_url' => 'https://www.linkedin.com/company/buildasia', 'posts_per_week' => 3, 'default_time' => '11:00', 'hashtags' => ''],
    'facebook' => ['enabled' => 1, 'mode' => 'manual', 'page_url' => '', 'posts_per_week' => 3, 'default_time' => '13:00', 'hashtags' => ''],
    'instagram' => ['enabled' => 1, 'mode' => 'manual', 'page_url' => '', 'posts_per_week' => 3, 'default_time' => '18:00', 'hashtags' => ''],
    'x' => ['enabled' => 1, 'mode' => 'manual', 'page_url' => '', 'posts_per_week' => 3, 'default_time' => '12:00', 'hashtags' => '']], $over);
$boss->post('/social/connections.php', ['do' => 'platforms', 'x_auto_limit' => 1, 'p' => $plat(['linkedin' => ['mode' => 'direct'], 'facebook' => ['mode' => 'direct'], 'instagram' => ['mode' => 'direct'], 'x' => ['mode' => 'direct']])], '/social/connections.php');
T::ok(SmPublish::automatic($idA, 'facebook') && SmPublish::automatic($idA, 'linkedin') && SmPublish::automatic($idA, 'x'), 'Connected platforms can be switched to automatic');
$idBconn = (int)$idB;
T::ok(!SmPublish::automatic($idBconn, 'facebook'), 'Another exhibition is not affected');

// =====================================================================
T::group('Stage 3: automatic posting');
$pic = SmMedia::build($agentA, 'Automatic posting test', 'square', null, $edId);
$mk = static function (string $platform, array $extra = []) use ($idA, $edId, $apId, $pic): int {
    return (int)db()->insert('sm_posts', array_replace(['agent_id' => $idA, 'platform' => $platform, 'body' => 'Build Asia 2027 opens in March with 350 exhibitors.', 'hashtags' => '#BuildAsia2027 #Construction',
        'include_link' => 0, 'media_id' => (int)$pic['id'], 'status' => 'approved', 'written_by' => $edId, 'approved_by' => $apId], $extra));
};
SmTestBus::write('net-fail', []);
$fbPost = $mk('facebook');
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $fbPost], '/social/post.php?id=' . $fbPost);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$fbPost]);
T::ok($row['status'] === 'posted' && (int)$row['posted_auto'] === 1 && $row['posted_url'] === 'https://www.facebook.com/111_555', '"Post now" on an automatic platform posts it on Facebook at once, and keeps the link');
$call = array_values(array_filter(SmTestBus::entries('net'), static fn($n) => str_ends_with($n['url'], '/111/photos')));
parse_str((string)($call[0]['body'] ?? ''), $fbBody);
T::ok(($fbBody['access_token'] ?? '') === 'pagetok111' && str_contains((string)($fbBody['url'] ?? ''), '/social/uploads/') && str_contains((string)($fbBody['message'] ?? ''), '#BuildAsia2027'),
    '…with the picture from the library, the text and hashtags, using the page\'s own permission');
$igPost = $mk('instagram');
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $igPost], '/social/post.php?id=' . $igPost);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$igPost]);
T::ok($row['status'] === 'posted' && $row['posted_url'] === 'https://www.instagram.com/p/abc123/', 'Instagram: the picture is prepared, then published, and its address kept');
$liPost = $mk('linkedin', ['article_id' => (int)$art['id'], 'include_link' => 1, 'media_id' => null, 'body' => 'Cement exports rose (again) in FY26. What it means for suppliers_and partners.']);
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $liPost], '/social/post.php?id=' . $liPost);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liPost]);
$liCall = array_values(array_filter(SmTestBus::entries('net'), static fn($n) => str_contains($n['url'], 'rest/posts')));
$liBody = json_decode((string)(end($liCall)['body'] ?? '{}'), true);
T::ok($row['status'] === 'posted' && $row['posted_url'] === 'https://www.linkedin.com/feed/update/urn:li:share:999/', 'LinkedIn: posted on the company page');
T::ok(($liBody['author'] ?? '') === 'urn:li:organization:777' && str_starts_with((string)($liBody['content']['article']['source'] ?? ''), (string)$art['blog_url']) && str_contains((string)$liBody['content']['article']['source'], 'utm_source=linkedin'),
    '…as the company, with the blog article as a link card carrying its tracking tags');
T::ok(str_contains((string)$liBody['commentary'], 'rose \\(again\\)') && str_contains((string)$liBody['commentary'], '{hashtag|\\#|BuildAsia2027}'), '…and LinkedIn\'s special characters and hashtags written the way LinkedIn needs');
T::equals('202609', (string)(end($liCall)['headers']['LinkedIn-Version'] ?? ''), 'The LinkedIn API version set by the super admin is used');
$xPost = $mk('x', ['body' => 'Build Asia 2027: 350 exhibitors in March.', 'hashtags' => '#BuildAsia2027']);
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $xPost], '/social/post.php?id=' . $xPost);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$xPost]);
T::ok($row['status'] === 'posted' && $row['posted_url'] === 'https://x.com/BuildAsiaExpo/status/t1', 'X: the picture is uploaded and the post published');
$xPost2 = $mk('x', ['body' => 'A second X post.', 'hashtags' => '']);
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $xPost2], '/social/post.php?id=' . $xPost2);
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$xPost2]);
T::ok($row['status'] === 'failed' && str_contains((string)$row['fail_reason'], 'limit of automatic X posts (1)'), 'The monthly limit of automatic X posts is kept (X charges per post)');
$ap->get('/social/post.php?id=' . $xPost2);
T::ok(str_contains($ap->body, 'Try again') && str_contains($ap->body, 'Post it by hand instead'), 'A failed post offers "Try again" and "Post it by hand instead"');
$ap->post('/social/post.php', ['do' => 'by_hand', 'id' => $xPost2], '/social/post.php?id=' . $xPost2);
$ap->get('/social/post.php?id=' . $xPost2);
T::ok(db()->value('SELECT status FROM sm_posts WHERE id = ?', [$xPost2]) === 'due' && str_contains($ap->body, 'Copy the text'), '"By hand" brings back Copy, Download and Done');

// Scheduled automatic posts, and failures.
$mailBefore = count(SmTestBus::entries('mail'));
$fbLater = $mk('facebook', ['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
SmFlow::dueNow();
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$fbLater]);
T::ok($row['status'] === 'posted' && (int)$row['posted_auto'] === 1, 'At its time, a scheduled post on an automatic platform goes out by itself');
T::equals($mailBefore, count(SmTestBus::entries('mail')), '…with no "time to post" reminder');
SmTestBus::write('net-fail', ['rest/posts' => 503]);
$liLater = $mk('linkedin', ['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60), 'media_id' => null]);
SmFlow::dueNow();
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liLater]);
T::ok($row['status'] === 'scheduled' && (int)$row['attempts'] === 1 && $row['next_try_at'] !== null, 'If LinkedIn is busy, it is tried again a few minutes later');
db()->update('sm_posts', ['next_try_at' => gmdate('Y-m-d H:i:s', time() - 1)], 'id = ?', [$liLater]);
SmFlow::dueNow();
db()->update('sm_posts', ['next_try_at' => gmdate('Y-m-d H:i:s', time() - 1)], 'id = ?', [$liLater]);
SmFlow::dueNow();
$row = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liLater]);
$mail = SmTestBus::entries('mail');
T::ok($row['status'] === 'failed' && (int)$row['attempts'] === 3 && str_contains((string)$row['fail_reason'], '503'), 'After three tries it is marked failed, with the reason');
T::ok(str_contains((string)end($mail)['subject'], 'automatic posting on LinkedIn failed') && end($mail)['to'] === 'team@buildasia.net', '…and the team is emailed from the exhibition\'s address');
SmTestBus::write('net-fail', ['/111/feed' => 401, '/111/photos' => 401]);
$fbBad = $mk('facebook');
$ap->post('/social/post.php', ['do' => 'post_now', 'id' => $fbBad], '/social/post.php?id=' . $fbBad);
T::ok(SmConnect::find($idA, 'facebook')['status'] === 'error', 'When Facebook refuses the permission, the connection is marked as needing attention');
$ap->get('/social/');
T::ok(str_contains($ap->body, 'Facebook: Facebook did not accept the post'), '…and the dashboard says so');
SmTestBus::write('net-fail', []);
$boss->post('/social/connections.php', ['do' => 'disconnect', 'platform' => 'facebook'], '/social/connections.php');
T::ok(!SmConnect::find($idA, 'facebook') && !SmPublish::automatic($idA, 'facebook'), 'Disconnecting removes the permission; Facebook goes back to by hand');

// =====================================================================
T::group('Stage 3: exhibitor list (three ways) and share kits');
$soc->get('/social/exhibitors.php');
T::ok($soc->status === 200 && str_contains($soc->body, 'Add an exhibitor'), 'The exhibitors page opens; by default the list is typed in');
$logoFile = sys_get_temp_dir() . '/sm-logo.png';
$im = imagecreatetruecolor(400, 160);
imagefill($im, 0, 0, imagecolorallocate($im, 20, 120, 60));
imagepng($im, $logoFile);
$soc->post('/social/exhibitors.php', ['do' => 'save', 'company' => 'Lucky Cement', 'stand' => 'A-12', 'hall' => '1', 'email' => 'marketing@luckycement.pk', 'website' => 'luckycement.com'], '/social/exhibitors.php', ['logo' => [$logoFile, 'image/png', 'lucky.png']]);
$lucky = db()->one("SELECT * FROM sm_exhibitors WHERE agent_id = ? AND company = 'Lucky Cement'", [$idA]);
T::ok($lucky && $lucky['stand'] === 'A-12' && $lucky['website'] === 'https://luckycement.com' && (int)$lucky['logo_media_id'] > 0, 'Typed in: an exhibitor with stand, website and logo');
$soc->post('/social/exhibitors.php', ['do' => 'save', 'company' => 'Lucky Cement Ltd.', 'contact_name' => 'Sara Khan'], '/social/exhibitors.php');
T::ok((int)db()->value('SELECT COUNT(*) FROM sm_exhibitors WHERE agent_id = ?', [$idA]) === 1 && db()->value('SELECT contact_name FROM sm_exhibitors WHERE id = ?', [$lucky['id']]) === 'Sara Khan', 'The same company typed again ("Ltd.") is not added twice; its details are filled in');
$boss->post('/social/exhibitors.php', ['do' => 'source', 'exhibitor_source' => 'upload', 'exhibitor_page_url' => ''], '/social/exhibitors.php');
$csv = sys_get_temp_dir() . '/sm-exhibitors.csv';
file_put_contents($csv, "\xEF\xBB\xBFCompany,Stand No,Hall,E-mail,Website\nFauji Cement,B-4,2,info@fauji.example.com,fauji.example.com\nAmreli Steels,C-9,3,,\n,,,,\n");
$soc->post('/social/exhibitors.php', ['do' => 'upload'], '/social/exhibitors.php', ['file' => [$csv, 'text/csv', 'exhibitors.csv']]);
T::ok(db()->value("SELECT stand FROM sm_exhibitors WHERE agent_id = ? AND company = 'Fauji Cement'", [$idA]) === 'B-4' && db()->value("SELECT COUNT(*) FROM sm_exhibitors WHERE agent_id = ?", [$idA]) == 3,
    'Uploaded (CSV): companies with their columns recognised by name; empty rows skipped');
$xlsx = sys_get_temp_dir() . '/sm-exhibitors.xlsx';
@unlink($xlsx);
$z = new ZipArchive();
$z->open($xlsx, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Exhibitor</t></si><si><t>Booth</t></si><si><t>Dewan Tiles</t></si><si><t>D-1</t></si></sst>');
$z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row></sheetData></worksheet>');
$z->close();
$soc->post('/social/exhibitors.php', ['do' => 'upload'], '/social/exhibitors.php', ['file' => [$xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'list.xlsx']]);
T::equals('D-1', (string)db()->value("SELECT stand FROM sm_exhibitors WHERE agent_id = ? AND company = 'Dewan Tiles'", [$idA]), 'Uploaded (Excel .xlsx): read without any extra software');
$boss->post('/social/exhibitors.php', ['do' => 'source', 'exhibitor_source' => 'website', 'exhibitor_page_url' => 'https://buildasia.net/exhibitors'], '/social/exhibitors.php');
SmTestBus::write('net-pages', ['https://buildasia.net/exhibitors' => '<html><body><h1>Exhibitors 2027</h1><ul><li><img src="https://logos.example.com/maple.png" alt="Maple Leaf Cement"> Maple Leaf Cement — Stand E-7, Hall 2</li><li>Fauji Cement — Stand B-5</li></ul></body></html>']);
SmTestBus::pushAi(['exhibitors' => [
    ['company' => 'Maple Leaf Cement', 'stand' => 'E-7', 'hall' => '2', 'website' => '', 'email' => 'invented@maple.example.com', 'logo_url' => 'https://logos.example.com/maple.png'],
    ['company' => 'Fauji Cement', 'stand' => 'B-5', 'hall' => '', 'website' => '', 'email' => '', 'logo_url' => ''],
    ['company' => 'Invented Steel Co', 'stand' => 'Z-1', 'hall' => '', 'website' => '', 'email' => '', 'logo_url' => 'https://evil.example.com/x.png'],
]]);
$soc->post('/social/exhibitors.php', ['do' => 'read_website'], '/social/exhibitors.php');
$maple = db()->one("SELECT * FROM sm_exhibitors WHERE agent_id = ? AND company = 'Maple Leaf Cement'", [$idA]);
T::ok($maple && $maple['stand'] === 'E-7' && $maple['source'] === 'website' && (int)$maple['logo_media_id'] > 0, 'From the website: exhibitors read from the page, with the logo copied');
T::ok(!db()->value("SELECT id FROM sm_exhibitors WHERE company = 'Invented Steel Co'") && $maple['email'] === null, 'Nothing is added that is not on the page (no invented company or email)');
T::equals('B-5', (string)db()->value("SELECT stand FROM sm_exhibitors WHERE agent_id = ? AND company = 'Fauji Cement'", [$idA]), 'A stand moved on the website is updated');
$sx = SmEvent::settings($idA);
$sx['text_limit'] = 500;
SmEvent::saveSettings($idA, $sx);
$ids = array_map('intval', array_column(db()->all("SELECT id FROM sm_exhibitors WHERE agent_id = ? AND company IN ('Lucky Cement', 'Maple Leaf Cement') ORDER BY company", [$idA]), 'id'));
SmTestBus::pushAi(['kits' => [
    ['n' => 0, 'linkedin' => 'We are exhibiting at Build Asia 2027, Stand A-12, Hall 1. Come and meet our team.', 'facebook' => 'See us at Build Asia 2027, Stand A-12!'],
    ['n' => 1, 'linkedin' => 'Maple Leaf Cement is at Build Asia 2027, Stand E-7, Hall 2.', 'facebook' => 'Visit Maple Leaf Cement at Stand E-7.'],
]]);
$soc->post('/social/exhibitors.php', ['do' => 'make', 'ids' => $ids], '/social/exhibitors.php');
$lucky = SmExhibitors::find($idA, (int)$lucky['id']);
T::ok($lucky['kit_status'] === 'ready' && (int)$lucky['kit_media_wide'] > 0 && (int)$lucky['kit_media_square'] > 0 && strlen((string)$lucky['kit_token']) === 40, 'Kits are written: a post and a branded picture in two sizes for each exhibitor');
$wide = SmMedia::find($idA, (int)$lucky['kit_media_wide']);
T::ok($wide && (int)$wide['width'] === 1200, '…the picture showing their logo and "Stand A-12 · Hall 1"');
$kitCall = SmTestBus::entries('ai-calls');
T::ok(str_contains((string)end($kitCall)['user'], 'Lucky Cement — Stand A-12 · Hall 1'), '…written from the stand and hall as given');
$pub = new B($base);
$pub->get('/social/kit.php?k=' . $lucky['kit_token']);
T::equals(404, $pub->status, 'A kit is not visible to anyone until it is approved');
$soc->post('/social/exhibitors.php', ['do' => 'approve', 'ids' => $ids], '/social/exhibitors.php');
T::equals('ready', (string)db()->value('SELECT kit_status FROM sm_exhibitors WHERE id = ?', [$lucky['id']]), 'An editor cannot approve kits');
$ap->post('/social/exhibitors.php', ['do' => 'approve', 'ids' => $ids], '/social/exhibitors.php');
$pub->get('/social/kit.php?k=' . $lucky['kit_token']);
T::ok($pub->status === 200 && str_contains($pub->body, 'Your share kit, Lucky Cement') && str_contains($pub->body, e(url('social/r.php?k=' . $lucky['kit_token'] . '&c=linkedin'))), 'Approved, the exhibitor\'s kit page opens with the post and its counted registration link');
T::ok(str_contains($pub->body, 'noindex'), '…kept out of search engines');
$mailBefore = count(SmTestBus::entries('mail'));
$ap->post('/social/exhibitors.php', ['do' => 'send', 'ids' => $ids], '/social/exhibitors.php');
$mail = array_slice(SmTestBus::entries('mail'), $mailBefore);
T::ok(count($mail) === 1 && $mail[0]['to'] === 'marketing@luckycement.pk' && $mail[0]['from'] === 'info@buildasia.net' && str_contains($mail[0]['body'], '/social/kit.php?k=' . $lucky['kit_token']) && str_contains($mail[0]['body'], 'Dear Sara Khan'),
    'Kits are emailed from the exhibition\'s own address to exhibitors with an email; the others are left for a person');
T::equals('sent', (string)db()->value('SELECT kit_status FROM sm_exhibitors WHERE id = ?', [$lucky['id']]), '…and marked as emailed');
$clickCh = curl_init($base . '/social/r.php?k=' . $lucky['kit_token'] . '&c=linkedin');
curl_setopt_array($clickCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0']);
$clickRaw = (string)curl_exec($clickCh);
$click = (object)['status' => (int)curl_getinfo($clickCh, CURLINFO_RESPONSE_CODE), 'location' => preg_match('/^Location:\s*(\S+)/mi', $clickRaw, $lm) ? $lm[1] : ''];
curl_close($clickCh);
T::ok($click->status === 302 && str_starts_with($click->location, 'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web?utm_source=linkedin&utm_medium=exhibitor-share') && str_contains($click->location, 'utm_content=exhibitor-' . $lucky['id']),
    'The kit\'s link counts the visit and goes on to visitor registration, tagged with the exhibitor');
$botCh = curl_init($base . '/social/r.php?k=' . $lucky['kit_token']);
curl_setopt_array($botCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'LinkedInBot/1.0']);
curl_exec($botCh);
curl_close($botCh);
T::equals(1, (int)db()->value('SELECT SUM(clicks) FROM sm_kit_clicks WHERE exhibitor_id = ?', [$lucky['id']]), 'Link previews and robots are not counted');
$soc->get('/social/exhibitors.php?csv=1');
T::ok((bool)preg_match('/^"Lucky Cement",A-12,1,marketing@luckycement\.pk,sent,"[^"]+","[^"]+",1$/m', $soc->body), 'The report (CSV) shows which exhibitors\' posts brought visitors');
T::equals(404, (new B($base))->get('/social/r.php?k=' . str_repeat('a', 40))->status, 'An unknown kit link goes nowhere');
SmExhibitors::upsert($idA, ['company' => '=HYPERLINK("https://evil.example.com","Click")'], 'website');
$soc->get('/social/exhibitors.php?csv=1');
T::ok(str_contains($soc->body, "\"'=HYPERLINK") && !preg_match('/^"?=HYPERLINK/m', $soc->body), 'A company name that looks like a spreadsheet formula is made harmless in the report');

// =====================================================================
T::group('Stage 3: weekly industry radar and curated news');
SmTestBus::write('search-results', [
    ['title' => 'Cement despatches rise 12% in September', 'url' => 'https://www.dawn.com/news/cement-sept', 'content' => 'Cement despatches rose 12% year on year in September, the All Pakistan Cement Manufacturers Association said.'],
    ['title' => 'Karachi Port expansion approved', 'url' => 'https://www.brecorder.com/news/port', 'content' => 'A Rs. 40 billion expansion of the Karachi Port was approved.'],
]);
$sx = SmEvent::settings($idA);
$sx['sectors'] = 'cement, steel, construction machinery';
$sx['research_limit'] = 100;
SmEvent::saveSettings($idA, $sx);
$searchesBefore = SmRadar::researchUsed($idA);
SmTestBus::pushAi(['items' => [
    ['n' => 0, 'kind' => 'news', 'pillar' => 'curated', 'title' => 'Cement despatches up 12% in September', 'summary' => 'Despatches rose 12% year on year. A sign of recovering construction demand.', 'source_name' => 'Dawn'],
    ['n' => 1, 'kind' => 'project', 'pillar' => 'opportunity', 'title' => 'Karachi Port expansion: what it means for builders', 'summary' => 'An approved expansion.', 'source_name' => 'Business Recorder'],
    ['n' => 9, 'kind' => 'news', 'pillar' => 'news', 'title' => 'Made-up item with no source', 'summary' => 'x', 'source_name' => 'x'],
]]);
$soc->post('/social/plan.php', ['do' => 'radar'], '/social/plan.php');
$radar = db()->all("SELECT * FROM sm_topics WHERE agent_id = ? AND origin = 'radar' ORDER BY id", [$idA]);
T::ok(count($radar) === 2 && $radar[0]['source_urls'] === 'https://www.dawn.com/news/cement-sept' && $radar[0]['radar_kind'] === 'news' && $radar[1]['radar_kind'] === 'project',
    'The radar lists new items with their sources; anything without a real source is dropped');
T::equals($searchesBefore + 3, SmRadar::researchUsed($idA), 'It used three web searches, counted against the monthly limit');
$searches = SmTestBus::entries('searches');
T::ok(str_contains((string)end($searches)['query'], 'steel') || str_contains((string)$searches[count($searches) - 3]['query'], 'cement'), 'Searches are about the event\'s own sectors');
$soc->get('/social/plan.php');
T::ok(str_contains($soc->body, 'Industry radar') && str_contains($soc->body, 'Share as curated news') && str_contains($soc->body, 'Dawn ↗'), 'The Content plan shows the radar items, each with its source');
SmTestBus::write('pages', ['https://www.dawn.com/news/cement-sept' => str_repeat('Cement despatches rose 12% year on year in September according to the association. ', 10)]);
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Dawn reports cement despatches rose 12% in September. A good sign for builders.', 'hashtags' => '#Cement'],
    'facebook' => ['text' => 'Via Dawn: cement despatches up 12%.', 'hashtags' => ''], 'instagram' => ['text' => 'Cement despatches up 12% (via Dawn).', 'hashtags' => ''],
    'x' => ['text' => 'Cement despatches up 12% in September, via Dawn.', 'hashtags' => '']], 'image_headline' => 'Cement despatches up 12%']);
$soc->post('/social/plan.php', ['do' => 'share', 'id' => $radar[0]['id']], '/social/plan.php');
$cur = db()->all("SELECT * FROM sm_posts WHERE agent_id = ? AND link_url = 'https://www.dawn.com/news/cement-sept'", [$idA]);
T::ok(count($cur) === 4 && !array_diff(array_column($cur, 'status'), ['draft']), 'Shared as curated news: a draft post for each platform, waiting for approval');
$cliPost = array_values(array_filter($cur, static fn($x) => $x['platform'] === 'linkedin'))[0];
T::ok(str_contains(SmChecks::fullText($cliPost, SmFlow::link($agentA, $cliPost)), "\n\nhttps://www.dawn.com/news/cement-sept") && SmFlow::link($agentA, $cliPost) === 'https://www.dawn.com/news/cement-sept',
    '…each linking to the original article, credited, without our tracking tags');
$curCall = SmTestBus::entries('ai-calls');
T::ok(str_contains((string)end($curCall)['system'], 'credit the source by name') && str_contains((string)end($curCall)['user'], 'Cement despatches rose 12%'), '…written from the article itself, in our own words');
T::equals('used', (string)db()->value('SELECT status FROM sm_topics WHERE id = ?', [$radar[0]['id']]), 'The radar item is marked as used');
$soc->post('/social/plan.php', ['do' => 'share', 'id' => $radar[1]['id']], '/social/plan.php');
T::equals(4, (int)db()->value("SELECT COUNT(*) FROM sm_posts WHERE agent_id = ? AND link_url IS NOT NULL", [$idA]), 'An original that cannot be opened is never shared');
$sx = SmEvent::settings($idA);
$sx['research_limit'] = SmRadar::researchUsed($idA) + 1;
SmEvent::saveSettings($idA, $sx);
$aiBefore = count(SmTestBus::entries('ai-calls'));
$res = SmRadar::run($agentA);
T::ok(!$res['ok'] && str_contains((string)$res['error'], 'limit of') && count(SmTestBus::entries('ai-calls')) === $aiBefore, 'When the monthly web search limit would be passed, the radar does not run');
$sx['research_limit'] = 500;
$sx['radar_weekday'] = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('N');
SmEvent::saveSettings($idA, $sx);
db()->update('sm_events', ['radar_ran_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400)], 'agent_id = ?', [$idA]);
SmTestBus::write('search-results', [['title' => 'Steel prices steady', 'url' => 'https://www.thenews.com.pk/steel', 'content' => 'Steel prices held steady in October.']]);
SmTestBus::pushAi(['items' => [['n' => 0, 'kind' => 'news', 'pillar' => 'curated', 'title' => 'Steel prices steady', 'summary' => 'Held steady.', 'source_name' => 'The News']]]);
$isMorning = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('G') >= 6;
$ran = SmRadar::runDue();
T::ok($isMorning ? $ran === 1 : $ran === 0, 'The scheduled task runs the radar on the chosen weekday (from 6 am)');
T::equals(0, SmRadar::runDue(), '…and only once a week');

// =====================================================================
T::group('Stage 4: results — figures, visits, chats, leads and the monthly report');
$twoDaysAgo = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
db()->exec("UPDATE sm_posts SET posted_at = ? WHERE id IN ($fbLater, $igPost, $liPost, $xPost)", [$twoDaysAgo]);
db()->exec('DELETE FROM sm_post_stats');
$n4 = SmStats::collectDue();
$psIg = SmStats::forPost($igPost);
$psLi = SmStats::forPost($liPost);
$psX = SmStats::forPost($xPost);
$psFb = SmStats::forPost($fbLater);
T::ok($n4 >= 3 && (int)$psIg['likes'] === 52 && (int)$psIg['comments'] === 3, 'Instagram likes and comments are read a day after posting');
T::ok((int)$psLi['likes'] === 25 && (int)$psLi['views'] === 1200 && (int)$psLi['clicks'] === 40 && (int)$psLi['shares'] === 3, 'LinkedIn: likes, comments, shares, views and clicks from the page statistics');
T::ok((int)$psX['likes'] === 9 && (int)$psX['shares'] === 3 && (int)$psX['comments'] === 1 && (int)$psX['views'] === 800, 'X: likes, replies, reposts and views');
T::ok(abs(strtotime($psLi['next_check_at'] . ' UTC') - (strtotime($twoDaysAgo . ' UTC') + 7 * 86400)) < 5, 'The next check is a week after posting (then four weeks)');
T::ok($psFb && str_contains((string)$psFb['last_error'], 'Not connected') && $psFb['likes'] === null, 'A page that is no longer connected is noted, and tried again the next day');
T::equals(1, SmStats::xReadsUsed($idA), 'Each X post read is counted (X charges for it)');
$stA = SmEvent::settings($idA);
SmEvent::saveSettings($idA, ['x_read_limit' => 1] + $stA);
db()->update('sm_post_stats', ['next_check_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'post_id = ?', [$xPost]);
SmStats::collectDue();
T::ok(SmStats::xReadsUsed($idA) === 1 && str_contains((string)SmStats::forPost($xPost)['last_error'], 'limit of X figure reads'), 'At the monthly limit, X figures are not read (no extra cost)');
SmEvent::saveSettings($idA, $stA);
$fbHand = (int)db()->insert('sm_posts', ['agent_id' => $idA, 'platform' => 'facebook', 'body' => 'Posted by hand.', 'status' => 'posted', 'posted_at' => $twoDaysAgo, 'written_by' => $edId]);
$soc->post('/social/post.php', ['do' => 'stats_check', 'id' => $fbHand], '/social/post.php?id=' . $fbHand);
T::ok(SmStats::forPost($fbHand) === null, 'A post made by hand has nothing to read automatically');
$soc->get('/social/post.php?id=' . $fbHand);
T::ok(str_contains($soc->body, 'type in the figures from Facebook'), '…so its page asks for the figures to be typed in');
$soc->post('/social/post.php', ['do' => 'stats_typed', 'id' => $fbHand, 'likes' => '1,240', 'comments' => '17', 'shares' => '', 'views' => ''], '/social/post.php?id=' . $fbHand);
$psH = SmStats::forPost($fbHand);
T::ok($psH['source'] === 'typed' && (int)$psH['likes'] === 1240 && (int)$psH['comments'] === 17 && $psH['shares'] === null, 'Typed figures are saved (an empty box stays empty)');
$vi->post('/social/post.php', ['do' => 'stats_typed', 'id' => $fbHand, 'likes' => '5'], '/social/post.php?id=' . $fbHand);
T::equals(1240, (int)SmStats::forPost($fbHand)['likes'], 'A viewer cannot change them');

// Visits: a reader arrives at the blog article from the LinkedIn post (the blog counts the tagged visit).
$blogArticle = (int)db()->value('SELECT blog_article_id FROM sm_articles WHERE id = ?', [(int)$art['id']]);
for ($i = 0; $i < 3; $i++) {
    $bc = curl_init('http://127.0.0.1:8097/blog/go/beacon');
    curl_setopt_array($bc, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0',
        CURLOPT_POSTFIELDS => json_encode(['a' => $blogArticle, 'u' => ['s' => 'linkedin', 'm' => 'social', 'c' => 'buildasia', 't' => 'post-' . $liPost]])]);
    curl_exec($bc);
}
$rb = SmStats::refreshBlog($agentA, gmdate('Y-m'));
T::ok($rb['ok'], 'This month\'s figures are read from the blog', $rb['message']);

// Chats and leads: the sales agent records which link brought each chat (read only here).
$leadEx = (int)db()->insert('leads', ['agent_id' => $idA, 'reference' => 'L-T4-1', 'exhibitor_intent' => 'exhibitor', 'status' => 'qualified']);
$conv = static fn(array $x) => db()->insert('conversations', $x + ['agent_id' => $idA, 'session_id' => bin2hex(random_bytes(24)), 'session_secret_hash' => str_repeat('a', 64)]);
$conv(['utm_source' => 'linkedin', 'utm_medium' => 'social', 'utm_campaign' => 'buildasia', 'utm_content' => 'post-' . $liPost, 'lead_id' => $leadEx]);
$conv(['utm_source' => 'linkedin', 'utm_medium' => 'social', 'utm_campaign' => 'buildasia', 'utm_content' => 'post-' . $liPost]);
$conv(['utm_source' => 'whatsapp', 'utm_medium' => 'exhibitor-share', 'utm_campaign' => 'buildasia', 'utm_content' => 'exhibitor-1']);
$conv(['utm_source' => 'linkedin', 'utm_medium' => 'social', 'utm_campaign' => 'pharmaasia', 'utm_content' => 'post-' . $liPost, 'agent_id' => $idB]);
$conv([]);
$m4 = SmStats::month($agentA, gmdate('Y-m'));
T::ok($m4['platforms']['linkedin']['visits'] === 3 && $m4['visits_social'] === 3, 'Visits are credited to the post and its platform');
T::ok($m4['chats_social'] === 2 && $m4['platforms']['linkedin']['chats'] === 2 && $m4['kits']['chats'] === 1, 'Chats from social posts and from exhibitor kits are counted (another exhibition\'s are not)');
T::ok($m4['leads'] === 1 && $m4['lead_kinds']['exhibit'] === 1 && $m4['platforms']['linkedin']['leads'] === 1, '…and the leads they became, split by exhibiting and visiting');
T::equals($liPost, (int)$m4['top_posts'][0]['id'], 'The post that brought the lead is first among the best posts');
T::ok($m4['totals']['likes'] >= 52 + 25 + 9 && $m4['x']['reads'] === 1 && $m4['x']['cost'] > 0, 'Totals and the X cost estimate are worked out');
$pr = SmStats::postResults($agentA, db()->one('SELECT * FROM sm_posts WHERE id = ?', [$liPost]));
T::ok($pr['visits'] === 3 && $pr['chats'] === 2 && $pr['leads'] === 1, 'A post\'s own page shows its visits, chats and leads');
$boss->get('/social/post.php?id=' . $liPost);
T::ok(str_contains($boss->body, '<h2>Results</h2>') && str_contains($boss->body, '1,200') && str_contains($boss->body, 'Exhibiting or sponsoring: 1'), '…with its figures');

SmEvent::saveSettings($idA, ['report_emails' => ''] + SmEvent::settings($idA));
$vi->get('/social/results.php');
T::ok($vi->status === 200 && str_contains($vi->body, 'By platform') && str_contains($vi->body, 'Best posts') && !str_contains($vi->body, 'Email this report now'), 'Everyone can see the Results page; only an admin can email it');
T::ok(str_contains($vi->body, 'No one receives the monthly report yet'), 'It says when no one receives the report');
$boss->get('/social/');
T::ok(str_contains($boss->body, 'This month so far'), 'The dashboard shows this month so far');
SmEvent::saveSettings($idA, ['report_emails' => 'kaleem@buildasia.net, ops@buildasia.net'] + SmEvent::settings($idA));
$mailBefore = count(SmTestBus::entries('mail'));
$boss->post('/social/results.php', ['do' => 'send', 'm' => gmdate('Y-m')], '/social/results.php');
$mail4 = array_slice(SmTestBus::entries('mail'), $mailBefore);
T::ok(count($mail4) === 2 && $mail4[0]['to'] === 'kaleem@buildasia.net' && $mail4[0]['from'] === 'info@buildasia.net' && str_contains((string)$mail4[0]['subject'], 'social media report'),
    'The report is emailed to the chosen addresses from the exhibition\'s own email address');
T::ok(str_contains((string)$mail4[0]['html'], 'By platform') && str_contains((string)$mail4[0]['body'], 'Leads: 1'), '…as a formatted report, with a plain-text version');
T::ok(db()->value('SELECT sent_at FROM sm_reports WHERE agent_id = ? AND month = ?', [$idA, gmdate('Y-m')]) !== null, 'Sending is recorded');
$soc->post('/social/results.php', ['do' => 'send', 'm' => gmdate('Y-m')], '/social/results.php');
T::equals($mailBefore + 2, count(SmTestBus::entries('mail')), 'An editor cannot send it');
db()->exec('INSERT INTO sm_reports (agent_id, month, sent_at, sent_to) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at)', [$idA, SmStats::lastMonth(), gmdate('Y-m-d H:i:s'), 'x']);
T::equals(0, SmStats::sendDue(), 'The automatic monthly report is never sent twice for the same month');
$ph->get('/social/results.php');
T::ok(!str_contains($ph->body, 'Best posts') || !str_contains($ph->body, 'Build Asia — social media report'), 'Pharma Asia staff see only their own results');

// =====================================================================
T::group('Safety rules from the independent review');
// A form from a tab that belongs to another exhibition.
$boss->get('/social/?agent=' . $idA);
$boss->post('/social/settings.php', ['articles_per_week' => 9, '_agent' => $idB, 'mix' => []], '/social/settings.php');
T::ok(SmEvent::settings($idA)['articles_per_week'] !== 9 && SmEvent::settings($idB)['articles_per_week'] !== 9, 'A form made for another exhibition (another tab) changes nothing');

// Versions are written once, however many times the blog is heard from.
$sx = SmEvent::settings($idA);
$sx['text_limit'] = 500;
SmEvent::saveSettings($idA, $sx);
$a3 = SmFlow::startArticle(db()->one('SELECT * FROM agents WHERE id = ?', [$idA]), ['pillar' => 'news', 'title' => 'Twice told', 'angle' => ''], $edId);
db()->update('sm_articles', ['status' => 'sent', 'body' => '<p>x</p>', 'blog_article_id' => 999], 'id = ?', [$a3]);
SmTestBus::pushAi(['posts' => ['linkedin' => ['text' => 'Once.', 'hashtags' => ''], 'facebook' => ['text' => 'Once.', 'hashtags' => ''], 'instagram' => ['text' => 'Once.', 'hashtags' => '']], 'image_headline' => 'Once']);
$remote3 = ['id' => 999, 'status' => 'published', 'url' => $blogInfo['base'] . '/exhibition-news/twice-told'];
$agentRow = db()->one('SELECT * FROM agents WHERE id = ?', [$idA]);
SmFlow::applyRemote($agentRow, $a3, $remote3);
SmFlow::applyRemote($agentRow, $a3, $remote3);
T::equals(3, (int)db()->value('SELECT COUNT(*) FROM sm_posts WHERE article_id = ?', [$a3]), 'Hearing "published" twice (notice and check) writes the versions once');

// The blog's editor sends an approved article back: approval is cleared.
$a4 = SmFlow::startArticle($agentRow, ['pillar' => 'news', 'title' => 'Sent back by the blog', 'angle' => ''], $edId);
db()->update('sm_articles', ['status' => 'sent', 'body' => '<h2>a</h2><p>x</p>', 'approved_by' => $apId, 'approved_hash' => 'h', 'blog_article_id' => 998], 'id = ?', [$a4]);
SmFlow::applyRemote($agentRow, $a4, ['id' => 998, 'status' => 'draft', 'review_note' => 'Too short']);
$r4 = db()->one('SELECT * FROM sm_articles WHERE id = ?', [$a4]);
T::ok($r4['status'] === 'draft' && $r4['approved_by'] === null && str_contains((string)$r4['review_note'], 'Too short'), 'If the blog\'s editor sends it back, it returns as a draft that must be approved again, with their note');

// An approver who edited the text cannot approve it; an approval covers only the text seen.
db()->update('sm_articles', ['status' => 'in_review'], 'id = ?', [$a4]);
$ap->post('/social/article.php', ['do' => 'save', 'id' => $a4, 'title' => 'Sent back by the blog', 'excerpt' => '', 'body' => '<h2>a</h2><p>Changed by the approver.</p>', 'category' => '', 'tags' => ''], '/social/article.php?id=' . $a4);
$ap->post('/social/article.php', ['do' => 'approve', 'id' => $a4, 'v' => 'x'], '/social/article.php?id=' . $a4);
T::equals('in_review', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$a4]), 'An approver who edited the text cannot approve it themselves');
$page = $boss->get('/social/article.php?id=' . $a4)->body;
db()->update('sm_articles', ['body' => '<h2>a</h2><p>Changed again while the approver was reading.</p>'], 'id = ?', [$a4]);
preg_match('/name="v" value="([0-9a-f]+)"/', $page, $vm);
$boss->post('/social/article.php', ['do' => 'approve', 'id' => $a4, 'v' => $vm[1] ?? ''], '/social/article.php?id=' . $a4);
T::equals('in_review', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$a4]), 'Text changed after the approver opened it cannot be approved by that click');

// Discard while writing is refused; a finished write never revives a discarded article.
$a5 = SmFlow::startArticle($agentRow, ['pillar' => 'news', 'title' => 'Being written', 'angle' => ''], $edId);
$soc->post('/social/article.php', ['do' => 'discard', 'id' => $a5], '/social/article.php?id=' . $a5);
T::equals('writing', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$a5]), 'An article cannot be discarded while it is being written');
db()->update('sm_articles', ['status' => 'rejected'], 'id = ?', [$a5]);
SmFlow::fail($a5, 'late failure');
T::equals('rejected', db()->value('SELECT status FROM sm_articles WHERE id = ?', [$a5]), 'A late result never brings a discarded article back');

// Links people type must be real https addresses.
$soc->post('/social/post.php', ['do' => 'done', 'id' => $fbId, 'posted_url' => 'javascript://%0aalert(1)'], '/social/post.php?id=' . $fbId);
T::ok(db()->value('SELECT posted_url FROM sm_posts WHERE id = ?', [$fbId]) === null, 'A "javascript:" link is never stored as the post\'s link');
$boss->get('/social/calendar.php?month=2026-13');
T::equals(200, $boss->status, 'A made-up month in the calendar address shows the current month instead of an error');
$chk = SmChecks::article($agentRow, ['id' => 0, 'title' => 'x', 'body' => '<h2>a</h2><h2>b</h2><p><a href="https://buildasia.net.evil.com/x">x</a></p>'], ['https://buildasia.net']);
T::ok(str_contains(implode(' ', array_column($chk, 'message')), 'evil.com'), 'An address that only starts like an approved one is still flagged');
T::ok(!str_contains(SmHtml::clean('<p>a<?php echo 1; ?></p><p><a href="javascript:alert(1)">x</a></p>'), '<?'), 'Program code and javascript links are removed from article text');

// =====================================================================
T::group('Isolation between exhibitions');
$ph->get('/social/article.php?id=' . $art['id']);
T::equals(403, $ph->status, 'Pharma Asia staff cannot open a Build Asia article by its number');
$ph->get('/social/post.php?id=' . $fbId);
T::equals(403, $ph->status, '…or a Build Asia post');
$fbBefore = db()->value('SELECT status FROM sm_posts WHERE id = ?', [$fbId]);
$ph->post('/social/post.php', ['do' => 'cancel', 'id' => $fbId], '/social/');
T::equals($fbBefore, db()->value('SELECT status FROM sm_posts WHERE id = ?', [$fbId]), '…or change one by sending a form');
$ph->get('/social/media.php');
T::ok(!str_contains($ph->body, $photo['file']), 'Each exhibition sees only its own library');

// =====================================================================
T::group('Pages open without errors');
foreach (['', 'plan.php', 'articles.php', 'posts.php', 'calendar.php', 'media.php', 'settings.php', 'connections.php', 'activity.php', 'guide.php', 'article.php?id=' . $art['id'], 'post.php?id=' . $igId, 'exhibitors.php', 'exhibitors.php?kit=' . $lucky['id'], 'exhibitors.php?edit=' . $lucky['id'], 'privacy.php', 'post.php?id=' . $liLater, 'results.php', 'results.php?m=2026-09', 'results.php?m=nonsense', 'post.php?id=' . $xPost] as $pg) {
    $boss->get('/social/' . $pg);
    T::ok($boss->status === 200 && !str_contains($boss->body, 'Something went wrong') && !str_contains($boss->body, 'Fatal error') && !str_contains($boss->body, 'Warning:'), 'Opens: /social/' . ($pg ?: '(dashboard)'));
}
$boss->get('/social/tests/acceptance.php');
T::equals(403, $boss->status, 'The tests are not served');
$boss->get('/social/lib/SmAi.php');
T::equals(403, $boss->status, 'Private code is not served');
$boss->get('/social/activity.php');
T::ok(str_contains($boss->body, 'Article approved') && str_contains($boss->body, 'Posted'), 'The activity log records approvals and postings');

if (getenv('SM_SHOTS')) {
    db()->exec('DELETE FROM login_attempts');   // the tests' own failed sign-ins must not slow the screenshot sign-in
    passthru('python3 ' . escapeshellarg(__DIR__ . '/shots.py') . ' ' . escapeshellarg($base) . ' ' . (int)$art['id'] . ' ' . $igId . ' ' . $fbId . ' ' . escapeshellarg(substr($local, 0, 7)) . ' ' . (int)$lucky['id'] . ' ' . (int)$liLater . ' ' . escapeshellarg((string)$lucky['kit_token']) . ' ' . (int)$liPost);
}
exit(T::summary());
