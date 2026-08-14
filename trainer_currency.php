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
 * RTO Compliance plugin — trainer_currency.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_trainers');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managetrainers', $context);

$trainerid = required_param('trainerid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $trainerid], '*', MUST_EXIST);
$traineruser = $DB->get_record('user', ['id' => $trainer->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');

$PAGE->set_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid, 'id' => $id]);
$PAGE->set_title('Industry Currency Activities - ' . fullname($traineruser));
$PAGE->set_heading('Industry Currency Activities');

$activitytypes = [
    '' => 'Select activity type...',
    'employment' => 'Ongoing industry employment',
    'consulting' => 'Industry consulting/contracting',
    'projects' => 'Industry projects or secondments',
    'professional_membership' => 'Professional association membership',
    'conferences' => 'Industry conferences and events',
    'research' => 'Industry research and publications',
    'mentoring' => 'Industry mentoring or advisory roles',
    'cpd' => 'Industry-specific professional development',
    'work_experience' => 'Structured work experience in industry',
    'other' => 'Other (describe in notes)',
];

$evidencetypes = [
    '' => 'Select evidence type...',
    'employment_contract' => 'Employment contract',
    'letter_employer' => 'Letter from employer',
    'payslips' => 'Payslips/payment records',
    'abn_invoices' => 'ABN registration/invoices',
    'membership_card' => 'Professional membership card',
    'conference_cert' => 'Conference attendance certificate',
    'publication' => 'Published article/research',
    'log_hours' => 'Log of hours/activities',
    'photos' => 'Photos/evidence of activities',
    'reference' => 'Reference/testimonial',
    'other' => 'Other documentation',
];

if ($delete && $id && confirm_sesskey()) {
    $activity = $DB->get_record('local_rtocompliance_trainer_currency', ['id' => $id, 'trainerid' => $trainerid]);
    if ($activity) {
        $DB->delete_records('local_rtocompliance_trainer_currency', ['id' => $id]);
        
        $log = new stdClass();
        $log->action = 'delete';
        $log->component = 'trainer_currency';
        $log->itemid = $id;
        $log->userid = $USER->id;
        $log->targetuserid = $trainer->userid;
        $log->details = json_encode(['activity_title' => $activity->title, 'type' => $activity->activitytype]);
        $log->ipaddress = getremoteaddr();
        $log->timecreated = time();
        $DB->insert_record('local_rtocompliance_log', $log);
    }
    // FIX-CURRENCY-AUTOSYNC (v4.4.64): keep industrycurrencydate on the trainer
    // record in sync with the most recent activity startdate after delete.
    $latestDate = $DB->get_field_sql(
        'SELECT MAX(startdate) FROM {local_rtocompliance_trainer_currency} WHERE trainerid = ?',
        [$trainerid]
    );
    if (!empty($latestDate)) {
        $DB->set_field('local_rtocompliance_trainers', 'industrycurrencydate', (int)$latestDate, ['id' => $trainerid]);
    }

    redirect(
        new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid]),
        'Currency activity deleted',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

class trainer_currency_form extends moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $activity = $this->_customdata['activity'] ?? null;
        $activitytypes = $this->_customdata['activitytypes'];
        $evidencetypes = $this->_customdata['evidencetypes'];
        $trainerid = $this->_customdata['trainerid'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        
        $mform->addElement('hidden', 'trainerid', $trainerid);
        $mform->setType('trainerid', PARAM_INT);

        $mform->addElement('header', 'activityheader', 'Industry Currency Activity');

        $mform->addElement('select', 'activitytype', 'Activity Type', $activitytypes);
        $mform->addRule('activitytype', 'Required', 'required', null, 'client');
        
        $mform->addElement('text', 'title', 'Activity Title/Role', ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', 'Required', 'required', null, 'client');
        $mform->addHelpButton('title', 'currency_title', 'local_rtocompliance');
        
        $mform->addElement('text', 'organisation', 'Organisation/Employer', ['size' => 50]);
        $mform->setType('organisation', PARAM_TEXT);

        $mform->addElement('header', 'datesheader', 'Activity Dates');

        $mform->addElement('date_selector', 'startdate', 'Start Date', ['optional' => true]);
        
        $mform->addElement('advcheckbox', 'ongoing', 'Still Ongoing?', 'This activity is currently ongoing');
        
        $mform->addElement('date_selector', 'enddate', 'End Date', ['optional' => true]);
        $mform->hideIf('enddate', 'ongoing', 'checked');
        
        $mform->addElement('text', 'hoursperweek', 'Hours per Week (approx)', ['size' => 5]);
        $mform->setType('hoursperweek', PARAM_INT);

        $mform->addElement('header', 'detailsheader', 'Activity Details');
        
        $mform->addElement('textarea', 'description', 'Description of Activities', 
            ['rows' => 4, 'cols' => 60, 'placeholder' => 'Describe the industry activities undertaken and how they maintain your current skills...']);
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('header', 'evidenceheader', 'Evidence');
        
        $mform->addElement('select', 'evidencetype', 'Evidence Type', $evidencetypes);
        
        $mform->addElement('filepicker', 'evidencefile', 'Upload Evidence', null, [
            'maxbytes' => 10485760,
            'accepted_types' => ['.pdf', '.doc', '.docx', '.jpg', '.jpeg', '.png'],
        ]);
        
        $mform->addElement('textarea', 'notes', 'Additional Notes', ['rows' => 2, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, $activity ? 'Update Activity' : 'Add Activity');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        if (empty($data['activitytype'])) {
            $errors['activitytype'] = 'Please select an activity type';
        }
        
        if (empty($data['title'])) {
            $errors['title'] = 'Please enter an activity title';
        }
        
        if (!empty($data['startdate']) && !empty($data['enddate']) && $data['enddate'] < $data['startdate']) {
            $errors['enddate'] = 'End date cannot be before start date';
        }
        
        return $errors;
    }
}

$activity = null;
if ($id) {
    $activity = $DB->get_record('local_rtocompliance_trainer_currency', ['id' => $id, 'trainerid' => $trainerid], '*', MUST_EXIST);
}

$form = new trainer_currency_form(null, [
    'activity' => $activity,
    'activitytypes' => $activitytypes,
    'evidencetypes' => $evidencetypes,
    'trainerid' => $trainerid,
]);

if ($activity) {
    $formdata = (object)[
        'id' => $activity->id,
        'trainerid' => $trainerid,
        'activitytype' => $activity->activitytype,
        'title' => $activity->title,
        'organisation' => $activity->organisation,
        'startdate' => $activity->startdate,
        'enddate' => $activity->enddate,
        'ongoing' => $activity->ongoing,
        'hoursperweek' => $activity->hoursperweek,
        'description' => $activity->description,
        'evidencetype' => $activity->evidencetype,
        'notes' => $activity->notes,
    ];
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid]));
} elseif ($data = $form->get_data()) {
    $record = new stdClass();
    $record->trainerid = $trainerid;
    $record->activitytype = $data->activitytype;
    $record->title = $data->title;
    $record->organisation = $data->organisation ?? '';
    $record->description = $data->description ?? '';
    $record->startdate = $data->startdate ?? 0;
    $record->ongoing = !empty($data->ongoing) ? 1 : 0;
    $record->enddate = $record->ongoing ? 0 : ($data->enddate ?? 0);
    $record->hoursperweek = $data->hoursperweek ?? 0;
    $record->evidencetype = $data->evidencetype ?? '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = time();
    
    $evidenceitemid = file_get_submitted_draft_itemid('evidencefile');
    if ($evidenceitemid) {
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $evidenceitemid, 'id', false);
        if ($files) {
            $file = reset($files);
            $record->evidencefilename = $file->get_filename();
            $record->evidencefileid = $evidenceitemid;
        }
    }

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_trainer_currency', $record);
        $logaction = 'update';
    } else {
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_rtocompliance_trainer_currency', $record);
        $logaction = 'create';
    }

    // EVIDENCE-PERSIST (v5.9.415): the uploaded evidence file was only ever read from
    // the USER'S DRAFT area — file_save_draft_area_files() was never called — so the
    // file was discarded when Moodle cleaned the draft area (~4 days) and the stored
    // evidencefileid (a draft itemid) pointed at nothing. Now persist the draft into
    // the permanent, pluginfile-served 'trainer_evidence' filearea keyed by the record
    // id, and store that record id as the durable evidencefileid.
    if (!empty($evidenceitemid)) {
        file_save_draft_area_files($evidenceitemid, context_system::instance()->id,
            'local_rtocompliance', 'trainer_evidence', $record->id, ['maxfiles' => 1]);
        $DB->set_field('local_rtocompliance_trainer_currency', 'evidencefileid', $record->id, ['id' => $record->id]);
    }

    $log = new stdClass();
    $log->action = $logaction;
    $log->component = 'trainer_currency';
    $log->itemid = $record->id;
    $log->userid = $USER->id;
    $log->targetuserid = $trainer->userid;
    $log->details = json_encode(['activity_title' => $record->title, 'type' => $record->activitytype]);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    // FIX-CURRENCY-AUTOSYNC (v4.4.64): keep industrycurrencydate on the trainer
    // record in sync with the most recent activity startdate after save.
    $latestDate = $DB->get_field_sql(
        'SELECT MAX(startdate) FROM {local_rtocompliance_trainer_currency} WHERE trainerid = ?',
        [$trainerid]
    );
    if (!empty($latestDate)) {
        $DB->set_field('local_rtocompliance_trainers', 'industrycurrencydate', (int)$latestDate, ['id' => $trainerid]);
    }

    redirect(
        new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid]),
        'Currency activity saved',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Industry Currency Activities', 'Edit Trainer', '/local/rtocompliance/trainer_edit.php?id=' . $trainerid, 'trainers');
