<?php
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
