<?php
declare(strict_types=1);

/**
 * Searching the web for company research.
 *
 * Behind an interface for the same reason as the AI and messaging
 * providers: the owner chose Tavily to start, and may later move to
 * Anthropic's or OpenAI's built-in search, or another service. Nothing
 * outside this file knows which one is in use; switching means adding one
 * adapter here, not changing the research.
 *
 * A provider only searches and reads pages. Deciding what the pages mean —
 * which facts are real, how relevant the company is — happens afterwards,
 * in CompanyResearch, under the platform's own rules.
 */
interface ResearchProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @return array{ok: bool, results: array<int,array{title:string,url:string,content:string}>,
     *               credits: int, error: ?string}
     */
    public function search(string $query, array $options = []): array;
}

abstract class BaseResearchProvider implements ResearchProvider
{
    protected function result(bool $ok, array $results = [], int $credits = 0, ?string $error = null): array
    {
        return [
            'ok'      => $ok,
            'results' => $results,
            'credits' => $credits,
            'error'   => $error !== null ? mb_substr($error, 0, 400) : null,
        ];
    }
}

/**
 * Every search service the owner might use, as data — including the ones
 * not yet connected, so the Settings page can show what is possible and
 * what switching would involve. Prices are as checked on 29 September 2026
 * and should be confirmed before switching.
 */
final class ResearchCatalogue
{
    public const PROVIDERS = [
        'tavily' => [
            'label'     => 'Tavily',
            'available' => true,
            'key'       => 'research.tavily.api_key',
            'cost'      => '$8 per 1,000 searches (pay as you go); 1,000 free credits a month. '
                         . 'A normal search uses 1 credit, a deep one 2.',
            'note'      => 'Built for AI research: returns the relevant text of each page, not just links. '
                         . 'Works with whichever AI provider each exhibition uses.',
            'console'   => 'https://app.tavily.com',
        ],
        'anthropic' => [
            'label'     => 'Claude web search (Anthropic)',
            'available' => false,
            'cost'      => '$10 per 1,000 searches, plus normal Claude usage.',
            'note'      => 'Uses the Anthropic key already set up for the AI. Searching and reading are done by '
                         . 'Claude itself, with a source address for every fact. Ties research to Anthropic.',
        ],
        'openai' => [
            'label'     => 'ChatGPT web search (OpenAI)',
            'available' => false,
            'cost'      => '$10 per 1,000 searches, plus the text it reads (charged as normal usage).',
            'note'      => 'Uses the OpenAI key already set up for the AI. Ties research to OpenAI.',
        ],
        'gemini' => [
            'label'     => 'Gemini with Google Search (Google)',
            'available' => false,
            'cost'      => '5,000 free searches a month, then $14 per 1,000.',
            'note'      => 'Google\'s own index through the Gemini key. Ties research to Gemini.',
        ],
        'exa' => [
            'label'     => 'Exa',
            'available' => false,
            'cost'      => '$1.50 per 1,000 searches; 1,000 free a month.',
            'note'      => 'Cheapest. Returns summaries rather than full page text, so less evidence per source.',
        ],
        'perplexity' => [
            'label'     => 'Perplexity Sonar',
            'available' => false,
            'cost'      => 'From $1 per million words read, plus a charge per request.',
            'note'      => 'Returns a written answer with citations rather than raw results.',
        ],
        'brave' => [
            'label'     => 'Brave Search API',
            'available' => false,
            'cost'      => '$5 per 1,000 searches; no free tier.',
            'note'      => 'Independent index. Returns links and short snippets only.',
        ],
        'serper' => [
            'label'     => 'Serper (Google results)',
            'available' => false,
            'cost'      => 'About $0.30–$1.00 per 1,000 searches.',
            'note'      => 'Google results, links and snippets only — pages would then have to be read separately.',
        ],
        'none' => [
            'label'     => 'Not set up',
            'available' => true,
            'cost'      => '',
            'note'      => 'No research is done. Conversations work as before.',
        ],
    ];

    /** Services that are gone and cannot be used, so nobody plans around them. */
    public const RETIRED = [
        'Google Custom Search JSON API' => 'Closing on 1 January 2027.',
        'Bing Web Search API'           => 'Retired by Microsoft in August 2025.',
    ];

    public static function chosen(): string
    {
        $name = (string)Settings::get('research.provider', 'none');
        return isset(self::PROVIDERS[$name]) && self::PROVIDERS[$name]['available'] ? $name : 'none';
    }

