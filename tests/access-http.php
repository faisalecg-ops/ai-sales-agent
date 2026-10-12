<?php
declare(strict_types=1);

/**
 * End-to-end access tests: real sign-ins, real pages, real forms.
 *
 * Starts PHP's built-in web server on this copy of the platform, signs in as
 * a super admin and as staff, and proves that staff on exhibition A cannot
 * see or change anything of exhibition B — by typing addresses and by
 * submitting forms — and that the super-admin pages stay closed to them.
 *
 * Run with:  php tests/access-http.php
 * Like tests/acceptance.php it clears the database it runs on: never point it
 * at a live installation.
 */

require_once __DIR__ . '/harness.php';

$mock = new MockLlmProvider();
LlmProviderFactory::setTestProvider($mock);
reset_database();

// ---------------------------------------------------------------------
// Fixtures: two exhibitions, each with its own records
// ---------------------------------------------------------------------
$agentA = seed_agent();
$idA = (int)$agentA['id'];
db()->update('agents', ['name' => 'Alpha Expo'], 'id = ?', [$idA]);
$idB = (int)db()->insert('agents', [
    'slug' => 'bravo', 'name' => 'Bravo Expo', 'status' => 'active', 'website_url' => 'https://bravo.example.net',
    'allowed_domains' => 'bravo.example.net', 'persona' => AgentDefaults::PERSONA, 'sales_objectives' => AgentDefaults::OBJECTIVES,
    'sales_rules' => AgentDefaults::SALES_RULES, 'ai_disclosure_text' => AgentDefaults::DISCLOSURE,
    'llm_provider' => 'mock', 'llm_model' => 'mock-model', 'notify_email' => 'b@example.net', 'readiness_min_signals' => 2,
]);
Fallbacks::seed($idB);

$lead = static fn(int $agent, string $name): int => (int)db()->insert('leads', [
    'agent_id' => $agent, 'reference' => 'L' . strtoupper(substr(md5($name), 0, 8)), 'name' => $name,
    'email' => strtolower(str_replace(' ', '.', $name)) . '@example.net', 'status' => 'new',
]);
$leadA = $lead($idA, 'Alice Alphason');
$leadB = $lead($idB, 'Boris Bravoski');

$agentBRow = db()->one('SELECT * FROM agents WHERE id = ?', [$idB]);
[$convB] = start_conversation($agentBRow);
db()->update('conversations', ['lead_id' => $leadB], 'id = ?', [(int)$convB['id']]);
[$convA] = start_conversation($agentA);

$companyB = (int)CompanyStore::findOrCreate($idB, ['name' => 'Bravo Cranes Secret Ltd', 'website' => 'https://bravo-cranes.example'])['company']['id'];
$articleB = (int)db()->insert('knowledge_articles', ['agent_id' => $idB, 'title' => 'Bravo private article',
    'body' => 'Bravo only.', 'category' => 'general', 'keywords' => '', 'status' => 'approved', 'active' => 1]);
$suppB = (int)db()->insert('suppression_list', ['agent_id' => $idB, 'kind' => 'email', 'value' => 'blocked@bravo.example',
    'source' => 'manual']);
Audit::setActor('fixture');
Audit::log('fixture_event', $idB, 'test', null, 'BRAVO-SECRET-AUDIT');
Audit::log('fixture_event', $idA, 'test', null, 'ALPHA-VISIBLE-AUDIT');
RegistrationSource::setEventIds($idA, '24');

$user = static function (string $name, string $role, array $access = [], int $mustChange = 0): int {
    $id = (int)db()->insert('admin_users', [
        'username' => $name, 'email' => $name . '@example.net', 'password_hash' => password_hash('Pass-' . $name . '-2026', PASSWORD_DEFAULT),
        'role' => $role, 'active' => 1, 'must_change_password' => $mustChange,
    ]);
    foreach ($access as [$platform, $agent, $r]) {
        db()->insert('admin_user_access', ['user_id' => $id, 'platform' => $platform, 'agent_id' => $agent, 'role' => $r]);
    }
    return $id;
};
$user('boss', 'admin');
$user('mgr-a', 'staff', [['sales', $idA, 'manager'], ['social', $idB, 'editor']]);
$user('view-a', 'staff', [['sales', $idA, 'viewer']]);
$user('social-only', 'staff', [['social', $idA, 'approver']]);
$user('nobody', 'staff');
$user('newbie', 'staff', [['sales', $idA, 'manager']], 1);

// ---------------------------------------------------------------------
// A small web client
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    exit("Run from the command line only.\n");
}

