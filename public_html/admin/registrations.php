<?php
declare(strict_types=1);

/**
 * Visitor registrations collected from the shared registration database.
 *
 * The screen answers three questions in order: is the connection working,
 * which of their events belong to this exhibition, and — for each person
 * collected — why they can or cannot be messaged.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_role('admin');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];

$testResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_connection') {
        Settings::set('regsource.db_host', Validator::text($_POST['db_host'] ?? null, 160) ?? 'localhost');
        Settings::set('regsource.db_name', Validator::text($_POST['db_name'] ?? null, 96));
        Settings::set('regsource.db_user', Validator::text($_POST['db_user'] ?? null, 96));
        Settings::set('regsource.table',   Validator::text($_POST['table'] ?? null, 64) ?? 'registrations');

        // An empty password box leaves the saved one alone, so re-saving the
        // other fields does not silently wipe it.
        $pass = (string)($_POST['db_pass'] ?? '');
        if ($pass !== '') {
            Settings::set('regsource.db_pass', $pass);
        }

        Audit::setActor($actor);
        Audit::log('regsource_configured', $agentId, 'settings', null, 'Registration database settings saved');
        admin_flash('Saved. Press Test the connection to check it.');
        admin_redirect('admin/registrations.php');
    }

    if ($action === 'save_events') {
        RegistrationSource::setEventIds($agentId, (string)($_POST['events'] ?? ''));
        admin_flash('Event numbers saved for ' . (string)$agent['name'] . '.');
        admin_redirect('admin/registrations.php');
    }

    if ($action === 'test') {
        $testResult = RegistrationSource::test();
    }

    if ($action === 'start_here') {
        $latest = RegistrationSource::currentHighestId($agentId);
        if ($latest === null) {
            admin_flash('Could not reach the registration database.', 'error');
        } else {
            RegistrationSource::setWatermark($agentId, $latest);
            admin_flash('Starting from now. Registrations from this point on will be collected; '
                . 'the ' . number_format($latest) . ' older ones are left alone.');
        }
        admin_redirect('admin/registrations.php');
    }

    if ($action === 'collect_all') {
        RegistrationSource::setWatermark($agentId, 0);
        admin_flash('Set to collect everything from the beginning. This may take several runs.', 'warn');
        admin_redirect('admin/registrations.php');
    }

    if ($action === 'recheck') {
        $done = RegistrationIntake::recheckNumbers($agent, 1000);
        if ($done['checked'] === 0) {
            admin_flash('There are no unreadable numbers to check.');
        } elseif ($done['recovered'] === 0) {
            admin_flash($done['checked'] . ' checked again, none could be read. '
                . 'Those numbers are genuinely unusable.', 'warn');
        } else {
            admin_flash($done['recovered'] . ' of ' . $done['checked']
                . ' number(s) could be read on a second look and are now usable.');
        }
        admin_redirect('admin/registrations.php');
    }

    if ($action === 'import') {
        $batch = RegistrationIntake::importBatch($agent, 200);
        if ($batch['error'] !== null) {
            admin_flash($batch['error'], 'error');
        } elseif ($batch['imported'] === 0) {
            admin_flash('Nothing new to collect.');
        } else {
            admin_flash($batch['imported'] . ' registration(s) collected'
                . ($batch['companies'] > 0 ? ', ' . $batch['companies'] . ' new company record(s)' : '') . '.');
        }
        admin_redirect('admin/registrations.php');
    }
}

$configured = RegistrationSource::isConfigured();
$events     = RegistrationSource::eventIds($agentId);
$watermark  = RegistrationSource::watermark($agentId);

$counts = [];
foreach (db()->all(
    'SELECT whatsapp_state, COUNT(*) AS c FROM registrations WHERE agent_id = ? GROUP BY whatsapp_state',
    [$agentId]
) as $row) {
    $counts[(string)$row['whatsapp_state']] = (int)$row['c'];
}
$total = array_sum($counts);

admin_layout_start('Registrations');

echo '<p class="lede">Visitors who registered for this exhibition, read from the shared '
   . 'registration system. Nothing is written back to it — the platform only reads.</p>';

// ---- Connection ----
echo '<div class="card"><div class="card-head"><h2 style="margin:0">Connection</h2>'
   . ($configured ? '<span class="pill pill-ok">Set up</span>' : '<span class="pill pill-warn">Not set up</span>')
   . '</div>';

if ($configured) {
    // Set up once and rarely touched, so the details fold away.
    echo '<p class="hint" style="margin:0 0 10px">Reading <span class="mono">'
       . e((string)RegistrationSource::setting('db_name', '')) . '.' . e((string)RegistrationSource::setting('table', 'registrations'))
       . '</span> as <span class="mono">' . e((string)RegistrationSource::setting('db_user', '')) . '</span>.</p>'
       . '<details><summary>Change the connection details</summary><div style="padding-top:12px">';
}

if (!$configured) {
    echo '<div class="alert alert-warn"><p>Not set up yet. You need a database user with '
       . '<strong>read-only</strong> permission on the registrations database.</p></div>'
       . '<p class="hint">In cPanel on the account that owns the registration site: '
       . 'MySQL Databases → create a new user → add it to the registrations database → '
       . 'tick <strong>SELECT only</strong>, nothing else. That user cannot change or delete '
       . 'anything, which is exactly what we want.</p>';
}

echo '<form method="post">' . Security::csrfField()
   . '<input type="hidden" name="action" value="save_connection">'
   . '<div class="field-row">'
   . f_text('db_host', 'Database host', (string)RegistrationSource::setting('db_host', 'localhost'), [
        'hint' => 'localhost, because both accounts are on the same server.'])
   . f_text('db_name', 'Database name', (string)RegistrationSource::setting('db_name', ''), [
        'placeholder' => 'ecgexhibitions_forms'])
   . '</div><div class="field-row">'
   . f_text('db_user', 'Read-only username', (string)RegistrationSource::setting('db_user', ''))
   . f_text('db_pass', 'Password', '', ['type' => 'password',
        'hint' => Settings::hasValue('regsource.db_pass')
            ? 'A password is saved. Leave blank to keep it.'
            : 'Stored encrypted and never shown again.'])
   . '</div>'
   . f_text('table', 'Table name', (string)RegistrationSource::setting('table', 'registrations'))
   . '<div class="btn-row"><button class="btn" type="submit">Save</button>'
   . ($configured ? '' : '<button class="btn btn-quiet" type="submit" form="reg-test">Test the connection</button>')
   . '</div></form>';

if ($configured) {
    echo '</div></details><div class="btn-row" style="margin-top:12px">'
       . '<button class="btn btn-quiet btn-small" type="submit" form="reg-test">Test the connection</button></div>';
}

echo '<form method="post" id="reg-test" class="side-form">' . Security::csrfField()
   . '<input type="hidden" name="action" value="test"></form>';

if ($testResult !== null) {
    echo '<div class="alert ' . ($testResult['ok'] ? 'alert-ok' : 'alert-error') . '"><p>'
       . e((string)$testResult['message']) . '</p></div>';

    if ($testResult['ok'] && $testResult['events']) {
        echo '<h3>Events found in that table</h3>'
           . '<p class="hint">Use these numbers below to say which belong to '
           . e((string)$agent['name']) . '.</p>'
           . '<div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>Event number</th><th>City</th><th>Registrations</th></tr></thead><tbody>';
        foreach ($testResult['events'] as $ev) {
            echo '<tr><td class="mono">' . (int)$ev['event_id'] . '</td>'
               . '<td>' . e((string)$ev['city']) . '</td>'
               . '<td>' . number_format((int)$ev['count']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
echo '</div>';

// ---- Which events ----
echo '<div class="card"><h2>Which events belong to ' . e((string)$agent['name']) . '</h2>'
   . '<p class="lede">One table holds every exhibition. Only the event numbers listed here '
   . 'are collected for this one.</p>'
   . '<form method="post">' . Security::csrfField()
   . '<input type="hidden" name="action" value="save_events">'
   . f_text('events', 'Event numbers', implode(', ', $events), [
        'placeholder' => '24, 62',
        'hint' => 'Separate with commas. For Build Asia that is 24 for Karachi and 62 for Lahore.'])
   . '<div class="btn-row"><button class="btn" type="submit">Save</button></div>'
   . '</form></div>';

// ---- Collecting ----
if ($configured && $events) {
    $pending = RegistrationSource::pendingCount($agentId);

    echo '<div class="card"><h2>Collecting</h2>';

    if ($watermark === 0 && $total === 0) {
        echo '<div class="alert alert-warn"><p>Nothing has been collected yet. That table holds '
           . 'years of history, so choose where to begin.</p></div>'
           . '<div class="btn-row">'
           . '<form method="post" style="margin:0">' . Security::csrfField()
           . '<input type="hidden" name="action" value="start_here">'
           . '<button class="btn" type="submit">Start from now</button></form>'
           . '<form method="post" style="margin:0" data-confirm="Collect every registration ever made for these events? This may take several runs.">'
           . Security::csrfField()
           . '<input type="hidden" name="action" value="collect_all">'
           . '<button class="btn btn-quiet" type="submit">Collect everything from the beginning</button></form>'
           . '</div>'
           . '<p class="hint">Start from now is the usual choice. Collect everything is for when '
           . 'you want the back catalogue for email prospecting.</p>';
    } else {
        echo '<p class="lede">Collected up to registration number <strong>'
           . number_format($watermark) . '</strong>. '
           . ($pending === null
                ? 'Could not check how many are waiting.'
                : number_format($pending) . ' waiting.') . '</p>'
           . '<div class="btn-row"><form method="post" style="margin:0">' . Security::csrfField()
           . '<input type="hidden" name="action" value="import">'
           . '<button class="btn" type="submit">Collect now</button></form></div>'
           . '<p class="hint">This also runs by itself on the schedule, so you do not have to '
           . 'press it. It collects up to 200 at a time.</p>';
    }

    echo '</div>';
}

// ---- What was collected ----
if ($total > 0) {
    echo '<div class="card"><h2>' . number_format($total) . ' collected</h2>'
       . '<p class="lede">Why each person can or cannot be messaged on WhatsApp. Every one of '
       . 'these is a reason to hold back; none of them grants permission on its own.</p>'
       . '<div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Status</th><th>How many</th><th>What it means</th></tr></thead><tbody>';

    $explain = [
        'eligible'   => 'Has consent, a usable number, and is not blocked.',
        'no_consent' => 'The registration form does not yet ask permission to message. Until it does, nobody is messaged.',
        'no_number'  => 'No number, or one that could not be read as a real mobile.',
        'onsite'     => 'Registered at the venue. Held for email follow-up instead, as agreed.',
        'suppressed' => 'On the do-not-contact list.',
        'sent'       => 'A message has been sent.',
        'opted_out'  => 'Asked not to be contacted again.',
    ];

    foreach ($counts as $state => $n) {
        echo '<tr><td><strong>' . e(RegistrationIntake::stateLabel($state)) . '</strong></td>'
           . '<td>' . number_format($n) . '</td>'
           . '<td class="small">' . e($explain[$state] ?? '') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // ---- Why numbers could not be used ----
    $problems = db()->all(
        'SELECT phone_problem, COUNT(*) AS c FROM registrations
          WHERE agent_id = ? AND phone_e164 IS NULL AND phone_problem IS NOT NULL
          GROUP BY phone_problem ORDER BY c DESC',
        [$agentId]
    );

    if ($problems) {
        echo '<div class="card"><h2>Numbers that could not be used</h2>'
           . '<p class="lede">Worth knowing which, because they are not the same problem. '
           . 'A landline was never reachable; a number damaged by a spreadsheet still exists '
           . 'correctly in the registration system.</p>'
           . '<div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>Problem</th><th>How many</th><th>What can be done</th></tr></thead><tbody>';

        $advice = [
            'landline'     => 'Nothing. WhatsApp cannot reach a landline. Reach them by email instead.',
            'spreadsheet'  => 'The digits are genuinely lost. The original row in the registration system still has them.',
            'wrong_length' => 'Usually a typo when registering. Nothing to do unless they get in touch.',
            'too_long'     => 'Two numbers in one box that could not be separated.',
            'no_country'   => 'No country code and no country given, so it cannot be completed safely.',
            'empty'        => 'No number was entered.',
            'unreadable'   => 'Not recognisable as a phone number.',
        ];

        foreach ($problems as $problem) {
            $key = (string)$problem['phone_problem'];
            echo '<tr><td><strong>' . e(Phone::reasonLabel($key)) . '</strong></td>'
               . '<td>' . number_format((int)$problem['c']) . '</td>'
               . '<td class="small">' . e($advice[$key] ?? '') . '</td></tr>';
        }

        echo '</tbody></table></div>'
           . '<form method="post" style="margin-top:8px">' . Security::csrfField()
           . '<input type="hidden" name="action" value="recheck">'
           . '<button class="btn btn-quiet" type="submit">Read these numbers again</button></form>'
           . '<p class="hint">Worth pressing after an update, in case a number an earlier version '
           . 'could not read can now be understood. It never changes anyone who has already been '
           . 'messaged or opted out.</p></div>';
    }

    // ---- Recent ----
    $recent = db()->all(
        'SELECT r.*, c.name AS company_record
           FROM registrations r LEFT JOIN companies c ON c.id = r.company_id
          WHERE r.agent_id = ? ORDER BY r.source_id DESC LIMIT 50',
        [$agentId]
    );

    echo '<div class="card" style="padding:0"><div class="card-head" style="padding:16px 16px 4px">'
       . '<h2 style="margin:0">Latest registrations</h2><span class="hint" style="margin:0">Newest '
       . min(50, count($recent)) . ' of ' . number_format($total) . '. Open a name to see everything about them.</span></div>'
       . '<div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">Name</th><th>Company</th><th>Email</th>'
       . '<th>Number</th><th>Event</th><th>Status</th></tr></thead><tbody>';

    foreach ($recent as $r) {
        $companyCell = $r['company_record'] !== null
            ? '<a href="' . e(url('admin/companies.php?view=' . (int)$r['company_id'])) . '">'
              . e((string)$r['company_record']) . '</a>'
            : e((string)($r['company_name'] ?? ''));

        echo '<tr><td style="padding-left:16px"><a href="' . e(PersonView::url('registration', (int)$r['id'])) . '">'
           . e(trim((string)($r['name'] ?? '')) !== '' ? (string)$r['name'] : '(no name)') . '</a></td>'
           . '<td class="small">' . $companyCell . '</td>'
           . '<td class="small mono">' . e((string)($r['email'] ?? '')) . '</td>'
           . '<td class="small mono">' . e(Phone::mask((string)($r['phone_e164'] ?? ''))) . '</td>'
           . '<td class="small">' . (int)$r['source_event_id'] . '</td>'
           . '<td class="small">' . e(RegistrationIntake::stateLabel((string)$r['whatsapp_state'])) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

// ---- The consent gate ----
// Only while nobody has given permission; once some have, it would be wrong.
$anyConsent = array_intersect_key($counts ?? [], array_flip(['eligible', 'sent', 'opted_out']));
if (!$anyConsent) {
echo '<div class="card"><h2>Before anyone can be messaged</h2>'
   . '<p class="lede">The registration form does not currently ask permission to send WhatsApp '
   . 'messages, so the platform will not send any. This is deliberate and cannot be overridden '
   . 'from this screen.</p>'
   . '<p class="hint">Once a consent box is added to the form and its answer is stored, those '
   . 'registrations will show as ready to message. Registrations taken at the venue stay held '
   . 'back regardless, and are worked by email instead.</p></div>';
}

admin_layout_end();
