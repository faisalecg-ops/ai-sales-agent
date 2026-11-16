<?php
declare(strict_types=1);

/**
 * Concerns a visitor raises, and how the assistant answers them (owner,
 * 13 Nov 2026: "SALES_CONVERSATION_QUALITY_AND_PROMPT_IMPROVEMENT_PLAN.md",
 * stage 8 "Objection handling" and scenario H "Refuses further contact").
 *
 * PHP reads the visitor's message for the common concerns in English and
 * Roman Urdu; the assistant also reports the concern it heard, in any
 * language, through the "concern" field of its reply. Either way the concern
 * is kept in the conversation's lead_state, so the next replies remember it
 * and the sales team's briefing lists it.
 *
 * Nothing here changes a price, offers a discount or decides a country. It
 * only shapes the guidance the assistant is given, and — for a visitor who
 * asks not to be contacted — stops the asking and puts their details on the
 * Do-not-contact list (that part is done by ChatOrchestrator).
 */
final class Concerns
{
    /** The concerns, in the words the sales team sees. */
    public const TYPES = [
        'price'        => 'Finds it expensive',
        'approval'     => 'Needs approval from management',
        'send_details' => 'Asked for details to be sent',
        'other_shows'  => 'Already takes part in other exhibitions',
        'not_ready'    => 'Not ready this year',
        'guarantee'    => 'Asked for guaranteed buyers or results',
        'audience'     => 'Asked about the quality of the visitors',
        'opt_out'      => 'Asked not to be contacted',
    ];

    /**
     * The concern in this message, if PHP can tell. Opt-out is checked first:
     * it is the one that changes what the program does.
     */
    public static function detect(string $message): ?string
    {
        $t = ' ' . mb_strtolower(preg_replace('/\s+/u', ' ', $message) ?? $message) . ' ';
        $t = str_replace(["\u{2019}", '`'], "'", $t);

        if (self::isOptOut($t)) {
            return 'opt_out';
        }
        // A quotation or proposal asked for is a request, not a concern.
        $asksQuote = (bool)preg_match('/\b(?:quotation|quote|proposal|pro-?forma|invoice)\b/', $t);

        if (preg_match('/\b(?:guarantee[ds]?|guaranty|assure (?:me|us)|promise (?:me|us)|roi\b|return on (?:our |the |my )?investment|'
            . 'how many (?:buyers|leads|orders|sales|deals|customers|distributors) (?:will|would|can|do)|'
            . '(?:will|would|can) (?:we|i) (?:definitely |surely |really )?(?:get|find|meet) (?:orders|buyers|business|sales|leads|distributors|customers))\b/', $t)) {
            return 'guarantee';
        }
        if (preg_match('/\b(?:too|very|quite|bit|rather|so) (?:expensive|costly|high|pricey|steep)\b|\bexpensive\b|\bcostly\b|'
            . '\b(?:over|above|beyond|out of|exceeds?) (?:our |my |the )?budget\b|\bcan\'?t afford\b|\bcannot afford\b|'
            . '\bmore than (?:we|i) (?:expected|thought|planned)|\bhigher than (?:we|i) (?:expected|thought)|\b(?:price|rate|cost)s? (?:is|are|seems?) (?:too )?high\b|'
            . '\banything cheaper\b|\bcheaper option\b|\b(?:bahut|bohat|kafi|zyada) (?:mehe?nga|mahe?nga)\b|\bmehe?nga hai\b/u', $t)) {
            return 'price';
        }
        // "Not ready to book yet, only comparing" is someone still deciding,
        // not a "no" for this year (transcript review, test 7).
        if (preg_match('/\b(?:not (?:this|the current) year|not ready(?! to (?:book|decide|commit|sign|confirm|order))(?! yet)|next year|next edition|future edition|following year|too early|'
            . 'budget (?:is |was )?(?:closed|finali[sz]ed|fixed|already (?:allocated|spent))|no budget (?:this|for this) year|'
            . 'maybe (?:in|for) (?:\d{4}|the next)|is saal nahi|agle saal)\b/u', $t)) {
            return 'not_ready';
        }
        if (preg_match('/\b(?:(?:check|discuss|consult|confirm|talk|speak)(?: it| this)? with (?:my|our) (?:manager|boss|director|management|team|ceo|md|owner|partners?|board|head office|headquarters|colleagues?)|'
            . '(?:management|board|director|manager|boss|ceo|head office|hq)(?:\'s)? (?:approval|decision|sign-?off)|'
            . 'need (?:to get )?approval|(?:my|our) (?:director|manager|boss|ceo|md|management) (?:asked|wants|told|will decide|decides)|'
            . '(?:collecting|gathering|getting) (?:the )?(?:prices|rates|information|info|details|options) for (?:my|our)|'
            . 'not the decision ?maker|decide internally|internal (?:discussion|approval)|manager se (?:pooch|baat))\b/u', $t)) {
            return 'approval';
        }
        if (preg_match('/\b(?:already|usually|normally|regularly|also) (?:exhibit|participate|take part|attend|go)\b.{0,30}\b(?:other|another|different|exhibitions?|shows?|fairs?|events?|expos?)\b|'
            . '\bother (?:exhibitions|shows|fairs|trade shows|expos|events)\b|\bwhy (?:this|your) (?:exhibition|show|event) (?:and not|instead|over|rather)\b/u', $t)) {
            return 'other_shows';
        }
        if (preg_match('/\b(?:quality|kind|type|profile) of (?:the )?(?:visitors|buyers|audience|attendees)\b|'
            . '\bare (?:the )?(?:visitors|attendees|buyers) (?:serious|real|decision ?makers|buyers|relevant)\b|'
            . '\b(?:who|what kind of people) (?:are the|will be the)? ?(?:visitors|buyers)\b|\bserious buyers\b/u', $t)) {
            return 'audience';
        }
        if (!$asksQuote && preg_match('/\b(?:send|email|e-mail|mail|share|forward) (?:me|us)? ?(?:the |some |more |your )?(?:details|information|info|brochure|catalogue|catalog|presentation|deck|profile|pdf|media kit)\b|'
            . '\b(?:do you have|is there) (?:a |an )?(?:brochure|catalogue|presentation|media kit|exhibitor kit)\b/u', $t)) {
            return 'send_details';
        }
        return null;
    }

