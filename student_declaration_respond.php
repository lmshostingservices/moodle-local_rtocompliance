<?php
// student_declaration_respond.php — Public-facing form where a student completes their declaration.
// Accessed via a unique token link emailed by student_declaration_send.php.
// Intentionally does NOT call require_login() — the token itself authenticates the request,
// matching the pattern used by survey_respond.php and suitability checklist respond pages.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$token = required_param('token', PARAM_ALPHANUMEXT);

// Load the declaration record by token.
$dbman = $DB->get_manager();
if (!$dbman->table_exists('local_rtocompliance_declarations')) {
    throw new moodle_exception('invalidtoken', 'local_rtocompliance');
}

$declaration = $DB->get_record('local_rtocompliance_declarations', ['token' => $token]);
if (!$declaration) {
    throw new moodle_exception('invalidtoken', 'local_rtocompliance');
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/student_declaration_respond.php', ['token' => $token]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Student Declaration');
$PAGE->set_heading('Student Declaration');
$PAGE->set_pagelayout('base');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// The fixed checklist items
$declarationItems = [
    'The Student Handbook, including my rights and obligations',
    'Student behaviour expectations and code of conduct',
    'How to lodge complaints and appeals',
    'My responsibility to participate in training and assessment activities',
    'Requirements for providing accurate information and evidence',
    'My obligation to complete assessments honestly and without plagiarism',
    'Any support services and adjustments available to me',
];
$declarationFootnote = 'I understand that failure to meet these obligations may affect my enrolment, '
    . 'progress, or results. I acknowledge that this information was provided to me prior to or at '
    . 'enrolment and I have had the opportunity to ask questions.';

$student = null;
if (!empty($declaration->userid)) {
    $student = $DB->get_record('user', ['id' => $declaration->userid],
        'id,firstname,lastname,email,firstnamephonetic,lastnamephonetic,middlename,alternatename');
}

$already = $declaration->status === 'completed' && $declaration->agreed;

// ── Handle form POST ───────────────────────────────────────────────────────────
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {
    $fullname  = trim(optional_param('fullname',  '', PARAM_TEXT));
    $signature = trim(optional_param('signature', '', PARAM_TEXT));
    $agreed    = optional_param('agreed', 0, PARAM_INT);
    // All items must be checked
    $allItems = optional_param_array('items', [], PARAM_INT);

    if (empty($fullname)) {
        $errors[] = 'Please enter your full name.';
    }
    if (empty($signature)) {
        $errors[] = 'Please enter your signature (type your full name again).';
    }
    if (!$agreed) {
        $errors[] = 'You must tick the confirmation checkbox to proceed.';
    }
    if (count($allItems) < count($declarationItems)) {
        $errors[] = 'Please tick each item in the checklist to confirm you have read and understood it.';
    }

    if (empty($errors)) {
        $declaration->status       = 'completed';
        $declaration->fullname     = $fullname;
        $declaration->signature    = $signature;
        $declaration->agreed       = 1;
        $declaration->timecompleted = time();
        $DB->update_record('local_rtocompliance_declarations', $declaration);
        $success = true;
    }
}

echo $OUTPUT->header();

echo html_writer::start_div('compliance-container', ['style' => 'max-width:680px;margin:2rem auto;']);
echo html_writer::tag('h2', 'Student Declaration — Pre-Enrolment Obligations',
    ['style' => 'margin-bottom:1rem;']);

if ($success || $already) {
    echo html_writer::start_div('alert alert-success');
    echo html_writer::tag('strong', $already ? 'Declaration already completed.' : 'Thank you — declaration recorded!');
    echo html_writer::tag('p', 'Your declaration has been received and timestamped. No further action is needed.');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// Student greeting
if ($student) {
    echo html_writer::tag('p',
        'Dear <strong>' . htmlspecialchars(fullname($student)) . '</strong>,',
        ['style' => 'font-size:1.05rem;']
    );
}
echo html_writer::tag('p',
    'Please read each item below, tick each one individually to confirm you have read and understood it, '
    . 'then enter your full name and typed signature at the bottom.'
);

// Show errors
if (!empty($errors)) {
    echo html_writer::start_div('alert alert-danger');
    echo html_writer::tag('strong', 'Please fix the following:');
    echo html_writer::start_tag('ul');
    foreach ($errors as $e) {
        echo html_writer::tag('li', htmlspecialchars($e));
    }
    echo html_writer::end_tag('ul');
    echo html_writer::end_div();
}

echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'decl-form']);

