<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_thirdparty');
$PAGE->set_title(get_string('thirdparty', 'local_rtocompliance'));
$PAGE->set_heading(get_string('thirdparty', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('thirdparty', 'local_rtocompliance'), null, null, 'thirdparty');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Third-Party Arrangements Register');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/thirdparty_edit.php'),
    'Add Arrangement',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card warning');
echo html_writer::tag('h4', 'ASQA 30-Day Notification Requirement');
echo html_writer::tag('p', 'RTOs must notify ASQA at least 30 days before entering into a third-party arrangement. Track mandatory clause verification including NRT logo prohibition, AQF issuance prohibition, and student transparency requirements.');
echo html_writer::end_div();

$arrangements = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_thirdparty')) {
    $arrangements = $DB->get_records('local_rtocompliance_thirdparty', null, 'agreementstartdate DESC', '*', 0, 50);
}

if ($arrangements) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Organisation');
    echo html_writer::tag('th', 'Type');
    echo html_writer::tag('th', 'Start Date');
    echo html_writer::tag('th', 'End Date');
    echo html_writer::tag('th', 'ASQA Notified');
    echo html_writer::tag('th', 'Clauses Verified');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($arrangements as $arr) {
        $statusclass = 'badge-success';
        if ($arr->status == 'inactive') $statusclass = 'badge-warning';
        if ($arr->status == 'expired' || $arr->status == 'terminated') $statusclass = 'badge-danger';

        $notified = $arr->asqanotified ? 'Yes' : 'No';
        $notifiedclass = $arr->asqanotified ? 'badge-success' : 'badge-danger';

        $clausesok = ($arr->mandatoryclausesnrtlogo && $arr->mandatoryclausesaqf && $arr->mandatoryclausestransparency);
        $clausesclass = $clausesok ? 'badge-success' : 'badge-warning';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', format_string($arr->organisationname)));
        echo html_writer::tag('td', ucfirst($arr->arrangementtype));
        echo html_writer::tag('td', userdate($arr->agreementstartdate, '%d %b %Y'));
        echo html_writer::tag('td', $arr->agreementenddate ? userdate($arr->agreementenddate, '%d %b %Y') : 'Ongoing');
        echo html_writer::tag('td', html_writer::tag('span', $notified, ['class' => 'badge ' . $notifiedclass]));
        echo html_writer::tag('td', html_writer::tag('span', $clausesok ? 'Complete' : 'Incomplete', ['class' => 'badge ' . $clausesclass]));
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($arr->status), ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/thirdparty_edit.php', ['id' => $arr->id]),
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
    echo $OUTPUT->pix_icon('i/withsubcat', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Third-Party Arrangements');
    echo html_writer::tag('p', 'Third-party delivery, assessment, or support arrangements will be tracked here. Includes monitoring schedules and staff credential verification.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/thirdparty_edit.php'),
        'Add First Arrangement',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