// The web server runs on a temporary copy of public_html, so this test can
// pretend /social is or is not installed without ever touching a real
// public_html/social folder. app, config and storage are linked, not copied.
$repo = dirname(__DIR__);
$site = sys_get_temp_dir() . '/aisa-access-http-' . getmypid();
$root = $site . '/public_html';
$copy = static function (string $from, string $to) use (&$copy): void {
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..' || ($from === dirname(__DIR__) . '/public_html' && $f === 'social')) {
            continue;
        }
        is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
    }
};
$copy($repo . '/public_html', $root);
foreach (['app', 'config', 'storage', 'tests'] as $link) {
    symlink($repo . '/' . $link, $site . '/' . $link);
}
$wipe = static function (string $dir) use (&$wipe): void {
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = "$dir/$f";
        is_link($path) || !is_dir($path) ? unlink($path) : $wipe($path);
    }
    rmdir($dir);
};

$port = 8200 + random_int(0, 500);
$log  = sys_get_temp_dir() . '/aisa-access-http-' . $port . '.log';
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
register_shutdown_function(static function () use ($server, $site, $wipe): void {
    proc_terminate($server);
    $wipe($site);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$base = 'http://127.0.0.1:' . $port;

final class Browser
{
    private string $jar;
    public int $status = 0;
    public string $location = '';
    public string $body = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields, ?string $csrfFrom = null): self
    {
        $page = $this->get($csrfFrom ?? $path)->body;
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $fields['_csrf'] = $m[1] ?? '';
        return $this->request('POST', $path, $fields);
    }

    public function login(string $name, ?string $next = null): self
    {
        $path = '/admin/login.php' . ($next !== null ? '?next=' . rawurlencode($next) : '');
        return $this->post($path, ['username' => $name, 'password' => 'Pass-' . $name . '-2026']);
    }

    /** The path a redirect points at, whatever host the configured address names. */
    public function goesTo(): string
    {
        return (string)parse_url($this->location, PHP_URL_PATH) . (parse_url($this->location, PHP_URL_QUERY) ? '?' . parse_url($this->location, PHP_URL_QUERY) : '');
    }

    private function request(string $method, string $path, array $fields = []): self
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw = (string)curl_exec($ch);
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        $this->location = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '';
        return $this;
    }
}

$installSocial = static function () use ($root): void {
    @mkdir($root . '/social');
    file_put_contents($root . '/social/index.php', "<?php echo 'social placeholder';");
};
$removeSocial = static function () use ($root): void {
    @unlink($root . '/social/index.php');
    @rmdir($root . '/social');
};

// =====================================================================
T::group('Signing in and being sent to the right place');
// =====================================================================
$b = (new Browser($base))->get('/admin/login.php');
T::ok(str_contains($b->body, '<h1>Sign in</h1>') && str_contains($b->body, 'AI Sales Agent · AI Social Media Expert Agent'),
    'The sign-in page is neutral and names both platforms');

$boss = new Browser($base);
$boss->login('boss');
T::equals('/admin/', $boss->goesTo(), 'A super admin lands on the sales agent');
$boss->get('/admin/index.php');
T::ok($boss->status === 200 && str_contains($boss->body, 'Bravo Expo') && str_contains($boss->body, 'Alpha Expo'),
    'And may switch between every exhibition');
T::ok(!str_contains($boss->body, 'platform-switch'), 'No platform switch while /social is not installed');

$installSocial();
$boss->get('/admin/index.php');
T::ok(str_contains($boss->body, '<nav class="platform-switch" aria-label="Platform"><a href="') &&
      str_contains($boss->body, '" aria-current="page">AI Sales Agent</a><a href="') &&
      str_contains($boss->body, '/social/">AI Social Media Expert Agent</a></nav><h1>'),
    'Once /social is installed, the switch shows at the start of the top bar, sales highlighted');

$soc = (new Browser($base))->login('social-only');
T::equals('/social/', $soc->goesTo(), 'Someone with /social only is sent there after signing in');
$soc->get('/admin/leads.php');
T::ok($soc->status === 302 && $soc->goesTo() === '/social/', 'And from any sales agent page');
$soc->get('/admin/account.php');
T::equals(200, $soc->status, 'But can still open their own account');
$removeSocial();
$soc->get('/admin/');
T::ok($soc->status === 403 && str_contains($soc->body, 'not installed yet'), 'Before /social exists they are told so plainly');

