<?php
declare(strict_types=1);

/**
 * One blog article: read and edit it, see its sources, the fact check and the
 * automatic checks, approve it (it then goes to the blog), and, once it is
 * published, its version for each platform.
 */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$uid = (int)$user['id'];
$id = (int)sm_in('id');
$a = db()->one('SELECT * FROM sm_articles WHERE id = ? AND agent_id = ?', [$id, $aid]);
if (!$a) {
    sm_not_permitted('That article is not part of ' . $agent['name'] . '.');
}
$back = static fn() => sm_redirect('article.php', ['id' => $id]);
$fromBlog = ($a['origin'] ?? 'agent') === 'blog';
$version = static fn(array $r): string => sm_version((string)$r['title'], (string)$r['excerpt'], (string)$r['body'], (string)$r['category']);
// Any change after approval (or after a failed send) needs a fresh approval.
$unapprove = ['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'approved_hash' => null];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    $editable = !$fromBlog && in_array($a['status'], ['draft', 'in_review', 'failed', 'approved'], true) && $a['body'] !== null;

    if ($do === 'save' && $editable) {
        $new = [
            'id' => $id,
            'title' => mb_substr(sm_in('title'), 0, 255) ?: $a['title'],
            'excerpt' => mb_substr(sm_in('excerpt'), 0, 400),
            'body' => SmHtml::clean((string)($_POST['body'] ?? '')),
            'sources' => $a['sources'],
        ];
        $checks = SmFlow::recheck($agent, $new);
        $fc = json_decode((string)$a['fact_check'], true) ?: [];
        if (($_POST['body'] ?? '') !== $a['body'] || $new['title'] !== $a['title']) {
            $fc['stale'] = true;
        }
        $wasApproved = (bool)$a['approved_by'];
        db()->update('sm_articles', array_merge([
            'fact_check' => json_encode($fc, JSON_UNESCAPED_UNICODE),
            'title' => $new['title'], 'excerpt' => $new['excerpt'], 'body' => $new['body'],
            'category' => mb_substr(sm_in('category'), 0, 120), 'tags' => mb_substr(sm_in('tags'), 0, 500),
            'checks' => json_encode($checks), 'edited_by' => $uid,
        ], $wasApproved ? $unapprove : []), 'id = ?', [$id]);
        SmActivity::log($aid, 'article_edited', (string)$new['title']);
        sm_flash($wasApproved ? 'Saved. Because it changed after approval, it needs approving again.' : 'Saved. The automatic checks were run again.');
        $back();
    }
    if ($do === 'refact' && $editable) {
        if (($why = SmAi::blocked($aid, 'text')) !== null) {
            sm_flash($why, 'error');
            $back();
        }
        $src = json_decode((string)$a['sources'], true) ?: [];
        $check = SmWriter::factCheck($agent, $a, (array)($src['used'] ?? []));
        if (isset($check['_error'])) {
            sm_flash((string)$check['_error'], 'error');
        } else {
            db()->update('sm_articles', ['fact_check' => json_encode($check, JSON_UNESCAPED_UNICODE)], 'id = ?', [$id]);
            sm_flash('The facts were checked again against the sources.');
        }
        $back();
    }
    if ($do === 'submit' && in_array($a['status'], ['draft', 'failed'], true) && $a['body']) {
        db()->update('sm_articles', ['status' => 'in_review', 'review_note' => null], 'id = ?', [$id]);
        SmActivity::log($aid, 'article_submitted', (string)$a['title']);
        sm_flash('Sent for approval.');
        $back();
    }
    if ($do === 'approve' && sm_can('approver') && in_array($a['status'], ['draft', 'in_review'], true) && $a['body']) {
        if (((int)$a['written_by'] === $uid || (int)$a['edited_by'] === $uid) && !sm_can('admin')) {
            sm_flash('You asked for or edited this article, so someone else must approve it (or an admin).', 'error');
            $back();
        }
        if (sm_in('v') !== $version($a)) {
            sm_flash('The article changed while you were reading it. Read the new version, then approve.', 'error');
            $back();
        }
        db()->update('sm_articles', ['status' => 'approved', 'approved_by' => $uid, 'approved_at' => gmdate('Y-m-d H:i:s'), 'approved_hash' => $version($a)], 'id = ?', [$id]);
        SmActivity::log($aid, 'article_approved', (string)$a['title']);
        $res = SmFlow::sendToBlog($agent, $id, $uid);
        sm_flash('Approved. ' . $res['message'], $res['ok'] ? 'ok' : 'error');
        $back();
    }
    if ($do === 'sendback' && sm_can('approver') && $a['status'] === 'in_review') {
        db()->update('sm_articles', ['status' => 'draft', 'review_note' => mb_substr(sm_in('note'), 0, 2000)], 'id = ?', [$id]);
        SmActivity::log($aid, 'article_sent_back', (string)$a['title']);
        sm_flash('Sent back with your note.');
        $back();
    }
    if ($do === 'retry_blog' && sm_can('approver') && in_array($a['status'], ['failed', 'approved'], true) && $a['approved_by']) {
        if ($a['approved_hash'] !== $version($a)) {
            db()->update('sm_articles', $unapprove, 'id = ?', [$id]);
            sm_flash('It changed since it was approved, so it needs approving again.', 'error');
            $back();
        }
        $res = SmFlow::sendToBlog($agent, $id, $uid);
        sm_flash($res['message'], $res['ok'] ? 'ok' : 'error');
        $back();
    }
    if ($do === 'rewrite' && $a['status'] === 'failed' && !$a['approved_by']) {
        if (($why = SmAi::blocked($aid, 'text')) !== null) {
            sm_flash($why, 'error');
            $back();
        }
        db()->update('sm_articles', ['status' => 'writing', 'error' => null], 'id = ?', [$id]);
        session_write_close();
        SmFlow::write($agent, $id);
        $back();
    }
    if ($do === 'check_blog' && $a['status'] === 'sent') {
        $blog = SmBlog::forAgent($aid);
        $r = $blog && $a['blog_article_id'] ? $blog->getArticle((int)$a['blog_article_id']) : ['ok' => false, 'message' => 'The blog is not connected.'];
        if ($r['ok']) {
            SmFlow::applyRemote($agent, $id, (array)($r['data']['article'] ?? []));
            sm_flash('Checked with the blog.');
        } else {
            sm_flash((string)$r['message'], 'error');
        }
        $back();
    }
    if ($do === 'versions' && $a['status'] === 'published') {
        $r = SmFlow::makeVersions($agent, $id, $uid);
        sm_flash($r['message'], $r['ok'] ? 'ok' : 'error');
        $back();
    }
    if ($do === 'cover' && $editable) {
        $mid = (int)sm_in('media_id');
        $reset = $a['approved_by'] ? $unapprove : [];
        if ($mid > 0 && SmMedia::find($aid, $mid)) {
            db()->update('sm_articles', ['cover_media_id' => $mid] + $reset, 'id = ?', [$id]);
        } elseif (sm_in('headline') !== '') {
            $b = SmMedia::build($agent, sm_in('headline'), 'cover', (int)sm_in('photo_id') ?: null, $uid);
            if ($b['ok']) {
                db()->update('sm_articles', ['cover_media_id' => $b['id']] + $reset, 'id = ?', [$id]);
            } else {
                sm_flash((string)$b['error'], 'error');
            }
        }
        $back();
    }
    if ($do === 'discard' && !in_array($a['status'], ['sent', 'published', 'writing'], true)) {
        db()->update('sm_articles', ['status' => 'rejected'], 'id = ?', [$id]);
        SmActivity::log($aid, 'article_discarded', (string)$a['title']);
        sm_flash('Discarded.');
        sm_redirect('articles.php');
    }
    $back();
}

