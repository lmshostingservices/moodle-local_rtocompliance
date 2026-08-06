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
require_once(__DIR__ . '/classes/nat_generator.php');

use local_rtocompliance\nat_generator;

admin_externalpage_setup('local_rtocompliance_natexport');
require_capability('local/rtocompliance:exportnat', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$year = optional_param('year', date('Y'), PARAM_INT);
$PAGE->set_title(get_string('natexport', 'local_rtocompliance'));
$PAGE->set_heading(get_string('nat_export_title', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$navheader = local_rtocompliance_render_nav_header(get_string('natexport', 'local_rtocompliance'), null, null, 'natexport');

if ($action === 'validate' && confirm_sesskey()) {
    $generator = new nat_generator($year);
    $result = $generator->validate();
    
    $SESSION->nat_validation = $result;
    redirect(new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]));
}

if ($action === 'generate' && confirm_sesskey()) {
    $generator = new nat_generator($year);
    $result = $generator->validate();
    
    if (!empty($result['errors'])) {
        // Bug 30: Validation errors may contain student names/USIs from the DB.
        // Sanitise before passing to redirect() which renders them in the notification.
        $safeerrors = array_map('htmlspecialchars', $result['errors']);
        redirect(
            new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]),
            'Cannot generate NAT files: ' . implode(', ', $safeerrors),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    
    $files = $generator->generate_all();
    
    $zipfilename = 'AVETMISS_' . $year . '_' . date('Ymd_His') . '.zip';
    $tempdir = make_temp_directory('natexport');
    $zippath = $tempdir . '/' . $zipfilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zippath, ZipArchive::CREATE) === true) {
        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }
        $zip->close();
        
        $totalrecords = 0;
        foreach ($result['record_counts'] as $count) {
            $totalrecords += $count;
        }
        
        $export = new stdClass();
        $export->exporttype = 'nat';
        $export->exportedby = $USER->id;
        $export->periodstart = strtotime("$year-01-01");
        $export->periodend = strtotime("$year-12-31 23:59:59");
        $export->recordcount = $totalrecords;
        $export->validationerrors = count($result['errors']);
        $export->validationwarnings = count($result['warnings']);
        $export->filepath = $zipfilename;
        $export->status = 'generated';
        $export->timecreated = time();
        $export->id = $DB->insert_record('local_rtocompliance_exports', $export);
        
        $log = new stdClass();
        $log->action = 'generate';
        $log->component = 'natexport';
        $log->itemid = $export->id;
        $log->userid = $USER->id;
        $log->targetuserid = null;
        $log->details = json_encode([
            'year' => $year,
            'files' => array_keys($files),
            'record_counts' => $result['record_counts'],
        ]);
        $log->ipaddress = getremoteaddr();
        $log->timecreated = time();
        $DB->insert_record('local_rtocompliance_log', $log);
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipfilename . '"');
        header('Content-Length: ' . filesize($zippath));
        readfile($zippath);
        unlink($zippath);
        exit;
    } else {
        redirect(
            new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]),
            'Failed to create ZIP file',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo $navheader;

