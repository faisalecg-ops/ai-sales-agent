<?php
declare(strict_types=1);

/**
 * Content plan: the agent suggests topics for the event's phase and content
 * mix; a person picks one (or types their own) and the article is written.
 */
require __DIR__ . '/_social.php';

$user = sm_page('viewer');
$agent = sm_agent();
$aid = (int)$agent['id'];
$uid = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = sm_in('do');
    if ($do === 'suggest') {
        $n = max(3, min(10, (int)sm_in('count', '6')));
        $pillar = sm_in('pillar');
        $pillar = isset(SmEvent::PILLARS[$pillar]) ? $pillar : null;
        $out = SmWriter::topics($agent, $n, $pillar);
        if (isset($out['_error'])) {
            sm_flash((string)$out['_error'], 'error');
        } else {
            $made = 0;
            foreach ((array)($out['topics'] ?? []) as $t) {
                $p = (string)($t['pillar'] ?? '');
                $title = trim((string)($t['title'] ?? ''));
                if (!isset(SmEvent::PILLARS[$p]) || $title === '') {
                    continue;
                }
                db()->insert('sm_topics', [
                    'agent_id' => $aid, 'pillar' => $p, 'title' => mb_substr($title, 0, 255),
                    'angle' => mb_substr(trim((string)($t['angle'] ?? '')) . (!empty($t['search']) ? "\nSearch: " . trim((string)$t['search']) : ''), 0, 2000),
                    'origin' => 'plan', 'created_by' => $uid,
                ]);
                $made++;
            }
            SmActivity::log($aid, 'topics_suggested', $made . ' topics');
            sm_flash($made ? "$made topics suggested. Pick the ones worth writing." : 'The AI suggested nothing usable. Try again.', $made ? 'ok' : 'warn');
        }
        sm_redirect('plan.php');
    }
    if ($do === 'add') {
        $title = mb_substr(sm_in('title'), 0, 255);
        $pillar = sm_in('pillar');
        if ($title === '' || !isset(SmEvent::PILLARS[$pillar])) {
            sm_flash('Give the topic a title and a content type.', 'error');
            sm_redirect('plan.php');
        }
        $urls = array_filter(array_map('trim', preg_split('/\s+/', sm_in('urls')) ?: []), static fn($u) => Validator::url($u) !== null && str_starts_with($u, 'https://'));
        db()->insert('sm_topics', [
            'agent_id' => $aid, 'pillar' => $pillar, 'title' => $title, 'angle' => mb_substr(sm_in('angle'), 0, 2000),
            'origin' => 'typed', 'source_urls' => implode("\n", array_slice($urls, 0, 6)) ?: null, 'status' => 'picked', 'created_by' => $uid,
        ]);
        SmActivity::log($aid, 'topic_added', $title);
        sm_flash('Topic added. Press "Write the article" when you are ready.');
        sm_redirect('plan.php');
    }
    $tid = (int)sm_in('id');
    $topic = db()->one("SELECT * FROM sm_topics WHERE id = ? AND agent_id = ? AND status IN ('suggested','picked')", [$tid, $aid]);
    if (!$topic) {
        sm_redirect('plan.php');
    }
    if ($do === 'dismiss') {
        db()->update('sm_topics', ['status' => 'dismissed'], 'id = ?', [$tid]);
        sm_redirect('plan.php');
    }
    if ($do === 'write') {
        if (($why = SmAi::blocked($aid, 'text')) !== null) {
            sm_flash($why, 'error');
            sm_redirect('plan.php');
        }
        $angle = (string)$topic['angle'];
        $search = '';
        if (preg_match('/\nSearch: (.+)$/s', $angle, $m)) {
            $search = trim($m[1]);
            $angle = trim(substr($angle, 0, -strlen($m[0])));
        }
        $topic['angle'] = $angle;
        $topic['search'] = $search;
        db()->update('sm_topics', ['angle' => $angle . ($search !== '' ? "\nSearch: $search" : '')], 'id = ?', [$tid]);
        if (db()->update('sm_topics', ['status' => 'used'], "id = ? AND status IN ('suggested','picked')", [$tid]) !== 1) {
            sm_redirect('plan.php');   // pressed twice: the first press is already writing it
        }
        $articleId = SmFlow::startArticle($agent, $topic, $uid);
        SmActivity::log($aid, 'article_started', (string)$topic['title']);
        // Answer the browser first where the server allows it; the writing takes a minute or two.
        $target = sm_url('article.php', ['id' => $articleId]);
        ignore_user_abort(true);
        $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        if ($finish !== null) {
            header('Location: ' . $target);
            echo ' ';
            session_write_close();
            $finish();
            SmFlow::write($agent, $articleId);
            exit;
        }
        session_write_close();
        SmFlow::write($agent, $articleId);
        header('Location: ' . $target);
        exit;
    }
    sm_redirect('plan.php');
}

