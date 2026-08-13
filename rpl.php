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
 * RTO Compliance plugin — rpl.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$tab = optional_param('tab', 'rpl', PARAM_ALPHA);

admin_externalpage_setup('local_rtocompliance_rpl');
require_login();
$PAGE->set_url('/local/rtocompliance/rpl.php', ['tab' => $tab]);
$PAGE->set_title('RPL & Credit Transfer Register');
$PAGE->set_heading('RPL & Credit Transfer Register');

$dbman = $DB->get_manager();

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('RPL & Credit Transfer');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'RPL & Credit Transfer Register');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl_edit.php', ['tab' => $tab]),
    $tab === 'credit' ? 'Add Credit Transfer' : 'Add RPL Application',
    ['class' => 'btn btn-primary', 'title' => 'Record a new RPL application or credit transfer']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standards 1.6 and 1.7 — Recognition of Prior Learning & Credit Transfer');
echo html_writer::tag('p', '
    <strong>Standard 1.6 (RPL):</strong> The RTO must have fair, flexible, and consistent processes for recognising the current skills and knowledge of applicants.
    Evidence must be assessed against unit requirements. Decisions must be documented.<br>
    <strong>Standard 1.7 (Credit Transfer):</strong> The RTO must provide credit for AQF qualifications, statements of attainment, and other evidence from NRT providers.
    USI transcript verification is required before credit can be granted.
');
echo html_writer::end_div();

$totalrpl = 0;
$totalct = 0;
$totalapproved = 0;
$totalpending = 0;

$totalnotapproved = 0;
$totalpartial     = 0;
$totalstudentsrpl = 0;
$rplthisyear      = 0;

if ($dbman->table_exists('local_rtocompliance_rpl')) {
    $totalrpl         = $DB->count_records('local_rtocompliance_rpl', ['rpltype' => 'rpl']);
    $totalct          = $DB->count_records('local_rtocompliance_rpl', ['rpltype' => 'credit_transfer']);
    $totalapproved    = $DB->count_records('local_rtocompliance_rpl', ['decision' => 'approved']);
    $totalpending     = $DB->count_records('local_rtocompliance_rpl', ['decision' => 'pending']);
    $totalnotapproved = $DB->count_records('local_rtocompliance_rpl', ['decision' => 'not_approved']);
    $totalpartial     = $DB->count_records('local_rtocompliance_rpl', ['decision' => 'partially_approved']);
    $totalstudentsrpl = $DB->count_records_sql("SELECT COUNT(DISTINCT studentid) FROM {local_rtocompliance_rpl} WHERE studentid IS NOT NULL AND studentid > 0");
    $rplthisyear      = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_rpl} WHERE timecreated >= :ys", ['ys' => mktime(0, 0, 0, 1, 1, (int)date('Y'))]);
}

echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 24px;']);
$summaryStats = [
    ['label' => 'Total RPL Applications',   'value' => $totalrpl,         'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('bar'),      'tip' => 'Total requests for RPL (recognition of prior learning) &ndash; giving a student credit for skills and knowledge they already have.'],
    ['label' => 'Total Credit Transfers',   'value' => $totalct,          'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('file'),     'tip' => 'Total credit transfers &ndash; giving credit for units the student already completed and was certified for somewhere else.'],
    ['label' => 'Approved / Granted',       'value' => $totalapproved,    'color' => 'green',  'icon' => local_rtocompliance_stat_icon('check'),    'tip' => 'Applications where the credit was granted in full.'],
    ['label' => 'Pending Decision',         'value' => $totalpending,     'color' => $totalpending  > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock'),    'tip' => 'Applications still waiting for a decision.'],
    ['label' => 'Not Approved',             'value' => $totalnotapproved, 'color' => $totalnotapproved > 0 ? 'rose' : 'green', 'icon' => local_rtocompliance_stat_icon('x'),        'tip' => 'Applications where no credit was granted.'],
    ['label' => 'Partially Approved',       'value' => $totalpartial,     'color' => $totalpartial  > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock'),    'tip' => 'Applications where some units were credited but not all.'],
    ['label' => 'Students with RPL / CT',   'value' => $totalstudentsrpl, 'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('users'),    'tip' => 'Number of separate students who have at least one RPL or credit transfer record.'],
    ['label' => 'Submitted This Year',      'value' => $rplthisyear,      'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('calendar'), 'tip' => 'Applications submitted since 1 January of this year.'],
];
foreach ($summaryStats as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color'], ['title' => $s['tip']]);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'], ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('tab-nav');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => 'rpl']),
    'RPL Applications (Standard 1.6)',
    ['class' => $tab === 'rpl' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => 'credit']),
    'Credit Transfers (Standard 1.7)',
    ['class' => $tab === 'credit' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => 'all']),
    'All Records',
    ['class' => $tab === 'all' ? 'active' : '']
);
echo html_writer::end_div();

$records = [];
if ($dbman->table_exists('local_rtocompliance_rpl')) {
    $sql = "SELECT r.*, u.firstname, u.lastname
            FROM {local_rtocompliance_rpl} r
            LEFT JOIN {user} u ON u.id = r.assessoruserid";
    $params = [];
    if ($tab === 'rpl') {
        $sql .= " WHERE r.rpltype = 'rpl'";
    } elseif ($tab === 'credit') {
        $sql .= " WHERE r.rpltype = 'credit_transfer'";
    }
    $sql .= " ORDER BY r.timecreated DESC";
    $records = $DB->get_records_sql($sql, $params, 0, 100);
}

if ($records) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Student', ['title' => 'Student the application relates to']);
    echo html_writer::tag('th', 'Unit / Qualification', ['title' => 'Unit or qualification being recognised or credited']);
    echo html_writer::tag('th', 'Type', ['title' => 'RPL application or credit transfer']);
    echo html_writer::tag('th', 'Assessor', ['title' => 'Assessor who made the decision']);
    echo html_writer::tag('th', 'Evidence', ['title' => 'Summary of the evidence submitted and assessed']);
    echo html_writer::tag('th', 'Decision', ['title' => 'Outcome of the assessment']);
    echo html_writer::tag('th', 'Decision Date', ['title' => 'Date the decision was made']);
    echo html_writer::tag('th', 'Student Notified', ['title' => 'Whether the outcome has been communicated to the student']);
    if ($tab === 'credit' || $tab === 'all') {
        echo html_writer::tag('th', 'USI Verified', ['title' => 'Whether the USI transcript has been verified']);
    }
    echo html_writer::tag('th', 'Actions', ['title' => 'Edit this record']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($records as $rec) {
        $decisionClass = 'badge-info';
        if ($rec->decision === 'approved')            $decisionClass = 'badge-success';
        if ($rec->decision === 'partially_approved')  $decisionClass = 'badge-warning';
        if ($rec->decision === 'not_approved')        $decisionClass = 'badge-danger';
        if ($rec->decision === 'pending')             $decisionClass = 'badge-secondary';

        $decisionLabel = ucwords(str_replace('_', ' ', $rec->decision));

        $decisionTitle = 'The outcome of this application.';
        if ($rec->decision === 'approved')           $decisionTitle = 'Approved: the credit was granted in full.';
        if ($rec->decision === 'partially_approved') $decisionTitle = 'Partially approved: some units were credited, others were not.';
        if ($rec->decision === 'not_approved')       $decisionTitle = 'Not approved: no credit was granted.';
        if ($rec->decision === 'pending')            $decisionTitle = 'Pending: a decision has not been made yet.';

        $typeLabel = $rec->rpltype === 'rpl' ? 'RPL' : 'Credit Transfer';
        $typeClass = $rec->rpltype === 'rpl' ? 'badge-blue' : 'badge-purple';
        $typeTitle = $rec->rpltype === 'rpl'
            ? 'RPL (recognition of prior learning): credit for skills and knowledge the student already has.'
            : 'Credit transfer: credit for units the student already completed and was certified for somewhere else.';

        $unitDisplay = $rec->unitcode
            ? s($rec->unitcode) . ($rec->unitname ? ' ' . s(substr($rec->unitname, 0, 50)) : '')
            : ($rec->qualcode ? s($rec->qualcode) . ($rec->qualname ? ' - ' . s(substr($rec->qualname, 0, 40)) : '') : '-');

        $assessorDisplay = $rec->assessorname
            ? s($rec->assessorname)
            : ($rec->firstname ? s($rec->firstname . ' ' . $rec->lastname) : '-');

        $evidenceSummary = $rec->evidencedescription
            ? html_writer::tag('small', s(substr($rec->evidencedescription, 0, 60)) . (strlen($rec->evidencedescription) > 60 ? '...' : ''))
            : html_writer::tag('span', 'Not recorded', ['class' => 'text-muted']);

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', s($rec->studentname ?: 'Unknown')));
        echo html_writer::tag('td', $unitDisplay);
        echo html_writer::tag('td', html_writer::tag('span', $typeLabel, ['class' => 'badge ' . $typeClass, 'title' => $typeTitle]));
        echo html_writer::tag('td', $assessorDisplay);
        echo html_writer::tag('td', $evidenceSummary);
        echo html_writer::tag('td', html_writer::tag('span', $decisionLabel, ['class' => 'badge ' . $decisionClass, 'title' => $decisionTitle]));
        echo html_writer::tag('td', $rec->decisiondate ? userdate($rec->decisiondate, '%d %b %Y') : html_writer::tag('span', 'Pending', ['class' => 'text-muted']));
        // RPL-P2 (v5.9.421): procedural-fairness signal — has the student been told the
        // outcome? Only meaningful once a decision is finalised (not while pending).
        if ($rec->decision === 'pending') {
            $notifiedCell = html_writer::tag('span', '—', ['class' => 'text-muted']);
        } else if (!empty($rec->outcomecommunicated)) {
            $nlabel = 'Notified';
            if (!empty($rec->outcomecommunicateddate)) {
                $nlabel .= ' ' . userdate($rec->outcomecommunicateddate, '%d %b %Y');
            }
            $notifiedCell = html_writer::tag('span', $nlabel, ['class' => 'badge badge-success', 'title' => 'The student has been told the outcome in writing.']);
        } else {
            $notifiedCell = html_writer::tag('span', 'Not notified', ['class' => 'badge badge-warning', 'title' => 'The student has not yet been told the outcome in writing.']);
        }
        echo html_writer::tag('td', $notifiedCell);
        if ($tab === 'credit' || $tab === 'all') {
            $usiClass = $rec->usitranscriptverified ? 'badge-success' : 'badge-warning';
            $usiLabel = $rec->usitranscriptverified ? 'Verified' : 'Not Verified';
            $usiTitle = $rec->usitranscriptverified
                ? 'The student USI (unique student identifier) transcript has been checked to confirm the earlier study is genuine.'
                : 'The student USI (unique student identifier) transcript has not been checked yet.';
            echo html_writer::tag('td', html_writer::tag('span', $usiLabel, ['class' => 'badge ' . $usiClass, 'title' => $usiTitle]));
        }
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $rec->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-secondary', 'title' => 'Edit this RPL or credit transfer record']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/checkedcircle', '', 'moodle', ['class' => 'empty-state-icon']);
    if ($tab === 'rpl') {
        echo html_writer::tag('h3', 'No RPL Applications Recorded');
        echo html_writer::tag('p', 'Record RPL applications here to demonstrate ASQA Standard 1.6 compliance. Each record must show: the student, units being recognised, evidence assessed, and the assessor\'s documented decision.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/rpl_edit.php', ['tab' => 'rpl']),
            'Record First RPL Application',
            ['class' => 'btn btn-primary', 'title' => 'Record the first RPL application']
        );
    } elseif ($tab === 'credit') {
        echo html_writer::tag('h3', 'No Credit Transfers Recorded');
        echo html_writer::tag('p', 'Record credit transfers here to demonstrate ASQA Standard 1.7 compliance. Each record must show: the student, units being credited, the AQF qualification or authenticated transcript used as evidence, USI transcript verification status, and the decision.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/rpl_edit.php', ['tab' => 'credit']),
            'Record First Credit Transfer',
            ['class' => 'btn btn-primary', 'title' => 'Record the first credit transfer']
        );
    } else {
        echo html_writer::tag('h3', 'No RPL or Credit Transfer Records');
        echo html_writer::tag('p', 'All RPL applications and credit transfer decisions will appear here.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/rpl_edit.php'),
            'Add First Record',
            ['class' => 'btn btn-primary', 'title' => 'Add the first RPL or credit transfer record']
        );
    }
    echo html_writer::end_div();
}

echo html_writer::start_div('info-card info-accent-blue');
echo html_writer::tag('h4', 'ASQA Audit Evidence Requirements');
echo html_writer::start_tag('ul');
echo html_writer::tag('li', '<strong>Standard 1.6 — RPL:</strong> Evidence must show fair, flexible assessment against the current unit requirements. Document: evidence submitted, assessment methodology, assessor credentials, and written decision rationale.');
echo html_writer::tag('li', '<strong>Standard 1.7 — Credit Transfer:</strong> Grant credit for equivalent AQF qualifications or statements of attainment issued by any NRT provider. Verify authenticity via USI transcript or sighted original documents. Document source qualification, issuing RTO, and equivalence mapping.');
echo html_writer::tag('li', '<strong>Both:</strong> Decisions must be communicated to the student in writing. Records must be retained as part of the student\'s training record.');
echo html_writer::end_tag('ul');
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
