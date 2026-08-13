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

$tab = optional_param('tab', 'register', PARAM_TEXT);
$tab = in_array($tab, ['all', 'register', 'financial', 'conflict_of_interest', 'conflicts', 'under18'], true) ? $tab : 'all';

admin_externalpage_setup('local_rtocompliance_risk');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_url('/local/rtocompliance/risk.php', ['tab' => $tab]);
$PAGE->set_title('Risk Management Register');
$PAGE->set_heading('Risk Management Register');

$dbman = $DB->get_manager();

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Risk Management');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Risk Management Register');
// Pre-select the category that matches the active tab so the form opens ready to use.
$addriskurl   = new moodle_url('/local/rtocompliance/risk_edit.php');
$addrisklabel = 'Add Risk';
if ($tab === 'financial') {
    $addriskurl   = new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'financial']);
    $addrisklabel = 'Add Financial Risk';
} elseif ($tab === 'conflicts') {
    $addriskurl   = new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'conflict_of_interest']);
    $addrisklabel = 'Add Conflict of Interest';
} elseif ($tab === 'under18') {
    $addriskurl   = new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'under18']);
    $addrisklabel = 'Add Under-18 Risk';
}
echo html_writer::link($addriskurl, $addrisklabel, ['class' => 'btn btn-primary']);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Standard 4.3 — Risk Management');
echo html_writer::tag('p', 'Governing persons must identify, manage, and regularly review risks to students, staff, and the organisation. This includes financial oversight, conflicts of interest, and (where applicable) under-18 learner safety. The risk register is key evidence for ASQA audits.');
echo html_writer::end_div();

$totalrisks     = 0;
$criticalrisks  = 0;
$highrisks      = 0;
$openrisks      = 0;
$financialrisks = 0;
$conflicts      = 0;
$under18risks   = 0;

if ($dbman->table_exists('local_rtocompliance_risks')) {
    $totalrisks     = $DB->count_records('local_rtocompliance_risks');
    $openrisks      = $DB->count_records('local_rtocompliance_risks', ['status' => 'open']);
    $financialrisks = $DB->count_records('local_rtocompliance_risks', ['riskcategory' => 'financial']);
    $conflicts      = $DB->count_records('local_rtocompliance_risks', ['riskcategory' => 'conflict_of_interest']);
    $under18risks   = $DB->count_records('local_rtocompliance_risks', ['riskcategory' => 'under18']);

    $criticalrisks = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_risks} WHERE likelihood * impact >= 16 AND status = 'open'"
    );
    $highrisks = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_risks} WHERE likelihood * impact >= 9 AND likelihood * impact < 16 AND status = 'open'"
    );
}

