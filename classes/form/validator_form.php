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
 * RTO Compliance plugin — validator_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class validator_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $validator = $this->_customdata['validator'] ?? null;

        $mform->addElement('header', 'validatordetails', get_string('validators_register', 'local_rtocompliance'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'fullname', get_string('full_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('fullname', PARAM_TEXT);
        $mform->addRule('fullname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('fullname', 'validator_fullname', 'local_rtocompliance');

        $mform->addElement('text', 'email', get_string('email'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('email', PARAM_EMAIL);
        $mform->addHelpButton('email', 'validator_email', 'local_rtocompliance');

        $mform->addElement('text', 'phone', get_string('phone'), ['size' => 20, 'maxlength' => 30]);
        $mform->setType('phone', PARAM_TEXT);
        $mform->addHelpButton('phone', 'validator_phone', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'isinternal', get_string('is_internal', 'local_rtocompliance'));
        $mform->setDefault('isinternal', 1);
        $mform->addHelpButton('isinternal', 'is_internal', 'local_rtocompliance');

        $mform->addElement('text', 'organisation', get_string('organisation', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('organisation', PARAM_TEXT);
        $mform->hideIf('organisation', 'isinternal', 'checked');
        $mform->addHelpButton('organisation', 'validator_organisation', 'local_rtocompliance');

        $roleoptions = [
            '3a' => get_string('role_3a', 'local_rtocompliance'),
            '3b' => get_string('role_3b', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'roletype', get_string('role_type', 'local_rtocompliance'), $roleoptions);
        $mform->addRule('roletype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('roletype', 'role_type', 'local_rtocompliance');

        $mform->addElement('header', 'credentials', 'Credentials');

        $mform->addElement('text', 'taecredential', get_string('tae_credential', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 50]);
        $mform->setType('taecredential', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('taecredential', 'validator_taecredential', 'local_rtocompliance');

        $mform->addElement('date_selector', 'taedateachieved', get_string('tae_date_achieved', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('taedateachieved', 'tae_date_achieved', 'local_rtocompliance');

        $mform->addElement('textarea', 'vocationalqualifications', get_string('vocational_qualifications', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('vocationalqualifications', PARAM_RAW);
        $mform->addHelpButton('vocationalqualifications', 'validator_vocquals', 'local_rtocompliance');

        $mform->addElement('header', 'experience', 'Industry Experience');

        $mform->addElement('textarea', 'industryexperience', get_string('industry_experience', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('industryexperience', PARAM_RAW);
        $mform->addHelpButton('industryexperience', 'validator_industryexp', 'local_rtocompliance');

        $mform->addElement('text', 'industryexperienceyears', get_string('industry_experience_years', 'local_rtocompliance'), ['size' => 5]);
        $mform->setType('industryexperienceyears', PARAM_INT);
        $mform->addHelpButton('industryexperienceyears', 'validator_expyears', 'local_rtocompliance');

        $mform->addElement('textarea', 'currentindustryengagement', get_string('current_industry_engagement', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('currentindustryengagement', PARAM_RAW);
        $mform->addHelpButton('currentindustryengagement', 'current_industry_engagement', 'local_rtocompliance');

        $mform->addElement('textarea', 'specialisations', get_string('specialisations', 'local_rtocompliance'), ['rows' => 2, 'cols' => 80]);
        $mform->setType('specialisations', PARAM_RAW);
        $mform->addHelpButton('specialisations', 'validator_specialisations', 'local_rtocompliance');

        $mform->addElement('header', 'validationhistory', 'Validation History');

        $mform->addElement('text', 'validationsled', get_string('validations_led', 'local_rtocompliance'), ['size' => 5]);
        $mform->setType('validationsled', PARAM_INT);
        $mform->setDefault('validationsled', 0);
        $mform->addHelpButton('validationsled', 'validations_led', 'local_rtocompliance');

        $mform->addElement('text', 'validationsparticipated', get_string('validations_participated', 'local_rtocompliance'), ['size' => 5]);
        $mform->setType('validationsparticipated', PARAM_INT);
        $mform->setDefault('validationsparticipated', 0);
        $mform->addHelpButton('validationsparticipated', 'validations_participated', 'local_rtocompliance');

        $mform->addElement('date_selector', 'lastvalidationdate', get_string('last_validation_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('lastvalidationdate', 'last_validation_date', 'local_rtocompliance');

        $statusoptions = [
            'active' => 'Active',
            'inactive' => 'Inactive',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'active');
        $mform->addHelpButton('status', 'validator_status', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'validator_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
