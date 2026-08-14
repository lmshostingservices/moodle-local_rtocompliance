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
 * RTO Compliance plugin — update_trainer_status_task.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class update_trainer_status_task extends \core\task\scheduled_task {
    const BATCH_SIZE = 100;
    const EXPIRY_WARNING_DAYS = 30;
    
    public function get_name() {
        return get_string('task_update_trainer_status', 'local_rtocompliance');
    }
    
    public function execute() {
        global $DB;
        
        $now = time();
        $warningthreshold = $now + (self::EXPIRY_WARNING_DAYS * 24 * 60 * 60);
        
        // Bug 13/45: Also check taeexpirydate. A trainer whose TAE credential has
        // expired must be flagged non-compliant per ASQA Standards.
        $DB->execute(
            "UPDATE {local_rtocompliance_trainers}
             SET status = 'expired', compliancestatus = 'noncompliant', timemodified = ?
             WHERE (wwccexpiry < ? AND wwccexpiry > 0)
                OR (policecheckexpiry < ? AND policecheckexpiry > 0)
                OR (taeexpirydate < ? AND taeexpirydate > 0)",
            [$now, $now, $now, $now]
        );

        $DB->execute(
            "UPDATE {local_rtocompliance_trainers}
             SET status = 'expiring', timemodified = ?
             WHERE status = 'current'
               AND ((wwccexpiry < ? AND wwccexpiry > ?)
                    OR (policecheckexpiry < ? AND policecheckexpiry > ?)
                    OR (taeexpirydate < ? AND taeexpirydate > ?))",
            [$now, $warningthreshold, $now, $warningthreshold, $now, $warningthreshold, $now]
        );
        
        \local_rtocompliance\cache_helper::invalidate_dashboard_metrics();
        
        mtrace("Trainer status update completed.");
    }
}
