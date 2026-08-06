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

class refresh_compliance_metrics_task extends \core\task\scheduled_task {
    
    public function get_name() {
        return get_string('task_refresh_metrics', 'local_rtocompliance');
    }
    
    public function execute() {
        global $DB;
        
        $dbman = $DB->get_manager();
        
        if ($dbman->table_exists('local_rtocompliance_metrics')) {
            // Bug K fix: the previous logic only recomputed when dirty rows existed.
            // On a fresh install the table exists but has zero rows -- record_exists()
            // returns false, so the task logged "No dirty metrics" and never computed
            // the initial metrics, leaving the dashboard showing a permanent zero-state.
            // Fix: also compute when the table is empty (count = 0).
            $rowcount  = $DB->count_records('local_rtocompliance_metrics');
            $hasDirty  = $DB->record_exists('local_rtocompliance_metrics', ['dirty' => 1]);

            if ($rowcount === 0 || $hasDirty) {
                mtrace($rowcount === 0 ? "No metrics yet, computing initial values..." : "Dirty metrics detected, recomputing...");
                \local_rtocompliance\cache_helper::get_dashboard_metrics();
                mtrace("Dashboard metrics refreshed.");
            } else {
                mtrace("No dirty metrics, skipping refresh.");
            }
        } else {
            \local_rtocompliance\cache_helper::get_dashboard_metrics();
            mtrace("Dashboard metrics computed (metrics table not yet created).");
        }
        
        \local_rtocompliance\cache_helper::get_compliance_summary();
        mtrace("Compliance summary refreshed.");
    }
}
