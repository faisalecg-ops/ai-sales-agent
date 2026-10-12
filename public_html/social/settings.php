<?php
declare(strict_types=1);

/** Every amount and choice for one exhibition, on one page (admins). */
require __DIR__ . '/_social.php';

sm_page('admin');
$agent = sm_agent();
$aid = (int)$agent['id'];
$s = SmEvent::settings($aid);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $mix = [];
    foreach (array_keys(SmEvent::PILLARS) as $k) {
        $mix[$k] = max(0, min(100, (int)($_POST['mix'][$k] ?? 0)));
    }
    $hex = static fn(string $v, string $d) => preg_match('/^#[0-9a-f]{6}$/i', trim($v)) ? strtoupper(trim($v)) : $d;
    $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', sm_in('reminder_emails')) ?: []), static fn($e) => Validator::email($e) !== null));
    $reportTo = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', sm_in('report_emails')) ?: []), static fn($e) => Validator::email($e) !== null)));
    $new = array_replace($s, [
        'articles_per_week' => max(0, min(21, (int)sm_in('articles_per_week'))),
        'mix'               => $mix,
        'sectors'           => mb_substr(sm_in('sectors'), 0, 1000),
        'audience'          => mb_substr(sm_in('audience'), 0, 600),
        'voice'             => mb_substr(sm_in('voice'), 0, 600),
        'avoid'             => mb_substr(sm_in('avoid'), 0, 600),
        'allow_prices'      => isset($_POST['allow_prices']),
        'writing_model'     => sm_in('writing_model') === 'background' ? 'background' : 'main',
        'text_limit'        => max(0, min(5000, (int)sm_in('text_limit'))),
        'image_limit'       => max(0, min(1000, (int)sm_in('image_limit'))),
        'ai_images'         => isset($_POST['ai_images']),
        'reminder_emails'   => implode(', ', $emails),
        'report_emails'     => implode(', ', array_slice($reportTo, 0, 10)),
        'radar_enabled'     => isset($_POST['radar_enabled']),
        'radar_weekday'     => max(1, min(7, (int)sm_in('radar_weekday', '1'))),
        'radar_count'       => max(3, min(10, (int)sm_in('radar_count', '6'))),
        'research_limit'    => max(0, min(2000, (int)sm_in('research_limit', '60'))),
        'blog_link_default' => isset($_POST['blog_link_default']),
        'blog_written_posts'=> isset($_POST['blog_written_posts']),
        'brand_primary'     => $hex(sm_in('brand_primary'), '#142A5C'),
        'brand_secondary'   => $hex(sm_in('brand_secondary'), '#1F4FBF'),
        'brand_accent'      => $hex(sm_in('brand_accent'), '#F7B10B'),
    ]);
    SmEvent::saveSettings($aid, $new);
    SmActivity::log($aid, 'settings_saved', 'Event settings');
    $total = array_sum($mix);
    sm_flash('Settings saved.' . ($total !== 100 ? " Note: the content mix adds up to $total%, not 100% — it is used as proportions." : ''), $total !== 100 ? 'warn' : 'ok');
    sm_redirect('settings.php');
}

sm_layout_start('Event settings');
echo '<nav class="set-nav"><a href="#amounts">Amounts and mix</a><a href="#voice">Subject and voice</a><a href="#ai">AI</a><a href="#people">Reminders</a><a href="#radar">Industry radar</a><a href="#report">Monthly report</a><a href="#brand">Branding</a></nav>';
echo '<form method="post" class="settings-form">' . sm_csrf();

echo '<section class="set-card" id="amounts"><div class="set-head"><h2>Amounts and content mix</h2><p>How much to publish for ' . e((string)$agent['name']) . ', and in which proportions. Posts per week are set for each platform on "Blog and platforms".</p></div><div class="set-body">'
   . f_text('articles_per_week', 'Blog articles per week', (string)$s['articles_per_week'], ['type' => 'number', 'min' => 0, 'max' => 21])
   . '<table class="table"><thead><tr><th>Content type</th><th style="width:120px">Share %</th></tr></thead><tbody>';
foreach (SmEvent::PILLARS as $k => [$label, $desc]) {
    echo '<tr><td><strong>' . e($label) . '</strong><br><span class="small muted">' . e($desc) . '</span></td><td><input class="input" type="number" min="0" max="100" name="mix[' . e($k) . ']" value="' . (int)$s['mix'][$k] . '" aria-label="' . e($label) . ' share"></td></tr>';
}
echo '</tbody></table><p class="hint">The event\'s phase (6 months out, 3 months, last month, show days, after) also decides which types lead.</p></div></section>';

