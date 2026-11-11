<?php
declare(strict_types=1);

/**
 * How the website assistant talks, turn by turn (owner's fix plan, approved
 * 11 November 2026: "AI Sales Agent chat — review and fix plan", steps 1, 2
 * and 5; build brief docs/handover/2026-11-11-chat-review-and-fix-plan.md).
 *
 * PHP reads what the visitor asked and what the assistant said, and keeps a
 * small record in the conversation's lead_state:
 *
 *   - which options and tiers have come up, so the offer stays in view for
 *     the rest of the chat (step 2);
 *   - which option a price question is about, so only that one is quoted, or
 *     the visitor is asked which one first (step 1);
 *   - when contact details were last asked for, and how the visitor took it,
 *     so the assistant asks once at the right moment and then leaves it
 *     (step 1);
 *   - what kind of sponsorship question it is: one tier, a comparison, or a
 *     recommendation (step 5).
 *
 * Nothing here decides a price or a country. It only shapes the guidance the
 * assistant is given for its next reply.
 */
final class ChatGuide
{
    /** Visitor messages that must pass after an ask before asking again. */
    public const ASK_GAP = 5;

    /** Words that name a kind of stand. */
    private const CATEGORY_STAND = '/\b(?:stands?|stalls?|booths?|exhibit(?:ion|ing)?\s+space|floor\s+space|participation\s+(?:options?|packages?)|space\s+options?)\b/i';
    /** Words that name sponsorship as a whole. */
    private const CATEGORY_SPONSOR = '/\b(?:sponsor(?:s|ship|ships|ing)?|tiers?|partner(?:s|ship|ships)?)\b/i';

    /** Words in an offer's name that do not tell one offer from another. */
    private const GENERIC_WORDS = [
        'sponsor', 'sponsors', 'sponsorship', 'partner', 'partners', 'partnership', 'package', 'packages',
        'tier', 'tiers', 'stall', 'stalls', 'stand', 'stands', 'space', 'scheme', 'inside', 'halls', 'hall',
        'exhibit', 'exhibition', 'the', 'and', 'with', 'for', 'per', 'sqm', 'option', 'area', 'level',
        'international', 'pakistan', 'local', 'edition', 'pharma', 'asia', 'build',
    ];

    // -----------------------------------------------------------------
    // What did they ask about?
    // -----------------------------------------------------------------

    /**
     * Read which options or tiers a visitor's message names, and what kind
     * of question it is.
     *
     * @param array $offers the catalogue: [['kind' => ..., 'name' => ...], ...]
     * @return array{items:string[],categories:string[],mode:?string}
     *   mode: 'recommend' | 'compare' | 'detail' | 'list' | null
     */
    public static function offerQuestion(string $message, array $offers): array
    {
        $text  = ' ' . mb_strtolower($message) . ' ';
        $items = [];
        foreach ($offers as $o) {
            $name = trim((string)($o['name'] ?? ''));
            if ($name !== '' && self::namesOffer($text, $name)) {
                $items[] = $name;
            }
        }
        $items = array_values(array_unique($items));

        $categories = [];
        if (preg_match(self::CATEGORY_STAND, $message) || preg_match('/\b(?:shell|bare|outdoor)\b/i', $message)) {
            $categories[] = 'stand';
        }
        if (preg_match(self::CATEGORY_SPONSOR, $message)) {
            $categories[] = 'sponsorship';
        }
        foreach ($offers as $o) {
            if (in_array((string)$o['name'], $items, true)) {
                $categories[] = str_starts_with((string)($o['kind'] ?? ''), 'Sponsorship') ? 'sponsorship' : 'stand';
            }
        }
        $categories = array_values(array_unique($categories));

        $mode = null;
        if (preg_match('/\b(?:suggest|recommend|advi[sc]e|best\s+(?:for|option|choice|fit)|better\s+(?:for|suited|fit)|suits?\s+(?:us|me|our)|right\s+(?:one|tier|option|package)\s+for|which\s+(?:one|tier|package|option|sponsorship|stand)\s+(?:is|would|should|do|for|suits?)|which\s+(?:should|would)\s+(?:we|i)|what\s+would\s+you)\b/i', $message)) {
            $mode = 'recommend';
        } elseif (preg_match('/\b(?:compare|comparison|comparing|difference|differences|differ|versus|vs\.?)\b/i', $message)
            || (count($items) >= 2 && preg_match('/\b(?:or|which)\b/i', $message))) {
            $mode = 'compare';
        } elseif (count($items) === 1) {
            $mode = 'detail';
        } elseif (count($items) >= 2) {
            $mode = 'compare';
        } elseif ($categories) {
            $mode = 'list';
        }

        return ['items' => $items, 'categories' => $categories, 'mode' => $mode];
    }

