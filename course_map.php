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
 * Moodle Course Map — admin page.
 *
 * The single source of truth that maps every Moodle course to an AVETMISS
 * qualification code + unit code.  All completion detection and certificate
 * generation paths read from this table; no runtime regex or category-tree
 * walking occurs after the initial seed.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir  . '/adminlib.php');   // COURSE-MAP-ADMINLIB-FIX: admin_externalpage_setup() requires this; without it PHP throws "Call to undefined function" when navigating to course_map.php directly.
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
admin_externalpage_setup('local_rtocompliance_course_map');

$action  = optional_param('action',  '',  PARAM_ALPHA);
$mapid   = optional_param('mapid',   0,   PARAM_INT);
$filterq = optional_param('filterq', '',  PARAM_ALPHANUMEXT);

$PAGE->set_url('/local/rtocompliance/course_map.php');
$PAGE->set_title('Moodle Course Map');
$PAGE->set_heading('Moodle → AVETMISS Course Map');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

$tableExists = $DB->get_manager()->table_exists('local_rtocompliance_course_map');

// ── POST ACTIONS ──────────────────────────────────────────────────────────────

$seedResult = null;
if ($action === 'seed' && confirm_sesskey()) {
    $seedQual   = optional_param('seedqual', '', PARAM_ALPHANUMEXT);
    $seedResult = local_rtocompliance_seed_course_map($seedQual);
    redirect(new moodle_url('/local/rtocompliance/course_map.php', [
        'seeded'    => 1,
        'inserted'  => $seedResult['inserted'],
        'skipped'   => $seedResult['skipped'],
        'nscanned'  => count($seedResult['quals_scanned']),
    ]));
}

if ($action === 'delete' && confirm_sesskey() && $mapid > 0 && $tableExists) {
    $DB->delete_records('local_rtocompliance_course_map', ['id' => $mapid]);
    redirect(new moodle_url('/local/rtocompliance/course_map.php', ['filterq' => $filterq]));
}

if ($action === 'confirm' && confirm_sesskey() && $mapid > 0 && $tableExists) {
    $DB->set_field('local_rtocompliance_course_map', 'confirmed',    1,    ['id' => $mapid]);
    $DB->set_field('local_rtocompliance_course_map', 'timemodified', time(), ['id' => $mapid]);
    redirect(new moodle_url('/local/rtocompliance/course_map.php', ['filterq' => $filterq]));
}

if ($action === 'add' && confirm_sesskey() && $tableExists) {
    $newcourseid = optional_param('newcourseid', 0,  PARAM_INT);
    $newqualcode = optional_param('newqualcode', '', PARAM_ALPHANUMEXT);
    $newunitcode = optional_param('newunitcode', '', PARAM_ALPHANUMEXT);
    if ($newcourseid > 0 && !empty($newqualcode) && !empty($newunitcode)) {
        // Validate the course actually exists in Moodle before inserting.
        if (!$DB->record_exists('course', ['id' => $newcourseid])) {
            \core\notification::error('Moodle course ID ' . $newcourseid . ' does not exist — mapping not added.');
        } elseif ($DB->record_exists('local_rtocompliance_course_map', ['courseid' => $newcourseid])) {
            // Duplicate: show a friendly message instead of a silent no-op or DB exception.
            \core\notification::warning('Course ID ' . $newcourseid . ' already has a mapping. '
                . 'Delete the existing entry first if you need to remap it to a different qual/unit.');
        } else {
            try {
                $course           = $DB->get_record('course', ['id' => $newcourseid], 'category');
                $row              = new stdClass();
                $row->courseid    = $newcourseid;
                $row->categoryid  = $course ? (int)$course->category : 0;
                $row->qualcode    = strtoupper(trim($newqualcode));
                $row->unitcode    = strtoupper(trim($newunitcode));
                $row->source      = 'manual';
                $row->confirmed   = 1;
                $row->timecreated  = time();
                $row->timemodified = time();
                $row->usermodified = (int)$USER->id;
                $DB->insert_record('local_rtocompliance_course_map', $row);
                \core\notification::success('Manual mapping added: course ' . $newcourseid
                    . ' → ' . strtoupper(trim($newqualcode)) . ' / ' . strtoupper(trim($newunitcode)));
            } catch (\dml_exception $e) {
                // Concurrent add raced us to the unique key.
                \core\notification::warning('Course ID ' . $newcourseid . ' was mapped by a concurrent update — no change made.');
            }
        }
    }
    redirect(new moodle_url('/local/rtocompliance/course_map.php', ['filterq' => $newqualcode]));
}

// ── RENDER ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
// v5.9.404: render the plugin's left-hand sidebar (this page was missing it).
echo local_rtocompliance_render_nav_header('Moodle Course Map');
echo local_rtocompliance_page_banner('Moodle Course Map');

