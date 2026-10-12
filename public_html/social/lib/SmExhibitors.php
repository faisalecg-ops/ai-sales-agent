<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * The exhibition's exhibitors and their share kits. The list comes one of three
 * ways (an admin chooses): read from the event website's exhibitor page, typed
 * in, or uploaded as a spreadsheet (CSV or Excel). Each kit — a ready post, a
 * branded picture with the exhibitor's logo and stand, and a counted
 * registration link — is approved by a person before it is sent.
 */
final class SmExhibitors
{
    public const SOURCES = [
        'website' => 'Read it from the event website\'s exhibitor page',
        'typed'   => 'Type it in here',
        'upload'  => 'Upload a spreadsheet (CSV or Excel)',
    ];

    public static function key(string $company): string
    {
        $k = mb_strtolower(trim($company));
        $k = preg_replace('/\b(pvt|private|ltd|limited|llc|inc|co|company|plc|smc|\(pvt\)|\(private\))\b\.?/u', ' ', $k) ?? $k;
        return mb_substr(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $k) ?? $k), 0, 200);
    }

    public static function find(int $agentId, int $id): ?array
    {
        return db()->one('SELECT * FROM sm_exhibitors WHERE id = ? AND agent_id = ?', [$id, $agentId]);
    }

    /**
     * Add one exhibitor, or fill in what an existing one (same company) is missing.
     * @return array{id:int, added:bool}|null null when there is no company name
     */
    public static function upsert(int $agentId, array $d, string $source): ?array
    {
        $company = mb_substr(trim(strip_tags((string)($d['company'] ?? ''))), 0, 200);
        $key = self::key($company);
        if ($company === '' || $key === '') {
            return null;
        }
        $web = trim((string)($d['website'] ?? ''));
        if ($web !== '' && !preg_match('#^https?://#i', $web)) {
            $web = 'https://' . $web;
        }
        $row = [
            'stand' => mb_substr(trim((string)($d['stand'] ?? '')), 0, 60) ?: null,
            'hall' => mb_substr(trim((string)($d['hall'] ?? '')), 0, 60) ?: null,
            'website' => $web !== '' && Validator::url($web) !== null ? mb_substr($web, 0, 500) : null,
            'email' => Validator::email((string)($d['email'] ?? '')),
            'contact_name' => mb_substr(trim((string)($d['contact_name'] ?? '')), 0, 160) ?: null,
            'logo_media_id' => !empty($d['logo_media_id']) ? (int)$d['logo_media_id'] : null,
        ];
        $old = db()->one('SELECT * FROM sm_exhibitors WHERE agent_id = ? AND company_key = ?', [$agentId, $key]);
        if ($old) {
            $upd = [];
            foreach ($row as $k => $v) {
                if ($v !== null && ($source !== 'website' || empty($old[$k]) || in_array($k, ['stand', 'hall'], true))) {
                    $upd[$k] = $v;
                }
            }
            if ($upd) {
                db()->update('sm_exhibitors', $upd, 'id = ?', [(int)$old['id']]);
            }
            return ['id' => (int)$old['id'], 'added' => false];
        }
        $id = db()->insert('sm_exhibitors', ['agent_id' => $agentId, 'company' => $company, 'company_key' => $key, 'source' => $source] + $row);
        return ['id' => (int)$id, 'added' => true];
    }

    // -----------------------------------------------------------------
    // Way 3: a spreadsheet
    // -----------------------------------------------------------------

    /** Column names people use, mapped to ours. */
    private const HEADERS = [
        'company' => ['company', 'company name', 'exhibitor', 'exhibitor name', 'name', 'organisation', 'organization', 'firm'],
        'stand' => ['stand', 'stand no', 'stand number', 'booth', 'booth no', 'booth number', 'stall', 'stall no'],
        'hall' => ['hall', 'hall no', 'hall number'],
        'website' => ['website', 'web', 'url', 'web site', 'site'],
        'email' => ['email', 'e-mail', 'email address', 'contact email'],
        'contact_name' => ['contact', 'contact person', 'contact name', 'person'],
        'logo_url' => ['logo', 'logo url', 'logo link'],
    ];

    /** @return array{ok:bool, error?:string, rows?:list<array>} */
    public static function readSpreadsheet(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $grid = self::readXlsx($path);
            if ($grid === null) {
                return ['ok' => false, 'error' => 'That Excel file could not be read. Save it again as .xlsx or .csv and try once more.'];
            }
        } elseif (in_array($ext, ['csv', 'txt'], true)) {
            $grid = [];
            $fh = fopen($path, 'r');
            $first = (string)fgets($fh);
            rewind($fh);
            $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
            while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false && count($grid) < 3000) {
                $grid[] = $r;
            }
            fclose($fh);
            if (isset($grid[0][0])) {
                $grid[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$grid[0][0]);
            }
        } else {
            return ['ok' => false, 'error' => 'Upload a .csv or .xlsx file (Excel: File → Save as → Excel Workbook or CSV).'];
        }
        if (count($grid) < 2) {
            return ['ok' => false, 'error' => 'The file has no rows under the column names.'];
        }
        $map = [];
        foreach ($grid[0] as $i => $h) {
            $h = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} -]+/u', ' ', (string)$h) ?? ''));
            $h = trim(preg_replace('/\s+/', ' ', $h) ?? $h);
            foreach (self::HEADERS as $field => $names) {
                if (!isset($map[$field]) && in_array($h, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }
        if (!isset($map['company'])) {
            return ['ok' => false, 'error' => 'No company column was found. Name the first column "Company" (other columns: Stand, Hall, Website, Email, Contact, Logo).'];
        }
        $rows = [];
        foreach (array_slice($grid, 1) as $r) {
            $row = [];
            foreach ($map as $field => $i) {
                $row[$field] = trim((string)($r[$i] ?? ''));
            }
            if ($row['company'] !== '') {
                $rows[] = $row;
            }
        }
        return ['ok' => true, 'rows' => $rows];
    }

    /** The first sheet of an .xlsx file as rows of cells (no extra libraries). */
    public static function readXlsx(string $path): ?array
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $strings = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && ($x = @simplexml_load_string($ss)) !== false) {
            foreach ($x->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                }
                foreach ($si->r as $run) {
                    $t .= (string)$run->t;
                }
                $strings[] = $t;
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false || ($x = @simplexml_load_string($sheet)) === false) {
            return null;
        }
        $grid = [];
        foreach ($x->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = 0;
                foreach (str_split(preg_replace('/\d+/', '', $ref) ?: 'A') as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $type = (string)$c['t'];
                $v = $type === 'inlineStr' ? (string)$c->is->t : (string)$c->v;
                if ($type === 's') {
                    $v = $strings[(int)$v] ?? '';
                }
                $cells[$col - 1] = $v;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $grid[] = $line;
            }
            if (count($grid) >= 3000) {
                break;
            }
        }
        return $grid;
    }

    /** Download a logo from a web address into the library. */
    public static function fetchLogo(int $agentId, string $url, string $company): ?int
    {
        if (!SmNet::publicUrl($url)) {
            return null;
        }
        $r = SmNet::request('GET', $url, ['Accept' => 'image/*'], null, 20);
        if ($r['status'] !== 200 || strlen($r['body']) < 100 || strlen($r['body']) > 5 * 1024 * 1024 || @getimagesizefromstring($r['body']) === false) {
            return null;
        }
        $st = SmMedia::store($agentId, $r['body'], 'Logo: ' . mb_substr($company, 0, 150), 'upload', null);
        return !empty($st['ok']) ? (int)$st['id'] : null;
    }

    /** @return array{added:int, updated:int, logos:int} */
    public static function importRows(int $agentId, array $rows, string $source): array
    {
        $added = $updated = $logos = 0;
        foreach ($rows as $row) {
            $res = self::upsert($agentId, $row, $source);
            if ($res === null) {
                continue;
            }
            $res['added'] ? $added++ : $updated++;
            $ex = self::find($agentId, $res['id']);
            if ($ex && empty($ex['logo_media_id']) && !empty($row['logo_url']) && ($mid = self::fetchLogo($agentId, (string)$row['logo_url'], (string)$ex['company']))) {
                db()->update('sm_exhibitors', ['logo_media_id' => $mid], 'id = ?', [(int)$ex['id']]);
                $logos++;
            }
        }
        return ['added' => $added, 'updated' => $updated, 'logos' => $logos];
    }

    // -----------------------------------------------------------------
    // Way 1: the event website's exhibitor page
    // -----------------------------------------------------------------

    /**
     * Read the exhibitor page. The AI only picks out what is on the page: every
     * company it returns must appear in the page's text, every logo must be one
     * of the page's own pictures.
     * @return array{ok:bool, error?:string, added?:int, updated?:int, logos?:int, found?:int}
     */
    public static function importWebsite(array $agent, string $url): array
    {
        $aid = (int)$agent['id'];
        if (!preg_match('#^https://#i', $url) || Validator::url($url) === null || !SmNet::publicUrl($url)) {
            return ['ok' => false, 'error' => 'Give the full public address of the exhibitor page, starting with https://'];
        }
        $r = SmNet::request('GET', $url, ['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; EventSocialAgent/1.0)'], null, 30);
        if ($r['status'] !== 200 || trim($r['body']) === '') {
            return ['ok' => false, 'error' => 'The exhibitor page could not be opened (' . SmNet::reason($r) . ').'];
        }
        $html = $r['body'];
        $text = SmSources::readable($html);
        $images = [];
        if (preg_match_all('#<img\b[^>]*>#i', $html, $m)) {
            foreach ($m[0] as $tag) {
                $src = preg_match('#\b(?:data-src|src)=["\']([^"\']+)["\']#i', $tag, $s) ? html_entity_decode($s[1]) : '';
                $alt = preg_match('#\balt=["\']([^"\']*)["\']#i', $tag, $a) ? html_entity_decode($a[1]) : '';
                $abs = self::absolute($src, $url);
                if ($abs !== null) {
                    $images[$abs] = $alt;
                }
            }
        }
        $imgList = '';
        foreach (array_slice($images, 0, 250, true) as $src => $alt) {
            $imgList .= "- $src" . ($alt !== '' ? " (alt: $alt)" : '') . "\n";
        }
        $system = 'You read an exhibition website\'s exhibitor list page and return the exhibitors on it. Use only what is on the page. Never invent a company, stand, email or address.'
            . "\nReturn: {\"exhibitors\":[{\"company\":\"exact name as on the page\",\"stand\":\"\",\"hall\":\"\",\"website\":\"\",\"email\":\"\",\"logo_url\":\"one of the listed pictures, or empty\"}]}";
        $user = "Page: $url\n\nText of the page:\n" . mb_substr($text, 0, 24000) . "\n\nPictures on the page:\n" . mb_substr($imgList, 0, 12000);
        $out = SmAi::json($agent, 'exhibitors', $system, $user, 6000);
        if (isset($out['_error'])) {
            return ['ok' => false, 'error' => (string)$out['_error']];
        }
        $hay = mb_strtolower($text . ' ' . implode(' ', $images));
        $rows = [];
        foreach ((array)($out['exhibitors'] ?? []) as $e) {
            $c = trim((string)($e['company'] ?? ''));
            if ($c === '' || mb_stripos($hay, mb_strtolower($c)) === false) {
                continue;   // not on the page: never added
            }
            $logo = (string)($e['logo_url'] ?? '');
            $email = (string)($e['email'] ?? '');
            $rows[] = ['company' => $c, 'stand' => (string)($e['stand'] ?? ''), 'hall' => (string)($e['hall'] ?? ''), 'website' => (string)($e['website'] ?? ''),
                'email' => $email !== '' && mb_stripos($html, $email) !== false ? $email : '',
                'logo_url' => isset($images[$logo]) ? $logo : ''];
        }
        if (!$rows) {
            return ['ok' => false, 'error' => 'No exhibitors were found on that page. Check the address, or type the list in or upload it instead.'];
        }
        $res = self::importRows($aid, $rows, 'website');
        db()->update('sm_events', ['exhibitors_checked_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [$aid]);
        return ['ok' => true, 'found' => count($rows)] + $res;
    }

    private static function absolute(string $src, string $base): ?string
    {
        $src = trim($src);
        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }
        if (preg_match('#^https://#i', $src)) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }
        $p = parse_url($base);
        $root = 'https://' . ($p['host'] ?? '');
        if (str_starts_with($src, '/')) {
            return $root . $src;
        }
        $dir = rtrim(preg_replace('#/[^/]*$#', '/', (string)($p['path'] ?? '/')) ?? '/', '/');
        return $root . $dir . '/' . $src;
    }

    /** Weekly re-read of the exhibitor page, for events that chose the website. */
    public static function refreshAll(): int
    {
        $n = 0;
        foreach (db()->all("SELECT e.agent_id FROM sm_events e JOIN agents g ON g.id = e.agent_id AND g.status <> 'deleted'
                            WHERE e.exhibitors_checked_at IS NULL OR e.exhibitors_checked_at < UTC_TIMESTAMP() - INTERVAL 7 DAY") as $r) {
            $s = SmEvent::settings((int)$r['agent_id']);
            if ($s['exhibitor_source'] !== 'website' || trim((string)$s['exhibitor_page_url']) === '') {
                continue;
            }
            $agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$r['agent_id']]);
            $res = self::importWebsite($agent, (string)$s['exhibitor_page_url']);
            db()->update('sm_events', ['exhibitors_checked_at' => gmdate('Y-m-d H:i:s')], 'agent_id = ?', [(int)$r['agent_id']]);
            if ($res['ok'] && ($res['added'] ?? 0) > 0) {
                SmActivity::log((int)$r['agent_id'], 'exhibitors_found', $res['added'] . ' new exhibitor(s) on the website', ['id' => 0, 'full_name' => 'System', 'username' => 'system']);
                $n += (int)$res['added'];
            }
        }
        return $n;
    }

    // -----------------------------------------------------------------
    // The kits
    // -----------------------------------------------------------------

    public static function standLine(array $ex): string
    {
        $parts = [];
        if (trim((string)$ex['stand']) !== '') {
            $parts[] = 'Stand ' . trim((string)$ex['stand']);
        }
        if (trim((string)$ex['hall']) !== '') {
            $parts[] = (stripos((string)$ex['hall'], 'hall') === false ? 'Hall ' : '') . trim((string)$ex['hall']);
        }
        return implode(' · ', $parts);
    }

    public static function kitUrl(array $ex): string
    {
        return url('social/kit.php?k=' . $ex['kit_token']);
    }

    /** The counted registration link in each kit. */
    public static function trackUrl(array $ex, string $platform = ''): string
    {
        return url('social/r.php?k=' . $ex['kit_token'] . ($platform !== '' ? '&c=' . $platform : ''));
    }

    /**
     * Write kits for exhibitors that have none: texts by the AI (a few at a time),
     * pictures built here. They wait for approval.
     * @return array{ok:bool, made:int, error?:string}
     */
    public static function makeKits(array $agent, array $ids, ?int $userId): array
    {
        $aid = (int)$agent['id'];
        $f = SmEvent::facts($agent);
        if (trim($f['register']) === '') {
            return ['ok' => false, 'made' => 0, 'error' => 'The registration address is missing. Add it in the sales agent (Agents and embed → Dates & venue) first: every kit links to it.'];
        }
        $list = [];
        foreach ($ids as $id) {
            $ex = self::find($aid, (int)$id);
            if ($ex && $ex['kit_status'] === 'none') {
                $list[] = $ex;
            }
        }
        $made = 0;
        $tags = [];
        foreach (SmEvent::platforms($aid) as $p => $row) {
            $tags[$p] = trim((string)($row['hashtags'] ?? ''));
        }
        foreach (array_chunk($list, 8) as $batch) {
            $lines = [];
            foreach ($batch as $i => $ex) {
                $lines[] = "$i. {$ex['company']}" . (self::standLine($ex) !== '' ? ' — ' . self::standLine($ex) : ' — stand not yet known');
            }
            $system = "You write a short social media post for each exhibitor below to share on their own pages, inviting their customers to visit them at {$f['name']}"
                . ($f['dates'] !== '' ? " ({$f['dates']}" . ($f['venue'] !== '' ? ", {$f['venue']}" : '') . ')' : '') . '.'
                . "\nWrite as the exhibitor (\"we\"). Mention the stand and hall exactly as given (or leave them out if unknown). Say nothing about the exhibitor's products or claims that are not given. The registration link is added by the system: do not write any URL."
                . "\nFor each: a LinkedIn version (60–110 words, professional) and a Facebook version (30–60 words, warm). No hashtags. British English. No emoji."
                . "\nReturn: {\"kits\":[{\"n\":0,\"linkedin\":\"…\",\"facebook\":\"…\"}]}";
            $out = SmAi::json($agent, 'exhibitor_kits', $system, "Exhibitors:\n" . implode("\n", $lines), 3500);
            if (isset($out['_error'])) {
                return ['ok' => $made > 0, 'made' => $made, 'error' => (string)$out['_error']];
            }
            $byN = [];
            foreach ((array)($out['kits'] ?? []) as $k) {
                $byN[(int)($k['n'] ?? -1)] = $k;
            }
            foreach ($batch as $i => $ex) {
                $k = $byN[$i] ?? null;
                if (!$k || trim((string)($k['linkedin'] ?? '')) === '') {
                    continue;
                }
                $texts = ['linkedin' => trim((string)$k['linkedin']), 'facebook' => trim((string)($k['facebook'] ?? $k['linkedin'])),
                    'hashtags' => trim(($tags['linkedin'] ?? '') . ' ' . ($tags['facebook'] ?? ''))];
                $texts['hashtags'] = implode(' ', array_unique(preg_split('/\s+/', $texts['hashtags'], -1, PREG_SPLIT_NO_EMPTY) ?: []));
                $headline = 'Visit ' . $ex['company'] . ' at ' . $f['name'];
                $extra = ['logo_id' => (int)$ex['logo_media_id'], 'subline' => self::standLine($ex)];
                $wide = SmMedia::build($agent, $headline, 'landscape', null, $userId, $extra);
                $square = SmMedia::build($agent, $headline, 'square', null, $userId, $extra);
                db()->update('sm_exhibitors', [
                    'kit_texts' => json_encode($texts, JSON_UNESCAPED_UNICODE), 'kit_status' => 'ready', 'kit_token' => $ex['kit_token'] ?: bin2hex(random_bytes(20)),
                    'kit_media_wide' => !empty($wide['ok']) ? (int)$wide['id'] : null, 'kit_media_square' => !empty($square['ok']) ? (int)$square['id'] : null,
                    'kit_approved_by' => null, 'last_error' => null,
                ], 'id = ?', [(int)$ex['id']]);
                $made++;
            }
        }
        SmActivity::log($aid, 'kits_made', $made . ' exhibitor kit(s)');
        return ['ok' => true, 'made' => $made];
    }

    /** The full text the exhibitor copies, with the counted link. */
    public static function kitText(array $ex, string $platform): string
    {
        $t = json_decode((string)$ex['kit_texts'], true) ?: [];
        return trim((string)($t[$platform] ?? '')) . "\n\n" . self::trackUrl($ex, $platform) . (trim((string)($t['hashtags'] ?? '')) !== '' ? "\n\n" . $t['hashtags'] : '');
    }

    /**
     * Email approved kits through the exhibition's own email profile.
     * @return array{sent:int, skipped:int, error?:string}
     */
    public static function send(array $agent, array $ids): array
    {
        $aid = (int)$agent['id'];
        if (($why = MailProfile::problem($agent)) !== null) {
            return ['sent' => 0, 'skipped' => count($ids), 'error' => 'The exhibition\'s email profile is not ready (' . $why . '). Set it up in the sales agent, or copy each kit\'s link instead.'];
        }
        $profile = MailProfile::forAgent($agent);
        $f = SmEvent::facts($agent);
        $sent = $skipped = 0;
        foreach ($ids as $id) {
            $ex = self::find($aid, (int)$id);
            if (!$ex || !in_array($ex['kit_status'], ['approved', 'sent'], true) || empty($ex['email'])) {
                $skipped++;
                continue;
            }
            $hello = trim((string)$ex['contact_name']) !== '' ? 'Dear ' . trim((string)$ex['contact_name']) : 'Dear ' . $ex['company'] . ' team';
            $body = "$hello,\n\nThank you for exhibiting at {$f['name']}" . ($f['dates'] !== '' ? " ({$f['dates']})" : '') . ".\n\n"
                . "We have prepared a ready-to-share post for your LinkedIn and Facebook pages, with a picture showing your logo"
                . (self::standLine($ex) !== '' ? ' and ' . self::standLine($ex) : '') . ". Copy it and post it in a minute:\n"
                . self::kitUrl($ex) . "\n\nThe link in the post takes your customers to visitor registration. Sharing it early helps more of your buyers find you at the show.\n\n"
                . "Kind regards,\n{$profile['from_name']}";
            $res = Mailer::sendWith($profile, (string)$ex['email'], 'Your share kit for ' . $f['name'], $body);
            if (!empty($res['ok'])) {
                db()->update('sm_exhibitors', ['kit_status' => 'sent', 'kit_sent_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null], 'id = ?', [(int)$ex['id']]);
                $sent++;
            } else {
                db()->update('sm_exhibitors', ['last_error' => 'Email failed: ' . mb_substr((string)($res['error'] ?? ''), 0, 400)], 'id = ?', [(int)$ex['id']]);
                $skipped++;
            }
        }
        SmActivity::log($aid, 'kits_sent', $sent . ' kit(s) emailed');
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** Count a click on a kit's link (search robots and link previews are not counted). */
    public static function click(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        $ex = db()->one("SELECT * FROM sm_exhibitors WHERE kit_token = ? AND kit_status IN ('approved','sent')", [$token]);
        if (!$ex) {
            return null;
        }
        $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua !== '' && !preg_match('/bot|crawl|spider|preview|slurp|facebookexternalhit|whatsapp|linkedin|twitterbot|embedly|curl|wget|python/', $ua)) {
            db()->exec('INSERT INTO sm_kit_clicks (exhibitor_id, day, clicks) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1', [(int)$ex['id'], gmdate('Y-m-d')]);
        }
        return $ex;
    }

    /** Clicks per exhibitor, most first. */
    public static function report(int $agentId): array
    {
        return db()->all("SELECT x.*, COALESCE(SUM(c.clicks), 0) AS clicks FROM sm_exhibitors x LEFT JOIN sm_kit_clicks c ON c.exhibitor_id = x.id
                          WHERE x.agent_id = ? GROUP BY x.id ORDER BY clicks DESC, x.company", [$agentId]);
    }
}