$mgr = (new Browser($base))->login('mgr-a');
$mgr->get('/admin/index.php');
T::ok(!str_contains($mgr->body, 'platform-switch'), 'No switch for staff while /social is not installed');
$installSocial();
$mgr->get('/admin/index.php');
T::ok(str_contains($mgr->body, 'platform-switch'), 'Staff with both platforms see the switch once it is');
$removeSocial();

$nob = (new Browser($base))->login('nobody');
$nob->get('/admin/');
T::ok($nob->status === 403 && str_contains($nob->body, 'no access yet'), 'An account with nothing given is told it has no access yet');

$nx = (new Browser($base))->login('boss', '/social/plan?x=1');
T::equals('/social/plan?x=1', $nx->goesTo(), 'login.php?next=/social/... returns there after signing in');
$nx2 = (new Browser($base))->login('boss', '//evil.example/x');
T::equals('/admin/', $nx2->goesTo(), 'But never to another site');

// =====================================================================
T::group('A new account chooses its own password first');
// =====================================================================
$new = (new Browser($base))->login('newbie');
T::ok(str_starts_with($new->goesTo(), '/admin/account.php'), 'Signing in with a given password goes to Your account');
$new->get('/admin/leads.php');
T::ok($new->status === 302 && str_starts_with($new->goesTo(), '/admin/account.php'), 'And nothing else opens until it is changed');
$new->post('/admin/account.php', ['current_password' => 'Pass-newbie-2026', 'new_password' => 'short', 'new_password_again' => 'short']);
T::ok(str_contains($new->body, 'at least 12 characters'), 'A short password is refused');
$new->post('/admin/account.php', ['current_password' => 'Pass-newbie-2026', 'new_password' => 'a long new phrase 2026',
    'new_password_again' => 'a long new phrase 2026']);
T::equals('/admin/', $new->goesTo(), 'After choosing one they go on to their platform');
T::equals(0, (int)db()->value("SELECT must_change_password FROM admin_users WHERE username = 'newbie'"), 'And are not asked again');
$new->get('/admin/leads.php');
T::equals(200, $new->status, 'Pages open normally afterwards');

// =====================================================================
T::group('Staff on Alpha cannot see Bravo — by typing addresses');
// =====================================================================
$mgr->get('/admin/index.php');
T::ok(str_contains($mgr->body, 'Alpha Expo') && !str_contains($mgr->body, 'Bravo Expo'), 'Only their own exhibition is listed');
$mgr->get('/admin/index.php?agent=' . $idB);
T::ok(str_contains($mgr->body, 'Alpha Expo') && !str_contains($mgr->body, 'Bravo Expo'), '?agent= for another exhibition is refused');
$_check = [
    'lead.php?id=' . $leadB                       => 'Boris Bravoski',
    'lead.php?agent=' . $idB . '&id=' . $leadB   => 'Boris Bravoski',
    'conversation.php?id=' . (int)$convB['id']    => 'Boris',
    'person.php?lead=' . $leadB                   => 'Boris Bravoski',
    'person.php?q=Boris'                          => 'Boris Bravoski',
    'companies.php?view=' . $companyB             => 'Bravo Cranes Secret',
    'companies.php'                               => 'Bravo Cranes Secret',
    'knowledge.php?edit=' . $articleB             => 'Bravo private article',
    'knowledge.php'                               => 'Bravo private article',
    'suppression.php'                             => 'blocked@bravo.example',
    'activity.php'                                => 'BRAVO-SECRET-AUDIT',
    'leads.php'                                   => 'Boris Bravoski',
    'leads.php?export=csv'                        => 'Boris Bravoski',
    'conversations.php'                           => 'Boris',
    'analytics.php'                               => 'Bravo Expo',
];
foreach ($_check as $path => $secret) {
    $mgr->get('/admin/' . $path);
    T::ok(!str_contains($mgr->body, $secret), 'Nothing of Bravo on ' . strtok($path, '?'), $path . ' → ' . $mgr->status);
}
$mgr->get('/admin/leads.php?export=csv');
T::ok(str_contains($mgr->body, 'Alice Alphason'), 'While their own records are there');
$mgr->get('/admin/activity.php');
T::ok(str_contains($mgr->body, 'ALPHA-VISIBLE-AUDIT'), 'Including their own activity');

foreach (['settings.php', 'agents.php', 'users.php', 'upgrade.php', 'outbound.php', 'agents.php?edit=' . $idB] as $path) {
    $mgr->get('/admin/' . $path);
    T::equals(403, $mgr->status, 'Super-admin page closed to staff: ' . $path);
}
$mgr->get('/admin/registrations.php');
T::ok($mgr->status === 200 && !str_contains($mgr->body, 'name="db_pass"') && !str_contains($mgr->body, 'name="events"'),
    'Registrations opens, without the connection or event numbers');

