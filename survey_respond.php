<?php
// survey_respond.php — Token-based survey response page for QI surveys.
// This file is intentionally accessible WITHOUT Moodle login (token-gated).
// Created v4.0.64: was previously missing, causing "File not found" errors
// when recipients clicked their survey invitation email link.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/weblib.php');

$token = required_param('token', PARAM_ALPHANUM);

// Look up the survey record by access token.
$survey = $DB->get_record('local_rtocompliance_surveys', ['accesstoken' => $token]);

if (!$survey) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url('/local/rtocompliance/survey_respond.php');
    $PAGE->set_title('Survey Not Found');
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This survey link is invalid or has expired. Please contact your training organisation for a new link.', 'error');
    echo $OUTPUT->footer();
    die();
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/rtocompliance/survey_respond.php', ['token' => $token]);
$PAGE->set_title('Training Survey');
$PAGE->set_heading('Training Survey');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// Already completed?
if ($survey->status === 'completed') {
    echo $OUTPUT->header();
    echo '<div class="compliance-container">';
    echo '<div class="info-card" style="text-align:center;padding:3rem 2rem;">';
    echo '<h2 style="color:#16a34a;margin-bottom:1rem;">Thank You!</h2>';
    echo '<p>You have already completed this survey. Your response has been recorded.</p>';
    echo '<p style="color:#6b7280;font-size:0.9rem;">If you have any questions please contact your training organisation directly.</p>';
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    die();
}

// Define questions per survey type.
$learner_questions = [
    'q1'  => 'Overall, how satisfied are you with the training you received? (1 = Very dissatisfied, 5 = Very satisfied)',
    'q2'  => 'The training was relevant to my work or career goals.',
    'q3'  => 'The trainer/assessor was knowledgeable and helpful.',
    'q4'  => 'The training materials and resources were appropriate.',
    'q5'  => 'Assessment tasks were fair and clearly explained.',
    'q6'  => 'I received adequate support during my training.',
    'q7'  => 'The training organisation communicated well with me.',
    'q8'  => 'I would recommend this training to others.',
];

$employer_questions = [
    'q1'  => 'Overall, how satisfied are you with the training outcomes of graduates? (1 = Very dissatisfied, 5 = Very satisfied)',
    'q2'  => 'Graduates demonstrated skills and knowledge relevant to the workplace.',
    'q3'  => 'The RTO communicated well with us during the training period.',
    'q4'  => 'We would support our employees to train with this RTO again.',
    'q5'  => 'The training met our business/industry requirements.',
    'q6'  => 'The assessment outcomes were appropriate for the job role.',
];

$questions = ($survey->surveytype === 'employer') ? $employer_questions : $learner_questions;

// Handle form submission.
$submitted = optional_param('submit', '', PARAM_ALPHA);
if ($submitted === 'yes' && confirm_sesskey()) {
    $responses = [];
    foreach ($questions as $key => $label) {
        $responses[$key] = optional_param($key, '', PARAM_TEXT);
    }
    $overallsatisfaction = optional_param('q1', null, PARAM_INT);
    $comments = optional_param('comments', '', PARAM_TEXT);

    $DB->update_record('local_rtocompliance_surveys', (object)[
        'id'                  => $survey->id,
        'responses'           => json_encode($responses),
        'overallsatisfaction' => $overallsatisfaction ?: null,
        'comments'            => $comments,
        'status'              => 'completed',
        'timecompleted'       => time(),
    ]);

    echo $OUTPUT->header();
    echo '<div class="compliance-container">';
    echo '<div class="info-card" style="text-align:center;padding:3rem 2rem;">';
    echo '<h2 style="color:#16a34a;margin-bottom:1rem;">Thank You!</h2>';
    echo '<p>Your response has been recorded. We appreciate your feedback.</p>';
    if ($survey->surveytype === 'learner') {
        echo '<p>Your feedback helps us improve our training and assessment practices for future learners.</p>';
    } else {
        echo '<p>Your feedback helps us ensure our training meets industry needs and employer expectations.</p>';
    }
    echo '<p style="color:#6b7280;font-size:0.9rem;margin-top:1.5rem;">If you have further questions, please contact your training organisation directly.</p>';
    echo '</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    die();
}

// Render survey form.
echo $OUTPUT->header();

$surveyLabel = ($survey->surveytype === 'employer') ? 'Employer Satisfaction Survey' : 'Learner Satisfaction Survey';
$yearLabel   = date('Y', $survey->timecreated);

echo '<div class="compliance-container">';
echo '<div class="compliance-header">';
echo html_writer::tag('h2', $surveyLabel);
echo html_writer::tag('p', 'Quality Indicator Survey — ' . $yearLabel, ['class' => 'text-muted']);
echo '</div>';

if (!empty($survey->respondentname)) {
    echo '<div class="info-card" style="margin-bottom:1.5rem;">';
    echo '<p>Hello <strong>' . s($survey->respondentname) . '</strong>,</p>';
    echo '<p>Please take a few minutes to complete this survey. Your answers are confidential and help us improve our training services.</p>';
    echo '<p>For each statement, please select a rating from <strong>1 (Strongly Disagree / Very Dissatisfied)</strong> to <strong>5 (Strongly Agree / Very Satisfied)</strong>.</p>';
    echo '</div>';
}

echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/survey_respond.php', ['token' => $token]))->out() . '" style="margin-top:1rem;">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="submit" value="yes">';

$qnum = 0;
foreach ($questions as $key => $label) {
    $qnum++;
    echo '<div class="info-card" style="margin-bottom:1rem;">';
    echo '<p style="font-weight:600;margin-bottom:0.75rem;"><span style="color:#6b7280;">' . $qnum . '.</span> ' . s($label) . '</p>';
    echo '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;">';
    for ($i = 1; $i <= 5; $i++) {
        $labels = ['', 'Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree'];
        if ($key === 'q1') {
            $labels = ['', 'Very Dissatisfied', 'Dissatisfied', 'Neutral', 'Satisfied', 'Very Satisfied'];
        }
        echo '<label style="display:flex;flex-direction:column;align-items:center;gap:0.25rem;cursor:pointer;padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:6px;min-width:80px;text-align:center;">';
        echo '<input type="radio" name="' . $key . '" value="' . $i . '" required style="margin:0;">';
        echo '<span style="font-size:1.1rem;font-weight:700;">' . $i . '</span>';
        echo '<span style="font-size:0.7rem;color:#6b7280;">' . $labels[$i] . '</span>';
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';
}

echo '<div class="info-card" style="margin-bottom:1.5rem;">';
echo '<p style="font-weight:600;margin-bottom:0.5rem;">Additional Comments (optional)</p>';
echo '<textarea name="comments" rows="4" style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:0.5rem;" placeholder="Please share any additional feedback about your training experience..."></textarea>';
echo '</div>';

echo '<div style="text-align:right;">';
echo '<button type="submit" class="btn btn-primary" style="min-width:160px;">Submit Survey</button>';
echo '</div>';
echo '</form>';
echo '</div>';

echo $OUTPUT->footer();
