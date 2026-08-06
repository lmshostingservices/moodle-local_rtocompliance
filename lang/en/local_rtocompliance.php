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

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'RTO Compliance';
$string['privacy:metadata'] = 'The RTO Compliance plugin stores student AVETMISS data, trainer credentials, and issued certificates.';

$string['privacy:metadata:trainers'] = 'Information about trainer credentials and compliance status.';
$string['privacy:metadata:trainers:userid'] = 'The ID of the trainer user.';
$string['privacy:metadata:trainers:taecredential'] = 'The TAE qualification code held by the trainer.';
$string['privacy:metadata:trainers:vocationalqualifications'] = 'Vocational qualifications held by the trainer.';
$string['privacy:metadata:trainers:industrycurrency'] = 'Industry currency information for the trainer.';
$string['privacy:metadata:trainers:cpdhours'] = 'Continuing Professional Development hours recorded.';

$string['privacy:metadata:certs'] = 'Information about certificates issued to students.';
$string['privacy:metadata:certs:userid'] = 'The ID of the student who received the certificate.';
$string['privacy:metadata:certs:certnumber'] = 'The unique certificate number.';
$string['privacy:metadata:certs:certtype'] = 'The type of certificate (Testamur, Statement of Attainment, etc).';
$string['privacy:metadata:certs:qualificationname'] = 'The name of the qualification on the certificate.';
$string['privacy:metadata:certs:issuedate'] = 'The date the certificate was issued.';

$string['privacy:metadata:surveys'] = 'Quality Indicator survey responses.';
$string['privacy:metadata:surveys:respondentid'] = 'The ID of the user who completed the survey.';
$string['privacy:metadata:surveys:responses'] = 'The survey question responses.';
$string['privacy:metadata:surveys:comments'] = 'Any comments provided in the survey.';

$string['privacy:metadata:log'] = 'Audit log entries for compliance actions.';
$string['privacy:metadata:log:userid'] = 'The ID of the user who performed the action.';
$string['privacy:metadata:log:action'] = 'The action that was performed.';
$string['privacy:metadata:log:ipaddress'] = 'The IP address from which the action was performed.';

$string['dashboard'] = 'Compliance Dashboard';
$string['qualificationbuilder'] = 'Qualification Builder';
$string['student_records'] = 'Student Records';
$string['support_docs'] = 'Support Centre';
$string['support'] = 'Support Docs on Essaygraderai.app';
$string['support_internal'] = 'Help and Compliance Guides';
$string['practice_guides'] = 'Practice Guides';

// Navigation items
$string['nav_getting_started'] = 'Getting Started';
$string['nav_student_management'] = 'Student Management';
$string['nav_trainer_compliance'] = 'Trainer Compliance';
$string['nav_reporting'] = 'Reporting';
$string['nav_continuous_improvement'] = 'Continuous Improvement';
$string['nav_rto_governance'] = 'RTO Governance';
$string['nav_help_support'] = 'Help & Support';
$string['settings'] = 'Settings';
$string['trainers'] = 'Trainer Compliance';
$string['certificates'] = 'Certificates';
$string['natexport'] = 'NAT/AVETMISS Export';
$string['surveys'] = 'Quality Indicator Surveys';
$string['rtocomplianceinfo'] = 'RTO Compliance';
$string['mycertificates'] = 'My Certificates';

$string['pluginsettings'] = 'Plugin Settings';
$string['rtodetails'] = 'RTO Details';
$string['rtodetails_desc'] = 'Configure your Registered Training Organisation details. This information appears on certificates and reports.';
$string['rtoname'] = 'RTO Name';
$string['rtoname_desc'] = 'Full legal name of your Registered Training Organisation';
$string['rtocode'] = 'RTO Code';
$string['rtocode_desc'] = 'Your National RTO ID (e.g. 12345)';
$string['abn'] = 'ABN';
$string['abn_desc'] = 'Australian Business Number';
$string['rtologo'] = 'RTO Logo';
$string['rtologo_desc'] = 'Upload your RTO logo for certificates (PNG, JPG or SVG recommended)';
$string['regulator'] = 'State/Territory Regulator';
$string['regulator_desc'] = 'Select your registering body (ASQA for most RTOs, or your state authority)';

$string['contactdetails'] = 'Contact Details';
$string['address'] = 'Address';
$string['phone'] = 'Phone';
$string['email'] = 'Email';
$string['website'] = 'Website';
$string['student_handbook_url'] = 'Student Handbook URL';
$string['student_handbook_url_desc'] = 'Full URL to the Student Handbook on your RTO website (e.g. https://yourrto.edu.au/student-handbook). Used as the "Show Evidence" link on the Standard 2.1 Student Obligations card. Leave blank to fall back to the Student Declaration records page.';

$string['apiheading'] = 'Platform API Settings';
$string['apiheading_desc'] = 'Connect this Moodle site to the lms-labs.com RTO Compliance platform. Enter your Site ID, API key, and optional webhook key to enable USI verification, config push, and other platform features.';
$string['siteid'] = 'Site ID';
$string['siteid_desc'] = 'Your Moodle site domain (e.g. moodle.yourschool.edu.au)';
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Your API key from lms-labs.com';
$string['apiurl'] = 'API Base URL';
$string['apiurl_desc'] = 'Base URL for the lms-labs.com API (e.g. https://lms-labs.com). Leave as default unless instructed otherwise by your administrator.';

$string['certificatesettings'] = 'Certificate Settings';
$string['certprefix'] = 'Certificate Number Prefix';
$string['certprefix_desc'] = 'Prefix for certificate numbers (e.g. CERT produces CERT-2024-00001)';
$string['enableqr'] = 'Enable QR Verification';
$string['enableqr_desc'] = 'Add QR code to certificates for online verification';
$string['verifyurl'] = 'Verification URL';
$string['verifyurl_desc'] = 'Public URL where certificates can be verified (leave blank to use site URL)';
$string['certfooter'] = 'Certificate Footer Text';
$string['certfooter_desc'] = 'Text that appears at the bottom of all certificates';
$string['signatoryname'] = 'Authorised Signatory Name';
$string['signatoryname_desc'] = 'Name of the authorised person who signs certificates (e.g. CEO or Training Manager)';
$string['signatorytitle'] = 'Signatory Title';
$string['signatorytitle_desc'] = 'Position title of the authorised signatory (e.g. Chief Executive Officer)';
$string['logopath'] = 'Certificate Logo Path';
$string['logopath_desc'] = 'Server file path to your RTO logo image for certificates (PNG or JPG). Leave blank for no logo.';
$string['sealpath'] = 'Organisation Seal Path';
$string['sealpath_desc'] = 'Server file path to your organisation seal/stamp image (PNG or JPG). Appears next to the signatory.';
$string['emailcerts'] = 'Email Certificates Automatically';
$string['emailcerts_desc'] = 'When enabled, certificates will be automatically emailed to students upon issuance.';

// Report Settings
$string['reportsettings'] = 'Report Settings';
$string['reportsettings_desc'] = 'Configure default settings for AVETMISS reporting and data exports.';
$string['defaultstate'] = 'Default State/Territory';
$string['defaultstate_desc'] = 'Default state for student residential address when not specified.';
$string['reportyear'] = 'Reporting Year';
$string['reportyear_desc'] = 'The year for which reports and data exports will be generated.';

// USI Verification Settings
$string['enableusi'] = 'Enable USI Verification';
$string['enableusi_desc'] = 'Enable connection to the USI Registry for automated student identity verification.';
$string['usiorgcode'] = 'USI Organisation Code';
$string['usiorgcode_desc'] = 'Your organisation code registered with the USI Registry (provided by the USI Student Management System).';
$string['usicert'] = 'USI Certificate/Key';
$string['usicert_desc'] = 'Your Machine Authentication Service (MAS-ST) certificate or API key for secure USI verification.';

// Auto Survey Settings
$string['autosurveysettings'] = 'Automatic Survey Settings';
$string['autosurveysettings_desc'] = 'Configure automatic Quality Indicator survey distribution to students upon course completion.';
$string['autosurveyenable'] = 'Enable Auto Surveys';
$string['autosurveyenable_desc'] = 'Automatically send Quality Indicator surveys to students when they complete a qualification.';
$string['autosurveydelay'] = 'Survey Delay';
$string['autosurveydelay_desc'] = 'How long to wait after completion before sending the survey invitation.';
$string['autosurveyemailsubject'] = 'Survey Email Subject';
$string['autosurveyemailsubject_desc'] = 'Subject line for the automatic survey invitation email.';

// ASQA 2025 Settings
$string['asqa2025settings'] = 'ASQA 2025 Compliance';
$string['asqa2025settings_desc'] = 'Configure settings to meet the new ASQA 2025 standards requirements.';
$string['enforcecredentialpolicy'] = 'Enforce Trainer Credential Policy';
$string['enforcecredentialpolicy_desc'] = 'Prevent trainers from being assigned to courses if their credentials are expired or missing.';
$string['currencyexpirydays'] = 'Industry Currency Expiry (days)';
$string['currencyexpirydays_desc'] = 'Number of days before trainer industry currency expires and requires renewal (default 365 days).';
$string['requiresupervision'] = 'Require Supervision Records';
$string['requiresupervision_desc'] = 'Require supervision log entries for trainers without full TAE40122 credential.';
$string['feeprotectionthreshold'] = 'Fee Protection Threshold ($)';
$string['feeprotectionthreshold_desc'] = 'Amount above which fee protection arrangements must be documented (VET Student Loans: $1,500).';
$string['feeprotectiontype'] = 'Fee Protection Type';
$string['feeprotectiontype_desc'] = 'Select the type of fee protection arrangement in place for this RTO under Standard 6. Must be configured before collecting prepaid fees exceeding the threshold.';
$string['feeprotectiontype_none'] = '— Not configured —';
$string['feeprotectiontype_protected_account'] = 'Protected Account (fees held in trust pending completion)';
$string['feeprotectiontype_bank_guarantee'] = 'Bank Guarantee (bank guarantees refund)';
$string['feeprotectiontype_tas_arrangement'] = 'TAS Arrangement (Tuition Assurance Scheme membership)';
$string['feeprotectiontype_threshold_compliant'] = 'Threshold Compliant (no prepaid fees exceed $1,500)';
$string['feeprotectiondetails'] = 'Fee Protection Details';
$string['feeprotectiondetails_desc'] = 'Enter details of the fee protection arrangement: account number, bank guarantee reference, TAS membership ID, or other relevant information.';
$string['enablegovernance'] = 'Enable Governance Module';
$string['enablegovernance_desc'] = 'Track governance meetings, policies, and compliance declarations.';

$string['reportingsettings'] = 'AVETMISS Reporting';
$string['ncverorgid'] = 'NCVER Organisation ID';
$string['ncverorgid_desc'] = 'Your NCVER organisation identifier for TVA submissions';
$string['includestatereporting'] = 'Include State Reporting Fields';
$string['includestatereporting_desc'] = 'Enable additional fields required by state training authorities';

$string['cert_testamur'] = 'Completion Certificate (Testamur)';
$string['cert_statement'] = 'Statement of Attainment';
$string['cert_record'] = 'Record of Results';
$string['cert_completion'] = 'Certificate of Completion';

$string['avetmiss_fields'] = 'AVETMISS Data Fields';
$string['usi'] = 'Unique Student Identifier (USI)';
$string['usi_help'] = 'The 10-character USI assigned by the USI Registry. Required before issuing qualifications.';
$string['countryofbirth'] = 'Country of Birth';
$string['languageathome'] = 'Language Spoken at Home';
$string['atsi'] = 'Aboriginal/Torres Strait Islander Status';
$string['disability'] = 'Disability Status';
$string['disabilitytype'] = 'Disability Type';
$string['prioreducation'] = 'Prior Educational Achievement';
$string['employmentstatus'] = 'Employment Category';
$string['studyreason'] = 'Study Reason';
$string['residentialpostcode'] = 'Residential Postcode';
$string['residentialstate'] = 'Residential State/Territory';
$string['residentialsuburb'] = 'Residential Suburb';

$string['compliance_status'] = 'Compliance Status';
$string['compliant'] = 'Compliant';
$string['noncompliant'] = 'Action Required';
$string['pending'] = 'Pending';

$string['missing_usi'] = 'Missing';
$string['missing_usi_title'] = 'Students Missing USI';
$string['missing_avetmiss'] = 'Incomplete AVETMISS Data';
$string['verified'] = 'Verified';
$string['noprofile'] = 'No Profile';
$string['expiring_trainers'] = 'Trainer Credentials Expiring';
$string['pending_certificates'] = 'Pending Certificate Issuance';
$string['upcoming_deadlines'] = 'Upcoming Deadlines';

$string['deadline_tva'] = 'Total VET Activity (TVA) Submission';
$string['deadline_qi'] = 'Quality Indicator Data Submission';
$string['deadline_declaration'] = 'Annual Compliance Declaration';

$string['trainer_name'] = 'Trainer Name';
$string['trainer_qualifications'] = 'Qualifications';
$string['trainer_qualifications_help'] = 'List all vocational qualifications held by this trainer that are relevant to the training products they deliver. Include qualification codes, titles, and dates achieved.';
$string['trainer_taecredential'] = 'TAE Credential';
$string['trainer_industrycurrency'] = 'Industry Currency';
$string['trainer_vocationalcompetency'] = 'Vocational Competency';
$string['trainer_expirydate'] = 'Next Review Date';
$string['trainer_cpdhours'] = 'CPD Hours (This Year)';
$string['trainer_status'] = 'Status';
$string['trainer_current'] = 'Current';
$string['trainer_expiring'] = 'Expiring Soon';
$string['trainer_expired'] = 'Expired';

$string['add_trainer'] = 'Add Trainer';
$string['edit_trainer'] = 'Edit Trainer';
$string['view_trainer'] = 'View Trainer Details';
$string['trainer_saved'] = 'Trainer details saved successfully';
$string['trainer_deleted'] = 'Trainer removed';
$string['trainer_register'] = 'Trainer Register';

$string['issue_certificate'] = 'Issue Certificate';
$string['soa_issue'] = 'Issue Multi-Unit SOA';
$string['soa_issue_pagetitle'] = 'Issue Multi-Unit Statement of Attainment';
$string['soa_issue_desc'] = 'Issue a compliant Statement of Attainment listing multiple units of competency on a single document with automatic AQF/ASQA compliance validation.';
$string['certificate_issued'] = 'Certificate issued successfully';
$string['certificate_emailed'] = 'Certificate emailed to student';
$string['certificate_verified'] = 'Certificate Verified';
$string['certificate_invalid'] = 'Certificate Not Found';
$string['certificate_number'] = 'Certificate Number';
$string['certificate_type'] = 'Certificate Type';
$string['certificate_qualification'] = 'Qualification/Unit';
$string['certificate_issuedate'] = 'Issue Date';
$string['certificate_student'] = 'Student Name';
$string['download_pdf'] = 'Download PDF';
$string['email_certificate'] = 'Email Certificate';
$string['verify_certificate'] = 'Verify Certificate';

$string['nat_export_title'] = 'AVETMISS NAT File Export';
$string['nat_export_desc'] = 'Generate NAT files for NCVER Total VET Activity (TVA) reporting. Files are validated before export.';
$string['nat_period'] = 'Reporting Period';
$string['nat_generate'] = 'Generate NAT Files';
$string['nat_download'] = 'Download NAT Package';
$string['nat_validate'] = 'Validate Data';
$string['nat_validation_passed'] = 'Validation passed - ready to export';
$string['nat_validation_errors'] = 'Validation errors found';
$string['nat_validation_warnings'] = 'Validation warnings';

$string['nat00010'] = 'NAT00010 - Training Organisation';
$string['nat00020'] = 'NAT00020 - Training Organisation Delivery Location';
$string['nat00030'] = 'NAT00030 - Program (Course)';
$string['nat00060'] = 'NAT00060 - Subject (Module/Unit of Competency)';
$string['nat00080'] = 'NAT00080 - Client (Student)';
$string['nat00085'] = 'NAT00085 - Client Postal Details';
$string['nat00090'] = 'NAT00090 - Disability';
$string['nat00100'] = 'NAT00100 - Prior Education';
$string['nat00120'] = 'NAT00120 - Enrolment';
$string['nat00130'] = 'NAT00130 - Program (Qualification) Completion';

$string['survey_learner'] = 'Learner Questionnaire';
$string['survey_employer'] = 'Employer Questionnaire';
$string['survey_send'] = 'Send Survey';
$string['survey_responses'] = 'Survey Responses';
$string['survey_summary'] = 'QI Summary Report';
$string['survey_completed'] = 'Survey completed - thank you!';

$string['ai_usage_report'] = 'AI Credit Usage Report';
$string['ai_usage_report_desc'] = 'AI credit usage for the RTO Compliance plugin on this Moodle site — includes AI Suggest (TAS/forms), Compliance Auditor, Unit Mapping, Qualification Mapping, and Survey Analyser. Shows totals, per-feature breakdown, and daily activity.';
$string['auditlog'] = 'Audit Log';
$string['auditlog_action'] = 'Action';
$string['auditlog_user'] = 'User';
$string['auditlog_time'] = 'Time';
$string['auditlog_details'] = 'Details';

$string['complaints_appeals'] = 'Complaints & Appeals';
$string['thirdparty'] = 'Third-Party Arrangements';
$string['governance'] = 'Governance & ADC';
$string['feeprotection'] = 'Fee Protection';
$string['insurance'] = 'Insurance Register';
$string['transitions'] = 'Training Product Transitions';
$string['validation'] = 'Validation Schedule';
$string['tas'] = 'TAS Generator';

$string['rtocompliance:manage'] = 'Manage RTO compliance settings';
$string['rtocompliance:viewall'] = 'View all compliance data';
$string['rtocompliance:viewown'] = 'View own compliance data';
$string['rtocompliance:issuecerts'] = 'Issue certificates';
$string['rtocompliance:managetrainers'] = 'Manage trainer compliance';
$string['rtocompliance:exportnat'] = 'Export NAT files';
$string['rtocompliance:managesurveys'] = 'Manage QI surveys';

$string['error_usi_required'] = 'USI is required before issuing certificates';
$string['error_usi_invalid'] = 'Invalid USI format (must be 10 alphanumeric characters)';
$string['error_missing_rto'] = 'Please configure RTO details in plugin settings';
$string['error_no_qualification'] = 'Student has no completed qualifications to certify';

$string['confirm_issue_cert'] = 'Issue certificate to {$a}?';
$string['confirm_delete_trainer'] = 'Remove this trainer from compliance tracking?';

$string['credits_remaining'] = 'Credits Remaining';
$string['buy_credits'] = 'Buy Credits';

$string['cron_check_expiry'] = 'Check trainer credential expiry';
$string['cron_send_reminders'] = 'Send compliance reminders';

$string['welcome_dashboard'] = 'Welcome to RTO Compliance';
$string['welcome_desc'] = 'This dashboard helps you manage your RTO compliance obligations including student data, trainer credentials, certificates, and AVETMISS reporting.';
$string['getting_started'] = 'Getting Started';
$string['getting_started_desc'] = 'Configure your RTO details in the plugin settings, then set up your trainer credentials and AVETMISS profile fields.';
$string['rpl_credit'] = 'RPL & Credit Transfer';

$string['quickstats'] = 'Quick Statistics';
$string['total_students'] = 'Total Students';
$string['total_completions'] = 'Completions This Year';
$string['certs_issued'] = 'Certificates Issued';
$string['trainers_count'] = 'Active Trainers';

$string['compliance_checklist'] = 'Compliance Checklist';
$string['checklist_rto'] = 'RTO details configured';
$string['checklist_trainers'] = 'All trainers current';
$string['checklist_usi'] = 'All students have USI';
$string['checklist_avetmiss'] = 'AVETMISS data complete';
$string['checklist_certs'] = 'Certificates up to date';
$string['checklist_surveys'] = 'QI surveys sent';

$string['students'] = 'Student AVETMISS Profiles';
$string['student'] = 'Student';
$string['studentprofile'] = 'Student Profile';
$string['editprofile'] = 'Edit Profile';
$string['viewprofile'] = 'View Profile';
$string['enrolments'] = 'Enrolments';

$string['personaldetails'] = 'Personal Details';
$string['addressdetails'] = 'Address Details';
$string['demographicdetails'] = 'Demographic Details';
$string['disabilitydetails'] = 'Disability Details';
$string['educationdetails'] = 'Education History';
$string['surveydetails'] = 'Survey Consent';
$string['statespecific'] = 'State-Specific Fields';

$string['clientid'] = 'Client ID';
$string['clientid_help'] = 'Your RTO-assigned unique client identifier for this student.';
$string['dateofbirth'] = 'Date of Birth';
$string['sex'] = 'Sex';
$string['buildingname'] = 'Building/Property Name';
$string['unitno'] = 'Unit/Flat Number';
$string['streetno'] = 'Street Number';
$string['streetname'] = 'Street Name';
$string['englishproficiency'] = 'English Proficiency';
$string['schoollevel'] = 'Highest School Level Completed';
$string['yearschoolcompleted'] = 'Year School Completed';
$string['atschoolflag'] = 'Currently Attending School';
$string['priorachievement'] = 'Prior Educational Achievement';
$string['surveyconsent'] = 'Survey Contact Consent';
$string['surveyconsent_desc'] = 'Student consents to be contacted for NCVER surveys';
$string['surveycontactemail'] = 'Survey Contact Email';
$string['surveycontactphone'] = 'Survey Contact Phone';
$string['notstated'] = 'Not stated';
$string['none'] = 'None';

$string['qldlui'] = 'QLD LUI';
$string['qldlui_help'] = 'Queensland Learner Unique Identifier for state reporting.';
$string['viccohortid'] = 'VIC Cohort ID';
$string['viccohortid_help'] = 'Victoria Commencing Program Cohort Identifier for SVTS reporting.';
$string['nswsmartskilled'] = 'NSW Smart & Skilled ID';
$string['nswsmartskilled_help'] = 'NSW Smart and Skilled contract identifier.';
$string['waraptid'] = 'WA RAPT ID';
$string['waraptid_help'] = 'Western Australia RAPT integration identifier.';

$string['profileupdated'] = 'Student profile updated successfully';
$string['profilecreated'] = 'Student profile created successfully';
$string['profileincomplete'] = 'Profile has missing or invalid data:';
$string['profilecomplete_msg'] = 'Profile is complete and valid for AVETMISS reporting.';
$string['profilestatus'] = 'Profile Status';
$string['complete'] = 'Complete';
$string['incomplete'] = 'Incomplete';
$string['allstudents'] = 'All Students';
$string['incompleteonly'] = 'Incomplete Profiles Only';
$string['missingusionly'] = 'Missing USI Only';

$string['error_usi_format'] = 'USI must be exactly 10 alphanumeric characters';
$string['error_postcode_format'] = 'Postcode must be exactly 4 digits';
$string['error_dob_required'] = 'Date of birth is required for AVETMISS';
$string['error_sex_required'] = 'Sex is required for AVETMISS';
$string['error_postcode_required'] = 'Postcode is required for AVETMISS';
$string['error_state_required'] = 'State/Territory is required for AVETMISS';
$string['error_suburb_required'] = 'Suburb is required for AVETMISS';
$string['error_indigenous_required'] = 'Indigenous status is required for AVETMISS';
$string['error_countryofbirth_required'] = 'Country of birth is required for AVETMISS';
$string['error_languageathome_required'] = 'Language spoken at home is required for AVETMISS';
$string['error_labourforcestatus_required'] = 'Labour force status is required for AVETMISS';
$string['error_highestschoollevel_required'] = 'Highest school level is required for AVETMISS';

$string['enrolment_details'] = 'Enrolment Details';
$string['program'] = 'Program/Qualification';
$string['unit'] = 'Unit of Competency';
$string['activitystartdate'] = 'Activity Start Date';
$string['activityenddate'] = 'Activity End Date';
$string['scheduledhours'] = 'Scheduled Hours';
$string['outcome'] = 'Outcome';
$string['deliverymode'] = 'Delivery Mode';
$string['fundingsource'] = 'Funding Source';
$string['enrolmentstatus'] = 'Status';
$string['active'] = 'Active';
$string['onhold'] = 'On Hold';
$string['withdrawn'] = 'Withdrawn';

$string['add_enrolment'] = 'Add Enrolment';
$string['edit_enrolment'] = 'Edit Enrolment';
$string['enrolment_saved'] = 'Enrolment saved successfully';
$string['enrolment_deleted'] = 'Enrolment deleted';
$string['no_enrolments'] = 'No enrolments recorded for this student';
$string['view_enrolments'] = 'View Enrolments';

$string['usiverified'] = 'USI Verified';
$string['usiunverified'] = 'USI Not Verified';
$string['verify_usi'] = 'Verify USI';
$string['usi_not_verified'] = 'USI verification required — this certificate cannot be issued until the student\'s USI has been verified on the USI Registry (Clause 12 compliance).';

$string['bulkimport'] = 'Bulk Import';
$string['exportprofiles'] = 'Export Profiles';
$string['importprofiles'] = 'Import Profiles';

$string['filterbystatus'] = 'Filter by Status';
$string['filterbystate'] = 'Filter by State';
$string['searchstudent'] = 'Search students...';

$string['qualificationcode'] = 'Qualification Code';
$string['qualificationcode_help'] = 'National qualification code (e.g. BSB50420 for Diploma of Leadership and Management)';
$string['qualificationname'] = 'Qualification Name';
$string['unitcode'] = 'Unit Code';
$string['unitcode_help'] = 'National unit of competency code (e.g. BSBWHS411)';
$string['unitname'] = 'Unit Name';
$string['tuitionfee'] = 'Tuition Fee ($)';
$string['feecharged'] = 'Fee Charged';
$string['govtcontribution'] = 'Government Contribution ($)';
$string['vetoptions'] = 'VET Options';
$string['vetflag'] = 'VET Program';
$string['vetinschoolsflag'] = 'VET in Schools';
$string['commencingprogramid'] = 'Commencing Status';
$string['holduntil'] = 'Hold Until';
$string['holdreason'] = 'Hold Reason';

$string['profilerequired'] = 'Please create an AVETMISS profile for this student first.';
$string['confirmdelete'] = 'Are you sure you want to delete this enrolment?';

$string['error_endbeforestart'] = 'End date cannot be before start date';
$string['error_invalidhours'] = 'Hours must be a positive number';

$string['programcode'] = 'Program Code';
$string['programcode_help'] = 'AVETMISS program identifier for this qualification.';

$string['error_no_profile'] = 'Student does not have an AVETMISS profile';
$string['error_no_units'] = 'At least one completed unit is required for Statement of Attainment';
$string['error_no_competent_units'] = 'No units have competent outcomes (20, 51, 52, 60, 81, 82)';
$string['error_hold_active'] = 'Certificate issuance blocked due to active hold';
$string['error_qualification_incomplete'] = 'Qualification requirements are not complete';
$string['error_outcomes_not_finalized'] = 'Some units still have continuing/pending outcomes';
$string['error_unknown_certtype'] = 'Unknown certificate type';
$string['missing_unit'] = 'Missing/incomplete unit';
$string['hold_no_reason'] = 'No reason specified';
$string['indefinite'] = 'Indefinitely';
$string['attendance_nonaccredited_only'] = 'Certificate of Attendance is for non-accredited training only';
$string['no_enrolments_for_qual'] = 'No enrolments found for this qualification';

$string['holds'] = 'Certificate Holds';
$string['add_hold'] = 'Add Hold';
$string['remove_hold'] = 'Remove Hold';
$string['hold_added'] = 'Hold added successfully';
$string['hold_removed'] = 'Hold removed successfully';
$string['hold_type'] = 'Hold Type';
$string['hold_reason'] = 'Reason';
$string['hold_until'] = 'Until';

$string['issuable_units'] = 'Units Available for Statement of Attainment';
$string['issuable_qualifications'] = 'Qualifications Available for Testamur';
$string['already_issued'] = 'Already issued';
$string['not_ready'] = 'Not ready for issuance';
$string['ready_to_issue'] = 'Ready to issue';

$string['trainers'] = 'Trainers & Assessors';
$string['trainer_compliance'] = 'Trainer Compliance';
$string['trainer_credentials'] = 'Trainer Credentials';
$string['trainer_profile'] = 'Trainer Profile';

$string['taecredential'] = 'TAE Qualification';
$string['taecredential_help'] = 'TAE qualification code (e.g. TAE40122)';
$string['taedateachieved'] = 'TAE Date Achieved';
$string['taeevidence'] = 'TAE Certificate';
$string['vocationalqualifications'] = 'Vocational Qualifications';
$string['vocationalqualifications_help'] = 'List of vocational qualifications held by the trainer';

$string['industrycurrency'] = 'Industry Currency';
$string['industrycurrency_help'] = 'Description of current industry engagement and experience';
$string['industrycurrencydate'] = 'Currency Verified Date';
$string['industrycurrencyevidence'] = 'Industry Currency Evidence';

$string['vocationalcompetency'] = 'Vocational Competency';
$string['vocationalcompetency_help'] = 'Description of vocational competency relevant to training delivery';
$string['vocationalcompetencydate'] = 'Competency Verified Date';
$string['vocationalcompetencyevidence'] = 'Vocational Competency Evidence';

$string['cpdhours'] = 'CPD Hours';
$string['cpdhours_help'] = 'Continuing Professional Development hours for current year';
$string['cpdlog'] = 'CPD Activities Log';
$string['add_cpd_activity'] = 'Add CPD Activity';

$string['wwcc'] = 'Working With Children Check';
$string['wwccnumber'] = 'WWCC Number';
$string['wwccnumber_help'] = 'Working With Children Check or Blue Card number';
$string['wwccstate'] = 'WWCC Issuing State';
$string['wwccexpiry'] = 'WWCC Expiry Date';
$string['wwccstatus'] = 'WWCC Status';
$string['wwccevidence'] = 'WWCC Evidence';

$string['policecheck'] = 'Police Check';
$string['policechecknumber'] = 'Police Check Number';
$string['policechecknumber_help'] = 'National Police Check reference number';
$string['policecheckdate'] = 'Police Check Date';
$string['policecheckexpiry'] = 'Police Check Expiry';
$string['policecheckstatus'] = 'Police Check Status';
$string['policecheckevidence'] = 'Police Check Evidence';

$string['scopemapping'] = 'Scope Mapping';
$string['scopemapping_help'] = 'Qualifications this trainer is approved to deliver and assess';
$string['scopeunits'] = 'Approved Units';
$string['scopeunits_help'] = 'Specific units this trainer is approved to deliver and assess';
$string['scopenotes'] = 'Scope Notes';
$string['add_scope'] = 'Add Qualification to Scope';
$string['remove_scope'] = 'Remove from Scope';
$string['in_scope'] = 'In Scope';
$string['out_of_scope'] = 'Out of Scope';

$string['compliancestatus'] = 'Compliance Status';
$string['complianceissues'] = 'Compliance Issues';
$string['pending_review'] = 'Pending Review';
$string['nextreviewdate'] = 'Next Review Date';

$string['status_current'] = 'Current';
$string['status_expiring'] = 'Expiring Soon';
$string['status_expired'] = 'Expired';
$string['status_inactive'] = 'Inactive';
$string['status_na'] = 'Not Applicable';
$string['status_pending'] = 'Pending';

$string['upload_evidence'] = 'Upload Evidence';
$string['view_evidence'] = 'View Evidence';
$string['evidence_uploaded'] = 'Evidence uploaded successfully';
$string['evidence_deleted'] = 'Evidence deleted';
$string['no_evidence'] = 'No evidence uploaded';

$string['trainer_compliant'] = 'Trainer is compliant';
$string['trainer_noncompliant'] = 'Trainer has compliance issues';
$string['trainer_issues'] = 'Issues requiring attention';
$string['issue_wwcc_expired'] = 'Working With Children Check has expired';
$string['issue_wwcc_expiring'] = 'Working With Children Check expires within 30 days';
$string['issue_policecheck_expired'] = 'Police check has expired';
$string['issue_policecheck_expiring'] = 'Police check expires within 30 days';
$string['issue_tae_missing'] = 'TAE qualification not recorded';
$string['issue_industry_currency'] = 'Industry currency needs verification';
$string['issue_vocational_currency'] = 'Vocational competency needs verification';

$string['state_specific_fields'] = 'State-Specific Fields';
$string['qldlui'] = 'QLD Learner Unique Identifier (LUI)';
$string['qldlui_help'] = 'Learner Unique Identifier required for Queensland state-funded training';
$string['viccohortid'] = 'VIC Cohort Identifier';
$string['viccohortid_help'] = 'Commencing Program Cohort Identifier for Victorian SVTS reporting';
$string['nswsmartskilled'] = 'NSW Smart and Skilled ID';
$string['nswsmartskilled_help'] = 'Contract identifier for NSW Smart and Skilled funded training';
$string['waraptid'] = 'WA RAPT Identifier';
$string['waraptid_help'] = 'Registered Apprenticeship/Traineeship identifier for Western Australia';

$string['fundingsourcestate'] = 'State Funding Source';
$string['fundingsourcestate_help'] = 'State-specific funding source code for state reporting requirements';

$string['state_qld'] = 'Queensland';
$string['state_vic'] = 'Victoria';
$string['state_nsw'] = 'New South Wales';
$string['state_wa'] = 'Western Australia';
$string['state_sa'] = 'South Australia';
$string['state_tas'] = 'Tasmania';
$string['state_nt'] = 'Northern Territory';
$string['state_act'] = 'Australian Capital Territory';

$string['warning_continuing_units'] = 'Some units still have continuing/pending outcomes and will not appear on the Statement';

$string['task_cleanup_certificates'] = 'Clean up expired certificate verification codes';

$string['privacy:metadata:students'] = 'Student AVETMISS profile data for NCVER reporting';
$string['privacy:metadata:students:userid'] = 'The Moodle user ID of the student';
$string['privacy:metadata:students:usi'] = 'Unique Student Identifier';
$string['privacy:metadata:students:dateofbirth'] = 'Date of birth';
$string['privacy:metadata:students:indigenousstatus'] = 'Indigenous status code';
$string['privacy:metadata:students:countryofbirth'] = 'Country of birth code';
$string['privacy:metadata:students:disabilityflag'] = 'Disability flag';
// Task #101: Plain-English field descriptions for remaining students table fields
// and the complaints/appeals/rpl tables declared in get_metadata().
$string['privacy:metadata:students:firstname'] = 'Student first name as stored in the AVETMISS profile';
$string['privacy:metadata:students:lastname'] = 'Student last name as stored in the AVETMISS profile';
$string['privacy:metadata:students:sex'] = 'Sex as reported for AVETMISS (M/F/X/@ not stated)';
$string['privacy:metadata:students:surveycontactphone'] = 'Phone number used to contact the student for quality-indicator surveys';
$string['privacy:metadata:students:surveycontactemail'] = 'Email address used to contact the student for quality-indicator surveys';
$string['privacy:metadata:students:buildingname'] = 'Building or property name component of the student\'s residential address';
$string['privacy:metadata:students:unitno'] = 'Unit or apartment number component of the student\'s residential address';
$string['privacy:metadata:students:streetno'] = 'Street number component of the student\'s residential address';
$string['privacy:metadata:students:streetname'] = 'Street name component of the student\'s residential address';
$string['privacy:metadata:students:suburb'] = 'Suburb or town component of the student\'s residential address';
$string['privacy:metadata:students:postcode'] = 'Postcode component of the student\'s residential address';
$string['privacy:metadata:students:statecode'] = 'State or territory code component of the student\'s residential address (AVETMISS code)';

$string['privacy:metadata:enrolments'] = 'Training activity enrolment records for AVETMISS reporting';
$string['privacy:metadata:enrolments:studentid'] = 'The student record ID';
$string['privacy:metadata:enrolments:courseid'] = 'The Moodle course ID';
$string['privacy:metadata:enrolments:outcomeidentifier'] = 'AVETMISS outcome code';
$string['privacy:metadata:enrolments:activitystartdate'] = 'Activity start date';

