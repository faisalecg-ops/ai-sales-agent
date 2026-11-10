<?php
declare(strict_types=1);

/**
 * Stable LLM provider interface (Section 45). The rest of the application
 * never depends on which provider is configured.
 */
interface LlmProvider
{
    public function name(): string;

    /**
     * @param string $system   assembled system prompt
     * @param array  $messages [['role'=>'user'|'assistant','content'=>string], ...]
     * @param array  $options  model, temperature, max_tokens, timeout
     *
     * @return array{
     *   ok: bool,
     *   text: ?string,
     *   input_tokens: int,
     *   output_tokens: int,
     *   latency_ms: int,
     *   status: string,            // ok|timeout|rate_limited|auth_error|bad_response|unavailable
     *   error: ?string,
     *   model: string
     * }
     */
    public function complete(string $system, array $messages, array $options): array;

    /** Is the provider ready to be called? */
    public function isConfigured(): bool;
}

abstract class BaseLlmProvider implements LlmProvider
{
    protected function result(
        bool $ok,
        ?string $text,
        string $status,
        ?string $error,
        string $model,
        int $in = 0,
        int $out = 0,
        int $latency = 0
    ): array {
        return [
            'ok'            => $ok,
            'text'          => $text,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'latency_ms'    => $latency,
            'status'        => $status,
            'error'         => $error !== null ? mb_substr($error, 0, 400) : null,
            'model'         => $model,
        ];
    }

