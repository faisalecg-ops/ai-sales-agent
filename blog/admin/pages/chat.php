<?php
/** The sales agent chat on every blog page, and where event facts come from. */
require_perm('settings');

if (is_post()) {
    post_guard();
    $base = rtrim(in_str('chat_base_url', 300), '/');
    $agent = preg_replace('/[^a-z0-9_-]/i', '', in_str('chat_agent', 64));
    if ($base !== '' && !is_web_url($base)) {
        flash('err', 'The sales agent address must start with https://');
        back_to('chat');
    }
    Settings::setMany(['chat_enabled' => (bool)in('chat_enabled'), 'chat_agent' => $agent, 'chat_base_url' => $base,
        'event_facts_from_agent' => (bool)in('event_facts_from_agent')]);
    Cache::clear();
    Activity::log('chat.updated', $agent);
    $msg = 'Saved.';
    if (in('do') === 'test' || (Settings::get('event_facts_from_agent', true) && $agent !== '')) {
        $res = Event::refresh();
        $msg .= $res['ok'] ? ' Connected: event facts received from the sales agent.' : ' Could not read event facts: ' . $res['error'] . ' (The chat itself may still work — check it on the blog.)';
        flash($res['ok'] ? 'ok' : 'warn', $msg);
    } else {
        flash('ok', $msg);
    }
    back_to('chat');
}

$host = (string)parse_url(base_url(), PHP_URL_HOST);
ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>Sales agent chat</h2>
    <?= f_check('chat_enabled', 'Show the sales agent chat on every blog page', (bool)Settings::get('chat_enabled', false), 'Home, articles, categories, search and the not-found page. It loads after the page, so it never slows the blog down.') ?>
    <div class="grid2">
      <?= f_text('chat_agent', 'Exhibition\'s assistant (agent name)', (string)Settings::get('chat_agent', ''), 'The same name as in the embed code, e.g. <code>buildasia</code>.', ['maxlength' => 64, 'pattern' => '[A-Za-z0-9_-]*']) ?>
      <?= f_text('chat_base_url', 'Sales agent address', (string)Settings::get('chat_base_url', ''), '', ['type' => 'url']) ?>
    </div>
    <?= f_check('event_facts_from_agent', 'Take event facts (dates, venue, links) from the sales agent', (bool)Settings::get('event_facts_from_agent', true)) ?>
    <div class="row-btns"><button class="btn btn--primary">Save</button> <button class="btn btn--ghost" name="do" value="test">Save and test connection</button></div>
  </section>
</form>
<section class="card">
  <h2>Checklist</h2>
  <ul class="plain">
    <li>The blog's website <strong><?= e($host) ?></strong> must be in the exhibition's <em>allowed domains</em> in the sales agent admin. It usually is already, because the blog lives on the event's own website.</li>
    <li>The chat button sits at the bottom right. The blog keeps that corner free: its own “back to top” button is on the left, and banners never float over it.</li>
  </ul>
</section>
<?php admin_render('Sales agent chat', (string)ob_get_clean(), 'chat');
