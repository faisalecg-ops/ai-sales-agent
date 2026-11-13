<?php
declare(strict_types=1);

/**
 * The lead briefing sent to the sales team (Section 33).
 *
 * The team should not have to open the admin panel to know what happened,
 * so the email carries the whole picture: who the person is, what was
 * discussed, the full conversation with timestamps, and a suggested next
 * step.
 *
 * The suggestion is worked out here in PHP from what actually happened in
 * the conversation. It is not written by the model, so it cannot invent a
 * commitment, a price or a deadline for a colleague to honour.
 */
final class LeadBriefing
{
    /**
     * @return array{subject:string,text:string,html:string}
     */
    public static function build(array $agent, array $lead, string $trigger, bool $isLocal): array
    {
        $tz = self::timezone($agent);

        $conversations = db()->all(
            'SELECT * FROM conversations WHERE lead_id = ? ORDER BY id ASC LIMIT 10',
            [(int)$lead['id']]
        );

        $region   = $isLocal ? 'Local' : 'International';
        $research = self::research($lead);
        $headline = match (true) {
            $trigger === 'handoff'                       => $region . ' handoff — ' . self::personLabel($lead),
            $trigger === 'quotation'                     => 'Quotation sent — ' . self::personLabel($lead),
            $trigger === 'researched' && $research !== null
                => $region . ' exhibitor researched — ' . self::personLabel($lead)
                   . ' (' . $research['score'] . '/100, ' . $research['priority'] . ')',
            default                                      => $region . ' lead — ' . self::personLabel($lead),
        };

        $subject = $headline . ' (' . (string)$agent['name'] . ')';

        $summary     = self::summaryRows($lead, $agent, $tz);
        $interest    = self::packageInterest($lead);
        $signals     = self::signalLabels($conversations);
        $unanswered  = self::unansweredQuestions($conversations);
        $suggestions = self::suggestions($agent, $lead, $conversations, $isLocal, $interest, $unanswered);

        return [
            'subject' => $subject,
            'text'    => self::renderText($agent, $lead, $headline, $summary, $interest, $signals, $unanswered, $suggestions, $conversations, $tz, $research),
            'html'    => self::renderHtml($agent, $lead, $headline, $summary, $interest, $signals, $unanswered, $suggestions, $conversations, $tz, $isLocal, $research),
        ];
    }

    // -----------------------------------------------------------------
    // Pieces
    // -----------------------------------------------------------------

    /**
     * What company research found, reduced to what a colleague needs before
     * picking up the phone. Only verified contact details; the reasoning is
     * labelled as the assistant's view. Null when there is none.
     */
    private static function research(array $lead): ?array
    {
        if (!class_exists('CompanyResearch') || !Migrations::tableExists('company_research')) {
            return null;
        }
        $row = CompanyResearch::forLead($lead);
        if ($row === null) {
            return null;
        }
        $f  = CompanyResearch::findingsOf($row);
        $dm = (array)($f['decision_maker'] ?? []);
        $who = trim(((!empty($dm['verified']) ? (string)($dm['name'] ?? '') : '') . ', ' . (string)($dm['title'] ?? '')), ', ');
        $contact = (array)($f['contact'] ?? []);

        $facts = [];
        foreach (array_slice((array)($f['facts'] ?? []), 0, 4) as $fact) {
            if (is_array($fact) && trim((string)($fact['claim'] ?? '')) !== '') {
                $facts[] = (string)$fact['claim'];
            }
        }

        return [
            'company'  => (string)$row['company_name'],
            'score'    => (int)$row['score'],
            'priority' => (string)$row['priority'],
            'website'  => (string)($f['official_website'] ?? ''),
            'why'      => (string)($f['why_exhibit'] ?? ''),
            'offer'    => (string)($f['best_offer']['label'] ?? ''),
            'who'      => $who,
            'email'    => (string)($contact['email'] ?? ''),
            'phone'    => (string)($contact['phone'] ?? ''),
            'facts'    => $facts,
            'link'     => url('admin/research.php?view=' . (int)$row['id']),
        ];
    }

