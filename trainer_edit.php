<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_trainers');
$context = context_system::instance();
require_capability('local/rtocompliance:managetrainers', $context);

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/trainer_edit.php', ['id' => $id]);

$trainer = null;
if ($id) {
    $trainer = $DB->get_record('local_rtocompliance_trainers', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_trainer', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('edit_trainer', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('add_trainer', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('add_trainer', 'local_rtocompliance'));
}


if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_trainers', ['id' => $id]);

    $log = new stdClass();
    $log->action = 'delete';
    $log->component = 'trainers';
    $log->itemid = $id;
    $log->userid = $USER->id;
    $log->targetuserid = $trainer->userid;
    $traineruser = $DB->get_record('user', ['id' => $trainer->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
    $log->details = json_encode(['trainer_name' => fullname($traineruser)]);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    redirect(
        new moodle_url('/local/rtocompliance/trainers.php'),
        get_string('trainer_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

class trainer_edit_form extends moodleform {
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $trainer = $this->_customdata['trainer'] ?? null;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'general', get_string('trainer_name', 'local_rtocompliance'));

        $users = $DB->get_records_sql(
            "SELECT id, firstname, lastname, email, firstnamephonetic, lastnamephonetic, middlename, alternatename FROM {user} 
             WHERE deleted = 0 AND suspended = 0 AND id > 1
             ORDER BY lastname, firstname",
            [],
            0,
            500
        );

        $useroptions = ['' => 'Select a user...'];
        foreach ($users as $user) {
            $useroptions[$user->id] = fullname($user) . ' (' . $user->email . ')';
        }

        if ($trainer) {
            $traineruser = $DB->get_record('user', ['id' => $trainer->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
            $mform->addElement('static', 'username', get_string('trainer_name', 'local_rtocompliance'),
                fullname($traineruser));
            $mform->addElement('hidden', 'userid');
            $mform->setType('userid', PARAM_INT);
        } else {
            $mform->addElement('select', 'userid', get_string('trainer_name', 'local_rtocompliance'), $useroptions);
            $mform->addRule('userid', null, 'required', null, 'client');
        }

        $mform->addElement('header', 'credentials', get_string('trainer_taecredential', 'local_rtocompliance'));
        $mform->addHelpButton('credentials', 'trainers', 'local_rtocompliance');

        $taeoptions = [
            '' => 'Select TAE Credential...',
            'TAE40110' => 'TAE40110 - Certificate IV in Training and Assessment (accepted, no additional units required)',
            'TAE40116' => 'TAE40116 - Certificate IV in Training and Assessment',
            'TAE40122' => 'TAE40122 - Certificate IV in Training and Assessment (current)',
            'TAE50116' => 'TAE50116 - Diploma of Vocational Education and Training',
            'TAE50122' => 'TAE50122 - Diploma of Vocational Education and Training (current)',
            'TAE50216' => 'TAE50216 - Diploma of Training Design and Development',
            'TAESS00021' => 'TAESS00021 - Assessor skill set (under direction only)',
            'TAESS00024' => 'TAESS00024 - Trainer skill set (under direction only)',
            'Working Towards' => 'Working Towards TAE (requires supervision)',
            'Other' => 'Other TAE qualification',
        ];
        $mform->addElement('select', 'taecredential', get_string('trainer_taecredential', 'local_rtocompliance'), $taeoptions);
        $mform->addHelpButton('taecredential', 'taecredential_help_long', 'local_rtocompliance');

        $mform->addElement('date_selector', 'taedateachieved', 'Date Achieved', ['optional' => true]);
        $mform->addHelpButton('taedateachieved', 'taedateachieved', 'local_rtocompliance');

        $mform->addElement('date_selector', 'taeexpirydate', 'TAE Expiry Date (leave blank if no expiry)', ['optional' => true]);
        $mform->addElement('static', 'taeexpiryhelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;"><strong>Important:</strong> TAE qualifications typically do NOT expire. Leave this field blank for "Current forever" status. Only set an expiry date if there is a specific expiry requirement.<br><br>
            <strong>Status Calculation:</strong><br>
            - No expiry date = <span style="color: green;">Current</span> (indefinitely)<br>
            - Expiry date in future = <span style="color: green;">Current</span><br>
            - Expiry within 30 days = <span style="color: orange;">Expiring</span><br>
            - Expiry in past = <span style="color: red;">Expired</span></div>');

        $credentialroles = [
            '1A' => '1A - Full TAE, vocational qualification, current industry (independent trainer/assessor)',
            '1B' => '1B - Full TAE, industry expert partnership (trainer only)',
            '1C' => '1C - Working towards TAE, vocational qualification (supervised)',
            '1D' => '1D - Working towards TAE, industry expert partnership (supervised)',
            '1E' => '1E - Secondary teaching qualification holder',
            '2A' => '2A - Industry expert, no TAE, paired with TAE holder for training',
            '2B' => '2B - Industry expert assisting assessment under direction',
            '2C' => '2C - Industry expert, assessment judgement only',
            '3A' => '3A - Validator with TAE qualification',
            '3B' => '3B - Industry expert validator',
        ];
        // FIX-RTO-TESTER-FEEDBACK-MAY1 #2/3/4: replaced the addGroup('<br>'...)
        // checkbox stack (which produced a boxed list with uneven spacing because
        // each <br> was rendered as a separate visual line different from the
        // form-row label height) with a clean .rtoc-checkbox-list container.
        // Each checkbox + label is its own <div> with consistent 8px row spacing,
        // hanging indent for wrapped labels, no borders/background.
        // v4.2.57 #2/#3/#4: separator changed from broken-HTML
        // `</div><div class="rtoc-checkbox-list-row">` (which closed the
        // .felement mid-stream and caused the first row to render inside
        // a box while every following row rendered outside it, giving the
        // misaligned look reported) to a clean `<br>` so all rows
        // flow naturally as a single plain vertical list inside .felement.
        // CSS in styles.css (#fgroup_id_credentialrolesgroup .felement)
        // strips background + border so there is no box at all — this is
        // the "plain list, no boxes" requested in item #4.
        $rolegroup = [];
        foreach ($credentialroles as $code => $label) {
            $rolegroup[] = $mform->createElement('advcheckbox', "role_{$code}", '', $label, ['class' => 'rtoc-checkbox-list-item'], ['0', $code]);
        }
        $mform->addGroup($rolegroup, 'credentialrolesgroup', get_string('credential_role', 'local_rtocompliance'),
            '<br>', false);
        $mform->addHelpButton('credentialrolesgroup', 'credential_role', 'local_rtocompliance');
        
        $mform->addElement('static', 'roleshelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">You can select multiple roles if the trainer operates in different capacities. Most trainers are 1A (fully credentialed). Roles 1C, 1D, 2A, 2B require documented supervision.</div>');

        $mform->addElement('static', 'asqalink', '', 
            '<a href="https://www.asqa.gov.au/how-we-regulate/revised-standards-rtos/practice-guides/practice-guide-credential-policy" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">View ASQA Credential Policy Guide</a>');

        $mform->addElement('header', 'vocational', 'Vocational Qualifications');

        $mform->addElement('static', 'vocqualhelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;"><strong>Important:</strong> This section is for vocational qualifications (e.g., Certificate III, IV, Diploma in the industry area) - NOT teaching qualifications like a Bachelor of Education. These demonstrate industry expertise.<br><br>
            <strong>Examples of vocational qualifications:</strong><br>
            - Certificate III in Individual Support (CHC33021)<br>
            - Certificate IV in Fitness (SIS40221)<br>
            - Diploma of Business (BSB50120)<br>
            - Diploma of Work Health and Safety (BSB51319)<br><br>
            You can search for qualifications at <a href="https://training.gov.au" target="_blank">training.gov.au</a></div>');

        $mform->addElement('textarea', 'vocationalqualifications', 'Vocational Qualifications (from TGA)',
            ['rows' => 4, 'cols' => 60, 'placeholder' => 'BSB50420 - Diploma of Leadership and Management (2022)
CHC50121 - Diploma of Early Childhood Education and Care (2021)
SIS40221 - Certificate IV in Fitness (2023)']);
        $mform->setType('vocationalqualifications', PARAM_TEXT);
        $mform->addHelpButton('vocationalqualifications', 'vocationalqualifications_help_long', 'local_rtocompliance');

        $mform->addElement('static', 'voccomphelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;"><strong>Evidence of Vocational Competency and Currency</strong><br>
            Select all evidence types that demonstrate this trainer\'s vocational competency relevant to the qualification being delivered and assessed. This aligns with Standard 3.3(2) of the Standards for RTOs 2025.</div>');

        $vocationalEvidenceTypes = [
            'qual_level' => 'Relevant vocational qualification(s) at least to the level being delivered and assessed',
            'industry_employment' => 'Current industry employment or recent work experience in the relevant field',
            'resume_cv' => 'Detailed resume/CV outlining industry roles and responsibilities',
            'position_descriptions' => 'Position descriptions aligned to industry job roles',
            'employer_references' => 'Employer references or statements of service confirming industry experience',
            'industry_licences' => 'Industry licences, registrations, or tickets (where required)',
            'professional_membership' => 'Membership of relevant industry associations or professional bodies',
            'industry_pd' => 'Participation in industry-based professional development (workshops, seminars, technical training)',
            'workplace_projects' => 'Engagement in workplace projects or consulting activities relevant to the qualification',
            'industry_consultation' => 'Industry consultation records (meetings, site visits, advisory input)',
            'currency_updates' => 'Evidence of maintaining currency (procedure updates, equipment changes, standards revisions)',
            'vocational_hours' => 'Log of vocational practice hours (where applicable)',
            'cpd_vocational' => 'CPD records related to vocational skills',
            'current_practices' => 'Evidence of applying current industry practices in training and assessment materials',
        ];
        
        // FIX-RTO-TESTER-FEEDBACK-MAY1 #2/3/4: see credentialrolesgroup above.
        $evidencegroup = [];
        foreach ($vocationalEvidenceTypes as $key => $label) {
            $evidencegroup[] = $mform->createElement('advcheckbox', "vocevidence_{$key}", '', $label, ['class' => 'rtoc-checkbox-list-item'], ['0', $key]);
        }
        // v4.2.57 #2/#3/#4: see credentialrolesgroup above for separator rationale.
        $mform->addGroup($evidencegroup, 'vocationalcompetencygroup', 'Vocational Competency Evidence (select all that apply)',
            '<br>', false);
        $mform->addHelpButton('vocationalcompetencygroup', 'vocationalcompetency_evidence', 'local_rtocompliance');

        $mform->addElement('textarea', 'vocationalcompetencynotes', 'Additional Vocational Competency Notes',
            ['rows' => 2, 'cols' => 60, 'placeholder' => 'Any additional notes about vocational competency...']);
        $mform->setType('vocationalcompetencynotes', PARAM_TEXT);

        $mform->addElement('date_selector', 'vocationalcompetencydate', 'Competency Verified Date', ['optional' => true]);

        $mform->addElement('static', 'voccompactivities', 'Vocational Competency Activities', 
            '<div id="voccomp-activities-summary"></div>' .
            '<a href="#" id="manage-voccomp-btn" class="btn btn-primary" style="margin-top: 10px;">Manage Vocational Competency Activities</a>' .
            '<p class="text-muted" style="margin-top: 8px;"><small>Save this trainer first, then click the button to record multiple vocational competency activities with evidence uploads.</small></p>');

        $mform->addElement('header', 'industry', get_string('trainer_industrycurrency', 'local_rtocompliance'));

        $mform->addElement('text', 'industryexperienceyears', 'Industry Experience (Years)',
            ['size' => 5, 'placeholder' => 'e.g. 8']);
        $mform->setType('industryexperienceyears', PARAM_INT);
        $mform->addHelpButton('industryexperienceyears', 'industryexperienceyears', 'local_rtocompliance');
        $mform->addElement('static', 'industryexphelp', '',
            '<small class="text-muted">Enter the number of years of industry experience <strong>directly relevant to the qualifications being delivered and assessed</strong>. This must be supported by resume/CV evidence.</small>');

        $mform->addElement('static', 'currencyexplain', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;"><strong>Industry Currency Activities</strong><br>
            ASQA Standards 2025 require trainers to demonstrate current industry skills through multiple activities. Use the Industry Currency Activities page to record employment, consulting, professional memberships, conferences, and other ongoing industry connections.<br><br>
            <strong>Record multiple activities</strong> to provide comprehensive evidence of your industry currency.<br><br>
            <a href="https://www.asqa.gov.au/how-we-regulate/revised-standards-rtos/practice-guides/practice-guide-credential-policy" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">View ASQA Trainer Credential Policy</a></div>');
        
        $mform->addElement('static', 'currencyactivities', 'Currency Activities', 
            '<div id="currency-activities-summary"></div>' .
            '<a href="#" id="manage-currency-btn" class="btn btn-primary" style="margin-top: 10px;">Manage Industry Currency Activities</a>' .
            '<p class="text-muted" style="margin-top: 8px;"><small>Save this trainer first, then click the button to manage multiple currency activities.</small></p>');

        $mform->addElement('header', 'llnvet', 'LLN Capability &amp; VET Currency');

        $mform->addElement('select', 'llncapability', 'LLN Capability', [
            ''          => 'Not recorded',
            'ACSF 1-2'  => 'ACSF Level 1–2 (can support entry-level learners)',
            'ACSF 3'    => 'ACSF Level 3 (can support most VET learners)',
            'ACSF 4-5'  => 'ACSF Level 4–5 (can support diploma/advanced diploma learners)',
            'qualified' => 'Formally qualified LLN practitioner',
            'trained'   => 'LLN awareness training completed',
            'na'        => 'Not applicable to this trainer\'s scope',
        ]);
        $mform->setType('llncapability', PARAM_TEXT);
        $mform->addHelpButton('llncapability', 'llncapability', 'local_rtocompliance');

        $mform->addElement('date_selector', 'vetcurrencydate', 'VET Currency Date (Date trainer last taught/assessed in VET)', ['optional' => true]);
        $mform->addHelpButton('vetcurrencydate', 'vetcurrencydate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'industrycurrencydate', 'Industry Currency Date (Date trainer last worked in their industry)', ['optional' => true]);
        $mform->addHelpButton('industrycurrencydate', 'industrycurrencydate', 'local_rtocompliance');

        $mform->addElement('header', 'resume', 'Resume/CV Upload');
        
        $mform->addElement('static', 'resumehelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">Upload your resume/CV that documents your <strong>industry experience</strong> (not just teaching/training experience). This should show your vocational work history in the areas you train and assess.</div>');
        
        $mform->addElement('filepicker', 'resumefile', 'Resume/CV Document', null, [
            'maxbytes' => 10485760,
            'accepted_types' => ['.pdf', '.doc', '.docx'],
        ]);
        $mform->addHelpButton('resumefile', 'resumefile', 'local_rtocompliance');

        $mform->addElement('header', 'cpd', get_string('trainer_cpdhours', 'local_rtocompliance'));

        $mform->addElement('text', 'cpdhours', get_string('trainer_cpdhours', 'local_rtocompliance'), ['size' => 5]);
        $mform->setType('cpdhours', PARAM_INT);
        $mform->setDefault('cpdhours', 0);
        $mform->addHelpButton('cpdhours', 'cpdhours_help_long', 'local_rtocompliance');

        $mform->addElement('date_selector', 'nextreviewdate', get_string('trainer_expirydate', 'local_rtocompliance'), ['optional' => true]);

        $mform->addElement('header', 'signoff', get_string('manager_signoff', 'local_rtocompliance'));
        
        $mform->addElement('static', 'signoffhelp', '', 
            '<div class="alert alert-warning" style="margin-bottom: 12px;">RTO Manager verification that this trainer\'s credentials have been verified against original documents and they are approved to deliver/assess mapped training products.</div>');

        $mform->addElement('advcheckbox', 'managersignoff', get_string('manager_signoff', 'local_rtocompliance'), 
            'I confirm credentials have been verified and this trainer is approved');
        $mform->addHelpButton('managersignoff', 'manager_signoff', 'local_rtocompliance');

        $mform->addElement('header', 'wwcc', 'Working With Children Check (WWCC / Blue Card)');
        $mform->addElement('static', 'wwcchelp', '',
            '<div class="alert alert-info" style="margin-bottom:12px;">Record your trainer\'s current WWCC, Blue Card, Working with Vulnerable People card, or state equivalent. Mark N/A if the trainer does not work with people under 18.</div>');
        $mform->addElement('select', 'wwccstatus', 'WWCC Status', [
            '' => 'Not recorded',
            'current'  => 'Current',
            'pending'  => 'Pending / Applied',
            'expired'  => 'Expired',
            'na'       => 'Not Applicable (N/A)',
        ]);
        $mform->setType('wwccstatus', PARAM_ALPHA);
        $mform->addElement('text', 'wwccnumber', 'WWCC / Card Number', ['size' => 30, 'maxlength' => 30]);
        $mform->setType('wwccnumber', PARAM_TEXT);
        $mform->addElement('select', 'wwccstate', 'Issuing State', [
            '' => 'Select state',
            '01' => 'NSW', '02' => 'VIC', '03' => 'QLD',
            '04' => 'SA', '05' => 'WA', '06' => 'TAS',
            '07' => 'NT', '08' => 'ACT',
        ]);
        $mform->setType('wwccstate', PARAM_ALPHANUMEXT);
        $mform->addElement('date_selector', 'wwccexpiry', 'WWCC Expiry Date', ['optional' => true]);

        $mform->addElement('header', 'policecheck', 'National Police Certificate');
        $mform->addElement('static', 'pchelp', '',
            '<div class="alert alert-info" style="margin-bottom:12px;">A current National Police Certificate is required for trainers who work with vulnerable people or in certain industry settings. Certificates typically expire after 3 years.</div>');
        $mform->addElement('select', 'policecheckstatus', 'Police Check Status', [
            '' => 'Not recorded',
            'current'  => 'Current',
            'pending'  => 'Pending',
            'expired'  => 'Expired',
            'na'       => 'Not Applicable (N/A)',
        ]);
        $mform->setType('policecheckstatus', PARAM_ALPHA);
        $mform->addElement('text', 'policechecknumber', 'Police Check Reference Number', ['size' => 50, 'maxlength' => 50]);
        $mform->setType('policechecknumber', PARAM_TEXT);
        $mform->addElement('date_selector', 'policecheckdate', 'Date of Police Check', ['optional' => true]);
        $mform->addElement('date_selector', 'policecheckexpiry', 'Police Check Expiry Date', ['optional' => true]);

        $mform->addElement('header', 'deliveryscope', 'Delivery Scope');
        $mform->addElement('static', 'scopehelp', '',
            '<div class="alert alert-info" style="margin-bottom:12px;">Document the specific qualifications, skill sets, and units this trainer is approved to deliver and/or assess. This forms part of your ASQA T&amp;A Register evidence.</div>');
        $mform->addElement('textarea', 'scopenotes', 'Approved Delivery Scope', ['rows' => 4, 'cols' => 60,
            'placeholder' => 'e.g. BSB50420 Diploma of Leadership and Management — all units; TAE40122 Certificate IV in Training and Assessment — units TAEDES401, TAEDES402, TAEASS401']);
        $mform->setType('scopenotes', PARAM_TEXT);

        $mform->addElement('textarea', 'notes', 'Notes', ['rows' => 3, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, $trainer ? get_string('savechanges') : get_string('add'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['id']) && empty($data['userid'])) {
            $errors['userid'] = 'Please select a user';
        }

        if (empty($data['id']) && !empty($data['userid'])) {
            $existing = $DB->get_record('local_rtocompliance_trainers', ['userid' => $data['userid']]);
            if ($existing) {
                $errors['userid'] = 'This user is already a registered trainer';
            }
        }

        return $errors;
    }
}

$form = new trainer_edit_form(null, ['trainer' => $trainer]);

if ($trainer) {
    $formdata = (object)[
        'id' => $trainer->id,
        'userid' => $trainer->userid,
        'taecredential' => $trainer->taecredential,
        'taedateachieved' => $trainer->taedateachieved,
        'taeexpirydate' => $trainer->taeexpirydate ?? null,
        'vocationalqualifications' => $trainer->vocationalqualifications,
        'vocationalcompetencynotes' => $trainer->vocationalcompetency ?? '',
        'vocationalcompetencydate' => $trainer->vocationalcompetencydate,
        'cpdhours' => $trainer->cpdhours,
        'nextreviewdate' => $trainer->nextreviewdate,
        'managersignoff' => $trainer->managersignoff ?? 0,
        'notes' => $trainer->notes,
        'resumefilename' => $trainer->resumefilename ?? '',
        'wwccstatus' => $trainer->wwccstatus ?? '',
        'wwccnumber' => $trainer->wwccnumber ?? '',
        'wwccstate'  => $trainer->wwccstate ?? '',
        'wwccexpiry' => $trainer->wwccexpiry ?? 0,
        'policecheckstatus' => $trainer->policecheckstatus ?? '',
        'policechecknumber' => $trainer->policechecknumber ?? '',
        'policecheckdate'   => $trainer->policecheckdate ?? 0,
        'policecheckexpiry' => $trainer->policecheckexpiry ?? 0,
        'scopenotes' => $trainer->scopenotes ?? '',
        'industryexperienceyears' => $trainer->industryexperienceyears ?? '',
        'llncapability' => $trainer->llncapability ?? '',
        'vetcurrencydate' => $trainer->vetcurrencydate ?? 0,
        'industrycurrencydate' => $trainer->industrycurrencydate ?? 0,
    ];
    
    // Handle multi-select credential roles
    $selectedroles = !empty($trainer->credentialrole) ? explode(',', $trainer->credentialrole) : [];
    foreach ($selectedroles as $role) {
        $role = trim($role);
        if (!empty($role)) {
            $formdata->{"role_{$role}"} = $role;
        }
    }
    
    // Handle vocational evidence checkboxes
    $vocevidence = !empty($trainer->vocationalevidence) ? explode(',', $trainer->vocationalevidence) : [];
    foreach ($vocevidence as $evidence) {
        $evidence = trim($evidence);
        if (!empty($evidence)) {
            $formdata->{"vocevidence_{$evidence}"} = $evidence;
        }
    }
    
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/trainers.php'));
} elseif ($data = $form->get_data()) {
    $record = new stdClass();
    $record->userid = $data->userid;
    $record->taecredential = $data->taecredential ?? '';
    $record->taedateachieved = $data->taedateachieved ?? 0;
    $record->taeexpirydate = !empty($data->taeexpirydate) ? (int)$data->taeexpirydate : null;
    
    // Collect selected credential roles from checkboxes
    $rolekeys = ['1A', '1B', '1C', '1D', '1E', '2A', '2B', '2C', '3A', '3B'];
    $selectedroles = [];
    foreach ($rolekeys as $rolekey) {
        $fieldname = "role_{$rolekey}";
        if (!empty($data->$fieldname)) {
            $selectedroles[] = $rolekey;
        }
    }
    $record->credentialrole = implode(',', $selectedroles);
    
    $record->vocationalqualifications = $data->vocationalqualifications ?? '';
    
    // Collect selected vocational evidence from checkboxes
    $evidencekeys = ['qual_level', 'industry_employment', 'resume_cv', 'position_descriptions', 
                     'employer_references', 'industry_licences', 'professional_membership', 
                     'industry_pd', 'workplace_projects', 'industry_consultation', 
                     'currency_updates', 'vocational_hours', 'cpd_vocational', 'current_practices'];
    $selectedevidence = [];
    foreach ($evidencekeys as $ekey) {
        $fieldname = "vocevidence_{$ekey}";
        if (!empty($data->$fieldname)) {
            $selectedevidence[] = $ekey;
        }
    }
    $record->vocationalevidence = implode(',', $selectedevidence);
    $record->vocationalcompetency = $data->vocationalcompetencynotes ?? '';
    $record->vocationalcompetencydate = $data->vocationalcompetencydate ?? 0;
    $record->cpdhours = $data->cpdhours ?? 0;
    $record->nextreviewdate = $data->nextreviewdate ?? 0;
    $record->notes = $data->notes ?? '';
    $record->wwccstatus = $data->wwccstatus ?? '';
    $record->wwccnumber = $data->wwccnumber ?? '';
    $record->wwccstate  = $data->wwccstate ?? '';
    $record->wwccexpiry = !empty($data->wwccexpiry) ? $data->wwccexpiry : null;
    $record->policecheckstatus = $data->policecheckstatus ?? '';
    $record->policechecknumber = $data->policechecknumber ?? '';
    $record->policecheckdate   = !empty($data->policecheckdate) ? $data->policecheckdate : null;
    $record->policecheckexpiry = !empty($data->policecheckexpiry) ? $data->policecheckexpiry : null;
    $record->scopenotes = $data->scopenotes ?? '';
    $record->industryexperienceyears = !empty($data->industryexperienceyears) ? (int)$data->industryexperienceyears : null;
    $record->llncapability = $data->llncapability ?? '';
    $record->vetcurrencydate = !empty($data->vetcurrencydate) ? (int)$data->vetcurrencydate : null;
    $record->industrycurrencydate = !empty($data->industrycurrencydate) ? (int)$data->industrycurrencydate : null;
    $record->timemodified = time();
    
    // Handle resume file upload
    $resumeitemid = file_get_submitted_draft_itemid('resumefile');
    if ($resumeitemid) {
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $resumeitemid, 'id', false);
        if ($files) {
            $file = reset($files);
            $record->resumefilename = $file->get_filename();
            $record->resumefileid = $resumeitemid;
        }
    }
    
    if (!empty($data->managersignoff)) {
        $record->managersignoff = 1;
        if (empty($trainer->managersignoff)) {
            $record->managersignoffby = $USER->id;
            $record->managersignoffdate = time();
        }
    } else {
        $record->managersignoff = 0;
    }

    if ($record->nextreviewdate && $record->nextreviewdate < time()) {
        $record->status = 'expired';
    } elseif ($record->nextreviewdate && $record->nextreviewdate < strtotime('+30 days')) {
        $record->status = 'expiring';
    } else {
        $record->status = 'current';
    }

    // Set fullname from user record
    $traineruser = $DB->get_record('user', ['id' => $record->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
    if ($traineruser) {
        $record->fullname = fullname($traineruser);
    }

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_trainers', $record);
        $action = 'update';
    } else {
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_rtocompliance_trainers', $record);
        $action = 'create';
    }

    $log = new stdClass();
    $log->action = $action;
    $log->component = 'trainers';
    $log->itemid = $record->id;
    $log->userid = $USER->id;
    $log->targetuserid = $record->userid;
    $log->details = json_encode(['taecredential' => $record->taecredential, 'status' => $record->status]);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    redirect(
        new moodle_url('/local/rtocompliance/trainers.php'),
        get_string('trainer_saved', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
$PAGE->requires->js('/local/rtocompliance/js/ai_suggest.js', false);
echo $OUTPUT->header();

// Use local_aiconfig (Central Config plugin) if installed — same priority chain as external.php.
$_rtoc_aicfglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($_rtoc_aicfglib)) {
    require_once($_rtoc_aicfglib);
}
$_rtoc_apikey = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
echo html_writer::tag('div', '', [
    'id'            => 'rtoc-ai-config',
    'data-api-key'  => $_rtoc_apikey,
    'data-api-base' => $_rtoc_apibase,
    'style'         => 'display:none',
    'aria-hidden'   => 'true',
]);

echo local_rtocompliance_render_nav_header($id ? get_string('edit_trainer', 'local_rtocompliance') : get_string('add_trainer', 'local_rtocompliance'), get_string('trainers', 'local_rtocompliance'), '/local/rtocompliance/trainers.php', 'trainers');

echo html_writer::start_div('', ['style' => 'max-width: 800px; margin: 0 auto; padding: 20px;']);

if ($trainer) {
    echo html_writer::start_div('', ['style' => 'margin-bottom: 20px;']);
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/trainer_edit.php', ['id' => $id, 'delete' => 1, 'sesskey' => sesskey()]),
        'Delete Trainer',
        ['class' => 'btn btn-sm btn-outline-danger', 'onclick' => "return confirm('Are you sure you want to delete this trainer record?');"]
    );
    echo html_writer::end_div();
    
    $currencyActivities = $DB->get_records('local_rtocompliance_trainer_currency', ['trainerid' => $trainer->id], 'ongoing DESC, startdate DESC');
    $ongoingCount = 0;
    $totalCount = count($currencyActivities);
    foreach ($currencyActivities as $act) {
        if ($act->ongoing) $ongoingCount++;
    }
    
    $currencySummaryHtml = '';
    if ($totalCount > 0) {
        $currencySummaryHtml = '<div class="alert alert-success" style="margin-bottom: 10px;">';
        $currencySummaryHtml .= '<strong>' . $totalCount . ' currency activities</strong> recorded';
        if ($ongoingCount > 0) {
            $currencySummaryHtml .= ' (' . $ongoingCount . ' ongoing)';
        }
        $currencySummaryHtml .= '</div>';
        foreach ($currencyActivities as $act) {
            $badge = $act->ongoing ? '<span class="badge badge-success" style="background:#28a745;color:#fff;">Ongoing</span>' : '';
            $currencySummaryHtml .= '<div style="padding:4px 0;border-bottom:1px solid #eee;">' . $badge . ' <strong>' . s($act->title) . '</strong>';
            if ($act->organisation) {
                $currencySummaryHtml .= ' at ' . s($act->organisation);
            }
            $currencySummaryHtml .= '</div>';
        }
    } else {
        $currencySummaryHtml = '<div class="alert alert-warning">No industry currency activities recorded yet. Click below to add activities.</div>';
    }
    
    $voccompActivities = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_trainer_voccomp')) {
        $voccompActivities = $DB->get_records('local_rtocompliance_trainer_voccomp', ['trainerid' => $trainer->id], 'ongoing DESC, startdate DESC');
    }
    $voccompOngoing = 0;
    $voccompTotal = count($voccompActivities);
    foreach ($voccompActivities as $act) {
        if ($act->ongoing) $voccompOngoing++;
    }
    
    $voccompSummaryHtml = '';
    if ($voccompTotal > 0) {
        $voccompSummaryHtml = '<div class="alert alert-success" style="margin-bottom: 10px;">';
        $voccompSummaryHtml .= '<strong>' . $voccompTotal . ' vocational competency activities</strong> recorded';
        if ($voccompOngoing > 0) {
            $voccompSummaryHtml .= ' (' . $voccompOngoing . ' ongoing)';
        }
        $voccompSummaryHtml .= '</div>';
        foreach ($voccompActivities as $act) {
            $badge = $act->ongoing ? '<span class="badge badge-success" style="background:#28a745;color:#fff;">Ongoing</span>' : '';
            $voccompSummaryHtml .= '<div style="padding:4px 0;border-bottom:1px solid #eee;">' . $badge . ' <strong>' . s($act->title) . '</strong>';
            if ($act->qualification) {
                $voccompSummaryHtml .= ' <code style="font-size:0.85em;">' . s($act->qualification) . '</code>';
            }
            $voccompSummaryHtml .= '</div>';
        }
    } else {
        $voccompSummaryHtml = '<div class="alert alert-warning">No vocational competency activities recorded yet. Click below to add activities with evidence.</div>';
    }
}

$form->display();

if ($trainer) {
    $currencyUrl = (new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $trainer->id]))->out(false);
    $voccompUrl = (new moodle_url('/local/rtocompliance/trainer_voccomp.php', ['trainerid' => $trainer->id]))->out(false);
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var summaryDiv = document.getElementById("currency-activities-summary");
        var manageBtn = document.getElementById("manage-currency-btn");
        if (summaryDiv) {
            summaryDiv.innerHTML = ' . json_encode($currencySummaryHtml) . ';
        }
        if (manageBtn) {
            manageBtn.href = ' . json_encode($currencyUrl) . ';
            manageBtn.querySelector("small")?.remove();
        }
        var helpText = manageBtn?.nextElementSibling;
        if (helpText && helpText.tagName === "P") {
            helpText.style.display = "none";
        }
        
        var voccompSummaryDiv = document.getElementById("voccomp-activities-summary");
        var voccompManageBtn = document.getElementById("manage-voccomp-btn");
        if (voccompSummaryDiv) {
            voccompSummaryDiv.innerHTML = ' . json_encode($voccompSummaryHtml) . ';
        }
        if (voccompManageBtn) {
            voccompManageBtn.href = ' . json_encode($voccompUrl) . ';
            voccompManageBtn.querySelector("small")?.remove();
        }
        var voccompHelpText = voccompManageBtn?.nextElementSibling;
        if (voccompHelpText && voccompHelpText.tagName === "P") {
            voccompHelpText.style.display = "none";
        }
    });
    </script>';
}

echo html_writer::end_div();

echo $OUTPUT->footer();
