<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_insurance');
$PAGE->set_title(get_string('insurance', 'local_rtocompliance'));
$PAGE->set_heading(get_string('insurance', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('insurance', 'local_rtocompliance'), null, null, 'insurance');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Insurance Register');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/insurance_edit.php'),
    'Add Insurance Policy',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Required Insurance Types');
echo html_writer::tag('p', 'Track public liability, professional indemnity, and workers compensation insurance with coverage mapping to delivery modes and locations. Set up expiry alerts to ensure continuous coverage.');
echo html_writer::end_div();

$policies = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_insurance')) {
    $policies = $DB->get_records('local_rtocompliance_insurance', null, 'expirydate ASC');
}

$requiredtypes = [
    'public_liability' => ['name' => 'Public Liability', 'icon' => 'i/risk_xp', 'found' => false],
    'professional_indemnity' => ['name' => 'Professional Indemnity', 'icon' => 'i/checkpermissions', 'found' => false],
    // FIX-INSURANCE-TYPE-MISMATCH (v5.9.276): the form (insurance_form.php)
    // stores the value 'workers_comp' in the DB but this lookup key was
    // 'workers_compensation' — causing Workers Compensation to always show
    // as "Missing" on the insurance dashboard regardless of what was saved.
    'workers_comp' => ['name' => 'Workers Compensation', 'icon' => 'i/user', 'found' => false],
];

foreach ($policies as $policy) {
    if (isset($requiredtypes[$policy->insurancetype])) {
        $requiredtypes[$policy->insurancetype]['found'] = true;
    }
}

echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 24px;']);
foreach ($requiredtypes as $key => $type) {
    $color = $type['found'] ? 'green' : 'rose';
    $extra = $type['found'] ? '' : ' alert-warning';
    echo html_writer::start_div('stat-card stat-' . $color . $extra);
    echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('shield') . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('div', $type['found'] ? 'Active' : 'Missing', ['class' => 'stat-number']);
    echo html_writer::tag('div', $type['name'], ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if ($policies) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Provider');
    echo html_writer::tag('th', 'Policy Number');
    echo html_writer::tag('th', 'Coverage');
    echo html_writer::tag('th', 'Expiry Date');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($policies as $policy) {
        $daysuntil = ceil(($policy->expirydate - time()) / 86400);
        $statusclass = 'badge-success';
        $status = 'Current';
        
        if ($daysuntil < 0) {
            $statusclass = 'badge-danger';
            $status = 'Expired';
        } elseif ($daysuntil < 30) {
            $statusclass = 'badge-warning';
            $status = 'Expiring Soon';
        } elseif ($daysuntil < 60) {
            $statusclass = 'badge-info';
            $status = $daysuntil . ' days';
        }

        $typename = ucwords(str_replace('_', ' ', $policy->insurancetype));

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', $typename));
        echo html_writer::tag('td', format_string($policy->provider));
        echo html_writer::tag('td', html_writer::tag('code', $policy->policynumber));
        echo html_writer::tag('td', '$' . number_format($policy->coverageamount, 0));
        echo html_writer::tag('td', userdate($policy->expirydate, '%d %b %Y'));
        echo html_writer::tag('td', html_writer::tag('span', $status, ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/insurance_edit.php', ['id' => $policy->id]),
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
    echo $OUTPUT->pix_icon('i/checkpermissions', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Insurance Policies');
    echo html_writer::tag('p', 'Add your insurance policies to track coverage and receive expiry alerts.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/insurance_edit.php'),
        'Add Insurance Policy',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
