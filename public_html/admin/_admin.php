<?php
declare(strict_types=1);

/**
 * Shared bootstrap for every admin page.
 *
 * Handles the session, the sign-in check, the page chrome and a few small
 * helpers so the individual pages stay short and readable.
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

Security::htmlHeaders();
Security::startSession('aisa_admin');

// ---------------------------------------------------------------------
// Who is signed in
// ---------------------------------------------------------------------
function admin_user(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $row = db()->one('SELECT * FROM admin_users WHERE id = ? AND active = 1 LIMIT 1', [$id]);
    if (!$row) {
        return null;
    }
    $cached = $row;
    Audit::setActor((string)$row['username']);
    return $cached;
}

function admin_require_login(): array
{
    $user = admin_user();
    if ($user === null) {
        $target = (string)($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . url('admin/login.php?next=' . rawurlencode($target)));
        exit;
    }

    // Your own account and signing out are open to everyone signed in, so a
    // new password can always be chosen and nobody is ever stuck.
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['account.php', 'logout.php'], true)) {
        return $user;
    }

    // A new account, or one given a temporary password, chooses its own
    // password before anything else.
    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header('Location: ' . url('admin/account.php?next=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? ''))));
        exit;
    }

    // One login serves two platforms. Someone with no access to this one is
    // sent to the other, or told plainly that they have no access yet.
    $platforms = Access::platforms((int)$user['id']);
    if (!$platforms['sales']) {
        if ($platforms['social'] && admin_social_installed()) {
            header('Location: ' . url('social/'));
            exit;
        }
        admin_no_access_page($platforms['social']
            ? 'Your account is for the AI Social Media Expert Agent, which is not installed yet.'
            : 'Your account has no access yet — ask the administrator.');
    }

    // Viewers read; they never change anything. Checked here, once, for every
    // form on every page, against the exhibition the page is working on.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !admin_is_super()) {
        $agent = admin_agent();
        if (Access::role((int)$user['id'], 'sales', (int)$agent['id']) !== 'manager') {
            admin_not_permitted('Your access to ' . (string)$agent['name'] . ' is read-only, so nothing was changed.');
        }
    }

    return $user;
}

/**
 * Pages only the super admin may open: platform settings and keys, users,
 * the database upgrade, the emergency stop, and creating or editing
 * exhibitions.
 */
function admin_require_role(string $role): array
{
    $user = admin_require_login();
    if ($role === 'admin' && !admin_is_super()) {
        admin_not_permitted('This page is for the super admin only.');
    }
    return $user;
}

/**
 * Pages that work on one exhibition. 'view' admits managers and viewers of
 * the current exhibition; 'manage' admits managers only. The super admin
 * always passes.
 */
function admin_require_access(string $level = 'view'): array
{
    $user = admin_require_login();
    if (!admin_can($level)) {
        $agent = admin_agent();
        admin_not_permitted($level === 'view'
            ? 'You do not have access to this page for ' . (string)$agent['name'] . '.'
            : 'Your access to ' . (string)$agent['name'] . ' does not include this page.');
    }
    return $user;
}

function admin_is_super(): bool
{
    $user = admin_user();
    return $user !== null && Access::isSuper((int)$user['id']);
}

/** Can the signed-in user do this on the current exhibition? 'view', 'manage' or 'super'. */
function admin_can(string $level): bool
{
    if (admin_is_super()) {
        return true;
    }
    if ($level === 'super') {
        return false;
    }
    $user  = admin_user();
    $agent = admin_agent(false);
    if ($user === null || $agent === null) {
        return false;
    }
    $role = Access::role((int)$user['id'], 'sales', (int)$agent['id']);
    return $level === 'view' ? in_array($role, ['manager', 'viewer'], true) : $role === 'manager';
}

/** Has the AI Social Media Expert Agent been installed beside this one? */
function admin_social_installed(): bool
{
    return is_file(dirname(__DIR__) . '/social/index.php');
}

