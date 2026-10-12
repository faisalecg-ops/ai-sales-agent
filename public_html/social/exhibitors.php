<?php
declare(strict_types=1);

/**
 * Exhibitors and their share kits: the list (from the website, typed in, or
 * uploaded — an admin chooses), kits written and approved here, emailed to
 * each exhibitor, and which exhibitors' posts brought visitors.
 */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$uid = (int)$user['id'];
$s = SmEvent::settings($aid);

// The clicks report as a spreadsheet.
if (sm_in('csv') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/i', '-', (string)$agent['slug']) . '-exhibitor-kits.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Company', 'Stand', 'Hall', 'Email', 'Kit', 'Emailed', 'Kit opened', 'Visitors from their posts (clicks)'], ',', '"', '\\');
    // Names read from websites must never run as spreadsheet formulas.
    $cell = static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    foreach (SmExhibitors::report($aid) as $r) {
        fputcsv($out, array_map($cell, [$r['company'], $r['stand'], $r['hall'], $r['email'], $r['kit_status'], $r['kit_sent_at'], $r['kit_opened_at'], (int)$r['clicks']]), ',', '"', '\\');
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    if ($do === 'source' && sm_can('admin')) {
        $src = sm_in('exhibitor_source');
        $s['exhibitor_source'] = isset(SmExhibitors::SOURCES[$src]) ? $src : 'typed';
        $pageUrl = sm_in('exhibitor_page_url');
        $s['exhibitor_page_url'] = $pageUrl !== '' && preg_match('#^https://#i', $pageUrl) && Validator::url($pageUrl) !== null ? mb_substr($pageUrl, 0, 500) : '';
        SmEvent::saveSettings($aid, $s);
        SmActivity::log($aid, 'settings_saved', 'Exhibitor list source: ' . $s['exhibitor_source']);
        sm_flash('Saved: ' . SmExhibitors::SOURCES[$s['exhibitor_source']] . '.' . ($s['exhibitor_source'] === 'website' && $s['exhibitor_page_url'] === '' ? ' Add the exhibitor page\'s address (https://…).' : ''));
        sm_redirect('exhibitors.php');
    }
    if ($do === 'read_website' && sm_can('editor')) {
        $res = SmExhibitors::importWebsite($agent, (string)$s['exhibitor_page_url']);
        sm_flash($res['ok'] ? "Read {$res['found']} exhibitors from the website: {$res['added']} new, {$res['updated']} already here ({$res['logos']} logos copied)." : (string)$res['error'], $res['ok'] ? 'ok' : 'error');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'upload' && sm_can('editor')) {
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            sm_flash('Choose the spreadsheet file first.', 'error');
            sm_redirect('exhibitors.php');
        }
        $read = SmExhibitors::readSpreadsheet((string)$f['tmp_name'], (string)$f['name']);
        if (!$read['ok']) {
            sm_flash((string)$read['error'], 'error');
            sm_redirect('exhibitors.php');
        }
        $res = SmExhibitors::importRows($aid, $read['rows'], 'upload');
        SmActivity::log($aid, 'exhibitors_uploaded', count($read['rows']) . ' row(s)');
        sm_flash("Uploaded: {$res['added']} new exhibitor(s), {$res['updated']} updated" . ($res['logos'] ? ", {$res['logos']} logo(s) copied" : '') . '.');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'save' && sm_can('editor')) {
        $id = (int)sm_in('id');
        $logo = null;
        if (!empty($_FILES['logo']['name'])) {
            $up = SmMedia::upload($aid, $_FILES['logo'], 'Logo: ' . mb_substr(sm_in('company'), 0, 150), $uid);
            if (!$up['ok']) {
                sm_flash((string)$up['error'], 'error');
                sm_redirect('exhibitors.php', $id ? ['edit' => $id] : []);
            }
            $logo = (int)$up['id'];
        }
        $data = ['company' => sm_in('company'), 'stand' => sm_in('stand'), 'hall' => sm_in('hall'), 'website' => sm_in('website'), 'email' => sm_in('email'), 'contact_name' => sm_in('contact_name')];
        if ($id && ($ex = SmExhibitors::find($aid, $id))) {
            $web = trim($data['website']);
            $web = $web !== '' && !preg_match('#^https?://#i', $web) ? 'https://' . $web : $web;
            db()->update('sm_exhibitors', [
                'stand' => mb_substr($data['stand'], 0, 60) ?: null, 'hall' => mb_substr($data['hall'], 0, 60) ?: null,
                'website' => $web !== '' && Validator::url($web) !== null ? mb_substr($web, 0, 500) : null, 'email' => Validator::email($data['email']),
                'contact_name' => mb_substr($data['contact_name'], 0, 160) ?: null, 'logo_media_id' => $logo ?? $ex['logo_media_id'],
                // A changed stand or logo needs a new kit.
                'kit_status' => ($logo !== null || $data['stand'] !== (string)$ex['stand'] || $data['hall'] !== (string)$ex['hall']) && $ex['kit_status'] !== 'none' ? 'none' : $ex['kit_status'],
            ], 'id = ?', [$id]);
            sm_flash('Saved.');
        } else {
            $res = SmExhibitors::upsert($aid, $data + ['logo_media_id' => $logo], 'typed');
            sm_flash($res === null ? 'Enter the company name.' : ($res['added'] ? 'Exhibitor added.' : 'That company was already on the list; its details were filled in.'), $res === null ? 'error' : 'ok');
        }
        sm_redirect('exhibitors.php');
    }
    if ($do === 'kit_text' && sm_can('editor')) {
        $ex = SmExhibitors::find($aid, (int)sm_in('id'));
        if ($ex && $ex['kit_status'] !== 'none') {
            $t = json_decode((string)$ex['kit_texts'], true) ?: [];
            $t['linkedin'] = mb_substr(trim((string)($_POST['linkedin'] ?? '')), 0, 3000);
            $t['facebook'] = mb_substr(trim((string)($_POST['facebook'] ?? '')), 0, 3000);
            db()->update('sm_exhibitors', ['kit_texts' => json_encode($t, JSON_UNESCAPED_UNICODE), 'kit_status' => $ex['kit_status'] === 'sent' ? 'sent' : 'ready', 'kit_approved_by' => null], 'id = ?', [(int)$ex['id']]);
            sm_flash($ex['kit_status'] === 'sent' ? 'Saved. The exhibitor\'s kit page shows the new text.' : 'Saved. It needs approving again.');
        }
        sm_redirect('exhibitors.php', ['kit' => (int)sm_in('id')]);
    }
    if (!$ids) {
        sm_flash('Tick at least one exhibitor first.', 'warn');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'make' && sm_can('editor')) {
        if (($why = SmAi::blocked($aid, 'text')) !== null) {
            sm_flash($why, 'error');
            sm_redirect('exhibitors.php');
        }
        session_write_close();
        $res = SmExhibitors::makeKits($agent, $ids, $uid);
        @session_start();
        sm_flash($res['made'] > 0 ? $res['made'] . ' kit(s) written. Check them, then an approver approves and emails them.' . (isset($res['error']) ? ' ' . $res['error'] : '')
            : ($res['error'] ?? 'No kits were written: the ticked exhibitors already have one.'), $res['made'] > 0 ? 'ok' : 'warn');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'approve' && sm_can('approver')) {
        $n = db()->exec("UPDATE sm_exhibitors SET kit_status = 'approved', kit_approved_by = ? WHERE agent_id = ? AND kit_status = 'ready' AND id IN (" . implode(',', $ids) . ')', [$uid, $aid]);
        SmActivity::log($aid, 'kits_approved', $n . ' kit(s)');
        sm_flash($n . ' kit(s) approved. Their pages are live; email them, or copy each link.');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'send' && sm_can('approver')) {
        $res = SmExhibitors::send($agent, $ids);
        sm_flash(isset($res['error']) ? $res['error'] : $res['sent'] . ' kit(s) emailed from ' . MailProfile::sender($agent) . '.' . ($res['skipped'] ? ' ' . $res['skipped'] . ' not sent (not approved yet, or no email address).' : ''), isset($res['error']) ? 'error' : 'ok');
        sm_redirect('exhibitors.php');
    }
    if ($do === 'remove' && sm_can('editor')) {
        db()->exec('DELETE FROM sm_kit_clicks WHERE exhibitor_id IN (SELECT id FROM sm_exhibitors WHERE agent_id = ? AND id IN (' . implode(',', $ids) . '))', [$aid]);
        $n = db()->exec('DELETE FROM sm_exhibitors WHERE agent_id = ? AND id IN (' . implode(',', $ids) . ')', [$aid]);
        sm_flash($n . ' exhibitor(s) removed.');
        sm_redirect('exhibitors.php');
    }
    sm_redirect('exhibitors.php');
}