echo html_writer::start_div('natexport-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('nat_export_title', 'local_rtocompliance'));
echo html_writer::end_div();
echo html_writer::tag('p', get_string('nat_export_desc', 'local_rtocompliance'), ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']);

$currentyear = date('Y');
$years = [];
for ($y = $currentyear; $y >= $currentyear - 5; $y--) {
    $years[$y] = $y;
}

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url, 'style' => 'margin-bottom: 24px;']);
echo html_writer::start_div('form-group', ['style' => 'display: flex; align-items: center; gap: 12px;']);
echo html_writer::label(get_string('nat_period', 'local_rtocompliance'), 'year');
echo html_writer::select(
    $years,
    'year',
    $year,
    null,
    ['id' => 'year', 'class' => 'form-control', 'style' => 'max-width: 120px;', 'onchange' => 'this.form.submit();']
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (!empty($SESSION->nat_validation)) {
    $validation = $SESSION->nat_validation;
    unset($SESSION->nat_validation);
    
    echo html_writer::start_div('', ['style' => 'margin-bottom: 24px;']);
    
    if (!empty($validation['errors'])) {
        echo html_writer::tag('div',
            html_writer::tag('h4', 'Validation Errors') .
            html_writer::alist($validation['errors']),
            ['style' => 'background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; padding: 16px; margin-bottom: 12px; color: #991b1b;']
        );
    }
    
    if (!empty($validation['warnings'])) {
        echo html_writer::tag('div',
            html_writer::tag('h4', 'Validation Warnings') .
            html_writer::alist($validation['warnings']),
            ['style' => 'background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 12px; color: #92400e;']
        );
    }
    
    if (empty($validation['errors']) && empty($validation['warnings'])) {
        echo html_writer::tag('div',
            html_writer::tag('strong', 'Validation Passed') . ' - Data is ready for AVETMISS export.',
            ['style' => 'background: #f0fdf4; border: 1px solid #22c55e; border-radius: 8px; padding: 16px; color: #166534;']
        );
    }
    
    if (!empty($validation['record_counts'])) {
        echo html_writer::tag('h4', 'Record Counts', ['style' => 'margin-top: 16px;']);
        $countlist = [];
        foreach ($validation['record_counts'] as $file => $count) {
            $countlist[] = "$file: $count records";
        }
        echo html_writer::alist($countlist);
    }
    
    echo html_writer::end_div();
}

$natfiles = [
    'NAT00010' => ['name' => 'Training Organisation', 'desc' => 'RTO details, contact information'],
    'NAT00020' => ['name' => 'Training Organisation Delivery Location', 'desc' => 'Campus and delivery site addresses'],
    'NAT00030' => ['name' => 'Program (Course/Qualification)', 'desc' => 'Qualifications, skill sets, accredited courses'],
    'NAT00060' => ['name' => 'Subject (Module/Unit of Competency)', 'desc' => 'Units being delivered'],
    'NAT00080' => ['name' => 'Client (Student)', 'desc' => 'Student demographics and AVETMISS data'],
    'NAT00085' => ['name' => 'Client Postal Details', 'desc' => 'Student address information'],
    'NAT00090' => ['name' => 'Client Disability', 'desc' => 'Disability type codes for applicable students'],
    'NAT00100' => ['name' => 'Client Prior Educational Achievement', 'desc' => 'Previous qualifications'],
    'NAT00120' => ['name' => 'Enrolment', 'desc' => 'Unit enrolments with outcomes, dates, delivery mode'],
    'NAT00130' => ['name' => 'Program (Qualification) Completion', 'desc' => 'Completed qualifications issued'],
];

echo html_writer::tag('h3', 'NAT Files to Generate');

echo html_writer::start_div('nat-files-list');
foreach ($natfiles as $code => $file) {
    echo html_writer::start_div('nat-file-item');
    echo html_writer::start_div('nat-file-info');
    echo html_writer::tag('span', $code, ['class' => 'nat-file-code']);
    echo html_writer::tag('span', $file['name'], ['class' => 'nat-file-desc']);
    echo html_writer::end_div();
    echo html_writer::tag('small', $file['desc'], ['class' => 'text-muted']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px;']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/natexport.php', ['action' => 'validate', 'year' => $year, 'sesskey' => sesskey()]),
    get_string('nat_validate', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/natexport.php', ['action' => 'generate', 'year' => $year, 'sesskey' => sesskey()]),
    get_string('nat_generate', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

$exports = $DB->get_records('local_rtocompliance_exports', [], 'timecreated DESC', '*', 0, 10);

if ($exports) {
    echo html_writer::tag('h3', 'Recent Exports', ['style' => 'margin-top: 32px;']);
    echo html_writer::start_tag('table', ['class' => 'table', 'style' => 'background: white; border: 1px solid #e5e7eb; border-radius: 12px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Date');
    echo html_writer::tag('th', 'Period');
    echo html_writer::tag('th', 'Records');
    echo html_writer::tag('th', 'Validation');
    echo html_writer::tag('th', '');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($exports as $export) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($export->timecreated, '%d %b %Y %H:%M'));
        echo html_writer::tag('td', date('Y', $export->periodstart));
        echo html_writer::tag('td', $export->recordcount);

        $validationhtml = '';
        if ($export->validationerrors > 0) {
            $validationhtml .= html_writer::tag('span', $export->validationerrors . ' errors', ['class' => 'status-badge status-urgent']);
        }
        if ($export->validationwarnings > 0) {
            $validationhtml .= ($validationhtml ? ' ' : '') .
                html_writer::tag('span', $export->validationwarnings . ' warnings', ['class' => 'status-badge status-warning']);
        }
        if (!$validationhtml) {
            $validationhtml = html_writer::tag('span', 'Passed', ['class' => 'status-badge status-ok']);
        }
        echo html_writer::tag('td', $validationhtml);

        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/download_nat.php', ['id' => $export->id, 'sesskey' => sesskey()]),
                get_string('nat_download', 'local_rtocompliance'),
                ['class' => 'btn btn-sm btn-primary']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::end_div();

echo $OUTPUT->footer();
