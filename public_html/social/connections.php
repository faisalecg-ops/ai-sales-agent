<?php
declare(strict_types=1);

/** The event blog's address and API key, and each social platform's settings (admins). */
require __DIR__ . '/_social.php';

sm_page('admin');
$agent = sm_agent();
$aid = (int)$agent['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    if ($do === 'blog') {
        $url = rtrim(sm_in('blog_url'), '/');
        if ($url !== '' && (Validator::url($url) === null || !preg_match('#^https?://#i', $url))) {
            sm_flash('The blog address must be a full address such as https://buildasia.net/blog', 'error');
            sm_redirect('connections.php');
        }
        SmBlog::saveConnection($aid, $url, sm_in('blog_api_key') ?: null, sm_in('hook_secret') ?: null);
        // From now on: articles the blog already had are not posted again.
        if (empty(SmEvent::row($aid)['blog_seen_at'])) {
            SmFlow::startBlogCursor($aid);
        }
        $blog = SmBlog::forAgent($aid);
        $msg = 'Saved.';
        $kind = 'ok';
        if ($blog) {
            $ping = $blog->ping();
            if ($ping['ok']) {
                $perm = (string)($ping['data']['permission'] ?? '');
                $msg = 'Connected to ' . ($ping['data']['site'] ?? $url) . ' (blog version ' . ($ping['data']['version'] ?? '?') . '). '
                     . ($perm === 'publish' ? 'Approved articles are published straight away.' : 'This key can only send drafts: each article will also wait for approval in the blog admin. For one approval only, give the key the "publish" permission.');
                $kind = $perm === 'publish' ? 'ok' : 'warn';
            } else {
                $msg = 'Saved, but the test failed: ' . $ping['message'];
                $kind = 'error';
            }
            db()->update('sm_events', ['blog_checked_at' => gmdate('Y-m-d H:i:s'), 'blog_status' => mb_substr($ping['ok'] ? 'ok' : $ping['message'], 0, 255)], 'agent_id = ?', [$aid]);
        }
        SmActivity::log($aid, 'blog_connection_saved', $url);
        sm_flash($msg, $kind);
        sm_redirect('connections.php');
    }
    if ($do === 'apps' && sm_is_super()) {
        foreach (array_keys(SmApps::LABELS) as $app) {
            $in = (array)($_POST['app'][$app] ?? []);
            SmApps::save($app, (string)($in['id'] ?? ''), (string)($in['secret'] ?? '') ?: null);
        }
        SmApps::saveSetting('graph_version', preg_match('/^v\d{2,3}\.\d$/', sm_in('graph_version')) ? sm_in('graph_version') : SmApps::DEFAULT_GRAPH_VERSION);
        SmApps::saveSetting('linkedin_version', preg_match('/^20\d{4}$/', sm_in('linkedin_version')) ? sm_in('linkedin_version') : SmApps::DEFAULT_LINKEDIN_VERSION);
        SmApps::saveSetting('meta_config_id', preg_match('/^\d{5,30}$/', sm_in('meta_config_id')) ? sm_in('meta_config_id') : '');
        SmApps::saveSetting('company_name', mb_substr(sm_in('company_name'), 0, 160) ?: SmApps::company());
        SmApps::saveSetting('contact_email', Validator::email(sm_in('contact_email')) ?? '');
        SmActivity::log($aid, 'apps_saved', 'Platform apps');
        sm_flash('Platform apps saved.');
        sm_redirect('connections.php', ['apps' => 1]);
    }
    if ($do === 'choose') {
        $pending = $_SESSION['sm_choose'] ?? null;
        $i = (int)sm_in('pick', '-1');
        if (!is_array($pending) || (int)$pending['agent'] !== $aid || ($pending['at'] ?? 0) < time() - 1800 || !isset($pending['choices'][$i])) {
            sm_flash('That choice has expired. Press Connect again.', 'error');
            sm_redirect('connections.php');
        }
        $c = $pending['choices'][$i];
        $c['token'] = (string)Crypto::decrypt((string)$c['token']);
        if (!empty($c['refresh_token'])) {
            $c['refresh_token'] = (string)Crypto::decrypt((string)$c['refresh_token']);
        }
        unset($_SESSION['sm_choose']);
        $done = SmConnect::choose((string)$pending['app'], $aid, $c, sm_uid());
        foreach ($done as $pl) {
            SmActivity::log($aid, 'connected', SmEvent::PLATFORMS[$pl] . ': ' . ($c['name'] ?? ''));
        }
        $names = implode(' and ', array_map(static fn($pl) => SmEvent::PLATFORMS[$pl], $done));
        sm_flash($names . ' connected to "' . ($c['name'] ?? '') . '". Switch ' . (count($done) > 1 ? 'them' : 'it') . ' to "automatic" below when you want posts to go out by themselves.'
            . ((string)$pending['app'] === 'meta' && !in_array('instagram', $done, true) ? ' This Facebook page has no Instagram business account linked, so Instagram stays by hand.' : ''));
        sm_redirect('connections.php');
    }
    if ($do === 'disconnect') {
        $pl = sm_in('platform');
        if (isset(SmEvent::PLATFORMS[$pl])) {
            SmConnect::disconnect($aid, $pl);
            SmActivity::log($aid, 'disconnected', SmEvent::PLATFORMS[$pl]);
            sm_flash(SmEvent::PLATFORMS[$pl] . ' disconnected. Its posts are prepared for posting by hand again.');
        }
        sm_redirect('connections.php');
    }
    if ($do === 'platforms') {
        $notConnected = [];
        $xLimit = max(0, min(1000, (int)($_POST['x_auto_limit'] ?? SmEvent::settings($aid)['x_auto_limit'])));
        $st = SmEvent::settings($aid);
        $st['x_auto_limit'] = $xLimit;
        $st['x_read_limit'] = max(0, min(5000, (int)($_POST['x_read_limit'] ?? $st['x_read_limit'])));
        SmEvent::saveSettings($aid, $st);
        foreach (array_keys(SmEvent::PLATFORMS) as $p) {
            $in = (array)($_POST['p'][$p] ?? []);
            $safe = static function (string $u): ?string {
                $u = trim($u);
                if (preg_match('#^https://[^\s/]+\.[^\s]+$#i', $u) !== 1) {
                    return null;
                }
                $v = Validator::url($u);
                return $v !== null && preg_match('#^https://#i', $v) === 1 ? mb_substr($v, 0, 255) : null;
            };
            $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($in['default_time'] ?? '')) ? (string)$in['default_time'] : '11:00';
            $mode = ($in['mode'] ?? 'manual') === 'direct' ? 'direct' : 'manual';
            if ($mode === 'direct' && !SmConnect::find($aid, $p)) {
                $mode = 'manual';
                $notConnected[] = SmEvent::PLATFORMS[$p];
            }
            db()->update('sm_platforms', [
                'enabled' => !empty($in['enabled']) ? 1 : 0,
                'mode' => $mode,
                'page_url' => $safe((string)($in['page_url'] ?? '')),
                'posts_per_week' => max(0, min(28, (int)($in['posts_per_week'] ?? 3))),
                'default_time' => $time,
                'hashtags' => mb_substr(trim((string)($in['hashtags'] ?? '')), 0, 500),
            ], 'agent_id = ? AND platform = ?', [$aid, $p]);
        }
        SmActivity::log($aid, 'platforms_saved', 'Platform settings');
        sm_flash('Platform settings saved.' . ($notConnected ? ' ' . implode(', ', $notConnected) . ' stay by hand until connected.' : ''), $notConnected ? 'warn' : 'ok');
        sm_redirect('connections.php');
    }
    sm_redirect('connections.php');
}

