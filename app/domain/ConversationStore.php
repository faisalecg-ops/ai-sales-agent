<?php
declare(strict_types=1);

/**
 * Conversation and message persistence (Sections 9, 31, 32).
 *
 * Messages are ordered by an explicit sequence number rather than by
 * timestamp, so two messages sent a moment apart keep their real order.
 */
final class ConversationStore
{
    /**
     * Load the conversation for this session, or start a new one.
     * A returning browser is linked to its previous lead, and that lead's
     * known fields are preloaded — without replaying any past messages.
     */
    public static function loadOrCreate(array $agent, array $input): array
    {
        $agentId   = (int)$agent['id'];
        $sessionId = Validator::opaqueId($input['session_id'] ?? null, 48);
        $visitorId = Validator::opaqueId($input['visitor_id'] ?? null);

        if ($sessionId !== null) {
            $conversation = db()->one(
                'SELECT * FROM conversations WHERE session_id = ? AND agent_id = ? LIMIT 1',
                [$sessionId, $agentId]
            );
            if ($conversation) {
                // The caller must present the secret issued when the session began.
                $secret = (string)($input['session_secret'] ?? '');
                if ($secret === '' || !hash_equals(
                    (string)$conversation['session_secret_hash'],
                    hash('sha256', $secret)
                )) {
                    app_log('session', 'session secret mismatch', ['agent' => $agentId]);
                    throw new RuntimeException('invalid_session');
                }
                db()->update('conversations', [
                    'last_activity_at' => gmdate('Y-m-d H:i:s'),
                ], 'id = ?', [(int)$conversation['id']]);
                return $conversation;
            }
        }

        throw new RuntimeException('unknown_session');
    }

    /**
     * Start a brand-new conversation. Returns the row plus the session
     * secret, which is handed to the widget once and never stored in clear.
     *
     * @return array{conversation:array,session_secret:string}
     */
    public static function start(array $agent, array $input): array
    {
        $agentId   = (int)$agent['id'];
        $visitorId = Validator::opaqueId($input['visitor_id'] ?? null);

        $sessionId = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $secret    = Crypto::token(24);

        $leadState = [];
        $leadId    = null;
        $returning = false;

        // Returning-visitor recognition (Section 9): fields only, no transcript.
        if ($visitorId !== null) {
            $priorLead = LeadManager::findByVisitorId($agentId, $visitorId);
            if ($priorLead) {
                $leadId    = (int)$priorLead['id'];
                $leadState = LeadManager::stateFromLead($priorLead);
                $returning = true;
            }
        }

        $conversationId = db()->insert('conversations', [
            'agent_id'   => $agentId,
            'channel'    => in_array(($input['channel'] ?? 'web'), ['web', 'whatsapp'], true)
                ? (string)($input['channel'] ?? 'web') : 'web',
            'lead_id'    => $leadId,
            'session_id' => $sessionId,
            'visitor_id' => $visitorId,
            'session_secret_hash' => hash('sha256', $secret),
            'state'      => ConversationState::NEW_CONVERSATION,
            'intent'     => $leadState['intent'] ?? 'unknown',
            'visitor_ip' => Security::clientIp(),
            'user_agent' => Security::userAgent(),
            'origin_domain' => $input['origin'] ?? null,
            'page_url'   => Validator::text($input['page_url'] ?? null, 500),
            'lead_state' => $leadState ? json_encode($leadState, JSON_UNESCAPED_UNICODE) : null,
            'prompt_version' => PromptBuilder::PROMPT_VERSION,
            'config_version' => (int)$agent['config_version'],
        ]);

        // A returning visitor whose company country is already known keeps
        // that classification, so they are not asked again (Section 12).
        if ($returning && !empty($leadState['company_country'])) {
            $resolved = GeoClassifier::resolveDisclosed((string)$leadState['company_country']);
            if ($resolved['code'] !== null) {
                db()->update('conversations', [
                    'pricing_country' => GeoClassifier::classifyCode($resolved['code']),
                    'country_source'  => GeoClassifier::SOURCE_DISCLOSED,
                    'disclosed_company_country' => (string)$resolved['name'],
                ], 'id = ?', [$conversationId]);
            }
        }

        $conversation = db()->one('SELECT * FROM conversations WHERE id = ?', [$conversationId]) ?? [];
        $conversation['_returning'] = $returning;

        Audit::log('conversation_started', $agentId, 'conversation', $conversationId,
            $returning ? 'Returning visitor, linked to existing lead' : 'New visitor');

        return ['conversation' => $conversation, 'session_secret' => $secret];
    }

