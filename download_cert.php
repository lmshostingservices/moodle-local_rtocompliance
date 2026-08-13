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
 * RTO Compliance plugin — download_cert.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.2.48 BUG-MAY2-AUDIT — download_cert.php previously built its own
// hard-coded PDF here and bypassed the certificate template builder
// entirely. That meant students who downloaded a certificate directly
// (e.g. via the Verify or My Certificates page) ALWAYS got the legacy
// layout, even when an admin had built and activated a custom template
// for that cert type. The single shared dispatcher
// local_rtocompliance_render_certificate_pdf_string() already routes to
// the active approved template (when present) and falls back to the
// legacy layout (when not), so this file now only handles auth, USI
// gating, audit logging, and streaming the bytes the dispatcher returns.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

// Bug B fix: require_login() must gate ALL data access. Previously the cert
// and user records were fetched before authentication, allowing
// unauthenticated callers to probe certificate ID existence via MUST_EXIST
// exception vs. redirect behaviour.
require_login();
$context = context_system::instance();

$id = required_param('id', PARAM_INT);

$cert = $DB->get_record('local_rtocompliance_certs', ['id' => $id], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $cert->userid], '*', MUST_EXIST);

if ($USER->id != $cert->userid && !has_capability('local/rtocompliance:issuecerts', $context)) {
    throw new moodle_exception('nopermission');
}

// USI compliance advisory (Clause 12) — was a hard block in earlier
// releases; downgraded to a soft notification in v4.2.55 so a verified-
// USI hiccup does not prevent legitimate downloads.  The audit_logger
// entry below still records every download for compliance purposes.
//
// NOTE: do NOT call \core\notification::add() here.  download_cert.php
// streams PDF bytes directly and never renders an HTML page, so any
// queued notification would bleed onto the NEXT page the user visits
// (e.g. verify.php).  This caused duplicate USI warning banners to
// appear on the verify page.  The Clause 12 reminder is already shown
// client-side via the onclick alert() on the Download/View button in
// certificates.php and verify.php — no server-side banner is needed.

// Render via the shared dispatcher so the active template is honoured
// (and the legacy layout used as a safe fallback if no template exists
// or the template render throws).
$pdfdata = local_rtocompliance_render_certificate_pdf_string($cert, $user);

$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $cert->certnumber) . '.pdf';

audit_logger::log_export(
    audit_logger::ENTITY_CERTIFICATE,
    $cert->id,
    'Certificate PDF downloaded: ' . $cert->certnumber,
    ['cert_type' => $cert->certtype, 'cert_number' => $cert->certnumber, 'student_id' => $cert->userid]
);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdfdata));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo $pdfdata;
exit;