    /** Does this text name this offer? Matched on its distinctive words. */
    private static function namesOffer(string $text, string $name): bool
    {
        $lower = mb_strtolower($name);
        if (str_contains($text, ' ' . $lower) || str_contains($text, $lower . ' ')) {
            return true;
        }
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [],
            static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, self::GENERIC_WORDS, true)
        ));
        if (!$words) {
            return false;
        }
        foreach ($words as $w) {
            if (!preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $text)) {
                return false;
            }
        }
        // "Title" alone is an ordinary word ("job title"): it names the top
        // tier only next to a sponsorship word.
        if ($words === ['title']) {
            return (bool)preg_match('/\btitle\s+(?:sponsor|partner|tier|package)|\b(?:the|a)\s+title\b/u', $text);
        }
        return true;
    }

    /**
     * Has this conversation reached the offer? Once stands or sponsorship
     * have come up, the catalogue stays in view (step 2).
     */
    public static function mentionsOffer(string $message, array $offers): bool
    {
        if (ChatOrchestrator::detectCatalogueRequest($message)) {
            return true;
        }
        $q = self::offerQuestion($message, $offers);
        return $q['items'] !== [] || $q['categories'] !== [];
    }

    // -----------------------------------------------------------------
    // Asking for contact details (step 1)
    // -----------------------------------------------------------------

    /** The contact record kept in lead_state, with defaults filled in. */
    public static function contactRecord(array $leadState): array
    {
        $c = is_array($leadState['contact_asks'] ?? null) ? $leadState['contact_asks'] : [];
        return [
            'asked'      => (int)($c['asked'] ?? 0),
            'last_turn'  => (int)($c['last_turn'] ?? 0),
            'pending'    => !empty($c['pending']),
            'ignored'    => (int)($c['ignored'] ?? 0),
            'declined'   => (int)($c['declined'] ?? 0),
            'stopped'    => !empty($c['stopped']),
        ];
    }

    /**
     * Before the reply: how did the visitor take the last ask? An answer with
     * no email or phone in it is "not given"; a refusal stops the asking.
     */
    public static function noteVisitorAnswer(array $record, string $message): array
    {
        if (!$record['pending']) {
            return $record;
        }
        $record['pending'] = false;

        if (self::containsContact($message)) {
            return $record;
        }
        if (self::isDecline($message)) {
            $record['declined']++;
            $record['stopped'] = true;
        } else {
            $record['ignored']++;
            if ($record['ignored'] + $record['declined'] >= 2) {
                $record['stopped'] = true;
            }
        }
        return $record;
    }

    /**
     * May the assistant ask for contact details in this reply?
     *
     * @param int  $turn          the number of this visitor message in the chat
     * @param bool $theyAskedForUs they asked for a call, a proposal or a person
     * @return array{may_ask:bool,why:string}
     */
    public static function mayAsk(array $record, int $turn, bool $contactCaptured, bool $theyAskedForUs): array
    {
        if ($contactCaptured) {
            return ['may_ask' => false, 'why' => 'captured'];
        }
        if ($theyAskedForUs) {
            return ['may_ask' => true, 'why' => 'they_asked'];
        }
        if ($record['stopped']) {
            return ['may_ask' => false, 'why' => 'stopped'];
        }
        if ($record['asked'] > 0 && ($turn - $record['last_turn']) < self::ASK_GAP) {
            return ['may_ask' => false, 'why' => 'recently_asked'];
        }
        return ['may_ask' => true, 'why' => 'open'];
    }

    /** After the reply: did it ask for contact details? */
    public static function noteReply(array $record, string $reply, int $turn): array
    {
        if (self::replyAsksForContact($reply)) {
            $record['asked']++;
            $record['last_turn'] = $turn;
            $record['pending']   = true;
        }
        return $record;
    }

    /** Does this reply ask the visitor for an email, a phone number or similar? */
    public static function replyAsksForContact(string $reply): bool
    {
        $contact = '(?:e-?mail(?:\s+address)?|phone(?:\s+number)?|mobile(?:\s+number)?|whats\s?app(?:\s+number)?|'
                 . 'contact\s+(?:details|number|information|info)|number\s+to\s+reach)';
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $reply) ?: [] as $sentence) {
            if (!preg_match('/' . $contact . '/i', $sentence)) {
                continue;
            }
            if (str_contains($sentence, '?')
                || preg_match('/\byour\s+(?:best\s+|preferred\s+|work\s+|business\s+)?' . $contact . '/i', $sentence)
                || preg_match('/\b(?:please\s+)?(?:share|leave|send\s+me|provide|let\s+me\s+have|drop)\b[^.]{0,40}' . $contact . '/i', $sentence)) {
                return true;
            }
        }
        return false;
    }

    public static function containsContact(string $text): bool
    {
        return (bool)preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text)
            || (bool)preg_match('/\+?\d[\d\s().\-]{7,}\d/', $text);
    }

    /**
     * A refusal: a plain "no", a polite "not now", or anything that turns
     * down giving contact details. A "no" that answers some other question
     * ("no, we have not exhibited before") is not a refusal.
     */
    public static function isDecline(string $text): bool
    {
        $t = trim(mb_strtolower($text));
        if (preg_match('/^(?:no|nope|nah|no\s+thanks?|no\s+thank\s+you|not\s+now|not\s+yet|maybe\s+later|later)[\s.!,]*$/u', $t)) {
            return true;
        }
        if (preg_match('/\b(?:no\s+thanks?|no\s+thank\s+you|not\s+now|maybe\s+later|(?:i\'?d|i\s+would|we\'?d|we\s+would)\s+rather\s+not|'
            . 'prefer\s+not|why\s+do\s+you\s+need|just\s+(?:tell|give|answer|show|send)\s+(?:me|us)|keep\s+it\s+(?:here|in\s+the\s+chat))\b/u', $t)) {
            return true;
        }
        // A negative about contact details themselves.
        $details = '(?:e-?mail|phone|number|contact|details|whatsapp)';
        return (bool)preg_match('/\b(?:(?:my|our)\s+(?:\w+\s+)?' . $details . '|(?:share|give|send|provide)\b.{0,20}' . $details . ')/u', $t)
            && (bool)preg_match('/\b(?:no|not|don\'?t|do\s+not|won\'?t|will\s+not|without|never|rather\s+not)\b/u', $t)
            && !self::containsContact($text);
    }

    // -----------------------------------------------------------------
    // Prices set out one option per line (step 1)
    // -----------------------------------------------------------------

    /**
     * One ready-made line per price, written by PHP from the record so the
     * assistant only has to copy it. For example:
     *   "Shell Scheme Stall, 9 sqm: USD 3,375 (USD 3,881.25 with 15% VAT)"
     */
    public static function quoteLine(array $card): string
    {
        $name = (string)$card['title'];
        if (!empty($card['sqm']) && (float)$card['sqm'] > 0) {
            $sqm  = (float)$card['sqm'];
            $name .= ', ' . number_format($sqm, fmod($sqm, 1.0) === 0.0 ? 0 : 2) . ' sqm';
        }

        $base  = (string)($card['price_text'] ?? '');
        $tax   = null;
        $total = null;
        foreach ((array)($card['price_lines'] ?? []) as $line) {
            if (!empty($line['emphasis'])) {
                $total = (string)$line['value'];
            } elseif (preg_match('/\bat\s+([\d.]+%)$/', (string)$line['label'], $m)) {
                $tax = trim(preg_replace('/\s+at\s+[\d.]+%$/', '', (string)$line['label']) ?? '') . '|' . $m[1];
            }
        }

        if ($total !== null && $tax !== null) {
            [$label, $rate] = explode('|', $tax, 2);
            return $name . ': ' . $base . ' (' . $total . ' with ' . $rate . ' ' . ($label !== '' ? $label : 'tax') . ')';
        }
        return $name . ': ' . $base;
    }
}