    /** Map an HTTP failure onto a stable status string. */
    protected function statusFromHttp(array $res): string
    {
        $error = strtolower((string)($res['error'] ?? ''));

        if (str_contains($error, 'timed out') || str_contains($error, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($error, 'insecure_url_refused')) {
            return 'auth_error';   // a configuration problem, not an outage
        }
        if (str_contains($error, 'could not resolve') || str_contains($error, 'couldn\'t connect')
            || str_contains($error, 'connection refused')) {
            return 'unavailable';
        }

        // "Too many requests" can also mean the account has run out of credit,
        // which waiting will not fix: that is treated as the service being
        // unavailable, not busy.
        $body = strtolower((string)($res['body'] ?? ''));
        if ($res['status'] === 429 && preg_match('/quota|credit|billing|insufficient|exceeded your current/', $body)) {
            return 'unavailable';
        }

        return match (true) {
            $res['status'] === 429               => 'rate_limited',
            // Overloaded (Anthropic 529) or temporarily unavailable (503): busy
            // for a moment, worth waiting for.
            in_array($res['status'], [503, 529], true) => 'overloaded',
            in_array($res['status'], [401, 403], true) => 'auth_error',
            $res['status'] === 404               => 'bad_response',
            $res['status'] === 0                 => 'unavailable',
            $res['status'] >= 500                => 'unavailable',
            default                              => 'bad_response',
        };
    }

    /** Pull a human-readable message out of an error body, if there is one. */
    protected function messageFromBody(string $body): ?string
    {
        $data = Http::decode($body);
        if (!is_array($data)) {
            return null;
        }
        foreach ([
            ['error', 'message'],
            ['error', 'msg'],
            ['message'],
            ['detail'],
            ['error'],
        ] as $path) {
            $node = $data;
            foreach ($path as $step) {
                if (!is_array($node) || !array_key_exists($step, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$step];
            }
            if (is_string($node) && trim($node) !== '') {
                return $node;
            }
        }
        return null;
    }
}

/**
 * What the platform can talk to, and what each one needs.
 *
 * Kept as data rather than scattered through the code, because adding a
 * provider should be a matter of describing it. Most services now speak the
 * same request shape as OpenAI, so only genuinely different APIs — Anthropic
 * and Google — need code of their own.
 */
final class LlmCatalogue
{
    /**
     * family: which request shape to use — openai | anthropic | gemini
     * self_hosted: runs on your own machine, so no key and plain HTTP allowed
     */
    public const PROVIDERS = [
        'anthropic' => [
            'label'        => 'Claude (Anthropic)',
            'family'       => 'anthropic',
            'key_setting'  => 'llm.anthropic.api_key',
            'key_required' => true,
            'key_hint'     => 'Starts with sk-ant-. From console.anthropic.com.',
            'console'      => 'https://console.anthropic.com/settings/keys',
            'endpoint'     => 'https://api.anthropic.com/v1/messages',
            'default_model' => 'claude-sonnet-4-5',
            'models'       => ['claude-sonnet-4-5', 'claude-opus-4-1', 'claude-haiku-4-5'],
            'notes'        => 'Strong at following the safety rules this platform depends on. A good default.',
        ],
        'openai' => [
            'label'        => 'ChatGPT (OpenAI)',
            'family'       => 'openai',
            'key_setting'  => 'llm.openai.api_key',
            'key_required' => true,
            'key_hint'     => 'Starts with sk-. From platform.openai.com.',
            'console'      => 'https://platform.openai.com/api-keys',
            'endpoint'     => 'https://api.openai.com/v1/chat/completions',
            'json_mode'    => true,
            'default_model' => 'gpt-4o',
            'models'       => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-6-luna', 'gpt-6.1-sol'],
            'notes'        => 'Widely available. Billed per token on your OpenAI account.',
        ],
        'gemini' => [
            'label'        => 'Gemini (Google)',
            'family'       => 'gemini',
            'key_setting'  => 'llm.gemini.api_key',
            'key_required' => true,
            'key_hint'     => 'From Google AI Studio.',
            'console'      => 'https://aistudio.google.com/app/apikey',
            'endpoint'     => 'https://generativelanguage.googleapis.com/v1beta',
            'base_url_setting' => 'llm.gemini.base_url',
            'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'base_url_optional' => true,
            'default_model' => 'gemini-2.5-flash',
            'models'       => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash'],
            'notes'        => 'Has a free tier, which suits testing. Check the rate limits on it.',
        ],
        'grok' => [
            'label'        => 'Grok (xAI)',
            'family'       => 'openai',
            'key_setting'  => 'llm.grok.api_key',
            'key_required' => true,
            'key_hint'     => 'Starts with xai-. From console.x.ai.',
            'console'      => 'https://console.x.ai',
            'endpoint'     => 'https://api.x.ai/v1/chat/completions',
            'json_mode'    => true,
            'default_model' => 'grok-4',
            'models'       => ['grok-4', 'grok-3', 'grok-3-mini'],
            'notes'        => 'Uses the same request format as OpenAI.',
        ],
        'deepseek' => [
            'label'        => 'DeepSeek',
            'family'       => 'openai',
            'key_setting'  => 'llm.deepseek.api_key',
            'key_required' => true,
            'key_hint'     => 'From platform.deepseek.com.',
            'console'      => 'https://platform.deepseek.com/api_keys',
            'endpoint'     => 'https://api.deepseek.com/v1/chat/completions',
            'json_mode'    => true,
            'default_model' => 'deepseek-chat',
            'models'       => ['deepseek-chat', 'deepseek-reasoner'],
            'notes'        => 'Inexpensive. Note that your conversations leave your own region.',
        ],
        'mistral' => [
            'label'        => 'Mistral',
            'family'       => 'openai',
            'key_setting'  => 'llm.mistral.api_key',
            'key_required' => true,
            'key_hint'     => 'From console.mistral.ai.',
            'console'      => 'https://console.mistral.ai/api-keys',
            'endpoint'     => 'https://api.mistral.ai/v1/chat/completions',
            'json_mode'    => true,
            'default_model' => 'mistral-large-latest',
            'models'       => ['mistral-large-latest', 'mistral-small-latest'],
            'notes'        => 'Hosted in the EU, which can matter for data residency.',
        ],
        'openrouter' => [
            'label'        => 'OpenRouter (many models, one key)',
            'family'       => 'openai',
            'key_setting'  => 'llm.openrouter.api_key',
            'key_required' => true,
            'key_hint'     => 'Starts with sk-or-. From openrouter.ai.',
            'console'      => 'https://openrouter.ai/keys',
            'endpoint'     => 'https://openrouter.ai/api/v1/chat/completions',
            'json_mode'    => true,
            'default_model' => 'anthropic/claude-sonnet-4.5',
            'models'       => [
                'anthropic/claude-sonnet-4.5',
                'openai/gpt-4o',
                'google/gemini-2.5-flash',
                'meta-llama/llama-3.3-70b-instruct',
            ],
            'notes'        => 'One account that reaches most models. Handy for trying several without separate keys.',
        ],
        'ollama' => [
            'label'        => 'Ollama (a model on your own server)',
            'family'       => 'openai',
            'key_setting'  => 'llm.ollama.api_key',
            'key_required' => false,
            'key_hint'     => 'Usually left blank. Only needed if you put Ollama behind a proxy that asks for one.',
            'base_url_setting' => 'llm.ollama.base_url',
            'default_base_url' => 'http://localhost:11434/v1',
            'self_hosted'  => true,
            'endpoint'     => null,          // built from the base URL
            'json_mode'    => false,         // not supported by every build
            'default_model' => 'llama3.1',
            'models'       => ['llama3.1', 'llama3.3', 'qwen2.5', 'mistral', 'gemma2'],
            'notes'        => 'Nothing leaves your own machine, and there is no per-token cost. '
                            . 'It will not work on ordinary shared hosting — Ollama has to be running '
                            . 'somewhere this server can reach.',
        ],
        'custom' => [
            'label'        => 'Something else that speaks the OpenAI format',
            'family'       => 'openai',
            'key_setting'  => 'llm.custom.api_key',
            'key_required' => false,
            'key_hint'     => 'Whatever key your service expects, if any.',
            'base_url_setting' => 'llm.custom.base_url',
            'default_base_url' => '',
            'endpoint'     => null,
            'json_mode'    => false,
            'default_model' => '',
            'models'       => [],
            'notes'        => 'For Groq, Together, Azure OpenAI, LM Studio, vLLM or anything else with an '
                            . 'OpenAI-compatible endpoint. Give the base URL ending in /v1.',
        ],
    ];

    public static function all(): array
    {
        return self::PROVIDERS;
    }

    public static function has(string $name): bool
    {
        return isset(self::PROVIDERS[$name]);
    }

    public static function meta(string $name): array
    {
        return self::PROVIDERS[$name] ?? [];
    }

    public static function label(string $name): string
    {
        return (string)(self::PROVIDERS[$name]['label'] ?? $name);
    }

    /** Names for a dropdown. */
    public static function options(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $out[$key] = (string)$meta['label'];
        }
        return $out;
    }

    /**
     * Providers that are actually usable right now.
     *
     * @return array<string,string> key => label
     */
    public static function configuredOptions(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            if (self::isConfigured($key)) {
                $out[$key] = (string)$meta['label'];
            }
        }
        return $out;
    }