echo html_writer::start_div('stats-cards');
$summaryStats = [
    ['label' => 'Total Risks',           'value' => $totalrisks,    'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('shield')],
    ['label' => 'Open Risks',            'value' => $openrisks,     'color' => $openrisks > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Critical Risks (score ≥ 16)', 'value' => $criticalrisks, 'color' => $criticalrisks > 0 ? 'rose' : 'green',  'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'High Risks (score 9–15)',    'value' => $highrisks,     'color' => $highrisks > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Financial Risks',       'value' => $financialrisks,'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('dollar')],
    ['label' => 'Conflicts of Interest', 'value' => $conflicts,     'color' => 'amber',  'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Under-18 Safety',       'value' => $under18risks,  'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('shield')],
];
foreach ($summaryStats as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
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
    new moodle_url('/local/rtocompliance/risk.php', ['tab' => 'register']),
    'Risk Register',
    ['class' => $tab === 'register' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/risk.php', ['tab' => 'financial']),
    'Financial Oversight',
    ['class' => $tab === 'financial' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/risk.php', ['tab' => 'conflicts']),
    'Conflicts of Interest',
    ['class' => $tab === 'conflicts' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/risk.php', ['tab' => 'under18']),
    'Under-18 Safety',
    ['class' => $tab === 'under18' ? 'active' : '']
);
echo html_writer::end_div();

$risks = [];
if ($dbman->table_exists('local_rtocompliance_risks')) {
    $sql = "SELECT * FROM {local_rtocompliance_risks}";
    $where = '';
    if ($tab === 'financial') {
        $where = " WHERE riskcategory = 'financial'";
    } elseif ($tab === 'conflicts') {
        $where = " WHERE riskcategory = 'conflict_of_interest'";
    } elseif ($tab === 'under18') {
        $where = " WHERE riskcategory = 'under18'";
    }
    $sql .= $where . " ORDER BY (likelihood * impact) DESC, timecreated DESC";
    $risks = $DB->get_records_sql($sql, [], 0, 100);
}

$categoryLabels = [
    'operational'        => 'Operational',
    'financial'          => 'Financial',
    'compliance'         => 'Compliance',
    'safety'             => 'Safety',
    'reputational'       => 'Reputational',
    'under18'            => 'Under-18 Safety',
    'conflict_of_interest' => 'Conflict of Interest',
];

$likelihoodLabels = [1 => 'Rare', 2 => 'Unlikely', 3 => 'Possible', 4 => 'Likely', 5 => 'Almost Certain'];
$impactLabels     = [1 => 'Insignificant', 2 => 'Minor', 3 => 'Moderate', 4 => 'Major', 5 => 'Catastrophic'];

function rtoc_risk_level_class($likelihood, $impact) {
    $score = $likelihood * $impact;
    if ($score >= 16) return ['badge-danger', 'Critical'];
    if ($score >= 9)  return ['badge-warning', 'High'];
    if ($score >= 4)  return ['badge-info', 'Medium'];
    return ['badge-success', 'Low'];
}

if ($tab === 'financial' && empty($risks)) {
    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Financial Oversight Requirements (Standard 4.3)');
    echo html_writer::start_tag('ul');
    echo html_writer::tag('li', 'Governing persons must oversee the financial position, performance, and cash flow of the RTO.');
    echo html_writer::tag('li', 'Financial review should be a standing agenda item at governance meetings.');
    echo html_writer::tag('li', 'Evidence: management accounts, P&L statements, cash flow forecasts, board meeting minutes showing financial review.');
    echo html_writer::tag('li', 'Fee-for-service RTOs: document controls for $1,500 prepaid fee threshold (see Fee Protection register).');
    echo html_writer::end_tag('ul');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'financial']),
        'Add Financial Risk',
        ['class' => 'btn btn-primary', 'style' => 'margin-top: 12px;']
    );
    echo html_writer::end_div();
}

if ($tab === 'conflicts' && empty($risks)) {
    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Conflict of Interest Requirements (Standard 4.3)');
    echo html_writer::start_tag('ul');
    echo html_writer::tag('li', 'The RTO must have a system for identifying and managing real or apparent conflicts of interest for governing persons and staff.');
    echo html_writer::tag('li', 'Disclosures should be recorded in a register and the conflicted person excluded from related decisions.');
    echo html_writer::tag('li', 'Evidence: conflict of interest declarations, register of disclosures, meeting minutes recording exclusions.');
    echo html_writer::end_tag('ul');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'conflict_of_interest']),
        'Record Conflict of Interest',
        ['class' => 'btn btn-primary', 'style' => 'margin-top: 12px;']
    );
    echo html_writer::end_div();
}

if ($tab === 'under18') {
    // FIX-UNDER18-NOTE: Prior to v4.2.8 the "Add Risk" button did not pre-select the active
    // category tab, so risks added from this tab may have been saved with riskcategory='operational'
    // instead of 'under18'. The count shown in the dashboard stat card may therefore be understated.
    // Check the Operational tab if you believe you have Under-18 risks that are not appearing here.
    echo '<div class="alert alert-warning" style="font-size:13px;margin-bottom:12px;">'
        . '<strong>Heads-up:</strong> Risks added before v4.2.9 from this tab may have been saved under '
        . 'the <strong>Operational</strong> category. If you are missing records, check the '
        . html_writer::link(new moodle_url('/local/rtocompliance/risk.php', ['tab' => 'all']), 'All Risks')
        . ' view and re-save any that belong here.'
        . '</div>';
}

