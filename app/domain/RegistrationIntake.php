<?php
declare(strict_types=1);

/**
 * Turning a row from the registration system into records this platform owns.
 *
 * Two judgements are made here and both are deliberately conservative.
 *
 * A **company record is only created when the company can be identified** —
 * that is, when there is a business email domain or a website. A registration
 * from a Gmail address with a company name typed into a box is kept as a
 * registration, but does not become a company, because a typed name is not an
 * identity and inventing one would fill the list with near-duplicates nobody
 * can untangle later.
 *
 * A **lead is never created automatically**. Leads are people the sales team
 * should act on; a hundred thousand attendees are not. A registration is
 * linked to an existing lead when the email matches one, and becomes a lead
 * of its own only when the person actually engages.
 */
final class RegistrationIntake
{
    /**
     * Collect and store the next batch for one exhibition.
     *
     * @return array{imported: int, skipped: int, companies: int, error: ?string, states: array<string,int>}
     */
    public static function importBatch(array $agent, int $limit = 100): array
    {
        $agentId = (int)$agent['id'];
        $result  = RegistrationSource::newRows($agentId, $limit);

        if ($result['error'] !== null) {
            return ['imported' => 0, 'skipped' => 0, 'companies' => 0,
                    'error' => $result['error'], 'states' => []];
        }

        $imported  = 0;
        $skipped   = 0;
        $companies = 0;
        $states    = [];

        foreach ($result['rows'] as $row) {
            $stored = self::store($agentId, $row);
            if ($stored === null) {
                $skipped++;
                continue;
            }
            $imported++;
            if ($stored['company_created']) {
                $companies++;
            }
            $state = (string)$stored['whatsapp_state'];
            $states[$state] = ($states[$state] ?? 0) + 1;
        }

        // The watermark moves past everything read, including rows that were
        // skipped, so a row the platform declined is not reconsidered on
        // every run for ever.
        if ($result['highest_id'] > 0) {
            RegistrationSource::setWatermark($agentId, (int)$result['highest_id']);
        }

        if ($imported > 0) {
            Audit::log('registrations_imported', $agentId, 'registration', null,
                $imported . ' collected, watermark now ' . (int)$result['highest_id']);
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'companies' => $companies,
                'error' => null, 'states' => $states];
    }

