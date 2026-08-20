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
 * RTO Compliance plugin — generate_course_certs.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.8.106 ARCHIVE-COURSE-PICKER — Course picker now groups courses by Moodle category path
// (shows archive courses organised under their Qualification > Archive label). A live search
// input lets admins type to narrow down hundreds of courses instantly. The filter bar on the
// student page gains a "Switch Course" selector showing sibling courses from the same parent
// category so admins can jump between archive versions without returning to the picker.
//
// v4.8.105 REGEN-BY-STUDENT-GROUP — Adds "Regenerate by Student / Group" tab to the course
// bulk certificate page. Admins can now:
//   (1) Filter the student list to a specific Moodle course group.
//   (2) Filter the student list to a single student.
//   (3) Switch between "Issue Missing" mode (skip already-issued) and
//       "Force Regenerate" mode (void existing cert and re-issue).
//
// Force-regenerate voids the existing cert record (sets reissued_at = now, keeps
// status = 'issued' for audit continuity) and creates a new cert with
// replacement_of pointing at the old record — matching the existing reissue
// convention used by issue_certificate.php. Credits are still deducted per new cert.
//
// Previous behaviour (v4.7.104 BULK-COURSE-CERTS):
//   - Course NOT nationally recognised                         → Completion Certificate
//   - Course IS nationally recognised + qualbuilder skillset/singleunit → Statement of Attainment
//   - Course IS nationally recognised + qualification + student completed ALL linked unit-courses → Testamur + Record of Results
//   - Course IS nationally recognised + qualification + student completed SOME unit-courses only  → Statement of Attainment

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

// USI-NAMESPACE-FIX (v5.9.246): class lives in local_rtocompliance\usi namespace;
// require_once alone does not import it into the global namespace.
use local_rtocompliance\usi\usi_platform_client;

admin_externalpage_setup('local_rtocompliance_generate_course_certs');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$courseid   = optional_param('courseid',   0,  PARAM_INT);
$action     = optional_param('action',     '', PARAM_ALPHA);
$sendemail  = optional_param('sendemail',  1,  PARAM_INT);
$groupid    = optional_param('groupid',    0,  PARAM_INT);
$studentid  = optional_param('studentid',  0,  PARAM_INT);
$forceregen = optional_param('forceregen', 0,  PARAM_INT);

// ── No course selected — show picker ─────────────────────────────────────────
if (!$courseid) {
    $PAGE->set_url('/local/rtocompliance/generate_course_certs.php');
    $PAGE->set_title('Generate Certificates by Course');
    $PAGE->set_heading('Generate Certificates by Course');
    $PAGE->requires->css('/local/rtocompliance/styles.css');
    $PAGE->add_body_class('path-local-rtocompliance');

    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header(
        get_string('generate_course_certs', 'local_rtocompliance'),
        get_string('certificates', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/certificates.php'),
        'certificates'
    );
    echo local_rtocompliance_page_banner(get_string('generate_course_certs', 'local_rtocompliance'));

    // ── Fetch courses with two-level category hierarchy (parent > child) ──────
    // This lets archive courses appear grouped under their qualification category.
    $courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.category,
                cat.name AS catname, cat.parent AS catparent,
                COALESCE(parent.name, '') AS parentcatname
           FROM {course} c
           JOIN {course_categories} cat    ON cat.id    = c.category
      LEFT JOIN {course_categories} parent ON parent.id = cat.parent
          WHERE c.id <> :siteid
          ORDER BY parentcatname ASC, cat.name ASC, c.fullname ASC",
        ['siteid' => SITEID]
    );

    // Build grouped structure: key = "Parent > Category" (or just "Category")
    $grouped = [];
    foreach ($courses as $c) {
        $parent  = trim((string)$c->parentcatname);
        $cat     = format_string($c->catname);
        $grpkey  = ($parent !== '' && $parent !== 'Miscellaneous')
                   ? ($parent . ' > ' . $cat)
                   : $cat;
        $grouped[$grpkey][] = $c;
    }

    echo html_writer::start_div('certificates-container');
    echo html_writer::tag('h2', 'Generate Certificates', ['style' => 'margin-bottom:6px;']);
    echo html_writer::tag('p', 'Choose how you want to generate certificates: by a single unit of competency, or for a full qualification.', ['style' => 'color:#6b7280;margin-bottom:24px;']);

    // Two-option card layout
    echo '<div style="display:flex;flex-wrap:wrap;gap:20px;align-items:stretch;margin-bottom:32px;">';

    // ── Option A: By Unit of Competency ───────────────────────────────────────
    echo '<div style="flex:1;min-width:280px;border:2px solid #3b82f6;border-radius:10px;padding:24px;background:#fff;">';
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
    echo '<span style="font-size:22px;">&#x1F4C4;</span>';
    echo '<strong style="font-size:1.1rem;color:#1e40af;">Generate by Unit of Competency</strong>';
    echo '</div>';
    echo '<p style="color:#374151;margin-bottom:16px;font-size:0.93rem;">Bulk-issue certificates for all students who completed a <strong>single Moodle course</strong> (unit of competency, including archive courses). The system automatically determines the correct certificate type: Completion Certificate, Statement of Attainment, or Testamur + Record of Results.</p>';

    // ── Live-search input ─────────────────────────────────────────────────────
    echo '<div style="margin-bottom:8px;">';
    echo '<input type="text" id="gc-course-search" placeholder="Type to search courses or categories..." '
       . 'autocomplete="off" '
       . 'style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;box-sizing:border-box;" '
       . 'oninput="gcFilterCourses(this.value)">';
    echo '</div>';

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/local/rtocompliance/generate_course_certs.php'))->out(false),
        'id'     => 'gc-picker-form',
    ]);

    // Build grouped <select> with <optgroup> per category path
    $selecthtml  = '<select name="courseid" id="gc-course-picker" class="custom-select" '
                 . 'style="flex:1;min-width:200px;width:100%;margin-bottom:8px;">';
    $selecthtml .= '<option value="0">— Select a course —</option>';
    $totalcourses = 0;
    foreach ($grouped as $grplabel => $grpcourses) {
        $selecthtml .= '<optgroup label="' . htmlspecialchars($grplabel, ENT_QUOTES) . '">';
        foreach ($grpcourses as $c) {
            $selecthtml .= '<option value="' . (int)$c->id . '">'
                         . format_string($c->fullname)
                         . '</option>';
            $totalcourses++;
        }
        $selecthtml .= '</optgroup>';
    }
    $selecthtml .= '</select>';
    echo $selecthtml;

    echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px;">';
    echo '<span id="gc-course-count" style="font-size:0.8rem;color:#6b7280;flex:1;">'
       . $totalcourses . ' courses across ' . count($grouped) . ' categories</span>';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Go', 'class' => 'btn btn-primary',
        'onclick' => 'if(!document.getElementById(\'gc-course-picker\').value||document.getElementById(\'gc-course-picker\').value==\'0\'){alert(\'Please select a course first.\');return false;}']);
    echo '</div>';
    echo html_writer::end_tag('form');

    // ── JavaScript: live filter for the course picker ─────────────────────────
    echo <<<'JS'
