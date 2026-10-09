<?php
declare(strict_types=1);

/**
 * Knowledge retrieval (Section 25). Lightweight and index-friendly, so it
 * runs comfortably inside a normal PHP request on shared hosting.
 *
 * Order: exact FAQ match -> category/stage relevance -> keyword scoring.
 * Only approved, active content belonging to this agent is ever returned.
 */
final class KnowledgeRetriever
{
    /**
     * Words carrying no search value. Question words such as "when", "where"
     * and "how" are deliberately NOT here: in a set of FAQs they are often
     * the most discriminating term in the whole question.
     */
    private const STOP_WORDS = [
        'the','a','an','and','or','but','if','of','to','in','on','for','with','is','are','was','were',
        'be','been','do','does','did','can','could','would','should','will','shall','i','we','you',
        'they','it','this','that','these','those','my','our','your','their',
        'please','tell','me','about','have','has','had','get','got','want','need',
        'there','here','from','at','as','by','so','not','no','yes','ok','okay','hi','hello','thanks',
    ];

    /**
     * @return array{faqs:array,articles:array,used_terms:string[]}
     */
    public static function retrieve(int $agentId, string $query, ?string $stage = null, int $maxArticles = 4, int $maxFaqs = 3): array
    {
        $terms = self::terms($query);

        $faqs     = self::matchFaqs($agentId, $query, $terms, $maxFaqs);
        $articles = self::matchArticles($agentId, $terms, $stage, $maxArticles);

        return ['faqs' => $faqs, 'articles' => $articles, 'used_terms' => $terms];
    }

    /** Significant search terms from the visitor's message. */
    public static function terms(string $query): array
    {
        $q = mb_strtolower($query);
        $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q) ?? $q;
        $parts = preg_split('/\s+/u', trim($q)) ?: [];
        $terms = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, self::STOP_WORDS, true)) {
                continue;
            }
            $terms[$p] = true;
            if (count($terms) >= 12) {
                break;
            }
        }
        // Cast back to strings: PHP turns a purely numeric array key into an
        // integer, and a visitor typing a phone number produces exactly that.
        return array_map('strval', array_keys($terms));
    }

    /**
     * FAQ matching. A near-exact question match short-circuits everything else.
     */
    private static function matchFaqs(int $agentId, string $query, array $terms, int $limit): array
    {
        $rows = db()->all(
            "SELECT id, question, answer, keywords, category FROM faq_entries
             WHERE agent_id = ? AND status = 'approved' AND active = 1
             LIMIT 400",
            [$agentId]
        );
        if (!$rows) {
            return [];
        }

        $normQuery = self::normalise($query);
        $scored    = [];

        foreach ($rows as $row) {
            $score    = 0.0;
            $normQ    = self::normalise((string)$row['question']);
            $haystack = mb_strtolower($row['question'] . ' ' . $row['keywords'] . ' ' . $row['category']);

            if ($normQ !== '' && $normQ === $normQuery) {
                $score += 100;
            } elseif ($normQ !== '' && (str_contains($normQuery, $normQ) || str_contains($normQ, $normQuery))) {
                $score += 25;
            }

            foreach ($terms as $t) {
                if (str_contains($haystack, $t)) {
                    $score += 3;
                }
                if (str_contains(mb_strtolower((string)$row['keywords']), $t)) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        return array_map(static fn($s) => [
            'id'       => (int)$s['row']['id'],
            'question' => (string)$s['row']['question'],
            'answer'   => (string)$s['row']['answer'],
            'score'    => $s['score'],
        ], $top);
    }

    /**
     * Article matching by stage tag, category and keyword overlap.
     */
    private static function matchArticles(int $agentId, array $terms, ?string $stage, int $limit): array
    {
        $rows = db()->all(
            "SELECT id, title, category, body, keywords, stage_tags FROM knowledge_articles
             WHERE agent_id = ? AND status = 'approved' AND active = 1
             LIMIT 400",
            [$agentId]
        );
        if (!$rows) {
            return [];
        }

        $stageKey = $stage !== null ? mb_strtolower($stage) : '';
        $scored   = [];

        foreach ($rows as $row) {
            $score    = 0.0;
            $title    = mb_strtolower((string)$row['title']);
            $keywords = mb_strtolower((string)$row['keywords']);
            $category = mb_strtolower((string)$row['category']);
            $body     = mb_strtolower(mb_substr((string)$row['body'], 0, 3000));
            $stages   = mb_strtolower((string)$row['stage_tags']);

            if ($stageKey !== '' && $stages !== '' && str_contains($stages, $stageKey)) {
                $score += 6;
            }

            foreach ($terms as $t) {
                if (str_contains($title, $t)) {
                    $score += 6;
                }
                if (str_contains($keywords, $t)) {
                    $score += 4;
                }
                if (str_contains($category, $t)) {
                    $score += 3;
                }
                if (str_contains($body, $t)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        return array_map(static fn($s) => [
            'id'       => (int)$s['row']['id'],
            'title'    => (string)$s['row']['title'],
            'category' => (string)$s['row']['category'],
            'body'     => self::trimBody((string)$s['row']['body']),
            'score'    => $s['score'],
        ], $top);
    }

    /** Keep prompt size predictable. */
    private static function trimBody(string $body, int $max = 1500): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        if (mb_strlen($body) <= $max) {
            return $body;
        }
        return mb_substr($body, 0, $max) . '...';
    }

    private static function normalise(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * Does the agent hold any approved knowledge at all? Used to warn the
     * administrator before the agent is exposed to visitors.
     */
    public static function approvedCount(int $agentId): array
    {
        return [
            'articles' => (int)db()->value(
                "SELECT COUNT(*) FROM knowledge_articles WHERE agent_id = ? AND status = 'approved' AND active = 1",
                [$agentId]
            ),
            'faqs' => (int)db()->value(
                "SELECT COUNT(*) FROM faq_entries WHERE agent_id = ? AND status = 'approved' AND active = 1",
                [$agentId]
            ),
        ];
    }
}
