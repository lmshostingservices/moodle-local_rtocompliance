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
 * RTO Compliance plugin — auditlog.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_dashboard');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());
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
    echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;"><div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">Audit Log</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;"><div><strong>Time</strong> &mdash; when the action was recorded</div><div><strong>User</strong> &mdash; person who performed the action, or System</div><div><strong>Component</strong> &mdash; area of the plugin that raised the entry</div><div><strong>Action</strong> &mdash; operation that was carried out</div><div><strong>Details</strong> &mdash; additional context captured for the entry</div></div></div>';
    echo html_writer::start_tag('table', ['class' => 'table', 'style' => 'background: white; border: 1px solid #e5e7eb; border-radius: 12px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('auditlog_time', 'local_rtocompliance'), ['title' => 'Date and time the action was recorded']);
    echo html_writer::tag('th', get_string('auditlog_user', 'local_rtocompliance'), ['title' => 'User who performed the action, or System for automated events']);
    echo html_writer::tag('th', 'Component', ['title' => 'Area of the plugin that generated this log entry']);
    echo html_writer::tag('th', get_string('auditlog_action', 'local_rtocompliance'), ['title' => 'Operation that was carried out']);
    echo html_writer::tag('th', get_string('auditlog_details', 'local_rtocompliance'), ['title' => 'Additional context captured for this entry']);
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
