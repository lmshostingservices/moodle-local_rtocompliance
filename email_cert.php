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

// v4.2.36 CERTIFICATES-REDESIGN — One-click email certificate.
//
// Now supports TWO call paths:
//   1. AJAX (header X-Requested-With: XMLHttpRequest OR ?ajax=1):
//      Skip the confirm prompt entirely. Generate PDF, email, return JSON.
//      Used by the new certificates.php "Email" action button.
//   2. Legacy GET (no AJAX header, no confirm):
//      Show the $OUTPUT->confirm dialog as before.
//   3. Legacy GET with confirm=1 + sesskey:
//      Same as before — actually send the email and redirect.
//
// Reissue support: when cert.replacement_of IS NOT NULL, uses the
// email_reissue_subject / email_reissue_body strings which name the
// replaced original certificate (requested wording: "(replaces CERT-...)").

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/pdflib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$ajax = optional_param('ajax', 0, PARAM_BOOL)
    || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0);

require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$cert = $DB->get_record('local_rtocompliance_certs', ['id' => $id], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $cert->userid], '*', MUST_EXIST);

$returnurl = new moodle_url('/local/rtocompliance/certificates.php');

/**
 * Render JSON for AJAX callers and exit.
 *
 * @param array $payload
 * @param int $http
 * @return void
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function rtoc_email_cert_json_response(array $payload, int $http = 200): void {
    header('Content-Type: application/json');
    http_response_code($http);
    echo json_encode($payload);
    exit;
}

// USI compliance advisory (Clause 12) — was a hard block in earlier
// releases; downgraded to a soft notification in v4.2.55 so a verified-
// USI hiccup does not prevent legitimate emails.  The send still goes
// ahead and is recorded in the audit log; the JS-side popup on the
// certificates list / verify page surfaces the Clause 12 reminder
// before the click.  For the AJAX path we attach a `warning` field
// that the client can surface alongside the success message.
$rtoc_usi_warning = null;
if (in_array($cert->certtype, ['testamur', 'statement'])) {
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('local_rtocompliance_students')) {
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $cert->userid]);
        if (!$student || empty($student->usiverified)) {
            $rtoc_usi_warning = 'Note: this student\'s USI has not yet been verified with the USI Registry. The email has still been sent — please verify the student\'s USI on the Students register as soon as possible.';
            if (!$ajax) {
                \core\notification::add($rtoc_usi_warning, \core\notification::WARNING);
            }
        }
    }
}

// Already-emailed guard.
if ($cert->emailsent) {
    if ($ajax) {
        rtoc_email_cert_json_response([
            'ok'    => false,
            'error' => get_string('certificate_already_emailed', 'local_rtocompliance'),
            'code'  => 'ALREADY_EMAILED',
        ], 409);
    }
    redirect($returnurl, get_string('certificate_already_emailed', 'local_rtocompliance'), null, \core\output\notification::NOTIFY_WARNING);
}


// ── AJAX path: do it now, return JSON ────────────────────────────────────
if ($ajax) {
    // require_sesskey() MUST be inside the try/catch so that a bad/expired
    // sesskey throws a \required_key_exception that we can return as JSON.
    // If it sits outside, Moodle's global exception handler renders a full
    // HTML error page, which the JS then tries to parse as JSON and shows
    // "Unexpected token '<'" to the user.
    try {
        require_sesskey();
        $result = local_rtocompliance_send_certificate_email($cert, $user);
        if (!$result['ok']) {
            rtoc_email_cert_json_response(['ok' => false, 'error' => $result['error']], 500);
        }
        rtoc_email_cert_json_response([
            'ok'      => true,
            'email'   => $result['email'],
            'message' => 'Certificate emailed to ' . $result['email'],
        ]);
    } catch (\Throwable $e) {
        rtoc_email_cert_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ── Legacy non-AJAX path ─────────────────────────────────────────────────
$PAGE->set_url('/local/rtocompliance/email_cert.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('email_certificate', 'local_rtocompliance'));
$PAGE->set_heading(get_string('email_certificate', 'local_rtocompliance'));

if ($confirm && confirm_sesskey()) {
    $result = local_rtocompliance_send_certificate_email($cert, $user);
    if (!$result['ok']) {
        redirect($returnurl, 'Email failed: ' . $result['error'], null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($returnurl, get_string('certificate_emailed', 'local_rtocompliance'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Email Certificate', 'Certificates', new moodle_url('/local/rtocompliance/certificates.php'));

$certtypes = local_rtocompliance_get_certificate_types();

echo $OUTPUT->confirm(
    html_writer::tag('p', get_string('email_certificate_confirm', 'local_rtocompliance', [
        'fullname'   => fullname($user),
        'email'      => $user->email,
        'certtype'   => $certtypes[$cert->certtype] ?? $cert->certtype,
        'certnumber' => $cert->certnumber,
    ])),
    new moodle_url('/local/rtocompliance/email_cert.php', ['id' => $id, 'confirm' => 1]),
    $returnurl
);

echo $OUTPUT->footer();
