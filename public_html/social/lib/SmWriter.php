<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * What the AI is asked to write, and the rules it writes under. The rules are
 * the same ones the 46 Build Asia starter articles were written and checked by
 * (tools/blog-content-pack/WRITER-BRIEF.md).
 */
final class SmWriter
{
    public const PLATFORM_RULES = [
        'linkedin'  => 'LinkedIn company page: decision-makers and professionals. 120–220 words, a strong first line, short paragraphs, a business angle, a clear call to read more. 3–5 hashtags at the end.',
        'facebook'  => 'Facebook page: a wider audience. 60–120 words, conversational and warm, one question or invitation to engage. 1–3 hashtags.',
        'instagram' => 'Instagram: visual-first. The caption carries the story in 80–150 words, an emoji or two at most, line breaks for reading. Links do not work in captions. 8–15 relevant hashtags at the end.',
        'x'         => 'X: short and news-like. At most 240 characters including hashtags (the link is added separately and counts as 23). 1–2 hashtags.',
    ];

    public const LIMITS = ['linkedin' => 3000, 'facebook' => 5000, 'instagram' => 2200, 'x' => 280];

    private static function base(array $agent): string
    {
        $f = SmEvent::facts($agent);
        $s = SmEvent::settings((int)$agent['id']);
        return "You write for {$f['name']}" . ($f['tagline'] !== '' ? " ({$f['tagline']})" : '') . ', an international exhibition organised in Pakistan.'
            . "\nEvent facts (the only facts about the event you may use): "
            . ($f['dates'] !== '' ? "dates {$f['dates']}; " : 'dates not announced; ')
            . ($f['venue'] !== '' ? "venue {$f['venue']}; " : '')
            . ($f['website'] !== '' ? "website {$f['website']}; " : '')
            . ($f['register'] !== '' ? "register to visit {$f['register']}; " : '')
            . ($f['stand'] !== '' ? "book a stand {$f['stand']}." : '')
            . "\nReaders: {$s['audience']}"
            . ($s['sectors'] !== '' ? "\nSectors this event covers: {$s['sectors']}" : '')
            . "\nVoice: {$s['voice']}"
            . ($s['avoid'] !== '' ? "\nNever use these words or claims: {$s['avoid']}" : '')
            . "\nWrite in British English. Plain words, specific facts, no hype, no exclamation marks, no emoji in articles."
            . ($s['allow_prices'] ? '' : "\nNever mention stand, stall, sponsorship or ticket prices.");
    }

    /** Ask for topic suggestions for the content plan. */
    public static function topics(array $agent, int $count, ?string $pillar = null): array
    {
        $aid = (int)$agent['id'];
        $s = SmEvent::settings($aid);
        $phase = SmEvent::phase($agent);
        $recent = array_column(db()->all('SELECT title FROM sm_articles WHERE agent_id = ? ORDER BY id DESC LIMIT 40', [$aid]), 'title');
        $recent = array_merge($recent, array_column(db()->all("SELECT title FROM sm_topics WHERE agent_id = ? AND status IN ('suggested','picked') ORDER BY id DESC LIMIT 40", [$aid]), 'title'));
        $knowledge = array_column(db()->all("SELECT COALESCE(approved_version_title, title) t FROM knowledge_articles WHERE agent_id = ? AND active = 1 AND status = 'approved' ORDER BY id DESC LIMIT 40", [$aid]), 't');
        $mix = [];
        foreach (SmEvent::PILLARS as $k => [$label, $desc]) {
            if ($pillar !== null && $pillar !== $k) {
                continue;
            }
            $mix[] = "- $k ($label, share {$s['mix'][$k]}%): $desc";
        }
        $system = self::base($agent) . "\n\nYou plan the content calendar. Suggest specific, useful topics that serve the readers and the industry, raise the exhibition's standing, and lead readers naturally towards visiting or exhibiting."
            . "\nThe event is now in this phase: {$phase['label']}. Types that should lead now: " . implode(', ', $phase['lead']) . '.'
            . "\nContent types:\n" . implode("\n", $mix)
            . "\nNever repeat or closely echo a recent title. For 'opportunity' topics, frame the opportunity through facts (demand, gaps, incentives, partners already active) — never an explicit foreign-investment pitch."
            . "\nFor 'spotlight' and 'voices' only suggest a topic when the knowledge list names a real exhibitor, partner or speaker; never invent names."
            . "\nReturn: {\"topics\":[{\"pillar\":\"<type key>\",\"title\":\"working title, under 90 characters\",\"angle\":\"two sentences: the specific angle and what the reader gains\",\"search\":\"a web search query to research it\"}]}";
        $user = "Suggest $count topics" . ($pillar ? " of type $pillar" : ', spread across the types according to the shares and the phase') . '.'
            . "\n\nApproved knowledge titles for this event:\n- " . (implode("\n- ", array_slice($knowledge, 0, 40)) ?: '(none yet)')
            . "\n\nRecent titles to avoid repeating:\n- " . (implode("\n- ", array_slice($recent, 0, 60)) ?: '(none yet)');
        return SmAi::json($agent, 'topics', $system, $user, 2500);
    }

