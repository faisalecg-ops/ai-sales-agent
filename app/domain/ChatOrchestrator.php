<?php
declare(strict_types=1);

/**
 * Per-turn orchestration for a visitor message.
 *
 * Order of work matters here, because the pricing gate must be resolved
 * before the model is given anything at all:
 *
 *   1  idempotency and abuse checks
 *   2  load or create the conversation (returning visitors preloaded)
 *   3  store the visitor message
 *   4  detect a stated location and a pricing request (PHP, not the model)
 *   5  run the geo gate only if pricing is in play and country is unknown
 *   6  decide pricing eligibility and gather approved facts
 *   7  retrieve knowledge, assemble the prompt, call the provider
 *   8  validate the structured output, apply lead fields, resolve packages
 *   9  verify no unapproved price appears in the reply
 *  10  transition state, persist, notify, return sanitised blocks
 *
 * Step 4 is deliberately PHP-side. The model's own pricing_request flag
 * arrives only with its reply, which is too late to decide what facts that
 * same reply was allowed to see.
 */
final class ChatOrchestrator
{
    /** Phrases that constitute an explicit pricing request. */
    private const PRICE_TERMS = [
        'price', 'pricing', 'prices', 'cost', 'costs', 'costing', 'rate', 'rates',
        'how much', 'quotation', 'quote', 'charges', 'fee', 'fees', 'budget',
        'per sqm', 'per square', 'package cost', 'tariff', 'expensive', 'afford',
    ];

    /** Patterns that mark an explicit statement of location. */
    private const LOCATION_PATTERNS = [
        '/\b(?:we(?:\'re| are)?|our (?:company|business|firm|office|factory|head ?office|hq)|i(?:\'m| am)?|my (?:company|business|firm))\s+(?:is\s+|are\s+)?(?:based|located|situated|registered|headquartered)\s+(?:in|at)\s+([A-Za-z][A-Za-z\s\.\-]{1,40})/i',
        '/\b(?:we(?:\'re| are)?|i(?:\'m| am)?)\s+(?:from|in)\s+([A-Za-z][A-Za-z\s\.\-]{1,40})/i',
        '/\b(?:company|business|office|factory|head ?office|hq)\s+(?:is\s+)?(?:in|at)\s+([A-Za-z][A-Za-z\s\.\-]{1,40})/i',
        '/\bbased\s+in\s+([A-Za-z][A-Za-z\s\.\-]{1,40})/i',
        '/\b([A-Za-z][A-Za-z\s\.\-]{1,30})[\-\s]based\s+(?:company|business|firm|manufacturer|supplier)/i',
        '/\bcoming\s+from\s+([A-Za-z][A-Za-z\s\.\-]{1,40})/i',
    ];

    /**
     * Handle one visitor turn.
     *
     * @return array the JSON-ready response payload
     */
    public static function handle(array $agent, array $input): array
    {
        $requestId = (string)$input['request_id'];

        // ---- 1. Idempotency (Section 32) --------------------------------
        $replay = self::replayIfSeen($requestId, (int)$agent['id']);
        if ($replay !== null) {
            return $replay;
        }

        try {
            $response = self::process($agent, $input);
        } catch (Throwable $ex) {
            app_log('chat', 'turn failed', [
                'agent' => (int)$agent['id'],
                'error' => $ex->getMessage(),
                'file'  => basename($ex->getFile()) . ':' . $ex->getLine(),
            ]);
            if (db()->inTransaction()) {
                db()->rollback();
            }
            $response = self::errorResponse($agent, 'technical_problem');
        }

        self::completeRequest($requestId, (int)$agent['id'], $response);
        return $response;
    }

