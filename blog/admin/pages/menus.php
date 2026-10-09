<?php
/** The header menu and the footer menu. */
require_perm('settings');

$clean = static function (array $labels, array $urls): array {
    $out = [];
    foreach ($labels as $i => $label) {
        $label = trim(mb_substr((string)$label, 0, 60));
        $u = trim((string)($urls[$i] ?? ''));
        if ($label === '' || $u === '') {
            continue;
        }
        if (str_starts_with($u, '/')) {
            $u = rtrim((string)preg_replace('#^(https?://[^/]+).*$#', '$1', base_url()), '/') . $u;
        }
        if (is_web_url($u)) {
            $out[] = ['label' => $label, 'url' => $u];
        }
    }
    return array_slice($out, 0, 12);
};

if (is_post()) {
    post_guard();
    Settings::set('main_menu', $clean((array)in('main_label', []), (array)in('main_url', [])));
    Settings::set('footer_menu', $clean((array)in('foot_label', []), (array)in('foot_url', [])));
    Cache::clear();
    Activity::log('menus.updated');
    flash('ok', 'Menus saved.');
    back_to('menus');
}

$cats = db()->all('SELECT name, slug FROM {p}categories ORDER BY sort, name');
$quick = array_map(static fn($c) => ['label' => $c['name'], 'url' => url($c['slug'] . '/')], $cats);
foreach (['main_site_url' => 'Home (main website)', 'register_url' => 'Register to visit', 'book_stand_url' => 'Book a stand'] as $k => $l) {
    if ($u = Event::get($k)) {
        $quick[] = ['label' => $l, 'url' => $u];
    }
}

$menuEditor = static function (string $prefix, array $items) use ($quick): string {
    $items[] = ['label' => '', 'url' => ''];
    $h = '<ol class="menu-rows" data-menurows="' . $prefix . '">';
    foreach ($items as $m) {
        $h .= '<li class="menu-row"><span class="sortable__grip" aria-hidden="true">⋮⋮</span><input name="' . $prefix . '_label[]" value="' . e($m['label']) . '" placeholder="Label" maxlength="60" aria-label="Label">'
            . '<input name="' . $prefix . '_url[]" value="' . e($m['url']) . '" placeholder="https://…" aria-label="Address">'
            . '<button type="button" class="btn btn--small btn--quiet" data-rowup aria-label="Move up">↑</button><button type="button" class="btn btn--small btn--quiet" data-rowdel aria-label="Remove">✕</button></li>';
    }
    $h .= '</ol><div class="row-btns"><button type="button" class="btn btn--small btn--ghost" data-rowadd="' . $prefix . '">+ Add item</button>';
    if ($quick) {
        $h .= '<select data-quickadd="' . $prefix . '" aria-label="Quick add"><option value="">Quick add…</option>';
        foreach ($quick as $q) {
            $h .= '<option value="' . e($q['url']) . '" data-label="' . e($q['label']) . '">' . e($q['label']) . '</option>';
        }
        $h .= '</select>';
    }
    return $h . '</div>';
};

ob_start(); ?>
<form method="post">
  <?= Auth::csrfField() ?>
  <section class="card">
    <h2>Header menu</h2>
    <p class="help">Usually the categories. Up to 12 items. On phones it folds into the ☰ button.</p>
    <?= $menuEditor('main', (array)Settings::get('main_menu', [])) ?>
  </section>
  <section class="card">
    <h2>Footer menu</h2>
    <p class="help">Useful pages of the main event website: exhibitor list, conference, sponsorship, contact.</p>
    <?= $menuEditor('foot', (array)Settings::get('footer_menu', [])) ?>
  </section>
  <div class="savebar"><button class="btn btn--primary">Save menus</button></div>
</form>
<?php admin_render('Menus', (string)ob_get_clean(), 'menus');
