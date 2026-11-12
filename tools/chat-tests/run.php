<?php
declare(strict_types=1);

/**
 * Run the 15 written test chats (chat fix plan, step 6) with the REAL AI model.
 *
 *   php tools/chat-tests/run.php [--model=gpt-6-luna] [--provider=openai] [--only=4] [--agent=slug] [--pause=65]
 *
 * Runs on a TEST copy only: it refuses an installation whose config says
 * 'production'. Without --agent it builds its own exhibition, "Pharma Asia
 * (chat test)", with Pharma Asia's 2027 offer (stands, outdoor from 80 sqm, four
 * tiers, both representatives, the market note, sectors and tier guidance), and
 * removes it afterwards. With --agent it uses an exhibition already on this copy.
 *
 * Keys: the AI key and the Tavily key saved under Settings on this copy, or the
 * environment variables OPENAI_API_KEY and TAVILY_API_KEY. Emails are only
 * queued, never sent (no scheduled task runs here).
 */

require_once __DIR__ . '/../../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}
if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to run on a production installation. Use a test copy.\n");
    exit(1);
}

$opt      = getopt('', ['model::', 'provider::', 'only::', 'agent::', 'keep', 'pause::']);
// Seconds to wait between visitor messages: for an AI account with a small
// tokens-per-minute limit (a new OpenAI account allows 10,000 a minute, about
// one chat reply), e.g. --pause=65.
$pause    = max(0, (int)($opt['pause'] ?? 0));
$provider = (string)($opt['provider'] ?? 'openai');
$model    = (string)($opt['model'] ?? 'gpt-6-luna');
$only     = isset($opt['only']) ? (int)$opt['only'] : null;

if (($k = getenv('OPENAI_API_KEY')) !== false && $k !== '') {
    Settings::set('llm.openai.api_key', $k);
}
if (($k = getenv('TAVILY_API_KEY')) !== false && $k !== '') {
    Settings::set('research.tavily.api_key', $k);
    Settings::set('research.provider', 'tavily');
}
if (!LlmProviderFactory::make($provider)->isConfigured()) {
    fwrite(STDERR, "No key for the AI provider '{$provider}'. Save it under Settings or set OPENAI_API_KEY.\n");
    exit(1);
}

// ---- The exhibition ---------------------------------------------------------
$built = false;
if (!empty($opt['agent'])) {
    $agent = db()->one("SELECT * FROM agents WHERE slug = ? AND status <> 'deleted'", [(string)$opt['agent']]);
    if ($agent === null) {
        fwrite(STDERR, "No exhibition with the slug '{$opt['agent']}'.\n");
        exit(1);
    }
    db()->update('agents', ['llm_provider' => $provider, 'llm_model' => $model], 'id = ?', [(int)$agent['id']]);
} else {
    $agent = require __DIR__ . '/fixture.php';
    $built = true;
}
$agent = db()->one('SELECT * FROM agents WHERE id = ?', [(int)$agent['id']]);

// ---- The chats --------------------------------------------------------------
$chats   = require __DIR__ . '/chats.php';
$out     = [];
$passed  = 0;
$failed  = 0;
$say     = static function (string $line) use (&$out): void { $out[] = $line; echo $line, "\n"; };
$textOf  = static function (array $response): string {
    $lines = [];
    foreach ((array)($response['blocks'] ?? []) as $b) {
        if (in_array($b['type'] ?? '', ['ul', 'ol'], true)) {
            foreach ((array)$b['items'] as $item) {
                $lines[] = '- ' . implode('', array_map(static fn($s) => (string)($s['v'] ?? '') . (($s['t'] ?? '') === 'a' && ($s['href'] ?? '') !== ($s['v'] ?? '') ? ' (' . $s['href'] . ')' : ''), (array)$item));
            }
        } else {
            $lines[] = implode('', array_map(static fn($s) => (string)($s['v'] ?? '') . (($s['t'] ?? '') === 'a' && ($s['href'] ?? '') !== ($s['v'] ?? '') ? ' (' . $s['href'] . ')' : ''), (array)($b['spans'] ?? [])));
        }
    }
    foreach ((array)($response['cards'] ?? []) as $c) {
        $lines[] = '[card: ' . (string)($c['title'] ?? '') . ' ' . (string)($c['price_text'] ?? '') . ']';
    }
    return trim(implode("\n", $lines));
};
$match = static function (string $pattern, string $text): bool {
    $end  = strrpos($pattern, '/');
    $mods = substr($pattern, $end + 1);
    return (bool)preg_match(substr($pattern, 0, $end + 1) . (str_contains($mods, 'i') ? $mods : $mods . 'i'), $text);
};

