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
 * RTO Compliance plugin — download_cert_pack.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// PACK-DOWNLOAD (v5.9.246) — Download Testamur + Record of Results as a ZIP.
//
// Given a student (userid) and qualification code (qualcode), locates the
// active issued testamur AND record certs for that student/qual pair, renders
// both PDFs via the shared dispatcher, and streams a ZIP archive containing both.
//
// This addresses the admin UX gap where the certificates list had one Download
// link per cert row — so clicking Download on a testamur row only got the testamur,
// and the corresponding Record of Results had to be found and downloaded separately.
//
// With this endpoint, the "📦 Pack" button in certificates.php calls here and the
// admin gets a single ZIP with both documents, correctly named for filing.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

require_login();
$context = context_system::instance();

// Accept either:
//   ?userid=X&qualcode=Y  — admin view (requires issuecerts capability)
//   ?id=X                 — derive userid/qualcode from a single cert id
//      (still checked: the caller must own the cert or have capability)
$userid  = optional_param('userid',  0,  PARAM_INT);
$qualcode = trim(optional_param('qualcode', '', PARAM_TEXT));
$certid  = optional_param('id',      0,  PARAM_INT);

if ($certid > 0 && ($userid === 0 || $qualcode === '')) {
    // Derive from a single cert id.
    $refc = $DB->get_record('local_rtocompliance_certs', ['id' => $certid], 'userid, qualificationcode', MUST_EXIST);
    $userid   = (int)$refc->userid;
    $qualcode = (string)$refc->qualificationcode;
}

if ($userid <= 0 || $qualcode === '') {
    throw new moodle_exception('invalidparameter', 'error', '', 'userid and qualcode are required');
}

// Auth: students can only download their own pack; admins need issuecerts cap.
if ($USER->id !== $userid) {
    require_capability('local/rtocompliance:issuecerts', $context);
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Fetch the active (non-superseded) testamur + record for this student/qual.
$findCert = function (string $certtype) use ($DB, $userid, $qualcode): ?stdClass {
    return $DB->get_record_sql(
        "SELECT * FROM {local_rtocompliance_certs}
          WHERE userid            = :uid
            AND certtype          = :ctype
            AND qualificationcode = :qcode
            AND status            = 'issued'
            AND (reissued_at IS NULL OR reissued_at = 0)
          ORDER BY issuedate DESC
          LIMIT 1",
        ['uid' => $userid, 'ctype' => $certtype, 'qcode' => $qualcode]
    ) ?: null;
};

$testamur = $findCert('testamur');
$record   = $findCert('record');

if (!$testamur && !$record) {
    throw new moodle_exception('notfound', 'error', '', 'No issued certificates found for this student and qualification.');
}

// Render available PDFs.
$files = [];

if ($testamur) {
    $pdfdata = local_rtocompliance_render_certificate_pdf_string($testamur, $user);
    $fname   = preg_replace('/[^A-Za-z0-9_-]/', '_', $testamur->certnumber) . '_Testamur.pdf';
    $files[$fname] = $pdfdata;
    audit_logger::log_export(
        audit_logger::ENTITY_CERTIFICATE,
        $testamur->id,
        'Certificate PDF downloaded (pack): ' . $testamur->certnumber,
        ['cert_type' => 'testamur', 'cert_number' => $testamur->certnumber, 'student_id' => $userid, 'pack' => true]
    );
}

if ($record) {
    $pdfdata = local_rtocompliance_render_certificate_pdf_string($record, $user);
    $fname   = preg_replace('/[^A-Za-z0-9_-]/', '_', $record->certnumber) . '_RecordOfResults.pdf';
    $files[$fname] = $pdfdata;
    audit_logger::log_export(
        audit_logger::ENTITY_CERTIFICATE,
        $record->id,
        'Certificate PDF downloaded (pack): ' . $record->certnumber,
        ['cert_type' => 'record', 'cert_number' => $record->certnumber, 'student_id' => $userid, 'pack' => true]
    );
}

// If only one cert exists, stream it directly as a PDF rather than a single-file ZIP.
if (count($files) === 1) {
    $fname   = array_key_first($files);
    $pdfdata = $files[$fname];
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdfdata));
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $pdfdata;
    exit;
}

// Build a ZIP in memory using PHP's ZipArchive.
$zipfile = tempnam(sys_get_temp_dir(), 'rtoc_pack_') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new moodle_exception('cannotcreatezip', 'error', '', 'Could not create certificate ZIP archive.');
}
foreach ($files as $name => $data) {
    $zip->addFromString($name, $data);
}
$zip->close();

$qualcodeClean = preg_replace('/[^A-Za-z0-9_-]/', '_', $qualcode);
$lastname      = preg_replace('/[^A-Za-z0-9_-]/', '_', $user->lastname ?? 'Student');
$zipname       = $qualcodeClean . '_' . $lastname . '_CertificatePack.zip';

$zipsize = filesize($zipfile);
$zipcontent = file_get_contents($zipfile);
unlink($zipfile);

header('Content-Type: application/zip');
header('Content-Length: ' . $zipsize);
header('Content-Disposition: attachment; filename="' . $zipname . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $zipcontent;
exit;
