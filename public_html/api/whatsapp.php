<?php
declare(strict_types=1);

/**
 * GET or POST /api/whatsapp.php?agent=<slug>&token=<secret>
 *
 * Where a reply from WhatsApp arrives.
 *
 * This is not the visitor API and deliberately shares none of its front
 * controller. Nothing here comes from a browser, so there is no origin to
 * check, no CORS to negotiate and no session to carry. What arrives is a
 * request from a machine, and the only thing standing between it and the
 * conversation engine is a secret in the address.
 *
 * Everything past that point is the same code the website uses: the same
 * prices, the same rules about geography, the same knowledge base, the same
 * handoff. The channel changes; the assistant does not.
 *
 * Two promises this file keeps whatever else happens:
 *  - it always answers 200, so the service never retries in a loop
 *  - a request to stop is obeyed, even when nothing else can be done
 */

define('AISA_JSON_ENDPOINT', true);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

const WA_MAX_BODY_BYTES = 16384;

/** How many messages a minute one exhibition will answer automatically. */
const WA_ANSWERS_PER_MINUTE = 60;

/**
 * Always answers 200.
 *
 * A messaging service that sees anything else assumes the message was lost
 * and sends it again, often for hours. Whether we could make sense of the
 * message is our problem, not something to argue about with a retry loop.
 * What actually happened is in the log and in the inbox table.
 */
