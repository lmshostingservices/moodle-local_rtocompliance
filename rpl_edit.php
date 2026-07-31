<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id  = optional_param('id', 0, PARAM_INT);
$tab = optional_param('tab', 'rpl', PARAM_ALPHA);

admin_externalpage_setup('local_rtocompliance_rpl');
$PAGE->set_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'tab' => $tab]);
$PAGE->set_title($id ? 'Edit RPL / Credit Transfer Record' : 'Add RPL / Credit Transfer Record');
$PAGE->set_heading($id ? 'Edit Record' : 'Add Record');

$dbman = $DB->get_manager();

$record = null;
if ($id && $dbman->table_exists('local_rtocompliance_rpl')) {
    $record = $DB->get_record('local_rtocompliance_rpl', ['id' => $id]);
    if (!$record) {
        redirect(new moodle_url('/local/rtocompliance/rpl.php'), 'Record not found.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $tab = $record->rpltype === 'credit_transfer' ? 'credit' : 'rpl';
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    // Bug I note: studentname is stored as free text with no foreign key to
    // local_rtocompliance_students or the Moodle user table. This means RPL
    // records cannot be reconciled with AVETMISS enrolments, USI records, or
    // certificates (a name typo silently orphans the record). A future schema
    // upgrade should add a nullable `userid` column and resolve the student via
    // a Moodle user selector rather than a plain text field.
    $data = new stdClass();
    $data->studentname        = required_param('studentname', PARAM_TEXT);
    $data->unitcode           = optional_param('unitcode', '', PARAM_TEXT);
    $data->unitname           = optional_param('unitname', '', PARAM_TEXT);
    $data->qualcode           = optional_param('qualcode', '', PARAM_TEXT);
    $data->qualname           = optional_param('qualname', '', PARAM_TEXT);
    $data->rpltype            = required_param('rpltype', PARAM_ALPHANUMEXT);
    $data->evidencedescription = optional_param('evidencedescription', '', PARAM_TEXT);
    $data->evidencefiles      = optional_param('evidencefiles', '', PARAM_TEXT);
    $data->assessorname       = optional_param('assessorname', '', PARAM_TEXT);
    $data->decision           = required_param('decision', PARAM_ALPHAEXT);
    $decdateraw               = optional_param('decisiondate', '', PARAM_TEXT);
    $data->decisiondate       = $decdateraw ? strtotime($decdateraw) : null;
    $data->decisionreason     = optional_param('decisionreason', '', PARAM_TEXT);
    $data->sourcequalcode     = optional_param('sourcequalcode', '', PARAM_TEXT);
    $data->sourcertoid        = optional_param('sourcertoid', '', PARAM_TEXT);
    $data->usitranscriptverified = optional_param('usitranscriptverified', 0, PARAM_INT);
    $data->timemodified       = time();

    if ($dbman->table_exists('local_rtocompliance_rpl')) {
        if ($id && $record) {
            $data->id = $id;
            $DB->update_record('local_rtocompliance_rpl', $data);
            local_rtocompliance_log_action('update', 'rpl', $id, ['student' => $data->studentname, 'decision' => $data->decision]);
            redirect(new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]), 'Record updated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            $data->timecreated = time();
            $DB->insert_record('local_rtocompliance_rpl', $data);
            local_rtocompliance_log_action('create', 'rpl', 0, ['student' => $data->studentname, 'type' => $data->rpltype]);
            redirect(new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]), 'Record saved successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    if ($dbman->table_exists('local_rtocompliance_rpl')) {
        $DB->delete_records('local_rtocompliance_rpl', ['id' => $id]);
        local_rtocompliance_log_action('delete', 'rpl', $id, []);
        redirect(new moodle_url('/local/rtocompliance/rpl.php'), 'Record deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$PAGE->add_body_class("path-local-rtocompliance");
$PAGE->requires->js('/local/rtocompliance/js/ai_suggest.js', false);
echo $OUTPUT->header();

// Use local_aiconfig (Central Config plugin) if installed — same priority chain as external.php.
$_rtoc_aicfglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($_rtoc_aicfglib)) {
    require_once($_rtoc_aicfglib);
}
$_rtoc_apikey = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
echo html_writer::tag('div', '', [
    'id'            => 'rtoc-ai-config',
    'data-api-key'  => $_rtoc_apikey,
    'data-api-base' => $_rtoc_apibase,
    'style'         => 'display:none',
    'aria-hidden'   => 'true',
]);

echo local_rtocompliance_render_nav_header('RPL & Credit Transfer');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', $id ? 'Edit RPL / Credit Transfer Record' : 'Add RPL / Credit Transfer Record');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]),
    'Back to Register',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

$formdata = $record ?: new stdClass();

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out_omit_querystring(),
    'style'  => 'max-width: 780px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);

$currentType = $record->rpltype ?? ($tab === 'credit' ? 'credit_transfer' : 'rpl');

echo html_writer::start_div('form-section');
echo html_writer::tag('h4', 'Application Type & Student', ['style' => 'margin-bottom: 16px; color: #374151;']);

$typeOptions = ['rpl' => 'RPL -- Recognition of Prior Learning (Standard 1.6)', 'credit_transfer' => 'Credit Transfer (Standard 1.7)'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Application Type *', ['for' => 'rpltype', 'class' => 'form-label']);
echo html_writer::select($typeOptions, 'rpltype', $currentType, null, ['id' => 'rpltype', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Student Name *', ['for' => 'studentname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'studentname', 'id' => 'studentname',
    'value' => s($formdata->studentname ?? ''),
    'class' => 'form-control', 'required' => 'required', 'placeholder' => 'Full name of student',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Unit / Qualification Details', ['style' => 'margin-bottom: 16px; color: #374151;']);

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 2fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Unit Code', ['for' => 'unitcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'unitcode', 'id' => 'unitcode',
    'value' => s($formdata->unitcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. BSBWHS411',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Unit Name', ['for' => 'unitname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'unitname', 'id' => 'unitname',
    'value' => s($formdata->unitname ?? ''),
    'class' => 'form-control', 'placeholder' => 'Unit of competency name',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 2fr; gap: 16px; margin-top: 12px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Qualification Code', ['for' => 'qualcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'qualcode', 'id' => 'qualcode',
    'value' => s($formdata->qualcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. BSB50120',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Qualification Name', ['for' => 'qualname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'qualname', 'id' => 'qualname',
    'value' => s($formdata->qualname ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. Diploma of Business',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['id' => 'credit-transfer-section', 'style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Credit Transfer Details (Standard 1.7)', ['style' => 'margin-bottom: 16px; color: #374151;']);

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Source Qualification Code', ['for' => 'sourcequalcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'sourcequalcode', 'id' => 'sourcequalcode',
    'value' => s($formdata->sourcequalcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'Code of previously held qualification',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Source RTO Identifier', ['for' => 'sourcertoid', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'sourcertoid', 'id' => 'sourcertoid',
    'value' => s($formdata->sourcertoid ?? ''),
    'class' => 'form-control', 'placeholder' => 'RTO code of issuing provider',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
$usiChecked = !empty($formdata->usitranscriptverified) ? ['checked' => 'checked'] : [];
echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']);
echo html_writer::empty_tag('input', array_merge([
    'type' => 'checkbox', 'name' => 'usitranscriptverified', 'id' => 'usitranscriptverified',
    'value' => '1', 'class' => 'form-check-input',
], $usiChecked));
echo html_writer::tag('label', 'USI Transcript Verified -- Clause 12 requirement: USI transcript sighted or verified before credit granted', [
    'for' => 'usitranscriptverified', 'class' => 'form-check-label',
    'style' => 'font-weight: 500; color: #374151;',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Evidence & Assessment', ['style' => 'margin-bottom: 16px; color: #374151;']);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Assessor Name', ['for' => 'assessorname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'assessorname', 'id' => 'assessorname',
    'value' => s($formdata->assessorname ?? ''),
    'class' => 'form-control', 'placeholder' => 'Name of trainer/assessor who evaluated this application',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Evidence Description', ['for' => 'evidencedescription', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->evidencedescription ?? ''), [
    'name' => 'evidencedescription', 'id' => 'evidencedescription',
    'class' => 'form-control', 'rows' => '4',
    'placeholder' => 'Describe the evidence provided: portfolio, work samples, references, statements, prior certificates, employer declarations, etc.',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Evidence Files / Documents', ['for' => 'evidencefiles', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->evidencefiles ?? ''), [
    'name' => 'evidencefiles', 'id' => 'evidencefiles',
    'class' => 'form-control', 'rows' => '3',
    'placeholder' => 'List filenames, document references, or external storage locations',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Decision', ['style' => 'margin-bottom: 16px; color: #374151;']);

$decisionOptions = [
    'pending'            => 'Pending -- Application under review',
    'approved'           => 'Approved -- RPL/credit granted in full',
    'partially_approved' => 'Partially Approved -- Some units granted',
    'not_approved'       => 'Not Approved -- Evidence insufficient',
];
echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Decision *', ['for' => 'decision', 'class' => 'form-label']);
echo html_writer::select($decisionOptions, 'decision', $formdata->decision ?? 'pending', null, ['id' => 'decision', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Decision Date', ['for' => 'decisiondate', 'class' => 'form-label']);
$decdateVal = !empty($formdata->decisiondate) ? date('Y-m-d', $formdata->decisiondate) : '';
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'decisiondate', 'id' => 'decisiondate',
    'value' => $decdateVal, 'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Decision Reason / Justification *', ['for' => 'decisionreason', 'class' => 'form-label']);
echo html_writer::tag('p', 'ASQA requires documented rationale for all RPL/credit transfer decisions.', ['class' => 'text-muted', 'style' => 'font-size: 0.875rem; margin-bottom: 6px;']);
echo html_writer::tag('textarea', s($formdata->decisionreason ?? ''), [
    'name' => 'decisionreason', 'id' => 'decisionreason',
    'class' => 'form-control', 'rows' => '5',
    'placeholder' => 'Provide written justification for the decision. For RPL: explain how evidence demonstrates competency. For credit transfer: describe equivalence mapping. For not approved: explain gap in evidence.',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px; align-items: center;']);
echo html_writer::tag('button', $id ? 'Update Record' : 'Save Record', [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]),
    'Cancel',
    ['class' => 'btn btn-secondary']
);
if ($id) {
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'action' => 'delete', 'sesskey' => sesskey()]),
        'Delete Record',
        ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete this record permanently?")']
    );
}
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::end_div();

echo html_writer::tag('script', '
document.addEventListener("DOMContentLoaded", function () {
    var typeSelect = document.getElementById("rpltype");
    var creditSection = document.getElementById("credit-transfer-section");
    function toggleCreditSection() {
        if (!typeSelect || !creditSection) return;
        if (typeSelect.value === "credit_transfer") {
            creditSection.style.display = "";
        } else {
            creditSection.style.display = "none";
        }
    }
    if (typeSelect) {
        typeSelect.addEventListener("change", toggleCreditSection);
        toggleCreditSection();
    }
});
');

echo $OUTPUT->footer();