echo '<section class="set-card" id="voice"><div class="set-head"><h2>Subject and voice</h2><p>What the agent writes about, for whom, and how.</p></div><div class="set-body">'
   . f_area('sectors', 'Sectors to cover', (string)$s['sectors'], ['rows' => 2, 'hint' => 'e.g. construction, cement, steel, building materials, construction machinery, infrastructure, housing. Used for industry and growth-sector articles.'])
   . f_area('audience', 'Readers', (string)$s['audience'], ['rows' => 2])
   . f_area('voice', 'Brand voice', (string)$s['voice'], ['rows' => 2])
   . f_area('avoid', 'Words or claims never to use (comma separated)', (string)$s['avoid'], ['rows' => 2])
   . f_check('allow_prices', 'Articles and posts may mention stand and sponsorship prices', (bool)$s['allow_prices'], 'Off: the agent never mentions prices, and the checks flag any that appear.')
   . f_check('blog_link_default', 'Tick "Include the blog post link" by default on new posts', (bool)$s['blog_link_default'])
   . f_check('blog_written_posts', 'Also write social posts for articles written directly in the blog', (bool)$s['blog_written_posts'], 'When the blog\'s own team publishes an article, its posts are written here as drafts for approval.')
   . '</div></section>';

echo '<section class="set-card" id="ai"><div class="set-head"><h2>AI</h2><p>Which model writes, and the monthly limits that stop overspending. Keys are those saved in the sales agent\'s Settings.</p></div><div class="set-body">'
   . f_select('writing_model', 'Model for writing', ['main' => 'The exhibition\'s main model (best quality)', 'background' => 'The cheaper background model'], (string)$s['writing_model'])
   . '<div class="form-grid">' . f_text('text_limit', 'Writing jobs per month', (string)$s['text_limit'], ['type' => 'number', 'min' => 0, 'hint' => 'Each draft, fact check, set of platform versions and topic list counts as one. Used: ' . SmAi::used($aid, 'text') . '.'])
   . f_text('image_limit', 'AI pictures per month', (string)$s['image_limit'], ['type' => 'number', 'min' => 0, 'hint' => 'Used: ' . SmAi::used($aid, 'image') . '.']) . '</div>'
   . f_check('ai_images', 'Allow AI-generated pictures (general illustrations only)', (bool)$s['ai_images'], SmAi::imagesReady() ? 'Uses the OpenAI key saved in the sales agent.' : 'Needs an OpenAI key in the sales agent\'s Settings before it works.')
   . '</div></section>';

echo '<section class="set-card" id="people"><div class="set-head"><h2>Reminders</h2><p>Who is emailed when a scheduled post is due, for platforms posted by hand.</p></div><div class="set-body">'
   . f_area('reminder_emails', 'Email addresses (comma separated)', (string)$s['reminder_emails'], ['rows' => 2, 'hint' => 'Sent from the exhibition\'s own email profile in the sales agent when it is set up.'])
   . '</div></section>';

echo '<section class="set-card" id="radar"><div class="set-head"><h2>Industry radar</h2><p>Once a week the agent searches the web for new reports, big projects, growing sectors and news in the sectors above, and lists them on the Content plan. Nothing is written until someone picks one.</p></div><div class="set-body">'
   . f_check('radar_enabled', 'Run the radar every week', (bool)$s['radar_enabled'])
   . '<div class="form-grid">' . f_select('radar_weekday', 'On', [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'], (string)$s['radar_weekday'])
   . f_text('radar_count', 'Items each week', (string)$s['radar_count'], ['type' => 'number', 'min' => 3, 'max' => 10])
   . f_text('research_limit', 'Web searches a month, at most', (string)$s['research_limit'], ['type' => 'number', 'min' => 0, 'hint' => 'Covers the radar and article research. Used this month: ' . SmRadar::researchUsed($aid) . '.']) . '</div>'
   . '</div></section>';

echo '<section class="set-card" id="report"><div class="set-head"><h2>Monthly management report</h2><p>Who receives this exhibition\'s one-page report each month: posts and articles published, the best ones, visits to the website, chats and leads from social media, and AI cost. Usually the exhibition\'s admins.</p></div><div class="set-body">'
   . f_area('report_emails', 'Send the report to (comma separated)', (string)$s['report_emails'], ['rows' => 2, 'hint' => 'Up to 10 addresses. Sent from the exhibition\'s own email profile, early each month, for the month before. You can see it any time under Results.'])
   . '</div></section>';

echo '<section class="set-card" id="brand"><div class="set-head"><h2>Branding on built images</h2><p>Colours used by the image builder. The logo is chosen in "Pictures and videos" (Use as logo).</p></div><div class="set-body"><div class="form-grid">'
   . f_text('brand_primary', 'Main colour', (string)$s['brand_primary'], ['type' => 'color'])
   . f_text('brand_secondary', 'Second colour', (string)$s['brand_secondary'], ['type' => 'color'])
   . f_text('brand_accent', 'Highlight colour', (string)$s['brand_accent'], ['type' => 'color'])
   . '</div></div></section>';

echo '<div class="save-bar"><button class="btn" type="submit">Save settings</button></div></form>';
sm_layout_end();
