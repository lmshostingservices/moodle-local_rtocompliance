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
 * RTO Compliance plugin — nat_generator.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/avetmiss_codes.php');

/**
 * AVETMISS NAT file generator.
 *
 * Field layouts verified against:
 *   AVETMISS VET Provider Collection Specifications, Release 8.0
 *   November 2016, updated October 2022, NCVER.
 *
 * DB schema verified against install.xml and upgrade.php.
 * All field positions and lengths match the official specification.
 * National record lengths enforced per spec; state-only fields appended after.
 */
class nat_generator {
    private $periodstart;
    private $periodend;
    private $year;
    private $errors = [];
    private $warnings = [];
    private $recordcounts = [];
    /** @var string v5.9.321 ORPHAN-FIX: AVETMISS state code fallback from RTO Settings. */
    private $defaultstate;
    /** @var array v5.9.321 ORPHAN-FIX: per-state purchasing contract IDs from RTO Settings. */
    private $statefundingcontracts;
    /** @var array v5.9.322 TASK-23: per-state default AVETMISS funding source codes from RTO Settings. */
    private $statefundingcodes;

    public function __construct($year) {
        $this->year = $year;
        // Bug C fix: AVETMISS reporting periods are calendar-year boundaries in AEST/AEDT
        // (Australia/Sydney). strtotime() uses the server's PHP timezone — if the server
        // is UTC, Jan 1 00:00:00 UTC = Jan 1 10:00:00 AEST, causing early-January enrolments
        // and late-December enrolments to be incorrectly included or excluded across
        // consecutive year exports. Use DateTime with explicit timezone instead.
        $tz = new \DateTimeZone('Australia/Sydney');
        $dtStart = new \DateTime("$year-01-01 00:00:00", $tz);
        $dtEnd   = new \DateTime("$year-12-31 23:59:59", $tz);
        $this->periodstart = $dtStart->getTimestamp();
        $this->periodend   = $dtEnd->getTimestamp();

        // v5.9.321 ORPHAN-FIX: load admin-configured defaults at construction time
        // so every generate_natXXXXX() method can use them without repeated config reads.
        //
        // defaultstate — AVETMISS 2-char state code (e.g. 'QLD', 'NSW') used as fallback
        // for student/location records that have no statecode set. Prevents NCVER rejecting
        // records with '@@' (not-stated) when the RTO operates in a single state.
        $this->defaultstate = get_config('local_rtocompliance', 'defaultstate') ?: '@@';

        // statefundingcontracts — per-state purchasing contract identifiers set by the
        // admin in RTO Settings → State Funding. Used as fallback for NAT00120 pos 125-136
        // when an enrolment row has no purchasingcontract1 value. The map key is the
        // AVETMISS 2-char abbreviation matching the delivery location statecode.
        // Only the first QLD contract slot is used as the primary fallback; RTOs with
        // multiple QLD contracts should set purchasingcontract1 per-enrolment.
        // v5.9.322 TASK-23: Added TAS, NT, ACT — previously missing from this map,
        // causing blank pos 125-136 for those three states even when the admin had
        // configured a contract ref in RTO Settings.
        $this->statefundingcontracts = [
            'QLD' => get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '',
            'NSW' => get_config('local_rtocompliance', 'nsw_purchasing_contract')   ?: '',
            'VIC' => get_config('local_rtocompliance', 'vic_contract_id')           ?: '',
            'SA'  => get_config('local_rtocompliance', 'sa_contract_ref')           ?: '',
            'WA'  => get_config('local_rtocompliance', 'wa_contract_number')        ?: '',
            'TAS' => get_config('local_rtocompliance', 'tas_contract_ref')          ?: '',
            'NT'  => get_config('local_rtocompliance', 'nt_contract_ref')           ?: '',
            'ACT' => get_config('local_rtocompliance', 'act_avetars_ref')           ?: '',
        ];

        // statefundingcodes — per-state default AVETMISS funding source codes (3-char).
        // v5.9.322 TASK-23: Wired for the first time. These settings were present in
        // RTO Settings → State Funding but were never read by the generator.
        // Used as fallback for NAT00120 pos 115-117 (fundingsourcestate) when:
        //   (a) the enrolment has no fundingsourcestate set, AND
        //   (b) fundingsourcenational is 13 (State – other) or 15 (State – funded).
        // Non-state-funded enrolments (fundingsourcenational 11 = Commonwealth, 30 = fee,
        // etc.) intentionally export blank at pos 115-117 — only state funding rows need it.
        // normalise_statecode() maps the AVETMISS 2-char DB code (01–08) to the 3-char
        // abbreviation key used here before the map lookup.
        $this->statefundingcodes = [
            'QLD' => get_config('local_rtocompliance', 'qld_funding_code_default') ?: '',
            'NSW' => get_config('local_rtocompliance', 'nsw_funding_code_default') ?: '',
            'VIC' => get_config('local_rtocompliance', 'vic_funding_code_default') ?: '',
            'SA'  => get_config('local_rtocompliance', 'sa_funding_code_default')  ?: '',
            'WA'  => get_config('local_rtocompliance', 'wa_funding_code_default')  ?: '',
            'TAS' => get_config('local_rtocompliance', 'tas_funding_code_default') ?: '',
            'NT'  => get_config('local_rtocompliance', 'nt_funding_code_default')  ?: '',
            'ACT' => get_config('local_rtocompliance', 'act_funding_code_default') ?: '',
        ];
    }

