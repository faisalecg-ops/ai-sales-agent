<?php
/**
 * Event Blog — acceptance tests.
 *   php blog/tests/acceptance.php
 * Needs MariaDB/MySQL running with database "blogtest" (user blog / blogpass).
 * It empties the bt_ tables of that database: never point it at a live site.
 */
declare(strict_types=1);

require __DIR__ . '/harness.php';

reset_blog();
start_server();
$jar = BLOG_STORAGE . '/tmp/test-cookies.txt';
@unlink($jar);

// =====================================================================
T::section('Addresses and text helpers');
T::eq(slugify("Why prefabrication is reshaping Pakistan's housing pipeline"), 'why-prefabrication-is-reshaping-pakistan-s-housing-pipeline', 'Titles become clean addresses');
T::eq(slugify('Café & Béton — 2027!'), 'cafe-beton-2027', 'Accents and symbols are simplified');
T::ok(strlen(slugify(str_repeat('word ', 60))) <= 80, 'Long addresses are shortened');
T::eq(excerpt('one two three four five six', 14), 'one two three…', 'Summaries are cut at a word');
T::eq(plain_text('<p>A</p><p>B</p>'), 'A B', 'Plain text keeps spacing between paragraphs');

// =====================================================================
T::section('Cleaning article text');
$h = Html::clean('<h1>Big</h1><p onclick="x()">Hi <script>alert(1)</script><b>bold</b></p><style>p{}</style>');
T::lacks($h, '<script', 'Scripts are removed');
T::lacks($h, 'onclick', 'Event handlers are removed');
T::lacks($h, '<style', 'Styles are removed');
T::has($h, '<h2>Big</h2>', 'A heading 1 becomes heading 2 (the title is the only h1)');
T::has($h, '<b>bold</b>', 'Bold text is kept');
$h = Html::clean('<p><a href="javascript:alert(1)">bad</a> <a href="https://buildasia.net/x?utm_source=fb&amp;id=3&amp;utm_medium=social">good</a></p>');
T::lacks($h, 'javascript:', 'javascript: links are removed');
T::has($h, 'href="https://buildasia.net/x?id=3"', 'Tracking (UTM) tags are taken off links, other parts kept');
T::has($h, 'target="_blank"', 'Outside links open in a new tab');
T::has($h, 'rel="noopener"', 'Outside links are opened safely');
$h = Html::clean('<p><a href="' . TEST_BASE . '/news/x">inside</a></p>');
T::lacks($h, 'target=', 'Links inside the blog stay in the same tab');
$h = Html::clean('<p><a href="/blog/news/x">rel</a></p>');
T::has($h, 'href="http://127.0.0.1:' . TEST_PORT . '/blog/news/x"', 'Short internal links become full addresses');
db()->insert('links', ['label' => 'Sponsor', 'url' => 'https://sponsor.example.com/', 'kind' => 'sponsorship', 'sponsored' => 1]);
LinkPolicy::forget();
$h = Html::clean('<p><a href="https://sponsor.example.com">s</a></p>');
T::has($h, 'sponsored', 'Paid links from the library are marked sponsored');
$h = Html::clean('<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><iframe src="https://evil.example.com/x"></iframe>');
T::has($h, 'youtube-nocookie.com/embed/abc', 'YouTube videos are allowed');
T::lacks($h, 'evil.example.com', 'Other embedded pages are removed');
T::lacks(Html::clean('<img src="data:image/png;base64,AAA">'), 'data:', 'Hidden embedded images are removed');
T::has(Html::clean("First para\n\nSecond para"), '<p>First para</p><p>Second para</p>', 'Plain text becomes paragraphs');
T::eq(Html::readingMinutes('<p>' . str_repeat('word ', 660) . '</p>'), 3, 'Reading time is about 220 words a minute');
[$withIds, $toc] = Html::withHeadingIds('<h2>Market</h2><p>x</p><h3>Prices</h3><h2>Market</h2>');
T::eq(count($toc), 3, 'Headings make the contents list');
T::has($withIds, 'id="market-2"', 'Repeated headings get distinct anchors');

// =====================================================================
T::section('Settings, theme and event facts');
T::eq(Settings::get('comments_mode'), 'off', 'Comments are off by default');
T::eq(Settings::get('newsletter_enabled'), false, 'The newsletter is off by default');
Settings::set('posts_per_page', 9);
Settings::flush();
T::eq(Settings::get('posts_per_page'), 9, 'Settings are saved and read back');
T::ok(Theme::contrast('#000000', '#FFFFFF') > 20, 'Black on white has maximum contrast');
T::ok(Theme::warnings(['text' => '#CCCCCC', 'background' => '#FFFFFF', 'muted' => '#595959', 'primary' => '#E3120B', 'secondary' => '#1F2E3D']) !== [], 'Light grey text on white is flagged as hard to read');
T::eq(Theme::warnings(Settings::defaultTheme()), [], 'The default theme is readable');
T::has(Theme::css(), '--secondary:#142A5C', 'The default look uses Build Asia\'s navy');
T::has(Theme::css(), '--highlight:#F7B10B', 'And its amber for call-to-action buttons');
T::has(Theme::css(), 'archivo-latin-variable.woff2', 'Archivo, the website\'s font, loads from the blog itself');
T::has(Theme::css(), '--head-stretch:112%', 'Headlines use Archivo\'s wide cut, like the website');
$t = Settings::defaultTheme();
$t['heading_font'] = 'source-serif-4';
T::has(Theme::css($t), 'source-serif-4-latin-700-normal.woff2', 'Other fonts from the list still work');
T::ok(Theme::warnings(array_merge(Settings::defaultTheme(), ['highlight_ink' => '#FFFFFF'])) !== [], 'White text on amber buttons is flagged as hard to read');
T::has(Theme::bodyClasses(Settings::defaultTheme()), 'has-band', 'The slanted stripe is on by default');
T::lacks(Theme::bodyClasses(array_merge(Settings::defaultTheme(), ['band' => false, 'motion' => 'off'])), 'motion-on', 'Stripe and motion can be switched off');
T::eq(count(Settings::looks()), 5, 'Five complete looks are offered');
$allReadable = true;
foreach (array_keys(Settings::looks()) as $lk) {
    $allReadable = $allReadable && Theme::warnings(Settings::lookTheme($lk)) === [];
}
T::ok($allReadable, 'Every complete look passes the readability check');
T::eq(Settings::lookTheme('medical')['heading_font'], 'source-sans-3', 'A look sets fonts as well as colours');
T::eq(Settings::lookTheme('corporate')['motion'], 'gentle', 'A look sets the motion level');
T::eq([Theme::motion(['motion' => true]), Theme::motion(['motion' => false]), Theme::motion(['motion' => 'gentle'])], ['full', 'off', 'gentle'], 'Older on/off motion settings still work');
Settings::setMany(['event_start' => '2027-01-14', 'event_end' => '2027-01-16', 'event_venue' => 'Expo Centre', 'event_city' => 'Karachi']);
T::eq(Event::dates(), '14–16 January 2027', 'Show dates read naturally');
Settings::set('event_end', '2027-02-02');
T::eq(Event::dates(), '14 January – 2 February 2027', 'Dates across months read naturally');
T::eq(Event::fill('Courtesy of {event}'), 'Courtesy of Build Asia', 'The event name fills admin wording');
Settings::setMany(['event_venue' => '', 'chat_agent' => 'buildasia', 'chat_base_url' => 'https://salesagent.example.net']);
Event::$fetcher = static fn($u) => str_contains($u, 'agent=buildasia') ? json_encode(['ok' => true, 'event' => ['name' => 'Build Asia', 'venue' => 'Expo Centre Karachi', 'city' => 'Lahore', 'register_url' => 'javascript:x', 'start_date' => '2027-01-14']]) : null;
T::ok(Event::refresh()['ok'], 'Event facts are fetched from the sales agent');
T::eq(Event::get('event_venue'), 'Expo Centre Karachi', 'An empty box uses the sales agent\'s value');
T::eq(Event::source('event_venue'), 'sales agent', 'The admin is told where the value came from');
T::eq(Event::get('event_city'), 'Karachi', 'A value typed in the blog takes priority');
T::eq(Event::get('register_url'), '', 'Unsafe addresses from outside are ignored');
Event::$fetcher = static fn($u) => null;
T::ok(!Event::refresh()['ok'], 'A failed fetch is reported…');
T::eq(Event::get('event_venue'), 'Expo Centre Karachi', '…and the last good facts are kept');

// =====================================================================
T::section('Users, roles and sign-in');
reset_blog();
$admin = make_user('admin');
$approver = make_user('approver');
$editor = make_user('editor');
$author = make_user('author');
$author2 = make_user('author');
Auth::actAs($author);
T::ok(Auth::can('articles.own') && !Auth::can('articles.publish') && !Auth::can('settings'), 'Authors write, but cannot publish or change settings');
Auth::actAs($editor);
T::ok(Auth::can('content') && Auth::can('articles.all') && !Auth::can('articles.publish'), 'Editors manage content but send articles for approval');
Auth::actAs($approver);
T::ok(Auth::can('articles.publish') && !Auth::can('users'), 'Approvers publish but do not manage users');
Auth::actAs($admin);
T::ok(Auth::can('users') && Auth::can('api') && Auth::can('settings'), 'Admins can do everything');
T::ok(Auth::passwordProblem('short1') !== null, 'Short passwords are refused');
T::ok(Auth::passwordProblem('onlyletterspassword') !== null, 'Passwords need numbers');
T::eq(Auth::passwordProblem('Strong12345'), null, 'A good password is accepted');
for ($i = 0; $i < 5; $i++) {
    Auth::attempt($admin['email'], 'wrong-password-1');
}
T::ok(!Auth::loginAllowed($admin['email']), 'Five wrong passwords pause sign-in for that account');

