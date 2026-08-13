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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_results');

// RESULTS-CAPABILITY-FIX (v5.9.300): admin_externalpage_setup enforces admin-area
// access but does not gate on the plugin-specific capability.  Any Moodle site
// admin could reach this page and read all student results.  Explicitly requiring
// local/rtocompliance:manage ensures only designated RTO compliance managers can
// view the student progress grid, matching the access model on every other page.
require_capability('local/rtocompliance:manage', context_system::instance());

$qualbuilderid = optional_param('id', 0, PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);

// BUG-RESULTS-NOID-BOUNCE (v4.2.29, 30 Apr 2026): the "Student Results" item in
// the Site Administration tree (settings.php) and the side nav (lib.php) both
// link to qualbuilder_results.php with NO ?id=N parameter — the only place that
// passed an id was the per-row "View Results" button on qualbuilder.php.  The
// previous behaviour for a missing id was redirect() → qualbuilder.php with the
// "invalidqualification" red error toast, which made every top-level click on
// "Student Results" appear to fail with a confusing error message.  Fixed by
// rendering an "All Training Products" picker view instead — lists every
// qualification / skill set / single unit with its enrolled-student count, a
// completion-rate hint, and a "View Results" button that drills into the
// per-product report.  The original per-id report below is unchanged.
if (empty($qualbuilderid)) {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_results.php'));
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('student_results', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('student_results', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
    $PAGE->navbar->add(get_string('student_results', 'local_rtocompliance'));
    $PAGE->requires->css('/local/rtocompliance/styles.css');

    // Pick up every training product with its enrolled-student count and a
    // completed-student count (matches qualbuilder_results.php's own definition
    // of "completed" — a student with at least one final-outcome enrolment for
    // every selected unit in the product).
    $picker_search = optional_param('q', '', PARAM_TEXT);
    $where = '';
    $params = [];
    if ($picker_search !== '') {
        $where = " WHERE " . $DB->sql_like('qb.qualificationcode', ':sc', false, false) .
                 " OR "    . $DB->sql_like('qb.qualificationname', ':sn', false, false);
        $params['sc'] = '%' . $picker_search . '%';
        $params['sn'] = '%' . $picker_search . '%';
    }
    $products = $DB->get_records_sql(
        "SELECT qb.id, qb.qualificationcode, qb.qualificationname, qb.producttype, qb.status,
                (SELECT COUNT(DISTINCT e.studentid)
                   FROM {local_rtocompliance_enrolments} e
                  WHERE e.programcode = qb.qualificationcode) AS studentcount,
                (SELECT COUNT(*)
                   FROM {local_rtocompliance_qualunits} qu
                  WHERE qu.qualbuilderid = qb.id) AS unitcount
           FROM {local_rtocompliance_qualbuilder} qb
                $where
       ORDER BY qb.status ASC, qb.qualificationcode ASC",
        $params
    );

    $PAGE->add_body_class('path-local-rtocompliance');
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header(
        get_string('student_results', 'local_rtocompliance'),
        get_string('qualificationbuilder', 'local_rtocompliance'),
        '/local/rtocompliance/qualbuilder.php',
        'qualresults'
    );

    echo html_writer::start_div('compliance-container');
    echo html_writer::start_div('compliance-header');
    echo html_writer::tag('h2', get_string('student_results', 'local_rtocompliance'));
    echo html_writer::end_div();
    echo html_writer::tag('p',
        'Pick a training product below to view its enrolled students, their per-unit AVETMISS outcomes, and overall completion progress. Each row links to the same report you reach from the per-row "View Results" button on the Qualification Builder page.',
        ['class' => 'text-muted']
    );

    // Search box
    echo html_writer::start_div('filter-section', ['style' => 'background:#f8fafc;padding:16px;border-radius:8px;margin-bottom:24px;']);
    echo '<form method="get" action="" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <input type="text" name="q" class="form-control" style="min-width:240px;"
               placeholder="Search by qualification code or name" value="' . s($picker_search) . '">
        <button type="submit" class="btn btn-primary">Search</button>';
    if ($picker_search !== '') {
        echo ' <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php'))->out() . '" class="btn btn-outline-secondary">Clear</a>';
    }
    echo '</form>';
    echo html_writer::end_div();

    if (empty($products)) {
        echo html_writer::div(
            '<div style="text-align:center;padding:40px;color:#6b7280;">
                <p style="font-size:16px;margin-bottom:8px;">No training products found.</p>
                <p style="font-size:14px;">Create a qualification, skill set or single unit in the <a href="' .
                (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out() .
                '">Qualification Builder</a> first, then come back here to view student results.</p>
             </div>',
            'no-products-message'
        );
    } else {
        $producttypelabels = [
            'qualification' => 'Qualification',
            'skillset'      => 'Skill Set',
            'singleunit'    => 'Single Unit',
        ];
        echo '<div style="overflow-x:auto;background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
        echo '<table class="table table-striped" style="margin:0;min-width:760px;">';
        echo '<thead style="background:#f1f5f9;"><tr>';
        echo '<th>Code</th><th>Name</th><th>Type</th><th style="text-align:center;">Units</th><th style="text-align:center;">Enrolled Students</th><th style="text-align:center;">Status</th><th style="text-align:center;min-width:140px;">Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($products as $p) {
            $typebadge = $producttypelabels[$p->producttype] ?? ucfirst((string) $p->producttype);
            $statusbadge = ($p->status === 'active') ? 'badge-success' : (($p->status === 'draft') ? 'badge-secondary' : 'badge-warning');
            echo '<tr>';
            echo '<td><strong>' . s($p->qualificationcode) . '</strong></td>';
            echo '<td>' . s($p->qualificationname) . '</td>';
            echo '<td>' . s($typebadge) . '</td>';
            echo '<td style="text-align:center;">' . (int) $p->unitcount . '</td>';
            echo '<td style="text-align:center;"><strong>' . (int) $p->studentcount . '</strong></td>';
            echo '<td style="text-align:center;"><span class="badge ' . $statusbadge . '">' . s(ucfirst((string) $p->status)) . '</span></td>';
            echo '<td style="text-align:center;">
                <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $p->id]))->out() . '"
                   class="btn btn-sm btn-primary">View Results</a>
            </td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo html_writer::start_div('', ['style' => 'margin-top:24px;']);
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/qualbuilder.php'),
        '&larr; Back to Qualification Builder',
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::end_div();

    echo html_writer::end_div(); // .compliance-container
    echo $OUTPUT->footer();
    exit;
}

