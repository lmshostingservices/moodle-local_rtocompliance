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
 * RTO Compliance plugin — generate_qual_certs.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/cert_template_renderer.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

admin_externalpage_setup('local_rtocompliance_generate_qual_certs');
require_login();
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
    echo local_rtocompliance_page_banner(get_string('generate_qual_certs', 'local_rtocompliance'));

    // The same qualification code is legitimately held more than once — one row per
    // semester intake, distinguished by streamname (e.g. "26 DCB S1" / "26 DCB S2").
    // Select streamname and the linked unit count so the picker can tell them apart;
    // code + name alone renders identical options.
    $quals = $DB->get_records_sql(
        "SELECT q.id, q.qualificationcode, q.qualificationname, q.streamname,
                q.totalunits,
                (SELECT COUNT(1)
                   FROM {local_rtocompliance_qualunits} qu
                  WHERE qu.qualbuilderid = q.id
                    AND qu.selected = 1
                    AND qu.status = 'active') AS linkedunits
         FROM {local_rtocompliance_qualbuilder} q
         WHERE q.producttype = 'qualification'
           AND q.status = 'active'
         ORDER BY q.qualificationcode ASC, q.streamname ASC, q.qualificationname ASC"
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
                ['class' => 'btn btn-primary mt-2', 'title' => 'Build qualifications and link their unit-of-competency courses']
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
            $label = $q->qualificationcode . ' ' . format_string($q->qualificationname);
            // Intake/stream is what actually separates two rows sharing a code.
            $stream = trim((string) ($q->streamname ?? ''));
            if ($stream !== '') {
                $label .= ' — ' . format_string($stream);
            }
            // Unit count makes an unpopulated or short-linked intake obvious before selection.
            $units = (int) ($q->linkedunits ?? 0);
            $total = (int) ($q->totalunits ?? 0);
            $label .= ' (' . $units . ' unit' . ($units === 1 ? '' : 's');
            if ($total > 0 && $total !== $units) {
                $label .= ' of ' . $total;
            }
            $label .= ')';
            if ($stream === '') {
                $label .= ' #' . (int) $q->id;
            }
            $options[$q->id] = $label;
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
    echo '<a href="' . s($courseurl) . '" class="btn btn-outline-primary" title="Issue certificates for all students who completed one specific Moodle course">Generate by Unit &rarr;</a>';
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
// NOTE: SOURCE 1's HAVING check uses $effectiveLinkedUnitCount (primary + variant + cat-tree
// unit count) so that pure category-tree QBs (where $numlinkedcourses = 0) are not silently
// skipped.  SOURCE 2 (enrolment-based) is always the authoritative completers fallback.
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

// COURSE-MAP-TABLE (v5.9.335): replaced the runtime category-tree regex walk with a
// single JOIN against local_rtocompliance_course_map (the admin-managed source of truth).
// $catTreePairs still accumulates (qualunitid, courseid) tuples for SOURCE 1; the data
// now comes from the table rather than regex-parsing category/course names on every page
// load.
//
// Fallback: if the map table doesn't exist (pre-upgrade) OR has no rows for this qual
// (not yet seeded), the legacy regex walk fires so nothing breaks during transition.
$catTreePairs    = [];   // [['quid' => int, 'cid' => int], …]
$gcq_mapExists   = $dbman_gcq->table_exists('local_rtocompliance_course_map');
$gcq_qualcode    = strtoupper(trim((string)$qual->qualificationcode));

// SYNC-BEFORE-CERT (v5.9.337): Run a targeted reseed for this specific qualification
// before the map lookup so that any semester-copy courses added since the last nightly
// sync are captured before this certificate is generated. Passing $gcq_qualcode scopes
// the BFS walk to one qual's category tree — typically < 100ms even on large installs.
// Existing confirmed/manual entries are never overwritten (INSERT-or-skip semantics).
if ($gcq_mapExists && !empty($gcq_qualcode)) {
    local_rtocompliance_seed_course_map($gcq_qualcode);
}

$gcq_mapHasRows  = $gcq_mapExists && !empty($gcq_qualcode)
    && $DB->record_exists('local_rtocompliance_course_map', ['qualcode' => $gcq_qualcode]);

if ($gcq_mapHasRows) {
    // New path: one JOIN query, sub-millisecond on indexed table.
    $mapRows = $DB->get_records_sql(
        "SELECT cm.courseid, qu.id AS qualunitid
           FROM {local_rtocompliance_course_map} cm
           JOIN {local_rtocompliance_qualunits} qu
             ON qu.qualbuilderid = :qbid
            AND qu.unitcode      = cm.unitcode
            AND qu.selected      = 1
            AND qu.status        = 'active'
          WHERE cm.qualcode = :qcode",
        ['qbid' => $qualid, 'qcode' => $gcq_qualcode]
    );
    foreach ($mapRows as $mr) {
        $cid = (int)$mr->courseid;
        if (!in_array($cid, $alllinkedcourseids)) {
            $alllinkedcourseids[] = $cid;
        }
        $catTreePairs[] = ['quid' => (int)$mr->qualunitid, 'cid' => $cid];
    }
} elseif (!empty($gcq_qualcode)) {
    // Legacy fallback: runtime regex walk (used when map not yet seeded).
    $gcq_rootcatid  = local_rtocompliance_get_qual_root_category_id($gcq_qualcode);
    $gcq_subtreeids = $gcq_rootcatid > 0
        ? local_rtocompliance_get_category_subtree_ids($gcq_rootcatid)
        : [];
    if ($gcq_subtreeids) {
        $gcq_units = $DB->get_records_sql(
            "SELECT id AS quid, unitcode FROM {local_rtocompliance_qualunits}
              WHERE qualbuilderid = :qbid AND selected = 1 AND status = 'active'
                AND unitcode IS NOT NULL AND unitcode != ''",
            ['qbid' => $qualid]
        );
        foreach ($gcq_units as $gu) {
            $catcourses = local_rtocompliance_get_category_tree_courseids_for_unit(
                $gcq_rootcatid, $gu->unitcode, $gcq_subtreeids
            );
            foreach ($catcourses as $cid) {
                if (!in_array($cid, $alllinkedcourseids)) {
                    $alllinkedcourseids[] = (int)$cid;
                }
                $catTreePairs[] = ['quid' => (int)$gu->quid, 'cid' => (int)$cid];
            }
        }
    }
}

// SOURCE-1-EFFECTIVE-UNIT-COUNT: units with at least one linked course (primary,
// variant, OR course-map). $numlinkedcourses only counts QB primary links; for
// map-only QBs it would be 0, silently skipping SOURCE 1.
if ($dbman_gcq->table_exists('local_rtocompliance_qualunit_courses')) {
    $effectiveLinkedUnitQids = $DB->get_fieldset_sql(
        "SELECT DISTINCT qu.id
           FROM {local_rtocompliance_qualunits} qu
           LEFT JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu.id
          WHERE qu.qualbuilderid = :qbid AND qu.selected = 1 AND qu.status = 'active'
            AND (qu.courseid IS NOT NULL OR quc.courseid IS NOT NULL)",
        ['qbid' => $qualid]
    );
} else {
    $effectiveLinkedUnitQids = $DB->get_fieldset_sql(
        "SELECT DISTINCT id FROM {local_rtocompliance_qualunits}
          WHERE qualbuilderid = :qbid AND selected = 1 AND status = 'active'
            AND courseid IS NOT NULL",
        ['qbid' => $qualid]
    );
}
if (!empty($catTreePairs)) {
    $catTreeQids             = array_values(array_unique(array_column($catTreePairs, 'quid')));
    $effectiveLinkedUnitQids = array_values(array_unique(
        array_merge($effectiveLinkedUnitQids, array_map('intval', $catTreeQids))
    ));
}
$effectiveLinkedUnitCount = count($effectiveLinkedUnitQids);

// All units for the certificate Record of Results.
$allunits = local_rtocompliance_get_qualbuilder_unit_list($qualid);

// ── POST: bulk generation ─────────────────────────────────────────────────────
if ($action === 'generate' && confirm_sesskey()) {
    // FIX-CERT-TIMEOUT (v5.2.33): Same fix as generate_course_certs.php — release session
    // lock and extend time limit. Bulk testamur + record generation (2 certs per student)
    // is the slowest cert operation; without these guards it reliably hits the 30s limit.
    $userids         = optional_param_array('userids',         [], PARAM_INT);
    $activate_userids = optional_param_array('activate_userids', [], PARAM_INT);
    $forceregen      = optional_param('forceregen', 0, PARAM_INT);
    $sendemail       = optional_param('sendemail',  1, PARAM_INT);

    // This check must run BEFORE write_close(), or its redirect message is queued into a
    // session that can no longer be written and the admin sees a bare page reload.
    if (empty($userids)) {
        redirect($PAGE->url, 'No students selected.', null, \core\output\notification::NOTIFY_WARNING);
    }

    \core\session\manager::write_close();
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_HUGE);

    // NO-CORE-WRITES (v5.9.411): removed the auto-unsuspend of Moodle {user}
    // accounts on certificate generation. Activating an account is an explicit
    // admin decision in Moodle user management, not a side-effect of issuing a
    // certificate — the plugin no longer writes to the core {user} table.
    unset($activate_userids);

    $issued              = 0;
    $skipped             = 0;
    $usiskipped          = 0; // USI-SKIP-REPORTING (v6.3.13)
    $failed              = 0;
    $voided              = 0;
    $messages            = [];
    $creditsExhausted    = false; // CERT-CREDITS-BREAK-FIX (v5.9.297)
    // ROR-PAGE-ALERT (v5.9.350): count students whose Record of Results
    // required continuation pages so we can surface a notice at the end.
    $multiPageRorCount   = 0;
    $multiPageRorNames   = [];

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
        // UNITS-BLEED-FIX (v6.3.13): $unitsForCert was never reset inside this loop, so a
        // student with no local_rtocompliance_students row inherited the PREVIOUS student's
        // per-unit outcomes on their Record of Results. qual_cert_hub.php and ajax.php both
        // initialise it per iteration; this page did not.
        $unitsForCert       = [];
        $issuedThisStudent  = 0;
        $heldThisStudent    = 0;
        $skippedThisStudent = 0;
        $failedThisStudent  = 0;

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

            if ($existingcert && !$forceregen) {
                $skipped++;
                $skippedThisStudent++;
                continue;
            }
            // VOID-AFTER-ISSUE (v6.3.13): the old certificate used to be superseded HERE,
            // before the issuance gate ran. When the gate then refused the replacement
            // (no verified USI, missing RTO details, no units) the student was left holding
            // nothing at all, and $voided reported the loss as a success. The void now
            // happens only after the replacement exists — see below.

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
                // VOID-AFTER-ISSUE (v6.3.13): the replacement exists, so it is now safe to
                // supersede the certificate it replaces.
                if ($forceregen && $existingcert) {
                    $DB->update_record('local_rtocompliance_certs', (object)[
                        'id'           => $existingcert->id,
                        'reissued_at'  => time(),
                        'notes'        => trim(($existingcert->notes ?? '')
                            . "\n[Superseded by force-regenerate — Generate Qualification Certificates]"),
                        'timemodified' => time(),
                    ]);
                    if (!empty($existingcert->verifytoken)) {
                        local_rtocompliance_update_registry_status($existingcert->verifytoken, 'superseded');
                    }
                    $voided++;
                }
                $issued++;
                $issuedThisStudent++;
                $messages[] = fullname($user) . ' — ' . $certtype . ' issued (' . $result['certnumber'] . ')';
                // ROR-PAGE-ALERT (v5.9.350): after the 'record' cert is inserted,
                // render it in-memory so cert_template_renderer::$last_ror_page_count
                // is populated from this student's actual unit list.  programmatic_issue_cert()
                // only writes a DB row — no PDF is produced until download time — so we
                // must trigger an explicit render here to count continuation pages reliably.
                // Memory and time limits are already raised by the block above (MEMORY_HUGE /
                // 300 s), so the extra render is safe.  The returned bytes are discarded.
                if ($certtype === 'record' && !empty($result['certid'])) {
                    try {
                        $rorCert = $DB->get_record('local_rtocompliance_certs', ['id' => $result['certid']]);
                        if ($rorCert) {
                            local_rtocompliance_render_certificate_pdf_string($rorCert, $user);
                            $rorPages = \local_rtocompliance\cert_template_renderer::$last_ror_page_count;
                            if ($rorPages > 1) {
                                $multiPageRorCount++;
                                $multiPageRorNames[] = fullname($user) . ' (' . $rorPages . ' pages)';
                            }
                        }
                    } catch (\Throwable $rorEx) {
                        // Non-fatal: page-count probe failed, skip the alert for this student.
                        debugging('ROR-PAGE-ALERT render probe failed for cert ' . $result['certid'] . ': ' . $rorEx->getMessage(), DEBUG_DEVELOPER);
                    }
                }
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
            } elseif (!empty($result['skipped'])
                || in_array(($result['error'] ?? ''), ['NO_USI', 'USI_UNVERIFIED', 'MISSING_RTO_SETTINGS', 'NO_UNITS'], true)) {
                // USI-SKIP-REPORTING (v6.3.13): a Clause-12 USI refusal is not a failure and it
                // costs nothing — the gate runs before credits are consumed. Previously this page
                // had no skipped branch at all (generate_course_certs.php and qual_cert_hub.php
                // both do), so the plain-English $result['reason'] was thrown away and the admin
                // saw only 'FAILED: NO_USI' buried in a truncated summary.
                $usiskipped++;
                $heldThisStudent++;
                $messages[] = fullname($user) . ' — NOT ISSUED (' . $certtype . '): '
                    . ($result['reason'] ?? 'refused by a pre-issue check. No credits were charged.');
            } else {
                $messages[] = fullname($user) . ' — FAILED: ' . ($result['error'] ?? 'unknown error');
                $failed++;
                $failedThisStudent++;
            }
        }

        // AUTOCERT-COMPLETE-FIX (v5.9.297): mark the autocert queue row 'complete' after
        // certs are issued for this student.  Previously the row stayed 'pending' forever
        // causing the admin queue to show historical completions as still outstanding.
        // We only do this when: a studentrec exists, credits were not exhausted mid-loop
        // (which would mean zero certs were issued for this student), and at least one
        // cert was successfully issued during this entire POST run so far.
        // AUTOCERT-FALSE-COMPLETE-FIX (v6.3.13): the condition below used to be
        // "$studentrec && !$creditsExhausted", which flipped the queue row to 'complete'
        // even when every certificate was refused by the USI gate. process_enrolment_task.php
        // then never re-queues a student whose row is 'complete', so the certificate stayed
        // missing forever — even after the USI was later verified — and certsissued was
        // inflated by 1 despite zero certificates existing. Mirrors qual_cert_hub.php.
        if ($studentrec && !$creditsExhausted
            && ($issuedThisStudent > 0 || $skippedThisStudent > 0
                || $heldThisStudent > 0 || $failedThisStudent > 0)) {
            $autocertrow = $DB->get_record('local_rtocompliance_autocerts', [
                'studentid'     => $studentrec->id,
                'qualbuilderid' => $qualid,
                'status'        => 'pending',
            ]);
            if ($autocertrow) {
                $autocertupdate = (object)[
                    'id'            => $autocertrow->id,
                    'timeprocessed' => time(),
                ];
                // ORDER MATTERS: a hold outranks "already had one". A student who holds
                // their Testamur but whose Record of Results was refused sets BOTH
                // $skippedThisStudent and $heldThisStudent — closing the row there would
                // strand the missing Record exactly as the original bug did.
                if ($heldThisStudent > 0 || $failedThisStudent > 0) {
                    // Refused before any credit was charged, or a hard error (DB insert,
                    // credit service). Either way this student is not finished, so leave the
                    // row re-runnable rather than closing it over a missing certificate.
                    // NB: nothing issues it automatically — an admin re-runs this page or uses
                    // Process Queue in the Qual Cert Hub.
                    $autocertupdate->status = 'pending';
                } elseif ($issuedThisStudent > 0) {
                    $autocertupdate->status      = 'complete';
                    $autocertupdate->certsissued = ($autocertrow->certsissued ?? 0) + $issuedThisStudent;
                } else {
                    // Already holds every certificate — the queue row is genuinely done.
                    // This is the v5.9.297 case and must keep closing, or the admin queue
                    // shows historical completions as outstanding forever. certsissued is
                    // left alone: nothing new was issued.
                    $autocertupdate->status = 'complete';
                }
                $DB->update_record('local_rtocompliance_autocerts', $autocertupdate);
            }
        }
    }

    $modeLabel = $forceregen ? 'Force-regenerated' : 'Issued';
    $usinote   = $usiskipped > 0
        ? " NOT ISSUED — refused by a pre-issue check: {$usiskipped} certificate(s) (no credits charged)."
        : '';
    $summary   = "{$modeLabel}: {$issued} certificate(s). Voided (superseded): {$voided}. Skipped (already held): {$skipped}.{$usinote} Failed: {$failed}.";
    if (!empty($messages)) {
        $summary .= ' ' . implode('; ', array_slice($messages, 0, 5));
        if (count($messages) > 5) {
            $summary .= ' … and ' . (count($messages) - 5) . ' more.';
        }
    }
    $notiftype = ($failed > 0 || $usiskipped > 0)
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS;

    // ROR-PAGE-ALERT (v5.9.350): when at least one student's Record of Results
    // required continuation pages, append a plain-English notice so admins can
    // audit why a cert is multi-page and tune the ror_table field height or font
    // size on the template.  The PDFs themselves are fully complete — this is
    // informational only.  Single-page batches are completely unchanged.
    if ($multiPageRorCount > 0) {
        $rorNames = implode(', ', array_slice($multiPageRorNames, 0, 5));
        if (count($multiPageRorNames) > 5) {
            $rorNames .= ' … and ' . (count($multiPageRorNames) - 5) . ' more';
        }
        $summary .= ' ⚠ ' . $multiPageRorCount . ' student' . ($multiPageRorCount === 1 ? '' : 's') .
            ' had Record of Results that required continuation pages: ' . $rorNames .
            '. Consider increasing the ror_table field height or reducing the font size on the cert template.';
        // Upgrade to WARNING so the notice stands out even when no certs failed.
        $notiftype = \core\output\notification::NOTIFY_WARNING;
    }

    // GEN-SUMMARY-STASH (v6.3.13): \core\session\manager::write_close() was called at the
    // top of this handler, so redirect()'s message — which Moodle queues into
    // $SESSION->notifications for the next request — could never be persisted and the admin
    // got a bare page reload with no result whatsoever. Stash it in the application cache
    // and render it on the following GET instead.
    local_rtocompliance_stash_gen_summary(
        'qualcerts_' . (int)($USER->id ?? 0) . '_' . $qualid,
        $summary,
        $notiftype
    );
    redirect($PAGE->url);
}

