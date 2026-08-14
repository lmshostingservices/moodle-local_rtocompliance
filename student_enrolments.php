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
 * RTO Compliance plugin — student_enrolments.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\form\enrolment_form;
use local_rtocompliance\audit_logger;

$userid = optional_param('userid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$enrolid = optional_param('enrolid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_students');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:viewall', $context);

if (!$userid) {
    // BUG-DASH-FIRST-ENROL: Previously rendered a hard error wall ("No student was
    // specified. Please select a student from the student list.") when this page
    // was reached without ?userid=N — happened when admins clicked the
    // "Create Your First Enrolment" dashboard tile, used a stale bookmark, or
    // landed here from any link that forgot to pass userid. Now we redirect
    // straight to the student picker with a friendly INFO notice so the user
    // can simply pick the student they want to enrol and click through.
    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        get_string('error_userid_missing', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$user = core_user::get_user($userid);
if (!$user) {
    $PAGE->set_url(new moodle_url('/local/rtocompliance/student_enrolments.php'));
    $PAGE->set_title(get_string('enrolments', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('enrolments', 'local_rtocompliance'));
    $PAGE->add_body_class("path-local-rtocompliance");
    $PAGE->requires->css('/local/rtocompliance/styles.css');
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header(
        get_string('enrolments', 'local_rtocompliance'),
        get_string('students', 'local_rtocompliance'),
        '/local/rtocompliance/students.php',
        'students'
    );
    echo local_rtocompliance_page_banner(get_string('enrolments', 'local_rtocompliance'));
    echo html_writer::div(
        html_writer::tag('p', get_string('error_student_not_found', 'local_rtocompliance')) .
        html_writer::link(
            new moodle_url('/local/rtocompliance/students.php'),
            get_string('students', 'local_rtocompliance'),
            ['class' => 'btn btn-primary']
        ),
        'alert alert-danger'
    );
    echo $OUTPUT->footer();
    return;
}

$student = $DB->get_record('local_rtocompliance_students', ['userid' => $userid]);
if (!$student) {
    redirect(
        new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $userid]),
        get_string('profilerequired', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]));
$PAGE->set_title(get_string('enrolments', 'local_rtocompliance') . ': ' . fullname($user));
$PAGE->set_heading(get_string('enrolments', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add(fullname($user), new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $userid]));
$PAGE->navbar->add(get_string('enrolments', 'local_rtocompliance'));

// Helper: fetch this student's Moodle-native course enrolments (excluding site course).
function rtocompliance_get_moodle_enrolments($userid) {
    global $DB;
    return $DB->get_records_sql(
        "SELECT DISTINCT c.id AS courseid, c.fullname, c.shortname, c.startdate AS coursestart,
                ue.timestart, ue.timecreated AS enrolled_at
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {course} c ON c.id = e.courseid
          WHERE ue.userid = :userid
            AND ue.status = 0
            AND e.status = 0
            AND c.id != 1
          ORDER BY c.fullname",
        ['userid' => $userid]
    );
}

// ============================================================
// Action: import from Moodle enrolments
// ============================================================
if ($action === 'import' && confirm_sesskey()) {
    require_sesskey();

    // TASK-32 (v5.9.325): Resolve the optional contract slot submitted with the import form.
    // When at least one QLD purchasing contract is configured and the admin chose a specific
    // slot (contract1/2/3), resolve it to the actual contract string and stamp purchasingcontract1
    // on every enrolment record created in this batch.  The default 'auto' (or absent) leaves
    // purchasingcontract1 empty so the NAT exporter applies the RTO-level default at export time,
    // preserving the existing behaviour for RTOs that do not use the slot selector.
    $import_contract_slot = optional_param('purchasingcontract_slot', 'auto', PARAM_ALPHANUMEXT);
    $resolved_import_contract = '';
    if ($import_contract_slot !== '' && $import_contract_slot !== 'auto') {
        $slotmap_import = [
            'contract1' => get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '',
            'contract2' => get_config('local_rtocompliance', 'qld_purchasing_contract_2') ?: '',
            'contract3' => get_config('local_rtocompliance', 'qld_purchasing_contract_3') ?: '',
        ];
        $resolved_import_contract = substr($slotmap_import[$import_contract_slot] ?? '', 0, 20);
    }

    $moodle_enrolments = rtocompliance_get_moodle_enrolments($userid);
    $now = time();
    $imported = 0;
    $skipped  = 0;

    foreach ($moodle_enrolments as $me) {
        $startdate = $me->timestart ?: ($me->coursestart ?: $me->enrolled_at);

        // Look up qual builder units linked to this exact Moodle course.
        // IMPORT-VARIANT-FIX (v5.9.296): also checks qualunit_courses (variant courses)
        // via UNION so that students enrolled in a variant delivery course get their
        // unitcode and programcode correctly populated on import, rather than falling
        // through to the bare course-level fallback with no unitcode.
        $linked_units = $DB->get_records_sql(
            "SELECT qu.unitcode, qu.unitname, qb.qualificationcode, qb.qualificationname
               FROM {local_rtocompliance_qualunits} qu
               JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
              WHERE qu.courseid = :courseid1
                AND qu.selected = 1
              UNION
             SELECT qu2.unitcode, qu2.unitname, qb2.qualificationcode, qb2.qualificationname
               FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {local_rtocompliance_qualunits} qu2  ON qu2.id = quc.qualunitid
               JOIN {local_rtocompliance_qualbuilder} qb2 ON qb2.id = qu2.qualbuilderid
              WHERE quc.courseid = :courseid2
                AND qu2.selected = 1
              ORDER BY unitcode ASC",
            ['courseid1' => $me->courseid, 'courseid2' => $me->courseid]
        );

        if (!empty($linked_units)) {
            // Create one record per linked unit (correct AVETMISS granularity).
            foreach ($linked_units as $lu) {
                if ($DB->record_exists('local_rtocompliance_enrolments', [
                    'studentid' => $student->id,
                    'courseid'  => $me->courseid,
                    'unitcode'  => $lu->unitcode,
                ])) {
                    $skipped++;
                    continue;
                }

                $rec = new stdClass();
                $rec->studentid           = $student->id;
                $rec->courseid            = $me->courseid;
                $rec->programcode         = $lu->qualificationcode;
                $rec->programname         = $lu->qualificationname;
                $rec->unitcode            = $lu->unitcode;
                $rec->unitname            = $lu->unitname;
                $rec->activitystartdate   = $startdate;
                // BUG-9 FIX: '00' is not a valid AVETMISS code; use '70' (Continuing).
                $rec->outcomeidentifier   = '70';
                $rec->deliverymode        = '10';
                $rec->fundingsourcenat    = '30';
                $rec->vetflag             = 'Y';
                $rec->vetinschoolsflag    = 'N';
                $rec->commencingprogramid = '3';
                $rec->feecharged          = 'Y';
                $rec->status              = 'active';
                $rec->timecreated         = $now;
                $rec->timemodified        = $now;
                // TASK-32: stamp the resolved contract on each created record.
                if ($resolved_import_contract !== '') {
                    $rec->purchasingcontract1 = $resolved_import_contract;
                }
                $DB->insert_record('local_rtocompliance_enrolments', $rec);
                $imported++;
            }
        } else {
            // No qual builder linkage — fall back to a single course-level record.
            // Try to detect a programcode from any qualification whose units link to this
            // course (primary or variant).  IMPORT-VARIANT-FIX (v5.9.296): added UNION
            // with qualunit_courses so a variant-only course also finds its qualification.
            $fallback_qual = $DB->get_record_sql(
                "SELECT qualificationcode, qualificationname FROM (
                     SELECT DISTINCT qb.qualificationcode, qb.qualificationname
                       FROM {local_rtocompliance_qualbuilder} qb
                       JOIN {local_rtocompliance_qualunits} qu ON qu.qualbuilderid = qb.id
                      WHERE qu.courseid = :courseid1
                     UNION
                     SELECT DISTINCT qb2.qualificationcode, qb2.qualificationname
                       FROM {local_rtocompliance_qualbuilder} qb2
                       JOIN {local_rtocompliance_qualunits} qu2  ON qu2.qualbuilderid = qb2.id
                       JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu2.id
                      WHERE quc.courseid = :courseid2
                 ) combined LIMIT 1",
                ['courseid1' => $me->courseid, 'courseid2' => $me->courseid]
            );

            if ($DB->record_exists('local_rtocompliance_enrolments', ['studentid' => $student->id, 'courseid' => $me->courseid])) {
                $skipped++;
                continue;
            }

            $rec = new stdClass();
            $rec->studentid           = $student->id;
            $rec->courseid            = $me->courseid;
            $rec->activitystartdate   = $startdate;
            // BUG-9 FIX: '00' is not a valid AVETMISS code; use '70' (Continuing).
            $rec->outcomeidentifier   = '70';
            $rec->deliverymode        = '10';
            $rec->fundingsourcenat    = '30';
            $rec->vetflag             = 'Y';
            $rec->vetinschoolsflag    = 'N';
            $rec->commencingprogramid = '3';
            $rec->feecharged          = 'Y';
            $rec->status              = 'active';
            $rec->timecreated         = $now;
            $rec->timemodified        = $now;
            if ($fallback_qual) {
                $rec->programcode = $fallback_qual->qualificationcode;
                $rec->programname = $fallback_qual->qualificationname;
            }
            // TASK-32: stamp the resolved contract on the fallback record too.
            if ($resolved_import_contract !== '') {
                $rec->purchasingcontract1 = $resolved_import_contract;
            }
            $DB->insert_record('local_rtocompliance_enrolments', $rec);
            $imported++;
        }
    }

    redirect(
        new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
        get_string('enrolments_imported', 'local_rtocompliance', ['imported' => $imported, 'skipped' => $skipped]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'delete' && $enrolid) {
    if ($confirm) {
        // Bug R fix: require_sesskey() before deleting an AVETMISS enrolment record.
        // ASQA requires 30-year retention of training records; a CSRF attack via a
        // crafted URL (action=delete&enrolid=X&confirm=1) could silently destroy
        // enrolment data required for compliance reporting.
        require_sesskey();
        $oldenrolment = $DB->get_record('local_rtocompliance_enrolments', ['id' => $enrolid, 'studentid' => $student->id]);
        $DB->delete_records('local_rtocompliance_enrolments', ['id' => $enrolid, 'studentid' => $student->id]);
        
        $deletedata = $oldenrolment ? [
            'enrolment_id' => $enrolid,
            'student_id' => $student->id,
            'user_id' => $userid,
            'course_id' => $oldenrolment->courseid ?? null,
            'unit_code' => $oldenrolment->unitcode ?? '',
            'outcome' => $oldenrolment->outcomeidentifier ?? '',
        ] : ['enrolment_id' => $enrolid, 'student_id' => $student->id];
        
        audit_logger::log_delete(
            audit_logger::ENTITY_ENROLMENT,
            $enrolid,
            'Enrolment deleted: Student ' . $student->id . ', User ' . $userid . ', Unit ' . ($oldenrolment->unitcode ?? 'N/A'),
            $deletedata
        );
        redirect(
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
            get_string('enrolment_deleted', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $PAGE->add_body_class("path-local-rtocompliance");
        $PAGE->requires->css('/local/rtocompliance/styles.css');
        echo $OUTPUT->header();
        echo local_rtocompliance_render_nav_header(
            get_string('enrolments', 'local_rtocompliance'),
            get_string('students', 'local_rtocompliance'),
            '/local/rtocompliance/students.php',
            'students'
        );
        echo local_rtocompliance_page_banner(get_string('enrolments', 'local_rtocompliance'));
        echo $OUTPUT->confirm(
            get_string('confirmdelete', 'local_rtocompliance'),
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid, 'action' => 'delete', 'enrolid' => $enrolid, 'confirm' => 1]),
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid])
        );
        echo $OUTPUT->footer();
        return;
    }
}

