<?php
declare(strict_types=1);

/**
 * Platform settings: API keys, email delivery, data retention and the
 * housekeeping task. Secrets are encrypted and never shown back.
 */

require __DIR__ . '/_admin.php';

$user = admin_require_role('admin');

$secretPlaceholder = '__unchanged__';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'save') {
        // A secret left at the placeholder means "keep what is stored".
        $secrets = array_merge(
            LlmCatalogue::secretKeys(),
            MessagingCatalogue::secretKeys(),
            ResearchCatalogue::secretKeys(),
            ['geo.ipinfo.token', 'mail.smtp.password']
        );
        foreach ($secrets as $key) {
            $value = (string)($_POST[str_replace('.', '_', $key)] ?? '');
            if ($value === '' || $value === $secretPlaceholder) {
                continue;
            }
            Settings::set($key, $value);
            Audit::log('setting_changed', null, 'setting', null, $key . ' updated', $key, '(hidden)', '(hidden)');
        }

        // Where a model runs is not a secret, but it is a URL and has to look
        // like one before it is stored.
        foreach (LlmCatalogue::all() as $providerKey => $meta) {
            if (empty($meta['base_url_setting'])) {
                continue;
            }
            $field = str_replace('.', '_', (string)$meta['base_url_setting']);
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $value = trim((string)$_POST[$field]);
            if ($value === '') {
                Settings::forget((string)$meta['base_url_setting']);
                continue;
            }
            if (!preg_match('#^https?://[^\s]+$#i', $value)) {
                admin_flash(
                    (string)$meta['label'] . ': that address does not look right. It should start with '
                    . 'http:// or https:// and usually ends in /v1.',
                    'error'
                );
                continue;
            }
            if (str_starts_with(strtolower($value), 'http://') && !Http::isLocalPlainHttp($value)) {
                admin_flash(
                    (string)$meta['label'] . ': an http:// address on the public internet would send your '
                    . 'key unencrypted, so it was not saved. Use https://, or an address on this machine '
                    . 'or your own network.',
                    'error'
                );
                continue;
            }
            Settings::set((string)$meta['base_url_setting'], rtrim($value, '/'));
        }

        $plain = [
            'whatsapp.provider'    => Validator::inList($_POST['whatsapp_provider'] ?? null,
                                        array_keys(MessagingCatalogue::PROVIDERS), 'none'),
            'research.provider'    => Validator::inList($_POST['research_provider'] ?? null,
                                        array_keys(ResearchCatalogue::selectable()), 'none'),
            'research.searches_per_company' => (string)max(1, min(6, (int)($_POST['research_searches'] ?? 4))),
            'research.monthly_credit_limit' => (string)max(0, min(100000, (int)($_POST['research_monthly_limit'] ?? 900))),
            'research.first_reply_wait_seconds' => (string)max(0, min(20, (int)($_POST['research_first_wait'] ?? 18))),
            'mail.transport'       => Validator::inList($_POST['mail_transport'] ?? null, ['mail', 'smtp'], 'mail'),
            'mail.from_address'    => (string)(Validator::email($_POST['mail_from_address'] ?? null) ?? ''),
            'mail.from_name'       => (string)(Validator::text($_POST['mail_from_name'] ?? null, 120) ?? ''),
            'mail.smtp.host'       => (string)(Validator::text($_POST['mail_smtp_host'] ?? null, 190) ?? ''),
            'mail.smtp.port'       => (string)max(1, (int)($_POST['mail_smtp_port'] ?? 587)),
            'mail.smtp.username'   => (string)(Validator::text($_POST['mail_smtp_username'] ?? null, 190) ?? ''),
            'mail.smtp.encryption' => Validator::inList($_POST['mail_smtp_encryption'] ?? null, ['tls', 'ssl', 'none'], 'tls'),
            'security.trust_proxy' => !empty($_POST['security_trust_proxy']) ? '1' : '0',
        ];
        foreach ($plain as $key => $value) {
            $old = (string)Settings::get($key, '');
            if ($old !== $value) {
                Settings::set($key, $value);
                Audit::log('setting_changed', null, 'setting', null, $key, $key, $old, $value);
            }
        }

        admin_flash('Settings saved.');
        admin_redirect('admin/settings.php');
    }

    // A secret nobody chose is worse than none, because it looks like
    // protection. Made here rather than left to be typed, and on its own
    // action so it cannot take the rest of the page's fields with it.
    if ($action === 'make_webhook_secret') {
        Settings::set('whatsapp.webhook_secret', Crypto::token(24));
        Audit::log('setting_changed', null, 'setting', null, 'WhatsApp webhook address replaced',
            'whatsapp.webhook_secret', '(hidden)', '(hidden)');
        admin_flash('A new webhook address was made. Paste it into your messaging service — the old one has '
                  . 'stopped working.');
        admin_redirect('admin/settings.php');
    }

    // One real search, so the key and the connection are proven before a
    // visitor's research depends on them. Uses one credit.
    if ($action === 'test_research') {
        $provider = ResearchProviderFactory::make();
        if (!$provider->isConfigured()) {
            admin_flash('Choose Tavily and save its API key first.', 'error');
        } else {
            $test = $provider->search('Karachi Expo Centre exhibitions', ['max_results' => 3]);
            CompanyResearch::addExtraCredits((int)$test['credits']);
            admin_flash($test['ok']
                ? 'The research service is working: ' . count($test['results']) . ' result(s) came back.'
                : (string)$test['error'], $test['ok'] ? 'ok' : 'error');
            Audit::log('research_tested', null, 'setting', null, $test['ok'] ? 'working' : 'failed');
        }
        admin_redirect('admin/settings.php');
    }

    if ($action === 'test_provider') {
        $providerKey = (string)($_POST['provider'] ?? '');
        if (!LlmCatalogue::has($providerKey)) {
            admin_flash('That AI provider is not one this platform knows about.', 'error');
            admin_redirect('admin/settings.php');
        }
        $result = LlmProviderFactory::test($providerKey, $_POST['test_model'] ?? null);
        admin_flash(
            $result['message'] . ($result['ok'] && $result['latency_ms'] > 0
                ? ' It replied in ' . number_format($result['latency_ms']) . ' ms.'
                : ''),
            $result['ok'] ? 'ok' : 'error'
        );
        Audit::log(
            'provider_tested', null, 'setting', null,
            $providerKey . ': ' . ($result['ok'] ? 'working' : 'failed')
        );
        admin_redirect('admin/settings.php');
    }

    if ($action === 'test_email') {
        $to = Validator::email($_POST['test_to'] ?? null);
        if ($to === null) {
            admin_flash('Enter a valid address to send the test to.', 'error');
        } else {
            $ok = Mailer::deliver(
                $to,
                'Test message from your AI Sales Agent',
                "This is a test.\n\nIf you received it, email delivery is working.",
                '<p style="font-family:sans-serif">This is a test.</p>'
                . '<p style="font-family:sans-serif">If you received it, email delivery is working.</p>'
            );
            admin_flash($ok
                ? 'Test message sent to ' . $to . '. Check the inbox, and the spam folder.'
                : 'Could not send: ' . (Mailer::lastError() ?: 'unknown problem') . '.', $ok ? 'ok' : 'error');
        }
        admin_redirect('admin/settings.php');
    }

    if ($action === 'flush_mail') {
        $sent = Mailer::flush(25);
        admin_flash($sent . ' queued ' . ($sent === 1 ? 'message' : 'messages') . ' sent.');
        admin_redirect('admin/settings.php');
    }
}