// =====================================================================
T::section('Articles: saving, approval and publishing');
reset_blog();
$admin = make_user('admin');
$author = make_user('author');
$author2 = make_user('author');
$cat = make_category('Machinery');
$cat2 = make_category('Market Insights');
Auth::actAs($author);
$id = Article::save(null, ['title' => 'Cranes on site', 'body' => '<p>' . str_repeat('Tower cranes are changing. ', 10) . '</p>', 'author_id' => $author['id']], ['Cranes', 'Safety']);
$a = Article::find($id);
T::eq($a['status'], 'draft', 'A new article starts as a draft');
T::eq($a['slug'], 'cranes-on-site', 'Its address is made from the title');
T::eq((int)db()->value('SELECT COUNT(*) FROM {p}article_revisions WHERE article_id = ?', [$id]), 1, 'The first version is kept');
T::ok(Auth::canEditArticle($a), 'The author can edit their own draft');
Auth::actAs($author2);
T::ok(!Auth::canEditArticle($a), 'Another author cannot edit it');
Auth::actAs($author);
$id2 = Article::save(null, ['title' => 'Cranes on site', 'body' => '<p>x</p>']);
T::eq(Article::find($id2)['slug'], 'cranes-on-site-2', 'Two articles never share an address');
db()->delete('articles', 'id = ?', [$id2]);
T::eq(Article::transition($id, 'pending')['ok'], true, 'The author sends it for approval');
T::ok(!Auth::canEditArticle(array_merge(Article::find($id), ['status' => 'published'])), 'Authors cannot change a published article');
$res = Article::transition($id, 'published');
T::ok(!$res['ok'] && str_contains($res['error'], 'category'), 'An article without a category cannot be published');
Auth::actAs($admin);
Article::save($id, ['category_id' => $cat]);
Settings::set('webhook_url', 'https://social.example.net/hook');
T::ok(Article::transition($id, 'published')['ok'], 'An approver publishes it');
$a = Article::find($id);
T::eq($a['status'], 'published', 'It is published');
T::ok($a['published_at'] !== null, 'The publishing time is recorded');
T::eq((int)$a['approved_by'], (int)$admin['id'], 'Who approved it is recorded');
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}webhook_outbox WHERE event = 'article.published' AND article_id = ?", [$id]), 1, 'The social agent is to be told about it');
Article::transition($id, 'draft');
Article::transition($id, 'published');
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}webhook_outbox WHERE article_id = ?", [$id]), 2, 'Publishing again after unpublishing tells the agent again');
T::eq(Article::find($id)['published_at'], $a['published_at'], 'The first publishing date is kept');

// Address changes
$oldUrl = Article::urlFor(Article::find($id));
Article::save($id, ['slug' => 'tower-cranes-on-site']);
T::eq((string)db()->value('SELECT article_id FROM {p}redirects WHERE old_path = ?', ['machinery/cranes-on-site']), (string)$id, 'Changing a published article\'s address keeps the old one forwarding');
Article::save($id, ['category_id' => $cat2]);
T::ok((bool)db()->value('SELECT id FROM {p}redirects WHERE old_path = ?', ['machinery/tower-cranes-on-site']), 'Moving it to another category forwards too');
Article::categoryRenamed($cat2, 'market-insights', 'insights');
db()->update('categories', ['slug' => 'insights'], 'id = ?', [$cat2]);
T::ok((bool)db()->value('SELECT id FROM {p}redirects WHERE old_path = ?', ['market-insights/tower-cranes-on-site']), 'Renaming a category forwards all its articles');

// Scheduling
$sid = Article::save(null, ['title' => 'Scheduled piece', 'body' => '<p>' . str_repeat('Words for the schedule. ', 10) . '</p>', 'category_id' => $cat]);
T::ok(!Article::transition($sid, 'scheduled', gmdate('Y-m-d H:i:s', time() - 60))['ok'], 'A time in the past cannot be scheduled');
T::ok(Article::transition($sid, 'scheduled', gmdate('Y-m-d H:i:s', time() + 3600))['ok'], 'An article is scheduled for later');
T::eq(Article::publishDue(), 0, 'It is not published early');
db()->update('articles', ['publish_at' => gmdate('Y-m-d H:i:s', time() - 5)], 'id = ?', [$sid]);
T::eq(Article::publishDue(), 1, 'It is published when its time comes');
T::eq(Article::find($sid)['status'], 'published', 'Its status is now published');

// Tags and revisions
T::eq(array_column(Article::tags($id), 'name'), ['Cranes', 'Safety'], 'Tags are attached');
Article::syncTags($id, ['cranes', 'Cranes', 'Equipment']);
T::eq(count(Article::tags($id)), 2, 'The same tag twice is stored once');
for ($i = 0; $i < 55; $i++) {
    Article::save($id, ['body' => '<p>Version ' . $i . ' ' . str_repeat('x ', 30) . '</p>']);
}
T::eq((int)db()->value('SELECT COUNT(*) FROM {p}article_revisions WHERE article_id = ?', [$id]), 50, 'The last 50 versions are kept');
[$rows, $total] = Article::search('Scheduled', 10, 0);
T::eq($total, 1, 'Search finds published articles by title');
[$rows, $total] = Article::search('a', 10, 0);
T::eq($total, 0, 'One-letter searches are ignored');

// =====================================================================
T::section('Link rules');
reset_blog();
$admin = make_user('admin');
Auth::actAs($admin);
$cat = make_category('News');
db()->insert('links', ['label' => 'Register to visit', 'url' => 'https://buildasia.net/register', 'kind' => 'register', 'keywords' => 'register, visitor pass']);
LinkPolicy::forget();
$pub = publish_article('Earlier article', $cat);
$body = '<p>Please <a href="https://buildasia.net/register?utm_source=x">register</a>. See <a href="' . Article::urlFor($pub) . '">earlier</a>, '
    . '<a href="https://stats.example.org/report">the report</a> and <a href="https://random.example.com">this</a>.</p>';
$rv = LinkPolicy::review(Html::clean($body), ['https://stats.example.org/report']);
$kinds = array_column($rv['links'], 'kind');
T::eq($kinds, ['library', 'article', 'source', 'unknown'], 'Each link is recognised: library, this blog, declared source, unknown');
T::eq($rv['flagged'], 1, 'Only the unknown link is flagged');
T::ok((bool)array_filter($rv['warnings'], static fn($w) => str_contains($w, 'not in the link library')), 'The editor is warned before approving');
$sug = LinkPolicy::suggest('<p>Get your visitor pass early.</p>');
T::ok((bool)array_filter($sug, static fn($s) => $s['kind'] === 'library' && $s['label'] === 'Register to visit'), 'The editor suggests library links whose key words appear');
T::ok((bool)array_filter($sug, static fn($s) => $s['kind'] === 'article'), 'It suggests other articles too');

// =====================================================================
T::section('Images');
$mid = test_image('site-photo', 2000, 1200);
$m = Media::find($mid);
T::ok($m !== null && (int)$m['width'] === 2000, 'An image is stored with its size');
$sizes = json_decode((string)$m['sizes'], true);
T::eq(array_keys($sizes), [400, 800, 1200, 1800], 'It is resized for phones and computers');
T::ok(!function_exists('imagewebp') || !empty($sizes['800']['w']), 'Smaller WebP copies are made');
T::has(Media::picture($m, '', 'Alt'), 'loading="lazy"', 'Pictures load lazily');
T::has(Media::picture($m, '', 'Alt', '100vw', true), 'fetchpriority="high"', 'The first picture on a page loads first');
$bad = BLOG_STORAGE . '/tmp/not-image.jpg';
file_put_contents($bad, '<?php echo "hack"; ?>');
T::ok(!Media::store($bad, 'shell.php.jpg')['ok'], 'A file that is not an image is refused');
@unlink($bad);
Article::save((int)$pub['id'], ['image_id' => $mid]);
T::ok(!Media::delete($mid)['ok'], 'An image in use cannot be deleted');
$free = test_image('unused');
$fm = Media::find($free);
T::ok(Media::delete($free)['ok'] && !is_file(BLOG_UPLOADS . '/' . $fm['file']), 'An unused image is deleted with its files');

