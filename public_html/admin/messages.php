<?php
declare(strict_types=1);

/**
 * The standard replies used when the assistant cannot or must not answer
 * normally (Section 30). These are business-approved wording, and they work
 * even when the AI provider is completely down.
 */

require __DIR__ . '/_admin.php';

$user    = admin_require_login();
$agent   = admin_agent();
$agentId = (int)$agent['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requireCsrf();
    $saved = 0;
    foreach (array_keys(Fallbacks::KEYS) as $key) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $body = Validator::paragraph($_POST[$key] ?? null, 2000);
        if ($body === null || trim($body) === '') {
            continue;
        }
        $current = Fallbacks::get($agentId, $key);
        if (trim($current) !== trim($body)) {
            Fallbacks::save($agentId, $key, $body);
            Audit::log('fallback_updated', $agentId, 'fallback_message', null, $key, $key, $current, $body);
            $saved++;
        }
    }
    admin_flash($saved > 0 ? ($saved === 1 ? 'One message updated.' : $saved . ' messages updated.') : 'Nothing changed.');
    admin_redirect('admin/messages.php');
}

$messages = Fallbacks::all($agentId);

$explain = [
    'llm_unavailable'      => 'When the AI service does not respond at all.',
    'pricing_unavailable'  => 'When someone asks about a price that is not published.',
    'human_requested'      => 'When a visitor asks to speak to a person.',
    'technical_problem'    => 'When something goes wrong at our end.',
    'rate_limited'         => 'When someone sends messages faster than we can handle.',
    'agent_unavailable'    => 'When the assistant has been taken offline automatically after repeated failures.',
    'no_matching_package'  => 'When nothing published matches what they asked for.',
    'pakistan_pricing'     => 'When a Pakistan-based company asks about price. They never see international pricing.',
    'undetermined_pricing' => 'When we do not yet know which country the company is in.',
    'conversation_limit'   => 'When a conversation has run to its maximum length.',
    'off_topic'            => 'When a question is outside what the assistant covers.',
    'knowledge_gap'        => 'When the assistant has no approved information on the subject.',
    'whatsapp_handover'    => 'Sent on WhatsApp when the assistant steps aside for your team.',
    'whatsapp_followup'    => 'A follow-up used only when the AI cannot write its own.',
    'llm_busy'             => 'Shown while the chat waits for a busy AI and tries again by itself.',
    'intro_visiting'       => 'The reply when someone picks "visiting" in the chat\'s opening questions.',
];

// Grouped by the situation, so the one you are looking for is easy to find.
$groups = [
    'msg-ai'      => ['When the AI cannot reply', 'The service is down, busy or offline, or something went wrong.',
                      ['llm_unavailable', 'llm_busy', 'agent_unavailable', 'technical_problem', 'rate_limited', 'conversation_limit']],
    'msg-prices'  => ['Prices and country', 'What visitors are told when a price cannot be shown.',
                      ['pricing_unavailable', 'no_matching_package', 'pakistan_pricing', 'undetermined_pricing']],
    'msg-answers' => ['Questions and people', 'When the assistant has no answer, or a person is needed.',
                      ['knowledge_gap', 'off_topic', 'human_requested', 'intro_visiting']],
    'msg-wa'      => ['WhatsApp', 'Wording used only on WhatsApp.', ['whatsapp_handover', 'whatsapp_followup']],
];
$placed = array_merge(...array_map(static fn($g) => $g[2], array_values($groups)));
$leftOver = array_values(array_diff(array_keys($messages), $placed));
if ($leftOver) {
    $groups['msg-other'] = ['Other messages', 'Anything not in the groups above.', $leftOver];
}


admin_layout_start('Standard messages');

$edited = count(array_filter($messages, static fn($m) => !$m['is_default']));
echo '<p class="lede">The exact words a visitor sees when the assistant cannot answer normally. They are stored '
   . 'here rather than written by the AI, so they still work when the AI service is down — and nobody is ever '
   . 'told something the business has not approved. ' . $edited . ' of ' . count($messages)
   . ' changed from the standard wording.</p>';

$nav = [];
foreach ($groups as $id => $g) {
    $nav[$id] = $g[0];
}
echo admin_page_nav($nav);

echo '<form method="post" class="settings-form">' . Security::csrfField();

foreach ($groups as $id => [$title, $intro, $keys]) {
    echo admin_card_open($id, $title, $intro);
    foreach ($keys as $key) {
        if (!isset($messages[$key])) {
            continue;
        }
        $m = $messages[$key];
        // Labels carry the placeholder note after a dash; it reads better as a hint.
        $parts = explode(' — ', (string)$m['label'], 2);
        echo '<div class="msg-row"><div class="msg-about"><label for="f_' . e($key) . '">' . e($parts[0]) . '</label>'
           . ($m['is_default'] ? '<span class="pill">Standard</span>' : '<span class="pill pill-ok">Changed</span>')
           . '<p class="hint">' . e($explain[$key] ?? '')
           . (isset($parts[1]) ? ' ' . e(ucfirst($parts[1])) . '.' : '') . '</p></div>'
           . '<textarea id="f_' . e($key) . '" name="' . e($key) . '" rows="3">' . e((string)$m['body']) . '</textarea></div>';
    }
    echo admin_card_close();
}

echo '<div class="save-bar"><span class="save-note">Saves every message on this page.</span>'
   . '<button class="btn" type="submit">Save all messages</button></div></form>';

admin_layout_end();
