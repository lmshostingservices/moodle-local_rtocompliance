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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\form\student_profile_form;
use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\audit_logger;

// Debug mode - set to true to enable diagnostic output
$debug = optional_param('debug', 0, PARAM_INT);

$userid = required_param('userid', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

require_login();

// Allow users to edit their own profile OR require viewall for editing others
$isownprofile = ($userid == $USER->id);
$context = context_system::instance();

if ($debug) {
    debugging("student_profile.php DEBUG: userid=$userid, USER->id={$USER->id}, isownprofile=" . ($isownprofile ? 'true' : 'false'), DEBUG_DEVELOPER);
}

if ($isownprofile) {
    // User editing their own profile - always allowed for authenticated users
    if ($debug) {
        debugging("student_profile.php DEBUG: User editing own profile - allowed", DEBUG_DEVELOPER);
    }
} else {
    // Admin editing another user's profile - require viewall OR manage capability
    $hasviewall = has_capability('local/rtocompliance:viewall', $context);
    $hasmanage = has_capability('local/rtocompliance:manage', $context);
    
    if (!$hasviewall && !$hasmanage) {
        if ($debug) {
            debugging("student_profile.php DEBUG: User lacks viewall/manage capability for userid=$userid", DEBUG_DEVELOPER);
        }
        throw new moodle_exception('nopermissions', 'error', '', get_string('viewall', 'local_rtocompliance'));
    }
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$requiresavetmiss = local_rtocompliance_user_requires_avetmiss($userid);
$nrcourses = local_rtocompliance_get_user_nationally_recognised_courses($userid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $userid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('studentprofile', 'local_rtocompliance') . ': ' . fullname($user));
$PAGE->set_heading(get_string('studentprofile', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add(fullname($user));

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
    $student->labourforcestatus = '@@';
    $student->studyreason = '@@';
    $student->prioreducationflag = '@';
    $student->surveycontactstatus = 'N';
    $student->profilecomplete = 0;
    $student->usiverified = 0;
    $student->timecreated = 0;
    $student->timemodified = 0;
}

$form = new student_profile_form(null, ['student' => $student]);
$form->set_data($student);

if ($form->is_cancelled()) {
    if ($returnurl) {
        redirect(new moodle_url($returnurl));
    } else {
        redirect(new moodle_url('/local/rtocompliance/students.php'));
    }
} else if ($data = $form->get_submitted_data_with_disability_types()) {
    $now = time();
    $iscreate = empty($student->id);
    
    // Only include valid database columns - remove form-only fields
    $validcolumns = [
        // USI-VERIFY-PRESERVE-FIX (v5.9.304): usiverified and usiverifieddate are set
        // explicitly below based on whether the USI changed — never from form POST data.
        'id', 'userid', 'clientid', 'usi',
        'firstname', 'lastname', 'dateofbirth', 'sex', 'indigenousstatus',
        'countryofbirth', 'languageathome', 'englishproficiency', 'disabilityflag',
        'disabilitytypes', 'buildingname', 'unitno', 'streetno', 'streetname',
        'suburb', 'postcode', 'statecode', 'highestschoollevel', 'yearschoolcompleted',
        'priorachevement1', 'priorachevement2', 'priorachevement3', 'priorachevement4',
        'atschoolflag', 'labourforcestatus', 'studyreason', 'prioreducationflag',
        'surveycontactstatus', 'surveycontactemail', 'surveycontactphone',
        'qldlui', 'viccohortid', 'nswsmartskilled', 'waraptid', 'schooltype',
        'profilecomplete', 'validationerrors', 'timecreated', 'timemodified'
        // SCHOOLTYPE-VALIDCOLUMNS-FIX (v5.9.299): schooltype is a DB column shown in the form
        // but was missing from $validcolumns, so it was silently dropped on every admin save.
    ];
    $cleandata = new stdClass();
    foreach ($validcolumns as $col) {
        if (isset($data->$col)) {
            $cleandata->$col = $data->$col;
        }
    }
    $data = $cleandata;
    
    $olddata = $iscreate ? null : [
        'usi' => $student->usi ?? '',
        'dateofbirth' => $student->dateofbirth ?? '',
        'sex' => $student->sex ?? '@',
        'indigenousstatus' => $student->indigenousstatus ?? '@',
        'countryofbirth' => $student->countryofbirth ?? '',
        'languageathome' => $student->languageathome ?? '',
        'suburb' => $student->suburb ?? '',
        'postcode' => $student->postcode ?? '',
        'statecode' => $student->statecode ?? '',
        'profilecomplete' => $student->profilecomplete ?? 0,
    ];

    $validationerrors = validate_student_profile($data);
    $data->profilecomplete = empty($validationerrors) ? 1 : 0;
    $data->validationerrors = !empty($validationerrors) ? json_encode($validationerrors) : null;
    $data->timemodified = $now;
    
    // Ensure required default values for NOT NULL fields
    if (empty($data->indigenousstatus)) {
        $data->indigenousstatus = '@';
    }
    if (empty($data->countryofbirth)) {
        $data->countryofbirth = '1101';
    }
    if (empty($data->languageathome)) {
        $data->languageathome = '1201';
    }
    if (empty($data->englishproficiency)) {
        $data->englishproficiency = '@';
    }
    if (empty($data->disabilityflag)) {
        $data->disabilityflag = 'N';
    }
    if (empty($data->highestschoollevel)) {
        $data->highestschoollevel = '@@';
    }
    if (empty($data->atschoolflag)) {
        $data->atschoolflag = 'N';
    }
    if (empty($data->labourforcestatus)) {
        $data->labourforcestatus = '@@';
    }
    if (empty($data->studyreason)) {
        $data->studyreason = '@@';
    }
    if (empty($data->prioreducationflag)) {
        $data->prioreducationflag = '@';
    }
    if (empty($data->surveycontactstatus)) {
        $data->surveycontactstatus = 'N';
    }

    // DEPENDENT-FIELD-CLEAR-FIX (v5.9.306): Moodle's hideIf is client-side JS only.
    // A hidden form field still submits its cached value, so when an admin toggles a
    // parent flag (e.g. atschoolflag Y→N) the dependent child field silently persists
    // in the DB with a stale value. Since nat_generator.php always reads these fields,
    // stale values corrupt NAT files. Clear them server-side when the parent is off.
    //
    // schooltype → only valid when atschoolflag='Y' (school-based student).
    // NAT00120 maps schooltype (GOV→10/CAT→20/IND→30) at pos 110-111; a stale GOV
    // on a non-school student emits '10' there for every enrolment.
    if (($data->atschoolflag ?? 'N') !== 'Y') {
        $data->schooltype = null;
    }
    // disabilitytypes → only valid when disabilityflag='Y'.
    // NAT00090 guards on disabilityflag='Y', so stale types don't corrupt the current
    // export — but if the flag is ever toggled back to 'Y', old types re-surface.
    if (($data->disabilityflag ?? 'N') !== 'Y') {
        $data->disabilitytypes = null;
    }
    // surveycontactemail / surveycontactphone → only valid when status is 'A' (agrees)
    // or 'E' (valid excuse). Status 'N'/'M' means no contact requested; NAT00085
    // always exports these fields, so they should be blank for non-consenting students.
    if (in_array($data->surveycontactstatus ?? 'N', ['N', 'M'], true)) {
        $data->surveycontactemail = null;
        $data->surveycontactphone = null;
    }

    // USI-VERIFY-PRESERVE-FIX (v5.9.304): the form has no usiverified input, so
    // the old `if (!isset) → 0` path was ALWAYS firing, silently resetting every
    // verified student back to usiverified=0 the moment an admin clicked Save Profile.
    // Rule: if the USI value itself is unchanged, preserve the existing verification
    // status and date from the DB row captured in $student above.
    // If the admin entered a different USI, reset to 0 — the new code is unverified.
    // On new student create, always start unverified.
    if ($iscreate) {
        $data->usiverified    = 0;
        $data->usiverifieddate = null;
    } else {
        $usichanged = trim((string)($data->usi ?? '')) !== trim((string)($student->usi ?? ''));
        if ($usichanged) {
            // New USI entered — strip old verification.
            $data->usiverified    = 0;
            $data->usiverifieddate = null;
        } else {
            // USI unchanged — preserve whatever the registry verified.
            $data->usiverified    = (int)($student->usiverified ?? 0);
            $data->usiverifieddate = $student->usiverifieddate ?? null;
        }
    }

    if (!$iscreate) {
        $DB->update_record('local_rtocompliance_students', $data);
        $message = get_string('profileupdated', 'local_rtocompliance');
    } else {
        // Remove id=0 for new record (auto-generated)
        unset($data->id);
        $data->timecreated = $now;
        $data->id = $DB->insert_record('local_rtocompliance_students', $data);
        $message = get_string('profilecreated', 'local_rtocompliance');
    }

    $newdata = [
        'usi' => $data->usi ?? '',
        'dateofbirth' => $data->dateofbirth ?? '',
        'sex' => $data->sex ?? '@',
        'indigenousstatus' => $data->indigenousstatus ?? '@',
        'countryofbirth' => $data->countryofbirth ?? '',
        'languageathome' => $data->languageathome ?? '',
        'suburb' => $data->suburb ?? '',
        'postcode' => $data->postcode ?? '',
        'statecode' => $data->statecode ?? '',
        'profilecomplete' => $data->profilecomplete,
    ];

    if ($iscreate) {
        audit_logger::log_create(
            audit_logger::ENTITY_STUDENT,
            $data->id,
            'Student profile created: User ID ' . $userid . ', USI: ' . ($data->usi ?? 'N/A'),
            $newdata
        );
    } else {
        audit_logger::log_update(
            audit_logger::ENTITY_STUDENT,
            $data->id,
            'Student profile updated: User ID ' . $userid . ', USI: ' . ($data->usi ?? 'N/A'),
            $olddata,
            $newdata
        );
    }

    // Auto-verify USI immediately if USI + DOB are both present and verification is enabled.
    // Non-fatal — profile is saved regardless of whether verification succeeds.
    if (!empty($data->usi) && !empty($data->dateofbirth)
        && get_config('local_rtocompliance', 'usi_verification_enabled')) {
        try {
            require_once(__DIR__ . '/classes/usi/usi_verification_service.php');
            $usiservice = new \local_rtocompliance\usi\usi_verification_service();
            $vstatus = $usiservice->is_service_available();
            if ($vstatus['available']) {
                $vresult = $usiservice->verify_student_usi($data->id);
                if (!empty($vresult['success'])) {
                    $message .= ' ' . get_string('usi_auto_verified', 'local_rtocompliance');
                }
            }
        } catch (\Throwable $e) {
            debugging('USI auto-verify on save failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    if ($returnurl) {
        redirect(new moodle_url($returnurl), $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect(new moodle_url('/local/rtocompliance/students.php'), $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

function validate_student_profile($data) {
    $errors = [];

    if (empty($data->usi)) {
        $errors[] = ['field' => 'usi', 'message' => get_string('error_usi_required', 'local_rtocompliance')];
    } else {
        $result = avetmiss_codes::validate_usi($data->usi);
        if (!$result['valid']) {
            $errors[] = ['field' => 'usi', 'message' => $result['error']];
        }
    }

    if (empty($data->dateofbirth)) {
        $errors[] = ['field' => 'dateofbirth', 'message' => get_string('error_dob_required', 'local_rtocompliance')];
    }

    if (empty($data->sex) || $data->sex === '@') {
        $errors[] = ['field' => 'sex', 'message' => get_string('error_sex_required', 'local_rtocompliance')];
    }

    if (empty($data->postcode)) {
        $errors[] = ['field' => 'postcode', 'message' => get_string('error_postcode_required', 'local_rtocompliance')];
    }

    if (empty($data->statecode)) {
        $errors[] = ['field' => 'statecode', 'message' => get_string('error_state_required', 'local_rtocompliance')];
    }

    if (empty($data->suburb)) {
        $errors[] = ['field' => 'suburb', 'message' => get_string('error_suburb_required', 'local_rtocompliance')];
    }

    // PROFILECOMPLETE-ALIGN-FIX (v5.9.299): admin validate_student_profile() was checking only
    // 6 fields while my_profile.php (student self-service) checked 11. A profile marked
    // "complete" by admin would flip back to "incomplete" as soon as the student saved it,
    // because the 5 extra AVETMISS-required fields weren't in the admin validation.
    // Align both paths to the same 11-field AVETMISS definition.
    if (empty($data->indigenousstatus) || $data->indigenousstatus === '@' || $data->indigenousstatus === '@@') {
        $errors[] = ['field' => 'indigenousstatus', 'message' => get_string('error_indigenous_required', 'local_rtocompliance')];
    }

    if (empty($data->countryofbirth)) {
        $errors[] = ['field' => 'countryofbirth', 'message' => get_string('error_countryofbirth_required', 'local_rtocompliance')];
    }

    if (empty($data->languageathome)) {
        $errors[] = ['field' => 'languageathome', 'message' => get_string('error_languageathome_required', 'local_rtocompliance')];
    }

    // SENTINEL-FIX (v5.9.306): labourforcestatus and highestschoollevel use '@@'
    // (double-@) as their AVETMISS "not stated" sentinel, NOT single '@'.
    // The previous checks tested for '@' only, which never matched — so a student
    // with the factory defaults ('@@' for both) passed admin validation and was
    // marked profilecomplete=1. When the student then saved via my_profile.php
    // (which correctly checks for '@@'), the profile flipped back to incomplete.
    // Fix: test for '@@' as the primary not-stated value; also accept '@' so we
    // are robust to any corrupted single-@ values already in the DB.
    if (empty($data->labourforcestatus) || $data->labourforcestatus === '@@' || $data->labourforcestatus === '@') {
        $errors[] = ['field' => 'labourforcestatus', 'message' => get_string('error_labourforcestatus_required', 'local_rtocompliance')];
    }

    if (empty($data->highestschoollevel) || $data->highestschoollevel === '@@' || $data->highestschoollevel === '@') {
        $errors[] = ['field' => 'highestschoollevel', 'message' => get_string('error_highestschoollevel_required', 'local_rtocompliance')];
    }

    return $errors;
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('studentprofile', 'local_rtocompliance'), get_string('students', 'local_rtocompliance'), '/local/rtocompliance/students.php', 'students');

$studentinfo = new stdClass();
$studentinfo->fullname = fullname($user);
$studentinfo->email = $user->email;
$studentinfo->profilecomplete = !empty($student->id) && $student->profilecomplete;
$studentinfo->validationerrors = [];

if (!empty($student->validationerrors)) {
    $studentinfo->validationerrors = json_decode($student->validationerrors, true) ?: [];
}

echo $OUTPUT->heading(fullname($user), 3);

if (!empty($nrcourses)) {
    $courselisthtml = '<strong>' . get_string('avetmiss_required_courses', 'local_rtocompliance') . '</strong><ul>';
    foreach ($nrcourses as $nrcourse) {
        $qualinfo = !empty($nrcourse->qualificationcode) ? " ({$nrcourse->qualificationcode})" : '';
        $courselisthtml .= '<li>' . format_string($nrcourse->fullname) . $qualinfo . '</li>';
    }
    $courselisthtml .= '</ul>';
    echo $OUTPUT->notification(
        get_string('avetmiss_required_notice', 'local_rtocompliance') . '<br>' . $courselisthtml,
        \core\output\notification::NOTIFY_INFO
    );
} else {
    echo $OUTPUT->notification(
        get_string('no_avetmiss_required', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_INFO
    );
}

if (!empty($studentinfo->validationerrors)) {
    echo $OUTPUT->notification(
        get_string('profileincomplete', 'local_rtocompliance') . 
        '<ul><li>' . implode('</li><li>', array_column($studentinfo->validationerrors, 'message')) . '</li></ul>',
        \core\output\notification::NOTIFY_WARNING
    );
} else if ($studentinfo->profilecomplete) {
    echo $OUTPUT->notification(
        get_string('profilecomplete_msg', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form->display();

echo $OUTPUT->footer();