// =====================================================================
T::section('Sponsor banners');
$bimg = test_image('banner', 1200, 200);
$b1 = db()->insert('banners', ['sponsor_name' => 'Acme', 'image_id' => $bimg, 'link' => 'https://acme.example.com', 'position' => 'middle']);
$b2 = db()->insert('banners', ['sponsor_name' => 'Acme', 'image_id' => $bimg, 'link' => 'https://acme.example.com/2', 'position' => 'middle', 'ends_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
$b3 = db()->insert('banners', ['sponsor_name' => 'Beta', 'image_id' => $bimg, 'link' => 'https://beta.example.com', 'position' => 'middle', 'starts_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
T::eq(array_map('intval', array_column(Banner::live('middle'), 'id')), [$b1], 'Only banners within their dates are shown');
$html = Banner::render('middle');
T::has($html, 'rel="sponsored noopener"', 'Banner links are marked sponsored');
T::has($html, '/go/b/' . $b1, 'Clicks go through the counter');
Banner::countViews([$b1, $b1, 999]);
T::eq((int)db()->value('SELECT views FROM {p}banner_stats WHERE banner_id = ?', [$b1]), 1, 'A banner shown on one page is counted once');
Banner::countViews([$b1]);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
T::eq(Banner::click($b1), 'https://acme.example.com', 'A click leads to the sponsor');
$r = Banner::report('Acme', gmdate('Y-m-d', time() - 86400), gmdate('Y-m-d'));
T::eq([$r['views'], $r['clicks']], [2, 1], 'The sponsor report counts views and clicks');
T::eq(count($r['banners']), 2, 'The report covers all of the sponsor\'s banners');

// =====================================================================
T::section('Comments and newsletter');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$human = ['name' => 'Ali', 'email' => 'ali@example.com', 'comment' => 'Useful article.', '_ts' => (time() - 20) . '.' . substr(hash_hmac('sha256', (string)(time() - 20), app_key()), 0, 16)];
T::eq(Reader::addComment((int)$pub['id'], $human)['error'] ?? '', 'closed', 'Comments are refused while switched off');
Settings::set('comments_mode', 'approval');
T::ok(Reader::addComment((int)$pub['id'], $human)['ok'], 'A comment is accepted when switched on');
T::eq((string)db()->value('SELECT status FROM {p}comments ORDER BY id DESC LIMIT 1'), 'pending', 'It waits for approval');
T::eq(Reader::approvedComments((int)$pub['id']), [], 'It is not shown before approval');
$before = (int)db()->value('SELECT COUNT(*) FROM {p}comments');
Reader::addComment((int)$pub['id'], array_merge($human, ['website' => 'http://spam']));
Reader::addComment((int)$pub['id'], array_merge($human, ['_ts' => time() . '.0000000000000000']));
T::eq((int)db()->value('SELECT COUNT(*) FROM {p}comments'), $before, 'Robots (hidden field, forged or instant forms) are ignored silently');
T::eq(Reader::subscribe(['email' => 'reader@example.com', 'consent' => '1', '_ts' => $human['_ts']], '/')['error'] ?? '', 'closed', 'Sign-up is refused while the newsletter is off');
Settings::set('newsletter_enabled', true);
T::eq(Reader::subscribe(['email' => 'reader@example.com', '_ts' => $human['_ts']], '/')['error'] ?? '', 'consent', 'Sign-up needs the consent box ticked');
$sent = [];
Mailer::$transport = static function ($to, $s, $t, $h) use (&$sent) { $sent[] = [$to, $t]; return true; };
T::ok(Reader::subscribe(['email' => 'Reader@Example.com', 'consent' => '1', '_ts' => $human['_ts']], TEST_BASE . '/')['ok'], 'A reader signs up');
$s = db()->one('SELECT * FROM {p}subscribers WHERE email = ?', ['reader@example.com']);
T::eq($s['status'], 'pending', 'They are not subscribed until they confirm');
T::ok($s['consent_text'] !== '', 'The consent wording is recorded');
T::ok(count($sent) === 1 && str_contains($sent[0][1], '/confirm/' . $s['token']), 'A confirmation email is sent with their link');
T::ok(Reader::confirm($s['token']), 'The link confirms them');
T::eq((string)db()->value('SELECT status FROM {p}subscribers WHERE id = ?', [(int)$s['id']]), 'confirmed', 'They are now confirmed');
T::ok(!Reader::confirm('not-a-real-token-at-all-xyz'), 'A made-up link does nothing');
T::ok(Reader::unsubscribe($s['token']), 'One click unsubscribes');
T::ok(!Reader::confirm($s['token']), 'An unsubscribed reader cannot be re-confirmed with an old link');

// =====================================================================
T::section('Notices to the Social Media Agent');
reset_blog();
$admin = make_user('admin');
Auth::actAs($admin);
$cat = make_category('News');
Settings::set('webhook_url', 'https://social.example.net/hook');
$a = publish_article('Webhook story', $cat, ['tags' => ['Cement']]);
$got = [];
Webhook::$transport = static function ($url, $body, $headers) use (&$got) { $got[] = [$url, $body, $headers]; return ['status' => 503, 'error' => 'busy']; };
T::eq(Webhook::flush(), 0, 'A busy agent is not counted as sent');
$job = db()->one('SELECT * FROM {p}webhook_outbox LIMIT 1');
T::eq([$job['status'], (int)$job['attempts']], ['queued', 1], 'It will be tried again');
T::ok(strtotime($job['next_try_at'] . ' UTC') > time(), 'After a pause');
db()->update('webhook_outbox', ['next_try_at' => now_utc()], '1=1');
Webhook::$transport = static function ($url, $body, $headers) use (&$got) { $got[] = [$url, $body, $headers]; return ['status' => 200, 'error' => '']; };
T::eq(Webhook::flush(), 1, 'It is sent when the agent answers');
$payload = json_decode($got[1][1], true);
T::eq($payload['event'], 'article.published', 'The notice says what happened');
T::eq($payload['article']['url'], Article::urlFor($a), 'It carries the published address');
T::eq($payload['article']['tags'], ['Cement'], 'And the tags');
$sig = (string)preg_replace('/^X-Blog-Signature: /', '', (string)current(array_filter($got[1][2], static fn($h) => str_starts_with($h, 'X-Blog-Signature'))));
T::eq($sig, 'sha256=' . hash_hmac('sha256', $got[1][1], 'testsecret'), 'It is signed with the shared secret');
Settings::set('webhook_url', '');
$before = (int)db()->value('SELECT COUNT(*) FROM {p}webhook_outbox');
publish_article('No hook', $cat);
T::eq((int)db()->value('SELECT COUNT(*) FROM {p}webhook_outbox'), $before, 'Nothing is queued while no agent is connected');

// =====================================================================
T::section('Secrets and API keys');
$enc = Secret::encrypt('smtp-pass');
T::ok($enc !== 'smtp-pass' && str_starts_with($enc, 'v1:'), 'Stored passwords are encrypted');
T::eq(Secret::decrypt($enc), 'smtp-pass', 'And read back correctly');
$k = ApiKey::create('Agent', 'drafts');
T::ok(!db()->value('SELECT id FROM {p}api_keys WHERE key_hash = ?', [$k['key']]), 'The key itself is never stored, only a scrambled copy');
T::ok(ApiKey::verify($k['key']) !== null, 'The key works');
db()->update('api_keys', ['active' => 0], 'id = ?', [$k['id']]);
T::eq(ApiKey::verify($k['key']), null, 'A switched-off key stops working');
T::eq(ApiKey::verify('eb_' . str_repeat('0', 48)), null, 'A made-up key does not work');

// =====================================================================
T::section('Public pages');
reset_blog();
$admin = make_user('admin', 'Faisal');
Auth::actAs($admin);
$cat = make_category('Machinery');
$img = test_image('hero', 1800, 1000);
Settings::setMany(['event_start' => gmdate('Y') + 1 . '-01-14', 'event_end' => gmdate('Y') + 1 . '-01-16', 'event_venue' => 'Expo Centre', 'event_city' => 'Karachi',
    'main_site_url' => 'https://buildasia.net', 'register_url' => 'https://buildasia.net/register', 'book_stand_url' => 'https://buildasia.net/stand',
    'chat_enabled' => true, 'chat_agent' => 'buildasia', 'chat_base_url' => 'https://salesagent.ecgateway.net']);
$a = publish_article('Prefabrication in Pakistan', $cat, ['excerpt' => 'Factory-built parts cut site time.', 'image_id' => $img, 'author_id' => $admin['id'], 'tags' => ['Housing']]);
$b = publish_article('Second story', $cat, ['author_id' => $admin['id']]);
Cache::clear();
$home = http('GET', '/');
T::eq($home['status'], 200, 'The home page opens');
T::has($home['body'], 'Prefabrication in Pakistan', 'It lists the articles');
T::has($home['body'], 'data-countdown', 'It shows the countdown to the show');
T::has($home['body'], 'https://buildasia.net/register', 'With the register button');
T::has($home['body'], '"@type":"Event"', 'Google is told about the exhibition');
T::has($home['body'], 'salesagent.ecgateway.net/widget/widget.js', 'The sales agent chat is on the page');
T::has($home['body'], 'window.addEventListener(\'load\'', 'The chat loads after the page');
T::has($home['body'], 'Visit Build Asia', 'The header links to the main website');
T::lacks($home['body'], 'class="stats', 'The counter strip stays hidden until figures are entered');
T::has($home['body'], 'prefers-reduced-motion: reduce', 'Animations are never started for visitors who prefer less motion');
T::has($home['body'], 'class="band band--masthead"', 'The signature stripe sits under the header');
T::has($home['body'], 'data-motion="full"', 'The page carries the chosen motion level');
Settings::setMany(['stats_caption' => '{event} in numbers', 'stats' => [['value' => 32000, 'suffix' => '+', 'label' => 'Trade visitors'], ['value' => 190000, 'suffix' => '+ sq ft', 'label' => 'Exhibition space'], ['value' => 0, 'suffix' => '', 'label' => 'Empty row']]]);
Cache::clear();
$withStats = http('GET', '/')['body'];
T::has($withStats, 'data-count="32000">32,000</span>', 'The counter strip shows the figures (counting up in the browser)');
T::has($withStats, 'Build Asia in numbers', 'Its caption names the exhibition');
T::has($withStats, 'stat__suffix--long', 'Long endings such as "sq ft" are set smaller');
T::lacks($withStats, 'Empty row', 'Rows without a number are ignored');
T::eq(http('GET', '/')['headers'] !== '' && str_contains(http('GET', '/')['headers'], 'X-Cache: HIT'), true, 'Later visitors get the saved page');
$art = http('GET', '/machinery/prefabrication-in-pakistan');
T::eq($art['status'], 200, 'An article opens at its clean address');
T::has($art['body'], '<h1 class="post__title">Prefabrication in Pakistan</h1>', 'It has a single main heading');
T::has($art['body'], '<link rel="canonical" href="' . TEST_BASE . '/machinery/prefabrication-in-pakistan">', 'It names its own address for Google');
T::has($art['body'], 'property="og:image"', 'It has a share picture for social media');
T::has($art['body'], 'name="twitter:card" content="summary_large_image"', 'And an X card');
T::has($art['body'], '"@type":"Article"', 'Google is told it is an article');
T::has($art['body'], '"@type":"BreadcrumbList"', 'With its breadcrumbs');
T::has($art['body'], 'Courtesy of Build Asia', 'The courtesy box is under the article');
T::has($art['body'], 'salesagent.ecgateway.net/widget/widget.js', 'The chat is on article pages too');
T::has($art['body'], 'Related articles', 'Related articles are suggested');
T::eq(http('GET', '/machinery')['location'], TEST_BASE . '/machinery/', 'Category addresses get their closing slash');
T::eq(http('GET', '/machinery/')['status'], 200, 'The category page opens');
T::eq(http('GET', '/news/prefabrication-in-pakistan')['location'], TEST_BASE . '/machinery/prefabrication-in-pakistan', 'A wrong category forwards to the right address');
Article::save((int)$a['id'], ['slug' => 'prefab-in-pakistan']);
$old = http('GET', '/machinery/prefabrication-in-pakistan');
T::eq([$old['status'], $old['location']], [301, TEST_BASE . '/machinery/prefab-in-pakistan'], 'An old address forwards permanently (301) to the new one');
$nf = http('GET', '/machinery/does-not-exist');
T::eq($nf['status'], 404, 'Missing pages answer "not found"');
T::has($nf['body'], 'noindex', 'Not-found pages are kept out of Google');
T::has($nf['body'], 'widget.js', 'The chat is on the not-found page too');
T::eq(http('GET', '/tag/housing')['status'], 200, 'Tag pages open');
T::eq(http('GET', '/author/' . $admin['slug'])['status'], 200, 'Author pages open');
$sr = http('GET', '/search?q=prefab');
T::has($sr['body'], 'Prefabrication in Pakistan', 'Search finds articles');
T::has($sr['body'], 'noindex', 'Search results are kept out of Google');
$sm = http('GET', '/sitemap.xml');
T::ok($sm['status'] === 200 && simplexml_load_string($sm['body']) !== false, 'The sitemap is valid');
T::has($sm['body'], '/machinery/prefab-in-pakistan</loc>', 'It lists the articles');
T::has($sm['body'], '<image:loc>', 'And their pictures');
$feed = http('GET', '/feed');
T::ok(simplexml_load_string($feed['body']) !== false && str_contains($feed['body'], '<item>'), 'The RSS feed is valid');
foreach (['/core/Db.php', '/config/config.php', '/storage/logs/blog.log', '/tests/harness.php'] as $p) {
    T::eq(http('GET', $p)['status'], 403, 'Private file ' . $p . ' cannot be opened');
}
Settings::set('hide_from_search', true);
Cache::clear();
T::has(http('GET', '/')['body'], 'content="noindex, nofollow"', 'A review copy can be hidden from Google');
Settings::setMany(['hide_from_search' => false, 'chat_enabled' => false]);
Cache::clear();
T::lacks(http('GET', '/')['body'], 'widget.js', 'The chat can be switched off');

// Counting
$before = (int)db()->value('SELECT views FROM {p}articles WHERE id = ?', [(int)$a['id']]);
http('POST', '/go/beacon', ['raw' => json_encode(['a' => (int)$a['id']])]);
T::eq((int)db()->value('SELECT views FROM {p}articles WHERE id = ?', [(int)$a['id']]), $before + 1, 'A reader\'s view is counted (even on saved pages)');
http('POST', '/go/beacon', ['raw' => json_encode(['a' => (int)$a['id']]), 'ua' => 'Googlebot/2.1']);
T::eq((int)db()->value('SELECT views FROM {p}articles WHERE id = ?', [(int)$a['id']]), $before + 1, 'Search robots are not counted');
$bid = db()->insert('banners', ['sponsor_name' => 'Acme', 'image_id' => $img, 'link' => 'https://acme.example.com', 'position' => 'sidebar']);
$click = http('GET', '/go/b/' . $bid);
T::eq([$click['status'], $click['location']], [302, 'https://acme.example.com'], 'A banner click leads to the sponsor');
T::eq((int)db()->value('SELECT clicks FROM {p}banner_stats WHERE banner_id = ?', [$bid]), 1, 'And is counted');

// Reader forms over the web
Settings::setMany(['comments_mode' => 'approval', 'newsletter_enabled' => true]);
Cache::clear();
$page = http('GET', '/machinery/prefab-in-pakistan');
preg_match('/name="_ts" value="([^"]+)"/', $page['body'], $ts);
sleep(3);
$c = http('POST', '/comment/' . $a['id'], ['form' => ['name' => 'Sara', 'email' => 'sara@example.com', 'comment' => 'Helpful.', '_ts' => $ts[1] ?? '']]);
T::ok($c['status'] === 303 && str_contains((string)$c['location'], 'c=thanks'), 'A reader can send a comment from the article page');
T::eq((string)db()->value("SELECT name FROM {p}comments WHERE status = 'pending' ORDER BY id DESC LIMIT 1"), 'Sara', 'It waits for approval');
$n = http('POST', '/subscribe', ['form' => ['email' => 'x@example.com', '_ts' => $ts[1] ?? '']]);
T::ok(str_contains((string)$n['location'], 'nl=consent'), 'Sign-up without consent is refused with a message');

// =====================================================================
T::section('Admin panel');
$login = http('GET', '/admin/', ['jar' => $jar]);
T::has($login['body'], 'Sign in', 'The admin asks for sign-in');
T::has($login['headers'], 'X-Robots-Tag: noindex', 'The admin is hidden from Google');
$bad = http('POST', '/admin/', ['jar' => $jar, 'form' => ['_csrf' => 'forged', 'email' => $admin['email'], 'password' => 'Password123']]);
T::has($bad['body'], 'expired', 'A forged sign-in form is refused');
$ok = http('POST', '/admin/', ['jar' => $jar, 'form' => ['_csrf' => csrf_from($login['body']), 'email' => $admin['email'], 'password' => 'Password123']]);
T::eq($ok['status'], 302, 'The admin signs in');
$pages = ['dashboard', 'articles', 'article', 'media', 'categories', 'comments', 'home', 'slides', 'banners', 'event', 'links', 'branding', 'menus', 'settings',
    'seo', 'chat', 'email', 'subscribers', 'users', 'profile', 'apikeys', 'activity', 'contributors', 'submissions', 'sponsored'];
$allOk = true;
foreach ($pages as $p) {
    $r = http('GET', '/admin/?p=' . $p, ['jar' => $jar]);
    if ($r['status'] !== 200 || !str_contains($r['body'], '</html>')) {
        $allOk = false;
        T::ok(false, 'Admin page ' . $p . ' opens');
    }
}
T::ok($allOk, 'All ' . count($pages) . ' admin pages open for an admin');
$dash = http('GET', '/admin/?p=article', ['jar' => $jar]);
$tok = csrf_from($dash['body']);
$new = http('POST', '/admin/?p=article', ['jar' => $jar, 'form' => ['_csrf' => $tok, 'do' => 'publish', 'title' => 'Written in the admin',
    'body' => '<p>' . str_repeat('Admin-written text about building materials. ', 6) . '</p>', 'category_id' => (string)$cat, 'tags' => 'Steel, Prices']]);
$nid = (int)db()->value("SELECT id FROM {p}articles WHERE title = 'Written in the admin'");
T::ok($nid > 0 && Article::find($nid)['status'] === 'published', 'An admin writes and publishes an article from the editor');
T::eq(array_column(Article::tags($nid), 'name'), ['Prices', 'Steel'], 'Its tags are saved');
$pv = http('GET', '/admin/?p=preview&id=' . $b['id'], ['jar' => $jar]);
T::has($pv['body'], 'Second story', 'Signed-in users can preview');
T::eq(http('GET', '/admin/?p=preview&id=' . $b['id'])['status'] === 200 && !str_contains(http('GET', '/admin/?p=preview&id=' . $b['id'])['body'], 'Second story'), true, 'Previews need sign-in');
$authorUser = make_user('author');
$jar2 = BLOG_STORAGE . '/tmp/test-cookies-2.txt';
@unlink($jar2);
$l2 = http('GET', '/admin/', ['jar' => $jar2]);
http('POST', '/admin/', ['jar' => $jar2, 'form' => ['_csrf' => csrf_from($l2['body']), 'email' => $authorUser['email'], 'password' => 'Password123']]);
T::has(http('GET', '/admin/?p=branding', ['jar' => $jar2])['body'], 'Your role does not include this page', 'An author cannot open Branding');
T::has(http('GET', '/admin/?p=users', ['jar' => $jar2])['body'], 'Your role does not include this page', 'An author cannot open Users');
$ap = http('GET', '/admin/?p=article', ['jar' => $jar2]);
http('POST', '/admin/?p=article', ['jar' => $jar2, 'form' => ['_csrf' => csrf_from($ap['body']), 'do' => 'publish', 'title' => 'Author tries to publish',
    'body' => '<p>' . str_repeat('An author article. ', 10) . '</p>', 'category_id' => (string)$cat]]);
T::eq((string)db()->value("SELECT status FROM {p}articles WHERE title = 'Author tries to publish'"), 'draft', 'An author pressing publish only saves a draft');
$rep = http('GET', '/admin/?p=banners&report=Acme&format=csv', ['jar' => $jar]);
T::has($rep['body'], 'Date,Views,Clicks', 'The sponsor report downloads as a spreadsheet');

// =====================================================================
T::section('API for the Social Media Agent');
$drafts = ApiKey::create('Agent drafts', 'drafts');
$publish = ApiKey::create('Agent publish', 'publish');
$auth = static fn($k) => ['headers' => ['Authorization: Bearer ' . $k['key']]];
T::eq(http('GET', '/api/v1/ping')['status'], 401, 'No key, no access');
T::eq(http('GET', '/api/v1/ping', ['headers' => ['Authorization: Bearer eb_' . str_repeat('1', 48)]])['status'], 401, 'A wrong key is refused');
$ping = json_decode(http('GET', '/api/v1/ping', $auth($drafts))['body'], true);
T::eq([$ping['ok'] ?? false, $ping['permission'] ?? ''], [true, 'drafts'], 'A valid key gets in and is told its permission');
T::ok(json_decode(http('GET', '/api/v1/ping', ['headers' => ['X-Api-Key: ' . $drafts['key']]])['body'], true)['ok'] ?? false, 'Hosts that drop the Authorization header can send X-Api-Key');
$cats = json_decode(http('GET', '/api/v1/categories', $auth($drafts))['body'], true);
T::ok(in_array('machinery', array_column($cats['categories'], 'slug'), true), 'The agent can list categories');
$art = ['title' => 'Agent article', 'body' => '<p>' . str_repeat('Market news written by the agent. ', 5) . '</p>', 'category' => 'machinery', 'tags' => ['News'], 'publish' => true, 'external_ref' => 'sm-100'];
$r = http('POST', '/api/v1/articles', $auth($drafts) + ['json' => $art]);
$j = json_decode($r['body'], true);
T::eq([$r['status'], $j['article']['status'] ?? ''], [201, 'pending'], 'With a drafts-only key, articles arrive awaiting approval even if "publish" is asked');
T::ok(array_key_exists('url', $j['article'] ?? []) && $j['article']['url'] === null, 'Unpublished articles have no public address yet');
T::eq(http('POST', '/api/v1/articles', $auth($drafts) + ['json' => $art])['status'], 409, 'The same article cannot be sent twice');
$r = http('POST', '/api/v1/articles', $auth($publish) + ['json' => array_merge($art, ['external_ref' => 'sm-101', 'body' => $art['body'] . '<p>See <a href="https://unknown.example.com">this</a>.</p>'])]);
T::eq(json_decode($r['body'], true)['article']['status'] ?? '', 'pending', 'Even a publishing key is held for approval when a link is not approved');
$r = http('POST', '/api/v1/articles', $auth($publish) + ['json' => array_merge($art, ['external_ref' => 'sm-102', 'sources' => ['https://data.example.org/x'],
    'body' => $art['body'] . '<p>Data from <a href="https://data.example.org/x">the association</a>.</p>'])]);
$pj = json_decode($r['body'], true);
T::eq($pj['article']['status'] ?? '', 'published', 'With a publishing key and clean links, it goes live');
T::ok(str_starts_with((string)($pj['article']['url'] ?? ''), TEST_BASE . '/machinery/'), 'The agent is given the live address');
$r = http('POST', '/api/v1/articles', $auth($publish) + ['json' => array_merge($art, ['external_ref' => 'sm-103', 'publish' => false, 'publish_at' => gmdate('c', time() + 7200)])]);
T::eq(json_decode($r['body'], true)['article']['status'] ?? '', 'scheduled', 'A publishing key can schedule');
$r = http('POST', '/api/v1/articles', $auth($drafts) + ['json' => ['title' => '', 'category' => 'nowhere']]);
T::eq($r['status'], 422, 'Incomplete articles are refused');
T::has($r['body'], 'category not found', 'With a clear reason');
$pid = (int)json_decode(http('GET', '/api/v1/articles?external_ref=sm-100', $auth($drafts))['body'], true)['articles'][0]['id'];
$r = http('PATCH', '/api/v1/articles/' . $pid, $auth($drafts) + ['json' => ['title' => 'Agent article, revised']]);
T::eq(json_decode($r['body'], true)['article']['title'] ?? '', 'Agent article, revised', 'The agent can revise an article that is not yet published');
T::eq(http('PATCH', '/api/v1/articles/' . (int)$pj['article']['id'], $auth($drafts) + ['json' => ['title' => 'x']])['status'], 409, 'A published article can only be changed by a person');
T::eq(http('GET', '/api/v1/articles/' . $nid, $auth($drafts))['status'], 404, 'Articles written in the admin are not visible to the API');
$up = http('POST', '/api/v1/media', $auth($drafts) + ['json' => ['filename' => 'pic.jpg', 'alt' => 'A site', 'data' => base64_encode((string)file_get_contents(BLOG_UPLOADS . '/' . Media::find($img)['file']))]]);
$uj = json_decode($up['body'], true);
T::ok($up['status'] === 201 && ($uj['media']['id'] ?? 0) > 0, 'The agent can upload an image');
T::eq(http('POST', '/api/v1/media', $auth($drafts) + ['json' => ['data' => base64_encode('not an image')]])['status'], 422, 'Non-images are refused');
// Read-only views for the Social Media Agent: articles written in the blog itself.
$machCat = (int)db()->value("SELECT id FROM {p}categories WHERE slug = 'machinery'");
$own = publish_article('Written in the blog by the team', $machCat, ['body' => '<p>' . str_repeat('Cranes on site this season. ', 12) . '</p>']);
$waitId = Article::save(null, ['title' => 'Waiting for the editor', 'body' => '<p>' . str_repeat('Draft text. ', 20) . '</p>', 'category_id' => $machCat], []);
Article::transition($waitId, 'pending');
$pub = json_decode(http('GET', '/api/v1/published?since=' . rawurlencode(gmdate('c', time() - 3600)), $auth($drafts))['body'], true);
$mine = array_values(array_filter($pub['articles'] ?? [], static fn($x) => $x['id'] === (int)$own['id']));
T::ok($mine && str_contains($mine[0]['body'], 'Cranes on site') && $mine[0]['from_api'] === false && str_contains((string)$mine[0]['url'], '/machinery/'), 'The agent can read published articles written in the blog, with their text and address');
T::ok(!in_array($waitId, array_column($pub['articles'] ?? [], 'id'), true), '…but never unpublished ones');
T::eq(http('GET', '/api/v1/published/' . $waitId, $auth($drafts))['status'], 404, 'An unpublished article cannot be read one by one either');
$one = json_decode(http('GET', '/api/v1/published/' . (int)$own['id'], $auth($drafts))['body'], true);
T::eq($one['article']['title'] ?? '', 'Written in the blog by the team', 'One published article can be read by its number');
$pend = json_decode(http('GET', '/api/v1/pending', $auth($drafts))['body'], true);
$w = array_values(array_filter($pend['articles'] ?? [], static fn($x) => $x['id'] === $waitId));
T::ok($w && str_contains($w[0]['admin_url'], '/admin/?p=article&id=' . $waitId) && !isset($w[0]['body']), 'The list of articles waiting for approval gives titles and a link to approve them in the blog admin, not their text');
T::eq(http('POST', '/api/v1/pending', $auth($publish))['status'], 404, 'These views are read-only');
// Stage 4: visits that came through a tagged link (social posts, exhibitor kits), and GET /stats.
$aid = (int)$own['id'];
http('POST', '/go/beacon', ['raw' => json_encode(['a' => $aid, 'u' => ['s' => 'facebook', 'm' => 'social', 'c' => 'buildasia', 't' => 'post-42']])]);
http('POST', '/go/beacon', ['raw' => json_encode(['a' => $aid, 'u' => ['s' => 'Facebook', 'm' => 'social', 'c' => 'buildasia', 't' => 'post-42']])]);
http('POST', '/go/beacon', ['raw' => json_encode(['a' => $aid, 'u' => ['s' => 'x', 'm' => 'social', 'c' => 'buildasia', 't' => 'post-43']]), 'ua' => 'Googlebot/2.1']);
http('POST', '/go/beacon', ['raw' => json_encode(['u' => ['s' => '<b>bad</b>', 'm' => 'social']])]);
http('POST', '/go/beacon', ['raw' => json_encode(['u' => ['s' => 'linkedin', 'm' => 'exhibitor-share', 'c' => 'pharmaasia', 't' => 'exhibitor-3']])]);
T::eq((int)db()->value("SELECT SUM(visits) FROM {p}visit_sources WHERE article_id = ? AND source = 'facebook' AND content = 'post-42'", [$aid]), 2, 'Visits from a social post are counted per article and post (robots are not)');
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}visit_sources WHERE source LIKE '%<%'"), 0, 'Odd tags are never stored');
$st = json_decode(http('GET', '/api/v1/stats?from=' . gmdate('Y-m-d', time() - 86400) . '&to=' . gmdate('Y-m-d') . '&campaign=buildasia', $auth($drafts))['body'], true);
$fb = array_values(array_filter($st['sources'] ?? [], static fn($x) => $x['content'] === 'post-42'));
T::ok(($st['ok'] ?? false) && $fb && $fb[0]['visits'] === 2 && $fb[0]['article_id'] === $aid, 'GET /stats gives the tagged visits for one campaign');
T::ok(!in_array('pharmaasia', array_column($st['sources'] ?? [], 'campaign'), true), '…and only for that campaign');
T::ok(in_array($aid, array_column($st['articles'] ?? [], 'id'), true) && $st['views_total'] >= 2, '…with article views for the period');
T::eq(http('POST', '/api/v1/stats', $auth($drafts))['status'], 404, 'The figures are read-only');
T::eq(http('GET', '/api/v1/stats')['status'], 401, 'And need an API key');
$js = (string)file_get_contents(dirname(__DIR__) . '/assets/js/blog.js');
T::ok(str_contains($js, "localStorage.setItem('aisa_arrival'") && str_contains($js, 'data.u = arrival'), 'Article pages keep the tags for the sales agent chat and send them with the view');
$own2 = publish_article('Same second as the last one', $machCat, ['body' => '<p>' . str_repeat('Excavators on site. ', 12) . '</p>']);
$at = (string)db()->value('SELECT published_at FROM {p}articles WHERE id = ?', [(int)$own['id']]);
db()->update('articles', ['published_at' => $at], 'id = ?', [(int)$own2['id']]);
$after = json_decode(http('GET', '/api/v1/published?since=' . rawurlencode(gmdate('c', strtotime($at . ' UTC'))) . '&after_id=' . (int)$own['id'], $auth($drafts))['body'], true);
T::eq(array_column($after['articles'] ?? [], 'id'), [(int)$own2['id']], 'An article published in the same second as the last one read is still listed (by its position), and the one already read is not');
$newest = json_decode(http('GET', '/api/v1/published?newest=1', $auth($drafts))['body'], true);
T::ok(count($newest['articles'] ?? []) === 1 && $newest['articles'][0]['id'] === (int)$own2['id'], 'The newest published article can be asked for on its own');
for ($i = 0, $limited = false; $i < 65 && !$limited; $i++) {
    $limited = http('GET', '/api/v1/ping', $auth($drafts))['status'] === 429;
}
T::ok($limited, 'A key is limited to 60 requests a minute');

// =====================================================================
T::section('Starter content (Build Asia pack)');
reset_blog();
$admin = make_user('admin');
Auth::actAs($admin);
Settings::set('event_venue', 'Typed by the team');
$packs = ContentPack::available();
T::ok(isset($packs['buildasia']), 'The Build Asia starter pack is included');
$pk = $packs['buildasia'];
$perCat = array_count_values(array_column($pk['articles'], 'category'));
T::ok(count($pk['categories']) >= 5 && min($perCat) >= 3 && max($perCat) <= 5, 'It has real categories with 3–5 articles each');
$res = ContentPack::import('buildasia', ['publish' => true, 'settings' => true]);
T::ok($res['ok'], 'It imports');
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}articles WHERE status = 'published'"), count($pk['articles']), 'All its articles are published');
$flagged = 0;
$leftover = 0;
$noSources = 0;
foreach (db()->all('SELECT body, sources, image_id FROM {p}articles') as $row) {
    $flagged += LinkPolicy::review($row['body'], json_decode((string)$row['sources'], true) ?: [])['flagged'];
    $leftover += preg_match('/\{\{/', $row['body']);
    $noSources += $row['image_id'] ? 0 : 1;
}
T::eq($flagged, 0, 'Every link in the articles follows the link rules');
T::eq($leftover, 0, 'All internal article links are resolved');
T::eq($noSources, 0, 'Every article has a cover image');
T::ok(str_contains((string)db()->value("SELECT body FROM {p}articles WHERE slug = 'cement-sales-fy26-what-the-numbers-say'"), '<h2>Sources</h2>'), 'Researched articles list their sources');
T::eq(Settings::get('event_venue'), 'Typed by the team', 'Settings the team already typed are kept');
T::eq(Settings::get('event_start'), '2027-03-18', 'Empty event details are filled in');
T::ok((int)Settings::get('logo_light', 0) > 0 && count((array)Settings::get('main_menu', [])) === 6, 'The logo and header menu are filled in');
$again = ContentPack::import('buildasia', ['publish' => true, 'settings' => true]);
T::eq((int)db()->value('SELECT COUNT(*) FROM {p}articles'), count($pk['articles']), 'Importing twice does not duplicate anything');
// A blog that imported an older, smaller pack gets only the new articles and categories.
$keepTitle = (string)db()->value("SELECT title FROM {p}articles WHERE slug = 'cement-sales-fy26-what-the-numbers-say'");
db()->update('articles', ['title' => 'Edited by the team'], 'slug = ?', ['cement-sales-fy26-what-the-numbers-say']);
$gone = (int)db()->value("SELECT id FROM {p}articles WHERE slug = 'psdp-2026-27-infrastructure-spending'");
db()->exec('DELETE FROM {p}article_tags WHERE article_id = ?', [$gone]);
db()->exec('DELETE FROM {p}articles WHERE id = ?', [$gone]);
db()->exec("DELETE FROM {p}categories WHERE slug = 'infrastructure-projects' AND NOT EXISTS (SELECT 1 FROM {p}articles a WHERE a.category_id = {p}categories.id)");
$newer = ContentPack::import('buildasia', ['publish' => true, 'settings' => true]);
T::ok($newer['ok'] && (int)db()->value("SELECT COUNT(*) FROM {p}articles WHERE slug = 'psdp-2026-27-infrastructure-spending' AND status = 'published'") === 1, 'Importing a newer pack adds the articles that were missing');
T::eq((string)db()->value("SELECT title FROM {p}articles WHERE slug = 'cement-sales-fy26-what-the-numbers-say'"), 'Edited by the team', '…and leaves articles the team edited alone');
db()->update('articles', ['title' => $keepTitle], 'slug = ?', ['cement-sales-fy26-what-the-numbers-say']);
T::eq((int)db()->value('SELECT COUNT(DISTINCT category_id) FROM {p}articles'), count($pk['categories']), 'Every category in the pack has articles');
Cache::clear();
T::eq(http('GET', '/market-insights/cement-sales-fy26-what-the-numbers-say')['status'], 200, 'A starter article opens on the blog');

// =====================================================================
T::section('Blog address out of date (styles must still load)');
$savedUrl = (string)Settings::get('site_url', '');
$cssOf = static function (string $html): string {
    return preg_match('#<link rel="stylesheet" href="([^"]+)"#', $html, $m) ? html_entity_decode($m[1]) : '';
};
$origin = (string)preg_replace('#^(https?://[^/]+).*$#', '$1', TEST_BASE);
// Saved as http://, opened over https (e.g. after the site got its SSL certificate).
Settings::set('site_url', $origin . '/blog');
Cache::clear();
$sec = http('GET', '/', ['headers' => ['X-Forwarded-Proto: https']]);
$css = $cssOf($sec['body']);
T::ok(str_starts_with($css, '/blog/assets/css/blog.css'), 'Styles are linked from this website, not from the saved address');
T::ok(str_contains($sec['body'], 'href="https://' . substr($origin, 7) . '/blog/'), 'On a secure visit, an http:// blog address is used as https://');
// Saved for another domain (e.g. the review copy's address on the live site).
Settings::set('site_url', 'https://buildasia.net/blog');
Cache::clear();
$other = http('GET', '/');
$css = $cssOf($other['body']);
T::ok(str_starts_with($css, '/blog/'), 'With a wrong domain saved, styles still come from this website');
$got = http('GET', $origin . $css);
T::ok($got['status'] === 200 && str_contains($got['headers'], 'text/css'), '…and the stylesheet loads');
// Links saved as full addresses at the old place (menus from a starter pack, slide buttons, article texts).
Settings::set('main_menu', [['label' => 'News', 'url' => 'https://buildasia.net/blog/news/'], ['label' => 'Main site', 'url' => 'https://buildasia.net/']]);
db()->exec("INSERT INTO {p}settings (name, value) VALUES ('footer_menu', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
    ['[{"label":"Events","url":"https:\\/\\/buildasia.net\\/blog\\/events\\/"}]']);
$moveArt = publish_article('Moved links article', make_category('Moving'), ['body' => '<p>' . str_repeat('Read more. ', 12) . '<a href="https://buildasia.net/blog/news/">news</a> <a href="https://example.com/blog/x">other</a></p>']);
Settings::flush();
$adm = http('GET', '/admin/?p=dashboard', ['jar' => $jar]);
T::has($adm['body'], 'The blog address does not match this website', 'The dashboard warns that the saved address is wrong');
T::ok(str_contains($adm['body'], 'href="/blog/admin/assets/admin.css'), 'The admin styles still load');
$fix = http('POST', '/admin/?p=dashboard', ['jar' => $jar, 'form' => ['do' => 'use_address', '_csrf' => csrf_from($adm['body'])]]);
Settings::flush();
T::eq(Settings::get('site_url'), $origin . '/blog', 'One click sets the address to the one in use');
$mm = (array)Settings::get('main_menu', []);
T::eq($mm[0]['url'] ?? '', $origin . '/blog/news/', '…and menu links saved with the old address now use the new one');
T::eq($mm[1]['url'] ?? '', 'https://buildasia.net/', '…while links outside the blog are left alone');
T::eq(((array)Settings::get('footer_menu', []))[0]['url'] ?? '', $origin . '/blog/events/', '…also when saved with escaped slashes');
$movedBody = (string)db()->value('SELECT body FROM {p}articles WHERE id = ?', [$moveArt['id']]);
T::ok(str_contains($movedBody, 'href="' . $origin . '/blog/news/"') && str_contains($movedBody, 'https://example.com/blog/x'), '…and links inside article texts too');
T::eq(Settings::moveLinks($origin . '/blog', $origin . '/blog'), 0, 'Nothing changes when the address stays the same');
Settings::set('main_menu', []);
Settings::set('footer_menu', []);
Settings::set('site_url', $savedUrl);
Cache::clear();

// =====================================================================
T::section('Industry Voices');
Settings::set('site_url', TEST_BASE);
Auth::actAs($admin);
$vCat = make_category('Opinion');
$cid = Voices::save(null, ['name' => 'Ayesha Malik', 'designation' => 'Chief Executive', 'company' => 'Indus Cement', 'company_url' => 'https://induscement.example.com',
    'linkedin_url' => 'https://www.linkedin.com/in/ayesha-malik', 'bio' => 'Twenty years in cement.', 'email' => 'ayesha@example.com', 'photo_id' => test_image('ayesha', 600, 600),
    'company_logo_id' => test_image('induslogo', 400, 200)]);
T::ok($cid > 0 && Voices::find($cid)['slug'] === 'ayesha-malik', 'An industry leader gets a contributor profile (no login needed)');
T::eq(Voices::save(null, ['name' => 'Bad Links', 'linkedin_url' => 'javascript:alert(1)', 'company_url' => 'not a url']) > 0, true, 'A profile with bad web addresses is still saved…');
T::eq(Voices::find((int)db()->value("SELECT id FROM {p}contributors WHERE name = 'Bad Links'"))['linkedin_url'], '', '…without the bad addresses');
$va = publish_article('Why cement demand will rise', $vCat, ['contributor_id' => $cid, 'body' => '<p>' . str_repeat('Housing needs cement. ', 15) . '</p>']);
Cache::clear();
$vp = http('GET', '/opinion/' . $va['slug']);
T::eq($vp['status'], 200, 'An Industry Voices article opens');
T::has($vp['body'], 'flag--voice', 'It carries the "Industry Voice" badge');
T::has($vp['body'], 'By Ayesha Malik</a>, Chief Executive, Indus Cement', 'The byline names the writer, designation and company');
T::has($vp['body'], 'About the author', 'A profile box follows the article');
T::has($vp['body'], 'href="https://www.linkedin.com/in/ayesha-malik" rel="nofollow noopener"', 'Their LinkedIn is linked (not passed on as a recommendation to Google)');
T::has($vp['body'], 'Views are the author&#039;s own and do not necessarily reflect those of Build Asia.', 'The "views are the author\'s own" line is shown, with the event\'s name');
T::has($vp['body'], '"author":{"@type":"Person","name":"Ayesha Malik"', 'Google is told the writer is Ayesha Malik');
$home = http('GET', '/');
T::has($home['body'], 'id="h-voices"', 'The home page has an Industry Voices section');
T::has($home['body'], 'href="' . TEST_BASE . '/industry-voices/">Industry Voices</a></li>', 'The header menu gets "Industry Voices" automatically');
$sec = http('GET', '/industry-voices/');
T::ok($sec['status'] === 200 && str_contains($sec['body'], 'Why cement demand will rise') && str_contains($sec['body'], 'voices-people'), 'The section page lists the articles and the contributors');
T::eq(http('GET', '/industry-voices')['status'], 301, 'Without the final slash it forwards to the section');
$prof = http('GET', '/industry-voices/ayesha-malik');
T::ok($prof['status'] === 200 && str_contains($prof['body'], '"@type":"ProfilePage"') && str_contains($prof['body'], 'Indus Cement'), 'Each contributor has a profile page');
T::eq(http('GET', '/industry-voices/nobody-here')['status'], 404, 'An unknown contributor is not found');
T::eq(http('GET', '/industry-voices/bad-links')['status'], 404, 'A contributor with no published article has no page yet');
$sm = Seo::sitemap();
T::ok(str_contains($sm, '/industry-voices/</loc>') && str_contains($sm, '/industry-voices/ayesha-malik</loc>'), 'The section and profiles are in the sitemap');
$pl = Webhook::articlePayload(Article::find((int)$va['id']));
T::ok(($pl['voice']['name'] ?? '') === 'Ayesha Malik' && $pl['voice']['company'] === 'Indus Cement' && $pl['voice']['linkedin_url'] !== '' && $pl['sponsor'] === null,
    'The notice to the Social Media Agent says who wrote it, for tagging');
$plainCard = View::card(Article::published('a.id = ?', [(int)publish_article('A plain article', $vCat)['id']])[0]);
T::lacks($plainCard, 'flag--voice', 'Ordinary articles have no badge');
// An author account linked to a contributor writes as them.
$voiceAuthor = make_user('author', 'Imran Qureshi');
$cid2 = Voices::save(null, ['name' => 'Imran Qureshi', 'company' => 'Steelco', 'user_id' => $voiceAuthor['id']]);
$jar3 = BLOG_STORAGE . '/tmp/test-cookies-3.txt';
@unlink($jar3);
$l3 = http('GET', '/admin/', ['jar' => $jar3]);
http('POST', '/admin/', ['jar' => $jar3, 'form' => ['_csrf' => csrf_from($l3['body']), 'email' => $voiceAuthor['email'], 'password' => 'Password123']]);
$ed = http('GET', '/admin/?p=article', ['jar' => $jar3]);
http('POST', '/admin/?p=article', ['jar' => $jar3, 'form' => ['_csrf' => csrf_from($ed['body']), 'do' => 'submit', 'title' => 'Steel from the inside',
    'body' => '<p>' . str_repeat('Steel mills explained. ', 10) . '</p>', 'category_id' => (string)$vCat, 'contributor_id' => (string)$cid, 'sponsor_name' => 'Sneaky Sponsor']]);
$sa = db()->one("SELECT * FROM {p}articles WHERE title = 'Steel from the inside'");
T::ok($sa && (int)$sa['contributor_id'] === $cid2 && $sa['status'] === 'pending', 'A leader with an author account writes as themselves and can only send for approval');
T::eq((string)$sa['sponsor_name'], '', 'An author cannot mark an article as sponsored');
// A category cannot take the section's address.
$catPage = http('GET', '/admin/?p=categories', ['jar' => $jar]);
http('POST', '/admin/?p=categories', ['jar' => $jar, 'form' => ['_csrf' => csrf_from($catPage['body']), 'do' => 'save_cat', 'name' => 'Industry Voices']]);
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}categories WHERE slug = 'industry-voices'"), 0, 'No category can take the Industry Voices address');
$cpage = http('GET', '/admin/?p=contributors', ['jar' => $jar]);
T::ok(str_contains($cpage['body'], 'Ayesha Malik') && str_contains($cpage['body'], 'Section settings'), 'Admins see the contributors and the section settings');
$cset = http('GET', '/admin/?p=contributors&tab=settings', ['jar' => $jar]);
http('POST', '/admin/?p=contributors', ['jar' => $jar, 'form' => ['_csrf' => csrf_from($cset['body']), 'do' => 'settings', 'voices_enabled' => '1', 'voices_name' => 'Leaders Speak',
    'voices_slug' => 'leaders', 'voices_badge' => 'Leader', 'voices_intro' => 'x', 'voices_disclaimer' => 'Own views.', 'voices_menu' => '1', 'voices_home_count' => '4',
    'write_enabled' => '1', 'write_slug' => 'write-for-us', 'write_title' => 'Write for us', 'write_intro' => 'Send us your ideas.', 'write_consent' => 'It is my own work.', 'write_notify' => 'editor@example.com, not-an-email']]);
Settings::flush();
Cache::clear();
T::ok(Voices::slug() === 'leaders' && http('GET', '/leaders/')['status'] === 200 && http('GET', '/industry-voices/')['status'] === 404, 'The section can be renamed and moved to another address');
T::eq((string)Settings::get('write_notify'), 'editor@example.com', 'Only valid addresses are kept for proposal emails');
Settings::set('voices_enabled', false);
Cache::clear();
T::eq(http('GET', '/leaders/')['status'], 404, 'Switched off, the section disappears…');
T::lacks(http('GET', '/opinion/' . $va['slug'])['body'], 'flag--voice', '…and so do the badges');
Settings::setMany(['voices_enabled' => true, 'voices_slug' => 'industry-voices', 'voices_name' => 'Industry Voices', 'voices_badge' => 'Industry Voice']);
Cache::clear();

T::section('"Write for us"');
$w = http('GET', '/write-for-us');
T::ok($w['status'] === 200 && str_contains($w['body'], 'name="designation"') && str_contains($w['body'], 'It is my own work.'), 'The public form opens, with the consent line');
$oldTs = static function (): string {
    $t = time() - 30;
    return $t . '.' . substr(hash_hmac('sha256', (string)$t, app_key()), 0, 16);
};
$form = ['name' => 'Bilal Khan', 'email' => 'bilal@example.com', 'designation' => 'Director', 'company' => 'Khan Builders', 'company_url' => 'https://khan.example.com',
    'linkedin_url' => 'https://www.linkedin.com/in/bilal', 'kind' => 'draft', 'title' => 'Prefab housing in Karachi',
    'body' => "Prefab is changing housing.\n\nHere is how we build faster.", 'consent' => '1', '_ts' => $oldTs(), 'website' => ''];
$miss = http('POST', '/write-for-us', ['form' => array_merge($form, ['company' => ''])]);
T::has((string)$miss['location'], 'w=missing', 'Missing details are refused with a message');
$noc = http('POST', '/write-for-us', ['form' => array_diff_key($form, ['consent' => 1])]);
T::has((string)$noc['location'], 'w=consent', 'The consent box must be ticked');
db()->exec('DELETE FROM {p}mail_outbox');
$sent = http('POST', '/write-for-us', ['form' => $form]);
T::has((string)$sent['location'], 'w=thanks', 'A complete proposal is accepted');
$sub = db()->one("SELECT * FROM {p}submissions WHERE email = 'bilal@example.com'");
T::ok($sub && $sub['status'] === 'new' && $sub['kind'] === 'draft' && $sub['consent_text'] === 'It is my own work.', 'It waits for the editors, with the consent the writer agreed to');
$mails = array_column(db()->all('SELECT to_email FROM {p}mail_outbox'), 'to_email');
T::ok(in_array('editor@example.com', $mails, true) && in_array('bilal@example.com', $mails, true), 'The editors are told, and the writer gets a thank-you email');
http('POST', '/write-for-us', ['form' => array_merge($form, ['website' => 'spam.example.com', 'email' => 'robot@example.com'])]);
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}submissions WHERE email = 'robot@example.com'"), 0, 'Robots filling the hidden field are ignored');
T::eq((int)db()->value("SELECT COUNT(*) FROM {p}articles WHERE title = 'Prefab housing in Karachi'"), 0, 'Nothing is published or even drafted by itself');
$inbox = http('GET', '/admin/?p=submissions', ['jar' => $jar]);
T::has($inbox['body'], 'Prefab housing in Karachi', 'The proposal appears in the admin');
$one = http('GET', '/admin/?p=submissions&id=' . $sub['id'], ['jar' => $jar]);
$acc = http('POST', '/admin/?p=submissions', ['jar' => $jar, 'form' => ['_csrf' => csrf_from($one['body']), 'id' => (string)$sub['id'], 'do' => 'accept']]);
$draft = db()->one("SELECT * FROM {p}articles WHERE title = 'Prefab housing in Karachi'");
$bilal = $draft ? Voices::find((int)$draft['contributor_id']) : null;
T::ok($draft && $draft['status'] === 'draft' && str_contains((string)$draft['body'], '<p>Here is how we build faster.</p>'), '"Make it a draft article" creates a draft with the writer\'s text');
T::ok($bilal && $bilal['company'] === 'Khan Builders' && $bilal['email'] === 'bilal@example.com', '…and the writer\'s contributor profile');
T::has((string)$acc['location'], 'p=article', 'The editor is taken straight to the draft');
T::eq((string)db()->value('SELECT status FROM {p}submissions WHERE id = ?', [$sub['id']]), 'accepted', 'The proposal is marked as done');
Settings::set('write_enabled', false);
T::eq(http('GET', '/write-for-us')['status'], 404, 'The form can be switched off');
Settings::set('write_enabled', true);

