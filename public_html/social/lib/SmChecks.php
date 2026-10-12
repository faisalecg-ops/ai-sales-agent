<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Checks PHP makes on every draft before a person sees it. They never block a
 * person from deciding — they are shown next to the Approve button.
 */
final class SmChecks
{
    /** @return list<array{level:string,message:string}> */
    public static function article(array $agent, array $article, array $allowedLinks): array
    {
        $aid = (int)$agent['id'];
        $f = SmEvent::facts($agent);
        $s = SmEvent::settings($aid);
        $body = (string)$article['body'];
        $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8'));
        $out = [];

        $words = str_word_count($text);
        if ($words < 250) {
            $out[] = ['level' => 'warn', 'message' => "Short: $words words (aim for 350–650)."];
        } elseif ($words > 900) {
            $out[] = ['level' => 'warn', 'message' => "Long: $words words (aim for 350–650)."];
        }
        if (substr_count(strtolower($body), '<h2') < 2) {
            $out[] = ['level' => 'warn', 'message' => 'Fewer than two subheadings.'];
        }
        if (mb_strlen((string)$article['title']) > 100) {
            $out[] = ['level' => 'warn', 'message' => 'The title is longer than 100 characters.'];
        }

        // Links only to approved addresses.
        $allowed = array_map([self::class, 'norm'], array_filter($allowedLinks));
        preg_match_all('#href\s*=\s*"([^"]+)"#i', $body, $m);
        foreach (array_unique($m[1]) as $href) {
            $n = self::norm(html_entity_decode($href));
            $ok = in_array($n, $allowed, true);
            if (!$ok) {
                foreach ($allowed as $a) {
                    // "buildasia.net/blog" allows "buildasia.net/blog/x", never "buildasia.net.evil.com" or "buildasia.net/blogger".
                    if ($a !== '' && str_starts_with($n, $a) && in_array(substr($n, strlen($a), 1), ['/', '?', '#'], true)) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok) {
                $out[] = ['level' => 'bad', 'message' => 'A link points to an address that is not a source or an approved page: ' . $href];
            }
        }

        // Event dates must match the sales agent's event facts.
        if ($f['dates'] !== '' && preg_match_all('/\b(\d{1,2})\s*[–-]\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(20\d\d)\b/u', $text, $dm, PREG_SET_ORDER)) {
            foreach ($dm as $d) {
                $range = $d[1] . '–' . $d[2] . ' ' . $d[3] . ' ' . $d[4];
                if ($range !== $f['dates'] && str_contains($text, $f['name'])) {
                    $out[] = ['level' => 'bad', 'message' => "The dates \"{$d[0]}\" do not match the event's dates ({$f['dates']}). Check they are not about the event."];
                }
            }
        }

        // Prices of stands and sponsorship, when the settings say no prices.
        if (!$s['allow_prices'] && preg_match('/(stand|stall|booth|sponsor\w*|package|per\s+(sq|square))[^.]{0,60}(Rs\.?|PKR|US\$|USD|\$|AED|€)\s?\d|(Rs\.?|PKR|US\$|USD|\$|AED|€)\s?\d[\d,.]*[^.]{0,60}(stand|stall|booth|sponsor\w*|per\s+(sq|square))/i', $text)) {
            $out[] = ['level' => 'bad', 'message' => 'It seems to mention a stand or sponsorship price. The settings say no prices.'];
        }

        // Words the exhibition never uses, and pitches the owner ruled out.
        $banned = array_filter(array_map('trim', explode(',', (string)$s['avoid'])));
        $banned = array_merge($banned, ['invest in pakistan', 'guaranteed return', 'risk-free', 'risk free', 'once-in-a-lifetime']);
        foreach ($banned as $b) {
            if ($b !== '' && stripos($text, $b) !== false) {
                $out[] = ['level' => 'bad', 'message' => "Uses \"$b\", which the settings or the rules do not allow."];
            }
        }

        // Too close to something already written.
        $title = self::words((string)$article['title']);
        foreach (db()->all("SELECT id, title FROM sm_articles WHERE agent_id = ? AND id <> ? AND status <> 'rejected' ORDER BY id DESC LIMIT 80", [$aid, (int)($article['id'] ?? 0)]) as $r) {
            if (self::similar($title, self::words((string)$r['title'])) >= 0.6) {
                $out[] = ['level' => 'warn', 'message' => 'Very close to an earlier article: "' . $r['title'] . '".'];
                break;
            }
        }
        if (!$out) {
            $out[] = ['level' => 'ok', 'message' => 'Length, headings, links, dates, prices and wording all passed.'];
        }
        return $out;
    }

    /** @return list<array{level:string,message:string}> */
    public static function post(array $agent, array $post, ?string $link): array
    {
        $p = (string)$post['platform'];
        $s = SmEvent::settings((int)$agent['id']);
        $full = self::fullText($post, $link);
        $len = $p === 'x' ? mb_strlen(self::fullText($post, $link !== null ? str_repeat('x', 23) : null)) : mb_strlen($full);
        $out = [];
        if ($len > SmWriter::LIMITS[$p]) {
            $out[] = ['level' => 'bad', 'message' => SmEvent::PLATFORMS[$p] . " allows " . SmWriter::LIMITS[$p] . " characters; this has $len."];
        }
        $tags = preg_match_all('/#\w+/u', (string)$post['hashtags']);
        if ($p === 'instagram' && $tags > 30) {
            $out[] = ['level' => 'bad', 'message' => 'Instagram allows at most 30 hashtags.'];
        }
        if (in_array($p, ['linkedin', 'x'], true) && $tags > 5) {
            $out[] = ['level' => 'warn', 'message' => 'More than 5 hashtags looks spammy on ' . SmEvent::PLATFORMS[$p] . '.'];
        }
        if ($p === 'instagram' && empty($post['media_id'])) {
            $out[] = ['level' => 'bad', 'message' => 'Instagram needs a picture or video.'];
        } elseif (empty($post['media_id'])) {
            $out[] = ['level' => 'warn', 'message' => 'No picture: posts without one reach far fewer people.'];
        }
        if (!$s['allow_prices'] && preg_match('/(Rs\.?|PKR|US\$|USD|\$|AED|€)\s?\d/i', (string)$post['body'])) {
            $out[] = ['level' => 'warn', 'message' => 'Mentions an amount of money. The settings say no prices — check it is not a stand price.'];
        }
        if (preg_match('#https?://#i', (string)$post['body'])) {
            $out[] = ['level' => 'warn', 'message' => 'The text contains a web address. Use the "Include the blog post link" box instead, so the link is tracked.'];
        }
        $credits = SmFlow::credits(!empty($post['article_id']) ? (int)$post['article_id'] : null);
        if (($sp = $credits['sponsor']) && !self::hasDisclosure($full, $sp)) {
            $out[] = ['level' => 'bad', 'message' => 'The article is paid content for ' . $sp['name'] . ', so the post must say so: keep the line "' . SmFlow::disclosure($sp) . '".'];
        }
        if (($v = $credits['voice']) && mb_stripos($full, (string)$v['name']) === false) {
            $out[] = ['level' => 'warn', 'message' => 'The article was written by ' . $v['name'] . '. Name them in the post (and tag them when you post).'];
        }
        if (!$out) {
            $out[] = ['level' => 'ok', 'message' => 'Length, hashtags and picture are fine for ' . SmEvent::PLATFORMS[$p] . '.'];
        }
        return $out;
    }

    /** The post says it is sponsored and names the sponsor. */
    public static function hasDisclosure(string $text, array $sponsor): bool
    {
        $label = trim((string)($sponsor['label'] ?? '')) ?: 'Sponsored';
        return mb_stripos($text, (string)$sponsor['name']) !== false
            && (mb_stripos($text, $label) !== false || preg_match('/\b(sponsored|paid partnership|#ad)\b/iu', $text) === 1);
    }

    /** The post exactly as it will appear on the platform. */
    public static function fullText(array $post, ?string $link): string
    {
        $body = trim((string)$post['body']);
        $tags = trim((string)$post['hashtags']);
        $p = (string)$post['platform'];
        $linkLine = '';
        if ($link !== null && (int)$post['include_link'] === 1) {
            $linkLine = $p === 'instagram' ? 'Link in bio.' : $link;
        }
        return trim($body . ($linkLine !== '' ? "\n\n" . $linkLine : '') . ($tags !== '' ? "\n\n" . $tags : ''));
    }

    public static function norm(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url) ?? $url;
        return rtrim($url, '/');
    }

    private static function words(string $s): array
    {
        $w = array_filter(preg_split('/[^a-z0-9]+/', strtolower($s)) ?: [], static fn($x) => strlen($x) > 2);
        return array_values(array_unique($w));
    }

    private static function similar(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        return count(array_intersect($a, $b)) / count(array_unique(array_merge($a, $b)));
    }
}