<script>
function gcFilterCourses(query) {
    var q = query.toLowerCase().trim();
    var sel = document.getElementById('gc-course-picker');
    var groups = sel.querySelectorAll('optgroup');
    var visible = 0;
    groups.forEach(function (og) {
        var ogLabel  = og.getAttribute('label').toLowerCase();
        var ogVisible = false;
        og.querySelectorAll('option').forEach(function (opt) {
            var text = opt.textContent.toLowerCase();
            var show = q === '' || text.indexOf(q) !== -1 || ogLabel.indexOf(q) !== -1;
            opt.style.display = show ? '' : 'none';
            if (show) { ogVisible = true; visible++; }
        });
        og.style.display = ogVisible ? '' : 'none';
    });
    var countEl = document.getElementById('gc-course-count');
    if (countEl) {
        countEl.textContent = q === '' ? (visible + ' courses') : (visible + ' matching courses');
    }
}
</script>
JS;

    echo '</div>';

    // ── Option B: By Full Qualification ──────────────────────────────────────
    $qualurl = (new moodle_url('/local/rtocompliance/generate_qual_certs.php'))->out(false);
    echo '<div style="flex:1;min-width:280px;border:2px solid #10b981;border-radius:10px;padding:24px;background:#fff;">';
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';
    echo '<span style="font-size:22px;">&#x1F393;</span>';
    echo '<strong style="font-size:1.1rem;color:#065f46;">Generate by Full Qualification</strong>';
    echo '</div>';
    echo '<p style="color:#374151;margin-bottom:16px;font-size:0.93rem;">Bulk-issue <strong>Testamur + Record of Results</strong> for all students who have completed <strong>every unit in a full qualification</strong> (e.g. Certificate III in Business). Uses the Qualification Builder to determine which units are required.</p>';
    echo '<a href="' . s($qualurl) . '" class="btn btn-success">Select a Qualification &rarr;</a>';
    echo '</div>';

    echo '</div>'; // end flex row

    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// ── Sibling courses in the same parent category (for archive course switcher) ─
// Find courses in categories that share the same parent as the current course's
// category. This surfaces other archive-period versions of the same unit so the
// admin can jump between them without returning to the picker.
$siblingcourses = [];
$coursecategoryrow = $DB->get_record('course_categories', ['id' => $course->category]);
if ($coursecategoryrow && (int)$coursecategoryrow->parent > 0) {
    $sibparentid = (int)$coursecategoryrow->parent;
    $siblingcourses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, cat.id AS catid, cat.name AS catname
           FROM {course} c
           JOIN {course_categories} cat ON cat.id = c.category
          WHERE cat.parent = :parentcatid
            AND c.id <> :currentid
            AND c.id <> :siteid
          ORDER BY cat.name ASC, c.fullname ASC",
        ['parentcatid' => $sibparentid, 'currentid' => $courseid, 'siteid' => SITEID]
    );
}

$PAGE->set_url('/local/rtocompliance/generate_course_certs.php', ['courseid' => $courseid]);
$PAGE->set_title('Generate Certificates by Course — ' . format_string($course->fullname));
$PAGE->set_heading('Generate Certificates by Course — ' . format_string($course->fullname));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ── POST: bulk generation (issue missing OR force-regenerate) ────────────────
if ($action === 'generate' && confirm_sesskey()) {
    // FIX-CERT-TIMEOUT (v5.2.33): Release session lock and extend time limit before bulk
    // cert generation. Without write_close() a concurrent request (e.g. the page reload
    // triggered by the redirect) blocks waiting for the session file lock and Moodle
    // returns a 500. Without raise() the 30s PHP default causes a fatal on large cohorts.
    $userids          = optional_param_array('userids',          [], PARAM_INT);
    $activate_userids = optional_param_array('activate_userids', [], PARAM_INT);
    $forceregen       = optional_param('forceregen', 0, PARAM_INT);
    $sendemail        = optional_param('sendemail',  1, PARAM_INT);

    // Must run BEFORE write_close(), or this message is queued into a session that can no
    // longer be written and the admin sees a bare page reload.
    if (empty($userids)) {
        redirect($PAGE->url, 'No students selected.', null, \core\output\notification::NOTIFY_WARNING);
    }

    \core\session\manager::write_close();
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_HUGE);

    // NO-CORE-WRITES (v5.9.411): the plugin no longer unsuspends Moodle {user}
    // accounts as a side-effect of certificate generation. Toggling core
    // {user}.suspended coupled account activation to cert issuance and wrote to a
    // core table; activating an account is an admin identity decision that must be
    // made explicitly in Moodle's user management, not implicitly by issuing a
    // certificate. Suspended students are still shown (badged) and can still be
    // issued certificates — the account is simply left untouched.
    unset($activate_userids);

    $issued           = 0;
    $skipped          = 0;
    $usiskipped       = 0; // v5.9.383: skipped because no USI recorded (Clause 12).
    $failed           = 0;
    $voided           = 0;
    $messages         = [];
    // COURSE-CERT-CREDITS-BREAK-FIX (v5.9.299): break 2 on INSUFFICIENT_CREDITS
    // silently killed both loops, leaving all remaining students uncounted.
    // Use a flag instead so the outer loop can continue tallying failures.
    $creditsExhausted = false;

    foreach ($userids as $userid) {
        $user = core_user::get_user($userid);
        if (!$user) {
            $failed++;
            continue;
        }

        $resolution = local_rtocompliance_resolve_cert_types_for_course($courseid, $userid);
        if (empty($resolution['certtypes'])) {
            $skipped++;
            continue;
        }

        // v5.9.368 COMPLETION-DATE-FIX: the POST handler runs BEFORE the display-path
        // $allcompleters map is built, so the old $allcompleters[$userid]->timecompleted
        // read was always undefined (silently 0). Compute the earliest completion date
        // for this user+course inline, from the same authoritative source
        // (course_completion_crit_compl, immune to grade re-saves) so cert.timecompleted
        // now actually stores it.
        $usercompletion = (int) ($DB->get_field_sql(
            "SELECT COALESCE(
                (SELECT MIN(ccc.timecompleted)
                   FROM {course_completion_crit_compl} ccc
                  WHERE ccc.userid = :u1 AND ccc.course = :c1
                    AND ccc.timecompleted IS NOT NULL AND ccc.timecompleted > 0),
                (SELECT MIN(cc.timecompleted)
                   FROM {course_completions} cc
                  WHERE cc.userid = :u2 AND cc.course = :c2
                    AND cc.timecompleted IS NOT NULL AND cc.timecompleted > 0)
            )",
            ['u1' => $userid, 'c1' => $courseid, 'u2' => $userid, 'c2' => $courseid]
        ) ?? 0);

        foreach ($resolution['certtypes'] as $certtype) {
            // SUPERSEDED-LOOKUP-FIX (v6.3.13): this was the only existing-cert lookup in the
            // plugin without the "(reissued_at IS NULL OR reissued_at = 0)" guard that
            // generate_qual_certs.php, qual_cert_hub.php and ajax.php all carry. Without it a
            // second force-regenerate run re-finds the ALREADY superseded certificate (and
            // emits a "found more than one record" debugging notice), stamps reissued_at over
            // it again, appends the supersede note a second time, and points the new cert's
            // replacement_of at the original instead of the one it actually replaces.
            $existingsql    = "userid = :userid AND certtype = :certtype AND status = 'issued'
                               AND (reissued_at IS NULL OR reissued_at = 0)";
            $existingparams = ['userid' => $userid, 'certtype' => $certtype];
            if (!empty($resolution['qualificationcode'])) {
                $existingsql .= " AND qualificationcode = :qualcode";
                $existingparams['qualcode'] = $resolution['qualificationcode'];
            }
            $existingcert = $DB->get_record_select(
                'local_rtocompliance_certs', $existingsql, $existingparams, '*', IGNORE_MULTIPLE);

            if ($existingcert && !$forceregen) {
                // Issue-missing mode: skip already-issued.
                $skipped++;
                continue;
            }
            // VOID-AFTER-ISSUE (v6.3.13): the old certificate used to be superseded here,
            // BEFORE the issuance gate ran — so a refused replacement left the student
            // holding nothing while $voided reported the loss as a success. Moved below,
            // to run only once the replacement actually exists.

            // INITIAL-COMPLETION-DATE-FIX (v5.9.232): pass the MIN'd timecompleted
            // from $allcompleters (sourced from course_completion_crit_compl)
            // so the cert record stores the original completion date, not the
            // date of the latest grade re-save.
            $result = local_rtocompliance_programmatic_issue_cert(
                $userid,
                $certtype,
                $resolution['qualificationcode'],
                $resolution['qualificationname'],
                $resolution['units'] ?? [],
                time(),
                'default',
                $sendemail,
                $usercompletion
            );

            if ($result['ok']) {
                // VOID-AFTER-ISSUE (v6.3.13): safe now that the replacement exists.
                if ($forceregen && $existingcert) {
                    $DB->update_record('local_rtocompliance_certs', (object)[
                        'id'           => $existingcert->id,
                        'reissued_at'  => time(),
                        'notes'        => trim(($existingcert->notes ?? '')
                            . "\n[Superseded by force-regenerate — Generate Course Certs]"),
                        'timemodified' => time(),
                    ]);
                    // Update the old cert's registry entry so scanning its QR shows
                    // "Superseded" instead of "Valid".
                    if (!empty($existingcert->verifytoken)) {
                        local_rtocompliance_update_registry_status($existingcert->verifytoken, 'superseded');
                    }
                    $voided++;
                }
                $issued++;
                $messages[] = fullname($user) . ' — ' . $certtype . ' issued (' . $result['certnumber'] . ')';

                // If this was a force-regen, link the new cert back to the old one.
                if ($forceregen && $existingcert && !empty($result['certid'])) {
                    $DB->update_record('local_rtocompliance_certs', (object)[
                        'id'             => $result['certid'],
                        'replacement_of' => $existingcert->id,
                        'notes'          => 'Force-regenerated via Generate Course Certificates',
                        'timemodified'   => time(),
                    ]);
                }
            } elseif ($result['error'] === 'INSUFFICIENT_CREDITS') {
                $messages[] = fullname($user) . ' — SKIPPED: insufficient credits';
                $failed++;
                $creditsExhausted = true;
                break; // exit inner certtype loop; outer loop will also exit via flag below
            } elseif (!empty($result['skipped']) || ($result['error'] ?? '') === 'NO_USI') {
                // v5.9.383: a Clause-12 USI skip is not a failure — count it separately.
                $usiskipped++;
                $messages[] = fullname($user) . ' — SKIPPED: '
                    . ($result['reason'] ?? 'no USI recorded (Clause 12 requires a USI before issuing)');
            } else {
                $messages[] = fullname($user) . ' — FAILED: ' . $result['error'];
                $failed++;
            }
        }
        if ($creditsExhausted) {
            // Count all remaining students as failed so summary is accurate.
            $remaining = array_slice($userids, array_search($userid, $userids) + 1);
            $failed   += count($remaining);
            if (!empty($remaining)) {
                $messages[] = count($remaining) . ' additional student(s) skipped — credits exhausted.';
            }
            break;
        }
    }

    $modeLabel = $forceregen ? 'Force-regenerated' : 'Issued';
    $usinote   = $usiskipped > 0 ? " Skipped (no USI): {$usiskipped}." : '';
    $summary   = "{$modeLabel}: {$issued} certificate(s). Voided (superseded): {$voided}. Skipped: {$skipped}.{$usinote} Failed: {$failed}.";
    $notiftype = ($failed > 0 || $usiskipped > 0)
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS;

    // Redirect back preserving group/student filter.
    $redirectparams = ['courseid' => $courseid];
    $postGroupid   = optional_param('groupid',   0, PARAM_INT);
    $postStudentid = optional_param('studentid', 0, PARAM_INT);
    if ($postGroupid)   { $redirectparams['groupid']   = $postGroupid; }
    if ($postStudentid) { $redirectparams['studentid'] = $postStudentid; }
    // GEN-SUMMARY-STASH (v6.3.13): \core\session\manager::write_close() was called at the
    // top of this handler, so redirect()'s message could never be written to the session and
    // the admin saw a bare page reload with no result. Stash it and render it on the GET.
    local_rtocompliance_stash_gen_summary(
        'coursecerts_' . (int)($USER->id ?? 0) . '_' . $courseid,
        $summary,
        $notiftype
    );
    redirect(new moodle_url('/local/rtocompliance/generate_course_certs.php', $redirectparams));
}

