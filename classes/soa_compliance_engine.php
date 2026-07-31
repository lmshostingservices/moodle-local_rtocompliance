<?php
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

    // AVETMISS outcome codes that mean "competency achieved".
    const COMPETENT_OUTCOMES = ['20', '51', '52', '81', '82'];

    const OUTCOME_LABELS = [
        '20' => 'Competency achieved/pass',
        '51' => 'RPL — granted',
        '52' => 'Credit transfer',
        '53' => 'RPL — not granted',
        '60' => 'Continuing enrolment',
        '70' => 'Withdrawn',
        '81' => 'Superseded — competency achieved',
        '82' => 'Superseded — not achieved',
        '30' => 'Not yet achieved/fail',
        '40' => 'Withdrawn/not competent',
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
                    c.shortname, c.fullname,
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
            $unitcode = strtoupper(trim($comp->shortname));
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
            $u->completiondate   = (int)$comp->timecompleted;
            $u->outcomeidentifier = $outcome;
            $u->outcomeLabel     = self::OUTCOME_LABELS[$outcome] ?? $outcome;
            $u->compliance       = $compliance;
            $u->compliant        = empty($compliance['errors']);
            $u->already_on_soa  = isset($issuedCodes[$unitcode]);

            $units[] = $u;
        }

        usort($units, function($a, $b) {
            $c = strcmp($a->categoryname, $b->categoryname);
            return $c !== 0 ? $c : strcmp($a->unitcode, $b->unitcode);
        });

        return $units;
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
            $key = $unit->categoryid;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'categoryid'   => $unit->categoryid,
                    'categoryname' => $unit->categoryname,
                    'units'        => [],
                ];
            }
            $groups[$key]['units'][] = $unit;
        }
        usort($groups, function($a, $b) { return count($b['units']) - count($a['units']); });
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
            $snap->qualcategoryname    = $unit->categoryname;
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