$string['privacy:metadata:trainers'] = 'Trainer credential records for RTO compliance';
$string['privacy:metadata:trainers:userid'] = 'The user ID of the trainer';
$string['privacy:metadata:trainers:taecredential'] = 'TAE qualification details';
$string['privacy:metadata:trainers:vocationalqualifications'] = 'Vocational qualifications';
$string['privacy:metadata:trainers:industrycurrency'] = 'Industry currency evidence';
$string['privacy:metadata:trainers:cpdhours'] = 'CPD hours logged';

$string['privacy:metadata:certs'] = 'Certificate issuance records';
$string['privacy:metadata:certs:userid'] = 'The user ID of the certificate holder';
$string['privacy:metadata:certs:certnumber'] = 'Certificate number';
$string['privacy:metadata:certs:certtype'] = 'Certificate type';
$string['privacy:metadata:certs:qualificationname'] = 'Qualification name';
$string['privacy:metadata:certs:issuedate'] = 'Issue date';

$string['privacy:metadata:surveys'] = 'Quality indicator survey responses';
$string['privacy:metadata:surveys:respondentid'] = 'The user ID of the respondent';
$string['privacy:metadata:surveys:responses'] = 'Survey responses';
$string['privacy:metadata:surveys:comments'] = 'Additional comments';

$string['privacy:metadata:log'] = 'Compliance audit log entries';
$string['privacy:metadata:log:userid'] = 'The user ID who performed the action';
$string['privacy:metadata:log:action'] = 'The action performed';
$string['privacy:metadata:log:ipaddress'] = 'IP address';

$string['privacy:metadata:cricos_students'] = 'International student CRICOS data for ESOS/PRISMS reporting';
$string['privacy:metadata:cricos_students:userid'] = 'The Moodle user ID';
$string['privacy:metadata:cricos_students:visasubclass'] = 'Visa subclass';
$string['privacy:metadata:cricos_students:passportnumber'] = 'Passport number';
$string['privacy:metadata:cricos_students:guardianname'] = 'Guardian name';

$string['privacy:metadata:cricos_coe'] = 'Confirmation of Enrolment records';
$string['privacy:metadata:cricos_coe:cricosstudentid'] = 'CRICOS student record ID';
$string['privacy:metadata:cricos_coe:coenumber'] = 'CoE number';
$string['privacy:metadata:cricos_coe:coursestartdate'] = 'Course start date';
// Task #101: Plain-English field descriptions for complaints, appeals, and RPL tables.
$string['privacy:metadata:complaints'] = 'Formal complaints lodged with or about the RTO, including complainant contact details, the nature of the complaint, and its resolution.';
$string['privacy:metadata:complaints:complainantname'] = 'Full name of the person who lodged the complaint';
$string['privacy:metadata:complaints:complainantemail'] = 'Email address of the complainant';
$string['privacy:metadata:complaints:complainantphone'] = 'Phone number of the complainant';
$string['privacy:metadata:complaints:description'] = 'Full description of the complaint as submitted';
$string['privacy:metadata:complaints:resolution'] = 'Outcome or resolution applied to the complaint';
$string['privacy:metadata:appeals'] = 'Formal assessment appeals lodged by students, including appellant contact details, the grounds for appeal, and the outcome.';
$string['privacy:metadata:appeals:appellantname'] = 'Full name of the student who lodged the appeal';
$string['privacy:metadata:appeals:appellantemail'] = 'Email address of the appellant';
$string['privacy:metadata:appeals:appellantphone'] = 'Phone number of the appellant';
$string['privacy:metadata:appeals:groundsforappeal'] = 'The stated reasons for the appeal';
$string['privacy:metadata:appeals:outcome'] = 'Decision or outcome applied to the appeal';
$string['privacy:metadata:rpl'] = 'Recognition of Prior Learning records, capturing evidence submitted by students and the resulting decisions.';
$string['privacy:metadata:rpl:studentid'] = 'Internal RTO Compliance student record ID (foreign key to student profile)';
$string['privacy:metadata:rpl:evidence'] = 'Description or reference to evidence supplied by the student to support the RPL claim';
$string['privacy:metadata:rpl:decision'] = 'The RPL decision (e.g. granted, not granted, partially granted)';
$string['privacy:metadata:rpl:decisiondate'] = 'Date the RPL decision was made';

$string['email_certificate_confirm'] = 'Are you sure you want to email the {$a->certtype} (#{$a->certnumber}) to {$a->fullname} at {$a->email}?';
$string['email_certificate_subject'] = 'Your {$a} Certificate';
$string['email_certificate_body'] = '<p>Dear {$a->fullname},</p><p>Please find attached your {$a->certtype}.</p><p>Certificate Number: {$a->certnumber}</p><p>If you have any questions, please contact us.</p><p>Kind regards,<br>{$a->rtoname}</p>';

// v4.2.36 CERTIFICATES-REDESIGN — reissue email + certificates page UI strings.
$string['email_reissue_subject'] = 'Reissued: Your {$a->certtype} Certificate (replaces {$a->originalnumber})';
$string['email_reissue_body'] = '<p>Dear {$a->fullname},</p><p>Please find attached a reissued copy of your {$a->certtype}.</p><p>New Certificate Number: <strong>{$a->certnumber}</strong><br>Replaces: {$a->originalnumber} (originally issued {$a->originaldate})</p><p>This reissued certificate supersedes the original. If you have any questions, please contact us.</p><p>Kind regards,<br>{$a->rtoname}</p>';
$string['certificates_filter_search'] = 'Search student or cert number';
$string['certificates_filter_certtype'] = 'Certificate type';
$string['certificates_filter_qualification'] = 'Qualification';
$string['certificates_filter_year'] = 'Issue year';
$string['certificates_filter_datefrom'] = 'Issued from';
$string['certificates_filter_dateto'] = 'Issued to';
$string['certificates_filter_usi'] = 'USI status';
$string['certificates_filter_email'] = 'Email status';
$string['certificates_filter_apply'] = 'Apply filters';
$string['certificates_filter_clear'] = 'Clear';
$string['certificates_view_cards'] = 'Cards';
$string['certificates_view_table'] = 'Table';
$string['certificates_action_delete'] = 'Delete';
$string['certificates_action_email'] = 'Email';
$string['certificates_action_reissue'] = 'Reissue';
$string['certificates_action_view'] = 'View';
$string['certificates_action_download'] = 'Download';
$string['certificates_reissue_confirm'] = 'Reissue {$a->certnumber} for {$a->fullname}? A new certificate will be created (5 credits) and the original preserved for the audit trail.';
$string['certificates_replaced_by'] = 'Replaced by {$a}';
$string['certificates_replaces'] = 'Replaces {$a}';
$string['certificates_no_results'] = 'No certificates match the current filters.';
$string['certificates_showing'] = 'Showing {$a->from}-{$a->to} of {$a->total}';
$string['certificate_emailed'] = 'Certificate has been emailed successfully.';
$string['certificate_already_emailed'] = 'This certificate has already been emailed to the student.';

$string['rtocompliance_settings'] = 'RTO Compliance Settings';
$string['rtocompliance_settings_desc'] = 'Configure RTO compliance settings for this course. Mark courses as nationally recognised training if they require AVETMISS data collection from students.';
$string['nationallyrecognised_header'] = 'Nationally Recognised Training';
$string['nationallyrecognised'] = 'Nationally Recognised Course';
$string['nationallyrecognised_desc'] = 'This course is nationally recognised training (VET/accredited) and requires AVETMISS data collection';
$string['nationallyrecognised_help'] = 'Enable this if this course leads to a nationally recognised qualification or unit of competency. Students enrolled will be required to complete their AVETMISS profile (USI, date of birth, address, etc.) for government reporting purposes.';
$string['qualificationcode'] = 'Qualification Code';
$string['qualificationcode_help'] = 'The national qualification code from training.gov.au (e.g., BSB50420 for Diploma of Leadership and Management)';
$string['qualificationname'] = 'Qualification Name';
$string['nominalhours'] = 'Nominal Hours';
$string['nominalhours_help'] = 'The total nominal hours for the qualification as specified on training.gov.au';
$string['cricos_header'] = 'CRICOS Registration';
$string['cricosregistered'] = 'CRICOS Registered';
$string['cricosregistered_desc'] = 'This course is registered on CRICOS for international students';
$string['cricoscode'] = 'CRICOS Course Code';
$string['cricoscode_help'] = 'The CRICOS course code (e.g., 123456A) if this course is registered for international students';
$string['settingssaved'] = 'Course RTO compliance settings saved.';
$string['avetmiss_required_notice'] = 'You are enrolled in nationally recognised training. Please complete your AVETMISS profile to meet government reporting requirements.';
$string['avetmiss_required_courses'] = 'The following courses require AVETMISS data:';
$string['no_avetmiss_required'] = 'This student is not currently enrolled in any nationally recognised training courses that require AVETMISS data.';
$string['complete_avetmiss_profile'] = 'Complete AVETMISS Profile';

$string['labourforcestatus'] = 'Labour Force Status';
$string['labourforcestatus_help'] = 'The client\'s labour force status at the time of enrolment. This is a required AVETMISS field for NAT00080.';
$string['studyreason'] = 'Study Reason';
$string['studyreason_help'] = 'The main reason why the client is undertaking the training. This is a required AVETMISS field for NAT00080.';
$string['prioreducationflag'] = 'Prior Educational Achievement';
$string['prioreducationflag_help'] = 'Whether the client has successfully completed any qualification or statement of attainment prior to commencing this program.';
$string['surveycontactstatus'] = 'Survey Contact Status';
$string['surveycontactstatus_help'] = 'Indicates whether the client agrees to be contacted for Quality Indicator surveys. A=Agrees, E=Valid excuse, M=No mail contact, N=Does not agree.';

$string['ai_not_configured'] = 'AI integration is not configured. Please add your API key in the plugin settings.';
$string['no_survey_responses'] = 'No survey responses found for the selected period.';
$string['ai_api_error'] = 'Error communicating with AI service: {$a}';
$string['ai_parse_error'] = 'Error parsing AI response: {$a}';

$string['ai_survey_analysis'] = 'AI Survey Analysis';
$string['ai_survey_analysis_desc'] = 'Use AI to analyse Quality Indicator survey responses and identify key themes, strengths, and areas for improvement.';
$string['run_analysis'] = 'Run AI Analysis';
$string['analysis_results'] = 'Analysis Results';
$string['sentiment'] = 'Overall Sentiment';
$string['satisfaction_index'] = 'Satisfaction Index';
$string['key_themes'] = 'Key Themes';
$string['strengths'] = 'Strengths';
$string['improvements'] = 'Areas for Improvement';
$string['recommendations'] = 'AI Recommendations';

$string['compliance_alerts'] = 'Compliance Alerts';
$string['compliance_alerts_desc'] = 'AI-powered predictive alerts to help you stay ahead of compliance issues.';
$string['alert_critical'] = 'Critical';
$string['alert_high'] = 'High Priority';
$string['alert_medium'] = 'Medium Priority';
$string['alert_low'] = 'Low Priority';
$string['acknowledge_alert'] = 'Acknowledge';
$string['resolve_alert'] = 'Mark Resolved';
$string['dismiss_alert'] = 'Dismiss';

$string['alert_trainer_expiry_title'] = 'Trainer credential expiring: {$a}';
$string['alert_trainer_expiry_desc'] = 'The following credentials are expiring soon: {$a}';
$string['alert_trainer_expiry_action'] = 'Update trainer credentials or arrange renewal before expiry to maintain compliance.';

$string['alert_missing_usi_title'] = '{$a} students missing USI with completed units';
$string['alert_missing_usi_desc'] = 'Students with completed training outcomes are missing their USI, which is required for AVETMISS reporting.';
$string['alert_missing_usi_action'] = 'Contact students to obtain and verify their USI before the next reporting period.';

$string['alert_incomplete_profiles_title'] = '{$a} students with incomplete AVETMISS profiles';
$string['alert_incomplete_profiles_desc'] = '{$a}% of students have incomplete AVETMISS data. This may affect your NAT file submission quality.';
$string['alert_incomplete_profiles_action'] = 'Send reminders to students to complete their AVETMISS profile information.';

$string['alert_deadline_approaching'] = 'This deadline is due in {$a} days.';
$string['alert_deadline_action'] = 'Review requirements and prepare submission before the due date.';

$string['alert_invalid_postcodes_title'] = '{$a} students with invalid postcodes';
$string['alert_invalid_postcodes_desc'] = 'Postcodes must be exactly 4 numeric digits for valid AVETMISS data.';
$string['alert_invalid_postcodes_action'] = 'Review and correct postcode data in student profiles.';

$string['alert_missing_outcomes_title'] = '{$a} enrolments missing outcome codes';
$string['alert_missing_outcomes_desc'] = 'Completed enrolments must have a valid outcome code assigned for accurate NAT00120 reporting.';
$string['alert_missing_outcomes_action'] = 'Review enrolment records and assign appropriate outcome codes.';

$string['alert_pending_certs_title'] = '{$a} certificates pending for over 7 days';
$string['alert_pending_certs_desc'] = 'Certificates should be issued promptly after completion to maintain student satisfaction and compliance.';
$string['alert_pending_certs_action'] = 'Review pending certificates and issue or reject as appropriate.';

$string['compliance_summary'] = 'Compliance Summary';
$string['risk_score'] = 'Risk Score';
$string['overall_status'] = 'Overall Status';
$string['status_excellent'] = 'Excellent';
$string['status_good'] = 'Good';
$string['status_attention'] = 'Needs Attention';
$string['status_warning'] = 'Warning';
$string['status_critical'] = 'Critical';

// ASQA 2025 Complaints & Appeals Register
$string['complaints_appeals'] = 'Complaints & Appeals';
$string['complaints'] = 'Complaints';
$string['continuous_improvement'] = 'Continuous Improvement';

$string['complaint_details'] = 'Complaint Details';
$string['complaint_reference'] = 'Reference Number';
$string['complainant_type'] = 'Complainant Type';
$string['complainant_student'] = 'Student';
$string['complainant_employer'] = 'Employer';
$string['complainant_public'] = 'Public';
$string['complainant_anonymous'] = 'Anonymous';
$string['is_anonymous'] = 'Anonymous Complaint';
$string['complainant_name'] = 'Complainant Name';
$string['complainant_email'] = 'Complainant Email';
$string['complainant_phone'] = 'Complainant Phone';
$string['issue_information'] = 'Issue Information';
$string['complaint_category'] = 'Category';
$string['complaint_subcategory'] = 'Subcategory';
$string['complaint_subject'] = 'Subject';
$string['complaint_description'] = 'Description';
$string['category_training'] = 'Training & Delivery';
$string['category_assessment'] = 'Assessment';
$string['category_service'] = 'Service Delivery';
$string['category_conduct'] = 'Staff Conduct';
$string['category_facilities'] = 'Facilities & Resources';
$string['category_other'] = 'Other';
$string['category_compliance'] = 'Compliance';
$string['category_governance'] = 'Governance';
$string['priority'] = 'Priority';
$string['priority_low'] = 'Low';
$string['priority_medium'] = 'Medium';
$string['priority_high'] = 'High';
$string['priority_critical'] = 'Critical';
$string['status'] = 'Status';
$string['status_received'] = 'Received';
$string['status_investigating'] = 'Investigating';
$string['status_resolved'] = 'Resolved';
$string['status_closed'] = 'Closed';
$string['status_withdrawn'] = 'Withdrawn';
$string['date_received'] = 'Date Received';
$string['target_resolution_date'] = 'Target Resolution Date';
$string['resolution_details'] = 'Resolution Details';
$string['date_acknowledged'] = 'Date Acknowledged';
$string['actual_resolution_date'] = 'Actual Resolution Date';
$string['actual_resolution_date_help'] = 'Select the date the complaint was actually resolved. This is the date the outcome was communicated to the complainant and all agreed actions were completed. Used to calculate resolution time against the 60-day target.';
$string['outcome_satisfactory'] = 'Outcome Satisfactory';
$string['is_systemic'] = 'Systemic Issue Identified';
$string['additional_information'] = 'Additional Information';
$string['notes'] = 'Notes';
$string['new_complaint'] = 'New Complaint';
$string['edit_complaint'] = 'Edit Complaint';
$string['complaint_created'] = 'Complaint created successfully';
$string['complaint_updated'] = 'Complaint updated successfully';
$string['complaint_deleted'] = 'Complaint deleted successfully';
$string['error_duplicate_reference'] = 'This reference number already exists';

// Appeals
$string['appeal_details'] = 'Appeal Details';
$string['appeal_reference'] = 'Appeal Reference';
$string['linked_complaint'] = 'Linked Complaint';
$string['appeal_type'] = 'Appeal Type';
$string['appeal_type_complaint'] = 'Complaint Outcome';
$string['appeal_type_assessment'] = 'Assessment Decision';
$string['appeal_type_enrolment'] = 'Enrolment Decision';
$string['appeal_type_fee'] = 'Fee or Refund';
$string['appeal_type_other'] = 'Other';
$string['appellant_information'] = 'Appellant Information';
$string['appellant_name'] = 'Appellant Name';
$string['appellant_email'] = 'Appellant Email';
$string['appellant_phone'] = 'Appellant Phone';
$string['appeal_grounds'] = 'Grounds for Appeal';
$string['grounds_for_appeal'] = 'Grounds for Appeal';
$string['original_decision'] = 'Original Decision';
$string['original_decision_date'] = 'Original Decision Date';
$string['appeal_processing'] = 'Appeal Processing';
$string['appeal_status_lodged'] = 'Lodged';
$string['appeal_status_reviewing'] = 'Under Review';
$string['appeal_status_hearing'] = 'Hearing Scheduled';
$string['appeal_status_decided'] = 'Decided';
$string['appeal_status_closed'] = 'Closed';
$string['date_lodged'] = 'Date Lodged';
$string['hearing_date'] = 'Hearing Date';
$string['panel_members'] = 'Panel Members';
$string['appeal_outcome'] = 'Appeal Outcome';
$string['outcome'] = 'Outcome';
$string['outcome_upheld'] = 'Upheld';
$string['outcome_partially_upheld'] = 'Partially Upheld';
$string['outcome_not_upheld'] = 'Not Upheld';
$string['outcome_withdrawn'] = 'Withdrawn';
$string['outcome_reason'] = 'Outcome Reason';
$string['decision_date'] = 'Decision Date';
$string['external_review'] = 'External Review';
$string['external_review_offered'] = 'External Review Offered';
$string['external_review_taken'] = 'External Review Taken';
$string['external_review_body'] = 'External Review Body';
$string['new_appeal'] = 'New Appeal';
$string['edit_appeal'] = 'Edit Appeal';
$string['appeal_created'] = 'Appeal created successfully';
$string['appeal_updated'] = 'Appeal updated successfully';
$string['appeal_deleted'] = 'Appeal deleted successfully';

// Continuous Improvements
$string['improvement_details'] = 'Improvement Details';
$string['improvement_reference'] = 'Reference Number';
$string['improvement_title'] = 'Title';
$string['improvement_description'] = 'Description';
$string['source_type'] = 'Source Type';
$string['source_complaint'] = 'Complaint';
$string['source_appeal'] = 'Appeal';
$string['source_validation'] = 'Validation';
$string['source_audit'] = 'Audit';
$string['source_feedback'] = 'Feedback';
$string['source_survey'] = 'Survey';
$string['source_incident'] = 'Incident';
$string['improvement_category'] = 'Category';
$string['improvement_status_identified'] = 'Identified';
$string['improvement_status_planned'] = 'Planned';
$string['improvement_status_inprogress'] = 'In Progress';
$string['improvement_status_completed'] = 'Completed';
$string['improvement_status_verified'] = 'Verified';
$string['improvement_status_closed'] = 'Closed';
$string['timeline'] = 'Timeline';
$string['date_identified'] = 'Date Identified';
$string['target_date'] = 'Target Date';
$string['completion_date'] = 'Completion Date';
$string['action_plan'] = 'Action Plan';
$string['action_plan_details'] = 'Action Plan Details';
$string['improvement_outcome'] = 'Outcome';
$string['verification'] = 'Verification';
$string['effectiveness_verified'] = 'Effectiveness Verified';
$string['verification_date'] = 'Verification Date';
$string['verification_method'] = 'Verification Method';
$string['new_improvement'] = 'New Improvement';
$string['edit_improvement'] = 'Edit Improvement';
$string['improvement_created'] = 'Improvement action created successfully';
$string['improvement_updated'] = 'Improvement action updated successfully';
$string['improvement_deleted'] = 'Improvement action deleted successfully';

// Third-Party Arrangements
$string['thirdparty'] = 'Third-Party Arrangements';
$string['thirdparty_details'] = 'Arrangement Details';
$string['organisation_name'] = 'Organisation Name';
$string['trading_name'] = 'Trading Name';
$string['arrangement_type'] = 'Arrangement Type';
$string['arrangement_partnership'] = 'Partnership';
$string['arrangement_subcontract'] = 'Subcontract';
$string['arrangement_auspice'] = 'Auspice';
$string['arrangement_venue'] = 'Venue Provider';
$string['contact_name'] = 'Contact Name';
$string['contact_email'] = 'Contact Email';
$string['contact_phone'] = 'Contact Phone';
$string['agreement_start_date'] = 'Agreement Start Date';
$string['agreement_end_date'] = 'Agreement End Date';
$string['qualifications_covered'] = 'Qualifications Covered';
$string['asqa_notified'] = 'ASQA Notified';
$string['asqa_notification_date'] = 'ASQA Notification Date';
$string['notification_deadline'] = 'Notification Deadline (30 days)';
$string['mandatory_clauses'] = 'Mandatory Clauses';
$string['clause_nrt_logo'] = 'NRT Logo Prohibition';
$string['clause_aqf'] = 'AQF Issuance Prohibition';
$string['clause_transparency'] = 'Student Transparency';
$string['monitoring_frequency'] = 'Monitoring Frequency';
$string['frequency_monthly'] = 'Monthly';
$string['frequency_quarterly'] = 'Quarterly';
$string['frequency_biannual'] = 'Bi-Annual';
$string['frequency_annual'] = 'Annual';
$string['last_monitoring_date'] = 'Last Monitoring Date';
$string['next_monitoring_date'] = 'Next Monitoring Date';
$string['risk_rating'] = 'Risk Rating';
$string['risk_low'] = 'Low';
$string['risk_medium'] = 'Medium';
$string['risk_high'] = 'High';
$string['staff_credentials_verified'] = 'Staff Credentials Verified';

// Governance
$string['governance'] = 'Governance & ADC';
$string['governing_persons'] = 'Governing Persons';
$string['material_changes'] = 'Material Changes';
$string['annual_declaration'] = 'Annual Declaration of Compliance';
$string['full_name'] = 'Full Name';
$string['position'] = 'Position';
$string['position_type'] = 'Position Type';
$string['position_director'] = 'Director';
$string['position_ceo'] = 'CEO';
$string['position_secretary'] = 'Secretary';
$string['position_public_officer'] = 'Public Officer';
$string['appointment_date'] = 'Appointment Date';
$string['cessation_date'] = 'Cessation Date';
$string['fit_proper_declared'] = 'Fit & Proper Declared';
$string['fit_proper_declared_date'] = 'Declaration Date';
$string['suitability_assessed'] = 'Suitability Assessed';
$string['suitability_assessed_date'] = 'Assessment Date';
$string['police_check_date'] = 'Police Check Date';
$string['police_check_status'] = 'Police Check Status';
$string['change_type'] = 'Change Type';
$string['change_description'] = 'Change Description';
$string['effective_date'] = 'Effective Date';
$string['asqa_acknowledged'] = 'ASQA Acknowledged';
$string['asqa_reference'] = 'ASQA Reference';
$string['impact_assessment'] = 'Impact Assessment';
$string['mitigation_actions'] = 'Mitigation Actions';
$string['adc_year'] = 'Year';
$string['adc_due_date'] = 'Due Date';
$string['adc_submission_date'] = 'Submission Date';
$string['declarant_name'] = 'Declarant Name';
$string['declarant_position'] = 'Declarant Position';
$string['evidence_collected'] = 'Evidence Collected';
$string['asqa_confirmation_ref'] = 'ASQA Confirmation Reference';

// Fee Protection
$string['feeprotection'] = 'Fee Protection';
$string['fee_receipts'] = 'Fee Receipts';
$string['fee_type'] = 'Fee Type';
$string['fee_tuition'] = 'Tuition';
$string['fee_materials'] = 'Materials';
$string['fee_administration'] = 'Administration';
$string['fee_other'] = 'Other';
$string['amount'] = 'Amount';
$string['payment_date'] = 'Payment Date';
$string['payment_method'] = 'Payment Method';
$string['receipt_reference'] = 'Receipt Reference';
$string['is_protected'] = 'Protected Fee';
$string['protection_method'] = 'Protection Method';
$string['threshold_alert'] = 'Threshold Alert ($1500)';
$string['running_total'] = 'Running Total';
$string['refunded'] = 'Refunded';
$string['refund_date'] = 'Refund Date';
$string['refund_amount'] = 'Refund Amount';

// Insurance Register
$string['insurance'] = 'Insurance Register';
$string['insurance_type'] = 'Insurance Type';
$string['insurance_public_liability'] = 'Public Liability';
$string['insurance_professional_indemnity'] = 'Professional Indemnity';
$string['insurance_workers_comp'] = 'Workers Compensation';
$string['provider'] = 'Provider';
$string['policy_number'] = 'Policy Number';
$string['coverage_amount'] = 'Coverage Amount';
$string['premium'] = 'Premium';
$string['excess_amount'] = 'Excess Amount';
$string['coverage_details'] = 'Coverage Details';
$string['exclusions'] = 'Exclusions';
$string['delivery_modes'] = 'Delivery Modes Covered';
$string['locations'] = 'Locations Covered';
$string['start_date'] = 'Start Date';
$string['expiry_date'] = 'Expiry Date';
$string['renewal_reminder_days'] = 'Renewal Reminder (Days)';

// Training Product Transitions
$string['transitions'] = 'Training Product Transitions';
$string['old_product_code'] = 'Old Product Code';
$string['old_product_name'] = 'Old Product Name';
$string['new_product_code'] = 'New Product Code';
$string['new_product_name'] = 'New Product Name';
$string['transition_type'] = 'Transition Type';
$string['transition_superseded'] = 'Superseded';
$string['transition_type_deleted'] = 'Deleted';
$string['transition_type_updated'] = 'Updated';
$string['tga_notification_date'] = 'TGA Notification Date';
$string['teachout_deadline'] = 'Teach-Out Deadline';
$string['students_affected'] = 'Students Affected';
$string['students_contacted'] = 'Students Contacted';
$string['transition_plan'] = 'Transition Plan';
$string['mapping_document'] = 'Mapping Document';
$string['scope_updated'] = 'Scope Updated';
$string['enrolments_closed'] = 'Enrolments Closed';

// Validation Schedule
$string['validation'] = 'Validation Schedule';
$string['validation_details'] = 'Validation Details';
$string['validation_reference'] = 'Reference';
$string['product_code'] = 'Product Code';
$string['product_name'] = 'Product Name';
$string['unit_codes'] = 'Unit Codes';
$string['validation_type'] = 'Validation Type';
$string['validation_initial'] = 'Initial';
$string['validation_ongoing'] = 'Ongoing';
$string['validation_post_assessment'] = 'Post-Assessment';
$string['risk_level'] = 'Risk Level';
$string['risk_factors'] = 'Risk Factors';
$string['scheduled_date'] = 'Scheduled Date';
$string['actual_date'] = 'Actual Date';
$string['lead_validator'] = 'Lead Validator';
$string['validators'] = 'Validators';
$string['methodologies'] = 'Methodologies Used';
$string['sample_size'] = 'Sample Size';
$string['sampling_method'] = 'Sampling Method';
$string['findings_count'] = 'Findings Count';
$string['findings'] = 'Findings';
$string['improvements_linked'] = 'Linked Improvements';
$string['report_document'] = 'Report Document';
$string['adc_linked'] = 'ADC Linked';

// Validators Register
$string['validators_register'] = 'Validators Register';
$string['is_internal'] = 'Internal Validator';
$string['organisation'] = 'Organisation';
$string['role_type'] = 'Role Type';
$string['role_3a'] = '3A - Lead Validator';
$string['role_3b'] = '3B - Participating Validator';
$string['tae_credential'] = 'TAE Credential';
$string['tae_date_achieved'] = 'TAE Date Achieved';
$string['vocational_qualifications'] = 'Vocational Qualifications';
$string['industry_experience'] = 'Industry Experience';
$string['industry_experience_years'] = 'Years of Experience';
$string['current_industry_engagement'] = 'Current Industry Engagement';
$string['specialisations'] = 'Specialisations';
$string['validations_led'] = 'Validations Led';
$string['validations_participated'] = 'Validations Participated';
$string['last_validation_date'] = 'Last Validation Date';

// TAS Generator
$string['tas'] = 'Training & Assessment Strategy';
$string['tas_deleted'] = 'TAS document deleted successfully.';
$string['tas_details'] = 'TAS Details';
$string['qualification_code'] = 'Qualification Code';
$string['qualification_name'] = 'Qualification Name';
$string['version'] = 'Version';
$string['effective_date'] = 'Effective Date';
$string['review_date'] = 'Review Date';
$string['approved_by'] = 'Approved By';
$string['approval_date'] = 'Approval Date';
$string['target_cohort'] = 'Target Learner Cohort';
$string['entry_requirements'] = 'Entry Requirements';
$string['volume_of_learning'] = 'Volume of Learning (Hours)';
$string['delivery_mode'] = 'Delivery Mode';
$string['delivery_locations'] = 'Delivery Locations';
$string['add_location'] = 'Add Delivery Location';
$string['edit_location'] = 'Edit Delivery Location';
$string['location_created'] = 'Delivery location created successfully.';
$string['location_updated'] = 'Delivery location updated successfully.';
$string['location_deleted'] = 'Delivery location deleted.';
$string['no_locations'] = 'No delivery locations have been added yet.';
$string['add_first_location'] = 'Add your first delivery location';
$string['locations_intro'] = 'Delivery locations are used in enrolment records and AVETMISS NAT120 export. Add a record for each physical site, online delivery, or workplace where training occurs.';
$string['location_id'] = 'Location ID';
$string['location_name'] = 'Location Name';
$string['location_identifier'] = 'Location Identifier';
$string['location_address'] = 'Address Details';
$string['location_contact'] = 'Contact Details';
$string['location_id_help'] = 'A unique identifier for this location (letters and numbers only, max 10 characters). Used in AVETMISS NAT120. Example: MAIN, ONLINE, PERTH01.';
$string['confirm_delete_location'] = 'Are you sure you want to delete this delivery location? Existing enrolments will retain the location ID.';
$string['inactive'] = 'Inactive';
$string['suburb'] = 'Suburb';
$string['postcode'] = 'Postcode';
$string['state'] = 'State/Territory';
$string['error_location_id_format'] = 'Location ID must be 1-10 uppercase letters and numbers only.';
$string['error_location_id_duplicate'] = 'A location with this ID already exists. Please use a unique Location ID.';
$string['location_list_empty_hint'] = 'No delivery locations have been configured yet. Go to {$a} to add your training sites.';
$string['assessor_list_empty_hint'] = 'No active trainers found. Go to {$a} to add trainers — they will appear here once added.';
$string['error_userid_missing'] = 'No student was specified. Please select a student from the student list.';
$string['error_student_not_found'] = 'The requested student could not be found. They may have been deleted. Please return to the student list.';
$string['duration'] = 'Duration';
$string['work_placement'] = 'Work Placement Required';
$string['work_placement_hours'] = 'Work Placement Hours';
$string['work_placement_details'] = 'Work Placement Details';
$string['learner_support'] = 'Learner Support';
$string['risk_management'] = 'Risk Management';
$string['marketing_compliance'] = 'Marketing Compliance';
$string['transition_procedures'] = 'Transition Procedures';
$string['complaints_process'] = 'Complaints Process';
$string['continuous_improvement_tas'] = 'Continuous Improvement';
$string['completeness_score'] = 'Completeness Score';
$string['generate_html'] = 'Generate HTML Document';

// Industry Consultation
$string['industry_consultations'] = 'Industry Consultations';
$string['consultation_type'] = 'Consultation Type';
$string['consultation_date'] = 'Consultation Date';
$string['participant_name'] = 'Participant Name';
$string['participant_role'] = 'Participant Role';
$string['participant_organisation'] = 'Participant Organisation';
$string['topics_discussed'] = 'Topics Discussed';
$string['feedback'] = 'Feedback';
$string['actions_agreed'] = 'Actions Agreed';
$string['evidence_document'] = 'Evidence Document';

// Delivery Schedule
$string['delivery_schedule'] = 'Delivery Schedule';
$string['unit_code'] = 'Unit Code';
$string['unit_name'] = 'Unit Name';
$string['sequence_order'] = 'Sequence Order';
$string['scheduled_weeks'] = 'Scheduled Weeks';
$string['nominal_hours'] = 'Nominal Hours';
$string['supervised_hours'] = 'Supervised Hours';
$string['unsupervised_hours'] = 'Unsupervised Hours';

// Assessment Mapping
$string['assessment_mapping'] = 'Assessment Mapping';
$string['assessment_name'] = 'Assessment Name';
$string['assessment_type'] = 'Assessment Type';
$string['elements_assessed'] = 'Elements Assessed';
$string['criteria_mapped'] = 'Criteria Mapped';
$string['methods_used'] = 'Methods Used';
$string['conditions_required'] = 'Conditions Required';

// Trainer Mapping
$string['trainer_mapping'] = 'Trainer Mapping';
$string['role'] = 'Role';
$string['units_covered'] = 'Units Covered';
$string['credential_verified'] = 'Credential Verified';
$string['credential_verified_date'] = 'Verification Date';

// Resources
$string['resources'] = 'Resources';
$string['resource_type'] = 'Resource Type';
$string['resource_learning'] = 'Learning Resource';
$string['resource_equipment'] = 'Equipment';
$string['resource_software'] = 'Software';
$string['resource_facility'] = 'Facility';
$string['resource_name'] = 'Resource Name';
$string['description'] = 'Description';
$string['quantity'] = 'Quantity';
$string['location'] = 'Location';
$string['available'] = 'Available';
$string['maintenance_date'] = 'Maintenance Date';

// CRUD success messages
$string['thirdparty_created'] = 'Third-party arrangement created successfully';
$string['thirdparty_updated'] = 'Third-party arrangement updated successfully';
$string['thirdparty_deleted'] = 'Third-party arrangement deleted successfully';
$string['governance_created'] = 'Governing person created successfully';
$string['governance_updated'] = 'Governing person updated successfully';
$string['governance_deleted'] = 'Governing person deleted successfully';
$string['insurance_created'] = 'Insurance policy created successfully';
$string['insurance_updated'] = 'Insurance policy updated successfully';
$string['insurance_deleted'] = 'Insurance policy deleted successfully';
$string['transition_created'] = 'Product transition created successfully';
$string['transition_updated'] = 'Product transition updated successfully';
$string['transition_deleted'] = 'Product transition deleted successfully';
$string['validation_created'] = 'Validation event created successfully';
$string['validation_updated'] = 'Validation event updated successfully';
$string['validation_deleted'] = 'Validation event deleted successfully';
$string['validator_created'] = 'Validator created successfully';
$string['validator_updated'] = 'Validator updated successfully';
$string['validator_deleted'] = 'Validator deleted successfully';

// Additional UI strings
$string['contactdetails'] = 'Contact Details';
$string['abn'] = 'ABN';
$string['email'] = 'Email';
$string['phone'] = 'Phone';

