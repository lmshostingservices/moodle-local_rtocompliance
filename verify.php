<?php
require_once(__DIR__ . '/../../config.php');
// v4.2.39 hotfix: lib.php must be explicitly included so that
// local_rtocompliance_get_certificate_types() (defined in lib.php) is
// available.  Moodle does NOT auto-load a plugin's lib.php for standalone
// scripts; every other caller (mycerts.php, download_cert.php, etc.)
// already requires it explicitly — verify.php was the only one missing
// the include, which broke the public certificate-verification page with
// "Call to undefined function local_rtocompliance_get_certificate_types()".
require_once(__DIR__ . '/lib.php');

$token = required_param('token', PARAM_ALPHANUM);

$PAGE->set_url('/local/rtocompliance/verify.php', ['token' => $token]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('verify_certificate', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();

echo html_writer::start_div('verify-container');

$cert = $DB->get_record('local_rtocompliance_certs', ['verifytoken' => $token, 'status' => 'issued']);

if ($cert) {
    $user = core_user::get_user($cert->userid);
    $rtoname = get_config('local_rtocompliance', 'rtoname');
    $rtocode = get_config('local_rtocompliance', 'rtocode');

    $certtypes = local_rtocompliance_get_certificate_types();

    echo html_writer::start_div('verify-card verify-success');
    echo html_writer::tag('div', '✓', ['class' => 'verify-icon']);
    echo html_writer::tag('h2', get_string('certificate_verified', 'local_rtocompliance'), ['class' => 'verify-title']);
    echo html_writer::tag('p', 'This certificate has been verified as authentic.', ['class' => 'text-muted']);

    echo html_writer::start_div('verify-details');

    // Bug H fix: This page is publicly accessible (no login -- intentional for QR
    // scanning). Showing the student's full legal name to anyone with the URL
    // unnecessarily exposes PII. Display first name + last-name initial only,
    // which is sufficient to confirm the certificate belongs to the right person
    // while limiting data exposure. The 128-bit random token makes brute-force
    // enumeration infeasible, but we still minimise PII shown on a public page.
    $displayName = '';
    if ($user) {
        $firstName = $user->firstname ?? '';
        $lastName  = $user->lastname ?? '';
        $lastInit  = $lastName !== '' ? (mb_strtoupper(mb_substr($lastName, 0, 1)) . '.') : '';
        $displayName = trim($firstName . ' ' . $lastInit);
    }

    $details = [
        'Certificate Number' => $cert->certnumber,
        'Certificate Type'   => $certtypes[$cert->certtype] ?? $cert->certtype,
        'Student Name'       => $displayName,
        'Issue Date'         => userdate($cert->issuedate, '%d %B %Y'),
    ];

    if ($cert->qualificationcode) {
        $details['Qualification'] = $cert->qualificationcode . ' - ' . $cert->qualificationname;
    }

    if ($cert->units) {
        $units = json_decode($cert->units, true);
        if ($units) {
            $details['Units'] = count($units) . ' unit(s) of competency';
        }
    }

    if ($rtoname) {
        $details['Issued By'] = $rtoname . ($rtocode ? ' (RTO ' . $rtocode . ')' : '');
    }

    foreach ($details as $label => $value) {
        echo html_writer::start_div('verify-detail-row');
        echo html_writer::tag('span', $label, ['class' => 'verify-detail-label']);
        echo html_writer::tag('span', $value, ['class' => 'verify-detail-value']);
        echo html_writer::end_div();
    }

    echo html_writer::end_div();   // close .verify-details

    // ── AVETMISS cross-reference (staff only) ──────────────────────────────
    // v4.9.115: For staff with issuecerts capability, cross-reference the
    // AVETMISS import data to confirm this qualification was reported to NCVER.
    // Join chain: cert.userid → local_rtocompliance_students.usi →
    //   local_rtocompliance_avetmiss_student.clientid →
    //   local_rtocompliance_avetmiss_completion (by clientid + qualcode).
    // No schema change — pure read-only query of existing import tables.
    $sysctx = context_system::instance();
    if (isloggedin() && !isguestuser() && has_capability('local/rtocompliance:issuecerts', $sysctx)) {
        $dbman = $DB->get_manager();
        $usi   = null;
        if ($dbman->table_exists('local_rtocompliance_students')) {
            $studentRec = $DB->get_record('local_rtocompliance_students', ['userid' => $cert->userid]);
            $usi = (!empty($studentRec->usi)) ? trim($studentRec->usi) : null;
        }

        $avetmissRecord = null;
        if ($usi && $cert->qualificationcode
                && $dbman->table_exists('local_rtocompliance_avetmiss_student')
                && $dbman->table_exists('local_rtocompliance_avetmiss_completion')) {
            $avetmissStudent = $DB->get_record('local_rtocompliance_avetmiss_student', ['usi' => $usi]);
            if ($avetmissStudent) {
                $avetmissRecord = $DB->get_record_sql(
                    "SELECT c.*
                       FROM {local_rtocompliance_avetmiss_completion} c
                      WHERE c.clientid = :clientid
                        AND c.qualcode  = :qualcode
                   ORDER BY c.id DESC",
                    ['clientid' => $avetmissStudent->clientid,
                     'qualcode'  => $cert->qualificationcode],
                    IGNORE_MULTIPLE
                );
            }
        }

        echo '<div class="verify-avetmiss-panel">';
        echo '<div class="verify-avetmiss-heading">NCVER / AVETMISS Reporting</div>';

        if ($avetmissRecord) {
            $hasParc = !empty(trim((string)$avetmissRecord->parchmentnumber));
            echo '<div class="verify-avetmiss-status verify-avetmiss-found">';
            echo '<span class="verify-avetmiss-badge verify-avetmiss-badge-ok">Reported to NCVER</span>';
            echo '<div class="verify-avetmiss-rows">';
            if ($hasParc) {
                echo '<div class="verify-avetmiss-row">';
                echo '<span class="verify-avetmiss-label">Parchment / Cert #</span>';
                echo '<span class="verify-avetmiss-val"><code>' . s(trim($avetmissRecord->parchmentnumber)) . '</code></span>';
                echo '</div>';
            }
            if ($avetmissRecord->completiondate) {
                echo '<div class="verify-avetmiss-row">';
                echo '<span class="verify-avetmiss-label">Completion date</span>';
                echo '<span class="verify-avetmiss-val">'
                    . s(local_rtocompliance_format_ddmmyyyy($avetmissRecord->completiondate)) . '</span>';
                echo '</div>';
            }
            if ($avetmissRecord->certificatedate) {
                echo '<div class="verify-avetmiss-row">';
                echo '<span class="verify-avetmiss-label">Certificate date</span>';
                echo '<span class="verify-avetmiss-val">'
                    . s(local_rtocompliance_format_ddmmyyyy($avetmissRecord->certificatedate)) . '</span>';
                echo '</div>';
            }
            if (!$hasParc) {
                echo '<div class="verify-avetmiss-row">';
                echo '<span class="verify-avetmiss-label">Parchment / Cert #</span>';
                echo '<span class="verify-avetmiss-val text-muted"><em>not recorded in this submission</em></span>';
                echo '</div>';
            }
            echo '</div>'; // .verify-avetmiss-rows
            echo '<p class="verify-avetmiss-note">This qualification appears in the AVETMISS data imported into this '
                . 'system. The parchment number is the identifier reported to NCVER — it provides an audit trail '
                . 'independent of any internal certificate renumbering.</p>';
            echo '</div>'; // .verify-avetmiss-status
        } else {
            $reason = '';
            if (!$usi) {
                $reason = 'No USI on file for this student.';
            } elseif (!$cert->qualificationcode) {
                $reason = 'Certificate has no qualification code.';
            } else {
                $reason = 'No matching AVETMISS completion record found for USI ' . s($usi) . ' + ' . s($cert->qualificationcode) . '.';
            }
            echo '<div class="verify-avetmiss-status verify-avetmiss-notfound">';
            echo '<span class="verify-avetmiss-badge verify-avetmiss-badge-na">Not in AVETMISS data</span>';
            echo '<p class="verify-avetmiss-note">' . s($reason)
                . ' This may mean AVETMISS data has not been imported yet, or the student\'s records '
                . 'were submitted before this system was in use.</p>';
            echo '</div>';
        }
        echo '</div>'; // .verify-avetmiss-panel
    }
    // BUG-MAY1-AUDIT #11/#14 (v4.2.44): when an authenticated staff member
    // with the issuecerts capability lands on this page (e.g. by clicking
    // "Verify" on the Certificates list), give them quick action buttons so
    // they don't have to bounce back to the list to download or email the
    // certificate.  Public visitors (anonymous QR scans) see only the
    // verification result — no action buttons.
    // Note: $sysctx is already defined above in the AVETMISS cross-reference block.
    if (isloggedin() && !isguestuser() && has_capability('local/rtocompliance:issuecerts', $sysctx)) {
        echo html_writer::start_div('verify-actions');

        echo html_writer::link(
            new moodle_url('/local/rtocompliance/certificates.php'),
            'Back to Certificates',
            ['class' => 'btn btn-secondary', 'data-testid' => 'link-back-certs']
        );

        // BUG-MAY1-AUDIT-PASS2 (v4.2.46): mirror the certificates.php
        // behaviour — when the USI is unverified, render Download / Email
        // as normal-looking buttons that pop a clear alert on click rather
        // than letting the user reach the server-side block page.
        $usiRequired = in_array($cert->certtype, ['testamur', 'statement']);
        $usiVerified = true;
        if ($usiRequired) {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_rtocompliance_students')) {
                $student = $DB->get_record('local_rtocompliance_students', ['userid' => $cert->userid]);
                $usiVerified = ($student && !empty($student->usiverified));
            }
        }

        // BUG-MAY2-USI-WARN-NOT-BLOCK (v4.2.55): downgraded the USI
        // verification gate on this page from a hard block to a
        // non-blocking advisory.  Both Download PDF and Email to Student
        // now point at the real action URLs; an inline alert() pops the
        // Clause 12 reminder per click but does NOT return false, so the
        // browser follows the link and the action completes.  The matching
        // server-side throws in download_cert.php / email_cert.php were
        // also relaxed in this release.
        $usiWarnAttrs = [];
        if (!$usiVerified) {
            $usiWarnMsg = "Note: this student's USI has not yet been verified with the USI Registry.\n\nUnder Clause 12 of the Standards for RTOs 2025, a verified USI should be on file before issuing a Testamur or Statement of Attainment. You can still download or email this certificate, but please verify the student's USI on the Students register as soon as possible.";
            $usiWarnAttrs['onclick'] = 'alert(' . json_encode($usiWarnMsg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        }

        echo html_writer::link(
            new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $cert->id]),
            'Download PDF',
            array_merge(['class' => 'btn btn-primary', 'target' => '_blank', 'data-testid' => $usiVerified ? 'link-download-cert' : 'link-download-cert-warn'], $usiWarnAttrs)
        );

        // email_cert.php exposes a legacy GET path that shows a confirm
        // dialog before sending — perfect for a manual click here.  (The
        // certificates list uses the X-Requested-With AJAX path; we keep
        // the deliberate confirm step on the verify page so a casual
        // staff click can't accidentally re-email a learner.)
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/email_cert.php', ['id' => $cert->id]),
            'Email to Student',
            array_merge(['class' => 'btn btn-secondary', 'data-testid' => $usiVerified ? 'link-email-cert' : 'link-email-cert-warn'], $usiWarnAttrs)
        );

        echo html_writer::end_div();   // close .verify-actions
    }

    echo html_writer::end_div();   // close .verify-card
} else {
    echo html_writer::start_div('verify-card verify-failed');
    echo html_writer::tag('div', '✗', ['class' => 'verify-icon']);
    echo html_writer::tag('h2', get_string('certificate_invalid', 'local_rtocompliance'), ['class' => 'verify-title']);
    echo html_writer::tag('p', 'This certificate could not be verified. It may have been revoked or the verification code is incorrect.');
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
