<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/** Keeps article HTML to the small set of tags the blog accepts. */
final class SmHtml
{
    private const ALLOWED = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!str_contains($html, '<')) {
            $html = '<p>' . implode('</p><p>', array_map('htmlspecialchars', preg_split('/\n\s*\n/', $html) ?: [])) . '</p>';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="sm-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('sm-root');
        if (!$root) {
            return '';
        }
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $c) {
            $out .= $doc->saveHTML($c);
        }
        return trim(preg_replace('/\n{3,}/', "\n\n", $out) ?? $out);
    }

    private static function walk(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'form', 'img', 'svg'], true)) {
                    $node->removeChild($c);
                    continue;
                }
                self::walk($c);
                if ($tag === 'h1') {
                    $h = $c->ownerDocument->createElement('h2');
                    while ($c->firstChild) {
                        $h->appendChild($c->firstChild);
                    }
                    $node->replaceChild($h, $c);
                    continue;
                }
                if (!in_array($tag, self::ALLOWED, true)) {
                    while ($c->firstChild) {
                        $node->insertBefore($c->firstChild, $c);
                    }
                    $node->removeChild($c);
                    continue;
                }
                $href = $tag === 'a' ? $c->getAttribute('href') : '';
                while ($c->attributes->length > 0) {
                    $c->removeAttribute($c->attributes->item(0)->nodeName);
                }
                if ($tag === 'a') {
                    if (preg_match('#^https?://#i', $href)) {
                        $c->setAttribute('href', $href);
                    } else {
                        while ($c->firstChild) {
                            $node->insertBefore($c->firstChild, $c);
                        }
                        $node->removeChild($c);
                    }
                }
            } elseif ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction) {
                $node->removeChild($c);
            }
        }
    }
}