// Scheduled task strings
$string['task_cleanup_logs'] = 'Clean up old audit log entries';
$string['task_update_trainer_status'] = 'Update trainer credential status';
$string['task_refresh_metrics'] = 'Refresh compliance dashboard metrics';
$string['task_process_enrolment'] = 'Process enrolment changes';

// Performance optimization
$string['cache_dashboard_metrics'] = 'Dashboard metrics cache';
$string['cache_student_counts'] = 'Student counts cache';
$string['cache_trainer_status'] = 'Trainer status cache';
$string['cache_course_settings'] = 'Course settings cache';
$string['cache_compliance_summary'] = 'Compliance summary cache';
$string['cache_avetmiss_codes'] = 'AVETMISS codes cache';

// USI Verification
$string['usisettings'] = 'USI Verification Settings';
$string['usisettings_desc'] = 'Configure connection to the Australian Government USI Registry for automated USI verification using Machine Authentication Service (MAS-ST).';
$string['usi_verification_enabled'] = 'Enable USI Verification';
$string['usi_verification_enabled_desc'] = 'Enable automated verification of student USIs against the government registry';
$string['usi_auto_verified'] = 'USI verified successfully against usi.gov.au.';
$string['usi_test_mode'] = 'Test Mode (EVTE)';
$string['usi_test_mode_desc'] = 'Use the EVTE (External Validation Test Environment) instead of production. Recommended for testing.';
$string['usi_organization_id'] = 'Organisation Code';
$string['usi_organization_id_desc'] = 'Your organisation code registered with the USI Registry';
$string['usi_certificate_path'] = 'Machine Credential Path';
$string['usi_certificate_path_desc'] = 'Server path to your M2M machine credential (.p12 or .pfx file) issued by myGovID';
$string['usi_certificate_password'] = 'Credential Password';
$string['usi_certificate_password_desc'] = 'Password for the machine credential certificate';
$string['usi_debug_mode'] = 'Debug Mode';
$string['usi_debug_mode_desc'] = 'Enable detailed logging for troubleshooting USI verification issues';
$string['task_verify_usi_batch'] = 'Batch verify student USIs';
$string['usi_verification'] = 'USI Verification';
$string['usi_verified'] = 'USI Verified';
$string['usi_unverified'] = 'USI Not Verified';
$string['usi_verification_failed'] = 'USI Verification Failed';
$string['usi_manual_review'] = 'Requires Manual Review';
$string['usi_verify_now'] = 'Verify Now';
$string['usi_manual_verify'] = 'Manual Verify';
$string['usi_verification_status'] = 'Verification Status';
$string['usi_verification_date'] = 'Verification Date';
$string['usi_verification_log'] = 'Verification Log';
$string['usi_service_available'] = 'USI Service Available';
$string['usi_service_unavailable'] = 'USI Service Not Configured';
$string['usi_credential_expiring'] = 'Machine credential expires in {$a} days';
$string['usi_credential_expired'] = 'Machine credential has expired';
$string['usi_test_connection'] = 'Test Connection';
$string['usi_connection_success'] = 'Successfully connected to USI Registry';
$string['usi_connection_failed'] = 'Connection to USI Registry failed';
$string['usi_stats_total'] = 'Total with USI';
$string['usi_stats_verified'] = 'Verified';
$string['usi_stats_unverified'] = 'Unverified';
$string['usi_stats_failed'] = 'Failed';
$string['usi_stats_pending'] = 'Pending Review';

// ============================================================
// COMPREHENSIVE TOOLTIP HELP STRINGS - Training Users
// ============================================================

// DASHBOARD SECTION TOOLTIPS
$string['dashboard_help'] = 'Your central command center for RTO compliance. This dashboard provides real-time visibility into your compliance status across all ASQA requirements. Use it daily to identify and address issues before they become audit findings.';
$string['dashboard_stats_help'] = 'These statistics update in real-time as you manage student records. Green numbers indicate healthy metrics, amber requires attention, and red indicates urgent action needed. Click any statistic to drill down into the underlying records.';
$string['quickactions_help'] = 'Frequently-used compliance actions are available here for quick access. These shortcuts save time when performing routine tasks like verifying USIs, issuing certificates, or checking trainer credentials.';

// STUDENT MANAGEMENT SECTION TOOLTIPS
$string['students_help'] = 'The Student AVETMISS Profiles section is critical for government reporting compliance. Every student enrolled in nationally recognised training MUST have a complete profile with validated data. Incomplete profiles will cause your NAT export to fail and may result in funding claw-backs.';
$string['studentprofile_help'] = 'This form captures all mandatory AVETMISS fields required for NAT file generation. Each field maps directly to NCVER reporting requirements. Fields marked with * are mandatory and must be completed before the student can receive any certificates or qualifications.';

// PERSONAL DETAILS SECTION
$string['personaldetails_help'] = 'Personal identification information required by NCVER for unique student identification across the national VET system. This data links to the NAT00080 (Client) file and must match the student\'s official identification documents exactly.';
$string['dateofbirth_help'] = 'The student\'s date of birth as shown on official ID. This is a mandatory AVETMISS field (NAT00080 Position 21-28). Format: DD/MM/YYYY. Students under 15 or over 100 will trigger a validation warning.';
$string['sex_help'] = 'The student\'s sex as recorded on official documents. This maps to NAT00080 Position 29. Required for all students enrolled in nationally recognised training. Options: Male (M), Female (F), Other (X).';
$string['firstname_help'] = 'Student\'s first name exactly as shown on their USI record. Must match their official identification. This appears on all certificates issued.';
$string['lastname_help'] = 'Student\'s family name/surname exactly as shown on their USI record. Must match their official identification. This appears on all certificates issued.';

// ADDRESS DETAILS SECTION
$string['addressdetails_help'] = 'Residential address is required for NAT00085 (Client Postal Details) and state funding eligibility verification. The postcode determines which state training authority receives funding claims. Accurate addresses ensure students receive Quality Indicator surveys.';
$string['buildingname_help'] = 'Optional: Name of building, complex, or property (e.g., "Sunshine Business Centre"). Leave blank if not applicable. Maps to NAT00085 Position 21-70.';
$string['unitno_help'] = 'Unit, apartment, or flat number within a building (e.g., "Unit 5" or "Apt 12"). Leave blank for houses. Maps to NAT00085.';
$string['streetno_help'] = 'Street number of the student\'s residence (e.g., "42" or "15A"). Required for complete address validation.';
$string['streetname_help'] = 'Full street name including street type (e.g., "Smith Street" or "Victoria Avenue"). This is a mandatory field for all residential addresses.';
$string['residentialsuburb_help'] = 'Suburb or locality name. Must match Australia Post locality database. This determines the student\'s Local Government Area for statistical reporting.';
$string['residentialpostcode_help'] = 'Australian 4-digit postcode. This is CRITICAL for determining: (1) State/Territory jurisdiction, (2) Regional/metropolitan classification, (3) Funding eligibility. Must be a valid postcode from the Australia Post database.';
$string['residentialstate_help'] = 'Australian State or Territory of residence. Must match the postcode. This determines which State Training Authority receives AVETMISS data and which state-specific fields are required.';

// DEMOGRAPHIC DETAILS SECTION
$string['demographicdetails_help'] = 'Demographic information required for NCVER statistical reporting and equity monitoring. This data helps identify training gaps in different population groups and is used to allocate equity funding. All fields are reported in aggregate, never individually.';
$string['countryofbirth_help'] = 'Country where the student was born. Uses SACC (Standard Australian Classification of Countries) codes. This information helps RTOs demonstrate they are providing services to diverse communities. Required for NAT00080.';
$string['languageathome_help'] = 'The main language spoken at home other than English. Uses ASCL (Australian Standard Classification of Languages) codes. If English is the main language at home, select "English". This helps identify students who may need additional language support.';
$string['englishproficiency_help'] = 'Self-assessed English language proficiency for students born overseas or who speak a language other than English at home. Options range from "Very well" to "Not at all". This helps identify potential literacy support needs.';
$string['atsi_help'] = 'Aboriginal and/or Torres Strait Islander status. This is a self-identification question asked of ALL students, not just those who appear to be Indigenous. Required for equity monitoring and Indigenous-specific funding programs. Student can decline to answer.';

// DISABILITY DETAILS SECTION
$string['disabilitydetails_help'] = 'Disability information supports access and equity monitoring and helps RTOs provide appropriate reasonable adjustments. Students are never required to disclose a disability. This information is used only to improve support services and for statistical reporting.';
$string['disability_help'] = 'Does the student identify as having a disability, impairment, or long-term condition? This is voluntary disclosure. A "Yes" response enables the disability type field and triggers your RTO\'s reasonable adjustment procedures.';
$string['disabilitytype_help'] = 'Select all disability types that apply from the standard AVETMISS codes. Multiple selections allowed. This helps your RTO plan appropriate support and reasonable adjustments. Common types include: Hearing/Deaf, Vision, Physical, Intellectual, Learning, Mental illness, Acquired brain impairment.';

// EDUCATION HISTORY SECTION
$string['educationdetails_help'] = 'Prior education information is required for NAT00080 and helps determine appropriate entry pathways, Recognition of Prior Learning (RPL) opportunities, and realistic completion timeframes. This data also feeds into national education statistics.';
$string['schoollevel_help'] = 'Highest school year completed (Year 8 or below, Year 9, Year 10, Year 11, Year 12). This affects literacy and numeracy support planning. Select "Did not go to school" only if the student never attended any formal schooling.';
$string['yearschoolcompleted_help'] = 'The calendar year when the student last attended secondary school. Enter 4-digit year (e.g., 2020). Leave blank if currently attending school. This helps identify mature-age students who may need additional support.';
$string['atschoolflag_help'] = 'Is the student currently enrolled in secondary school? Select "Yes" for VET in Schools (VETiS) or School-Based Apprenticeship students. This affects funding category and delivery mode reporting.';
$string['prioreducation_help'] = 'Highest qualification completed BEFORE starting this training. Options include: Bachelor degree or higher, Advanced Diploma/Diploma, Certificate IV, Certificate III, Certificate II, Certificate I, Other education. Select "None" only if no post-school education completed.';
$string['priorachievement_help'] = 'Has the student successfully completed any AQF qualification or Statement of Attainment prior to this enrolment? This is the NAT00080 "Prior Educational Achievement Flag" and affects outcome validation.';

// EMPLOYMENT SECTION
$string['employmentstatus_help'] = 'Student\'s employment status at the time of enrolment. Options: Full-time, Part-time, Self-employed, Not employed (seeking), Not employed (not seeking). This is mandatory for NAT00080 and affects funding eligibility for some programs.';
$string['labourforcestatus_help'] = 'Labour force status uses AVETMISS Edition 2.3 codes. Options: 01=Full-time employee, 02=Part-time employee, 03=Self-employed (not employing others), 04=Employer, 05=Unemployed (seeking full-time), 06=Unemployed (seeking part-time), 07=Not employed (not seeking), 08=Not stated. This maps directly to NAT00080.';
$string['studyreason_help'] = 'Primary reason for undertaking the training. AVETMISS codes: 01=Job skills/employment, 02=Employment promotion/career change, 03=New business start-up, 04=Self-development, 08=Other, 11=VET requirement for school. This helps demonstrate training meets industry needs.';
$string['prioreducationflag_help'] = 'AVETMISS field indicating whether the student has successfully completed any qualification or Statement of Attainment prior to this program. Y=Yes prior education, N=No prior education, @=Not stated. This affects outcome validation rules.';

// SURVEY CONSENT SECTION
$string['surveydetails_help'] = 'Quality Indicator survey consent is required by ASQA. Students can agree or decline to be contacted for the NCVER Student Outcomes Survey. Refusal does not affect their training or assessment.';
$string['surveyconsent_help'] = 'Does the student agree to be contacted for national surveys about their training experience? AVETMISS codes: A=Agrees to be contacted, E=Valid excuse (deceased, mental incapacity), M=No mail contact details, N=Does not agree. RTOs must collect this at enrolment.';
$string['surveycontactstatus_help'] = 'Survey Contact Status codes: A=Student agrees to be contacted for Quality Indicator and Student Outcomes surveys. E=Valid excuse exists (deceased, institutionalised, mental incapacity). M=No mail contact details available. N=Student does not agree to be contacted.';

// STATE-SPECIFIC FIELDS SECTION
$string['statespecific_help'] = 'Some State Training Authorities require additional data fields beyond national AVETMISS requirements. Complete these fields if you deliver state-funded training or have reporting obligations to specific states. Fields shown depend on your RTO\'s registered state.';
$string['fundingsourcestate_help'] = 'State-specific funding source code required by State Training Authorities. This code identifies the specific funding program or contract under which the training is delivered. Check your state STA guidelines for valid codes.';

// TRAINER MANAGEMENT SECTION TOOLTIPS
$string['trainers_help'] = 'Trainer compliance is your highest audit risk area. Under the 2025 RTO Standards (effective 1 July 2025), Standard 3.2 requires documented evidence that trainers hold appropriate credentials per the Credential Policy, and Standard 3.3 requires vocational competency, industry currency, and ongoing CPD. This section provides a single view of all trainer compliance status.';
$string['trainer_profile_help'] = 'Each trainer must have a complete profile documenting their credentials before they can deliver training or conduct assessments. Under Standard 3.2, RTOs must authenticate credentials and have systems to verify qualifications, monitor performance, and ensure industry currency. Update profiles immediately when new evidence is obtained.';

// TRAINER CREDENTIAL FIELDS
$string['credentialrole_help'] = '2025 Credential Policy role classification: Section 1A/1B - Training and/or assessment WITHOUT direction (TAE40122/40116/40110 or Diploma VET). Section 1C/1D - Training UNDER direction (skill sets, cannot make assessment judgements). Section 2A-2C - TAE delivery (Diploma level). Section 3A/3B - Validation roles. See ASQA Practice Guide: Credential Policy.';
$string['taecredential_help_long'] = 'TAE qualification per the 2025 Credential Policy. Section 1A/1B (without direction): TAE40122, TAE40116, or TAE40110 (now accepted without additional units), Diploma VET or higher. Section 1C/1D (under direction): TAESS00021, TAESS00024, or secondary teaching qualification - but persons CANNOT make assessment judgements. Enter full qualification code.';
$string['taedateachieved_help'] = 'Date the TAE qualification was awarded. Enter the date shown on the testamur or Statement of Attainment. This is used to calculate currency and identify trainers who may need to transition to newer TAE qualifications.';
$string['taeevidence_help'] = 'Upload a scan or photo of the TAE testamur or Statement of Attainment. ASQA auditors will request to see original evidence. Acceptable formats: PDF, JPG, PNG. Maximum file size 5MB.';

$string['vocationalqualifications_help_long'] = 'List ALL vocational qualifications held by this trainer that are relevant to the training products they deliver and assess. Include: (1) Qualification code and title, (2) Date achieved, (3) Issuing RTO. Example: "BSB50420 Diploma of Leadership and Management, 2019, RTO 12345". Trainers must hold qualifications at or above the level being delivered.';
$string['industrycurrency_help_long'] = 'Industry currency demonstrates current knowledge of industry practices. Document: (1) Current or recent work in industry (within 2-3 years), (2) Industry consultation activities, (3) Site visits, (4) Return-to-industry placements, (5) Industry association membership. ASQA requires evidence of currency for ALL trainers.';
$string['industrycurrencydate_help'] = 'Date when industry currency was last verified. This should be reviewed at least annually. Set the next review date to ensure ongoing compliance. Trainers with currency older than 2 years may be flagged for review.';
$string['industrycurrencyevidence_help'] = 'Upload evidence of industry currency: employer letters, industry site visit reports, industry association membership certificates, CPD records related to industry skills. Multiple documents can be uploaded.';

$string['vocationalcompetency_help_long'] = 'Vocational competency is the ability to demonstrate skills and knowledge equivalent to or exceeding the training being delivered. Document: (1) Work experience in the field, (2) Projects completed, (3) Mentoring/supervising others, (4) Industry recognition/awards. This is separate from qualifications - it\'s about practical capability.';
$string['vocationalcompetencydate_help'] = 'Date when vocational competency was last formally verified. This may be through performance review, skills assessment, or competency conversation. Should be reviewed at least annually.';
$string['vocationalcompetencyevidence_help'] = 'Upload evidence of vocational competency: performance reviews, skills assessments, employer references, portfolio of work, peer assessments. This demonstrates current capability to perform at industry standard.';

$string['cpdhours_help_long'] = 'Continuing Professional Development (CPD) hours for the current calendar year. Track ALL activities that maintain or develop training and vocational competence: industry conferences, workshops, webinars, formal study, mentoring, research. ASQA expects trainers to maintain currency through ongoing CPD.';
$string['cpdlog_help'] = 'Record each CPD activity with: Date, Activity description, Hours, Provider/Organisation, Relevance to training delivery. This log provides audit evidence of ongoing professional development and currency maintenance.';

$string['wwccnumber_help_long'] = 'Working With Children Check (WWCC) number issued by the relevant state authority. Name varies by state: Blue Card (QLD), WWCC (NSW, VIC, WA, TAS), Working with Vulnerable People (ACT), DCSI Screening (SA). MANDATORY for all trainers working with students under 18.';
$string['wwccstate_help'] = 'Select the state/territory that issued the WWCC. If your RTO operates across multiple states, trainers may need checks in each state. Some states have mutual recognition arrangements - check with your STA.';
$string['wwccexpiry_help'] = 'WWCC expiry date. Set calendar reminders 90 days before expiry to ensure renewal applications are lodged in time. Expired WWCC means the trainer cannot work with minors until renewed.';
$string['wwccevidence_help'] = 'Upload a copy of the WWCC card or approval letter. Auditors may ask to sight the original card. Keep this current - an expired evidence document with a valid check may cause confusion during audits.';

$string['policechecknumber_help_long'] = 'National Police Check (Criminal History Check) reference number. RTOs should determine their own policy for police checks based on the training delivered. Recommended for all trainers, particularly those delivering aged care, disability, children\'s services, or security training.';
$string['policecheckdate_help'] = 'Date the police check was conducted. RTOs typically require checks to be renewed every 3 years, though some licensing requirements specify shorter periods. Include in your trainer compliance policy.';
$string['policecheckexpiry_help'] = 'Police check expiry date based on your RTO policy. Unlike WWCC, police checks don\'t have an official expiry - the expiry is determined by your RTO\'s policy (typically 3 years from issue).';
$string['policecheckevidence_help'] = 'Upload the National Police Check result letter. This document contains sensitive information - ensure your RTO has appropriate data handling policies for criminal history information.';

$string['scopemapping_help_long'] = 'Map this trainer to the qualifications and units they are approved to deliver and assess. Trainers can only be assigned to courses within their approved scope. This creates your internal scope mapping and is cross-referenced with your RTO\'s ASQA scope of registration.';
$string['scopeunits_help_long'] = 'List specific units of competency this trainer is approved to deliver and assess. If they can deliver all units within a qualification, you can note "All units in [qualification code]". Be specific - this is checked during audits.';
$string['scopenotes_help'] = 'Any additional notes about scope restrictions or conditions. For example: "Can assess only, not train", "Requires supervision for BSBWHS units", "Approved pending completion of TAE50122 by March 2024".';

// COMPLAINTS AND APPEALS SECTION
$string['complaints_help'] = 'Under Standard 5 (Complaints and Appeals), RTOs must have a fair, transparent, and documented complaints and appeals process. This register tracks all complaints from lodgement through investigation to resolution. Complaint data identifies systemic issues and drives continuous improvement. Maintain records of all complaints for a minimum of 5 years.';
$string['complaint_form_help'] = 'Record complaints per Standard 5 requirements. Anonymous complaints are permitted. The complainant should receive acknowledgment within 10 business days (ASQA guideline). Document the investigation process, findings, and resolution. Link systemic issues to continuous improvement actions.';
$string['complainttype_help'] = 'Categorise the complaint: Academic (assessment decisions, training quality), Administrative (enrolment, fees, scheduling), Staff Conduct (trainer behaviour), Facilities (classroom, equipment), Other. Category helps identify trends and systemic issues.';
$string['complaintseverity_help'] = 'Severity level: Low (minor service issue), Medium (impacts training quality), High (potential regulatory breach), Critical (immediate risk to safety or compliance). Severity determines response timeframes and escalation requirements.';
$string['complaintstatus_help'] = 'Current status: Received (new complaint logged), Under Investigation (being reviewed), Resolved (outcome determined), Appealed (complainant has appealed decision), Closed (no further action). Status must be updated within regulatory timeframes.';
$string['complaintoutcome_help'] = 'Record the investigation findings and resolution. Include: what was found, what action was taken, whether the complaint was upheld/not upheld/partially upheld. This becomes part of your continuous improvement evidence.';

// APPEALS SECTION
$string['appeals_help'] = 'Under Standard 5, students have the right to appeal assessment decisions and complaint outcomes. Appeals must be handled by a person/panel not involved in the original decision. If the internal appeal is not resolved to the student\'s satisfaction, they must be advised of external appeal options (e.g., state consumer tribunals, ombudsmen).';
$string['appeal_form_help'] = 'Record appeal details per Standard 5: original decision being appealed, grounds for appeal, and supporting documentation. Appeals should be resolved within stated timeframes (typically 10-20 business days). If the outcome is not in the appellant\'s favour, advise them of external review options.';
$string['appealtype_help'] = 'Type of appeal: Academic (challenging an assessment decision), Complaint (challenging a complaint outcome), Enrolment (challenging an enrolment decision), Fee (challenging a fee or refund decision). Each type may have different review processes.';
$string['appealgrounds_help'] = 'Document the grounds on which the appeal is based: procedural error, new evidence, extenuating circumstances, bias, etc. The appellant must provide grounds - "I disagree" is not sufficient grounds for appeal.';
$string['appealoutcome_help'] = 'Record the appeal panel\'s decision: Upheld (original decision overturned), Not Upheld (original decision stands), Partially Upheld (decision modified). Include reasoning and any actions required. Appellant must be notified in writing.';

// CONTINUOUS IMPROVEMENT SECTION
$string['improvement_help'] = 'Under Standard 7 (Quality Assurance), RTOs must have systematic approaches to continuous improvement. This section records improvement actions arising from complaints, audits, validation, trainer feedback, student feedback, Quality Indicator surveys, and industry consultation. Link actions to their source for audit evidence. Improvement data supports the Annual Declaration of Compliance.';
$string['improvement_form_help'] = 'Document improvement actions per Standard 7: what triggered the improvement (source), what changes were made, who is responsible, implementation timeline, and how effectiveness will be measured. Track all improvements to completion and verify effectiveness. This provides evidence of systematic quality assurance.';
$string['improvementsource_help'] = 'Source of the improvement action: Student Complaint, Trainer Feedback, Industry Consultation, Internal Audit, External Audit, QI Survey, RPL Review, Validation Activity, Stakeholder Meeting, Other. Linking to source demonstrates evidence-based improvement.';
$string['improvementstatus_help'] = 'Status of the improvement action: Identified (recognised need for change), In Progress (implementation underway), Implemented (change made), Verified (effectiveness confirmed), Closed (no further action). Track all improvements to completion.';

// GOVERNANCE SECTION
$string['governance_help'] = 'Under the 2025 RTO Standards, Standard 4 (Governance) requires RTOs to have appropriate governance arrangements. All governing persons (directors, trustees, high managerial agents) must be "fit and proper persons". ASQA assesses governance arrangements including management structures, decision-making processes, and compliance oversight. See ASQA Practice Guide: Fit and proper person requirements.';
$string['governance_form_help'] = 'Record details of each governing person including their role, fit and proper person declaration, police check status, and material changes. Under Standard 4, governing persons must not have been: convicted of relevant offences, undischarged bankrupt, had RTO registration cancelled, or been subject to regulatory action. Changes must be notified to ASQA.';
$string['governingrole_help'] = 'The person\'s role in RTO governance per Standard 4: Director, Company Secretary, Trustee, Partner, CEO/General Manager, Public Officer, High Managerial Agent. Role determines ASQA notification requirements and fit-and-proper assessment obligations. All persons exercising significant influence over RTO operations are covered.';
$string['fitandproperdeclaration_help'] = 'Has this person signed a fit and proper person declaration per Standard 4? This declaration confirms they: (1) Have not been convicted of relevant offences, (2) Are not an undischarged bankrupt, (3) Have not had an RTO registration cancelled, (4) Are not subject to regulatory action. MANDATORY for all governing persons before they commence in the role.';
$string['materialchanges_help'] = 'Record material changes requiring ASQA notification: change in control (ownership), change in governing persons, change in financial viability, significant adverse events, change in delivery locations or scope. Notification timeframes vary by change type. ASQA may impose conditions or take action for undisclosed material changes.';

// THIRD PARTY ARRANGEMENTS SECTION
$string['thirdparty_help'] = 'Under the 2025 RTO Standards, Standard 2 (Third-Party Arrangements) requires written agreements with third parties who deliver or assess training on your behalf. This includes partner RTOs, industry trainers, and organisations providing services under your RTO code. ASQA must be notified within 30 calendar days of entering any third-party arrangement for training delivery or assessment.';
$string['thirdparty_form_help'] = 'Document each third-party arrangement including scope of services, quality assurance measures, and review dates. Agreements must specify: (1) NRT logo prohibition for third party, (2) Third party cannot issue AQF credentials, (3) Students must be informed of RTO responsibility. You retain full compliance responsibility.';
$string['thirdpartytype_help'] = 'Type of third party: Delivery Partner (delivers training), Assessment Partner (conducts assessment), Recruitment Agent (recruits students), Support Service (provides student support), Venue Provider (provides facilities). Type determines mandatory contract clauses and monitoring requirements.';
$string['thirdpartyscope_help'] = 'Define the scope of services: which qualifications/units, which locations, which cohorts. The written agreement must clearly specify what the third party is authorised to do. Third parties delivering training must have trainer credentials verified against the Credential Policy.';
$string['thirdpartymonitoring_help'] = 'Document how you monitor third-party performance under Standard 2: site visits, observation of training/assessment, document review, student feedback, complaints monitoring. ASQA expects active, ongoing monitoring - not just annual contract review. Risk rating determines monitoring frequency.';
$string['thirdpartyasqanotified_help'] = 'CRITICAL: ASQA notification is required within 30 CALENDAR DAYS of entering any third-party arrangement involving delivery or assessment of training. Record the notification date and ASQA reference number. Failure to notify within 30 days is a compliance breach.';

// INSURANCE SECTION
$string['insurance_help'] = 'RTOs must maintain appropriate insurance coverage. While ASQA doesn\'t mandate specific insurance types, most RTOs require: Public Liability, Professional Indemnity, and Workers Compensation. Some state funding contracts and licensing bodies require specific coverage levels.';
$string['insurance_form_help'] = 'Record each insurance policy including the coverage type, insurer, policy number, coverage amount, and renewal dates. Set up alerts for policies approaching expiry to maintain continuous coverage.';
$string['insurancetype_help'] = 'Type of insurance: Public Liability (injury on premises), Professional Indemnity (advice/training errors), Workers Compensation (staff injury), Building/Contents, Cyber Liability, Directors & Officers. Different delivery contexts require different coverage.';
$string['insurancecoverage_help'] = 'Coverage amount in dollars. Industry standard for RTO public liability is typically $10-20 million. Check your state STA requirements and any industry licensing requirements for minimum coverage levels.';
$string['insuranceexpiry_help'] = 'Policy expiry date. Set reminder 60 days before expiry to allow time for renewal quotes. Lapsed insurance creates significant risk and may breach funding contracts or licensing requirements.';

// FEE PROTECTION SECTION
$string['feeprotection_help'] = 'Under Standard 6 (Financial Management), RTOs collecting fees in advance exceeding $1,500 (the regulatory threshold) must have fee protection arrangements in place BEFORE collecting fees. This protects students if your RTO ceases operations. Options include: bank guarantee, protected account, or tuition assurance scheme (TAS). Track all prepaid fees to ensure compliance.';
$string['feeprotection_form_help'] = 'Record fee protection arrangements including type of protection, account/guarantee details, and students covered. The $1,500 THRESHOLD applies to total prepaid fees per student - not per payment. RTOs must demonstrate all students paying over $1,500 in advance are protected BEFORE collecting those fees.';
$string['feeprotectiontype_help'] = 'Type of fee protection per Standard 6: Protected Account (fees held in trust pending completion), Bank Guarantee (bank guarantees refund), Tuition Assurance Scheme (TAS membership). Protection must be in place BEFORE collecting fees over $1,500 threshold.';
$string['feeprotectionamount_help'] = 'Total prepaid fees for this student requiring protection. CRITICAL: If this amount exceeds $1,500, fee protection MUST be in place. Track running totals as payments are received. This is the amount requiring refund or coverage if RTO ceases operations.';

// VALIDATION SECTION
$string['validation_help'] = 'Under Standard 1.5 (Validation), validation ensures assessment tools and practices produce valid, reliable, fair, and flexible outcomes. The 2025 Standards require systematic validation with appropriately credentialed validators. For TAE qualifications: validators must collectively meet Credential Policy Section 1A or 1B. For other products: validators must collectively have industry expertise and relevant knowledge. Pre-use review of tools does NOT require formal validator credentials.';
$string['validation_form_help'] = 'Record each validation event: units validated, participants, samples reviewed, and improvements identified. Under the 2025 Credential Policy Section 3: validators for TAE products must meet Section 1A/1B requirements. For other training products, validators must collectively have industry expertise and knowledge relevant to the qualification. Include industry representatives where possible.';
$string['validationtype_help'] = 'Type of validation per Standard 1.5: Pre-implementation (new assessment tool - note: pre-use review does not require formal validator credentials), Scheduled (part of systematic cycle), Triggered (following complaint or adverse finding), Industry (with industry representatives), Peer (with other RTOs). All types must apply the 5 rules of evidence.';
$string['validationfindings_help'] = 'Document findings from validation: what worked well, improvements needed, issues with assessment tools, judgement consistency, industry relevance. Findings must link to continuous improvement actions. Validation evidence supports Annual Declaration of Compliance.';
$string['validatorcredentials_help'] = 'Record validator credentials per 2025 Credential Policy Section 3: For TAE qualifications/skill sets: validators must collectively meet Section 1A or 1B requirements. For other training products: validators must collectively have industry expertise and knowledge relevant to the qualification being validated. Document TAE credential, vocational competency, and industry currency for each validator.';

// TRAINING PRODUCT TRANSITIONS SECTION
$string['transitions_help'] = 'Under Standard 1.7 (Training Product Transitions), RTOs must transition students to current products when training packages are superseded or deleted. The teach-out period is typically 12-24 months from supersession date (check TGA for specific product deadlines). Track transition plans, student notifications, and teach-out arrangements. Failure to transition students on time is a common compliance issue.';
$string['transition_form_help'] = 'Create a transition plan including: (1) Superseding product details, (2) Student impact assessment, (3) Transition timeline aligned with TGA deadlines, (4) Communication plan. Students enrolled before supersession have rights to complete under the old product within teach-out period. No new enrolments permitted after a product is superseded.';
$string['transitionstatus_help'] = 'Transition status per Standard 1.7: Planned (transition approach determined), In Progress (students being transitioned or teaching out), Completed (all students on new product or completed under old product), Teach-Out Active (students completing old product by TGA deadline). Track all affected students to ensure none miss deadlines.';
$string['teachoutdate_help'] = 'Teach-out end date from training.gov.au - the FINAL date students can be issued credentials under the superseded product. Typically 12-24 months from supersession (product-specific). CRITICAL: No new enrolments after supersession. No certificate issuance after teach-out deadline. Monitor TGA for exact dates.';

// TAS (Training and Assessment Strategy) SECTION
$string['tas_help'] = 'The Training and Assessment Strategy (TAS) documents your approach to delivering each qualification per Standard 1.1 (Training and Assessment Strategies). ASQA requires a current TAS for every qualification on scope. The TAS must address: training package requirements, volume of learning, delivery modes, assessment strategy, trainer requirements (per 2025 Credential Policy), and resources. Industry consultation must inform the TAS.';
$string['tas_form_help'] = 'Complete all 9 sections as required by ASQA. The TAS addresses Standards 1.1-1.9. Review annually or when training package requirements change. Ensure volume of learning aligns with AQF guidelines. Industry consultation must be documented and inform delivery approach. The TAS is a living document - update when practices change.';
$string['tasqualification_help'] = 'Select the qualification this TAS covers. Each qualification requires its own TAS per Standard 1.1. The TAS must reflect specific training package rules, packaging requirements, and any licensing or regulatory requirements for the qualification.';
$string['tasvolume_help'] = 'Volume of learning per AQF guidelines indicates notional duration (in hours) including all supervised training, self-directed learning, and assessment time. Standard 1.1 requires volume to be appropriate for the qualification level. AQF indicative volumes: Cert I (600-1200hrs), Cert II (600-1200hrs), Cert III (1200-2400hrs), Cert IV (600-2400hrs), Diploma (1200-2400hrs). Justify any significant deviation.';
$string['tasdeliverymode_help'] = 'Describe delivery methods per Standard 1.2: face-to-face classroom, online/distance, workplace-based, blended, simulation, etc. Include learning environment details, student:trainer ratios, prerequisites, and how delivery addresses the training package assessment conditions. Online delivery must meet the same quality standards as face-to-face.';
$string['tasassessmentstrategy_help'] = 'Describe your assessment approach per Standards 1.3-1.4: timing of assessment, assessment methods used, RPL provisions, reasonable adjustment, reassessment policy, and competency-based progression. Assessment must meet training package requirements and apply the principles of assessment (validity, reliability, flexibility, fairness).';
$string['tastrainerrequirements_help'] = 'Document trainer/assessor requirements per Standards 3.2-3.3 and the 2025 Credential Policy: TAE credential (Section 1A/1B for unsupervised, 1C/1D for under direction), vocational qualifications at/above the level being delivered, industry currency, ongoing CPD, and any specific licensing requirements.';
$string['tasresources_help'] = 'List physical and digital resources per Standard 1.6: facilities (classrooms, workshops), equipment (tools, machinery, software), learning materials, PPE, and consumables. Resources MUST match the assessment conditions specified in the training package. Include any industry-specific requirements.';
$string['tasindustryconsultation_help'] = 'Document industry consultation per Standard 1.1: who you consulted (industry representatives, employers, industry bodies), when, what input they provided, and how it shaped delivery approach. Industry consultation must be ONGOING - not a one-time exercise. Use consultation to ensure training remains industry-relevant.';

// TAS FORM FIELD STRINGS — with ASQA practice guide compliance notes
// ─────────────────────────────────────────────────────────────────────────────
// Each _help string includes: what ASQA expects, relevant Standard clause,
// what auditors look for, and best practice guidance from the ASQA TAS
// Practice Guide (2024 edition) and Standards for RTOs 2015/2025.
// ─────────────────────────────────────────────────────────────────────────────

$string['scopedetails'] = 'RTO Scope Details';
$string['scopedetails_help'] = '**ASQA Standard 5.1 — Pre-Enrolment Information**

This field confirms the RTO\'s registration scope. Include:
- The qualification\'s national code and title (e.g. BSB30120 Certificate III in Business)
- The training package it belongs to (e.g. BSB Business Services Training Package)
- Your ASQA registration number and the qualification\'s listing on training.gov.au
- The credentials issued (Testamur, Statement of Attainment, or Record of Results)

**What auditors check:** That the qualification is currently on your ASQA scope of registration and that the credential issued matches the qualification on scope.

**Best practice:** Include a direct link to your qualification on training.gov.au.';

$string['targetcohort'] = 'Target Learner Cohort';
$string['targetcohort_help'] = '**ASQA Standard 1.1 — Training and Assessment Strategies**

The cohort description is one of the most scrutinised TAS sections. ASQA expects you to describe:
- Who your typical learners are (age range, employment background, cultural diversity)
- Their prior qualifications and work experience relevant to this qualification
- Language, Literacy and Numeracy (LLN) levels using the Australian Core Skills Framework (ACSF): Cert III/IV = ACSF Level 2–3; Diploma = Level 3–4
- Access and equity considerations (learners with disability, CALD backgrounds, First Nations learners)
- Any specific support needs that influence your delivery approach

