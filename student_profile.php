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
 * RTO Compliance plugin — student_profile.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
    // WRITE-AUTH (v5.9.415): viewing another student's profile only requires the
    // read capability (viewall), but SAVING must require the write capability
    // (manage) — otherwise a read-only auditor with viewall could edit student
    // records. Editing your own profile stays allowed. (Moodle's form sesskey
    // already guards CSRF; this guards authorization.)
    if (!$isownprofile && !has_capability('local/rtocompliance:manage', $context)) {
        throw new moodle_exception('nopermissions', 'error', '',
            'Editing another student\'s profile requires the manage capability.');
    }
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

    // SHARED-DEFINITION (v6.3.0): profilecomplete is computed by
    // local_rtocompliance_calculate_profilecomplete() — the same function the student
    // self-service form uses — so staff saves and student saves can never disagree
    // about whether a profile is complete.
    //
    // It is deliberately calculated HERE, after the NOT NULL sentinel defaults above
    // have been applied, so the flag describes the row as it is actually written to
    // the database. Calculating it earlier made a saved row look incomplete purely
    // because a blank field had not yet been defaulted.
    $data->profilecomplete = local_rtocompliance_calculate_profilecomplete($data);

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
echo local_rtocompliance_page_banner(get_string('studentprofile', 'local_rtocompliance'));

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

// ============================================================================
// WORLD-CLASS READ-ONLY STUDENT SUMMARY
// Rendered ABOVE the edit form so admins see the full profile, results and
// certificates at a glance on load. All DB reads are guarded for the
// new-profile case ($student->id === 0). Coded AVETMISS fields are rendered as
// human labels via \local_rtocompliance\avetmiss_codes. The editable form
// remains below this block, unchanged.
// ============================================================================

$isnewprofile = empty($student->id);

// --- Human-label helper for coded AVETMISS fields --------------------------
// Placeholder values (@, @@, @@@@, blank) render as a muted "Not stated".
$avlabel = function ($value, array $map): array {
    $v = trim((string)($value ?? ''));
    if ($v === '' || $v === '@' || $v === '@@' || $v === '@@@@') {
        return ['text' => 'Not stated', 'muted' => true];
    }
    if (isset($map[$v])) {
        return ['text' => $map[$v], 'muted' => false];
    }
    return ['text' => $v, 'muted' => false];
};

// --- Load code maps once ---------------------------------------------------
$map_sex      = avetmiss_codes::get_sex_codes();
$map_indig    = avetmiss_codes::get_indigenous_status_codes();
$map_lang     = avetmiss_codes::get_language_codes();
$map_country  = avetmiss_codes::get_country_codes();
$map_labour   = avetmiss_codes::get_labour_force_status_codes();
$map_school   = avetmiss_codes::get_school_level_codes();
$map_state    = avetmiss_codes::get_state_codes();
$map_disab    = avetmiss_codes::get_disability_codes();
$map_atschool = avetmiss_codes::get_at_school_flag_codes();
$map_prioredu = avetmiss_codes::get_prior_education_flag_codes();

