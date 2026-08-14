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
 * v4.4.0 NRT-LOGO-COMPLIANCE — scheduled task that flags certificates
 * marked complete more than 30 days ago that have not yet been issued.
 *
 * Per the ASQA Practice Guide (Issue of VET qualifications and VET
 * statements of attainment, 17 Jun 2025), certificates must be issued
 * within 30 calendar days of the completion date. This task surfaces
 * any breach so an administrator can chase the outstanding issuance
 * before it becomes an audit finding.
 *
 * The task is read-only (no DB writes against cert rows) — it logs the
 * count to mtrace and writes a single audit_logger entry per overdue
 * cert so the trace is durable across runs. It never sends emails by
 * itself; admins/auditors monitor the audit log.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class check_overdue_issuance extends \core\task\scheduled_task {
    /** Display name shown on the Site administration → Server → Scheduled tasks list. */
    public function get_name() {
        return get_string('task_check_overdue_issuance', 'local_rtocompliance');
    }

    /** Execute. Idempotent — safe to run multiple times per day. */
    public function execute() {
        global $DB, $CFG;

        // ASQA SLA: 30 calendar days from completion to issuance.
        $sla_seconds = 30 * 24 * 60 * 60;
        $cutoff      = time() - $sla_seconds;

        // Find certs with timecompleted older than the cutoff that have
        // not been issued (timeissued NULL or 0). Defensive: bail early
        // if the table is missing (fresh install before db/install.xml
        // has been processed).
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_rtocompliance_certs')) {
            mtrace('  [check_overdue_issuance] local_rtocompliance_certs table not present — skipping.');
            return;
        }

        $sql = "SELECT id, certnumber, userid, qualificationcode, qualificationname,
                       timecompleted, timeissued
                  FROM {local_rtocompliance_certs}
                 WHERE timecompleted IS NOT NULL
                   AND timecompleted > 0
                   AND timecompleted < :cutoff
                   AND (timeissued IS NULL OR timeissued = 0)";

        try {
            $overdue = $DB->get_records_sql($sql, ['cutoff' => $cutoff]);
        } catch (\Throwable $e) {
            mtrace('  [check_overdue_issuance] query failed: ' . $e->getMessage());
            return;
        }

        $count = count($overdue);
        mtrace('  [check_overdue_issuance] ' . $count . ' certificate(s) past the 30-day ASQA issuance SLA.');

        if ($count === 0) {
            return;
        }

        // Best-effort audit-logger write per overdue cert. Wrapped in a
        // try/catch so a missing helper or filearea on a partially-
        // installed site can never block the cron run.
        $libfile = $CFG->dirroot . '/local/rtocompliance/lib.php';
        if (file_exists($libfile)) {
            require_once($libfile);
        }
        foreach ($overdue as $cert) {
            $days_overdue = (int) floor((time() - (int) $cert->timecompleted) / 86400) - 30;
            $msg = sprintf(
                'Cert %s (qual %s "%s", user %d) is %d day(s) past the ASQA 30-day issuance SLA.',
                $cert->certnumber ?? ('id:' . $cert->id),
                $cert->qualificationcode ?? '?',
                $cert->qualificationname ?? '?',
                (int) $cert->userid,
                $days_overdue
            );
            mtrace('    - ' . $msg);
            if (function_exists('local_rtocompliance_audit_log')) {
                try {
                    \local_rtocompliance_audit_log('cert_overdue_issuance', [
                        'certid'        => (int) $cert->id,
                        'certnumber'    => $cert->certnumber,
                        'userid'        => (int) $cert->userid,
                        'days_overdue'  => $days_overdue,
                        'sla_days'      => 30,
                    ]);
                } catch (\Throwable $e) {
                    debugging('check_overdue_issuance audit_log failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }
}
