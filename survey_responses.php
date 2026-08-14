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
 * RTO Compliance plugin — survey_responses.php.
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

$type = required_param('type', PARAM_ALPHA);
$year = optional_param('year', date('Y'), PARAM_INT);

if (!in_array($type, ['learner', 'employer'])) {
    throw new moodle_exception('invalidsurveytype', 'local_rtocompliance');
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => $type, 'year' => $year]));
$PAGE->set_title(ucfirst($type) . ' Survey Responses');
$PAGE->navbar->add(get_string('surveys', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/surveys.php'));
$PAGE->navbar->add(ucfirst($type) . ' Responses');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(ucfirst($type) . ' Survey Responses', get_string('surveys', 'local_rtocompliance'), '/local/rtocompliance/surveys.php', 'surveys');
echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', ucfirst($type) . ' Survey Responses - ' . $year);

$years = range(date('Y'), date('Y') - 5);
$yearselect = html_writer::start_tag('select', ['onchange' => "window.location.href='?type=$type&year='+this.value", 'class' => 'form-control', 'style' => 'width: auto; display: inline-block;']);
foreach ($years as $y) {
    $selected = ($y == $year) ? 'selected' : '';
    $yearselect .= html_writer::tag('option', $y, ['value' => $y, 'selected' => ($y == $year)]);
}
$yearselect .= html_writer::end_tag('select');
echo $yearselect;
echo html_writer::end_div();

$completed = $DB->get_records('local_rtocompliance_surveys', [
    'surveytype' => $type,
    'year' => $year,
    'status' => 'completed',
], 'timecompleted DESC');

// RESPONSE-RATE-FIX (v5.9.381): "outstanding" is every invited survey that is
// not yet completed — regardless of whether its status is 'sent' (manual send),
// 'pending' (auto-task) or anything else. Counting only 'pending' made the rate
// read ~100% for manually-sent batches. Denominator = all invited for type+year.
$totalsurveys   = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => $type,
    'year' => $year,
]);
$totalresponses = count($completed);
$pending        = max(0, $totalsurveys - $totalresponses);
$responserate   = $totalsurveys > 0 ? round(($totalresponses / $totalsurveys) * 100) : 0;

$avgsatisfaction = 0;
if ($completed) {
    $totalsat = 0;
    $satcount = 0;
    foreach ($completed as $survey) {
        if (!empty($survey->overallsatisfaction)) {
            $totalsat += $survey->overallsatisfaction;
            $satcount++;
        }
    }
    if ($satcount > 0) {
        $avgsatisfaction = round($totalsat / $satcount, 1);
    }
}

echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 24px;']);
foreach ([
    ['label' => 'Completed Responses', 'value' => $totalresponses,        'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('check')],
    ['label' => 'Pending',             'value' => $pending,               'color' => $pending > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Response Rate',       'value' => $responserate . '%',    'color' => 'green',  'icon' => local_rtocompliance_stat_icon('percent')],
    ['label' => 'Avg Satisfaction',    'value' => $avgsatisfaction . '/5','color' => 'amber',  'icon' => local_rtocompliance_stat_icon('star')],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'], ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($completed) {
    echo html_writer::tag('h3', 'Individual Responses', ['class' => 'section-title']);

    echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;"><div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">Understanding this table</div><div style="font-size:14.5px;color:#334155;line-height:1.55;">Each row is one completed survey response for the selected year. Satisfaction shows the overall rating the respondent gave on a 1 to 5 scale, colour-coded green for high, amber for moderate and red for low. Use the year selector above to view other periods.</div></div>';

    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Respondent', ['title' => 'Name of the person who completed the survey (Anonymous if not supplied)']);
    echo html_writer::tag('th', 'Email', ['title' => 'Email address of the respondent, if provided']);
    echo html_writer::tag('th', 'Satisfaction', ['title' => 'Overall satisfaction rating on a 1 to 5 scale']);
    echo html_writer::tag('th', 'Completed', ['title' => 'Date the respondent completed the survey']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($completed as $survey) {
        $satclass = 'badge-success';
        if ($survey->overallsatisfaction <= 2) {
            $satclass = 'badge-danger';
        } elseif ($survey->overallsatisfaction <= 3) {
            $satclass = 'badge-warning';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', format_string($survey->respondentname) ?: 'Anonymous');
        echo html_writer::tag('td', $survey->respondentemail ?: '-');
        echo html_writer::tag('td', 
            html_writer::tag('span', $survey->overallsatisfaction . '/5', ['class' => 'badge ' . $satclass])
        );
        echo html_writer::tag('td', userdate($survey->timecompleted, '%d %b %Y'));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/report', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Responses Yet');
    echo html_writer::tag('p', 'Survey responses for ' . $year . ' will appear here once respondents complete the survey.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/survey_send.php', ['type' => $type]),
        'Send Survey Invitations',
        ['class' => 'btn btn-primary', 'title' => 'Send survey invitation emails to learners or employers']
    );
    echo html_writer::end_div();
}

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/surveys.php'),
    'Back to Surveys',
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/ai_analysis.php', ['type' => $type, 'year' => $year]),
    'Analyse with AI',
    ['class' => 'btn btn-primary', 'style' => 'background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none;', 'title' => 'Run AI analysis on these survey responses']
);
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
