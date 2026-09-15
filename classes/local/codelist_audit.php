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
 * RTO Compliance plugin — AVETMISS code-list integrity audit.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

use local_rtocompliance\avetmiss_codes;

/**
 * Finds student records holding a code the AVETMISS standard does not define.
 *
 * WHY THIS CLASS EXISTS (v6.3.33):
 * Up to v6.3.32 the plugin's country and language arrays were not SACC and ASCL. They
 * had the right shape - four digits, plausible labels - and were wrong from their first
 * entry onward, so nothing about them looked broken on screen or in a NAT file. The
 * damage was only visible by comparing stored codes against NCVER's own system files,
 * which is exactly what this class does. It is READ-ONLY: it reports, it never writes.
 *
 * Every consumer shares this one definition of "valid" - the audit report page, the
 * repair CLI, the upgrade step's summary, and the PHPUnit pinning tests - so none of
 * them can drift from the others.
 */
class codelist_audit {
    /**
     * Coded columns on {local_rtocompliance_students}, mapped to their code getter.
     *
     * Deliberately the same 13 fields the student profile form guards, so a field
     * cannot be protected on the form but unaudited here, or the reverse.
     *
     * @return array<string, string> column name => avetmiss_codes getter
     */
    public static function get_coded_fields(): array {
        return [
            'sex'                 => 'get_sex_codes',
            'statecode'           => 'get_state_codes',
            'countryofbirth'      => 'get_country_codes',
            'languageathome'      => 'get_language_codes',
            'englishproficiency'  => 'get_english_proficiency_codes',
            'indigenousstatus'    => 'get_indigenous_status_codes',
            'disabilityflag'      => 'get_disability_codes',
            'highestschoollevel'  => 'get_school_level_codes',
            'atschoolflag'        => 'get_at_school_flag_codes',
            'labourforcestatus'   => 'get_labour_force_status_codes',
            'studyreason'         => 'get_study_reason_codes',
            'prioreducationflag'  => 'get_prior_education_flag_codes',
            'surveycontactstatus' => 'get_survey_contact_codes',
        ];
    }

    /**
     * Count students per (field, stored code) for every value outside the standard.
     *
     * Counting is done in PHP against a GROUP BY of the distinct stored values rather
     * than with a NOT IN (...) clause per field. A NOT IN would need up to 504
     * bind parameters for languageathome alone, which exceeds the parameter limit on
     * some supported drivers, and would have to be rebuilt every time NCVER revises a
     * list. Grouping first keeps the query to one row per distinct code - at most a
     * few hundred rows for the whole table, whatever the student count.
     *
     * @param string|null $onlyfield Audit a single field, or null for all of them.
     * @return array list of objects with ->field, ->code, ->students
     */
    public static function get_invalid_counts(?string $onlyfield = null): array {
        global $DB;

        $out = [];
        foreach (self::get_coded_fields() as $field => $getter) {
            if ($onlyfield !== null && $field !== $onlyfield) {
                continue;
            }
            $valid = avetmiss_codes::$getter();

            // The column name is interpolated, never a bind parameter - it comes from
            // the hard-coded map above and is not reachable from a request.
            $rows = $DB->get_records_sql(
                "SELECT $field AS code, COUNT(*) AS students
                   FROM {local_rtocompliance_students}
                  GROUP BY $field"
            );
            foreach ($rows as $row) {
                // NULL and '' mean "never answered", which the AVETMISS profile gate
                // already reports. They are not invalid CODES, so they are not counted
                // here - doing so would bury the real defects under empty records.
                if ($row->code === null || (string)$row->code === '') {
                    continue;
                }
                if (array_key_exists((string)$row->code, $valid)) {
                    continue;
                }
                $out[] = (object)[
                    'field'    => $field,
                    'code'     => (string)$row->code,
                    'students' => (int)$row->students,
                ];
            }
        }

        usort($out, function ($a, $b) {
            return [$b->students, $a->field, $a->code] <=> [$a->students, $b->field, $b->code];
        });
        return $out;
    }

