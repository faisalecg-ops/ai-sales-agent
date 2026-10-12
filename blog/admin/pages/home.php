<?php
/** Home page builder: which sections appear, and in what order. */
require_perm('content');

$labels = [
    'slider'        => ['Hero slider', 'The large rotating pictures at the top. Slides are managed in Slider.', 'slides'],
    'banner_top'    => ['Sponsor banner — top', 'Wide sponsor banner(s) for the top position.', 'banners'],
    'featured'      => ['Featured articles', 'The editor\'s picks: one large story and smaller ones beside it.', 'articles'],
    'event_panel'   => ['Event panel', 'Countdown to the show with Register and Book a stand buttons.', 'event'],
    'stats'         => ['Counter strip', 'Figures that count up as readers scroll to them — set them below.', ''],
    'latest'        => ['Latest articles', 'The newest articles, with a link to all of them.', ''],
    'voices'        => ['Industry Voices', 'The newest articles by outside experts. Hidden until there is one.', 'contributors'],
    'partner'       => ['Partner content', 'Sponsored articles inside their paid period. Hidden when there are none.', 'sponsored'],
    'banner_middle' => ['Sponsor banner — middle', 'Wide sponsor banner(s) for the middle position.', 'banners'],
    'categories'    => ['Category blocks', 'A row of recent articles for each category.', 'categories'],
    'most_read'     => ['Most read', 'The five most read articles of the last 30 days.', ''],
    'tags'          => ['Tags', 'The most used topics.', ''],
    'newsletter'    => ['Newsletter sign-up', 'Shown only when the newsletter is switched on in Readers & general.', 'settings'],
];

if (is_post()) {
    post_guard();
    $order = (array)in('order', []);
    $enabled = (array)in('enabled', []);
    $sections = [];
    foreach (array_keys($labels) as $k) {
        $sections[] = ['key' => $k, 'enabled' => !empty($enabled[$k]), 'o' => (int)($order[$k] ?? 99)];
    }
    usort($sections, static fn($a, $b) => $a['o'] <=> $b['o']);
    Settings::set('home_sections', array_map(static fn($s) => ['key' => $s['key'], 'enabled' => $s['enabled']], $sections));
    Settings::set('featured_count', max(1, min(7, (int)in('featured_count', 3))));
    Settings::set('latest_count', max(3, min(24, (int)in('latest_count', 6))));
    Settings::set('home_category_ids', array_values(array_map('intval', (array)in('home_cats', []))));
    $stats = [];
    $sv = (array)in('stat_value', []);
    $ss = (array)in('stat_suffix', []);
    $sl = (array)in('stat_label', []);
    foreach ($sv as $i => $v) {
        $num = (int)preg_replace('/[^0-9]/', '', (string)$v);
        $label = mb_substr(trim((string)($sl[$i] ?? '')), 0, 60);
        if ($num > 0 && $label !== '') {
            $stats[] = ['value' => $num, 'suffix' => mb_substr(trim((string)($ss[$i] ?? '')), 0, 12), 'label' => $label];
        }
    }
    Settings::set('stats', array_slice($stats, 0, 6));
    Settings::set('stats_caption', in_str('stats_caption', 160));
    Cache::clear();
    Activity::log('home.updated');
    flash('ok', 'Home page saved. It is live now.');
    back_to('home');
}

$current = (array)Settings::get('home_sections', []);
$known = array_column($current, 'key');
foreach (array_keys($labels) as $k) {
    if (!in_array($k, $known, true)) {
        $current[] = ['key' => $k, 'enabled' => false];
    }
}
$cats = db()->all('SELECT id, name FROM {p}categories ORDER BY sort, name');
$chosen = array_map('intval', (array)Settings::get('home_category_ids', []));

ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>Sections, top to bottom</h2>
    <p class="help">Tick the sections to show. Drag them (or use the arrows) to change the order.</p>
    <ol class="sortable" data-sortable>
      <?php foreach ($current as $i => $s): if (!isset($labels[$s['key']])) continue; [$label, $help, $link] = $labels[$s['key']]; ?>
        <li class="sortable__item" draggable="true">
          <span class="sortable__grip" aria-hidden="true">⋮⋮</span>
          <input type="hidden" name="order[<?= e($s['key']) ?>]" value="<?= $i ?>" data-order>
          <label class="sortable__label"><input type="checkbox" name="enabled[<?= e($s['key']) ?>]" value="1"<?= !empty($s['enabled']) ? ' checked' : '' ?>> <strong><?= e($label) ?></strong></label>
          <span class="sortable__help"><?= e($help) ?><?php if ($link): ?> <a href="<?= e(aurl($link)) ?>">Manage →</a><?php endif; ?></span>
          <span class="sortable__move"><button type="button" data-up aria-label="Move up">↑</button><button type="button" data-down aria-label="Move down">↓</button></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <section class="card">
    <h2>How much to show</h2>
    <div class="grid2">
      <?= f_text('featured_count', 'Featured articles', (string)Settings::get('featured_count', 3), 'Mark articles as Featured in the article editor. 1–7.', ['type' => 'number', 'min' => 1, 'max' => 7]) ?>
      <?= f_text('latest_count', 'Latest articles', (string)Settings::get('latest_count', 6), '3–24. Multiples of 3 fill the rows neatly.', ['type' => 'number', 'min' => 3, 'max' => 24]) ?>
    </div>
    <div class="field"><label>Category blocks</label><p class="help">Which categories get a block. None ticked = every category that has articles.</p>
      <div class="checks"><?php foreach ($cats as $c): ?><label><input type="checkbox" name="home_cats[]" value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $chosen, true) ? ' checked' : '' ?>> <?= e($c['name']) ?></label><?php endforeach; ?></div></div>
  </section>
  <section class="card">
    <h2>Counter strip</h2>
    <p class="help">Up to six figures, e.g. <em>32000</em> <em>+</em> <em>Trade visitors</em>. Use the latest post-show report figures. Empty rows are ignored; with no figures the strip is hidden.</p>
    <?= f_text('stats_caption', 'Caption above the figures (optional)', (string)Settings::get('stats_caption', ''), 'e.g. "{event} in numbers" — {event} becomes the exhibition name.', ['maxlength' => 160]) ?>
    <table class="table stats-edit"><thead><tr><th>Number</th><th>After the number</th><th>Label</th></tr></thead><tbody>
    <?php $rows = (array)Settings::get('stats', []); for ($i = 0; $i < 6; $i++): $r = $rows[$i] ?? ['value' => '', 'suffix' => '', 'label' => '']; ?>
      <tr><td><input name="stat_value[]" value="<?= e((string)$r['value']) ?>" inputmode="numeric" placeholder="32000" aria-label="Number <?= $i + 1 ?>"></td>
        <td><input name="stat_suffix[]" value="<?= e((string)$r['suffix']) ?>" maxlength="12" placeholder="+" aria-label="After number <?= $i + 1 ?>"></td>
        <td><input name="stat_label[]" value="<?= e((string)$r['label']) ?>" maxlength="60" placeholder="Trade visitors" aria-label="Label <?= $i + 1 ?>"></td></tr>
    <?php endfor; ?>
    </tbody></table>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save home page</button> <a class="btn btn--ghost" href="<?= e(url()) ?>" target="_blank" rel="noopener">View home page ↗</a></div>
</form>
<?php admin_render('Home page builder', (string)ob_get_clean(), 'home');