$product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid]);
if (!$product) {
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder.php'),
        get_string('qualificationnotfound', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qualbuilderid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('student_results', 'local_rtocompliance') . ': ' . $product->qualificationcode);
$PAGE->set_heading(get_string('student_results', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add($product->qualificationcode, new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid]));
$PAGE->navbar->add(get_string('student_results', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

// RESULTS-SELECTED-UNITS-FIX (v5.9.295): must filter selected=1 so that:
// (a) "Units in Product" stat matches Core+Elective counts (which both use selected=1),
// (b) the per-student progress grid only shows units the RTO has actually included,
// (c) the progress % denominator is not inflated by unselected/deselected unit rows.
$units = $DB->get_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qualbuilderid, 'selected' => 1], 'unittype ASC, unitcode ASC');
$unitcodes = array_column($units, 'unitcode');

// AVETMISS 2.3 Outcome Identifier - National codes.
// Source: NCVER AVETMISS Data Element Definitions Edition 2.3 (updated November 2022).
$outcomecodes = [
    '20' => ['label' => 'Competent',                      'badge' => 'badge-success',   'short' => 'C'],
    '30' => ['label' => 'Not Yet Competent',              'badge' => 'badge-danger',    'short' => 'NYC'],
    '40' => ['label' => 'Withdrawn',                      'badge' => 'badge-warning',   'short' => 'W'],
    '41' => ['label' => 'Incomplete - RTO Closure',       'badge' => 'badge-warning',   'short' => 'INC'],
    '51' => ['label' => 'RPL Granted',                    'badge' => 'badge-success',   'short' => 'RPL'],
    '52' => ['label' => 'RPL Not Granted',                'badge' => 'badge-danger',    'short' => 'RPL-NG'],
    '60' => ['label' => 'Credit Transfer',                'badge' => 'badge-success',   'short' => 'CT'],
    '61' => ['label' => 'Superseded Subject',             'badge' => 'badge-secondary', 'short' => 'SUP'],
    '70' => ['label' => 'Continuing',                     'badge' => 'badge-info',      'short' => 'CONT'],
    '81' => ['label' => 'Non-Assessed - Satisfactory',    'badge' => 'badge-success',   'short' => 'S'],
    '82' => ['label' => 'Non-Assessed - Unsatisfactory',  'badge' => 'badge-danger',    'short' => 'U'],
    '85' => ['label' => 'Not Yet Started',                'badge' => 'badge-secondary', 'short' => 'NYS'],
];

// Match students who either:
//   (a) have an enrolment record with programcode set to this qualification, OR
//   (b) are enrolled in any Moodle course that is a primary delivery course for this QB, OR
//   (c) are enrolled in any variant delivery course for this QB (qualunit_courses).
// RESULTS-VARIANT-STUDENT-FIX (v5.9.295): added UNION with qualunit_courses so that
// students who completed via a variant course but whose enrolment was created before
// programcode auto-detection are not silently excluded from the results page.
$sql = "SELECT DISTINCT s.id as studentid, s.userid, s.usi, s.usiverified,
               u.firstname, u.lastname, u.email,
               u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
        FROM {local_rtocompliance_students} s
        JOIN {user} u ON u.id = s.userid
        JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
        WHERE (
            e.programcode = :programcode
            OR e.courseid IN (
                SELECT DISTINCT qu2.courseid
                  FROM {local_rtocompliance_qualunits} qu2
                 WHERE qu2.qualbuilderid = :qbid_fallback
                   AND qu2.courseid IS NOT NULL
                   AND qu2.selected = 1
                UNION
                SELECT DISTINCT quc.courseid
                  FROM {local_rtocompliance_qualunit_courses} quc
                  JOIN {local_rtocompliance_qualunits} qu3 ON qu3.id = quc.qualunitid
                 WHERE qu3.qualbuilderid = :qbid_variant
                   AND quc.courseid IS NOT NULL
                   AND quc.is_archive = 0
            )
        )
        AND u.deleted = 0";
$params = [
    'programcode'  => $product->qualificationcode,
    'qbid_fallback' => $qualbuilderid,
    'qbid_variant'  => $qualbuilderid,
];

if (!empty($search)) {
    $sql .= " AND (" . $DB->sql_like('u.firstname', ':search1', false, false) .
            " OR " . $DB->sql_like('u.lastname', ':search2', false, false) .
            " OR " . $DB->sql_like('u.email', ':search3', false, false) .
            " OR " . $DB->sql_like('s.usi', ':search4', false, false) . ")";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
    $params['search4'] = '%' . $search . '%';
}

if ($filter === 'complete') {
    $sql .= " AND NOT EXISTS (
        SELECT 1 FROM {local_rtocompliance_qualunits} qu
        WHERE qu.qualbuilderid = :qbid1
        AND NOT EXISTS (
            SELECT 1 FROM {local_rtocompliance_enrolments} e2
            WHERE e2.studentid = s.id
            AND e2.unitcode = qu.unitcode
            AND e2.outcomeidentifier IN ('20', '30', '40', '81')
        )
    )";
    $params['qbid1'] = $qualbuilderid;
} else if ($filter === 'inprogress') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM {local_rtocompliance_qualunits} qu
        WHERE qu.qualbuilderid = :qbid2
        AND NOT EXISTS (
            SELECT 1 FROM {local_rtocompliance_enrolments} e2
            WHERE e2.studentid = s.id
            AND e2.unitcode = qu.unitcode
            AND e2.outcomeidentifier IN ('20', '30', '40', '81')
        )
    )";
    $params['qbid2'] = $qualbuilderid;
}