$row = SmEvent::row($aid);
$platforms = SmEvent::platforms($aid);
$hook = url('social/hook.php?agent=' . $aid);

sm_layout_start('Blog and platforms');
echo '<section class="set-card" id="blog"><div class="set-head"><h2>The event blog</h2><p>Where approved articles are published. Make the key in the blog admin → API keys, with the "publish" permission.</p></div><div class="set-body">';
if ($row['blog_status']) {
    echo '<p>' . ($row['blog_status'] === 'ok' ? '<span class="pill pill-ok">Connected</span>' : '<span class="pill pill-bad">Problem</span> ' . e((string)$row['blog_status']))
       . ' <span class="muted small">checked ' . e(sm_time($row['blog_checked_at'])) . '</span></p>';
}
echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="blog">'
   . f_text('blog_url', 'Blog address', (string)($row['blog_url'] ?? ''), ['type' => 'url', 'placeholder' => 'https://buildasia.net/blog'])
   . f_text('blog_api_key', 'API key', '', ['type' => 'password', 'placeholder' => $row['blog_api_key'] ? 'Saved — leave empty to keep it' : 'eb_…'])
   . '<div class="field"><label>"Article published" notice address — paste this into the blog admin → API keys</label><code class="sm-code">' . e($hook) . '</code> '
   . '<button class="btn btn-small btn-quiet" type="button" data-copy-text="' . e($hook) . '">Copy</button></div>'
   . f_text('hook_secret', 'Signing secret shown there', '', ['type' => 'password', 'placeholder' => $row['blog_hook_secret'] ? 'Saved — leave empty to keep it' : 'Copy it from the blog admin', 'hint' => 'With the notice, a published article is picked up at once; without it, /social checks the blog every few minutes.'])
   . '<button class="btn" type="submit">Save and test</button></form></div></section>';