// Declaration items — each must be ticked individually
echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'I confirm that I have read, understood, and agree to the following:');
echo html_writer::start_tag('ul', ['style' => 'list-style:none;padding:0;margin:0;']);
foreach ($declarationItems as $i => $item) {
    echo html_writer::start_tag('li', ['style' => 'display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;']);
    echo html_writer::empty_tag('input', [
        'type'     => 'checkbox',
        'name'     => 'items[]',
        'value'    => $i,
        'required' => 'required',
        'style'    => 'margin-top:3px;flex-shrink:0;width:18px;height:18px;',
        'class'    => 'decl-item-cb',
    ]);
    echo html_writer::tag('span', htmlspecialchars($item));
    echo html_writer::end_tag('li');
}
echo html_writer::end_tag('ul');

echo html_writer::tag('p',
    '<em>' . htmlspecialchars($declarationFootnote) . '</em>',
    ['style' => 'margin-top:1rem;color:#374151;font-size:0.9rem;']
);
echo html_writer::end_div();

// Name + signature + timestamp
echo html_writer::start_div('info-card', ['style' => 'margin-top:1rem;']);
echo html_writer::tag('h4', 'Acknowledgement');

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Full Name *', ['for' => 'fullname', 'style' => 'font-weight:600;']);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'id'          => 'fullname',
    'name'        => 'fullname',
    'class'       => 'form-control',
    'required'    => 'required',
    'placeholder' => 'Enter your legal full name',
    'value'       => s(optional_param('fullname', '', PARAM_TEXT)),
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top:0.75rem;']);
echo html_writer::tag('label', 'Typed Signature *', ['for' => 'signature', 'style' => 'font-weight:600;']);
echo html_writer::tag('small', ' (type your full name again as your electronic signature)', ['class' => 'text-muted']);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'id'          => 'signature',
    'name'        => 'signature',
    'class'       => 'form-control',
    'required'    => 'required',
    'placeholder' => 'Type your full name as your signature',
    'style'       => 'font-family:cursive;font-size:1.15rem;',
    'value'       => s(optional_param('signature', '', PARAM_TEXT)),
]);
echo html_writer::end_div();

echo html_writer::start_div('form-check', ['style' => 'margin-top:1rem;']);
echo html_writer::empty_tag('input', [
    'type'     => 'checkbox',
    'id'       => 'agreed',
    'name'     => 'agreed',
    'value'    => '1',
    'required' => 'required',
    'class'    => 'form-check-input',
]);
echo html_writer::tag('label',
    'I confirm that I have read, understood, and agree to all of the above obligations, '
    . 'and that the information I have provided is accurate.',
    ['for' => 'agreed', 'class' => 'form-check-label', 'style' => 'font-weight:600;']
);
echo html_writer::end_div();

echo html_writer::tag('p',
    '<em>Timestamp will be recorded as: ' . userdate(time(), '%d %B %Y %H:%M') . ' (server time)</em>',
    ['style' => 'margin-top:0.75rem;font-size:0.85rem;color:#6b7280;']
);

echo html_writer::tag('button', 'Submit Declaration',
    ['type' => 'submit', 'class' => 'btn btn-primary', 'style' => 'margin-top:1rem;', 'id' => 'submit-btn']);

echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();

?>
<script>
(function () {
    'use strict';
    var btn   = document.getElementById('submit-btn');
    var cbs   = document.querySelectorAll('.decl-item-cb');
    var total = cbs.length;

    function refresh() {
        var checked = document.querySelectorAll('.decl-item-cb:checked').length;
        if (btn) btn.disabled = (checked < total);
    }
    cbs.forEach(function (cb) { cb.addEventListener('change', refresh); });
    refresh();
})();
</script>
<?php
echo $OUTPUT->footer();
