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
 * RTO Compliance plugin — bulk_action_cert.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.2.37 BULK-CERT-ACTIONS — POST endpoint for bulk operations on certificates.
//
// Three actions exposed via the certificates.php floating action bar after the
// admin selects N rows via checkboxes:
//
//   action=email
//     Loops the selected cert ids, calls the shared
//     local_rtocompliance_send_certificate_email() helper for each. Smart skip
//     logic: skips already-emailed, skips USI-blocked (Clause 12), skips
//     replaced originals. Returns JSON with per-cert results + counts.
//
//   action=download_zip
//     Streams a ZipArchive of the selected cert PDFs as a binary download.
//     Filenames inside the ZIP follow the pattern:
//       certificate_<certnumber>_<lastname>.pdf
//     USI-blocked certs are excluded with a note in a manifest.txt file.
//
//   action=export_csv
//     Streams a CSV of the selected cert metadata (cert#, student name+email,
//     type, qualification, issue date, USI status, email status, status,
//     replacement_of) for ASQA / AVETMISS evidence. No PDF generation —
//     fast, no credit cost. Single-row certs get a single-row CSV.
//
// Sesskey + capability protected. POST only. Cert ids are clamped to 200/req
// to keep PHP/Apache request times safe.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_sesskey();
require_capability('local/rtocompliance:issuecerts', context_system::instance());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// PARAM-ALPHA-FIX (v5.9.248): PARAM_ALPHA strips underscores, turning
// 'download_zip' → 'downloadzip' and 'export_csv' → 'exportcsv', both unknown.
// PARAM_ALPHANUMEXT preserves letters, digits, hyphens, and underscores.
$action = required_param('action', PARAM_ALPHANUMEXT);

// Cert ids: comma-separated string in 'ids' POST param OR ids[] array.
$idsraw = optional_param_array('ids', null, PARAM_INT);
if ($idsraw === null) {
    $idstr = optional_param('ids', '', PARAM_TEXT);
    $idsraw = array_filter(array_map('intval', explode(',', $idstr)));
}
$ids = array_values(array_unique(array_filter(array_map('intval', $idsraw ?: []))));

if (empty($ids)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No certificate ids supplied']);
    exit;
}

// Safety cap.
if (count($ids) > 200) {
    header('Content-Type: application/json');
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Bulk action capped at 200 certificates per request. Refine your filters and try again.']);
    exit;
}

// Load all selected certs + students (joined for USI gate) in one query.
[$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
$dbman = $DB->get_manager();
$studentsTableExists = $dbman->table_exists('local_rtocompliance_students');

if ($studentsTableExists) {
    $sql = "SELECT c.*, u.firstname, u.lastname, u.email, u.middlename, u.alternatename,
                   u.firstnamephonetic, u.lastnamephonetic,
                   s.usi, s.usiverified
            FROM {local_rtocompliance_certs} c
            JOIN {user} u ON u.id = c.userid
            LEFT JOIN {local_rtocompliance_students} s ON s.userid = c.userid
            WHERE c.id $insql";
} else {
    $sql = "SELECT c.*, u.firstname, u.lastname, u.email, u.middlename, u.alternatename,
                   u.firstnamephonetic, u.lastnamephonetic
            FROM {local_rtocompliance_certs} c
            JOIN {user} u ON u.id = c.userid
            WHERE c.id $insql";
}
$rows = $DB->get_records_sql($sql, $inparams);

if (empty($rows)) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No matching certificates found']);
    exit;
}

// Helper: classify a cert for skip logic.
$classify = function ($r) use ($studentsTableExists) {
    $reasons = [];
    if (!empty($r->reissued_at)) {
        $reasons[] = 'replaced';
    }
    if ($r->status !== 'issued') {
        $reasons[] = 'status=' . $r->status;
    }
    if (in_array($r->certtype, ['testamur', 'statement'], true)
        && $studentsTableExists
        && !local_rtocompliance_usi_is_verified($r->usiverified)) {
        $reasons[] = 'usi_unverified';
    }
    return $reasons;
};

