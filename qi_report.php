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
 * RTO Compliance plugin — qi_report.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$year = optional_param('year', date('Y'), PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/qi_report.php', ['year' => $year]));
$PAGE->set_title('Quality Indicator Summary Report');
$PAGE->navbar->add(get_string('surveys', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/surveys.php'));
$PAGE->navbar->add('QI Summary Report');

// AQTF Quality Indicator questionnaire item bank (official ACER standard wording).
// Learner Questionnaire (LQ) and Employer Questionnaire (EQ), each item tagged to
// its diagnostic scale and to the reportable Quality Indicator. Answered on the
// standard AQTF 4-point agreement scale (1 = Strongly Disagree .. 4 = Strongly Agree).
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

/**
 * Compute AQTF QI scale- and indicator-level averages from completed survey records.
 *
 * Scale average  = mean of all item responses in the scale, pooled across respondents.
 * Indicator score = mean of that indicator's scale averages (equal weight per scale).
 * Robust to missing/invalid JSON and to zero responses (no divide-by-zero).
 *
 * @param array $surveys completed survey records with a `responses` JSON column
 * @param array $bank    output of local_rtocompliance_qi_item_bank()
 * @return array [ 'respondents' => int,
 *                 'scales'      => [ scalekey => ['label','indicator','avg'|null,'items','responses'] ],
 *                 'indicators'  => [ indicatorkey => ['label','avg'|null,'scalecount'] ] ]
 */
function local_rtocompliance_qi_compute($surveys, $bank) {
    $scalestats = [];
    foreach ($bank['scales'] as $sk => $scale) {
        $scalestats[$sk] = ['sum' => 0, 'count' => 0];
    }
    $respondents = 0;
    foreach ($surveys as $s) {
        if (empty($s->responses)) {
            continue;
        }
        $decoded = json_decode($s->responses, true);
        if (!is_array($decoded)) {
            continue; // Guard against malformed JSON.
        }
        $respondents++;
        foreach ($decoded as $itemid => $ans) {
            if (!is_array($ans) || !isset($ans['value'])) {
                continue;
            }
            $v = (int)$ans['value'];
            if ($v < 1 || $v > 4) {
                continue;
            }
            // Prefer the stored scale tag; fall back to the bank if absent.
            $sk = isset($ans['scale']) ? $ans['scale'] : null;
            if ($sk === null || !isset($scalestats[$sk])) {
                $sk = null;
                foreach ($bank['scales'] as $k => $scale) {
                    if (isset($scale['items'][$itemid])) {
                        $sk = $k;
                        break;
                    }
                }
            }
            if ($sk !== null && isset($scalestats[$sk])) {
                $scalestats[$sk]['sum'] += $v;
                $scalestats[$sk]['count']++;
            }
        }
    }

    $scales = [];
    foreach ($bank['scales'] as $sk => $scale) {
        $count = $scalestats[$sk]['count'];
        $scales[$sk] = [
            'label'     => $scale['label'],
            'indicator' => $scale['indicator'],
            'avg'       => $count > 0 ? $scalestats[$sk]['sum'] / $count : null,
            'items'     => count($scale['items']),
            'responses' => $count,
        ];
    }

    $indicators = [];
    foreach ($bank['indicators'] as $ik => $ilabel) {
        $sum = 0.0;
        $n = 0;
        foreach ($scales as $sc) {
            if ($sc['indicator'] === $ik && $sc['avg'] !== null) {
                $sum += $sc['avg'];
                $n++;
            }
        }
        $indicators[$ik] = [
            'label'      => $ilabel,
            'avg'        => $n > 0 ? $sum / $n : null,
            'scalecount' => $n,
        ];
    }

    return ['respondents' => $respondents, 'scales' => $scales, 'indicators' => $indicators];
}

$learnercount = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner',
    'year' => $year,
    'status' => 'completed',
]);

$employercount = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer',
    'year' => $year,
    'status' => 'completed',
]);

$learnersurveys = $DB->get_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner', 'year' => $year, 'status' => 'completed',
]);
$employersurveys = $DB->get_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer', 'year' => $year, 'status' => 'completed',
]);

$learnerbank   = local_rtocompliance_qi_item_bank('learner');
$employerbank  = local_rtocompliance_qi_item_bank('employer');
$learnerstats  = local_rtocompliance_qi_compute($learnersurveys, $learnerbank);
$employerstats = local_rtocompliance_qi_compute($employersurveys, $employerbank);

