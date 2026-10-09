<?php
/** Logos, colours, fonts and shapes — previewed live before saving, with one-click undo. */
require_perm('settings');

$colourFields = [
    'primary' => ['Main colour', 'Category labels, links, “view all”. Usually the exhibition\'s brand colour.'],
    'secondary' => ['Second colour', 'Headlines, buttons and dark areas: top strip, event panel, counters, footer.'],
    'accent' => ['Link colour', 'Links inside articles.'],
    'highlight' => ['Highlight colour', 'Call-to-action buttons (Register, Visit website), the stripe, counter labels.'],
    'highlight_ink' => ['Text on the highlight colour', 'Usually very dark or white — whichever reads best on the highlight.'],
    'background' => ['Page background', ''],
    'surface' => ['Soft background', 'Boxes such as the newsletter and courtesy box.'],
    'text' => ['Text', ''],
    'muted' => ['Grey text', 'Dates, captions, summaries.'],
];

if (is_post()) {
    post_guard();
    if (in('do') === 'undo') {
        $prev = Settings::get('theme_previous');
        if (is_array($prev)) {
            Settings::set('theme_previous', Settings::theme());
            Settings::set('theme', $prev);
            Cache::clear();
            Activity::log('branding.undo');
            flash('ok', 'The previous look has been restored. Press Undo again to go back.');
        }
        back_to('branding');
    }
    $t = Settings::theme();
    $new = $t;
    foreach (array_keys($colourFields) as $k) {
        $c = strtoupper(in_str('c_' . $k, 7));
        if (Theme::validColour($c)) {
            $new[$k] = $c;
        }
    }
    $fonts = Settings::fonts();
    $new['heading_font'] = isset($fonts[(string)in('heading_font')]) ? (string)in('heading_font') : $t['heading_font'];
    $new['body_font'] = isset($fonts[(string)in('body_font')]) ? (string)in('body_font') : $t['body_font'];
    $new['header_style'] = in_array(in('header_style'), ['light', 'dark', 'transparent'], true) ? (string)in('header_style') : 'light';
    $new['button_shape'] = in_array(in('button_shape'), ['square', 'rounded', 'pill'], true) ? (string)in('button_shape') : 'square';
    $new['card_style'] = in_array(in('card_style'), ['rule', 'bordered', 'shadow'], true) ? (string)in('card_style') : 'rule';
    $new['radius'] = max(0, min(24, (int)in('radius')));
    $new['slider_shade'] = max(0, min(85, (int)in('slider_shade')));
    $new['preset'] = (string)in('preset', 'custom');
    $new['band'] = (bool)in('band');
    $new['motion'] = (bool)in('motion');
    if ($new !== $t) {
        Settings::set('theme_previous', $t);
        Settings::set('theme', $new);
    }
    foreach (['logo_light', 'logo_dark', 'logo_footer', 'favicon', 'share_image'] as $k) {
        Settings::set($k, (int)in($k));
    }
    Settings::set('site_name', in_str('site_name', 120) ?: (string)Settings::get('site_name'));
    Settings::set('site_tagline', in_str('site_tagline', 200));
    Cache::clear();
    Activity::log('branding.updated');
    $warn = Theme::warnings($new);
    flash($warn ? 'warn' : 'ok', 'Branding saved.' . ($warn ? ' Readability: ' . implode(' ', $warn) : ''));
    back_to('branding');
}

$t = Settings::theme();
$fonts = Settings::fonts();
$fontJs = [];
foreach ($fonts as $k => $f) {
    $fontJs[$k] = ['stack' => $f['stack'], 'family' => trim(explode(',', $f['stack'])[0], "' "),
        'variable' => !empty($f['variable']), 'stretch' => $f['stretch'] ?? '100%', 'weight' => $f['head_weight'] ?? 700,
        'files' => !empty($f['variable']) ? [['100 900', url('assets/fonts/' . $k . '-latin-variable.woff2')]]
            : array_map(static fn($w) => [$w, url('assets/fonts/' . $k . '-latin-' . $w . '-normal.woff2')], $f['weights'])];
}

