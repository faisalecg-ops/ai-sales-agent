<?php
declare(strict_types=1);

/**
 * POST /api/session.php
 *
 * Opens a conversation and returns the widget's opening state.
 * No geo lookup happens here (Section 11) and no model call is made.
 */

require __DIR__ . '/_api.php';

$ctx   = api_context();
$agent = $ctx['agent'];
$input = $ctx['input'];

$started = ConversationStore::start($agent, [
    'visitor_id' => $input['visitor_id'] ?? null,
    'origin'     => $ctx['origin'],
    'page_url'   => $input['page_url'] ?? null,
]);

$conversation = $started['conversation'];
$unavailable  = CircuitBreaker::isUnavailable($agent);

$greetingKey = $unavailable ? 'agent_unavailable' : null;
$blocks      = [];

if ($unavailable) {
    // Offline: static message and contact form, no model call at all (Section 39).
    $blocks = Sanitizer::toBlocks(
        Fallbacks::get((int)$agent['id'], $greetingKey),
        AgentResolver::approvedLinkDomains($agent)
    );
} else {
    $greeting = trim((string)Settings::get('agent.' . (int)$agent['id'] . '.greeting', ''));
    if ($greeting === '') {
        $greeting = 'Hello. I can help with exhibiting, sponsorship and general questions about '
                  . (string)$agent['name'] . '. What brings you here today?';
    }
    $blocks = Sanitizer::toBlocks($greeting, AgentResolver::approvedLinkDomains($agent));
}

// The short introduction card (first name, company, country, exhibiting or
// visiting). Offered unless the exhibition has switched it off, and never
// while the assistant is offline.
$introForm = !$unavailable && (int)($agent['chat_intro_form'] ?? 1) === 1;

api_respond([
    'ok'             => true,
    'intro_form'     => $introForm,
    'countries'      => $introForm ? ChatIntro::countryOptions() : [],
    'session_id'     => (string)$conversation['session_id'],
    'session_secret' => $started['session_secret'],
    'agent_name'     => (string)$agent['name'],
    'unavailable'    => $unavailable,
    'contact_form'   => $unavailable,
    'blocks'         => $blocks,
    'disclosure'     => (string)($agent['ai_disclosure_text'] ?: 'You are chatting with an AI assistant.'),
    'privacy_url'    => $agent['privacy_url'] ?: null,
    'quick_replies'  => $unavailable ? [] : [
        'I want to exhibit',
        'I am attending',
        'Ask a question',
    ],
], 200, $ctx['cors']);
