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
    if ($do === 'platforms') {
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
            db()->update('sm_platforms', [
                'enabled' => !empty($in['enabled']) ? 1 : 0,
                'mode' => 'manual',
                'page_url' => $safe((string)($in['page_url'] ?? '')),
                'posts_per_week' => max(0, min(28, (int)($in['posts_per_week'] ?? 3))),
                'default_time' => $time,
                'hashtags' => mb_substr(trim((string)($in['hashtags'] ?? '')), 0, 500),
            ], 'agent_id = ? AND platform = ?', [$aid, $p]);
        }
        SmActivity::log($aid, 'platforms_saved', 'Platform settings');
        sm_flash('Platform settings saved.');
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

echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="platforms">';
echo '<section class="set-card" id="platforms"><div class="set-head"><h2>Social media platforms</h2><p>Which of the event\'s pages the agent writes for. Until Meta and LinkedIn approve the direct connection, every platform is posted by hand: the agent prepares the post, reminds you at the scheduled time, and you press "Done".</p></div><div class="set-body">';
foreach ($platforms as $p => $r) {
    $n = SmEvent::PLATFORMS[$p];
    echo '<fieldset class="sm-platform"><legend>' . e($n) . '</legend>'
       . '<div class="check"><input type="checkbox" id="en_' . $p . '" name="p[' . $p . '][enabled]" value="1"' . ((int)$r['enabled'] ? ' checked' : '') . '><label for="en_' . $p . '">Write posts for ' . e($n) . '</label></div>'
       . '<div class="form-grid">'
       . '<div class="field"><label for="pu_' . $p . '">The event\'s ' . e($n) . ' page</label><input type="url" id="pu_' . $p . '" name="p[' . $p . '][page_url]" value="' . e((string)$r['page_url']) . '" placeholder="https://…"></div>'
       . '<div class="field"><label for="pw_' . $p . '">Posts per week</label><input type="number" min="0" max="28" id="pw_' . $p . '" name="p[' . $p . '][posts_per_week]" value="' . (int)$r['posts_per_week'] . '"></div>'
       . '<div class="field"><label for="dt_' . $p . '">Usual posting time (' . e(SmEvent::tz($agent)->getName()) . ')</label><input type="time" id="dt_' . $p . '" name="p[' . $p . '][default_time]" value="' . e((string)$r['default_time']) . '"></div>'
       . '</div>'
       . '<div class="field"><label for="ht_' . $p . '">Hashtags always added</label><input type="text" id="ht_' . $p . '" name="p[' . $p . '][hashtags]" value="' . e((string)$r['hashtags']) . '" placeholder="#BuildAsia2027"></div>'
       . '<p class="small muted">Connection: <strong>by hand</strong>. Direct posting will be offered here once ' . ($p === 'linkedin' ? 'LinkedIn approves' : ($p === 'x' ? 'X access is arranged' : 'Meta approves')) . ' the app (Stage 3).</p>'
       . '</fieldset>';
}
echo '</div></section><div class="save-bar"><button class="btn" type="submit">Save platform settings</button></div></form>';
sm_layout_end();
