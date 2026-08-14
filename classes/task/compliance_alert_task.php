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
 * Weekly compliance-alert digest.
 *
 * Computes the key audit-readiness risk signals (the same ones shown on the
 * Compliance Health command centre) and, when any need attention, emails a
 * plain-English digest to the site administrators (plus any extra address set
 * in the 'alertemail' config) with a link to the Compliance Health page.
 *
 * Fully read-only and defensively guarded — a missing table or a mail failure
 * can never break the cron run. When everything is clear it sends nothing (to
 * avoid alert fatigue) and just notes it in the task trace.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class compliance_alert_task extends \core\task\scheduled_task {
    public function get_name() {
        if (get_string_manager()->string_exists('task_compliance_alert', 'local_rtocompliance')) {
            return get_string('task_compliance_alert', 'local_rtocompliance');
        }
        return 'RTO compliance weekly alert digest';
    }

    public function execute() {
        global $DB, $CFG;

        // Master on/off (default on). Set config 'compliancealerts' to 0 to disable.
        $enabled = get_config('local_rtocompliance', 'compliancealerts');
        if ($enabled === '0' || $enabled === 0) {
            mtrace('  [compliance_alert] disabled by config — skipping.');
            return;
        }

        $dbman = $DB->get_manager();
        $now   = time();

        // Small guarded counter.
        $count = function (string $table, string $select, array $params) use ($DB, $dbman): int {
            try {
                if (!$dbman->table_exists($table)) {
                    return 0;
                }
                return (int) $DB->count_records_select($table, $select, $params);
            } catch (\Throwable $e) {
                return 0;
            }
        };

        $alerts = [];

        // QA1 — validations overdue.
        $n = $count('local_rtocompliance_validations',
            'nextduedate IS NOT NULL AND nextduedate > 0 AND nextduedate < :now', ['now' => $now]);
        if ($n > 0) {
            $alerts[] = [$n, 'assessment validation(s) overdue for their five-year cycle',
                '/local/rtocompliance/validation.php'];
        }

        // QA3 — working-towards trainers past their 2-year TAE deadline.
        $n = $count('local_rtocompliance_trainers',
            "taecredential = :wt AND wtdeadline IS NOT NULL AND wtdeadline > 0 AND wtdeadline < :now",
            ['wt' => 'Working Towards', 'now' => $now]);
        if ($n > 0) {
            $alerts[] = [$n, 'working-towards trainer(s) past their 2-year TAE completion deadline',
                '/local/rtocompliance/supervision.php'];
        }

        // QA3 — stale industry currency (older than 365 days).
        $n = $count('local_rtocompliance_trainers',
            "(industrycurrencydate IS NULL OR industrycurrencydate < :cut) AND (status IS NULL OR status <> :inactive)",
            ['cut' => $now - (365 * 86400), 'inactive' => 'inactive']);
        if ($n > 0) {
            $alerts[] = [$n, 'trainer(s) whose industry currency is stale or unrecorded (>12 months)',
                '/local/rtocompliance/trainers.php'];
        }

        // Certification — students with recorded results but an unverified USI (cert-blocking).
        try {
            if ($dbman->table_exists('local_rtocompliance_students')
                && $dbman->table_exists('local_rtocompliance_enrolments')) {
                $blocked = (int) $DB->count_records_sql(
                    "SELECT COUNT(DISTINCT s.id)
                       FROM {local_rtocompliance_students} s
                       JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
                      WHERE s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified = 0");
                if ($blocked > 0) {
                    $alerts[] = [$blocked, 'student(s) with results but an UNVERIFIED USI — their certificates cannot be issued',
                        '/local/rtocompliance/students.php'];
                }
            }
        } catch (\Throwable $e) {
            debugging('compliance_alert usi-blocked query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Certification — certs past the 30-day issuance SLA.
        $n = $count('local_rtocompliance_certs',
            'timecompleted IS NOT NULL AND timecompleted > 0 AND timecompleted < :cut AND (timeissued IS NULL OR timeissued = 0)',
            ['cut' => $now - (30 * 86400)]);
        if ($n > 0) {
            $alerts[] = [$n, 'certificate(s) past the ASQA 30-day issuance rule',
                '/local/rtocompliance/certificates.php'];
        }

        // QA2 — incomplete AVETMISS student profiles.
        $n = $count('local_rtocompliance_students', 'profilecomplete = 0', []);
        if ($n > 0) {
            $alerts[] = [$n, 'student profile(s) with incomplete AVETMISS data',
                '/local/rtocompliance/students.php'];
        }

        if (empty($alerts)) {
            mtrace('  [compliance_alert] all key compliance checks clear — no digest sent.');
            return;
        }

        mtrace('  [compliance_alert] ' . count($alerts) . ' area(s) need attention — sending digest.');

        // ── Build recipients ─────────────────────────────────────────────────
        $recipients = [];
        try {
            foreach (get_admins() as $admin) {
                $recipients[$admin->id] = $admin;
            }
        } catch (\Throwable $e) {
            debugging('compliance_alert get_admins failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        // Optional extra address configured by the RTO.
        $extra = trim((string) get_config('local_rtocompliance', 'alertemail'));
        if ($extra !== '' && validate_email($extra)) {
            $fake = \core_user::get_noreply_user();
            $u = clone $fake;
            $u->id = -1;
            $u->email = $extra;
            $u->firstname = 'RTO';
            $u->lastname = 'Compliance';
            $u->maildisplay = 1;
            $recipients['extra'] = $u;
        }
        if (empty($recipients)) {
            mtrace('  [compliance_alert] no recipients resolved — skipping send.');
            return;
        }

        // ── Build message ────────────────────────────────────────────────────
        $healthurl = $CFG->wwwroot . '/local/rtocompliance/compliance_health.php';
        $rtoname   = trim((string) get_config('local_rtocompliance', 'rtoname'));
        $subject   = 'RTO Compliance — ' . count($alerts) . ' area(s) need attention'
            . ($rtoname !== '' ? ' (' . $rtoname . ')' : '');

        $textlines = ['These RTO compliance items need attention:', ''];
        $htmlrows  = '';
        foreach ($alerts as [$num, $desc, $path]) {
            $textlines[] = '  • ' . $num . ' ' . $desc;
            $htmlrows .= '<tr><td style="padding:6px 12px;font-weight:700;color:#b91c1c;text-align:right;">' . (int) $num . '</td>'
                . '<td style="padding:6px 12px;">' . s($desc)
                . ' &nbsp;<a href="' . $CFG->wwwroot . s($path) . '">review</a></td></tr>';
        }
        $textlines[] = '';
        $textlines[] = 'Open the Compliance Health page for the full picture: ' . $healthurl;
        $textbody = implode("\n", $textlines);

        $htmlbody = '<div style="font-family:sans-serif;max-width:640px;">'
            . '<h2 style="color:#0f172a;">RTO Compliance — action needed</h2>'
            . '<p style="color:#475569;">The weekly compliance check found ' . count($alerts)
            . ' area(s) that need attention' . ($rtoname !== '' ? ' at <strong>' . s($rtoname) . '</strong>' : '') . ':</p>'
            . '<table style="border-collapse:collapse;width:100%;border:1px solid #e2e8f0;">' . $htmlrows . '</table>'
            . '<p style="margin-top:18px;"><a href="' . $healthurl . '" '
            . 'style="background:#2563eb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;">'
            . 'Open Compliance Health →</a></p>'
            . '<p style="color:#94a3b8;font-size:12px;margin-top:16px;">Automated weekly digest from the RTO Compliance plugin. '
            . 'To turn this off, set the plugin config <code>compliancealerts</code> to 0.</p></div>';

        $from = \core_user::get_noreply_user();
        $sent = 0;
        foreach ($recipients as $to) {
            try {
                if (email_to_user($to, $from, $subject, $textbody, $htmlbody)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                debugging('compliance_alert email_to_user failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        mtrace('  [compliance_alert] digest sent to ' . $sent . ' recipient(s).');
    }
}
