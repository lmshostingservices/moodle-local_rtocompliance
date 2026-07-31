<?php
// LLN ADAPTER (v4.2.50) — generic HTTP webhook adapter.
//
// POSTs student + qualification context as JSON to a configured URL
// and parses the returned ACSF level.  The payload is HMAC-signed with
// the configured secret so the receiving system can verify the call.
//
// Request:
//   POST <lln_webhook_url>
//   Content-Type: application/json
//   X-RTO-Signature: sha256=<hmac_sha256(body, secret)>
//   X-RTO-Provider:  local_rtocompliance
//   Body: {
//     "userid":     1234,
//     "email":      "student@example.com",
//     "fullname":   "Jane Smith",
//     "qualcode":   "BSB30120",
//     "qualname":   "Certificate III in Business",
//     "rto":        "Acme RTO"
//   }
//
// Response (HTTP 200):
//   {
//     "level":        "3",                       // required, "1".."5"
//     "assessed_at":  1735689600,                // optional unix ts
//     "assessor":     "Acme LLN Online v2.4"     // optional
//   }
//
// Any non-200, JSON error, missing/invalid level, or transport timeout
// returns null — the suitability flow then proceeds without an
// auto-pulled level (trainer can still override manually).

namespace local_rtocompliance\lln;

defined('MOODLE_INTERNAL') || die();

class webhook_adapter implements adapter_interface {

    /** @var int curl timeout in seconds. */
    const TIMEOUT_SECONDS = 5;

    public function get_code(): string {
        return 'webhook';
    }

    public function get_label(): string {
        $custom = get_config('local_rtocompliance', 'lln_provider_label');
        if (!empty($custom)) {
            return (string) $custom;
        }
        return get_string('lln_adapter_webhook', 'local_rtocompliance');
    }

    public function get_level(int $userid, int $tasid, ?\stdClass $suit = null): ?array {
        global $DB;

        $url    = trim((string) get_config('local_rtocompliance', 'lln_webhook_url'));
        $secret = (string) get_config('local_rtocompliance', 'lln_webhook_secret');

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        $user = \core_user::get_user($userid);
        $tas  = $DB->get_record('local_rtocompliance_tas', ['id' => $tasid]);
        if (!$user || !$tas) {
            return null;
        }

        $payload = [
            'userid'   => (int) $userid,
            'email'    => (string) $user->email,
            'fullname' => fullname($user),
            'qualcode' => (string) ($tas->qualificationcode ?? ''),
            'qualname' => (string) ($tas->qualificationname ?? ''),
            'rto'      => (string) (get_config('local_rtocompliance', 'rtoname') ?: ''),
        ];

        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $headers = [
            'Content-Type: application/json',
            'X-RTO-Signature: ' . $signature,
            'X-RTO-Provider: local_rtocompliance',
        ];

        try {
            \core\session\manager::write_close();
            $curl = new \curl();
            $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT_SECONDS, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT_SECONDS, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2, 'CURLOPT_FOLLOWLOCATION' => false]);
            $curl->setHeader($headers);
            $resp = $curl->post($url, $body);
            $code = (int) $curl->info['http_code'];
            $err  = $curl->error;
        } catch (\Throwable $e) {
            debugging('LLN webhook transport error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if ($code !== 200 || $resp === false || $resp === '' || $err !== '') {
            debugging('LLN webhook bad response: HTTP ' . $code . ' err=' . $err, DEBUG_DEVELOPER);
            return null;
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            return null;
        }
        $level = isset($decoded['level']) ? trim((string) $decoded['level']) : '';
        if (!in_array($level, ['1', '2', '3', '4', '5'], true)) {
            return null;
        }

        return [
            'level'        => $level,
            'source'       => 'webhook',
            'assessed_at'  => isset($decoded['assessed_at']) ? (int) $decoded['assessed_at'] : time(),
            'assessor'     => isset($decoded['assessor']) ? (string) $decoded['assessor'] : $this->get_label(),
        ];
    }
}