// Response-rate denominators: all invited surveys for the year (any status).
$learnertotal  = $DB->count_records('local_rtocompliance_surveys', ['surveytype' => 'learner',  'year' => $year]);
$employertotal = $DB->count_records('local_rtocompliance_surveys', ['surveytype' => 'employer', 'year' => $year]);
$learnerrate   = $learnertotal  > 0 ? round(($learnercount  / $learnertotal)  * 100) : 0;
$employerrate  = $employertotal > 0 ? round(($employercount / $employertotal) * 100) : 0;

/**
 * Render one Quality Indicator block: the indicator score(s) plus the
 * scale-level average scores that make it up.
 */
function local_rtocompliance_qi_render_indicators($bank, $stats, $completed, $total, $rate) {
    $fmt = function ($v) {
        return $v === null ? 'N/A' : number_format($v, 2) . ' / 4';
    };

    echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 16px;']);

    echo html_writer::start_div('stat-card stat-blue');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('check') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', $completed, ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Completed Responses', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('stat-card stat-amber');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('star') . '</div>';
    echo html_writer::start_div('stat-info');
    // Headline: first indicator score (Learner Engagement / Employer Satisfaction).
    $first = reset($stats['indicators']);
    echo html_writer::tag('div', $first ? $fmt($first['avg']) : 'N/A', ['class' => 'stat-number']);
    echo html_writer::tag('div', $first ? $first['label'] . ' (avg)' : 'Indicator', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('stat-card stat-green');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('percent') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', $rate . '%', ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Response Rate (' . $completed . '/' . $total . ' invited)', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div();

    // Per-indicator scale tables.
    foreach ($bank['indicators'] as $ik => $ilabel) {
        $ind = $stats['indicators'][$ik];
        echo html_writer::tag('h4',
            $ilabel . ' — indicator score: ' . $fmt($ind['avg']),
            ['style' => 'margin:1rem 0 0.5rem;']);

        $rows = '';
        foreach ($stats['scales'] as $sc) {
            if ($sc['indicator'] !== $ik) {
                continue;
            }
            $rows .= html_writer::start_tag('tr');
            $rows .= html_writer::tag('td', s($sc['label']));
            $rows .= html_writer::tag('td', $fmt($sc['avg']), ['style' => 'text-align:center;font-weight:600;']);
            $rows .= html_writer::tag('td', (int)$sc['items'], ['style' => 'text-align:center;']);
            $rows .= html_writer::tag('td', (int)$sc['responses'], ['style' => 'text-align:center;']);
            $rows .= html_writer::end_tag('tr');
        }
        $head = html_writer::start_tag('tr')
            . html_writer::tag('th', 'Scale', ['title' => 'AQTF diagnostic scale that makes up this Quality Indicator'])
            . html_writer::tag('th', 'Average (1-4)', ['style' => 'text-align:center;', 'title' => 'Mean of all item responses in this scale, on the 1 to 4 agreement scale'])
            . html_writer::tag('th', 'Items', ['style' => 'text-align:center;', 'title' => 'Number of questionnaire items in this scale'])
            . html_writer::tag('th', 'Item Responses', ['style' => 'text-align:center;', 'title' => 'Total item responses pooled across all respondents for this scale'])
            . html_writer::end_tag('tr');
        echo html_writer::tag('table',
            html_writer::tag('thead', $head) . html_writer::tag('tbody', $rows),
            ['class' => 'generaltable', 'style' => 'width:100%;margin-bottom:0.5rem;']);
    }
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('QI Summary Report', get_string('surveys', 'local_rtocompliance'), '/local/rtocompliance/surveys.php', 'surveys');
echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Quality Indicator Summary Report - ' . $year);

$years = range(date('Y'), date('Y') - 5);
$yearselect = html_writer::start_tag('select', ['onchange' => "window.location.href='?year='+this.value", 'class' => 'form-control', 'style' => 'width: auto; display: inline-block;']);
foreach ($years as $y) {
    $yearselect .= html_writer::tag('option', $y, ['value' => $y, 'selected' => ($y == $year)]);
}
$yearselect .= html_writer::end_tag('select');
echo $yearselect;
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'AQTF Quality Indicator Annual Summary Report');
echo html_writer::tag('p', 'Under the AQTF Quality Indicators framework, RTOs must collect the standard Learner Questionnaire (LQ) and Employer Questionnaire (EQ) and report the Quality Indicators of Learner Engagement, Competency Development and Employer Satisfaction annually. Scores below are on the standard 4-point agreement scale (1 = Strongly Disagree, 4 = Strongly Agree). Scale scores are the mean of the items in each scale; each indicator score is the mean of its scale scores. This summary supports your Annual Declaration of Compliance and continuous improvement activities.');
echo html_writer::end_div();

// ── Learner Questionnaire (LQ) → Learner Engagement + Competency Development ──
echo html_writer::tag('h3', 'Learner Questionnaire (LQ)', ['class' => 'section-title']);
echo html_writer::start_div('form-card');
if ($learnercount > 0) {
    local_rtocompliance_qi_render_indicators($learnerbank, $learnerstats, $learnercount, $learnertotal, $learnerrate);
} else {
    echo html_writer::tag('p', 'No completed learner questionnaires for ' . $year . ' yet. Response rate: '
        . $learnerrate . '% (' . $learnercount . ' of ' . $learnertotal . ' invited).',
        ['class' => 'text-muted']);
}
echo html_writer::start_div('rtoc-card-actions');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'learner', 'year' => $year]),
    'View Detailed Responses',
    ['class' => 'btn btn-sm btn-secondary', 'title' => 'View individual completed learner questionnaire responses for this year']
);
echo html_writer::end_div();
echo html_writer::end_div();