function admin_not_permitted(string $message): never
{
    http_response_code(403);
    admin_layout_start('Not permitted');
    echo '<div class="alert alert-error"><p>' . e($message) . '</p></div>'
       . '<p><a class="btn btn-quiet" href="' . e(url('admin/')) . '">Back to the dashboard</a></p>';
    admin_layout_end();
    exit;
}

/** For an account with nothing it may open here. Deliberately outside the normal page chrome. */
function admin_no_access_page(string $message): never
{
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>No access yet</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css')) . '"></head>'
       . '<body class="signin-page"><div class="signin"><div class="card"><h1>No access yet</h1>'
       . '<p class="lede">' . e($message) . '</p>'
       . '<p><a class="btn btn-quiet" href="' . e(url('admin/logout.php')) . '">Sign out</a></p>'
       . '</div></div></body></html>';
    exit;
}

/**
 * The switch between the two platforms. Shown only to people who may use
 * both, and only once the second platform is installed. The markup is
 * shared, character for character, with /social.
 */
function admin_platform_switch(): string
{
    $user = admin_user();
    if ($user === null || !admin_social_installed()) {
        return '';
    }
    $p = Access::platforms((int)$user['id']);
    if (!$p['sales'] || !$p['social']) {
        return '';
    }
    return '<nav class="platform-switch" aria-label="Platform">'
         . '<a href="' . e(url('admin/')) . '" aria-current="page">AI Sales Agent</a>'
         . '<a href="' . e(url('social/')) . '">AI Social Media Expert Agent</a>'
         . '</nav>';
}

// ---------------------------------------------------------------------
// Which agent is being worked on
// ---------------------------------------------------------------------
function admin_agents(): array
{
    $user = admin_user();
    if ($user === null) {
        return [];
    }
    if (Access::isSuper((int)$user['id'])) {
        return db()->all("SELECT * FROM agents WHERE status <> 'deleted' ORDER BY name ASC");
    }
    $ids = Access::agentIds((int)$user['id'], 'sales');
    if (!$ids) {
        return [];
    }
    return db()->all(
        "SELECT * FROM agents WHERE status <> 'deleted' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
          ORDER BY name ASC",
        $ids
    );
}

/**
 * The agent in context. Remembered in the session so it survives navigation.
 */
function admin_agent(bool $required = true): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Staff may only ever be working on an exhibition they were given. The
    // session's exhibition is shared with /social; one not allowed here is
    // simply not used here, and the first allowed one is taken instead.
    $user    = admin_user();
    $super   = $user !== null && Access::isSuper((int)$user['id']);
    $allowed = $super ? [] : ($user !== null ? Access::agentIds((int)$user['id'], 'sales') : []);
    $mayOpen = static fn(int $id): bool => $id > 0 && ($super || in_array($id, $allowed, true));

    $requested = isset($_GET['agent']) ? (int)$_GET['agent'] : 0;
    if ($mayOpen($requested)) {
        $_SESSION['agent_id'] = $requested;
    }
    $agentId = (int)($_SESSION['agent_id'] ?? 0);

    $agent = $mayOpen($agentId)
        ? db()->one("SELECT * FROM agents WHERE id = ? AND status <> 'deleted'", [$agentId])
        : null;

    if (!$agent) {
        if ($super) {
            $agent = db()->one("SELECT * FROM agents WHERE status <> 'deleted' ORDER BY id ASC LIMIT 1");
        } elseif ($allowed) {
            $agent = db()->one("SELECT * FROM agents WHERE id = ? AND status <> 'deleted'", [$allowed[0]]);
        }
        if ($agent) {
            $_SESSION['agent_id'] = (int)$agent['id'];
        }
    }

    if (!$agent && $required && !$super) {
        admin_layout_start('No exhibitions');
        echo '<div class="card"><h2>You have no exhibitions on this platform</h2>'
           . '<p class="lede">Ask the administrator to give you access to an exhibition.</p></div>';
        admin_layout_end();
        exit;
    }

    if (!$agent && $required) {
        admin_layout_start('No agent yet');
        echo '<div class="card"><h2>No agent has been created</h2>'
           . '<p class="lede">An agent represents one exhibition website. Create one to begin.</p>'
           . '<a class="btn" href="' . e(url('admin/agents.php?new=1')) . '">Create an agent</a></div>';
        admin_layout_end();
        exit;
    }

    $cached = $agent;
    return $agent;
}

