<?php
/** Write or edit one article; send it for approval, publish or schedule it. */
require_perm('articles.own');

$id = (int)($_GET['id'] ?? 0);
$a = $id ? Article::find($id) : null;
if ($id && !$a) {
    flash('err', 'That article no longer exists.');
    back_to('articles');
}
if ($a && !Auth::canEditArticle($a) && !Auth::can('articles.publish')) {
    flash('err', 'You can only edit your own drafts and articles awaiting approval.');
    back_to('articles');
}
$canPublish = Auth::can('articles.publish');

// --- Link check and suggestions (asked for by the editor while writing) ---
if (isset($_GET['ajax']) && is_post()) {
    post_guard();
    $html = Html::clean((string)in('body'));
    $sources = $a ? (json_decode((string)$a['sources'], true) ?: []) : [];
    $sponsorUrl = Auth::can('sponsors') && in_str('sponsor_name', 160) !== '' ? in_str('sponsor_url', 500) : (string)($a['sponsor_url'] ?? '');
    $review = LinkPolicy::review($html, $sources, is_web_url($sponsorUrl) ? $sponsorUrl : '');
    json_out(['ok' => true, 'review' => $review, 'suggest' => LinkPolicy::suggest($html, $id ?: null, (int)in('category_id') ?: null)]);
}

