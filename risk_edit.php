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
 * RTO Compliance plugin — risk_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id       = optional_param('id', 0, PARAM_INT);
$category = optional_param('category', 'operational', PARAM_TEXT);
$allowed_categories = ['operational', 'financial', 'conflict_of_interest', 'under18', 'reputational', 'safety', 'compliance', 'strategic'];
$category = in_array($category, $allowed_categories, true) ? $category : 'operational';

admin_externalpage_setup('local_rtocompliance_risk');
require_login();
$PAGE->set_url('/local/rtocompliance/risk_edit.php', ['id' => $id]);
$PAGE->set_title($id ? 'Edit Risk' : 'Add Risk');
$PAGE->set_heading($id ? 'Edit Risk' : 'Add Risk');

$dbman = $DB->get_manager();

$record = null;
if ($id && $dbman->table_exists('local_rtocompliance_risks')) {
    $record = $DB->get_record('local_rtocompliance_risks', ['id' => $id]);
    if (!$record) {
        redirect(new moodle_url('/local/rtocompliance/risk.php'), 'Risk record not found.', null, \core\output\notification::NOTIFY_ERROR);
    }
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->risktitle        = required_param('risktitle', PARAM_TEXT);
    $data->riskcategory     = required_param('riskcategory', PARAM_TEXT);
    $data->riskcategory     = in_array($data->riskcategory, ['operational', 'financial', 'conflict_of_interest', 'under18', 'reputational', 'safety', 'compliance', 'strategic'], true) ? $data->riskcategory : 'operational';
    $data->riskdescription  = optional_param('riskdescription', '', PARAM_TEXT);
    $data->likelihood       = required_param('likelihood', PARAM_INT);
    $data->impact           = required_param('impact', PARAM_INT);
    $data->riskowner        = optional_param('riskowner', '', PARAM_TEXT);
    $data->mitigationplan   = optional_param('mitigationplan', '', PARAM_TEXT);
    $revdateraw             = optional_param('reviewdate', '', PARAM_TEXT);
    $data->reviewdate       = $revdateraw ? strtotime($revdateraw) : null;
    $data->status           = required_param('status', PARAM_ALPHA);
    $data->notes            = optional_param('notes', '', PARAM_TEXT);
    $data->timemodified     = time();

    $data->likelihood = max(1, min(5, (int)$data->likelihood));
    $data->impact     = max(1, min(5, (int)$data->impact));

    if ($dbman->table_exists('local_rtocompliance_risks')) {
        if ($id && $record) {
            $data->id = $id;
            $DB->update_record('local_rtocompliance_risks', $data);
            local_rtocompliance_log_action('update', 'governance', $id, ['risk' => $data->risktitle]);
            redirect(new moodle_url('/local/rtocompliance/risk.php'), 'Risk updated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            $data->timecreated = time();
            $DB->insert_record('local_rtocompliance_risks', $data);
            local_rtocompliance_log_action('create', 'governance', 0, ['risk' => $data->risktitle, 'category' => $data->riskcategory]);
            redirect(new moodle_url('/local/rtocompliance/risk.php'), 'Risk added successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    if ($dbman->table_exists('local_rtocompliance_risks')) {
        $DB->delete_records('local_rtocompliance_risks', ['id' => $id]);
        local_rtocompliance_log_action('delete', 'governance', $id, []);
        redirect(new moodle_url('/local/rtocompliance/risk.php'), 'Risk deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
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

echo local_rtocompliance_render_nav_header('Risk Management');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', $id ? 'Edit Risk Record' : 'Add Risk to Register');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/risk.php'),
    'Back to Risk Register',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

$formdata = $record ?: new stdClass();
if (!$id) {
    $formdata->riskcategory = $category;
    $formdata->likelihood   = 3;
    $formdata->impact       = 3;
    $formdata->status       = 'open';
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out_omit_querystring(),
    'style'  => 'max-width: 720px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);

$categoryOptions = [
    'operational'          => 'Operational — Delivery, staffing, process risks',
    'financial'            => 'Financial — Financial position, cashflow, fee protection',
    'compliance'           => 'Compliance — Regulatory, ASQA audit risk',
    'safety'               => 'Safety — Physical safety, WHS',
    'reputational'         => 'Reputational — Brand, stakeholder perception',
    'under18'              => 'Under-18 Safety & Wellbeing',
    'conflict_of_interest' => 'Conflict of Interest',
];

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Risk Title *', ['for' => 'risktitle', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'risktitle', 'id' => 'risktitle',
    'value' => s($formdata->risktitle ?? ''),
    'class' => 'form-control', 'required' => 'required',
    'placeholder' => 'e.g. Cash flow insufficient to maintain operations',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Category *', ['for' => 'riskcategory', 'class' => 'form-label']);
echo html_writer::select($categoryOptions, 'riskcategory', $formdata->riskcategory ?? 'operational', null, ['id' => 'riskcategory', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Description', ['for' => 'riskdescription', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->riskdescription ?? ''), [
    'name' => 'riskdescription', 'id' => 'riskdescription',
    'class' => 'form-control', 'rows' => '3',
    'placeholder' => 'Describe the risk in detail, its causes, and potential consequences',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px; padding: 16px; background: #f9fafb; border-radius: 8px;']);
echo html_writer::tag('h4', 'Risk Rating Matrix', ['style' => 'margin-bottom: 12px; color: #374151;']);
echo html_writer::tag('p', 'Risk Score = Likelihood × Impact. Critical (≥16), High (9-15), Medium (4-8), Low (1-3)', ['class' => 'text-muted', 'style' => 'font-size: 0.875rem; margin-bottom: 16px;']);

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 20px;']);

$likelihoodOptions = [1 => '1 — Rare', 2 => '2 — Unlikely', 3 => '3 — Possible', 4 => '4 — Likely', 5 => '5 — Almost Certain'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Likelihood *', ['for' => 'likelihood', 'class' => 'form-label']);
echo html_writer::select($likelihoodOptions, 'likelihood', $formdata->likelihood ?? 3, null, ['id' => 'likelihood', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

$impactOptions = [1 => '1 — Insignificant', 2 => '2 — Minor', 3 => '3 — Moderate', 4 => '4 — Major', 5 => '5 — Catastrophic'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Impact *', ['for' => 'impact', 'class' => 'form-label']);
echo html_writer::select($impactOptions, 'impact', $formdata->impact ?? 3, null, ['id' => 'impact', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 16px;']);
echo html_writer::tag('label', 'Risk Owner', ['for' => 'riskowner', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'riskowner', 'id' => 'riskowner',
    'value' => s($formdata->riskowner ?? ''),
    'class' => 'form-control', 'placeholder' => 'Name or role responsible for managing this risk',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Mitigation Plan', ['for' => 'mitigationplan', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->mitigationplan ?? ''), [
    'name' => 'mitigationplan', 'id' => 'mitigationplan',
    'class' => 'form-control', 'rows' => '4',
    'placeholder' => 'Describe controls, actions, and treatments in place to reduce likelihood or impact of this risk',
]);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;']);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Review Date', ['for' => 'reviewdate', 'class' => 'form-label']);
$revdateVal = !empty($formdata->reviewdate) ? date('Y-m-d', $formdata->reviewdate) : '';
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'reviewdate', 'id' => 'reviewdate',
    'value' => $revdateVal, 'class' => 'form-control',
]);
echo html_writer::end_div();

$statusOptions = ['open' => 'Open — Active risk', 'mitigated' => 'Mitigated — Controls in place', 'closed' => 'Closed — No longer applicable'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Status *', ['for' => 'status', 'class' => 'form-label']);
echo html_writer::select($statusOptions, 'status', $formdata->status ?? 'open', null, ['id' => 'status', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Additional Notes', ['for' => 'notes', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->notes ?? ''), [
    'name' => 'notes', 'id' => 'notes',
    'class' => 'form-control', 'rows' => '3',
    'placeholder' => 'Any additional context, escalation path, or external references',
]);
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px; align-items: center;']);
echo html_writer::tag('button', $id ? 'Update Risk' : 'Save Risk', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/risk.php'),
    'Cancel',
    ['class' => 'btn btn-secondary']
);
if ($id) {
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/risk_edit.php', ['id' => $id, 'action' => 'delete', 'sesskey' => sesskey()]),
        'Delete',
        ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete this risk record?")']
    );
}
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
