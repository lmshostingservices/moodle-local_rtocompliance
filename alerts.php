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
 * RTO Compliance plugin — alerts.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/ai/compliance_predictor.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\ai\compliance_predictor;
use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_dashboard');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:viewreports', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]));
$PAGE->set_title('Compliance Alerts');
$PAGE->navbar->add('Compliance Alerts');

$predictor = new compliance_predictor();

if ($action === 'scan' && confirm_sesskey()) {
    $alerts = $predictor->run_predictive_analysis();
    audit_logger::log('scan', audit_logger::ENTITY_ALERT, 0, null, 'Compliance scan executed', null, ['alerts_count' => count($alerts)]);
    redirect(
        new moodle_url('/local/rtocompliance/alerts.php'),
        count($alerts) . ' compliance checks completed. ' . count($alerts) . ' alerts generated/updated.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'acknowledge' && $id > 0 && confirm_sesskey()) {
    global $DB;
    // BUG-3 FIX: Table is local_rtocompliance_ai_alerts (not local_rtocompliance_alerts).
    $alert = $DB->get_record('local_rtocompliance_ai_alerts', ['id' => $id]);
    if (!$alert) {
        audit_logger::log('error', audit_logger::ENTITY_ALERT, $id, null, 'Acknowledge failed: Alert not found', null, ['attempted_action' => 'acknowledge', 'user_id' => $USER->id, 'timestamp' => time()]);
        throw new moodle_exception('alertnotfound', 'local_rtocompliance', new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]), null, 'Alert ID: ' . $id);
    }
    $oldstatus = $alert->status;
    $predictor->acknowledge_alert($id, $USER->id);
    audit_logger::log_update(
        audit_logger::ENTITY_ALERT,
        $id,
        'Alert acknowledged: ' . $alert->alerttype . ' - Severity: ' . $alert->severity,
        ['status' => $oldstatus, 'severity' => $alert->severity, 'type' => $alert->alerttype, 'title' => $alert->title ?? ''],
        ['status' => 'acknowledged', 'acknowledged_by' => $USER->id, 'acknowledged_at' => time()]
    );
    redirect(
        new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]),
        'Alert acknowledged.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'resolve' && $id > 0 && confirm_sesskey()) {
    global $DB;
    $notes = optional_param('notes', '', PARAM_TEXT);
    $alert = $DB->get_record('local_rtocompliance_ai_alerts', ['id' => $id]);
    if (!$alert) {
        audit_logger::log('error', audit_logger::ENTITY_ALERT, $id, null, 'Resolve failed: Alert not found', null, ['attempted_action' => 'resolve', 'user_id' => $USER->id, 'timestamp' => time()]);
        throw new moodle_exception('alertnotfound', 'local_rtocompliance', new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]), null, 'Alert ID: ' . $id);
    }
    $oldstatus = $alert->status;
    $predictor->resolve_alert($id, $USER->id, $notes);
    audit_logger::log_update(
        audit_logger::ENTITY_ALERT,
        $id,
        'Alert resolved: ' . $alert->alerttype . ' - Severity: ' . $alert->severity,
        ['status' => $oldstatus, 'severity' => $alert->severity, 'type' => $alert->alerttype, 'title' => $alert->title ?? ''],
        ['status' => 'resolved', 'resolved_by' => $USER->id, 'resolved_at' => time(), 'resolution_notes' => $notes]
    );
    redirect(
        new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]),
        'Alert resolved.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'dismiss' && $id > 0 && confirm_sesskey()) {
    global $DB;
    $alert = $DB->get_record('local_rtocompliance_ai_alerts', ['id' => $id]);
    if (!$alert) {
        audit_logger::log('error', audit_logger::ENTITY_ALERT, $id, null, 'Dismiss failed: Alert not found', null, ['attempted_action' => 'dismiss', 'user_id' => $USER->id, 'timestamp' => time()]);
        throw new moodle_exception('alertnotfound', 'local_rtocompliance', new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]), null, 'Alert ID: ' . $id);
    }
    $oldstatus = $alert->status;
    $predictor->dismiss_alert($id);
    audit_logger::log_update(
        audit_logger::ENTITY_ALERT,
        $id,
        'Alert dismissed: ' . $alert->alerttype . ' - Severity: ' . $alert->severity,
        ['status' => $oldstatus, 'severity' => $alert->severity, 'type' => $alert->alerttype, 'title' => $alert->title ?? ''],
        ['status' => 'dismissed', 'dismissed_by' => $USER->id, 'dismissed_at' => time()]
    );
    redirect(
        new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $filter]),
        'Alert dismissed.',
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Compliance Alerts');
echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Predictive Compliance Alerts');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/alerts.php', ['action' => 'scan', 'sesskey' => sesskey()]),
    'Run Compliance Scan',
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

