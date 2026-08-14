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
 * RTO Compliance plugin — survey_respond.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// survey_respond.php — Token-based survey response page for QI surveys.
// This file is intentionally accessible WITHOUT Moodle login (token-gated).
// Created v4.0.64: was previously missing, causing "File not found" errors
// when recipients clicked their survey invitation email link.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/weblib.php');

// Public entry is via a token link emailed to the respondent. If no token is supplied
// this is not a legitimate public access — require a Moodle login instead.
$token = optional_param('token', '', PARAM_ALPHANUM);
if ($token === '') {
    require_login();
    throw new moodle_exception('missingparam', 'error', '', 'token');
}

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

// AQTF Quality Indicator questionnaire item bank (official ACER standard wording).
// Learner Questionnaire (LQ, 35 items) and Employer Questionnaire (EQ, 30 items),
// each item tagged to its diagnostic scale and to the reportable Quality Indicator.
// Answered on the standard AQTF 4-point agreement scale
// (1 = Strongly Disagree .. 4 = Strongly Agree).
if (!function_exists('local_rtocompliance_qi_item_bank')) {
    function local_rtocompliance_qi_item_bank($surveytype) {
        if ($surveytype === 'employer') {
            $indicators = ['employer_satisfaction' => 'Employer Satisfaction'];
            $scales = [
                'trainer_quality' => ['label' => 'Trainer Quality', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq17' => 'Trainers had good knowledge and experience of the industry.',
                    'eq19' => 'Trainers were effective in their teaching.',
                    'eq21' => 'Trainers were able to relate material to the workplace.',
                ]],
                'effective_assessment' => ['label' => 'Effective Assessment', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq4'  => 'Assessment was at an appropriate standard.',
                    'eq15' => 'The training organisation gave appropriate recognition of existing knowledge and skills.',
                    'eq16' => 'The way employees were assessed was a fair test of their skills and knowledge.',
                    'eq18' => 'Assessments were based on realistic activities.',
                ]],
                'training_relevance' => ['label' => 'Training Relevance', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq6'  => 'The training reflected current industry practice.',
                    'eq9'  => 'The training focused on relevant skills.',
                    'eq11' => 'The training was effectively integrated into our organisation.',
                    'eq20' => 'The training was an effective investment.',
                    'eq22' => 'The training had a good mix of theory and practice.',
                    'eq27' => 'The training prepared employees well for work.',
                ]],
                'competency_development' => ['label' => 'Competency Development', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq10' => 'Our employees gained the skills they needed from this training.',
                    'eq24' => 'The training has helped our employees work with people.',
                    'eq26' => 'The training helped employees identify how to build on their current knowledge and skills.',
                    'eq28' => 'Our employees gained the knowledge they needed from this training.',
                    'eq29' => 'The training prepared our employees for the demands of work.',
                ]],
                'training_resources' => ['label' => 'Training Resources', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq1'  => 'The training used up-to-date equipment, facilities and materials.',
                    'eq5'  => 'The training resources were appropriate for learner needs.',
                    'eq25' => 'Training resources and equipment were in good condition.',
                ]],
                'effective_support' => ['label' => 'Effective Support', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq2'  => 'The training organisation dealt satisfactorily with any issues or complaints.',
                    'eq3'  => 'The training organisation was flexible enough to meet our needs.',
                    'eq7'  => 'The training organisation developed customised programs.',
                    'eq8'  => 'The training organisation provided good support for workplace training and assessment.',
                    'eq23' => 'The training organisation acted on feedback from employers.',
                    'eq30' => 'The training organisation clearly explained what was expected from employers.',
                ]],
                'overall_satisfaction' => ['label' => 'Overall Satisfaction', 'indicator' => 'employer_satisfaction', 'items' => [
                    'eq12' => 'Overall, we are satisfied with the training.',
                    'eq13' => 'We would recommend the training organisation to others.',
                    'eq14' => 'We would recommend the training to others.',
                ]],
            ];
            $overall = 'eq12';
        } else {
            $indicators = [
                'learner_engagement'     => 'Learner Engagement',
                'competency_development' => 'Competency Development',
            ];
            $scales = [
                'trainer_quality' => ['label' => 'Trainer Quality', 'indicator' => 'learner_engagement', 'items' => [
                    'lq1' => 'Trainers encouraged learners to ask questions.',
                    'lq2' => 'Trainers made the subject as interesting as possible.',
                    'lq3' => 'Trainers had an excellent knowledge of the subject content.',
                    'lq4' => 'Trainers explained things clearly.',
                ]],
                'effective_assessment' => ['label' => 'Effective Assessment', 'indicator' => 'learner_engagement', 'items' => [
                    'lq8'  => 'I received useful feedback on my assessments.',
                    'lq9'  => 'Assessments were based on realistic activities.',
                    'lq10' => 'The way I was assessed was a fair test of my skills and knowledge.',
                    'lq11' => 'The training organisation gave appropriate recognition of existing knowledge and skills.',
                ]],
                'clarity_of_expectations' => ['label' => 'Clarity of Expectations', 'indicator' => 'learner_engagement', 'items' => [
                    'lq12' => 'It was always easy to know the standards expected.',
                    'lq13' => 'I usually had a clear idea of what was expected of me.',
                    'lq14' => 'Trainers made it clear right from the start what they expected from me.',
                ]],
                'learning_stimulation' => ['label' => 'Learning Stimulation', 'indicator' => 'learner_engagement', 'items' => [
                    'lq15' => 'I was given enough material to keep up my interest.',
                    'lq16' => 'The amount of work I had to do was reasonable.',
                    'lq17' => 'The training was at the right level of difficulty for me.',
                ]],
                'training_relevance' => ['label' => 'Training Relevance', 'indicator' => 'learner_engagement', 'items' => [
                    'lq18' => 'The training focused on relevant skills.',
                    'lq19' => 'The training prepared me well for work.',
                    'lq20' => 'The training had a good mix of theory and practice.',
                ]],
                'competency_development' => ['label' => 'Competency Development', 'indicator' => 'competency_development', 'items' => [
                    'lq21' => 'I developed the skills expected from this training.',
                    'lq22' => 'I learned to work with people.',
                    'lq23' => 'I identified ways to build on my current knowledge and skills.',
                    'lq24' => 'I developed the knowledge expected from this training.',
                    'lq25' => 'I learned to plan and manage my work.',
                ]],
                'learning_resources' => ['label' => 'Learning Resources', 'indicator' => 'learner_engagement', 'items' => [
                    'lq26' => 'Training resources were available when I needed them.',
                    'lq27' => 'The training used up-to-date equipment, facilities and materials.',
                    'lq28' => 'Training facilities and materials were in good condition.',
                ]],
                'effective_support' => ['label' => 'Effective Support', 'indicator' => 'learner_engagement', 'items' => [
                    'lq29' => 'Training organisation staff respected my background and needs.',
                    'lq30' => 'The training was flexible enough to meet my needs.',
                    'lq31' => 'The training organisation had a range of services to support learners.',
                ]],
                'active_learning' => ['label' => 'Active Learning', 'indicator' => 'learner_engagement', 'items' => [
                    'lq32' => 'I set high standards for myself in this training.',
                    'lq33' => 'I pushed myself to understand things I found confusing.',
                    'lq34' => 'I looked for my own resources to help me learn.',
                    'lq35' => 'I approached trainers if I needed help.',
                ]],
                'overall_satisfaction' => ['label' => 'Overall Satisfaction', 'indicator' => 'learner_engagement', 'items' => [
                    'lq5' => 'Overall, I am satisfied with the training.',
                    'lq6' => 'I would recommend the training to others.',
                    'lq7' => 'I would recommend the training organisation to others.',
                ]],
            ];
            $overall = 'lq5';
        }
        return ['indicators' => $indicators, 'scales' => $scales, 'overall_item' => $overall];
    }
}
$bank = local_rtocompliance_qi_item_bank($survey->surveytype);

