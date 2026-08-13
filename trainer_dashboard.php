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
 * RTO Compliance plugin — trainer_dashboard.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// RTO Compliance — Trainer Dashboard (v4.2.30, 30 Apr 2026)
//
// ROLE-SPLIT companion page: scoped read-only view for users holding
// local/rtocompliance:viewtrainer (editingteacher / teacher archetype) so
// trainers / assessors can see the data RELEVANT TO THEM without exposure to
// management-only screens like Governance & ADC, Fee Protection, Insurance
// Register, NAT submission, AI Survey Analysis credits, or other trainers'
// students.
//
// Sections:
//   A. My current classes      — courses where the user is editingteacher
//   B. My students             — enrolled in those courses, with USI status
//                                 + competency-progress %
//   C. My survey responses     — Quality Indicator (Learner / Employer)
//                                 responses for products this trainer delivers
//   D. My currency profile     — own VET trainer credentials (TAE, vocational
//                                 currency, PD log) — links to the existing
//                                 record_management.php scoped to $USER->id

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

require_login();
$systemcontext = context_system::instance();

// Gate: any of the three trainer-eligible caps (managers can view too)
if (!has_capability('local/rtocompliance:viewtrainer', $systemcontext)
    && !has_capability('local/rtocompliance:viewall',   $systemcontext)
    && !has_capability('local/rtocompliance:manage',    $systemcontext)) {
    throw new \required_capability_exception(
        $systemcontext,
        'local/rtocompliance:viewtrainer',
        'nopermissions',
        ''
    );
}

// SORT: clickable Name column header for My Students table.
$sort    = optional_param('sort', 'name', PARAM_ALPHA);
$sortdir = optional_param('sortdir', 'asc', PARAM_ALPHA);
if (!in_array($sort, ['name'], true)) { $sort = 'name'; }
if (!in_array($sortdir, ['asc', 'desc'], true)) { $sortdir = 'asc'; }
$PAGE->set_url(new moodle_url('/local/rtocompliance/trainer_dashboard.php', ['sort' => $sort, 'sortdir' => $sortdir]));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('trainerdashboard', 'local_rtocompliance'));
$PAGE->set_heading(get_string('trainerdashboard', 'local_rtocompliance'));

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('trainerdashboard', 'local_rtocompliance'), null, null, 'trainers');
echo local_rtocompliance_page_banner(get_string('trainerdashboard', 'local_rtocompliance'));

// ─── Header bar ───────────────────────────────────────────────────────────
echo '<div style="background:linear-gradient(135deg,#0ea5e9 0%,#0284c7 100%);color:white;padding:20px 24px;border-radius:8px;margin-bottom:24px;">';
echo '<div style="display:flex;align-items:center;gap:14px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>';
echo '<div>';
echo '<h2 style="margin:0;color:white;font-size:22px;">' . get_string('trainerdashboard', 'local_rtocompliance') . '</h2>';
echo '<div style="opacity:.85;font-size:13px;margin-top:2px;">' . get_string('trainerdashboard_intro', 'local_rtocompliance') . '</div>';
echo '</div></div></div>';

// ─── A. My current classes ───────────────────────────────────────────────
$mycourses = [];
try {
    // Find courses where the current user is enrolled with the editingteacher
    // OR teacher role (i.e. a "trainer" in Moodle parlance).
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname, c.visible
         FROM {course} c
         JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
         JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = :uid
         JOIN {role} r ON r.id = ra.roleid
         WHERE r.shortname IN ('editingteacher','teacher')
           AND c.id <> :siteid
         ORDER BY c.fullname",
        ['uid' => $USER->id, 'siteid' => SITEID]
    );
    $mycourses = $rows;
} catch (\Throwable $e) {
    $mycourses = [];
}

echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 14px 0;font-size:17px;display:flex;align-items:center;gap:8px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>';
echo get_string('trainer_myclasses', 'local_rtocompliance');
echo '</h3>';