$src = json_decode((string)$a['sources'], true) ?: [];
$fact = json_decode((string)$a['fact_check'], true) ?: [];
$checks = json_decode((string)$a['checks'], true) ?: [];
$cover = $a['cover_media_id'] ? SmMedia::find($aid, (int)$a['cover_media_id']) : null;
$posts = db()->all("SELECT * FROM sm_posts WHERE article_id = ? AND status <> 'cancelled' ORDER BY FIELD(platform,'linkedin','facebook','instagram','x')", [$id]);
$writer = $a['written_by'] ? db()->one('SELECT username, full_name FROM admin_users WHERE id = ?', [(int)$a['written_by']]) : null;
$editable = !$fromBlog && in_array($a['status'], ['draft', 'in_review', 'failed', 'approved'], true) && $a['body'] !== null && sm_can('editor');

sm_layout_start('Article');
echo '<div class="card"><p style="margin-top:0">' . sm_pill((string)$a['status'])
   . ($fromBlog ? ' <span class="pill pill-info">Written in the blog</span> <span class="muted small">by the blog\'s own team · picked up ' . e(sm_time($a['created_at'])) . '</span></p>'
               : ' <span class="muted small">' . e(SmEvent::PILLARS[$a['pillar']][0] ?? '') . ' · asked for by '
   . e($writer ? (string)($writer['full_name'] ?: $writer['username']) : '—') . ' · ' . e(sm_time($a['created_at'])) . '</span></p>')
   . '<h2 style="margin:.2rem 0">' . e((string)$a['title']) . '</h2>';
