<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Connecting an exhibition's own pages for automatic posting. The person signs
 * in on the platform's own page and presses "Allow" (OAuth); no password is
 * ever seen or stored here. Tokens are kept encrypted, one connection per
 * platform per exhibition.
 */
final class SmConnect
{
    /** Which app each platform's connection comes from. */
    public const APP_OF = ['facebook' => 'meta', 'instagram' => 'meta', 'linkedin' => 'linkedin', 'x' => 'x'];

    private const META_SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish,business_management';
    private const LINKEDIN_SCOPES = 'w_organization_social r_organization_social rw_organization_admin';
    private const X_SCOPES = 'tweet.read tweet.write users.read offline.access media.write';

    public static function find(int $agentId, string $platform): ?array
    {
        return db()->one('SELECT * FROM sm_connections WHERE agent_id = ? AND platform = ?', [$agentId, $platform]);
    }

    /** Connected and usable now. */
    public static function ok(int $agentId, string $platform): bool
    {
        $c = self::find($agentId, $platform);
        return $c !== null && $c['status'] === 'ok';
    }

    public static function extra(array $c): array
    {
        return json_decode((string)($c['extra'] ?? ''), true) ?: [];
    }

    public static function disconnect(int $agentId, string $platform): void
    {
        db()->exec('DELETE FROM sm_connections WHERE agent_id = ? AND platform = ?', [$agentId, $platform]);
        db()->update('sm_platforms', ['mode' => 'manual'], 'agent_id = ? AND platform = ?', [$agentId, $platform]);
    }

    public static function store(int $agentId, string $platform, array $d, int $userId): void
    {
        db()->exec('DELETE FROM sm_connections WHERE agent_id = ? AND platform = ?', [$agentId, $platform]);
        db()->insert('sm_connections', [
            'agent_id' => $agentId, 'platform' => $platform,
            'account_id' => mb_substr((string)$d['account_id'], 0, 190), 'account_name' => mb_substr((string)($d['account_name'] ?? ''), 0, 255),
            'account_url' => mb_substr((string)($d['account_url'] ?? ''), 0, 500),
            'token' => Crypto::encrypt((string)$d['token']),
            'refresh_token' => !empty($d['refresh_token']) ? Crypto::encrypt((string)$d['refresh_token']) : null,
            'expires_at' => $d['expires_at'] ?? null, 'extra' => isset($d['extra']) ? json_encode($d['extra']) : null,
            'status' => 'ok', 'connected_by' => $userId ?: null,
        ]);
    }

    // -----------------------------------------------------------------
    // Step 1: send the person to the platform's own sign-in page
    // -----------------------------------------------------------------