sm_layout_start('Exhibitors and share kits', ['Download the report (CSV)' => sm_url('exhibitors.php', ['csv' => 1])]);

// One kit, close up.
if (($kid = (int)sm_in('kit')) && ($ex = SmExhibitors::find($aid, $kid)) && $ex['kit_status'] !== 'none') {
    $t = json_decode((string)$ex['kit_texts'], true) ?: [];
    $wide = $ex['kit_media_wide'] ? SmMedia::find($aid, (int)$ex['kit_media_wide']) : null;
    $sq = $ex['kit_media_square'] ? SmMedia::find($aid, (int)$ex['kit_media_square']) : null;
    echo '<p><a href="' . e(sm_url('exhibitors.php')) . '">← All exhibitors</a></p><div class="card"><h2>Kit for ' . e((string)$ex['company']) . '</h2><p>' . sm_pill_kit((string)$ex['kit_status'])
       . ($ex['kit_status'] !== 'ready' ? ' <a href="' . e(SmExhibitors::kitUrl($ex)) . '" target="_blank" rel="noopener">The exhibitor\'s kit page ↗</a>' : ' <span class="muted small">The kit page goes live once approved.</span>') . '</p>'
       . '<div class="grid grid-2">' . ($wide ? '<div class="sm-kit"><img src="' . e(SmMedia::url($wide)) . '" alt=""><p class="small muted">LinkedIn picture</p></div>' : '')
       . ($sq ? '<div class="sm-kit"><img src="' . e(SmMedia::url($sq)) . '" alt="" style="max-width:360px"><p class="small muted">Facebook picture</p></div>' : '') . '</div>';
    if (sm_can('editor')) {
        echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="kit_text"><input type="hidden" name="id" value="' . (int)$ex['id'] . '">'
           . f_area('linkedin', 'LinkedIn post', (string)($t['linkedin'] ?? ''), ['rows' => 5]) . f_area('facebook', 'Facebook post', (string)($t['facebook'] ?? ''), ['rows' => 4])
           . '<p class="hint">The counted registration link and the hashtags (' . e((string)($t['hashtags'] ?? '')) . ') are added under each text.</p>'
           . '<button class="btn" type="submit">Save the texts</button></form>';
    } else {
        echo '<p style="white-space:pre-wrap">' . e((string)($t['linkedin'] ?? '')) . '</p><p style="white-space:pre-wrap">' . e((string)($t['facebook'] ?? '')) . '</p>';
    }
    echo '</div>';
    sm_layout_end();
    exit;
}

