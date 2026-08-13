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
 * RTO Compliance plugin — thirdparty.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_thirdparty');
require_login();
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
    ['class' => 'btn btn-primary', 'title' => 'Add a new third-party arrangement to the register']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card warning');
echo html_writer::tag('h4', 'Third-party arrangement requirements');
// FIX-THIRDPARTY-STATEMENT (v5.9.415): the previous text ("notify ASQA at least 30
// days BEFORE entering") was factually wrong — there is no 30-day advance-notice rule
// for third-party arrangements. Under the 2025 Standards the RTO must have a WRITTEN
// AGREEMENT with the third party and maintain quality oversight/responsibility for
// the training and assessment delivered on its behalf; ASQA is notified of changes
// through the material-change process, not 30 days in advance of each arrangement.
echo html_writer::tag('p', 'For any training or assessment delivered on your behalf you must have a written agreement in place and retain full responsibility for, and oversight of, its quality. Record each arrangement here and verify the mandatory clauses — the third party must not use the NRT logo, must not issue AQF certification documentation, and students must be told when a third party is involved in their delivery.');
echo html_writer::end_div();

$arrangements = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_thirdparty')) {
    $arrangements = $DB->get_records('local_rtocompliance_thirdparty', null, 'agreementstartdate DESC', '*', 0, 50);
}

if ($arrangements) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Organisation', ['title' => 'Name of the third-party organisation']);
    echo html_writer::tag('th', 'Type', ['title' => 'Type of arrangement (delivery, assessment or support)']);
    echo html_writer::tag('th', 'Start Date', ['title' => 'Date the written agreement starts']);
    echo html_writer::tag('th', 'End Date', ['title' => 'Date the agreement ends, or ongoing']);
    echo html_writer::tag('th', 'ASQA Notified', ['title' => 'Whether ASQA has been notified of this arrangement']);
    echo html_writer::tag('th', 'Clauses Verified', ['title' => 'Whether the mandatory agreement clauses have been verified (no NRT logo, no AQF certification, student transparency)']);
    echo html_writer::tag('th', 'Status', ['title' => 'Current status of the arrangement']);
    echo html_writer::tag('th', 'Actions', ['title' => 'Actions available for this record']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($arrangements as $arr) {
        $statusclass = 'badge-success';
        if ($arr->status == 'inactive') $statusclass = 'badge-warning';
        if ($arr->status == 'expired' || $arr->status == 'terminated') $statusclass = 'badge-danger';

        $statustitle = 'Active: this arrangement is current and in effect.';
        if ($arr->status == 'inactive') $statustitle = 'Inactive: this arrangement is paused or not currently in use.';
        if ($arr->status == 'expired') $statustitle = 'Expired: the agreement end date has passed.';
        if ($arr->status == 'terminated') $statustitle = 'Terminated: this arrangement has been ended.';

        $notified = $arr->asqanotified ? 'Yes' : 'No';
        $notifiedclass = $arr->asqanotified ? 'badge-success' : 'badge-danger';

        $clausesok = ($arr->mandatoryclausesnrtlogo && $arr->mandatoryclausesaqf && $arr->mandatoryclausestransparency);
        $clausesclass = $clausesok ? 'badge-success' : 'badge-warning';
        $clausestitle = $clausesok
            ? 'Complete: all the required agreement clauses have been checked and are in place.'
            : 'Incomplete: not all the required agreement clauses (no NRT logo, no AQF certificates, students told) have been checked yet.';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', format_string($arr->organisationname)));
        echo html_writer::tag('td', ucfirst($arr->arrangementtype));
        echo html_writer::tag('td', userdate($arr->agreementstartdate, '%d %b %Y'));
        echo html_writer::tag('td', $arr->agreementenddate ? userdate($arr->agreementenddate, '%d %b %Y') : 'Ongoing');
        echo html_writer::tag('td', html_writer::tag('span', $notified, ['class' => 'badge ' . $notifiedclass]));
        echo html_writer::tag('td', html_writer::tag('span', $clausesok ? 'Complete' : 'Incomplete', ['class' => 'badge ' . $clausesclass, 'title' => $clausestitle]));
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($arr->status), ['class' => 'badge ' . $statusclass, 'title' => $statustitle]));
        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/thirdparty_edit.php', ['id' => $arr->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-secondary', 'title' => 'Edit this third-party arrangement']
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
        ['class' => 'btn btn-primary', 'title' => 'Add your first third-party arrangement']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
