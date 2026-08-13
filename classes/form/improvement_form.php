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

namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class improvement_form extends \moodleform {
    protected function definition() {
        global $CFG, $DB;
        $mform = $this->_form;
        $improvement = $this->_customdata['improvement'] ?? null;

        $mform->addElement('header', 'improvementdetails', get_string('improvement_details', 'local_rtocompliance'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'reference', get_string('improvement_reference', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('reference', PARAM_ALPHANUMEXT);
        $mform->addRule('reference', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reference', 'improvement_reference', 'local_rtocompliance');

        $mform->addElement('text', 'title', get_string('improvement_title', 'local_rtocompliance'), ['size' => 80, 'maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('title', 'improvement_title', 'local_rtocompliance');

        $mform->addElement('textarea', 'description', get_string('improvement_description', 'local_rtocompliance'), ['rows' => 6, 'cols' => 80]);
        $mform->setType('description', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addRule('description', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('description', 'improvement_description', 'local_rtocompliance');

        $sourcetypeoptions = [
            'complaint' => get_string('source_complaint', 'local_rtocompliance'),
            'appeal' => get_string('source_appeal', 'local_rtocompliance'),
            'validation' => get_string('source_validation', 'local_rtocompliance'),
            'audit' => get_string('source_audit', 'local_rtocompliance'),
            'feedback' => get_string('source_feedback', 'local_rtocompliance'),
            'survey' => get_string('source_survey', 'local_rtocompliance'),
            'incident' => get_string('source_incident', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'sourcetype', get_string('source_type', 'local_rtocompliance'), $sourcetypeoptions);
        $mform->addRule('sourcetype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('sourcetype', 'source_type', 'local_rtocompliance');
        
        $mform->addElement('static', 'linkinghelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">If this improvement action originated from a complaint, appeal or validation finding, you can link it below for audit trail purposes.</div>');
        
        $complaintsoptions = ['' => 'No linked complaint'];
        $tableexists = $DB->get_manager()->table_exists('local_rtocompliance_complaints');
        if ($tableexists) {
            $complaints = $DB->get_records('local_rtocompliance_complaints', [], 'timecreated DESC', 'id, reference, subject', 0, 50);
            foreach ($complaints as $c) {
                $complaintsoptions[$c->id] = $c->reference . ' - ' . substr($c->subject, 0, 40) . (strlen($c->subject) > 40 ? '...' : '');
            }
        }
        $mform->addElement('select', 'linkedcomplaintid', 'Linked Complaint', $complaintsoptions);
        $mform->hideIf('linkedcomplaintid', 'sourcetype', 'neq', 'complaint');
        $mform->addHelpButton('linkedcomplaintid', 'linked_complaint_improvement', 'local_rtocompliance');
        
        $validationsoptions = ['' => 'No linked validation'];
        $valtableexists = $DB->get_manager()->table_exists('local_rtocompliance_validations');
        if ($valtableexists) {
            $validations = $DB->get_records('local_rtocompliance_validations', [], 'timecreated DESC', 'id, reference, productname', 0, 50);
            foreach ($validations as $v) {
                $validationsoptions[$v->id] = $v->reference . ' - ' . substr($v->productname, 0, 40) . (strlen($v->productname) > 40 ? '...' : '');
            }
        }
        $mform->addElement('select', 'linkedvalidationid', 'Linked Validation', $validationsoptions);
        $mform->hideIf('linkedvalidationid', 'sourcetype', 'neq', 'validation');
        $mform->addHelpButton('linkedvalidationid', 'linked_validation_improvement', 'local_rtocompliance');

        $categoryoptions = [
            'training' => get_string('category_training', 'local_rtocompliance'),
            'assessment' => get_string('category_assessment', 'local_rtocompliance'),
            'service' => get_string('category_service', 'local_rtocompliance'),
            'compliance' => get_string('category_compliance', 'local_rtocompliance'),
            'governance' => get_string('category_governance', 'local_rtocompliance'),
            'other' => get_string('category_other', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'category', get_string('improvement_category', 'local_rtocompliance'), $categoryoptions);
        $mform->addRule('category', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('category', 'improvement_category', 'local_rtocompliance');

        $priorityoptions = [
            'low' => get_string('priority_low', 'local_rtocompliance'),
            'medium' => get_string('priority_medium', 'local_rtocompliance'),
            'high' => get_string('priority_high', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'priority', get_string('priority', 'local_rtocompliance'), $priorityoptions);
        $mform->setDefault('priority', 'medium');
        $mform->addHelpButton('priority', 'improvement_priority', 'local_rtocompliance');

        $statusoptions = [
            'identified' => get_string('improvement_status_identified', 'local_rtocompliance'),
            'planned' => get_string('improvement_status_planned', 'local_rtocompliance'),
            'inprogress' => get_string('improvement_status_inprogress', 'local_rtocompliance'),
            'completed' => get_string('improvement_status_completed', 'local_rtocompliance'),
            'verified' => get_string('improvement_status_verified', 'local_rtocompliance'),
            'closed' => get_string('improvement_status_closed', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'identified');
        $mform->addHelpButton('status', 'improvement_status', 'local_rtocompliance');

        $mform->addElement('header', 'timeline', get_string('timeline', 'local_rtocompliance'));

        $mform->addElement('date_selector', 'dateidentified', get_string('date_identified', 'local_rtocompliance'));
        $mform->addRule('dateidentified', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('dateidentified', 'date_identified', 'local_rtocompliance');

        $mform->addElement('date_selector', 'targetdate', get_string('target_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('targetdate', 'target_date', 'local_rtocompliance');

        $mform->addElement('date_selector', 'completiondate', get_string('completion_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('completiondate', 'completion_date', 'local_rtocompliance');

        $mform->addElement('header', 'actionplan_hdr', get_string('action_plan', 'local_rtocompliance'));

        $mform->addElement('textarea', 'actionplan', get_string('action_plan_details', 'local_rtocompliance'), ['rows' => 6, 'cols' => 80]);
        $mform->setType('actionplan', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('actionplan', 'action_plan_details', 'local_rtocompliance');

        $mform->addElement('textarea', 'outcome', get_string('improvement_outcome', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('outcome', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('outcome', 'improvement_outcome', 'local_rtocompliance');

        $mform->addElement('header', 'verification', get_string('verification', 'local_rtocompliance'));

        $mform->addElement('advcheckbox', 'effectivenessverified', get_string('effectiveness_verified', 'local_rtocompliance'));
        $mform->setDefault('effectivenessverified', 0);
        $mform->addHelpButton('effectivenessverified', 'effectiveness_verified', 'local_rtocompliance');

        $mform->addElement('date_selector', 'verificationdate', get_string('verification_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->hideIf('verificationdate', 'effectivenessverified', 'notchecked');
        $mform->addHelpButton('verificationdate', 'verification_date', 'local_rtocompliance');

        $mform->addElement('textarea', 'verificationmethod', get_string('verification_method', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('verificationmethod', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->hideIf('verificationmethod', 'effectivenessverified', 'notchecked');
        $mform->addHelpButton('verificationmethod', 'verification_method', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $mform->addHelpButton('notes', 'improvement_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        global $DB;

        if (!empty($data['id'])) {
            $existing = $DB->get_record('local_rtocompliance_improvements', ['reference' => $data['reference']]);
            if ($existing && $existing->id != $data['id']) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        } else {
            if ($DB->record_exists('local_rtocompliance_improvements', ['reference' => $data['reference']])) {
                $errors['reference'] = get_string('error_duplicate_reference', 'local_rtocompliance');
            }
        }

        return $errors;
    }
}
