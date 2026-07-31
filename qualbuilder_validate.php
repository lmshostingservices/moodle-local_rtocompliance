<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/packagingrules_validator.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\packagingrules_validator;
use local_rtocompliance\audit_logger;

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_rtocompliance_qualbuilder');
$context = context_system::instance();

// Bug S fix: this page writes DB state (validationpassed/date/errors) on every page load.
// Without sesskey validation an attacker could silently overwrite validation results
// for any qualification product by tricking an admin into visiting a crafted URL.
require_sesskey();

$product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $id], '*', MUST_EXIST);
$units = $DB->get_records('local_rtocompliance_qualunits', ['qualbuilderid' => $id, 'selected' => 1], 'unittype ASC, sequenceorder ASC');

$PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_validate.php', ['id' => $id]));
$PAGE->set_title(get_string('check_packaging', 'local_rtocompliance'));
$PAGE->set_heading(get_string('check_packaging', 'local_rtocompliance') . ': ' . $product->qualificationcode);

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add($product->qualificationcode, new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]));
$PAGE->navbar->add(get_string('check_packaging', 'local_rtocompliance'));


$result = packagingrules_validator::validate($product, $units);

$now = time();
$DB->set_field('local_rtocompliance_qualbuilder', 'validationpassed', $result['passed'] ? 1 : 0, ['id' => $id]);
$DB->set_field('local_rtocompliance_qualbuilder', 'validationdate', $now, ['id' => $id]);
$DB->set_field('local_rtocompliance_qualbuilder', 'validationerrors', json_encode($result['errors']), ['id' => $id]);
$DB->set_field('local_rtocompliance_qualbuilder', 'timemodified', $now, ['id' => $id]);

audit_logger::log_update(
    'qualbuilder',
    $id,
    'Packaging rules validation: ' . ($result['passed'] ? 'PASSED' : 'FAILED'),
    null,
    ['validation_passed' => $result['passed'], 'checks' => $result['checks']]
);

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('check_packaging', 'local_rtocompliance'), get_string('qualificationbuilder', 'local_rtocompliance'), '/local/rtocompliance/qualbuilder.php', 'qualbuilder');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('packaging_validation_results', 'local_rtocompliance'));
echo html_writer::end_div();
echo html_writer::tag('p', $product->qualificationcode . ' - ' . $product->qualificationname, ['class' => 'text-muted', 'style' => 'margin-bottom: 1.5rem;']);

// Source badge: is the data fresh from TGA or stored?
$tgaSourced = false;
foreach ($result['warnings'] as $w) {
    if (strpos($w, 'training.gov.au') !== false) {
        $tgaSourced = true;
        break;
    }
}
$sourcebadge = $tgaSourced
    ? '<span style="background:#e0f2fe;color:#0369a1;border-radius:4px;padding:2px 10px;font-size:0.85em;margin-left:10px;">Rules sourced live from TGA</span>'
    : '<span style="background:#fef9c3;color:#92400e;border-radius:4px;padding:2px 10px;font-size:0.85em;margin-left:10px;">Using stored rules (TGA unavailable)</span>';

if ($result['passed']) {
    echo html_writer::div(
        html_writer::tag('h3', '&#10003; ' . get_string('packaging_compliant', 'local_rtocompliance') . $sourcebadge),
        'alert alert-success'
    );
} else {
    echo html_writer::div(
        html_writer::tag('h3', '&#10007; ' . get_string('packaging_noncompliant', 'local_rtocompliance') . $sourcebadge),
        'alert alert-danger'
    );
}

echo html_writer::tag('h4', get_string('validation_checks', 'local_rtocompliance'), ['style' => 'margin-top: 30px;']);

$table = new html_table();
$table->head = [
    get_string('check', 'local_rtocompliance'),
    get_string('expected', 'local_rtocompliance'),
    get_string('actual', 'local_rtocompliance'),
    get_string('status'),
];
$table->attributes['class'] = 'generaltable validation-table';

foreach ($result['checks'] as $check) {
    $statusicon = $check['passed'] ? 
        html_writer::tag('span', '&#10003;', ['class' => 'text-success', 'style' => 'font-size: 1.2em;']) :
        html_writer::tag('span', '&#10007;', ['class' => 'text-danger', 'style' => 'font-size: 1.2em;']);
    
    $table->data[] = [
        $check['name'],
        $check['expected'],
        $check['actual'],
        $statusicon,
    ];
}

echo html_writer::table($table);

if (!empty($result['errors'])) {
    echo html_writer::tag('h4', get_string('validation_errors', 'local_rtocompliance'), ['style' => 'margin-top: 30px;']);
    echo html_writer::start_div('alert alert-warning');
    echo html_writer::alist($result['errors']);
    echo html_writer::end_div();
}

// Filter out source-annotation notes — they are already shown in the banner badge.
$displayWarnings = array_values(array_filter($result['warnings'], function($w) {
    return strpos($w, 'training.gov.au') === false
        && strpos($w, 'Could not connect to TGA API') === false;
}));
if (!empty($displayWarnings)) {
    echo html_writer::tag('h4', get_string('warnings', 'local_rtocompliance'), ['style' => 'margin-top: 20px;']);
    echo html_writer::start_div('alert alert-info');
    echo html_writer::alist($displayWarnings);
    echo html_writer::end_div();
}
// Show API unavailable notice as a distinct amber box so it stands out.
$tgaUnavailable = false;
foreach ($result['warnings'] as $w) {
    if (strpos($w, 'Could not connect to TGA API') !== false) {
        $tgaUnavailable = true;
        break;
    }
}
if ($tgaUnavailable) {
    echo html_writer::div(
        '&#9888; Could not reach training.gov.au — packaging rules are based on stored values and may not reflect the latest TGA requirements. ' .
        'Click <em>Check Packaging Rules</em> again when connectivity is restored for an authoritative validation.',
        'alert alert-warning', ['style' => 'margin-top: 16px;']
    );
}

echo html_writer::start_div('', ['style' => 'margin-top: 30px;']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]),
    get_string('back_to_product', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
