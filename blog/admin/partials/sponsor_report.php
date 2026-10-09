<?php
/** @var array $r  A sponsor's report, designed to print on one page (or Save as PDF). */
$logo = Media::find((int)Settings::get('logo_light', 0));
$theme = Settings::theme();
$maxV = max(1, ...array_map(static fn($d) => (int)$d['views'], $r['days'] ?: [['views' => 1]]));
$fmt = static fn(string $d) => (new DateTimeImmutable($d))->format('j M Y');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sponsor report — <?= e($r['sponsor']) ?> — <?= e(Event::name()) ?></title>
<meta name="robots" content="noindex">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#141414;margin:0;background:#f4f4f2}
.page{max-width:800px;margin:24px auto;background:#fff;padding:40px;border-top:6px solid <?= e($theme['primary']) ?>}
header{display:flex;justify-content:space-between;align-items:center;gap:20px;border-bottom:1px solid #ddd;padding-bottom:18px}
header img{max-height:56px}h1{font-family:Georgia,serif;font-size:28px;margin:22px 0 4px}.muted{color:#666}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:24px 0}.kpi{border:1px solid #e3e3e3;padding:16px}.kpi b{display:block;font-size:30px;font-family:Georgia,serif}
table{width:100%;border-collapse:collapse;font-size:14px;margin-top:10px}th,td{padding:7px 8px;border-bottom:1px solid #eee;text-align:left}th{background:#f6f6f4}td.n,th.n{text-align:right}
.bar{height:8px;background:<?= e($theme['primary']) ?>;opacity:.8}h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;margin-top:28px}
.foot{margin-top:28px;font-size:12px;color:#666;border-top:1px solid #eee;padding-top:12px}
.tools{max-width:800px;margin:16px auto 0;text-align:right}.tools button{background:#141414;color:#fff;border:0;padding:9px 16px;cursor:pointer}
@media print{body{background:#fff}.page{margin:0;box-shadow:none;padding:0;border-top:0}.tools{display:none}}
</style></head><body>
<div class="tools"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="page">
  <header><?php if ($logo): ?><img src="<?= e(Media::url($logo, 400)) ?>" alt="<?= e(Event::name()) ?>"><?php else: ?><strong><?= e(Event::name()) ?></strong><?php endif; ?><span class="muted"><?= e(preg_replace('#^https?://#', '', base_url())) ?></span></header>
  <h1>Sponsor report: <?= e($r['sponsor']) ?></h1>
  <p class="muted"><?= e($fmt($r['from'])) ?> to <?= e($fmt($r['to'])) ?></p>
  <div class="kpis">
    <div class="kpi"><b><?= number_format($r['views']) ?></b>Times shown</div>
    <div class="kpi"><b><?= number_format($r['clicks']) ?></b>Clicks to your website</div>
    <div class="kpi"><b><?= $r['ctr'] ?>%</b>Click rate</div>
  </div>
  <h2>Your banners</h2>
  <table><thead><tr><th>Banner</th><th>Place</th><th>Link</th><th class="n">Shown</th><th class="n">Clicks</th></tr></thead><tbody>
  <?php foreach ($r['banners'] as $b): $p = $r['per_banner'][(int)$b['id']] ?? ['views' => 0, 'clicks' => 0]; ?>
    <tr><td><?= e($b['title'] ?: 'Banner ' . (int)$b['id']) ?></td><td><?= e(ucfirst((string)$b['position'])) ?></td><td><?= e(preg_replace('#^https?://#', '', (string)$b['link'])) ?></td><td class="n"><?= number_format((int)$p['views']) ?></td><td class="n"><?= number_format((int)$p['clicks']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <h2>Day by day</h2>
  <?php if (!$r['days']): ?><p class="muted">No views in this period.</p><?php else: ?>
  <table><thead><tr><th>Date</th><th class="n">Shown</th><th class="n">Clicks</th><th style="width:40%"></th></tr></thead><tbody>
  <?php foreach ($r['days'] as $d): ?>
    <tr><td><?= e($fmt((string)$d['day'])) ?></td><td class="n"><?= number_format((int)$d['views']) ?></td><td class="n"><?= number_format((int)$d['clicks']) ?></td><td><div class="bar" style="width:<?= round((int)$d['views'] / $maxV * 100) ?>%"></div></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <p class="foot">“Shown” counts each time a reader's browser displayed the banner; “clicks” counts readers who opened your link. Automated visits by search engines are excluded. Report produced <?= e(local_date(now_utc(), 'j F Y')) ?> by <?= e(Event::name()) ?>.</p>
</div></body></html>