T::section('Sponsored articles');
$sCat = make_category('Partner News');
$sp = Article::save(null, ['title' => 'Faster concrete with Acme admixtures', 'category_id' => $sCat,
    'body' => '<p>' . str_repeat('Admixtures cut curing time. ', 12) . ' <a href="https://acme.example.com/admix">See the range</a> and <a href="' . TEST_BASE . '/opinion/' . $va['slug'] . '">our view</a>.</p>',
    'sponsor_name' => 'Acme Chemicals', 'sponsor_url' => 'https://acme.example.com', 'sponsor_label' => 'sponsored', 'sponsor_ends' => gmdate('Y-m-d H:i:s', time() + 86400 * 20)], []);
T::eq(Article::transition($sp, 'published')['ok'], true, 'An approver publishes a sponsored article');
Cache::clear();
$spa = Article::find($sp);
$spp = http('GET', '/partner-news/' . $spa['slug']);
T::has($spp['body'], 'flag--sponsor">Sponsored</span>', 'Readers see the "Sponsored" label');
T::has($spp['body'], 'This article was paid for by Acme Chemicals. The Build Asia editors reviewed it before publishing.', '…and the disclosure line, with the sponsor and event filled in');
T::has($spp['body'], 'href="' . TEST_BASE . '/go/a/' . $sp . '?u=https%3A%2F%2Facme.example.com%2Fadmix&amp;s=', 'Outside links go through the blog, so clicks are counted');
T::ok((bool)preg_match('#go/a/' . $sp . '\?u=https%3A%2F%2Facme\.example\.com%2Fadmix[^"]*" rel="sponsored noopener" target="_blank"#', $spp['body']), '…and are marked "sponsored" for Google');
T::has($spp['body'], 'href="' . TEST_BASE . '/opinion/' . $va['slug'] . '"', 'Links to the blog\'s own pages stay as they are');
$home = http('GET', '/');
T::ok(str_contains($home['body'], 'id="h-partner"') && str_contains($home['body'], 'Sponsored · Acme Chemicals'), 'During its paid period it is listed in "From our partners" on the home page, labelled');
$go = Sponsored::goUrl($sp, 'https://acme.example.com/admix');
$clk = http('GET', substr($go, strlen(TEST_BASE)));
T::ok($clk['status'] === 302 && $clk['location'] === 'https://acme.example.com/admix', 'A click takes the reader to the sponsor');
T::eq((int)db()->value('SELECT SUM(clicks) FROM {p}article_clicks WHERE article_id = ?', [$sp]), 1, 'The click is counted');
http('GET', substr($go, strlen(TEST_BASE)), ['ua' => 'Googlebot/2.1']);
T::eq((int)db()->value('SELECT SUM(clicks) FROM {p}article_clicks WHERE article_id = ?', [$sp]), 1, 'Search robots are not counted');
T::eq(http('GET', '/go/a/' . $sp . '?u=' . rawurlencode('https://evil.example.com') . '&s=0000')['status'], 404, 'The counting address cannot be used to send people elsewhere');
db()->exec('INSERT INTO {p}article_views (article_id, day, views) VALUES (?, ?, 40)', [$sp, gmdate('Y-m-d')]);
$csv = http('GET', '/admin/?p=sponsored&report=' . $sp . '&format=csv', ['jar' => $jar]);
T::ok(str_contains($csv['body'], 'Date,"Readers (views)","Clicks to outside links"') && str_contains($csv['body'], 'Total,40,1'), 'The sponsor report downloads as a spreadsheet, with readers and clicks');
$rp = http('GET', '/admin/?p=sponsored&report=' . $sp, ['jar' => $jar]);
T::ok(str_contains($rp['body'], 'Sponsor report: Acme Chemicals') && str_contains($rp['body'], 'Faster concrete with Acme admixtures') && str_contains($rp['body'], 'Print / Save as PDF'), '…or as a printable page for the sponsor');
$list = http('GET', '/admin/?p=sponsored', ['jar' => $jar]);
T::ok(str_contains($list['body'], 'Acme Chemicals') && str_contains($list['body'], 'Running'), 'The admin lists sponsored articles with their state');
$plS = Webhook::articlePayload($spa);
T::ok(($plS['sponsor']['name'] ?? '') === 'Acme Chemicals' && $plS['sponsor']['label'] === 'Sponsored', 'The Social Media Agent is told it is sponsored, so its posts are labelled');
// Paid period that starts later: approval schedules it.
$later = Article::save(null, ['title' => 'Partner launch next week', 'category_id' => $sCat, 'body' => '<p>' . str_repeat('A launch is coming. ', 10) . '</p>',
    'sponsor_name' => 'Beta Tiles', 'sponsor_label' => 'partner', 'sponsor_starts' => gmdate('Y-m-d H:i:00', time() + 86400 * 3)], []);
