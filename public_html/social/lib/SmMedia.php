<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Each exhibition's pictures and videos, and the branded image builder.
 *
 * Files live in social/uploads/<exhibition>/<year>/<month>/ under random
 * names; nothing there can run as a program (uploads/.htaccess).
 */
final class SmMedia
{
    public const SHAPES = [
        'square'    => [1080, 1080, 'Square 1080×1080'],
        'portrait'  => [1080, 1350, 'Portrait 1080×1350 (Instagram)'],
        'landscape' => [1200, 630, 'Landscape 1200×630 (LinkedIn, Facebook, X)'],
        'cover'     => [1600, 900, 'Blog cover 1600×900'],
    ];

    /** Which shape suits each platform best. */
    public const PLATFORM_SHAPE = ['linkedin' => 'landscape', 'facebook' => 'square', 'instagram' => 'portrait', 'x' => 'landscape'];

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    public static function dir(): string
    {
        return SM_ROOT . '/uploads';
    }

    public static function url(array $m): string
    {
        return sm_url('uploads/' . $m['file']);
    }

    public static function path(array $m): string
    {
        return self::dir() . '/' . $m['file'];
    }

    public static function find(int $agentId, int $id): ?array
    {
        return $id > 0 ? db()->one('SELECT * FROM sm_media WHERE id = ? AND agent_id = ?', [$id, $agentId]) : null;
    }