// How the list is provided.
$src = (string)$s['exhibitor_source'];
echo '<div class="card"><h2>The exhibitor list</h2>';
if (sm_can('admin')) {
    echo '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="source">'
       . f_select('exhibitor_source', 'How the list is provided', SmExhibitors::SOURCES, $src)
       . f_text('exhibitor_page_url', 'Exhibitor page on the event website (for the first way)', (string)$s['exhibitor_page_url'], ['type' => 'url', 'placeholder' => 'https://buildasia.net/exhibitors'])
       . '<button class="btn btn-quiet" type="submit">Save</button></form>';
} else {
    echo '<p>' . e(SmExhibitors::SOURCES[$src]) . '. <span class="muted small">(An admin can change this.)</span></p>';
}
if (sm_can('editor')) {
    if ($src === 'website') {
        $row = SmEvent::row($aid);
        echo '<p>Read from <a href="' . e((string)$s['exhibitor_page_url']) . '" target="_blank" rel="noopener">' . e((string)$s['exhibitor_page_url'] ?: '(no address yet)') . '</a>'
           . ($row['exhibitors_checked_at'] ? ' · last read ' . e(sm_time($row['exhibitors_checked_at'])) : '') . '. New exhibitors are picked up from the page once a week by themselves.</p>'
           . ($s['exhibitor_page_url'] !== '' ? sm_button('exhibitors.php', ['do' => 'read_website'], 'Read the page now', 'btn') : '');
    } elseif ($src === 'upload') {
        echo '<form method="post" enctype="multipart/form-data" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="upload">'
           . '<div class="field"><label for="exfile">Spreadsheet (.xlsx or .csv)</label><input type="file" id="exfile" name="file" accept=".csv,.xlsx,.txt" required></div>'
           . '<button class="btn" type="submit">Upload</button></form>'
           . '<p class="hint">First row = column names. Needed: <strong>Company</strong>. Optional: Stand, Hall, Website, Email, Contact, Logo (a web address of the logo). Uploading again adds new companies and fills in missing details; nobody is removed.</p>';
    }
    $edit = (int)sm_in('edit') ? SmExhibitors::find($aid, (int)sm_in('edit')) : null;
    if ($src === 'typed' || $edit) {
        echo '<details' . ($edit || $src === 'typed' ? ' open' : '') . '><summary><strong>' . ($edit ? 'Edit ' . e((string)$edit['company']) : 'Add an exhibitor') . '</strong></summary>'
           . '<form method="post" enctype="multipart/form-data">' . sm_csrf() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '"><div class="form-grid">'
           . f_text('company', 'Company', (string)($edit['company'] ?? ''), ['required' => true, 'maxlength' => 200] + ($edit ? ['readonly' => true] : []))
           . f_text('stand', 'Stand', (string)($edit['stand'] ?? ''), ['maxlength' => 60]) . f_text('hall', 'Hall', (string)($edit['hall'] ?? ''), ['maxlength' => 60])
           . f_text('website', 'Website', (string)($edit['website'] ?? ''), ['placeholder' => 'https://']) . f_text('email', 'Email for the kit', (string)($edit['email'] ?? ''), ['type' => 'email'])
           . f_text('contact_name', 'Contact person', (string)($edit['contact_name'] ?? ''))
           . '<div class="field"><label for="exlogo">Logo</label><input type="file" id="exlogo" name="logo" accept="image/png,image/jpeg,image/webp"></div></div>'
           . '<button class="btn" type="submit">' . ($edit ? 'Save' : 'Add') . '</button>' . ($edit ? ' <a class="btn btn-quiet" href="' . e(sm_url('exhibitors.php')) . '">Cancel</a>' : '') . '</form></details>';
    }
}
echo '</div>';