// ── GET: display UI ───────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    'Generate Certificates by Qualification',
    get_string('certificates', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/certificates.php'),
    'certificates'
);
echo local_rtocompliance_page_banner('Generate Certificates by Qualification');

// GEN-SUMMARY-STASH (v6.3.13): show the result of the generation run we just redirected from.
$gqstashed = local_rtocompliance_pop_gen_summary('qualcerts_' . (int)($USER->id ?? 0) . '_' . $qualid);
if ($gqstashed !== null) {
    echo $OUTPUT->notification($gqstashed['summary'], $gqstashed['type']);
}

echo html_writer::start_div('certificates-container');

// HUB-LINK (v5.9.339): crosslink to Qual Cert Hub for the unified view.
$hubDetailUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid]))->out(false);
echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
echo '<span style="font-size:0.87rem;color:#374151;">🎓 <strong>New:</strong> View completion stats, issue all pending, and browse the autocert queue in the <strong>Qualification Certificate Hub</strong>.</span>';
echo '<a href="' . s($hubDetailUrl) . '" class="btn btn-outline-success btn-sm" title="View completion stats, issue all pending, and browse the autocert queue">Open Hub for this Qual &rarr;</a>';
echo '</div>';

// ── Qualification summary banner ──────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px 20px;margin-bottom:20px;']);
echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:8px;margin-bottom:10px;']);
echo html_writer::tag('span', 'Qualification', ['style' => 'font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#2563eb;background:#dbeafe;padding:2px 8px;border-radius:4px;']);
echo html_writer::tag('span', 'Testamur + Record of Results Generation', ['style' => 'font-size:1rem;font-weight:700;color:#1e3a5f;']);
echo html_writer::end_div();

