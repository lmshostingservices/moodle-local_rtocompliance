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

use local_rtocompliance\avetmiss_codes;

class student_profile_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $student = $this->_customdata['student'] ?? null;

        $mform->addElement('header', 'personaldetails', get_string('personaldetails', 'local_rtocompliance'));
        $mform->addHelpButton('personaldetails', 'personaldetails', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('text', 'clientid', get_string('clientid', 'local_rtocompliance'), ['size' => 15, 'maxlength' => 10]);
        $mform->setType('clientid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('clientid', 'clientid', 'local_rtocompliance');

        $mform->addElement('text', 'usi', get_string('usi', 'local_rtocompliance'), ['size' => 15, 'maxlength' => 10]);
        $mform->setType('usi', PARAM_ALPHANUMEXT);
        $mform->addRule('usi', get_string('error_usi_format', 'local_rtocompliance'), 'maxlength', 10, 'client');
        $mform->addHelpButton('usi', 'usi', 'local_rtocompliance');

        $mform->addElement('date_selector', 'dateofbirth', get_string('dateofbirth', 'local_rtocompliance'), [
            'startyear' => 1920,
            'stopyear' => date('Y') - 14,
            'optional' => true,
        ]);
        $mform->addHelpButton('dateofbirth', 'dateofbirth', 'local_rtocompliance');

        $sexoptions = avetmiss_codes::get_sex_codes();
        $mform->addElement('select', 'sex', get_string('sex', 'local_rtocompliance'), $sexoptions);
        $mform->setDefault('sex', '@');
        $mform->addHelpButton('sex', 'sex', 'local_rtocompliance');

        $mform->addElement('header', 'addressdetails', get_string('addressdetails', 'local_rtocompliance'));
        $mform->addHelpButton('addressdetails', 'addressdetails', 'local_rtocompliance');

        $mform->addElement('text', 'buildingname', get_string('buildingname', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 50]);
        $mform->setType('buildingname', PARAM_TEXT);
        $mform->addHelpButton('buildingname', 'buildingname', 'local_rtocompliance');

        $mform->addElement('text', 'unitno', get_string('unitno', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 30]);
        $mform->setType('unitno', PARAM_TEXT);
        $mform->addHelpButton('unitno', 'unitno', 'local_rtocompliance');

        $mform->addElement('text', 'streetno', get_string('streetno', 'local_rtocompliance'), ['size' => 15, 'maxlength' => 15]);
        $mform->setType('streetno', PARAM_TEXT);
        $mform->addHelpButton('streetno', 'streetno', 'local_rtocompliance');

        $mform->addElement('text', 'streetname', get_string('streetname', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 70]);
        $mform->setType('streetname', PARAM_TEXT);
        $mform->addHelpButton('streetname', 'streetname', 'local_rtocompliance');

        $mform->addElement('text', 'suburb', get_string('residentialsuburb', 'local_rtocompliance'), ['size' => 40, 'maxlength' => 50]);
        $mform->setType('suburb', PARAM_TEXT);
        $mform->addHelpButton('suburb', 'residentialsuburb', 'local_rtocompliance');

        $mform->addElement('text', 'postcode', get_string('residentialpostcode', 'local_rtocompliance'), ['size' => 6, 'maxlength' => 4]);
        $mform->setType('postcode', PARAM_TEXT);
        $mform->addRule('postcode', get_string('error_postcode_format', 'local_rtocompliance'), 'maxlength', 4, 'client');
        $mform->addHelpButton('postcode', 'residentialpostcode', 'local_rtocompliance');

        $stateoptions = ['' => get_string('choosedots')] + avetmiss_codes::get_state_codes();
        $mform->addElement('select', 'statecode', get_string('residentialstate', 'local_rtocompliance'), $stateoptions);
        $mform->addHelpButton('statecode', 'residentialstate', 'local_rtocompliance');

        $mform->addElement('header', 'demographicdetails', get_string('demographicdetails', 'local_rtocompliance'));
        $mform->addHelpButton('demographicdetails', 'demographicdetails', 'local_rtocompliance');

        $countryoptions = avetmiss_codes::get_country_codes();
        $mform->addElement('select', 'countryofbirth', get_string('countryofbirth', 'local_rtocompliance'), $countryoptions);
        $mform->setDefault('countryofbirth', '1101');
        $mform->addHelpButton('countryofbirth', 'countryofbirth', 'local_rtocompliance');

        $languageoptions = avetmiss_codes::get_language_codes();
        $mform->addElement('select', 'languageathome', get_string('languageathome', 'local_rtocompliance'), $languageoptions);
        $mform->setDefault('languageathome', '1201');
        $mform->addHelpButton('languageathome', 'languageathome', 'local_rtocompliance');

        $englishoptions = avetmiss_codes::get_english_proficiency_codes();
        $mform->addElement('select', 'englishproficiency', get_string('englishproficiency', 'local_rtocompliance'), $englishoptions);
        $mform->setDefault('englishproficiency', '@');
        $mform->addHelpButton('englishproficiency', 'englishproficiency', 'local_rtocompliance');

        $atsioptions = avetmiss_codes::get_indigenous_status_codes();
        $mform->addElement('select', 'indigenousstatus', get_string('atsi', 'local_rtocompliance'), $atsioptions);
        $mform->setDefault('indigenousstatus', '@');
        $mform->addHelpButton('indigenousstatus', 'atsi', 'local_rtocompliance');

        $mform->addElement('header', 'disabilitydetails', get_string('disabilitydetails', 'local_rtocompliance'));
        $mform->addHelpButton('disabilitydetails', 'disabilitydetails', 'local_rtocompliance');

        $disabilityoptions = avetmiss_codes::get_disability_codes();
        $mform->addElement('select', 'disabilityflag', get_string('disability', 'local_rtocompliance'), $disabilityoptions);
        $mform->setDefault('disabilityflag', 'N');
        $mform->addHelpButton('disabilityflag', 'disability', 'local_rtocompliance');

        $disabilitytypes = avetmiss_codes::get_disability_type_codes();
        $typesgroup = [];
        foreach ($disabilitytypes as $code => $label) {
            $typesgroup[] = $mform->createElement('advcheckbox', "disabilitytype_{$code}", '', $label, [], ['0', $code]);
        }
        $mform->addGroup($typesgroup, 'disabilitytypesgroup', get_string('disabilitytype', 'local_rtocompliance'), '<br>', false);
        $mform->hideIf('disabilitytypesgroup', 'disabilityflag', 'neq', 'Y');
        $mform->addHelpButton('disabilitytypesgroup', 'disabilitytype', 'local_rtocompliance');

        $mform->addElement('header', 'educationdetails', get_string('educationdetails', 'local_rtocompliance'));
        $mform->addHelpButton('educationdetails', 'educationdetails', 'local_rtocompliance');

        $schooloptions = avetmiss_codes::get_school_level_codes();
        $mform->addElement('select', 'highestschoollevel', get_string('schoollevel', 'local_rtocompliance'), $schooloptions);
        $mform->setDefault('highestschoollevel', '@@');
        $mform->addHelpButton('highestschoollevel', 'schoollevel', 'local_rtocompliance');

        $years = ['' => get_string('choosedots'), '@@@@' => get_string('notstated', 'local_rtocompliance')];
        for ($y = date('Y'); $y >= 1950; $y--) {
            $years[$y] = $y;
        }
        $mform->addElement('select', 'yearschoolcompleted', get_string('yearschoolcompleted', 'local_rtocompliance'), $years);
        $mform->addHelpButton('yearschoolcompleted', 'yearschoolcompleted', 'local_rtocompliance');

        $atschooloptions = avetmiss_codes::get_at_school_flag_codes();
        $mform->addElement('select', 'atschoolflag', get_string('atschoolflag', 'local_rtocompliance'), $atschooloptions);
        $mform->setDefault('atschoolflag', 'N');
        $mform->addHelpButton('atschoolflag', 'atschoolflag', 'local_rtocompliance');

        $labourforceoptions = avetmiss_codes::get_labour_force_status_codes();
        $mform->addElement('select', 'labourforcestatus', get_string('labourforcestatus', 'local_rtocompliance'), $labourforceoptions);
        $mform->setDefault('labourforcestatus', '@@');
        $mform->addHelpButton('labourforcestatus', 'labourforcestatus', 'local_rtocompliance');

        $studyreasonoptions = avetmiss_codes::get_study_reason_codes();
        $mform->addElement('select', 'studyreason', get_string('studyreason', 'local_rtocompliance'), $studyreasonoptions);
        $mform->setDefault('studyreason', '@@');
        $mform->addHelpButton('studyreason', 'studyreason', 'local_rtocompliance');

        $prioroptions = ['' => get_string('none', 'local_rtocompliance')] + avetmiss_codes::get_prior_education_codes();
        $mform->addElement('select', 'priorachevement1', get_string('priorachievement', 'local_rtocompliance') . ' 1', $prioroptions);
        $mform->addElement('select', 'priorachevement2', get_string('priorachievement', 'local_rtocompliance') . ' 2', $prioroptions);
        $mform->addElement('select', 'priorachevement3', get_string('priorachievement', 'local_rtocompliance') . ' 3', $prioroptions);
        $mform->addElement('select', 'priorachevement4', get_string('priorachievement', 'local_rtocompliance') . ' 4', $prioroptions);

        $priorflagoptions = avetmiss_codes::get_prior_education_flag_codes();
        $mform->addElement('select', 'prioreducationflag', get_string('prioreducationflag', 'local_rtocompliance'), $priorflagoptions);
        $mform->setDefault('prioreducationflag', '@');
        $mform->addHelpButton('prioreducationflag', 'prioreducationflag', 'local_rtocompliance');

        $mform->addElement('header', 'surveydetails', get_string('surveydetails', 'local_rtocompliance'));
        $mform->addHelpButton('surveydetails', 'surveydetails', 'local_rtocompliance');

        $surveycontactoptions = avetmiss_codes::get_survey_contact_codes();
        $mform->addElement('select', 'surveycontactstatus', get_string('surveycontactstatus', 'local_rtocompliance'), $surveycontactoptions);
        $mform->setDefault('surveycontactstatus', 'N');
        $mform->addHelpButton('surveycontactstatus', 'surveycontactstatus', 'local_rtocompliance');

        $mform->addElement('text', 'surveycontactemail', get_string('surveycontactemail', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('surveycontactemail', PARAM_EMAIL);
        $mform->hideIf('surveycontactemail', 'surveycontactstatus', 'eq', 'N');
        $mform->hideIf('surveycontactemail', 'surveycontactstatus', 'eq', 'M');

        $mform->addElement('text', 'surveycontactphone', get_string('surveycontactphone', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 20]);
        $mform->setType('surveycontactphone', PARAM_TEXT);
        $mform->hideIf('surveycontactphone', 'surveycontactstatus', 'eq', 'N');
        $mform->hideIf('surveycontactphone', 'surveycontactstatus', 'eq', 'M');

        $mform->addElement('header', 'statespecific', get_string('statespecific', 'local_rtocompliance'));
        $mform->setExpanded('statespecific', true);
        $mform->addHelpButton('statespecific', 'statespecific', 'local_rtocompliance');

        $mform->addElement('text', 'qldlui', get_string('qldlui', 'local_rtocompliance'), ['size' => 15, 'maxlength' => 10]);
        $mform->setType('qldlui', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('qldlui', 'qldlui', 'local_rtocompliance');

        $mform->addElement('text', 'viccohortid', get_string('viccohortid', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('viccohortid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('viccohortid', 'viccohortid', 'local_rtocompliance');

        $mform->addElement('text', 'nswsmartskilled', get_string('nswsmartskilled', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('nswsmartskilled', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('nswsmartskilled', 'nswsmartskilled', 'local_rtocompliance');

        $mform->addElement('text', 'waraptid', get_string('waraptid', 'local_rtocompliance'), ['size' => 25, 'maxlength' => 20]);
        $mform->setType('waraptid', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('waraptid', 'waraptid', 'local_rtocompliance');

        // School type — required by QLD DTET and other STAs when atschoolflag=Y.
        $schooltypes = [
            ''    => get_string('choosedots'),
            'GOV' => get_string('schooltype_gov', 'local_rtocompliance'),
            'CAT' => get_string('schooltype_cat', 'local_rtocompliance'),
            'IND' => get_string('schooltype_ind', 'local_rtocompliance'),
            'OTH' => get_string('schooltype_oth', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'schooltype', get_string('schooltype', 'local_rtocompliance'), $schooltypes);
        $mform->setType('schooltype', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('schooltype', 'schooltype', 'local_rtocompliance');
        $mform->hideIf('schooltype', 'atschoolflag', 'neq', 'Y');

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['usi'])) {
            $result = avetmiss_codes::validate_usi($data['usi']);
            if (!$result['valid']) {
                $errors['usi'] = $result['error'];
            }
        }

        if (!empty($data['postcode']) && !empty($data['statecode'])) {
            $result = avetmiss_codes::validate_postcode($data['postcode'], $data['statecode']);
            if (!$result['valid']) {
                $errors['postcode'] = $result['error'];
            }
        }

        if (!empty($data['postcode']) && !preg_match('/^\d{4}$/', $data['postcode'])) {
            $errors['postcode'] = get_string('error_postcode_format', 'local_rtocompliance');
        }

        return $errors;
    }

    public function set_data($data) {
        if (!empty($data->disabilitytypes)) {
            $types = explode(',', $data->disabilitytypes);
            foreach ($types as $type) {
                if (!empty($type)) {
                    $data->{"disabilitytype_{$type}"} = $type;
                }
            }
        }
        parent::set_data($data);
    }

    public function get_submitted_data_with_disability_types() {
        $data = $this->get_data();
        if (!$data) {
            return null;
        }

        $types = [];
        $disabilitytypecodes = array_keys(avetmiss_codes::get_disability_type_codes());
        foreach ($disabilitytypecodes as $code) {
            $fieldname = "disabilitytype_{$code}";
            if (!empty($data->$fieldname)) {
                $types[] = $code;
            }
            unset($data->$fieldname);
        }
        $data->disabilitytypes = implode(',', $types);

        return $data;
    }
}
