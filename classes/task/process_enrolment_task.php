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
 * RTO Compliance plugin — process_enrolment_task.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
                // Hardened: never instantiate arbitrary objects from stored data.
                $existingdata = @unserialize($record->customdata, ['allowed_classes' => false]);
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
        global $DB, $CFG;
        // Explicitly load lib.php — Moodle's task runner does not guarantee plugin
        // lib files are auto-included before adhoc task execution.
        require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

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
        // Keep course settings for delivery mode resolution only — programcode is now
        // derived exclusively from the Qual Builder (primary) or the Moodle category
        // tree (below). The old nationallyrecognised flag and idnumber fallbacks are removed.
        $coursesettings = \local_rtocompliance\cache_helper::get_course_settings($courseid);

        // COURSE-MAP-TABLE (v5.9.335) + CATEGORY-TREE-DETECTION (v5.9.329)
        //
        // When a course has no Qual Builder link, resolve the qual/unit codes.
        // Priority: (1) course_map table — single indexed lookup, admin-verified;
        //           (2) BFS category ancestor walk — slower, fires only on map miss.
        //
        // The RTO organises Moodle like this:
        //   "ABC12345 — a Diploma qualification"   ← top-level category
        //       └── "2023 S1"
        //             └── "ABC12345 CL1 2023 S1"       ← course
        //       └── "2024 S2"
        //             └── "ABC12345 CL1 2024 S2"
        $catDetectedProgramcode = '';
        $catDetectedUnitcode    = '';
        if (empty($qualunits)) {
            // Try course map table first (fast path).
            if ($dbman->table_exists('local_rtocompliance_course_map')) {
                $mapRec = $DB->get_record(
                    'local_rtocompliance_course_map',
                    ['courseid' => $courseid],
                    'qualcode, unitcode',
                    IGNORE_MISSING
                );
                if ($mapRec && !empty($mapRec->qualcode)) {
                    $catDetectedProgramcode = (string)$mapRec->qualcode;
                    $catDetectedUnitcode    = (string)($mapRec->unitcode ?? '');
                }
            }
            // Fallback: BFS ancestor walk (map absent or no entry for this course).
            if ($catDetectedProgramcode === '') {
                $catDetectedProgramcode = $this->detect_qualcode_from_category_ancestors($courseid);
                if ($catDetectedProgramcode !== '') {
                    $courseNameRow = $DB->get_record('course', ['id' => $courseid], 'fullname, shortname');
                    if ($courseNameRow) {
                        $catDetectedUnitcode =
                            $this->extract_avetmiss_code_from_name($courseNameRow->fullname)
                            ?: $this->extract_avetmiss_code_from_name($courseNameRow->shortname);
                    }
                    // ON-THE-FLY SEED (v5.9.337): BFS found a qual for this course but
                    // it wasn't in the map. Seed it now so the next completion event
                    // (and the next cert generation for this qual) uses the fast path.
                    // Scoped to the one qual — typically < 100ms.
                    if ($dbman->table_exists('local_rtocompliance_course_map')) {
                        local_rtocompliance_seed_course_map($catDetectedProgramcode);
                    }
                }
            }
        }

        // No QB link AND category tree found no qualification → genuinely unlinked course.
        if (empty($qualunits) && $catDetectedProgramcode === '') {
            debugging(
                'rtocompliance process_enrolment_task: skipping courseid=' . $courseid
                . ' userid=' . $userid . ' — not linked to any Qual Builder unit and no '
                . 'AVETMISS qualification code found in Moodle category ancestors.',
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

            // CATEGORY-TREE-DETECTION (v5.9.329): programcode from ancestor category name,
            // unitcode from course name — both resolved by the tree walk above.
            $programcode  = $catDetectedProgramcode;
            $programname  = '';
            $unitcode     = $catDetectedUnitcode;
            $unitname     = '';

            $deliverymode = ($coursesettings && !empty($coursesettings->deliverymode))
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
     * CATEGORY-TREE-DETECTION (v5.9.329)
     *
     * Walk the full Moodle category ancestor chain for a course and return the first
     * AVETMISS qualification code found at the START of any ancestor category's NAME.
     *
     * Handles structures like:
     *   "ABC12345 — a Diploma qualification"  ← qual code at start of name
     *       └── "2023 S1"                         ← semester sub-category (skipped)
     *             └── "ABC12345 CL1 2023 S1"      ← the course
     *
     * Self-contained (no lib.php dependency) so it is safe in scheduled/adhoc task context.
     */
    private function detect_qualcode_from_category_ancestors(int $courseid): string {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'category');
        if (!$course) {
            return '';
        }
        $catid   = (int)$course->category;
        $visited = [];
        // AVETMISS qual code at start of name, followed by space/dash/em-dash/colon or EOL.
        $rx = '/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})(?:\s|$|-|—|:)/u';
        while ($catid > 0 && !isset($visited[$catid])) {
            $visited[$catid] = true;
            $cat = $DB->get_record('course_categories', ['id' => $catid], 'id, name, parent');
            if (!$cat) {
                break;
            }
            if (preg_match($rx, strtoupper(trim((string)$cat->name)), $m)) {
                return $m[1];
            }
            $catid = (int)$cat->parent;
        }
        return '';
    }

    /**
     * CATEGORY-TREE-DETECTION (v5.9.329)
     *
     * Extract an AVETMISS unit or qualification code from the START of a string
     * (course fullname or shortname). Returns '' if not found.
     * Self-contained duplicate of local_rtocompliance_extract_unit_code_from_name()
     * so it is safe in scheduled/adhoc task context without requiring lib.php.
     */
    private function extract_avetmiss_code_from_name(string $name): string {
        if (preg_match('/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/i', trim($name), $m)) {
            return strtoupper($m[1]);
        }
        return '';
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
            // v5.9.374: the v5.9.297 is_archive=0 guard is REMOVED here. Students
            // legitimately complete units through archived / semester-copy intake
            // courses, and those completions must count toward the qualification.
            // So completing ANY variant course (archived or not) now triggers the
            // qualification-completion / autocert check for its qualification. The
            // check itself still reads the results register, so it only issues when
            // the whole qualification is genuinely complete.
            $variantQbids = $DB->get_fieldset_sql(
                "SELECT DISTINCT qu.qualbuilderid
                   FROM {local_rtocompliance_qualunit_courses} quc
                   JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                  WHERE quc.courseid = :courseid",
                ['courseid' => $courseid]
            );
            if ($variantQbids) {
                $qualbuilderids = array_values(array_unique(
                    array_merge($qualbuilderids, $variantQbids)
                ));
            }
        }

        // COURSE-MAP-TABLE (v5.9.335): replaced the category-ancestor regex walk with a
        // direct lookup in local_rtocompliance_course_map (the admin-managed source of
        // truth). Completing "ABC12345 CL1 2023 S1" now fires the ABC12345 autocert
        // check via a single indexed query — no ancestor chain walking required.
        //
        // Fallback: if the map table doesn't exist (pre-upgrade) or has no row for
        // this course (not yet seeded), detect_qualcode_from_category_ancestors() is
        // used so nothing breaks during the upgrade transition.
        $mapQualcode = '';
        if ($dbman->table_exists('local_rtocompliance_course_map')) {
            $mapRec = $DB->get_record(
                'local_rtocompliance_course_map',
                ['courseid' => $courseid],
                'qualcode',
                IGNORE_MISSING
            );
            if ($mapRec && !empty($mapRec->qualcode)) {
                $mapQualcode = (string)$mapRec->qualcode;
            }
        }
        // Fallback to ancestor walk if map had no entry for this course.
        $catQualcode = $mapQualcode !== '' ? $mapQualcode
            : $this->detect_qualcode_from_category_ancestors($courseid);

        if ($catQualcode !== '') {
            $catQbids = $DB->get_fieldset_sql(
                "SELECT id FROM {local_rtocompliance_qualbuilder}
                  WHERE qualificationcode = :qcode
                    AND status != 'superseded'",
                ['qcode' => $catQualcode]
            );
            if ($catQbids) {
                $qualbuilderids = array_values(array_unique(
                    array_merge($qualbuilderids, array_map('intval', $catQbids))
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
                // TASK-47: Wrap insert in try/catch to silently absorb duplicate-key
                // violations that arise when two concurrent completion events both pass
                // the application-level guard above before either has committed its row.
                // The UNIQUE index on (studentid, qualbuilderid) ensures at most one
                // pending entry exists; the losing concurrent insert simply no-ops.
                try {
                    $DB->insert_record('local_rtocompliance_autocerts', $autocert);
                } catch (\dml_exception $e) {
                    if (strpos($e->getMessage(), 'duplicate') === false &&
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                    // Duplicate key — another concurrent insert won the race.
                    // The queue entry already exists; nothing more to do.
                }
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