// =====================================================================
T::group('Staff on Alpha cannot change Bravo — by submitting forms');
// =====================================================================
$mgr->post('/admin/lead.php?agent=' . $idB . '&id=' . $leadB, ['action' => 'status', 'status' => 'converted'], '/admin/leads.php');
T::equals('new', (string)db()->value('SELECT status FROM leads WHERE id = ?', [$leadB]), 'A Bravo lead\'s status cannot be changed');
$mgr->post('/admin/lead.php?id=' . $leadB, ['action' => 'note', 'internal_note' => 'HACKED'], '/admin/leads.php');
T::ok((string)db()->value('SELECT internal_note FROM leads WHERE id = ?', [$leadB]) !== 'HACKED', 'Nor its note');
$mgr->post('/admin/knowledge.php', ['action' => 'save', 'id' => $articleB, 'title' => 'HACKED', 'body' => 'x', 'category' => 'x'], '/admin/knowledge.php?new=1');
T::equals('Bravo private article', (string)db()->value('SELECT title FROM knowledge_articles WHERE id = ?', [$articleB]),
    'A Bravo knowledge article cannot be edited');
$mgr->post('/admin/knowledge.php', ['action' => 'archive', 'id' => $articleB], '/admin/knowledge.php');
T::equals('approved', (string)db()->value('SELECT status FROM knowledge_articles WHERE id = ?', [$articleB]), 'Nor archived');
$mgr->post('/admin/suppression.php', ['action' => 'remove', 'id' => $suppB], '/admin/suppression.php');
T::equals(1, (int)db()->value('SELECT COUNT(*) FROM suppression_list WHERE id = ?', [$suppB]), 'A Bravo do-not-contact entry cannot be removed');
$mgr->post('/admin/companies.php', ['action' => 'do_not_contact', 'id' => $companyB, 'reason' => 'x'], '/admin/companies.php');
T::equals('active', (string)db()->value('SELECT status FROM companies WHERE id = ?', [$companyB]), 'A Bravo company cannot be blocked');
$mgr->post('/admin/registrations.php', ['action' => 'save_events', 'events' => '99'], '/admin/suppression.php');
T::ok($mgr->status === 403 && RegistrationSource::eventIds($idA) === [24], 'Event numbers cannot be changed by staff', $mgr->status . ' ' . json_encode(RegistrationSource::eventIds($idA)));
$mgr->post('/admin/outbound.php', ['action' => 'stop', 'note' => 'x'], '/admin/whatsapp.php');
T::ok($mgr->status === 403 && !OutboundSwitch::isStopped(), 'The emergency stop is the super admin\'s alone');
$before = (int)db()->value('SELECT COUNT(*) FROM admin_users');
$mgr->post('/admin/users.php', ['action' => 'save', 'username' => 'sneaky', 'email' => 'sneaky@example.net', 'kind' => 'admin'], '/admin/index.php');
T::ok($mgr->status === 403 && (int)db()->value('SELECT COUNT(*) FROM admin_users') === $before, 'Staff cannot create users');
$mgr->post('/admin/settings.php', ['action' => 'save'], '/admin/index.php');
T::equals(403, $mgr->status, 'Nor change platform settings');

// =====================================================================
T::group('A viewer reads and never changes');
// =====================================================================
$view = (new Browser($base))->login('view-a');
foreach (['index.php', 'leads.php', 'conversations.php', 'analytics.php', 'lead.php?id=' . $leadA, 'person.php'] as $path) {
    $view->get('/admin/' . $path);
    T::equals(200, $view->status, 'A viewer can read ' . strtok($path, '?'));
}
foreach (['knowledge.php', 'whatsapp.php', 'prospects.php', 'review.php', 'companies.php', 'registrations.php', 'activity.php'] as $path) {
    $view->get('/admin/' . $path);
    T::equals(403, $view->status, 'But not open ' . $path);
}
$view->get('/admin/index.php');
T::ok(!str_contains($view->body, 'knowledge.php') && !str_contains($view->body, 'whatsapp.php') && str_contains($view->body, 'leads.php'),
    'And the menu shows only what they can open');
$view->post('/admin/lead.php?id=' . $leadA, ['action' => 'status', 'status' => 'converted']);
T::ok($view->status === 403 && (string)db()->value('SELECT status FROM leads WHERE id = ?', [$leadA]) === 'new',
    'A viewer\'s form changes nothing, even on their own exhibition');

