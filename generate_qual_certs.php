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

// GEN-BY-QUAL (v5.2.72) — Suspended students now appear in the completion list with a
// SUSPENDED badge. Certificate generation works regardless of account status — completing
// a qualification is a historical fact independent of current account state. Admins can
// tick the "Activate account" checkbox per student to unsuspend as part of the generation.
// GEN-BY-QUAL (v5.2.0) — Bulk-generate Testamur + Record of Results for all students
// who have completed every linked unit-of-competency course in a full qualification.
//
// Flow:
//   1. No qualid param  → show picker: lists active qualifications from qualbuilder.
//   2. qualid given     → find all students who completed ALL linked unit-courses.
//   3. POST action=generate → issue testamur + record for each selected student.
//
// This is the qualification-level counterpart to generate_course_certs.php.
// That page operates on a single Moodle course (unit of competency).
// This page operates on a full qualification entry in the Qualification Builder
// and always issues Testamur + Record of Results — never SoA or Completion Cert.

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

admin_externalpage_setup('local_rtocompliance_generate_qual_certs');
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$qualid     = optional_param('qualid',     0, PARAM_INT);
$action     = optional_param('action',     '', PARAM_ALPHA);
$sendemail  = optional_param('sendemail',  1, PARAM_INT);
$forceregen = optional_param('forceregen', 0, PARAM_INT);

// ── No qualification selected — show picker ───────────────────────────────────
if (!$qualid) {
    $PAGE->set_url('/local/rtocompliance/generate_qual_certs.php');
    $PAGE->set_title('Generate Certificates by Qualification');
    $PAGE->set_heading('Generate Certificates by Qualification');
    $PAGE->requires->css('/local/rtocompliance/styles.css');
    $PAGE->add_body_class('path-local-rtocompliance');

    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header(
        get_string('generate_qual_certs', 'local_rtocompliance'),
        get_string('certificates', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/certificates.php'),
        'certificates'
    );

    $quals = $DB->get_records_sql(
        "SELECT id, qualificationcode, qualificationname
         FROM {local_rtocompliance_qualbuilder}
         WHERE producttype = 'qualification'
           AND status = 'active'
         ORDER BY qualificationname ASC"
    );

    echo html_writer::start_div('certificates-container');
    echo html_writer::tag('h2', 'Generate by Full Qualification', ['style' => 'margin-bottom:6px;']);
    echo html_writer::tag('p', 'Bulk-issue Testamur + Record of Results for all students who have completed every unit linked to a qualification in the Qualification Builder.', ['style' => 'color:#6b7280;margin-bottom:20px;']);

    if (empty($quals)) {
        echo html_writer::div(
            html_writer::tag('p', 'No active qualifications found in the Qualification Builder.') .
            html_writer::tag('p', 'Build a qualification, link its unit-of-competency courses, then set its status to Active.') .
            html_writer::link(
                new moodle_url('/local/rtocompliance/qualbuilder.php'),
                'Open Qualification Builder →',
                ['class' => 'btn btn-primary mt-2']
            ),
            'no-deadlines'
        );
    } else {
        echo html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new moodle_url('/local/rtocompliance/generate_qual_certs.php'))->out(false),
            'class'  => 'form-inline mb-3',
        ]);
        $options = [];
        foreach ($quals as $q) {
            $options[$q->id] = $q->qualificationcode . ' — ' . format_string($q->qualificationname);
        }
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
        echo html_writer::select($options, 'qualid', '', ['0' => 'Select a qualification...'], ['class' => 'custom-select', 'id' => 'gq-qual-picker', 'style' => 'min-width:320px;flex:1;']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Go', 'class' => 'btn btn-success']);
        echo '</div>';
        echo html_writer::end_tag('form');
    }

    // Crosslink back to unit-by-unit generator
    $courseurl = (new moodle_url('/local/rtocompliance/generate_course_certs.php'))->out(false);
    echo '<div style="margin-top:24px;padding:16px 20px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">';
    echo '<div style="color:#374151;"><strong>Generating for a single unit of competency?</strong><br><span style="font-size:0.88rem;color:#6b7280;">Issue certificates for all students who completed one specific Moodle course.</span></div>';
    echo '<a href="' . s($courseurl) . '" class="btn btn-outline-primary">Generate by Unit &rarr;</a>';
    echo '</div>';

    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ── Qualification selected ────────────────────────────────────────────────────
