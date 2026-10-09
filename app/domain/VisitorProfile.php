<?php
declare(strict_types=1);

/**
 * Reading a registration to work out who this person probably is.
 *
 * Everything this produces is an **inference** from a job title and a company
 * name typed into a form. "Director" might run a hospital group or might be
 * the sole proprietor of a two-person trading firm; the words are identical.
 * So every conclusion here carries the reason it was reached, and none of it
 * may ever be stated back to the visitor as fact. A message may quote what
 * they typed. It may not claim what we guessed.
 *
 * Rules rather than a model, deliberately. These conclusions steer a
 * commercial decision, they have to be explainable to whoever reviews them,
 * and they cost nothing to run on a hundred thousand registrations. Where the
 * rules find nothing, the answer is "unknown" and the message stays generic,
 * which is the right outcome.
 */
final class VisitorProfile
{
    // Longest, most specific patterns first: "managing director" must not be
    // read as "manager", and "assistant manager" is not a head of department.
    private const SENIORITY = [
        'decision_maker' => [
            'chief executive', 'managing director', 'chairman', 'chairperson',
            'proprietor', 'founder', 'co-founder', 'owner', 'partner',
            'president', 'ceo', 'cto', 'cfo', 'coo', 'director', 'dy. director',
            'deputy director', 'principal', 'dean',
        ],
        'influencer' => [
            'general manager', 'head of', 'head ', 'department head', 'hod',
            'senior manager', 'project manager', 'manager', 'incharge',
            'in-charge', 'in charge', 'supervisor', 'team lead', 'lead ',
            'coordinator', 'consultant', 'specialist', 'chief',
        ],
        'individual' => [
            'student', 'intern', 'trainee', 'apprentice', 'assistant',
            'executive', 'officer', 'engineer', 'technician', 'technologist',
            'operator', 'representative', 'staff', 'employee', 'undergraduate',
            'retired', 'housewife', 'freelancer',
        ],
    ];

    private const FUNCTION_WORDS = [
        'clinical'   => ['doctor', 'dr.', 'physician', 'surgeon', 'radiologist', 'radiology',
                         'sonologist', 'pathologist', 'nurse', 'clinician', 'medical officer',
                         'consultant physician', 'anaesth', 'cardiolog', 'oncolog', 'lab '],
        'technical'  => ['engineer', 'technician', 'technologist', 'biomedical', 'maintenance',
                         'installation', 'service engineer', 'quality', 'production', 'site '],
        'procurement'=> ['procurement', 'purchase', 'purchasing', 'supply chain', 'sourcing',
                         'stores', 'materials'],
        'commercial' => ['marketing', 'brand', 'sales', 'business development', 'bd ',
                         'commercial', 'export', 'import', 'trade'],
        'management' => ['chief executive', 'managing director', 'general manager', 'director',
                         'administrator', 'administration', 'admin', 'operations', 'owner',
                         'proprietor', 'president', 'ceo', 'management'],
        'academic'   => ['student', 'professor', 'lecturer', 'researcher', 'faculty',
                         'undergraduate', 'phd', 'scholar'],
    ];

    private const ORG_WORDS = [
        'hospital'     => ['hospital', 'clinic', 'medical center', 'medical centre', 'healthcare',
                           'health care', 'diagnostic', 'laborator', 'imaging center', 'imaging centre',
                           'polyclinic', 'nursing home', 'trust hospital'],
        'academic'     => ['university', 'college', 'institute', 'school', 'academy', 'nust',
                           'faculty of'],
        'manufacturer' => ['industries', 'industry', 'manufactur', 'mills', 'factory', 'works',
                           'cement', 'steel', 'plastic', 'textile', 'engineering works'],
        'supplier'     => ['trading', 'traders', 'enterprises', 'enterprise', 'suppliers',
                           'supplier', 'distribut', 'agencies', 'agency', 'impex', 'corporation',
                           'equipment', 'machinery', 'systems', 'technologies', 'solutions',
                           'associates', 'store', 'sanitary', 'hardware', 'marketing',
                           'advertising', 'surgical', 'instruments', 'pharma', 'medical',
                           'motors', 'international'],
        'contractor'   => ['builders', 'developers', 'construction', 'contractor', 'infrastructure',
                           'real estate', 'architects', 'consultants'],
        'government'   => ['ministry', 'department of', 'authority', 'municipal', 'government',
                           'suparco', 'wapda', 'council'],
        'media'        => ['media', 'publication', 'magazine', 'press', 'broadcast', 'news'],
    ];