// ---------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------
function admin_flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'kind' => $kind];
}

/**
 * Warn when the panel is being browsed at a different address from the one
 * in config.php.
 *
 * This matters more than it sounds. Every link and form action is built from
 * the configured address, while the security policy the pages send only
 * permits forms to submit back to the address they were served from. If the
 * two disagree, the browser refuses every form in the panel — silently, with
 * nothing on screen to explain it. Buttons simply do nothing.
 *
 * It is a warning rather than a redirect, because guessing which of the two
 * addresses is the correct one is not this function's business.
 */
function admin_address_warning(): string
{
    $configured = (string)cfg('base_url', '');
    if ($configured === '') {
        return '';
    }

    $parts = parse_url($configured);
    if (!is_array($parts) || !isset($parts['host'])) {
        return '';
    }

    $wantScheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $wantHost   = strtolower((string)$parts['host']);
    $wantPort   = isset($parts['port']) ? (int)$parts['port'] : ($wantScheme === 'https' ? 443 : 80);

    $haveScheme = Security::isHttps() ? 'https' : 'http';
    $haveHostRaw = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($haveHostRaw === '') {
        return '';
    }

    $haveHost = $haveHostRaw;
    $havePort = $haveScheme === 'https' ? 443 : 80;
    if (str_contains($haveHostRaw, ':')) {
        [$haveHost, $portText] = explode(':', $haveHostRaw, 2);
        $havePort = (int)$portText;
    }

    if ($haveScheme === $wantScheme && $haveHost === $wantHost && $havePort === $wantPort) {
        return '';
    }

    $current = $haveScheme . '://' . $haveHostRaw;

    return '<div class="alert alert-error"><p><strong>This page is open at the wrong address.</strong> '
         . 'You are at <code>' . e($current) . '</code> but the platform is set up as <code>'
         . e($configured) . '</code>. Until they match, buttons and forms in this panel will do '
         . 'nothing when pressed, with no error shown. Either open the panel at '
         . '<a href="' . e(rtrim($configured, '/') . '/admin/') . '">' . e($configured) . '</a>, '
         . 'or change <code>base_url</code> in <code>config/config.php</code> to the address you '
         . 'actually use.</p></div>';
}

function admin_flash_render(): string
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $out = '';
    foreach ($items as $f) {
        $class = match ($f['kind']) {
            'error' => 'alert alert-error',
            'warn'  => 'alert alert-warn',
            default => 'alert alert-ok',
        };
        $out .= '<div class="' . $class . '"><p>' . e((string)$f['message']) . '</p></div>';
    }
    return $out;
}

function admin_redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ---------------------------------------------------------------------
// Page chrome
// ---------------------------------------------------------------------
/**
 * What is waiting for someone on the team, for one exhibition. Shown as
 * counts in the menu and as the "Waiting for you" row on the dashboard.
 */
