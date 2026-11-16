<?php
declare(strict_types=1);

/**
 * Output sanitization (Section 29).
 *
 * The model's reply is untrusted. Rather than emitting an HTML string and
 * hoping the widget escapes it, this converts the reply into a structured
 * block list. The widget builds DOM nodes and assigns text via textContent,
 * so there is no innerHTML path for model output to travel down at all.
 *
 * Supported subset: paragraphs, line breaks, **bold**, bullet lists,
 * numbered lists, and links restricted to an approved-domain allow-list.
 */
final class Sanitizer
{
    /** Emoji and pictographic ranges — removed per the no-emoji UI standard. */
    private const EMOJI_PATTERN =
        '/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2460}-\x{27BF}'
        . '\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{200D}\x{20E3}]/u';

    /**
     * Convert reply text to safe blocks.
     *
     * @param string   $text            raw model reply
     * @param string[] $approvedDomains hostnames whose links may render as clickable
     * @return array<int,array<string,mixed>>
     */
    public static function toBlocks(string $text, array $approvedDomains = []): array
    {
        $text = self::normalise($text);
        if ($text === '') {
            return [];
        }

        $blocks = [];
        $lines  = explode("\n", $text);
        $para   = [];
        $list   = null;   // ['type' => 'ul'|'ol', 'items' => []]

        $flushPara = static function () use (&$para, &$blocks, $approvedDomains): void {
            if ($para) {
                $joined = trim(implode("\n", $para));
                if ($joined !== '') {
                    $blocks[] = ['type' => 'p', 'spans' => self::spans($joined, $approvedDomains)];
                }
                $para = [];
            }
        };
        $flushList = static function () use (&$list, &$blocks): void {
            if ($list && $list['items']) {
                $blocks[] = ['type' => $list['type'], 'items' => $list['items']];
            }
            $list = null;
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '') {
                $flushPara();
                $flushList();
                continue;
            }

            if (preg_match('/^[\-\*\x{2022}\x{00B7}]\s+(.*)$/u', $trim, $m)) {
                $flushPara();
                if (!$list || $list['type'] !== 'ul') {
                    $flushList();
                    $list = ['type' => 'ul', 'items' => []];
                }
                $list['items'][] = self::spans($m[1], $approvedDomains);
                continue;
            }

            if (preg_match('/^(\d{1,2})[\.\)]\s+(.*)$/u', $trim, $m)) {
                $flushPara();
                if (!$list || $list['type'] !== 'ol') {
                    $flushList();
                    $list = ['type' => 'ol', 'items' => []];
                }
                $list['items'][] = self::spans($m[2], $approvedDomains);
                continue;
            }

            $flushList();
            // Drop markdown headings down to plain emphasis rather than rendering headings in a chat bubble.
            $trim = preg_replace('/^#{1,6}\s*/', '', $trim) ?? $trim;
            $para[] = $trim;
        }

        $flushPara();
        $flushList();

