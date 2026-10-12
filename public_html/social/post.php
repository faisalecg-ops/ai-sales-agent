<?php
declare(strict_types=1);

/**
 * One social post: preview it as it will look, edit it, choose or build its
 * picture, approve it, then Post now or Schedule. Platforms in manual mode
 * show Copy text / Download picture / Open the page, then "Done — I posted it".
 */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$uid = (int)$user['id'];
$id = (int)sm_in('id');
$p = db()->one('SELECT * FROM sm_posts WHERE id = ? AND agent_id = ?', [$id, $aid]);
if (!$p) {
    sm_not_permitted('That post is not part of ' . $agent['name'] . '.');
}
$platforms = SmEvent::platforms($aid);
$plat = $platforms[$p['platform']] ?? ['page_url' => '', 'bio_url' => '', 'default_time' => '11:00', 'mode' => 'manual'];
$back = static fn() => sm_redirect('post.php', ['id' => $id]);
$version = static fn(array $r): string => sm_version((string)$r['body'], (string)$r['hashtags'], (string)$r['include_link'], (string)$r['media_id']);
$https = static function (string $url): ?string {
    $url = trim($url);
    if (preg_match('#^https://[^\s/]+\.[^\s]+$#i', $url) !== 1) {
        return null;
    }
    $v = Validator::url($url);
    return $v !== null && preg_match('#^https://#i', $v) === 1 ? mb_substr($v, 0, 500) : null;
};
$tz = SmEvent::tz($agent);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    $open = !in_array($p['status'], ['posted', 'cancelled'], true);

    if ($do === 'save' && $open) {
        $wasLive = in_array($p['status'], ['approved', 'scheduled', 'due'], true);
        db()->update('sm_posts', [
            'body' => mb_substr(trim((string)($_POST['body'] ?? '')), 0, 5000),
            'hashtags' => mb_substr(sm_in('hashtags'), 0, 500),
            'include_link' => isset($_POST['include_link']) ? 1 : 0,
            'edited_by' => $uid,
            'status' => $wasLive ? 'draft' : $p['status'],
            'scheduled_at' => $wasLive ? null : $p['scheduled_at'],
        ], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_edited', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash($wasLive ? 'Saved. Because it changed after approval, it needs approving again (any schedule was cleared).' : 'Saved.');
        $back();
    }
    if ($do === 'media' && $open) {
        $mid = (int)sm_in('media_id');
        if (!empty($_FILES['file']['name'])) {
            $up = SmMedia::upload($aid, $_FILES['file'], 'Uploaded for a ' . SmEvent::PLATFORMS[$p['platform']] . ' post', $uid);
            if (!$up['ok']) {
                sm_flash((string)$up['error'], 'error');
                $back();
            }
            $mid = (int)$up['id'];
        } elseif (sm_in('headline') !== '') {
            $b = SmMedia::build($agent, sm_in('headline'), sm_in('shape') ?: SmMedia::PLATFORM_SHAPE[$p['platform']], (int)sm_in('photo_id') ?: null, $uid);
            if (!$b['ok']) {
                sm_flash((string)$b['error'], 'error');
                $back();
            }
            $mid = (int)$b['id'];
        } elseif (sm_in('ai_prompt') !== '') {
            $shape = ['instagram' => 'portrait', 'facebook' => 'square'][$p['platform']] ?? 'landscape';
            $bytes = SmAi::image($agent, sm_in('ai_prompt'), $shape);
            if (is_array($bytes)) {
                sm_flash((string)$bytes['_error'], 'error');
                $back();
            }
            $st = SmMedia::store($aid, $bytes, 'AI picture: ' . mb_substr(sm_in('ai_prompt'), 0, 150), 'ai', $uid);
            if (!$st['ok']) {
                sm_flash((string)$st['error'], 'error');
                $back();
            }
            $mid = (int)$st['id'];
            if (isset($_POST['brand_it'])) {
                $b = SmMedia::build($agent, sm_in('ai_headline') ?: (string)$agent['name'], SmMedia::PLATFORM_SHAPE[$p['platform']], $mid, $uid);
                if ($b['ok']) {
                    $mid = (int)$b['id'];
                }
            }
        }
        if ($mid > 0 && SmMedia::find($aid, $mid)) {
            $wasLive = in_array($p['status'], ['approved', 'scheduled', 'due'], true);
            db()->update('sm_posts', ['media_id' => $mid, 'edited_by' => $uid, 'status' => $wasLive ? 'draft' : $p['status'], 'scheduled_at' => $wasLive ? null : $p['scheduled_at']], 'id = ?', [$id]);
            sm_flash('Picture changed.' . ($wasLive ? ' It needs approving again.' : ''));
        }
        $back();
    }
    if ($do === 'submit' && $p['status'] === 'draft') {
        db()->update('sm_posts', ['status' => 'in_review', 'review_note' => null], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_submitted', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash('Sent for approval.');
        $back();
    }
    if ($do === 'approve' && sm_can('approver') && in_array($p['status'], ['draft', 'in_review'], true)) {
        if (((int)$p['written_by'] === $uid || (int)$p['edited_by'] === $uid) && !sm_can('admin')) {
            sm_flash('You asked for or edited this post, so someone else must approve it (or an admin).', 'error');
            $back();
        }
        if (sm_in('v') !== $version($p)) {
            sm_flash('The post changed while you were reading it. Read the new version, then approve.', 'error');
            $back();
        }
        if (($why = SmFlow::blocker($agent, $p)) !== null) {
            sm_flash('Not yet: ' . $why, 'error');
            $back();
        }
        db()->update('sm_posts', ['status' => 'approved', 'approved_by' => $uid], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_approved', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash('Approved. Now Post now, or Schedule it.');
        $back();
    }
    if ($do === 'sendback' && sm_can('approver') && $p['status'] === 'in_review') {
        db()->update('sm_posts', ['status' => 'draft', 'review_note' => mb_substr(sm_in('note'), 0, 2000)], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_sent_back', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash('Sent back with your note.');
        $back();
    }
    if ($do === 'post_now' && sm_can('approver') && in_array($p['status'], ['approved', 'scheduled'], true)) {
        if (($why = SmFlow::blocker($agent, $p)) !== null) {
            sm_flash('Not yet: ' . $why, 'error');
            $back();
        }
        db()->update('sm_posts', ['status' => 'due', 'scheduled_at' => gmdate('Y-m-d H:i:s'), 'reminder_sent_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_now', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        $back();
    }
    if ($do === 'schedule' && sm_can('approver') && in_array($p['status'], ['approved', 'scheduled', 'due'], true)) {
        $utc = sm_local_to_utc(sm_in('when'));
        if ($utc === null || strtotime($utc . ' UTC') < time() + 60) {
            sm_flash('Choose a date and time in the future.', 'error');
            $back();
        }
        if (($why = SmFlow::blocker($agent, $p)) !== null) {
            sm_flash('Not yet: ' . $why, 'error');
            $back();
        }
        db()->update('sm_posts', ['status' => 'scheduled', 'scheduled_at' => $utc, 'reminder_sent_at' => null], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_scheduled', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id . ' for ' . sm_time($utc));
        sm_flash('Scheduled for ' . sm_time($utc) . ' (' . $tz->getName() . ').');
        $back();
    }
    if ($do === 'unschedule' && sm_can('approver') && in_array($p['status'], ['scheduled', 'due'], true)) {
        db()->update('sm_posts', ['status' => 'approved', 'scheduled_at' => null, 'reminder_sent_at' => null], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_unscheduled', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash('Taken off the schedule. It stays approved.');
        $back();
    }
    if ($do === 'done' && sm_can('editor') && in_array($p['status'], ['approved', 'scheduled', 'due'], true)) {
        $url = sm_in('posted_url');
        db()->update('sm_posts', [
            'status' => 'posted', 'posted_at' => gmdate('Y-m-d H:i:s'), 'posted_by' => $uid,
            'posted_url' => $url !== '' ? $https($url) : null,
        ], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_done', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id . ' marked as posted');
        sm_flash('Marked as posted. Thank you.');
        $back();
    }
    if ($do === 'cancel' && $open) {
        db()->update('sm_posts', ['status' => 'cancelled', 'scheduled_at' => null], 'id = ?', [$id]);
        SmActivity::log($aid, 'post_cancelled', SmEvent::PLATFORMS[$p['platform']] . ' post #' . $id);
        sm_flash('Cancelled.');
        sm_redirect('posts.php');
    }
    $back();
}

$link = SmFlow::link($agent, $p);
$full = SmChecks::fullText($p, $link);
$media = $p['media_id'] ? SmMedia::find($aid, (int)$p['media_id']) : null;
$checks = SmChecks::post($agent, $p, $link);
$article = $p['article_id'] ? db()->one('SELECT id, title, status, blog_url FROM sm_articles WHERE id = ?', [(int)$p['article_id']]) : null;
$pname = SmEvent::PLATFORMS[$p['platform']];
// Industry Voices writer to tag, and the paid-partnership label for sponsored articles.
$credits = SmFlow::credits($p['article_id'] ? (int)$p['article_id'] : null);
$creditSteps = '';
if ($v = $credits['voice']) {
    $creditSteps .= '<li>Tag the writer, <strong>' . e((string)$v['name']) . '</strong>'
        . ($v['linkedin_url'] !== '' ? ' (<a href="' . e((string)$v['linkedin_url']) . '" target="_blank" rel="noopener">LinkedIn ↗</a>)' : '')
        . ($v['company'] !== '' ? ', and their company, <strong>' . e((string)$v['company']) . '</strong>' . ($v['company_url'] !== '' ? ' (<a href="' . e((string)$v['company_url']) . '" target="_blank" rel="noopener">website ↗</a>)' : '') : '')
        . '. Type @ and their name in the post to tag them.</li>';
}
if ($sp = $credits['sponsor']) {
    $creditSteps .= '<li><strong>Paid content for ' . e((string)$sp['name']) . ':</strong> keep the first line "' . e(SmFlow::disclosure($sp)) . '"'
        . (in_array($p['platform'], ['facebook', 'instagram'], true) ? ', and switch on <em>Add paid partnership label</em> (tag ' . e((string)$sp['name']) . ' as the brand partner)'
            : ($p['platform'] === 'linkedin' ? ', and switch on <em>Brand partnership</em> if LinkedIn offers it on the page' : ', and add #ad'))
        . '. The platforms\' rules require it.</li>';
}
$editable = sm_can('editor') && !in_array($p['status'], ['posted', 'cancelled'], true);

// Suggested time: the platform's usual time, next time it comes round.
$t = DateTimeImmutable::createFromFormat('Y-m-d H:i', (new DateTimeImmutable('now', $tz))->format('Y-m-d') . ' ' . ($plat['default_time'] ?: '11:00'), $tz) ?: new DateTimeImmutable('now', $tz);
if ($t <= new DateTimeImmutable('+30 minutes', $tz)) {
    $t = $t->modify('+1 day');
}
$suggest = $p['scheduled_at'] && $p['status'] === 'scheduled'
    ? (new DateTimeImmutable($p['scheduled_at'] . ' UTC'))->setTimezone($tz)->format('Y-m-d\TH:i')
    : $t->format('Y-m-d\TH:i');

sm_layout_start($pname . ' post');

if ($credits['voice'] || $credits['sponsor']) {
    echo '<div class="alert alert-info"><p style="margin:0">'
       . ($credits['sponsor'] ? '<strong>' . e((string)($credits['sponsor']['label'] ?: 'Sponsored')) . '</strong> article, paid for by ' . e((string)$credits['sponsor']['name']) . '. Every post must say so. ' : '')
       . ($credits['voice'] ? '<strong>Industry Voices</strong> article by ' . e((string)$credits['voice']['name']) . ($credits['voice']['company'] !== '' ? ', ' . e((string)$credits['voice']['company']) : '') . ': credit and tag them.' : '')
       . '</p></div>';
}
echo '<div class="card"><p style="margin-top:0">' . sm_pill((string)$p['status'])
   . ($p['scheduled_at'] && in_array($p['status'], ['scheduled', 'due'], true) ? ' <strong>' . e(sm_time($p['scheduled_at'])) . '</strong>' : '')
   . ($p['status'] === 'posted' ? ' <span class="muted">' . e(sm_time($p['posted_at'])) . '</span>' . ($p['posted_url'] ? ' · <a href="' . e((string)$p['posted_url']) . '" target="_blank" rel="noopener">See the post ↗</a>' : '') : '')
   . ($article ? ' <span class="muted small">· from the article <a href="' . e(sm_url('article.php', ['id' => $article['id']])) . '">' . e((string)$article['title']) . '</a></span>' : '') . '</p>';
if ($p['review_note']) {
    echo '<div class="alert alert-warn"><p style="margin:0"><strong>Note from the approver:</strong> ' . e((string)$p['review_note']) . '</p></div>';
}

// Posting panel: shown when it is time to post (manual mode).
if (in_array($p['status'], ['due', 'approved', 'scheduled'], true) && sm_can('editor')) {
    $isDue = $p['status'] === 'due';
    echo '<div class="sm-postit' . ($isDue ? ' is-due' : '') . '"><h2 style="margin-top:0">' . ($isDue ? 'Post it now on ' . e($pname) : 'When it is time to post') . '</h2>'
       . '<ol class="sm-steps"><li><button class="btn btn-small" type="button" data-copy="#sm-full">Copy the text</button></li>'
       . ($media ? '<li><a class="btn btn-small" href="' . e(SmMedia::url($media)) . '" download>Download the ' . ($media['kind'] === 'video' ? 'video' : 'picture') . '</a></li>' : '')
       . '<li>' . ($plat['page_url'] ? '<a class="btn btn-small" href="' . e((string)$plat['page_url']) . '" target="_blank" rel="noopener">Open the event\'s ' . e($pname) . ' page ↗</a>' : 'Open the event\'s ' . e($pname) . ' page <span class="muted small">(add its address under Blog and platforms)</span>') . '</li>'
       . ($p['platform'] === 'instagram' && $link ? '<li>Put this link in the bio or a story: <code class="sm-code">' . e($link) . '</code> <button class="btn btn-small btn-quiet" type="button" data-copy-text="' . e($link) . '">Copy link</button></li>' : '')
       . $creditSteps
       . '<li>Paste the text, add the ' . ($media && $media['kind'] === 'video' ? 'video' : 'picture') . ', post it, then:</li></ol>'
       . '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="done"><input type="hidden" name="id" value="' . $id . '">'
       . f_text('posted_url', 'Link to the post (optional)', '', ['placeholder' => 'https://…'])
       . '<button class="btn btn-ok" type="submit">Done — I posted it</button></form>'
       . '<textarea id="sm-full" class="sr-only" readonly>' . e($full) . '</textarea></div>';
}

// Approve / schedule buttons
echo '<div class="btn-row">';
if ($editable && $p['status'] === 'draft') {
    echo sm_button('post.php', ['do' => 'submit', 'id' => $id], 'Send for approval', 'btn');
}
if (sm_can('approver') && in_array($p['status'], ['draft', 'in_review'], true)) {
    echo sm_button('post.php', ['do' => 'approve', 'id' => $id, 'v' => $version($p)], 'Approve', 'btn btn-ok');
}
if (sm_can('approver') && in_array($p['status'], ['approved', 'scheduled'], true)) {
    echo sm_button('post.php', ['do' => 'post_now', 'id' => $id], 'Post now', 'btn btn-ok');
}
if (sm_can('approver') && in_array($p['status'], ['scheduled', 'due'], true)) {
    echo sm_button('post.php', ['do' => 'unschedule', 'id' => $id], 'Take off the schedule', 'btn btn-quiet');
}
if ($editable) {
    echo sm_button('post.php', ['do' => 'cancel', 'id' => $id], 'Cancel this post', 'btn btn-danger', 'Cancel this post? It will not be posted.');
}
echo '</div>';
if (sm_can('approver') && in_array($p['status'], ['approved', 'scheduled', 'due'], true)) {
    echo '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="schedule"><input type="hidden" name="id" value="' . $id . '">'
       . f_text('when', 'Schedule for (' . $tz->getName() . ' time)', $suggest, ['type' => 'datetime-local', 'hint' => 'Suggested: the usual ' . $pname . ' time for this event (' . ($plat['default_time'] ?: '11:00') . ').'])
       . '<button class="btn" type="submit">' . ($p['status'] === 'scheduled' ? 'Move to this time' : 'Schedule') . '</button></form>';
}
if (sm_can('approver') && $p['status'] === 'in_review') {
    echo '<details class="small"><summary>Send back with a note</summary><form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="sendback"><input type="hidden" name="id" value="' . $id . '">'
       . f_area('note', 'What should change', '', ['rows' => 3]) . '<button class="btn btn-small" type="submit">Send back</button></form></details>';
}
echo '</div>';

echo '<div class="grid grid-2">';
// Preview as it will look
echo '<div class="card"><h2>Preview</h2><div class="sm-preview sm-pv-' . e((string)$p['platform']) . '">'
   . '<div class="sm-pv-head"><span class="sm-pv-avatar">' . e(mb_substr((string)$agent['name'], 0, 1)) . '</span><strong>' . e((string)$agent['name']) . '</strong></div>';
if ($p['platform'] === 'instagram' && $media) {
    echo self_media($media);
}
echo '<div class="sm-pv-text">' . nl2br(e($full)) . '</div>';
if ($p['platform'] !== 'instagram' && $media) {
    echo self_media($media);
}
echo '</div><ul class="plain" style="margin-top:12px">';
foreach ($checks as $c) {
    echo '<li class="chk chk-' . e((string)$c['level']) . '"><b>' . (['ok' => '✓', 'warn' => '!', 'bad' => '✗'][$c['level']] ?? '·') . '</b> ' . e((string)$c['message']) . '</li>';
}
echo '</ul></div>';

// Edit
echo '<div class="card"><h2>Text</h2>';
if ($editable) {
    $limit = SmWriter::LIMITS[$p['platform']];
    echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . $id . '">'
       . f_area('body', 'Post text', (string)$p['body'], ['rows' => 10, 'data' => 'data-count="' . $limit . '"'])
       . f_text('hashtags', 'Hashtags', (string)$p['hashtags'])
       . ($p['article_id'] ? f_check('include_link', 'Include the blog post link', (int)$p['include_link'] === 1, $p['platform'] === 'instagram' ? 'Instagram captions cannot hold links: the post says "Link in bio" and the link is shown to put in the bio or a story.' : 'The link carries a tracking tag so visits from this post are counted.') : '')
       . '<button class="btn" type="submit">Save</button></form>';
} else {
    echo '<p>' . nl2br(e((string)$p['body'])) . '</p>';
}
echo '</div></div>';

// Picture
if ($editable) {
    $imgs = db()->all("SELECT id, label, kind FROM sm_media WHERE agent_id = ? ORDER BY id DESC LIMIT 80", [$aid]);
    $opts = ['' => 'Choose…'];
    $photos = ['' => 'None (plain background)'];
    foreach ($imgs as $m) {
        $opts[(string)$m['id']] = '#' . $m['id'] . ' ' . ($m['kind'] === 'video' ? '[video] ' : '') . mb_strimwidth((string)$m['label'], 0, 60, '…');
        if ($m['kind'] === 'image') {
            $photos[(string)$m['id']] = '#' . $m['id'] . ' ' . mb_strimwidth((string)$m['label'], 0, 60, '…');
        }
    }
    $shapes = array_map(static fn($s) => $s[2], SmMedia::SHAPES);
    unset($shapes['cover']);
    echo '<div class="card"><h2>Picture or video</h2><div class="grid grid-3">'
       . '<form method="post" enctype="multipart/form-data">' . sm_csrf() . '<input type="hidden" name="do" value="media"><input type="hidden" name="id" value="' . $id . '"><h3>Use your own</h3>'
       . f_select('media_id', 'From the library', $opts, (string)($p['media_id'] ?? ''))
       . '<div class="field"><label for="f_file">Or upload a finished picture or video</label><input type="file" id="f_file" name="file" accept="image/*,video/mp4,video/quicktime,video/webm"></div>'
       . '<button class="btn btn-small" type="submit">Use it</button></form>'
       . '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="media"><input type="hidden" name="id" value="' . $id . '"><h3>Build a branded image</h3>'
       . f_text('headline', 'Headline on the image', '', ['maxlength' => 90, 'placeholder' => 'Short and strong, under 60 characters'])
       . f_select('photo_id', 'Photo behind it', $photos, '')
       . f_select('shape', 'Shape', $shapes, SmMedia::PLATFORM_SHAPE[$p['platform']])
       . '<button class="btn btn-small" type="submit">Build it</button></form>';
    if (SmAi::imagesReady() && SmEvent::settings($aid)['ai_images']) {
        echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="media"><input type="hidden" name="id" value="' . $id . '"><h3>AI picture</h3>'
           . f_area('ai_prompt', 'Describe a general illustration', '', ['rows' => 3, 'hint' => 'Concepts and scenes only — never real machinery models, people or logos. ' . SmAi::used($aid, 'image') . ' of ' . SmAi::limit($aid, 'image') . ' used this month.'])
           . f_check('brand_it', 'Add the event\'s branding and a headline', true)
           . f_text('ai_headline', 'Headline', '', ['maxlength' => 90])
           . '<button class="btn btn-small" type="submit" data-busy="Making the picture…">Make it</button></form>';
    } else {
        echo '<div><h3>AI picture</h3><p class="muted small">' . (SmEvent::settings($aid)['ai_images'] ? 'Needs an OpenAI key in the sales agent\'s Settings.' : 'Switched off for this exhibition.') . '</p></div>';
    }
    echo '</div></div>';
}
sm_layout_end();

function self_media(array $m): string
{
    return $m['kind'] === 'video'
        ? '<video class="sm-pv-media" src="' . e(SmMedia::url($m)) . '" controls preload="metadata"></video>'
        : '<img class="sm-pv-media" src="' . e(SmMedia::url($m)) . '" alt="">';
}