    /**
     * Total number of student records holding at least one out-of-standard code.
     *
     * Not the sum of get_invalid_counts() - one student can be wrong in two fields.
     *
     * @return int
     */
    public static function count_affected_students(): int {
        global $DB;

        $fields = self::get_coded_fields();
        $valid = [];
        foreach ($fields as $field => $getter) {
            $valid[$field] = avetmiss_codes::$getter();
        }

        // ONE pass over the table, reading only the coded columns. Thirteen separate
        // "SELECT id, <field>" queries would walk the whole table thirteen times.
        $columns = 'id, ' . implode(', ', array_keys($fields));
        $affected = 0;
        $rs = $DB->get_recordset_sql("SELECT $columns FROM {local_rtocompliance_students}");
        foreach ($rs as $row) {
            foreach ($fields as $field => $unused) {
                $value = $row->$field;
                if ($value === null || (string)$value === '') {
                    continue;
                }
                if (!array_key_exists((string)$value, $valid[$field])) {
                    $affected++;
                    break;
                }
            }
        }
        $rs->close();
        return $affected;
    }

    /**
     * Enrolment-level gaps the plugin deliberately refuses to guess at.
     *
     * Two fields are required by the specification and have no safe default:
     *
     *  - Delivery mode identifier. Release 8.0 requires a three-character Y/N triplet and
     *    says the field must not be blank. Up to v6.3.34 the schema defaulted it to '10',
     *    a Release 7.0 code, and the generator wrote that straight out - so every
     *    enrolment nobody had configured silently claimed classroom-only delivery. The
     *    default is now empty and the generator passes empty through, which makes the
     *    file invalid on purpose: a blank is detectably wrong and gets fixed, a
     *    fabricated 'YNN' is undetectably wrong and gets lodged.
     *  - Outcome identifier. '00' was the schema default and is not a code in the
     *    standard. It is now '70' (Continuing activity), which is valid, but a row still
     *    holding '00' from before the upgrade is reported here.
     *
     * Counted per enrolment, not per student, because that is the unit NCVER reports.
     *
     * @return array list of objects with ->field, ->problem, ->enrolments
     */
    public static function get_enrolment_gaps(): array {
        global $DB;

        $out = [];

        $unset = $DB->count_records_select('local_rtocompliance_enrolments',
            "deliverymode IS NULL OR TRIM(deliverymode) = ''");
        if ($unset > 0) {
            $out[] = (object)[
                'field'      => 'deliverymode',
                'problem'    => 'not recorded - the specification requires this field and the '
                              . 'plugin will not invent a delivery mode',
                'enrolments' => (int)$unset,
            ];
        }

        // Release 7.0 numeric codes still stored. These are CONVERTED on output, so the
        // file is valid - this is reported so the gap is known, not because it breaks.
        $numeric = $DB->count_records_select('local_rtocompliance_enrolments',
            "deliverymode IN ('10','20','30','40','90')");
        if ($numeric > 0) {
            $out[] = (object)[
                'field'      => 'deliverymode',
                'problem'    => 'Release 7.0 numeric code - converted to the Release 8.0 Y/N '
                              . 'triplet on export, but never recorded against the standard',
                'enrolments' => (int)$numeric,
            ];
        }

        $validoutcomes = array_keys(avetmiss_codes::get_outcome_identifiers());
        [$insql, $params] = $DB->get_in_or_equal($validoutcomes, SQL_PARAMS_NAMED, 'oc', false);
        $badoutcome = $DB->count_records_select('local_rtocompliance_enrolments',
            "outcomeidentifier IS NOT NULL AND TRIM(outcomeidentifier) <> ''
             AND outcomeidentifier $insql", $params);
        if ($badoutcome > 0) {
            $out[] = (object)[
                'field'      => 'outcomeidentifier',
                'problem'    => 'not a code in the AVETMISS standard',
                'enrolments' => (int)$badoutcome,
            ];
        }

        return $out;
    }

    /**
     * A one-line summary for logs and the upgrade output.
     *
     * @return string
     */
    public static function summarise(): string {
        $invalid = self::get_invalid_counts();
        if (!$invalid) {
            return 'AVETMISS code lists: no student record holds an undefined code.';
        }
        $total = self::count_affected_students();
        $parts = [];
        foreach (array_slice($invalid, 0, 6) as $row) {
            $parts[] = "{$row->field}={$row->code} ({$row->students})";
        }
        $more = count($invalid) > 6 ? ' and ' . (count($invalid) - 6) . ' more' : '';
        return "AVETMISS code lists: $total student record(s) hold a code the standard does "
             . 'not define. Worst: ' . implode(', ', $parts) . $more
             . '. See Reports > AVETMISS code-list integrity.';
    }
}
