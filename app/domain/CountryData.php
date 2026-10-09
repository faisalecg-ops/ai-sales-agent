<?php
declare(strict_types=1);

/**
 * Reference data for resolving a location the visitor stated in conversation
 * into an ISO country code. Country classification is a PHP decision
 * (Section 2), so this mapping lives in the application, not in the model.
 */
final class CountryData
{
    /** ISO 3166-1 alpha-2 => display name. */
    public const COUNTRIES = [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
        'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia',
        'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
        'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin',
        'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
        'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde',
        'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China',
        'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'DR Congo', 'CR' => 'Costa Rica',
        'CI' => "Cote d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
        'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
        'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea',
        'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia', 'FJ' => 'Fiji',
        'FI' => 'Finland', 'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia',
        'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala',
        'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti', 'HN' => 'Honduras',
        'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
        'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
        'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon',
        'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
        'LU' => 'Luxembourg', 'MO' => 'Macao', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia',
        'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MR' => 'Mauritania', 'MU' => 'Mauritius',
        'MX' => 'Mexico', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro',
        'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NP' => 'Nepal',
        'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger',
        'NG' => 'Nigeria', 'KP' => 'North Korea', 'MK' => 'North Macedonia', 'NO' => 'Norway',
        'OM' => 'Oman', 'PK' => 'Pakistan', 'PS' => 'Palestine', 'PA' => 'Panama',
        'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines',
        'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia',
        'RW' => 'Rwanda', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia',
        'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea',
        'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname',
        'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan',
        'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo',
        'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkiye', 'TM' => 'Turkmenistan',
        'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela',
        'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** Alternate names, abbreviations and demonyms => ISO code. */
    public const ALIASES = [
        'uae' => 'AE', 'u a e' => 'AE', 'united arab emirates' => 'AE', 'emirates' => 'AE', 'emirati' => 'AE',
        'uk' => 'GB', 'u k' => 'GB', 'britain' => 'GB', 'great britain' => 'GB', 'england' => 'GB',
        'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB', 'british' => 'GB',
        'usa' => 'US', 'u s a' => 'US', 'us' => 'US', 'u s' => 'US', 'america' => 'US',
        'united states of america' => 'US', 'american' => 'US', 'states' => 'US',
        'ksa' => 'SA', 'saudi' => 'SA', 'saudia' => 'SA', 'kingdom of saudi arabia' => 'SA', 'saudi arabian' => 'SA',
        'pak' => 'PK', 'pakistani' => 'PK', 'islamic republic of pakistan' => 'PK',
        'prc' => 'CN', 'mainland china' => 'CN', 'chinese' => 'CN', "people's republic of china" => 'CN',
        'indian' => 'IN', 'bharat' => 'IN',
        'turkey' => 'TR', 'turkish' => 'TR', 'türkiye' => 'TR',
        'south korea' => 'KR', 'korea' => 'KR', 'republic of korea' => 'KR', 'korean' => 'KR',
        'russia' => 'RU', 'russian federation' => 'RU', 'russian' => 'RU',
        'holland' => 'NL', 'the netherlands' => 'NL', 'dutch' => 'NL',
        'german' => 'DE', 'deutschland' => 'DE', 'french' => 'FR', 'italian' => 'IT', 'spanish' => 'ES',
        'japanese' => 'JP', 'malaysian' => 'MY', 'singaporean' => 'SG', 'indonesian' => 'ID',
        'egyptian' => 'EG', 'iranian' => 'IR', 'afghan' => 'AF', 'afghanistan' => 'AF',
        'bangladeshi' => 'BD', 'sri lankan' => 'LK', 'ceylon' => 'LK', 'nepalese' => 'NP',
        'qatari' => 'QA', 'omani' => 'OM', 'kuwaiti' => 'KW', 'bahraini' => 'BH',
        'south african' => 'ZA', 'nigerian' => 'NG', 'kenyan' => 'KE', 'australian' => 'AU',
        'canadian' => 'CA', 'brazilian' => 'BR', 'mexican' => 'MX', 'polish' => 'PL',
        'czech republic' => 'CZ', 'czech' => 'CZ', 'uzbek' => 'UZ', 'kazakh' => 'KZ',
        'ivory coast' => 'CI', 'burma' => 'MM', 'macedonia' => 'MK', 'swaziland' => 'SZ',
        'vietnamese' => 'VN', 'thai' => 'TH', 'filipino' => 'PH', 'philippine' => 'PH',
        'hong kong sar' => 'HK', 'gulf' => null, 'middle east' => null, 'europe' => null,
        'asia' => null, 'africa' => null, 'overseas' => null, 'abroad' => null,
    ];

    /** Well-known cities that unambiguously imply a country. */
    public const CITIES = [
        // Pakistan
        'karachi' => 'PK', 'lahore' => 'PK', 'islamabad' => 'PK', 'rawalpindi' => 'PK',
        'faisalabad' => 'PK', 'multan' => 'PK', 'peshawar' => 'PK', 'quetta' => 'PK',
        'sialkot' => 'PK', 'gujranwala' => 'PK', 'sukkur' => 'PK', 'bahawalpur' => 'PK',
        'abbottabad' => 'PK', 'mardan' => 'PK', 'gujrat' => 'PK', 'sargodha' => 'PK',
        'larkana' => 'PK', 'sheikhupura' => 'PK', 'rahim yar khan' => 'PK', 'jhelum' => 'PK',
        'wah cantt' => 'PK', 'nawabshah' => 'PK', 'mirpur' => 'PK', 'muzaffarabad' => 'PK',
        'gilgit' => 'PK', 'gwadar' => 'PK', 'kasur' => 'PK', 'okara' => 'PK', 'sahiwal' => 'PK',
        'dera ghazi khan' => 'PK', 'chiniot' => 'PK', 'kamoke' => 'PK', 'attock' => 'PK',
        'punjab pakistan' => 'PK', 'sindh' => 'PK', 'balochistan' => 'PK', 'khyber pakhtunkhwa' => 'PK',
        'kpk' => 'PK', 'azad kashmir' => 'PK',
        // UAE and Gulf
        'dubai' => 'AE', 'abu dhabi' => 'AE', 'sharjah' => 'AE', 'ajman' => 'AE',
        'ras al khaimah' => 'AE', 'fujairah' => 'AE', 'umm al quwain' => 'AE', 'al ain' => 'AE',
        'doha' => 'QA', 'kuwait city' => 'KW', 'manama' => 'BH', 'muscat' => 'OM', 'salalah' => 'OM',
        'riyadh' => 'SA', 'jeddah' => 'SA', 'dammam' => 'SA', 'mecca' => 'SA', 'makkah' => 'SA',
        'medina' => 'SA', 'khobar' => 'SA', 'jubail' => 'SA',
        // Wider region and major markets
        'istanbul' => 'TR', 'ankara' => 'TR', 'izmir' => 'TR',
        'tehran' => 'IR', 'baghdad' => 'IQ', 'erbil' => 'IQ', 'amman' => 'JO', 'beirut' => 'LB',
        'cairo' => 'EG', 'casablanca' => 'MA', 'tunis' => 'TN',
        'mumbai' => 'IN', 'delhi' => 'IN', 'new delhi' => 'IN', 'bangalore' => 'IN',
        'bengaluru' => 'IN', 'chennai' => 'IN', 'kolkata' => 'IN', 'ahmedabad' => 'IN',
        'pune' => 'IN', 'surat' => 'IN', 'jaipur' => 'IN',
        'dhaka' => 'BD', 'chittagong' => 'BD', 'colombo' => 'LK', 'kathmandu' => 'NP',
        'kabul' => 'AF', 'tashkent' => 'UZ', 'almaty' => 'KZ', 'baku' => 'AZ',
        'beijing' => 'CN', 'shanghai' => 'CN', 'guangzhou' => 'CN', 'shenzhen' => 'CN',
        'foshan' => 'CN', 'ningbo' => 'CN', 'qingdao' => 'CN', 'hangzhou' => 'CN',
        'tianjin' => 'CN', 'wuhan' => 'CN', 'xiamen' => 'CN', 'yiwu' => 'CN',
        'kuala lumpur' => 'MY', 'johor bahru' => 'MY', 'penang' => 'MY',
        'jakarta' => 'ID', 'surabaya' => 'ID', 'bangkok' => 'TH', 'hanoi' => 'VN',
        'ho chi minh city' => 'VN', 'saigon' => 'VN', 'manila' => 'PH', 'seoul' => 'KR',
        'busan' => 'KR', 'tokyo' => 'JP', 'osaka' => 'JP', 'taipei' => 'TW',
        'london' => 'GB', 'manchester' => 'GB', 'birmingham uk' => 'GB', 'glasgow' => 'GB',
        'paris' => 'FR', 'lyon' => 'FR', 'marseille' => 'FR',
        'berlin' => 'DE', 'munich' => 'DE', 'hamburg' => 'DE', 'frankfurt' => 'DE',
        'cologne' => 'DE', 'dusseldorf' => 'DE', 'stuttgart' => 'DE',
        'milan' => 'IT', 'rome' => 'IT', 'turin' => 'IT', 'naples' => 'IT',
        'madrid' => 'ES', 'barcelona' => 'ES',
        'amsterdam' => 'NL', 'rotterdam' => 'NL', 'brussels' => 'BE', 'antwerp' => 'BE',
        'zurich' => 'CH', 'geneva' => 'CH', 'vienna' => 'AT', 'warsaw' => 'PL',
        'stockholm' => 'SE', 'copenhagen' => 'DK', 'oslo' => 'NO', 'helsinki' => 'FI',
        'dublin' => 'IE', 'lisbon' => 'PT', 'athens' => 'GR', 'prague' => 'CZ',
        'budapest' => 'HU', 'bucharest' => 'RO', 'moscow' => 'RU', 'saint petersburg' => 'RU',
        'kyiv' => 'UA', 'kiev' => 'UA',
        'new york' => 'US', 'los angeles' => 'US', 'chicago' => 'US', 'houston' => 'US',
        'dallas' => 'US', 'miami' => 'US', 'atlanta' => 'US', 'san francisco' => 'US',
        'seattle' => 'US', 'boston' => 'US', 'las vegas' => 'US', 'orlando' => 'US',
        'toronto' => 'CA', 'vancouver' => 'CA', 'montreal' => 'CA', 'calgary' => 'CA',
        'sydney' => 'AU', 'melbourne' => 'AU', 'brisbane' => 'AU',
        'auckland' => 'NZ', 'johannesburg' => 'ZA', 'cape town' => 'ZA', 'durban' => 'ZA',
        'lagos' => 'NG', 'abuja' => 'NG', 'nairobi' => 'KE', 'accra' => 'GH',
        'dar es salaam' => 'TZ', 'addis ababa' => 'ET', 'khartoum' => 'SD',
        'sao paulo' => 'BR', 'rio de janeiro' => 'BR', 'mexico city' => 'MX',
        'buenos aires' => 'AR', 'lima' => 'PE', 'bogota' => 'CO',
        'singapore' => 'SG', 'hong kong' => 'HK',
    ];

    /**
     * Place names that exist in more than one country. These must never be
     * used to classify pricing — the agent asks instead (Section 14).
     */
    public const AMBIGUOUS_PLACES = [
        'hyderabad',    // Pakistan and India
        'punjab',       // Pakistan and India
        'kashmir',
        'birmingham',   // United Kingdom and United States
        'tripoli',      // Libya and Lebanon
        'san jose',
        'santiago',
        'valencia',
        'cordoba',
        'georgia',      // country and US state
        'jordan',       // country and common surname/place
        'lebanon',
        'canton',
        'richmond',
        'newcastle',
        'perth',
        'kingston',
        'alexandria',
        'cambridge',
        'london ontario',
    ];

    public static function name(string $code): ?string
    {
        return self::COUNTRIES[strtoupper($code)] ?? null;
    }
}
