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
 * RTO Compliance plugin — tas.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_tas');
require_login();

// Handle TAS delete action (site:config required)
$delete = optional_param('delete', 0, PARAM_INT);
if ($delete && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    if ($DB->get_manager()->table_exists('local_rtocompliance_tas')) {
        $DB->delete_records('local_rtocompliance_tas', ['id' => $delete]);
    }
    redirect(
        new moodle_url('/local/rtocompliance/tas.php'),
        get_string('tas_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_title(get_string('tas', 'local_rtocompliance'));
$PAGE->set_heading(get_string('tas', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('tas', 'local_rtocompliance'), null, null, 'tas');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Training & Assessment Strategy Generator');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/tas_edit.php'),
    'Create New TAS',
    ['class' => 'btn btn-primary', 'title' => 'Start a new Training and Assessment Strategy document']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'ASQA 2025 Compliant TAS Documents');
echo html_writer::tag('p', 'Generate comprehensive Training and Assessment Strategy documents with all 9 ASQA mandated sections. Includes auto-calculated volume of learning, trainer credential verification, assessment methods checklist, industry consultation evidence, and version control.');
echo html_writer::end_div();

echo html_writer::tag('h3', '9 Required TAS Sections', ['class' => 'section-title']);

$sections = [
    ['num' => 1, 'title' => 'RTO & Training Product Details', 'desc' => 'RTO information, qualification details, scope'],
    ['num' => 2, 'title' => 'Target Learner Cohort & Entry Requirements', 'desc' => 'Student demographics, LLN requirements, prerequisites'],
    ['num' => 3, 'title' => 'Industry Consultation', 'desc' => 'Industry engagement evidence, job role alignment'],
    ['num' => 4, 'title' => 'Delivery Structure & Volume of Learning', 'desc' => 'Nominal hours, delivery modes, scheduling'],
    ['num' => 5, 'title' => 'Assessment Plan', 'desc' => 'Assessment methods and mapping'],
    ['num' => 6, 'title' => 'Trainer & Assessor Requirements', 'desc' => 'Credential policy compliance, supervision arrangements'],
    ['num' => 7, 'title' => 'Learning Resources & Equipment', 'desc' => 'Materials, facilities, technology requirements'],
    ['num' => 8, 'title' => 'Work Placement Requirements', 'desc' => 'Practical placement hours, supervision'],
    ['num' => 9, 'title' => 'TAS Approval & Review', 'desc' => 'Version history, approval signatures'],
];

$module_colours = ['teal', 'blue', 'amber', 'green', 'rose', 'purple'];
echo html_writer::start_div('modules-grid', ['style' => 'margin-bottom: 2rem;']);
foreach ($sections as $i => $section) {
    $colour = $module_colours[$i % count($module_colours)];
    $anchorAttrs = [
        'href' => (new moodle_url('/local/rtocompliance/tas_edit.php'))->out(false) . '#tas-section-' . $section['num'],
        'class' => 'module-card module-' . $colour,
    ];
    if ($section['num'] === 7) {
        $anchorAttrs['id'] = 'tas-section-7';
    }
    echo html_writer::start_tag('a', $anchorAttrs);
    echo html_writer::start_div('');
    echo html_writer::tag('div', 'Section ' . $section['num'], ['class' => 'tas-section-badge']);
    echo html_writer::tag('div', $section['title'], ['class' => 'module-title']);
    echo html_writer::tag('div', $section['desc'], ['class' => 'module-desc']);
    echo html_writer::end_div();
    echo html_writer::end_tag('a');
}
echo html_writer::end_div();

echo html_writer::tag('h3', 'Existing TAS Documents', ['class' => 'section-title']);

$documents = [];
if ($DB->get_manager()->table_exists('local_rtocompliance_tas')) {
    $documents = $DB->get_records('local_rtocompliance_tas', null, 'timemodified DESC', '*', 0, 20);
}

if ($documents) {
    echo html_writer::start_tag('table', ['class' => 'data-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Qualification', ['title' => 'Qualification code and title this TAS document covers']);
    echo html_writer::tag('th', 'Version', ['title' => 'Version number of this TAS document']);
    echo html_writer::tag('th', 'Completeness', ['title' => 'Percentage of the 9 required TAS sections that have been completed']);
    echo html_writer::tag('th', 'Last Modified', ['title' => 'Date this TAS document was last edited']);
    echo html_writer::tag('th', 'Status', ['title' => 'Workflow status of this TAS: draft, review or approved']);
    echo html_writer::tag('th', 'Actions', ['title' => 'Actions available for this TAS document']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($documents as $doc) {
        $statusclass = 'badge-success';
        if ($doc->status == 'draft') $statusclass = 'badge-warning';
        if ($doc->status == 'review') $statusclass = 'badge-info';

        $statustitle = 'Approved: this TAS (training and assessment strategy) has been checked and signed off for use.';
        if ($doc->status == 'draft') $statustitle = 'Draft: this TAS (training and assessment strategy) is still being written and is not final.';
        if ($doc->status == 'review') $statustitle = 'Review: this TAS (training and assessment strategy) is written and is being checked before sign-off.';

        $completeness = $doc->completeness ?? 0;
        $completenessclass = 'badge-danger';
        if ($completeness >= 80) $completenessclass = 'badge-success';
        elseif ($completeness >= 50) $completenessclass = 'badge-warning';

        $completenesstitle = 'How much of the TAS (training and assessment strategy) is filled in &ndash; the share of the 9 required sections completed. Green is nearly done, amber is part way, red means only a little is done.';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', 
            html_writer::tag('code', $doc->qualificationcode) . '<br>' . 
            html_writer::tag('small', format_string($doc->qualificationname))
        );
        echo html_writer::tag('td', 'v' . $doc->version);
        echo html_writer::tag('td', html_writer::tag('span', $completeness . '%', ['class' => 'badge ' . $completenessclass, 'title' => $completenesstitle]));
        echo html_writer::tag('td', userdate($doc->timemodified, '%d %b %Y'));
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($doc->status), ['class' => 'badge ' . $statusclass, 'title' => $statustitle]));
        $canDelete = has_capability('moodle/site:config', context_system::instance());
        $deleteUrl = new moodle_url('/local/rtocompliance/tas.php', ['delete' => $doc->id, 'sesskey' => sesskey()]);
        echo html_writer::tag('td',
            html_writer::link(new moodle_url('/local/rtocompliance/tas_edit.php', ['id' => $doc->id]), 'Edit', ['class' => 'btn btn-sm btn-secondary', 'title' => 'Open this TAS to edit its sections']) . ' ' .
            html_writer::link(new moodle_url('/local/rtocompliance/tas_export.php', ['id' => $doc->id]), 'Export', ['class' => 'btn btn-sm btn-outline-primary', 'title' => 'Export this TAS document to a file']) .
            ($canDelete ? ' ' . html_writer::link($deleteUrl, 'Delete', [
                'class'   => 'btn btn-sm btn-danger',
                'title'   => 'Permanently delete this TAS document',
                'onclick' => "return confirm('Delete this TAS document? This cannot be undone.')",
            ]) : '')
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::start_div('empty-state');
    echo $OUTPUT->pix_icon('i/manual_item', '', 'moodle', ['class' => 'empty-state-icon']);
    echo html_writer::tag('h3', 'No TAS Documents');
    echo html_writer::tag('p', 'Create your first Training and Assessment Strategy document with all 9 ASQA mandated sections.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/tas_edit.php'),
        'Create First TAS',
        ['class' => 'btn btn-primary', 'title' => 'Start a new Training and Assessment Strategy document']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
