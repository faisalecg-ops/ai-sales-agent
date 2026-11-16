<?php
declare(strict_types=1);

/**
 * POST /api/intro.php
 *
 * The introduction card at the start of a website chat: first name, company,
 * country and exhibiting or visiting. All the deciding is in ChatIntro.
 */

require __DIR__ . '/_api.php';

// Research before the first exhibitor reply waits up to 20 seconds, then
// the reply itself is written (fix plan step 3, 11 Nov 2026).
@set_time_limit(120);

$ctx   = api_context();
$agent = $ctx['agent'];
$input = $ctx['input'];

if (Validator::opaqueId($input['session_id'] ?? null, 48) === null) {
    api_fail('session_required', 400, $ctx['cors']);
}

// The same invisible field the contact form carries (Section 47).
if (trim((string)($input['company_website_hp'] ?? '')) !== '') {
    app_log('abuse', 'honeypot triggered on intro card', ['agent' => (int)$agent['id']]);
    api_fail('rate_limited', 429, $ctx['cors']);
}

$requestId = Validator::opaqueId($input['request_id'] ?? null);
if ($requestId === null) {
    api_fail('request_id_required', 400, $ctx['cors']);
}

try {
    $result = ChatIntro::submit($agent, [
        'request_id'     => $requestId,
        'session_id'     => (string)$input['session_id'],
        'session_secret' => (string)($input['session_secret'] ?? ''),
        'first_name'     => $input['first_name'] ?? null,
        'company'        => $input['company'] ?? null,
        'website'        => $input['website'] ?? null,
        'language'       => $input['language'] ?? null,
        'country'        => $input['country'] ?? null,
        'interest'       => $input['interest'] ?? null,
        'origin'         => $ctx['origin'],
    ]);
} catch (RuntimeException $ex) {
    if (in_array($ex->getMessage(), ['invalid_session', 'unknown_session'], true)) {
        api_fail('session_expired', 409, $ctx['cors']);
    }
    throw $ex;
}

if (empty($result['ok'])) {
    api_respond([
        'ok'      => false,
        'error'   => (string)($result['error'] ?? 'invalid'),
        'message' => (string)($result['message'] ?? 'Please check the details and try again.'),
    ], 422, $ctx['cors']);
}

api_respond($result, 200, $ctx['cors']);