// ── GET: display generation UI ────────────────────────────────────────────────

// CERT-COST-POPUP: Fetch live credit balance for the total-cost preview modal.
// Balance values: -2 = not configured, -1 = unlimited, >= 0 = actual balance.
$_gcp_credit_client  = new usi_platform_client();
$_gcp_credit_balance = $_gcp_credit_client->get_credit_balance();
$_gcp_balance_val    = !$_gcp_credit_balance['configured'] ? -2
    : ($_gcp_credit_balance['unlimited'] ? -1 : (int)($_gcp_credit_balance['balance'] ?? 0));

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('generate_course_certs', 'local_rtocompliance'),
    get_string('certificates', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/certificates.php'),
    'certificates'
);
echo local_rtocompliance_page_banner(get_string('generate_course_certs', 'local_rtocompliance'));

// GEN-SUMMARY-STASH (v6.3.13): show the result of the generation run we just redirected from.
// Must live in the real display section, NOT the no-courseid picker branch above — the POST
// handler always stashes under the real courseid, so a key built from courseid 0 never matches.
$gccStashed = local_rtocompliance_pop_gen_summary('coursecerts_' . (int)($USER->id ?? 0) . '_' . $courseid);
if ($gccStashed !== null) {
    echo $OUTPUT->notification($gccStashed['summary'], $gccStashed['type']);
}

// ── Course summary card ───────────────────────────────────────────────────────
$dummyresolution = local_rtocompliance_resolve_cert_types_for_course($courseid, 0);
// Derive nationally-recognised flag from resolution result — covers both DB flag and unit-code auto-detection.
$nationally      = ($dummyresolution['reason'] !== 'non_accredited');

$certtypelabels  = local_rtocompliance_get_certificate_types();
$predictedtypes  = array_map(function ($t) use ($certtypelabels) {
    return $certtypelabels[$t] ?? $t;
}, $dummyresolution['certtypes']);

echo html_writer::start_div('certificates-container');

// Smart Detection info banner — light background, dark readable text
echo html_writer::start_div('', ['style' => 'background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px 20px;margin-bottom:20px;']);

echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:8px;margin-bottom:10px;']);
echo html_writer::tag('span', 'Smart Detection', ['style' => 'font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#2563eb;background:#dbeafe;padding:2px 8px;border-radius:4px;']);
echo html_writer::tag('span', 'Certificate Type Analysis', ['style' => 'font-size:1rem;font-weight:700;color:#1e3a5f;']);
echo html_writer::end_div();

echo html_writer::tag('p', html_writer::tag('strong', format_string($course->fullname), ['style' => 'color:#1e40af;']), ['style' => 'margin:0 0 10px;font-size:0.95rem;color:#374151;']);

echo html_writer::start_div('', ['style' => 'display:flex;flex-wrap:wrap;gap:12px;align-items:center;']);

// Nationally Recognised pill
$nrColour = $nationally ? '#16a34a' : '#6b7280';
$nrBg     = $nationally ? '#dcfce7' : '#f3f4f6';
echo html_writer::tag('span',
    html_writer::tag('strong', 'Nationally Recognised: ') . ($nationally ? 'Yes' : 'No'),
    ['style' => 'background:' . $nrBg . ';color:' . $nrColour . ';padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']
);

// Cert types badge
foreach ($predictedtypes as $ptlabel) {
    echo html_writer::tag('span', htmlspecialchars($ptlabel), ['style' => 'background:#dbeafe;color:#1d4ed8;padding:4px 10px;border-radius:6px;font-size:0.85rem;font-weight:600;']);
}

echo html_writer::end_div();

