<?php
declare(strict_types=1);

/**
 * One person, everything about them: registration, website chats, WhatsApp,
 * lead, company research, and whether they may be contacted — then a single
 * history of what happened, marked with who did it.
 *
 * Opened from any of their records (?lead=, ?registration=, ?conversation=)
 * or found by searching. Read only: nothing on this page changes a record.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_login();
$agent   = admin_agent();
$agentId = (int)$agent['id'];

$seed = [];
foreach (['lead', 'registration', 'conversation'] as $key) {
    if (!empty($_GET[$key])) {
        $seed[$key] = (int)$_GET[$key];
    }
}

// ---- Search -------------------------------------------------------------
if (!$seed) {
    $q = trim((string)($_GET['q'] ?? ''));
    admin_layout_start('Find a person');

    echo '<form method="get" class="filters">'
       . f_text('q', 'Name, company, email, phone or lead reference', $q, ['type' => 'search', 'max' => 120])
       . '<button class="btn" type="submit">Search</button>'
       . '</form>';

    echo '<p class="lede">Everyone who registered, chatted on the website or answered on WhatsApp for '
       . e((string)$agent['name']) . '. Opening someone shows everything about them on one page.</p>';

    if ($q !== '') {
        $found = PersonView::search($agentId, $q);
        if (!$found) {
            echo '<div class="empty">Nobody matches “' . e($q) . '”.</div>';
        } else {
            echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
               . '<th style="padding-left:16px">Name</th><th>Company</th><th>Contact</th><th>Found in</th><th>Date</th></tr></thead><tbody>';
            foreach ($found as $f) {
                echo '<tr><td style="padding-left:16px"><a href="' . e(url('admin/person.php?' . $f['seed'])) . '">'
                   . e($f['name'] !== '' ? $f['name'] : '(no name)') . '</a></td>'
                   . '<td>' . e($f['company']) . '</td>'
                   . '<td class="small">' . e($f['contact']) . '</td>'
                   . '<td class="small muted">' . e($f['source']) . '</td>'
                   . '<td class="small muted">' . e(admin_time($f['at'], 'j M Y')) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
    }
    admin_layout_end();
    exit;
}

// ---- One person ---------------------------------------------------------
$person = PersonView::resolve($agentId, $seed);

if (!$person['leads'] && !$person['registrations'] && !$person['conversations']) {
    admin_layout_start('Person not found', ['Find a person' => url('admin/person.php')]);
    echo '<div class="alert alert-error"><p>Nothing was found for that record in this exhibition.</p></div>';
    admin_layout_end();
    exit;
}

$s = PersonView::summary($agent, $person);
$events = PersonView::timeline($agent, $person);

$channels = array_values(array_unique(array_column($events, 'channel')));
$only = (string)($_GET['only'] ?? '');
if ($only !== '' && !in_array($only, $channels, true)) {
    $only = '';
}
$shown = $only === '' ? $events : array_values(array_filter($events, static fn($e) => $e['channel'] === $only));

$title = $s['name'] !== '' ? $s['name'] : 'Unnamed visitor';
admin_layout_start($title, ['Find a person' => url('admin/person.php')]);

if ($s['blocked'] !== null) {
    echo '<div class="alert alert-error"><p><strong>Do not contact.</strong> ' . e($s['blocked']) . '</p></div>';
}
if ($s['needs_person'] !== null) {
    echo '<div class="alert alert-warn"><p><strong>A person from the team is needed.</strong> ' . e($s['needs_person']) . '</p></div>';
}

echo '<div class="grid grid-2">';

// ---- Who they are ----
$who = [
    'Company'  => $s['company'],
    'Job'      => $s['job'],
    'Email'    => $s['email'],
    'Mobile'   => $s['phone'],
    'Country'  => $s['country'],
];
echo '<div class="card"><h2>Who they are</h2><table class="table"><tbody>';
foreach ($who as $label => $value) {
    if (trim((string)$value) === '') { continue; }
    echo '<tr><td style="width:120px;color:var(--ink-soft)">' . e($label) . '</td><td>' . e((string)$value) . '</td></tr>';
}
echo '<tr><td style="color:var(--ink-soft)">Interested in</td><td>';
if ($s['interests']) {
    foreach ($s['interests'] as $i) {
        echo '<span class="pill ' . ($i === 'Visiting' ? '' : 'pill-info') . '" style="margin:0 4px 4px 0">' . e($i) . '</span>';
    }
} else {
    echo '<span class="muted">Not known yet</span>';
}
echo '</td></tr></tbody></table></div>';

// ---- Where things stand ----
$lead = $s['lead'];
$research = $s['research'];
echo '<div class="card"><h2>Where things stand</h2><table class="table"><tbody>'
   . '<tr><td style="width:120px;color:var(--ink-soft)">Registration</td><td>' . e($s['registration']) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Website chat</td><td>' . e($s['website']) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">WhatsApp</td><td>' . e($s['whatsapp']) . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Lead</td><td>'
   . ($lead !== null
        ? '<a href="' . e(url('admin/lead.php?id=' . (int)$lead['id'])) . '">' . e((string)$lead['reference']) . '</a> · '
          . e(LeadManager::STATUS_LABELS[(string)$lead['status']] ?? (string)$lead['status'])
        : '<span class="muted">Not a lead yet</span>')
   . '</td></tr>'
   . '<tr><td style="color:var(--ink-soft)">Company research</td><td>'
   . ($research !== null
        ? '<a href="' . e(url('admin/research.php?view=' . (int)$research['id'])) . '">'
          . (int)$research['score'] . '/100 · ' . e((string)$research['priority']) . '</a>'
        : '<span class="muted">Not researched</span>')
   . '</td></tr>'
   . '</tbody></table></div>';

echo '</div>';

// ---- History ----
echo '<div class="card"><div class="card-head"><h2>Everything that happened</h2></div>';

if (count($channels) > 1) {
    echo '<p class="small" style="margin:0 0 14px">Show: ';
    $base = 'admin/person.php?' . http_build_query($seed);
    echo $only === '' ? '<strong>Everything</strong>' : '<a href="' . e(url($base)) . '">Everything</a>';
    foreach ($channels as $ch) {
        echo ' · ' . ($only === $ch
            ? '<strong>' . e($ch) . '</strong>'
            : '<a href="' . e(url($base . '&only=' . rawurlencode($ch))) . '">' . e($ch) . '</a>');
    }
    echo '</p>';
}

if (!$shown) {
    echo '<div class="empty">Nothing recorded yet.</div>';
} else {
    echo '<ol class="timeline">';
    $lastDay = '';
    foreach ($shown as $ev) {
        $day = admin_time($ev['at'], 'l j F Y');
        if ($day !== $lastDay) {
            echo '<li class="tl-day">' . e($day) . '</li>';
            $lastDay = $day;
        }
        echo '<li class="tl-item tl-' . e($ev['who']) . '">'
           . '<div class="tl-meta"><span class="tl-time">' . e(admin_time($ev['at'], 'H:i')) . '</span>'
           . '<span class="tl-who">' . e(PersonView::WHO_LABELS[$ev['who']] ?? $ev['who']) . '</span>'
           . '<span class="tl-channel">' . e($ev['channel']) . '</span></div>'
           . '<div class="tl-body"><strong>' . e($ev['what']) . '</strong>'
           . ($ev['text'] !== '' ? '<div class="tl-text">' . e($ev['text']) . '</div>' : '')
           . ($ev['link'] !== null ? '<a class="small" href="' . e($ev['link']) . '">Open</a>' : '')
           . '</div></li>';
    }
    echo '</ol>';
}
echo '</div>';

// ---- The records behind this page ----
echo '<div class="card"><h2>The records behind this page</h2>'
   . '<p class="lede small">Put together from: ' . e(implode('; ', $person['linked_by']) ?: 'the record you opened') . '. '
   . 'Shared company mailboxes such as info@ are never used to link people. Nothing here is merged — '
   . 'each record stays as it is.</p><table class="table"><tbody>';
foreach ($person['leads'] as $l) {
    echo '<tr><td style="width:140px" class="muted">Lead</td><td><a href="' . e(url('admin/lead.php?id=' . (int)$l['id'])) . '">'
       . e((string)$l['reference']) . '</a>' . (!empty($l['merged_into_id']) ? ' <span class="muted">(merged)</span>' : '') . '</td>'
       . '<td class="small muted">' . e(admin_time((string)$l['created_at'], 'j M Y')) . '</td></tr>';
}
foreach ($person['registrations'] as $r) {
    echo '<tr><td class="muted">Registration</td><td>' . e(trim((string)$r['name'] . ' · ' . (string)($r['reg_type'] ?? ''), ' ·'))
       . ' <span class="small muted">#' . (int)$r['source_id'] . '</span></td>'
       . '<td class="small muted">' . e(admin_time((string)($r['registered_at'] ?? $r['imported_at']), 'j M Y')) . '</td></tr>';
}
foreach ($person['conversations'] as $c) {
    echo '<tr><td class="muted">' . ((string)$c['channel'] === 'whatsapp' ? 'WhatsApp' : 'Website chat') . '</td>'
       . '<td><a href="' . e(url('admin/conversation.php?id=' . (int)$c['id'])) . '">Conversation '
       . (int)$c['id'] . '</a> <span class="small muted">' . (int)$c['message_count'] . ' messages</span></td>'
       . '<td class="small muted">' . e(admin_time((string)$c['created_at'], 'j M Y')) . '</td></tr>';
}
echo '</tbody></table></div>';

admin_layout_end();