$cr = SmFlow::credits($id);
if ($cr['voice'] || $cr['sponsor']) {
    echo '<p>' . ($cr['voice'] ? '<span class="pill pill-info">Industry Voices</span> by <strong>' . e((string)$cr['voice']['name']) . '</strong>' . (trim($cr['voice']['designation'] . ', ' . $cr['voice']['company'], ', ') !== '' ? ', ' . e(trim($cr['voice']['designation'] . ', ' . $cr['voice']['company'], ', ')) : '') . ' ' : '')
       . ($cr['sponsor'] ? '<span class="pill pill-warn">' . e((string)($cr['sponsor']['label'] ?: 'Sponsored')) . '</span> paid for by <strong>' . e((string)$cr['sponsor']['name']) . '</strong> — its posts carry the label' : '') . '</p>';
}
if ($a['status'] === 'writing') {
    echo '<p class="lede">The agent is researching and writing this article. It usually takes one to three minutes. <a href="' . e(sm_url('article.php', ['id' => $id])) . '">Refresh</a> to see it.</p>';
}
if ($a['error']) {
    echo '<div class="alert alert-error"><p style="margin:0">' . e((string)$a['error']) . '</p></div>';
}
if ($a['review_note']) {
    echo '<div class="alert alert-warn"><p style="margin:0"><strong>Note from the approver:</strong> ' . e((string)$a['review_note']) . '</p></div>';
}
if ($a['blog_url']) {
    echo '<p>Published on the blog: <a href="' . e((string)$a['blog_url']) . '" target="_blank" rel="noopener">' . e((string)$a['blog_url']) . '</a></p>';
} elseif ($a['status'] === 'sent') {
    echo '<p>The blog is holding it for approval in its own admin. It is checked again every few minutes. ' . sm_button('article.php', ['do' => 'check_blog', 'id' => $id], 'Check now', 'btn btn-small btn-quiet') . '</p>';
}

