<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * Calls to the AI, through the sales agent's own providers and keys (read-only),
 * counted against each exhibition's monthly limits.
 */
final class SmAi
{
    /** Set only by the test suite. */
    public static ?Closure $imageHook = null;

    /** Text jobs used this month (drafts, versions, checks, topic lists). */
    public static function used(int $agentId, string $kind): int
    {
        return (int)db()->value(
            'SELECT COUNT(*) FROM sm_ai_usage WHERE agent_id = ? AND kind = ? AND ok = 1 AND created_at >= ?',
            [$agentId, $kind, gmdate('Y-m-01 00:00:00')]
        );
    }

    public static function limit(int $agentId, string $kind): int
    {
        $s = SmEvent::settings($agentId);
        return (int)($kind === 'image' ? $s['image_limit'] : $s['text_limit']);
    }

    /** null when allowed, otherwise the reason in plain words. */
    public static function blocked(int $agentId, string $kind): ?string
    {
        $limit = self::limit($agentId, $kind);
        if (self::used($agentId, $kind) >= $limit) {
            return $kind === 'image'
                ? 'This month\'s limit of ' . $limit . ' AI pictures for this exhibition has been reached. An admin can raise it under Event settings.'
                : 'This month\'s limit of ' . $limit . ' AI writing jobs for this exhibition has been reached. An admin can raise it under Event settings.';
        }
        return null;
    }

    /**
     * Ask the AI for JSON. Returns the decoded array, or ['_error' => '…'].
     */
    public static function json(array $agent, string $purpose, string $system, string $user, int $maxTokens = 3000): array
    {
        $aid = (int)$agent['id'];
        if (($why = self::blocked($aid, 'text')) !== null) {
            return ['_error' => $why];
        }
        $settings = SmEvent::settings($aid);
        if (($settings['writing_model'] ?? 'main') === 'background') {
            [$provider, $model] = AiModels::backgroundProvider($agent);
        } else {
            $live = AiModels::live($agent);
            $provider = LlmProviderFactory::make($live['provider']);
            $model = $live['model'];
        }
        if (!$provider->isConfigured()) {
            return ['_error' => 'The AI is not set up yet: its key is missing in the sales agent\'s Settings.'];
        }
        $res = $provider->complete(
            $system . "\n\nAnswer with one JSON object only, no other text.",
            [['role' => 'user', 'content' => $user]],
            ['model' => $model, 'temperature' => 0.4, 'max_tokens' => $maxTokens, 'timeout' => 120]
        );
        db()->insert('sm_ai_usage', [
            'agent_id' => $aid, 'kind' => 'text', 'purpose' => mb_substr($purpose, 0, 48), 'model' => mb_substr((string)($res['model'] ?? ''), 0, 96),
            'input_tokens' => (int)($res['input_tokens'] ?? 0), 'output_tokens' => (int)($res['output_tokens'] ?? 0), 'ok' => $res['ok'] ? 1 : 0,
        ]);
        if (!$res['ok']) {
            return ['_error' => 'The AI did not answer (' . ($res['status'] ?? 'error') . '). Try again in a minute.'];
        }
        $data = self::decode((string)$res['text']);
        return $data ?? ['_error' => 'The AI\'s answer could not be read. Try again.'];
    }

    public static function decode(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $data = json_decode(substr($text, $a, $b - $a + 1), true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /** Is AI picture-making possible at all on this server? */
    public static function imagesReady(): bool
    {
        return self::$imageHook !== null || trim((string)Settings::get('llm.openai.api_key', '')) !== '';
    }

    /**
     * Make one illustration with OpenAI's image model. Returns raw PNG/JPEG
     * bytes, or ['_error' => …]. Size: square, landscape or portrait.
     */
    public static function image(array $agent, string $prompt, string $shape = 'square'): string|array
    {
        $aid = (int)$agent['id'];
        if (!SmEvent::settings($aid)['ai_images']) {
            return ['_error' => 'AI pictures are switched off for this exhibition.'];
        }
        if (($why = self::blocked($aid, 'image')) !== null) {
            return ['_error' => $why];
        }
        $size = ['square' => '1024x1024', 'landscape' => '1536x1024', 'portrait' => '1024x1536'][$shape] ?? '1024x1024';
        $safe = $prompt . "\n\nGeneral illustration only. No text, no logos, no brand names, no identifiable real people, "
              . 'no specific real products or machinery models.';
        if (self::$imageHook !== null) {
            $bytes = (self::$imageHook)($safe, $size);
        } else {
            $key = trim((string)Settings::get('llm.openai.api_key', ''));
            if ($key === '') {
                return ['_error' => 'AI pictures need an OpenAI key in the sales agent\'s Settings.'];
            }
            $res = Http::request('POST', 'https://api.openai.com/v1/images/generations', [
                'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json',
            ], json_encode(['model' => 'gpt-image-1', 'prompt' => mb_substr($safe, 0, 3800), 'size' => $size, 'n' => 1]), 120);
            $data = Http::decode((string)($res['body'] ?? ''));
            $b64 = (string)($data['data'][0]['b64_json'] ?? '');
            $bytes = $b64 !== '' ? (base64_decode($b64, true) ?: '') : '';
            if ($bytes === '') {
                app_log('social', 'image generation failed', ['status' => $res['status'] ?? 0]);
            }
        }
        db()->insert('sm_ai_usage', ['agent_id' => $aid, 'kind' => 'image', 'purpose' => 'picture', 'model' => 'gpt-image-1', 'ok' => $bytes !== '' ? 1 : 0]);
        return $bytes !== '' ? $bytes : ['_error' => 'The picture could not be made. Try again, or upload one.'];
    }
}
