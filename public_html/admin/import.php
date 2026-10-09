<?php
declare(strict_types=1);

/**
 * "Conversation with AI" — build the knowledge base from uploaded documents.
 *
 * The page is written as a conversation because that is what the work actually
 * is: the operator supplies documents, the assistant reports what it found,
 * and where the documents disagree it asks rather than guesses.
 *
 * It works without JavaScript. When scripts are available the next step is
 * submitted automatically so the operator can watch it progress; without them
 * there is a Continue button that does the same thing. Each step is a separate
 * request, because shared hosting will not let one run for long.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_login();
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$adminId = (int)$user['id'];

$runId = isset($_GET['run']) ? (int)$_GET['run'] : 0;
$run   = $runId > 0 ? ImportRun::load($runId, $agentId) : ImportRun::active($agentId);

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // When the whole request body exceeds post_max_size, PHP throws away every
    // field including the CSRF token, so the request arrives looking forged.
    // Recognising that first turns a baffling security error into a useful
    // sentence about file size.
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($_POST === [] && $declared > 0) {
        admin_flash(
            'Those files were too large to upload together — the server discarded the whole request. '
            . ImportRun::uploadLimitAdvice(),
            'error'
        );
        admin_redirect('admin/import.php' . ($runId > 0 ? '?run=' . $runId : ''));
    }

    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'start') {
        $run = ImportRun::create(
            $agent,
            $adminId,
            $_POST['title'] ?? null,
            Validator::inList($_POST['reader_provider'] ?? null, array_keys(LlmCatalogue::all()), null),
            $_POST['reader_model'] ?? null
        );
        admin_redirect('admin/import.php?run=' . (int)$run['id']);
    }

    if ($run === null) {
        admin_flash('That import no longer exists.', 'error');
        admin_redirect('admin/import.php');
    }

    $runId = (int)$run['id'];

    switch ($action) {
        case 'upload':
            $meta = [
                'doc_kind'      => $_POST['doc_kind'] ?? 'unknown',
                'edition_label' => $_POST['edition_label'] ?? null,
            ];
            $files = $_FILES['documents'] ?? null;
            $added = 0;

            if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
                $count = count($files['name']);
                for ($i = 0; $i < $count; $i++) {
                    $one = [
                        'name'     => $files['name'][$i] ?? '',
                        'tmp_name' => $files['tmp_name'][$i] ?? '',
                        'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $files['size'][$i] ?? 0,
                    ];
                    if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $result = ImportRun::addDocument($run, $one, $meta);
                    if ($result['ok']) {
                        $added++;
                    } else {
                        admin_flash((string)$one['name'] . ': ' . (string)$result['error'], 'error');
                    }
                }
            }

            if ($added > 0) {
                admin_flash($added === 1 ? 'Document added.' : $added . ' documents added.');
            }
            admin_redirect('admin/import.php?run=' . $runId);

        case 'paste':
            $result = ImportRun::addPastedText(
                $run,
                (string)($_POST['paste_title'] ?? 'Pasted text'),
                (string)($_POST['paste_body'] ?? ''),
                [
                    'doc_kind'      => $_POST['doc_kind'] ?? 'unknown',
                    'edition_label' => $_POST['edition_label'] ?? null,
                ]
            );
            admin_flash($result['ok'] ? 'Text added.' : (string)$result['error'], $result['ok'] ? 'ok' : 'error');
            admin_redirect('admin/import.php?run=' . $runId);

        case 'remove_document':
            ImportRun::removeDocument($run, (int)($_POST['document_id'] ?? 0));
            admin_flash('Document removed.');
            admin_redirect('admin/import.php?run=' . $runId);

        case 'describe_document':
            $documentId = (int)($_POST['document_id'] ?? 0);
            if (ImportRun::document($documentId, $runId) !== null) {
                db()->update('import_documents', [
                    'doc_kind'      => Validator::inList($_POST['doc_kind'] ?? null, ImportRun::documentKinds(), 'unknown'),
                    'edition_label' => Validator::text($_POST['edition_label'] ?? null, 160),
                ], 'id = ?', [$documentId]);
            }
            admin_redirect('admin/import.php?run=' . $runId);

        case 'step':
            $result = ImportPipeline::step($agent, $run);
            $_SESSION['import_message'] = $result['message'];
            admin_redirect('admin/import.php?run=' . $runId);

        case 'answer':
            FindingReconciler::answer(
                $run,
                (int)($_POST['question_id'] ?? 0),
                $_POST['answer_value'] ?? null,
                (string)($_POST['answer_source'] ?? 'typed'),
                $adminId,
                $_POST['answer_note'] ?? null
            );
            admin_redirect('admin/import.php?run=' . $runId);

        case 'exclude_proposal':
            ProposalBuilder::exclude((int)($_POST['proposal_id'] ?? 0), $runId);
            admin_redirect('admin/import.php?run=' . $runId . '#proposals');

        case 'restore_proposal':
            ProposalBuilder::restore((int)($_POST['proposal_id'] ?? 0), $runId);
            admin_redirect('admin/import.php?run=' . $runId . '#proposals');

        case 'edit_proposal':
            $fields = [];
            foreach ((array)($_POST['field'] ?? []) as $key => $value) {
                if (!is_string($key) || is_array($value)) {
                    continue;
                }
                $fields[$key] = trim((string)$value);
            }
            ProposalBuilder::saveEdit((int)($_POST['proposal_id'] ?? 0), $runId, $fields);
            admin_flash('Change saved. It will be used when you add these to the knowledge base.');
            admin_redirect('admin/import.php?run=' . $runId . '#proposals');

        case 'publish':
            $sendForReview = !empty($_POST['send_for_review']);
            $result = ImportPublisher::publish($run, $agent, (string)$user['username'], $sendForReview);
            foreach ($result['errors'] as $error) {
                admin_flash($error, 'error');
            }
            admin_flash(
                $result['created'] . ' ' . ($result['created'] === 1 ? 'item' : 'items')
                . ($sendForReview
                    ? ' added and sent for review. Nothing is visible to visitors until it is approved.'
                    : ' added as drafts. Send them for review when you are ready — nothing is visible to visitors'
                      . ' until it is approved.')
            );
            admin_redirect('admin/import.php?run=' . $runId);

        case 'set_reader':
            $chosen = Validator::inList($_POST['reader_provider'] ?? null, array_keys(LlmCatalogue::all()), null);
            if ($chosen === null) {
                admin_flash('That AI provider is not one this platform knows about.', 'error');
                admin_redirect('admin/import.php?run=' . $runId);
            }
            ImportRun::setReader($run, $chosen, $_POST['reader_model'] ?? null);
            $problem = LlmCatalogue::configurationProblem($chosen);
            admin_flash(
                $problem === null
                    ? LlmCatalogue::label($chosen) . ' will read the documents for this import.'
                    : $problem . ' Add a key under Settings before continuing.',
                $problem === null ? 'ok' : 'warn'
            );
            admin_redirect('admin/import.php?run=' . $runId);

        case 'resume':
            ImportRun::resume($run);
            admin_flash('Picked up where it left off.');
            admin_redirect('admin/import.php?run=' . $runId);

        case 'cancel':
            ImportRun::cancel($run, (string)$user['username']);
            ImportRun::purgeFiles($runId);
            admin_flash('Import cancelled and the uploaded files deleted.');
            admin_redirect('admin/import.php');
    }
}

$stepMessage = $_SESSION['import_message'] ?? null;
unset($_SESSION['import_message']);

// ---------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------

/** One turn of the conversation. */
function turn(string $who, string $body, string $extra = ''): string
{
    $isAgent = $who === 'agent';
    $bg      = $isAgent ? 'var(--wash)' : 'var(--accent-wash)';
    $border  = $isAgent ? 'var(--line)' : '#CFDEEF';
    $name    = $isAgent ? 'Assistant' : 'You';

    return '<div style="margin:0 0 12px;max-width:720px' . ($isAgent ? '' : ';margin-left:auto') . '">'
         . '<p style="margin:0 0 4px;font-size:11.5px;color:var(--ink-faint)"><strong>' . $name . '</strong></p>'
         . '<div style="background:' . $bg . ';border:1px solid ' . $border . ';border-radius:6px;padding:12px 14px">'
         . $body . $extra . '</div></div>';
}