    /**
     * Store one registration.
     *
     * @return array{id:int, whatsapp_state:string, company_created:bool}|null
     */
    public static function store(int $agentId, array $row): ?array
    {
        $sourceId = (int)($row['id'] ?? 0);
        if ($sourceId <= 0) {
            return null;
        }

        // Already collected. Cheaper to ask than to catch the constraint.
        $existing = db()->value(
            'SELECT id FROM registrations WHERE agent_id = ? AND source_id = ?',
            [$agentId, $sourceId]
        );
        if ($existing !== null) {
            return null;
        }

        $email   = self::cleanEmail($row['email'] ?? null);
        $country = $row['country'] ?? null;
        $read    = Phone::inspect($row['phone'] ?? null, $country);
        $phone   = $read['number'];

        // A registration taken at the exhibition venue. The registration
        // system fills regsite for these, and they are never messaged: the
        // volumes are large, the consent is not there, and a messaging
        // account would be blocked long before the list was worked through.
        $onsite = trim((string)($row['regsite'] ?? '')) !== '';

        // What the visitor actually answered on the form. Anything other than
        // a clear yes is treated as no permission, including a blank, because
        // an unanswered box is not agreement.
        [$consent, $consentAt] = self::readConsent($row);

        // Identify the company only when something reliable identifies it.
        $companyId      = null;
        $companyCreated = false;
        $identity = CompanyStore::normaliseDomain($row['website'] ?? null)
            ?? CompanyStore::companyDomainFromEmail($email);

        if ($identity !== null) {
            $made = CompanyStore::findOrCreate($agentId, [
                'name'    => $row['company'] ?? $identity,
                'website' => $identity,
                'country' => $country,
                'city'    => $row['city'] ?? null,
            ], 'registration');

            $companyId      = (int)$made['company']['id'];
            $companyCreated = (bool)$made['created'];

            if ($companyCreated) {
                self::recordEvidence($agentId, $companyId, $row, $identity);
            }
        }

        // Decided once the company is known, so a company on the do-not-
        // contact list is honoured for everyone who works there.
        $state = self::decideState($agentId, $onsite, $phone, $consent, $email, $companyId);

        // Attach to a lead only if one already exists for this person.
        $leadId = null;
        if ($email !== null) {
            $found = db()->value(
                'SELECT id FROM leads WHERE agent_id = ? AND email = ? ORDER BY id DESC LIMIT 1',
                [$agentId, $email]
            );
            if ($found !== null) {
                $leadId = (int)$found;
                if ($companyId !== null) {
                    db()->update('leads', ['company_id' => $companyId], 'id = ? AND company_id IS NULL', [$leadId]);
                }
            }
        }

        $id = db()->insert('registrations', [
            'agent_id'         => $agentId,
            'source_id'        => $sourceId,
            'source_event_id'  => isset($row['event_id']) ? (int)$row['event_id'] : null,
            'name'             => self::trimTo($row['name'] ?? null, 200),
            'designation'      => self::trimTo($row['designation'] ?? null, 160),
            'company_name'     => self::trimTo($row['company'] ?? null, 200),
            'email'            => $email,
            'website'          => self::trimTo($row['website'] ?? null, 255),
            'phone_raw'        => self::trimTo($row['phone'] ?? null, 120),
            'phone_e164'       => $phone,
            'phone_problem'    => $phone === null ? $read['reason'] : null,
            'city'             => self::trimTo($row['city'] ?? null, 96),
            'country'          => self::trimTo($country, 96),
            'reg_type'         => self::trimTo($row['reg_type'] ?? null, 64),
            'interested_in'    => self::trimTo($row['interested'] ?? null, 255),
            'purpose'          => self::trimTo($row['purpose'] ?? null, 255),
            'product_solution' => self::trimTo($row['product'] ?? null, 255),
            'regsite'          => self::trimTo($row['regsite'] ?? null, 64),
            'onsite'           => $onsite ? 1 : 0,
            'consent_whatsapp' => $consent,
            'consent_at'       => $consentAt,
            'whatsapp_state'   => $state,
            'company_id'       => $companyId,
            'lead_id'          => $leadId,
            'registered_at'    => RegistrationSource::parseDate($row['created_at'] ?? null),
        ]);

        return ['id' => $id, 'whatsapp_state' => $state, 'company_created' => $companyCreated];
    }

    /**
     * Read the unusable numbers again.
     *
     * The parser improves; the registrations already collected do not. This
     * goes back over everything that could not be read before and tries
     * again, which rescues the ones an earlier, blunter reading refused
     * without having to collect them all over.
     *
     * @return array{checked: int, recovered: int, reasons: array<string,int>}
     */
    public static function recheckNumbers(array $agent, int $limit = 500): array
    {
        $agentId = (int)$agent['id'];

        $rows = db()->all(
            "SELECT id, phone_raw, country, onsite, consent_whatsapp, whatsapp_state, email, company_id
               FROM registrations
              WHERE agent_id = ? AND phone_e164 IS NULL AND phone_raw IS NOT NULL
              ORDER BY id ASC LIMIT " . max(1, min(2000, $limit)),
            [$agentId]
        );

        $recovered = 0;
        $reasons   = [];

        foreach ($rows as $row) {
            $read = Phone::inspect((string)$row['phone_raw'], $row['country'] ?? null);

            if ($read['number'] === null) {
                $reasons[$read['reason']] = ($reasons[$read['reason']] ?? 0) + 1;
                db()->update('registrations', ['phone_problem' => $read['reason']], 'id = ?', [(int)$row['id']]);
                continue;
            }

            // A state already settled by a person — an opt-out, or a message
            // already sent — is not reopened by finding a number.
            $keep  = in_array((string)$row['whatsapp_state'], ['queued', 'sent', 'opted_out'], true);
            $state = $keep ? (string)$row['whatsapp_state'] : self::decideState(
                $agentId, (int)$row['onsite'] === 1, $read['number'], (string)$row['consent_whatsapp'],
                $row['email'] ?? null,
                isset($row['company_id']) ? (int)$row['company_id'] : null
            );

            db()->update('registrations', [
                'phone_e164'     => $read['number'],
                'phone_problem'  => null,
                'whatsapp_state' => $state,
            ], 'id = ?', [(int)$row['id']]);

            $recovered++;
        }

        if ($recovered > 0) {
            Audit::log('registration_numbers_rechecked', $agentId, 'registration', null,
                $recovered . ' number(s) recovered from ' . count($rows) . ' checked');
        }

        return ['checked' => count($rows), 'recovered' => $recovered, 'reasons' => $reasons];
    }

