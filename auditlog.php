<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_dashboard');
$context = context_system::instance();

$PAGE->set_url('/local/rtocompliance/auditlog.php');
$PAGE->set_title(get_string('auditlog', 'local_rtocompliance'));
$PAGE->set_heading(get_string('auditlog', 'local_rtocompliance'));


$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('auditlog', 'local_rtocompliance'));

echo html_writer::start_div('compliance-container');
echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('auditlog', 'local_rtocompliance'));
echo html_writer::end_div();

$totalcount = $DB->count_records('local_rtocompliance_log');

$logs = $DB->get_records_sql(
    "SELECT l.*, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
     FROM {local_rtocompliance_log} l
     LEFT JOIN {user} u ON u.id = l.userid
     ORDER BY l.timecreated DESC",
    [],
    $page * $perpage,
    $perpage
);

if ($logs) {
    echo html_writer::start_tag('table', ['class' => 'table', 'style' => 'background: white; border: 1px solid #e5e7eb; border-radius: 12px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('auditlog_time', 'local_rtocompliance'));
    echo html_writer::tag('th', get_string('auditlog_user', 'local_rtocompliance'));
    echo html_writer::tag('th', 'Component');
    echo html_writer::tag('th', get_string('auditlog_action', 'local_rtocompliance'));
    echo html_writer::tag('th', get_string('auditlog_details', 'local_rtocompliance'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($logs as $log) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($log->timecreated, '%d %b %Y %H:%M'));
        echo html_writer::tag('td', $log->firstname ? fullname($log) : 'System');
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($log->component), ['class' => 'status-badge status-ok']));
        echo html_writer::tag('td', $log->action);

        $details = '';
        if ($log->details) {
            $detailsarr = json_decode($log->details, true);
            if ($detailsarr) {
                // Bug O fix: HTML-encode each key and value before rendering.
                // html_writer::tag() outputs its content argument raw (no auto-escaping),
                // so a stored XSS payload in the details JSON would execute without s().
                $details = implode(', ', array_map(function ($k, $v) {
                    if (is_array($v)) {
                        $v = json_encode($v);
                    }
                    return s((string)$k) . ': ' . s((string)$v);
                }, array_keys($detailsarr), array_values($detailsarr)));
            }
        }
        echo html_writer::tag('td', html_writer::tag('small', $details, ['class' => 'text-muted']));
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
} else {
    echo html_writer::div(
        html_writer::tag('p', 'No audit log entries yet.') .
        html_writer::tag('p', 'Actions like issuing certificates, exporting NAT files, and managing trainers will be logged here.'),
        'no-deadlines'
    );
}

echo html_writer::end_div();

echo $OUTPUT->footer();
