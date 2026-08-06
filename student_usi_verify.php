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
 * USI Verification — inline AJAX endpoint.
 *
 * Called by the "Verify via usi.gov.au" button on students.php.
 * Accepts:
 *   profileid  (int)  — local_rtocompliance_students.id
 *   sesskey    (string) — Moodle sesskey for CSRF protection
 *   ajax       (int,  1 = return JSON, else redirect)
 *
 * Returns JSON: { success, html, message }
 * or redirects back to students.php on non-AJAX use.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\usi\usi_verification_service;

$profileid = required_param('profileid', PARAM_INT);
$ajax      = optional_param('ajax',      0, PARAM_INT);

// CSRF protection — always enforce sesskey
require_sesskey();

// Require RTO compliance manage capability
require_capability('local/rtocompliance:manage', context_system::instance());

// ── Fetch the student record ─────────────────────────────────────────────────
$student = $DB->get_record('local_rtocompliance_students', ['id' => $profileid]);

if (!$student) {
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'html' => '', 'message' => 'Student profile not found.']);
        exit;
    }
    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        'Student profile not found.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if (empty($student->usi)) {
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'html' => '', 'message' => 'No USI recorded for this student.']);
        exit;
    }
    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        'No USI recorded for this student.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// ── Run verification via usi_verification_service ────────────────────────────
try {
    $service = new usi_verification_service();
    $result  = $service->verify_student_usi($student->id);
} catch (\Throwable $ex) {
    $result = ['success' => false, 'message' => $ex->getMessage()];
}

// Reload the updated student record so we can build the fresh HTML
$student = $DB->get_record('local_rtocompliance_students', ['id' => $profileid]);

// ── Build the updated USI cell HTML (mirrors students.php display logic) ─────
if (!function_exists('rtoc_build_usi_cell')) {
function rtoc_build_usi_cell(object $student): string {
    if (empty($student->usi)) {
        return '<span class="rtoc-usi-missing">Missing</span>';
    }

    $vstat = (int)($student->usiverified ?? 0);
    $vdate = '';
    if (!empty($student->usiverifieddate)) {
        $vdate = userdate($student->usiverifieddate, get_string('strftimedatefullshort', 'langconfig'));
    }

    $html = '<code class="rtoc-usi-code">' . s($student->usi) . '</code>';

    if ($vstat === 1) {
        $html .= '<span class="rtoc-usi-badge rtoc-usi-verified">'
            . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M5.5 8l1.8 1.8 3.2-3.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . ' Verified via <strong>usi.gov.au</strong>'
            . ($vdate ? '<span class="rtoc-usi-date"> &mdash; ' . $vdate . '</span>' : '')
            . '</span>';
    } else if ($vstat === 2) {
        $html .= '<span class="rtoc-usi-badge rtoc-usi-failed">'
            . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            . ' Verification failed'
            . '</span>'
            . ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->id . '">Retry &#x21BB;</button>';
    } else if ($vstat === 3) {
        $html .= '<span class="rtoc-usi-badge rtoc-usi-pending">'
            . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            . ' Verification pending'
            . '</span>';
    } else if ($vstat === 4) {
        $html .= '<span class="rtoc-usi-badge rtoc-usi-review">'
            . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2l1.3 2.6 2.9.4-2.1 2.1.5 2.9L8 8.6 5.4 10l.5-2.9L3.8 5l2.9-.4L8 2z" stroke="currentColor" stroke-width="1.2"/></svg>'
            . ' Needs manual review'
            . '</span>'
            . ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->id . '">Verify</button>';
    } else {
        $html .= '<span class="rtoc-usi-badge rtoc-usi-unverified">'
            . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M8 6v3M8 10.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            . ' Not yet verified'
            . '</span>'
            . ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->id . '">Verify via usi.gov.au &#x2192;</button>';
    }

    return $html;
}
} // end function_exists('rtoc_build_usi_cell')

$cellhtml = rtoc_build_usi_cell($student);
$success  = !empty($result['success']);
$message  = $result['message'] ?? ($success ? 'USI verified successfully.' : 'Verification could not be completed.');

if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'html'    => $cellhtml,
        'message' => $message,
    ]);
    exit;
}

// Non-AJAX fallback — redirect back with notification
redirect(
    new moodle_url('/local/rtocompliance/students.php'),
    $message,
    null,
    $success ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
);