    /**
     * Why this person may or may not be messaged.
     *
     * Stored as a state rather than a yes/no, so the screen can say which of
     * several reasons applies. Every one of these is a refusal; nothing here
     * grants permission on its own.
     */
    /**
     * Read the consent answer as it was submitted.
     *
     * @return array{0:string,1:?string} the answer, and when it was given
     */
    public static function readConsent(array $row): array
    {
        if (!array_key_exists('optin', $row)) {
            // The form does not ask yet.
            return ['unknown', null];
        }

        $raw = strtolower(trim((string)($row['optin'] ?? '')));

        $yes = in_array($raw, ['1', 'y', 'yes', 'true', 'on', 'checked'], true);
        if (!$yes) {
            // A blank is not a refusal, but it is not agreement either, and
            // only agreement lets a message be sent.
            return [$raw === '' ? 'unknown' : 'no', null];
        }

        $at = RegistrationSource::parseDate($row['optin_at'] ?? null)
            ?? RegistrationSource::parseDate($row['created_at'] ?? null);

        return ['yes', $at];
    }

    public static function decideState(
        int $agentId,
        bool $onsite,
        ?string $phone,
        string $consent,
        ?string $email = null,
        ?int $companyId = null
    ): string {
        if ($onsite) {
            return 'onsite';
        }
        if (!Phone::isReachable($phone)) {
            return 'no_number';
        }
        // Every kind of block, not only the number: an address or a whole
        // company on the do-not-contact list covers this person too.
        if (WhatsAppEngine::blockedReason($agentId, $phone, $email, $companyId) !== null) {
            return 'suppressed';
        }
        if ($consent !== 'yes') {
            return 'no_consent';
        }
        return 'eligible';
    }

    /** What the registration itself tells us about the company. */
    private static function recordEvidence(int $agentId, int $companyId, array $row, string $identity): void
    {
        $label = 'Visitor registration';
        $where = 'registration form';

        if (!empty($row['country'])) {
            CompanyEvidence::record($agentId, $companyId, 'country', (string)$row['country'],
                'observed', $where, $label, 'medium');
        }
        if (!empty($row['city'])) {
            CompanyEvidence::record($agentId, $companyId, 'city', (string)$row['city'],
                'observed', $where, $label, 'medium');
        }
        if (!empty($row['product'])) {
            CompanyEvidence::record($agentId, $companyId, 'product', (string)$row['product'],
                'observed', $where, $label, 'low');
        }
        if (!empty($row['interested'])) {
            CompanyEvidence::record($agentId, $companyId, 'product_category', (string)$row['interested'],
                'observed', $where, $label, 'low');
        }
    }

    // -----------------------------------------------------------------

    private static function cleanEmail(?string $raw): ?string
    {
        $email = strtolower(trim((string)$raw));
        if ($email === '' || mb_strlen($email) > 255) {
            return null;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private static function trimTo(?string $value, int $length): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /** Plain-language labels for the states above. */
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'eligible'   => 'Can be messaged',
            'queued'     => 'First message written',
            'no_consent' => 'No consent recorded',
            'no_number'  => 'No usable number',
            'onsite'     => 'Registered at the venue',
            'suppressed' => 'On the do-not-contact list',
            'sent'       => 'Message sent',
            'opted_out'  => 'Opted out',
            default      => ucfirst(str_replace('_', ' ', $state)),
        };
    }
}