// Detection reason + qualification on next line
echo html_writer::start_div('', ['style' => 'margin-top:10px;font-size:0.85rem;color:#374151;display:flex;flex-wrap:wrap;gap:16px;']);
echo html_writer::tag('span',
    html_writer::tag('strong', 'Reason: ', ['style' => 'color:#374151;']) .
    html_writer::tag('span', local_rtocompliance_cert_reason_label($dummyresolution['reason'] ?? ''), ['style' => 'color:#374151;'])
);
if (!empty($dummyresolution['qualificationcode'])) {
    // FIX-QUAL-LABEL (v4.9.141): choose the right label based on what was detected.
    // Unit-only courses must not say "Qualification:" — that would confuse RTO staff.
    $qualreason = $dummyresolution['reason'] ?? '';
    if ($qualreason === 'unit_code_detected') {
        $quallabel = 'Unit: ';
    } elseif ($qualreason === 'skillset_or_singleunit') {
        $quallabel = 'Skill Set / Unit: ';
    } else {
        $quallabel = 'Qualification: ';
    }

    // FIX-QUAL-DUPLICATE (v4.9.141): qualificationname often already starts with the
    // code (e.g. "MEM16006A - Organise and communicate information") — don't prefix
    // the code a second time or it reads "MEM16006A — MEM16006A - Organise...".
    $qcode = $dummyresolution['qualificationcode'];
    $qname = $dummyresolution['qualificationname'];
    if (strpos($qname, $qcode) !== false) {
        // Name already contains the code — just show the name.
        $qualvalue = $qname;
    } else {
        // Name doesn't include the code — show "CODE — Name" format.
        $qualvalue = $qcode . ' — ' . $qname;
    }

    echo html_writer::tag('span',
        html_writer::tag('strong', $quallabel, ['style' => 'color:#374151;']) .
        html_writer::tag('span', htmlspecialchars($qualvalue), ['style' => 'color:#374151;'])
    );
}
echo html_writer::end_div();

echo html_writer::end_div();

// ── Get ALL course completers (unfiltered — used for student picker + counts) ─
// FIX-SUSPENDED-CERTS (v5.2.72): Removed u.suspended = 0 — suspended students who
// completed a course are still completers; their account status doesn't change that fact.
// u.suspended is included in SELECT so the table can badge suspended accounts.
// INITIAL-COMPLETION-DATE-FIX (v5.9.232): use the EARLIEST criterion completion
// timestamp from {course_completion_crit_compl} instead of the raw
// {course_completions}.timecompleted, which Moodle updates every time grades are
// re-saved.  The correlated COALESCE subquery falls back to cc.timecompleted
// for sites that use manual completion (no crit_compl rows exist).
$allcompleters = $DB->get_records_sql(
    "SELECT cc.userid,
            COALESCE(
                (SELECT MIN(ccc.timecompleted)
                   FROM {course_completion_crit_compl} ccc
                  WHERE ccc.userid = cc.userid
                    AND ccc.course = cc.course
                    AND ccc.timecompleted IS NOT NULL
                    AND ccc.timecompleted > 0),
                cc.timecompleted
            ) AS timecompleted,
            u.firstname, u.lastname, u.email,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
            u.suspended
     FROM {course_completions} cc
     JOIN {user} u ON u.id = cc.userid
     WHERE cc.course = :courseid
       AND cc.timecompleted IS NOT NULL
       AND cc.timecompleted > 0
       AND u.deleted = 0
     ORDER BY u.lastname, u.firstname",
    ['courseid' => $courseid]
);

// NAT-FIX-5.2.4 (NAT-COMPLETION): Fallback — if Moodle {course_completions} is empty,
// check the live RTO enrolments table for students who achieved competency against
// this course's unit code (course.idnumber).  This covers students whose completion
// was recorded via NAT import (after the doenrol run creates course_completions) but
// also guards legacy sites where Moodle course completion tracking is not configured.
if (empty($allcompleters) && !empty($course->idnumber)) {
    // FIX-FALLBACK-OUTCOMES (v5.2.29): Outcome set now matches the canonical competent
    // set used by doenrol NAT-COMPLETION: '41' (VETiS satisfactorily completed) and
    // '85' (non-assessable satisfactorily completed) added; '52' (RPL Not Granted)
    // removed — it was mistakenly in the old list but was excluded from the competent
    // set in v5.2.15 because a denied RPL application is not a completion.
    list($insql, $inparams) = $DB->get_in_or_equal(
        ['20','41','51','53','60','61','81','85'],
        SQL_PARAMS_NAMED,
        'oc'
    );
    $inparams['uc'] = $course->idnumber;
    // FIX-SUSPENDED-CERTS (v5.2.72): Removed u.suspended = 0. u.suspended added to SELECT.
    $allcompleters  = $DB->get_records_sql(
        "SELECT s.userid,
                e.activityenddate AS timecompleted,
                u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                u.suspended
           FROM {local_rtocompliance_enrolments} e
           JOIN {local_rtocompliance_students} s ON s.id = e.studentid
           JOIN {user} u ON u.id = s.userid
          WHERE e.unitcode = :uc
            AND e.outcomeidentifier $insql
            AND u.deleted = 0
          ORDER BY u.lastname, u.firstname",
        $inparams
    );
}

