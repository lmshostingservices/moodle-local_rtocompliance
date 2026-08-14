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
 * RTO Compliance plugin — reconcile_completions_task.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

/**
 * RECONCILE-COMPLETIONS-TASK (v5.9.374)
 *
 * Scheduled wrapper around \local_rtocompliance\completion_reconciler::reconcile().
 * Reads Moodle course completions and writes the resulting COMPETENT outcomes
 * into the plugin results register so units completed across many semester-copy
 * / archived / cross-category courses all surface on Student Results.
 *
 * Writes ONLY to local_rtocompliance_enrolments. Creates/deletes NO Moodle
 * accounts, enrolments or completions. Idempotent.
 *
 * DISABLED BY DEFAULT: because it populates the register from live completions,
 * it should be enabled deliberately. Run it on demand first via the "Sync
 * results from Moodle completions" button on Student Results, review the
 * counts, then enable the nightly schedule under Site administration → Server →
 * Scheduled tasks if you want it kept current automatically.
 */
class reconcile_completions_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_reconcile_completions', 'local_rtocompliance');
    }

    public function execute() {
        $summary = \local_rtocompliance\completion_reconciler::reconcile();
        mtrace(sprintf(
            'reconcile_completions: scanned=%d created=%d updated=%d already=%d manual_preserved=%d '
            . 'no_student=%d no_map=%d',
            $summary['scanned'], $summary['created'], $summary['updated'],
            $summary['already_competent'], $summary['manual_preserved'],
            $summary['skipped_nostudent'], $summary['skipped_nomap']
        ));
        if (!empty($summary['unresolved_sample'])) {
            mtrace('reconcile_completions: sample unresolved course IDs: '
                . implode(', ', $summary['unresolved_sample']));
        }
    }
}