    /** Save an uploaded file. @return array{ok:bool,id?:int,error?:string} */
    public static function upload(int $agentId, array $file, string $label, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['ok' => false, 'error' => match ((int)($file['error'] ?? 4)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_NO_FILE => 'Choose a file first.',
                default => 'The upload did not finish. Try again.',
            }];
        }
        $tmp = (string)$file['tmp_name'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (isset(self::VIDEO_TYPES[$mime])) {
            return self::storeFile($agentId, $tmp, $mime, $label, $userId);
        }
        if (!isset(self::IMAGE_TYPES[$mime])) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, WebP or GIF pictures and MP4, MOV or WebM videos can be added.'];
        }
        return self::store($agentId, (string)file_get_contents($tmp), $label, 'upload', $userId);
    }

    /** A video goes straight from the upload to the library, never through memory. */
    private static function storeFile(int $agentId, string $tmp, string $mime, string $label, int $userId): array
    {
        $bytes = (int)filesize($tmp);
        if ($bytes > 200 * 1048576) {
            return ['ok' => false, 'error' => 'Videos can be at most 200 MB.'];
        }
        $rel = $agentId . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . self::VIDEO_TYPES[$mime];
        $abs = self::dir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
            return ['ok' => false, 'error' => 'The uploads folder is not writable. Check its permissions (755).'];
        }
        if (!@move_uploaded_file($tmp, $abs) && !@rename($tmp, $abs)) {
            return ['ok' => false, 'error' => 'The file could not be saved. Check that the uploads folder is writable.'];
        }
        $id = db()->insert('sm_media', ['agent_id' => $agentId, 'kind' => 'video', 'origin' => 'upload', 'file' => $rel, 'mime' => $mime,
            'bytes' => $bytes, 'label' => mb_substr($label, 0, 255), 'created_by' => $userId]);
        return ['ok' => true, 'id' => $id];
    }

    /** Pictures too large to open safely on shared hosting (width × height). */
    public const MAX_PIXELS = 40_000_000;

    public static function canBuild(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext') && is_file(SM_ROOT . '/lib/fonts/archivo-black.ttf');
    }

    /** Open a library picture for building, or null when it is missing or too large. */
    private static function open(array $m): ?GdImage
    {
        $path = self::path($m);
        $size = @getimagesize($path);
        if (!$size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $im = @imagecreatefromstring((string)file_get_contents($path));
        return $im instanceof GdImage ? $im : null;
    }

    /** Save bytes as a new library item. */
    public static function store(int $agentId, string $bytes, string $label, string $origin, ?int $userId): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $isImage = isset(self::IMAGE_TYPES[$mime]);
        $isVideo = isset(self::VIDEO_TYPES[$mime]);
        if (!$isImage && !$isVideo) {
            return ['ok' => false, 'error' => 'Only JPEG, PNG, WebP or GIF pictures and MP4, MOV or WebM videos can be added.'];
        }
        if ($isImage && strlen($bytes) > 15 * 1048576) {
            return ['ok' => false, 'error' => 'Pictures can be at most 15 MB.'];
        }
        if ($isVideo && strlen($bytes) > 200 * 1048576) {
            return ['ok' => false, 'error' => 'Videos can be at most 200 MB.'];
        }
        $w = $h = null;
        if ($isImage) {
            $size = @getimagesizefromstring($bytes);
            if (!$size) {
                return ['ok' => false, 'error' => 'That picture could not be read.'];
            }
            [$w, $h] = $size;
            if ($w * $h > self::MAX_PIXELS) {
                return ['ok' => false, 'error' => 'That picture is too large (over 40 megapixels). Save a smaller copy and upload that.'];
            }
        }
        $rel = $agentId . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(10)) . '.' . ($isImage ? self::IMAGE_TYPES[$mime] : self::VIDEO_TYPES[$mime]);
        $abs = self::dir() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
            return ['ok' => false, 'error' => 'The uploads folder is not writable. Check its permissions (755).'];
        }
        if (@file_put_contents($abs, $bytes) === false) {
            return ['ok' => false, 'error' => 'The file could not be saved. Check that the uploads folder is writable.'];
        }
        $id = db()->insert('sm_media', [
            'agent_id' => $agentId, 'kind' => $isImage ? 'image' : 'video', 'origin' => $origin, 'file' => $rel, 'mime' => $mime,
            'width' => $w, 'height' => $h, 'bytes' => strlen($bytes), 'label' => mb_substr($label, 0, 255), 'created_by' => $userId,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $agentId, int $id): bool
    {
        $m = self::find($agentId, $id);
        if (!$m) {
            return false;
        }
        $inUse = (int)db()->value('SELECT COUNT(*) FROM sm_posts WHERE media_id = ? AND status IN (\'scheduled\',\'due\',\'approved\',\'in_review\',\'draft\')', [$id])
               + (int)db()->value('SELECT COUNT(*) FROM sm_articles WHERE cover_media_id = ? AND status NOT IN (\'published\',\'rejected\')', [$id]);
        if ($inUse > 0) {
            return false;
        }
        @unlink(self::path($m));
        db()->exec('DELETE FROM sm_media WHERE id = ?', [$id]);
        return true;
    }

    // -----------------------------------------------------------------
    // Branded image builder (PHP GD, no extra cost)
    // -----------------------------------------------------------------

    /**
     * Build a branded image: the photo (if any) behind, the event's colours,
     * its name and dates, the headline and the slanted band of the website.
     */
    /**
     * @param array{logo_id?:int, subline?:string} $extra exhibitor kits: their logo on a white tile, and "Stand 12 · Hall B"
     */
    public static function build(array $agent, string $headline, string $shape, ?int $photoId, ?int $userId, array $extra = []): array
    {
        if (!self::canBuild()) {
            return ['ok' => false, 'error' => 'Branded images need PHP\'s GD extension with FreeType on this server.'];
        }
        try {
            return self::draw($agent, $headline, $shape, $photoId, $userId, $extra);
        } catch (Throwable $e) {
            app_log('social', 'image build failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'The image could not be built. Try another photo.'];
        }
    }

    private static function draw(array $agent, string $headline, string $shape, ?int $photoId, ?int $userId, array $extra = []): array
    {
        $aid = (int)$agent['id'];
        [$W, $H] = self::SHAPES[$shape] ?? self::SHAPES['square'];
        $s = SmEvent::settings($aid);
        $f = SmEvent::facts($agent);
        $navy = self::hex($s['brand_primary'] ?? '#142A5C');
        $royal = self::hex($s['brand_secondary'] ?? '#1F4FBF');
        $amber = self::hex($s['brand_accent'] ?? '#F7B10B');

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$navy));

        $photo = $photoId ? self::find($aid, $photoId) : null;
        if ($photo && $photo['kind'] === 'image' && ($src = self::open($photo)) !== null) {
            // Cover-fit the photo, then darken it so the words always read.
            $sw = imagesx($src);
            $sh = imagesy($src);
            $scale = max($W / $sw, $H / $sh);
            $cw = (int)round($W / $scale);
            $ch = (int)round($H / $scale);
            imagecopyresampled($im, $src, 0, 0, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $W, $H, $cw, $ch);
            imagedestroy($src);
            for ($y = 0; $y < $H; $y++) {
                $a = (int)max(20, min(115, 115 - 95 * (1 - $y / $H) ** 1.6));
                imageline($im, 0, $y, $W, $y, imagecolorallocatealpha($im, $navy[0], $navy[1], $navy[2], 127 - $a));
            }
        } else {
            // No photo: the skyline motif of the blog's covers.
            $faint = imagecolorallocatealpha($im, 255, 255, 255, 112);
            $x = (int)($W * 0.55);
            $seed = crc32($headline);
            while ($x < $W) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                $bw = 50 + $seed % 70;
                $bh = (int)($H * (0.18 + ($seed % 40) / 100));
                imagefilledrectangle($im, $x, $H - (int)($H * 0.08) - $bh, $x + $bw, $H, $faint);
                $x += $bw + 14;
            }
        }

        $black = SM_ROOT . '/lib/fonts/archivo-black.ttf';
        $bold = SM_ROOT . '/lib/fonts/archivo-bold.ttf';
        $white = imagecolorallocate($im, 255, 255, 255);
        $amberC = imagecolorallocate($im, ...$amber);
        $pad = (int)round($W * 0.07);
        $unit = $W / 1080;

        // Event name and dates.
        $label = mb_strtoupper($f['name'] . ($f['dates'] !== '' ? '  ·  ' . $f['dates'] : ''));
        imagettftext($im, (int)(26 * $unit), 0, $pad, $pad + (int)(30 * $unit), $amberC, $bold, $label);

        // Exhibitor kits: where their logo tile goes, so the headline stays below it.
        $exLogo = !empty($extra['logo_id']) ? self::find($aid, (int)$extra['logo_id']) : null;
        $xi = $exLogo && $exLogo['kind'] === 'image' ? self::open($exLogo) : null;
        $topLimit = 0;
        if ($xi !== null) {
            $boxW = (int)($W * 0.42);
            $boxH = (int)(($shape === 'landscape' ? 95 : 150) * $unit);
            $sc = min($boxW / max(1, imagesx($xi)), $boxH / max(1, imagesy($xi)));
            $xlw = max(1, (int)(imagesx($xi) * $sc));
            $xlh = max(1, (int)(imagesy($xi) * $sc));
            $tp = (int)(14 * $unit);
            $ty = $pad + (int)(55 * $unit);
            $topLimit = $ty + $xlh + 2 * $tp + (int)(30 * $unit);
        }
        $subReserve = trim((string)($extra['subline'] ?? '')) !== '' ? (int)(40 * $unit * 1.9) : 0;

        // Headline, as large as fits.
        $headline = trim($headline) !== '' ? trim($headline) : $f['name'];
        $maxW = $W - 2 * $pad;
        $size = (int)(($shape === 'landscape' ? 58 : 76) * $unit);
        $lines = [];
        while ($size > 24) {
            $lines = self::wrap($headline, $black, $size, $maxW);
            $blockH = count($lines) * $size * 1.22;
            $fitsUnderLogo = $topLimit === 0 || ($H - $H * 0.13 - $subReserve - $blockH) >= $topLimit;
            if ($blockH <= $H * ($shape === 'landscape' ? 0.52 : 0.5) && count($lines) <= 5 && $fitsUnderLogo) {
                break;
            }
            $size -= 4;
        }
        $lineH = (int)($size * 1.22);
        $sub = trim((string)($extra['subline'] ?? ''));
        $subSize = (int)(40 * $unit);
        $y = (int)($H - $H * 0.13 - count($lines) * $lineH - ($sub !== '' ? (int)($subSize * 1.9) : 0));
        foreach ($lines as $ln) {
            $y += $lineH;
            imagettftext($im, $size, 0, $pad, $y, $white, $black, $ln);
        }

        if ($sub !== '') {
            imagettftext($im, $subSize, 0, $pad, $y + (int)($subSize * 1.7), $amberC, $black, $sub);
        }

        // Exhibitor kits: the exhibitor's own logo on a white tile, under the event name.
        if ($xi !== null) {
            imagefilledrectangle($im, $pad, $ty, $pad + $xlw + 2 * $tp, $ty + $xlh + 2 * $tp, imagecolorallocate($im, 255, 255, 255));
            imagecopyresampled($im, $xi, $pad + $tp, $ty + $tp, 0, 0, $xlw, $xlh, imagesx($xi), imagesy($xi));
            imagedestroy($xi);
        }

        // Slanted band along the foot, as on the website.
        $bandH = (int)max(18, $H * 0.045);
        $by = $H - $bandH;
        $slant = (int)($bandH * 0.9);
        $deep = imagecolorallocate($im, max(0, $navy[0] - 8), max(0, $navy[1] - 14), max(0, $navy[2] - 26));
        imagefilledpolygon($im, [0, $by, (int)($W * .22), $by, (int)($W * .22) - $slant, $H, 0, $H], $amberC);
        imagefilledpolygon($im, [(int)($W * .235), $by, (int)($W * .74), $by, (int)($W * .74) - $slant, $H, (int)($W * .235) - $slant, $H], imagecolorallocate($im, ...$royal));
        imagefilledpolygon($im, [(int)($W * .755), $by, $W, $by, $W, $H, (int)($W * .755) - $slant, $H], $deep);

        // The logo, top right, on a white tile so any logo reads.
        $logo = !empty($s['logo_media_id']) ? self::find($aid, (int)$s['logo_media_id']) : null;
        if ($logo && $logo['kind'] === 'image' && ($li = self::open($logo)) !== null) {
            $lh = (int)(90 * $unit);
            $lw = (int)round(imagesx($li) * $lh / max(1, imagesy($li)));
            $lw = min($lw, (int)($W * 0.32));
            $tile = imagecolorallocate($im, 255, 255, 255);
            $tx = $W - $pad - $lw - (int)(16 * $unit);
            imagefilledrectangle($im, $tx, $pad - (int)(16 * $unit), $W - $pad, $pad + $lh + (int)(16 * $unit), $tile);
            imagecopyresampled($im, $li, $tx + (int)(8 * $unit), $pad, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
            imagedestroy($li);
        }

        ob_start();
        imagejpeg($im, null, 88);
        $bytes = (string)ob_get_clean();
        imagedestroy($im);
        return self::store($aid, $bytes, 'Built: ' . mb_substr($headline, 0, 120) . ' (' . $shape . ')', 'built', $userId);
    }

    /** @return list<string> */
    private static function wrap(string $text, string $font, int $size, int $maxW): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            $box = imagettfbbox($size, 0, $font, $try);
            if (($box[2] - $box[0]) > $maxW && $line !== '') {
                $lines[] = $line;
                $line = $w;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    /** @return array{int,int,int} */
    private static function hex(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = '142A5C';
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
