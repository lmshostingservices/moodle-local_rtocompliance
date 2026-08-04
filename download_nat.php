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
require_once(__DIR__ . '/classes/nat_generator.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\nat_generator;
use local_rtocompliance\audit_logger;

$id = required_param('id', PARAM_INT);

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/rtocompliance:exportnat', $context);

$export = $DB->get_record('local_rtocompliance_exports', ['id' => $id], '*', MUST_EXIST);

// Bug 34: This download regenerates NAT files from CURRENT live data, not the
// historical data at the time of the original export. The downloaded ZIP will
// reflect the state of student/enrolment records today, which may differ from
// the original submission if records have been edited since the export was created.
// The export log entry (record counts, filepath) refers to the original run.
// For a true point-in-time archive, store the original ZIP in Moodle file storage.
$year = date('Y', $export->periodstart);
$generator = new nat_generator($year);
$files = $generator->generate_all();

$zipfilename = $export->filepath ?: 'AVETMISS_' . $year . '_regenerated.zip';
$tempdir = make_temp_directory('natexport');
$zippath = $tempdir . '/' . $zipfilename;

$zip = new ZipArchive();
if ($zip->open($zippath, ZipArchive::CREATE) === true) {
    foreach ($files as $filename => $content) {
        $zip->addFromString($filename, $content);
    }
    $zip->close();
    
    audit_logger::log_export(
        audit_logger::ENTITY_NAT_EXPORT,
        $id,
        'AVETMISS NAT file download: ' . $zipfilename,
        ['filename' => $zipfilename, 'year' => $year, 'file_count' => count($files)]
    );
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipfilename . '"');
    header('Content-Length: ' . filesize($zippath));
    readfile($zippath);
    unlink($zippath);
    exit;
} else {
    redirect(
        new moodle_url('/local/rtocompliance/natexport.php'),
        'Failed to create ZIP file',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
