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
 * RTO Compliance plugin — governance_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class governance_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $person = $this->_customdata['person'] ?? null;

        $mform->addElement('header', 'persondetails', get_string('governing_persons', 'local_rtocompliance'));
        $mform->addHelpButton('persondetails', 'governance_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'fullname', get_string('full_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('fullname', PARAM_TEXT);
        $mform->addRule('fullname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('fullname', 'governance_fullname', 'local_rtocompliance');

        $positionoptions = [
            'director' => get_string('position_director', 'local_rtocompliance'),
            'ceo' => get_string('position_ceo', 'local_rtocompliance'),
            'secretary' => get_string('position_secretary', 'local_rtocompliance'),
            'public_officer' => get_string('position_public_officer', 'local_rtocompliance'),
            'cfo' => 'Chief Financial Officer',
            'chair' => 'Board Chair/Chairperson',
            'high_managerial_agent' => 'High Managerial Agent',
            'rto_manager' => 'RTO Manager/Compliance Manager',
            'training_manager' => 'Training Manager',
            'quality_manager' => 'Quality/Audit Manager',
            'sole_trader' => 'Sole Trader/Owner',
            'partner' => 'Partner (Partnership)',
            'trustee' => 'Trustee',
            'other' => 'Other (specify in notes)',
        ];
        $mform->addElement('select', 'positiontype', get_string('position_type', 'local_rtocompliance'), $positionoptions);
        $mform->addRule('positiontype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('positiontype', 'positiontype', 'local_rtocompliance');

        $mform->addElement('text', 'positiontitle', get_string('position', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('positiontitle', PARAM_TEXT);
        $mform->addHelpButton('positiontitle', 'positiontitle', 'local_rtocompliance');

        $mform->addElement('text', 'email', get_string('email'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('email', PARAM_EMAIL);
        $mform->addHelpButton('email', 'governance_email', 'local_rtocompliance');

        $mform->addElement('text', 'phone', get_string('phone'), ['size' => 20, 'maxlength' => 30]);
        $mform->setType('phone', PARAM_TEXT);
        $mform->addHelpButton('phone', 'governance_phone', 'local_rtocompliance');

        $mform->addElement('date_selector', 'appointmentdate', get_string('appointment_date', 'local_rtocompliance'));
        $mform->addRule('appointmentdate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('appointmentdate', 'appointmentdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'cessationdate', get_string('cessation_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('cessationdate', 'cessationdate', 'local_rtocompliance');

        $mform->addElement('header', 'declarations', 'Fit & Proper Person Declaration');
        $mform->addHelpButton('declarations', 'declarations_header', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'fitproperdeclared', get_string('fit_proper_declared', 'local_rtocompliance'));
        $mform->setDefault('fitproperdeclared', 0);
        $mform->addHelpButton('fitproperdeclared', 'fitproperdeclared', 'local_rtocompliance');

        $mform->addElement('date_selector', 'fitproperdeclareddate', get_string('fit_proper_declared_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->hideIf('fitproperdeclareddate', 'fitproperdeclared', 'notchecked');
        $mform->addHelpButton('fitproperdeclareddate', 'fitproperdeclareddate', 'local_rtocompliance');

        $mform->addElement('header', 'suitability', 'Suitability Assessment');
        $mform->addHelpButton('suitability', 'suitability_header', 'local_rtocompliance');

        $mform->addElement('static', 'suitabilityhelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">
            <strong>ASQA Requirement:</strong> RTOs must assess the suitability of all high managerial agents before they take up their role. 
            This includes examining character, experience, qualifications, and any convictions/prohibitions that may affect their fitness.</div>');

        $mform->addElement('advcheckbox', 'suitabilityassessed', get_string('suitability_assessed', 'local_rtocompliance'));
        $mform->setDefault('suitabilityassessed', 0);
        $mform->addHelpButton('suitabilityassessed', 'suitabilityassessed', 'local_rtocompliance');

        $mform->addElement('date_selector', 'suitabilityassesseddate', get_string('suitability_assessed_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->hideIf('suitabilityassesseddate', 'suitabilityassessed', 'notchecked');
        $mform->addHelpButton('suitabilityassesseddate', 'suitabilityassesseddate', 'local_rtocompliance');

        $mform->addElement('text', 'suitabilityevidencefile', 'Evidence Document (filename)', ['size' => 80, 'maxlength' => 255, 'placeholder' => 'e.g., Suitability_Assessment_JohnSmith_2024.pdf']);
        $mform->setType('suitabilityevidencefile', PARAM_TEXT);
        $mform->hideIf('suitabilityevidencefile', 'suitabilityassessed', 'notchecked');

        $mform->addElement('header', 'policecheck', 'Police Check');
        $mform->addHelpButton('policecheck', 'policecheck_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'policecheckdate', get_string('police_check_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('policecheckdate', 'policecheckdate', 'local_rtocompliance');

        $policecheckstatusoptions = [
            '' => get_string('choosedots'),
            'clear' => 'Clear',
            'disclosures' => 'Disclosures Present',
            'pending' => 'Pending',
            'not_required' => 'Not Required',
        ];
        $mform->addElement('select', 'policecheckstatus', get_string('police_check_status', 'local_rtocompliance'), $policecheckstatusoptions);
        $mform->addHelpButton('policecheckstatus', 'policecheckstatus', 'local_rtocompliance');

        $statusoptions = [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'active');
        $mform->addHelpButton('status', 'governance_status', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'governance_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
