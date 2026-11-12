<?php
declare(strict_types=1);

/**
 * The figures behind the Analytics page.
 *
 * Every number is a count of something the platform recorded — a message
 * sent, a reply received, a state a person or the AI set. Where a figure
 * rests on the AI's reading ("interested in exhibiting") the page says so;
 * none of it is a promise of a booking.
 *
 * Dates are the exhibition's own days, as everywhere else.
 */
final class Analytics
{
    /** Where a business opportunity came from. */
    public const SOURCES = [
        'web'         => 'Website chat',
        'whatsapp'    => 'WhatsApp',
        'prospecting' => 'Prospecting',
    ];

    /** States that mean a prospect got as far as each stage. */
    private const QUALIFIED = ['qualified', 'no_email', 'awaiting_approval', 'approved', 'sent', 'email_failed',
        'responded', 'interested', 'follow_up', 'not_interested', 'converted', 'already_contacted', 'unsubscribed'];
    private const SENT = ['sent', 'responded', 'interested', 'follow_up', 'not_interested', 'converted', 'unsubscribed'];
    private const REPLIED = ['responded', 'interested', 'follow_up', 'not_interested', 'converted'];
    private const INTERESTED = ['interested', 'follow_up', 'converted'];

    // -----------------------------------------------------------------
    // The period
    // -----------------------------------------------------------------

    /**
     * The period asked for, as the exhibition's days and as UTC bounds.
     *
     * @return array{from:string, to:string, fromUtc:string, toUtc:string, days:int, label:string}
     */
    public static function period(array $agent, ?string $from, ?string $to, string $preset = '30'): array
    {
        $tz = self::zone($agent);
        $today = new DateTimeImmutable('today', $tz);
        $valid = static fn(?string $d) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

        if ($valid($from) && $valid($to)) {
            $start = new DateTimeImmutable($from, $tz);
            $end   = new DateTimeImmutable($to, $tz);
            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }
            $label = 'From ' . $start->format('j M Y') . ' to ' . $end->format('j M Y');
        } else {
            $days = in_array($preset, ['7', '30', '90', '365'], true) ? (int)$preset : 30;
            if ($preset === 'month') {
                $start = $today->modify('first day of this month');
                $label = 'This month';
            } else {
                $start = $today->modify('-' . ($days - 1) . ' days');
                $label = 'The last ' . $days . ' days';
            }
            $end = $today;
        }
        // Never more than two years, so a mistyped date cannot run for ever.
        if ($start < $end->modify('-730 days')) {
            $start = $end->modify('-730 days');
        }