// Standard AQTF 4-point agreement scale.
$scalelabels = [1 => 'Strongly Disagree', 2 => 'Disagree', 3 => 'Agree', 4 => 'Strongly Agree'];

// Handle form submission.
$submitted = optional_param('submit', '', PARAM_ALPHA);
if ($submitted === 'yes' && confirm_sesskey()) {
    // Store one entry per item, keyed by item id, carrying its scale tag + value.
    $responses = [];
    foreach ($bank['scales'] as $scalekey => $scale) {
        foreach ($scale['items'] as $itemid => $itemtext) {
            $val = optional_param($itemid, 0, PARAM_INT);
            if ($val >= 1 && $val <= 4) {
                $responses[$itemid] = ['scale' => $scalekey, 'value' => $val];
            }
        }
    }
    // Overall satisfaction is taken from the questionnaire's overall item.
    $overallitem = $bank['overall_item'];
    $overallsatisfaction = isset($responses[$overallitem]) ? (int)$responses[$overallitem]['value'] : null;
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
    echo '<p>For each statement, please indicate how much you agree on the scale <strong>Strongly Disagree, Disagree, Agree, Strongly Agree</strong>.</p>';
    echo '</div>';
}

echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/survey_respond.php', ['token' => $token]))->out() . '" style="margin-top:1rem;">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="submit" value="yes">';

$qnum = 0;
foreach ($bank['scales'] as $scalekey => $scale) {
    echo '<h3 style="margin:1.5rem 0 0.5rem;color:#374151;font-size:1.05rem;">' . s($scale['label']) . '</h3>';
    foreach ($scale['items'] as $itemid => $itemtext) {
        $qnum++;
        echo '<div class="info-card" style="margin-bottom:1rem;">';
        echo '<p style="font-weight:600;margin-bottom:0.75rem;"><span style="color:#6b7280;">' . $qnum . '.</span> ' . s($itemtext) . '</p>';
        echo '<div style="display:flex;gap:0.5rem;flex-wrap:wrap;">';
        for ($i = 1; $i <= 4; $i++) {
            echo '<label style="display:flex;flex-direction:column;align-items:center;gap:0.25rem;cursor:pointer;padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:6px;min-width:110px;text-align:center;">';
            echo '<input type="radio" name="' . $itemid . '" value="' . $i . '" required style="margin:0;">';
            echo '<span style="font-size:0.8rem;color:#374151;font-weight:600;">' . s($scalelabels[$i]) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '</div>';
    }
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