    /**
     * Write a blog article from numbered sources.
     * $sources: list<array{title,url?,text,ref?}> — knowledge first, then web pages.
     */
    public static function article(array $agent, array $topic, array $sources, array $links, array $categories): array
    {
        $f = SmEvent::facts($agent);
        $pillar = (string)$topic['pillar'];
        $extra = match ($pillar) {
            'opportunity' => "\nThis is a growth-sector article. Show the opportunity through facts only: scale of demand, gaps (imports, idle capacity, under-served needs), policies and incentives, and partners or international firms already active. Add a short section such as 'Where the openings are'. Add one honest 'what to watch' line on risks or delays named in the sources. Never write 'invest in Pakistan', never address foreign investors directly, never promise returns, no political claims.",
            'curated'     => "\nThis is curated news: summarise the source in your own words, add the industry's perspective and why it matters to readers, quote at most one short phrase in quotation marks, and always credit and link the original. Never copy sentences.",
            'news', 'visitor' => "\nUse only the event facts and approved knowledge for anything about the event itself.",
            default       => '',
        };
        $closing = "\nEnd with one natural line connecting the subject to {$f['name']}"
            . ($f['dates'] !== '' ? " ({$f['dates']}" . ($f['venue'] !== '' ? ", {$f['venue']}" : '') . ')' : '')
            . ', where the people in this field meet — linking to the registration or stand-booking page where it fits. Do not overdo it.';
        $numbered = [];
        foreach (array_values($sources) as $i => $src) {
            $numbered[] = '[' . ($i + 1) . '] ' . ($src['title'] ?? '') . (!empty($src['url']) ? ' — ' . $src['url'] : ' — ' . ($src['ref'] ?? 'approved knowledge'))
                . "\n" . mb_substr((string)$src['text'], 0, 7000);
        }
        $allowed = array_values(array_unique(array_filter(array_merge(
            array_column($links, 'url'),
            [$f['register'], $f['stand'], $f['website']],
            array_values(array_filter(array_column($sources, 'url')))
        ))));
        $system = self::base($agent)
            . "\n\nYou write one blog article for the event's own blog."
            . "\nHard rules:"
            . "\n1. Every number, name, date and quote must come from the numbered sources below. If a fact is not in them, leave it out. Do not use memory for figures."
            . "\n2. Make each fact's date clear in the text (for example 'in FY26', 'as of June 2026')."
            . "\n3. Never copy sentences from a source; summarise in your own words."
            . "\n4. Links inside the text may only point to these addresses: " . implode(' ', $allowed)
            . "\n5. 350–650 words of body text, at least two <h2> subheadings, a table or list where it helps the reader. Allowed tags: p, h2, h3, ul, ol, li, strong, em, a, blockquote, table, thead, tbody, tr, th, td."
            . "\n6. Separate facts from observations clearly ('In our view…')."
            . $extra . $closing
            . "\nCategory must be one of: " . implode(', ', array_map(static fn($c) => (string)($c['slug'] ?? $c), $categories ?: [['slug' => 'news']]))
            . "\nReturn: {\"title\":\"under 90 characters, specific\",\"excerpt\":\"one or two sentences, at most 220 characters\",\"body\":\"the HTML\",\"category\":\"slug\",\"tags\":[\"2 or 3 short tags\"],\"sources_used\":[numbers of the sources you actually used],\"seo_title\":\"under 60 characters\",\"meta_description\":\"120–155 characters\",\"image_headline\":\"under 60 characters for the cover image\"}";
        $user = 'Topic: ' . $topic['title'] . "\nAngle: " . ($topic['angle'] ?? '') . "\n\nSources:\n\n" . implode("\n\n", $numbered);
        return SmAi::json($agent, 'article', $system, $user, 6000);
    }

