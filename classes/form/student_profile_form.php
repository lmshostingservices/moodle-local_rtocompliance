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
 * RTO Compliance plugin — student_profile_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_rtocompliance\avetmiss_codes;

class student_profile_form extends \moodleform {
    use code_select_trait;

    /**
     * The AVETMISS coded fields on this form, mapped to the getter that defines
     * their legal values. Used by add_code_select() to build each menu and by
     * validation() to refuse a value that is not in the standard.
     *
     * Keep this in step with the add_code_select() calls in definition().
     */
    const CODE_FIELDS = [
        'sex'                 => ['get_sex_codes', '@'],
        'statecode'           => ['get_state_codes', '@@'],
        'countryofbirth'      => ['get_country_codes', '@@@@'],
        'residentialcountry'  => ['get_country_codes', '@@@@'],
        'languageathome'      => ['get_language_codes', '@@@@'],
        'englishproficiency'  => ['get_english_proficiency_codes', '@'],
        'indigenousstatus'    => ['get_indigenous_status_codes', '@'],
        'disabilityflag'      => ['get_disability_codes', null],
        'highestschoollevel'  => ['get_school_level_codes', '@@'],
        'atschoolflag'        => ['get_at_school_flag_codes', null],
        'labourforcestatus'   => ['get_labour_force_status_codes', '@@'],
        'studyreason'         => ['get_study_reason_codes', '@@'],
        'prioreducationflag'  => ['get_prior_education_flag_codes', '@'],
        'surveycontactstatus' => ['get_survey_contact_codes', null],
    ];

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

        $mform->addElement(
            'date_selector', 'dateofbirth', get_string('dateofbirth', 'local_rtocompliance'), [
                'startyear' => 1920,
                'stopyear' => date('Y') - 14,
                'optional' => true,
        ]);
        $mform->addHelpButton('dateofbirth', 'dateofbirth', 'local_rtocompliance');

        $this->add_code_select('sex', get_string('sex', 'local_rtocompliance'));

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

        $this->add_code_select('statecode', get_string('residentialstate', 'local_rtocompliance'), 'residentialstate');

        $mform->addElement('header', 'demographicdetails', get_string('demographicdetails', 'local_rtocompliance'));
        $mform->addHelpButton('demographicdetails', 'demographicdetails', 'local_rtocompliance');

        $this->add_code_select('countryofbirth', get_string('countryofbirth', 'local_rtocompliance'));
        // Australia on the CREATE path only; set_data() overrides this for an existing
        // student, so it cannot reach a record that already holds a value.
        $mform->setDefault('countryofbirth', '1101');

        // v6.6 RESIDENTIAL COUNTRY. This is the field that decides whether a student is
        // studying offshore, and it had no control anywhere in the interface - so nobody
        // could set it, and it was empty for every student on the reference site while
        // still being exported. An offshore international client is exempt from holding
        // an identifier, and this is what establishes that status, so without an input
        // the exemption could not be evidenced and those students were reported as
        // missing an identifier instead.
        $this->add_code_select('residentialcountry',
            get_string('residentialcountry', 'local_rtocompliance'));
        $mform->addHelpButton('residentialcountry', 'residentialcountry', 'local_rtocompliance');
        $mform->setDefault('residentialcountry', '1101');

        $this->add_code_select('languageathome', get_string('languageathome', 'local_rtocompliance'));
        $mform->setDefault('languageathome', '1201');

        $this->add_code_select('englishproficiency', get_string('englishproficiency', 'local_rtocompliance'));

        $this->add_code_select('indigenousstatus', get_string('atsi', 'local_rtocompliance'), 'atsi');

        $mform->addElement('header', 'disabilitydetails', get_string('disabilitydetails', 'local_rtocompliance'));
        $mform->addHelpButton('disabilitydetails', 'disabilitydetails', 'local_rtocompliance');

        $this->add_code_select('disabilityflag', get_string('disability', 'local_rtocompliance'), 'disability');
        $mform->setDefault('disabilityflag', 'N');

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

        $this->add_code_select('highestschoollevel', get_string('schoollevel', 'local_rtocompliance'), 'schoollevel');

        $years = ['' => get_string('choosedots'), '@@@@' => get_string('notstated', 'local_rtocompliance')];
        for ($y = date('Y'); $y >= 1950; $y--) {
            $years[$y] = $y;
        }
        $mform->addElement('select', 'yearschoolcompleted', get_string('yearschoolcompleted', 'local_rtocompliance'), $years);
        $mform->addHelpButton('yearschoolcompleted', 'yearschoolcompleted', 'local_rtocompliance');

        $this->add_code_select('atschoolflag', get_string('atschoolflag', 'local_rtocompliance'));
        $mform->setDefault('atschoolflag', 'N');

        $this->add_code_select('labourforcestatus', get_string('labourforcestatus', 'local_rtocompliance'));

        $this->add_code_select('studyreason', get_string('studyreason', 'local_rtocompliance'));

