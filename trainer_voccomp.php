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
 * RTO Compliance plugin — trainer_voccomp.php.
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
$saved = optional_param('saved', 0, PARAM_INT);
$deleted = optional_param('deleted', 0, PARAM_INT);

$trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $trainerid], '*', MUST_EXIST);
$traineruser = $DB->get_record('user', ['id' => $trainer->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');

$PAGE->set_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'id' => $id]);
$PAGE->set_title('Vocational Competency Register - ' . fullname($traineruser));
$PAGE->set_heading('Vocational Competency Register');
$PAGE->requires->css('/local/rtocompliance/styles.css');

$activitytypes = [
    '' => 'Select activity type...',
    'vocational_qualification' => 'Relevant vocational qualification held',
    'industry_employment' => 'Current or recent industry employment',
    'workplace_projects' => 'Workplace projects or consulting',
    'vocational_cpd' => 'Vocational CPD related to skills',
    'industry_licences' => 'Industry licenses / tickets',
    'professional_logbook' => 'Professional practice logbook',
    'training_delivery' => 'Application of skills in training delivery',
    'supervisor_work' => 'Supervision / mentoring in industry',
    'other' => 'Other vocational evidence',
];

$evidencetypes = [
    '' => 'Select evidence type...',
    'resume_cv' => 'Resume / CV',
    'statement_service' => 'Statement of service / employer letter',
    'logbook' => 'Logbook / activity log',
    'licence_card' => 'Licence / registration card',
    'cpd_record' => 'CPD record / certificate',
    'qualification_cert' => 'Qualification certificate / transcript',
    'position_description' => 'Position description / job spec',
    'industry_reference' => 'Industry reference / testimonial',
    'project_portfolio' => 'Project portfolio / samples',
    'payslips' => 'Payslips / invoices',
    'other' => 'Other documentation',
];

$tablename = 'local_rtocompliance_trainer_voccomp';
$tableexists = $DB->get_manager()->table_exists($tablename);

if (!$tableexists) {
    $PAGE->add_body_class("path-local-rtocompliance");
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header('Vocational Competency Register', 'Edit Trainer', '/local/rtocompliance/trainer_edit.php?id=' . $trainerid, 'trainers');
    echo html_writer::div(
        '<strong>Database Upgrade Required</strong><br>The vocational competency register table has not been created yet. ' .
        'Please upgrade the plugin to the latest version (v3.7.41+) to enable this feature.<br><br>' .
        '<strong>Upgrade Instructions:</strong><br>' .
        '1. Download and install the latest RTO Compliance plugin<br>' .
        '2. Go to Site Administration → Notifications to run the database upgrade<br>' .
        '3. Return to this page after the upgrade completes',
        'alert alert-warning'
    );
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/trainer_edit.php', ['id' => $trainerid]),
        'Back to Trainer Details',
        ['class' => 'btn btn-secondary', 'style' => 'margin-top: 15px;']
    );
    echo $OUTPUT->footer();
    return;
}

if ($delete && $id && confirm_sesskey()) {
    $activity = $DB->get_record($tablename, ['id' => $id, 'trainerid' => $trainerid]);
    if ($activity) {
        $DB->delete_records($tablename, ['id' => $id]);
        
        $log = new stdClass();
        $log->action = 'delete';
        $log->component = 'trainer_voccomp';
        $log->itemid = $id;
        $log->userid = $USER->id;
        $log->targetuserid = $trainer->userid;
        $log->details = json_encode(['activity_title' => $activity->title, 'type' => $activity->activitytype]);
        $log->ipaddress = getremoteaddr();
        $log->timecreated = time();
        $DB->insert_record('local_rtocompliance_log', $log);
    }
    // FIX-VOCCOMP-AUTOSYNC (v4.4.64): keep vocationalcompetencydate on the trainer
    // record in sync with the most recent activity startdate after delete.
    $latestDate = $DB->get_field_sql(
        'SELECT MAX(startdate) FROM {local_rtocompliance_trainer_voccomp} WHERE trainerid = ?',
        [$trainerid]
    );
    if (!empty($latestDate)) {
        $DB->set_field('local_rtocompliance_trainers', 'vocationalcompetencydate', (int)$latestDate, ['id' => $trainerid]);
    }

    redirect(new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'deleted' => 1]));
}

