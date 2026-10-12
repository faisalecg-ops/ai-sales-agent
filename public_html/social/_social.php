<?php
declare(strict_types=1);

/**
 * Loaded by every /social page.
 *
 * Signs people in through the sales agent's shared login (it never shows a
 * login form of its own), decides which exhibitions and which role they have
 * here through the sales agent's read-only Access helper, and draws the page
 * in the sales agent's look with both switchers on top.
 */

require_once __DIR__ . '/lib/boot.php';

// ---------------------------------------------------------------------
// Who is signed in (shared session with the sales agent)
// ---------------------------------------------------------------------
function sm_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = (int)($_SESSION['admin_id'] ?? 0);
    $user = $id > 0 ? db()->one('SELECT * FROM admin_users WHERE id = ? AND active = 1', [$id]) : null;
    return $user;
}

function sm_uid(): int
{
    return (int)(sm_user()['id'] ?? 0);
}

/** The page's own address, for returning after sign-in. */
function sm_here(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/social/');
    return str_starts_with($uri, '/') && !str_starts_with($uri, '//') ? $uri : '/social/';
}

/**
 * Sign-in, password change and platform access, as the sales agent's contract
 * describes. Never sends a signed-in person back to login.php (that would loop).
 */
function sm_require_login(): array
{
    Security::startSession('aisa_admin');
    Security::htmlHeaders();
    $user = sm_user();
    if ($user === null) {
        header('Location: ' . url('admin/login.php?next=' . rawurlencode(sm_here())));
        exit;
    }
    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header('Location: ' . url('admin/account.php?next=' . rawurlencode(sm_here())));
        exit;
    }
    if (!Access::platforms((int)$user['id'])['social']) {
        sm_no_access('Your account does not have access to the AI Social Media Expert Agent yet. Ask the administrator to give you access under Users.');
    }
    Audit::setActor((string)$user['username'], (int)$user['id']);
    return $user;
}

function sm_no_access(string $message): never
{
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>No access</title><link rel="stylesheet" href="' . e(url('assets/admin.css')) . '"></head>'
       . '<body class="signin-page"><div class="signin"><div class="card"><h1>No access yet</h1><p class="lede">' . e($message) . '</p>'
       . (Access::platforms(sm_uid())['sales'] ? '<p><a class="btn" href="' . e(url('admin/')) . '">Go to the AI Sales Agent</a></p>' : '')
       . '<p><a class="btn btn-quiet" href="' . e(url('admin/logout.php')) . '">Sign out</a></p></div></div></body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Which exhibition (shared with the sales agent through $_SESSION['agent_id'])
// ---------------------------------------------------------------------
/** @return list<array> exhibitions this person may use here */
function sm_agents(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $ids = Access::agentIds(sm_uid(), 'social');
    if (!$ids) {
        return $list = [];
    }
    $in = implode(',', array_map('intval', $ids));
    $rows = db()->all("SELECT * FROM agents WHERE id IN ($in) AND status <> 'deleted'");
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    $list = [];
    foreach ($ids as $id) {
        if (isset($byId[(int)$id])) {
            $list[] = $byId[(int)$id];
        }
    }
    return $list;
}

function sm_agent(): array
{
    static $agent = null;
    if ($agent !== null) {
        return $agent;
    }
    $allowed = sm_agents();
    if (!$allowed) {
        sm_no_access('You have no exhibitions on the AI Social Media Expert Agent yet. Ask the administrator.');
    }
    $byId = [];
    foreach ($allowed as $a) {
        $byId[(int)$a['id']] = $a;
    }
    $want = isset($_GET['agent']) ? (int)$_GET['agent'] : (int)($_SESSION['agent_id'] ?? 0);
    if (!isset($byId[$want])) {
        $want = (int)($_SESSION['agent_id'] ?? 0);
    }
    if (!isset($byId[$want])) {
        $want = (int)$allowed[0]['id'];
    }
    $_SESSION['agent_id'] = $want;
    SmEvent::row($want);
    return $agent = $byId[$want];
}

function sm_agent_id(): int
{
    return (int)sm_agent()['id'];
}

// ---------------------------------------------------------------------
// Roles on /social: admin > approver > editor > viewer
// ---------------------------------------------------------------------
function sm_role(): string
{
    return (string)(Access::role(sm_uid(), 'social', sm_agent_id()) ?? 'viewer');
}

function sm_can(string $level): bool
{
    $rank = ['viewer' => 0, 'editor' => 1, 'approver' => 2, 'admin' => 3];
    return ($rank[sm_role()] ?? 0) >= ($rank[$level] ?? 99);
}

function sm_is_super(): bool
{
    return Access::isSuper(sm_uid());
}

/**
 * Every page starts here. Viewers can never send a form; everyone else must
 * pass the CSRF check and hold the level the page asks for.
 */
function sm_page(string $level = 'viewer'): array
{
    $user = sm_require_login();
    sm_agent();
    if (!sm_can($level)) {
        sm_not_permitted('This page needs the ' . $level . ' role on ' . sm_agent()['name'] . '.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!sm_can('editor')) {
            sm_not_permitted('Viewers can read everything here but cannot make changes.');
        }
        Security::requireCsrf();
        if ((int)($_POST['_agent'] ?? 0) !== sm_agent_id()) {
            sm_flash('Nothing was changed: that page belonged to another exhibition (it was switched in another tab or window). Check the exhibition and try again.', 'error');
            sm_redirect('');
        }
    }
    return $user;
}

function sm_not_permitted(string $message): never
{
    http_response_code(403);
    sm_layout_start('Not permitted');
    echo '<div class="alert alert-error"><p>' . e($message) . '</p></div><p><a class="btn btn-quiet" href="' . e(sm_url('')) . '">Back to the dashboard</a></p>';
    sm_layout_end();
    exit;
}

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------
function sm_redirect(string $page, array $query = []): never
{
    header('Location: ' . sm_url($page, $query));
    exit;
}

function sm_flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['sm_flash'][] = [$kind, $message];
}

