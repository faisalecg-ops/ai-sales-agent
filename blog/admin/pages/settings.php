<?php
/** General settings and what readers can do: comments, newsletter, reading time, sharing. */
require_perm('settings');

if (is_post()) {
    post_guard();
    if (in('do') === 'clear_cache') {
        Cache::clear();
        flash('ok', 'Saved pages cleared. Every page will be rebuilt on its next visit.');
        back_to('settings');
    }
    $url = rtrim(in_str('site_url', 300), '/');
    if (!is_web_url($url)) {
        flash('err', 'The blog address must be a full address such as https://buildasia.net/blog');
        back_to('settings');
    }
    $oldUrl = base_url();
    $tz = in_str('timezone', 64);
    try {
        new DateTimeZone($tz);
    } catch (Throwable) {
        $tz = 'Asia/Karachi';
    }
    Settings::setMany([
        'site_url' => $url,
        'timezone' => $tz,
        'language' => array_key_exists((string)in('language'), Lang::available()) ? (string)in('language') : 'en',
        'posts_per_page' => max(6, min(48, (int)in('posts_per_page', 12))),
        'comments_mode' => in('comments_mode') === 'approval' ? 'approval' : 'off',
        'newsletter_enabled' => (bool)in('newsletter_enabled'),
        'newsletter_title' => in_str('newsletter_title', 120),
        'newsletter_text' => in_str('newsletter_text', 300),
        'newsletter_consent' => in_str('newsletter_consent', 300) ?: Settings::defaults()['newsletter_consent'],
        'show_reading_time' => (bool)in('show_reading_time'),
        'show_share_buttons' => (bool)in('show_share_buttons'),
    ]);
    Cache::clear();
    Activity::log('settings.updated');
    if ($url !== $oldUrl) {
        flash('warn', 'The blog address changed. If this is a move to a new website (for example from the review copy to buildasia.net/blog), also update the address in the sales agent\'s allowed domains and in Google Search Console.');
    }
    flash('ok', 'Settings saved.');
    back_to('settings');
}

$lastCron = (string)Settings::get('last_cron', '');
$cronOk = $lastCron !== '' && strtotime($lastCron . ' UTC') > time() - 3600;
$cronCmd = '/usr/local/bin/php ' . BLOG_ROOT . '/cron.php';
$zones = ['Asia/Karachi' => 'Pakistan (Karachi)', 'Asia/Dubai' => 'Gulf (Dubai)', 'Asia/Shanghai' => 'China (Shanghai)', 'Asia/Kolkata' => 'India', 'Europe/London' => 'London', 'UTC' => 'UTC'];
$tzNow = (string)Settings::get('timezone', 'Asia/Karachi');
if (!isset($zones[$tzNow])) {
    $zones[$tzNow] = $tzNow;
}

ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>General</h2>
    <div class="grid2">
      <?= f_text('site_url', 'Blog address', base_url(), 'Change this when the blog moves, e.g. from the review copy to the live site.', ['type' => 'url', 'required' => true]) ?>
      <?= f_select('timezone', 'Time zone', $zones, $tzNow, 'Used for publishing times and dates shown to readers.') ?>
      <?= f_select('language', 'Language of the blog', Lang::available(), (string)Settings::get('language', 'en'), 'English for now. More languages can be added later as language files.') ?>
      <?= f_text('posts_per_page', 'Articles per page in lists', (string)Settings::get('posts_per_page', 12), '6–48', ['type' => 'number', 'min' => 6, 'max' => 48]) ?>
    </div>
    <?= f_check('show_reading_time', 'Show reading time (“4 min read”)', (bool)Settings::get('show_reading_time', true)) ?>
    <?= f_check('show_share_buttons', 'Show share buttons under articles', (bool)Settings::get('show_share_buttons', true)) ?>
  </section>

  <section class="card">
    <h2>Comments</h2>
    <?= f_select('comments_mode', 'Comments on articles', ['off' => 'Off', 'approval' => 'On — each comment appears only after approval'], (string)Settings::get('comments_mode', 'off')) ?>
    <p class="help">Spam robots are blocked automatically. Approve comments in <a href="<?= e(aurl('comments')) ?>">Comments</a>.</p>
  </section>

  <section class="card">
    <h2>Newsletter sign-up</h2>
    <?= f_check('newsletter_enabled', 'Show the newsletter sign-up box', (bool)Settings::get('newsletter_enabled', false), 'Readers tick a consent box, then confirm by email before they are added. Every email can unsubscribe in one click.') ?>
    <?= f_text('newsletter_title', 'Heading', (string)Settings::get('newsletter_title', ''), '', ['maxlength' => 120]) ?>
    <?= f_text('newsletter_text', 'Short text', (string)Settings::get('newsletter_text', ''), '', ['maxlength' => 300]) ?>
    <?= f_text('newsletter_consent', 'Consent sentence (next to the tick box)', (string)Settings::get('newsletter_consent', ''), 'The exact words are stored with each sign-up as the record of consent.', ['maxlength' => 300]) ?>
    <p class="help">Confirmation emails are sent from the address set in <a href="<?= e(aurl('email')) ?>">Email</a>. Subscribers are listed in <a href="<?= e(aurl('subscribers')) ?>">Subscribers</a>.</p>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save settings</button></div>
</form>

<section class="card" id="cron">
  <h2>Scheduled tasks (cron job)</h2>
  <p><?= $cronOk ? '✓ Running. Last run ' . e(local_date($lastCron, 'j M, H:i')) . '.' : '<strong>Not running yet.</strong> Without it, scheduled articles still go out (when someone visits the blog), but the link check, event facts and notices to the social agent wait.' ?></p>
  <p class="help">In cPanel → <strong>Cron Jobs</strong>, add a job that runs <strong>every 5 minutes</strong> with this command:</p>
  <pre class="code"><?= e($cronCmd) ?></pre>
  <p class="help">If your hosting uses a different PHP path, cPanel shows it on the Cron Jobs page.</p>
</section>

<section class="card">
  <h2>Speed</h2>
  <p class="help">Pages are saved after their first visit so later visitors get them instantly. Saved pages are cleared automatically whenever you change anything here.</p>
  <form method="post"><?= Auth::csrfField() ?><input type="hidden" name="do" value="clear_cache"><button class="btn btn--small btn--ghost">Clear saved pages now</button></form>
</section>
<?php admin_render('Readers & general', (string)ob_get_clean(), 'settings');
