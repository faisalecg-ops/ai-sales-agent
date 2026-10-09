<?php
declare(strict_types=1);

/**
 * Sending a WhatsApp message.
 *
 * Kept behind an interface for one reason: TextMeBot is a practical way to
 * start and is not Meta's official API. Moving to the official one later
 * should change one adapter, not the conversation system. Nothing outside
 * this file knows which provider is in use.
 */
interface MessagingProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @return array{ok: bool, reference: ?string, status: string, error: ?string}
     */
    public function send(string $toE164, string $body, array $options = []): array;
}

abstract class BaseMessagingProvider implements MessagingProvider
{
    /** @return array{ok: bool, reference: ?string, status: string, error: ?string} */
    protected function result(bool $ok, ?string $reference, string $status, ?string $error = null): array
    {
        return [
            'ok'        => $ok,
            'reference' => $reference,
            'status'    => $status,
            'error'     => $error !== null ? mb_substr($error, 0, 400) : null,
        ];
    }
}

/**
 * The catalogue, as data.
 *
 * Deliberately says in plain words that TextMeBot is not the official API,
 * because that distinction has consequences the operator is entitled to
 * understand and it must not quietly disappear into a settings screen.
 */
final class MessagingCatalogue
{
    public const PROVIDERS = [
        'textmebot' => [
            'label'    => 'TextMeBot',
            'official' => false,
            'endpoint' => 'https://api.textmebot.com/send.php',
            // Kept only so a key saved platform-wide by an earlier version is
            // still treated as a secret. Keys now live on each exhibition.
            'key'      => 'whatsapp.textmebot.api_key',
            'note'     => 'A third-party service that links to a real WhatsApp account, '
                        . 'not Meta\'s official Business API. Inexpensive and quick to set up; '
                        . 'the number it uses can be blocked by WhatsApp.',
        ],
        'none' => [
            'label'    => 'Not set up',
            'official' => false,
            'endpoint' => '',
            'key'      => '',
            'note'     => 'Nothing is sent.',
        ],
    ];

    public static function options(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $out[$key] = (string)$meta['label'];
        }
        return $out;
    }

    public static function meta(string $name): array
    {
        return self::PROVIDERS[$name] ?? self::PROVIDERS['none'];
    }

    /** Keys holding credentials, so Settings encrypts them. */
    public static function secretKeys(): array
    {
        $keys = [];
        foreach (self::PROVIDERS as $meta) {
            if (($meta['key'] ?? '') !== '') {
                $keys[] = (string)$meta['key'];
            }
        }
        return $keys;
    }

    public static function chosen(): string
    {
        $name = (string)Settings::get('whatsapp.provider', 'none');
        return isset(self::PROVIDERS[$name]) ? $name : 'none';
    }
}

/**
 * TextMeBot.
 *
 * A single GET request carrying the recipient, the key and the text. The key
 * never appears in a log line, because a log is read by more people than a
 * settings screen is.
 */
final class TextMeBotProvider extends BaseMessagingProvider
{
    /**
     * The key for one WhatsApp number.
     *
     * A TextMeBot key belongs to the phone it was linked with, so a provider
     * is always made for one exhibition, with that exhibition's key. There is
     * deliberately no platform-wide key to fall back on: falling back would
     * send one exhibition's messages from another exhibition's number.
     */
    public function __construct(private string $apiKey)
    {
    }

    public function name(): string
    {
        return 'textmebot';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function send(string $toE164, string $body, array $options = []): array
    {
        if ($this->apiKey === '') {
            return $this->result(false, null, 'not_configured',
                'No API key is saved for this exhibition\'s WhatsApp number.');
        }

        $url = $this->requestUrl($toE164, $body, $options);

        // Headers third, body fourth. The key travels in the URL, so this
        // must be https — Http refuses plain http to the public internet,
        // which is exactly right when a credential is in the address.
        $response = Http::request('GET', $url, [], null, (int)($options['timeout'] ?? 25));

        return $this->interpret($response);
    }

    /** The address the message is sent to. Separate so it can be checked without sending. */
    public function requestUrl(string $toE164, string $body, array $options = []): string
    {
        $endpoint = (string)Settings::get(
            'whatsapp.textmebot.endpoint',
            (string)MessagingCatalogue::meta('textmebot')['endpoint']
        );

        // The provider expects the number without a plus.
        $url = $endpoint . '?recipient=' . rawurlencode(ltrim($toE164, '+'))
             . '&apikey=' . rawurlencode($this->apiKey)
             . '&text=' . rawurlencode($body);

        if (!empty($options['file_url'])) {
            $url .= '&file=' . rawurlencode((string)$options['file_url']);
        }

        return $url;
    }

    /**
     * What the service's answer means.
     *
     * Separate from send() so it can be checked against every kind of answer
     * the service gives without a message ever being sent.
     *
     * @param array{ok?: bool, status?: int, body?: string, error?: ?string} $response
     * @return array{ok: bool, reference: ?string, status: string, error: ?string}
     */
    public function interpret(array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $text   = trim((string)($response['body'] ?? ''));

        // No status at all means nothing came back — the service was never
        // reached. Anything with a status was reached and answered, and is
        // judged on what it said below. Http reports both as not-ok, so the
        // status is what tells them apart: a refused key must not be
        // reported as a network fault, or the operator looks in the wrong
        // place.
        if ($status === 0) {
            // The technical reason goes to the log, where someone who can act
            // on it will look. The operator is told what it means.
            app_log('whatsapp', 'TextMeBot unreachable', ['error' => (string)($response['error'] ?? '')]);
            return $this->result(false, null, 'network_error',
                'Could not reach TextMeBot. Either the service is down, or this server is not allowed to '
                . 'make outgoing connections — the hosting provider can confirm which.');
        }

        // Some answers arrive as a small web page. Only the words matter.
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);

        if ($status < 200 || $status >= 300) {
            return $this->result(false, null, 'http_' . $status, self::explain($plain, $status));
        }

        // The service answers in prose rather than a status code, so a
        // failure can arrive with a perfectly healthy 200. A clear success is
        // recognised first, so a success message that happens to mention a
        // limit ("40 of your daily limit left") is not read as a refusal.
        $lower = mb_strtolower($plain);

        $saysSuccess = (bool)preg_match('/\b(success|successfully|sent|queued|delivered)\b/', $lower);
        $saysFailure = (bool)preg_match(
            '/\b(error|invalid|not valid|expired|wrong|failed|fail|denied|blocked|not registered|'
            . 'not on whatsapp|exceeded)\b/',
            $lower
        );

        if ($saysFailure && !$saysSuccess) {
            return $this->result(false, null, 'rejected', self::explain($plain, $status));
        }

        if (!$saysSuccess && !$saysFailure) {
            // Neither — accepted, because the service took the request, but
            // written down so the wording can be checked the first time the
            // platform is used for real.
            app_log('whatsapp', 'TextMeBot answer not recognised', ['answer' => mb_substr($plain, 0, 200)]);
        }

        return $this->result(true, self::reference($plain), 'ok');
    }

