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
