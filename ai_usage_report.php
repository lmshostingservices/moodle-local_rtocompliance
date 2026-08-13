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

admin_externalpage_setup('local_rtocompliance_ai_usage_report');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/rtocompliance/ai_usage_report.php');
$PAGE->set_title(get_string('ai_usage_report', 'local_rtocompliance'));
$PAGE->set_heading(get_string('ai_usage_report', 'local_rtocompliance'));
$PAGE->add_body_class('path-local-rtocompliance');

// Date range filter (days=0 = all time).
$days = optional_param('days', 0, PARAM_INT);
$allowed_days = [0, 7, 30, 90, 365];
if (!in_array($days, $allowed_days)) {
    $days = 0;
}

// Resolve API base URL and API key.
$_lib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($_lib) && !function_exists('local_aiconfig_get_apikey')) {
    require_once($_lib);
}
$apikey  = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

// Fetch full historical usage data from /api/rto/credit-usage-history.
$report      = null;
$fetch_error = '';
if ($apikey) {
    $url  = $apibase . '/api/rto/credit-usage-history?apiKey=' . rawurlencode($apikey) . '&days=' . $days;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
        $fetch_error = 'Connection error: ' . s($err);
    } else {
        $decoded = json_decode($raw, true);
        if (!empty($decoded['success'])) {
            $report = $decoded;
        } else {
            $fetch_error = !empty($decoded['error']) ? s($decoded['error']) : 'Unexpected API response.';
        }
    }
} else {
    $fetch_error = 'No API key configured. Set your API key in RTO Compliance settings or via AI Grader Central Config.';
}

// Local DB audit log summary.
$has_log_table = $DB->get_manager()->table_exists('local_rtocompliance_log');
$db_breakdown  = [];
$db_total      = 0;
if ($has_log_table) {
    $log_rows = $DB->get_records_sql(
        "SELECT component, COUNT(*) AS calls
         FROM {local_rtocompliance_log}
         GROUP BY component
         ORDER BY calls DESC
         LIMIT 20"
    );
    foreach ($log_rows as $row) {
        $db_breakdown[] = [
            'component' => $row->component,
            'label'     => ucwords(str_replace('_', ' ', $row->component)),
            'calls'     => (int)$row->calls,
        ];
        $db_total += (int)$row->calls;
    }
}

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('ai_usage_report', 'local_rtocompliance'));

echo html_writer::start_div('compliance-container');
echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('ai_usage_report', 'local_rtocompliance'));
echo html_writer::tag('p',
    get_string('ai_usage_report_desc', 'local_rtocompliance'),
    ['style' => 'color:#fff;opacity:0.85;margin:4px 0 0;']
);
echo html_writer::end_div();

// ── Date range filter tabs ────────────────────────────────────────────────────
$base_url = new moodle_url('/local/rtocompliance/ai_usage_report.php');
echo html_writer::start_div('ai-usage-datefilter');
$range_labels = [0 => 'All Time', 7 => 'Last 7 Days', 30 => 'Last 30 Days', 90 => 'Last 90 Days', 365 => 'Last Year'];
foreach ($range_labels as $d => $lbl) {
    $url   = new moodle_url($base_url, ['days' => $d]);
    $cls   = ($days === $d) ? 'ai-usage-tab active' : 'ai-usage-tab';
    echo html_writer::link($url, $lbl, ['class' => $cls]);
}
echo html_writer::end_div();

