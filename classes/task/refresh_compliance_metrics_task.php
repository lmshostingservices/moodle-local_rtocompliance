<?php
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