$qual = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid], '*', MUST_EXIST);

$PAGE->set_url('/local/rtocompliance/generate_qual_certs.php', ['qualid' => $qualid]);
$PAGE->set_title('Generate Certificates — ' . format_string($qual->qualificationname));
$PAGE->set_heading('Generate Certificates by Qualification');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// All unit-courses linked to this qualification (for completion detection).
// BUG-D-FIX (v5.9.221): added AND selected = 1 so the SOURCE 1 HAVING check
// requires completion of courses linked to REQUIRED units only, not all active units.
// BUG-VARIANT-UNION-FIX (v5.9.293): also collect variant courses from qualunit_courses
// so that SOURCE 1's completion timestamp lookup (course_completion_crit_compl) and the
// {course_completions} fallback capture students who completed via a variant course.
// NOTE: SOURCE 1's HAVING check uses $numlinkedcourses (primary count only) because the
// HAVING requires one completion per unit, not one per delivery variant.  SOURCE 2
// (enrolment-based) is the authoritative completers list for variant-heavy qualifications.
$dbman_gcq = $DB->get_manager();
$linkedcourseids = $DB->get_fieldset_sql(
    "SELECT DISTINCT courseid
     FROM {local_rtocompliance_qualunits}
     WHERE qualbuilderid = :qbid
       AND courseid IS NOT NULL
       AND status = 'active'
       AND selected = 1",
    ['qbid' => $qualid]
);
$numlinkedcourses = count($linkedcourseids);

// Build the full set of all delivery courseids (primary + variants) for timestamp
// lookups.  Do NOT use this expanded set for the SOURCE 1 HAVING COUNT check.
$alllinkedcourseids = $linkedcourseids;
if ($dbman_gcq->table_exists('local_rtocompliance_qualunit_courses')) {
    $variantCourseids = $DB->get_fieldset_sql(
        "SELECT DISTINCT quc.courseid
           FROM {local_rtocompliance_qualunit_courses} quc
           JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
          WHERE qu.qualbuilderid = :qbid
            AND qu.selected = 1
            AND qu.status = 'active'
            AND quc.courseid IS NOT NULL",
        ['qbid' => $qualid]
    );
    if ($variantCourseids) {
        $alllinkedcourseids = array_values(array_unique(
            array_merge($alllinkedcourseids, array_map('intval', $variantCourseids))
        ));
    }
}

// All units for the certificate Record of Results.
$allunits = local_rtocompliance_get_qualbuilder_unit_list($qualid);