    /** The first usable provider, preferring one the caller asks for. */
    public static function firstConfigured(?string $preferred = null): ?string
    {
        if ($preferred !== null && self::has($preferred) && self::isConfigured($preferred)) {
            return $preferred;
        }
        foreach (array_keys(self::PROVIDERS) as $key) {
            if (self::isConfigured($key)) {
                return $key;
            }
        }
        return null;
    }

    /** Every setting key that holds a credential, for the secrets list. */
    public static function secretKeys(): array
    {
        $keys = [];
        foreach (self::PROVIDERS as $meta) {
            if (!empty($meta['key_setting'])) {
                $keys[] = (string)$meta['key_setting'];
            }
        }
        return $keys;
    }

    public static function defaultModel(string $name): string
    {
        return (string)(self::PROVIDERS[$name]['default_model'] ?? '');
    }

    public static function models(string $name): array
    {
        return (array)(self::PROVIDERS[$name]['models'] ?? []);
    }

    /**
     * The base URL in use, which for a self-hosted service is whatever the
     * administrator configured.
     */
    public static function baseUrl(string $name): string
    {
        $meta = self::meta($name);
        if (empty($meta['base_url_setting'])) {
            return (string)($meta['endpoint'] ?? '');
        }
        $configured = trim((string)Settings::get((string)$meta['base_url_setting'], ''));
        if ($configured === '') {
            $configured = (string)($meta['default_base_url'] ?? '');
        }
        return rtrim($configured, '/');
    }

