<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_dashboard');
$context = context_system::instance();

$entitytype = optional_param('entitytype', '', PARAM_ALPHAEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$from = optional_param('from', 0, PARAM_INT);
$to = optional_param('to', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/audit.php', [
    'entitytype' => $entitytype,
    'action' => $action,
    'userid' => $userid,
    'from' => $from,
    'to' => $to,
    'page' => $page,
]));
$PAGE->set_title('Audit Log');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Audit Log');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Audit Log');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Compliance Audit Trail');
echo html_writer::tag('p', 'Complete audit trail of all actions taken within the RTO Compliance system. Records are retained for 2 years per ASQA requirements for regulatory evidence.');
echo html_writer::end_div();

$perpage = 50;
$offset = $page * $perpage;

$filters = [];
if ($entitytype) {
    $filters['entitytype'] = $entitytype;
}
if ($action) {
    $filters['action'] = $action;
}
if ($userid) {
    $filters['userid'] = $userid;
}
if ($from) {
    $filters['from'] = $from;
}
if ($to) {
    $filters['to'] = $to;
}

echo html_writer::start_div('rtoc-filter-section');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring()]);

echo html_writer::start_div('rtoc-filter-row-flex');

$entitytypes = [
    '' => 'All Entity Types',
    'student' => 'Student',
    'enrolment' => 'Enrolment',
    'trainer' => 'Trainer',
    'certificate' => 'Certificate',
    'nat_export' => 'NAT Export',
    'tas' => 'TAS',
    'validation' => 'Validation',
    'governance' => 'Governance',
    'thirdparty' => 'Third Party',
    'deadline' => 'Deadline',
    'alert' => 'Alert',
    'survey' => 'Survey',
    'fee' => 'Fee',
];

echo html_writer::start_div('rtoc-filter-field');
echo html_writer::tag('label', 'Entity Type', ['for' => 'entitytype']);
echo html_writer::select($entitytypes, 'entitytype', $entitytype, null, ['id' => 'entitytype', 'class' => 'form-control']);
echo html_writer::end_div();

$actions = [
    '' => 'All Actions',
    'create' => 'Create',
    'update' => 'Update',
    'delete' => 'Delete',
    'view' => 'View',
    'export' => 'Export',
    'approve' => 'Approve',
    'reject' => 'Reject',
];

echo html_writer::start_div('rtoc-filter-field');
echo html_writer::tag('label', 'Action', ['for' => 'action']);
echo html_writer::select($actions, 'action', $action, null, ['id' => 'action', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('rtoc-filter-field');
echo html_writer::tag('label', 'User ID', ['for' => 'userid']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'userid',
    'id' => 'userid',
    'value' => $userid ?: '',
    'class' => 'form-control',
    'placeholder' => 'User ID',
]);
echo html_writer::end_div();

echo html_writer::tag('button', 'Filter', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/audit.php'),
    'Clear',
    ['class' => 'btn btn-secondary']
);

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();

$logs = audit_logger::get_logs($filters, $perpage + 1, $offset);
$hasmore = count($logs) > $perpage;
if ($hasmore) {
    array_pop($logs);
}

if ($logs) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Time');
    echo html_writer::tag('th', 'User');
    echo html_writer::tag('th', 'Action');
    echo html_writer::tag('th', 'Entity');
    echo html_writer::tag('th', 'ID');
    echo html_writer::tag('th', 'Description');
    echo html_writer::tag('th', 'IP Address');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($logs as $log) {
        $actionclass = 'badge-info';
        if ($log->action === 'create') {
            $actionclass = 'badge-success';
        } elseif ($log->action === 'delete') {
            $actionclass = 'badge-danger';
        } elseif ($log->action === 'update') {
            $actionclass = 'badge-warning';
        } elseif ($log->action === 'export') {
            $actionclass = 'badge-purple';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('span', userdate($log->timecreated, '%d %b %Y %H:%M'), ['style' => 'white-space: nowrap;']));
        echo html_writer::tag('td', $log->firstname ? $log->firstname . ' ' . $log->lastname : 'System');
        echo html_writer::tag('td', html_writer::tag('span', audit_logger::format_action_label($log->action), ['class' => 'badge ' . $actionclass]));
        echo html_writer::tag('td', audit_logger::format_entity_label($log->entitytype));
        echo html_writer::tag('td', $log->entityid ?: '-');
        echo html_writer::tag('td', $log->description ? format_string(substr($log->description, 0, 100)) : '-');
        echo html_writer::tag('td', html_writer::tag('code', $log->ipaddress ?: '-', ['style' => 'font-size: 0.75rem;']));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    echo html_writer::start_div('rtoc-center-actions');
    if ($page > 0) {
        $prevparams = array_merge($filters, ['page' => $page - 1]);
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/audit.php', $prevparams),
            'Previous',
            ['class' => 'btn btn-secondary']
        );
    }
    if ($hasmore) {
        $nextparams = array_merge($filters, ['page' => $page + 1]);
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/audit.php', $nextparams),
            'Next',
            ['class' => 'btn btn-secondary']
        );
    }
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/log', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Audit Records Found');
    echo html_writer::tag('p', 'Audit records will appear here as actions are performed in the system.');
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
