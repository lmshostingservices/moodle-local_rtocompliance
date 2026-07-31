<?php
namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to verify pending USIs in batches
 * 
 * Runs periodically to verify student USIs against the Australian Government
 * USI Registry using the MAS-ST authentication service.
 */
class verify_usi_batch_task extends \core\task\scheduled_task {
    
    const BATCH_SIZE = 25; // kept in sync with usi_verification_service::BATCH_SIZE
    
    public function get_name() {
        return get_string('task_verify_usi_batch', 'local_rtocompliance');
    }
    
    public function execute() {
        global $DB;
        
        $enabled = get_config('local_rtocompliance', 'usi_verification_enabled');
        if (!$enabled) {
            mtrace('USI verification is disabled in settings');
            return;
        }
        
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_students')) {
            mtrace('Students table does not exist');
            return;
        }
        
        require_once(__DIR__ . '/../usi/usi_verification_service.php');
        
        $service = new \local_rtocompliance\usi\usi_verification_service();
        
        $status = $service->is_service_available();
        if (!$status['available']) {
            mtrace('USI verification service not available: ' . $status['message']);
            return;
        }
        
        mtrace('Starting USI batch verification...');
        mtrace('Service mode: ' . ($status['test_mode'] ? 'TEST (EVTE)' : 'PRODUCTION'));
        
        if (isset($status['days_until_expiry'])) {
            mtrace("Machine credential expires in {$status['days_until_expiry']} days");
            
            if ($status['days_until_expiry'] <= 30) {
                mtrace('WARNING: Machine credential expires soon - please renew');
            }
        }
        
        $result = $service->verify_pending_batch(self::BATCH_SIZE);
        
        mtrace("Processed: {$result['processed']}");
        mtrace("Verified: {$result['verified']}");
        mtrace("Failed: {$result['failed']}");
        mtrace($result['message']);
        
        $stats = $service->get_verification_stats();
        mtrace("\nVerification Statistics:");
        mtrace("- Total with USI: {$stats['total_with_usi']}");
        mtrace("- Verified: {$stats['verified']}");
        mtrace("- Unverified: {$stats['unverified']}");
        mtrace("- Failed: {$stats['failed']}");
        mtrace("- Pending Review: {$stats['pending_review']}");
        mtrace("- Missing USI: {$stats['missing_usi']}");
        
        \local_rtocompliance\cache_helper::invalidate_dashboard_metrics();
        
        mtrace('USI batch verification completed');
    }
}