    // -----------------------------------------------------------------
    // Main pipeline
    // -----------------------------------------------------------------
    private static function process(array $agent, array $input): array
    {
        $agentId = (int)$agent['id'];
        $message = (string)$input['message'];

        // ---- 2. Conversation --------------------------------------------
        $conversation = ConversationStore::loadOrCreate($agent, $input);

        if ((string)$conversation['status'] === 'blocked') {
            return self::staticResponse($agent, $conversation, 'rate_limited');
        }

        // Conversation length cap (Section 46)
        $maxMessages = max(4, (int)$agent['max_messages_per_conversation']);
        if ((int)$conversation['message_count'] >= $maxMessages) {
            return self::staticResponse($agent, $conversation, 'conversation_limit', ConversationState::HANDOFF);
        }

        // ---- Abuse checks (Section 47) ----------------------------------
        $abuse = RateLimiter::abuseReason($message, $conversation);
        if ($abuse !== null) {
            app_log('abuse', 'message rejected', ['agent' => $agentId, 'reason' => $abuse]);
            if ($abuse === 'duplicate_flooding') {
                db()->update('conversations', ['status' => 'blocked'], 'id = ?', [(int)$conversation['id']]);
                Audit::log('conversation_blocked', $agentId, 'conversation', (int)$conversation['id'], $abuse);
            }
            return self::staticResponse($agent, $conversation, 'rate_limited');
        }

        $leadState = ConversationStore::leadState($conversation);

        // ---- 3. Store the visitor's message ------------------------------
        // Trying again after a busy AI: the message is already stored and
        // still unanswered, so it is answered rather than stored twice.
        $sequence = !empty($input['retry']) ? self::unansweredVisitorMessage($conversation, $message) : null;
        $sequence ??= ConversationStore::appendMessage($conversation, 'visitor', $message, [
            'request_id'  => (string)$input['request_id'],
            'stage_before' => (string)$conversation['state'],
        ]);

        // ---- 4. Location statement and pricing request (PHP-side) --------
        $stated = self::extractStatedLocation($message);
        if ($stated['code'] !== null || $stated['ambiguous']) {
            $conversation = self::applyDisclosedLocation($agent, $conversation, $stated, 'message_scan');
        }

        // ---- Blocked countries (owner, 12 Nov 2026) ----------------------
        // By what they said or chose, never the IP address. One fixed reply:
        // no AI, no research, no prices, no lead, nothing sent to the team.
        if (BlockedCountries::blocksConversation($conversation)) {
            if (empty($leadState['blocked_country'])) {
                $leadState['blocked_country'] = true;
                ConversationStore::update($conversation, ['lead_state' => json_encode($leadState, JSON_UNESCAPED_UNICODE)]);
                Audit::log('conversation_blocked_country', $agentId, 'conversation', (int)$conversation['id'],
                    (string)($conversation['disclosed_company_country'] ?: $conversation['disclosed_country']));
            }
            return self::staticResponse($agent, $conversation, 'blocked_country');
        }

        $pricingRequested = self::detectPricingRequest($message, (string)$conversation['state']);

        // ---- Fix plan (11 Nov 2026): what was named, memory, contact rule --
        $turnNo    = (int)($leadState['visitor_turns'] ?? 0) + 1;
        $offers    = PricingEngine::catalogueForPrompt($agentId);
        $offerQ    = ChatGuide::offerQuestion($message, $offers);
        // Follow-up words alone ("benefits", "which one") count only for a
        // possible exhibitor; a visitor must name a stand, a tier or
        // sponsorship before the offer is put in view.
        $offerTalk = !empty($leadState['offer_discussed']) || $pricingRequested
            || $offerQ['items'] !== [] || $offerQ['categories'] !== []
            || self::detectCatalogueRequest($message, false)
            || (in_array((string)$conversation['intent'], ['exhibitor', 'sponsor', 'partner'], true)
                && self::detectCatalogueRequest($message));
        $offerFocus = $offerQ['items'] ?: (array)($leadState['offer_focus'] ?? []);
        // A quotation in writing (plan part 3). PHP decides whether one is
        // wanted; it stays wanted until it is sent, so an email address given
        // two messages later still sends it.
        $quoteOn     = (string)($conversation['channel'] ?? 'web') !== 'whatsapp' && Quotation::available($agent);
        $quoteAsked  = $quoteOn && (Quotation::wants($message)
            || (!empty($leadState['quote_offered']) && Quotation::saysYes($message)));
        if ($quoteAsked) {
            unset($leadState['quote_declined']);        // they have asked again themselves
        }
        $quoteWanted = $quoteAsked || ($quoteOn && !empty($leadState['quote_wanted']));
        // They would rather not give their details: the request is dropped,
        // never pressed. They are pointed to the booking page or the rep, as
        // for any visitor who declines (test chat 5, 12 Nov 2026).
        $quoteDropped = false;
        if ($quoteWanted && !$quoteAsked && ChatGuide::isDecline($message)) {
            $quoteWanted  = false;
            $quoteDropped = true;
        }

        $contactRecord = ChatGuide::noteVisitorAnswer(ChatGuide::contactRecord($leadState), $message);
        $contactRule   = ChatGuide::mayAsk(
            $contactRecord,
            $turnNo,
            self::hasContactInState($leadState),
            self::asksForUs($message) || $quoteWanted
        );

        // ---- 5. Geo gate (Sections 11, 13) --------------------------------
        $geoRan = false;
        $needsLocationClarification = $stated['ambiguous'];

        $intentSoFar = (string)$conversation['intent'];
        $mayGeo = ConversationState::mayTriggerGeo((string)$conversation['state'], $intentSoFar, $pricingRequested);

        if ($mayGeo && !GeoClassifier::isKnown($conversation) && !$needsLocationClarification) {
            $geo = GeoClassifier::lookupByIp($agent, $conversation, (string)$conversation['visitor_ip']);
            $geoRan = true;
            $conversation = ConversationStore::applyGeoResult($conversation, $geo);
        }

        // ---- 6. Pricing eligibility and approved facts --------------------
        $classification = (string)$conversation['pricing_country'];
        $eligibility    = PricingEngine::eligibility($agent, $classification);
        $pricingAllowed = $eligibility['allowed'];

        // A size the visitor stated in their own words. PHP reads it and PHP
        // does the multiplication; the model is never asked to calculate.
        $requestedSqm = PricingEngine::requestedSize($message);
        if ($requestedSqm === null && !empty($conversation['requested_sqm'])) {
            $requestedSqm = (float)$conversation['requested_sqm'];
        }

        // Once pricing is on the table it stays on the table: a later
        // question about a different size still needs the approved facts.
        $pricingInPlay = $pricingRequested
            || (int)($conversation['pricing_shown'] ?? 0) === 1
            || !empty($leadState['prices_given'])
            || in_array((string)$conversation['state'], [
                ConversationState::PRICING,
                ConversationState::SALES_READINESS,
                ConversationState::OBJECTION_HANDLING,
            ], true);

        $facts   = [];
        $records = [];
        $allowedFigures = [];
        if ($pricingAllowed && $pricingInPlay) {
            $stalls = PricingEngine::stallPackages($agentId, $eligibility['region_groups']);
            $spons  = PricingEngine::sponsorshipPackages($agentId, $eligibility['region_groups']);
            $records = ['stall' => $stalls, 'sponsorship' => $spons];
            $facts = array_merge(
                PricingEngine::factsForPrompt($stalls, PricingEngine::TYPE_STALL, $requestedSqm),
                PricingEngine::factsForPrompt($spons, PricingEngine::TYPE_SPONSORSHIP, $requestedSqm)
            );
            $allowedFigures = array_merge(
                PricingEngine::allowedFigures($stalls, PricingEngine::TYPE_STALL, $requestedSqm),
                PricingEngine::allowedFigures($spons, PricingEngine::TYPE_SPONSORSHIP, $requestedSqm)
            );
            if (!$facts) {
                $pricingAllowed = false;
            }
        }

        // What is on offer, without prices. Once stands or sponsorship have
        // come up in this chat, it stays in view on every later turn, so a
        // follow-up such as "what do I get with Silver?" is answered from it
        // (fix plan step 2). Not needed when price facts, which carry the
        // same details, were prepared for this turn.
        $catalogue = [];
        if (!$facts && $offerTalk) {
            $catalogue = $offers;
        }

        // ---- Circuit breaker (Section 45) ---------------------------------
        if (CircuitBreaker::shouldSkipModel($agent)) {
            return self::staticResponse($agent, $conversation, 'agent_unavailable', null, true);
        }

        // ---- Daily budget (Section 46) ------------------------------------
        if (!RateLimiter::withinDailyBudget($agent)) {
            app_log('budget', 'daily budget reached', ['agent' => $agentId]);
            return self::staticResponse($agent, $conversation, 'llm_unavailable', null, true);
        }

        // ---- 7. Knowledge, prompt, provider -------------------------------
        $knowledge = KnowledgeRetriever::retrieve(
            $agentId,
            $message,
            (string)$conversation['state'],
            4,
            3
        );

        $recent = ConversationStore::recentMessages($conversation, (int)$agent['max_recent_messages']);

        // ---- Guided selling (plan approved 12 Nov 2026) --------------------
        // For a possible exhibitor: the qualification record, and the step
        // of the sales path PHP has worked out from it. Visitors are not put
        // on the path.
        $onPath   = self::qualificationReady()
            && in_array((string)$conversation['intent'], ['exhibitor', 'sponsor', 'partner'], true);
        $qual     = is_array($leadState['qual'] ?? null) ? $leadState['qual'] : [];
        $pathFlags = is_array($leadState['path'] ?? null) ? $leadState['path'] : [];
        $knownProducts = self::knownProducts($conversation, $leadState);
        $pathStep = null;
        $salesPath = '';
        if ($onPath) {
            $qual = Qualification::withResearch($qual, self::researchProducts($conversation));
            if (Qualification::asksNextStep($message)) {
                $pathFlags['next_step'] = true;
            }
            $pathStep  = Qualification::step($qual, $pathFlags);
            // A quotation is not offered to someone who has declined to give their details.
            $salesPath = Qualification::promptBlock($qual, $pathStep, $classification === GeoClassifier::INTERNATIONAL,
                $quoteOn && !$quoteDropped && empty($leadState['quote_declined']) && empty($contactRecord['stopped']));
        }
        // What the exhibition's website says about what they asked (part 5).
        $sitePages = SitePages::match($agentId, $message, 3);

        // What a quotation still needs, from what is known before this reply.
        $quoteNames  = $offerQ['items'] ?: $offerFocus;
        $quoteAssess = null;
        if ($quoteWanted) {
            $known = !empty($conversation['lead_id'])
                ? (db()->one('SELECT name, company, email FROM leads WHERE id = ?', [(int)$conversation['lead_id']]) ?? [])
                : [];
            foreach (['name', 'company', 'email'] as $field) {
                if (trim((string)($known[$field] ?? '')) === '' && !empty($leadState[$field])) {
                    $known[$field] = (string)$leadState[$field];
                }
            }
            // An address typed in this very message counts.
            if (trim((string)($known['email'] ?? '')) === '' && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $message, $em)) {
                $known['email'] = $em[0];
            }
            $quoteAssess = Quotation::assess($agent, $conversation, $known, $quoteNames,
                $quoteNames === [] || count($quoteNames) > 1 ? ($leadState['quote_last_ref'] ?? null) : null, $requestedSqm);
        }

        // A quotation already sent in this chat: the assistant knows, and
        // does not promise a second "proposal" for the same thing.
        // Offering a quotation (owner, 13 Nov 2026: "it does not offer the
        // quotation on any stage"). The program offers it, not the AI: after
        // a price is shown, or when they ask how to go ahead. Twice at most in
        // a chat, never after they declined, never for Pakistan or an unknown
        // country, and not once three have been sent.
        $quoteCanOffer = $quoteOn && !$quoteWanted && !$quoteDropped
            && empty($leadState['quote_declined']) && empty($contactRecord['stopped'])
            && $classification === GeoClassifier::INTERNATIONAL && $pricingAllowed
            && (string)($conversation['country_source'] ?? '') === GeoClassifier::SOURCE_DISCLOSED
            && count((array)($leadState['quotes_sent'] ?? [])) < Quotation::MAX_PER_CONVERSATION
            && (int)($leadState['quote_offers'] ?? 0) < 2;
        $quoteNextStep = Qualification::asksNextStep($message);
        $quoteOfferDue = $quoteCanOffer && ($pricingRequested || $quoteNextStep);

        $quoteNote = '';
        if ($quoteAssess !== null) {
            $quoteNote = Quotation::promptBlock($quoteAssess);
        } elseif ($quoteDropped) {
            $quoteNote = "QUOTATION\nThey asked for a quotation earlier but do not want to give the details it needs. Accept that in a few "
                . "words and do not ask again. A quotation cannot be emailed without an address; they can go ahead through the Book "
                . "a stand page or the sales representative whenever they wish. Keep helping with what they ask.";
        } elseif ($quoteOfferDue) {
            $quoteNote = "QUOTATION OFFER\nThe system can email them an official quotation (a PDF from the price list) for one option. "
                . "End this reply by offering it, as your only question, in these words or close to them: "
                . "\"Would you like me to email you a quotation for this?\" Do not write the quotation's figures yourself.";
        } elseif ($quoteOn && !empty($leadState['quotes_sent'])) {
            $quoteNote = "QUOTATION ALREADY SENT\nQuotation " . implode(', ', array_map('strval', (array)$leadState['quotes_sent']))
                . " has been emailed to them from this chat. It is an offer: a stand is booked only after the signed contract and "
                . "payment, and an invoice with payment details follows their confirmation. Do not promise another proposal for the "
                . "same thing and do not ask them to confirm their email address again. To go ahead, they reply to that email or "
                . "contact the sales representative.";
        }