        $prioroptions = ['' => get_string('none', 'local_rtocompliance')] + avetmiss_codes::get_prior_education_codes();
        $mform->addElement('select', 'priorachevement1', get_string('priorachievement', 'local_rtocompliance') . ' 1', $prioroptions);
        $mform->addElement('select', 'priorachevement2', get_string('priorachievement', 'local_rtocompliance') . ' 2', $prioroptions);
        $mform->addElement('select', 'priorachevement3', get_string('priorachievement', 'local_rtocompliance') . ' 3', $prioroptions);
        $mform->addElement('select', 'priorachevement4', get_string('priorachievement', 'local_rtocompliance') . ' 4', $prioroptions);

        $this->add_code_select('prioreducationflag', get_string('prioreducationflag', 'local_rtocompliance'));

        $mform->addElement('header', 'surveydetails', get_string('surveydetails', 'local_rtocompliance'));
        $mform->addHelpButton('surveydetails', 'surveydetails', 'local_rtocompliance');

        $this->add_code_select('surveycontactstatus', get_string('surveycontactstatus', 'local_rtocompliance'));
        $mform->setDefault('surveycontactstatus', 'N');

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

        // PROFILE-GATE (v6.3.0): in locked mode there is nowhere to cancel to — every
        // other page redirects straight back here — so the Cancel button is removed
        // rather than left as a dead end that just reloads this same form.
        if (!empty($this->_customdata['lockmode'])) {
            $this->add_action_buttons(false, get_string('savechanges'));
        } else {
            $this->add_action_buttons();
        }
    }

    public function validation($data, $files) {
        global $CFG;

        $errors = parent::validation($data, $files);

        // CODE-FIELD GATE (v6.3.33; shared with the enrolment form since v6.3.34):
        // refuse any coded value that is not in the standard, so a bad code cannot enter
        // the database at all - not from a browser fallback, not from a hand-built POST,
        // not from a future defect in the option-building code. code_select_trait
        // explains why a value UNCHANGED from the stored one is deliberately allowed.
        $errors = array_merge($errors, $this->validate_code_fields($data));

        // PROFILE-GATE (v6.3.0): the fields the AVETMISS gate requires are validated
        // here, server-side, rather than with client-side 'required' rules — neither
        // rule type can express what "answered" means for AVETMISS. The selects are
        // never empty (they default to the '@' / '@@' not-stated sentinels) and the
        // date of birth is an optional date_selector group.
        // local_rtocompliance_avetmiss_value_missing() holds the one true definition,
        // shared with the gate and with the profilecomplete flag, so this form can
        // never accept a profile that the gate would immediately reject again.
        $requiredfields = $this->_customdata['requiredfields'] ?? [];
        if (!empty($requiredfields)) {
            require_once($CFG->dirroot . '/local/rtocompliance/lib.php');
            $labels = local_rtocompliance_avetmiss_field_labels();
            foreach ($requiredfields as $field) {
                if (isset($errors[$field])) {
                    continue; // A more specific error already applies to this field.
                }
                $value = $data[$field] ?? null;
                if (local_rtocompliance_avetmiss_value_missing($field, $value)) {
                    $errors[$field] = get_string(
                        'avetmiss_field_required', 'local_rtocompliance',
                        $labels[$field] ?? $field);
                }
            }
        }

        if (!empty($data['usi'])) {
            $result = avetmiss_codes::validate_usi($data['usi']);
            if (!$result['valid']) {
                $errors['usi'] = $result['error'];
            }
        }

        // v6.6 OVERSEAS POSTCODE. The collection standard's value for a client with an
        // overseas address is the literal OSPC, not a number, and it is REQUIRED where
        // the identifier field carries the offshore exemption code. Both checks below
        // demanded four digits, so that value could not be entered through this form at
        // all - which made it impossible to record an offshore student correctly.
        $isoverseas = (strtoupper(trim((string) ($data['postcode'] ?? ''))) === 'OSPC');

        if (!$isoverseas && !empty($data['postcode']) && !empty($data['statecode'])) {
            $result = avetmiss_codes::validate_postcode($data['postcode'], $data['statecode']);
            if (!$result['valid']) {
                $errors['postcode'] = $result['error'];
            }
        }

        if (!$isoverseas && !empty($data['postcode']) && !preg_match('/^\d{4}$/', $data['postcode'])) {
            $errors['postcode'] = get_string('error_postcode_format', 'local_rtocompliance');
        }

        // OSPC only makes sense alongside a residential country outside Australia. Left
        // unchecked, it would be a way to put a meaningless postcode on a domestic
        // student and have them silently treated as exempt.
        if ($isoverseas) {
            $rc = trim((string) ($data['residentialcountry'] ?? ''));
            if ($rc === '' || $rc === '1101' || $rc === '@@@@') {
                $errors['postcode'] = get_string('error_ospc_needs_country', 'local_rtocompliance');
            }
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