    /** The research as label → value rows, shared by both formats. */
    private static function researchRows(array $r): array
    {
        $rows = ['Score' => $r['score'] . '/100 — ' . $r['priority']];
        if ($r['website'] !== '') { $rows['Website'] = $r['website']; }
        if ($r['offer'] !== '')   { $rows['Suggested offer'] = $r['offer']; }
        if ($r['who'] !== '')     { $rows['Who to approach'] = $r['who']; }
        if ($r['email'] !== '')   { $rows['Company email'] = $r['email']; }
        if ($r['phone'] !== '')   { $rows['Company phone'] = $r['phone']; }
        return $rows;
    }

    private static function timezone(array $agent): DateTimeZone
    {
        $name = trim((string)($agent['notify_timezone'] ?? '')) ?: 'Asia/Karachi';
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** Times are stored in UTC and shown in the team's own timezone. */
    private static function localTime(?string $utc, DateTimeZone $tz, string $format = 'D j M Y, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($utc . ' UTC');
            return $dt->setTimezone($tz)->format($format);
        } catch (Throwable) {
            return (string)$utc;
        }
    }

    private static function personLabel(array $lead): string
    {
        $name    = trim((string)($lead['name'] ?? ''));
        $company = trim((string)($lead['company'] ?? ''));
        if ($name !== '' && $company !== '') {
            return $name . ', ' . $company;
        }
        if ($company !== '') {
            return $company;
        }
        if ($name !== '') {
            return $name;
        }
        return (string)$lead['reference'];
    }

    private static function summaryRows(array $lead, array $agent, DateTimeZone $tz): array
    {
        $country = trim((string)($lead['company_country'] ?? '')) !== ''
            ? (string)$lead['company_country']
            : (string)($lead['country'] ?? '');

        $regionWord = match ((string)$lead['pricing_country']) {
            GeoClassifier::PAKISTAN     => 'Pakistan (local pricing)',
            GeoClassifier::INTERNATIONAL => 'International (USD pricing)',
            default                      => 'Not established',
        };

        $sourceWord = match ((string)($lead['country_source'] ?? '')) {
            GeoClassifier::SOURCE_DISCLOSED => 'stated by the visitor',
            GeoClassifier::SOURCE_IP        => 'from IP address',
            default                         => 'not established',
        };

        return array_filter([
            'Reference'      => (string)$lead['reference'],
            'Name'           => trim((string)($lead['name'] ?? '')),
            'Company'        => trim((string)($lead['company'] ?? '')),
            'Email'          => trim((string)($lead['email'] ?? '')),
            'Phone'          => trim((string)($lead['phone'] ?? '')),
            'Country'        => $country !== '' ? $country . ' (' . $sourceWord . ')' : '',
            'Pricing region' => $regionWord,
            'Industry'       => trim((string)($lead['industry'] ?? '')),
            'Products'       => trim((string)($lead['product_category'] ?? '')),
            'Website'        => trim((string)($lead['website'] ?? '')),
            'Status'         => LeadManager::STATUS_LABELS[(string)$lead['status']] ?? (string)$lead['status'],
            'Quotations'     => self::quotationLine($lead),
            'Chat language'  => self::languageLine($lead),
            'Lead score'     => self::scoreLine($lead),
            'Qualification'  => self::qualificationLine($lead),
            'First seen'     => self::localTime($lead['created_at'] ?? null, $tz),
            'Page'           => trim((string)($lead['source_url'] ?? '')),
        ], static fn($v) => $v !== '' && $v !== null);
    }

