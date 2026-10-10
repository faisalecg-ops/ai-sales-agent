<?php
declare(strict_types=1);

/** Each exhibition's pictures and videos: upload, build branded images, AI pictures. */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$uid = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    if ($do === 'upload') {
        $files = $_FILES['files'] ?? null;
        $n = 0;
        $errors = [];
        if (is_array($files) && is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $one = ['name' => $name, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $r = SmMedia::upload($aid, $one, sm_in('label') ?: (string)$name, $uid);
                $r['ok'] ? $n++ : $errors[] = $name . ': ' . $r['error'];
            }
        }
        if ($n) {
            SmActivity::log($aid, 'media_uploaded', $n . ' file(s)');
            sm_flash("$n file(s) added to the library.");
        }
        foreach ($errors as $err) {
            sm_flash($err, 'error');
        }
        if (!$n && !$errors) {
            sm_flash('Choose one or more files first.', 'error');
        }
        sm_redirect('media.php');
    }
    if ($do === 'build') {
        $r = SmMedia::build($agent, sm_in('headline'), sm_in('shape'), (int)sm_in('photo_id') ?: null, $uid);
        sm_flash($r['ok'] ? 'Branded image added to the library.' : (string)$r['error'], $r['ok'] ? 'ok' : 'error');
        sm_redirect('media.php');
    }
    if ($do === 'ai') {
        $bytes = SmAi::image($agent, sm_in('prompt'), sm_in('shape') === 'portrait' ? 'portrait' : (sm_in('shape') === 'square' ? 'square' : 'landscape'));
        if (is_array($bytes)) {
            sm_flash((string)$bytes['_error'], 'error');
        } else {
            $r = SmMedia::store($aid, $bytes, 'AI picture: ' . mb_substr(sm_in('prompt'), 0, 150), 'ai', $uid);
            sm_flash($r['ok'] ? 'AI picture added to the library.' : (string)$r['error'], $r['ok'] ? 'ok' : 'error');
        }
        sm_redirect('media.php');
    }
    if ($do === 'logo' && sm_can('admin')) {
        $s = SmEvent::settings($aid);
        $s['logo_media_id'] = (int)sm_in('id');
        SmEvent::saveSettings($aid, $s);
        sm_flash('This picture is now the event logo on built images.');
        sm_redirect('media.php');
    }
    if ($do === 'delete') {
        $ok = SmMedia::delete($aid, (int)sm_in('id'));
        sm_flash($ok ? 'Deleted.' : 'It is used by a post or an article that is not finished, so it was kept.', $ok ? 'ok' : 'warn');
        sm_redirect('media.php');
    }
    sm_redirect('media.php');
}

$kind = sm_in('kind');
$rows = db()->all('SELECT * FROM sm_media WHERE agent_id = ?' . (in_array($kind, ['upload', 'built', 'ai'], true) ? ' AND origin = ?' : '') . ' ORDER BY id DESC LIMIT 200',
    in_array($kind, ['upload', 'built', 'ai'], true) ? [$aid, $kind] : [$aid]);
$settings = SmEvent::settings($aid);
$photos = ['' => 'None (plain background)'];
foreach (db()->all("SELECT id, label FROM sm_media WHERE agent_id = ? AND kind = 'image' AND origin <> 'built' ORDER BY id DESC LIMIT 80", [$aid]) as $m) {
    $photos[(string)$m['id']] = '#' . $m['id'] . ' ' . mb_strimwidth((string)$m['label'], 0, 60, '…');
}