$say('Test chats — ' . gmdate('Y-m-d H:i') . ' UTC — ' . $agent['name'] . ' — ' . $provider . ' / ' . $model);
foreach ($chats as $i => $chat) {
    if ($only !== null && $only !== $i + 1) {
        continue;
    }
    $say('');
    $say('=== ' . $chat['name']);
    $started = ConversationStore::start($agent, ['visitor_id' => 'chattest' . bin2hex(random_bytes(8)),
        'origin' => 'pharmaasia.com.pk', 'page_url' => 'https://pharmaasia.com.pk/']);
    $conv   = $started['conversation'];
    $secret = $started['session_secret'];

    $turns = $chat['turns'];
    $response = null;
    if (!empty($chat['intro'])) {
        $t0 = microtime(true);
        $response = ChatIntro::submit($agent, $chat['intro'] + [
            'request_id' => 'r' . bin2hex(random_bytes(10)), 'session_id' => (string)$conv['session_id'],
            'session_secret' => $secret, 'origin' => 'pharmaasia.com.pk',
        ]);
        $say('  (introduction card: ' . json_encode($chat['intro']) . ', answered in ' . round(microtime(true) - $t0, 1) . ' s)');
    }

    foreach ($turns as $turn) {
        if ($turn['say'] !== null) {
            if ($pause > 0) {
                sleep($pause);
            }
            $say('  VISITOR: ' . $turn['say']);
            $response = ChatOrchestrator::handle($agent, [
                'request_id' => 'r' . bin2hex(random_bytes(10)), 'session_id' => (string)$conv['session_id'],
                'session_secret' => $secret, 'message' => (string)$turn['say'], 'origin' => 'pharmaasia.com.pk',
            ]);
        }
        $reply = $textOf((array)$response);
        $say('  ASSISTANT: ' . str_replace("\n", "\n             ", $reply));

        $problems = [];
        foreach (EVERY_REPLY_MUST_NOT as $label => $p) {
            if ($match($p, $reply)) {
                $problems[] = $label;
            }
        }
        foreach ((array)($turn['must'] ?? []) as $label => $p) {
            if (!$match($p, $reply)) {
                $problems[] = 'missing: ' . $label;
            }
        }
        foreach ((array)($turn['must_not'] ?? []) as $label => $p) {
            if ($match($p, $reply)) {
                $problems[] = $label;
            }
        }
        if (!empty($response['fallback'])) {
            $problems[] = 'a standard reply was used (' . $response['fallback'] . ')';
        }
        // What the live server does straight after a reply: finish research
        // that did not finish before it (fastcgi_finish_request has no
        // equivalent on the command line).
        foreach (db()->all("SELECT id FROM company_research WHERE conversation_id = ? AND status = 'queued'", [(int)$conv['id']]) as $q) {
            CompanyResearch::run((int)$q['id']);
        }
        if ($problems) {
            $failed++;
            $say('  FAIL: ' . implode('; ', $problems));
        } else {
            $passed++;
            $say('  PASS');
        }
    }

    $research = db()->one('SELECT status, match_level, error FROM company_research WHERE conversation_id = ? ORDER BY id DESC LIMIT 1',
        [(int)$conv['id']]);
    if ($research !== null) {
        $say('  (research: ' . $research['status'] . ($research['match_level'] ? ', ' . $research['match_level'] : '')
            . ($research['error'] ? ', ' . $research['error'] : '') . ')');
    }
}

$say('');
$say('Replies passed: ' . $passed . '   failed: ' . $failed);

$file = rtrim((string)cfg('storage_path', APP_ROOT . '/storage'), '/') . '/chat-tests-' . gmdate('Ymd-His') . '.txt';
@file_put_contents($file, implode("\n", $out) . "\n");
echo "Saved to {$file}\n";

if ($built && !isset($opt['keep'])) {
    $id = (int)$agent['id'];
    foreach (['messages', 'company_research', 'leads', 'conversations', 'stall_packages', 'sponsorship_packages',
              'knowledge_articles', 'faq_entries', 'agent_sales_reps', 'fallback_messages'] as $table) {
        try {
            db()->exec("DELETE FROM {$table} WHERE agent_id = ?", [$id]);
        } catch (Throwable) {
            // A table this copy does not have.
        }
    }
    db()->exec("UPDATE agents SET status = 'deleted' WHERE id = ?", [$id]);
}
exit($failed > 0 ? 1 : 0);
