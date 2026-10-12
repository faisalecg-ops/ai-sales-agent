<?php
/** Sponsored articles: the list with their figures, each sponsor's report, and the label wording. */
require_perm('sponsors');

// --- Sponsor report for one article: printable page (save as PDF) or spreadsheet file ---
if (isset($_GET['report'])) {
    $a = Article::find((int)$_GET['report']);
    if (!$a || !Sponsored::is($a)) {
        flash('err', 'That article is not a sponsored article.');
        back_to('sponsored');
    }
    [$dFrom, $dTo] = Sponsored::defaultPeriod($a);
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : $dFrom;
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : $dTo;
    $r = Sponsored::report((int)$a['id'], $from, $to);
    if (($_GET['format'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . slugify((string)$a['sponsor_name']) . '-article-report-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Readers (views)', 'Clicks to outside links']);
        foreach ($r['days'] as $d) {
            fputcsv($out, [$d['day'], (int)$d['views'], (int)$d['clicks']]);
        }
        fputcsv($out, ['Total', $r['views'], $r['clicks']]);
        exit;
    }
    // The same one-page layout as the banner reports.
    $live = $a['status'] === 'published' ? Article::urlFor($a) : '';
    $r += ['sponsor' => (string)$a['sponsor_name'], 'banners' => [], 'per_banner' => []];
    $articleReport = ['title' => (string)$a['title'], 'url' => $live, 'label' => Sponsored::label($a)];
    require __DIR__ . '/../partials/sponsor_report.php';
    exit;
}

if (is_post()) {
    post_guard();
    require_perm('settings');
    Settings::setMany([
        'sponsored_text' => in_str('sponsored_text', 40) ?: 'Sponsored',
        'partner_text' => in_str('partner_text', 40) ?: 'Partner content',
        'sponsored_disclosure' => in_str('sponsored_disclosure', 400),
        'partner_disclosure' => in_str('partner_disclosure', 400),
        'partner_section' => in_str('partner_section', 60) ?: 'From our partners',
    ]);
    Cache::clear();
    Activity::log('sponsored.settings');
    flash('ok', 'Wording saved. It is live now.');
    back_to('sponsored');
}

$rows = db()->all("SELECT a.*, c.slug AS cat,
    (SELECT COALESCE(SUM(v.views), 0) FROM {p}article_views v WHERE v.article_id = a.id) AS v_total,
    (SELECT COALESCE(SUM(k.clicks), 0) FROM {p}article_clicks k WHERE k.article_id = a.id) AS c_total
    FROM {p}articles a LEFT JOIN {p}categories c ON c.id = a.category_id WHERE a.sponsor_name <> '' ORDER BY COALESCE(a.sponsor_starts, a.published_at, a.created_at) DESC LIMIT 200");
$now = now_utc();
ob_start(); ?>
<section class="card">
  <p class="help">A sponsored article is an ordinary article with a sponsor filled in (article editor → “Sponsored article”). Readers see a “<?= e((string)Settings::get('sponsored_text', 'Sponsored')) ?>” or “<?= e((string)Settings::get('partner_text', 'Partner content')) ?>” label, the sponsor's name or logo and a disclosure line; its outside links are marked as sponsored for Google and their clicks are counted. An approver publishes it like any article.</p>
  <p><a class="btn btn--small" href="<?= e(aurl('article')) ?>">+ New article</a></p>
</section>
<section class="card card--flush">
  <?php if (!$rows): ?>
    <p class="empty">No sponsored articles yet.</p>
  <?php else: ?>
  <table class="table table--list"><thead><tr><th>Article</th><th>Sponsor</th><th class="hide-s">Paid period</th><th class="hide-s right">Readers</th><th class="hide-s right">Clicks</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $state = $r['status'] !== 'published' ? badge((string)$r['status'])
          : (Sponsored::running($r, $now) ? '<span class="badge badge--published">Running</span>' : (!empty($r['sponsor_starts']) && $r['sponsor_starts'] > $now ? '<span class="badge badge--scheduled">Not started</span>' : '<span class="badge badge--archived">Ended</span>')); ?>
    <tr><td><a href="<?= e(aurl('article', ['id' => $r['id']])) ?>"><strong><?= e($r['title']) ?></strong></a><br><?= $state ?></td>
      <td><?= e($r['sponsor_name']) ?><br><span class="small muted"><?= e(Sponsored::label($r)) ?></span></td>
      <td class="hide-s small"><?= $r['sponsor_starts'] ? e(local_date($r['sponsor_starts'], 'j M Y')) : 'From publishing' ?> → <?= $r['sponsor_ends'] ? e(local_date($r['sponsor_ends'], 'j M Y')) : 'no end' ?><?php if ($r['sponsor_ends'] && $r['sponsor_after'] === 'archive'): ?><br><span class="muted">then taken off</span><?php endif; ?></td>
      <td class="hide-s right"><?= number_format((int)$r['v_total']) ?></td><td class="hide-s right"><?= number_format((int)$r['c_total']) ?></td>
      <td class="right nowrap"><a class="btn btn--small btn--ghost" href="<?= e(aurl('sponsored', ['report' => $r['id']])) ?>" target="_blank" rel="noopener">Report</a>
        <a class="btn btn--small btn--quiet" href="<?= e(aurl('sponsored', ['report' => $r['id'], 'format' => 'csv'])) ?>">CSV</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<p class="help">The report covers the paid period (or from publishing until today). To choose other dates, add <code>&amp;from=2026-11-01&amp;to=2026-11-30</code> to the report's address.</p>

<?php if (Auth::can('settings')): ?>
<form method="post" class="card">
  <?= Auth::csrfField() ?>
  <h2>Wording readers see</h2>
  <div class="grid2">
    <?= f_text('sponsored_text', 'Label: paid article', (string)Settings::get('sponsored_text', 'Sponsored'), '', ['maxlength' => 40]) ?>
    <?= f_text('partner_text', 'Label: partnership', (string)Settings::get('partner_text', 'Partner content'), '', ['maxlength' => 40]) ?>
  </div>
  <?= f_area('sponsored_disclosure', 'Disclosure line: paid article', (string)Settings::get('sponsored_disclosure', ''), '{sponsor} and {event} are filled in.', 2, ['maxlength' => 400]) ?>
  <?= f_area('partner_disclosure', 'Disclosure line: partnership', (string)Settings::get('partner_disclosure', ''), '', 2, ['maxlength' => 400]) ?>
  <?= f_text('partner_section', 'Home page section heading', (string)Settings::get('partner_section', 'From our partners'), 'The section lists sponsored articles inside their paid period. Place it in the Home page builder.', ['maxlength' => 60]) ?>
  <div class="savebar"><button class="btn btn--primary">Save wording</button></div>
</form>
<?php endif;
admin_render('Sponsored articles', (string)ob_get_clean(), 'sponsored');
