<?php
declare(strict_types=1);

/**
 * The media library. Uploaded images are checked, turned the right way up,
 * and saved in several sizes, each as JPEG/PNG plus a smaller WebP copy,
 * using PHP's built-in GD (no extra software on the server). Pages then
 * load the smallest size that fits the screen.
 */
final class Media
{
    public const WIDTHS = [400, 800, 1200, 1800];
    public const MAX_BYTES = 12 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private static array $cache = [];

    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = db()->one('SELECT * FROM {p}media WHERE id = ?', [$id]);
        }
        return self::$cache[$id];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * Save an uploaded or received file.
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function store(string $tmpPath, string $originalName, ?int $userId = null, ?int $apiKeyId = null, array $meta = []): array
    {
        if (!is_file($tmpPath)) {
            return ['ok' => false, 'error' => 'The file did not arrive. Please try again.'];
        }
        $bytes = (int)filesize($tmpPath);
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Images must be smaller than 12 MB.'];
        }
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!isset(self::TYPES[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, WebP and GIF images can be uploaded.'];
        }
        $info = @getimagesize($tmpPath);
        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return ['ok' => false, 'error' => 'This file is not a readable image.'];
        }
        if ($info[0] * $info[1] > 40_000_000) {
            return ['ok' => false, 'error' => 'This image is too large in pixels (over 40 megapixels). Please resize it first.'];
        }

        $ext = self::TYPES[$mime];
        $dir = gmdate('Y') . '/' . gmdate('m');
        $abs = BLOG_UPLOADS . '/' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
            return ['ok' => false, 'error' => 'The uploads folder cannot be written. Check its permissions.'];
        }
        $base = slugify(pathinfo($originalName, PATHINFO_FILENAME), 60) ?: 'image';
        $name = $base;
        $i = 2;
        while (is_file($abs . '/' . $name . '.' . $ext)) {
            $name = $base . '-' . $i++;
        }
        $file = $dir . '/' . $name . '.' . $ext;
        if (!@copy($tmpPath, BLOG_UPLOADS . '/' . $file)) {
            return ['ok' => false, 'error' => 'The image could not be saved.'];
        }
        @chmod(BLOG_UPLOADS . '/' . $file, 0644);

        [$w, $h, $sizes] = self::makeSizes(BLOG_UPLOADS . '/' . $file, $mime, $dir, $name);

        $id = db()->insert('media', [
            'file'          => $file,
            'original_name' => mb_substr($originalName, 0, 190),
            'mime'          => $mime,
            'width'         => $w,
            'height'        => $h,
            'bytes'         => $bytes,
            'sizes'         => json_encode($sizes),
            'alt'           => mb_substr((string)($meta['alt'] ?? ''), 0, 255),
            'caption'       => mb_substr((string)($meta['caption'] ?? ''), 0, 500),
            'credit'        => mb_substr((string)($meta['credit'] ?? ''), 0, 255),
            'uploaded_by'   => $userId,
            'api_key_id'    => $apiKeyId,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{0:int,1:int,2:array} width, height and the sizes made */
    private static function makeSizes(string $path, string $mime, string $dir, string $name): array
    {
        $info = getimagesize($path);
        $w = (int)$info[0];
        $h = (int)$info[1];
        $sizes = [];
        if ($mime === 'image/gif' || !function_exists('imagecreatetruecolor')) {
            return [$w, $h, $sizes];   // animated GIFs are kept as they are
        }
        @ini_set('memory_limit', '256M');
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        if (!$src) {
            return [$w, $h, $sizes];
        }
        // Photos from phones carry their orientation separately.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $rot = match ((int)($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($rot !== 0) {
                $r = imagerotate($src, $rot, 0);
                if ($r) {
                    imagedestroy($src);
                    $src = $r;
                    $w = imagesx($src);
                    $h = imagesy($src);
                    imagejpeg($src, $path, 86);
                }
            }
        }
        $alpha = $mime === 'image/png' || $mime === 'image/webp';
        $outExt = $alpha ? 'png' : 'jpg';
        foreach (self::WIDTHS as $target) {
            if ($target >= $w && $target !== self::WIDTHS[0]) {
                continue;
            }
            $tw = min($target, $w);
            $th = (int)max(1, round($h * $tw / $w));
            $dst = imagecreatetruecolor($tw, $th);
            if ($alpha) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            $entry = [];
            $f = $dir . '/' . $name . '-' . $tw . '.' . $outExt;
            if ($alpha ? imagepng($dst, BLOG_UPLOADS . '/' . $f, 7) : imagejpeg($dst, BLOG_UPLOADS . '/' . $f, 82)) {
                imageinterlace($dst, true);
                $entry['f'] = $f;
            }
            if (function_exists('imagewebp')) {
                $wf = $dir . '/' . $name . '-' . $tw . '.webp';
                if (@imagewebp($dst, BLOG_UPLOADS . '/' . $wf, 78)) {
                    $entry['w'] = $wf;
                }
            }
            imagedestroy($dst);
            if ($entry) {
                $sizes[(string)$tw] = $entry + ['h' => $th];
            }
        }
        imagedestroy($src);
        return [$w, $h, $sizes];
    }

    public static function fileUrl(string $file): string
    {
        return url('uploads/' . implode('/', array_map('rawurlencode', explode('/', $file))));
    }

    /** Address of the best size for a given width (the original when no smaller copy exists). */
    public static function url(?array $m, int $width = 1200): string
    {
        if (!$m) {
            return '';
        }
        $sizes = json_decode((string)$m['sizes'], true) ?: [];
        ksort($sizes, SORT_NUMERIC);
        foreach ($sizes as $w => $s) {
            if ((int)$w >= $width && !empty($s['f'])) {
                return self::fileUrl($s['f']);
            }
        }
        $last = end($sizes);
        if ($last && !empty($last['f']) && (int)key($sizes) >= (int)$m['width'] * 0.9) {
            return self::fileUrl($last['f']);
        }
        return self::fileUrl((string)$m['file']);
    }

    /**
     * A responsive <picture>: WebP for browsers that support it, JPEG/PNG
     * otherwise, the right size for the screen, and lazy loading unless the
     * image is the first thing on the page.
     */
    public static function picture(?array $m, string $class = '', string $alt = '', string $sizes = '100vw', bool $eager = false): string
    {
        if (!$m) {
            return '';
        }
        $alt = $alt !== '' ? $alt : (string)$m['alt'];
        $list = json_decode((string)$m['sizes'], true) ?: [];
        ksort($list, SORT_NUMERIC);
        $webp = [];
        $std = [];
        foreach ($list as $w => $s) {
            if (!empty($s['w'])) {
                $webp[] = self::fileUrl($s['w']) . ' ' . $w . 'w';
            }
            if (!empty($s['f'])) {
                $std[] = self::fileUrl($s['f']) . ' ' . $w . 'w';
            }
        }
        $w = (int)$m['width'];
        $h = (int)$m['height'];
        $load = $eager ? 'fetchpriority="high" decoding="async"' : 'loading="lazy" decoding="async"';
        $html = '<picture>';
        if ($webp) {
            $html .= '<source type="image/webp" srcset="' . e(implode(', ', $webp)) . '" sizes="' . e($sizes) . '">';
        }
        $html .= '<img src="' . e(self::url($m, 1200)) . '"'
            . ($std ? ' srcset="' . e(implode(', ', $std)) . '" sizes="' . e($sizes) . '"' : '')
            . ($w ? ' width="' . $w . '" height="' . $h . '"' : '')
            . ' alt="' . e($alt) . '"' . ($class ? ' class="' . e($class) . '"' : '') . ' ' . $load . '>';
        return $html . '</picture>';
    }

    /** Delete a file and all its sizes (only when nothing uses it). */
    public static function delete(int $id): array
    {
        $m = self::find($id);
        if (!$m) {
            return ['ok' => false, 'error' => 'Not found.'];
        }
        $uses = self::usage($id);
        if ($uses) {
            return ['ok' => false, 'error' => 'This image is still used: ' . implode(', ', array_slice($uses, 0, 5)) . '.'];
        }
        $files = [(string)$m['file']];
        foreach (json_decode((string)$m['sizes'], true) ?: [] as $s) {
            foreach (['f', 'w'] as $k) {
                if (!empty($s[$k])) {
                    $files[] = $s[$k];
                }
            }
        }
        foreach ($files as $f) {
            $abs = realpath(BLOG_UPLOADS . '/' . $f);
            if ($abs && str_starts_with($abs, realpath(BLOG_UPLOADS) . DIRECTORY_SEPARATOR)) {
                @unlink($abs);
            }
        }
        db()->delete('media', 'id = ?', [$id]);
        unset(self::$cache[$id]);
        return ['ok' => true];
    }

    /** Where an image is used, in plain words. */
    public static function usage(int $id): array
    {
        $uses = [];
        foreach (db()->all('SELECT title FROM {p}articles WHERE image_id = ? OR share_image_id = ? OR body LIKE ?', [$id, $id, '%data-media-id="' . $id . '"%']) as $r) {
            $uses[] = 'article "' . $r['title'] . '"';
        }
        if (db()->value('SELECT COUNT(*) FROM {p}slides WHERE image_id = ?', [$id])) {
            $uses[] = 'a home page slide';
        }
        if (db()->value('SELECT COUNT(*) FROM {p}banners WHERE image_id = ? OR mobile_image_id = ?', [$id, $id])) {
            $uses[] = 'a sponsor banner';
        }
        foreach (['logo_light', 'logo_dark', 'logo_footer', 'favicon', 'share_image'] as $k) {
            if ((int)Settings::get($k, 0) === $id) {
                $uses[] = 'the site branding';
                break;
            }
        }
        if (db()->value('SELECT COUNT(*) FROM {p}users WHERE avatar_id = ?', [$id])) {
            $uses[] = 'an author photo';
        }
        return $uses;
    }
}
