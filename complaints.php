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
 * RTO Compliance plugin — complaints.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_complaints');
require_login();

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
    ['class' => 'btn btn-primary', 'title' => 'Lodge a new complaint in the register']
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
    ['label' => 'Total Complaints',          'value' => $_c_total,   'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('list'),     'tip' => 'Every complaint ever logged in this register, whether still open or already finished.'],
    ['label' => 'Open Complaints',           'value' => $_c_open,    'color' => $_c_open     > 0 ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert'),    'tip' => 'Complaints still being handled &ndash; not yet resolved, closed or withdrawn.'],
    ['label' => 'Total Appeals',             'value' => $_a_total,   'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('file'),     'tip' => 'Every appeal ever lodged. An appeal is a request to review a decision the person disagrees with.'],
    ['label' => 'Open Appeals',              'value' => $_a_open,    'color' => $_a_open     > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock'),    'tip' => 'Appeals still in progress &ndash; not yet decided or closed.'],
    ['label' => 'Improvement Actions Open',  'value' => $_ci_open,   'color' => $_ci_open    > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock'),    'tip' => 'Improvement tasks still under way &ndash; not yet completed or closed.'],
    ['label' => 'Total Improvement Actions', 'value' => $_ci_total,  'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('check'),    'tip' => 'Every improvement task ever recorded, finished or not. These are fixes made so problems do not happen again.'],
    ['label' => 'High / Critical Priority',  'value' => $_c_highpri, 'color' => $_c_highpri  > 0 ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert'),    'tip' => 'Complaints marked high or critical urgency that need faster attention.'],
    ['label' => 'Logged This Year',          'value' => $_c_thisyear,'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('calendar'), 'tip' => 'Complaints received since 1 January of this year.'],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color'], ['title' => $s['tip']]);
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
        echo html_writer::tag('th', 'Reference', ['title' => 'Unique complaint reference number']);
        echo html_writer::tag('th', 'Date Received', ['title' => 'Date the complaint was received']);
        echo html_writer::tag('th', 'Category', ['title' => 'Category or nature of the complaint']);
        echo html_writer::tag('th', 'Status', ['title' => 'Current stage in the complaint lifecycle']);
        echo html_writer::tag('th', 'Priority', ['title' => 'Assigned priority or urgency level']);
        echo html_writer::tag('th', 'Assigned To', ['title' => 'Staff member responsible for handling the complaint']);
        echo html_writer::tag('th', 'Actions', ['title' => 'View or manage this complaint']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($complaints as $complaint) {
            $statusclass = 'badge-info';
            if ($complaint->status == 'resolved') $statusclass = 'badge-success';
            if ($complaint->status == 'investigating') $statusclass = 'badge-warning';

            $statustitle = 'Open: this complaint has been logged and is still being handled.';
            if ($complaint->status == 'investigating') $statustitle = 'Investigating: this complaint is being looked into to find out what happened.';
            if ($complaint->status == 'resolved') $statustitle = 'Resolved: this complaint has been dealt with and finished.';
            if ($complaint->status == 'closed') $statustitle = 'Closed: this complaint is finished and needs no more action.';
            if ($complaint->status == 'withdrawn') $statustitle = 'Withdrawn: the person has taken this complaint back.';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $complaint->reference));
            echo html_writer::tag('td', userdate($complaint->datereceived, '%d %b %Y'));
            echo html_writer::tag('td', ucfirst($complaint->category));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($complaint->status), ['class' => 'badge ' . $statusclass, 'title' => $statustitle]));
            echo html_writer::tag('td', ucfirst($complaint->priority));
            echo html_writer::tag('td', $complaint->assignedto ?: '-');
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/complaint_edit.php', ['id' => $complaint->id]),
                    'View',
                    ['class' => 'btn btn-sm btn-secondary', 'title' => 'View the full complaint record']
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
            ['class' => 'btn btn-primary', 'title' => 'Lodge the first complaint in the register']
        );
        echo html_writer::end_div();
    }
} elseif ($tab == 'appeals') {
    echo html_writer::start_div('tab-header');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/appeal_edit.php'),
        'Lodge New Appeal',
        ['class' => 'btn btn-primary', 'title' => 'Lodge a new appeal']
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
        echo html_writer::tag('th', 'Reference', ['title' => 'Unique appeal reference number']);
        echo html_writer::tag('th', 'Type', ['title' => 'Type of appeal lodged']);
        echo html_writer::tag('th', 'Date Lodged', ['title' => 'Date the appeal was lodged']);
        echo html_writer::tag('th', 'Status', ['title' => 'Current stage in the appeal process']);
        echo html_writer::tag('th', 'Outcome', ['title' => 'Final decision or outcome of the appeal']);
        echo html_writer::tag('th', 'Actions', ['title' => 'View or manage this appeal']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($appeals as $appeal) {
            $statusclass = 'badge-info';
            if ($appeal->status == 'decided') $statusclass = 'badge-success';
            if ($appeal->outcome == 'not_upheld') $statusclass = 'badge-warning';

            $statustitle = 'Open: this appeal is still being considered.';
            if ($appeal->status == 'decided') $statustitle = 'Decided: a final decision has been made on this appeal.';
            if ($appeal->status == 'closed') $statustitle = 'Closed: this appeal is finished and needs no more action.';
            if ($appeal->status == 'withdrawn') $statustitle = 'Withdrawn: the person has taken this appeal back.';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $appeal->reference));
            echo html_writer::tag('td', ucfirst(str_replace('_', ' ', $appeal->appealtype)));
            echo html_writer::tag('td', userdate($appeal->datelodged, '%d %b %Y'));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($appeal->status), ['class' => 'badge ' . $statusclass, 'title' => $statustitle]));
            echo html_writer::tag('td', $appeal->outcome ? ucfirst(str_replace('_', ' ', $appeal->outcome)) : '-');
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/appeal_edit.php', ['id' => $appeal->id]),
                    'View',
                    ['class' => 'btn btn-sm btn-secondary', 'title' => 'View the full appeal record']
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
            ['class' => 'btn btn-primary', 'title' => 'Lodge the first appeal']
        );
        echo html_writer::end_div();
    }
} else {
    echo html_writer::start_div('tab-header');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/improvement_edit.php'),
        'Add Improvement Action',
        ['class' => 'btn btn-primary', 'title' => 'Add a continuous improvement action']
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
        echo html_writer::tag('th', 'Reference', ['title' => 'Unique improvement action reference number']);
        echo html_writer::tag('th', 'Title', ['title' => 'Short title of the improvement action']);
        echo html_writer::tag('th', 'Source', ['title' => 'Where this improvement action originated']);
        echo html_writer::tag('th', 'Date Identified', ['title' => 'Date the improvement need was identified']);
        echo html_writer::tag('th', 'Target Date', ['title' => 'Planned completion date for the action']);
        echo html_writer::tag('th', 'Status', ['title' => 'Current progress of the improvement action']);
        echo html_writer::tag('th', 'Actions', ['title' => 'Edit this improvement action']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($improvements as $item) {
            $statusclass = 'badge-info';
            if ($item->status == 'completed' || $item->status == 'verified') $statusclass = 'badge-success';
            if ($item->status == 'inprogress') $statusclass = 'badge-warning';

            $statustitle = 'Open: this improvement action has been identified but not started yet.';
            if ($item->status == 'inprogress') $statustitle = 'In progress: work on this improvement action is under way.';
            if ($item->status == 'completed') $statustitle = 'Completed: this improvement action has been done.';
            if ($item->status == 'verified') $statustitle = 'Verified: the action is done and has been checked to confirm it worked.';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('code', $item->reference));
            echo html_writer::tag('td', format_string($item->title));
            echo html_writer::tag('td', ucfirst($item->sourcetype));
            echo html_writer::tag('td', userdate($item->dateidentified, '%d %b %Y'));
            echo html_writer::tag('td', $item->targetdate ? userdate($item->targetdate, '%d %b %Y') : '-');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($item->status), ['class' => 'badge ' . $statusclass, 'title' => $statustitle]));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/improvement_edit.php', ['id' => $item->id]),
                    'Edit',
                    ['class' => 'btn btn-sm btn-secondary', 'title' => 'Edit this improvement action']
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
            ['class' => 'btn btn-primary', 'title' => 'Add a continuous improvement action']
        );
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
