<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Where an article's facts come from: the exhibition's approved knowledge in
 * the sales agent (read-only) and web pages the agent has actually opened.
 */
final class SmSources
{
    /** Set only by the test suite: fn(array $urls): array<url, text>. */
    public static ?Closure $extractHook = null;

    /**
     * Approved knowledge and FAQs that match the topic's words.
     * @return list<array{title:string,text:string,ref:string}>
     */
    public static function knowledge(int $agentId, string $topic, int $limit = 6): array
    {
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', mb_strtolower($topic)) ?: [],
            static fn($w) => mb_strlen($w) > 3 && !in_array($w, ['with', 'from', 'that', 'this', 'what', 'they', 'their', 'into', 'about', 'pakistan', 'event'], true)
        ));
        $rows = [];
        foreach (db()->all("SELECT id, COALESCE(approved_version_title, title) t, COALESCE(approved_version_body, body) b, keywords FROM knowledge_articles
                            WHERE agent_id = ? AND active = 1 AND (status = 'approved' OR approved_version_body IS NOT NULL)", [$agentId]) as $r) {
            $rows[] = ['title' => (string)$r['t'], 'text' => (string)$r['b'], 'ref' => 'knowledge #' . $r['id'], 'kw' => (string)$r['keywords']];
        }
        foreach (db()->all("SELECT id, COALESCE(approved_version_question, question) q, COALESCE(approved_version_answer, answer) a, keywords FROM faq_entries
                            WHERE agent_id = ? AND active = 1 AND (status = 'approved' OR approved_version_answer IS NOT NULL)", [$agentId]) as $r) {
            $rows[] = ['title' => (string)$r['q'], 'text' => (string)$r['a'], 'ref' => 'FAQ #' . $r['id'], 'kw' => (string)$r['keywords']];
        }
        foreach ($rows as &$r) {
            $hay = mb_strtolower($r['title'] . ' ' . $r['kw'] . ' ' . mb_substr($r['text'], 0, 1500));
            $r['score'] = 0;
            foreach ($words as $w) {
                $r['score'] += substr_count($hay, $w);
            }
        }
        unset($r);
        usort($rows, static fn($a, $b) => $b['score'] <=> $a['score']);
        $out = [];
        foreach ($rows as $r) {
            if (count($out) >= $limit) {
                break;
            }
            if ($r['score'] > 0 || count($out) < 2) {
                $out[] = ['title' => $r['title'], 'text' => mb_substr(strip_tags($r['text']), 0, 2500), 'ref' => $r['ref']];
            }
        }
        return $out;
    }

    /**
     * Search the web and open the best pages.
     * @return array{ok:bool,error:?string,pages:list<array{title:string,url:string,text:string}>}
     */
    public static function web(int $agentId, string $query, int $open = 4): array
    {
        $provider = ResearchProviderFactory::make();
        if (!$provider->isConfigured()) {
            return ['ok' => false, 'error' => 'Web research is not set up (no Tavily key in the sales agent\'s Settings).', 'pages' => []];
        }
        $limit = (int)SmEvent::settings($agentId)['research_limit'];
        if (SmRadar::researchUsed($agentId) >= $limit) {
            return ['ok' => false, 'error' => 'This month\'s limit of ' . $limit . ' web searches is reached. An admin can raise it under Event settings.', 'pages' => []];
        }
        $res = $provider->search($query, ['max_results' => 8, 'deep' => true]);
        db()->insert('sm_ai_usage', ['agent_id' => $agentId, 'kind' => 'research', 'purpose' => 'search', 'model' => $provider->name(), 'ok' => $res['ok'] ? 1 : 0]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)$res['error'], 'pages' => []];
        }
        $results = array_slice($res['results'], 0, $open);
        $texts = self::extract(array_column($results, 'url'));
        $pages = [];
        foreach ($results as $r) {
            $text = trim((string)($texts[$r['url']] ?? ''));
            if (mb_strlen($text) < 300) {
                continue;   // a page we could not read is never used as a source
            }
            $pages[] = ['title' => $r['title'], 'url' => $r['url'], 'text' => mb_substr($text, 0, 9000)];
        }
        return ['ok' => true, 'error' => $pages ? null : 'No readable pages were found for this topic.', 'pages' => $pages];
    }

    /** @return array<string,string> url => readable text */
    public static function extract(array $urls): array
    {
        $urls = array_values(array_filter($urls, static fn($u) => is_string($u) && preg_match('#^https://#i', $u)));
        if (!$urls) {
            return [];
        }
        if (self::$extractHook !== null) {
            return (self::$extractHook)($urls);
        }
        $out = [];
        $key = trim((string)Settings::get('research.tavily.api_key', ''));
        if ($key !== '') {
            $res = Http::request('POST', 'https://api.tavily.com/extract', [
                'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json',
            ], json_encode(['urls' => $urls, 'extract_depth' => 'basic']), 60);
            $data = Http::decode((string)($res['body'] ?? ''));
            foreach ((array)($data['results'] ?? []) as $row) {
                $u = (string)($row['url'] ?? '');
                $t = (string)($row['raw_content'] ?? '');
                if ($u !== '' && $t !== '') {
                    $out[$u] = $t;
                }
            }
        }
        foreach ($urls as $u) {
            if (!isset($out[$u])) {
                $r = Http::request('GET', $u, ['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; EventSocialAgent/1.0)'], null, 20);
                if (($r['status'] ?? 0) === 200) {
                    $out[$u] = self::readable((string)$r['body']);
                }
            }
        }
        return $out;
    }

    public static function readable(string $html): string
    {
        $html = preg_replace('#<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], "\n", $html)), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n\s*\n+/', "\n", $text) ?? $text) ?? $text);
    }
}
