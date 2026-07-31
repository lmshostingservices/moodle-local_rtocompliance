<?php
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
        'minute' => '0',
        'hour' => '*/4',
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
];
