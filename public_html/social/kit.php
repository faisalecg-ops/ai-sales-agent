<?php
declare(strict_types=1);

/**
 * An exhibitor's share kit: their ready post for LinkedIn and Facebook with
 * Copy buttons, and the pictures to download. Public through its private link
 * (not listed anywhere, not indexed); live only after a person approved it.
 */
require_once __DIR__ . '/lib/boot.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$token = (string)($_GET['k'] ?? '');
$ex = preg_match('/^[a-f0-9]{40}$/', $token) ? db()->one("SELECT * FROM sm_exhibitors WHERE kit_token = ? AND kit_status IN ('approved','sent')", [$token]) : null;
$agent = $ex ? db()->one("SELECT * FROM agents WHERE id = ? AND status <> 'deleted'", [(int)$ex['agent_id']]) : null;
if (!$ex || !$agent) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This kit is not available.';
    exit;
}
if (empty($ex['kit_opened_at'])) {
    db()->update('sm_exhibitors', ['kit_opened_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [(int)$ex['id']]);
}
$aid = (int)$agent['id'];
$f = SmEvent::facts($agent);
$s = SmEvent::settings($aid);
$wide = $ex['kit_media_wide'] ? SmMedia::find($aid, (int)$ex['kit_media_wide']) : null;
$sq = $ex['kit_media_square'] ? SmMedia::find($aid, (int)$ex['kit_media_square']) : null;
$blocks = [['LinkedIn', 'linkedin', $wide], ['Facebook', 'facebook', $sq ?: $wide]];
$navy = preg_match('/^#[0-9a-f]{6}$/i', (string)$s['brand_primary']) ? $s['brand_primary'] : '#142A5C';
$amber = preg_match('/^#[0-9a-f]{6}$/i', (string)$s['brand_accent']) ? $s['brand_accent'] : '#F7B10B';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Your share kit — <?= e($f['name']) ?></title>
<link rel="stylesheet" href="<?= e(sm_url('assets/kit.css')) ?>">
<style>:root{--navy:<?= e($navy) ?>;--amber:<?= e($amber) ?>}</style>
</head><body>
<header class="kh"><div class="kw"><p class="kev"><?= e($f['name']) ?><?= $f['dates'] !== '' ? ' · ' . e($f['dates']) : '' ?><?= $f['venue'] !== '' ? ' · ' . e($f['venue']) : '' ?></p>
<h1>Your share kit, <?= e((string)$ex['company']) ?></h1>
<p>Tell your customers you are exhibiting<?= SmExhibitors::standLine($ex) !== '' ? ' — ' . e(SmExhibitors::standLine($ex)) : '' ?>. Copy the text, download the picture, and post it on your company page. The link takes them to visitor registration.</p></div></header>
<main class="kw">
<?php foreach ($blocks as [$label, $key, $img]): $text = SmExhibitors::kitText($ex, $key); ?>
  <section class="kc">
    <h2><?= e($label) ?></h2>
    <?php if ($img): ?><img src="<?= e(SmMedia::url($img)) ?>" alt="<?= e('Visit ' . $ex['company'] . ' at ' . $f['name']) ?>"><?php endif; ?>
    <textarea readonly rows="8" id="t-<?= e($key) ?>"><?= e($text) ?></textarea>
    <p class="kb"><button type="button" data-copy="#t-<?= e($key) ?>">Copy the text</button>
    <?php if ($img): ?><a href="<?= e(SmMedia::url($img)) ?>" download>Download the picture</a><?php endif; ?></p>
  </section>
<?php endforeach; ?>
<p class="kn">Questions? Reply to the email this link came in, or contact the <?= e($f['name']) ?> team<?= $f['website'] !== '' ? ' at <a href="' . e($f['website']) . '">' . e(preg_replace('#^https?://(www\.)?#', '', $f['website'])) . '</a>' : '' ?>.</p>
</main>
<script src="<?= e(sm_url('assets/kit.js')) ?>" defer></script>
</body></html>