    /** Is this provider ready to be used? */
    public static function isConfigured(string $name): bool
    {
        $meta = self::meta($name);
        if ($meta === []) {
            return false;
        }

        if (!empty($meta['key_required'])) {
            if (trim((string)Settings::get((string)$meta['key_setting'], '')) === '') {
                return false;
            }
        }

        // A service reached at an address of its own counts as set up only once
        // the administrator has actually entered that address. Falling back to
        // a built-in default here would advertise, say, a local Ollama as ready
        // when nothing is running on that port.
        if (!empty($meta['base_url_setting']) && empty($meta['base_url_optional'])) {
            if (trim((string)Settings::get((string)$meta['base_url_setting'], '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** Why a provider is not usable, in words an administrator can act on. */
    public static function configurationProblem(string $name): ?string
    {
        $meta = self::meta($name);
        if ($meta === []) {
            return 'That AI provider is not one this platform knows about.';
        }

        if (!empty($meta['key_required'])
            && trim((string)Settings::get((string)$meta['key_setting'], '')) === '') {
            return 'No API key is saved for ' . (string)$meta['label'] . '.';
        }

        if (!empty($meta['base_url_setting'])
            && empty($meta['base_url_optional'])
            && trim((string)Settings::get((string)$meta['base_url_setting'], '')) === '') {
            return (string)$meta['label'] . ' needs the address it is running at'
                 . (!empty($meta['default_base_url'])
                    ? ', usually ' . (string)$meta['default_base_url'] . '.'
                    : '.');
        }

        return null;
    }
}

// =====================================================================
// Anthropic — its own request shape
// =====================================================================
final class AnthropicLlmProvider extends BaseLlmProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return LlmCatalogue::isConfigured('anthropic');
    }

    public function complete(string $system, array $messages, array $options): array
    {
        $model  = (string)($options['model'] ?? LlmCatalogue::defaultModel('anthropic'));
        $apiKey = (string)Settings::get('llm.anthropic.api_key', '');
        if ($apiKey === '') {
            return $this->result(false, null, 'auth_error', 'api_key_not_configured', $model);
        }

        $payload = [
            'model'       => $model,
            'max_tokens'  => (int)($options['max_tokens'] ?? 700),
            'temperature' => (float)($options['temperature'] ?? 0.3),
            'system'      => $system,
            'messages'    => array_map(
                static fn($m) => [
                    'role'    => $m['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => (string)$m['content'],
                ],
                $messages
            ),
        ];

        $res = Http::postJson(
            (string)LlmCatalogue::meta('anthropic')['endpoint'],
            $payload,
            ['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'],
            (int)($options['timeout'] ?? 25)
        );

        if (!$res['ok']) {
            return $this->result(
                false, null, $this->statusFromHttp($res),
                $this->messageFromBody($res['body']) ?? $res['error'],
                $model, 0, 0, $res['latency_ms']
            );
        }

        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->result(false, null, 'bad_response', 'invalid_json', $model, 0, 0, $res['latency_ms']);
        }

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }
        if (trim($text) === '') {
            return $this->result(false, null, 'bad_response', 'empty_content', $model, 0, 0, $res['latency_ms']);
        }

        return $this->result(
            true, $text, 'ok', null,
            (string)($data['model'] ?? $model),
            (int)($data['usage']['input_tokens'] ?? 0),
            (int)($data['usage']['output_tokens'] ?? 0),
            $res['latency_ms']
        );
    }
}

// =====================================================================
// Google Gemini — its own request shape
// =====================================================================
final class GeminiLlmProvider extends BaseLlmProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return LlmCatalogue::isConfigured('gemini');
    }

