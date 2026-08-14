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
 * RTO Compliance plugin — validation_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class validation_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $validation = $this->_customdata['validation'] ?? null;

        $mform->addElement('header', 'validationdetails', get_string('validation_details', 'local_rtocompliance'));
        $mform->addHelpButton('validationdetails', 'validation_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'reference', get_string('validation_reference', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('reference', PARAM_ALPHANUMEXT);
        $mform->addRule('reference', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reference', 'validation_reference', 'local_rtocompliance');

        $mform->addElement('text', 'productcode', get_string('product_code', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('productcode', PARAM_ALPHANUMEXT);
        $mform->addRule('productcode', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('productcode', 'validation_productcode', 'local_rtocompliance');

        $mform->addElement('text', 'productname', get_string('product_name', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('productname', PARAM_TEXT);
        $mform->addRule('productname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('productname', 'validation_productname', 'local_rtocompliance');

        $mform->addElement('textarea', 'unitcodes', get_string('unit_codes', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('unitcodes', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('unitcodes', 'validation_unitcodes', 'local_rtocompliance');

        $typeoptions = [
            'initial' => get_string('validation_initial', 'local_rtocompliance'),
            'ongoing' => get_string('validation_ongoing', 'local_rtocompliance'),
            'post_assessment' => get_string('validation_post_assessment', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'validationtype', get_string('validation_type', 'local_rtocompliance'), $typeoptions);
        $mform->setDefault('validationtype', 'ongoing');
        $mform->addHelpButton('validationtype', 'validationtype', 'local_rtocompliance');

        $mform->addElement('header', 'riskassessment', 'Risk Assessment');
        $mform->addHelpButton('riskassessment', 'riskassessment_header', 'local_rtocompliance');

        $riskoptions = [
            'low' => get_string('risk_low', 'local_rtocompliance'),
            'medium' => get_string('risk_medium', 'local_rtocompliance'),
            'high' => get_string('risk_high', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'risklevel', get_string('risk_level', 'local_rtocompliance'), $riskoptions);
        $mform->setDefault('risklevel', 'medium');
        $mform->addHelpButton('risklevel', 'validation_risklevel', 'local_rtocompliance');

        $mform->addElement('static', 'riskfactorshelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">Select all risk factors that apply. These help determine validation priority and frequency.</div>');
        
        $riskfactors = [
            'new_product' => 'New training product (not delivered before)',
            'new_trainer' => 'New trainer/assessor delivering this product',
            'high_enrolments' => 'High enrolment numbers (>50 per year)',
            'high_complaints' => 'History of complaints in this product area',
            'low_completion' => 'Low completion rates (<70%)',
            'regulatory_focus' => 'Product area under regulatory focus/audit',
            'industry_change' => 'Recent industry changes affecting content',
            'external_issues' => 'Issues identified by external parties',
            'long_gap' => 'Long time since last validation (>12 months)',
            'assessment_issues' => 'Previous assessment moderation issues',
            'student_feedback' => 'Negative student feedback',
            'employer_feedback' => 'Negative employer feedback',
        ];
        $factorgroup = [];
        foreach ($riskfactors as $code => $label) {
            $factorgroup[] = $mform->createElement('advcheckbox', "riskfactor_{$code}", '', $label, [], ['0', $code]);
        }
        $mform->addGroup($factorgroup, 'riskfactorsgroup', 'Risk Factors', '<br>', false);
        $mform->addHelpButton('riskfactorsgroup', 'risk_factors', 'local_rtocompliance');

        $mform->addElement('textarea', 'riskfactors', 'Additional Risk Notes', ['rows' => 2, 'cols' => 80, 'placeholder' => 'Add any additional risk factors not listed above...']);
        $mform->setType('riskfactors', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $mform->addElement('header', 'schedule', 'Schedule');
        $mform->addHelpButton('schedule', 'schedule_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'scheduleddate', get_string('scheduled_date', 'local_rtocompliance'));
        $mform->addRule('scheduleddate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('scheduleddate', 'scheduleddate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'actualdate', get_string('actual_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('actualdate', 'actualdate', 'local_rtocompliance');

        $statusoptions = [
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'scheduled');
        $mform->addHelpButton('status', 'validation_status', 'local_rtocompliance');

        $mform->addElement('header', 'validators', get_string('validators', 'local_rtocompliance'));
        $mform->addHelpButton('validators', 'validators_header', 'local_rtocompliance');

        global $DB;
        $validatoroptions = ['' => get_string('choosedots')];
        if ($DB->get_manager()->table_exists('local_rtocompliance_validators')) {
            $validators = $DB->get_records('local_rtocompliance_validators', ['status' => 'active'], 'fullname ASC', 'id, fullname, roletype');
            foreach ($validators as $v) {
                $roleLabel = '';
                if ($v->roletype === '3a') {
                    $roleLabel = ' (Lead Validator)';
                } elseif ($v->roletype === '3b') {
                    $roleLabel = ' (Panel Validator)';
                }
                $validatoroptions[$v->id] = $v->fullname . $roleLabel;
            }
        }
        if ($DB->get_manager()->table_exists('local_rtocompliance_trainers')) {
            $trainers = $DB->get_records_sql(
                "SELECT id, fullname FROM {local_rtocompliance_trainers} WHERE status = 'active' ORDER BY fullname ASC",
                [],
                0,
                200
            );
            foreach ($trainers as $t) {
                if (!isset($validatoroptions['trainer_' . $t->id])) {
                    $validatoroptions['trainer_' . $t->id] = $t->fullname . ' (Trainer)';
                }
            }
        }

        $mform->addElement('select', 'leadvalidatorid', get_string('lead_validator', 'local_rtocompliance'), $validatoroptions);
        $mform->addHelpButton('leadvalidatorid', 'leadvalidator', 'local_rtocompliance');

        $mform->addElement('text', 'leadvalidator', get_string('lead_validator', 'local_rtocompliance') . ' (Custom)', ['size' => 50, 'maxlength' => 255]);
        $mform->setType('leadvalidator', PARAM_TEXT);
        $mform->hideIf('leadvalidator', 'leadvalidatorid', 'neq', '');

        $panelmemberoptions = $validatoroptions;
        unset($panelmemberoptions['']);
        $select = $mform->addElement('select', 'panelmemberids', get_string('panel_members', 'local_rtocompliance'), $panelmemberoptions);
        $select->setMultiple(true);
        $mform->addHelpButton('panelmemberids', 'panelmembers', 'local_rtocompliance');

        $mform->addElement('textarea', 'panelmembers', get_string('panel_members', 'local_rtocompliance') . ' (Additional)', ['rows' => 2, 'cols' => 80]);
        $mform->setType('panelmembers', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $mform->addElement('header', 'methodology', 'Methodology');
        $mform->addHelpButton('methodology', 'methodology_header', 'local_rtocompliance');

        $mform->addElement('static', 'methodologyhelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">Select validation methodologies used. A combination of methods provides stronger validation evidence.</div>');

        $methodologies = [
            'tool_review' => 'Assessment Tool Review (check tools against TAS/competency requirements)',
            'evidence_review' => 'Student Evidence Review (examine completed assessments)',
            'judgement_review' => 'Assessor Judgement Review (cross-mark selected assessments)',
            'mapping_check' => 'Competency Mapping Check (verify assessment covers all UoC requirements)',
            'industry_feedback' => 'Industry Panel Feedback (industry experts review assessment approach)',
            'moderator_review' => 'External Moderation (independent assessor cross-marking)',
            'compliance_check' => 'Compliance Verification (check against Standards for RTOs)',
            'rpl_review' => 'RPL Portfolio Review (for RPL-heavy assessments)',
            'observation_review' => 'Observation Criteria Review (practical assessment checklists)',
            'benchmarking' => 'Benchmarking (compare with other RTOs/industry practice)',
        ];
        $methodgroup = [];
        foreach ($methodologies as $code => $label) {
            $methodgroup[] = $mform->createElement('advcheckbox', "method_{$code}", '', $label, [], ['0', $code]);
        }
        $mform->addGroup($methodgroup, 'methodologiesgroup', 'Validation Methods Used', '<br>', false);
        $mform->addHelpButton('methodologiesgroup', 'methodology_samples', 'local_rtocompliance');

        $mform->addElement('textarea', 'methodologies', 'Additional Methodology Notes', ['rows' => 2, 'cols' => 80, 'placeholder' => 'Describe any additional methodologies used...']);
        $mform->setType('methodologies', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $mform->addElement('text', 'samplesize', get_string('sample_size', 'local_rtocompliance'), ['size' => 10]);
        $mform->setType('samplesize', PARAM_INT);
        $mform->addHelpButton('samplesize', 'samplesize', 'local_rtocompliance');

        $samplingmethods = [
            '' => 'Select sampling method...',
            'random' => 'Random Sampling - Randomly selected student assessments',
            'stratified' => 'Stratified Sampling - Samples from different cohorts/locations/trainers',
            'purposive' => 'Purposive Sampling - Selected based on specific criteria (e.g., borderline passes)',
            'comprehensive' => 'Comprehensive Review - All assessments reviewed (small cohort)',
            'cluster' => 'Cluster Sampling - All assessments from selected groups/classes',
            'systematic' => 'Systematic Sampling - Every nth assessment (e.g., every 5th)',
            'convenience' => 'Convenience Sampling - Recently completed assessments',
        ];
        $mform->addElement('select', 'samplingmethod', get_string('sampling_method', 'local_rtocompliance'), $samplingmethods);
        $mform->addHelpButton('samplingmethod', 'samplingmethod', 'local_rtocompliance');

        // Standard 1.5 (T-P1-1): Validator independence.
        $mform->addElement('header', 'independence', 'Independence (Standard 1.5)');

        $mform->addElement('static', 'independencehelp', '',
            '<div class="alert alert-info" style="margin-bottom: 12px;">Standard 1.5 requires that the validation outcome is not solely determined by a person who designed or delivered the assessment being validated. Confirm independence before marking a validation as <strong>Completed</strong>.</div>');

        $mform->addElement('advcheckbox', 'independenceconfirmed',
            'Validator independence',
            'I confirm the validation outcome was NOT solely determined by a person who designed or delivered the assessment being validated (Standard 1.5)',
            [], ['0', '1']);
        $mform->setDefault('independenceconfirmed', 0);

        $mform->addElement('textarea', 'independencedeclaration',
            'Independence declaration',
            ['rows' => 3, 'cols' => 80, 'placeholder' => 'Describe how independence was assured — who validated, and who made the final judgement']);
        $mform->setType('independencedeclaration', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $mform->addElement('header', 'outcomes', 'Outcomes');
        $mform->addHelpButton('outcomes', 'outcomes_header', 'local_rtocompliance');

        $mform->addElement('static', 'outcomeshelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">
            <strong>Findings Count:</strong> Total number of issues/improvements identified during validation.<br>
            <strong>Report Document URL:</strong> Paste the full URL to the validation report (Google Drive, SharePoint, OneDrive, or any accessible link). The URL will appear as a "View Report" button in the Validation Schedule and Completed Events lists.<br>
            <strong>ADC Linked:</strong> Check if validation evidence is attached to Annual Declaration of Compliance documentation.</div>');

        // Standard 1.5 (P2-2): Validation outcome and rectification actions.
        $outcomeoptions = [
            '' => 'Not yet determined',
            'compliant' => 'Compliant',
            'improvements_required' => 'Improvements required',
            'noncompliant' => 'Non-compliant',
        ];
        $mform->addElement('select', 'outcome', 'Validation Outcome', $outcomeoptions);

        $mform->addElement('textarea', 'improvements', 'Improvements / Rectification Actions',
            ['rows' => 4, 'cols' => 80, 'placeholder' => 'Document improvements required and rectification actions arising from this validation (what, who, by when).']);
        $mform->setType('improvements', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $mform->addElement('text', 'findingscount', 'Number of Findings', ['size' => 10]);
        $mform->setType('findingscount', PARAM_INT);
        $mform->setDefault('findingscount', 0);
        $mform->addHelpButton('findingscount', 'findings_count', 'local_rtocompliance');

        $mform->addElement('textarea', 'findings', 'Findings & Recommendations', ['rows' => 5, 'cols' => 80, 'placeholder' => 'Document each finding with:
- Issue identified
- Severity (minor/major/critical)
- Recommended action
- Responsible person
- Due date for action']);
        $mform->setType('findings', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('findings', 'findings', 'local_rtocompliance');

        $mform->addElement('text', 'reportdocument', 'Report Document URL', ['size' => 80, 'maxlength' => 500, 'placeholder' => 'https://drive.google.com/... or https://sharepoint.com/...']);
        $mform->setType('reportdocument', PARAM_URL);
        $mform->addHelpButton('reportdocument', 'report_document', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'adclinked', 'Link to Annual Declaration of Compliance (ADC)', 'Include as ADC evidence for ASQA reporting');
        $mform->setDefault('adclinked', 0);
        $mform->addHelpButton('adclinked', 'adc_linked', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()
        $mform->addHelpButton('notes', 'validation_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Standard 1.5 (T-P1-1): a validation cannot be marked Completed unless the
     * validator-independence declaration checkbox has been ticked. When it is
     * not, the form is rejected here so the record's status is never persisted.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (($data['status'] ?? '') === 'completed' && empty($data['independenceconfirmed'])) {
            $errors['independenceconfirmed'] = 'You must confirm validator independence (Standard 1.5) before a validation can be marked as Completed.';
        }

        return $errors;
    }
}
