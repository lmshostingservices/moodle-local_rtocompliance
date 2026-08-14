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
 * RTO Compliance plugin — marketing_info.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_marketing_info');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$PAGE->set_url('/local/rtocompliance/marketing_info.php');
$PAGE->set_title('Marketing Information');
$PAGE->set_heading('Marketing Information');

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

$context = context_system::instance();
$canmanage = has_capability('local/rtocompliance:manage', $context);

// STANDARD 2.1 PRE-ENROLMENT DISCLOSURE REGISTER (v5.9.428).
// The mandatory information an RTO must disclose to prospective students BEFORE
// enrolment / before payment. Each item records whether it is disclosed and where
// it is provided, plus a retained pre-payment fee/obligation acknowledgement — the
// evidence ASQA looks for under Standard 2.1. Persisted server-side in plugin config
// (no Moodle core writes, no schema change), matching the student-support pattern.
$disclosureitems = [
    'products'    => 'Training products — codes, titles, delivery mode, duration and locations',
    'fees'        => 'All fees and charges, and the payment terms, disclosed before enrolment',
    'refunds'     => 'Refund policy and how to request a refund',
    'feeprotect'  => 'Fee-protection arrangement for prepaid fees',
    'entry'       => 'Entry requirements / prerequisites for each product',
    'usi'         => 'USI requirement (a verified USI is needed for certification)',
    'support'     => 'Learner support, LLN assistance and reasonable adjustments available',
    'thirdparty'  => 'Any third-party arrangements involved in delivery or services',
    'obligations' => 'Rights and obligations — terms and conditions of enrolment',
    'complaints'  => 'Complaints and appeals process and how to access it',
    'recognition' => 'Recognition arrangements — RPL and credit transfer',
    'outcome'     => 'What the student receives on completion (AQF certification issued)',
    'privacy'     => 'Privacy — how personal information (including the USI) is collected and used',
];

// Load saved state.
$saved = [];
$rawsaved = get_config('local_rtocompliance', 'marketing_disclosures');
if ($rawsaved) {
    $decoded = json_decode($rawsaved, true);
    if (is_array($decoded)) {
        $saved = $decoded;
    }
}
$ackprepay = (int) get_config('local_rtocompliance', 'marketing_prepay_ack');
$reviewdate = (string) get_config('local_rtocompliance', 'marketing_review_date');