function admin_waiting(int $agentId): array
{
    $out = ['leads' => 0, 'whatsapp' => 0, 'prospects' => 0];
    $out['leads'] = (int)db()->value(
        "SELECT COUNT(*) FROM leads WHERE agent_id = ? AND status IN ('new','contact_captured') AND merged_into_id IS NULL",
        [$agentId]
    );

    // Messages waiting for someone to approve them, ones that could not be
    // sent, and conversations a person has taken over. All three need someone
    // to act.
    if (Migrations::tableExists('whatsapp_outbox')) {
        try {
            $out['whatsapp'] = (int)db()->value(
                "SELECT COUNT(*) FROM whatsapp_outbox WHERE agent_id = ? AND status IN ('held','failed')",
                [$agentId]
            );
            if (Migrations::columnExists('conversations', 'automation_paused_at')) {
                $out['whatsapp'] += (int)db()->value(
                    "SELECT COUNT(*) FROM conversations
                      WHERE agent_id = ? AND channel = 'whatsapp' AND automation_paused_at IS NOT NULL",
                    [$agentId]
                );
            }
        } catch (Throwable) {
            $out['whatsapp'] = 0;
        }
    }

    if (Migrations::tableExists('outreach_emails')) {
        try {
            $out['prospects'] = (int)db()->value(
                "SELECT COUNT(*) FROM outreach_emails WHERE agent_id = ? AND status IN ('draft','failed')",
                [$agentId]
            );
        } catch (Throwable) {
            $out['prospects'] = 0;
        }
    }
    return $out;
}