    /**
     * Work out what we can about one registration.
     *
     * @return array{
     *   seniority: string, seniority_reason: string,
     *   function: string, function_reason: string,
     *   organisation: string, organisation_reason: string,
     *   track: string, track_reason: string,
     *   confidence: string, quotable: array<string,string>
     * }
     */
    public static function fromRegistration(array $reg, array $agent = []): array
    {
        $title   = self::normalise((string)($reg['designation'] ?? ''));
        $company = self::normalise((string)($reg['company_name'] ?? ''));
        $stated  = self::normalise(
            trim((string)($reg['product_solution'] ?? '') . ' ' . (string)($reg['interested_in'] ?? ''))
        );

        [$seniority, $seniorityWord] = self::matchGroup($title, self::SENIORITY);
        [$function,  $functionWord]  = self::matchWords($title, self::FUNCTION_WORDS);
        [$org,       $orgWord]       = self::matchWords($company . ' ' . $stated, self::ORG_WORDS);

        // A clinical or academic job says more about the function than a
        // generic company name does, so it is not overridden by one.
        if ($function === 'unknown' && $org === 'academic') {
            $function = 'academic';
            $functionWord = 'their organisation';
        }

        // Does this organisation belong at THIS exhibition? A textile mill is
        // a manufacturer, and still has nothing to exhibit at a medical show.
        [$relevance, $matched] = self::sectorRelevance($company . ' ' . $stated, $agent);

        [$track, $trackReason] = self::decideTrack($org, $function, $seniority, $relevance, $matched);

        return [
            'relevance'        => $relevance,
            'relevance_match'  => $matched,
            'relevance_reason' => match ($relevance) {
                'relevant'     => 'What they do matches "' . $matched . '", which this exhibition covers.',
                'not_relevant' => 'Nothing about their business matches what this exhibition covers.',
                default        => 'This exhibition has no sector list set, so relevance could not be judged.',
            },
            'seniority'        => $seniority,
            'seniority_reason' => $seniority === 'unknown'
                ? 'The job title did not say.'
                : 'Their title says "' . $seniorityWord . '".',
            'function'         => $function,
            'function_reason'  => $function === 'unknown'
                ? 'Nothing in the title indicated a department.'
                : 'Their title mentions "' . $functionWord . '".',
            'organisation'        => $org,
            'organisation_reason' => $org === 'unknown'
                ? 'The organisation name gave no clue as to its type.'
                : 'Their organisation name contains "' . $orgWord . '".',
            'track'        => $track,
            'track_reason' => $trackReason,
            'confidence'   => self::confidence($seniority, $function, $org),
            'quotable'     => self::quotable($reg),
        ];
    }