if ($action === 'markcomplete' && $enrolid) {
    require_sesskey();
    // FIX-MARKCOMPLETE-ERROR: Wrap in try-catch so any unexpected DB or session exception
    // (e.g. MUST_EXIST failure if the enrolment studentid doesn't match, or an audit log
    // error) produces a friendly redirect notification instead of a Moodle error page.
    try {
        $enrolment = $DB->get_record('local_rtocompliance_enrolments', ['id' => $enrolid, 'studentid' => $student->id], '*', MUST_EXIST);
        $enrolment->status = 'completed';
        // Default to outcome 20 (Competency Achieved/Qualification Awarded) only if currently unset or on the
        // in-progress code 70 (Continuing), so we don't overwrite a meaningful outcome already recorded.
        if (empty($enrolment->outcomeidentifier) || $enrolment->outcomeidentifier === '70' || $enrolment->outcomeidentifier === '00') {
            $enrolment->outcomeidentifier = '20';
        }
        if (empty($enrolment->activityenddate)) {
            $enrolment->activityenddate = time();
        }
        $enrolment->timemodified = time();
        $DB->update_record('local_rtocompliance_enrolments', $enrolment);
        // Audit logging is non-fatal — if it fails the enrolment is still saved.
        try {
            audit_logger::log_update(
                audit_logger::ENTITY_ENROLMENT,
                $enrolid,
                'Enrolment marked complete via quick action: Student ' . $student->id . ', Unit ' . ($enrolment->unitcode ?? 'N/A'),
                ['enrolment_id' => $enrolid, 'student_id' => $student->id, 'status' => 'completed', 'outcome' => $enrolment->outcomeidentifier]
            );
        } catch (\Throwable $auditerr) {
            // Non-fatal — continue to redirect.
        }
        redirect(
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
            'Enrolment marked as completed.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\dml_missing_record_exception $e) {
        redirect(
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
            'Could not find this enrolment for the current student. It may have already been updated.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    } catch (\Throwable $e) {
        redirect(
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
            'An error occurred while completing the enrolment: ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

if ($action === 'edit' || $action === 'add') {
    // FIX-ENROLMENT-SAVE: $PAGE->url was previously set with only ['userid' => $userid],
    // so the Moodle form action URL (derived from $PAGE->url via qualified_me()) was
    // missing action= and enrolid=.  On POST submission PHP saw $action='' and skipped
    // the entire save block — nothing was saved and no error was shown.
    // Re-setting $PAGE->url here ensures the form posts back with all required params.
    $PAGE->set_url(new moodle_url('/local/rtocompliance/student_enrolments.php', [
        'userid'  => $userid,
        'action'  => $action,
        'enrolid' => $enrolid,
    ]));

    $enrolment = null;
    if ($action === 'edit' && $enrolid) {
        $enrolment = $DB->get_record('local_rtocompliance_enrolments', ['id' => $enrolid, 'studentid' => $student->id], '*', MUST_EXIST);
    } else {
        $enrolment = new stdClass();
        $enrolment->id = 0;
        $enrolment->studentid = $student->id;
        // BUG-9 FIX: '00' is not a valid AVETMISS code; use '70' (Continuing).
        $enrolment->outcomeidentifier = '70';
        $enrolment->deliverymode = '10';
        $enrolment->fundingsourcenat = '30';
        $enrolment->vetflag = 'Y';
        $enrolment->vetinschoolsflag = 'N';
        $enrolment->commencingprogramid = '3';
        $enrolment->feecharged = 'Y';
        $enrolment->status = 'active';
    }

    // BUG-SR-OUTCOME-SAVE-5 (v4.2.33): pass userid/action/enrolid into the form
    // so the new hidden inputs (added in enrolment_form.php for the same bug)
    // can carry these values in the POST BODY — making the save survive any
    // theme/layout that strips the query string from the form action URL.
    $form = new enrolment_form(null, [
        'studentid' => $student->id,
        'enrolment' => $enrolment,
        'userid'    => $userid,
        'action'    => $action,
        'enrolid'   => $enrolid,
    ]);

    // BUG-SR-OUTCOME-SAVE-3 (v4.2.26): set_data() must NOT be called before
    // get_data() on POST.  The previous unconditional `$form->set_data($enrolment)`
    // call (running on every request, including POST submissions) was repopulating
    // the form's internal element values with the OLD DB record AFTER Moodle's
    // formslib had already parsed the submitted POST values.  For SELECT elements
    // with explicit setDefault() entries (outcomeidentifier, deliverymode,
    // fundingsourcenat, vetflag, vetinschoolsflag, commencingprogramid, feecharged,
    // status), this caused get_data() to return the OLD values instead of the
    // user's new selections — the save block then "successfully" wrote the OLD
    // record back to the DB with the new timemodified, producing a silent revert
    // with no error toast.  This is the documented Moodle 4.5+ behaviour change
    // (https://docs.moodle.org/dev/Form_API#set_data) — set_data must only be
    // called on INITIAL display, never before get_data().  Fix: move the
    // set_data() call into the else-branch below so it only runs when the form
    // is being displayed for the first time (no submission, not cancelled).
    if ($form->is_cancelled()) {
        redirect(new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]));
    } else if ($data = $form->get_data()) {
        $now = time();
        $iscreate = empty($data->id) || $data->id == 0;
        $data->timemodified = $now;

        // BUG-SR-OUTCOME-SAVE-2 (v4.2.21): bulletproof field cleaning.  Multiple report
        // paths into this save (Outcome change → Save) were producing an opaque error
        // toast because Moodle's date_selector with optional=true returns boolean
        // FALSE (not null) when "Enable" is unchecked, and a select element returns
        // the empty-option key '' as a string for char-FK columns that should be null.
        // Either case can blow up update_record() with a DB type-coercion error that
        // gets shown to the user as a generic "Could not save enrolment".  Below we
        // (a) coerce each NOT NULL field to its safe AVETMISS default, (b) coerce each
        // nullable date/FK field to a strict null when not set, and (c) cast every
        // numeric/int/float column explicitly so the DB layer never has to guess.
        $defaults = [
            'outcomeidentifier'   => '70',  // BUG-9: '00' is invalid AVETMISS — use '70' (Continuing).
            'deliverymode'        => '10',
            'fundingsourcenat'    => '30',
            'vetflag'             => 'Y',
            'vetinschoolsflag'    => 'N',
            'commencingprogramid' => '3',
            'feecharged'          => 'Y',
            'status'              => 'active',
        ];
        foreach ($defaults as $field => $default) {
            // empty('00') === false in PHP, but empty('0') === true — guard against both.
            $current = $data->$field ?? null;
            if ($current === null || $current === '' || $current === false) {
                $data->$field = $default;
            } else {
                $data->$field = (string) $current;
            }
        }

        // Nullable INT FK / lookup columns — convert empty/0/false to strict null.
        $nullable_int_fields = ['assessoruserid', 'supersededfrom', 'exportedinnat', 'assessmentdate'];
        foreach ($nullable_int_fields as $field) {
            if (property_exists($data, $field)) {
                $val = $data->$field;
                $data->$field = (empty($val) || $val === '' || $val === false) ? null : (int) $val;
            }
        }

        // Nullable CHAR FK / code columns — convert empty string to strict null so the
        // DB doesn't try to insert '' into a char column that has a length constraint.
        $nullable_char_fields = [
            'deliverylocationid', 'programid', 'subjectid', 'programcode', 'programname',
            'unitcode', 'unitname', 'fundingsourcestate', 'feeexemption',
            'trainingcontractid', 'purchasedfrom', 'programcompletedyear', 'programoutcome',
        ];
        foreach ($nullable_char_fields as $field) {
            if (property_exists($data, $field) && ($data->$field === '' || $data->$field === false)) {
                $data->$field = null;
            }
        }

        // Nullable DATE columns from date_selector with optional=true.  When the user
        // un-ticks the "Enable" checkbox Moodle returns boolean FALSE here, which the
        // DB layer cannot store in an int(10) column on strict MySQL/Postgres configs —
        // this is the most common cause of the "Could not save enrolment" toast.
        $nullable_date_fields = ['activitystartdate', 'activityenddate', 'holduntil', 'assessmentdate'];
        foreach ($nullable_date_fields as $field) {
            if (property_exists($data, $field)) {
                $val = $data->$field;
                $data->$field = (empty($val) || $val === false) ? null : (int) $val;
            }
        }

        // Nullable NUMBER (decimal) columns — empty string becomes null, otherwise float.
        foreach (['tuitionfee', 'govtcontribution'] as $field) {
            if (property_exists($data, $field)) {
                $val = $data->$field;
                $data->$field = ($val === '' || $val === null || $val === false) ? null : (float) $val;
            }
        }

        // Nullable INT columns (counts, hours).
        if (property_exists($data, 'scheduledhours')) {
            $val = $data->scheduledhours;
            $data->scheduledhours = ($val === '' || $val === null || $val === false) ? null : (int) $val;
        }

        // NOMINAL-HOURS-DEFAULT (v5.9.419) — Phase 3: when the admin leaves AVETMISS
        // scheduled hours blank, default them from the unit's authoritative nominal
        // hours so NAT00120 reports a sensible value without manual entry. RPL (51) and
        // Credit Transfer (60) involve no delivery → 0. A value the admin actually typed
        // is never overwritten (this only fills blanks).
        if (property_exists($data, 'scheduledhours') && $data->scheduledhours === null) {
            $_oc = property_exists($data, 'outcomeidentifier') ? (string) $data->outcomeidentifier : '';
            if (in_array($_oc, ['51', '60'], true)) {
                $data->scheduledhours = 0;
            } else if (property_exists($data, 'unitcode') && trim((string) $data->unitcode) !== ''
                    && function_exists('local_rtocompliance_lookup_nominalhours')) {
                $_nh = local_rtocompliance_lookup_nominalhours((string) $data->unitcode);
                if ($_nh !== null && (int) $_nh['nominalhours'] > 0) {
                    $data->scheduledhours = (int) $_nh['nominalhours'];
                }
            }
        }

        // Nullable text columns — empty string is fine but coerce false to null.
        foreach (['holdreason', 'validationerrors'] as $field) {
            if (property_exists($data, $field) && $data->$field === false) {
                $data->$field = null;
            }
        }

        // TASK-25 (v5.9.324): Resolve purchasingcontract_slot → purchasingcontract1.
        // The slot selector is a virtual form field (not a DB column).  Resolve it to
        // the actual QLD contract string before the DB write, then unset it so
        // update_record() doesn't try to write a non-existent column.
        //
        // 'auto' (or absent) — leave purchasingcontract1 exactly as submitted from the
        // manual text field below the selector; the NAT generator applies the RTO-level
        // default at export time when the column is empty.  We must NOT clear the
        // text field value here because non-QLD state-funded enrolments (NSW, VIC, etc.)
        // and non-state-funded rows also pass through this save handler with slot='auto'.
        //
        // contract1/2/3 — overwrite purchasingcontract1 with the configured QLD code.
        // This is only reachable when the form rendered the slot selector (i.e. at least
        // one qld_purchasing_contract_N config is non-empty) and the admin selected a
        // specific QLD slot for this enrolment.
        if (!empty($data->purchasingcontract_slot) && $data->purchasingcontract_slot !== 'auto') {
            $slotmap = [
                'contract1' => get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '',
                'contract2' => get_config('local_rtocompliance', 'qld_purchasing_contract_2') ?: '',
                'contract3' => get_config('local_rtocompliance', 'qld_purchasing_contract_3') ?: '',
            ];
            $resolved = $slotmap[$data->purchasingcontract_slot] ?? '';
            if ($resolved !== '') {
                // Overwrite with the resolved QLD contract string (max 20 chars per column length).
                $data->purchasingcontract1 = substr($resolved, 0, 20);
            }
            // If the selected slot has no configured value, fall through and leave
            // purchasingcontract1 as submitted from the manual text field.
        }
        // Always remove the virtual field before the DB write regardless of slot value.
        unset($data->purchasingcontract_slot);

        // BUG-SR-OUTCOME-AUTOREVERT (v4.2.27): mark this row as a MANUAL outcome
        // so the user_graded observer (classes/observer.php), course_completed
        // queue (classes/observer.php) and process_enrolment_task cron
        // (classes/task/process_enrolment_task.php) all skip it on subsequent
        // auto-grading events. v4.2.26 fixed the form-layer set_data() ordering
        // bug, but managers continued to report silent reverts because three
        // separate auto-flows were over-writing the row WHERE studentid=? AND
        // courseid=? regardless of what the manager had just saved. Manager
        // edits via this form ARE the legal AVETMISS record-of-truth — the
        // gradebook is a convenience starting point only, never an override.
        $data->manualoutcome = 1;

        $olddata = null;
        if (!$iscreate) {
            $oldenrolment = $DB->get_record('local_rtocompliance_enrolments', ['id' => $data->id]);
            $olddata = [
                'course_id' => $oldenrolment->courseid ?? null,
                'unit_code' => $oldenrolment->unitcode ?? '',
                'outcome' => $oldenrolment->outcomeidentifier ?? '',
                'status' => $oldenrolment->status ?? '',
                'delivery_mode' => $oldenrolment->deliverymode ?? '',
            ];
        }

        // BUG-SR-OUTCOME FIX: Wrap DB save and audit logging in try/catch.
        // Previously, any dml_exception (e.g. duplicate key, missing audit table) would
        // propagate as an uncaught exception and render a raw Moodle error page instead
        // of a user-friendly redirect.  The try block covers both the DB write and the
        // non-fatal audit log, so either failure produces a clear error notification.
        // BUG-SR-OUTCOME-SAVE-2 (v4.2.21): also surface the exception class and the
        // last DB error (when available) so admins can diagnose root cause without
        // server-log access — same pattern as the v4.2.18 USI fix and v4.2.20 survey
        // AI fix.  The full message + stack trace is still written to error_log() for
        // server-side troubleshooting.
        // BUG-SR-OUTCOME-SAVE-3 (v4.2.26): always log the submitted vs. resolved
        // outcome/status pair so admins can grep error_log for "[rto/enrolment-save-debug]"
        // and confirm the form actually transmitted the user's selection.  Same
        // diagnostic pattern as BUG-USI-PLATFORM-MSG (v4.2.18).
        error_log('[rto/enrolment-save-debug] action=' . $action
            . ' enrolid=' . ($enrolid ?: 'new')
            . ' studentid=' . $student->id
            . ' iscreate=' . ($iscreate ? '1' : '0')
            . ' submitted_outcome=' . var_export($data->outcomeidentifier ?? null, true)
            . ' submitted_status=' . var_export($data->status ?? null, true)
            . ' submitted_id=' . var_export($data->id ?? null, true));

        try {
            if (!$iscreate) {
                $DB->update_record('local_rtocompliance_enrolments', $data);
            } else {
                // Remove id=0 for new record (auto-generated)
                unset($data->id);
                $data->timecreated = $now;
                $data->id = $DB->insert_record('local_rtocompliance_enrolments', $data);
            }
        } catch (\Throwable $e) {
            // Build a user-facing message that includes the underlying root cause.
            $cls    = (new \ReflectionClass($e))->getShortName();
            $msg    = trim($e->getMessage());
            $detail = '';
            if ($e instanceof \dml_write_exception || $e instanceof \dml_exception) {
                // Moodle's dml_exception holds the raw DB driver error in $e->error /
                // $e->debuginfo — surface a snippet so admins see "Data too long for
                // column 'unitcode'" or "cannot be null" verbatim.
                $debug = property_exists($e, 'debuginfo') ? (string) $e->debuginfo : '';
                $err   = property_exists($e, 'error') ? (string) $e->error : '';
                $detail = trim($err . ' ' . substr($debug, 0, 200));
            }
            $userfacing = "Could not save enrolment ({$cls}): {$msg}";
            if ($detail !== '') {
                $userfacing .= ' [' . $detail . ']';
            }
            // Always log the full trace server-side so we can grep for "[rto/enrolment-save]".
            error_log('[rto/enrolment-save] ' . $cls . ' studentid=' . $student->id
                . ' enrolid=' . ($enrolid ?: 'new') . ' action=' . $action . ' :: '
                . $msg . "\n" . $e->getTraceAsString());

            redirect(
                new moodle_url('/local/rtocompliance/student_enrolments.php', [
                    'userid'  => $userid,
                    'action'  => $action,
                    'enrolid' => $enrolid,
                ]),
                $userfacing,
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        $newdata = [
            'enrolment_id' => $data->id,
            'student_id' => $student->id,
            'user_id' => $userid,
            'course_id' => $data->courseid ?? null,
            'unit_code' => $data->unitcode ?? '',
            'outcome' => $data->outcomeidentifier ?? '',
            'status' => $data->status ?? '',
            'delivery_mode' => $data->deliverymode ?? '',
        ];

        // Audit logging is non-fatal — if it fails the enrolment is still saved.
        try {
            if ($iscreate) {
                audit_logger::log_create(
                    audit_logger::ENTITY_ENROLMENT,
                    $data->id,
                    'Enrolment created: Student ' . $student->id . ', User ' . $userid . ', Unit ' . ($data->unitcode ?? 'N/A'),
                    $newdata
                );
            } else {
                audit_logger::log_update(
                    audit_logger::ENTITY_ENROLMENT,
                    $data->id,
                    'Enrolment updated: Student ' . $student->id . ', User ' . $userid . ', Unit ' . ($data->unitcode ?? 'N/A'),
                    $olddata,
                    $newdata
                );
            }
        } catch (\Throwable $ignored) {
            // Audit log failure is non-fatal.
        }

        // BUG-SR-OUTCOME-SAVE-3 (v4.2.26): re-fetch the saved row and surface
        // the persisted outcome + status in the success toast so the user can
        // verify the save actually took effect (without needing to reopen the
        // edit form).  If the values shown here don't match what was selected,
        // the [rto/enrolment-save-debug] error_log line will reveal whether the
        // form transmitted the wrong value (browser/JS issue) or whether the
        // DB rejected the write (column type / constraint issue).
        $saved = $DB->get_record('local_rtocompliance_enrolments', ['id' => $data->id], 'id, outcomeidentifier, status', IGNORE_MISSING);
        $verifymsg = get_string('enrolment_saved', 'local_rtocompliance');
        if ($saved) {
            $verifymsg .= ' (outcome=' . ($saved->outcomeidentifier ?? '?')
                . ', status=' . ($saved->status ?? '?') . ')';
            error_log('[rto/enrolment-save-debug] PERSISTED enrolid=' . $saved->id
                . ' outcome=' . var_export($saved->outcomeidentifier, true)
                . ' status=' . var_export($saved->status, true));
        }

        redirect(
            new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid]),
            $verifymsg,
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // BUG-SR-OUTCOME-SAVE-3 (v4.2.26): set_data() lives here — only on initial
    // display (form not submitted, not cancelled) — so it never overrides the
    // user's POST values when re-rendering after a validation failure.
    // BUG-SR-OUTCOME-SAVE-4 (v4.2.32): only set_data() on a true initial display
    // (no POST happened).  When the form was submitted but validation failed,
    // get_data() returns null — Moodle has already loaded the user's POST
    // values into the form internals, so calling set_data() here would
    // re-overwrite them with the OLD record (the same ordering bug v4.2.26
    // fixed for the SUBMITTED branch).
    $ispost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!$ispost) {
        // TASK-25 (v5.9.324): Pre-populate purchasingcontract_slot from the
        // saved purchasingcontract1 value so the slot selector shows the correct
        // option when re-opening an existing enrolment for edit.
        // Compare the stored value against the three configured QLD contract codes
        // and select the matching slot; fall back to 'auto' when no match is found.
        $qld_slot_c1 = get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '';
        $qld_slot_c2 = get_config('local_rtocompliance', 'qld_purchasing_contract_2') ?: '';
        $qld_slot_c3 = get_config('local_rtocompliance', 'qld_purchasing_contract_3') ?: '';
        $saved_contract = $enrolment->purchasingcontract1 ?? '';
        if ($saved_contract !== '' && $qld_slot_c1 !== '' && $saved_contract === $qld_slot_c1) {
            $enrolment->purchasingcontract_slot = 'contract1';
        } elseif ($saved_contract !== '' && $qld_slot_c2 !== '' && $saved_contract === $qld_slot_c2) {
            $enrolment->purchasingcontract_slot = 'contract2';
        } elseif ($saved_contract !== '' && $qld_slot_c3 !== '' && $saved_contract === $qld_slot_c3) {
            $enrolment->purchasingcontract_slot = 'contract3';
        } else {
            $enrolment->purchasingcontract_slot = 'auto';
        }
        $form->set_data($enrolment);
    } else {
        // POST happened but get_data() returned null → validation failed.
        // Surface a clear top-of-page notification so users don't think
        // "nothing happened" — the form's per-field error markers are easy
        // to miss on long forms (BUG-SR-OUTCOME-SAVE-4: courseid silent fail).
        $errors = (array) ($form->_form->_errors ?? []);
        $errfields = [];
        foreach ($errors as $field => $msg) {
            // Map machine field names to user-visible labels where possible.
            $label = $field;
            $stringkey = $field;
            if (get_string_manager()->string_exists($stringkey, 'local_rtocompliance')) {
                $label = get_string($stringkey, 'local_rtocompliance');
            } else if (get_string_manager()->string_exists($field, 'core')) {
                $label = get_string($field);
            }
            $errfields[] = s($label) . ' — ' . s($msg);
        }
        $banner = 'The enrolment was NOT saved because the form has validation errors. Please review the highlighted fields below and click Save again.';
        if (!empty($errfields)) {
            $banner .= '<ul style="margin:8px 0 0 18px;">';
            foreach ($errfields as $line) {
                $banner .= '<li>' . $line . '</li>';
            }
            $banner .= '</ul>';
        }
        \core\notification::error($banner);
        error_log('[rto/enrolment-save-validfail] action=' . $action
            . ' enrolid=' . ($enrolid ?: 'new')
            . ' studentid=' . $student->id
            . ' errors=' . json_encode(array_keys($errors)));
    }

    $PAGE->add_body_class("path-local-rtocompliance");
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header(($action === 'edit' ? get_string('edit_enrolment', 'local_rtocompliance') : get_string('add_enrolment', 'local_rtocompliance')), get_string('students', 'local_rtocompliance'), '/local/rtocompliance/students.php');
    echo local_rtocompliance_page_banner(($action === 'edit' ? get_string('edit_enrolment', 'local_rtocompliance') : get_string('add_enrolment', 'local_rtocompliance')));
    echo $OUTPUT->heading(($action === 'edit' ? get_string('edit_enrolment', 'local_rtocompliance') : get_string('add_enrolment', 'local_rtocompliance')), 3);
    $form->display();
    echo $OUTPUT->footer();
    return;
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('enrolments', 'local_rtocompliance'), get_string('students', 'local_rtocompliance'), '/local/rtocompliance/students.php', 'students');
echo local_rtocompliance_page_banner(get_string('enrolments', 'local_rtocompliance'));
echo $OUTPUT->heading(fullname($user) . ' - ' . get_string('enrolments', 'local_rtocompliance'), 3);

$addurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid, 'action' => 'add']);
echo html_writer::link($addurl, get_string('add_enrolment', 'local_rtocompliance'), ['class' => 'btn btn-primary mb-3', 'title' => 'Add a new unit enrolment record for this learner']);

$profileurl = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $userid]);
echo html_writer::link($profileurl, get_string('editprofile', 'local_rtocompliance'), ['class' => 'btn btn-outline-secondary mb-3 ml-2', 'title' => 'Open and edit the AVETMISS profile for this learner']);

