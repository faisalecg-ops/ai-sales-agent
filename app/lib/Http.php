<?php
declare(strict_types=1);

/**
 * Minimal outbound HTTP client (cURL). Used for LLM and geo providers.
 */
final class Http
{
    /**
     * @return array{ok:bool,status:int,body:string,error:?string,latency_ms:int}
     */
    /**
     * @param bool $allowPlainHttp permit http:// — only honoured for hosts on
     *        the local machine or a private network, which is where a
     *        self-hosted model such as Ollama actually lives. Public hosts
     *        always require HTTPS, so this can never be used to send an API
     *        key over the open internet in clear text.
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 25,
        bool $allowPlainHttp = false
    ): array {
        $start = microtime(true);

        if (!function_exists('curl_init')) {
            return self::result(false, 0, '', 'curl_unavailable', $start);
        }

        $plain = $allowPlainHttp && self::isLocalPlainHttp($url);
        if (str_starts_with(strtolower($url), 'http://') && !$plain) {
            return self::result(
                false,
                0,
                '',
                'insecure_url_refused',
                $start
            );
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(3, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(10, max(3, (int)ceil($timeout / 3))),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => self::formatHeaders($headers),
            CURLOPT_USERAGENT      => 'AISalesAgent/1.0 (+PHP)',
            CURLOPT_PROTOCOLS      => $plain ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($response === false) {
            return self::result(false, $status, '', $error ?? 'request_failed', $start);
        }

        return self::result($status >= 200 && $status < 300, $status, (string)$response, $error, $start);
    }

    public static function postJson(
        string $url,
        array $payload,
        array $headers = [],
        int $timeout = 25,
        bool $allowPlainHttp = false
    ): array {
        $headers['Content-Type'] = 'application/json';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::request('POST', $url, $headers, $json === false ? '{}' : $json, $timeout, $allowPlainHttp);
    }

    /**
     * Is this an http:// address on this machine or a private network?
     *
     * A self-hosted model is reached at a loopback or LAN address, where there
     * is no public network to eavesdrop on. Anything else over plain HTTP is
     * refused, so a mistyped or hostile setting cannot leak a key or turn the
     * server into a probe for arbitrary public services.
     */
    public static function isLocalPlainHttp(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http') {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // A bare hostname with no dot is a local machine name on most networks.
        if (!str_contains($host, '.') && !str_contains($host, ':')) {
            return true;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null;
        if ($ip === null) {
            // A name that resolves outside the private ranges is not local.
            $resolved = @gethostbyname($host);
            $ip = ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false)
                ? $resolved
                : null;
            if ($ip === null) {
                return false;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    public static function getJson(string $url, array $headers = [], int $timeout = 10): array
    {
        $headers['Accept'] = 'application/json';
        return self::request('GET', $url, $headers, null, $timeout);
    }

    /** Decode a JSON body, returning null on failure. */
    public static function decode(string $body): ?array
    {
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    private static function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = is_int($k) ? (string)$v : $k . ': ' . $v;
        }
        return $out;
    }

    private static function result(bool $ok, int $status, string $body, ?string $error, float $start): array
    {
        return [
            'ok'         => $ok,
            'status'     => $status,
            'body'       => $body,
            'error'      => $error,
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
        ];
    }
}
