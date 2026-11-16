<?php
declare(strict_types=1);

/**
 * Agents: one per exhibition website. Also where the embed code lives.
 */

require __DIR__ . '/_admin.php';

$user = admin_require_role('admin');

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = isset($_GET['new']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'test_mail') {
    Security::requireCsrf();
    $testId    = (int)($_POST['id'] ?? 0);
    $testAgent = db()->one('SELECT * FROM agents WHERE id = ?', [$testId]);
    $testTo    = Validator::email((string)($_POST['test_to'] ?? ''));
    if ($testAgent === null || $testTo === null) {
        admin_flash('Enter an email address to send the test to.', 'error');
    } elseif (($why = MailProfile::problem($testAgent)) !== null) {
        admin_flash($why . ' Save the email settings first.', 'error');
    } else {
        $sent = Mailer::sendWith(MailProfile::forAgent($testAgent), $testTo,
            'Test email from ' . (string)$testAgent['name'],
            "This is a test from the AI Sales Agent platform.\n\nIf you are reading it, "
            . (string)$testAgent['name'] . "'s prospect emails can be sent from "
            . MailProfile::sender($testAgent) . '.');
        Audit::log('mail_profile_tested', $testId, 'agent', $testId, $sent['ok'] ? 'working' : 'failed');
        $sent['ok']
            ? admin_flash('Sent. Check ' . $testTo . ' — including the spam folder — to see how it arrives.')
            : admin_flash('The mail server did not accept it: ' . (string)$sent['error']
                . '. Check the server name, port, encryption, user name and password.', 'error');
    }
    admin_redirect('admin/agents.php?edit=' . $testId);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $id     = (int)($_POST['id'] ?? 0);
    $errors = [];
    foreach (MailProfile::check($_POST) as $mailProblem) {
        $errors[] = $mailProblem;
    }

    $name = Validator::text($_POST['name'] ?? null, 160);
    $slug = Validator::slug($_POST['slug'] ?? null);

    if ($name === null) { $errors[] = 'Enter the exhibition name.'; }
    if ($slug === null) { $errors[] = 'The short code must be lowercase letters, numbers or hyphens.'; }

    $hosts = [];
    foreach (preg_split('/[\r\n,]+/', (string)($_POST['allowed_domains'] ?? '')) ?: [] as $lineRaw) {
        $h = Validator::hostname($lineRaw);
        if ($h !== null) { $hosts[] = $h; }
    }
    if (!$hosts) { $errors[] = 'Add at least one website address, otherwise every request is refused.'; }

    $typedNumber = trim((string)($_POST['whatsapp_number'] ?? ''));
    if ($typedNumber !== '' && Phone::fromInternational($typedNumber) === null) {
        $errors[] = 'That WhatsApp number could not be read. Include the country code, '
                  . 'for example 923008618200 or +92 300 8618200.';
    }
    if (!empty($_POST['whatsapp_enabled']) && $typedNumber === '') {
        $errors[] = 'WhatsApp cannot be switched on without a number to send from.';
    }
    foreach (SalesReps::REGIONS as $repRegion) {
        foreach (SalesReps::check($repRegion, (array)($_POST['rep'][$repRegion] ?? [])) as $repProblem) {
            $errors[] = $repProblem;
        }
    }
    if (!empty($_POST['whatsapp_enabled']) && trim((string)($_POST['whatsapp_api_key'] ?? '')) === '') {
        $errors[] = 'WhatsApp cannot be switched on without the API key for this number.';
    }
    if (admin_time_of_day($_POST['whatsapp_send_from'] ?? null, '10:00:00')
        === admin_time_of_day($_POST['whatsapp_send_to'] ?? null, '20:00:00')) {
        $errors[] = 'The sending hours start and end at the same time, which would mean no first messages '
                  . 'are ever sent. Choose two different times.';
    }

    // A bare domain covers its subdomains, but "www.example.com" does not
    // cover "example.com". Listing only the www form is an easy mistake and
    // the symptom is baffling: the chat appears but refuses to answer for
    // anyone who reached the site without the www.
    foreach ($hosts as $h) {
        if (str_starts_with($h, 'www.')) {
            $bare = substr($h, 4);
            if ($bare !== '' && !in_array($bare, $hosts, true)) {
                admin_flash(
                    'You allowed ' . $h . ' but not ' . $bare . '. Visitors who reach the site '
                    . 'without the "www." will see the chat but get no answers. Add ' . $bare
                    . ' on its own line too if that address works.',
                    'warn'
                );
            }
        }
    }

    if ($slug !== null) {
        $clash = db()->value('SELECT id FROM agents WHERE slug = ? AND id <> ?', [$slug, $id]);
        if ($clash) { $errors[] = 'Another agent already uses that short code.'; }
    }

    $values = [
        'name'            => $name,
        'slug'            => $slug,
        'status'          => Validator::inList($_POST['status'] ?? null, ['active', 'disabled'], 'active'),
        'website_url'     => Validator::url($_POST['website_url'] ?? null),
        'allowed_domains' => implode("\n", array_unique($hosts)),
        'persona'         => Validator::paragraph($_POST['persona'] ?? null, 6000),
        'sales_objectives' => Validator::paragraph($_POST['sales_objectives'] ?? null, 6000),
        'sales_rules'     => Validator::paragraph($_POST['sales_rules'] ?? null, 6000),
        'exhibition_sectors' => Validator::paragraph($_POST['exhibition_sectors'] ?? null, 4000),
        'system_prompt_extra' => Validator::paragraph($_POST['system_prompt_extra'] ?? null, 4000),
        'ai_disclosure_text' => Validator::text($_POST['ai_disclosure_text'] ?? null, 400),
        'privacy_url'     => Validator::url($_POST['privacy_url'] ?? null),
        'notify_email'    => Validator::text($_POST['notify_email'] ?? null, 500),
        'notify_email_local' => Validator::text($_POST['notify_email_local'] ?? null, 500),
        'notify_timezone' => Validator::text($_POST['notify_timezone'] ?? null, 64) ?: 'Asia/Karachi',
        'whatsapp_number' => Phone::fromInternational($_POST['whatsapp_number'] ?? null),
        'whatsapp_enabled' => !empty($_POST['whatsapp_enabled']) ? 1 : 0,
        'whatsapp_first_delay_minutes' => max(0, min(1440, (int)($_POST['whatsapp_first_delay_minutes'] ?? 10))),
        'whatsapp_daily_cap' => max(1, min(2000, (int)($_POST['whatsapp_daily_cap'] ?? 40))),
        // A time box can be left empty or typed into, so it is read rather
        // than trusted. An unreadable value falls back to a civil hour.
        'whatsapp_send_from' => admin_time_of_day($_POST['whatsapp_send_from'] ?? null, '10:00:00'),
        'whatsapp_send_to'   => admin_time_of_day($_POST['whatsapp_send_to'] ?? null, '20:00:00'),
        'whatsapp_approve_first' => !empty($_POST['whatsapp_approve_first']) ? 1 : 0,
        'chat_intro_form'  => !empty($_POST['chat_intro_form']) ? 1 : 0,
        'whatsapp_followups' => max(0, min(WhatsAppFollowUp::MAX_IN_ROW, (int)($_POST['whatsapp_followups'] ?? 1))),
        'llm_provider'    => Validator::inList($_POST['llm_provider'] ?? null, array_keys(LlmCatalogue::all()), 'anthropic'),
        'llm_model'       => Validator::text($_POST['llm_model'] ?? null, 100),
        'llm_fallback_provider' => Validator::inList($_POST['llm_fallback_provider'] ?? null, array_keys(LlmCatalogue::all()), null),
        'llm_fallback_model'    => Validator::text($_POST['llm_fallback_model'] ?? null, 100),
        'geo_provider'    => Validator::inList($_POST['geo_provider'] ?? null, array_keys(GeoProviderFactory::available()), 'ipwhois'),
        'readiness_min_signals' => max(1, (int)($_POST['readiness_min_signals'] ?? 2)),
        'max_messages_per_conversation' => max(4, (int)($_POST['max_messages_per_conversation'] ?? 40)),
        'max_input_chars' => max(80, (int)($_POST['max_input_chars'] ?? 1200)),
        'rate_per_ip_per_min' => max(1, (int)($_POST['rate_per_ip_per_min'] ?? 12)),
        'notify_on_lead'   => !empty($_POST['notify_on_lead']) ? 1 : 0,
        'notify_on_handoff' => !empty($_POST['notify_on_handoff']) ? 1 : 0,
    ];

    if (Migrations::columnExists('agents', 'mail_from_email')) {
        $values += [
            'mail_from_name'  => Validator::text($_POST['mail_from_name'] ?? null, 120),
            'mail_from_email' => Validator::email((string)($_POST['mail_from_email'] ?? '')),
            'mail_reply_to'   => Validator::email((string)($_POST['mail_reply_to'] ?? '')),
            'mail_smtp_host'  => Validator::text($_POST['mail_smtp_host'] ?? null, 190),
            'mail_smtp_port'  => max(1, min(65535, (int)($_POST['mail_smtp_port'] ?? 587))),
            'mail_smtp_encryption' => Validator::inList($_POST['mail_smtp_encryption'] ?? null,
                array_keys(MailProfile::ENCRYPTIONS), 'tls'),
            'mail_smtp_username' => Validator::text($_POST['mail_smtp_username'] ?? null, 190),
            'mail_postal_address' => Validator::text($_POST['mail_postal_address'] ?? null, 400),
            'prospect_emails_per_day' => max(1, min(500, (int)($_POST['prospect_emails_per_day'] ?? 30))),
            'prospect_email_interval_minutes' => max(1, min(720, (int)($_POST['prospect_email_interval_minutes'] ?? 10))),
            'prospect_research_per_day' => max(1, min(200, (int)($_POST['prospect_research_per_day'] ?? 20))),
            'prospect_research_gap_minutes' => max(1, min(720, (int)($_POST['prospect_research_gap_minutes'] ?? 5))),
        ];
    }

    if (Migrations::columnExists('agents', 'llm_background_model')) {
        $values['llm_background_provider'] = Validator::inList($_POST['llm_background_provider'] ?? null, array_keys(LlmCatalogue::all()), null);
        $values['llm_background_model']    = Validator::text($_POST['llm_background_model'] ?? null, 100);
        if ($values['llm_background_provider'] !== null && !LlmCatalogue::isConfigured((string)$values['llm_background_provider'])) {
            admin_flash('The background provider has no key saved under Settings, so background work will use the '
                . 'main model until one is added.', 'warn');
        }
    }

    // Added by the October upgrade; saved only once the upgrade has run.
    if (Migrations::columnExists('agents', 'whatsapp_followup_days')) {
        $values['whatsapp_followup_days'] = max(1, min(14, (int)($_POST['whatsapp_followup_days'] ?? 3)));
        $endsOn = trim((string)($_POST['event_ends_on'] ?? ''));
        $values['event_ends_on'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) && strtotime($endsOn) !== false
            ? $endsOn : null;
    }

    // Show dates and venue for the event blog; saved once that upgrade has run.
    if (Migrations::columnExists('agents', 'event_starts_on')) {
        $startsOn = trim((string)($_POST['event_starts_on'] ?? ''));
        $values['event_starts_on'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) && strtotime($startsOn) !== false ? $startsOn : null;
        $values['event_tagline']  = Validator::text($_POST['event_tagline'] ?? null, 300);
        $values['event_venue']    = Validator::text($_POST['event_venue'] ?? null, 200);
        $values['event_city']     = Validator::text($_POST['event_city'] ?? null, 120);
        $values['event_country']  = Validator::text($_POST['event_country'] ?? null, 96);
        $values['register_url']   = Validator::url($_POST['register_url'] ?? null);
        $values['book_stand_url'] = Validator::url($_POST['book_stand_url'] ?? null);
        if (($values['event_ends_on'] ?? null) !== null && $values['event_starts_on'] !== null
            && $values['event_ends_on'] < $values['event_starts_on']) {
            $errors[] = 'The last day of the exhibition is before the first.';
        }
    }

    // Lead score and website reading; saved once that upgrade has run.
    if (Migrations::columnExists('agents', 'score_weights')) {
        $weights = [];
        foreach (Qualification::DEFAULT_WEIGHTS as $wKey => $wDefault) {
            $weights[$wKey] = max(0, min(100, (int)($_POST['weight_' . $wKey] ?? $wDefault)));
        }
        $values['score_weights']    = json_encode($weights);
        $values['target_countries'] = Validator::paragraph($_POST['target_countries'] ?? null, 3000);
    }
    if (Migrations::columnExists('agents', 'site_pages_enabled')) {
        $values['site_pages_enabled'] = !empty($_POST['site_pages_enabled']) ? 1 : 0;
        $values['site_sitemap_url']   = Validator::url($_POST['site_sitemap_url'] ?? null);
    }
    // Personal chat links (13 Nov 2026); saved once that upgrade has run.
    if (Migrations::columnExists('agents', 'chat_link_quote')) {
        foreach (array_keys(PersonalLink::KINDS) as $kind) {
            $values['chat_link_' . $kind] = !empty($_POST['chat_link_' . $kind]) ? 1 : 0;
        }
    }
    // Quotations (plan part 3); saved once that upgrade has run.
    $quoteLogoNote = null;
    if (Migrations::columnExists('agents', 'quote_enabled')) {
        $values['quote_enabled']    = !empty($_POST['quote_enabled']) ? 1 : 0;
        $values['quote_prefix']     = mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['quote_prefix'] ?? '')) ?? ''), 0, 12) ?: null;
        $values['quote_valid_days'] = max(1, min(365, (int)($_POST['quote_valid_days'] ?? 30)));
        $values['quote_terms']      = Validator::paragraph($_POST['quote_terms'] ?? null, 2000);
        $values['quote_footer']     = Validator::paragraph($_POST['quote_footer'] ?? null, 500);

        $logoAgent = (int)($_POST['id'] ?? 0);
        $upload = $_FILES['quote_logo_file'] ?? null;
        if ($logoAgent > 0 && !empty($_POST['quote_logo_remove'])) {
            $values['quote_logo'] = null;
        }
        if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $tmp  = (string)($upload['tmp_name'] ?? '');
            $info = (int)$upload['error'] === UPLOAD_ERR_OK && is_uploaded_file($tmp) ? @getimagesize($tmp) : false;
            if ($logoAgent <= 0) {
                $quoteLogoNote = 'Save the exhibition first, then add the quotation logo.';
            } elseif ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $quoteLogoNote = 'The quotation logo was not saved: it must be a PNG or JPG picture.';
            } elseif ((int)$upload['size'] > 1024 * 1024) {
                $quoteLogoNote = 'The quotation logo was not saved: it is larger than 1 MB.';
            } else {
                $dir = Quotation::logoDir();
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
                $name = 'agent-' . $logoAgent . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
                $dest = $dir . '/' . $name;
                // A PNG saved in a way the PDF cannot place (interlaced, 16-bit)
                // is re-saved plainly when the server can do it.
                if (@move_uploaded_file($tmp, $dest)) {
                    if (!PdfWriter::canPlace($dest) && function_exists('imagecreatefromstring')) {
                        $im = @imagecreatefromstring((string)file_get_contents($dest));
                        if ($im !== false) {
                            imagesavealpha($im, true);
                            imageinterlace($im, false);
                            $info[2] === IMAGETYPE_PNG ? imagepng($im, $dest) : imagejpeg($im, $dest, 90);
                        }
                    }
                    if (PdfWriter::canPlace($dest)) {
                        $values['quote_logo'] = $name;
                    } else {
                        @unlink($dest);
                        $quoteLogoNote = 'The quotation logo was not saved: please save it as an ordinary PNG or JPG and try again.';
                    }
                } else {
                    $quoteLogoNote = 'The quotation logo could not be stored. Check the storage folder is writable.';
                }
            }
        }
    }

    // An agent with no model cannot answer anything, so fall back to the
    // provider's usual one rather than saving something unusable.
    if (($values['llm_model'] ?? '') === '' || $values['llm_model'] === null) {
        $values['llm_model'] = LlmCatalogue::defaultModel((string)$values['llm_provider']);
    }
    if (($values['llm_fallback_provider'] ?? null) !== null
        && ($values['llm_fallback_model'] ?? '') === '') {
        $values['llm_fallback_model'] = LlmCatalogue::defaultModel((string)$values['llm_fallback_provider']);
    }

    // Warn rather than block: the key may be added straight afterwards.
    $providerProblem = LlmCatalogue::configurationProblem((string)$values['llm_provider']);
    if ($providerProblem !== null) {
        admin_flash(
            $providerProblem . ' Add it under Settings, or the assistant will show its offline message.',
            'warn'
        );
    }

    if ($quoteLogoNote !== null) {
        admin_flash($quoteLogoNote, 'warn');
    }

    if ($errors) {
        foreach ($errors as $e) { admin_flash($e, 'error'); }
        admin_redirect('admin/agents.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }

    if ($id > 0) {
        $before = db()->one('SELECT * FROM agents WHERE id = ?', [$id]);
        db()->update('agents', $values + ['config_version' => (int)$before['config_version'] + 1], 'id = ?', [$id]);
        Audit::logChanges('agent_updated', $id, 'agent', $id, $before, $values, array_keys($values));
        admin_flash('Agent saved.');
    } else {
        $id = db()->insert('agents', $values);
        Fallbacks::seed($id);
        Audit::log('agent_created', $id, 'agent', $id, 'Created by ' . (string)$user['username']);
        admin_flash('Agent created. Add your content, then paste the embed code into the website.');
    }

    // Only when the form actually carried the rep boxes, so nothing that
    // posts here without them can blank the reps.
    if (isset($_POST['rep']) && is_array($_POST['rep'])) {
        foreach (SalesReps::REGIONS as $repRegion) {
            SalesReps::save($id, $repRegion, (array)($_POST['rep'][$repRegion] ?? []));
        }
        Audit::log('sales_reps_saved', $id, 'agent', $id, 'Sales rep details saved');
    }

    // The WhatsApp key, kept apart from the other fields so it is never
    // written to the audit trail or shown back. Left untouched means "keep".
    $typedKey  = trim((string)($_POST['whatsapp_api_key'] ?? ''));
    $numberNow = Phone::fromInternational($_POST['whatsapp_number'] ?? null);
    $numberWas = isset($before) ? (string)($before['whatsapp_number'] ?? '') : '';

    if ($typedKey !== '' && $typedKey !== '__unchanged__') {
        MessagingProviderFactory::saveKey($id, $typedKey);
        Audit::log('whatsapp_key_changed', $id, 'agent', $id, 'WhatsApp API key saved (hidden)');
    } elseif ($numberWas !== '' && (string)$numberNow !== $numberWas) {
        // A different number is a different phone, and the old key belongs to
        // the old phone. Keeping it would send from the wrong number.
        MessagingProviderFactory::saveKey($id, '');
        Audit::log('whatsapp_key_changed', $id, 'agent', $id, 'WhatsApp API key cleared: number changed');
        admin_flash('The WhatsApp number changed, so the old API key was removed — it belongs to the old '
            . 'phone. Enter the key for the new number.', 'warn');
    }

    // The mail server password, like the WhatsApp key: encrypted, never
    // shown back, never in the audit trail. Untouched means "keep".
    $typedPass = (string)($_POST['mail_smtp_password'] ?? '');
    if (Migrations::columnExists('agents', 'mail_smtp_password')) {
        $serverChanged = isset($before) && (
            (string)($before['mail_smtp_host'] ?? '') !== (string)($values['mail_smtp_host'] ?? '')
            || (int)($before['mail_smtp_port'] ?? 587) !== (int)($values['mail_smtp_port'] ?? 587)
            || (string)($before['mail_smtp_encryption'] ?? 'tls') !== (string)($values['mail_smtp_encryption'] ?? 'tls')
            || (string)($before['mail_smtp_username'] ?? '') !== (string)($values['mail_smtp_username'] ?? ''));
        if ($typedPass !== '' && $typedPass !== '__unchanged__') {
            MailProfile::savePassword($id, $typedPass);
            Audit::log('mail_password_changed', $id, 'agent', $id, 'Mail server password saved (hidden)');
        } elseif ($serverChanged && trim((string)($before['mail_smtp_password'] ?? '')) !== '') {
            // The saved password belongs to the old server settings. Sending
            // it to a newly typed server — perhaps mistyped — would give it away.
            MailProfile::savePassword($id, '');
            Audit::log('mail_password_changed', $id, 'agent', $id, 'Mail server password cleared: server settings changed');
            admin_flash('The mail server settings changed, so the saved password was removed. Enter the password '
                . 'again to use the new settings.', 'warn');
        }
    }

    $_SESSION['agent_id'] = $id;
    admin_redirect('admin/agents.php?edit=' . $id);
}

// ---- Form -----------------------------------------------------------
if ($editId > 0 || $isNew) {
    $a = $editId > 0 ? db()->one('SELECT * FROM agents WHERE id = ?', [$editId]) : [];

    // A new agent starts from the same working defaults the installer uses,
    // so the operator edits sensible wording instead of filling in four blank
    // boxes. Routing and timezone are copied from an existing agent, since a
    // second exhibition almost always goes to the same sales desks.
    if ($editId === 0) {
        $sibling = db()->one("SELECT * FROM agents WHERE status <> 'deleted' ORDER BY id ASC LIMIT 1") ?: [];
        $a = [
            'persona'            => AgentDefaults::PERSONA,
            'sales_objectives'   => AgentDefaults::OBJECTIVES,
            'sales_rules'        => AgentDefaults::SALES_RULES,
            'ai_disclosure_text' => AgentDefaults::DISCLOSURE,
            'notify_email'       => (string)($sibling['notify_email'] ?? ''),
            'notify_email_local' => (string)($sibling['notify_email_local'] ?? ''),
            'notify_timezone'    => (string)($sibling['notify_timezone'] ?? 'Asia/Karachi'),
            'privacy_url'        => (string)($sibling['privacy_url'] ?? ''),
            'llm_provider'       => (string)($sibling['llm_provider'] ?? 'anthropic'),
            'llm_model'          => (string)($sibling['llm_model'] ?? ''),
            'llm_fallback_provider' => (string)($sibling['llm_fallback_provider'] ?? ''),
            'llm_fallback_model'    => (string)($sibling['llm_fallback_model'] ?? ''),
            'geo_provider'       => (string)($sibling['geo_provider'] ?? 'ipwhois'),
        ];
    }

    if ($editId > 0 && !$a) {
        admin_layout_start('Not found');
        echo '<div class="alert alert-error"><p>No such agent.</p></div>';
        admin_layout_end();
        exit;
    }

    $v = static fn(string $k, string $d = '') => (string)($a[$k] ?? $d);

    admin_layout_start($editId > 0 ? 'Edit agent' : 'New agent', ['Back' => url('admin/agents.php')]);

    if ($editId === 0) {
        echo '<div class="card"><h2>Before you start</h2>'
           . '<p class="lede">This creates a second, completely separate assistant — its own prices, '
           . 'its own knowledge, its own leads. Nothing is shared with your existing events, so an '
           . 'exhibitor asking one about the other gets nothing.</p>'
           . '<p class="hint">The wording below is already filled in with the same starting text your '
           . 'first agent uses. Edit anything that does not fit this event. After you save, the new '
           . 'assistant has no content yet, so it will say it cannot help until you add some — the '
           . 'quickest way is Conversation with AI, where you upload that event\'s brochures and '
           . 'rate card.</p></div>';
    }

    if ($editId > 0) {
        $snippet = '<script src="' . url('widget/widget.js') . '" data-agent="' . $v('slug') . '"></script>';
        echo '<div class="card"><h2>Embed code</h2>'
           . '<p class="lede">Paste this once into your exhibition website, just before the closing '
           . '&lt;/body&gt; tag. It works on any page and needs nothing else installed.</p>'
           . '<pre>' . e($snippet) . '</pre>'
           . '<p class="hint">Optional settings you can add to the tag: '
           . '<code>data-position="left"</code> to move the button, '
           . '<code>data-label="Talk to us"</code> to change the wording, '
           . '<code>data-auto-open="true"</code> to open it automatically.</p></div>';
    }

    echo '<form method="post" class="settings-form" enctype="multipart/form-data">' . Security::csrfField()
       . '<input type="hidden" name="id" value="' . $editId . '">'

       . admin_settings_nav($a)
       . admin_card_open('set-event', 'The exhibition', 'Its name, its website, and where the chat is allowed to appear.')
       . f_text('name', 'Exhibition name', $v('name'), ['required' => true])
       . f_text('slug', 'Short code', $v('slug'), ['required' => true,
            'hint' => 'Used in the embed code. Lowercase letters, numbers and hyphens. Changing it breaks any embed already installed.'])
       . f_text('website_url', 'Website address', $v('website_url'), ['type' => 'url'])
       . f_area('allowed_domains', 'Websites allowed to show the chat', $v('allowed_domains'), ['rows' => 4,
            'hint' => 'One per line, for example www.buildasia.net. Requests from anywhere else are refused, so nobody can put your assistant on their own site.'])
       . f_select('status', 'Status', ['active' => 'Active', 'disabled' => 'Disabled'], $v('status', 'active'),
            ['hint' => 'Disabling stops the assistant answering anywhere immediately.'])
       . admin_card_close()
       . admin_card_open('set-show', 'Show dates and venue', 'Used by this exhibition\'s blog for its countdown, buttons and Google listing.')
       . '<p class="hint" style="margin:0 0 12px">The event blog reads these once a day. They do not change what the assistant says: '
       . 'its answers still come from approved knowledge only.</p><div class="field-row">'
       . f_text('event_starts_on', 'First day of the exhibition', $v('event_starts_on'), ['type' => 'date'])
       . f_text('event_ends_on', 'Last day of the exhibition', $v('event_ends_on'), ['type' => 'date',
            'hint' => 'No WhatsApp follow-ups are sent after this day.'])
       . '</div>'
       . f_text('event_tagline', 'One-line description', $v('event_tagline'), ['max' => 300,
            'hint' => 'For example: Pakistan\'s largest construction and building materials exhibition.'])
       . '<div class="field-row">'
       . f_text('event_venue', 'Venue', $v('event_venue'), ['max' => 200])
       . f_text('event_city', 'City', $v('event_city'), ['max' => 120])
       . f_text('event_country', 'Country', $v('event_country'), ['max' => 96])
       . '</div><div class="field-row">'
       . f_text('register_url', '"Register to visit" page', $v('register_url'), ['type' => 'url'])
       . f_text('book_stand_url', '"Book a stand" page', $v('book_stand_url'), ['type' => 'url'])
       . '</div>'
       . admin_card_close()
       . admin_card_open('set-covers', 'What this exhibition covers', 'The sectors on show — this decides who is asked about exhibiting.')
       . f_area('exhibition_sectors', 'Sectors shown at this event', $v('exhibition_sectors'), ['rows' => 8,
            'hint' => 'One per line. This decides who gets asked about exhibiting. A cement '
                    . 'supplier belongs at a construction show and not at a medical one, and '
                    . 'without this list the assistant cannot tell the difference — so it will '
                    . 'send everyone a general welcome instead of asking anyone to exhibit. '
                    . 'Write them as a visitor would describe their business: construction '
                    . 'machinery, building materials, cement, HVAC, sanitary ware.'])
       . admin_card_close()
       . (Migrations::columnExists('agents', 'score_weights') ? (static function () use ($v, $a): string {
            $w = Qualification::weights((array)$a);
            $labels = ['sector_match' => 'Products match the sectors', 'sells_abroad' => 'Sells abroad',
                       'target_country' => 'From a target country', 'decision_maker' => 'Contact is a decision maker',
                       'completeness' => 'How complete the record is'];
            $out = admin_card_open('set-score', 'Lead score', 'How each possible exhibitor is scored: Hot, Warm, Nurture or Low.')
                 . '<p class="hint">Points for each part. The total is turned into a score out of 100: 90 and above is Hot, '
                 . '70 to 89 Warm, 50 to 69 Nurture, below 50 Low.</p><div class="field-row">';
            foreach ($labels as $key => $label) {
                $out .= f_text('weight_' . $key, $label, (string)$w[$key], ['type' => 'number']);
            }
            return $out . '</div>'
                 . f_area('target_countries', 'Target countries', $v('target_countries'), ['rows' => 6,
                    'hint' => 'One country per line, as visitors choose it on the chat card (for example China, Germany, '
                            . 'Türkiye). Add "| 2" after a second-priority country, for example "France | 2"; it then counts '
                            . 'for 60 per cent. Left empty, any company from outside Pakistan counts for 60 per cent.'])
                 . admin_card_close();
         })() : '')
       . (Migrations::columnExists('agents', 'site_pages_enabled') ? admin_card_open('set-website', 'Website answers',
                'The assistant reads this exhibition\'s website once a week, to answer questions about it and give the right link.')
            . f_check('site_pages_enabled', 'Read the website every week', (int)($a['site_pages_enabled'] ?? 1) === 1,
                'The pages it knows are listed under Content → Website pages.')
            . f_text('site_sitemap_url', 'Sitemap address', $v('site_sitemap_url'), ['type' => 'url',
                'hint' => 'Leave empty to use the website address followed by /sitemap.xml.'])
            . admin_card_close() : '')
       . (Migrations::columnExists('agents', 'quote_enabled') ? (static function () use ($v, $a): string {
            $set  = Quotation::settings($a ?: ['name' => (string)($_POST['name'] ?? '')]);
            $why  = $a ? Quotation::whyNot($a) : null;
            $out  = admin_card_open('set-quotations', 'Quotations',
                    'A one-page PDF with figures from your price list, emailed when a visitor asks the chat for a quotation.')
                  . f_check('quote_enabled', 'Let the chat email quotations by itself', (int)($a['quote_enabled'] ?? 0) === 1,
                        'Never for a company in Pakistan, and only when the visitor has given their name, company, email, country, and one '
                        . 'stand option with a size that can be booked, or one sponsorship tier. At most ' . Quotation::MAX_PER_CONVERSATION
                        . ' per conversation. Your team gets the same PDF with the lead email.');
            if ($a && (int)($a['quote_enabled'] ?? 0) === 1 && $why !== null) {
                $out .= '<p class="hint" style="color:var(--danger)">Not working yet: ' . e($why) . '</p>';
            } elseif ($a && (int)($a['quote_enabled'] ?? 0) === 1) {
                $out .= '<p class="hint"><span class="pill pill-ok">On</span> The chat offers a quotation after showing a price, '
                      . 'and when a visitor asks how to go ahead.</p>';
            } elseif ($a) {
                $out .= '<p class="hint"><span class="pill">Off</span> The chat neither offers nor sends quotations. '
                      . 'Tick the box above and save to switch them on.</p>';
            }
            $out .= '<div class="grid grid-2">'
                  . f_text('quote_prefix', 'Number prefix', $v('quote_prefix'), ['max' => 12,
                        'hint' => 'Numbers look like ' . e($set['prefix']) . '-Q-0001. Leave empty to use ' . e($set['prefix']) . '.'])
                  . f_text('quote_valid_days', 'Valid for (days)', (string)($a['quote_valid_days'] ?? 30), ['type' => 'number'])
                  . '</div>'
                  . f_area('quote_terms', 'Terms printed on the quotation', $v('quote_terms'), ['rows' => 5,
                        'hint' => 'One term per line. Leave empty for the standard four terms. {days} becomes the number of days above. Do not put payment details here; they go on the invoice.'])
                  . f_area('quote_footer', 'Footer: organiser, address, phone, website', $v('quote_footer'), ['rows' => 3,
                        'hint' => 'First line is the company name, shown next to the Ecommerce Gateway logo. Leave empty for "' . Quotation::DEFAULT_FOOTER . '".'])
                  . '<div class="field"><label for="f_quote_logo_file">Exhibition logo at the top of the quotation</label>'
                  . '<input type="file" id="f_quote_logo_file" name="quote_logo_file" accept="image/png,image/jpeg">'
                  . '<p class="hint">' . ($set['logo'] !== null ? 'A logo is saved. Choose a file only to replace it. ' : 'No logo yet. ')
                  . 'PNG or JPG, up to 1 MB. A wide logo on a white or clear background works best.</p></div>'
                  . ($set['logo'] !== null ? f_check('quote_logo_remove', 'Remove the saved logo', false) : '');
            return $out . admin_card_close();
        })() : '')
       . (Migrations::columnExists('agents', 'chat_link_quote') ? (static function () use ($a): string {
            $out = admin_card_open('set-links', 'Personal chat links',
                    '"Let\'s discuss more — click here": opens the chat on your website as that person, skipping the first questions.')
                 . '<p class="hint">The link opens the exhibition\'s website address (above), so the chat must be on that page. '
                 . 'It works until the last day of the exhibition, then opens the normal chat.</p>';
            foreach (PersonalLink::KINDS as $kind => $label) {
                $out .= f_check('chat_link_' . $kind, 'Add it to ' . lcfirst($label), (int)($a['chat_link_' . $kind] ?? 1) === 1);
            }
            return $out . admin_card_close();
        })() : '')
       . admin_card_open('set-voice', 'How the assistant talks', 'Who it is, what it is trying to achieve, and how it should sell.')
       . f_area('persona', 'Who the assistant is', $v('persona'), ['rows' => 5])
       . f_area('sales_objectives', 'What it is trying to achieve', $v('sales_objectives'), ['rows' => 7])
       . f_area('sales_rules', 'How it should sell', $v('sales_rules'), ['rows' => 8])
       . f_area('system_prompt_extra', 'Anything else it should know', $v('system_prompt_extra'), ['rows' => 4,
            'hint' => 'Extra instructions. The safety rules about prices, tax and bookings cannot be edited here and always apply.'])
       . admin_card_close()
       . admin_card_open('set-chat', 'Website chat', 'What happens when someone opens the chat on your website.')
       . f_check('chat_intro_form', 'Ask four short questions when the chat opens',
            (int)($a['chat_intro_form'] ?? 1) === 1,
            'First name, company, the country the company is based in, and whether they are interested in '
            . 'exhibiting or visiting. Visitors can skip it. Those visiting are thanked and offered help; those '
            . 'exhibiting go straight into a conversation about exhibiting, with pricing for their country.')
       . admin_card_close()
       . admin_card_open('set-disclosure', 'Telling visitors it is AI', 'The line shown at the top of the chat, and your privacy notice.')
       . f_text('ai_disclosure_text', 'Disclosure wording', $v('ai_disclosure_text'), ['max' => 400,
            'hint' => 'Shown at the top of the chat panel at all times.'])
       . f_text('privacy_url', 'Privacy notice address', $v('privacy_url'), ['type' => 'url',
            'hint' => 'Optional. Linked next to the disclosure.'])
       . admin_card_close()
       . admin_card_open('set-leads', 'Where leads are sent', 'Who is emailed when someone leaves their details or needs a person.')
       . f_text('notify_email', 'International enquiries', $v('notify_email'), ['max' => 500,
            'hint' => 'Separate several addresses with commas.'])
       . f_text('notify_email_local', 'Local and undetermined enquiries', $v('notify_email_local'), ['max' => 500,
            'hint' => 'Pakistan visitors, and anyone whose country could not be established, go here.'])
       . f_text('notify_timezone', 'Timezone for emails and screens', $v('notify_timezone', 'Asia/Karachi'), [
            'hint' => 'For example Asia/Karachi. All times are shown in this zone.'])
       . f_check('notify_on_lead', 'Email when contact details are captured', (int)($a['notify_on_lead'] ?? 1) === 1)
       . f_check('notify_on_handoff', 'Email when someone is passed to sales', (int)($a['notify_on_handoff'] ?? 1) === 1)
       . admin_card_close()
       . admin_reps_fieldset($editId > 0 ? $editId : 0)
       . admin_card_open('set-whatsapp', 'WhatsApp', 'The number messages come from, when they may go, and follow-ups.')
       . f_text('whatsapp_number', 'Number messages are sent from', $v('whatsapp_number'), ['max' => 24,
            'hint' => 'With the country code, for example 923008618200. Each exhibition sends from '
                    . 'its own number, so one being blocked never affects the others.'])
       . f_text('whatsapp_api_key', 'TextMeBot API key for this number',
            trim((string)($a['whatsapp_api_key'] ?? '')) !== '' ? '__unchanged__' : '', [
            'type' => 'password',
            'hint' => (trim((string)($a['whatsapp_api_key'] ?? '')) !== ''
                    ? 'A key is saved. Leave this untouched to keep it. '
                    : 'No key saved yet. ')
                    . 'Every WhatsApp number has its own key — use the one TextMeBot gave you when you '
                    . 'linked this number. It is stored encrypted and never shown again.'])
       . f_check('whatsapp_enabled', 'Send WhatsApp messages for this exhibition',
            (int)($a['whatsapp_enabled'] ?? 0) === 1,
            'Nothing is sent to anyone who has not given permission on the registration form, '
            . 'whether this is on or off.')
       . f_check('whatsapp_approve_first', 'Let me read each first message and follow-up before it goes',
            (int)($a['whatsapp_approve_first'] ?? 1) === 1,
            'Recommended for the first few weeks. Each first message and follow-up waits under WhatsApp until you '
            . 'approve it. Replies to people who have written to you are never held.')
       . '<div class="field-row">'
       . f_text('whatsapp_first_delay_minutes', 'Wait before the first message (minutes)',
            $v('whatsapp_first_delay_minutes', '10'), ['type' => 'number',
            'hint' => 'Long enough not to feel automated, short enough that they still remember registering.'])
       . f_text('whatsapp_daily_cap', 'Most messages per day', $v('whatsapp_daily_cap', '40'), ['type' => 'number',
            'hint' => 'Start low and raise it over a fortnight. A new number sending hundreds on its '
                    . 'first day looks exactly like the thing WhatsApp blocks.'])
       . '</div>'
       . '<div class="field-row">'
       . f_text('whatsapp_send_from', 'Do not send before',
            substr($v('whatsapp_send_from', '10:00:00'), 0, 5), ['type' => 'time',
            'hint' => 'In this exhibition\'s own timezone, set further up this page.'])
       . f_text('whatsapp_send_to', 'Do not send after',
            substr($v('whatsapp_send_to', '20:00:00'), 0, 5), ['type' => 'time',
            'hint' => 'A message at two in the morning is a complaint waiting to happen, and complaints '
                    . 'are what get a number blocked. Replies to someone who has written to you are '
                    . 'always answered, whatever the hour.'])
       . '</div>'
       . '<h3 class="set-sub">Follow-ups</h3>'
       . '<p class="hint" style="margin:0 0 10px">When someone goes quiet, the assistant can follow up — '
       . 'only with a likely exhibitor who never answered the first message, or with someone who was '
       . 'talking about exhibiting or sponsoring. Ordinary visitors are never chased. Follow-ups count '
       . 'towards the daily limit, keep to the sending hours, and wait for your approval if first messages do.</p>'
       . '<div class="field-row">'
       . f_select('whatsapp_followups', 'Follow-ups without an answer',
            ['0' => 'None — never follow up', '1' => 'One', '2' => 'Two', '3' => 'Three'],
            (string)max(0, min(3, (int)($a['whatsapp_followups'] ?? 1))), [
            'hint' => 'How many in a row, if they do not reply. When they write back, it starts again. '
                    . 'Nobody ever gets more than ' . WhatsAppFollowUp::TOTAL_PER_PERSON . ' in all.'])
       . f_text('whatsapp_followup_days', 'Wait before following up (days)',
            $v('whatsapp_followup_days', '3'), ['type' => 'number',
            'hint' => 'Days of silence before each follow-up. Between 1 and 14.'])
       . '</div>'
       . '<p class="hint">No follow-ups are sent after the last day of the exhibition (set under <a href="#set-show">Show dates and venue</a>). Replies to people who write to you still are.</p>'
       . admin_card_close()
       . (array_key_exists('mail_from_email', $a) || $editId === 0 && Migrations::columnExists('agents', 'mail_from_email')
          ? admin_card_open('set-email', 'Prospect emails', 'The name, address and mail server prospect emails are sent from, and how fast.')
          . '<p class="hint" style="margin:0 0 12px">Emails to companies found by prospecting go out as this exhibition, '
          . 'through its own mail server — never another exhibition\'s. Your team\'s own notifications still use '
          . 'the address under Settings.</p>'
          . '<div class="field-row">'
          . f_text('mail_from_name', 'From name', $v('mail_from_name'), ['placeholder' => 'Build Asia Secretariat'])
          . f_text('mail_from_email', 'From email', $v('mail_from_email'), ['type' => 'email', 'placeholder' => 'info@buildasia.net'])
          . f_text('mail_reply_to', 'Replies go to (optional)', $v('mail_reply_to'), ['type' => 'email',
                'hint' => 'Leave empty to receive replies at the From address.'])
          . '</div>'
          . '<div class="field-row">'
          . f_text('mail_smtp_host', 'Mail server (SMTP)', $v('mail_smtp_host'), ['placeholder' => 'mail.buildasia.net',
                'hint' => 'In cPanel: Email Accounts → Connect Devices, under "Outgoing Server".'])
          . f_text('mail_smtp_port', 'Port', $v('mail_smtp_port', '587'), ['type' => 'number'])
          . f_select('mail_smtp_encryption', 'Encryption', MailProfile::ENCRYPTIONS, $v('mail_smtp_encryption', 'tls'))
          . '</div>'
          . '<div class="field-row">'
          . f_text('mail_smtp_username', 'User name', $v('mail_smtp_username'), ['autocomplete' => 'off',
                'hint' => 'Usually the full email address.'])
          . f_text('mail_smtp_password', 'Password',
                trim((string)($a['mail_smtp_password'] ?? '')) !== '' ? '__unchanged__' : '', ['type' => 'password',
                'autocomplete' => 'new-password',
                'hint' => trim((string)($a['mail_smtp_password'] ?? '')) !== ''
                    ? 'A password is saved. Leave this untouched to keep it.'
                    : 'Stored encrypted and never shown again.'])
          . '</div>'
          . f_text('mail_postal_address', 'Organiser\'s postal address (shown at the foot of every email)',
                $v('mail_postal_address'), ['hint' => 'Business emails to people who did not ask for them should say who '
                . 'sent them and where the sender is. Example: ECG, Office 5, Clifton, Karachi, Pakistan.'])
          . '<h3 class="set-sub">Pace</h3>'
          . '<div class="field-row">'
          . f_text('prospect_emails_per_day', 'Emails per day', $v('prospect_emails_per_day', '30'), ['type' => 'number',
                'hint' => 'Start low — 20 to 30 — and raise it slowly. A new sender that suddenly emails hundreds '
                        . 'of companies is what spam filters look for.'])
          . f_text('prospect_email_interval_minutes', 'Interval between emails (minutes)',
                $v('prospect_email_interval_minutes', '10'), ['type' => 'number'])
          . f_text('prospect_research_per_day', 'Companies researched per day',
                $v('prospect_research_per_day', '20'), ['type' => 'number',
                'hint' => 'Each company uses up to the searches set under Settings → Company research.'])
          . f_text('prospect_research_gap_minutes', 'Gap between researches (minutes)',
                $v('prospect_research_gap_minutes', '5'), ['type' => 'number'])
          . '</div>'
          . admin_card_close()
          : '')
       . admin_card_open('set-ai', 'AI and limits', 'Which AI answers, a cheaper one for background work, and safety limits.')
       . f_select('llm_provider', 'AI provider', LlmCatalogue::options(), $v('llm_provider', 'anthropic'),
            ['hint' => 'Set the key for whichever you choose under Settings, where you can also test it.'])
       . f_text('llm_model', 'Model', $v('llm_model'), [
            'hint' => 'Leave blank to use that provider\'s usual model. Start typing for suggestions.',
            'list' => 'all_models',
         ])
       . f_select('llm_fallback_provider', 'Backup provider', ['' => 'None'] + LlmCatalogue::options(),
            $v('llm_fallback_provider'),
            ['hint' => 'Optional. If the main provider fails, this one is tried once before the assistant '
                     . 'shows its offline message. Useful if you do not want a single account to be a single '
                     . 'point of failure.'])
       . f_text('llm_fallback_model', 'Backup model', $v('llm_fallback_model'), ['list' => 'all_models'])
       . (array_key_exists('llm_background_model', $a) || ($editId === 0 && Migrations::columnExists('agents', 'llm_background_model'))
          ? '<div class="field-row">'
          . f_select('llm_background_provider', 'Background work: provider', ['' => 'Same as the main provider'] + LlmCatalogue::options(),
                $v('llm_background_provider'), ['hint' => 'Company research, finding prospects, first WhatsApp messages, '
                . 'follow-ups and prospect emails — work nobody is waiting for. A cheaper model here saves money; '
                . 'live chat and WhatsApp replies always use the main model above.'])
          . f_text('llm_background_model', 'Background work: model', $v('llm_background_model'), ['list' => 'all_models',
                'hint' => 'For example a "mini", "haiku" or "flash" model. Leave blank to use the main model.'])
          . '</div>'
          : '')
       . f_select('geo_provider', 'Country lookup service', GeoProviderFactory::available(), $v('geo_provider', 'ipwhois'))
       . '<div class="field-row">'
       . f_text('readiness_min_signals', 'Buying signals before someone counts as ready', $v('readiness_min_signals', '2'), ['type' => 'number'])
       . f_text('max_messages_per_conversation', 'Longest conversation (messages)', $v('max_messages_per_conversation', '40'), ['type' => 'number'])
       . f_text('max_input_chars', 'Longest message (characters)', $v('max_input_chars', '1200'), ['type' => 'number'])
       . f_text('rate_per_ip_per_min', 'Messages allowed per minute', $v('rate_per_ip_per_min', '12'), ['type' => 'number'])
       . '</div>' . admin_card_close()
       . '<datalist id="all_models">';

    foreach (LlmCatalogue::all() as $providerKey => $providerMeta) {
        foreach (LlmCatalogue::models($providerKey) as $modelName) {
            echo '<option value="' . e($modelName) . '">' . e((string)$providerMeta['label']) . '</option>';
        }
    }

    echo '</datalist>'
       . '<div class="save-bar"><span class="save-note">Changes apply as soon as you save.</span>'
       . '<a class="btn btn-quiet" href="' . e(url('admin/agents.php')) . '">Cancel</a>'
       . '<button class="btn" type="submit">Save agent</button></div>'
       . '</form>';

    if ($editId > 0 && array_key_exists('mail_from_email', $a)) {
        echo '<form method="post" class="card">' . Security::csrfField()
           . '<input type="hidden" name="action" value="test_mail">'
           . '<input type="hidden" name="id" value="' . (int)$editId . '">'
           . '<h2>Check the prospect email settings</h2>'
           . '<p class="lede">Sends one test email through this exhibition\'s own mail server, exactly as a '
           . 'prospect email would go. Save the settings above first.</p>'
           . f_text('test_to', 'Send the test to', (string)($user['email'] ?? ''), ['type' => 'email'])
           . '<button class="btn btn-small" type="submit">Send a test email</button></form>';
    }

    admin_layout_end();
    exit;
}

// ---- List -----------------------------------------------------------
$agents = admin_agents();

admin_layout_start('Agents and embed', ['New agent' => url('admin/agents.php?new=1')]);

echo '<p class="lede">One agent serves one exhibition website. Each keeps its own content, pricing and leads, '
   . 'and they never share anything.</p>';

echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
   . '<th style="padding-left:16px">Name</th><th>Short code</th><th>Websites</th><th>Status</th><th>Assistant</th><th></th>'
   . '</tr></thead><tbody>';

foreach ($agents as $a) {
    $health = CircuitBreaker::statusFor($a);
    echo '<tr><td style="padding-left:16px">' . e((string)$a['name']) . '</td>'
       . '<td class="mono">' . e((string)$a['slug']) . '</td>'
       . '<td class="small">' . e(str_replace("\n", ', ', (string)$a['allowed_domains'])) . '</td>'
       . '<td>' . ((string)$a['status'] === 'active'
            ? '<span class="pill pill-ok">Active</span>'
            : '<span class="pill pill-bad">Disabled</span>') . '</td>'
       . '<td>' . ($health['status'] === 'normal'
            ? '<span class="pill pill-ok">Normal</span>'
            : '<span class="pill pill-bad">Offline</span>') . '</td>'
       . '<td style="padding-right:16px"><a href="' . e(url('admin/agents.php?edit=' . (int)$a['id'])) . '">Edit</a></td></tr>';
}

echo '</tbody></table></div></div>';

admin_layout_end();

/**
 * The two sales reps, side by side.
 *
 * Visitors are given these details whenever a person is needed — asked for,
 * a discount, a complaint, a contract or payment question, or the assistant
 * could not answer. Pakistan gets the local rep, elsewhere the international
 * one, and a visitor whose country is not known yet gets both, labelled.
 */
/** Links to each card, so a long page can be reached in one click. */
function admin_settings_nav(array $a): string
{
    $links = [
        'set-event' => 'Exhibition', 'set-show' => 'Dates & venue', 'set-covers' => 'Sectors', 'set-score' => 'Lead score', 'set-website' => 'Website answers', 'set-quotations' => 'Quotations', 'set-links' => 'Chat links', 'set-voice' => 'Voice', 'set-chat' => 'Website chat',
        'set-disclosure' => 'AI notice', 'set-leads' => 'Leads', 'set-reps' => 'Sales reps', 'set-whatsapp' => 'WhatsApp',
    ];
    if (array_key_exists('mail_from_email', $a) || ($a === [] && Migrations::columnExists('agents', 'mail_from_email'))) {
        $links['set-email'] = 'Prospect emails';
    }
    $links['set-ai'] = 'AI and limits';
    return admin_page_nav($links);
}

function admin_reps_fieldset(int $agentId): string
{
    $html = admin_card_open('set-reps', 'Sales reps', 'Who visitors are given when they need a person — one local, one international.')
          . '<p class="hint" style="margin:0 0 12px">Given to visitors whenever a person is needed: they ask for '
          . 'one, ask for a discount, complain, ask about a contract or payment, or the assistant cannot '
          . 'answer. Companies in Pakistan are given the local rep, companies elsewhere the international '
          . 'one. If their country is not known yet, they are given both. Leave a rep empty and nobody is given '
          . 'those details.</p><div class="field-row" style="align-items:flex-start">';

    foreach ([
        'local'         => 'Local — companies in Pakistan',
        'international' => 'International — companies outside Pakistan',
    ] as $region => $title) {
        $rep = $agentId > 0 ? SalesReps::get($agentId, $region) : array_fill_keys(SalesReps::FIELDS, null);
        $html .= '<div style="flex:1;min-width:260px"><h3 style="margin:0 0 8px">' . e($title) . '</h3>'
               . f_text('rep[' . $region . '][name]', 'Name', $rep['name'], ['max' => 120])
               . f_text('rep[' . $region . '][designation]', 'Designation', $rep['designation'], ['max' => 120])
               . f_text('rep[' . $region . '][email]', 'Email', $rep['email'], ['type' => 'email'])
               . f_text('rep[' . $region . '][mobile]', 'Mobile number', $rep['mobile'],
                    ['max' => 24, 'hint' => 'With the country code, e.g. +92 300 1234567.'])
               . f_text('rep[' . $region . '][whatsapp]', 'WhatsApp number', $rep['whatsapp'],
                    ['max' => 24, 'hint' => 'With the country code.'])
               . f_text('rep[' . $region . '][wechat]', 'WeChat ID', $rep['wechat'], ['max' => 64])
               . '</div>';
    }

    return $html . '</div>' . admin_card_close();
}
