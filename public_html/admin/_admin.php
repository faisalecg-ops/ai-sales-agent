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
    return $user;
}

function admin_require_role(string $role): array
{
    $user = admin_require_login();
    if ($role === 'admin' && (string)$user['role'] !== 'admin') {
        admin_layout_start('Not permitted');
        echo '<div class="alert alert-error"><p>This page is for administrators only.</p></div>';
        admin_layout_end();
        exit;
    }
    return $user;
}

// ---------------------------------------------------------------------
// Which agent is being worked on
// ---------------------------------------------------------------------
function admin_agents(): array
{
    return db()->all("SELECT * FROM agents WHERE status <> 'deleted' ORDER BY name ASC");
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

    $requested = isset($_GET['agent']) ? (int)$_GET['agent'] : 0;
    if ($requested > 0) {
        $_SESSION['agent_id'] = $requested;
    }
    $agentId = (int)($_SESSION['agent_id'] ?? 0);

    $agent = $agentId > 0
        ? db()->one("SELECT * FROM agents WHERE id = ? AND status <> 'deleted'", [$agentId])
        : null;

    if (!$agent) {
        $agent = db()->one("SELECT * FROM agents WHERE status <> 'deleted' ORDER BY id ASC LIMIT 1");
        if ($agent) {
            $_SESSION['agent_id'] = (int)$agent['id'];
        }
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

    $nav = [
        'Day to day' => [
            'index.php'         => ['Dashboard', 0],
            'leads.php'         => ['Leads', $newLeads],
            'conversations.php' => ['Conversations', 0],
            'review.php'        => ['Review queue', $pending],
            'analytics.php'     => ['Analytics', 0],
        ],
        'Content' => [
            'import.php'    => ['Conversation with AI', 0],
            'knowledge.php' => ['Knowledge', 0],
            'faqs.php'      => ['FAQs', 0],
            'pricing.php'   => ['Stand pricing', 0],
            'sponsorship.php' => ['Sponsorship', 0],
        ],
        'Visitors' => [
            'person.php'        => ['Find a person', 0],
            'registrations.php' => ['Registrations', 0],
            'whatsapp.php'      => ['WhatsApp', $waWaiting],
        ],
        'Prospecting' => [
            'prospects.php' => ['Campaigns', $prospectWaiting],
            'companies.php' => ['Companies', 0],
            'research.php'  => ['Company research', 0],
        ],
        'Set-up' => [
            'agents.php'      => ['Agents and embed', 0],
            'messages.php'    => ['Standard messages', 0],
            'suppression.php' => ['Do not contact', 0],
            'activity.php'    => ['Activity log', 0],
            'settings.php'    => ['Settings', 0],
            'upgrade.php'     => ['Database upgrade', $upgradesDue],
        ],
    ];

    foreach ($nav as $group => $links) {
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
       . '<p class="muted small" style="margin:0 0 6px">Signed in as ' . e((string)($user['username'] ?? '')) . '</p>'
       . '<a href="' . e(url('admin/logout.php')) . '">Sign out</a>'
       . '</div></nav>';

    // ---- Main ----
    echo '<div class="main"><header class="topbar"><h1>' . e($title) . '</h1>';

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