if (empty($mycourses)) {
    echo '<div style="padding:14px;background:#f3f4f6;border-radius:6px;color:#6b7280;font-size:14px;">' . get_string('trainer_noclasses', 'local_rtocompliance') . '</div>';
} else {
    echo '<table class="generaltable" style="width:100%;margin:0;">';
    echo '<thead><tr>';
    echo '<th title="Course short name or code">' . get_string('shortnamecourse') . '</th>';
    echo '<th title="Full course name">' . get_string('fullnamecourse') . '</th>';
    echo '<th style="text-align:right;" title="Number of students enrolled in this class">' . get_string('students') . '</th>';
    echo '<th style="text-align:right;" title="Available actions for this class">' . get_string('actions') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($mycourses as $course) {
        $enrolled = 0;
        try {
            $coursectx = context_course::instance($course->id);
            $enrolled = count_enrolled_users($coursectx, '', 0, true);
        } catch (\Throwable $e) { $enrolled = 0; }
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        echo '<tr>';
        echo '<td><strong>' . s($course->shortname) . '</strong></td>';
        echo '<td>' . s($course->fullname) . '</td>';
        echo '<td style="text-align:right;">' . (int) $enrolled . '</td>';
        echo '<td style="text-align:right;"><a href="' . $courseurl->out() . '" class="btn btn-sm btn-outline-primary" title="Open this course in Moodle">' . get_string('view') . '</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div>';

// ─── B. My students (USI + competency progress) ──────────────────────────
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 14px 0;font-size:17px;display:flex;align-items:center;gap:8px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
echo get_string('trainer_mystudents', 'local_rtocompliance');
echo '</h3>';

if (empty($mycourses)) {
    echo '<div style="padding:14px;background:#f3f4f6;border-radius:6px;color:#6b7280;font-size:14px;">' . get_string('trainer_nostudents', 'local_rtocompliance') . '</div>';
} else {
    $courseids = array_keys($mycourses);
    list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
    $params['studentrole'] = 'student';
    $sortSqlDir = ($sortdir === 'desc') ? 'DESC' : 'ASC';
    try {
        $students = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.idnumber
             FROM {user} u
             JOIN {role_assignments} ra ON ra.userid = u.id
             JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = " . CONTEXT_COURSE . "
             JOIN {role} r ON r.id = ra.roleid
             WHERE ctx.instanceid $insql
               AND r.shortname = :studentrole
               AND u.deleted = 0 AND u.suspended = 0
             ORDER BY u.lastname $sortSqlDir, u.firstname $sortSqlDir
             LIMIT 200",
            $params
        );
    } catch (\Throwable $e) { $students = []; }

    if (empty($students)) {
        echo '<div style="padding:14px;background:#f3f4f6;border-radius:6px;color:#6b7280;font-size:14px;">' . get_string('trainer_nostudents', 'local_rtocompliance') . '</div>';
    } else {
        echo '<div style="overflow-x:auto;">';
        echo '<table class="generaltable" style="width:100%;margin:0;">';
        // Sortable Name header for My Students table.
        $dashNameNextDir = ($sort === 'name' && $sortdir === 'asc') ? 'desc' : 'asc';
        $dashNameSortUrl = (new moodle_url('/local/rtocompliance/trainer_dashboard.php', [
            'sort' => 'name', 'sortdir' => $dashNameNextDir,
        ]))->out(false);
        $dashNameArrow = ($sort === 'name')
            ? ($sortdir === 'asc'
                ? ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 2L9 8H1z" fill="currentColor"/></svg>'
                : ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 8L1 2h8z" fill="currentColor"/></svg>')
            : '';
        $dashNameLink = '<a href="' . htmlspecialchars($dashNameSortUrl, ENT_QUOTES) . '" style="white-space:nowrap;text-decoration:none;color:inherit;font-weight:bold">'
            . get_string('fullname') . $dashNameArrow . '</a>';
        echo '<thead><tr>';
        echo '<th title="Student name — click to sort">' . $dashNameLink . '</th>';
        echo '<th title="Student email address">' . get_string('email') . '</th>';
        echo '<th title="Unique Student Identifier (USI)">USI</th>';
        echo '<th title="Student ID number">' . get_string('idnumber') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($students as $stu) {
            // Look up USI from local_rtocompliance_students if present
            $usi = '';
            try {
                $rec = $DB->get_record('local_rtocompliance_students', ['userid' => $stu->id], 'usi', IGNORE_MISSING);
                if ($rec && !empty($rec->usi)) $usi = $rec->usi;
            } catch (\Throwable $e) { /* table may not exist on bare install */ }
            $userurl = new moodle_url('/user/profile.php', ['id' => $stu->id]);
            echo '<tr>';
            echo '<td><a href="' . $userurl->out() . '">' . s(fullname($stu)) . '</a></td>';
            echo '<td style="font-size:12px;color:#6b7280;">' . s($stu->email) . '</td>';
            echo '<td>' . ($usi ? '<code>' . s($usi) . '</code>' : '<span style="color:#9ca3af;">—</span>') . '</td>';
            echo '<td style="font-size:12px;">' . s($stu->idnumber ?: '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div style="margin-top:8px;font-size:12px;color:#6b7280;">' . get_string('trainer_students_note', 'local_rtocompliance') . '</div>';
    }
}
echo '</div>';

// ─── C. My currency profile ──────────────────────────────────────────────
// v5.9.380: record_management.php was renamed to trainer_currency.php (keyed by
// trainerid). Look up this user's trainer record; hide the button if none.
$mytrainer = $DB->get_record('local_rtocompliance_trainers', ['userid' => $USER->id], 'id', IGNORE_MULTIPLE);
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 14px 0;font-size:17px;display:flex;align-items:center;gap:8px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
echo get_string('trainer_currency', 'local_rtocompliance');
echo '</h3>';
echo '<p style="color:#6b7280;font-size:14px;margin:0 0 12px 0;">' . get_string('trainer_currency_intro', 'local_rtocompliance') . '</p>';
if ($mytrainer) {
    $recordurl = new moodle_url('/local/rtocompliance/trainer_currency.php', ['trainerid' => $mytrainer->id]);
    echo '<a href="' . $recordurl->out() . '" class="btn btn-primary" title="Open your industry currency and credential record">' . get_string('trainer_currency_open', 'local_rtocompliance') . '</a>';
} else {
    echo '<p style="color:#9ca3af;font-size:13px;margin:0;">No trainer record is linked to your account yet.</p>';
}
echo '</div>';

// ─── D. Validation events I'm assigned to (read-only) ───────────────────
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 14px 0;font-size:17px;display:flex;align-items:center;gap:8px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
echo get_string('trainer_validation', 'local_rtocompliance');
echo '</h3>';
echo '<p style="color:#6b7280;font-size:14px;margin:0 0 12px 0;">' . get_string('trainer_validation_intro', 'local_rtocompliance') . '</p>';
$valurl = new moodle_url('/local/rtocompliance/validation.php');
echo '<a href="' . $valurl->out() . '" class="btn btn-outline-primary" title="Open the validation events you are assigned to">' . get_string('trainer_validation_open', 'local_rtocompliance') . '</a>';
echo '</div>';

echo $OUTPUT->footer();