// =====================================================================
T::group('The Users screen');
// =====================================================================
$boss->post('/admin/users.php', [
    'action' => 'save', 'id' => 0, 'username' => 'fresh-staff', 'email' => 'fresh@example.net', 'full_name' => 'Fresh Staff', 'kind' => 'staff',
    'platform' => ['sales' => '1', 'social' => '1'],
    'access' => ['sales' => [$idA => 'viewer', $idB => ''], 'social' => [$idB => 'approver', $idA => 'bogus-role']],
], '/admin/users.php?new=1');
$freshId = (int)db()->value("SELECT id FROM admin_users WHERE username = 'fresh-staff'");
T::ok($freshId > 0 && (string)db()->value('SELECT role FROM admin_users WHERE id = ?', [$freshId]) === 'staff', 'The super admin creates a staff user');
$rows = db()->all('SELECT platform, agent_id, role FROM admin_user_access WHERE user_id = ? ORDER BY platform', [$freshId]);
T::equals([['platform' => 'sales', 'agent_id' => (string)$idA, 'role' => 'viewer'], ['platform' => 'social', 'agent_id' => (string)$idB, 'role' => 'approver']],
    array_map(static fn($r) => ['platform' => $r['platform'], 'agent_id' => (string)$r['agent_id'], 'role' => $r['role']], $rows),
    'Exactly the platforms, exhibitions and roles ticked are saved; an unknown role is not');
$boss->get('/admin/users.php?edit=' . $freshId);
T::ok(preg_match('#<code style="font-size:15px">([A-Za-z0-9-]{14})</code>#', $boss->body, $pw) === 1, 'A temporary password is shown once');
T::equals(1, (int)db()->value('SELECT must_change_password FROM admin_users WHERE id = ?', [$freshId]), 'And must be changed at first sign-in');
$boss->get('/admin/users.php?edit=' . $freshId);
T::ok(!str_contains($boss->body, $pw[1] ?? 'none'), 'It is not shown a second time');

$boss->post('/admin/users.php', ['action' => 'save', 'id' => $freshId, 'email' => 'fresh@example.net', 'kind' => 'staff',
    'platform' => ['social' => '1'], 'access' => ['sales' => [$idA => 'manager'], 'social' => [$idB => 'editor']]], '/admin/users.php?edit=' . $freshId);
T::equals(['social'], array_column(db()->all('SELECT platform FROM admin_user_access WHERE user_id = ?', [$freshId]), 'platform'),
    'Unticking a platform removes all its exhibitions');

$mgrId = (int)db()->value("SELECT id FROM admin_users WHERE username = 'mgr-a'");
$boss->post('/admin/users.php', ['action' => 'active', 'id' => $mgrId], '/admin/users.php?edit=' . $mgrId);
$mgr->get('/admin/leads.php');
T::ok($mgr->status === 302 && str_starts_with($mgr->goesTo(), '/admin/login.php'), 'Deactivating someone signs them out at their next click');
$boss->post('/admin/users.php', ['action' => 'active', 'id' => $mgrId, 'active' => '1'], '/admin/users.php?edit=' . $mgrId);

$bossId = (int)db()->value("SELECT id FROM admin_users WHERE username = 'boss'");
$boss->post('/admin/users.php', ['action' => 'active', 'id' => $bossId], '/admin/users.php?edit=' . $bossId);
T::equals(1, (int)db()->value('SELECT active FROM admin_users WHERE id = ?', [$bossId]), 'A super admin cannot deactivate themselves');
$boss->post('/admin/users.php', ['action' => 'save', 'id' => $bossId, 'email' => 'boss@example.net', 'kind' => 'staff'], '/admin/users.php?edit=' . $bossId);
T::equals('admin', (string)db()->value('SELECT role FROM admin_users WHERE id = ?', [$bossId]), 'Nor make themselves staff');

$boss->post('/admin/users.php', ['action' => 'temp_password', 'id' => $mgrId], '/admin/users.php?edit=' . $mgrId);
T::equals(1, (int)db()->value('SELECT must_change_password FROM admin_users WHERE id = ?', [$mgrId]), 'A temporary password can be given to someone who forgot theirs');

$boss->get('/admin/logout.php');
$boss->get('/admin/index.php');
T::ok($boss->status === 302 && str_starts_with($boss->goesTo(), '/admin/login.php'), 'Signing out signs out of both platforms (one session)');

$removeSocial();
exit(T::summary());
