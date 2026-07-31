<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
require_capability('local/rtocompliance:managesurveys', context_system::instance());
$PAGE->set_title(get_string('surveys', 'local_rtocompliance'));
$PAGE->set_heading(get_string('surveys', 'local_rtocompliance'));

/**
 * BUG-SURVEY-AI-2CLICK (v4.2.34): one-click POST form helper that submits
 * directly to ai_analysis.php?action=analyze with sesskey + type + year, so
 * the user no longer has to click through to a confirmation page first.  A
 * JS confirm() dialog guards the 5-credit spend, and the same spinner UX
 * as the ai_analysis.php submit button (v4.2.33 BUG-SURVEY-AI-NORESP-2)
 * runs during the 30-60s OpenAI round-trip so the button never feels dead.
 *
 * @param string $type   'learner' or 'employer'
 * @param int    $year   Survey period year (e.g. 2026)
 * @param int    $count  Number of completed responses available for analysis
 */
function rtoc_render_run_analysis_form($type, $year, $count) {
    $confirmmsg = "Run AI analysis on {$count} completed " . ($type === 'employer' ? 'employer' : 'learner')
                . " survey response" . ($count === 1 ? '' : 's') . " for {$year}?\\n\\n"
                . "This will deduct 5 platform credits from your balance and may take 30-60 seconds.";
    // Spinner SVG + label swap — kept inline (no AMD dependency) to match the
    // ai_analysis.php submit button onclick handler (v4.2.33).
    $spinnerjs = "if (this.dataset.busy) { return false; } "
              .  "if (!confirm(" . json_encode($confirmmsg) . ")) { return false; } "
              .  "this.dataset.busy='1'; this.disabled = true; "
              .  "this.innerHTML = '<span style=\"display:inline-block;vertical-align:middle;margin-right:8px;\">"
              .  "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"animation:rtoc-spin 1s linear infinite;\">"
              .  "<path d=\"M21 12a9 9 0 1 1-6.219-8.56\"></path></svg></span>"
              .  "Analysing responses (please wait 30-60 seconds)...'; "
              .  "this.form.submit(); return false;";
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new \moodle_url('/local/rtocompliance/ai_analysis.php'))->out(false),
        'style'  => 'display:inline;margin:0;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'analyze']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'type',    'value' => $type]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'year',    'value' => (string)$year]);
    echo html_writer::tag('button',
        'Run AI Analysis (' . (int)$count . ' response' . ($count === 1 ? '' : 's') . ')',
        [
            'type'    => 'submit',
            'class'   => 'btn btn-primary',
            'title'   => 'Runs the AI analysis directly. You will be asked to confirm the 5-credit cost first.',
            'onclick' => $spinnerjs,
        ]
    );
    echo html_writer::end_tag('form');
    // Inject keyframes once per page render — duplicate-safe, browsers ignore
    // identical @keyframes blocks.
    static $styleinjected = false;
    if (!$styleinjected) {
        echo '<style>@keyframes rtoc-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}</style>';
        $styleinjected = true;
    }
}

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('surveys', 'local_rtocompliance'), null, null, 'surveys');

echo html_writer::start_div('surveys-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('surveys', 'local_rtocompliance'));
echo html_writer::end_div();
echo html_writer::tag('p', 'Quality Indicator surveys are required annually by ASQA/NCVER. Send these surveys to learners and employers to collect satisfaction data.', ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']);

$currentyear = date('Y');

$learnercount = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner',
    'year' => $currentyear,
    'status' => 'completed',
]);

$employercount = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer',
    'year' => $currentyear,
    'status' => 'completed',
]);

$learnerpending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner',
    'year' => $currentyear,
    'status' => 'sent',
]);

$employerpending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer',
    'year' => $currentyear,
    'status' => 'sent',
]);

$learnertotal  = $DB->count_records('local_rtocompliance_surveys', ['surveytype' => 'learner',  'year' => $currentyear]);
$employertotal = $DB->count_records('local_rtocompliance_surveys', ['surveytype' => 'employer', 'year' => $currentyear]);
$learnerrate   = ($learnertotal  > 0) ? round($learnercount  / $learnertotal  * 100) : 0;
$employerrate  = ($employertotal > 0) ? round($employercount / $employertotal * 100) : 0;
$alltimecount  = $DB->count_records('local_rtocompliance_surveys', ['status' => 'completed']);

// ── Quick Statistics cards ────────────────────────────────────────────────────
$icoSend   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
$icoCheck  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
$icoBuild  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>';
$icoClock  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
$icoBar    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>';
$icoAward  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>';