// ── Employer Questionnaire (EQ) → Employer Satisfaction ──────────────────────
echo html_writer::tag('h3', 'Employer Questionnaire (EQ)', ['class' => 'section-title']);
echo html_writer::start_div('form-card');
if ($employercount > 0) {
    local_rtocompliance_qi_render_indicators($employerbank, $employerstats, $employercount, $employertotal, $employerrate);
} else {
    echo html_writer::tag('p', 'No completed employer questionnaires for ' . $year . ' yet. Response rate: '
        . $employerrate . '% (' . $employercount . ' of ' . $employertotal . ' invited).',
        ['class' => 'text-muted']);
}
echo html_writer::start_div('rtoc-card-actions');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'employer', 'year' => $year]),
    'View Detailed Responses',
    ['class' => 'btn btn-sm btn-secondary', 'title' => 'View individual completed employer questionnaire responses for this year']
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::tag('h3', 'Competency Completion Rate', ['class' => 'section-title']);

echo html_writer::start_div('form-card');

$yearstart = mktime(0, 0, 0, 1, 1, $year);
$yearend   = mktime(0, 0, 0, 1, 1, $year + 1);

// QI-COMPLETION-RATE-FIX (v5.9.415): the competency completion rate was computed
// as (competions by activity END date) / (enrolments by activity START date) — two
// different cohorts, so the ratio wasn't a true rate — and the "completion" set
// wrongly included 52 (RPL NOT granted), 70 (continuing) and other non-competent
// codes, inflating it. It is now "of the units that reached a FINAL outcome this
// year, what fraction were competent": both numerator and denominator key on
// activityenddate within the year, the denominator counts every terminal outcome,
// and the numerator counts only the competent ones (20/51/60/81).
$terminalset = "('20','30','40','41','51','52','60','61','81','82')";
$totalenrolments = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
     WHERE outcomeidentifier IN $terminalset
       AND activityenddate >= ? AND activityenddate < ?",
    [$yearstart, $yearend]
);

$completions = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
     WHERE outcomeidentifier IN ('20', '51', '60', '81')
       AND activityenddate >= ? AND activityenddate < ?",
    [$yearstart, $yearend]
);

$completionrate = $totalenrolments > 0 ? round(($completions / $totalenrolments) * 100, 1) : 0;

echo html_writer::start_div('rtoc-inner-stat-grid');

echo html_writer::start_div('stat-card');
echo html_writer::tag('div', $totalenrolments, ['class' => 'stat-value']);
echo html_writer::tag('div', 'Total Enrolments', ['class' => 'stat-label']);
echo html_writer::end_div();

echo html_writer::start_div('stat-card');
echo html_writer::tag('div', $completions, ['class' => 'stat-value']);
echo html_writer::tag('div', 'Competencies Achieved', ['class' => 'stat-label']);
echo html_writer::end_div();

echo html_writer::start_div('stat-card');
echo html_writer::tag('div', $completionrate . '%', ['class' => 'stat-value']);
echo html_writer::tag('div', 'Completion Rate', ['class' => 'stat-label']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('rtoc-action-row');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/surveys.php'),
    'Back to Surveys',
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qi_export.php', ['year' => $year]),
    'Export QI Report (CSV)',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
