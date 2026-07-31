<?php
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class avetmiss_codes {
    
    /**
     * Returns all AVETMISS 2.3 Outcome Identifier - National codes and their
     * official descriptions. Source: AVETMISS Data Element Definitions Edition 2.3
     * November 2016 (updated November 2022), NCVER.
     *
     * Codes removed since earlier editions and NOT present in 2.3:
     *   '53', '54' - Recognition of Current Competency codes (deleted in Edition 2.1)
     *   '65', '66' - Non-standard codes (never in the AVETMISS standard)
     *   '90' - "Not yet available at interim collection" (deleted in Edition 2.3)
     */
    public static function get_outcome_identifiers() {
        return [
            '20' => 'Competency achieved/pass',
            '30' => 'Competency not achieved/fail',
            '40' => 'Withdrawn/discontinued',
            '41' => 'Incomplete due to RTO closure',
            '51' => 'Recognition of prior learning granted',
            '52' => 'Recognition of prior learning not granted',
            '60' => 'Credit transfer/national recognition',
            '61' => 'Superseded subject',
            '70' => 'Continuing activity',
            '81' => 'Non-assessable activity - satisfactorily completed',
            '82' => 'Non-assessable activity - withdrawn or not satisfactorily completed',
            '85' => 'Not yet started',
        ];
    }

    /**
     * Outcome codes that represent a POSITIVE final competency result.
     * Used to determine qualification completion eligibility for auto-certificates.
     * Note: '30' (Fail) and '40' (Withdrawn) are final outcomes but NOT positive.
     * Note: '52' (RPL not granted) is a final outcome but NOT a positive result.
     */
    public static function get_completion_outcomes() {
        return ['20', '51', '60', '81'];
    }

    /**
     * Outcome codes that represent ongoing/in-progress training.
     * Records with these codes must be reported in a subsequent collection with a final outcome.
     */
    public static function get_continuing_outcomes() {
        return ['70', '85'];
    }

    public static function get_country_codes() {
        return [
            '0000' => 'Inadequately described',
            '0001' => 'At sea',
            '1101' => 'Australia',
            '1102' => 'Norfolk Island',
            '1199' => 'Australian External Territories, nec',
            '1201' => 'New Zealand',
            '1301' => 'New Caledonia',
            '1302' => 'Papua New Guinea',
            '1303' => 'Solomon Islands',
            '1304' => 'Vanuatu',
            '1401' => 'Guam',
            '1402' => 'Kiribati',
            '1403' => 'Marshall Islands',
            '1404' => 'Micronesia, Federated States of',
            '1405' => 'Nauru',
            '1406' => 'Northern Mariana Islands',
            '1407' => 'Palau',
            '1501' => 'Cook Islands',
            '1502' => 'Fiji',
            '1503' => 'French Polynesia',
            '1504' => 'Niue',
            '1505' => 'Samoa',
            '1506' => 'Samoa, American',
            '1507' => 'Tokelau',
            '1508' => 'Tonga',
            '1511' => 'Tuvalu',
            '1512' => 'Wallis and Futuna',
            '1513' => 'Pitcairn Islands',
            '1599' => 'Polynesia (excludes Hawaii), nec',
            '2100' => 'United Kingdom, Channel Islands and Isle of Man',
            '2102' => 'England',
            '2103' => 'Isle of Man',
            '2104' => 'Northern Ireland',
            '2105' => 'Scotland',
            '2106' => 'Wales',
            '2107' => 'Guernsey',
            '2108' => 'Jersey',
            '2201' => 'Ireland',
            '3101' => 'Germany',
            '3102' => 'Netherlands',
            '3103' => 'Switzerland',
            '3104' => 'Austria',
            '3105' => 'Belgium',
            '3106' => 'Liechtenstein',
            '3107' => 'Luxembourg',
            '3201' => 'Denmark',
            '3202' => 'Faroe Islands',
            '3203' => 'Finland',
            '3204' => 'Greenland',
            '3205' => 'Iceland',
            '3206' => 'Norway',
            '3207' => 'Sweden',
            '3208' => 'Aland Islands',
            '3301' => 'Andorra',
            '3302' => 'Gibraltar',
            '3303' => 'Holy See',
            '3304' => 'Italy',
            '3305' => 'Malta',
            '3306' => 'Monaco',
            '3307' => 'Portugal',
            '3308' => 'San Marino',
            '3311' => 'Spain',
            '3401' => 'Albania',
            '3402' => 'Bosnia and Herzegovina',
            '3403' => 'Bulgaria',
            '3404' => 'Croatia',
            '3405' => 'Cyprus',
            '3406' => 'North Macedonia',
            '3407' => 'Greece',
            '3408' => 'Moldova',
            '3411' => 'Romania',
            '3412' => 'Slovenia',
            '3413' => 'Montenegro',
            '3414' => 'Serbia',
            '3415' => 'Kosovo',
            '3501' => 'Belarus',
            '3502' => 'Czech Republic',
            '3503' => 'Estonia',
            '3504' => 'Hungary',
            '3505' => 'Latvia',
            '3506' => 'Lithuania',
            '3507' => 'Poland',
            '3508' => 'Russian Federation',
            '3511' => 'Slovakia',
            '3512' => 'Ukraine',
            '4101' => 'Brunei Darussalam',
            '4102' => 'Myanmar',
            '4103' => 'Cambodia',
            '4104' => 'Indonesia',
            '4105' => 'Laos',
            '4106' => 'Malaysia',
            '4107' => 'Philippines',
            '4108' => 'Singapore',
            '4111' => 'Thailand',
            '4112' => 'Timor-Leste',
            '4113' => 'Vietnam',
            '5101' => 'China (excludes SARs and Taiwan)',
            '5102' => 'Hong Kong (SAR of China)',
            '5103' => 'Macau (SAR of China)',
            '5104' => 'Mongolia',
            '5105' => 'Taiwan',
            '5201' => 'Japan',
            '5202' => 'Korea, Democratic Peoples Republic of (North)',
            '5203' => 'Korea, Republic of (South)',
            '6101' => 'Bangladesh',
            '6102' => 'Bhutan',
            '6103' => 'India',
            '6104' => 'Maldives',
            '6105' => 'Nepal',
            '6106' => 'Pakistan',
            '6107' => 'Sri Lanka',
            '6201' => 'Afghanistan',
            '6202' => 'Armenia',
            '6203' => 'Azerbaijan',
            '6204' => 'Georgia',
            '6205' => 'Kazakhstan',
            '6206' => 'Kyrgyzstan',
            '6207' => 'Tajikistan',
            '6208' => 'Turkmenistan',
            '6211' => 'Uzbekistan',
            '7101' => 'Bahrain',
            '7102' => 'Gaza Strip and West Bank',
            '7103' => 'Iran',
            '7104' => 'Iraq',
            '7105' => 'Israel',
            '7106' => 'Jordan',
            '7107' => 'Kuwait',
            '7108' => 'Lebanon',
            '7111' => 'Oman',
            '7112' => 'Qatar',
            '7113' => 'Saudi Arabia',
            '7114' => 'Syria',
            '7115' => 'Turkey',
            '7116' => 'United Arab Emirates',
            '7117' => 'Yemen',
            '8101' => 'Algeria',
            '8102' => 'Egypt',
            '8103' => 'Libya',
            '8104' => 'Morocco',
            '8105' => 'Sudan',
            '8106' => 'Tunisia',
            '8107' => 'Western Sahara',
            '8108' => 'South Sudan',
            '9101' => 'Benin',
            '9102' => 'Burkina Faso',
            '9103' => 'Cameroon',
            '9104' => 'Cabo Verde',
            '9105' => "Cote d'Ivoire",
            '9106' => 'Gambia',
            '9107' => 'Ghana',
            '9108' => 'Guinea',
            '9111' => 'Guinea-Bissau',
            '9112' => 'Liberia',
            '9113' => 'Mali',
            '9114' => 'Mauritania',
            '9115' => 'Niger',
            '9116' => 'Nigeria',
            '9117' => 'Senegal',
            '9118' => 'Sierra Leone',
            '9121' => 'Togo',
            '9201' => 'Angola',
            '9202' => 'Botswana',
            '9203' => 'Burundi',
            '9204' => 'Central African Republic',
            '9205' => 'Chad',
            '9206' => 'Comoros',
            '9207' => 'Congo, Republic of',
            '9208' => 'Congo, Democratic Republic of',
            '9211' => 'Djibouti',
            '9212' => 'Equatorial Guinea',
            '9213' => 'Eritrea',
            '9214' => 'Eswatini',
            '9215' => 'Ethiopia',
            '9216' => 'Gabon',
            '9217' => 'Kenya',
            '9218' => 'Lesotho',
            '9221' => 'Madagascar',
            '9222' => 'Malawi',
            '9223' => 'Mauritius',
            '9224' => 'Mayotte',
            '9225' => 'Mozambique',
            '9226' => 'Namibia',
            '9227' => 'Reunion',
            '9228' => 'Rwanda',
            '9231' => 'St Helena',
            '9232' => 'Sao Tome and Principe',
            '9233' => 'Seychelles',
            '9234' => 'Somalia',
            '9235' => 'South Africa',
            '9236' => 'Tanzania',
            '9237' => 'Uganda',
            '9238' => 'Zambia',
            '9241' => 'Zimbabwe',
            '8201' => 'Bermuda',
            '8202' => 'Canada',
            '8203' => 'St Pierre and Miquelon',
            '8204' => 'United States of America',
            '8301' => 'Argentina',
            '8302' => 'Bolivia',
            '8303' => 'Brazil',
            '8304' => 'Chile',
            '8305' => 'Colombia',
            '8306' => 'Ecuador',
            '8307' => 'Falkland Islands',
            '8308' => 'French Guiana',
            '8311' => 'Guyana',
            '8312' => 'Paraguay',
            '8313' => 'Peru',
            '8314' => 'Suriname',
            '8315' => 'Uruguay',
            '8316' => 'Venezuela',
            '8401' => 'Belize',
            '8402' => 'Costa Rica',
            '8403' => 'El Salvador',
            '8404' => 'Guatemala',
            '8405' => 'Honduras',
            '8406' => 'Mexico',
            '8407' => 'Nicaragua',
            '8408' => 'Panama',
            '8501' => 'Anguilla',
            '8502' => 'Antigua and Barbuda',
            '8503' => 'Aruba',
            '8504' => 'Bahamas',
            '8505' => 'Barbados',
            '8506' => 'Cayman Islands',
            '8507' => 'Cuba',
            '8508' => 'Curacao',
            '8509' => 'Dominica',
            '8511' => 'Dominican Republic',
            '8512' => 'Grenada',
            '8513' => 'Guadeloupe',
            '8514' => 'Haiti',
            '8515' => 'Jamaica',
            '8516' => 'Martinique',
            '8517' => 'Montserrat',
            '8518' => 'Puerto Rico',
            '8521' => 'St Kitts and Nevis',
            '8522' => 'St Lucia',
            '8523' => 'St Vincent and the Grenadines',
            '8524' => 'Sint Maarten (Dutch part)',
            '8525' => 'Trinidad and Tobago',
            '8526' => 'Turks and Caicos Islands',
            '8527' => 'Virgin Islands, British',
            '8528' => 'Virgin Islands, United States',
            '9999' => 'Not stated',
        ];
    }

    public static function get_language_codes() {
        return [
            '0001' => 'Inadequately described',
            '1101' => 'Afrikaans',
            '1102' => 'Dutch',
            '1103' => 'Frisian',
            '1199' => 'Germanic Languages, nec',
            '1201' => 'English',
            '1301' => 'German',
            '1302' => 'Luxembourgish',
            '1303' => 'Swiss German',
            '1401' => 'Yiddish',
            '1501' => 'Danish',
            '1502' => 'Icelandic',
            '1503' => 'Norwegian',
            '1504' => 'Swedish',
            '2101' => 'French',
            '2201' => 'Catalan',
            '2301' => 'Italian',
            '2302' => 'Sardinian',
            '2401' => 'Portuguese',
            '2501' => 'Romanian',
            '2901' => 'Spanish',
            '3101' => 'Greek',
            '3201' => 'Belarusian',
            '3202' => 'Russian',
            '3203' => 'Ukrainian',
            '3301' => 'Bulgarian',
            '3302' => 'Macedonian',
            '3303' => 'Serbian',
            '3304' => 'Slovene',
            '3305' => 'Bosnian',
            '3306' => 'Croatian',
            '3401' => 'Czech',
            '3402' => 'Polish',
            '3403' => 'Slovak',
            '3404' => 'Sorbian',
            '3501' => 'Latvian',
            '3502' => 'Lithuanian',
            '3601' => 'Estonian',
            '3602' => 'Finnish',
            '3603' => 'Hungarian',
            '3701' => 'Albanian',
            '3801' => 'Armenian',
            '3901' => 'Romani',
            '4101' => 'Arabic',
            '4102' => 'Assyrian Neo-Aramaic',
            '4103' => 'Chaldean Neo-Aramaic',
            '4104' => 'Hebrew',
            '4105' => 'Mandaic',
            '4106' => 'Maltese',
            '4201' => 'Iranic Languages',
            '4202' => 'Dari',
            '4203' => 'Kurdish',
            '4204' => 'Persian (excluding Dari)',
            '4205' => 'Pashto',
            '4301' => 'Turkish',
            '4302' => 'Turkmen',
            '4303' => 'Azeri',
            '4304' => 'Kazakh',
            '4305' => 'Uighur',
            '4306' => 'Uzbek',
            '5101' => 'Burmese',
            '5102' => 'Karen',
            '5103' => 'Lao',
            '5104' => 'Thai',
            '5201' => 'Khmer',
            '5202' => 'Mon',
            '5203' => 'Vietnamese',
            '5301' => 'Bisaya',
            '5302' => 'Cebuano',
            '5303' => 'Hiligaynon',
            '5304' => 'Ilocano',
            '5305' => 'Tagalog',
            '5306' => 'Waray',
            '5307' => 'Filipino',
            '5401' => 'Bahasa Indonesia',
            '5402' => 'Bahasa Malaysia',
            '5403' => 'Javanese',
            '5404' => 'Madurese',
            '5405' => 'Sundanese',
            '5406' => 'Tetum',
            '5501' => 'Hmong',
            '5502' => 'Mien',
            '6101' => 'Cantonese',
            '6102' => 'Hakka',
            '6103' => 'Mandarin',
            '6104' => 'Hokkien',
            '6105' => 'Teochew',
            '6106' => 'Shanghainese',
            '6107' => 'Wu',
            '6199' => 'Chinese, nec',
            '6201' => 'Japanese',
            '6301' => 'Korean',
            '7101' => 'Assamese',
            '7102' => 'Bengali',
            '7103' => 'Bihari',
            '7104' => 'Gujarati',
            '7105' => 'Hindi',
            '7106' => 'Konkani',
            '7107' => 'Marathi',
            '7108' => 'Nepali',
            '7109' => 'Oriya',
            '7110' => 'Punjabi',
            '7111' => 'Sindhi',
            '7112' => 'Urdu',
            '7113' => 'Kashmiri',
            '7114' => 'Sinhalese',
            '7199' => 'Indo-Aryan, nec',
            '7201' => 'Kannada',
            '7202' => 'Malayalam',
            '7203' => 'Tamil',
            '7204' => 'Telugu',
            '7205' => 'Tulu',
            '7299' => 'Dravidian, nec',
            '8101' => 'Somali',
            '8102' => 'Oromo',
            '8103' => 'Amharic',
            '8104' => 'Tigrinya',
            '8105' => 'Tigre',
            '8201' => 'Swahili',
            '8202' => 'Shona',
            '8203' => 'Kirundi',
            '8204' => 'Kinyarwanda',
            '8205' => 'Acholi',
            '8206' => 'Luganda',
            '8207' => 'Dinka',
            '8208' => 'Luo',
            '8209' => 'Ndebele',
            '8210' => 'Sotho, Southern',
            '8211' => 'Tswana',
            '8212' => 'Xhosa',
            '8213' => 'Zulu',
            '8301' => 'Akan',
            '8302' => 'Ewe',
            '8303' => 'Ga',
            '8304' => 'Hausa',
            '8305' => 'Igbo',
            '8306' => 'Temne',
            '8307' => 'Yoruba',
            '8308' => 'Wolof',
            '8401' => 'Nuer',
            '8402' => 'Sudanese Arabic',
            '9101' => 'Fijian',
            '9102' => 'Gilbertese',
            '9103' => 'Nauruan',
            '9104' => 'Rotuman',
            '9105' => 'Tongan',
            '9106' => 'Cook Islands Maori',
            '9107' => 'Hawaiian',
            '9108' => 'Maori (New Zealand)',
            '9109' => 'Niuean',
            '9110' => 'Samoan',
            '9111' => 'Tahitian',
            '9112' => 'Tokelauan',
            '9113' => 'Tuvaluan',
            '9199' => 'Oceanian Pidgins and Creoles, nec',
            '9201' => 'Tok Pisin',
            '9202' => 'Solomon Islands Pijin',
            '9203' => 'Bislama',
            '9301' => 'Drehu',
            '9302' => 'Ajië',
            '9399' => 'New Caledonian Languages, nec',
            '9401' => 'Australian Indigenous Languages, nec',
            '9402' => 'Arrernte',
            '9403' => 'Burarra',
            '9404' => 'Djambarrpuyngu',
            '9405' => 'Kriol',
            '9406' => 'Murrinh-Patha',
            '9407' => 'Pitjantjatjara',
            '9408' => 'Tiwi',
            '9409' => 'Warlpiri',
            '9410' => 'Torres Strait Creole',
            '9411' => 'Yolngu Matha',
            '9501' => 'Auslan',
            '9502' => 'Signed Languages',
            '9601' => 'Esperanto',
            '9999' => 'Not stated',
        ];
    }

    public static function get_indigenous_status_codes() {
        return [
            '@' => 'Not stated',
            '1' => 'Yes, Aboriginal',
            '2' => 'Yes, Torres Strait Islander',
            '3' => 'Yes, Both Aboriginal and Torres Strait Islander',
            '4' => 'No',
        ];
    }

    public static function get_disability_codes() {
        return [
            'Y' => 'Yes',
            'N' => 'No',
            '@' => 'Not stated',
        ];
    }

    public static function get_disability_type_codes() {
        return [
            '11' => 'Hearing/Deaf',
            '12' => 'Physical',
            '13' => 'Intellectual',
            '14' => 'Learning',
            '15' => 'Mental illness',
            '16' => 'Acquired brain impairment',
            '17' => 'Vision',
            '18' => 'Medical condition',
            '19' => 'Other',
            '99' => 'Unspecified',
        ];
    }

    public static function get_prior_education_codes() {
        return [
            '008' => 'Bachelor Degree or Higher Degree level',
            '410' => 'Advanced Diploma or Associate Degree Level',
            '420' => 'Diploma Level',
            '511' => 'Certificate IV',
            '514' => 'Certificate III',
            '521' => 'Certificate II',
            '524' => 'Certificate I',
            '990' => 'Miscellaneous Education',
            '@@' => 'Not stated',
        ];
    }

    public static function get_school_level_codes() {
        return [
            '02' => 'Did not go to school',
            '08' => 'Year 8 or below',
            '09' => 'Year 9 or equivalent',
            '10' => 'Year 10 or equivalent',
            '11' => 'Year 11 or equivalent',
            '12' => 'Year 12 or equivalent',
            '@@' => 'Not stated',
        ];
    }

    public static function get_labour_force_status_codes() {
        return [
            '01' => 'Full-time employee',
            '02' => 'Part-time employee',
            '03' => 'Self-employed - not employing others',
            '04' => 'Employer',
            '05' => 'Employed - unpaid worker in a family business',
            '06' => 'Unemployed - seeking full-time work',
            '07' => 'Unemployed - seeking part-time work',
            '08' => 'Not employed - not seeking employment',
            '@@' => 'Not stated',
        ];
    }

    public static function get_study_reason_codes() {
        return [
            '01' => 'To get a job',
            '02' => 'To develop my existing business',
            '03' => 'To start my own business',
            '04' => 'To try for a different career',
            '05' => 'To get a better job or promotion',
            '06' => 'It was a requirement of my job',
            '07' => 'I wanted extra skills for my job',
            '08' => 'To get into another course of study',
            '11' => 'For personal interest or self-development',
            '12' => 'Other reasons',
            '@@' => 'Not specified',
        ];
    }

    public static function get_state_codes() {
        return [
            '01' => 'New South Wales',
            '02' => 'Victoria',
            '03' => 'Queensland',
            '04' => 'South Australia',
            '05' => 'Western Australia',
            '06' => 'Tasmania',
            '07' => 'Northern Territory',
            '08' => 'Australian Capital Territory',
            '09' => 'Other Australian Territories or Dependencies',
            '99' => 'Other (Overseas but not an Australian Territory or Dependency)',
        ];
    }

    public static function get_delivery_mode_codes() {
        return [
            'N' => 'Not applicable - recognition of prior learning / credit transfer / recognition of current competency',
            'I' => 'Internal - Classroom delivery',
            'E' => 'External - Self-paced/Distance education/Online',
            'W' => 'Workplace based',
            'C' => 'Combination of modes',
        ];
    }

    public static function get_funding_source_codes() {
        return [
            '10' => 'Commonwealth and state general recurrent',
            '11' => 'Commonwealth specific purpose programs',
            '12' => 'State specific purpose programs',
            '13' => 'Domestic fee for service',
            '14' => 'International full fee-paying',
            '15' => 'International onshore fee-paying',
            '20' => 'Fee for service (non-government funded)',
            '30' => 'Revenue earned from another RTO',
        ];
    }

    public static function get_funding_source_national_codes() {
        return [
            '11' => 'Commonwealth recurrent funding',
            '13' => 'Commonwealth specific funding purpose programs',
            '15' => 'State recurrent funding',
            '20' => 'Domestic fee for service',
            '30' => 'International fee for service',
        ];
    }

    /**
     * Return state-specific funding source codes for AVETMISS "below the line" reporting.
     * Each State Training Authority (STA) uses its own code set beyond the national standard.
     *
     * @param string $state Two or three letter state code: QLD, NSW, VIC, SA, WA, TAS, NT, ACT
     * @return array  code => description
     */
    public static function get_state_funding_source_codes(string $state): array {
        $codes = [
            'QLD' => [
                ''    => '— Not specified —',
                'B01' => 'B01 — Career Boost (eligible job seekers & mature age)',
                'S01' => 'S01 — Career Start (young people 15–24)',
                'QL1' => 'QL1 — Certificate 3 Guarantee',
                'QC1' => 'QC1 — Higher Level Skills (Certificate IV and above)',
                'UC1' => 'UC1 — User Choice (Apprenticeships & Traineeships)',
                'B11' => 'B11 — Skills and Jobs Centres',
                'B02' => 'B02 — Skilling Queenslanders for Work',
                'VE1' => 'VE1 — VET in Schools (VETiS)',
                'QNS' => 'QNS — Not state government funded',
            ],
            'NSW' => [
                ''    => '— Not specified —',
                '22'  => '22 — Smart and Skilled (government subsidised)',
                '23'  => '23 — NSW Apprenticeships/Traineeships',
                '24'  => '24 — NSW Fee for Service',
                '25'  => '25 — NSW VET in Schools',
                '26'  => '26 — NSW Language, Literacy and Numeracy (LLN)',
            ],
            'VIC' => [
                ''     => '— Not specified —',
                'VSKI' => 'VSKI — Skills First (government subsidised)',
                'VHLS' => 'VHLS — Higher Level Skills',
                'VLLN' => 'VLLN — Language, Literacy and Numeracy',
                'VFFS' => 'VFFS — Fee for Service',
                'VAPP' => 'VAPP — Apprenticeship/Traineeship',
                'VVIS' => 'VVIS — VET in Schools',
            ],
            'SA'  => [
                ''     => '— Not specified —',
                'SK1'  => 'SK1 — Skills for All (subsidised)',
                'SApp' => 'SApp — SA Apprenticeship/Traineeship (User Choice)',
                'SFFS' => 'SFFS — Fee for Service',
                'SVIS' => 'SVIS — VET in Schools',
            ],
            'WA'  => [
                ''     => '— Not specified —',
                'WA1'  => 'WA1 — DTWD Government Subsidised',
                'WApp' => 'WApp — WA Apprenticeship/Traineeship',
                'WFFS' => 'WFFS — Fee for Service',
                'WVIS' => 'WVIS — VET in Schools',
                'WAOP' => 'WAOP — WA On-the-Job Training',
            ],
            'TAS' => [
                ''     => '— Not specified —',
                'STF'  => 'STF — Skills Tasmania Funded',
                'TApp' => 'TApp — TAS Apprenticeship/Traineeship',
                'TFFS' => 'TFFS — Fee for Service',
                'TVIS' => 'TVIS — VET in Schools',
            ],
            'NT'  => [
                ''     => '— Not specified —',
                'NT1'  => 'NT1 — NT Government Funded (DITT)',
                'NApp' => 'NApp — NT Apprenticeship/Traineeship',
                'NFFS' => 'NFFS — Fee for Service',
                'NVIS' => 'NVIS — VET in Schools',
            ],
            'ACT' => [
                ''     => '— Not specified —',
                'AC1'  => 'AC1 — ACT Subsidised Training (Skills Canberra)',
                'AApp' => 'AApp — ACT Apprenticeship/Traineeship',
                'AFFS' => 'AFFS — Fee for Service',
                'AVIS' => 'AVIS — VET in Schools',
            ],
        ];
        return $codes[strtoupper($state)] ?? ['' => '— Not specified —'];
    }

    /**
     * Return fee concession status codes used in state-funded training reporting.
     * Applied per enrolment to indicate whether the student paid full, concessional,
     * or an exempt/waived fee.
     *
     * @return array  code => description
     */
    public static function get_concession_status_codes(): array {
        return [
            ''  => '— Not specified —',
            'F' => 'F — Full fee (non-concessional)',
            'C' => 'C — Concessional rate',
            'E' => 'E — Exempt / Fee waived',
        ];
    }

    public static function get_sex_codes() {
        return [
            'M' => 'Male',
            'F' => 'Female',
            '@' => 'Not stated/inadequately described',
        ];
    }

    public static function get_english_proficiency_codes() {
        return [
            '1' => 'Very well',
            '2' => 'Well',
            '3' => 'Not well',
            '4' => 'Not at all',
            '@' => 'Not stated',
        ];
    }

    public static function get_commencing_program_codes() {
        return [
            '1' => 'Commencing enrolment in the program',
            '2' => 'Did not commence',
            '3' => 'Continuing enrolment in the program from a previous year',
            '4' => 'Recommencing enrolment in the program',
        ];
    }

    public static function get_program_outcome_codes() {
        return [
            '01' => 'Qualification issued - AQF level',
            '02' => 'Qualification issued - non-AQF level',
            '03' => 'Qualification not issued - program still in progress',
            '04' => 'Qualification not issued - student withdrawn',
            '05' => 'Qualification not issued - other reason',
        ];
    }

    public static function get_delivery_mode_nat_codes() {
        return [
            '10' => 'Classroom-based',
            '20' => 'Electronic-based',
            '30' => 'Employment-based',
            '40' => 'Other delivery',
            '90' => 'Not applicable (RPL/Credit Transfer)',
        ];
    }

    public static function get_vet_flag_codes() {
        return [
            'Y' => 'Yes - Nationally accredited VET program',
            'N' => 'No - Non-accredited program',
        ];
    }

    public static function get_fee_charged_codes() {
        return [
            'Y' => 'Fee charged',
            'N' => 'No fee charged',
            'P' => 'Partial fee charged (concession)',
        ];
    }

    public static function get_at_school_flag_codes() {
        return [
            'Y' => 'Currently attending secondary school',
            'N' => 'Not attending secondary school',
        ];
    }

    public static function get_survey_contact_codes() {
        return [
            'A' => 'Agrees to be contacted',
            'E' => 'Has a valid excuse',
            'M' => 'No mail contact possible',
            'N' => 'Does not agree to be contacted',
        ];
    }

    public static function get_prior_education_flag_codes() {
        return [
            'Y' => 'Has successfully completed a prior qualification',
            'N' => 'Has not completed a prior qualification',
            '@' => 'Not stated',
        ];
    }

    public static function get_certificate_types() {
        return [
            'testamur' => [
                'name' => 'Qualification (Testamur)',
                'description' => 'Issued when student completes ALL requirements of a full qualification (core + required electives)',
                'requires' => 'Full qualification completion with all outcomes finalized',
                'documents' => ['Testamur', 'Record of Results'],
            ],
            'statement' => [
                'name' => 'Statement of Attainment',
                'description' => 'Issued for completed units of competency that do not form a complete qualification',
                'requires' => 'At least one unit with competent outcome (20, 51, 52, 60, 81, 82)',
                'documents' => ['Statement of Attainment'],
            ],
            'record' => [
                'name' => 'Record of Results',
                'description' => 'Accompanies Testamur showing all units and outcomes achieved',
                'requires' => 'Issued with Testamur only',
                'documents' => ['Record of Results'],
            ],
            'attendance' => [
                'name' => 'Certificate of Attendance',
                'description' => 'For non-accredited training or participation without competency assessment',
                'requires' => 'Participation in non-accredited course',
                'documents' => ['Certificate of Attendance'],
            ],
        ];
    }

    public static function validate_usi($usi) {
        if (empty($usi)) {
            return ['valid' => false, 'error' => 'USI is required'];
        }
        
        $usi = strtoupper(trim($usi));
        
        if (strlen($usi) != 10) {
            return ['valid' => false, 'error' => 'USI must be exactly 10 characters'];
        }
        
        if (!preg_match('/^[2-9A-HJ-NP-Z]{10}$/', $usi)) {
            return ['valid' => false, 'error' => 'USI contains invalid characters (no 0, 1, I, or O allowed)'];
        }
        
        return ['valid' => true, 'usi' => $usi];
    }

    public static function validate_postcode($postcode, $state) {
        $postcode = trim($postcode);
        
        if (!preg_match('/^\d{4}$/', $postcode)) {
            return ['valid' => false, 'error' => 'Postcode must be 4 digits'];
        }
        
        $firstdigit = substr($postcode, 0, 1);
        $stateranges = [
            '01' => ['2'],
            '02' => ['3'],
            '03' => ['4'],
            '04' => ['5'],
            '05' => ['6'],
            '06' => ['7'],
            '07' => ['0', '8'],
            '08' => ['0', '2'],
        ];
        
        if (isset($stateranges[$state])) {
            if (!in_array($firstdigit, $stateranges[$state])) {
                return ['valid' => false, 'error' => 'Postcode does not match state'];
            }
        }
        
        return ['valid' => true, 'postcode' => $postcode];
    }

    public static function get_mandatory_avetmiss_fields() {
        return [
            'personal' => ['firstname', 'lastname', 'dateofbirth', 'sex'],
            'contact' => ['address', 'suburb', 'postcode', 'state'],
            'demographic' => ['countryofbirth', 'languageathome', 'atsi', 'disability', 'prioreducation', 'schoollevel', 'employmentstatus', 'studyreason'],
            'enrolment' => ['fundingsource', 'deliverymode', 'outcome', 'startdate'],
        ];
    }
}