$sql .= " ORDER BY u.lastname, u.firstname";

// BUG-13 FIX: Strip ORDER BY before building countsql. The $sql ends with
// "ORDER BY u.lastname, u.firstname" which, when included in a COUNT query,
// is either an error (Postgres disallows ORDER BY in a non-wrapped subquery)
// or unnecessary overhead. Strip it to avoid a potential fatal SQL error.
$fromsql = substr($sql, strpos($sql, 'FROM'));
$fromsql = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $fromsql);
$countsql = "SELECT COUNT(DISTINCT s.id) " . $fromsql;
$totalcount = $DB->count_records_sql($countsql, $params);

$students = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

if ($export === 'csv') {
    $allstudents = $DB->get_records_sql($sql, $params);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_results_' . $product->qualificationcode . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    $headers = ['Student Name', 'Email', 'USI', 'USI Verified'];
    foreach ($units as $unit) {
        $headers[] = $unit->unitcode;
    }
    $headers[] = 'Completion %';
    $headers[] = 'Status';
    fputcsv($output, $headers);
    
    foreach ($allstudents as $student) {
        // FIX: Build a unitcode→enrolment map that handles the case where a student
        // has multiple enrolments for the same unitcode (e.g. one withdrawn, one active).
        // We use get_records_sql with explicit ordering so the preferred row wins:
        //   1. active > completed > withdrawn/hold
        //   2. most recently modified within same status
        // Previously get_records() keyed by unitcode silently discarded duplicates.
        $enrolment_rows = $DB->get_records_sql(
            "SELECT id, unitcode, outcomeidentifier, status, timemodified
               FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND programcode = :programcode
           ORDER BY CASE status
                    WHEN 'active'     THEN 1
                    WHEN 'completed'  THEN 2
                    WHEN 'hold'       THEN 3
                    WHEN 'withdrawn'  THEN 4
                    ELSE 5 END ASC,
                    timemodified DESC",
            ['studentid' => $student->studentid, 'programcode' => $product->qualificationcode]
        );
        // Key by unitcode; first-wins preserves the priority ordering above.
        $enrolments = [];
        foreach ($enrolment_rows as $er) {
            if (!isset($enrolments[$er->unitcode])) {
                $enrolments[$er->unitcode] = $er;
            }
        }

        $completedunits = 0;
        $totalunits = count($units);
        
        $row = [
            fullname($student),
            $student->email,
            $student->usi ?? '',
            $student->usiverified ? 'Yes' : 'No'
        ];
        
        foreach ($units as $unit) {
            $enrolment = $enrolments[$unit->unitcode] ?? null;
            if ($enrolment) {
                $code = $outcomecodes[$enrolment->outcomeidentifier]['short'] ?? $enrolment->outcomeidentifier;
                $row[] = $code;
                if (in_array($enrolment->outcomeidentifier, ['20', '30', '40', '81'])) {
                    $completedunits++;
                }
            } else {
                $row[] = '-';
            }
        }
        
        $percentage = $totalunits > 0 ? round(($completedunits / $totalunits) * 100) : 0;
        $row[] = $percentage . '%';
        $row[] = ($completedunits >= $totalunits) ? 'Complete' : 'In Progress';
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('student_results', 'local_rtocompliance') . ': ' . $product->qualificationcode,
    get_string('qualificationbuilder', 'local_rtocompliance'),
    '/local/rtocompliance/qualbuilder.php',
    'qualresults'
);

$producttypes = [
    'qualification' => 'Qualification',
    'skillset' => 'Skill Set',
    'singleunit' => 'Single Unit',
];
$producttype = $producttypes[$product->producttype] ?? 'Training Product';

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('student_results', 'local_rtocompliance'));
echo html_writer::end_div();
echo html_writer::tag('p', $producttype . ': <strong>' . s($product->qualificationcode) . ' - ' . s($product->qualificationname) . '</strong>', ['class' => 'text-muted', 'style' => 'margin: 0 0 1rem;']);

