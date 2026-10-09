<?php
/** The address newsletter confirmations are sent from. */
require_perm('settings');

if (is_post()) {
    post_guard();
    $row = [
        'mail_from_name' => in_str('mail_from_name', 120),
        'mail_from_email' => in_str('mail_from_email', 190),
        'mail_transport' => in('mail_transport') === 'smtp' ? 'smtp' : 'mail',
        'smtp_host' => in_str('smtp_host', 190),
        'smtp_port' => max(1, min(65535, (int)in('smtp_port', 587))),
        'smtp_encryption' => in_array(in('smtp_encryption'), ['tls', 'ssl', 'none'], true) ? (string)in('smtp_encryption') : 'tls',
        'smtp_username' => in_str('smtp_username', 190),
    ];
    if ($row['mail_from_email'] !== '' && !filter_var($row['mail_from_email'], FILTER_VALIDATE_EMAIL)) {
        flash('err', 'The sender address is not a valid email.');
        back_to('email');
    }
    Settings::setMany($row);
    $pass = (string)in('smtp_password');
    if ($pass !== '') {
        Settings::set('smtp_password', Secret::encrypt($pass));
    }
    Activity::log('email.updated');
    if (in('do') === 'test') {
        $to = (string)(Auth::user()['email'] ?? '');
        $ok = Mailer::send($to, 'Test email from ' . Event::name() . ' blog', "This is a test. Newsletter confirmations will be sent like this.\n");
        flash($ok ? 'ok' : 'err', $ok ? 'Saved. A test email was sent to ' . $to . '.' : 'Saved, but the test email failed: ' . Mailer::lastError());
    } else {
        flash('ok', 'Email settings saved.');
    }
    back_to('email');
}

ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>Sender</h2>
    <p class="help">Used only for newsletter confirmation emails. Use the exhibition's own address, e.g. "Build Asia Secretariat" &lt;info@buildasia.net&gt;.</p>
    <div class="grid2">
      <?= f_text('mail_from_name', 'From name', (string)Settings::get('mail_from_name', ''), '', ['maxlength' => 120, 'placeholder' => Event::name() . ' Secretariat']) ?>
      <?= f_text('mail_from_email', 'From email', (string)Settings::get('mail_from_email', ''), '', ['type' => 'email']) ?>
    </div>
    <?= f_select('mail_transport', 'Send through', ['mail' => 'The hosting\'s built-in mail (simplest)', 'smtp' => 'A mail server (SMTP) — more reliable delivery'], (string)Settings::get('mail_transport', 'mail')) ?>
  </section>
  <section class="card">
    <h2>Mail server (SMTP)</h2>
    <p class="help">Only needed when “A mail server” is chosen above. Use the same settings as the exhibition's email in cPanel → Email Accounts → Connect Devices.</p>
    <div class="grid2">
      <?= f_text('smtp_host', 'Server', (string)Settings::get('smtp_host', ''), 'e.g. mail.buildasia.net') ?>
      <?= f_text('smtp_port', 'Port', (string)Settings::get('smtp_port', 587), '587 with TLS, or 465 with SSL', ['type' => 'number']) ?>
      <?= f_select('smtp_encryption', 'Security', ['tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'], (string)Settings::get('smtp_encryption', 'tls')) ?>
      <?= f_text('smtp_username', 'Username', (string)Settings::get('smtp_username', ''), 'Usually the full email address') ?>
      <?= f_text('smtp_password', 'Password', '', Settings::get('smtp_password', '') !== '' ? 'Saved (hidden). Type a new one only to change it.' : 'Stored encrypted.', ['type' => 'password', 'autocomplete' => 'new-password']) ?>
    </div>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save</button> <button class="btn btn--ghost" name="do" value="test">Save and send me a test</button></div>
</form>
<?php admin_render('Email', (string)ob_get_clean(), 'email');
