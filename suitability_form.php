<?php
// Public student-facing pre-enrolment suitability review form.
// Accessed via a token link emailed to the student — no Moodle login required.
//
// AUDIT-REWRITE (v4.2.50): true 4-stage stepper UI with pluggable LLN adapter.
// Stages, in order:
//   Stage 1 — Evidence: qualification, school, experience, digital
//             literacy, disability/RA disclosure.
//   Stage 2 — LLN/ACSF: dispatched through the configured LLN adapter
//             (manual or external webhook). Read-only — students never
//             self-report an ACSF level here. If no result is on file,
//             the system tells the student that an LLN assessment will
//             be arranged.
//   Stage 3 — System Decision: shown AFTER submit on the same screen,
//             together with reasons, support items and plain-language
//             advice. The visual stepper communicates this up-front so
//             the student knows what's coming.
//   Stage 4 — Declaration: typed full name + truth declaration + submit.
//
// Backward-compat: pending records created before v4.2.47 still have
// answer rows in local_rtocompliance_suitability_answers and render the
// OLD 16-question form via suitability_form_legacy.php so in-flight
// checklists are not broken by the upgrade.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$token = required_param('token', PARAM_ALPHANUM);

$PAGE->set_url('/local/rtocompliance/suitability_form.php', ['token' => $token]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('embedded');
// FIX-MAY5-SUIT-PAGETITLE (v4.4.45): $PAGE->set_title still said the old
// 'Pre-Enrolment Suitability Review' name. The v4.4.44 fix only updated the
// card h1 heading; the browser <title> tag still showed the old label. Updated
// to match the form heading and ASQA-aligned feature name.
$PAGE->set_title('Student Eligibility Checklist');
$PAGE->add_body_class('path-local-rtocompliance rtoc-suitability-form');
$PAGE->requires->css('/local/rtocompliance/styles.css');

echo $OUTPUT->header();

$suit = $DB->get_record('local_rtocompliance_suitability', ['token' => $token]);

$render_message = function (string $cls, string $icon, string $title, string $body) {
    echo html_writer::start_div('rtoc-suit-container');
    echo html_writer::start_div('rtoc-suit-card ' . $cls);
    echo html_writer::tag('div', $icon, ['class' => 'rtoc-suit-icon rtoc-suit-icon-' . substr($cls, strrpos($cls, '-') + 1)]);
    echo html_writer::tag('h2', $title, ['class' => 'rtoc-suit-title']);
    echo html_writer::tag('div', $body, ['class' => 'rtoc-suit-desc']);
    echo html_writer::end_div();
    echo html_writer::end_div();
};

if (!$suit) {
    $render_message('rtoc-suit-error', '!', 'Link Not Found',
        'This eligibility check link could not be found. It may have expired or been replaced with a newer link. '
        . 'Please check your email for a more recent message from your training provider, or contact them directly for assistance.');
    echo $OUTPUT->footer();
    exit;
}

$student = core_user::get_user($suit->userid);
if (!$student || $student->deleted) {
    $render_message('rtoc-suit-error', '!', 'Account Not Found',
        get_string('suitability_student_not_found', 'local_rtocompliance'));
    echo $OUTPUT->footer();
    exit;
}

$tas = $DB->get_record('local_rtocompliance_tas', ['id' => $suit->tasid]);
if (!$tas) {
    $render_message('rtoc-suit-error', '!', 'Qualification Not Found',
        get_string('suitability_tas_not_found', 'local_rtocompliance'));
    echo $OUTPUT->footer();
    exit;
}

$rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';

// ── Detect mode: legacy (has answer rows) vs. new (evidence-based) ───────────
// FIX-MAY2026-SUIT-SELFHEAL (v4.4.48): For PENDING records with legacy answer
// rows (created before v4.2.47 or via old auto-send path), silently delete those
// rows so the new Student Eligibility Checklist is shown instead of the old
// 16-question form. Legacy routing is only preserved for non-pending records that
// were genuinely submitted via the old form, so no completed data is lost.
$legacyAnswers = $DB->get_records('local_rtocompliance_suitability_answers',
    ['suitabilityid' => $suit->id], 'displayorder ASC');
if (!empty($legacyAnswers) && $suit->status === 'pending') {
    $DB->delete_records('local_rtocompliance_suitability_answers', ['suitabilityid' => $suit->id]);
    $legacyAnswers = [];
}
$isLegacy = !empty($legacyAnswers);

// Already completed or submitted and awaiting trainer review?
if (!in_array($suit->status, ['pending'], true)) {
    if ($suit->status === 'submitted') {
        // v4.4.42: Student submitted but trainer has not yet made a decision.
        echo html_writer::start_div('rtoc-suit-container');
        echo html_writer::start_div('rtoc-suit-card rtoc-suit-review');
        echo html_writer::tag('div', '&#9203;', ['class' => 'rtoc-suit-icon rtoc-suit-icon-review']);
        echo html_writer::tag('h2', 'Eligibility Check Submitted', ['class' => 'rtoc-suit-title']);
        echo html_writer::tag('div',
            '<p>Thank you for completing your Student Eligibility Check. Your trainer is currently reviewing your responses and will be in contact with you shortly regarding your enrolment.</p>'
            . '<p>No further action is required from you at this stage.</p>',
            ['class' => 'rtoc-suit-desc']);
        local_rtocompliance_render_signed_declaration_block($suit);
        echo html_writer::end_div();
        echo html_writer::tag('p', s($rtoname), ['class' => 'rtoc-suit-rto-footer']);
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }
    if (!empty($suit->trainer_advice) || !empty($suit->advice)) {
        // New or old flow — trainer has made a decision. Show outcome + advice card.
        echo html_writer::start_div('rtoc-suit-container');
        echo html_writer::start_div('rtoc-suit-card');
        echo html_writer::tag('h1', 'Student Eligibility Check Result', ['style' => 'font-size:1.35rem;color:#1a1a2e;margin:0 0 4px']);
        echo html_writer::tag('p',
            'Student: <strong>' . s(fullname($student)) . '</strong> &middot; ' .
            s($tas->qualificationcode . ' ' . $tas->qualificationname),
            ['class' => 'text-muted', 'style' => 'margin-bottom:18px']);
        local_rtocompliance_render_outcome_block($suit);
        local_rtocompliance_render_signed_declaration_block($suit);
        echo html_writer::tag('p',
            'A copy of this review is on file with your training provider. If anything has changed since you submitted this, please contact them directly.',
            ['class' => 'text-muted', 'style' => 'margin-top:18px;font-size:0.85rem']);
        echo html_writer::end_div();
        echo html_writer::tag('p', s($rtoname), ['class' => 'rtoc-suit-rto-footer']);
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }
    // Legacy completed or no advice yet — plain "thanks" message.
    $render_message('rtoc-suit-success', '&#10003;', 'Already Submitted',
        'Your Pre-Enrolment Suitability Review has already been submitted. Your training provider has your responses on file.');
    echo $OUTPUT->footer();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// LEGACY MODE: pending record created before v4.2.47 — render the old form.
// ─────────────────────────────────────────────────────────────────────────────
if ($isLegacy) {
    require __DIR__ . '/suitability_form_legacy.php';
    echo $OUTPUT->footer();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// STUDENT ELIGIBILITY CHECKLIST (v4.4.42): 5-section single-page form.
// Replaces the old 4-stage stepper (qualification/LLN/system-decision/declaration).
// Student submits → status = 'submitted' → trainer reviews in suitability_view.php.
// ─────────────────────────────────────────────────────────────────────────────

$digitalLiteracyOptions = [
    ''         => '— Please select —',
    'basic'    => 'Unable to use basic computer systems',
    'limited'  => 'Limited skills in using a computer',
    'adequate' => 'Adequate skills in using a computer',
    'strong'   => 'Strong skills in using a computer',
];
$priorSkillsOptions = [
    ''          => '— Please select —',
    'none'      => 'No skills or experience in this area',
    'limited'   => 'Limited skills or experience in this area',
    'relevant'  => 'Relevant skills or experience in this area',
    'extensive' => 'Extensive skills or experience in this area',
];
// FIX-MAY4-SUIT-OPTIONS (v4.4.43): updated option labels and added new support
// need categories (mentoring, moretime, flexible, english) to match the
// document-specified wording; disability option now prompts for free text;
// legacy keys (cultural, carer, other) kept in view/pdf label maps for backward-compat.
$supportNeedsOptions = [
    'lln'       => 'LLN support',
    'digital'   => 'Digital skills support',
    'mentoring' => 'Workplace mentoring',
    'moretime'  => 'More time to complete assessments',
    'flexible'  => 'Flexible study arrangements',
    'english'   => 'English support',
    'disability' => 'Other support relating to a disability',
];

$errors = [];
$form = [
    'lln_evidence'              => $suit->lln_evidence ?? '',
    'digital_literacy'          => $suit->digital_literacy ?? '',
    'digital_literacy_evidence' => $suit->digital_literacy_evidence ?? '',
    'prior_skills'              => $suit->prior_skills ?? '',
    'prior_skills_evidence'     => $suit->prior_skills_evidence ?? '',
    'course_req_note'           => $suit->course_req_note ?? '',
    'support_needs'             => !empty($suit->support_needs) ? json_decode($suit->support_needs, true) : [],
    'disability_disclosure'     => $suit->disability_disclosure ?? '',
    'declaration_name'          => '',
];

$submitted = false;

// ─── Handle submission ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['lln_evidence']              = trim(optional_param('lln_evidence', '', PARAM_TEXT));
    $form['digital_literacy']          = optional_param('digital_literacy', '', PARAM_ALPHANUMEXT);
    $form['digital_literacy_evidence'] = trim(optional_param('digital_literacy_evidence', '', PARAM_TEXT));
    $form['prior_skills']              = optional_param('prior_skills', '', PARAM_ALPHANUMEXT);
    $form['prior_skills_evidence']     = trim(optional_param('prior_skills_evidence', '', PARAM_TEXT));
    $form['course_req_note']           = trim(optional_param('course_req_note', '', PARAM_TEXT));
    $form['support_needs']             = optional_param_array('support_needs', [], PARAM_ALPHANUMEXT);
    $form['disability_disclosure']     = trim(optional_param('disability_disclosure', '', PARAM_TEXT));
    $form['declaration_name']          = trim(optional_param('declaration_name', '', PARAM_TEXT));

    // Filter support_needs to known keys only (prevents injection).
    $form['support_needs'] = array_values(array_intersect($form['support_needs'], array_keys($supportNeedsOptions)));

    if (empty($form['lln_evidence'])) {
        $errors[] = 'Section 1 — please describe your language, literacy and numeracy level and/or experience.';
    }
    if (!isset($digitalLiteracyOptions[$form['digital_literacy']]) || $form['digital_literacy'] === '') {
        $errors[] = 'Section 2 — please select your digital literacy level.';
    }
    if (!isset($priorSkillsOptions[$form['prior_skills']]) || $form['prior_skills'] === '') {
        $errors[] = 'Section 3 — please select your level of prior skills and experience.';
    }
    if (!optional_param('declaration', '', PARAM_RAW)) {
        $errors[] = 'Declaration — you must tick the declaration before submitting.';
    }
    if ($form['declaration_name'] === '') {
        $errors[] = 'Declaration — please type your full name as your electronic signature.';
    } else if (mb_strlen($form['declaration_name']) < 3) {
        $errors[] = 'Declaration — please enter your full name (at least 3 characters).';
    } else if (strpos($form['declaration_name'], ' ') === false) {
        $errors[] = 'Declaration — please type your full name (first and last name, separated by a space).';
    }

    if (empty($errors)) {
        $suit->lln_evidence              = substr($form['lln_evidence'], 0, 2000);
        $suit->digital_literacy          = $form['digital_literacy'];
        $suit->digital_literacy_evidence = $form['digital_literacy_evidence'] ? substr($form['digital_literacy_evidence'], 0, 2000) : null;
        $suit->prior_skills              = $form['prior_skills'];
        $suit->prior_skills_evidence     = $form['prior_skills_evidence'] ? substr($form['prior_skills_evidence'], 0, 2000) : null;
        $suit->course_req_note           = $form['course_req_note'] ? substr($form['course_req_note'], 0, 2000) : null;
        $suit->support_needs             = json_encode($form['support_needs']);
        $suit->disability_disclosure     = $form['disability_disclosure'] ? substr($form['disability_disclosure'], 0, 1000) : null;
        $suit->declaration_name          = substr($form['declaration_name'], 0, 200);
        $suit->declaration_signed_at     = time();
        $suit->status                    = 'submitted';
        $suit->timecompleted             = time();
        $suit->timemodified              = time();
        $DB->update_record('local_rtocompliance_suitability', $suit);

        local_rtocompliance_log_action('suitability_submitted', 'suitability', $suit->id, [
            'digital_literacy' => $form['digital_literacy'],
            'prior_skills'     => $form['prior_skills'],
            'support_needs'    => count($form['support_needs']),
            'declared_by'      => $form['declaration_name'],
        ]);

        // ASYNC-EMAIL (v4.4.52): Queue an adhoc task so cron delivers the admin
        // notification email in the background (≤ 1 minute). This replaces the
        // previous synchronous email_to_user() loop which blocked the page POST
        // for the duration of the SMTP transaction for every site admin account.
        $nfy_task = new \local_rtocompliance\task\send_suitability_notification();
        $nfy_task->set_custom_data(['suitabilityid' => (int)$suit->id]);
        \core\task\manager::queue_adhoc_task($nfy_task);

        $submitted = true;
    }
}

// ─── Render: stylesheet + page chrome ────────────────────────────────────────
echo '
<style>
.rtoc-suit-container { max-width: 780px; margin: 32px auto; padding: 0 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #222; }
.rtoc-suit-card { background: #fff; border: 1px solid #d0d4d9; border-radius: 6px; padding: 28px 32px; margin-bottom: 24px; }
.rtoc-suit-header { border-bottom: 2px solid #0069d9; padding-bottom: 16px; margin-bottom: 22px; }
.rtoc-suit-header h1 { font-size: 1.35rem; color: #1a1a2e; margin: 0 0 4px; }
.rtoc-suit-header p { color: #555; font-size: 0.9rem; margin: 0 0 2px; }
.rtoc-suit-header .rtoc-suit-qual { font-weight: 600; color: #0069d9; }
.rtoc-suit-intro { background: #eef5ff; border-left: 4px solid #0069d9; padding: 14px 18px; border-radius: 4px; margin-bottom: 22px; font-size: 0.92rem; line-height: 1.55; }
.rtoc-suit-intro p:last-child { margin-bottom: 0; }
.rtoc-suit-section { margin-bottom: 22px; padding: 18px 20px; background: #fcfcfd; border: 1px solid #e2e6ea; border-radius: 6px; }
.rtoc-suit-section h3 { margin: 0 0 4px; font-size: 1.05rem; color: #1a1a2e; }
.rtoc-suit-section .rtoc-suit-stage-tag { display: inline-block; background: #0069d9; color: #fff; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; padding: 2px 8px; border-radius: 3px; margin-right: 8px; vertical-align: middle; text-transform: uppercase; }
.rtoc-suit-section .rtoc-suit-section-desc { margin: 6px 0 14px; color: #555; font-size: 0.85rem; }
.rtoc-suit-field { margin-bottom: 14px; }
.rtoc-suit-field label.rtoc-suit-field-label { display: block; font-size: 0.92rem; font-weight: 600; color: #222; margin-bottom: 4px; }
.rtoc-suit-field select, .rtoc-suit-field input[type=text], .rtoc-suit-field textarea {
    width: 100%; border: 1px solid #ced4da; border-radius: 4px; padding: 8px 10px; font: inherit; font-size: 0.95rem; box-sizing: border-box;
}
.rtoc-suit-field textarea { min-height: 72px; resize: vertical; }
.rtoc-suit-field .rtoc-suit-help { font-size: 0.82rem; color: #666; margin-top: 4px; }
.rtoc-suit-checklist { display: flex; flex-direction: column; gap: 8px; }
.rtoc-suit-checklist label { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid #dee2e6; border-radius: 4px; cursor: pointer; font-size: 0.92rem; background: #fff; }
.rtoc-suit-checklist label:has(input:checked) { border-color: #28a745; background: #f0fdf4; }
.rtoc-suit-checklist input[type=checkbox] { flex-shrink: 0; width: 16px; height: 16px; margin: 0; }
.rtoc-suit-confirm { margin: 0 0 12px; padding: 14px 18px; background: #f7f9fc; border-radius: 4px; }
.rtoc-suit-confirm label { display: flex; align-items: flex-start; gap: 10px; font-size: 0.92rem; line-height: 1.4; cursor: pointer; }
.rtoc-suit-signature { margin-top: 12px; }
.rtoc-suit-signature label { display: block; font-size: 0.88rem; font-weight: 600; margin-bottom: 4px; }
.rtoc-suit-signature input { width: 100%; border: 1px solid #ced4da; border-radius: 4px; padding: 8px 10px; font: inherit; font-size: 0.95rem; box-sizing: border-box; }
.rtoc-suit-submit { text-align: right; margin-top: 8px; }
.rtoc-suit-error-msg { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.9rem; }
.rtoc-suit-icon { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; font-weight: bold; margin: 0 auto 18px; }
.rtoc-suit-icon-success { background: #d4edda; color: #155724; }
.rtoc-suit-icon-error   { background: #f8d7da; color: #721c24; }
.rtoc-suit-icon-review  { background: #fff3cd; color: #856404; }
.rtoc-suit-success, .rtoc-suit-review, .rtoc-suit-error { text-align: center; }
.rtoc-suit-title { font-size: 1.4rem; margin-bottom: 12px; }
.rtoc-suit-desc { color: #555; line-height: 1.55; text-align: left; }
.rtoc-suit-rto-footer { text-align: center; color: #888; font-size: 0.82rem; margin-top: 18px; }
.rtoc-suit-outcome { padding: 18px 22px; border-radius: 6px; margin: 18px 0; }
.rtoc-suit-outcome.suitable                       { background: #d4edda; border: 1px solid #b8dcbe; color: #155724; }
.rtoc-suit-outcome.suitable_with_support          { background: #fff3cd; border: 1px solid #ffe7a3; color: #856404; }
.rtoc-suit-outcome.not_suitable                   { background: #f8d7da; border: 1px solid #f1b0b7; color: #721c24; }
.rtoc-suit-outcome.override_suitable              { background: #fff3cd; border: 1px solid #ffe7a3; color: #856404; }
.rtoc-suit-outcome.override_suitable_with_support { background: #fff3cd; border: 1px solid #ffe7a3; color: #856404; }
.rtoc-suit-outcome.override_not_suitable          { background: #e2e3e5; border: 1px solid #c6c8ca; color: #383d41; }
.rtoc-suit-outcome h2 { margin: 0 0 6px; font-size: 1.2rem; }
.rtoc-suit-outcome .rtoc-suit-outcome-sub { font-size: 0.92rem; opacity: 0.85; margin-bottom: 10px; }
.rtoc-suit-outcome ul { margin: 8px 0 4px 18px; padding: 0; }
.rtoc-suit-outcome li { margin-bottom: 4px; }
.rtoc-suit-advice { background: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 14px 18px; margin-top: 12px; white-space: pre-wrap; font-size: 0.92rem; color: #222; line-height: 1.55; }
@media print {
    @page { size: A4 portrait; margin: 14mm 12mm 14mm 12mm; }
    body  { background: #fff !important; }
    .rtoc-suit-container { max-width: 100%; margin: 0; padding: 0; }
    .rtoc-suit-card { border: none; padding: 0; box-shadow: none; }
    .rtoc-suit-section { background: #fff !important; border: 1px solid #aaa !important; }
    .rtoc-suit-submit, .rtoc-suit-confirm { display: none; }
}
</style>
';

echo html_writer::start_div('rtoc-suit-container');
echo html_writer::start_div('rtoc-suit-card');

// Header
echo html_writer::start_div('rtoc-suit-header');
// FIX-MAY4-SUIT-TITLE (v4.4.44): tester document specifies the heading should
// be "Student Eligibility Checklist" (with "Checklist") to match the printed
// form name and the Standard 2 PI 2(a) terminology used in ASQA guidance.
echo html_writer::tag('h1', 'Student Eligibility Checklist');
echo html_writer::tag('p', 'Student: <strong>' . s(fullname($student)) . '</strong>');
echo html_writer::tag('p', 'Qualification: <span class="rtoc-suit-qual">' . s($tas->qualificationcode . ' ' . $tas->qualificationname) . '</span>');
echo html_writer::tag('p', 'Reference: Standards for RTOs 2025 — Standard 2, Performance Indicators 2(a) &amp; 2(b)');
echo html_writer::end_div();

// ─── POST-SUBMIT VIEW ────────────────────────────────────────────────────────
if ($submitted) {
    echo html_writer::start_div('rtoc-suit-review', ['style' => 'text-align:center;padding:12px 0 20px']);
    echo html_writer::tag('div', '&#9203;', ['class' => 'rtoc-suit-icon rtoc-suit-icon-review']);
    echo html_writer::tag('h2', 'Eligibility Check Submitted', ['class' => 'rtoc-suit-title']);
    echo html_writer::tag('div',
        '<p>Thank you for completing your Student Eligibility Check. Your trainer will review your responses and will be in contact with you shortly regarding your enrolment.</p>'
        . '<p>No further action is required from you at this stage.</p>',
        ['class' => 'rtoc-suit-desc']);
    echo html_writer::end_div();
    local_rtocompliance_render_signed_declaration_block($suit);
    echo html_writer::tag('p',
        'A copy of this check has been sent to your training provider.',
        ['class' => 'text-muted', 'style' => 'margin-top:18px;font-size:0.85rem']);
    echo html_writer::end_div();
    echo html_writer::tag('p', s($rtoname), ['class' => 'rtoc-suit-rto-footer']);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ─── FORM VIEW ──────────────────────────────────────────────────────────────

// Intro
echo html_writer::start_div('rtoc-suit-intro');
echo '<p>Before you can be enrolled in this qualification, your training provider is required to check that the course is suitable for you and identify any support you may need. This meets the requirements of <strong>Standard 2</strong> of the Standards for RTOs 2025.</p>';
echo '<p style="margin-bottom:0">Please complete all sections. All information is treated confidentially and used only to plan your enrolment and any reasonable adjustments.</p>';
echo html_writer::end_div();

// Errors
if (!empty($errors)) {
    echo html_writer::start_div('rtoc-suit-error-msg');
    echo '<strong>Please fix the following before submitting:</strong><ul style="margin:6px 0 0 18px;padding:0">';
    foreach ($errors as $e) { echo '<li>' . s($e) . '</li>'; }
    echo '</ul>';
    echo html_writer::end_div();
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'novalidate' => 'novalidate']);

// ── Section 1: LLN / ACSF Level ──────────────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3><span class="rtoc-suit-stage-tag">Section 1</span>Language, Literacy and Numeracy (LLN)</h3>';
echo html_writer::tag('p', 'Your training provider must assess whether you have the language, literacy and numeracy skills needed to complete this course (Australian Core Skills Framework — ACSF). Please describe your current LLN level and any supporting evidence.', ['class' => 'rtoc-suit-section-desc']);
if (!empty($suit->req_lln_level)) {
    echo '<div style="background:#f4f8ff;border-left:4px solid #0069d9;padding:10px 16px;border-radius:4px;margin-bottom:14px;font-size:0.9rem">'
        . '<strong>ACSF level required for this course:</strong> Level ' . s($suit->req_lln_level) . '</div>';
}
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="lln_evidence">What is your current ACSF level? Please describe your LLN skills and any supporting evidence. <span style="font-weight:400;color:#721c24">*</span></label>';
echo '<textarea id="lln_evidence" name="lln_evidence" rows="4" maxlength="2000" placeholder="e.g. \'I completed an LLN assessment at TAFE in 2023 and was assessed at ACSF Level 3 in reading and numeracy.\'&#10;If unsure of your ACSF level, describe how you manage reading, writing and numbers in everyday life or work.">' . s($form['lln_evidence']) . '</textarea>';
echo '<div class="rtoc-suit-help">If you are unsure of your ACSF level, simply describe your experience. Your trainer will assist if needed.</div>';
echo html_writer::end_div();
echo html_writer::end_div();

// ── Section 2: Digital Literacy ──────────────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3><span class="rtoc-suit-stage-tag">Section 2</span>Digital Literacy</h3>';
echo html_writer::tag('p', 'This course involves online learning materials. Please tell us about your digital skills so we can arrange any support you may need.', ['class' => 'rtoc-suit-section-desc']);
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="digital_literacy">How would you describe your current digital literacy skills? <span style="font-weight:400;color:#721c24">*</span></label>';
echo html_writer::select($digitalLiteracyOptions, 'digital_literacy', $form['digital_literacy'], false, ['id' => 'digital_literacy']);
echo html_writer::end_div();
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="digital_literacy_evidence">Please describe your digital experience or any evidence of your digital skills <span style="font-weight:400;color:#666">(optional)</span></label>';
echo '<textarea id="digital_literacy_evidence" name="digital_literacy_evidence" rows="2" maxlength="2000" placeholder="e.g. I use a computer daily at work and am confident with Microsoft Office and email.">' . s($form['digital_literacy_evidence']) . '</textarea>';
echo html_writer::end_div();
echo html_writer::end_div();

// ── Section 3: Prior Skills and Experience ────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3><span class="rtoc-suit-stage-tag">Section 3</span>Prior Skills and Experience</h3>';
echo html_writer::tag('p', 'Tell us about your prior skills or experience relevant to this qualification. This helps your trainer understand your background and identify any recognition of prior learning (RPL) opportunities.', ['class' => 'rtoc-suit-section-desc']);
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="prior_skills">What is your level of prior skills or experience relevant to this qualification? <span style="font-weight:400;color:#721c24">*</span></label>';
echo html_writer::select($priorSkillsOptions, 'prior_skills', $form['prior_skills'], false, ['id' => 'prior_skills']);
echo html_writer::end_div();
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="prior_skills_evidence">Please describe your relevant experience or provide any supporting evidence <span style="font-weight:400;color:#666">(optional)</span></label>';
echo '<textarea id="prior_skills_evidence" name="prior_skills_evidence" rows="2" maxlength="2000" placeholder="e.g. I worked as a support worker for 2 years at Caring Hands Agency, 2021–2023.">' . s($form['prior_skills_evidence']) . '</textarea>';
echo html_writer::end_div();
echo html_writer::end_div();

// ── Section 4: Course Entry Requirements ──────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3><span class="rtoc-suit-stage-tag">Section 4</span>Course Entry Requirements</h3>';
echo html_writer::tag('p', 'Review the entry requirements for this qualification and let us know if you believe you have any gaps.', ['class' => 'rtoc-suit-section-desc']);
$entryreqs = trim($tas->entryrequirements ?? '');
echo '<div style="background:#f4f8ff;border-left:4px solid #0069d9;padding:12px 16px;border-radius:4px;margin-bottom:14px;font-size:0.9rem;white-space:pre-wrap;line-height:1.55">';
echo '<strong>Entry requirements for ' . s($tas->qualificationcode) . ':</strong><br>';
echo !empty($entryreqs) ? s($entryreqs) : '<em style="color:#666">No specific entry requirements have been listed for this qualification. Please contact your trainer if you have any questions.</em>';
echo '</div>';
echo html_writer::start_div('rtoc-suit-field');
echo '<label class="rtoc-suit-field-label" for="course_req_note">If you believe you have any gaps in meeting these requirements, please describe them here <span style="font-weight:400;color:#666">(optional)</span></label>';
echo '<textarea id="course_req_note" name="course_req_note" rows="2" maxlength="2000" placeholder="e.g. I do not hold a First Aid certificate but I am willing to complete one before training begins.">' . s($form['course_req_note']) . '</textarea>';
echo html_writer::end_div();
echo html_writer::end_div();

// ── Section 5: Support Needs ──────────────────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3><span class="rtoc-suit-stage-tag">Section 5</span>Learning Support Needs</h3>';
echo html_writer::tag('p', 'Do you require any of the following types of support? Tick all that apply. Disclosing a support need does not prevent you from enrolling — it helps us plan the right assistance for you. All information is treated confidentially.', ['class' => 'rtoc-suit-section-desc']);
echo html_writer::start_div('rtoc-suit-checklist');
foreach ($supportNeedsOptions as $key => $label) {
    $checked = in_array($key, (array)$form['support_needs'], true) ? ' checked' : '';
    $cbextra = ($key === 'disability') ? ' id="rtoc-suit-disability-cb"' : '';
    echo '<label><input type="checkbox" name="support_needs[]" value="' . s($key) . '"' . $checked . $cbextra . '> ' . s($label) . '</label>';
    if ($key === 'disability') {
        $hideStyle = in_array('disability', (array)$form['support_needs'], true) ? '' : ' style="display:none"';
        echo '<div id="rtoc-suit-disability-detail"' . $hideStyle . ' style="margin-left:24px;margin-top:6px;margin-bottom:6px">';
        echo '<label for="disability_disclosure" style="font-size:0.85rem;font-weight:600;display:block;margin-bottom:4px">Please state here:</label>';
        echo '<textarea id="disability_disclosure" name="disability_disclosure" rows="2" maxlength="1000" placeholder="Describe the support you may need..." style="width:100%;max-width:480px">' . s($form['disability_disclosure']) . '</textarea>';
        echo '</div>';
    }
}
echo html_writer::end_div();
echo html_writer::end_div();
$PAGE->requires->js_init_code('(function() {
    var cb = document.getElementById("rtoc-suit-disability-cb");
    var detail = document.getElementById("rtoc-suit-disability-detail");
    if (!cb || !detail) { return; }
    cb.addEventListener("change", function() {
        detail.style.display = cb.checked ? "" : "none";
    });
})();
', true);

// ── Declaration ───────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-suit-section');
echo '<h3>Declaration</h3>';
echo '<p style="margin-bottom:12px">Please read each statement below carefully. By typing your name and ticking the box, you confirm that all of the following apply to you.</p>';
echo html_writer::start_tag('ol', ['style' => 'margin-bottom:16px;line-height:1.6']);
echo html_writer::tag('li', 'The information I have provided in this form is true and complete to the best of my knowledge.');
echo html_writer::tag('li', 'I understand that providing false or misleading information may result in my enrolment being cancelled or withdrawn.');
echo html_writer::tag('li', 'I consent to my training provider collecting and using this information to assess my suitability for enrolment, to arrange appropriate learning support, and to keep records as required under the <em>Privacy Act 1988</em> and the Standards for RTOs.');
echo html_writer::tag('li', 'I understand that completing this form does not guarantee enrolment, and that the RTO may contact me to request additional information before a final decision is made.');
echo html_writer::tag('li', 'I agree to notify my training provider of any significant changes to my circumstances before my first scheduled training day.');
echo html_writer::end_tag('ol');
echo html_writer::start_div('rtoc-suit-confirm');
echo '<label><input type="checkbox" name="declaration" value="1" required style="margin-top:3px;flex-shrink:0"> I have read and agree to all five statements above.</label>';
echo html_writer::end_div();
echo html_writer::start_div('rtoc-suit-signature');
echo '<label for="declaration_name">Type your full name as your electronic signature</label>';
echo '<input type="text" id="declaration_name" name="declaration_name" value="' . s($form['declaration_name']) . '" maxlength="200" placeholder="Type your full name (first and last name)" autocomplete="off">';
echo html_writer::end_div();
echo html_writer::end_div();

// Submit
echo html_writer::start_div('rtoc-suit-submit');
echo '<button type="submit" id="rtoc-suit-submit-btn" class="btn btn-primary">Submit Eligibility Check</button>';
echo '<script>
document.getElementById("rtoc-suit-submit-btn").closest("form").addEventListener("submit", function() {
    var btn = document.getElementById("rtoc-suit-submit-btn");
    btn.disabled = true;
    btn.textContent = "Submitting\u2026 please wait";
    btn.style.opacity = "0.8";
});
</script>';
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div(); // /card
echo html_writer::tag('p', s($rtoname), ['class' => 'rtoc-suit-rto-footer']);
echo html_writer::end_div(); // /container

echo $OUTPUT->footer();

// ─── Helper: render the outcome / reasons / advice block ─────────────────────
function local_rtocompliance_render_outcome_block(stdClass $suit): void {
    // v4.4.42: trainer-decided records use different labels.
    $isTrainerDecision = !empty($suit->trainer_decision);
    $labels = [
        'suitable'              => $isTrainerDecision
            ? ['SUITABLE TO ENROL', 'Your trainer has reviewed your eligibility check and determined that you are suitable to enrol in this course.']
            : ['SUITABLE TO ENROL', 'You appear to meet the requirements for this course.'],
        'suitable_with_support' => $isTrainerDecision
            ? ['SUITABLE TO ENROL WITH SUPPORT', 'Your trainer has reviewed your eligibility check and determined that you are suitable to enrol with some additional support.']
            : ['SUITABLE WITH SUPPORT', 'You appear suitable with some additional support, see below.'],
        'not_suitable'          => $isTrainerDecision
            ? ['NOT CURRENTLY SUITABLE', 'Your trainer has reviewed your eligibility check and determined that this course may not be the right fit for you at this time. Your trainer will be in contact to discuss your options.']
            : ['NOT CURRENTLY SUITABLE', 'You do not currently meet all of the entry requirements for this course.'],
        'override_suitable'                => ['SUITABLE TO ENROL (Trainer Override)', 'A trainer has reviewed your responses and determined that you are suitable.'],
        'override_suitable_with_support'   => ['SUITABLE WITH SUPPORT (Trainer Override)', 'A trainer has reviewed your responses and determined that you are suitable with support.'],
        'override_not_suitable'            => ['NOT CURRENTLY SUITABLE (Trainer Override)', 'A trainer has reviewed your responses and determined that this course is not currently the right fit.'],
    ];
    $outcome = $suit->status;
    [$title, $sub] = $labels[$outcome] ?? ['REVIEW PENDING', 'Your suitability is being reviewed.'];

    $reasons = !empty($suit->reasons) ? json_decode($suit->reasons, true) : [];
    $support = !empty($suit->support_required) ? json_decode($suit->support_required, true) : [];

    echo html_writer::start_div('rtoc-suit-outcome ' . $outcome);
    echo '<h2>' . s($title) . '</h2>';
    echo '<div class="rtoc-suit-outcome-sub">' . s($sub) . '</div>';
    if (!empty($reasons)) {
        echo '<div style="margin-top:10px"><strong>Why:</strong><ul>';
        foreach ($reasons as $r) { echo '<li>' . s($r) . '</li>'; }
        echo '</ul></div>';
    }
    if (!empty($support)) {
        echo '<div style="margin-top:10px"><strong>Support &amp; recommendations:</strong><ul>';
        foreach ($support as $sp) { echo '<li>' . s($sp) . '</li>'; }
        echo '</ul></div>';
    }
    echo html_writer::end_div();

    // Prefer trainer_advice (v4.4.42+) over the old auto-generated advice.
    $adviceText = !empty($suit->trainer_advice) ? $suit->trainer_advice : ($suit->advice ?? '');
    if (!empty($adviceText)) {
        echo html_writer::start_div('rtoc-suit-advice');
        echo '<strong>Your trainer\'s advice</strong><br>';
        echo s($adviceText);
        echo html_writer::end_div();
    }
}

// ─── Helper: render the signed-declaration confirmation block ────────────────
// FIX-RTOC-B5 (v4.2.51): student-facing card shown after submit and on
// return visits to confirm what was signed and when. Silent no-op for
// any record created before v4.2.51 where the typed-name signature was
// never persisted to the database (declaration_name will be NULL).
function local_rtocompliance_render_signed_declaration_block(stdClass $suit): void {
    if (empty($suit->declaration_name)) {
        return;
    }
    echo html_writer::start_div('rtoc-suit-signed', [
        'style' => 'background:#f7f9fc;border:1px solid #dee2e6;border-left:4px solid #28a745;'
                 . 'border-radius:4px;padding:14px 18px;margin-top:14px;font-size:0.9rem;line-height:1.5'
    ]);
    echo '<strong>Signed declaration on file</strong><br>';
    echo 'Signed by: <strong>' . s($suit->declaration_name) . '</strong>';
    if (!empty($suit->declaration_signed_at)) {
        echo ' on ' . s(date('j M Y, g:ia', (int) $suit->declaration_signed_at));
    }
    echo '<div style="color:#555;margin-top:6px;font-size:0.85rem">'
        . 'You confirmed that the information you provided is true and accurate to the best of your knowledge, '
        . 'and that it will be used by your training provider to assess your suitability for enrolment.'
        . '</div>';
    echo html_writer::end_div();
}

// ─── Admin notification — new evidence-based outcome ─────────────────────────
function local_rtocompliance_notify_admin_suitability(object $student, object $tas, object $suit, array $decision): void {
    global $CFG;
    $admin   = get_admin();
    $rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';
    $viewurl = $CFG->wwwroot . '/local/rtocompliance/suitability_view.php?id=' . $suit->id;
    $noreply = core_user::get_noreply_user();

    $outLabels = [
        'suitable'              => 'SUITABLE',
        'suitable_with_support' => 'SUITABLE WITH SUPPORT',
        'not_suitable'          => 'NOT SUITABLE',
    ];
    $outLabel = $outLabels[$decision['outcome']] ?? strtoupper($decision['outcome']);

    $subject = '[' . $rtoname . '] Suitability submitted: ' . fullname($student) . ' — ' . $outLabel;

    $body  = "A Pre-Enrolment Suitability Review has been submitted.\n\n";
    $body .= "Student:       " . fullname($student) . " <" . $student->email . ">\n";
    $body .= "Qualification: " . $tas->qualificationcode . " " . $tas->qualificationname . "\n";
    $body .= "Outcome:       " . $outLabel . "\n";
    if (!empty($suit->lln_actual_level)) {
        $body .= "LLN level:     ACSF " . $suit->lln_actual_level;
        if (!empty($suit->lln_source))   { $body .= " (source: " . $suit->lln_source . ")"; }
        if (!empty($suit->lln_assessor)) { $body .= " - assessor: " . $suit->lln_assessor; }
        $body .= "\n";
    }
    // FIX-RTOC-B3 (v4.2.51): include the signed-by line so the admin can
    // see the typed-name signature and the signed-at timestamp without
    // having to open the suitability_view.php screen.
    if (!empty($suit->declaration_name)) {
        $body .= "Signed by:     " . $suit->declaration_name;
        if (!empty($suit->declaration_signed_at)) {
            $body .= " on " . userdate((int) $suit->declaration_signed_at);
        }
        $body .= "\n";
    }
    $body .= "\n";
    if (!empty($decision['reasons'])) {
        $body .= "Reasons:\n";
        foreach ($decision['reasons'] as $r) { $body .= "  • " . $r . "\n"; }
        $body .= "\n";
    }
    if (!empty($decision['support'])) {
        $body .= "Support / recommendations:\n";
        foreach ($decision['support'] as $s) { $body .= "  • " . $s . "\n"; }
        $body .= "\n";
    }
    if (!empty(trim($suit->disability_disclosure ?? ''))) {
        $body .= "Disability / RA disclosure:\n  " . trim($suit->disability_disclosure) . "\n\n";
    }
    $body .= "Review or override: " . $viewurl . "\n";

    email_to_user($admin, $noreply, $subject, $body);
}
