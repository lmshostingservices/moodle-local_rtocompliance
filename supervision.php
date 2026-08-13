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
 * RTO Compliance plugin — supervision.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_supervision');
require_login();
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
    ['class' => 'btn btn-primary', 'title' => 'Record a new supervision and direction log']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'ASQA Credential Policy Requirements');
echo html_writer::tag('p', 'Trainers in roles 1C, 1D, 2A, 2B, and 2C require documented supervision and direction. This log tracks supervision activities, QA reviews, and development progress for trainers working towards full credentials.');
echo html_writer::link(
    'https://www.asqa.gov.au/how-we-regulate/revised-standards-rtos/practice-guides/practice-guide-credential-policy',
    'View ASQA Trainer Credential Policy',
    ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Open the ASQA Trainer Credential Policy practice guide in a new tab']
);
echo html_writer::end_div();

// Std 3.2 (T-P1-3): read-only alert for Working Towards trainers whose 2-year TAE deadline has
// passed (OVERDUE) or is within the next 90 days (DUE SOON).
$wtnow = time();
$wtsoon = $wtnow + (90 * 86400);
$wtalerts = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_trainers')) {
    try {
        $wtalerts = $DB->get_records_sql(
            "SELECT t.id, t.wtdeadline,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
             FROM {local_rtocompliance_trainers} t
             JOIN {user} u ON u.id = t.userid
             WHERE t.taecredential = 'Working Towards'
               AND t.wtdeadline IS NOT NULL
               AND t.wtdeadline > 0
               AND t.wtdeadline <= :wtsoon
             ORDER BY t.wtdeadline ASC",
            ['wtsoon' => $wtsoon]
        );
    } catch (Exception $e) {
        $wtalerts = [];
    }
}

if ($wtalerts) {
    $overdue = [];
    $duesoon = [];
    foreach ($wtalerts as $wt) {
        if ($wt->wtdeadline < $wtnow) {
            $overdue[] = $wt;
        } else {
            $duesoon[] = $wt;
        }
    }

    $renderrow = function ($wt, $isoverdue) {
        $name = (object)[
            'firstname' => $wt->firstname,
            'lastname' => $wt->lastname,
            'firstnamephonetic' => $wt->firstnamephonetic,
            'lastnamephonetic' => $wt->lastnamephonetic,
            'middlename' => $wt->middlename,
            'alternatename' => $wt->alternatename,
        ];
        $badgeclass = $isoverdue ? 'badge-danger' : 'badge-warning';
        $badgetext = $isoverdue ? 'OVERDUE' : 'DUE SOON';
        $link = html_writer::link(
            new moodle_url('/local/rtocompliance/trainer_edit.php', ['id' => $wt->id]),
            fullname($name)
        );
        return html_writer::tag('div',
            html_writer::tag('span', $badgetext, ['class' => 'badge ' . $badgeclass, 'style' => 'margin-right: 8px;'])
            . html_writer::tag('strong', $link)
            . ' — 2-year TAE deadline: ' . userdate($wt->wtdeadline, '%d %b %Y'),
            ['style' => 'padding: 4px 0; border-bottom: 1px solid #eee;']);
    };

    $alertclass = !empty($overdue) ? 'alert-danger' : 'alert-warning';
    echo html_writer::start_div('info-card ' . $alertclass, ['style' => 'margin: 20px 0;']);
    echo html_writer::tag('h4', 'Working Towards TAE — Deadline Alerts');
    echo html_writer::tag('p',
        'The following Working Towards trainers must complete their full TAE within 2 years of commencement. Review supervision arrangements before the deadline passes.');
    foreach ($overdue as $wt) {
        echo $renderrow($wt, true);
    }
    foreach ($duesoon as $wt) {
        echo $renderrow($wt, false);
    }
    echo html_writer::end_div();
}

$filter = optional_param('filter', 'all', PARAM_ALPHA);

echo html_writer::start_div('filter-tabs', ['id' => 'filters', 'style' => 'margin: 20px 0;']);
$filters = [
    'all' => 'All Logs',
    'pending' => 'Pending Validation',
    'validated' => 'Validated',
    'overdue' => 'Overdue Actions',
];
$filtertips = [
    'all' => 'Show all supervision logs',
    'pending' => 'Show logs awaiting manager validation',
    'validated' => 'Show logs that have been validated',
    'overdue' => 'Show logs with overdue follow-up actions',
];
foreach ($filters as $key => $label) {
    $url = new moodle_url('/local/rtocompliance/supervision.php', ['filter' => $key]);
    echo html_writer::link(
        $url->out(false) . '#filters',
        $label,
        ['class' => 'btn btn-sm ' . ($filter == $key ? 'btn-primary' : 'btn-secondary'), 'style' => 'margin-right: 8px;', 'title' => $filtertips[$key] ?? $label]
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

$sql .= " ORDER BY s.supervisiondate DESC"; // v5.9.368: LIMIT moved to get_records_sql for cross-DB portability

$logs = [];
$tableexists = $DB->get_manager()->table_exists('local_rtocompliance_supervision');
$trainertableexists = $DB->get_manager()->table_exists('local_rtocompliance_trainers');

if ($tableexists && $trainertableexists) {
    try {
        $logs = $DB->get_records_sql($sql, $params, 0, 50);
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
    echo html_writer::tag('th', 'Date', ['title' => 'Date the supervision activity took place']);
    echo html_writer::tag('th', 'Trainer', ['title' => 'Trainer receiving supervision and direction']);
    echo html_writer::tag('th', 'Supervisor', ['title' => 'Qualified supervisor providing the direction']);
    echo html_writer::tag('th', 'Type', ['title' => 'Type of supervision activity, such as observation or QA check']);
    echo html_writer::tag('th', 'Qualification', ['title' => 'Qualification or unit code the supervision relates to']);
    echo html_writer::tag('th', 'Duration', ['title' => 'Length of the supervision session in minutes']);
    echo html_writer::tag('th', 'Status', ['title' => 'Validation and follow-up action status of this log']);
    echo html_writer::tag('th', 'Actions', ['title' => 'View this supervision log']);
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
        echo html_writer::tag('td', s($supervisiontypes[$log->supervisiontype] ?? $log->supervisiontype)); // v5.9.368: escape free-text
        echo html_writer::tag('td', $log->qualificationcode ? s($log->qualificationcode) : '-');
        echo html_writer::tag('td', $log->duration ? $log->duration . ' mins' : '-');
        echo html_writer::tag('td', html_writer::tag('span', $status, ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/supervision_edit.php', ['id' => $log->id]),
                'View',
                ['class' => 'btn btn-sm btn-secondary', 'title' => 'View this supervision log']
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
