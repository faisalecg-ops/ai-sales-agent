<?php
/** The exhibition's facts, the links back to the main website, contact details and social pages. */
require_perm('settings');

$fields = [
    'event_name'     => ['Exhibition name', 'e.g. Build Asia', 'text'],
    'event_tagline'  => ['One-line description', 'e.g. Pakistan\'s largest construction and building materials exhibition', 'text'],
    'event_start'    => ['First show day', '', 'date'],
    'event_end'      => ['Last show day', '', 'date'],
    'event_venue'    => ['Venue', 'e.g. Expo Centre', 'text'],
    'event_city'     => ['City', 'e.g. Karachi', 'text'],
    'event_country'  => ['Country', '', 'text'],
    'main_site_url'  => ['Main event website', 'e.g. https://buildasia.net', 'url'],
    'register_url'   => ['"Register to visit" page', 'https://…', 'url'],
    'book_stand_url' => ['"Book a stand" page', 'https://…', 'url'],
];

if (is_post()) {
    post_guard();
    if (in('do') === 'fetch') {
        $res = Event::refresh();
        $res['ok'] ? flash('ok', 'Event facts fetched from the sales agent.') : flash('err', $res['error']);
        back_to('event');
    }
    $errors = [];
    foreach ($fields as $k => [$label, , $type]) {
        $val = in_str($k, 300);
        if ($type === 'url' && $val !== '' && !is_web_url($val)) {
            $errors[] = $label . ' must be a full address starting with https://';
            continue;
        }
        if ($type === 'date' && $val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            $errors[] = $label . ' is not a valid date.';
            continue;
        }
        Settings::set($k, $val);
    }
    if (Settings::get('event_start') && Settings::get('event_end') && Settings::get('event_end') < Settings::get('event_start')) {
        $errors[] = 'The last show day is before the first.';
    }
    Settings::set('event_facts_from_agent', (bool)in('event_facts_from_agent'));
    Settings::setMany([
        'header_button_enabled' => (bool)in('header_button_enabled'),
        'header_button_text'    => in_str('header_button_text', 80) ?: 'Visit {event} ↗',
        'courtesy_enabled'      => (bool)in('courtesy_enabled'),
        'courtesy_title'        => in_str('courtesy_title', 120) ?: 'Courtesy of {event}',
        'courtesy_text'         => in_str('courtesy_text', 300),
        'footer_credit_enabled' => (bool)in('footer_credit_enabled'),
        'footer_credit_text'    => in_str('footer_credit_text', 120) ?: 'Blog by {event}',
        'contact_address'       => in_str('contact_address', 300),
        'contact_phone'         => in_str('contact_phone', 60),
        'contact_email'         => in_str('contact_email', 190),
    ]);
    $social = [];
    foreach (['facebook', 'linkedin', 'instagram', 'x', 'youtube', 'tiktok'] as $s) {
        $u = in_str('social_' . $s, 300);
        if ($u !== '' && !is_web_url($u)) {
            $errors[] = ucfirst($s) . ' must be a full address starting with https://';
            $u = '';
        }
        $social[$s] = $u;
    }
    Settings::set('social_links', $social);
    Cache::clear();
    Activity::log('event.updated');
    $errors ? flash('err', 'Saved, except: ' . implode(' ', $errors)) : flash('ok', 'Saved. The blog shows the new details now.');
    back_to('event');
}

$remoteAt = (string)Settings::get('event_remote_at', '');
$social = (array)Settings::get('social_links', []);
ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <div class="card__head"><h2>Exhibition facts</h2>
      <?php if (Settings::get('chat_agent', '')): ?><button class="btn btn--small btn--ghost" name="do" value="fetch" formnovalidate>Fetch from sales agent now</button><?php endif; ?></div>
    <p class="help">These feed the countdown, the buttons, the “Courtesy of” box and Google's event listing.
      <?php if (Settings::get('chat_agent', '')): ?>Leave a box empty to use the sales agent's approved value (fetched daily<?= $remoteAt ? ', last on ' . e(local_date($remoteAt, 'j M, H:i')) : '' ?>). Anything you type here takes priority.<?php else: ?>Connect the sales agent in <a href="<?= e(aurl('chat')) ?>">Sales agent chat</a> to fill these automatically from its approved information.<?php endif; ?></p>
    <div class="grid2">
      <?php foreach ($fields as $k => [$label, $ph, $type]):
          $src = Event::source($k);
          $remote = (array)Settings::get('event_remote', []);
          $help = $src === 'sales agent' ? 'From the sales agent: <em>' . e(Event::get($k)) . '</em>' : ''; ?>
        <?= f_text($k, $label, (string)Settings::get($k, ''), $help, ['type' => $type, 'placeholder' => $src === 'sales agent' ? Event::get($k) : $ph]) ?>
      <?php endforeach; ?>
    </div>
    <?= f_check('event_facts_from_agent', 'Use the sales agent\'s facts for empty boxes', (bool)Settings::get('event_facts_from_agent', true)) ?>
  </section>

  <section class="card">
    <h2>Links back to the main website</h2>
    <p class="help">Write <code>{event}</code> to insert the exhibition name.</p>
    <?= f_check('header_button_enabled', 'Show the button in the header (it moves into the menu on phones)', (bool)Settings::get('header_button_enabled', true)) ?>
    <?= f_text('header_button_text', 'Header button text', (string)Settings::get('header_button_text', ''), '', ['maxlength' => 80]) ?>
    <?= f_check('courtesy_enabled', 'Show the “Courtesy of” box under every article', (bool)Settings::get('courtesy_enabled', true)) ?>
    <div class="grid2">
      <?= f_text('courtesy_title', 'Box title', (string)Settings::get('courtesy_title', ''), '', ['maxlength' => 120]) ?>
      <?= f_text('courtesy_text', 'One line about the exhibition', (string)Settings::get('courtesy_text', ''), 'Leave empty to use the one-line description above.', ['maxlength' => 300]) ?>
    </div>
    <?= f_check('footer_credit_enabled', 'Show the credit line in the footer', (bool)Settings::get('footer_credit_enabled', true)) ?>
    <?= f_text('footer_credit_text', 'Footer credit', (string)Settings::get('footer_credit_text', ''), 'The main website address is added after it automatically.', ['maxlength' => 120]) ?>
  </section>

  <section class="card">
    <h2>Contact details and social media pages</h2>
    <div class="grid3">
      <?= f_area('contact_address', 'Address', (string)Settings::get('contact_address', ''), '', 2) ?>
      <?= f_text('contact_phone', 'Phone', (string)Settings::get('contact_phone', '')) ?>
      <?= f_text('contact_email', 'Email', (string)Settings::get('contact_email', ''), '', ['type' => 'email']) ?>
    </div>
    <div class="grid3">
      <?php foreach (['facebook' => 'Facebook page', 'linkedin' => 'LinkedIn page', 'instagram' => 'Instagram', 'x' => 'X (Twitter)', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'] as $k => $l): ?>
        <?= f_text('social_' . $k, $l, (string)($social[$k] ?? ''), '', ['type' => 'url', 'placeholder' => 'https://']) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save</button></div>
</form>
<?php admin_render('Event & courtesy', (string)ob_get_clean(), 'event');
