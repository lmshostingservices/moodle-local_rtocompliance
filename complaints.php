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

admin_externalpage_setup('local_rtocompliance_complaints');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$tab = optional_param('tab', 'complaints', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/complaints.php', ['tab' => $tab]);
$PAGE->set_title(get_string('complaints_appeals', 'local_rtocompliance'));
$PAGE->set_heading(get_string('complaints_appeals', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('complaints_appeals', 'local_rtocompliance'), null, null, 'complaints');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Complaints & Appeals Register');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/complaint_edit.php'),
    'Lodge New Complaint',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

// ── Quick Statistics cards ────────────────────────────────────────────────────
// Complaints and appeals live in separate tables — no 'type' discriminator column.
$_c_total    = $DB->count_records('local_rtocompliance_complaints');
$_c_open     = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_complaints} WHERE status NOT IN ('resolved','closed','withdrawn')");
$_a_total    = $DB->count_records('local_rtocompliance_appeals');
$_a_open     = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_appeals} WHERE status NOT IN ('resolved','closed','withdrawn','decided')");
$_ci_total   = $DB->count_records('local_rtocompliance_improvements');
$_ci_open    = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_improvements} WHERE status NOT IN ('completed','closed')");
$_c_highpri  = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_complaints} WHERE priority IN ('high','critical')");
$_c_thisyear = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_complaints} WHERE datereceived >= :ys", ['ys' => mktime(0, 0, 0, 1, 1, (int)date('Y'))]);

echo html_writer::start_div('stats-cards');
foreach ([
    ['label' => 'Total Complaints',          'value' => $_c_total,   'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('list')],
    ['label' => 'Open Complaints',           'value' => $_c_open,    'color' => $_c_open     > 0 ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Total Appeals',             'value' => $_a_total,   'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('file')],
    ['label' => 'Open Appeals',              'value' => $_a_open,    'color' => $_a_open     > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Improvement Actions Open',  'value' => $_ci_open,   'color' => $_ci_open    > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Total Improvement Actions', 'value' => $_ci_total,  'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('check')],
    ['label' => 'High / Critical Priority',  'value' => $_c_highpri, 'color' => $_c_highpri  > 0 ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Logged This Year',          'value' => $_c_thisyear,'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('calendar')],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'],  ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'ASQA Practice Guide Compliance');
echo html_writer::tag('p', 'Track complaints from receipt through investigation, resolution, and appeal. Link findings to continuous improvement actions. Supports anonymous complaints and systemic issue identification.');
echo html_writer::end_div();

echo html_writer::start_div('tab-nav');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/complaints.php', ['tab' => 'complaints']),
    'Complaints',
    ['class' => $tab == 'complaints' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/complaints.php', ['tab' => 'appeals']),
    'Appeals',
    ['class' => $tab == 'appeals' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/complaints.php', ['tab' => 'improvement']),
    'Continuous Improvement',
    ['class' => $tab == 'improvement' ? 'active' : '']
);
echo html_writer::end_div();

if ($tab == 'complaints') {
    $complaints = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_complaints')) {
        $complaints = $DB->get_records('local_rtocompliance_complaints', null, 'datereceived DESC', '*', 0, 50);
    }

    if ($complaints) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Reference');
        echo html_writer::tag('th', 'Date Received');
        echo html_writer::tag('th', 'Category');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Priority');
        echo html_writer::tag('th', 'Assigned To');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($complaints as $complaint) {
            $statusclass = 'badge-info';
            if ($complaint->status == 'resolved') $statusclass = 'badge-success';
            if ($complaint->status == 'investigating') $statusclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $complaint->reference));
            echo html_writer::tag('td', userdate($complaint->datereceived, '%d %b %Y'));
            echo html_writer::tag('td', ucfirst($complaint->category));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($complaint->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td', ucfirst($complaint->priority));
            echo html_writer::tag('td', $complaint->assignedto ?: '-');
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/complaint_edit.php', ['id' => $complaint->id]),
                    'View',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/risk_xp', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Complaints Recorded');
        echo html_writer::tag('p', 'Complaints will appear here when they are lodged. Track the full lifecycle from receipt through resolution.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/complaint_edit.php'),
            'Lodge First Complaint',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
} elseif ($tab == 'appeals') {
    echo html_writer::start_div('tab-header');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/appeal_edit.php'),
        'Lodge New Appeal',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();

    $appeals = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_appeals')) {
        $appeals = $DB->get_records('local_rtocompliance_appeals', null, 'datelodged DESC', '*', 0, 50);
    }

    if ($appeals) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Reference');
        echo html_writer::tag('th', 'Type');
        echo html_writer::tag('th', 'Date Lodged');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Outcome');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($appeals as $appeal) {
            $statusclass = 'badge-info';
            if ($appeal->status == 'decided') $statusclass = 'badge-success';
            if ($appeal->outcome == 'not_upheld') $statusclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $appeal->reference));
            echo html_writer::tag('td', ucfirst(str_replace('_', ' ', $appeal->appealtype)));
            echo html_writer::tag('td', userdate($appeal->datelodged, '%d %b %Y'));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($appeal->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td', $appeal->outcome ? ucfirst(str_replace('_', ' ', $appeal->outcome)) : '-');
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/appeal_edit.php', ['id' => $appeal->id]),
                    'View',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/flagged', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Appeals Recorded');
        echo html_writer::tag('p', 'Appeals against complaint decisions will appear here.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/appeal_edit.php'),
            'Lodge First Appeal',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
} else {
    echo html_writer::start_div('tab-header');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/improvement_edit.php'),
        'Add Improvement Action',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();

    $improvements = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_improvements')) {
        $improvements = $DB->get_records('local_rtocompliance_improvements', null, 'dateidentified DESC', '*', 0, 50);
    }

    if ($improvements) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Reference');
        echo html_writer::tag('th', 'Title');
        echo html_writer::tag('th', 'Source');
        echo html_writer::tag('th', 'Date Identified');
        echo html_writer::tag('th', 'Target Date');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($improvements as $item) {
            $statusclass = 'badge-info';
            if ($item->status == 'completed' || $item->status == 'verified') $statusclass = 'badge-success';
            if ($item->status == 'inprogress') $statusclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $item->reference));
            echo html_writer::tag('td', format_string($item->title));
            echo html_writer::tag('td', ucfirst($item->sourcetype));
            echo html_writer::tag('td', userdate($item->dateidentified, '%d %b %Y'));
            echo html_writer::tag('td', $item->targetdate ? userdate($item->targetdate, '%d %b %Y') : '-');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($item->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/improvement_edit.php', ['id' => $item->id]),
                    'Edit',
                    ['class' => 'btn btn-sm btn-secondary']
                )
            );
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/reload', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Improvement Actions');
        echo html_writer::tag('p', 'Continuous improvement actions linked to complaints, validations, and audits will appear here.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/improvement_edit.php'),
            'Add Improvement Action',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