function wa_done(string $note, bool $accepted = true): never
{
    if (!headers_sent()) {
        Security::apiHeaders(null);
        http_response_code(200);
    }
    echo json_encode(['ok' => $accepted, 'note' => $note], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Pull one value out, whatever the service decided to call it.
 *
 * Every WhatsApp bridge invents its own field names, and an unofficial one
 * may change them without notice. Reading several spellings costs nothing
 * and is the difference between a working webhook and a silent one.
 */
function wa_field(array $payload, array $names): string
{
    foreach ($names as $name) {
        $value = $payload;
        foreach (explode('.', $name) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if (is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return '';
}

function wa_truthy(string $value): bool
{
    return in_array(strtolower($value), ['1', 'true', 'yes'], true);
}

function wa_handle(): never
{
    // -----------------------------------------------------------------
    // 1. The secret
    // -----------------------------------------------------------------
    $expected = (string)Settings::get('whatsapp.webhook_secret', '');

    if ($expected === '') {
        app_log('whatsapp', 'inbound message but no webhook secret is set');
        wa_done('Inbound WhatsApp is not set up on this platform.', false);
    }

    $presented = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));

    if ($presented === '' || !hash_equals($expected, $presented)) {
        // Recorded, because a wrong token is either a misconfigured webhook
        // or someone guessing, and the two look different over time.
        app_log('security', 'whatsapp webhook token rejected', ['ip' => Security::clientIp()]);
        wa_done('Refused.', false);
    }

    // -----------------------------------------------------------------
    // 2. Which exhibition
    // -----------------------------------------------------------------
    $slug  = Validator::slug($_GET['agent'] ?? null);
    $agent = $slug !== null ? AgentResolver::bySlug($slug) : null;

    if ($agent === null) {
        app_log('whatsapp', 'inbound message for an unknown exhibition', ['slug' => (string)$slug]);
        wa_done('Unknown exhibition.', false);
    }

    // -----------------------------------------------------------------
    // 3. What the message says
    // -----------------------------------------------------------------
    $payload = [];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = (string)file_get_contents('php://input', false, null, 0, WA_MAX_BODY_BYTES + 1);

        if (strlen($raw) > WA_MAX_BODY_BYTES) {
            wa_done('The message was too large to read.', false);
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        } else {
            // Some services post a form rather than JSON.
            parse_str($raw, $form);
            $payload = is_array($form) ? $form : [];
        }
    }

    // Query parameters are merged in, because the same service may deliver by
    // either route. Our own two are removed so they cannot be read as content.
    $query = $_GET;
    unset($query['token'], $query['agent']);
    $payload = array_merge($payload, $query);

    // A copy of a message we sent, or a delivery receipt, is not someone
    // writing to us. Answering either would mean replying to ourselves, or
    // to the person we just messaged as though they had written back.
    if (wa_truthy(wa_field($payload, ['fromMe', 'from_me', 'message.fromMe', 'data.fromMe', 'isOutgoing']))) {
        wa_done('Our own message; ignored.');
    }

    // The sender only. "to" and "recipient" name the person a message was
    // sent to, and are deliberately not read.
    $from = wa_field($payload, [
        'sender', 'from', 'phone', 'wa_id', 'number',
        'message.from', 'data.from', 'data.sender', 'contact.wa_id', 'contacts.0.wa_id',
    ]);

    $body = wa_field($payload, [
        'text', 'message', 'body', 'content', 'msg',
        'message.text', 'message.body', 'data.text', 'data.body', 'data.message', 'text.body',
    ]);

    $reference = wa_field($payload, [
        'id', 'message_id', 'messageId', 'msg_id', 'uuid',
        'message.id', 'data.id', 'data.message_id',
    ]);

    if ($from === '') {
        app_log('whatsapp', 'inbound message with no sender', ['agent' => (int)$agent['id']]);
        wa_done('No sender in the message.', false);
    }

    if ($body === '') {
        // A photo, a voice note, or a delivery receipt. Nothing to read.
        app_log('whatsapp', 'inbound message with no text', ['agent' => (int)$agent['id']]);
        wa_done('Nothing to read in the message.', false);
    }

    if ($reference === '') {
        $reference = wa_derived_reference((int)$agent['id'], $from, $body);
        if ($reference === null) {
            wa_done('Already received.');
        }
    }

    // -----------------------------------------------------------------
    // 4. Whether to answer, or only to record and obey
    // -----------------------------------------------------------------
    $mayReply = true;
    $whyNot   = '';

    if ((int)($agent['whatsapp_enabled'] ?? 0) !== 1) {
        $mayReply = false;
        $whyNot   = 'Received while WhatsApp was switched off for this exhibition, so not answered.';
    } elseif (!RateLimiter::allow('wa:agent:' . (int)$agent['id'], WA_ANSWERS_PER_MINUTE, 60, (int)$agent['id'])) {
        // Counted per exhibition rather than per address, because every
        // message from the service arrives from the same address.
        $mayReply = false;
        $whyNot   = 'Too many messages arrived at once, so this one was not answered automatically.';
        app_log('whatsapp', 'inbound answer limit reached', ['agent' => (int)$agent['id']]);
    }

    $result = WhatsAppEngine::receive($agent, $from, $body, $reference, $mayReply, $whyNot);

    wa_done((string)$result['note'], (bool)$result['handled']);
}

/**
 * A reference for a message the service sent without one.
 *
 * The same sender with the same words inside ten minutes is taken to be the
 * same message delivered twice — which is what a retry looks like. Returns
 * null for such a repeat.
 */
function wa_derived_reference(int $agentId, string $from, string $body): ?string
{
    $phone = Phone::toE164($from, null, false) ?? Phone::fromInternational($from);

    if ($phone !== null) {
        $repeat = db()->value(
            'SELECT id FROM whatsapp_inbox
              WHERE agent_id = ? AND phone_e164 = ? AND body = ? AND received_at >= ? LIMIT 1',
            [$agentId, $phone, mb_substr($body, 0, 4000), gmdate('Y-m-d H:i:s', time() - 600)]
        );
        if ($repeat !== null) {
            return null;
        }
    }

    return 'derived:' . mb_substr(hash('sha256', $from . '|' . $body . '|' . microtime(true)), 0, 40);
}

// Everything inside one guard, so no failure anywhere — reading the secret,
// the database, the model — can turn into a 500 that the service retries.
try {
    wa_handle();
} catch (Throwable $ex) {
    app_log('whatsapp', 'inbound message failed', [
        'error' => $ex->getMessage(),
        'file'  => basename($ex->getFile()) . ':' . $ex->getLine(),
    ]);
    try {
        if (db()->inTransaction()) {
            db()->rollback();
        }
    } catch (Throwable) {
        // The database may be the thing that failed.
    }
    wa_done('Received, but could not be handled automatically.', false);
}