    /** "Please don't contact me again", "unsubscribe", "remove me from your list". */
    public static function isOptOut(string $text): bool
    {
        $t = ' ' . mb_strtolower(str_replace(["\u{2019}", '`'], "'", $text)) . ' ';
        return (bool)preg_match(
            '/\b(?:(?:do not|don\'?t|dont|never|please do not|stop) (?:contact|email|e-mail|call|phone|message|whatsapp|text|spam|follow up with) (?:me|us)\b|'
            . 'stop (?:contacting|emailing|calling|messaging|sending)|'
            . 'unsubscribe|opt[ -]?out\b|remove (?:me|us|my (?:email|number|details)|our (?:email|number|details)) from (?:your|the|all)|'
            . '(?:no|not want any) (?:more |further )?(?:emails|calls|messages|contact|follow-?ups?)\b|'
            . 'leave (?:me|us) alone|'
            . '(?:mujhe|hamein|humein) (?:contact|call|email|message|rabta) (?:mat|na) (?:karo|karna|karein|kijiye|kren)|'
            . 'rabta na (?:karein|karo|kijiye))/u',
            $t
        );
    }

    /** Validate the concern the assistant reported. */
    public static function fromModel(mixed $value): ?string
    {
        return is_string($value) && isset(self::TYPES[$value]) ? $value : null;
    }

    /** Add a concern to the record kept in lead_state, oldest first, each once. */
    public static function remember(array $leadState, ?string $type): array
    {
        if ($type === null || !isset(self::TYPES[$type])) {
            return $leadState;
        }
        $list = array_values(array_filter((array)($leadState['concerns'] ?? []), static fn($c) => isset(self::TYPES[$c])));
        if (!in_array($type, $list, true)) {
            $list[] = $type;
        }
        $leadState['concerns'] = $list;
        return $leadState;
    }

