<?php
declare(strict_types=1);

/**
 * Cleans article text from the editor or the API so only safe formatting
 * reaches the page: headings, paragraphs, lists, quotes, tables, images,
 * links and YouTube/Vimeo videos. Scripts, styles and event handlers are
 * removed. Tracking (UTM) tags are taken off links inside articles.
 */
final class Html
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'mark' => [],
        'sup' => [], 'sub' => [], 'code' => [], 'pre' => [], 'small' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => ['cite'],
        'figure' => ['class'], 'figcaption' => [],
        'img' => ['src', 'alt', 'width', 'height', 'title', 'data-media-id'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen'],
        'div' => ['class'], 'span' => [],
    ];

    /** Elements removed together with everything inside them. */
    private const DROP = ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'noscript', 'template', 'head', 'title', 'meta', 'link'];

    private const VIDEO_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // Plain text with no tags: make paragraphs from blank lines.
        if (!preg_match('/<[a-z][^>]*>/i', $html)) {
            $parts = preg_split("/\R{2,}/", $html) ?: [];
            $html = implode('', array_map(static fn($p) => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>', array_filter($parts, 'strlen')));
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $root = $doc->getElementById('__root');
        if (!$root) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        // Empty paragraphs left by word processors.
        $out = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#i', '', $out) ?? $out;
        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            // Headings: the article title is the page's only h1.
            if ($tag === 'h1') {
                $child = self::rename($child, 'h2');
                $tag = 'h2';
            } elseif (in_array($tag, ['h5', 'h6'], true)) {
                $child = self::rename($child, 'h4');
                $tag = 'h4';
            }
            if (!array_key_exists($tag, self::ALLOWED)) {
                // Keep the text, lose the unknown wrapper.
                self::walk($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                if (!in_array(strtolower($attr->name), self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && !self::fixLink($child)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'img' && !self::fixImage($child)) {
                $node->removeChild($child);
                continue;
            }
            if ($tag === 'iframe' && !self::fixVideo($child)) {
                $node->removeChild($child);
                continue;
            }
            if (in_array($tag, ['div', 'figure'], true)) {
                $cls = (string)$child->getAttribute('class');
                $keep = array_intersect(preg_split('/\s+/', $cls) ?: [], ['video', 'wide', 'callout', 'align-left', 'align-right']);
                $keep ? $child->setAttribute('class', implode(' ', $keep)) : $child->removeAttribute('class');
            }
            self::walk($child);
        }
    }

    private static function rename(DOMElement $el, string $tag): DOMElement
    {
        $new = $el->ownerDocument->createElement($tag);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }

    private static function fixLink(DOMElement $a): bool
    {
        $href = trim((string)$a->getAttribute('href'));
        if ($href === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
            return false;
        }
        if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            $a->removeAttribute('target');
            return true;
        }
        if (str_starts_with($href, '/')) {
            $href = rtrim((string)preg_replace('#^(https?://[^/]+).*$#', '$1', base_url()), '/') . $href;
        }
        if (!is_web_url($href)) {
            return false;
        }
        $href = self::stripTracking($href);
        $a->setAttribute('href', $href);

        $rel = array_filter(preg_split('/\s+/', strtolower((string)$a->getAttribute('rel'))) ?: []);
        $rel = array_intersect($rel, ['sponsored', 'nofollow', 'ugc']);
        if (LinkPolicy::isSponsored($href)) {
            $rel[] = 'sponsored';
        }
        if (is_internal_url($href)) {
            $a->removeAttribute('target');
        } else {
            // Outside links open in a new tab, safely.
            $a->setAttribute('target', '_blank');
            $rel[] = 'noopener';
        }
        $rel = array_values(array_unique($rel));
        $rel ? $a->setAttribute('rel', implode(' ', $rel)) : $a->removeAttribute('rel');
        return true;
    }

    /** Remove utm_* and click-id parameters: no tracking tags inside articles. */
    public static function stripTracking(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid$|gclid$|mc_eid$|mc_cid$)/i', (string)$k)) {
                unset($q[$k]);
            }
        }
        $base = preg_replace('/\?.*$/', '', $url) ?? $url;
        $frag = '';
        if (isset($parts['fragment'])) {
            $base = preg_replace('/#.*$/', '', $base) ?? $base;
            $frag = '#' . $parts['fragment'];
        }
        return $base . ($q ? '?' . http_build_query($q) : '') . $frag;
    }

    private static function fixImage(DOMElement $img): bool
    {
        $src = trim((string)$img->getAttribute('src'));
        if ($src === '' || preg_match('/^\s*(javascript|data):/i', $src)) {
            return false;
        }
        if (str_starts_with($src, '/')) {
            $src = rtrim((string)preg_replace('#^(https?://[^/]+).*$#', '$1', base_url()), '/') . $src;
            $img->setAttribute('src', $src);
        }
        if (!is_web_url($src)) {
            return false;
        }
        if (!$img->hasAttribute('alt')) {
            $img->setAttribute('alt', '');
        }
        foreach (['width', 'height'] as $dim) {
            if ($img->hasAttribute($dim) && !ctype_digit((string)$img->getAttribute($dim))) {
                $img->removeAttribute($dim);
            }
        }
        return true;
    }

    private static function fixVideo(DOMElement $f): bool
    {
        $src = (string)$f->getAttribute('src');
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        if (!str_starts_with($src, 'https://') || !in_array($host, self::VIDEO_HOSTS, true)) {
            return false;
        }
        $f->setAttribute('loading', 'lazy');
        $f->setAttribute('allowfullscreen', '');
        if (!$f->hasAttribute('title')) {
            $f->setAttribute('title', 'Video');
        }
        return true;
    }

    /** All links in an article body: [['url' => ..., 'text' => ...], ...] */
    public static function links(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        $out = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = (string)$a->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }
            $out[] = ['url' => $href, 'text' => trim($a->textContent), 'rel' => (string)$a->getAttribute('rel')];
        }
        return $out;
    }

    /** Add ids to h2/h3 so they can be linked to, and return the table of contents. */
    public static function withHeadingIds(string $html): array
    {
        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<(h2|h3)(\s[^>]*)?>(.*?)</\1>#is', static function ($m) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            $id = slugify($text, 60) ?: 'section';
            $base = $id;
            $n = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $n++;
            }
            $used[$id] = true;
            $toc[] = ['level' => (int)substr($m[1], 1), 'id' => $id, 'text' => $text];
            return '<' . $m[1] . ' id="' . $id . '">' . $m[3] . '</' . $m[1] . '>';
        }, $html) ?? $html;
        return [$html, $toc];
    }

    /** Make article images lazy-load, with modern formats where we have them. */
    public static function enhanceImages(string $html): string
    {
        return preg_replace_callback('#<img\b([^>]*)>#i', static function ($m) {
            $attrs = $m[1];
            if (preg_match('/data-media-id="(\d+)"/', $attrs, $id)) {
                $media = Media::find((int)$id[1]);
                if ($media) {
                    preg_match('/alt="([^"]*)"/', $attrs, $alt);
                    return Media::picture($media, 'content', html_entity_decode($alt[1] ?? '', ENT_QUOTES), '(max-width: 760px) 100vw, 720px');
                }
            }
            if (!str_contains($attrs, 'loading=')) {
                $attrs .= ' loading="lazy" decoding="async"';
            }
            return '<img' . $attrs . '>';
        }, $html) ?? $html;
    }

    public static function readingMinutes(string $html): int
    {
        $words = str_word_count(plain_text($html));
        return max(1, (int)round($words / 220));
    }
}
