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

namespace local_rtocompliance\usi;

defined('MOODLE_INTERNAL') || die();

// Moodle's \curl wrapper class lives in lib/filelib.php.
// When this class is loaded via PHP's namespace autoloader the file is not
// auto-included — we must require it explicitly before calling new \curl().
global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * USI Platform Proxy Client
 *
 * Routes all USI verification requests through the lms-labs.com platform
 * instead of calling the ATO STS and USI Registry directly.
 * The platform holds the shared machine credential (P12 cert) and org ID,
 * so no per-site credentials are needed on the Moodle side.
 *
 * Drop-in replacement for usi_registry_client.php.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class usi_platform_client {
    const REQUEST_TIMEOUT_SECONDS = 30;

    private $apiurl;
    private $siteid;
    private $apikey;
    private $is_test_mode;
    private $last_error;

    public function __construct($config = []) {
        global $CFG;

        $this->is_test_mode = (bool) ($config['test_mode'] ?? true);

        // Load Central Config (local_aiconfig) if installed — it takes priority over
        // plugin-specific settings. Falls back to local_rtocompliance settings automatically.
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }

        $this->siteid = function_exists('local_aiconfig_get_siteid')
            ? (local_aiconfig_get_siteid('local_rtocompliance') ?: '')
            : (get_config('local_rtocompliance', 'siteid') ?: '');

        $this->apikey = function_exists('local_aiconfig_get_apikey')
            ? (local_aiconfig_get_apikey('local_rtocompliance') ?: '')
            : (get_config('local_rtocompliance', 'apikey') ?: '');

        // apiurl has no central config equivalent — always read from plugin settings.
        $this->apiurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
    }

    /**
     * Verify a single USI via the platform API.
     *
     * @param string $usi          10-character USI
     * @param string $firstname    Student first name
     * @param string $lastname     Student family name
     * @param string $dateofbirth  YYYY-MM-DD
     * @return array ['verified'=>bool, 'status'=>string, 'message'=>string, 'details'=>array]
     */
    public function verify_usi($usi, $firstname, $lastname, $dateofbirth) {
        $this->last_error = null;

        $validation = $this->validate_input($usi, $firstname, $lastname, $dateofbirth);
        if (!$validation['valid']) {
            return [
                'verified' => false,
                'status'   => 'INVALID_INPUT',
                'message'  => $validation['error'],
                'details'  => [],
            ];
        }

        if (!$this->is_platform_configured()) {
            return [
                'verified' => false,
                'status'   => 'NOT_CONFIGURED',
                'message'  => 'Platform API not configured. Go to RTO Compliance → API Settings and enter your Site ID and API key.',
                'details'  => [],
            ];
        }

        $payload = json_encode([
            'usi'          => strtoupper($usi),
            'firstname'    => $firstname,
            'lastname'     => $lastname,
            'dateofbirth'  => $dateofbirth,
            'siteid'       => $this->siteid,
            'apikey'       => $this->apikey,
        ]);

        $endpoint = $this->apiurl . '/api/usi/verify';

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $raw      = $curl->post($endpoint, $payload);
        $httpcode = $curl->info['http_code'];
        $error    = $curl->error;

        if ($error) {
            $this->last_error = $error;
            return [
                'verified' => false,
                'status'   => 'NETWORK_ERROR',
                'message'  => 'Could not reach the lms-labs.com platform: ' . $error,
                'details'  => [],
            ];
        }

        $data = @json_decode($raw, true);

        if ($httpcode === 503 || ($data && ($data['status'] ?? '') === 'CERT_PENDING')) {
            return [
                'verified' => false,
                'status'   => 'CERT_PENDING',
                'message'  => 'USI verification service is pending — platform machine credential not yet configured. Contact support.',
                'details'  => [],
            ];
        }

        if ($httpcode === 401) {
            return [
                'verified' => false,
                'status'   => 'AUTH_ERROR',
                'message'  => 'Invalid Site ID or API key. Check RTO Compliance → API Settings.',
                'details'  => [],
            ];
        }

        if (!$data || $httpcode >= 500) {
            // BUG-USI-PLATFORM-MSG: Surface the actual server-side error message
            // (e.g. "USI Registry call failed: bad decrypt") instead of the generic
            // "Platform error" placeholder, so admins can diagnose configuration issues
            // (wrong RTO_USI_CERT_PASSWORD, expired cert, ATO endpoint down, etc.)
            // without having to dig through server logs.
            $servermsg = '';
            if (is_array($data)) {
                if (!empty($data['message'])) {
                    $servermsg = (string) $data['message'];
                }
                if (!empty($data['details']) && is_array($data['details']) && !empty($data['details']['error'])) {
                    $errdetail = $data['details']['error'];
                    if (is_array($errdetail)) {
                        $errdetail = json_encode($errdetail);
                    }
                    $servermsg .= ' [' . substr((string) $errdetail, 0, 200) . ']';
                }
            }
            if ($servermsg === '' && !empty($raw)) {
                // Fall back to raw response body (truncated) when JSON decode failed.
                $servermsg = 'Non-JSON response: ' . substr((string) $raw, 0, 200);
            }
            if ($servermsg === '') {
                $servermsg = 'Platform returned HTTP ' . $httpcode . ' with no body.';
            }
            $this->last_error = "HTTP $httpcode: " . $servermsg;
            return [
                'verified' => false,
                'status'   => is_array($data) && !empty($data['status']) ? (string) $data['status'] : 'PLATFORM_ERROR',
                'message'  => 'Verification failed (HTTP ' . $httpcode . '): ' . $servermsg,
                'details'  => ['http_code' => $httpcode, 'server_response' => is_array($data) ? $data : null],
            ];
        }

        return [
            'verified' => (bool) ($data['verified'] ?? false),
            'status'   => $data['status']  ?? 'UNKNOWN',
            'message'  => $data['message'] ?? 'No message from platform',
            'details'  => $data['details'] ?? [],
        ];
    }

    /**
     * Batch verify — calls verify_usi() per student.
     */
    public function verify_batch($students) {
        $results = [];
        foreach ($students as $student) {
            $usi = $student['usi'];
            usleep(100000);
            $results[$usi] = $this->verify_usi(
                $usi,
                $student['firstname'],
                $student['lastname'],
                $student['dateofbirth']
            );
        }
        return $results;
    }

    /**
     * Check whether the platform API is usable (siteid + apikey set).
     */
    public function has_valid_credential() {
        return $this->is_platform_configured();
    }

    /**
     * Return credential expiry — not applicable for platform client (cert lives on server).
     */
    public function get_credential_expiry() {
        return null;
    }

    /**
     * Test the platform connection by pinging /api/usi/status.
     */
    public function test_connection() {
        if (!$this->is_platform_configured()) {
            return [
                'connected' => false,
                'message'   => 'Platform API not configured (no siteid/apikey).',
            ];
        }

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 10]);
        $curl->setHeader(['X-Site-Id: ' . $this->siteid, 'X-Api-Key: ' . $this->apikey]);
        $raw      = $curl->get($this->apiurl . '/api/usi/status');
        $httpcode = $curl->info['http_code'];
        $error    = $curl->error;

        if ($error) {
            return ['connected' => false, 'message' => 'cURL error: ' . $error];
        }

        $data = @json_decode($raw, true);
        return [
            'connected'    => ($httpcode === 200),
            'message'      => $data['message'] ?? "HTTP $httpcode",
            'cert_ready'   => $data['certReady'] ?? false,
            'test_mode'    => $data['testMode']  ?? true,
        ];
    }

    /**
     * Fetch the client's current credit balance from the platform.
     *
     * Returns an array:
     *   ['ok'=>true,  'balance'=>N,  'unlimited'=>bool, 'configured'=>true]  on success
     *   ['ok'=>false, 'balance'=>null, 'unlimited'=>false, 'configured'=>false] when not configured
     *   ['ok'=>false, 'balance'=>null, 'unlimited'=>false, 'configured'=>true]  on error
     */
    public function get_credit_balance() {
        if (!$this->is_platform_configured()) {
            return ['ok' => false, 'balance' => null, 'unlimited' => false, 'configured' => false];
        }

        // API key sent as header (not query string) to keep it out of server access logs.
        $url = $this->apiurl . '/api/credits?siteId=' . urlencode($this->siteid);

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 10, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['X-API-Key: ' . $this->apikey]);
        $raw      = $curl->get($url);
        $httpcode = $curl->info['http_code'];
        $curlerr  = $curl->error;

        if ($curlerr || $httpcode !== 200) {
            return ['ok' => false, 'balance' => null, 'unlimited' => false, 'configured' => true];
        }

        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'balance' => null, 'unlimited' => false, 'configured' => true];
        }

        $balance   = $data['credits'] ?? $data['creditsBalance'] ?? 0;
        $unlimited = ($balance === -1);
        return [
            'ok'        => true,
            'balance'   => $balance,
            'unlimited' => $unlimited,
            'configured' => true,
        ];
    }

    /**
     * Consume credits for a feature via the platform API.
     *
     * Called before performing any credit-costing action (e.g. certificate issuance).
     * Cost: 5 credits per certificate generation.
     *
     * Returns ['ok'=>true] on success or when the platform is not configured / network error
     * (fail-open policy keeps the plugin functional on unconfigured sites).
     * Returns ['ok'=>false, 'error'=>'INSUFFICIENT_CREDITS', 'buyUrl'=>string] when the
     * client genuinely has no credits — the caller must block the action and show the error.
     *
     * @param int    $amount    Credits to consume (e.g. 5 for a certificate)
     * @param string $usagetype Short label logged in the usage ledger (e.g. 'certificate')
     * @param string $pluginid  Plugin component name (e.g. 'local_rtocompliance')
     * @param array  $metadata  Optional key→value metadata to store with the usage event
     * @return array ['ok'=>bool, 'error'=>string|null, 'credits'=>int, 'buyUrl'=>string|null]
     */
    public function consume_credits($amount, $usagetype = 'certificate', $pluginid = 'local_rtocompliance', $metadata = []) {
        if (!$this->is_platform_configured()) {
            // Fail-open: no credentials configured, allow the action so unconfigured sites work.
            return ['ok' => true, 'error' => null, 'credits' => -1, 'buyUrl' => null];
        }

        $payload = json_encode([
            'siteId'    => $this->siteid,
            'apiKey'    => $this->apikey,
            'amount'    => (int) $amount,
            'pluginId'  => $pluginid,
            'usageType' => $usagetype,
            'metadata'  => $metadata,
        ]);

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $raw      = $curl->post($this->apiurl . '/api/consume-credit', $payload);
        $httpcode = $curl->info['http_code'];
        $curlerr  = $curl->error;

        if ($curlerr) {
            // Network error — fail-open so availability is maintained.
            return ['ok' => true, 'error' => 'NETWORK_ERROR', 'credits' => -1, 'buyUrl' => null];
        }

        $data = @json_decode($raw, true);

        if (!is_array($data)) {
            // Unparseable response — fail-open.
            return ['ok' => true, 'error' => 'PLATFORM_ERROR', 'credits' => -1, 'buyUrl' => null];
        }

        return [
            'ok'     => (bool) ($data['ok'] ?? false),
            'error'  => $data['error']  ?? null,
            'credits' => $data['credits'] ?? 0,
            'buyUrl'  => $data['buyUrl']  ?? null,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function is_platform_configured() {
        return !empty($this->siteid) && !empty($this->apikey) && !empty($this->apiurl);
    }

    private function validate_input($usi, $firstname, $lastname, $dateofbirth) {
        // C1-USI-VALIDATE-FIX (v5.9.305): the previous regex used the /i flag (case-
        // insensitive), which accepted 0, 1, I, and O — characters explicitly excluded
        // by the AVETMISS USI character set (^[2-9A-HJ-NP-Z]{10}$).  avetmiss_codes.php
        // is the authoritative validator; it normalises with strtoupper/trim first, then
        // checks without /i.  Mirror that approach here so both paths agree.
        // Note: the API call at line 88 already sends strtoupper($usi), so normalising
        // here too keeps validate_input consistent with what is actually transmitted.
        $usi = strtoupper(trim((string) $usi));
        if (strlen($usi) !== 10) {
            return ['valid' => false, 'error' => 'USI must be exactly 10 characters'];
        }
        if (!preg_match('/^[2-9A-HJ-NP-Z]{10}$/', $usi)) {
            return ['valid' => false, 'error' => 'USI contains invalid characters (no 0, 1, I, or O allowed)'];
        }
        if (empty($firstname)) {
            return ['valid' => false, 'error' => 'First name is required'];
        }
        if (empty($lastname)) {
            return ['valid' => false, 'error' => 'Last name is required'];
        }
        if (empty($dateofbirth)) {
            return ['valid' => false, 'error' => 'Date of birth is required'];
        }
        return ['valid' => true];
    }

    /**
     * PER-TENANT-USI (v4.2.30, 30 Apr 2026): upload this RTO's myGovID Machine
     * Credential (.pfx) to the platform's /api/rto/usi-cert/upload endpoint.
     * The platform stores it in client_rto_configs scoped to this site, and
     * subsequent /api/usi/verify calls use this credential rather than a
     * shared platform credential.
     *
     * @param string $cert_base64        Base64-encoded .pfx / keystore file bytes
     * @param string $cert_password      Password for the .pfx (may be empty)
     * @param string $org_id             RTO organisation code (TOID)
     * @param bool   $test_mode          true = EVTE test environment, false = production
     * @param string $notification_email Optional — where to email expiry warnings (60/30/7 day)
     * @return array  ['ok' => bool, 'message' => string, 'certBytes' => int, 'orgId' => string, 'testMode' => bool, 'certExpiry' => string, 'certSubject' => string, 'notificationEmail' => string, 'error' => string]
     */
    public function upload_cert($cert_base64, $cert_password, $org_id, $test_mode = true, $notification_email = '') {
        if (!$this->is_platform_configured()) {
            return [
                'ok'    => false,
                'error' => 'Platform connection not configured (api_url / siteid / apikey missing).',
            ];
        }
        if (empty($cert_base64) || strlen($cert_base64) < 200) {
            return ['ok' => false, 'error' => 'cert_base64 missing or implausibly short.'];
        }
        if (empty($org_id)) {
            return ['ok' => false, 'error' => 'org_id (TOID) is required.'];
        }

        $url = rtrim($this->apiurl, '/') . '/api/rto/usi-cert/upload';
        $payload = json_encode([
            'siteid'             => $this->siteid,
            'apikey'             => $this->apikey,
            'cert_base64'        => $cert_base64,
            'cert_password'      => (string) $cert_password,
            'org_id'             => $org_id,
            'test_mode'          => $test_mode ? true : false,
            'notification_email' => (string) $notification_email,
        ]);

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $raw  = $curl->post($url, $payload);
        $code = (int) $curl->info['http_code'];
        $cerr = $curl->error;

        if ($cerr !== '') {
            return ['ok' => false, 'error' => 'cURL error: ' . $cerr];
        }
        $data = json_decode((string) $raw, true);
        if ($code !== 200) {
            $err = is_array($data) && !empty($data['error']) ? (string) $data['error'] : ('HTTP ' . $code);
            return ['ok' => false, 'error' => $err, 'http_code' => $code];
        }
        if (!is_array($data) || empty($data['ok'])) {
            return [
                'ok'    => false,
                'error' => is_array($data) && !empty($data['error']) ? (string) $data['error'] : 'Platform returned non-OK response.',
            ];
        }
        return [
            'ok'        => true,
            'message'   => $data['message']   ?? 'Credential uploaded.',
            'certBytes' => (int) ($data['certBytes'] ?? 0),
            'orgId'     => (string) ($data['orgId'] ?? $org_id),
            'testMode'  => (bool) ($data['testMode'] ?? $test_mode),
        ];
    }
}