        $promptCtx = [
            'quotation'         => $quoteNote,
            'quotation_on'      => $quoteOn,
            'sales_path'        => $salesPath,
            'site_pages'        => $sitePages,
            'agent'             => $agent,
            'message'           => $message,
            'state'             => (string)$conversation['state'],
            'intent'            => (string)$conversation['intent'],
            'knowledge'         => $knowledge,
            'pricing_facts'     => $facts,
            'catalogue'         => $catalogue,
            'pricing_allowed'   => $pricingAllowed,
            'pricing_requested' => $pricingRequested,
            'pricing_country'   => $classification,
            'requested_sqm'     => $requestedSqm,
            'lead_state'        => $leadState,
            'recent_messages'   => $recent,
            'summary'           => (string)($conversation['summary'] ?? ''),
            'contact_captured'  => self::hasContactInState($leadState),
            'message_count'     => (int)$conversation['message_count'],
            'returning_visitor' => !empty($conversation['_returning']),
            'needs_location_clarification' => $needsLocationClarification,
            'handoff_expected'  => (string)$conversation['state'] === ConversationState::HANDOFF,
            // What research found about their company, when it has run.
            'research'          => self::researchFor($conversation),
            'known_products'    => self::knownProducts($conversation, $leadState),
            'offer_question'    => $offerQ,
            'offer_focus'       => (array)($leadState['offer_focus'] ?? []),
            'contact_rule'      => $contactRule,
            'exhibitor_case'    => self::exhibitorCase($agent, $conversation, $leadState),
        ];

        if ($requestedSqm !== null && (float)($conversation['requested_sqm'] ?? 0) !== $requestedSqm) {
            db()->update('conversations', ['requested_sqm' => $requestedSqm], 'id = ?', [(int)$conversation['id']]);
            $conversation['requested_sqm'] = $requestedSqm;
        }

        $prompt = PromptBuilder::build($promptCtx);
        $llm    = self::callProvider($agent, $prompt, $conversation);

        // ---- 8. Validate the structured output ----------------------------
        if (!$llm['ok']) {
            return self::modelFailureResponse($agent, $conversation, $llm, $sequence);
        }

        $parsed = LlmContract::parse($llm['text']);
        if (!$parsed['ok']) {
            app_log('llm', 'contract validation failed', [
                'agent'  => $agentId,
                'reason' => $parsed['reason'],
            ]);
            // One retry with a tightened instruction before falling back.
            $retry = self::retryForValidJson($agent, $prompt, $conversation);
            if ($retry !== null) {
                $llm    = $retry['llm'];
                $parsed = $retry['parsed'];
            }
        }

        if (!$parsed['ok']) {
            return self::modelFailureResponse(
                $agent,
                $conversation,
                ['status' => 'bad_response', 'error' => $parsed['reason'], 'provider' => (string)$agent['llm_provider'], 'model' => $llm['model'] ?? '', 'latency_ms' => $llm['latency_ms'] ?? 0, 'input_tokens' => 0, 'output_tokens' => 0],
                $sequence
            );
        }

        $signals = $parsed['data'];

        // ---- Lead fields and location the model extracted -----------------
        $lead = null;
        $leadFields = $signals['lead_fields'];

        // The model reports what was said; PHP decides what country that means.
        $modelLocation = self::resolveModelLocation($leadFields, $signals);
        if ($modelLocation['code'] !== null || $modelLocation['ambiguous']) {
            $conversation = self::applyDisclosedLocation($agent, $conversation, $modelLocation, 'model_extraction');
            $classification = (string)$conversation['pricing_country'];
            if ($modelLocation['ambiguous']) {
                $needsLocationClarification = true;
            }
        }

        // ---- 9. Package reference and price verification (Section 20) -----
        $cards = [];
        $reference = $signals['package_reference'];
        if ($reference !== null && $pricingAllowed) {
            $record = PricingEngine::resolveReference($reference, $agentId, $eligibility['region_groups']);
            if ($record !== null) {
                // Quote at the visitor's size only when that size can be sold.
                // When it cannot, no card at all: a card at another size would
                // put a price nobody asked for under "the nearest sizes are…"
                // (test chat 12, 12 Nov 2026).
                $cardSize = null;
                $sellable = true;
                if ((string)($record['pricing_mode'] ?? 'fixed') === 'per_sqm' && $requestedSqm !== null) {
                    $check = PricingEngine::checkSize($record, $requestedSqm);
                    $cardSize = $check['ok'] ? $requestedSqm : null;
                    $sellable = $check['ok'];
                }
                // A price card only when price is being discussed: asked now,
                // or given before. "What is included?" gets the list, not a
                // price (owner: prices only when asked; test chats 5 and 15).
                $priceWanted = $pricingRequested || !empty($leadState['prices_given'])
                    || (int)($conversation['pricing_shown'] ?? 0) === 1;
                if ($sellable && $priceWanted) {
                    $cards[] = PricingEngine::toCard($record, (string)$record['_type'], $cardSize);
                }
            } else {
                app_log('pricing', 'package_reference did not resolve', [
                    'agent' => $agentId, 'reference' => $reference,
                ]);
            }
        }

        $reply = SitePages::onlyKnownLinks($agent, (string)$signals['reply'], [
            (string)($agent['book_stand_url'] ?? ''), (string)($agent['register_url'] ?? ''),
            (string)($agent['website_url'] ?? ''),
        ]);
        // A price asked for one named option is always answered with its
        // price line (real-AI test chat 11, 12 Nov 2026: on the sales path
        // the model once listed what a stall includes and moved on to its
        // next question). The line and the card come from the price record.
        if ($pricingRequested && $pricingAllowed && count($offerQ['items']) === 1
            && !preg_match('/(?:\b[A-Z]{3}|[$€£]|Rs\.?)\s?\d/u', $reply)) {
            foreach ($records as $type => $list) {
                foreach ($list as $rec) {
                    $recName = (string)($rec[$type === 'stall' ? 'package_name' : 'tier_name'] ?? '');
                    if (strcasecmp(trim($recName), trim((string)$offerQ['items'][0])) !== 0) {
                        continue;
                    }
                    $perSqm = (string)($rec['pricing_mode'] ?? 'fixed') === 'per_sqm';
                    if ($perSqm && $requestedSqm !== null && !PricingEngine::checkSize($rec, $requestedSqm)['ok']) {
                        break 2;                    // that size cannot be booked: no figure
                    }
                    $card  = PricingEngine::toCard($rec, $type, $perSqm ? $requestedSqm : null);
                    $reply = ChatGuide::quoteLine($card) . "\n\n" . ltrim($reply);
                    if (!$cards) {
                        $cards[] = $card;
                    }
                    break 2;
                }
            }
        }

        $priceViolation = null;

        if (!$pricingAllowed) {
            // No approved facts were supplied, so no monetary figure is legitimate.
            $priceViolation = PricingEngine::findUnauthorisedPrice($reply, []);
        } else {
            $priceViolation = PricingEngine::findUnauthorisedPrice($reply, $allowedFigures);
        }

        if ($priceViolation !== null) {
            app_log('pricing', 'reply contained an unapproved figure', [
                'agent' => $agentId, 'found' => $priceViolation, 'allowed' => $pricingAllowed,
            ]);
            Audit::log('pricing_blocked', $agentId, 'conversation', (int)$conversation['id'],
                'Reply suppressed: unapproved figure "' . $priceViolation . '"');

            // The reply is discarded rather than patched — PHP cannot reliably
            // rewrite a price out of free text (Section 20).
            $reply = Fallbacks::get($agentId, $pricingAllowed ? 'pricing_unavailable' : self::pricingFallbackKey($classification));
            $cards = [];
        }

