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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_supervision');
require_capability('local/rtocompliance:managetrainers', context_system::instance());
$PAGE->set_title(get_string('supervision_log', 'local_rtocompliance'));
$PAGE->set_heading(get_string('supervision_log', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('supervision_log', 'local_rtocompliance'), null, null, 'supervision');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('supervision_log', 'local_rtocompliance'));
echo html_writer::link(
    new moodle_url('/local/rtocompliance/supervision_edit.php'),
    get_string('add_supervision', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'ASQA Credential Policy Requirements');
echo html_writer::tag('p', 'Trainers in roles 1C, 1D, 2A, 2B, and 2C require documented supervision and direction. This log tracks supervision activities, QA reviews, and development progress for trainers working towards full credentials.');
echo html_writer::link(
    'https://www.asqa.gov.au/how-we-regulate/revised-standards-rtos/practice-guides/practice-guide-credential-policy',
    'View ASQA Trainer Credential Policy',
    ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank', 'rel' => 'noopener noreferrer']
);
echo html_writer::end_div();

$filter = optional_param('filter', 'all', PARAM_ALPHA);

echo html_writer::start_div('filter-tabs', ['id' => 'filters', 'style' => 'margin: 20px 0;']);
$filters = [
    'all' => 'All Logs',
    'pending' => 'Pending Validation',
    'validated' => 'Validated',
    'overdue' => 'Overdue Actions',
];
foreach ($filters as $key => $label) {
    $url = new moodle_url('/local/rtocompliance/supervision.php', ['filter' => $key]);
    echo html_writer::link(
        $url->out(false) . '#filters',
        $label,
        ['class' => 'btn btn-sm ' . ($filter == $key ? 'btn-primary' : 'btn-secondary'), 'style' => 'margin-right: 8px;']
    );
}
echo html_writer::end_div();

$sql = "SELECT s.*, 
        t.userid as trainer_userid, 
        sup.userid as supervisor_userid,
        tu.firstname as trainer_firstname, tu.lastname as trainer_lastname,
        tu.firstnamephonetic as trainer_firstnamephonetic, tu.lastnamephonetic as trainer_lastnamephonetic,
        tu.middlename as trainer_middlename, tu.alternatename as trainer_alternatename,
        su.firstname as supervisor_firstname, su.lastname as supervisor_lastname,
        su.firstnamephonetic as supervisor_firstnamephonetic, su.lastnamephonetic as supervisor_lastnamephonetic,
        su.middlename as supervisor_middlename, su.alternatename as supervisor_alternatename
        FROM {local_rtocompliance_supervision} s
        JOIN {local_rtocompliance_trainers} t ON t.id = s.trainerid
        JOIN {local_rtocompliance_trainers} sup ON sup.id = s.supervisorid
        JOIN {user} tu ON tu.id = t.userid
        JOIN {user} su ON su.id = sup.userid";

$params = [];
$whereclauses = [];

if ($filter == 'pending') {
    $whereclauses[] = "s.managervalidated = 0";
} elseif ($filter == 'validated') {
    $whereclauses[] = "s.managervalidated = 1";
} elseif ($filter == 'overdue') {
    $whereclauses[] = "s.actionscompleted = 0 AND s.actionsduedate < :now AND s.actionsduedate > 0";
    $params['now'] = time();
}

if (!empty($whereclauses)) {
    $sql .= " WHERE " . implode(' AND ', $whereclauses);
}

$sql .= " ORDER BY s.supervisiondate DESC LIMIT 50";

$logs = [];
$tableexists = $DB->get_manager()->table_exists('local_rtocompliance_supervision');
$trainertableexists = $DB->get_manager()->table_exists('local_rtocompliance_trainers');

if ($tableexists && $trainertableexists) {
    try {
        $logs = $DB->get_records_sql($sql, $params);
    } catch (Exception $e) {
        // Handle case where columns may not exist yet
        $logs = [];
    }
}

$supervisiontypes = [
    'observation' => 'Observation',
    'feedback' => 'Feedback Session',
    'assessment_review' => 'Assessment Review',
    'qa_check' => 'QA Check',
    'mentoring' => 'Mentoring',
];

if ($logs) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Date');
    echo html_writer::tag('th', 'Trainer');
    echo html_writer::tag('th', 'Supervisor');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Qualification');
    echo html_writer::tag('th', 'Duration');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($logs as $log) {
        $trainername = (object)[
            'firstname' => $log->trainer_firstname,
            'lastname' => $log->trainer_lastname,
            'firstnamephonetic' => $log->trainer_firstnamephonetic,
            'lastnamephonetic' => $log->trainer_lastnamephonetic,
            'middlename' => $log->trainer_middlename,
            'alternatename' => $log->trainer_alternatename,
        ];
        $supervisorname = (object)[
            'firstname' => $log->supervisor_firstname,
            'lastname' => $log->supervisor_lastname,
            'firstnamephonetic' => $log->supervisor_firstnamephonetic,
            'lastnamephonetic' => $log->supervisor_lastnamephonetic,
            'middlename' => $log->supervisor_middlename,
            'alternatename' => $log->supervisor_alternatename,
        ];

        $statusclass = 'badge-warning';
        $status = 'Pending Validation';
        if ($log->managervalidated) {
            $statusclass = 'badge-success';
            $status = 'Validated';
        }
        if (!$log->actionscompleted && $log->actionsduedate && $log->actionsduedate < time()) {
            $statusclass = 'badge-danger';
            $status = 'Actions Overdue';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($log->supervisiondate, '%d %b %Y'));
        echo html_writer::tag('td', html_writer::tag('strong', fullname($trainername)));
        echo html_writer::tag('td', fullname($supervisorname));
        echo html_writer::tag('td', $supervisiontypes[$log->supervisiontype] ?? $log->supervisiontype);
        echo html_writer::tag('td', $log->qualificationcode ?: '-');
        echo html_writer::tag('td', $log->duration ? $log->duration . ' mins' : '-');
        echo html_writer::tag('td', html_writer::tag('span', $status, ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/supervision_edit.php', ['id' => $log->id]),
                'View',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/user', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Supervision Logs');
    echo html_writer::tag('p', 'Supervision and direction logs for trainers working towards credentials will appear here. Use the Add Supervision Log button to record supervision activities.');
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