if ($tab === 'under18' && empty($risks)) {
    echo html_writer::start_div('info-card');
    echo html_writer::tag('h4', 'Under-18 Safety & Wellbeing (Standard 4.3 — where applicable)');
    echo html_writer::tag('p', 'Where training involves learners under 18, governing persons must manage risks to their safety and wellbeing. This includes child safety policies, supervision arrangements, and mandatory reporting obligations.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/risk_edit.php', ['category' => 'under18']),
        'Add Under-18 Safety Risk',
        ['class' => 'btn btn-primary', 'style' => 'margin-top: 12px;']
    );
    echo html_writer::end_div();
}

if ($risks) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Risk');
    echo html_writer::tag('th', 'Category');
    echo html_writer::tag('th', 'Likelihood');
    echo html_writer::tag('th', 'Impact');
    echo html_writer::tag('th', 'Risk Level');
    echo html_writer::tag('th', 'Owner');
    echo html_writer::tag('th', 'Review Date');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($risks as $risk) {
        [$levelClass, $levelLabel] = rtoc_risk_level_class($risk->likelihood, $risk->impact);

        $statusClass = 'badge-info';
        if ($risk->status === 'mitigated') $statusClass = 'badge-success';
        if ($risk->status === 'open')      $statusClass = 'badge-warning';
        if ($risk->status === 'closed')    $statusClass = 'badge-secondary';

        $overdueReview = $risk->reviewdate && $risk->reviewdate < time() && $risk->status === 'open';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            html_writer::tag('strong', s($risk->risktitle)) .
            ($risk->riskdescription ? html_writer::empty_tag('br') . html_writer::tag('small', s(substr($risk->riskdescription, 0, 80)), ['class' => 'text-muted']) : '')
        );
        echo html_writer::tag('td', $categoryLabels[$risk->riskcategory] ?? ucfirst($risk->riskcategory));
        echo html_writer::tag('td', $likelihoodLabels[$risk->likelihood] ?? $risk->likelihood);
        echo html_writer::tag('td', $impactLabels[$risk->impact] ?? $risk->impact);
        echo html_writer::tag('td', html_writer::tag('span', $levelLabel, ['class' => 'badge ' . $levelClass]));
        echo html_writer::tag('td', $risk->riskowner ? s($risk->riskowner) : html_writer::tag('span', 'Unassigned', ['class' => 'text-muted']));
        echo html_writer::tag('td',
            $risk->reviewdate
                ? ($overdueReview
                    ? html_writer::tag('span', userdate($risk->reviewdate, '%d %b %Y') . ' OVERDUE', ['class' => 'badge badge-danger'])
                    : userdate($risk->reviewdate, '%d %b %Y'))
                : '-'
        );
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($risk->status), ['class' => 'badge ' . $statusClass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/risk_edit.php', ['id' => $risk->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} elseif ($tab === 'register') {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/warning', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Risks Recorded');
    echo html_writer::tag('p', 'All RTOs must identify and document risks under Standard 4.3. Start by recording your key operational, financial, and compliance risks. ASQA expects to see a maintained risk register as evidence of active governance.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/risk_edit.php'),
        'Add First Risk',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::start_div('info-card info-accent-purple');
echo html_writer::tag('h4', 'Standard 4.3 — Risk Management Compliance Checklist');
$checklist = [
    'Risk identification and management reviewed by governing persons',
    'Financial position, performance, and cash flow monitored',
    'Conflict of interest system in place with declaration register',
    'Under-18 learner safety considered (if applicable)',
    'Risk register reviewed at governance meetings (documented in minutes)',
    'High risks have documented mitigation plans and review dates',
];
echo html_writer::start_tag('ul');
foreach ($checklist as $item) {
    echo html_writer::tag('li', $item);
}
echo html_writer::end_tag('ul');
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