        // ---- 10. State, persistence, notifications ------------------------
        return self::finalise($agent, $conversation, [
            'reply'             => $reply,
            'signals'           => $signals,
            'lead_fields'       => $leadFields,
            'cards'             => $cards,
            'llm'               => $llm,
            'pricing_requested' => $pricingRequested || $signals['pricing_request'],
            'pricing_allowed'   => $pricingAllowed,
            'classification'    => $classification,
            'message'           => $message,
            'geo_ran'           => $geoRan,
            'needs_location_clarification' => $needsLocationClarification,
            'is_fallback'       => $priceViolation !== null,
            // Details already known from somewhere other than this chat —
            // a WhatsApp registration. Used only to fill blanks on the lead.
            'known_contact'     => is_array($input['known_contact'] ?? null) ? $input['known_contact'] : [],
            // What the next turn needs to remember (fix plan steps 1 and 2).
            'memory'            => [
                'quote'         => ['on' => $quoteOn, 'wanted' => $quoteWanted, 'asked' => $quoteAsked, 'dropped' => $quoteDropped,
                                    'can_offer' => $quoteCanOffer, 'next_step' => $quoteNextStep,
                                    'names' => $quoteNames, 'sqm' => $requestedSqm,
                                    // This turn's option, or the one whose price card was shown last.
                                    'reference' => $pricingAllowed
                                        ? ($reference ?? ($quoteNames === [] || count($quoteNames) > 1 ? ($leadState['quote_last_ref'] ?? null) : null))
                                        : null],
                'on_path'       => $onPath,
                'qual'          => $qual,
                'qual_update'   => $onPath ? Qualification::supported(
                    Qualification::fromModel($signals['qualification'] ?? null),
                    self::visitorWords($conversation, $message),
                    implode(' ', self::researchProducts($conversation))
                ) : [],
                'path'          => $pathFlags,
                'path_step'     => $pathStep,
                'turn'          => $turnNo,
                'contact'       => $contactRecord,
                'offers'        => $offers,
                'offer_talk'    => $offerTalk,
                'offer_items'   => $offerQ['items'],
                'offer_focus'   => $offerFocus,
                'prices_given'  => $pricingAllowed && $facts && $priceViolation === null
                    && (bool)preg_match('/(?:USD|\$)\s?\d/i', (string)$reply),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Finalisation
    // -----------------------------------------------------------------
    private static function finalise(array $agent, array $conversation, array $turn): array
    {
        $agentId = (int)$agent['id'];
        $signals = $turn['signals'];

        db()->begin();
        try {
            // Lead: created once there is something worth following up.
            $lead = null;
            $hasFields = !empty(array_filter(
                $turn['lead_fields'],
                static fn($k) => in_array($k, LeadManager::TRACKED_FIELDS, true),
                ARRAY_FILTER_USE_KEY
            ));
            $quote = (array)($turn['memory']['quote'] ?? []);
            $needsLead = $hasFields
                || $signals['handoff_required']
                || $turn['pricing_requested']
                || !empty($quote['wanted'])
                || !empty($conversation['lead_id']);

            if ($needsLead) {
                $lead = LeadManager::ensureLead($agent, $conversation);
                $applied = LeadManager::applyFields($agent, $lead, $turn['lead_fields']);

                // What we already knew fills only what is still empty, so it
                // never overrides something the person has just told us.
                $known = [];
                foreach ((array)($turn['known_contact'] ?? []) as $field => $value) {
                    if (in_array($field, LeadManager::TRACKED_FIELDS, true)
                        && trim((string)$value) !== ''
                        && trim((string)($lead[$field] ?? '')) === ''
                        && !array_key_exists($field, $turn['lead_fields'])) {
                        $known[$field] = (string)$value;
                    }
                }
                if ($known && LeadManager::applyFields($agent, $lead, $known, 'registration')) {
                    $applied = true;
                }
                if ($applied) {
                    $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
                }
                LeadManager::syncGeo($lead, $conversation);
            }

            // Conversation lead-state mirror, so the next turn never re-asks.
            $leadState = ConversationStore::leadState($conversation);
            foreach ($turn['lead_fields'] as $k => $v) {
                if (in_array($k, LeadManager::TRACKED_FIELDS, true)) {
                    $leadState[$k] = $v;
                }
            }
            if (!empty($conversation['disclosed_country'])) {
                $leadState['country'] = (string)$conversation['disclosed_country'];
            }
            if (!empty($conversation['disclosed_company_country'])) {
                $leadState['company_country'] = (string)$conversation['disclosed_company_country'];
            }
            $leadState = self::remember($leadState, (array)($turn['memory'] ?? []), (string)$turn['reply']);
            $qualified = self::qualify($agent, $leadState, (array)($turn['memory'] ?? []), (string)$turn['reply'],
                $lead !== null ? (int)$lead['id'] : (!empty($conversation['lead_id']) ? (int)$conversation['lead_id'] : null));
            $leadState = $qualified['lead_state'];

            $contactCaptured = $lead !== null && LeadManager::hasContact($lead);

            // Readiness signals (Section 30) — PHP computed.
            $stored  = json_decode((string)($conversation['readiness_signals'] ?? '{}'), true) ?: [];
            $signalsMap = ConversationState::computeSignals($stored, $leadState, (string)$turn['message'], $signals);
            $salesReady = ConversationState::isSalesReady($agent, $signalsMap);

            // Handoff triggers (Section 33, 34).
            $handoffReason = null;
            if ($signals['handoff_required']) {
                $handoffReason = $signals['handoff_reason'] ?? 'Requested during conversation';
            } elseif ($turn['cards']) {
                $handoffReason = 'Interested in ' . (string)$turn['cards'][0]['title'];
            } elseif ($turn['pricing_requested'] && !$turn['pricing_allowed']) {
                $handoffReason = $turn['classification'] === GeoClassifier::PAKISTAN
                    ? 'Pakistan pricing required'
                    : 'Pricing not available for this enquiry';
            }

            // State transition (Section 6) — PHP decides.
            $nextState = ConversationState::next((string)$conversation['state'], [
                'intent'            => $signals['intent'] ?? (string)$conversation['intent'],
                'pricing_requested' => $turn['pricing_requested'],
                'country_known'     => GeoClassifier::isKnown($conversation) && !$turn['needs_location_clarification'],
                'pricing_allowed'   => $turn['pricing_allowed'],
                'pricing_shown'     => !empty($turn['cards']),
                'handoff'           => $handoffReason !== null,
                'sales_ready'       => $salesReady,
                'contact_captured'  => $contactCaptured,
                'suggested'         => $signals['stage_signal'],
            ]);

            // Lead pipeline (Section 8).
            if ($lead !== null) {
                if ($contactCaptured) {
                    LeadManager::advanceStatus($agent, $lead, 'contact_captured');
                }
                if ($salesReady && $contactCaptured) {
                    LeadManager::advanceStatus($agent, $lead, 'qualified');
                }
                if ($turn['cards']) {
                    LeadManager::markPricingDiscussed($agent, $lead);
                    LeadManager::advanceStatus($agent, $lead, 'pricing_discussed');
                    foreach ($turn['cards'] as $card) {
                        LeadManager::addPackageInterest($agent, $lead, $card);
                    }
                }
                if ($handoffReason !== null) {
                    LeadManager::markHandoff($agent, $lead, $handoffReason);
                }
                LeadManager::refreshDedupFlag($agent, $lead);
            }

            // A person is needed — asked for, a discount, a complaint, a
            // contract. On the website the conversation carries on, and the
            // right sales rep's details are added so the visitor can reach them
            // directly. Added after the price guard has run on the model's
            // words, and taken from the approved rep records, never from the
            // model. WhatsApp does this itself when it hands over.
            // A company in Pakistan asking for prices, a quotation or the next
            // step is given the local representative's details (owner,
            // 12 Nov 2026): the chat itself never quotes Pakistan.
            // Only for a possible exhibitor: a visitor asking whether
            // registration is free is not sent to the sales rep.
            $pakistanAsk = (string)($conversation['pricing_country'] ?? '') === GeoClassifier::PAKISTAN
                && in_array((string)($signals['intent'] ?? $conversation['intent']), ['exhibitor', 'sponsor', 'partner'], true)
                && ($turn['pricing_requested'] || Qualification::asksNextStep((string)$turn['message']));
            // When the chat sends quotations itself, the word "quotation" alone
            // no longer needs a person: the visitor is getting what they asked.
            $personReason = PersonRequest::detect((string)$turn['message']);
            if ($personReason === 'A contract, payment or discount question' && !empty($quote['on']) && !empty($quote['wanted'])
                && (string)($conversation['pricing_country'] ?? '') === GeoClassifier::INTERNATIONAL
                && PersonRequest::detect((string)preg_replace('/\b(?:quotation|pro-?\s?forma)\b/i', ' ', (string)$turn['message'])) === null) {
                $personReason = null;
            }
            if ((string)($conversation['channel'] ?? 'web') !== 'whatsapp'
                && ($personReason !== null || $pakistanAsk)) {
                $repText = SalesReps::textFor($agentId, (string)($conversation['pricing_country'] ?? ''), 'web');
                if ($repText !== '') {
                    $turn['reply'] = rtrim((string)$turn['reply']) . "\n\n" . $repText;
                    $leadState['rep_given'] = true;
                }
            }

            // ---- The quotation (plan part 3) --------------------------------
            // Prepared here, inside the same save as the reply that announces
            // it; emailed after the save, so a slow mail server holds nothing up.
            $quoteRow = null;
            if (!empty($quote['on'])) {
                $leadState['quote_offered'] = !$turn['is_fallback'] && Quotation::offered((string)$turn['reply']);
                // The offer is certain: if a price was shown (or they asked how
                // to go ahead) and the reply does not offer a quotation, the
                // program adds one line. "Yes" to it then sends the quotation.
                if (!empty($quote['can_offer']) && !$turn['is_fallback'] && empty($quote['wanted'])
                    && ($turn['cards'] || !empty($quote['next_step']))) {
                    if (!$leadState['quote_offered'] && !preg_match('/\bquot(?:ation|e)\b/i', (string)$turn['reply'])) {
                        $turn['reply'] = rtrim((string)$turn['reply']) . "\n\n" . 'I can also email you an official quotation for this. Just reply "yes".';
                    }
                    $leadState['quote_offered'] = true;
                    $leadState['quote_offers']  = (int)($leadState['quote_offers'] ?? 0) + 1;
                }
                if ($turn['cards'] && !empty($turn['cards'][0]['reference'])) {
                    $leadState['quote_last_ref'] = (string)$turn['cards'][0]['reference'];
                }
                if (!empty($quote['asked'])) {
                    unset($leadState['quote_declined']);
                }
                if (!empty($quote['dropped'])) {
                    $leadState['quote_declined'] = true;
                    $leadState['quote_wanted'] = false;
                    $leadState['quote_waits']  = 0;
                }
                if (!empty($quote['wanted']) && $lead !== null && !$turn['is_fallback']) {
                    $assess = Quotation::assess($agent, $conversation, $lead, (array)($quote['names'] ?? []),
                        $quote['reference'] ?? null, isset($quote['sqm']) ? (float)$quote['sqm'] : null);
                    if ($assess['missing'] === null) {
                        try {
                            $quoteRow = Quotation::prepare($agent, $lead, $conversation, $assess['record'], $assess['sqm']);
                            $turn['reply'] = rtrim((string)$turn['reply']) . "\n\n" . 'Quotation ' . $quoteRow['number']
                                . ' is on its way to ' . $quoteRow['to_email'] . '. It is valid until '
                                . (string)((array)json_decode((string)$quoteRow['detail'], true))['valid'] . '.';
                            $leadState['quote_wanted'] = false;
                            $leadState['quote_waits']  = 0;
                            $leadState['quote_offered'] = false;
                            $leadState['quotes_sent'] = array_slice(array_merge((array)($leadState['quotes_sent'] ?? []), [$quoteRow['number']]), -5);
                        } catch (Throwable $ex) {
                            app_log('quotation', 'could not prepare', ['error' => $ex->getMessage(), 'conversation' => (int)$conversation['id']]);
                            $leadState['quote_wanted'] = false;
                            $handoffReason ??= 'Asked for a quotation; it could not be made automatically';
                        }
                    } elseif (in_array($assess['missing'], ['pakistan', 'limit'], true)) {
                        $leadState['quote_wanted'] = false;
                        if ($assess['missing'] === 'limit' && empty($leadState['rep_given'])) {
                            $repText = SalesReps::textFor($agentId, (string)($conversation['pricing_country'] ?? ''), 'web');
                            if ($repText !== '') {
                                $turn['reply'] = rtrim((string)$turn['reply']) . "\n\n" . $repText;
                                $leadState['rep_given'] = true;
                            }
                        }
                    } else {
                        // Waits for the missing detail, but not for ever: asked
                        // for in two replies and still missing, it is let go, so
                        // the chat never turns into the same question repeated.
                        $waits = !empty($quote['asked']) ? 1 : (int)($leadState['quote_waits'] ?? 0) + 1;
                        $leadState['quote_waits']  = $waits;
                        $leadState['quote_wanted'] = $waits <= 2;
                    }
                }
            }

            // Sanitised reply (Section 29).
            $approvedDomains = AgentResolver::approvedLinkDomains($agent);
            $blocks = Sanitizer::toBlocks((string)$turn['reply'], $approvedDomains);
            $plain  = Sanitizer::toPlainText((string)$turn['reply']);

            ConversationStore::appendMessage($conversation, 'agent', $plain, [
                'rendered_cards' => $turn['cards'] ? json_encode($turn['cards'], JSON_UNESCAPED_UNICODE) : null,
                'stage_before'   => (string)$conversation['state'],
                'stage_after'    => $nextState,
                'llm_raw_signals' => json_encode(array_diff_key($signals, ['reply' => 1]), JSON_UNESCAPED_UNICODE),
                'model'          => (string)($turn['llm']['model'] ?? ''),
                'provider'       => (string)$agent['llm_provider'],
                'input_tokens'   => (int)($turn['llm']['input_tokens'] ?? 0),
                'output_tokens'  => (int)($turn['llm']['output_tokens'] ?? 0),
                'latency_ms'     => (int)($turn['llm']['latency_ms'] ?? 0),
                'response_status' => 'ok',
                'is_fallback'    => $turn['is_fallback'] ? 1 : 0,
            ]);

            ConversationStore::update($conversation, [
                'state'             => $nextState,
                'intent'            => $signals['intent'] ?? (string)$conversation['intent'],
                'readiness_signals' => json_encode($signalsMap),
                'lead_state'        => json_encode($leadState, JSON_UNESCAPED_UNICODE),
                'pricing_shown'     => !empty($turn['cards']) ? 1 : (int)$conversation['pricing_shown'],
                'handoff_triggered' => $handoffReason !== null ? 1 : (int)$conversation['handoff_triggered'],
                'handoff_reason'    => $handoffReason !== null ? mb_substr($handoffReason, 0, 255) : $conversation['handoff_reason'],
                'summary'           => ConversationStore::buildSummary($conversation, $leadState, $signalsMap, $turn),
            ] + $qualified['columns'] + [
                'status'            => $nextState === ConversationState::COMPLETED ? 'completed' : 'open',
            ]);

            db()->commit();

            // Research their company once they are a possible exhibitor.
            // Never allowed to break the conversation.
            try {
                CompanyResearch::considerConversation($agent, (int)$conversation['id']);
            } catch (Throwable $ex) {
                app_log('research', 'could not consider research', ['error' => $ex->getMessage()]);
            }

            // The quotation's email, and the team's copy with the same PDF.
            if ($quoteRow !== null) {
                try {
                    Quotation::dispatch($agent, $quoteRow);
                } catch (Throwable $ex) {
                    app_log('quotation', 'could not send', ['number' => $quoteRow['number'], 'error' => $ex->getMessage()]);
                }
            }

            // Notifications after the transaction commits.
            if ($lead !== null) {
                $lead = db()->one('SELECT * FROM leads WHERE id = ?', [(int)$lead['id']]) ?? $lead;
                if ($contactCaptured) {
                    LeadManager::notifyNewLead($agent, $lead, 'contact');
                }
                if ($handoffReason !== null) {
                    LeadManager::notifyNewLead($agent, $lead, 'handoff');
                }
            }
        } catch (Throwable $ex) {
            db()->rollback();
            throw $ex;
        }

        RateLimiter::recordUsage(
            $agentId,
            (int)($turn['llm']['input_tokens'] ?? 0),
            (int)($turn['llm']['output_tokens'] ?? 0)
        );

        return [
            'ok'      => true,
            'blocks'  => $blocks,
            'cards'   => $turn['cards'],
            'state'   => $nextState,
            'handoff' => $handoffReason !== null,
            'contact_form' => false,
            'session_id'   => (string)$conversation['session_id'],
        ];
    }

    // -----------------------------------------------------------------
    // Provider calls
    // -----------------------------------------------------------------
    private static function callProvider(array $agent, array $prompt, array $conversation): array
    {
        $options = [
            'model'       => (string)$agent['llm_model'],
            'temperature' => (float)$agent['llm_temperature'],
            'max_tokens'  => (int)$agent['llm_max_tokens'],
            'timeout'     => (int)$agent['llm_timeout_seconds'],
        ];

        $provider = LlmProviderFactory::make((string)$agent['llm_provider']);
        $result   = self::callWithPatience($provider, $prompt, $options, $conversation);

        // Token limit: trim context and retry (Section 31, 45).
        if (!$result['ok'] && $result['status'] === 'bad_response'
            && $result['error'] !== null && stripos($result['error'], 'token') !== false) {
            $trimmed = $prompt;
            $trimmed['messages'] = array_slice($prompt['messages'], -4);
            $result = $provider->complete($trimmed['system'], $trimmed['messages'], $options);
        }

        if ($result['ok']) {
            CircuitBreaker::recordSuccess($agent, (string)$agent['llm_provider']);
            return $result;
        }

        // Configured fallback provider, tried before declaring unavailability.
        $fallbackProvider = trim((string)($agent['llm_fallback_provider'] ?? ''));
        if ($fallbackProvider !== '' && $fallbackProvider !== (string)$agent['llm_provider']) {
            $fb = LlmProviderFactory::make($fallbackProvider);
            if ($fb->isConfigured()) {
                $fbOptions = $options;
                $fbOptions['model'] = (string)($agent['llm_fallback_model'] ?? '') ?: $options['model'];
                $fbResult = $fb->complete($prompt['system'], $prompt['messages'], $fbOptions);
                if ($fbResult['ok']) {
                    app_log('llm', 'primary failed, fallback succeeded', [
                        'agent' => (int)$agent['id'], 'primary' => (string)$agent['llm_provider'],
                    ]);
                    CircuitBreaker::recordFailure($agent, (string)$agent['llm_provider'], $result['status'], $result['error']);
                    return $fbResult;
                }
            }
        }

        // Busy is not broken: a provider that is overloaded for a minute must
        // not be switched off for everyone, so only real failures count.
        if (!in_array((string)$result['status'], self::BUSY, true)) {
            CircuitBreaker::recordFailure($agent, (string)$agent['llm_provider'], $result['status'], $result['error']);
        }
        RateLimiter::recordUsage((int)$agent['id'], 0, 0, true);
        return $result;
    }

    /** Statuses that mean "busy, try again shortly" rather than "broken". */
    public const BUSY = ['rate_limited', 'overloaded'];

    /**
     * How long a live reply may wait for a busy AI, in seconds. On the
     * website the visitor sees "still working"; on WhatsApp the message is
     * kept and answered by the next scheduled run instead, so it waits less.
     */
    public static int $busyWaitWeb = 25;
    public static int $busyWaitWhatsApp = 8;

    /** Set only by the automated tests, so waiting takes no real time. */
    public static bool $sleepForReal = true;

    /**
     * Call the AI, and when it is busy, wait and try again — a little longer
     * each time — until it answers or the waiting time is used up.
     */
    private static function callWithPatience(LlmProvider $provider, array $prompt, array $options, array $conversation): array
    {
        $budget = (string)($conversation['channel'] ?? 'web') === 'whatsapp'
            ? self::$busyWaitWhatsApp
            : (int)(Settings::get('llm.busy_wait_seconds') ?? self::$busyWaitWeb);
        $deadline = microtime(true) + max(0, $budget);
        $pauses = [1.0, 2.0, 3.0, 5.0, 8.0, 8.0, 8.0];
        $triedAgainAfterBlip = false;

        $result = $provider->complete($prompt['system'], $prompt['messages'], $options);
        for ($i = 0; !$result['ok'] && $i < count($pauses); $i++) {
            $status = (string)$result['status'];
            $busy = in_array($status, self::BUSY, true);
            // A dropped connection or a slow answer is worth one more try.
            $blip = !$triedAgainAfterBlip && in_array($status, ['unavailable', 'timeout'], true);
            if (!$busy && !$blip) {
                break;
            }
            $left = $deadline - microtime(true);
            $pause = $busy ? $pauses[$i] + mt_rand(0, 400) / 1000 : 1.0;
            if ($left < $pause + 1) {
                break;
            }
            if ($blip) {
                $triedAgainAfterBlip = true;
            }
            if (self::$sleepForReal) {
                usleep((int)($pause * 1000000));
            }
            $result = $provider->complete($prompt['system'], $prompt['messages'], $options);
        }
        return $result;
    }

    /**
     * The sequence number of this exact message if it is the last thing the
     * visitor said and nothing has answered it yet; otherwise null.
     */
    private static function unansweredVisitorMessage(array $conversation, string $message): ?int
    {
        $last = db()->one(
            "SELECT sequence_number, role, content FROM messages WHERE conversation_id = ? AND role IN ('visitor','agent')
              ORDER BY sequence_number DESC LIMIT 1",
            [(int)$conversation['id']]
        );
        if ($last !== null && (string)$last['role'] === 'visitor' && trim((string)$last['content']) === trim($message)) {
            return (int)$last['sequence_number'];
        }
        return null;
    }

    /** One tightened retry when the model failed to return valid JSON. */
    private static function retryForValidJson(array $agent, array $prompt, array $conversation): ?array
    {
        $strict = $prompt;
        $strict['messages'][] = [
            'role'    => 'user',
            'content' => 'Your previous response was not valid JSON. Reply again with only the JSON object described in the response format. No other text.',
        ];

        $provider = LlmProviderFactory::make((string)$agent['llm_provider']);
        $result   = $provider->complete($strict['system'], $strict['messages'], [
            'model'       => (string)$agent['llm_model'],
            'temperature' => 0.0,
            'max_tokens'  => (int)$agent['llm_max_tokens'],
            'timeout'     => (int)$agent['llm_timeout_seconds'],
        ]);

        if (!$result['ok']) {
            return null;
        }
        $parsed = LlmContract::parse($result['text']);
        return $parsed['ok'] ? ['llm' => $result, 'parsed' => $parsed] : null;
    }

    // -----------------------------------------------------------------
    // Location handling
    // -----------------------------------------------------------------

    /**
     * Scan the visitor's message for an explicit statement of location.
     * Only explicit patterns are used, so "we export to Germany" is not
     * mistaken for "we are based in Germany".
     */
    public static function extractStatedLocation(string $message): array
    {
        foreach (self::LOCATION_PATTERNS as $pattern) {
            if (preg_match($pattern, $message, $m)) {
                $candidate = trim($m[1]);
                $resolved  = GeoClassifier::resolveDisclosed($candidate);
                if ($resolved['code'] !== null || $resolved['ambiguous']) {
                    $resolved['is_company'] = (bool)preg_match('/company|business|firm|office|factory|hq|head ?office|registered|headquartered/i', $m[0]);
                    $resolved['raw'] = $candidate;
                    return $resolved;
                }
            }
        }
        return ['code' => null, 'name' => null, 'ambiguous' => false, 'matched' => null, 'is_company' => false, 'raw' => null];
    }

    /** Resolve the location fields the model reported. */
    private static function resolveModelLocation(array $leadFields, array $signals): array
    {
        $companyText  = $leadFields['company_country_disclosed'] ?? null;
        $personalText = $leadFields['country_disclosed'] ?? null;

        $company  = GeoClassifier::resolveDisclosed($companyText);
        $personal = GeoClassifier::resolveDisclosed($personalText);

        // Company location governs (Section 14).
        if ($company['code'] !== null) {
            $company['is_company'] = true;
            $company['raw'] = $companyText;
            return $company;
        }
        if ($personal['code'] !== null) {
            $personal['is_company'] = false;
            $personal['raw'] = $personalText;
            return $personal;
        }

        $ambiguous = $company['ambiguous'] || $personal['ambiguous'] || !empty($signals['location_ambiguous']);
        return [
            'code' => null, 'name' => null, 'ambiguous' => $ambiguous,
            'matched' => null, 'is_company' => false, 'raw' => $companyText ?? $personalText,
        ];
    }

    /**
     * Persist a disclosed location. A disclosed country always overrides an
     * earlier IP result, and the IP result is retained (Section 12).
     */
    /** The guided-selling columns exist (the 12 Nov 2026 database upgrade has run). */
    private static function qualificationReady(): bool
    {
        static $ready = null;
        return $ready ??= Migrations::columnExists('conversations', 'lead_label');
    }

    /** What research is sure the company makes. @return string[] */
    private static function researchProducts(array $conversation): array
    {
        try {
            $row = Migrations::tableExists('company_research') ? CompanyResearch::forConversation($conversation) : null;
            return $row !== null ? CompanyResearch::knownProducts($row) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Everything the visitor has written in this conversation, plus this message. */
    private static function visitorWords(array $conversation, string $message): string
    {
        $rows = db()->all(
            "SELECT content FROM messages WHERE conversation_id = ? AND role = 'visitor' ORDER BY id DESC LIMIT 60",
            [(int)$conversation['id']]
        );
        return implode("\n", array_column($rows, 'content')) . "\n" . $message;
    }

    /**
     * After the reply: add what the visitor told us to the qualification
     * record, move along the sales path, and score the lead.
     *
     * @return array{lead_state:array,columns:array}
     */
    private static function qualify(array $agent, array $leadState, array $memory, string $reply, ?int $leadId): array
    {
        if (empty($memory['on_path'])) {
            return ['lead_state' => $leadState, 'columns' => []];
        }
        $qual = Qualification::merge((array)($memory['qual'] ?? []), (array)($memory['qual_update'] ?? []));
        if (!empty($memory['qual_update']['products_made'])) {
            unset($qual['_from_research']);
        }
        $offers = (array)($memory['offers'] ?? []);
        $named  = $offers ? ChatGuide::offerQuestion($reply, $offers)['items'] !== [] : false;
        $path   = Qualification::advance((array)($memory['path'] ?? []), (int)($memory['path_step'] ?? 1), $named, false);
        $step   = Qualification::step($qual, $path);
        $score  = Qualification::score($agent, $qual, (string)($leadState['company_country'] ?? '') ?: null);

        $leadState['qual'] = $qual;
        $leadState['path'] = $path;

        $columns = [
            'qual_pct'   => Qualification::completeness($qual),
            'lead_score' => $score['score'],
            'lead_label' => $score['label'],
            'path_step'  => $step,
        ];
        if ($leadId !== null) {
            db()->update('leads', [
                'qualification' => json_encode($qual, JSON_UNESCAPED_UNICODE),
                'qual_pct'      => $columns['qual_pct'],
                'lead_score'    => $columns['lead_score'],
                'lead_label'    => $columns['lead_label'],
            ], 'id = ?', [$leadId]);
        }
        return ['lead_state' => $leadState, 'columns' => $columns];
    }

    /** What they make, from research or from what they told us: never asked again. */
    private static function knownProducts(array $conversation, array $leadState): array
    {
        $told = trim((string)($leadState['product_category'] ?? ''));
        try {
            $row = Migrations::tableExists('company_research') ? CompanyResearch::forConversation($conversation) : null;
            $found = $row !== null ? CompanyResearch::knownProducts($row) : [];
        } catch (Throwable) {
            $found = [];
        }
        return array_values(array_unique(array_filter(array_merge($told !== '' ? [$told] : [], $found))));
    }

    private static function researchFor(array $conversation): string
    {
        try {
            if (!Migrations::tableExists('company_research')) {
                return '';
            }
            $row = CompanyResearch::forConversation($conversation);
            if ($row !== null) {
                return CompanyResearch::promptBlock($row);
            }
            // Started before the first reply but not finished in time (a
            // slow moment at the AI service): it will be ready for the next
            // reply, so this one does not ask what research will tell us.
            $pending = db()->value(
                "SELECT COUNT(*) FROM company_research WHERE conversation_id = ? AND status IN ('queued', 'running')",
                [(int)$conversation['id']]
            );
            return (int)$pending > 0
                ? "WHAT RESEARCH FOUND ABOUT THEIR COMPANY\nResearch on their company is still running and will be "
                  . "ready for your next reply. Do not ask what their company makes; ask what they would most want "
                  . "to achieve at the exhibition instead."
                : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The country picked on the chat's introduction card.
     *
     * Applied as where the company is based, because that is what the card
     * asks and what pricing depends on. Treated exactly like a country the
     * visitor typed: something they told us, which the IP address never
     * overrides. Anything they say later in the conversation still wins.
     */
    public static function applyFormCountry(array $agent, array $conversation, string $countryCode): array
    {
        $name = CountryData::name($countryCode);
        if ($name === null) {
            return $conversation;
        }
        return self::applyDisclosedLocation($agent, $conversation, [
            'code' => strtoupper($countryCode), 'name' => $name, 'is_company' => true,
        ], 'intro_form');
    }

    private static function applyDisclosedLocation(array $agent, array $conversation, array $resolved, string $source): array
    {
        if ($resolved['code'] === null) {
            return $conversation;
        }

        $isCompany = !empty($resolved['is_company']);
        $existingCompany = (string)($conversation['disclosed_company_country'] ?? '');

        // A personal location never overrides a known company location.
        if (!$isCompany && $existingCompany !== '') {
            return $conversation;
        }

        $classification = GeoClassifier::classifyCode($resolved['code']);
        $previous       = (string)$conversation['pricing_country'];
        $previousSource = (string)$conversation['country_source'];

        $update = [
            'pricing_country' => $classification,
            'country_source'  => GeoClassifier::SOURCE_DISCLOSED,
        ];
        if ($isCompany) {
            $update['disclosed_company_country'] = (string)$resolved['name'];
        } else {
            $update['disclosed_country'] = (string)$resolved['name'];
        }

        db()->update('conversations', $update, 'id = ?', [(int)$conversation['id']]);
        $conversation = array_merge($conversation, $update);

        if ($previous !== $classification || $previousSource !== GeoClassifier::SOURCE_DISCLOSED) {
            Audit::log(
                'geo_classification_changed',
                (int)$agent['id'],
                'conversation',
                (int)$conversation['id'],
                'source=' . $source . ($isCompany ? ' (company location)' : ' (visitor location)')
                . '; previous source=' . $previousSource,
                'pricing_country',
                $previous,
                $classification
            );
        }

        return $conversation;
    }

    /** Explicit pricing request detection (Section 5). */
    public static function detectPricingRequest(string $message, string $state): bool
    {
        $text = mb_strtolower($message);
        foreach (self::PRICE_TERMS as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }
        // A bare size mention counts once the conversation has reached the
        // point where stand size is what is actually being discussed.
        if (in_array($state, [
                ConversationState::PRICING,
                ConversationState::SALES_READINESS,
                ConversationState::LEAD_CAPTURE,
                ConversationState::OBJECTION_HANDLING,
                ConversationState::HANDOFF,
            ], true)
            && preg_match('/\b\d{1,3}\s?(?:sqm|sq\.?\s?m|m2)\b/i', $message)) {
            return true;
        }
        return false;
    }

    /**
     * Is the visitor asking what can be bought (packages, stands, sponsorship),
     * as opposed to what it costs?
     */
    public static function detectCatalogueRequest(string $message, bool $followUps = true): bool
    {
        // Tier and option names, and the follow-ups people use about them,
        // count too (fix plan step 2, 11 Nov 2026).
        if (preg_match(
            '/\b(?:packages?|sponsor(?:s|ship|ships|ing)?|tiers?|stalls?|stands?|booths?|shell(?:\s+scheme)?|'
            . 'bare(?:\s+space)?|raw\s+space|outdoor|participation|participating|exhibit(?:ing)?\s+(?:options?|space)|'
            . 'platinum|gold|silver|bronze|diamond|title\s+(?:sponsor|partner))\b/i',
            $message
        )) {
            return true;
        }
        return $followUps && (bool)preg_match(
            '/\b(?:which\s+one|compare|comparison|difference|what\s+do\s+(?:i|we)\s+get|what(?:\'s|\s+is)\s+included|'
            . 'included|inclusions|benefits|suggest|recommend)\b/i',
            $message
        );
    }

    /**
     * Did the visitor ask the team to get in touch, for a call, a proposal
     * or a quotation? Then asking for their details is the help they want.
     */
    private static function asksForUs(string $message): bool
    {
        return PersonRequest::detect($message) !== null
            || (bool)preg_match('/\b(?:call\s+(?:me|us)|contact\s+(?:me|us)|get\s+in\s+touch|reach\s+(?:me|us)|'
                . 'e-?mail\s+(?:me|us)|send\s+(?:me|us)\s+(?:a\s+|the\s+)?(?:proposal|quotation|quote|details|brochure|'
                . 'information|info)|(?:a|the|your)\s+(?:proposal|quotation)|book(?:ing)?\s+(?:a\s+)?(?:stand|stall|space|booth))\b/i',
                $message);
    }

    /**
     * What this turn adds to the conversation's memory: the visitor's turn
     * count, the contact-asking record, the options that came up, and
     * whether prices were given.
     */
    private static function remember(array $leadState, array $memory, string $reply): array
    {
        if (!$memory) {
            return $leadState;
        }
        $turn = (int)($memory['turn'] ?? 0);
        $leadState['visitor_turns'] = $turn;
        $leadState['contact_asks']  = ChatGuide::noteReply((array)($memory['contact'] ?? []) + ChatGuide::contactRecord([]), $reply, $turn);

        $offers = (array)($memory['offers'] ?? []);
        $talked = !empty($memory['offer_talk']) || ($offers && ChatGuide::mentionsOffer($reply, $offers));
        if ($talked) {
            $leadState['offer_discussed'] = true;
        }
        $named = array_merge(
            (array)($memory['offer_items'] ?? []),
            $offers ? ChatGuide::offerQuestion($reply, $offers)['items'] : []
        );
        if ($named) {
            $leadState['offers_mentioned'] = array_slice(array_values(array_unique(array_merge(
                (array)($leadState['offers_mentioned'] ?? []), $named
            ))), -10);
        }
        if (!empty($memory['offer_focus'])) {
            $leadState['offer_focus'] = array_slice(array_values((array)$memory['offer_focus']), 0, 4);
        }
        if (!empty($memory['prices_given'])) {
            $leadState['prices_given'] = true;
        }
        return $leadState;
    }

    /**
     * The case for exhibiting, for someone who may exhibit (fix plan step 3):
     * what they make (told or researched), the sector it matches, and the
     * exhibition's own notes on Pakistan's market for that sector.
     */
    private static function exhibitorCase(array $agent, array $conversation, array $leadState): ?array
    {
        if (!in_array((string)$conversation['intent'], ['exhibitor', 'sponsor', 'partner'], true)) {
            return null;
        }
        try {
            $about = implode(' ', array_filter([
                (string)($leadState['company'] ?? ''),
                (string)($leadState['industry'] ?? ''),
                (string)($leadState['product_category'] ?? ''),
                (string)($leadState['requirements'] ?? ''),
            ]));
            $row = Migrations::tableExists('company_research') ? CompanyResearch::forConversation($conversation) : null;
            if ($row !== null) {
                $f = CompanyResearch::findingsOf($row);
                if (($f['match_level'] ?? '') !== 'poor') {
                    $about .= ' ' . implode(' ', (array)($f['products'] ?? [])) . ' ' . (string)($f['summary'] ?? '');
                }
            }
            $profile = VisitorProfile::fromRegistration([
                'company_name'     => (string)($leadState['company'] ?? ''),
                'product_solution' => $about,
            ], $agent);
            $profile['track'] = 'exhibitor';
            return ValueProposition::build($agent, $profile, []);
        } catch (Throwable $ex) {
            app_log('chat', 'could not build the exhibitor case', ['error' => $ex->getMessage()]);
            return null;
        }
    }

    private static function pricingFallbackKey(string $classification): string
    {
        return match ($classification) {
            GeoClassifier::PAKISTAN => 'pakistan_pricing',
            GeoClassifier::UNDETERMINED => 'undetermined_pricing',
            default => 'pricing_unavailable',
        };
    }

    private static function hasContactInState(array $leadState): bool
    {
        return Validator::email($leadState['email'] ?? null) !== null
            || Validator::phone($leadState['phone'] ?? null) !== null;
    }

    // -----------------------------------------------------------------
    // Fallback responses
    // -----------------------------------------------------------------

    /**
     * Approved static reply, with no model call (Section 30).
     */
    private static function staticResponse(
        array $agent,
        array $conversation,
        string $key,
        ?string $forceState = null,
        bool $showContactForm = false
    ): array {
        $text   = Fallbacks::get((int)$agent['id'], $key);
        $blocks = Sanitizer::toBlocks($text, AgentResolver::approvedLinkDomains($agent));

        try {
            ConversationStore::appendMessage($conversation, 'agent', $text, [
                'response_status' => $key,
                'is_fallback'     => 1,
                'stage_before'    => (string)$conversation['state'],
                'stage_after'     => $forceState ?? (string)$conversation['state'],
            ]);
            if ($forceState !== null) {
                ConversationStore::update($conversation, ['state' => $forceState]);
            }
        } catch (Throwable $ex) {
            app_log('chat', 'could not store fallback', ['error' => $ex->getMessage()]);
        }

        return [
            'ok'           => true,
            'blocks'       => $blocks,
            'cards'        => [],
            'state'        => $forceState ?? (string)$conversation['state'],
            'handoff'      => $showContactForm,
            'contact_form' => $showContactForm,
            'session_id'   => (string)$conversation['session_id'],
            'fallback'     => $key,
        ];
    }

    /** Provider failure: approved fallback, message preserved, error logged. */
    private static function modelFailureResponse(array $agent, array $conversation, array $llm, int $sequence): array
    {
        app_log('llm', 'call failed', [
            'agent'    => (int)$agent['id'],
            'status'   => (string)($llm['status'] ?? 'unknown'),
            'provider' => (string)$agent['llm_provider'],
        ]);

        // Still busy after waiting: nothing is broken, and the visitor's
        // message is kept. It is answered as soon as the AI is free — the
        // website chat tries again by itself, WhatsApp on the next run.
        if (in_array((string)($llm['status'] ?? ''), self::BUSY, true)) {
            try {
                ConversationStore::appendMessage($conversation, 'system',
                    'The AI was busy, so this message is being tried again.', [
                        'provider' => (string)$agent['llm_provider'], 'response_status' => (string)$llm['status'],
                    ]);
            } catch (Throwable $ex) {
                app_log('chat', 'could not note a busy AI', ['error' => $ex->getMessage()]);
            }
            return [
                'ok'           => true,
                'blocks'       => Sanitizer::toBlocks(Fallbacks::get((int)$agent['id'], 'llm_busy'), []),
                'cards'        => [],
                'state'        => (string)$conversation['state'],
                'handoff'      => false,
                'contact_form' => false,
                'session_id'   => (string)$conversation['session_id'],
                'fallback'     => 'llm_busy',
                'retry_after'  => 12,
            ];
        }

        $key  = CircuitBreaker::isUnavailable($agent) ? 'agent_unavailable' : 'llm_unavailable';
        $text = Fallbacks::get((int)$agent['id'], $key);

        // The assistant cannot answer, so a person is needed: on the website,
        // say who to contact. (WhatsApp hands over and adds this itself.)
        if ((string)($conversation['channel'] ?? 'web') !== 'whatsapp') {
            $repText = SalesReps::textFor((int)$agent['id'], (string)($conversation['pricing_country'] ?? ''), 'web');
            if ($repText !== '') {
                $text .= "\n\n" . $repText;
            }
        }

        try {
            ConversationStore::appendMessage($conversation, 'agent', $text, [
                'provider'        => (string)$agent['llm_provider'],
                'model'           => (string)($llm['model'] ?? ''),
                'latency_ms'      => (int)($llm['latency_ms'] ?? 0),
                'response_status' => (string)($llm['status'] ?? 'error'),
                'error_information' => $llm['error'] !== null ? mb_substr((string)$llm['error'], 0, 500) : null,
                'is_fallback'     => 1,
                'stage_before'    => (string)$conversation['state'],
                'stage_after'     => (string)$conversation['state'],
            ]);
        } catch (Throwable $ex) {
            app_log('chat', 'could not store failure message', ['error' => $ex->getMessage()]);
        }

        return [
            'ok'           => true,
            'blocks'       => Sanitizer::toBlocks($text, AgentResolver::approvedLinkDomains($agent)),
            'cards'        => [],
            'state'        => (string)$conversation['state'],
            'handoff'      => true,
            'contact_form' => true,
            'session_id'   => (string)$conversation['session_id'],
            'fallback'     => $key,
        ];
    }

    private static function errorResponse(array $agent, string $key): array
    {
        $text = Fallbacks::get((int)$agent['id'], $key);
        return [
            'ok'           => true,
            'blocks'       => Sanitizer::toBlocks($text, []),
            'cards'        => [],
            'state'        => ConversationState::NEW_CONVERSATION,
            'handoff'      => false,
            'contact_form' => true,
            'session_id'   => null,
            'fallback'     => $key,
        ];
    }

    // -----------------------------------------------------------------
    // Idempotency (Section 32)
    // -----------------------------------------------------------------
    private static function replayIfSeen(string $requestId, int $agentId): ?array
    {
        $row = db()->one(
            "SELECT state, response_json FROM request_log WHERE request_id = ? AND endpoint = 'chat' LIMIT 1",
            [$requestId]
        );

        if ($row === null) {
            try {
                db()->insert('request_log', [
                    'request_id' => $requestId,
                    'agent_id'   => $agentId,
                    'endpoint'   => 'chat',
                    'state'      => 'in_progress',
                ]);
                return null;
            } catch (Throwable) {
                // A concurrent request claimed it first; fall through to wait.
                $row = db()->one(
                    "SELECT state, response_json FROM request_log WHERE request_id = ? AND endpoint = 'chat' LIMIT 1",
                    [$requestId]
                );
            }
        }

        if ($row === null) {
            return null;
        }

        if ((string)$row['state'] === 'complete' && $row['response_json'] !== null) {
            $decoded = json_decode((string)$row['response_json'], true);
            if (is_array($decoded)) {
                $decoded['replayed'] = true;
                return $decoded;
            }
        }

        // Still processing: wait briefly for the original to finish rather
        // than starting a second model call for the same message.
        for ($i = 0; $i < 12; $i++) {
            usleep(500000);
            $check = db()->one(
                "SELECT state, response_json FROM request_log WHERE request_id = ? AND endpoint = 'chat' LIMIT 1",
                [$requestId]
            );
            if ($check && (string)$check['state'] === 'complete' && $check['response_json'] !== null) {
                $decoded = json_decode((string)$check['response_json'], true);
                if (is_array($decoded)) {
                    $decoded['replayed'] = true;
                    return $decoded;
                }
            }
        }

        return ['ok' => false, 'error' => 'still_processing'];
    }

    private static function completeRequest(string $requestId, int $agentId, array $response): void
    {
        try {
            db()->update('request_log', [
                'state'         => 'complete',
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE),
                'completed_at'  => gmdate('Y-m-d H:i:s'),
            ], "request_id = ? AND endpoint = 'chat'", [$requestId]);
        } catch (Throwable $ex) {
            app_log('chat', 'could not finalise request log', ['error' => $ex->getMessage()]);
        }
    }
}