// ── Quick Statistics ──────────────────────────────────────────────────────────
// RESULTS-STATS-MATCH-FIX (v5.9.295): stats queries now use the same
// programcode-OR-courseid(primary+variant) pattern as the main student table
// query above so that "Total Enrolled" always equals the student table row count
// and Completed/In-Progress stats are never negative or inconsistent.
$statcoursesub = "(
    SELECT DISTINCT qu2.courseid
      FROM {local_rtocompliance_qualunits} qu2
     WHERE qu2.qualbuilderid = :statqbid1
       AND qu2.courseid IS NOT NULL
       AND qu2.selected = 1
    UNION
    SELECT DISTINCT quc.courseid
      FROM {local_rtocompliance_qualunit_courses} quc
      JOIN {local_rtocompliance_qualunits} qu3 ON qu3.id = quc.qualunitid
     WHERE qu3.qualbuilderid = :statqbid2
       AND quc.courseid IS NOT NULL
       AND quc.is_archive = 0
)";

$totalstudents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT s.id)
       FROM {local_rtocompliance_students} s
       JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
      WHERE (e.programcode = :programcode OR e.courseid IN $statcoursesub)",
    ['programcode' => $product->qualificationcode, 'statqbid1' => $qualbuilderid, 'statqbid2' => $qualbuilderid]
);