        $utc = new DateTimeZone('UTC');
        return [
            'from'    => $start->format('Y-m-d'),
            'to'      => $end->format('Y-m-d'),
            'fromUtc' => $start->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'toUtc'   => $end->setTime(0, 0)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'days'    => (int)$start->diff($end)->days + 1,
            'label'   => $label,
        ];
    }

    private static function zone(array $agent): DateTimeZone
    {
        try {
            return new DateTimeZone((string)($agent['notify_timezone'] ?? '') ?: 'Asia/Karachi');
        } catch (Throwable) {
            return new DateTimeZone('Asia/Karachi');
        }
    }

    // -----------------------------------------------------------------
    // Where opportunities come from
    // -----------------------------------------------------------------

    /**
     * Opportunities by source: leads from the website chat and WhatsApp, and
     * prospects who replied to an email.
     *
     * @return array<string,array{opportunities:int, interested:int, booked:int}>
     */
    public static function sources(array $agent, array $p): array
    {
        $id = (int)$agent['id'];
        $out = [];
        foreach (self::SOURCES as $key => $label) {
            $out[$key] = ['opportunities' => 0, 'interested' => 0, 'booked' => 0];
        }

        foreach (db()->all(
            "SELECT l.id, l.status, l.exhibitor_intent,
                    (SELECT c.channel FROM conversations c WHERE c.lead_id = l.id ORDER BY c.id ASC LIMIT 1) AS channel
               FROM leads l
              WHERE l.agent_id = ? AND l.created_at >= ? AND l.created_at < ?
                AND (l.merged_into_id IS NULL OR l.merged_into_id = 0) AND l.status <> 'spam'",
            [$id, $p['fromUtc'], $p['toUtc']]
        ) as $l) {
            $src = (string)$l['channel'] === 'whatsapp' ? 'whatsapp' : 'web';
            $out[$src]['opportunities']++;
            if (in_array((string)$l['status'], ['interested', 'qualified', 'pricing_discussed', 'follow_up_required', 'converted'], true)) {
                $out[$src]['interested']++;
            }
            if ((string)$l['status'] === 'converted') {
                $out[$src]['booked']++;
            }
        }

        if (Migrations::tableExists('prospect_targets')) {
            foreach (db()->all(
                'SELECT state FROM prospect_targets WHERE agent_id = ? AND updated_at >= ? AND updated_at < ?
                   AND state IN (' . self::marks(self::REPLIED) . ')',
                array_merge([$id, $p['fromUtc'], $p['toUtc']], self::REPLIED)
            ) as $t) {
                $out['prospecting']['opportunities']++;
                if (in_array((string)$t['state'], self::INTERESTED, true)) {
                    $out['prospecting']['interested']++;
                }
                if ((string)$t['state'] === 'converted') {
                    $out['prospecting']['booked']++;
                }
            }
        }
        return $out;
    }

    /**
     * New opportunities per day, per source, for the chart.
     *
     * @return array{days: list<string>, series: array<string, list<int>>}
     */
    public static function daily(array $agent, array $p): array
    {
        $id = (int)$agent['id'];
        $tz = self::zone($agent);
        $days = [];
        $cursor = new DateTimeImmutable($p['from'], $tz);
        $end = new DateTimeImmutable($p['to'], $tz);
        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $index = array_flip($days);
        $series = [];
        foreach (self::SOURCES as $key => $label) {
            $series[$key] = array_fill(0, count($days), 0);
        }

        $localDay = static function (string $utc) use ($tz): string {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
        };

        foreach (db()->all(
            "SELECT l.created_at,
                    (SELECT c.channel FROM conversations c WHERE c.lead_id = l.id ORDER BY c.id ASC LIMIT 1) AS channel
               FROM leads l WHERE l.agent_id = ? AND l.created_at >= ? AND l.created_at < ?
                AND (l.merged_into_id IS NULL OR l.merged_into_id = 0) AND l.status <> 'spam'",
            [$id, $p['fromUtc'], $p['toUtc']]
        ) as $l) {
            $d = $localDay((string)$l['created_at']);
            if (isset($index[$d])) {
                $series[(string)$l['channel'] === 'whatsapp' ? 'whatsapp' : 'web'][$index[$d]]++;
            }
        }
        if (Migrations::tableExists('prospect_targets')) {
            foreach (db()->all(
                'SELECT updated_at FROM prospect_targets WHERE agent_id = ? AND updated_at >= ? AND updated_at < ?
                   AND state IN (' . self::marks(self::REPLIED) . ')',
                array_merge([$id, $p['fromUtc'], $p['toUtc']], self::REPLIED)
            ) as $t) {
                $d = $localDay((string)$t['updated_at']);
                if (isset($index[$d])) {
                    $series['prospecting'][$index[$d]]++;
                }
            }
        }
        return ['days' => $days, 'series' => $series];
    }

    // -----------------------------------------------------------------
    // Prospecting
    // -----------------------------------------------------------------

    /** The filters the prospecting figures can be narrowed by. */
    public static function prospectFilters(array $agent): array
    {
        $id = (int)$agent['id'];
        if (!Migrations::tableExists('prospect_targets')) {
            return ['campaigns' => [], 'countries' => [], 'categories' => []];
        }
        return [
            'campaigns'  => array_column(db()->all('SELECT id, name FROM prospect_campaigns WHERE agent_id = ? ORDER BY id DESC', [$id]), 'name', 'id'),
            'countries'  => array_column(db()->all("SELECT DISTINCT country FROM prospect_targets WHERE agent_id = ? AND country IS NOT NULL AND country <> '' ORDER BY country", [$id]), 'country'),
            'categories' => array_column(db()->all("SELECT DISTINCT category FROM prospect_targets WHERE agent_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category", [$id]), 'category'),
        ];
    }

    /**
     * The prospecting funnel for companies found in the period, narrowed by
     * campaign, country and category.
     *
     * @return list<array{key:string, label:string, count:int, note:?string}>
     */
    public static function prospectFunnel(array $agent, array $p, array $f): array
    {
        if (!Migrations::tableExists('prospect_targets')) {
            return [];
        }
        [$where, $params] = self::targetWhere($agent, $p, $f);
        $rows = db()->all('SELECT t.state, (SELECT COUNT(*) FROM outreach_emails o WHERE o.target_id = t.id) AS emails,
                                  (SELECT MAX(o.approved_at IS NOT NULL) FROM outreach_emails o WHERE o.target_id = t.id) AS approved
                             FROM prospect_targets t WHERE ' . $where, $params);

        $count = static fn(callable $test) => count(array_filter($rows, $test));
        $in = static fn(array $states) => static fn($r) => in_array((string)$r['state'], $states, true);

        return [
            ['key' => 'found', 'label' => 'Companies found', 'count' => count($rows), 'note' => null],
            ['key' => 'qualified', 'label' => 'Qualified', 'count' => $count($in(self::QUALIFIED)),
             'note' => 'Scored 60 or more by research — the AI\'s assessment, not a commitment'],
            ['key' => 'rejected', 'label' => 'Set aside', 'count' => $count($in(['not_relevant', 'low_fit', 'rejected', 'do_not_contact'])),
             'note' => 'Not relevant, weak fit, or rejected by the team'],
            ['key' => 'written', 'label' => 'Emails written', 'count' => $count(static fn($r) => (int)$r['emails'] > 0), 'note' => null],
            ['key' => 'approved', 'label' => 'Emails approved', 'count' => $count(static fn($r) => (int)$r['approved'] === 1), 'note' => null],
            ['key' => 'sent', 'label' => 'Emails sent', 'count' => $count($in(self::SENT)), 'note' => null],
            ['key' => 'replied', 'label' => 'Replied', 'count' => $count($in(self::REPLIED)), 'note' => 'As recorded by the team'],
            ['key' => 'interested', 'label' => 'Interested', 'count' => $count($in(self::INTERESTED)), 'note' => 'As recorded by the team'],
            ['key' => 'follow_up', 'label' => 'Passed to a person to follow up', 'count' => $count($in(['follow_up'])), 'note' => null],
            ['key' => 'converted', 'label' => 'Booked', 'count' => $count($in(['converted'])), 'note' => null],
        ];
    }

    /**
     * The same figures, one row per campaign, country or category.
     *
     * @param string $by campaign, country or category
     * @return list<array{name:string, found:int, qualified:int, sent:int, replied:int, interested:int, booked:int}>
     */
    public static function prospectBreakdown(array $agent, array $p, array $f, string $by): array
    {
        if (!Migrations::tableExists('prospect_targets')) {
            return [];
        }
        [$where, $params] = self::targetWhere($agent, $p, $f);
        $col = match ($by) {
            'country'  => "COALESCE(NULLIF(t.country, ''), 'Not known')",
            'category' => "COALESCE(NULLIF(t.category, ''), 'Not known')",
            default    => 'c.name',
        };
        $rows = db()->all('SELECT ' . $col . ' AS name, t.state FROM prospect_targets t
                             JOIN prospect_campaigns c ON c.id = t.campaign_id WHERE ' . $where, $params);
        $out = [];
        foreach ($rows as $r) {
            $n = (string)$r['name'];
            $out[$n] ??= ['name' => $n, 'found' => 0, 'qualified' => 0, 'sent' => 0, 'replied' => 0, 'interested' => 0, 'booked' => 0];
            $s = (string)$r['state'];
            $out[$n]['found']++;
            $out[$n]['qualified']  += in_array($s, self::QUALIFIED, true) ? 1 : 0;
            $out[$n]['sent']       += in_array($s, self::SENT, true) ? 1 : 0;
            $out[$n]['replied']    += in_array($s, self::REPLIED, true) ? 1 : 0;
            $out[$n]['interested'] += in_array($s, self::INTERESTED, true) ? 1 : 0;
            $out[$n]['booked']     += $s === 'converted' ? 1 : 0;
        }
        usort($out, static fn($a, $b) => [$b['replied'], $b['sent'], $b['found']] <=> [$a['replied'], $a['sent'], $a['found']]);
        return array_values($out);
    }

    /** @return array{0:string, 1:array} */
    private static function targetWhere(array $agent, array $p, array $f): array
    {
        $where = 't.agent_id = ? AND t.created_at >= ? AND t.created_at < ?';
        $params = [(int)$agent['id'], $p['fromUtc'], $p['toUtc']];
        if (!empty($f['campaign'])) {
            $where .= ' AND t.campaign_id = ?';
            $params[] = (int)$f['campaign'];
        }
        if (!empty($f['country'])) {
            $where .= ' AND t.country = ?';
            $params[] = (string)$f['country'];
        }
        if (!empty($f['category'])) {
            $where .= ' AND t.category = ?';
            $params[] = (string)$f['category'];
        }
        return [$where, $params];
    }

    // -----------------------------------------------------------------
    // WhatsApp
    // -----------------------------------------------------------------

    /** @return list<array{label:string, count:int, note:?string}> */
    public static function whatsapp(array $agent, array $p): array
    {
        $id = (int)$agent['id'];
        $range = [$id, $p['fromUtc'], $p['toUtc']];
        if (!Migrations::tableExists('registrations')) {
            return [];
        }
        $v = static fn(string $sql, array $params = []) => (int)db()->value($sql, $params ?: $range);

        return [
            ['label' => 'Registrations collected', 'count' => $v(
                'SELECT COUNT(*) FROM registrations WHERE agent_id = ? AND imported_at >= ? AND imported_at < ?'), 'note' => null],
            ['label' => 'Could be messaged on WhatsApp', 'count' => $v(
                "SELECT COUNT(*) FROM registrations WHERE agent_id = ? AND imported_at >= ? AND imported_at < ?
                   AND whatsapp_state NOT IN ('no_consent','no_number','onsite')"), 'note' => 'Agreed to WhatsApp and gave a usable number'],
            ['label' => 'First messages sent', 'count' => $v(
                "SELECT COUNT(*) FROM whatsapp_outbox WHERE agent_id = ? AND kind = 'opening' AND status = 'sent' AND sent_at >= ? AND sent_at < ?"), 'note' => null],
            ['label' => 'Follow-ups sent', 'count' => $v(
                "SELECT COUNT(*) FROM whatsapp_outbox WHERE agent_id = ? AND kind = 'followup' AND status = 'sent' AND sent_at >= ? AND sent_at < ?"), 'note' => null],
            ['label' => 'People who replied', 'count' => $v(
                'SELECT COUNT(DISTINCT phone_e164) FROM whatsapp_inbox WHERE agent_id = ? AND received_at >= ? AND received_at < ?'), 'note' => null],
            ['label' => 'Showed interest in exhibiting or sponsoring', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel = 'whatsapp' AND intent IN ('exhibitor','sponsor')
                   AND created_at >= ? AND created_at < ?"), 'note' => 'The AI\'s reading of the conversation'],
            ['label' => 'Interested in the conference', 'count' => $v(
                "SELECT COUNT(*) FROM registrations WHERE agent_id = ? AND imported_at >= ? AND imported_at < ?
                   AND LOWER(CONCAT_WS(' ', interested_in, reg_type, purpose)) REGEXP 'conference|symposium|seminar|summit|workshop'"),
             'note' => 'From what they ticked when registering'],
            ['label' => 'Became leads', 'count' => $v(
                "SELECT COUNT(DISTINCT l.id) FROM leads l JOIN conversations c ON c.lead_id = l.id AND c.channel = 'whatsapp'
                  WHERE l.agent_id = ? AND l.created_at >= ? AND l.created_at < ?"), 'note' => null],
            ['label' => 'Handed to a person', 'count' => $v(
                "SELECT COUNT(*) FROM audit_log WHERE agent_id = ? AND event = 'whatsapp_handover' AND created_at >= ? AND created_at < ?"), 'note' => null],
            ['label' => 'Asked to stop', 'count' => $v(
                "SELECT COUNT(*) FROM audit_log WHERE agent_id = ? AND event = 'whatsapp_opt_out' AND created_at >= ? AND created_at < ?"), 'note' => null],
        ];
    }

    // -----------------------------------------------------------------
    // Website chat
    // -----------------------------------------------------------------

    /** @return list<array{label:string, count:int, note:?string}> */
    public static function websiteChat(array $agent, array $p): array
    {
        $id = (int)$agent['id'];
        $range = [$id, $p['fromUtc'], $p['toUtc']];
        $v = static fn(string $sql) => (int)db()->value($sql, $range);
        return [
            ['label' => 'Chats started', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel <> 'whatsapp' AND created_at >= ? AND created_at < ?"), 'note' => null],
            ['label' => 'Chats with at least one message', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel <> 'whatsapp' AND message_count > 0 AND created_at >= ? AND created_at < ?"), 'note' => null],
            ['label' => 'Interested in exhibiting or sponsoring', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel <> 'whatsapp' AND intent IN ('exhibitor','sponsor')
                   AND created_at >= ? AND created_at < ?"), 'note' => 'The AI\'s reading of the conversation, or their answer to the opening questions'],
            ['label' => 'Shown a price', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel <> 'whatsapp' AND pricing_shown = 1 AND created_at >= ? AND created_at < ?"), 'note' => null],
            ['label' => 'Left their details (leads)', 'count' => $v(
                "SELECT COUNT(DISTINCT l.id) FROM leads l JOIN conversations c ON c.lead_id = l.id AND c.channel <> 'whatsapp'
                  WHERE l.agent_id = ? AND l.created_at >= ? AND l.created_at < ?"), 'note' => null],
            ['label' => 'Passed to sales', 'count' => $v(
                "SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND channel <> 'whatsapp' AND handoff_triggered = 1 AND created_at >= ? AND created_at < ?"), 'note' => null],
        ] + (Quotation::ready() ? [6 => ['label' => 'Quotations emailed', 'count' => $v(
                "SELECT COUNT(*) FROM quotations WHERE agent_id = ? AND status = 'sent' AND created_at >= ? AND created_at < ?"),
                'note' => 'By the chat and by your team, from the lead page']] : []);
    }

    // -----------------------------------------------------------------
    // Every exhibition side by side
    // -----------------------------------------------------------------

    /** @return list<array{name:string, chat_leads:int, wa_leads:int, emails:int, replies:int, booked:int}> */
    public static function exhibitions(array $p, ?array $onlyAgentIds = null): array
    {
        $out = [];
        $hasProspects = Migrations::tableExists('prospect_targets');
        foreach (db()->all("SELECT id, name, notify_timezone FROM agents WHERE status <> 'deleted' ORDER BY name") as $a) {
            // Staff see only the exhibitions they were given.
            if ($onlyAgentIds !== null && !in_array((int)$a['id'], array_map('intval', $onlyAgentIds), true)) {
                continue;
            }
            $ap = self::period($a, $p['from'], $p['to']);
            $s = self::sources($a, $ap);
            $out[] = [
                'name'       => (string)$a['name'],
                'chat_leads' => $s['web']['opportunities'],
                'wa_leads'   => $s['whatsapp']['opportunities'],
                'emails'     => $hasProspects ? (int)db()->value(
                    "SELECT COUNT(*) FROM outreach_emails WHERE agent_id = ? AND status = 'sent' AND sent_at >= ? AND sent_at < ?",
                    [(int)$a['id'], $ap['fromUtc'], $ap['toUtc']]) : 0,
                'replies'    => $s['prospecting']['opportunities'],
                'booked'     => $s['web']['booked'] + $s['whatsapp']['booked'] + $s['prospecting']['booked'],
            ];
        }
        return $out;
    }

    private static function marks(array $v): string
    {
        return implode(',', array_fill(0, max(1, count($v)), '?'));
    }
}
