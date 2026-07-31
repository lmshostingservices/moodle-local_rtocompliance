<?php
// v4.2.36 CERTIFICATES-REDESIGN — One-click certificate reissue endpoint.
//
// Creates a new certificate based on an existing source cert, marks the source
// as replaced (audit trail preserved — original cert is NEVER deleted), charges
// 5 credits same as a fresh issuance, logs the action, and returns JSON.
//
// Mirrors the issuance flow in issue_certificate.php but with two key differences:
//   1. Source data (qualification, units, certtype, userid) is copied from the
//      original cert — admin does not retype anything.
//   2. The new cert carries replacement_of = source.id so reports can trace it
//      back to the original; the source carries reissued_at = time() so the
//      certificates list UI can grey out actions and show "Replaced by ..." badge.
//
// POST-only, sesskey-protected, JSON-only response. No HTML redirect path —
// caller is always JS on certificates.php.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

use local_rtocompliance\usi\usi_platform_client;

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

$sourceid = required_param('id', PARAM_INT);

try {
    $source = $DB->get_record('local_rtocompliance_certs', ['id' => $sourceid], '*', MUST_EXIST);
} catch (\Throwable $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Source certificate not found']);
    exit;
}

if ($source->status !== 'issued') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Source certificate is not in issued status (current: ' . s($source->status) . ')']);
    exit;
}

// USI compliance gate (Clause 12) — testamur and statement reissues require
// verified USI just like fresh issuances.
if (in_array($source->certtype, ['testamur', 'statement'], true)) {
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('local_rtocompliance_students')) {
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $source->userid]);
        if (!$student || empty($student->usiverified)) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'USI not verified for this student — Clause 12 blocks reissue of testamur/statement until USI verification is complete.',
            ]);
            exit;
        }
    }
}

// Generate new cert number.
$prefix = get_config('local_rtocompliance', 'certprefix') ?: 'CERT';
$year = date('Y');
$sequence = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_certs} WHERE certnumber LIKE ?",
    [$prefix . '-' . $year . '-%']
) + 1;
$newcertnumber = sprintf('%s-%s-%05d', $prefix, $year, $sequence);

// Charge 5 credits BEFORE the DB insert so a credit failure does not leave
// orphan cert records (same pattern as issue_certificate.php).
$platformclient = new usi_platform_client();
$creditresult = $platformclient->consume_credits(
    5,
    'certificate_reissue',
    'local_rtocompliance',
    [
        'certtype'        => $source->certtype,
        'studentid'       => $source->userid,
        'newcertnumber'   => $newcertnumber,
        'sourcecertid'    => $source->id,
        'sourcecertnumber' => $source->certnumber,
    ]
);
if (!$creditresult['ok']) {
    http_response_code(402);
    $errmsg = ($creditresult['error'] ?? '') === 'INSUFFICIENT_CREDITS'
        ? 'Insufficient credits — reissue costs 5 credits. Current balance: ' . (int)($creditresult['credits'] ?? 0) . '.'
        : 'Credit charge failed: ' . s($creditresult['error'] ?? 'unknown');
    echo json_encode(['ok' => false, 'error' => $errmsg, 'buyUrl' => $creditresult['buyUrl'] ?? null]);
    exit;
}

// Insert the new cert record (clone of source with fresh number/date/token,
// emailsent reset to 0, replacement_of pointing to source).
$now = time();
$newcert = new stdClass();
$newcert->userid            = $source->userid;
$newcert->certnumber        = $newcertnumber;
$newcert->certtype          = $source->certtype;
$newcert->qualificationcode = $source->qualificationcode;
$newcert->qualificationname = $source->qualificationname;
$newcert->units             = $source->units;
$newcert->issuedate         = $now;
$newcert->expirydate        = $source->expirydate;
$newcert->verifytoken       = local_rtocompliance_generate_certificate_token();
$newcert->status            = 'issued';
$newcert->issuedby          = $USER->id;
$newcert->notes             = trim(($source->notes ? $source->notes . "\n\n" : '') . 'Reissued from ' . $source->certnumber . ' (originally issued ' . userdate($source->issuedate, '%d %b %Y') . ')');
$newcert->emailsent         = 0;
$newcert->emailsentdate     = null;
$newcert->replacement_of    = $source->id;
$newcert->reissued_at       = null;
$newcert->timecreated       = $now;
$newcert->timemodified      = $now;

try {
    $newcert->id = $DB->insert_record('local_rtocompliance_certs', $newcert);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database insert failed: ' . s($e->getMessage())]);
    exit;
}

// Mark the source cert as superseded — ORIGINAL row is preserved untouched
// for ASQA audit trail (Clause 14 record-keeping); only reissued_at is set.
$DB->set_field('local_rtocompliance_certs', 'reissued_at', $now, ['id' => $source->id]);
$DB->set_field('local_rtocompliance_certs', 'timemodified', $now, ['id' => $source->id]);

// FIX-REISSUE-REGISTRY (v5.9.274): mark the SOURCE cert's verifytoken as
// 'superseded' in the registry so its QR code shows "Superseded / Replaced"
// instead of "Valid" when scanned after reissue.  Bulk generation paths
// (generate_course_certs.php, generate_qual_certs.php) already called this;
// the single reissue_cert.php endpoint was the only missing call site.
// SELF-REVIEW (v5.9.277): wrapped in try/catch so a registry API timeout or
// network failure does NOT abort the page before the audit log is written.
// The registry update is best-effort — the reissue itself is already committed.
try {
    local_rtocompliance_update_registry_status($source->verifytoken, 'superseded');
} catch (\Throwable $ereg) {
    debugging('reissue_cert: registry supersede failed (non-fatal): '
        . $ereg->getMessage(), DEBUG_DEVELOPER);
}

// REISSUE-REGISTRY-PUBLISH-FIX (v5.9.300): the old cert's token was correctly
// marked 'superseded' above, but the NEW cert's verifytoken was never published
// to the registry.  Without this call the new cert's QR code returns "not found"
// until the cert is emailed or downloaded through a path that triggers publish.
// Registry publish is best-effort — failure must not abort the reissue itself.
try {
    $certowner = core_user::get_user($newcert->userid);
    if ($certowner) {
        local_rtocompliance_publish_cert_to_registry($newcert, $certowner);
    }
} catch (\Throwable $ereg2) {
    debugging('reissue_cert: registry publish for new cert failed (non-fatal): '
        . $ereg2->getMessage(), DEBUG_DEVELOPER);
}

// Log the action.
$DB->insert_record('local_rtocompliance_log', [
    'action'       => 'reissue_certificate',
    'component'    => 'certificates',
    'itemid'       => $newcert->id,
    'userid'       => $USER->id,
    'targetuserid' => $source->userid,
    'details'      => json_encode([
        'newcertnumber'    => $newcertnumber,
        'sourcecertid'     => $source->id,
        'sourcecertnumber' => $source->certnumber,
        'certtype'         => $source->certtype,
    ]),
    'ipaddress'    => getremoteaddr(),
    'timecreated'  => $now,
]);

echo json_encode([
    'ok'             => true,
    'new_id'         => (int)$newcert->id,
    'new_certnumber' => $newcertnumber,
    'source_id'      => (int)$source->id,
    'message'        => 'Reissued as ' . $newcertnumber . '. Original ' . $source->certnumber . ' preserved for audit trail.',
]);