$has = static fn(string $key): bool => trim((string)Settings::get($key, '')) !== '';
$val = static fn(string $key, string $default = ''): string => (string)Settings::get($key, $default);

$queued = (int)db()->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'queued'");
$failed = (int)db()->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'failed'");

/**
 * A small form that lives outside the main one. Buttons and boxes anywhere on
 * the page join it through their form="..." attribute, so a test button can
 * sit right next to what it tests without nesting forms.
 */
$sideForm = static function (string $id, string $action, array $hidden = [], string $confirm = ''): string {
    $html = '<form method="post" id="' . e($id) . '" class="side-form"'
          . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
          . Security::csrfField() . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . e((string)$name) . '" value="' . e((string)$value) . '">';
    }
    return $html . '</form>';
};
$sideForms = [];

admin_layout_start('Settings');

echo '<p class="lede">Things every exhibition shares: the AI services, email, the WhatsApp connection and company '
   . 'research. Each exhibition\'s own settings are under <a href="' . e(url('admin/agents.php')) . '">Agents and '
   . 'embed</a>.</p>';

echo admin_page_nav([
    'set-ai' => 'AI providers', 'set-mail' => 'Email', 'set-whatsapp' => 'WhatsApp',
    'set-research' => 'Company research', 'set-tasks' => 'Scheduled tasks', 'set-other' => 'Country and security',
]);

