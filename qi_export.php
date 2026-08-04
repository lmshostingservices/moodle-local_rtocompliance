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

// qi_export.php — Export QI Report data as CSV.
// Created v4.0.64: was previously missing, causing "File not found" errors
// when clicking the "Export QI Report" button on the QI Report page.

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_surveys');
$context = context_system::instance();
require_capability('local/rtocompliance:managesurveys', $context);

$year = optional_param('year', date('Y'), PARAM_INT);

// Fetch all surveys for the requested year.
$surveys = $DB->get_records_sql(
    "SELECT id, surveytype, respondentname, respondentemail, qualificationcode,
            responses, overallsatisfaction, comments, status, timecreated, timecompleted
     FROM {local_rtocompliance_surveys}
     WHERE year = ?
     ORDER BY surveytype, timecompleted, timecreated",
    [$year]
);

// PROBLEM 8 FIX: Moodle may buffer output (debug messages, session cookies, etc.) before
// this point. Discard the entire output buffer before sending raw CSV headers so the
// browser receives a clean CSV file and not an HTML page prepended to the data.
while (ob_get_level()) {
    ob_end_clean();
}

// Build CSV output.
$filename = 'qi_report_' . $year . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility.
fwrite($out, "\xEF\xBB\xBF");

// Header row.
fputcsv($out, [
    'Year',
    'Survey Type',
    'Respondent Name',
    'Respondent Email',
    'Qualification Code',
    'Status',
    'Overall Satisfaction (1-5)',
    'Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6', 'Q7', 'Q8',
    'Additional Comments',
    'Date Sent',
    'Date Completed',
]);

foreach ($surveys as $s) {
    $responses = [];
    if (!empty($s->responses)) {
        $decoded = json_decode($s->responses, true);
        if (is_array($decoded)) {
            $responses = $decoded;
        }
    }

    fputcsv($out, [
        $year,
        ucfirst($s->surveytype),
        $s->respondentname ?? '',
        $s->respondentemail ?? '',
        $s->qualificationcode ?? '',
        ucfirst($s->status),
        $s->overallsatisfaction ?? '',
        $responses['q1'] ?? '',
        $responses['q2'] ?? '',
        $responses['q3'] ?? '',
        $responses['q4'] ?? '',
        $responses['q5'] ?? '',
        $responses['q6'] ?? '',
        $responses['q7'] ?? '',
        $responses['q8'] ?? '',
        $s->comments ?? '',
        $s->timecreated  ? date('d/m/Y', $s->timecreated)  : '',
        $s->timecompleted ? date('d/m/Y', $s->timecompleted) : '',
    ]);
}

fclose($out);
exit;