    /**
     * Which conversation this person should be offered.
     *
     * Follows the rule given for Health Asia: people who work in the field
     * are there for the conference and the exhibition floor; companies that
     * supply the field, and the commercial side of a hospital, are the ones
     * worth asking about exhibiting.
     *
     * @return array{0:string,1:string}
     */
    private static function decideTrack(
        string $org,
        string $function,
        string $seniority,
        string $relevance,
        string $matched
    ): array {
        // Nobody is asked about exhibiting unless what they do actually
        // belongs at this show. Being senior, or being a manufacturer, is not
        // enough: a procurement manager at a textile mill is a visitor at a
        // construction fair and nothing at all at a medical one.
        $mayExhibit = $relevance === 'relevant';

        if ($mayExhibit && in_array($org, ['supplier', 'manufacturer'], true)) {
            return ['exhibitor',
                'They supply "' . $matched . '", which this exhibition covers.'];
        }

        if ($mayExhibit && $org === 'hospital'
            && in_array($function, ['commercial', 'management', 'procurement'], true)) {
            return ['exhibitor',
                'They are on the commercial side of an organisation in this field.'];
        }

        if ($org === 'hospital' || $function === 'clinical') {
            return ['conference',
                'They work in the field, so the conference programme is the relevant part.'];
        }

        if ($org === 'academic' || $function === 'academic') {
            return ['conference',
                'A student or academic, for whom the conference and the floor are the draw.'];
        }

        if ($org === 'media') {
            return ['media', 'Their organisation looks like a media outlet.'];
        }

        // Someone who runs a business in this field, or runs its commercial
        // side, is the person to ask — but again only if the business is in
        // this field at all.
        if ($mayExhibit && $seniority === 'decision_maker'
            && ($org !== 'unknown' || in_array($function, ['commercial', 'management'], true))) {
            return ['exhibitor',
                'Senior enough to decide, in a field this exhibition covers.'];
        }

        // A company in the right field whose name gives nothing away — "Gulf
        // Cranes LLC" carries no word this class recognises — still belongs on
        // the exhibitor track, as long as the person is not plainly junior.
        if ($mayExhibit && in_array($seniority, ['decision_maker', 'influencer'], true)) {
            return ['exhibitor',
                'They work in "' . $matched . '", which this exhibition covers, and are senior enough to ask.'];
        }

        // Everyone else gets a welcome and whatever conference or programme
        // information is relevant. That includes buyers, which is most
        // visitors, and is not a lesser outcome — they are the audience the
        // exhibitors come for.
        if ($relevance === 'not_relevant' && in_array($org, ['supplier', 'manufacturer'], true)) {
            return ['visitor',
                'Their line of business is not one this exhibition covers, so they are a visitor.'];
        }

        return ['visitor', 'Not enough to place them, so treat them simply as a visitor.'];
    }

    /**
     * How much of this is actually known.
     *
     * Reported so that a thin profile produces a general message rather than
     * a confidently wrong one.
     */
    private static function confidence(string $seniority, string $function, string $org): string
    {
        $known = 0;
        foreach ([$seniority, $function, $org] as $value) {
            if ($value !== 'unknown') {
                $known++;
            }
        }
        return match ($known) {
            3       => 'high',
            2       => 'medium',
            1       => 'low',
            default => 'none',
        };
    }