    /** Only what can actually be chosen today. */
    public static function selectable(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            if ($meta['available']) {
                $out[$key] = (string)$meta['label'];
            }
        }
        return $out;
    }

    public static function secretKeys(): array
    {
        $keys = [];
        foreach (self::PROVIDERS as $meta) {
            if (!empty($meta['key'])) {
                $keys[] = (string)$meta['key'];
            }
        }
        return $keys;
    }
}

/**
 * Tavily's search endpoint: one POST, a key in the Authorization header,
 * results with the relevant text of each page already extracted.
 */
final class TavilyResearchProvider extends BaseResearchProvider
{
    private const ENDPOINT = 'https://api.tavily.com/search';

    public function name(): string
    {
        return 'tavily';
    }

    public function isConfigured(): bool
    {
        return trim((string)Settings::get('research.tavily.api_key', '')) !== '';
    }

    public function search(string $query, array $options = []): array
    {
        $key = trim((string)Settings::get('research.tavily.api_key', ''));
        if ($key === '') {
            return $this->result(false, [], 0, 'No Tavily API key is saved.');
        }

        $body = [
            'query'         => mb_substr($query, 0, 400),
            'search_depth'  => ($options['deep'] ?? false) ? 'advanced' : 'basic',
            'max_results'   => max(1, min(10, (int)($options['max_results'] ?? 5))),
            'include_answer' => false,
            'include_usage' => true,
        ];
        if (!empty($options['include_domains'])) {
            $body['include_domains'] = array_values(array_slice((array)$options['include_domains'], 0, 20));
        }
        if (!empty($options['exclude_domains'])) {
            $body['exclude_domains'] = array_values(array_slice((array)$options['exclude_domains'], 0, 50));
        }

        // Overridable only so a stand-in service on this machine can check the
        // connection in testing. Plain http is refused unless it is local.
        $endpoint = (string)Settings::get('research.tavily.endpoint', self::ENDPOINT);

        $response = Http::request('POST', $endpoint, [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ], json_encode($body, JSON_UNESCAPED_UNICODE), (int)($options['timeout'] ?? 25),
            Http::isLocalPlainHttp($endpoint));

        $status = (int)($response['status'] ?? 0);
        if ($status === 0) {
            app_log('research', 'Tavily unreachable', ['error' => (string)($response['error'] ?? '')]);
            return $this->result(false, [], 0,
                'Could not reach Tavily. Either the service is down, or this server is not allowed to make '
                . 'outgoing connections.');
        }
        if ($status === 401 || $status === 403) {
            return $this->result(false, [], 0, 'Tavily refused the API key. Check it under Settings.');
        }
        if ($status === 429 || $status === 432 || $status === 433) {
            return $this->result(false, [], 0, 'Tavily\'s usage limit has been reached for now.');
        }
        if ($status < 200 || $status >= 300) {
            return $this->result(false, [], 0, 'Tavily returned an error (' . $status . ').');
        }

        $data = Http::decode((string)($response['body'] ?? ''));
        if ($data === null) {
            return $this->result(false, [], 0, 'Tavily\'s answer could not be read.');
        }

        $results = [];
        foreach ((array)($data['results'] ?? []) as $row) {
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $results[] = [
                'title'   => mb_substr(trim((string)($row['title'] ?? '')), 0, 300),
                'url'     => mb_substr($url, 0, 500),
                'content' => mb_substr(trim((string)($row['content'] ?? '')), 0, 4000),
            ];
        }

        $credits = (int)($data['usage']['credits'] ?? ($body['search_depth'] === 'advanced' ? 2 : 1));

        return $this->result(true, $results, $credits);
    }
}

final class NullResearchProvider extends BaseResearchProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function search(string $query, array $options = []): array
    {
        return $this->result(false, [], 0, 'No research service is set up.');
    }
}

final class ResearchProviderFactory
{
    private static ?ResearchProvider $testProvider = null;

    /** Set only by the automated tests, so no real search is ever made. */
    public static function setTestProvider(?ResearchProvider $provider): void
    {
        self::$testProvider = $provider;
    }

    public static function make(): ResearchProvider
    {
        if (self::$testProvider !== null) {
            return self::$testProvider;
        }
        return match (ResearchCatalogue::chosen()) {
            'tavily' => new TavilyResearchProvider(),
            default  => new NullResearchProvider(),
        };
    }
}