/** Build a price card from a proposal payload, using the real renderer. */
function proposal_card(array $payload, string $type): ?array
{
    $row = $payload;
    $row['id']     = 0;
    $row['active'] = 1;
    $row['status'] = 'approved';

    foreach (['price', 'rate_per_sqm', 'min_sqm', 'increment_sqm', 'max_sqm', 'tax_rate_percent', 'quantity_available'] as $key) {
        if (($row[$key] ?? null) === '' ) {
            $row[$key] = null;
        }
    }
    if (($row['price'] ?? null) === null && ($row['rate_per_sqm'] ?? null) === null) {
        return null;
    }
    if (($row['price'] ?? null) === null) {
        $row['price'] = (float)$row['rate_per_sqm'] * (float)($row['min_sqm'] ?? 1);
    }

    try {
        return $type === 'stall_package'
            ? PricingEngine::toCard($row, PricingEngine::TYPE_STALL, isset($row['min_sqm']) ? (float)$row['min_sqm'] : null)
            : PricingEngine::toCard($row, PricingEngine::TYPE_SPONSORSHIP);
    } catch (Throwable) {
        return null;
    }
}

$csrf = Security::csrfField();

/**
 * The provider picker.
 *
 * Reading a pile of brochures is a batch job with different economics from
 * answering visitors, so it gets its own choice. Only providers that are
 * actually set up are offered; anything else would just fail later.
 */
