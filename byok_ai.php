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
 * Bring your own AI: the page scripts' AI requests, answered on this site's own AI.
 *
 * Takes {sesskey, route, body}, where route is the LMS Labs route the script would call and body its request,
 * and answers with the status and JSON the LMS Labs service would give.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/rtocompliance/byok_ai.php'));
require_login();
require_capability('local/rtocompliance:manage', $context);

$fail = function (int $status, string $error): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
};

$limit = \local_rtocompliance\byok::MAX_BODY + 65536;
$raw = (string)file_get_contents('php://input', false, null, 0, $limit + 1);
if (strlen($raw) > $limit) {
    $fail(413, 'Request too large.');
}
$input = json_decode((string)$raw);
if (!$input instanceof stdClass || !isset($input->sesskey) || !is_string($input->sesskey) || !confirm_sesskey($input->sesskey)) {
    $fail(403, get_string('invalidsesskey', 'error'));
}
$route = isset($input->route) && is_string($input->route) ? $input->route : '';
if (!in_array($route, \local_rtocompliance\byok::BROWSER_ROUTES, true) || !property_exists($input, 'body')) {
    $fail(400, 'Unknown request.');
}
if (!\local_rtocompliance\byok::uses()) {
    $fail(409, get_string('byok_off', 'local_rtocompliance'));
}

\core\session\manager::write_close();
$body = json_encode($input->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
[$status, $json] = \local_rtocompliance\byok::answer($route, (string)$body);
http_response_code($status);
echo $json;
