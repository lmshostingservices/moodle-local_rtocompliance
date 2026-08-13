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
 * RTO Compliance plugin — soa_compliance_engine.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.6.101 MULTI-UNIT-SOA — Backend compliance engine.
// Fetches eligible units for a student from Moodle course completions,
// runs per-unit AQF/ASQA compliance checks, groups units into suggested
// SOA bundles, and writes immutable compliance snapshots at issue time.
//
// Moodle object mapping:
//   Category   → Qualification / Training Package
//   Course     → Unit of Competency  (course shortname = unit code)
//   Completion → Competency outcome

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class soa_compliance_engine {
    // AVETMISS (NAT00120) outcome identifiers that mean "competency achieved"
    // and therefore make a unit eligible for inclusion on a Statement of Attainment.
    //   20 = Competency achieved / pass
    //   51 = RPL granted
    //   60 = Credit transfer / national recognition
    //   81 = Non-assessable enrolment — satisfactorily completed
    const COMPETENT_OUTCOMES = ['20', '51', '60', '81'];

    // Full AVETMISS 8.0 national outcome identifier labels.
    const OUTCOME_LABELS = [
        '20' => 'Competency achieved/pass',
        '30' => 'Competency not achieved/fail',
        '40' => 'Withdrawn/discontinued',
        '51' => 'RPL granted',
        '52' => 'RPL not granted',
        '60' => 'Credit transfer/national recognition',
        '61' => 'Superseded subject',
        '70' => 'Continuing activity',
        '81' => 'Non-assessable enrolment — satisfactorily completed',
        '82' => 'Non-assessable enrolment — withdrawn/not satisfactorily completed',
        '85' => 'Not started',
        '00' => 'Result not finalised',
    ];

    /**
     * Return every unit the student has completed that is eligible for SOA inclusion.
     *
     * Each element is a stdClass with:
     *   courseid, unitcode, unittitle, categoryid, categoryname,
     *   completiondate, outcomeidentifier, outcomeLabel,
     *   compliance['errors'], compliance['warnings'],
     *   compliant (bool), already_on_soa (bool)
     *
     * @param int  $userid
     * @param bool $override   When true, include units with non-competent outcomes
     * @return stdClass[]
     */
    /**
     * Resolve a course's national unit-of-competency code from the course itself, in the order
     * that survives semester course-copying: the course ID number when it is a valid code, else
     * the national code prefix in the course full name (e.g. "TLIX5049 Determine indirect taxes
     * (DIT) 20S2" -> TLIX5049), else the shortname as a last resort. Read from the course — never
     * the courseid — because every semester copy is a new courseid but keeps the same code in its
     * name, so this is the key that lets duplicate semester copies de-dup back to one unit.
     *
     * @param  string|null $idnumber  course.idnumber
     * @param  string|null $fullname  course.fullname
     * @param  string|null $shortname course.shortname
     * @return string  the national unit code (upper-cased), or '' if none can be found
     */
    private static function resolve_national_unit_code($idnumber, $fullname, $shortname): string {
        // A nationally recognised unit/qual code: 2–8 letters then 3–6 digits, optional 0–2
        // trailing letters (TLIX5049, TLIR4001, BSBOPS505, TLIX0008, TLI50816). Same shape as the
        // canonical local_rtocompliance_extract_unit_code_from_name() so both agree on what a code is.
        $id = strtoupper(trim((string)$idnumber));
        if ($id !== '' && preg_match('/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})$/', $id)) {
            return $id;
        }
        $fn = strtoupper(trim((string)$fullname));
        if ($fn !== '' && preg_match('/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/', $fn, $m)) {
            return $m[1];
        }
        return strtoupper(trim((string)$shortname));
    }

    public static function get_eligible_units(int $userid, bool $override = false): array {
        global $DB;

        $dbman = $DB->get_manager();

        // ── Course completions ───────────────────────────────────────────────
        // SOA-DELETED-COL-FIX (v5.9.254): removed AND c.deleted = 0 — Moodle's {course}
        // table has NO deleted column (courses are hard-deleted from the table). This
        // condition caused a dml_read_exception on every getstudent and getunits AJAX
        // call, producing "Student not found" and "Error reading from database" for
        // every student regardless of their actual data.
        $completions = $DB->get_records_sql(
            "SELECT cc.id AS ccid,
                    cc.course AS courseid,
                    cc.timecompleted,
                    c.shortname, c.fullname, c.idnumber AS courseidnumber,
                    c.category,
                    cat.name  AS catname,
                    cat.idnumber AS catidnumber
             FROM   {course_completions} cc
             JOIN   {course} c            ON c.id   = cc.course
             JOIN   {course_categories} cat ON cat.id = c.category
             WHERE  cc.userid = :uid
               AND  cc.timecompleted IS NOT NULL
               AND  cc.timecompleted > 0",
            ['uid' => $userid]
        );

        if (empty($completions)) {
            return [];
        }

        // ── AVETMISS outcome per course (from enrolments table if present) ───
        $outcomeMap = [];  // courseid => outcomeidentifier
        if ($dbman->table_exists('local_rtocompliance_enrolments') &&
            $dbman->table_exists('local_rtocompliance_students')) {
            $stud = $DB->get_record('local_rtocompliance_students',
                ['userid' => $userid], 'id', IGNORE_MISSING);
            if ($stud) {
                $rows = $DB->get_records_sql(
                    "SELECT courseid, outcomeidentifier
                     FROM {local_rtocompliance_enrolments}
                     WHERE studentid = :sid ORDER BY id DESC",
                    ['sid' => $stud->id]
                );
                foreach ($rows as $row) {
                    if (!isset($outcomeMap[$row->courseid])) {
                        $outcomeMap[$row->courseid] = $row->outcomeidentifier;
                    }
                }
            }
        }

        // ── Student record for USI check ─────────────────────────────────────
        $student = null;
        if ($dbman->table_exists('local_rtocompliance_students')) {
            $student = $DB->get_record('local_rtocompliance_students',
                ['userid' => $userid], 'id, usi, usiverified', IGNORE_MISSING);
        }

        $moodleuser  = \core_user::get_user($userid);
        $useractive  = $moodleuser && !$moodleuser->deleted && !$moodleuser->suspended;

        // ── Units already on an issued SOA for this student ──────────────────
        $issuedCodes = [];
        $existingSoas = $DB->get_records_sql(
            "SELECT units FROM {local_rtocompliance_certs}
             WHERE userid = :uid AND certtype = 'statement' AND status = 'issued'",
            ['uid' => $userid]
        );
        foreach ($existingSoas as $soa) {
            if (!empty($soa->units)) {
                foreach ((json_decode($soa->units, true) ?: []) as $u) {
                    if (!empty($u['code'])) {
                        $issuedCodes[strtoupper(trim($u['code']))] = true;
                    }
                }
            }
        }
        if ($dbman->table_exists('local_rtocompliance_soa_snapshot')) {
            $snaps = $DB->get_records_sql(
                "SELECT sn.unitcode
                 FROM {local_rtocompliance_soa_snapshot} sn
                 JOIN {local_rtocompliance_certs} c ON c.id = sn.certid
                 WHERE sn.userid = :uid AND c.status = 'issued'",
                ['uid' => $userid]
            );
            foreach ($snaps as $sn) {
                $issuedCodes[strtoupper(trim($sn->unitcode))] = true;
            }
        }

        // ── Build unit list ──────────────────────────────────────────────────
        $units = [];
        foreach ($completions as $comp) {
            // v6.2.86 TREE-FIRST CODE: resolve the national unit code from the course (ID number ->
            // full-name prefix -> shortname) instead of blindly using the shortname, which at many
            // RTOs is a semester code ("DIT 20S2") rather than the national code (TLIX5049).
            $unitcode = self::resolve_national_unit_code(
                $comp->courseidnumber ?? '', $comp->fullname ?? '', $comp->shortname ?? ''
            );
            $outcome  = $outcomeMap[$comp->courseid] ?? '20';

            if (!$override && !in_array($outcome, self::COMPETENT_OUTCOMES, true)) {
                continue;
            }

            $compliance = self::check_unit_compliance(
                $userid, $comp->courseid, $unitcode, $outcome, $student, $useractive
            );

            $u = new \stdClass();
            $u->courseid         = (int)$comp->courseid;
            $u->unitcode         = $unitcode;
            $u->unittitle        = $comp->fullname;
            $u->categoryid       = (int)$comp->category;
            $u->categoryname     = $comp->catname;
            $u->categoryidnumber = $comp->catidnumber ?? '';
            // v5.9.369 QUAL-RESOLVE: the immediate Moodle category is usually a
            // semester/archive folder ("Archive s21"), not the qualification. Keep it
            // as the semester label; the real qualification is resolved below.
            $u->semesterlabel    = $comp->catname;
            $u->completiondate   = (int)$comp->timecompleted;
            $u->outcomeidentifier = $outcome;
            $u->outcomeLabel     = self::OUTCOME_LABELS[$outcome] ?? $outcome;
            $u->compliance       = $compliance;
            $u->compliant        = empty($compliance['errors']);
            $u->already_on_soa  = isset($issuedCodes[$unitcode]);

            $units[] = $u;
        }

        // v6.2.86 DE-DUPLICATE ACROSS SEMESTER COPIES. The same national unit is delivered as a
        // fresh Moodle course every semester (course copy => new courseid), so a learner who did
        // TLIX5049 across two intakes has two completions in two courses. Collapse to ONE row per
        // resolved national unit code (the key that survives course-copying; courseid does not).
        // Keep policy: a competent outcome always beats a non-competent one; among equal competency
        // the latest completion date wins. semestercopies records how many copies were collapsed so
        // the UI can show "(latest of N intakes)" if wanted; the kept row's courseid/date/outcome is
        // the one that will appear on the SOA.
        $byCode = [];
        foreach ($units as $u) {
            // Never merge blank codes together (they are genuinely unidentified units).
            $key = ($u->unitcode !== '') ? $u->unitcode : ('COURSE:' . $u->courseid);
            if (!isset($byCode[$key])) {
                $u->semestercopies = 1;
                $byCode[$key] = $u;
                continue;
            }
            $cur = $byCode[$key];
            $copies = ($cur->semestercopies ?? 1) + 1;
            $incumbentCompetent  = in_array($cur->outcomeidentifier, self::COMPETENT_OUTCOMES, true);
            $challengerCompetent = in_array($u->outcomeidentifier, self::COMPETENT_OUTCOMES, true);
            $take = false;
            if ($challengerCompetent && !$incumbentCompetent) {
                $take = true;
            } else if ($challengerCompetent === $incumbentCompetent
                    && (int)$u->completiondate > (int)$cur->completiondate) {
                $take = true;
            }
            if ($take) {
                $u->semestercopies = $copies;
                $byCode[$key] = $u;
            } else {
                $cur->semestercopies = $copies;
            }
        }
        $units = array_values($byCode);

        // v5.9.369 QUAL-RESOLVE: attach the real qualification to every unit from the
        // source-of-truth mapping (Course → Category → Qualification), so units group
        // by qualification (e.g. "ABC12345 — Certificate IV in ...") instead of the raw
        // semester category, and Step 3 can auto-fill the qual code + name.
        $qualmap = self::resolve_qualifications_for_courses(
            array_map(function ($u) { return $u->courseid; }, $units)
        );
        foreach ($units as $u) {
            $q = $qualmap[$u->courseid] ?? null;
            if ($q && $q['qualcode'] !== '') {
                $u->qualcode       = $q['qualcode'];
                $u->qualname       = $q['qualname'] !== '' ? $q['qualname'] : $q['qualcode'];
                $u->qualtype       = $q['qualtype'];
                $u->qualsource     = $q['source'];
                $u->qualgroupkey   = 'qual:' . $q['qualcode'];
                $u->qualgrouplabel = $q['qualname'] !== ''
                    ? ($q['qualcode'] . ' — ' . $q['qualname'])
                    : $q['qualcode'];
            } else {
                // Unmapped — fall back to the raw category so nothing disappears.
                $u->qualcode       = '';
                $u->qualname       = '';
                $u->qualtype       = '';
                $u->qualsource     = 'none';
                $u->qualgroupkey   = 'cat:' . $u->categoryid;
                $u->qualgrouplabel = $u->categoryname;
            }
        }

        usort($units, function ($a, $b) {
            $c = strcmp($a->qualgrouplabel, $b->qualgrouplabel);
            return $c !== 0 ? $c : strcmp($a->unitcode, $b->unitcode);
        });

        return $units;
    }

    /**
     * v5.9.369 — Resolve the qualification each completed course (unit) belongs to,
     * using the RTO's source-of-truth mappings in priority order:
     *   1. course_map           (courseid → qualcode; confirmed rows preferred)
     *   2. Qualification Builder direct link (qualunits.courseid)
     *   3. Qualification Builder variant/archive courses (qualunit_courses.courseid)
     * The qualification NAME is taken from the Qualification Builder for the resolved
     * qualcode. Returns [courseid => ['qualcode','qualname','qualtype','source']].
     *
     * @param  int[] $courseids
     * @return array
     */
    public static function resolve_qualifications_for_courses(array $courseids): array {
        global $DB;
        $out = [];
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (empty($courseids)) {
            return $out;
        }
        $dbman = $DB->get_manager();

        // qualcode → {name,type} from the Qualification Builder (active products only).
        $qualNames = [];
        if ($dbman->table_exists('local_rtocompliance_qualbuilder')) {
            $qbs = $DB->get_records_select('local_rtocompliance_qualbuilder',
                "status = 'active'", [], '', 'id, qualificationcode, qualificationname, producttype');
            foreach ($qbs as $qb) {
                $qualNames[strtoupper(trim($qb->qualificationcode))] = [
                    'name' => $qb->qualificationname,
                    'type' => $qb->producttype ?: 'qualification',
                ];
            }
        }

        $addhit = function (int $cid, string $qcode, string $source) use (&$out, $qualNames) {
            if (isset($out[$cid])) {
                return; // first (higher-priority) hit wins
            }
            $qcode = strtoupper(trim($qcode));
            if ($qcode === '') {
                return;
            }
            $out[$cid] = [
                'qualcode' => $qcode,
                'qualname' => $qualNames[$qcode]['name'] ?? '',
                'qualtype' => $qualNames[$qcode]['type'] ?? 'qualification',
                'source'   => $source,
            ];
        };

        // ── #1 course_map (source of truth) — confirmed rows first ───────────────
        if ($dbman->table_exists('local_rtocompliance_course_map')) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cm');
            $rows = $DB->get_records_sql(
                "SELECT id, courseid, qualcode, confirmed
                   FROM {local_rtocompliance_course_map}
                  WHERE courseid $insql AND qualcode <> ''
                  ORDER BY confirmed DESC, id ASC", $inparams);
            foreach ($rows as $r) {
                $addhit((int)$r->courseid, (string)$r->qualcode, 'map');
            }
        }

        // ── #2 Qualification Builder direct link ─────────────────────────────────
        $need = array_values(array_diff($courseids, array_keys($out)));
        if ($need && $dbman->table_exists('local_rtocompliance_qualunits')
                  && $dbman->table_exists('local_rtocompliance_qualbuilder')) {
            list($insql, $inparams) = $DB->get_in_or_equal($need, SQL_PARAMS_NAMED, 'qb');
            $rows = $DB->get_records_sql(
                "SELECT qu.id, qu.courseid, qb.qualificationcode
                   FROM {local_rtocompliance_qualunits} qu
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE qu.courseid $insql AND qu.selected = 1 AND qb.status = 'active'
                  ORDER BY qb.id ASC", $inparams);
            foreach ($rows as $r) {
                $addhit((int)$r->courseid, (string)$r->qualificationcode, 'qualbuilder');
            }
        }

        // ── #3 Qualification Builder variant / archive (semester-copy) courses ───
        $need = array_values(array_diff($courseids, array_keys($out)));
        if ($need && $dbman->table_exists('local_rtocompliance_qualunit_courses')
                  && $dbman->table_exists('local_rtocompliance_qualbuilder')) {
            list($insql, $inparams) = $DB->get_in_or_equal($need, SQL_PARAMS_NAMED, 'qc');
            $rows = $DB->get_records_sql(
                "SELECT quc.id, quc.courseid, qb.qualificationcode
                   FROM {local_rtocompliance_qualunit_courses} quc
                   JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE quc.courseid $insql AND qb.status = 'active'
                  ORDER BY qb.id ASC", $inparams);
            foreach ($rows as $r) {
                $addhit((int)$r->courseid, (string)$r->qualificationcode, 'qualbuilder');
            }
        }

        return $out;
    }

    /**
     * Run per-unit AQF/ASQA compliance checks.
     *
     * @return array ['errors' => string[], 'warnings' => string[]]
     */
    public static function check_unit_compliance(
        int $userid, int $courseid, string $unitcode,
        string $outcome, $student, bool $useractive
    ): array {
        $errors   = [];
        $warnings = [];

        if (!in_array($outcome, self::COMPETENT_OUTCOMES, true)) {
            $errors[] = 'Outcome is not competency achieved (' . $outcome . ')';
        }

        if (!$useractive) {
            $errors[] = 'Student account is suspended or deleted';
        }

        if ($student) {
            if (empty($student->usi)) {
                $errors[] = 'USI not recorded — Clause 12 requires a verified USI';
            } else {
                // USI_STATUS: 0=UNVERIFIED, 1=VERIFIED, 2=FAILED, 3=PENDING, 4=MANUAL_REVIEW.
                // Using empty() was wrong — it treated 2/3/4 as truthy (verified).
                // FAILED is a compliance error; PENDING/UNVERIFIED/REVIEW are warnings.
                $vstat = (int) $student->usiverified;
                if ($vstat === 2) { // STATUS_FAILED
                    $errors[] = 'USI verification failed — student details do not match the USI Registry record. The student must correct their USI or details (Clause 12).';
                } else if ($vstat !== 1) { // anything other than STATUS_VERIFIED
                    $warnings[] = 'USI recorded but not yet verified with USI Registry (Clause 12)';
                }
            }
        } else {
            $warnings[] = 'Student not in RTO Compliance student register';
        }

        if (empty($unitcode) || strlen($unitcode) < 4) {
            $errors[] = 'Unit code missing — set Moodle course shortname to the unit code';
        } else if (!preg_match('/\d/', $unitcode)) {
            $warnings[] = 'Unit code has no digit — confirm this is a nationally recognised unit code';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Group eligible units into suggested SOA bundles by qualification category.
     *
     * @param  stdClass[] $units  Result of get_eligible_units()
     * @return array              [{categoryid, categoryname, units: [...]}]
     */
    public static function get_suggested_groups(array $units): array {
        $groups = [];
        foreach ($units as $unit) {
            // v5.9.369: group by the resolved qualification, not the raw semester category.
            $key = $unit->qualgroupkey ?? ('cat:' . $unit->categoryid);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'groupkey'     => $key,
                    'qualcode'     => $unit->qualcode ?? '',
                    'qualname'     => $unit->qualname ?? '',
                    'qualtype'     => $unit->qualtype ?? '',
                    'source'       => $unit->qualsource ?? 'none',
                    'label'        => $unit->qualgrouplabel ?? $unit->categoryname,
                    'semesters'    => [],
                    // Back-compat: keep the (first) category for any legacy consumer.
                    'categoryid'   => $unit->categoryid,
                    'categoryname' => $unit->categoryname,
                    'units'        => [],
                ];
            }
            $groups[$key]['units'][] = $unit;
            $sem = $unit->semesterlabel ?? $unit->categoryname;
            if ($sem !== '' && !in_array($sem, $groups[$key]['semesters'], true)) {
                $groups[$key]['semesters'][] = $sem;
            }
        }
        // Resolved qualifications first, then by unit count (largest bundle first).
        usort($groups, function ($a, $b) {
            $ar = ($a['source'] !== 'none') ? 0 : 1;
            $br = ($b['source'] !== 'none') ? 0 : 1;
            if ($ar !== $br) { return $ar - $br; }
            return count($b['units']) - count($a['units']);
        });
        return array_values($groups);
    }

    /**
     * Write immutable SOA compliance snapshots for a newly issued certificate.
     * Called immediately after the cert row is inserted so historical SOA
     * records survive Moodle course renames/deletions.
     *
     * @param int        $certid
     * @param int        $userid
     * @param int        $issuedby
     * @param stdClass[] $units    Selected units from get_eligible_units()
     */
    public static function save_soa_snapshot(int $certid, int $userid, int $issuedby, array $units): void {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_soa_snapshot')) {
            return;
        }

        $now = time();
        // Wrap all inserts in a transaction so the snapshot is either complete
        // or entirely absent — no partial audit records on process death.
        $transaction = $DB->start_delegated_transaction();
        foreach ($units as $unit) {
            $snap = new \stdClass();
            $snap->certid              = $certid;
            $snap->userid              = $userid;
            $snap->issuedby            = $issuedby;
            $snap->unitcode            = $unit->unitcode;
            $snap->unittitle           = $unit->unittitle;
            $snap->moodlecourseid      = $unit->courseid;
            $snap->qualcategoryid      = $unit->categoryid;
            // v5.9.369: store the resolved qualification label when known (falls back to
            // the raw category) so the immutable snapshot names the qualification.
            $snap->qualcategoryname    = !empty($unit->qualgrouplabel) ? $unit->qualgrouplabel : $unit->categoryname;
            $snap->completiondate      = $unit->completiondate;
            $snap->outcomeidentifier   = $unit->outcomeidentifier;
            $snap->snapshottime        = $now;
            $DB->insert_record('local_rtocompliance_soa_snapshot', $snap);
        }
        $transaction->allow_commit();
    }

    /**
     * Return a summary object for the student info panel.
     *
     * @param int $userid
     * @return array
     */
    public static function get_student_summary(int $userid): array {
        global $DB;

        $moodleuser = \core_user::get_user($userid);
        if (!$moodleuser) {
            return ['error' => 'User not found'];
        }

        $dbman = $DB->get_manager();

        $result = [
            'userid'       => $userid,
            'fullname'     => fullname($moodleuser),
            'email'        => $moodleuser->email,
            'idnumber'     => $moodleuser->idnumber,
            'active'       => !$moodleuser->deleted && !$moodleuser->suspended,
            'usi'          => null,
            'usiverified'  => false,
        ];

        if ($dbman->table_exists('local_rtocompliance_students')) {
            $stud = $DB->get_record('local_rtocompliance_students',
                ['userid' => $userid], 'id, usi, usiverified', IGNORE_MISSING);
            if ($stud) {
                $result['usi']         = $stud->usi;
                $result['usiverified'] = (int)$stud->usiverified === 1; // STATUS_VERIFIED only
            }
        }

        // SOA-DELETED-COL-FIX (v5.9.254): removed AND c.deleted = 0 (same fix as get_eligible_units).
        $result['completedunits'] = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM {course_completions} cc
             JOIN {course} c ON c.id = cc.course
             WHERE cc.userid = :uid AND cc.timecompleted IS NOT NULL AND cc.timecompleted > 0",
            ['uid' => $userid]
        );

        $result['existingsoas'] = (int)$DB->count_records('local_rtocompliance_certs',
            ['userid' => $userid, 'certtype' => 'statement', 'status' => 'issued']);

        return $result;
    }
}
