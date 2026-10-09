<?php
declare(strict_types=1);

/**
 * Shared front-controller logic for the public API.
 * Included by chat.php, session.php and contact.php.
 */

define('AISA_JSON_ENDPOINT', true);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

/** Maximum accepted request body, before any parsing (Section 43). */
const API_MAX_BODY_BYTES = 24576;

/** Emit a JSON response and stop. */
function api_respond(array $payload, int $status = 200, ?string $allowOrigin = null): never
{
    Security::apiHeaders($allowOrigin);
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Error response. Never carries internal detail.
 *
 * When no origin has been validated, the caller's own origin is echoed so the
 * browser will let the page read the error. These bodies carry nothing but an
 * error code; without the header the browser hides the response entirely and
 * the site owner sees only an unexplained network failure.
 */
function api_fail(string $code, int $status = 400, ?string $allowOrigin = null, array $extra = []): never
{
    if ($allowOrigin === null || $allowOrigin === '') {
        $allowOrigin = Security::originHeader();
    }
    api_respond(array_merge(['ok' => false, 'error' => $code], $extra), $status, $allowOrigin);
}

/** Read and decode the JSON request body. */
function api_input(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > API_MAX_BODY_BYTES) {
        api_fail('payload_too_large', 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, API_MAX_BODY_BYTES + 1);
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > API_MAX_BODY_BYTES) {
        api_fail('payload_too_large', 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_fail('invalid_json', 400);
    }
    return $data;
}

/**
 * Resolve the agent, validate the request origin and apply rate limits.
 * Every public endpoint starts here.
 *
 * @return array{agent:array,origin:?string,cors:?string,input:array}
 */
function api_context(bool $requireRateSlot = true): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        // Pre-flight: resolve the agent only to decide whether to allow it.
        $slug  = Validator::slug($_GET['agent'] ?? null);
        $agent = $slug !== null ? AgentResolver::bySlug($slug) : null;
        $cors  = $agent ? AgentResolver::corsOrigin($agent) : null;

        // If the agent could not be resolved, or its domain list does not
        // cover this origin, still answer the pre-flight by echoing the
        // origin back. A pre-flight grants nothing on its own: the real
        // request that follows is validated in full and refused with a
        // readable JSON error. Refusing here instead produces an opaque
        // browser-level CORS failure that tells the site owner nothing.
        if ($cors === null) {
            $cors = Security::originHeader();
        }

        Security::apiHeaders($cors);
        http_response_code($cors !== null ? 204 : 403);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        api_fail('method_not_allowed', 405);
    }

    $input = api_input();

    // The slug is a lookup key, never an authorisation token (Section 36).
    $slug  = Validator::slug($input['agent'] ?? ($_GET['agent'] ?? null));
    if ($slug === null) {
        api_fail('unknown_agent', 404);
    }

    $agent = AgentResolver::bySlug($slug);
    if ($agent === null) {
        // A disabled or deleted agent stops accepting public traffic (test 21).
        api_fail('unknown_agent', 404);
    }

    // Anything resembling an internal id in the request is discarded.
    unset($input['agent_id'], $input['id']);

    $cors = AgentResolver::corsOrigin($agent);

    // Domain validation (Section 40).
    $origin = AgentResolver::validateOrigin($agent);
    if (!$origin['allowed']) {
        app_log('security', 'request from unauthorised origin', [
            'agent'  => (int)$agent['id'],
            'origin' => $origin['origin'],
            'reason' => $origin['reason'],
        ]);
        Audit::log('origin_rejected', (int)$agent['id'], 'agent', (int)$agent['id'],
            (string)$origin['reason'] . ' origin=' . (string)$origin['origin']);
        // The reason is returned so the site owner can tell "this domain is
        // not on the list" apart from "no list has been set up at all".
        // It discloses nothing a caller does not already know: they sent the
        // origin, and it is their own site.
        api_fail('origin_not_allowed', 403, $cors, ['reason' => (string)$origin['reason']]);
    }

    // Rate limiting (Section 47).
    if ($requireRateSlot) {
        $ip = Security::clientIp();
        if (!RateLimiter::allow('ip:' . $ip, (int)$agent['rate_per_ip_per_min'], 60, (int)$agent['id'])) {
            api_fail('rate_limited', 429, $cors);
        }
        $session = Validator::opaqueId($input['session_id'] ?? null, 48);
        if ($session !== null
            && !RateLimiter::allow('sess:' . $session, (int)$agent['rate_per_session_per_min'], 60, (int)$agent['id'])) {
            api_fail('rate_limited', 429, $cors);
        }
    }

    // Opportunistic housekeeping, roughly once every fifty requests.
    if (random_int(1, 50) === 1) {
        try {
            RateLimiter::prune((int)cfg('retention.rate_event_hours', 24));
        } catch (Throwable) {
            // Housekeeping never breaks a visitor request.
        }
    }

    return [
        'agent'  => $agent,
        'origin' => $origin['origin'],
        'cors'   => $cors,
        'input'  => $input,
    ];
}

/**
 * Queued notifications are flushed after the response has been sent, so a
 * slow mail server never delays a visitor's reply. Registered as a shutdown
 * hook because api_respond() exits.
 */
register_shutdown_function(static function (): void {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    try {
        Mailer::flush(5);
    } catch (Throwable $ex) {
        app_log('mail', 'flush failed', ['error' => $ex->getMessage()]);
    }
});