**What auditors check:** That your delivery strategies actually respond to the cohort described — generic cohort descriptions that don\'t connect to your training and assessment approach are a common finding.

**Best practice (ASQA TAS Practice Guide 2024):** Be specific about YOUR learners, not a theoretical cohort. Use data from enrolment forms, LLN assessments, and industry consultation to describe real learner characteristics.';

$string['entryrequirements'] = 'Entry Requirements';
$string['entryrequirements_help'] = '**ASQA Outcome Standard 2.2 — Pre-Enrolment Information**

Entry requirements must be transparent, reasonable, and not create unreasonable barriers to access. Document:
- Minimum age requirement (if applicable — must be legally justified)
- Prior qualifications or experience required and why
- Minimum LLN skill level (reference ACSF level)
- Technology access requirements
- Physical or occupational licensing requirements (if applicable)

**What auditors check:** That entry requirements are consistently applied, clearly communicated to prospective learners BEFORE enrolment, and are genuinely necessary (not exclusionary).

**Best practice:** Avoid requirements like "must have Year 12" unless the qualification genuinely demands that level of foundational knowledge. ASQA expects RTOs to support learners rather than screen them out. Always state WHERE these requirements are communicated (website, prospectus, pre-enrolment checklist).';

$string['llnrequirements'] = 'LLN Requirements';
$string['llnrequirements_help'] = '**ASQA Standards 1.3 and 1.7 — Training/Assessment Resources & Learner Support**

The LLN requirements section must document:
- The LLN demands of the qualification using the **Australian Core Skills Framework (ACSF)**: Reading, Writing, Oral Communication, Numeracy, Learning
- Typical ACSF levels: Cert I/II = Level 1–2 | Cert III/IV = Level 2–3 | Diploma = Level 3–4
- The LLN pre-assessment tool used (e.g. BKSB, Foundation Skills Assessment Tool, TAFE LLN assessment)
- Minimum LLN levels required for learner success
- LLN support services available to learners who need assistance

**What auditors check:** That you have actually assessed LLN demands, not just stated generic requirements. Auditors look for your actual LLN assessment tool and evidence that learners with LLN needs receive support.

**Best practice (ASQA TAS Practice Guide 2024):** LLN assessment should occur at or before enrolment — not after. Document the assessment instrument and how results are used to inform individual learning plans and support arrangements.';

$string['prerequisites'] = 'Prerequisites';
$string['prerequisites_help'] = '**ASQA Outcome Standard 2.2 — Pre-Enrolment Information**

Prerequisites are formal entry requirements that must be met before enrolment. Document:
- Specific units of competency that must be completed first (with reason)
- Formal qualifications required (with AQF level justification)
- Documented workplace experience (e.g. 12 months in relevant industry)
- Any licensing or registration requirements (e.g. First Aid certificate, Working With Children Check)

**What auditors check:** That prerequisites are communicated to learners before enrolment and are genuinely necessary for the qualification or workplace safety — not used as administrative gatekeeping.

**Best practice:** If there are no formal prerequisites, state "No formal prerequisites. Learners must meet entry requirements documented above." This shows you have actively considered the issue rather than left the field blank.';

$string['jobroles'] = 'Job Roles & Outcomes';
$string['jobroles_help'] = '**ASQA Standard 1.1 — Training and Assessment Strategies**

This section demonstrates the vocational relevance of the qualification. Document:
- Specific job titles and roles graduates can pursue (e.g. Customer Service Officer, Team Leader)
- Industry sectors where graduates are likely to be employed
- AQF-level appropriate roles (Cert III = operative/technician; Diploma = supervisory/specialist)
- Progression pathways to higher qualifications or senior roles
- Evidence from industry consultation confirming labour market demand

**What auditors check:** That job roles align with the qualification\'s vocational outcomes and AQF level, and that they reflect current industry demand (not outdated roles). Industry consultation evidence supporting the roles listed is expected.

**Best practice (ASQA TAS Practice Guide 2024):** Cross-reference job roles with current job advertisements, industry workforce data (e.g. NCVER, Jobs and Skills Australia), and your own industry consultation records.';

$string['deliveryschedule'] = 'Delivery Schedule';
$string['deliveryschedule_help'] = '**ASQA Standard 1.1 — Training and Assessment Strategies**

The delivery schedule shows HOW units are structured and sequenced. Include:
- Unit sequencing rationale (why units are delivered in this order)
- Clustering arrangements (which units are taught together and why)
- Approximate hours per unit/week
- Scheduled assessment periods
- Work placement or industry project periods (if applicable)

**What auditors check:** That the schedule allows sufficient time for competency development, that foundational units precede complex ones, and that the schedule is realistic given the volume of learning.

**Best practice:** Show the "why" behind your sequencing — e.g. "BSBTEC301 is delivered first because learners need digital skills to access all other learning resources." ASQA looks for evidence of deliberate, learner-centred design, not just a list of units in training package order.';

$string['learningbreakdown'] = 'Volume of Learning Breakdown';
$string['learningbreakdown_help'] = '**ASQA Standard 1.1 and AQF Volume of Learning Guidelines**

Break down the total volume of learning into its components. AQF expectations:
| Level | Volume of Learning |
|-------|-------------------|
| Cert I | 600 hrs |
| Cert II | 600–1,200 hrs |
| Cert III | 1,200–2,400 hrs |
| Cert IV | 600–2,400 hrs |
| Diploma | 1,200–2,400 hrs |

Components to document:
- **Structured training** (face-to-face, synchronous online, facilitated)
- **Workplace-based learning** (placement, supervised on-the-job)
- **Self-directed study** (reading, asynchronous online, research)
- **Assessment activities** (completing assessment tools, gathering evidence)

**What auditors check:** That totals align with AQF expectations and that each component is realistic and achievable given the delivery schedule. Under-delivery is a common finding.';

$string['volumeoflearning'] = 'Volume of Learning (Total Hours)';
$string['volumeoflearning_help'] = '**ASQA Standard 1.1 — Volume of Learning**

Enter the total volume of learning in hours for this qualification. This is the total time a learner is expected to spend engaging with the training and assessment, including:
- Structured training (face-to-face, online, facilitated sessions)
- Workplace-based learning and placement hours
- Self-directed study, research, and reading
- Assessment activities

**AQF Volume of Learning Guidelines (indicative):**
| Level | Volume of Learning |
|-------|-------------------|
| Certificate I | 600 hrs |
| Certificate II | 600–1,200 hrs |
| Certificate III | 1,200–2,400 hrs |
| Certificate IV | 600–2,400 hrs |
| Diploma | 1,200–2,400 hrs |

**What auditors check:** That the total hours are realistic, align with AQF expectations for the qualification level, and are supported by the delivery schedule.';

$string['volumejustification'] = 'TAS Volume of Learning Justification';
$string['volumejustification_help'] = '**ASQA Standard 1.1 — Critical compliance section**

This justification explains WHY your planned volume is sufficient. Include:
- How your total volume aligns with AQF guidelines for this qualification level
- Why each component (supervised, self-directed, workplace, assessment) is set at the hours documented
- Any factors that affect volume (experienced cohort, workplace delivery, RPL available)
- Reference to TGA nominal hours and how they inform your approach

**What auditors check:** Under-delivery relative to AQF volume expectations is one of the most common ASQA audit findings. A strong justification demonstrates you have deliberately planned the volume, not just filled in a number.

**Best practice (ASQA TAS Practice Guide 2024):** Quote the AQF volume of learning range for the qualification level and explain how your total volume sits within or above this range. If below the AQF minimum, you must justify strongly (e.g. highly experienced cohort with direct workplace access).';

$string['assessmentmethods'] = 'Assessment Methods';
$string['assessmentmethods_help'] = '**ASQA Standard 1.8 — Assessment Requirements**

Assessment must be **valid, reliable, flexible and fair**. Document the methods used:

**Direct evidence methods:**
- Direct observation (trainer observes performance in real or simulated workplace)
- Third-party evidence (workplace supervisor report confirming performance)

**Indirect evidence methods:**
- Portfolio of workplace evidence (samples, photos, records, work outputs)
- Written knowledge questions (short answer, extended response)
- Projects and case studies (applied tasks demonstrating competency)
- Role plays and simulations (where direct workplace access is limited)

**What auditors check:** That the combination of methods gathers sufficient evidence across ALL performance criteria, performance evidence, knowledge evidence, and assessment conditions for every unit. Single-method assessment (e.g. only written questions) rarely satisfies all unit requirements.

**Best practice (ASQA TAS Practice Guide 2024):** For each major unit, describe what method(s) are used and what competency element they address. Show that your methods mirror real workplace conditions and reflect the specific assessment conditions in the training package.';

$string['assessmentmapping'] = 'Assessment Mapping to Units';
$string['assessmentmapping_help'] = '**ASQA Standard 1.8 — Assessment Tool Mapping**

All assessment tools must be mapped to unit requirements. The mapping must address:
- **Performance Criteria** — every criterion for every element
- **Performance Evidence** — all specified skills demonstrated in context
- **Knowledge Evidence** — all specified underpinning knowledge
- **Assessment Conditions** — equipment, environment, and supervision requirements

**What auditors check:** ASQA auditors commonly request and review assessment mapping documents. Gaps in mapping (performance criteria not addressed) are a significant finding that can result in conditions on registration.

**Document here:** How your mapping is maintained (separate mapping document, tool-by-tool tables), the format of the mapping (e.g. cross-reference grid), and how the mapping is reviewed and updated when units are revised.

**Best practice:** Include mapping as a separate attachment to each assessment tool. Update mapping whenever the training package is revised and document the date of the mapping review.';

$string['validationschedule'] = 'Assessment Validation Schedule';
$string['validationschedule_help'] = '**ASQA Standard 1.9 — Assessment Validation**

Validation of assessment tools is mandatory at a minimum once every 5 years per unit. Requirements:
- All units must be validated within the 5-year cycle
- At least one validator must be an **industry representative** (not from your RTO)
- Validation must review the tool\'s validity, reliability, flexibility, and fairness
- Findings must be documented and actioned

**What auditors check:** A documented validation schedule with dates, validators (including industry), findings, and improvement actions. Evidence that validation was actually conducted, not just planned.

**Document here:** The schedule of upcoming validation reviews (which units, when, who will validate). Include the validation method to be used (e.g. benchmarking against similar tools, moderation of marked samples, panel review).

**Best practice (ASQA TAS Practice Guide 2024):** Prioritise validation of newly created tools and units with high non-completion rates. Involve employer and industry body representatives — student feedback alone is insufficient for validation purposes.';

$string['trainerrequirements'] = 'Trainer/Assessor Requirements';
$string['trainerrequirements_help'] = '**Standard 3.3(2) of the Standards for RTOs 2025**

Trainer/assessors must meet ALL of the following requirements:

**TAE Credential (2015 Standards):**
- TAE40116 or TAE40122 Certificate IV in Training and Assessment, OR
- Bridge: TAE40116 with TAELLN411 + TAEASS502, OR
- Diploma or higher (AQF Level 5+) in adult education

**Vocational Competency:**
- Hold vocational qualifications at or above the AQF level being delivered
- Have current industry experience (typically within last 2–3 years)
- Demonstrate competency in units being delivered/assessed

**Currency requirements:**
- Maintain vocational currency through ongoing industry engagement
- Complete annual CPD requirements
- Document currency evidence in trainer file

**What auditors check:** That EVERY trainer delivering/assessing this qualification holds the required TAE credential, has relevant vocational qualifications at the appropriate AQF level, AND can demonstrate current vocational competency. Currency of qualifications is the most common trainer compliance finding.';

$string['supervisionarrangements'] = 'Supervision Arrangements';
$string['supervisionarrangements_help'] = '**ASQA Standard 2.3 — Third-Party Arrangements and Supervision**

Document supervision arrangements for:
- **Workplace-based delivery:** Minimum supervisor qualifications, learner-to-supervisor ratios, RTO monitoring visits
- **Contracted or third-party trainers:** Oversight arrangements, quality monitoring, reporting requirements
- **Online or distance delivery:** How learners are supported and monitored remotely
- **Practical/clinical placement:** Host site briefing process, supervisor requirements, incident management

**What auditors check:** That you have documented, systematic processes for monitoring delivery quality regardless of who delivers it. The RTO is legally responsible for quality even when third parties deliver the training.

**Best practice:** Document the specific monitoring activities (e.g. "Trainer to conduct minimum one site visit per learner during placement"), their frequency, who is responsible, and how concerns are escalated and resolved.';

$string['learningresources'] = 'Learning Resources & Materials';
$string['learningresources_help'] = '**ASQA Standard 1.8 — Training and Assessment Resources**

Learning resources must be:
- **Current** — reflecting the current training package version and current industry practice
- **Accessible** — appropriate to the delivery mode and learner cohort (including CALD learners and those with disability)
- **Sufficient** — covering all unit requirements across the qualification
- **Contextualised** — relevant to the specific industry and workplace context

Document:
- LMS name and access arrangements (if online delivery)
- Textbooks or e-resources (title, edition, publisher, year)
- Industry reference materials and codes of practice
- Handouts, workbooks, and assessment resources
- How resources are reviewed and updated

**What auditors check:** That resources are current (not outdated versions of superseded training packages or old legislation), accessible to all learners including those with disability, and that you have evidence they are regularly reviewed.

**Best practice (ASQA TAS Practice Guide 2024):** Include a resource review schedule and assign responsibility for keeping resources current when training packages are updated.';

$string['facilities'] = 'Facilities & Equipment';
$string['facilities_help'] = '**ASQA Standard 1.8 — Physical Resources**

Facilities and equipment must:
- Meet the **assessment conditions** specified in training package units (this is non-negotiable)
- Be adequate, safe, well-maintained, and accessible
- Simulate real industry conditions where required by the unit

For each major unit or cluster, document:
- The facility type required (classroom, workshop, kitchen, office environment, clinical room, etc.)
- Specific equipment required (tools, machinery, software, PPE)
- Whether simulated or real workplace environments are used
- Accessibility features for learners with disability

**What auditors check:** ASQA auditors visit training facilities and verify they match what is described in the TAS. Discrepancies between stated facilities and actual facilities are a significant finding.

**Best practice:** Review the "Assessment Conditions" section of EVERY unit in the qualification. Document that your facilities meet every stated condition. If you use simulated environments, document how they simulate real workplace conditions.';

$string['technology'] = 'Technology Requirements';
$string['technology_help'] = '**ASQA Standard 1.8 — Technology and Digital Resources**

Technology requirements must be communicated to learners BEFORE enrolment (Standard 5.2). Document:

**Learner requirements:**
- Minimum hardware specifications (device type, processor, RAM)
- Software requirements (Office suite, specialist industry software, video conferencing)
- Minimum internet speed and connection type
- LMS access (link, system requirements, browser compatibility)

**RTO provisions:**
- Technology provided at training facilities
- Technical support available to learners (who, how to contact, hours)
- Accessibility alternatives for learners without required technology

**What auditors check:** That learners are informed about technology requirements before committing to the course, and that support is available for learners who face technology barriers.

**Best practice:** Include technology requirements on your website\'s course information page and in the pre-enrolment information provided to all prospective learners.';

$string['thirdparty'] = 'Third-Party Arrangements';
$string['thirdparty_help'] = '**ASQA Standard 2.3 — Third-Party Arrangements**

If ANY part of training or assessment is delivered by a third party (another organisation, host employer, or contracted trainer), you must document:

- The third party\'s name and the scope of their involvement
- Whether a written agreement exists (mandatory — must specify quality requirements)
- How you monitor the quality of third-party delivery
- How third-party trainers are checked against Standard 1.13 requirements
- How learner feedback on third-party delivery is collected

**What auditors check:** Written third-party agreements, evidence of quality monitoring (observation records, feedback, reporting), and that the RTO acts on quality concerns. Lack of agreements or monitoring evidence is a common compliance finding.

**Important:** Even if the third party is a reputable organisation, YOUR RTO remains legally responsible for the quality of all training and assessment under its scope. "We trusted them" is not an acceptable response to a non-compliance finding.

**If no third parties are used:** State explicitly "All training and assessment is delivered directly by [RTO name] employed trainers. No third-party arrangements are in place."';

$string['learnersupport'] = 'Learner Support Services';
$string['learnersupport_help'] = '**ASQA Standard 1.7 (2015) and Standard 2.3 (2025) — Learner Support**

RTOs must identify and respond to individual learner support needs. Document:

**Support identification:**
- How support needs are identified at enrolment (pre-enrolment questionnaire, LLN assessment, interview)
- How ongoing support needs are identified during training

**Support services:**
- LLN support (what programs, who provides, how to access)
- Learning assistance (tutoring, mentoring, study skills)
- Disability and learning support (who coordinates, process for adjustments)
- Cultural and linguistic support (translation, cultural liaison, community connections)
- Wellbeing and counselling referrals (internal or external)
- Technology support (IT helpdesk, device lending)

**What auditors check:** That support services genuinely exist and are accessible — not just listed in a policy. Auditors interview learners about whether they are aware of and can access support services.

**Best practice (ASQA TAS Practice Guide 2024):** Include specific contact details, access processes, and response timeframes for each support service. Learners should be able to clearly explain how to access support when asked by an auditor.';

$string['accessibility'] = 'Accessibility & Reasonable Adjustments';
$string['accessibility_help'] = '**ASQA Standard 1.7 and Disability Discrimination Act 1992**

RTOs have a legal obligation to provide reasonable adjustments to training and assessment for learners with disability. The key principle:

**Adjustments change HOW evidence is gathered — NOT what must be demonstrated.**

Document:
- The process for learners to request an adjustment (when, to whom, how)
- How adjustment requests are assessed (who makes decisions, what criteria)
- Examples of typical adjustments provided (extended time, oral instead of written assessment, screen readers, alternative formats, rest breaks, support person)
- How adjustments are documented in the learner\'s file
- How assessor competency is maintained even with adjustments
- The escalation process for disputed adjustment decisions

**What auditors check:** That you have a genuine, documented process — not just a policy statement. Auditors look for evidence of adjustments actually being applied and documented in learner files.

**Best practice:** Train all trainers and assessors on reasonable adjustment principles. Document each adjustment decision and rationale in the learner\'s assessment folder.';

$string['marketinginfo'] = 'Pre-Enrolment Information';
$string['marketinginfo_help'] = '**ASQA Standard 5.2 — Accurate and Current Information**

Pre-enrolment information must be accurate, complete, and provided BEFORE the learner commits to enrolment. ASQA specifically checks this section. Document what information is provided:

**Mandatory information (Standard 5.2):**
- Full qualification name, code, and AQF level
- Course duration and delivery schedule
- Delivery mode (face-to-face, online, blended, workplace)
- Fees, payment schedule, and refund policy
- Entry requirements and prerequisites
- Expected learning outcomes and qualification issued
- RTO contact details and complaints process

**Additional best practice information:**
- Technology requirements
- Materials or equipment learners must source
- Estimated additional costs
- Pathways from the qualification

**What auditors check:** That information is accurate (matches the TAS), current (not from a superseded version), and is available to learners BEFORE they sign an enrolment form or pay fees.

**Best practice (ASQA TAS Practice Guide 2024):** Conduct annual reviews of all marketing materials against the current TAS to identify and correct any discrepancies before ASQA finds them.';

$string['feesinformation'] = 'Fees & Payment Information';
$string['feesinformation_help'] = '**ASQA Standard 5.3 — Fees, Charges and Refunds + Tuition Protection Service (TPS)**

Fee information must be transparent and disclosed before enrolment. Document:

**Fee structure:**
- Total course fee (GST status — VET courses are generally GST-free)
- Itemised additional fees (materials, equipment, licensing, RPL assessment)
- Payment options (upfront, instalment, payment plan terms)
- Funding arrangements (government-subsidised, VET FEE-HELP, employer-funded)

**Refund policy:**
- Refund amounts at each withdrawal stage (before start, within first week, after Week 1, etc.)
- Circumstances where full refund applies (RTO cancels, significant change to course)
- Process and timeline for refund payment

**Tuition Protection:**
- If you collect pre-paid fees over $1,500, document your TPS obligations or fee protection arrangements

**What auditors check:** That fees are clearly disclosed, the refund policy is fair and accessible, and that pre-paid fee obligations are met.';

$string['placementdetails'] = 'Placement Details & Supervision';
$string['placementdetails_help'] = '**ASQA Outcome Standards 1.1(2e); 1.2; 2.1(2c(iv)) — Work Placement Requirements**

If this qualification includes mandatory work placement, document:

**Placement structure:**
- Total hours required (and how calculated)
- Breakdown of hours per unit if multiple placement units
- Whether placement can be conducted in a current workplace

**Host employer requirements:**
- Written agreement/MOU requirements
- Host employer eligibility criteria
- Process for approving placement sites

**Workplace supervisor requirements:**
- Qualifications or experience required of workplace supervisors
- Briefing process for workplace supervisors
- How supervisors complete third-party reports

**RTO monitoring:**
- Frequency and method of monitoring visits or contact during placement
- Process for managing unsafe or unsuitable placements
- Documentation requirements (logbooks, supervisor reports)

**What auditors check:** That placement agreements exist, supervisors are briefed, and the RTO actively monitors learner welfare and experience quality during placement. Lack of monitoring evidence is a common finding.';

$string['transitionplan'] = 'Transition/Teach-Out Procedures';
$string['transitionplan_help'] = '**ASQA Standard 1.26 (2015) — Teach-Out and Transition Obligations**

When a training product is superseded, RTOs must have a documented teach-out and transition plan. Document:

- The supersession date of the old qualification
- The teach-out end date (typically 2 years after supersession)
- The equivalent current qualification code and title
- How enrolled learners are notified of their options:
  a) Complete under the superseded qualification (within teach-out period)
  b) Transfer to the current equivalent (with credit transfer)
  c) Withdraw with refund
- Credit transfer arrangements between old and new qualifications
- Process for learners who cannot complete within the teach-out period

**What auditors check:** That enrolled learners are not disadvantaged by the qualification transition, that options are communicated clearly and promptly, and that transition records are maintained.

**If qualification is current (not superseded):** State the current supersession status from training.gov.au and the anticipated end date of the current qualification version.';

$string['riskmanagement'] = 'Training Delivery Risks & Mitigation';
$string['riskmanagement_help'] = '**ASQA Standard 8 — Governance and Risk Management**

A systematic risk management approach is required. For training delivery risks, document using this structure:

**Risk register format:**
- Risk description (what could go wrong)
- Likelihood (Low/Medium/High)
- Impact (Low/Medium/High)
- Risk rating (combined score)
- Mitigation controls (what prevents or reduces the risk)
- Residual risk (risk remaining after controls)
- Responsible person

**Key training delivery risks to consider:**
- Trainer/assessor unavailability (illness, resignation)
- Assessment validity issues (tools not mapped to all unit requirements)
- LLN barriers affecting completion rates
- Third-party arrangement quality failures
- Qualification supersession/currency risk
- Learner welfare incidents during placement
- Technology failures for online delivery
- Low enrolment affecting commercial viability

**What auditors check:** Evidence of a genuine, maintained risk register — not a one-time document. Auditors look for risk reviews following incidents and evidence of mitigation controls actually operating.';

$string['complaintsprocess'] = 'Complaints & Appeals Reference';
$string['complaintsprocess_help'] = '**ASQA Standard 6.1 — Complaints and Appeals**

This section references your RTO\'s formal complaints and appeals process. The process must be:
- Documented and accessible (available on your website and in enrolment documentation)
- Fair (complaints handled by someone not involved in the original issue)
- Timely (defined response timeframes — typically 20 business days)
- Free from charge to the complainant

**Document here:**
- How to lodge a complaint or assessment appeal (internal process)
- Timeline for acknowledgement and resolution
- The appeal review process (who conducts, what they consider)
- External escalation options (ASQA, relevant state training authority, consumer affairs)
- How complaint/appeal outcomes are recorded and used for continuous improvement

**What auditors check:** That the process is genuinely accessible, that learners know about it, and that complaints are handled within the documented timeframes. Auditors interview learners about whether they know how to make a complaint.

**Best practice:** Learners should be told about the complaints process at induction, in their student handbook, and on invoices/receipts. Test your own process annually to ensure it still works as documented.';

$string['continuousimprovement'] = 'Feedback & Review Mechanisms';
$string['continuousimprovement_help'] = '**ASQA Standard 2.2 (Governance) — Quality Assurance and Continuous Improvement**

RTOs must systematically collect and act on feedback. This is a mandatory annual reporting requirement. Document:

**Quality Indicator data collection (mandatory ASQA reporting):**
- Learner engagement survey (administered and submitted to ASQA annually)
- Employer satisfaction survey (if applicable to your qualification)
- Competency completion data

**Other feedback mechanisms:**
- Trainer/assessor feedback (how gathered, how often)
- Assessment validation findings and improvement actions
- Graduate outcomes tracking
- Industry consultation feedback (how used to update the TAS)

**The continuous improvement cycle:**
1. Collect feedback
2. Analyse data and identify improvement opportunities
3. Plan and implement improvements
4. Evaluate effectiveness of improvements
5. Document the cycle in your continuous improvement register

**What auditors check:** Evidence that the cycle is CLOSED — that feedback leads to documented improvements that are then evaluated for effectiveness. A list of feedback mechanisms without evidence of action taken and outcomes evaluated is insufficient.

**Best practice (ASQA TAS Practice Guide 2024):** Maintain a continuous improvement register that records the source of each improvement, the action taken, who was responsible, the completion date, and the measured outcome.';

$string['revisionnotes'] = 'Revision Notes';
$string['revisionnotes_help'] = '**ASQA Standard 1.1 — TAS Version Control and Currency**

Revision notes document the history of changes made to this TAS. Best practice is to record:
- Date of revision
- Version number (e.g. v1.0 → v1.1)
- Summary of what was changed and why (e.g. "Updated assessment methods — added third-party report tool following validation findings")
- Who made and approved the revision
- Next scheduled review date

**What auditors check:** That the TAS is kept current and that version control demonstrates the RTO actively maintains and improves its training and assessment approach.

**Best practice:** Schedule an annual TAS review and document it — even if no substantive changes are made. The review itself demonstrates active quality management. Major triggers for review: training package updates, audit findings, validation outcomes, change in delivery mode or learner cohort, trainer changes.';

// CERTIFICATE SETTINGS TOOLTIPS
$string['certificates_help'] = 'Certificates section manages issuance of Testamurs, Statements of Attainment, and Records of Results. Under Standard 1.9 (Certification), certificates MUST be issued within 30 CALENDAR DAYS of competency achievement OR program completion. The AQF Certification Register must be maintained for 30 YEARS. USI is required before issuing any AQF credential.';
$string['certtype_help'] = 'Certificate type per AQF requirements: Testamur (full qualification completion), Statement of Attainment (partial completion - one or more units), Record of Results (transcript of all outcomes). Each type has specific content requirements and must include RTO code, qualification/unit codes, and NRT logo.';
$string['certnumber_help'] = 'Unique certificate number generated automatically using the prefix from plugin settings. This number is used for verification and MUST be recorded in the AQF Certification Register for 30 YEARS per Standard 1.9. Numbers are sequential and non-reusable.';
$string['certissuedate_help'] = 'Date the certificate is issued. CRITICAL: Must be within 30 CALENDAR DAYS of final competency determination. This date appears on the certificate and triggers the 30-year retention period. Delays beyond 30 days are a compliance breach.';
$string['certqrcode_help'] = 'QR code for online verification (if enabled in settings). When scanned, links to your RTO\'s verification page to confirm authenticity. Helps prevent certificate fraud. Verification must be available for 30 years per AQF Certification Register requirements.';

// AVETMISS/NAT EXPORT TOOLTIPS
$string['natexport_help'] = 'AVETMISS NAT file export generates the 10 National Activity Training (NAT) files required for government reporting. Files are validated before export to identify errors and warnings. Resolve all errors before submitting to your State Training Authority or NCVER.';
$string['natvalidation_help'] = 'Pre-export validation checks your data against AVETMISS Edition 2.3 business rules. Errors (red) must be fixed before export. Warnings (amber) should be reviewed but won\'t prevent export. Common issues: missing USIs, invalid postcodes, date conflicts.';
$string['natperiod_help'] = 'Select the reporting period: calendar year for TVA (Total VET Activity), or semester for some state reporting. Only activity within the selected period is included in the export. Ensure all assessments are finalised before generating files.';

// SURVEY TOOLTIPS
$string['surveys_help'] = 'Quality Indicator surveys (Learner Engagement and Employer Satisfaction) are mandatory annual data collections. This section helps you send surveys, collect responses, and analyse results. Survey data must be reported to ASQA annually and used to drive improvement.';
$string['surveytype_help'] = 'Survey type: Learner Engagement (sent to students), Employer Satisfaction (sent to employers). ASQA prescribes the questions but RTOs choose when/how to administer. Consider timing to maximise response rates.';
$string['surveyresponse_help'] = 'View individual and aggregate survey responses. Look for patterns in feedback - consistent criticism indicates systemic issues requiring action. Positive feedback can be used for marketing with student permission.';
$string['surveyanalysis_help'] = 'AI-powered analysis of survey responses identifies themes, trends, and actionable insights. Use this to prioritise improvement activities. Feed insights into your continuous improvement register.';

// DEADLINES SECTION
$string['deadlines_help'] = 'Key regulatory and funding deadlines for your RTO. Missing deadlines can result in funding penalties, regulatory action, or scope restrictions. This section provides advance warning of approaching deadlines.';
$string['deadline_tva_help'] = 'Total VET Activity (TVA) submission deadline - typically 30 June for the previous calendar year. Submit your NAT files via NCVER\'s SVTS portal. Late submissions result in data quality flags on your ASQA dashboard.';
$string['deadline_qi_help'] = 'Quality Indicator data submission deadline - annually in March/April for the previous year. Submit via the DET portal. Late or non-submission is a compliance breach.';
$string['deadline_declaration_help'] = 'ASQA Annual Declaration of Compliance deadline - 31 December each year. CEO/Director must sign off on the declaration. Late submission can trigger ASQA compliance activity.';

// AUDIT LOG
$string['auditlog_help'] = 'The audit log records all changes to compliance data including who made the change, when, and what was changed. This provides an audit trail for ASQA assessors and supports your quality assurance processes. Logs are retained for 7 years.';

// ALERTS SECTION  
$string['alerts_help'] = 'Predictive compliance alerts use AI to identify potential compliance issues before they become problems. Alerts are generated based on patterns in your data, approaching deadlines, and common audit findings. Address alerts proactively to maintain compliance.';

// SUPERVISION LOG SECTION
$string['supervision_log'] = 'Supervision Log';
$string['add_supervision'] = 'Add Supervision Log';
$string['edit_supervision'] = 'Edit Supervision Log';
$string['supervision_details'] = 'Supervision Details';
$string['supervision_saved'] = 'Supervision log saved successfully';
$string['supervision_deleted'] = 'Supervision log deleted';
$string['supervision_validated'] = 'Supervision log validated by RTO Manager';
$string['trainer_supervised'] = 'Trainer Being Supervised';
$string['trainer_supervised_help'] = 'Select the trainer who was supervised. Under the 2025 Credential Policy, this applies to persons working under direction (Sections 1C, 1D) who hold skill sets or secondary teaching qualifications but NOT full TAE credentials. CRITICAL: Persons under direction cannot make assessment judgements - they can only collect evidence and contribute to assessment.';
$string['supervisor'] = 'Supervisor';
$string['supervisor_help'] = 'Select the supervising trainer. The supervisor MUST hold a full TAE credential meeting Section 1A or 1B requirements (TAE40122, TAE40116, TAE40110, or Diploma VET) AND have vocational competency and industry currency in the relevant training product area. The supervisor is responsible for all assessment judgements.';
$string['supervision_date'] = 'Supervision Date';
$string['supervision_type'] = 'Supervision Type';
$string['supervision_type_help'] = 'Type of supervision activity per the 2025 Credential Policy: Observation (watching training/assessment delivery), Feedback (structured feedback session), Assessment Review (supervisor reviewing and making assessment judgements - REQUIRED when supervisee collects evidence), QA Check (quality assurance review), Mentoring (ongoing guidance and support). Note: Assessment judgements MUST be made by the qualified supervisor, not the person under direction.';
$string['duration_minutes'] = 'Duration (minutes)';
$string['duration_minutes_help'] = 'Approximate duration of the supervision session in minutes.';
$string['supervision_activities'] = 'Supervision Activities';
$string['activities_description'] = 'Description of Activities';
$string['activities_description_help'] = 'Describe the supervision activities conducted: what was observed, topics covered, assessments reviewed, etc. Be specific enough to demonstrate meaningful supervision occurred.';
$string['feedback_provided'] = 'Feedback Provided';
$string['feedback_provided_help'] = 'Document the feedback provided to the trainee during or after the supervision session. Include both positive feedback and areas for development.';
$string['development_needs'] = 'Development Needs Identified';
$string['development_needs_help'] = 'List any development needs or gaps identified during supervision. These should inform the trainee\'s development plan and future supervision focus areas.';
$string['follow_up_actions'] = 'Follow-up Actions';
$string['action_items'] = 'Action Items';
$string['action_items_help'] = 'List specific actions to be completed by the trainee following this supervision session. Include clear, measurable outcomes.';
$string['actions_due_date'] = 'Actions Due Date';
$string['actions_completed'] = 'Actions Completed';
$string['assessment_judgement_restricted'] = 'Assessment Judgement Restricted';
$string['assessment_judgement_restricted_help'] = 'Check this if the trainee is in role 1C or 1D and cannot make final assessment judgements without supervisor oversight. The supervisor must verify all assessment decisions.';
$string['next_supervision_date'] = 'Next Supervision Date';
$string['error_same_trainer_supervisor'] = 'Trainer and supervisor cannot be the same person';
$string['notes'] = 'Notes';

// TRAINER FORM ENHANCEMENTS
$string['industry_currency_type'] = 'Industry Currency Type';
$string['industry_currency_type_help'] = 'Select how industry currency is maintained. Per ASQA Credential Policy, trainers must demonstrate current industry skills. Options include ongoing employment, industry projects, professional development, industry engagement, and more. Refer to ASQA guidance for acceptable currency activities.';
$string['resume_document'] = 'Resume/CV Upload';
$string['resume_document_help'] = 'Upload the trainer\'s resume or CV documenting their industry experience and qualifications. This provides evidence of vocational competency and industry currency.';
$string['manager_signoff'] = 'RTO Manager Sign-off';
$string['manager_signoff_help'] = 'RTO Manager verification that this trainer\'s credentials have been verified and they are approved to deliver and assess the mapped training products.';
$string['manager_signoff_date'] = 'Sign-off Date';
$string['manager_signoff_by'] = 'Signed off by';
$string['vocational_qualification_examples'] = 'e.g., BSB50420, TAE50216, etc. Include qualification code, title, and date achieved. One per line.';
$string['industry_currency_examples'] = 'Describe current industry engagement: employment, consulting, projects, professional memberships, industry events attended, etc.';
$string['credential_role'] = 'Credential Role Classification';
$string['credential_role_help'] = 'ASQA Credential Policy role classification: 1A/1B (full TAE, can train/assess independently), 1C/1D (working towards TAE, requires supervision), 2A/2B/2C (industry experts, different supervision requirements), 3A/3B (validators).';

