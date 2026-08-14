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
 * RTO Compliance plugin — observer.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

// COURSE-MAP-FIX (v5.9.338): lib.php must be explicitly loaded because observer
// callbacks fire before Moodle's callback scanner auto-loads plugin lib files.
// detect_qualcode_from_category_ancestors() and seed_course_map() live in lib.php.
require_once(__DIR__ . '/../lib.php');

class local_rtocompliance_observer {
    public static function course_deleted(\core\event\course_deleted $event) {
        global $DB;
        $courseid = $event->objectid;

        // Archive enrolments rather than deleting them. ASQA Standards Clause 3.5
        // requires student training records to be retained for a minimum of 30 years.
        // Permanently deleting AVETMISS enrolment records when a course is deleted
        // would destroy the compliance audit trail.
        $now = time();
        $DB->execute(
            "UPDATE {local_rtocompliance_enrolments}
             SET status = 'withdrawn', outcomeidentifier = '40', activityenddate = ?,
                 timemodified = ?
             WHERE courseid = ? AND status = 'active'",
            [$now, $now, $courseid]
        );

        // Write audit log so administrators can see which course was deleted.
        try {
            \local_rtocompliance\audit_logger::log(
                \local_rtocompliance\audit_logger::ACTION_DELETE,
                'course',
                (int)$courseid,
                null,
                'Moodle course deleted — active enrolments archived as withdrawn',
                ['courseid' => $courseid]
            );
        } catch (\Throwable $e) {
            // Audit log failure must not prevent the rest of course deletion completing.
            debugging('rtocompliance audit log failed on course_deleted: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $DB->delete_records('local_rtocompliance_courses', ['courseid' => $courseid]);
        \local_rtocompliance\cache_helper::invalidate_course_settings($courseid);
        \local_rtocompliance\cache_helper::mark_metrics_dirty();
    }

    public static function user_enrolment_created(\core\event\user_enrolment_created $event) {
        global $DB;

        $coursesettings = \local_rtocompliance\cache_helper::get_course_settings($event->courseid);
        $nationallyrecognised = $coursesettings && $coursesettings->nationallyrecognised;

        // Also trigger for courses linked to a Qual Builder unit -- even if not flagged nationally recognised.
        // record_exists is indexed on courseid so this is fast.
        if (!$nationallyrecognised) {
            // BUG-18 FIX: $DB->get_manager()->table_exists() was called on EVERY
            // user_enrolment_created event across the entire Moodle site (not just courses
            // managed by this plugin). On sites with high enrolment activity this causes
            // repeated schema-metadata queries for every single enrolment event.
            // Cache the table-exists result in a static variable so the schema check
            // happens at most once per PHP request, regardless of how many enrolment
            // events fire during that request.
            static $qualunits_table_exists = null;
            if ($qualunits_table_exists === null) {
                $qualunits_table_exists = $DB->get_manager()->table_exists('local_rtocompliance_qualunits');
            }
            if (!$qualunits_table_exists) {
                return;
            }
            if (!$DB->record_exists('local_rtocompliance_qualunits', ['courseid' => $event->courseid])) {
                // Not the primary course — check the archive courses junction table.
                // This allows enrolments in archive semester courses to also trigger
                // AVETMISS record creation (ARCHIVE-COURSE-LINKING v5.2.37).
                // COURSE-MAP-FIX (v5.9.338): previously when qualunit_courses table was
                // absent the observer returned immediately, bypassing the course_map
                // lookup entirely. Fixed: treat "table absent" and "course not in table"
                // identically — both fall through to the course_map / BFS check.
                static $qualunit_courses_table_exists = null;
                if ($qualunit_courses_table_exists === null) {
                    $qualunit_courses_table_exists = $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses');
                }
                $notInVariants = !$qualunit_courses_table_exists ||
                    !$DB->record_exists('local_rtocompliance_qualunit_courses', ['courseid' => $event->courseid]);
                if ($notInVariants) {
                    // Not in QB primary, not in variant links — check course_map table
                    // (single indexed lookup), then BFS ancestor walk as last resort.
                    static $course_map_table_exists_c = null;
                    if ($course_map_table_exists_c === null) {
                        $course_map_table_exists_c = $DB->get_manager()->table_exists('local_rtocompliance_course_map');
                    }
                    $knownToMap = $course_map_table_exists_c &&
                        $DB->record_exists('local_rtocompliance_course_map', ['courseid' => $event->courseid]);
                    if (!$knownToMap) {
                        // Map miss — fall back to BFS ancestor chain.
                        if (local_rtocompliance_detect_qualcode_from_category_ancestors($event->courseid) === '') {
                            return;
                        }
                    }
                }
            }
        }

        \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
            'action'   => 'create',
            'userid'   => $event->relateduserid,
            'courseid' => $event->courseid,
            'enrolid'  => $event->objectid,
        ]);

        // Auto-send suitability checklist on enrolment if enabled in settings
        try {
            $autosend = (bool) get_config('local_rtocompliance', 'autosend_suitability');
            if ($autosend) {
                $tasid = (int) get_config('local_rtocompliance', 'autosend_suitability_tasid');
                if ($tasid > 0) {
                    local_rtocompliance_auto_send_suitability((int) $event->relateduserid, $tasid);
                }
            }
        } catch (\Throwable $e) {
            // Must not prevent the enrolment process from completing.
            debugging('rtocompliance auto suitability send failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        // Mirror the same filter used in user_enrolment_created: only queue a
        // withdrawal task for courses that are nationally recognised or linked
        // to a Qual Builder unit. Queueing for every unenrolment site-wide
        // creates unnecessary overhead on large Moodle installations.
        global $DB;

        $coursesettings = \local_rtocompliance\cache_helper::get_course_settings($event->courseid);
        $nationallyrecognised = $coursesettings && $coursesettings->nationallyrecognised;

        if (!$nationallyrecognised) {
            // Same static-cache pattern as user_enrolment_created (bug 18 fix).
            static $qualunits_del_table_exists = null;
            if ($qualunits_del_table_exists === null) {
                $qualunits_del_table_exists = $DB->get_manager()->table_exists('local_rtocompliance_qualunits');
            }
            if (!$qualunits_del_table_exists) {
                return;
            }
            if (!$DB->record_exists('local_rtocompliance_qualunits', ['courseid' => $event->courseid])) {
                // Not a QB primary course. Check variant links, course_map, then BFS.
                // COURSE-MAP-FIX (v5.9.338 revised): previously had an early return when
                // qualunit_courses table was absent — that bypassed the course_map lookup.
                // Now: absent table is treated identically to "course not in table", so
                // the combined condition flows straight to course_map / BFS.
                static $qualunit_courses_del_table_exists = null;
                if ($qualunit_courses_del_table_exists === null) {
                    $qualunit_courses_del_table_exists = $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses');
                }
                $notInVariantsDel = !$qualunit_courses_del_table_exists ||
                    !$DB->record_exists('local_rtocompliance_qualunit_courses', ['courseid' => $event->courseid]);
                if ($notInVariantsDel) {
                    static $course_map_table_exists_d = null;
                    if ($course_map_table_exists_d === null) {
                        $course_map_table_exists_d = $DB->get_manager()->table_exists('local_rtocompliance_course_map');
                    }
                    $knownToMapD = $course_map_table_exists_d &&
                        $DB->record_exists('local_rtocompliance_course_map', ['courseid' => $event->courseid]);
                    if (!$knownToMapD) {
                        if (local_rtocompliance_detect_qualcode_from_category_ancestors($event->courseid) === '') {
                            return;
                        }
                    }
                }
            }
        }

        \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
            'action' => 'delete',
            'userid' => $event->relateduserid,
            'courseid' => $event->courseid,
        ]);
    }