// Confirm the intake on the detail page too: with several active streams per code,
// the heading alone cannot tell the admin which one they picked.
$qualheading = html_writer::tag('strong', htmlspecialchars($qual->qualificationcode), ['style' => 'color:#1e40af;'])
    . ' — ' . format_string($qual->qualificationname);
if (trim((string) ($qual->streamname ?? '')) !== '') {
    $qualheading .= html_writer::tag('span', format_string($qual->streamname),
        ['style' => 'margin-left:10px;background:#eef2ff;color:#3730a3;padding:2px 8px;'
                  . 'border-radius:4px;font-size:0.85rem;font-weight:600;']);
}
echo html_writer::tag('p', $qualheading,
    ['style' => 'margin:0 0 8px;font-size:0.95rem;color:#374151;']
);

echo html_writer::start_div('', ['style' => 'display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:8px;']);
echo html_writer::tag('span', 'Certificate Types: Testamur + Record of Results',
    ['style' => 'background:#dbeafe;color:#1d4ed8;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::tag('span', $effectiveLinkedUnitCount . ' linked unit-course(s)',
    ['style' => 'background:#dcfce7;color:#16a34a;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::tag('span', count($allunits) . ' unit(s) of competency',
    ['style' => 'background:#fef9c3;color:#92400e;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
echo html_writer::end_div();

if ($effectiveLinkedUnitCount === 0) {
    echo html_writer::tag('p',
        'No Moodle courses are linked to any units in this qualification (via direct link, variant, or category tree). '
        . 'Open the Qualification Builder, select each unit, and link it to its Moodle course. '
        . 'Completion is also detected automatically from the qualification\'s Moodle category tree.',
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
if ($effectiveLinkedUnitCount === 0 && $numunits === 0) {
    echo html_writer::div(
        html_writer::tag('p', 'Cannot determine qualification completions: no Moodle courses are linked and no unit codes are set for this qualification\'s units.') .
        html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder.php'),
            'Open Qualification Builder →',
            ['class' => 'btn btn-primary mt-2', 'title' => 'Build qualifications and link their unit-of-competency courses']
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
//
// SOURCE1-VARIANT-FIX (v5.9.331): Changed from counting primary-course completions to
// counting per-UNIT completions. Previously used $linkedcourseids (primary courses only)
// with HAVING COUNT(DISTINCT cc.course) = numlinkedcourses — a student who completed all
// units via semester-variant courses (never primary) would never satisfy the HAVING.
//
// New approach: a derived subquery maps each courseid (primary OR variant) back to its
// qualunit id, so HAVING COUNT(DISTINCT unitcourses.quid) counts ONE unit per student
// regardless of which delivery course was used. Uses $alllinkedcourseids in the IN
// clause so variant courses are visible to the WHERE filter.
if ($effectiveLinkedUnitCount > 0) {
    // If no primary-linked courses exist but category-tree courses were found, $alllinkedcourseids
    // may only contain cat-tree courseids. get_in_or_equal handles a non-empty array fine.
    if (empty($alllinkedcourseids)) {
        $alllinkedcourseids = [0]; // safe sentinel — no real course has id=0
    }
    list($insql, $inparams) = $DB->get_in_or_equal($alllinkedcourseids, SQL_PARAMS_NAMED, 'cid');

    // Build the unit↔course mapping subquery. UNION in variants only when the table exists.
    $unitcoursesSubq = "SELECT qu.id AS quid, qu.courseid AS cid
                          FROM {local_rtocompliance_qualunits} qu
                         WHERE qu.qualbuilderid = :src1_qbid1
                           AND qu.selected = 1 AND qu.status = 'active'
                           AND qu.courseid IS NOT NULL";
    $src1extra = ['src1_qbid1' => $qualid];
    if ($dbman_gcq->table_exists('local_rtocompliance_qualunit_courses')) {
        $unitcoursesSubq .= "
             UNION
             SELECT qu.id AS quid, quc.courseid AS cid
               FROM {local_rtocompliance_qualunits} qu
               JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu.id
              WHERE qu.qualbuilderid = :src1_qbid2
                AND qu.selected = 1 AND qu.status = 'active'
                AND quc.courseid IS NOT NULL";
        $src1extra['src1_qbid2'] = $qualid;
    }
    // CATEGORY-TREE-DETECTION (v5.9.332): append category-tree (quid, cid) pairs as
    // additional UNION SELECT literals so SOURCE 1 counts semester-copy completions
    // per unit. Values are always cast to int — no SQL injection risk.
    foreach ($catTreePairs as $pairIdx => $pair) {
        $qk = 'ctq' . $pairIdx;
        $ck = 'ctc' . $pairIdx;
        $unitcoursesSubq       .= "\n             UNION SELECT :$qk AS quid, :$ck AS cid";
        $src1extra[$qk]         = $pair['quid'];
        $src1extra[$ck]         = $pair['cid'];
    }

    // FIX-SUSPENDED-CERTS (v5.2.72): Removed u.suspended = 0. u.suspended added to SELECT/GROUP BY.
    $allcompleters = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                u.suspended,
                MIN(cc.timecompleted) AS timecompleted
           FROM {user} u
           JOIN {course_completions} cc ON cc.userid = u.id
           JOIN ($unitcoursesSubq) unitcourses ON unitcourses.cid = cc.course
          WHERE cc.course {$insql}
            AND cc.timecompleted IS NOT NULL
            AND cc.timecompleted > 0
            AND u.deleted = 0
          GROUP BY u.id, u.firstname, u.lastname, u.email,
                   u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.suspended
         HAVING COUNT(DISTINCT unitcourses.quid) >= :numcourses
          ORDER BY u.lastname, u.firstname",
        array_merge($inparams, $src1extra, ['numcourses' => $effectiveLinkedUnitCount])
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
    $hint = $effectiveLinkedUnitCount > 0
        ? 'A student appears here once Moodle marks them complete in all linked unit-courses (' . $effectiveLinkedUnitCount . ' unit(s) required, including category-tree courses), OR once the plugin records a competent outcome (C/RPL/CT) for every unit.'
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

// ── USI PREFLIGHT (v6.3.13) ───────────────────────────────────────────────────
// This page issues a Testamur + Record of Results, both of which the issuance gate in
// local_rtocompliance_programmatic_issue_cert() refuses outright unless the student has a
// USI that is VERIFIED with the USI Registry. Until now the worklist showed no USI at all:
// every completer was pre-ticked, the admin confirmed a credit charge, and the refusal
// happened silently server-side. Resolve the status for the whole list in one query and
// make an ineligible student visibly, physically unselectable.
$gqUsiMap     = local_rtocompliance_usi_issue_status_map(array_keys($allcompleters));
// The other pre-credit refusal: missing RTO identity fields. It blocks EVERY student, so it
// has to hold the tick boxes too — a red banner over a page of enabled boxes would leave the
// silent "confirm a charge, get nothing" path wide open.
$gqMissingSettings = local_rtocompliance_missing_cert_settings();
$gqBlocked    = 0;
$gqEligible   = 0;
foreach ($allcompleters as $gqStudent) {
    if (empty($gqMissingSettings) && !empty($gqUsiMap[(int)$gqStudent->id]['canissue'])) {
        $gqEligible++;
    } else {
        $gqBlocked++;
    }
}
if (!empty($gqMissingSettings)) {
    echo '<div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;'
        . 'border-radius:8px;padding:14px 18px;margin-bottom:16px;">'
        . '<div style="font-weight:700;color:#991b1b;margin-bottom:6px;font-size:15px;">'
        . 'No certificate can be issued — required RTO details are missing</div>'
        . '<div style="font-size:14px;color:#7f1d1d;line-height:1.55;">'
        . 'These AQF-required fields are not configured: <strong>'
        . s(implode(', ', $gqMissingSettings)) . '</strong>. '
        . 'Every certificate will be refused until they are set. '
        . '<a href="' . s((new moodle_url('/local/rtocompliance/plugin_settings.php',
            ['section' => 'local_rtocompliance_settings']))->out(false))
        . '" style="font-weight:600;">Open RTO Settings &rarr;</a></div></div>';
}

echo local_rtocompliance_usi_blocked_callout(
    $gqBlocked,
    count($allcompleters),
    !empty($gqMissingSettings)
        ? 'Every student is currently held because the RTO details above are not set.'
        : null
);

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
    count($allcompleters) . ' student(s) completed all ' . $effectiveLinkedUnitCount . ' unit-course(s)'
    . ($gqBlocked > 0
        ? ' — ' . $gqEligible . ' can be issued, ' . $gqBlocked . ' held (no verified USI)'
        : ''),
    ['style' => 'font-weight:600;color:#1e3a5f;flex:1 1 auto;']
);
echo html_writer::tag('label',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'forceregen', 'value' => '1', 'id' => 'gq-forceregen', 'style' => 'margin-right:6px;'])
    . 'Force regenerate (void existing)',
    ['for' => 'gq-forceregen', 'style' => 'font-size:0.87rem;color:#374151;cursor:pointer;margin:0;font-weight:400;']
);
// SENDEMAIL-UNCHECKED-FIX (v6.3.16): an unchecked checkbox submits NOTHING, so
// optional_param('sendemail', 1) fell back to its default of 1 and the certificate
// was emailed even when the admin had deliberately unticked the box. The hidden
// field below always submits 0; when the box IS ticked its value is submitted after
// the hidden one and wins. Reported 20 Aug 2026 by an admin who unticked "Notify
// students" and found the register showing the students as emailed.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sendemail', 'value' => '0']);
echo html_writer::tag('label',
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'sendemail', 'value' => '1', 'id' => 'gq-sendemail', 'checked' => 'checked', 'style' => 'margin-right:6px;'])
    . 'Notify students',
    ['for' => 'gq-sendemail', 'style' => 'font-size:0.87rem;color:#374151;cursor:pointer;margin:0;font-weight:400;']
);
// USI-PREFLIGHT (v6.3.13): :not(:disabled) — never tick a student the gate would refuse.
echo html_writer::tag('a', 'Select all eligible',
    ['href' => '#', 'onclick' => 'document.querySelectorAll(".gq-cbx:not(:disabled)").forEach(function (cb){cb.checked=true;}); return false;',
     'style' => 'font-size:0.85rem;text-decoration:none;color:#2563eb;',
     'title' => 'Tick every student who has a verified USI']
);
echo html_writer::tag('span', '/', ['style' => 'color:#9ca3af;']);
echo html_writer::tag('a', 'None',
    ['href' => '#', 'onclick' => 'document.querySelectorAll(".gq-cbx:not(:disabled)").forEach(function (cb){cb.checked=false;}); return false;',
     'style' => 'font-size:0.85rem;text-decoration:none;color:#2563eb;']
);
echo html_writer::end_div();

// ── Explainer card (who is in this list) ──────────────────────────────────────
echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;"><div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">Eligible Students</div><div style="font-size:14.5px;color:#334155;line-height:1.55;">Every student listed below has completed all required units of this qualification and is ready to receive a <strong>Testamur</strong> and <strong>Record of Results</strong>. Tick the students you want, then use the Generate button at the bottom. Rows that already hold both certificates are shown with a green tick and are left unticked by default. '
    . 'The <strong>USI</strong> column shows whether each student can legally be issued: a student without a '
    . 'USI verified with the USI Registry cannot be ticked, because the certificate would be refused. '
    . 'Use the <strong>Add / verify USI</strong> link on the row to fix it.</div></div>';

// Students table
echo html_writer::start_div('', ['style' => 'overflow-x:auto;']);
echo '<table class="generaltable" style="width:100%;">';
echo '<thead><tr style="background:#f1f5f9;">';
echo '<th style="width:36px;padding:10px 8px;" title="Select students to generate certificates for">'
    . '<input type="checkbox" id="gq-selectall" title="Select/deselect all eligible students"'
    . ' onchange="document.querySelectorAll(\'.gq-cbx:not(:disabled)\').forEach(function (cb){cb.checked=this.checked;}.bind(this));"'
    . ($gqEligible > 0 ? ' checked' : ' disabled') . '>'
    . '</th>';
echo '<th style="padding:10px 8px;" title="Student name and account status">Student</th>';
echo '<th style="padding:10px 8px;" title="Student email address">Email</th>';
echo '<th style="padding:10px 8px;" title="Date the student finished all required units">All Units Completed</th>';
// USI-PREFLIGHT (v6.3.13)
echo '<th style="padding:10px 8px;" title="A USI verified with the USI Registry is required before a Testamur or Record of Results can be issued">USI</th>';
echo '<th style="padding:10px 8px;" title="Certificates already issued to this student for this qualification">Existing Certificates</th>';
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

    // USI-PREFLIGHT (v6.3.13)
    $usiStatus  = $gqUsiMap[(int)$student->id] ?? ['status' => 'norecord', 'canissue' => false, 'usi' => '', 'reason' => ''];
    $canIssue   = empty($gqMissingSettings) && !empty($usiStatus['canissue']);
    $usiCell    = local_rtocompliance_usi_status_badge($usiStatus);
    if (!$canIssue) {
        $usiCell .= '<div style="font-size:0.75rem;color:#92400e;margin-top:4px;max-width:320px;line-height:1.4;">'
            . s($usiStatus['reason'] ?? '') . '</div>'
            . '<div style="margin-top:4px;">' . local_rtocompliance_usi_fix_link((int)$student->id) . '</div>';
    }

    $isSuspended   = !empty($student->suspended);
    $suspendBadge  = $isSuspended
        ? ' <span style="background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-size:0.75rem;font-weight:600;vertical-align:middle;">SUSPENDED</span>'
        : '';
    // NO-CORE-WRITES (v5.9.411): activate-account checkbox removed; the plugin no
    // longer unsuspends Moodle accounts. Certs still issue for suspended students.
    $activateCb    = $isSuspended
        ? '<br><span style="font-size:0.75rem;color:#9ca3af;margin-top:4px;display:inline-block;">Account suspended — activate in Moodle user admin if required.</span>'
        : '';

    // USI-PREFLIGHT (v6.3.13): a held row is tinted amber and its tick box is DISABLED, so
    // the admin cannot submit a student the issuance gate would refuse. The server-side gate
    // remains the real enforcement — this is the warning, not the lock.
    // Suspended keeps its own (stronger) tint; the USI hold is still conveyed by the
    // disabled tick box, the badge and the reason text in the USI column.
    $rowtint = $isSuspended ? 'background:#fff7f7;' : '';
    if (!$canIssue && !$isSuspended) {
        $rowtint = 'background:#fffbeb;';
    }
    echo '<tr style="' . $rowstyle . $rowtint . ($canIssue ? '' : 'opacity:0.85;') . '">';
    echo '<td style="padding:8px;">'
        . '<input type="checkbox" name="userids[]" value="' . $student->id . '" class="gq-cbx"'
        . ($canIssue ? ($hasBoth ? '' : ' checked') : ' disabled')
        . ' title="' . ($canIssue
            ? 'Select this student'
            : 'Cannot be issued — ' . s($usiStatus['reason'] ?? 'no verified USI')) . '">'
        . '</td>';
    echo '<td style="padding:8px;font-weight:500;">' . htmlspecialchars(fullname($student)) . $suspendBadge . $activateCb . '</td>';
    echo '<td style="padding:8px;color:#6b7280;">' . htmlspecialchars($student->email) . '</td>';
    echo '<td style="padding:8px;color:#374151;">' . userdate($student->timecompleted, '%d %b %Y') . '</td>';
    echo '<td style="padding:8px;">' . $usiCell . '</td>';
    echo '<td style="padding:8px;">' . $certbadge . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo html_writer::end_div(); // overflow-x:auto

// Submit buttons
echo html_writer::start_div('', ['style' => 'margin-top:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;']);
// CERT-COST-CONFIRM: state the credit/dollar cost before generating. Each student issued gets a
// Testamur + Record of Results (2 certificates), and every certificate is charged 5 credits
// (~A$0.50) through the central issuer — including nothing that generates for free.
echo html_writer::empty_tag('input', [
    'type'    => 'submit',
    'value'   => 'Generate Testamur + Record of Results',
    'class'   => 'btn btn-success btn-lg',
    'title'   => 'Issue a Testamur and Record of Results for each ticked student',
    'onclick' => 'var n=document.querySelectorAll(".gq-cbx:not(:disabled):checked").length;'
        . 'if(!n){alert("Select at least one student with a verified USI first.\\n\\nStudents without a verified USI cannot be ticked — a Testamur or Record of Results cannot be issued without one.");return false;}'
        . 'var certs=n*2, cr=certs*5;'
        . 'return confirm("Generate certificates for "+n+" student(s)?\\n\\nEach student receives a Testamur + Record of Results (2 certificates), and every certificate costs 5 credits (about A$0.50).\\n\\nThis will charge "+cr+" credits (about A$"+(cr*0.10).toFixed(2)+") in total.\\n\\nContinue?");',
]);
echo '<span style="font-size:12.5px;color:#64748b;">Each certificate costs 5 credits (&#8776; A$0.50). Testamur + Record of Results = 2 per student.</span>';
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
