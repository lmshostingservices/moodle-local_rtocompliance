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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_tas');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/tas_edit.php', ['id' => $id]));

$tas = null;
if ($id) {
    $tas = $DB->get_record('local_rtocompliance_tas', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit TAS: ' . $tas->qualificationcode);
    $PAGE->navbar->add(get_string('tas', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/tas.php'));
    $PAGE->navbar->add('Edit TAS');
} else {
    $PAGE->set_title('Create New TAS');
    $PAGE->navbar->add(get_string('tas', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/tas.php'));
    $PAGE->navbar->add('Create New TAS');
}

if ($delete && $id && confirm_sesskey()) {
    $consultRecords = $DB->get_records('local_rtocompliance_tas_consult', ['tasid' => $id], '', 'id');
    if ($consultRecords) {
        $fs = get_file_storage();
        foreach ($consultRecords as $cr) {
            $fs->delete_area_files($context->id, 'local_rtocompliance', 'consultation_evidence', $cr->id);
        }
    }
    $DB->delete_records('local_rtocompliance_tas', ['id' => $id]);
    $DB->delete_records('local_rtocompliance_tas_consult', ['tasid' => $id]);
    $DB->delete_records('local_rtocompliance_tas_trainers', ['tasid' => $id]);
    $DB->delete_records('local_rtocompliance_tas_resources', ['tasid' => $id]);
    $DB->delete_records('local_rtocompliance_tas_schedule', ['tasid' => $id]);
    $DB->delete_records('local_rtocompliance_tas_mapping', ['tasid' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/tas.php'),
        'TAS document deleted',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

class tas_form extends moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $tas = $this->_customdata['tas'] ?? null;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'section1', 'Section 1: RTO & Training Product Details');

        $mform->addElement('text', 'qualificationcode', 'Qualification Code', ['size' => 20, 'placeholder' => 'e.g. BSB50420']);
        $mform->setType('qualificationcode', PARAM_TEXT);
        $mform->addRule('qualificationcode', null, 'required', null, 'client');

        $mform->addElement('text', 'qualificationname', 'Qualification Name', ['size' => 80]);
        $mform->setType('qualificationname', PARAM_TEXT);
        $mform->addRule('qualificationname', null, 'required', null, 'client');

        $mform->addElement('text', 'traininggovlink', 'Training.gov.au Link', ['size' => 80, 'placeholder' => 'https://training.gov.au/Training/Details/...']);
        $mform->setType('traininggovlink', PARAM_URL);

        $mform->addElement('textarea', 'scopedetails', 'RTO Scope Details', ['rows' => 3, 'cols' => 80]);
        $mform->setType('scopedetails', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('scopedetails', 'scopedetails', 'local_rtocompliance');

        $mform->addElement('header', 'section2', 'Section 2: Target Learner Cohort & Entry Requirements');

        $mform->addElement('static', 'cohort_selector_helper', '',
'<div id="rtoc-cohort-helper" style="border:1px solid #dee2e6;border-radius:8px;padding:16px;background:#f0f6ff;margin-bottom:16px;">
<strong style="font-size:14px;">Smart Cohort &amp; Entry Requirements Builder</strong>
<p style="color:#555;font-size:13px;margin:6px 0 12px;">Select the qualification AQF level, tick applicable learner cohorts, then click <em>Apply to Section 2</em> to auto-fill the fields below.</p>
<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;">
  <div>
    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px;">AQF Qualification Level</label>
    <select id="rtoc-aqf-level" style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
      <option value="">-- Select level --</option>
      <option value="Cert I">Certificate I</option>
      <option value="Cert II">Certificate II</option>
      <option value="Cert III">Certificate III</option>
      <option value="Cert IV">Certificate IV</option>
      <option value="Diploma">Diploma</option>
      <option value="Advanced Diploma">Advanced Diploma</option>
    </select>
  </div>
  <div id="rtoc-school-info" style="display:none;font-size:12px;color:#555;background:#fff;border:1px solid #cce;padding:6px 10px;border-radius:4px;">
    School equivalence: <strong id="rtoc-school-eq"></strong>
  </div>
</div>
<div id="rtoc-cohort-grid" style="display:none;">
  <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Select Applicable Learner Cohorts</label>
  <div id="rtoc-cohort-checkboxes" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-bottom:10px;"></div>
  <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:8px 12px;font-size:12px;margin-bottom:10px;">
    <strong>Mature-Age Entry (4+ years experience):</strong> Workplace LLN assessment, reading/writing/numeracy tasks, digital literacy check.
    ACSF outcomes: Level 3 → Cert III/IV &bull; Level 4 → Diploma &bull; Level 2 → Foundation Skills required.
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button type="button" id="rtoc-apply-section2" class="btn btn-primary" style="font-size:13px;">Apply to Section 2</button>
    <button type="button" id="rtoc-clear-cohorts" class="btn btn-outline-secondary" style="font-size:13px;">Clear</button>
    <span id="rtoc-cohort-status" style="color:#666;font-size:12px;"></span>
  </div>
</div>
</div>
<script>
(function (){
  var aqfTable = {
    "Cert I":          { acsf:"Level 1",   acsfShort:"ACSF Level 1",   entry:"No formal prerequisites. Open entry for all learners.", school:"Year 9 or below equivalent" },
    "Cert II":         { acsf:"Level 2",   acsfShort:"ACSF Level 2",   entry:"Basic literacy and numeracy skills recommended. No formal qualifications required.", school:"Year 10 equivalent" },
    "Cert III":        { acsf:"Level 2-3", acsfShort:"ACSF Level 2-3", entry:"Certificate II or equivalent vocational experience recommended.", school:"Year 11-12 equivalent" },
    "Cert IV":         { acsf:"Level 3",   acsfShort:"ACSF Level 3",   entry:"Certificate III or relevant industry experience required.", school:"Year 12 / ATAR equivalent" },
    "Diploma":         { acsf:"Level 3-4", acsfShort:"ACSF Level 3-4", entry:"Certificate IV or demonstrated equivalent industry experience required.", school:"Year 12 / ATAR equivalent" },
    "Advanced Diploma":{ acsf:"Level 4",   acsfShort:"ACSF Level 4",   entry:"Diploma or substantial demonstrated industry experience required.", school:"Post-Year 12 / undergraduate equivalent" }
  };
  var allCohorts = [
    {name:"School Leavers (Year 10)",       levels:["Cert I","Cert II"],                          acsf:"1-2"},
    {name:"School Leavers (Year 12)",       levels:["Cert II","Cert III"],                        acsf:"2-3"},
    {name:"Existing Workers",               levels:["Cert II","Cert III","Cert IV"],               acsf:"2-3"},
    {name:"Experienced Workers",            levels:["Cert III","Cert IV","Diploma"],               acsf:"3"},
    {name:"Supervisors / Team Leaders",     levels:["Cert IV","Diploma"],                          acsf:"3-4"},
    {name:"Career Changers",                levels:["Cert II","Cert III","Cert IV"],               acsf:"2-3"},
    {name:"Job Seekers / Unemployed",       levels:["Cert I","Cert II"],                           acsf:"1-2"},
    {name:"CALD / ESL Learners",            levels:["Cert I","Cert II","Cert III"],                acsf:"1-3"},
    {name:"Apprentices / Trainees",         levels:["Cert II","Cert III","Cert IV"],               acsf:"2-3"},
    {name:"Para-Professional Staff",        levels:["Cert IV","Diploma"],                          acsf:"3-4"},
    {name:"Managers / Senior Leaders",      levels:["Diploma","Advanced Diploma"],                  acsf:"4-5"},
    {name:"Learners with Disability Support Needs",levels:["Cert I","Cert II","Cert III"],         acsf:"1-3"},
    {name:"International Students",         levels:["Cert III","Cert IV","Diploma"],               acsf:"3"}
  ];

  var selEl = document.getElementById("rtoc-aqf-level");
  var gridEl = document.getElementById("rtoc-cohort-grid");
  var checkboxEl = document.getElementById("rtoc-cohort-checkboxes");
  var schoolEl = document.getElementById("rtoc-school-info");
  var schoolEqEl = document.getElementById("rtoc-school-eq");
  var statusEl = document.getElementById("rtoc-cohort-status");

  selEl.addEventListener("change", function (){
    var level = selEl.value;
    if (!level) { gridEl.style.display="none"; schoolEl.style.display="none"; return; }
    var info = aqfTable[level];
    schoolEqEl.textContent = info.school;
    schoolEl.style.display = "block";
    checkboxEl.innerHTML = "";
    allCohorts.forEach(function (c,i){
      var applicable = c.levels.indexOf(level) !== -1;
      var div = document.createElement("div");
      div.style.cssText = "display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:4px;background:"+(applicable?"#e8f5e9":"#f5f5f5")+";border:1px solid "+(applicable?"#a5d6a7":"#e0e0e0")+";";
      var cb = document.createElement("input");
      cb.type = "checkbox"; cb.id = "rtoc-cohort-"+i; cb.value = c.name;
      if (applicable) cb.checked = true;
      var lbl = document.createElement("label");
      lbl.htmlFor = "rtoc-cohort-"+i;
      lbl.style.cssText = "font-size:12px;margin:0;cursor:pointer;color:"+(applicable?"#1b5e20":"#757575")+";";
      lbl.textContent = c.name + (applicable?" (ACSF "+c.acsf+")":"");
      div.appendChild(cb); div.appendChild(lbl);
      checkboxEl.appendChild(div);
    });
    gridEl.style.display = "block";
  });

  document.getElementById("rtoc-apply-section2").addEventListener("click", function (){
    var level = selEl.value;
    if (!level) { statusEl.textContent="Please select an AQF level first."; return; }
    var info = aqfTable[level];
    var selected = [];
    allCohorts.forEach(function (c,i){
      var cb = document.getElementById("rtoc-cohort-"+i);
      if (cb && cb.checked) selected.push(c);
    });
    if (!selected.length) { statusEl.textContent="Tick at least one cohort."; return; }

    var cohortText = "Target learner cohorts for this " + level + " qualification include:\n\n";
    selected.forEach(function (c){
      cohortText += "- " + c.name + " (ACSF " + c.acsf + ")\n";
    });
    cohortText += "\nMature-age learners (4+ years industry experience) are also eligible subject to LLN assessment.";

    var entryText = "Entry Requirements (" + level + "):\n" + info.entry + "\n\nSchool Equivalence: " + info.school + "\n\n" +
      "All prospective learners must complete a pre-enrolment LLN assessment prior to enrolment. " +
      "Learners who do not meet the minimum literacy and numeracy requirements will be referred to Foundation Skills programs.";

    var llnText = "Minimum Language, Literacy and Numeracy (LLN) Requirements: " + info.acsfShort + "\n\n" +
      "All learners undertake a pre-training LLN assessment (ACSF-aligned) prior to commencement. " +
      "Learners assessed below " + info.acsf + " are referred to an appropriate Foundation Skills program before enrolment. " +
      "Reasonable adjustments are available for learners with diagnosed learning difficulties.";

    var cohortTA = document.querySelector("textarea[name=\'targetcohort\']");
    var entryTA  = document.querySelector("textarea[name=\'entryrequirements\']");
    var llnTA    = document.querySelector("textarea[name=\'llnrequirements\']");
    if (cohortTA) cohortTA.value = cohortText;
    if (entryTA)  entryTA.value  = entryText;
    if (llnTA)    llnTA.value    = llnText;
    statusEl.textContent = "Applied \u2713 " + selected.length + " cohort(s) — review and customise the text below.";
    statusEl.style.color = "#2e7d32";
  });

  document.getElementById("rtoc-clear-cohorts").addEventListener("click", function (){
    var cbs = checkboxEl.querySelectorAll("input[type=checkbox]");
    cbs.forEach(function (cb){ cb.checked=false; });
    statusEl.textContent = "";
  });
})();
</script>');

        $mform->addElement('textarea', 'targetcohort', 'Target Learner Cohort', ['rows' => 4, 'cols' => 80]);
        $mform->setType('targetcohort', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('targetcohort', 'targetcohort', 'local_rtocompliance');

        $mform->addElement('textarea', 'entryrequirements', 'Entry Requirements', ['rows' => 4, 'cols' => 80]);
        $mform->setType('entryrequirements', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('entryrequirements', 'entryrequirements', 'local_rtocompliance');

        $mform->addElement('textarea', 'llnrequirements', 'LLN Requirements', ['rows' => 3, 'cols' => 80]);
        $mform->setType('llnrequirements', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('llnrequirements', 'llnrequirements', 'local_rtocompliance');

        $mform->addElement('textarea', 'prerequisites', 'Prerequisites', ['rows' => 3, 'cols' => 80]);
        $mform->setType('prerequisites', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('prerequisites', 'prerequisites', 'local_rtocompliance');

        $mform->addElement('header', 'section3', 'Section 3: Industry Consultation');

        if ($tas && !empty($tas->id)) {
            $consultCount = $DB->count_records('local_rtocompliance_tas_consult', ['tasid' => $tas->id]);
            $consultUrl = new moodle_url('/local/rtocompliance/tas_consultation.php', ['tasid' => $tas->id]);

            $statusHtml = '';
            if ($consultCount > 0) {
                $latestConsult = $DB->get_records('local_rtocompliance_tas_consult', ['tasid' => $tas->id], 'consultationdate DESC', '*', 0, 1);
                $latestConsult = reset($latestConsult);
                $age = time() - $latestConsult->consultationdate;
                $twelvemonths = 365 * 24 * 60 * 60;
                if ($age <= $twelvemonths) {
                    $badge = '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:#d4edda;color:#155724;border:1px solid #c3e6cb;">OK</span>';
                } elseif ($age <= $twelvemonths + (90 * 24 * 60 * 60)) {
                    $badge = '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:#fff3cd;color:#856404;border:1px solid #ffeeba;">DUE</span>';
                } else {
                    $badge = '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;">OVERDUE</span>';
                }
                $statusHtml = '<div style="margin: 8px 0;">' . $badge . ' <strong>' . $consultCount . '</strong> consultation record(s) on file.</div>';
            } else {
                $statusHtml = '<div style="margin: 8px 0;"><span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:#e2e3e5;color:#383d41;border:1px solid #d6d8db;">NO EVIDENCE</span> No consultation records uploaded yet.</div>';
            }

            $mform->addElement('static', 'consultationmanager', '',
                '<div style="border:1px solid #dee2e6;border-radius:8px;padding:16px;background:#f8f9fa;margin-bottom:12px;">'
                . '<strong>Industry Consultation Evidence</strong><br>'
                . '<p style="color:#666;margin:8px 0;">Instead of entering text directly, use the Industry Consultation Manager to download a pre-filled template, '
                . 'record consultation details, upload evidence documents, and auto-generate the TAS narrative.</p>'
                . $statusHtml
                . '<a href="' . $consultUrl->out(false) . '" class="btn btn-primary" style="margin-top:4px;">Manage Industry Consultations</a>'
                . '</div>');
        } else {
            $mform->addElement('static', 'consultationmanager', '',
                '<div class="alert alert-warning">Save this TAS first, then return to manage industry consultation records.</div>');
        }

        $mform->addElement('hidden', 'industryconsultation');
        $mform->setType('industryconsultation', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        $mform->addElement('textarea', 'jobroles', 'Job Roles & Outcomes', ['rows' => 4, 'cols' => 80]);
        $mform->setType('jobroles', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('jobroles', 'jobroles', 'local_rtocompliance');

        $mform->addElement('header', 'section4', 'Section 4: Delivery Structure & Volume of Learning');

        $mform->addElement('static', 'deliveryplannerhelp', '',
            '<div class="alert alert-info" style="margin-bottom: 16px;">' .
            '<strong>Smart Delivery Planner</strong><br>' .
            'Enter a start date and click "Generate Delivery Plan" to automatically calculate delivery duration, ' .
            'volume of learning, weekly schedule, and hour breakdown based on AQF expectations and the qualification units. ' .
            'The system uses training.gov.au data and skips Australian public holidays.' .
            '</div>');

        $modes = [
            'classroom' => 'Classroom-based',
            'workplace' => 'Workplace-based',
            'online' => 'Online/Distance',
            'blended' => 'Blended Delivery',
            'mixed' => 'Mixed Mode',
        ];
        $mform->addElement('select', 'deliverymode', 'Primary Delivery Mode', $modes);

        $mform->addElement('date_selector', 'deliverystartdate', 'Delivery Start Date', ['optional' => true]);

        $mform->addElement('text', 'hoursperweek', 'Hours per Week per Unit', ['size' => 10]);
        $mform->setType('hoursperweek', PARAM_INT);
        $mform->setDefault('hoursperweek', 4);

        $mform->addElement('text', 'nominalhours', 'Total Nominal Hours', ['size' => 10]);
        $mform->setType('nominalhours', PARAM_INT);

        $mform->addElement('text', 'durationweeks', 'Duration (Weeks)', ['size' => 10]);
        $mform->setType('durationweeks', PARAM_INT);

        $mform->addElement('text', 'volumeoflearning', 'Volume of Learning (Total Hours)', ['size' => 10]);
        $mform->setType('volumeoflearning', PARAM_INT);
        $mform->addHelpButton('volumeoflearning', 'volumeoflearning', 'local_rtocompliance');

        $mform->addElement('textarea', 'deliveryschedule', 'Delivery Schedule', ['rows' => 8, 'cols' => 80]);
        $mform->setType('deliveryschedule', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('deliveryschedule', 'deliveryschedule', 'local_rtocompliance');

        $mform->addElement('textarea', 'learningbreakdown', 'Volume of Learning Breakdown', ['rows' => 6, 'cols' => 80]);
        $mform->setType('learningbreakdown', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('learningbreakdown', 'learningbreakdown', 'local_rtocompliance');

        $mform->addElement('textarea', 'volumejustification', 'TAS Volume of Learning Justification', ['rows' => 6, 'cols' => 80]);
        $mform->setType('volumejustification', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('volumejustification', 'volumejustification', 'local_rtocompliance');

        $mform->addElement('header', 'section5', 'Section 5: Assessment Plan');

        // Hidden field - stores JSON of selected assessment methods
        $mform->addElement('hidden', 'assessmentmethods');
        $mform->setType('assessmentmethods', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Assessment methods checklist
        $amCategories = [
            'Knowledge (Theoretical) Assessments' => [
                'Multiple Choice Questions (MCQs)',
                'Short Answer Questions',
                'Long Answer / Extended Response',
                'True / False Questions',
                'Fill in the Blanks / Missing Words',
                'Matching Questions',
                'Case Study Analysis Questions',
                'Scenario-based MCQs',
                'Open-book Assessments',
                'Closed-book Tests',
                'Research Tasks',
                'Problem-solving Questions',
            ],
            'Practical (Performance) Assessments' => [
                'Direct Observation (Live Task)',
                'Simulated Workplace Task',
                'Practical Demonstration',
                'Skills Checklist Assessment',
                'Task-based Assessment',
                'Multi-step Job Simulation',
                'End-to-end Workflow Task',
                'Timed Practical Tasks',
                'Fault-finding / Troubleshooting Tasks',
            ],
            'Workplace / Real-World Assessments' => [
                'Workplace Observation',
                'Third-party Reports (Supervisor)',
                'Logbooks / Workplace Journals',
                'On-the-job Competency Assessments',
            ],
            'Document / Product-based Assessments' => [
                'Completed Workplace Forms',
                'Reports',
                'Plans (e.g. Project Plan, WHS Plan)',
                'Spreadsheets / Calculations',
                'Designs / Drawings',
            ],
            'Role Play / Interaction Assessments' => [
                'Customer Interaction Role Play',
                'Team Communication Scenario',
                'Conflict Resolution Simulation',
                'Supervisor / Employee Interactions',
            ],
            'Project-based Assessments' => [
                'Individual Projects',
                'Group Projects',
                'Workplace Improvement Project',
                'Research & Implementation Tasks',
            ],
            'Portfolio of Evidence' => [
                'Collection of Work Samples',
                'Evidence from Workplace',
                'Photos / Videos',
                'Documents Created Over Time',
            ],
            'Structured Interviews / Oral Questioning' => [
                'Verbal Questioning During Assessment',
                'Structured Competency Interview',
                'Knowledge Confirmation Discussion',
            ],
            'Recognition of Prior Learning (RPL)' => [
                'Evidence Review',
                'Competency Conversation (RPL)',
                'Portfolio Assessment (RPL)',
                'Third-party Verification (RPL)',
            ],
            'Simulation-based Assessments' => [
                'Full Workplace Simulation',
                'Scenario-based Job Environment',
                'Virtual Simulation Tools',
            ],
            'Video / Visual Evidence' => [
                'Student-recorded Demonstrations',
                'Assessor-recorded Observation',
                'Screen Recordings (Software Tasks)',
            ],
            'Diagnostic / Formative Assessments' => [
                'Pre-tests',
                'Practice Quizzes',
                'Skill Checks',
            ],
        ];

        $amHtml = '<div id="rtoc-assessment-checklist" style="border:1px solid #dee2e6;border-radius:8px;padding:20px;background:#f8f9fa;margin-bottom:16px;">';
        $amHtml .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">';
        $amHtml .= '<strong style="font-size:15px;color:#333;">Select Assessment Methods for this Qualification</strong>';
        $amHtml .= '<div style="display:flex;gap:8px;">';
        $amHtml .= '<button type="button" onclick="rtocAmSelectAll(true)" style="font-size:12px;padding:4px 12px;border:1px solid #6c757d;border-radius:4px;background:#f8f9fa;color:#333333;cursor:pointer;">Select All</button>';
        $amHtml .= '<button type="button" onclick="rtocAmSelectAll(false)" style="font-size:12px;padding:4px 12px;border:1px solid #6c757d;border-radius:4px;background:#f8f9fa;color:#333333;cursor:pointer;">Clear All</button>';
        $amHtml .= '</div></div>';
        $amHtml .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">';

        foreach ($amCategories as $catName => $items) {
            $amHtml .= '<div style="background:#fff;border:1px solid #e9ecef;border-radius:6px;padding:14px;">';
            $amHtml .= '<div style="font-size:12px;font-weight:700;color:#495057;padding-bottom:8px;margin-bottom:10px;border-bottom:2px solid #e9ecef;">' . htmlspecialchars($catName) . '</div>';
            foreach ($items as $item) {
                $itemId = 'rtoc-am-' . substr(md5($item), 0, 8);
                $itemVal = htmlspecialchars($item, ENT_QUOTES);
                $amHtml .= '<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">';
                $amHtml .= '<input type="checkbox" id="' . $itemId . '" value="' . $itemVal . '" class="rtoc-am-check" style="margin-top:2px;flex-shrink:0;" onchange="rtocAmUpdate()">';
                $amHtml .= '<label for="' . $itemId . '" style="font-size:12px;cursor:pointer;color:#495057;line-height:1.4;margin:0;">' . htmlspecialchars($item) . '</label>';
                $amHtml .= '</div>';
            }
            $amHtml .= '</div>';
        }

        $amHtml .= '</div>';
        $amHtml .= '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #dee2e6;">';
        $amHtml .= '<label style="font-size:13px;font-weight:600;color:#495057;display:block;margin-bottom:6px;">Additional Methods (not listed above):</label>';
        $amHtml .= '<textarea id="rtoc-am-additional" rows="2" style="width:100%;padding:8px;border:1px solid #ced4da;border-radius:4px;font-size:13px;resize:vertical;" placeholder="List any additional assessment methods not covered above..." oninput="rtocAmUpdate()"></textarea>';
        $amHtml .= '</div>';
        $amHtml .= '<p id="rtoc-am-saved" style="color:#2e7d32;font-size:12px;margin:8px 0 0;display:none;">Selection saved</p>';
        $amHtml .= '</div>';

        $amHtml .= '<script>
function rtocAmUpdate(){
    var checks = document.querySelectorAll(".rtoc-am-check:checked");
    var arr = [];
    checks.forEach(function (c){ arr.push(c.value); });
    var extra = document.getElementById("rtoc-am-additional");
    if(extra && extra.value.trim()) arr.push("OTHER: " + extra.value.trim());
    var hidden = document.querySelector("input[name=\'assessmentmethods\']");
    if(hidden) hidden.value = JSON.stringify({v:2, selected:arr});
    var saved = document.getElementById("rtoc-am-saved");
    if(saved){ saved.style.display="block"; setTimeout(function (){ saved.style.display="none"; }, 1500); }
}
function rtocAmSelectAll(state){
    document.querySelectorAll(".rtoc-am-check").forEach(function (c){ c.checked=state; });
    rtocAmUpdate();
}
document.addEventListener("DOMContentLoaded", function (){
    var hidden = document.querySelector("input[name=\'assessmentmethods\']");
    if(!hidden || !hidden.value) return;
    var parsed = null;
    try{ parsed = JSON.parse(hidden.value); }catch(e){}
    if(parsed && parsed.v===2 && Array.isArray(parsed.selected)){
        var others = [];
        parsed.selected.forEach(function (s){
            if(s.indexOf("OTHER: ")===0){ others.push(s.replace("OTHER: ","")); return; }
            var checks = document.querySelectorAll(".rtoc-am-check");
            checks.forEach(function (c){ if(c.value===s) c.checked=true; });
        });
        if(others.length){
            var ex=document.getElementById("rtoc-am-additional");
            if(ex) ex.value=others.join(", ");
        }
    } else if(hidden.value){
        var ex=document.getElementById("rtoc-am-additional");
        if(ex && !ex.value) ex.value=hidden.value;
    }
});
</script>';

        $mform->addElement('static', 'assessmentmethods_checklist', '', $amHtml);

        // Assessment Mapping Document - replaced textarea with document link
        $mform->addElement('text', 'assessmentmapping', 'Assessment Mapping Document Link', ['size' => 80, 'placeholder' => 'Paste link to mapping document (e.g. SharePoint, Google Drive, or your DMS)']);
        $mform->setType('assessmentmapping', PARAM_TEXT);
        $mform->addElement('static', 'assessmentmapping_note', '', '<p style="color:#666;font-size:12px;margin-top:4px;">Paste a link to your assessment mapping spreadsheet (mapping each assessment to units of competency). Upload the document to SharePoint, Google Drive, or your document management system and paste the link above.</p>');

        // Validation Schedule removed - managed in dashboard
        $mform->addElement('hidden', 'validationschedule');
        $mform->setType('validationschedule', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addElement('static', 'validationschedule_note', '', '<div class="alert alert-info" style="margin-bottom:0;"><strong>Assessment Validation Schedule</strong> is managed in the Validation Register on the <a href="/local/rtocompliance/validation.php">RTO Compliance Dashboard</a>. Validation is not qualification-specific and belongs in the central register.</div>');

        $mform->addElement('textarea', 'assessmentnotes', 'Assessment Plan Notes', ['rows' => 4, 'cols' => 80, 'placeholder' => 'Describe your overall assessment approach, contextualisation of assessment tools, reasonable adjustment principles, and how assessment meets the rules of evidence (valid, sufficient, authentic, current)...']);
        $mform->setType('assessmentnotes', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addElement('static', 'assessmentnotes_help', '', '<p style="color:#666;font-size:12px;margin-top:4px;">Use this field to document your assessment approach narrative. AI suggestion is available via the sparkle button.</p>');

        $mform->addElement('header', 'section6', 'Section 6: Trainer & Assessor Requirements');

        $mform->addElement('textarea', 'trainerrequirements', 'Trainer/Assessor Requirements', ['rows' => 4, 'cols' => 80]);
        $mform->setType('trainerrequirements', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('trainerrequirements', 'trainerrequirements', 'local_rtocompliance');

        $mform->addElement('textarea', 'supervisionarrangements', 'Supervision Arrangements (if applicable)', ['rows' => 3, 'cols' => 80]);
        $mform->setType('supervisionarrangements', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('supervisionarrangements', 'supervisionarrangements', 'local_rtocompliance');

        $mform->addElement('header', 'section7', 'Section 7: Learning Resources & Equipment');

        $mform->addElement('textarea', 'learningresources', 'Learning Resources & Materials', ['rows' => 4, 'cols' => 80]);
        $mform->setType('learningresources', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('learningresources', 'learningresources', 'local_rtocompliance');

        $mform->addElement('textarea', 'facilities', 'Facilities & Equipment', ['rows' => 4, 'cols' => 80]);
        $mform->setType('facilities', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('facilities', 'facilities', 'local_rtocompliance');

        $mform->addElement('textarea', 'technology', 'Technology Requirements', ['rows' => 3, 'cols' => 80]);
        $mform->setType('technology', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('technology', 'technology', 'local_rtocompliance');

        // Section 8: Third-Party Arrangements — removed from TAS, managed in the dashboard register
        $mform->addElement('hidden', 'thirdparty');
        $mform->setType('thirdparty', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Section 9: Learner Support & Wellbeing — removed from TAS, managed in the dashboard
        $mform->addElement('hidden', 'learnersupport');
        $mform->setType('learnersupport', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addElement('hidden', 'accessibility');
        $mform->setType('accessibility', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Section 10: Marketing & Pre-Enrolment — removed from TAS, managed in Marketing Information page
        $mform->addElement('hidden', 'marketinginfo');
        $mform->setType('marketinginfo', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        $mform->addElement('hidden', 'feesinformation');
        $mform->setType('feesinformation', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        $mform->addElement('header', 'section8', 'Section 8: Work Placement Requirements');

        $mform->addElement('advcheckbox', 'hasworkplacement', 'Requires Work Placement', 'This qualification includes mandatory work placement');

        $mform->addElement('text', 'placementhours', 'Work Placement Hours', ['size' => 10]);
        $mform->setType('placementhours', PARAM_INT);
        $mform->disabledIf('placementhours', 'hasworkplacement', 'notchecked');

        $mform->addElement('textarea', 'placementdetails', 'Placement Details & Supervision', ['rows' => 4, 'cols' => 80]);
        $mform->setType('placementdetails', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('placementdetails', 'placementdetails', 'local_rtocompliance');
        $mform->disabledIf('placementdetails', 'hasworkplacement', 'notchecked');

        // Section 12: Transition & Teach-Out — removed from TAS, managed in Training Transitions register
        $mform->addElement('hidden', 'transitionplan');
        $mform->setType('transitionplan', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Section 13: Risk Management — removed from TAS, managed in Risk Register
        $mform->addElement('hidden', 'riskmanagement');
        $mform->setType('riskmanagement', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Section 14: Complaints & Appeals — removed from TAS, managed in Complaints register
        $mform->addElement('hidden', 'complaintsprocess');
        $mform->setType('complaintsprocess', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        // Section 15: Continuous Improvement — removed from TAS, managed in CI register
        $mform->addElement('hidden', 'continuousimprovement');
        $mform->setType('continuousimprovement', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
 // pipeline-ignore: PARAM_RAW — rich-text/JSON field sanitised before display or decoded immediately
        $mform->addElement('header', 'section9', 'Section 9: TAS Approval & Review');

        $statuses = [
            'draft' => 'Draft',
            'review' => 'Under Review',
            'approved' => 'Approved',
            'archived' => 'Archived',
        ];
        $mform->addElement('select', 'status', 'Document Status', $statuses);

        $mform->addElement('text', 'version', 'Version Number', ['size' => 10]);
        $mform->setType('version', PARAM_TEXT);
        $mform->setDefault('version', '1.0');

        $mform->addElement('date_selector', 'approvaldate', 'Approval Date');

        $mform->addElement('text', 'approvedby', 'Approved By', ['size' => 50]);
        $mform->setType('approvedby', PARAM_TEXT);

        $mform->addElement('date_selector', 'nextreviewdate', 'Next Review Date');

        $mform->addElement('textarea', 'revisionnotes', 'Revision Notes', ['rows' => 3, 'cols' => 80]);
        $mform->setType('revisionnotes', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('revisionnotes', 'revisionnotes', 'local_rtocompliance');

        $this->add_action_buttons(true, $tas ? 'Update TAS' : 'Create TAS');
    }
}

$form = new tas_form(null, ['tas' => $tas]);

if ($tas) {
    $formdata = clone $tas;
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/tas.php'));
} else if ($data = $form->get_data()) {
    $now = time();

    $record = new stdClass();
    $record->qualificationcode = $data->qualificationcode;
    $record->qualificationname = $data->qualificationname;
    $record->traininggovlink = $data->traininggovlink ?? '';
    $record->scopedetails = $data->scopedetails ?? '';
    $record->targetcohort = $data->targetcohort ?? '';
    $record->entryrequirements = $data->entryrequirements ?? '';
    $record->llnrequirements = $data->llnrequirements ?? '';
    $record->prerequisites = $data->prerequisites ?? '';
    $record->industryconsultation = $data->industryconsultation ?? '';
    $record->jobroles = $data->jobroles ?? '';
    $record->deliverymode = $data->deliverymode ?? 'classroom';
    $record->nominalhours = $data->nominalhours ?? 0;
    $record->durationweeks = $data->durationweeks ?? 0;
    $record->hoursperweek = $data->hoursperweek ?? 0;
    $record->volumeoflearning = $data->volumeoflearning ?? 0;
    $record->deliveryschedule = $data->deliveryschedule ?? '';
    $record->deliverystartdate = $data->deliverystartdate ?? 0;
    $record->learningbreakdown = $data->learningbreakdown ?? '';
    $record->volumejustification = $data->volumejustification ?? '';
    $record->assessmentmethods = $data->assessmentmethods ?? '';
    $record->assessmentmapping = $data->assessmentmapping ?? '';
    $record->assessmentnotes   = $data->assessmentnotes   ?? '';
    $record->validationschedule = $data->validationschedule ?? '';
    $record->trainerrequirements = $data->trainerrequirements ?? '';
    $record->supervisionarrangements = $data->supervisionarrangements ?? '';
    $record->learningresources = $data->learningresources ?? '';
    $record->facilities = $data->facilities ?? '';
    $record->technology = $data->technology ?? '';
    $record->thirdparty = $data->thirdparty ?? '';
    $record->learnersupport = $data->learnersupport ?? '';
    $record->accessibility = $data->accessibility ?? '';
    $record->marketinginfo = $data->marketinginfo ?? '';
    $record->feesinformation = $data->feesinformation ?? '';
    $record->hasworkplacement = $data->hasworkplacement ?? 0;
    $record->placementhours = $data->placementhours ?? 0;
    $record->placementdetails = $data->placementdetails ?? '';
    $record->transitionplan = $data->transitionplan ?? '';
    $record->riskmanagement = $data->riskmanagement ?? '';
    $record->complaintsprocess = $data->complaintsprocess ?? '';
    $record->continuousimprovement = $data->continuousimprovement ?? '';
    $record->status = $data->status ?? 'draft';
    $record->version = $data->version ?? '1.0';
    $record->approvaldate = $data->approvaldate ?? null;
    $record->approvedby = $data->approvedby ?? '';
    $record->nextreviewdate = $data->nextreviewdate ?? null;
    $record->revisionnotes = $data->revisionnotes ?? '';
    $record->timemodified = $now;

    // Completeness calculated across the 9 TAS sections
    $filledfields = 0;
    $totalfields = 9;
    // Section 1: RTO & Training Product — qualification code/name indicates section started
    // (deliverymode is in Section 4 and defaults to 'classroom', so it is always non-empty
    //  and cannot be used as a completeness signal for Section 1)
    if (!empty($record->qualificationcode) || !empty($record->qualificationname)) $filledfields++;
    // Section 2: Target Learner Cohort & Entry Requirements
    if (!empty($record->targetcohort) || !empty($record->entryrequirements)) $filledfields++;
    // Section 3: Industry Consultation (query consultation register, not deprecated hidden field)
    $consultCount3 = $DB->count_records('local_rtocompliance_tas_consult', ['tasid' => !empty($data->id) ? (int)$data->id : 0]);
    if ($consultCount3 > 0) $filledfields++;
    // Section 4: Delivery Structure & Volume of Learning
    if (!empty($record->deliveryschedule) || $record->nominalhours > 0 || $record->volumeoflearning > 0) $filledfields++;
    // Section 5: Assessment Plan
    if (!empty($record->assessmentmethods) || !empty($record->assessmentmapping)) $filledfields++;
    // Section 6: Trainer & Assessor Requirements
    if (!empty($record->trainerrequirements) || !empty($record->jobroles)) $filledfields++;
    // Section 7: Learning Resources & Equipment
    if (!empty($record->learningresources)) $filledfields++;
    // Section 8: Work Placement Requirements — always count. Having no work placement
    // (hasworkplacement=0) is a valid, deliberate answer meaning "no WP required".
    // The section is complete whether the RTO selected "yes with details" or "no WP needed".
    $filledfields++;
    // Section 9: TAS Approval & Review
    if (!empty($record->approvedby) || $record->status === 'approved') $filledfields++;

    $record->completeness = round(($filledfields / $totalfields) * 100);

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_tas', $record);
        $message = 'TAS document updated successfully';
    } else {
        $existing = $DB->get_record('local_rtocompliance_tas', [
            'qualificationcode' => $record->qualificationcode,
            'version'           => $record->version,
        ]);
        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $record->createdby   = $existing->createdby;
            $DB->update_record('local_rtocompliance_tas', $record);
            $message = 'TAS document updated successfully';
        } else {
            $record->timecreated = $now;
            $record->createdby = $USER->id;
            $DB->insert_record('local_rtocompliance_tas', $record);
            $message = 'TAS document created successfully';
        }
    }

    redirect(
        new moodle_url('/local/rtocompliance/tas.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
$PAGE->requires->js('/local/rtocompliance/js/ai_suggest.js', false);
echo $OUTPUT->header();

// ── AI Suggest config block ──────────────────────────────────────────────────
// Embeds API key and base URL for the ai_suggest.js frontend module.
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
    'id'             => 'rtoc-ai-config',
    'data-api-key'   => $_rtoc_apikey,
    'data-api-base'  => $_rtoc_apibase,
    'style'          => 'display:none',
    'aria-hidden'    => 'true',
]);
echo local_rtocompliance_render_nav_header($id ? 'Edit TAS' : 'Create TAS', get_string('tas', 'local_rtocompliance'), '/local/rtocompliance/tas.php', 'tas');
echo html_writer::start_div('compliance-container');
echo $OUTPUT->heading($id ? 'Edit Training & Assessment Strategy' : 'Create Training & Assessment Strategy');

// FIX-AI-NOTICE: if API key not configured, show a clear admin-only notice so
// the user understands why the AI sparkle buttons are absent from textareas.
if (empty($_rtoc_apikey) && has_capability('moodle/site:config', context_system::instance())) {
    echo html_writer::tag('div',
        html_writer::tag('strong', 'AI Content Suggestions unavailable: ') .
        'No platform API key is configured for this plugin. To enable the AI sparkle buttons on all text fields, go to ' .
        html_writer::tag('a', 'Plugin Settings', ['href' => (new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_api']))->out(false)]) .
        ' and enter your API key.',
        ['class' => 'alert alert-warning', 'style' => 'margin-bottom: 12px;']
    );
}

echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 24px;']);
echo html_writer::tag('p', 'Complete all 9 ASQA-mandated sections below. Fields marked with * are required. The completeness score is calculated automatically based on filled sections.', ['class' => 'text-muted', 'style' => 'margin: 0;']);
echo html_writer::end_div();

$form->display();

echo '<script>
document.addEventListener("DOMContentLoaded", function () {

    var AQF_VOLUME = {
        1: {label:"Certificate I", low:600, high:1200},
        2: {label:"Certificate II", low:600, high:1200},
        3: {label:"Certificate III", low:1200, high:2400},
        4: {label:"Certificate IV", low:600, high:2400},
        5: {label:"Diploma", low:1200, high:2400},
        6: {label:"Advanced Diploma", low:1500, high:3000},
        7: {label:"Graduate Certificate", low:600, high:1200},
        8: {label:"Graduate Diploma", low:1200, high:2400}
    };

    var AU_HOLIDAYS_2025_2027 = [
        "2025-01-27","2025-04-18","2025-04-19","2025-04-21","2025-04-25","2025-06-09",
        "2025-12-25","2025-12-26",
        "2026-01-26","2026-04-03","2026-04-04","2026-04-06","2026-04-25","2026-06-08",
        "2026-12-25","2026-12-28",
        "2027-01-26","2027-03-26","2027-03-27","2027-03-29","2027-04-26","2027-06-14",
        "2027-12-27","2027-12-28"
    ];

    function detectAQFLevel(qualCode, qualTitle) {
        var code = (qualCode || "").toUpperCase();
        var title = (qualTitle || "").toLowerCase();
        if (title.indexOf("advanced diploma") !== -1) return 6;
        if (title.indexOf("graduate diploma") !== -1) return 8;
        if (title.indexOf("graduate certificate") !== -1) return 7;
        if (title.indexOf("diploma") !== -1) return 5;
        if (title.indexOf("certificate iv") !== -1 || title.indexOf("certificate 4") !== -1) return 4;
        if (title.indexOf("certificate iii") !== -1 || title.indexOf("certificate 3") !== -1) return 3;
        if (title.indexOf("certificate ii") !== -1 || title.indexOf("certificate 2") !== -1) return 2;
        if (title.indexOf("certificate i") !== -1 || title.indexOf("certificate 1") !== -1) return 1;
        var digit = code.match(/(\d)(?=\d{4,5}$)/);
        if (digit) {
            var lvl = parseInt(digit[1]);
            if (lvl >= 1 && lvl <= 8) return lvl;
        }
        return 4;
    }

    function getMonday(d) {
        var day = d.getDay();
        var diff = d.getDate() - day + (day === 0 ? -6 : 1);
        return new Date(d.getFullYear(), d.getMonth(), diff);
    }

    function formatDate(d) {
        var months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
        return d.getDate() + " " + months[d.getMonth()];
    }

    function isHolidayWeek(monday) {
        for (var i = 0; i < 5; i++) {
            var day = new Date(monday);
            day.setDate(day.getDate() + i);
            var iso = day.toISOString().substring(0, 10);
            if (AU_HOLIDAYS_2025_2027.indexOf(iso) !== -1) return true;
        }
        return false;
    }

    function generateSchedule(units, startDate, hrsPerWeek) {
        var schedule = [];
        var current = getMonday(new Date(startDate));
        var coreUnits = units.filter(function (u) { return u.CoreUnit; });
        var electiveUnits = units.filter(function (u) { return !u.CoreUnit; });
        var orderedUnits = coreUnits.concat(electiveUnits);

        for (var i = 0; i < orderedUnits.length; i++) {
            while (isHolidayWeek(current)) {
                var friday = new Date(current);
                friday.setDate(friday.getDate() + 4);
                schedule.push({
                    week: schedule.length + 1,
                    unit: "PUBLIC HOLIDAY - No delivery",
                    code: "",
                    hours: 0,
                    dates: formatDate(current) + " - " + formatDate(friday),
                    type: "holiday"
                });
                current.setDate(current.getDate() + 7);
            }
            var friday2 = new Date(current);
            friday2.setDate(friday2.getDate() + 4);
            schedule.push({
                week: schedule.length + 1,
                unit: orderedUnits[i].UnitTitle,
                code: orderedUnits[i].UnitCode,
                hours: hrsPerWeek,
                dates: formatDate(current) + " - " + formatDate(friday2),
                type: orderedUnits[i].CoreUnit ? "core" : "elective"
            });
            current.setDate(current.getDate() + 7);
        }

        while (isHolidayWeek(current)) {
            current.setDate(current.getDate() + 7);
        }
        var assessFriday = new Date(current);
        assessFriday.setDate(assessFriday.getDate() + 4);
        schedule.push({
            week: schedule.length + 1,
            unit: "Assessment & Validation Week",
            code: "",
            hours: hrsPerWeek,
            dates: formatDate(current) + " - " + formatDate(assessFriday),
            type: "assessment"
        });

        return schedule;
    }

    function calculateBreakdown(unitCount, aqfLevel, hrsPerWeek) {
        var aqf = AQF_VOLUME[aqfLevel] || AQF_VOLUME[4];
        var suggestedVol = Math.round((aqf.low + aqf.high) / 2);
        var supervised = unitCount * hrsPerWeek;
        var assessment = unitCount * 5;
        var selfStudy = unitCount * 10;
        var workplace = Math.max(0, suggestedVol - (supervised + assessment + selfStudy));
        return {
            supervised: supervised,
            assessment: assessment,
            selfStudy: selfStudy,
            workplace: workplace,
            total: supervised + assessment + selfStudy + workplace,
            aqfRange: aqf.low + " - " + aqf.high,
            aqfLabel: aqf.label,
            suggested: suggestedVol
        };
    }

    function generateJustification(qualName, breakdown, deliveryWeeks, hrsPerWeek, deliveryMode) {
        var modeText = deliveryMode === "classroom" ? "classroom-based facilitated" :
                       deliveryMode === "workplace" ? "workplace-based" :
                       deliveryMode === "online" ? "online and distance" :
                       deliveryMode === "blended" ? "blended (online and face-to-face)" : "mixed mode";

        return "Volume of Learning\\n\\n" +
            "The delivery of " + qualName + " is structured over " + deliveryWeeks + " weeks, " +
            "with " + hrsPerWeek + " hours of " + modeText + " training per week per unit, " +
            "supported by additional self-directed learning, assessment activities, and workplace application.\\n\\n" +
            "The total volume of learning is approximately " + breakdown.total + " hours, which aligns with AQF expectations " +
            "for " + breakdown.aqfLabel + " qualifications (" + breakdown.aqfRange + " hours).\\n\\n" +
            "The volume of learning includes:\\n" +
            "  - Supervised training: " + breakdown.supervised + " hours\\n" +
            "  - Self-directed study and review of learning resources: " + breakdown.selfStudy + " hours\\n" +
            "  - Assessment preparation and completion: " + breakdown.assessment + " hours\\n" +
            "  - Workplace application of skills and knowledge: " + breakdown.workplace + " hours\\n\\n" +
            "This structure ensures learners have sufficient opportunity to develop the required competencies " +
            "through a combination of structured learning, independent practice, and real-world application.";
    }

    // Delivery plan generation removed - button removed from Section 4
    if (false) {
        var generateBtn = null;
        var hrsPerWeek = 0;
        var deliveryMode = "";
        var statusEl = null;
        var outputEl = null;
        var qualCode = "";
        var qualCodeEl = null;
        var startDate = new Date();
        statusEl.innerHTML = "";

            var siteUrl = window.location.protocol + "//" + window.location.host;
            var apiUrl = "";
            var configEl = document.querySelector("[data-aigrader-api]");
            if (configEl) {
                apiUrl = configEl.getAttribute("data-aigrader-api");
            }
            if (!apiUrl) {
                try {
                    apiUrl = M.cfg.wwwroot.replace(/\\/moodle.*$/, "").replace(/\\/$/, "");
                } catch(e) {}
            }

            // Bug J fix (client side): pass sesskey so ajax.php require_sesskey() check passes.
            var tgaSesskey = (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : "";
            var tgaUrl = "/local/rtocompliance/ajax.php?action=tga_qualification&sesskey=" + encodeURIComponent(tgaSesskey) + "&code=" + encodeURIComponent(qualCode);

            fetch(tgaUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.units || data.units.length === 0) {
                    statusEl.innerHTML = "<span style=\\"color:#cc0000;\\">No units found for " + qualCode + ". You can fill in the fields manually.</span>";
                    generateBtn.disabled = false;
                    return;
                }

                var qualTitle = data.qualification ? data.qualification.Title : qualCode;
                var units = data.units;
                var aqfLevel = detectAQFLevel(qualCode, qualTitle);
                var schedule = generateSchedule(units, startDate, hrsPerWeek);
                var deliveryWeeks = schedule.filter(function (s) { return s.type !== "holiday"; }).length;
                var breakdown = calculateBreakdown(units.length, aqfLevel, hrsPerWeek);

                var scheduleText = "Week | Unit Code | Unit Title | Hours | Dates | Type\\n";
                scheduleText += "-----+----------+-----------+-------+-------+-----\\n";
                for (var i = 0; i < schedule.length; i++) {
                    var s = schedule[i];
                    scheduleText += s.week + " | " + (s.code || "-") + " | " + s.unit + " | " + s.hours + " | " + s.dates + " | " + s.type + "\\n";
                }

                var breakdownText = "Volume of Learning Breakdown (" + breakdown.aqfLabel + ", AQF range: " + breakdown.aqfRange + " hours)\\n\\n";
                breakdownText += "Supervised Training:    " + breakdown.supervised + " hours\\n";
                breakdownText += "Self-Directed Study:    " + breakdown.selfStudy + " hours\\n";
                breakdownText += "Assessment Activities:  " + breakdown.assessment + " hours\\n";
                breakdownText += "Workplace Practice:     " + breakdown.workplace + " hours\\n";
                breakdownText += "-------------------------------\\n";
                breakdownText += "Total Volume of Learning: " + breakdown.total + " hours\\n";

                var justification = generateJustification(qualTitle, breakdown, deliveryWeeks, hrsPerWeek, deliveryMode);

                var nominalEl = document.getElementById("id_nominalhours");
                var durationEl = document.getElementById("id_durationweeks");
                var volEl = document.getElementById("id_volumeoflearning");
                var scheduleEl = document.getElementById("id_deliveryschedule");
                var breakdownEl = document.getElementById("id_learningbreakdown");
                var justEl = document.getElementById("id_volumejustification");

                // Use actual TGA nominal hours (sum of per-unit hours) when available.
                // Fall back to unit-count * hrs/week only if TGA data has no hours.
                var tgaNominalHours = (data.qualification && data.qualification.NominalHours) ? data.qualification.NominalHours : 0;
                if (!tgaNominalHours) {
                    tgaNominalHours = units.reduce(function (sum, u) { return sum + (u.NominalHours || 0); }, 0);
                }
                if (nominalEl) nominalEl.value = tgaNominalHours > 0 ? tgaNominalHours : units.length * hrsPerWeek;
                if (durationEl) durationEl.value = deliveryWeeks;
                if (volEl) volEl.value = breakdown.total;
                if (scheduleEl) scheduleEl.value = scheduleText;
                if (breakdownEl) breakdownEl.value = breakdownText;
                if (justEl) justEl.value = justification.replace(/\\\\n/g, "\\n");

                var html = "<div style=\\"border:1px solid #dee2e6; border-radius:8px; padding:16px; margin-bottom:16px; background:#f8f9fa;\\">";
                html += "<h5 style=\\"margin-top:0;color:#333;\\">Delivery Plan Generated</h5>";
                html += "<table style=\\"width:100%;border-collapse:collapse;\\">";
                html += "<tr><td style=\\"padding:4px 12px;font-weight:bold;\\">Qualification</td><td style=\\"padding:4px 12px;\\">" + qualTitle + " (" + qualCode + ")</td></tr>";
                html += "<tr><td style=\\"padding:4px 12px;font-weight:bold;\\">AQF Level</td><td style=\\"padding:4px 12px;\\">" + breakdown.aqfLabel + " (Level " + aqfLevel + ")</td></tr>";
                html += "<tr><td style=\\"padding:4px 12px;font-weight:bold;\\">Total Units</td><td style=\\"padding:4px 12px;\\">" + units.length + " (" + units.filter(function (u){return u.CoreUnit;}).length + " core, " + units.filter(function (u){return !u.CoreUnit;}).length + " elective)</td></tr>";
                html += "<tr><td style=\\"padding:4px 12px;font-weight:bold;\\">Delivery Weeks</td><td style=\\"padding:4px 12px;\\">" + deliveryWeeks + " weeks (inc. assessment)</td></tr>";
                html += "<tr><td style=\\"padding:4px 12px;font-weight:bold;\\">Volume of Learning</td><td style=\\"padding:4px 12px;\\">" + breakdown.total + " hours (AQF range: " + breakdown.aqfRange + ")</td></tr>";
                html += "</table>";
                html += "</div>";

                outputEl.innerHTML = html;
                statusEl.innerHTML = "<span style=\\"color:#28a745;font-weight:bold;\\">Plan generated - fields populated below.</span>";
                generateBtn.disabled = false;
            })
            .catch(function (err) {
                statusEl.innerHTML = "<span style=\\"color:#cc0000;\\">Error: " + err.message + "</span>";
                generateBtn.disabled = false;
            });
    }

    if (window.location.hash) {
        var hash = window.location.hash.substring(1);
        var match = hash.match(/^tas-section-(\d+)$/);
        if (match) {
            var sectionNum = match[1];
            var sectionHeader = document.getElementById("id_section" + sectionNum);
            if (sectionHeader) {
                var fieldset = sectionHeader.closest("fieldset");
                if (fieldset && fieldset.classList.contains("collapsed")) {
                    var legend = fieldset.querySelector(".ftoggler a");
                    if (legend) {
                        legend.click();
                    }
                }
                setTimeout(function () {
                    sectionHeader.scrollIntoView({ behavior: "smooth", block: "start" });
                    fieldset = sectionHeader.closest("fieldset");
                    if (fieldset) {
                        fieldset.style.outline = "2px solid #3b82f6";
                        fieldset.style.borderRadius = "8px";
                        setTimeout(function () {
                            fieldset.style.outline = "";
                            fieldset.style.borderRadius = "";
                        }, 3000);
                    }
                }, 100);
            }
        }
    }
});
</script>';

echo html_writer::end_div();
echo $OUTPUT->footer();
