<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$tab = optional_param('tab', 'rpl', PARAM_ALPHA);

admin_externalpage_setup('local_rtocompliance_rpl');
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
    ['class' => 'btn btn-primary']
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
    ['label' => 'Total RPL Applications',   'value' => $totalrpl,         'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('bar')],
    ['label' => 'Total Credit Transfers',   'value' => $totalct,          'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('file')],
    ['label' => 'Approved / Granted',       'value' => $totalapproved,    'color' => 'green',  'icon' => local_rtocompliance_stat_icon('check')],
    ['label' => 'Pending Decision',         'value' => $totalpending,     'color' => $totalpending  > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Not Approved',             'value' => $totalnotapproved, 'color' => $totalnotapproved > 0 ? 'rose' : 'green', 'icon' => local_rtocompliance_stat_icon('x')],
    ['label' => 'Partially Approved',       'value' => $totalpartial,     'color' => $totalpartial  > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Students with RPL / CT',   'value' => $totalstudentsrpl, 'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('users')],
    ['label' => 'Submitted This Year',      'value' => $rplthisyear,      'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('calendar')],
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
    echo html_writer::tag('th', 'Student');
    echo html_writer::tag('th', 'Unit / Qualification');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Assessor');
    echo html_writer::tag('th', 'Evidence');
    echo html_writer::tag('th', 'Decision');
    echo html_writer::tag('th', 'Decision Date');
    if ($tab === 'credit' || $tab === 'all') {
        echo html_writer::tag('th', 'USI Verified');
    }
    echo html_writer::tag('th', 'Actions');
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

        $typeLabel = $rec->rpltype === 'rpl' ? 'RPL' : 'Credit Transfer';
        $typeClass = $rec->rpltype === 'rpl' ? 'badge-blue' : 'badge-purple';

        $unitDisplay = $rec->unitcode
            ? s($rec->unitcode) . ($rec->unitname ? ' - ' . s(substr($rec->unitname, 0, 50)) : '')
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
        echo html_writer::tag('td', html_writer::tag('span', $typeLabel, ['class' => 'badge ' . $typeClass]));
        echo html_writer::tag('td', $assessorDisplay);
        echo html_writer::tag('td', $evidenceSummary);
        echo html_writer::tag('td', html_writer::tag('span', $decisionLabel, ['class' => 'badge ' . $decisionClass]));
        echo html_writer::tag('td', $rec->decisiondate ? userdate($rec->decisiondate, '%d %b %Y') : html_writer::tag('span', 'Pending', ['class' => 'text-muted']));
        if ($tab === 'credit' || $tab === 'all') {
            $usiClass = $rec->usitranscriptverified ? 'badge-success' : 'badge-warning';
            $usiLabel = $rec->usitranscriptverified ? 'Verified' : 'Not Verified';
            echo html_writer::tag('td', html_writer::tag('span', $usiLabel, ['class' => 'badge ' . $usiClass]));
        }
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $rec->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-secondary']
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
            ['class' => 'btn btn-primary']
        );
    } elseif ($tab === 'credit') {
        echo html_writer::tag('h3', 'No Credit Transfers Recorded');
        echo html_writer::tag('p', 'Record credit transfers here to demonstrate ASQA Standard 1.7 compliance. Each record must show: the student, units being credited, the AQF qualification or authenticated transcript used as evidence, USI transcript verification status, and the decision.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/rpl_edit.php', ['tab' => 'credit']),
            'Record First Credit Transfer',
            ['class' => 'btn btn-primary']
        );
    } else {
        echo html_writer::tag('h3', 'No RPL or Credit Transfer Records');
        echo html_writer::tag('p', 'All RPL applications and credit transfer decisions will appear here.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/rpl_edit.php'),
            'Add First Record',
            ['class' => 'btn btn-primary']
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