if (is_post()) {
    post_guard();
    $do = (string)in('do', 'save');

    // Restore an earlier version of the text.
    if ($do === 'restore' && $a) {
        $rev = db()->one('SELECT * FROM {p}article_revisions WHERE id = ? AND article_id = ?', [(int)in('revision'), $id]);
        if ($rev && Auth::canEditArticle($a)) {
            Article::save($id, ['title' => $rev['title'], 'excerpt' => $rev['excerpt'], 'body' => $rev['body']], null, 'Restored the version of ' . local_date($rev['created_at'], 'j M Y H:i'));
            flash('ok', 'The earlier version has been restored. Check it, then save or publish.');
        }
        back_to('article', ['id' => $id]);
    }
    if ($do === 'delete' && $a) {
        if (Auth::role() !== 'admin' || $a['status'] === 'published') {
            flash('err', 'Only an admin can delete, and only articles that are not published. Unpublish or archive it first.');
            back_to('article', ['id' => $id]);
        }
        db()->delete('article_tags', 'article_id = ?', [$id]);
        db()->delete('article_revisions', 'article_id = ?', [$id]);
        db()->delete('articles', 'id = ?', [$id]);
        Activity::log('article.deleted', (string)$a['title']);
        Cache::clear();
        flash('ok', 'The article has been deleted.');
        back_to('articles');
    }
    // State changes that do not need the form saved first.
    if (in_array($do, ['unpublish', 'archive', 'send_back'], true) && $a) {
        if (!$canPublish) {
            flash('err', 'Only approvers and admins can do that.');
            back_to('article', ['id' => $id]);
        }
        $res = Article::transition($id, $do === 'archive' ? 'archived' : 'draft', null, $do === 'send_back' ? in_str('review_note', 2000) : '');
        $msg = ['unpublish' => 'The article is unpublished and back to draft.', 'archive' => 'The article is archived.', 'send_back' => 'Sent back to the writer as a draft, with your note.'][$do];
        $res['ok'] ? flash('ok', $msg) : flash('err', $res['error']);
        back_to('article', ['id' => $id]);
    }

    if ($a && !Auth::canEditArticle($a)) {
        flash('err', 'You cannot change this article.');
        back_to('article', ['id' => $id]);
    }
    $title = in_str('title', 255);
    if ($title === '') {
        flash('err', 'Give the article a title before saving.');
        $_SESSION['article_draft'] = $_POST;
        back_to('article', $id ? ['id' => $id] : []);
    }
    $data = [
        'title' => $title,
        'slug' => in_str('slug', 190),
        'excerpt' => in_str('excerpt', 600),
        'body' => (string)in('body'),
        'category_id' => (int)in('category_id'),
        'image_id' => (int)in('image_id'),
        'share_image_id' => (int)in('share_image_id'),
        'seo_title' => in_str('seo_title', 190),
        'meta_description' => in_str('meta_description', 320),
    ];
    if (!$a) {
        $data['author_id'] = (int)Auth::id();
        // An author account linked to an Industry Voices profile writes as that contributor.
        if ($mine = Voices::forUser((int)Auth::id())) {
            $data['contributor_id'] = (int)$mine['id'];
        }
    }
    if (Auth::can('articles.all') && isset($_POST['contributor_id'])) {
        $data['contributor_id'] = (int)in('contributor_id');
    }
    if (Auth::can('sponsors') && isset($_POST['sponsor_name'])) {
        $sStart = local_to_utc(in_str('sponsor_starts', 30));
        $sEnd = local_to_utc(in_str('sponsor_ends', 30));
        $data += [
            'sponsor_name' => in_str('sponsor_name', 160), 'sponsor_label' => (string)in('sponsor_label'), 'sponsor_url' => in_str('sponsor_url', 500),
            'sponsor_logo_id' => (int)in('sponsor_logo_id'), 'sponsor_starts' => $sStart, 'sponsor_ends' => $sEnd,
            'sponsor_after' => (string)in('sponsor_after'), 'sponsor_email' => in_str('sponsor_email', 190),
        ];
    }
    if (Auth::can('articles.all') && in('author_id', '') !== '') {
        $data['author_id'] = (int)in('author_id');
    }
    if (Auth::can('content')) {
        $data['is_featured'] = (int)(bool)in('is_featured');
        $data['featured_order'] = (int)in('featured_order');
    }
    $tags = array_filter(array_map('trim', explode(',', in_str('tags', 1000))));
    $note = ['save' => 'Saved', 'submit' => 'Sent for approval', 'publish' => 'Published', 'schedule' => 'Scheduled'][$do] ?? 'Saved';
    try {
        $id = Article::save($a ? $id : null, $data, $tags, $note);
    } catch (Throwable $ex) {
        blog_log('article', 'save failed', ['error' => $ex->getMessage()]);
        flash('err', 'The article could not be saved. Please try again.');
        back_to('articles');
    }
    if (!$a) {
        Activity::log('article.created', $title, ['id' => $id]);
    }
    $msg = 'Saved.';
    if ($do === 'submit') {
        $res = Article::transition($id, 'pending');
        $msg = $res['ok'] ? 'Saved and sent for approval.' : $res['error'];
    } elseif ($do === 'publish' && $canPublish) {
        $res = Article::transition($id, 'published');
        $msg = !$res['ok'] ? 'Saved, but not published: ' . $res['error']
            : (($res['status'] ?? '') === 'scheduled' ? 'Approved. It goes live when the sponsorship starts, ' . local_date((string)Article::find($id)['publish_at'], 'j F Y, H:i') . '.' : 'Published. It is now live on the blog.');
    } elseif ($do === 'schedule' && $canPublish) {
        $res = Article::transition($id, 'scheduled', local_to_utc(in_str('publish_at', 30)));
        $msg = $res['ok'] ? 'Scheduled for ' . local_date((string)Article::find($id)['publish_at'], 'j F Y, H:i') . '.' : 'Saved, but not scheduled: ' . $res['error'];
    }
    flash(isset($res) && !$res['ok'] ? 'err' : 'ok', $msg);
    back_to('article', ['id' => $id]);
}

// --- The form ---
$draft = $_SESSION['article_draft'] ?? null;
unset($_SESSION['article_draft']);
$v = $a ?? ['id' => 0, 'title' => '', 'slug' => '', 'excerpt' => '', 'body' => '', 'category_id' => 0, 'image_id' => 0, 'share_image_id' => 0,
    'seo_title' => '', 'meta_description' => '', 'status' => 'draft', 'is_featured' => 0, 'featured_order' => 0, 'author_id' => Auth::id(),
    'publish_at' => null, 'published_at' => null, 'source' => 'admin', 'review_note' => '', 'sources' => null, 'updated_at' => null,
    'contributor_id' => (Voices::forUser((int)Auth::id())['id'] ?? 0), 'sponsor_name' => '', 'sponsor_label' => 'sponsored', 'sponsor_url' => '', 'sponsor_logo_id' => 0,
    'sponsor_starts' => null, 'sponsor_ends' => null, 'sponsor_after' => 'keep', 'sponsor_email' => ''];
