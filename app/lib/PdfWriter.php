<?php
declare(strict_types=1);

/**
 * A small PDF writer, with no outside library (the platform has no Composer).
 *
 * Enough for a business document on A4: text in Helvetica and Helvetica
 * Bold (built into every PDF reader, so nothing is embedded), lines, filled
 * boxes, and PNG or JPEG pictures. Positions are in points, measured from the
 * TOP left corner of the page, because that is how a page is read.
 *
 * Text is written in Windows-1252, which covers English and the accented
 * letters of western European names. A character outside it (Chinese, Urdu,
 * Cyrillic) cannot be drawn by the built-in fonts and is left out; latin()
 * says whether anything was lost, so the caller can react.
 */
final class PdfWriter
{
    public const W = 595.28;   // A4
    public const H = 841.89;

    /** @var list<string> one content stream per page */
    private array $pages = [''];
    /** @var array<string,array{id:int,w:int,h:int,obj:string,smask:?string}> */
    private array $images = [];
    private string $title = '';

    /** Widths of characters 32 to 126, in 1/1000 of the font size. */
    private const REGULAR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,
        667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,
        500,722,500,500,500,334,260,334,584];
    private const BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,
        333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,
        667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,
        556,778,556,556,500,389,280,389,584];

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * Text as the built-in fonts can draw it (Windows-1252).
     *
     * @return array{0:string,1:bool} the bytes, and whether any character was lost
     */
    public static function latin(string $text): array
    {
        $text = strtr($text, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ', "\t" => ' ']);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        $out = '';
        $lost = false;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($ch) === 1) {
                $out .= $ch;
                continue;
            }
            $b = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//IGNORE', $ch) : false;
            if ($b === false || $b === '') {
                $b = function_exists('mb_convert_encoding') ? (string)@mb_convert_encoding($ch, 'Windows-1252', 'UTF-8') : '';
                if ($b === '?' || $b === '') {
                    $b = '';
                }
            }
            if ($b === '') {
                $lost = true;
                continue;
            }
            $out .= $b;
        }
        return [trim(preg_replace('/ {2,}/', ' ', $out) ?? $out), $lost];
    }

    /** The width of a piece of text, in points. */
    public function width(string $text, float $size, bool $bold = false): float
    {
        [$bytes] = self::latin($text);
        $table = $bold ? self::BOLD : self::REGULAR;
        $sum = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $c = ord($bytes[$i]);
            $sum += ($c >= 32 && $c <= 126) ? $table[$c - 32] : ($bold ? 611 : 556);
        }
        return $sum * $size / 1000;
    }

    /** @return list<string> the text broken into lines no wider than $max */
    public function wrap(string $text, float $max, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->width($try, $size, $bold) > $max) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * One line of text. $y is the top of the line.
     *
     * @param array{0:float,1:float,2:float} $rgb each 0 to 1
     */
    public function text(float $x, float $y, string $text, float $size, bool $bold = false, string $align = 'L',
                         array $rgb = [0.1, 0.12, 0.16]): void
    {
        [$bytes] = self::latin($text);
        if ($bytes === '') {
            return;
        }
        $w = $this->width($text, $size, $bold);
        if ($align === 'R') {
            $x -= $w;
        } elseif ($align === 'C') {
            $x -= $w / 2;
        }
        $baseline = self::H - $y - $size * 0.78;
        $escaped = strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $this->pages[count($this->pages) - 1] .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $baseline, $escaped
        );
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.6, array $rgb = [0.78, 0.8, 0.84]): void
    {
        $this->pages[count($this->pages) - 1] .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $w, $x1, self::H - $y1, $x2, self::H - $y2
        );
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    public function box(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->pages[count($this->pages) - 1] .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, self::H - $y - $h, $w, $h
        );
    }

    /**
     * A picture, fitted inside a box of $maxW by $maxH with its top left
     * corner at ($x, $y). Returns the size drawn, or null when the file is not
     * a picture this writer can place (the page is then simply without it).
     *
     * @return array{0:float,1:float}|null
     */
    public function image(string $path, float $x, float $y, float $maxW, float $maxH, string $align = 'L'): ?array
    {
        $key = md5($path);
        if (!isset($this->images[$key])) {
            $img = self::readImage($path);
            if ($img === null) {
                return null;
            }
            $img['id'] = count($this->images) + 1;
            $this->images[$key] = $img;
        }
        $img = $this->images[$key];
        $scale = min($maxW / $img['w'], $maxH / $img['h']);
        $w = $img['w'] * $scale;
        $h = $img['h'] * $scale;
        if ($align === 'R') {
            $x += $maxW - $w;
        }
        $this->pages[count($this->pages) - 1] .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q\n", $w, $h, $x, self::H - $y - $h, $img['id']
        );
        return [$w, $h];
    }

    /** Can this file be placed on a page? Used when a logo is uploaded. */
    public static function canPlace(string $path): bool
    {
        return self::readImage($path) !== null;
    }

    /** @return array{w:int,h:int,obj:string,smask:?string}|null */
    private static function readImage(string $path): ?array
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 24) {
            return null;
        }
        if (str_starts_with($data, "\xFF\xD8")) {
            $info = @getimagesizefromstring($data);
            if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
                return null;
            }
            $cs = match ((int)($info['channels'] ?? 3)) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' };
            return ['w' => (int)$info[0], 'h' => (int)$info[1], 'smask' => null,
                'obj' => sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 "
                    . "/Filter /DCTDecode%s /Length %d >>\nstream\n", $info[0], $info[1], $cs,
                    $cs === '/DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '', strlen($data)) . $data . "\nendstream"];
        }
        if (!str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        // ---- PNG: the header, the palette if any, and the picture data ----
        $pos = 8;
        $w = $h = $bits = $type = $interlace = 0;
        $idat = $plte = $trns = '';
        while ($pos + 8 <= strlen($data)) {
            $len  = unpack('N', substr($data, $pos, 4))[1];
            $kind = substr($data, $pos + 4, 4);
            $body = substr($data, $pos + 8, $len);
            $pos += 12 + $len;
            if ($kind === 'IHDR') {
                $w = unpack('N', substr($body, 0, 4))[1];
                $h = unpack('N', substr($body, 4, 4))[1];
                $bits = ord($body[8]);
                $type = ord($body[9]);
                $interlace = ord($body[12]);
            } elseif ($kind === 'PLTE') {
                $plte = $body;
            } elseif ($kind === 'tRNS') {
                $trns = $body;
            } elseif ($kind === 'IDAT') {
                $idat .= $body;
            } elseif ($kind === 'IEND') {
                break;
            }
        }
        if ($w < 1 || $h < 1 || $w > 6000 || $h > 6000 || $bits !== 8 || $interlace !== 0 || $idat === '') {
            return null;
        }

        $smask = null;
        if ($type === 4 || $type === 6) {
            // Colour and transparency are stored together; a PDF wants them
            // apart. Each row keeps its filter byte, so both halves can still
            // be read with the same row filters.
            $raw = @gzuncompress($idat);
            if ($raw === false) {
                return null;
            }
            $colours = $type === 6 ? 3 : 1;
            $rowLen = 1 + $w * ($colours + 1);
            if (strlen($raw) < $rowLen * $h) {
                return null;
            }
            $colour = $alpha = '';
            $pattern = $type === 6 ? '/(.{3})./s' : '/(.)./s';
            for ($row = 0; $row < $h; $row++) {
                $line = substr($raw, $row * $rowLen, $rowLen);
                $colour .= $line[0] . preg_replace($pattern, '$1', substr($line, 1));
                $alpha  .= $line[0] . preg_replace($type === 6 ? '/.{3}(.)/s' : '/.(.)/s', '$1', substr($line, 1));
            }
            $idat = gzcompress($colour);
            $alpha = gzcompress($alpha);
            $smask = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 "
                . "/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns %d >> /Length %d >>\nstream\n",
                $w, $h, $w, strlen($alpha)) . $alpha . "\nendstream";
            $cs = $type === 6 ? '/DeviceRGB' : '/DeviceGray';
        } elseif ($type === 2) {
            $colours = 3;
            $cs = '/DeviceRGB';
        } elseif ($type === 0) {
            $colours = 1;
            $cs = '/DeviceGray';
        } elseif ($type === 3 && $plte !== '') {
            $colours = 1;
            $cs = '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]';
        } else {
            return null;
        }

        return ['w' => $w, 'h' => $h, 'smask' => $smask,
            'obj' => sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 "
                . "/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >>%s /Length %d >>\nstream\n",
                $w, $h, $cs, $colours, $w, '%SMASK%', strlen($idat)) . $idat . "\nendstream"];
    }

    /** The finished file. */
    public function output(): string
    {
        $objects = [];           // number => body
        $n = 0;
        $add = static function (string $body) use (&$objects, &$n): int {
            $objects[++$n] = $body;
            return $n;
        };

        $catalog = $add('');     // 1
        $pagesId = $add('');     // 2
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');

        $xobjects = '';
        foreach ($this->images as $img) {
            $maskRef = '';
            if ($img['smask'] !== null) {
                $maskRef = ' /SMask ' . $add($img['smask']) . ' 0 R';
            }
            $id = $add(str_replace('%SMASK%', $maskRef, $img['obj']));
            $xobjects .= ' /I' . $img['id'] . ' ' . $id . ' 0 R';
        }

        $kids = [];
        foreach ($this->pages as $stream) {
            $packed = gzcompress($stream);
            $content = $add('<< /Filter /FlateDecode /Length ' . strlen($packed) . " >>\nstream\n" . $packed . "\nendstream");
            $kids[] = $add(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R '
                . '/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> /XObject <<%s >> >> >>',
                $pagesId, self::W, self::H, $content, $f1, $f2, $xobjects
            ));
        }
        $objects[$pagesId] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $k): string => $k . ' 0 R', $kids))
            . '] /Count ' . count($kids) . ' >>';
        $objects[$catalog] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';

        [$title] = self::latin($this->title);
        $info = $add('<< /Title (' . strtr($title, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') /Producer (AI Sales Agent) '
            . '/CreationDate (D:' . gmdate('YmdHis') . 'Z) >>');

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $out . "trailer\n<< /Size " . ($n + 1) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
