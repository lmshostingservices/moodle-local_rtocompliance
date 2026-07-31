<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\improvement_form;

admin_externalpage_setup('local_rtocompliance_complaints');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$sourcetype = optional_param('sourcetype', '', PARAM_ALPHA);
$sourceid = optional_param('sourceid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/improvement_edit.php', ['id' => $id]));

$improvement = null;
if ($id) {
    $improvement = $DB->get_record('local_rtocompliance_improvements', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_improvement', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('continuous_improvement', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('edit_improvement', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('new_improvement', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('continuous_improvement', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('new_improvement', 'local_rtocompliance'));
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_improvements', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        get_string('improvement_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new improvement_form(null, ['improvement' => $improvement]);

if ($improvement) {
    $formdata = clone $improvement;
    $form->set_data($formdata);
} else if ($sourcetype && $sourceid) {
    $formdata = new stdClass();
    $formdata->sourcetype = $sourcetype;
    $formdata->sourceid = $sourceid;
    $formdata->dateidentified = time();
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/complaints.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->title = $data->title;
    $record->description = $data->description;
    $record->sourcetype = $data->sourcetype;
    // Set sourceid based on linked selection
    if ($data->sourcetype === 'complaint' && !empty($data->linkedcomplaintid)) {
        $record->sourceid = $data->linkedcomplaintid;
    } elseif ($data->sourcetype === 'validation' && !empty($data->linkedvalidationid)) {
        $record->sourceid = $data->linkedvalidationid;
    } else {
        $record->sourceid = $data->sourceid ?? null;
    }
    $record->category = $data->category;
    $record->priority = $data->priority;
    $record->status = $data->status;
    $record->dateidentified = $data->dateidentified;
    $record->targetdate = $data->targetdate ?? null;
    $record->completiondate = $data->completiondate ?? null;
    $record->actionplan = $data->actionplan ?? '';
    $record->outcome = $data->outcome ?? '';
    $record->effectivenessverified = $data->effectivenessverified;
    $record->verificationdate = $data->effectivenessverified ? ($data->verificationdate ?? null) : null;
    $record->verificationmethod = $data->effectivenessverified ? ($data->verificationmethod ?? '') : '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_improvements', $record);
        $message = get_string('improvement_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $record->id = $DB->insert_record('local_rtocompliance_improvements', $record);
        $message = get_string('improvement_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? get_string('edit_improvement', 'local_rtocompliance') : get_string('new_improvement', 'local_rtocompliance'), get_string('complaints_appeals', 'local_rtocompliance'), '/local/rtocompliance/complaints.php', 'complaints');
echo $OUTPUT->heading($id ? get_string('edit_improvement', 'local_rtocompliance') : get_string('new_improvement', 'local_rtocompliance'));

$form->display();

echo $OUTPUT->footer();
