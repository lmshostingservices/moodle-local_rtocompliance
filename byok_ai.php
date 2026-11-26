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
 * The page scripts' AI requests: answered on this site's own AI (Bring your own AI), or sent on to LMS Labs.
 *
 * Takes {sesskey, route, body}, where route is the LMS Labs route the script would call and body its request,
 * and answers with the status and JSON the LMS Labs service gives. On LMS Labs credits the site's API key is
 * added here on the server, so it is never printed into the page.
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
require_login(null, false);

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
$credits = $route === \local_rtocompliance\byok::CREDITS_ROUTE;
if ((!$credits && !in_array($route, \local_rtocompliance\byok::BROWSER_ROUTES, true)) || !property_exists($input, 'body')) {
    $fail(400, 'Unknown request.');
}
// The credit balance shows in the sidebar on every plugin page; the AI features are for managers.
if ($credits ? isguestuser() : !has_capability('local/rtocompliance:manage', $context)) {
    $fail(403, get_string('nopermissions', 'error', get_string('rtocompliance:manage', 'local_rtocompliance')));
}

\core\session\manager::write_close();
if (!$credits && \local_rtocompliance\byok::uses()) {
    $body = json_encode($input->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$status, $json] = \local_rtocompliance\byok::answer($route, (string)$body);
} else {
    $body = $input->body instanceof stdClass ? $input->body : new stdClass();
    if ($credits) {
        // The balance needs nothing from the page.
        $body = new stdClass();
    }
    [$status, $json] = \local_rtocompliance\byok::forward($route, $body);
}
http_response_code($status);
echo $json;