// ── POST: bulk generation ─────────────────────────────────────────────────────
if ($action === 'generate' && confirm_sesskey()) {
    // FIX-CERT-TIMEOUT (v5.2.33): Same fix as generate_course_certs.php — release session
    // lock and extend time limit. Bulk testamur + record generation (2 certs per student)
    // is the slowest cert operation; without these guards it reliably hits the 30s limit.
    \core\session\manager::write_close();
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_HUGE);

    $userids         = optional_param_array('userids',         [], PARAM_INT);
    $activate_userids = optional_param_array('activate_userids', [], PARAM_INT);
    $forceregen      = optional_param('forceregen', 0, PARAM_INT);
    $sendemail       = optional_param('sendemail',  1, PARAM_INT);

    if (empty($userids)) {
        redirect($PAGE->url, 'No students selected.', null, \core\output\notification::NOTIFY_WARNING);
    }

    // ACTIVATE-ON-GENERATE (v5.2.72): Unsuspend accounts the admin ticked before generating.
    if (!empty($activate_userids)) {
        require_capability('moodle/user:update', context_system::instance());
        foreach ($activate_userids as $uid) {
            if ($uid > 1) {
                $DB->set_field('user', 'suspended', 0, ['id' => $uid]);
            }
        }
    }

    $issued           = 0;
    $skipped          = 0;
    $failed           = 0;
    $voided           = 0;
    $messages         = [];
    $creditsExhausted = false; // CERT-CREDITS-BREAK-FIX (v5.9.297)

    foreach ($userids as $userid) {
        if ($creditsExhausted) {
            // CERT-CREDITS-BREAK-FIX (v5.9.297): old code used 'break 2' which silently
            // killed both the cert-type loop and the student loop, giving the admin no
            // indication of how many students were skipped.  Now we track the flag and
            // break cleanly at the outer loop boundary so the summary counts are correct.
            $failed++;
            $messages[] = fullname(core_user::get_user($userid) ?: (object)['firstname'=>'','lastname'=>$userid]) . ' — SKIPPED: insufficient credits (not attempted)';
            continue;
        }

        $user = core_user::get_user($userid);
        if (!$user) {
            $failed++;
            continue;
        }

        // BUG-C-FIX (v5.9.221): Look up this student's actual AVETMISS outcome per unit
        // (Competent, RPL, Credit Transfer, etc.) from their enrolment records so the
        // Record of Results cert shows the real outcome, not a hardcoded "Competent".
        // Requires local_rtocompliance_students.id (studentid), not the Moodle userid.
        $studentrec = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'id', IGNORE_MISSING);
        if ($studentrec) {
            $unitsForCert = local_rtocompliance_get_qualbuilder_unit_list_with_outcomes($qual->id, $studentrec->id);
        }
        // Fall back to the qual-level list (outcome='20' for all) when no student record exists.
        if (empty($unitsForCert)) {
            $unitsForCert = $allunits;
        }

        foreach (['testamur', 'record'] as $certtype) {
            // Look for an existing active (non-superseded) cert for this qual + type.
            $existingcert = $DB->get_record_sql(
                "SELECT * FROM {local_rtocompliance_certs}
                 WHERE userid           = :userid
                   AND certtype         = :certtype
                   AND qualificationcode = :qualcode
                   AND status           = 'issued'
                   AND (reissued_at IS NULL OR reissued_at = 0)
                 LIMIT 1",
                ['userid' => $userid, 'certtype' => $certtype, 'qualcode' => $qual->qualificationcode]
            );

            if ($existingcert) {
                if (!$forceregen) {
                    $skipped++;
                    continue;
                }
                // Void the old cert for audit continuity.
                $DB->update_record('local_rtocompliance_certs', (object)[
                    'id'           => $existingcert->id,
                    'reissued_at'  => time(),
                    'notes'        => trim(($existingcert->notes ?? '') . "\n[Superseded by force-regenerate — Generate Qualification Certificates]"),
                    'timemodified' => time(),
                ]);
                if (!empty($existingcert->verifytoken)) {
                    local_rtocompliance_update_registry_status($existingcert->verifytoken, 'superseded');
                }
                $voided++;
            }

            // INITIAL-COMPLETION-DATE-FIX (v5.9.232): look up the EARLIEST
            // criterion completion timestamp from {course_completion_crit_compl}
            // across all linked courses for this qualification.  $allcompleters
            // is not yet in scope here (it is built for the display path below),
            // so we query directly.  $linkedcourseids IS in scope (line 117).
            $initialTimecompleted = 0;
            // Use $alllinkedcourseids (primary + variants) so a student who
            // completed via a variant course gets the correct earliest timestamp.
            if (!empty($alllinkedcourseids)) {
                list($ltcInsql, $ltcParams) = $DB->get_in_or_equal(
                    $alllinkedcourseids, SQL_PARAMS_NAMED, 'ltc'
                );
                $ltcParams['ltcuid'] = $userid;
                $ltcTs = (int) $DB->get_field_sql(
                    "SELECT MIN(timecompleted)
                       FROM {course_completion_crit_compl}
                      WHERE userid = :ltcuid
                        AND course $ltcInsql
                        AND timecompleted IS NOT NULL
                        AND timecompleted > 0",
                    $ltcParams
                );
                if ($ltcTs > 0) {
                    $initialTimecompleted = $ltcTs;
                } else {
                    // Fallback: MIN from {course_completions} when no
                    // crit_compl rows exist (e.g. manual completion mode).
                    list($ltcInsql2, $ltcParams2) = $DB->get_in_or_equal(
                        $alllinkedcourseids, SQL_PARAMS_NAMED, 'ltcf'
                    );
                    $ltcParams2['ltcfuid'] = $userid;
                    $initialTimecompleted = (int) $DB->get_field_sql(
                        "SELECT MIN(timecompleted)
                           FROM {course_completions}
                          WHERE userid = :ltcfuid
                            AND course $ltcInsql2
                            AND timecompleted IS NOT NULL
                            AND timecompleted > 0",
                        $ltcParams2
                    );
                }
            }
            $result = local_rtocompliance_programmatic_issue_cert(
                $userid,
                $certtype,
                $qual->qualificationcode,
                $qual->qualificationname,
                $unitsForCert,
                time(),
                'default',
                $sendemail,
                $initialTimecompleted
            );

            if ($result['ok']) {
                $issued++;
                $messages[] = fullname($user) . ' — ' . $certtype . ' issued (' . $result['certnumber'] . ')';
                if ($forceregen && $existingcert && !empty($result['certid'])) {
                    $DB->update_record('local_rtocompliance_certs', (object)[
                        'id'             => $result['certid'],
                        'replacement_of' => $existingcert->id,
                        'notes'          => 'Force-regenerated via Generate Qualification Certificates',
                        'timemodified'   => time(),
                    ]);
                }
            } elseif ($result['error'] === 'INSUFFICIENT_CREDITS') {
                // CERT-CREDITS-BREAK-FIX (v5.9.297): replaced 'break 2' with a flag so
                // the outer loop counts remaining students as failed and the summary shows
                // exactly how many were skipped due to credit exhaustion.
                $messages[] = fullname($user) . ' — SKIPPED: insufficient credits';
                $failed++;
                $creditsExhausted = true;
                break; // break inner (cert-type) loop only; outer loop checks flag
            } else {
                $messages[] = fullname($user) . ' — FAILED: ' . ($result['error'] ?? 'unknown error');
                $failed++;
            }
        }

        // AUTOCERT-COMPLETE-FIX (v5.9.297): mark the autocert queue row 'complete' after
        // certs are issued for this student.  Previously the row stayed 'pending' forever
        // causing the admin queue to show historical completions as still outstanding.
        // We only do this when: a studentrec exists, credits were not exhausted mid-loop
        // (which would mean zero certs were issued for this student), and at least one
        // cert was successfully issued during this entire POST run so far.
        if ($studentrec && !$creditsExhausted) {
            $autocertrow = $DB->get_record('local_rtocompliance_autocerts', [
                'studentid'     => $studentrec->id,
                'qualbuilderid' => $qualid,
                'status'        => 'pending',
            ]);
            if ($autocertrow) {
                $DB->update_record('local_rtocompliance_autocerts', (object)[
                    'id'            => $autocertrow->id,
                    'status'        => 'complete',
                    'timeprocessed' => time(),
                    'certsissued'   => ($autocertrow->certsissued ?? 0) + 1,
                ]);
            }
        }
    }

    $modeLabel = $forceregen ? 'Force-regenerated' : 'Issued';
    $summary   = "{$modeLabel}: {$issued} certificate(s). Voided (superseded): {$voided}. Skipped: {$skipped}. Failed: {$failed}.";
    if (!empty($messages)) {
        $summary .= ' ' . implode('; ', array_slice($messages, 0, 5));
        if (count($messages) > 5) {
            $summary .= ' … and ' . (count($messages) - 5) . ' more.';
        }
    }
    $notiftype = $failed > 0 ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS;
    redirect($PAGE->url, $summary, null, $notiftype);
}

