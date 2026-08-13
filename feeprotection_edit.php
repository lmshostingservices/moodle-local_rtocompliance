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
 * RTO Compliance plugin — feeprotection_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_feeprotection');
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/feeprotection_edit.php', ['id' => $id]));

$fee = null;
if ($id) {
    $fee = $DB->get_record('local_rtocompliance_fees', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Fee Record');
    $PAGE->navbar->add(get_string('feeprotection', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/feeprotection.php'));
    $PAGE->navbar->add('Edit Fee Record');
} else {
    $PAGE->set_title('Add Fee Record');
    $PAGE->navbar->add(get_string('feeprotection', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/feeprotection.php'));
    $PAGE->navbar->add('Add Fee Record');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_fees', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/feeprotection.php'),
        'Fee record deleted',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$allusers = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
     FROM {user} u
     WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1
     ORDER BY u.lastname, u.firstname",
    [],
    0,
    500
);
$useroptions = ['' => 'Select a student...'];
foreach ($allusers as $u) {
    $useroptions[$u->id] = fullname($u) . ' (' . $u->email . ')';
}

$courses = $DB->get_records('course', ['visible' => 1], 'fullname', 'id, fullname, shortname');
$courseoptions = ['' => 'Select a course...'];
foreach ($courses as $c) {
    if ($c->id != SITEID) {
        $courseoptions[$c->id] = $c->shortname . ': ' . $c->fullname;
    }
}

class feeprotection_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $useroptions = $this->_customdata['useroptions'];
        $courseoptions = $this->_customdata['courseoptions'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'studentheader', 'Student & Course');

        $mform->addElement('select', 'userid', 'Student', $useroptions);
        $mform->addRule('userid', null, 'required', null, 'client');

        $mform->addElement('select', 'courseid', 'Course/Qualification', $courseoptions);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $mform->addElement('header', 'feeheader', 'Fee Details');

        $mform->addElement('text', 'amount', 'Fee Amount ($)', ['size' => 15]);
        $mform->setType('amount', PARAM_RAW);
        $mform->addRule('amount', null, 'required', null, 'client');
        $mform->addRule('amount', 'Please enter a valid amount', 'numeric', null, 'client');

        $mform->addElement('textarea', 'description', 'Description', ['rows' => 2, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $feetypes = [
            'tuition' => 'Tuition Fee',
            'materials' => 'Materials Fee',
            'admin' => 'Administration Fee',
            'deposit' => 'Deposit',
            'other' => 'Other Fee',
        ];
        $mform->addElement('select', 'feetype', 'Fee Type', $feetypes);

        $mform->addElement('date_selector', 'paymentdate', 'Payment Date');

        $paymentmethods = [
            'card' => 'Credit/Debit Card',
            'bank' => 'Bank Transfer',
            'cash' => 'Cash',
            'eftpos' => 'EFTPOS',
        ];
        $mform->addElement('select', 'paymentmethod', 'Payment Method', $paymentmethods);

        $mform->addElement('text', 'receiptref', 'Receipt Reference', ['size' => 30]);
        $mform->setType('receiptref', PARAM_TEXT);

        $mform->addElement('header', 'protectionheader', 'Fee Protection ($1,500 Threshold)');

        $mform->addElement('static', 'thresholdwarning', '', 
            '<div class="alert alert-warning" style="margin-bottom: 16px;">
            <strong>ASQA Requirement:</strong> RTOs cannot accept more than $1,500 from a student before training commences unless fee protection arrangements are in place.<br><br>
            <strong>Fee Protection Options:</strong>
            <ul style="margin-bottom: 0;">
                <li><strong>Protected Account:</strong> Fees held in a dedicated account until training delivered</li>
                <li><strong>Bank Guarantee:</strong> Refund secured by a bank guarantee</li>
                <li><strong>Tuition Assurance Scheme (TAS):</strong> Industry fund protects student fees</li>
            </ul></div>');

        $mform->addElement('advcheckbox', 'isprotected', 'Fee Protected', 'This payment is protected under fee protection arrangements');

        $protectionmethods = [
            '' => 'Not applicable',
            'protected_account' => 'Protected Account (RTO holds fees in dedicated account)',
            'bank_guarantee' => 'Bank Guarantee (refund secured by bank guarantee)',
            'tuition_assurance' => 'Tuition Assurance Scheme (TAS membership)',
            'staged_payments' => 'Staged Payments (fees collected after training delivered)',
            'government_funded' => 'Government Funded (fee subsidy applies)',
            'employer_funded' => 'Employer Funded (employer pays on behalf of student)',
        ];
        $mform->addElement('select', 'protectionmethod', 'Protection Method', $protectionmethods);
        $mform->disabledIf('protectionmethod', 'isprotected', 'notchecked');

        $mform->addElement('text', 'protectionreference', 'Protection Reference/Policy Number', ['size' => 40, 'maxlength' => 100, 'placeholder' => 'e.g., TAS-2024-12345 or Account #12345678']);
        $mform->setType('protectionreference', PARAM_TEXT);
        $mform->disabledIf('protectionreference', 'isprotected', 'notchecked');

        $mform->addElement('textarea', 'notes', 'Notes', ['rows' => 3, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, 'Save Fee Record');
    }
}

$form = new feeprotection_form(null, [
    'fee' => $fee,
    'useroptions' => $useroptions,
    'courseoptions' => $courseoptions,
]);

if ($fee) {
    $formdata = clone $fee;
    $form->set_data($formdata);
} elseif ($userid) {
    $form->set_data(['userid' => $userid]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/feeprotection.php'));
} else if ($data = $form->get_data()) {
    $now = time();

    $studentid = 0;
    $student = $DB->get_record('local_rtocompliance_students', ['userid' => $data->userid]);
    if ($student) {
        $studentid = $student->id;
    } else {
        $newstudent = new stdClass();
        $newstudent->userid = $data->userid;
        // Set required AVETMISS defaults for NOT NULL fields
        $newstudent->indigenousstatus = '@';
        $newstudent->countryofbirth = '1101';
        $newstudent->languageathome = '1201';
        $newstudent->englishproficiency = '@';
        $newstudent->disabilityflag = 'N';
        $newstudent->highestschoollevel = '@@';
        $newstudent->atschoolflag = 'N';
        $newstudent->labourforcestatus = '@@';
        $newstudent->studyreason = '@@';
        $newstudent->prioreducationflag = '@';
        $newstudent->surveycontactstatus = 'N';
        $newstudent->profilecomplete = 0;
        $newstudent->usiverified = 0;
        $newstudent->timecreated = $now;
        $newstudent->timemodified = $now;
        $studentid = $DB->insert_record('local_rtocompliance_students', $newstudent);
    }

    $amount = floatval(str_replace(['$', ','], '', $data->amount));

    $record = new stdClass();
    $record->studentid = $studentid;
    $record->userid = $data->userid;
    $record->courseid = $data->courseid ?: null;
    $record->feetype = $data->feetype ?? 'tuition';
    $record->description = $data->description ?? '';
    $record->amount = $amount;
    $record->paymentdate = $data->paymentdate;
    $record->paymentmethod = $data->paymentmethod ?? '';
    $record->receiptref = $data->receiptref ?? '';
    $record->isprotected = $data->isprotected ?? 0;
    $record->protectionmethod = $data->isprotected ? ($data->protectionmethod ?? '') : '';
    $record->thresholdalert = ($amount > 1500) ? 1 : 0;
    // Protection reference stored in notes if no dedicated column
    $protectionNote = '';
    if (!empty($data->isprotected) && !empty($data->protectionreference)) {
        $protectionNote = 'Protection Ref: ' . $data->protectionreference;
    }
    if (!empty($protectionNote)) {
        $record->notes = $protectionNote . (!empty($data->notes) ? "\n" . $data->notes : '');
    } else {
        $record->notes = $data->notes ?? '';
    }
    $record->timemodified = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_fees', $record);
        $message = 'Fee record updated';
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_fees', $record);
        $message = 'Fee record created';
    }

    redirect(
        new moodle_url('/local/rtocompliance/feeprotection.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Fee Record' : 'Add Fee Record', get_string('feeprotection', 'local_rtocompliance'), '/local/rtocompliance/feeprotection.php', 'feeprotection');
echo local_rtocompliance_page_banner($id ? 'Edit Fee Record' : 'Add Fee Record');
echo html_writer::start_div('compliance-container');

if (!$id) {
    echo html_writer::start_div('info-card warning', ['style' => 'margin-bottom: 24px;']);
    echo html_writer::tag('h4', '$1,500 Fee Protection Threshold');
    echo html_writer::tag('p', 'Payments exceeding $1,500 before training commences require fee protection measures. Fees will be automatically flagged if they exceed this threshold without protection selected.', ['style' => 'margin: 0;']);
    echo html_writer::end_div();
} elseif ($fee && $fee->userid) {
    $existingfees = $DB->get_records_sql(
        "SELECT SUM(amount) as total FROM {local_rtocompliance_fees} WHERE userid = ? AND id != ?",
        [$fee->userid, $fee->id]
    );
    $totalexisting = $existingfees ? reset($existingfees)->total : 0;
    
    if ($totalexisting > 0) {
        echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 24px;']);
        echo html_writer::tag('h4', 'Student Fee Summary');
        echo html_writer::tag('p', 
            'This student has $' . number_format($totalexisting, 2) . ' in other recorded fees. ' .
            'Total fees including this record: $' . number_format($totalexisting + $fee->amount, 2) . '.',
            ['style' => 'margin: 0;']
        );
        if ($totalexisting + $fee->amount > 1500 && !$fee->isprotected) {
            echo html_writer::tag('p', 
                '<strong style="color: #dc2626;">Warning:</strong> Total exceeds $1,500 threshold - fee protection required.',
                ['style' => 'margin-top: 8px; margin-bottom: 0;']
            );
        }
        echo html_writer::end_div();
    }
}

$form->display();

echo html_writer::end_div();
echo $OUTPUT->footer();
