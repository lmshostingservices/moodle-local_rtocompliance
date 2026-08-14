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
 * RTO Compliance plugin — survey_send.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$type = required_param('type', PARAM_ALPHA);

if (!in_array($type, ['learner', 'employer'])) {
    throw new moodle_exception('invalidsurveytype', 'local_rtocompliance');
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/survey_send.php', ['type' => $type]));
$PAGE->set_title('Send ' . ucfirst($type) . ' Survey');
$PAGE->navbar->add(get_string('surveys', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/surveys.php'));
$PAGE->navbar->add('Send ' . ucfirst($type) . ' Survey');

class survey_send_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $type = $this->_customdata['type'];

        $mform->addElement('hidden', 'type', $type);
        $mform->setType('type', PARAM_ALPHA);

        $mform->addElement('header', 'recipientheader', 'Survey Recipients');

        if ($type === 'learner') {
            $sendoptions = [
                'all_active' => 'All active students',
                'completed_current_year' => 'Students who completed training this year',
                'specific_course' => 'Students in a specific course',
                'manual' => 'Enter email addresses manually',
            ];
        } else {
            $sendoptions = [
                'employer_contacts' => 'Registered employer contacts',
                'manual' => 'Enter email addresses manually',
            ];
        }
        $mform->addElement('select', 'sendto', 'Send To', $sendoptions);

        if ($type === 'learner') {
            global $DB;
            $courses = $DB->get_records_sql(
                "SELECT c.id, c.fullname, c.shortname
                 FROM {course} c
                 WHERE c.id > 1 AND c.visible = 1
                 ORDER BY c.fullname",
                [],
                0,
                500
            );
            $courseoptions = ['' => '-- Select a course --'];
            foreach ($courses as $course) {
                $courseoptions[$course->id] = $course->shortname . ': ' . $course->fullname;
            }
            $mform->addElement('select', 'courseid', 'Select Course', $courseoptions);
            $mform->hideIf('courseid', 'sendto', 'neq', 'specific_course');
        }

        $mform->addElement('textarea', 'manualemails', 'Email Addresses (one per line)', ['rows' => 5, 'cols' => 60]);
        $mform->setType('manualemails', PARAM_TEXT);
        $mform->disabledIf('manualemails', 'sendto', 'neq', 'manual');

        $mform->addElement('header', 'messageheader', 'Email Message');

        $mform->addElement('text', 'subject', 'Email Subject', ['size' => 80]);
        $mform->setType('subject', PARAM_TEXT);
        if ($type === 'learner') {
            $mform->setDefault('subject', 'Quality Indicator Survey - Your Feedback Matters');
        } else {
            $mform->setDefault('subject', 'Employer Satisfaction Survey - Your Feedback Matters');
        }

        $mform->addElement('editor', 'message', 'Email Message', ['rows' => 10]);
        $mform->setType('message', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — Moodle editor/textarea field; PARAM_RAW is the correct type for rich-text content, which is escaped on output by format_text()

        $defaultmessage = '<p>Dear {FIRSTNAME},</p>';
        if ($type === 'learner') {
            $defaultmessage .= '<p>We value your feedback on your training experience. Please take a few minutes to complete our Quality Indicator survey.</p>';
            $defaultmessage .= '<p>Your responses help us improve our training and assessment services.</p>';
        } else {
            $defaultmessage .= '<p>We value your feedback on the quality of training provided to your employees. Please take a few minutes to complete our Employer Satisfaction survey.</p>';
            $defaultmessage .= '<p>Your responses help us ensure our training meets industry needs.</p>';
        }
        $defaultmessage .= '<p><a href="{SURVEY_LINK}">Click here to complete the survey</a></p>';
        $defaultmessage .= '<p>This survey is confidential and takes approximately 5 minutes to complete.</p>';
        $defaultmessage .= '<p>Thank you for your time.</p>';

        $mform->setDefault('message', ['text' => $defaultmessage, 'format' => FORMAT_HTML]);

        // v5.9.381: removed the "Send Reminder" and "Survey Expires On" options —
        // they were never implemented (no reminder was ever sent and the link
        // never expired), so they promised behaviour the code did not deliver.
        $this->add_action_buttons(true, 'Send Surveys');
    }
}

$form = new survey_send_form(null, ['type' => $type]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/surveys.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    $year = date('Y');
    $sentcount = 0;
    $errors = [];

    $recipients = [];

    if ($data->sendto === 'manual') {
        $emails = explode("\n", $data->manualemails);
        foreach ($emails as $email) {
            $email = trim($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = ['email' => $email, 'firstname' => '', 'lastname' => ''];
            }
        }
    } elseif ($data->sendto === 'all_active') {
        $users = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname
             FROM {user} u
             JOIN {user_enrolments} ue ON ue.userid = u.id
             JOIN {enrol} e ON e.id = ue.enrolid
             WHERE u.deleted = 0 AND u.suspended = 0 AND ue.status = 0
             ORDER BY u.lastname, u.firstname",
            [],
            0,
            1000
        );
        foreach ($users as $u) {
            $recipients[] = ['email' => $u->email, 'firstname' => $u->firstname, 'lastname' => $u->lastname, 'userid' => $u->id];
        }
    } elseif ($data->sendto === 'completed_current_year') {
        $yearstart = strtotime($year . '-01-01');
        $users = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname
             FROM {user} u
             JOIN {course_completions} cc ON cc.userid = u.id
             WHERE u.deleted = 0 AND cc.timecompleted >= ?
             ORDER BY u.lastname, u.firstname",
            [$yearstart],
            0,
            1000
        );
        foreach ($users as $u) {
            $recipients[] = ['email' => $u->email, 'firstname' => $u->firstname, 'lastname' => $u->lastname, 'userid' => $u->id];
        }
    } elseif ($data->sendto === 'specific_course' && !empty($data->courseid)) {
        $users = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.email, u.firstname, u.lastname
             FROM {user} u
             JOIN {user_enrolments} ue ON ue.userid = u.id
             JOIN {enrol} e ON e.id = ue.enrolid
             WHERE u.deleted = 0 AND u.suspended = 0 AND ue.status = 0 AND e.courseid = ?
             ORDER BY u.lastname, u.firstname",
            [$data->courseid],
            0,
            1000
        );
        foreach ($users as $u) {
            $recipients[] = ['email' => $u->email, 'firstname' => $u->firstname, 'lastname' => $u->lastname, 'userid' => $u->id];
        }
    } elseif ($data->sendto === 'employer_contacts') {
        if ($DB->get_manager()->table_exists('local_rtocompliance_thirdparty')) {
            $contacts = $DB->get_records_sql(
                "SELECT id, contactname, contactemail
                 FROM {local_rtocompliance_thirdparty}
                 WHERE contactemail IS NOT NULL AND contactemail != ''
                   AND status = 'active'
                 ORDER BY organisationname",
                [],
                0,
                500
            );
            foreach ($contacts as $c) {
                if (filter_var($c->contactemail, FILTER_VALIDATE_EMAIL)) {
                    $nameParts = explode(' ', trim($c->contactname ?? ''), 2);
                    $recipients[] = [
                        'email'     => $c->contactemail,
                        'firstname' => $nameParts[0] ?? '',
                        'lastname'  => $nameParts[1] ?? '',
                    ];
                }
            }
        }
        if (empty($recipients)) {
            redirect(
                new moodle_url('/local/rtocompliance/surveys.php'),
                'No active employer contacts with email addresses found. Add contacts in the Third-Party Arrangements register first.',
                null,
                \core\output\notification::NOTIFY_WARNING
            );
            return;
        }
    }

    foreach ($recipients as $recipient) {
        $accesstoken = bin2hex(random_bytes(32));

        $survey = new stdClass();
        $survey->surveytype = $type;
        $survey->respondentid = $recipient['userid'] ?? null;
        $survey->respondentname = trim($recipient['firstname'] . ' ' . $recipient['lastname']);
        $survey->respondentemail = $recipient['email'];
        $survey->responses = '';
        $survey->year = $year;
        $survey->accesstoken = $accesstoken;
        // PROBLEM 7 FIX: use 'sent' so dashboard stats (which count status='sent') are accurate.
        // 'pending' was inserted before but surveys.php counts 'sent', so count was always 0.
        $survey->status = 'sent';
        $survey->timecreated = $now;

        $surveyid = $DB->insert_record('local_rtocompliance_surveys', $survey);

        $surveylink = new moodle_url('/local/rtocompliance/survey_respond.php', ['token' => $accesstoken]);

        $messagetext = $data->message['text'];
        $messagetext = str_replace('{FIRSTNAME}', $recipient['firstname'] ?: 'Participant', $messagetext);
        $messagetext = str_replace('{SURVEY_LINK}', $surveylink->out(false), $messagetext);

        $eventdata = new \core\message\message();
        $eventdata->component = 'local_rtocompliance';
        $eventdata->name = 'survey_invitation';
        $eventdata->userfrom = \core_user::get_noreply_user();
        $eventdata->subject = $data->subject;
        $eventdata->fullmessage = html_to_text($messagetext);
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $messagetext;
        $eventdata->smallmessage = $data->subject;

        if (!empty($recipient['userid'])) {
            $userto = \core_user::get_user($recipient['userid']);
            if (!$userto || !empty($userto->deleted)) {
                $errors[] = $recipient['email'] . ': User not found or deleted';
                continue;
            }
            $eventdata->userto = $userto;
            try {
                message_send($eventdata);
                $sentcount++;
            } catch (Exception $e) {
                $errors[] = $recipient['email'] . ': ' . $e->getMessage();
            }
        } else {
            // External recipient (no Moodle account) — use email_to_user() to avoid
            // message_send() errors: "Attempt to read property 'id'/'emailstop' on bool"
            $tempuser                    = new stdClass();
            $tempuser->id                = -99;
            $tempuser->email             = $recipient['email'];
            $tempuser->firstname         = $recipient['firstname'] ?: 'Participant';
            $tempuser->lastname          = $recipient['lastname'] ?: '';
            $tempuser->firstnamephonetic = '';
            $tempuser->lastnamephonetic  = '';
            $tempuser->middlename        = '';
            $tempuser->alternatename     = '';
            $tempuser->mailformat        = 1;
            $tempuser->emailstop         = 0;
            $tempuser->maildisplay       = 1;
            $tempuser->auth              = 'manual';
            $tempuser->confirmed         = 1;
            $tempuser->suspended         = 0;
            $tempuser->deleted           = 0;
            $tempuser->mnethostid        = $CFG->mnet_localhost_id ?? 1;
            $tempuser->lang              = current_language();
            $tempuser->timezone          = '99';
            $noreply = \core_user::get_noreply_user();
            $result = email_to_user(
                $tempuser,
                $noreply,
                $data->subject,
                html_to_text($messagetext),
                $messagetext
            );
            if ($result) {
                $sentcount++;
            } else {
                $errors[] = $recipient['email'] . ': Email delivery failed';
            }
        }
    }

    if ($sentcount > 0) {
        $message = "Survey invitations sent to $sentcount recipients.";
        if (!empty($errors)) {
            $message .= ' ' . count($errors) . ' failed.';
        }
        redirect(
            new moodle_url('/local/rtocompliance/surveys.php'),
            $message,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/local/rtocompliance/surveys.php'),
            'No survey invitations were sent. Please check recipient selection.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Send ' . ucfirst($type) . ' Survey', get_string('surveys', 'local_rtocompliance'), '/local/rtocompliance/surveys.php', 'surveys');
echo local_rtocompliance_page_banner('Send ' . ucfirst($type) . ' Survey');
echo html_writer::start_div('compliance-container');
echo $OUTPUT->heading('Send ' . ucfirst($type) . ' Quality Indicator Survey');

echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 24px;']);
if ($type === 'learner') {
    echo html_writer::tag('p', 'Recipients receive the standard AQTF Learner Questionnaire (LQ). Its items feed the Learner Engagement and Competency Development quality indicators, each answered on the 4-point agreement scale (Strongly Disagree to Strongly Agree).', ['style' => 'margin: 0;']);
} else {
    echo html_writer::tag('p', 'Recipients receive the standard AQTF Employer Questionnaire (EQ). Its items feed the Employer Satisfaction quality indicator, each answered on the 4-point agreement scale (Strongly Disagree to Strongly Agree).', ['style' => 'margin: 0;']);
}
echo html_writer::end_div();

$form->display();

echo html_writer::end_div();
echo $OUTPUT->footer();
