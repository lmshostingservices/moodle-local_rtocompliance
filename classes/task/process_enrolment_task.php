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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class process_enrolment_task extends \core\task\adhoc_task {
    private const THROTTLE_SECONDS = 60;

    public function get_name() {
        return get_string('task_process_enrolment', 'local_rtocompliance');
    }

    public static function queue_if_not_pending($data) {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT id, customdata FROM {task_adhoc}
             WHERE classname = :classname
               AND timecreated > :cutoff
             ORDER BY id DESC",
            [
                'classname' => '\\' . self::class,
                'cutoff' => time() - self::THROTTLE_SECONDS,
            ],
            0,
            100
        );

        foreach ($records as $record) {
            $existingdata = @json_decode($record->customdata, true);
            if ($existingdata === null || $existingdata === false) {
                $existingdata = @unserialize($record->customdata);
            }

            if (is_object($existingdata)) {
                $existingdata = (array)$existingdata;
            }

            if (!is_array($existingdata)) {
                continue;
            }

            $existingaction  = $existingdata['action']   ?? null;
            $existinguserid  = $existingdata['userid']   ?? null;
            $existingcourseid = $existingdata['courseid'] ?? null;

            if ($existingaction === $data['action'] &&
                (int)$existinguserid  === (int)$data['userid'] &&
                (int)$existingcourseid === (int)$data['courseid']) {
                return false;
            }
        }

        $task = new self();
        $task->set_custom_data($data);
        $task->set_component('local_rtocompliance');
        \core\task\manager::queue_adhoc_task($task, true);
        return true;
    }

    public function execute() {
        global $DB;

        \core_php_time_limit::raise(300); // FIX-TIMEOUT-TASK: adhoc tasks processing large enrolment batches can exceed 30s default

        $data = $this->get_custom_data();

        if (empty($data->action)) {
            return;
        }

        $dbman = $DB->get_manager();

        if (!$dbman->table_exists('local_rtocompliance_students')) {
            return;
        }
        if (!$dbman->table_exists('local_rtocompliance_enrolments')) {
            return;
        }
        if (!$dbman->table_exists('local_rtocompliance_courses')) {
            return;
        }

        switch ($data->action) {
            case 'create':
                $this->process_enrolment_created($data);
                break;
            case 'delete':
                $this->process_enrolment_deleted($data);
                break;
            case 'complete':
                $this->process_course_completed($data);
                break;
            case 'check_completion':
                $this->process_check_qualification_completion($data);
                break;
        }

        \local_rtocompliance\cache_helper::invalidate_dashboard_metrics();
    }

    // -------------------------------------------------------------------------
    // FIX 1: Look up local_rtocompliance_qualunits to populate unitcode on the
    // enrolment record, and derive programcode from the Qual Builder rather than
    // relying solely on the old course_settings qualificationcode field.
    // Also accepts courses that are linked via Qual Builder even if not flagged
    // as nationallyrecognised in the old course_settings table.
    // -------------------------------------------------------------------------
    private function process_enrolment_created($data) {
        global $DB;

        $userid   = $data->userid;
        $courseid = $data->courseid;

        // --- Resolve all units and qualifications from Qual Builder (primary source of truth) ---
        // FIX: A single Moodle course can be linked to multiple qual units (e.g. shared
        // delivery course covering the same unit in two qualifications). We must create
        // one RTO enrolment record per linked qual unit, not just the first one found.
        $qualunits = [];
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_qualunits')) {
            $qualunits = $DB->get_records_sql(
                "SELECT qu.*, qb.qualificationcode, qb.qualificationname
                   FROM {local_rtocompliance_qualunits} qu
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE qu.courseid = :courseid
                    AND qb.status != 'superseded'
               ORDER BY qb.status ASC, qu.id ASC",
                ['courseid' => $courseid]
            );
        }

        // QB-VARIANT-UNION-FIX (v5.9.293): Always check qualunit_courses regardless of
        // whether the primary lookup found anything.  The old OR/fallback pattern meant
        // a course that is the PRIMARY delivery course for unit A in QB-record-1 AND
        // simultaneously a VARIANT course for unit B in QB-record-2 would never create
        // enrolments for QB-record-2 (the variant path was skipped because primary found
        // QB-record-1).  We now ALWAYS run the variant query and merge the results.
        // Dedup by qu.id: if a unit row already came from the primary lookup, the primary
        // courseid is authoritative — do not overwrite it with the variant courseid.
        if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
            // TASK-VARIANT-ARCHIVE-FIX (v5.9.297): is_archive filter was missing —
            // archived/superseded semester courses (is_archive=1) were creating live
            // AVETMISS enrolment records for students.  Only non-archived variants
            // (is_archive=0 or NULL) should produce enrolments.
            $arcQualunits = $DB->get_records_sql(
                "SELECT qu.*, qb.qualificationcode, qb.qualificationname
                   FROM {local_rtocompliance_qualunit_courses} quc
                   JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE quc.courseid = :courseid
                    AND (quc.is_archive IS NULL OR quc.is_archive = 0)
                    AND qb.status != 'superseded'
               ORDER BY qb.status ASC, qu.id ASC",
                ['courseid' => $courseid]
            );
            // Rewrite courseid on variant rows to the actual course the student is
            // enrolled in (the variant/archive course), then merge into $qualunits.
            // Skip any qu.id already present from the primary lookup.
            foreach ($arcQualunits as $qid => $aqRow) {
                if (!isset($qualunits[$qid])) {
                    $aqRow->courseid = $courseid;
                    $qualunits[$qid] = $aqRow;
                }
            }
        }

        // --- Fallback 1: legacy course_settings (nationallyrecognised flag) ---
        $coursesettings = \local_rtocompliance\cache_helper::get_course_settings($courseid);
        $nationallyrecognised = $coursesettings && $coursesettings->nationallyrecognised;

        // --- Fallback 2: category.idnumber as programcode + course.idnumber as unitcode ---
        // Covers RTOs that have set idnumber fields correctly (best practice) but have not
        // yet configured Qual Builder records or a nationallyrecognised course_settings entry.
        // Both codes must match the AVETMISS pattern (2-7 uppercase letters + 3-5 digits)
        // to be trusted — prevents garbage data from unrelated idnumber fields.
        $catFallbackProgramcode = '';
        $catFallbackUnitcode    = '';
        if (empty($qualunits) && !$nationallyrecognised) {
            $courserow = $DB->get_record_sql(
                "SELECT c.idnumber AS courseidnumber, cat.idnumber AS catidnumber
                   FROM {course} c
                   JOIN {course_categories} cat ON cat.id = c.category
                  WHERE c.id = :courseid",
                ['courseid' => $courseid]
            );
            if ($courserow) {
                $_cfProg = strtoupper(trim((string)($courserow->catidnumber    ?? '')));
                $_cfUnit = strtoupper(trim((string)($courserow->courseidnumber ?? '')));
                // End-anchor ($) is critical: without it, slash-format idnumbers like
                // "LIX0036125/LIX0036125" falsely match (regex stops after 5 digits).
                // With $ the full string must be a clean AVETMISS code — nothing extra.
                $_cfRx   = '/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/';
                if (preg_match($_cfRx, $_cfProg) && preg_match($_cfRx, $_cfUnit)) {
                    $catFallbackProgramcode = $_cfProg;
                    $catFallbackUnitcode    = $_cfUnit;
                }
            }
        }

        // If no Qual Builder link, no nationally-recognised flag, and no valid idnumber
        // fallback, this course is genuinely unlinked from AVETMISS — skip it entirely.
        // ENROLMENT-SKIP-LOG (v5.9.304): previously a completely silent return with no
        // log entry. Admins had no way to know why enrolment events fired but produced
        // no RTO compliance record. Added debugging log so it shows in cron output and
        // developer debug logs when the site has debug enabled.
        if (empty($qualunits) && !$nationallyrecognised && $catFallbackProgramcode === '') {
            debugging(
                'rtocompliance process_enrolment_task: skipping courseid=' . $courseid
                . ' userid=' . $userid . ' — not linked to any Qual Builder unit, '
                . 'not flagged nationallyrecognised, and no valid AVETMISS idnumber fallback.',
                DEBUG_DEVELOPER
            );
            return;
        }

        // --- Find or create student record ---
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            $student = new \stdClass();
            $student->userid              = $userid;
            $student->clientid            = '';
            $student->usi                 = '';
            $student->usiverified         = 0;
            $student->indigenousstatus    = '@';
            $student->countryofbirth      = '1101';
            $student->languageathome      = '1201';
            $student->englishproficiency  = '@';
            $student->disabilityflag      = 'N';
            $student->highestschoollevel  = '@@';
            $student->labourforcestatus   = '@@';
            $student->studyreason         = '@@';
            $student->prioreducationflag  = '@';
            // Bug 39: sex defaults to '@' (not stated/unknown) per AVETMISS 2.3.
            $student->sex                 = '@';
            $student->surveycontactstatus = 'N';
            $student->atschoolflag        = 'N';
            $student->profilecomplete     = 0;
            $student->timecreated         = time();
            $student->timemodified        = time();

            // Race condition mitigation -- two parallel task runners could both read
            // "no student record" and both proceed to insert. The try/catch handles this
            // IF a UNIQUE constraint exists on local_rtocompliance_students.userid.
            try {
                $student->id = $DB->insert_record('local_rtocompliance_students', $student);
            } catch (\dml_exception $e) {
                if (strpos($e->getMessage(), 'duplicate') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false) {
                    $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
                    if (!$student) {
                        return;
                    }
                } else {
                    throw $e;
                }
            }
        }

        // --- Create one RTO enrolment per linked qual unit ---
        // If the course has no qual builder linkage, fall back to a single course-level record.
        if (!empty($qualunits)) {
            // BUG-COMMENCING-LOOP FIX (v4.9.167): resolve_commencing_id was previously called
            // INSIDE the foreach loop. On a course with 2+ units for the same qualification the
            // first insert writes an 'active' row; the second call then finds that row and returns
            // '3' (Continuing) instead of '1' (Commencing) — even for a brand-new student.
            // Fix: build a per-programcode cache BEFORE any inserts so all units in the same
            // qualification share the same commencing ID resolution snapshot.
            $commencing_by_program = [];
            foreach ($qualunits as $qualunit) {
                $pc = $qualunit->qualificationcode ?? '';
                if (!isset($commencing_by_program[$pc])) {
                    $commencing_by_program[$pc] = $this->resolve_commencing_id($student->id, $pc);
                }
            }

            foreach ($qualunits as $qualunit) {
                $programcode = $qualunit->qualificationcode ?? '';
                $programname = $qualunit->qualificationname ?? '';
                $unitcode    = $qualunit->unitcode ?? '';
                $unitname    = $qualunit->unitname ?? '';

                // BUG-REENROL-WITHDRAWN FIX (v4.9.167): record_exists() with no status filter
                // matched withdrawn rows, blocking re-enrolment after a student was unenrolled.
                // A student who was withdrawn and then re-enrolled in Moodle never got a new
                // active RTO enrolment record — they'd remain permanently withdrawn in AVETMISS.
                // Fix: exclude withdrawn rows from the duplicate check so re-enrolment creates
                // a fresh active record as expected.
                $exists = $DB->record_exists_sql(
                    "SELECT 1 FROM {local_rtocompliance_enrolments}
                      WHERE studentid = :studentid
                        AND courseid  = :courseid
                        AND unitcode  = :unitcode
                        AND status   != 'withdrawn'",
                    ['studentid' => $student->id, 'courseid' => $courseid, 'unitcode' => $unitcode]
                );
                if ($exists) {
                    continue;
                }

                $commencingprogramid = $commencing_by_program[$programcode] ?? '1';

                $deliverymode = '10';
                if ($coursesettings && !empty($coursesettings->deliverymode)) {
                    $deliverymode = $coursesettings->deliverymode;
                } elseif (!empty($qualunit->deliverymode)) {
                    $deliverymode = $qualunit->deliverymode;
                }

                $enrolment = new \stdClass();
                $enrolment->studentid           = $student->id;
                $enrolment->courseid            = $courseid;
                $enrolment->programcode         = $programcode;
                $enrolment->programname         = $programname;
                $enrolment->unitcode            = $unitcode;
                $enrolment->unitname            = $unitname;
                $enrolment->activitystartdate   = time();
                $enrolment->outcomeidentifier   = '70';
                $enrolment->deliverymode        = $deliverymode;
                $enrolment->fundingsourcenat    = '30';
                $enrolment->vetflag             = 'Y';
                $enrolment->vetinschoolsflag    = 'N';
                $enrolment->commencingprogramid = $commencingprogramid;
                $enrolment->feecharged          = 'Y';
                $enrolment->status              = 'active';
                $enrolment->timecreated         = time();
                $enrolment->timemodified        = time();

                try {
                    $DB->insert_record('local_rtocompliance_enrolments', $enrolment);
                } catch (\dml_exception $e) {
                    if (strpos($e->getMessage(), 'duplicate') === false &&
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        } else {
            // No Qual Builder linkage. Two sub-cases:
            //
            // (a) nationallyrecognised via legacy course_settings — create a single
            //     course-level record with no unitcode (historical behaviour preserved).
            //
            // (b) category/course idnumber fallback — both codes were validated as real
            //     AVETMISS codes above; create a unit-level record so AVETMISS exports
            //     contain the correct programcode and unitcode without needing Qual Builder.
            //
            // BUG-REENROL-WITHDRAWN FIX (v4.9.167): exclude withdrawn rows so
            // re-enrolment after unenrolment creates a fresh active record.

            if ($nationallyrecognised) {
                $programcode  = $coursesettings->qualificationcode ?? '';
                $programname  = $coursesettings->qualificationname ?? '';
                $unitcode     = '';
                $unitname     = '';
            } else {
                // Category idnumber fallback — codes already validated in the guard above.
                $programcode  = $catFallbackProgramcode;
                $programname  = '';
                $unitcode     = $catFallbackUnitcode;
                $unitname     = '';
            }

            $deliverymode        = ($coursesettings && !empty($coursesettings->deliverymode))
                                   ? $coursesettings->deliverymode : '10';
            $commencingprogramid = $this->resolve_commencing_id($student->id, $programcode);

            // Duplicate check includes unitcode so the same course can legitimately
            // produce multiple unit records when the fallback fires for different units.
            $exists = $DB->record_exists_sql(
                "SELECT 1 FROM {local_rtocompliance_enrolments}
                  WHERE studentid = :studentid
                    AND courseid  = :courseid
                    AND unitcode  = :unitcode
                    AND status   != 'withdrawn'",
                ['studentid' => $student->id, 'courseid' => $courseid, 'unitcode' => $unitcode]
            );
            if ($exists) {
                return;
            }

            $enrolment = new \stdClass();
            $enrolment->studentid           = $student->id;
            $enrolment->courseid            = $courseid;
            $enrolment->programcode         = $programcode;
            $enrolment->programname         = $programname;
            $enrolment->unitcode            = $unitcode;
            $enrolment->unitname            = $unitname;
            $enrolment->activitystartdate   = time();
            $enrolment->outcomeidentifier   = '70';
            $enrolment->deliverymode        = $deliverymode;
            $enrolment->fundingsourcenat    = '30';
            $enrolment->vetflag             = 'Y';
            $enrolment->vetinschoolsflag    = 'N';
            $enrolment->commencingprogramid = $commencingprogramid;
            $enrolment->feecharged          = 'Y';
            $enrolment->status              = 'active';
            $enrolment->timecreated         = time();
            $enrolment->timemodified        = time();

            try {
                $DB->insert_record('local_rtocompliance_enrolments', $enrolment);
            } catch (\dml_exception $e) {
                if (strpos($e->getMessage(), 'duplicate') === false &&
                    strpos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Resolve AVETMISS commencingprogramid for a student + program combination.
     * '1' = Commencing, '3' = Continuing, '4' = Recommencing (previously withdrawn).
     */
    private function resolve_commencing_id(int $studentid, string $programcode): string {
        global $DB;
        if (!$programcode) {
            return '1';
        }
        $priorwithdrawn = $DB->record_exists_sql(
            "SELECT 1 FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND programcode = :programcode AND status = 'withdrawn'",
            ['studentid' => $studentid, 'programcode' => $programcode]
        );
        if ($priorwithdrawn) {
            return '4';
        }
        $prioractive = $DB->record_exists_sql(
            "SELECT 1 FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND programcode = :programcode AND status IN ('active','completed')",
            ['studentid' => $studentid, 'programcode' => $programcode]
        );
        return $prioractive ? '3' : '1';
    }

    // -------------------------------------------------------------------------
    // AVETMISS 2.3 withdrawal: '40' = Withdrawn/discontinued (correct).
    // Note: '60' = Credit transfer/national recognition -- a different concept.
    // -------------------------------------------------------------------------
    private function process_enrolment_deleted($data) {
        global $DB;

        $userid   = $data->userid;
        $courseid = $data->courseid;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return;
        }

        // BUG-1 FIX: Qual Builder creates one enrolment row per unit for the same course.
        // get_record() only ever returns the FIRST matching row — any additional active
        // enrolments for the same student+course are silently left as 'active' forever,
        // creating ghost AVETMISS records. Use get_records() and withdraw every active row.
        $enrolments = $DB->get_records('local_rtocompliance_enrolments', [
            'studentid' => $student->id,
            'courseid'  => $courseid,
            'status'    => 'active',
        ]);

        $now = time();
        foreach ($enrolments as $enrolment) {
            // BUG-SR-OUTCOME-AUTOREVERT (v4.2.27): same manager-locked guard as
            // process_course_completed — preserve a manually set outcome even
            // when Moodle fires user_enrolment_deleted.  We still mark the row
            // withdrawn (it IS no longer active) but the outcome the manager
            // saved (e.g. '60' Credit Transfer, '51' RPL) stays put.
            $manuallocked = !empty($enrolment->manualoutcome);
            $enrolment->status            = 'withdrawn';
            if (!$manuallocked) {
                $enrolment->outcomeidentifier = '40';    // AVETMISS 2.3: '40' = Withdrawn/discontinued
            }
            $enrolment->activityenddate   = $now;
            $enrolment->timemodified      = $now;
            $DB->update_record('local_rtocompliance_enrolments', $enrolment);

            try {
                \local_rtocompliance\audit_logger::log(
                    \local_rtocompliance\audit_logger::ACTION_UPDATE,
                    'enrolment',
                    (int)$enrolment->id,
                    $userid,
                    'Enrolment withdrawn (Moodle unenrolment event)',
                    ['courseid' => $courseid, 'studentid' => $student->id]
                );
            } catch (\Throwable $e) {
                debugging('rtocompliance audit log failed on withdrawal: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    // -------------------------------------------------------------------------
    // FIX 3: Course completion = Competent ('20') for RTO/VET purposes.
    // In Moodle, the RTO configures completion conditions (quiz at 100%,
    // assignment graded satisfactory, etc.) so that the course only marks
    // "complete" when ALL competency criteria are met. A Moodle course
    // completion therefore directly maps to AVETMISS outcome '20' Competent.
    //
    // After marking competent, checks whether ALL units of the linked
    // qualification are now competent and queues auto-certificate if so.
    // -------------------------------------------------------------------------
    private function process_course_completed($data) {
        global $DB;

        $userid   = $data->userid;
        $courseid = $data->courseid;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return;
        }

        // BUG-2 FIX: Same get_record() flaw as process_enrolment_deleted.
        // Qual Builder creates one enrolment row per unit/qual link for the same course.
        // get_record() returns only the FIRST matching row; remaining active unit enrolments
        // for the same course would never be marked competent, producing ghost 'active' rows
        // in NAT00120 that never resolve. Use get_records() and complete every active row.
        $enrolments = $DB->get_records('local_rtocompliance_enrolments', [
            'studentid' => $student->id,
            'courseid'  => $courseid,
            'status'    => 'active',
        ]);

        $now = time();
        foreach ($enrolments as $enrolment) {
            // BUG-SR-OUTCOME-AUTOREVERT (v4.2.27): manager-set outcomes are the
            // legal AVETMISS record-of-truth — never auto-overwrite them.  We
            // still bump status='completed' (the course IS complete) and the
            // timestamp, but leave outcomeidentifier as the manager set it.
            $manuallocked = !empty($enrolment->manualoutcome);
            $enrolment->status            = 'completed';
            if (!$manuallocked) {
                $enrolment->outcomeidentifier = '20';    // 20 = Competent
            }
            $enrolment->activityenddate   = $now;
            // Do NOT set programoutcome='01' or programcompletedyear here.
            // These represent qualification-level completion, not unit completion.
            // They are set by queue_autocert_if_all_units_complete() only when ALL
            // units of the qualification are competent.
            $enrolment->timemodified      = $now;
            $DB->update_record('local_rtocompliance_enrolments', $enrolment);
        }

        if (!empty($enrolments)) {
            // Check if the student has now completed all units of any linked qualification.
            $this->queue_autocert_if_all_units_complete($student->id, $userid, $courseid);
        }
    }

    // -------------------------------------------------------------------------
    // FIX 4: Check qualification completion triggered by user_graded observer
    // when a grade-based competency result arrives after course completion.
    // -------------------------------------------------------------------------
    private function process_check_qualification_completion($data) {
        global $DB;

        $userid   = $data->userid;
        $courseid = $data->courseid;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return;
        }

        $this->queue_autocert_if_all_units_complete($student->id, $userid, $courseid);
    }

    // -------------------------------------------------------------------------
    // Helper: Find all qualifications that contain this course as a unit, then
    // check whether all units of each qualification are now competent for this
    // student. If yes, add an entry to the auto-cert queue.
    // -------------------------------------------------------------------------
    private function queue_autocert_if_all_units_complete(int $studentid, int $userid, int $courseid) {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_qualunits') ||
            !$dbman->table_exists('local_rtocompliance_qualbuilder') ||
            !$dbman->table_exists('local_rtocompliance_autocerts')) {
            return;
        }

        // Find all qualifications that have this course as a linked unit.
        // Primary lookup: qualunits.courseid stores the main delivery course.
        $qualbuilderids = $DB->get_fieldset_sql(
            "SELECT DISTINCT qualbuilderid FROM {local_rtocompliance_qualunits}
              WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );

        // QB-VARIANT-UNION-FIX (v5.9.293): Always check qualunit_courses too — do not
        // use it only as a fallback.  A course can be the PRIMARY course for one QB
        // record AND a VARIANT/ARCHIVE course for another.  The old OR/fallback meant
        // the second QB's autocert check was never triggered.  We now always merge both
        // sets so qualification-completion detection fires for every QB that references
        // this course in either table.
        if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
            // TASK-VARIANT-ARCHIVE-FIX (v5.9.297): same is_archive=0 guard applied
            // to the autocert qualbuilderid lookup so that completing a course that is
            // archived in qualunit_courses does not trigger qualification-completion
            // checks for stale/historical qualifier records.
            $variantQbids = $DB->get_fieldset_sql(
                "SELECT DISTINCT qu.qualbuilderid
                   FROM {local_rtocompliance_qualunit_courses} quc
                   JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                  WHERE quc.courseid = :courseid
                    AND (quc.is_archive IS NULL OR quc.is_archive = 0)",
                ['courseid' => $courseid]
            );
            if ($variantQbids) {
                $qualbuilderids = array_values(array_unique(
                    array_merge($qualbuilderids, $variantQbids)
                ));
            }
        }

        if (!$qualbuilderids) {
            return;
        }

        // AVETMISS 2.3 codes that represent a positive/final competency outcome:
        // 20 = Competent, 51 = RPL granted, 60 = Credit transfer, 81 = Non-assessable satisfactory.
        // Note: 30 = Fail, 40 = Withdrawn -- these must NOT count toward qualification completion.
        $competentcodes = ['20', '51', '60', '81'];
        list($insql, $inparams) = $DB->get_in_or_equal($competentcodes);

        foreach ($qualbuilderids as $qualbuilderid) {
            $qualbuilder = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid]);
            if (!$qualbuilder || $qualbuilder->status === 'superseded') {
                continue;
            }

            // Get all selected units for this qualification.
            $qualunits = $DB->get_records('local_rtocompliance_qualunits', [
                'qualbuilderid' => $qualbuilderid,
                'selected'      => 1,
                'status'        => 'active',
            ]);

            if (!$qualunits) {
                continue;
            }

            // Check every unit has a competent enrolment for this student.
            $allcompetent = true;
            foreach ($qualunits as $unit) {
                if (!$unit->unitcode) {
                    continue;
                }
                $params = array_merge([$studentid, $unit->unitcode], $inparams);
                $found  = $DB->record_exists_sql(
                    "SELECT 1 FROM {local_rtocompliance_enrolments}
                      WHERE studentid = ? AND unitcode = ? AND outcomeidentifier $insql",
                    $params
                );
                if (!$found) {
                    $allcompetent = false;
                    break;
                }
            }

            if (!$allcompetent) {
                continue;
            }

            // All units competent - add to auto-cert queue if not already pending or done.
            $existing = $DB->get_record('local_rtocompliance_autocerts', [
                'studentid'     => $studentid,
                'qualbuilderid' => $qualbuilderid,
            ]);

            if ($existing && in_array($existing->status, ['pending', 'processing', 'complete'])) {
                continue;
            }

            $certtypes  = $qualbuilder->producttype === 'qualification' ? 'testamur,record' : 'statement';
            $creditcost = $qualbuilder->producttype === 'qualification' ? 10 : 5;

            $autocert = new \stdClass();
            $autocert->studentid     = $studentid;
            $autocert->qualbuilderid = $qualbuilderid;
            $autocert->certtypes     = $certtypes;
            $autocert->creditcost    = $creditcost;
            $autocert->status        = 'pending';

            if ($existing) {
                // Bug 14: On retry (failed/error status), preserve completiondate,
                // certsissued, and emailsent from the prior attempt so the certificate
                // engine does not re-send certificates that were already issued.
                $autocert->id             = $existing->id;
                $autocert->completiondate = $existing->completiondate;
                $autocert->creditdeducted = $existing->creditdeducted;
                $autocert->certsissued    = $existing->certsissued;
                $autocert->emailsent      = $existing->emailsent;
                $autocert->timecreated    = $existing->timecreated;
                $DB->update_record('local_rtocompliance_autocerts', $autocert);
            } else {
                $autocert->completiondate = time();
                $autocert->creditdeducted = 0;
                $autocert->certsissued    = 0;
                $autocert->emailsent      = 0;
                $autocert->timecreated    = time();
                $DB->insert_record('local_rtocompliance_autocerts', $autocert);
            }

            // Bug 1+2/15: Mark programoutcome='01' (AQF qualification completed) and
            // programcompletedyear on the MOST RECENTLY COMPLETED enrolment for this
            // student+qualification pair. This is the only point where the full
            // qualification is confirmed complete, so NAT00130 will have exactly one
            // record per student per qualification (not one per unit).
            $latestrec = $DB->get_record_sql(
                "SELECT id FROM {local_rtocompliance_enrolments}
                  WHERE studentid = :studentid
                    AND programcode = :programcode
                    AND status = 'completed'
                  ORDER BY activityenddate DESC, id DESC",
                ['studentid' => $studentid, 'programcode' => $qualbuilder->qualificationcode],
                IGNORE_MULTIPLE
            );
            if ($latestrec) {
                $DB->set_field('local_rtocompliance_enrolments', 'programoutcome',
                    '01', ['id' => $latestrec->id]);
                // BUG-15 FIX: date('Y') uses the server timezone (typically UTC) and returns
                // the CURRENT year — not the year the student actually completed.
                // A cron job running on Jan 2 2026 for a student who completed Dec 31 2025
                // would write programcompletedyear='2026', corrupting AVETMISS NAT00130.
                // Fix: derive year from the enrolment's activityenddate in Australia/Sydney TZ.
                $fullrec = $DB->get_record('local_rtocompliance_enrolments',
                    ['id' => $latestrec->id], 'activityenddate');
                $compYear = date('Y'); // fallback: current year if no activityenddate
                if (!empty($fullrec->activityenddate)) {
                    $tz = new \DateTimeZone('Australia/Sydney');
                    $dt = new \DateTime('@' . (int)$fullrec->activityenddate);
                    $dt->setTimezone($tz);
                    $compYear = $dt->format('Y');
                }
                $DB->set_field('local_rtocompliance_enrolments', 'programcompletedyear',
                    $compYear, ['id' => $latestrec->id]);
            }
        }
    }
}