$summary = $predictor->get_compliance_summary();

$statuscolor = '#22c55e';
$statusicon = '✓';
if ($summary['overall_status'] === 'critical') {
    $statuscolor = '#ef4444';
    $statusicon = '!';
} elseif ($summary['overall_status'] === 'warning') {
    $statuscolor = '#f59e0b';
    $statusicon = '⚠';
} elseif ($summary['overall_status'] === 'attention') {
    $statuscolor = '#3b82f6';
    $statusicon = 'i';
}

$statusCardColor = 'green';
if ($summary['overall_status'] === 'critical')  { $statusCardColor = 'rose'; }
elseif ($summary['overall_status'] === 'warning') { $statusCardColor = 'amber'; }
elseif ($summary['overall_status'] === 'attention') { $statusCardColor = 'blue'; }

echo html_writer::start_div('form-card', ['style' => 'margin-bottom: 24px;']);
echo html_writer::start_div('stats-cards');

echo html_writer::start_div('stat-card stat-' . $statusCardColor);
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('activity') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', ucfirst($summary['overall_status']), ['class' => 'stat-number']);
echo html_writer::tag('div', 'Overall Status', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-amber');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('target') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $summary['risk_score'] . '%', ['class' => 'stat-number']);
echo html_writer::tag('div', 'Risk Score', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-' . ($summary['by_severity']['critical'] > 0 ? 'rose' : 'green'));
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('alert') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $summary['by_severity']['critical'], ['class' => 'stat-number']);
echo html_writer::tag('div', 'Critical', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-' . ($summary['by_severity']['high'] > 0 ? 'amber' : 'green'));
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('alert') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $summary['by_severity']['high'], ['class' => 'stat-number']);
echo html_writer::tag('div', 'High', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-blue');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('shield') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $summary['by_severity']['medium'], ['class' => 'stat-number']);
echo html_writer::tag('div', 'Medium', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('stat-card stat-green');
echo '<div class="stat-icon-wrap">' . local_rtocompliance_stat_icon('check') . '</div>';
echo html_writer::start_div('stat-info');
echo html_writer::tag('div', $summary['by_severity']['low'], ['class' => 'stat-number']);
echo html_writer::tag('div', 'Low', ['class' => 'stat-label']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('filter-tabs', ['id' => 'filters', 'style' => 'margin-bottom: 20px; display: flex; gap: 8px; flex-wrap: wrap;']);
$filters = ['all' => 'All', 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
foreach ($filters as $key => $label) {
    $class = $filter === $key ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-secondary';
    $url = new moodle_url('/local/rtocompliance/alerts.php', ['filter' => $key]);
    echo html_writer::link(
        $url->out(false) . '#filters',
        $label,
        ['class' => $class]
    );
}
echo html_writer::end_div();

$severityfilter = $filter === 'all' ? null : $filter;
$alerts = $predictor->get_active_alerts($severityfilter);

if ($alerts) {
    echo html_writer::start_div('alerts-list');
    
    foreach ($alerts as $alert) {
        $severityclass = 'alert-info';
        $severitybg = '#f0f9ff';
        $severityborder = '#0ea5e9';
        
        if ($alert->severity === 'critical') {
            $severityclass = 'alert-critical';
            $severitybg = '#fef2f2';
            $severityborder = '#ef4444';
        } elseif ($alert->severity === 'high') {
            $severityclass = 'alert-high';
            $severitybg = '#fffbeb';
            $severityborder = '#f59e0b';
        } elseif ($alert->severity === 'medium') {
            $severityclass = 'alert-medium';
            $severitybg = '#f0f9ff';
            $severityborder = '#3b82f6';
        } elseif ($alert->severity === 'low') {
            $severityclass = 'alert-low';
            $severitybg = '#f9fafb';
            $severityborder = '#6b7280';
        }
        
        echo html_writer::start_div('alert-card', [
            'style' => "background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);"
        ]);
        
        echo html_writer::start_div('', ['style' => 'display: flex; justify-content: space-between; align-items: flex-start;']);
        
        echo html_writer::start_div('');
        echo html_writer::tag('span', ucfirst($alert->severity), [
            'class' => 'badge',
            'style' => "background: $severityborder; color: white; margin-right: 8px;"
        ]);
        echo html_writer::tag('span', $alert->alerttype, [
            'class' => 'badge badge-secondary',
            'style' => 'margin-right: 8px;'
        ]);
        if (!empty($alert->daysuntildue)) {
            $daystext = $alert->daysuntildue <= 0 ? 'Overdue' : $alert->daysuntildue . ' days left';
            echo html_writer::tag('span', $daystext, ['class' => 'text-muted', 'style' => 'font-size: 0.875rem;']);
        }
        echo html_writer::end_div();
        
        if (!empty($alert->riskscore)) {
            echo html_writer::tag('span', 'Risk: ' . round($alert->riskscore) . '%', [
                'style' => 'font-weight: 600; color: ' . $severityborder . ';'
            ]);
        }
        
        echo html_writer::end_div();
        
        echo html_writer::tag('h4', format_string($alert->title), ['style' => 'margin: 12px 0 8px 0;']);
        echo html_writer::tag('p', format_text($alert->description, FORMAT_PLAIN), ['style' => 'margin: 0 0 12px 0; color: #374151;']);
        
        if (!empty($alert->recommendation)) {
            echo html_writer::tag('p', html_writer::tag('strong', 'Recommended Action: ') . format_text($alert->recommendation, FORMAT_PLAIN), [
                'style' => 'margin: 0 0 12px 0; font-size: 0.9rem; color: #4b5563;'
            ]);
        }
        
        echo html_writer::start_div('', ['style' => 'display: flex; gap: 8px; margin-top: 12px;']);
        
        if ($alert->status === 'active') {
            echo html_writer::link(
                new moodle_url('/local/rtocompliance/alerts.php', ['action' => 'acknowledge', 'id' => $alert->id, 'filter' => $filter, 'sesskey' => sesskey()]),
                'Acknowledge',
                ['class' => 'btn btn-sm btn-secondary']
            );
        }
        
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/alerts.php', ['action' => 'resolve', 'id' => $alert->id, 'filter' => $filter, 'sesskey' => sesskey()]),
            'Resolve',
            ['class' => 'btn btn-sm btn-primary']
        );
        
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/alerts.php', ['action' => 'dismiss', 'id' => $alert->id, 'filter' => $filter, 'sesskey' => sesskey()]),
            'Dismiss',
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
        
        echo html_writer::end_div();
        
        echo html_writer::end_div();
    }
    
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('empty-state');
    echo html_writer::tag('div', '✓', ['style' => 'font-size: 3rem; color: #22c55e; margin-bottom: 16px;']);
    echo html_writer::tag('h3', 'No Active Alerts');
    echo html_writer::tag('p', 'All compliance checks passed. Run a scan to check for new issues.');
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/alerts.php', ['action' => 'scan', 'sesskey' => sesskey()]),
        'Run Compliance Scan',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo $OUTPUT->footer();