// Plain-English explainer card for the enrolments table.
echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;">'
    . '<div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">About this enrolments list</div>'
    . '<div style="font-size:14.5px;color:#334155;line-height:1.55;margin-bottom:8px;">Each row is one unit of competency this learner is enrolled in, with its AVETMISS result. These records feed national reporting and certificate issuance. Here is what each column means:</div>'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;">'
    . '<div><strong>Unit</strong> &mdash; the unit code and title the learner is enrolled in.</div>'
    . '<div><strong>Start date</strong> &mdash; the activity start date for the unit.</div>'
    . '<div><strong>Outcome</strong> &mdash; the AVETMISS national outcome, or Not yet assessed if none is recorded.</div>'
    . '<div><strong>Status</strong> &mdash; whether the enrolment is active, completed, withdrawn or on hold.</div>'
    . '<div><strong>Contract</strong> &mdash; the purchasing contract for state-funded enrolments (shown only when relevant).</div>'
    . '<div><strong>Actions</strong> &mdash; edit, mark complete, or delete the enrolment record.</div>'
    . '</div></div>';

// ----------------------------------------------------------------
// Detect Moodle-native course enrolments not yet in the RTO table.
// ----------------------------------------------------------------
$moodle_courses = rtocompliance_get_moodle_enrolments($userid);
$rto_courseids  = $DB->get_fieldset_select(
    'local_rtocompliance_enrolments',
    'courseid',
    'studentid = :sid',
    ['sid' => $student->id]
);
$unimported = [];
foreach ($moodle_courses as $mc) {
    if (!in_array($mc->courseid, $rto_courseids)) {
        $unimported[] = $mc;
    }
}