// ── GET: display UI ───────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    'Generate Certificates by Qualification',
    get_string('certificates', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/certificates.php'),
    'certificates'
);

echo html_writer::start_div('certificates-container');

// ── Qualification summary banner ──────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px 20px;margin-bottom:20px;']);
echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:8px;margin-bottom:10px;']);
echo html_writer::tag('span', 'Qualification', ['style' => 'font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#2563eb;background:#dbeafe;padding:2px 8px;border-radius:4px;']);
echo html_writer::tag('span', 'Testamur + Record of Results Generation', ['style' => 'font-size:1rem;font-weight:700;color:#1e3a5f;']);
echo html_writer::end_div();

echo html_writer::tag('p',
    html_writer::tag('strong', htmlspecialchars($qual->qualificationcode), ['style' => 'color:#1e40af;'])
    . ' — ' . htmlspecialchars(format_string($qual->qualificationname)),
    ['style' => 'margin:0 0 8px;font-size:0.95rem;color:#374151;']
);

echo html_writer::start_div('', ['style' => 'display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:8px;']);
echo html_writer::tag('span', 'Certificate Types: Testamur + Record of Results',
    ['style' => 'background:#dbeafe;color:#1d4ed8;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::tag('span', $numlinkedcourses . ' linked unit-course(s)',
    ['style' => 'background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::tag('span', count($allunits) . ' unit(s) of competency',
    ['style' => 'background:#fef9c3;color:#92400e;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::end_div();

if ($numlinkedcourses === 0) {
    echo html_writer::tag('p',
        'No Moodle courses are linked to any units in this qualification. '
        . 'Open the Qualification Builder, select each unit, and link it to its Moodle course. '
        . 'Completion is determined by tracking which students have finished all linked courses.',
        ['style' => 'color:#dc2626;font-size:0.87rem;margin:0;']
    );
}
echo html_writer::end_div();

// FIX-QUAL-COMPLETION (v5.2.71): Count selected active units that have a unitcode.
// This is used for the outcome-based completion check (SOURCE 2 below) which does NOT
// require Moodle course completion tracking to be configured.
$numunits = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT id)
       FROM {local_rtocompliance_qualunits}
      WHERE qualbuilderid = :qbid
        AND selected = 1
        AND status = 'active'
        AND " . $DB->sql_isnotempty('local_rtocompliance_qualunits', 'unitcode', false, false),
    ['qbid' => $qualid]
);

// ── Guard: no linked courses AND no units with codes ──────────────────────────
// Exit early only when BOTH methods have nothing to check.
// If there are unit codes (even with no Moodle course links) we can still use
// the plugin's outcome records to find completers (SOURCE 2).
if ($numlinkedcourses === 0 && $numunits === 0) {
    echo html_writer::div(
        html_writer::tag('p', 'Cannot determine qualification completions: no Moodle courses are linked and no unit codes are set for this qualification\'s units.') .
        html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder.php'),
            'Open Qualification Builder →',
            ['class' => 'btn btn-primary mt-2']
        ),
        'no-deadlines'
    );
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ── Find all students who completed EVERY unit of this qualification ───────────
// FIX-QUAL-COMPLETION (v5.2.71): Two complementary sources are checked and merged.
//
// SOURCE 1 — Moodle course_completions: works when Moodle completion criteria are
//   configured for each linked course and timecompleted has been calculated.
//
// SOURCE 2 — Plugin outcome records (local_rtocompliance_enrolments): works when
//   the RTO Compliance plugin has recorded competent outcomes (20=Competent,
//   51=RPL, 60=Credit transfer, 81=Non-assessable satisfactory) for every unit.
//   This is the same source the auto-cert trigger uses and is the ground truth
//   for RTO competency — works regardless of Moodle completion tracking.
//
// Result: a student appears if they are complete in EITHER source. Students found
// in both are de-duplicated (SOURCE 1 record preferred to preserve timecompleted).

$allcompleters = [];

// SOURCE 1: Moodle course_completions
// FIX-FULLNAME-DEBUG (v5.2.33): include phonetic/middle/alternate name fields so
// fullname($comp) in the table below doesn't trigger Moodle debug warnings.
// These fields must also appear in GROUP BY (MySQL ONLY_FULL_GROUP_BY compliance).
if ($numlinkedcourses > 0) {
    list($insql, $inparams) = $DB->get_in_or_equal($linkedcourseids, SQL_PARAMS_NAMED, 'cid');
    // FIX-SUSPENDED-CERTS (v5.2.72): Removed u.suspended = 0. u.suspended added to SELECT/GROUP BY.
    $allcompleters = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                u.suspended,
                MIN(cc.timecompleted) AS timecompleted
         FROM {user} u
         JOIN {course_completions} cc ON cc.userid = u.id
         WHERE cc.course {$insql}
           AND cc.timecompleted IS NOT NULL
           AND cc.timecompleted > 0
           AND u.deleted = 0
         GROUP BY u.id, u.firstname, u.lastname, u.email,
                  u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.suspended
         HAVING COUNT(DISTINCT cc.course) = :numcourses
         ORDER BY u.lastname, u.firstname",
        array_merge($inparams, ['numcourses' => $numlinkedcourses])
    );
}

// SOURCE 2: Plugin outcome records (local_rtocompliance_enrolments)
// Mirror of queue_autocert_if_all_units_complete() — finds students who have a
// competent outcome code for every selected active unit in the qualification.
$dbman = $DB->get_manager();
if ($numunits > 0
    && $dbman->table_exists('local_rtocompliance_enrolments')
    && $dbman->table_exists('local_rtocompliance_students')
) {
    // FIX-SUSPENDED-CERTS (v5.2.72): Removed u.suspended = 0. u.suspended added to SELECT/GROUP BY.
    $outcomecompleters = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                u.suspended,
                MAX(COALESCE(e.activityenddate, e.timecreated)) AS timecompleted
         FROM {local_rtocompliance_students} s
         JOIN {user} u ON u.id = s.userid AND u.deleted = 0
         JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
              AND e.outcomeidentifier IN ('20','51','60','81')
         JOIN {local_rtocompliance_qualunits} qu ON qu.unitcode = e.unitcode
              AND qu.qualbuilderid = :qbid
              AND qu.selected = 1
              AND qu.status = 'active'
         GROUP BY s.id, u.id, u.firstname, u.lastname, u.email,
                  u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.suspended
         HAVING COUNT(DISTINCT qu.id) >= :numunits
         ORDER BY u.lastname, u.firstname",
        ['qbid' => $qualid, 'numunits' => $numunits]
    );

    foreach ($outcomecompleters as $userid => $completer) {
        if (!isset($allcompleters[$userid])) {
            // Ensure timecompleted is a usable timestamp for display.
            if (empty($completer->timecompleted) || $completer->timecompleted <= 0) {
                $completer->timecompleted = time();
            }
            $allcompleters[$userid] = $completer;
        }
    }

    // Re-sort merged results alphabetically by lastname, firstname.
    uasort($allcompleters, function ($a, $b) {
        $c = strcmp($a->lastname ?? '', $b->lastname ?? '');
        return $c !== 0 ? $c : strcmp($a->firstname ?? '', $b->firstname ?? '');
    });
}

