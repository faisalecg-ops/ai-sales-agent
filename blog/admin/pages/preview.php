<?php
/** How an unpublished article will look on the blog. Only signed-in users can see it. */
require_perm('articles.own');
$a = Article::forPreview((int)($_GET['id'] ?? 0));
if (!$a || (!Auth::can('articles.all') && (int)$a['author_id'] !== Auth::id())) {
    flash('err', 'That article cannot be previewed.');
    back_to('articles');
}
if (!$a['published_at']) {
    $a['published_at'] = now_utc();
}
header('X-Robots-Tag: noindex, nofollow');
echo Pages::article((string)($a['cat'] ?? ''), (string)$a['slug'], $a);