if (!empty($unimported)) {
    // Show info panel listing courses that exist in Moodle but have no RTO record yet.
    echo html_writer::start_div('alert alert-info rto-import-panel');
    echo html_writer::tag('strong', get_string('moodle_enrolments_found', 'local_rtocompliance'));
    echo html_writer::tag('p', get_string('moodle_enrolments_found_desc', 'local_rtocompliance'));

    echo html_writer::start_tag('ul', ['class' => 'rto-import-course-list']);
    foreach ($unimported as $mc) {
        $label = s($mc->fullname);
        if ($mc->shortname) {
            $label .= ' <small class="text-muted">(' . s($mc->shortname) . ')</small>';
        }
        if ($mc->timestart) {
            $label .= ' — enrolled ' . userdate($mc->timestart, get_string('strftimedate', 'langconfig')); // v5.9.405: include year.
        }
        echo html_writer::tag('li', $label);
    }
    echo html_writer::end_tag('ul');

    $import_url = new moodle_url('/local/rtocompliance/student_enrolments.php', [
        'userid'  => $userid,
        'action'  => 'import',
        'sesskey' => sesskey(),
    ]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $import_url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'import']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid',  'value' => $userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    // TASK-32 (v5.9.325): Contract slot selector — only shown when at least one QLD
    // purchasing contract is configured.  Defaults to Auto so existing behaviour is
    // unchanged for RTOs that have not configured QLD contracts.
    $imp_qld_c1 = get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '';
    $imp_qld_c2 = get_config('local_rtocompliance', 'qld_purchasing_contract_2') ?: '';
    $imp_qld_c3 = get_config('local_rtocompliance', 'qld_purchasing_contract_3') ?: '';
    if ($imp_qld_c1 !== '' || $imp_qld_c2 !== '' || $imp_qld_c3 !== '') {
        $imp_slots = ['auto' => get_string('purchasingcontract_slot_auto', 'local_rtocompliance')];
        $slot1label = $imp_qld_c1 ?: get_string('purchasingcontract_slot_notset', 'local_rtocompliance');
        $imp_slots['contract1'] = get_string('purchasingcontract_slot_1', 'local_rtocompliance', $slot1label);
        if ($imp_qld_c2 !== '') {
            $imp_slots['contract2'] = get_string('purchasingcontract_slot_2', 'local_rtocompliance', $imp_qld_c2);
        }
        if ($imp_qld_c3 !== '') {
            $imp_slots['contract3'] = get_string('purchasingcontract_slot_3', 'local_rtocompliance', $imp_qld_c3);
        }

        $select_html = html_writer::start_div('form-group d-flex align-items-center mb-2', ['style' => 'gap:0.5rem']);
        $select_html .= html_writer::tag('label',
            get_string('purchasingcontract_slot', 'local_rtocompliance') . ':',
            ['for' => 'import_contract_slot', 'class' => 'mb-0 font-weight-bold', 'style' => 'white-space:nowrap']);
        $select_opts = '';
        foreach ($imp_slots as $val => $label) {
            $select_opts .= html_writer::tag('option', s($label), ['value' => $val]);
        }
        $select_html .= html_writer::tag('select', $select_opts,
            ['name' => 'purchasingcontract_slot', 'id' => 'import_contract_slot', 'class' => 'custom-select custom-select-sm', 'style' => 'max-width:280px']);
        $select_html .= html_writer::end_div();
        echo $select_html;
    }

    echo html_writer::tag(
        'button',
        get_string('import_moodle_enrolments_btn', 'local_rtocompliance', ['count' => count($unimported)]),
        ['type' => 'submit', 'class' => 'btn btn-primary', 'title' => 'Create RTO enrolment records from the Moodle course enrolments listed above']
    );
    echo html_writer::end_tag('form');
    echo html_writer::tag('p', get_string('import_moodle_enrolments_hint', 'local_rtocompliance'), ['class' => 'mt-2 mb-0 text-muted small']);
    echo html_writer::end_div();
}