ob_start(); ?>
<form method="post" class="brand-page" data-branding>
  <?= Auth::csrfField() ?>
  <div class="brand-page__controls">
    <section class="card">
      <h2>Name and logos</h2>
      <?= f_text('site_name', 'Blog name', (string)Settings::get('site_name', ''), 'Shown in browser tabs and Google, e.g. "Build Asia Blog".', ['maxlength' => 120]) ?>
      <?= f_text('site_tagline', 'Blog tagline', (string)Settings::get('site_tagline', ''), '', ['maxlength' => 200]) ?>
      <div class="grid2">
        <?= f_media('logo_light', 'Main logo (for light backgrounds)', (int)Settings::get('logo_light', 0), 'PNG with transparent background, about 400 px wide.') ?>
        <?= f_media('logo_dark', 'Logo for dark backgrounds', (int)Settings::get('logo_dark', 0), 'White version, used with the dark or transparent header.') ?>
        <?= f_media('logo_footer', 'Footer logo (optional)', (int)Settings::get('logo_footer', 0), 'Leave empty to use the dark-background logo.') ?>
        <?= f_media('favicon', 'Browser tab icon', (int)Settings::get('favicon', 0), 'Square, at least 192 × 192 px.') ?>
      </div>
      <?= f_media('share_image', 'Default image when shared on social media', (int)Settings::get('share_image', 0), 'Used when an article has no picture. 1200 × 630 px.') ?>
    </section>

    <section class="card">
      <h2>Colours</h2>
      <div class="presets"><span class="small">Start from:</span>
        <?php foreach (Settings::presets() as $k => $p): ?>
          <button type="button" class="preset" data-preset='<?= e(json_encode($p)) ?>' data-key="<?= e($k) ?>"><span style="background:<?= e($p['primary']) ?>"></span><span style="background:<?= e($p['secondary']) ?>"></span><?= e($p['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="preset" value="<?= e($t['preset']) ?>" data-presetname>
      <div class="colours">
        <?php foreach ($colourFields as $k => [$label, $help]): ?>
          <div class="colour"><label for="c_<?= $k ?>"><?= e($label) ?></label>
            <div class="colour__row"><input type="color" id="c_<?= $k ?>" value="<?= e($t[$k]) ?>" data-colour="<?= $k ?>"><input type="text" name="c_<?= $k ?>" value="<?= e($t[$k]) ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-hex="<?= $k ?>"></div>
            <?php if ($help): ?><p class="help"><?= e($help) ?></p><?php endif; ?></div>
        <?php endforeach; ?>
      </div>
      <div class="contrast" data-contrast aria-live="polite"></div>
    </section>

    <section class="card">
      <h2>Fonts and style</h2>
      <div class="grid2">
        <?= f_select('heading_font', 'Headline font', array_map(static fn($f) => $f['label'], $fonts), (string)$t['heading_font'], '', ['data-font' => 'head']) ?>
        <?= f_select('body_font', 'Text font', array_map(static fn($f) => $f['label'], $fonts), (string)$t['body_font'], '', ['data-font' => 'body']) ?>
        <?= f_select('header_style', 'Header', ['light' => 'Light', 'dark' => 'Dark (second colour)', 'transparent' => 'Transparent over the slider'], (string)$t['header_style'], '', ['data-bodyclass' => 'hdr']) ?>
        <?= f_select('card_style', 'Article cards', ['rule' => 'Fine lines (newspaper)', 'bordered' => 'Boxes with a border', 'shadow' => 'Raised with a shadow'], (string)$t['card_style'], '', ['data-bodyclass' => 'cards']) ?>
        <?= f_select('button_shape', 'Button shape', ['square' => 'Square', 'rounded' => 'Slightly rounded', 'pill' => 'Pill'], (string)$t['button_shape'], '', ['data-var' => 'btn']) ?>
        <?= f_text('radius', 'Corner roundness of images and boxes (px)', (string)$t['radius'], '', ['type' => 'range', 'min' => 0, 'max' => 24, 'data-var' => 'radius']) ?>
        <?= f_text('slider_shade', 'Darkening over slider pictures (%)', (string)$t['slider_shade'], 'More darkening makes the white text easier to read.', ['type' => 'range', 'min' => 0, 'max' => 85, 'data-var' => 'shade']) ?>
      </div>
      <?= f_check('band', 'Show the slanted stripe under the header and above the footer', !empty($t['band'])) ?>
      <?= f_check('motion', 'Scroll animations (sections rise in, figures count up, the stripe draws across)', !array_key_exists('motion', $t) || !empty($t['motion']), 'Always off for visitors whose device asks for less motion.') ?>
      <p class="help">Fonts are stored on your own website, so pages load fast with no outside font service.</p>
    </section>
    <div class="savebar">
      <button class="btn btn--primary">Save branding</button>
      <?php if (is_array(Settings::get('theme_previous'))): ?><button class="btn btn--ghost" name="do" value="undo" formnovalidate onclick="return confirm('Go back to the look before the last save?')">↶ Undo last change</button><?php endif; ?>
    </div>
  </div>
  <div class="brand-page__preview">
    <div class="preview-head"><strong>Live preview</strong> <span class="small muted">Changes show here before you save.</span>
      <span class="preview-size"><button type="button" data-pw="1280" class="is-active">Computer</button><button type="button" data-pw="390">Phone</button></span></div>
    <div class="preview-frame"><iframe src="<?= e(url()) ?>" title="Preview" data-preview loading="lazy"></iframe></div>
  </div>
</form>
<?php admin_render('Branding', (string)ob_get_clean(), 'branding', ['wide' => true,
    'scripts' => '<script>window.FONTS=' . json_encode($fontJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>']);