if (empty($allcompleters)) {
    echo html_writer::div(
        html_writer::tag('p', 'No students have completed this course yet.') .
        html_writer::tag('p', 'Students appear here once Moodle marks them as course-complete, '
            . 'or after a NAT file is imported with a competent outcome (code 20) for the '
            . 'matching unit of competency and the auto-enrol step is run.'),
        'no-deadlines'
    );
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ── Moodle course groups for this course ──────────────────────────────────────
$coursegroups = groups_get_all_groups($courseid);

// ── Apply group filter ────────────────────────────────────────────────────────
$filteredcompleters = $allcompleters;

if ($groupid > 0 && !empty($coursegroups[$groupid])) {
    $groupmembers = groups_get_members($groupid, 'u.id');
    $groupmemberids = array_keys($groupmembers);
    $filteredcompleters = array_filter($allcompleters, function ($c) use ($groupmemberids) {
        return in_array($c->userid, $groupmemberids);
    });
}

// ── Apply individual student filter ──────────────────────────────────────────
if ($studentid > 0) {
    $filteredcompleters = array_filter($allcompleters, function ($c) use ($studentid) {
        return (int)$c->userid === $studentid;
    });
}

$completers = $filteredcompleters;

// ── Build "has cert" lookup for filtered students ─────────────────────────────
$hascert = [];
if (!empty($completers)) {
    $alluserids = array_keys($completers);
    list($insql, $inparams) = $DB->get_in_or_equal($alluserids, SQL_PARAMS_NAMED, 'u');
    // FIX-HASCERT-SUPERSEDED (v5.0.5): exclude certs that have been superseded by a
    // force-regenerate (reissued_at IS NOT NULL). Those rows keep status='issued' for
    // audit trail continuity but a fresh cert exists for the same student+type, so
    // showing the old cert number as "Already Issued" is misleading. Only pick the
    // current (non-superseded) cert per student+type.
    $existingcerts = $DB->get_records_sql(
        "SELECT id, userid, certtype, qualificationcode, certnumber
         FROM {local_rtocompliance_certs}
         WHERE userid {$insql}
           AND status = 'issued'
           AND (reissued_at IS NULL OR reissued_at = 0)
         ORDER BY id ASC",
        $inparams
    );
    foreach ($existingcerts as $ec) {
        if (!isset($hascert[$ec->userid])) {
            $hascert[$ec->userid] = [];
        }
        $hascert[$ec->userid][$ec->certtype] = $ec->certnumber;
    }
}

// ── Counts for stat cards ─────────────────────────────────────────────────────
$needscert   = 0;
$alreadydone = 0;
foreach ($completers as $comp) {
    $res     = local_rtocompliance_resolve_cert_types_for_course($courseid, $comp->userid);
    $missing = false;
    foreach ($res['certtypes'] as $ct) {
        if (empty($hascert[$comp->userid][$ct])) {
            $missing = true;
        }
    }
    if ($missing) { $needscert++; } else { $alreadydone++; }
}

// ── Stat summary ──────────────────────────────────────────────────────────────
$filterLabel = '';
if ($groupid > 0 && !empty($coursegroups[$groupid])) {
    $filterLabel = ' — Group: ' . html_writer::tag('strong', format_string($coursegroups[$groupid]->name));
} elseif ($studentid > 0 && !empty($allcompleters[$studentid])) {
    $c = $allcompleters[$studentid];
    $filterLabel = ' — Student: ' . html_writer::tag('strong', fullname($c));
}

if ($filterLabel) {
    echo html_writer::tag('p', 'Showing ' . count($completers) . ' student(s)' . $filterLabel, ['class' => 'text-muted mb-3']);
}

echo html_writer::start_div('rtoc-stat-cards mb-4');
echo html_writer::start_div('rtoc-stat-card');
echo html_writer::tag('div', count($completers), ['class' => 'rtoc-stat-number']);
echo html_writer::tag('div', $filterLabel ? 'Filtered Students' : 'Course Completers', ['class' => 'rtoc-stat-label']);
echo html_writer::end_div();
echo html_writer::start_div('rtoc-stat-card');
echo html_writer::tag('div', $needscert, ['class' => 'rtoc-stat-number text-warning']);
echo html_writer::tag('div', 'Need Certificate', ['class' => 'rtoc-stat-label']);
echo html_writer::end_div();
echo html_writer::start_div('rtoc-stat-card');
echo html_writer::tag('div', $alreadydone, ['class' => 'rtoc-stat-number text-success']);
echo html_writer::tag('div', 'Already Issued', ['class' => 'rtoc-stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

// ── Archive course switcher — jump to sibling archive course ──────────────────
// Appears above the filter bar when the current course lives inside a category
// that has a parent category (e.g. Qual > Archive S2 2010 > TLJA5061). Shows all
// other courses in sibling categories so the admin can switch archive periods.
if (!empty($siblingcourses)) {
    $pickerbaseurl = (new moodle_url('/local/rtocompliance/generate_course_certs.php'))->out(false);
    echo html_writer::start_div('', [
        'style' => 'background:#fff8ed;border:1px solid #fcd34d;border-radius:6px;'
                 . 'padding:12px 16px;margin-bottom:12px;display:flex;flex-wrap:wrap;'
                 . 'gap:12px;align-items:center;',
    ]);
    echo html_writer::tag('span', 'Switch Archive Course:', [
        'style' => 'font-size:0.85rem;font-weight:600;color:#92400e;white-space:nowrap;',
    ]);

    // Build option list grouped by sibling category name
    $sibopthtml = '<option value="">— Current: ' . format_string($course->fullname) . ' —</option>';
    $lastcat = '';
    foreach ($siblingcourses as $sc) {
        $sccat = format_string($sc->catname);
        if ($sccat !== $lastcat) {
            if ($lastcat !== '') { $sibopthtml .= '</optgroup>'; }
            $sibopthtml .= '<optgroup label="' . htmlspecialchars($sccat, ENT_QUOTES) . '">';
            $lastcat = $sccat;
        }
        $sibopthtml .= '<option value="' . (int)$sc->id . '">'
                     . format_string($sc->fullname)
                     . '</option>';
    }
    if ($lastcat !== '') { $sibopthtml .= '</optgroup>'; }

    echo '<select id="gc-sibling-switch" class="form-control form-control-sm" '
       . 'style="min-width:260px;max-width:480px;" '
       . 'onchange="if(this.value){window.location.href=\'' . s($pickerbaseurl) . '?courseid=\'+this.value;}">'
       . $sibopthtml
       . '</select>';

    echo html_writer::tag('span',
        count($siblingcourses) . ' other course' . (count($siblingcourses) === 1 ? '' : 's') . ' in this qualification',
        ['style' => 'font-size:0.8rem;color:#92400e;']
    );
    echo html_writer::end_div();
}

// ── Filter bar ────────────────────────────────────────────────────────────────
$filterurl = new moodle_url('/local/rtocompliance/generate_course_certs.php', ['courseid' => $courseid]);
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $filterurl->out(false),
    'class'  => 'rtoc-filter-bar mb-4',
    'style'  => 'background:var(--rtoc-filter-bg,#f8f9fa);border:1px solid #dee2e6;border-radius:6px;padding:16px;',
]);
echo html_writer::tag('input', '', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

echo html_writer::start_div('d-flex flex-wrap gap-3 align-items-end');

// Group selector
echo html_writer::start_div('');
echo html_writer::tag('label', 'Filter by Group', [
    'for'   => 'filter-groupid',
    'style' => 'display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;',
]);
$groupopts  = html_writer::tag('option', '— All students —', array_filter(['value' => '0', 'selected' => $groupid === 0 ? 'selected' : null]));
foreach ($coursegroups as $cg) {
    $sel = ($groupid === (int)$cg->id) ? ['selected' => 'selected'] : [];
    $groupopts .= html_writer::tag('option', format_string($cg->name), array_merge(['value' => $cg->id], $sel));
}
echo html_writer::tag('select', $groupopts, [
    'name'  => 'groupid',
    'id'    => 'filter-groupid',
    'class' => 'form-control form-control-sm',
    'style' => 'min-width:180px;',
]);
echo html_writer::end_div();

// Individual student selector
echo html_writer::start_div('');
echo html_writer::tag('label', 'Filter by Student', [
    'for'   => 'filter-studentid',
    'style' => 'display:block;font-size:0.85rem;font-weight:600;margin-bottom:4px;',
]);
$studentopts  = html_writer::tag('option', '— All students —', array_filter(['value' => '0', 'selected' => $studentid === 0 ? 'selected' : null]));
foreach ($allcompleters as $ac) {
    $sel = ($studentid === (int)$ac->userid) ? ['selected' => 'selected'] : [];
    $studentopts .= html_writer::tag('option', fullname($ac) . ' (' . $ac->email . ')', array_merge(['value' => $ac->userid], $sel));
}
echo html_writer::tag('select', $studentopts, [
    'name'  => 'studentid',
    'id'    => 'filter-studentid',
    'class' => 'form-control form-control-sm',
    'style' => 'min-width:220px;',
]);
echo html_writer::end_div();

// Apply / Clear filter buttons
echo html_writer::start_div('d-flex gap-2');
echo html_writer::tag('button', 'Apply Filter', [
    'type'  => 'submit',
    'class' => 'btn btn-sm btn-secondary',
]);
if ($groupid || $studentid) {
    $clearurl = new moodle_url('/local/rtocompliance/generate_course_certs.php', ['courseid' => $courseid]);
    echo html_writer::link($clearurl, 'Clear Filter', ['class' => 'btn btn-sm btn-outline-secondary']);
}
echo html_writer::end_div();

echo html_writer::end_div(); // d-flex
echo html_writer::end_tag('form');

// ── Bulk form ─────────────────────────────────────────────────────────────────
$formurl = new moodle_url('/local/rtocompliance/generate_course_certs.php', [
    'courseid' => $courseid,
    'action'   => 'generate',
    'sesskey'  => sesskey(),
]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false)]);

// Preserve filter state in POST.
echo html_writer::tag('input', '', ['type' => 'hidden', 'name' => 'groupid',   'value' => $groupid]);
echo html_writer::tag('input', '', ['type' => 'hidden', 'name' => 'studentid', 'value' => $studentid]);

// ── Mode selector ─────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-clause-banner mb-4', ['style' => 'border-left:4px solid #6c757d;']);
echo html_writer::start_div('rtoc-clause-banner-title');
echo html_writer::tag('span', 'Generation Mode', ['class' => 'rtoc-clause-banner-label']);
echo html_writer::tag('h4', 'Choose how to handle existing certificates');
echo html_writer::end_div();
echo html_writer::start_div('rtoc-clause-banner-body');

echo html_writer::start_div('d-flex flex-wrap gap-4');

// Issue Missing option
echo html_writer::start_div('');
echo html_writer::start_tag('label', ['style' => 'cursor:pointer;display:flex;align-items:flex-start;gap:10px;']);
echo html_writer::tag('input', '', [
    'type'    => 'radio',
    'name'    => 'forceregen',
    'value'   => '0',
    'id'      => 'mode-issuemissing',
    'checked' => ($forceregen == 0) ? 'checked' : null,
    'style'   => 'margin-top:3px;',
]);
echo html_writer::start_div('');
echo html_writer::tag('strong', 'Issue Missing Certificates');
echo html_writer::tag('div', 'Only issue certificates to students who don\'t already have one. Students with existing certificates are skipped.', ['class' => 'text-muted', 'style' => 'font-size:0.85rem;']);
echo html_writer::end_div();
echo html_writer::end_tag('label');
echo html_writer::end_div();

