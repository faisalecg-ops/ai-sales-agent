<?php
declare(strict_types=1);

/**
 * Interface wording. Each language is one file in lang/ (lang/en.php now;
 * lang/ur.php or lang/zh.php can be added later without code changes).
 * A language file also says its text direction, so right-to-left
 * languages such as Urdu switch the whole layout automatically.
 */
final class Lang
{
    private static ?array $strings = null;
    private static ?array $meta = null;
    private static string $code = 'en';

    public static function load(string $code): void
    {
        $code = preg_replace('/[^a-z_-]/', '', strtolower($code)) ?: 'en';
        $file = BLOG_ROOT . '/lang/' . $code . '.php';
        if (!is_file($file)) {
            $code = 'en';
            $file = BLOG_ROOT . '/lang/en.php';
        }
        $data = require $file;
        $base = $code === 'en' ? $data : require BLOG_ROOT . '/lang/en.php';
        self::$code = $code;
        self::$meta = $data['_meta'] ?? ['name' => 'English', 'dir' => 'ltr', 'locale' => 'en'];
        self::$strings = array_merge($base, $data);
    }

    private static function ensure(): void
    {
        if (self::$strings === null) {
            $code = 'en';
            try {
                $code = (string)Settings::get('language', 'en');
            } catch (Throwable) {
            }
            self::load($code);
        }
    }

    public static function get(string $key): string
    {
        self::ensure();
        $v = self::$strings[$key] ?? $key;
        return is_string($v) ? $v : $key;
    }

    public static function code(): string
    {
        self::ensure();
        return self::$code;
    }

    public static function dir(): string
    {
        self::ensure();
        return (string)(self::$meta['dir'] ?? 'ltr');
    }

    public static function locale(): string
    {
        self::ensure();
        return (string)(self::$meta['locale'] ?? self::$code);
    }

    /** Languages available in lang/ — shown in the admin. */
    public static function available(): array
    {
        $out = [];
        foreach (glob(BLOG_ROOT . '/lang/*.php') ?: [] as $file) {
            $data = require $file;
            $out[basename($file, '.php')] = (string)($data['_meta']['name'] ?? basename($file, '.php'));
        }
        return $out;
    }
}
