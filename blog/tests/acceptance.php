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
T::lacks(Theme::bodyClasses(array_merge(Settings::defaultTheme(), ['band' => false, 'motion' => false])), 'motion-on', 'Stripe and motion can be switched off');
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
    'seo', 'chat', 'email', 'subscribers', 'users', 'profile', 'apikeys', 'activity'];
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
for ($i = 0, $limited = false; $i < 65 && !$limited; $i++) {
    $limited = http('GET', '/api/v1/ping', $auth($drafts))['status'] === 429;
}
T::ok($limited, 'A key is limited to 60 requests a minute');

// =====================================================================
T::section('Scheduled tasks');
$out = (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php -r ' . escapeshellarg(
    '$GLOBALS["BLOG_CONFIG_OVERRIDE"]=' . var_export($GLOBALS['BLOG_CONFIG_OVERRIDE'], true) . '; define("BLOG_TESTING",true); $argv=[]; require "cron.php";') . ' 2>&1');
T::has($out, 'scheduled articles published', 'The cron job runs');
T::ok((string)Settings::get('last_cron', '') !== '' || (Settings::flush() || true) && (string)Settings::get('last_cron', '') !== '', 'It records when it last ran (shown in the admin)');
$web = http('GET', '/cron.php');
T::ok($web['status'] === 403 || !str_contains($web['body'], 'scheduled articles'), 'It cannot be run from a browser');

exit(T::summary());
