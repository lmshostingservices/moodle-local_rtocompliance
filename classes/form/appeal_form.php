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
 * RTO Compliance plugin — appeal_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class appeal_form extends \moodleform {
    protected function definition() {
        global $CFG, $DB;
        $mform = $this->_form;
        $appeal = $this->_customdata['appeal'] ?? null;

        $mform->addElement('header', 'appealdetails', get_string('appeal_details', 'local_rtocompliance'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'reference', get_string('appeal_reference', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('reference', PARAM_TEXT);
        $mform->addRule('reference', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reference', 'appeal_reference', 'local_rtocompliance');

        $complaints = $DB->get_records_menu('local_rtocompliance_complaints', null, 'reference', 'id, reference');
        $complaints = ['' => get_string('choosedots')] + $complaints;
        $mform->addElement('select', 'complaintid', get_string('linked_complaint', 'local_rtocompliance'), $complaints);
        $mform->addHelpButton('complaintid', 'linked_complaint', 'local_rtocompliance');

        $typeoptions = [
            'complaint_outcome' => get_string('appeal_type_complaint', 'local_rtocompliance'),
            'assessment_decision' => get_string('appeal_type_assessment', 'local_rtocompliance'),
            'enrolment' => get_string('appeal_type_enrolment', 'local_rtocompliance'),
            'fee' => get_string('appeal_type_fee', 'local_rtocompliance'),
            'other' => get_string('appeal_type_other', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'appealtype', get_string('appeal_type', 'local_rtocompliance'), $typeoptions);
        $mform->addRule('appealtype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('appealtype', 'appeal_type', 'local_rtocompliance');

        $mform->addElement('header', 'appellantinfo', get_string('appellant_information', 'local_rtocompliance'));

        $mform->addElement('text', 'appellantname', get_string('appellant_name', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('appellantname', PARAM_TEXT);
        $mform->addRule('appellantname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('appellantname', 'appellant_name', 'local_rtocompliance');

        $mform->addElement('text', 'appellantemail', get_string('appellant_email', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('appellantemail', PARAM_EMAIL);
        $mform->addHelpButton('appellantemail', 'appellant_email', 'local_rtocompliance');

        $mform->addElement('text', 'appellantphone', get_string('appellant_phone', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 30]);
        $mform->setType('appellantphone', PARAM_TEXT);
        $mform->addHelpButton('appellantphone', 'appellant_phone', 'local_rtocompliance');

        $mform->addElement('header', 'appealgrounds', get_string('appeal_grounds', 'local_rtocompliance'));

        $mform->addElement('textarea', 'groundsforappeal', get_string('grounds_for_appeal', 'local_rtocompliance'), ['rows' => 8, 'cols' => 80]);
        $mform->setType('groundsforappeal', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addRule('groundsforappeal', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('groundsforappeal', 'grounds_for_appeal', 'local_rtocompliance');

        $mform->addElement('html', '
<div class="rtoc-ai-assist-bar" style="margin:-0.25rem 0 0.75rem 0;">
    <button type="button" id="rtoc-grounds-ai-btn" class="btn btn-sm btn-outline-secondary"
            style="display:inline-flex;align-items:center;gap:5px;font-size:0.82em;">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
            <path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>
        </svg>
        Generate with AI
    </button>
    <span id="rtoc-grounds-ai-status" style="margin-left:8px;font-size:0.82em;color:#666;display:none;">Generating...</span>
</div>
<script>
(function () {
    "use strict";
    function rtocStripMd(t) {
        return t.replace(/^#{1,6}\s+/gm,"").replace(/\*\*\*([^*]+)\*\*\*/g,"$1").replace(/\*\*([^*]+)\*\*/g,"$1").replace(/\*([^*\n]+)\*/g,"$1").replace(/__([^_]+)__/g,"$1").replace(/_([^_\n]+)_/g,"$1").replace(/^>\s+/gm,"").replace(/\n{3,}/g,"\n\n").trim();
    }
    function rtocGroundsAiInit() {
        var btn = document.getElementById("rtoc-grounds-ai-btn");
        if (!btn) return;
        btn.addEventListener("click", function () {
            var status = document.getElementById("rtoc-grounds-ai-status");
            var textarea = document.getElementById("id_groundsforappeal");
            var appealtype = document.querySelector("select[name=\'appealtype\']");
            var ref = document.querySelector("input[name=\'reference\']");
            var origdec = document.getElementById("id_originaldecision");

            status.style.display = "inline";
            btn.disabled = true;

            var params = new URLSearchParams({
                action: "generate_resolution",
                sesskey: M.cfg.sesskey,
                context_type: "grounds_for_appeal",
                appealtype: appealtype ? appealtype.value : "",
                reference: ref ? ref.value : "",
                originaldecision: origdec ? origdec.value : ""
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
        document.addEventListener("DOMContentLoaded", rtocGroundsAiInit);
    } else {
        rtocGroundsAiInit();
    }
})();
</script>');

        $mform->addElement('textarea', 'originaldecision', get_string('original_decision', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('originaldecision', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('originaldecision', 'original_decision', 'local_rtocompliance');

        // Standard 2.8 independence: identify the original decision-maker.
        $mform->addElement('text', 'originaldecisionmaker', 'Original decision-maker (who made the decision being appealed)', ['size' => 50, 'maxlength' => 255]);
        $mform->setType('originaldecisionmaker', PARAM_TEXT);

        $mform->addElement('date_selector', 'originaldecisiondate', get_string('original_decision_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('originaldecisiondate', 'original_decision_date', 'local_rtocompliance');

        $mform->addElement('header', 'appealprocessing', get_string('appeal_processing', 'local_rtocompliance'));

        $statusoptions = [
            'lodged' => get_string('appeal_status_lodged', 'local_rtocompliance'),
            'reviewing' => get_string('appeal_status_reviewing', 'local_rtocompliance'),
            'hearing' => get_string('appeal_status_hearing', 'local_rtocompliance'),
            'decided' => get_string('appeal_status_decided', 'local_rtocompliance'),
            'closed' => get_string('appeal_status_closed', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'lodged');
        $mform->addHelpButton('status', 'appeal_status', 'local_rtocompliance');

        $mform->addElement('date_selector', 'datelodged', get_string('date_lodged', 'local_rtocompliance'));
        $mform->addRule('datelodged', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('datelodged', 'date_lodged', 'local_rtocompliance');

        $mform->addElement('date_selector', 'dateacknowledged', get_string('date_acknowledged', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('dateacknowledged', 'date_acknowledged', 'local_rtocompliance');

        $mform->addElement('date_selector', 'hearingdate', get_string('hearing_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('hearingdate', 'hearing_date', 'local_rtocompliance');

        $mform->addElement('textarea', 'panelmembers', get_string('panel_members', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('panelmembers', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('panelmembers', 'panel_members', 'local_rtocompliance');

        // Standard 2.8 independence confirmation.
        $mform->addElement('advcheckbox', 'independenceconfirmed', 'I confirm the reviewer/panel is independent of the original decision-maker');
        $mform->setDefault('independenceconfirmed', 0);

        $mform->addElement('header', 'appealoutcome', get_string('appeal_outcome', 'local_rtocompliance'));

        $outcomeoptions = [
            '' => get_string('choosedots'),
            'upheld' => get_string('outcome_upheld', 'local_rtocompliance'),
            'partially_upheld' => get_string('outcome_partially_upheld', 'local_rtocompliance'),
            'not_upheld' => get_string('outcome_not_upheld', 'local_rtocompliance'),
            'withdrawn' => get_string('outcome_withdrawn', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'outcome', get_string('outcome', 'local_rtocompliance'), $outcomeoptions);
        $mform->addHelpButton('outcome', 'appeal_outcome', 'local_rtocompliance');

        $mform->addElement('textarea', 'outcomereason', get_string('outcome_reason', 'local_rtocompliance'), ['rows' => 5, 'cols' => 80]);
        $mform->setType('outcomereason', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('outcomereason', 'outcome_reason', 'local_rtocompliance');

        $mform->addElement('html', '
<div class="rtoc-ai-assist-bar" style="margin:-0.25rem 0 0.75rem 0;">
    <button type="button" id="rtoc-outcomereason-ai-btn" class="btn btn-sm btn-outline-secondary"
            style="display:inline-flex;align-items:center;gap:5px;font-size:0.82em;">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
            <path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>
        </svg>
        Generate with AI
    </button>
    <span id="rtoc-outcomereason-ai-status" style="margin-left:8px;font-size:0.82em;color:#666;display:none;">Generating...</span>
</div>
<script>
(function () {
    "use strict";
    function rtocStripMd(t) {
        return t.replace(/^#{1,6}\s+/gm,"").replace(/\*\*\*([^*]+)\*\*\*/g,"$1").replace(/\*\*([^*]+)\*\*/g,"$1").replace(/\*([^*\n]+)\*/g,"$1").replace(/__([^_]+)__/g,"$1").replace(/_([^_\n]+)_/g,"$1").replace(/^>\s+/gm,"").replace(/\n{3,}/g,"\n\n").trim();
    }
    function rtocOutcomeReasonAiInit() {
        var btn = document.getElementById("rtoc-outcomereason-ai-btn");
        if (!btn) return;
        btn.addEventListener("click", function () {
            var status = document.getElementById("rtoc-outcomereason-ai-status");
            var textarea = document.getElementById("id_outcomereason");
            var appealtype = document.querySelector("select[name=\'appealtype\']");
            var grounds = document.getElementById("id_groundsforappeal");
            var origdec = document.getElementById("id_originaldecision");
            var outcome = document.querySelector("select[name=\'outcome\']");

            status.style.display = "inline";
            btn.disabled = true;

            var params = new URLSearchParams({
                action: "generate_resolution",
                sesskey: M.cfg.sesskey,
                context_type: "appeal",
                appealtype: appealtype ? appealtype.value : "",
                groundsforappeal: grounds ? grounds.value : "",
                originaldecision: origdec ? origdec.value : "",
                status: outcome ? outcome.value : ""
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
        document.addEventListener("DOMContentLoaded", rtocOutcomeReasonAiInit);
    } else {
        rtocOutcomeReasonAiInit();
    }
})();
</script>');

        $mform->addElement('date_selector', 'decisiondate', get_string('decision_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('decisiondate', 'decision_date', 'local_rtocompliance');

        // Standard 2.8 record correction (assessment-decision appeals only).
        $mform->addElement('advcheckbox', 'resultcorrected', 'Underlying record corrected');
        $mform->setDefault('resultcorrected', 0);
        $mform->hideIf('resultcorrected', 'appealtype', 'neq', 'assessment_decision');

        $mform->addElement('header', 'externalreview', get_string('external_review', 'local_rtocompliance'));

        $mform->addElement('advcheckbox', 'externalreviewoffered', get_string('external_review_offered', 'local_rtocompliance'));
        $mform->setDefault('externalreviewoffered', 0);
        $mform->addHelpButton('externalreviewoffered', 'external_review_offered', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'externalreviewtaken', get_string('external_review_taken', 'local_rtocompliance'));
        $mform->setDefault('externalreviewtaken', 0);
        $mform->hideIf('externalreviewtaken', 'externalreviewoffered', 'notchecked');
        $mform->addHelpButton('externalreviewtaken', 'external_review_taken', 'local_rtocompliance');

        $mform->addElement('text', 'externalreviewbody', get_string('external_review_body', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 100]);
        $mform->setType('externalreviewbody', PARAM_TEXT);
        $mform->hideIf('externalreviewbody', 'externalreviewoffered', 'notchecked');
        $mform->addHelpButton('externalreviewbody', 'external_review_body', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('notes', 'appeal_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        global $DB;

        if (!empty($data['id'])) {
            $existing = $DB->get_record('local_rtocompliance_appeals', ['reference' => $data['reference']]);
            if ($existing && $existing->id != $data['id']) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        } else {
            if ($DB->record_exists('local_rtocompliance_appeals', ['reference' => $data['reference']])) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        }

        return $errors;
    }
}