$settings = SmEvent::settings($aid);
$phase = SmEvent::phase($agent);
$topics = db()->all("SELECT * FROM sm_topics WHERE agent_id = ? AND status IN ('suggested','picked') ORDER BY status = 'picked' DESC, id DESC LIMIT 60", [$aid]);
$pillars = array_map(static fn($p) => $p[0], SmEvent::PILLARS);

sm_layout_start('Content plan');

echo '<div class="card"><p class="lede" style="margin-top:0"><strong>' . e($phase['label']) . '.</strong> Leading now: '
   . e(implode(', ', array_map(static fn($k) => SmEvent::PILLARS[$k][0], $phase['lead']))) . '. Target: '
   . (int)$settings['articles_per_week'] . ' blog article(s) a week.</p>';
if (sm_can('editor')) {
    echo '<form method="post" class="filters">' . sm_csrf() . '<input type="hidden" name="do" value="suggest">'
       . f_select('pillar', 'Content type', ['' => 'Follow the mix and the phase'] + $pillars, '')
       . f_select('count', 'How many', ['4' => '4', '6' => '6', '8' => '8', '10' => '10'], '6')
       . '<button class="btn" type="submit" data-busy="Thinking of topics…">Suggest topics</button></form>';
}
echo '</div>';

if ($topics) {
    echo '<div class="card"><h2>Topics</h2><table class="table"><thead><tr><th>Topic</th><th>Type</th><th></th></tr></thead><tbody>';
    foreach ($topics as $t) {
        $angle = preg_replace('/\nSearch: .*$/s', '', (string)$t['angle']) ?? '';
        echo '<tr><td><strong>' . e((string)$t['title']) . '</strong>' . ($t['status'] === 'picked' ? ' ' . sm_pill('picked') : '')
           . '<br><span class="muted small">' . e($angle) . '</span>'
           . ($t['source_urls'] ? '<br><span class="small">Sources given: ' . e(str_replace("\n", ', ', (string)$t['source_urls'])) . '</span>' : '') . '</td>'
           . '<td class="small">' . e(SmEvent::PILLARS[$t['pillar']][0] ?? $t['pillar']) . '</td><td style="white-space:nowrap">';
        if (sm_can('editor')) {
            echo sm_button('plan.php', ['do' => 'write', 'id' => $t['id']], 'Write the article', 'btn btn-small', '')
               . ' ' . sm_button('plan.php', ['do' => 'dismiss', 'id' => $t['id']], 'Dismiss', 'btn btn-small btn-quiet');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table><p class="hint">Writing takes one to three minutes: the agent reads the approved knowledge, opens the best web sources, writes the article, and a second check compares it with the sources.</p></div>';
} else {
    echo '<div class="empty"><p>No topics yet. Press <strong>Suggest topics</strong>, or add your own below.</p></div>';
}

if (sm_can('editor')) {
    echo '<div class="card"><h2>Add your own topic</h2><form method="post">' . sm_csrf() . '<input type="hidden" name="do" value="add">'
       . '<div class="form-grid">' . f_text('title', 'Topic', '', ['required' => true, 'maxlength' => 255, 'placeholder' => 'e.g. What the new building code means for high-rise fire safety'])
       . f_select('pillar', 'Content type', $pillars, 'insight') . '</div>'
       . f_area('angle', 'Angle (optional)', '', ['rows' => 2, 'hint' => 'What the article should focus on and who it is for.'])
       . f_area('urls', 'Source addresses (optional)', '', ['rows' => 2, 'hint' => 'Web pages the article must be based on, one per line (https only). For curated news, put the original article here.'])
       . '<button class="btn" type="submit">Add topic</button></form></div>';
}
sm_layout_end();
