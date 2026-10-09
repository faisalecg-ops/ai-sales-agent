<?php
declare(strict_types=1);

/**
 * Stable geo-provider interface (Section 13). Business logic depends on this
 * shape only, so the provider can be swapped without touching pricing rules.
 *
 * Input:  public_ip
 * Output: country_code, country_name, status, provider, timestamp, failure_reason
 */
interface GeoProvider
{
    public function name(): string;

    /**
     * @return array{
     *   country_code: ?string,
     *   country_name: ?string,
     *   status: string,            // 'ok' | 'failed'
     *   provider: string,
     *   timestamp: string,
     *   failure_reason: ?string,
     *   latency_ms: int
     * }
     */
    public function lookup(string $ip): array;
}

abstract class BaseGeoProvider implements GeoProvider
{
    protected function ok(?string $code, ?string $name, int $latency): array
    {
        return [
            'country_code'   => $code ? strtoupper(substr($code, 0, 2)) : null,
            'country_name'   => $name,
            'status'         => $code ? 'ok' : 'failed',
            'provider'       => $this->name(),
            'timestamp'      => gmdate('c'),
            'failure_reason' => $code ? null : 'no_country_in_response',
            'latency_ms'     => $latency,
        ];
    }

    protected function fail(string $reason, int $latency = 0): array
    {
        return [
            'country_code'   => null,
            'country_name'   => null,
            'status'         => 'failed',
            'provider'       => $this->name(),
            'timestamp'      => gmdate('c'),
            'failure_reason' => mb_substr($reason, 0, 200),
            'latency_ms'     => $latency,
        ];
    }
}
