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
 * RTO Compliance plugin — assistant.php.
 *
 * AI ASSISTANT (v5.9.456): JSON endpoint for the in-product help assistant. The
 * floating widget (js/rtoc_assistant.js) POSTs the conversation here; this script
 * grounds the question on the plugin's live knowledge base and routes it through the
 * lms-labs.com platform (which holds the Claude key and bills one credit per question).
 * Requires login + a plugin capability + sesskey. It never exposes any secret.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
// Any staff member who can see the plugin may use the assistant.
if (!has_capability('local/rtocompliance:viewall', $context)
        && !has_capability('local/rtocompliance:manage', $context)
        && !has_capability('local/rtocompliance:viewreports', $context)
        && !is_siteadmin()) {
    require_capability('local/rtocompliance:viewall', $context);
}

header('Content-Type: application/json; charset=utf-8');

// Read the JSON body: { sesskey, messages: [{role, content}], page: 'students.php',
//                      pageparams: {qualid: 12} }.
$raw = file_get_contents('php://input');
$in  = json_decode((string) $raw, true);
if (!is_array($in)) {
    $in = [];
}

// Sesskey travels in the JSON body (the request is application/json, so it is not a
// normal POST param). Validate it against this user's session.
$sesskey = (isset($in['sesskey']) && is_string($in['sesskey']))
    ? $in['sesskey']
    : optional_param('sesskey', '', PARAM_ALPHANUMEXT);
if (!confirm_sesskey($sesskey)) {
    echo json_encode(['ok' => false, 'error' => 'Your session expired — please reload the page.']);
    exit;
}
$messages = isset($in['messages']) && is_array($in['messages']) ? $in['messages'] : [];
// is_string(): a JSON array here would raise 'Array to string conversion' and corrupt the
// JSON response on developer sites.
$page     = isset($in['page']) && is_string($in['page']) ? clean_param($in['page'], PARAM_FILE) : '';

// v6.3.15: the widget sends the ids of the record on screen, so the assistant can answer
// "why can't I issue for this student?" about that student rather than in the abstract.
//
// v6.3.14 sent the whole query string and parsed it server-side, which forced an untyped
// request parameter. It never needed to be untyped: the only values ever used are a handful of
// positive integer ids, so the widget now sends just those and each one is read with
// PARAM_INT. Nothing free-form from the address bar reaches the server at all.
$pageparams = (isset($in['pageparams']) && is_array($in['pageparams']))
    ? \local_rtocompliance\assistant\knowledge::filter_page_params($in['pageparams'])
    : [];

if (empty($messages)) {
    echo json_encode(['ok' => false, 'error' => 'Please type a question.']);
    exit;
}

// Lightweight per-user rate limit: max 20 questions per 60s window.
$cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
$rlkey = 'assistant_rl_' . (int) $USER->id;
$bucket = $cache->get($rlkey);
$now = time();
if (!is_array($bucket) || ($bucket['reset'] ?? 0) < $now) {
    $bucket = ['count' => 0, 'reset' => $now + 60];
}
if ($bucket['count'] >= 20) {
    echo json_encode(['ok' => false, 'error' => 'You are asking very quickly — give it a few seconds and try again.']);
    exit;
}
$bucket['count']++;
$cache->set($rlkey, $bucket);

$result = local_rtocompliance_assistant_ask($messages, $page, $pageparams);

echo json_encode([
    'ok'      => (bool) $result['ok'],
    'reply'   => (string) ($result['reply'] ?? ''),
    'credits' => $result['credits'] ?? null,
    'mode'    => (string) ($result['mode'] ?? ''),
    'error'   => (string) ($result['error'] ?? ''),
]);
exit;