// The list, with kits and results.
$rows = SmExhibitors::report($aid);
$counts = array_count_values(array_column($rows, 'kit_status'));
echo '<div class="card"><h2>' . count($rows) . ' exhibitor(s)</h2>';
if (!$rows) {
    echo '<p class="muted">None yet.</p></div>';
    sm_layout_end();
    exit;
}
echo '<p class="small muted">Kits: ' . (int)($counts['none'] ?? 0) . ' not written · ' . (int)($counts['ready'] ?? 0) . ' waiting for approval · ' . (int)($counts['approved'] ?? 0) . ' approved · ' . (int)($counts['sent'] ?? 0) . ' emailed. '
   . 'The count is the number of times people opened the registration link in that exhibitor\'s posts.</p>'
   . '<form method="post" id="exform">' . sm_csrf()
   . '<table class="table"><thead><tr><th><input type="checkbox" data-check-all aria-label="Tick all"></th><th>Company</th><th>Stand</th><th>Kit</th><th>Email</th><th style="text-align:right">Visitors</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $logo = $r['logo_media_id'] ? SmMedia::find($aid, (int)$r['logo_media_id']) : null;
    echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '" aria-label="Tick ' . e((string)$r['company']) . '"></td>'
       . '<td>' . ($logo ? '<img src="' . e(SmMedia::url($logo)) . '" alt="" style="height:22px;vertical-align:middle;margin-right:6px">' : '') . '<strong>' . e((string)$r['company']) . '</strong>'
       . ($r['website'] ? '<br><a class="small" href="' . e((string)$r['website']) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://(www\.)?#', '', (string)$r['website'])) . '</a>' : '') . '</td>'
       . '<td class="small">' . e(SmExhibitors::standLine($r) ?: '—') . '</td>'
       . '<td>' . sm_pill_kit((string)$r['kit_status']) . ($r['kit_status'] !== 'none' ? '<br><a class="small" href="' . e(sm_url('exhibitors.php', ['kit' => $r['id']])) . '">See the kit</a>' : '')
       . ($r['last_error'] ? '<br><span class="small" style="color:var(--danger)">' . e((string)$r['last_error']) . '</span>' : '') . '</td>'
       . '<td class="small">' . e((string)($r['email'] ?? '—')) . ($r['kit_opened_at'] ? '<br><span class="muted">kit opened ' . e(sm_time($r['kit_opened_at'], 'j M')) . '</span>' : '') . '</td>'
       . '<td style="text-align:right">' . (int)$r['clicks'] . '</td>'
       . '<td>' . (sm_can('editor') ? '<a class="small" href="' . e(sm_url('exhibitors.php', ['edit' => $r['id']])) . '">Edit</a>' : '') . '</td></tr>';
}
echo '</tbody></table><div class="btn-row">';
if (sm_can('editor')) {
    echo '<button class="btn" name="do" value="make" data-busy="Writing kits…">Write kits for the ticked</button> ';
}
if (sm_can('approver')) {
    echo '<button class="btn btn-ok" name="do" value="approve">Approve the ticked kits</button> <button class="btn" name="do" value="send">Email the ticked kits</button> ';
}
if (sm_can('editor')) {
    echo '<button class="btn btn-danger" name="do" value="remove" data-confirm="Remove the ticked exhibitors and their kits?">Remove</button>';
}
echo '</div></form><p class="hint">Kits are emailed from the exhibition\'s own address (' . e(MailProfile::sender($agent)) . '). Exhibitors without an email: open "See the kit" and send them the kit page\'s link yourself.</p></div>';
sm_layout_end();

function sm_pill_kit(string $s): string
{
    return match ($s) {
        'ready' => '<span class="pill pill-warn">Waiting for approval</span>',
        'approved' => '<span class="pill pill-ok">Approved</span>',
        'sent' => '<span class="pill pill-ok">Emailed</span>',
        default => '<span class="pill">No kit yet</span>',
    };
}
