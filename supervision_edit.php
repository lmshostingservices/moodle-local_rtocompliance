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
 * RTO Compliance plugin — supervision_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_supervision');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managetrainers', $context);

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$validate = optional_param('validate', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/supervision_edit.php', ['id' => $id]);

$supervision = null;
if ($id) {
    $supervision = $DB->get_record('local_rtocompliance_supervision', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_supervision', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('edit_supervision', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('add_supervision', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('add_supervision', 'local_rtocompliance'));
}


if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_supervision', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/supervision.php'),
        get_string('supervision_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($validate && $id && confirm_sesskey()) {
    $supervision->managervalidated = 1;
    $supervision->managervalidatedby = $USER->id;
    $supervision->managervalidateddate = time();
    $supervision->timemodified = time();
    $DB->update_record('local_rtocompliance_supervision', $supervision);
    redirect(
        new moodle_url('/local/rtocompliance/supervision_edit.php', ['id' => $id]),
        get_string('supervision_validated', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

class supervision_edit_form extends moodleform {
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $supervision = $this->_customdata['supervision'] ?? null;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'general', get_string('supervision_details', 'local_rtocompliance'));

        $trainers = $DB->get_records_sql(
            "SELECT t.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
             FROM {local_rtocompliance_trainers} t
             JOIN {user} u ON u.id = t.userid
             ORDER BY u.lastname, u.firstname"
        );

        $traineroptions = ['' => 'Select trainer...'];
        foreach ($trainers as $t) {
            $traineroptions[$t->id] = fullname($t);
        }

        // Std 3.2 / Credential Policy: a supervisor must be FULLY credentialled. Exclude trainers who
        // are Working Towards, have a blank TAE credential, or hold an expired TAE (taeexpirydate < now).
        $supervisors = $DB->get_records_sql(
            "SELECT t.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
             FROM {local_rtocompliance_trainers} t
             JOIN {user} u ON u.id = t.userid
             WHERE t.taecredential IS NOT NULL
               AND t.taecredential <> ''
               AND t.taecredential <> 'Working Towards'
               AND (t.taeexpirydate IS NULL OR t.taeexpirydate = 0 OR t.taeexpirydate >= :now)
             ORDER BY u.lastname, u.firstname",
            ['now' => time()]
        );

        $supervisoroptions = ['' => 'Select supervisor...'];
        foreach ($supervisors as $s) {
            $supervisoroptions[$s->id] = fullname($s);
        }

        $mform->addElement('select', 'trainerid', get_string('trainer_supervised', 'local_rtocompliance'), $traineroptions);
        $mform->addRule('trainerid', null, 'required', null, 'client');
        $mform->addHelpButton('trainerid', 'trainer_supervised', 'local_rtocompliance');

        $mform->addElement('static', 'supervisorcredhelp', '',
            '<div class="alert alert-info" style="margin-bottom: 12px;">Only fully credentialled trainers are listed as supervisors. Trainers who are Working Towards, have no TAE credential, or hold an expired TAE are excluded.</div>');

        $mform->addElement('select', 'supervisorid', get_string('supervisor', 'local_rtocompliance'), $supervisoroptions);
        $mform->addRule('supervisorid', null, 'required', null, 'client');
        $mform->addHelpButton('supervisorid', 'supervisor', 'local_rtocompliance');

        $mform->addElement('date_selector', 'supervisiondate', get_string('supervision_date', 'local_rtocompliance'));
        $mform->setDefault('supervisiondate', time());

        $supervisiontypes = [
            '' => 'Select type...',
            'observation' => 'Observation - Direct observation of training/assessment delivery',
            'feedback' => 'Feedback Session - Structured feedback on performance',
            'assessment_review' => 'Assessment Review - Review of assessment judgements',
            'qa_check' => 'QA Check - Quality assurance review',
            'mentoring' => 'Mentoring - Ongoing mentoring and guidance',
        ];
        $mform->addElement('select', 'supervisiontype', get_string('supervision_type', 'local_rtocompliance'), $supervisiontypes);
        $mform->addRule('supervisiontype', null, 'required', null, 'client');
        $mform->addHelpButton('supervisiontype', 'supervision_type', 'local_rtocompliance');

        $mform->addElement('text', 'qualificationcode', get_string('qualificationcode', 'local_rtocompliance'), ['size' => 20]);
        $mform->setType('qualificationcode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('qualificationcode', 'qualificationcode', 'local_rtocompliance');

        $mform->addElement('text', 'duration', get_string('duration_minutes', 'local_rtocompliance'), ['size' => 10]);
        $mform->setType('duration', PARAM_INT);
        $mform->addHelpButton('duration', 'duration_minutes', 'local_rtocompliance');

        $mform->addElement('header', 'activityheader', get_string('supervision_activities', 'local_rtocompliance'));

        $mform->addElement('textarea', 'activities', get_string('activities_description', 'local_rtocompliance'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('activities', PARAM_TEXT);
        $mform->addHelpButton('activities', 'activities_description', 'local_rtocompliance');

        $mform->addElement('textarea', 'feedback', get_string('feedback_provided', 'local_rtocompliance'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('feedback', PARAM_TEXT);
        $mform->addHelpButton('feedback', 'feedback_provided', 'local_rtocompliance');

        $mform->addElement('textarea', 'developmentneeds', get_string('development_needs', 'local_rtocompliance'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('developmentneeds', PARAM_TEXT);
        $mform->addHelpButton('developmentneeds', 'development_needs', 'local_rtocompliance');

        $mform->addElement('header', 'followupheader', get_string('follow_up_actions', 'local_rtocompliance'));

        $mform->addElement('textarea', 'actionitems', get_string('action_items', 'local_rtocompliance'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('actionitems', PARAM_TEXT);
        $mform->addHelpButton('actionitems', 'action_items', 'local_rtocompliance');

        $mform->addElement('date_selector', 'actionsduedate', get_string('actions_due_date', 'local_rtocompliance'), ['optional' => true]);

        $mform->addElement('advcheckbox', 'actionscompleted', get_string('actions_completed', 'local_rtocompliance'));

        $mform->addElement('advcheckbox', 'assessmentjudgementrestricted', get_string('assessment_judgement_restricted', 'local_rtocompliance'));
        $mform->addHelpButton('assessmentjudgementrestricted', 'assessment_judgement_restricted', 'local_rtocompliance');

        $mform->addElement('date_selector', 'nextsupervisiondate', get_string('next_supervision_date', 'local_rtocompliance'), ['optional' => true]);

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, $supervision ? get_string('savechanges') : get_string('add'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['trainerid'])) {
            $errors['trainerid'] = get_string('required');
        }

        if (empty($data['supervisorid'])) {
            $errors['supervisorid'] = get_string('required');
        }

        if (!empty($data['trainerid']) && !empty($data['supervisorid']) && $data['trainerid'] == $data['supervisorid']) {
            $errors['supervisorid'] = get_string('error_same_trainer_supervisor', 'local_rtocompliance');
        }

        // Std 3.2 / Credential Policy: reject any supervisor who is not fully credentialled, even if
        // an out-of-date value was submitted (e.g. the trainer became unqualified after page load).
        if (!empty($data['supervisorid'])) {
            $sup = $DB->get_record('local_rtocompliance_trainers',
                ['id' => $data['supervisorid']], 'id, taecredential, taeexpirydate');
            if (!$sup
                    || trim((string)$sup->taecredential) === ''
                    || $sup->taecredential === 'Working Towards'
                    || (!empty($sup->taeexpirydate) && $sup->taeexpirydate < time())) {
                $errors['supervisorid'] = 'Selected supervisor is not fully credentialled. A supervisor must hold a current TAE credential (not Working Towards, not blank, not expired).';
            }
        }

        return $errors;
    }
}

$form = new supervision_edit_form(null, ['supervision' => $supervision]);

if ($supervision) {
    $form->set_data($supervision);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/supervision.php'));
} elseif ($data = $form->get_data()) {
    $record = new stdClass();
    $record->trainerid = $data->trainerid;
    $record->supervisorid = $data->supervisorid;
    $record->supervisiondate = $data->supervisiondate;
    $record->supervisiontype = $data->supervisiontype;
    $record->qualificationcode = $data->qualificationcode ?? '';
    $record->duration = $data->duration ?? 0;
    $record->activities = $data->activities ?? '';
    $record->feedback = $data->feedback ?? '';
    $record->developmentneeds = $data->developmentneeds ?? '';
    $record->actionitems = $data->actionitems ?? '';
    $record->actionsduedate = $data->actionsduedate ?? 0;
    $record->actionscompleted = $data->actionscompleted ?? 0;
    $record->assessmentjudgementrestricted = $data->assessmentjudgementrestricted ?? 0;
    $record->nextsupervisiondate = $data->nextsupervisiondate ?? 0;
    $record->notes = $data->notes ?? '';
    $record->timemodified = time();

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_supervision', $record);
        $action = 'update';
    } else {
        $record->timecreated = time();
        $record->createdby = $USER->id;
        $record->managervalidated = 0;
        $record->id = $DB->insert_record('local_rtocompliance_supervision', $record);
        $action = 'create';
    }

    $log = new stdClass();
    $log->action = $action;
    $log->component = 'supervision';
    $log->itemid = $record->id;
    $log->userid = $USER->id;
    $log->details = json_encode(['type' => $record->supervisiontype, 'trainerid' => $record->trainerid]);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    redirect(
        new moodle_url('/local/rtocompliance/supervision.php'),
        get_string('supervision_saved', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? get_string('edit_supervision', 'local_rtocompliance') : get_string('add_supervision', 'local_rtocompliance'), get_string('supervision_log', 'local_rtocompliance'), '/local/rtocompliance/supervision.php', 'supervision');
echo local_rtocompliance_page_banner($id ? get_string('edit_supervision', 'local_rtocompliance') : get_string('add_supervision', 'local_rtocompliance'));

echo html_writer::start_div('', ['style' => 'max-width: 900px; margin: 0 auto; padding: 20px;']);

if ($supervision) {
    echo html_writer::start_div('', ['style' => 'margin-bottom: 20px; display: flex; gap: 8px;']);
    
    if (!$supervision->managervalidated) {
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/supervision_edit.php', ['id' => $id, 'validate' => 1, 'sesskey' => sesskey()]),
            'Validate as RTO Manager',
            ['class' => 'btn btn-success', 'onclick' => "return confirm('Validate this supervision log as RTO Manager?');"]
        );
    } else {
        $validator = $DB->get_record('user', ['id' => $supervision->managervalidatedby], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        echo html_writer::tag('span', 'Validated by ' . fullname($validator) . ' on ' . userdate($supervision->managervalidateddate, '%d %b %Y'), 
            ['class' => 'badge badge-success', 'style' => 'padding: 8px 16px;']);
    }
    
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/supervision_edit.php', ['id' => $id, 'delete' => 1, 'sesskey' => sesskey()]),
        'Delete',
        ['class' => 'btn btn-outline-danger', 'onclick' => "return confirm('Are you sure you want to delete this supervision log?');"]
    );
    echo html_writer::end_div();
}

$form->display();

echo html_writer::end_div();

echo $OUTPUT->footer();