function reader_picker(?string $selected, ?string $model, string $agentProvider): string
{
    $ready = LlmCatalogue::configuredOptions();

    if ($ready === []) {
        return '<div class="alert alert-error"><p>No AI provider is set up yet, so documents cannot be '
             . 'analysed. Add a key under <a href="' . e(url('admin/settings.php')) . '">Settings</a> '
             . 'first — any of the providers listed there will do.</p></div>';
    }

    // Name the agent's own provider, so it is clear what the default means.
    $options = [];
    foreach ($ready as $key => $label) {
        $options[$key] = $label . ($key === $agentProvider ? ' — also answers your visitors' : '');
    }

    $selected = ($selected !== null && isset($ready[$selected])) ? $selected : (string)array_key_first($ready);

    $out = '';

    // If the agent's own provider is not usable, say why something else was
    // chosen, rather than silently substituting one.
    if (!isset($ready[$agentProvider])) {
        $out .= '<div class="alert alert-warn"><p>Your agent answers visitors with '
              . e(LlmCatalogue::label($agentProvider)) . ', but no key is saved for it, so it cannot read '
              . 'documents. ' . e(LlmCatalogue::label($selected)) . ' has been chosen instead. That is '
              . 'fine — reading documents is a one-off job and does not have to use the same provider.</p>'
              . '</div>';
    }

    $out .= f_select('reader_provider', 'Which AI should read the documents?', $options, $selected,
        ['hint' => 'Only providers you have set up are listed. This choice applies to this import only — '
                 . 'it does not change which AI answers your visitors.']);

    $out .= f_text('reader_model', 'Model', (string)$model, [
        'list' => 'reader_models',
        'hint' => 'Leave blank to use that provider\'s usual model.',
    ]);

    $out .= '<datalist id="reader_models">';
    foreach (LlmCatalogue::all() as $providerKey => $meta) {
        foreach (LlmCatalogue::models($providerKey) as $modelName) {
            $out .= '<option value="' . e($modelName) . '">' . e((string)$meta['label']) . '</option>';
        }
    }
    $out .= '</datalist>';

    return $out;
}