// Handle save.
if ($canmanage && optional_param('action', '', PARAM_ALPHA) === 'save' && confirm_sesskey()) {
    $new = [];
    foreach ($disclosureitems as $key => $label) {
        $new[$key] = [
            'disclosed' => optional_param('disclosed_' . $key, 0, PARAM_INT) ? 1 : 0,
            'where'     => clean_param(optional_param('where_' . $key, '', PARAM_TEXT), PARAM_TEXT),
        ];
    }
    set_config('marketing_disclosures', json_encode($new), 'local_rtocompliance');
    set_config('marketing_prepay_ack', optional_param('ackprepay', 0, PARAM_INT) ? 1 : 0, 'local_rtocompliance');
    $rd = optional_param('reviewdate', '', PARAM_TEXT);
    set_config('marketing_review_date', $rd !== '' ? clean_param($rd, PARAM_TEXT) : '', 'local_rtocompliance');
    if (function_exists('local_rtocompliance_log_action')) {
        local_rtocompliance_log_action('update', 'marketing_disclosures', 0, ['items' => count($new)]);
    }
    redirect(new moodle_url('/local/rtocompliance/marketing_info.php'),
        'Pre-enrolment disclosure register saved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Compute coverage.
$total = count($disclosureitems);
$done = 0;
foreach ($disclosureitems as $key => $label) {
    if (!empty($saved[$key]['disclosed'])) {
        $done++;
    }
}
$pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Marketing Information', null, null, 'marketing_info');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Marketing Information');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standard 2.1 – Information about the organisation and training products');
echo html_writer::tag('p', '
    <strong>Clause 2.1:</strong> The RTO must provide accurate and accessible information to prospective and current students about its training products and services,
    including any third party arrangements, entry requirements, fees, refund arrangements, and complaints processes &mdash; before the student enrols and before they pay.
');
echo html_writer::end_div();

// ── Coverage hero ─────────────────────────────────────────────────────────
$covcolor = $pct >= 100 ? '#059669' : ($pct >= 60 ? '#d97706' : '#dc2626');
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Pre-enrolment disclosure coverage');
echo '<div style="display:flex;align-items:center;gap:16px;margin-top:6px;">';
echo '<div style="font-size:2rem;font-weight:800;color:' . $covcolor . ';line-height:1;">' . $pct . '%</div>';
echo '<div style="flex:1;">';
echo '<div style="height:10px;background:#eef2f7;border-radius:9999px;overflow:hidden;">';
echo '<div style="height:100%;width:' . $pct . '%;background:' . $covcolor . ';border-radius:9999px;transition:width .3s;"></div>';
echo '</div>';
echo '<div style="font-size:0.85rem;color:#64748b;margin-top:6px;">' . $done . ' of ' . $total
    . ' mandatory disclosure items marked as provided'
    . ($ackprepay ? ' &middot; <span style="color:#059669;">fee/obligation acknowledgement retained before payment</span>'
                  : ' &middot; <span style="color:#b45309;">pre-payment acknowledgement not yet confirmed</span>')
    . '</div>';
echo '</div>';
echo '</div>';
echo html_writer::end_div();

// ── The register ──────────────────────────────────────────────────────────
if ($canmanage) {
    echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/marketing_info.php'))->out(false) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
}

echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Pre-enrolment information disclosure register');
echo html_writer::tag('p', 'Confirm each mandatory item is disclosed to prospective students before they enrol, and record where it is provided (e.g. website, student handbook, course guide, enrolment form). This is your Standard 2.1 evidence.',
    ['class' => 'text-muted', 'style' => 'font-size:0.875rem;margin-bottom:12px;']);

echo '<table class="table" style="margin-bottom:0;"><thead><tr>'
    . '<th style="width:40px;" title="Whether this information item is disclosed to prospective students before enrolment">Provided</th>'
    . '<th title="The mandatory pre-enrolment information item to be disclosed">Information item</th>'
    . '<th style="width:34%;" title="Where students are given this information (e.g. website, student handbook, course guide, enrolment form)">Where it is provided</th>'
    . '</tr></thead><tbody>';
foreach ($disclosureitems as $key => $label) {
    $isdone = !empty($saved[$key]['disclosed']);
    $where = isset($saved[$key]['where']) ? (string) $saved[$key]['where'] : '';
    echo '<tr>';
    echo '<td style="text-align:center;">';
    if ($canmanage) {
        echo '<input type="checkbox" name="disclosed_' . $key . '" value="1"' . ($isdone ? ' checked' : '') . '>';
    } else {
        echo $isdone ? '<span style="color:#059669;font-weight:700;">&#10003;</span>'
                     : '<span style="color:#cbd5e1;">&mdash;</span>';
    }
    echo '</td>';
    echo '<td style="font-size:0.9rem;">' . s($label) . '</td>';
    echo '<td>';
    if ($canmanage) {
        echo '<input type="text" name="where_' . $key . '" value="' . s($where) . '" class="form-control form-control-sm" placeholder="e.g. Website + Student Handbook v3">';
    } else {
        echo $where !== '' ? s($where) : '<span class="text-muted">Not recorded</span>';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo html_writer::end_div();

// ── Pre-payment acknowledgement + review ──────────────────────────────────
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Fee &amp; obligation acknowledgement, and review');
echo '<div class="form-check" style="margin-bottom:12px;">';
if ($canmanage) {
    echo '<input type="checkbox" class="form-check-input" id="ackprepay" name="ackprepay" value="1"' . ($ackprepay ? ' checked' : '') . '>';
    echo '<label class="form-check-label" for="ackprepay" style="margin-left:6px;">Students sign and we retain an acknowledgement of the fees, refund policy and their obligations <strong>before</strong> any payment is taken.</label>';
} else {
    echo ($ackprepay ? '<span style="color:#059669;font-weight:700;">&#10003;</span> ' : '<span style="color:#cbd5e1;">&mdash;</span> ')
        . 'Signed, retained pre-payment fee/obligation acknowledgement';
}
echo '</div>';
echo '<div class="form-group" style="max-width:280px;">';
echo '<label for="reviewdate" class="form-label" style="font-size:0.85rem;">Marketing materials last reviewed / approved</label>';
if ($canmanage) {
    echo '<input type="date" id="reviewdate" name="reviewdate" value="' . s($reviewdate) . '" class="form-control form-control-sm">';
} else {
    echo '<div>' . ($reviewdate !== '' ? s($reviewdate) : '<span class="text-muted">Not recorded</span>') . '</div>';
}
echo '</div>';
echo html_writer::tag('p', 'Keep marketing materials, the student handbook and course guides under review so the disclosed information stays accurate and current.',
    ['class' => 'text-muted', 'style' => 'font-size:0.82rem;margin:10px 0 0;']);
echo html_writer::end_div();

if ($canmanage) {
    echo '<div style="margin-top:18px;">';
    echo '<button type="submit" class="btn btn-primary">Save disclosure register</button>';
    echo '</div>';
    echo '</form>';
} else {
    echo html_writer::tag('p', 'You have read-only access to this register.',
        ['class' => 'text-muted', 'style' => 'font-size:0.82rem;margin-top:12px;']);
}

echo html_writer::end_div();

echo $OUTPUT->footer();
