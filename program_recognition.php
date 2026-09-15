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
 * Program recognition — which programs are nationally recognised training.
 *
 * Most codes classify themselves against the National Register. This page exists
 * for the ones the register does not hold, which are the RTO's own non-accredited
 * short courses — a handful, not everything.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\local\recognition;
use local_rtocompliance\local\register_lookup;

// admin_externalpage_setup() does require_login() and the page's own capability
// check, and puts the page in the Site administration tree. It is what every other
// page in this plugin uses; doing it by hand is how this page ended up outside the
// plugin's own layout.
admin_externalpage_setup('local_rtocompliance_program_recognition');

$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// THE UPGRADE WINDOW. The plugin's code is in place before the upgrade step that
// creates this table, so this page - and a form POST to it - is reachable before its
// table exists. Checked here rather than at render time, because an action must not
// be able to reach the table either: a posted lookup would otherwise throw.
$tableready = recognition::table_ready();

$PAGE->set_url(new moodle_url('/local/rtocompliance/program_recognition.php'));
$PAGE->set_title(get_string('recognition_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('recognition_title', 'local_rtocompliance'));

// The plugin's stylesheet is scoped to [class*="path-local-rtocompliance"], and
// admin_externalpage_setup() gives the body an admin-setting-* page type instead, so
// without this class the sidebar and every plugin style silently do not apply.
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

$returnurl = new moodle_url('/local/rtocompliance/program_recognition.php');

// ---------------------------------------------------------------- actions ----
if ($tableready && $action !== '' && confirm_sesskey()) {

    if ($action === 'lookup') {
        // THE NO-JAVASCRIPT PATH. With JavaScript on, the submit is intercepted by
        // js/recognition_progress.js and the work runs in small batches against
        // recognition_ajax.php with a progress bar.
        //
        // This path is CAPPED. It used to call classify_all() with no limit, which on
        // a site with 65 unclassified codes meant 65 sequential HTTP lookups inside
        // one page request - minutes of blank page, then usually a timeout, with the
        // operator unable to tell "working" from "dead". A capped run always comes
        // back and says how many are left, so the worst case is clicking again.
        // Safe to repeat: it only touches codes with no state, and a person's
        // decision is never overwritten.
        $found = register_lookup::discover_codes();
        $runstart = time();
        $counts = register_lookup::classify_batch($runstart, 10);
        $remaining = register_lookup::count_pending($runstart);

        $msg = get_string('recognition_lookupdone', 'local_rtocompliance', (object)[
            'checked'    => $counts['total'],
            'recognised' => $counts['found'],
            'notfound'   => $counts['notfound'],
            'errors'     => $counts['error'],
            'discovered' => $found,
        ]);
        if ($remaining > 0) {
            $msg .= ' ' . get_string('recognition_lookupmore', 'local_rtocompliance',
                $remaining);
        }
        redirect($returnurl, $msg,
            null, ($counts['error'] > 0 || $remaining > 0)
                ? \core\output\notification::NOTIFY_WARNING
                : \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'setstate') {
        // PARAM_TEXT rather than an unfiltered type: a qualification code needs no markup,
        // and the value is normalised by recognition::normalise_code() and only ever used
        // as a bound query parameter. Not PARAM_ALPHANUMEXT, because an RTO's own internal
        // codes follow no convention and must not be silently mangled - and a real code
        // like 22237VIC or a colon-bearing internal key has to survive intact.
        $code  = required_param('code', PARAM_TEXT);
        $state = required_param('state', PARAM_ALPHANUMEXT);
        $notes = optional_param('notes', '', PARAM_TEXT);
        if (recognition::set_manual($code, $state, $notes)) {
            redirect($returnurl,
                get_string('recognition_saved', 'local_rtocompliance',
                    recognition::normalise_code($code)),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($returnurl, get_string('recognition_savefailed', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

// ------------------------------------------------------------------ render ----
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('recognition_title', 'local_rtocompliance'));

if (!$tableready) {
    echo $OUTPUT->notification(
        get_string('recognition_notupgraded', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

$counts = recognition::count_by_state();
$unknown = $counts[recognition::STATE_UNKNOWN];

// What this page is for, in plain terms, because "unclassified" needs explaining.
echo html_writer::start_div('alert alert-info');
echo html_writer::tag('p', get_string('recognition_intro', 'local_rtocompliance'));
echo html_writer::tag('p', get_string('recognition_intro2', 'local_rtocompliance'),
    ['class' => 'mb-0']);
echo html_writer::end_div();

// Counts.
echo html_writer::start_div('row mb-3');
foreach ([
    [recognition::STATE_RECOGNISED,     $counts[recognition::STATE_RECOGNISED],
        'success',   'rtoc-count-recognised'],
    [recognition::STATE_NOT_RECOGNISED, $counts[recognition::STATE_NOT_RECOGNISED],
        'secondary', 'rtoc-count-notrecognised'],
    [recognition::STATE_UNKNOWN,        $unknown,
        $unknown > 0 ? 'warning' : 'success', 'rtoc-count-unknown'],
] as [$state, $n, $colour, $countid]) {
    echo html_writer::start_div('col-md-4');
    echo html_writer::start_div("card border-$colour mb-2");
    echo html_writer::start_div('card-body py-2');
    // The id lets the progress script update the number live, so the cards are not
    // stale while a run is going.
    echo html_writer::tag('h3', $n, ['class' => "mb-0 text-$colour", 'id' => $countid]);
    echo html_writer::tag('div',
        get_string('recognition_state_' . $state, 'local_rtocompliance'),
        ['class' => 'small text-muted']);
    echo html_writer::end_div() . html_writer::end_div() . html_writer::end_div();
}
echo html_writer::end_div();

// Run the register lookup.
//
// The form posts to this page and works with JavaScript off. With JavaScript on,
// js/recognition_progress.js takes over the submit and runs the same work in small
// batches against recognition_ajax.php, showing progress - because doing 65 lookups
// inside one request means minutes on a blank page and, often, a timeout.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $returnurl->out(false),
    'class' => 'mb-2', 'id' => 'rtoc-recognition-lookup-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
    'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
    'value' => 'lookup']);
echo html_writer::tag('button',
    get_string('recognition_runlookup', 'local_rtocompliance'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::tag('span', get_string('recognition_runlookup_help', 'local_rtocompliance'),
    ['class' => 'text-muted ml-2 small']);
echo html_writer::end_tag('form');

// Progress panel. Hidden until a run starts; the script unhides it.
echo html_writer::start_div('mb-4', ['id' => 'rtoc-recognition-progress', 'hidden' => 'hidden']);
echo html_writer::start_div('progress', ['style' => 'height:1.25rem']);
echo html_writer::div('', 'progress-bar progress-bar-striped progress-bar-animated', [
    'id' => 'rtoc-recognition-bar',
    'role' => 'progressbar',
    'style' => 'width:0%',
    'aria-valuenow' => '0',
    'aria-valuemin' => '0',
    'aria-valuemax' => '100',
]);
echo html_writer::end_div();
echo html_writer::div('', 'mt-2', ['id' => 'rtoc-recognition-label',
    'role' => 'status', 'aria-live' => 'polite']);
echo html_writer::div('', 'mt-2', ['id' => 'rtoc-recognition-log',
    'style' => 'max-height:12rem;overflow-y:auto']);
echo html_writer::end_div();

// Config for the progress script. Emitted here, after the elements it addresses, so
// the script can run immediately without waiting on a load event. Strings are passed
// through rather than hardcoded in the JS so the page stays translatable.
$progressstrings = [
    'starting'      => get_string('recognition_prog_starting', 'local_rtocompliance'),
    'progress'      => get_string('recognition_prog_progress', 'local_rtocompliance',
                           (object)['done' => '{$a->done}', 'total' => '{$a->total}']),
    'done'          => get_string('recognition_prog_done', 'local_rtocompliance',
                           (object)['checked' => '{$a->checked}',
                                    'recognised' => '{$a->recognised}',
                                    'notfound' => '{$a->notfound}',
                                    'errors' => '{$a->errors}']),
    'nothingtodo'   => get_string('recognition_prog_nothingtodo', 'local_rtocompliance'),
    'failed'        => get_string('recognition_prog_failed', 'local_rtocompliance',
                           (object)['error' => '{$a->error}']),
    'failed_resume' => get_string('recognition_prog_failed_resume', 'local_rtocompliance'),
    'badresponse'   => get_string('recognition_prog_badresponse', 'local_rtocompliance'),
    'reload'        => get_string('recognition_prog_reload', 'local_rtocompliance'),
    'result_found'    => get_string('recognition_register_found', 'local_rtocompliance'),
    'result_notfound' => get_string('recognition_register_notfound', 'local_rtocompliance'),
    'result_error'    => get_string('recognition_register_error', 'local_rtocompliance'),
];
echo html_writer::script('window.rtocRecognition = ' . json_encode([
    'endpoint' => (new moodle_url('/local/rtocompliance/recognition_ajax.php'))->out(false),
    'sesskey'  => sesskey(),
    'strings'  => $progressstrings,
], JSON_UNESCAPED_SLASHES) . ';');
echo html_writer::tag('script', '', ['src' =>
    (new moodle_url('/local/rtocompliance/js/recognition_progress.js',
        ['v' => get_config('local_rtocompliance', 'version')]))->out(false)]);

// The table. Unclassified first — that is the work.
$rows = $DB->get_records_sql(
    "SELECT r.*,
            (SELECT COUNT(DISTINCT e.studentid)
               FROM {local_rtocompliance_enrolments} e
              WHERE UPPER(TRIM(e.programcode)) = r.qualificationcode) AS students
       FROM {local_rtocompliance_recognition} r
      ORDER BY CASE r.state WHEN :unknown THEN 0 ELSE 1 END, r.qualificationcode",
    ['unknown' => recognition::STATE_UNKNOWN]);

if (!$rows) {
    echo $OUTPUT->notification(get_string('recognition_none', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('recognition_col_code', 'local_rtocompliance'),
    get_string('recognition_col_state', 'local_rtocompliance'),
    get_string('recognition_col_register', 'local_rtocompliance'),
    get_string('recognition_col_students', 'local_rtocompliance'),
    get_string('recognition_col_set', 'local_rtocompliance'),
];
$table->attributes['class'] = 'generaltable';

$badges = [
    recognition::STATE_RECOGNISED     => 'badge-success',
    recognition::STATE_NOT_RECOGNISED => 'badge-secondary',
    recognition::STATE_UNKNOWN        => 'badge-warning',
];

foreach ($rows as $row) {
    // What the register said, and when — so a stale or failed lookup is visible
    // rather than looking like a decision.
    $reg = '';
    if (!empty($row->registerresult) && $row->registerresult !== 'never') {
        $reg = get_string('recognition_register_' . $row->registerresult,
            'local_rtocompliance');
        if (!empty($row->registertitle)) {
            $reg .= html_writer::tag('div', s($row->registertitle),
                ['class' => 'small text-muted']);
        }
        if (!empty($row->registerchecked)) {
            $reg .= html_writer::tag('div',
                userdate($row->registerchecked, get_string('strftimedate', 'langconfig')),
                ['class' => 'small text-muted']);
        }
    } else {
        $reg = html_writer::tag('span',
            get_string('recognition_register_never', 'local_rtocompliance'),
            ['class' => 'text-muted small']);
    }

    $state = html_writer::tag('span',
        get_string('recognition_state_' . $row->state, 'local_rtocompliance'),
        ['class' => 'badge ' . ($badges[$row->state] ?? 'badge-light')]);
    if ($row->source === recognition::SOURCE_MANUAL) {
        $state .= html_writer::tag('div',
            get_string('recognition_setmanually', 'local_rtocompliance'),
            ['class' => 'small text-muted']);
    }
    if (!empty($row->notes)) {
        $state .= html_writer::tag('div', s($row->notes), ['class' => 'small text-muted']);
    }

    // Per-row set form. Three explicit choices, no default selected beyond the
    // current value — the operator says what is true, nothing is pre-guessed.
    $form = html_writer::start_tag('form', ['method' => 'post',
        'action' => $returnurl->out(false), 'class' => 'form-inline']);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
        'value' => sesskey()]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
        'value' => 'setstate']);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'code',
        'value' => $row->qualificationcode]);
    $opts = [];
    foreach (recognition::get_states() as $st) {
        $opts[$st] = get_string('recognition_state_' . $st, 'local_rtocompliance');
    }
    $form .= html_writer::select($opts, 'state', $row->state, false,
        ['class' => 'custom-select custom-select-sm mr-1']);
    $form .= html_writer::empty_tag('input', ['type' => 'text', 'name' => 'notes',
        'class' => 'form-control form-control-sm mr-1', 'style' => 'max-width:14rem',
        'placeholder' => get_string('recognition_notes_placeholder', 'local_rtocompliance'),
        'value' => '']);
    $form .= html_writer::tag('button', get_string('save'),
        ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary']);
    $form .= html_writer::end_tag('form');

    $table->data[] = [
        html_writer::tag('code', s($row->qualificationcode)),
        $state,
        $reg,
        (int)$row->students,
        $form,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
