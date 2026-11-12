<?php
declare(strict_types=1);

/**
 * Website pages: what the assistant knows about the exhibition's own website
 * (owner's plan approved 12 November 2026, part 5). The pages are read from
 * the website's sitemap once a week; any page can be switched off here.
 */

require __DIR__ . '/_admin.php';
admin_require_access('manage');

$agent   = admin_agent();
$agentId = (int)$agent['id'];

if (!SitePages::ready()) {
    admin_layout_start('Website pages');
    echo '<div class="alert"><p>Run <a href="' . e(url('admin/upgrade.php')) . '">Set-up → Database upgrade</a> first.</p></div>';
    admin_layout_end();
    exit;
}

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'read') {
        @set_time_limit(90);
        $begun = SitePages::begin($agent);
        if (!$begun['ok']) {
            $flash = ['bad', (string)$begun['error']];
        } else {
            $done = SitePages::work($agent, 25);
            Audit::log('site_pages_read', $agentId, 'agent', $agentId, $begun['queued'] . ' pages found');
            $flash = ['ok', $begun['queued'] . ' pages found on the website; ' . $done['read'] . ' read so far.'
                . ($done['left'] > 0 ? ' The other ' . $done['left'] . ' are read by the scheduled task within the hour, or press "Continue reading".' : '')];
        }
    } elseif ($action === 'continue') {
        @set_time_limit(90);
        $done = SitePages::work($agent, 25);
        $flash = ['ok', $done['read'] . ' more page(s) read; ' . $done['left'] . ' to go.'];
    } elseif ($action === 'toggle') {
        $pageId = (int)($_POST['page_id'] ?? 0);
        $row = db()->one('SELECT id, active, url FROM site_pages WHERE id = ? AND agent_id = ?', [$pageId, $agentId]);
        if ($row !== null) {
            db()->update('site_pages', ['active' => (int)$row['active'] === 1 ? 0 : 1], 'id = ?', [$pageId]);
            Audit::log('site_page_toggled', $agentId, 'site_page', $pageId,
                ((int)$row['active'] === 1 ? 'Switched off: ' : 'Switched on: ') . (string)$row['url']);
        }
        header('Location: ' . url('admin/website.php' . admin_query([])));
        exit;
    }
}

$status = SitePages::status($agentId);
$search = Validator::text($_GET['q'] ?? null, 120);
$where  = 'agent_id = ?';
$params = [$agentId];
if ($search !== null && $search !== '') {
    $where .= ' AND (title LIKE ? OR url LIKE ?)';
    array_push($params, '%' . $search . '%', '%' . $search . '%');
}
$pages = db()->all('SELECT id, url, title, active, note, fetched_at, CHAR_LENGTH(body) AS chars FROM site_pages WHERE '
    . $where . ' ORDER BY url LIMIT 500', $params);

admin_layout_start('Website pages');

if ($flash !== null) {
    echo '<div class="alert ' . ($flash[0] === 'ok' ? 'alert-ok' : '') . '"><p>' . e($flash[1]) . '</p></div>';
}

echo '<div class="card"><h2>What the assistant knows about the website</h2>'
   . '<p>The assistant reads <strong>' . e((string)($agent['website_url'] ?: 'the website (no address set)')) . '</strong> once a week, '
   . 'from its sitemap. When a visitor asks about something on the website, it answers from the matching page and gives the '
   . 'link when they need to do something there.</p>'
   . '<p class="small muted">Prices written on the website are never used: prices come only from Stand pricing and Sponsorship. '
   . 'A page switched off here is ignored.</p>'
   . '<p><strong>' . (int)$status['pages'] . '</strong> pages known'
   . ($status['last_read'] !== '' ? ' · last full reading ' . e(admin_time($status['last_read'])) : ' · not read yet')
   . ($status['waiting'] > 0 ? ' · <strong>' . (int)$status['waiting'] . '</strong> waiting to be read' : '') . '</p>'
   . '<form method="post" class="btn-row">' . Security::csrfField()
   . '<button class="btn" type="submit" name="action" value="read">Read the website now</button>'
   . ($status['waiting'] > 0 ? '<button class="btn btn-quiet" type="submit" name="action" value="continue">Continue reading</button>' : '')
   . '</form>'
   . ((int)($agent['site_pages_enabled'] ?? 1) !== 1
        ? '<p class="small muted">The weekly reading is switched off for this exhibition (Agents and embed → Website answers).</p>' : '')
   . '</div>';

echo '<form method="get" class="filters"><div class="field" style="min-width:260px"><label for="f_q">Search</label>'
   . '<input type="search" id="f_q" name="q" value="' . e((string)$search) . '" placeholder="Title or address"></div>'
   . '<button class="btn" type="submit">Filter</button></form>';

if (!$pages) {
    echo '<div class="empty">No pages yet. Press "Read the website now".</div>';
} else {
    echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
       . '<th style="padding-left:16px">Page</th><th>Last read</th><th>Used</th><th></th></tr></thead><tbody>';
    foreach ($pages as $p) {
        echo '<tr><td style="padding-left:16px"><strong>' . e((string)($p['title'] ?: '(no title)')) . '</strong><br>'
           . '<a class="small" href="' . e((string)$p['url']) . '" target="_blank" rel="noopener noreferrer">' . e((string)$p['url']) . '</a>'
           . ($p['note'] ? '<br><span class="pill pill-warn">Check</span> <span class="small">' . e((string)$p['note']) . '</span>' : '')
           . '</td><td class="small muted">' . e($p['fetched_at'] ? admin_time((string)$p['fetched_at']) : '—') . '</td>'
           . '<td>' . ((int)$p['active'] === 1 ? '<span class="pill pill-ok">Yes</span>' : '<span class="pill">Off</span>') . '</td>'
           . '<td style="padding-right:16px"><form method="post">' . Security::csrfField()
           . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="page_id" value="' . (int)$p['id'] . '">'
           . '<button class="btn btn-small btn-quiet" type="submit">' . ((int)$p['active'] === 1 ? 'Switch off' : 'Switch on') . '</button>'
           . '</form></td></tr>';
    }
    echo '</tbody></table></div></div>';
}

admin_layout_end();