function admin_layout_start(string $title, array $actions = []): void
{
    $user  = admin_user();
    $agent = null;
    try {
        $agent = admin_agent(false);
    } catch (Throwable) {
        $agent = null;
    }

    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pending = $agent ? ReviewWorkflow::pendingCount((int)$agent['id']) : 0;

    // An upgrade that nobody notices is an upgrade that never gets run, so it
    // carries a count in the menu until it is done.
    try {
        $upgradesDue = Migrations::outstandingCount();
    } catch (Throwable) {
        $upgradesDue = 0;
    }
    $waiting         = $agent ? admin_waiting((int)$agent['id']) : ['leads' => 0, 'whatsapp' => 0, 'prospects' => 0];
    $newLeads        = $waiting['leads'];
    $waWaiting       = $waiting['whatsapp'];
    $prospectWaiting = $waiting['prospects'];

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' — AI Sales Agent</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css') . '?v=' . (int)@filemtime(__DIR__ . '/../assets/admin.css')) . '">'
       // Served from this site, so the Content-Security-Policy allows it.
       // Inline handlers are blocked, so anything interactive lives there.
       . '<script src="' . e(url('assets/admin.js') . '?v=' . (int)@filemtime(__DIR__ . '/../assets/admin.js')) . '" defer></script>'
       . '</head><body><div class="shell">';

    // ---- Sidebar ----
    echo '<nav class="side"><div class="side-brand"><strong>AI Sales Agent</strong>'
       . '<span>' . e($agent ? (string)$agent['name'] : 'No agent') . '</span></div>';

    // Each page with the least access that may open it.
    $nav = [
        'Day to day' => [
            'index.php'         => ['Dashboard', 0, 'view'],
            'leads.php'         => ['Leads', $newLeads, 'view'],
            'conversations.php' => ['Conversations', 0, 'view'],
            'review.php'        => ['Review queue', $pending, 'manage'],
            'analytics.php'     => ['Analytics', 0, 'view'],
        ],
        'Content' => [
            'import.php'    => ['Conversation with AI', 0, 'manage'],
            'knowledge.php' => ['Knowledge', 0, 'manage'],
            'faqs.php'      => ['FAQs', 0, 'manage'],
            'pricing.php'   => ['Stand pricing', 0, 'manage'],
            'sponsorship.php' => ['Sponsorship', 0, 'manage'],
            'website.php'   => ['Website pages', 0, 'manage'],
        ],
        'Visitors' => [
            'person.php'        => ['Find a person', 0, 'view'],
            'registrations.php' => ['Registrations', 0, 'manage'],
            'whatsapp.php'      => ['WhatsApp', $waWaiting, 'manage'],
        ],
        'Prospecting' => [
            'prospects.php' => ['Campaigns', $prospectWaiting, 'manage'],
            'companies.php' => ['Companies', 0, 'manage'],
            'research.php'  => ['Company research', 0, 'manage'],
        ],
        'Set-up' => [
            'agents.php'      => ['Agents and embed', 0, 'super'],
            'messages.php'    => ['Standard messages', 0, 'manage'],
            'suppression.php' => ['Do not contact', 0, 'manage'],
            'activity.php'    => ['Activity log', 0, 'manage'],
            'users.php'       => ['Users', 0, 'super'],
            'settings.php'    => ['Settings', 0, 'super'],
            'upgrade.php'     => ['Database upgrade', $upgradesDue, 'super'],
        ],
    ];

    foreach ($nav as $group => $links) {
        $links = array_filter($links, static fn(array $l): bool => $user !== null && admin_can($l[2]));
        if (!$links) {
            continue;
        }
        echo '<div class="side-group"><p>' . e($group) . '</p>';
        foreach ($links as $file => [$label, $tally]) {
            $active = $current === $file ? ' class="is-active"' : '';
            echo '<a href="' . e(url('admin/' . $file)) . '"' . $active . '>'
               . '<span>' . e($label) . '</span>'
               . ($tally > 0 ? '<span class="tally">' . (int)$tally . '</span>' : '')
               . '</a>';
        }
        echo '</div>';
    }

    echo '<div class="side-foot">'
       . '<p class="muted small" style="margin:0 0 6px">Signed in as ' . e((string)($user['username'] ?? ''))
       . ($user !== null ? ' · ' . (admin_is_super() ? 'super admin' : 'staff') : '') . '</p>'
       . '<a href="' . e(url('admin/account.php')) . '">Your account</a>'
       . '<a href="' . e(url('admin/logout.php')) . '">Sign out</a>'
       . '</div></nav>';

    // ---- Main ----
    echo '<div class="main"><header class="topbar">' . admin_platform_switch() . '<h1>' . e($title) . '</h1>';

    $agents = admin_agents();
    if (count($agents) > 1) {
        // The switcher carries a real submit button. admin.js hides it and
        // switches on change instead; without that script the button is the
        // one that works, so the switcher is never a dead control.
        echo '<form method="get" class="agent-switch" style="margin:0">'
           . '<label class="sr-only" for="agent_switch">Exhibition</label>'
           . '<select name="agent" id="agent_switch" class="input" style="width:auto" data-autosubmit>';
        foreach ($agents as $a) {
            $sel = $agent && (int)$a['id'] === (int)$agent['id'] ? ' selected' : '';
            echo '<option value="' . (int)$a['id'] . '"' . $sel . '>' . e((string)$a['name']) . '</option>';
        }
        echo '</select>'
           . '<button class="btn btn-small" type="submit" data-autosubmit-fallback>Switch</button>'
           . '</form>';
    }

    foreach ($actions as $label => $href) {
        echo '<a class="btn btn-small" href="' . e($href) . '">' . e($label) . '</a>';
    }

    echo '</header><main class="content">' . admin_outbound_banner() . admin_address_warning() . admin_flash_render();
}

function admin_layout_end(): void
{
    echo '</main></div></div></body></html>';
}

// ---------------------------------------------------------------------
// Small form helpers
// ---------------------------------------------------------------------
function f_text(string $name, string $label, ?string $value, array $opts = []): string
{
    $type  = (string)($opts['type'] ?? 'text');
    $hint  = (string)($opts['hint'] ?? '');
    $extra = !empty($opts['required']) ? ' required' : '';
    $extra .= isset($opts['placeholder']) ? ' placeholder="' . e((string)$opts['placeholder']) . '"' : '';
    $extra .= isset($opts['step']) ? ' step="' . e((string)$opts['step']) . '"' : '';
    $extra .= !empty($opts['disabled']) ? ' disabled' : '';
    $extra .= isset($opts['list']) ? ' list="' . e((string)$opts['list']) . '"' : '';
    $extra .= isset($opts['autocomplete']) ? ' autocomplete="' . e((string)$opts['autocomplete']) . '"' : '';

    return '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label>'
         . '<input type="' . e($type) . '" id="f_' . e($name) . '" name="' . e($name) . '" '
         . 'value="' . e((string)$value) . '"' . $extra . '>'
         . ($hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '')
         . '</div>';
}

