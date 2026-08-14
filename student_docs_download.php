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
 * RTO Compliance plugin — student_docs_download.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.9.108 STUDENT-DOC-REPOSITORY — File download handler for student_docs.
// Serves files stored via Moodle file API (component=local_rtocompliance, filearea=student_doc).
// Access: students see own docs, admins/trainers with viewall see any.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();

$docid = required_param('docid', PARAM_INT);

$doc = $DB->get_record('local_rtocompliance_student_docs', ['id' => $docid], '*', MUST_EXIST);

if ($doc->userid != $USER->id) {
    require_capability('local/rtocompliance:viewall', context_system::instance());
}
require_capability('local/rtocompliance:viewown', context_user::instance($doc->userid));

$fs   = get_file_storage();
$file = $fs->get_file(
    context_system::instance()->id,
    'local_rtocompliance',
    'student_doc',
    $docid,
    '/',
    $doc->filename
);

if (!$file || $file->is_directory()) {
    throw new moodle_exception('filenotfound', 'local_rtocompliance');
}

send_stored_file($file, 0, 0, true); // forcedownload = true
