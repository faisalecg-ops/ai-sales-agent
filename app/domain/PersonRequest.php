<?php
declare(strict_types=1);

/**
 * Whether a visitor's message needs a person rather than the assistant, and why.
 *
 * One set of rules for every channel. On WhatsApp a match hands the
 * conversation to the team and the assistant goes quiet; on the website the
 * conversation carries on, but in both the visitor is given the right sales
 * rep's details.
 *
 * Three kinds, all read as whole phrases rather than single words:
 *  - they asked for a person, or to be called
 *  - they are complaining or being abusive
 *  - they raised something only the team can decide: a contract, an
 *    invoice, payment, a refund, a discount
 *
 * A request to stop is not here. It is handled before this and ends the
 * conversation outright.
 */
final class PersonRequest
{
    public static function detect(string $body): ?string
    {
        $text = mb_strtolower(trim($body), 'UTF-8');
        $text = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        $has = static function (array $phrases) use ($text): bool {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                    return true;
                }
            }
            return false;
        };

        // Phrases, not single words. B2B visitors describe themselves with
        // words like "representative", "contract" and "court" all the time —
        // "we are the representative of XYZ", "a court furniture maker" — and
        // silencing the assistant on those would lose exactly the people it
        // exists to talk to.
        if ($has([
            'real person', 'actual person', 'real human', 'human being', 'not a bot', 'no bot', 'stop the bot',
            'talk to a person', 'talk to a human', 'speak to a person', 'speak to a human',
            'speak with a person', 'speak with a human', 'talk with a person',
            'talk to someone', 'speak to someone', 'speak with someone', 'talk with someone',
            'talk to your team', 'speak to your team', 'speak with your team',
            'talk to a manager', 'speak to a manager', 'speak with a manager', 'your manager',
            'someone from your team', 'someone from the team', 'your sales team', 'sales person',
            'salesperson', 'sales manager', 'sales representative', 'your representative',
            'speak to a representative', 'talk to a representative', 'customer service',
            'call me', 'please call', 'pls call', 'plz call', 'give me a call', 'can you call me',
            'could you call me', 'can someone call', 'phone me', 'ring me', 'call me back', 'callback',
            'kisi insaan', 'kisi insan', 'kisi bande', 'kisi banday', 'baat karwa', 'baat karwao',
            'baat karwayen', 'call karein', 'call karen', 'call kren', 'call karo', 'call kar dein', 'mujhe call',
            'کال کریں', 'کال کرو', 'بات کروائیں', 'کسی انسان',
        ])) {
            return 'Asked for a person';
        }

        if ($has([
            'complaint', 'complain', 'complaining', 'fraud', 'scam', 'scammer', 'cheated', 'cheating us',
            'cheating me', 'harass', 'harassment', 'harassing', 'disgusting', 'pathetic',
            'this is rubbish', 'rubbish service', 'nonsense', 'useless service', 'you are useless',
            'worst service', 'worst experience', 'worst organiser', 'worst organizer', 'terrible service',
            'very bad service', 'not happy', 'unhappy with', 'very angry', 'i am angry',
            'legal action', 'my lawyer', 'our lawyer', 'take you to court', 'see you in court',
            'consumer court', 'call the police', 'report to police', 'sue you',
            'idiot', 'stupid', 'shut up', 'fuck', 'fucking', 'bullshit',
            'shikayat', 'dhoka', 'dhokha', 'bakwas',
            'شکایت', 'دھوکہ', 'بکواس',
        ])) {
            return 'Complaint or upset';
        }

        if ($has([
            'send the contract', 'send me the contract', 'send us the contract', 'the contract for',
            'sign the contract', 'contract copy', 'agreement copy', 'sign the agreement', 'send the agreement',
            'invoice', 'proforma', 'pro forma', 'quotation', 'bank details', 'bank account',
            'payment terms', 'payment method', 'how to pay', 'how do i pay', 'how can i pay', 'make payment',
            'make the payment', 'advance payment', 'refund', 'cancel my booking', 'cancel our booking',
            'cancellation', 'discount', 'negotiate', 'special price', 'special rate', 'terms and conditions',
        ])) {
            return 'A contract, payment or discount question';
        }

        // The same requests in the chat's other languages (13 Nov 2026).
        if (Lang::has($body, 'person')) {
            return 'Asked for a person';
        }
        if (Lang::has($body, 'money')) {
            return 'A contract, payment or discount question';
        }

        return null;
    }
}