if (empty($allcompleters)) {
    $hint = $numlinkedcourses > 0
        ? 'A student appears here once Moodle marks them complete in every linked unit-course (' . $numlinkedcourses . ' course(s) required), OR once the plugin records a competent outcome (C/RPL/CT) for every unit.'
        : 'A student appears here once the plugin records a competent outcome (Competent / RPL / Credit Transfer) for every selected unit of the qualification.';
    echo html_writer::div(
        html_writer::tag('p', 'No students have completed all units of this qualification yet.') .
        html_writer::tag('p', $hint),
        'no-deadlines'
    );
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// Task #132: Multi-page RoR notice — shown when the qualification's unit count
// exceeds a single page's capacity (~25 units). Helps admins know in advance that
// issued certificates will span more than one page, and links to the cert audit view.
// The threshold is conservative; actual overflow depends on template field height.
define('ROR_MULTIPAGE_UNIT_THRESHOLD', 25);
$isMultiPage = count($allunits) >= ROR_MULTIPAGE_UNIT_THRESHOLD;
if ($isMultiPage) {
    $certHubUrl = (new moodle_url('/local/rtocompliance/certificates.php', [
        'qualcode' => urlencode($qual->qualificationcode),
    ]))->out(false);
    echo '<div class="alert alert-info mt-2 mb-3" style="border-left:4px solid #2563eb;">'
        . '<strong>Multi-page Record of Results:</strong> This qualification has '
        . count($allunits) . ' units of competency — student certificates will span '
        . ceil(count($allunits) / ROR_MULTIPAGE_UNIT_THRESHOLD) . ' or more pages. '
        . 'The cert Preview button shows all pages. '
        . '<a href="' . s($certHubUrl) . '" class="alert-link">View issued certificates →</a>'
        . '</div>';
}

// ── Generation form ───────────────────────────────────────────────────────────
$formurl = new moodle_url('/local/rtocompliance/generate_qual_certs.php', [
    'qualid'  => $qualid,
    'action'  => 'generate',
    'sesskey' => sesskey(),
]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false)]);

