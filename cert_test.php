<?php
// CERT-OF-COMPLETION + TEST-CERT (v4.2.41) — test certificate generator.
//
// Lets an admin pick any of the four certificate types and generate a
// sample PDF on demand using either the active approved template (if one
// exists for that type) or the built-in default layout (legacy renderer).
// No data is persisted — purely for layout/QA verification.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/cert_template_renderer.php');

use local_rtocompliance\cert_template;
use local_rtocompliance\cert_template_renderer;

require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managecerttemplates', $context);

$generate    = optional_param('generate', 0, PARAM_INT);
$certtype    = optional_param('certtype', '', PARAM_ALPHA);
$studentname = optional_param('studentname', '', PARAM_TEXT);
$orientation = optional_param('orientation', '', PARAM_ALPHA);
// Sanitise orientation — only 'P' or 'L' are valid; anything else means auto.
if ($orientation !== 'P' && $orientation !== 'L') {
    $orientation = '';
}

$validtypes = local_rtocompliance_get_certificate_types();

// ---------------------------------------------------------------------------
// PDF generation branch — stream the PDF and exit.
// ---------------------------------------------------------------------------
if ($generate && isset($validtypes[$certtype])) {
    require_sesskey();

    $studentname = trim($studentname);
    if ($studentname === '') {
        $studentname = 'Jane Citizen';
    }

    // Build a synthetic $cert + $user pair so we can route through the same
    // pipeline that real issuance uses.  This guarantees the test reflects
    // exactly what students will get.
    global $USER;

    $parts = preg_split('/\s+/', $studentname, 2);
    $firstname = $parts[0] ?? 'Jane';
    $lastname  = $parts[1] ?? 'Citizen';

    $sampleuser = (object) [
        'id'        => $USER->id,
        'firstname' => $firstname,
        'lastname'  => $lastname,
        'email'     => $USER->email ?? 'test@example.com',
    ];

    $issuets = time();
    $samplecert = (object) [
        'id'                => 0,
        'certtype'          => $certtype,
        'certnumber'        => strtoupper(substr($certtype, 0, 4)) . '-TEST-' . date('YmdHis'),
        'qualificationcode' => $certtype === 'completion' ? '' : 'BSB30120',
        'qualificationname' => $certtype === 'completion'
            ? 'Workplace First Aid (Non-Accredited)'
            : 'Certificate III in Business',
        'unitsofcompetency' => '',
        'coursetitle'       => $certtype === 'completion'
            ? 'Workplace First Aid (Non-Accredited)'
            : '',
        'units'             => json_encode([
            ['code' => 'BSBCMM311', 'name' => 'Apply critical thinking skills in a team environment'],
            ['code' => 'BSBCRT311', 'name' => 'Apply critical thinking skills'],
            ['code' => 'BSBPEF301', 'name' => 'Organise personal work priorities'],
        ]),
        'issuedate'         => $issuets,
        'timeissued'        => $issuets,
        'timecompleted'     => $issuets - (3 * DAYSECS),
    ];

    $pdfdata = local_rtocompliance_render_certificate_pdf_string($samplecert, $sampleuser, $orientation);

    $filename = 'test-' . $certtype . '-' . date('Ymd-His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdfdata));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $pdfdata;
    exit;
}

// ---------------------------------------------------------------------------
// Form rendering branch.
// ---------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/local/rtocompliance/cert_test.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('cert_test_pagetitle', 'local_rtocompliance'));
$PAGE->set_heading(get_string('cert_test_heading', 'local_rtocompliance'));

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Test Certificate', 'Certificate Templates', new moodle_url('/local/rtocompliance/cert_templates.php'));
echo $OUTPUT->heading(get_string('cert_test_heading', 'local_rtocompliance'));
echo html_writer::tag('p', get_string('cert_test_intro', 'local_rtocompliance'));

// Build per-type status summary so the admin sees which types have an
// active template versus which will fall back to the default layout.
$statussummary = [];
foreach ($validtypes as $key => $label) {
    $active = cert_template::get_active_template($key);
    $statussummary[$key] = $active
        ? get_string('cert_test_active_template', 'local_rtocompliance', format_string($active->name))
        : get_string('cert_test_no_active_template', 'local_rtocompliance');
}

$actionurl = new moodle_url('/local/rtocompliance/cert_test.php');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $actionurl->out(false),
    'target' => '_blank',
    'class'  => 'mt-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'generate', 'value' => 1]);
// NRT-ORIENT-OVERRIDE (v4.4.7): carry the chosen orientation into the POST so the
// generate branch can force portrait or landscape regardless of cert-type default.

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_orientation_label', 'local_rtocompliance'),
    'cert_test_orientation',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::start_tag('select', [
    'name'  => 'orientation',
    'id'    => 'cert_test_orientation',
    'class' => 'form-select',
    'style' => 'max-width: 480px;',
]);
echo html_writer::tag('option', get_string('cert_test_orientation_auto', 'local_rtocompliance'),      ['value' => '']);
echo html_writer::tag('option', get_string('cert_test_orientation_portrait', 'local_rtocompliance'), ['value' => 'P']);
echo html_writer::tag('option', get_string('cert_test_orientation_landscape', 'local_rtocompliance'),['value' => 'L']);
echo html_writer::end_tag('select');
echo html_writer::tag('p',
    get_string('cert_test_orientation_hint', 'local_rtocompliance'),
    ['class' => 'form-text text-muted mt-1']
);
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_certtype_label', 'local_rtocompliance'),
    'cert_test_certtype',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::start_tag('select', [
    'name'  => 'certtype',
    'id'    => 'cert_test_certtype',
    'class' => 'form-select',
    'style' => 'max-width: 480px;',
    'required' => 'required',
]);
foreach ($validtypes as $key => $label) {
    echo html_writer::tag('option', s($label . ' — ' . $statussummary[$key]), ['value' => $key]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_studentname_label', 'local_rtocompliance'),
    'cert_test_studentname',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'name'        => 'studentname',
    'id'          => 'cert_test_studentname',
    'class'       => 'form-control',
    'style'       => 'max-width: 480px;',
    'placeholder' => get_string('cert_test_studentname_placeholder', 'local_rtocompliance'),
    'maxlength'   => 100,
]);
echo html_writer::end_div();

echo html_writer::tag('button',
    get_string('cert_test_generate', 'local_rtocompliance'),
    ['type' => 'submit', 'class' => 'btn btn-primary']
);

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