class trainer_voccomp_form extends moodleform {
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

        $mform->addElement('header', 'activityheader', 'Vocational Competency Activity');

        $mform->addElement('select', 'activitytype', 'Activity Type', $activitytypes);
        $mform->addRule('activitytype', 'Required', 'required', null, 'client');
        
        $mform->addElement('text', 'title', 'Activity Title / Context', ['size' => 50, 'placeholder' => 'e.g. Owner/operator - small business consultancy']);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', 'Required', 'required', null, 'client');
        
        $mform->addElement('text', 'qualification', 'Related Qualification(s)', ['size' => 50, 'placeholder' => 'e.g. BSB40120, BSB50120']);
        $mform->setType('qualification', PARAM_TEXT);
        $mform->addHelpButton('qualification', 'voccomp_qualification', 'local_rtocompliance');
        
        $mform->addElement('text', 'organisation', 'Organisation/Employer', ['size' => 50]);
        $mform->setType('organisation', PARAM_TEXT);

        $mform->addElement('header', 'datesheader', 'Activity Dates');

        $mform->addElement('date_selector', 'startdate', 'Start Date', ['optional' => true]);
        
        $mform->addElement('advcheckbox', 'ongoing', 'Still Ongoing?', 'This activity is currently ongoing');
        
        $mform->addElement('date_selector', 'enddate', 'End Date', ['optional' => true]);
        $mform->hideIf('enddate', 'ongoing', 'checked');
        
        $mform->addElement('text', 'totalhours', 'Total Hours (if applicable)', ['size' => 5]);
        $mform->setType('totalhours', PARAM_INT);

        $mform->addElement('header', 'detailsheader', 'Activity Details');

