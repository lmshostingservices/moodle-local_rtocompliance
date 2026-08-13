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

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * SYNC-COURSE-MAP-TASK (v5.9.337)
 *
 * Scheduled task that keeps local_rtocompliance_course_map fresh without
 * requiring admin intervention after each Moodle course/category change.
 *
 * Two-step execution:
 *   1. PRUNE: Remove unconfirmed 'auto' entries whose Moodle course no longer
 *      exists (course was deleted). Confirmed and 'manual' entries are never
 *      touched automatically.
 *   2. RESEED: Call local_rtocompliance_seed_course_map() to add any new QB
 *      primary courses, new variant courses, and any new category-tree courses
 *      that have appeared since the last run. Existing entries are left intact
 *      (INSERT-or-skip semantics), so confirmed rows are never overwritten.
 *
 * Default schedule: nightly at 01:30, so the map is always fresh before the
 * morning cert-generation window. Admins can adjust via Site Administration →
 * Server → Scheduled tasks.
 *
 * @package   local_rtocompliance
 * @copyright 2026 RTO Compliance
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_course_map_task extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_sync_course_map', 'local_rtocompliance',
            null, true) ?: 'Sync RTO Compliance Moodle Course Map';
    }

    public function execute(): void {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_course_map')) {
            mtrace('local_rtocompliance sync_course_map: table does not exist yet — skipping.');
            return;
        }

        // ── Step 1: Prune unconfirmed auto-detected entries for deleted courses ──
        // We only prune source='auto', confirmed=0 rows. 'qb' rows are re-synced
        // from Qual Builder below, and 'manual' rows are admin-owned.
        $staleRecs = $DB->get_records_sql(
            "SELECT cm.id, cm.courseid
               FROM {local_rtocompliance_course_map} cm
          LEFT JOIN {course} c ON c.id = cm.courseid
              WHERE c.id IS NULL
                AND cm.confirmed = 0
                AND cm.source    = 'auto'"
        );
        if ($staleRecs) {
            $staleIds = array_column((array) $staleRecs, 'id', 'id');
            list($insql, $params) = $DB->get_in_or_equal(array_keys($staleIds), SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {local_rtocompliance_course_map} WHERE id $insql", $params);
            mtrace('local_rtocompliance course map: pruned ' . count($staleIds)
                . ' stale auto-entry/entries (Moodle course deleted).');
        }

        // Also prune stale 'qb' entries: QB no longer links these courses.
        // A QB entry is stale when neither qualunits.courseid NOR qualunit_courses.courseid
        // matches anymore. We only remove confirmed=0 QB rows here; confirmed=1 rows
        // are admin-approved and stay until the admin removes them manually.
        // NOT EXISTS is used instead of NOT IN to avoid SQL three-valued logic issues
        // when subquery rows contain NULLs — NOT IN with any NULL in the subquery
        // returns UNKNOWN for every outer row, silently skipping all pruning.
        $staleQb = $DB->get_records_sql(
            "SELECT cm.id
               FROM {local_rtocompliance_course_map} cm
              WHERE cm.source    = 'qb'
                AND cm.confirmed = 0
                AND NOT EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_qualunits} qu
                     WHERE qu.courseid = cm.courseid
                       AND qu.courseid IS NOT NULL
                )
                AND NOT EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_qualunit_courses} quc
                     WHERE quc.courseid = cm.courseid
                       AND quc.courseid IS NOT NULL
                )"
        );
        if ($staleQb) {
            $staleQbIds = array_column((array) $staleQb, 'id', 'id');
            list($insql2, $params2) = $DB->get_in_or_equal(array_keys($staleQbIds), SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {local_rtocompliance_course_map} WHERE id $insql2", $params2);
            mtrace('local_rtocompliance course map: pruned ' . count($staleQbIds)
                . ' stale qb-entry/entries (no longer in Qual Builder).');
        }

        // ── Step 2: Full reseed — new QB links + new category-tree courses ────
        $result = local_rtocompliance_seed_course_map();
        mtrace('local_rtocompliance course map sync complete:'
            . ' inserted='      . $result['inserted']
            . ', skipped='      . $result['skipped']
            . ', quals_scanned=' . count($result['quals_scanned'])
            . ' (' . implode(', ', $result['quals_scanned']) . ')');
    }
}
