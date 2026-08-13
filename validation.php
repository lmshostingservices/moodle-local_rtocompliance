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

admin_externalpage_setup('local_rtocompliance_validation');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$tab = optional_param('tab', 'schedule', PARAM_ALPHA);

$PAGE->set_url('/local/rtocompliance/validation.php', ['tab' => $tab]);
$PAGE->set_title(get_string('validation', 'local_rtocompliance'));
$PAGE->set_heading(get_string('validation', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('validation', 'local_rtocompliance'), null, null, 'validation');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Validation Schedule & Events');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/validation_edit.php'),
    'Schedule Validation',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Risk-Based Prioritisation');
echo html_writer::tag('p', 'Schedule and conduct assessment validation events with risk-based prioritisation. Verify validator credentials (3A/3B requirements), track findings, and link to ADC evidence and continuous improvement.');
echo html_writer::end_div();

echo html_writer::start_div('tab-nav');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'schedule']),
    'Validation Schedule',
    ['class' => $tab == 'schedule' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'events']),
    'Completed Events',
    ['class' => $tab == 'events' ? 'active' : '']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']),
    'Validators',
    ['class' => $tab == 'validators' ? 'active' : '']
);
echo html_writer::end_div();

if ($tab == 'schedule') {
    $scheduled = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_validations')) {
        $scheduled = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_validations} WHERE status IN ('scheduled', 'in_progress') ORDER BY scheduleddate ASC"
        );
    }

    if ($scheduled) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Product/Assessment');
        echo html_writer::tag('th', 'Scheduled Date');
        echo html_writer::tag('th', 'Risk Level');
        echo html_writer::tag('th', 'Validator');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($scheduled as $item) {
            $daysuntil = ceil(($item->scheduleddate - time()) / 86400);
            $statusclass = 'badge-info';
            
            if ($daysuntil < 0) {
                $statusclass = 'badge-danger';
            } elseif ($daysuntil < 14) {
                $statusclass = 'badge-warning';
            }

            $riskclass = 'badge-success';
            if ($item->risklevel == 'high') $riskclass = 'badge-danger';
            if ($item->risklevel == 'medium') $riskclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($item->productname));
            echo html_writer::tag('td', userdate($item->scheduleddate, '%d %b %Y'));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($item->risklevel), ['class' => 'badge ' . $riskclass]));
            echo html_writer::tag('td', format_string($item->leadvalidator ?? '') ?: 'Unassigned');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst(str_replace('_', ' ', $item->status)), ['class' => 'badge ' . $statusclass]));
            $actions = html_writer::link(
                new moodle_url('/local/rtocompliance/validation_edit.php', ['id' => $item->id]),
                'Manage',
                ['class' => 'btn btn-sm btn-secondary']
            );
            $reporturl = trim($item->reportdocument ?? '');
            if ($reporturl && (strpos($reporturl, 'http://') === 0 || strpos($reporturl, 'https://') === 0)) {
                $actions .= '&nbsp;' . html_writer::link(
                    $reporturl,
                    'View Report',
                    ['class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer']
                );
            }
            echo html_writer::tag('td', $actions);
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/calendar', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Scheduled Validations');
        echo html_writer::tag('p', 'Schedule validation events for your assessment tools using risk-based prioritisation.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/validation_edit.php'),
            'Schedule First Validation',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
} elseif ($tab == 'events') {
    $completed = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_validations')) {
        $completed = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_validations} WHERE status = 'completed' ORDER BY actualdate DESC",
            [],
            0,
            50
        );
    }

    if ($completed) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Product/Assessment');
        echo html_writer::tag('th', 'Date Completed');
        echo html_writer::tag('th', 'Lead Validator');
        echo html_writer::tag('th', 'Sample Size');
        echo html_writer::tag('th', 'Findings');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($completed as $item) {
            $findingsclass = 'badge-success';
            if ($item->findingscount > 5) $findingsclass = 'badge-danger';
            elseif ($item->findingscount > 0) $findingsclass = 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($item->productname));
            echo html_writer::tag('td', $item->actualdate ? userdate($item->actualdate, '%d %b %Y') : '-');
            echo html_writer::tag('td', format_string($item->leadvalidator ?? '') ?: '-');
            echo html_writer::tag('td', ($item->samplesize ?: 0) . ' samples');
            echo html_writer::tag('td', html_writer::tag('span', $item->findingscount . ' items', ['class' => 'badge ' . $findingsclass]));
            $completedReportUrl = trim($item->reportdocument ?? '');
            $completedActions = '';
            if ($completedReportUrl && (strpos($completedReportUrl, 'http://') === 0 || strpos($completedReportUrl, 'https://') === 0)) {
                $completedActions .= html_writer::link(
                    $completedReportUrl,
                    'View Report',
                    ['class' => 'btn btn-sm btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer']
                ) . '&nbsp;';
            }
            $completedActions .= html_writer::link(
                new moodle_url('/local/rtocompliance/validation_edit.php', ['id' => $item->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-outline-secondary']
            );
            echo html_writer::tag('td', $completedActions);
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('empty-state');
        echo $OUTPUT->pix_icon('i/checkedcircle', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Completed Validations');
        echo html_writer::tag('p', 'Completed validation events with findings and outcomes will appear here.');
        echo html_writer::end_div();
    }
} else {
    $validators = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_validators')) {
        $validators = $DB->get_records('local_rtocompliance_validators', null, 'fullname ASC');
    }

    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('h4', 'Validator Credential Requirements');
    echo html_writer::tag('p', 'Validators must meet ASQA role 3A (industry expert) or 3B (assessment specialist) requirements. Track their credentials and verify eligibility before assigning to validation events.');
    echo html_writer::end_div();

    if ($validators) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Name');
        echo html_writer::tag('th', 'Role Type');
        echo html_writer::tag('th', 'Internal/External');
        echo html_writer::tag('th', 'TAE Credential');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($validators as $val) {
            $roleclass = strtoupper($val->roletype) == '3A' ? 'badge-info' : 'badge-purple';
            $statusclass = $val->status == 'active' ? 'badge-success' : 'badge-warning';

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', format_string($val->fullname)));
            echo html_writer::tag('td', html_writer::tag('span', strtoupper($val->roletype), ['class' => 'badge ' . $roleclass]));
            echo html_writer::tag('td', $val->isinternal ? 'Internal' : format_string($val->organisation));
            echo html_writer::tag('td', $val->taecredential ?: '-');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($val->status), ['class' => 'badge ' . $statusclass]));
            echo html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/rtocompliance/validator_edit.php', ['id' => $val->id]),
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
        echo $OUTPUT->pix_icon('i/user', '', 'moodle', ['class' => 'empty-state-icon']);
        echo html_writer::tag('h3', 'No Validators Registered');
        echo html_writer::tag('p', 'Add validators with their 3A/3B credentials to assign them to validation events.');
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/validator_edit.php'),
            'Add Validator',
            ['class' => 'btn btn-primary']
        );
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