// Connect / disconnect buttons (separate forms, outside the settings form).
echo '<section class="set-card" id="connect"><div class="set-head"><h2>Connect the event\'s pages</h2><p>You sign in on the platform\'s own page and press "Allow". No password is seen or kept here; the permission is stored encrypted and can be removed at any time.</p></div><div class="set-body"><div class="btn-row">';
foreach (SmApps::LABELS as $app => $label) {
    $plats = array_keys(array_filter(SmConnect::APP_OF, static fn($a) => $a === $app));
    $on = array_filter($plats, static fn($pl) => SmConnect::find($aid, $pl) !== null);
    echo '<form method="post" action="' . e(sm_url('oauth.php')) . '" class="inline-form">' . sm_csrf() . '<input type="hidden" name="app" value="' . e($app) . '">'
       . '<button class="btn' . ($on ? ' btn-quiet' : '') . '" type="submit"' . (SmApps::ready($app) ? '' : ' disabled title="Set up the app first (super admin)"') . '>' . ($on ? 'Connect again: ' : 'Connect ') . e($label) . '</button></form> ';
    foreach ($on as $pl) {
        echo sm_button('connections.php', ['do' => 'disconnect', 'platform' => $pl], 'Disconnect ' . SmEvent::PLATFORMS[$pl], 'btn btn-small btn-quiet', 'Disconnect ' . SmEvent::PLATFORMS[$pl] . '? Its posts go back to being posted by hand.') . ' ';
    }
}
echo '</div><p class="hint">Facebook and Instagram connect together: choose the event\'s Facebook page; its linked Instagram business account comes with it. On LinkedIn you must be a Super admin of the company page.</p></div></section>';

// Choosing which page to connect (after "Allow" on Meta or LinkedIn).
$pending = $_SESSION['sm_choose'] ?? null;
if (sm_in('choose') !== '' && is_array($pending) && (int)$pending['agent'] === $aid) {
    echo '<section class="set-card" id="choose"><div class="set-head"><h2>Which page is ' . e((string)$agent['name']) . '\'s?</h2><p>You manage more than one. Choose the event\'s own page.</p></div><div class="set-body"><ul class="plain">';
    foreach ((array)$pending['choices'] as $i => $c) {
        echo '<li style="margin-bottom:8px">' . sm_button('connections.php', ['do' => 'choose', 'pick' => $i], 'Connect ' . (string)$c['name'], 'btn btn-small')
           . (!empty($c['ig_username']) ? ' <span class="muted small">with Instagram @' . e((string)$c['ig_username']) . '</span>' : (($pending['app'] ?? '') === 'meta' ? ' <span class="muted small">no Instagram business account linked</span>' : ''))
           . (!empty($c['link']) ? ' <a class="small" href="' . e((string)$c['link']) . '" target="_blank" rel="noopener">view ↗</a>' : '') . '</li>';
    }
    echo '</ul></div></section>';
}

echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="platforms">';
echo '<section class="set-card" id="platforms"><div class="set-head"><h2>Social media platforms</h2><p>Which of the event\'s pages the agent writes for, and how each is posted: <strong>by hand</strong> (the agent prepares the post and reminds you; you post it and press "Done") or <strong>automatic</strong> (approved posts go out by themselves at their time). Automatic needs the page connected first.</p></div><div class="set-body">';
foreach ($platforms as $p => $r) {
    $n = SmEvent::PLATFORMS[$p];
    $conn = SmConnect::find($aid, $p);
    $app = SmConnect::APP_OF[$p];
    echo '<fieldset class="sm-platform"><legend>' . e($n) . '</legend>'
       . '<div class="check"><input type="checkbox" id="en_' . $p . '" name="p[' . $p . '][enabled]" value="1"' . ((int)$r['enabled'] ? ' checked' : '') . '><label for="en_' . $p . '">Write posts for ' . e($n) . '</label></div>'
       . '<div class="form-grid">'
       . '<div class="field"><label for="pu_' . $p . '">The event\'s ' . e($n) . ' page</label><input type="url" id="pu_' . $p . '" name="p[' . $p . '][page_url]" value="' . e((string)$r['page_url']) . '" placeholder="https://…"></div>'
       . '<div class="field"><label for="pw_' . $p . '">Posts per week</label><input type="number" min="0" max="28" id="pw_' . $p . '" name="p[' . $p . '][posts_per_week]" value="' . (int)$r['posts_per_week'] . '"></div>'
       . '<div class="field"><label for="dt_' . $p . '">Usual posting time (' . e(SmEvent::tz($agent)->getName()) . ')</label><input type="time" id="dt_' . $p . '" name="p[' . $p . '][default_time]" value="' . e((string)$r['default_time']) . '"></div>'
       . '</div>'
       . '<div class="field"><label for="ht_' . $p . '">Hashtags always added</label><input type="text" id="ht_' . $p . '" name="p[' . $p . '][hashtags]" value="' . e((string)$r['hashtags']) . '" placeholder="#BuildAsia2027"></div>'
       . '<div class="field"><label>How it is posted</label><div class="sm-radios">'
       . '<label><input type="radio" name="p[' . $p . '][mode]" value="manual"' . ($r['mode'] !== 'direct' ? ' checked' : '') . '> By hand (free)</label>'
       . '<label><input type="radio" name="p[' . $p . '][mode]" value="direct"' . ($r['mode'] === 'direct' ? ' checked' : '') . ($conn ? '' : ' disabled') . '> Automatic' . ($p === 'x' ? ' (X charges per post)' : '') . '</label></div>'
       . ($conn
           ? '<p class="small">' . ($conn['status'] === 'ok' ? '<span class="pill pill-ok">Connected</span>' : '<span class="pill pill-bad">Needs connecting again</span> ' . e((string)$conn['last_error']))
             . ' ' . ($conn['account_url'] ? '<a href="' . e((string)$conn['account_url']) . '" target="_blank" rel="noopener">' . e((string)$conn['account_name']) . ' ↗</a>' : e((string)$conn['account_name']))
             . ($conn['expires_at'] && $p === 'linkedin' && empty($conn['refresh_token']) ? ' <span class="muted">· connect again before ' . e(sm_time($conn['expires_at'], 'j M Y')) . '</span>' : '') . '</p>'
           : '<p class="small muted">Not connected' . (SmApps::ready($app) ? '.' : ': the ' . e(SmApps::LABELS[$app]) . ' app is not set up yet (super admin, "Platform apps" below).') . '</p>')
       . '</div>'
       . ($p === 'x' ? '<div class="field"><label for="xl">Automatic X posts a month, at most</label><input type="number" min="0" max="1000" id="xl" name="x_auto_limit" value="' . (int)SmEvent::settings($aid)['x_auto_limit'] . '">'
           . '<p class="hint">X charges for every post made by software (about US$0.015 a post, US$0.20 with a link, Oct 2026), from credit bought in X\'s developer console. When the limit is reached, X posts go back to "by hand" for the month.</p></div>' 
           . '<div class="field"><label for="xr">X post figures read a month, at most</label><input type="number" min="0" max="5000" id="xr" name="x_read_limit" value="' . (int)SmEvent::settings($aid)['x_read_limit'] . '">'
           . '<p class="hint">For Results, the likes, replies, reposts and views of each automatic X post are read 1, 7 and 28 days after posting. X charges about US$0.005 for each post read (' . SmStats::xReadsUsed($aid) . ' used this month). 0 switches it off.</p></div>' : '')
       . '</fieldset>';
}
echo '</div></section><div class="save-bar"><button class="btn" type="submit">Save platform settings</button></div></form>';

