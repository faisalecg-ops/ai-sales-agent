<?php
declare(strict_types=1);

/**
 * Prospecting campaigns: setting them up, watching them work, and the queue
 * of emails waiting for a person to approve.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('manage');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];

if (!Migrations::tableExists('prospect_campaigns')) {
    admin_layout_start('Campaigns');
    echo '<div class="alert alert-warn"><p>Run the database upgrade under Set-up → Database upgrade first.</p></div>';
    admin_layout_end();
    exit;
}

$guard = Security::csrfField() . '<input type="hidden" name="agent_id" value="' . $agentId . '">';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $cid    = (int)($_POST['campaign_id'] ?? 0);

    if ((int)($_POST['agent_id'] ?? 0) !== $agentId) {
        admin_flash('That page was showing a different exhibition, so nothing was done. Check it and try again.', 'error');
        admin_redirect('admin/prospects.php');
    }
    Audit::setActor($actor, (int)$user['id']);

    if ($action === 'save_campaign') {
        $saved = Prospecting::saveCampaign($agent, $_POST, $actor, $cid > 0 ? $cid : null);
        if ($saved['errors']) {
            foreach ($saved['errors'] as $e) { admin_flash($e, 'error'); }
            admin_redirect('admin/prospects.php?' . ($cid > 0 ? 'campaign=' . $cid . '&edit=1' : 'new=1'));
        }
        admin_flash($cid > 0 ? 'Campaign saved.' : 'Campaign created. Press Start when you are ready.');
        admin_redirect('admin/prospects.php?campaign=' . (int)$saved['id']);
    }

    if (in_array($action, ['start', 'pause', 'finish'], true)) {
        $status = ['start' => 'active', 'pause' => 'paused', 'finish' => 'finished'][$action];
        if ($status === 'active' && ($why = MailProfile::problem($agent)) !== null) {
            admin_flash('Started — companies will be found and researched, but no email can be sent yet: ' . $why
                . ' Set it under Agents and embed → Prospect emails.', 'warn');
        }
        Prospecting::setCampaignStatus($agentId, $cid, $status, $actor);
        admin_flash('Campaign ' . strtolower(Prospecting::CAMPAIGN_STATUSES[$status]) . '.');
        admin_redirect('admin/prospects.php?campaign=' . $cid);
    }

    if ($action === 'discover_now') {
        $campaign = Prospecting::campaign($agentId, $cid);
        if ($campaign !== null) {
            @set_time_limit(180);
            $d = Prospecting::discoverStep($agent, $campaign);
            admin_flash($d['searched']
                ? $d['found'] . ' new compan' . ($d['found'] === 1 ? 'y' : 'ies') . ' found. (Searched: ' . $d['note'] . ')'
                : 'No search made: ' . $d['note'], $d['searched'] ? 'ok' : 'warn');
        }
        admin_redirect('admin/prospects.php?campaign=' . $cid);
    }

    if ($action === 'approve_email') {
        $why = ProspectMailer::approve($agentId, (int)($_POST['email_id'] ?? 0), $actor);
        admin_flash($why ?? 'Approved. It will go out within this exhibition\'s pace.', $why !== null ? 'error' : 'ok');
        admin_redirect('admin/prospects.php' . ($cid > 0 ? '?campaign=' . $cid : ''));
    }
}

$counts = Prospecting::counts($agentId);
$waiting = db()->all(
    "SELECT o.*, c.name AS company_name, t.id AS target_id, t.country, t.category, p.name AS campaign_name, r.score, r.priority
       FROM outreach_emails o
       JOIN prospect_targets t ON t.id = o.target_id
       JOIN companies c ON c.id = o.company_id
       JOIN prospect_campaigns p ON p.id = o.campaign_id
  LEFT JOIN company_research r ON r.id = t.research_id
      WHERE o.agent_id = ? AND o.status = 'draft'
      ORDER BY r.score DESC, o.id ASC LIMIT 100",
    [$agentId]
);

// ---- New or edit -----------------------------------------------------
$editId = (int)($_GET['campaign'] ?? 0);
if (!empty($_GET['new']) || ($editId > 0 && !empty($_GET['edit']))) {
    $c = $editId > 0 ? (Prospecting::campaign($agentId, $editId) ?? []) : [];
    $val = static fn(string $k, string $d = '') => (string)($c[$k] ?? $d);
    $list = static fn(string $k) => implode("\n", Prospecting::decodeList($c[$k] ?? null));

    admin_layout_start($editId > 0 ? 'Edit campaign' : 'New campaign', ['Back' => url('admin/prospects.php')]);
    echo '<form method="post" class="card">' . $guard
       . '<input type="hidden" name="action" value="save_campaign">'
       . '<input type="hidden" name="campaign_id" value="' . $editId . '">'
       . f_text('name', 'Campaign name', $val('name'), ['placeholder' => 'Build Asia — Gulf construction machinery'])
       . f_area('objective', 'Objective', $val('objective'), ['rows' => 3,
            'hint' => 'In a sentence or two. The AI reads this when writing each email.'])
       . '<div class="field-row">'
       . f_area('countries', 'Countries, most important first', $list('countries'), ['rows' => 6,
            'hint' => 'One per line. Leave empty to search anywhere.'])
       . f_area('categories', 'Product categories, most important first', $list('categories'), ['rows' => 6,
            'hint' => 'One per line. Leave empty to use what the exhibition covers.'])
       . '</div>'
       . f_check('countries_only', 'Only companies based in these countries', $editId === 0 || (int)($c['countries_only'] ?? 1) === 1,
            'Untick to treat the list as a priority, and keep good companies found elsewhere.')
       . f_text('company_types', 'Kinds of company', $val('company_types'), [
            'placeholder' => 'manufacturers exporters distributors',
            'hint' => 'Words added to every search. Leave empty for manufacturers, suppliers and exporters.'])
       . '<div class="field-row">'
       . f_text('max_companies', 'Most companies to find', $val('max_companies', '100'), ['type' => 'number'])
       . f_text('starts_on', 'Starts', $val('starts_on'), ['type' => 'date'])
       . f_text('ends_on', 'Ends', $val('ends_on'), ['type' => 'date'])
       . '</div>'
       . f_select('approval_mode', 'Before an email goes', [
            'manual'    => 'A person approves every email (recommended)',
            'automatic' => 'Send automatically once written and checked',
         ], $val('approval_mode', 'manual'), [
            'hint' => 'Automatic sending still keeps to the exhibition\'s daily number and interval, the '
                    . 'do-not-contact list and the emergency stop. An email the AI could not write properly '
                    . 'always waits for a person.'])
       . f_area('notes', 'Notes for the team', $val('notes'), ['rows' => 2])
       . '<div class="btn-row"><button class="btn" type="submit">Save campaign</button>'
       . '<a class="btn btn-quiet" href="' . e(url('admin/prospects.php')) . '">Cancel</a></div></form>';
    admin_layout_end();
    exit;
}

// ---- One campaign ----------------------------------------------------
if ($editId > 0) {
    $c = Prospecting::campaign($agentId, $editId);
    if ($c === null) {
        admin_layout_start('Campaign not found');
        echo '<div class="alert alert-error"><p>That campaign does not belong to this exhibition.</p></div>';
        admin_layout_end();
        exit;
    }
    $state = (string)($_GET['state'] ?? '');
    $state = isset(Prospecting::STATES[$state]) ? $state : '';
    $cCounts = Prospecting::counts($agentId, $editId);

    admin_layout_start((string)$c['name'], [
        'Edit' => url('admin/prospects.php?campaign=' . $editId . '&edit=1'),
    ]);
    echo '<p class="small" style="margin:0 0 12px"><a href="' . e(url('admin/prospects.php')) . '">← All campaigns</a></p>';

    $plan = Prospecting::searchPlan($agent, $c);
    $mailProblem = MailProfile::problem($agent);
    if ($mailProblem !== null) {
        echo '<div class="alert alert-warn"><p><strong>No email can be sent yet.</strong> ' . e($mailProblem)
           . ' <a href="' . e(url('admin/agents.php?edit=' . $agentId)) . '">Set it under Agents and embed → Prospect emails</a>.</p></div>';
    }

    echo '<div class="grid grid-2"><div class="card"><div class="card-head"><h2>Campaign</h2>'
       . '<span class="pill ' . ((string)$c['status'] === 'active' ? 'pill-ok' : '') . '">'
       . e(Prospecting::CAMPAIGN_STATUSES[(string)$c['status']] ?? (string)$c['status']) . '</span></div>'
       . '<table class="table"><tbody>'
       . '<tr><td style="width:150px;color:var(--ink-soft)">Objective</td><td>' . e((string)($c['objective'] ?? '')) . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Countries</td><td>' . e(implode(', ', Prospecting::decodeList($c['countries'])) ?: 'Anywhere')
       . ((int)$c['countries_only'] === 1 && Prospecting::decodeList($c['countries']) ? ' <span class="muted small">(only these)</span>' : '') . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Categories</td><td>' . e(implode(', ', Prospecting::categoriesFor($agent, $c))) . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Searches made</td><td>' . min((int)$c['search_cursor'], count($plan)) . ' of ' . count($plan) . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Approval</td><td>' . ((string)$c['approval_mode'] === 'automatic'
            ? 'Automatic once checked' : 'A person approves every email') . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Sends as</td><td>' . e(MailProfile::sender($agent)) . '</td></tr>'
       . '<tr><td style="color:var(--ink-soft)">Pace</td><td>' . ProspectMailer::emailsPerDay($agent) . ' emails a day, '
       . ProspectMailer::intervalMinutes($agent) . ' minutes apart · ' . Prospecting::researchPerDay($agent)
       . ' researches a day, ' . Prospecting::researchGapMinutes($agent) . ' minutes apart</td></tr>'
       . '</tbody></table><div class="btn-row">';
    $btn = static fn(string $action, string $label, string $cls = '') =>
        '<form method="post" style="margin:0">' . $guard . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="campaign_id" value="' . $editId . '">'
        . '<button class="btn btn-small ' . $cls . '" type="submit">' . e($label) . '</button></form>';
    if ((string)$c['status'] !== 'active') {
        echo $btn('start', (string)$c['status'] === 'draft' ? 'Start' : 'Resume');
    } else {
        echo $btn('pause', 'Pause', 'btn-quiet') . $btn('discover_now', 'Find companies now', 'btn-quiet');
    }
    if ((string)$c['status'] !== 'finished') {
        echo $btn('finish', 'Finish', 'btn-quiet');
    }
    echo '</div></div>';

    echo '<div class="card"><h2>Where the companies are</h2>';
    if (!$cCounts) {
        echo '<div class="empty">Nothing found yet. Once started, the campaign searches every few minutes.</div>';
    } else {
        echo '<table class="table"><tbody>';
        foreach (Prospecting::STATES as $key => $label) {
            if (empty($cCounts[$key])) { continue; }
            echo '<tr><td><a href="' . e(url('admin/prospects.php?campaign=' . $editId . '&state=' . $key)) . '">' . e($label) . '</a></td>'
               . '<td class="num">' . (int)$cCounts[$key] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div>';

    $rows = db()->all(
        'SELECT t.*, c.name AS company_name, c.domain, r.score, r.priority
           FROM prospect_targets t JOIN companies c ON c.id = t.company_id
      LEFT JOIN company_research r ON r.id = t.research_id
          WHERE t.campaign_id = ? AND t.agent_id = ?' . ($state !== '' ? ' AND t.state = ?' : '') . '
          ORDER BY t.priority_rank, r.score DESC, t.id LIMIT 300',
        $state !== '' ? [$editId, $agentId, $state] : [$editId, $agentId]
    );
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">Company</th><th>Country</th><th>Category</th><th>Score</th><th>Where it stands</th><th>By</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6" style="padding-left:16px" class="muted">None' . ($state !== '' ? ' in this state' : ' yet') . '.</td></tr>';
    }
    foreach ($rows as $t) {
        echo '<tr><td style="padding-left:16px"><a href="' . e(url('admin/prospect.php?id=' . (int)$t['id'])) . '">' . e((string)$t['company_name']) . '</a>'
           . '<div class="small muted">' . e((string)$t['domain']) . '</div></td>'
           . '<td class="small">' . e((string)($t['country'] ?? '')) . '</td>'
           . '<td class="small">' . e((string)($t['category'] ?? '')) . '</td>'
           . '<td class="num">' . ($t['score'] !== null ? (int)$t['score'] : '—') . '</td>'
           . '<td>' . e(Prospecting::STATES[(string)$t['state']] ?? (string)$t['state'])
           . ((string)($t['state_note'] ?? '') !== '' ? '<div class="small muted">' . e(mb_strimwidth((string)$t['state_note'], 0, 110, '…', 'UTF-8')) . '</div>' : '')
           . '</td><td class="small muted">' . e(PersonView::WHO_LABELS[(string)$t['state_by']] ?? (string)$t['state_by']) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    admin_layout_end();
    exit;
}

// ---- Overview --------------------------------------------------------
admin_layout_start('Campaigns', ['New campaign' => url('admin/prospects.php?new=1')]);

$mailProblem = MailProfile::problem($agent);
if ($mailProblem !== null) {
    echo '<div class="alert alert-warn"><p><strong>Emails cannot be sent for ' . e((string)$agent['name']) . ' yet.</strong> '
       . e($mailProblem) . ' <a href="' . e(url('admin/agents.php?edit=' . $agentId)) . '">Set the sender and mail server</a>.</p></div>';
}
if (!ResearchProviderFactory::make()->isConfigured()) {
    echo '<div class="alert alert-warn"><p><strong>No research service is set up</strong>, so no companies can be found. '
       . '<a href="' . e(url('admin/settings.php')) . '">Add the Tavily key under Settings</a>.</p></div>';
}

echo '<div class="card"><h2>Emails waiting for your approval</h2>';
if (!$waiting) {
    echo '<div class="empty">Nothing waiting.</div>';
} else {
    echo '<p class="lede small">Highest score first. Open one to read the research behind it, edit, rewrite, or reject. '
       . 'Approving here sends it as written, within ' . e((string)$agent['name']) . '\'s pace ('
       . ProspectMailer::emailsPerDay($agent) . ' a day, ' . ProspectMailer::intervalMinutes($agent) . ' minutes apart).</p>'
       . '<div class="table-wrap"><table class="table"><thead><tr><th>Company</th><th>Score</th><th>To</th><th>Subject</th><th></th></tr></thead><tbody>';
    foreach ($waiting as $w) {
        echo '<tr><td><a href="' . e(url('admin/prospect.php?id=' . (int)$w['target_id'])) . '">' . e((string)$w['company_name']) . '</a>'
           . '<div class="small muted">' . e(trim((string)$w['country'] . ' · ' . (string)$w['campaign_name'], ' ·')) . '</div></td>'
           . '<td class="num">' . ($w['score'] !== null ? (int)$w['score'] : '—') . '</td>'
           . '<td class="small">' . e((string)$w['to_email']) . '</td>'
           . '<td>' . e((string)$w['subject'])
           . ((int)$w['used_fallback'] === 1 ? ' <span class="pill pill-warn">standard email</span>' : '') . '</td>'
           . '<td><form method="post" style="margin:0">' . $guard
           . '<input type="hidden" name="action" value="approve_email"><input type="hidden" name="email_id" value="' . (int)$w['id'] . '">'
           . '<button class="btn btn-small" type="submit">Approve</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

$campaigns = db()->all('SELECT * FROM prospect_campaigns WHERE agent_id = ? ORDER BY FIELD(status, \'active\', \'paused\', \'draft\', \'finished\'), id DESC', [$agentId]);
echo '<div class="card"><div class="card-head"><h2>Campaigns</h2>'
   . '<a class="btn btn-small" href="' . e(url('admin/prospects.php?new=1')) . '">New campaign</a></div>';
if (!$campaigns) {
    echo '<div class="empty">No campaigns yet. A campaign says what kind of companies to look for, and where.</div>';
} else {
    echo '<div class="table-wrap"><table class="table"><thead><tr><th>Campaign</th><th>Status</th><th class="num">Found</th><th class="num">Qualified</th>'
       . '<th class="num">Emailed</th><th class="num">Replied</th></tr></thead><tbody>';
    foreach ($campaigns as $c) {
        $n = Prospecting::counts($agentId, (int)$c['id']);
        $qualified = array_sum(array_intersect_key($n, array_flip(['qualified', 'no_email', 'awaiting_approval', 'approved', 'sent',
            'email_failed', 'responded', 'interested', 'follow_up', 'not_interested', 'converted'])));
        $emailed = array_sum(array_intersect_key($n, array_flip(['sent', 'responded', 'interested', 'follow_up', 'not_interested', 'converted'])));
        $replied = array_sum(array_intersect_key($n, array_flip(['responded', 'interested', 'follow_up', 'not_interested', 'converted'])));
        echo '<tr><td><a href="' . e(url('admin/prospects.php?campaign=' . (int)$c['id'])) . '">' . e((string)$c['name']) . '</a></td>'
           . '<td>' . e(Prospecting::CAMPAIGN_STATUSES[(string)$c['status']] ?? '') . '</td>'
           . '<td class="num">' . array_sum($n) . '</td><td class="num">' . $qualified . '</td>'
           . '<td class="num">' . $emailed . '</td><td class="num">' . $replied . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

echo '<p class="small muted">Today: ' . ProspectMailer::sentToday($agent) . ' of ' . ProspectMailer::emailsPerDay($agent)
   . ' prospect emails sent. Research this month: ' . CompanyResearch::creditsThisMonth() . ' of '
   . CompanyResearch::monthlyCreditLimit() . ' search credits.</p>';

admin_layout_end();
