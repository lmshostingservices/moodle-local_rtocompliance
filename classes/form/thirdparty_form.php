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
 * RTO Compliance plugin — thirdparty_form.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class thirdparty_form extends \moodleform {
    protected function definition() {
        global $CFG;
        $mform = $this->_form;
        $arrangement = $this->_customdata['arrangement'] ?? null;

        $mform->addElement('header', 'organisationdetails', get_string('thirdparty_details', 'local_rtocompliance'));
        $mform->addHelpButton('organisationdetails', 'thirdparty_header', 'local_rtocompliance');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'organisationname', get_string('organisation_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('organisationname', PARAM_TEXT);
        $mform->addRule('organisationname', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('organisationname', 'organisationname', 'local_rtocompliance');

        $mform->addElement('text', 'tradingname', get_string('trading_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 255]);
        $mform->setType('tradingname', PARAM_TEXT);
        $mform->addHelpButton('tradingname', 'tradingname', 'local_rtocompliance');

        $mform->addElement('text', 'abn', get_string('abn', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 11]);
        $mform->setType('abn', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('abn', 'abn', 'local_rtocompliance');

        $typeoptions = [
            'partnership' => get_string('arrangement_partnership', 'local_rtocompliance'),
            'subcontract' => get_string('arrangement_subcontract', 'local_rtocompliance'),
            'auspice' => get_string('arrangement_auspice', 'local_rtocompliance'),
            'venue' => get_string('arrangement_venue', 'local_rtocompliance'),
            'recruitment' => 'Recruitment/Brokerage Agent',
            'employer_host' => 'Employer Host (Workplace Training)',
            'licensing' => 'Licensing/Franchise Arrangement',
            'assessment_only' => 'Assessment Services Only',
            'rpl_services' => 'RPL Assessment Services',
            'resource_development' => 'Training Resource Development',
            'online_platform' => 'Online/LMS Platform Provider',
            'other' => 'Other (specify in notes)',
        ];
        $mform->addElement('select', 'arrangementtype', get_string('arrangement_type', 'local_rtocompliance'), $typeoptions);
        $mform->addRule('arrangementtype', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('arrangementtype', 'arrangementtype', 'local_rtocompliance');

        $mform->addElement('header', 'contactinfo', get_string('contactdetails', 'local_rtocompliance'));
        $mform->addHelpButton('contactinfo', 'contactinfo_header', 'local_rtocompliance');

        $mform->addElement('text', 'contactname', get_string('contact_name', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('contactname', PARAM_TEXT);
        $mform->addHelpButton('contactname', 'contactname', 'local_rtocompliance');

        $mform->addElement('text', 'contactemail', get_string('contact_email', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('contactemail', PARAM_EMAIL);
        $mform->addHelpButton('contactemail', 'contactemail', 'local_rtocompliance');

        $mform->addElement('text', 'contactphone', get_string('contact_phone', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 30]);
        $mform->setType('contactphone', PARAM_TEXT);
        $mform->addHelpButton('contactphone', 'contactphone', 'local_rtocompliance');

        $mform->addElement('header', 'agreementinfo', 'Agreement Details');
        $mform->addHelpButton('agreementinfo', 'agreementinfo_header', 'local_rtocompliance');

        $mform->addElement('date_selector', 'agreementstartdate', get_string('agreement_start_date', 'local_rtocompliance'));
        $mform->addRule('agreementstartdate', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('agreementstartdate', 'agreementstartdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'agreementenddate', get_string('agreement_end_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('agreementenddate', 'agreementenddate', 'local_rtocompliance');

        $mform->addElement('textarea', 'qualificationscovered', get_string('qualifications_covered', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('qualificationscovered', PARAM_RAW);
        $mform->addHelpButton('qualificationscovered', 'qualificationscovered', 'local_rtocompliance');

        $mform->addElement('header', 'asqanotification', 'ASQA Notification (30-Day Requirement)');
        $mform->addHelpButton('asqanotification', 'asqanotification_header', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'asqanotified', get_string('asqa_notified', 'local_rtocompliance'));
        $mform->setDefault('asqanotified', 0);
        $mform->addHelpButton('asqanotified', 'asqanotified', 'local_rtocompliance');

        $mform->addElement('date_selector', 'asqanotificationdate', get_string('asqa_notification_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->hideIf('asqanotificationdate', 'asqanotified', 'notchecked');
        $mform->addHelpButton('asqanotificationdate', 'asqanotificationdate', 'local_rtocompliance');

        $mform->addElement('header', 'mandatoryclauses', get_string('mandatory_clauses', 'local_rtocompliance'));
        $mform->addHelpButton('mandatoryclauses', 'mandatoryclauses_header', 'local_rtocompliance');

        $mform->addElement('static', 'mandatoryclauses_intro', '', '<div class="alert alert-warning" style="margin-bottom:12px;"><strong>ASQA Requirement:</strong> Written agreements with third parties must include all applicable mandatory clauses under the Standards for RTOs 2015 (Standard 2.3, Element 1). Tick each clause to confirm it is included in the written agreement.</div>');

        // --- Existing 3 mandatory clauses ---
        $mform->addElement('advcheckbox', 'mandatoryclausesnrtlogo', get_string('clause_nrt_logo', 'local_rtocompliance'));
        $mform->addHelpButton('mandatoryclausesnrtlogo', 'mandatoryclausesnrtlogo', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'mandatoryclausesaqf', get_string('clause_aqf', 'local_rtocompliance'));
        $mform->addHelpButton('mandatoryclausesaqf', 'mandatoryclausesaqf', 'local_rtocompliance');

        $mform->addElement('advcheckbox', 'mandatoryclausestransparency', get_string('clause_transparency', 'local_rtocompliance'));
        $mform->addHelpButton('mandatoryclausestransparency', 'mandatoryclausestransparency', 'local_rtocompliance');

        // --- Additional mandatory clauses (stored as JSON in mandatoryclausesextra) ---
        $mform->addElement('advcheckbox', 'clause_priortodelivery',
            'Agreement entered into PRIOR to delivery and assessment',
            'Confirmed — the written agreement was entered into before any training or assessment commenced');
        $mform->setDefault('clause_priortodelivery', 0);

        $mform->addElement('advcheckbox', 'clause_cooperateregulator',
            'Cooperate with the National VET Regulator (ASQA)',
            'Confirmed — the third party agrees to cooperate with the National VET Regulator as required');
        $mform->setDefault('clause_cooperateregulator', 0);

        $mform->addElement('advcheckbox', 'clause_accurateresponses',
            'Provide accurate responses to Regulator information requests',
            'Confirmed — the third party agrees to provide accurate and timely responses to requests for information from the Regulator');
        $mform->setDefault('clause_accurateresponses', 0);

        $mform->addElement('advcheckbox', 'clause_rtocertification',
            'RTO maintains sole responsibility for certification issuance',
            'Confirmed — the agreement specifies that the RTO (not the third party) is solely responsible for issuing AQF certification documentation');
        $mform->setDefault('clause_rtocertification', 0);

        $mform->addElement('advcheckbox', 'clause_partiesnames',
            'Agreement contains full business/trading names of all parties',
            'Confirmed — the written agreement includes the full legal business and trading names of the RTO and the third party');
        $mform->setDefault('clause_partiesnames', 0);

        $mform->addElement('advcheckbox', 'clause_datesincluded',
            'Agreement contains the commencement and end dates',
            'Confirmed — the written agreement specifies the start date and (where applicable) the end date of the arrangement');
        $mform->setDefault('clause_datesincluded', 0);

        $mform->addElement('advcheckbox', 'clause_obligations',
            'Agreement contains the obligations of each party',
            'Confirmed — the written agreement clearly sets out the obligations, roles and responsibilities of each party to the arrangement');
        $mform->setDefault('clause_obligations', 0);

        $mform->addElement('advcheckbox', 'clause_monitorquality',
            'RTO regularly monitors the third party for quality and compliance',
            'Confirmed — the agreement includes provisions for the RTO to regularly monitor the quality of training and assessment delivered by the third party');
        $mform->setDefault('clause_monitorquality', 0);

        // Hidden field stores the extra clauses as JSON for DB persistence
        $mform->addElement('hidden', 'mandatoryclausesextra');
        $mform->setType('mandatoryclausesextra', PARAM_RAW);

        // Copy of Agreement document
        $mform->addElement('header', 'agreementdocheader', 'Copy of Agreement');
        $mform->addElement('text', 'agreementdocument', 'Agreement Document Link / Reference',
            ['size' => 80, 'maxlength' => 255, 'placeholder' => 'e.g. SharePoint link, DMS reference, or filename']);
        $mform->setType('agreementdocument', PARAM_TEXT);
        $mform->addElement('static', 'agreementdocument_note', '',
            '<p style="color:#666;font-size:12px;margin-top:4px;">Paste a link to the signed copy of this agreement (SharePoint, Google Drive, DMS) or enter the document reference number. This is displayed in the Third-Party Register so auditors can quickly access the agreement.</p>');

        $mform->addElement('header', 'monitoring', 'Monitoring & Risk');
        $mform->addHelpButton('monitoring', 'monitoring_header', 'local_rtocompliance');

        $frequencyoptions = [
            'monthly' => get_string('frequency_monthly', 'local_rtocompliance'),
            'quarterly' => get_string('frequency_quarterly', 'local_rtocompliance'),
            'biannual' => get_string('frequency_biannual', 'local_rtocompliance'),
            'annual' => get_string('frequency_annual', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'monitoringfrequency', get_string('monitoring_frequency', 'local_rtocompliance'), $frequencyoptions);
        $mform->setDefault('monitoringfrequency', 'quarterly');
        $mform->addHelpButton('monitoringfrequency', 'monitoringfrequency', 'local_rtocompliance');

        $mform->addElement('date_selector', 'lastmonitoringdate', get_string('last_monitoring_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('lastmonitoringdate', 'lastmonitoringdate', 'local_rtocompliance');

        $mform->addElement('date_selector', 'nextmonitoringdate', get_string('next_monitoring_date', 'local_rtocompliance'), ['optional' => true]);
        $mform->addHelpButton('nextmonitoringdate', 'nextmonitoringdate', 'local_rtocompliance');

        $riskoptions = [
            'low' => get_string('risk_low', 'local_rtocompliance'),
            'medium' => get_string('risk_medium', 'local_rtocompliance'),
            'high' => get_string('risk_high', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'riskrating', get_string('risk_rating', 'local_rtocompliance'), $riskoptions);
        $mform->setDefault('riskrating', 'low');
        $mform->addHelpButton('riskrating', 'riskrating', 'local_rtocompliance');

        $mform->addElement('static', 'credentialshelp', '', 
            '<div class="alert alert-info" style="margin-bottom: 12px;">
            <strong>Staff Credentials Verification:</strong> RTOs must verify that all trainers and assessors engaged through third parties hold the required credentials (TAE40122/TAE50122 and vocational competencies).
            <br><br>Check below to confirm credentials have been verified. For ongoing arrangements, re-verify credentials at each monitoring review.</div>');

        $mform->addElement('advcheckbox', 'staffcredentialsverified', 'Staff Credentials Verified', 'All third-party trainers/assessors have verified TAE and vocational credentials');
        $mform->setDefault('staffcredentialsverified', 0);
        $mform->addHelpButton('staffcredentialsverified', 'staffcredentialsverified', 'local_rtocompliance');

        $mform->addElement('date_selector', 'staffcredentialsverifieddate', 'Credentials Verification Date', ['optional' => true]);
        $mform->hideIf('staffcredentialsverifieddate', 'staffcredentialsverified', 'notchecked');

        $mform->addElement('text', 'staffcredentialsdocument', 'Credentials Evidence Document', ['size' => 80, 'maxlength' => 255, 'placeholder' => 'e.g., ThirdParty_Staff_Credentials_Matrix.xlsx']);
        $mform->setType('staffcredentialsdocument', PARAM_TEXT);
        $mform->hideIf('staffcredentialsdocument', 'staffcredentialsverified', 'notchecked');

        $statusoptions = [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'expired' => 'Expired',
            'terminated' => 'Terminated',
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_rtocompliance'), $statusoptions);
        $mform->setDefault('status', 'active');
        $mform->addHelpButton('status', 'thirdparty_status', 'local_rtocompliance');

        $mform->addElement('header', 'additionalinfo', get_string('additional_information', 'local_rtocompliance'));

        $mform->addElement('textarea', 'notes', get_string('notes', 'local_rtocompliance'), ['rows' => 4, 'cols' => 80]);
        $mform->setType('notes', PARAM_RAW);
        $mform->addHelpButton('notes', 'thirdparty_notes', 'local_rtocompliance');

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
