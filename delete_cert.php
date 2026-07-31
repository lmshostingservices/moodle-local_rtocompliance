<?php
// v5.2.69 CERT-DELETE — Soft-deletes (revokes) a certificate record.
//
// Sets the certificate status to 'revoked' so it no longer appears in the
// issued list. The record is preserved in the database for audit purposes.
//
// POST-only, sesskey-protected, JSON-only response.
// FIX (v5.2.69): Removed admin_settings_changed event trigger (threw PHP
// exception → Moodle returned HTML error page instead of JSON). Made revoke
// idempotent — if cert is already revoked, return success silently.

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

header('Content-Type: application/json');

$certid = required_param('id', PARAM_INT);

$cert = $DB->get_record('local_rtocompliance_certs', ['id' => $certid]);
if (!$cert) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Certificate not found']);
    exit;
}

// Idempotent: if already revoked, treat as success (no double-error).
if ($cert->status !== 'revoked') {
    $DB->set_field('local_rtocompliance_certs', 'status', 'revoked', ['id' => $certid]);
}

// FIX-DELETE-AUDIT (v5.9.274): write to the plugin's structured audit log so
// revocations appear in the admin audit trail alongside issuances and reissues.
// Previously used error_log() which went only to the PHP error log and was
// invisible in the Moodle admin UI.  (mtrace() is not used here because it
// echoes to stdout in a web context, which would corrupt the JSON response.)
$DB->insert_record('local_rtocompliance_log', [
    'action'       => 'revoke_certificate',
    'component'    => 'certificates',
    'itemid'       => $certid,
    'userid'       => $USER->id,
    'targetuserid' => $cert->userid,
    'details'      => json_encode([
        'certnumber' => $cert->certnumber,
        'certtype'   => $cert->certtype ?? '',
        'status_was' => $cert->status,
    ]),
    'ipaddress'    => getremoteaddr(),
    'timecreated'  => time(),
]);

echo json_encode(['ok' => true, 'certnumber' => $cert->certnumber]);