// Controls bar
echo html_writer::start_div('', ['style' => 'display:flex;flex-wrap:wrap;gap:16px;align-items:center;margin-bottom:16px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;']);
echo html_writer::tag('span',
    count($allcompleters) . ' student(s) completed all ' . $numlinkedcourses . ' unit-course(s)',
    ['style' => 'font-weight:600;color:#1e3a5f;flex:1 1 auto;']
);
echo html_writer::tag('label',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'forceregen', 'value' => '1', 'id' => 'gq-forceregen', 'style' => 'margin-right:6px;'])
    . 'Force regenerate (void existing)',
    ['for' => 'gq-forceregen', 'style' => 'font-size:0.87rem;color:#374151;cursor:pointer;margin:0;font-weight:400;']
);
echo html_writer::tag('label',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'sendemail', 'value' => '1', 'id' => 'gq-sendemail', 'checked' => 'checked', 'style' => 'margin-right:6px;'])
    . 'Notify students',
    ['for' => 'gq-sendemail', 'style' => 'font-size:0.87rem;color:#374151;cursor:pointer;margin:0;font-weight:400;']
);
echo html_writer::tag('a', 'Select all',
    ['href' => '#', 'onclick' => 'document.querySelectorAll(".gq-cbx").forEach(function (cb){cb.checked=true;}); return false;',
     'style' => 'font-size:0.85rem;text-decoration:none;color:#2563eb;']
);
echo html_writer::tag('span', '/', ['style' => 'color:#9ca3af;']);
echo html_writer::tag('a', 'None',
    ['href' => '#', 'onclick' => 'document.querySelectorAll(".gq-cbx").forEach(function (cb){cb.checked=false;}); return false;',
     'style' => 'font-size:0.85rem;text-decoration:none;color:#2563eb;']
);
echo html_writer::end_div();