function sm_flash_render(): string
{
    $out = '';
    foreach ((array)($_SESSION['sm_flash'] ?? []) as [$kind, $msg]) {
        $out .= '<div class="alert alert-' . ($kind === 'error' ? 'error' : ($kind === 'warn' ? 'warn' : 'ok')) . '"><p style="margin:0">' . e((string)$msg) . '</p></div>';
    }
    unset($_SESSION['sm_flash']);
    return $out;
}

function sm_in(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** UTC database time shown in the exhibition's own time zone. */
function sm_time(?string $utc, string $format = 'j M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($utc . ' UTC'))->setTimezone(SmEvent::tz(sm_agent()))->format($format);
    } catch (Throwable) {
        return (string)$utc;
    }
}

/** A local date and time typed by a person, stored as UTC. */
function sm_local_to_utc(string $local): ?string
{
    $local = str_replace('T', ' ', trim($local));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local) !== 1) {
        return null;
    }
    try {
        return (new DateTimeImmutable($local, SmEvent::tz(sm_agent())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function sm_pill(string $status): string
{
    [$cls, $label] = match ($status) {
        'writing'   => ['pill-info', 'Being written'],
        'draft'     => ['pill-info', 'Draft'],
        'in_review' => ['pill-warn', 'Waiting for approval'],
        'approved'  => ['pill-ok', 'Approved'],
        'sent'      => ['pill-info', 'Sent to the blog'],
        'published' => ['pill-ok', 'Published'],
        'rejected'  => ['pill-bad', 'Sent back'],
        'failed'    => ['pill-bad', 'Failed'],
        'scheduled' => ['pill-info', 'Scheduled'],
        'due'       => ['pill-warn', 'Due — post it now'],
        'posted'    => ['pill-ok', 'Posted'],
        'cancelled' => ['', 'Cancelled'],
        'suggested' => ['pill-info', 'Suggested'],
        'picked'    => ['pill-ok', 'Picked'],
        'used'      => ['', 'Written'],
        'dismissed' => ['', 'Dismissed'],
        default     => ['', $status],
    };
    return '<span class="pill ' . $cls . '">' . e($label) . '</span>';
}

function f_text(string $name, string $label, ?string $value, array $opts = []): string
{
    $type = (string)($opts['type'] ?? 'text');
    $extra = (!empty($opts['required']) ? ' required' : '') . (!empty($opts['readonly']) ? ' readonly' : '');
    foreach (['placeholder', 'min', 'max', 'step', 'maxlength'] as $a) {
        if (isset($opts[$a])) {
            $extra .= ' ' . $a . '="' . e((string)$opts[$a]) . '"';
        }
    }
    return '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label>'
         . '<input type="' . e($type) . '" id="f_' . e($name) . '" name="' . e($name) . '" value="' . e((string)$value) . '"' . $extra . '>'
         . (!empty($opts['hint']) ? '<p class="hint">' . e((string)$opts['hint']) . '</p>' : '') . '</div>';
}

function f_area(string $name, string $label, ?string $value, array $opts = []): string
{
    return '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label>'
         . '<textarea id="f_' . e($name) . '" name="' . e($name) . '" rows="' . (int)($opts['rows'] ?? 5) . '"'
         . (!empty($opts['maxlength']) ? ' maxlength="' . (int)$opts['maxlength'] . '"' : '') . (!empty($opts['data']) ? ' ' . $opts['data'] : '') . '>' . e((string)$value) . '</textarea>'
         . (!empty($opts['hint']) ? '<p class="hint">' . e((string)$opts['hint']) . '</p>' : '') . '</div>';
}

function f_select(string $name, string $label, array $options, ?string $value, array $opts = []): string
{
    $out = '<div class="field"><label for="f_' . e($name) . '">' . e($label) . '</label><select id="f_' . e($name) . '" name="' . e($name) . '">';
    foreach ($options as $k => $text) {
        $out .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e((string)$text) . '</option>';
    }
    return $out . '</select>' . (!empty($opts['hint']) ? '<p class="hint">' . e((string)$opts['hint']) . '</p>' : '') . '</div>';
}

function f_check(string $name, string $label, bool $checked, string $hint = ''): string
{
    return '<div class="check"><input type="checkbox" id="f_' . e($name) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
         . '<label for="f_' . e($name) . '">' . e($label) . ($hint !== '' ? '<span class="hint" style="display:block">' . e($hint) . '</span>' : '') . '</label></div>';
}

/** CSRF token plus the exhibition the form belongs to (a form from another tab cannot land on the wrong one). */
function sm_csrf(): string
{
    return Security::csrfField() . '<input type="hidden" name="_agent" value="' . (int)sm_agent_id() . '">';
}

/** Hash of what a person approves, so an approval only covers the text they saw. */
function sm_version(string ...$parts): string
{
    return sha1(implode("\x1f", $parts));
}

/** A small form with one button (approve, cancel, …). */
function sm_button(string $page, array $fields, string $label, string $class = 'btn btn-small', string $confirm = ''): string
{
    $out = '<form method="post" action="' . e(sm_url($page)) . '" class="inline-form"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . sm_csrf();
    foreach ($fields as $k => $v) {
        $out .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    return $out . '<button class="' . e($class) . '" type="submit">' . e($label) . '</button></form>';
}

// ---------------------------------------------------------------------
// Page layout — the sales agent's look, plus /social's own small stylesheet
// ---------------------------------------------------------------------
function sm_platform_switch(): string
{
    $p = Access::platforms(sm_uid());
    if (!$p['sales'] || !$p['social']) {
        return '';
    }
    return '<nav class="platform-switch" aria-label="Platform">'
         . '<a href="' . e(url('admin/')) . '">AI Sales Agent</a>'
         . '<a href="' . e(url('social/')) . '" aria-current="page">AI Social Media Expert Agent</a>'
         . '</nav>';
}

function sm_layout_start(string $title, array $actions = []): void
{
    $user = sm_user();
    $agent = null;
    try {
        $agent = sm_agents() ? sm_agent() : null;
    } catch (Throwable) {
        $agent = null;
    }
    $aid = $agent ? (int)$agent['id'] : 0;
    $count = static fn(string $sql) => $aid ? (int)db()->value($sql, [$aid]) : 0;
    $waiting = $count("SELECT COUNT(*) FROM sm_articles WHERE agent_id = ? AND status = 'in_review'")
             + $count("SELECT COUNT(*) FROM sm_posts WHERE agent_id = ? AND status = 'in_review'");
    $due = $count("SELECT COUNT(*) FROM sm_posts WHERE agent_id = ? AND status IN ('due','failed')");
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $v = static fn(string $f) => (int)@filemtime($f);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' — AI Social Media Expert Agent</title>'
       . '<link rel="stylesheet" href="' . e(url('assets/admin.css') . '?v=' . $v(dirname(__DIR__) . '/assets/admin.css')) . '">'
       . '<link rel="stylesheet" href="' . e(sm_url('assets/social.css') . '?v=' . $v(SM_ROOT . '/assets/social.css')) . '">'
       . '<script src="' . e(url('assets/admin.js') . '?v=' . $v(dirname(__DIR__) . '/assets/admin.js')) . '" defer></script>'
       . '<script src="' . e(sm_url('assets/social.js') . '?v=' . $v(SM_ROOT . '/assets/social.js')) . '" defer></script>'
       . '</head><body class="sm"><div class="shell">';

    echo '<nav class="side"><div class="side-brand"><strong>AI Social Media Expert Agent</strong><span>' . e($agent ? (string)$agent['name'] : '') . '</span></div>';
    $nav = [
        'Day to day' => [
            'index.php'    => ['Dashboard', $due, 'viewer'],
            'plan.php'     => ['Content plan', 0, 'viewer'],
            'articles.php' => ['Blog articles', $waiting, 'viewer'],
            'posts.php'    => ['Social posts', 0, 'viewer'],
            'calendar.php' => ['Calendar', 0, 'viewer'],
            'exhibitors.php' => ['Exhibitors and kits', 0, 'viewer'],
        ],
        'Library' => [
            'media.php' => ['Pictures and videos', 0, 'viewer'],
        ],
        'Set-up' => [
            'settings.php'    => ['Event settings', 0, 'admin'],
            'connections.php' => ['Blog and platforms', 0, 'admin'],
            'activity.php'    => ['Activity log', 0, 'viewer'],
            'guide.php'       => ['How it works', 0, 'viewer'],
        ],
    ];
    foreach ($nav as $group => $links) {
        $links = array_filter($links, static fn($l) => $agent !== null && sm_can($l[2]));
        if (!$links) {
            continue;
        }
        echo '<div class="side-group"><p>' . e($group) . '</p>';
        foreach ($links as $file => [$label, $tally]) {
            echo '<a href="' . e(sm_url($file === 'index.php' ? '' : $file)) . '"' . ($current === $file ? ' class="is-active"' : '') . '><span>' . e($label) . '</span>'
               . ($tally > 0 ? '<span class="tally">' . (int)$tally . '</span>' : '') . '</a>';
        }
        echo '</div>';
    }
    echo '<div class="side-foot"><p class="muted small" style="margin:0 0 6px">Signed in as ' . e((string)($user['username'] ?? ''))
       . ($agent ? ' · ' . e(sm_is_super() ? 'super admin' : sm_role()) : '') . '</p>'
       . '<a href="' . e(url('admin/account.php?next=' . rawurlencode('/social/'))) . '">Your account</a>'
       . '<a href="' . e(url('admin/logout.php')) . '">Sign out</a></div></nav>';

    echo '<div class="main"><header class="topbar">' . sm_platform_switch() . '<h1>' . e($title) . '</h1>';
    $agents = sm_agents();
    if (count($agents) > 1) {
        echo '<form method="get" class="agent-switch" style="margin:0"><label class="sr-only" for="agent_switch">Exhibition</label>'
           . '<select name="agent" id="agent_switch" class="input" style="width:auto" data-autosubmit>';
        foreach ($agents as $a) {
            echo '<option value="' . (int)$a['id'] . '"' . ((int)$a['id'] === $aid ? ' selected' : '') . '>' . e((string)$a['name']) . '</option>';
        }
        echo '</select><button class="btn btn-small" type="submit" data-autosubmit-fallback>Switch</button></form>';
    }
    foreach ($actions as $label => $href) {
        echo '<a class="btn btn-small" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</header><main class="content">' . sm_flash_render();
}

function sm_layout_end(): void
{
    echo '</main></div></div></body></html>';
}
