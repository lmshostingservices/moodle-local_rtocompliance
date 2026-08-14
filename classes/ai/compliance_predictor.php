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
 * RTO Compliance plugin — compliance_predictor.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\ai;

defined('MOODLE_INTERNAL') || die();

class compliance_predictor {
    private $alertthresholds = [
        'trainer_expiry_days' => 30,
        'deadline_warning_days' => 14,
        'usi_missing_critical_days' => 7,
        'incomplete_profile_warning_percentage' => 20,
    ];

    public function run_predictive_analysis(): array {
        $alerts = [];
        
        $alerts = array_merge($alerts, $this->check_trainer_expirations());
        $alerts = array_merge($alerts, $this->check_student_compliance());
        $alerts = array_merge($alerts, $this->check_deadline_risks());
        $alerts = array_merge($alerts, $this->check_nat_validation_issues());
        $alerts = array_merge($alerts, $this->check_certificate_issues());
        
        $this->save_alerts($alerts);
        
        return $alerts;
    }

    private function check_trainer_expirations(): array {
        global $DB;
        $alerts = [];
        $now = time();
        $warningthreshold = $now + ($this->alertthresholds['trainer_expiry_days'] * 86400);
        
        $expiringtrainers = $DB->get_records_sql(
            "SELECT t.*, u.firstname, u.lastname, u.email
             FROM {local_rtocompliance_trainers} t
             JOIN {user} u ON u.id = t.userid
             WHERE (t.taeexpiry IS NOT NULL AND t.taeexpiry < ?)
             OR (t.vocationalexpiry IS NOT NULL AND t.vocationalexpiry < ?)
             OR (t.currencyexpiry IS NOT NULL AND t.currencyexpiry < ?)",
            [$warningthreshold, $warningthreshold, $warningthreshold]
        );
        
        foreach ($expiringtrainers as $trainer) {
            $expiries = [];
            $daysuntil = PHP_INT_MAX;
            
            if ($trainer->taeexpiry && $trainer->taeexpiry < $warningthreshold) {
                $days = ceil(($trainer->taeexpiry - $now) / 86400);
                $expiries[] = 'TAE qualification';
                $daysuntil = min($daysuntil, $days);
            }
            if ($trainer->vocationalexpiry && $trainer->vocationalexpiry < $warningthreshold) {
                $days = ceil(($trainer->vocationalexpiry - $now) / 86400);
                $expiries[] = 'Vocational competency';
                $daysuntil = min($daysuntil, $days);
            }
            if ($trainer->currencyexpiry && $trainer->currencyexpiry < $warningthreshold) {
                $days = ceil(($trainer->currencyexpiry - $now) / 86400);
                $expiries[] = 'Industry currency';
                $daysuntil = min($daysuntil, $days);
            }
            
            $severity = $daysuntil <= 7 ? 'critical' : ($daysuntil <= 14 ? 'high' : 'medium');
            
            $alerts[] = [
                'alerttype' => 'trainer_expiry',
                'severity' => $severity,
                'title' => get_string('alert_trainer_expiry_title', 'local_rtocompliance', $trainer->firstname . ' ' . $trainer->lastname),
                'description' => get_string('alert_trainer_expiry_desc', 'local_rtocompliance', implode(', ', $expiries)),
                'recommendation' => get_string('alert_trainer_expiry_action', 'local_rtocompliance'),
                'targettype' => 'trainer',
                'targetid' => $trainer->id,
                'targetuserid' => $trainer->userid,
                'riskscore' => $this->calculate_trainer_risk_score($trainer, $daysuntil),
                'riskfactors' => json_encode($expiries),
                'duedate' => min(
                    $trainer->taeexpiry ?: PHP_INT_MAX,
                    $trainer->vocationalexpiry ?: PHP_INT_MAX,
                    $trainer->currencyexpiry ?: PHP_INT_MAX
                ),
                'daysuntildue' => $daysuntil,
            ];
        }
        
        return $alerts;
    }

