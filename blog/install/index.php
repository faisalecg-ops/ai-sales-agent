<?php
/**
 * Event Blog — installer. Open https://your-site/blog/install/ once.
 * It checks the hosting, creates the database tables and the first admin,
 * writes config/config.php, and then refuses to run again.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if (blog_installed()) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><title>Installed</title><body style="font-family:system-ui;max-width:560px;margin:60px auto;padding:0 16px">'
        . '<h1>The blog is already installed</h1><p>For safety the installer does not run again. <a href="../admin/">Go to the admin</a>.</p>'
        . '<p>To reinstall, delete <code>config/config.php</code> first.</p>');
}

$checks = [
    'PHP 8.0 or newer'                 => version_compare(PHP_VERSION, '8.0.0', '>='),
    'MySQLi (database)'                => extension_loaded('mysqli'),
    'GD (image resizing)'              => extension_loaded('gd'),
    'mbstring (text)'                  => extension_loaded('mbstring'),
    'DOM (cleaning article text)'      => extension_loaded('dom'),
    'cURL (webhook, link check)'       => extension_loaded('curl'),
    'Sodium (encrypted passwords)'     => extension_loaded('sodium'),
    'fileinfo (checking uploads)'      => extension_loaded('fileinfo'),
    'config/ folder can be written'    => is_writable(BLOG_ROOT . '/config'),
    'storage/ folder can be written'   => is_writable(BLOG_STORAGE),
    'uploads/ folder can be written'   => is_writable(BLOG_UPLOADS),
];
$ready = !in_array(false, $checks, true);

$https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$guess = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/blog/install/index.php'))), '/');

$errors = [];
$v = array_merge([
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'prefix' => 'blog_',
    'site_url' => $guess, 'event_name' => '', 'admin_name' => '', 'admin_email' => '', 'admin_pass' => '',
    'categories' => "News\nMarket Insights\nTechnology\nEvents",
], array_map(static fn($x) => is_string($x) ? trim($x) : '', $_POST));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ready) {
    if ($v['db_name'] === '' || $v['db_user'] === '') {
        $errors[] = 'Enter the database name and user (from cPanel → MySQL Databases).';
    }
    if (!filter_var($v['site_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'The blog address must be a full address such as https://buildasia.net/blog';
    }
    if ($v['event_name'] === '') {
        $errors[] = 'Enter the exhibition name.';
    }
    if ($v['admin_name'] === '' || !filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter the first admin\'s name and a valid email.';
    }
    if ($p = Auth::passwordProblem((string)($_POST['admin_pass'] ?? ''))) {
        $errors[] = $p;
    }
    $prefix = Db::cleanPrefix($v['prefix']) ?: 'blog_';

    if (!$errors) {
        $GLOBALS['BLOG_CONFIG'] = [
            'db' => ['host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => (string)($_POST['db_pass'] ?? ''), 'port' => 3306],
            'prefix' => $prefix, 'base_url' => rtrim($v['site_url'], '/'), 'app_key' => bin2hex(random_bytes(32)), 'debug' => false,
        ];
        try {
            Db::reset();
            db();
        } catch (Throwable $ex) {
            $errors[] = 'Could not connect to the database. Check the name, user and password, and that the user is added to the database with ALL PRIVILEGES.';
        }
    }
    if (!$errors) {
        try {
            if (db()->tableExists('settings') && db()->value('SELECT COUNT(*) FROM {p}users')) {
                throw new RuntimeException('Tables with the prefix "' . $prefix . '" already exist in this database. Choose another prefix, or empty the database.');
            }
            Schema::install();
            Settings::setMany([
                'site_url' => rtrim($v['site_url'], '/'),
                'site_name' => $v['event_name'] . ' Blog',
                'event_name' => $v['event_name'],
                'organization_name' => $v['event_name'],
                'webhook_secret' => bin2hex(random_bytes(24)),
            ]);
            $sort = 0;
            foreach (preg_split('/\R/', $v['categories']) ?: [] as $name) {
                $name = trim($name);
                $slug = slugify($name);
                if ($name !== '' && $slug !== '' && !in_array($slug, Article::RESERVED, true)) {
                    db()->exec('INSERT IGNORE INTO {p}categories (name, slug, sort) VALUES (?, ?, ?)', [$name, $slug, $sort++]);
                }
            }
            $slug = slugify($v['admin_name']) ?: 'admin';
            db()->insert('users', ['name' => $v['admin_name'], 'email' => strtolower($v['admin_email']),
                'password_hash' => password_hash((string)$_POST['admin_pass'], PASSWORD_DEFAULT), 'role' => 'admin', 'slug' => $slug]);

            $config = "<?php\n// Event Blog configuration — written by the installer on " . gmdate('Y-m-d H:i') . " UTC.\n// Keep this file private. app_key protects passwords and API keys: never change it.\nreturn "
                . var_export($GLOBALS['BLOG_CONFIG'], true) . ";\n";
            if (@file_put_contents(BLOG_CONFIG_FILE, $config) === false) {
                throw new RuntimeException('The tables were created, but config/config.php could not be written. Check the folder permissions and try again with a new prefix.');
            }
            @chmod(BLOG_CONFIG_FILE, 0640);
            header('Location: ../admin/?installed=1');
            exit;
        } catch (Throwable $ex) {
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Installation failed: ' . $ex->getMessage();
        }
    }
}
$f = static fn(string $k) => htmlspecialchars((string)$v[$k], ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install the Event Blog</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f4f4f2;color:#141414;margin:0;padding:24px 16px;line-height:1.5}
.box{max-width:640px;margin:0 auto;background:#fff;border-top:4px solid #E3120B;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-family:Georgia,serif;margin:0 0 6px}h2{font-size:1rem;margin:26px 0 10px;text-transform:uppercase;letter-spacing:.06em;font-size:.8rem;border-top:1px solid #ddd;padding-top:14px}
label{display:block;margin:12px 0 4px;font-weight:600;font-size:.92rem}small{display:block;color:#666;font-weight:400}
input,textarea{width:100%;padding:10px;border:1px solid #bbb;font:inherit;box-sizing:border-box}
.row{display:grid;gap:12px;grid-template-columns:1fr 1fr}@media(max-width:560px){.row{grid-template-columns:1fr}}
button{margin-top:22px;background:#E3120B;color:#fff;border:0;padding:12px 22px;font-weight:700;font-size:1rem;cursor:pointer}
.err{background:#fde8e8;border-left:4px solid #c00;padding:10px 14px;margin:12px 0}
ul.checks{list-style:none;padding:0;margin:0;columns:2}@media(max-width:560px){ul.checks{columns:1}}
.ok:before{content:"✓ ";color:#16a34a;font-weight:700}.no:before{content:"✗ ";color:#c00;font-weight:700}.no{color:#c00}
</style></head><body><div class="box">
<h1>Install the Event Blog</h1>
<p>This takes about two minutes. You need the database details from cPanel → <strong>MySQL Databases</strong>.</p>

<h2>Hosting check</h2>
<ul class="checks"><?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'ok' : 'no' ?>"><?= htmlspecialchars($label) ?></li><?php endforeach; ?></ul>
<?php if (!$ready): ?><p class="err">Something above is missing. In cPanel, use <strong>Select PHP Version</strong> to choose PHP 8.1 or newer and tick the missing extension, or make the folder writable (permission 755), then reload this page.</p><?php endif; ?>

<?php foreach ($errors as $e): ?><p class="err"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>

<?php if ($ready): ?>
<form method="post" autocomplete="off">
  <h2>Database</h2>
  <div class="row">
    <div><label>Database name<input name="db_name" value="<?= $f('db_name') ?>" required></label></div>
    <div><label>Database user<input name="db_user" value="<?= $f('db_user') ?>" required></label></div>
  </div>
  <div class="row">
    <div><label>Database password<input name="db_pass" type="password"></label></div>
    <div><label>Database server<small>Almost always "localhost"</small><input name="db_host" value="<?= $f('db_host') ?>"></label></div>
  </div>
  <label>Table prefix<small>Lets the blog share a database with the main website safely. Keep "blog_" unless told otherwise.</small><input name="prefix" value="<?= $f('prefix') ?>"></label>

  <h2>The blog</h2>
  <label>Blog address<small>Detected automatically. Example: https://buildasia.net/blog</small><input name="site_url" value="<?= $f('site_url') ?>" required></label>
  <label>Exhibition name<small>Example: Build Asia</small><input name="event_name" value="<?= $f('event_name') ?>" required></label>
  <label>Starting categories<small>One per line. You can change them later.</small><textarea name="categories" rows="4"><?= $f('categories') ?></textarea></label>

  <h2>First admin account</h2>
  <div class="row">
    <div><label>Name<input name="admin_name" value="<?= $f('admin_name') ?>" required></label></div>
    <div><label>Email<input name="admin_email" type="email" value="<?= $f('admin_email') ?>" required></label></div>
  </div>
  <label>Password<small>At least 10 characters, with letters and numbers.</small><input name="admin_pass" type="password" required minlength="10"></label>
  <button type="submit">Install the blog</button>
</form>
<?php endif; ?>
</div></body></html>