// Actions
echo '<div class="btn-row">';
if (sm_can('editor') && in_array($a['status'], ['draft'], true) && $a['body']) {
    echo sm_button('article.php', ['do' => 'submit', 'id' => $id], 'Send for approval', 'btn');
}
if (sm_can('approver') && in_array($a['status'], ['draft', 'in_review'], true) && $a['body']) {
    echo sm_button('article.php', ['do' => 'approve', 'id' => $id, 'v' => $version($a)], 'Approve and publish on the blog', 'btn btn-ok', 'Approve this article? It is sent to the event blog and published there.');
}
if (sm_can('approver') && in_array($a['status'], ['failed', 'approved'], true) && $a['approved_by']) {
    echo sm_button('article.php', ['do' => 'retry_blog', 'id' => $id], 'Try sending to the blog again', 'btn');
}
if (sm_can('editor') && $a['status'] === 'failed' && !$a['approved_by']) {
    echo sm_button('article.php', ['do' => 'rewrite', 'id' => $id], 'Write it again', 'btn', '');
}
if (sm_can('editor') && $a['status'] === 'published') {
    echo sm_button('article.php', ['do' => 'versions', 'id' => $id], 'Write versions for missing platforms', 'btn btn-quiet');
}
if (sm_can('editor') && !in_array($a['status'], ['sent', 'published', 'rejected', 'writing'], true)) {
    echo sm_button('article.php', ['do' => 'discard', 'id' => $id], 'Discard', 'btn btn-danger', 'Discard this article?');
}
echo '</div>';
if (sm_can('approver') && $a['status'] === 'in_review') {
    echo '<details class="small"><summary>Send back with a note</summary><form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="sendback"><input type="hidden" name="id" value="' . $id . '">'
       . f_area('note', 'What should change', '', ['rows' => 3]) . '<button class="btn btn-small" type="submit">Send back</button></form></details>';
}
echo '</div>';

