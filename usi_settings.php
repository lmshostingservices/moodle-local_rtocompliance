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

// This file is part of Moodle - http://moodle.org/
//
// RTO Compliance — USI Verification Settings (per-tenant cert upload)
// PER-TENANT-USI v4.2.38 (1 May 2026) — full self-service onboarding
//
// Each customer RTO supplies its OWN myID Machine Credential (.xml/.pfx) +
// password + TOID via this page.  The credential is uploaded to the
// lms-labs.com platform's /api/rto/usi-cert/upload endpoint, which
// stores it in client_rto_configs scoped to this site's siteid.  The next
// /api/usi/verify call uses this site's credential rather than a shared
// platform credential — so each RTO verifies students against ITS OWN TOID.
//
// v4.2.38 additions:
//   - notification_email field (60/30/7 day expiry warnings)
//   - cert subject + expiry + days-remaining displayed in Status panel
//   - prominent "API Connection required" gate when api_url/siteid/apikey unset
//   - file accept list extended to .xml/.pfx/.p12 with explanatory text
//   - help panel rebuilt with prerequisites, step-by-step, troubleshooting

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$PAGE->set_url(new moodle_url('/local/rtocompliance/usi_settings.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('usi_pertenant_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('usi_pertenant_title', 'local_rtocompliance'));

$apiurl = trim((string) (get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com'));
$siteid = trim((string) get_config('local_rtocompliance', 'siteid'));
$apikey = trim((string) get_config('local_rtocompliance', 'apikey'));

$apiconfigured = ($apiurl !== '' && $siteid !== '' && $apikey !== '');

$message = '';
$messageclass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $orgid     = trim((string) optional_param('org_id', '', PARAM_TEXT));
    $certpass  = (string) optional_param('cert_password', '', PARAM_RAW);
    $testmode  = optional_param('test_mode', 0, PARAM_BOOL) ? 1 : 0;
    $notifmail = trim((string) optional_param('notification_email', '', PARAM_EMAIL));

    $certb64 = '';
    if (!empty($_FILES['cert_file']['tmp_name']) && is_uploaded_file($_FILES['cert_file']['tmp_name'])) {
        $bytes = file_get_contents($_FILES['cert_file']['tmp_name']);
        if ($bytes === false || strlen($bytes) < 100) {
            $message = get_string('usi_pertenant_err_filetoo_small', 'local_rtocompliance');
            $messageclass = 'alert-danger';
        } else {
            $certb64 = base64_encode($bytes);
        }
    } else {
        $message = get_string('usi_pertenant_err_nofile', 'local_rtocompliance');
        $messageclass = 'alert-danger';
    }

    if ($certb64 !== '' && $orgid === '') {
        $message = get_string('usi_pertenant_err_no_orgid', 'local_rtocompliance');
        $messageclass = 'alert-danger';
        $certb64 = '';
    }

    if ($certb64 !== '' && !$apiconfigured) {
        $message = get_string('usi_pertenant_err_noapi', 'local_rtocompliance');
        $messageclass = 'alert-danger';
        $certb64 = '';
    }

    if ($certb64 !== '') {
        require_once($CFG->dirroot . '/local/rtocompliance/classes/usi/usi_platform_client.php');
        $client = new \local_rtocompliance\usi\usi_platform_client(['test_mode' => (bool) $testmode]);
        $result = $client->upload_cert($certb64, $certpass, $orgid, (bool) $testmode, $notifmail);
        if (!empty($result['ok'])) {
            // If the platform returned an apiKey (new registration or key-drift recovery),
            // save it immediately into plugin config so subsequent uploads and verify
            // calls authenticate correctly.  Without this the admin is permanently locked
            // out after the first upload because the auto-generated key is never saved.
            $returnedApiKey = isset($result['apiKey']) && strlen((string) $result['apiKey']) >= 16
                ? (string) $result['apiKey'] : null;
            if ($returnedApiKey !== null) {
                set_config('apikey', $returnedApiKey, 'local_rtocompliance');
                // Also update Central Config (local_aiconfig) if installed, so all plugins
                // that read from aiconfig pick up the correct key immediately.
                $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
                if (file_exists($aiconfiglib)) {
                    require_once($aiconfiglib);
                    set_config('apikey', $returnedApiKey, 'local_aiconfig');
                }
                // Update the local variable so the status panel below reflects the new key.
                $apikey = $returnedApiKey;
                $apiconfigured = ($apiurl !== '' && $siteid !== '' && $apikey !== '');
            }

            $message = get_string('usi_pertenant_uploaded', 'local_rtocompliance', [
                'bytes' => (int) ($result['certBytes'] ?? 0),
                'org'   => s($result['orgId'] ?? $orgid),
                'mode'  => $testmode ? 'EVTE (test)' : 'PRODUCTION',
            ]);
            // Append the saved-key notice so the admin knows it was auto-saved.
            if ($returnedApiKey !== null) {
                $message .= '<br><strong>&#x2705; Platform API key saved automatically.</strong> '
                    . 'Your API key is: <code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;">'
                    . s($returnedApiKey) . '</code> '
                    . 'It has been saved to your Plugin Settings → Platform API tab.';
            }
            $messageclass = 'alert-success';
        } else {
            $message = get_string('usi_pertenant_upload_failed', 'local_rtocompliance')
                . ' — ' . s($result['error'] ?? 'unknown error');
            $messageclass = 'alert-danger';
        }
    }
}

// Fetch current platform-side status
$status = null;
if ($apiconfigured) {
    // Use Moodle's \curl wrapper so site proxy settings are respected.
    \core\session\manager::write_close();
    $mcurl = new \curl();
    $mcurl->setopt(['CURLOPT_TIMEOUT' => 12, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
    $mcurl->setHeader(['X-Site-Id: ' . $siteid, 'X-Api-Key: ' . $apikey]);
    $resp = $mcurl->get(rtrim($apiurl, '/') . '/api/usi/status');
    $code = (int) $mcurl->info['http_code'];
    if ($code === 200 && $resp) {
        $decoded = json_decode($resp, true);
        if (is_array($decoded)) $status = $decoded;
    }
}

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('usi_pertenant_title', 'local_rtocompliance'), null, null, 'usi');

// Header banner
echo '<div style="background:linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%);color:white;padding:20px 24px;border-radius:8px;margin-bottom:24px;">';
echo '<h2 style="margin:0;color:white;font-size:22px;">' . get_string('usi_pertenant_title', 'local_rtocompliance') . '</h2>';
echo '<div style="opacity:.9;font-size:14px;margin-top:6px;">' . get_string('usi_pertenant_intro', 'local_rtocompliance') . '</div>';
echo '</div>';

// Flash message
if ($message !== '') {
    echo '<div class="alert ' . $messageclass . '" style="margin-bottom:20px;">' . $message . '</div>';
}


// Current status panel
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 12px 0;font-size:16px;">' . get_string('usi_pertenant_currentstatus', 'local_rtocompliance') . '</h3>';
if ($status && !empty($status['ok'])) {
    $ready   = !empty($status['certReady']);
    $expired = !empty($status['expired']);
    $warn    = !empty($status['expiryWarn']) && !$expired;
    if ($expired) {
        $colour = '#ef4444';
        $label  = get_string('usi_pertenant_expired', 'local_rtocompliance');
    } else if (!$ready) {
        $colour = '#ef4444';
        $label  = get_string('usi_pertenant_notready', 'local_rtocompliance');
    } else {
        $colour = '#10b981';
        $label  = get_string('usi_pertenant_ready', 'local_rtocompliance');
    }
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
    echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $colour . ';"></span>';
    echo '<strong style="color:' . $colour . ';">' . $label . '</strong>';
    echo '</div>';

    if ($warn) {
        echo '<div class="alert alert-warning" style="margin-bottom:12px;">' . get_string('usi_pertenant_expirywarn', 'local_rtocompliance') . '</div>';
    }

    echo '<div style="font-size:13px;color:#374151;display:grid;grid-template-columns:200px 1fr;gap:6px 16px;">';
    echo '<div><b>' . get_string('usi_pertenant_source', 'local_rtocompliance') . ':</b></div><div>' . s($status['source'] ?? '—') . '</div>';
    echo '<div><b>' . get_string('usi_pertenant_orgid', 'local_rtocompliance') . ':</b></div><div>' . s((string)($status['orgId'] ?? '—')) . '</div>';
    echo '<div><b>' . get_string('usi_pertenant_mode', 'local_rtocompliance') . ':</b></div><div>' . (!empty($status['testMode']) ? 'EVTE (test sandbox)' : 'PRODUCTION (live USI Registry)') . '</div>';
    if (!empty($status['certSubject'])) {
        echo '<div><b>' . get_string('usi_pertenant_certsubject', 'local_rtocompliance') . ':</b></div><div>' . s($status['certSubject']) . '</div>';
    }
    if (!empty($status['certExpiry'])) {
        $expts = strtotime($status['certExpiry']);
        $expdate = $expts ? userdate($expts, '%d %b %Y') : s($status['certExpiry']);
        echo '<div><b>' . get_string('usi_pertenant_certexpiry', 'local_rtocompliance') . ':</b></div><div>' . s($expdate) . '</div>';
    }
    if (isset($status['daysToExpiry']) && $status['daysToExpiry'] !== null) {
        $days = (int)$status['daysToExpiry'];
        $dcol = $expired ? '#ef4444' : ($warn ? '#f59e0b' : '#10b981');
        echo '<div><b>' . get_string('usi_pertenant_daystoexpiry', 'local_rtocompliance') . ':</b></div><div style="color:' . $dcol . ';font-weight:600;">' . $days . '</div>';
    }
    if (!empty($status['notificationEmail'])) {
        echo '<div><b>' . get_string('usi_pertenant_notifemail', 'local_rtocompliance') . ':</b></div><div>' . s($status['notificationEmail']) . '</div>';
    }
    echo '</div>';

    if (!empty($status['message'])) {
        echo '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #f3f4f6;color:#6b7280;font-size:12px;">' . s($status['message']) . '</div>';
    }
} else if (!$apiconfigured) {
    echo '<div class="alert alert-warning" style="margin:0;">' . get_string('usi_pertenant_err_noapi', 'local_rtocompliance') . '</div>';
} else {
    echo '<div class="alert alert-warning" style="margin:0;">' . get_string('usi_pertenant_err_status', 'local_rtocompliance') . '</div>';
}
echo '</div>';

// Upload form
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 12px 0;font-size:16px;">' . get_string('usi_pertenant_uploadtitle', 'local_rtocompliance') . '</h3>';
echo '<p style="color:#6b7280;font-size:14px;margin:0 0 16px 0;">' . get_string('usi_pertenant_uploadintro', 'local_rtocompliance') . '</p>';

echo '<form method="post" enctype="multipart/form-data" action="' . $PAGE->url->out(false) . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Pre-fill notification email from current status if available
$prefilledEmail = ($status && !empty($status['notificationEmail'])) ? $status['notificationEmail'] : '';

echo '<div class="form-group" style="margin-bottom:18px;">';
echo '<label style="font-weight:600;display:block;margin-bottom:4px;">' . get_string('usi_pertenant_field_orgid', 'local_rtocompliance') . '</label>';
echo '<input type="text" name="org_id" class="form-control" placeholder="e.g. 50918" maxlength="20" style="max-width:300px;">';
echo '<small class="form-text text-muted" style="display:block;margin-top:6px;line-height:1.55;">' . get_string('usi_pertenant_field_orgid_help', 'local_rtocompliance') . '</small>';
echo '</div>';

echo '<div class="form-group" style="margin-bottom:18px;">';
echo '<label style="font-weight:600;display:block;margin-bottom:4px;">' . get_string('usi_pertenant_field_certfile', 'local_rtocompliance') . '</label>';
echo '<input type="file" name="cert_file" accept=".xml,.pfx,.p12" class="form-control-file" required>';
echo '<small class="form-text text-muted" style="display:block;margin-top:6px;line-height:1.55;">' . get_string('usi_pertenant_field_certfile_help', 'local_rtocompliance') . '</small>';
echo '</div>';

echo '<div class="form-group" style="margin-bottom:18px;">';
echo '<label style="font-weight:600;display:block;margin-bottom:4px;">' . get_string('usi_pertenant_field_password', 'local_rtocompliance') . '</label>';
echo '<input type="password" name="cert_password" class="form-control" autocomplete="off" style="max-width:400px;">';
echo '<small class="form-text text-muted" style="display:block;margin-top:6px;line-height:1.55;">' . get_string('usi_pertenant_field_password_help', 'local_rtocompliance') . '</small>';
echo '</div>';

echo '<div class="form-group" style="margin-bottom:18px;">';
echo '<label style="font-weight:600;display:block;margin-bottom:4px;">' . get_string('usi_pertenant_field_notifemail', 'local_rtocompliance') . '</label>';
echo '<input type="email" name="notification_email" class="form-control" placeholder="compliance@yourrto.com.au" maxlength="255" value="' . s($prefilledEmail) . '" style="max-width:400px;">';
echo '<small class="form-text text-muted" style="display:block;margin-top:6px;line-height:1.55;">' . get_string('usi_pertenant_field_notifemail_help', 'local_rtocompliance') . '</small>';
echo '</div>';

echo '<div class="form-check" style="margin-bottom:24px;">';
echo '<input type="checkbox" id="cb_testmode" name="test_mode" value="1" class="form-check-input" checked>';
echo '<label class="form-check-label" for="cb_testmode" style="font-weight:600;">' . get_string('usi_pertenant_field_testmode', 'local_rtocompliance') . '</label>';
echo '<div><small class="form-text text-muted" style="display:block;margin-top:6px;line-height:1.55;">' . get_string('usi_pertenant_field_testmode_help', 'local_rtocompliance') . '</small></div>';
echo '</div>';

echo '<button type="submit" class="btn btn-primary">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
echo get_string('usi_pertenant_submit', 'local_rtocompliance');
echo '</button>';

echo '</form>';
echo '</div>';

// Help panel — comprehensive walk-through
echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 6px 0;font-size:16px;">' . get_string('usi_pertenant_help_title', 'local_rtocompliance') . '</h3>';
echo '<p style="margin:0 0 16px 0;color:#6b7280;font-size:13px;">' . get_string('usi_pertenant_help_intro', 'local_rtocompliance') . '</p>';

// Prerequisites
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin-bottom:14px;">';
echo '<h4 style="margin:0 0 8px 0;font-size:14px;color:#0369a1;">' . get_string('usi_pertenant_help_prereq_title', 'local_rtocompliance') . '</h4>';
echo '<ol style="margin:0;padding-left:20px;color:#374151;font-size:13px;line-height:1.7;">';
for ($i = 1; $i <= 5; $i++) {
    echo '<li>' . get_string('usi_pertenant_help_prereq_' . $i, 'local_rtocompliance') . '</li>';
}
echo '</ol>';
echo '</div>';

// Create the credential
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin-bottom:14px;">';
echo '<h4 style="margin:0 0 8px 0;font-size:14px;color:#0369a1;">' . get_string('usi_pertenant_help_step_title', 'local_rtocompliance') . '</h4>';
echo '<ol style="margin:0;padding-left:20px;color:#374151;font-size:13px;line-height:1.7;">';
for ($i = 1; $i <= 5; $i++) {
    echo '<li>' . get_string('usi_pertenant_help_step' . $i, 'local_rtocompliance') . '</li>';
}
echo '</ol>';
echo '</div>';

// Upload
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin-bottom:14px;">';
echo '<h4 style="margin:0 0 8px 0;font-size:14px;color:#0369a1;">' . get_string('usi_pertenant_help_upload_title', 'local_rtocompliance') . '</h4>';
echo '<ol style="margin:0;padding-left:20px;color:#374151;font-size:13px;line-height:1.7;">';
for ($i = 1; $i <= 4; $i++) {
    echo '<li>' . get_string('usi_pertenant_help_upload_' . $i, 'local_rtocompliance') . '</li>';
}
echo '</ol>';
echo '</div>';

// Renewal
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;margin-bottom:14px;">';
echo '<h4 style="margin:0 0 8px 0;font-size:14px;color:#0369a1;">' . get_string('usi_pertenant_help_renewal_title', 'local_rtocompliance') . '</h4>';
echo '<p style="margin:0;color:#374151;font-size:13px;line-height:1.6;">' . get_string('usi_pertenant_help_renewal_body', 'local_rtocompliance') . '</p>';
echo '</div>';

// Troubleshooting
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:14px 18px;">';
echo '<h4 style="margin:0 0 8px 0;font-size:14px;color:#b45309;">' . get_string('usi_pertenant_help_troubleshoot_title', 'local_rtocompliance') . '</h4>';
echo '<ul style="margin:0;padding-left:20px;color:#374151;font-size:13px;line-height:1.7;">';
echo '<li>' . get_string('usi_pertenant_help_troubleshoot_extension', 'local_rtocompliance') . '</li>';
echo '<li>' . get_string('usi_pertenant_help_troubleshoot_authority', 'local_rtocompliance') . '</li>';
echo '<li>' . get_string('usi_pertenant_help_troubleshoot_password', 'local_rtocompliance') . '</li>';
echo '<li>' . get_string('usi_pertenant_help_troubleshoot_rejection', 'local_rtocompliance') . '</li>';
echo '</ul>';
echo '</div>';

echo '</div>';

echo $OUTPUT->footer();