function f_area(string $name, string $label, ?string $value, array $opts = []): string
{
    $hint = (string)($opts['hint'] ?? '');
    $rows = (int)($opts['rows'] ?? 5);
    $cls  = !empty($opts['tall']) ? ' class="tall"' : '';
    return '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label>'
         . '<textarea id="f_' . e($name) . '" name="' . e($name) . '" rows="' . $rows . '"' . $cls . '>'
         . e((string)$value) . '</textarea>'
         . ($hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '')
         . '</div>';
}

function f_select(string $name, string $label, array $options, ?string $value, array $opts = []): string
{
    $hint = (string)($opts['hint'] ?? '');
    $out  = '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label>'
          . '<select id="f_' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $key => $text) {
        $sel = ((string)$key === (string)$value) ? ' selected' : '';
        $out .= '<option value="' . e((string)$key) . '"' . $sel . '>' . e((string)$text) . '</option>';
    }
    return $out . '</select>'
        . ($hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '')
        . '</div>';
}

function f_check(string $name, string $label, bool $checked, string $hint = ''): string
{
    return '<div class="check"><input type="checkbox" id="f_' . e($name) . '" name="' . e($name) . '" value="1"'
         . ($checked ? ' checked' : '') . '>'
         . '<label for="f_' . e($name) . '">' . e($label)
         . ($hint !== '' ? '<span class="hint" style="display:block">' . e($hint) . '</span>' : '')
         . '</label></div>';
}

// ---------------------------------------------------------------------
// Pages made of cards
// ---------------------------------------------------------------------
/** A card for one group of settings, with a title and one line saying what it is for. */
function admin_card_open(string $id, string $title, string $intro): string
{
    return '<section class="set-card" id="' . e($id) . '"><div class="set-head"><h2>' . e($title) . '</h2>'
         . '<p>' . e($intro) . '</p></div><div class="set-body">';
}

function admin_card_close(): string
{
    return '</div></section>';
}

/** Links to each card on a long page, so any part is one click away. */
function admin_page_nav(array $links): string
{
    $html = '<nav class="set-nav" aria-label="Sections of this page">';
    foreach ($links as $id => $label) {
        $html .= '<a href="#' . e((string)$id) . '">' . e((string)$label) . '</a>';
    }
    return $html . '</nav>';
}

/** Status pill used across the content pages. */
function status_pill(string $status): string
{
    [$class, $label] = match ($status) {
        'approved'       => ['pill-ok', 'Live'],
        'pending_review' => ['pill-warn', 'Awaiting review'],
        'draft'          => ['pill-info', 'Draft'],
        'archived'       => ['', 'Archived'],
        // Outbound messages.
        'held'           => ['pill-warn', 'Waiting for your approval'],
        'scheduled'      => ['pill-info', 'Waiting to go'],
        'sending'        => ['pill-info', 'Sending now'],
        'sent'           => ['pill-ok', 'Sent'],
        'failed'         => ['pill-warn', 'Could not be sent'],
        'cancelled'      => ['', 'Stopped'],
        default          => ['', $status],
    };
    return '<span class="pill ' . $class . '">' . e($label) . '</span>';
}

/**
 * The emergency stop, shown on every page while it is on.
 *
 * On every page because a stop nobody remembers is as bad as no stop: the
 * team must never wonder why nothing is going out.
 */
