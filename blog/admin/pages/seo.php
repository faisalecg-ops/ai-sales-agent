<?php
/** SEO settings and how to connect the blog to Google. */
require_perm('settings');

if (is_post()) {
    post_guard();
    $verify = in_str('gsc_verification', 200);
    // People often paste the whole tag; keep only the code.
    if (preg_match('/content="([^"]+)"/', $verify, $m)) {
        $verify = $m[1];
    }
    Settings::setMany([
        'seo_home_title' => in_str('seo_home_title', 120),
        'seo_home_description' => in_str('seo_home_description', 320),
        'organization_name' => in_str('organization_name', 160),
        'gsc_verification' => $verify,
        'hide_from_search' => (bool)in('hide_from_search'),
    ]);
    Cache::clear();
    Activity::log('seo.updated');
    flash('ok', 'SEO settings saved.');
    back_to('seo');
}

$host = parse_url(base_url(), PHP_URL_SCHEME) . '://' . parse_url(base_url(), PHP_URL_HOST);
ob_start(); ?>
<?php if (Settings::get('hide_from_search', false)): ?><div class="flash flash--warn"><strong>The blog is hidden from Google.</strong> Right for a review copy; switch it off when the blog goes live.</div><?php endif; ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>Home page in Google</h2>
    <?= f_text('seo_home_title', 'Title', (string)Settings::get('seo_home_title', ''), 'Leave empty to use the blog name. e.g. "Construction industry news and insights | Build Asia".', ['maxlength' => 120, 'data-count' => 60]) ?>
    <?= f_area('seo_home_description', 'Description', (string)Settings::get('seo_home_description', ''), '120–155 characters about what readers find here.', 2, ['maxlength' => 320, 'data-count' => 155]) ?>
    <?= f_text('organization_name', 'Organisation name', (string)Settings::get('organization_name', ''), 'Tells Google who publishes the blog. Usually the exhibition name.', ['maxlength' => 160]) ?>
  </section>
  <section class="card">
    <h2>Google Search Console</h2>
    <?= f_text('gsc_verification', 'Verification code (HTML tag method)', (string)Settings::get('gsc_verification', ''), 'Paste the code (or the whole meta tag) Google gives you.', ['maxlength' => 200]) ?>
    <?= f_check('hide_from_search', 'Hide the blog from Google (for a review copy only)', (bool)Settings::get('hide_from_search', false)) ?>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save</button></div>
</form>

<section class="card">
  <h2>Help Google find every article</h2>
  <p>Your blog's sitemap (always up to date): <a href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= e(url('sitemap.xml')) ?></a><br>RSS feed: <a href="<?= e(url('feed')) ?>" target="_blank" rel="noopener"><?= e(url('feed')) ?></a></p>
  <ol>
    <li><strong>Add one line to the main website's robots.txt</strong> (the file at <?= e($host) ?>/robots.txt — create it if it does not exist):
      <pre class="code">Sitemap: <?= e(url('sitemap.xml')) ?></pre></li>
    <li><strong>In Google Search Console</strong>, open the website's property → Sitemaps → add <code><?= e(ltrim(parse_url(url('sitemap.xml'), PHP_URL_PATH) ?? '', '/')) ?></code>.</li>
  </ol>
  <p class="help">Built in on every page: clean addresses, titles and descriptions, share previews for Facebook, LinkedIn, WhatsApp and X, structured data for Google (article, breadcrumbs, organisation, event), canonical addresses and automatic forwarding when an address changes.</p>
</section>
<?php admin_render('SEO & Google', (string)ob_get_clean(), 'seo');
