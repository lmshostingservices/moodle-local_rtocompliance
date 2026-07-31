<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
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

$pending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => $type,
    'year' => $year,
    'status' => 'pending',
]);

$totalresponses = count($completed);
$responserate = ($totalresponses + $pending) > 0 ? round(($totalresponses / ($totalresponses + $pending)) * 100) : 0;

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

    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Respondent');
    echo html_writer::tag('th', 'Email');
    echo html_writer::tag('th', 'Satisfaction');
    echo html_writer::tag('th', 'Completed');
    echo html_writer::tag('th', 'Actions');
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
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/survey_view.php', ['id' => $survey->id]),
                'View Details',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
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
        ['class' => 'btn btn-primary']
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
    new moodle_url('/local/rtocompliance/survey_export.php', ['type' => $type, 'year' => $year]),
    'Export to CSV',
    ['class' => 'btn btn-outline-primary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/ai_analysis.php', ['type' => $type, 'year' => $year]),
    'Analyse with AI',
    ['class' => 'btn btn-primary', 'style' => 'background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none;']
);
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
