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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/avetmiss_codes.php');
require_once(__DIR__ . '/classes/certificate_validator.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\certificate_validator;
use local_rtocompliance\usi\usi_platform_client;

admin_externalpage_setup('local_rtocompliance_certificates');
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$PAGE->set_url('/local/rtocompliance/issue_certificate.php');
$PAGE->set_title(get_string('issue_certificate', 'local_rtocompliance'));
$PAGE->set_heading(get_string('issue_certificate', 'local_rtocompliance'));


class issue_certificate_form extends moodleform {
    protected function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('issue_certificate', 'local_rtocompliance'));

        // Bug G fix: previous code loaded at most 500 users into a plain <select>,
        // silently making students whose surnames appear after the 500th alphabetical
        // entry unreachable on any medium/large site. Switched to Moodle's autocomplete
        // element (client-side filter on pre-loaded list) with a 10 000-user cap, which
        // is sufficient for any RTO while keeping the page load practical. For sites with
        // > 10 000 users the cap should be replaced with a proper AJAX user-selector.
        $users = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
             FROM {user} u
             WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1
             ORDER BY u.lastname, u.firstname",
            [],
            0,
            10000
        );

        $useroptions = ['' => 'Select a student...'];
        foreach ($users as $user) {
            $useroptions[$user->id] = fullname($user) . ' (' . $user->email . ')';
        }
        $mform->addElement('autocomplete', 'userid', get_string('certificate_student', 'local_rtocompliance'), $useroptions);
        $mform->addRule('userid', null, 'required', null, 'client');

        $certtypes = local_rtocompliance_get_certificate_types();
        $mform->addElement('select', 'certtype', get_string('certificate_type', 'local_rtocompliance'), $certtypes);
        $mform->addRule('certtype', null, 'required', null, 'client');

        // v4.3.0 CERT-TEMPLATE-AUDIENCES — audience picker so the issuer
        // chooses which testamur/SoA/RoR design applies to THIS student
        // (apprentice vs general public vs school-based vs VET-FEE etc.).
        // The active template for (certtype + audience) is resolved at
        // save time and pinned onto the cert row via certtmplid so any
        // later reissue uses the same design.
        require_once(__DIR__ . '/classes/cert_template.php');
        $audienceopts = [];
        foreach (\local_rtocompliance\cert_template::AUDIENCES as $aud) {
            $audienceopts[$aud] = get_string('cert_template_audience_' . $aud, 'local_rtocompliance');
        }
        $mform->addElement('select', 'audience',
            get_string('cert_template_audience', 'local_rtocompliance'), $audienceopts);
        $mform->setDefault('audience', 'default');
        $mform->addElement('static', 'audience_help', '',
            get_string('certificate_audience_help', 'local_rtocompliance'));

        $mform->addElement('header', 'qualification', get_string('certificate_qualification', 'local_rtocompliance'));

        $mform->addElement('text', 'qualificationcode', 'Qualification Code', ['size' => 20, 'placeholder' => 'e.g. BSB50420']);
        $mform->setType('qualificationcode', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'qualificationname', 'Qualification Name', ['size' => 60, 'placeholder' => 'e.g. Diploma of Leadership and Management']);
        $mform->setType('qualificationname', PARAM_TEXT);

        $mform->addElement('textarea', 'units', 'Units of Competency (for Statement of Attainment)',
            ['rows' => 6, 'cols' => 60, 'placeholder' => "Enter one unit per line in format: CODE - Name\nBSBLDR411 - Demonstrate leadership in the workplace\nBSBLDR412 - Communicate effectively as a workplace leader"]);
        $mform->setType('units', PARAM_TEXT);
        $mform->disabledIf('units', 'certtype', 'neq', 'statement');

        $mform->addElement('header', 'options', 'Options');

        $mform->addElement('date_selector', 'issuedate', get_string('certificate_issuedate', 'local_rtocompliance'));
        $mform->setDefault('issuedate', time());

        $mform->addElement('date_selector', 'expirydate', 'Expiry Date (optional)', ['optional' => true]);

        // FIX-SENDEMAIL-LABEL (v5.0.5): This sends a Moodle in-platform notification,
        // not a PDF email. The PDF email is a separate per-cert action from Certificates.
        $mform->addElement('advcheckbox', 'sendemail', 'Notify Student', 'Send a Moodle notification to the student when the certificate is issued');
        $mform->setDefault('sendemail', 1);

        $mform->addElement('advcheckbox', 'bypassvalidation', 'Bypass Validation', 'Issue certificate despite validation warnings (not recommended)');
        $mform->setDefault('bypassvalidation', 0);

        $mform->addElement('textarea', 'notes', 'Notes', ['rows' => 2, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('issue_certificate', 'local_rtocompliance'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['userid'])) {
            $errors['userid'] = 'Please select a student';
            return $errors;
        }

        if ($data['certtype'] === 'testamur' || $data['certtype'] === 'record') {
            if (empty($data['qualificationcode'])) {
                $errors['qualificationcode'] = 'Qualification code is required for this certificate type';
            }
            if (empty($data['qualificationname'])) {
                $errors['qualificationname'] = 'Qualification name is required for this certificate type';
            }
        }

        if ($data['certtype'] === 'statement' && empty($data['units'])) {
            $errors['units'] = 'At least one unit of competency is required for Statement of Attainment';
        }

        $unitsarray = [];
        if ($data['certtype'] === 'statement' && !empty($data['units'])) {
            $unitlines = array_filter(array_map('trim', explode("\n", $data['units'])));
            foreach ($unitlines as $line) {
                if (preg_match('/^([A-Z0-9]+)\s*[-–]\s*(.+)$/i', $line, $matches)) {
                    $unitsarray[] = ['code' => trim($matches[1]), 'name' => trim($matches[2]), 'outcome' => '20'];
                } else {
                    $unitsarray[] = ['code' => '', 'name' => trim($line), 'outcome' => '20'];
                }
            }
        }

        $validationdata = [
            'qualificationcode' => $data['qualificationcode'] ?? '',
            'units' => $unitsarray,
        ];
        
        $validation = certificate_validator::validate_certificate_issuance(
            $data['certtype'],
            $data['userid'],
            $validationdata
        );
        
        if (!$validation['can_issue'] && empty($data['bypassvalidation'])) {
            foreach ($validation['errors'] as $error) {
                $errors['userid'] = $error;
            }
        }

        return $errors;
    }
}