// ── Stat cards ────────────────────────────────────────────────────────────────
if ($fetch_error) {
    echo html_writer::start_div('ai-usage-section');
    echo html_writer::div(
        html_writer::tag('strong', 'Could not load usage data: ') . $fetch_error,
        'ai-usage-error'
    );
    echo html_writer::end_div();
} else {
    $total_calls   = (int)($report['totalCalls']    ?? 0);
    $total_credits = (int)($report['totalCredits']  ?? 0);
    $remaining     = (int)($report['creditsRemaining'] ?? 0);
    $aud_cost      = number_format(($report['estimatedAUD'] ?? $total_credits * 0.10), 2);
    $range_label   = $days > 0 ? $range_labels[$days] : 'All Time';

    echo html_writer::start_div('ai-usage-section');
    echo html_writer::tag('h3', 'Credit Usage Summary — ' . s($range_label), ['class' => 'ai-usage-section-title']);
    echo html_writer::tag('p',
        'This report shows credits consumed by RTO Compliance plugin features only (TAS AI Suggest, ' .
        'Complaints/Appeals AI Draft, Compliance Auditor, Unit Mapping, etc.). ' .
        'Credits used by other AI Grader plugins (AI Grader, AI Knowledge Check, AI Content Creator, etc.) ' .
        'are not included — check the AI Grader admin portal for a full account-wide credit history.',
        ['class' => 'ai-usage-scope-note']
    );

    echo html_writer::start_div('ai-usage-stat-grid');
    $cards = [
        ['Total AI Calls',       $total_calls,             'stat-card'],
        ['Credits Used',         number_format($total_credits), 'stat-card'],
        ['Credits Remaining',    number_format($remaining), 'stat-card'],
        ['Est. Cost (AUD)',       '$' . $aud_cost,          'stat-card'],
    ];
    foreach ($cards as [$label, $value, $cls]) {
        echo html_writer::start_div('ai-usage-stat-card ' . $cls);
        echo html_writer::tag('div', s((string)$value), ['class' => 'stat-value']);
        echo html_writer::tag('div', $label, ['class' => 'stat-label']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div(); // stat-grid

    // ── Feature breakdown table ───────────────────────────────────────────────
    $breakdown = $report['breakdown'] ?? [];
    echo html_writer::tag('h4', 'Usage by Feature', ['class' => 'ai-usage-sub-title']);
    if (!empty($breakdown)) {
        echo html_writer::start_tag('table', ['class' => 'table ai-usage-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Feature', 'AI Calls', 'Credits Used', 'Est. Cost (AUD)', 'Share'] as $th) {
            echo html_writer::tag('th', $th);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($breakdown as $row) {
            $calls   = (int)($row['calls']   ?? 0);
            $credits = (int)($row['credits'] ?? 0);
            $pct     = $total_credits > 0 ? round($credits / $total_credits * 100) : 0;
            $bar     = html_writer::div(
                html_writer::div('', 'ai-usage-bar-fill', ['style' => 'width:' . $pct . '%']),
                'ai-usage-bar'
            );
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', s($row['label'] ?? $row['usageType'])));
            echo html_writer::tag('td', number_format($calls));
            echo html_writer::tag('td', number_format($credits));
            echo html_writer::tag('td', '$' . number_format($credits * 0.10, 2));
            echo html_writer::tag('td', $bar . html_writer::tag('span', $pct . '%', ['class' => 'pct-label']));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    } else {
        echo html_writer::div(
            html_writer::tag('p', 'No AI usage recorded for this period. Use AI Suggest, Compliance Auditor, Unit Mapping or other features to see data here.'),
            'ai-usage-empty'
        );
    }
    echo html_writer::end_div(); // section

    // ── Daily activity chart ──────────────────────────────────────────────────
    $daily = $report['daily'] ?? [];
    if (!empty($daily)) {
        $chart_labels = json_encode(array_column($daily, 'day'));
        $chart_calls  = json_encode(array_column($daily, 'calls'));
        $chart_credits = json_encode(array_column($daily, 'credits'));

        echo html_writer::start_div('ai-usage-section');
        echo html_writer::tag('h3', 'Daily Activity', ['class' => 'ai-usage-section-title']);
        echo html_writer::tag('canvas', '', ['id' => 'ai-usage-daily-chart', 'height' => '70']);
        $PAGE->requires->js_amd_inline("
require(['core/chartjs'], function (Chart) {
    var ctx = document.getElementById('ai-usage-daily-chart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$chart_labels},
            datasets: [
                {
                    label: 'AI Calls',
                    data: {$chart_calls},
                    backgroundColor: 'rgba(79, 110, 247, 0.7)',
                    borderColor: '#4f6ef7',
                    borderWidth: 1,
                    borderRadius: 3,
                    yAxisID: 'y'
                },
                {
                    label: 'Credits Used',
                    data: {$chart_credits},
                    type: 'line',
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    borderWidth: 2,
                    pointRadius: 2,
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } }
            },
            scales: {
                y:  { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'AI Calls' } },
                y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Credits' } }
            }
        }
    });
});
");
        echo html_writer::end_div();
    }

    // ── Recent events ─────────────────────────────────────────────────────────
    $recent = $report['recent'] ?? [];
    if (!empty($recent)) {
        echo html_writer::start_div('ai-usage-section');
        echo html_writer::tag('h3', 'Recent Activity (Last 20 Events)', ['class' => 'ai-usage-section-title']);
        echo html_writer::start_tag('table', ['class' => 'table ai-usage-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Date / Time', 'Feature', 'Credits'] as $th) {
            echo html_writer::tag('th', $th);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($recent as $row) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', s($row['ts'] ?? ''), ['style' => 'font-family:monospace;font-size:0.85rem;color:#6b7280;']);
            echo html_writer::tag('td', s($row['label'] ?? $row['usageType']));
            echo html_writer::tag('td', number_format((int)($row['credits'] ?? 0)));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
}

// ── Local audit log summary ───────────────────────────────────────────────────
echo html_writer::start_div('ai-usage-section');
echo html_writer::tag('h3', 'Local Moodle Audit Log', ['class' => 'ai-usage-section-title']);
if (!$has_log_table) {
    echo html_writer::div(html_writer::tag('p', 'Audit log table not available.'), 'ai-usage-empty');
} elseif (empty($db_breakdown)) {
    echo html_writer::div(html_writer::tag('p', 'No entries found in the local audit log.'), 'ai-usage-empty');
} else {
    echo html_writer::tag('p',
        'The local audit log records admin actions performed on this Moodle site, independent of API credit consumption.',
        ['style' => 'color:#6b7280;font-size:0.875rem;margin:0 0 12px;']
    );
    echo html_writer::start_tag('table', ['class' => 'table ai-usage-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    foreach (['Component', 'Logged Actions', 'Share'] as $th) {
        echo html_writer::tag('th', $th);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    foreach ($db_breakdown as $row) {
        $pct = $db_total > 0 ? round($row['calls'] / $db_total * 100) : 0;
        $bar = html_writer::div(
            html_writer::div('', 'ai-usage-bar-fill', ['style' => 'width:' . $pct . '%']),
            'ai-usage-bar'
        );
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', s($row['label'])));
        echo html_writer::tag('td', number_format($row['calls']));
        echo html_writer::tag('td', $bar . html_writer::tag('span', $pct . '%', ['class' => 'pct-label']));
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}
echo html_writer::end_div();

// ── Inline styles ─────────────────────────────────────────────────────────────
echo html_writer::tag('style', '
.ai-usage-datefilter {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.ai-usage-tab {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 500;
    color: #374151;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    text-decoration: none;
    transition: background 0.15s;
}
.ai-usage-tab:hover { background: #e5e7eb; color: #111827; text-decoration: none; }
.ai-usage-tab.active { background: #4f6ef7; color: #fff; border-color: #4f6ef7; }
.ai-usage-section {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 24px;
    margin-bottom: 20px;
}
.ai-usage-section-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 16px;
    color: #111827;
}
.ai-usage-sub-title {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 20px 0 10px;
    color: #374151;
}
.ai-usage-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.ai-usage-stat-card {
    border-radius: 8px;
    padding: 18px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top: 3px solid #4f6ef7;
}
.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1;
}
.stat-label {
    font-size: 0.73rem;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 2px;
}
.ai-usage-table { width: 100%; border-collapse: collapse; }
.ai-usage-table th {
    background: #f9fafb;
    padding: 8px 12px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid #e5e7eb;
}
.ai-usage-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.875rem;
    color: #374151;
    vertical-align: middle;
}
.ai-usage-bar {
    height: 5px;
    background: #f3f4f6;
    border-radius: 3px;
    margin-top: 5px;
    overflow: hidden;
    min-width: 60px;
}
.ai-usage-bar-fill {
    height: 100%;
    background: #4f6ef7;
    border-radius: 3px;
    transition: width 0.4s ease;
}
.pct-label {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-left: 4px;
}
.ai-usage-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 14px 16px;
    color: #b91c1c;
    font-size: 0.875rem;
}
.ai-usage-empty {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 24px;
    color: #6b7280;
    text-align: center;
    font-size: 0.875rem;
}
.ai-usage-scope-note {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    padding: 10px 14px;
    color: #1e40af;
    font-size: 0.8125rem;
    margin: 0 0 16px;
    line-height: 1.5;
}
');

echo html_writer::end_div(); // compliance-container
echo $OUTPUT->footer();