// Platform apps: once for the whole platform (super admins).
if (sm_is_super()) {
    echo '<details class="set-card" id="apps"' . (sm_in('apps') !== '' ? ' open' : '') . '><summary class="set-head" style="cursor:pointer"><h2 style="display:inline">Platform apps (super admin, set once)</h2><p>Our own apps on Meta, LinkedIn and X, which every exhibition connects through. Make them as described in the approval guide, then paste their keys here.</p></summary><div class="set-body">'
       . '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="apps">'
       . '<div class="field"><label>Redirect / callback address to enter in all three apps</label><code class="sm-code">' . e(SmApps::redirectUri()) . '</code> <button class="btn btn-small btn-quiet" type="button" data-copy-text="' . e(SmApps::redirectUri()) . '">Copy</button></div>'
       . '<div class="field"><label>Privacy policy and data deletion pages (asked for by Meta)</label><code class="sm-code">' . e(sm_url('privacy.php')) . '</code><br><code class="sm-code">' . e(sm_url('privacy.php') . '#deletion') . '</code></div>';
    foreach (SmApps::LABELS as $app => $label) {
        $a = SmApps::get($app);
        echo '<fieldset class="sm-platform"><legend>' . e($label) . ($a['id'] !== '' && $a['secret'] !== '' ? ' <span class="pill pill-ok">Set</span>' : '') . '</legend><div class="form-grid">'
           . f_text('app[' . $app . '][id]', $app === 'meta' ? 'App ID' : 'Client ID', $a['id'], ['maxlength' => 190])
           . f_text('app[' . $app . '][secret]', $app === 'meta' ? 'App secret' : 'Client secret', '', ['type' => 'password', 'placeholder' => $a['secret'] !== '' ? 'Saved — leave empty to keep it' : ''])
           . '</div></fieldset>';
    }
    echo '<div class="form-grid">'
       . f_text('meta_config_id', 'Meta "Facebook Login for Business" configuration ID', SmApps::setting('meta_config_id', ''), ['hint' => 'From the Meta app → Facebook Login for Business → Configurations (token type: User access token). Leave empty if the app uses classic Facebook Login.'])
       . f_text('graph_version', 'Meta Graph API version', SmApps::graphVersion(), ['hint' => 'Meta retires versions after about two years; move it on when Meta asks.'])
       . f_text('linkedin_version', 'LinkedIn API version (YYYYMM)', SmApps::linkedinVersion(), ['hint' => 'LinkedIn retires each monthly version after about a year.'])
       . f_text('company_name', 'Company named on the privacy page', SmApps::company())
       . f_text('contact_email', 'Contact email on the privacy page', SmApps::contactEmail(), ['type' => 'email'])
       . '</div><button class="btn" type="submit">Save platform apps</button></form></div></details>';
}
sm_layout_end();