    public function complete(string $system, array $messages, array $options): array
    {
        $model  = (string)($options['model'] ?? LlmCatalogue::defaultModel('gemini'));
        $apiKey = (string)Settings::get('llm.gemini.api_key', '');
        if ($apiKey === '') {
            return $this->result(false, null, 'auth_error', 'api_key_not_configured', $model);
        }

        // Gemini calls the assistant "model" rather than "assistant".
        $contents = [];
        foreach ($messages as $message) {
            $contents[] = [
                'role'  => ($message['role'] === 'assistant') ? 'model' : 'user',
                'parts' => [['text' => (string)$message['content']]],
            ];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature'      => (float)($options['temperature'] ?? 0.3),
                'maxOutputTokens'  => (int)($options['max_tokens'] ?? 700),
                // The platform always wants JSON back, and Gemini can be told so.
                'responseMimeType' => 'application/json',
            ],
        ];

        $base = LlmCatalogue::baseUrl('gemini');
        if ($base === '') {
            $base = (string)LlmCatalogue::meta('gemini')['endpoint'];
        }
        $url = rtrim($base, '/') . '/models/' . rawurlencode($model) . ':generateContent';

        $res = Http::postJson(
            $url,
            $payload,
            ['x-goog-api-key' => $apiKey],
            (int)($options['timeout'] ?? 25),
            true   // allows a locally proxied endpoint; public hosts still require HTTPS
        );

