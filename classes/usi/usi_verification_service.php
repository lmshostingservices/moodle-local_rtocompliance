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

namespace local_rtocompliance\usi;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/usi_platform_client.php');

/**
 * USI Verification Service
 * 
 * Provides high-level USI verification functionality with caching,
 * batch processing, and integration with the RTO Compliance student records.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class usi_verification_service {
    
    const CACHE_TTL_VERIFIED = 86400 * 365;
    const CACHE_TTL_FAILED = 86400;
    const BATCH_SIZE = 25; // matches verify_usi_batch_task::BATCH_SIZE — keep in sync
    const RATE_LIMIT_PER_MINUTE = 60;
    const RATE_LIMIT_CACHE_KEY = 'usi_rate_limit_count';
    const RATE_LIMIT_WINDOW_SECONDS = 60;
    
    const STATUS_UNVERIFIED = 0;
    const STATUS_VERIFIED = 1;
    const STATUS_FAILED = 2;
    const STATUS_PENDING = 3;
    const STATUS_MANUAL_REVIEW = 4;
    
    private $client;
    private $config;
    
    public function __construct() {
        $this->load_config();
        $this->client = new usi_platform_client($this->config);
    }
    
    /**
     * Load configuration from Moodle settings
     */
    private function load_config() {
        $this->config = [
            'test_mode'  => get_config('local_rtocompliance', 'usi_test_mode') ?? true,
            'debug_mode' => get_config('local_rtocompliance', 'usi_debug_mode') ?? false,
            // Platform credentials — USI calls are proxied through lms-labs.com.
            // The platform holds the shared machine credential (P12) for all plugin customers.
            'siteid'  => get_config('local_rtocompliance', 'siteid')  ?? '',
            'apikey'  => get_config('local_rtocompliance', 'apikey')  ?? '',
            'apiurl'  => get_config('local_rtocompliance', 'apiurl')  ?? 'https://lms-labs.com',
        ];
    }
    
    /**
     * Verify a student's USI
     * 
     * @param int $studentid The local_rtocompliance_students record ID
     * @return array Verification result
     */
    public function verify_student_usi($studentid) {
        global $DB;
        
        $student = $DB->get_record('local_rtocompliance_students', ['id' => $studentid]);
        if (!$student) {
            return [
                'success' => false,
                'message' => 'Student record not found',
            ];
        }
        
        if (empty($student->usi)) {
            return [
                'success' => false,
                'message' => 'Student does not have a USI entered',
            ];
        }
        
        // BUG-USI-NAMESPACE FIX: core_user is a Moodle global class. Without the leading \
        // PHP resolves it as local_rtocompliance\usi\core_user (does not exist).
        $user = \core_user::get_user($student->userid);
        if (!$user) {
            return [
                'success' => false,
                'message' => 'User account not found',
            ];
        }
        
        $firstname = $student->firstname ?? $user->firstname;
        $lastname = $student->lastname ?? $user->lastname;
        $dateofbirth = $student->dateofbirth ? date('Y-m-d', $student->dateofbirth) : null;
        
        if (empty($dateofbirth)) {
            return [
                'success' => false,
                'message' => 'Date of birth is required for USI verification',
            ];
        }
        
        $result = $this->client->verify_usi(
            $student->usi,
            $firstname,
            $lastname,
            $dateofbirth
        );
        
        $this->update_student_verification_status($studentid, $result);
        
        $this->log_verification_attempt($studentid, $student->usi, $result);
        
        return [
            'success' => $result['verified'],
            'message' => $result['message'],
            'status' => $result['status'],
            'details' => $result['details'],
        ];
    }
    
    /**
     * Check and enforce rate limiting
     * 
     * @return bool True if request is allowed, false if rate limited
     */
    private function check_rate_limit() {
        // BUG-17 FIX: Wrap the check+increment in a Moodle named lock to prevent the
        // TOCTOU race condition. Without a lock, two concurrent requests both read
        // count=N, both pass the N < max check, and both increment to N+1 — allowing
        // up to 2× the permitted call rate during bursts.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_rtocompliance_usi_rate');
        $lock = $lockfactory->get_lock('check_rate_limit', 5);
        if (!$lock) {
            // Fail closed: if we can't acquire the lock, deny the request rather than
            // risk exceeding the USI API rate limit and getting the RTO's key suspended.
            return false;
        }

        try {
            $cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
            $data = $cache->get(self::RATE_LIMIT_CACHE_KEY);

            $now = time();

            if (!$data || !is_array($data)) {
                $data = ['count' => 0, 'window_start' => $now];
            }

            // Reset counter if window expired.
            if ($now - $data['window_start'] >= self::RATE_LIMIT_WINDOW_SECONDS) {
                $data = ['count' => 0, 'window_start' => $now];
            }

            if ($data['count'] >= self::RATE_LIMIT_PER_MINUTE) {
                return false;
            }

            $data['count']++;
            $cache->set(self::RATE_LIMIT_CACHE_KEY, $data);

            return true;
        } finally {
            $lock->release();
        }
    }
    
    /**
     * Wait for rate limit window to reset
     */
    private function wait_for_rate_limit() {
        $cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
        $data = $cache->get(self::RATE_LIMIT_CACHE_KEY);
        
        if (!$data || !is_array($data)) {
            return;
        }
        
        $now = time();
        $elapsed = $now - $data['window_start'];
        
        if ($elapsed < self::RATE_LIMIT_WINDOW_SECONDS) {
            $waittime = self::RATE_LIMIT_WINDOW_SECONDS - $elapsed + 1;
            sleep($waittime);
            
            // Reset the counter after waiting
            $cache->set(self::RATE_LIMIT_CACHE_KEY, ['count' => 0, 'window_start' => time()]);
        }
    }
    
    /**
     * Batch verify unverified USIs
     * 
     * @param int $limit Maximum number to process
     * @return array Summary of verification results
     */
    public function verify_pending_batch($limit = 50) {
        global $DB;
        
        $limit = min($limit, self::BATCH_SIZE);
        
        // USI-STUCK-PENDING-FIX (v5.9.302): previously this query only fetched
        // usiverified = 0 (never attempted).  Students whose first verification
        // attempt returned CERT_PENDING (HTTP 503 — platform credential not yet
        // active) were silently written to usiverified = 3 (STATUS_PENDING) and
        // then never re-queried.  With 1,066 students all stuck at status 3, the
        // batch appeared to process 0 students on every run.
        // Fix: also include usiverified = 3 so pending-retry students are re-tried
        // automatically on the next scheduled run without any admin intervention.
        $students = $DB->get_records_select(
            'local_rtocompliance_students',
            "usi IS NOT NULL AND usi != '' AND usiverified IN (0, 3) AND dateofbirth IS NOT NULL AND dateofbirth != 0",
            null,
            'timemodified ASC',
            '*',
            0,
            $limit
        );
        
        if (empty($students)) {
            return [
                'processed' => 0,
                'verified' => 0,
                'failed' => 0,
                'message' => 'No pending USI verifications',
            ];
        }
        
        $batch = [];
        foreach ($students as $student) {
            // BUG-USI-NAMESPACE FIX: same leading \ required as in verify_student_usi().
            $user = \core_user::get_user($student->userid);
            if (!$user) {
                continue;
            }
            
            $batch[] = [
                'student_id' => $student->id,
                'usi' => $student->usi,
                'firstname' => $student->firstname ?? $user->firstname,
                'lastname' => $student->lastname ?? $user->lastname,
                'dateofbirth' => date('Y-m-d', $student->dateofbirth),
            ];
        }
        
        $verified = 0;
        $failed = 0;
        $rateLimited = 0;
        
        // Process each student with rate limiting enforcement
        foreach ($batch as $item) {
            // Check rate limit before each verification request.
            // Stop the batch early rather than sleeping — sleep() blocks the cron
            // runner for up to 61 s and can cause the task to be marked as timed out.
            // The remainder will be picked up on the next scheduled run.
            if (!$this->check_rate_limit()) {
                break;
            }
            
            try {
                $result = $this->client->verify_usi(
                    $item['usi'],
                    $item['firstname'],
                    $item['lastname'],
                    $item['dateofbirth']
                );
                
                $this->update_student_verification_status($item['student_id'], $result);
                $this->log_verification_attempt($item['student_id'], $item['usi'], $result);
                
                if ($result['verified']) {
                    $verified++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                $this->log_verification_attempt($item['student_id'], $item['usi'], [
                    'verified' => false,
                    'status' => 'ERROR',
                    'message' => $e->getMessage(),
                    'details' => [],
                ]);
            }
            
            // Small delay between requests to be respectful to the API
            usleep(100000); // 100ms
        }
        
        return [
            'processed' => count($batch),
            'verified' => $verified,
            'failed' => $failed,
            'message' => "Processed {$verified} verified, {$failed} failed of " . count($batch) . " students",
        ];
    }
    
    /**
     * Update student record with verification status
     */
    private function update_student_verification_status($studentid, $result) {
        global $DB;
        
        $update = new \stdClass();
        $update->id = $studentid;
        $update->timemodified = time();
        
        if ($result['verified']) {
            $update->usiverified = self::STATUS_VERIFIED;
            $update->usiverifieddate = time();
        } else {
            switch ($result['status']) {
                case 'PARTIAL_MATCH':
                case 'NO_MATCH':
                    $update->usiverified = self::STATUS_MANUAL_REVIEW;
                    break;
                case 'NOT_FOUND':
                case 'INACTIVE':
                    $update->usiverified = self::STATUS_FAILED;
                    break;
                default:
                    $update->usiverified = self::STATUS_PENDING;
            }
        }
        
        $DB->update_record('local_rtocompliance_students', $update);
        
        \local_rtocompliance\cache_helper::mark_metrics_dirty();
    }
    
    /**
     * Log verification attempt
     */
    private function log_verification_attempt($studentid, $usi, $result) {
        global $DB;

        // BUG-16 FIX: table_exists() was called on every single log write.
        // In a batch of 25 students this triggers 25 separate schema-metadata lookups,
        // each of which hits the DB information_schema. get_manager() itself is also
        // non-trivial to instantiate. Cache the result in a static variable so the
        // schema check happens at most once per PHP request/cron task execution.
        static $usilog_exists = null;
        if ($usilog_exists === null) {
            $usilog_exists = $DB->get_manager()->table_exists('local_rtocompliance_usilog');
        }
        if (!$usilog_exists) {
            return;
        }
        
        $log = new \stdClass();
        $log->studentid = $studentid;
        $log->usi = $usi;
        $log->status = $result['status'];
        $log->verified = $result['verified'] ? 1 : 0;
        $log->message = substr($result['message'], 0, 255);
        $log->details = json_encode($result['details'] ?? []);
        $log->timecreated = time();
        
        $DB->insert_record('local_rtocompliance_usilog', $log);
    }
    
    /**
     * Get verification statistics
     */
    public function get_verification_stats() {
        global $DB;
        
        $stats = [
            'total_with_usi' => 0,
            'verified' => 0,
            'unverified' => 0,
            'failed' => 0,
            'pending_review' => 0,
            'missing_usi' => 0,
        ];
        
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_students')) {
            return $stats;
        }
        
        $stats['total_with_usi'] = $DB->count_records_select(
            'local_rtocompliance_students',
            "usi IS NOT NULL AND usi != ''"
        );
        
        $stats['verified'] = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_VERIFIED]);
        $stats['unverified'] = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_UNVERIFIED]);
        $stats['failed'] = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_FAILED]);
        $stats['pending_review'] = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_MANUAL_REVIEW]);
        // STATUS_PENDING (3) = transient error (CERT_PENDING, NETWORK_ERROR, etc.) — will be retried
        $stats['pending_retry'] = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_PENDING]);
        $stats['missing_usi'] = $DB->count_records_select(
            'local_rtocompliance_students',
            "usi IS NULL OR usi = ''"
        );
        
        return $stats;
    }
    
    /**
     * Get students requiring manual USI review
     */
    public function get_students_for_review($page = 0, $perpage = 50) {
        global $DB;
        
        $sql = "SELECT s.*, u.firstname as user_firstname, u.lastname as user_lastname, u.email
                FROM {local_rtocompliance_students} s
                JOIN {user} u ON u.id = s.userid
                WHERE s.usiverified = :status
                ORDER BY s.timemodified DESC";
        
        $students = $DB->get_records_sql($sql, ['status' => self::STATUS_MANUAL_REVIEW], $page * $perpage, $perpage);
        
        $total = $DB->count_records('local_rtocompliance_students', ['usiverified' => self::STATUS_MANUAL_REVIEW]);
        
        return [
            'students' => array_values($students),
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
        ];
    }
    
    /**
     * Manually verify a USI (admin override)
     */
    public function manual_verify($studentid, $verifierid, $notes = '') {
        global $DB;

        // Validate that the verifier is a real, non-deleted Moodle user so the
        // audit log cannot be populated with arbitrary IDs.
        $verifier = \core_user::get_user($verifierid);
        if (!$verifier || !empty($verifier->deleted)) {
            return ['success' => false, 'message' => 'Verifier user not found or deleted'];
        }

        $update = new \stdClass();
        $update->id = $studentid;
        $update->usiverified = self::STATUS_VERIFIED;
        $update->usiverifieddate = time();
        $update->timemodified = time();
        
        $DB->update_record('local_rtocompliance_students', $update);
        
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_usilog')) {
            $log = new \stdClass();
            $log->studentid = $studentid;
            $log->usi = $DB->get_field('local_rtocompliance_students', 'usi', ['id' => $studentid]);
            $log->status = 'MANUAL_VERIFIED';
            $log->verified = 1;
            $log->message = 'Manually verified by admin. ' . $notes;
            $log->details = json_encode(['verifier_id' => $verifierid]);
            $log->timecreated = time();
            $DB->insert_record('local_rtocompliance_usilog', $log);
        }
        
        \local_rtocompliance\cache_helper::invalidate_dashboard_metrics();
        
        return ['success' => true, 'message' => 'USI manually verified'];
    }
    
    /**
     * Check if the verification service is configured and available
     */
    public function is_service_available() {
        // With the platform proxy architecture, availability requires siteid + apikey
        // (not a local P12 cert). The platform holds the shared machine credential.
        $siteid = $this->config['siteid'] ?? '';
        $apikey = $this->config['apikey'] ?? '';

        if (empty($siteid) || empty($apikey)) {
            return [
                'available' => false,
                'message'   => 'Platform API not configured. Go to RTO Compliance → API Settings and enter your Site ID and API key.',
            ];
        }

        // Quick connectivity check to the platform
        $status = $this->client->test_connection();

        if (!$status['connected']) {
            return [
                'available' => false,
                'message'   => 'Cannot reach lms-labs.com platform: ' . ($status['message'] ?? 'unknown error'),
            ];
        }

        if (isset($status['cert_ready']) && !$status['cert_ready']) {
            return [
                'available' => false,
                'message'   => 'Platform is reachable but USI machine credential is not yet configured on the platform (pending myGovID). Verifications will be queued.',
                'test_mode' => $status['test_mode'] ?? true,
            ];
        }

        return [
            'available' => true,
            'message'   => 'Platform USI service is configured and ready',
            'test_mode' => $status['test_mode'] ?? false,
        ];
    }
    
    /**
     * Reset all students stuck at STATUS_PENDING (3) back to STATUS_UNVERIFIED (0)
     * so the next scheduled batch run re-attempts their verification.
     *
     * Called by the admin "Retry pending USI verifications" button.
     * Only touches students who have a USI and a date of birth (otherwise the batch
     * would skip them anyway and they'd just bounce back to pending).
     *
     * @return int Number of student records reset
     */
    public function reset_stuck_pending() {
        global $DB;

        // Count BEFORE the update so we can report how many were reset.
        $count = (int) $DB->count_records_select(
            'local_rtocompliance_students',
            "usiverified = :pending AND usi IS NOT NULL AND usi != ''
             AND dateofbirth IS NOT NULL AND dateofbirth != 0",
            ['pending' => self::STATUS_PENDING]
        );

        if ($count > 0) {
            $DB->execute(
                "UPDATE {local_rtocompliance_students}
                    SET usiverified = :newstatus, timemodified = :now
                  WHERE usiverified = :pending
                    AND usi IS NOT NULL AND usi != ''
                    AND dateofbirth IS NOT NULL AND dateofbirth != 0",
                [
                    'newstatus' => self::STATUS_UNVERIFIED,
                    'now'       => time(),
                    'pending'   => self::STATUS_PENDING,
                ]
            );

            // Bust any cached metrics so the dashboard reflects the reset immediately.
            \local_rtocompliance\cache_helper::invalidate_dashboard_metrics();
        }

        return $count;
    }

    /**
     * Test the service connection
     */
    public function test_connection() {
        return $this->client->test_connection();
    }
}
