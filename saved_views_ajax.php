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
 * Secure saved-table-view endpoint for RTO Compliance.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

/**
 * Return the stable endpoint envelope and stop.
 *
 * @param array $payload Response payload.
 * @param int $status HTTP status.
 * @return void
 */
function local_rtocompliance_saved_views_reply(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// POST-only. The request METHOD is not request DATA and has no optional_param()
// equivalent; every VALUE this endpoint reads now comes through optional_param().
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST for saved views.'],
        400
    );
}

if (!isloggedin() || isguestuser()) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'forbidden', 'message' => 'A signed-in user is required.'],
        403
    );
}

require_login();

if (isguestuser()) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'forbidden', 'message' => 'A signed-in user is required.'],
        403
    );
}

$PAGE->set_context(\context_system::instance());

// confirm_sesskey() validates the session-bound key without throwing a
// Moodle HTML exception, keeping all endpoint errors JSON and stable.
//
// v6.3.32: with NO sesskey at all, confirm_sesskey() falls through to
// required_param('sesskey') and Moodle throws its own missingparam exception,
// so the one case the client is most likely to hit - a stale page whose sesskey
// has gone - came back as a Moodle stack trace instead of this endpoint's
// envelope. Check the parameter is present first, and answer in our own shape.
// ON THE ABSENCE OF A TOP-LEVEL require_capability(): deliberate, not an oversight.
// Saved views exist on several pages that have DIFFERENT access rules, so one blanket
// capability check here would state an access rule some of those pages do not have -
// either locking out users who may legitimately use the feature, or implying an
// authorisation this endpoint has not actually made. The check is per page instead:
// saved_views::can_access_page($page) below resolves the requested page to its own
// capability (and mirrors the OR-gate on trainer_dashboard.php, and defers to the page
// itself where the rule depends on a target userid). Nothing is read or written before
// that check passes, and every view is scoped to $USER->id.
$postedsesskey = optional_param('sesskey', '', PARAM_ALPHANUMEXT);
if ($postedsesskey === '') {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'invalid_sesskey', 'message' => 'The security key is missing or invalid.'],
        403
    );
}
if (!confirm_sesskey($postedsesskey)) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'invalid_sesskey', 'message' => 'The security key is missing or invalid.'],
        403
    );
}

// A saved view belongs to the person who saved it, so this endpoint refuses any
// request that even ATTEMPTS to name another user, another context, or an export. It
// used to enumerate the submitted request keys; it now asks for each forbidden name
// through optional_param(), which has the same effect without reading raw request data.
// A sentinel default distinguishes "absent" from "submitted empty".
foreach (['targetuserid', 'userid', 'userids', 'export', 'context', 'contextid'] as $forbidden) {
    if (optional_param($forbidden, null, PARAM_TEXT) !== null) {
        local_rtocompliance_saved_views_reply(
            ['ok' => false, 'error' => 'invalid_request', 'message' => 'This request contains an unsupported field.'],
            400
        );
    }
}

// PARAM_TEXT on all three, deliberately. Each value is then validated by
// saved_views against its own strict allow-list - page against
// ^[A-Za-z0-9_-]+(\.php)?$ plus a registered-page check, table against
// ^[a-z0-9:_-]{1,80}$, action against a fixed list - so the job here is only to avoid
// MANGLING a legitimate value before that validation runs. A narrower type would:
// PARAM_ALPHANUMEXT would strip the dot from 'students.php' and the colon from a table
// key, turning a valid request into an invalid one.
$action = optional_param('action', null, PARAM_TEXT);
$page = optional_param('page', null, PARAM_TEXT);
$table = optional_param('table', null, PARAM_TEXT);
if (!is_string($action) || !is_string($page) || !is_string($table)) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'invalid_request', 'message' => 'The saved-view request is invalid.'],
        400
    );
}

global $USER;
$registryclass = \local_rtocompliance\local\saved_views::class;
try {
    $action = $registryclass::validate_action($action);
} catch (\local_rtocompliance\local\saved_views_exception $exception) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'invalid_request', 'message' => 'The saved-view request is invalid.'],
        400
    );
}
if (!$registryclass::is_supported_page($page)) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'unsupported_page', 'message' => 'Saved views are not available on this page.'],
        400
    );
}
if (!$registryclass::can_access_page($page)) {
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'forbidden', 'message' => 'You cannot use saved views on this page.'],
        403
    );
}