    private function check_student_compliance(): array {
        global $DB;
        $alerts = [];
        
        $missingusi = $DB->get_records_sql(
            "SELECT s.*, u.firstname, u.lastname
             FROM {local_rtocompliance_students} s
             JOIN {user} u ON u.id = s.userid
             WHERE (s.usi IS NULL OR s.usi = '')
             AND EXISTS (
                 SELECT 1 FROM {local_rtocompliance_enrolments} e 
                 WHERE e.studentid = s.id 
                 AND e.outcomeidentifier IN ('20', '51', '52', '60', '81', '82')
             )"
        );
        
        if (count($missingusi) > 0) {
            $alerts[] = [
                'alerttype' => 'missing_data',
                'severity' => 'critical',
                'title' => get_string('alert_missing_usi_title', 'local_rtocompliance', count($missingusi)),
                'description' => get_string('alert_missing_usi_desc', 'local_rtocompliance'),
                'recommendation' => get_string('alert_missing_usi_action', 'local_rtocompliance'),
                'targettype' => 'student',
                'riskscore' => min(100, count($missingusi) * 10),
                'riskfactors' => json_encode(['missing_usi' => count($missingusi)]),
            ];
        }
        
        $incompleteprofiles = $DB->get_records_sql(
            "SELECT s.*, u.firstname, u.lastname
             FROM {local_rtocompliance_students} s
             JOIN {user} u ON u.id = s.userid
             WHERE s.profilecomplete = 0"
        );
        
        $totalstudents = $DB->count_records('local_rtocompliance_students');
        $incompletepercentage = $totalstudents > 0 ? (count($incompleteprofiles) / $totalstudents) * 100 : 0;
        
        if ($incompletepercentage > $this->alertthresholds['incomplete_profile_warning_percentage']) {
            $alerts[] = [
                'alerttype' => 'missing_data',
                'severity' => 'high',
                'title' => get_string('alert_incomplete_profiles_title', 'local_rtocompliance', count($incompleteprofiles)),
                'description' => get_string('alert_incomplete_profiles_desc', 'local_rtocompliance', round($incompletepercentage, 1)),
                'recommendation' => get_string('alert_incomplete_profiles_action', 'local_rtocompliance'),
                'targettype' => 'student',
                'riskscore' => min(100, $incompletepercentage * 2),
                'riskfactors' => json_encode(['incomplete_profiles' => count($incompleteprofiles), 'percentage' => $incompletepercentage]),
            ];
        }
        
        return $alerts;
    }

