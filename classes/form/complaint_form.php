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
 * RTO Compliance plugin — complaint_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class complaint_form extends \moodleform {
    protected function definition() {
        global $CFG, $DB;
        $mform = $this->_form;
        $complaint = $this->_customdata['complaint'] ?? null;

        $mform->addElement('header', 'complaintdetails', get_string('complaint_details', 'local_rtocompliance'));
        $mform->addHelpButton('complaintdetails', 'complaint_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'reference', get_string('complaint_reference', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('reference', PARAM_TEXT);
        $mform->addRule('reference', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reference', 'complaint_reference', 'local_rtocompliance');

        $typeoptions = [
            'student' => get_string('complainant_student', 'local_rtocompliance'),
            'employer' => get_string('complainant_employer', 'local_rtocompliance'),
            'public' => get_string('complainant_public', 'local_rtocompliance'),
            'anonymous' => get_string('complainant_anonymous', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'complainanttype', get_string('complainant_type', 'local_rtocompliance'), $typeoptions);
        $mform->addHelpButton('complainanttype', 'complainanttype', 'local_rtocompliance');
        $mform->setDefault('complainanttype', 'student');

        $mform->addElement('advcheckbox', 'isanonymous', get_string('is_anonymous', 'local_rtocompliance'));
        $mform->setDefault('isanonymous', 0);
        $mform->addHelpButton('isanonymous', 'isanonymous', 'local_rtocompliance');

        $mform->addElement('text', 'complainantname', get_string('complainant_name', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('complainantname', PARAM_TEXT);
        $mform->hideIf('complainantname', 'isanonymous', 'checked');
        $mform->addHelpButton('complainantname', 'complainantname', 'local_rtocompliance');

        $mform->addElement('text', 'complainantemail', get_string('complainant_email', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('complainantemail', PARAM_EMAIL);
        $mform->hideIf('complainantemail', 'isanonymous', 'checked');
        $mform->addHelpButton('complainantemail', 'complainantemail', 'local_rtocompliance');

        $mform->addElement('text', 'complainantphone', get_string('complainant_phone', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 30]);
        $mform->setType('complainantphone', PARAM_TEXT);
        $mform->hideIf('complainantphone', 'isanonymous', 'checked');
        $mform->addHelpButton('complainantphone', 'complainantphone', 'local_rtocompliance');

        $mform->addElement('header', 'issueinformation', get_string('issue_information', 'local_rtocompliance'));
        $mform->addHelpButton('issueinformation', 'issueinformation_header', 'local_rtocompliance');

        $categoryoptions = [
            'training' => get_string('category_training', 'local_rtocompliance'),
            'assessment' => get_string('category_assessment', 'local_rtocompliance'),
            'service' => get_string('category_service', 'local_rtocompliance'),
            'conduct' => get_string('category_conduct', 'local_rtocompliance'),
            'facilities' => get_string('category_facilities', 'local_rtocompliance'),
            'other' => get_string('category_other', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'category', get_string('complaint_category', 'local_rtocompliance'), $categoryoptions);
        $mform->addRule('category', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('category', 'complaint_category', 'local_rtocompliance');

        $subcategoryoptions = [
            '' => get_string('choosedots'),
            'training_quality' => 'Trainer/Assessor Quality',
            'training_content' => 'Training Content/Materials',
            'training_delivery' => 'Delivery Method Issues',
            'training_schedule' => 'Scheduling/Timing Problems',
            'assessment_fairness' => 'Assessment Fairness',
            'assessment_feedback' => 'Assessment Feedback',
            'assessment_timing' => 'Assessment Timing',
            'assessment_methods' => 'Assessment Methods',
            'service_communication' => 'Communication Issues',
            'service_admin' => 'Administrative Errors',
            'service_support' => 'Support Services',
            'service_fees' => 'Fees/Billing Issues',
            'conduct_staff' => 'Staff Conduct',
            'conduct_student' => 'Student Conduct',
            'conduct_discrimination' => 'Discrimination/Harassment',
            'facilities_equipment' => 'Equipment Issues',
            'facilities_access' => 'Accessibility Issues',
            'facilities_safety' => 'Safety Concerns',
            'other' => 'Other (specify in description)',
        ];
        $mform->addElement('select', 'subcategory', get_string('complaint_subcategory', 'local_rtocompliance'), $subcategoryoptions);
        $mform->addHelpButton('subcategory', 'complaint_subcategory', 'local_rtocompliance');

        $mform->addElement('text', 'subject', get_string('complaint_subject', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('subject', 'complaint_subject', 'local_rtocompliance');

        $mform->addElement('textarea', 'description', get_string('complaint_description', 'local_rtocompliance'), ['rows' => 8, 'cols' => 80]);
        $mform->setType('description', PARAM_RAW);
        $mform->addRule('description', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('description', 'complaint_description', 'local_rtocompliance');

        // Standard 2.7 procedural fairness: respondent and opportunity to respond.
        $mform->addElement('text', 'respondentname', 'Respondent (person/party the complaint is against)', ['size' => 50, 'maxlength' => 255]);
        $mform->setType('respondentname', PARAM_TEXT);

        $mform->addElement('textarea', 'respondentresponse', "Respondent's response (opportunity to respond)", ['rows' => 5, 'cols' => 80]);
        $mform->setType('respondentresponse', PARAM_RAW);

        $priorityoptions = [
            'low' => get_string('priority_low', 'local_rtocompliance'),
            'medium' => get_string('priority_medium', 'local_rtocompliance'),
            'high' => get_string('priority_high', 'local_rtocompliance'),
            'critical' => get_string('priority_critical', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'priority', get_string('priority', 'local_rtocompliance'), $priorityoptions);
        $mform->setDefault('priority', 'medium');
        $mform->addHelpButton('priority', 'complaint_priority', 'local_rtocompliance');

        $statusoptions = [
            'received' => get_string('status_received', 'local_rtocompliance'),
            'investigating' => get_string('status_investigating', 'local_rtocompliance'),
            'resolved' => get_string('status_resolved', 'local_rtocompliance'),
            'closed' => get_string('status_closed', 'local_rtocompliance'),
            'withdrawn' => get_string('status_withdrawn', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'received');
        $mform->addHelpButton('status', 'complaint_status', 'local_rtocompliance');

        $useroptions = ['' => get_string('choosedots')];
        $managers = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
             FROM {user} u
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {role} r ON r.id = ra.roleid
             WHERE u.deleted = 0 AND u.suspended = 0 
             AND r.shortname IN ('manager', 'editingteacher', 'coursecreator')
             ORDER BY u.lastname, u.firstname",
            [],
            0,
            200
        );
        foreach ($managers as $u) {
            $useroptions[$u->id] = fullname($u) . ' (' . $u->email . ')';
        }
        $mform->addElement('select', 'assignedto', get_string('assigned_to', 'local_rtocompliance'), $useroptions);
        $mform->addHelpButton('assignedto', 'complaint_assignedto', 'local_rtocompliance');

        $mform->addElement('date_selector', 'datereceived', get_string('date_received', 'local_rtocompliance'));
        $mform->addRule('datereceived', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('datereceived', 'datereceived', 'local_rtocompliance');

        $mform->addElement('date_selector', 'targetresolutiondate', get_string('target_resolution_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('targetresolutiondate', 'targetresolutiondate', 'local_rtocompliance');

        $mform->addElement('header', 'resolutiondetails', get_string('resolution_details', 'local_rtocompliance'));
        $mform->addHelpButton('resolutiondetails', 'resolutiondetails_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'dateacknowledged', get_string('date_acknowledged', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('dateacknowledged', 'dateacknowledged', 'local_rtocompliance');

        $mform->addElement('date_selector', 'actualresolutiondate', get_string('actual_resolution_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('actualresolutiondate', 'actualresolutiondate', 'local_rtocompliance');

        // Standard 2.7 procedural fairness: outcome communicated to all parties.
        $mform->addElement('date_selector', 'dateoutcomecommunicated', 'Date outcome communicated to all parties', ['optional' => true]);

        $mform->addElement('textarea', 'resolution', get_string('resolution', 'local_rtocompliance'), ['rows' => 5, 'cols' => 80]);
        $mform->setType('resolution', PARAM_RAW);
        $mform->addHelpButton('resolution', 'resolution', 'local_rtocompliance');

        // FIX-COMPLAINT-PHP-ERROR: Use NOWDOC to avoid PHP single-quoted string parse errors
        // caused by JavaScript regex patterns containing single-quoted empty strings ('').
        $_rtoc_ai_html = <<<'RTOCAIBLOCK'
<div class="rtoc-ai-assist-bar" style="margin:-0.25rem 0 0.75rem 0;">
    <button type="button" id="rtoc-resolution-ai-btn" class="btn btn-sm btn-outline-secondary"
            style="display:inline-flex;align-items:center;gap:5px;font-size:0.82em;">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
            <path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>
        </svg>
        Generate with AI
    </button>
    <span id="rtoc-resolution-ai-status" style="margin-left:8px;font-size:0.82em;color:#666;display:none;">Generating...</span>
</div>
<script>
(function () {
    "use strict";
    function rtocStripMd(t) {
        return t.replace(/^#{1,6}\s+/gm,'').replace(/\*\*\*([^*]+)\*\*\*/g,'$1').replace(/\*\*([^*]+)\*\*/g,'$1').replace(/\*([^*\n]+)\*/g,'$1').replace(/__([^_]+)__/g,'$1').replace(/_([^_\n]+)_/g,'$1').replace(/^>\s+/gm,'').replace(/\n{3,}/g,'\n\n').trim();
    }
    function rtocResolutionAiInit() {
        var btn = document.getElementById("rtoc-resolution-ai-btn");
        if (!btn) return;
        btn.addEventListener("click", function () {
            var status = document.getElementById("rtoc-resolution-ai-status");
            var textarea = document.getElementById("id_resolution");
            var cat = document.querySelector("select[name='category']");
            var subcat = document.querySelector("select[name='subcategory']");
            var subj = document.querySelector("input[name='subject']");
            var desc = document.getElementById("id_description");
            var pri = document.querySelector("select[name='priority']");
            var st = document.querySelector("select[name='status']");

            status.style.display = "inline";
            btn.disabled = true;

            var params = new URLSearchParams({
                action: "generate_resolution",
                sesskey: M.cfg.sesskey,
                context_type: "complaint",
                category: cat ? cat.value : "",
                subcategory: subcat ? subcat.value : "",
                subject: subj ? subj.value : "",
                description: desc ? desc.value : "",
                priority: pri ? pri.value : "",
                status: st ? st.value : ""
            });

            fetch(M.cfg.wwwroot + "/local/rtocompliance/ajax.php", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: params.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                status.style.display = "none";
                if (data.success) {
                    if (textarea) { textarea.value = rtocStripMd(data.text); }
                } else {
                    alert(data.error || "AI generation failed. Check AI configuration.");
                }
            })
            .catch(function () {
                btn.disabled = false;
                status.style.display = "none";
                alert("AI request failed. Please try again.");
            });
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", rtocResolutionAiInit);
    } else {
        rtocResolutionAiInit();
    }
})();
</script>
RTOCAIBLOCK;
        $mform->addElement('html', $_rtoc_ai_html);

        $satisfactoryoptions = [
            '' => get_string('choosedots'),
            '1' => get_string('yes'),
            '0' => get_string('no'),
        ];
        $mform->addElement('select', 'outcomesatisfactory', get_string('outcome_satisfactory', 'local_rtocompliance'), $satisfactoryoptions);
        $mform->addHelpButton('outcomesatisfactory', 'outcomesatisfactory', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'issystemic', get_string('is_systemic', 'local_rtocompliance'));
        $mform->setDefault('issystemic', 0);
        $mform->addHelpButton('issystemic', 'issystemic', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'complaint_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        global $DB;

        if (!empty($data['id'])) {
            $existing = $DB->get_record('local_rtocompliance_complaints', ['reference' => $data['reference']]);
            if ($existing && $existing->id != $data['id']) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        } else {
            if ($DB->record_exists('local_rtocompliance_complaints', ['reference' => $data['reference']])) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        }

        return $errors;
    }
}
