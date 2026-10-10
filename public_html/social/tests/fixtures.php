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
    Mailer::$testTransport = static function ($profile, $to, $subject, $body) {
        SmTestBus::log('mail', ['from' => $profile['from_email'] ?? '', 'to' => $to, 'subject' => $subject, 'body' => $body]);
        return ['ok' => true];
    };
}
