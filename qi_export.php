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
 * RTO Compliance plugin — qi_export.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// qi_export.php — Export the AQTF Quality Indicator Annual Summary as CSV.
//
// PRIVACY FIX (audit S-P1-2): this export previously dumped identifiable
// per-respondent rows (names + emails + individual answers). It now exports
// only the AGGREGATE QI summary — scale-level average scores per indicator,
// plus respondent counts and response rates — with no personal data.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$year = optional_param('year', date('Y'), PARAM_INT);

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
 * Robust to missing/invalid JSON and to zero responses (no divide-by-zero).
 */
if (!function_exists('local_rtocompliance_qi_compute')) {
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
                continue;
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
}

// Gather aggregate data per survey type (no identifiable rows are read).
$data = [];
foreach (['learner' => 'Learner Questionnaire (LQ)', 'employer' => 'Employer Questionnaire (EQ)'] as $type => $label) {
    $bank = local_rtocompliance_qi_item_bank($type);
    $completedrecs = $DB->get_records('local_rtocompliance_surveys', [
        'surveytype' => $type, 'year' => $year, 'status' => 'completed',
    ]);
    $stats = local_rtocompliance_qi_compute($completedrecs, $bank);
    $completed = count($completedrecs);
    $invited = $DB->count_records('local_rtocompliance_surveys', ['surveytype' => $type, 'year' => $year]);
    $rate = $invited > 0 ? round(($completed / $invited) * 100) : 0;
    $data[$type] = [
        'label' => $label, 'bank' => $bank, 'stats' => $stats,
        'completed' => $completed, 'invited' => $invited, 'rate' => $rate,
    ];
}

// Discard any buffered output before sending raw CSV headers.
while (ob_get_level()) {
    ob_end_clean();
}

$filename = 'qi_annual_summary_' . $year . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel.

$fmt = function ($v) {
    return $v === null ? '' : number_format($v, 2);
};

// Title / context rows.
fputcsv($out, ['AQTF Quality Indicator Annual Summary Report']);
fputcsv($out, ['Year', $year]);
fputcsv($out, ['Scale scale', '1 = Strongly Disagree .. 4 = Strongly Agree (aggregate averages only; no personal data)']);
fputcsv($out, []);

// Section 1: response summary.
fputcsv($out, ['Response Summary']);
fputcsv($out, ['Questionnaire', 'Completed Responses', 'Invited', 'Response Rate %']);
foreach ($data as $d) {
    fputcsv($out, [$d['label'], $d['completed'], $d['invited'], $d['rate'] . '%']);
}
fputcsv($out, []);

// Section 2: indicator + scale scores.
fputcsv($out, ['Quality Indicator Scores']);
fputcsv($out, ['Questionnaire', 'Quality Indicator', 'Scale', 'Average Score (1-4)', 'Items In Scale', 'Item Responses', 'Completed Respondents']);
foreach ($data as $d) {
    $bank = $d['bank'];
    $stats = $d['stats'];
    foreach ($bank['indicators'] as $ik => $ilabel) {
        $ind = $stats['indicators'][$ik];
        // Indicator overall row.
        fputcsv($out, [
            $d['label'],
            $ilabel,
            'INDICATOR OVERALL',
            $fmt($ind['avg']),
            '',
            '',
            $d['completed'],
        ]);
        // Constituent scale rows.
        foreach ($stats['scales'] as $sc) {
            if ($sc['indicator'] !== $ik) {
                continue;
            }
            fputcsv($out, [
                $d['label'],
                $ilabel,
                $sc['label'],
                $fmt($sc['avg']),
                $sc['items'],
                $sc['responses'],
                $d['completed'],
            ]);
        }
    }
}

fclose($out);
exit;
