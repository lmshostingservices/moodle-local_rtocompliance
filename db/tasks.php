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
 * RTO Compliance plugin — tasks.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_rtocompliance\task\cleanup_old_logs_task',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '0',
        'disabled' => 0,
    ],
    [
        'classname' => '\local_rtocompliance\task\update_trainer_status_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '6',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => '\local_rtocompliance\task\refresh_compliance_metrics_task',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => '\local_rtocompliance\task\verify_usi_batch_task',
        'blocking' => 0,
        // USI-CADENCE (v6.2.44): run HOURLY (was every 4h). At 25 verifications/run that lifts
        // throughput from ~150/day to ~600/day to clear the backlog faster; still far under the
        // 60/min platform rate limit. Adjust down again once the backlog is cleared if desired.
        'minute' => '0',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => '\local_rtocompliance\task\send_completion_survey_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '8',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // v4.4.0 NRT-LOGO-COMPLIANCE — daily check that flags any cert
    // marked complete more than 30 days ago that has not yet been
    // issued (ASQA Practice Guide SLA: certificates must be issued
    // within 30 days of the unit/qualification completion date).
    [
        'classname' => '\local_rtocompliance\task\check_overdue_issuance',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '4',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // SYNC-COURSE-MAP-TASK (v5.9.337): nightly reseed of the course map table.
    // Prunes unconfirmed auto entries for deleted courses, then reseeds from
    // QB primary/variant courses and the category-tree regex. Runs at 01:30
    // so the map is always fresh before the morning cert-generation window.
    [
        'classname' => '\local_rtocompliance\task\sync_course_map_task',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '1',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // RECONCILE-COMPLETIONS-TASK (v5.9.374): populate the results register from
    // Moodle course completions (any category, incl. archived/semester-copy
    // variants). DISABLED by default — run on demand from Student Results first,
    // then enable here if you want it kept current nightly. Scheduled at 02:00,
    // after the course-map reseed at 01:30 so resolution is freshest.
    [
        'classname' => '\local_rtocompliance\task\reconcile_completions_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 1,
    ],
    // COMPLIANCE-ALERT-DIGEST (v5.9.399): weekly email to site admins summarising the
    // key audit-readiness risks (overdue validations, working-towards deadlines, stale
    // trainer currency, USI-blocked certificates, 30-day issuance breaches, incomplete
    // profiles). Sends only when something needs attention. Mondays at 07:00.
    [
        'classname' => '\local_rtocompliance\task\compliance_alert_task',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '7',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '1',
        'disabled' => 0,
    ],
];
