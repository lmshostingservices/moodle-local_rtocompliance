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

/**
 * v5.9.371 NAT-RESULTS-REGISTER
 *
 * Populates the plugin's OWN results register (local_rtocompliance_enrolments)
 * directly from an imported NAT file's staging rows (local_rtocompliance_avetmiss_enrolment).
 *
 * Design guarantees (this class NEVER touches Moodle):
 *   - It writes ONLY to local_rtocompliance_enrolments (the plugin register).
 *   - It does NOT create or delete Moodle {user} accounts.
 *   - It does NOT create or delete Moodle {user_enrolments} or {course_completions}.
 *   - It does NOT create student rows (existing students only, matched by clientid).
 *     Brand-new historical students with no plugin record are returned as "unmatched"
 *     for a review export; importing those as standalone records is the separate
 *     student-decouple step.
 *
 * The register is what the Student Results page, the Qualification Certificate Hub,
 * Generate-by-Qualification and Record-of-Results already read — so populating it
 * here makes historical NAT outcomes appear across those screens automatically.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class results_importer {
    /** @var string[] Valid AVETMISS national outcome identifiers we accept from NAT files. */
    // v5.9.440: '41' (incomplete due to RTO closure) is a valid AVETMISS outcome the NAT
    // parser accepts and the Student Results grid can display, but it was missing here — so an
    // imported '41' was silently collapsed to '00' (which renders as "?"). Added so it survives.
    const VALID_OUTCOMES = ['20', '30', '40', '41', '51', '52', '53', '60', '61', '70', '81', '82', '85', '90', '00'];

    /**
     * Populate the results register from a completed NAT import's staging rows.
     *
     * @param int $importid  local_rtocompliance_imports id (the staged import batch)
     * @return array counts + unmatched clientids:
     *   ['written'=>int, 'updated'=>int, 'skipped_manual'=>int, 'students'=>int, 'unmatched'=>string[]]
     */
    public static function populate_from_import(int $importid): array {
        global $DB;

        $out = ['written' => 0, 'updated' => 0, 'skipped_manual' => 0, 'students' => 0, 'unmatched' => []];
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_avetmiss_enrolment')
            || !$dbman->table_exists('local_rtocompliance_students')
            || !$dbman->table_exists('local_rtocompliance_enrolments')) {
            return $out;
        }

        $staged = $DB->get_records('local_rtocompliance_avetmiss_enrolment', ['importid' => $importid]);
        if (empty($staged)) {
            return $out;
        }

        // ── Match staged clientids to EXISTING plugin students (no rows are created) ──
        $clientids = [];
        foreach ($staged as $r) {
            $c = strtoupper(trim((string) $r->clientid));
            if ($c !== '') {
                $clientids[$c] = true;
            }
        }
        $clientids = array_keys($clientids);
        $sidByClient = [];
        // Client IDs are numeric strings, so a direct match is correct (no case folding
        // needed). Chunk to stay within IN() parameter limits.
        foreach (array_chunk($clientids, 1000) as $chunk) {
            list($insql, $inparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cid');
            $rows = $DB->get_records_select('local_rtocompliance_students',
                "clientid $insql", $inparams, '', 'id, clientid, userid');
            foreach ($rows as $sr) {
                $sidByClient[strtoupper(trim((string) $sr->clientid))] = (int) $sr->id;
            }
        }
        $out['students'] = count($sidByClient);

        // ── Resolve a Moodle courseid + unit name per unit for nicer display/linking ──
        // (Display only — resolving a course id here does NOT enrol anyone.)
        $courseByUnit = [];
        $nameByUnit   = [];
        self::build_unit_maps($staged, $courseByUnit, $nameByUnit);

        $now = time();
        $unmatched = [];

        foreach ($staged as $row) {
            $cid = strtoupper(trim((string) $row->clientid));
            if ($cid === '' || !isset($sidByClient[$cid])) {
                if ($cid !== '') {
                    $unmatched[$cid] = true;
                }
                continue;
            }
            $unitcode = strtoupper(trim((string) $row->unitcode));
            if ($unitcode === '') {
                continue;
            }
            $studentid = $sidByClient[$cid];
            $qualcode  = strtoupper(trim((string) $row->qualcode));
            $outcome   = self::map_outcome((string) ($row->outcome ?? ''));
            $courseid  = $courseByUnit[$unitcode] ?? 0;

            // Idempotent: match an existing register row on (studentid, unitcode, programcode).
            $existing = $DB->get_records_select('local_rtocompliance_enrolments',
                "studentid = :sid AND UPPER(unitcode) = :uc AND "
                . "(UPPER(COALESCE(programcode,'')) = :pc)",
                ['sid' => $studentid, 'uc' => $unitcode, 'pc' => $qualcode],
                'id ASC', '*', 0, 1);
            $existing = $existing ? reset($existing) : null;

            $rec = self::build_record($studentid, $courseid, $unitcode,
                $nameByUnit[$unitcode] ?? null, $qualcode, $row, $outcome, $now);

            if ($existing) {
                // Never clobber a manually-set outcome (an assessor edit is authoritative).
                if ((int) ($existing->manualoutcome ?? 0) === 1) {
                    $out['skipped_manual']++;
                    continue;
                }
                $rec->id          = $existing->id;
                $rec->timecreated = $existing->timecreated ?: $now;
                $DB->update_record('local_rtocompliance_enrolments', $rec);
                $out['updated']++;
            } else {
                $DB->insert_record('local_rtocompliance_enrolments', $rec);
                $out['written']++;
            }
        }

        $out['unmatched'] = array_keys($unmatched);
        return $out;
    }

    /**
     * Return the clientids in an import's staging that have NO matching plugin student.
     * Used by the "students not in your system" review export. Read-only.
     *
     * @param int $importid
     * @return array[] each: ['clientid','name','email','qualcodes'=>string]
     */
    public static function unmatched_students(int $importid): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_avetmiss_student')) {
            return [];
        }
        $staged = $DB->get_records('local_rtocompliance_avetmiss_student', ['importid' => $importid]);
        if (empty($staged)) {
            return [];
        }
        // Qual codes seen per client (from staged enrolments) for context.
        $qualsByClient = [];
        if ($dbman->table_exists('local_rtocompliance_avetmiss_enrolment')) {
            $er = $DB->get_records('local_rtocompliance_avetmiss_enrolment', ['importid' => $importid], '', 'id, clientid, qualcode');
            foreach ($er as $e) {
                $c = strtoupper(trim((string) $e->clientid));
                $q = strtoupper(trim((string) $e->qualcode));
                if ($c !== '' && $q !== '') {
                    $qualsByClient[$c][$q] = true;
                }
            }
        }
        $rows = [];
        foreach ($staged as $s) {
            $cid = strtoupper(trim((string) $s->clientid));
            if ($cid === '') {
                continue;
            }
            $exists = $DB->record_exists('local_rtocompliance_students', ['clientid' => (string) $s->clientid]);
            if ($exists) {
                continue;
            }
            $rows[] = [
                'clientid'  => (string) $s->clientid,
                'name'      => trim((string) ($s->name ?? trim(($s->firstname ?? '') . ' ' . ($s->familyname ?? '')))),
                'email'     => (string) ($s->email ?? ''),
                'qualcodes' => isset($qualsByClient[$cid]) ? implode(' ', array_keys($qualsByClient[$cid])) : '',
            ];
        }
        return $rows;
    }

    /**
     * Map a raw NAT outcome value to a valid AVETMISS national outcome identifier.
     */
    private static function map_outcome(string $raw): string {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return '00';
        }
        // National outcome identifier is 2 chars; some SMS pad to more.
        $code = substr(preg_replace('/[^0-9]/', '', $raw), 0, 2);
        if ($code !== '' && in_array($code, self::VALID_OUTCOMES, true)) {
            return $code;
        }
        return '00';
    }

    /**
     * Build a register record from a staged NAT enrolment row.
     * Only the meaningful fields are set; NOT NULL columns not set here take their
     * schema defaults (deliverymode=10, fundingsourcenat=30, vetflag=Y, etc.).
     */
    private static function build_record(int $studentid, int $courseid, string $unitcode,
            ?string $unitname, string $qualcode, \stdClass $row, string $outcome, int $now): \stdClass {
        $rec = new \stdClass();
        $rec->studentid         = $studentid;
        $rec->courseid          = $courseid; // 0 when the unit has no Moodle course (historical).
        $rec->programcode       = $qualcode !== '' ? $qualcode : null;
        $rec->unitcode          = $unitcode;
        if ($unitname !== null && $unitname !== '') {
            $rec->unitname = $unitname;
        }
        $rec->outcomeidentifier = $outcome;
        $rec->manualoutcome     = 0;
        $rec->activitystartdate = self::parse_nat_date((string) ($row->startdate ?? ''));
        $rec->activityenddate   = self::parse_nat_date((string) ($row->enddate ?? ''));
        if (!empty($row->fundingsource)) {
            $rec->fundingsourcenat = substr((string) $row->fundingsource, 0, 2);
        }
        if (isset($row->supervisedhours) && $row->supervisedhours !== null && $row->supervisedhours !== '') {
            $rec->scheduledhours = (int) $row->supervisedhours;
        }
        $rec->status       = 'active';
        $rec->timecreated  = $now;
        $rec->timemodified = $now;
        return $rec;
    }

    /**
     * Parse a NAT fixed-width date (DDMMYYYY) to a unix timestamp, or null.
     */
    private static function parse_nat_date(string $d): ?int {
        $d = trim($d);
        if ($d === '' || preg_match('/^[@\s]+$/', $d)) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $d);
        if (strlen($digits) === 8) {
            $day = (int) substr($digits, 0, 2);
            $mon = (int) substr($digits, 2, 2);
            $yr  = (int) substr($digits, 4, 4);
            if ($day >= 1 && $day <= 31 && $mon >= 1 && $mon <= 12 && $yr >= 1900) {
                return make_timestamp($yr, $mon, $day, 0, 0, 0, 99, false);
            }
        }
        return null;
    }

    /**
     * Build unitcode → Moodle courseid and unitcode → unit name maps for the staged units,
     * from the Qualification Builder (qualunits.courseid) and confirmed course_map entries.
     * Resolving a course id is for display/linking only and never enrols anyone.
     */
    private static function build_unit_maps(array $staged, array &$courseByUnit, array &$nameByUnit): void {
        global $DB;
        $dbman = $DB->get_manager();
        $units = [];
        foreach ($staged as $r) {
            $u = strtoupper(trim((string) $r->unitcode));
            if ($u !== '') {
                $units[$u] = true;
            }
        }
        $units = array_keys($units);
        if (empty($units)) {
            return;
        }
        foreach (array_chunk($units, 1000) as $chunk) {
            list($insql, $inparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            if ($dbman->table_exists('local_rtocompliance_qualunits')) {
                $rs = $DB->get_records_select('local_rtocompliance_qualunits',
                    "UPPER(unitcode) $insql", $inparams, '', 'id, unitcode, unitname, courseid');
                foreach ($rs as $q) {
                    $u = strtoupper(trim((string) $q->unitcode));
                    if (!isset($nameByUnit[$u]) && !empty($q->unitname)) {
                        $nameByUnit[$u] = $q->unitname;
                    }
                    if (!isset($courseByUnit[$u]) && !empty($q->courseid)) {
                        $courseByUnit[$u] = (int) $q->courseid;
                    }
                }
            }
            if ($dbman->table_exists('local_rtocompliance_course_map')) {
                $rs = $DB->get_records_select('local_rtocompliance_course_map',
                    "UPPER(unitcode) $insql AND confirmed = 1 AND courseid > 0", $inparams,
                    'confirmed DESC, id ASC', 'id, unitcode, courseid');
                foreach ($rs as $m) {
                    $u = strtoupper(trim((string) $m->unitcode));
                    if (!isset($courseByUnit[$u]) && !empty($m->courseid)) {
                        $courseByUnit[$u] = (int) $m->courseid;
                    }
                }
            }
        }
    }
}
