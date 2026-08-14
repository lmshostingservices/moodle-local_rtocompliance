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
 * RTO Compliance plugin — validation.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_validation');
require_login();

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
    ['class' => 'btn btn-primary', 'title' => 'Schedule a new validation event']
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
        echo html_writer::tag('th', 'Product/Assessment', ['title' => 'Training product or assessment being validated']);
        echo html_writer::tag('th', 'Scheduled Date', ['title' => 'Date the validation is scheduled to take place']);
        echo html_writer::tag('th', 'Risk Level', ['title' => 'Risk-based priority assigned to this validation']);
        echo html_writer::tag('th', 'Validator', ['title' => 'Validator assigned to lead this event']);
        echo html_writer::tag('th', 'Status', ['title' => 'Current status of the scheduled validation']);
        echo html_writer::tag('th', 'Actions', ['title' => 'Manage this validation']);
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
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($item->risklevel), ['class' => 'badge ' . $riskclass, 'title' => 'How urgent this validation is. Higher-risk products should be validated sooner and more often.']));
            echo html_writer::tag('td', format_string($item->leadvalidator ?? '') ?: 'Unassigned');
            echo html_writer::tag('td', html_writer::tag('span', ucfirst(str_replace('_', ' ', $item->status)), ['class' => 'badge ' . $statusclass, 'title' => 'Where this validation is up to. The colour warns you if the scheduled date is near (amber) or already past (red).']));
            $actions = html_writer::link(
                new moodle_url('/local/rtocompliance/validation_edit.php', ['id' => $item->id]),
                'Manage',
                ['class' => 'btn btn-sm btn-secondary', 'title' => 'Manage this scheduled validation']
            );
            $reporturl = trim($item->reportdocument ?? '');
            if ($reporturl && (strpos($reporturl, 'http://') === 0 || strpos($reporturl, 'https://') === 0)) {
                $actions .= '&nbsp;' . html_writer::link(
                    $reporturl,
                    'View Report',
                    ['class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Open the validation report in a new tab']
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
            ['class' => 'btn btn-primary', 'title' => 'Schedule the first validation event']
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
        echo html_writer::tag('th', 'Product/Assessment', ['title' => 'Training product or assessment that was validated']);
        echo html_writer::tag('th', 'Date Completed', ['title' => 'Date the validation event was completed']);
        echo html_writer::tag('th', 'Lead Validator', ['title' => 'Validator who led the completed event']);
        echo html_writer::tag('th', 'Sample Size', ['title' => 'Number of assessment samples reviewed']);
        echo html_writer::tag('th', 'Findings', ['title' => 'Number of findings raised during validation']);
        echo html_writer::tag('th', 'Next Due', ['title' => 'Date the next validation is due']);
        echo html_writer::tag('th', 'Actions', ['title' => 'View report or edit this validation']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($completed as $item) {
            $findingsclass = 'badge-success';
            if ($item->findingscount > 5) $findingsclass = 'badge-danger';
            elseif ($item->findingscount > 0) $findingsclass = 'badge-warning';

            // Standard 1.5 (T-P1-2): next-due date with OVERDUE / DUE SOON flags.
            $nextdue = !empty($item->nextduedate) ? (int) $item->nextduedate : 0;
            if ($nextdue <= 0) {
                $nextduecell = '-';
            } else {
                $nextduelabel = userdate($nextdue, '%d %b %Y');
                $daysleft = ceil(($nextdue - time()) / 86400);
                if ($daysleft < 0) {
                    $nextduecell = $nextduelabel . ' ' . html_writer::tag('span', 'OVERDUE', ['class' => 'badge badge-danger', 'title' => 'The next validation for this product is past its due date. Schedule it now.']);
                } elseif ($daysleft <= 180) {
                    $nextduecell = $nextduelabel . ' ' . html_writer::tag('span', 'DUE SOON', ['class' => 'badge badge-warning', 'title' => 'The next validation for this product is due within six months. Plan it in soon.']);
                } else {
                    $nextduecell = $nextduelabel;
                }
            }

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($item->productname));
            echo html_writer::tag('td', $item->actualdate ? userdate($item->actualdate, '%d %b %Y') : '-');
            echo html_writer::tag('td', format_string($item->leadvalidator ?? '') ?: '-');
            echo html_writer::tag('td', ($item->samplesize ?: 0) . ' samples');
            echo html_writer::tag('td', html_writer::tag('span', $item->findingscount . ' items', ['class' => 'badge ' . $findingsclass, 'title' => 'How many issues the validation found. Green is none, amber is a few, red is more than five to act on.']));
            echo html_writer::tag('td', $nextduecell);
            $completedReportUrl = trim($item->reportdocument ?? '');
            $completedActions = '';
            if ($completedReportUrl && (strpos($completedReportUrl, 'http://') === 0 || strpos($completedReportUrl, 'https://') === 0)) {
                $completedActions .= html_writer::link(
                    $completedReportUrl,
                    'View Report',
                    ['class' => 'btn btn-sm btn-primary', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'title' => 'Open the validation report in a new tab']
                ) . '&nbsp;';
            }
            $completedActions .= html_writer::link(
                new moodle_url('/local/rtocompliance/validation_edit.php', ['id' => $item->id]),
                'Edit',
                ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Edit this completed validation']
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

    // Standard 1.5 (T-P1-2): Coverage gaps. Read-only list of every training
    // product on scope (active qualifications in the Qual Builder) that has no
    // completed validation, or whose latest next-due date has already passed.
    // Products on scope come from {local_rtocompliance_qualbuilder}
    // (qualificationcode / qualificationname, status = 'active') and are matched
    // to validations by productcode.
    $onscope = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_qualbuilder')) {
        $onscope = $DB->get_records(
            'local_rtocompliance_qualbuilder',
            ['status' => 'active'],
            'qualificationcode ASC',
            'id, qualificationcode, qualificationname'
        );
    }

    // Latest next-due date per product code across completed validations.
    $latestdue = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_validations')) {
        $completedvals = $DB->get_records_sql(
            "SELECT id, productcode, nextduedate FROM {local_rtocompliance_validations} WHERE status = 'completed'"
        );
        foreach ($completedvals as $cv) {
            $code = trim((string) $cv->productcode);
            if ($code === '') {
                continue;
            }
            $due = (int) ($cv->nextduedate ?? 0);
            if (!array_key_exists($code, $latestdue) || $due > $latestdue[$code]) {
                $latestdue[$code] = $due;
            }
        }
    }

    $now = time();
    $gaps = [];
    foreach ($onscope as $q) {
        $code = trim((string) $q->qualificationcode);
        if (!array_key_exists($code, $latestdue)) {
            $gaps[] = ['q' => $q, 'reason' => 'No completed validation', 'class' => 'badge-danger'];
        } else if ($latestdue[$code] > 0 && $latestdue[$code] < $now) {
            $gaps[] = ['q' => $q, 'reason' => 'Validation lapsed (next-due passed ' . userdate($latestdue[$code], '%d %b %Y') . ')', 'class' => 'badge-warning'];
        }
    }

    echo html_writer::start_div('compliance-header');
    echo html_writer::tag('h3', 'Coverage Gaps');
    echo html_writer::end_div();
    echo html_writer::start_div('info-card warning');
    echo html_writer::tag('p', 'Training products on scope (active qualifications) with no completed validation, or whose five-year (two-year for high-risk) validation cycle has lapsed. This section is read-only.');
    echo html_writer::end_div();

    if ($gaps) {
        echo html_writer::start_div('rtoc-table-scroll');
        echo html_writer::start_tag('table', ['class' => 'data-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Product Code', ['title' => 'National code of the training product on scope']);
        echo html_writer::tag('th', 'Product Name', ['title' => 'Name of the training product on scope']);
        echo html_writer::tag('th', 'Coverage Status', ['title' => 'Why this product is flagged as a validation coverage gap']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($gaps as $gap) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($gap['q']->qualificationcode));
            echo html_writer::tag('td', format_string($gap['q']->qualificationname));
            echo html_writer::tag('td', html_writer::tag('span', $gap['reason'], ['class' => 'badge ' . $gap['class'], 'title' => 'Why this product needs attention. Schedule a validation to close the coverage gap.']));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    } else if ($onscope) {
        echo html_writer::start_div('info-card');
        echo html_writer::tag('p', 'All training products on scope have a current completed validation. No coverage gaps.');
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('info-card');
        echo html_writer::tag('p', 'No active training products found in the Qual Builder to assess coverage against.');
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
        echo html_writer::tag('th', 'Name', ['title' => 'Full name of the validator']);
        echo html_writer::tag('th', 'Role Type', ['title' => 'ASQA role 3A (industry expert) or 3B (assessment specialist)']);
        echo html_writer::tag('th', 'Internal/External', ['title' => 'Whether the validator is internal staff or an external party']);
        echo html_writer::tag('th', 'TAE Credential', ['title' => 'Training and assessment credential held']);
        echo html_writer::tag('th', 'Status', ['title' => 'Whether the validator is currently active']);
        echo html_writer::tag('th', 'Actions', ['title' => 'Edit this validator']);
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
                    ['class' => 'btn btn-sm btn-secondary', 'title' => 'Edit this validator']
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
            ['class' => 'btn btn-primary', 'title' => 'Add a new validator']
        );
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