echo html_writer::start_div('stats-cards');
foreach ([
    ['label' => 'Learner Surveys Sent ' . $currentyear,  'value' => $learnertotal,                      'color' => 'blue',                                                  'icon' => $icoSend],
    ['label' => 'Learner Responses Received',             'value' => $learnercount,                      'color' => 'green',                                                 'icon' => $icoCheck],
    ['label' => 'Employer Surveys Sent ' . $currentyear, 'value' => $employertotal,                     'color' => 'purple',                                                'icon' => $icoBuild],
    ['label' => 'Employer Responses Received',            'value' => $employercount,                     'color' => 'green',                                                 'icon' => $icoCheck],
    ['label' => 'Awaiting Response',                      'value' => $learnerpending + $employerpending, 'color' => ($learnerpending + $employerpending) > 0 ? 'amber' : 'green', 'icon' => $icoClock],
    ['label' => 'Learner Response Rate',                  'value' => $learnerrate . '%',                 'color' => $learnerrate  >= 50 ? 'green' : 'amber',                 'icon' => $icoBar],
    ['label' => 'Employer Response Rate',                 'value' => $employerrate . '%',                'color' => $employerrate >= 50 ? 'green' : 'amber',                 'icon' => $icoBar],
    ['label' => 'All Time Completions',                   'value' => $alltimecount,                      'color' => 'blue',                                                  'icon' => $icoAward],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'],  ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('survey-type-card');
echo html_writer::tag('h3', get_string('survey_learner', 'local_rtocompliance'));
echo html_writer::tag('p', 'Collect feedback from students about their training experience, including quality of training, assessment, and support services.');

echo html_writer::start_div('rtoc-flex-row');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_send.php', ['type' => 'learner']),
    get_string('survey_send', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'learner']),
    get_string('survey_responses', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary']
);
// BUG-SURVEY-AI FIX: Add per-type Run AI Analysis buttons inside each survey card so
// users can navigate directly to the analysis page for the correct survey type.
// BUG-SURVEY-BTN-PURPLE: removed inline indigo→purple gradient — the button now uses
// the standard btn-primary blue so it matches Send Survey on the same row.
// BUG-SURVEY-AI-NORESP (v4.2.32): when there are zero completed responses for the
// year, the button on ai_analysis.php silently renders as a disabled grey "No
// responses to analyse" span — to a manager who clicks the surveys.php link
// without first checking that surveys have been sent AND completed, this looks
// exactly like "Run AI Analysis does nothing".  Pre-check responsecount here
// and render a tooltip-rich disabled button when responsecount === 0 so the
// reason is obvious without needing to navigate.
// BUG-SURVEY-AI-2CLICK (v4.2.34, 1 May 2026): a customer's screenshot showed the per-card
// "Run AI Analysis (N responses)" buttons just navigate to ai_analysis.php where
// she has to click ANOTHER "Run AI Analysis — 5 Credits" button to actually run
// it.  Two clicks for one action — the per-card buttons looked like they should
// run the analysis themselves.  Fix: turn the per-card button into a real POST
// form that goes straight to action=analyze with sesskey+type+year, with a
// JS confirm() guarding the 5-credit spend, and the same spinner UX as the
// ai_analysis.php submit button so she sees the 30-60 second OpenAI round-trip
// is in progress.  The standalone "AI Survey Analysis" button at the bottom
// of the page is left as a plain navigation link because it's a generic entry
// point that lets her switch survey type / year before running.
if ($learnercount > 0) {
    rtoc_render_run_analysis_form('learner', $currentyear, $learnercount);
} else {
    echo html_writer::tag('span',
        'AI Analysis — no responses yet',
        ['class' => 'btn btn-secondary disabled', 'title' => 'Send the learner survey and wait for at least one completed response before running AI analysis.', 'aria-disabled' => 'true']
    );
}
echo html_writer::end_div();

echo html_writer::start_div('survey-stats');
echo html_writer::start_div('survey-stat');
echo html_writer::tag('div', $learnercount, ['class' => 'survey-stat-value']);
echo html_writer::tag('div', 'Responses ' . $currentyear, ['class' => 'survey-stat-label']);
echo html_writer::end_div();

$pending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'learner',
    'year' => $currentyear,
    'status' => 'pending',
]);
echo html_writer::start_div('survey-stat');
echo html_writer::tag('div', $pending, ['class' => 'survey-stat-value']);
echo html_writer::tag('div', 'Pending', ['class' => 'survey-stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('survey-type-card');
echo html_writer::tag('h3', get_string('survey_employer', 'local_rtocompliance'));
echo html_writer::tag('p', 'Collect feedback from employers about the quality of training, competency of graduates, and relevance to workplace needs.');

echo html_writer::start_div('rtoc-flex-row');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_send.php', ['type' => 'employer']),
    get_string('survey_send', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/survey_responses.php', ['type' => 'employer']),
    get_string('survey_responses', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary']
);
// BUG-SURVEY-AI FIX: Per-type Run AI Analysis button for employer surveys.
// BUG-SURVEY-BTN-PURPLE: removed inline indigo→purple gradient — see learner button above.
// BUG-SURVEY-AI-NORESP (v4.2.32): same disabled-state pre-check as learner — see comment above.
// BUG-SURVEY-AI-2CLICK (v4.2.34): one-click POST form, see learner comment above.
if ($employercount > 0) {
    rtoc_render_run_analysis_form('employer', $currentyear, $employercount);
} else {
    echo html_writer::tag('span',
        'AI Analysis — no responses yet',
        ['class' => 'btn btn-secondary disabled', 'title' => 'Send the employer survey and wait for at least one completed response before running AI analysis.', 'aria-disabled' => 'true']
    );
}
echo html_writer::end_div();

echo html_writer::start_div('survey-stats');
echo html_writer::start_div('survey-stat');
echo html_writer::tag('div', $employercount, ['class' => 'survey-stat-value']);
echo html_writer::tag('div', 'Responses ' . $currentyear, ['class' => 'survey-stat-label']);
echo html_writer::end_div();

$pending = $DB->count_records('local_rtocompliance_surveys', [
    'surveytype' => 'employer',
    'year' => $currentyear,
    'status' => 'pending',
]);
echo html_writer::start_div('survey-stat');
echo html_writer::tag('div', $pending, ['class' => 'survey-stat-value']);
echo html_writer::tag('div', 'Pending', ['class' => 'survey-stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('rtoc-action-row');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qi_report.php'),
    get_string('survey_summary', 'local_rtocompliance'),
    ['class' => 'btn btn-outline-primary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/ai_analysis.php'),
    'AI Survey Analysis',
    ['class' => 'btn btn-primary', 'style' => 'background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border: none;']
);
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