    /** A second, independent pass: does the article say more than its sources? */
    public static function factCheck(array $agent, array $article, array $sources): array
    {
        $numbered = [];
        foreach (array_values($sources) as $i => $src) {
            $numbered[] = '[' . ($i + 1) . '] ' . ($src['title'] ?? '') . "\n" . mb_substr((string)$src['text'], 0, 7000);
        }
        $system = 'You are a strict fact-checker for a trade-exhibition blog. You did not write the article. '
            . 'Compare every number, name, date, percentage, quote and claim in the article with the numbered sources. '
            . 'Flag anything not supported, stated more strongly than the source, out of date, or presented as decided when the source says proposed. '
            . 'Also flag hype, promised returns, political claims, and sentences that look copied from a source. '
            . 'Return: {"verdict":"ok" or "issues","issues":[{"quote":"the exact words from the article","problem":"what is wrong, in plain words","fix":"suggested correction or \'remove\'"}]}';
        $user = "Article title: {$article['title']}\n\nArticle:\n" . strip_tags(str_replace(['</p>', '</li>', '</h2>'], "\n", (string)$article['body']))
            . "\n\nSources:\n\n" . implode("\n\n", $numbered);
        return SmAi::json($agent, 'fact_check', $system, $user, 2500);
    }

    /** One version of the published article for each platform. */
    public static function versions(array $agent, array $article, array $platforms): array
    {
        $s = SmEvent::settings((int)$agent['id']);
        $rules = [];
        foreach ($platforms as $p => $row) {
            $tags = trim((string)($row['hashtags'] ?? ''));
            $rules[] = "- $p: " . self::PLATFORM_RULES[$p] . ($tags !== '' ? " Always include these hashtags: $tags." : '');
        }
        $sponsored = '';
        $system = self::base($agent)
            . "\n\nThe article below has been approved and published on the event's blog. Write a separate post for each platform, each written for that platform's audience — not a plain 'read our new blog post'."
            . "\nUse only facts that are in the article. The link to the article is added by the system: do not write any URL."
            . "\nPlatforms:\n" . implode("\n", $rules) . $sponsored
            . "\nReturn: {\"posts\":{\"<platform>\":{\"text\":\"the post without hashtags\",\"hashtags\":\"#one #two\"}},\"image_headline\":\"under 60 characters for the post image\"}";
        $user = "Title: {$article['title']}\nSummary: {$article['excerpt']}\n\nArticle:\n" . mb_substr(strip_tags(str_replace(['</p>', '</li>', '</h2>'], "\n", (string)$article['body'])), 0, 9000);
        return SmAi::json($agent, 'versions', $system, $user, 3000);
    }

    /** A post with no article behind it (reminders, show-day photos, announcements). */
    public static function standalone(array $agent, string $brief, array $platforms): array
    {
        $rules = [];
        foreach ($platforms as $p => $row) {
            $tags = trim((string)($row['hashtags'] ?? ''));
            $rules[] = "- $p: " . self::PLATFORM_RULES[$p] . ($tags !== '' ? " Always include these hashtags: $tags." : '');
        }
        $system = self::base($agent)
            . "\n\nWrite one social media post per platform from the brief below. Use only the brief, the event facts and nothing else. Do not write any URL."
            . "\nPlatforms:\n" . implode("\n", $rules)
            . "\nReturn: {\"posts\":{\"<platform>\":{\"text\":\"…\",\"hashtags\":\"#one #two\"}},\"image_headline\":\"under 60 characters\"}";
        return SmAi::json($agent, 'standalone', $system, 'Brief: ' . $brief, 2500);
    }
}