    private function check_deadline_risks(): array {
        global $DB;
        $alerts = [];
        $now = time();
        $warningthreshold = $now + ($this->alertthresholds['deadline_warning_days'] * 86400);
        
        $upcomingdeadlines = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_deadlines}
             WHERE duedate > ? AND duedate <= ?
             AND status != 'completed'
             ORDER BY duedate ASC",
            [$now, $warningthreshold]
        );
        
        foreach ($upcomingdeadlines as $deadline) {
            $daysuntil = ceil(($deadline->duedate - $now) / 86400);
            $severity = $daysuntil <= 3 ? 'critical' : ($daysuntil <= 7 ? 'high' : 'medium');
            
            $alerts[] = [
                'alerttype' => 'deadline',
                'severity' => $severity,
                'title' => $deadline->title,
                'description' => $deadline->description ?: get_string('alert_deadline_approaching', 'local_rtocompliance', $daysuntil),
                'recommendation' => get_string('alert_deadline_action', 'local_rtocompliance'),
                'targettype' => 'deadline',
                'targetid' => $deadline->id,
                'duedate' => $deadline->duedate,
                'daysuntildue' => $daysuntil,
                'riskscore' => max(0, 100 - ($daysuntil * 5)),
            ];
        }
        
        return $alerts;
    }

    private function check_nat_validation_issues(): array {
        global $DB;
        $alerts = [];

        // BUG-PRED-1 FIX: NOT REGEXP is MySQL-only and fails on PostgreSQL Moodle installs.
        // Pull non-empty postcodes in PHP and validate with preg_match() for portability.
        $postcoderows = $DB->get_fieldset_sql(
            "SELECT postcode FROM {local_rtocompliance_students}
             WHERE postcode IS NOT NULL AND postcode != ''"
        );
        $invalidpostcodes = 0;
        foreach ($postcoderows as $pc) {
            if (!preg_match('/^[0-9]{4}$/', $pc)) {
                $invalidpostcodes++;
            }
        }
        
        if ($invalidpostcodes > 0) {
            $alerts[] = [
                'alerttype' => 'nat_validation',
                'severity' => 'medium',
                'title' => get_string('alert_invalid_postcodes_title', 'local_rtocompliance', $invalidpostcodes),
                'description' => get_string('alert_invalid_postcodes_desc', 'local_rtocompliance'),
                'recommendation' => get_string('alert_invalid_postcodes_action', 'local_rtocompliance'),
                'targettype' => 'student',
                'riskscore' => min(50, $invalidpostcodes * 5),
                'riskfactors' => json_encode(['invalid_postcodes' => $invalidpostcodes]),
            ];
        }
        
        $missingoutcomes = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
             WHERE (outcomeidentifier IS NULL OR outcomeidentifier = '' OR outcomeidentifier = '00')
             AND activityenddate IS NOT NULL
             AND activityenddate < ?",
            [time()]
        );
        
        if ($missingoutcomes > 0) {
            $alerts[] = [
                'alerttype' => 'nat_validation',
                'severity' => 'high',
                'title' => get_string('alert_missing_outcomes_title', 'local_rtocompliance', $missingoutcomes),
                'description' => get_string('alert_missing_outcomes_desc', 'local_rtocompliance'),
                'recommendation' => get_string('alert_missing_outcomes_action', 'local_rtocompliance'),
                'targettype' => 'enrolment',
                'riskscore' => min(80, $missingoutcomes * 2),
                'riskfactors' => json_encode(['missing_outcomes' => $missingoutcomes]),
            ];
        }
        
        return $alerts;
    }

    private function check_certificate_issues(): array {
        global $DB;
        $alerts = [];
        
        // BUG-2 FIX: Table is local_rtocompliance_certs (not local_rtocompliance_certificates).
        // Also certs table has no 'pending' status — newly created certs are 'issued' immediately.
        // Check instead for certs created but not yet emailed to the student after 7 days.
        $pendingcerts = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {local_rtocompliance_certs}
             WHERE status = 'issued'
             AND emailsent = 0
             AND timecreated < ?",
            [time() - (7 * 86400)]
        );
        
        if ($pendingcerts > 0) {
            $alerts[] = [
                'alerttype' => 'certificate',
                'severity' => 'medium',
                'title' => get_string('alert_pending_certs_title', 'local_rtocompliance', $pendingcerts),
                'description' => get_string('alert_pending_certs_desc', 'local_rtocompliance'),
                'recommendation' => get_string('alert_pending_certs_action', 'local_rtocompliance'),
                'targettype' => 'certificate',
                'riskscore' => min(50, $pendingcerts * 5),
                'riskfactors' => json_encode(['pending_certificates' => $pendingcerts]),
            ];
        }
        
        return $alerts;
    }

    private function calculate_trainer_risk_score(object $trainer, int $daysuntil): float {
        $basescore = 50;
        
        if ($daysuntil <= 0) {
            $basescore = 100;
        } elseif ($daysuntil <= 7) {
            $basescore = 90;
        } elseif ($daysuntil <= 14) {
            $basescore = 70;
        } elseif ($daysuntil <= 30) {
            $basescore = 50;
        } else {
            $basescore = 30;
        }
        
        return min(100, $basescore);
    }

    private function save_alerts(array $alerts): void {
        global $DB;
        $now = time();
        
        foreach ($alerts as $alert) {
            $existingalert = $DB->get_record_sql(
                "SELECT id FROM {local_rtocompliance_ai_alerts}
                 WHERE alerttype = ? AND targettype = ? AND targetid = ? AND status = 'active'",
                [$alert['alerttype'], $alert['targettype'] ?? '', $alert['targetid'] ?? 0]
            );
            
            if ($existingalert) {
                $record = new \stdClass();
                $record->id = $existingalert->id;
                $record->severity = $alert['severity'];
                $record->description = $alert['description'];
                $record->riskscore = $alert['riskscore'] ?? null;
                $record->riskfactors = $alert['riskfactors'] ?? null;
                $record->daysuntildue = $alert['daysuntildue'] ?? null;
                $record->timemodified = $now;
                $DB->update_record('local_rtocompliance_ai_alerts', $record);
            } else {
                $record = new \stdClass();
                $record->alerttype = $alert['alerttype'];
                $record->severity = $alert['severity'];
                $record->title = $alert['title'];
                $record->description = $alert['description'];
                $record->recommendation = $alert['recommendation'] ?? null;
                $record->targettype = $alert['targettype'] ?? null;
                $record->targetid = $alert['targetid'] ?? null;
                $record->targetuserid = $alert['targetuserid'] ?? null;
                $record->riskscore = $alert['riskscore'] ?? null;
                $record->riskfactors = $alert['riskfactors'] ?? null;
                $record->predictedimpact = $alert['predictedimpact'] ?? null;
                $record->duedate = $alert['duedate'] ?? null;
                $record->daysuntildue = $alert['daysuntildue'] ?? null;
                $record->status = 'active';
                $record->aigenerated = 0;
                $record->timecreated = $now;
                $record->timemodified = $now;
                $DB->insert_record('local_rtocompliance_ai_alerts', $record);
            }
        }
    }

    public function get_active_alerts(string $severity = null, int $limit = 50): array {
        global $DB;
        
        $sql = "SELECT * FROM {local_rtocompliance_ai_alerts} WHERE status = 'active'";
        $params = [];
        
        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }
        
        // BUG-PRED-2 FIX: NULLS LAST is not supported in MySQL 5.7 (Moodle minimum).
        // Use the portable ISNULL()/IS NULL trick: ORDER BY (duedate IS NULL), duedate ASC
        // puts NULL rows last on both MySQL and PostgreSQL.
        $sql .= " ORDER BY 
            CASE severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
                ELSE 5 
            END,
            (CASE WHEN duedate IS NULL THEN 1 ELSE 0 END),
            duedate ASC,
            timecreated DESC";
        
        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    public function acknowledge_alert(int $alertid, int $userid): bool {
        global $DB;
        
        $record = new \stdClass();
        $record->id = $alertid;
        $record->status = 'acknowledged';
        $record->acknowledgedby = $userid;
        $record->acknowledgeddate = time();
        $record->timemodified = time();
        
        return $DB->update_record('local_rtocompliance_ai_alerts', $record);
    }

    public function resolve_alert(int $alertid, int $userid, string $notes = ''): bool {
        global $DB;
        
        $record = new \stdClass();
        $record->id = $alertid;
        $record->status = 'resolved';
        $record->resolvedby = $userid;
        $record->resolveddate = time();
        $record->resolutionnotes = $notes;
        $record->timemodified = time();
        
        return $DB->update_record('local_rtocompliance_ai_alerts', $record);
    }

    public function dismiss_alert(int $alertid): bool {
        global $DB;
        
        $record = new \stdClass();
        $record->id = $alertid;
        $record->status = 'dismissed';
        $record->timemodified = time();
        
        return $DB->update_record('local_rtocompliance_ai_alerts', $record);
    }

    public function get_compliance_summary(): array {
        global $DB;
        
        $criticalcount = $DB->count_records('local_rtocompliance_ai_alerts', ['status' => 'active', 'severity' => 'critical']);
        $highcount = $DB->count_records('local_rtocompliance_ai_alerts', ['status' => 'active', 'severity' => 'high']);
        $mediumcount = $DB->count_records('local_rtocompliance_ai_alerts', ['status' => 'active', 'severity' => 'medium']);
        $lowcount = $DB->count_records('local_rtocompliance_ai_alerts', ['status' => 'active', 'severity' => 'low']);
        
        $totalalerts = $criticalcount + $highcount + $mediumcount + $lowcount;
        
        if ($criticalcount > 0) {
            $overallstatus = 'critical';
            $riskscore = 90 + min(10, $criticalcount);
        } elseif ($highcount > 0) {
            $overallstatus = 'warning';
            $riskscore = 60 + min(30, $highcount * 5);
        } elseif ($mediumcount > 0) {
            $overallstatus = 'attention';
            $riskscore = 30 + min(30, $mediumcount * 3);
        } elseif ($lowcount > 0) {
            $overallstatus = 'good';
            $riskscore = 10 + min(20, $lowcount * 2);
        } else {
            $overallstatus = 'excellent';
            $riskscore = 0;
        }
        
        return [
            'overall_status' => $overallstatus,
            'risk_score' => min(100, $riskscore),
            'total_alerts' => $totalalerts,
            'by_severity' => [
                'critical' => $criticalcount,
                'high' => $highcount,
                'medium' => $mediumcount,
                'low' => $lowcount,
            ],
        ];
    }
}
