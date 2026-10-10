<?php
declare(strict_types=1);
defined('SM_BOOT') || exit;

/**
 * The event blog's own API (docs/blog-api.md) — the only way /social writes
 * into a blog. One blog per exhibition; its address and key are saved on the
 * "Blog and platforms" page, the key encrypted.
 */
final class SmBlog
{
    public function __construct(private string $base, private string $key)
    {
        $this->base = rtrim($base, '/');
    }

    public static function forAgent(int $agentId): ?self
    {
        $row = SmEvent::row($agentId);
        $url = trim((string)($row['blog_url'] ?? ''));
        $enc = (string)($row['blog_api_key'] ?? '');
        if ($url === '' || $enc === '') {
            return null;
        }
        $key = Crypto::decrypt($enc);
        return $key ? new self($url, $key) : null;
    }

    public static function saveConnection(int $agentId, string $url, ?string $key, ?string $hookSecret): void
    {
        SmEvent::row($agentId);
        $data = ['blog_url' => rtrim($url, '/')];
        if ($key !== null && $key !== '') {
            $data['blog_api_key'] = Crypto::encrypt($key);
        }
        if ($hookSecret !== null && $hookSecret !== '') {
            $data['blog_hook_secret'] = Crypto::encrypt($hookSecret);
        }
        db()->update('sm_events', $data, 'agent_id = ?', [$agentId]);
    }

    /** @return array{ok:bool,status:int,data:?array,message:string} */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $url = $this->base . '/api/v1/' . ltrim($path, '/');
        $res = Http::request($method, $url, [
            'Authorization' => 'Bearer ' . $this->key,
            'X-Api-Key'     => $this->key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ], $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, 60, defined('SM_TESTING'));
        $status = (int)($res['status'] ?? 0);
        $data = Http::decode((string)($res['body'] ?? ''));
        if ($status === 0) {
            $err = (string)($res['error'] ?? '');
            $msg = $err === 'insecure_url_refused'
                ? 'The blog address must start with https://.'
                : 'The blog could not be reached. Check its address, and that the blog is installed there.';
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => $msg];
        }
        $ok = $status >= 200 && $status < 300 && is_array($data) && ($data['ok'] ?? false);
        $msg = $ok ? '' : match (true) {
            $status === 401 => 'The blog refused the API key. Make a new key in the blog admin → API keys and paste it here.',
            $status === 403 => (string)($data['message'] ?? 'The blog refused the request (it must use https).'),
            $status === 429 => 'The blog is busy (too many requests). Try again in a minute.',
            $status === 404 && $data === null => 'No blog API was found at that address. The address should end in /blog.',
            default => (string)($data['message'] ?? ('The blog answered with an error (' . $status . ').')),
        };
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'message' => $msg];
    }

    public function ping(): array
    {
        return $this->call('GET', 'ping');
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function categories(): array
    {
        $r = $this->call('GET', 'categories');
        return $r['ok'] ? (array)($r['data']['categories'] ?? []) : [];
    }

    /** @return list<array{label:string,url:string,kind:string}> */
    public function links(): array
    {
        $r = $this->call('GET', 'links');
        return $r['ok'] ? (array)($r['data']['links'] ?? []) : [];
    }

    public function uploadImage(string $bytes, string $filename, string $alt): array
    {
        return $this->call('POST', 'media', ['filename' => $filename, 'data' => base64_encode($bytes), 'alt' => mb_substr($alt, 0, 250)]);
    }

    public function createArticle(array $payload): array
    {
        return $this->call('POST', 'articles', $payload);
    }

    public function updateArticle(int $id, array $payload): array
    {
        return $this->call('PATCH', 'articles/' . $id, $payload);
    }

    public function getArticle(int $id): array
    {
        return $this->call('GET', 'articles/' . $id);
    }

    public function findByRef(string $ref): array
    {
        return $this->call('GET', 'articles?external_ref=' . rawurlencode($ref));
    }
}