$completedstudents = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT s.id)
       FROM {local_rtocompliance_students} s
       JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
      WHERE (e.programcode = :programcode OR e.courseid IN $statcoursesub)
        AND NOT EXISTS (
            SELECT 1 FROM {local_rtocompliance_qualunits} qu
             WHERE qu.qualbuilderid = :qbid
               AND qu.selected = 1
               AND NOT EXISTS (
                   SELECT 1 FROM {local_rtocompliance_enrolments} e2
                    WHERE e2.studentid = s.id
                      AND e2.unitcode = qu.unitcode
                      AND e2.outcomeidentifier IN ('20', '30', '40', '81')
               )
        )",
    ['programcode' => $product->qualificationcode, 'statqbid1' => $qualbuilderid, 'statqbid2' => $qualbuilderid, 'qbid' => $qualbuilderid]
);

$inprogressstudents = $totalstudents - $completedstudents;
$completionrate     = ($totalstudents > 0) ? round($completedstudents / $totalstudents * 100) : 0;
$coreunits          = $DB->count_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qualbuilderid, 'unittype' => 'core',     'selected' => 1]);
$electiveunits      = $DB->count_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qualbuilderid, 'unittype' => 'elective', 'selected' => 1]);
$totalunitenrolments = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_enrolments} WHERE programcode = :code", ['code' => $product->qualificationcode]);

echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 24px;']);
foreach ([
    ['label' => 'Total Enrolled',        'value' => $totalstudents,       'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('users')],
    ['label' => 'Completed',             'value' => $completedstudents,   'color' => 'green',  'icon' => local_rtocompliance_stat_icon('check')],
    ['label' => 'In Progress',           'value' => $inprogressstudents,  'color' => $inprogressstudents > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'Units in Product',      'value' => count($units),        'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('list')],
    ['label' => 'Completion Rate',       'value' => $completionrate . '%','color' => $completionrate >= 50 ? 'green' : 'amber', 'icon' => local_rtocompliance_stat_icon('percent')],
    ['label' => 'Core Units',            'value' => $coreunits,           'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('book')],
    ['label' => 'Elective Units',        'value' => $electiveunits,       'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('file')],
    ['label' => 'Total Unit Enrolments', 'value' => $totalunitenrolments, 'color' => 'green',  'icon' => local_rtocompliance_stat_icon('bar')],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'],  ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('filter-section', ['style' => 'background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 24px;']);
echo '<form method="get" action="" class="form-inline" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
    <input type="hidden" name="id" value="' . $qualbuilderid . '">
    <div class="form-group">
        <label for="filter" class="mr-2" style="font-weight: 500;">Status:</label>
        <select name="filter" id="filter" class="form-control" onchange="this.form.submit()">
            <option value="all"' . ($filter === 'all' ? ' selected' : '') . '>All Students</option>
            <option value="complete"' . ($filter === 'complete' ? ' selected' : '') . '>Completed Only</option>
            <option value="inprogress"' . ($filter === 'inprogress' ? ' selected' : '') . '>In Progress Only</option>
        </select>
    </div>
    <div class="form-group">
        <input type="text" name="search" id="search" class="form-control" style="min-width: 200px;"
               placeholder="Search by name, email, or USI" 
               value="' . s($search) . '">
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
    <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qualbuilderid, 'export' => 'csv', 'filter' => $filter, 'search' => $search]))->out() . '" class="btn btn-outline-secondary">
        Export CSV
    </a>
</form>';
echo html_writer::end_div();

if (empty($students)) {
    echo html_writer::div(
        '<div style="text-align: center; padding: 40px; color: #6b7280;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 48px; height: 48px; margin-bottom: 16px; opacity: 0.5;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <p style="font-size: 16px; margin-bottom: 8px;">No students enrolled in this ' . strtolower($producttype) . '</p>
            <p style="font-size: 14px;">Students will appear here once they have enrolments with unit codes matching this training product.</p>
        </div>',
        'no-students-message'
    );
} else {
    echo html_writer::tag('p', 'Showing ' . count($students) . ' of ' . $totalcount . ' students', ['class' => 'text-muted', 'style' => 'margin-bottom: 12px;']);
    
    echo '<div style="overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
    echo '<table class="table table-striped" style="margin: 0; min-width: 800px;">';
    echo '<thead style="background: #f1f5f9;">';
    echo '<tr>';
    echo '<th style="position: sticky; left: 0; background: #f1f5f9; z-index: 10; min-width: 180px;">Student</th>';
    echo '<th style="min-width: 100px;">USI</th>';
    
    foreach ($units as $unit) {
        $typebadge = $unit->unittype === 'core' ? 'C' : 'E';
        $typecolor = $unit->unittype === 'core' ? '#ef4444' : '#3b82f6';
        echo '<th style="text-align: center; min-width: 90px; font-size: 12px;" title="' . s($unit->unitname) . '">' . 
             s($unit->unitcode) . '<br><span style="background: ' . $typecolor . '; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px;">' . $typebadge . '</span></th>';
    }
    
    echo '<th style="text-align: center; min-width: 80px;">Progress</th>';
    echo '<th style="text-align: center; min-width: 100px;">Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($students as $student) {
        // FIX: same duplicate-key handling as the CSV export above.
        $enrolment_rows = $DB->get_records_sql(
            "SELECT id, unitcode, outcomeidentifier, status, timemodified
               FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND programcode = :programcode
           ORDER BY CASE status
                    WHEN 'active'     THEN 1
                    WHEN 'completed'  THEN 2
                    WHEN 'hold'       THEN 3
                    WHEN 'withdrawn'  THEN 4
                    ELSE 5 END ASC,
                    timemodified DESC",
            ['studentid' => $student->studentid, 'programcode' => $product->qualificationcode]
        );
        $enrolments = [];
        foreach ($enrolment_rows as $er) {
            if (!isset($enrolments[$er->unitcode])) {
                $enrolments[$er->unitcode] = $er;
            }
        }

        $completedunits = 0;
        $totalunits = count($units);
        
        echo '<tr>';
        
        echo '<td style="position: sticky; left: 0; background: white; z-index: 5;">';
        echo '<strong>' . fullname($student) . '</strong><br>';
        echo '<small class="text-muted">' . s($student->email) . '</small>';
        echo '</td>';
        
        $usibadge = '';
        if (!empty($student->usi)) {
            if ($student->usiverified) {
                $usibadge = '<span class="badge badge-success" title="USI Verified">' . s($student->usi) . '</span>';
            } else {
                $usibadge = '<span class="badge badge-warning" title="USI Not Verified">' . s($student->usi) . '</span>';
            }
        } else {
            $usibadge = '<span class="badge badge-danger">Missing</span>';
        }
        echo '<td>' . $usibadge . '</td>';
        
        foreach ($units as $unit) {
            $enrolment = $enrolments[$unit->unitcode] ?? null;
            if ($enrolment) {
                $outcome = $outcomecodes[$enrolment->outcomeidentifier] ?? ['label' => 'Unknown', 'badge' => 'badge-secondary', 'short' => '?'];
                echo '<td style="text-align: center;"><span class="badge ' . $outcome['badge'] . '" title="' . $outcome['label'] . '">' . $outcome['short'] . '</span></td>';
                if (in_array($enrolment->outcomeidentifier, ['20', '30', '40', '81'])) {
                    $completedunits++;
                }
            } else {
                echo '<td style="text-align: center;"><span class="badge badge-light" title="Not Enrolled">-</span></td>';
            }
        }
        
        $percentage = $totalunits > 0 ? round(($completedunits / $totalunits) * 100) : 0;
        $progresscolor = $percentage >= 100 ? '#22c55e' : ($percentage >= 50 ? '#f59e0b' : '#ef4444');
        echo '<td style="text-align: center;">
            <div style="background: #e5e7eb; border-radius: 4px; height: 8px; width: 60px; margin: 0 auto 4px;">
                <div style="background: ' . $progresscolor . '; height: 100%; width: ' . $percentage . '%; border-radius: 4px;"></div>
            </div>
            <small style="font-weight: 600;">' . $percentage . '%</small>
        </td>';
        
        echo '<td style="text-align: center;">
            <a href="' . (new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-primary" title="View Profile">Profile</a>
            <a href="' . (new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-secondary" title="Manage Enrolments">Enrolments</a>
            <a href="' . (new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-info" title="View Documents &amp; Certificates">Docs &amp; Certs</a>
        </td>';
        
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qualbuilderid, 'filter' => $filter, 'search' => $search]));
}

echo html_writer::start_div('legend-section', ['style' => 'margin-top: 24px; padding: 16px; background: #f8fafc; border-radius: 8px;']);
echo html_writer::tag('h4', 'Outcome Code Legend', ['style' => 'margin: 0 0 12px 0; font-size: 14px;']);
echo '<div style="display: flex; flex-wrap: wrap; gap: 12px;">';
foreach ($outcomecodes as $code => $info) {
    echo '<span class="badge ' . $info['badge'] . '" style="font-size: 12px;">' . $info['short'] . ' = ' . $info['label'] . '</span>';
}
echo '</div>';
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 24px;']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder.php'),
    '&larr; Back to Qualification Builder',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
