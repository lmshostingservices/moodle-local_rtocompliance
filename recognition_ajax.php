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
 * Batched National Register classification endpoint.
 *
 * WHY THIS EXISTS (v6.4.2):
 * program_recognition.php's button ran every unclassified code in ONE page request.
 * Each lookup can take up to its curl timeout, so a site with 65 unclassified codes
 * could sit on a blank page for minutes and then be killed by max_execution_time or a
 * proxy timeout, with no way for the operator to tell the difference between "working"
 * and "dead". This endpoint does a SMALL BATCH per request so the page can show real
 * progress and survive a slow or unreachable register.
 *
 * Each call is independently safe: it only touches rows that are still unclassified,
 * never overwrites a decision a person has made, and records what the register said.
 * If the browser is closed halfway the work already done is saved.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_rtocompliance\local\recognition;
use local_rtocompliance\local\register_lookup;

header('Content-Type: application/json; charset=utf-8');

/**
 * Return the response envelope and stop.
 *
 * @param array $payload Response payload.
 * @param int $status HTTP status.
 * @return void
 */
function local_rtocompliance_recognition_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// This endpoint writes, so it is POST-only. The request METHOD is not request data and
// has no optional_param() equivalent; the same check appears in around forty places
// across this plugin. Every VALUE read below comes through optional_param().
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if (!isloggedin() || isguestuser()) {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'not_logged_in'], 403);
}

require_login(null, false);
$context = context_system::instance();
$PAGE->set_context($context);

if (!has_capability('local/rtocompliance:manage', $context)) {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'no_capability'], 403);
}

// optional_param, not required_param: with no sesskey at all required_param throws
// Moodle's own exception, which this endpoint's client receives as unparseable HTML
// rather than as its JSON envelope. Presence is checked first, then validity.
//
// A session key is alphanumeric, so PARAM_ALPHANUMEXT is the correct type, and every
// value this endpoint reads now comes through optional_param() rather than raw
// request data.

$postedsesskey = optional_param('sesskey', '', PARAM_ALPHANUMEXT);
if ($postedsesskey === '' || !confirm_sesskey($postedsesskey)) {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'invalid_sesskey'], 403);
}

// THE UPGRADE WINDOW: the endpoint is reachable before the upgrade creates the
// table. Answer in the JSON envelope rather than letting a DML exception out.
if (!recognition::table_ready()) {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'not_upgraded'], 409);
}

$step = optional_param('step', 'batch', PARAM_ALPHA);

// A batch small enough that one request cannot outlive a normal PHP time limit even
// if every lookup in it times out, and large enough that 65 codes is a handful of
// round trips rather than 65.
$batchsize = optional_param('batch', 5, PARAM_INT);
$batchsize = max(1, min(10, $batchsize));

// Long lookups do not need the session, and holding its lock would serialise the
// user's own page loads behind this run.
\core\session\manager::write_close();

/**
 * The three state counts, for the cards on the page.
 *
 * @return array
 */
function local_rtocompliance_recognition_counts(): array {
    $c = recognition::count_by_state();
    return [
        'recognised'    => (int)$c[recognition::STATE_RECOGNISED],
        'notrecognised' => (int)$c[recognition::STATE_NOT_RECOGNISED],
        'unknown'       => (int)$c[recognition::STATE_UNKNOWN],
    ];
}

if ($step === 'start') {
    // Make sure nothing is invisible before counting, so the total the progress bar
    // is measured against is the real one.
    $discovered = register_lookup::discover_codes();

    // The run's clock comes from the SERVER, not the browser, because it is compared
    // against registerchecked values the server wrote.
    $runstart = time();

    local_rtocompliance_recognition_reply([
        'ok'         => true,
        'discovered' => $discovered,
        'runstart'   => $runstart,
        'total'      => register_lookup::count_pending($runstart),
        'counts'     => local_rtocompliance_recognition_counts(),
    ]);
}

// ----------------------------------------------------------------- one batch ----
// optional_param, not required_param: required_param throws, and a thrown exception
// does not come back in this endpoint's JSON envelope, so the client would see
// unparseable output for what is simply a bad call.
$runstart = optional_param('runstart', 0, PARAM_INT);
if ($runstart <= 0 || $runstart > time() + 300) {
    local_rtocompliance_recognition_reply(
        ['ok' => false, 'error' => 'bad_runstart'], 400);
}

$results = [];
$counts = register_lookup::classify_batch($runstart, $batchsize,
    function ($res) use (&$results) {
        $results[] = [
            'code'   => $res['code'],
            'result' => $res['result'],
            'state'  => $res['state'],
            'title'  => $res['title'],
            'detail' => $res['detail'],
        ];
    });

// remaining is recounted from the table rather than decremented client-side, so a
// code classified in another browser tab cannot make the bar lie.
local_rtocompliance_recognition_reply([
    'ok'        => true,
    'processed' => (int)$counts['total'],
    'tally'     => ['found' => (int)$counts['found'],
                    'notfound' => (int)$counts['notfound'],
                    'error' => (int)$counts['error']],
    'results'   => $results,
    'remaining' => register_lookup::count_pending($runstart),
    'counts'    => local_rtocompliance_recognition_counts(),
]);
