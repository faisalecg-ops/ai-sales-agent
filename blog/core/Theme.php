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
            ['#FFFFFF', 'secondary', 'White text on the second colour (dark header, footer, buttons)', 4.5],
            ['highlight_ink', 'highlight', 'Text on the highlight colour (call-to-action buttons)', 4.5],
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
        if (!empty($fonts[$key]['variable'])) {
            // One variable file holds every weight and width.
            $range = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
            $css = "@font-face{font-family:'$family';font-style:normal;font-weight:100 900;font-stretch:62% 125%;font-display:swap;src:url("
                . url('assets/fonts/' . $key . '-latin-variable.woff2') . ") format('woff2-variations'),url(" . url('assets/fonts/' . $key . '-latin-variable.woff2') . ") format('woff2');unicode-range:$range}";
            if (is_file(BLOG_ROOT . '/assets/fonts/' . $key . '-latin-ext-variable.woff2')) {
                $css .= "@font-face{font-family:'$family';font-style:normal;font-weight:100 900;font-stretch:62% 125%;font-display:swap;src:url("
                    . url('assets/fonts/' . $key . '-latin-ext-variable.woff2') . ") format('woff2-variations');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}";
            }
            return $css;
        }
        $css = '';
        foreach ($fonts[$key]['weights'] as $w) {
            $css .= "@font-face{font-family:'$family';font-style:normal;font-weight:$w;font-display:swap;src:url(" . url('assets/fonts/' . $key . '-latin-' . $w . '-normal.woff2') . ") format('woff2')}";
        }
        if (is_file(BLOG_ROOT . '/assets/fonts/' . $key . '-latin-400-italic.woff2')) {
            $css .= "@font-face{font-family:'$family';font-style:italic;font-weight:400;font-display:swap;src:url(" . url('assets/fonts/' . $key . '-latin-400-italic.woff2') . ") format('woff2')}";
        }
        return $css;
    }

    /** The font file to load first for a font. */
    public static function fontFile(string $key, int $weight): string
    {
        $f = Settings::fonts()[$key] ?? null;
        if (!$f) {
            return '';
        }
        return !empty($f['variable']) ? url('assets/fonts/' . $key . '-latin-variable.woff2') : url('assets/fonts/' . $key . '-latin-' . $weight . '-normal.woff2');
    }

    public static function preloadFonts(array $t): string
    {
        $out = '';
        foreach (array_unique([(string)$t['heading_font'], (string)$t['body_font']]) as $k) {
            if (isset(Settings::fonts()[$k])) {
                $out .= '<link rel="preload" href="' . e(self::fontFile($k, $k === $t['heading_font'] ? 700 : 400)) . '" as="font" type="font/woff2" crossorigin>';
            }
        }
        return $out;
    }

    /** The CSS custom properties for a theme. */
    public static function css(?array $t = null): string
    {
        $t = $t ?? Settings::theme();
        $fonts = Settings::fonts();
        $hf = $fonts[$t['heading_font']] ?? $fonts['archivo'];
        $heading = $hf['stack'];
        $body = ($fonts[$t['body_font']] ?? $fonts['archivo'])['stack'];
        $col = static fn(string $k, string $d) => self::validColour((string)($t[$k] ?? '')) ? (string)$t[$k] : $d;
        $btn = match ($t['button_shape']) { 'pill' => '999px', 'rounded' => '5px', default => '0px' };
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
            . '--highlight:' . $col('highlight', (string)($t['primary'] ?? '#F7B10B')) . ';'
            . '--highlight-ink:' . $col('highlight_ink', '#1D1500') . ';'
            . '--head-stretch:' . ($hf['stretch'] ?? '100%') . ';'
            . '--head-weight:' . ($hf['head_weight'] ?? 700) . ';'
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
        return 'hdr-' . $h . ' cards-' . $c . (!empty($t['band']) ? ' has-band' : '') . (!array_key_exists('motion', $t) || !empty($t['motion']) ? ' motion-on' : '');
    }
}
