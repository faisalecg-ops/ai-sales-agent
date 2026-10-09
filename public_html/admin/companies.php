<?php
declare(strict_types=1);

/**
 * Companies — the shared record behind prospects, leads and registrations.
 *
 * The screen's job is to keep three things visibly apart: what was seen on a
 * page, what the assistant concluded from it, and what nobody knows. Blurring
 * those is how an outbound message ends up stating something untrue.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_role('admin');
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $actor  = (string)$user['username'];

    if ($action === 'create') {
        $name = Validator::text($_POST['name'] ?? null, 200);
        if ($name === null) {
            admin_flash('Enter the company name.', 'error');
            admin_redirect('admin/companies.php');
        }
        $result = CompanyStore::findOrCreate($agentId, [
            'name'    => $name,
            'website' => Validator::text($_POST['website'] ?? null, 255),
            'country' => Validator::text($_POST['country'] ?? null, 96),
        ], 'manual');

        if (!$result['created']) {
            admin_flash('That website already belongs to a company on your list, so it was opened instead of added again.');
        } elseif ($result['duplicates']) {
            admin_flash('Added. It resembles ' . count($result['duplicates'])
                . ' company that is already on your list — check below before researching it.', 'warn');
        } else {
            admin_flash('Company added.');
        }
        admin_redirect('admin/companies.php?view=' . (int)$result['company']['id']);
    }

    if ($action === 'do_not_contact') {
        CompanyStore::setDoNotContact($agentId, $id, $actor,
            Validator::text($_POST['reason'] ?? null, 255));
        admin_flash('Marked do not contact. This applies to every campaign for this exhibition.');
        admin_redirect('admin/companies.php?view=' . $id);
    }

    if ($action === 'allow_contact') {
        $company = CompanyStore::find($agentId, $id);
        if ($company !== null) {
            db()->update('companies', ['status' => 'active'], 'id = ?', [$id]);
            Audit::setActor($actor);
            Audit::log('company_contact_restored', $agentId, 'company', $id, 'Do not contact removed');
            admin_flash('Contact allowed again. Any suppressed addresses stay on the suppression list until you remove them there.', 'warn');
        }
        admin_redirect('admin/companies.php?view=' . $id);
    }

    if ($action === 'merge') {
        $into = (int)($_POST['into'] ?? 0);
        if (CompanyStore::merge($agentId, $id, $into, $actor)) {
            admin_flash('Merged. The old record is archived rather than deleted, so the decision stays visible.');
            admin_redirect('admin/companies.php?view=' . $into);
        }
        admin_flash('Those two could not be merged.', 'error');
        admin_redirect('admin/companies.php?view=' . $id);
    }

    admin_redirect('admin/companies.php');
}

// ---------------------------------------------------------------------
// One company
// ---------------------------------------------------------------------
if ($viewId > 0) {
    $company = CompanyStore::find($agentId, $viewId);
    if ($company === null) {
        admin_layout_start('Not found', ['Back' => url('admin/companies.php')]);
        echo '<div class="alert alert-error"><p>No such company for this exhibition.</p></div>';
        admin_layout_end();
        exit;
    }

    $facts      = CompanyEvidence::summarise($agentId, $viewId);
    $sources    = CompanyEvidence::sources($agentId, $viewId);
    $duplicates = CompanyStore::possibleDuplicates($agentId, $company);
    $contacts   = db()->all('SELECT * FROM company_contacts WHERE company_id = ? ORDER BY kind, id', [$viewId]);
    $blocked    = (string)$company['status'] === 'do_not_contact';

    admin_layout_start((string)$company['name'], ['Back to companies' => url('admin/companies.php')]);

    if ($blocked) {
        echo '<div class="alert alert-warn"><p>This company is marked <strong>do not contact</strong>. '
           . 'Nothing will be sent to it by any campaign.</p></div>';
    }

    // ---- Summary ----
    echo '<div class="card"><h2>The company</h2><div class="table-wrap"><table class="table"><tbody>';
    $rowOut = static function (string $label, string $value): void {
        echo '<tr><th style="width:180px;text-align:left">' . e($label) . '</th><td>'
           . ($value === '' ? '<span class="muted">Not known</span>' : e($value)) . '</td></tr>';
    };
    $rowOut('Name', (string)$company['name']);
    $rowOut('Website', (string)($company['website'] ?? ''));
    $rowOut('Matched on', (string)($company['domain'] ?? ''));
    $rowOut('Country', (string)($company['country'] ?? ''));
    $rowOut('Industry', (string)($company['industry'] ?? ''));
    $rowOut('First seen', (string)$company['created_at']);
    $rowOut('Came from', ucfirst((string)$company['source']));
    echo '</tbody></table></div></div>';

    // ---- Facts, in three states ----
    echo '<div class="card"><h2>What we know</h2>'
       . '<p class="lede">Observed means it was read on a page we can link to. '
       . 'Concluded means the assistant worked it out, and it may not be stated as fact in a message.</p>';

    if ($facts['observed']) {
        echo '<h3>Observed</h3><div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>Fact</th><th>Value</th><th>Seen on</th></tr></thead><tbody>';
        foreach ($facts['observed'] as $row) {
            echo '<tr><td>' . e(CompanyEvidence::label((string)$row['claim_key'])) . '</td>'
               . '<td>' . e((string)$row['claim_value']) . '</td>'
               . '<td class="small">' . ($row['source_url']
                    ? '<a href="' . e((string)$row['source_url']) . '" rel="noopener noreferrer nofollow" target="_blank">'
                      . e((string)($row['source_label'] ?: parse_url((string)$row['source_url'], PHP_URL_HOST) ?: 'source')) . '</a>'
                    : '<span class="muted">no link</span>')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    if ($facts['inferred']) {
        echo '<h3>Concluded by the assistant</h3><div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>About</th><th>What it concluded</th><th>Confidence</th></tr></thead><tbody>';
        foreach ($facts['inferred'] as $row) {
            echo '<tr><td>' . e(CompanyEvidence::label((string)$row['claim_key'])) . '</td>'
               . '<td>' . e((string)$row['claim_value']) . '</td>'
               . '<td class="small">' . e(ucfirst((string)$row['confidence'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    if ($facts['missing']) {
        $labels = array_map(static fn($k) => CompanyEvidence::label($k), $facts['missing']);
        echo '<h3>Not known</h3><p class="hint">' . e(implode(', ', $labels))
           . '. These are shown as missing rather than guessed.</p>';
    }

    if (!$facts['observed'] && !$facts['inferred']) {
        echo '<p class="hint">Nothing has been researched yet.</p>';
    }

    echo '</div>';

    // ---- Sources ----
    if ($sources) {
        echo '<div class="card"><h2>Where this came from</h2><ul>';
        foreach ($sources as $src) {
            echo '<li><a href="' . e((string)$src['source_url']) . '" rel="noopener noreferrer nofollow" target="_blank">'
               . e((string)$src['source_url']) . '</a> <span class="muted small">read '
               . e((string)$src['seen_at']) . '</span></li>';
        }
        echo '</ul></div>';
    }

    // ---- Contacts ----
    echo '<div class="card"><h2>Business contact points</h2>';
    if ($contacts) {
        echo '<div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>Type</th><th>Value</th><th>Status</th></tr></thead><tbody>';
        foreach ($contacts as $c) {
            echo '<tr><td>' . e(ucfirst((string)$c['kind'])) . '</td>'
               . '<td class="mono">' . e((string)$c['value']) . '</td>'
               . '<td>' . e(ucfirst((string)$c['status'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="hint">None recorded. Nothing can be sent to this company until there is one.</p>';
    }
    echo '</div>';

    // ---- Suspected duplicates ----
    if ($duplicates) {
        echo '<div class="card"><h2>Might be the same company</h2>'
           . '<p class="lede">These were not merged automatically, because a similar name is not proof. '
           . 'Merging cannot be undone from here, so check both before deciding.</p>'
           . '<div class="table-wrap"><table class="table"><thead><tr>'
           . '<th>Name</th><th>Country</th><th>Why it is suggested</th><th></th></tr></thead><tbody>';
        foreach ($duplicates as $dup) {
            $other = $dup['company'];
            echo '<tr><td><a href="' . e(url('admin/companies.php?view=' . (int)$other['id'])) . '">'
               . e((string)$other['name']) . '</a></td>'
               . '<td>' . e((string)($other['country'] ?? '')) . '</td>'
               . '<td class="small">' . e((string)$dup['reason']) . '</td>'
               . '<td><form method="post" style="margin:0" data-confirm="Merge this company into '
               . e((string)$other['name']) . '? This cannot be undone.">' . Security::csrfField()
               . '<input type="hidden" name="action" value="merge">'
               . '<input type="hidden" name="id" value="' . $viewId . '">'
               . '<input type="hidden" name="into" value="' . (int)$other['id'] . '">'
               . '<button class="btn btn-small btn-quiet" type="submit">Merge into this</button>'
               . '</form></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    // ---- Research ----
    $latestResearch = Migrations::tableExists('company_research') ? db()->one(
        'SELECT id, status, score, priority, finished_at FROM company_research
          WHERE agent_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1',
        [$agentId, $viewId]
    ) : null;
    echo '<div class="card"><h2>Research</h2>';
    if ($latestResearch !== null) {
        echo '<p>' . ((string)$latestResearch['status'] === 'done'
                ? 'Last researched ' . e(admin_time((string)$latestResearch['finished_at'])) . ': <strong>'
                  . (int)$latestResearch['score'] . '/100</strong>, '
                  . e(CompanyResearch::priorityLabel((string)$latestResearch['priority'])) . '.'
                : 'Research is ' . e((string)$latestResearch['status']) . '.')
           . ' <a href="' . e(url('admin/research.php?view=' . (int)$latestResearch['id'])) . '">Open it</a></p>';
    }
    echo '<form method="post" action="' . e(url('admin/research.php')) . '" style="margin:0">' . Security::csrfField()
       . '<input type="hidden" name="agent_id" value="' . $agentId . '">'
       . '<input type="hidden" name="action" value="request">'
       . '<input type="hidden" name="company_id" value="' . $viewId . '">'
       . '<button class="btn btn-small' . ($latestResearch !== null ? ' btn-quiet' : '') . '" type="submit">'
       . ($latestResearch !== null ? 'Research again' : 'Research this company') . '</button></form></div>';

    // ---- Contact status ----
    echo '<div class="card"><h2>Contact status</h2>';
    if ($blocked) {
        echo '<form method="post">' . Security::csrfField()
           . '<input type="hidden" name="action" value="allow_contact">'
           . '<input type="hidden" name="id" value="' . $viewId . '">'
           . '<p class="lede">Allowing contact again does not remove addresses from the suppression list. '
           . 'Remove them there as well if that is what you intend.</p>'
           . '<button class="btn btn-quiet" type="submit">Allow contact again</button></form>';
    } else {
        echo '<form method="post" data-confirm="Mark this company do not contact? It applies to every campaign for this exhibition.">'
           . Security::csrfField()
           . '<input type="hidden" name="action" value="do_not_contact">'
           . '<input type="hidden" name="id" value="' . $viewId . '">'
           . f_text('reason', 'Reason (optional)', '', ['max' => 255,
                'hint' => 'Recorded in the audit trail.'])
           . '<button class="btn" type="submit">Mark do not contact</button></form>';
    }
    echo '</div>';

    admin_layout_end();
    exit;
}

// ---------------------------------------------------------------------
// The list
// ---------------------------------------------------------------------
$filter = (string)($_GET['status'] ?? 'active');
$search = trim((string)($_GET['q'] ?? ''));

$where  = ['agent_id = ?'];
$params = [$agentId];

if (in_array($filter, ['active', 'do_not_contact', 'archived'], true)) {
    $where[]  = 'status = ?';
    $params[] = $filter;
}
if ($search !== '') {
    $where[]  = '(name LIKE ? OR domain LIKE ? OR country LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$companies = db()->all(
    'SELECT c.*, (SELECT COUNT(*) FROM company_evidence e WHERE e.company_id = c.id) AS fact_count
       FROM companies c WHERE ' . implode(' AND ', $where) . '
      ORDER BY c.created_at DESC LIMIT 200',
    $params
);

$total = (int)db()->value('SELECT COUNT(*) FROM companies WHERE agent_id = ?', [$agentId]);

admin_layout_start('Companies');

echo '<p class="lede">One record per company, however it was first encountered. '
   . 'Companies belong to this exhibition only and are never shared with your other events.</p>';

// ---- Filters ----
echo '<div class="card"><form method="get" class="field-row">'
   . f_select('status', 'Show', [
        'active'         => 'Active',
        'do_not_contact' => 'Do not contact',
        'archived'       => 'Archived',
        'all'            => 'All',
     ], $filter)
   . f_text('q', 'Search', $search, ['placeholder' => 'Name, website or country'])
   . '<div class="field"><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>'
   . '</form></div>';

if (!$companies) {
    echo '<div class="card"><h2>Nothing here yet</h2>'
       . '<p class="lede">' . ($total > 0
            ? 'No company matches that filter.'
            : 'Companies will appear as prospecting finds them, or as visitors identify themselves. '
              . 'You can also add one by hand below.') . '</p></div>';
} else {
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">Name</th><th>Website</th><th>Country</th>'
       . '<th>Known facts</th><th>Came from</th><th>Status</th><th></th>'
       . '</tr></thead><tbody>';

    foreach ($companies as $c) {
        $status = (string)$c['status'];
        $pill   = $status === 'active'
            ? '<span class="pill pill-ok">Active</span>'
            : ($status === 'do_not_contact'
                ? '<span class="pill pill-bad">Do not contact</span>'
                : '<span class="pill">Archived</span>');

        echo '<tr><td style="padding-left:16px"><a href="' . e(url('admin/companies.php?view=' . (int)$c['id'])) . '">'
           . e((string)$c['name']) . '</a></td>'
           . '<td class="small mono">' . e((string)($c['domain'] ?? '')) . '</td>'
           . '<td>' . e((string)($c['country'] ?? '')) . '</td>'
           . '<td>' . (int)$c['fact_count'] . '</td>'
           . '<td class="small">' . e(ucfirst((string)$c['source'])) . '</td>'
           . '<td>' . $pill . '</td>'
           . '<td style="padding-right:16px"><a href="' . e(url('admin/companies.php?view=' . (int)$c['id'])) . '">Open</a></td></tr>';
    }

    echo '</tbody></table></div></div>';
}

// ---- Add by hand ----
echo '<details class="card add-card"><summary><b>Add a company</b> <span class="hint" style="margin:0">— for a firm you '
   . 'already know about. Prospecting adds the rest.</span></summary>'
   . '<form method="post" style="margin-top:14px">' . Security::csrfField()
   . '<input type="hidden" name="action" value="create">'
   . '<div class="field-row">'
   . f_text('name', 'Company name', '', ['required' => true, 'max' => 200])
   . f_text('country', 'Country', '', ['max' => 96])
   . '</div>'
   . f_text('website', 'Website', '', ['max' => 255,
        'hint' => 'The surest way to avoid duplicates. A company with the same website is recognised as the same company.'])
   . '<div class="btn-row"><button class="btn" type="submit">Add company</button></div>'
   . '</form></details>';

admin_layout_end();