$enrolments = $DB->get_records('local_rtocompliance_enrolments', ['studentid' => $student->id], 'activitystartdate DESC');

// TASK-31: Determine whether to show the Contract column.
// Only shown when the student has at least one state-funded enrolment (QLD codes 13 or 15).
$has_state_funded = false;
foreach ($enrolments as $_e) {
    if (in_array((string)$_e->fundingsourcenat, ['13', '15'])) {
        $has_state_funded = true;
        break;
    }
}
// RTO-level default contract — used as a fallback label when purchasingcontract1 is empty.
$rto_default_contract = $has_state_funded
    ? (get_config('local_rtocompliance', 'qld_purchasing_contract_1') ?: '')
    : '';

if (empty($enrolments)) {
    if (empty($moodle_courses)) {
        // Truly not enrolled anywhere.
        echo $OUTPUT->notification(get_string('no_enrolments_anywhere', 'local_rtocompliance'), \core\output\notification::NOTIFY_INFO);
    } else {
        // Has Moodle enrolments but none imported yet — the banner above handles the CTA.
        echo $OUTPUT->notification(get_string('no_enrolments_use_import', 'local_rtocompliance'), \core\output\notification::NOTIFY_INFO);
    }
} else {
    $table = new html_table();
    $table->head = [
        html_writer::tag('span', get_string('unit', 'local_rtocompliance'), ['title' => 'The unit of competency code and title the learner is enrolled in']),
        html_writer::tag('span', get_string('activitystartdate', 'local_rtocompliance'), ['title' => 'The activity start date recorded for this unit']),
        html_writer::tag('span', get_string('outcome', 'local_rtocompliance'), ['title' => 'The AVETMISS national outcome, or Not yet assessed if none is recorded']),
        html_writer::tag('span', get_string('enrolmentstatus', 'local_rtocompliance'), ['title' => 'Whether the enrolment is active, completed, withdrawn or on hold']),
    ];
    // TASK-31: Show Contract column only when at least one state-funded enrolment exists.
    if ($has_state_funded) {
        $table->head[] = html_writer::tag('span', get_string('col_contract', 'local_rtocompliance'), ['title' => 'The purchasing contract for this state-funded enrolment']);
    }
    $table->head[] = html_writer::tag('span', get_string('actions'), ['title' => 'Edit, mark complete or delete this enrolment record']);
    $table->attributes['class'] = 'generaltable';

    $outcomes = avetmiss_codes::get_outcome_identifiers();
    // FIX: load only the courses actually referenced by this student's enrolments
    // instead of fetching every course on the site into memory.
    $courseids = array_unique(array_column((array)$enrolments, 'courseid'));
    $courses = [];
    if (!empty($courseids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_sql(
            "SELECT id, fullname FROM {course} WHERE id $insql", $inparams
        );
        $courses = array_column((array)$courses, 'fullname', 'id');
    }

    foreach ($enrolments as $enrolment) {
        $coursename = $courses[$enrolment->courseid] ?? get_string('unknown', 'local_rtocompliance');
        
        $unitcell = '';
        if (!empty($enrolment->unitcode)) {
            $unitcell = $enrolment->unitcode;
            if (!empty($enrolment->unitname)) {
                $unitcell .= '<br><small class="text-muted">' . s($enrolment->unitname) . '</small>';
            }
        }
        
        // v5.9.405: use the FULL date (with year) — strftimedateshort omits the year.
        $startdate = $enrolment->activitystartdate ? userdate($enrolment->activitystartdate, get_string('strftimedate', 'langconfig')) : '-';

        // v5.9.405: '00'/'10'/blank are not valid AVETMISS national outcomes — they mean
        // "no result recorded yet". Show a clear label instead of the raw code.
        $oc = trim((string)$enrolment->outcomeidentifier);
        $outcomename = $outcomes[$oc] ?? (($oc === '' || $oc === '00' || $oc === '10') ? 'Not yet assessed' : $oc);
        
        $statusbadge = '';
        switch ($enrolment->status) {
            case 'active':
                $statusbadge = '<span class="badge badge-primary">' . get_string('active', 'local_rtocompliance') . '</span>';
                break;
            case 'completed':
                $statusbadge = '<span class="badge badge-success">' . get_string('completed') . '</span>';
                break;
            case 'withdrawn':
                $statusbadge = '<span class="badge badge-danger">' . get_string('withdrawn', 'local_rtocompliance') . '</span>';
                break;
            case 'hold':
                $statusbadge = '<span class="badge badge-warning">' . get_string('onhold', 'local_rtocompliance') . '</span>';
                break;
        }
        
        $editurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid, 'action' => 'edit', 'enrolid' => $enrolment->id]);
        $deleteurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid, 'action' => 'delete', 'enrolid' => $enrolment->id]);
        $completeurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $userid, 'action' => 'markcomplete', 'enrolid' => $enrolment->id, 'sesskey' => sesskey()]);
        
        $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-primary mr-1', 'title' => 'Edit this enrolment record']);
        if ($enrolment->status !== 'completed') {
            $actions .= html_writer::link($completeurl, 'Complete', ['class' => 'btn btn-sm btn-outline-success mr-1', 'title' => 'Mark this enrolment as completed (outcome: Competency Achieved)']);
        }
        $actions .= html_writer::link($deleteurl, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete this enrolment record']);
        
        // TASK-31: Build the contract cell for state-funded rows.
        $contractcell = '';
        if ($has_state_funded) {
            if (in_array((string)$enrolment->fundingsourcenat, ['13', '15'])) {
                $val = trim($enrolment->purchasingcontract1 ?? '');
                if ($val !== '') {
                    $contractcell = s($val);
                } elseif ($rto_default_contract !== '') {
                    // Empty on the enrolment — NAT export will use the RTO default.
                    $contractcell = html_writer::tag(
                        'span',
                        s($rto_default_contract),
                        ['class' => 'text-muted', 'title' => get_string('col_contract_default_tip', 'local_rtocompliance')]
                    );
                } else {
                    $contractcell = html_writer::tag('span', '—', ['class' => 'text-muted']);
                }
            }
            // Non-state-funded rows: leave $contractcell empty (no contract applies).
        }

        $row = [
            $unitcell,
            $startdate,
            $outcomename,
            $statusbadge,
        ];
        if ($has_state_funded) {
            $row[] = $contractcell;
        }
        $row[] = $actions;
        $table->data[] = $row;
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
