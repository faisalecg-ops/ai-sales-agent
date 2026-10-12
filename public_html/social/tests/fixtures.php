<?php
/**
 * Test helper (not deployed): stand-ins for the AI, web research, page reading,
 * AI pictures and email, shared between the test script and the test web
 * server through files in a temporary folder.
 */
declare(strict_types=1);

final class SmTestBus
{
    public static function dir(): string
    {
        $d = sys_get_temp_dir() . '/sm-test-bus';
        if (!is_dir($d)) {
            @mkdir($d, 0777, true);
        }
        return $d;
    }

    public static function reset(): void
    {
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Queue the AI's next answer(s). */
    public static function pushAi(array ...$answers): void
    {
        $q = self::read('ai-queue');
        foreach ($answers as $a) {
            $q[] = json_encode($a, JSON_UNESCAPED_UNICODE);
        }
        self::write('ai-queue', $q);
    }

    public static function popAi(): ?string
    {
        $f = self::dir() . '/ai-queue.json';
        $h = fopen($f, 'c+');
        flock($h, LOCK_EX);
        $q = json_decode((string)stream_get_contents($h), true) ?: [];
        $next = array_shift($q);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($q));
        flock($h, LOCK_UN);
        fclose($h);
        return $next;
    }

    public static function log(string $name, array $entry): void
    {
        file_put_contents(self::dir() . '/' . $name . '.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array> */
    public static function entries(string $name): array
    {
        $f = self::dir() . '/' . $name . '.log';
        return is_file($f) ? array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($f)))) : [];
    }

    public static function read(string $name): array
    {
        $f = self::dir() . '/' . $name . '.json';
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    public static function write(string $name, array $data): void
    {
        file_put_contents(self::dir() . '/' . $name . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

final class SmScriptedAi extends BaseLlmProvider
{
    public function name(): string { return 'scripted'; }
    public function isConfigured(): bool { return true; }
    public function complete(string $system, array $messages, array $options): array
    {
        SmTestBus::log('ai-calls', ['system' => $system, 'user' => $messages[0]['content'] ?? '']);
        $next = SmTestBus::popAi();
        if ($next === null) {
            return $this->result(false, null, 'unavailable', 'no scripted answer', 'scripted');
        }
        return $this->result(true, $next, 'ok', null, 'scripted-model', 900, 400, 20);
    }
}

final class SmScriptedResearch extends BaseResearchProvider
{
    public function name(): string { return 'scripted-research'; }
    public function isConfigured(): bool { return true; }
    public function search(string $query, array $options = []): array
    {
        SmTestBus::log('searches', ['query' => $query]);
        return $this->result(true, SmTestBus::read('search-results'), 1);
    }
}

/** Install the stand-ins in this PHP process. */
function sm_install_fakes(): void
{
    LlmProviderFactory::setTestProvider(new SmScriptedAi());
    ResearchProviderFactory::setTestProvider(new SmScriptedResearch());
    SmSources::$extractHook = static function (array $urls): array {
        $pages = SmTestBus::read('pages');
        $out = [];
        foreach ($urls as $u) {
            if (isset($pages[$u])) {
                $out[$u] = $pages[$u];
            }
        }
        return $out;
    };
    SmAi::$imageHook = static function (string $prompt, string $size): string {
        SmTestBus::log('images', ['prompt' => $prompt, 'size' => $size]);
        [$w, $h] = array_map('intval', explode('x', $size));
        $im = imagecreatetruecolor(max(64, (int)($w / 8)), max(64, (int)($h / 8)));
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 200));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    };
    SmNet::$hook = static fn(string $m, string $u, array $h, ?string $b): array => sm_fake_net($m, $u, $h, $b);
    Mailer::$testTransport = static function ($profile, $to, $subject, $body) {
        SmTestBus::log('mail', ['from' => $profile['from_email'] ?? '', 'to' => $to, 'subject' => $subject, 'body' => $body]);
        return ['ok' => true];
    };
}

/**
 * Stand-in for Meta, LinkedIn and X (and outside web pages): answers like the
 * real services, records every call. Tests can make an address fail with
 * SmTestBus::write('net-fail', ['part of the address' => status]).
 */
function sm_fake_net(string $method, string $url, array $headers, ?string $body): array
{
    SmTestBus::log('net', ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body !== null && strlen($body) > 4000 ? '[' . strlen($body) . ' bytes]' : $body]);
    foreach (SmTestBus::read('net-fail') as $part => $status) {
        if (str_contains($url, (string)$part)) {
            return ['status' => (int)$status, 'body' => json_encode(['error' => ['message' => 'Simulated failure ' . $status]]), 'headers' => []];
        }
    }
    $j = static fn(array $d, int $st = 200, array $h = []) => ['status' => $st, 'body' => json_encode($d), 'headers' => $h];
    $pages = SmTestBus::read('net-pages');
    if (isset($pages[$url])) {
        return ['status' => 200, 'body' => (string)$pages[$url], 'headers' => ['content-type' => 'text/html']];
    }
    if (str_contains($url, 'logos.example.com')) {
        $im = imagecreatetruecolor(300, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        return ['status' => 200, 'body' => (string)ob_get_clean(), 'headers' => ['content-type' => 'image/png']];
    }
    // Meta
    if (str_contains($url, 'graph.facebook.com')) {
        if (str_contains($url, '/oauth/access_token')) {
            return $j(['access_token' => str_contains($url, 'fb_exchange_token') ? 'fblong' : 'fbshort', 'token_type' => 'bearer']);
        }
        if (str_contains($url, '/me/accounts')) {
            return $j(['data' => [
                ['id' => '111', 'name' => 'Build Asia', 'link' => 'https://www.facebook.com/buildasia', 'access_token' => 'pagetok111', 'instagram_business_account' => ['id' => 'ig222', 'username' => 'buildasia']],
                ['id' => '333', 'name' => 'Another page', 'access_token' => 'pagetok333'],
            ]]);
        }
        if (preg_match('#/111/(photos|feed|videos)$#', $url)) {
            return $j(['id' => '555', 'post_id' => '111_555']);
        }
        if (str_ends_with($url, '/ig222/media')) {
            return $j(['id' => 'container1']);
        }
        if (str_contains($url, '/container1?')) {
            return $j(['status_code' => 'FINISHED', 'id' => 'container1']);
        }
        if (str_ends_with($url, '/ig222/media_publish')) {
            return $j(['id' => 'igmedia1']);
        }
        if (str_contains($url, '/igmedia1?')) {
            return $j(['permalink' => 'https://www.instagram.com/p/abc123/', 'id' => 'igmedia1']);
        }
    }
    // LinkedIn
    if (str_contains($url, 'linkedin.com/oauth/v2/accessToken')) {
        return $j(['access_token' => 'litok', 'expires_in' => 5184000]);
    }
    if (str_contains($url, 'api.linkedin.com/rest/organizationAcls')) {
        return $j(['elements' => [['organization' => 'urn:li:organization:777', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED']]]);
    }
    if (str_contains($url, 'api.linkedin.com/rest/organizations/777')) {
        return $j(['id' => 777, 'localizedName' => 'Build Asia', 'vanityName' => 'buildasia']);
    }
    if (str_contains($url, 'api.linkedin.com/rest/images?action=initializeUpload')) {
        return $j(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/up1', 'image' => 'urn:li:image:img1', 'uploadUrlExpiresAt' => 0]]);
    }
    if (str_contains($url, 'linkedin.com/dms-uploads/')) {
        return ['status' => 201, 'body' => '', 'headers' => []];
    }
    if (str_contains($url, 'api.linkedin.com/rest/posts')) {
        return ['status' => 201, 'body' => '', 'headers' => ['x-restli-id' => 'urn:li:share:999']];
    }
    // X
    if (str_contains($url, 'api.x.com/2/oauth2/token')) {
        return $j(['access_token' => 'xtok', 'refresh_token' => 'xref', 'expires_in' => 7200, 'token_type' => 'bearer']);
    }
    if (str_contains($url, 'api.x.com/2/users/me')) {
        return $j(['data' => ['id' => '55', 'username' => 'BuildAsiaExpo', 'name' => 'Build Asia']]);
    }
    if (str_contains($url, 'api.x.com/2/media/upload')) {
        return $j(['data' => ['id' => 'm1', 'media_key' => '3_m1']]);
    }
    if (str_contains($url, 'api.x.com/2/tweets')) {
        return $j(['data' => ['id' => 't1', 'text' => 'ok']], 201);
    }
    return ['status' => 404, 'body' => '{}', 'headers' => []];
}
