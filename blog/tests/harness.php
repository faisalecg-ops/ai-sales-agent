<?php
/**
 * Event Blog — test harness. Not part of the deployed package.
 *
 * Uses its own database and table prefix (never a live installation) and a
 * local PHP server for the page and API tests.
 *   Database: blogtest (user blog / blogpass on localhost), prefix bt_
 */
declare(strict_types=1);

define('BLOG_TESTING', true);
const TEST_PORT = 8097;
const TEST_BASE = 'http://127.0.0.1:' . TEST_PORT . '/blog';

$GLOBALS['BLOG_CONFIG_OVERRIDE'] = [
    'db' => ['host' => 'localhost', 'name' => getenv('BLOG_TEST_DB') ?: 'blogtest', 'user' => 'blog', 'pass' => 'blogpass', 'port' => 3306],
    'prefix' => 'bt_', 'base_url' => TEST_BASE, 'app_key' => str_repeat('ab', 32), 'debug' => false, 'api_allow_http' => true,
];
require dirname(__DIR__) . '/core/bootstrap.php';

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $failures = [];
    private static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(bool $cond, string $label): void
    {
        if ($cond) {
            self::$pass++;
            echo "  \033[32mPASS\033[0m  " . $label . "\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$section . ': ' . $label;
            echo "  \033[31mFAIL\033[0m  " . $label . "\n";
        }
    }

    public static function eq(mixed $a, mixed $b, string $label): void
    {
        self::ok($a === $b, $label . ($a === $b ? '' : ' (got ' . var_export($a, true) . ', expected ' . var_export($b, true) . ')'));
    }

    public static function has(string $haystack, string $needle, string $label): void
    {
        self::ok(str_contains($haystack, $needle), $label);
    }

    public static function lacks(string $haystack, string $needle, string $label): void
    {
        self::ok(!str_contains($haystack, $needle), $label);
    }

    public static function summary(): int
    {
        echo "\n" . str_repeat('-', 62) . "\nPassed: " . self::$pass . "   Failed: " . self::$fail . "\n";
        foreach (self::$failures as $f) {
            echo "  - " . $f . "\n";
        }
        return self::$fail === 0 ? 0 : 1;
    }
}

/** Empty every blog table and restore default settings. */
function reset_blog(): void
{
    $db = db();
    if (!$db->tableExists('settings')) {
        Schema::install();
    }
    Schema::upgrade();   // an older test database gets the new columns
    $db->raw()->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'bt\\_%'") as $r) {
        $db->raw()->query('TRUNCATE TABLE `' . $r['t'] . '`');
    }
    $db->raw()->query('SET FOREIGN_KEY_CHECKS = 1');
    Settings::flush();
    Settings::setMany(['schema_version' => Schema::VERSION, 'site_url' => TEST_BASE, 'event_name' => 'Build Asia', 'site_name' => 'Build Asia Blog',
        'webhook_secret' => 'testsecret']);
    LinkPolicy::forget();
    Media::forget();
    Cache::clear();
    Auth::actAs(null);
    Webhook::$transport = null;
    Event::$fetcher = null;
    Mailer::$transport = static fn() => true;
    LinkCheck::$checker = static fn() => ['status' => 200, 'error' => ''];
}

function make_user(string $role, string $name = 'Test User'): array
{
    static $n = 0;
    $n++;
    $id = db()->insert('users', ['name' => $name . ' ' . $n, 'email' => $role . $n . '@example.com', 'password_hash' => password_hash('Password123', PASSWORD_DEFAULT),
        'role' => $role, 'slug' => slugify($name . ' ' . $n)]);
    return db()->one('SELECT * FROM {p}users WHERE id = ?', [$id]);
}

function make_category(string $name): int
{
    return db()->insert('categories', ['name' => $name, 'slug' => slugify($name)]);
}

function test_image(string $name = 'photo', int $w = 1600, int $h = 1000): int
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 40, 80, 120));
    $f = BLOG_STORAGE . '/tmp/' . $name . '.jpg';
    imagejpeg($im, $f, 85);
    $res = Media::store($f, $name . '.jpg', null);
    @unlink($f);
    return (int)$res['id'];
}

/** A published article. */
function publish_article(string $title, int $catId, array $extra = []): array
{
    $id = Article::save(null, array_merge(['title' => $title, 'body' => '<p>' . str_repeat('Construction industry text. ', 20) . '</p>', 'category_id' => $catId], $extra), $extra['tags'] ?? []);
    Article::transition($id, 'published');
    return Article::find($id);
}

// ---------------------------------------------------------------------
// HTTP against a local server
// ---------------------------------------------------------------------

function start_server(): void
{
    $router = __DIR__ . '/router.php';
    $cmd = sprintf('php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!', TEST_PORT, escapeshellarg($router), escapeshellarg(BLOG_STORAGE . '/tmp/test-server.log'));
    $pid = (int)shell_exec($cmd);
    register_shutdown_function(static function () use ($pid) {
        if ($pid > 0) {
            @posix_kill($pid, SIGTERM);
        }
    });
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', TEST_PORT);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('test server did not start');
}

/**
 * @return array{status:int, body:string, headers:array, location:?string}
 */
function http(string $method, string $path, array $opt = []): array
{
    $url = str_starts_with($path, 'http') ? $path : TEST_BASE . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => $opt['ua'] ?? 'Mozilla/5.0 (Test browser)']);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['form']);
    } elseif (isset($opt['raw'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['raw']);
    }
    if (isset($opt['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['jar']);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsize);
    $loc = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : null;
    return ['status' => $status, 'body' => substr($raw, $hsize), 'headers' => $head, 'location' => $loc];
}

function csrf_from(string $html): string
{
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}