// Seed result flash.
$seededFlash = optional_param('seeded', 0, PARAM_INT);
if ($seededFlash) {
    $ins  = optional_param('inserted', 0, PARAM_INT);
    $skip = optional_param('skipped',  0, PARAM_INT);
    $ns   = optional_param('nscanned', 0, PARAM_INT);
    echo '<div class="alert alert-success" style="margin-bottom:16px;">'
        . '✅ Scan complete — <strong>' . $ins . '</strong> new mapping(s) added, '
        . '<strong>' . $skip . '</strong> already existed, '
        . '<strong>' . $ns  . '</strong> qualification(s) scanned.'
        . '</div>';
}

if (!$tableExists) {
    echo '<div class="alert alert-danger">The <code>local_rtocompliance_course_map</code> table does not exist. '
        . 'Please upgrade the plugin to v5.9.335 or later via the Moodle admin notifications page.</div>';
    echo $OUTPUT->footer();
    exit;
}

// Stats.
$total     = $DB->count_records('local_rtocompliance_course_map');
$confirmed = $DB->count_records('local_rtocompliance_course_map', ['confirmed' => 1]);
$nAuto     = $DB->count_records('local_rtocompliance_course_map', ['source'    => 'auto']);
$nQb       = $DB->count_records('local_rtocompliance_course_map', ['source'    => 'qb']);
$nManual   = $DB->count_records('local_rtocompliance_course_map', ['source'    => 'manual']);
$nUnconf   = $total - $confirmed;

// ── HEADER / SEED CONTROLS ────────────────────────────────────────────────────
echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#6366f1;margin-bottom:20px;">';
echo '<div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">';
echo '<div style="flex:1;min-width:260px;">';
echo '<strong style="font-size:1em;">What is this?</strong><br>';
echo '<span style="font-size:0.98em;color:#444;">This table is the <em>single source of truth</em> that maps every Moodle course to an AVETMISS qualification code + unit code. '
    . 'Certificate generation, completion detection, and autocert triggering all read from here — '
    . 'no more guessing from course names at runtime.</span>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';

// Seed button.
$seedUrl = new moodle_url('/local/rtocompliance/course_map.php', ['action' => 'seed', 'sesskey' => sesskey()]);
echo '<a href="' . $seedUrl->out(false) . '" class="btn btn-primary btn-sm" '
    . 'onclick="this.textContent=\'Scanning…\';this.style.opacity=0.6;">'
    . '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
    . '<path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    . 'Scan &amp; Seed All Quals</a>';

// Filter form.
echo '<form method="get" action="" style="display:flex;gap:6px;align-items:center;">'
    . '<input type="text" name="filterq" value="' . htmlspecialchars($filterq, ENT_QUOTES) . '" '
    . '  placeholder="Filter by qualification code" title="Type a qualification code (e.g. TLI50119) to show only that qualification&rsquo;s course mappings" class="form-control form-control-sm" style="width:250px;">'
    . '<button type="submit" class="btn btn-outline-secondary btn-sm" title="Show only mappings for the qualification code you typed">Filter</button>';
if ($filterq) {
    echo '<a href="/local/rtocompliance/course_map.php" class="btn btn-outline-danger btn-sm">✕</a>';
}
echo '</form>';
echo '</div></div>';

// Stat badges.
echo '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">';
echo '<span class="badge badge-secondary" style="font-size:0.85em;" title="Total number of course-to-unit links in the map (every row below).">Total: ' . $total . '</span>';
echo '<span class="badge badge-success"   style="font-size:0.85em;" title="Links a person has reviewed and accepted. Only confirmed links are trusted for certificates and reporting.">Confirmed: ' . $confirmed . '</span>';
if ($nUnconf > 0) {
    echo '<span class="badge badge-warning" style="font-size:0.85em;" title="Links the system found automatically that still need you to check them. Click the green tick on a row to confirm it.">⚠ Unconfirmed: ' . $nUnconf . '</span>';
}
echo '<span class="badge badge-primary"   style="font-size:0.85em;" title="Links that came from the courses you attached to units in the Qualification Builder.">QB-linked: ' . $nQb . '</span>';
echo '<span class="badge badge-info"      style="font-size:0.85em;" title="Links the system worked out by reading your Moodle category tree and course names.">Auto-detected: ' . $nAuto . '</span>';
echo '<span class="badge badge-dark"      style="font-size:0.85em;" title="Links you added by hand using the Add Manual Mapping form.">Manual: ' . $nManual . '</span>';
echo '</div></div>'; // close bar

// ── MAPPING TABLE ─────────────────────────────────────────────────────────────
$filterWhere  = '';
$filterParams = [];
if (!empty($filterq)) {
    $filterWhere        = ' WHERE cm.qualcode = :fq';
    $filterParams['fq'] = strtoupper(trim($filterq));
}

$maps = $DB->get_records_sql(
    "SELECT cm.id, cm.courseid, cm.qualcode, cm.unitcode, cm.source, cm.confirmed,
            c.fullname AS coursename
       FROM {local_rtocompliance_course_map} cm
       LEFT JOIN {course} c ON c.id = cm.courseid
      $filterWhere
      ORDER BY cm.qualcode ASC, cm.unitcode ASC, cm.courseid ASC",
    $filterParams
);