    public static function course_completed(\core\event\course_completed $event) {
        \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
            'action' => 'complete',
            'userid' => $event->relateduserid,
            'courseid' => $event->courseid,
        ]);
    }

    /**
     * Removed the broken '70' -> '65' logic that marked students as Not Yet Competent
     * simply because they completed a Moodle module. Module completion means progress,
     * not failure. We now only update the timemodified timestamp to keep the record fresh.
     * Note: '65' does not exist in AVETMISS 2.3. The correct fail code is '30'.
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event) {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_students') ||
            !$dbman->table_exists('local_rtocompliance_enrolments')) {
            return;
        }

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $event->relateduserid]);
        if (!$student) {
            return;
        }

        // Only update timemodified -- do NOT change outcomeidentifier.
        // Outcome is driven by course completion + grade, not individual module completion.
        $DB->execute(
            "UPDATE {local_rtocompliance_enrolments}
             SET timemodified = ?
             WHERE studentid = ? AND courseid = ? AND status = 'active'",
            [time(), $student->id, $event->courseid]
        );
    }

    /**
     * FIX: Now reads the actual grade and maps it to an AVETMISS outcome code.
     * Only processes course-level grade items (not quiz/assignment sub-items).
     * Grade >= pass threshold -> '20' Competent; below -> '30' Not Yet Competent.
     * Note: '65' does NOT exist in AVETMISS 2.3 — '30' is the correct fail code.
     * Then queues a check_completion task to trigger auto-certificate if all units done.
     */
    public static function user_graded(\core\event\user_graded $event) {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_students') ||
            !$dbman->table_exists('local_rtocompliance_enrolments')) {
            return;
        }

        // Only process course-level grade items, not activity-level (quiz, assignment etc).
        $itemid = isset($event->other['itemid']) ? (int)$event->other['itemid'] : 0;
        if (!$itemid) {
            return;
        }
        $gradeitem = $DB->get_record('grade_items', ['id' => $itemid]);
        if (!$gradeitem || $gradeitem->itemtype !== 'course') {
            return;
        }

        $userid = $event->relateduserid;
        $courseid = (int)$gradeitem->courseid;

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
        if (!$student) {
            return;
        }

        // Load the actual grade record.
        $gradegrade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
        if (!$gradegrade || $gradegrade->finalgrade === null) {
            return;
        }

        $outcome = self::grade_to_outcome((float)$gradegrade->finalgrade, (float)$gradeitem->grademax);

        // '70' means no grade scale is configured — leave existing outcome unchanged.
        if ($outcome === '70') {
            return;
        }

        // Update outcomeidentifier and assessmentdate on this student's enrolment for the course.
        // Applies to both active and completed status (grade may arrive after completion event).
        //
        // BUG-22 FIX: assessmentdate was set to time() (the moment the cron/event handler ran),
        // not the actual time the assessment was graded. On a busy Moodle site the grading event
        // can be processed hours after the grade was entered, recording a meaningless wall-clock
        // time instead of the real assessment date. Use $event->timecreated which is the exact
        // moment Moodle recorded the user_graded event — the correct assessment timestamp.
        // BUG-SR-OUTCOME-AUTOREVERT (v4.2.27): the WHERE clause now excludes
        // rows where manualoutcome = 1.  These are enrolments where a manager
        // has explicitly set the outcome via student_enrolments.php and that
        // decision is the legal AVETMISS record-of-truth; the gradebook event
        // must NOT silently overwrite it.  Pre-v4.2.27 rows default to 0 so
        // existing auto-grading behaviour is preserved for un-touched data.
        $DB->execute(
            "UPDATE {local_rtocompliance_enrolments}
             SET outcomeidentifier = ?, assessmentdate = ?, timemodified = ?
             WHERE studentid = ? AND courseid = ? AND status IN ('active', 'completed')
               AND COALESCE(manualoutcome, 0) = 0",
            [$outcome, $event->timecreated, time(), $student->id, $courseid]
        );

        // If the student achieved a positive outcome, check whether the full qualification
        // is complete so we can queue an auto-certificate.
        // AVETMISS 2.3 positive codes: 20=Competent, 51=RPL granted, 60=Credit transfer, 81=Non-assessable satisfactory.
        // 30=Fail and 40=Withdrawn must NOT trigger qualification completion.
        if (in_array($outcome, ['20', '51', '60', '81'])) {
            \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
                'action' => 'check_completion',
                'userid' => $userid,
                'courseid' => $courseid,
            ]);
        }
    }

    /**
     * LOGIN-PROFILE-PROMPT (v5.9.314)
     *
     * Fires on every successful login. If the user is enrolled in nationally
     * recognised training and their AVETMISS profile is missing or incomplete,
     * we set a session flag. The flag is consumed by
     * local_rtocompliance_extend_navigation() in lib.php, which runs after
     * require_login() on the first real page the user lands on — at that point
     * redirect() is safe to call because no output has been written yet.
     *
     * We do NOT redirect directly from here: event observers fire inside the
     * login request before the session cookie has been finalised and the
     * browser redirected to the wantsurl, so a redirect() here would race
     * against Moodle's own post-login redirect and could drop the user on a
     * blank page on some server configurations.
     *
     * Skipped for: guest users, site admins, users with no nationally recognised
     * enrolments, and users whose profile is already complete.
     *
     * @deprecated since v6.3.0 — UNREGISTERED in db/events.php. The one-shot login
     * flag has been replaced by local_rtocompliance_profile_gate_check(), which
     * re-evaluates on every page build instead of only the first page after login.
     * The method is retained so that an event cache still referencing it during the
     * upgrade resolves cleanly rather than fataling; it can be deleted in a later
     * release once all sites have upgraded past 6.3.0.
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB, $SESSION;
        require_once(__DIR__ . '/../lib.php');

        $userid = (int)$event->objectid;

        // Never prompt guests or site admins.
        if (isguestuser($userid) || is_siteadmin($userid)) {
            return;
        }

        // Only relevant for students in nationally recognised training.
        if (!local_rtocompliance_user_requires_avetmiss($userid)) {
            return;
        }

        // PROFILE-GATE (v6.3.0): completeness is judged from the actual field values,
        // not from the stored profilecomplete flag. That flag can be stale — it is
        // written by NAT imports, bulk syncs and older plugin versions that used a
        // shorter mandatory-field list — so a student with, for example, no date of
        // birth could carry profilecomplete = 1 and never be asked for it again.
        $missing = local_rtocompliance_get_missing_avetmiss_fields($userid);
        if (empty($missing)) {
            return;
        }

        // Kept for backwards compatibility with sessions started before v6.3.0.
        // The redirect is no longer driven by this flag — it is now the persistent
        // gate in local_rtocompliance_profile_gate_check(), which re-checks on every
        // page instead of only once on the first page after login.
        $SESSION->local_rtocompliance_needs_profile = 1;
    }

    /**
     * Map a raw Moodle grade to an AVETMISS outcome identifier.
     *
     * In Australian VET/RTO, Moodle course completion is the primary competency gate
     * (the RTO configures completion conditions to require 100% on all activities).
     * The grade event is a secondary signal used to mark Not Yet Competent when
     * a student fails a graded activity.
     *
     * Uses the plugin setting 'passgrade' (default 50%) as the pass threshold.
     * Result (AVETMISS 2.3 codes):
     *   grade >= threshold  ->  '20'  Competency achieved/pass
     *   grade < threshold   ->  '30'  Competency not achieved/fail (NOT '65' -- '65' is not in AVETMISS 2.3)
     *   no grademax defined ->  '70'  Leave as Continuing (no grade scale set)
     */
    public static function grade_to_outcome(float $finalgrade, float $grademax): string {
        if ($grademax <= 0) {
            return '70';
        }
        $threshold = (float)(get_config('local_rtocompliance', 'passgrade') ?: 50.0);
        $pct = ($finalgrade / $grademax) * 100.0;
        // AVETMISS 2.3: '20' = Competency achieved/pass; '30' = Competency not achieved/fail.
        // Note: '65' does NOT exist in the AVETMISS 2.3 standard -- '30' is the correct fail code.
        return $pct >= $threshold ? '20' : '30';
    }
}