// Force Regenerate option
echo html_writer::start_div('');
echo html_writer::start_tag('label', ['style' => 'cursor:pointer;display:flex;align-items:flex-start;gap:10px;']);
echo html_writer::tag('input', '', [
    'type'    => 'radio',
    'name'    => 'forceregen',
    'value'   => '1',
    'id'      => 'mode-forceregen',
    'checked' => ($forceregen == 1) ? 'checked' : null,
    'style'   => 'margin-top:3px;',
]);
echo html_writer::start_div('');
echo html_writer::tag('strong', 'Force Regenerate');
echo html_writer::tag('div', 'Void existing certificates and re-issue new ones (e.g. after updating a template). 5 credits per new certificate. Old certs are marked as superseded.', ['class' => 'text-muted', 'style' => 'font-size:0.85rem;']);
echo html_writer::end_div();
echo html_writer::end_tag('label');
echo html_writer::end_div();

echo html_writer::end_div(); // d-flex gap-4
echo html_writer::end_div(); // clause-banner-body
echo html_writer::end_div(); // clause-banner

// Email toggle
echo html_writer::start_div('d-flex align-items-center gap-2 mb-3');
echo html_writer::tag('label', 'Email certificates to students:', ['for' => 'sendemail', 'class' => 'mb-0 mr-2']);
echo html_writer::tag('input', '', [
    'type'    => 'checkbox',
    'name'    => 'sendemail',
    'id'      => 'sendemail',
    'value'   => '1',
    'checked' => 'checked',
    'class'   => 'mr-2',
]);
echo html_writer::end_div();

// ── USI PREFLIGHT (v6.3.13) ───────────────────────────────────────────────────
// A Statement of Attainment / Testamur / Record of Results cannot be issued without a USI
// verified with the USI Registry — the issuance gate refuses them before any credit is
// consumed. A Completion Certificate for a non-accredited course is NOT gated, which is why
// this page appeared to work while Generate by Qualification did not. Resolve the cert types
// and the USI status for every listed student up front, so an ineligible row is visibly
// unselectable instead of silently refused after the admin confirms a charge.
$gccMissingSettings = local_rtocompliance_missing_cert_settings();
$gccUsiMap    = local_rtocompliance_usi_issue_status_map(array_map(function ($c) {
    return (int) $c->userid;
}, array_values($completers)));
$gccTypes     = [];
$gccBlockedBy = [];
$gccReasons   = [];
$gccBlocked   = 0;
$gccEligible  = 0;
foreach ($completers as $gccComp) {
    $gccUid            = (int) $gccComp->userid;
    $gccRes            = local_rtocompliance_resolve_cert_types_for_course($courseid, $gccUid);
    $gccTypes[$gccUid] = $gccRes;
    $gccGated          = local_rtocompliance_usi_certtypes_are_gated($gccRes['certtypes']);
    // The gate has THREE pre-credit refusals, not one. Model all of them, or the page keeps
    // a path where the admin confirms a charge and gets a silent no-op:
    //   NO_USI / USI_UNVERIFIED   — no verified USI
    //   MISSING_RTO_SETTINGS      — RTO legal name / provider code / signatory unset
    //   NO_UNITS                  — a statement/record with no units AND no qualification code
    $gccEmptyDoc = !empty(array_intersect($gccRes['certtypes'], ['statement', 'record']))
        && empty($gccRes['units'])
        && trim((string) ($gccRes['qualificationcode'] ?? '')) === '';

    $gccReason = '';
    if ($gccGated && !empty($gccMissingSettings)) {
        $gccReason = 'Required RTO details are not configured: ' . implode(', ', $gccMissingSettings)
            . '. Set them in RTO Settings before issuing.';
    } else if ($gccGated && $gccEmptyDoc) {
        $gccReason = 'This course has no unit list and no qualification code, so the certificate would '
            . 'be an empty compliance document. Link the course to its unit in the Qualification Builder, '
            . 'or set the qualification code in the course settings.';
    } else if ($gccGated && empty($gccUsiMap[$gccUid]['canissue'])) {
        $gccReason = $gccUsiMap[$gccUid]['reason'] ?? 'No verified USI.';
    }

    $gccOk                 = ($gccReason === '');
    $gccReasons[$gccUid]   = $gccReason;
    $gccBlockedBy[$gccUid] = !$gccOk;
    if ($gccOk) {
        $gccEligible++;
    } else {
        $gccBlocked++;
    }
}

if (!empty($gccMissingSettings)) {
    echo '<div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;'
        . 'border-radius:8px;padding:14px 18px;margin-bottom:16px;">'
        . '<div style="font-weight:700;color:#991b1b;margin-bottom:6px;font-size:15px;">'
        . 'Nationally recognised certificates cannot be issued — required RTO details are missing</div>'
        . '<div style="font-size:14px;color:#7f1d1d;line-height:1.55;">'
        . 'These AQF-required fields are not configured: <strong>'
        . s(implode(', ', $gccMissingSettings)) . '</strong>. '
        . '<a href="' . s((new moodle_url('/local/rtocompliance/plugin_settings.php',
            ['section' => 'local_rtocompliance_settings']))->out(false))
        . '" style="font-weight:600;">Open RTO Settings &rarr;</a></div></div>';
}

echo local_rtocompliance_usi_blocked_callout(
    $gccBlocked,
    count($completers),
    'Students whose course only warrants a Completion Certificate are not affected — that is not AQF certification '
        . 'and needs no USI. The USI column gives the exact reason for each held row.'
);