echo '<form method="post" class="settings-form" id="settings-main">' . Security::csrfField()
   . '<input type="hidden" name="action" value="save">';

// ---- AI providers ----
$inUse = [];
foreach (admin_agents() as $someAgent) {
    $inUse[(string)$someAgent['llm_provider']][] = (string)$someAgent['name'];
    if (trim((string)($someAgent['llm_fallback_provider'] ?? '')) !== '') {
        $inUse[(string)$someAgent['llm_fallback_provider']][] = (string)$someAgent['name'] . ' (as backup)';
    }
}
$readyCount = 0;
foreach (array_keys(LlmCatalogue::all()) as $providerKey) {
    $readyCount += LlmCatalogue::isConfigured($providerKey) ? 1 : 0;
}

echo admin_card_open('set-ai', 'AI providers', 'The services that write the replies. Set up one or more, then choose '
        . 'which each exhibition uses under Agents and embed.')
   . '<p class="hint" style="margin:0 0 12px">' . $readyCount . ' of ' . count(LlmCatalogue::all()) . ' set up. '
   . 'Keys are encrypted and never shown again — leave a box untouched to keep what is saved. Open one to add or '
   . 'change its key; save, then press <b>Test</b> on it.</p><div class="prov-grid">';

foreach (LlmCatalogue::all() as $providerKey => $meta) {
    $keySetting = (string)($meta['key_setting'] ?? '');
    $hasKey     = $keySetting !== '' && $has($keySetting);
    $ready      = LlmCatalogue::isConfigured($providerKey);
    $used       = array_unique($inUse[$providerKey] ?? []);
    $formId     = 'test-' . preg_replace('/[^a-z0-9_-]/i', '', $providerKey);

    echo '<details class="prov' . ($ready ? ' is-ready' : '') . '"' . ($ready || $used ? ' open' : '') . '>'
       . '<summary><span class="prov-name">' . e((string)$meta['label']) . '</span>'
       . ($ready ? '<span class="pill pill-ok">Ready</span>' : '<span class="pill">Not set up</span>')
       . ($used ? '<span class="prov-used">Used by ' . e(implode(', ', $used)) . '</span>' : '')
       . '</summary><div class="prov-body">';

    echo '<p class="hint" style="margin:0 0 12px">' . e((string)$meta['notes']);
    if (!empty($meta['console'])) {
        echo ' <a href="' . e((string)$meta['console']) . '" target="_blank" rel="noopener noreferrer">Get a key</a>.';
    }
    echo '</p>';

    if (!empty($meta['base_url_setting'])) {
        echo f_text(
            str_replace('.', '_', (string)$meta['base_url_setting']),
            'Address it is running at',
            (string)Settings::get((string)$meta['base_url_setting'], ''),
            [
                'placeholder' => (string)($meta['default_base_url'] ?? ''),
                'hint'        => !empty($meta['self_hosted'])
                    ? 'For example ' . (string)$meta['default_base_url'] . '. Plain http:// is allowed only '
                      . 'for this machine or your own network.'
                    : 'Leave blank to use the standard address.',
            ]
        );
    }

    if ($keySetting !== '') {
        echo f_text(
            str_replace('.', '_', $keySetting),
            'API key' . (empty($meta['key_required']) ? ' (optional)' : ''),
            $hasKey ? $secretPlaceholder : '',
            [
                'type' => 'password',
                'hint' => ($hasKey ? 'A key is saved. ' : 'No key saved yet. ') . (string)($meta['key_hint'] ?? ''),
            ]
        );
    }

    // Testing it, right here. The model box and button belong to a small
    // form of their own (see $sideForm), not to the settings being saved.
    $problem = LlmCatalogue::configurationProblem($providerKey);
    $models  = LlmCatalogue::models($providerKey);
    echo '<div class="test-row"><label for="m_' . e($formId) . '">Test with model</label><div class="inline">'
       . '<input type="text" id="m_' . e($formId) . '" name="test_model" form="' . e($formId) . '"'
       . ($models ? ' list="models_' . e($providerKey) . '"' : '')
       . ' value="' . e(LlmCatalogue::defaultModel($providerKey)) . '" placeholder="model name">'
       . '<button class="btn btn-small' . ($problem !== null ? ' btn-quiet' : '') . '" type="submit" form="'
       . e($formId) . '"' . ($problem !== null ? ' disabled title="' . e($problem) . '"' : '') . '>Test</button></div>'
       . '<p class="hint">' . e($problem ?? 'Sends one tiny request and reports exactly what came back.')
       . ($models ? ' Models include ' . e(implode(', ', $models)) . '.' : '') . '</p></div>';
    if ($models) {
        echo '<datalist id="models_' . e($providerKey) . '">';
        foreach ($models as $modelName) {
            echo '<option value="' . e($modelName) . '"></option>';
        }
        echo '</datalist>';
    }
    $sideForms[] = $sideForm($formId, 'test_provider', ['provider' => $providerKey]);

    echo '</div></details>';
}
echo '</div>' . admin_card_close();

