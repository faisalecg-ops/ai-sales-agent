<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * The weekly industry radar: a few web searches about the event's sectors,
 * turned into suggested topics with their sources — new reports, big projects,
 * growing sectors and news worth sharing. Nothing is written from them until
 * a person picks one. News items can also be shared as "curated news" posts.
 */
final class SmRadar
{
    public const KINDS = ['news' => 'News', 'report' => 'Report', 'project' => 'Project', 'sector' => 'Growing sector'];

    /** Web searches used this month (each costs a research credit). */
    public static function researchUsed(int $agentId): int
    {
        return (int)db()->value("SELECT COUNT(*) FROM sm_ai_usage WHERE agent_id = ? AND kind = 'research' AND created_at >= ?", [$agentId, gmdate('Y-m-01 00:00:00')]);
    }

    /** @return list<string> */
    public static function queries(array $agent): array
    {
        $s = SmEvent::settings((int)$agent['id']);
        $f = SmEvent::facts($agent);
        $country = 'Pakistan';
        if (preg_match('/,\s*([^,]+)$/', $f['venue'], $m)) {
            $country = trim($m[1]);
        }
        $sectors = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string)$s['sectors']) ?: [])));
        if (!$sectors) {
            $sectors = [$f['name'] . ' industry'];
        }
        $year = gmdate('Y');
        return [
            implode(' ', array_slice($sectors, 0, 3)) . " $country news this week",
            ($sectors[0] ?? '') . " $country new project announced $year",
            ($sectors[1] ?? $sectors[0]) . " $country market report $year growth",
        ];
    }

    /**
     * Run it for one exhibition.
     * @return array{ok:bool, made:int, error?:string}
     */
    public static function run(array $agent): array
    {
        $aid = (int)$agent['id'];
        $s = SmEvent::settings($aid);
        $queries = self::queries($agent);
        if (self::researchUsed($aid) + count($queries) > (int)$s['research_limit']) {
            return ['ok' => false, 'made' => 0, 'error' => 'This month\'s limit of ' . $s['research_limit'] . ' web searches is reached. An admin can raise it under Event settings.'];
        }
        if (($why = SmAi::blocked($aid, 'text')) !== null) {
            return ['ok' => false, 'made' => 0, 'error' => $why];
        }
        $provider = ResearchProviderFactory::make();
        if (!$provider->isConfigured()) {
            return ['ok' => false, 'made' => 0, 'error' => 'Web research is not set up (no Tavily key in the sales agent\'s Settings).'];
        }
        $found = [];
        foreach ($queries as $q) {
            $res = $provider->search($q, ['max_results' => 8, 'topic' => 'news']);
            db()->insert('sm_ai_usage', ['agent_id' => $aid, 'kind' => 'research', 'purpose' => 'radar', 'model' => $provider->name(), 'ok' => $res['ok'] ? 1 : 0]);
            foreach ((array)($res['results'] ?? []) as $r) {
                $u = (string)($r['url'] ?? '');
                if (preg_match('#^https://#i', $u) && !isset($found[$u])) {
                    $found[$u] = ['title' => (string)($r['title'] ?? ''), 'url' => $u, 'content' => mb_substr((string)($r['content'] ?? ''), 0, 800)];
                }
            }
        }
        // Skip what we already suggested.
        $known = array_flip(array_filter(array_map('trim', explode("\n", implode("\n", array_column(
            db()->all("SELECT source_urls FROM sm_topics WHERE agent_id = ? AND origin = 'radar'", [$aid]), 'source_urls'))))));
        $found = array_values(array_filter($found, static fn($r) => !isset($known[$r['url']])));
        if (!$found) {
            db()->update('sm_events', ['radar_ran_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [$aid]);
            return ['ok' => true, 'made' => 0];
        }
        $list = '';
        foreach (array_slice($found, 0, 24) as $i => $r) {
            $list .= "[$i] {$r['title']}\n{$r['url']}\n{$r['content']}\n\n";
        }
        $f = SmEvent::facts($agent);
        $count = max(3, min(10, (int)$s['radar_count']));
        $system = "You scan industry news for {$f['name']}, an exhibition for these sectors: " . ($s['sectors'] ?: $f['name']) . '.'
            . "\nFrom the search results below, pick the $count most useful for the exhibition's readers: new reports, big projects, growing sectors, and news worth sharing."
            . "\nUse only what the results say. Each pick must name the result it comes from by its number. Skip adverts, old items and anything about competing exhibitions."
            . "\nkind: news | report | project | sector. pillar: insight | opportunity | news | curated (curated = share someone else's article with our comment)."
            . "\nReturn: {\"items\":[{\"n\":0,\"kind\":\"news\",\"pillar\":\"curated\",\"title\":\"a working title for us, under 90 characters\",\"summary\":\"two sentences: what it says and why it matters to our readers\",\"source_name\":\"publication name\"}]}";
        $out = SmAi::json($agent, 'radar', $system, "Search results:\n\n" . $list, 2500);
        if (isset($out['_error'])) {
            return ['ok' => false, 'made' => 0, 'error' => (string)$out['_error']];
        }
        $made = 0;
        foreach ((array)($out['items'] ?? []) as $it) {
            $r = $found[(int)($it['n'] ?? -1)] ?? null;
            $title = trim((string)($it['title'] ?? ''));
            $pillar = (string)($it['pillar'] ?? 'insight');
            if (!$r || $title === '' || $made >= $count) {
                continue;
            }
            db()->insert('sm_topics', [
                'agent_id' => $aid, 'pillar' => isset(SmEvent::PILLARS[$pillar]) ? $pillar : 'insight', 'title' => mb_substr($title, 0, 255),
                'angle' => mb_substr(trim((string)($it['summary'] ?? '')), 0, 2000), 'origin' => 'radar', 'source_urls' => $r['url'],
                'radar_kind' => isset(self::KINDS[$it['kind'] ?? '']) ? (string)$it['kind'] : 'news',
                'source_name' => mb_substr(trim((string)($it['source_name'] ?? '')) ?: (string)parse_url($r['url'], PHP_URL_HOST), 0, 160),
            ]);
            $made++;
        }
        db()->update('sm_events', ['radar_ran_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [$aid]);
        SmActivity::log($aid, 'radar', $made . ' item(s) found', ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
        return ['ok' => true, 'made' => $made];
    }

    /** Cron: each exhibition once a week, on its chosen day (from 6 am in its time zone). */
    public static function runDue(): int
    {
        $n = 0;
        foreach (db()->all("SELECT e.agent_id, e.radar_ran_at FROM sm_events e JOIN agents g ON g.id = e.agent_id AND g.status <> 'deleted'") as $r) {
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$r['agent_id']]);
            $s = SmEvent::settings((int)$r['agent_id']);
            if (!$agent || !$s['radar_enabled']) {
                continue;
            }
            $local = new DateTimeImmutable('now', SmEvent::tz($agent));
            if ((int)$local->format('N') !== (int)$s['radar_weekday'] || (int)$local->format('G') < 6) {
                continue;
            }
            if ($r['radar_ran_at'] && strtotime($r['radar_ran_at'] . ' UTC') > time() - 5 * 86400) {
                continue;
            }
            $res = self::run($agent);
            db()->update('sm_events', ['radar_ran_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [(int)$r['agent_id']]);
            $n += $res['made'];
        }
        return $n;
    }

    /**
     * Share a radar item as curated news: posts for each platform, in our own
     * words with credit and the original's link. The original is opened first;
     * an article we cannot read is never shared.
     * @return array{ok:bool, message:string}
     */
    public static function share(array $agent, array $topic, int $userId): array
    {
        $aid = (int)$agent['id'];
        $url = trim(explode("\n", (string)$topic['source_urls'])[0] ?? '');
        if (!preg_match('#^https://#i', $url)) {
            return ['ok' => false, 'message' => 'This item has no source to share.'];
        }
        $platforms = SmEvent::platforms($aid, true);
        if (!$platforms) {
            return ['ok' => false, 'message' => 'No platform is switched on.'];
        }
        $text = trim((string)(SmSources::extract([$url])[$url] ?? ''));
        if (mb_strlen($text) < 300) {
            return ['ok' => false, 'message' => 'The original article could not be read, so it is not shared. Open it yourself, or pick another item.'];
        }
        $out = SmWriter::curated($agent, $topic, $url, mb_substr($text, 0, 9000), $platforms);
        if (isset($out['_error'])) {
            return ['ok' => false, 'message' => (string)$out['_error']];
        }
        $res = SmFlow::makeFromOutput($agent, $out, $platforms, $userId, $url);
        if ($res['ok']) {
            db()->update('sm_topics', ['status' => 'used'], 'id = ?', [(int)$topic['id']]);
            SmActivity::log($aid, 'curated_shared', (string)$topic['title']);
        }
        return ['ok' => $res['ok'], 'message' => $res['ok'] ? $res['message'] . ' Each credits ' . ($topic['source_name'] ?: 'the source') . ' and links to the original.' : $res['message']];
    }
}