// VALIDATION SCHEDULE ENHANCEMENTS
$string['risk_factors'] = 'Risk Factors';
$string['risk_factors_help'] = 'Select the risk factors that triggered this validation activity. High-risk products should be validated more frequently. Risk factors include: new product, student complaints, poor outcomes, audit findings, trainer changes, significant enrolment changes.';
$string['methodology_samples'] = 'Validation Methodologies';
$string['methodology_samples_help'] = 'Select the validation methodologies used: document review, observation of assessment, student interviews, industry expert review, benchmarking against other RTOs, mapping analysis, and more.';
$string['findings_count_help'] = 'Number of findings or issues identified during validation. Findings should be categorised by severity and linked to improvement actions.';
$string['report_document_help'] = 'Upload the validation report document. The report should include findings, recommendations, and required actions. This provides evidence for ADC and ASQA audits.';
$string['adc_linked_help'] = 'Check this to link validation evidence to your Annual Declaration of Compliance. ASQA requires evidence of validation activities in the ADC.';

// COMPLAINTS SUBCATEGORIES
$string['complaint_subcategory'] = 'Complaint Subcategory';
$string['complaint_subcategory_help'] = 'Select a subcategory to classify this complaint more specifically. Subcategories help identify systemic issues and inform improvement priorities.';

// GOVERNANCE ENHANCEMENTS  
$string['declaration_upload'] = 'Signed Declaration Upload';
$string['declaration_upload_help'] = 'Upload the signed Fit and Proper Person Declaration. Each governing person must complete this declaration. ASQA may request these during audits.';
$string['suitability_criteria'] = 'Suitability Criteria';
$string['suitability_criteria_help'] = 'Suitability criteria per ASQA include: no relevant criminal history, no prior adverse regulatory action, no bankruptcy/insolvency, no disqualification from managing corporations, and demonstrated skills/knowledge for the role.';
$string['police_check_upload'] = 'Police Check Upload';
$string['police_check_upload_help'] = 'Upload the National Police Check certificate. Checks should be no more than 3 years old. This provides evidence that governing persons meet fit and proper person requirements.';

// ===================================================================================
// COMPREHENSIVE FORM FIELD TOOLTIPS - Maximum helpful advice on EVERY field
// ===================================================================================

// THIRD PARTY ARRANGEMENTS FORM TOOLTIPS
$string['thirdparty_header'] = 'Third Party Arrangement Details';
$string['thirdparty_header_help'] = 'Third party arrangements must be documented and monitored per ASQA Standards. This includes partnerships, subcontracting arrangements, auspicing, and venue hire. ASQA must be notified within 30 days of entering a third party arrangement for delivery of training and assessment.';
$string['organisationname'] = 'Organisation Name';
$string['organisationname_help'] = 'Enter the full legal name of the third party organisation exactly as registered. This should match their ABN registration and any contractual documents. Verify spelling carefully as this appears on compliance reports.';
$string['tradingname'] = 'Trading Name';
$string['tradingname_help'] = 'If the organisation operates under a different trading name (also known as "trading as" or "DBA"), enter it here. Leave blank if they only use their legal name. This helps identify the organisation in day-to-day communications.';
$string['abn_help'] = 'Enter the 11-digit Australian Business Number without spaces. You can verify ABNs at the Australian Business Register (ABR) website. This is essential for contractual and tax documentation. Format: 12345678901';
$string['arrangementtype'] = 'Arrangement Type';
$string['arrangementtype_help'] = 'Select the type of arrangement: PARTNERSHIP - sharing delivery responsibilities; SUBCONTRACT - they deliver training/assessment on your behalf; AUSPICE - they operate under your RTO registration; VENUE - facility/equipment hire only. Each type has different ASQA notification and monitoring requirements.';
$string['contactinfo_header'] = 'Contact Information';
$string['contactinfo_header_help'] = 'Maintain current contact details for the third party. This person should be your primary point of contact for compliance matters, monitoring activities, and issue resolution. Update whenever contacts change.';
$string['contactname'] = 'Contact Name';
$string['contactname_help'] = 'Enter the full name of your primary contact person at the third party organisation. This should be someone with authority to discuss compliance matters and respond to queries. Include their position/title if helpful.';
$string['contactemail'] = 'Contact Email';
$string['contactemail_help'] = 'Enter a valid business email address for the primary contact. This will be used for official correspondence, monitoring requests, and compliance communications. Consider using a role-based email if personnel may change.';
$string['contactphone'] = 'Contact Phone';
$string['contactphone_help'] = 'Enter a direct phone number for the primary contact including area code. Format: (02) 1234 5678 or +61 2 1234 5678 for international. Include mobile if that is preferred for urgent matters.';
$string['agreementinfo_header'] = 'Agreement Details';
$string['agreementinfo_header_help'] = 'Document the formal agreement terms. Written agreements are mandatory for training/assessment delivery arrangements. The agreement must include specific clauses required by ASQA regarding quality assurance, issuance, and learner rights.';
$string['agreementstartdate'] = 'Agreement Start Date';
$string['agreementstartdate_help'] = 'Select the date the written agreement commenced or will commence. This is typically the date both parties signed the contract. ASQA notification (if required) must be made within 30 days of this date.';
$string['agreementenddate'] = 'Agreement End Date';
$string['agreementenddate_help'] = 'Select the date the agreement expires or is scheduled to end. Leave blank for ongoing/rolling agreements. Set reminders to review and renew before expiry. Expired agreements must be renewed or terminated.';
$string['qualificationscovered'] = 'Qualifications Covered';
$string['qualificationscovered_help'] = 'List all qualification codes and names covered by this arrangement, one per line. Format: CODE - Name (e.g., BSB50420 - Diploma of Leadership and Management). This must match your scope of registration and the agreement terms.';
$string['asqanotification_header'] = 'ASQA Notification (30-Day Requirement)';
$string['asqanotification_header_help'] = 'ASQA must be notified within 30 business days of entering into a third party arrangement where training and/or assessment is delivered by the third party. Failure to notify is a compliance breach. Venue-only arrangements typically do not require notification.';
$string['asqanotified'] = 'ASQA Notified';
$string['asqanotified_help'] = 'Check this box once you have submitted notification to ASQA about this arrangement. Keep a copy of your notification submission as evidence. ASQA notifications are submitted via the ASQA portal (portal.asqa.gov.au).';
$string['asqanotificationdate'] = 'ASQA Notification Date';
$string['asqanotificationdate_help'] = 'Select the date you submitted notification to ASQA. This must be within 30 business days of the agreement start date. Retain proof of submission (confirmation email, portal screenshot) for audit evidence.';
$string['mandatoryclauses_header'] = 'Mandatory Agreement Clauses';
$string['mandatoryclauses_header_help'] = 'ASQA requires specific clauses in third party agreements. These ensure learners are aware of arrangements, protect the integrity of qualifications, and maintain RTO accountability. All applicable clauses must be included in your written agreement.';
$string['mandatoryclausesnrtlogo'] = 'NRT Logo Clause';
$string['mandatoryclausesnrtlogo_help'] = 'Check this to confirm your agreement includes a clause prohibiting the third party from using the NRT (Nationally Recognised Training) logo independently. Only the RTO may use and authorise use of the NRT logo on marketing and certification.';
$string['mandatoryclausesaqf'] = 'AQF Issuance Clause';
$string['mandatoryclausesaqf_help'] = 'Check this to confirm your agreement includes a clause stating that only the RTO may issue AQF qualifications and statements of attainment. Third parties cannot issue certification - this remains the RTO\'s responsibility.';
$string['mandatoryclausestransparency'] = 'Student Transparency Clause';
$string['mandatoryclausestransparency_help'] = 'Check this to confirm your agreement includes a clause ensuring students are informed of the arrangement. Learners must know who is delivering their training, that they are enrolled with your RTO, and how to contact the RTO directly.';
$string['monitoring_header'] = 'Monitoring & Risk Management';
$string['monitoring_header_help'] = 'RTOs must actively monitor third party arrangements to ensure quality and compliance. This includes regular reviews, site visits, trainer credential verification, and learner feedback analysis. Document all monitoring activities.';
$string['monitoringfrequency'] = 'Monitoring Frequency';
$string['monitoringfrequency_help'] = 'Select how often you conduct formal monitoring of this arrangement. MONTHLY for high-risk or new arrangements; QUARTERLY is standard for established arrangements; BIANNUAL for low-risk venue arrangements; ANNUAL minimum for any arrangement.';
$string['lastmonitoringdate'] = 'Last Monitoring Date';
$string['lastmonitoringdate_help'] = 'Select the date of the most recent monitoring activity. This could include site visits, document reviews, trainer credential checks, or learner surveys. Update this each time monitoring is conducted.';
$string['nextmonitoringdate'] = 'Next Monitoring Date';
$string['nextmonitoringdate_help'] = 'Select when the next monitoring activity is scheduled. This should align with your monitoring frequency. Set calendar reminders to ensure monitoring occurs on schedule. Overdue monitoring is a compliance risk.';
$string['riskrating'] = 'Risk Rating';
$string['riskrating_help'] = 'Assess the risk level of this arrangement. HIGH: new partners, complaints history, high enrolments, delivery of high-risk qualifications. MEDIUM: established partners with some concerns. LOW: long-term partners with proven track record. Adjust monitoring frequency based on risk.';
$string['staffcredentialsverified'] = 'Staff Credentials Verified';
$string['staffcredentialsverified_help'] = 'Check this to confirm you have verified that all trainers and assessors engaged by the third party meet ASQA credential requirements. This includes TAE qualifications, vocational competency, and industry currency. Reverify annually or when staff change.';
$string['thirdparty_status'] = 'Arrangement Status';
$string['thirdparty_status_help'] = 'ACTIVE: currently operating under this arrangement. INACTIVE: temporarily suspended but not terminated. EXPIRED: agreement end date has passed - renew or terminate. TERMINATED: arrangement has ended - retain records for 7 years.';
$string['thirdparty_notes'] = 'Notes';
$string['thirdparty_notes_help'] = 'Record any additional information about this arrangement: special conditions, monitoring observations, concerns raised, improvement actions, contact history, or audit notes. This provides an audit trail of your oversight activities.';

// COMPLAINTS FORM TOOLTIPS
$string['complaint_header'] = 'Complaint Registration';
$string['complaint_header_help'] = 'RTOs must have a complaints and appeals policy and maintain a register of all complaints received. Complaints must be acknowledged within 10 working days and resolved within 60 calendar days. This register provides evidence for ASQA audits.';
$string['complaint_reference'] = 'Complaint Reference';
$string['complaint_reference_help'] = 'Enter a unique reference number for this complaint. Use a consistent format (e.g., COMP-2024-001). This reference appears on all correspondence and helps track the complaint through resolution. Each complaint must have a unique reference.';
$string['complainanttype'] = 'Complainant Type';
$string['complainanttype_help'] = 'Select who is making the complaint: STUDENT - current or former learner; EMPLOYER - workplace or industry partner; PUBLIC - member of the community; ANONYMOUS - identity not disclosed. This helps analyse complaint patterns and inform responses.';
$string['isanonymous'] = 'Anonymous Complaint';
$string['isanonymous_help'] = 'Check this if the complainant wishes to remain anonymous. Anonymous complaints must still be investigated and resolved, but communication will be limited. Note that some matters may be difficult to investigate without complainant details.';
$string['complainantname'] = 'Complainant Name';
$string['complainantname_help'] = 'Enter the full name of the person making the complaint. This is required for non-anonymous complaints. Ensure correct spelling for correspondence. For student complaints, verify against enrolment records.';
$string['complainantemail'] = 'Complainant Email';
$string['complainantemail_help'] = 'Enter a valid email address for the complainant. This is the primary method for sending acknowledgement letters, progress updates, and outcome notifications. Verify the email is correct before sending correspondence.';
$string['complainantphone'] = 'Complainant Phone';
$string['complainantphone_help'] = 'Enter a contact phone number for the complainant. This is useful for clarifying complaint details, discussing resolution options, and urgent communications. Include area code and mobile if available.';
$string['issueinformation_header'] = 'Issue Details';
$string['issueinformation_header_help'] = 'Document the nature of the complaint thoroughly. Clear categorisation helps identify systemic issues, assign appropriate investigators, and track patterns across your organisation.';
$string['complaint_category'] = 'Complaint Category';
$string['complaint_category_help'] = 'Select the primary category: TRAINING - delivery methods, trainer quality, course content; ASSESSMENT - fairness, competency decisions, feedback; SERVICE - admin, enrolment, fees, communication; CONDUCT - staff behaviour, discrimination; FACILITIES - learning environment, equipment; OTHER - matters not covered above.';
$string['complaint_subject'] = 'Subject/Title';
$string['complaint_subject_help'] = 'Enter a brief, descriptive title summarising the complaint (e.g., "Assessment marking delay for BSB50420" or "Trainer unprofessional conduct in CHC class"). This appears in reports and helps quickly identify the nature of each complaint.';
$string['complaint_description'] = 'Full Description';
$string['complaint_description_help'] = 'Record the complete details of the complaint in the complainant\'s own words where possible. Include: what happened, when it happened, who was involved, what impact it had, and what outcome the complainant seeks. Be thorough - this is your primary investigation starting point.';
$string['complaint_priority'] = 'Priority Level';
$string['complaint_priority_help'] = 'LOW: minor inconvenience, no impact on training outcomes. MEDIUM: moderate impact requiring attention. HIGH: significant impact on learning or student welfare. CRITICAL: safety concerns, potential regulatory breach, or serious harm. Higher priorities require faster response times.';
$string['complaint_status'] = 'Complaint Status';
$string['complaint_status_help'] = 'RECEIVED: logged but not yet actioned. INVESTIGATING: actively being reviewed and investigated. RESOLVED: outcome determined and communicated. CLOSED: resolution accepted, no further action. WITHDRAWN: complainant chose not to proceed.';
$string['datereceived'] = 'Date Received';
$string['datereceived_help'] = 'Select the date the complaint was first received by the RTO. This starts the 10-working-day acknowledgement clock and 60-day resolution timeframe. Use the date on the written complaint or first formal notification.';
$string['targetresolutiondate'] = 'Target Resolution Date';
$string['targetresolutiondate_help'] = 'Set a target date for resolving this complaint. ASQA expects resolution within 60 calendar days of receipt. Complex complaints may take longer but should be communicated to the complainant with revised timeframes.';
$string['resolutiondetails_header'] = 'Resolution Details';
$string['resolutiondetails_header_help'] = 'Document all resolution activities and outcomes. This provides evidence of your complaints handling process and is essential for ASQA audits and continuous improvement.';
$string['dateacknowledged'] = 'Date Acknowledged';
$string['dateacknowledged_help'] = 'Select the date you formally acknowledged receipt of the complaint to the complainant. This must be within 10 working days of receipt. Acknowledgement should be in writing and include the complaint reference number.';
$string['actualresolutiondate'] = 'Actual Resolution Date';
$string['actualresolutiondate_help'] = 'Select the date the complaint was formally resolved. This is when the outcome was communicated to the complainant. Calculate days from receipt to resolution for reporting and trend analysis.';
$string['resolution'] = 'Resolution Summary';
$string['resolution_help'] = 'Document how the complaint was resolved: investigation findings, actions taken, outcome decision, and any remedies provided. Include the rationale for decisions. This is key evidence for ASQA audits and internal quality reviews.';
$string['outcomesatisfactory'] = 'Outcome Satisfactory to Complainant';
$string['outcomesatisfactory_help'] = 'Record whether the complainant accepted the resolution. YES: complainant satisfied with outcome. NO: complainant dissatisfied - consider whether appeal rights apply. If no, document why and any further actions offered.';
$string['issystemic'] = 'Systemic Issue Identified';
$string['issystemic_help'] = 'Check this if investigation revealed a systemic issue affecting multiple students or processes. Systemic issues must be linked to continuous improvement actions. Multiple similar complaints often indicate systemic problems requiring broader remediation.';
$string['complaint_notes'] = 'Additional Notes';
$string['complaint_notes_help'] = 'Record any additional information: investigation timeline, evidence reviewed, people interviewed, regulatory notifications made, continuous improvement links, or appeal outcomes. This provides a complete audit trail.';

// GOVERNANCE FORM TOOLTIPS
$string['governance_header'] = 'Governing Person Registration';
$string['governance_header_help'] = 'ASQA requires RTOs to notify of all "governing persons" - those who can influence the direction of the RTO. This includes directors, CEOs, and other key executives. Each must complete Fit and Proper Person declarations.';
$string['governance_fullname'] = 'Full Name';
$string['governance_fullname_help'] = 'Enter the person\'s full legal name exactly as it appears on their identification documents. This must match the name used for police checks and ASQA notifications. Include all given names.';
$string['positiontype'] = 'Position Type';
$string['positiontype_help'] = 'Select the governance role: DIRECTOR - company director with voting rights; CEO - Chief Executive Officer or equivalent; SECRETARY - company secretary; PUBLIC OFFICER - registered public officer for the organisation. Multiple roles may apply to one person.';
$string['positiontitle'] = 'Position Title';
$string['positiontitle_help'] = 'Enter the specific job title for this person (e.g., "Managing Director", "Chief Executive Officer", "Company Secretary"). This may differ from the position type and reflects their day-to-day role.';
$string['governance_email'] = 'Email Address';
$string['governance_email_help'] = 'Enter a direct email address for this governing person. This is used for compliance communications and may be shared with ASQA if required. A work email is preferred for audit trail purposes.';
$string['governance_phone'] = 'Phone Number';
$string['governance_phone_help'] = 'Enter a contact phone number for this person. Include mobile if that is the preferred contact. This enables direct contact for urgent governance or compliance matters.';
$string['appointmentdate'] = 'Appointment Date';
$string['appointmentdate_help'] = 'Select the date this person commenced in their governance role. For directors, this is typically the date recorded with ASIC. ASQA must be notified of new governing persons within 10 business days of appointment.';
$string['cessationdate'] = 'Cessation Date';
$string['cessationdate_help'] = 'If this person has ceased their governance role, select the date of cessation. ASQA must be notified of departures within 10 business days. Leave blank for current governing persons. Retain records for 7 years after cessation.';
$string['declarations_header'] = 'Fit & Proper Person Declaration';
$string['declarations_header_help'] = 'Each governing person must declare they meet ASQA\'s Fit and Proper Person requirements. This includes declarations about criminal history, regulatory history, and suitability. Declarations should be renewed annually.';
$string['fitproperdeclared'] = 'Declaration Completed';
$string['fitproperdeclared_help'] = 'Check this to confirm this person has completed and signed a Fit and Proper Person Declaration form. The declaration must cover all ASQA requirements including criminal history, regulatory history, and financial matters. Keep the signed original on file.';
$string['fitproperdeclareddate'] = 'Declaration Date';
$string['fitproperdeclareddate_help'] = 'Select the date the Fit and Proper Person Declaration was signed. Declarations should be refreshed annually or when circumstances change. ASQA may request copies during audits.';
$string['suitability_header'] = 'Suitability Assessment';
$string['suitability_header_help'] = 'Beyond self-declaration, RTOs should conduct their own suitability assessment of governing persons. This demonstrates due diligence in ensuring governance integrity.';
$string['suitabilityassessed'] = 'Suitability Assessed';
$string['suitabilityassessed_help'] = 'Check this to confirm you have conducted a suitability assessment for this governing person. Assessment should verify declaration claims where possible, review publicly available information, and consider any known concerns.';
$string['suitabilityassesseddate'] = 'Assessment Date';
$string['suitabilityassesseddate_help'] = 'Select the date the suitability assessment was conducted. Document the assessment process and findings separately. Reassess if new information comes to light or at each declaration renewal.';
$string['policecheck_header'] = 'National Police Check';
$string['policecheck_header_help'] = 'While not mandatory for all governing persons, national police checks demonstrate due diligence and support Fit and Proper Person assessments. Consider obtaining checks for all key governance roles.';
$string['policecheckdate'] = 'Police Check Date';
$string['policecheckdate_help'] = 'Select the date the National Police Check was issued. Checks are typically valid for 3 years. Schedule renewals before expiry. Original certificates should be sighted and copies retained.';
$string['policecheckstatus'] = 'Police Check Result';
$string['policecheckstatus_help'] = 'CLEAR: no relevant disclosures. DISCLOSURES PRESENT: matters disclosed - assess relevance to role. PENDING: check requested but not yet received. NOT REQUIRED: check not conducted - document rationale.';
$string['governance_status'] = 'Status';
$string['governance_status_help'] = 'ACTIVE: currently serving in this governance role. INACTIVE: ceased governance role - set cessation date. Inactive records should be retained for 7 years minimum for audit purposes.';
$string['governance_notes'] = 'Notes';
$string['governance_notes_help'] = 'Record any relevant information: assessment notes, concerns identified and how addressed, ASQA notification references, declaration renewal reminders, or audit observations. Maintain confidentiality of sensitive information.';

// INSURANCE FORM TOOLTIPS  
$string['insurance_header'] = 'Insurance Policy Registration';
$string['insurance_header_help'] = 'RTOs must maintain appropriate insurance coverage including public liability and professional indemnity. Insurance certificates provide evidence of coverage for ASQA audits and protect the RTO from financial risk.';
$string['insurancetype'] = 'Insurance Type';
$string['insurancetype_help'] = 'PUBLIC LIABILITY: covers injury to third parties and property damage on RTO premises or during training. PROFESSIONAL INDEMNITY: covers claims arising from professional services/advice. WORKERS COMPENSATION: mandatory cover for employees.';
$string['insurance_provider'] = 'Insurance Provider';
$string['insurance_provider_help'] = 'Enter the name of the insurance company providing coverage. This should match the Certificate of Currency. Include broker name if applicable (e.g., "QBE Insurance via XYZ Brokers").';
$string['policynumber'] = 'Policy Number';
$string['policynumber_help'] = 'Enter the policy number exactly as shown on your Certificate of Currency. This unique identifier is essential for claims and verification. Different policy types will have different numbers.';
$string['coverage_header'] = 'Coverage Details';
$string['coverage_header_help'] = 'Document the specifics of what the policy covers. This information helps ensure coverage is adequate for your operations and assists in claims if needed.';
$string['coverageamount'] = 'Coverage Amount';
$string['coverageamount_help'] = 'Enter the total coverage amount (sum insured) in Australian dollars. For public liability, $10-20 million is typical. For professional indemnity, coverage should reflect your exposure. Enter the number only (e.g., 10000000 for $10 million).';
$string['premium'] = 'Annual Premium';
$string['premium_help'] = 'Enter the annual premium amount in Australian dollars. This helps with budgeting and cost comparisons. Update when policies renew. Include GST if applicable.';
$string['excessamount'] = 'Excess Amount';
$string['excessamount_help'] = 'Enter the excess (deductible) payable per claim. This is the amount the RTO pays before insurance coverage applies. Higher excesses typically result in lower premiums.';
$string['coveragedetails'] = 'Coverage Details';
$string['coveragedetails_help'] = 'Describe what activities and situations are covered by this policy. For RTOs, ensure coverage includes: training delivery, student supervision, work placement activities, and online delivery if applicable. List key coverage inclusions.';
$string['exclusions'] = 'Exclusions';
$string['exclusions_help'] = 'List any significant exclusions or limitations in the policy. Common exclusions include: professional advice outside scope, intentional acts, and specific high-risk activities. Understanding exclusions helps identify coverage gaps.';
$string['coveragemapping_header'] = 'Coverage Mapping';
$string['coveragemapping_header_help'] = 'Map your insurance coverage to your training delivery. This ensures all delivery locations and modes are adequately covered and identifies any gaps requiring additional coverage.';
$string['deliverymodes'] = 'Delivery Modes Covered';
$string['deliverymodes_help'] = 'List the delivery modes covered by this insurance: face-to-face classroom, workplace delivery, online/distance, blended, simulated environments, etc. Verify unusual delivery modes are specifically included.';
$string['insurance_locations'] = 'Locations Covered';
$string['insurance_locations_help'] = 'List the locations where coverage applies: RTO premises, third party venues, workplace sites, and any geographical limitations. Some policies are state-specific or exclude certain activities outside approved locations.';
$string['policydates_header'] = 'Policy Period';
$string['policydates_header_help'] = 'Insurance policies run for a defined period, typically 12 months. Monitor expiry dates carefully - uninsured periods represent significant risk and potential compliance issues.';
$string['insurance_startdate'] = 'Policy Start Date';
$string['insurance_startdate_help'] = 'Select the date insurance coverage commenced. This is when the policy became active. Ensure no gap exists between old and new policies when renewing.';
$string['insurance_expirydate'] = 'Policy Expiry Date';
$string['insurance_expirydate_help'] = 'Select the date insurance coverage expires. Set reminders to renew well before expiry. Operating without valid insurance is a significant compliance and financial risk. ASQA may request current certificates during audits.';
$string['renewalreminderdays'] = 'Renewal Reminder Days';
$string['renewalreminderdays_help'] = 'Enter how many days before expiry you want to receive a renewal reminder. 30 days is recommended to allow time for quotes, comparison, and renewal processing. Critical for maintaining continuous coverage.';
$string['insurance_status'] = 'Policy Status';
$string['insurance_status_help'] = 'ACTIVE: current valid policy in force. EXPIRED: policy has passed expiry date - urgent renewal required. CANCELLED: policy terminated before expiry. Maintain records of all policies for audit purposes.';
$string['insurance_notes'] = 'Notes';
$string['insurance_notes_help'] = 'Record any relevant information: claims history, premium changes, broker contact details, special conditions, or coverage review notes. This helps with renewals and audit preparation.';

// VALIDATION FORM TOOLTIPS
$string['validation_header'] = 'Validation Activity Registration';
$string['validation_header_help'] = 'Validation is the quality review of assessment tools and judgements. ASQA requires systematic validation of all assessment tools. This register tracks your validation schedule and outcomes for compliance evidence.';
$string['validation_reference'] = 'Validation Reference';
$string['validation_reference_help'] = 'Enter a unique reference for this validation activity (e.g., VAL-2024-001). Use a consistent format across all validations. This reference links to findings and improvement actions.';
$string['validation_productcode'] = 'Product Code';
$string['validation_productcode_help'] = 'Enter the qualification or skill set code being validated (e.g., BSB50420). This should match your scope of registration. Validation should cover all training products within your 5-year cycle.';
$string['validation_productname'] = 'Product Name';
$string['validation_productname_help'] = 'Enter the full name of the qualification or skill set (e.g., "Diploma of Leadership and Management"). This helps identify the validation target in reports and schedules.';
$string['validation_unitcodes'] = 'Units Validated';
$string['validation_unitcodes_help'] = 'List the specific unit codes included in this validation, one per line. For whole qualification validation, list all units. For targeted validation, list only the units reviewed. Include both core and elective units.';
$string['validationtype'] = 'Validation Type';
$string['validationtype_help'] = 'INITIAL: first validation of new assessment tools before use. ONGOING: regular systematic validation of existing tools. POST-ASSESSMENT: review of assessment judgements and student work samples. Most validation is ongoing.';
$string['riskassessment_header'] = 'Risk Assessment';
$string['riskassessment_header_help'] = 'Risk-based validation prioritises high-risk products. This ensures validation resources focus where they can have most impact on quality and compliance.';
$string['validation_risklevel'] = 'Risk Level';
$string['validation_risklevel_help'] = 'HIGH: new products, complaints received, poor completion rates, audit findings. MEDIUM: established products with some concerns or moderate enrolments. LOW: proven products with good outcomes and trainer stability. High-risk products should be validated first and more frequently.';
$string['schedule_header'] = 'Schedule';
$string['schedule_header_help'] = 'Track when validation is scheduled and when it actually occurs. This ensures all products are validated within required timeframes and supports compliance reporting.';
$string['scheduleddate'] = 'Scheduled Date';
$string['scheduleddate_help'] = 'Select when this validation is scheduled to occur. Plan validations across the year to spread workload. Consider trainer availability and academic calendars. All products should be validated at least once every 5 years.';
$string['actualdate'] = 'Actual Date';
$string['actualdate_help'] = 'Select the date validation was actually conducted. This may differ from the scheduled date. Record the actual date for accurate reporting and to demonstrate validation has occurred.';
$string['validation_status'] = 'Status';
$string['validation_status_help'] = 'SCHEDULED: planned but not yet conducted. IN PROGRESS: validation currently underway. COMPLETED: validation finished with findings recorded. CANCELLED: validation did not proceed - document reason.';
$string['validators_header'] = 'Validation Panel';
$string['validators_header_help'] = 'ASQA requires validators to hold appropriate credentials. Validation panels should include people independent of the assessment being validated. Document who participated in each validation.';
$string['leadvalidator'] = 'Lead Validator';
$string['leadvalidator_help'] = 'Enter the name of the person leading this validation. The lead validator must hold TAE40122/TAE50222 or equivalent plus vocational competency in the area being validated. They coordinate the validation process and prepare findings.';
$string['panelmembers'] = 'Panel Members';
$string['panelmembers_help'] = 'List other validators participating, one per line. Include their role (e.g., "Jane Smith - Industry Expert"). Panels should include independent perspectives - not just the assessor whose work is being validated.';
$string['methodology_header'] = 'Methodology';
$string['methodology_header_help'] = 'Document the validation methods used. Multiple methods provide more robust validation. ASQA looks for systematic, documented validation processes.';
$string['samplesize'] = 'Sample Size';
$string['samplesize_help'] = 'Enter the number of assessment samples reviewed. Sample size depends on enrolment numbers: 10-20% is typical for smaller cohorts, larger cohorts may use statistical sampling. Document your sampling rationale.';
$string['samplingmethod'] = 'Sampling Method';
$string['samplingmethod_help'] = 'Describe how samples were selected: random, stratified (by assessor/location/date), targeted (complaints/concerns), or comprehensive (all assessments). Random or stratified sampling provides more generalisable findings.';
$string['outcomes_header'] = 'Validation Outcomes';
$string['outcomes_header_help'] = 'Record the outcomes of validation including any findings and required improvements. This provides evidence of your validation process and links to continuous improvement.';
$string['findings_count'] = 'Number of Findings';
$string['findings_count_help'] = 'Enter the total number of findings or issues identified. Categorise findings by severity: Critical (immediate action required), Major (significant improvement needed), Minor (enhancement opportunity). Zero findings is acceptable if tools are compliant.';
$string['findings'] = 'Findings Details';
$string['findings_help'] = 'Document each finding in detail: what was found, which units/tools affected, severity rating, and recommended actions. Be specific enough that someone could understand and address each finding. Link findings to improvement actions.';
$string['report_document'] = 'Report Document Reference';
$string['report_document_help'] = 'Enter the file name or reference for the full validation report. The report should include: scope of validation, panel details, methodology, findings, recommendations, and sign-off. Store reports in your document management system.';
$string['adc_linked'] = 'Linked to ADC';
$string['adc_linked_help'] = 'Check this if this validation provides evidence for your Annual Declaration of Compliance. ASQA requires evidence of assessment validation in the ADC. Completed validations with documented findings demonstrate ongoing compliance.';
$string['validation_notes'] = 'Additional Notes';
$string['validation_notes_help'] = 'Record any additional information: panel observations, good practices identified, trainer development needs, follow-up actions required, or links to related improvement activities.';

// TRANSITION FORM TOOLTIPS
$string['transition_header'] = 'Training Product Transition';
$string['transition_header_help'] = 'When training products are superseded, deleted, or updated, RTOs must manage transitions for enrolled students. This register tracks transition planning and ensures students complete within teach-out periods.';
$string['oldproductcode'] = 'Superseded Product Code';
$string['oldproductcode_help'] = 'Enter the code of the product being superseded or deleted (e.g., BSB50215). This is the old version that students are transitioning from. Check training.gov.au for supersession details.';
$string['oldproductname'] = 'Superseded Product Name';
$string['oldproductname_help'] = 'Enter the full name of the superseded product. This helps identify the transition and appears in student communications. Use the official title from training.gov.au.';
$string['transitiontype'] = 'Transition Type';
$string['transitiontype_help'] = 'SUPERSEDED: replaced by a new version - typically 1-2 year teach-out. DELETED: removed from the register entirely - teach-out by deletion date. UPDATED: minor changes not requiring full transition. Superseded and deleted products have regulatory teach-out deadlines.';
$string['newproduct_header'] = 'Replacement Product';
$string['newproduct_header_help'] = 'If the product is superseded (not deleted), document the replacement product students may transition to. This helps with planning and student communications.';
$string['newproductcode'] = 'Replacement Product Code';
$string['newproductcode_help'] = 'Enter the code of the new/replacement product if applicable (e.g., BSB50420). Leave blank for deleted products with no replacement. Check training.gov.au for replacement product details.';
$string['newproductname'] = 'Replacement Product Name';
$string['newproductname_help'] = 'Enter the full name of the replacement product. This appears in student communications about transition options. Students may choose to complete the old product or transition to the new version.';
$string['timeline_header'] = 'Transition Timeline';
$string['timeline_header_help'] = 'Regulatory timelines for transitions are set by training.gov.au. RTOs must complete all student enrolments before the teach-out deadline. Plan backwards from the deadline to ensure adequate time.';
$string['tganotificationdate'] = 'TGA Notification Date';
$string['tganotificationdate_help'] = 'Select the date training.gov.au published the supersession/deletion notice. This starts the teach-out period. Monitor training.gov.au regularly for announcements affecting your scope.';
$string['teachoutdeadline'] = 'Teach-Out Deadline';
$string['teachoutdeadline_help'] = 'Select the date by which all students must complete or transition. Typically 1-2 years from the TGA notification for supersession. After this date, no further enrolments or completions are permitted under the old product.';
$string['impactedstudents_header'] = 'Student Impact';
$string['impactedstudents_header_help'] = 'Track the number of students affected and your progress in contacting them. ASQA expects RTOs to proactively communicate with affected students about their options.';
$string['studentsaffected'] = 'Students Affected';
$string['studentsaffected_help'] = 'Enter the total number of students currently enrolled who will be affected by this transition. Include all students who have not yet completed, including those on hold or leave of absence.';
$string['studentscontacted'] = 'Students Contacted';
$string['studentscontacted_help'] = 'Enter the number of affected students who have been contacted about the transition. Contact should explain their options: accelerated completion, transition to new product, or implications of non-completion.';
$string['transitionplan_header'] = 'Transition Planning';
$string['transitionplan_header_help'] = 'Document your plan for managing this transition including student communication, accelerated delivery options, scope updates, and completion tracking.';
$string['transitionplan'] = 'Transition Plan Details';
$string['transitionplan_help'] = 'Describe your transition plan: How will students be notified? What completion acceleration options are available? How will you track progress? What support will be provided? Who is responsible for managing the transition?';
$string['mappingdocument'] = 'Unit Mapping Document';
$string['mappingdocument_help'] = 'Enter the reference for your unit mapping document showing how old units map to new units. This helps with Recognition of Prior Learning and determining what additional training students need if transitioning to the new product.';
$string['actionstaken_header'] = 'Actions Taken';
$string['actionstaken_header_help'] = 'Track key actions to manage the transition. These checkpoints help ensure all necessary steps are completed before the teach-out deadline.';
$string['scopeupdated'] = 'Scope Updated';
$string['scopeupdated_help'] = 'Check this once you have updated (or applied to update) your scope to include the new product and/or remove the old product. Scope applications should be submitted well before teach-out deadlines.';
$string['enrolmentsclosed'] = 'Enrolments Closed';
$string['enrolmentsclosed_help'] = 'Check this to mark new enrolments as closed for this superseded/deleted product. If a Linked Moodle Course is set below, saving with this box ticked will automatically disable self-enrolment on that Moodle course. Unticking re-enables it.';
$string['transition_linkedcourse'] = 'Linked Moodle Course';
$string['transition_linkedcourse_help'] = 'Select the Moodle course that delivers this superseded/deleted qualification. When "Enrolments Closed" is ticked, the plugin will automatically disable self-enrolment on this course so new students cannot enrol. Leave blank if you prefer to manage enrolments manually in Moodle.';
$string['transition_enrolstatus_closed'] = 'Enrolments Closed';
$string['transition_enrolstatus_open'] = 'Enrolments Open';
$string['transition_enrolstatus_overdue'] = 'Deadline Passed — Enrolments Still Open';
$string['transition_moodle_enrol_closed'] = 'Self-enrolment disabled on linked Moodle course.';
$string['transition_moodle_enrol_opened'] = 'Self-enrolment re-enabled on linked Moodle course.';
$string['transition_moodle_no_enrol'] = 'No self-enrolment instance found on the linked course — please disable enrolments manually in Moodle.';
$string['transition_status'] = 'Transition Status';
$string['transition_status_help'] = 'IDENTIFIED: transition need recognised but not yet planned. PLANNING: developing transition strategy. IN PROGRESS: actively managing student transitions. COMPLETED: all students completed or transitioned, teach-out period ended.';
$string['transition_notes'] = 'Notes';
$string['transition_notes_help'] = 'Record additional information: student queries, challenges encountered, extension requests, lessons learned. This helps with future transitions and provides audit trail evidence.';

