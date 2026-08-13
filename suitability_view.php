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

// Admin view: review a completed suitability submission and (optionally)
// override the system-generated outcome.
//
// AUDIT-REWRITE (v4.2.47): rebuilt for the new evidence-based flow.  The
// trainer can now override to any of the three outcomes (Suitable /
// Suitable with Support / Not Suitable) with a mandatory reason — meeting
// PI 2(b) "documented advice" expectation that human judgement is on the
// record.  Backward-compat: if the record has legacy answer rows the old
// 16-question table is shown.

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_rtocompliance_students');

$PAGE->set_url(new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $id]));
$PAGE->set_title(get_string('suitability_view_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('suitability_view_title', 'local_rtocompliance'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add(get_string('suitability_view_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

$suit    = $DB->get_record('local_rtocompliance_suitability', ['id' => $id], '*', MUST_EXIST);
$student = core_user::get_user($suit->userid, '*', MUST_EXIST);
$tas     = $DB->get_record('local_rtocompliance_tas', ['id' => $suit->tasid], '*', MUST_EXIST);
$answers = $DB->get_records('local_rtocompliance_suitability_answers', ['suitabilityid' => $suit->id], 'displayorder ASC');
$isLegacy = !empty($answers);
$rtoname  = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';

// ─── Handle trainer decision (v4.4.42 new form) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()
        && optional_param('trainer_action', '', PARAM_ALPHA) === 'decision') {
    $decision   = optional_param('trainer_decision_val', '', PARAM_ALPHANUMEXT);
    $advicetxt  = trim(optional_param('trainer_advice_text', '', PARAM_TEXT));
    $justif     = trim(optional_param('trainer_justification', '', PARAM_TEXT));
    $decl_tick  = optional_param('trainer_declaration_tick', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW -- rich-text/JSON field; sanitised before display or decoded immediately
        $validDec   = ['suitable', 'suitable_with_support', 'not_suitable'];

    if (!in_array($decision, $validDec, true)) {
        redirect($PAGE->url, 'Please select a valid decision outcome.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (empty($advicetxt)) {
        redirect($PAGE->url, 'Please provide advice for the student.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (empty($justif)) {
        redirect($PAGE->url, 'Please provide a justification for your decision.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (!$decl_tick) {
        redirect($PAGE->url, 'You must tick the trainer declaration before saving.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $suit->trainer_decision      = $decision;
    $suit->trainer_advice        = substr($advicetxt, 0, 5000);
    $suit->trainer_justification = substr($justif, 0, 2000);
    $suit->trainer_declaration   = 1;
    $suit->trainer_declared_at   = time();
    $suit->trainerid             = $USER->id;
    $suit->status                = $decision;
    $suit->timemodified          = time();
    $DB->update_record('local_rtocompliance_suitability', $suit);

    local_rtocompliance_log_action('suitability_trainer_decision', 'suitability', $suit->id, [
        'decision'  => $decision,
        'trainerid' => $USER->id,
    ]);

    // Email the student with the decision and advice.
    $rtoname_n = get_config('local_rtocompliance', 'rtoname') ?: 'Your Training Provider';
    $subj = $rtoname_n . ' — Your Eligibility Check Result';
    $bodytext = "Dear " . fullname($student) . ",\n\n"
        . "Your trainer has reviewed your Student Eligibility Check for " . $tas->qualificationcode . " " . $tas->qualificationname . ".\n\n"
        . "Decision: " . strtoupper(str_replace('_', ' ', $decision)) . "\n\n"
        . $suit->trainer_advice . "\n\n"
        . "If you have any questions, please contact your training provider.\n\n"
        . "Regards,\n" . $rtoname_n;
    email_to_user($student, core_user::get_support_user(), $subj, $bodytext);

    redirect($PAGE->url, 'Trainer decision saved and student notified by email.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// ─── Handle legacy override submission ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $newOutcome = required_param('override_outcome', PARAM_ALPHANUMEXT);
    $notes      = required_param('overridenotes', PARAM_TEXT);
    $validOut   = ['suitable', 'suitable_with_support', 'not_suitable'];

    if (!in_array($newOutcome, $validOut, true)) {
        redirect($PAGE->url, 'Please choose a valid outcome.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (trim($notes) === '') {
        redirect($PAGE->url, get_string('suitability_override_notes_required', 'local_rtocompliance'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $statusmap = [
        'suitable'              => 'override_suitable',
        'suitable_with_support' => 'override_suitable_with_support',
        'not_suitable'          => 'override_not_suitable',
    ];
    $suit->status           = $statusmap[$newOutcome];
    $suit->override_outcome = $newOutcome;
    $suit->overridenotes    = trim($notes);
    $suit->overriddenby     = $USER->id;
    $suit->overriddentime   = time();
    $suit->timemodified     = time();

    // Refresh the student-facing advice to reflect the override decision.
    $reasons = !empty($suit->reasons) ? json_decode($suit->reasons, true) : [];
    $support = !empty($suit->support_required) ? json_decode($suit->support_required, true) : [];
    $support[] = 'Trainer override applied: ' . trim($notes);
    $suit->support_required = json_encode($support);
    $suit->advice = local_rtocompliance_format_suitability_advice(
        $suit->status, $reasons, $support, $tas, $rtoname);

    $DB->update_record('local_rtocompliance_suitability', $suit);
    local_rtocompliance_log_action('suitability_overridden', 'suitability', $suit->id, [
        'outcome' => $newOutcome,
        'notes'   => $notes,
    ]);
    redirect($PAGE->url, get_string('suitability_overridden_ok', 'local_rtocompliance'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('students', 'local_rtocompliance'), null, null, 'students');
echo $OUTPUT->heading(get_string('suitability_view_title', 'local_rtocompliance'), 2);

// ── Summary header ──
$statuslabel = [
    'pending'                          => '<span class="badge badge-info">Awaiting Response</span>',
    'submitted'                        => '<span class="badge badge-warning">Submitted — Awaiting Trainer Review</span>',
    'suitable'                         => '<span class="badge badge-success">Suitable</span>',
    'suitable_with_support'            => '<span class="badge badge-warning">Suitable with Support</span>',
    'not_suitable'                     => '<span class="badge badge-danger">Not Suitable</span>',
    'override_suitable'                => '<span class="badge badge-warning">Override: Suitable</span>',
    'override_suitable_with_support'   => '<span class="badge badge-warning">Override: Suitable with Support</span>',
    'override_not_suitable'            => '<span class="badge badge-secondary">Override: Not Suitable</span>',
];
$statusbadge = $statuslabel[$suit->status] ?? '<span class="badge badge-secondary">' . s($suit->status) . '</span>';

echo html_writer::start_div('generalbox mb-4 p-3');
echo html_writer::start_tag('table', ['class' => 'table table-sm mb-0', 'style' => 'max-width:720px']);
echo '<tr><th style="width:200px">Student</th><td>' . fullname($student) . ' &lt;' . s($student->email) . '&gt;</td></tr>';
echo '<tr><th>Qualification</th><td>' . s($tas->qualificationcode . ' ' . $tas->qualificationname) . '</td></tr>';
echo '<tr><th>Status</th><td>' . $statusbadge . '</td></tr>';
echo '<tr><th>Sent</th><td>' . ($suit->timesent ? userdate($suit->timesent) : '—') . '</td></tr>';
echo '<tr><th>Completed</th><td>' . ($suit->timecompleted ? userdate($suit->timecompleted) : '—') . '</td></tr>';
echo '<tr><th>Reference</th><td>Standards for RTOs 2025 — Standard 2 PI 2(a) &amp; 2(b)</td></tr>';
echo html_writer::end_tag('table');
echo html_writer::end_div();

// ── Action: download PDF report (only when there's something to report) ──
if ($suit->status !== 'pending') {
    echo html_writer::tag('p',
        html_writer::link(
            new moodle_url('/local/rtocompliance/suitability_pdf.php', ['id' => $suit->id]),
            'Download PDF Report',
            ['class' => 'btn btn-outline-primary mb-3', 'target' => '_blank', 'data-testid' => 'link-suit-pdf-' . $suit->id]
        )
    );
}

// Detect which form version the student used.
// FIX-MAY4-ISNEWFORM (v4.4.44): The original detection — digital_literacy OR
// status='submitted' — missed records where the trainer had already made a
// decision (status changed from 'submitted' to 'suitable'/'not_suitable'/etc.)
// AND digital_literacy happened to be empty (e.g. column added in v4.4.42 but
// the student left the self-rating blank).  In those cases isNewForm was false,
// so the trainer saw the old 3-section view with no Section 5 or signature.
// Fix: also flag new-form when lln_evidence or declaration_name is set — those
// columns only exist on records submitted through the v4.4.42+ form flow.
$isNewForm = !empty($suit->digital_literacy)
          || !empty($suit->lln_evidence)
          || !empty($suit->declaration_name)
          || $suit->status === 'submitted';

// ─── Body — always render new Student Eligibility Checklist structure ────────
// FIX-MAY2026-VIEW-NOLEGACY (v4.4.49): removed legacy 16-question table display
// and old evidence-based view. All records now render in the new Student
// Eligibility Checklist structure. Fields absent on pre-v4.4.42 records show "—".
if ($suit->status === 'pending') {
    echo $OUTPUT->notification('The student has not yet completed the Student Eligibility Checklist.', \core\output\notification::NOTIFY_INFO);
} else {
    // ── v4.4.42 Student Eligibility Checklist view ──
    $dlLabels = [
        'basic'    => 'Unable to use basic computer systems',
        'limited'  => 'Limited skills in using a computer',
        'adequate' => 'Adequate skills in using a computer',
        'strong'   => 'Strong skills in using a computer',
    ];
    $psLabels = [
        'none'      => 'No skills or experience in this area',
        'limited'   => 'Limited skills or experience in this area',
        'relevant'  => 'Relevant skills or experience in this area',
        'extensive' => 'Extensive skills or experience in this area',
    ];
    $snLabels = [
        'lln'       => 'LLN support',
        'digital'   => 'Digital skills support',
        'mentoring' => 'Workplace mentoring',
        'moretime'  => 'More time to complete assessments',
        'flexible'  => 'Flexible study arrangements',
        'english'   => 'English support',
        'disability' => 'Other support relating to a disability',
        'cultural'  => 'Cultural or language support',
        'carer'     => 'Carer / family responsibilities',
        'other'     => 'Other support needs',
    ];

    echo html_writer::tag('h5', 'Section 1 — Language, Literacy and Numeracy (LLN)', ['class' => 'mt-2 mb-3']);
    echo html_writer::start_div('generalbox p-3 mb-4');
    echo html_writer::start_tag('table', ['class' => 'table table-sm mb-0', 'style' => 'max-width:720px']);
    if (!empty($suit->req_lln_level)) {
        echo '<tr><th style="width:240px">ACSF level required for course</th><td>Level ' . s($suit->req_lln_level) . '</td></tr>';
    }
    echo '<tr><th style="width:240px">Student\'s LLN self-report</th><td><span style="white-space:pre-wrap">'
        . (!empty(trim($suit->lln_evidence ?? '')) ? s($suit->lln_evidence) : '<span class="text-muted">Not provided</span>')
        . '</span></td></tr>';
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    echo html_writer::tag('h5', 'Section 2 — Digital Literacy', ['class' => 'mt-3 mb-3']);
    echo html_writer::start_div('generalbox p-3 mb-4');
    echo html_writer::start_tag('table', ['class' => 'table table-sm mb-0', 'style' => 'max-width:720px']);
    echo '<tr><th style="width:240px">Digital literacy level</th><td>' . s($dlLabels[$suit->digital_literacy ?? ''] ?? '—') . '</td></tr>';
    if (!empty(trim($suit->digital_literacy_evidence ?? ''))) {
        echo '<tr><th>Digital evidence / description</th><td><span style="white-space:pre-wrap">' . s($suit->digital_literacy_evidence) . '</span></td></tr>';
    }
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    echo html_writer::tag('h5', 'Section 3 — Prior Skills and Experience', ['class' => 'mt-3 mb-3']);
    echo html_writer::start_div('generalbox p-3 mb-4');
    echo html_writer::start_tag('table', ['class' => 'table table-sm mb-0', 'style' => 'max-width:720px']);
    echo '<tr><th style="width:240px">Prior skills level</th><td>' . s($psLabels[$suit->prior_skills ?? ''] ?? '—') . '</td></tr>';
    if (!empty(trim($suit->prior_skills_evidence ?? ''))) {
        echo '<tr><th>Prior skills evidence / description</th><td><span style="white-space:pre-wrap">' . s($suit->prior_skills_evidence) . '</span></td></tr>';
    }
    echo html_writer::end_tag('table');
    echo html_writer::end_div();

    if (!empty(trim($suit->course_req_note ?? ''))) {
        echo html_writer::tag('h5', 'Section 4 — Entry Requirements Gap Note', ['class' => 'mt-3 mb-3']);
        echo html_writer::start_div('generalbox p-3 mb-4');
        echo '<span style="white-space:pre-wrap">' . s($suit->course_req_note) . '</span>';
        echo html_writer::end_div();
    }

    echo html_writer::tag('h5', 'Section 5 — Support Needs', ['class' => 'mt-3 mb-3']);
    echo html_writer::start_div('generalbox p-3 mb-4');
    $snSelected = !empty($suit->support_needs) ? json_decode($suit->support_needs, true) : [];
    if (!empty($snSelected)) {
        echo '<ul class="mb-0">';
        foreach ($snSelected as $snKey) {
            echo '<li>' . s($snLabels[$snKey] ?? $snKey);
            if ($snKey === 'disability' && !empty(trim($suit->disability_disclosure ?? ''))) {
                echo ': <em>' . s(trim($suit->disability_disclosure)) . '</em>';
            }
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<span class="text-muted">No support needs disclosed.</span>';
    }
    echo html_writer::end_div();

    // Student declaration.
    echo html_writer::tag('h5', 'Student Declaration', ['class' => 'mt-3 mb-3']);
    echo html_writer::start_div('generalbox p-3 mb-4', ['style' => 'max-width:780px']);
    echo html_writer::start_tag('ol', ['style' => 'margin-bottom:12px;line-height:1.6;font-size:0.9rem;color:#444']);
    echo html_writer::tag('li', 'The information I have provided in this form is true and complete to the best of my knowledge.');
    echo html_writer::tag('li', 'I understand that providing false or misleading information may result in my enrolment being cancelled or withdrawn.');
    echo html_writer::tag('li', 'I consent to my training provider collecting and using this information to assess my suitability for enrolment, to arrange appropriate learning support, and to keep records as required under the Privacy Act 1988 and the Standards for RTOs.');
    echo html_writer::tag('li', 'I understand that completing this form does not guarantee enrolment, and that the RTO may contact me to request additional information before a final decision is made.');
    echo html_writer::tag('li', 'I agree to notify my training provider of any significant changes to my circumstances before my first scheduled training day.');
    echo html_writer::end_tag('ol');
    if (!empty($suit->declaration_name)) {
        echo html_writer::start_tag('table', ['class' => 'table table-sm mb-0', 'style' => 'max-width:560px']);
        echo '<tr><th style="width:220px">Electronic signature (typed name)</th><td><strong>' . s($suit->declaration_name) . '</strong></td></tr>';
        echo '<tr><th>Signed at</th><td>'
            . (!empty($suit->declaration_signed_at) ? userdate((int)$suit->declaration_signed_at) : '<span class="text-muted">—</span>')
            . '</td></tr>';
        echo html_writer::end_tag('table');
        echo '<p style="margin:10px 0 0;font-size:0.85rem;color:#666;">Electronic signature confirmed by the student typing their name and ticking the declaration checkbox.</p>';
    } else {
        echo '<span class="text-muted">No signature on file.</span>';
    }
    echo html_writer::end_div();
}

// ─── Trainer Decision section — always uses new v4.4.42 panel ────────────────
// FIX-MAY2026-VIEW-NOLEGACY: removed legacy override panel branching.
// All records now use the Trainer Decision form (decision/advice/justification).
if ($suit->status !== 'pending') {
    echo html_writer::tag('h5', 'Trainer Decision (PI 2(b) — Documented Trainer Judgement)', ['class' => 'mt-4 mb-2']);

        if (!empty($suit->trainer_decision)) {
            // Decision already made — show read-only panel.
            $trainerUser = $suit->trainerid ? core_user::get_user($suit->trainerid) : null;
            $trainerName = $trainerUser ? fullname($trainerUser) : 'Unknown trainer';
            $decLabels   = [
                'suitable'               => 'Suitable to Enrol',
                'suitable_with_support'  => 'Suitable with Support',
                'not_suitable'           => 'Not Currently Suitable',
            ];
            echo html_writer::start_div('alert alert-info', ['style' => 'max-width:780px']);
            echo '<strong>Decision Recorded</strong><br>';
            echo 'Decision: <strong>' . s($decLabels[$suit->trainer_decision] ?? $suit->trainer_decision) . '</strong><br>';
            echo 'Made by: <strong>' . s($trainerName) . '</strong>';
            if (!empty($suit->trainer_declared_at)) { echo ' on ' . userdate((int)$suit->trainer_declared_at); }
            echo '<br><br>';
            if (!empty($suit->trainer_justification)) {
                echo '<strong>Justification:</strong><br><span style="white-space:pre-wrap">' . s($suit->trainer_justification) . '</span><br><br>';
            }
            if (!empty($suit->trainer_advice)) {
                echo '<strong>Advice sent to student:</strong><br><span style="white-space:pre-wrap">' . s($suit->trainer_advice) . '</span>';
            }
            echo html_writer::end_div();
        } else if ($suit->status === 'submitted') {
            // Awaiting decision — show form.
            echo html_writer::tag('p',
                'The student has completed their eligibility check and is awaiting your review. Complete the form below to record your decision. The student will be emailed your advice.',
                ['class' => 'text-muted', 'style' => 'max-width:780px']);

            echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'style' => 'max-width:780px']);
            echo html_writer::input_hidden_params(new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $id, 'sesskey' => sesskey()]));
            echo '<input type="hidden" name="trainer_action" value="decision">';

            echo html_writer::start_div('form-group');
            echo html_writer::tag('label', 'Decision outcome', ['for' => 'trainer_decision_val', 'class' => 'font-weight-bold']);
            echo html_writer::select(
                [
                    ''                       => '— Select outcome —',
                    'suitable'               => 'Suitable to Enrol',
                    'suitable_with_support'  => 'Suitable with Support',
                    'not_suitable'           => 'Not Currently Suitable',
                ],
                'trainer_decision_val', '', false,
                ['id' => 'trainer_decision_val', 'class' => 'form-control', 'required' => 'required']
            );
            echo html_writer::end_div();

            echo html_writer::start_div('form-group mt-3');
            echo html_writer::tag('label', 'Advice to student (required — emailed to the student)',
                ['for' => 'trainer_advice_text', 'class' => 'font-weight-bold']);
            echo html_writer::tag('textarea', '', [
                'name'        => 'trainer_advice_text',
                'id'          => 'trainer_advice_text',
                'class'       => 'form-control mt-1',
                'rows'        => 5,
                'placeholder' => 'e.g. Based on your eligibility check, you have been approved to enrol in this qualification. Your trainer will contact you to discuss enrolment arrangements and any support that has been identified...',
                'required'    => 'required',
            ]);
            echo html_writer::end_div();

            echo html_writer::start_div('form-group mt-3');
            echo html_writer::tag('label', 'Trainer justification (required — recorded for audit, not sent to student)',
                ['for' => 'trainer_justification', 'class' => 'font-weight-bold']);
            echo html_writer::tag('textarea', '', [
                'name'        => 'trainer_justification',
                'id'          => 'trainer_justification',
                'class'       => 'form-control mt-1',
                'rows'        => 3,
                'placeholder' => 'Record your professional judgement. Reference the student\'s LLN self-report, digital literacy, prior skills, and any identified support needs.',
                'required'    => 'required',
            ]);
            echo html_writer::end_div();

            echo html_writer::start_div('form-group mt-3');
            echo '<label style="display:flex;align-items:flex-start;gap:10px;font-size:0.92rem">'
                . '<input type="checkbox" name="trainer_declaration_tick" value="1" required style="margin-top:3px;flex-shrink:0">'
                . ' I have reviewed the student\'s eligibility check responses, made a professional judgement in line with Standard 2 PI 2(b) of the Standards for RTOs 2025, and I declare this decision to be accurate and complete to the best of my knowledge.'
                . '</label>';
            echo html_writer::end_div();

            echo html_writer::tag('button', 'Save Decision &amp; Notify Student', [
                'type'  => 'submit',
                'class' => 'btn btn-primary mt-3',
            ]);
            echo html_writer::end_tag('form');
        }
}

// Back button
echo html_writer::tag('p',
    html_writer::link(
        new moodle_url('/local/rtocompliance/students.php'),
        '&larr; ' . get_string('students', 'local_rtocompliance'),
        ['class' => 'btn btn-outline-secondary mt-3']
    ),
    ['class' => 'mt-4']
);

echo $OUTPUT->footer();