if (empty($maps)) {
    echo '<div class="alert alert-info" style="margin-top:16px;">';
    if ($filterq) {
        echo 'No mappings found for qual code <code>' . htmlspecialchars($filterq) . '</code>.';
    } else {
        echo '<strong>No course mappings yet.</strong> Click <strong>Scan &amp; Seed All Quals</strong> above to auto-detect from your Qual Builder records and Moodle category tree.';
    }
    echo '</div>';
} else {
    $sourceLabels = [
        'qb'     => '<span class="badge badge-primary" title="Linked directly in Qual Builder">QB</span>',
        'auto'   => '<span class="badge badge-info"    title="Detected from Moodle category tree / course name">Auto</span>',
        'manual' => '<span class="badge badge-dark"    title="Added manually by admin">Manual</span>',
    ];
    echo '<table class="table table-sm table-bordered table-hover" style="font-size:0.86em;margin-top:12px;">';
    echo '<thead class="thead-light"><tr>'
        . '<th>Qual Code</th><th>Unit Code</th>'
        . '<th>Moodle Course</th><th>Source</th><th>Status</th><th style="width:120px">Actions</th>'
        . '</tr></thead><tbody>';

    foreach ($maps as $m) {
        $courseName = $m->coursename
            ? htmlspecialchars($m->coursename, ENT_QUOTES)
            : '<em style="color:#999">(deleted course)</em>';
        $courseLink = $m->coursename
            ? '<a href="' . (new moodle_url('/course/view.php', ['id' => $m->courseid]))->out(false) . '" target="_blank">' . $courseName . '</a>'
            : $courseName;
        $sourceHtml = $sourceLabels[$m->source] ?? '<span class="badge badge-secondary">' . htmlspecialchars($m->source) . '</span>';
        $statusHtml = $m->confirmed
            ? '<span class="badge badge-success">✓ Confirmed</span>'
            : '<span class="badge badge-warning">Unconfirmed</span>';

        $confirmBtn = '';
        if (!$m->confirmed) {
            $confirmUrl = new moodle_url('/local/rtocompliance/course_map.php', [
                'action' => 'confirm', 'mapid' => $m->id, 'sesskey' => sesskey(), 'filterq' => $filterq,
            ]);
            $confirmBtn = '<a href="' . $confirmUrl->out(false) . '" class="btn btn-xs btn-success" style="margin-right:3px;" title="Mark as confirmed">✓</a>';
        }
        $deleteUrl = new moodle_url('/local/rtocompliance/course_map.php', [
            'action' => 'delete', 'mapid' => $m->id, 'sesskey' => sesskey(), 'filterq' => $filterq,
        ]);
        $deleteBtn = '<a href="' . $deleteUrl->out(false) . '" class="btn btn-xs btn-danger" '
            . 'onclick="return confirm(\'Remove this mapping? The course will be excluded from completion detection until re-added.\')" title="Remove mapping">✕</a>';

        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($m->qualcode) . '</code></td>';
        echo '<td><code>' . htmlspecialchars($m->unitcode) . '</code></td>';
        echo '<td>' . $courseLink . ' <small style="color:#aaa">#' . (int)$m->courseid . '</small></td>';
        echo '<td>' . $sourceHtml . '</td>';
        echo '<td>' . $statusHtml . '</td>';
        echo '<td>' . $confirmBtn . $deleteBtn . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

// ── ADD MANUAL MAPPING ────────────────────────────────────────────────────────
echo '<hr style="margin-top:24px;">';
echo '<h5>Add Manual Mapping</h5>';
echo '<p style="color:#555;font-size:0.88em;max-width:680px;">Use this when a Moodle course cannot be auto-detected (e.g. the course name does not start with the unit code, or it lives outside the qualification\'s category tree). '
    . 'Manual mappings are always confirmed and are never overwritten by a re-scan.</p>';

echo '<form method="post" action="" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:4px;">';
echo '<input type="hidden" name="action"  value="add">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="filterq" value="' . htmlspecialchars($filterq, ENT_QUOTES) . '">';
echo '<div><label style="font-size:0.82em;font-weight:600;display:block;">Qual Code</label>'
    . '<input type="text" name="newqualcode" class="form-control form-control-sm" placeholder="e.g. ABC12345" style="width:140px;text-transform:uppercase" required></div>';
echo '<div><label style="font-size:0.82em;font-weight:600;display:block;">Unit Code</label>'
    . '<input type="text" name="newunitcode" class="form-control form-control-sm" placeholder="e.g. ABC12345" style="width:140px;text-transform:uppercase" required></div>';
echo '<div><label style="font-size:0.82em;font-weight:600;display:block;">Moodle Course ID</label>'
    . '<input type="number" name="newcourseid" class="form-control form-control-sm" placeholder="e.g. 42" style="width:120px" min="1" required></div>';
echo '<div><button type="submit" class="btn btn-sm btn-primary" style="margin-top:1px;">Add Mapping</button></div>';
echo '</form>';
echo '<small style="color:#888;">Tip: find the course ID in the Moodle URL when viewing a course: <code>/course/view.php?id=<strong>42</strong></code></small>';

echo $OUTPUT->footer();