// ---- Email ----
echo admin_card_open('set-mail', 'Email to your team', 'How lead notifications and test messages are sent. '
        . 'Prospect emails use each exhibition\'s own sender instead.')
   . f_select('mail_transport', 'How email is sent', [
        'mail' => 'The server\'s own mail function (simplest on cPanel)',
        'smtp' => 'An SMTP server (more reliable delivery)',
     ], $val('mail.transport', (string)cfg('mail_transport', 'mail')))
   . '<div class="field-row">'
   . f_text('mail_from_address', 'From address', $val('mail.from_address', (string)cfg('mail_from', '')), ['type' => 'email',
        'hint' => 'Use an address on your own domain, or messages will be treated as spam.'])
   . f_text('mail_from_name', 'From name', $val('mail.from_name', 'AI Sales Agent'))
   . '</div>'
   . '<h3 class="set-sub">SMTP — only if you chose it above</h3><div class="field-row">'
   . f_text('mail_smtp_host', 'Server', $val('mail.smtp.host'))
   . f_text('mail_smtp_port', 'Port', $val('mail.smtp.port', '587'), ['type' => 'number'])
   . f_select('mail_smtp_encryption', 'Encryption', ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None'], $val('mail.smtp.encryption', 'tls'))
   . '</div><div class="field-row">'
   . f_text('mail_smtp_username', 'Username', $val('mail.smtp.username'))
   . f_text('mail_smtp_password', 'Password', $has('mail.smtp.password') ? $secretPlaceholder : '', ['type' => 'password'])
   . '</div>'
   . '<h3 class="set-sub">Check it works</h3>'
   . '<div class="test-row"><label for="f_test_to">Send a test message to</label><div class="inline">'
   . '<input type="email" id="f_test_to" name="test_to" form="test-email" value="' . e((string)($user['email'] ?? '')) . '">'
   . '<button class="btn btn-small" type="submit" form="test-email">Send test</button></div>'
   . '<p class="hint">Save any changes first. Then check the inbox, and the spam folder.</p></div>'
   . '<p class="queue-line"><span>Waiting to be sent: <b>' . $queued . '</b> · could not be sent: <b>' . $failed . '</b>. '
   . 'Notifications go out just after the visitor gets their reply, so a slow mail server never holds up a '
   . 'conversation.</span>'
   . '<button class="btn btn-small btn-quiet" type="submit" form="flush-mail"' . ($queued === 0 ? ' disabled' : '')
   . '>Send waiting messages now</button></p>'
   . admin_card_close();
$sideForms[] = $sideForm('test-email', 'test_email');
$sideForms[] = $sideForm('flush-mail', 'flush_mail');

// ---- WhatsApp ----
$waProvider = MessagingCatalogue::chosen();
$waMeta     = MessagingCatalogue::meta($waProvider);
$waSecret   = $has('whatsapp.webhook_secret');

echo admin_card_open('set-whatsapp', 'WhatsApp connection', 'The service every exhibition\'s WhatsApp number goes '
        . 'through. Numbers, keys, hours and limits are set per exhibition.')
   . f_select('whatsapp_provider', 'Messaging service', MessagingCatalogue::options(), $waProvider)
   . '<p class="hint" style="margin:0 0 12px">Each WhatsApp number has its own API key. Enter it next to the '
   . 'number under <a href="' . e(url('admin/agents.php')) . '">Agents and embed</a> → Edit → WhatsApp.</p>';

if ($has('whatsapp.textmebot.api_key')) {
    // Saved here by the earlier version, before keys moved to each exhibition.
    echo '<p class="note-warn">A key was saved on this page by an earlier version. It is no longer used: enter it '
       . 'again under the exhibition whose number it belongs to.</p>';
}

// Said plainly, because it has consequences the operator is entitled to
// weigh: an unofficial bridge is cheap and quick, and the number it uses
// can be blocked by WhatsApp without warning or appeal.
if ($waProvider !== 'none' && empty($waMeta['official'])) {
    echo '<p class="note-warn">' . e((string)$waMeta['label']) . ' is not Meta\'s official WhatsApp Business API. '
       . 'It works by linking to a real WhatsApp account, much as WhatsApp Web does. That is why it is inexpensive '
       . 'and quick to set up, and also why the number can be blocked if people report the messages. Use a number '
       . 'you can afford to lose, keep the daily limits low, and stop at the first request to stop.</p>';
}

echo '<h3 class="set-sub">Where WhatsApp replies come back to</h3>'
   . '<p class="hint" style="margin:0 0 10px">Paste an exhibition\'s address into your messaging service as its '
   . 'webhook, and replies are answered by that exhibition\'s assistant. Without it, messages go out but nothing '
   . 'comes back.</p>';

if (!$waSecret) {
    echo '<p class="hint">No address has been made yet.</p>';
} else {
    $waToken = (string)Settings::get('whatsapp.webhook_secret', '');
    echo '<div class="table-wrap"><table class="table"><thead><tr><th>Exhibition</th><th>Webhook address</th></tr></thead><tbody>';
    // Every exhibition is listed, switched on or not, so the address can be
    // given to the messaging service before anything is sent.
    foreach (admin_agents() as $waAgentRow) {
        $waOn = (int)($waAgentRow['whatsapp_enabled'] ?? 0) === 1;
        echo '<tr><td>' . e((string)$waAgentRow['name'])
           . ($waOn ? '' : '<br><span class="hint" style="margin:0">WhatsApp is off for this one</span>')
           . '</td><td><code style="word-break:break-all">'
           . e(url('api/whatsapp.php') . '?agent=' . rawurlencode((string)$waAgentRow['slug'])
               . '&token=' . rawurlencode($waToken))
           . '</code></td></tr>';
    }
    echo '</tbody></table></div>'
       . '<p class="hint">Anyone holding this address can send messages into the assistant, so treat it like '
       . 'a password. If it leaks, make a new one — the old address stops working immediately.</p>';
}
echo '<p><button class="btn btn-small' . ($waSecret ? ' btn-quiet' : '') . '" type="submit" form="webhook-secret">'
   . ($waSecret ? 'Make a new address' : 'Make the address') . '</button></p>'
   . admin_card_close();
$sideForms[] = $sideForm('webhook-secret', 'make_webhook_secret', [], $waSecret
    ? 'Making a new address stops the current one working. You will have to paste the new one into your '
      . 'messaging service. Continue?'
    : '');

// ---- Company research ----
$rsChosen = ResearchCatalogue::chosen();
$rsUsed   = Migrations::tableExists('company_research') ? CompanyResearch::creditsThisMonth() : 0;
$rsLimit  = CompanyResearch::monthlyCreditLimit();

echo admin_card_open('set-research', 'Company research', 'Looks companies up on the web before the assistant '
        . 'pitches to them. Only for serious prospects, never ordinary visitors.')
   . '<p class="hint" style="margin:0 0 12px">It finds what a company makes, whether it exports and exhibits, any '
   . 'link to Pakistan, who decides, and which of your packages fits — and every fact keeps the address it came '
   . 'from.</p>'
   . f_select('research_provider', 'Research service', ResearchCatalogue::selectable(), $rsChosen)
   . '<p class="hint" style="margin:-4px 0 12px">Tavily: ' . e((string)ResearchCatalogue::PROVIDERS['tavily']['note']) . ' '
   . e((string)ResearchCatalogue::PROVIDERS['tavily']['cost']) . ' '
   . '<a href="' . e((string)ResearchCatalogue::PROVIDERS['tavily']['console']) . '" target="_blank" '
   . 'rel="noopener noreferrer">Get a key</a>.</p>'
   . f_text('research_tavily_api_key', 'Tavily API key', $has('research.tavily.api_key') ? $secretPlaceholder : '', [
        'type' => 'password',
        'hint' => $has('research.tavily.api_key') ? 'A key is saved. Leave this untouched to keep it.' : 'No key saved yet.',
     ])
   . '<div class="field-row">'
   . f_text('research_searches', 'Searches per company', (string)CompanyResearch::searchesPerCompany(), [
        'type' => 'number',
        'hint' => 'Between 1 and 6. Four covers the company\'s own site, trade shows, exports and Pakistan.',
     ])
   . f_text('research_monthly_limit', 'Most search credits per month', (string)$rsLimit, [
        'type' => 'number',
        'hint' => 'Research stops for the month when this is reached. 900 stays inside Tavily\'s free 1,000.',
     ])
   . '</div>'
   . f_text('research_first_wait', 'Seconds to wait before the first reply to an exhibitor',
        (string)CompanyResearch::firstReplyWait(), [
        'type' => 'number',
        'hint' => 'Between 0 and 20. When someone chooses "exhibiting", their company is looked up first, so the first '
                . 'reply is about their business. The visitor sees only the typing dots. 0 switches the wait off.',
     ]);

$rsPct = $rsLimit > 0 ? min(100, (int)round(100 * $rsUsed / $rsLimit)) : 0;
echo '<div class="meter-line"><span>This month: <b>' . number_format($rsUsed) . '</b> of '
   . number_format($rsLimit) . ' credits used</span><span class="meter" role="img" aria-label="' . $rsPct
   . ' percent used"><span style="width:' . $rsPct . '%"></span></span>'
   . '<button class="btn btn-small btn-quiet" type="submit" form="test-research">Test the research service</button></div>'
   . '<p class="hint">The test makes one real search (one credit). Save the key first.</p>';
$sideForms[] = $sideForm('test-research', 'test_research');

// The other services, so switching later is an informed choice, not a
// research project. Only Tavily is connected today.
echo '<details style="margin:8px 0 12px"><summary>Other services it could use in future</summary>'
   . '<p class="hint">Not connected yet. Switching to one of these means adding its connection to the platform; '
   . 'the research itself stays the same. Prices were checked in September 2026 — confirm before switching.</p>'
   . '<div class="table-wrap"><table class="table"><thead><tr><th>Service</th><th>Cost</th><th>Notes</th></tr></thead><tbody>';
foreach (ResearchCatalogue::PROVIDERS as $rsKey => $rsMeta) {
    if ($rsMeta['available']) {
        continue;
    }
    echo '<tr><td>' . e((string)$rsMeta['label']) . '</td><td>' . e((string)$rsMeta['cost']) . '</td>'
       . '<td>' . e((string)$rsMeta['note']) . '</td></tr>';
}
foreach (ResearchCatalogue::RETIRED as $rsName => $rsWhy) {
    echo '<tr><td>' . e($rsName) . '</td><td>—</td><td>Not possible: ' . e($rsWhy) . '</td></tr>';
}
echo '</tbody></table></div></details>' . admin_card_close();

// ---- Scheduled tasks and how long data is kept ----
echo admin_card_open('set-tasks', 'Scheduled tasks and data kept', 'Two jobs the server runs by itself. Add both '
        . 'once in cPanel under Cron Jobs.')
   . '<div class="cron-grid">'
   . '<div><h3>Every 5 minutes</h3><pre>' . e('php ' . APP_ROOT . '/cron/whatsapp.php') . '</pre>'
   . '<p class="hint">WhatsApp messages and follow-ups, company research, prospecting, prospect emails, and '
   . 'answers the AI was too busy to give straight away.</p></div>'
   . '<div><h3>Once an hour</h3><pre>' . e('php ' . APP_ROOT . '/cron/tasks.php') . '</pre>'
   . '<p class="hint">Emails still waiting, new registrations, deleting data past its time, and a back-up run of '
   . 'the five-minute work.</p></div></div>'
   . '<h3 class="set-sub">How long data is kept</h3>'
   . '<div class="table-wrap"><table class="table"><thead><tr><th>What</th><th>Kept for</th></tr></thead><tbody>'
   . '<tr><td>Visitor IP addresses</td><td>' . (int)cfg('retention.raw_ip_days', 365) . ' days</td></tr>'
   . '<tr><td>Conversations and messages</td><td>' . (int)cfg('retention.conversation_days', 730) . ' days</td></tr>'
   . '<tr><td>Leads</td><td>' . ((int)cfg('retention.lead_days', 0) === 0 ? 'Kept indefinitely' : (int)cfg('retention.lead_days') . ' days') . '</td></tr>'
   . '<tr><td>Audit trail</td><td>' . (int)cfg('retention.audit_days', 1095) . ' days</td></tr>'
   . '<tr><td>Error logs</td><td>' . (int)cfg('retention.error_log_days', 90) . ' days</td></tr>'
   . '</tbody></table></div>'
   . '<p class="hint">These periods are set in config/config.php on the server.</p>'
   . admin_card_close();

// ---- Country lookup and security ----
echo admin_card_open('set-other', 'Country lookup and security', 'Rarely changed.')
   . f_text('geo_ipinfo_token', 'ipinfo.io token', $has('geo.ipinfo.token') ? $secretPlaceholder : '',
        ['type' => 'password', 'hint' => 'Only needed if you chose ipinfo.io for country lookups. The internet '
            . 'address is only ever used when nothing better is known and a price must be shown.'])
   . f_check('security_trust_proxy', 'This site sits behind Cloudflare or another proxy',
        $val('security.trust_proxy') === '1',
        'Only switch this on if it is true. It tells the system to read the visitor\'s real address from the proxy header, which an attacker could otherwise forge.')
   . admin_card_close();

echo '<div class="save-bar"><span class="save-note">Saves everything on this page. Test buttons do not save.</span>'
   . '<button class="btn" type="submit">Save settings</button></div></form>';

// The small forms the test and action buttons above belong to.
echo implode('', $sideForms);

admin_layout_end();
