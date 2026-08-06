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
require_once(__DIR__ . '/classes/ai/survey_analyzer.php');

use local_rtocompliance\ai\survey_analyzer;

admin_externalpage_setup('local_rtocompliance_surveys');
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'learner', PARAM_ALPHA);
$year = optional_param('year', date('Y'), PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);

if (!in_array($type, ['learner', 'employer'])) {
    $type = 'learner';
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/ai_analysis.php', ['type' => $type, 'year' => $year]));
$PAGE->set_title('AI Survey Analysis');
$PAGE->navbar->add(get_string('surveys', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/surveys.php'));
$PAGE->navbar->add('AI Analysis');

$analyzer = new survey_analyzer();

// BUG-SURVEY-AI-NOSHOW (v4.2.29, 30 Apr 2026): the v4.2.25 flow was
// POST→analyze→insert→redirect-with-id→GET→fetch-by-id→render.  Server-side
// platform logs confirmed the analyze API was returning 200 OK with full
// JSON and credits were being deducted, but users still reported "Run AI
// Analysis does nothing" — root cause was the silent fall-through in
// survey_analyzer::analyze_survey_responses() at the inner DB insert: if the
// local_rtocompliance_ai_survey table is missing or has a column-type mismatch
// on the user's installation, the catch{} block swallows the error and returns
// analysis_id=0.  The redirect then carried ?id=0 and the "if ($id > 0)"
// rendering block never fired, so the page reloaded showing only the green
// "completed successfully" toast and an empty results area.  v4.2.29 fixed
// the analysis_id=0 case by setting $inlineanalysis but kept the redirect on
// the success-with-id path.
//
// BUG-SURVEY-AI-NOSHOW-2 (v4.2.35, 1 May 2026): a customer reported "just goes
// back to the screen after the button is clicked and after 30 seconds" with
// platform logs showing 5 successful POSTs to
// /api/rto/ai-survey-analyze (200 OK, credits deducted).  The
// redirect-on-success path was the culprit: any post-redirect failure mode
// (silent get_analysis() returning null because of theme-injected output
// before the redirect, session lock conflicts, browser not following the 303
// because the form was submitted via JS .form.submit() inside an onclick that
// returned false too late, or simply the green success toast being below the
// theme's nav bar) presented as "nothing happened".  v4.2.35 ELIMINATES the
// redirect entirely on success — the analysis result is ALWAYS rendered
// inline on the same request that produced it, regardless of whether DB
// persistence succeeded.  Credits are already spent; the user MUST see what
// they paid for, and the rendering must not depend on a second HTTP round
// trip that can fail in any number of theme/session/cookie ways.  Persistence
// becomes a side effect that only feeds the "Previous Analyses" table on
// subsequent page loads.
$inlineanalysis = null;
$inlinenotice   = null;

if ($action === 'analyze') {
    // BUG-SURVEY-AI-NOOP (v4.2.24): require_sesskey() instead of confirm_sesskey().
    // The previous "&& confirm_sesskey()" silently skipped the entire analyze
    // block when the sesskey didn't match (e.g. session changed since page load,
    // or POST-vs-GET sesskey mismatch on the new $OUTPUT->single_button form),
    // making the button appear to do nothing with NO error message at all.
    // require_sesskey() throws a clear "Invalid sesskey submitted" error page
    // instead, which is far better UX than the silent no-op.
    require_sesskey();

    if (!$analyzer->is_configured()) {
        redirect(
            $PAGE->url,
            'AI analysis is not configured. Please add your platform API key in plugin settings.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $periodstart = strtotime("$year-01-01");
    $periodend = strtotime("$year-12-31 23:59:59");

    $result = $analyzer->analyze_survey_responses($type, $periodstart, $periodend);

    if ($result['success']) {
        // BUG-SURVEY-AI-NOSHOW-2 (v4.2.35): always render the analysis blob
        // inline on this same request — never redirect on success.  The
        // analysis_id (if persisted) only feeds the Previous Analyses table.
        $analysisdata    = $result['analysis'] ?? [];
        $inlineanalysis  = (object) [
            'id'                => (int) ($result['analysis_id'] ?? 0),
            'status'            => 'completed',
            'overallsentiment'  => $analysisdata['sentiment'] ?? null,
            'satisfactionindex' => $analysisdata['satisfaction_index'] ?? null,
            'responsecount'     => (int) ($result['responses_analysed'] ?? 0),
            'timecompleted'     => time(),
            'trendsummary'      => $analysisdata['trend_summary'] ?? '',
            'strengths'         => json_encode($analysisdata['strengths'] ?? []),
            'improvements'      => json_encode($analysisdata['improvements'] ?? []),
            'recommendations'   => json_encode($analysisdata['recommendations'] ?? []),
            'keythemes'         => json_encode($analysisdata['themes'] ?? []),
            'fullanalysis'      => $analysisdata['full_report'] ?? '',
        ];
        if (empty($result['analysis_id'])) {
            $inlinenotice = 'AI analysis completed (results shown below). The analysis history could not be persisted on this installation — please ask an administrator to run the database upgrade so future analyses are saved to the Previous Analyses table.';
        }
    } else {
        // BUG-SURVEY-AI-MSG: show the underlying error message verbatim — the plugin's
        // call_platform_api now throws plain \Exception with a single-layer message
        // (e.g. "AI analysis failed (HTTP 500): OpenAI API key not configured") so we
        // no longer add a "Analysis failed:" prefix on top of an already-prefixed
        // string.  This is the same pattern as the v4.2.18 USI verification fix.
        $errmsg = trim((string) ($result['error'] ?? ''));
        if ($errmsg === '') {
            $errmsg = 'Analysis failed — no error message returned.';
        }
        redirect(
            $PAGE->url,
            $errmsg,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('AI Survey Analysis', get_string('surveys', 'local_rtocompliance'), '/local/rtocompliance/surveys.php', 'surveys');
echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'AI Survey Analysis');

// FIX-SURVEY-DROPDOWN: Use explicit 'selected' => 'selected' (or omit) instead of boolean true/false.
// Moodle's html_writer::attributes() converts PHP boolean false to the attribute name string
// ("selected") in some versions, causing ALL options to render as selected="selected".
// Using the ternary null pattern ensures only the matching option is marked selected.
$typeselect = html_writer::start_tag('select', ['onchange' => "window.location.href='?type='+this.value+'&year=$year'", 'class' => 'form-control rtoc-select-inline']);
$typeselect .= html_writer::tag('option', 'Learner Survey',    ['value' => 'learner',   'selected' => ($type === 'learner')   ? 'selected' : null]);
$typeselect .= html_writer::tag('option', 'Employer Survey',   ['value' => 'employer',  'selected' => ($type === 'employer')  ? 'selected' : null]);
$typeselect .= html_writer::end_tag('select');
echo $typeselect;

$years = range(date('Y'), date('Y') - 5);
$yearselect = html_writer::start_tag('select', ['onchange' => "window.location.href='?type=$type&year='+this.value", 'class' => 'form-control rtoc-select-inline']);
foreach ($years as $y) {
    $yearselect .= html_writer::tag('option', (string)$y, ['value' => $y, 'selected' => ($y == $year) ? 'selected' : null]);
}
$yearselect .= html_writer::end_tag('select');
echo $yearselect;
echo html_writer::end_div();

// BUG-SURVEY-AI FIX: Wrap count_records in try/catch — if the surveys table or year column
// is missing on an older installation the page would crash before showing any content.
try {
    $responsecount = $DB->count_records('local_rtocompliance_surveys', [
        'surveytype' => $type,
        'year' => $year,
        'status' => 'completed',
    ]);
} catch (\Throwable $ignored) {
    $responsecount = 0;
}

// RESULTS-FIRST (v4.4.6): Determine and render analysis results ABOVE the
// info/form cards so the user sees output immediately after the POST completes
// without having to scroll down past the form. No auto-scroll JS needed —
// the results appear at the top of the page content area.
$analysistorender = null;
if ($inlineanalysis !== null) {
    $analysistorender = $inlineanalysis;
    if ($inlinenotice !== null) {
        echo $OUTPUT->notification($inlinenotice, \core\output\notification::NOTIFY_WARNING);
    }
} else if ($id > 0) {
    $maybe = $analyzer->get_analysis($id);
    if ($maybe && $maybe->status === 'completed') {
        $analysistorender = $maybe;
    }
}

if ($analysistorender !== null) {
    $analysis = $analysistorender;

    echo html_writer::tag('div',
        '<strong style="display:block;font-size:18px;margin-bottom:4px;">'
        . 'AI analysis completed successfully'
        . '</strong>'
        . 'Your results are shown below — '
        . (int) $analysis->responsecount . ' '
        . s($type) . ' survey response'
        . ((int) $analysis->responsecount === 1 ? '' : 's')
        . ' analysed for '
        . (int) $year . '.',
        [
            'class' => 'alert alert-success',
            'style' => 'margin:16px 0;border-left:4px solid #22c55e;'
                     . 'background:#f0fdf4;color:#14532d;padding:16px 20px;'
                     . 'border-radius:6px;font-size:15px;',
        ]
    );

    echo html_writer::tag('h3', 'Analysis Results', ['class' => 'section-title']);
    echo html_writer::start_div('form-card');
    echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 16px;']);

    $sentimentcardcolor = 'blue';
    if ($analysis->overallsentiment === 'positive') {
        $sentimentcardcolor = 'green';
    } elseif ($analysis->overallsentiment === 'negative') {
        $sentimentcardcolor = 'rose';
    }

    echo html_writer::start_div('stat-card stat-' . $sentimentcardcolor);
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('activity') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', ucfirst($analysis->overallsentiment ?: 'N/A'), ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Overall Sentiment', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('stat-card stat-amber');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('star') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', round($analysis->satisfactionindex ?? 0) . '%', ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Satisfaction Index', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('stat-card stat-blue');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('bar') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', $analysis->responsecount ?: $responsecount, ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Responses Analysed', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('stat-card stat-purple');
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('calendar') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', userdate($analysis->timecompleted, '%d %b %Y'), ['class' => 'stat-number']);
    echo html_writer::tag('div', 'Analysis Date', ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // end stats-cards

    if (!empty($analysis->trendsummary)) {
        echo html_writer::tag('h4', 'Trend Summary', ['class' => 'rtoc-section-heading']);
        echo html_writer::tag('p', format_text($analysis->trendsummary, FORMAT_PLAIN));
    }

    $strengths = json_decode($analysis->strengths, true) ?: [];
    if (!empty($strengths)) {
        echo html_writer::tag('h4', 'Strengths Identified', ['class' => 'rtoc-strength-heading']);
        echo html_writer::start_tag('ul');
        foreach ($strengths as $s) {
            echo html_writer::tag('li', format_string($s));
        }
        echo html_writer::end_tag('ul');
    }

    $improvements = json_decode($analysis->improvements, true) ?: [];
    if (!empty($improvements)) {
        echo html_writer::tag('h4', 'Areas for Improvement', ['class' => 'rtoc-improvement-heading']);
        echo html_writer::start_tag('ul');
        foreach ($improvements as $imp) {
            echo html_writer::tag('li', format_string($imp));
        }
        echo html_writer::end_tag('ul');
    }

    $recommendations = json_decode($analysis->recommendations, true) ?: [];
    if (!empty($recommendations)) {
        echo html_writer::tag('h4', 'AI Recommendations', ['class' => 'rtoc-section-heading']);
        echo html_writer::start_tag('ol');
        foreach ($recommendations as $rec) {
            echo html_writer::tag('li', format_string($rec));
        }
        echo html_writer::end_tag('ol');
    }

    $themes = json_decode($analysis->keythemes, true) ?: [];
    if (!empty($themes)) {
        echo html_writer::tag('h4', 'Key Themes', ['class' => 'rtoc-section-heading']);
        echo html_writer::start_div('rtoc-flex-row');
        foreach ($themes as $theme) {
            echo html_writer::tag('span', format_string($theme), ['class' => 'rtoc-theme-badge']);
        }
        echo html_writer::end_div();
    }

    if (!empty($analysis->fullanalysis)) {
        echo html_writer::tag('h4', 'Full Analysis Report', ['class' => 'rtoc-section-heading']);
        echo html_writer::tag('div', format_text($analysis->fullanalysis, FORMAT_MARKDOWN), ['class' => 'rtoc-analysis-report']);
    }

    echo html_writer::end_div(); // end form-card results
}

if (!$analyzer->is_configured()) {
    echo html_writer::start_div('alert-card warning');
    echo html_writer::tag('h4', 'AI Analysis Not Configured');
    echo html_writer::tag('p', 'To use AI-powered survey analysis, please add your platform API key in the plugin settings. Each analysis costs ' . survey_analyzer::CREDIT_COST . ' platform credits.');
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
        'Configure Settings',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'AI-Powered Survey Insights — 5 Credits');
echo html_writer::tag('p',
    'Analyse your Quality Indicator survey responses using AI to generate actionable insights, identify themes, and track sentiment trends for continuous improvement. ' .
    'Each analysis uses <strong>' . survey_analyzer::CREDIT_COST . ' platform credits</strong> and is deducted automatically when you run the analysis.'
);
echo html_writer::end_div();

$creditcost = $analyzer->get_credit_cost();

echo html_writer::start_div('form-card');
echo html_writer::start_div('rtoc-form-card-header');

echo html_writer::start_div('');
echo html_writer::tag('h4', ucfirst($type) . ' Survey - ' . $year);
echo html_writer::tag('p', $responsecount . ' completed responses available for analysis', ['class' => 'text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('rtoc-form-card-actions');

// Credit cost badge — always visible so users know the cost before clicking.
echo html_writer::tag(
    'span',
    html_writer::tag('svg',
        '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>',
        ['xmlns' => 'http://www.w3.org/2000/svg', 'viewBox' => '0 0 24 24', 'width' => '14', 'height' => '14',
         'fill' => 'currentColor', 'style' => 'flex-shrink:0;']
    ) . ' ' . $creditcost . ' credits per analysis',
    ['class' => 'rtoc-credit-badge']
);

if ($analyzer->is_configured() && $responsecount > 0) {
    // BUG-SURVEY-AI-NOOP-2 (v4.2.25): the v4.2.24 fix used single_button() with
    // ->add_confirm_action(), which renders a Moodle AMD-based confirm modal
    // (core/notification:confirm).  On some Moodle 4.5+ installations that AMD
    // module fails to load silently (mismatched JS bundle, theme override, or
    // browser-blocked AMD chunk), so clicking the button produced NO modal AND
    // no form submission — the same "click does nothing" symptom the v4.2.24
    // fix was meant to eliminate.  Additionally, single_button($url,$lbl,'post',true)
    // emits a Moodle 4.5 debug warning ("$primary deprecated, use $type")
    // visible at the top of the page when debugging is on.
    //
    // This v4.2.25 rewrite renders a plain <form method="post"> with a hidden
    // sesskey input and a native <button type="submit"> styled as btn-primary.
    // No JavaScript dependency, no AMD module, no deprecated constructor —
    // works on Moodle 4.0 through 5.x identically.  The credit cost is already
    // shown in the badge above AND in the button label "— X Credits" so the
    // confirmation modal was redundant UX noise anyway.
    $formurl = new moodle_url('/local/rtocompliance/ai_analysis.php');
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $formurl->out(false),
        'style'  => 'display:inline;margin:0;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'analyze']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'type',    'value' => $type]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'year',    'value' => (string)$year]);
    // BUG-SURVEY-AI-NORESP-2 (v4.2.33): a customer's 1 May 2026 screenshot shows the
    // "Run AI Analysis — 5 Credits" button rendered correctly with 1 completed
    // employer survey response available — clicking did not produce any visual
    // feedback for the 15-60 second OpenAI round-trip (the platform endpoint
    // /api/rto/ai-survey-analyze hits OpenAI's gpt-4o-mini synchronously), so
    // to the user the button appeared dead.  Compounding this: a frustrated
    // double-click would fire a second POST while the first was still in
    // flight, which Moodle's session lock will queue (the second request waits
    // for the first to complete before its own session unlocks), making the
    // second redirect arrive even later than the first — feeling like nothing
    // happens for an even longer time.  Fix: native onclick handler that
    // disables the button immediately, swaps the label to a "please wait"
    // message, and submits the form via .form.submit() so the disabled button
    // is still "in" the form payload.  No AMD/JS module dependency to keep
    // this 100% Moodle-version-agnostic (same rationale as v4.2.25).
    echo html_writer::tag('button',
        'Run AI Analysis — ' . $creditcost . ' Credits',
        [
            'type'    => 'submit',
            'class'   => 'btn btn-primary',
            'onclick' => "if (this.dataset.busy) { return false; } this.dataset.busy='1'; "
                      .  "this.disabled = true; "
                      .  "this.innerHTML = '<span style=\"display:inline-block;vertical-align:middle;margin-right:8px;\">"
                      .  "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"animation:rtoc-spin 1s linear infinite;\">"
                      .  "<path d=\"M21 12a9 9 0 1 1-6.219-8.56\"></path></svg></span>"
                      .  "Analysing responses (please wait 30-60 seconds)...'; "
                      .  "this.form.submit(); return false;",
        ]
    );
    // Inject keyframes for the inline spinner once per page render.
    echo '<style>@keyframes rtoc-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}</style>';
    echo html_writer::end_tag('form');
} else {
    // BUG-SURVEY-AI-NORESP (v4.2.32): the previous disabled <span> rendered as a
    // small grey button next to the credit-cost badge — easy to miss, and the
    // exact UX symptom reported as "Run AI Analysis does nothing".  Promote
    // the empty-state to a full-width yellow alert so the reason is unmistakable.
    if (!$analyzer->is_configured()) {
        echo html_writer::tag('div',
            '<strong>AI analysis disabled —</strong> the platform API key is not configured. Ask your site administrator to add it under Site Administration → Plugins → Local plugins → RTO Compliance.',
            ['class' => 'alert alert-warning', 'style' => 'margin:0;width:100%;']
        );
    } else {
        $sendurl = (new moodle_url('/local/rtocompliance/survey_send.php', ['type' => $type]))->out(false);
        echo html_writer::tag('div',
            '<strong>No completed ' . s($type) . ' surveys yet for ' . (int)$year . '.</strong> '
            . 'AI analysis needs at least one completed survey response to work — '
            . 'send a survey first, wait for respondents to complete it, then return here. '
            . '<a href="' . $sendurl . '" class="btn btn-primary btn-sm" style="margin-left:12px;">Send ' . s($type) . ' survey →</a>',
            ['class' => 'alert alert-warning', 'style' => 'margin:0;width:100%;']
        );
    }
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// RESULTS-FIRST (v4.4.6): $analysistorender determined and rendered above, before
// the info/form cards, so no duplicate block is needed here.
// AI-ANALYSIS-PARSE-FIX (v5.9.301): orphaned echo html_writer::tag('div', with no
// arguments caused a PHP parse error, crashing the entire page on line 434.
$recentanalyses = $analyzer->get_recent_analyses($type, 5);

if ($recentanalyses) {
    echo html_writer::tag('h3', 'Previous Analyses', ['class' => 'section-title']);
    
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Date');
    echo html_writer::tag('th', 'Period');
    echo html_writer::tag('th', 'Responses');
    echo html_writer::tag('th', 'Sentiment');
    echo html_writer::tag('th', 'Satisfaction');
    echo html_writer::tag('th', '');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($recentanalyses as $a) {
        $sentimentbadge = 'badge-secondary';
        if ($a->overallsentiment === 'positive') {
            $sentimentbadge = 'badge-success';
        } elseif ($a->overallsentiment === 'negative') {
            $sentimentbadge = 'badge-danger';
        }
        
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($a->timecreated, '%d %b %Y'));
        echo html_writer::tag('td', date('Y', $a->periodstart));
        echo html_writer::tag('td', $a->responsecount);
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($a->overallsentiment ?: 'N/A'), ['class' => 'badge ' . $sentimentbadge]));
        echo html_writer::tag('td', round($a->satisfactionindex ?? 0) . '%');
        echo html_writer::tag('td', 
            html_writer::link(
                new moodle_url('/local/rtocompliance/ai_analysis.php', ['type' => $type, 'year' => date('Y', $a->periodstart), 'id' => $a->id]),
                'View',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::start_div('rtoc-action-row');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/surveys.php'),
    'Back to Surveys',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