// ─────────────────────────────────────────────────────────────────────────
// Action: email
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'email') {
    header('Content-Type: application/json');

    $results = [
        'sent'    => [],
        'skipped' => [],
        'failed'  => [],
    ];

    // Bump PHP limits — emailing 50 PDFs takes time.
    @set_time_limit(120);
    @ini_set('memory_limit', '512M');

    foreach ($rows as $r) {
        $skipReasons = $classify($r);
        if (!empty($skipReasons)) {
            $results['skipped'][] = [
                'id'         => (int)$r->id,
                'certnumber' => $r->certnumber,
                'student'    => fullname($r),
                'reason'     => implode(',', $skipReasons),
            ];
            continue;
        }
        if ($r->emailsent) {
            $results['skipped'][] = [
                'id'         => (int)$r->id,
                'certnumber' => $r->certnumber,
                'student'    => fullname($r),
                'reason'     => 'already_emailed',
            ];
            continue;
        }

        // Build user object the helper expects.
        $user = (object)[
            'id'                 => $r->userid,
            'firstname'          => $r->firstname,
            'lastname'           => $r->lastname,
            'email'              => $r->email,
            'middlename'         => $r->middlename,
            'alternatename'      => $r->alternatename,
            'firstnamephonetic'  => $r->firstnamephonetic,
            'lastnamephonetic'   => $r->lastnamephonetic,
        ];

        try {
            $res = local_rtocompliance_send_certificate_email($r, $user);
            if ($res['ok']) {
                $results['sent'][] = [
                    'id'         => (int)$r->id,
                    'certnumber' => $r->certnumber,
                    'student'    => fullname($r),
                    'email'      => $res['email'],
                ];
            } else {
                $results['failed'][] = [
                    'id'         => (int)$r->id,
                    'certnumber' => $r->certnumber,
                    'student'    => fullname($r),
                    'error'      => $res['error'] ?? 'Unknown',
                ];
            }
        } catch (\Throwable $e) {
            $results['failed'][] = [
                'id'         => (int)$r->id,
                'certnumber' => $r->certnumber,
                'student'    => fullname($r),
                'error'      => $e->getMessage(),
            ];
        }
    }

    // Aggregate log entry.
    $DB->insert_record('local_rtocompliance_log', [
        'action'      => 'bulk_email_certificates',
        'component'   => 'certificates',
        'itemid'      => 0,
        'userid'      => $USER->id,
        'targetuserid'=> 0,
        'details'     => json_encode([
            'requested'    => count($rows),
            'sent_count'   => count($results['sent']),
            'skipped_count'=> count($results['skipped']),
            'failed_count' => count($results['failed']),
        ]),
        'ipaddress'   => getremoteaddr(),
        'timecreated' => time(),
    ]);

    echo json_encode([
        'ok'       => true,
        'action'   => 'email',
        'counts'   => [
            'requested' => count($rows),
            'sent'      => count($results['sent']),
            'skipped'   => count($results['skipped']),
            'failed'    => count($results['failed']),
        ],
        'results'  => $results,
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Action: download_zip
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'download_zip') {
    @set_time_limit(120);
    @ini_set('memory_limit', '512M');

    $temppath = tempnam($CFG->tempdir, 'rtoc_certbundle_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($temppath, ZipArchive::CREATE) !== true) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create ZIP archive']);
        exit;
    }

    $manifest = "Bulk Certificate Download\n";
    $manifest .= "Generated: " . userdate(time(), '%d %B %Y %H:%M') . "\n";
    $manifest .= "Generated by: " . fullname($USER) . " (" . $USER->email . ")\n";
    $manifest .= "Total selected: " . count($rows) . "\n\n";
    $included = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $skipReasons = $classify($r);
        if (!empty($skipReasons)) {
            $manifest .= "SKIPPED  " . $r->certnumber . " — " . fullname($r) . " — " . implode(',', $skipReasons) . "\n";
            $skipped++;
            continue;
        }
        $user = (object)[
            'id'                 => $r->userid,
            'firstname'          => $r->firstname,
            'lastname'           => $r->lastname,
            'email'              => $r->email,
            'middlename'         => $r->middlename,
            'alternatename'      => $r->alternatename,
            'firstnamephonetic'  => $r->firstnamephonetic,
            'lastnamephonetic'   => $r->lastnamephonetic,
        ];
        try {
            $pdfbytes = local_rtocompliance_render_certificate_pdf_string($r, $user);
            // Filesystem-safe filename with student lastname for sorting.
            $safelast = preg_replace('/[^A-Za-z0-9_-]/', '', $r->lastname ?? 'student');
            $fname = 'certificate_' . $r->certnumber . '_' . $safelast . '.pdf';
            $zip->addFromString($fname, $pdfbytes);
            $manifest .= "INCLUDED " . $r->certnumber . " — " . fullname($r) . " — " . $fname . "\n";
            $included++;
        } catch (\Throwable $e) {
            $manifest .= "FAILED   " . $r->certnumber . " — " . fullname($r) . " — " . $e->getMessage() . "\n";
            $skipped++;
        }
    }

    $manifest .= "\n--\nIncluded: $included\nSkipped/Failed: $skipped\n";
    $zip->addFromString('manifest.txt', $manifest);
    $zip->close();

    $DB->insert_record('local_rtocompliance_log', [
        'action'      => 'bulk_download_certificates',
        'component'   => 'certificates',
        'itemid'      => 0,
        'userid'      => $USER->id,
        'targetuserid'=> 0,
        'details'     => json_encode([
            'requested' => count($rows),
            'included'  => $included,
            'skipped'   => $skipped,
        ]),
        'ipaddress'   => getremoteaddr(),
        'timecreated' => time(),
    ]);

    $bundlename = 'certificates_' . date('Y-m-d_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $bundlename . '"');
    header('Content-Length: ' . filesize($temppath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($temppath);
    @unlink($temppath);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// Action: export_csv
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'export_csv') {
    $certtypes = local_rtocompliance_get_certificate_types();

    $filename = 'certificates_export_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility (customers may open these in Excel).
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Certificate Number',
        'Student First Name',
        'Student Last Name',
        'Student Email',
        'Certificate Type',
        'Qualification Code',
        'Qualification Name',
        'Issue Date',
        'Expiry Date',
        'USI',
        'USI Verified',
        'Email Sent',
        'Email Sent Date',
        'Status',
        'Replacement Of (Original Cert ID)',
        'Reissued At',
        'Notes',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r->certnumber,
            $r->firstname,
            $r->lastname,
            $r->email,
            $certtypes[$r->certtype] ?? $r->certtype,
            $r->qualificationcode,
            $r->qualificationname,
            $r->issuedate ? userdate($r->issuedate, '%Y-%m-%d') : '',
            !empty($r->expirydate) ? userdate($r->expirydate, '%Y-%m-%d') : '',
            $studentsTableExists ? ($r->usi ?? '') : '',
            $studentsTableExists ? (local_rtocompliance_usi_is_verified($r->usiverified) ? 'Yes' : 'No') : '',
            !empty($r->emailsent) ? 'Yes' : 'No',
            !empty($r->emailsentdate) ? userdate($r->emailsentdate, '%Y-%m-%d %H:%M') : '',
            $r->status,
            $r->replacement_of ?? '',
            !empty($r->reissued_at) ? userdate($r->reissued_at, '%Y-%m-%d %H:%M') : '',
            $r->notes,
        ]);
    }
    fclose($out);

    $DB->insert_record('local_rtocompliance_log', [
        'action'      => 'bulk_export_certificates_csv',
        'component'   => 'certificates',
        'itemid'      => 0,
        'userid'      => $USER->id,
        'targetuserid'=> 0,
        'details'     => json_encode(['exported' => count($rows)]),
        'ipaddress'   => getremoteaddr(),
        'timecreated' => time(),
    ]);

    exit;
}

// Unknown action.
header('Content-Type: application/json');
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . s($action)]);
