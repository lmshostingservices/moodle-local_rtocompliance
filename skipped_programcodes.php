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
 * Skipped Qualification Codes Report (v5.9.347)
 *
 * Shows all enrolment records that still have no programcode after running
 * "Sync Qualification Codes", grouped by course.  For each course:
 *   – displays the Moodle category path the sync walked (so admins can see why it failed)
 *   – offers "Link to QB" when a Qual Builder record matches the unit or course
 *   – offers "Mark as non-VET" to exclude courses that are genuinely not AVETMISS-reportable
 *   – offers "Show excluded courses" toggle and "Undo exclusion" to reverse a non-VET mark
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
admin_externalpage_setup('local_rtocompliance_students');

$action        = optional_param('action',        '', PARAM_ALPHANUMEXT);
$courseid      = optional_param('courseid',      0,  PARAM_INT);
$updated       = optional_param('updated',       0,  PARAM_INT); // count from the just-run sync
$showexcluded  = optional_param('showexcluded',  0,  PARAM_INT); // toggle: 1 = show non-VET section

// ── POST ACTION: link_to_qb ─────────────────────────────────────────────────
// Find the best-matching QB record for a given course and set programcode on
// all blank enrolments for that course.
if ($action === 'link_to_qb' && $courseid > 0 && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());

    $qualcode = '';

    // First preference: a qualunit row that directly maps this courseid.
    $qbRow = $DB->get_record_sql(
        "SELECT qb.qualificationcode
           FROM {local_rtocompliance_qualunits} qu
           JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
          WHERE qu.courseid = :cid
            AND qb.status != 'superseded'
          ORDER BY qb.timecreated DESC
          LIMIT 1",
        ['cid' => $courseid]
    );
    if ($qbRow) {
        $qualcode = $qbRow->qualificationcode;
    }

    // Second preference: match on unit code — find QB units whose unitcode
    // matches any unitcode on the blank enrolments for this course.
    if ($qualcode === '') {
        $unitcodes = $DB->get_fieldset_sql(
            "SELECT DISTINCT unitcode
               FROM {local_rtocompliance_enrolments}
              WHERE courseid = :cid
                AND (unitcode IS NOT NULL AND unitcode != '')
                AND (programcode IS NULL OR programcode = '')
                AND vetflag != 'N'",
            ['cid' => $courseid]
        );
        foreach ($unitcodes as $uc) {
            $qbRow2 = $DB->get_record_sql(
                "SELECT qb.qualificationcode
                   FROM {local_rtocompliance_qualunits} qu
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE qu.unitcode = :uc
                    AND qb.status != 'superseded'
                  ORDER BY qb.timecreated DESC
                  LIMIT 1",
                ['uc' => $uc]
            );
            if ($qbRow2) {
                $qualcode = $qbRow2->qualificationcode;
                break;
            }
        }
    }

    if ($qualcode !== '') {
        $DB->execute(
            "UPDATE {local_rtocompliance_enrolments}
                SET programcode = :qc, timemodified = :now
              WHERE courseid = :cid
                AND (programcode IS NULL OR programcode = '')
                AND vetflag != 'N'",
            ['qc' => $qualcode, 'now' => time(), 'cid' => $courseid]
        );
        redirect(
            new moodle_url('/local/rtocompliance/skipped_programcodes.php'),
            "Qualification code '{$qualcode}' linked to all enrolments in this course.",
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        redirect(
            new moodle_url('/local/rtocompliance/skipped_programcodes.php'),
            'No matching Qual Builder record found for this course. Please add it to Qual Builder first, then retry.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

// ── POST ACTION: exclude_course ──────────────────────────────────────────────
// Mark all blank-programcode enrolments for this course as non-VET (vetflag='N').
// These records are excluded from AVETMISS reporting and no longer trigger the
// blank-programcode warning banner.
if ($action === 'exclude_course' && $courseid > 0 && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    $DB->execute(
        "UPDATE {local_rtocompliance_enrolments}
            SET vetflag = 'N', timemodified = :now
          WHERE courseid = :cid
            AND (programcode IS NULL OR programcode = '')
            AND vetflag != 'N'",
        ['now' => time(), 'cid' => $courseid]
    );
    redirect(
        new moodle_url('/local/rtocompliance/skipped_programcodes.php'),
        'Enrolments for this course have been marked as non-VET and will be excluded from AVETMISS reporting.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── POST ACTION: undo_exclude ────────────────────────────────────────────────
// Reverse a non-VET exclusion: reset vetflag to 'Y' and blank the programcode
// so the enrolments reappear in the main skipped-codes list for further action.
if ($action === 'undo_exclude' && $courseid > 0 && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    $DB->execute(
        "UPDATE {local_rtocompliance_enrolments}
            SET vetflag = 'Y', programcode = NULL, timemodified = :now
          WHERE courseid = :cid
            AND vetflag = 'N'
            AND (programcode IS NULL OR programcode = '')",
        ['now' => time(), 'cid' => $courseid]
    );
    redirect(
        new moodle_url('/local/rtocompliance/skipped_programcodes.php', ['showexcluded' => 1]),
        'Non-VET exclusion has been undone. These enrolments will now appear in the main skipped-codes list.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── PAGE SETUP ────────────────────────────────────────────────────────────────
$PAGE->set_url(new moodle_url('/local/rtocompliance/skipped_programcodes.php'));
$PAGE->set_title('Skipped Qualification Codes');
$PAGE->set_heading('Enrolments with No Qualification Code');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
// All categories, loaded once, for path-building.
$allcats = $DB->get_records('course_categories', null, '', 'id, name, parent');

// Build a human-readable ancestor path for a given Moodle course.
$getCatPath = function (int $cid) use ($DB, $allcats): string {
    $course = $DB->get_record('course', ['id' => $cid], 'category');
    if (!$course) {
        return '(course not found)';
    }
    $catid   = (int)$course->category;
    $visited = [];
    $parts   = [];
    while ($catid > 0 && !isset($visited[$catid])) {
        $visited[$catid] = true;
        if (!isset($allcats[$catid])) {
            break;
        }
        $parts[] = trim((string)$allcats[$catid]->name);
        $catid   = (int)$allcats[$catid]->parent;
    }
    return empty($parts) ? '(top-level / no category)' : implode(' › ', array_reverse($parts));
};

// Fetch every blank-programcode enrolment with student + course info.
// vetflag='N' records are already excluded (user already marked them).
$rows = $DB->get_records_sql(
    "SELECT e.id       AS enrolid,
            e.courseid,
            e.unitcode,
            e.vetflag,
            s.userid,
            COALESCE(u.firstname, s.firstname, '') AS firstname,
            COALESCE(u.lastname,  s.lastname,  '') AS lastname
       FROM {local_rtocompliance_enrolments} e
       JOIN {local_rtocompliance_students}   s ON s.id = e.studentid
       LEFT JOIN {user} u                       ON u.id = s.userid
      WHERE (e.programcode IS NULL OR e.programcode = '')
        AND e.courseid IS NOT NULL
        AND e.courseid > 0
        AND (e.vetflag IS NULL OR e.vetflag != 'N')
      ORDER BY e.courseid ASC, s.lastname ASC, s.firstname ASC"
);

// Group by courseid.
$groups = [];
foreach ($rows as $row) {
    $cid = (int)$row->courseid;
    if (!isset($groups[$cid])) {
        $groups[$cid] = [];
    }
    $groups[$cid][] = $row;
}

// ── EXCLUDED COURSES (vetflag='N', no programcode) ────────────────────────────
// Fetch courses that were explicitly marked as non-VET so we can offer an undo.
$excludedRows = $DB->get_records_sql(
    "SELECT e.courseid,
            COUNT(e.id) AS enrolcount
       FROM {local_rtocompliance_enrolments} e
      WHERE e.vetflag = 'N'
        AND (e.programcode IS NULL OR e.programcode = '')
        AND e.courseid IS NOT NULL
        AND e.courseid > 0
      GROUP BY e.courseid
      ORDER BY e.courseid ASC"
);
// Build course names for excluded courses.
$excludedInfo = [];
foreach ($excludedRows as $er) {
    $cid2 = (int)$er->courseid;
    $c2   = $DB->get_record('course', ['id' => $cid2], 'id, fullname', IGNORE_MISSING);
    $excludedInfo[$cid2] = [
        'name'       => $c2 ? $c2->fullname : "(Moodle course ID $cid2 — no longer exists)",
        'enrolcount' => (int)$er->enrolcount,
    ];
}

// For each course: get name + category path + QB match.
$courseInfo = [];
foreach (array_keys($groups) as $cid) {
    $c = $DB->get_record('course', ['id' => $cid], 'id, fullname, shortname', IGNORE_MISSING);
    $courseName = $c ? $c->fullname : "(Moodle course ID $cid — no longer exists)";
    $catPath    = $getCatPath($cid);

    // Look for a QB unit with this courseid.
    $qbMatch = $DB->get_record_sql(
        "SELECT qb.qualificationcode, qb.qualificationname
           FROM {local_rtocompliance_qualunits} qu
           JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
          WHERE qu.courseid = :cid
            AND qb.status != 'superseded'
          ORDER BY qb.timecreated DESC
          LIMIT 1",
        ['cid' => $cid]
    );

    // If no direct course match, try by unit code.
    if (!$qbMatch) {
        $unitcodes = array_filter(array_unique(array_column($groups[$cid], 'unitcode')));
        foreach ($unitcodes as $uc) {
            $qbMatch = $DB->get_record_sql(
                "SELECT qb.qualificationcode, qb.qualificationname
                   FROM {local_rtocompliance_qualunits} qu
                   JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                  WHERE qu.unitcode = :uc
                    AND qb.status != 'superseded'
                  ORDER BY qb.timecreated DESC
                  LIMIT 1",
                ['uc' => $uc]
            );
            if ($qbMatch) {
                break;
            }
        }
    }

    // Plain-English suggestion when there is no QB match.
    $topCat = '';
    if (!$qbMatch) {
        // Identify the top-level category name for the suggestion text.
        $course2 = $DB->get_record('course', ['id' => $cid], 'category');
        if ($course2) {
            $catid2   = (int)$course2->category;
            $visited2 = [];
            $topCatId = $catid2;
            while ($catid2 > 0 && !isset($visited2[$catid2])) {
                $visited2[$catid2] = true;
                if (!isset($allcats[$catid2])) { break; }
                $topCatId = $catid2;
                $catid2   = (int)$allcats[$catid2]->parent;
            }
            $topCat = isset($allcats[$topCatId]) ? trim((string)$allcats[$topCatId]->name) : '';
        }
    }

    $courseInfo[$cid] = [
        'name'    => $courseName,
        'catpath' => $catPath,
        'qbmatch' => $qbMatch ?: null,
        'topcat'  => $topCat,
    ];
}

// ── HTML OUTPUT ───────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Skipped Program Codes'); // v5.9.404: add sidebar.

echo '<div style="max-width:1200px;margin:0 auto 2rem auto;">';

// Back link + summary header.
$backUrl = htmlspecialchars(
    (new moodle_url('/local/rtocompliance/students.php'))->out(false),
    ENT_QUOTES
);
echo '<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">'
    . '<a href="' . $backUrl . '" class="btn btn-sm btn-outline-secondary">'
    . '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
    . '<path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
    . '</svg>Back to Students</a>'
    . '<h1 style="font-size:1.4rem;font-weight:700;margin:0;">Enrolments with No Qualification Code</h1>'
    . '</div>';

if (empty($groups)) {
    // Show "All clear" banner but do NOT exit — the excluded-courses section below
    // must still render so admins can undo a non-VET exclusion even when the main
    // skipped list is empty (e.g. after all courses were marked non-VET).
    echo '<div class="alert alert-success">'
        . '<svg style="width:16px;height:16px;vertical-align:middle;margin-right:6px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
        . '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>'
        . '<strong>All clear!</strong> Every enrolment record now has a qualification code.'
        . '</div>';
    // Fall through to render the excluded-courses section if any exist.
}

if (!empty($groups)) {
    $totalSkipped = count($rows);
    $courseCount  = count($groups);
    $qbMatchCount = count(array_filter($courseInfo, fn($ci) => $ci['qbmatch'] !== null));

    // Show updated count if we just came from a sync.
    if ($updated > 0) {
        echo '<div class="alert alert-success" style="margin-bottom:1rem;">'
            . '<strong>' . $updated . ' enrolment record(s) were updated</strong> by the sync. '
            . 'The ' . $totalSkipped . ' record(s) below could not be resolved automatically.'
            . '</div>';
    }

    echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#f59e0b;margin-bottom:1.5rem;">'
        . '<span class="rtoc-dob-sync-msg">'
        . '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px;color:#f59e0b" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        . '<strong>' . $totalSkipped . ' enrolment record(s)</strong> across '
        . '<strong>' . $courseCount . ' course(s)</strong> still have no qualification code. '
        . ($qbMatchCount > 0
            ? '<strong>' . $qbMatchCount . ' course(s)</strong> have a matching Qual Builder record — use <em>Link to QB</em> to resolve them instantly. '
            : '')
        . 'Courses with no QB match are likely non-AVETMISS activities (e.g. orientation, LLN, professional development) — use <em>Mark as non-VET</em> to exclude them.'
        . '</span>'
        . '</div>';
}

// Per-course groups.
foreach ($groups as $cid => $enrolments) {
    $info      = $courseInfo[$cid];
    $hasQb     = $info['qbmatch'] !== null;
    $qb        = $info['qbmatch'];
    $borderCol = $hasQb ? '#22c55e' : '#6b7280';

    $linkQbUrl = (new moodle_url('/local/rtocompliance/skipped_programcodes.php', [
        'action'   => 'link_to_qb',
        'courseid' => $cid,
        'sesskey'  => sesskey(),
    ]))->out(false);
    $excludeUrl = (new moodle_url('/local/rtocompliance/skipped_programcodes.php', [
        'action'   => 'exclude_course',
        'courseid' => $cid,
        'sesskey'  => sesskey(),
    ]))->out(false);
    $moodleCourseUrl = (new moodle_url('/course/view.php', ['id' => $cid]))->out(false);

    echo '<div style="border:1px solid #e5e7eb;border-left:4px solid ' . $borderCol . ';border-radius:6px;margin-bottom:1.5rem;overflow:hidden;">';

    // Course header.
    echo '<div style="background:#f9fafb;padding:.85rem 1.1rem;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">';
    echo '<div>';
    echo '<div style="font-weight:700;font-size:1rem;">'
        . '<a href="' . htmlspecialchars($moodleCourseUrl, ENT_QUOTES) . '" target="_blank" style="color:inherit;text-decoration:none;">'
        . htmlspecialchars($info['name'], ENT_QUOTES)
        . ' <svg style="width:12px;height:12px;vertical-align:middle;opacity:.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</a>'
        . '</div>';

    // Category path breadcrumb.
    echo '<div style="font-size:.82rem;color:#6b7280;margin-top:.25rem;">'
        . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:3px;opacity:.6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '<em>Category path:</em> '
        . htmlspecialchars($info['catpath'], ENT_QUOTES)
        . '</div>';

    // QB match indicator or suggestion.
    if ($hasQb) {
        echo '<div style="margin-top:.35rem;font-size:.82rem;color:#16a34a;">'
            . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:3px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="22 4 12 14.01 9 11.01" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '<strong>QB match found:</strong> '
            . htmlspecialchars($qb->qualificationcode . ' ' . $qb->qualificationname, ENT_QUOTES)
            . '</div>';
    } else {
        $topCatName = htmlspecialchars($info['topcat'] ?: 'this category', ENT_QUOTES);
        echo '<div style="margin-top:.35rem;font-size:.82rem;color:#6b7280;">'
            . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:3px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="16" x2="12.01" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
            . 'No QB match. This course sits under <strong>' . $topCatName . '</strong>.'
            . ' If it\'s part of a nationally accredited qualification, move it under the correct qualification category (e.g. <em>ABC12345 — a Diploma qualification</em>) and re-run Sync.'
            . ' If it is not AVETMISS-reportable (orientation, LLN, professional development), click <em>Mark as non-VET</em>.'
            . '</div>';
    }
    echo '</div>'; // end left col

    // Action buttons.
    echo '<div style="display:flex;gap:.5rem;align-items:flex-start;flex-shrink:0;">';
    if ($hasQb) {
        echo '<a href="' . htmlspecialchars($linkQbUrl, ENT_QUOTES) . '"'
            . ' class="btn btn-sm"'
            . ' style="background:#22c55e;color:#fff;border:none;white-space:nowrap;"'
            . ' title="Set programcode = ' . htmlspecialchars($qb->qualificationcode, ENT_QUOTES) . ' on all blank enrolments for this course"'
            . ' onclick="return confirm(\'Link all ' . count($enrolments) . ' enrolment(s) to ' . htmlspecialchars(addslashes($qb->qualificationcode), ENT_QUOTES) . '?\');">'
            . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . 'Link to QB (' . htmlspecialchars($qb->qualificationcode, ENT_QUOTES) . ')'
            . '</a>';
    }
    echo '<a href="' . htmlspecialchars($excludeUrl, ENT_QUOTES) . '"'
        . ' class="btn btn-sm btn-outline-secondary"'
        . ' style="white-space:nowrap;"'
        . ' title="Mark these enrolments as non-VET (vetflag=N) — they will be excluded from AVETMISS reporting and will no longer trigger the blank-programcode warning"'
        . ' onclick="return confirm(\'Mark ' . count($enrolments) . ' enrolment(s) as non-VET (excluded from AVETMISS)?\');">'
        . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        . 'Mark as non-VET'
        . '</a>';
    echo '</div>'; // end buttons
    echo '</div>'; // end header

    // Enrolment rows table.
    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" style="margin:0;width:100%;font-size:.88rem;">';
    echo '<thead><tr>'
        . '<th style="padding:.5rem .75rem;background:#f3f4f6;font-weight:600;">Student</th>'
        . '<th style="padding:.5rem .75rem;background:#f3f4f6;font-weight:600;">Unit Code</th>'
        . '<th style="padding:.5rem .75rem;background:#f3f4f6;font-weight:600;">Status</th>'
        . '</tr></thead>';
    echo '<tbody>';
    foreach ($enrolments as $enrol) {
        $studentName = trim($enrol->firstname . ' ' . $enrol->lastname);
        if ($studentName === '') {
            $studentName = '(unknown)';
        }
        $unitcode = $enrol->unitcode ? htmlspecialchars($enrol->unitcode, ENT_QUOTES) : '<em style="color:#9ca3af">no unit code</em>';
        echo '<tr>'
            . '<td style="padding:.45rem .75rem;">' . htmlspecialchars($studentName, ENT_QUOTES) . '</td>'
            . '<td style="padding:.45rem .75rem;font-family:monospace;">' . $unitcode . '</td>'
            . '<td style="padding:.45rem .75rem;"><span style="background:#fef3c7;color:#92400e;padding:.1rem .45rem;border-radius:4px;font-size:.78rem;font-weight:600;">No qual code</span></td>'
            . '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>'; // overflow-x
    echo '</div>'; // card
}

// ── EXCLUDED COURSES SECTION ─────────────────────────────────────────────────
$excludedCount = count($excludedInfo);
if ($excludedCount > 0) {
    $toggleLabel  = $showexcluded ? 'Hide excluded courses' : 'Show excluded courses (' . $excludedCount . ')';
    $toggleTarget = $showexcluded ? 0 : 1;
    $toggleUrl    = (new moodle_url('/local/rtocompliance/skipped_programcodes.php', [
        'showexcluded' => $toggleTarget,
    ]))->out(false);

    echo '<div style="margin-top:2rem;border-top:1px solid #e5e7eb;padding-top:1.25rem;">';
    echo '<div style="display:flex;align-items:center;gap:1rem;margin-bottom:' . ($showexcluded ? '1rem' : '0') . ';">';
    echo '<a href="' . htmlspecialchars($toggleUrl, ENT_QUOTES) . '" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap;">'
        . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
        . ($showexcluded
            ? '<polyline points="18 15 12 9 6 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            : '<polyline points="6 9 12 15 18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>')
        . '</svg>'
        . htmlspecialchars($toggleLabel, ENT_QUOTES)
        . '</a>';
    if ($showexcluded) {
        echo '<span style="font-size:.85rem;color:#6b7280;">'
            . 'These courses were marked as non-VET and are excluded from AVETMISS reporting. '
            . 'Click <em>Undo exclusion</em> to move a course back into the main list.'
            . '</span>';
    }
    echo '</div>';

    if ($showexcluded) {
        foreach ($excludedInfo as $cid2 => $exInfo) {
            $moodleCourseUrl2 = (new moodle_url('/course/view.php', ['id' => $cid2]))->out(false);
            $undoUrl = (new moodle_url('/local/rtocompliance/skipped_programcodes.php', [
                'action'       => 'undo_exclude',
                'courseid'     => $cid2,
                'sesskey'      => sesskey(),
            ]))->out(false);

            echo '<div style="border:1px solid #e5e7eb;border-left:4px solid #9ca3af;border-radius:6px;margin-bottom:.75rem;overflow:hidden;">';
            echo '<div style="background:#f9fafb;padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">';

            // Course name + enrolment count.
            echo '<div>';
            echo '<div style="font-weight:600;font-size:.95rem;">'
                . '<a href="' . htmlspecialchars($moodleCourseUrl2, ENT_QUOTES) . '" target="_blank" style="color:inherit;text-decoration:none;">'
                . htmlspecialchars($exInfo['name'], ENT_QUOTES)
                . ' <svg style="width:11px;height:11px;vertical-align:middle;opacity:.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . '</a>'
                . '</div>';
            echo '<div style="font-size:.81rem;color:#6b7280;margin-top:.2rem;">'
                . '<span style="background:#f3f4f6;color:#374151;padding:.1rem .4rem;border-radius:4px;font-size:.78rem;font-weight:600;margin-right:.4rem;">non-VET</span>'
                . $exInfo['enrolcount'] . ' enrolment(s) excluded from AVETMISS reporting'
                . '</div>';
            echo '</div>';

            // Undo button.
            echo '<a href="' . htmlspecialchars($undoUrl, ENT_QUOTES) . '"'
                . ' class="btn btn-sm btn-outline-secondary"'
                . ' style="white-space:nowrap;color:#dc2626;border-color:#dc2626;"'
                . ' title="Reset vetflag to Y and clear programcode so these enrolments reappear in the main list"'
                . ' onclick="return confirm(\'Undo the non-VET exclusion for this course? The ' . $exInfo['enrolcount'] . ' enrolment(s) will return to the main skipped-codes list.\');">'
                . '<svg style="width:12px;height:12px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
                . '<polyline points="1 4 1 10 7 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                . '<path d="M3.51 15a9 9 0 1 0 .49-3.47" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                . '</svg>'
                . 'Undo exclusion'
                . '</a>';

            echo '</div>'; // header row
            echo '</div>'; // card
        }
    }
    echo '</div>'; // section wrapper
}

echo '</div>'; // max-width container
echo $OUTPUT->footer();