try {
    if ($action === 'list') {
        $views = $registryclass::list_views((int)$USER->id, $page, $table);
    } else if ($action === 'save') {
        $name = optional_param('name', null, PARAM_TEXT);
        // A JSON blob: size-checked below, then decoded with JSON_THROW_ON_ERROR and a
        // depth limit, and every key and value validated against an allow-list. Any
        // cleaning applied here would corrupt valid JSON.
        $rawstate = optional_param('state', null, PARAM_RAW); // pipeline-ignore: PARAM_RAW - JSON blob, json_decode()'d immediately below
        $id = optional_param('id', null, PARAM_ALPHANUM);
        if (!is_string($name) || !is_string($rawstate) || ($id !== null && !is_string($id))) {
            throw new \local_rtocompliance\local\saved_views_exception('invalid_request');
        }
        // Reject very large JSON before decoding it. This is intentionally
        // separate from the post-normalisation preference bound.
        if (strlen($rawstate) > 8192) {
            throw new \local_rtocompliance\local\saved_views_exception('state_too_large');
        }
        try {
            $state = json_decode($rawstate, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \local_rtocompliance\local\saved_views_exception('invalid_state');
        }
        if (!is_array($state)) {
            throw new \local_rtocompliance\local\saved_views_exception('invalid_state');
        }
        $registryclass::save_view((int)$USER->id, $page, $table, $name, $state, $id === '' ? null : $id);
        $views = $registryclass::list_views((int)$USER->id, $page, $table);
    } else if ($action === 'remember') {
        // No state is accepted here. This only records which of the user's own
        // existing views should be reapplied on a later visit, so a chosen view
        // survives a refresh and a logout/login cycle.
        $id = optional_param('id', null, PARAM_ALPHANUM);
        if (!is_string($id) || $id === '') {
            throw new \local_rtocompliance\local\saved_views_exception('invalid_id');
        }
        $registryclass::set_last_view((int)$USER->id, $page, $table, $id);
        $views = $registryclass::list_views((int)$USER->id, $page, $table);
    } else if ($action === 'forget') {
        $registryclass::clear_last_view((int)$USER->id, $page, $table);
        $views = $registryclass::list_views((int)$USER->id, $page, $table);
    } else {
        $id = optional_param('id', null, PARAM_ALPHANUM);
        if (!is_string($id) || $id === '') {
            throw new \local_rtocompliance\local\saved_views_exception('invalid_id');
        }
        $registryclass::delete_view((int)$USER->id, $page, $table, $id);
        $views = $registryclass::list_views((int)$USER->id, $page, $table);
    }

    local_rtocompliance_saved_views_reply([
        'ok' => true,
        'views' => $views,
        'lastview' => $registryclass::get_last_view((int)$USER->id, $page, $table),
    ]);
} catch (\local_rtocompliance\local\saved_views_exception $exception) {
    $error = $exception->get_errorcode();
    $messages = [
        'invalid_request' => 'The saved-view request is invalid.',
        'invalid_page' => 'The selected page is invalid.',
        'unsupported_page' => 'Saved views are not available on this page.',
        'invalid_table' => 'The table key is invalid.',
        'invalid_id' => 'The saved-view id is invalid.',
        'invalid_name' => 'The saved-view name must be between 1 and 60 characters.',
        'invalid_state' => 'The saved-view state is invalid.',
        'invalid_query' => 'The saved-view contains an unsupported query field.',
        'invalid_sort' => 'The saved-view sort is invalid.',
        'query_too_large' => 'The saved-view has too many query fields.',
        'state_too_large' => 'The saved-view state is too large.',
        'duplicate_name' => 'A saved view with this name already exists for this table.',
        'table_limit' => 'This table already has the maximum number of saved views.',
        'total_limit' => 'You already have the maximum number of saved views.',
        'not_found' => 'The saved view was not found.',
        'manifest_too_large' => 'The saved-view list is too large.',
        'storage' => 'The saved view could not be stored.',
    ];
    $status = $error === 'forbidden' ? 403 : 400;
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => $error, 'message' => $messages[$error] ?? 'The saved-view request is invalid.'],
        $status
    );
} catch (\Throwable $exception) {
    // Do not disclose database, JSON, filesystem, or registry internals.
    local_rtocompliance_saved_views_reply(
        ['ok' => false, 'error' => 'storage', 'message' => 'The saved view could not be stored.'],
        400
    );
}
