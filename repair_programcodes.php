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
 * Repair Missing Qualification Codes (TASK-81, v5.9.359)
 *
 * Lists all Qual Builder records that have enrolment rows with a blank programcode,
 * showing the count of affected rows.  For each qualification the admin can
 * bulk-apply the correct programcode to all matching blank enrolments with one
 * click (after a confirmation prompt).
 *
 * This closes the gap left by the semester-copy outcome fallback (v5.9.345):
 * that fallback recovers the right outcome at cert-issue time, but the underlying
 * enrolment rows still have no programcode, so NAT export queries silently exclude
 * them and debugging() fires on every partial SoA render.
 *
 * Affected enrolment rows are those where:
 *   - (programcode IS NULL OR programcode = '')
 *   - vetflag != 'N'  (i.e. AVETMISS-reportable)
 *   - courseid is linked to the qualification via qualunits.courseid OR
 *     qualunit_courses.courseid (both primary and variant/archive courses)
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
admin_externalpage_setup('local_rtocompliance_repair_programcodes');

$action        = optional_param('action',        '', PARAM_ALPHANUMEXT);
$qualbuilderid = optional_param('qualbuilderid', 0,  PARAM_INT);

// ── POST ACTION: bulk_apply ────────────────────────────────────────────────
// Set programcode on every blank enrolment whose course belongs to this qual.
if ($action === 'bulk_apply' && $qualbuilderid > 0 && confirm_sesskey()) {

    $qb = $DB->get_record('local_rtocompliance_qualbuilder',
        ['id' => $qualbuilderid], 'id, qualificationcode, qualificationname', MUST_EXIST);

    $qualcode = trim((string)$qb->qualificationcode);
    if ($qualcode === '') {
        redirect(
            new moodle_url('/local/rtocompliance/repair_programcodes.php'),
            get_string('repair_programcodes_no_qualcode', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Collect all course IDs linked to this qual (primary + variant/archive).
    $courseids = $DB->get_fieldset_sql(
        "SELECT DISTINCT qu.courseid
           FROM {local_rtocompliance_qualunits} qu
          WHERE qu.qualbuilderid = :qbid
            AND qu.courseid IS NOT NULL
            AND qu.courseid > 0
         UNION
         SELECT DISTINCT quc.courseid
           FROM {local_rtocompliance_qualunit_courses} quc
           JOIN {local_rtocompliance_qualunits} qu2 ON qu2.id = quc.qualunitid
          WHERE qu2.qualbuilderid = :qbid2
            AND quc.courseid IS NOT NULL
            AND quc.courseid > 0",
        ['qbid' => $qualbuilderid, 'qbid2' => $qualbuilderid]
    );

    if (empty($courseids)) {
        redirect(
            new moodle_url('/local/rtocompliance/repair_programcodes.php'),
            get_string('repair_programcodes_no_courses', 'local_rtocompliance',
                s($qb->qualificationcode)),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Update all blank enrolments for those courses.
    list($inSql, $inParams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
    $inParams['qcode'] = $qualcode;
    $updated = 0;

    // Chunk the course IDs to avoid hitting DB IN() limits on large sites.
    $chunks = array_chunk($courseids, 200);
    foreach ($chunks as $chunk) {
        list($chunkSql, $chunkParams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'chk');
        // Count before (for this chunk).
        $chunkCount = (int)$DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {local_rtocompliance_enrolments}
              WHERE courseid $chunkSql
                AND (programcode IS NULL OR programcode = '')
                AND (vetflag IS NULL OR vetflag != 'N')",
            $chunkParams
        );
        if ($chunkCount === 0) {
            continue;
        }
        // Update.
        $DB->execute(
            "UPDATE {local_rtocompliance_enrolments}
                SET programcode   = :qcode,
                    timemodified  = :tmod
              WHERE courseid $chunkSql
                AND (programcode IS NULL OR programcode = '')
                AND (vetflag IS NULL OR vetflag != 'N')",
            array_merge(['qcode' => $qualcode, 'tmod' => time()], $chunkParams)
        );
        $updated += $chunkCount;
    }

    // Log the repair action.
    $log = new stdClass();
    $log->action      = 'repair_programcodes';
    $log->component   = 'enrolments';
    $log->itemid      = $qualbuilderid;
    $log->userid      = $USER->id;
    $log->targetuserid = null;
    $log->details     = json_encode([
        'qualbuilderid'    => $qualbuilderid,
        'qualificationcode' => $qualcode,
        'rows_updated'     => $updated,
    ]);
    $log->ipaddress   = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    $msg = get_string('repair_programcodes_applied', 'local_rtocompliance', [
        'count'    => $updated,
        'qualcode' => s($qualcode),
        'qualname' => s($qb->qualificationname),
    ]);
    redirect(
        new moodle_url('/local/rtocompliance/repair_programcodes.php'),
        $msg,
        null,
        $updated > 0
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_WARNING
    );
}

// ── PAGE RENDER ──────────────────────────────────────────────────────────────

$PAGE->set_title(get_string('repair_programcodes_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('repair_programcodes_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

$navheader = local_rtocompliance_render_nav_header(
    get_string('repair_programcodes_title', 'local_rtocompliance'),
    get_string('student_records', 'local_rtocompliance'),
    (new moodle_url('/local/rtocompliance/students.php'))->out(false)
);

echo $OUTPUT->header();
echo $navheader;

echo '<div style="max-width:900px;">';
echo html_writer::tag('h2', get_string('repair_programcodes_title', 'local_rtocompliance'));
echo html_writer::tag('p',
    get_string('repair_programcodes_desc', 'local_rtocompliance'),
    ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']
);

// ── Query: which quals have blank enrolments? ────────────────────────────────
// For each active/draft qualbuilder record, count enrolments that are:
//   (a) linked to a course in that qual (primary or variant/archive)
//   (b) missing a programcode
//   (c) AVETMISS-reportable (vetflag != 'N')
$rows = $DB->get_records_sql(
    "SELECT qb.id,
            qb.qualificationcode,
            qb.qualificationname,
            qb.status,
            COUNT(DISTINCT e.id) AS blankcount
       FROM {local_rtocompliance_qualbuilder} qb
       JOIN {local_rtocompliance_qualunits} qu  ON qu.qualbuilderid = qb.id
       JOIN {local_rtocompliance_enrolments} e
            ON (e.courseid = qu.courseid
                OR e.courseid IN (
                    SELECT quc.courseid
                      FROM {local_rtocompliance_qualunit_courses} quc
                     WHERE quc.qualunitid = qu.id
                )
               )
      WHERE qb.status != 'superseded'
        AND qu.courseid IS NOT NULL
        AND qu.courseid > 0
        AND (e.programcode IS NULL OR e.programcode = '')
        AND (e.vetflag IS NULL OR e.vetflag != 'N')
      GROUP BY qb.id, qb.qualificationcode, qb.qualificationname, qb.status
      HAVING COUNT(DISTINCT e.id) > 0
      ORDER BY blankcount DESC, qb.qualificationcode ASC"
);

if (empty($rows)) {
    // No affected quals — show a success state.
    echo html_writer::tag(
        'div',
        html_writer::tag('strong', '✓ ' . get_string('repair_programcodes_all_clean', 'local_rtocompliance')) .
        html_writer::tag('p',
            get_string('repair_programcodes_all_clean_desc', 'local_rtocompliance'),
            ['style' => 'margin:6px 0 0 0;font-size:.9rem;']
        ),
        ['style' => 'background:#f0fdf4;border:1px solid #22c55e;border-radius:8px;padding:16px 20px;color:#166534;']
    );
} else {
    // Summary banner.
    $totalblank = array_sum(array_column((array)$rows, 'blankcount'));
    echo html_writer::tag(
        'div',
        html_writer::tag('strong',
            sprintf(
                get_string('repair_programcodes_summary', 'local_rtocompliance'),
                count($rows),
                $totalblank
            )
        ) .
        html_writer::tag('p',
            get_string('repair_programcodes_summary_desc', 'local_rtocompliance'),
            ['style' => 'margin:6px 0 0 0;font-size:.9rem;']
        ),
        ['style' => 'background:#fffbeb;border:1px solid #f59e0b;border-left:4px solid #d97706;'
            . 'border-radius:8px;padding:14px 18px;margin-bottom:20px;color:#92400e;']
    );

    // Table of affected qualifications.
    echo html_writer::start_tag('table', [
        'class' => 'table',
        'style' => 'background:white;border:1px solid #e5e7eb;border-radius:12px;border-collapse:collapse;width:100%;',
    ]);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('qualification', 'local_rtocompliance'),
        ['style' => 'padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;']);
    echo html_writer::tag('th', get_string('repair_programcodes_col_blank', 'local_rtocompliance'),
        ['style' => 'padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;text-align:center;']);
    echo html_writer::tag('th', '',
        ['style' => 'padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;width:180px;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($rows as $row) {
        $applyUrl = (new moodle_url('/local/rtocompliance/repair_programcodes.php', [
            'action'        => 'bulk_apply',
            'qualbuilderid' => $row->id,
            'sesskey'       => sesskey(),
        ]))->out(false);

        $confirmMsg = addslashes(
            get_string('repair_programcodes_confirm', 'local_rtocompliance', [
                'count'    => $row->blankcount,
                'qualcode' => $row->qualificationcode,
            ])
        );

        $statusBadge = '';
        if ($row->status === 'draft') {
            $statusBadge = ' <span style="font-size:.72rem;background:#fef9c3;color:#854d0e;'
                . 'padding:.1rem .45rem;border-radius:4px;font-weight:600;vertical-align:middle;">'
                . 'draft</span>';
        }

        echo html_writer::start_tag('tr', ['style' => 'border-bottom:1px solid #f3f4f6;']);

        // Qual name cell.
        echo html_writer::tag('td',
            html_writer::tag('div',
                html_writer::tag('span',
                    s($row->qualificationcode),
                    ['style' => 'font-weight:700;font-family:monospace;font-size:.9rem;']
                ) . $statusBadge,
                ['style' => 'margin-bottom:.2rem;']
            ) .
            html_writer::tag('div',
                s($row->qualificationname),
                ['style' => 'font-size:.85rem;color:#6b7280;']
            ),
            ['style' => 'padding:12px 14px;']
        );

        // Blank count cell.
        echo html_writer::tag('td',
            html_writer::tag('span',
                number_format($row->blankcount),
                ['style' => 'font-size:1.1rem;font-weight:700;color:#d97706;']
            ),
            ['style' => 'padding:12px 14px;text-align:center;vertical-align:middle;']
        );

        // Action cell.
        echo html_writer::tag('td',
            html_writer::link(
                $applyUrl,
                get_string('repair_programcodes_apply_btn', 'local_rtocompliance'),
                [
                    'class'   => 'btn btn-sm btn-warning',
                    'style'   => 'white-space:nowrap;',
                    'onclick' => "return confirm('" . $confirmMsg . "');",
                ]
            ),
            ['style' => 'padding:12px 14px;text-align:right;vertical-align:middle;']
        );

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

// ── Info box: what this does ────────────────────────────────────────────────
echo '<details style="margin-top:28px;border:1px solid #e5e7eb;border-radius:8px;">';
echo '<summary style="padding:12px 16px;cursor:pointer;font-size:.9rem;font-weight:600;color:#374151;list-style:none;display:flex;align-items:center;gap:8px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
echo get_string('repair_programcodes_how_heading', 'local_rtocompliance');
echo '</summary>';
echo '<div style="padding:0 16px 16px 16px;font-size:.875rem;color:#374151;">';
echo html_writer::tag('p', get_string('repair_programcodes_how_body', 'local_rtocompliance'),
    ['style' => 'margin:.75rem 0 .5rem;']);
echo html_writer::start_tag('ul', ['style' => 'padding-left:1.4rem;margin:.4rem 0;']);
echo html_writer::tag('li', get_string('repair_programcodes_how_li1', 'local_rtocompliance'),
    ['style' => 'margin-bottom:.35rem;']);
echo html_writer::tag('li', get_string('repair_programcodes_how_li2', 'local_rtocompliance'),
    ['style' => 'margin-bottom:.35rem;']);
echo html_writer::tag('li', get_string('repair_programcodes_how_li3', 'local_rtocompliance'),
    ['style' => 'margin-bottom:.35rem;']);
echo html_writer::end_tag('ul');
echo '</div>';
echo '</details>';

echo '</div>'; // max-width container
echo $OUTPUT->footer();