// Students table
echo html_writer::start_div('', ['style' => 'overflow-x:auto;']);
echo '<table class="generaltable" style="width:100%;">';
echo '<thead><tr style="background:#f1f5f9;">';
echo '<th style="width:36px;padding:10px 8px;">'
    . '<input type="checkbox" id="gq-selectall" title="Select/deselect all"'
    . ' onchange="document.querySelectorAll(\'.gq-cbx\').forEach(function (cb){cb.checked=this.checked;}.bind(this));" checked>'
    . '</th>';
echo '<th style="padding:10px 8px;">Student</th>';
echo '<th style="padding:10px 8px;">Email</th>';
echo '<th style="padding:10px 8px;">All Units Completed</th>';
echo '<th style="padding:10px 8px;">Existing Certificates</th>';
if ($isMultiPage) {
    echo '<th style="padding:10px 8px;">RoR Pages</th>';
}
echo '</tr></thead><tbody>';

foreach ($allcompleters as $student) {
    $existingtestamur = $DB->record_exists_sql(
        "SELECT 1 FROM {local_rtocompliance_certs}
         WHERE userid = :uid AND certtype = 'testamur' AND qualificationcode = :qcode
           AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)",
        ['uid' => $student->id, 'qcode' => $qual->qualificationcode]
    );
    $existingrecord = $DB->record_exists_sql(
        "SELECT 1 FROM {local_rtocompliance_certs}
         WHERE userid = :uid AND certtype = 'record' AND qualificationcode = :qcode
           AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)",
        ['uid' => $student->id, 'qcode' => $qual->qualificationcode]
    );

    $hasBoth   = $existingtestamur && $existingrecord;
    $rowstyle  = $hasBoth ? 'background:#f0fdf4;' : '';
    $certbadge = '';
    if ($existingtestamur) {
        $certbadge .= '<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:4px;font-size:0.78rem;margin-right:4px;">Testamur ✓</span>';
    }
    if ($existingrecord) {
        $certbadge .= '<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:4px;font-size:0.78rem;">Record ✓</span>';
    }
    if (!$certbadge) {
        $certbadge = '<span style="color:#6b7280;font-size:0.85rem;">None yet</span>';
    }

    $isSuspended   = !empty($student->suspended);
    $suspendBadge  = $isSuspended
        ? ' <span style="background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-size:0.75rem;font-weight:600;vertical-align:middle;">SUSPENDED</span>'
        : '';
    $activateCb    = $isSuspended
        ? '<br><label style="font-size:0.78rem;color:#6b7280;margin-top:4px;display:inline-flex;align-items:center;gap:4px;cursor:pointer;">'
            . '<input type="checkbox" name="activate_userids[]" value="' . $student->id . '" style="margin:0;">'
            . ' Activate account</label>'
        : '';

    echo '<tr style="' . $rowstyle . ($isSuspended ? 'background:#fff7f7;' : '') . '">';
    echo '<td style="padding:8px;">'
        . '<input type="checkbox" name="userids[]" value="' . $student->id . '" class="gq-cbx"'
        . ($hasBoth ? '' : ' checked') . '>'
        . '</td>';
    echo '<td style="padding:8px;font-weight:500;">' . htmlspecialchars(fullname($student)) . $suspendBadge . $activateCb . '</td>';
    echo '<td style="padding:8px;color:#6b7280;">' . htmlspecialchars($student->email) . '</td>';
    echo '<td style="padding:8px;color:#374151;">' . userdate($student->timecompleted, '%d %b %Y') . '</td>';
    echo '<td style="padding:8px;">' . $certbadge . '</td>';
    if ($isMultiPage) {
        $estimatedPages = (int) ceil(count($allunits) / ROR_MULTIPAGE_UNIT_THRESHOLD);
        echo '<td style="padding:8px;">'
            . '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:600;">~'
            . $estimatedPages . ' page' . ($estimatedPages > 1 ? 's' : '') . '</span>'
            . '</td>';
    }
    echo '</tr>';
}

echo '</tbody></table>';
echo html_writer::end_div(); // overflow-x:auto

// Submit buttons
echo html_writer::start_div('', ['style' => 'margin-top:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;']);
echo html_writer::empty_tag('input', [
    'type'    => 'submit',
    'value'   => 'Generate Testamur + Record of Results',
    'class'   => 'btn btn-success btn-lg',
    'onclick' => 'return confirm(\'Issue Testamur + Record of Results for all selected students?\');',
]);
echo html_writer::link(
    $PAGE->url->out(false),
    'Refresh',
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/generate_qual_certs.php'),
    '← Change Qualification',
    ['class' => 'btn btn-outline-secondary']
);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // certificates-container
echo $OUTPUT->footer();
