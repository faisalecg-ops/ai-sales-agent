<?php
declare(strict_types=1);

/**
 * Installer.
 *
 * Place the application on the server, complete config/config.php, then open
 * /install/ in a browser.
 *
 * This is the one part of the setup that has to live inside public_html,
 * because it is a web page the administrator visits. Delete the folder once
 * setup is finished — the installer also refuses to run a second time, once
 * an active administrator account exists.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

Security::htmlHeaders();
Security::startSession('aisa_install');

$step    = (string)($_GET['step'] ?? 'checks');
$errors  = [];
$notices = [];

// ---------------------------------------------------------------------
// Refuse to run once the platform is set up
// ---------------------------------------------------------------------
// Setup runs in stages, and the administrator account is created before the
// first agent. So the presence of an account cannot on its own mean setup is
// finished — treating it that way would lock the operator out of the very next
// step. Setup is complete only once there is both an account and an agent.
$hasAdmin = false;
$hasAgent = false;
try {
    $hasAdmin = (int)db()->value('SELECT COUNT(*) FROM admin_users WHERE active = 1') > 0;
    $hasAgent = (int)db()->value("SELECT COUNT(*) FROM agents WHERE status <> 'deleted'") > 0;
} catch (Throwable) {
    // The tables do not exist yet, which is the normal starting state.
    $hasAdmin = false;
    $hasAgent = false;
}

$setupComplete = $hasAdmin && $hasAgent;

if ($setupComplete && $step !== 'done') {
    http_response_code(403);
    render(
        'Already installed',
        '<p>This platform is already set up, so the installer will not run again.</p>'
        . '<div class="alert alert-warn"><p>Delete the <code>public_html/install</code> folder '
        . 'from the server now. It is the only part of the application that sits inside your '
        . 'public web folder.</p></div>'
        . '<p><a class="btn" href="' . e(url('admin/')) . '">Go to the admin panel</a></p>'
    );
    exit;
}

// An account already exists, so the steps that create one are closed off —
// only the remaining setup may continue.
if ($hasAdmin && in_array($step, ['checks', 'schema', 'account'], true)) {
    header('Location: ' . url('install/?step=agent'));
    exit;
}

// ---------------------------------------------------------------------
// Environment checks
// ---------------------------------------------------------------------
function environmentChecks(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP version 8.0 or newer',
        'ok'    => PHP_VERSION_ID >= 80000,
        'detail' => PHP_VERSION,
    ];
    foreach (['mysqli' => 'Database access', 'curl' => 'Outbound connections', 'mbstring' => 'Text handling', 'openssl' => 'Encryption', 'json' => 'Data format'] as $ext => $label) {
        $checks[] = [
            'label'  => $label . ' (' . $ext . ')',
            'ok'     => extension_loaded($ext),
            'detail' => extension_loaded($ext) ? 'Available' : 'Not installed',
        ];
    }

    $checks[] = [
        'label'  => 'Encryption key set in config.php',
        'ok'     => Crypto::isConfigured(),
        'detail' => Crypto::isConfigured() ? 'Set' : 'Missing or too short — run the command shown below',
    ];

    $storageWritable = is_dir(STORAGE_PATH) && is_writable(STORAGE_PATH);
    $checks[] = [
        'label'  => 'Storage folder writable',
        'ok'     => $storageWritable,
        'detail' => STORAGE_PATH,
    ];

    $configOutside = !str_starts_with(realpath(CONFIG_PATH) ?: '', realpath(APP_ROOT . '/public_html') ?: '###');
    $checks[] = [
        'label'  => 'Configuration is outside the public folder',
        'ok'     => $configOutside,
        'detail' => $configOutside ? 'Correct' : 'config/ is reachable over the web — move it above public_html',
    ];

    $dbOk = false;
    $dbDetail = '';
    try {
        db()->value('SELECT 1');
        $dbOk = true;
        $dbDetail = 'Connected to ' . (string)cfg('db.name');
    } catch (Throwable $ex) {
        $dbDetail = 'Could not connect — check the database settings in config.php';
    }
    $checks[] = ['label' => 'Database connection', 'ok' => $dbOk, 'detail' => $dbDetail];

    // Advisory rather than required. The chat widget will not send anything
    // over an unencrypted connection, so HTTPS must be working before the
    // agent goes live — but it should not block setting the platform up. Some
    // hosts terminate TLS at a proxy, where PHP cannot see it even though the
    // site is properly encrypted, and blocking here would leave those
    // installations with no way forward.
    $checks[] = [
        'label'    => 'Site served over HTTPS',
        'ok'       => Security::isHttps(),
        'required' => false,
        'detail'   => Security::isHttps()
            ? 'Yes'
            : 'Not detected. Set this up before the assistant goes live. If your site '
              . 'sits behind Cloudflare or a similar proxy, it may already be encrypted '
              . 'and simply not visible from here.',
    ];

    return $checks;
}

// ---------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------
if ($step === 'schema' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    try {
        $sql = file_get_contents(APP_PATH . '/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Could not read schema.sql');
        }
        $link = db()->raw();
        if ($link->multi_query($sql)) {
            do {
                if ($res = $link->store_result()) {
                    $res->free();
                }
            } while ($link->more_results() && $link->next_result());
        }
        if ($link->errno) {
            throw new RuntimeException($link->error);
        }

        // Anything added after the first release lives in Migrations rather
        // than in schema.sql, so there is one definition of each table and a
        // fresh install ends up identical to an upgraded one.
        $upgrade = Migrations::run('installer');
        if ($upgrade['failed'] !== null) {
            throw new RuntimeException($upgrade['failed'] . ': ' . (string)$upgrade['error']);
        }

        header('Location: ' . url('install/?step=account'));
        exit;
    } catch (Throwable $ex) {
        $errors[] = 'Could not create the tables: ' . $ex->getMessage();
    }
}

if ($step === 'account' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();

    $username = Validator::slug($_POST['username'] ?? null, 32);
    $email    = Validator::email($_POST['email'] ?? null);
    $fullName = Validator::text($_POST['full_name'] ?? null, 160);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($username === null) {
        $errors[] = 'Choose a username using lowercase letters, numbers or hyphens.';
    }
    if ($email === null) {
        $errors[] = 'Enter a valid email address.';
    }
    if (mb_strlen($password) < 12) {
        $errors[] = 'Use a password of at least 12 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        try {
            db()->insert('admin_users', [
                'username'      => $username,
                'email'         => $email,
                'full_name'     => $fullName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role'          => 'admin',
                'active'        => 1,
            ]);
            Audit::setActor('installer');
            Audit::log('admin_created', null, 'admin_user', db()->lastId(), 'Created during installation');

            // Sensible platform defaults.
            Settings::set('security.trust_proxy', '0');
            Settings::set('mail.transport', (string)cfg('mail_transport', 'mail'));
            Settings::set('mail.from_address', (string)cfg('mail_from', ''));
            Settings::set('mail.from_name', (string)cfg('mail_from_name', 'AI Sales Agent'));

            header('Location: ' . url('install/?step=agent'));
            exit;
        } catch (Throwable $ex) {
            $errors[] = 'Could not create the account: ' . $ex->getMessage();
        }
    }
}

if ($step === 'agent' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();

    $name    = Validator::text($_POST['name'] ?? null, 160);
    $slug    = Validator::slug($_POST['slug'] ?? null);
    $website = Validator::url($_POST['website'] ?? null);
    $domains = (string)($_POST['allowed_domains'] ?? '');

    if ($name === null) {
        $errors[] = 'Enter the exhibition name.';
    }
    if ($slug === null) {
        $errors[] = 'Enter a short code using lowercase letters and numbers, for example buildasia.';
    }

    $hosts = [];
    foreach (preg_split('/[\r\n,]+/', $domains) ?: [] as $line) {
        $h = Validator::hostname($line);
        if ($h !== null) {
            $hosts[] = $h;
        }
    }
    if (!$hosts) {
        $errors[] = 'Add at least one website address where the chat will appear.';
    }

    if (!$errors) {
        try {
            $agentId = db()->insert('agents', [
                'slug'            => $slug,
                'name'            => $name,
                'status'          => 'active',
                'website_url'     => $website,
                'allowed_domains' => implode("\n", array_unique($hosts)),
                'persona'         => AgentDefaults::PERSONA,
                'sales_objectives' => AgentDefaults::OBJECTIVES,
                'sales_rules'     => AgentDefaults::SALES_RULES,
                'ai_disclosure_text' => AgentDefaults::DISCLOSURE,
            ]);
            Fallbacks::seed($agentId);
            Audit::setActor('installer');
            Audit::log('agent_created', $agentId, 'agent', $agentId, 'Created during installation');

            header('Location: ' . url('install/?step=done'));
            exit;
        } catch (Throwable $ex) {
            $errors[] = 'Could not create the agent: ' . $ex->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------
function render(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' — AI Sales Agent Platform</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '">'
       . '</head><body class="install-page"><div class="install-shell">'
       . '<header class="install-head"><p class="install-eyebrow">AI Sales Agent Platform</p>'
       . '<h1>' . e($title) . '</h1></header><main class="card">' . $body . '</main></div></body></html>';
}

function errorList(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $out = '<div class="alert alert-error"><p>Fix the following before continuing:</p><ul>';
    foreach ($errors as $e) {
        $out .= '<li>' . e($e) . '</li>';
    }
    return $out . '</ul></div>';
}

$csrf = Security::csrfField();

if ($step === 'checks') {
    $checks = environmentChecks();

    // A check is required unless it says otherwise.
    $blocking = array_filter(
        $checks,
        static fn(array $c): bool => !$c['ok'] && ($c['required'] ?? true)
    );
    $advisory = array_filter(
        $checks,
        static fn(array $c): bool => !$c['ok'] && !($c['required'] ?? true)
    );
    $allOk = $blocking === [];

    $rows = '';
    foreach ($checks as $c) {
        if ($c['ok']) {
            $pill = '<span class="pill pill-ok">Ready</span>';
        } elseif (($c['required'] ?? true)) {
            $pill = '<span class="pill pill-bad">Must fix</span>';
        } else {
            $pill = '<span class="pill pill-warn">Recommended</span>';
        }
        $rows .= '<tr><td>' . e($c['label']) . '</td><td>' . $pill . '</td>'
               . '<td class="muted">' . e((string)$c['detail']) . '</td></tr>';
    }

    $keyHelp = Crypto::isConfigured() ? '' :
        '<div class="alert alert-warn"><p>Generate an encryption key and paste it into '
        . '<code>config/config.php</code>:</p><pre>php -r "echo bin2hex(random_bytes(32));"</pre></div>';

    if ($allOk) {
        $next = '';
        if ($advisory !== []) {
            $next .= '<div class="alert alert-warn"><p>You can continue, but the items marked '
                   . 'Recommended should be sorted out before the assistant is put in front of '
                   . 'visitors.</p></div>';
        }
        $next .= '<form method="post" action="' . e(url('install/?step=schema')) . '">' . $csrf
               . '<button class="btn" type="submit">Create the database tables</button></form>';
    } else {
        $next = '<p class="muted">Fix the items marked <strong>Must fix</strong>, then reload '
              . 'this page. Anything marked Recommended will not stop you continuing.</p>';
    }

    render('Set-up checks',
        '<p class="lede">These need to be in place before the platform can run.</p>'
        . $keyHelp
        . '<table class="table"><thead><tr><th>Requirement</th><th>Status</th><th>Detail</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>' . $next
    );
    exit;
}

if ($step === 'schema') {
    render('Create the database tables',
        errorList($errors)
        . '<form method="post" action="' . e(url('install/?step=schema')) . '">' . $csrf
        . '<button class="btn" type="submit">Create the tables</button></form>'
    );
    exit;
}

if ($step === 'account') {
    render('Create your administrator account',
        errorList($errors)
        . '<form method="post" action="' . e(url('install/?step=account')) . '" autocomplete="off">' . $csrf
        . '<div class="field"><label for="full_name">Your name</label>'
        . '<input id="full_name" name="full_name" value="' . e((string)($_POST['full_name'] ?? '')) . '"></div>'
        . '<div class="field"><label for="username">Username</label>'
        . '<input id="username" name="username" required value="' . e((string)($_POST['username'] ?? '')) . '">'
        . '<p class="hint">Lowercase letters, numbers and hyphens.</p></div>'
        . '<div class="field"><label for="email">Email address</label>'
        . '<input id="email" name="email" type="email" required value="' . e((string)($_POST['email'] ?? '')) . '"></div>'
        . '<div class="field"><label for="password">Password</label>'
        . '<input id="password" name="password" type="password" required minlength="12">'
        . '<p class="hint">At least 12 characters.</p></div>'
        . '<div class="field"><label for="password_confirm">Repeat password</label>'
        . '<input id="password_confirm" name="password_confirm" type="password" required></div>'
        . '<button class="btn" type="submit">Create account</button></form>'
    );
    exit;
}

if ($step === 'agent') {
    render('Set up your first agent',
        errorList($errors)
        . '<p class="lede">One agent serves one exhibition website. You can add more later.</p>'
        . '<form method="post" action="' . e(url('install/?step=agent')) . '">' . $csrf
        . '<div class="field"><label for="name">Exhibition name</label>'
        . '<input id="name" name="name" required placeholder="Build Asia" value="' . e((string)($_POST['name'] ?? '')) . '"></div>'
        . '<div class="field"><label for="slug">Short code</label>'
        . '<input id="slug" name="slug" required placeholder="buildasia" value="' . e((string)($_POST['slug'] ?? '')) . '">'
        . '<p class="hint">Used in the embed tag. Lowercase, no spaces.</p></div>'
        . '<div class="field"><label for="website">Website address</label>'
        . '<input id="website" name="website" placeholder="https://www.buildasia.net" value="' . e((string)($_POST['website'] ?? '')) . '"></div>'
        . '<div class="field"><label for="allowed_domains">Websites allowed to show the chat</label>'
        . '<textarea id="allowed_domains" name="allowed_domains" rows="4" placeholder="www.buildasia.net&#10;buildasia.net">' . e((string)($_POST['allowed_domains'] ?? '')) . '</textarea>'
        . '<p class="hint">One per line. Requests from anywhere else are refused.</p></div>'
        . '<button class="btn" type="submit">Create agent</button></form>'
    );
    exit;
}

if ($step === 'done') {
    render('Set-up complete',
        '<div class="alert alert-ok"><p>The platform is ready.</p></div>'
        . '<h2>What to do next</h2><ol class="steps">'
        . '<li>Delete the <code>public_html/install</code> folder from the server.</li>'
        . '<li>Sign in and add your AI provider key under Settings.</li>'
        . '<li>Add your knowledge, FAQs and pricing, then send them for review.</li>'
        . '<li>Paste the embed tag into your exhibition website.</li>'
        . '</ol>'
        . '<p><a class="btn" href="' . e(url('admin/')) . '">Sign in to the admin panel</a></p>'
    );
    exit;
}

header('Location: ' . url('install/?step=checks'));
