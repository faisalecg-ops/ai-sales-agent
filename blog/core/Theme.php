<?php
declare(strict_types=1);

/**
 * Turns the branding settings into the page's colours, fonts and shapes.
 * Only the two fonts in use are loaded, from the blog's own server.
 */
final class Theme
{
    public static function validColour(string $c): bool
    {
        return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c);
    }

    /** WCAG contrast ratio between two colours (1 to 21). 4.5 or more is readable text. */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $c = [];
            foreach ([0, 2, 4] as $i) {
                $v = hexdec(substr($hex, $i, 2)) / 255;
                $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        if (!self::validColour($a) || !self::validColour($b)) {
            return 1.0;
        }
        $l1 = $lum($a);
        $l2 = $lum($b);
        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    /** Readability warnings for a theme, in plain words. */
    public static function warnings(array $t): array
    {
        $w = [];
        $pairs = [
            ['text', 'background', 'Body text on the page background', 4.5],
            ['muted', 'background', 'Grey secondary text on the background', 4.5],
            ['primary', 'background', 'The main colour (links, labels) on the background', 3.0],
            ['#FFFFFF', 'primary', 'White button text on the main colour', 4.5],
            ['#FFFFFF', 'secondary', 'White text on the second colour (dark header, footer)', 4.5],
        ];
        foreach ($pairs as [$fg, $bg, $label, $min]) {
            $f = str_starts_with($fg, '#') ? $fg : (string)($t[$fg] ?? '');
            $b = (string)($t[$bg] ?? '');
            $ratio = self::contrast($f, $b);
            if ($ratio < $min) {
                $w[] = $label . ' is hard to read (contrast ' . $ratio . ':1, needs ' . $min . ':1).';
            }
        }
        return $w;
    }

    private static function fontFace(string $key): string
    {
        $fonts = Settings::fonts();
        if (!isset($fonts[$key])) {
            return '';
        }
        $family = trim(explode(',', $fonts[$key]['stack'])[0], "' ");
        $css = '';
        foreach ($fonts[$key]['weights'] as $w) {
            $css .= "@font-face{font-family:'$family';font-style:normal;font-weight:$w;font-display:swap;src:url(" . url('assets/fonts/' . $key . '-latin-' . $w . '-normal.woff2') . ") format('woff2')}";
        }
        if (is_file(BLOG_ROOT . '/assets/fonts/' . $key . '-latin-400-italic.woff2')) {
            $css .= "@font-face{font-family:'$family';font-style:italic;font-weight:400;font-display:swap;src:url(" . url('assets/fonts/' . $key . '-latin-400-italic.woff2') . ") format('woff2')}";
        }
        return $css;
    }

    public static function preloadFonts(array $t): string
    {
        $out = '';
        foreach (array_unique([(string)$t['heading_font'], (string)$t['body_font']]) as $k) {
            if (isset(Settings::fonts()[$k])) {
                $out .= '<link rel="preload" href="' . e(url('assets/fonts/' . $k . '-latin-' . ($k === $t['heading_font'] ? '700' : '400') . '-normal.woff2')) . '" as="font" type="font/woff2" crossorigin>';
            }
        }
        return $out;
    }

    /** The CSS custom properties for a theme. */
    public static function css(?array $t = null): string
    {
        $t = $t ?? Settings::theme();
        $fonts = Settings::fonts();
        $heading = $fonts[$t['heading_font']]['stack'] ?? $fonts['source-serif-4']['stack'];
        $body = $fonts[$t['body_font']]['stack'] ?? $fonts['inter']['stack'];
        $col = static fn(string $k, string $d) => self::validColour((string)($t[$k] ?? '')) ? (string)$t[$k] : $d;
        $btn = match ($t['button_shape']) { 'pill' => '999px', 'rounded' => '6px', default => '0px' };
        $radius = max(0, min(24, (int)$t['radius'])) . 'px';
        $shade = max(0, min(85, (int)$t['slider_shade'])) / 100;
        $css = self::fontFace((string)$t['heading_font']);
        if ($t['body_font'] !== $t['heading_font']) {
            $css .= self::fontFace((string)$t['body_font']);
        }
        $css .= ':root{'
            . '--primary:' . $col('primary', '#E3120B') . ';'
            . '--secondary:' . $col('secondary', '#1F2E3D') . ';'
            . '--accent:' . $col('accent', '#006BA2') . ';'
            . '--bg:' . $col('background', '#FFFFFF') . ';'
            . '--surface:' . $col('surface', '#F6F5F2') . ';'
            . '--text:' . $col('text', '#121212') . ';'
            . '--muted:' . $col('muted', '#595959') . ';'
            . '--font-head:' . $heading . ';'
            . '--font-body:' . $body . ';'
            . '--btn-radius:' . $btn . ';'
            . '--radius:' . $radius . ';'
            . '--shade:' . $shade . ';'
            . '}';
        return $css;
    }

    public static function bodyClasses(array $t): string
    {
        $h = in_array($t['header_style'], ['light', 'dark', 'transparent'], true) ? $t['header_style'] : 'light';
        $c = in_array($t['card_style'], ['rule', 'bordered', 'shadow'], true) ? $t['card_style'] : 'rule';
        return 'hdr-' . $h . ' cards-' . $c;
    }
}