        return array_slice($blocks, 0, 40);
    }

    /** Plain-text version, used for storage, notifications and CSV export. */
    public static function toPlainText(string $text): string
    {
        $text = self::normalise($text);
        $text = preg_replace('/\*\*(.+?)\*\*/su', '$1', $text) ?? $text;
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/su', '$1 ($2)', $text) ?? $text;
        return trim($text);
    }

    /**
     * Strip anything that could carry markup or control behaviour.
     */
    private static function normalise(string $text): string
    {
        // Remove null bytes and normalise newlines.
        $text = str_replace("\0", '', $text);
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        // Remove any HTML/XML tags outright — the model has no business emitting them.
        $text = preg_replace('/<[^>]*>/s', '', $text) ?? $text;

        // Decode entities the model may have produced, then neutralise the result
        // so "&lt;script&gt;" cannot survive a later decode step anywhere downstream.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<[^>]*>/s', '', $text) ?? $text;

        // Strip emoji and pictographs (UI standard, Section 39).
        $text = preg_replace(self::EMOJI_PATTERN, '', $text) ?? $text;

        // Remove invisible/bidi control characters that can be used to spoof text.
        $text = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]/u', '', $text) ?? $text;

        // Collapse runaway blank lines, tidy spacing left by removed characters, cap length.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/ +([,.;:!?])/', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = mb_substr($text, 0, 6000);

        return trim($text);
    }

    /**
     * Split a line into text / bold / link spans.
     *
     * @return array<int,array<string,string>>
     */
    private static function spans(string $line, array $approvedDomains): array
    {
        $spans = [];
        $len   = mb_strlen($line);
        $buf   = '';
        $i     = 0;

        while ($i < $len) {
            // Markdown link: [label](url)
            if (mb_substr($line, $i, 1) === '['
                && preg_match('/^\[([^\]]{1,120})\]\(((?:[^()\s]|\([^()\s]*\)){1,400})\)/u', mb_substr($line, $i), $m)) {
                $href = self::safeHref($m[2], $approvedDomains);
                if ($buf !== '') {
                    $spans[] = ['t' => 'text', 'v' => $buf];
                    $buf = '';
                }
                if ($href !== null) {
                    $spans[] = ['t' => 'a', 'v' => $m[1], 'href' => $href];
                } else {
                    $spans[] = ['t' => 'text', 'v' => $m[1]];
                }
                $i += mb_strlen($m[0]);
                continue;
            }

            // Bold: **text**
            if (mb_substr($line, $i, 2) === '**'
                && preg_match('/^\*\*(.+?)\*\*/su', mb_substr($line, $i), $m)) {
                if ($buf !== '') {
                    $spans[] = ['t' => 'text', 'v' => $buf];
                    $buf = '';
                }
                $spans[] = ['t' => 'b', 'v' => $m[1]];
                $i += mb_strlen($m[0]);
                continue;
            }

            // Bare URL
            if (preg_match('~^(https?://[^\s<>"\']{4,400})~i', mb_substr($line, $i), $m)) {
                // Punctuation after a link belongs to the sentence, not the
                // link: "(https://…/book-a-stand/)?" links to …/book-a-stand/
                // and keeps ")?" as text (test chat 5, 12 Nov 2026).
                while ($m[1] !== '' && (preg_match('~[.,;:!?]$~', $m[1])
                    || (str_ends_with($m[1], ')') && substr_count($m[1], '(') < substr_count($m[1], ')'))
                    || (str_ends_with($m[1], ']') && substr_count($m[1], '[') < substr_count($m[1], ']')))) {
                    $m[1] = substr($m[1], 0, -1);
                }
                $href = self::safeHref($m[1], $approvedDomains);
                if ($buf !== '') {
                    $spans[] = ['t' => 'text', 'v' => $buf];
                    $buf = '';
                }
                if ($href !== null) {
                    $spans[] = ['t' => 'a', 'v' => self::linkLabel($href), 'href' => $href];
                } else {
                    $spans[] = ['t' => 'text', 'v' => self::linkLabel($m[1])];
                }
                $i += mb_strlen($m[1]);
                continue;
            }

            $buf .= mb_substr($line, $i, 1);
            $i++;
        }

        if ($buf !== '') {
            $spans[] = ['t' => 'text', 'v' => $buf];
        }

        // Strip leftover markdown emphasis markers from plain text spans.
        foreach ($spans as $k => $s) {
            if ($s['t'] === 'text') {
                $spans[$k]['v'] = str_replace(['**', '__'], '', $s['v']);
            }
        }

        return $spans;
    }

    /**
     * Only HTTPS links to approved hostnames become clickable (Section 29).
     */
    private static function safeHref(string $url, array $approvedDomains): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 400) {
            return null;
        }
        // Reject anything that is not plainly http(s) — blocks javascript:, data:, vbscript:
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return null;   // clickable links are HTTPS only
        }
        $host = strtolower($parts['host']);
        foreach ($approvedDomains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }
            if (str_starts_with($domain, '*.')) {
                $base = substr($domain, 2);
                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return $url;
                }
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $url;
            }
        }
        return null;
    }

    private static function linkLabel(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : mb_substr($url, 0, 60);
    }
}
