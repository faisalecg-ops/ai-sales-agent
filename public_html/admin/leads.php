<?php
declare(strict_types=1);

/**
 * Lead list, with filters and a CSV export (Section 35).
 */

require __DIR__ . '/_admin.php';

admin_require_login();
$agent   = admin_agent();
$agentId = (int)$agent['id'];

// ---- Filters -------------------------------------------------------
$status  = Validator::inList($_GET['status'] ?? null, array_keys(LeadManager::STATUS_LABELS), null);
$region  = Validator::inList($_GET['region'] ?? null, ['international', 'pakistan', 'undetermined'], null);
$flagged = !empty($_GET['flagged']);
$handoff = !empty($_GET['handoff']);
$search  = Validator::text($_GET['q'] ?? null, 80);
$from    = Validator::date($_GET['from'] ?? null);
$to      = Validator::date($_GET['to'] ?? null);

$where  = ['agent_id = ?', 'merged_into_id IS NULL'];
$params = [$agentId];

if ($status !== null) { $where[] = 'status = ?';          $params[] = $status; }
if ($region !== null) { $where[] = 'pricing_country = ?'; $params[] = $region; }
if ($flagged)         { $where[] = 'dedup_flag = 1'; }
if ($handoff)         { $where[] = 'handoff_required = 1'; }
if ($from !== null)   { $where[] = 'created_at >= ?';     $params[] = $from . ' 00:00:00'; }
if ($to !== null)     { $where[] = 'created_at <= ?';     $params[] = $to . ' 23:59:59'; }

if ($search !== null && $search !== '') {
    $where[] = '(name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR reference LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql = 'FROM leads WHERE ' . implode(' AND ', $where);

// ---- CSV export ----------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db()->all('SELECT * ' . $sql . ' ORDER BY id DESC LIMIT 5000', $params);

    Audit::log('leads_exported', $agentId, 'lead', null, count($rows) . ' rows exported');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . gmdate('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel opens it as UTF-8
    fputcsv($out, [
        'Reference', 'Captured', 'Name', 'Company', 'Email', 'Phone', 'Country',
        'Company country', 'Pricing region', 'How country was known', 'Industry',
        'Products', 'Website', 'Status', 'Pricing discussed', 'Handoff needed',
        'Handoff reason', 'Packages of interest', 'Possible duplicate', 'Source page',
    ]);
    foreach ($rows as $r) {
        $packages = [];
        foreach ((array)json_decode((string)$r['package_interest'], true) as $p) {
            $packages[] = ($p['label'] ?? '') . ' (' . ($p['price'] ?? '') . ')';
        }
        fputcsv($out, [
            $r['reference'], admin_time((string)$r['created_at'], 'Y-m-d H:i'),
            $r['name'], $r['company'], $r['email'], $r['phone'], $r['country'],
            $r['company_country'], $r['pricing_country'], $r['country_source'],
            $r['industry'], $r['product_category'], $r['website'],
            LeadManager::STATUS_LABELS[(string)$r['status']] ?? $r['status'],
            ((int)$r['pricing_discussed'] === 1 ? 'Yes' : 'No'),
            ((int)$r['handoff_required'] === 1 ? 'Yes' : 'No'),
            $r['handoff_reason'], implode('; ', $packages),
            ((int)$r['dedup_flag'] === 1 ? ($r['dedup_note'] ?: 'Yes') : 'No'),
            $r['source_url'],
        ]);
    }
    fclose($out);
    exit;
}

// ---- Page ----------------------------------------------------------
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$total   = (int)db()->value('SELECT COUNT(*) ' . $sql, $params);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);

$leads = db()->all(
    'SELECT * ' . $sql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
    $params
);

admin_layout_start('Leads');

echo '<form method="get" class="filters">'
   . '<div class="field" style="min-width:210px"><label for="f_q">Search</label>'
   . '<input type="search" id="f_q" name="q" value="' . e((string)$search) . '" placeholder="Name, company, email"></div>'
   . f_select('status', 'Status', ['' => 'Any'] + LeadManager::STATUS_LABELS, (string)$status)
   . f_select('region', 'Region', [
        '' => 'Any', 'international' => 'International',
        'pakistan' => 'Pakistan', 'undetermined' => 'Not established',
     ], (string)$region)
   . '<div class="field"><label for="f_from">From</label><input type="date" id="f_from" name="from" value="' . e((string)$from) . '"></div>'
   . '<div class="field"><label for="f_to">To</label><input type="date" id="f_to" name="to" value="' . e((string)$to) . '"></div>'
   . '<div style="display:flex;flex-direction:column;gap:2px">'
   . '<label class="small"><input type="checkbox" name="handoff" value="1"' . ($handoff ? ' checked' : '') . '> Needs handoff</label>'
   . '<label class="small"><input type="checkbox" name="flagged" value="1"' . ($flagged ? ' checked' : '') . '> Possible duplicate</label>'
   . '</div>'
   . '<button class="btn" type="submit">Filter</button>'
   . '<a class="btn btn-quiet" href="' . e(url('admin/leads.php')) . '">Clear</a>'
   . '</form>';

echo '<div class="btn-row" style="margin-bottom:14px">'
   . '<span class="muted">' . $total . ' ' . ($total === 1 ? 'lead' : 'leads') . '</span>'
   . '<a class="btn btn-small btn-quiet" href="' . e(url('admin/leads.php' . admin_query(['export' => 'csv', 'page' => null]))) . '">Download as CSV</a>'
   . '</div>';

if (!$leads) {
    echo '<div class="empty">No leads match these filters.</div>';
} else {
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0">'
       . '<thead><tr><th style="padding-left:16px">Reference</th><th>Name</th><th>Company</th>'
       . '<th>Contact</th><th>Region</th><th>Status</th><th>Captured</th><th></th></tr></thead><tbody>';

    foreach ($leads as $l) {
        $contact = trim((string)$l['email']);
        if ($contact === '') { $contact = trim((string)$l['phone']); }

        echo '<tr>'
           . '<td class="mono" style="padding-left:16px">' . e((string)$l['reference']) . '</td>'
           . '<td>' . e((string)$l['name'] ?: '—') . '</td>'
           . '<td>' . e((string)$l['company'] ?: '—') . '</td>'
           . '<td>' . e($contact ?: '—') . '</td>'
           . '<td>' . e(ucfirst((string)$l['pricing_country'])) . '</td>'
           . '<td>' . e(LeadManager::STATUS_LABELS[(string)$l['status']] ?? (string)$l['status'])
           . ((int)$l['handoff_required'] === 1 ? ' <span class="pill pill-warn">Handoff</span>' : '')
           . ((int)$l['dedup_flag'] === 1 ? ' <span class="pill">Duplicate?</span>' : '')
           . '</td>'
           . '<td class="small muted">' . e(admin_time((string)$l['created_at'])) . '</td>'
           . '<td style="padding-right:16px"><a href="' . e(url('admin/lead.php?id=' . (int)$l['id'])) . '">Open</a></td>'
           . '</tr>';
    }
    echo '</tbody></table></div></div>';

    if ($pages > 1) {
        echo '<div class="btn-row" style="margin-top:14px">';
        if ($page > 1) {
            echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/leads.php' . admin_query(['page' => $page - 1]))) . '">Previous</a>';
        }
        echo '<span class="muted small">Page ' . $page . ' of ' . $pages . '</span>';
        if ($page < $pages) {
            echo '<a class="btn btn-small btn-quiet" href="' . e(url('admin/leads.php' . admin_query(['page' => $page + 1]))) . '">Next</a>';
        }
        echo '</div>';
    }
}

admin_layout_end();
