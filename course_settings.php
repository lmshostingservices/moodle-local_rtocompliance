<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/rtocompliance:manage', $context);

$PAGE->set_url(new moodle_url('/local/rtocompliance/course_settings.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('rtocompliance_settings', 'local_rtocompliance'));
$PAGE->set_heading($course->fullname);

$existing = $DB->get_record('local_rtocompliance_courses', ['courseid' => $courseid]);
$recordid = $existing ? $existing->id : 0;

class course_settings_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'recordid');
        $mform->setType('recordid', PARAM_INT);

        $mform->addElement('header', 'nationallyrecognisedheader', get_string('nationallyrecognised_header', 'local_rtocompliance'));

        $mform->addElement('advcheckbox', 'nationallyrecognised', get_string('nationallyrecognised', 'local_rtocompliance'),
            get_string('nationallyrecognised_desc', 'local_rtocompliance'));
        $mform->addHelpButton('nationallyrecognised', 'nationallyrecognised', 'local_rtocompliance');

        $mform->addElement('text', 'qualificationcode', get_string('qualificationcode', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 20]);
        $mform->setType('qualificationcode', PARAM_ALPHANUMEXT);
        $mform->hideIf('qualificationcode', 'nationallyrecognised', 'notchecked');
        $mform->addHelpButton('qualificationcode', 'qualificationcode', 'local_rtocompliance');

        $mform->addElement('text', 'qualificationname', get_string('qualificationname', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('qualificationname', PARAM_TEXT);
        $mform->hideIf('qualificationname', 'nationallyrecognised', 'notchecked');

        $mform->addElement('text', 'nominalhours', get_string('nominalhours', 'local_rtocompliance'), ['size' => 6, 'maxlength' => 5]);
        $mform->setType('nominalhours', PARAM_INT);
        $mform->hideIf('nominalhours', 'nationallyrecognised', 'notchecked');
        $mform->addHelpButton('nominalhours', 'nominalhours', 'local_rtocompliance');

        $mform->addElement('header', 'cricosheader', get_string('cricos_header', 'local_rtocompliance'));

        $mform->addElement('advcheckbox', 'cricosregistered', get_string('cricosregistered', 'local_rtocompliance'),
            get_string('cricosregistered_desc', 'local_rtocompliance'));
        $mform->hideIf('cricosregistered', 'nationallyrecognised', 'notchecked');

        $mform->addElement('text', 'cricoscode', get_string('cricoscode', 'local_rtocompliance'), ['size' => 15, 'maxlength' => 10]);
        $mform->setType('cricoscode', PARAM_ALPHANUMEXT);
        $mform->hideIf('cricoscode', 'cricosregistered', 'notchecked');
        $mform->addHelpButton('cricoscode', 'cricoscode', 'local_rtocompliance');

        $this->add_action_buttons();
    }
}

$form = new course_settings_form();

$formdata = $existing ? clone $existing : new stdClass();
$formdata->courseid = $courseid;
$formdata->recordid = $recordid;
if (!$existing) {
    $formdata->nationallyrecognised = 0;
    $formdata->cricosregistered = 0;
}
$form->set_data($formdata);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
} else if ($data = $form->get_data()) {
    $record = new stdClass();
    $record->courseid = $courseid;
    $record->nationallyrecognised = !empty($data->nationallyrecognised) ? 1 : 0;
    $record->qualificationcode = $data->qualificationcode ?? '';
    $record->qualificationname = $data->qualificationname ?? '';
    $record->nominalhours = !empty($data->nominalhours) ? (int)$data->nominalhours : null;
    $record->cricosregistered = !empty($data->cricosregistered) ? 1 : 0;
    $record->cricoscode = $data->cricoscode ?? '';
    $record->timemodified = time();

    if (!empty($data->recordid)) {
        $record->id = $data->recordid;
        $DB->update_record('local_rtocompliance_courses', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_rtocompliance_courses', $record);
    }

    redirect(
        new moodle_url('/course/view.php', ['id' => $courseid]),
        get_string('settingssaved', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('rtocompliance_settings', 'local_rtocompliance'));
echo $OUTPUT->heading(get_string('rtocompliance_settings', 'local_rtocompliance'));

echo html_writer::tag('p', get_string('rtocompliance_settings_desc', 'local_rtocompliance'), ['class' => 'text-muted mb-3']);

$form->display();

// v4.7.104 BULK-COURSE-CERTS — quick-action link to bulk cert generation for this course
echo html_writer::start_div('card mt-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', 'Certificate Actions', ['class' => 'card-title']);
echo html_writer::tag('p', 'Generate certificates for all students who have completed this course. The system automatically detects the correct certificate type based on the course qualification settings.', ['class' => 'text-muted']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/generate_course_certs.php', ['courseid' => $courseid]),
    'Generate Certificates for This Course',
    ['class' => 'btn btn-success']
);
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
