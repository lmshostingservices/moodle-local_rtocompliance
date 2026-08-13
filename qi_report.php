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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$year = optional_param('year', date('Y'), PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/qi_report.php', ['year' => $year]));
$PAGE->set_title('Quality Indicator Summary Report');
$PAGE->navbar->add(get_string('surveys', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/surveys.php'));
$PAGE->navbar->add('QI Summary Report');

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

$learnersat = $DB->get_field_sql(
    "SELECT AVG(overallsatisfaction) 
     FROM {local_rtocompliance_surveys} 
     WHERE surveytype = 'learner' AND year = ? AND status = 'completed' AND overallsatisfaction > 0",
    [$year]
);

$employersat = $DB->get_field_sql(
    "SELECT AVG(overallsatisfaction) 
     FROM {local_rtocompliance_surveys} 
     WHERE surveytype = 'employer' AND year = ? AND status = 'completed' AND overallsatisfaction > 0",
    [$year]
);

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
echo html_writer::tag('h4', 'ASQA/NCVER Quality Indicator Requirements');
echo html_writer::tag('p', 'RTOs must collect and report Quality Indicator data annually. This summary can be used for your Annual Declaration of Compliance and continuous improvement activities.');
echo html_writer::end_div();

echo html_writer::tag('h3', 'Learner Engagement Survey', ['class' => 'section-title']);

echo html_writer::start_div('form-card');
echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 16px;']);

echo html_writer::start_div('stat-card stat-blue');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('check') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $learnercount, ['class' => 'stat-number']);
echo html_writer::tag('div', 'Completed Responses', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-amber');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('star') . '</div>';
echo html_writer::start_div('stat-info');
$satdisplay = $learnersat ? round($learnersat, 1) . '/5' : 'N/A';
echo html_writer::tag('div', $satdisplay, ['class' => 'stat-number']);
echo html_writer::tag('div', 'Average Satisfaction', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

$learnerpending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner',
    'year' => $year,
    'status' => 'pending',
]);
$learnerrate = ($learnercount + $learnerpending) > 0 ? round(($learnercount / ($learnercount + $learnerpending)) * 100) : 0;

echo html_writer::start_div('stat-card stat-green');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('percent') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $learnerrate . '%', ['class' => 'stat-number']);
echo html_writer::tag('div', 'Response Rate', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('rtoc-card-actions');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'learner', 'year' => $year]),
    'View Detailed Responses',
    ['class' => 'btn btn-sm btn-secondary']
);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::tag('h3', 'Employer Satisfaction Survey', ['class' => 'section-title']);

echo html_writer::start_div('form-card');
echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 16px;']);

echo html_writer::start_div('stat-card stat-blue');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('check') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $employercount, ['class' => 'stat-number']);
echo html_writer::tag('div', 'Completed Responses', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-amber');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('star') . '</div>';
echo html_writer::start_div('stat-info');
$satdisplay = $employersat ? round($employersat, 1) . '/5' : 'N/A';
echo html_writer::tag('div', $satdisplay, ['class' => 'stat-number']);
echo html_writer::tag('div', 'Average Satisfaction', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

$employerpending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer',
    'year' => $year,
    'status' => 'pending',
]);
$employerrate = ($employercount + $employerpending) > 0 ? round(($employercount / ($employercount + $employerpending)) * 100) : 0;

echo html_writer::start_div('stat-card stat-green');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('percent') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $employerrate . '%', ['class' => 'stat-number']);
echo html_writer::tag('div', 'Response Rate', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('rtoc-card-actions');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'employer', 'year' => $year]),
    'View Detailed Responses',
    ['class' => 'btn btn-sm btn-secondary']
);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::tag('h3', 'Competency Completion Rate', ['class' => 'section-title']);

echo html_writer::start_div('form-card');

$yearstart = mktime(0, 0, 0, 1, 1, $year);
$yearend   = mktime(0, 0, 0, 1, 1, $year + 1);

$totalenrolments = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
     WHERE activitystartdate >= ? AND activitystartdate < ?",
    [$yearstart, $yearend]
);

$completions = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_enrolments}
     WHERE outcomeidentifier IN ('20', '51', '52', '53', '54', '60', '70', '79', '81')
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
