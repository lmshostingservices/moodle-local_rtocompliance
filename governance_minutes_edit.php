<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_governancepage');
$PAGE->set_url('/local/rtocompliance/governance_minutes_edit.php', ['id' => $id]);
$PAGE->set_title($id ? 'Edit Meeting Minutes' : 'Add Meeting Minutes');
$PAGE->set_heading($id ? 'Edit Meeting Minutes' : 'Add Meeting Minutes');

$dbman = $DB->get_manager();

$record = null;
if ($id && $dbman->table_exists('local_rtocompliance_minutes')) {
    $record = $DB->get_record('local_rtocompliance_minutes', ['id' => $id]);
    if (!$record) {
        redirect(
            new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']),
            'Record not found.', null, \core\output\notification::NOTIFY_ERROR
        );
    }
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->meetingtitle     = required_param('meetingtitle', PARAM_TEXT);
    $data->meetingtype      = required_param('meetingtype', PARAM_ALPHA);
    $meetingdateraw         = required_param('meetingdate', PARAM_TEXT);
    $data->meetingdate      = $meetingdateraw ? strtotime($meetingdateraw) : time();
    $data->location         = optional_param('location', '', PARAM_TEXT);
    $data->attendees        = optional_param('attendees', '', PARAM_TEXT);
    $data->agendaitems      = optional_param('agendaitems', '', PARAM_TEXT);
    $data->decisions        = optional_param('decisions', '', PARAM_TEXT);
    $data->actionitems      = optional_param('actionitems', '', PARAM_TEXT);
    $data->complianceitems  = optional_param('complianceitems', '', PARAM_TEXT);
    $data->timemodified     = time();

    if ($dbman->table_exists('local_rtocompliance_minutes')) {
        if ($id && $record) {
            $data->id = $id;
            $DB->update_record('local_rtocompliance_minutes', $data);
            local_rtocompliance_log_action('update', 'governance', $id, ['meeting' => $data->meetingtitle]);
        } else {
            $data->timecreated = time();
            $DB->insert_record('local_rtocompliance_minutes', $data);
            local_rtocompliance_log_action('create', 'governance', 0, ['meeting' => $data->meetingtitle]);
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']),
        'Meeting minutes saved.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    if ($dbman->table_exists('local_rtocompliance_minutes')) {
        $DB->delete_records('local_rtocompliance_minutes', ['id' => $id]);
    }
    redirect(
        new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']),
        'Record deleted.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('governance', 'local_rtocompliance'), null, null, 'governance');

echo html_writer::start_div('compliance-container');
echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', $id ? 'Edit Meeting Minutes' : 'Add Meeting Minutes');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']),
    'Back',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

$f = $record ?: new stdClass();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out_omit_querystring(),
    'style'  => 'max-width: 780px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Meeting Title *', ['for' => 'meetingtitle', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'meetingtitle', 'id' => 'meetingtitle',
    'value' => s($f->meetingtitle ?? ''),
    'class' => 'form-control', 'required' => 'required',
    'placeholder' => 'e.g. Board Meeting March 2026 / Quality Management Meeting Q1 2026',
]);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 12px;']);

$typeOptions = ['board' => 'Board Meeting', 'management' => 'Management Meeting', 'quality' => 'Quality Meeting', 'staff' => 'Staff Meeting', 'other' => 'Other'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Meeting Type *', ['for' => 'meetingtype', 'class' => 'form-label']);
echo html_writer::select($typeOptions, 'meetingtype', $f->meetingtype ?? 'board', null, ['id' => 'meetingtype', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Date *', ['for' => 'meetingdate', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'meetingdate', 'id' => 'meetingdate',
    'value' => !empty($f->meetingdate) ? date('Y-m-d', $f->meetingdate) : '',
    'class' => 'form-control', 'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Location', ['for' => 'location', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'location', 'id' => 'location',
    'value' => s($f->location ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. Head Office / Video call',
]);
echo html_writer::end_div();

echo html_writer::end_div();

foreach ([
    ['attendees', 'Attendees', 'List names and roles of all attendees', 3],
    ['agendaitems', 'Agenda Items', 'List agenda items discussed at the meeting', 4],
    ['decisions', 'Decisions Made', 'Record all formal decisions made at the meeting', 4],
    ['actionitems', 'Action Items', 'Action items with responsible party and due date. E.g. "John Smith to update risk register by 15 April"', 4],
    ['complianceitems', 'Compliance / Regulatory Items (Standard 4.1 & 4.2 Evidence)', 'Record compliance topics discussed: risk review, financial monitoring, regulatory updates communicated to staff, ASQA standards updates, quality outcomes review, audit preparation. This section is key ASQA audit evidence.', 5],
] as [$name, $label, $placeholder, $rows]) {
    echo html_writer::start_div('form-group', ['style' => 'margin-top: 14px;']);
    echo html_writer::tag('label', $label, ['for' => $name, 'class' => 'form-label']);
    echo html_writer::tag('textarea', s($f->$name ?? ''), [
        'name' => $name, 'id' => $name, 'class' => 'form-control',
        'rows' => $rows, 'placeholder' => $placeholder,
    ]);
    echo html_writer::end_div();
}

echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 20px;']);
echo html_writer::tag('button', $id ? 'Update' : 'Save', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/rtocompliance/governance.php', ['tab' => 'minutes']), 'Cancel', ['class' => 'btn btn-secondary']);
if ($id) {
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/governance_minutes_edit.php', ['id' => $id, 'action' => 'delete', 'sesskey' => sesskey()]),
        'Delete',
        ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete these minutes?")']
    );
}
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