echo local_rtocompliance_page_banner('Industry Currency Activities');

echo html_writer::start_div('', ['style' => 'max-width: 1000px; margin: 0 auto; padding: 20px;']);

echo html_writer::tag('p', 'Trainer: ' . html_writer::tag('strong', fullname($traineruser)));

if ($action === 'add' || $id) {
    echo html_writer::tag('h3', $id ? 'Edit Currency Activity' : 'Add Currency Activity');
    
    echo html_writer::start_div('alert alert-info', ['style' => 'margin-bottom: 20px;']);
    echo '<strong>ASQA Standards 2025 - Industry Currency</strong><br>';
    echo 'Trainers must demonstrate current industry skills and knowledge. Record all activities that show you stay connected to your industry, including ongoing employment, consulting work, professional memberships, conferences, and industry projects.';
    echo html_writer::end_div();
    
    $form->display();
} else {
    echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;']);
    echo html_writer::tag('h3', 'Industry Currency Activities', ['style' => 'margin: 0;']);
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid, 'action' => 'add']),
        'Add Currency Activity',
        ['class' => 'btn btn-primary', 'title' => 'Record a new industry currency activity for this trainer']
    );
    echo html_writer::end_div();
    
    echo html_writer::start_div('alert alert-info', ['style' => 'margin-bottom: 20px;']);
    echo '<strong>Why record multiple activities?</strong><br>';
    echo 'ASQA expects trainers to maintain industry currency through various means. Recording multiple activities provides stronger evidence of your ongoing connection to industry. Include employment, consulting, professional memberships, conferences, and any other activities that demonstrate current industry skills.';
    echo html_writer::end_div();

    $activities = $DB->get_records('local_rtocompliance_trainer_currency', ['trainerid' => $trainerid], 'ongoing DESC, startdate DESC');

    if ($activities) {
        // BUG-MAY1-AUDIT-PASS2 #4 (v4.2.45): tester wanted simple no-box
        // lists "throughout various stages of entry of data under Trainers
        // and Assessors", not just on the voc-comp screen.  Replaced the
        // generaltable here with the same flat <ul.rtoc-vc-list> pattern
        // already shipped in trainer_voccomp.php (styles.css owns the look).
        echo '<ul class="rtoc-vc-list">';

        foreach ($activities as $act) {
            $typeLabel = $activitytypes[$act->activitytype] ?? $act->activitytype;

            $period = '';
            if ($act->startdate) {
                $period = userdate($act->startdate, '%b %Y');
            }
            if ($act->ongoing) {
                $period .= ' - Ongoing';
            } elseif ($act->enddate) {
                $period .= ' - ' . userdate($act->enddate, '%b %Y');
            }

            $statusText  = $act->ongoing ? 'Ongoing' : 'Completed';
            $statusColor = $act->ongoing ? '#15803d' : '#475569';
            $evidenceText  = !empty($act->evidencefilename) ? 'Evidence on file' : 'No evidence attached';
            $evidenceColor = !empty($act->evidencefilename) ? '#0e7490' : '#9ca3af';

            $editLink = html_writer::link(
                new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid, 'id' => $act->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Edit this industry currency activity']
            );
            $deleteLink = html_writer::link(
                new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainerid, 'id' => $act->id, 'delete' => 1, 'sesskey' => sesskey()]),
                'Delete',
                ['class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete this industry currency activity', 'onclick' => "return confirm('Delete this currency activity?');"]
            );

            echo '<li class="rtoc-vc-list-item">';
            echo '<div class="rtoc-vc-title">' . s($act->title) . '</div>';
            echo '<div class="rtoc-vc-actions">' . $editLink . ' ' . $deleteLink . '</div>';
            echo '<div class="rtoc-vc-meta">';
            echo '<span>' . s($typeLabel) . '</span>';
            if (!empty($act->organisation)) {
                echo '<span>' . s($act->organisation) . '</span>';
            }
            if ($period) {
                echo '<span>' . s($period) . '</span>';
            }
            echo '<span style="color:' . $statusColor . ';font-weight:600;">' . s($statusText) . '</span>';
            echo '<span style="color:' . $evidenceColor . ';">' . s($evidenceText) . '</span>';
            echo '</div>';
            echo '</li>';
        }

        echo '</ul>';
        
        $ongoingCount = 0;
        $evidenceCount = 0;
        foreach ($activities as $act) {
            if ($act->ongoing) $ongoingCount++;
            if (!empty($act->evidencefilename)) $evidenceCount++;
        }
        
        echo html_writer::start_div('alert alert-' . ($ongoingCount > 0 ? 'success' : 'warning'), ['style' => 'margin-top: 20px;']);
        echo '<strong>Currency Summary:</strong> ';
        echo count($activities) . ' activities recorded, ';
        echo $ongoingCount . ' ongoing, ';
        echo $evidenceCount . ' with evidence uploaded.';
        if ($ongoingCount === 0) {
            echo '<br><em>Consider adding at least one ongoing activity to demonstrate current industry connection.</em>';
        }
        echo html_writer::end_div();
        
    } else {
        echo html_writer::div(
            html_writer::tag('p', 'No industry currency activities have been recorded for this trainer.') .
            html_writer::tag('p', 'Click "Add Currency Activity" to start recording how this trainer maintains their industry currency.'),
            'alert alert-warning'
        );
    }
    
    echo html_writer::start_div('', ['style' => 'margin-top: 20px;']);
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/trainer_edit.php', ['id' => $trainerid]),
        'Back to Trainer Details',
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo $OUTPUT->footer();