        // FIX-RTO-TESTER-FEEDBACK-MAY1 #5: add an "AI Generate" button next to
        // the Description of Vocational Practice textarea.  The button calls
        // ajax.php?action=ai_voccomp_description and pipes the seed fields
        // (activity title, qualification, organisation, dates) so the backend
        // can produce a tailored draft the trainer can edit.
        $mform->addElement('textarea', 'description', 'Description of Vocational Practice', [
            'rows'        => 5,
            'cols'        => 60,
            'placeholder' => 'Describe what vocational skills were applied or maintained through this activity and how they relate to the qualification being delivered...',
        ]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addElement('static', 'voccompaihelp', '',
            '<div class="rtoc-ai-box">' .
            // FIX-MAY4-AI-BUTTON-STYLE (v4.4.44): btn-outline-primary rendered as a
            // hollow/ghost button that appeared visually broken inside the rtoc-ai-box
            // div when the form was loaded in edit mode.  Changed to btn-primary to
            // match the solid AI buttons used throughout the rest of the plugin.
            '<button type="button" id="rtoc-ai-voccomp-desc" class="btn btn-sm btn-primary" title="Draft the description with AI from the activity title, qualification, organisation and dates above">' .
            '<i class="fa fa-magic" aria-hidden="true"></i> AI Generate Description' .
            '</button>' .
            '<span id="rtoc-ai-voccomp-desc-status" class="rtoc-ai-status"></span>' .
            '<small class="rtoc-ai-hint d-block mt-1 text-muted">Drafts a description from the activity title, qualification, organisation and dates above.  You can edit the result before saving.</small>' .
            '</div>');

        $mform->addElement('header', 'evidenceheader', 'Evidence');
        
        $mform->addElement('select', 'evidencetype', 'Evidence Type', $evidencetypes);
        
        $mform->addElement('filepicker', 'evidencefile', 'Upload Evidence', null, [
            'maxbytes' => 10485760,
            'accepted_types' => ['*'],
        ]);
        // FIX-MAY4-FILETYPE-HINT (v4.4.44): same as tas_consultation.php fix —
        // addElement('static') wrapped the text in fitem/felement divs that render
        // as a bordered box.  addElement('html') injects the text directly.
        $mform->addElement('html', '<p class="text-muted" style="margin:2px 0 8px;font-size:0.82rem">Accepted file types: PDF, Word (.doc, .docx), images (.jpg, .png) and Excel (.xls, .xlsx) — max 10 MB</p>');

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
    $activity = $DB->get_record($tablename, ['id' => $id, 'trainerid' => $trainerid], '*', MUST_EXIST);
}

$form = new trainer_voccomp_form(null, [
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
        'qualification' => $activity->qualification,
        'organisation' => $activity->organisation,
        'startdate' => $activity->startdate,
        'enddate' => $activity->enddate,
        'ongoing' => $activity->ongoing,
        'totalhours' => $activity->totalhours,
        'description' => $activity->description,
        'evidencetype' => $activity->evidencetype,
        'notes' => $activity->notes,
    ];
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid]));
} elseif ($data = $form->get_data()) {
    $record = new stdClass();
    $record->trainerid = $trainerid;
    $record->activitytype = $data->activitytype;
    $record->title = $data->title;
    $record->qualification = $data->qualification ?? '';
    $record->organisation = $data->organisation ?? '';
    $record->description = $data->description ?? '';
    $record->startdate = $data->startdate ?? 0;
    $record->ongoing = !empty($data->ongoing) ? 1 : 0;
    $record->enddate = $record->ongoing ? 0 : ($data->enddate ?? 0);
    $record->totalhours = $data->totalhours ?? 0;
    $record->evidencetype = $data->evidencetype ?? '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = time();
    
    // Close session before file operations to prevent "session mutated after close" warning.
    // File storage API may call write_close() internally; we must not write to $SESSION after this.
    \core\session\manager::write_close();

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
        $DB->update_record($tablename, $record);
        $logaction = 'update';
    } else {
        $record->timecreated = time();
        $record->id = $DB->insert_record($tablename, $record);
        $logaction = 'create';
    }

    // EVIDENCE-PERSIST (v5.9.415): persist the uploaded evidence from the transient
    // draft area into the permanent, pluginfile-served 'trainer_voccomp_evidence'
    // filearea (its own area, so its record ids can't collide with trainer_currency's
    // in a shared area). file_save_draft_area_files() was previously never called, so
    // the file was lost on draft cleanup.
    if (!empty($evidenceitemid)) {
        file_save_draft_area_files($evidenceitemid, context_system::instance()->id,
            'local_rtocompliance', 'trainer_voccomp_evidence', $record->id, ['maxfiles' => 1]);
        $DB->set_field($tablename, 'evidencefileid', $record->id, ['id' => $record->id]);
    }

    $log = new stdClass();
    $log->action = $logaction;
    $log->component = 'trainer_voccomp';
    $log->itemid = $record->id;
    $log->userid = $USER->id;
    $log->targetuserid = $trainer->userid;
    $log->details = json_encode(['activity_title' => $record->title, 'type' => $record->activitytype]);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    // FIX-VOCCOMP-AUTOSYNC (v4.4.64): keep vocationalcompetencydate on the trainer
    // record in sync with the most recent activity startdate after save.
    // session was already closed above (write_close) so use direct DB call only.
    $latestDate = $DB->get_field_sql(
        'SELECT MAX(startdate) FROM {local_rtocompliance_trainer_voccomp} WHERE trainerid = ?',
        [$trainerid]
    );
    if (!empty($latestDate)) {
        $DB->set_field('local_rtocompliance_trainers', 'vocationalcompetencydate', (int)$latestDate, ['id' => $trainerid]);
    }

    // Use URL param for success message — avoids writing to $SESSION after write_close().
    redirect(new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'saved' => 1]));
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Vocational Competency Register', 'Edit Trainer', '/local/rtocompliance/trainer_edit.php?id=' . $trainerid, 'trainers');
echo local_rtocompliance_page_banner('Vocational Competency Register');