// ENROLMENT FORM TOOLTIPS
$string['program_header'] = 'Program/Qualification Details';
$string['program_header_help'] = 'Link this activity to a Moodle course and specify the training product details. This connects the student\'s learning to their AVETMISS reporting requirements.';
$string['enrolment_courseid'] = 'Moodle Course';
$string['enrolment_courseid_help'] = 'Select the Moodle course this enrolment activity relates to. This links the AVETMISS data to the student\'s LMS experience. Only visible courses are shown.';
$string['programcode'] = 'Program Code';
$string['programcode_help'] = 'Enter the qualification code from training.gov.au (e.g., BSB50420). This is required for AVETMISS NAT120 file. Must match your scope of registration. Leave blank for unit-only enrolments.';
$string['programname'] = 'Program Name';
$string['programname_help'] = 'Enter the full qualification name exactly as shown on training.gov.au (e.g., "Diploma of Leadership and Management"). This appears on certificates and reports.';
$string['unit_header'] = 'Unit of Competency Details';
$string['unit_header_help'] = 'Specify the unit details if this is a unit-level enrolment. For full qualification enrolments, these may be auto-populated from the course settings.';
$string['unitcode_help'] = 'Enter the unit of competency code from training.gov.au (e.g., BSBOPS502). Required for AVETMISS NAT120 file. Must be listed on your scope or part of a qualification on your scope.';
$string['unitname_help'] = 'Enter the full unit name exactly as shown on training.gov.au (e.g., "Manage business operational plans"). This appears on statements of attainment.';
$string['activity_header'] = 'Activity Details';
$string['activity_header_help'] = 'AVETMISS requires tracking of training activity dates, hours, outcomes, and delivery modes for each enrolment. This data populates NAT120 and NAT130 files.';
$string['activitystartdate_help'] = 'Select the date training commenced for this unit/program. This is when the student began learning, not the enrolment date. Required for AVETMISS reporting.';
$string['activityenddate_help'] = 'Select the date training ended or is expected to end. For completed students, this is the last assessment date. For ongoing students, this is the expected completion date.';
$string['scheduledhours_help'] = 'Enter the scheduled supervised hours for this unit/activity. This is the planned face-to-face or synchronous training hours. Part of volume of learning calculations. Exclude self-directed study.';
$string['outcomeidentifier'] = 'Outcome Identifier';
$string['outcomeidentifier_help'] = 'Select the AVETMISS outcome code: 20 = Competent, 30 = Competent RPL, 40 = Credit Transfer, 51 = RPL-Granted, 60 = Withdrawn, 70 = Not Yet Competent. Code 00 = Not yet assessed is the default for in-progress activities.';
$string['deliverymode_help'] = 'Select how training is delivered: 10 = Classroom-based, 20 = Electronic-based, 30 = Employment-based, 40 = Other delivery. This AVETMISS code appears in NAT120.';
$string['funding_header'] = 'Funding Information';
$string['funding_header_help'] = 'AVETMISS requires tracking of funding sources and fees. This information is critical for government-funded training and accurate NAT120/NAT130 reporting.';
$string['fundingsourcenat'] = 'National Funding Source';
$string['fundingsourcenat_help'] = 'Select the national funding source code: 11 = Commonwealth specific, 13 = Commonwealth VET for schools, 15 = State/Territory, 20 = Domestic fee, 30 = International fee.';
$string['fundingsourcestate_help'] = 'Enter the state-specific funding code if applicable. This varies by state - check your state training authority requirements. Often required for Smart and Skilled, Jobs and Skills WA, etc.';
$string['tuitionfee_help'] = 'Enter the tuition fee charged to the student in dollars. Enter 0 if fully government-funded. This is required for AVETMISS and fee protection reporting.';
$string['feecharged_help'] = 'Select whether fees were charged: Y = Yes, fee charged; N = No fee charged. This affects AVETMISS validation and fee protection obligations.';
$string['govtcontribution_help'] = 'Enter any government contribution amount in dollars. This is the subsidy paid by government for subsidised training. Enter 0 for full-fee or self-funded students.';
$string['deliverylocation'] = 'Delivery Location';
$string['deliverylocationid'] = 'Delivery Location';
$string['location_header'] = 'Delivery Location';
$string['location_header_help'] = 'Track where training is delivered. This must match your registered delivery locations and is required for AVETMISS NAT120.';
$string['deliverylocationid_help'] = 'Select the delivery location from your registered locations. This is where training primarily occurs. Locations must be registered with ASQA for face-to-face delivery.';
$string['trainingcontractid'] = 'Training Contract ID';
$string['trainingcontractid_help'] = 'Enter the training contract ID for apprentices/trainees. This links the enrolment to the Australian Apprenticeship Support Network (AASN) contract. Leave blank for non-apprentices.';
$string['none'] = 'None';
$string['assessor'] = 'Assessor';
$string['assessoruserid'] = 'Assessor';
$string['assessoruserid_help'] = 'Select the trainer/assessor responsible for this student\'s assessment. This links to your trainer register for credential tracking and audit purposes.';
$string['purchasedfrom'] = 'Purchased From';
$string['purchasedfrom_help'] = 'If training was purchased from another RTO under a purchasing contract, enter their RTO code here. This is required for AVETMISS. Leave blank for training delivered by your RTO.';
$string['vetoptions_header'] = 'VET Options';
$string['vetoptions_header_help'] = 'Additional AVETMISS flags for specific student categories. These affect data validation and reporting requirements.';
$string['vetflag_help'] = 'VET Flag indicator: Y = Yes, this is a VET program; N = No, not VET (e.g., recreational course). Most enrolments should be Y for RTOs delivering nationally recognised training.';
$string['vetinschoolsflag_help'] = 'VET in Schools flag: Y = Student is a school student undertaking VET; N = Student is not a school student. This affects enrolment and completion counting rules.';
$string['commencingprogramid_help'] = 'Commencing program identifier: 3 = Commencing, 4 = Continuing. Code 3 for first enrolment in this program, code 4 for subsequent activities in the same program.';
$string['enrolmentstatus_header'] = 'Enrolment Status';
$string['enrolmentstatus_header_help'] = 'Track the current status of this enrolment. Status affects AVETMISS reporting and student management.';
$string['enrolment_status'] = 'Enrolment Status';
$string['enrolment_status_help'] = 'ACTIVE: currently studying. COMPLETED: successfully finished (check outcome code). WITHDRAWN: student has withdrawn from activity. ON HOLD: temporarily paused with intent to return.';
$string['holduntil_help'] = 'If status is On Hold, select the date the hold is expected to end. Monitor holds to ensure students return to study or are withdrawn if they do not return.';
$string['holdreason_help'] = 'Document the reason for the hold: medical, personal circumstances, work commitments, etc. This provides audit trail and helps with student follow-up.';

// COMPLAINT FORM ADDITIONS
$string['assigned_to'] = 'Assigned To';
$string['complaint_assignedto'] = 'Assigned To';
$string['complaint_assignedto_help'] = 'Select the staff member responsible for investigating and resolving this complaint. Only managers and editing teachers are shown. Assignment ensures accountability and timely resolution.';

// TAS EXPORT
$string['tas'] = 'Training & Assessment Strategy';
$string['tas_export'] = 'Export TAS Document';

// Student self-service profile
$string['myavetmissprofile'] = 'My Training Profile';
$string['incomplete'] = 'Incomplete';
$string['noavetmissrequired'] = 'You are not enrolled in any nationally recognised training courses. No AVETMISS profile is required.';
$string['profilesaved'] = 'Your training profile has been saved successfully.';
$string['enrolledin_accredited'] = 'You are enrolled in nationally recognised training';
$string['avetmiss_required_explanation'] = 'Australian government reporting requires us to collect some demographic information. Please complete your training profile below.';
$string['profile_incomplete_warning'] = 'Your training profile is incomplete. Please complete all required fields to ensure your qualifications can be issued and reported correctly.';
$string['rtocompliance:editownprofile'] = 'Edit own AVETMISS profile';

// Certificate notifications
$string['certificate_notification_subject'] = 'Your {$a} has been issued';
$string['certificate_notification_message'] = '<p>Dear {$a->firstname},</p>
<p>Congratulations! Your <strong>{$a->certtype}</strong> has been issued.</p>
<p><strong>Certificate Number:</strong> {$a->certnumber}</p>
{$a->qualification}
<p>You can download your certificate from your profile:</p>
<p><a href="{$a->downloadlink}">View My Certificates</a></p>
<p>Kind regards,<br>{$a->rtoname}</p>';

// Survey automation
$string['task_send_completion_survey'] = 'Send learner surveys to course completers';
$string['default_survey_subject'] = 'We value your feedback - Learner Survey';
$string['autosurvey_message'] = '<p>Dear {$a->firstname},</p>
<p>Congratulations on completing <strong>{$a->coursename}</strong>!</p>
<p>We would love to hear about your training experience. Your feedback helps us improve our services.</p>
<p><a href="{$a->surveylink}">Click here to complete a short survey</a></p>
<p>The survey takes approximately 5 minutes to complete and is confidential.</p>
<p>Thank you for your time.</p>';

// Message providers
$string['messageprovider:certificate_issued'] = 'Certificate issued notification';
$string['messageprovider:survey_invitation'] = 'Quality Indicator survey invitation';
$string['messageprovider:profile_reminder'] = 'AVETMISS profile completion reminder';

// Settings for auto-survey
$string['autosurvey_settings'] = 'Automatic Survey Settings';
$string['autosurvey_enabled'] = 'Enable automatic learner surveys';
$string['autosurvey_enabled_desc'] = 'Automatically send learner surveys to students who complete nationally recognised courses';
$string['autosurvey_delay_days'] = 'Days after completion';
$string['autosurvey_delay_days_desc'] = 'Number of days after course completion before sending the survey (default: 7)';
$string['autosurvey_subject'] = 'Survey email subject';
$string['autosurvey_subject_desc'] = 'Subject line for automatic survey emails';

// Trainer Form Help Buttons (2025 RTO Standards - Standards 3.2, 3.3 & Credential Policy)
$string['trainers'] = 'Trainers & Assessors';
$string['trainers_help'] = 'The trainer register documents compliance with the 2025 RTO Standards (effective 1 July 2025). Standard 3.2 (VET Workforce) requires trainers/assessors to have appropriate credentials per the Credential Policy. Standard 3.3 requires vocational competency, industry currency, and ongoing CPD.';

$string['taecredential_help_long'] = 'Select the TAE qualification held by this trainer. Under the 2025 Credential Policy: Section 1A (training and assessment without direction) requires TAE40122, TAE40116, or TAE40110. Note: TAE40110 is now accepted without additional LLN/assessment design units. Sections 1C/1D (working under direction) accept skill sets like TAESS00021 or TAESS00024, but persons cannot make assessment judgements.';
$string['taecredential_help_long_help'] = 'Select the TAE qualification held by this trainer. Under the 2025 Credential Policy: Section 1A (training and assessment without direction) requires TAE40122, TAE40116, or TAE40110. Note: TAE40110 is now accepted without additional LLN/assessment design units. Sections 1C/1D (working under direction) accept skill sets like TAESS00021 or TAESS00024, but persons cannot make assessment judgements.';

$string['taedateachieved'] = 'Date TAE Achieved';
$string['taedateachieved_help'] = 'The date when this trainer achieved their TAE credential. Under the 2025 Standards, TAE40110 holders no longer need additional units. This date helps track credential currency and CPD planning.';

$string['credential_role'] = 'Trainer/Assessor Role Categories';
$string['credential_role_help'] = 'Select the role category based on the 2025 Credential Policy: Section 1A/1B - Training and/or assessment WITHOUT direction (TAE40122/40116/40110 or Diploma VET). Section 1C/1D - Training UNDER direction (skill sets, cannot make assessment judgements). Section 2A-2C - TAE delivery (requires Diploma level). Section 3A/3B - Validation roles.';

$string['vocationalqualifications_help_long'] = 'List vocational qualifications (not TAE qualifications) demonstrating expertise in the industry area delivered. Under Standard 3.3, trainers must hold vocational competencies at least to the level being delivered, evidenced through qualifications OR equivalence of competency (broad knowledge, skill, and experience).';
$string['vocationalqualifications_help_long_help'] = 'List vocational qualifications (not TAE qualifications) demonstrating expertise in the industry area delivered. Under Standard 3.3, trainers must hold vocational competencies at least to the level being delivered, evidenced through qualifications OR equivalence of competency (broad knowledge, skill, and experience).';

$string['vocationalcompetency_evidence'] = 'Vocational Competency Evidence';
$string['vocationalcompetency_evidence_help'] = 'Select all evidence types documenting vocational competency. Under Standard 3.3, trainers must demonstrate competencies at least to the level being delivered. The 2025 Standards accept flexible evidence including: holding units of competency OR equivalence demonstrated through broad knowledge, skill, and experience. Multiple evidence types strengthen audit documentation.';

$string['industry_currency_type'] = 'Industry Currency Type';
$string['industry_currency_type_help'] = 'Select how this trainer maintains industry currency. Standard 3.3 requires trainers to maintain current industry skills through: ongoing industry engagement, professional development, workshops, conferences, higher qualifications, or accessing VET publications. RTOs must have systems to ensure industry currency through regular industry engagement.';

$string['industrycurrency_help_long'] = 'Provide details of how this trainer maintains current industry skills under Standard 3.3. Document: employer/organisation names, roles, dates, and specific activities demonstrating ongoing engagement with current industry practices. RTOs must have systems to monitor industry currency.';
$string['industrycurrency_help_long_help'] = 'Provide details of how this trainer maintains current industry skills under Standard 3.3. Document: employer/organisation names, roles, dates, and specific activities demonstrating ongoing engagement with current industry practices. RTOs must have systems to monitor industry currency.';

$string['cpdhours_help_long'] = 'Record CPD hours for the current year. Standard 3.3 requires all trainers/assessors to undertake CPD in: (1) Training and assessment practice, and (2) Engaging and supporting VET students. Include both pedagogical and industry-specific CPD. Best practice: 30-50 hours annually.';
$string['cpdhours_help_long_help'] = 'Record CPD hours for the current year. Standard 3.3 requires all trainers/assessors to undertake CPD in: (1) Training and assessment practice, and (2) Engaging and supporting VET students. Include both pedagogical and industry-specific CPD. Best practice: 30-50 hours annually.';

$string['manager_signoff'] = 'Manager Sign-Off';
$string['manager_signoff_help'] = 'By checking this box, you confirm credentials have been authenticated per Standard 3.2. RTOs must verify trainer/assessor qualifications, monitor performance, identify PD opportunities, and ensure industry currency. Original documents (TAE certificates, vocational qualifications, industry currency evidence) must be sighted.';

$string['resumefile'] = 'Resume/CV Upload';
$string['resumefile_help'] = 'Upload the trainer\'s resume or CV that documents their industry work history (not just teaching experience). This should demonstrate vocational competency and industry experience in the areas they train and assess.';

// Qualification Builder
$string['qualbuilder'] = 'Qualification Builder';
$string['qualbuilder_desc'] = 'Build and manage qualifications, skill sets, and single unit courses with packaging rules validation and auto-certificate issuance.';
$string['student_results'] = 'Student Results';
$string['student_results_desc'] = 'View students enrolled in each qualification, skill set, or unit with their competency outcomes and completion progress.';
$string['view_results'] = 'View Results';
$string['total_enrolled'] = 'Total Enrolled';
$string['completed_students'] = 'Completed';
$string['inprogress_students'] = 'In Progress';
$string['add_product'] = 'Add Training Product';
$string['edit_product'] = 'Edit Training Product';
$string['product_details'] = 'Product Details';
$string['product_type'] = 'Product Type';
$string['product_type_help'] = 'Single Unit: One unit of competency with Statement of Attainment. Skill Set: Multiple units with Statement of Attainment. Qualification: Full qualification with Testamur and Record of Results.';
$string['product_type_qualification'] = 'Full Qualification';
$string['product_type_skillset'] = 'Skill Set';
$string['product_type_singleunit'] = 'Single Unit Course';
$string['qualification_code'] = 'Code';
$string['qualification_code_help'] = 'The training.gov.au code (e.g. BSB50420 for Diploma of Leadership and Management, or BSBWHS411 for a single unit).';
$string['qualification_name'] = 'Name';
$string['aqf_level'] = 'AQF Level';
$string['select_aqf'] = 'Select AQF level...';
$string['moodle_category'] = 'Moodle Category';
$string['moodle_category_help'] = 'The Moodle course category containing the courses for this qualification. Used for auto-detecting course links.';
$string['select_category'] = 'Select category...';
$string['nominal_hours'] = 'Nominal Hours';
$string['total_units_required'] = 'Total Units Required';
$string['core_units_required'] = 'Core Units Required';
$string['elective_count'] = 'Elective Units Required';
$string['elective_rules_json'] = 'Elective Rules (JSON)';
$string['elective_rules_placeholder'] = '{"minFromList": 4, "maxImported": 2, "requiredGroups": {"A": {"min": 2}}}';
$string['elective_rules'] = 'Elective Rules';
$string['elective_rules_help'] = 'JSON configuration for elective selection rules. minFromList: minimum electives from the approved list. maxImported: maximum units imported from other qualifications. requiredGroups: minimum from each elective group.';

// Qualification Builder Status
$string['draft'] = 'Draft';
$string['active'] = 'Active';
$string['superseded'] = 'Superseded';
$string['superseded_by'] = 'Superseded By';
$string['teachout_date'] = 'Teach-Out Date';

// Units
$string['units'] = 'Units';
$string['units_of_competency'] = 'Units of Competency';
$string['add_unit'] = 'Add Unit';
$string['edit_unit'] = 'Edit Unit';
$string['unit_details'] = 'Unit Details';
$string['unit_code'] = 'Unit Code';
$string['unit_code_help'] = 'The training.gov.au unit code (e.g. BSBCMM511).';
$string['unit_name'] = 'Unit Name';
$string['unit_type'] = 'Unit Type';
$string['unit_type_core'] = 'Core';
$string['unit_type_elective'] = 'Elective';
$string['unit_type_imported'] = 'Imported';
$string['elective_group'] = 'Elective Group';
$string['elective_group_help'] = 'Assign electives to groups (A, B, C, D) to enforce group-based selection rules. Leave empty if no grouping is required.';
$string['credit_points'] = 'Credit Points';
$string['credit_points_help'] = 'The credit point value for this unit (used by points-based qualifications such as MEM and UEE). Enter 0 if this qualification uses unit counts rather than credit points.';
$string['sequence_order'] = 'Sequence Order';
$string['core_units'] = 'Core Units';
$string['elective_units'] = 'Elective Units';
$string['imported_units'] = 'Imported Units';
$string['no_units_added'] = 'No units have been added to this training product yet.';
$string['unit_added'] = 'Unit added successfully.';
$string['unit_updated'] = 'Unit updated successfully.';
$string['unit_deleted'] = 'Unit deleted successfully.';
$string['confirm_delete_unit'] = 'Are you sure you want to delete the unit "{$a}"?';

// Course Linking
$string['course_linking'] = 'Course Linking';
$string['link_courses'] = 'Link Moodle Courses';
$string['link_courses_desc'] = 'In Moodle, each qualification is a course category and each unit of competency is a Moodle course within that category. Use this page to link each unit row to its matching Moodle course. Click Auto-Detect to match automatically by unit code.';
$string['link_courses_category_label'] = 'Filtered to category';
$string['link_courses_category_hint'] = 'Only courses in this category are shown in the dropdowns below. Select the matching course for each unit.';
$string['link_courses_no_category_title'] = 'No Moodle category linked';
$string['link_courses_no_category_hint'] = 'All site courses are shown (up to 500). For a shorter list, set the Moodle Category on the product settings page.';
$string['link_courses_go_to_settings'] = 'Go to product settings';
$string['autodetect_courses_tip'] = 'Automatically matches each unit to a Moodle course by looking for the unit code in the course shortname or fullname.';
$string['linked_course'] = 'Linked Course';
$string['linked_course_help'] = 'Select the Moodle course that delivers this unit of competency. When students complete this course, their unit result will be updated automatically.';
$string['linked_courses'] = 'Linked Courses';
$string['linked_status'] = 'Linking Status';
$string['units_linked'] = 'units linked';
$string['not_linked'] = 'Not linked';
$string['select_course'] = 'Select course...';
$string['autodetect_courses'] = 'Auto-Detect Courses';
$string['autodetect_complete'] = 'Auto-detection complete. {$a} courses linked.';
$string['save_links'] = 'Save Links';
$string['links_saved'] = 'Course links saved. {$a} links updated.';
$string['back_to_product'] = 'Back to Product';

// Packaging Rules Validation
$string['packaging_rules'] = 'Packaging Rules';
$string['check_packaging'] = 'Check Packaging Rules';
$string['packaging_validation_results'] = 'Packaging Rules Validation Results';
$string['packaging_compliant'] = 'PACKAGING RULES: COMPLIANT';
$string['packaging_noncompliant'] = 'PACKAGING RULES: NOT COMPLIANT';
$string['packaging_validated'] = 'Packaging rules validated';
$string['validation_checks'] = 'Validation Checks';
$string['validation_errors'] = 'Validation Errors';
$string['check'] = 'Check';
$string['expected'] = 'Expected';
$string['actual'] = 'Actual';
$string['warnings'] = 'Warnings';

// Validation check names
$string['check_total_units'] = 'Total Units';
$string['check_core_units'] = 'Core Units';
$string['check_elective_units'] = 'Elective Units';
$string['check_electives_from_list'] = 'Electives from Approved List';
$string['check_imported_limit'] = 'Imported Units Limit';
$string['check_courses_linked'] = 'Moodle Courses Linked';
$string['check_no_duplicates'] = 'No Duplicate Units';
$string['no_duplicates'] = 'No duplicates';
$string['duplicates_found'] = 'duplicates found';

// Validation error messages
$string['error_total_units'] = 'Total units mismatch: expected {$a->expected}, found {$a->actual}';
$string['error_core_units'] = 'Core units mismatch: expected {$a->expected}, found {$a->actual}';
$string['error_elective_units'] = 'Elective units mismatch: expected {$a->expected}, found {$a->actual}';
$string['error_electives_from_list'] = 'Minimum {$a->minimum} electives from approved list required, found {$a->actual}';
$string['error_imported_limit'] = 'Maximum {$a->maximum} imported units allowed, found {$a->actual}';
$string['error_duplicate_units'] = 'Duplicate unit codes found: {$a}';
$string['error_group_minimum'] = 'Group {$a->group} requires minimum {$a->minimum} units, found {$a->actual}';
$string['error_group_maximum'] = 'Group {$a->group} allows maximum {$a->maximum} units, found {$a->actual}';
$string['warning_unlinked_units'] = '{$a->count} units are not linked to Moodle courses: {$a->units}';
$string['units_must_match'] = 'Core units + Elective units must equal Total units';
$string['error_totalunits_min'] = 'Total units required must be at least 1';
$string['error_units_negative'] = 'Unit count cannot be negative';
$string['error_units_exceed_total'] = 'Core units + Elective units cannot exceed Total units required';
$string['last_validation_result'] = 'Last Packaging Validation Result';
$string['validation_not_passed_hint'] = 'Add all required units then click \'Check Packaging Rules\' to re-validate.';

// Product list
$string['no_products'] = 'No training products have been created yet.';
$string['add_first_product'] = 'Add your first training product';
$string['product_created'] = 'Training product created successfully.';
$string['product_updated'] = 'Training product updated successfully.';
$string['product_deleted'] = 'Training product deleted successfully.';
$string['confirm_delete_product'] = 'Are you sure you want to delete the training product "{$a}"? This will also delete all associated units.';

// Certificate Credits
$string['certificate_credits'] = 'Certificate Credit Costs';
$string['soa_credits'] = 'Statement of Attainment - 5 credits';
$string['qual_credits'] = 'Testamur + Record of Results - 10 credits';

// TGA Integration
$string['import_from_tga'] = 'Import from TGA';
$string['import_units_from_tga'] = 'Import Units from Training.gov.au';
$string['tga_import_help'] = 'Enter the qualification code to automatically fetch core and elective units from Training.gov.au. Core units are selected by default. Choose the elective units you want to include in your training product.';
$string['fetch_units'] = 'Fetch Units';
$string['tga_search_qualification'] = 'Search Qualification';
$string['tga_search_unit'] = 'Search Unit';
$string['tga_lookup_error'] = 'Error fetching data from Training.gov.au: {$a}';
$string['tga_no_units_found'] = 'No units found for this qualification.';
$string['units_imported'] = '{$a} units imported successfully.';
$string['import_selected_units'] = 'Import Selected Units';
$string['select_units_to_import'] = 'Select which units to import into your training product.';
$string['core_units_auto_selected'] = 'Core units are automatically selected as they are mandatory.';
$string['elective_units_selection'] = 'Select the elective units your RTO will deliver for this qualification.';
$string['invalidqualification'] = 'Please select a qualification from the list to view student results.';
$string['qualificationnotfound'] = 'The requested qualification could not be found. It may have been deleted.';

// Trainer Currency Activities
$string['currency_title'] = 'Activity Title';
$string['currency_title_help'] = 'Enter a descriptive title for this industry currency activity. For employment, this could be your job title. For consulting work, describe the project or engagement. For professional memberships, enter the association name. Be specific so auditors can understand the nature of your industry connection.';

// Vocational Competency form help strings (trainer_voccomp.php)
$string['voccomp_qualification'] = 'Related Qualification(s)';
$string['voccomp_qualification_help'] = 'Enter the training.gov.au qualification code(s) that this vocational competency activity relates to. For example: BSB40120, BSB50120. This links the activity to the qualifications the trainer delivers and helps demonstrate vocational competency for ASQA audit purposes (Standards 2025, Standard 3.2).';

// Test Data Generator
$string['testdata'] = 'Test Data Generator';
$string['testdata_desc'] = 'Generate realistic Australian test users for development and demonstration purposes. Test students are populated with full AVETMISS profile data. Test teachers are created with complete trainer credential records including TAE credentials, WWCC, and police check data.';
$string['testdata_students_label'] = 'Test Students';
$string['testdata_teachers_label'] = 'Test Teachers';
$string['testdata_status_in_moodle'] = 'currently in Moodle';
$string['testdata_create_students'] = 'Generate 50 Test Students';
$string['testdata_create_students_desc'] = 'Creates 50 Moodle user accounts (teststudent001–teststudent050) with full Australian demographic profiles including AVETMISS data, addresses, and contact details registered in the Student Management System.';
$string['testdata_create_teachers'] = 'Generate 10 Test Teachers';
$string['testdata_create_teachers_desc'] = 'Creates 10 Moodle user accounts (testteacher01–testteacher10) with complete trainer compliance records including TAE credentials, vocational qualifications, WWCC, police checks, and scope mapping.';
$string['testdata_btn_students'] = 'Generate 50 Students';
$string['testdata_btn_teachers'] = 'Generate 10 Teachers';
$string['testdata_btn_cleanup'] = 'Remove All Test Users';
$string['testdata_students_created'] = 'Done — {$a->created} student(s) created, {$a->skipped} already existed.';
$string['testdata_teachers_created'] = 'Done — {$a->created} teacher(s) created, {$a->skipped} already existed.';
$string['testdata_cleanup_done'] = 'Cleanup complete — {$a->deleted} test user(s) removed from Moodle.';
$string['testdata_credentials_heading'] = 'Login Credentials';
$string['testdata_cred_students'] = 'Student usernames: teststudent001 through teststudent050';
$string['testdata_cred_teachers'] = 'Teacher usernames: testteacher01 through testteacher10';
$string['testdata_cred_password'] = 'Password for all test users: Testdata2026!';
$string['testdata_danger_heading'] = 'Remove Test Data';
$string['testdata_danger_desc'] = 'This permanently deletes all test students (teststudent001–050) and test teachers (testteacher01–10) from Moodle, along with their RTO compliance records.';
$string['testdata_cleanup_confirm'] = 'This will permanently delete all test users and their RTO records. Are you sure?';

// Moodle enrolment import (student_enrolments.php)
$string['moodle_enrolments_found'] = 'Moodle course enrolments detected — not yet in AVETMISS records';
$string['moodle_enrolments_found_desc'] = 'This student is enrolled in the following Moodle courses, but no AVETMISS training activity records have been created for them yet. Click the button below to import these enrolments as skeleton AVETMISS records. You can then edit each record to add unit codes, outcomes, delivery modes, and funding information.';
$string['import_moodle_enrolments_btn'] = 'Import {$a->count} Moodle Enrolment(s) as AVETMISS Records';
$string['import_moodle_enrolments_hint'] = 'Imported records will use sensible defaults (Outcome: Continuing/Not started, Delivery: Classroom, Funding: Domestic fee for service). Edit each record after import to set the correct AVETMISS data.';
$string['enrolments_imported'] = '{$a->imported} enrolment(s) imported from Moodle. {$a->skipped} skipped (already had RTO records). Edit each record to complete the AVETMISS data.';
$string['trainers_imported'] = '{$a->imported} Moodle teacher(s) imported as RTO trainer profile(s). Edit each profile to add TAE credentials, industry currency, WWCC, and other compliance data.';
$string['no_enrolments_anywhere'] = 'No AVETMISS training records and no Moodle course enrolments found for this student. Enrol the student in a Moodle course first, then return here to import their enrolments, or click "Add Enrolment" to manually create an AVETMISS record.';
$string['no_enrolments_use_import'] = 'No AVETMISS records yet. Use the "Import Moodle Enrolments" button above to create records from this student\'s existing Moodle course enrolments.';

$string['maintenancesettings'] = 'Maintenance';
$string['log_retentiondays'] = 'Audit log retention (days)';
$string['log_retentiondays_desc'] = 'Audit log entries older than this many days are automatically deleted by the nightly cleanup task. Default is 730 days (2 years). ASQA may require RTOs to retain compliance records for a minimum period — verify your legal obligations before reducing this value. Set to 0 to disable automatic pruning.';


$string['unknown'] = 'Unknown';

// Platform Webhook
$string['usi_settings'] = 'USI Verification Settings';
$string['usi_settings_desc'] = 'Configure credentials for the Australian Unique Student Identifier (USI) Registry. The machine credential (.p12 file) must be obtained from the ATO software authorisations portal via myGovID.';
$string['webhookapikey'] = 'Platform Webhook Key';
$string['webhookapikey_desc'] = '<strong>Leave this blank.</strong> All features work without it. If AI Grader support provides you with a key, enter it here — it allows our team to push settings to your site remotely so you do not have to enter them yourself.';

// Testing Engine
$string['testing'] = 'Testing Engine';
$string['testing_desc'] = 'QA testing panel to systematically test, validate, and approve every feature of the RTO Compliance plugin before production release. Run automated checks against live Moodle data, record test history, and mark features as approved.';

// Student Suitability Check System
$string['suitability_col'] = 'Suitability';
$string['suitability_already_suitable'] = 'This student has already been assessed as <strong>Suitable</strong> for this qualification. Resending the Student Suitability Check would erase their existing assessment. To view or override, use the View button in their history above.';
$string['suitability_no_answers'] = 'This Student Suitability Check has no questions attached. Please contact your training provider for assistance.';
$string['suitability_student_not_found'] = 'The student account for this Student Suitability Check could not be found. The link may have expired or the student account may have been removed. Please contact your training provider.';
$string['invalidtoken'] = 'This declaration link is invalid or has already been used. Please contact your training provider if you need a new link sent to you.';
$string['suitability_tas_not_found'] = 'The qualification associated with this Student Suitability Check could not be found. Please contact your training provider.';
$string['suitability_send_title'] = 'Send Student Suitability Check';
$string['suitability_view_title'] = 'View Student Suitability Check';
$string['suitability_send_btn_short'] = 'Send Suitability Check';
$string['suitability_send_btn'] = 'Send Student Suitability Check';
$string['suitability_send_new'] = 'Send a New Student Suitability Check';
$string['suitability_select_tas'] = 'Select Training and Assessment Strategy (qualification)';
$string['suitability_preview_label'] = 'Questions to be sent';
$string['suitability_existing'] = 'Previously Sent Suitability Checks';
$string['suitability_resend'] = 'Resend Email';
$string['suitability_email_sent'] = 'Student Suitability Check sent to {$a}.';
$string['suitability_email_resent'] = 'Student Suitability Check email resent to {$a}.';
$string['suitability_no_questions'] = 'No entry requirements found in the selected TAS. Please add entry requirements to the TAS before sending a Student Suitability Check.';
$string['suitability_no_tas'] = 'No approved TAS records found with entry requirements. Please create and approve a TAS with entry requirements before sending a Student Suitability Check.';
$string['suitability_status_pending'] = 'Awaiting';
$string['suitability_status_suitable'] = 'Suitable';
$string['suitability_status_not_suitable'] = 'Not Suitable';
$string['suitability_status_override'] = 'Override: Suitable';
$string['suitability_answers'] = 'Checklist Responses';
$string['suitability_not_yet_answered'] = 'The student has not yet submitted their Student Suitability Check.';
$string['suitability_view_override'] = 'View / Override';
$string['suitability_override_heading'] = 'Override Suitability Decision';
$string['suitability_override_desc'] = 'After speaking with the student, you may override this result and mark them as suitable for enrolment. You must provide notes explaining your reasoning.';
$string['suitability_override_notes'] = 'Reason for Override';
$string['suitability_override_placeholder'] = 'Explain why this student is being deemed suitable despite not meeting all entry requirements (e.g. equivalent experience, RPL, special circumstances)…';
$string['suitability_override_btn'] = 'Mark as Suitable (Override)';
$string['suitability_override_confirm'] = 'Are you sure you want to override the suitability decision and mark this student as suitable for enrolment?';
$string['suitability_override_notes_required'] = 'You must provide a reason for the override.';
$string['suitability_overridden_ok'] = 'Suitability has been overridden. The student is now marked as suitable.';

// v3.8.49 — Bulk suitability sending
$string['suitability_bulk_heading']       = 'Bulk Student Suitability Check';
$string['suitability_bulk_select_tas']    = '— Select a qualification —';
$string['suitability_bulk_send_selected'] = 'Send to Selected';
$string['suitability_bulk_none_selected'] = 'No students were selected. Please select at least one student using the checkboxes.';
$string['suitability_bulk_result']        = 'Student Suitability Check sent to {$a->sent} student(s). {$a->skipped} skipped (already suitable or invalid email).';
$string['suitability_selectall']          = 'Select all students';
$string['suitability_cb_already_suitable'] = 'This student is already marked as suitable and cannot be re-selected.';
$string['suitability_fill_gaps_title']    = 'Fill Compliance Gaps';
$string['suitability_fill_gaps_btn_short'] = 'Fill Compliance Gaps';
$string['suitability_fill_gaps_desc']     = 'This action sends the Student Suitability Check to every student who has not yet received one for the selected qualification. Students already marked as Suitable or Override: Suitable are skipped automatically.';
$string['suitability_fill_gaps_btn']      = 'Send to All Uncontacted Students';
$string['suitability_fill_gaps_confirm']  = 'This will send the Student Suitability Check to all students who have not yet received one for this qualification. Continue?';

