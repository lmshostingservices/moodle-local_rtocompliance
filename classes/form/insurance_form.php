<?php
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class insurance_form extends \moodleform {

    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $insurance = $this->_customdata['insurance'] ?? null;

        $mform->addElement('header', 'insurancedetails', get_string('insurance', 'local_rtocompliance'));
        $mform->addHelpButton('insurancedetails', 'insurance_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $typeoptions = [
            'public_liability' => get_string('insurance_public_liability', 'local_rtocompliance'),
            'professional_indemnity' => get_string('insurance_professional_indemnity', 'local_rtocompliance'),
            'workers_comp' => get_string('insurance_workers_comp', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'insurancetype', get_string('insurance_type', 'local_rtocompliance'), $typeoptions);
        $mform->addRule('insurancetype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('insurancetype', 'insurancetype', 'local_rtocompliance');

        $mform->addElement('text', 'provider', get_string('provider', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('provider', PARAM_TEXT);
        $mform->addRule('provider', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('provider', 'insurance_provider', 'local_rtocompliance');

        $mform->addElement('text', 'policynumber', get_string('policy_number', 'local_rtocompliance'), ['size' => 30, 'maxlength' => 50]);
        $mform->setType('policynumber', PARAM_ALPHANUMEXT);
        $mform->addRule('policynumber', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('policynumber', 'policynumber', 'local_rtocompliance');

        $mform->addElement('header', 'coverage', 'Coverage Details');
        $mform->addHelpButton('coverage', 'coverage_header', 'local_rtocompliance');

        $mform->addElement('text', 'coverageamount', get_string('coverage_amount', 'local_rtocompliance'), ['size' => 20]);
        $mform->setType('coverageamount', PARAM_FLOAT);
        $mform->addHelpButton('coverageamount', 'coverageamount', 'local_rtocompliance');

        $mform->addElement('text', 'premium', get_string('premium', 'local_rtocompliance'), ['size' => 15]);
        $mform->setType('premium', PARAM_FLOAT);
        $mform->addHelpButton('premium', 'premium', 'local_rtocompliance');

        $mform->addElement('text', 'excessamount', get_string('excess_amount', 'local_rtocompliance'), ['size' => 15]);
        $mform->setType('excessamount', PARAM_FLOAT);
        $mform->addHelpButton('excessamount', 'excessamount', 'local_rtocompliance');

        $mform->addElement('textarea', 'coveragedetails', get_string('coverage_details', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('coveragedetails', PARAM_RAW);
        $mform->addHelpButton('coveragedetails', 'coveragedetails', 'local_rtocompliance');

        $mform->addElement('textarea', 'exclusions', get_string('exclusions', 'local_rtocompliance'), ['rows' => 3, 'cols' => 80]);
        $mform->setType('exclusions', PARAM_RAW);
        $mform->addHelpButton('exclusions', 'exclusions', 'local_rtocompliance');

        $mform->addElement('header', 'coveragemapping', 'Coverage Mapping');
        $mform->addHelpButton('coveragemapping', 'coveragemapping_header', 'local_rtocompliance');
        
        $mform->addElement('static', 'coveragehelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;"><strong>TAS Linking:</strong> Link this insurance policy to the qualifications, delivery modes and locations it covers. This is required for ASQA compliance to demonstrate appropriate insurance coverage for your training products.</div>');

        $mform->addElement('textarea', 'qualificationscovered', 'Qualifications Covered (TAS Link)', 
            ['rows' => 3, 'cols' => 80, 'placeholder' => 'BSB50420 - Diploma of Leadership and Management
CHC50121 - Diploma of Early Childhood Education and Care
SIS40221 - Certificate IV in Fitness']);
        $mform->setType('qualificationscovered', PARAM_RAW);

        $mform->addElement('textarea', 'deliverymodes', get_string('delivery_modes', 'local_rtocompliance'), 
            ['rows' => 2, 'cols' => 80, 'placeholder' => 'Classroom, Online, Workplace, Blended']);
        $mform->setType('deliverymodes', PARAM_RAW);
        $mform->addHelpButton('deliverymodes', 'deliverymodes', 'local_rtocompliance');

        $mform->addElement('textarea', 'locations', get_string('locations', 'local_rtocompliance'), 
            ['rows' => 2, 'cols' => 80, 'placeholder' => 'Head Office - 123 Main St, Sydney NSW 2000
Training Centre - 456 Training Rd, Melbourne VIC 3000']);
        $mform->setType('locations', PARAM_RAW);
        $mform->addHelpButton('locations', 'insurance_locations', 'local_rtocompliance');

        $mform->addElement('header', 'policydates', 'Policy Period');
        $mform->addHelpButton('policydates', 'policydates_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'startdate', get_string('start_date', 'local_rtocompliance'));
        $mform->addRule('startdate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('startdate', 'insurance_startdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'expirydate', get_string('expiry_date', 'local_rtocompliance'));
        $mform->addRule('expirydate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('expirydate', 'insurance_expirydate', 'local_rtocompliance');

        $mform->addElement('text', 'renewalreminderdays', get_string('renewal_reminder_days', 'local_rtocompliance'), ['size' => 5]);
        $mform->setType('renewalreminderdays', PARAM_INT);
        $mform->setDefault('renewalreminderdays', 30);
        $mform->addHelpButton('renewalreminderdays', 'renewalreminderdays', 'local_rtocompliance');

        $statusoptions = [
            'active' => 'Active',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'active');
        $mform->addHelpButton('status', 'insurance_status', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'insurance_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