    /**
     * Append a message with an explicit sequence number (Section 32).
     */
    public static function appendMessage(array $conversation, string $role, string $content, array $extra = []): int
    {
        $conversationId = (int)$conversation['id'];

        $next = (int)db()->value(
            'SELECT COALESCE(MAX(sequence_number), 0) + 1 FROM messages WHERE conversation_id = ?',
            [$conversationId]
        );

        $row = array_merge([
            'conversation_id' => $conversationId,
            'agent_id'        => (int)$conversation['agent_id'],
            'sequence_number' => $next,
            'role'            => $role,
            'content'         => mb_substr($content, 0, 16000),
        ], array_intersect_key($extra, array_flip([
            'rendered_cards', 'stage_before', 'stage_after', 'llm_raw_signals', 'model', 'provider',
            'input_tokens', 'output_tokens', 'latency_ms', 'request_id', 'response_status',
            'error_information', 'is_fallback',
        ])));

        // Retry once on a sequence collision from two near-simultaneous writes.
        try {
            db()->insert('messages', $row);
        } catch (Throwable $ex) {
            if (!str_contains($ex->getMessage(), 'Duplicate')) {
                throw $ex;
            }
            $row['sequence_number'] = $next + 1;
            db()->insert('messages', $row);
        }

        db()->exec(
            'UPDATE conversations SET message_count = message_count + 1, last_activity_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s'), $conversationId]
        );

        return (int)$row['sequence_number'];
    }

    /** Recent turns for the prompt, oldest first (Section 31). */
    public static function recentMessages(array $conversation, int $limit): array
    {
        $limit = max(2, min(40, $limit));
        $rows  = db()->all(
            "SELECT role, content FROM messages
             WHERE conversation_id = ? AND role IN ('visitor','agent')
             ORDER BY sequence_number DESC LIMIT " . $limit,
            [(int)$conversation['id']]
        );
        return array_reverse($rows);
    }

    public static function update(array $conversation, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $fields['last_activity_at'] = gmdate('Y-m-d H:i:s');
        db()->update('conversations', $fields, 'id = ?', [(int)$conversation['id']]);
    }

    public static function leadState(array $conversation): array
    {
        $decoded = json_decode((string)($conversation['lead_state'] ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Persist the outcome of an IP lookup (Sections 12, 13). */
    public static function applyGeoResult(array $conversation, array $geo): array
    {
        $update = [
            'ip_country_code' => $geo['country_code'],
            'ip_country_name' => $geo['country_name'],
            'ip_geo_status'   => $geo['status'],
            'ip_geo_failure'  => $geo['failure_reason'],
            'geo_checked_at'  => gmdate('Y-m-d H:i:s'),
        ];

        // A disclosed country already on file always wins (Section 12).
        if ((string)$conversation['country_source'] !== GeoClassifier::SOURCE_DISCLOSED) {
            $update['pricing_country'] = $geo['classification'];
            $update['country_source']  = $geo['source'];
        }

        db()->update('conversations', $update, 'id = ?', [(int)$conversation['id']]);
        return array_merge($conversation, $update);
    }

    /**
     * A factual conversation summary, built by PHP from state rather than by
     * asking the model to summarise. It costs nothing, cannot hallucinate,
     * and keeps the business-critical fields intact when history is trimmed
     * (Section 31).
     */
    public static function buildSummary(array $conversation, array $leadState, array $signals, array $turn): ?string
    {
        if ((int)$conversation['message_count'] < 8) {
            return $conversation['summary'] ?? null;
        }

        $parts = [];

        $who = [];
        foreach (['name' => 'Contact', 'company' => 'Company', 'industry' => 'Industry', 'product_category' => 'Products'] as $k => $label) {
            if (!empty($leadState[$k])) {
                $who[] = $label . ': ' . $leadState[$k];
            }
        }
        if ($who) {
            $parts[] = implode('. ', $who) . '.';
        }

        $country = $leadState['company_country'] ?? ($leadState['country'] ?? null);
        if ($country) {
            $parts[] = 'Company location: ' . $country . '.';
        }

        $active = ConversationState::activeSignalLabels($signals);
        if ($active) {
            $parts[] = 'So far they have: ' . strtolower(implode(', ', $active)) . '.';
        }

        if (!empty($conversation['pricing_shown']) || !empty($turn['cards'])) {
            $parts[] = 'Pricing has already been shared with them.';
        }
        if (!empty($leadState['requirements'])) {
            $parts[] = 'Requirements: ' . mb_substr((string)$leadState['requirements'], 0, 300);
        }

        return $parts ? mb_substr(implode(' ', $parts), 0, 2000) : null;
    }

    /** Full transcript for the admin panel. */
    public static function transcript(int $conversationId): array
    {
        return db()->all(
            'SELECT * FROM messages WHERE conversation_id = ? ORDER BY sequence_number ASC',
            [$conversationId]
        );
    }
}
