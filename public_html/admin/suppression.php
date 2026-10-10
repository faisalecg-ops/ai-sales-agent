<?php
declare(strict_types=1);

/**
 * The do-not-contact list.
 *
 * Deliberately its own screen rather than a setting buried inside campaigns.
 * It is checked at the moment anything is sent, it outlives the records it
 * came from, and someone should be able to find it without knowing where to
 * look.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_access('manage');
$agent   = admin_agent();
$agentId = (int)$agent['id'];
$actor   = (string)$user['username'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $kind   = Suppression::normaliseKind((string)($_POST['kind'] ?? ''));
        $raw    = trim((string)($_POST['value'] ?? ''));
        $reason = Validator::text($_POST['reason'] ?? null, 255);

        if ($kind === null) {
            admin_flash('Choose what kind of entry this is.', 'error');
        } elseif (Suppression::normaliseValue($kind, $raw) === null) {
            admin_flash(match ($kind) {
                'email'  => 'That does not look like an email address.',
                'domain' => 'That does not look like a website address.',
                default  => 'That does not look like a phone number. Include the country code.',
            }, 'error');
        } else {
            Suppression::add($agentId, $kind, $raw, $reason, 'manual', $actor);
            admin_flash('Added. Nothing will be sent to it from this exhibition again.');
        }
        admin_redirect('admin/suppression.php');
    }

    if ($action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if (Suppression::remove($agentId, $id, $actor)) {
            admin_flash('Removed. That address can be contacted again.', 'warn');
        }
        admin_redirect('admin/suppression.php');
    }

    admin_redirect('admin/suppression.php');
}

$entries = Suppression::all($agentId);

admin_layout_start('Do not contact');

echo '<p class="lede">Nothing on this list is ever contacted by this exhibition, on any channel, '
   . 'by any campaign. It is checked at the moment of sending, not when a message is written, '
   . 'so adding someone here stops a message that was already approved.</p>';

echo '<div class="card"><h2>How entries get here</h2>'
   . '<ul><li><strong>Someone opted out</strong> — added automatically, immediately</li>'
   . '<li><strong>You marked a company do not contact</strong> — its addresses are added too</li>'
   . '<li><strong>A message bounced repeatedly</strong> — added to stop wasting sends</li>'
   . '<li><strong>You added it here</strong></li></ul>'
   . '<p class="hint">Entries stay even if the company record is deleted. Forgetting that '
   . 'someone asked not to be contacted would be the worst possible outcome, so the list '
   . 'is kept separately on purpose.</p></div>';

// ---- Add ----
echo '<form method="post" class="card">' . Security::csrfField()
   . '<input type="hidden" name="action" value="add">'
   . '<h2>Add an entry</h2>'
   . '<div class="field-row">'
   . f_select('kind', 'Type', [
        'email'  => 'Email address',
        'domain' => 'Whole company website',
        'phone'  => 'Phone number',
     ], 'email')
   . f_text('value', 'Value', '', ['required' => true, 'max' => 255,
        'hint' => 'A website blocks everyone at that company. A phone number needs its country code.'])
   . '</div>'
   . f_text('reason', 'Reason (optional)', '', ['max' => 255])
   . '<div class="btn-row"><button class="btn" type="submit">Add to the list</button></div>'
   . '</form>';

// ---- List ----
if (!$entries) {
    echo '<div class="card"><h2>The list is empty</h2>'
       . '<p class="lede">Nobody has opted out and nothing has been blocked by hand.</p></div>';
    admin_layout_end();
    exit;
}

echo '<div class="card" style="padding:0"><div class="table-wrap"><table class="table" style="margin:0"><thead><tr>'
   . '<th style="padding-left:16px">Type</th><th>Value</th><th>Why</th><th>Added</th><th></th>'
   . '</tr></thead><tbody>';

$kindLabels = ['email' => 'Email', 'domain' => 'Website', 'phone' => 'Phone'];
$sourceLabels = [
    'opt_out'   => 'They opted out',
    'manual'    => 'Added by hand',
    'bounce'    => 'Kept bouncing',
    'complaint' => 'Complaint',
    'import'    => 'Imported',
];

foreach ($entries as $row) {
    $source = (string)$row['source'];
    $why    = (string)($row['reason'] ?? '') !== ''
        ? (string)$row['reason']
        : ($sourceLabels[$source] ?? $source);

    echo '<tr><td style="padding-left:16px">' . e($kindLabels[(string)$row['kind']] ?? (string)$row['kind']) . '</td>'
       . '<td class="mono">' . e((string)$row['value']) . '</td>'
       . '<td class="small">' . e($why)
       . ($source === 'opt_out' ? ' <span class="pill pill-bad">Opted out</span>' : '') . '</td>'
       . '<td class="small">' . e((string)$row['created_at']) . '</td>'
       . '<td style="padding-right:16px">';

    // An opt-out is the person's own decision, so removing it is made
    // deliberately awkward rather than a one-click action alongside the rest.
    $question = $source === 'opt_out'
        ? 'This person asked not to be contacted. Remove them from the list anyway?'
        : 'Remove this entry? It can be contacted again afterwards.';

    echo '<form method="post" style="margin:0" data-confirm="' . e($question) . '">' . Security::csrfField()
       . '<input type="hidden" name="action" value="remove">'
       . '<input type="hidden" name="id" value="' . (int)$row['id'] . '">'
       . '<button class="btn btn-small btn-quiet" type="submit">Remove</button></form>'
       . '</td></tr>';
}

echo '</tbody></table></div></div>';

admin_layout_end();
