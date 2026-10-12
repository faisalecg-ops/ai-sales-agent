<?php
/** @var string $content @var array $seo */
$theme = Settings::theme();
$event = Event::name();
$mainSite = Event::get('main_site_url');
$logo = Media::find((int)Settings::get('logo_light', 0));
$logoDark = Media::find((int)Settings::get('logo_dark', 0)) ?: $logo;
$favicon = Media::find((int)Settings::get('favicon', 0));
$menu = array_filter((array)Settings::get('main_menu', []), static fn($m) => !empty($m['label']) && !empty($m['url']));
// Industry Voices gets its own menu item once it has an article (unless the menu already links to it).
if (Voices::enabled() && Settings::get('voices_menu', true) && !in_array(Voices::sectionUrl(), array_column($menu, 'url'), true)
    && Article::countPublished('a.contributor_id IS NOT NULL') > 0) {
    $menu[] = ['label' => Voices::name(), 'url' => Voices::sectionUrl()];
}
$headerStyle = (string)$theme['header_style'];
$headerLogo = $headerStyle === 'light' ? $logo : $logoDark;
$isHome = !empty($isHome);
$transparent = $headerStyle === 'transparent' && $isHome && !empty($slides);
$upcoming = Event::isUpcoming();
$dates = Event::dates();
$chat = Settings::get('chat_enabled', false) && trim((string)Settings::get('chat_agent', '')) !== '';
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= e(Lang::dir()) ?>" data-motion="<?= e(Theme::motion($theme)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?= Seo::head($seo) ?>
<?= Theme::preloadFonts($theme) ?>
<style><?= Theme::css($theme) ?></style>
<?php if (Theme::motion($theme) !== 'off'): ?>
<script>/* Scroll animations only where they can run properly — never for visitors who prefer reduced motion. Safety net: show everything if the page script has not started within 2.5 s. */
(function(){var r=document.documentElement;if((window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)||!('IntersectionObserver' in window))return;r.classList.add('anim');setTimeout(function(){if(!window.__blogMotion)r.classList.remove('anim');},2500);})();</script>
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/blog.css')) ?>">
<?php if ($favicon): ?><link rel="icon" href="<?= e(Media::url($favicon, 400)) ?>"><link rel="apple-touch-icon" href="<?= e(Media::url($favicon, 400)) ?>"><?php endif; ?>
<meta name="theme-color" content="<?= e($theme['primary']) ?>">
<?php if (!empty($seo['ld'])): ?><?= Seo::graph($seo['ld']) ?><?php endif; ?>
</head>
<body class="<?= e(Theme::bodyClasses($theme)) ?><?= $transparent ? ' hdr-over' : '' ?><?= $isHome ? ' is-home' : '' ?>">
<a class="skip" href="#main"><?= e(t('skip_to_content')) ?></a>
<?php if (!empty($isPreview)): ?><div class="preview-bar"><?= e(t('preview_banner')) ?></div><?php endif; ?>

<?php if ($dates !== '' && $upcoming): ?>
<div class="topbar">
  <div class="wrap topbar__in">
    <span class="topbar__event"><strong><?= e($event) ?></strong> <span class="topbar__sep">·</span> <?= e($dates) ?><?= Event::place() !== '' ? ' <span class="topbar__sep">·</span> ' . e(Event::place()) : '' ?></span>
    <span class="topbar__links">
      <?php if ($r = Event::get('register_url')): ?><a href="<?= e($r) ?>"><?= e(t('register_visit')) ?></a><?php endif; ?>
      <?php if ($s = Event::get('book_stand_url')): ?><a href="<?= e($s) ?>"><?= e(t('book_stand')) ?></a><?php endif; ?>
    </span>
  </div>
</div>
<?php endif; ?>

<header class="masthead" id="top">
  <div class="wrap masthead__in">
    <a class="brand" href="<?= e(url()) ?>" aria-label="<?= e(Seo::siteName()) ?> — <?= e(t('home')) ?>">
      <?php if ($headerLogo): ?>
        <img src="<?= e(Media::url($headerLogo, 400)) ?>" alt="<?= e(Seo::siteName()) ?>" width="<?= (int)$headerLogo['width'] ?>" height="<?= (int)$headerLogo['height'] ?>" class="brand__logo">
      <?php else: ?>
        <span class="brand__box"><?= e($event) ?></span>
      <?php endif; ?>
      <span class="brand__blog"><?= e(t('blog')) ?></span>
    </a>
    <nav class="nav" id="site-nav" aria-label="<?= e(t('menu')) ?>">
      <ul class="nav__list">
        <?php foreach ($menu as $m): ?>
          <li><a href="<?= e($m['url']) ?>"><?= e($m['label']) ?></a></li>
        <?php endforeach; ?>
        <?php if (Settings::get('header_button_enabled', true) && $mainSite): ?>
          <li class="nav__visit-m"><a href="<?= e($mainSite) ?>"><?= e(Event::fill((string)Settings::get('header_button_text', 'Visit {event} ↗'))) ?></a></li>
        <?php endif; ?>
      </ul>
    </nav>
    <div class="masthead__tools">
      <form class="search" action="<?= e(url('search')) ?>" method="get" role="search">
        <label class="sr" for="q"><?= e(t('search')) ?></label>
        <input id="q" name="q" type="search" placeholder="<?= e(t('search_placeholder')) ?>" autocomplete="off">
        <button type="submit" aria-label="<?= e(t('search')) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
      </form>
      <?php if (Settings::get('header_button_enabled', true) && $mainSite): ?>
        <a class="btn btn--cta visit" href="<?= e($mainSite) ?>"><?= e(Event::fill((string)Settings::get('header_button_text', 'Visit {event} ↗'))) ?></a>
      <?php endif; ?>
      <button class="burger" type="button" aria-controls="site-nav" aria-expanded="false"><span></span><span></span><span></span><span class="sr"><?= e(t('menu')) ?></span></button>
    </div>
  </div>
</header>
<div class="band band--masthead" aria-hidden="true"></div>

<main id="main" class="main">
<?= $content ?>
</main>

<?= Banner::render('footer') ? '<div class="wrap">' . Banner::render('footer') . '</div>' : '' ?>

<div class="band band--foot" aria-hidden="true"></div>
<footer class="footer">
  <div class="wrap footer__grid">
    <div class="footer__about reveal">
      <?php $fl = Media::find((int)Settings::get('logo_footer', 0)) ?: $logoDark; ?>
      <?php if ($fl): ?><img src="<?= e(Media::url($fl, 400)) ?>" alt="<?= e($event) ?>" class="footer__logo" loading="lazy" width="<?= (int)$fl['width'] ?>" height="<?= (int)$fl['height'] ?>"><?php else: ?><span class="brand__box brand__box--light"><?= e($event) ?></span><?php endif; ?>
      <?php if ($tl = Event::get('event_tagline') ?: (string)Settings::get('site_tagline', '')): ?><p><?= e($tl) ?></p><?php endif; ?>
      <?php if ($dates !== ''): ?><p class="footer__dates"><?= e($dates) ?><?= Event::place() !== '' ? ' · ' . e(Event::place()) : '' ?></p><?php endif; ?>
    </div>
    <?php $fm = array_filter((array)Settings::get('footer_menu', []), static fn($m) => !empty($m['label']) && !empty($m['url'])); ?>
    <?php if ($fm): ?>
    <nav class="footer__col reveal" aria-label="Footer">
      <ul><?php foreach ($fm as $m): ?><li><a href="<?= e($m['url']) ?>"><?= e($m['label']) ?></a></li><?php endforeach; ?></ul>
    </nav>
    <?php endif; ?>
    <div class="footer__col reveal">
      <h2 class="footer__h"><?= e(t('footer_contact')) ?></h2>
      <ul>
        <?php if ($v = Settings::get('contact_address', '')): ?><li><?= nl2br(e($v)) ?></li><?php endif; ?>
        <?php if ($v = Settings::get('contact_phone', '')): ?><li><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$v)) ?>"><?= e($v) ?></a></li><?php endif; ?>
        <?php if ($v = Settings::get('contact_email', '')): ?><li><a href="mailto:<?= e($v) ?>"><?= e($v) ?></a></li><?php endif; ?>
        <?php if ($mainSite): ?><li><a href="<?= e($mainSite) ?>"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($mainSite, '/'))) ?></a></li><?php endif; ?>
      </ul>
      <?= View::render('partials/social') ?>
    </div>
  </div>
  <div class="wrap footer__base">
    <span><?= e(t('footer_rights', ['year' => date('Y'), 'name' => $event])) ?></span>
    <?php if (Settings::get('footer_credit_enabled', true)): ?>
      <span class="footer__credit"><?= e(Event::fill((string)Settings::get('footer_credit_text', 'Blog by {event}'))) ?><?php if ($mainSite): ?> — <a href="<?= e($mainSite) ?>"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($mainSite, '/'))) ?></a><?php endif; ?></span>
    <?php endif; ?>
    <a href="<?= e(url('feed')) ?>"><?= e(t('rss')) ?></a>
  </div>
</footer>

<a href="#top" class="totop" aria-label="Back to top"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 14l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2"/></svg></a>
<script>window.BLOG={beacon:<?= json_encode(url('go/beacon'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,article:<?= (int)($articleId ?? 0) ?>,copied:<?= json_encode(t('link_copied')) ?>};</script>
<script src="<?= e(asset('js/blog.js')) ?>" defer></script>
<?php if ($chat && empty($isPreview)): ?>
<script>
/* The sales agent chat loads after the page has finished, so it never slows the page. */
window.addEventListener('load',function(){setTimeout(function(){var s=document.createElement('script');s.src=<?= json_encode(rtrim((string)Settings::get('chat_base_url', ''), '/') . '/widget/widget.js', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;s.async=true;s.setAttribute('data-agent',<?= json_encode((string)Settings::get('chat_agent', ''), JSON_HEX_TAG) ?>);document.body.appendChild(s);},800);});
</script>
<?php endif; ?>
</body>
</html>