if (is_array($draft)) {
    foreach (['title', 'excerpt', 'body', 'seo_title', 'meta_description'] as $k) {
        $v[$k] = (string)($draft[$k] ?? $v[$k]);
    }
}
$cats = db()->all('SELECT id, name, slug FROM {p}categories ORDER BY sort, name');
$tagStr = $a ? implode(', ', array_column(Article::tags($id), 'name')) : '';
$authors = Auth::can('articles.all') ? db()->all("SELECT id, name FROM {p}users WHERE status = 'active' ORDER BY name") : [];
$revisions = $a ? db()->all('SELECT r.id, r.note, r.created_at, r.status, u.name FROM {p}article_revisions r LEFT JOIN {p}users u ON u.id = r.user_id WHERE r.article_id = ? ORDER BY r.id DESC LIMIT 20', [$id]) : [];
$sources = $a ? (json_decode((string)$a['sources'], true) ?: []) : [];
$catSlug = '';
foreach ($cats as $c) {
    if ((int)$c['id'] === (int)$v['category_id']) {
        $catSlug = $c['slug'];
    }
}
$liveUrl = $a ? Article::urlFor($a) : '';
$contributors = Auth::can('articles.all') && Voices::enabled() ? Voices::all() : [];
$myVoice = Voices::find((int)$v['contributor_id']);
$editable = !$a || Auth::canEditArticle($a);

ob_start(); ?>
<?php if ($a && $a['source'] === 'api'): ?>
  <div class="flash flash--info">Written by the <strong>Social Media Agent</strong>. Check the facts, the tone and every link below before approving.<?php if ($sources): ?> The agent says it used these sources: <?php foreach ($sources as $s): ?><a href="<?= e($s) ?>" target="_blank" rel="noopener"><?= e(parse_url((string)$s, PHP_URL_HOST) ?: $s) ?></a> <?php endforeach; ?><?php endif; ?></div>
<?php endif; ?>
<?php if (Sponsored::is($v)): ?>
  <div class="flash flash--info"><strong><?= e(Sponsored::label($v)) ?> for <?= e($v['sponsor_name']) ?>.</strong> Readers see the label, the sponsor and this line: <em><?= e(Sponsored::disclosure($v)) ?></em> Every outside link is marked as sponsored and its clicks are counted for the sponsor's report.</div>
<?php elseif ($myVoice): ?>
  <div class="flash flash--info"><strong><?= e(Voices::name()) ?>:</strong> written by <?= e($myVoice['name']) ?><?= Voices::role($myVoice) !== '' ? ', ' . e(Voices::role($myVoice)) : '' ?>. Readers see the “<?= e(Voices::badge()) ?>” badge, the author's profile and this line: <em><?= e(Voices::disclaimer()) ?></em></div>
<?php endif; ?>
<?php if ($a && trim((string)$a['review_note']) !== '' && in_array($a['status'], ['draft', 'pending'], true)): ?>
  <div class="flash flash--warn"><strong><?= $a['status'] === 'draft' ? 'Note from the approver:' : 'Note:' ?></strong> <?= nl2br(e($a['review_note'])) ?></div>
<?php endif; ?>

