<?php
/**
 * Shared admin frame: sign-in check, menu, messages and form helpers.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

if (!blog_installed()) {
    header('Location: ../install/');
    exit;
}
define('BLOG_ADMIN', true);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

Auth::startSession();
Schema::upgrade();

/** Address of an admin stylesheet or script, with a version stamp. */
function admin_asset(string $file): string
{
    $abs = __DIR__ . '/assets/' . $file;
    return local_url('admin/assets/' . $file) . '?v=' . (is_file($abs) ? filemtime($abs) : BLOG_VERSION);
}

/** Address of an admin page. */
function aurl(string $page = 'dashboard', array $params = []): string
{
    $q = array_merge(['p' => $page], $params);
    return local_url('admin/') . '?' . http_build_query($q);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function require_perm(string $perm): void
{
    if (!Auth::can($perm)) {
        http_response_code(403);
        admin_render('Not allowed', '<div class="card"><p>Your role does not include this page. Ask an admin if you need access.</p></div>');
        exit;
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Stop any form that did not come from our own page. */
function post_guard(): void
{
    if (!Auth::checkCsrf()) {
        if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') || isset($_GET['ajax'])) {
            json_out(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        flash('err', 'Your session expired before the form was sent. Please try again.');
        redirect((string)($_SERVER['REQUEST_URI'] ?? aurl()));
    }
}

function back_to(string $page, array $params = []): never
{
    redirect(aurl($page, $params));
}

function in(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function in_str(string $key, int $max = 1000): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

// ----------------------------------------------------------------------
// Form helpers (escape everything)
// ----------------------------------------------------------------------

function f_text(string $name, string $label, mixed $value, string $help = '', array $attr = []): string
{
    $type = $attr['type'] ?? 'text';
    unset($attr['type']);
    $extra = '';
    foreach ($attr as $k => $v) {
        $extra .= ' ' . $k . ($v === true ? '' : '="' . e($v) . '"');
    }
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    return '<div class="field"><label for="' . $id . '">' . e($label) . '</label>'
        . ($help !== '' ? '<p class="help">' . $help . '</p>' : '')
        . '<input id="' . $id . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '></div>';
}

function f_area(string $name, string $label, mixed $value, string $help = '', int $rows = 3, array $attr = []): string
{
    $extra = '';
    foreach ($attr as $k => $v) {
        $extra .= ' ' . $k . ($v === true ? '' : '="' . e($v) . '"');
    }
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    return '<div class="field"><label for="' . $id . '">' . e($label) . '</label>'
        . ($help !== '' ? '<p class="help">' . $help . '</p>' : '')
        . '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . $rows . '"' . $extra . '>' . e($value) . '</textarea></div>';
}

function f_select(string $name, string $label, array $options, mixed $value, string $help = '', array $attr = []): string
{
    $extra = '';
    foreach ($attr as $k => $v) {
        $extra .= ' ' . $k . ($v === true ? '' : '="' . e($v) . '"');
    }
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    $h = '<div class="field"><label for="' . $id . '">' . e($label) . '</label>' . ($help !== '' ? '<p class="help">' . $help . '</p>' : '')
        . '<select id="' . $id . '" name="' . e($name) . '"' . $extra . '>';
    foreach ($options as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select></div>';
}

function f_check(string $name, string $label, bool $checked, string $help = ''): string
{
    return '<div class="field field--check"><label><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1"'
        . ($checked ? ' checked' : '') . '> <span>' . e($label) . '</span></label>' . ($help !== '' ? '<p class="help">' . $help . '</p>' : '') . '</div>';
}

/** An image chosen from the media library. */
function f_media(string $name, string $label, int $mediaId, string $help = ''): string
{
    $m = Media::find($mediaId);
    $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
    return '<div class="field"><label>' . e($label) . '</label>' . ($help !== '' ? '<p class="help">' . $help . '</p>' : '')
        . '<div class="mediafield" data-mediafield><input type="hidden" id="' . $id . '" name="' . e($name) . '" value="' . ($m ? (int)$m['id'] : '') . '">'
        . '<div class="mediafield__preview">' . ($m ? '<img src="' . e(Media::url($m, 400)) . '" alt="">' : '<span>No image</span>') . '</div>'
        . '<div class="mediafield__btns"><button type="button" class="btn btn--small" data-pick>Choose image</button>'
        . '<button type="button" class="btn btn--small btn--ghost" data-clear' . ($m ? '' : ' hidden') . '>Remove</button></div></div></div>';
}

function badge(string $status): string
{
    $label = Article::STATUSES[$status] ?? ucfirst($status);
    return '<span class="badge badge--' . e($status) . '">' . e($label) . '</span>';
}

function pager_links(int $page, int $pages, array $params): string
{
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav class="apager">';
    if ($page > 1) {
        $h .= '<a class="btn btn--small btn--ghost" href="' . e(aurl($params['p'], array_merge($params, ['page' => $page - 1]))) . '">← Newer</a>';
    }
    $h .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $h .= '<a class="btn btn--small btn--ghost" href="' . e(aurl($params['p'], array_merge($params, ['page' => $page + 1]))) . '">Older →</a>';
    }
    return $h . '</nav>';
}

/** The menu, grouped, showing only what the user's role may open. */
function admin_menu(): array
{
    $pending = 0;
    $comments = 0;
    $proposals = 0;
    try {
        $proposals = (int)db()->value("SELECT COUNT(*) FROM {p}submissions WHERE status = 'new'");
        $pending = (int)db()->value("SELECT COUNT(*) FROM {p}articles WHERE status = 'pending'");
        $comments = (int)db()->value("SELECT COUNT(*) FROM {p}comments WHERE status = 'pending'");
    } catch (Throwable) {
    }
    return [
        'Content' => [
            ['dashboard', 'Dashboard', 'articles.own', 0],
            ['articles', 'Articles', 'articles.own', $pending],
            ['media', 'Media library', 'media', 0],
            ['categories', 'Categories & tags', 'content', 0],
            ['comments', 'Comments', 'comments', $comments],
        ],
        'Industry Voices' => [
            ['contributors', 'Contributors', 'content', 0],
            ['submissions', 'Write for us', 'content', $proposals],
        ],
        'Home page & sponsors' => [
            ['home', 'Home page builder', 'content', 0],
            ['slides', 'Slider', 'content', 0],
            ['banners', 'Sponsor banners', 'sponsors', 0],
            ['sponsored', 'Sponsored articles', 'sponsors', 0],
        ],
        'Event & links' => [
            ['event', 'Event & courtesy', 'settings', 0],
            ['links', 'Link library', 'content', 0],
        ],
        'Look' => [
            ['branding', 'Branding', 'settings', 0],
            ['menus', 'Menus', 'settings', 0],
        ],
        'Settings' => [
            ['settings', 'Readers & general', 'settings', 0],
            ['starter', 'Starter content', 'settings', 0],
            ['seo', 'SEO & Google', 'settings', 0],
            ['chat', 'Sales agent chat', 'settings', 0],
            ['email', 'Email', 'settings', 0],
            ['subscribers', 'Subscribers', 'subscribers', 0],
            ['users', 'Users', 'users', 0],
            ['apikeys', 'API keys', 'api', 0],
            ['activity', 'Activity log', 'activity', 0],
        ],
    ];
}

function admin_render(string $title, string $body, string $active = '', array $opts = []): void
{
    $user = Auth::user();
    $flashes = take_flashes();
    $site = (string)Settings::get('site_name', 'Event Blog');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e($site) ?> admin</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= e(admin_asset('admin.css')) ?>">
<meta name="csrf" content="<?= e(Auth::csrfToken()) ?>">
</head><body class="<?= $user ? 'has-nav' : 'is-login' ?>">
<?php if ($user): ?>
<header class="atop">
  <button class="atop__burger" type="button" data-navtoggle aria-label="Menu">☰</button>
  <a class="atop__site" href="<?= e(aurl()) ?>"><?= e($site) ?></a>
  <span class="atop__space"></span>
  <a class="atop__link" href="<?= e(url()) ?>" target="_blank" rel="noopener">View blog ↗</a>
  <a class="atop__link" href="<?= e(aurl('profile')) ?>"><?= e($user['name']) ?> · <?= e(ucfirst((string)$user['role'])) ?></a>
  <a class="atop__link" href="<?= e(local_url('admin/logout.php')) ?>">Sign out</a>
</header>
<nav class="aside" id="anav" aria-label="Admin">
  <?php foreach (admin_menu() as $group => $items):
      $items = array_filter($items, static fn($i) => Auth::can($i[2]));
      if (!$items) continue; ?>
    <p class="aside__h"><?= e($group) ?></p>
    <ul>
      <?php foreach ($items as [$key, $label, , $count]): ?>
        <li><a href="<?= e(aurl($key)) ?>"<?= $active === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= e($label) ?><?php if ($count > 0): ?><span class="count"><?= $count ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
  <p class="aside__ver">Event Blog <?= e(BLOG_VERSION) ?></p>
</nav>
<?php endif; ?>
<main class="amain<?= !empty($opts['wide']) ? ' amain--wide' : '' ?>">
  <?php if ($user): ?><div class="ahead"><h1><?= e($title) ?></h1><?= $opts['actions'] ?? '' ?></div><?php endif; ?>
  <?php foreach ($flashes as [$type, $msg]): ?><div class="flash flash--<?= e($type) ?>" role="status"><?= e($msg) ?></div><?php endforeach; ?>
  <?= $body ?>
</main>
<?php if ($user) { require __DIR__ . '/partials/media_modal.php'; } ?>
<script src="<?= e(admin_asset('admin.js')) ?>" defer></script>
<?= $opts['scripts'] ?? '' ?>
</body></html><?php
}
