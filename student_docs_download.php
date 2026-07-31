<?php
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
