<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_governancepage');
$PAGE->set_url('/local/rtocompliance/governance_roles_edit.php', ['id' => $id]);
$PAGE->set_title($id ? 'Edit Role' : 'Add Role');
$PAGE->set_heading($id ? 'Edit Role' : 'Add Role');

$dbman = $DB->get_manager();

$record = null;
if ($id && $dbman->table_exists('local_rtocompliance_roles')) {
    $record = $DB->get_record('local_rtocompliance_roles', ['id' => $id]);
    if (!$record) {
        redirect(
            new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']),
            'Role not found.', null, \core\output\notification::NOTIFY_ERROR
        );
    }
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->rolename              = required_param('rolename', PARAM_TEXT);
    $data->roleowner             = optional_param('roleowner', '', PARAM_TEXT);
    $data->department            = optional_param('department', '', PARAM_TEXT);
    $data->responsibilities      = optional_param('responsibilities', '', PARAM_TEXT);
    $data->reportsto             = optional_param('reportsto', '', PARAM_TEXT);
    $data->regulatoryobligations = optional_param('regulatoryobligations', '', PARAM_TEXT);
    $revdateraw                  = optional_param('reviewdate', '', PARAM_TEXT);
    $data->reviewdate            = $revdateraw ? strtotime($revdateraw) : null;
    $data->notes                 = optional_param('notes', '', PARAM_TEXT);
    $data->timemodified          = time();

    if ($dbman->table_exists('local_rtocompliance_roles')) {
        if ($id && $record) {
            $data->id = $id;
            $DB->update_record('local_rtocompliance_roles', $data);
            local_rtocompliance_log_action('update', 'governance', $id, ['role' => $data->rolename]);
        } else {
            $data->timecreated = time();
            $DB->insert_record('local_rtocompliance_roles', $data);
            local_rtocompliance_log_action('create', 'governance', 0, ['role' => $data->rolename]);
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']),
        'Role saved.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    if ($dbman->table_exists('local_rtocompliance_roles')) {
        $DB->delete_records('local_rtocompliance_roles', ['id' => $id]);
    }
    redirect(
        new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']),
        'Role deleted.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('governance', 'local_rtocompliance'), null, null, 'governance');

echo html_writer::start_div('compliance-container');
echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', $id ? 'Edit Role & Responsibilities' : 'Add Role & Responsibilities');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']),
    'Back',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

$f = $record ?: new stdClass();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out_omit_querystring(),
    'style'  => 'max-width: 720px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);

$fields = [
    ['rolename', 'text', 'Role Title *', 'e.g. RTO Manager / CEO / Training Manager', true],
    ['roleowner', 'text', 'Current Role Holder', 'Name of person currently in this role', false],
    ['department', 'text', 'Department / Team', 'e.g. Operations, Training, Administration', false],
    ['reportsto', 'text', 'Reports To', 'e.g. CEO / Board of Directors', false],
];

foreach ($fields as [$name, $type, $label, $placeholder, $req]) {
    echo html_writer::start_div('form-group', ['style' => 'margin-bottom: 14px;']);
    echo html_writer::tag('label', $label, ['for' => $name, 'class' => 'form-label']);
    $attrs = ['type' => $type, 'name' => $name, 'id' => $name, 'class' => 'form-control', 'placeholder' => $placeholder, 'value' => s($f->$name ?? '')];
    if ($req) $attrs['required'] = 'required';
    echo html_writer::empty_tag('input', $attrs);
    echo html_writer::end_div();
}

foreach ([
    ['responsibilities', 'Key Responsibilities', 'List the key responsibilities and regulatory obligations of this role. Include how they contribute to ASQA compliance.', 5],
    ['regulatoryobligations', 'How Holder is Kept Informed of Regulatory Changes (Standard 4.2 evidence)', 'Describe the process: e.g. ASQA newsletter subscriptions, internal briefings, QMS update reviews, training.gov.au alerts, staff meetings.', 4],
    ['notes', 'Additional Notes', 'Any other relevant information', 3],
] as [$name, $label, $placeholder, $rows]) {
    echo html_writer::start_div('form-group', ['style' => 'margin-bottom: 14px;']);
    echo html_writer::tag('label', $label, ['for' => $name, 'class' => 'form-label']);
    echo html_writer::tag('textarea', s($f->$name ?? ''), [
        'name' => $name, 'id' => $name, 'class' => 'form-control',
        'rows' => $rows, 'placeholder' => $placeholder,
    ]);
    echo html_writer::end_div();
}

echo html_writer::start_div('form-group', ['style' => 'margin-bottom: 14px;']);
echo html_writer::tag('label', 'Review Date', ['for' => 'reviewdate', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'reviewdate', 'id' => 'reviewdate',
    'value' => !empty($f->reviewdate) ? date('Y-m-d', $f->reviewdate) : '',
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 20px;']);
echo html_writer::tag('button', $id ? 'Update' : 'Save', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'roles']), 'Cancel', ['class' => 'btn btn-secondary']);
if ($id) {
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/governance_roles_edit.php', ['id' => $id, 'action' => 'delete', 'sesskey' => sesskey()]),
        'Delete',
        ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete this role?")']
    );
}
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
