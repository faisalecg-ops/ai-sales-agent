<?php
declare(strict_types=1);

/**
 * Blocked countries (owner, 12 Nov 2026): one list for every exhibition.
 * What a block does is described in app/domain/BlockedCountries.php.
 */

require __DIR__ . '/_admin.php';

admin_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $change = BlockedCountries::save((array)($_POST['blocked'] ?? []));
    $count  = count(BlockedCountries::codes());
    admin_flash($count === 0
        ? 'Saved. No country is blocked.'
        : 'Saved. ' . $count . ($count === 1 ? ' country is' : ' countries are') . ' blocked in every part of the sales agent.',
        $change['removed'] && !$change['added'] ? 'warn' : 'ok');
    admin_redirect('admin/blocked-countries.php');
}

$blocked = BlockedCountries::codes();
$names   = BlockedCountries::names();
$all     = CountryData::COUNTRIES;
asort($all, SORT_NATURAL | SORT_FLAG_CASE);

admin_layout_start('Blocked countries');

echo '<p class="lede">Tick a country to close it in every part of the sales agent, for <strong>every exhibition</strong>.</p>';

echo '<div class="card"><h2>What a block does</h2><ul>'
   . '<li><strong>Website chat:</strong> the country is not offered on the first card. If a person says their company is there, '
   . 'they get one polite standard reply and nothing more: no selling, no prices, no lead, no email to your team.</li>'
   . '<li><strong>WhatsApp:</strong> no first message and no follow-up to a registration or number from that country. '
   . 'A message from such a number is not answered.</li>'
   . '<li><strong>Prospecting:</strong> a company from that country is not researched and never emailed, even if an email was already approved.</li>'
   . '</ul><p class="hint">It goes by the country the person chose or said, and the country on a company or registration record. '
   . 'The IP address is not used. Leads and conversations already on file are not changed. '
   . 'The wording of the reply is under Standard messages → "Blocked country".</p></div>';

echo '<form method="post" class="card">' . Security::csrfField()
   . '<h2>Blocked now <span class="pill ' . ($names ? 'pill-bad' : '') . '">' . count($names) . '</span></h2>'
   . '<p>' . ($names ? e(implode(', ', $names)) : 'None. Every country is open.') . '</p>'
   . '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin:14px 0 10px">'
   . '<input type="text" id="bc-find" placeholder="Find a country…" aria-label="Find a country" style="max-width:260px">'
   . '<label class="small"><input type="checkbox" id="bc-only"> Show only blocked</label></div>'
   . '<div id="bc-list" data-tick-filter style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:4px 16px;max-height:460px;overflow:auto;'
   . 'border:1px solid var(--line);border-radius:6px;padding:12px">';
foreach ($all as $code => $name) {
    $on = in_array($code, $blocked, true);
    echo '<label class="small" data-name="' . e(mb_strtolower($name)) . '" style="display:flex;gap:8px;align-items:center;padding:3px 0">'
       . '<input type="checkbox" name="blocked[]" value="' . e($code) . '"' . ($on ? ' checked' : '') . '> ' . e($name) . '</label>';
}
echo '</div><p style="margin-top:14px"><button class="btn" type="submit">Save blocked countries</button></p></form>';

admin_layout_end();