        if (!$res['ok']) {
            return $this->result(
                false, null, $this->statusFromHttp($res),
                $this->messageFromBody($res['body']) ?? $res['error'],
                $model, 0, 0, $res['latency_ms']
            );
        }

        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->result(false, null, 'bad_response', 'invalid_json', $model, 0, 0, $res['latency_ms']);
        }

        // A safety block returns no candidate, which is a refusal rather than
        // a fault, but the platform still has nothing to show.
        $candidate = $data['candidates'][0] ?? null;
        if ($candidate === null) {
            $reason = (string)($data['promptFeedback']['blockReason'] ?? 'no_candidate');
            return $this->result(false, null, 'bad_response', 'blocked: ' . $reason, $model, 0, 0, $res['latency_ms']);
        }

        $text = '';
        foreach (($candidate['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        if (trim($text) === '') {
            return $this->result(false, null, 'bad_response', 'empty_content', $model, 0, 0, $res['latency_ms']);
        }

        return $this->result(
            true, $text, 'ok', null,
            (string)($data['modelVersion'] ?? $model),
            (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
            (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            $res['latency_ms']
        );
    }
}

// =====================================================================
// Everything that speaks the OpenAI chat-completions format
// =====================================================================
class OpenAiCompatibleProvider extends BaseLlmProvider
{
    public function __construct(private readonly string $providerKey)
    {
    }

    public function name(): string
    {
        return $this->providerKey;
    }

    public function isConfigured(): bool
    {
        return LlmCatalogue::isConfigured($this->providerKey);
    }

    /**
     * How much thinking an OpenAI GPT-6 model does before it answers, or null
     * for every other model.
     *
     * The GPT-6 family reasons before replying and takes different request
     * settings from earlier models (OpenAI's "Using GPT-6" guide, read 10
     * November 2026). GPT-6 Luna and GPT-6 Sol can answer without reasoning,
     * which suits a live chat where someone is waiting. GPT-6.1 Sol, GPT-6.1
     * Terra and GPT-6 Astra cannot go below "low".
     */
    public static function gpt6Effort(string $providerKey, string $model): ?string
    {
        $model = strtolower(trim($model));
        if ($providerKey !== 'openai' || !str_starts_with($model, 'gpt-6')) {
            return null;
        }
        return in_array($model, ['gpt-6-luna', 'gpt-6-sol'], true) ? 'none' : 'low';
    }

    /**
     * The request sent to the service. Kept apart from the sending so the
     * tests can check it without calling anyone.
     */
    public static function requestBody(string $providerKey, string $model, array $chat, array $options, array $meta): array
    {
        $temperature = (float)($options['temperature'] ?? 0.3);
        $maxTokens   = (int)($options['max_tokens'] ?? 700);
        $effort      = self::gpt6Effort($providerKey, $model);

        if ($effort === null) {
            $payload = [
                'model'       => $model,
                'messages'    => $chat,
                'temperature' => $temperature,
                'max_tokens'  => $maxTokens,
            ];
        } else {
            // GPT-6: the length limit has a different name, and it also has
            // to cover the model's own reasoning, so room is added for that
            // when it reasons. Temperature is only accepted with no reasoning.
            $payload = [
                'model'                 => $model,
                'messages'              => $chat,
                'reasoning_effort'      => $effort,
                'max_completion_tokens' => $effort === 'none' ? $maxTokens : $maxTokens + 2000,
            ];
            if ($effort === 'none') {
                $payload['temperature'] = $temperature;
            }
        }

        // Only asked for where the service is known to support it; the reply
        // parser copes with plain text containing JSON either way.
        if (!empty($meta['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        return $payload;
    }

    public function complete(string $system, array $messages, array $options): array
    {
        $meta  = LlmCatalogue::meta($this->providerKey);
        $model = (string)($options['model'] ?? LlmCatalogue::defaultModel($this->providerKey));

        if ($model === '') {
            return $this->result(false, null, 'auth_error', 'no_model_configured', '');
        }

        $endpoint = $this->endpoint($meta);
        if ($endpoint === null) {
            return $this->result(false, null, 'auth_error', 'no_endpoint_configured', $model);
        }

        $apiKey = trim((string)Settings::get((string)($meta['key_setting'] ?? ''), ''));
        if ($apiKey === '' && !empty($meta['key_required'])) {
            return $this->result(false, null, 'auth_error', 'api_key_not_configured', $model);
        }

        $chat = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $message) {
            $chat[] = [
                'role'    => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string)$message['content'],
            ];
        }

        $payload = self::requestBody($this->providerKey, $model, $chat, $options, $meta);

        $headers = [];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($this->providerKey === 'openrouter') {
            // OpenRouter asks callers to identify themselves.
            $headers['HTTP-Referer'] = BASE_URL;
            $headers['X-Title']      = 'AI Sales Agent Platform';
        }

        $res = Http::postJson(
            $endpoint,
            $payload,
            $headers,
            (int)($options['timeout'] ?? 25),
            !empty($meta['self_hosted']) || $this->providerKey === 'custom'
        );

        if (!$res['ok']) {
            $status = $this->statusFromHttp($res);
            $detail = $this->messageFromBody($res['body']) ?? $res['error'];

            // A self-hosted model that cannot be reached is worth saying
            // plainly, because the cause is nearly always that it is not
            // running or not reachable from this server.
            if (!empty($meta['self_hosted']) && $status === 'unavailable') {
                $detail = 'Could not reach ' . $endpoint . '. Check the model is running and that this '
                        . 'server can reach that address. ' . (string)$detail;
            }

            return $this->result(false, null, $status, $detail, $model, 0, 0, $res['latency_ms']);
        }

        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->result(false, null, 'bad_response', 'invalid_json', $model, 0, 0, $res['latency_ms']);
        }

        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            return $this->result(false, null, 'bad_response', 'empty_content', $model, 0, 0, $res['latency_ms']);
        }

        return $this->result(
            true, $text, 'ok', null,
            (string)($data['model'] ?? $model),
            (int)($data['usage']['prompt_tokens'] ?? 0),
            (int)($data['usage']['completion_tokens'] ?? 0),
            $res['latency_ms']
        );
    }

    /** The full chat-completions URL for this provider. */
    private function endpoint(array $meta): ?string
    {
        if (!empty($meta['endpoint'])) {
            return (string)$meta['endpoint'];
        }

        $base = LlmCatalogue::baseUrl($this->providerKey);
        if ($base === '') {
            return null;
        }
        // Accept a base URL given either as ".../v1" or as the full path.
        if (str_contains($base, '/chat/completions')) {
            return $base;
        }
        return $base . '/chat/completions';
    }
}

/** Kept as its own class so existing configuration keeps working. */
final class OpenAiLlmProvider extends OpenAiCompatibleProvider
{
    public function __construct()
    {
        parent::__construct('openai');
    }
}

/** Used in tests and when nothing is configured. */
final class NullLlmProvider extends BaseLlmProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(string $system, array $messages, array $options): array
    {
        return $this->result(false, null, 'unavailable', 'no_provider_configured', 'none');
    }
}