    /** The concerns in the team's words. */
    public static function labels(array $types): array
    {
        $out = [];
        foreach ($types as $t) {
            if (isset(self::TYPES[$t])) {
                $out[] = self::TYPES[$t];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The guidance the assistant is given.
     *
     * @param ?string $now     the concern in this message, if PHP found one
     * @param array   $before concerns raised earlier in this chat
     * @param array   $audience approved articles about who visits, for an
     *                          audience, guarantee or other-shows concern
     */
    public static function promptBlock(?string $now, array $before, array $audience = []): string
    {
        $lines = [
            "WHEN THEY RAISE A CONCERN",
            "If they raise a concern (cost, approval, timing, other exhibitions, results, who visits), first work out what "
            . "the concern really is; do not assume. Acknowledge it in a few words, answer it honestly from what you know, "
            . "then offer one low-pressure next step. At most one question. Never argue, never pressure, never invent "
            . "urgency, a discount, a special rate, availability or a result. Report it in \"concern\".",
        ];

        $guide = match ($now) {
            'price' => "THIS MESSAGE: they find it expensive. Say you understand the investment has to make commercial sense. "
                . "Do not offer or hint at a discount and do not defend the price. Ask ONE question: is the concern the total "
                . "budget, or whether that space will give them the right exposure? If a smaller option exists in the "
                . "options you were given, you may mention it by name. Tie the value to what they told you they want.",
            'approval' => "THIS MESSAGE: they need their management's approval, or are collecting information for someone "
                . "else. That is normal, and the person collecting the options matters. Offer to help them prepare a short "
                . "summary for the decision: the exhibition, the option that fits, and the cost"
                . " (a quotation by email does this, where it is offered). Ask at most one question, for example who will "
                . "decide or which option they would put forward.",
            'send_details' => "THIS MESSAGE: they asked for details to be sent. So the details are useful, ask ONE question: "
                . "do they want information about the visitors and the exhibition, the stand and sponsorship options, or "
                . "both? Answer from what you know here. Ask for an email address only as the contact rule under THIS TURN "
                . "allows, and never make information depend on it.",
            'other_shows' => "THIS MESSAGE: they already take part in other exhibitions. Do not criticise any other event. "
                . "Ask what they look for when choosing an exhibition (new distributors, direct buyers, a new market, "
                . "existing customers), then show what this one offers for that, from what you know about the event only.",
            'not_ready' => "THIS MESSAGE: they are not ready this year. Respect it and do not push. Ask once whether they "
                . "would like to hear about a future edition, or prefer to leave it here. If they prefer to leave it, thank "
                . "them and stop selling.",
            'guarantee' => "THIS MESSAGE: they ask for guaranteed buyers, orders or a return. Be honest: no exhibition can "
                . "guarantee a number of sales or buyers for an exhibitor. Then give what you do know about who visits, from "
                . "the approved facts below or in what you know about the event, and ask which customers they most want to meet.",
            'audience' => "THIS MESSAGE: they ask about the quality of the visitors. Answer only from the approved facts "
                . "about visitors (below, or in what you know about the event). Never invent numbers, buyer names or job "
                . "titles. Then ask which customers they most want to meet.",
            'opt_out' => "THIS MESSAGE: they asked not to be contacted. Say, in one or two sentences, that you understand and "
                . "the team will not follow up with them, and that you are happy to help here if they need event information "
                . "later. Ask nothing. No offer, no question, no quotation, no contact details.",
            default => '',
        };
        if ($guide !== '') {
            $lines[] = $guide;
        }

        $earlier = array_values(array_diff(array_intersect($before, array_keys(self::TYPES)), [$now, 'opt_out']));
        if ($earlier) {
            $lines[] = "Raised earlier in this chat: " . implode('; ', array_map(static fn($c) => mb_strtolower(self::TYPES[$c]), $earlier))
                . ". Keep it in mind; do not bring it up again unless they do.";
        }

        if ($audience && in_array($now, ['guarantee', 'audience', 'other_shows', 'price'], true)) {
            $lines[] = "";
            $lines[] = "APPROVED FACTS ABOUT WHO VISITS (use these figures exactly as written; no others):";
            foreach (array_slice($audience, 0, 2) as $a) {
                $lines[] = mb_strtoupper((string)($a['title'] ?? '')) . "\n" . mb_substr((string)($a['body'] ?? ''), 0, 1500);
            }
        }
        return implode("\n", $lines);
    }

    /** For a visitor who asked not to be contacted, on every later reply. */
    public static function optedOutBlock(): string
    {
        return "THEY ASKED NOT TO BE CONTACTED\nEarlier in this chat they asked not to be contacted. Never ask for contact "
            . "details, never offer a quotation, a call or a follow-up, and do not try to sell. Answer only what they ask.";
    }
}
