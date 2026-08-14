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
 * RTO Compliance plugin — webhook.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// local_rtocompliance/webhook.php
// Receives configuration pushes from the lms-labs.com SaaS platform.
// Authenticated by X-Webhook-Key header matching stored 'webhookapikey' config.
// No Moodle session required -- key-based auth only.
// ─────────────────────────────────────────────────────────────────────────────
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');

// Machine-to-machine endpoint authenticated by the X-Webhook-Key header (checked below).
// A request with no webhook key is not a legitimate machine call — require a Moodle login.
if (empty($_SERVER['HTTP_X_WEBHOOK_KEY'])) {
    require_login();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth ─────────────────────────────────────────────────────────────────────
$incomingKey  = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '';
$storedKey    = get_config('local_rtocompliance', 'webhookapikey');

if (empty($storedKey)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platform webhook key not configured. Go to RTO Compliance → API Settings → Platform Webhook Key.']);
    exit;
}

if (!hash_equals((string) $storedKey, (string) $incomingKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized -- webhook key mismatch.']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
// Bug F fix: cap the raw body to 2 MB before reading. Without this, an attacker
// with the webhook key can send gigabyte-scale payloads -- a valid P12 cert is
// typically < 10 KB, and a full config push will never exceed a few KB.
$maxBodyBytes = 2 * 1024 * 1024; // 2 MB
$rawInput = fopen('php://input', 'r');
$body = stream_get_contents($rawInput, $maxBodyBytes);
fclose($rawInput);
if (strlen($body) >= $maxBodyBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Payload too large (max 2 MB).']);
    exit;
}
$data = json_decode($body, true);

if (!is_array($data) || !isset($data['configs']) || !is_array($data['configs'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid body -- expected {"configs": {"key": "value", ...}}']);
    exit;
}

// ── Whitelist ─────────────────────────────────────────────────────────────────
// Only these keys may be written remotely. Anything else is silently skipped.
// BUG-13 FIX: 'usi_certificate_path' was in the whitelist, allowing it to be written
// both by the base64 cert handler (which writes the cert to disk then sets the path) AND
// by the configs[] whitelist loop. If a platform push includes both
// configs['usi_certificate_path'] = '/old/stale/path' AND usi_cert_base64 = '...new...',
// the whitelist loop runs first and writes the stale path, then the base64 handler
// overwrites it correctly — but the reverse can also occur on any subsequent push that
// includes ONLY configs['usi_certificate_path'], reverting to a stale/wrong path.
// The certificate path must ONLY ever be set by the base64 cert handler, which always
// writes the cert to a known location. Remove it from the whitelist entirely.
$ALLOWED_KEYS = [
    // Platform connection
    'siteid', 'apikey', 'apiurl',
    // USI Registry — non-secret operational config only.
    // CREDENTIAL-BROKER-ONLY (v5.9.452): 'usi_certificate_password' has been REMOVED
    // from the whitelist. The myGovID machine credential and its passphrase are held
    // ONLY on the lms-labs.com platform, which performs verification on Moodle's behalf
    // via /api/usi/verify. Moodle must never store the keystore or its password, so a
    // pushed password is now ignored (reported as skipped). 'usi_cert_expiry' and
    // 'usi_cert_subject' are non-secret display metadata the platform may push.
    'usi_organization_id', 'usi_test_mode',
    'usi_cert_expiry', 'usi_cert_subject',
    // Auto-survey
    'autosurveyenable', 'autosurveydelay', 'autosurveyemailsubject',
    // NAT / report settings
    'reportyear', 'defaultstate',
];

$applied = [];
$skipped = [];

foreach ($data['configs'] as $key => $value) {
    $key = (string) $key;
    if (in_array($key, $ALLOWED_KEYS, true)) {
        set_config($key, (string) $value, 'local_rtocompliance');
        $applied[] = $key;
    } else {
        $skipped[] = $key;
    }
}

// ── Handle USI cert push ──────────────────────────────────────────────────────
// CREDENTIAL-BROKER-ONLY (v5.9.452): Moodle no longer stores the machine credential.
// The keystore lives ONLY on the lms-labs.com platform, which authenticates to the
// ATO and verifies USIs on Moodle's behalf (POST /api/usi/verify). When the platform
// signals that a credential is present for this site, we record a NON-SECRET readiness
// flag so the plugin knows USI is ready — we deliberately do NOT decode or write the
// .p12 to disk, and we do NOT store the passphrase. This keeps the (sensitive) keystore
// out of the Moodle server entirely.
if (!empty($data['usi_cert_base64']) || !empty($data['usi_cert_ready'])) {
    set_config('usi_cert_uploaded', 1, 'local_rtocompliance');
    $applied[] = 'usi_cert_uploaded (platform holds credential; keystore NOT stored in Moodle)';
}

echo json_encode([
    'ok'      => true,
    'applied' => $applied,
    'skipped' => $skipped,
    'message' => count($applied) . ' setting(s) updated successfully.',
]);