// =====================================================================
// No run yet: the landing page
// =====================================================================
if ($run === null) {
    admin_layout_start('Conversation with AI');

    echo '<p class="lede">Upload your brochures, price lists, sponsorship packages and post-show reports. '
       . 'The assistant reads them, works out what belongs in its knowledge base, and asks you about anything '
       . 'the documents disagree on.</p>';

    echo '<div class="card"><h2>How it works</h2>'
       . '<ol class="steps">'
       . '<li>You add the documents and say roughly what each one is.</li>'
       . '<li>The assistant reads them and pulls out the facts, quoting the exact wording for each one.</li>'
       . '<li>It compares the documents against each other.</li>'
       . '<li>Where they disagree — two prices for the same package, two sets of dates — it asks you which is '
       . 'right rather than choosing for itself.</li>'
       . '<li>You review everything it has prepared, edit anything you want, and add it.</li>'
       . '</ol>'
       . '<div class="alert"><p>Everything arrives as a <strong>draft</strong>. Nothing is visible to visitors '
       . 'until you approve it in the review queue, exactly as if you had typed it in yourself.</p></div>'
       . '<form method="post">' . $csrf
       . '<input type="hidden" name="action" value="start">'
       . f_text('title', 'Give this import a name', 'Build Asia knowledge, ' . gmdate('j M Y'),
            ['hint' => 'Only so you can find it again later.'])
       . reader_picker(null, null, (string)$agent['llm_provider'])
       . '<button class="btn" type="submit">Start</button></form></div>';

    $history = ImportRun::recent($agentId);
    if ($history) {
        echo '<div class="card"><h2>Earlier imports</h2><table class="table"><thead><tr>'
           . '<th>Name</th><th>When</th><th>Documents</th><th>Added</th><th>Status</th><th></th>'
           . '</tr></thead><tbody>';
        foreach ($history as $past) {
            echo '<tr><td>' . e((string)$past['title']) . '</td>'
               . '<td class="small muted">' . e(admin_time((string)$past['created_at'])) . '</td>'
               . '<td class="num">' . (int)$past['documents_total'] . '</td>'
               . '<td class="num">' . (int)$past['published_total'] . '</td>'
               . '<td>' . e(ImportRun::STAGES[(string)$past['status']] ?? (string)$past['status']) . '</td>'
               . '<td><a href="' . e(url('admin/import.php?run=' . (int)$past['id'])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    admin_layout_end();
    exit;
}

// =====================================================================
// A run in progress
// =====================================================================
$runId     = (int)$run['id'];
$documents = ImportRun::documents($runId);
$progress  = ImportRun::progress($run);
$status    = (string)$run['status'];

admin_layout_start('Conversation with AI', ['All imports' => url('admin/import.php?run=0')]);

// ---- Progress ----
echo '<div class="card"><div class="card-head"><h2>' . e((string)$run['title']) . '</h2>'
   . '<span class="pill ' . ($status === 'published' ? 'pill-ok' : ($status === 'failed' ? 'pill-bad' : 'pill-info')) . '">'
   . e($progress['label']) . '</span></div>';

echo '<div style="background:var(--wash);border:1px solid var(--line);border-radius:999px;height:8px;overflow:hidden">'
   . '<div style="width:' . (int)$progress['percent'] . '%;height:100%;background:var(--accent)"></div></div>'
   . '<p class="hint" style="margin-top:8px">' . e($progress['detail']) . '</p>';

$reader = ImportRun::reader($run, $agent);
echo '<p class="hint">Reading the documents with <strong>'
   . e(LlmCatalogue::label($reader['provider'])) . '</strong>'
   . ($reader['model'] !== '' ? ' (' . e($reader['model']) . ')' : '') . '.';

if ((int)$run['input_tokens'] > 0) {
    echo ' About ' . number_format((int)$run['input_tokens'] + (int)$run['output_tokens'])
       . ' tokens used so far.';
}
echo '</p>';

// Switching provider is useful mid-import: a key runs out of credit, or the
// operator decides a cheaper one will do for the remaining sections.
if (!in_array($status, ['published', 'cancelled'], true)) {
    echo '<details><summary style="cursor:pointer;font-size:13.5px;font-weight:600">'
       . 'Use a different AI for this import</summary>'
       . '<form method="post" style="margin-top:12px;max-width:520px">' . $csrf
       . '<input type="hidden" name="action" value="set_reader">'
       . reader_picker($reader['provider'], $reader['model'], (string)$agent['llm_provider'])
       . '<button class="btn btn-small" type="submit">Use this one</button>'
       . '<p class="hint">Sections already read are kept. Only what is left is read by the new one.</p>'
       . '</form></details>';
}

echo '</div>';

// ---- The conversation ----
echo '<div class="card"><h2>Conversation</h2>';

if ($documents) {
    $names = array_map(static fn($d) => (string)$d['original_name'], $documents);
    echo turn('you', '<p style="margin:0">Here ' . (count($names) === 1 ? 'is' : 'are') . ' '
        . count($names) . ' ' . (count($names) === 1 ? 'document' : 'documents') . ': '
        . e(implode(', ', $names)) . '</p>');

    $read = array_filter($documents, static fn($d) => (string)$d['extraction_status'] !== 'pending');
    if ($read) {
        $lines = '';
        foreach ($read as $doc) {
            $ok = (string)$doc['extraction_status'] === 'ok';
            $lines .= '<li>' . ($ok ? '' : '<strong>Could not read </strong>')
                    . e((string)$doc['original_name'])
                    . ($ok
                        ? ' — ' . number_format((int)$doc['extracted_chars']) . ' characters, via '
                          . e((string)$doc['extraction_method'])
                        : '')
                    . ((string)$doc['extraction_problem'] !== ''
                        ? '<br><span class="small" style="color:var(--signal)">' . e((string)$doc['extraction_problem']) . '</span>'
                        : '')
                    . '</li>';
        }
        echo turn('agent', '<p style="margin:0 0 6px">Here is what I could read:</p><ul style="margin:0;padding-left:20px">' . $lines . '</ul>');
    }
}

if ((int)$run['findings_total'] > 0) {
    echo turn('agent', '<p style="margin:0">I found ' . (int)$run['findings_total']
        . ' individual facts across those documents, each with the exact wording it came from.</p>');
}

// Everything already settled, so the operator can see the decisions they made.
foreach (FindingReconciler::questions($runId) as $question) {
    if ((string)$question['status'] === 'open') {
        continue;
    }
    echo turn('agent', '<p style="margin:0">' . e((string)$question['question_text']) . '</p>');
    echo turn('you', '<p style="margin:0">'
        . ((string)$question['status'] === 'skipped'
            ? '<em>Skipped — use whatever the documents say.</em>'
            : e((string)$question['answer_value']))
        . '</p>');
}

if ($stepMessage !== null && $stepMessage !== '') {
    echo turn('agent', '<p style="margin:0">' . e((string)$stepMessage) . '</p>');
}

// ---- The open question, if there is one ----
$open = FindingReconciler::nextOpenQuestion($runId);

if ($status === 'clarifying' && $open !== null) {
    $options = json_decode((string)($open['options_json'] ?? '[]'), true);
    $options = is_array($options) ? $options : [];

    $body = '<p style="margin:0 0 4px"><strong>' . e((string)$open['question_text']) . '</strong></p>';
    if ((string)$open['detail_text'] !== '') {
        $body .= '<p class="hint" style="margin:0">' . e((string)$open['detail_text']) . '</p>';
    }
    echo turn('agent', $body);

    echo '<div style="max-width:720px;margin-left:auto">';
    foreach ($options as $index => $option) {
        echo '<form method="post" style="margin:0 0 6px">' . $csrf
           . '<input type="hidden" name="action" value="answer">'
           . '<input type="hidden" name="question_id" value="' . (int)$open['id'] . '">'
           . '<input type="hidden" name="answer_source" value="option">'
           . '<input type="hidden" name="answer_value" value="' . e((string)$option['value']) . '">'
           . '<button class="btn btn-quiet" type="submit" style="width:100%;text-align:left">'
           . '<strong>' . e((string)($option['label'] ?? $option['value'])) . '</strong>'
           . (!empty($option['detail']) ? '<br><span class="small muted">' . e((string)$option['detail']) . '</span>' : '')
           . (!empty($option['quote']) ? '<br><span class="small muted">&ldquo;' . e(mb_substr((string)$option['quote'], 0, 160)) . '&rdquo;</span>' : '')
           . '</button></form>';
    }

    if ((int)$open['allow_free_text'] === 1) {
        echo '<form method="post" style="margin:0 0 6px">' . $csrf
           . '<input type="hidden" name="action" value="answer">'
           . '<input type="hidden" name="question_id" value="' . (int)$open['id'] . '">'
           . '<input type="hidden" name="answer_source" value="typed">'
           . '<div style="display:flex;gap:8px">'
           . '<input class="input" type="text" name="answer_value" placeholder="Or type the correct value" required>'
           . '<button class="btn" type="submit">Use this</button></div></form>';
    }

    echo '<form method="post" style="margin:0">' . $csrf
       . '<input type="hidden" name="action" value="answer">'
       . '<input type="hidden" name="question_id" value="' . (int)$open['id'] . '">'
       . '<input type="hidden" name="answer_source" value="skip">'
       . '<button class="btn btn-quiet btn-small" type="submit">Skip — leave it as the documents have it</button>'
       . '</form></div>';
}

// ---- Work still to do ----
if (ImportRun::hasPendingWork($run)) {
    echo '<div style="max-width:720px">'
       . '<form method="post" id="step-form">' . $csrf
       . '<input type="hidden" name="action" value="step">'
       . '<button class="btn" type="submit">Continue</button>'
       . '<span class="hint" style="margin-left:10px">Working through the documents one piece at a time.</span>'
       . '</form></div>'
       // Submitted automatically where scripts are available, so the operator
       // can watch it work; the button above does the same thing without them.
       . '<script>setTimeout(function(){var f=document.getElementById("step-form");if(f){f.submit();}},900);</script>';
}

if ($status === 'collecting' && $documents) {
    echo '<div style="max-width:720px"><form method="post">' . $csrf
       . '<input type="hidden" name="action" value="step">'
       . '<button class="btn" type="submit">Read these documents and start</button></form></div>';
}

echo '</div>';

// =====================================================================
// A failed run can be picked up once the cause is dealt with
// =====================================================================
if ($status === 'failed') {
    echo '<div class="card"><div class="alert alert-error"><p><strong>This import stopped.</strong> '
       . e((string)($run['last_error'] ?? 'No reason was recorded.')) . '</p></div>'
       . '<p class="lede">Your documents are still here. Once the cause is dealt with, carry on from '
       . 'where it stopped — nothing needs uploading again.</p>'
       . '<form method="post" style="max-width:520px;margin-bottom:14px">' . $csrf
       . '<input type="hidden" name="action" value="set_reader">'
       . reader_picker(
            ImportRun::reader($run, $agent)['provider'],
            ImportRun::reader($run, $agent)['model'],
            (string)$agent['llm_provider']
         )
       . '<button class="btn btn-quiet btn-small" type="submit">Use this one instead</button></form>'
       . '<form method="post">' . $csrf
       . '<input type="hidden" name="action" value="resume">'
       . '<button class="btn" type="submit">Try again</button></form></div>';
}

// =====================================================================
// Documents panel
// =====================================================================
if (in_array($status, ['collecting', 'extracting', 'failed'], true)) {
    echo '<div class="card"><h2>Documents</h2>';

    if ($documents) {
        echo '<div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>File</th><th>What it is</th><th>Edition</th><th>Size</th><th>Read</th><th></th>'
           . '</tr></thead><tbody>';
        foreach ($documents as $doc) {
            $kindLabels = ImportRun::documentKindLabels();
            echo '<tr><td>' . e((string)$doc['original_name']) . '</td>'
               . '<td><form method="post" style="margin:0;display:flex;gap:6px">' . $csrf
               . '<input type="hidden" name="action" value="describe_document">'
               . '<input type="hidden" name="document_id" value="' . (int)$doc['id'] . '">'
               . '<select name="doc_kind" class="input" style="font-size:13px;padding:5px">';
            foreach ($kindLabels as $key => $label) {
                echo '<option value="' . e($key) . '"' . ((string)$doc['doc_kind'] === $key ? ' selected' : '') . '>'
                   . e($label) . '</option>';
            }
            echo '</select></td>'
               . '<td><input class="input" type="text" name="edition_label" style="font-size:13px;padding:5px" '
               . 'value="' . e((string)$doc['edition_label']) . '" placeholder="e.g. Karachi 2027">'
               . '<button class="btn btn-small btn-quiet" type="submit" style="margin-top:4px">Save</button></form></td>'
               . '<td class="num small">' . e(ImportRun::readableSize((int)$doc['byte_size'])) . '</td>'
               . '<td>' . match ((string)$doc['extraction_status']) {
                    'ok'     => '<span class="pill pill-ok">' . number_format((int)$doc['extracted_chars']) . ' chars</span>',
                    'empty'  => '<span class="pill pill-warn">No text</span>',
                    'failed' => '<span class="pill pill-bad">Failed</span>',
                    default  => '<span class="pill">Waiting</span>',
                 } . '</td>'
               . '<td><form method="post" style="margin:0">' . $csrf
               . '<input type="hidden" name="action" value="remove_document">'
               . '<input type="hidden" name="document_id" value="' . (int)$doc['id'] . '">'
               . '<button class="btn btn-small btn-quiet" type="submit">Remove</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div class="empty">No documents yet.</div>';
    }

    // ---- Add documents ----
    echo '<h3 style="margin-top:20px">Add documents</h3>';

    // A low server limit is the commonest reason an upload fails, and it is
    // worth saying so before the operator tries rather than after.
    if (ImportRun::uploadLimitIsServerImposed()) {
        echo '<div class="alert alert-warn"><p>' . e(ImportRun::uploadLimitAdvice()) . '</p></div>';
    }

    echo ''
       . '<form method="post" enctype="multipart/form-data">' . $csrf
       . '<input type="hidden" name="action" value="upload">'
       . '<div class="field"><label for="f_documents">Choose files</label>'
       . '<input type="file" id="f_documents" name="documents[]" multiple '
       . 'accept=".pdf,.docx,.txt,.md,.csv,.tsv,.html,.htm,.rtf">'
       . '<p class="hint">' . e(implode(', ', array_values(DocumentText::ACCEPTED))) . '. '
       . 'Up to ' . ImportRun::readableSize(ImportRun::uploadLimitBytes()) . ' each, '
       . ImportRun::MAX_DOCUMENTS . ' in total.</p></div>'
       . '<div class="field-row">'
       . f_select('doc_kind', 'What are these?', ImportRun::documentKindLabels(), 'unknown',
            ['hint' => 'This decides which document is believed when two disagree on a detail.'])
       . f_text('edition_label', 'Which edition do they describe?', '',
            ['hint' => 'Optional but valuable, for example "21st Build Asia, Karachi, March 2027". '
                     . 'It stops one edition\'s prices being mixed with another\'s.'])
       . '</div>'
       . '<button class="btn" type="submit">Add these documents</button></form>';

    // ---- Paste text ----
    echo '<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:600">'
       . 'Or paste text in directly</summary>'
       . '<p class="hint">Useful when a PDF is made of scanned images, which have no text to read.</p>'
       . '<form method="post">' . $csrf
       . '<input type="hidden" name="action" value="paste">'
       . f_text('paste_title', 'What is this?', '', ['placeholder' => 'e.g. 2027 participation rates'])
       . f_area('paste_body', 'The text', '', ['rows' => 8])
       . '<button class="btn btn-quiet" type="submit">Add this text</button></form></details>';

    echo '</div>';
}

// =====================================================================
// Review the prepared knowledge
// =====================================================================
if (in_array($status, ['review', 'published'], true)) {
    $proposals = ProposalBuilder::proposals($runId);
    $excluded  = db()->all(
        "SELECT * FROM import_proposals WHERE run_id = ? AND status = 'excluded' ORDER BY id ASC",
        [$runId]
    );

    $typeNames = [
        'stall_package'       => 'Stand pricing',
        'sponsorship_package' => 'Sponsorship tiers',
        'faq'                 => 'FAQs',
        'knowledge_article'   => 'Knowledge articles',
    ];

    echo '<div class="card" id="proposals"><div class="card-head"><h2>What I have prepared</h2>';
    if ($status === 'review') {
        echo '<span class="muted small">' . count($proposals) . ' item(s)</span>';
    }
    echo '</div>';

    if ($status === 'review') {
        echo '<div class="alert"><p>Check these over and edit anything that is not right. When you add them '
           . 'they become <strong>drafts</strong> — still invisible to visitors until you approve them in the '
           . 'review queue.</p></div>';
    }

    if (!$proposals) {
        echo '<div class="empty">Nothing was prepared from those documents.</div>';
    }

    $grouped = [];
    foreach ($proposals as $proposal) {
        $grouped[(string)$proposal['target_type']][] = $proposal;
    }

    foreach ($typeNames as $type => $typeLabel) {
        if (empty($grouped[$type])) {
            continue;
        }
        echo '<h3 style="margin-top:20px">' . e($typeLabel) . ' (' . count($grouped[$type]) . ')</h3>';

        foreach ($grouped[$type] as $proposal) {
            $payload  = json_decode((string)$proposal['payload_json'], true) ?: [];
            $warnings = json_decode((string)($proposal['warnings_json'] ?? '[]'), true) ?: [];
            $isDone   = (string)$proposal['status'] === 'published';

            echo '<div class="card" style="border-left:3px solid '
               . ($isDone ? 'var(--ok)' : ($warnings ? 'var(--signal)' : 'var(--accent)')) . '">';

            echo '<div class="card-head"><h4 style="margin:0">' . e((string)$proposal['title']) . '</h4>';
            if ($isDone) {
                echo '<span class="pill pill-ok">Added as a draft</span>';
            } elseif ((int)$proposal['clarified'] === 1) {
                echo '<span class="pill pill-info">You confirmed a value</span>';
            }
            echo '</div>';

            echo '<p class="hint" style="margin:0 0 10px">From ' . e((string)$proposal['source_summary']) . '</p>';

            foreach ($warnings as $warning) {
                echo '<div class="alert alert-warn" style="margin:0 0 8px"><p>' . e((string)$warning) . '</p></div>';
            }

            // For pricing, show exactly what a visitor would see.
            if (in_array($type, ['stall_package', 'sponsorship_package'], true)) {
                $card = proposal_card($payload, $type);
                if ($card !== null) {
                    echo '<div style="background:var(--paper);border:1px solid var(--line);border-left:3px solid var(--accent);'
                       . 'border-radius:5px;padding:12px 14px;margin-bottom:12px">'
                       . '<strong>' . e((string)$card['title']) . '</strong>';
                    if ($card['meta']) {
                        echo '<p class="small muted" style="margin:2px 0 0">' . e(implode(' / ', $card['meta'])) . '</p>';
                    }
                    echo '<table class="sums"><tbody>';
                    foreach ($card['price_lines'] as $line) {
                        echo '<tr><td>' . e((string)$line['label']) . '</td><td>' . e((string)$line['value']) . '</td></tr>';
                    }
                    echo '</tbody></table>'
                       . '<p class="small muted" style="margin:8px 0 0">' . e((string)$card['tax_note']) . '</p>'
                       . '<p class="hint" style="margin:6px 0 0">This is how it would appear to a visitor.</p>'
                       . '</div>';
                }
            }

            if (!$isDone) {
                echo '<details><summary style="cursor:pointer;font-weight:600;font-size:13.5px">Edit this</summary>'
                   . '<form method="post" style="margin-top:12px">' . $csrf
                   . '<input type="hidden" name="action" value="edit_proposal">'
                   . '<input type="hidden" name="proposal_id" value="' . (int)$proposal['id'] . '">';

                $editable = match ($type) {
                    'stall_package' => [
                        'package_name' => ['Name', 'text'], 'stall_size' => ['Size description', 'text'],
                        'currency' => ['Currency', 'text'], 'price' => ['Fixed price', 'text'],
                        'rate_per_sqm' => ['Rate per sqm', 'text'], 'min_sqm' => ['Smallest size', 'text'],
                        'increment_sqm' => ['Sold in steps of', 'text'], 'max_sqm' => ['Largest size', 'text'],
                        'tax_rate_percent' => ['Tax rate (%)', 'text'], 'tax_label' => ['Tax name', 'text'],
                        'edition_label' => ['Edition', 'text'],
                        'included_items' => ['What is included, one per line', 'area'],
                        'description' => ['Description', 'area'],
                    ],
                    'sponsorship_package' => [
                        'tier_name' => ['Tier name', 'text'], 'currency' => ['Currency', 'text'],
                        'price' => ['Price', 'text'], 'quantity_available' => ['How many remain', 'text'],
                        'tax_rate_percent' => ['Tax rate (%)', 'text'], 'tax_label' => ['Tax name', 'text'],
                        'edition_label' => ['Edition', 'text'],
                        'benefits' => ['What the partner gets, one per line', 'area'],
                        'description' => ['Description', 'area'],
                    ],
                    'faq' => [
                        'question' => ['Question', 'text'], 'answer' => ['Answer', 'area'],
                        'category' => ['Category', 'text'], 'keywords' => ['Search words', 'text'],
                    ],
                    default => [
                        'title' => ['Title', 'text'], 'body' => ['The information', 'area'],
                        'category' => ['Category', 'text'], 'keywords' => ['Search words', 'text'],
                    ],
                };

                foreach ($editable as $field => [$label, $kind]) {
                    $value = (string)($payload[$field] ?? '');
                    if ($kind === 'area') {
                        echo '<div class="field"><label>' . e($label) . '</label>'
                           . '<textarea class="input" name="field[' . e($field) . ']" rows="'
                           . ($field === 'body' ? 10 : 5) . '">' . e($value) . '</textarea></div>';
                    } else {
                        echo '<div class="field"><label>' . e($label) . '</label>'
                           . '<input class="input" type="text" name="field[' . e($field) . ']" value="' . e($value) . '"></div>';
                    }
                }

                echo '<button class="btn btn-small" type="submit">Save this change</button></form></details>';

                echo '<form method="post" style="margin-top:10px">' . $csrf
                   . '<input type="hidden" name="action" value="exclude_proposal">'
                   . '<input type="hidden" name="proposal_id" value="' . (int)$proposal['id'] . '">'
                   . '<button class="btn btn-small btn-quiet" type="submit">Leave this one out</button></form>';
            }

            echo '</div>';
        }
    }

    if ($excluded) {
        echo '<h3 style="margin-top:20px">Left out (' . count($excluded) . ')</h3><table class="table"><tbody>';
        foreach ($excluded as $proposal) {
            echo '<tr><td>' . e((string)$proposal['title']) . '</td>'
               . '<td class="small muted">' . e(str_replace('_', ' ', (string)$proposal['target_type'])) . '</td>'
               . '<td><form method="post" style="margin:0">' . $csrf
               . '<input type="hidden" name="action" value="restore_proposal">'
               . '<input type="hidden" name="proposal_id" value="' . (int)$proposal['id'] . '">'
               . '<button class="btn btn-small btn-quiet" type="submit">Put it back</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    if ($status === 'review' && $proposals) {
        echo '<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--line)">'
           . '<h3>Add these to the knowledge base</h3>'
           . '<p class="lede">Either way they arrive unpublished. The difference is only whether they go '
           . 'straight into the review queue, which is convenient when you want one person to check the '
           . 'whole batch at once.</p>'
           . '<div class="btn-row">'
           . '<form method="post" style="margin:0">' . $csrf
           . '<input type="hidden" name="action" value="publish">'
           . '<input type="hidden" name="send_for_review" value="1">'
           . '<button class="btn" type="submit">Add all ' . count($proposals) . ' and send for review</button></form>'
           . '<form method="post" style="margin:0">' . $csrf
           . '<input type="hidden" name="action" value="publish">'
           . '<button class="btn btn-quiet" type="submit">Add as drafts only</button></form>'
           . '</div></div>';
    }

    if ($status === 'published') {
        $pending = ReviewWorkflow::pendingCount($agentId);
        echo '<div class="alert alert-ok" style="margin-top:18px">'
           . '<p><strong>Done.</strong> ' . (int)$run['published_total'] . ' '
           . ((int)$run['published_total'] === 1 ? 'item was' : 'items were') . ' added.</p>'
           . '<p>Nothing is visible to visitors yet. '
           . ($pending > 0
                ? 'Open the <a href="' . e(url('admin/review.php')) . '">review queue</a> to check and approve.'
                : 'They are saved as drafts. Open <a href="' . e(url('admin/knowledge.php')) . '">Knowledge</a>, '
                  . '<a href="' . e(url('admin/pricing.php')) . '">Stand pricing</a> or '
                  . '<a href="' . e(url('admin/sponsorship.php')) . '">Sponsorship</a> to send them for review.')
           . '</p></div>';
    }

    echo '</div>';
}

// =====================================================================
// Cancel
// =====================================================================
if (!in_array($status, ['published', 'cancelled'], true)) {
    echo '<div class="card"><h3>Abandon this import</h3>'
       . '<p class="lede">Nothing will be added to the knowledge base and the uploaded files will be deleted.</p>'
       . '<form method="post" data-confirm="Cancel this import and delete the uploaded files?">' . $csrf
       . '<input type="hidden" name="action" value="cancel">'
       . '<button class="btn btn-quiet" type="submit">Cancel this import</button></form></div>';
}

admin_layout_end();