sm_layout_start('Pictures and videos');
if (sm_can('editor')) {
    echo '<div class="grid grid-3">'
       . '<div class="card"><h2>Add pictures or videos</h2><form method="post" enctype="multipart/form-data">' . sm_csrf() . '<input type="hidden" name="do" value="upload">'
       . '<div class="field"><label for="f_files">Files</label><input type="file" id="f_files" name="files[]" multiple accept="image/*,video/mp4,video/quicktime,video/webm"></div>'
       . f_text('label', 'Description (optional)', '', ['placeholder' => 'e.g. Hall 2, opening day 2026'])
       . '<p class="hint">Past show photos, hall pictures, logos, finished designs. Pictures up to 15 MB, videos up to 200 MB (if the server allows).</p>'
       . '<button class="btn" type="submit">Upload</button></form></div>'
       . '<div class="card"><h2>Build a branded image</h2><form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="build">'
       . f_text('headline', 'Headline', '', ['maxlength' => 90, 'required' => true])
       . f_select('photo_id', 'Photo behind it', $photos, '')
       . f_select('shape', 'Shape', array_map(static fn($s) => $s[2], SmMedia::SHAPES), 'square')
       . '<button class="btn" type="submit">Build</button></form><p class="hint">The event\'s colours, name, dates, logo and the slanted band are added automatically.</p></div>';
    echo '<div class="card"><h2>AI picture</h2>';
    if (!$settings['ai_images']) {
        echo '<p class="muted">Switched off for this exhibition (Event settings).</p>';
    } elseif (!SmAi::imagesReady()) {
        echo '<p class="muted">Needs an OpenAI key in the sales agent\'s Settings.</p>';
    } else {
        echo '<form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="ai">'
           . f_area('prompt', 'Describe a general illustration', '', ['rows' => 3])
           . f_select('shape', 'Shape', ['square' => 'Square', 'portrait' => 'Portrait', 'landscape' => 'Landscape'], 'square')
           . '<p class="hint">Concepts and scenes only — never real machinery models, people or logos. ' . SmAi::used($aid, 'image') . ' of ' . SmAi::limit($aid, 'image') . ' used this month.</p>'
           . '<button class="btn" type="submit" data-busy="Making the picture…">Make it</button></form>';
    }
    echo '</div></div>';
}

echo '<nav class="set-nav">';
foreach (['' => 'All', 'upload' => 'Uploaded', 'built' => 'Built', 'ai' => 'AI pictures'] as $k => $l) {
    echo '<a href="' . e(sm_url('media.php', $k !== '' ? ['kind' => $k] : [])) . '"' . ($k === $kind ? ' aria-current="page"' : '') . '>' . e($l) . '</a>';
}
echo '</nav>';
if (!$rows) {
    echo '<div class="empty"><p>The library is empty. Upload past show photos and the event logo first: they are used behind the branded images.</p></div>';
} else {
    echo '<div class="sm-media-grid">';
    foreach ($rows as $m) {
        $isLogo = (int)$settings['logo_media_id'] === (int)$m['id'];
        echo '<figure class="sm-media">'
           . ($m['kind'] === 'video' ? '<video src="' . e(SmMedia::url($m)) . '" preload="metadata" controls></video>' : '<img src="' . e(SmMedia::url($m)) . '" alt="" loading="lazy">')
           . '<figcaption><span class="small">#' . (int)$m['id'] . ' · ' . e((string)$m['label']) . '</span><br><span class="muted small">'
           . e(['upload' => 'Uploaded', 'built' => 'Built', 'ai' => 'AI'][$m['origin']]) . ($m['width'] ? ' · ' . (int)$m['width'] . '×' . (int)$m['height'] : '') . ($isLogo ? ' · <b>event logo</b>' : '') . '</span>'
           . '<span class="sm-media-actions"><a class="btn btn-small btn-quiet" href="' . e(SmMedia::url($m)) . '" download>Download</a>';
        if (sm_can('admin') && $m['kind'] === 'image' && !$isLogo) {
            echo sm_button('media.php', ['do' => 'logo', 'id' => $m['id']], 'Use as logo', 'btn btn-small btn-quiet');
        }
        if (sm_can('editor')) {
            echo sm_button('media.php', ['do' => 'delete', 'id' => $m['id']], 'Delete', 'btn btn-small btn-danger', 'Delete this file?');
        }
        echo '</span></figcaption></figure>';
    }
    echo '</div>';
}
sm_layout_end();