    /** The platform's "Allow" page. $app is meta, linkedin or x. */
    public static function startUrl(string $app, int $agentId): ?string
    {
        if (!SmApps::ready($app)) {
            return null;
        }
        $a = SmApps::get($app);
        $state = bin2hex(random_bytes(20));
        $flow = ['app' => $app, 'agent' => $agentId, 'at' => time()];
        $redirect = SmApps::redirectUri();
        if ($app === 'meta') {
            // Business apps use "Facebook Login for Business": a configuration ID replaces the list of permissions.
            $config = SmApps::setting('meta_config_id', '');
            $url = 'https://www.facebook.com/' . SmApps::graphVersion() . '/dialog/oauth?' . http_build_query([
                'client_id' => $a['id'], 'redirect_uri' => $redirect, 'state' => $state, 'response_type' => 'code']
                + ($config !== '' ? ['config_id' => $config] : ['scope' => self::META_SCOPES]));
        } elseif ($app === 'linkedin') {
            $url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
                'response_type' => 'code', 'client_id' => $a['id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => self::LINKEDIN_SCOPES], '', '&', PHP_QUERY_RFC3986);
        } else {
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $flow['verifier'] = $verifier;
            $url = 'https://x.com/i/oauth2/authorize?' . http_build_query([
                'response_type' => 'code', 'client_id' => $a['id'], 'redirect_uri' => $redirect, 'scope' => self::X_SCOPES, 'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
        }
        $_SESSION['sm_oauth'] = array_slice(array_filter((array)($_SESSION['sm_oauth'] ?? []), static fn($f) => ($f['at'] ?? 0) > time() - 1800), -4, null, true);
        $_SESSION['sm_oauth'][$state] = $flow;
        return $url;
    }

    /** The flow a returning "state" belongs to (used once). */
    public static function takeFlow(string $state): ?array
    {
        $flow = $_SESSION['sm_oauth'][$state] ?? null;
        unset($_SESSION['sm_oauth'][$state]);
        return is_array($flow) && ($flow['at'] ?? 0) > time() - 1800 ? $flow : null;
    }

    // -----------------------------------------------------------------
    // Step 2: the platform sends the person back with a code
    // -----------------------------------------------------------------

    /**
     * Exchange the code. Meta and LinkedIn return the pages the person manages,
     * to choose from; X connects straight away.
     * @return array{ok:bool, error?:string, choices?:list<array>, connected?:string}
     */
    public static function finish(array $flow, string $code, int $userId): array
    {
        $a = SmApps::get($flow['app']);
        $redirect = SmApps::redirectUri();
        $aid = (int)$flow['agent'];
        if ($flow['app'] === 'meta') {
            $g = 'https://graph.facebook.com/' . SmApps::graphVersion();
            $r = SmNet::request('GET', $g . '/oauth/access_token?' . http_build_query(['client_id' => $a['id'], 'redirect_uri' => $redirect, 'client_secret' => $a['secret'], 'code' => $code]));
            $short = (string)($r['json']['access_token'] ?? '');
            if ($short === '') {
                return ['ok' => false, 'error' => 'Facebook did not confirm the connection. ' . SmNet::reason($r)];
            }
            $r = SmNet::request('GET', $g . '/oauth/access_token?' . http_build_query(['grant_type' => 'fb_exchange_token', 'client_id' => $a['id'], 'client_secret' => $a['secret'], 'fb_exchange_token' => $short]));
            $long = (string)($r['json']['access_token'] ?? $short);
            $r = SmNet::request('GET', $g . '/me/accounts?' . http_build_query(['fields' => 'id,name,link,access_token,instagram_business_account{id,username}', 'limit' => 100, 'access_token' => $long]));
            $pages = [];
            foreach ((array)($r['json']['data'] ?? []) as $p) {
                if (!empty($p['id']) && !empty($p['access_token'])) {
                    $pages[] = ['id' => (string)$p['id'], 'name' => (string)($p['name'] ?? $p['id']), 'link' => (string)($p['link'] ?? ''), 'token' => (string)$p['access_token'],
                        'ig_id' => (string)($p['instagram_business_account']['id'] ?? ''), 'ig_username' => (string)($p['instagram_business_account']['username'] ?? '')];
                }
            }
            if (!$pages) {
                return ['ok' => false, 'error' => 'No Facebook page was shared. Connect again and tick the event\'s page (you must be one of its admins). ' . ($r['status'] >= 400 ? SmNet::reason($r) : '')];
            }
            return ['ok' => true, 'choices' => $pages];
        }
        if ($flow['app'] === 'linkedin') {
            $r = SmNet::form('https://www.linkedin.com/oauth/v2/accessToken', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect,
                'client_id' => $a['id'], 'client_secret' => $a['secret']]);
            $token = (string)($r['json']['access_token'] ?? '');
            if ($token === '') {
                return ['ok' => false, 'error' => 'LinkedIn did not confirm the connection. ' . SmNet::reason($r)];
            }
            $tok = ['token' => $token, 'refresh_token' => (string)($r['json']['refresh_token'] ?? ''),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + (int)($r['json']['expires_in'] ?? 5184000))];
            $acl = SmNet::request('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED', self::linkedinHeaders($token));
            $orgs = [];
            foreach ((array)($acl['json']['elements'] ?? []) as $el) {
                if (preg_match('/^urn:li:organization:(\d+)$/', (string)($el['organization'] ?? ''), $m)) {
                    $o = SmNet::request('GET', 'https://api.linkedin.com/rest/organizations/' . $m[1], self::linkedinHeaders($token));
                    $orgs[] = ['id' => $m[1], 'name' => (string)($o['json']['localizedName'] ?? ('Company page ' . $m[1])),
                        'link' => !empty($o['json']['vanityName']) ? 'https://www.linkedin.com/company/' . $o['json']['vanityName'] . '/' : 'https://www.linkedin.com/company/' . $m[1] . '/'] + $tok;
                }
            }
            if (!$orgs) {
                return ['ok' => false, 'error' => 'No LinkedIn company page was found where you are a Super admin. Ask a Super admin of the page to connect it. ' . ($acl['status'] >= 400 ? SmNet::reason($acl) : '')];
            }
            return ['ok' => true, 'choices' => $orgs];
        }
        // X
        $r = SmNet::form('https://api.x.com/2/oauth2/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect,
            'code_verifier' => (string)($flow['verifier'] ?? ''), 'client_id' => $a['id']], ['Authorization' => 'Basic ' . base64_encode($a['id'] . ':' . $a['secret'])]);
        $token = (string)($r['json']['access_token'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'X did not confirm the connection. ' . SmNet::reason($r)];
        }
        $me = SmNet::request('GET', 'https://api.x.com/2/users/me', ['Authorization' => 'Bearer ' . $token]);
        $u = (array)($me['json']['data'] ?? []);
        if (empty($u['id'])) {
            return ['ok' => false, 'error' => 'X connected, but did not say which account. ' . SmNet::reason($me)];
        }
        self::store($aid, 'x', ['account_id' => (string)$u['id'], 'account_name' => '@' . ($u['username'] ?? $u['id']), 'account_url' => 'https://x.com/' . ($u['username'] ?? ''),
            'token' => $token, 'refresh_token' => (string)($r['json']['refresh_token'] ?? ''),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int)($r['json']['expires_in'] ?? 7200))], $userId);
        return ['ok' => true, 'connected' => 'x'];
    }

    /** Save the page the person chose. @return list<string> the platforms connected */
    public static function choose(string $app, int $agentId, array $choice, int $userId): array
    {
        if ($app === 'meta') {
            self::store($agentId, 'facebook', ['account_id' => $choice['id'], 'account_name' => $choice['name'], 'account_url' => $choice['link'] ?: 'https://www.facebook.com/' . $choice['id'],
                'token' => $choice['token']], $userId);
            $done = ['facebook'];
            if ($choice['ig_id'] !== '') {
                self::store($agentId, 'instagram', ['account_id' => $choice['ig_id'], 'account_name' => '@' . ($choice['ig_username'] ?: $choice['ig_id']),
                    'account_url' => $choice['ig_username'] !== '' ? 'https://www.instagram.com/' . $choice['ig_username'] . '/' : '', 'token' => $choice['token'],
                    'extra' => ['page_id' => $choice['id']]], $userId);
                $done[] = 'instagram';
            }
            return $done;
        }
        self::store($agentId, 'linkedin', ['account_id' => $choice['id'], 'account_name' => $choice['name'], 'account_url' => $choice['link'],
            'token' => $choice['token'], 'refresh_token' => $choice['refresh_token'] ?? '', 'expires_at' => $choice['expires_at'] ?? null], $userId);
        return ['linkedin'];
    }

    public static function linkedinHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'LinkedIn-Version' => SmApps::linkedinVersion(), 'X-Restli-Protocol-Version' => '2.0.0'];
    }

    // -----------------------------------------------------------------
    // Using a connection
    // -----------------------------------------------------------------

    /**
     * A working token, refreshed first when it is about to run out.
     * @return array{ok:bool, token?:string, conn?:array, error?:string}
     */
    public static function token(int $agentId, string $platform): array
    {
        $c = self::find($agentId, $platform);
        if (!$c) {
            return ['ok' => false, 'error' => 'Not connected. Connect it under Blog and platforms.'];
        }
        $token = (string)(Crypto::decrypt((string)$c['token']) ?? '');
        $soon = $c['expires_at'] !== null && strtotime($c['expires_at'] . ' UTC') < time() + ($platform === 'x' ? 300 : 7 * 86400);
        if ($soon && !empty($c['refresh_token'])) {
            $refreshed = self::refresh($c);
            if ($refreshed !== null) {
                return ['ok' => true, 'token' => $refreshed, 'conn' => self::find($agentId, $platform)];
            }
        }
        if ($c['expires_at'] !== null && strtotime($c['expires_at'] . ' UTC') < time()) {
            self::mark($c, 'expired', 'The connection has expired. Connect the page again under Blog and platforms.');
            return ['ok' => false, 'error' => 'The ' . SmEvent::PLATFORMS[$platform] . ' connection has expired. Connect it again under Blog and platforms.'];
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'The saved connection cannot be read. Connect it again.'];
        }
        return ['ok' => true, 'token' => $token, 'conn' => $c];
    }

    private static function refresh(array $c): ?string
    {
        $refresh = (string)(Crypto::decrypt((string)$c['refresh_token']) ?? '');
        if ($refresh === '') {
            return null;
        }
        if ($c['platform'] === 'x') {
            $a = SmApps::get('x');
            $r = SmNet::form('https://api.x.com/2/oauth2/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $a['id']],
                ['Authorization' => 'Basic ' . base64_encode($a['id'] . ':' . $a['secret'])]);
        } elseif ($c['platform'] === 'linkedin') {
            $a = SmApps::get('linkedin');
            $r = SmNet::form('https://www.linkedin.com/oauth/v2/accessToken', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $a['id'], 'client_secret' => $a['secret']]);
        } else {
            return null;
        }
        $token = (string)($r['json']['access_token'] ?? '');
        if ($token === '') {
            self::mark($c, SmNet::transient($r) ? 'ok' : 'expired', 'Refreshing the connection failed: ' . SmNet::reason($r));
            return null;
        }
        db()->update('sm_connections', [
            'token' => Crypto::encrypt($token),
            'refresh_token' => !empty($r['json']['refresh_token']) ? Crypto::encrypt((string)$r['json']['refresh_token']) : $c['refresh_token'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (int)($r['json']['expires_in'] ?? ($c['platform'] === 'x' ? 7200 : 5184000))),
            'status' => 'ok', 'last_error' => null,
        ], 'id = ?', [(int)$c['id']]);
        return $token;
    }

    public static function mark(array $c, string $status, string $error): void
    {
        db()->update('sm_connections', ['status' => $status, 'last_error' => mb_substr($error, 0, 500)], 'id = ?', [(int)$c['id']]);
    }

    /** Connections that need a person: expired, failing, or LinkedIn's 60 days nearly up. */
    public static function problems(int $agentId): array
    {
        $out = [];
        foreach (db()->all('SELECT * FROM sm_connections WHERE agent_id = ?', [$agentId]) as $c) {
            $n = SmEvent::PLATFORMS[$c['platform']];
            if ($c['status'] !== 'ok') {
                $out[] = $n . ': ' . ($c['last_error'] ?: 'the connection needs connecting again');
            } elseif ($c['platform'] === 'linkedin' && empty($c['refresh_token']) && $c['expires_at'] && strtotime($c['expires_at'] . ' UTC') < time() + 7 * 86400) {
                $out[] = $n . ': the connection runs out on ' . gmdate('j M', (int)strtotime($c['expires_at'] . ' UTC')) . ' — connect it again before then (LinkedIn asks every 60 days)';
            }
        }
        return $out;
    }
}