    /**
     * Whatever the service gives us to identify the message, if anything.
     * Only something that looks like an identifier — with a digit in it — so
     * an ordinary word from the reply is not stored as a reference.
     */
    private static function reference(string $body): ?string
    {
        if (preg_match('/\b(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{8,}\b/', $body, $m) === 1) {
            return mb_substr($m[0], 0, 160);
        }
        return null;
    }

    private static function explain(string $body, int $status): string
    {
        $lower = mb_strtolower($body);

        // Before anything the body says, because a 429 is unambiguous.
        if ($status === 429) {
            return 'The service asked us to slow down. The message will be tried again later.';
        }

        if (str_contains($lower, 'apikey') || str_contains($lower, 'api key') || $status === 401) {
            return 'The API key was refused. Check it under Settings.';
        }
        if (str_contains($lower, 'expired')) {
            return 'The TextMeBot subscription appears to have expired.';
        }
        if (str_contains($lower, 'limit')) {
            return 'The service reported a sending limit.';
        }
        if (str_contains($lower, 'not valid') || str_contains($lower, 'invalid')) {
            return 'The number was refused: it may not be on WhatsApp.';
        }
        return $body === '' ? 'The service gave no reason.' : mb_substr($body, 0, 200);
    }
}

/** Used when nothing is configured. Refuses cleanly rather than pretending. */
final class NullMessagingProvider extends BaseMessagingProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $toE164, string $body, array $options = []): array
    {
        return $this->result(false, null, 'not_configured',
            'No messaging service has been set up, so nothing was sent.');
    }
}

final class MessagingProviderFactory
{
    private static ?MessagingProvider $testProvider = null;

    /** Set only by the automated tests, so no message leaves the building. */
    public static function setTestProvider(?MessagingProvider $provider): void
    {
        self::$testProvider = $provider;
    }

    /**
     * The messaging service for one exhibition, holding that exhibition's own
     * key. Always made per exhibition, never shared between them.
     */
    public static function make(array $agent): MessagingProvider
    {
        if (self::$testProvider !== null) {
            return self::$testProvider;
        }

        return match (MessagingCatalogue::chosen()) {
            'textmebot' => new TextMeBotProvider(self::keyFor($agent)),
            default     => new NullMessagingProvider(),
        };
    }

    /** This exhibition's key, decrypted, or an empty string. */
    public static function keyFor(array $agent): string
    {
        $stored = trim((string)($agent['whatsapp_api_key'] ?? ''));
        if ($stored === '') {
            return '';
        }
        return (string)(Crypto::decrypt($stored) ?? '');
    }

    /** Store a key for an exhibition, encrypted. An empty key removes it. */
    public static function saveKey(int $agentId, string $key): void
    {
        $key = trim($key);
        db()->update('agents', [
            'whatsapp_api_key' => $key === '' ? null : Crypto::encrypt($key),
        ], 'id = ?', [$agentId]);
    }

    /** Why sending is not possible right now, or null. */
    public static function problem(array $agent): ?string
    {
        if ((int)($agent['whatsapp_enabled'] ?? 0) !== 1) {
            return 'WhatsApp is switched off for this exhibition.';
        }
        if (trim((string)($agent['whatsapp_number'] ?? '')) === '') {
            return 'No WhatsApp number has been set for this exhibition.';
        }

        if (MessagingCatalogue::chosen() === 'none' && self::$testProvider === null) {
            return 'No messaging service is chosen. Choose one under Settings.';
        }

        $provider = self::make($agent);
        if (!$provider->isConfigured()) {
            return 'No API key is saved for this exhibition\'s WhatsApp number. Add it under Agents and embed, '
                 . 'next to the number.';
        }

        return null;
    }
}