    /**
     * The only things a message may repeat back.
     *
     * Strictly what the visitor typed into the form. Everything else in this
     * class is a guess, and a guess said out loud becomes a false claim.
     */
    private static function quotable(array $reg): array
    {
        $out = [];
        foreach ([
            'name'         => 'name',
            'company_name' => 'company',
            'designation'  => 'job title',
            'city'         => 'city',
            'country'      => 'country',
            'product_solution' => 'what they said they do',
            'interested_in'    => 'what they said they are interested in',
        ] as $field => $label) {
            $value = trim((string)($reg[$field] ?? ''));
            if ($value !== '' && strtolower($value) !== 'none') {
                $out[$label] = $value;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------

    /**
     * Words too general to prove anything.
     *
     * A sector written as "medical equipment" should match a company that
     * supplies medical equipment, not every firm with "equipment" in its
     * name. Single words this common are ignored on their own; they still
     * count inside a longer phrase.
     */
    private const WEAK_WORDS = [
        'equipment', 'systems', 'system', 'solutions', 'services', 'service',
        'products', 'supplies', 'company', 'trading', 'general', 'international',
        'group', 'industries', 'industry', 'technologies', 'technology',
        'material', 'materials', 'machine', 'machinery', 'works', 'centre', 'center',
    ];

    /**
     * Whether what this person does belongs at this exhibition.
     *
     * Compares what the visitor said about their business against the sector
     * list held on the exhibition. With no list set, the honest answer is
     * "unknown" — and unknown never opens the exhibitor track, because
     * guessing wrong means asking a textile mill to exhibit at a medical show.
     *
     * @return array{0:string,1:string} the verdict, and the sector that matched
     */
    public static function sectorRelevance(string $businessText, array $agent): array
    {
        $sectors = self::sectorList($agent);
        if (!$sectors) {
            return ['unknown', ''];
        }

        $text = self::normalise($businessText);
        if (trim($text) === '') {
            return ['not_relevant', ''];
        }

        // Whole phrases first: they are the strongest evidence, and the
        // longest match is the most specific one.
        $best = '';
        foreach ($sectors as $sector) {
            $needle = self::normalise($sector);
            $needle = trim($needle);
            if ($needle === '') {
                continue;
            }
            if (str_contains($text, ' ' . $needle . ' ') && strlen($needle) > strlen($best)) {
                $best = $needle;
            }
        }
        if ($best !== '') {
            return ['relevant', $best];
        }

        // Then the distinctive single words within each sector.
        foreach ($sectors as $sector) {
            foreach (preg_split('/\s+/', trim(self::normalise($sector))) ?: [] as $word) {
                if (strlen($word) < 4 || in_array($word, self::WEAK_WORDS, true)) {
                    continue;
                }
                if (str_contains($text, ' ' . $word) || str_contains($text, $word . ' ')) {
                    return ['relevant', $word];
                }
            }
        }

        return ['not_relevant', ''];
    }

    /** The sectors this exhibition covers, one per line or comma separated. */
    public static function sectorList(array $agent): array
    {
        $raw = trim((string)($agent['exhibition_sectors'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @return array{0:string,1:string} the group matched, and the word that matched it */
    private static function matchGroup(string $haystack, array $groups): array
    {
        if ($haystack === '') {
            return ['unknown', ''];
        }
        foreach ($groups as $label => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return [$label, trim($word)];
                }
            }
        }
        return ['unknown', ''];
    }

    /**
     * Same as above, but picks the longest match anywhere rather than the
     * first group in order, so "assistant sales manager" is read as
     * commercial rather than stopping at the first word that hits.
     *
     * @return array{0:string,1:string}
     */
    private static function matchWords(string $haystack, array $groups): array
    {
        if (trim($haystack) === '') {
            return ['unknown', ''];
        }

        $bestLabel = 'unknown';
        $bestWord  = '';

        foreach ($groups as $label => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word) && strlen($word) > strlen($bestWord)) {
                    $bestLabel = $label;
                    $bestWord  = $word;
                }
            }
        }

        return [$bestLabel, trim($bestWord)];
    }

    private static function normalise(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\.\- ]+/', ' ', $text) ?? '';
        return ' ' . trim(preg_replace('/\s+/', ' ', $text) ?? '') . ' ';
    }

    // -----------------------------------------------------------------
    // Labels
    // -----------------------------------------------------------------

    public static function trackLabel(string $track): string
    {
        return match ($track) {
            'exhibitor'  => 'Possible exhibitor',
            'conference' => 'Conference and visiting',
            'media'      => 'Media',
            'visitor'    => 'Visitor',
            default      => ucfirst($track),
        };
    }

    public static function seniorityLabel(string $seniority): string
    {
        return match ($seniority) {
            'decision_maker' => 'Likely decides',
            'influencer'     => 'Likely influences',
            'individual'     => 'Unlikely to decide',
            default          => 'Not known',
        };
    }

    public static function organisationLabel(string $org): string
    {
        return match ($org) {
            'hospital'     => 'Hospital or clinic',
            'academic'     => 'University or college',
            'manufacturer' => 'Manufacturer',
            'supplier'     => 'Supplier or trader',
            'contractor'   => 'Contractor or developer',
            'government'   => 'Government body',
            'media'        => 'Media',
            default        => 'Not known',
        };
    }

    public static function functionLabel(string $function): string
    {
        return match ($function) {
            'clinical'    => 'Clinical',
            'technical'   => 'Technical',
            'procurement' => 'Procurement',
            'commercial'  => 'Sales or marketing',
            'management'  => 'Management',
            'academic'    => 'Academic',
            default       => 'Not known',
        };
    }
}
