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

admin_externalpage_setup('local_rtocompliance_feeprotection');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_title(get_string('feeprotection', 'local_rtocompliance'));
$PAGE->set_heading(get_string('feeprotection', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

// Check if table exists first
$tableexists = $DB->get_manager()->table_exists('local_rtocompliance_fees');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('feeprotection', 'local_rtocompliance'), null, null, 'feeprotection');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Fee Protection');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/feeprotection_edit.php'),
    'Add Student Fee Record',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card warning');
echo html_writer::tag('h4', '$1,500 Threshold Requirement');
echo html_writer::tag('p', 'RTOs must not accept more than $1,500 in prepaid fees before the student begins training, unless adequate fee protection measures are in place. Track protected accounts, bank guarantees, or TAS arrangements.');
echo html_writer::end_div();

$protectiontype = get_config('local_rtocompliance', 'feeprotectiontype');
$protectiondetails = get_config('local_rtocompliance', 'feeprotectiondetails');

echo html_writer::start_div('form-card');
echo html_writer::tag('h3', 'Fee Protection Arrangement', ['style' => 'margin: 0 0 16px 0;']);

if ($protectiontype) {
    $types = [
        'protected_account' => 'Protected Account',
        'bank_guarantee' => 'Bank Guarantee',
        'tas_arrangement' => 'TAS Arrangement (Tuition Assurance)',
        'threshold_compliant' => 'Threshold Compliant (No fees >$1,500)',
    ];
    echo html_writer::tag('p', '<strong>Type:</strong> ' . ($types[$protectiontype] ?? $protectiontype));
    echo html_writer::tag('p', '<strong>Details:</strong> ' . format_string($protectiondetails));
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_asqa2025']),
        'Update Settings',
        ['class' => 'btn btn-sm btn-secondary']
    );
} else {
    echo html_writer::tag('p', 'No fee protection arrangement configured.', ['class' => 'text-muted']);
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_asqa2025']),
        'Configure Fee Protection',
        ['class' => 'btn btn-primary']
    );
}
echo html_writer::end_div();

$fees = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_fees')) {
    $fees = $DB->get_records_sql(
        "SELECT f.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, c.fullname as coursename
         FROM {local_rtocompliance_fees} f
         JOIN {user} u ON u.id = f.userid
         LEFT JOIN {course} c ON c.id = f.courseid
         WHERE f.amount > 1500 OR f.thresholdalert = 1
         ORDER BY f.paymentdate DESC",
        [],
        0,
        50
    );
}

echo html_writer::tag('h3', 'Students Approaching/Exceeding Threshold', ['class' => 'section-title', 'style' => 'margin-top: 32px;']);

if ($fees) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Student');
    echo html_writer::tag('th', 'Course');
    echo html_writer::tag('th', 'Total Fees');
    echo html_writer::tag('th', 'Date Received');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($fees as $fee) {
        $statusclass = 'badge-success';
        if ($fee->amount > 1500 && !$fee->isprotected) {
            $statusclass = 'badge-danger';
        } elseif ($fee->amount > 1200) {
            $statusclass = 'badge-warning';
        }

        $status = 'OK';
        if ($fee->amount > 1500 && !$fee->isprotected) {
            $status = 'Exceeds Threshold';
        } elseif ($fee->amount > 1200) {
            $status = 'Approaching';
        }

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', fullname($fee)));
        echo html_writer::tag('td', format_string($fee->coursename ?? '-'));
        echo html_writer::tag('td', '$' . number_format($fee->amount, 2));
        echo html_writer::tag('td', userdate($fee->paymentdate, '%d %b %Y'));
        echo html_writer::tag('td', html_writer::tag('span', $status, ['class' => 'badge ' . $statusclass]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/feeprotection_edit.php', ['id' => $fee->id]),
                'View',
                ['class' => 'btn btn-sm btn-secondary']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/grade_correct', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No Fee Alerts');
    echo html_writer::tag('p', 'Students with prepaid fees approaching or exceeding $1,500 will be flagged here.');
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