// v3.8.50 — AVETMISS Data Import
$string['dataimport']                      = 'AVETMISS Data Import';
$string['dataimport_title']                = 'AVETMISS NAT File Import';
$string['dataimport_desc']                 = 'Import AVETMISS student data from your Student Management System (SMS) NAT file exports. Supports Wisenet and other compliant SMS providers.';
$string['dataimport_upload_heading']       = 'Upload NAT Files';
$string['dataimport_upload_desc']          = 'Select all NAT files from your SMS export and upload them together. <strong>NAT00080 is required</strong> — it contains student demographics and USI codes. NAT00085 adds email addresses (needed for email-based matching). Other supported files: NAT00010, NAT00120, NAT00130.';
$string['dataimport_history_heading']      = 'Import History';
$string['dataimport_no_imports']           = 'No imports yet. Upload NAT files above to get started.';
$string['dataimport_delete']               = 'Delete import';
$string['dataimport_deleted']              = 'Import and all associated data have been deleted.';
$string['dataimport_success']              = 'Import complete — {$a} year group(s) processed.';
// FIX-SESSION-EXPIRED-I18N (v4.9.136): was a hardcoded English string.
$string['dataimport_session_expired']      = 'Upload session expired — please re-upload the files.';
// FIX-AUTOENROL-GUARD-I18N (v4.9.137): were hardcoded English strings.
$string['autoenrol_fail_noplugin']         = 'Auto-enrolment cannot run: the manual enrolment plugin is disabled on this site. Please enable it at Site Administration → Plugins → Enrolments → Manage enrol plugins.';
$string['autoenrol_fail_norole']           = 'Auto-enrolment cannot run: no student role was found on this site. Please ensure a role with the student archetype exists at Site Administration → Users → Permissions → Define roles.';
// ADD-COMPLETIONS-SEARCH (v4.9.138): completions tab now has search.
$string['dataimport_search_completions']   = 'Search by student name, ID or qualification code…';
$string['dataimport_no_data']              = 'No parseable AVETMISS data found. Please upload NAT00080, NAT00120 or NAT00130 files.';
$string['dataimport_students']             = 'Students';
$string['dataimport_enrolments']           = 'Enrolments';
$string['dataimport_completions']          = 'Completions';
$string['dataimport_flagged']              = 'Flagged';
$string['dataimport_back']                 = 'All imports';
$string['dataimport_search_students']      = 'Search by name, client ID or email…';
$string['dataimport_search_enrolments']    = 'Search by student name, client ID, unit or qualification…';
$string['dataimport_rto']                  = 'RTO';
$string['dataimport_collection_year']      = 'Collection Year';
$string['dataimport_imported_at']          = 'Imported';
$string['dataimport_clientid']             = 'Client ID';
$string['dataimport_name']                 = 'Name';
$string['dataimport_dob']                  = 'DOB';
$string['dataimport_usi']                  = 'USI';
$string['dataimport_email']                = 'Email';
$string['dataimport_flags']                = 'Flags';
$string['dataimport_unit']                 = 'Unit';
$string['dataimport_qual']                 = 'Qualification';
$string['dataimport_start']                = 'Start';
$string['dataimport_end']                  = 'End';
$string['dataimport_outcome']              = 'Outcome';
$string['dataimport_funding']              = 'Funding';
$string['dataimport_completed']            = 'Completed';
$string['dataimport_cert_date']            = 'Cert Date';
$string['dataimport_parchment']            = 'Parchment #';
$string['dataimport_data_issue']           = 'data issue';
$string['dataimport_confirm_delete']       = 'Are you sure you want to delete this import and all associated student, enrolment and completion data?';

// v4.9.117 — Auto-enrol wizard (NAT import step 3)
$string['autoenrol_title']        = 'Auto-Enrol Students into Moodle Courses';
$string['autoenrol_heading']      = 'Step 3 of 3 — Auto-Enrol into Moodle Courses (Optional)';
$string['autoenrol_desc']         = 'Your AVETMISS data has been imported. The qualification codes below come from the NAT00120 enrolment records. Select a Moodle course to enrol each group of students into, or leave it as "Skip" if you don\'t want to auto-enrol that group. Students already enrolled in the selected course are skipped automatically.';
$string['autoenrol_qual']         = 'Qualification Code';
$string['autoenrol_studentcount'] = 'Students in NAT file';
$string['autoenrol_course']       = 'Map to Moodle course';
$string['autoenrol_skip_qual']    = '— Skip this qualification —';
$string['autoenrol_skip_all']     = 'Skip — go straight to import results';
$string['autoenrol_confirm']      = 'Enrol Students &amp; Finish';
$string['autoenrol_noquals']      = 'No qualification codes were found in this import\'s enrolment records. Nothing to auto-enrol.';
$string['autoenrol_done']         = 'Auto-enrolment complete — {$a} student(s) enrolled into Moodle course(s).';
$string['autoenrol_skipped']        = '{$a} student record(s) skipped — no active Moodle account found matching the email address.';
// FIX-AUTOENROL-SKIP-MSG (v4.9.135): split the previously aggregated "no Moodle account" message
// into three separate messages so admins get actionable diagnostics for each failure mode.
$string['autoenrol_skipnostudent']  = '{$a} student record(s) skipped — no NAT00085 student demographics row found for this client ID. Was the NAT00085 file included in your upload?';
$string['autoenrol_skipnoemail']    = '{$a} student record(s) skipped — the NAT00085 student record has no email address stored.';
$string['autoenrol_already']      = '{$a} student(s) were already enrolled.';
$string['autoenrol_suggestedmatch'] = 'Suggested match — qual code found in course name';

// v3.8.49 — Auto-send on enrolment settings
$string['autosend_suitability_heading']      = 'Student Suitability Check — Auto-Send';
$string['autosend_suitability_heading_desc'] = 'When enabled, the Student Suitability Check is automatically emailed to each student at the moment they are enrolled in a nationally recognised course, so no manual action is required.';
$string['autosend_suitability']              = 'Auto-send on enrolment';
$string['autosend_suitability_desc']         = 'Tick to automatically send the Student Suitability Check whenever a student is enrolled. The check used is the one configured below.';
$string['autosend_suitability_tasid']        = 'Qualification for auto-send';
$string['autosend_suitability_tasid_desc']   = 'Select the qualification (TAS) whose entry requirements will be used for the automatically sent checklist. Only approved TAS records with entry requirements are listed.';
$string['autosend_suitability_tasid_none']   = '— Select a qualification —';

// v4.0.62 — Missing trainer help strings (fix coding_exception on trainer edit page)
$string['industryexperienceyears']      = 'Industry Experience (Years)';
$string['industryexperienceyears_help'] = 'Number of years of industry experience in the vocational area this trainer delivers and assesses. Under Standard 3.3, trainers must maintain industry currency to ensure their skills and knowledge remain relevant and current. Industry experience underpins vocational competency.';
$string['llncapability']                = 'LLN Capability';
$string['llncapability_help']           = 'Language, Literacy and Numeracy (LLN) capability level of this trainer. Trainers should have the LLN skills necessary to support their students. Options: Foundation, Level 1, Level 2, Level 3+, or equivalent vocational LLN as required by the training products delivered.';
$string['vetcurrencydate']              = 'VET Currency Date';
$string['vetcurrencydate_help']         = 'Date when this trainer most recently delivered training or conducted assessment in a VET context. ASQA requires ongoing VET currency — evidenced by records of training delivery, assessment activity, and professional development within the last 5 years. Review annually to ensure continued compliance.';
$string['vetcurrencyyears']             = 'VET Currency (Years)';
$string['vetcurrencyyears_help']        = 'Number of years this trainer has been actively teaching and/or assessing in the VET sector. Under Standard 3.3, trainers and assessors must maintain currency in training and assessment practice through ongoing participation in the VET sector.';

// v4.1.7 — Help strings for Appeal, Improvement and Validator forms (previously missing)

// Appeal form help strings
$string['appeal_reference_help']       = 'A unique reference number for this appeal record (e.g. APP-2026-001). Use a consistent format across all appeals to make them easy to search and cross-reference in your appeals register. Required under ASQA Standard QA2.8.';
$string['linked_complaint_help']       = 'If this appeal relates to an existing complaint, link it here to create an auditable paper trail. Linking also lets you track whether the original complaint was resolved to the student\'s satisfaction before the appeal was lodged.';
$string['appeal_type_help']            = 'Select the type of decision being appealed. ASQA Standard QA2.8 requires RTOs to have a documented process for appeals covering assessment decisions, enrolment decisions, RPL outcomes, and other decisions that affect student outcomes. Choose the category that most closely matches.';
$string['appellant_name_help']         = 'Full legal name of the person lodging the appeal. This should match the name on their student record or ID document. Required for natural justice — the appellant must be properly identified in all appeal correspondence.';
$string['appellant_email_help']        = 'Primary email address for all appeal correspondence. All notices, acknowledgements, and outcome letters must be sent to this address within your documented timeframes. ASQA Standard QA2.8 requires timely written communication at each stage.';
$string['appellant_phone_help']        = 'Contact phone number for the appellant. Useful for scheduling hearing dates and for urgent communication if emails are not responded to within the required timeframe.';
$string['grounds_for_appeal_help']     = 'A clear, specific statement of why the appellant believes the original decision was incorrect, unfair, or not made in accordance with your RTO\'s published procedures. Under natural justice principles (ASQA Standard QA2.8), the appellant must be given a genuine opportunity to present their case. Be detailed — vague grounds make it difficult to conduct a fair review.';
$string['original_decision_help']      = 'Describe the specific decision being appealed (e.g., "Assessment result of NYC for BSBWHS411 — assessed on 15 January 2026"). Documenting the original decision clearly ensures the appeal panel reviews the correct outcome and that the reasons for the decision are properly examined.';
$string['original_decision_date_help'] = 'The date on which the original decision was communicated to the appellant. Important for calculating whether the appeal was lodged within your published timeframe (commonly 20 or 30 working days from the decision). Your RTO policies must specify this timeframe and it must be consistently applied.';
$string['appeal_status']               = 'Status';
$string['appeal_status_help']          = 'Current stage of the appeal. Keep this updated as the appeal progresses: Received → Under Review → Hearing Scheduled → Decision Made → Closed. An accurate status ensures your compliance register is up to date and staff can identify outstanding appeals that need action.';
$string['date_lodged_help']            = 'The date the formal appeal was received by your RTO. This is the start date for all timeframe calculations. ASQA requires RTOs to acknowledge appeals promptly (typically within 5 working days) and complete the process within a documented maximum period (commonly 60 calendar days).';
$string['date_acknowledged_help']      = 'The date your RTO sent written acknowledgement of the appeal to the appellant. ASQA Standard QA2.8 requires RTOs to acknowledge receipt of all appeals in writing within a reasonable period. Record the date here to evidence compliance with this requirement.';
$string['hearing_date_help']           = 'The date of the formal appeal hearing or panel meeting. All parties must receive sufficient notice of the hearing date. Keep a record of who attended, as attendance by all relevant parties (appellant, assessor, independent panel member) is evidence of procedural fairness.';
$string['panel_members_help']          = 'List the name and role of each person on the appeal panel. ASQA requires that appeal panels are conducted by people who were not involved in the original decision. Including an independent member strengthens natural justice. Typical panels include: RTO Manager (chair), an independent industry expert, and an assessor not involved in the original decision.';
$string['appeal_outcome_help']         = 'The final decision of the appeal panel: Upheld (original decision overturned), Partially Upheld, or Dismissed (original decision stands). ASQA auditors will check that outcomes are documented, communicated in writing to the appellant, and that upheld appeals result in corrective action.';
$string['outcome_reason_help']         = 'A detailed written rationale for the appeal outcome. Explain: what evidence was reviewed, how each ground of appeal was assessed, why the panel reached its conclusion, and what actions will be taken as a result. This is the most important evidence document for ASQA compliance — a vague reason is a red flag. Use the AI Suggest button for a draft.';
$string['decision_date_help']          = 'The date the final appeal decision was communicated to the appellant in writing. Your RTO policies must specify a maximum time from lodgement to decision (e.g., 60 calendar days). Recording this date lets you calculate whether you met your own timeframe commitments.';
$string['external_review_offered_help'] = 'ASQA Standard QA2.8 requires that RTOs inform appellants of their right to seek an independent external review if they are not satisfied with the outcome. This external avenue may include ASQA, the relevant State Training Authority, the Ombudsman, or a court/tribunal. Tick this box when the written outcome letter advises the appellant of external review options.';
$string['external_review_taken_help']  = 'Tick if the appellant chose to pursue an external review with an outside body (e.g., ASQA, Ombudsman, court). Tracking this is important: a pattern of external reviews being escalated may indicate systemic issues with your complaints/appeals process that need addressing under continuous improvement (Standard QA4.4).';
$string['external_review_body_help']   = 'Name of the external body the appellant approached for independent review (e.g., "ASQA", "Victorian Ombudsman", "NCAT — NSW Civil and Administrative Tribunal"). This should be recorded for every escalated appeal for your continuous improvement register.';
$string['appeal_notes']                = 'Notes';
$string['appeal_notes_help']           = 'Internal notes for staff managing the appeal. Record anything not captured elsewhere: informal communications, requests for extensions, witness statements received, or reasons for delays. These notes form part of your confidential appeals register and may be reviewed during an ASQA audit.';

// Continuous Improvement form help strings
$string['improvement_reference_help']  = 'A unique reference code for this improvement action (e.g. CI-2026-003). Use alphanumeric characters only. A consistent reference format makes it easy to cross-reference improvement items with the complaints, audits, or validation events that triggered them. Required for your continuous improvement register under ASQA Standard QA4.4.';
$string['improvement_title_help']      = 'A brief, descriptive title for this improvement action (e.g., "Revise RPL evidence guide for Certificate III in Aged Care"). The title should clearly identify the issue being addressed so staff can quickly understand the scope of the action without reading the full description.';
$string['improvement_description_help'] = 'A clear description of the issue, gap, or opportunity that has been identified. Explain: what the problem is, where it was observed, how it was identified (e.g., student feedback, validation outcome, complaint), and what impact it has on student outcomes or regulatory compliance. Be specific — vague descriptions make it hard to verify that the action actually addressed the root cause.';
$string['source_type_help']            = 'Select the primary source that identified this improvement need. ASQA Standard QA4.4 requires RTOs to use feedback from multiple sources to drive continuous improvement. Common sources include: student/employer surveys, complaints or appeals, internal or external audits, ASQA feedback, validation outcomes, or staff observations. Tracking the source allows you to demonstrate a systematic approach to improvement.';
$string['linked_complaint_improvement'] = 'Linked Complaint';
$string['linked_complaint_improvement_help'] = 'Link this improvement item to a specific complaint if it was triggered by a complaint outcome. Linking creates an auditable trail showing that your RTO acted on feedback — a key piece of evidence for ASQA Standard QA4.4 (Continuous Improvement) and demonstrating that your complaints process leads to genuine systemic change.';
$string['linked_validation_improvement'] = 'Linked Validation';
$string['linked_validation_improvement_help'] = 'Link this improvement item to a specific validation event if it was triggered by a validation finding. This demonstrates that your RTO uses validation outcomes to improve training and assessment practice, as required by ASQA Standard QA1.5.';
$string['improvement_category_help']   = 'Select the compliance area most directly affected by this improvement. This categorisation helps your RTO identify patterns across improvement items — for example, if 80% of improvements relate to assessment practices, that signals a systemic issue requiring strategic attention rather than item-by-item fixes.';
$string['improvement_priority']        = 'Priority';
$string['improvement_priority_help']   = 'Set the priority based on the impact on student outcomes and regulatory risk. High priority: immediate risk to student outcomes or compliance (address within 30 days). Medium: significant but not immediate risk (address within 90 days). Low: improvement opportunity with limited immediate risk (address within 12 months). Priority should be agreed by a manager and documented.';
$string['improvement_status']          = 'Status';
$string['improvement_status_help']     = 'Current status of this improvement action. Keep this updated: Identified → In Progress → Completed → Verified. An accurate status register is essential for ASQA Standard QA4.4 — auditors will check that your improvement register is actively maintained, not just populated and forgotten.';
$string['date_identified_help']        = 'The date the improvement need was first formally identified. This is the start of the accountability clock — your RTO\'s policies should specify maximum timeframes from identification to action for each priority level (e.g., High = 30 days, Medium = 90 days). Recording the date allows you to measure your responsiveness.';
$string['target_date_help']            = 'The planned completion date for this improvement action, based on its priority. Set a realistic but firm target date. ASQA auditors look for evidence that improvement actions are not left open indefinitely — a completed improvement item with a recorded target and completion date demonstrates systematic management.';
$string['completion_date_help']        = 'The date the improvement action was completed. Leave blank if still in progress. Once complete, move to the Verification step to confirm the change actually improved outcomes. A completed date without a verification date may suggest the improvement has not been followed through.';
$string['action_plan_details_help']    = 'A step-by-step action plan describing exactly what will be done to address the improvement. Assign clear ownership, specific actions, and deadlines for each step. Avoid vague actions like "review processes" — instead write "Update the RPL evidence guide to include examples for each unit, assign to Training Manager, complete by 30 June 2026." Specific, measurable actions are what ASQA wants to see.';
$string['improvement_outcome_help']    = 'Describe what was actually done to address the improvement, and what changed as a result. This is the evidence of action — e.g., "Updated RPL evidence guide completed and approved by manager on 14 May 2026. New guide distributed to all assessors and uploaded to Moodle resource page." Be specific about the outputs and any measurable improvement in student outcomes or processes.';
$string['effectiveness_verified_help'] = 'Tick once you have confirmed that the improvement action actually achieved the intended outcome. Verification is the critical step that closes the improvement loop — ASQA Standard QA4.4 expects RTOs to check whether their improvements worked, not just complete actions and assume they did. Common verification methods: follow-up survey, repeat audit, reviewing outcomes data.';
$string['verification_date_help']      = 'The date effectiveness was confirmed through an objective verification method. This date should be after the completion date and after enough time has passed for the improvement to have taken effect (e.g., at the next validation event, or at the end of the next student cohort). Recording this date demonstrates a genuine closed-loop improvement system.';
$string['verification_method_help']    = 'Describe how you verified the improvement was effective (e.g., "Reviewed 10 RPL applications submitted after the guide was updated — all met evidence requirements on first submission, compared to 60% requiring resubmission previously." or "Validation panel confirmed assessment tools meet Standards in December 2026 validation."). Measurable evidence of effectiveness is what distinguishes genuine continuous improvement from paperwork compliance.';
$string['improvement_notes']           = 'Notes';
$string['improvement_notes_help']      = 'Internal notes for staff managing this improvement item. Use this field for context that doesn\'t fit elsewhere — e.g., reasons for delays, approvals obtained, related policy documents updated, staff training completed. These notes contribute to the documented evidence trail for ASQA Standard QA4.4.';

// Validation Validator/Panel register help strings
$string['validator_fullname']          = 'Validator Full Name';
$string['validator_fullname_help']     = 'Full legal name of the validator or panel member. This person\'s credentials and experience must be documented in your Validators Register to demonstrate that validations are conducted by suitably qualified people, as required by ASQA Standard QA1.5. The name here should match their credential documents.';
$string['validator_email']             = 'Email Address';
$string['validator_email_help']        = 'Contact email address for the validator. Used to coordinate validation scheduling, send briefing materials, and distribute draft assessment tools for review. Keep this current so validators can be contacted promptly when a validation event is scheduled.';
$string['validator_phone']             = 'Phone Number';
$string['validator_phone_help']        = 'Contact phone number for the validator. Useful for last-minute scheduling changes and for validators who are external contractors or industry experts who may not respond to email quickly.';
$string['is_internal_help']            = 'Select Internal if this validator is a current employee or contractor of your RTO. Select External if they are independent of your RTO. ASQA Standard QA1.5 and the ASQA Practice Guide on Validation recommend including at least one person who is external to, and independent of, the RTO in each validation panel to provide an objective perspective. Relying solely on internal validators is a common audit finding.';
$string['validator_organisation_help'] = 'For external validators: their employer, industry organisation, or consultancy. For internal validators: their team or role within your RTO (e.g., "Training Department"). Recording the organisation helps demonstrate that your validation panels draw from a range of industry and educational backgrounds, as recommended by ASQA.';
$string['role_type_help']              = 'The validator\'s role in validation activities. Panel Chair: leads the validation process and signs off on findings. Panel Member: reviews assessment tools and contributes findings. Subject Matter Expert: provides industry expertise on current practice standards — may not have TAE credentials but brings vocational currency. Industry Representative: stakeholder from industry who validates that assessment benchmarks reflect actual workplace expectations.';
$string['validator_taecredential_help'] = 'TAE qualification held by this validator (e.g., TAE40122 — Certificate IV in Training and Assessment, or TAE50122 — Diploma of VET). Under the ASQA Credential Policy (Standard QA3.2), validators who are also trainers and assessors must hold the applicable credential. External industry experts and subject matter experts may participate in validation without a TAE credential, but this should be documented.';
$string['tae_date_achieved_help']      = 'Date the validator was awarded their TAE qualification. This is important for determining credential currency — TAE40110 holders (pre-2016 framework) may need to evidence currency through additional units or professional development. Recording the award date allows you to identify validators who may need to update their credentials.';
$string['validator_vocquals_help']     = 'List the vocational qualifications this validator holds that are relevant to the training products they will validate (e.g., "BSB50420 Diploma of Leadership and Management, awarded 2019, Holmesglen TAFE"). ASQA Standard QA1.5 requires that validation panels have sufficient vocational competency to review the assessment tools — a validator without relevant qualifications cannot meaningfully judge whether assessment benchmarks meet industry expectations.';
$string['validator_industryexp_help']  = 'Describe the validator\'s current industry experience in the vocational area. Include: their role, employer, duration of employment, and what they do. Under ASQA Standard QA3.3, currency means actively working in the industry — not just having worked in it historically. Update this field annually when validators renew their agreements.';
$string['validator_expyears_help']     = 'Total number of years of industry experience in the relevant vocational area. Combined with the description of current engagement, this gives a quantitative indicator of the validator\'s depth of industry knowledge. ASQA does not specify a minimum number of years, but panels should have sufficient collective experience to credibly assess industry benchmarks.';
$string['current_industry_engagement_help'] = 'Describe what the validator is currently doing to maintain their industry currency (e.g., "Employed as Senior Project Manager at Arup — 4 days per week on commercial construction projects", or "Sits on the HIA Industry Advisory Panel and attends quarterly meetings"). Specific, current engagement is far more compelling to ASQA than historical experience. If a validator has not had recent industry contact, their participation in validation may be questioned.';
$string['validator_specialisations_help'] = 'List specific areas, qualifications, or units of competency the validator specialises in (e.g., "Certificate III & IV in Construction, site safety units, first aid"). This helps training managers match the right validators to the right qualifications. A generalist validator should not be used to validate specialist trade or health units where deep vocational knowledge is needed.';
$string['validations_led_help']        = 'Total number of validation processes this person has led as panel chair or lead validator. This is an indicator of their experience and capability to lead future panels. ASQA auditors may ask whether validators have received appropriate orientation and have sufficient experience to lead a credible validation process.';
$string['validations_participated_help'] = 'Total number of validation processes this person has participated in as a panel member or subject matter expert (excluding those they led). Combined with the number led, this gives the overall picture of their validation experience and demonstrates an active, ongoing involvement in your RTO\'s quality assurance activities.';
$string['last_validation_date_help']   = 'The date of the most recent validation event this person participated in. Gaps of more than 2–3 years in validation activity may indicate the validator\'s currency with your assessment practices has lapsed. Aim to include experienced validators in at least one event per year to maintain an active, current panel register.';
$string['validator_status_help']       = 'Active: currently available and approved to participate in validation panels. Inactive: no longer used (e.g., left industry, no longer available, or credentials expired). Keeping inactive records rather than deleting them preserves your historical evidence of validation panel composition for previous years — important if ASQA audits a period from 2–3 years ago.';
$string['validator_notes_help']        = 'Notes about this validator — e.g., areas of expertise, preferred contact method, any conflicts of interest to be aware of, credential update reminders, or reasons for inactive status. These internal notes help the training team make informed decisions when assembling validation panels.';

// Rule 9B — Building Classification
$string['certificate9b_upload']      = 'Class 9B Certificate(s)';
$string['certificate9b_upload_help'] = 'Upload the building\'s Class 9B certificate or equivalent approval document (PDF, JPG, or PNG). Having the certificate on file provides evidence during ASQA audits that the premises meet the required building classification for VET delivery. You may upload up to 3 files (e.g. front and back of certificate, or multiple premises certificates). There is no cost to upload — the cost referred to is the cost of obtaining the 9B classification from your local council or certifier.';
$string['rule9b_header']       = 'ASQA Compliance — Rule 9B Building Classification';
$string['rule9b_approved']     = 'Building Approved (Class 9B)';
$string['rule9b_approved_help'] = 'Check this box if the premises hold the required Class 9B building classification (or equivalent approved classification) for vocational education and training delivery, as required by ASQA Standards. Class 9B under the National Construction Code covers buildings used for education, including assembly and lecture halls. RTOs must ensure their facilities are fit-for-purpose and meet relevant building codes.';
$string['rule9b_badge_yes']    = 'Rule 9B Approved';
$string['rule9b_badge_no']     = 'Not 9B Approved';
$string['rule9b_col']          = 'Rule 9B';

// ─── v4.2.30 ROLE-SPLIT + NAV-PRIMARY + PER-TENANT-USI ───────────────────
$string['rtocompliance_navtitle']     = 'RTO Compliance';
$string['viewtrainer']                = 'View trainer-scoped RTO Compliance dashboard';

// Trainer Dashboard
$string['trainerdashboard']           = 'Trainer Dashboard';
$string['trainerdashboard_intro']     = 'Your classes, your students, your currency profile, and the validation events you are assigned to.';
$string['trainer_myclasses']          = 'My Classes';
$string['trainer_noclasses']          = 'You are not currently assigned as the trainer or assessor for any course.';
$string['trainer_mystudents']         = 'My Students';
$string['trainer_nostudents']         = 'No enrolled students found across your current classes.';
$string['trainer_students_note']      = 'Showing up to 200 students enrolled across the courses where you are the trainer or assessor. USI is read from the RTO Compliance student record where present.';
$string['trainer_currency']           = 'My Currency Profile';
$string['trainer_currency_intro']     = 'Maintain your TAE qualifications, vocational currency, and professional development log so your trainer file is audit-ready at any time.';
$string['trainer_currency_open']      = 'Open my currency profile';
$string['trainer_validation']         = 'Validation Events';
$string['trainer_validation_intro']   = 'View the assessment validation events for the products you deliver. Read-only — managers schedule new events.';
$string['trainer_validation_open']    = 'Open Validation Schedule';

// Per-tenant USI Settings
$string['usi_pertenant_title']        = 'USI Verification — Machine Credential Setup';
$string['usi_settings_legacy']        = 'USI Verification (legacy local file — not recommended)';
$string['usi_pertenant_intro']        = 'Upload your RTO\'s own myID Machine Credential keystore so every student USI lookup is signed by your organisation\'s certificate and recorded against your TOID. Each RTO using this platform supplies its own credential — none are shared between tenants.';

// Status panel
$string['usi_pertenant_currentstatus']    = 'Current status';
$string['usi_pertenant_ready']        = 'USI verification is configured and ready';
$string['usi_pertenant_notready']     = 'USI verification is not yet configured';
$string['usi_pertenant_source']       = 'Credential source';
$string['usi_pertenant_orgid']        = 'TOID (Org Code)';
$string['usi_pertenant_mode']         = 'Environment';
$string['usi_pertenant_certsubject']  = 'Credential subject';
$string['usi_pertenant_certexpiry']   = 'Expires';
$string['usi_pertenant_daystoexpiry'] = 'Days remaining';
$string['usi_pertenant_notifemail']   = 'Expiry notification email';
$string['usi_pertenant_expirywarn']   = 'This credential expires soon — generate a renewed Machine Credential in RAM and re-upload it here before the expiry date to avoid an outage.';
$string['usi_pertenant_expired']      = 'This credential has expired. Generate a new Machine Credential in RAM and re-upload it here. USI verifications will fail until you do.';

// API-connection prerequisite (shown if Site ID / API Key not yet set)
$string['usi_pertenant_apicheck_title']   = 'Step 0 — API Connection required';
$string['usi_pertenant_apicheck_body']    = 'Before you can upload a credential, the plugin needs to know how to reach the lms-labs.com platform. Configure the API URL, Site ID and API Key under <b>Site administration → RTO Compliance → API Connection</b>. You can find your Site ID and API Key on the platform dashboard at <i>Settings → Moodle Plugin Connection</i>.';
$string['usi_pertenant_apicheck_link']    = 'Open API Connection settings';

// Upload form
$string['usi_pertenant_uploadtitle']  = 'Upload Machine Credential';
$string['usi_pertenant_uploadintro']  = 'You need three things to fill this form: (1) your RTO\'s TOID, (2) the keystore file you downloaded from RAM (.xml or .pfx), and (3) the password you set when you created the credential. See the step-by-step guide below if you do not yet have a Machine Credential.';

$string['usi_pertenant_field_orgid']          = 'TOID — your RTO Organisation Code';
$string['usi_pertenant_field_orgid_help']     = 'Your 5-digit Training Organisation ID assigned by ASQA. Find it at <a href="https://training.gov.au/Search/Organisation" target="_blank" rel="noopener">training.gov.au → Search Organisations</a> — it is the number at the top of your record (e.g. <i>National Corporate Training Pty Ltd — TOID 50918</i>). The TOID you enter here MUST match the ABN that issued the Machine Credential, otherwise the USI Registry will reject every lookup. If you cannot find your TOID, contact your compliance manager — do not guess.';

$string['usi_pertenant_field_certfile']       = 'Machine Credential keystore file';
$string['usi_pertenant_field_certfile_help']  = 'Select the keystore file produced by RAM when you created the Machine Credential. Modern RAM downloads are named <code>keystore.xml</code> (a PKCS#12 wrapped in XML). Older downloads may be a raw <code>.pfx</code> or <code>.p12</code> file. All three are accepted. The file is sent to the platform over HTTPS, encrypted at rest, and never written to your Moodle file area. If you cannot find your file, check your browser\'s Downloads folder — RAM saves it there immediately when you click "Create" and the myID Credential extension popup appears.';

$string['usi_pertenant_field_password']       = 'Keystore password';
$string['usi_pertenant_field_password_help']  = 'The password you set in RAM when you created the Machine Credential. <b>This password cannot be reset</b> — if you have lost it, you must create a brand new Machine Credential in RAM and start over. Save the password in your password manager. The platform stores it encrypted and never logs it.';

$string['usi_pertenant_field_notifemail']     = 'Expiry notification email';
$string['usi_pertenant_field_notifemail_help'] = 'We will email this address 60, 30 and 7 days before the Machine Credential expires so you have time to generate a renewed one in RAM and re-upload it here. Use a monitored mailbox (compliance@yourrto.com.au is ideal) — not a personal address. Optional but strongly recommended; without it you must remember to renew the credential yourself before the 2-year expiry.';

$string['usi_pertenant_field_testmode']       = 'Use EVTE (test) environment';
$string['usi_pertenant_field_testmode_help']  = 'Leave this ticked for your first upload. EVTE is the USI Office\'s test sandbox — it accepts your real credential but only returns results for published test USIs (not real student USIs). After you confirm the credential is accepted in EVTE, return to this page and untick this box to switch to PRODUCTION (live USI Registry).';

$string['usi_pertenant_submit']               = 'Save credential';

// Result messages
$string['usi_pertenant_uploaded']             = 'Credential uploaded successfully ({$a->bytes} bytes, TOID {$a->org}, {$a->mode} mode). Run a test verification from the Students page next to confirm the credential is accepted by the USI Registry.';
$string['usi_pertenant_upload_failed']        = 'Credential upload failed';
$string['usi_pertenant_err_nofile']           = 'Please select a Machine Credential keystore file (.xml, .pfx, or .p12) to upload.';
$string['usi_pertenant_err_filetoo_small']    = 'The selected file is too small to be a valid Machine Credential keystore. Please re-download the keystore from RAM and try again.';
$string['usi_pertenant_err_no_orgid']         = 'Please enter your RTO TOID (Organisation Code). Find it at training.gov.au.';
$string['usi_pertenant_err_noapi']            = 'RTO Compliance API connection is not configured. Set the API URL, Site ID and API Key under Site administration → RTO Compliance → API Connection first.';
$string['usi_pertenant_err_status']           = 'Could not retrieve the current status from the platform. Your credential will still upload — refresh this page in a minute to see the updated status.';

// Step-by-step help panel
$string['usi_pertenant_help_title']   = 'Step-by-step: How to obtain a myID Machine Credential';
$string['usi_pertenant_help_intro']   = 'A Machine Credential is a digital certificate that lets your RTO talk to the USI Registry as itself, not through a third party. The whole process takes about 15 minutes the first time and is free.';

$string['usi_pertenant_help_prereq_title']    = 'Before you start';
$string['usi_pertenant_help_prereq_1']        = 'Confirm your TOID at <a href="https://training.gov.au/Search/Organisation" target="_blank" rel="noopener">training.gov.au</a>. Note your RTO\'s exact legal name and ABN — you will need them to match.';
$string['usi_pertenant_help_prereq_2']        = 'Confirm your RTO is subscribed to the USI Office as a "Registered Training Organisation" subscriber type at <a href="https://www.usi.gov.au/training-organisations" target="_blank" rel="noopener">usi.gov.au</a>. Without this subscription the USI Registry will reject every verification, even with a valid Machine Credential.';
$string['usi_pertenant_help_prereq_3']        = 'Install the <b>myID</b> app on your phone and verify your personal myID at <a href="https://www.myid.gov.au" target="_blank" rel="noopener">myid.gov.au</a> (Standard or Strong identity strength).';
$string['usi_pertenant_help_prereq_4']        = 'Install the <b>myID Credential</b> Chrome / Edge extension from the Chrome Web Store on the computer you will use. Without this extension the "Create" button in RAM will not save the keystore file — this is the most common cause of the credential creation flow appearing to "do nothing".';
$string['usi_pertenant_help_prereq_5']        = 'You must be the <b>principal authority</b> of your RTO\'s ABN, OR be invited by the principal authority as an "authorised user" with the <b>Machine credential administrator</b> permission. If your RTO does not appear in your business selector inside RAM, the principal authority must add you first via RAM → Manage authorisations → Add new user, then you accept via a 6-digit code emailed to you.';

$string['usi_pertenant_help_step_title']      = 'Create the credential';
$string['usi_pertenant_help_step1']           = 'Sign in to <a href="https://authorisationmanager.gov.au/AuthMgr/v1/" target="_blank" rel="noopener">Relationship Authorisation Manager (RAM)</a> with your myID.';
$string['usi_pertenant_help_step2']           = 'On the landing page choose "View or manage authorisations, machine credentials and cloud software notifications" and select <b>your RTO</b> from the business selector. CRITICAL: confirm the business name shown matches your RTO — creating the credential under the wrong ABN means the USI Registry will reject it.';
$string['usi_pertenant_help_step3']           = 'Open the <b>Machine credentials</b> tab and click "Create machine credential".';
$string['usi_pertenant_help_step4']           = 'Fill the form: a memorable <b>Keystore name</b> (e.g. "MyRTO-USI"), a strong <b>Keystore password</b> (save it in a password manager — it cannot be reset), and a notification email. Leave the suspension/expiry on the default (RAM caps it at 2 years).';
$string['usi_pertenant_help_step5']           = 'Click <b>Create</b>. The myID Credential extension popup appears — click <b>Save</b> and the keystore file (typically <code>keystore.xml</code>) is downloaded to your browser\'s Downloads folder. In RAM, tick "I have saved the keystore file" and continue.';