$res = Article::transition($later, 'published');
$la = Article::find($later);
T::ok($res['ok'] && $la['status'] === 'scheduled' && $la['publish_at'] === $la['sponsor_starts'], 'Approved before its paid period starts, it goes live when the period starts');
T::eq(Sponsored::label($la), 'Partner content', 'The "Partner content" label can be used instead');
$bad = Article::save(null, ['title' => 'Wrong dates', 'category_id' => $sCat, 'body' => '<p>' . str_repeat('Dates are wrong. ', 10) . '</p>',
    'sponsor_name' => 'Gamma', 'sponsor_starts' => '2026-12-10 00:00:00', 'sponsor_ends' => '2026-12-01 00:00:00'], []);
T::eq(Article::transition($bad, 'published')['ok'], false, 'A paid period that ends before it starts is refused');
// End of the paid period.
$gone = publish_article('Old partner article', $sCat, ['sponsor_name' => 'Delta', 'sponsor_after' => 'archive']);
$kept = publish_article('Kept partner article', $sCat, ['sponsor_name' => 'Epsilon', 'sponsor_after' => 'keep']);
db()->exec("UPDATE {p}articles SET sponsor_ends = ? WHERE id IN (?, ?)", [gmdate('Y-m-d H:i:s', time() - 60), $gone['id'], $kept['id']]);
T::eq(Sponsored::endDue(), 1, 'When the paid period ends, an article set to "take it off" is archived');
T::ok(Article::find((int)$gone['id'])['status'] === 'archived' && Article::find((int)$kept['id'])['status'] === 'published', '…and one set to "keep" stays, still labelled');
Cache::clear();
$hb = http('GET', '/')['body'];
$partnerPart = (string)strstr((string)strstr($hb, 'id="h-partner"'), '</section>', true);
T::ok($partnerPart !== '' && str_contains($partnerPart, 'Acme') && !str_contains($partnerPart, 'Kept partner article'), 'After its paid period it leaves the "From our partners" section');
$edS = http('GET', '/admin/?p=article&id=' . $sp, ['jar' => $jar]);
T::ok(str_contains($edS['body'], 'name="sponsor_name"') && str_contains($edS['body'], 'Sponsor report'), 'The article editor has the sponsored-article panel and the report link');
Auth::actAs(null);

// =====================================================================
T::section('Scheduled tasks');
$out = (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php -r ' . escapeshellarg(
    '$GLOBALS["BLOG_CONFIG_OVERRIDE"]=' . var_export($GLOBALS['BLOG_CONFIG_OVERRIDE'], true) . '; define("BLOG_TESTING",true); $argv=[]; require "cron.php";') . ' 2>&1');
T::has($out, 'scheduled articles published', 'The cron job runs');
T::ok((string)Settings::get('last_cron', '') !== '' || (Settings::flush() || true) && (string)Settings::get('last_cron', '') !== '', 'It records when it last ran (shown in the admin)');
$web = http('GET', '/cron.php');
T::ok($web['status'] === 403 || !str_contains($web['body'], 'scheduled articles'), 'It cannot be run from a browser');

exit(T::summary());
