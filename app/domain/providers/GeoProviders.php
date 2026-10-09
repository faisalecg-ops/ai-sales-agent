<?php
declare(strict_types=1);

/**
 * Concrete geo providers. All use HTTPS.
 * Selected per agent via agents.geo_provider.
 */

/** ipwho.is — no API key, HTTPS, suitable as a default. */
final class IpWhoIsGeoProvider extends BaseGeoProvider
{
    public function name(): string
    {
        return 'ipwhois';
    }

    public function lookup(string $ip): array
    {
        $res = Http::getJson('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,message,country,country_code', [], 8);
        if (!$res['ok']) {
            return $this->fail('http_' . ($res['error'] ?? $res['status']), $res['latency_ms']);
        }
        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->fail('invalid_json', $res['latency_ms']);
        }
        if (($data['success'] ?? false) !== true) {
            return $this->fail('provider_error: ' . (string)($data['message'] ?? 'unknown'), $res['latency_ms']);
        }
        return $this->ok(
            isset($data['country_code']) ? (string)$data['country_code'] : null,
            isset($data['country']) ? (string)$data['country'] : null,
            $res['latency_ms']
        );
    }
}

/** ipinfo.io — token-based, higher accuracy and quota. */
final class IpInfoGeoProvider extends BaseGeoProvider
{
    public function name(): string
    {
        return 'ipinfo';
    }

    public function lookup(string $ip): array
    {
        $token = (string)Settings::get('geo.ipinfo.token', '');
        if ($token === '') {
            return $this->fail('token_not_configured');
        }
        $res = Http::getJson(
            'https://ipinfo.io/' . rawurlencode($ip) . '/json',
            ['Authorization' => 'Bearer ' . $token],
            8
        );
        if ($res['status'] === 429) {
            return $this->fail('quota_exhausted', $res['latency_ms']);
        }
        if (!$res['ok']) {
            return $this->fail('http_' . ($res['error'] ?? $res['status']), $res['latency_ms']);
        }
        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->fail('invalid_json', $res['latency_ms']);
        }
        if (!empty($data['bogon'])) {
            return $this->fail('private_or_reserved_ip', $res['latency_ms']);
        }
        $code = isset($data['country']) ? (string)$data['country'] : null;
        return $this->ok($code, $code ? GeoClassifier::countryName($code) : null, $res['latency_ms']);
    }
}

/** ipapi.co — no key required for low volume. */
final class IpApiCoGeoProvider extends BaseGeoProvider
{
    public function name(): string
    {
        return 'ipapico';
    }

    public function lookup(string $ip): array
    {
        $res = Http::getJson('https://ipapi.co/' . rawurlencode($ip) . '/json/', [], 8);
        if ($res['status'] === 429) {
            return $this->fail('quota_exhausted', $res['latency_ms']);
        }
        if (!$res['ok']) {
            return $this->fail('http_' . ($res['error'] ?? $res['status']), $res['latency_ms']);
        }
        $data = Http::decode($res['body']);
        if (!$data) {
            return $this->fail('invalid_json', $res['latency_ms']);
        }
        if (!empty($data['error'])) {
            return $this->fail('provider_error: ' . (string)($data['reason'] ?? 'unknown'), $res['latency_ms']);
        }
        return $this->ok(
            isset($data['country_code']) ? (string)$data['country_code'] : null,
            isset($data['country_name']) ? (string)$data['country_name'] : null,
            $res['latency_ms']
        );
    }
}

/**
 * Disabled provider: always Undetermined.
 * Used before a provider is configured, and for testing the Undetermined path.
 */
final class NullGeoProvider extends BaseGeoProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function lookup(string $ip): array
    {
        return $this->fail('geo_provider_disabled');
    }
}

/**
 * The geo services an administrator can choose between.
 */
final class GeoProviderFactory
{
    public static function available(): array
    {
        return [
            'ipwhois' => 'ipwho.is (free, no account needed)',
            'ipinfo'  => 'ipinfo.io (needs a token, more accurate)',
            'ipapico' => 'ipapi.co (free tier)',
            'none'    => 'None — country is never looked up',
        ];
    }
}
