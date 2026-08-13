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
 * RTO Compliance plugin — cert_test.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// CERT-OF-COMPLETION + TEST-CERT (v4.2.42) — test certificate generator.
//
// Lets an admin pick any of the four certificate types and generate a
// sample PDF on demand using either the active approved template (if one
// exists for that type) or the built-in default layout (legacy renderer).
// No data is persisted — purely for layout/QA verification.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/cert_template_renderer.php');

use local_rtocompliance\cert_template;
use local_rtocompliance\cert_template_renderer;

require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managecerttemplates', $context);

$generate    = optional_param('generate', 0, PARAM_INT);
$certtype    = optional_param('certtype', '', PARAM_ALPHA);
$studentname = optional_param('studentname', '', PARAM_TEXT);
$orientation = optional_param('orientation', '', PARAM_ALPHA);
$sendemail   = optional_param('sendemail', 0, PARAM_INT);
// Sanitise orientation — only 'P' or 'L' are valid; anything else means auto.
if ($orientation !== 'P' && $orientation !== 'L') {
    $orientation = '';
}

$validtypes = local_rtocompliance_get_certificate_types();

// ---------------------------------------------------------------------------
// PDF generation branch — stream the PDF and exit.
// ---------------------------------------------------------------------------
if ($generate && isset($validtypes[$certtype])) {
    require_sesskey();

    $studentname = trim($studentname);
    if ($studentname === '') {
        $studentname = 'Jane Citizen';
    }

    // Build a synthetic $cert + $user pair so we can route through the same
    // pipeline that real issuance uses.  This guarantees the test reflects
    // exactly what students will get.
    global $USER;

    $parts = preg_split('/\s+/', $studentname, 2);
    $firstname = $parts[0] ?? 'Jane';
    $lastname  = $parts[1] ?? 'Citizen';

    $sampleuser = (object) [
        'id'        => $USER->id,
        'firstname' => $firstname,
        'lastname'  => $lastname,
        'email'     => $USER->email ?? 'test@example.com',
    ];

    $issuets = time();
    $samplecert = (object) [
        'id'                => 0,
        'certtype'          => $certtype,
        // TEST-CERT-NUMBER (v6.2.54): show the REAL certificate-number format on the test
        // preview — {certprefix}-{TYPECODE}-{YYYY}-{NNNN}, e.g. CBF-CER-2026-0001 — using the
        // same generator real issuance uses, instead of the old "TEST-TEST-<timestamp>"
        // placeholder. This preview cert is never saved (id=0), so computing the next number
        // has no side effect and does not reserve a sequence. (Set the "Certificate prefix"
        // in RTO settings to your code — e.g. CBF — for the leading segment to read that way.)
        'certnumber'        => local_rtocompliance_generate_cert_number($certtype),
        // F6 (v5.9.390): give the test cert its OWN dummy verify token and USI so the
        // QR encodes a clearly non-verifiable test URL and a Record of Results preview
        // shows a placeholder USI instead of silently borrowing the logged-in admin's
        // real USI (the renderer falls back to the live student USI when cert.usi is empty).
        'verifytoken'       => 'TEST-PREVIEW-NOT-A-REAL-CERTIFICATE',
        'usi'               => $certtype === 'record' ? 'TESTUSI010' : '',
        'qualificationcode' => $certtype === 'completion' ? '' : 'BSB30120',
        'qualificationname' => $certtype === 'completion'
            ? 'Workplace First Aid (Non-Accredited)'
            : 'Certificate III in Business',
        'unitsofcompetency' => '',
        'coursetitle'       => $certtype === 'completion'
            ? 'Workplace First Aid (Non-Accredited)'
            : '',
        // v5.9.365: include outcome + semester so the Live preview's Record of Results
        // Semester/Results columns populate (outcome 20 = Competent, AVETMISS).
        'units'             => json_encode([
            ['code' => 'BSBCMM311', 'name' => 'Apply critical thinking skills in a team environment', 'outcome' => '20', 'semester' => 'Sem 1 ' . date('Y')],
            ['code' => 'BSBCRT311', 'name' => 'Apply critical thinking skills', 'outcome' => '20', 'semester' => 'Sem 1 ' . date('Y')],
            ['code' => 'BSBPEF301', 'name' => 'Organise personal work priorities', 'outcome' => '20', 'semester' => 'Sem 2 ' . date('Y')],
        ]),
        'issuedate'         => $issuets,
        'timeissued'        => $issuets,
        'timecompleted'     => $issuets - (3 * DAYSECS),
    ];

    $pdfdata = local_rtocompliance_render_certificate_pdf_string($samplecert, $sampleuser, $orientation);

    // -----------------------------------------------------------------------
    // Optional: email the test PDF to the currently logged-in admin.
    // Bypasses the emailcerts on/off setting (this is a deliberate test send)
    // and never writes any DB records — no cert row, no log entry.
    // -----------------------------------------------------------------------
    if ($sendemail) {
        global $CFG;
        $rtoname     = get_config('local_rtocompliance', 'rtoname') ?: 'Training Organisation';
        $certtypes   = local_rtocompliance_get_certificate_types();
        $certtypename = $certtypes[$certtype] ?? $certtype;

        $subject = get_string('cert_test_email_subject', 'local_rtocompliance', [
            'certtype' => $certtypename,
            'rtoname'  => $rtoname,
        ]);
        $messagehtml = get_string('cert_test_email_body', 'local_rtocompliance', [
            'fullname'   => fullname($sampleuser),
            'certtype'   => $certtypename,
            'certnumber' => $samplecert->certnumber,
            'rtoname'    => $rtoname,
        ]);
        $messagetext = html_to_text($messagehtml);

        // Write PDF bytes to a temp file for email_to_user().
        $tempfilename = 'test-cert-email-' . $samplecert->certnumber . '.pdf';
        $temppath     = $CFG->tempdir . '/' . $tempfilename;
        file_put_contents($temppath, $pdfdata);
        register_shutdown_function (function () use ($temppath) {
            if (file_exists($temppath)) {
                @unlink($temppath);
            }
        });

        // Send to the real admin user (not the synthetic $sampleuser) so the
        // email lands in the logged-in admin's inbox.
        $supportuser = core_user::get_support_user();
        $sent = email_to_user($USER, $supportuser, $subject, $messagetext, $messagehtml, $temppath, $tempfilename);
        @unlink($temppath);

        if (!$sent) {
            debugging(
                'local_rtocompliance cert_test: email_to_user() returned false for test certificate email ' .
                '(certtype=' . $certtype . ', recipient=' . $USER->email . '). ' .
                'Check Moodle outgoing mail settings (Site administration › Server › Email › Outgoing mail configuration).',
                DEBUG_DEVELOPER
            );
        }

        // We deliberately do NOT write any DB records — no cert row, no log entry.
    }

    // -----------------------------------------------------------------------
    // When an email send was requested, show a brief HTML splash page with the
    // send result, then auto-redirect the new tab to the PDF-only URL.
    // Without the sendemail param the second request skips the email block and
    // streams the PDF directly — no double-send occurs.
    // -----------------------------------------------------------------------
    if ($sendemail) {
        $pdfurl = new moodle_url('/local/rtocompliance/cert_test.php', [
            'generate'    => 1,
            'certtype'    => $certtype,
            'studentname' => $studentname,
            'orientation' => $orientation,
            'sendemail'   => 0,
            'sesskey'     => sesskey(),
        ]);
        $pdfurlesc = $pdfurl->out(false);

        if (!empty($sent)) {
            $statusclass   = 'alert-success';
            $statusicon    = '✓';
            $statusmessage = get_string('cert_test_email_sent', 'local_rtocompliance', s($USER->email));
        } else {
            $statusclass   = 'alert-danger';
            $statusicon    = '✗';
            $statusmessage = get_string('cert_test_email_failed', 'local_rtocompliance');
        }

        // Redirect delay (seconds) — long enough to read the message, short
        // enough not to feel slow.
        $delay = !empty($sent) ? 3 : 0; // On failure don't auto-redirect; admin must click.

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Test certificate — email status</title>';
        if (!empty($sent)) {
            echo '<meta http-equiv="refresh" content="' . $delay . '; url=' . $pdfurlesc . '">';
        }
        echo '
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         display: flex; align-items: center; justify-content: center;
         min-height: 100vh; margin: 0; background: #f8f9fa; }
  .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12);
          padding: 2.5rem 3rem; max-width: 520px; width: 100%; text-align: center; }
  .alert { border-radius: 6px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;
           font-size: 1.05rem; line-height: 1.5; }
  .alert-success { background: #d1e7dd; color: #0a3622; border: 1px solid #a3cfbb; }
  .alert-danger  { background: #f8d7da; color: #58151c; border: 1px solid #f1aeb5; }
  .icon { font-size: 2rem; display: block; margin-bottom: .5rem; }
  .pdf-link { display: inline-block; margin-top: .75rem; padding: .5rem 1.25rem;
              background: #0d6efd; color: #fff; border-radius: 5px;
              text-decoration: none; font-weight: 600; }
  .pdf-link:hover { background: #0b5ed7; }
  .redirect-note { color: #6c757d; font-size: .875rem; margin-top: 1rem; }
</style>
</head>
<body>
<div class="card">
  <div class="alert ' . $statusclass . '">
    <span class="icon">' . $statusicon . '</span>
    ' . $statusmessage . '
  </div>
  <a class="pdf-link" href="' . $pdfurlesc . '">Open PDF &rarr;</a>';
        if (!empty($sent)) {
            echo '
  <p class="redirect-note">PDF will open automatically in ' . $delay . ' seconds&hellip;</p>';
        }
        echo '
</div>
</body>
</html>';
        exit;
    }

    $filename = 'test-' . $certtype . '-' . date('Ymd-His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdfdata));
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $pdfdata;
    exit;
}

// ---------------------------------------------------------------------------
// Form rendering branch.
// ---------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/local/rtocompliance/cert_test.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('cert_test_pagetitle', 'local_rtocompliance'));
$PAGE->set_heading(get_string('cert_test_heading', 'local_rtocompliance'));

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Test Certificate', 'Certificate Templates', new moodle_url('/local/rtocompliance/cert_templates.php'));
echo local_rtocompliance_page_banner('Test Certificate');
echo $OUTPUT->heading(get_string('cert_test_heading', 'local_rtocompliance'));
echo html_writer::tag('p', get_string('cert_test_intro', 'local_rtocompliance'));

// Build per-type status summary so the admin sees which types have an
// active template versus which will fall back to the default layout.
$statussummary = [];
foreach ($validtypes as $key => $label) {
    $active = cert_template::get_active_template($key);
    $statussummary[$key] = $active
        ? get_string('cert_test_active_template', 'local_rtocompliance', format_string($active->name))
        : get_string('cert_test_no_active_template', 'local_rtocompliance');
}

$actionurl = new moodle_url('/local/rtocompliance/cert_test.php');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $actionurl->out(false),
    'target' => '_blank',
    'class'  => 'mt-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'generate', 'value' => 1]);
// NRT-ORIENT-OVERRIDE (v4.4.7): carry the chosen orientation into the POST so the
// generate branch can force portrait or landscape regardless of cert-type default.

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_orientation_label', 'local_rtocompliance'),
    'cert_test_orientation',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::start_tag('select', [
    'name'  => 'orientation',
    'id'    => 'cert_test_orientation',
    'class' => 'form-select',
    'style' => 'max-width: 480px;',
]);
echo html_writer::tag('option', get_string('cert_test_orientation_auto', 'local_rtocompliance'),      ['value' => '']);
echo html_writer::tag('option', get_string('cert_test_orientation_portrait', 'local_rtocompliance'), ['value' => 'P']);
echo html_writer::tag('option', get_string('cert_test_orientation_landscape', 'local_rtocompliance'),['value' => 'L']);
echo html_writer::end_tag('select');
echo html_writer::tag('p',
    get_string('cert_test_orientation_hint', 'local_rtocompliance'),
    ['class' => 'form-text text-muted mt-1']
);
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_certtype_label', 'local_rtocompliance'),
    'cert_test_certtype',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::start_tag('select', [
    'name'  => 'certtype',
    'id'    => 'cert_test_certtype',
    'class' => 'form-select',
    'style' => 'max-width: 480px;',
    'required' => 'required',
]);
foreach ($validtypes as $key => $label) {
    echo html_writer::tag('option', s($label . ' — ' . $statussummary[$key]), ['value' => $key]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-3');
echo html_writer::label(
    get_string('cert_test_studentname_label', 'local_rtocompliance'),
    'cert_test_studentname',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'name'        => 'studentname',
    'id'          => 'cert_test_studentname',
    'class'       => 'form-control',
    'style'       => 'max-width: 480px;',
    'placeholder' => get_string('cert_test_studentname_placeholder', 'local_rtocompliance'),
    'maxlength'   => 100,
]);
echo html_writer::end_div();

// "Send to my email" checkbox — shows the admin's real email address.
echo html_writer::start_div('form-group mb-3');
echo html_writer::start_div('form-check');
echo html_writer::empty_tag('input', [
    'type'  => 'checkbox',
    'name'  => 'sendemail',
    'id'    => 'cert_test_sendemail',
    'class' => 'form-check-input',
    'value' => 1,
]);
echo html_writer::label(
    get_string('cert_test_sendemail_label', 'local_rtocompliance', s($USER->email)),
    'cert_test_sendemail',
    true,
    ['class' => 'form-check-label']
);
echo html_writer::end_div();
echo html_writer::tag('p',
    get_string('cert_test_sendemail_hint', 'local_rtocompliance'),
    ['class' => 'form-text text-muted mt-1']
);
echo html_writer::end_div();

echo html_writer::tag('button',
    get_string('cert_test_generate', 'local_rtocompliance'),
    ['type' => 'submit', 'class' => 'btn btn-primary']
);

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