<form method="post" class="editor-page" id="articleForm" data-article="<?= (int)$v['id'] ?>" data-ajax="<?= e(aurl('article', ['id' => $v['id'], 'ajax' => 1])) ?>">
  <?= Auth::csrfField() ?>
  <div class="editor-main">
    <div class="field field--title"><label for="f_title" class="sr">Title</label>
      <input id="f_title" name="title" value="<?= e($v['title']) ?>" placeholder="Article title" maxlength="255" required <?= $editable ? '' : 'readonly' ?>></div>
    <div class="field field--standfirst"><label for="f_excerpt">Standfirst <span class="help-inline">— one or two sentences under the title; also used on cards and in Google if no description is set</span></label>
      <textarea id="f_excerpt" name="excerpt" rows="2" maxlength="600"><?= e($v['excerpt']) ?></textarea></div>

    <div class="wp" data-editor>
      <div class="wp__bar" role="toolbar" aria-label="Formatting">
        <select data-block aria-label="Text style"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Sub-heading</option><option value="h4">Small heading</option></select>
        <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
        <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
        <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
        <span class="wp__sep"></span>
        <button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
        <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <button type="button" data-cmd="quote" title="Quote">❝ Quote</button>
        <span class="wp__sep"></span>
        <button type="button" data-cmd="link" title="Add a link">🔗 Link</button>
        <button type="button" data-cmd="unlink" title="Remove link">Unlink</button>
        <button type="button" data-cmd="image" title="Insert an image from the media library">🖼 Image</button>
        <button type="button" data-cmd="video" title="Insert a YouTube or Vimeo video">▶ Video</button>
        <button type="button" data-cmd="table" title="Insert a table">▦ Table</button>
        <button type="button" data-cmd="hr" title="Divider line">―</button>
        <span class="wp__sep"></span>
        <button type="button" data-cmd="undo" title="Undo">↶</button>
        <button type="button" data-cmd="redo" title="Redo">↷</button>
        <button type="button" data-cmd="source" title="Edit the HTML code">&lt;/&gt;</button>
      </div>
      <div class="wp__area prose-admin" contenteditable="<?= $editable ? 'true' : 'false' ?>" data-area spellcheck="true"><?= $v['body'] ?></div>
      <textarea class="wp__source" data-source hidden aria-label="HTML code"></textarea>
      <textarea name="body" data-body hidden><?= e($v['body']) ?></textarea>
      <div class="wp__foot"><span data-wordcount>0 words</span></div>
    </div>

    <section class="card" data-linkpanel>
      <div class="card__head"><h2>Links in this article</h2><button type="button" class="btn btn--small btn--ghost" data-checklinks>Check links</button></div>
      <p class="help">Usually 3–6 links. Allowed: links from the <a href="<?= e(aurl('links')) ?>">link library</a>, other articles of this blog, and sources actually used. Outside links open in a new tab; sponsor links are marked for Google automatically; tracking tags (UTM) are removed.</p>
      <div data-linklist><p class="muted small">Press “Check links” to see every link and any warnings.</p></div>
      <div data-suggest></div>
    </section>

    <section class="card">
      <h2>Google and social media</h2>
      <div class="gpreview" data-gpreview>
        <span class="gpreview__url" data-gurl><?= e($liveUrl ?: url(($catSlug ?: 'category') . '/' . ($v['slug'] ?: 'article-address'))) ?></span>
        <span class="gpreview__title" data-gtitle></span>
        <span class="gpreview__desc" data-gdesc></span>
      </div>
      <?= f_text('seo_title', 'SEO title', $v['seo_title'], 'Leave empty to use the article title. Best under 60 characters.', ['maxlength' => 190, 'data-count' => 60]) ?>
      <?= f_area('meta_description', 'Description for Google', $v['meta_description'], 'Leave empty to use the standfirst. Best 120–155 characters.', 2, ['maxlength' => 320, 'data-count' => 155]) ?>
      <?= f_text('slug', 'Web address (slug)', $v['slug'], 'Made from the title automatically. If you change it on a published article, the old address keeps working and forwards readers.', ['maxlength' => 190, 'pattern' => '[a-z0-9-]*']) ?>
      <?= f_media('share_image_id', 'Image when shared on social media', (int)$v['share_image_id'], 'Optional. Leave empty to use the featured image. Best 1200 × 630 px.') ?>
    </section>

    <?php if ($revisions): ?>
    <section class="card">
      <h2>Version history</h2>
      <table class="table"><tbody>
      <?php foreach ($revisions as $i => $r): ?>
        <tr><td class="small"><?= e(local_date($r['created_at'], 'j M Y, H:i')) ?></td><td class="small"><?= e($r['note']) ?><?= $r['name'] ? ' · ' . e($r['name']) : '' ?></td>
        <td class="right"><?php if ($i > 0 && $editable): ?><button class="btn btn--small btn--ghost" form="restoreForm" name="revision" value="<?= (int)$r['id'] ?>" onclick="return confirm('Replace the current text with this earlier version? (The current text stays in the history.)')">Restore</button><?php elseif ($i === 0): ?><span class="muted small">Current</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>
    <?php endif; ?>
  </div>

  <aside class="editor-side">
    <section class="card card--sticky">
      <p class="status-line">Status: <?= badge((string)$v['status']) ?></p>
      <?php if ($v['status'] === 'scheduled'): ?><p class="small">Goes live <?= e(local_date($v['publish_at'], 'j M Y, H:i')) ?></p><?php endif; ?>
      <?php if ($v['published_at']): ?><p class="small muted">First published <?= e(local_date($v['published_at'], 'j M Y, H:i')) ?></p><?php endif; ?>
      <div class="actions">
        <?php if ($editable): ?>
          <button class="btn btn--block" name="do" value="save"><?= $v['status'] === 'published' ? 'Save changes (live)' : 'Save draft' ?></button>
          <?php if (!in_array($v['status'], ['published', 'scheduled'], true) && !$canPublish): ?>
            <button class="btn btn--primary btn--block" name="do" value="submit">Send for approval</button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($canPublish): ?>
          <?php if ($v['status'] !== 'published'): ?>
            <button class="btn btn--primary btn--block" name="do" value="publish" data-confirm-links>Approve &amp; publish now</button>
            <details class="schedule"><summary>Schedule for later</summary>
              <label class="small" for="f_publish_at">Publish on (Pakistan time)</label>
              <input id="f_publish_at" type="datetime-local" name="publish_at" value="<?= e(utc_to_input($v['publish_at'])) ?>">
              <button class="btn btn--block" name="do" value="schedule">Schedule</button>
            </details>
          <?php endif; ?>
          <?php if ($a && $v['status'] === 'pending'): ?>
            <details class="schedule"><summary>Send back to the writer</summary>
              <textarea name="review_note" rows="3" placeholder="What should be changed?"></textarea>
              <button class="btn btn--block btn--ghost" name="do" value="send_back">Send back as draft</button>
            </details>
          <?php endif; ?>
          <?php if ($a && in_array($v['status'], ['published', 'scheduled'], true)): ?>
            <button class="btn btn--block btn--ghost" name="do" value="unpublish" onclick="return confirm('Take this article off the blog and make it a draft?')">Unpublish</button>
          <?php endif; ?>
          <?php if ($a && $v['status'] !== 'archived'): ?>
            <button class="btn btn--block btn--ghost btn--quiet" name="do" value="archive" onclick="return confirm('Archive this article? It will be removed from the blog.')">Archive</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if ($a): ?>
        <p class="links-row"><a href="<?= e(aurl('preview', ['id' => $id])) ?>" target="_blank" rel="noopener">Preview ↗</a><?php if ($v['status'] === 'published'): ?> · <a href="<?= e($liveUrl) ?>" target="_blank" rel="noopener">View live ↗</a><?php endif; ?></p>
      <?php endif; ?>
    </section>

    <section class="card">
      <?= f_select('category_id', 'Category', ['' => '— choose —'] + array_column($cats, 'name', 'id'), (string)$v['category_id'], '', ['required' => true]) ?>
      <?= f_text('tags', 'Tags', $tagStr, 'Separate with commas, e.g. Cement, Market data', ['data-tags' => true]) ?>
      <?php if ($authors): ?><?= f_select('author_id', 'Author', array_column($authors, 'name', 'id'), (string)$v['author_id']) ?><?php endif; ?>
      <?php if ($contributors): ?>
        <?= f_select('contributor_id', Voices::name(), ['' => '— not an ' . Voices::name() . ' article —'] + array_map(static fn($c) => $c['name'] . ($c['company'] !== '' ? ' (' . $c['company'] . ')' : ''), array_column($contributors, null, 'id')), (string)($v['contributor_id'] ?: ''), 'Written by an outside expert? Choose them. Add people in <a href="' . e(aurl('contributors')) . '">Contributors</a>.') ?>
      <?php elseif ($myVoice): ?>
        <p class="small muted"><?= e(Voices::name()) ?>: <?= e($myVoice['name']) ?></p>
      <?php endif; ?>
    </section>

    <?php if (Auth::can('sponsors')): ?>
    <section class="card">
      <details<?= Sponsored::is($v) ? ' open' : '' ?>><summary><strong>Sponsored article</strong> <span class="small muted">— paid content</span></summary>
        <?= f_text('sponsor_name', 'Sponsor', (string)$v['sponsor_name'], 'Leave empty for an ordinary article. With a name, the article is marked as paid content.', ['maxlength' => 160]) ?>
        <?= f_select('sponsor_label', 'Label', ['sponsored' => (string)Settings::get('sponsored_text', 'Sponsored'), 'partner' => (string)Settings::get('partner_text', 'Partner content')], (string)$v['sponsor_label']) ?>
        <?= f_text('sponsor_url', 'Sponsor\'s website', (string)$v['sponsor_url'], 'Optional. Their name or logo links here.', ['type' => 'url', 'maxlength' => 500, 'placeholder' => 'https://']) ?>
        <?= f_media('sponsor_logo_id', 'Sponsor\'s logo', (int)$v['sponsor_logo_id'], 'Optional. Shown next to the label.') ?>
        <?= f_text('sponsor_starts', 'Paid period starts (Pakistan time)', utc_to_input($v['sponsor_starts']), 'Approving before this date schedules it to go live then.', ['type' => 'datetime-local']) ?>
        <?= f_text('sponsor_ends', 'Paid period ends', utc_to_input($v['sponsor_ends']), 'Until then it is shown in “' . e((string)Settings::get('partner_section', 'From our partners')) . '” on the home page.', ['type' => 'datetime-local']) ?>
        <?= f_select('sponsor_after', 'When the paid period ends', ['keep' => 'Keep it on the blog (still labelled)', 'archive' => 'Take it off the blog (archive)'], (string)$v['sponsor_after']) ?>
        <?= f_text('sponsor_email', 'Sponsor\'s contact email (private)', (string)$v['sponsor_email'], 'For your records; the report is sent by you.', ['type' => 'email', 'maxlength' => 190]) ?>
        <?php if ($a && Sponsored::is($v)): ?><p><a class="btn btn--small btn--ghost" href="<?= e(aurl('sponsored', ['report' => $id])) ?>" target="_blank" rel="noopener">Sponsor report ↗</a></p><?php endif; ?>
      </details>
    </section>
    <?php endif; ?>

    <section class="card">
      <?= f_media('image_id', 'Featured image', (int)$v['image_id'], 'Shown at the top of the article and on cards. Best at least 1600 px wide.') ?>
    </section>

    <?php if (Auth::can('content')): ?>
    <section class="card">
      <?= f_check('is_featured', 'Show in “Featured” on the home page', (bool)$v['is_featured']) ?>
      <?= f_text('featured_order', 'Featured order', (string)$v['featured_order'], 'Lower numbers come first; the first is the large story.', ['type' => 'number', 'min' => 0, 'max' => 99]) ?>
    </section>
    <?php endif; ?>

    <?php if ($a && Auth::role() === 'admin' && $v['status'] !== 'published'): ?>
      <p><button class="btn btn--small btn--danger" form="deleteForm" onclick="return confirm('Delete this article permanently? This cannot be undone.')">Delete article</button></p>
    <?php endif; ?>
  </aside>
</form>
<?php if ($a): ?>
<form method="post" id="restoreForm" hidden><?= Auth::csrfField() ?><input type="hidden" name="do" value="restore"></form>
<form method="post" id="deleteForm" hidden><?= Auth::csrfField() ?><input type="hidden" name="do" value="delete"></form>
<?php endif; ?>
<?php
$title = $a ? 'Edit article' : 'New article';
admin_render($title, (string)ob_get_clean(), 'articles', ['wide' => true,
    'scripts' => '<script>window.LINKLIB=' . json_encode(array_values(array_map(static fn($l) => ['label' => $l['label'], 'url' => $l['url'], 'kind' => $l['kind']], LinkPolicy::library())), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';window.SITEHOST=' . json_encode(parse_url(base_url(), PHP_URL_HOST)) . ';</script>']);