final class LlmProviderFactory
{
    /**
     * Set only by the automated test suite, so acceptance tests can run
     * without calling a live provider. Never set in normal operation.
     */
    private static ?LlmProvider $testProvider = null;

    public static function setTestProvider(?LlmProvider $provider): void
    {
        self::$testProvider = $provider;
    }

    public static function make(string $name): LlmProvider
    {
        if (self::$testProvider !== null) {
            return self::$testProvider;
        }

        if (!LlmCatalogue::has($name)) {
            return new NullLlmProvider();
        }

        return match ((string)LlmCatalogue::meta($name)['family']) {
            'anthropic' => new AnthropicLlmProvider(),
            'gemini'    => new GeminiLlmProvider(),
            'openai'    => new OpenAiCompatibleProvider($name),
            default     => new NullLlmProvider(),
        };
    }

    public static function available(): array
    {
        return LlmCatalogue::options();
    }

    /**
     * Send the smallest possible request, to prove a provider actually works
     * before an agent is left depending on it.
     *
     * @return array{ok:bool,message:string,latency_ms:int,model:string}
     */
    public static function test(string $name, ?string $model = null): array
    {
        $problem = LlmCatalogue::configurationProblem($name);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem, 'latency_ms' => 0, 'model' => (string)$model];
        }

        $model    = ($model !== null && trim($model) !== '') ? trim($model) : LlmCatalogue::defaultModel($name);
        $provider = self::make($name);

        $result = $provider->complete(
            'You are a test. Reply with exactly this JSON and nothing else: {"ok":true}',
            [['role' => 'user', 'content' => 'Reply with {"ok":true}']],
            ['model' => $model, 'temperature' => 0.0, 'max_tokens' => 64, 'timeout' => 20]
        );

        if ($result['ok']) {
            return [
                'ok'         => true,
                'message'    => 'Working. ' . LlmCatalogue::label($name) . ' replied using '
                              . (string)$result['model'] . '.',
                'latency_ms' => (int)$result['latency_ms'],
                'model'      => (string)$result['model'],
            ];
        }

        return [
            'ok'         => false,
            'message'    => self::explainFailure($name, (string)$result['status'], $result['error']),
            'latency_ms' => (int)$result['latency_ms'],
            'model'      => $model,
        ];
    }

    /** Turn a failure status into something an administrator can act on. */
    private static function explainFailure(string $name, string $status, ?string $error): string
    {
        $label  = LlmCatalogue::label($name);
        $detail = ($error !== null && trim($error) !== '') ? ' (' . trim($error) . ')' : '';

        if ($error !== null && str_contains($error, 'insecure_url_refused')) {
            return $label . ' is configured with an http:// address on the public internet. That would '
                 . 'send your API key unencrypted, so it was refused. Use https://, or an address on '
                 . 'this machine or your own network.';
        }

        return match ($status) {
            'auth_error'   => $label . ' rejected the key. Check it was copied in full, and that the '
                            . 'account it belongs to is active.' . $detail,
            'overloaded'   => $label . ' is overloaded at the moment. This passes on its own; try the test again shortly.',
            'rate_limited' => $label . ' is rate limiting this key. It may be working but busy, or out of '
                            . 'credit.' . $detail,
            'timeout'      => $label . ' did not respond in time. Try again; if it keeps happening the '
                            . 'service may be having trouble.' . $detail,
            'unavailable'  => $label . ' could not be reached.' . $detail,
            'bad_response' => $label . ' replied with something unexpected. The model name is the usual '
                            . 'cause — check it exists on your account.' . $detail,
            default        => $label . ' could not be used.' . $detail,
        };
    }
}
