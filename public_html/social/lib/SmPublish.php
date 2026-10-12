<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Automatic posting to a connected page: Facebook, Instagram, LinkedIn and X.
 * Only approved posts reach here, and only for platforms switched to
 * "automatic" whose page is connected. Everything else stays manual.
 */
final class SmPublish
{
    /** Posted automatically when the time comes (instead of a reminder)? */
    public static function automatic(int $agentId, string $platform): bool
    {
        $mode = (string)db()->value('SELECT mode FROM sm_platforms WHERE agent_id = ? AND platform = ?', [$agentId, $platform]);
        return $mode === 'direct' && SmConnect::find($agentId, $platform) !== null;
    }

    /** Automatic X posts this month (X charges for each one). */
    public static function xUsed(int $agentId): int
    {
        return (int)db()->value("SELECT COUNT(*) FROM sm_posts WHERE agent_id = ? AND platform = 'x' AND posted_auto = 1 AND posted_at >= ?", [$agentId, gmdate('Y-m-01 00:00:00')]);
    }

    /**
     * Post it now. Claims the post first, so a notice, cron and a button never post it twice.
     * @return array{ok:bool, message:string, retry?:bool}
     */
    public static function run(array $agent, int $postId): array
    {
        $aid = (int)$agent['id'];
        $claimed = db()->exec("UPDATE sm_posts SET posting_since = UTC_TIMESTAMP() WHERE id = ? AND agent_id = ? AND status IN ('approved','scheduled','due','failed')
                               AND (posting_since IS NULL OR posting_since < UTC_TIMESTAMP() - INTERVAL 15 MINUTE)", [$postId, $aid]);
        if ($claimed !== 1) {
            return ['ok' => false, 'message' => 'This post is already being posted, or it is not approved.'];
        }
        $post = db()->one('SELECT * FROM sm_posts WHERE id = ?', [$postId]);
        try {
            $res = self::send($agent, $post);
        } catch (Throwable $e) {
            app_log('social', 'publish failed', ['post' => $postId, 'error' => $e->getMessage()]);
            $res = ['ok' => false, 'message' => 'Something went wrong while posting. Try again, or post it by hand.', 'retry' => true];
        }
        $name = SmEvent::PLATFORMS[$post['platform']];
        if ($res['ok']) {
            db()->update('sm_posts', ['status' => 'posted', 'posted_at' => gmdate('Y-m-d H:i:s'), 'posted_url' => mb_substr((string)($res['url'] ?? ''), 0, 500) ?: null,
                'remote_id' => mb_substr((string)($res['id'] ?? ''), 0, 190), 'posted_auto' => 1, 'fail_reason' => null, 'posting_since' => null, 'next_try_at' => null], 'id = ?', [$postId]);
            SmActivity::log($aid, 'post_auto', $name . ' post #' . $postId . ' posted automatically', ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
            return ['ok' => true, 'message' => 'Posted on ' . $name . '.'];
        }
        $attempts = (int)$post['attempts'] + 1;
        $retry = !empty($res['retry']) && $attempts < 3;
        db()->update('sm_posts', [
            'status' => $retry && $post['status'] === 'scheduled' ? 'scheduled' : 'failed',
            'fail_reason' => mb_substr($res['message'], 0, 1000), 'attempts' => $attempts, 'posting_since' => null,
            'next_try_at' => $retry ? gmdate('Y-m-d H:i:s', time() + 600 * $attempts) : null,
        ], 'id = ?', [$postId]);
        SmActivity::log($aid, 'post_failed', $name . ' post #' . $postId . ': ' . mb_substr($res['message'], 0, 150), ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
        if (!$retry) {
            self::tellTeam($agent, $post, $res['message']);
        }
        return ['ok' => false, 'message' => $res['message'], 'retry' => $retry];
    }

    /** @return array{ok:bool, message:string, id?:string, url?:string, retry?:bool} */
    private static function send(array $agent, array $post): array
    {
        $aid = (int)$agent['id'];
        $p = (string)$post['platform'];
        if (!self::automatic($aid, $p)) {
            return ['ok' => false, 'message' => SmEvent::PLATFORMS[$p] . ' is set to posting by hand.'];
        }
        if (($why = SmFlow::blocker($agent, $post)) !== null) {
            return ['ok' => false, 'message' => $why];
        }
        if ($p === 'x' && self::xUsed($aid) >= (int)SmEvent::settings($aid)['x_auto_limit']) {
            return ['ok' => false, 'message' => 'This month\'s limit of automatic X posts (' . SmEvent::settings($aid)['x_auto_limit'] . ') is reached, because X charges for each post. Post it by hand, or an admin can raise the limit in Event settings.'];
        }
        $t = SmConnect::token($aid, $p);
        if (!$t['ok']) {
            return ['ok' => false, 'message' => (string)$t['error']];
        }
        $link = SmFlow::link($agent, $post);
        $media = !empty($post['media_id']) ? SmMedia::find($aid, (int)$post['media_id']) : null;
        $out = match ($p) {
            'facebook' => self::facebook($t, $post, $link, $media),
            'instagram' => self::instagram($t, $post, $link, $media),
            'linkedin' => self::linkedin($agent, $t, $post, $link, $media),
            default => self::x($t, $post, $link, $media),
        };
        if (!$out['ok'] && in_array($out['status'] ?? 0, [401, 403], true) && ($c = SmConnect::find($aid, $p))) {
            SmConnect::mark($c, 'error', $out['message']);
        }
        return $out;
    }

    /** The picture's public address (platforms fetch it themselves). */
    private static function publicUrl(array $m): string
    {
        return url('social/uploads/' . $m['file']);
    }

    private static function fail(array $r, string $what): array
    {
        return ['ok' => false, 'status' => $r['status'], 'retry' => SmNet::transient($r), 'message' => $what . ' ' . SmNet::reason($r)];
    }

    // -----------------------------------------------------------------

    private static function facebook(array $t, array $post, ?string $link, ?array $media): array
    {
        $g = 'https://graph.facebook.com/' . SmApps::graphVersion();
        $page = (string)$t['conn']['account_id'];
        $text = SmChecks::fullText($post, $link);
        if ($media && $media['kind'] === 'video') {
            $r = SmNet::form($g . '/' . $page . '/videos', ['file_url' => self::publicUrl($media), 'description' => $text, 'access_token' => $t['token']]);
        } elseif ($media) {
            $r = SmNet::form($g . '/' . $page . '/photos', ['url' => self::publicUrl($media), 'message' => $text, 'access_token' => $t['token']]);
        } else {
            // A link card: the address goes in "link", not repeated in the text.
            $fields = ['message' => $link !== null ? SmChecks::fullText(['include_link' => 0] + $post, null) : $text, 'access_token' => $t['token']];
            if ($link !== null) {
                $fields['link'] = $link;
            }
            $r = SmNet::form($g . '/' . $page . '/feed', $fields);
        }
        $id = (string)($r['json']['post_id'] ?? $r['json']['id'] ?? '');
        if ($r['status'] !== 200 || $id === '') {
            return self::fail($r, 'Facebook did not accept the post.');
        }
        return ['ok' => true, 'id' => $id, 'url' => 'https://www.facebook.com/' . $id];
    }

    private static function instagram(array $t, array $post, ?string $link, ?array $media): array
    {
        if (!$media) {
            return ['ok' => false, 'message' => 'Instagram needs a picture or video.'];
        }
        $g = 'https://graph.facebook.com/' . SmApps::graphVersion();
        $ig = (string)$t['conn']['account_id'];
        $caption = SmChecks::fullText($post, $link);
        $fields = $media['kind'] === 'video'
            ? ['media_type' => 'REELS', 'video_url' => self::publicUrl($media), 'caption' => $caption, 'access_token' => $t['token']]
            : ['image_url' => self::publicUrl($media), 'caption' => $caption, 'access_token' => $t['token']];
        $r = SmNet::form($g . '/' . $ig . '/media', $fields);
        $container = (string)($r['json']['id'] ?? '');
        if ($container === '') {
            return self::fail($r, 'Instagram did not accept the picture.');
        }
        // Instagram prepares the picture or video first; wait until it is ready.
        for ($i = 0; $i < ($media['kind'] === 'video' ? 20 : 6); $i++) {
            $s = SmNet::request('GET', $g . '/' . $container . '?' . http_build_query(['fields' => 'status_code', 'access_token' => $t['token']]));
            $code = (string)($s['json']['status_code'] ?? 'FINISHED');
            if ($code === 'FINISHED') {
                break;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                return ['ok' => false, 'message' => 'Instagram could not process the ' . ($media['kind'] === 'video' ? 'video' : 'picture') . '. Try another one.'];
            }
            if (SmNet::$hook === null) {
                sleep(3);
            }
        }
        $r = SmNet::form($g . '/' . $ig . '/media_publish', ['creation_id' => $container, 'access_token' => $t['token']]);
        $id = (string)($r['json']['id'] ?? '');
        if ($id === '') {
            return self::fail($r, 'Instagram did not publish the post.');
        }
        $pl = SmNet::request('GET', $g . '/' . $id . '?' . http_build_query(['fields' => 'permalink', 'access_token' => $t['token']]));
        return ['ok' => true, 'id' => $id, 'url' => (string)($pl['json']['permalink'] ?? '')];
    }

    /** LinkedIn's text format: some characters must be escaped; hashtags become real hashtags. */
    public static function littleText(string $text): string
    {
        $out = preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text) ?? $text;
        return preg_replace('/\\\\#([\p{L}\p{N}_]+)/u', '{hashtag|\\\\#|$1}', $out) ?? $out;
    }

    private static function linkedin(array $agent, array $t, array $post, ?string $link, ?array $media): array
    {
        $org = 'urn:li:organization:' . $t['conn']['account_id'];
        $h = SmConnect::linkedinHeaders($t['token']);
        $body = [
            'author' => $org, 'visibility' => 'PUBLIC', 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
        ];
        $text = SmChecks::fullText($post, $link);
        if ($media && $media['kind'] === 'video') {
            return ['ok' => false, 'message' => 'Videos are posted to LinkedIn by hand for now: use a picture, or post this one by hand.'];
        }
        if ($media) {
            $init = SmNet::json('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => $org]], $h);
            $upload = (string)($init['json']['value']['uploadUrl'] ?? '');
            $image = (string)($init['json']['value']['image'] ?? '');
            if ($upload === '' || $image === '') {
                return self::fail($init, 'LinkedIn did not accept the picture.');
            }
            $bytes = (string)@file_get_contents(SmMedia::path($media));
            $put = SmNet::request('PUT', $upload, ['Authorization' => 'Bearer ' . $t['token'], 'Content-Type' => (string)$media['mime']], $bytes, 120);
            if ($put['status'] < 200 || $put['status'] >= 300) {
                return self::fail($put, 'The picture could not be sent to LinkedIn.');
            }
            $body['content'] = ['media' => ['id' => $image]];
        } elseif ($link !== null) {
            // A link card: LinkedIn does not read the page itself, so give it the title.
            $title = $post['article_id'] ? (string)db()->value('SELECT title FROM sm_articles WHERE id = ?', [(int)$post['article_id']]) : '';
            $body['content'] = ['article' => ['source' => $link, 'title' => mb_substr($title !== '' ? $title : (string)$agent['name'], 0, 400)]];
            $text = SmChecks::fullText(['include_link' => 0] + $post, null);
        }
        $body['commentary'] = self::littleText($text);
        $r = SmNet::json('POST', 'https://api.linkedin.com/rest/posts', $body, $h);
        $urn = (string)($r['headers']['x-restli-id'] ?? '');
        if ($r['status'] !== 201 || $urn === '') {
            return self::fail($r, 'LinkedIn did not accept the post.');
        }
        return ['ok' => true, 'id' => $urn, 'url' => 'https://www.linkedin.com/feed/update/' . $urn . '/'];
    }

    private static function x(array $t, array $post, ?string $link, ?array $media): array
    {
        $h = ['Authorization' => 'Bearer ' . $t['token']];
        $payload = ['text' => SmChecks::fullText($post, $link)];
        if ($media && $media['kind'] === 'video') {
            return ['ok' => false, 'message' => 'Videos are posted to X by hand for now: use a picture, or post this one by hand.'];
        }
        if ($media) {
            $bytes = (string)@file_get_contents(SmMedia::path($media));
            if (strlen($bytes) > 5 * 1024 * 1024) {
                return ['ok' => false, 'message' => 'X accepts pictures up to 5 MB. Choose a smaller one.'];
            }
            $up = SmNet::multipart('https://api.x.com/2/media/upload', ['media_category' => 'tweet_image'],
                ['media' => ['name' => basename((string)$media['file']), 'type' => (string)$media['mime'], 'bytes' => $bytes]], $h);
            $mediaId = (string)($up['json']['data']['id'] ?? '');
            if ($mediaId === '') {
                return self::fail($up, 'X did not accept the picture.');
            }
            $payload['media'] = ['media_ids' => [$mediaId]];
        }
        $r = SmNet::json('POST', 'https://api.x.com/2/tweets', $payload, $h);
        $id = (string)($r['json']['data']['id'] ?? '');
        if ($id === '') {
            return self::fail($r, 'X did not accept the post.');
        }
        $handle = ltrim((string)$t['conn']['account_name'], '@');
        return ['ok' => true, 'id' => $id, 'url' => 'https://x.com/' . ($handle !== '' ? $handle : 'i/web') . '/status/' . $id];
    }

    /** When automatic posting fails for good, the reminder addresses are told. */
    private static function tellTeam(array $agent, array $post, string $why): void
    {
        $to = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)SmEvent::settings((int)$agent['id'])['reminder_emails']) ?: []));
        if (!$to || MailProfile::problem($agent) !== null) {
            return;
        }
        $platform = SmEvent::PLATFORMS[$post['platform']];
        $link = url('social/post.php?id=' . (int)$post['id'] . '&agent=' . (int)$agent['id']);
        $profile = MailProfile::forAgent($agent);
        foreach ($to as $addr) {
            if (Validator::email($addr) !== null) {
                Mailer::sendWith($profile, $addr, $agent['name'] . ': automatic posting on ' . $platform . ' failed',
                    "A post could not be posted automatically on $platform for {$agent['name']}.\n\nReason: $why\n\nOpen it to try again or post it by hand:\n$link\n\n— AI Social Media Expert Agent");
            }
        }
    }
}