if ($a['body'] !== null && $fromBlog) {
    echo '<div class="card"><p class="muted">This article was written and approved in the blog itself, so it is shown here read-only. Its social media versions below go through the normal approval.</p>'
       . '<p class="lede">' . e((string)$a['excerpt']) . '</p><div class="sm-prose">' . $a['body'] . '</div></div>';
} elseif ($a['body'] !== null) {
    echo '<div class="grid sm-article">';
    // Checks first: they are what the approver needs to see.
    echo '<div class="card"><h2>Checks</h2><ul class="plain">';
    foreach ($checks as $c) {
        $icon = ['ok' => '✓', 'warn' => '!', 'bad' => '✗'][$c['level']] ?? '·';
        echo '<li class="chk chk-' . e((string)$c['level']) . '"><b>' . $icon . '</b> ' . e((string)$c['message']) . '</li>';
    }
    echo '</ul><h3>Fact check against the sources</h3>';
    if (!empty($fact['stale'])) {
        echo '<div class="alert alert-warn"><p style="margin:0">The text has changed since this fact check.'
           . ($editable ? ' ' . sm_button('article.php', ['do' => 'refact', 'id' => $id], 'Check the facts again', 'btn btn-small') : '') . '</p></div>';
    }
    if (($fact['verdict'] ?? '') === 'ok' && empty($fact['issues'])) {
        echo '<p class="chk chk-ok"><b>✓</b> Every fact checked matches its source.</p>';
    } elseif (!empty($fact['issues'])) {
        echo '<ul class="plain">';
        foreach ((array)$fact['issues'] as $i) {
            echo '<li class="chk chk-bad"><b>✗</b> “' . e((string)($i['quote'] ?? '')) . '” — ' . e((string)($i['problem'] ?? '')) . (!empty($i['fix']) ? ' <span class="muted">Suggested: ' . e((string)$i['fix']) . '</span>' : '') . '</li>';
        }
        echo '</ul><p class="hint">Edit the text to fix these before approving, or remove the sentence.</p>';
    } else {
        echo '<p class="muted">' . e((string)($fact['error'] ?? 'The fact check did not run.')) . '</p>';
    }
    echo '<h3>Sources</h3><ol class="small">';
    foreach ((array)($src['used'] ?? []) as $s) {
        echo '<li>' . ($s['url'] !== '' ? '<a href="' . e((string)$s['url']) . '" target="_blank" rel="noopener">' . e((string)$s['title']) . '</a>' : e((string)$s['title']) . ' <span class="muted">(' . e((string)$s['ref']) . ', approved knowledge)</span>') . '</li>';
    }
    echo '</ol></div>';

    // The article itself.
    echo '<div class="card">';
    if ($editable) {
        $cats = ($b = SmBlog::forAgent($aid)) ? $b->categories() : [];
        $catOpts = [];
        foreach ($cats as $c) {
            $catOpts[(string)$c['slug']] = (string)$c['name'];
        }
        if ($a['category'] && !isset($catOpts[$a['category']])) {
            $catOpts[(string)$a['category']] = (string)$a['category'];
        }
        echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . $id . '">'
           . f_text('title', 'Title', (string)$a['title'], ['maxlength' => 255])
           . f_area('excerpt', 'Summary (under the title and on cards)', (string)$a['excerpt'], ['rows' => 2, 'maxlength' => 400])
           . '<div class="form-grid">' . ($catOpts ? f_select('category', 'Blog category', $catOpts, (string)$a['category']) : f_text('category', 'Blog category', (string)$a['category']))
           . f_text('tags', 'Tags (comma separated)', (string)$a['tags']) . '</div>'
           . '<div class="field"><label>Preview</label><div class="sm-prose" data-preview-of="f_body">' . $a['body'] . '</div></div>'
           . f_area('body', 'Text (HTML: <p>, <h2>, <ul>, <a href="…">…)', (string)$a['body'], ['rows' => 18, 'data' => 'data-preview="1"'])
           . '<button class="btn" type="submit">Save</button></form>';
    } else {
        echo '<p class="lede">' . e((string)$a['excerpt']) . '</p><div class="sm-prose">' . $a['body'] . '</div>';
    }
    echo '</div></div>';

    // Cover picture
    echo '<div class="card"><h2>Cover picture</h2>';
    if ($cover) {
        echo '<img class="sm-thumb-lg" src="' . e(SmMedia::url($cover)) . '" alt="">';
    } else {
        echo '<p class="muted">None chosen. A branded cover with the title is made automatically when the article is sent to the blog.</p>';
    }
    if ($editable) {
        $imgs = db()->all("SELECT id, label FROM sm_media WHERE agent_id = ? AND kind = 'image' ORDER BY id DESC LIMIT 60", [$aid]);
        $opts = ['' => 'Choose…'];
        foreach ($imgs as $m) {
            $opts[(string)$m['id']] = '#' . $m['id'] . ' ' . mb_strimwidth((string)$m['label'], 0, 60, '…');
        }
        echo '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="cover"><input type="hidden" name="id" value="' . $id . '">'
           . f_select('media_id', 'Use a picture from the library', $opts, '') . '<button class="btn btn-small" type="submit">Use it</button></form>'
           . '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="cover"><input type="hidden" name="id" value="' . $id . '">'
           . f_text('headline', 'Or build one with this headline', (string)(($src['image_headline'] ?? '') ?: $a['title']), ['maxlength' => 90])
           . f_select('photo_id', 'Photo behind it (optional)', $opts, '') . '<button class="btn btn-small" type="submit">Build cover</button></form>';
    }
    echo '</div>';
}

// Platform versions
if ($posts || $a['status'] === 'published') {
    echo '<div class="card"><h2>Social media versions</h2>';
    if (!$posts) {
        echo '<p class="muted">None yet. They are written automatically when the article is published; press "Write versions for missing platforms" to try again.</p>';
    } else {
        echo '<table class="table"><tbody>';
        foreach ($posts as $p) {
            echo '<tr><td style="width:110px"><strong>' . e(SmEvent::PLATFORMS[$p['platform']]) . '</strong></td><td><a href="' . e(sm_url('post.php', ['id' => $p['id']])) . '">' . e(mb_strimwidth((string)$p['body'], 0, 120, '…')) . '</a></td><td>' . sm_pill((string)$p['status'])
               . ($p['scheduled_at'] && in_array($p['status'], ['scheduled', 'due'], true) ? '<br><span class="small muted">' . e(sm_time($p['scheduled_at'])) . '</span>' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
sm_layout_end();