// Select all / Generate buttons
echo html_writer::start_div('d-flex gap-2 mb-3 flex-wrap align-items-center');
if ($gccBlocked > 0) {
    echo html_writer::tag('span',
        $gccEligible . ' of ' . count($completers) . ' selectable',
        ['class' => 'text-muted mr-2', 'style' => 'font-size:0.87rem;font-weight:600;']);
}
echo html_writer::tag('button', 'Select All Needing Certs', [
    'type'    => 'button',
    'class'   => 'btn btn-sm btn-outline-secondary',
    'onclick' => 'document.querySelectorAll(".cert-checkbox.needs-cert:not(:disabled)").forEach(c => c.checked = true)',
]);
echo html_writer::tag('button', 'Select All Shown', [
    'type'    => 'button',
    'class'   => 'btn btn-sm btn-outline-secondary',
    'onclick' => 'document.querySelectorAll(".cert-checkbox:not(:disabled)").forEach(c => c.checked = true)',
]);
echo html_writer::tag('button', 'Deselect All', [
    'type'    => 'button',
    'class'   => 'btn btn-sm btn-outline-secondary',
    'onclick' => 'document.querySelectorAll(".cert-checkbox:not(:disabled)").forEach(c => c.checked = false)',
]);
echo html_writer::start_div('ml-auto');
echo html_writer::tag('button', 'Generate / Regenerate Selected', [
    'type'    => 'button',
    'class'   => 'btn btn-primary',
    'id'      => 'rtoc-gencert-btn',
    'onclick' => 'rtocBulkCertConfirm()',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// ── Credit cost confirmation modal (CERT-COST-POPUP) ─────────────────────────
$_gcp_balance_json = json_encode($_gcp_balance_val);
echo <<<HTML
<div class="modal fade" id="rtocCertCostModal" tabindex="-1" role="dialog" aria-labelledby="rtocCertCostModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius:10px;overflow:hidden;">
      <div class="modal-header" style="background:#1e40af;color:#fff;border-bottom:none;">
        <h5 class="modal-title" id="rtocCertCostModalLabel" style="font-weight:700;display:flex;align-items:center;gap:8px;">
          <span style="font-size:1.2em;">&#128179;</span> Confirm Certificate Generation
        </h5>
        <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" style="color:#fff;opacity:0.8;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" style="padding:24px;">
        <div id="rtocCertCostBody" style="font-size:0.95rem;"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid #e5e7eb;gap:8px;">
        <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Cancel — go back</button>
        <button type="button" class="btn btn-primary" id="rtocCertCostConfirmBtn" onclick="rtocBulkCertSubmit()">
          Confirm &amp; Generate
        </button>
      </div>
    </div>
  </div>
</div>
<script>
var RTOC_GCP_BALANCE = {$_gcp_balance_json};
var RTOC_GCP_COST_PER_CERT = 5;
var _rtocCertForm = null;

function rtocBulkCertConfirm() {
    var forceregen = document.querySelector('input[name=forceregen]:checked');
    var isRegen    = forceregen && forceregen.value === '1';

    // Count selected checkboxes.
    // Issue-missing mode: only need-cert boxes that are checked will actually incur a cost.
    // Force-regen mode:   every checked box will incur a cost.
    var allChecked     = document.querySelectorAll('.cert-checkbox:not(:disabled):checked');
    var needsCertChecked = document.querySelectorAll('.cert-checkbox.needs-cert:not(:disabled):checked');

    var billableCount = isRegen ? allChecked.length : needsCertChecked.length;
    var selectedCount = allChecked.length;
    var totalCost     = billableCount * RTOC_GCP_COST_PER_CERT;

    if (selectedCount === 0) {
        alert('Please select at least one student before generating.\\n\\nStudents without a verified USI cannot be ticked \u2014 a nationally recognised certificate cannot be issued without one.');
        return;
    }

    var balanceVal = RTOC_GCP_BALANCE; // -2=not configured, -1=unlimited, >=0=actual
    var html       = '';

    // ── Mode summary ──────────────────────────────────────────────────────────
    var modeColour = isRegen ? '#dc2626' : '#2563eb';
    var modeLabel  = isRegen
        ? '<strong style="color:' + modeColour + ';">Force Regenerate</strong> — void existing certificates and re-issue new ones'
        : '<strong style="color:' + modeColour + ';">Issue Missing</strong> — skip students who already have a certificate';
    html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;">';
    html += '<div style="font-size:0.82rem;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;margin-bottom:4px;">Mode</div>';
    html += '<div>' + modeLabel + '</div>';
    html += '</div>';

    // ── Student + cost summary ────────────────────────────────────────────────
    html += '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
    html += '<tr style="border-bottom:1px solid #e5e7eb;">';
    html += '<td style="padding:8px 4px;color:#6b7280;">Students selected</td>';
    html += '<td style="padding:8px 4px;text-align:right;font-weight:600;">' + selectedCount + '</td>';
    html += '</tr>';
    if (!isRegen && selectedCount !== billableCount) {
        var alreadyHave = selectedCount - billableCount;
        html += '<tr style="border-bottom:1px solid #e5e7eb;">';
        html += '<td style="padding:8px 4px;color:#6b7280;">Already have a certificate (will be skipped)</td>';
        html += '<td style="padding:8px 4px;text-align:right;color:#6b7280;">' + alreadyHave + '</td>';
        html += '</tr>';
    }
    html += '<tr style="border-bottom:1px solid #e5e7eb;">';
    html += '<td style="padding:8px 4px;color:#374151;">Certificates to generate</td>';
    html += '<td style="padding:8px 4px;text-align:right;font-weight:700;">' + billableCount + '</td>';
    html += '</tr>';
    html += '<tr style="border-bottom:1px solid #e5e7eb;">';
    html += '<td style="padding:8px 4px;color:#374151;">Credit cost per certificate</td>';
    html += '<td style="padding:8px 4px;text-align:right;">5 credits <span style="color:#6b7280;">(&#8776; A$0.50)</span></td>';
    html += '</tr>';
    html += '<tr style="background:#fef9ec;">';
    html += '<td style="padding:10px 4px;font-weight:700;font-size:1.05rem;">Total credit cost</td>';
    html += '<td style="padding:10px 4px;text-align:right;font-weight:800;font-size:1.1rem;color:#b45309;">' + totalCost.toLocaleString() + ' credits <span style="font-weight:600;font-size:0.85rem;color:#92400e;">(&#8776; A$' + (totalCost * 0.10).toFixed(2) + ')</span></td>';
    html += '</tr>';
    html += '</table>';

    // ── Balance ───────────────────────────────────────────────────────────────
    if (balanceVal === -2) {
        html += '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;font-size:0.88rem;color:#92400e;">';
        html += '<strong>&#9888; Platform not configured.</strong> Credits cannot be checked. ';
        html += 'Generation will proceed but may fail if credits are insufficient. ';
        html += 'Visit <em>Site Administration &rarr; RTO Compliance &rarr; Settings</em> to connect your account.';
        html += '</div>';
    } else if (balanceVal === -1) {
        html += '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;font-size:0.88rem;color:#166534;">';
        html += '<strong>&#10003; Unlimited credits.</strong> No charge will be deducted.';
        html += '</div>';
    } else {
        var afterBalance = balanceVal - totalCost;
        var balanceOk    = afterBalance >= 0;
        var balColour    = balanceOk ? '#166534'  : '#991b1b';
        var balBg        = balanceOk ? '#f0fdf4'  : '#fef2f2';
        var balBorder    = balanceOk ? '#86efac'  : '#fca5a5';
        html += '<div style="background:' + balBg + ';border:1px solid ' + balBorder + ';border-radius:6px;padding:10px 14px;margin-bottom:' + (balanceOk ? '0' : '10px') + ';">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;">';
        html += '<tr><td style="color:#4b5563;padding:3px 0;">Current balance</td>';
        html += '<td style="text-align:right;font-weight:600;color:#1f2937;">' + balanceVal.toLocaleString() + ' credits</td></tr>';
        html += '<tr><td style="color:#4b5563;padding:3px 0;">This operation</td>';
        html += '<td style="text-align:right;color:#dc2626;">&minus;' + totalCost.toLocaleString() + ' credits</td></tr>';
        html += '<tr style="border-top:1px solid ' + balBorder + ';">';
        html += '<td style="padding:4px 0;font-weight:700;color:' + balColour + ';">Balance after</td>';
        html += '<td style="text-align:right;font-weight:800;color:' + balColour + ';">' + afterBalance.toLocaleString() + ' credits</td></tr>';
        html += '</table>';
        html += '</div>';
        if (!balanceOk) {
            html += '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;font-size:0.88rem;color:#7f1d1d;">';
            html += '<strong>&#9888; Insufficient credits.</strong> You need <strong>' + totalCost.toLocaleString() + '</strong> credits ';
            html += 'but only have <strong>' + balanceVal.toLocaleString() + '</strong>. ';
            html += '<a href="https://lms-labs.com/pricing" target="_blank" style="color:#b91c1c;text-decoration:underline;">Purchase more credits &rarr;</a>';
            html += '</div>';
            document.getElementById('rtocCertCostConfirmBtn').disabled = true;
        } else {
            document.getElementById('rtocCertCostConfirmBtn').disabled = false;
        }
    }

    document.getElementById('rtocCertCostBody').innerHTML = html;
    rtocModalToggle('rtocCertCostModal', true);
}

// v5.9.368 BS5-MODAL-FIX: the jQuery \$('#..').modal() plugin API was removed in
// Bootstrap 5, which broke the entire bulk-generate confirm flow on BS5 themes.
// This helper drives the modal on Bootstrap 5, Bootstrap 4, or a plain fallback.
function rtocModalToggle(id, show) {
    var el = document.getElementById(id);
    if (!el) { return; }
    if (window.bootstrap && window.bootstrap.Modal) {
        var m = window.bootstrap.Modal.getOrCreateInstance(el);
        if (show) { m.show(); } else { m.hide(); }
        return;
    }
    if (window.jQuery && typeof window.jQuery(el).modal === 'function') {
        window.jQuery(el).modal(show ? 'show' : 'hide');
        return;
    }
    if (show) {
        el.classList.add('show'); el.style.display = 'block';
        el.removeAttribute('aria-hidden'); document.body.classList.add('modal-open');
    } else {
        el.classList.remove('show'); el.style.display = 'none';
        el.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open');
    }
}

function rtocBulkCertSubmit() {
    rtocModalToggle('rtocCertCostModal', false);
    document.getElementById('rtoc-gencert-btn').closest('form').submit();
}
</script>
HTML;
// ── /Credit cost confirmation modal ──────────────────────────────────────────

// ── Student table ─────────────────────────────────────────────────────────────
if (empty($completers)) {
    echo html_writer::div(
        html_writer::tag('p', 'No students match the current filter.') .
        html_writer::tag('p', 'Try a different group or student, or clear the filter to see all completers.'),
        'no-deadlines'
    );
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_div('rtoc-table-responsive');
echo html_writer::start_tag('table', ['class' => 'rtoc-table table table-hover']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', html_writer::tag('input', '', [
    'type'    => 'checkbox',
    'title'   => 'Select all',
    'onclick' => 'document.querySelectorAll(".cert-checkbox:not(:disabled)").forEach(c => c.checked = this.checked)',
]));
echo html_writer::tag('th', 'Student');
echo html_writer::tag('th', 'Completed');
// USI-PREFLIGHT (v6.3.13)
echo html_writer::tag('th', 'USI', [
    'title' => 'A USI verified with the USI Registry is required before a nationally recognised certificate can be issued',
]);
echo html_writer::tag('th', 'Cert Type');
echo html_writer::tag('th', 'Status');
echo html_writer::tag('th', 'Cert No.');
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($completers as $comp) {
    // USI-PREFLIGHT (v6.3.13): resolved once in the preflight pass above.
    $res    = $gccTypes[(int)$comp->userid] ?? local_rtocompliance_resolve_cert_types_for_course($courseid, $comp->userid);
    $types  = $res['certtypes'];
    $labels = array_map(function ($t) use ($certtypelabels) { return $certtypelabels[$t] ?? $t; }, $types);

    $allissued = true;
    $certnums  = [];
    foreach ($types as $ct) {
        if (!empty($hascert[$comp->userid][$ct])) {
            $certnums[] = $hascert[$comp->userid][$ct];
        } else {
            $allissued = false;
        }
    }

    $statusbadge = $allissued
        ? html_writer::tag('span', 'Issued', ['class' => 'badge badge-success'])
        : html_writer::tag('span', 'Needs Certificate', ['class' => 'badge badge-warning']);

    // In force-regen mode all rows are selectable; in issue-missing mode only unchecked by default
    // USI-PREFLIGHT (v6.3.13): hold students whose certificate types require a verified USI.
    $usiStatus  = $gccUsiMap[(int)$comp->userid] ?? ['status' => 'norecord', 'canissue' => false, 'usi' => '', 'reason' => ''];
    $usiGated   = local_rtocompliance_usi_certtypes_are_gated($types);
    $usiHeld    = !empty($gccBlockedBy[(int)$comp->userid]);

    $holdReason = $gccReasons[(int)$comp->userid] ?? '';
    $checkboxattrs = [
        'type'  => 'checkbox',
        'name'  => 'userids[]',
        'value' => $comp->userid,
        'class' => 'cert-checkbox' . ($allissued ? '' : ' needs-cert'),
        'title' => $usiHeld
            ? 'Cannot be issued — ' . ($holdReason !== '' ? $holdReason : 'refused by a pre-issue check')
            : 'Select this student',
    ];
    if ($usiHeld) {
        $checkboxattrs['disabled'] = 'disabled';
    } else if (!$allissued) {
        $checkboxattrs['checked'] = 'checked';
    }

    if (!$usiGated) {
        $usiCell = html_writer::tag('span', 'Not required', [
            'style' => 'background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px;'
                . 'font-size:0.78rem;font-weight:600;white-space:nowrap;',
            'title' => 'This course warrants a Completion Certificate, which is not AQF certification and needs no USI',
        ]);
    } else {
        $usiCell = local_rtocompliance_usi_status_badge($usiStatus);
        if ($usiHeld && $holdReason !== '') {
            $usiCell .= html_writer::tag('div', s($holdReason), [
                'style' => 'font-size:0.75rem;color:#92400e;margin-top:4px;max-width:300px;line-height:1.4;',
            ]);
            // Only offer the USI fix link when the USI is actually what is wrong — telling an
            // admin to fix a USI that is already verified is worse than saying nothing.
            if (empty($usiStatus['canissue'])) {
                $usiCell .= html_writer::tag('div', local_rtocompliance_usi_fix_link((int)$comp->userid),
                    ['style' => 'margin-top:4px;']);
            }
        }
    }

    $profileurl    = new moodle_url('/user/profile.php', ['id' => $comp->userid]);
    $namelink      = html_writer::link($profileurl, fullname($comp));
    // FIX-SUSPENDED-CERTS (v5.2.72): Badge suspended accounts (read-only).
    // NO-CORE-WRITES (v5.9.411): the per-row "Activate account" checkbox was removed
    // — the plugin no longer unsuspends Moodle accounts. Suspended students can still
    // be issued certificates; activate the account (if wanted) in Moodle user admin.
    $isSuspended   = !empty($comp->suspended);
    $suspendBadge  = $isSuspended
        ? ' <span style="background:#fee2e2;color:#b91c1c;padding:1px 6px;border-radius:4px;font-size:0.75rem;font-weight:600;vertical-align:middle;">SUSPENDED</span>'
        : '';
    $activateCb    = $isSuspended
        ? html_writer::tag('span', 'Account suspended — activate in Moodle user admin if required.',
            ['style' => 'font-size:0.75rem;color:#9ca3af;display:block;margin-top:3px;font-weight:400;'])
        : '';

    // Highlight rows with existing certs differently if we might regenerate them
    $rowclass = $isSuspended ? 'table-danger' : '';
    if ($usiHeld && !$isSuspended) {
        // Suspended keeps its own (stronger) tint; the USI hold is still conveyed by the
        // disabled tick box, the badge and the reason text in the USI column.
        $rowclass = 'table-warning';
    } else if (!$isSuspended) {
        if (!$allissued) {
            $rowclass = 'table-warning';
        } elseif ($alreadydone > 0) {
            $rowclass = 'table-light';
        }
    }

    echo html_writer::start_tag('tr', ['class' => $rowclass]);
    echo html_writer::tag('td', html_writer::tag('input', '', $checkboxattrs));
    echo html_writer::tag('td', $namelink . $suspendBadge . $activateCb . html_writer::tag('small', ' ' . $comp->email, ['class' => 'text-muted']));
    echo html_writer::tag('td', userdate($comp->timecompleted, '%d %b %Y'));
    echo html_writer::tag('td', $usiCell);
    echo html_writer::tag('td', implode(' + ', $labels));
    echo html_writer::tag('td', $statusbadge);
    echo html_writer::tag('td', $certnums ? implode(', ', $certnums) : '—');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();

echo $OUTPUT->footer();

// ── Helpers ───────────────────────────────────────────────────────────────────
function local_rtocompliance_cert_reason_label(string $reason): string {
    $labels = [
        'non_accredited'                             => 'Non-accredited / local course — Completion Certificate',
        'unit_code_detected'                         => 'Unit code detected in course name — nationally recognised, Statement of Attainment',
        'nationally_recognised_no_qualbuilder'       => 'Nationally recognised (no qualification code set) — Statement of Attainment',
        'nationally_recognised_course_settings_only' => 'Nationally recognised (qual code from course settings, no qualbuilder link) — Statement of Attainment',
        'skillset_or_singleunit'                     => 'Skill set or single unit — Statement of Attainment',
        'full_qualification'                         => 'Full qualification completed — Testamur + Record of Results',
        'partial_qualification'                      => 'Partial qualification completed — Statement of Attainment',
    ];
    return $labels[$reason] ?? $reason;
}
