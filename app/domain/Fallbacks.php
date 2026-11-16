<?php
declare(strict_types=1);

/**
 * Approved fallback responses (Section 30).
 *
 * These are business-approved, stored per agent and editable in the admin
 * panel. They deliberately require no model call, because the point of a
 * fallback is that it works when the model does not.
 *
 * The defaults below are drafts awaiting Build Asia's sign-off.
 */
final class Fallbacks
{
    public const KEYS = [
        'llm_unavailable'     => 'AI assistant unavailable',
        'pricing_unavailable' => 'Pricing not available',
        'human_requested'     => 'Visitor asked for a person',
        'technical_problem'   => 'Technical problem',
        'rate_limited'        => 'Too many messages',
        'agent_unavailable'   => 'Agent offline (circuit breaker)',
        'no_matching_package' => 'No matching package',
        'pakistan_pricing'    => 'Pakistan pricing enquiry',
        'undetermined_pricing' => 'Country not established',
        'conversation_limit'  => 'Conversation length reached',
        'blocked_country'     => 'Blocked country: the only reply such a visitor gets',
        'off_topic'           => 'Outside what the agent can answer',
        'knowledge_gap'       => 'Information not held',
        'whatsapp_handover'   => 'WhatsApp: a person is taking over',
        'whatsapp_followup'   => 'WhatsApp: follow-up when the AI cannot write one — {name} and {exhibition} are filled in',
        'llm_busy'            => 'AI busy: shown while the chat waits to try again',
        'intro_visiting'      => 'Website chat: visitor chose "visiting" — {name} and {exhibition} are filled in',
    ];

    public const DEFAULTS = [
        // The reply to a website visitor who says they are coming to visit.
        // {name} and {exhibition} are replaced; everything else is as written.
        'intro_visiting' =>
            "Thank you, {name} — we look forward to welcoming you at {exhibition}. Is there anything else I can help you with?",

        // Sent once, on WhatsApp, when the assistant steps aside for a person.
        // No request for contact details: on WhatsApp we already have them.
        // Shown in the website chat while it waits for a busy AI; the message
        // is kept and answered as soon as the AI is free.
        'llm_busy' =>
            "Many people are asking right now — your answer is on its way.",

        // Used only when the assistant could not write a suitable follow-up.
        'whatsapp_followup' =>
            "Hello {name}, just following up from {exhibition}. If there is anything you would like to know, simply reply here and I will help.",

        'whatsapp_handover' =>
            "Thank you. I've passed this to a member of our team, who will reply to you here personally.",

        'llm_unavailable' =>
            "I'm not able to respond just now. Leave your name and email or phone number and a member of the team will follow up with you directly.",

        'pricing_unavailable' =>
            "I don't have published pricing I can share for that. If you leave your contact details, the team will send you the correct figures.",

        'human_requested' =>
            "Of course. Share your name and either an email address or phone number, and a member of the team will contact you.",

        'technical_problem' =>
            "Something went wrong at our end. Please try sending that again, or leave your contact details and the team will get back to you.",

        'rate_limited' =>
            "You're sending messages faster than I can process. Wait a moment and try again.",

        'agent_unavailable' =>
            "Our assistant is temporarily offline. Leave your name and email or phone number below and the team will follow up with you directly.",

        'no_matching_package' =>
            "I don't have a published package that matches that. The team can put together the right quotation for you — may I take your contact details?",

        'pakistan_pricing' =>
            "For companies based in Pakistan, pricing is handled directly by our sales team. If you share your name and email or phone number, they'll send you the current rates and answer any questions.",

        'undetermined_pricing' =>
            "So that I share the right pricing, could you tell me which country your company is based in?",

        'blocked_country' =>
            "Thank you for your message. We are sorry, but we are not able to assist with participation from your country.",

        'conversation_limit' =>
            "We've covered a lot here. So the team can pick this up properly, leave your name and email or phone number and someone will be in touch.",

        'off_topic' =>
            "That's outside what I can help with. I can tell you about exhibiting, stands, sponsorship and the event itself.",

        'knowledge_gap' =>
            "I don't have that information to hand, and I'd rather not guess. The team can confirm it for you — may I take your contact details?",
    ];

    /** Fetch an agent's approved text, falling back to the platform default. */
    public static function get(int $agentId, string $key): string
    {
        $row = db()->one(
            'SELECT body FROM fallback_messages WHERE agent_id = ? AND message_key = ? LIMIT 1',
            [$agentId, $key]
        );
        $body = $row ? trim((string)$row['body']) : '';
        if ($body !== '') {
            return $body;
        }
        return self::DEFAULTS[$key] ?? self::DEFAULTS['technical_problem'];
    }

    /** Seed an agent with the default set. */
    public static function seed(int $agentId): void
    {
        foreach (self::DEFAULTS as $key => $body) {
            db()->exec(
                'INSERT INTO fallback_messages (agent_id, message_key, body) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE message_key = message_key',
                [$agentId, $key, $body]
            );
        }
    }

    /** All messages for an agent, for the admin editor. */
    public static function all(int $agentId): array
    {
        $rows = db()->all(
            'SELECT message_key, body FROM fallback_messages WHERE agent_id = ?',
            [$agentId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['message_key']] = (string)$r['body'];
        }
        $out = [];
        foreach (self::KEYS as $key => $label) {
            $out[$key] = [
                'label'      => $label,
                'body'       => $map[$key] ?? (self::DEFAULTS[$key] ?? ''),
                'is_default' => !isset($map[$key]) || trim($map[$key]) === trim(self::DEFAULTS[$key] ?? ''),
            ];
        }
        return $out;
    }

    public static function save(int $agentId, string $key, string $body): void
    {
        if (!isset(self::KEYS[$key])) {
            return;
        }
        db()->exec(
            'INSERT INTO fallback_messages (agent_id, message_key, body) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE body = VALUES(body)',
            [$agentId, $key, mb_substr(trim($body), 0, 2000)]
        );
    }
}
