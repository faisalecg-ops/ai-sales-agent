<?php
declare(strict_types=1);

/**
 * Circuit breaker (Section 45).
 *
 * Repeated provider failures flip the agent to Unavailable, at which point
 * new conversations skip the model call entirely and get the approved
 * static fallback plus a contact form. Recovery is opportunistic: the next
 * request after the cooldown triggers a health check. No daemon is involved,
 * because shared hosting cannot run one.
 */
final class CircuitBreaker
{
    public const NORMAL      = 'normal';
    public const UNAVAILABLE = 'unavailable';

    /** Failure statuses that count toward the breaker. */
    private const COUNTED = ['timeout', 'unavailable', 'bad_response', 'rate_limited', 'auth_error'];

    public static function health(int $agentId, string $provider): array
    {
        $row = db()->one(
            'SELECT * FROM agent_provider_health WHERE agent_id = ? AND provider = ? LIMIT 1',
            [$agentId, $provider]
        );
        if ($row) {
            return $row;
        }
        db()->exec(
            'INSERT INTO agent_provider_health (agent_id, provider, status, last_checked_at)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE provider = provider',
            [$agentId, $provider, self::NORMAL, gmdate('Y-m-d H:i:s')]
        );
        return db()->one(
            'SELECT * FROM agent_provider_health WHERE agent_id = ? AND provider = ? LIMIT 1',
            [$agentId, $provider]
        ) ?? [
            'agent_id' => $agentId, 'provider' => $provider, 'status' => self::NORMAL,
            'consecutive_failures' => 0, 'last_failure_at' => null, 'last_success_at' => null,
        ];
    }

    /**
     * Should this request skip the model entirely?
     *
     * Returns false (do not skip) when the cooldown has elapsed, so the
     * request itself becomes the health check.
     */
    public static function shouldSkipModel(array $agent): bool
    {
        $provider = (string)$agent['llm_provider'];
        $health   = self::health((int)$agent['id'], $provider);

        if ((string)$health['status'] !== self::UNAVAILABLE) {
            return false;
        }

        $cooldown = max(30, (int)($agent['breaker_cooldown_seconds'] ?? 300));
        $since    = $health['status_changed_at'] ?? $health['last_failure_at'] ?? null;
        if ($since === null) {
            return true;
        }
        $elapsed = time() - strtotime((string)$since . ' UTC');

        // Cooldown elapsed: let this request act as the health check.
        return $elapsed < $cooldown;
    }

    public static function isUnavailable(array $agent): bool
    {
        $health = self::health((int)$agent['id'], (string)$agent['llm_provider']);
        return (string)$health['status'] === self::UNAVAILABLE;
    }

    /** Record a successful call. Flips back to Normal automatically. */
    public static function recordSuccess(array $agent, string $provider): void
    {
        $health = self::health((int)$agent['id'], $provider);
        $wasDown = (string)$health['status'] === self::UNAVAILABLE;

        db()->update('agent_provider_health', [
            'status'               => self::NORMAL,
            'consecutive_failures' => 0,
            'last_success_at'      => gmdate('Y-m-d H:i:s'),
            'last_checked_at'      => gmdate('Y-m-d H:i:s'),
            'status_changed_at'    => $wasDown ? gmdate('Y-m-d H:i:s') : ($health['status_changed_at'] ?? null),
            'last_error'           => null,
        ], 'agent_id = ? AND provider = ?', [(int)$agent['id'], $provider]);

        if ($wasDown) {
            Audit::log('agent_status_recovered', (int)$agent['id'], 'agent', (int)$agent['id'],
                'Provider ' . $provider . ' recovered; agent back to Normal');
            app_log('breaker', 'agent recovered', ['agent' => (int)$agent['id'], 'provider' => $provider]);
        }
    }

    /**
     * Record a failure. Crossing the threshold flips the agent to Unavailable.
     *
     * @return bool true when the agent is now unavailable
     */
    public static function recordFailure(array $agent, string $provider, string $status, ?string $error): bool
    {
        if (!in_array($status, self::COUNTED, true)) {
            return false;
        }

        $health    = self::health((int)$agent['id'], $provider);
        $failures  = (int)$health['consecutive_failures'] + 1;
        $threshold = max(2, (int)($agent['breaker_failure_threshold'] ?? 5));
        $nowDown   = $failures >= $threshold;
        $wasDown   = (string)$health['status'] === self::UNAVAILABLE;

        db()->update('agent_provider_health', [
            'status'               => $nowDown ? self::UNAVAILABLE : (string)$health['status'],
            'consecutive_failures' => $failures,
            'last_failure_at'      => gmdate('Y-m-d H:i:s'),
            'last_checked_at'      => gmdate('Y-m-d H:i:s'),
            'status_changed_at'    => ($nowDown && !$wasDown) ? gmdate('Y-m-d H:i:s') : ($health['status_changed_at'] ?? null),
            'last_error'           => $error !== null ? mb_substr($status . ': ' . $error, 0, 400) : $status,
        ], 'agent_id = ? AND provider = ?', [(int)$agent['id'], $provider]);

        if ($nowDown && !$wasDown) {
            Audit::log('agent_status_unavailable', (int)$agent['id'], 'agent', (int)$agent['id'],
                'Provider ' . $provider . ' failed ' . $failures . ' times consecutively (' . $status . ')');
            app_log('breaker', 'agent unavailable', [
                'agent' => (int)$agent['id'], 'provider' => $provider, 'failures' => $failures, 'status' => $status,
            ]);

            // An invalid credential is a configuration problem, not an outage.
            if ($status === 'auth_error') {
                self::alertAdministrator($agent, $provider, $error);
            }
        }

        return $nowDown || $wasDown;
    }

    private static function alertAdministrator(array $agent, string $provider, ?string $error): void
    {
        $to = trim((string)($agent['notify_email'] ?? ''));
        if ($to === '') {
            return;
        }
        Mailer::queue(
            $to,
            'Action needed: ' . (string)$agent['name'] . ' assistant is offline',
            "The AI assistant for " . (string)$agent['name'] . " has been taken offline automatically.\n\n"
            . "Reason: " . LlmCatalogue::label($provider) . " rejected our credentials.\n"
            . ($error !== null ? "Detail: " . $error . "\n" : '')
            . "\nVisitors are currently seeing the offline message and a contact form, so nothing is being lost.\n\n"
            . "Check the API key under Settings in the admin panel:\n" . url('admin/settings.php'),
            (int)$agent['id'],
            'breaker-auth-' . (int)$agent['id'] . '-' . gmdate('Y-m-d-H')
        );
    }

    /** Status summary for the admin dashboard. */
    public static function statusFor(array $agent): array
    {
        $health = self::health((int)$agent['id'], (string)$agent['llm_provider']);
        return [
            'status'          => (string)$health['status'],
            'label'           => (string)$health['status'] === self::UNAVAILABLE ? 'Unavailable' : 'Normal',
            'failures'        => (int)$health['consecutive_failures'],
            'last_success_at' => $health['last_success_at'] ?? null,
            'last_failure_at' => $health['last_failure_at'] ?? null,
            'changed_at'      => $health['status_changed_at'] ?? null,
            'last_error'      => $health['last_error'] ?? null,
            'provider'        => (string)$health['provider'],
        ];
    }
}