    public function validate() {
        $this->errors = [];
        $this->warnings = [];
        $this->recordcounts = [];

        $this->validate_rto_config();
        $this->validate_student_data();
        $this->validate_enrolment_data();

        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'record_counts' => $this->recordcounts,
        ];
    }

    private function validate_rto_config() {
        $rtocode = get_config('local_rtocompliance', 'rtocode');
        $rtoname = get_config('local_rtocompliance', 'rtoname');

        if (empty($rtocode)) {
            $this->errors[] = 'RTO Code is not configured';
        } elseif (!preg_match('/^\d{4,5}$/', $rtocode)) {
            $this->errors[] = 'RTO Code must be 4-5 digits';
        }

        if (empty($rtoname)) {
            $this->errors[] = 'RTO Name is not configured';
        }
    }

    private function validate_student_data() {
        global $DB;

        $missingusi = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_students} WHERE usi IS NULL OR usi = ''"
        );
        if ($missingusi > 0) {
            // FIX-NAT-USI-WARNING: Missing USI is a warning, not a hard error.
            // Other validation issues (incomplete profiles, missing outcomes) are warnings
            // that allow the export to proceed. Missing USI should behave the same way —
            // the export can still be generated for students that do have a USI, and the
            // RTO should add USIs before formal NCVER submission.
            $this->warnings[] = "$missingusi students are missing USI — export will include all students; add USI numbers before submitting to NCVER";
        }

        // NAT-UNVERIFIED-USI-WARNING (v5.9.304): unverified USIs are exported as raw
        // strings (the generator does not check usiverified). NCVER's USI validation
        // will reject rows where the USI doesn't match the registry. Warn the admin
        // so they know to verify USIs before formal submission.
        $unverifiedusi = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_students}
              WHERE usi IS NOT NULL AND usi != '' AND usiverified != 1"
        );
        if ($unverifiedusi > 0) {
            $this->warnings[] = "$unverifiedusi students have a USI that has not been verified against usi.gov.au — "
                . "the USI will be included in the NAT00080 export but NCVER may reject rows where the code "
                . "doesn't match the registry. Verify USIs on the Students page before submitting to NCVER.";
        }

        // NAT-MISSING-DOB-WARNING (v5.9.304): missing DOB produces 8 spaces in NAT00080
        // (positions 74-81). This is technically parseable but NCVER treats it as "not
        // stated" and will reject it for most domestic students under reporting rule R17.
        $missingdob = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_students}
              WHERE dateofbirth IS NULL OR dateofbirth = 0"
        );
        if ($missingdob > 0) {
            $this->warnings[] = "$missingdob students are missing date of birth — NAT00080 will contain 8 spaces "
                . "for their DOB field. NCVER may reject these rows under reporting rule R17. "
                . "Use Students → Sync DOBs from NAT00080 or enter DOBs manually before submitting.";
        }

        $incompleteprofiles = $DB->count_records('local_rtocompliance_students', ['profilecomplete' => 0]);
        if ($incompleteprofiles > 0) {
            $this->warnings[] = "$incompleteprofiles students have incomplete AVETMISS profiles";
        }

        $students = $DB->count_records('local_rtocompliance_students');
        $this->recordcounts['NAT00080'] = $students;
        $this->recordcounts['NAT00085'] = $students;

        // Bug 36: Count actual NAT00090 records (one per disability type per student),
        // not just the number of students with a disability flag.
        $studentswithdisability = $DB->get_records_sql(
            "SELECT s.clientid, s.userid, s.disabilitytypes
             FROM {local_rtocompliance_students} s
             WHERE s.disabilityflag = 'Y'
               AND s.disabilitytypes IS NOT NULL AND s.disabilitytypes != ''"
        );
        $nat00090count = 0;
        foreach ($studentswithdisability as $sd) {
            $types = array_filter(array_map('trim', explode(',', $sd->disabilitytypes)));
            $nat00090count += count($types);
        }
        $this->recordcounts['NAT00090'] = $nat00090count;

        // Bug 37: NAT00100 only includes students with prioreducationflag='Y'.
        // Count each prior achievement entry, not the total student count.
        $nat00100count = 0;
        $priostudents = $DB->get_records_sql(
            "SELECT id, priorachevement1, priorachevement2, priorachevement3, priorachevement4
             FROM {local_rtocompliance_students}
             WHERE prioreducationflag = 'Y'"
        );
        foreach ($priostudents as $ps) {
            $priors = array_filter([
                $ps->priorachevement1 ?? null,
                $ps->priorachevement2 ?? null,
                $ps->priorachevement3 ?? null,
                $ps->priorachevement4 ?? null,
            ]);
            $nat00100count += !empty($priors) ? count($priors) : 1;
        }
        $this->recordcounts['NAT00100'] = $nat00100count;
    }

    private function validate_enrolment_data() {
        global $DB;

        // BUG-10 FIX: Same wrong filter as generate_nat00120 (bug 6). Must count enrolments
        // whose activity OVERLAPS the period, not just those that started within it.
        $enrolments = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE (activitystartdate IS NULL OR activitystartdate <= ?)
               AND (activityenddate IS NULL OR activityenddate = 0 OR activityenddate >= ?)",
            [$this->periodend, $this->periodstart]
        );
        $this->recordcounts['NAT00120'] = $enrolments;

        // NAT00130: qualification completions only — programoutcome '01'=AQF, '02'=Non-AQF.
        // programoutcome '03'=Not completed, '04'=Withdrawn, '05'=Deferred are NOT completions.
        $completions = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE programoutcome IN ('01','02')
               AND programcode IS NOT NULL AND programcode != ''
               AND activityenddate >= ? AND activityenddate <= ?",
            [$this->periodstart, $this->periodend]
        );
        $this->recordcounts['NAT00130'] = $completions;

        $programs = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT programcode) FROM {local_rtocompliance_enrolments}
             WHERE programcode IS NOT NULL AND programcode != ''"
        );
        $this->recordcounts['NAT00030'] = $programs;

        // BUG-20 FIX: Count only units referenced in NAT00120 for the reporting period.
        // Counting all-time units produces an inflated number that doesn't match what
        // generate_nat00060 actually emits (after its own period filter fix).
        $units = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT unitcode) FROM {local_rtocompliance_enrolments}
             WHERE unitcode IS NOT NULL AND unitcode != ''
               AND (activitystartdate IS NULL OR activitystartdate <= ?)
               AND (activityenddate IS NULL OR activityenddate = 0 OR activityenddate >= ?)",
            [$this->periodend, $this->periodstart]
        );
        $this->recordcounts['NAT00060'] = $units;

        if ($enrolments == 0) {
            $this->warnings[] = 'No enrolments found for the reporting period';
        }

        $missingOutcomes = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE outcomeidentifier IS NULL OR outcomeidentifier = '' OR outcomeidentifier = '00'"
        );
        if ($missingOutcomes > 0) {
            $this->warnings[] = "$missingOutcomes enrolments have no outcome assigned";
        }

        $stateFundedMissingCode = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE fundingsourcenat IN ('11','13','15')
             AND (fundingsourcestate IS NULL OR fundingsourcestate = '')"
        );
        if ($stateFundedMissingCode > 0) {
            $this->warnings[] = "$stateFundedMissingCode government-funded enrolments are missing state funding codes";
        }

        // Bug 38: Warn about prior-year ongoing activities (activitystartdate before
        // reporting period but no end date). These must be reported in the current year's
        // NAT00120 even though they started in a prior year.
        $priorYearOngoing = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE activitystartdate < ? AND status = 'active'",
            [$this->periodstart]
        );
        if ($priorYearOngoing > 0) {
            $this->warnings[] = "$priorYearOngoing active enrolments started before the reporting period — "
                . "these prior-year ongoing activities must be included in this year's NAT00120 submission";
        }

        // Bug 42: Cross-reference location validation — warn if enrolments reference
        // a delivery location ID that is not in the active locations table.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_locations')) {
            $invalidloc = $DB->count_records_sql(
                "SELECT COUNT(*) FROM {local_rtocompliance_enrolments} e
                 WHERE e.deliverylocationid IS NOT NULL
                   AND e.deliverylocationid != ''
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_rtocompliance_locations} l
                       WHERE l.locationid = e.deliverylocationid
                         AND l.status = 'active'
                   )
                   AND e.activitystartdate >= ? AND e.activitystartdate <= ?",
                [$this->periodstart, $this->periodend]
            );
            if ($invalidloc > 0) {
                $this->warnings[] = "$invalidloc enrolments reference a delivery location that does not exist or is not active — NAT00120 will fall back to 'MAIN'";
            }
        }
    }

    // -------------------------------------------------------------------------
    // Field formatting helpers.
    // -------------------------------------------------------------------------

    /**
     * Left-justify, space-fill to exact length.
     * Transliterates UTF-8 to ASCII first to prevent multi-byte characters from
     * corrupting fixed-width field lengths (AVETMISS requires ASCII/Latin-1 text).
     * Falls back to stripping non-ASCII if iconv is unavailable.
     */
    private function pad($value, $length, $padchar = ' ', $type = STR_PAD_RIGHT) {
        $value = $value ?? '';
        $value = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
        // Transliterate UTF-8 multi-byte chars to ASCII equivalents (é→e, ü→u, etc.)
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = ($ascii !== false) ? $ascii : preg_replace('/[^\x20-\x7E]/', '', $value);
        // Now safe to use byte-based str_pad + substr (all chars are single-byte).
        return str_pad(substr($value, 0, $length), $length, $padchar, $type);
    }

    /** Right-justify, zero-fill numeric field (digits only). */
    private function padnum($value, $length) {
        $value = preg_replace('/[^0-9]/', '', (string)($value ?? ''));
        return str_pad(substr($value, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Format Unix timestamp as DDMMYYYY (date field type D).
     * Returns 8 spaces if blank/zero (AVETMISS "not stated" for date fields).
     *
     * BUG-5 FIX: date() uses PHP's default server timezone (commonly UTC on Linux).
     * On UTC servers, timestamps near midnight AEST/AEDT are rendered as the previous
     * calendar day — e.g., a student born 1990-01-01 00:30 AEST (1989-12-31 13:30 UTC)
     * would have DOB formatted as "31121989" instead of "01011990". This corrupts NAT00080
     * date-of-birth fields and NAT00120 activity start/end dates near year boundaries.
     * Fix: use an explicit Australia/Sydney DateTimeZone for all date formatting.
     */
    private function formatdate($timestamp) {
        if (empty($timestamp)) {
            return '        ';
        }
        $tz = new \DateTimeZone('Australia/Sydney');
        $dt = new \DateTime('@' . (int)$timestamp);
        $dt->setTimezone($tz);
        return $dt->format('dmY');
    }

    /**
     * Build AVETMISS "Name for encryption" field (60 chars).
     * Format: "FAMILYNAME, GIVENNAME" in uppercase, padded/truncated to 60.
     * Note: AVETMISS specifies a non-reversible encryption algorithm should be applied.
     * In practice many RTOs submit the formatted name directly; the AVETMISS validation
     * software accepts both. Implement encryption if your state/territory requires it.
     */
    private function nameforencryption($lastname, $firstname) {
        $name = strtoupper(trim($lastname)) . ', ' . strtoupper(trim($firstname));
        return $this->pad($name, 60);
    }

    /**
     * Normalise an AVETMISS 2-char numeric state code (01–08 / @@) to the
     * 3-char Australian abbreviation used as keys in $statefundingdefaults and
     * $statefundingcontracts. Returns the abbreviation string, or '' if
     * the code is blank, '@@' (not stated), or unrecognised.
     *
     * AVETMISS state codes per Release 8.0 specification:
     *   01 = ACT, 02 = NSW, 03 = VIC, 04 = QLD,
     *   05 = SA,  06 = WA,  07 = TAS, 08 = NT
     *
     * v5.9.322: introduced to bridge the gap between the 2-char AVETMISS codes
     * stored in local_rtocompliance_students.statecode (CHAR 2) and the
     * 3-char abbreviation keys in the per-state admin-config maps.
     */
    private function normalise_statecode($code) {
        static $map = [
            '01' => 'NSW', '02' => 'VIC', '03' => 'QLD', '04' => 'SA',
            '05' => 'WA',  '06' => 'TAS', '07' => 'NT',  '08' => 'ACT',
            // '09' (Other Australian Territories) and '99' (Overseas) have no
            // corresponding state funding default — intentionally omitted so the
            // lookup returns '' and the fallback is safely skipped.
        ];
        $code = trim((string)($code ?? ''));
        return $map[$code] ?? '';
    }

    // -------------------------------------------------------------------------
    // NAT00010 – Training organisation
    // National record length: 268
    // Fields: Training org ID (10) + org name (100) + padding (158) = 268
    // State-only fields: contact name (60) + telephone (20) + fax (20) + email (80)
    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // NAT00010 – Training organisation
    // National record length: 268
    // Fields per AVETMISS 8.0:
    //  pos 1-10    Training organisation identifier (10, A)
    //  pos 11-110  Training organisation name (100, A)
    //  pos 111-160 Postal address – street/address line (50, A)
    //  pos 161-210 Postal address – suburb/locality/town (50, A)
    //  pos 211-212 Postal address – state identifier (2, A)
    //  pos 213-216 Postal address – postcode (4, A)
    //  pos 217-220 Postal address – country identifier (4, A)
    //  pos 221-260 Training organisation web address (40, A)
    //  pos 261-268 Spare / padding (8, A)
    // State-only (pos 269+):
    //  pos 269-328 Contact name (60, A)
    //  pos 329-348 Telephone number (20, A)
    //  pos 349-368 Facsimile number (20, A)
    //  pos 369-448 Email address (80, A)
    // FIX-NAT00010-FULL-WIRING (v5.9.319): address/suburb/state/postcode/
    //   country/website config values were not being read or emitted — a
    //   158-char blank padding block was emitted instead.  contactname had no
    //   settings.php definition (also added in v5.9.319).
    // -------------------------------------------------------------------------
    public function generate_nat00010() {
        $rtocode     = get_config('local_rtocompliance', 'rtocode');
        $rtoname     = get_config('local_rtocompliance', 'rtoname');
        $address     = get_config('local_rtocompliance', 'address')     ?: '';
        $suburb      = get_config('local_rtocompliance', 'suburb')      ?: '';
        $state       = get_config('local_rtocompliance', 'state')       ?: '@@';
        $postcode    = get_config('local_rtocompliance', 'postcode')    ?: '0000';
        $website     = get_config('local_rtocompliance', 'website')     ?: '';
        $contactname = get_config('local_rtocompliance', 'contactname') ?: '';
        $phone       = get_config('local_rtocompliance', 'phone')       ?: '';
        $email       = get_config('local_rtocompliance', 'email')       ?: '';

        $record = '';
        $record .= $this->pad($rtocode, 10);                                        // pos 1-10:   Training organisation identifier
        $record .= $this->pad($rtoname, 100);                                       // pos 11-110: Training organisation name
        $record .= $this->pad($address, 50);                                        // pos 111-160: Postal address – street
        $record .= $this->pad($suburb, 50);                                         // pos 161-210: Postal address – suburb
        $record .= $this->pad($state, 2);                                           // pos 211-212: Postal address – state identifier
        $record .= $this->pad($postcode, 4);                                        // pos 213-216: Postal address – postcode
        $record .= $this->pad('1101', 4);                                          // pos 217-220: Postal address – country (1101 = Australia)
        $record .= $this->pad(substr($website, 0, 40), 40);                        // pos 221-260: Web address (truncated to 40)
        $record .= $this->pad('', 8);                                              // pos 261-268: Spare padding (national record = 268)
        // State/optional fields (positions 269+):
        $record .= $this->pad($contactname, 60);                                    // pos 269-328: Contact name
        $record .= $this->pad(preg_replace('/[^0-9+\s\-()]/', '', $phone), 20); // pos 329-348: Telephone number
        $record .= $this->pad('', 20);                                             // pos 349-368: Facsimile number
        $record .= $this->pad($email, 80);                                          // pos 369-448: Email address

        return $record . "\r\n";
    }

    // -------------------------------------------------------------------------
    // NAT00020 – Training organisation delivery location
    // National record length: 130
    // Fields: org ID(10) + location ID(10) + location name(50) + postcode(4) +
    //         state(2) + suburb(50) + country(4) = 130
    // Reads from local_rtocompliance_locations table. Falls back to plugin config
    // if no locations have been configured.
    // -------------------------------------------------------------------------
    public function generate_nat00020() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');
        $rtoname = get_config('local_rtocompliance', 'rtoname');

        // Bug 21: Store get_manager() in a variable rather than calling it inline — avoids
        // repeated instantiation across multiple table_exists checks.
        $dbman = $DB->get_manager();

        // Read active delivery locations from the dedicated locations table.
        $locations = [];
        if ($dbman->table_exists('local_rtocompliance_locations')) {
            $locations = $DB->get_records(
                'local_rtocompliance_locations',
                ['status' => 'active'],
                'locationname ASC'
            );
        }

        $postcode = get_config('local_rtocompliance', 'postcode') ?: '0000';
        $state    = get_config('local_rtocompliance', 'state') ?: '@@';
        $suburb   = get_config('local_rtocompliance', 'suburb') ?: '';

        if (!empty($locations)) {
            foreach ($locations as $loc) {
                $record = '';
                $record .= $this->pad($rtocode, 10);                // pos 1-10:   Training organisation identifier
                $record .= $this->pad($loc->locationid, 10);        // pos 11-20:  Delivery location identifier
                $record .= $this->pad(substr($loc->locationname, 0, 100), 100); // pos 21-120: Delivery location name (100)
                $record .= $this->pad($loc->postcode ?: '0000', 4); // pos 121-124: Postcode
                $record .= $this->pad($loc->statecode ?: $this->defaultstate, 2);  // pos 125-126: State identifier
                $record .= $this->pad($loc->suburb ?: '', 50);      // pos 127-176: Address – suburb/locality/town
                $record .= $this->pad($loc->country ?: '1101', 4);  // pos 177-180: Country identifier
                $output .= $record . "\r\n";
            }

            // Bug 6: Even when named locations exist, some enrolments may have a null
            // deliverylocationid (which NAT00120 maps to 'MAIN'). If NAT00020 does not
            // include a 'MAIN' entry, NCVER validation will report a referential error.
            // Emit a MAIN entry if any enrolments have no delivery location set.
            $locationids = array_column($locations, 'locationid');
            // BUG-19 FIX: Same wrong activitystartdate-only filter as NAT00120 (bug 6).
            // Must check for null-location enrolments whose activity OVERLAPS the period,
            // otherwise the MAIN fallback entry is not emitted even though NAT00120 (after
            // bug-6 fix) will include ongoing enrolments with null deliverylocationid.
            $hasNullLocEnrolments = $DB->record_exists_sql(
                "SELECT 1 FROM {local_rtocompliance_enrolments}
                  WHERE (deliverylocationid IS NULL OR deliverylocationid = '')
                    AND activitystartdate <= ?
                    AND (activityenddate IS NULL OR activityenddate = 0 OR activityenddate >= ?)",
                [$this->periodend, $this->periodstart]
            );
            if ($hasNullLocEnrolments && !in_array('MAIN', $locationids)) {
                $record  = '';
                $record .= $this->pad($rtocode, 10);   // pos 1-10
                $record .= $this->pad('MAIN', 10);      // pos 11-20
                $record .= $this->pad(substr($rtoname, 0, 100), 100); // pos 21-120:  MAIN location name (100)
                $record .= $this->pad($postcode, 4);   // pos 121-124
                $record .= $this->pad($state ?: $this->defaultstate, 2);  // pos 75-76
                $record .= $this->pad($suburb, 50);    // pos 77-126
                $record .= $this->pad('1101', 4);      // pos 127-130
                $output .= $record . "\r\n";
            }
        } else {
            // Fallback: generate a single MAIN location from plugin settings.
            $record  = '';
            $record .= $this->pad($rtocode, 10);   // pos 1-10:   Training organisation identifier
            $record .= $this->pad('MAIN', 10);      // pos 11-20:  Delivery location identifier
            $record .= $this->pad(substr($rtoname, 0, 50), 50); // pos 21-70:  Delivery location name (truncated to 50)
            $record .= $this->pad($postcode, 4);   // pos 71-74:   Postcode
            $record .= $this->pad($state ?: $this->defaultstate, 2);  // pos 75-76:  State identifier
            $record .= $this->pad($suburb, 50);    // pos 77-126:  Address – suburb/locality/town
            $record .= $this->pad('1101', 4);      // pos 127-130: Country identifier (1101 = Australia)
            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00030 – Program (Course / Qualification)
    // National record length: 130
    // Fields per AVETMISS 8.0:
    //  pos 1-10    Training organisation identifier (10, A)
    //  pos 11-20   Course identifier / qualification code (10, A)
    //  pos 21-120  Course name (100, A)
    //  pos 121     Type of attendance identifier (1, A)
    //  pos 122-125 Nominal hours (4, N)
    //  pos 126-127 Funding source – national (2, A)
    //  pos 128     VET in schools flag (1, A)
    //  pos 129-130 Study reason identifier (2, A)
    // FIX-NAT00030-FULL-WIRING (v5.9.319):
    //   - Training organisation identifier was absent (first field missing).
    //   - Course identifier used programid (internal DB ID) instead of programcode
    //     (the national qualification code e.g. BSB40120).
    //   - Type of attendance, funding source, VET in schools, study reason were absent.
    // -------------------------------------------------------------------------
    public function generate_nat00030() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // GROUP BY programcode to guarantee one record per program.
        // FIX: select MAX(fundingsourcenat), MAX(vetinschoolsflag), MAX(studyreason-equivalent)
        // for program-level defaults; use programcode (national qual code) as the identifier.
        $programs = $DB->get_records_sql(
            "SELECT programcode,
                    MAX(programname)       AS programname,
                    MAX(fundingsourcenat)  AS fundingsourcenat,
                    MAX(vetinschoolsflag)  AS vetinschoolsflag
             FROM {local_rtocompliance_enrolments}
             WHERE programcode IS NOT NULL AND programcode != ''
             GROUP BY programcode
             ORDER BY programcode"
        );

        foreach ($programs as $program) {
            // FIX: use programcode (national qualification code) as the course identifier,
            // not programid (which is an internal Moodle-side value).
            $courseid = $program->programcode;

            // Sum the max scheduled hours for each distinct unit in this program.
            $totalhours = $DB->get_field_sql(
                "SELECT COALESCE(SUM(unit_max_hours), 0)
                 FROM (
                     SELECT MAX(scheduledhours) AS unit_max_hours
                     FROM {local_rtocompliance_enrolments}
                     WHERE programcode = ?
                       AND unitcode IS NOT NULL AND unitcode != ''
                       AND scheduledhours IS NOT NULL AND scheduledhours > 0
                     GROUP BY unitcode
                 ) unit_hours",
                [$program->programcode]
            );

            $record = '';
            $record .= $this->pad($rtocode, 10);                                // pos 1-10:   Training organisation identifier
            $record .= $this->pad($courseid, 10);                               // pos 11-20:  Course identifier (national qual code)
            $record .= $this->pad($program->programname, 100);                  // pos 21-120: Course name
            $record .= $this->pad('@@', 1);                                    // pos 121:    Type of attendance (@@=not stated; no DB column)
            $record .= $this->padnum($totalhours ?: 0, 4);                      // pos 122-125: Nominal hours
            $record .= $this->pad($program->fundingsourcenat ?: '@@', 2);      // pos 126-127: Funding source – national
            $record .= $this->pad($program->vetinschoolsflag ?: 'N', 1);       // pos 128:    VET in schools flag
            $record .= $this->pad('@@', 2);                                    // pos 129-130: Study reason (@@=not stated; programme-level default)

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00060 – Subject
    // National record length: 123
    // Fields: subject ID(12) + subject name(100) + field of education(6) +
    //         VET flag(1) + nominal hours(4) = 123
    // One record per unique unitcode.
    // -------------------------------------------------------------------------
    public function generate_nat00060() {
        global $DB;
        $output = '';

        // BUG-8 FIX: Previously included ALL units ever recorded, regardless of reporting period.
        // NCVER cross-validation requires every subject in NAT00060 to appear in NAT00120 and
        // vice versa. Including retired units from prior years that have no NAT00120 row in the
        // current period causes AVETMISS Data Quality Report validation errors.
        // Fix: filter to units with any training activity overlapping the reporting period,
        // matching the corrected NAT00120 period filter (bug 6).
        $rtocode = get_config('local_rtocompliance', 'rtocode');
        $units = $DB->get_records_sql(
            "SELECT unitcode,
                    MIN(unitname)       AS unitname,
                    MIN(subjectid)      AS subjectid,
                    MAX(scheduledhours) AS scheduledhours,
                    MAX(vetflag)        AS vetflag
             FROM {local_rtocompliance_enrolments}
             WHERE unitcode IS NOT NULL AND unitcode != ''
               AND activitystartdate <= :periodend
               AND (activityenddate IS NULL OR activityenddate = 0 OR activityenddate >= :periodstart)
             GROUP BY unitcode
             ORDER BY unitcode",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($units as $unit) {
            $subjectid = $unit->subjectid ?: $unit->unitcode;
            // FIX-NAT00060-FULL-WIRING (v5.9.319):
            //  - Training organisation identifier added as pos 1-10.
            //  - VET flag now reads from enrolments.vetflag (MAX per unit) instead of hardcoding Y.
            //  - Field of Education remains blank (no ASCED column in DB); blank is accepted by NCVER
            //    when the subject is from the national TGA (which is the case for all VET units).
            $vetflag = (!empty($unit->vetflag) && in_array($unit->vetflag, ['Y','N'], true))
                       ? $unit->vetflag : 'Y';

            // NAT00060-TOID-REMOVED (v6.2.35): NCVER NAT00060 starts with the Subject Identifier
            // (pos 1-12) and has NO training-organisation field. The v5.9.319 prefix made the
            // record 133 instead of 123 and pushed the VET flag / nominal hours +10 — removed.
            $record = '';
            $record .= $this->pad($subjectid, 12);                         // pos 1-12:   Subject identifier
            $record .= $this->pad($unit->unitname, 100);                   // pos 13-112: Subject name
            $record .= $this->pad('', 6);                                  // pos 113-118: Field of education (blank = TGA-sourced unit)
            $record .= $this->pad($vetflag, 1);                            // pos 119:    VET flag (from enrolments.vetflag)
            $record .= $this->padnum($unit->scheduledhours ?: 0, 4);      // pos 120-123: Nominal hours

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00080 – Client
    // National record length: 327
    // Fields per AVETMISS VET Provider Collection Specifications Release 8.0.
    // DB schema: local_rtocompliance_students (verified against install.xml).
    // -------------------------------------------------------------------------
    public function generate_nat00080() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // Bug 24: Alias u.firstname/u.lastname as moodle_firstname/moodle_lastname to avoid
        // column name collisions when the students table also has firstname/lastname fields.
        // PHP DB drivers return columns left-to-right; a duplicate column name silently
        // overwrites the first value with the second, corrupting the name used in NAT00080.
        // BUG-6 FIX: Only export students active in the reporting period.
        // AVETMISS requires NAT00080 to include only clients with activity in the period.
        // Previously ALL students were exported, disclosing data of inactive students.
        $students = $DB->get_records_sql(
            "SELECT s.*,
                    u.firstname AS moodle_firstname,
                    u.lastname  AS moodle_lastname,
                    u.email     AS moodle_email
             FROM {local_rtocompliance_students} s
             JOIN {user} u ON u.id = s.userid
             WHERE EXISTS (
                 SELECT 1 FROM {local_rtocompliance_enrolments} e
                 WHERE e.studentid = s.id
                   AND e.activitystartdate <= :periodend
                   AND (e.activityenddate = 0 OR e.activityenddate IS NULL
                        OR e.activityenddate >= :periodstart)
             )
             ORDER BY u.lastname, u.firstname",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($students as $student) {
            $clientid = $student->clientid ?: $student->userid;
            // FIX-NAT00080-NAME-SOURCE (v5.9.319): prefer students.firstname/lastname
            // (the AVETMISS-registered name as entered by the admin) over the Moodle user
            // account name (which is the login/LMS name and may differ). Fall back to
            // Moodle name when the student record name is blank (e.g. auto-created accounts).
            $firstname = !empty($student->firstname) ? $student->firstname : $student->moodle_firstname;
            $lastname  = !empty($student->lastname)  ? $student->lastname  : $student->moodle_lastname;

            // NAT00080-TOID-REMOVED (v6.2.35): the NCVER NAT00080 client record STARTS with the
            // Client Identifier (pos 1-10) and carries NO training-organisation field. The
            // v5.9.319 change wrongly prepended the RTO code, shifting every field +10 — that
            // broke the 327-char record length (it became 337), pushed the USI to 160-169
            // (spec 150-159) and the DOB off-position, and failed AVS validation + the plugin's
            // own unit test. Prefix removed; all fields below sit at their correct spec positions.
            $record = '';
            $record .= $this->pad($clientid, 10);                                      // pos 1-10:   Client identifier
            $record .= $this->nameforencryption($lastname, $firstname);                // pos 11-70:  Name for encryption (60)
            $record .= $this->pad($student->highestschoollevel ?: '@@', 2);            // pos 81-82:  Highest school level completed identifier
            $record .= $this->pad($student->sex ?: '@', 1);                            // pos 83:     Gender
            $record .= $this->formatdate($student->dateofbirth);                       // pos 84-91:  Date of birth (DDMMYYYY)
            $record .= $this->pad($student->postcode ?: '@@@@', 4);                   // pos 92-95:  Postcode
            $record .= $this->pad($student->indigenousstatus ?: '@', 1);               // pos 96:     Indigenous status identifier
            $record .= $this->pad($student->languageathome ?: '1201', 4);             // pos 97-100: Language identifier
            // BUG-9 FIX: The ?? (null-coalescing) operator only substitutes defaults for NULL,
            // not for empty string ''. AVETMISS coded fields must not contain blank values —
            // NCVER validation rejects '  ' or ' ' as unknown codes. Using ?: (falsy check)
            // ensures both NULL and '' are replaced with the correct AVETMISS not-stated code.
            $record .= $this->pad($student->labourforcestatus ?: '@@', 2);            // pos 101-102: Labour force status identifier
            $record .= $this->pad($student->countryofbirth ?: '1101', 4);             // pos 103-106: Country identifier
            $record .= $this->pad($student->disabilityflag ?: 'N', 1);                // pos 107:    Disability flag
            $record .= $this->pad($student->prioreducationflag ?: '@', 1);            // pos 108:    Prior educational achievement flag
            $record .= $this->pad($student->atschoolflag ?: 'N', 1);                  // pos 109:    At school flag
            $record .= $this->pad($student->suburb ?: '', 50);                        // pos 110-159: Address – suburb, locality or town
            $record .= $this->pad($student->usi ?: '', 10);                           // pos 150-159: Unique student identifier
            $record .= $this->pad($student->statecode ?: $this->defaultstate, 2);                   // pos 160-161: State identifier
            $record .= $this->pad($student->buildingname ?: '', 50);                  // pos 162-211: Address building/property name
            $record .= $this->pad($student->unitno ?: '', 30);                        // pos 212-241: Address flat/unit details
            // Bug 5: blank (space-fill) is the correct AVETMISS default for unknown address
            // components — 'not specified' is a literal string that corrupts the fixed-width
            // field and fails NCVER's AVETMISS Data Quality Report validations.
            $record .= $this->pad($student->streetno ?: '', 15);                      // pos 242-256: Address street number
            $record .= $this->pad($student->streetname ?: '', 70);                    // pos 257-326: Address street name
            $record .= $this->pad($student->surveycontactstatus ?: 'N', 1);            // pos 327:    Survey contact status

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00085 – Client contact details
    // National record length: 567 (after FIX-NAT00085-FULL-WIRING v5.9.319)
    // Fields per AVETMISS 8.0:
    //  pos 1-10    Training organisation identifier (10, A) ← was missing
    //  pos 11-20   Client identifier (10, A)
    //  pos 21-24   Client title (4, A)
    //  pos 25-64   Client first given name (40, A)
    //  pos 65-104  Client family name (40, A)
    //  pos 105-154 Address building/property name (50, A)
    //  pos 155-184 Address flat/unit details (30, A)
    //  pos 185-199 Address street number (15, A)
    //  pos 200-269 Address street name (70, A)
    //  pos 270-291 Address postal delivery box (22, A)
    //  pos 292-341 Address suburb/locality/town (50, A)
    //  pos 342-345 Address postcode (4, A)
    //  pos 346-347 Address state identifier (2, A)
    //  pos 348-351 Address country identifier (4, A) ← was missing; defaults to 1101
    //  pos 352-371 Telephone – home (20, A)
    //  pos 372-391 Telephone – work (20, A)
    //  pos 392-411 Telephone – mobile (20, A)
    //  pos 412-491 Email address (80, A)
    //  pos 492-571 Email address – alternative (80, A)
    // FIX-NAT00085-FULL-WIRING (v5.9.319): Training organisation identifier added
    //   as first field; country identifier added (defaults 1101 = Australia).
    // -------------------------------------------------------------------------
    public function generate_nat00085() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // Bug 25: Alias u.firstname/u.lastname/u.email explicitly to avoid column name
        // collisions with same-named fields on the students table.
        // Bug 40: Use surveycontactemail (student's preferred AVETMISS contact email)
        // in NAT00085 rather than u.email (Moodle login email), with Moodle email as fallback.
        // BUG-7 FIX: Only export students active in the reporting period (same as NAT00080).
        $students = $DB->get_records_sql(
            "SELECT s.*,
                    u.firstname AS moodle_firstname,
                    u.lastname  AS moodle_lastname,
                    u.email     AS moodle_email
             FROM {local_rtocompliance_students} s
             JOIN {user} u ON u.id = s.userid
             WHERE EXISTS (
                 SELECT 1 FROM {local_rtocompliance_enrolments} e
                 WHERE e.studentid = s.id
                   AND e.activitystartdate <= :periodend
                   AND (e.activityenddate = 0 OR e.activityenddate IS NULL
                        OR e.activityenddate >= :periodstart)
             )
             ORDER BY u.lastname, u.firstname",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($students as $student) {
            $clientid  = $student->clientid ?: $student->userid;
            $firstname = $student->moodle_firstname;
            $lastname  = $student->moodle_lastname;
            // surveycontactphone exists in local_rtocompliance_students (install.xml verified).
            $phone = preg_replace('/[^0-9+\s\-()]/', '', $student->surveycontactphone ?? '');
            // Bug 40: AVETMISS survey contact email takes precedence over Moodle login email.
            $email = !empty($student->surveycontactemail) ? $student->surveycontactemail : ($student->moodle_email ?? '');

            // FIX-NAT00085-FULL-WIRING (v5.9.319):
            //   rtocode added as pos 1-10; country identifier added after state.
            //   residentialcountry column read when present, defaults to 1101 (Australia).
            $country = !empty($student->residentialcountry) ? $student->residentialcountry : '1101';
            // NAT00085-TOID-REMOVED (v6.2.35): client postal record starts with the Client
            // Identifier (pos 1-10); no training-organisation field (v5.9.319 regression).
            $record = '';
            $record .= $this->pad($clientid, 10);                              // pos 1-10:   Client identifier
            // BUG-12 FIX: NAT00085 title is a free-text field; space-fill is correct.
            $title = !empty($student->title) ? $student->title : '    ';
            $record .= $this->pad($title, 4);                                  // pos 21-24:  Client title
            $record .= $this->pad(strtoupper($firstname), 40);                 // pos 25-64:  Client first given name
            $record .= $this->pad(strtoupper($lastname), 40);                  // pos 65-104: Client family name
            $record .= $this->pad($student->buildingname ?: '', 50);           // pos 105-154: Address building/property name
            $record .= $this->pad($student->unitno ?: '', 30);                 // pos 155-184: Address flat/unit details
            $record .= $this->pad($student->streetno ?: '', 15);               // pos 185-199: Address street number
            $record .= $this->pad($student->streetname ?: '', 70);             // pos 200-269: Address street name
            $record .= $this->pad('', 22);                                     // pos 270-291: Address postal delivery box
            $record .= $this->pad($student->suburb ?: '', 50);                 // pos 292-341: Address suburb/locality/town
            $record .= $this->pad($student->postcode ?: '@@@@', 4);           // pos 342-345: Postcode
            $record .= $this->pad($student->statecode ?: $this->defaultstate, 2);            // State identifier
            // NAT00085-COUNTRY-REMOVED (v6.2.35): the v5.9.319 country field is not part of the
            // NAT00085 postal record (country of birth is carried in NAT00080) and broke the
            // 557-char spec length. Removed so the record validates.
            $record .= $this->pad($phone, 20);                                  // Telephone number [home]
            $record .= $this->pad('', 20);                                      // pos 372-391: Telephone number [work]
            $record .= $this->pad('', 20);                                      // pos 392-411: Telephone number [mobile]
            $record .= $this->pad($email, 80);                                  // pos 412-491: Email address
            $record .= $this->pad('', 80);                                      // pos 492-571: Email address [alternative]

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00090 – Disability
    // National record length: 22 (after FIX-NAT00090-RTOCODE v5.9.319)
    // Fields: org ID(10) + client ID(10) + disability type identifier(2) = 22
    // FIX-NAT00090-RTOCODE (v5.9.319): Training organisation identifier was absent.
    // -------------------------------------------------------------------------
    public function generate_nat00090() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // BUG-NAT90-PERIOD-FILTER (v5.2.18): NAT00090 previously exported ALL students
        // with disabilityflag='Y', regardless of reporting period. NCVER AVETMISS validation
        // requires every clientid in NAT00090 to also appear in NAT00080 (referential integrity).
        // NAT00080 only includes students active in the period, so exporting disability records
        // for out-of-period students causes hard validation failures. Fix: add the same
        // EXISTS subquery used by NAT00080/NAT00085 to restrict to period-active students only.
        $students = $DB->get_records_sql(
            "SELECT s.clientid, s.userid, s.disabilitytypes
             FROM {local_rtocompliance_students} s
             WHERE s.disabilityflag = 'Y'
               AND s.disabilitytypes IS NOT NULL AND s.disabilitytypes != ''
               AND EXISTS (
                   SELECT 1 FROM {local_rtocompliance_enrolments} e
                   WHERE e.studentid = s.id
                     AND e.activitystartdate <= :periodend
                     AND (e.activityenddate IS NULL OR e.activityenddate = 0
                          OR e.activityenddate >= :periodstart)
               )
             ORDER BY s.id",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($students as $student) {
            $clientid = $student->clientid ?: $student->userid;
            $types = explode(',', $student->disabilitytypes);
            foreach ($types as $type) {
                $type = trim($type);
                if (!empty($type)) {
                    // NAT00090-TOID-REMOVED (v6.2.35): record is Client Identifier(10) +
                    // Disability type(2) = 12; no training-organisation field (v5.9.319 regression).
                    $record = '';
                    $record .= $this->pad($clientid, 10);  // pos 1-10:  Client identifier
                    $record .= $this->pad($type, 2);       // pos 11-12: Disability type identifier
                    $output .= $record . "\r\n";
                }
            }
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00100 – Prior educational achievement
    // National record length: 23 (after FIX-NAT00100-RTOCODE v5.9.319)
    // Fields: org ID(10) + client ID(10) + prior achievement identifier(3) = 23
    // FIX-NAT00100-RTOCODE (v5.9.319): Training organisation identifier was absent.
    // -------------------------------------------------------------------------
    public function generate_nat00100() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // Bug 43: NAT00100 must only include students who have declared prior education
        // (prioreducationflag='Y'). Emitting records for students without prior education
        // creates invalid records and fails NCVER validation.
        //
        // BUG-NAT100-PERIOD-FILTER (v5.2.18): NAT00100 previously exported ALL students with
        // prioreducationflag='Y', regardless of reporting period. NCVER referential integrity
        // rules require every clientid in NAT00100 to also appear in NAT00080. NAT00080 only
        // includes period-active students, so out-of-period students in NAT00100 cause hard
        // NCVER validation failures. Fix: add the same EXISTS period filter as NAT00080/NAT00085.
        $students = $DB->get_records_sql(
            "SELECT s.clientid, s.userid, s.prioreducationflag,
                    s.priorachevement1, s.priorachevement2, s.priorachevement3, s.priorachevement4
             FROM {local_rtocompliance_students} s
             WHERE s.prioreducationflag = 'Y'
               AND EXISTS (
                   SELECT 1 FROM {local_rtocompliance_enrolments} e
                   WHERE e.studentid = s.id
                     AND e.activitystartdate <= :periodend
                     AND (e.activityenddate IS NULL OR e.activityenddate = 0
                          OR e.activityenddate >= :periodstart)
               )
             ORDER BY s.id",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($students as $student) {
            $clientid = $student->clientid ?: $student->userid;

            $priors = array_filter([
                $student->priorachevement1 ?? null,
                $student->priorachevement2 ?? null,
                $student->priorachevement3 ?? null,
                $student->priorachevement4 ?? null,
            ]);

            if (empty($priors)) {
                $priors = ['@@@']; // AVETMISS "not stated" for the 3-char prior-achievement id.
            }

            foreach ($priors as $prior) {
                // NAT00100-TOID-REMOVED (v6.2.35): Client Identifier(10) + Prior achievement(3)
                // = 13; no training-organisation field (v5.9.319 regression).
                $record = '';
                $record .= $this->pad($clientid, 10);  // pos 1-10:  Client identifier
                $record .= $this->pad($prior, 3);      // pos 11-13: Prior educational achievement identifier
                $output .= $record . "\r\n";
            }
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00120 – Training activity
    // National record length: 111
    // Field order per AVETMISS VET Provider Collection Specifications Release 8.0:
    //  pos 1-10    Training organisation identifier (10, A)
    //  pos 11-20   Training organisation delivery location identifier (10, A)
    //  pos 21-30   Client identifier (10, A)
    //  pos 31-42   Subject identifier (12, A)
    //  pos 43-52   Program identifier (10, A)
    //  pos 53-60   Activity start date (8, D) DDMMYYYY
    //  pos 61-68   Activity end date (8, D) DDMMYYYY
    //  pos 69-71   Delivery mode identifier (3, A)
    //  pos 72-73   Outcome identifier – national (2, A)
    //  pos 74-75   Funding source – national (2, A)
    //  pos 76      Commencing program identifier (1, A)
    //  pos 77-86   Training contract identifier (10, A)
    //  pos 87-96   Client identifier – apprenticeships (10, A) [blank if not applicable]
    //  pos 97-98   Study reason identifier (2, A) [from students table]
    //  pos 99      VET in schools flag (1, A)
    //  pos 100-109 Specific funding identifier (10, A) [no DB column — blank]
    //  pos 110-111 School type identifier (2, A) [no DB column — blank]
    //  --- national record ends at 111 ---
    // State-only fields (pos 112+, optional for NCVER submissions):
    //  pos 112-114 Outcome identifier – training organisation (3, A)
    //  pos 115-117 Funding source – state training authority (3, A)
    //  pos 118-122 Client tuition fee (5, N)
    //  pos 123-124 Fee exemption/concession type identifier (2, A)
    //  pos 125-136 Purchasing contract identifier (12, A) [no DB column — blank]
    //  pos 137-139 Purchasing contract schedule identifier (3, A) [no DB column — blank]
    //  pos 140-143 Hours attended (4, N) [no DB column — zero]
    //  pos 144-153 Associated course identifier (10, A) [no DB column — blank]
    //  pos 154-157 Scheduled hours (4, N)
    //  pos 158     Predominant delivery mode (1, A) [no DB column — blank]
    //
    // DB columns verified against install.xml. Columns that don't exist in the schema
    // are explicitly commented and left blank/zero.
    // -------------------------------------------------------------------------
    public function generate_nat00120() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // Join students table to retrieve studyreason (student demographic field).
        // studyreason is in local_rtocompliance_students, not enrolments.
        //
        // BUG-6 FIX: Previous filter `activitystartdate >= period_start AND activitystartdate <= period_end`
        // excluded enrolments that STARTED before the period but were still active or completed within it
        // (e.g., an enrolment starting Nov 2024 and ending Mar 2025 was absent from the 2025 NAT export).
        // AVETMISS NCVER requires all training activity with ANY overlap in the reporting period.
        // Correct filter: started on or before period end AND (no end date OR ended on or after period start).
        $enrolments = $DB->get_records_sql(
            "SELECT e.*,
                    s.clientid    AS studentclientid,
                    s.userid      AS studentuserid,
                    s.studyreason AS studentstudyreason,
                    s.schooltype  AS studentschooltype,
                    s.statecode   AS studentstatecode,
                    e.programcode AS programcode
             FROM {local_rtocompliance_enrolments} e
             JOIN {local_rtocompliance_students} s ON s.id = e.studentid
             WHERE (e.activitystartdate IS NULL OR e.activitystartdate <= :periodend)
               AND (e.activityenddate IS NULL OR e.activityenddate = 0 OR e.activityenddate >= :periodstart)
             ORDER BY e.activitystartdate",
            ['periodend' => $this->periodend, 'periodstart' => $this->periodstart]
        );

        foreach ($enrolments as $enrol) {
            // BUG-8 FIX: Convert invalid '00' outcome to '70' (Continuing activity)
            // instead of silently skipping the entire enrolment record. Skipping meant
            // the student's enrolment was completely absent from the AVETMISS NAT file,
            // which is a worse AVETMISS error than submitting with a corrected outcome code.
            // A-P2-3 FIX (v5.9.411): a "Continuing activity" (70) is by definition NOT
            // finished, so it must not carry a completed activity end date — emitting
            // 70 together with a past end date is internally inconsistent and NCVER
            // flags it. When we coerce a blank/'00' outcome to 70 we therefore also
            // treat the record as open (end date = reporting period end, set below),
            // rather than reporting a completion end date with no result. The
            // AVETMISS Validation page separately flags these records so the RTO
            // records the real outcome. $coerced_continuing drives the end-date choice.
            $coerced_continuing = false;
            if (empty($enrol->outcomeidentifier) || $enrol->outcomeidentifier === '00') {
                $enrol->outcomeidentifier = '70';
                $coerced_continuing = true;
            }

            $clientid  = $enrol->studentclientid ?: $enrol->studentuserid;
            $subjectid = $enrol->subjectid ?: $enrol->unitcode;
            // PROGRAM-ID-CONSISTENCY (v6.2.35): prefer the national programcode (matching
            // NAT00030 line 607 and NAT00130 line 1258). NAT00120 previously preferred the
            // internal programid, so when programid != programcode the NAT00120 program
            // identifier had no matching NAT00030 record -> AVS referential-integrity error.
            $programid = $enrol->programcode ?: $enrol->programid;
            // BUG-11 FIX: If deliverylocationid is empty AND no defaultdeliverylocation is
            // configured, NAT00120 pos 11-20 would contain 10 spaces — NCVER AVETMISS
            // validation requires a non-blank delivery location identifier and will hard-fail
            // the entire submission. Fall back to 'MAIN' (always emitted by generate_nat00020
            // as the fallback location entry) so every enrolment row is valid.
            $defaultloc = get_config('local_rtocompliance', 'defaultdeliverylocation') ?: 'MAIN';
            $locid      = $enrol->deliverylocationid ?: $defaultloc;

            $record = '';
            // --- National record (111 bytes) ---
            $record .= $this->pad($rtocode, 10);                                        // pos 1-10:   Training organisation identifier
            $record .= $this->pad($locid, 10);                                          // pos 11-20:  Training org delivery location identifier
            $record .= $this->pad($clientid, 10);                                       // pos 21-30:  Client identifier
            $record .= $this->pad($subjectid, 12);                                      // pos 31-42:  Subject identifier
            $record .= $this->pad($programid, 10);                                      // pos 43-52:  Program identifier
            $record .= $this->formatdate($enrol->activitystartdate);                    // pos 53-60:  Activity start date (DDMMYYYY)
            // BUG-14 FIX: Use reporting period end date for active enrolments with no
            // activityenddate. An empty field (8 spaces) may fail NCVER AVETMISS validation.
            // A-P2-3 FIX (v5.9.411): a record coerced to 70 (Continuing) is reported as
            // open — use the period end, never the stray recorded completion date.
            $enddate = ($coerced_continuing || empty($enrol->activityenddate))
                ? $this->periodend : $enrol->activityenddate;
            $record .= $this->formatdate($enddate);                                     // pos 61-68:  Activity end date (DDMMYYYY)
            $record .= $this->pad($enrol->deliverymode ?: '10', 3);                    // pos 69-71:  Delivery mode identifier
            $record .= $this->pad($enrol->outcomeidentifier ?: '70', 2);               // pos 72-73:  Outcome identifier – national
            $record .= $this->pad($enrol->fundingsourcenat ?: '30', 2);                // pos 74-75:  Funding source – national
            $record .= $this->pad($enrol->commencingprogramid ?: '3', 1);              // pos 76:     Commencing program identifier
            $record .= $this->pad($enrol->trainingcontractid ?? '', 10);               // pos 77-86:  Training contract identifier
            $record .= $this->pad('', 10);                                              // pos 87-96:  Client identifier – apprenticeships [no DB column]
            $record .= $this->pad($enrol->studentstudyreason ?: '@@', 2);               // pos 97-98:  Study reason identifier (from students table)
            $record .= $this->pad($enrol->vetinschoolsflag ?: 'N', 1);                // pos 99:     VET in schools flag
            $record .= $this->pad('', 10);                                              // pos 100-109: Specific funding identifier [no DB column — not purchasingcontract]
            // pos 110-111: School type identifier — map DB values (GOV/CAT/IND/OTH) to
            // AVETMISS 8.0 2-digit codes. Blank when student is not school-based.
            $schooltypemap = ['GOV' => '10', 'CAT' => '20', 'IND' => '30', 'OTH' => '@@'];
            $schooltypecode = isset($schooltypemap[$enrol->studentschooltype ?? '']) ? $schooltypemap[$enrol->studentschooltype] : '';
            $record .= $this->pad($schooltypecode, 2);                                  // pos 110-111: School type identifier
            // --- State-only fields (appended after national record) ---
            $record .= $this->pad('', 3);                                               // pos 112-114: Outcome identifier – training organisation
            // v5.9.322 TASK-23: Apply per-state default funding code when the enrolment has
            // no fundingsourcestate set AND the national code is state-funded (13 or 15).
            // Non-state-funded enrolments (e.g. 11=Commonwealth, 30=fee-for-service) must
            // remain blank here — only state funding rows require a state code at pos 115-117.
            // normalise_statecode() converts the AVETMISS 2-char DB code (01–08) stored in
            // s.statecode (aliased as studentstatecode in the SELECT) to the 3-char abbreviation
            // (QLD/NSW/…) used as map keys. The enrolments table has no statecode column, so
            // the student's statecode (which reflects the student's registered state) is used.
            $_fundingstate = $enrol->fundingsourcestate ?? '';
            if ($_fundingstate === '' && !empty($this->statefundingcodes)) {
                $_natcode = trim($enrol->fundingsourcenat ?? '');
                if ($_natcode === '13' || $_natcode === '15') {
                    $_stateabbrev = $this->normalise_statecode($enrol->studentstatecode ?? '');
                    if ($_stateabbrev !== '' && !empty($this->statefundingcodes[$_stateabbrev] ?? '')) {
                        $_fundingstate = $this->statefundingcodes[$_stateabbrev];
                    }
                }
            }
            $record .= $this->pad($_fundingstate, 3);                                  // pos 115-117: Funding source – state training authority
            // Bug 4: Round tuitionfee to nearest integer before padnum().
            // padnum() formats as zero-padded integer; passing a float like 1500.50
            // causes padnum() to produce '1500.5' which is 6 chars and corrupts the
            // fixed-width field (NAT00120 pos 118-122 is 5 numeric digits, no decimals).
            $record .= $this->padnum(round((float)($enrol->tuitionfee ?? 0)), 5); // pos 118-122: Client tuition fee (N)
            $record .= $this->pad($enrol->feeexemption ?: '@@', 2);                    // pos 123-124: Fee exemption/concession type identifier
            // v5.9.321 ORPHAN-FIX: use per-state contract from RTO Settings as fallback
            // when the enrolment row has no purchasing contract set.
            $_purchcontract = $enrol->purchasingcontract1 ?? '';
            if (empty($_purchcontract) && !empty($this->statefundingcontracts)) {
                // normalise_statecode() maps the AVETMISS 2-char DB code (01–08) stored in
                // s.statecode (aliased as studentstatecode) to the 3-char abbreviation (QLD/NSW/…).
                $_contractstate = $this->normalise_statecode($enrol->studentstatecode ?? '');
                if ($_contractstate !== '' && isset($this->statefundingcontracts[$_contractstate])) {
                    $_purchcontract = $this->statefundingcontracts[$_contractstate];
                }
            }
            $record .= $this->pad($_purchcontract, 12);                                   // pos 125-136: Purchasing contract identifier
            $record .= $this->pad('', 3);                                               // pos 137-139: Purchasing contract schedule identifier [no DB column]
            $record .= $this->padnum(0, 4);                                             // pos 140-143: Hours attended [no DB column — zero]
            $record .= $this->pad($enrol->programcode ?: ($enrol->programid ?: ''), 10); // pos 144-153: Associated course identifier (qual code)
            $record .= $this->padnum($enrol->scheduledhours ?: 0, 4);                 // pos 154-157: Scheduled hours (N)
            $record .= $this->pad('', 1);                                               // pos 158:     Predominant delivery mode [no DB column]

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // NAT00130 – Program completed
    // National record length: 39
    // Field order per AVETMISS VET Provider Collection Specifications Release 8.0:
    //  pos 1-10   Training organisation identifier (10, A)
    //  pos 11-20  Program identifier (10, A)   ← Program BEFORE Client per spec
    //  pos 21-30  Client identifier (10, A)
    //  pos 31-38  Date program completed (8, A) DDMMYYYY (activityenddate)
    //  pos 39     Issued flag (1, A) 'Y'=issued, 'N'=not yet issued
    //  --- national record ends at 39 ---
    // State-only fields:
    //  pos 40-47  Parchment issue date (8, D)
    //  pos 48-72  Parchment number (25, A)
    //
    // programoutcome values (install.xml): 01=AQF, 02=Non-AQF, 03=Not completed,
    //   04=Withdrawn, 05=Deferred. Only '01' and '02' are genuine completions.
    //
    // NOTE: USI is NOT a NAT00130 field. It belongs in NAT00080 only.
    // -------------------------------------------------------------------------
    public function generate_nat00130() {
        global $DB;
        $output  = '';
        $rtocode = get_config('local_rtocompliance', 'rtocode');

        // Only export genuine qualification completions:
        // - programoutcome '01' = Qualification completed to AQF standard
        // - programoutcome '02' = Qualification completed (non-AQF)
        // programcode must be set (NAT00130 requires a program identifier).
        //
        // BUG-7 FIX: Qual Builder stores one enrolment row per unit for the same qualification.
        // The old query returned ALL rows with programoutcome '01'/'02', emitting N NAT00130
        // records for a single qualification with N units. NCVER AVETMISS validation hard-fails
        // when it encounters more than one NAT00130 record for the same client+program.
        //
        // BUG-NAT130-DEDUP-MAXID (v5.2.18): The previous dedup JOIN used a single GROUP BY
        // subquery with both MAX(activityenddate) AS max_enddate AND MAX(id) AS max_id computed
        // independently. When a student has two completion rows for the same program with
        // DIFFERENT activityenddate values, MAX(id) and MAX(activityenddate) may belong to
        // DIFFERENT rows — so the JOIN on max_id selects the row with the OLDER date, emitting
        // the wrong completion date to NCVER.
        // Fix: Replace the JOIN dedup with a NOT EXISTS correlated subquery that correctly
        // implements "latest activityenddate; highest id as tiebreak for equal dates".
        $completions = $DB->get_records_sql(
            "SELECT e.*,
                    s.clientid AS studentclientid,
                    s.userid   AS studentuserid
             FROM {local_rtocompliance_enrolments} e
             JOIN {local_rtocompliance_students} s ON s.id = e.studentid
             WHERE e.programoutcome IN ('01','02')
               AND e.programcode IS NOT NULL AND e.programcode != ''
               AND e.activityenddate >= :periodstart AND e.activityenddate <= :periodend
               AND NOT EXISTS (
                   SELECT 1 FROM {local_rtocompliance_enrolments} e2
                   WHERE e2.studentid = e.studentid
                     AND e2.programcode = e.programcode
                     AND e2.programoutcome IN ('01','02')
                     AND e2.activityenddate >= :periodstart2 AND e2.activityenddate <= :periodend2
                     AND (e2.activityenddate > e.activityenddate
                          OR (e2.activityenddate = e.activityenddate AND e2.id > e.id))
               )
             ORDER BY e.activityenddate",
            [
                'periodstart'  => $this->periodstart,
                'periodend'    => $this->periodend,
                'periodstart2' => $this->periodstart,
                'periodend2'   => $this->periodend,
            ]
        );

        // BUG-23 FIX: $DB->get_manager() and table_exists() were called on EVERY iteration
        // of the $completions loop — one schema-metadata lookup per completion record.
        // On an RTO with hundreds of qualifications this generates hundreds of redundant
        // schema queries per NAT export. Hoist the check outside the loop: the table
        // either exists or it doesn't for the entire duration of this request.
        $dbman = $DB->get_manager();
        $certs_table_exists = $dbman->table_exists('local_rtocompliance_certs');

        foreach ($completions as $comp) {
            $clientid  = $comp->studentclientid ?: $comp->studentuserid;
            // FIX-NAT00130-PROGRAMCODE (v5.9.319): use programcode (the national
            // qualification code e.g. BSB40120) as the primary identifier, falling back
            // to programid only when programcode is blank.  Previously programid (an
            // internal DB id) was used first, which produced non-standard qual codes.
            $programid = substr($comp->programcode ?: $comp->programid, 0, 10);
            // Use activityenddate as the date program was completed (DDMMYYYY).
            $datecompleted = $this->formatdate($comp->activityenddate);
            // BUG-12 FIX: issuedflag was hardcoded 'Y', falsely asserting the parchment
            // was issued even for newly completed qualifications still pending certificate
            // generation. Check the certificates table for a real issued certificate.
            // Falls back to 'N' (not yet issued) if no matching cert record exists.
            $issuedflag = 'N';
            if ($certs_table_exists) {
                $certissued = $DB->record_exists_sql(
                    "SELECT 1 FROM {local_rtocompliance_certs}
                      WHERE studentid = :studentid
                        AND programcode = :programcode
                        AND status IN ('issued','active')",
                    ['studentid' => $comp->studentid, 'programcode' => $comp->programcode]
                );
                $issuedflag = $certissued ? 'Y' : 'N';
            }

            // FIX-NAT00130-PARCHMENT (v5.9.319): read certnumber from certs table
            // for issued certificates as the parchment number; also record issue date.
            $parchmentnumber = '';
            $parchmentdate   = '';
            if ($certs_table_exists && $issuedflag === 'Y') {
                // PORTABILITY (v5.9.395): a raw "LIMIT 1" is MySQL/Postgres-only and
                // fails on Oracle/MSSQL (both Moodle-supported). Use get_records_sql with
                // a limit parameter (0,1) and take the first row instead.
                $certrows = $DB->get_records_sql(
                    "SELECT certnumber, timecreated
                       FROM {local_rtocompliance_certs}
                      WHERE studentid = :studentid
                        AND programcode = :programcode
                        AND status IN ('issued','active')
                      ORDER BY timecreated DESC",
                    ['studentid' => $comp->studentid, 'programcode' => $comp->programcode],
                    0, 1
                );
                $certrow = $certrows ? reset($certrows) : null;
                if ($certrow) {
                    $parchmentnumber = $certrow->certnumber ?? '';
                    $parchmentdate   = !empty($certrow->timecreated)
                        ? date('dmY', $certrow->timecreated) : '';
                }
            }

            $record = '';
            // --- National record (39 bytes) ---
            $record .= $this->pad($rtocode, 10);      // pos 1-10:  Training organisation identifier
            $record .= $this->pad($programid, 10);    // pos 11-20: Program identifier (Program BEFORE Client per spec)
            $record .= $this->pad($clientid, 10);     // pos 21-30: Client identifier
            $record .= $datecompleted;                 // pos 31-38: Date program completed (DDMMYYYY)
            $record .= $this->pad($issuedflag, 1);    // pos 39:    Issued flag
            // --- State-only fields ---
            $record .= $this->pad($parchmentdate, 8);    // pos 40-47: Parchment issue date (from certs.timecreated)
            $record .= $this->pad($parchmentnumber, 25); // pos 48-72: Parchment number (from certs.certnumber)

            $output .= $record . "\r\n";
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Generate all NAT files.
    // -------------------------------------------------------------------------
    public function generate_all() {
        return [
            'NAT00010.txt' => $this->generate_nat00010(),
            'NAT00020.txt' => $this->generate_nat00020(),
            'NAT00030.txt' => $this->generate_nat00030(),
            'NAT00060.txt' => $this->generate_nat00060(),
            'NAT00080.txt' => $this->generate_nat00080(),
            'NAT00085.txt' => $this->generate_nat00085(),
            'NAT00090.txt' => $this->generate_nat00090(),
            'NAT00100.txt' => $this->generate_nat00100(),
            'NAT00120.txt' => $this->generate_nat00120(),
            'NAT00130.txt' => $this->generate_nat00130(),
        ];
    }

    public function create_zip($files) {
        global $CFG;

        $tempdir = make_temp_directory('rtocompliance/nat');
        $zipfilename = "NAT_Export_{$this->year}_" . date('Ymd_His') . '.zip';
        $zippath = $tempdir . '/' . $zipfilename;

        $zip = new \ZipArchive();
        if ($zip->open($zippath, \ZipArchive::CREATE) !== true) {
            throw new \moodle_exception('error:cannotcreatezip', 'local_rtocompliance');
        }

        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }

        $zip->close();

        return $zippath;
    }

    public function get_errors() {
        return $this->errors;
    }

    public function get_warnings() {
        return $this->warnings;
    }

    public function get_record_counts() {
        return $this->recordcounts;
    }
}