// --- Styling ---------------------------------------------------------------
echo '<style>
.rtoc-profile-summary { margin-bottom: 28px; }
.rtoc-profile-summary .card { border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(15,23,42,.06); margin-bottom:20px; }
.rtoc-profile-summary .card-header { background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:12px 12px 0 0; font-weight:600; font-size:14px; color:#0f172a; display:flex; align-items:center; gap:8px; padding:12px 18px; }
.rtoc-profile-summary .card-body { padding:18px; }
.rtoc-hero { display:flex; flex-wrap:wrap; align-items:center; gap:18px; padding:22px 24px; background:linear-gradient(135deg,#1e3a8a 0%,#3b82f6 100%); border-radius:12px; color:#fff; box-shadow:0 4px 14px rgba(30,58,138,.25); margin-bottom:20px; }
.rtoc-hero-avatar { flex-shrink:0; width:64px; height:64px; border-radius:50%; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:700; letter-spacing:.5px; }
.rtoc-hero-main { flex:1 1 260px; min-width:220px; }
.rtoc-hero-name { font-size:22px; font-weight:700; line-height:1.2; margin:0 0 4px; }
.rtoc-hero-sub { font-size:13px; opacity:.9; margin:0; }
.rtoc-hero-meta { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.rtoc-pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:5px 12px; border-radius:999px; line-height:1; }
.rtoc-pill-green { background:#dcfce7; color:#166534; }
.rtoc-pill-amber { background:#fef3c7; color:#92400e; }
.rtoc-pill-grey  { background:#e2e8f0; color:#475569; }
.rtoc-pill-white { background:rgba(255,255,255,.9); color:#1e3a8a; }
.rtoc-missing-list { margin:14px 0 0; padding:12px 16px; background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; }
.rtoc-missing-list strong { color:#92400e; font-size:13px; }
.rtoc-missing-list ul { margin:6px 0 0; padding-left:20px; }
.rtoc-missing-list li { font-size:13px; color:#78350f; line-height:1.6; }
.rtoc-demo-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:2px 28px; }
.rtoc-demo-item { display:flex; flex-direction:column; padding:9px 2px; border-bottom:1px solid #f1f5f9; }
.rtoc-demo-label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; margin-bottom:3px; }
.rtoc-demo-value { font-size:15px; color:#0f172a; font-weight:500; }
.rtoc-demo-value.text-muted { color:#94a3b8 !important; font-style:italic; font-weight:400; }
.rtoc-profile-summary table.table { margin-bottom:0; }
.rtoc-profile-summary table.table td, .rtoc-profile-summary table.table th { vertical-align:middle; font-size:14px; }
.rtoc-cert-row { display:flex; flex-wrap:wrap; align-items:center; gap:14px; padding:12px 4px; border-bottom:1px solid #f1f5f9; }
.rtoc-cert-row:last-child { border-bottom:0; }
.rtoc-cert-main { flex:1 1 320px; min-width:240px; }
.rtoc-cert-title { font-size:15px; font-weight:600; color:#0f172a; }
.rtoc-cert-sub { font-size:13px; color:#64748b; margin-top:2px; }
.rtoc-empty { color:#94a3b8; font-style:italic; font-size:14px; padding:6px 2px; }

/* --- Results section (flagship) --- */
.rtoc-results-head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:14px; margin-bottom:18px; }
.rtoc-results-head h4 { margin:0; font-size:18px; font-weight:700; color:#0f172a; }
.rtoc-results-head .rtoc-results-sub { font-size:13px; color:#64748b; margin-top:2px; }
.rtoc-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
.rtoc-btn { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; text-decoration:none; line-height:1; border:1px solid transparent; transition:filter .12s ease, box-shadow .12s ease; cursor:pointer; }
.rtoc-btn:hover { text-decoration:none; filter:brightness(.96); box-shadow:0 2px 6px rgba(15,23,42,.12); }
.rtoc-btn-primary { background:#1e3a8a; color:#fff; }
.rtoc-btn-primary:hover { color:#fff; }
.rtoc-btn-secondary { background:#eff6ff; color:#1e3a8a; border-color:#bfdbfe; }
.rtoc-btn-secondary:hover { color:#1e3a8a; }
.rtoc-btn-ghost { background:transparent; color:#475569; border-color:#e2e8f0; font-weight:500; }
.rtoc-btn-ghost:hover { color:#0f172a; }

/* --- Qualification progress block --- */
.rtoc-qual { border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(15,23,42,.06); margin-bottom:20px; overflow:hidden; }
.rtoc-qual-head { display:flex; flex-wrap:wrap; align-items:center; gap:20px; padding:18px 20px; background:linear-gradient(135deg,#f8fafc 0%,#eff6ff 100%); border-bottom:1px solid #e2e8f0; }
.rtoc-qual-ident { flex:1 1 260px; min-width:220px; }
.rtoc-qual-code { display:inline-block; font-size:12px; font-weight:700; letter-spacing:.03em; color:#1e3a8a; background:#dbeafe; padding:3px 9px; border-radius:6px; margin-bottom:6px; }
.rtoc-qual-name { font-size:16px; font-weight:700; color:#0f172a; line-height:1.3; }
.rtoc-qual-progress { flex:1 1 320px; min-width:260px; }
.rtoc-progress-top { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:6px; }
.rtoc-progress-count { font-size:14px; font-weight:700; color:#0f172a; }
.rtoc-progress-pct { font-size:13px; font-weight:700; color:#166534; }
.rtoc-progress-track { height:12px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.rtoc-progress-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#16a34a,#22c55e); transition:width .3s ease; }
.rtoc-progress-fill.rtoc-partial { background:linear-gradient(90deg,#2563eb,#3b82f6); }
.rtoc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.rtoc-chip { font-size:11px; font-weight:600; padding:3px 9px; border-radius:999px; line-height:1.4; }
.rtoc-chip-green { background:#dcfce7; color:#166534; }
.rtoc-chip-red   { background:#fee2e2; color:#991b1b; }
.rtoc-chip-amber { background:#fef3c7; color:#92400e; }
.rtoc-chip-blue  { background:#dbeafe; color:#1e40af; }
.rtoc-chip-grey  { background:#e2e8f0; color:#475569; }

/* --- Unit result table --- */
.rtoc-unit-table { width:100%; margin:0; border-collapse:collapse; }
.rtoc-unit-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; padding:10px 20px; border-bottom:1px solid #e2e8f0; background:#fff; }
.rtoc-unit-table td { padding:11px 20px; border-bottom:1px solid #f1f5f9; font-size:14px; color:#0f172a; vertical-align:middle; }
.rtoc-unit-table tr:last-child td { border-bottom:0; }
.rtoc-unit-table tr:hover td { background:#f8fafc; }
.rtoc-unit-code { font-weight:600; }
.rtoc-unit-name { color:#475569; }
.rtoc-src { font-size:13px; color:#334155; }
.rtoc-src-muted { font-size:12px; color:#94a3b8; font-style:italic; }

/* --- Outcome badge --- */
.rtoc-oc { display:inline-block; font-size:12px; font-weight:600; padding:4px 10px; border-radius:6px; line-height:1.3; white-space:nowrap; cursor:default; }
.rtoc-oc-green { background:#dcfce7; color:#166534; }
.rtoc-oc-red   { background:#fee2e2; color:#991b1b; }
.rtoc-oc-amber { background:#fef3c7; color:#92400e; }
.rtoc-oc-blue  { background:#dbeafe; color:#1e40af; }
.rtoc-oc-grey  { background:#e2e8f0; color:#475569; }

/* --- Legend --- */
.rtoc-legend { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:4px 0 22px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
.rtoc-legend-title { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; margin-right:4px; }
.rtoc-legend .rtoc-oc { font-size:11px; padding:3px 8px; }
</style>';

echo '<div class="rtoc-profile-summary">';

// --- HEADER (a) ------------------------------------------------------------
$initials = strtoupper(mb_substr(trim((string)($user->firstname ?? '')), 0, 1)
    . mb_substr(trim((string)($user->lastname ?? '')), 0, 1));
if ($initials === '') {
    $initials = '?';
}

$usi       = trim((string)($student->usi ?? ''));
$usiverified = (int)($student->usiverified ?? 0);
if ($usi === '') {
    $usibadge = '<span class="rtoc-pill rtoc-pill-grey">No USI recorded</span>';
} else if ($usiverified === 1) {
    $vdate = !empty($student->usiverifieddate)
        ? ' &middot; ' . userdate($student->usiverifieddate, get_string('strftimedate', 'langconfig'))
        : '';
    $usibadge = '<span class="rtoc-pill rtoc-pill-green">&#10003; USI verified' . $vdate . '</span>';
} else {
    $usibadge = '<span class="rtoc-pill rtoc-pill-amber">&#9888; USI not verified</span>';
}

// Completeness pill computed from already-available validation data.
$missingmessages = array_column($studentinfo->validationerrors, 'message');
if ($isnewprofile) {
    $completepill = '<span class="rtoc-pill rtoc-pill-grey">New profile &mdash; not yet saved</span>';
} else if (!empty($student->profilecomplete) && empty($missingmessages)) {
    $completepill = '<span class="rtoc-pill rtoc-pill-green">&#10003; Profile complete</span>';
} else {
    $n = count($missingmessages);
    $label = $n > 0 ? ($n . ' field' . ($n === 1 ? '' : 's') . ' missing') : 'Profile incomplete';
    $completepill = '<span class="rtoc-pill rtoc-pill-amber">&#9888; ' . s($label) . '</span>';
}

echo '<div class="rtoc-hero">';
echo '<div class="rtoc-hero-avatar">' . s($initials) . '</div>';
echo '<div class="rtoc-hero-main">';
echo '<p class="rtoc-hero-name">' . s(fullname($user)) . '</p>';
$clientid = trim((string)($student->clientid ?? ''));
echo '<p class="rtoc-hero-sub">'
    . 'Client ID: <strong>' . ($clientid !== '' ? s($clientid) : '&mdash;') . '</strong>'
    . ($usi !== '' ? ' &nbsp;&bull;&nbsp; USI: <strong>' . s($usi) . '</strong>' : '')
    . ' &nbsp;&bull;&nbsp; ' . s($user->email)
    . '</p>';
echo '<div class="rtoc-hero-meta">' . $usibadge . $completepill . '</div>';
echo '</div>';
echo '</div>';

// If incomplete, list the missing-field messages inline so gaps are visible on load.
if (!$isnewprofile && !empty($missingmessages)) {
    echo '<div class="rtoc-missing-list">';
    echo '<strong>To complete this profile, the following still need attention:</strong>';
    echo '<ul>';
    foreach ($missingmessages as $msg) {
        echo '<li>' . s($msg) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

// --- PRE-ENROLMENT READINESS (v5.9.423) -----------------------------------
// Consolidated view of the four ASQA pre-enrolment gates for this student. Read-only
// signal built from existing suitability + USI records — it flags gaps before results
// or certificates flow; it does not create or block any Moodle enrolment.
if (!$isnewprofile && function_exists('local_rtocompliance_preenrolment_readiness')) {
    $pe = local_rtocompliance_preenrolment_readiness((int)$userid);
    $headpill = $pe['ready']
        ? '<span class="rtoc-pill rtoc-pill-green">&#10003; Pre-enrolment complete</span>'
        : '<span class="rtoc-pill rtoc-pill-amber">&#9888; ' . $pe['metcount'] . ' of ' . $pe['total'] . ' met</span>';
    echo '<div class="card">';
    echo '<div class="card-header">Pre-enrolment readiness ' . $headpill . '</div>';
    echo '<div class="card-body">';
    echo '<table class="table" style="margin-bottom:0;"><tbody>';
    foreach ($pe['gates'] as $g) {
        if ($g['ok']) {
            $icon = '<span class="rtoc-pill rtoc-pill-green">&#10003;</span>';
        } else if (!empty($g['warn'])) {
            $icon = '<span class="rtoc-pill rtoc-pill-amber">&#9888;</span>';
        } else {
            $icon = '<span class="rtoc-pill rtoc-pill-grey">&mdash;</span>';
        }
        echo '<tr>';
        echo '<td style="width:34px;">' . $icon . '</td>';
        echo '<td style="font-weight:600;width:220px;">' . s($g['label']) . '</td>';
        echo '<td class="text-muted">' . s($g['detail']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if (!$pe['ready']) {
        echo '<p class="text-muted" style="margin:12px 0 0;font-size:13px;">'
            . 'Pre-enrolment obligations (Standard 2) should be met before training and assessment begin. '
            . 'Send or complete the suitability review, verify the USI, and capture the student declaration to close the gaps above.</p>';
    }
    echo '</div></div>';
}

// --- DEMOGRAPHICS (b) ------------------------------------------------------
$dobval = !empty($student->dateofbirth) && (int)$student->dateofbirth > 0
    ? ['text' => userdate((int)$student->dateofbirth, get_string('strftimedate', 'langconfig')), 'muted' => false]
    : ['text' => 'Not stated', 'muted' => true];

$suburb    = trim((string)($student->suburb ?? ''));
$statecode = trim((string)($student->statecode ?? ''));
$statename = ($statecode !== '' && isset($map_state[$statecode])) ? $map_state[$statecode] : $statecode;
// v5.9.396: the full street address (building / unit / street / postcode) now
// propagates from NAT00085, so show the whole address rather than just suburb+state.
$street    = trim((string)($student->streetname ?? ''));
$unit      = trim((string)($student->unitno ?? ''));
$building  = trim((string)($student->buildingname ?? ''));
$postcode  = trim((string)($student->postcode ?? ''));
$line1     = trim(($unit !== '' ? $unit . '/' : '') . $street);
$statepc   = trim($statename . ($postcode !== '' ? ' ' . $postcode : ''));
$addrparts = array_filter([$building, $line1, $suburb, $statepc], fn($p) => $p !== '');
if (empty($addrparts)) {
    $addrval = ['text' => 'Not stated', 'muted' => true];
} else {
    $addrval = ['text' => implode(', ', $addrparts), 'muted' => false];
}

// SENSITIVE-DATA (v5.9.401): disability is a sensitive disclosure. Staff need the
// dedicated viewsensitive capability to see it; the student always sees their own,
// and managers hold it by default (so existing access is unchanged).
$cansensitive = ($userid == $USER->id)
    || has_capability('local/rtocompliance:viewsensitive', $context);
$disabilityval = $cansensitive
    ? $avlabel($student->disabilityflag ?? '', $map_disab)
    : ['text' => 'Restricted (needs "view sensitive data" permission)', 'muted' => true];

$demorows = [
    ['label' => 'Date of birth',        'val' => $dobval],
    ['label' => 'Sex',                  'val' => $avlabel($student->sex ?? '', $map_sex)],
    ['label' => 'Address',              'val' => $addrval],
    ['label' => 'Indigenous status',    'val' => $avlabel($student->indigenousstatus ?? '', $map_indig)],
    ['label' => 'Language at home',     'val' => $avlabel($student->languageathome ?? '', $map_lang)],
    ['label' => 'Country of birth',     'val' => $avlabel($student->countryofbirth ?? '', $map_country)],
    ['label' => 'Labour force status',  'val' => $avlabel($student->labourforcestatus ?? '', $map_labour)],
    ['label' => 'Highest school level', 'val' => $avlabel($student->highestschoollevel ?? '', $map_school)],
    ['label' => 'At school',            'val' => $avlabel($student->atschoolflag ?? '', $map_atschool)],
    ['label' => 'Disability',           'val' => $disabilityval],
    ['label' => 'Prior education',      'val' => $avlabel($student->prioreducationflag ?? '', $map_prioredu)],
];

echo '<div class="card"><div class="card-header">Demographics (AVETMISS)</div><div class="card-body">';
echo '<div class="rtoc-demo-grid">';
foreach ($demorows as $row) {
    $cls = 'rtoc-demo-value' . (!empty($row['val']['muted']) ? ' text-muted' : '');
    echo '<div class="rtoc-demo-item">';
    echo '<span class="rtoc-demo-label">' . s($row['label']) . '</span>';
    echo '<span class="' . $cls . '">' . s($row['val']['text']) . '</span>';
    echo '</div>';
}
echo '</div></div></div>';

// --- RESULTS (c) -----------------------------------------------------------
// Outcome map: every AVETMISS outcome code -> human label, short tag and colour.
// Codes 00 / 10 / blank are treated as "Not yet assessed" (grey — deliberately
// neither Competent nor NYC). The "competent" set drives progress + the SoA action.
$outcomemeta = [
    '20' => ['label' => 'Competent',                     'short' => 'C',    'color' => 'green'],
    '30' => ['label' => 'Not Yet Competent',             'short' => 'NYC',  'color' => 'red'],
    '40' => ['label' => 'Withdrawn',                     'short' => 'W',    'color' => 'amber'],
    '51' => ['label' => 'RPL Granted',                   'short' => 'RPL',  'color' => 'green'],
    '52' => ['label' => 'RPL Not Granted',               'short' => 'RPL-', 'color' => 'red'],
    '60' => ['label' => 'Credit Transfer',               'short' => 'CT',   'color' => 'green'],
    '61' => ['label' => 'Superseded',                    'short' => 'SUP',  'color' => 'grey'],
    '70' => ['label' => 'Continuing activity',           'short' => 'CONT', 'color' => 'blue'],
    '81' => ['label' => 'Satisfactorily Completed',      'short' => 'SC',   'color' => 'green'],
    '82' => ['label' => 'Not Satisfactorily Completed',  'short' => 'NSC',  'color' => 'red'],
    '85' => ['label' => 'Not Yet Started',               'short' => 'NYS',  'color' => 'grey'],
];
$competentset = ['20', '51', '60', '81'];

// Resolve any raw code to its display metadata (label/short/color/code).
$outcomeinfo = function ($code) use ($outcomemeta): array {
    $c = trim((string)$code);
    if ($c === '' || $c === '00' || $c === '10') {
        return ['label' => 'Not yet assessed', 'short' => 'NA', 'color' => 'grey',
                'code' => ($c === '' ? '—' : $c)];
    }
    if (isset($outcomemeta[$c])) {
        return $outcomemeta[$c] + ['code' => $c];
    }
    return ['label' => $c, 'short' => $c, 'color' => 'grey', 'code' => $c];
};

// Full-label badge with the raw code + short tag in the tooltip.
$outcomebadge = function ($code) use ($outcomeinfo): string {
    $m = $outcomeinfo($code);
    $title = 'AVETMISS code ' . $m['code'] . ' · ' . $m['short'];
    return '<span class="rtoc-oc rtoc-oc-' . $m['color'] . '" title="' . s($title) . '">'
        . s($m['label']) . '</span>';
};

// --- Load results (read-only, guarded for new profiles) --------------------
$results = [];
if (!$isnewprofile) {
    $results = $DB->get_records('local_rtocompliance_enrolments',
        ['studentid' => $student->id], 'activityenddate DESC, unitcode ASC',
        'id, unitcode, unitname, outcomeidentifier, activityenddate, programcode, programname, courseid',
        0, 200);
}

// Batch-load the delivery courses once (Source column) to avoid a query per row.
$coursecache = [];
if (!empty($results)) {
    $courseids = [];
    foreach ($results as $r) {
        $cid = (int)($r->courseid ?? 0);
        if ($cid > 0) {
            $courseids[$cid] = true;
        }
    }
    if (!empty($courseids)) {
        $coursecache = $DB->get_records_list('course', 'id', array_keys($courseids),
            '', 'id, shortname, fullname');
    }
}

// MOODLE-COMPLETION-LINK (v5.9.406): compute each unit's EFFECTIVE outcome, so a
// unit that is complete in its Moodle delivery course shows as Competent even before
// it has been synced into the AVETMISS register. $r->_effcode / $r->_frommoodle drive
// the badge, the competent counts and the progress bars below.
$completedcourses  = $isnewprofile ? [] : local_rtocompliance_moodle_completed_courses($userid);
$moodlederivedcount = 0;
foreach ($results as $r) {
    $eff = local_rtocompliance_effective_outcome(
        (string)($r->outcomeidentifier ?? ''), (int)($r->courseid ?? 0), $completedcourses);
    $r->_effcode    = $eff['code'];
    $r->_frommoodle = $eff['frommoodle'];
    if ($eff['frommoodle']) {
        $moodlederivedcount++;
    }
}

// Group results by qualification (programcode/programname).
$qualgroups = [];
foreach ($results as $r) {
    $pcode = trim((string)($r->programcode ?? ''));
    $pname = trim((string)($r->programname ?? ''));
    $key = $pcode !== '' ? $pcode : ($pname !== '' ? $pname : '__none__');
    if (!isset($qualgroups[$key])) {
        $qualgroups[$key] = ['code' => $pcode, 'name' => $pname, 'rows' => []];
    }
    $qualgroups[$key]['rows'][] = $r;
}

// Does the student have any competent unit? (drives the SoA action button)
$hascompetent = false;
foreach ($results as $r) {
    if (in_array($r->_effcode, $competentset, true)) {
        $hascompetent = true;
        break;
    }
}

// --- Results section header + consolidated actions -------------------------
$soaurl  = new moodle_url('/local/rtocompliance/soa_issue.php');
$hubkey  = trim((string)($student->usi ?? '')) !== ''
    ? trim((string)$student->usi)
    : trim((string)($student->clientid ?? ''));
$huburl  = new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['q' => $hubkey]);
$enrolurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]);

$totalunits = count($results);
$totalcompetent = 0;
foreach ($results as $r) {
    if (in_array($r->_effcode, $competentset, true)) {
        $totalcompetent++;
    }
}

echo '<div class="rtoc-results-head">';
echo '<div><h4>Training results</h4><div class="rtoc-results-sub">'
    . ($isnewprofile
        ? 'Save the profile to start recording results.'
        : ($totalunits > 0
            ? s($totalcompetent . ' of ' . $totalunits . ' units competent across '
                . count($qualgroups) . ' qualification' . (count($qualgroups) === 1 ? '' : 's'))
            : 'No unit results recorded yet.'))
    . '</div></div>';
if (!$isnewprofile) {
    echo '<div class="rtoc-actions">';
    if ($hascompetent) {
        echo html_writer::link($soaurl, '&#128196; Issue Statement of Attainment',
            ['class' => 'rtoc-btn rtoc-btn-primary', 'title' => 'Issue a Statement of Attainment for the competent units of this learner']);
    }
    echo html_writer::link($huburl, '&#127891; Issue Qualification Certificate',
        ['class' => 'rtoc-btn rtoc-btn-secondary', 'title' => 'Open the Qualification Certificate Hub to issue a full qualification certificate']);
    echo html_writer::link($enrolurl, 'Record / edit results',
        ['class' => 'rtoc-btn rtoc-btn-ghost', 'title' => 'Add or edit this learner unit enrolments and outcomes']);
    echo '</div>';
}
echo '</div>';

// MOODLE-COMPLETION-LINK (v5.9.406): prompt to persist Moodle-derived competencies.
if (!$isnewprofile && $moodlederivedcount > 0) {
    echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin:0 0 14px;font-size:13px;color:#1e40af;">'
        . '<strong>' . (int)$moodlederivedcount . '</strong> unit' . ($moodlederivedcount === 1 ? ' is' : 's are')
        . ' complete in Moodle but not yet recorded in your AVETMISS register (shown below as Competent, tagged &ldquo;via Moodle&rdquo;). '
        . html_writer::link(new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
            'Sync results from Moodle completions', ['style' => 'font-weight:600;color:#1d4ed8;'])
        . ' to record them permanently — required before issuing certificates or exporting NAT.'
        . '</div>';
}

// --- Outcome legend --------------------------------------------------------
if (!$isnewprofile && $totalunits > 0) {
    echo '<div class="rtoc-legend"><span class="rtoc-legend-title">Outcome key</span>';
    foreach ([
        ['Competent', 'green'], ['Not Yet Competent', 'red'], ['Continuing activity', 'blue'],
        ['Withdrawn', 'amber'], ['Not yet assessed', 'grey'],
    ] as $leg) {
        echo '<span class="rtoc-oc rtoc-oc-' . $leg[1] . '">' . s($leg[0]) . '</span>';
    }
    echo '</div>';
}

// --- One progress block + unit table per qualification ---------------------
if ($isnewprofile) {
    echo '<div class="card"><div class="card-body"><div class="rtoc-empty">'
        . 'No unit results recorded yet.</div></div></div>';
} else if (empty($qualgroups)) {
    echo '<div class="card"><div class="card-body"><div class="rtoc-empty">'
        . 'No unit results recorded yet.</div></div></div>';
} else {
    // Plain-English explainer card for the per-qualification unit results tables.
    echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;">'
        . '<div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">About these training results</div>'
        . '<div style="font-size:14.5px;color:#334155;line-height:1.55;margin-bottom:8px;">Results are grouped by qualification, each with a progress bar showing how many units are competent. The table under each qualification lists the individual units. Here is what each column means:</div>'
        . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;">'
        . '<div><strong>Unit</strong> &mdash; the unit of competency code and title.</div>'
        . '<div><strong>Source</strong> &mdash; where the result came from: the Moodle delivery course, or Manual / RPL.</div>'
        . '<div><strong>Outcome</strong> &mdash; the AVETMISS outcome recorded for the unit (see the Outcome key above).</div>'
        . '<div><strong>End date</strong> &mdash; the activity end date for the unit.</div>'
        . '</div></div>';
    foreach ($qualgroups as $g) {
        $rows = $g['rows'];
        $total = count($rows);

        // Outcome breakdown for chips + competent count for the progress bar.
        $competent = 0;
        $breakdown = [];       // label => ['count'=>n, 'color'=>c]
        foreach ($rows as $r) {
            $code = $r->_effcode;
            if (in_array($code, $competentset, true)) {
                $competent++;
            }
            $info = $outcomeinfo($code);
            $lbl = $info['label'];
            if (!isset($breakdown[$lbl])) {
                $breakdown[$lbl] = ['count' => 0, 'color' => $info['color']];
            }
            $breakdown[$lbl]['count']++;
        }
        $pct = $total > 0 ? (int)round(($competent / $total) * 100) : 0;
        $iscomplete = ($competent === $total && $total > 0);

        // Qualification identity.
        $qcode = trim((string)$g['code']);
        $qname = trim((string)$g['name']);
        if ($qname === '' && $qcode === '') {
            $qname = 'Unassigned units';
        }

        echo '<div class="rtoc-qual">';

        // Progress header (hero).
        echo '<div class="rtoc-qual-head">';
        echo '<div class="rtoc-qual-ident">';
        if ($qcode !== '') {
            echo '<span class="rtoc-qual-code">' . s($qcode) . '</span><br>';
        }
        echo '<span class="rtoc-qual-name">'
            . ($qname !== '' ? format_string($qname) : s($qcode)) . '</span>';
        echo '</div>';

        echo '<div class="rtoc-qual-progress">';
        echo '<div class="rtoc-progress-top">';
        echo '<span class="rtoc-progress-count">' . s($competent . ' of ' . $total
            . ' unit' . ($total === 1 ? '' : 's') . ' competent') . '</span>';
        echo '<span class="rtoc-progress-pct">' . $pct . '%</span>';
        echo '</div>';
        echo '<div class="rtoc-progress-track"><div class="rtoc-progress-fill'
            . ($iscomplete ? '' : ' rtoc-partial') . '" style="width:' . $pct . '%"></div></div>';

        // Breakdown chips.
        echo '<div class="rtoc-chips">';
        foreach ($breakdown as $lbl => $b) {
            echo '<span class="rtoc-chip rtoc-chip-' . $b['color'] . '">'
                . $b['count'] . ' ' . s($lbl) . '</span>';
        }
        echo '</div>';
        echo '</div>'; // .rtoc-qual-progress
        echo '</div>'; // .rtoc-qual-head

        // Unit rows.
        echo '<table class="rtoc-unit-table"><thead><tr>'
            . '<th title="The unit of competency code and title">Unit</th>'
            . '<th title="Where the result came from: the Moodle delivery course, or Manual / RPL">Source</th>'
            . '<th title="The AVETMISS outcome recorded for the unit">Outcome</th>'
            . '<th title="The activity end date for the unit">End date</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $ucode = s(trim((string)($r->unitcode ?? '')));
            $uname = trim((string)($r->unitname ?? '')) !== ''
                ? ' <span class="rtoc-unit-name">&mdash; ' . format_string($r->unitname) . '</span>'
                : '';

            // Source: resolve courseid to the Moodle course shortname.
            $cid = (int)($r->courseid ?? 0);
            if ($cid > 0 && isset($coursecache[$cid])) {
                $co = $coursecache[$cid];
                $src = '<span class="rtoc-src" title="' . format_string($co->fullname) . '">'
                    . format_string($co->shortname) . '</span>';
            } else {
                $src = '<span class="rtoc-src-muted">Manual / RPL</span>';
            }

            $end = !empty($r->activityenddate) && (int)$r->activityenddate > 0
                ? userdate((int)$r->activityenddate, get_string('strftimedate', 'langconfig'))
                : '<span class="text-muted">&mdash;</span>';

            $ocbadge = $outcomebadge($r->_effcode);
            if (!empty($r->_frommoodle)) {
                $ocbadge .= ' <span style="font-size:10px;color:#2563eb;font-weight:600;white-space:nowrap;"'
                    . ' title="Completed in its Moodle course — click Sync to record this in the AVETMISS register">via Moodle</span>';
            }
            echo '<tr>';
            echo '<td><span class="rtoc-unit-code">' . $ucode . '</span>' . $uname . '</td>';
            echo '<td>' . $src . '</td>';
            echo '<td>' . $ocbadge . '</td>';
            echo '<td>' . $end . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // .rtoc-qual
    }
}

// --- CERTIFICATES (d) ------------------------------------------------------
$certtypelabels = [
    'testamur'   => 'Testamur',
    'statement'  => 'Statement of Attainment',
    'record'     => 'Record of Results',
    'completion' => 'Completion',
];

$certs = $DB->get_records('local_rtocompliance_certs',
    ['userid' => $userid, 'status' => 'issued'], 'issuedate DESC',
    'id, certtype, certnumber, qualificationcode, qualificationname, issuedate');

echo '<div class="card"><div class="card-header">Issued certificates</div><div class="card-body">';
if (empty($certs)) {
    echo '<div class="rtoc-empty">No certificates issued yet.</div>';
} else {
    foreach ($certs as $c) {
        $ctype = $certtypelabels[$c->certtype] ?? ucfirst((string)$c->certtype);
        $qual  = trim((string)($c->qualificationname ?? '')) !== ''
            ? format_string($c->qualificationname)
            : trim((string)($c->qualificationcode ?? ''));
        $issued = !empty($c->issuedate) && (int)$c->issuedate > 0
            ? userdate((int)$c->issuedate, get_string('strftimedate', 'langconfig'))
            : '&mdash;';
        $dlurl = new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $c->id]);

        echo '<div class="rtoc-cert-row">';
        echo '<div class="rtoc-cert-main">';
        echo '<div class="rtoc-cert-title">' . s($ctype)
            . ($qual !== '' ? ' <span class="text-muted">&middot;</span> ' . s($qual) : '') . '</div>';
        echo '<div class="rtoc-cert-sub">'
            . ($c->certnumber ? 'Cert #: ' . s($c->certnumber) . ' &nbsp;&bull;&nbsp; ' : '')
            . 'Issued: ' . $issued . '</div>';
        echo '</div>';
        echo html_writer::link($dlurl, 'Download PDF',
            ['class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank', 'title' => 'Download this issued certificate as a PDF']);
        echo '</div>';
    }
}
echo '</div></div>';

echo '</div>'; // .rtoc-profile-summary

$form->display();

echo $OUTPUT->footer();
