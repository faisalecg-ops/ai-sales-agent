<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Stage 4 — results. What each post brought:
 *
 *   post → likes, comments, shares, views   (read from the platform, or typed in)
 *        → visits to the blog               (the blog's GET /stats, tagged links)
 *        → chats with the assistant         (sales agent `conversations.utm_*`, read only)
 *        → leads                            (sales agent `leads`, read only)
 *
 * and the monthly management report built from it, emailed to the addresses
 * in Event settings → Monthly report.
 *
 * Figures are read 1, 7 and 28 days after a post goes out automatically.
 * X charges for every post read, so X reads stop at the monthly limit.
 */
final class SmStats
{
    public const CHECK_DAYS = [1, 7, 28];
    /** X pay-per-use prices (checked 12 Oct 2026), for the cost estimate only. */
    public const X_PRICE_READ = 0.005;
    public const X_PRICE_POST = 0.015;
    public const X_PRICE_LINK_POST = 0.20;
    public const LEAD_KINDS = ['exhibit' => 'Exhibiting or sponsoring', 'visit' => 'Visiting', 'other' => 'Not yet known'];

    // -----------------------------------------------------------------
    // Likes, comments, shares, views
    // -----------------------------------------------------------------

    /** X post figures read this month. */
    public static function xReadsUsed(int $agentId): int
    {
        return (int)db()->value("SELECT COALESCE(SUM(items), 0) FROM sm_stat_reads WHERE agent_id = ? AND platform = 'x' AND created_at >= ?", [$agentId, gmdate('Y-m-01 00:00:00')]);
    }

    public static function forPost(int $postId): ?array
    {
        return db()->one('SELECT * FROM sm_post_stats WHERE post_id = ?', [$postId]);
    }

    /** Cron: read the figures of posts whose next check has come. */
    public static function collectDue(): int
    {
        $now = time();
        $rows = db()->all("SELECT p.* FROM sm_posts p LEFT JOIN sm_post_stats s ON s.post_id = p.id
                           JOIN agents g ON g.id = p.agent_id AND g.status <> 'deleted'
                           WHERE p.status = 'posted' AND p.remote_id IS NOT NULL AND p.remote_id <> '' AND p.posted_at >= ?
                             AND ((s.post_id IS NULL AND p.posted_at <= ?) OR (s.source = 'auto' AND s.next_check_at IS NOT NULL AND s.next_check_at <= ?))
                           ORDER BY p.agent_id, p.platform, p.posted_at LIMIT 300",
            [gmdate('Y-m-d H:i:s', $now - 40 * 86400), gmdate('Y-m-d H:i:s', $now - 86400), gmdate('Y-m-d H:i:s', $now)]);
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['agent_id'] . '|' . $r['platform']][] = $r;
        }
        $n = 0;
        foreach ($groups as $posts) {
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$posts[0]['agent_id']]);
            if ($agent) {
                $n += self::collect($agent, (string)$posts[0]['platform'], $posts, true)['updated'];
            }
        }
        return $n;
    }

    /**
     * Read the figures of some posts on one platform.
     * $scheduled: true from cron (moves each post to its next check), false for "Check now".
     * @return array{ok:bool, updated:int, message:string}
     */
    public static function collect(array $agent, string $platform, array $posts, bool $scheduled): array
    {
        $aid = (int)$agent['id'];
        $posts = array_values(array_filter($posts, static fn($p) => (string)($p['remote_id'] ?? '') !== ''));
        if (!$posts) {
            return ['ok' => false, 'updated' => 0, 'message' => 'This post was not posted automatically, so its figures are typed in.'];
        }
        $postpone = static function (array $list, string $why) use ($aid, $scheduled): void {
            foreach ($list as $p) {
                self::ensureRow($p);
                db()->update('sm_post_stats', ['last_error' => mb_substr($why, 0, 300)]
                    + ($scheduled ? ['next_check_at' => gmdate('Y-m-d H:i:s', time() + 86400)] : []), 'post_id = ?', [(int)$p['id']]);
            }
        };
        if ($platform === 'x') {
            $left = (int)SmEvent::settings($aid)['x_read_limit'] - self::xReadsUsed($aid);
            if ($left <= 0) {
                $why = 'This month\'s limit of X figure reads is reached (X charges for each one). An admin can raise it under Blog and platforms.';
                $postpone($posts, $why);
                return ['ok' => false, 'updated' => 0, 'message' => $why];
            }
            $later = array_slice($posts, $left);
            $posts = array_slice($posts, 0, $left);
            if ($later) {
                $postpone($later, 'Waiting: this month\'s limit of X figure reads is reached.');
            }
        }
        $t = SmConnect::token($aid, $platform);
        if (!$t['ok']) {
            $postpone($posts, (string)$t['error']);
            return ['ok' => false, 'updated' => 0, 'message' => (string)$t['error']];
        }
        $res = match ($platform) {
            'facebook'  => self::readFacebook($t, $posts),
            'instagram' => self::readInstagram($t, $posts),
            'linkedin'  => self::readLinkedin($t, $posts),
            'x'         => self::readX($aid, $t, $posts),
            default     => ['figures' => [], 'error' => 'Unknown platform.'],
        };
        $updated = 0;
        foreach ($posts as $p) {
            $f = $res['figures'][(int)$p['id']] ?? null;
            if ($f === null) {
                $postpone([$p], $res['error'] !== '' ? $res['error'] : 'The platform did not return figures for this post (it may have been deleted there).');
                continue;
            }
            self::save($p, $f, 'auto', null, $scheduled);
            $updated++;
        }
        return ['ok' => $updated > 0, 'updated' => $updated,
            'message' => $updated > 0 ? 'Figures updated.' : ($res['error'] !== '' ? $res['error'] : 'No figures came back.')];
    }

    private static function ensureRow(array $p): void
    {
        db()->exec('INSERT IGNORE INTO sm_post_stats (post_id, agent_id, source) VALUES (?, ?, ?)',
            [(int)$p['id'], (int)$p['agent_id'], (string)($p['remote_id'] ?? '') !== '' ? 'auto' : 'typed']);
    }

    /** @param array{likes?:?int,comments?:?int,shares?:?int,views?:?int,clicks?:?int} $f */
    public static function save(array $p, array $f, string $source, ?int $userId = null, bool $scheduled = false): void
    {
        self::ensureRow($p);
        $row = self::forPost((int)$p['id']) ?? ['checks' => 0];
        $num = static fn($v) => $v === null || $v === '' ? null : max(0, min(4000000000, (int)$v));
        $data = [
            'likes' => $num($f['likes'] ?? null), 'comments' => $num($f['comments'] ?? null), 'shares' => $num($f['shares'] ?? null),
            'views' => $num($f['views'] ?? null), 'clicks' => $num($f['clicks'] ?? null),
            'source' => $source, 'checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null,
        ];
        if ($source === 'typed') {
            $data['typed_by'] = $userId;
            $data['next_check_at'] = null;
        } elseif ($scheduled) {
            $checks = (int)$row['checks'] + 1;
            $base = strtotime((string)$p['posted_at'] . ' UTC') ?: time();
            $next = null;
            foreach (self::CHECK_DAYS as $d) {
                if ($base + $d * 86400 > time() + 3600) {
                    $next = gmdate('Y-m-d H:i:s', $base + $d * 86400);
                    break;
                }
            }
            $data['checks'] = min(255, $checks);
            $data['next_check_at'] = $next;
        }
        db()->update('sm_post_stats', $data, 'post_id = ?', [(int)$p['id']]);
    }

    /** Figures typed in for a post made by hand. */
    public static function typed(array $p, array $in, int $userId): void
    {
        $f = [];
        foreach (['likes', 'comments', 'shares', 'views', 'clicks'] as $k) {
            $v = trim((string)($in[$k] ?? ''));
            $f[$k] = $v === '' ? null : (int)preg_replace('/\D/', '', $v);
        }
        self::save($p, $f, 'typed', $userId);
    }

    private static function readFacebook(array $t, array $posts): array
    {
        $g = 'https://graph.facebook.com/' . SmApps::graphVersion();
        $out = [];
        $err = '';
        foreach ($posts as $p) {
            $r = SmNet::request('GET', $g . '/' . rawurlencode((string)$p['remote_id']) . '?' . http_build_query([
                'fields' => 'shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)', 'access_token' => $t['token']]));
            if ($r['status'] !== 200 || !is_array($r['json'])) {
                $err = 'Facebook did not give the figures. ' . SmNet::reason($r);
                continue;
            }
            $out[(int)$p['id']] = ['likes' => (int)($r['json']['reactions']['summary']['total_count'] ?? 0),
                'comments' => (int)($r['json']['comments']['summary']['total_count'] ?? 0), 'shares' => (int)($r['json']['shares']['count'] ?? 0)];
        }
        return ['figures' => $out, 'error' => $err];
    }

    private static function readInstagram(array $t, array $posts): array
    {
        $g = 'https://graph.facebook.com/' . SmApps::graphVersion();
        $out = [];
        $err = '';
        foreach ($posts as $p) {
            $r = SmNet::request('GET', $g . '/' . rawurlencode((string)$p['remote_id']) . '?' . http_build_query(['fields' => 'like_count,comments_count', 'access_token' => $t['token']]));
            if ($r['status'] !== 200 || !is_array($r['json'])) {
                $err = 'Instagram did not give the figures. ' . SmNet::reason($r);
                continue;
            }
            $out[(int)$p['id']] = ['likes' => (int)($r['json']['like_count'] ?? 0), 'comments' => (int)($r['json']['comments_count'] ?? 0)];
        }
        return ['figures' => $out, 'error' => $err];
    }

    /** LinkedIn's page statistics for chosen posts (lifetime, the last 12 months; posts with no activity are left out = zero). */
    private static function readLinkedin(array $t, array $posts): array
    {
        $org = 'urn:li:organization:' . $t['conn']['account_id'];
        $byUrn = [];
        foreach ($posts as $p) {
            $byUrn[(string)$p['remote_id']] = (int)$p['id'];
        }
        $out = [];
        $err = '';
        foreach (['shares' => 'urn:li:share:', 'ugcPosts' => 'urn:li:ugcPost:'] as $param => $prefix) {
            $urns = array_values(array_filter(array_keys($byUrn), static fn($u) => str_starts_with($u, $prefix)));
            foreach (array_chunk($urns, 20) as $chunk) {
                $url = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($org)
                     . '&' . $param . '=List(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
                $r = SmNet::request('GET', $url, SmConnect::linkedinHeaders($t['token']));
                if ($r['status'] !== 200 || !is_array($r['json'])) {
                    $err = 'LinkedIn did not give the figures. ' . SmNet::reason($r);
                    continue;
                }
                foreach ($chunk as $u) {
                    $out[$byUrn[$u]] = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'views' => 0, 'clicks' => 0];
                }
                foreach ((array)($r['json']['elements'] ?? []) as $el) {
                    $u = (string)($el['share'] ?? $el['ugcPost'] ?? '');
                    $s = (array)($el['totalShareStatistics'] ?? []);
                    if (isset($byUrn[$u])) {
                        $out[$byUrn[$u]] = ['likes' => max(0, (int)($s['likeCount'] ?? 0)), 'comments' => (int)($s['commentCount'] ?? 0),
                            'shares' => (int)($s['shareCount'] ?? 0), 'views' => (int)($s['impressionCount'] ?? 0), 'clicks' => (int)($s['clickCount'] ?? 0)];
                    }
                }
            }
        }
        if (count($out) < count($byUrn) && $err === '') {
            $err = 'LinkedIn did not recognise this post.';
        }
        return ['figures' => $out, 'error' => $err];
    }

    /** X: up to 100 posts per request; each post returned is charged, so it is counted. */
    private static function readX(int $aid, array $t, array $posts): array
    {
        $byId = [];
        foreach ($posts as $p) {
            $byId[(string)$p['remote_id']] = (int)$p['id'];
        }
        $out = [];
        $err = '';
        foreach (array_chunk(array_keys($byId), 100) as $chunk) {
            $r = SmNet::request('GET', 'https://api.x.com/2/tweets?' . http_build_query(['ids' => implode(',', $chunk), 'tweet.fields' => 'public_metrics']),
                ['Authorization' => 'Bearer ' . $t['token']]);
            $data = (array)($r['json']['data'] ?? []);
            if ($r['status'] !== 200 || !$data) {
                $err = 'X did not give the figures. ' . SmNet::reason($r);
                continue;
            }
            db()->insert('sm_stat_reads', ['agent_id' => $aid, 'platform' => 'x', 'items' => count($data)]);
            foreach ($data as $tw) {
                $m = (array)($tw['public_metrics'] ?? []);
                if (isset($byId[(string)($tw['id'] ?? '')])) {
                    $out[$byId[(string)$tw['id']]] = ['likes' => (int)($m['like_count'] ?? 0), 'comments' => (int)($m['reply_count'] ?? 0),
                        'shares' => (int)($m['retweet_count'] ?? 0) + (int)($m['quote_count'] ?? 0), 'views' => isset($m['impression_count']) ? (int)$m['impression_count'] : null];
                }
            }
        }
        return ['figures' => $out, 'error' => $err];
    }

    // -----------------------------------------------------------------
    // Visits to the blog (from the blog's own figures)
    // -----------------------------------------------------------------

    public static function monthRange(string $ym): array
    {
        $start = strtotime($ym . '-01 00:00:00 UTC') ?: strtotime(gmdate('Y-m-01') . ' UTC');
        return [gmdate('Y-m-d', $start), gmdate('Y-m-t', $start), gmdate('F Y', $start)];
    }

    public static function validMonth(string $ym): string
    {
        return preg_match('/^20\d\d-(0[1-9]|1[0-2])$/', $ym) ? $ym : gmdate('Y-m');
    }

    public static function lastMonth(): string
    {
        return gmdate('Y-m', strtotime('first day of last month', strtotime(gmdate('Y-m-15'))));
    }

    /** Fetch one month's figures from the blog and keep them. */
    public static function refreshBlog(array $agent, string $ym): array
    {
        $aid = (int)$agent['id'];
        $blog = SmBlog::forAgent($aid);
        if (!$blog) {
            return ['ok' => false, 'message' => 'No blog is connected.'];
        }
        [$from, $to] = self::monthRange($ym);
        $r = $blog->stats($from, $to, (string)$agent['slug']);
        if (!$r['ok']) {
            $msg = $r['status'] === 404 ? 'The blog is older than 1.4.0 and has no reading figures yet. Install the new blog package.' : $r['message'];
            db()->exec('INSERT INTO sm_blog_stats (agent_id, month, error) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE error = VALUES(error)', [$aid, $ym, mb_substr($msg, 0, 300)]);
            return ['ok' => false, 'message' => $msg];
        }
        $keep = ['views_total' => (int)($r['data']['views_total'] ?? 0),
            'articles' => array_slice((array)($r['data']['articles'] ?? []), 0, 200), 'sources' => array_slice((array)($r['data']['sources'] ?? []), 0, 2000)];
        db()->exec('INSERT INTO sm_blog_stats (agent_id, month, data, fetched_at, error) VALUES (?, ?, ?, ?, NULL)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), fetched_at = VALUES(fetched_at), error = NULL',
            [$aid, $ym, json_encode($keep, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
        return ['ok' => true, 'message' => 'Blog figures updated.'];
    }

    /** @return array{data:?array, fetched_at:?string, error:?string} */
    public static function blogMonth(int $aid, string $ym): array
    {
        $row = db()->one('SELECT * FROM sm_blog_stats WHERE agent_id = ? AND month = ?', [$aid, $ym]);
        $data = $row && $row['data'] ? json_decode((string)$row['data'], true) : null;
        return ['data' => is_array($data) ? $data : null, 'fetched_at' => $row['fetched_at'] ?? null, 'error' => $row['error'] ?? null];
    }

    /** Cron: this month's figures every 3 hours; last month's once more after it ends. */
    public static function refreshAllBlogs(): int
    {
        $n = 0;
        $cur = gmdate('Y-m');
        $prev = self::lastMonth();
        foreach (db()->all("SELECT e.agent_id FROM sm_events e JOIN agents g ON g.id = e.agent_id AND g.status <> 'deleted'") as $r) {
            $aid = (int)$r['agent_id'];
            if (!SmBlog::forAgent($aid)) {
                continue;
            }
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [$aid]);
            $c = db()->one('SELECT fetched_at, error FROM sm_blog_stats WHERE agent_id = ? AND month = ?', [$aid, $cur]);
            if (!$c || ($c['fetched_at'] === null && $c['error'] !== null) || strtotime((string)$c['fetched_at'] . ' UTC') < time() - 3 * 3600) {
                $n += self::refreshBlog($agent, $cur)['ok'] ? 1 : 0;
            }
            $p = db()->one('SELECT fetched_at FROM sm_blog_stats WHERE agent_id = ? AND month = ?', [$aid, $prev]);
            if (!$p || $p['fetched_at'] === null || $p['fetched_at'] < $cur . '-01 00:00:00') {
                $n += self::refreshBlog($agent, $prev)['ok'] ? 1 : 0;
            }
        }
        return $n;
    }

    // -----------------------------------------------------------------
    // Chats and leads (the sales agent's records, read only)
    // -----------------------------------------------------------------

    /** The sales agent records which link brought a chat (its October 2026 upgrade). */
    public static function chatsReady(): bool
    {
        static $ready = null;
        return $ready ??= Migrations::columnExists('conversations', 'utm_source');
    }

    public static function leadKind(?string $intent): string
    {
        return match ((string)$intent) {
            'exhibitor', 'sponsor', 'partner' => 'exhibit',
            'attendee' => 'visit',
            default => 'other',
        };
    }

    /** Chats that came through our links in a period. @return list<array> */
    public static function chats(int $aid, string $fromDay, string $toDay, ?string $content = null): array
    {
        if (!self::chatsReady()) {
            return [];
        }
        $sql = "SELECT c.id, c.lead_id, c.utm_source, c.utm_medium, c.utm_content, c.created_at, l.exhibitor_intent
                FROM conversations c LEFT JOIN leads l ON l.id = c.lead_id
                WHERE c.agent_id = ? AND c.utm_medium IN ('social', 'exhibitor-share', 'share') AND c.created_at BETWEEN ? AND ?";
        $params = [$aid, $fromDay . ' 00:00:00', $toDay . ' 23:59:59'];
        if ($content !== null) {
            $sql .= ' AND c.utm_content = ?';
            $params[] = $content;
        }
        return db()->all($sql, $params);
    }

    /** Everything a post brought, so far. */
    public static function postResults(array $agent, array $post): array
    {
        $aid = (int)$agent['id'];
        $key = 'post-' . (int)$post['id'];
        $visits = 0;
        foreach (db()->all('SELECT data FROM sm_blog_stats WHERE agent_id = ? AND data IS NOT NULL', [$aid]) as $row) {
            foreach ((array)(json_decode((string)$row['data'], true)['sources'] ?? []) as $s) {
                if (($s['content'] ?? '') === $key && ($s['medium'] ?? '') === 'social') {
                    $visits += (int)$s['visits'];
                }
            }
        }
        $chats = self::chats($aid, '2026-01-01', gmdate('Y-m-d'), $key);
        $leads = [];
        foreach ($chats as $c) {
            if ($c['lead_id'] !== null) {
                $leads[(int)$c['lead_id']] = self::leadKind($c['exhibitor_intent']);
            }
        }
        return ['stats' => self::forPost((int)$post['id']), 'visits' => $visits, 'chats' => count($chats), 'leads' => count($leads),
            'lead_kinds' => array_count_values($leads), 'chats_ready' => self::chatsReady()];
    }

    // -----------------------------------------------------------------
    // The month
    // -----------------------------------------------------------------

    public static function month(array $agent, string $ym): array
    {
        $aid = (int)$agent['id'];
        $ym = self::validMonth($ym);
        [$from, $to, $label] = self::monthRange($ym);
        $fromT = $from . ' 00:00:00';
        $toT = $to . ' 23:59:59';
        $notes = [];
        $blank = ['posts' => 0, 'auto' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'views' => 0, 'visits' => 0, 'chats' => 0, 'leads' => 0];
        $platforms = [];
        foreach (SmEvent::PLATFORMS as $k => $name) {
            $platforms[$k] = $blank;
        }

        // Posts that went out this month, with their figures.
        $posts = db()->all("SELECT p.id, p.platform, p.body, p.posted_at, p.posted_url, p.posted_auto, p.include_link, p.link_url, p.article_id,
                                   s.likes, s.comments, s.shares, s.views, s.clicks, s.source AS stat_source
                            FROM sm_posts p LEFT JOIN sm_post_stats s ON s.post_id = p.id
                            WHERE p.agent_id = ? AND p.status = 'posted' AND p.posted_at BETWEEN ? AND ? ORDER BY p.posted_at", [$aid, $fromT, $toT]);
        $byId = [];
        $noFigures = 0;
        foreach ($posts as $p) {
            $k = (string)$p['platform'];
            $platforms[$k]['posts']++;
            $platforms[$k]['auto'] += (int)$p['posted_auto'];
            foreach (['likes', 'comments', 'shares', 'views'] as $f) {
                $platforms[$k][$f] += (int)$p[$f];
            }
            if ($p['stat_source'] === null) {
                $noFigures++;
            }
            $byId[(int)$p['id']] = $p + ['visits' => 0, 'chats' => 0, 'leads' => 0];
        }
        if ($noFigures > 0) {
            $notes[] = $noFigures . ' post(s) have no likes or comments yet: figures of automatic posts are read a day after posting; for posts made by hand, type them in on the post.';
        }

        // Visits from the blog's figures.
        $blog = self::blogMonth($aid, $ym);
        $visitsSocial = $visitsShare = 0;
        $topArticles = [];
        $viewsTotal = 0;
        if ($blog['data'] === null) {
            $notes[] = SmBlog::forAgent($aid) ? 'Blog visits are not available yet' . ($blog['error'] ? ': ' . $blog['error'] : ' (they are fetched every few hours).') : 'No blog is connected, so visits are not counted.';
        } else {
            $viewsTotal = (int)$blog['data']['views_total'];
            $topArticles = array_slice((array)$blog['data']['articles'], 0, 5);
            foreach ((array)$blog['data']['sources'] as $s) {
                if ($s['medium'] === 'social') {
                    $visitsSocial += (int)$s['visits'];
                    if (preg_match('/^post-(\d+)$/', (string)$s['content'], $m)) {
                        $pid = (int)$m[1];
                        $plat = $byId[$pid]['platform'] ?? (string)db()->value('SELECT platform FROM sm_posts WHERE id = ? AND agent_id = ?', [$pid, $aid]);
                        if (isset($platforms[$plat])) {
                            $platforms[$plat]['visits'] += (int)$s['visits'];
                        }
                        if (!isset($byId[$pid]) && $plat !== '') {
                            $extra = db()->one("SELECT p.id, p.platform, p.body, p.posted_at, p.posted_url, p.posted_auto, p.include_link, p.link_url, p.article_id,
                                    s.likes, s.comments, s.shares, s.views, s.clicks, s.source AS stat_source
                                    FROM sm_posts p LEFT JOIN sm_post_stats s ON s.post_id = p.id WHERE p.id = ? AND p.agent_id = ?", [$pid, $aid]);
                            if ($extra) {
                                $byId[$pid] = $extra + ['visits' => 0, 'chats' => 0, 'leads' => 0, 'earlier' => true];
                            }
                        }
                        if (isset($byId[$pid])) {
                            $byId[$pid]['visits'] += (int)$s['visits'];
                        }
                    }
                } elseif ($s['medium'] === 'share') {
                    $visitsShare += (int)$s['visits'];
                }
            }
        }

        // Chats and leads (sales agent).
        $leadKinds = ['exhibit' => 0, 'visit' => 0, 'other' => 0];
        $chatsSocial = $chatsKits = $chatsShare = 0;
        $leadSeen = [];
        if (!self::chatsReady()) {
            $notes[] = 'Chats and leads are not counted yet: the sales agent upgrade "which link brought each chat" (October 2026) is not installed.';
        }
        foreach (self::chats($aid, $from, $to) as $c) {
            $medium = (string)$c['utm_medium'];
            $kind = $c['lead_id'] !== null ? self::leadKind($c['exhibitor_intent']) : null;
            $newLead = $c['lead_id'] !== null && !isset($leadSeen[(int)$c['lead_id']]);
            if ($newLead) {
                $leadSeen[(int)$c['lead_id']] = true;
                $leadKinds[$kind]++;
            }
            if ($medium === 'social') {
                $chatsSocial++;
                $plat = (string)$c['utm_source'];
                if (preg_match('/^post-(\d+)$/', (string)$c['utm_content'], $m) && isset($byId[(int)$m[1]])) {
                    $plat = (string)$byId[(int)$m[1]]['platform'];
                    $byId[(int)$m[1]]['chats']++;
                    $byId[(int)$m[1]]['leads'] += $newLead ? 1 : 0;
                }
                if (isset($platforms[$plat])) {
                    $platforms[$plat]['chats']++;
                    $platforms[$plat]['leads'] += $newLead ? 1 : 0;
                }
            } elseif ($medium === 'exhibitor-share') {
                $chatsKits++;
            } else {
                $chatsShare++;
            }
        }

        // Top posts: chats and leads first, then visits, then reactions.
        $top = array_values($byId);
        usort($top, static fn($a, $b) => [$b['leads'], $b['chats'], $b['visits'], (int)$b['likes'] + (int)$b['comments'] + (int)$b['shares']]
            <=> [$a['leads'], $a['chats'], $a['visits'], (int)$a['likes'] + (int)$a['comments'] + (int)$a['shares']]);
        $top = array_slice($top, 0, 5);

        // Exhibitor kits.
        $kits = [
            'sent' => (int)db()->value('SELECT COUNT(*) FROM sm_exhibitors WHERE agent_id = ? AND kit_sent_at BETWEEN ? AND ?', [$aid, $fromT, $toT]),
            'opened' => (int)db()->value('SELECT COUNT(*) FROM sm_exhibitors WHERE agent_id = ? AND kit_opened_at BETWEEN ? AND ?', [$aid, $fromT, $toT]),
            'clicks' => (int)db()->value('SELECT COALESCE(SUM(c.clicks), 0) FROM sm_kit_clicks c JOIN sm_exhibitors x ON x.id = c.exhibitor_id WHERE x.agent_id = ? AND c.day BETWEEN ? AND ?', [$aid, $from, $to]),
            'chats' => $chatsKits,
            'top' => db()->all('SELECT x.company, SUM(c.clicks) AS clicks FROM sm_kit_clicks c JOIN sm_exhibitors x ON x.id = c.exhibitor_id
                                WHERE x.agent_id = ? AND c.day BETWEEN ? AND ? GROUP BY x.id ORDER BY clicks DESC LIMIT 5', [$aid, $from, $to]),
        ];

        // Articles.
        $articles = (int)db()->value("SELECT COUNT(*) FROM sm_articles WHERE agent_id = ? AND published_at BETWEEN ? AND ?", [$aid, $fromT, $toT]);

        // AI and paid platform use.
        $ai = ['text' => 0, 'tokens' => 0, 'image' => 0, 'research' => 0];
        foreach (db()->all('SELECT kind, COUNT(*) n, SUM(input_tokens + output_tokens) t FROM sm_ai_usage WHERE agent_id = ? AND created_at BETWEEN ? AND ? GROUP BY kind', [$aid, $fromT, $toT]) as $r) {
            $ai[(string)$r['kind']] = (int)$r['n'];
            if ($r['kind'] === 'text') {
                $ai['tokens'] = (int)$r['t'];
            }
        }
        $xLink = $xPlain = 0;
        foreach ($posts as $p) {
            if ($p['platform'] === 'x' && (int)$p['posted_auto'] === 1) {
                ((int)$p['include_link'] === 1 && ($p['article_id'] || $p['link_url'])) ? $xLink++ : $xPlain++;
            }
        }
        $xReads = (int)db()->value("SELECT COALESCE(SUM(items), 0) FROM sm_stat_reads WHERE agent_id = ? AND platform = 'x' AND created_at BETWEEN ? AND ?", [$aid, $fromT, $toT]);
        $x = ['posts_link' => $xLink, 'posts_plain' => $xPlain, 'reads' => $xReads,
            'cost' => round($xLink * self::X_PRICE_LINK_POST + $xPlain * self::X_PRICE_POST + $xReads * self::X_PRICE_READ, 2)];

        $totals = $blank;
        foreach ($platforms as $row) {
            foreach ($row as $f => $v) {
                $totals[$f] += $v;
            }
        }
        return [
            'month' => $ym, 'label' => $label, 'from' => $from, 'to' => $to, 'event' => (string)$agent['name'],
            'platforms' => array_filter($platforms, static fn($r) => array_sum($r) > 0), 'totals' => $totals,
            'visits_social' => $visitsSocial, 'visits_share' => $visitsShare, 'views_total' => $viewsTotal,
            'chats_social' => $chatsSocial, 'chats_share' => $chatsShare, 'leads' => array_sum($leadKinds), 'lead_kinds' => $leadKinds,
            'top_posts' => $top, 'top_articles' => $topArticles, 'articles' => $articles, 'kits' => $kits, 'ai' => $ai, 'x' => $x,
            'blog_fetched_at' => $blog['fetched_at'], 'notes' => $notes,
        ];
    }

    // -----------------------------------------------------------------
    // The report (the same HTML on the page and in the email)
    // -----------------------------------------------------------------

    public static function reportHtml(array $d): string
    {
        $n = static fn($v) => number_format((int)$v);
        $h = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $th = 'style="text-align:left;padding:6px 8px;border-bottom:2px solid #142A5C;font-size:13px"';
        $thr = 'style="text-align:right;padding:6px 8px;border-bottom:2px solid #142A5C;font-size:13px"';
        $td = 'style="padding:6px 8px;border-bottom:1px solid #e3e7ef;font-size:13px;vertical-align:top"';
        $tdr = 'style="padding:6px 8px;border-bottom:1px solid #e3e7ef;font-size:13px;text-align:right;white-space:nowrap"';
        $h2 = 'style="font-size:16px;color:#142A5C;margin:26px 0 8px"';
        $box = static fn(string $label, string $value, string $sub = '') => '<td style="padding:12px 14px;border:1px solid #e3e7ef;border-radius:8px;background:#f7f9fc;width:25%;vertical-align:top">'
            . '<div style="font-size:12px;color:#5b6475">' . $label . '</div><div style="font-size:24px;font-weight:700;color:#142A5C">' . $value . '</div>'
            . ($sub !== '' ? '<div style="font-size:12px;color:#5b6475">' . $sub . '</div>' : '') . '</td>';

        $t = $d['totals'];
        $out = '<div class="sm-report" style="font-family:Arial,Helvetica,sans-serif;color:#1d2433;max-width:820px">'
            . '<h1 style="font-size:22px;color:#142A5C;margin:0 0 2px">' . $h($d['event']) . ' — social media report</h1>'
            . '<p style="margin:0 0 16px;color:#5b6475">' . $h($d['label']) . '</p>'
            . '<table role="presentation" style="width:100%;border-collapse:separate;border-spacing:8px 0"><tr>'
            . $box('Posts published', $n($t['posts']), $n($t['auto']) . ' automatically')
            . $box('Visits from social media', $n($d['visits_social']), 'to the blog')
            . $box('Chats from social media', $n($d['chats_social']), 'with the assistant')
            . $box('Leads', $n($d['leads']), $n($d['lead_kinds']['exhibit']) . ' exhibiting or sponsoring')
            . '</tr></table>';

        // Per platform.
        $out .= '<h2 ' . $h2 . '>By platform</h2>';
        if (!$d['platforms']) {
            $out .= '<p style="font-size:13px">Nothing was posted this month.</p>';
        } else {
            $out .= '<table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Platform</th>';
            foreach (['Posts', 'Likes', 'Comments', 'Shares', 'Views', 'Visits', 'Chats', 'Leads'] as $c) {
                $out .= '<th ' . $thr . '>' . $c . '</th>';
            }
            $out .= '</tr>';
            foreach ($d['platforms'] as $k => $r) {
                $out .= '<tr><td ' . $td . '>' . $h(SmEvent::PLATFORMS[$k] ?? $k) . '</td>';
                foreach (['posts', 'likes', 'comments', 'shares', 'views', 'visits', 'chats', 'leads'] as $f) {
                    $out .= '<td ' . $tdr . '>' . $n($r[$f]) . '</td>';
                }
                $out .= '</tr>';
            }
            $out .= '<tr><td ' . $td . '><strong>Total</strong></td>';
            foreach (['posts', 'likes', 'comments', 'shares', 'views', 'visits', 'chats', 'leads'] as $f) {
                $out .= '<td ' . $tdr . '><strong>' . $n($t[$f]) . '</strong></td>';
            }
            $out .= '</tr></table><p style="font-size:12px;color:#5b6475;margin:6px 0 0">Views are only given by LinkedIn and X. Visits, chats and leads are counted in this month, also for posts from earlier months.</p>';
        }

        // Top posts.
        if ($d['top_posts']) {
            $out .= '<h2 ' . $h2 . '>Best posts</h2><table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Post</th><th ' . $thr . '>Reactions</th><th ' . $thr . '>Visits</th><th ' . $thr . '>Chats</th><th ' . $thr . '>Leads</th></tr>';
            foreach ($d['top_posts'] as $p) {
                $text = mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$p['body'])), 0, 110, '…');
                $when = $p['posted_at'] ? gmdate('j M', strtotime($p['posted_at'] . ' UTC')) : '';
                $label = $h((SmEvent::PLATFORMS[$p['platform']] ?? $p['platform']) . ', ' . $when . (!empty($p['earlier']) ? ' (earlier month)' : ''));
                $link = $p['posted_url'] ? '<a href="' . $h((string)$p['posted_url']) . '" style="color:#1F4FBF">' . $label . '</a>' : $label;
                $out .= '<tr><td ' . $td . '>' . $link . '<br><span style="color:#5b6475">' . $h($text) . '</span></td>'
                    . '<td ' . $tdr . '>' . $n((int)$p['likes'] + (int)$p['comments'] + (int)$p['shares']) . '</td><td ' . $tdr . '>' . $n($p['visits']) . '</td>'
                    . '<td ' . $tdr . '>' . $n($p['chats']) . '</td><td ' . $tdr . '>' . $n($p['leads']) . '</td></tr>';
            }
            $out .= '</table>';
        }

        // Leads.
        $out .= '<h2 ' . $h2 . '>Leads from social media and exhibitor kits</h2><table style="width:100%;border-collapse:collapse">';
        foreach (self::LEAD_KINDS as $k => $label) {
            $out .= '<tr><td ' . $td . '>' . $h($label) . '</td><td ' . $tdr . '>' . $n($d['lead_kinds'][$k]) . '</td></tr>';
        }
        $out .= '</table>';

        // Blog.
        $out .= '<h2 ' . $h2 . '>Blog</h2><p style="font-size:13px;margin:0 0 6px">' . $n($d['articles']) . ' new article(s) published · '
            . $n($d['views_total']) . ' article views in total · ' . $n($d['visits_share']) . ' visit(s) from links readers shared.</p>';
        if ($d['top_articles']) {
            $out .= '<table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Most read</th><th ' . $thr . '>Views</th></tr>';
            foreach ($d['top_articles'] as $a) {
                $out .= '<tr><td ' . $td . '><a href="' . $h((string)$a['url']) . '" style="color:#1F4FBF">' . $h((string)$a['title']) . '</a></td><td ' . $tdr . '>' . $n($a['views']) . '</td></tr>';
            }
            $out .= '</table>';
        }

        // Kits.
        $k = $d['kits'];
        $out .= '<h2 ' . $h2 . '>Exhibitor share kits</h2><p style="font-size:13px;margin:0 0 6px">' . $n($k['sent']) . ' kit(s) emailed · ' . $n($k['opened']) . ' opened · '
            . $n($k['clicks']) . ' click(s) on exhibitors\' shared links · ' . $n($k['chats']) . ' chat(s).</p>';
        if ($k['top']) {
            $out .= '<table style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Exhibitors whose posts brought most visitors</th><th ' . $thr . '>Clicks</th></tr>';
            foreach ($k['top'] as $r) {
                $out .= '<tr><td ' . $td . '>' . $h((string)$r['company']) . '</td><td ' . $tdr . '>' . $n($r['clicks']) . '</td></tr>';
            }
            $out .= '</table>';
        }

        // Usage and costs.
        $ai = $d['ai'];
        $x = $d['x'];
        $out .= '<h2 ' . $h2 . '>AI use and paid services</h2><table style="width:100%;border-collapse:collapse">'
            . '<tr><td ' . $td . '>AI writing requests</td><td ' . $tdr . '>' . $n($ai['text']) . ' (' . $n($ai['tokens']) . ' tokens)</td></tr>'
            . '<tr><td ' . $td . '>AI pictures</td><td ' . $tdr . '>' . $n($ai['image']) . '</td></tr>'
            . '<tr><td ' . $td . '>Web searches (research and radar)</td><td ' . $tdr . '>' . $n($ai['research']) . '</td></tr>'
            . '<tr><td ' . $td . '>X: automatic posts (' . $n($x['posts_link']) . ' with a link, ' . $n($x['posts_plain']) . ' without) and ' . $n($x['reads']) . ' figure reads</td><td ' . $tdr . '>about $' . number_format($x['cost'], 2) . '</td></tr>'
            . '</table><p style="font-size:12px;color:#5b6475;margin:6px 0 0">X prices: about $0.015 a post, $0.20 a post with a link, $0.005 a figure read. AI costs depend on your AI provider\'s prices.</p>';

        if ($d['notes']) {
            $out .= '<h2 ' . $h2 . '>Notes</h2><ul style="font-size:13px;padding-left:18px">';
            foreach ($d['notes'] as $note) {
                $out .= '<li>' . $h($note) . '</li>';
            }
            $out .= '</ul>';
        }
        return $out . '<p style="font-size:12px;color:#5b6475;margin-top:24px">AI Social Media Expert Agent · ' . $h(gmdate('j M Y')) . '</p></div>';
    }

    /** A plain-text version for email programs that do not show HTML. */
    public static function reportText(array $d): string
    {
        $t = $d['totals'];
        $lines = [$d['event'] . ' — social media report, ' . $d['label'], '',
            'Posts published: ' . $t['posts'] . ' (' . $t['auto'] . ' automatically)',
            'Likes, comments, shares: ' . $t['likes'] . ', ' . $t['comments'] . ', ' . $t['shares'],
            'Visits from social media: ' . $d['visits_social'],
            'Chats from social media: ' . $d['chats_social'],
            'Leads: ' . $d['leads'] . ' (exhibiting or sponsoring ' . $d['lead_kinds']['exhibit'] . ', visiting ' . $d['lead_kinds']['visit'] . ', not yet known ' . $d['lead_kinds']['other'] . ')',
            'New blog articles: ' . $d['articles'] . '; article views: ' . $d['views_total'],
            'Exhibitor kits: ' . $d['kits']['sent'] . ' sent, ' . $d['kits']['clicks'] . ' clicks',
            'X cost (estimate): about $' . number_format($d['x']['cost'], 2),
        ];
        foreach ($d['notes'] as $n) {
            $lines[] = 'Note: ' . $n;
        }
        return implode("\n", $lines) . "\n\n— AI Social Media Expert Agent";
    }

    /** @return list<string> */
    public static function recipients(int $aid): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)SmEvent::settings($aid)['report_emails']) ?: []),
            static fn($e) => $e !== '' && Validator::email($e) !== null));
    }

    /** @return array{ok:bool, message:string} */
    public static function send(array $agent, string $ym, ?int $userId): array
    {
        $aid = (int)$agent['id'];
        $to = self::recipients($aid);
        if (!$to) {
            return ['ok' => false, 'message' => 'No addresses are set. Add them under Event settings → Monthly report.'];
        }
        if (($why = MailProfile::problem($agent)) !== null) {
            self::record($aid, $ym, null, $userId, 'The exhibition\'s email profile is not ready: ' . $why);
            return ['ok' => false, 'message' => 'The exhibition\'s email profile is not ready: ' . $why];
        }
        if (SmBlog::forAgent($aid)) {
            self::refreshBlog($agent, $ym);
        }
        $d = self::month($agent, $ym);
        $html = '<!doctype html><html><body style="margin:0;padding:20px;background:#ffffff">' . self::reportHtml($d) . '</body></html>';
        $profile = MailProfile::forAgent($agent);
        $sent = [];
        $errors = [];
        foreach ($to as $addr) {
            $r = Mailer::sendWith($profile, $addr, $agent['name'] . ' — social media report, ' . $d['label'], self::reportText($d), $html);
            $r['ok'] ? $sent[] = $addr : $errors[] = $addr . ': ' . (string)$r['error'];
        }
        self::record($aid, $ym, $sent ? implode(', ', $sent) : null, $userId, $errors ? mb_substr(implode('; ', $errors), 0, 500) : null);
        SmActivity::log($aid, 'report_sent', $d['label'] . ' → ' . ($sent ? implode(', ', $sent) : 'not sent'),
            $userId ? null : ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
        if (!$sent) {
            return ['ok' => false, 'message' => 'The report could not be sent: ' . implode('; ', $errors)];
        }
        return ['ok' => true, 'message' => 'The ' . $d['label'] . ' report was sent to ' . implode(', ', $sent) . ($errors ? '. Not sent to: ' . implode('; ', $errors) : '.')];
    }

    private static function record(int $aid, string $ym, ?string $sentTo, ?int $userId, ?string $error): void
    {
        db()->exec('INSERT INTO sm_reports (agent_id, month, sent_at, sent_to, sent_by, error, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE sent_at = COALESCE(VALUES(sent_at), sent_at), sent_to = COALESCE(VALUES(sent_to), sent_to),
                                            sent_by = VALUES(sent_by), error = VALUES(error), created_at = VALUES(created_at)',
            [$aid, $ym, $sentTo !== null ? gmdate('Y-m-d H:i:s') : null, $sentTo, $userId, $error, gmdate('Y-m-d H:i:s')]);
    }

    /** Cron: last month's report, early in the new month (from 8 am in the event's time zone); a failed try is repeated every 6 hours. */
    public static function sendDue(): int
    {
        $n = 0;
        $ym = self::lastMonth();
        foreach (db()->all("SELECT e.agent_id FROM sm_events e JOIN agents g ON g.id = e.agent_id AND g.status <> 'deleted'") as $r) {
            $aid = (int)$r['agent_id'];
            if (!self::recipients($aid)) {
                continue;
            }
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [$aid]);
            $local = new DateTimeImmutable('now', SmEvent::tz($agent));
            if ((int)$local->format('j') > 10 || ((int)$local->format('j') === 1 && (int)$local->format('G') < 8) || $local->format('Y-m') === $ym) {
                continue;
            }
            $done = db()->one('SELECT sent_at, created_at FROM sm_reports WHERE agent_id = ? AND month = ?', [$aid, $ym]);
            if ($done && ($done['sent_at'] !== null || strtotime($done['created_at'] . ' UTC') > time() - 6 * 3600)) {
                continue;
            }
            $n += self::send($agent, $ym, null)['ok'] ? 1 : 0;
        }
        return $n;
    }
}