$string['usi_pertenant_help_upload_title']    = 'Upload here';
$string['usi_pertenant_help_upload_1']        = 'Come back to this page. Enter your TOID, attach the keystore file from your Downloads folder, paste the password, and add a notification email.';
$string['usi_pertenant_help_upload_2']        = 'Leave "Use EVTE (test) environment" ticked and click <b>Save credential</b>.';
$string['usi_pertenant_help_upload_3']        = 'Run a test verification from the Students page (<i>Verify via usi.gov.au</i>). A successful EVTE response confirms the credential is accepted.';
$string['usi_pertenant_help_upload_4']        = 'Return here, untick "Use EVTE" and click Save again — the same credential is reused, but verifications now hit the live USI Registry.';

$string['usi_pertenant_help_renewal_title']   = 'Renewal — every 2 years';
$string['usi_pertenant_help_renewal_body']    = 'Machine Credentials issued by RAM expire after a maximum of 2 years. The platform will email your notification address at 60, 30 and 7 days before expiry. To renew: repeat steps 1–5 above, then re-upload the new keystore file and password on this page. The TOID stays the same.';

$string['usi_pertenant_help_troubleshoot_title']  = 'Troubleshooting';
$string['usi_pertenant_help_troubleshoot_extension']  = '<b>"Create" button does nothing</b> — install the myID Credential Chrome/Edge extension and reload the RAM page.';
$string['usi_pertenant_help_troubleshoot_authority']  = '<b>Your RTO does not appear in the business selector</b> — the principal authority must invite you in RAM with "Machine credential administrator" permission.';
$string['usi_pertenant_help_troubleshoot_password']   = '<b>Password lost</b> — there is no recovery. Create a brand new Machine Credential in RAM and re-upload it here. The old one can be deleted from RAM.';
$string['usi_pertenant_help_troubleshoot_rejection']  = '<b>USI Registry rejects the credential</b> — the most likely cause is a TOID/ABN mismatch (credential was created against the wrong business). Verify the credential\'s subject in the Current status panel above and confirm it matches your TOID.';

// Platform API key display strings
$string['usi_pertenant_apikey_label']     = 'Platform API key';
$string['usi_pertenant_apikey_saved']     = '(saved)';
$string['usi_pertenant_apikey_not_set']   = '(not set — check Plugin Settings → Platform API)';
$string['usi_pertenant_config_source']    = 'Reading from';
$string['usi_pertenant_siteid_mismatch']  = 'Config mismatch: uploads use site ID <strong>{$a->aiconfig}</strong> (local_aiconfig) but Platform API tab shows <strong>{$a->rtocompliance}</strong> (local_rtocompliance). Update both to match so all plugins use the same site.';


// CERT-TEMPLATE-BUILDER (v4.2.40) — visual certificate template builder strings.
$string['cert_templates']                       = 'Certificate Templates';
$string['cert_templates_desc']                  = 'Design your own testamur, statement of attainment, and record of results layouts. Drag and drop mandatory fields, upload a background image and logo, add custom text/date/image elements, and submit for ASQA approval before activating.';
$string['cert_template_new']                    = 'New template';
$string['cert_template_edit_btn']               = 'Edit';
$string['cert_template_preview_btn']            = 'Preview';
$string['cert_template_submit_btn']             = 'Submit for approval';
$string['cert_template_activate_btn']           = 'Activate';
$string['cert_template_archive_btn']            = 'Archive';
$string['cert_template_duplicate_btn']          = 'Duplicate';
$string['cert_template_delete_btn']             = 'Delete';
$string['cert_template_save_btn']               = 'Save draft';
$string['cert_template_status_draft']           = 'Draft';
$string['cert_template_status_approved']        = 'Approved';
$string['cert_template_status_archived']        = 'Archived';
$string['cert_template_active_badge']           = 'Active';
$string['cert_template_certtype']               = 'Certificate type';
$string['cert_template_name']                   = 'Template name';
$string['cert_template_status']                 = 'Status';
$string['cert_template_modified']               = 'Last modified';
$string['cert_template_modifiedby']             = 'Modified by';
$string['cert_template_actions']                = 'Actions';
$string['cert_template_validation']             = 'ASQA compliance validator';
$string['cert_template_validation_passed']      = 'All ASQA requirements met. Template can be submitted for approval.';
$string['cert_template_validation_errors']      = 'ASQA errors — must be fixed before approval';
$string['cert_template_validation_warnings']    = 'Recommendations';
$string['cert_template_validation_pending']     = 'Save the template to run the ASQA validator.';
$string['cert_template_palette']                = 'Field palette';
$string['cert_template_palette_dynamic']        = 'Mandatory & dynamic fields';
$string['cert_template_palette_custom']         = 'Custom fields';
$string['cert_template_palette_text']           = 'Text';
$string['cert_template_palette_date']           = 'Date';
$string['cert_template_palette_image']          = 'Image';
$string['cert_template_palette_line']           = 'Line';
$string['cert_template_palette_box']            = 'Box';
$string['cert_template_props']                  = 'Properties';
$string['cert_template_props_select']           = 'Select a field on the canvas to edit its properties.';
$string['cert_template_prop_x']                 = 'X (mm)';
$string['cert_template_prop_y']                 = 'Y (mm)';
$string['cert_template_prop_w']                 = 'Width (mm)';
$string['cert_template_prop_h']                 = 'Height (mm)';
$string['cert_template_prop_font']              = 'Font';
$string['cert_template_prop_fontsize']          = 'Font size (pt)';
$string['cert_template_prop_fontstyle']         = 'Style';
$string['cert_template_prop_color']             = 'Colour';
$string['cert_template_prop_align']             = 'Alignment';
$string['cert_template_prop_text']              = 'Text content';
$string['cert_template_prop_dateformat']        = 'Date format';
$string['cert_template_prop_image']             = 'Image';
$string['cert_template_prop_linewidth']         = 'Line thickness (mm)';
$string['cert_template_prop_delete']            = 'Delete field';
$string['cert_template_page_settings']          = 'Page';
$string['cert_template_page_orientation']       = 'Orientation';
$string['cert_template_page_orientation_l']     = 'Landscape';
$string['cert_template_page_orientation_p']     = 'Portrait';
$string['cert_template_page_bgcolor']           = 'Background colour';
$string['cert_template_page_bgimage']           = 'Background image';
$string['cert_template_page_bgimage_clear']     = 'Remove background image';
$string['cert_template_certtype_testamur']      = 'Testamur (full qualification)';
$string['cert_template_certtype_statement']     = 'Statement of attainment (units)';
$string['cert_template_certtype_record']        = 'Record of results';
$string['cert_template_certtype_completion']    = 'Certificate of Completion (non-accredited)';
$string['cert_template_create_heading']         = 'Create new template';
$string['cert_template_create_intro']           = 'Choose the certificate type and give your template a memorable name. You will be taken to the visual editor to design the layout.';
$string['cert_template_none_yet']               = 'No templates yet — click "New template" to get started. Until you activate a template, certificates will use the built-in default layout.';
$string['cert_template_action_ok_saved']        = 'Template saved.';
$string['cert_template_action_ok_approved']     = 'Template approved.';
$string['cert_template_action_ok_activated']    = 'Template activated. New certificates of this type will use this design.';
$string['cert_template_action_ok_archived']     = 'Template archived.';
$string['cert_template_action_ok_deleted']      = 'Template deleted.';
$string['cert_template_action_ok_duplicated']   = 'Template duplicated. The new draft is open below.';
$string['cert_template_action_err_validation']  = 'Cannot approve — please fix the ASQA errors first.';
$string['cert_template_action_err_notallowed']  = 'Action not allowed in the current state.';
$string['cert_template_confirm_archive']        = 'Archive this template? Active certificates will no longer use it.';
$string['cert_template_confirm_delete']         = 'Delete this draft template? This cannot be undone.';
$string['cert_template_confirm_activate']       = 'Activate this template? Any other active template for this certificate type will be deactivated.';
$string['cert_template_savefirst']              = 'Unsaved changes — save the draft before previewing.';

// CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — RTO Branding panel.
$string['cert_template_branding']               = 'RTO Branding';
$string['cert_template_branding_help']          = 'Upload your RTO logo and CEO signature once — every template will automatically use them wherever the rto.logo or signatory.signature dynamic fields are placed.';
$string['cert_template_branding_logo']          = 'RTO logo';
$string['cert_template_branding_signature']     = 'CEO signature image (PNG with transparent background recommended)';
$string['cert_template_branding_clear']         = 'Remove on save';
$string['cert_template_branding_missing']       = 'Tip: certificates look unprofessional without your RTO logo and a real signature image — upload both above and they will populate every template automatically.';

// ASQA-COMPLIANCE-PASS-3 (v4.2.60) — STA logo upload.
$string['cert_template_branding_sta_logo']      = 'State / Territory Training Authority logo (optional)';
$string['cert_template_branding_sta_logo_help'] = 'Only required for RTOs delivering state-funded VET on a state contract. Upload your relevant State Training Authority logo (e.g. NSW Training Services, VIC DJSIR, QLD DESBT, etc.). When set, it will replace the placeholder STA logo on testamurs and Statements of Attainment that use the state_training_authority_logo dynamic field.';

// ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Quick Start guide panel (editor).
$string['cert_template_quickstart_title']       = 'Quick guide: How to edit this certificate';
$string['cert_template_quickstart_intro']       = 'Every part of this certificate can be moved, resized, restyled or replaced. Click any element on the canvas to select it, then use the properties panel on the right to change its appearance. Drag to reposition, drag the corner handle to resize.';
$string['cert_template_quickstart_step1']       = 'Upload your RTO logo and CEO signature in the RTO Branding section below — they will appear automatically wherever those fields are placed on the certificate.';
$string['cert_template_quickstart_step2']       = 'Click any text or image on the certificate to select it. The properties panel on the right lets you change the position, size, font, colour, alignment, and bold/italic.';
$string['cert_template_quickstart_step3']       = 'Add new content from the field palette below. "Dynamic" fields (like student name or qualification code) are filled in automatically when the certificate is generated. "Custom" fields (text, image, line, box) let you add your own decorative or static content.';
$string['cert_template_quickstart_step4']       = 'Tick "Show sample data" in the toolbar to preview what the certificate will look like with real student information filled in.';
$string['cert_template_quickstart_step5']       = 'Save your changes as a draft, then click "Preview" to download a sample PDF. When you are happy with the design, submit it for approval and activate it.';
$string['cert_template_quickstart_safety']      = 'Made a mistake? Click "Reset to ASQA starter" on the templates list — it will restore the ASQA-recommended starting design without losing your template settings.';

// ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Reset to ASQA starter.
$string['cert_template_reset_btn']              = 'Reset to ASQA starter';
$string['cert_template_confirm_reset']          = 'Reset this template to the ASQA-recommended starter design? Your custom layout, fonts and field positions will be replaced. This cannot be undone.';
$string['cert_template_action_ok_reset']        = 'Template was reset to the ASQA-recommended starter design.';

// ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Completion-of-course optional descriptor.
$string['completionofcoursestatement']          = 'Completion-of-course statement (SoA — auto-generated)';
$string['completionofcoursestatement_desc']     = 'This field is no longer used. The completion-of-course statement is now auto-generated from each certificate\'s qualification code and name: <em>"These competencies were attained in completion of {CODE} course in {NAME}."</em> The qualification code is automatically inserted before the word "course" per the ASQA Sample Forms fact sheet, page 4. Any value saved here will be ignored.';

// Editor toolbar.
$string['cert_template_toolbar_undo']           = 'Undo';
$string['cert_template_toolbar_redo']           = 'Redo';
$string['cert_template_toolbar_zoom']           = 'Zoom';
$string['cert_template_toolbar_grid']           = 'Show grid';
$string['cert_template_toolbar_sample']         = 'Show sample data';
$string['cert_template_keyboard_help']          = 'Arrow keys nudge 1mm (Shift+Arrow = 5mm) | Delete = remove | Ctrl+D = duplicate | Esc = deselect';

// Validator Fix buttons.
$string['cert_template_validation_fix']         = 'Add at recommended position';
$string['cert_template_validation_remove']      = 'Remove';

// CERT-OF-COMPLETION + TEST-CERT (v4.2.41)
$string['cert_test_pagetitle']                  = 'Test certificate generator';
$string['cert_test_heading']                    = 'Generate a test certificate';
$string['cert_test_intro']                      = 'Pick a certificate type and click "Generate test PDF". The system will use the active approved template for that type if one exists, otherwise it will fall back to the built-in default layout. The PDF is rendered with sample student data — nothing is saved.';
$string['cert_test_certtype_label']             = 'Certificate type';
$string['cert_test_studentname_label']          = 'Sample student name (optional)';
$string['cert_test_studentname_placeholder']    = 'Defaults to "Jane Citizen"';
$string['cert_test_generate']                   = 'Generate test PDF';
$string['cert_test_active_template']            = 'Active template: {$a}';
$string['cert_test_no_active_template']         = 'No active template — will use built-in default layout.';
$string['cert_test_link']                       = 'Generate test certificate';
$string['cert_test_orientation_label']          = 'Page orientation';
$string['cert_test_orientation_auto']           = 'Auto — follow certificate type default';
$string['cert_test_orientation_portrait']       = 'Portrait (A4 tall)';
$string['cert_test_orientation_landscape']      = 'Landscape (A4 wide)';
$string['cert_test_orientation_hint']           = 'Override the default orientation to preview both layouts. Testamur and Completion default to landscape; Statement of Attainment and Record of Results default to portrait.';

// ── LLN Integration (v4.2.50) ────────────────────────────────────────────────
$string['lln_heading']             = 'LLN Integration';
$string['lln_heading_desc']        = 'Configure how the Student Suitability Check obtains a student\'s assessed Australian Core Skills Framework (ACSF) level. Trainer-entered levels remain available as a fallback regardless of which adapter is selected.';
$string['lln_adapter']             = 'LLN provider';
$string['lln_adapter_desc']        = 'Select which LLN provider supplies the student\'s ACSF level when the Student Suitability Check is opened. Webhook lets you plug your own LLN system in (or our hosted Replit LLN endpoint).';
$string['lln_adapter_manual']      = 'Trainer entry (manual)';
$string['lln_adapter_webhook']     = 'External webhook';
$string['lln_provider_label']      = 'Provider display name';
$string['lln_provider_label_desc'] = 'Optional friendly name for your LLN provider (e.g. "Acme LLN Online"). Shown to trainers and students wherever the LLN result appears. Leave blank to use a generic label.';
$string['lln_webhook_url']         = 'Webhook URL';
$string['lln_webhook_url_desc']    = 'HTTPS endpoint that returns the student\'s ACSF level. Called server-to-server when the Student Suitability Check opens. Expects JSON response: {"level":"3","assessed_at":1735689600,"assessor":"Acme LLN v2.4"}.';
$string['lln_webhook_secret']      = 'Webhook signing secret';
$string['lln_webhook_secret_desc'] = 'Shared secret used to sign each webhook request with HMAC-SHA256 in the X-RTO-Signature header. Your LLN provider must verify this signature before returning a level.';
$string['lln_assessor_trainer']    = 'Trainer (manual entry)';
$string['lln_no_result']           = 'No LLN assessment on file yet.';
$string['lln_result_summary']      = 'Assessed at ACSF Level {$a->level} on {$a->date} by {$a->assessor}.';
$string['lln_required_summary']    = 'This course requires a minimum of ACSF Level {$a}.';
$string['lln_send_manual_override']         = 'Enter LLN level manually for this student';
$string['lln_send_manual_override_desc']    = 'Tick this box to enter an LLN level manually for this send, even though the site is configured to auto-pull from {$a}. Useful when the auto-pull system has no record for the student yet.';
$string['lln_send_webhook_notice']          = 'LLN will be auto-pulled from <strong>{$a}</strong> when the student opens this form. Trainer-entered levels still take precedence as a fallback.';

// ── ASQA-COMPLIANCE-LANG (v4.2.58) ─────────────────────────────────────
$string['aqfstatement']      = 'AQF Statement (Testamur / Statement of Attainment)';
$string['aqfstatement_desc'] = 'The mandatory ASQA wording placed on the testamur and Statement of Attainment to identify the qualification under the Australian Qualifications Framework. Leave blank to use the default per-cert auto-text "[QUALCODE] is recognised within the Australian Qualifications Framework."';
$string['signatorysignature']      = 'Authorised Signatory Signature Image';

// ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptors per ASQA Sample Forms fact sheet.
$string['industrydescriptor']         = 'Industry descriptor (testamur, optional)';
$string['industrydescriptor_desc']    = 'Optional plain-English industry name (e.g. "Business Services", "Health Services") printed below the qualification on testamurs.';
$string['occupationalstream']         = 'Occupational / functional stream (testamur, optional)';
$string['occupationalstream_desc']    = 'Optional stream / specialisation (e.g. "Administration") printed in brackets below the industry descriptor on testamurs.';
$string['apprenticeshipstatement']    = 'Australian Apprenticeship statement (optional)';
$string['apprenticeshipstatement_desc'] = 'Optional statement printed where the qualification was achieved through an Australian Apprenticeship (e.g. "Achieved through an Australian Apprenticeship.").';
$string['languagestatement']          = 'Language-of-issue statement (optional)';
$string['languagestatement_desc']     = 'Optional statement printed where units were delivered and assessed in a language other than English (e.g. "These units were delivered and assessed in Mandarin.").';
$string['skillsetstatement']          = 'Skill set statement (statement of attainment, optional)';
$string['skillsetstatement_desc']     = 'Optional statement printed on a statement of attainment when units form part of a recognised skill set (e.g. "These units form part of the Workplace First Aid skill set.").';
$string['signatorysignature_desc'] = 'PNG/JPG/SVG signature image displayed above the signatory name on every certificate. Upload via the cert template branding panel.';

// v4.3.0 CERT-TEMPLATE-AUDIENCES — audience codes and helper strings.
// One template per (certtype + audience) can be active at a time.
// Audiences let an RTO ship different testamur designs to different
// student groups (e.g. apprentices vs general public vs school-based)
// for the same qualification.
$string['cert_template_audience']            = 'Audience';
$string['cert_template_audience_help']       = 'Pick which student group this template should be used for. You can keep one active template per (certificate type + audience) combination — for example, separate active testamurs for apprentices and the general public.';
$string['cert_template_audiencelabel']       = 'Audience label (optional)';
$string['cert_template_audiencelabel_placeholder'] = 'e.g. Apprentices — VIC contract';
$string['cert_template_audience_default']             = 'Default (any student)';
$string['cert_template_audience_apprentice']          = 'Apprentices';
$string['cert_template_audience_traineeship']         = 'Trainees';
$string['cert_template_audience_school']              = 'School-based / VET in Schools';
$string['cert_template_audience_vetfee']              = 'VET Student Loan / VET-FEE';
$string['cert_template_audience_funded_state']        = 'State-funded';
$string['cert_template_audience_funded_commonwealth'] = 'Commonwealth-funded';
$string['cert_template_audience_international']       = 'International / CRICOS';
$string['cert_template_audience_private_fee']         = 'Private fee-for-service';
$string['certificate_audience_help']         = 'Pick the student group this certificate is being issued under. The matching active template will be used (and pinned onto the certificate so any future reissue uses the same design).';

// v4.4.0 NRT-LOGO-COMPLIANCE — compliance asset upload slots.
$string['compliance_logos_heading']      = 'Compliance logos';
$string['compliance_logos_heading_desc'] = 'Upload your official ASQA-supplied compliance artwork. Uploaded files override the bundled defaults shipped in the plugin. PNG or JPEG only.';
$string['nrt_logo_file']                 = 'NRT logo (Nationally Recognised Training)';
$string['nrt_logo_file_desc']            = 'Per the NRT Logo Conditions of Use Policy, the NRT logo can only be reproduced from the artwork ASQA (the National VET Regulator) provided to your RTO. Upload that exact file here. If left blank, a generic ASQA-style fallback shipped in the plugin will be used.';
$string['aqf_logo_file']                 = 'AQF logo';
$string['aqf_logo_file_desc']            = 'Optional — upload the official AQF logo if you display it on testamurs. Falls back to a bundled default if blank.';
$string['organisation_seal_file']        = 'Organisation seal / corporate identifier';
$string['organisation_seal_file_desc']   = 'REQUIRED on testamurs and Statements of Attainment per ASQA Practice Guide (Issue of VET qualifications and VET statements of attainment, item 1(e)/2(e)). This is your RTO\'s unique watermark or corporate identifier — distinct from your RTO logo and the verification URL.';
$string['compliance_logo_1']             = 'Additional compliance logo 1 (e.g. State Training Authority)';
$string['compliance_logo_1_desc']        = 'Optional — upload any additional logo required by your funding body (e.g. a State or Territory Training Authority logo for state-funded VET delivery).';
$string['compliance_logo_2']             = 'Additional compliance logo 2';
$string['compliance_logo_2_desc']        = 'Optional — second free-form compliance logo slot.';

// v4.4.0 — overdue issuance scheduled-task name.
$string['task_check_overdue_issuance']   = 'Flag certificates not issued within 30 days of completion (ASQA SLA)';

// v4.7.104 BULK-COURSE-CERTS — Bulk certificate generation and student document portal.
// FIX-GENERATE-LABEL (v5.0.5): Correct the terminology. In the VET/Moodle mapping a
// Moodle Course = Unit of Competency, and a Moodle Category = Qualification. This page
// bulk-issues certificates for all completers of a single Moodle Course (unit), so
// "by Course" is the accurate label. The previous rename to "by Qualification" was
// incorrect and caused confusion (page picker said "Select a Unit of Competency" while
// the heading said "by Qualification").
$string['generate_course_certs']           = 'Generate Certificates by Course';
$string['generate_course_certs_desc']      = 'Bulk-issue certificates for all students who have completed a specific Moodle course (unit of competency). The system automatically determines the correct certificate type (Testamur + Record of Results, Statement of Attainment, or Completion Certificate) based on the course\'s qualification and qualbuilder settings.';
$string['generate_qual_certs']             = 'Generate Certificates by Qualification';
$string['generate_qual_certs_desc']        = 'Bulk-issue Testamur + Record of Results for all students who have completed every linked unit-of-competency course in a full qualification. Select an active qualification from the Qualification Builder; the system automatically identifies eligible students.';
$string['student_cert_portal']             = 'My Certificate Portfolio';
$string['student_cert_portal_desc']        = 'View and download all your certificates issued by this training organisation.';
$string['cert_type_detected']              = 'Certificate type detected';
$string['cert_type_full_qual']             = 'Full Qualification — Testamur + Record of Results';
$string['cert_type_partial_qual']          = 'Partial Qualification — Statement of Attainment';
$string['cert_type_skillset']              = 'Skill Set / Single Unit — Statement of Attainment';
$string['cert_type_non_accredited']        = 'Non-Accredited — Completion Certificate';
$string['generate_missing_certs']          = 'Generate Missing Certificates';
$string['certs_already_issued']            = 'Already issued';
$string['certs_needs_generation']          = 'Needs certificate';
$string['mydocuments']                     = 'My Documents &amp; Certificates';
$string['mydocuments_desc']                = 'View all your issued certificates and documents uploaded by your trainer or administrator.';
$string['student_doc_rpl']                 = 'RPL Decision / Evidence';
$string['student_doc_usi_letter']          = 'USI Verification Letter';
$string['student_doc_suitability']         = 'Suitability Assessment';
$string['student_doc_credit_transfer']     = 'Credit Transfer Record';
$string['student_doc_enrolment_agreement'] = 'Enrolment Agreement';
$string['student_doc_third_party']         = 'Third-Party Workplace Record';
$string['student_doc_nat_export']          = 'AVETMISS Export';
$string['student_doc_other']               = 'Other Document';
$string['upload_document']                 = 'Upload Document';
$string['doc_type']                        = 'Document Type';
$string['doc_notes']                       = 'Notes';

// FIX-SUSPENDED-UNSUSPEND (v5.2.38)
$string['user_unsuspended'] = 'Account has been unsuspended. The student can now log in.';

// FIX-MANDATORY-WORDING (v5.2.38) — admin settings for ASQA mandatory phrase wording.
$string['mandatoryphrasesheading']               = 'Mandatory ASQA Certificate Wording';
$string['mandatoryphrasesheading_desc']          = 'Customise the mandatory phrases that appear on AQF certificates. Leave any field blank to use the built-in ASQA default wording. Changes apply to all new certificates generated after saving. Reference: <a href="https://www.asqa.gov.au/sites/default/files/2026-04/fact_sheet_-_sample_forms_of_aqf_certification_documentation.pdf" target="_blank" rel="noopener">ASQA Fact Sheet – Sample forms of AQF certification documentation</a>.';
$string['certify_statement_setting']             = '"This is to certify that" (Testamur only)';
$string['certify_statement_setting_desc']        = 'The text printed <strong>before the student\'s name</strong> on a Testamur (ASQA fact sheet p.2). Default: <em>This is to certify that</em>';
$string['attained_statement_setting']            = '"has fulfilled the requirements for" (Testamur only)';
$string['attained_statement_setting_desc']       = 'The text printed <strong>after the student\'s name</strong> on a Testamur, linking the name to the qualification (ASQA fact sheet p.2). Default: <em>has fulfilled the requirements for</em>';
$string['soa_intro_statement_setting']           = '"This is a statement that" (Statement of Attainment only)';
$string['soa_intro_statement_setting_desc']      = 'The text printed <strong>before the student\'s name</strong> on a Statement of Attainment (ASQA fact sheet p.4). Default: <em>This is a statement that</em>';
$string['soa_attained_statement_setting']        = '"has attained" (Statement of Attainment only)';
$string['soa_attained_statement_setting_desc']   = 'The text printed <strong>after the student\'s name</strong> on a Statement of Attainment, linking the name to the units of competency (ASQA fact sheet p.4). Default: <em>has attained</em>';
$string['statement_of_attainment_heading_setting']      = 'Statement of Attainment heading';
$string['statement_of_attainment_heading_setting_desc'] = 'Heading printed at the top of Statements of Attainment. Default: <em>Statement of Attainment</em>';
$string['record_of_results_heading_setting']            = 'Record of Results heading';
$string['record_of_results_heading_setting_desc']       = 'Heading printed at the top of Records of Results. Default: <em>Record of Results</em>';
$string['not_a_testamur_setting']                = 'Statement of Attainment banner (top of document)';
$string['not_a_testamur_setting_desc']           = 'Mandatory banner printed <strong>at the very top</strong> of Statements of Attainment, above the RTO name and logo. Per the <a href="https://www.asqa.gov.au/sites/default/files/2026-04/fact_sheet_-_sample_forms_of_aqf_certification_documentation.pdf" target="_blank" rel="noopener">ASQA fact sheet</a> (page 4), this statement is mandatory and must be prominent to ensure the document is not mistaken for a testamur. Default: <em>A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS</em>';

// STATE FUNDING — v5.9.43 ─────────────────────────────────────────────────────
// New fields: schooltype (students), concessionstatus + purchasingcontract1/2/3
// (enrolments). New admin settings page: local_rtocompliance_statefunding.

// Student profile form — school type field
$string['schooltype']          = 'School Sector';
$string['schooltype_help']     = 'The sector of the school the student currently attends. Required by QLD DTET and other State Training Authorities when the student is enrolled in secondary school (VET in Schools / School-Based Apprenticeship). Only visible when "Currently Attending School" is ticked.';
$string['schooltype_gov']      = 'Government';
$string['schooltype_cat']      = 'Catholic';
$string['schooltype_ind']      = 'Independent';
$string['schooltype_oth']      = 'Other';

// Enrolment form — concession status
$string['concessionstatus']       = 'Fee Concession Status';
$string['concessionstatus_help']  = 'Whether the student paid a full fee, a concessional (reduced) fee, or received a fee exemption/waiver. Required for state-funded training reporting (QLD DTET, NSW Smart & Skilled, VIC Skills First and others). F = Full fee; C = Concessional rate; E = Exempt / Fee waived.';

// Enrolment form — purchasing contracts
$string['purchasingcontract1']      = 'Purchasing Contract Code 1';
$string['purchasingcontract1_help'] = 'The state funding purchasing contract or program code that this enrolment is reported under. Queensland DTET requires at least one code per funded enrolment (e.g. QS102922 for Career Start). Other states typically use a single contract reference. Up to three codes can be recorded — enter additional codes in fields 2 and 3 only if required by your STA.';
$string['purchasingcontract2']      = 'Purchasing Contract Code 2';
$string['purchasingcontract3']      = 'Purchasing Contract Code 3';

// Admin settings page — State Funding & Reporting
$string['statefunding_settings']      = 'State Funding & Reporting';
$string['statefunding_settings_desc'] = 'Configure per-state default values for AVETMISS "below the line" reporting fields required by each Australian State Training Authority (STA). Settings here provide site-wide defaults that pre-fill new enrolments — trainers can override them at the individual enrolment level. Only complete the sections for states in which your RTO holds a funded training contract.';

// QLD
$string['statefunding_qld']              = 'Queensland (QLD) — DTET';
$string['statefunding_qld_desc']         = 'Required for RTOs delivering Queensland Government funded training under DTET programs (Career Start, Career Boost, Cert 3 Guarantee, Higher Level Skills, User Choice, VETiS). The RTO identifier and purchasing contract codes below must match your approved DTET contract.';
$string['qld_dtet_rtoid']                = 'QLD DTET RTO Identifier';
$string['qld_dtet_rtoid_desc']           = 'Your organisation\'s identifier as registered with the Queensland Department of Training and Education (DTET). This is distinct from your national AVETMISS RTO ID and is assigned when you sign a DTET funding contract. Leave blank if your RTO does not deliver Queensland government-funded training.';
$string['qld_funding_code_default']      = 'Default QLD Funding Source Code';
$string['qld_funding_code_default_desc'] = 'The QLD DTET program code that applies to most of your funded enrolments. This will be pre-selected in the enrolment form\'s "Funding Source (State)" field and can be changed per enrolment. Common codes: B01 Career Boost, S01 Career Start, QL1 Certificate 3 Guarantee, QC1 Higher Level Skills.';
$string['qld_purchasing_contract_1']     = 'Default Purchasing Contract 1';
$string['qld_purchasing_contract_desc']  = 'QLD DTET requires RTOs to record the purchasing contract code(s) under which each unit of competency is reported. Enter the code exactly as it appears in your DTET approval letter (e.g. QS102922). If your RTO operates under multiple contracts, enter up to three codes — DTET accepts up to 3 per enrolment.';
$string['qld_purchasing_contract_2']     = 'Default Purchasing Contract 2';
$string['qld_purchasing_contract_3']     = 'Default Purchasing Contract 3';

// NSW
$string['statefunding_nsw']              = 'New South Wales (NSW) — Smart & Skilled';
$string['statefunding_nsw_desc']         = 'Required for RTOs delivering NSW Government subsidised training under the Smart & Skilled program. Your commitment ID is assigned by the NSW Department of Education when your training contract is approved.';
$string['nsw_commitment_id']             = 'NSW Smart & Skilled Commitment ID';
$string['nsw_commitment_id_desc']        = 'The commitment identifier assigned by the NSW Department of Education for your Smart & Skilled training contract. This is included in AVETMISS state reporting for NSW funded enrolments. Leave blank if your RTO does not hold a NSW Smart & Skilled contract.';
$string['nsw_funding_code_default']      = 'Default NSW Funding Source Code';
$string['nsw_funding_code_default_desc'] = 'The NSW funding source code that applies to most of your funded enrolments. Code 22 covers most government-subsidised Smart & Skilled training.';
$string['nsw_purchasing_contract']       = 'NSW Purchasing Contract Reference';
$string['nsw_purchasing_contract_desc']  = 'Your NSW Smart & Skilled purchasing contract reference number, as it appears in your contract documentation.';

// VIC
$string['statefunding_vic']              = 'Victoria (VIC) — Skills First';
$string['statefunding_vic_desc']         = 'Required for RTOs delivering Victorian Government subsidised training under the Skills First program. Your contract ID is assigned by the Victorian Skills Authority (VSA).';
$string['vic_contract_id']               = 'VIC Skills First Contract ID';
$string['vic_contract_id_desc']          = 'Your Skills First contract identifier as assigned by the Victorian Skills Authority. This is required for SVTS (Skills Victorian Training System) reporting. Leave blank if your RTO does not hold a VIC Skills First contract.';
$string['vic_funding_code_default']      = 'Default VIC Funding Source Code';
$string['vic_funding_code_default_desc'] = 'The VIC Skills First program code that applies to most of your funded enrolments.';

// SA
$string['statefunding_sa']              = 'South Australia (SA) — Skills for All';
$string['statefunding_sa_desc']         = 'Required for RTOs delivering South Australian Government subsidised training under the Skills for All program. Contract references are assigned by the SA Department for Industry, Science and Resources.';
$string['sa_contract_ref']              = 'SA Training Contract Reference';
$string['sa_contract_ref_desc']         = 'Your South Australia funded training contract reference. Leave blank if your RTO does not hold an SA subsidised training contract.';
$string['sa_funding_code_default']      = 'Default SA Funding Source Code';
$string['sa_funding_code_default_desc'] = 'The SA program code that applies to most of your funded enrolments.';

// WA
$string['statefunding_wa']              = 'Western Australia (WA) — DTWD / TAC';
$string['statefunding_wa_desc']         = 'Required for RTOs delivering Western Australian Government subsidised training under DTWD programs. Contract numbers are assigned by the Training Accreditation Council (TAC).';
$string['wa_contract_number']           = 'WA DTWD Contract Number';
$string['wa_contract_number_desc']      = 'Your Western Australia Department of Training and Workforce Development contract number. Required for RAPT/STELA state reporting. Leave blank if your RTO does not hold a WA government training contract.';
$string['wa_funding_code_default']      = 'Default WA Funding Source Code';
$string['wa_funding_code_default_desc'] = 'The WA program code that applies to most of your funded enrolments.';

// TAS
$string['statefunding_tas']              = 'Tasmania (TAS) — Skills Tasmania';
$string['statefunding_tas_desc']         = 'Required for RTOs delivering Tasmanian Government subsidised training under Skills Tasmania programs.';
$string['tas_contract_ref']              = 'TAS Skills Tasmania Contract Reference';
$string['tas_contract_ref_desc']         = 'Your Skills Tasmania funded training contract reference. Leave blank if your RTO does not hold a TAS funded training contract.';
$string['tas_funding_code_default']      = 'Default TAS Funding Source Code';
$string['tas_funding_code_default_desc'] = 'The TAS program code that applies to most of your funded enrolments.';

// NT
$string['statefunding_nt']              = 'Northern Territory (NT) — DITT';
$string['statefunding_nt_desc']         = 'Required for RTOs delivering Northern Territory Government subsidised training under DITT (Department of Industry, Tourism and Trade) programs.';
$string['nt_contract_ref']              = 'NT DITT Contract Reference';
$string['nt_contract_ref_desc']         = 'Your NT Government funded training contract reference. Leave blank if your RTO does not hold an NT training contract.';
$string['nt_funding_code_default']      = 'Default NT Funding Source Code';
$string['nt_funding_code_default_desc'] = 'The NT program code that applies to most of your funded enrolments.';

// ACT
$string['statefunding_act']              = 'Australian Capital Territory (ACT) — Skills Canberra / AVETARS';
$string['statefunding_act_desc']         = 'Required for RTOs delivering ACT Government subsidised training. AVETARS (Australian Vocational Education and Training AVETMISS Reporting System) is the ACT data collection system managed by Skills Canberra.';
$string['act_avetars_ref']               = 'ACT AVETARS Reference Number';
$string['act_avetars_ref_desc']          = 'Your AVETARS reference number assigned by Skills Canberra (ACT Government). Required for ACT state AVETMISS reporting. Leave blank if your RTO does not hold an ACT funded training contract.';
$string['act_funding_code_default']      = 'Default ACT Funding Source Code';
$string['act_funding_code_default_desc'] = 'The ACT program code that applies to most of your funded enrolments.';
