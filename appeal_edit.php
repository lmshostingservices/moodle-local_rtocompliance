<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\appeal_form;

admin_externalpage_setup('local_rtocompliance_complaints');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/appeal_edit.php', ['id' => $id]));

$appeal = null;
if ($id) {
    $appeal = $DB->get_record('local_rtocompliance_appeals', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_appeal', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('edit_appeal', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('new_appeal', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('new_appeal', 'local_rtocompliance'));
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_appeals', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        get_string('appeal_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new appeal_form(null, ['appeal' => $appeal]);

if ($appeal) {
    $formdata = clone $appeal;
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/complaints.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->complaintid = !empty($data->complaintid) ? $data->complaintid : null;
    $record->appealtype = $data->appealtype;
    $record->appellantname = $data->appellantname;
    $record->appellantemail = $data->appellantemail ?? '';
    $record->appellantphone = $data->appellantphone ?? '';
    $record->groundsforappeal = $data->groundsforappeal;
    $record->originaldecision = $data->originaldecision ?? '';
    $record->originaldecisiondate = $data->originaldecisiondate ?? null;
    $record->status = $data->status;
    $record->datelodged = $data->datelodged;
    $record->dateacknowledged = $data->dateacknowledged ?? null;
    $record->hearingdate = $data->hearingdate ?? null;
    $record->panelmembers = $data->panelmembers ?? '';
    $record->outcome = !empty($data->outcome) ? $data->outcome : null;
    $record->outcomereason = $data->outcomereason ?? '';
    $record->decisiondate = $data->decisiondate ?? null;
    $record->externalreviewoffered = $data->externalreviewoffered;
    $record->externalreviewtaken = $data->externalreviewoffered ? ($data->externalreviewtaken ?? 0) : 0;
    $record->externalreviewbody = $data->externalreviewoffered ? ($data->externalreviewbody ?? '') : '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_appeals', $record);
        $message = get_string('appeal_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $record->id = $DB->insert_record('local_rtocompliance_appeals', $record);
        $message = get_string('appeal_created', 'local_rtocompliance');
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
echo local_rtocompliance_render_nav_header($id ? get_string('edit_appeal', 'local_rtocompliance') : get_string('new_appeal', 'local_rtocompliance'), get_string('complaints_appeals', 'local_rtocompliance'), '/local/rtocompliance/complaints.php', 'complaints');
echo $OUTPUT->heading($id ? get_string('edit_appeal', 'local_rtocompliance') : get_string('new_appeal', 'local_rtocompliance'));

$form->display();

echo $OUTPUT->footer();
