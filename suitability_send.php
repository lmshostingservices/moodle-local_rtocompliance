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

// Send (or resend) a pre-enrolment suitability review to a student.
//
// AUDIT-REWRITE (v4.2.47, BUG-MAY1-AUDIT-PASS4): full restructure to satisfy
// Standard 2 PI 2(a) and PI 2(b) of the 2025 Standards.  The previous
// 16-question Yes/No self-declaration is replaced by a structured evidence-
// collection form (suitability_form.php) and a system-generated suitability
// decision (calculated in lib.php).  This send screen now captures three
// admin-side context fields per send:
//   - Required prerequisite qualification (default "none")
//   - Required ACSF LLN level (default 3)
//   - Student's previously assessed LLN level if known (optional)
// No "answers" rows are seeded — all evidence is captured on a single
// suitability record by the student.  Old pending records (created on
// v4.2.46 or earlier) keep their answer rows and still render via the
// backward-compat path in suitability_form.php.

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$userid   = required_param('userid', PARAM_INT);
$resendid = optional_param('resendid', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_students');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $userid]));
$PAGE->set_title(get_string('suitability_send_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('suitability_send_title', 'local_rtocompliance'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add(get_string('suitability_send_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// Hard-fail on guest/missing user.
$user = core_user::get_user($userid, '*', MUST_EXIST);
if (isguestuser($user) || empty($user->email) || strpos($user->email, '@') === false) {
    throw new moodle_exception('error', 'local_rtocompliance', '',
        'Cannot send suitability review to user id ' . $userid . ': account is the guest user, deleted, or has no valid email.');
}

// Qualification dropdown values shared with suitability_form.php.
$qualOptions = [
    'none'        => 'No prerequisite required',
    'school'      => 'Schooling only / no post-secondary qualification',
    'cert1'       => 'Certificate I',
    'cert2'       => 'Certificate II',
    'cert3'       => 'Certificate III',
    'cert4'       => 'Certificate IV',
    'diploma'     => 'Diploma',
    'advdiploma'  => 'Advanced Diploma',
    'bachelor'    => 'Bachelor degree or higher',
];

// LLN level dropdown — ACSF 1..5 plus "not yet assessed".
$llnOptions = [
    ''   => 'Not yet assessed',
    '1'  => 'ACSF Level 1',
    '2'  => 'ACSF Level 2',
    '3'  => 'ACSF Level 3',
    '4'  => 'ACSF Level 4',
    '5'  => 'ACSF Level 5',
];

// ─── Handle resend: clear legacy state, issue new token, re-email ────────────
// FIX-MAY2026-RESEND-LEGACY (v4.4.48): The old resend path kept legacy answer
// rows in local_rtocompliance_suitability_answers, which caused suitability_form.php
// to detect $isLegacy = true and render the old 16-question form even after resend.
// Fix: delete legacy rows + generate a fresh token (invalidates the old link) +
// reset all evidence/decision fields to null — mirroring what the "new send" path
// already does at lines ~119-120.
if ($resendid && confirm_sesskey()) {
    $record = $DB->get_record('local_rtocompliance_suitability', ['id' => $resendid, 'userid' => $userid], '*', MUST_EXIST);
    $tas    = $DB->get_record('local_rtocompliance_tas', ['id' => $record->tasid], '*', MUST_EXIST);

    // Drop any legacy answer rows so the new form is rendered (not old 16-question form).
    $DB->delete_records('local_rtocompliance_suitability_answers', ['suitabilityid' => $record->id]);

    // Issue a fresh token so the previous email link is invalidated.
    $newToken = bin2hex(random_bytes(32));

    // Reset all evidence/decision fields to pending state.
    $record->token                  = $newToken;
    $record->status                 = 'pending';
    $record->lln_evidence           = null;
    $record->digital_literacy       = null;
    $record->digital_literacy_evidence = null;
    $record->prior_skills           = null;
    $record->prior_skills_evidence  = null;
    $record->course_req_note        = null;
    $record->support_needs          = null;
    $record->disability_disclosure  = null;
    $record->declaration_name       = null;
    $record->declaration_signed_at  = null;
    $record->trainer_decision       = null;
    $record->trainer_justification  = null;
    $record->trainer_advice         = null;
    $record->trainer_declared_at    = null;
    $record->trainerid              = null;
    $record->advice                 = null;
    $record->reasons                = null;
    $record->support_required       = null;
    $record->overridenotes          = null;
    $record->overriddenby           = null;
    $record->overriddentime         = null;
    $record->override_outcome       = null;
    $record->timecompleted          = null;
    $record->timesent               = time();
    $record->timemodified           = time();
    $DB->update_record('local_rtocompliance_suitability', $record);

    local_rtocompliance_send_suitability_email($user, $tas, $newToken);

    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        get_string('suitability_email_resent', 'local_rtocompliance', fullname($user)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── Active LLN adapter (v4.2.50 — pluggable) ────────────────────────────────
$llnAdapter      = \local_rtocompliance\lln\lln_dispatcher::get_active_adapter();
$llnAdapterCode  = $llnAdapter->get_code();
$llnAdapterLabel = $llnAdapter->get_label();
$llnIsManual     = ($llnAdapterCode === 'manual');

// ─── Handle new send submission ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $tasid       = required_param('tasid', PARAM_INT);
    $reqPrereq   = optional_param('req_prereq', 'none', PARAM_ALPHANUMEXT);
    $reqLLNRaw   = optional_param('req_lln_level', '3', PARAM_RAW_TRIMMED); // pipeline-ignore: PARAM_RAW -- trimmed text field; sanitised before use
        // Manual override is implicit when adapter = manual; explicit checkbox otherwise.
    $manualOverride = (bool) optional_param('lln_manual_override', $llnIsManual ? 1 : 0, PARAM_BOOL);
    $actLLNRaw   = $manualOverride ? optional_param('lln_actual_level', '', PARAM_RAW_TRIMMED) : ''; // pipeline-ignore: PARAM_RAW -- text/JSON param; sanitised before use

    $reqLLN = in_array($reqLLNRaw, ['1', '2', '3', '4', '5'], true) ? $reqLLNRaw : '3';
    $actLLN = in_array($actLLNRaw, ['1', '2', '3', '4', '5'], true) ? $actLLNRaw : null;
    if (!isset($qualOptions[$reqPrereq])) {
        $reqPrereq = 'none';
    }

    $tas = $DB->get_record('local_rtocompliance_tas', ['id' => $tasid], '*', MUST_EXIST);

    $existing = $DB->get_record('local_rtocompliance_suitability', ['userid' => $userid, 'tasid' => $tasid]);
    if ($existing && in_array($existing->status, ['suitable', 'override_suitable', 'suitable_with_support', 'override_suitable_with_support'])) {
        redirect(
            new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $userid]),
            get_string('suitability_already_suitable', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    // FIX-RESEND-SUBMITTED (v4.4.52): Block silent overwrite when the student has already
    // submitted their checklist. Sending again would generate a new token, invalidating
    // the student's submission record and showing them "Link Not Found" on their old link.
    // Redirect the admin to the submitted record so they can review it instead.
    if ($existing && $existing->status === 'submitted') {
        redirect(
            new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $existing->id]),
            'This student has already submitted their Eligibility Check — it is awaiting your review below. '
            . 'Sending a new checklist now would discard their submission. Use the Resend option only if you want to start fresh.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $token = bin2hex(random_bytes(32));

    if ($existing) {
        // Drop legacy answers (if any) and reset record to pending with the new context fields.
        $DB->delete_records('local_rtocompliance_suitability_answers', ['suitabilityid' => $existing->id]);
        $existing->token              = $token;
        $existing->status             = 'pending';
        $existing->req_prereq         = $reqPrereq;
        $existing->req_lln_level      = $reqLLN;
        $existing->lln_actual_level   = $actLLN;
        $existing->qualification      = null;
        $existing->experience         = 0;
        $existing->experience_years   = null;
        $existing->industry_type      = null;
        $existing->school_level       = null;
        $existing->digital_skills     = null;
        $existing->disability_disclosure = null;
        $existing->reasons            = null;
        $existing->support_required   = null;
        $existing->advice             = null;
        $existing->overridenotes      = null;
        $existing->overriddenby       = null;
        $existing->overriddentime     = null;
        $existing->override_outcome   = null;
        $existing->timesent           = time();
        $existing->timecompleted      = null;
        $existing->timemodified       = time();
        $DB->update_record('local_rtocompliance_suitability', $existing);
        $suitabilityid = $existing->id;
    } else {
        $newrec = (object)[
            'tasid'              => $tasid,
            'userid'             => $userid,
            'token'              => $token,
            'status'             => 'pending',
            'req_prereq'         => $reqPrereq,
            'req_lln_level'      => $reqLLN,
            'lln_actual_level'   => $actLLN,
            'experience'         => 0,
            'timesent'           => time(),
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $suitabilityid = $DB->insert_record('local_rtocompliance_suitability', $newrec);
    }

    local_rtocompliance_send_suitability_email($user, $tas, $token);
    local_rtocompliance_log_action('suitability_sent', 'suitability', $suitabilityid, [
        'userid'      => $userid,
        'tasid'       => $tasid,
        'req_prereq'  => $reqPrereq,
        'req_lln'     => $reqLLN,
        'actual_lln'  => $actLLN,
    ]);

    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        get_string('suitability_email_sent', 'local_rtocompliance', fullname($user)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── GET: Show the send form ──────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('students', 'local_rtocompliance'), null, null, 'students');
echo $OUTPUT->heading(get_string('suitability_send_title', 'local_rtocompliance'), 2);

// Audit context note for the admin.
echo html_writer::start_div('alert alert-info', ['style' => 'max-width:780px']);
echo '<strong>Standard 2 PI 2(a) &amp; (b) — Pre-enrolment suitability review.</strong>';
echo '<p style="margin:6px 0 0">This form sends the student a structured evidence-collection link.  After the student submits, the system will compute a <em>Suitable / Suitable with Support / Not Suitable</em> outcome from their responses and the context you supply below, and will send the student plain-language advice on next steps.  Set the prerequisite qualification and required ACSF LLN level for this course before sending.</p>';
echo html_writer::end_div();

// Student info
echo html_writer::start_div('generalbox mb-3 p-3');
echo html_writer::tag('p', '<strong>' . get_string('student', 'local_rtocompliance') . ':</strong> ' . fullname($user) . ' &lt;' . s($user->email) . '&gt;');
echo html_writer::end_div();

// Existing suitability records for this student
$existing_records = $DB->get_records_sql(
    "SELECT su.*, t.qualificationcode, t.qualificationname
       FROM {local_rtocompliance_suitability} su
       JOIN {local_rtocompliance_tas} t ON t.id = su.tasid
      WHERE su.userid = :uid
      ORDER BY su.timecreated DESC",
    ['uid' => $userid]
);

if (!empty($existing_records)) {
    echo html_writer::tag('h5', get_string('suitability_existing', 'local_rtocompliance'), ['class' => 'mt-3']);
    $etable = new html_table();
    $etable->head = ['Qualification', 'Status', 'Sent', 'Completed', 'Actions'];
    $etable->attributes['class'] = 'generaltable table-sm';
    foreach ($existing_records as $er) {
        $statusmap = [
            'pending'                          => '<span class="badge badge-info">Awaiting Response</span>',
            'suitable'                         => '<span class="badge badge-success">Suitable</span>',
            'suitable_with_support'            => '<span class="badge badge-warning">Suitable with Support</span>',
            'not_suitable'                     => '<span class="badge badge-danger">Not Suitable</span>',
            'override_suitable'                => '<span class="badge badge-warning">Override: Suitable</span>',
            'override_suitable_with_support'   => '<span class="badge badge-warning">Override: Suitable with Support</span>',
            'override_not_suitable'            => '<span class="badge badge-secondary">Override: Not Suitable</span>',
        ];
        $stbadge = $statusmap[$er->status] ?? '<span class="badge badge-secondary">' . s($er->status) . '</span>';
        $sent = $er->timesent ? userdate($er->timesent, get_string('strftimedatetimeshort', 'langconfig')) : '-';
        $done = $er->timecompleted ? userdate($er->timecompleted, get_string('strftimedatetimeshort', 'langconfig')) : '-';
        $acts = html_writer::link(
            new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $er->id]),
            'View',
            ['class' => 'btn btn-sm btn-outline-secondary mr-1']
        );
        if ($er->status === 'pending') {
            $resendurl = new moodle_url('/local/rtocompliance/suitability_send.php', [
                'userid'   => $userid,
                'resendid' => $er->id,
                'sesskey'  => sesskey(),
            ]);
            $acts .= html_writer::link($resendurl, get_string('suitability_resend', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-primary']);
        }
        $etable->data[] = [
            s($er->qualificationcode) . ' ' . s($er->qualificationname),
            $stbadge,
            $sent,
            $done,
            $acts,
        ];
    }
    echo html_writer::table($etable);
}

$tas_records = $DB->get_records_sql(
    "SELECT id, qualificationcode, qualificationname
       FROM {local_rtocompliance_tas}
      WHERE status IN ('approved','review')
      ORDER BY qualificationcode",
    []
);

if (empty($tas_records)) {
    echo $OUTPUT->notification(get_string('suitability_no_tas', 'local_rtocompliance'), \core\output\notification::NOTIFY_WARNING);
} else {
    echo html_writer::tag('h5', get_string('suitability_send_new', 'local_rtocompliance'), ['class' => 'mt-4']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'class' => 'mt-2', 'style' => 'max-width:720px']);
    echo html_writer::input_hidden_params(new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $userid, 'sesskey' => sesskey()]));

    $tasoptions = [];
    foreach ($tas_records as $t) {
        $tasoptions[$t->id] = $t->qualificationcode . ': ' . $t->qualificationname;
    }

    echo html_writer::tag('label', get_string('suitability_select_tas', 'local_rtocompliance'),
        ['for' => 'tasid', 'class' => 'd-block mb-1 font-weight-bold']);
    echo html_writer::select($tasoptions, 'tasid', '', false,
        ['id' => 'tasid', 'class' => 'form-control mb-3']);

    echo html_writer::tag('label', 'Required prerequisite qualification',
        ['for' => 'req_prereq', 'class' => 'd-block mb-1 font-weight-bold']);
    echo html_writer::tag('p', 'The minimum prior qualification required to enrol in this course. The student must hold at least this level. Default: no prerequisite.', ['class' => 'text-muted small mb-1']);
    echo html_writer::select($qualOptions, 'req_prereq', 'none', false,
        ['id' => 'req_prereq', 'class' => 'form-control mb-3']);

    echo html_writer::tag('label', 'Required LLN level (ACSF)',
        ['for' => 'req_lln_level', 'class' => 'd-block mb-1 font-weight-bold']);
    echo html_writer::tag('p', 'The minimum Australian Core Skills Framework level required for this course. Most Certificate III/IV courses require Level 3.', ['class' => 'text-muted small mb-1']);
    echo html_writer::select(
        ['1' => 'ACSF Level 1', '2' => 'ACSF Level 2', '3' => 'ACSF Level 3', '4' => 'ACSF Level 4', '5' => 'ACSF Level 5'],
        'req_lln_level', '3', false, ['id' => 'req_lln_level', 'class' => 'form-control mb-3']);

    // ── LLN: render based on configured adapter (v4.2.50) ──
    if ($llnIsManual) {
        // Manual adapter: show the dropdown directly — current behaviour.
        echo html_writer::tag('label', 'Student\'s assessed LLN level (if known)',
            ['for' => 'lln_actual_level', 'class' => 'd-block mb-1 font-weight-bold']);
        echo html_writer::tag('p', 'If the student has already completed an LLN assessment, record their result here. Leave as <em>Not yet assessed</em> if no result is on file — the system will recommend an LLN assessment as part of the support plan.', ['class' => 'text-muted small mb-1']);
        echo html_writer::select($llnOptions, 'lln_actual_level', '', false,
            ['id' => 'lln_actual_level', 'class' => 'form-control mb-3']);
    } else {
        // External adapter (e.g. webhook): show notice + per-send manual override.
        echo html_writer::start_div('alert alert-info mb-3');
        echo get_string('lln_send_webhook_notice', 'local_rtocompliance', s($llnAdapterLabel));
        echo html_writer::end_div();

        echo html_writer::start_div('form-check mb-2');
        echo '<input class="form-check-input" type="checkbox" id="lln_manual_override" name="lln_manual_override" value="1">';
        echo '<label class="form-check-label font-weight-bold" for="lln_manual_override">' .
            get_string('lln_send_manual_override', 'local_rtocompliance') . '</label>';
        echo html_writer::tag('p', get_string('lln_send_manual_override_desc', 'local_rtocompliance', s($llnAdapterLabel)),
            ['class' => 'text-muted small mb-1']);
        echo html_writer::end_div();

        echo html_writer::start_div('mb-3', ['id' => 'lln_manual_block', 'style' => 'display:none']);
        echo html_writer::tag('label', 'Student\'s assessed LLN level',
            ['for' => 'lln_actual_level', 'class' => 'd-block mb-1 font-weight-bold']);
        echo html_writer::select($llnOptions, 'lln_actual_level', '', false,
            ['id' => 'lln_actual_level', 'class' => 'form-control']);
        echo html_writer::end_div();

        echo html_writer::script('
(function (){
    var cb  = document.getElementById("lln_manual_override");
    var blk = document.getElementById("lln_manual_block");
    if (cb && blk) {
        cb.addEventListener("change", function (){ blk.style.display = cb.checked ? "block" : "none"; });
    }
})();
');
    }

    echo html_writer::tag('button', get_string('suitability_send_btn', 'local_rtocompliance'), [
        'type'  => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::start_div('mt-4 p-3 bg-light rounded', ['style' => 'max-width:720px']);
    echo html_writer::tag('strong', 'What the student will see (4-stage flow)');
    echo '<ol class="mt-2 mb-0">';
    echo '<li><strong>Stage 1 — Evidence:</strong> highest qualification, schooling, industry experience, digital literacy, optional disability/RA disclosure.</li>';
    echo '<li><strong>Stage 2 — LLN:</strong> required ACSF level for this course alongside their assessed level (auto-pulled from <em>' . s($llnAdapterLabel) . '</em>).</li>';
    echo '<li><strong>Stage 3 — System Decision:</strong> Suitable / Suitable with Support / Not Suitable, computed from Stages 1-2 plus the entry requirements you set above. Plain-language advice and next steps shown alongside the outcome.</li>';
    echo '<li><strong>Stage 4 — Declaration:</strong> typed full name + "I confirm the information provided is true and accurate to the best of my knowledge."</li>';
    echo '</ol>';
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