$form = new issue_certificate_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/certificates.php'));
} elseif ($data = $form->get_data()) {
    $unitsarray = [];
    if ($data->certtype === 'statement' && !empty($data->units)) {
        $unitlines = array_filter(array_map('trim', explode("\n", $data->units)));
        foreach ($unitlines as $line) {
            if (preg_match('/^([A-Z0-9]+)\s*[-–]\s*(.+)$/i', $line, $matches)) {
                $unitsarray[] = ['code' => trim($matches[1]), 'name' => trim($matches[2]), 'outcome' => '20'];
            } else {
                $unitsarray[] = ['code' => '', 'name' => trim($line), 'outcome' => '20'];
            }
        }
    }

    $validationdata = [
        'qualificationcode' => $data->qualificationcode ?? '',
        'units' => $unitsarray,
    ];
    
    $validation = certificate_validator::validate_certificate_issuance(
        $data->certtype,
        $data->userid,
        $validationdata
    );

    if (!$validation['can_issue'] && empty($data->bypassvalidation)) {
        redirect(
            $PAGE->url,
            'Certificate cannot be issued: ' . implode(', ', $validation['errors']),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    $prefix = get_config('local_rtocompliance', 'certprefix') ?: 'CERT';
    $year = date('Y');
    // FIX-LIKE-ESCAPE (v5.9.277): escape LIKE wildcards in $prefix (same fix
    // applied to lib.php in v5.9.276 — this file had the identical unescaped
    // pattern; a prefix containing % or _ inflates $sequence and skips numbers).
    $prefix_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    $sequence = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_certs} WHERE certnumber LIKE ?",
        [$prefix_escaped . '-' . $year . '-%']
    ) + 1;
    $certnumber = sprintf('%s-%s-%05d', $prefix, $year, $sequence);

    $units = null;
    if ($data->certtype === 'statement' && !empty($unitsarray)) {
        $units = json_encode($unitsarray);
    } elseif ($data->certtype === 'record' && !empty($data->qualificationcode)) {
        // ROR-UNITS-FIX (v5.9.226): Record of Results certs previously stored units=NULL
        // because the condition above only ran for 'statement' type.  With NULL units the
        // renderer had nothing to put in any of the three columns (Semester/Year, Unit, Results).
        // Fix: auto-populate from the student's completed enrolments for this qualification.
        // check_qualification_completion() now also includes a 'semester' field derived from
        // activityenddate so Col 1 shows the correct semester rather than the issue date.
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => (int)$data->userid]);
        if ($student) {
            $completion = certificate_validator::check_qualification_completion(
                $student->id,
                $data->qualificationcode
            );
            if (!empty($completion['units'])) {
                $units = json_encode($completion['units']);
            }
        }
    }

    $cert = new stdClass();
    $cert->userid = $data->userid;
    $cert->certnumber = $certnumber;
    $cert->certtype = $data->certtype;
    $cert->qualificationcode = $data->qualificationcode ?? '';
    $cert->qualificationname = $data->qualificationname ?? '';
    $cert->units = $units;
    $cert->issuedate = $data->issuedate;
    $cert->expirydate = !empty($data->expirydate) ? $data->expirydate : null;
    $cert->verifytoken = local_rtocompliance_generate_certificate_token();
    $cert->status = 'issued';
    $cert->issuedby = $USER->id;
    $cert->notes = $data->notes ?? '';
    $cert->emailsent = 0;
    $cert->timecreated = time();
    $cert->timemodified = time();

    // v4.3.0 CERT-TEMPLATE-AUDIENCES — resolve which template applies to
    // THIS issuance based on (certtype + audience) and pin the template
    // id onto the cert row so later reissues use the same design even
    // if the active template is later swapped. Falls back gracefully:
    // if no audience-specific template exists, certtmplid stays NULL
    // and the render dispatcher (lib.php) re-picks at render time
    // using the same precedence rules.
    require_once(__DIR__ . '/classes/cert_template.php');
    $cert->certtmplid = null;
    $auddata = !empty($data->audience) ? $data->audience : 'default';
    if (!in_array($auddata, \local_rtocompliance\cert_template::AUDIENCES, true)) {
        $auddata = 'default';
    }
    try {
        $picked = \local_rtocompliance\cert_template::pick_for_audience(
            $data->certtype, $auddata);
        if (!$picked && $auddata !== 'default') {
            // Fall back to the default-audience template if no audience-
            // specific template is configured yet.
            $picked = \local_rtocompliance\cert_template::pick_for_audience(
                $data->certtype, 'default');
        }
        if ($picked && !empty($picked->id)) {
            $cert->certtmplid = (int) $picked->id;
        }
    } catch (\Throwable $eaud) {
        debugging('cert template pick at issuance failed (non-fatal): '
            . $eaud->getMessage(), DEBUG_DEVELOPER);
    }

    // ── Credit deduction (5 credits per certificate) ──────────────────────────
    // Must happen BEFORE the DB insert so no cert record is created on failure.
    $platformclient = new usi_platform_client();
    $creditresult   = $platformclient->consume_credits(
        5,
        'certificate',
        'local_rtocompliance',
        ['certtype' => $data->certtype, 'studentid' => $data->userid, 'certnumber' => $certnumber]
    );
    if (!$creditresult['ok'] && ($creditresult['error'] ?? '') === 'INSUFFICIENT_CREDITS') {
        $buymsg = '';
        if (!empty($creditresult['buyUrl'])) {
            $buymsg = ' ' . html_writer::link(
                $creditresult['buyUrl'],
                'Purchase credits',
                ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']
            );
        }
        redirect(
            $PAGE->url,
            'Cannot issue certificate: insufficient credits. Each certificate costs 5 credits -- your current balance is ' .
            (int) $creditresult['credits'] . '.' . $buymsg,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    // ── /Credit deduction ─────────────────────────────────────────────────────

    // FIX-CREDIT-ORPHAN (v5.9.277): wrap the DB insert in try/catch so that if
    // the insert fails AFTER credits have been deducted, the failure is surfaced
    // clearly rather than silently dropping the cert with no refund.  Credits
    // cannot be automatically refunded (no refund API on usi_platform_client)
    // so the catch logs the orphaned charge with enough detail for manual review.
    try {
        $cert->id = $DB->insert_record('local_rtocompliance_certs', $cert);
    } catch (\Throwable $eins) {
        // Log orphaned credit charge for manual review — admin can apply a
        // compensatory grant via the AI Grader credits dashboard.
        error_log('[local_rtocompliance] CREDIT-ORPHAN: 5 credits consumed but '
            . 'cert DB insert failed. certnumber=' . $certnumber
            . ' userid=' . ($data->userid ?? '?')
            . ' issuedby=' . $USER->id
            . ' error=' . $eins->getMessage());
        redirect(
            $PAGE->url,
            'Certificate database insert failed after credits were deducted — '
            . 'please contact your administrator. Error: ' . s($eins->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Publish to AI Grader central verification registry (best-effort).
    // This makes the QR code scannable via essaygradeai.app/verify/<token>
    // independently of this Moodle server.
    $certissueuser = core_user::get_user($data->userid);
    if ($certissueuser) {
        local_rtocompliance_publish_cert_to_registry($cert, $certissueuser);
    }

    $logdetails = [
        'certnumber' => $certnumber,
        'certtype' => $data->certtype,
        'qualification' => $data->qualificationcode ?? '',
    ];
    
    if (!empty($validation['warnings'])) {
        $logdetails['warnings'] = $validation['warnings'];
    }
    if (!empty($data->bypassvalidation)) {
        $logdetails['validation_bypassed'] = true;
        $logdetails['bypassed_errors'] = $validation['errors'] ?? [];
    }

    $log = new stdClass();
    $log->action = 'issue';
    $log->component = 'certs';
    $log->itemid = $cert->id;
    $log->userid = $USER->id;
    $log->targetuserid = $data->userid;
    $log->details = json_encode($logdetails);
    $log->ipaddress = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    // FIX-SENDEMAIL-RESPECTED (v5.0.5): honour the "Notify Student" checkbox.
    // Previously message_send() was called unconditionally regardless of whether
    // the admin had unchecked the notification box. Also update emailsent=1 in the
    // cert record when the notification is successfully dispatched.
    if (!empty($data->sendemail)) {
        $recipient = core_user::get_user($data->userid);
        if ($recipient) {
            $certtypes = local_rtocompliance_get_certificate_types();
            $certtypename = $certtypes[$data->certtype] ?? $data->certtype;

            $qualname = '';
            if (!empty($data->qualificationcode)) {
                $qualname = $data->qualificationcode;
                if (!empty($data->qualificationname)) {
                    $qualname .= ' - ' . $data->qualificationname;
                }
            }

            $downloadurl = new moodle_url('/local/rtocompliance/mycerts.php');

            $messagehtml = get_string('certificate_notification_message', 'local_rtocompliance', [
                'firstname'    => $recipient->firstname,
                'certtype'     => $certtypename,
                'certnumber'   => $certnumber,
                'qualification' => $qualname,
                'downloadlink' => $downloadurl->out(false),
                'rtoname'      => get_config('local_rtocompliance', 'rtoname') ?: 'Training Organisation',
            ]);

            $eventdata = new \core\message\message();
            $eventdata->component = 'local_rtocompliance';
            $eventdata->name = 'certificate_issued';
            $eventdata->userfrom = \core_user::get_noreply_user();
            $eventdata->userto = $recipient;
            $eventdata->subject = get_string('certificate_notification_subject', 'local_rtocompliance', $certtypename);
            $eventdata->fullmessage = strip_tags($messagehtml);
            $eventdata->fullmessageformat = FORMAT_HTML;
            $eventdata->fullmessagehtml = $messagehtml;
            $eventdata->smallmessage = get_string('certificate_notification_subject', 'local_rtocompliance', $certtypename);
            $eventdata->notification = 1;
            $eventdata->contexturl = $downloadurl;
            $eventdata->contexturlname = get_string('mycertificates', 'local_rtocompliance');

            try {
                message_send($eventdata);
                // Mark notification as sent on the cert record so the Certificates
                // list correctly shows the "Emailed" badge for this cert.
                $DB->set_field('local_rtocompliance_certs', 'emailsent', 1, ['id' => $cert->id]);
            } catch (Exception $e) {
                debugging('Failed to send certificate notification: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    $message = get_string('certificate_issued', 'local_rtocompliance') . ' (' . $certnumber . ')';
    
    if (!empty($validation['warnings'])) {
        $message .= ' - Warnings: ' . implode(', ', $validation['warnings']);
    }

    redirect(
        new moodle_url('/local/rtocompliance/certificates.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('issue_certificate', 'local_rtocompliance'), get_string('certificates', 'local_rtocompliance'), '/local/rtocompliance/certificates.php', 'certificates');

echo html_writer::start_div('', ['style' => 'max-width: 800px; margin: 0 auto; padding: 20px;']);

$rtoname = get_config('local_rtocompliance', 'rtoname');
$rtocode = get_config('local_rtocompliance', 'rtocode');

if (empty($rtoname) || empty($rtocode)) {
    echo $OUTPUT->notification(
        'Please configure your RTO details before issuing certificates. ' .
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
            'Configure RTO Settings',
            ['class' => 'btn btn-sm btn-primary ml-2']
        ),
        'warning'
    );
}

// ── Credit cost information panel ────────────────────────────────────────────
$credpanel_client  = new usi_platform_client();
$credpanel_balance = $credpanel_client->get_credit_balance();

$cert_cost = 5;

if (!$credpanel_balance['configured']) {
    // Platform not configured -- soft advisory only.
    $cred_html = html_writer::tag('div',
        html_writer::tag('h4',
            '&#128179; Credit Cost -- Certificate Issuance',
            ['style' => 'margin: 0 0 8px; font-size: 15px; color: #0369a1;']
        ) .
        html_writer::tag('p',
            '<strong>Each certificate issued costs ' . $cert_cost . ' credits.</strong> ' .
            'Connect your RTO Compliance Platform account in the plugin settings to manage credits.',
            ['style' => 'margin: 0; color: #374151;']
        ),
        ['style' => 'background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; display: flex; flex-direction: column; gap: 4px;']
    );
} else {
    $balance_val = $credpanel_balance['balance'] ?? 0;
    $unlimited   = $credpanel_balance['unlimited'];
    $balance_ok  = $unlimited || ($balance_val >= $cert_cost);

    if ($unlimited) {
        $badge_html = html_writer::tag('span', 'UNLIMITED',
            ['style' => 'background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;']);
        $after_html = '';
    } elseif ($balance_ok) {
        $badge_html = html_writer::tag('span', number_format($balance_val) . ' credits',
            ['style' => 'background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;']);
        $after      = $balance_val - $cert_cost;
        $after_html = html_writer::tag('span', 'After issue: ' . number_format($after) . ' remaining',
            ['style' => 'color:#4b5563;font-size:12px;']);
    } else {
        $badge_html = html_writer::tag('span', number_format($balance_val) . ' credits (insufficient)',
            ['style' => 'background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;']);
        $after_html = '';
    }

    $buy_link = '';
    if (!$unlimited) {
        $buy_url  = 'https://lms-labs.com/pricing';
        $buy_link = '&ensp;' . html_writer::link($buy_url,
            '+ Purchase credits',
            ['target' => '_blank',
             'style'  => 'font-size:13px;color:#0369a1;text-decoration:underline;white-space:nowrap;']);
    }

    $warn_html = '';
    if (!$balance_ok) {
        $warn_html = html_writer::tag('div',
            '&#9888; You do not have enough credits to issue a certificate. Each certificate costs <strong>' .
            $cert_cost . ' credits</strong> and your current balance is <strong>' . number_format($balance_val) .
            '</strong>. Please purchase more credits before proceeding.',
            ['style' => 'background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 12px;margin-top:8px;font-size:13px;color:#7f1d1d;']
        );
    }

    $cred_html = html_writer::tag('div',
        html_writer::tag('div',
            html_writer::tag('div',
                html_writer::tag('h4',
                    '&#128179; Certificate Cost',
                    ['style' => 'margin:0;font-size:15px;color:#0369a1;']
                ) .
                html_writer::tag('p',
                    'Issuing a certificate deducts <strong>' . $cert_cost . ' credits</strong> from your account balance.',
                    ['style' => 'margin:4px 0 0;color:#374151;font-size:13px;']
                ),
                ['style' => 'flex:1;']
            ) .
            html_writer::tag('div',
                html_writer::tag('div',
                    html_writer::tag('span', 'Current balance:&nbsp;', ['style'=>'font-size:12px;color:#6b7280;']) .
                    $badge_html . $buy_link,
                    ['style'=>'display:flex;align-items:center;gap:6px;flex-wrap:wrap;']
                ) .
                ($after_html ? html_writer::tag('div', $after_html, ['style'=>'margin-top:4px;text-align:right;']) : ''),
                ['style' => 'text-align:right;white-space:nowrap;']
            ),
            ['style' => 'display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;']
        ) .
        $warn_html,
        ['style' => 'background:#f0f9ff;border:1px solid ' . ($balance_ok ? '#bae6fd' : '#fca5a5') . ';border-radius:8px;padding:14px 16px;margin-bottom:18px;']
    );
}
echo $cred_html;
// ── /Credit cost information panel ───────────────────────────────────────────

echo html_writer::tag('div', 
    html_writer::tag('h4', 'Certificate Issuance Rules', ['style' => 'margin-bottom: 12px;']) .
    html_writer::tag('ul', 
        html_writer::tag('li', '<strong>Testamur/Qualification:</strong> Requires valid USI and complete AVETMISS profile. Student must have completed all core and required elective units.') .
        html_writer::tag('li', '<strong>Statement of Attainment:</strong> Requires valid USI. At least one unit must have a competent outcome (20, 51, 52, 60, 81, 82).') .
        html_writer::tag('li', '<strong>Record of Results:</strong> Issued with Testamur. Lists all units and outcomes.') .
        html_writer::tag('li', '<strong>Certificate of Attendance:</strong> For non-accredited training only. No USI or competency requirements.')
    ),
    ['style' => 'background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; padding: 16px; margin-bottom: 24px;']
);

$form->display();

echo html_writer::end_div();

echo $OUTPUT->footer();