function admin_outbound_banner(): string
{
    try {
        $status = OutboundSwitch::status();
    } catch (Throwable) {
        return '';
    }
    if (!$status['stopped']) {
        return '';
    }

    if (!admin_is_super()) {
        return '<div class="alert alert-error" role="alert"><p style="margin:0"><strong>All outgoing messages are '
             . 'stopped.</strong> Nothing is being sent to visitors on any exhibition until the administrator '
             . 'resumes sending.</p></div>';
    }

    $here = ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $back = preg_match('#(admin/[a-z_]+\.php)$#', $here, $m) === 1 ? $m[1] : 'admin/index.php';

    return '<div class="alert alert-error" role="alert" style="display:flex;gap:12px;align-items:center;'
         . 'justify-content:space-between;flex-wrap:wrap">'
         . '<p style="margin:0"><strong>All outgoing messages are stopped.</strong> '
         . 'Nothing is being sent to visitors on any exhibition. Incoming messages are still recorded, '
         . 'and requests to stop are still obeyed. '
         . 'Stopped ' . e(admin_time($status['at'])) . ' by ' . e((string)$status['by'])
         . ($status['note'] ? ' — “' . e((string)$status['note']) . '”' : '') . '.</p>'
         . '<form method="post" action="' . e(url('admin/outbound.php')) . '" style="margin:0" '
         . 'data-confirm="Resume sending? Waiting messages will start going out within the next few minutes, '
         . 'within each exhibition\'s hours and limits.">'
         . Security::csrfField()
         . '<input type="hidden" name="action" value="resume">'
         . '<input type="hidden" name="return" value="' . e($back) . '">'
         . '<button class="btn btn-small" type="submit">Resume sending</button></form>'
         . '</div>';
}

/**
 * A time-of-day box, read rather than trusted.
 *
 * Browsers send "HH:MM" from a time input, but the box can be cleared, and on
 * an older browser it is a plain text field that will accept anything.
 */
function admin_time_of_day(mixed $value, string $default): string
{
    $text = trim((string)$value);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $text, $m) === 1) {
        return $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
    }
    return $default;
}

/** Dates shown in the team's own timezone. */
function admin_time(?string $utc, string $format = 'j M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $agent = admin_agent(false);
    $tzName = trim((string)($agent['notify_timezone'] ?? '')) ?: 'Asia/Karachi';
    try {
        $tz = new DateTimeZone($tzName);
        return (new DateTimeImmutable($utc . ' UTC'))->setTimezone($tz)->format($format);
    } catch (Throwable) {
        return (string)$utc;
    }
}

/** Build a query string preserving the current filters. */
function admin_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
}

/** The lead score label as a coloured pill: Hot, Warm, Nurture or Low. */
function lead_label_pill(?string $label, $score = null): string
{
    $text = Qualification::labelText($label);
    if ($text === '') {
        return '';
    }
    $class = match ($label) { 'hot' => 'pill-bad', 'warm' => 'pill-warn', 'nurture' => 'pill-info', default => '' };
    $title = $score !== null && $score !== '' ? ' title="Lead score ' . e((string)(float)$score) . ' of 100"' : '';
    return '<span class="pill ' . $class . '"' . $title . '>' . e($text) . '</span>';
}

/** The qualification record as table rows, with its completeness. */
function qualification_card(array $qual, $pct, ?string $label, $score): string
{
    $out = '<div class="card"><h2>Qualification</h2><p>' . lead_label_pill($label, $score)
         . ' <span class="muted small">' . (int)$pct . '% complete'
         . ($score !== null && $score !== '' ? ' · score ' . e((string)(float)$score) . ' of 100' : '') . '</span></p>'
         . '<table class="table"><tbody>';
    foreach (Qualification::FIELDS as $key => $name) {
        $value = Qualification::display($qual, $key);
        $out .= '<tr><td style="width:200px;color:var(--ink-soft)">' . e($name) . '</td><td>'
              . ($value !== '' ? e($value) . ($key === 'products_made' && !empty($qual['_from_research'])
                    ? ' <span class="muted small">(from research)</span>' : '')
                 : '<span class="muted">Not known yet</span>') . '</td></tr>';
    }
    return $out . '</tbody></table></div>';
}