    /** The language the visitor chose on the chat card, when it is not English. */
    private static function languageLine(array $lead): string
    {
        $state = db()->value('SELECT lead_state FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [(int)$lead['id']]);
        $code  = Lang::of((array)json_decode((string)$state, true));
        return $code === 'en' ? '' : Lang::name($code) . ' (the transcript below is in that language)';
    }

    /** "PA27-Q-0003: Shell Scheme Stall, 18 sqm, USD 7,762.50, emailed 12 Nov (PDF attached)". */
    private static function quotationLine(array $lead): string
    {
        $out = [];
        foreach (array_slice(Quotation::forLead((int)$lead['agent_id'], (int)$lead['id']), 0, 3) as $q) {
            $out[] = $q['number'] . ': ' . $q['title']
                . ($q['sqm'] ? ', ' . rtrim(rtrim(number_format((float)$q['sqm'], 2), '0'), '.') . ' sqm' : '')
                . ', ' . $q['total_text'] . ' — ' . strtolower(Quotation::statusLabel($q)) . ' to ' . $q['to_email'];
        }
        return implode('; ', $out);
    }

    /** "Warm (72 of 100), 67% complete" — from the guided-selling record, when there is one. */
    private static function scoreLine(array $lead): string
    {
        $label = Qualification::labelText($lead['lead_label'] ?? null);
        if ($label === '') {
            return '';
        }
        return $label . ' (' . (float)($lead['lead_score'] ?? 0) . ' of 100), ' . (int)($lead['qual_pct'] ?? 0) . '% complete';
    }

    private static function qualificationLine(array $lead): string
    {
        $qual = json_decode((string)($lead['qualification'] ?? ''), true);
        if (!is_array($qual)) {
            return '';
        }
        $parts = [];
        foreach (Qualification::FIELDS as $key => $name) {
            $value = Qualification::display($qual, $key);
            if ($value !== '') {
                $parts[] = $name . ': ' . $value;
            }
        }
        return implode('; ', $parts);
    }

    private static function packageInterest(array $lead): array
    {
        $decoded = json_decode((string)($lead['package_interest'] ?? '[]'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function signalLabels(array $conversations): array
    {
        $merged = [];
        foreach ($conversations as $c) {
            $signals = json_decode((string)($c['readiness_signals'] ?? '{}'), true);
            if (is_array($signals)) {
                foreach ($signals as $k => $v) {
                    if ($v) {
                        $merged[$k] = true;
                    }
                }
            }
        }
        return ConversationState::activeSignalLabels($merged);
    }

    /**
     * Questions the agent could not answer. Any visitor message immediately
     * followed by a fallback reply is one the assistant did not handle, and
     * is exactly what a salesperson needs to see before making contact.
     */
    private static function unansweredQuestions(array $conversations): array
    {
        $out = [];
        foreach ($conversations as $c) {
            $messages = ConversationStore::transcript((int)$c['id']);
            for ($i = 0; $i < count($messages); $i++) {
                $m = $messages[$i];
                if ((string)$m['role'] !== 'agent' || (int)($m['is_fallback'] ?? 0) !== 1) {
                    continue;
                }
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ((string)$messages[$j]['role'] === 'visitor') {
                        $out[] = (string)$messages[$j]['content'];
                        break;
                    }
                }
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    /**
     * What the salesperson should do next, derived from the conversation.
     * Deliberately concrete, and never promising anything the agent did not
     * already say.
     */
    private static function suggestions(
        array $agent,
        array $lead,
        array $conversations,
        bool $isLocal,
        array $interest,
        array $unanswered
    ): array {
        $out     = [];
        $hasEmail = Validator::email($lead['email'] ?? null) !== null;
        $hasPhone = Validator::phone($lead['phone'] ?? null) !== null;
        $region   = (string)$lead['pricing_country'];

        // 1. The main action.
        if ($interest) {
            $names = array_map(static fn($i) => (string)($i['label'] ?? ''), $interest);
            $out[] = 'Send the official proposal for ' . self::joinList($names)
                   . '. The assistant has shown the published figures but has not quoted anything else, '
                   . 'and has not discussed stand positions.';
        } elseif ($region === GeoClassifier::PAKISTAN) {
            $out[] = 'This is a Pakistan-based enquiry. The assistant did not share any pricing, '
                   . 'so local rates still need to be sent.';
        } elseif ($region === GeoClassifier::UNDETERMINED) {
            $out[] = 'The country was never established, so no pricing was shared. Confirm where the '
                   . 'company is based before quoting, since that decides which rates apply.';
        } elseif ((int)($lead['pricing_discussed'] ?? 0) === 1) {
            $out[] = 'Pricing has been discussed. Follow up with the official proposal.';
        } else {
            $out[] = 'No pricing has been shared yet. Establish what space they need, then send the '
                   . 'relevant figures.';
        }

        // 2. How to make contact.
        if ($hasEmail && $hasPhone) {
            $out[] = 'Reachable by email and phone.';
        } elseif ($hasEmail) {
            $out[] = 'Only an email address was captured. Ask for a phone number when you reply.';
        } elseif ($hasPhone) {
            $out[] = 'Only a phone number was captured. Ask for an email address so the proposal can be sent.';
        } else {
            $out[] = 'No usable contact details were captured. The record exists only because the '
                   . 'conversation showed buying intent — there may be nothing to act on.';
        }

        // 3. Gaps to close.
        $missing = [];
        foreach (['company' => 'company name', 'industry' => 'industry', 'product_category' => 'what they make or sell'] as $field => $label) {
            if (trim((string)($lead[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            $out[] = 'Still unknown: ' . self::joinList($missing) . '.';
        }

        if ($unanswered) {
            $out[] = 'The assistant could not answer ' . (count($unanswered) === 1 ? 'one question' : count($unanswered) . ' questions')
                   . ', listed below. Those are worth answering first.';
        }

        if ((int)($lead['dedup_flag'] ?? 0) === 1 && trim((string)($lead['dedup_note'] ?? '')) !== '') {
            $out[] = 'Possible duplicate: ' . (string)$lead['dedup_note'] . '. Check before creating a new record.';
        }

        if (count($conversations) > 1) {
            $out[] = 'This person has spoken to the assistant ' . count($conversations)
                   . ' times. All conversations are below.';
        }

        return $out;
    }

    private static function joinList(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items)));
        if (!$items) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    // -----------------------------------------------------------------
    // Plain text
    // -----------------------------------------------------------------
    private static function renderText(
        array $agent, array $lead, string $headline, array $summary, array $interest,
        array $signals, array $unanswered, array $suggestions, array $conversations, DateTimeZone $tz,
        ?array $research = null
    ): string {
        $l   = [];
        $l[] = strtoupper($headline);
        $l[] = str_repeat('=', min(70, mb_strlen($headline)));
        $l[] = '';

        $l[] = 'SUGGESTED NEXT STEP';
        foreach ($suggestions as $i => $sug) {
            $l[] = ($i + 1) . '. ' . $sug;
        }
        $l[] = '';

        $l[] = 'CONTACT';
        foreach ($summary as $label => $value) {
            $l[] = '  ' . str_pad($label, 16) . $value;
        }
        $l[] = '';

        if ($research !== null) {
            $l[] = 'COMPANY RESEARCH — ' . mb_strtoupper($research['company']);
            foreach (self::researchRows($research) as $label => $value) {
                $l[] = '  ' . str_pad($label, 16) . $value;
            }
            if ($research['facts']) {
                $l[] = '  Found on the web:';
                foreach ($research['facts'] as $fact) {
                    $l[] = '    - ' . mb_substr($fact, 0, 300);
                }
            }
            if ($research['why'] !== '') {
                $l[] = '  The assistant\'s view (not checked fact): ' . mb_substr($research['why'], 0, 600);
            }
            $l[] = '  Full research: ' . $research['link'];
            $l[] = '';
        }

        if ($interest) {
            $l[] = 'PACKAGES DISCUSSED';
            foreach ($interest as $i) {
                $l[] = '  ' . (string)($i['label'] ?? '') . ' — ' . (string)($i['price'] ?? '');
            }
            $l[] = '';
        }

        if ($signals) {
            $l[] = 'BUYING SIGNALS';
            foreach ($signals as $s) {
                $l[] = '  - ' . $s;
            }
            $l[] = '';
        }

        if ($unanswered) {
            $l[] = 'QUESTIONS THE ASSISTANT COULD NOT ANSWER';
            foreach ($unanswered as $q) {
                $l[] = '  - ' . mb_substr($q, 0, 300);
            }
            $l[] = '';
        }

        $l[] = 'CONVERSATION';
        $l[] = 'All times ' . $tz->getName() . '.';
        foreach ($conversations as $n => $c) {
            if (count($conversations) > 1) {
                $l[] = '';
                $l[] = '--- Conversation ' . ($n + 1) . ', started ' . self::localTime($c['created_at'] ?? null, $tz) . ' ---';
            }
            foreach (ConversationStore::transcript((int)$c['id']) as $m) {
                $who = match ((string)$m['role']) {
                    'visitor' => 'Visitor',
                    'agent'   => 'Assistant',
                    default   => 'System',
                };
                $l[] = '';
                $l[] = '[' . self::localTime($m['created_at'] ?? null, $tz, 'H:i') . '] ' . $who . ':';
                $l[] = self::indent((string)$m['content']);
                if (!empty($m['rendered_cards'])) {
                    $cards = json_decode((string)$m['rendered_cards'], true);
                    foreach (is_array($cards) ? $cards : [] as $card) {
                        $l[] = '    [shown: ' . (string)($card['title'] ?? '') . ']';
                        foreach (($card['price_lines'] ?? []) as $line) {
                            $l[] = '      ' . str_pad((string)$line['label'], 30) . (string)$line['value'];
                        }
                    }
                }
            }
        }

        $l[] = '';
        $l[] = 'Open this lead: ' . url('admin/lead.php?id=' . (int)$lead['id']);
        $l[] = 'Sent automatically by the ' . (string)$agent['name'] . ' assistant.';

        return implode("\n", $l);
    }

    private static function indent(string $text, string $pad = '    '): string
    {
        $wrapped = wordwrap(trim($text), 76, "\n", false);
        return $pad . str_replace("\n", "\n" . $pad, $wrapped);
    }

    // -----------------------------------------------------------------
    // HTML
    // -----------------------------------------------------------------
    private static function renderHtml(
        array $agent, array $lead, string $headline, array $summary, array $interest,
        array $signals, array $unanswered, array $suggestions, array $conversations,
        DateTimeZone $tz, bool $isLocal, ?array $research = null
    ): string {
        $ink   = '#0F2138';
        $soft  = '#4A5C72';
        $faint = '#7A8899';
        $line  = '#DCE3EC';
        $wash  = '#F4F6F9';
        $accent = '#1B4E8F';
        $signal = '#8A5100';

        $h  = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($headline) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $wash . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $wash . ';padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#FFFFFF;border:1px solid ' . $line . ';border-radius:8px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:' . $ink . ';">';

        // Header
        $h .= '<tr><td style="background:' . $ink . ';padding:20px 24px;">'
            . '<p style="margin:0 0 4px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#9FB3CA;">'
            . e((string)$agent['name']) . ' &middot; ' . ($isLocal ? 'Local enquiry' : 'International enquiry') . '</p>'
            . '<h1 style="margin:0;font-size:19px;line-height:1.35;color:#FFFFFF;font-weight:600;">' . e($headline) . '</h1>'
            . '</td></tr>';

        // Suggested next step
        $h .= '<tr><td style="padding:20px 24px 4px;">'
            . '<h2 style="margin:0 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $accent . ';font-weight:700;">Suggested next step</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ECF2F9;border-left:3px solid ' . $accent . ';border-radius:4px;">'
            . '<tr><td style="padding:12px 14px;">';
        foreach ($suggestions as $i => $sug) {
            $h .= '<p style="margin:' . ($i === 0 ? '0' : '8px') . ' 0 0;font-size:14px;line-height:1.55;color:' . $ink . ';">'
                . '<strong style="color:' . $accent . ';">' . ($i + 1) . '.</strong> ' . e($sug) . '</p>';
        }
        $h .= '</td></tr></table></td></tr>';

        // Contact
        $h .= '<tr><td style="padding:22px 24px 0;">'
            . '<h2 style="margin:0 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $faint . ';font-weight:700;">Contact</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">';
        foreach ($summary as $label => $value) {
            $display = e((string)$value);
            if ($label === 'Email' && Validator::email((string)$value) !== null) {
                $display = '<a href="mailto:' . e((string)$value) . '" style="color:' . $accent . ';">' . e((string)$value) . '</a>';
            }
            if ($label === 'Phone') {
                $display = '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string)$value) ?? '') . '" style="color:' . $accent . ';">' . e((string)$value) . '</a>';
            }
            $h .= '<tr>'
                . '<td style="padding:5px 12px 5px 0;color:' . $soft . ';width:130px;vertical-align:top;">' . e((string)$label) . '</td>'
                . '<td style="padding:5px 0;color:' . $ink . ';vertical-align:top;">' . $display . '</td>'
                . '</tr>';
        }
        $h .= '</table></td></tr>';

        // Company research
        if ($research !== null) {
            $h .= '<tr><td style="padding:22px 24px 0;">'
                . '<h2 style="margin:0 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $faint . ';font-weight:700;">'
                . 'Company research &middot; ' . e($research['company']) . '</h2>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">';
            foreach (self::researchRows($research) as $label => $value) {
                $h .= '<tr><td style="padding:5px 12px 5px 0;color:' . $soft . ';width:130px;vertical-align:top;">' . e($label) . '</td>'
                    . '<td style="padding:5px 0;color:' . $ink . ';vertical-align:top;">' . e($value) . '</td></tr>';
            }
            $h .= '</table>';
            if ($research['facts']) {
                $h .= '<p style="margin:10px 0 4px;font-size:12.5px;color:' . $soft . ';">Found on the web</p>'
                    . '<ul style="margin:0;padding-left:18px;font-size:13.5px;line-height:1.55;color:' . $ink . ';">';
                foreach ($research['facts'] as $fact) {
                    $h .= '<li>' . e(mb_substr($fact, 0, 300)) . '</li>';
                }
                $h .= '</ul>';
            }
            if ($research['why'] !== '') {
                $h .= '<p style="margin:10px 0 0;font-size:13.5px;line-height:1.55;color:' . $soft . ';">'
                    . '<em>The assistant&rsquo;s view, not checked fact:</em> ' . e(mb_substr($research['why'], 0, 600)) . '</p>';
            }
            $h .= '<p style="margin:10px 0 0;font-size:13.5px;"><a href="' . e($research['link']) . '" style="color:' . $accent . ';">'
                . 'Open the full research</a></p></td></tr>';
        }

        // Packages
        if ($interest) {
            $h .= '<tr><td style="padding:22px 24px 0;">'
                . '<h2 style="margin:0 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $faint . ';font-weight:700;">Packages discussed</h2>';
            foreach ($interest as $i) {
                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $line . ';border-left:3px solid ' . $accent . ';border-radius:4px;margin-bottom:8px;">'
                    . '<tr><td style="padding:10px 12px;">'
                    . '<span style="font-size:14px;font-weight:600;">' . e((string)($i['label'] ?? '')) . '</span>'
                    . '<span style="float:right;font-size:14px;font-weight:600;">' . e((string)($i['price'] ?? '')) . '</span>'
                    . '</td></tr></table>';
            }
            $h .= '</td></tr>';
        }

        // Signals
        if ($signals) {
            $h .= '<tr><td style="padding:22px 24px 0;">'
                . '<h2 style="margin:0 0 8px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $faint . ';font-weight:700;">Buying signals</h2>'
                . '<p style="margin:0;font-size:14px;line-height:1.7;color:' . $soft . ';">';
            foreach ($signals as $s) {
                $h .= '<span style="display:inline-block;background:' . $wash . ';border:1px solid ' . $line . ';border-radius:3px;padding:3px 9px;margin:0 5px 5px 0;font-size:12.5px;color:' . $ink . ';">'
                    . e($s) . '</span>';
            }
            $h .= '</p></td></tr>';
        }

        // Unanswered
        if ($unanswered) {
            $h .= '<tr><td style="padding:22px 24px 0;">'
                . '<h2 style="margin:0 0 10px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $signal . ';font-weight:700;">Questions the assistant could not answer</h2>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FBF3E6;border:1px solid #EBDCC2;border-radius:4px;">'
                . '<tr><td style="padding:12px 14px;">';
            foreach ($unanswered as $n => $q) {
                $h .= '<p style="margin:' . ($n === 0 ? '0' : '8px') . ' 0 0;font-size:13.5px;line-height:1.55;color:' . $signal . ';">'
                    . e(mb_substr($q, 0, 300)) . '</p>';
            }
            $h .= '</td></tr></table></td></tr>';
        }

        // Transcript
        $h .= '<tr><td style="padding:24px 24px 0;">'
            . '<h2 style="margin:0 0 4px;font-size:12px;letter-spacing:.07em;text-transform:uppercase;color:' . $faint . ';font-weight:700;">Conversation</h2>'
            . '<p style="margin:0 0 12px;font-size:12px;color:' . $faint . ';">All times ' . e($tz->getName()) . '.</p>';

        foreach ($conversations as $n => $c) {
            if (count($conversations) > 1) {
                $h .= '<p style="margin:14px 0 8px;font-size:12px;color:' . $faint . ';border-top:1px solid ' . $line . ';padding-top:10px;">'
                    . 'Conversation ' . ($n + 1) . ' &middot; started ' . e(self::localTime($c['created_at'] ?? null, $tz)) . '</p>';
            }

            foreach (ConversationStore::transcript((int)$c['id']) as $m) {
                $role = (string)$m['role'];
                $who  = match ($role) {
                    'visitor' => 'Visitor',
                    'agent'   => 'Assistant',
                    default   => 'System',
                };
                $bg     = $role === 'visitor' ? '#ECF2F9' : ($role === 'agent' ? $wash : '#FFFFFF');
                $border = $role === 'visitor' ? $accent : ($role === 'agent' ? $line : $line);
                $time   = self::localTime($m['created_at'] ?? null, $tz, 'H:i');

                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">'
                    . '<tr><td style="background:' . $bg . ';border:1px solid ' . $line . ';border-left:3px solid ' . $border . ';border-radius:4px;padding:10px 12px;">'
                    . '<p style="margin:0 0 5px;font-size:11px;color:' . $faint . ';">'
                    . '<strong style="color:' . $soft . ';font-size:11.5px;">' . $who . '</strong> &middot; ' . e($time)
                    . (!empty($m['is_fallback']) ? ' &middot; <span style="color:' . $signal . ';">standard reply</span>' : '')
                    . '</p>'
                    . '<p style="margin:0;font-size:14px;line-height:1.55;color:' . $ink . ';white-space:pre-wrap;">'
                    . e(mb_substr((string)$m['content'], 0, 4000)) . '</p>';

                if (!empty($m['rendered_cards'])) {
                    $cards = json_decode((string)$m['rendered_cards'], true);
                    foreach (is_array($cards) ? $cards : [] as $card) {
                        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:9px;background:#FFFFFF;border:1px solid ' . $line . ';border-radius:4px;">'
                            . '<tr><td style="padding:9px 11px;">'
                            . '<p style="margin:0 0 6px;font-size:12.5px;font-weight:600;color:' . $ink . ';">'
                            . e((string)($card['title'] ?? '')) . '</p>'
                            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12.5px;">';
                        foreach (($card['price_lines'] ?? []) as $pl) {
                            $weight = !empty($pl['emphasis']) ? '600' : '400';
                            $h .= '<tr>'
                                . '<td style="padding:2px 0;color:' . $soft . ';font-weight:' . $weight . ';">' . e((string)$pl['label']) . '</td>'
                                . '<td align="right" style="padding:2px 0;color:' . $ink . ';font-weight:' . $weight . ';">' . e((string)$pl['value']) . '</td>'
                                . '</tr>';
                        }
                        $h .= '</table></td></tr></table>';
                    }
                }

                $h .= '</td></tr></table>';
            }
        }
        $h .= '</td></tr>';

        // Footer
        $h .= '<tr><td style="padding:20px 24px 24px;">'
            . '<a href="' . e(url('admin/lead.php?id=' . (int)$lead['id'])) . '" '
            . 'style="display:inline-block;background:' . $accent . ';color:#FFFFFF;text-decoration:none;'
            . 'padding:11px 18px;border-radius:5px;font-size:14px;font-weight:500;">Open this lead</a>'
            . '<p style="margin:16px 0 0;font-size:11.5px;color:' . $faint . ';line-height:1.5;">'
            . 'Sent automatically by the ' . e((string)$agent['name']) . ' assistant. '
            . 'Reference ' . e((string)$lead['reference']) . '.</p>'
            . '</td></tr>';

        $h .= '</table></td></tr></table></body></html>';

        return $h;
    }
}
