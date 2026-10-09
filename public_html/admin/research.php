<?php
declare(strict_types=1);

/**
 * Company research: every company researched for this exhibition, best
 * prospects first, and the full prospect card for each.
 *
 * Laid out after the owner's prospecting method: the score in its six parts
 * rather than one mysterious number, facts with their sources, what is only
 * inferred, what could not be found, who decides, which offer fits, and why
 * the company would pay to exhibit at all.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_role('admin');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];
Audit::setActor($actor);

$guard = Security::csrfField() . '<input type="hidden" name="agent_id" value="' . $agentId . '">';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    if ((int)($_POST['agent_id'] ?? 0) !== $agentId) {
        admin_flash('That page was showing a different exhibition, so nothing was done. Check and try again.', 'error');
        admin_redirect('admin/research.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request') {
        if (!ResearchProviderFactory::make()->isConfigured()) {
            admin_flash('No research service is set up yet. Add Tavily under Settings → Company research.', 'error');
            admin_redirect('admin/research.php');
        }
        $companyId = (int)($_POST['company_id'] ?? 0);
        $company   = $companyId > 0 ? CompanyStore::find($agentId, $companyId) : null;
        $name      = $company !== null ? (string)$company['name'] : trim((string)($_POST['company_name'] ?? ''));
        $country   = $company !== null ? ($company['country'] ?? null)
                   : (CountryData::name((string)($_POST['country'] ?? '')) ?? null);
        $website   = $company !== null ? ($company['domain'] ?? null) : trim((string)($_POST['website'] ?? ''));

        $id = CompanyResearch::request($agent, $name, $country, $website !== '' ? $website : null, 'admin', null,
            $actor, $company !== null ? $companyId : null);

        if ($id === null) {
            admin_flash('Enter the company\'s name.', 'error');
            admin_redirect('admin/research.php');
        }

        $row = CompanyResearch::find($agentId, $id);
        if ($row !== null && (string)$row['status'] === 'queued') {
            CompanyResearch::runAfterResponse($id);
            admin_flash('Research started. It takes a minute or two — refresh this page to see the result.');
        } else {
            admin_flash('This company was researched recently, so the existing result is shown rather than paying '
                . 'for the same searches again.');
        }
        admin_redirect('admin/research.php?view=' . $id);
    }

    if ($action === 'retry') {
        $id = (int)($_POST['id'] ?? 0);
        if (CompanyResearch::retry($agentId, $id)) {
            CompanyResearch::runAfterResponse($id);
            admin_flash('Started again. Refresh in a minute or two.');
        } else {
            admin_flash('Only research that failed can be started again.', 'error');
        }
        admin_redirect('admin/research.php?view=' . $id);
    }
}

$viewId = (int)($_GET['view'] ?? 0);

admin_layout_start('Company research');

if (!ResearchProviderFactory::make()->isConfigured()) {
    echo '<div class="card" style="border-left:3px solid var(--signal)"><h2>Not set up yet</h2>'
       . '<p>Research needs a search service. Add your Tavily key under '
       . '<a href="' . e(url('admin/settings.php')) . '">Settings → Company research</a>.</p></div>';
}

// ---------------------------------------------------------------------
// One company, in full
// ---------------------------------------------------------------------
if ($viewId > 0) {
    $row = CompanyResearch::find($agentId, $viewId);

    if ($row === null) {
        echo '<div class="card"><p>That research is not on this exhibition\'s list.</p></div>';
    } else {
        $f       = CompanyResearch::findingsOf($row);
        $sources = json_decode((string)($row['sources'] ?? '[]'), true) ?: [];
        $byId    = [];
        foreach ($sources as $s) {
            $byId[(string)$s['id']] = $s;
        }
        $srcLink = static function (array $ids) use ($byId): string {
            $out = [];
            foreach ($ids as $sid) {
                if (isset($byId[$sid])) {
                    $out[] = '<a href="' . e((string)$byId[$sid]['url']) . '" target="_blank" rel="noopener noreferrer" '
                           . 'title="' . e((string)$byId[$sid]['title']) . '">' . e($sid) . '</a>';
                }
            }
            return $out ? implode(', ', $out) : '<span class="hint" style="margin:0">no source</span>';
        };

        echo '<div class="card"><div class="card-head"><h2 style="margin:0">' . e((string)$row['company_name']) . '</h2>'
           . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/research.php')) . '">Back to the list</a></div>'
           . '<p class="hint" style="margin:4px 0 12px">'
           . e(trim(implode(' · ', array_filter([
                (string)($row['company_country'] ?? ''),
                (string)($f['official_website'] ?? $row['company_website'] ?? ''),
                'requested from ' . match ((string)$row['trigger_source']) {
                    'chat' => 'the website chat', 'whatsapp' => 'WhatsApp', 'campaign' => 'a campaign', default => 'this panel',
                },
                admin_time((string)$row['created_at']),
            ]))))
           . '</p>';

        if ((string)$row['status'] !== 'done') {
            echo '<p><strong>' . e(match ((string)$row['status']) {
                'queued'  => 'Waiting to run.',
                'running' => 'Researching now — refresh in a minute.',
                default   => 'Could not be completed.',
            }) . '</strong> ' . e((string)($row['error'] ?? '')) . '</p>';
            if ((string)$row['status'] === 'failed') {
                echo '<form method="post" style="margin:0">' . $guard
                   . '<input type="hidden" name="action" value="retry">'
                   . '<input type="hidden" name="id" value="' . (int)$row['id'] . '">'
                   . '<button class="btn btn-small" type="submit">Try again</button></form>';
            }
            echo '</div>';
        } else {
            $prio = (string)$row['priority'];
            echo '<p style="font-size:1.6em;margin:0"><strong>' . (int)$row['score'] . '</strong>/100 · '
               . e(CompanyResearch::priorityLabel($prio)) . '</p>'
               . '<p style="margin:6px 0 0">'
               . 'Match: <strong>' . e(ucfirst((string)$row['match_level'])) . '</strong> · '
               . 'Type: <strong>' . e(CompanyResearch::PROSPECT_TYPES[(string)$row['prospect_type']] ?? '—') . '</strong> · '
               . 'Exhibition propensity: <strong>' . e(ucfirst((string)$row['propensity'])) . '</strong> · '
               . 'Timing: <strong>' . e(ucfirst((string)($f['timing'] ?? 'low'))) . '</strong> · '
               . 'Pakistan: <strong>' . (!empty($f['pakistan_verified']) ? 'verified connection' : 'potential opportunity')
               . '</strong></p>'
               . ((string)($f['summary'] ?? '') !== '' ? '<p style="margin-top:10px">' . e((string)$f['summary']) . '</p>' : '');
            echo '</div>';

            // The score, part by part — never one unexplained number.
            echo '<div class="card"><h2>How the score was reached</h2><table class="table"><thead><tr>'
               . '<th>Part</th><th>Points</th><th>Why</th><th>Sources</th></tr></thead><tbody>';
            foreach ((array)($f['components'] ?? []) as $c) {
                echo '<tr><td>' . e((string)$c['label']) . '</td>'
                   . '<td class="nowrap">' . (int)$c['points'] . ' / ' . (int)$c['max']
                   . (!empty($c['capped']) ? '<br><span class="hint" style="margin:0">capped: no source</span>' : '') . '</td>'
                   . '<td>' . e((string)$c['reason']) . '</td>'
                   . '<td>' . $srcLink((array)$c['sources']) . '</td></tr>';
            }
            echo '</tbody></table>'
               . '<p class="hint">Scores are the assistant\'s judgement from what it found, not a guarantee of anything. '
               . 'A high mark with no source behind it is cut to half.</p></div>';

            // Why they would pay to exhibit, and what to offer.
            echo '<div class="card"><h2>Why they might exhibit</h2>'
               . '<p>' . e((string)($f['why_exhibit'] ?? '') ?: 'Not established.') . '</p>';
            if (!empty($f['objectives'])) {
                echo '<p><strong>Possible objectives:</strong> ' . e(implode(' · ', (array)$f['objectives'])) . '</p>';
            }
            echo '<p><strong>Best-fitting offer:</strong> '
               . (!empty($f['best_offer']['label'])
                    ? e((string)$f['best_offer']['label']) . ' — ' . e((string)$f['best_offer']['reason'])
                    : 'None recommended.')
               . '</p>'
               . '<p><strong>How to position it:</strong> ' . e((string)($f['recommendation'] ?? '') ?: '—') . '</p>'
               . '</div>';

            // Who to talk to.
            $dm = (array)($f['decision_maker'] ?? []);
            $ct = (array)($f['contact'] ?? []);
            echo '<div class="card"><h2>Who to contact</h2><table class="table"><tbody>'
               . '<tr><th>Decision-maker</th><td>'
               . (!empty($dm['name'])
                    ? e((string)$dm['name']) . (!empty($dm['title']) ? ', ' . e((string)$dm['title']) : '')
                      . ' (' . $srcLink([(string)$dm['source']]) . ')'
                    : (!empty($dm['title']) ? 'Name not found. The person to look for: ' . e((string)$dm['title']) : 'Not found.'))
               . '</td></tr>'
               . '<tr><th>Email</th><td>'
               . (!empty($ct['email']) ? e((string)$ct['email']) . ' (' . $srcLink([(string)$ct['email_source']]) . ')'
                                       : '<strong>CONTACT NOT VERIFIED</strong> — no address appeared in any page found.')
               . '</td></tr>'
               . '<tr><th>Phone</th><td>'
               . (!empty($ct['phone']) ? e((string)$ct['phone']) . ' (' . $srcLink([(string)$ct['phone_source']]) . ')' : 'Not found.')
               . '</td></tr></tbody></table>'
               . '<p class="hint">Only details that appear, exactly as written, in a page that was found are shown. '
               . 'Nothing is guessed.</p></div>';

            // Fact, inference, unknown — kept apart.
            echo '<div class="card"><h2>What was found</h2><h3>Facts</h3>';
            if (empty($f['facts'])) {
                echo '<p class="hint">No facts with a source.</p>';
            } else {
                echo '<ul>';
                foreach ((array)$f['facts'] as $fact) {
                    echo '<li>' . e((string)$fact['claim']) . ' — ' . $srcLink([(string)$fact['source']])
                       . ((int)$fact['tier'] === 3 ? ' <span class="pill">directory or news source</span>' : '') . '</li>';
                }
                echo '</ul>';
            }
            echo '<h3>Inferences — not to be stated as fact</h3>';
            if (empty($f['inferences'])) {
                echo '<p class="hint">None.</p>';
            } else {
                echo '<ul>';
                foreach ((array)$f['inferences'] as $inf) {
                    echo '<li>' . e((string)$inf['claim'])
                       . (!empty($inf['demoted']) ? ' <span class="pill">reported as a fact, but no source</span>' : '')
                       . '</li>';
                }
                echo '</ul>';
            }
            echo '<h3>Could not verify</h3>';
            echo empty($f['unknowns']) ? '<p class="hint">Nothing listed.</p>'
                : '<ul><li>' . implode('</li><li>', array_map('e', (array)$f['unknowns'])) . '</li></ul>';
            echo '</div>';

            if (!empty($f['objections'])) {
                echo '<div class="card"><h2>Likely objections</h2><table class="table"><tbody>';
                foreach ((array)$f['objections'] as $o) {
                    echo '<tr><th>“' . e((string)$o['objection']) . '”</th><td>' . e((string)$o['response']) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            echo '<div class="card"><h2>Sources</h2><ol>';
            foreach ($sources as $s) {
                echo '<li><a href="' . e((string)$s['url']) . '" target="_blank" rel="noopener noreferrer">'
                   . e((string)($s['title'] !== '' ? $s['title'] : $s['url'])) . '</a> '
                   . '<span class="hint" style="margin:0">' . e((string)$s['id']) . ' · '
                   . e(match ((int)$s['tier']) { 1 => 'official site', 2 => 'professional or official body', default => 'directory, news or other' })
                   . '</span></li>';
            }
            echo '</ol>';
            $links = [];
            if (!empty($row['company_id'])) {
                $links[] = '<a href="' . e(url('admin/companies.php?view=' . (int)$row['company_id'])) . '">The company record</a>';
            }
            if (!empty($row['conversation_id'])) {
                $links[] = '<a href="' . e(url('admin/conversation.php?id=' . (int)$row['conversation_id'])) . '">The conversation</a>';
                $links[] = '<a href="' . e(PersonView::url('conversation', (int)$row['conversation_id'])) . '">Everything about the person</a>';
            }
            if ($links) {
                echo '<p>' . implode(' · ', $links) . '</p>';
            }
            echo '</div>';
        }
    }
}

// ---------------------------------------------------------------------
// Research a company by hand
// ---------------------------------------------------------------------
$countryOptions = ['' => 'Not known'];
foreach (ChatIntro::countryOptions() as [$code, $cname]) {
    $countryOptions[$code] = $cname;
}
echo '<div class="card"><h2>Research a company</h2>'
   . '<form method="post">' . $guard . '<input type="hidden" name="action" value="request">'
   . '<div class="field-row">'
   . f_text('company_name', 'Company name', '', ['max' => 200])
   . f_select('country', 'Country', $countryOptions, '')
   . f_text('website', 'Website, if known', '', ['max' => 255, 'hint' => 'Makes the research faster and more reliable.'])
   . '</div>'
   . '<button class="btn btn-small" type="submit">Research</button>'
   . '<p class="hint">Uses up to ' . CompanyResearch::searchesPerCompany() . ' searches. A company researched in the last '
   . '30 days is not searched again.</p></form></div>';

// ---------------------------------------------------------------------
// The list, best prospects first
// ---------------------------------------------------------------------
$rows = db()->all(
    "SELECT * FROM company_research WHERE agent_id = ?
      ORDER BY (status = 'done') DESC, score DESC, id DESC LIMIT 200",
    [$agentId]
);

echo '<div class="card"><h2>Researched companies</h2>';
if (!$rows) {
    echo '<p class="hint">Nothing researched yet. Research runs by itself when someone in the website chat or on '
       . 'WhatsApp is clearly interested in exhibiting, or you can research a company above.</p>';
} else {
    echo '<div class="table-wrap"><table class="table"><thead><tr>'
       . '<th>Score</th><th>Priority</th><th>Company</th><th>Country</th><th>Match</th><th>Type</th>'
       . '<th>Propensity</th><th>Pakistan</th><th>Contact</th><th>Decision-maker</th><th></th>'
       . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $rf = CompanyResearch::findingsOf($r);
        $done = (string)$r['status'] === 'done';
        echo '<tr>'
           . '<td>' . ($done ? '<strong>' . (int)$r['score'] . '</strong>' : '—') . '</td>'
           . '<td>' . ($done ? e(CompanyResearch::priorityLabel((string)$r['priority']))
                             : e(match ((string)$r['status']) { 'queued' => 'Waiting', 'running' => 'Researching', default => 'Failed' })) . '</td>'
           . '<td>' . e((string)$r['company_name']) . '</td>'
           . '<td>' . e((string)($r['company_country'] ?? '')) . '</td>'
           . '<td>' . e(ucfirst((string)($r['match_level'] ?? ''))) . '</td>'
           . '<td>' . e(CompanyResearch::PROSPECT_TYPES[(string)($r['prospect_type'] ?? '')] ?? '') . '</td>'
           . '<td>' . e(ucfirst((string)($r['propensity'] ?? ''))) . '</td>'
           . '<td>' . ($done ? (!empty($rf['pakistan_verified']) ? 'Verified' : 'Potential') : '') . '</td>'
           . '<td>' . ($done ? (!empty($rf['contact_verified']) ? 'Verified' : 'Not verified') : '') . '</td>'
           . '<td>' . e((string)($rf['decision_maker']['name'] ?? ($rf['decision_maker']['title'] ?? ''))) . '</td>'
           . '<td><a href="' . e(url('admin/research.php?view=' . (int)$r['id'])) . '">Open</a></td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

admin_layout_end();
