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
 * RTO Compliance plugin — my_profile.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\form\student_profile_form;
use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\audit_logger;

require_login();

$userid = $USER->id;
$context = context_user::instance($userid);
require_capability('local/rtocompliance:editownprofile', $context);

if (!local_rtocompliance_user_requires_avetmiss($userid)) {
    redirect(
        new moodle_url('/user/profile.php'),
        get_string('noavetmissrequired', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rtocompliance/my_profile.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myavetmissprofile', 'local_rtocompliance'));
$PAGE->set_heading(get_string('myavetmissprofile', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('myavetmissprofile', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);

if (!$student) {
    $student = new stdClass();
    $student->id = 0;
    $student->userid = $userid;
    $student->indigenousstatus = '@';
    $student->countryofbirth = '1101';
    $student->languageathome = '1201';
    $student->englishproficiency = '@';
    $student->disabilityflag = 'N';
    $student->highestschoollevel = '@@';
    $student->atschoolflag = 'N';
    $student->surveycontactstatus = 'N';
    $student->profilecomplete = 0;
    
    // POSTCODE-PREFILL-FIX (v6.3.0): $USER->city is a suburb NAME, so copying it into
    // postcode pre-filled an invalid value into a 4-digit column — with the postcode
    // now gate-mandatory that produced a "must be 4 digits" error on a field the
    // student had never touched. Only the suburb is pre-filled.
    $student->suburb = $USER->city ?: '';
    $student->postcode = '';
}

// PROFILE-GATE (v6.3.0): when the gate is on and this student still has mandatory
// AVETMISS fields outstanding, the page is rendered in LOCKED mode — the missing
// fields become hard form requirements, the Cancel button is removed (there is
// nowhere to cancel to; every other page redirects straight back here), and the
// banner explains exactly what is outstanding and why it is being asked for.
$gatemissing = local_rtocompliance_get_missing_avetmiss_fields($userid, $student);
$gatelocked  = (local_rtocompliance_profile_gate_applies($userid) !== false);

$form = new student_profile_form(null, [
    'student'       => $student,
    'selfservice'   => true,
    'lockmode'      => $gatelocked,
    'requiredfields' => $gatelocked ? local_rtocompliance_avetmiss_mandatory_fields() : [],
]);
$form->set_data($student);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/user/profile.php'));
} else if ($data = $form->get_submitted_data_with_disability_types()) {
    $now = time();
    $iscreate = empty($student->id);
    
    $data->userid = $userid;
    $data->timemodified = $now;
    
    // BUG-5 FIX: Expanded mandatory fields to include all AVETMISS-required fields.
    // Checking only 6 of 11 required fields caused students to be incorrectly marked
    // "complete" by self-service while still failing admin AVETMISS validation.
    //
    // SHARED-DEFINITION (v6.3.0): the 11-field list and the "is this value actually
    // answered?" rule now live in lib.php
    // (local_rtocompliance_calculate_profilecomplete), so staff edits, student
    // self-service and the login gate can never disagree about what "complete"
    // means — previously each path carried its own copy of the list and they drifted.
    $data->profilecomplete = local_rtocompliance_calculate_profilecomplete($data);

    // BUG-4 FIX: Filter to only DB columns before write. Passing raw form data (which
    // includes submitbutton, sesskey, and other non-DB form fields) to update_record()
    // or insert_record() causes a DML exception. Mirror student_profile.php validcolumns.
    $validcolumns = [
        // USI-VERIFY-PRESERVE-FIX (v5.9.304): usiverified and usiverifieddate must never
        // come from a student-submitted form — students cannot self-certify verification.
        // They are set explicitly below. Removing from validcolumns also closes the
        // security hole where a crafted POST could reset usiverified to 0 or 1.
        'id', 'userid', 'clientid', 'usi',
        'firstname', 'lastname', 'dateofbirth', 'sex', 'indigenousstatus',
        'countryofbirth', 'languageathome', 'englishproficiency', 'disabilityflag',
        'disabilitytypes', 'buildingname', 'unitno', 'streetno', 'streetname',
        'suburb', 'postcode', 'statecode', 'highestschoollevel', 'yearschoolcompleted',
        'priorachevement1', 'priorachevement2', 'priorachevement3', 'priorachevement4',
        'atschoolflag', 'labourforcestatus', 'studyreason', 'prioreducationflag',
        'surveycontactstatus', 'surveycontactemail', 'surveycontactphone',
        'qldlui', 'viccohortid', 'nswsmartskilled', 'waraptid', 'schooltype',
        'profilecomplete', 'validationerrors', 'timecreated', 'timemodified',
        // SCHOOLTYPE-VALIDCOLUMNS-FIX (v5.9.299): schooltype was missing from both validcolumns
        // lists (student_profile.php and my_profile.php), silently dropped on every save.
    ];
    $cleandata = new stdClass();
    foreach ($validcolumns as $col) {
        if (isset($data->$col)) {
            $cleandata->$col = $data->$col;
        }
    }
    $data = $cleandata;

    // DEPENDENT-FIELD-CLEAR-FIX (v5.9.306): Same as student_profile.php.
    // hideIf is client-side only — clear dependent fields server-side so stale
    // cached values from previously-selected states don't persist in the DB.
    if (($data->atschoolflag ?? 'N') !== 'Y') {
        $data->schooltype = null;
    }
    if (($data->disabilityflag ?? 'N') !== 'Y') {
        $data->disabilitytypes = null;
    }
    if (in_array($data->surveycontactstatus ?? 'N', ['N', 'M'], true)) {
        $data->surveycontactemail = null;
        $data->surveycontactphone = null;
    }

    // USI-VERIFY-PRESERVE-FIX (v5.9.304): same preserve logic as student_profile.php.
    // If the student changed their own USI value, reset verification (new USI unverified).
    // If unchanged, restore the existing status so self-service saves don't strip it.
    if ($iscreate) {
        $data->usiverified    = 0;
        $data->usiverifieddate = null;
    } else {
        $usichanged = trim((string)($data->usi ?? '')) !== trim((string)($student->usi ?? ''));
        if ($usichanged) {
            $data->usiverified    = 0;
            $data->usiverifieddate = null;
        } else {
            $data->usiverified    = (int)($student->usiverified ?? 0);
            $data->usiverifieddate = $student->usiverifieddate ?? null;
        }
    }

    if (!$iscreate) {
        $data->id = $student->id;
        $DB->update_record('local_rtocompliance_students', $data);
        
        audit_logger::log_update(
            audit_logger::ENTITY_STUDENT,
            $data->id,
            'Student self-updated AVETMISS profile: User ' . $userid,
            [],
            ['profile_complete' => $data->profilecomplete, 'usi' => !empty($data->usi)]
        );
    } else {
        $data->timecreated = $now;
        $data->id = $DB->insert_record('local_rtocompliance_students', $data);
        
        audit_logger::log_create(
            audit_logger::ENTITY_STUDENT,
            $data->id,
            'Student self-created AVETMISS profile: User ' . $userid,
            ['profile_complete' => $data->profilecomplete]
        );
    }
    
    // GATE-RELEASE (v6.3.0): if this save completed the profile, let the student
    // straight through to wherever they were originally headed when the gate
    // caught them (or their dashboard), with a clear "you're done" message —
    // rather than dumping them back on the form they just finished.
    if (!empty($data->profilecomplete)) {
        $returnurl = new moodle_url('/my/');
        if (!empty($SESSION->local_rtocompliance_gate_return)) {
            try {
                $returnurl = new moodle_url($SESSION->local_rtocompliance_gate_return);
            } catch (\Throwable $e) {
                $returnurl = new moodle_url('/my/');
            }
            unset($SESSION->local_rtocompliance_gate_return);
        }
        redirect(
            $returnurl,
            get_string('avetmiss_profile_unlocked', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    redirect(
        new moodle_url('/local/rtocompliance/my_profile.php', ['prompt' => 1]),
        get_string('profilesaved', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('myavetmissprofile', 'local_rtocompliance'));
echo local_rtocompliance_page_banner(get_string('myavetmissprofile', 'local_rtocompliance'));

echo html_writer::start_div('compliance-container');

$nrcourses = local_rtocompliance_get_user_nationally_recognised_courses($userid);
if (!empty($nrcourses)) {
    echo html_writer::start_div('info-card', ['style' => 'margin-bottom: 24px;']);
    echo html_writer::tag('h4', get_string('enrolledin_accredited', 'local_rtocompliance'), ['style' => 'margin: 0 0 12px 0;']);
    echo html_writer::tag('p', get_string('avetmiss_required_explanation', 'local_rtocompliance'), ['style' => 'margin: 0 0 12px 0; color: #666;']);
    echo '<ul style="margin: 0; padding-left: 20px;">';
    foreach ($nrcourses as $course) {
        $qualinfo = '';
        if (!empty($course->qualificationcode)) {
            $qualinfo = ' (' . $course->qualificationcode . ')';
        }
        echo '<li>' . s($course->fullname) . $qualinfo . '</li>';
    }
    echo '</ul>';
    echo html_writer::end_div();
}

$isprompt = optional_param('prompt', 0, PARAM_INT);

if ($gatelocked) {
    // LOCKED (v6.3.0): the student cannot reach any other page until these fields
    // are answered, so the banner has to do three things — say plainly that access
    // is held, list exactly what is outstanding, and explain why it is required.
    echo html_writer::start_div('alert alert-danger', ['style' => 'margin-bottom: 24px;', 'role' => 'alert']);
    echo html_writer::tag('h4',
        '&#128274; ' . get_string('avetmiss_profile_locked_title', 'local_rtocompliance'),
        ['style' => 'margin: 0 0 10px 0; font-size: 1.15em;']
    );
    echo html_writer::tag('p',
        get_string('avetmiss_profile_locked_body', 'local_rtocompliance'),
        ['style' => 'margin: 0 0 12px 0;']
    );

    if (!empty($gatemissing)) {
        echo html_writer::tag('p',
            html_writer::tag('strong', get_string('avetmiss_still_needed', 'local_rtocompliance')),
            ['style' => 'margin: 0 0 6px 0;']
        );
        echo '<ul style="margin: 0 0 12px 0; padding-left: 22px;">';
        foreach ($gatemissing as $label) {
            echo '<li>' . s($label) . '</li>';
        }
        echo '</ul>';
    }

    echo html_writer::tag('p',
        get_string('avetmiss_profile_locked_footer', 'local_rtocompliance'),
        ['style' => 'margin: 0; font-size: 0.95em; opacity: .9;']
    );
    echo html_writer::end_div();
} else if ($isprompt && !$student->profilecomplete) {
    // Arrived here via the login-time redirect — show an action-required banner
    // that explains WHY the profile is needed, not just that it's incomplete.
    echo html_writer::start_div('alert alert-danger', ['style' => 'margin-bottom: 24px;', 'role' => 'alert']);
    echo html_writer::tag('h4',
        html_writer::tag('span', '&#9888; ', []) .
        get_string('avetmiss_profile_prompt_title', 'local_rtocompliance'),
        ['style' => 'margin: 0 0 10px 0; font-size: 1.1em;']
    );
    echo html_writer::tag('p',
        get_string('avetmiss_profile_prompt_body', 'local_rtocompliance'),
        ['style' => 'margin: 0;']
    );
    echo html_writer::end_div();
} else if (!$student->profilecomplete) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('profile_incomplete_warning', 'local_rtocompliance')),
        'alert alert-warning',
        ['style' => 'margin-bottom: 20px;']
    );
}

$form->display();

echo html_writer::end_div();

echo $OUTPUT->footer();