echo html_writer::start_div('', ['style' => 'max-width: 1000px; margin: 0 auto; padding: 20px;']);

echo html_writer::tag('p', 'Trainer: ' . html_writer::tag('strong', fullname($traineruser)));

if ($saved) {
    echo $OUTPUT->notification('Vocational competency activity saved.', 'success');
}
if ($deleted) {
    echo $OUTPUT->notification('Vocational competency activity deleted.', 'success');
}

if ($action === 'add' || $id) {
    echo html_writer::tag('h3', $id ? 'Edit Vocational Competency Activity' : 'Add Vocational Competency Activity');
    
    echo html_writer::start_div('alert alert-info', ['style' => 'margin-bottom: 20px;']);
    echo '<strong>ASQA Standards 2025 - Vocational Competency (Standard 3.2)</strong><br>';
    echo 'Trainers must demonstrate vocational competency in the training product being delivered. Record all activities that demonstrate your current skills and knowledge in the vocational area, including employment, qualifications, projects, logbooks, and CPD activities.';
    echo html_writer::end_div();
    
    $form->display();
} else {
    echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;']);
    echo html_writer::tag('h3', 'Vocational Competency Activities', ['style' => 'margin: 0;']);
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'action' => 'add']),
        'Add Vocational Competency Activity',
        ['class' => 'btn btn-primary', 'title' => 'Record a new vocational competency activity for this trainer']
    );
    echo html_writer::end_div();
    
    echo html_writer::start_div('alert alert-info', ['style' => 'margin-bottom: 20px;']);
    echo '<strong>Why record multiple activities?</strong><br>';
    echo 'ASQA Standards 2025 require trainers to demonstrate vocational competency through documented evidence. Recording multiple activities with evidence provides stronger audit trails showing ongoing competency in the qualification being delivered. Include qualifications, employment, consulting, logbooks, and CPD that demonstrate your vocational skills.';
    echo html_writer::end_div();

    $activities = $DB->get_records($tablename, ['trainerid' => $trainerid], 'ongoing DESC, startdate DESC');

    if ($activities) {
        // BUG-MAY1-AUDIT #4 (v4.2.44): tester asked for the trainer voc-comp
        // activities to be shown as a "simple list without boxes" to save
        // vertical space.  Replaced the heavy <table class="generaltable">
        // with a flat stacked <ul.rtoc-vc-list> driven by CSS in styles.css.
        // Each row shows the title prominently, type / qualification / period /
        // status / evidence as a small meta line, and edit/delete on the right.
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

            $statusText = $act->ongoing ? 'Ongoing' : 'Completed';
            $statusColor = $act->ongoing ? '#15803d' : '#475569';
            $evidenceText = !empty($act->evidencefilename) ? 'Evidence on file' : 'No evidence attached';
            $evidenceColor = !empty($act->evidencefilename) ? '#0e7490' : '#9ca3af';

            $editLink = html_writer::link(
                new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'id' => $act->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Edit this vocational competency activity']
            );
            $deleteLink = html_writer::link(
                new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainerid, 'id' => $act->id, 'delete' => 1, 'sesskey' => sesskey()]),
                'Delete',
                ['class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete this vocational competency activity', 'onclick' => "return confirm('Delete this vocational competency activity?');"]
            );

            echo '<li class="rtoc-vc-list-item">';
            echo '<div class="rtoc-vc-title">' . s($act->title) . '</div>';
            echo '<div class="rtoc-vc-actions">' . $editLink . ' ' . $deleteLink . '</div>';
            echo '<div class="rtoc-vc-meta">';
            echo '<span>' . s($typeLabel) . '</span>';
            if ($act->qualification) {
                echo '<code>' . s($act->qualification) . '</code>';
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
        echo '<strong>Vocational Competency Summary:</strong> ';
        echo count($activities) . ' activities recorded, ';
        echo $ongoingCount . ' ongoing, ';
        echo $evidenceCount . ' with evidence uploaded.';
        if ($evidenceCount < count($activities)) {
            echo '<br><em>Consider uploading evidence for all activities to strengthen your audit documentation.</em>';
        }
        echo html_writer::end_div();
        
    } else {
        echo html_writer::div(
            html_writer::tag('p', 'No vocational competency activities have been recorded for this trainer.') .
            html_writer::tag('p', 'Click "Add Vocational Competency Activity" to start recording how this trainer demonstrates vocational competency in the qualifications they deliver.'),
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

// FIX-RTO-TESTER-FEEDBACK-MAY1 #5 (v4.2.42): wire the "AI Generate Description"
// button.  Posts seed fields to ajax.php?action=ai_draft_text and writes the
// returned text into the textarea.
echo html_writer::script('
(function () {
    var btn = document.getElementById("rtoc-ai-voccomp-desc");
    if (!btn) return;
    var status = document.getElementById("rtoc-ai-voccomp-desc-status");
    var target = document.getElementById("id_description");
    if (!target) { if (status) { status.textContent = "Error: textarea not found."; status.classList.add("is-error"); } return; }
    var ajax   = "' . $CFG->wwwroot . '/local/rtocompliance/ajax.php";

    function val(id) { var el = document.getElementById(id); return el ? (el.value || "") : ""; }
    function txtOf(sel) {
        var el = document.querySelector(sel);
        if (!el) return "";
        if (el.tagName === "SELECT") {
            var opt = el.options[el.selectedIndex];
            return opt ? opt.text : "";
        }
        return el.value || "";
    }
    function dateText(name) {
        // date_selector renders id_<name>_day / _month / _year hidden fields + visible selects.
        var d = document.querySelector("[name=\\"" + name + "[day]\\"]");
        var m = document.querySelector("[name=\\"" + name + "[month]\\"]");
        var y = document.querySelector("[name=\\"" + name + "[year]\\"]");
        if (!d || !m || !y) return "";
        return d.value + "/" + m.value + "/" + y.value;
    }

    btn.addEventListener("click", function () {
        if (target && target.value && target.value.length > 30) {
            if (!confirm("This will replace the existing description.  Continue?")) return;
        }
        btn.disabled = true;
        status.textContent = "Generating...";
        status.classList.remove("is-error");

        var fd = new FormData();
        fd.append("action", "ai_draft_text");
        fd.append("contexttype", "voccomp_description");
        fd.append("sesskey", M.cfg.sesskey);
        fd.append("seed[activitytype]",  txtOf("[name=activitytype]"));
        fd.append("seed[title]",         val("id_title"));
        fd.append("seed[qualification]", val("id_qualification"));
        fd.append("seed[organisation]",  val("id_organisation"));
        fd.append("seed[startdate]",     dateText("startdate"));
        fd.append("seed[enddate]",       dateText("enddate"));
        fd.append("seed[totalhours]",    val("id_totalhours"));

        fetch(ajax, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) {
                var st = r.status;
                return r.text().then(function (raw) {
                    try {
                        var j = JSON.parse(raw);
                        j.__httpStatus = st;
                        return j;
                    } catch (e) {
                        return { success: false, error: "Bad response (HTTP " + st + "): " + raw.substring(0, 120), __httpStatus: st };
                    }
                });
            })
            .then(function (j) {
                btn.disabled = false;
                if (j && j.success && j.text) {
                    target.value = j.text;
                    target.dispatchEvent(new Event("input", { bubbles: true }));
                    status.textContent = "Draft inserted - please review and edit before saving.";
                } else {
                    var msg = (j && j.error) || ("AI request failed (HTTP " + (j && j.__httpStatus || "?") + ")");
                    status.textContent = "Error: " + msg;
                    status.classList.add("is-error");
                }
            })
            .catch(function (e) {
                btn.disabled = false;
                status.textContent = "Network error: " + e.message;
                status.classList.add("is-error");
            });
    });
})();
');

echo $OUTPUT->footer();
