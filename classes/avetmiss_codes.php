<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RTO Compliance plugin — avetmiss_codes.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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

    /**
     * SACC country identifiers, verbatim from NCVER's own system file.
     *
     * SOURCE OF TRUTH: countryidentifier-revised26Nov2025.txt, published by NCVER at
     * https://www.ncver.edu.au/rto-hub/statistical-standard-software/country
     * A two-column copy ships at db/codelists/countryidentifier.txt (NCVER's
     * last_update column dropped, nothing else altered) and
     * avetmiss_codelist_test.php fails if this array and that file ever disagree.
     *
     * DO NOT hand-edit this array. Re-download the file, replace the copy in
     * db/codelists/, and regenerate. The list this replaced in v6.3.32 and earlier
     * was NOT a damaged copy of SACC - it was a different, partly invented
     * classification. It put the Virgin Islands at 8527/8528 (SACC says 8427/8428),
     * omitted 2102 England and every 2xxx UK constituent, and carried '9999' =>
     * 'Not stated', which is not a SACC identifier at all. On one production site
     * 798 of 948 non-Australian students (84%) held a country that either does not
     * exist in SACC or names a different country than the label the operator clicked.
     *
     * '@@@@' is NCVER's own not-specified value and is the last row of their file.
     * It is NOT a padding artefact - it is what you submit when the field is unknown.
     */
    public static function get_country_codes() {
        return [
            '0000' => 'Inadequately Described',
            '0001' => 'At Sea',
            '0911' => 'Europe, nfd',
            '0912' => 'Former USSR, nfd',
            '0915' => 'Kurdistan, nfd',
            '0916' => 'East Asia, nfd',
            '0917' => 'Asia, nfd',
            '0918' => 'Africa, nfd',
            '1000' => 'Oceania and Antarctica',
            '1100' => 'Australia (includes External Territories)',
            '1101' => 'Australia',
            '1102' => 'Norfolk Island',
            '1199' => 'Australian External Territories, nec',
            '1201' => 'New Zealand',
            '1300' => 'Melanesia',
            '1301' => 'New Caledonia',
            '1302' => 'Papua New Guinea',
            '1303' => 'Solomon Islands',
            '1304' => 'Vanuatu',
            '1400' => 'Micronesia',
            '1401' => 'Guam',
            '1402' => 'Kiribati',
            '1403' => 'Marshall Islands',
            '1404' => 'Micronesia, Federated States of',
            '1405' => 'Nauru',
            '1406' => 'Northern Mariana Islands',
            '1407' => 'Palau',
            '1500' => 'Polynesia (excludes Hawaii)',
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
            '1600' => 'Antarctica',
            '1601' => 'Adelie Land (France)',
            '1602' => 'Argentinian Antarctic Territory',
            '1603' => 'Australian Antarctic Territory',
            '1604' => 'British Antarctic Territory',
            '1605' => 'Chilean Antarctic Territory',
            '1606' => 'Queen Maud Land (Norway)',
            '1607' => 'Ross Dependency (New Zealand)',
            '2000' => 'North-West Europe',
            '2100' => 'United Kingdom, Channel Islands and Isle of Man',
            '2102' => 'England',
            '2103' => 'Isle of Man',
            '2104' => 'Northern Ireland',
            '2105' => 'Scotland',
            '2106' => 'Wales',
            '2107' => 'Guernsey',
            '2108' => 'Jersey',
            '2201' => 'Ireland',
            '2300' => 'Western Europe',
            '2301' => 'Austria',
            '2302' => 'Belgium',
            '2303' => 'France',
            '2304' => 'Germany',
            '2305' => 'Liechtenstein',
            '2306' => 'Luxembourg',
            '2307' => 'Monaco',
            '2308' => 'Netherlands',
            '2311' => 'Switzerland',
            '2400' => 'Northern Europe',
            '2401' => 'Denmark',
            '2402' => 'Faroe Islands',
            '2403' => 'Finland',
            '2404' => 'Greenland',
            '2405' => 'Iceland',
            '2406' => 'Norway',
            '2407' => 'Sweden',
            '2408' => 'Aland Islands',
            '3000' => 'Southern and Eastern Europe',
            '3100' => 'Southern Europe',
            '3101' => 'Andorra',
            '3102' => 'Gibraltar',
            '3103' => 'Holy See',
            '3104' => 'Italy',
            '3105' => 'Malta',
            '3106' => 'Portugal',
            '3107' => 'San Marino',
            '3108' => 'Spain',
            '3200' => 'South Eastern Europe',
            '3201' => 'Albania',
            '3202' => 'Bosnia and Herzegovina',
            '3203' => 'Bulgaria',
            '3204' => 'Croatia',
            '3205' => 'Cyprus',
            '3206' => 'North Macedonia',
            '3207' => 'Greece',
            '3208' => 'Moldova',
            '3211' => 'Romania',
            '3212' => 'Slovenia',
            '3214' => 'Montenegro',
            '3215' => 'Serbia',
            '3216' => 'Kosovo',
            '3300' => 'Eastern Europe',
            '3301' => 'Belarus',
            '3302' => 'Czechia',
            '3303' => 'Estonia',
            '3304' => 'Hungary',
            '3305' => 'Latvia',
            '3306' => 'Lithuania',
            '3307' => 'Poland',
            '3308' => 'Russian Federation',
            '3311' => 'Slovakia',
            '3312' => 'Ukraine',
            '4000' => 'North Africa and the Middle East',
            '4100' => 'North Africa',
            '4101' => 'Algeria',
            '4102' => 'Egypt',
            '4103' => 'Libya',
            '4104' => 'Morocco',
            '4105' => 'Sudan',
            '4106' => 'Tunisia',
            '4107' => 'Western Sahara',
            '4108' => 'Spanish North Africa',
            '4111' => 'South Sudan',
            '4200' => 'Middle East',
            '4201' => 'Bahrain',
            '4202' => 'Palestine',
            '4203' => 'Iran',
            '4204' => 'Iraq',
            '4205' => 'Israel',
            '4206' => 'Jordan',
            '4207' => 'Kuwait',
            '4208' => 'Lebanon',
            '4211' => 'Oman',
            '4212' => 'Qatar',
            '4213' => 'Saudi Arabia',
            '4214' => 'Syria',
            '4215' => 'Turkiye',
            '4216' => 'United Arab Emirates',
            '4217' => 'Yemen',
            '5000' => 'South-East Asia',
            '5100' => 'Mainland South-East Asia',
            '5101' => 'Myanmar',
            '5102' => 'Cambodia',
            '5103' => 'Laos',
            '5104' => 'Thailand',
            '5105' => 'Vietnam',
            '5200' => 'Maritime South-East Asia',
            '5201' => 'Brunei Darussalam',
            '5202' => 'Indonesia',
            '5203' => 'Malaysia',
            '5204' => 'Philippines',
            '5205' => 'Singapore',
            '5206' => 'Timor-Leste',
            '6000' => 'North-East Asia',
            '6100' => 'Chinese Asia (includes Mongolia)',
            '6101' => 'China (excludes SARs and Taiwan)',
            '6102' => 'Hong Kong (SAR of China)',
            '6103' => 'Macau (SAR of China)',
            '6104' => 'Mongolia',
            '6105' => 'Taiwan',
            '6200' => 'Japan and the Koreas',
            '6201' => 'Japan',
            '6202' => 'Korea, Democratic People\'s Republic of (North)',
            '6203' => 'Korea, Republic of (South)',
            '7000' => 'Southern and Central Asia',
            '7100' => 'Southern Asia',
            '7101' => 'Bangladesh',
            '7102' => 'Bhutan',
            '7103' => 'India',
            '7104' => 'Maldives',
            '7105' => 'Nepal',
            '7106' => 'Pakistan',
            '7107' => 'Sri Lanka',
            '7200' => 'Central Asia',
            '7201' => 'Afghanistan',
            '7202' => 'Armenia',
            '7203' => 'Azerbaijan',
            '7204' => 'Georgia',
            '7205' => 'Kazakhstan',
            '7206' => 'Kyrgyzstan',
            '7207' => 'Tajikistan',
            '7208' => 'Turkmenistan',
            '7211' => 'Uzbekistan',
            '8000' => 'Americas',
            '8100' => 'Northern America',
            '8101' => 'Bermuda',
            '8102' => 'Canada',
            '8103' => 'St Pierre and Miquelon',
            '8104' => 'United States of America',
            '8200' => 'South America',
            '8201' => 'Argentina',
            '8202' => 'Bolivia',
            '8203' => 'Brazil',
            '8204' => 'Chile',
            '8205' => 'Colombia',
            '8206' => 'Ecuador',
            '8207' => 'Falkland Islands',
            '8208' => 'French Guiana',
            '8211' => 'Guyana',
            '8212' => 'Paraguay',
            '8213' => 'Peru',
            '8214' => 'Suriname',
            '8215' => 'Uruguay',
            '8216' => 'Venezuela',
            '8299' => 'South America, nec',
            '8300' => 'Central America',
            '8301' => 'Belize',
            '8302' => 'Costa Rica',
            '8303' => 'El Salvador',
            '8304' => 'Guatemala',
            '8305' => 'Honduras',
            '8306' => 'Mexico',
            '8307' => 'Nicaragua',
            '8308' => 'Panama',
            '8400' => 'Caribbean',
            '8401' => 'Anguilla',
            '8402' => 'Antigua and Barbuda',
            '8403' => 'Aruba',
            '8404' => 'Bahamas',
            '8405' => 'Barbados',
            '8406' => 'Cayman Islands',
            '8407' => 'Cuba',
            '8408' => 'Dominica',
            '8411' => 'Dominican Republic',
            '8412' => 'Grenada',
            '8413' => 'Guadeloupe',
            '8414' => 'Haiti',
            '8415' => 'Jamaica',
            '8416' => 'Martinique',
            '8417' => 'Montserrat',
            '8421' => 'Puerto Rico',
            '8422' => 'St Kitts and Nevis',
            '8423' => 'St Lucia',
            '8424' => 'St Vincent and the Grenadines',
            '8425' => 'Trinidad and Tobago',
            '8426' => 'Turks and Caicos Islands',
            '8427' => 'Virgin Islands, British',
            '8428' => 'Virgin Islands, United States',
            '8431' => 'St Barthelemy',
            '8432' => 'St Martin (French part)',
            '8433' => 'Bonaire, Sint Eustatius and Saba',
            '8434' => 'Curacao',
            '8435' => 'Sint Maarten (Dutch part)',
            '9000' => 'Sub-Saharan Africa',
            '9100' => 'Central and West Africa',
            '9101' => 'Benin',
            '9102' => 'Burkina Faso',
            '9103' => 'Cameroon',
            '9104' => 'Cape Verde',
            '9105' => 'Central African Republic',
            '9106' => 'Chad',
            '9107' => 'Congo, Republic of',
            '9108' => 'Congo, Democratic Republic of',
            '9111' => 'Cote d\'Ivoire',
            '9112' => 'Equatorial Guinea',
            '9113' => 'Gabon',
            '9114' => 'Gambia',
            '9115' => 'Ghana',
            '9116' => 'Guinea',
            '9117' => 'Guinea-Bissau',
            '9118' => 'Liberia',
            '9121' => 'Mali',
            '9122' => 'Mauritania',
            '9123' => 'Niger',
            '9124' => 'Nigeria',
            '9125' => 'Sao Tome and Principe',
            '9126' => 'Senegal',
            '9127' => 'Sierra Leone',
            '9128' => 'Togo',
            '9200' => 'Southern and East Africa',
            '9201' => 'Angola',
            '9202' => 'Botswana',
            '9203' => 'Burundi',
            '9204' => 'Comoros',
            '9205' => 'Djibouti',
            '9206' => 'Eritrea',
            '9207' => 'Ethiopia',
            '9208' => 'Kenya',
            '9211' => 'Lesotho',
            '9212' => 'Madagascar',
            '9213' => 'Malawi',
            '9214' => 'Mauritius',
            '9215' => 'Mayotte',
            '9216' => 'Mozambique',
            '9217' => 'Namibia',
            '9218' => 'Reunion',
            '9221' => 'Rwanda',
            '9222' => 'St Helena',
            '9223' => 'Seychelles',
            '9224' => 'Somalia',
            '9225' => 'South Africa',
            '9226' => 'Eswatini',
            '9227' => 'Tanzania',
            '9228' => 'Uganda',
            '9231' => 'Zambia',
            '9232' => 'Zimbabwe',
            '9299' => 'Southern and East Africa, nec',
            '@@@@' => 'Not specified',
        ];
    }

    /**
     * ASCL language identifiers, verbatim from NCVER's own system file.
     *
     * SOURCE OF TRUTH: language_systemfile_2016.txt, published by NCVER at
     * https://www.ncver.edu.au/rto-hub/statistical-standard-software/Language-identifier
     * A two-column copy ships at db/codelists/language_systemfile.txt and
     * avetmiss_codelist_test.php fails if this array and that file ever disagree.
     *
     * DO NOT hand-edit this array. The list this replaced was wrong from its first
     * entry onward: it had 1101 => Afrikaans, 1102 => Dutch, 1103 => Frisian, where
     * ASCL defines 1101 Gaelic (Scotland), 1102 Irish, 1103 Welsh. It was not ASCL
     * with errors; it was a different scheme wearing ASCL's four-digit shape, which
     * is why nothing about it looked obviously broken on screen. Its first option,
     * '0001', was labelled 'Inadequately described'; ASCL 0001 is 'Non verbal', so
     * every student the browser silently defaulted to the first option was recorded
     * as non-verbal. On one production site only 47.6% of language values were
     * ASCL-valid, 1,542 students held a code the dropdown could not even select, and
     * 1,621 held '9999', which is not an ASCL identifier.
     *
     * Includes the 8xxx Australian Indigenous languages in full. An earlier draft of
     * this fix omitted them because no student on the site under audit used one -
     * which is precisely the reason to ship them: a code you cannot select is a code
     * that gets recorded as something else.
     *
     * '@@@@' is NCVER's own not-specified value and is the last row of their file.
     */
    public static function get_language_codes() {
        return [
            '0000' => 'Unknown',
            '0001' => 'Non verbal',
            '0003' => 'Swiss, so described',
            '0004' => 'Cypriot, so described',
            '0005' => 'Creole, nfd',
            '0006' => 'French Creole, nfd',
            '0007' => 'Spanish Creole, nfd',
            '0008' => 'Portuguese Creole, nfd',
            '0009' => 'Pidgin, nfd',
            '1000' => 'NORTHERN EUROPEAN LANGUAGES',
            '1100' => 'Celtic',
            '1101' => 'Gaelic (Scotland)',
            '1102' => 'Irish',
            '1103' => 'Welsh',
            '1199' => 'Celtic, nec',
            '1201' => 'English',
            '1300' => 'German and Related Languages',
            '1301' => 'German',
            '1302' => 'Letzeburgish',
            '1303' => 'Yiddish',
            '1400' => 'Dutch and Related Languages',
            '1401' => 'Dutch',
            '1402' => 'Frisian',
            '1403' => 'Afrikaans',
            '1500' => 'Scandinavian',
            '1501' => 'Danish',
            '1502' => 'Icelandic',
            '1503' => 'Norwegian',
            '1504' => 'Swedish',
            '1599' => 'Scandinavian, nec',
            '1600' => 'Finnish and Related Languages',
            '1601' => 'Estonian',
            '1602' => 'Finnish',
            '1699' => 'Finnish and Related Languages, nec',
            '2000' => 'SOUTHERN EUROPEAN LANGUAGES',
            '2101' => 'French',
            '2201' => 'Greek',
            '2300' => 'Iberian Romance',
            '2301' => 'Catalan',
            '2302' => 'Portuguese',
            '2303' => 'Spanish',
            '2399' => 'Iberian Romance, nec',
            '2401' => 'Italian',
            '2501' => 'Maltese',
            '2900' => 'Other Southern European Languages',
            '2901' => 'Basque',
            '2902' => 'Latin',
            '2999' => 'Other Southern European Languages, nec',
            '3000' => 'EASTERN EUROPEAN LANGUAGES',
            '3100' => 'Baltic',
            '3101' => 'Latvian',
            '3102' => 'Lithuanian',
            '3301' => 'Hungarian',
            '3400' => 'East Slavic',
            '3401' => 'Belorussian',
            '3402' => 'Russian',
            '3403' => 'Ukrainian',
            '3500' => 'South Slavic',
            '3501' => 'Bosnian',
            '3502' => 'Bulgarian',
            '3503' => 'Croatian',
            '3504' => 'Macedonian',
            '3505' => 'Serbian',
            '3506' => 'Slovene',
            '3507' => 'Serbo-Croatian/Yugoslavian, so described',
            '3600' => 'West Slavic',
            '3601' => 'Czech',
            '3602' => 'Polish',
            '3603' => 'Slovak',
            '3604' => 'Czechoslovakian, so described',
            '3900' => 'Other Eastern European Languages',
            '3901' => 'Albanian',
            '3903' => 'Aromunian (Macedo-Romanian)',
            '3904' => 'Romanian',
            '3905' => 'Romany',
            '3999' => 'Other Eastern European Languages, nec',
            '4000' => 'SOUTHWEST AND CENTRAL ASIAN LANGUAGES',
            '4100' => 'Iranic',
            '4101' => 'Kurdish',
            '4102' => 'Pashto',
            '4104' => 'Balochi',
            '4105' => 'Dari',
            '4106' => 'Persian (excluding Dari)',
            '4107' => 'Hazaraghi',
            '4199' => 'Iranic, nec',
            '4200' => 'Middle Eastern Semitic Languages',
            '4202' => 'Arabic',
            '4204' => 'Hebrew',
            '4206' => 'Assyrian Neo-Aramaic',
            '4207' => 'Chaldean Neo-Aramaic',
            '4208' => 'Mandaean (Mandaic)',
            '4299' => 'Middle Eastern Semitic Languages, nec',
            '4300' => 'Turkic',
            '4301' => 'Turkish',
            '4302' => 'Azeri',
            '4303' => 'Tatar',
            '4304' => 'Turkmen',
            '4305' => 'Uygur',
            '4306' => 'Uzbek',
            '4399' => 'Turkic, nec',
            '4900' => 'Other Southwest and Central Asian Languages',
            '4901' => 'Armenian',
            '4902' => 'Georgian',
            '4999' => 'Other Southwest and Central Asian Languages, nec',
            '5000' => 'SOUTHERN ASIAN LANGUAGES',
            '5100' => 'Dravidian',
            '5101' => 'Kannada',
            '5102' => 'Malayalam',
            '5103' => 'Tamil',
            '5104' => 'Telugu',
            '5105' => 'Tulu',
            '5199' => 'Dravidian, nec',
            '5200' => 'Indo-Aryan',
            '5201' => 'Bengali',
            '5202' => 'Gujarati',
            '5203' => 'Hindi',
            '5204' => 'Konkani',
            '5205' => 'Marathi',
            '5206' => 'Nepali',
            '5207' => 'Punjabi',
            '5208' => 'Sindhi',
            '5211' => 'Sinhalese',
            '5212' => 'Urdu',
            '5213' => 'Assamese',
            '5214' => 'Dhivehi',
            '5215' => 'Kashmiri',
            '5216' => 'Oriya',
            '5217' => 'Fijian Hindustani',
            '5299' => 'Indo-Aryan, nec',
            '5999' => 'Other Southern Asian Languages',
            '6000' => 'SOUTHEAST ASIAN LANGUAGES',
            '6100' => 'Burmese and Related Languages',
            '6101' => 'Burmese',
            '6102' => 'Chin Haka',
            '6103' => 'Karen',
            '6104' => 'Rohingya',
            '6105' => 'Zomi',
            '6199' => 'Burmese and Related Languages, nec',
            '6200' => 'Hmong-Mien',
            '6201' => 'Hmong',
            '6299' => 'Hmong-Mien, nec',
            '6300' => 'Mon-Khmer',
            '6301' => 'Khmer',
            '6302' => 'Vietnamese',
            '6303' => 'Mon',
            '6399' => 'Mon-Khmer, nec',
            '6400' => 'Tai',
            '6401' => 'Lao',
            '6402' => 'Thai',
            '6499' => 'Tai, nec',
            '6500' => 'Southeast Asian Austronesian Languages',
            '6501' => 'Bisaya',
            '6502' => 'Cebuano',
            '6503' => 'IIokano',
            '6504' => 'Indonesian',
            '6505' => 'Malay',
            '6507' => 'Tetum',
            '6508' => 'Timorese',
            '6511' => 'Tagalog',
            '6512' => 'Filipino',
            '6513' => 'Acehnese',
            '6514' => 'Balinese',
            '6515' => 'Bikol',
            '6516' => 'Iban',
            '6517' => 'Ilonggo (Hiligaynon)',
            '6518' => 'Javanese',
            '6521' => 'Pampangan',
            '6599' => 'Southeast Asian Austronesian Languages, nec',
            '6999' => 'Other Southeast Asian Languages',
            '7000' => 'EASTERN ASIAN LANGUAGES',
            '7100' => 'Chinese',
            '7101' => 'Cantonese',
            '7102' => 'Hakka',
            '7104' => 'Mandarin',
            '7106' => 'Wu',
            '7107' => 'Min Nan',
            '7199' => 'Chinese, nec',
            '7201' => 'Japanese',
            '7301' => 'Korean',
            '7900' => 'Other Eastern Asian Languages',
            '7901' => 'Tibetan',
            '7902' => 'Mongolian',
            '7999' => 'Other Eastern Asian Languages, nec',
            '8000' => 'AUSTRALIAN INDIGENOUS LANGUAGES',
            '8100' => 'Arnhem Land and Daly River Region Languages',
            '8101' => 'Anindilyakwa',
            '8111' => 'Maung',
            '8113' => 'Ngan\'gikurunggurr',
            '8114' => 'Nunggubuyu',
            '8115' => 'Rembarrnga',
            '8117' => 'Tiwi',
            '8121' => 'Alawa',
            '8122' => 'Dalabon',
            '8123' => 'Gudanji',
            '8127' => 'Iwaidja',
            '8128' => 'Jaminjung',
            '8131' => 'Jawoyn',
            '8132' => 'Jingulu',
            '8133' => 'Kunbarlang',
            '8136' => 'Larrakiya',
            '8137' => 'Malak Malak',
            '8138' => 'Mangarrayi',
            '8141' => 'Maringarr',
            '8142' => 'Marra',
            '8143' => 'Marrithiyel',
            '8144' => 'Matngala',
            '8146' => 'Murrinh Patha',
            '8147' => 'Na-kara',
            '8148' => 'Ndjébbana (Gunavidji)',
            '8151' => 'Ngalakgan',
            '8152' => 'Ngaliwurru',
            '8153' => 'Nungali',
            '8154' => 'Wambaya',
            '8155' => 'Wardaman',
            '8156' => 'Amurdak',
            '8157' => 'Garrwa',
            '8158' => 'Kuwema',
            '8161' => 'Marramaninyshi',
            '8162' => 'Ngandi',
            '8163' => 'Waanyi',
            '8164' => 'Wagiman',
            '8165' => 'Yanyuwa',
            '8166' => 'Marridan (Maridan)',
            '8170' => 'Kunwinjkuan',
            '8171' => 'Gundjeihmi',
            '8172' => 'Kune',
            '8173' => 'Kuninjku',
            '8174' => 'Kunwinjku',
            '8175' => 'Mayali',
            '8179' => 'Kunwinjkuan, nec',
            '8180' => 'Burarran',
            '8181' => 'Burarra',
            '8182' => 'Gun-nartpa',
            '8183' => 'Gurr-goni',
            '8189' => 'Burarran, nec',
            '8199' => 'Arnhem Land and Daly River Region Languages, nec',
            '8200' => 'Yolngu Matha',
            '8210' => 'Dhangu',
            '8211' => 'Galpu',
            '8212' => 'Golumala',
            '8213' => 'Wangurri',
            '8219' => 'Dhangu, nec',
            '8220' => 'Dhay\'yi',
            '8221' => 'Dhalwangu',
            '8222' => 'Djarrwark',
            '8229' => 'Dhay\'yi, nec',
            '8230' => 'Dhuwal',
            '8231' => 'Djambarrpuyngu',
            '8232' => 'Djapu',
            '8233' => 'Daatiwuy',
            '8234' => 'Marrangu',
            '8235' => 'Liyagalawumirr',
            '8236' => 'Liyagawumirr',
            '8239' => 'Dhuwal, nec',
            '8240' => 'Dhuwala',
            '8242' => 'Gumatj',
            '8243' => 'Gupapuyngu',
            '8244' => 'Guyamirrilili',
            '8246' => 'Manggalili',
            '8247' => 'Wubulkarra',
            '8249' => 'Dhuwala, nec',
            '8250' => 'Djinang',
            '8251' => 'Wurlaki',
            '8259' => 'Djinang, nec',
            '8261' => 'Ganalbingu',
            '8262' => 'Djinba',
            '8263' => 'Manyjalpingu',
            '8269' => 'Djinba, nec',
            '8270' => 'Yakuy',
            '8271' => 'Ritharrngu',
            '8272' => 'Wagilak',
            '8279' => 'Yakuy, nec',
            '8281' => 'Nhangu',
            '8282' => 'Yan-Nhangu',
            '8289' => 'Nhangu, nec',
            '8290' => 'Other Yolngu Matha',
            '8291' => 'Dhuwaya',
            '8292' => 'Djangu',
            '8293' => 'Madarrpa',
            '8294' => 'Warramiri',
            '8295' => 'Rirratjingu',
            '8299' => 'Other Yolngu Matha, nec',
            '8300' => 'Cape York Peninsula Languages',
            '8301' => 'Kuku Yalanji',
            '8302' => 'Guugu Yimidhirr',
            '8303' => 'Kuuku-Ya\'u',
            '8304' => 'Wik Mungkan',
            '8305' => 'Djabugay',
            '8306' => 'Dyirbal',
            '8307' => 'Girramay',
            '8308' => 'Koko-Bera',
            '8311' => 'Kuuk Thayorre',
            '8312' => 'Lamalama',
            '8313' => 'Yidiny',
            '8314' => 'Wik Ngathan',
            '8315' => 'Alngith',
            '8316' => 'Kugu Muminh',
            '8317' => 'Morrobalama',
            '8318' => 'Thaynakwith',
            '8321' => 'Yupangathi',
            '8322' => 'Tjungundji',
            '8399' => 'Cape York Peninsula Languages, nec',
            '8400' => 'Torres Strait Island Languages',
            '8401' => 'Kalaw Kawaw Ya/Kalaw Lagaw Ya',
            '8402' => 'Meriam Mir',
            '8403' => 'Yumplatok (Torres Strait Creole)',
            '8500' => 'Northern Desert Fringe Area Languages',
            '8504' => 'Bilinarra',
            '8505' => 'Gurindji',
            '8506' => 'Gurindji Kriol',
            '8507' => 'Jaru',
            '8508' => 'Light Warlpiri',
            '8511' => 'Malngin',
            '8512' => 'Mudburra',
            '8514' => 'Ngardi',
            '8515' => 'Ngarinyman',
            '8516' => 'Walmajarri',
            '8517' => 'Wanyjirra',
            '8518' => 'Warlmanpa',
            '8521' => 'Warlpiri',
            '8522' => 'Warumungu',
            '8599' => 'Northern Desert Fringe Area Languages, nec',
            '8600' => 'Arandic',
            '8603' => 'Alyawarr',
            '8606' => 'Kaytetye',
            '8607' => 'Antekerrepenh',
            '8610' => 'Anmatyerr',
            '8611' => 'Central Anmatyerr',
            '8612' => 'Eastern Anmatyerr',
            '8619' => 'Anmatyerr, nec',
            '8620' => 'Arrernte',
            '8621' => 'Eastern Arrernte',
            '8622' => 'Western Arrarnta',
            '8629' => 'Arrernte, nec',
            '8699' => 'Arandic, nec',
            '8700' => 'Western Desert Language',
            '8703' => 'Antikarinya',
            '8704' => 'Kartujarra',
            '8705' => 'Kukatha',
            '8706' => 'Kukatja',
            '8707' => 'Luritja',
            '8708' => 'Manyjilyjarra',
            '8711' => 'Martu Wangka',
            '8712' => 'Ngaanyatjarra',
            '8713' => 'Pintupi',
            '8714' => 'Pitjantjatjara',
            '8715' => 'Wangkajunga',
            '8716' => 'Wangkatha',
            '8717' => 'Warnman',
            '8718' => 'Yankunytjatjara',
            '8721' => 'Yulparija',
            '8722' => 'Tjupany',
            '8799' => 'Western Desert Language, nec',
            '8800' => 'Kimberley Area Languages',
            '8801' => 'Bardi',
            '8802' => 'Bunuba',
            '8803' => 'Gooniyandi',
            '8804' => 'Miriwoong',
            '8805' => 'Ngarinyin',
            '8806' => 'Nyikina',
            '8807' => 'Worla',
            '8808' => 'Worrorra',
            '8811' => 'Wunambal',
            '8812' => 'Yawuru',
            '8813' => 'Gambera',
            '8814' => 'Jawi',
            '8815' => 'Kija',
            '8899' => 'Kimberley Area Languages, nec',
            '8900' => 'Other Australian Indigenous Languages',
            '8901' => 'Adnymathanha',
            '8902' => 'Arabana',
            '8903' => 'Bandjalang',
            '8904' => 'Banyjima',
            '8905' => 'Batjala',
            '8906' => 'Bidjara',
            '8907' => 'Dhanggatti',
            '8908' => 'Diyari',
            '8911' => 'Gamilaraay',
            '8913' => 'Garuwali',
            '8914' => 'Githabul',
            '8915' => 'Gumbaynggir',
            '8916' => 'Kanai',
            '8917' => 'Karajarri',
            '8918' => 'Kariyarra',
            '8921' => 'Kaurna',
            '8922' => 'Kayardild',
            '8924' => 'Kriol',
            '8925' => 'Lardil',
            '8926' => 'Mangala',
            '8927' => 'Muruwari',
            '8928' => 'Narungga',
            '8931' => 'Ngarluma',
            '8932' => 'Ngarrindjeri',
            '8933' => 'Nyamal',
            '8934' => 'Nyangumarta',
            '8935' => 'Nyungar',
            '8936' => 'Paakantyi',
            '8937' => 'Palyku/Nyiyaparli',
            '8938' => 'Wajarri',
            '8941' => 'Wiradjuri',
            '8943' => 'Yindjibarndi',
            '8944' => 'Yinhawangka',
            '8945' => 'Yorta Yorta',
            '8946' => 'Baanbay',
            '8947' => 'Badimaya',
            '8948' => 'Barababaraba',
            '8951' => 'Dadi Dadi',
            '8952' => 'Dharawal',
            '8953' => 'Djabwurrung',
            '8954' => 'Gudjal',
            '8955' => 'Keerray-Woorroong',
            '8956' => 'Ladji Ladji',
            '8957' => 'Mirning',
            '8958' => 'Ngatjumaya',
            '8961' => 'Waluwarra',
            '8962' => 'Wangkangurru',
            '8963' => 'Wargamay',
            '8964' => 'Wergaia',
            '8965' => 'Yugambeh',
            '8998' => 'Aboriginal English, so described',
            '8999' => 'Other Australian Indigenous Languages, nec',
            '9000' => 'OTHER LANGUAGES',
            '9101' => 'American Languages',
            '9200' => 'African Languages',
            '9201' => 'Acholi',
            '9203' => 'Akan',
            '9205' => 'Mauritian Creole',
            '9206' => 'Oromo',
            '9207' => 'Shona',
            '9208' => 'Somali',
            '9211' => 'Swahili',
            '9212' => 'Yoruba',
            '9213' => 'Zulu',
            '9214' => 'Amharic',
            '9215' => 'Bemba',
            '9216' => 'Dinka',
            '9217' => 'Ewe',
            '9218' => 'Ga',
            '9221' => 'Harari',
            '9222' => 'Hausa',
            '9223' => 'Igbo',
            '9224' => 'Kikuyu',
            '9225' => 'Krio',
            '9226' => 'Luganda',
            '9227' => 'Luo',
            '9228' => 'Ndebele',
            '9231' => 'Nuer',
            '9232' => 'Nyanja (Chichewa)',
            '9233' => 'Shilluk',
            '9234' => 'Tigré',
            '9235' => 'Tigrinya',
            '9236' => 'Tswana',
            '9237' => 'Xhosa',
            '9238' => 'Seychelles Creole',
            '9241' => 'Anuak',
            '9242' => 'Bari',
            '9243' => 'Bassa',
            '9244' => 'Dan (Gio-Dan)',
            '9245' => 'Fulfulde',
            '9246' => 'Kinyarwanda (Rwanda)',
            '9247' => 'Kirundi (Rundi)',
            '9248' => 'Kpelle',
            '9251' => 'Krahn',
            '9252' => 'Liberian (Liberian English)',
            '9253' => 'Loma (Lorma)',
            '9254' => 'Lumun (Kuku Lumun)',
            '9255' => 'Madi',
            '9256' => 'Mandinka',
            '9257' => 'Mann',
            '9258' => 'Moro (Nuba Moro)',
            '9261' => 'Themne',
            '9262' => 'Lingala',
            '9299' => 'African Languages, nec',
            '9300' => 'Pacific Austronesian Languages',
            '9301' => 'Fijian',
            '9302' => 'Gilbertese',
            '9303' => 'Maori (Cook Island)',
            '9304' => 'Maori (New Zealand)',
            '9306' => 'Nauruan',
            '9307' => 'Niue',
            '9308' => 'Samoan',
            '9311' => 'Tongan',
            '9312' => 'Rotuman',
            '9313' => 'Tokelauan',
            '9314' => 'Tuvaluan',
            '9315' => 'Yapese',
            '9399' => 'Pacific Austronesian Languages, nec',
            '9400' => 'Oceanian Pidgins and Creoles',
            '9402' => 'Bislama',
            '9403' => 'Hawaiian English',
            '9404' => 'Norf\'k-Pitcairn',
            '9405' => 'Solomon Islands Pijin',
            '9499' => 'Oceanian Pidgins and Creoles, nec',
            '9500' => 'Papua New Guinea Languages',
            '9502' => 'Kiwai',
            '9503' => 'Motu (HiriMotu)',
            '9504' => 'Tok Pisin (Neomelanesian)',
            '9599' => 'Papua New Guinea Languages, nec',
            '9601' => 'Invented Languages',
            '9700' => 'Sign Languages',
            '9701' => 'Auslan',
            '9702' => 'Key Word Sign Australia',
            '9799' => 'Sign Languages, nec',
            '@@@@' => 'Not specified',
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
            // '@@' WAS MISSING UNTIL v6.3.33, and 1,386 students on one production site
            // hold it. It is the standard's own not-stated value for this field: the
            // Collection Specifications say "If State identifier is not '@@', then State
            // identifier and Postcode combination must match" (Client file, Address rules),
            // and NCVER's client-details fact sheet instructs "State identifier - Enter in
            // '@@' or '99' for overseas clients".
            '@@' => 'Not stated',
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
     * IMPORTANT: these values are an INDICATIVE convenience list only. Each STA defines and
     * periodically changes its own "Funding source - state training authority" identifiers (e.g.
     * Queensland replaced Certificate 3 Guarantee / User Choice / Higher Level Skills with Career
     * Start / Career Boost from 1 July 2025). Before reporting or claiming, the RTO must confirm the
     * exact code against its STA's CURRENT AVETMISS/reporting specification and funded contract.
     * Some jurisdictions (e.g. WA) report through their own system (RAPT/TAMS) rather than plain
     * AVETMISS. Do not treat this list as the authoritative source of truth for a live claim.
     *
     * @param string $state Two or three letter state code: QLD, NSW, VIC, SA, WA, TAS, NT, ACT
     * @return array  code => description
     */
    public static function get_state_funding_source_codes(string $state): array {
        $codes = [
            'QLD' => [
                ''    => '— Not specified —',
                'B01' => 'B01 — Career Boost (current, from 1 Jul 2025)',
                'S01' => 'S01 — Career Start (current, from 1 Jul 2025)',
                'UC1' => 'UC1 — Apprenticeships & Traineeships',
                'QL1' => 'QL1 — Certificate 3 Guarantee (LEGACY — pre 1 Jul 2025)',
                'QC1' => 'QC1 — Higher Level Skills (LEGACY — pre 1 Jul 2025)',
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
                'SK1'  => 'SK1 — Skills SA / Subsidised Training List (subsidised)',
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

    /**
     * Gender identifier (renamed from Sex in Collection Specifications Release 8.0, p.22).
     *
     * 'X' WAS MISSING UNTIL v6.3.33, and 782 students on one production site hold it.
     * They did not get it from this plugin - the menu never offered it - so it arrived by
     * NAT import and has been correct all along. NCVER's Data Support Bulletin states the
     * rule plainly: "Where a response other than Male/Female is recorded, this should be
     * coded for the National VET Provider Collection at this point in time as 'Other',
     * until we have formally brought other Gender code values into the Standard." The
     * Release 8.0 enrolment form prints three boxes - Male, Female, Other - and the
     * data element definitions (edition 2.3) give the scheme as M / F / X with '@' as the
     * not-specified fill.
     *
     * The method name is kept as get_sex_codes() deliberately: renaming it would touch
     * every caller for no behavioural gain, and the DB column is still `sex`.
     */
    public static function get_sex_codes() {
        return [
            '@' => 'Not stated',
            'M' => 'Male',
            'F' => 'Female',
            'X' => 'Other',
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

    /**
     * Delivery mode identifier, AVETMISS Release 8.0.
     *
     * WHAT CHANGED AND WHY (v6.3.34):
     * Release 8.0 made this a THREE-character field of Y/N flags - position 1 internal,
     * position 2 external, position 3 workplace-based - replacing the Release 7.0 codes
     * 10/20/30/40/90 this method used to return. The plugin was writing '10 ' into a
     * field that has to read 'YNN'. Confirmed in NCVER's AVETMISS data element
     * definitions (edition 2.3) and independently in Queensland's AVETMISS 8.0 reporting
     * requirements, which tabulate the identical eight combinations.
     *
     * The gain is not just correctness: the triplet can say "internal AND workplace",
     * which no Release 7.0 code could express, so blended delivery is now recordable.
     *
     * Stored data is NOT migrated. to_release8_delivery_mode() converts legacy values on
     * their way into a NAT file, so a site whose enrolments all hold '10' produces a
     * correct file without a single row being rewritten.
     */
    public static function get_delivery_mode_nat_codes() {
        return [
            'YNN' => 'Internal only (classroom-based)',
            'NYN' => 'External only (electronic or distance)',
            'NNY' => 'Workplace-based only',
            'YYN' => 'Combination of internal and external',
            'YNY' => 'Combination of internal and workplace-based',
            'NYY' => 'Combination of external and workplace-based',
            'YYY' => 'All modes',
            'NNN' => 'Not applicable (recognition of prior learning or credit transfer)',
        ];
    }

    /**
     * The Release 7.0 delivery mode codes, kept only so that stored legacy values can be
     * recognised and labelled rather than reported as unknown.
     *
     * These must never be offered for selection and must never be written to a NAT file.
     */
    public static function get_delivery_mode_legacy_codes() {
        return [
            '10' => 'Classroom-based (Release 7.0 code)',
            '20' => 'Electronic-based (Release 7.0 code)',
            '30' => 'Employment-based (Release 7.0 code)',
            '40' => 'Other delivery (Release 7.0 code)',
            '90' => 'Not applicable - RPL/Credit Transfer (Release 7.0 code)',
        ];
    }

    /**
     * Convert a stored delivery mode to its Release 8.0 form for NAT output.
     *
     * A value that is already a Y/N triplet is returned unchanged, so enrolments recorded
     * through the Release 8.0 menu pass straight through.
     *
     * '40 - Other delivery' is DELIBERATELY NOT MAPPED. Release 8.0 has no "other" - the
     * three flags are exhaustive - so any mapping would be an invention, and inventing one
     * would put a claim about how training was delivered into a statutory return. It is
     * returned unchanged, which makes it fail validation loudly instead of passing quietly
     * as something it is not.
     *
     * An empty value becomes 'YNN', which is what the generator's previous '10' default
     * meant. That is a DEFAULT, not a recorded fact, and on a site where delivery mode was
     * never captured it will be wrong for most records - see the delivery mode entry in
     * Reports > AVETMISS code-list integrity.
     *
     * @param string|null $stored
     * @return string
     */
    public static function to_release8_delivery_mode($stored): string {
        $v = strtoupper(trim((string)$stored));

        if (preg_match('/^[YN]{3}$/', $v)) {
            return $v;
        }

        $map = [
            '10' => 'YNN',   // Classroom-based  -> internal only
            '20' => 'NYN',   // Electronic-based -> external only
            '30' => 'NNY',   // Employment-based -> workplace-based only
            '90' => 'NNN',   // Not applicable   -> RPL / credit transfer
        ];
        if (isset($map[$v])) {
            return $map[$v];
        }
        // AN UNRECORDED DELIVERY MODE IS NOT REPORTED AS CLASSROOM (v6.3.35).
        //
        // This returned 'YNN' for an empty value, matching the '10' the generator used to
        // default to. Both are the same mistake: they put a claim about how training was
        // delivered into a statutory return on no evidence. 'YNN' says internal only, and
        // for an online or workplace-based RTO that is simply false.
        //
        // An empty value is passed through as empty. The specification says this field
        // must not be blank, so the resulting file is INVALID - and that is the point. A
        // blank is detectably wrong and gets fixed before lodgement; 'YNN' is
        // undetectably wrong and gets lodged. Reports > AVETMISS code-list integrity
        // lists every enrolment with an unrecorded delivery mode so it surfaces there
        // rather than in an NCVER validation report.
        //
        // Same reasoning as '40 - Other delivery' above: where the plugin does not know,
        // it says so instead of guessing.
        return $v;
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
                'requires' => 'At least one unit with a competent outcome (20, 51, 60, 81)',
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
