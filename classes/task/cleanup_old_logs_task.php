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

class cleanup_old_logs_task extends \core\task\scheduled_task {
    
    const BATCH_SIZE = 1000;
    const DEFAULT_RETENTION_DAYS = 730;

    public function get_name() {
        return get_string('task_cleanup_logs', 'local_rtocompliance');
    }

    public function execute() {
        global $DB;

        $retentiondays = (int)(get_config('local_rtocompliance', 'log_retentiondays') ?: self::DEFAULT_RETENTION_DAYS);

        if ($retentiondays <= 0) {
            mtrace('RTO Compliance audit log cleanup: retention set to 0 -- pruning disabled.');
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        // Bug A-regression fix: the plugin has TWO log tables that both require pruning.
        //   local_rtocompliance_log    — user-facing compliance action log (lib.php log_action())
        //   local_rtocompliance_audit  — internal security audit trail (audit_logger::log())
        // The previous "Bug A fix" incorrectly switched to local_rtocompliance_audit only,
        // leaving local_rtocompliance_log to grow without bound. Both tables are now pruned
        // using the same retention window.
        $tables = ['local_rtocompliance_log', 'local_rtocompliance_audit'];

        $totaldeleted = 0;
        foreach ($tables as $table) {
            $tabledeleted = 0;
            do {
                $ids = $DB->get_fieldset_select(
                    $table,
                    'id',
                    'timecreated < :cutoff',
                    ['cutoff' => $cutoff],
                    'id ASC',
                    0,
                    self::BATCH_SIZE
                );

                if (!empty($ids)) {
                    list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
                    $DB->delete_records_select($table, "id $insql", $params);
                    $tabledeleted += count($ids);
                }

            } while (count($ids) >= self::BATCH_SIZE);

            mtrace("  {$table}: deleted {$tabledeleted} record(s).");
            $totaldeleted += $tabledeleted;
        }

        mtrace("RTO Compliance audit log cleanup: deleted {$totaldeleted} total record(s) older than {$retentiondays} days.");
    }
}
