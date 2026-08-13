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
 * RTO Compliance plugin — qualbuilder_results.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_results');
require_login();

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

// ROSTER-OVERHAUL (v5.9.373): the no-id landing is now a full cross-qualification
// "Master Student Roster" — the single table an RTO looks at first.  These extra
// filters drive it.  They are ignored by the per-product (id=N) drill-down below.
$rqual = optional_param('rqual', '', PARAM_RAW_TRIMMED);  // filter roster by a qualification code
$rcat  = optional_param('rcat', 0, PARAM_INT);            // filter roster by Moodle category (sub-category)
$rusi  = optional_param('rusi', 'all', PARAM_ALPHA);      // USI health filter: all|verified|unverified|missing
$rsort = optional_param('rsort', 'name', PARAM_ALPHA);    // name|progress|recent|units
// v6.2.84 CASCADE ROSTER FILTER — parent category -> sub-category -> course, sourced from
// the students' ACTUAL result courses (enrolments.courseid -> course -> course_categories),
// because most qualbuilder products have no Moodle categoryid so the old $rcat dropdown was
// nearly empty. These three narrow the roster (and every stat card, which reuses the same
// WHERE) to learners who hold a result in a matching course. Additive / narrowing only.
$rparent = optional_param('rparent', 0, PARAM_INT);       // parent category id
$rsub    = optional_param('rsub', 0, PARAM_INT);          // sub-category id
$rcourse = optional_param('rcourse', 0, PARAM_INT);       // specific course id

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
    // ==================================================================
    // MASTER STUDENT ROSTER (v5.9.373)
    // The single, cross-qualification table an RTO looks at first.  Reads
    // straight from the results register (local_rtocompliance_enrolments) —
    // the one source of truth — so both historical (NAT/Wisenet import) and
    // current (Moodle completion) results appear side by side.  The {user}
    // join is a LEFT join with a name fallback to the plugin's own student
    // record, so once historical students are decoupled from Moodle accounts
    // (Phase 2) they show here automatically with no further change.
    // ==================================================================
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_results.php'));
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('student_results', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('student_results', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
    $PAGE->navbar->add(get_string('student_results', 'local_rtocompliance'));
    $PAGE->requires->css('/local/rtocompliance/styles.css');

    // RECONCILE-COMPLETIONS (v5.9.374): on-demand sync of the results register
    // from Moodle course completions (any category, incl. archived / semester-copy
    // variants). POST-only + sesskey; the page capability check at the top of the
    // file already gates access. Writes ONLY to the plugin register.
    // RECONCILE-ACTION-PARAM FIX: the button POSTs action="reconcile_completions",
    // but reading it with PARAM_ALPHA strips the underscore ("reconcilecompletions"),
    // so the comparison never matched and the sync silently did nothing. PARAM_ALPHANUMEXT
    // preserves the underscore so the button actually triggers the reconcile.
    if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'reconcile_completions'
            && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
        // The scan streams all completions; give it room on large sites. It is
        // idempotent, so a re-run after a timeout is safe. Very large sites can
        // instead enable the nightly reconcile_completions scheduled task.
        \core_php_time_limit::raise(600);
        raise_memory_limit(MEMORY_HUGE);
        $rsum = \local_rtocompliance\completion_reconciler::reconcile();
        $msg = get_string('reconcile_completions_btn', 'local_rtocompliance') . ': '
            . $rsum['created'] . ' created, ' . $rsum['updated'] . ' updated, '
            . $rsum['already_competent'] . ' already competent, '
            . $rsum['manual_preserved'] . ' manual kept'
            . ($rsum['skipped_nostudent'] ? ', ' . $rsum['skipped_nostudent'] . ' with no student record' : '')
            . ($rsum['skipped_nomap'] ? ', ' . $rsum['skipped_nomap'] . ' unmapped courses' : '')
            . '.';
        redirect(new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
            $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // UNMAPPED-COMPLETIONS REVIEW EXPORT (v5.9.374): the exact courses the sync
    // could not resolve to a unit, ranked by how many students they block, so the
    // RTO can link/rename precisely those. Streamed before any header output.
    if ($export === 'unmapped') {
        \core_php_time_limit::raise(600);
        raise_memory_limit(MEMORY_HUGE);
        $urows = \local_rtocompliance\completion_reconciler::unmapped_completions();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="unmapped_completions_' . date('Y-m-d') . '.csv"');
        $uout = fopen('php://output', 'w');
        fputcsv($uout, ['Course ID', 'Course Shortname', 'Course Fullname', 'Category', 'Completions', 'Students Affected']);
        foreach ($urows as $ur) {
            fputcsv($uout, [$ur->courseid, $ur->shortname, $ur->fullname, $ur->category, $ur->completions, $ur->students]);
        }
        fclose($uout);
        exit;
    }

    // AVETMISS state + sex code maps for readable display.
    // AVETMISS-TERMINOLOGY (v6.2.9): official AVETMISS state-identifier labels. Codes 01–08
    // are the states/territories, 09 = "Other Australian territory". The AVETMISS not-stated
    // fills (@@, @, blank) and any legacy "99" placeholder are shown as "Not stated" rather
    // than leaking a raw code into the roster.
    $statelabels = [
        '01' => 'NSW', '02' => 'VIC', '03' => 'QLD', '04' => 'SA', '05' => 'WA',
        '06' => 'TAS', '07' => 'NT', '08' => 'ACT', '09' => 'Other Australian territory',
        '1'  => 'NSW', '2'  => 'VIC', '3'  => 'QLD', '4'  => 'SA', '5'  => 'WA',
        '6'  => 'TAS', '7'  => 'NT', '8'  => 'ACT', '9'  => 'Other Australian territory',
        '@@' => 'Not stated', '@' => 'Not stated', '99' => 'Not stated', '' => 'Not stated',
    ];
    // AVETMISS sex-identifier labels: M/F, X = "Other", @ (and blank) = "Not stated".
    $sexlabels = ['M' => 'Male', 'F' => 'Female', 'X' => 'Other', '@' => 'Not stated', '' => 'Not stated'];

    // "Competent / credit" AVETMISS outcome set — a unit genuinely achieved.
    $competentin = "('20','51','60','81')";

    // v6.2.84 CASCADE DATA — the category tree + course list built from the courses students
    // ACTUALLY hold results in (enrolments.courseid -> course -> course_categories). Mirrors the
    // SoA-issue cascade so both pages present the same parent/sub/course picker.
    $resCourses = [];   // courseid => ['name'=>.., 'catpath'=>.., 'catid'=>..]
    $resSubs    = [];   // subid    => ['name'=>.., 'parent'=>..]
    $resParents = [];   // parentid => name
    if ($DB->get_manager()->table_exists('course_categories')) {
        foreach ($DB->get_records_sql(
            "SELECT c.id, c.fullname, cc.id AS catid, cc.name AS catname, cc.parent AS catparent, cc.path AS catpath
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id IN (SELECT DISTINCT e.courseid FROM {local_rtocompliance_enrolments} e
                              WHERE e.courseid IS NOT NULL AND e.courseid > 0)
              ORDER BY cc.path, c.fullname") as $co) {
            $catid = (int)$co->catid;
            $resCourses[(int)$co->id] = ['name' => (string)$co->fullname, 'catpath' => (string)$co->catpath, 'catid' => $catid];
            $resSubs[$catid] = ['name' => (string)$co->catname, 'parent' => (int)$co->catparent];
        }
        $resParentIds = [];
        foreach ($resSubs as $sm) { if ((int)$sm['parent'] > 0) { $resParentIds[(int)$sm['parent']] = true; } }
        if (!empty($resParentIds)) {
            list($pin_a, $pin_p) = $DB->get_in_or_equal(array_keys($resParentIds), SQL_PARAMS_NAMED, 'rpp');
            foreach ($DB->get_records_select('course_categories', "id $pin_a", $pin_p, '', 'id, name') as $pc) {
                $resParents[(int)$pc->id] = (string)$pc->name;
            }
        }
    }
    // Given the selected parent/sub/course, resolve the concrete set of result-course ids the
    // roster must be narrowed to. Course wins over sub wins over parent (most specific first).
    // A sub/parent matches its own courses AND any in descendant categories, via the path prefix.
    $cascadeActive = ($rcourse > 0 || $rsub > 0 || $rparent > 0);
    $matchCourseIds = [];
    if ($rcourse > 0) {
        if (isset($resCourses[$rcourse])) { $matchCourseIds[] = $rcourse; }
    } else if ($rsub > 0) {
        foreach ($resCourses as $cid => $cm) {
            if ($cm['catid'] === $rsub
                    || strpos('/' . trim($cm['catpath'], '/') . '/', '/' . $rsub . '/') !== false) {
                $matchCourseIds[] = $cid;
            }
        }
    } else if ($rparent > 0) {
        foreach ($resCourses as $cid => $cm) {
            if (strpos('/' . trim($cm['catpath'], '/') . '/', '/' . $rparent . '/') !== false) {
                $matchCourseIds[] = $cid;
            }
        }
    }

    // ---- Build the shared WHERE (filters) once; reused by count, data,
    //      summary panels and CSV export so every number is consistent.
    //      Parameterised by table alias + a param-name suffix so the same
    //      filter set can be embedded a second time (with distinct aliases
    //      and distinct placeholders) inside a status-restricting sub-select
    //      without any placeholder being reused within one query. ----
    $build_filters = function (string $sa, string $ua, string $ea, string $suffix)
            use ($DB, $search, $rqual, $rcat, $rusi, $cascadeActive, $matchCourseIds) {
        $w = [];
        $p = [];
        // v6.2.84 cascade: narrow to students who hold a result in a matching result-course.
        // If a cascade level is chosen but resolves to no courses, force an empty roster
        // rather than silently ignoring the filter.
        if ($cascadeActive) {
            if (empty($matchCourseIds)) {
                $w[] = '1 = 0';
            } else {
                $ph = [];
                $ci = 0;
                foreach ($matchCourseIds as $cid) {
                    $k = 'cid' . $ci . $suffix;
                    $ph[] = ':' . $k;
                    $p[$k] = (int)$cid;
                    $ci++;
                }
                $w[] = "EXISTS (SELECT 1 FROM {local_rtocompliance_enrolments} exf$suffix
                                 WHERE exf$suffix.studentid = $sa.id
                                   AND exf$suffix.courseid IN (" . implode(',', $ph) . "))";
            }
        }
        if ($search !== '') {
            $w[] = '(' . $DB->sql_like("$ua.firstname", ":rs1$suffix", false, false)
                . ' OR ' . $DB->sql_like("$ua.lastname", ":rs2$suffix", false, false)
                . ' OR ' . $DB->sql_like("$sa.firstname", ":rs3$suffix", false, false)
                . ' OR ' . $DB->sql_like("$sa.lastname", ":rs4$suffix", false, false)
                . ' OR ' . $DB->sql_like("$ua.email", ":rs5$suffix", false, false)
                . ' OR ' . $DB->sql_like("$sa.usi", ":rs6$suffix", false, false)
                . ' OR ' . $DB->sql_like("$sa.clientid", ":rs7$suffix", false, false) . ')';
            foreach (['rs1', 'rs2', 'rs3', 'rs4', 'rs5', 'rs6', 'rs7'] as $k) {
                $p[$k . $suffix] = '%' . $DB->sql_like_escape($search) . '%';
            }
        }
        if ($rqual !== '') {
            $w[] = "$ea.programcode = :rqual$suffix";
            $p["rqual$suffix"] = $rqual;
        }
        if ($rcat > 0) {
            $w[] = "$ea.programcode IN (SELECT qbx$suffix.qualificationcode
                                          FROM {local_rtocompliance_qualbuilder} qbx$suffix
                                         WHERE qbx$suffix.categoryid = :rcat$suffix)";
            $p["rcat$suffix"] = $rcat;
        }
        if ($rusi === 'verified') {
            $w[] = "($sa.usi IS NOT NULL AND $sa.usi <> :usiempty$suffix AND $sa.usiverified = 1)";
            $p["usiempty$suffix"] = '';
        } else if ($rusi === 'unverified') {
            $w[] = "($sa.usi IS NOT NULL AND $sa.usi <> :usiempty$suffix AND $sa.usiverified = 0)";
            $p["usiempty$suffix"] = '';
        } else if ($rusi === 'missing') {
            $w[] = "($sa.usi IS NULL OR $sa.usi = :usiempty$suffix)";
            $p["usiempty$suffix"] = '';
        }
        return [empty($w) ? '' : (' WHERE ' . implode(' AND ', $w)), $p];
    };

    list($rwheresql, $rparams) = $build_filters('s', 'u', 'e', '');

    $rosterfrom = "FROM {local_rtocompliance_students} s
                   LEFT JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                   JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id";

    $attexpr  = "COUNT(DISTINCT e.unitcode)";
    $compexpr = "COUNT(DISTINCT CASE WHEN e.outcomeidentifier IN $competentin THEN e.unitcode ELSE NULL END)";

    // Status filter → HAVING on the per-student aggregates.
    $rhaving = '';
    if ($filter === 'complete') {
        $rhaving = " HAVING $attexpr > 0 AND $attexpr = $compexpr";
    } else if ($filter === 'inprogress') {
        $rhaving = " HAVING $attexpr > $compexpr";
    }

    // When a status filter is active, the row-grained volume stats and the
    // outcome-mix pivot must be restricted to the SAME students that pass the
    // per-student HAVING — otherwise they'd contradict the Students count.
    // Build that restriction with distinct aliases (s2/u2/e2) and a 'b' param
    // suffix so nothing is reused within a single query.
    $scopewhere = $rwheresql;
    $scopeparams = $rparams;
    if ($rhaving !== '') {
        list($subwhere, $subparams) = $build_filters('s2', 'u2', 'e2', 'b');
        $rhaving2 = str_replace('e.', 'e2.', $rhaving);
        $statusrestrict = " e.studentid IN (
            SELECT s2.id
              FROM {local_rtocompliance_students} s2
              LEFT JOIN {user} u2 ON u2.id = s2.userid AND u2.deleted = 0
              JOIN {local_rtocompliance_enrolments} e2 ON e2.studentid = s2.id
              $subwhere
          GROUP BY s2.id
              $rhaving2 )";
        $scopewhere  = ($rwheresql === '' ? ' WHERE ' : $rwheresql . ' AND ') . $statusrestrict;
        $scopeparams = $rparams + $subparams;
    }

    // ---- CSV export (full field set) ----
    if ($export === 'csv') {
        $csvsql = "SELECT s.id AS studentid, s.userid, s.clientid, s.usi, s.usiverified,
                          s.dateofbirth, s.sex, s.statecode,
                          COALESCE(NULLIF(u.firstname, ''), s.firstname) AS dfirst,
                          COALESCE(NULLIF(u.lastname, ''), s.lastname)  AS dlast,
                          u.email AS email,
                          $attexpr  AS unitsattempted,
                          $compexpr AS unitscompetent,
                          COUNT(DISTINCT e.programcode) AS programcount,
                          MAX(e.timemodified) AS lastactivity
                     $rosterfrom
                     $rwheresql
                 GROUP BY s.id, s.userid, s.clientid, s.usi, s.usiverified, s.dateofbirth, s.sex, s.statecode,
                          COALESCE(NULLIF(u.firstname, ''), s.firstname),
                          COALESCE(NULLIF(u.lastname, ''), s.lastname), u.email
                          $rhaving
                 ORDER BY dlast ASC, dfirst ASC";
        $csvrows = $DB->get_records_sql($csvsql, $rparams);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="student_roster_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student Name', 'Client ID', 'USI', 'USI Verified', 'Date of Birth', 'Sex',
                       'State', 'Qualifications', 'Units Attempted', 'Units Competent', 'Progress %', 'Status', 'Last Activity']);
        foreach ($csvrows as $r) {
            $name = trim(trim((string)$r->dfirst) . ' ' . trim((string)$r->dlast));
            if ($name === '') {
                $name = $r->clientid ? ('Client ' . $r->clientid) : ('Student #' . $r->studentid);
            }
            $progs = $DB->get_fieldset_sql(
                "SELECT DISTINCT programcode FROM {local_rtocompliance_enrolments}
                  WHERE studentid = :sid AND programcode IS NOT NULL AND programcode <> :pe
               ORDER BY programcode",
                ['sid' => $r->studentid, 'pe' => '']
            );
            $att  = (int)$r->unitsattempted;
            $comp = (int)$r->unitscompetent;
            $pct  = $att > 0 ? round($comp / $att * 100) : 0;
            fputcsv($out, [
                $name,
                (string)$r->clientid,
                (string)$r->usi,
                // USI-VERIFIED-ACCURACY (v6.2.8): only usiverified===1 (STATUS_VERIFIED, confirmed
                // against usi.gov.au) is a real "Yes". The previous truthy test wrongly reported
                // usiverified===3 (verification PENDING/stuck) as "Yes", overstating verified USIs
                // in AVETMISS/compliance reporting. Pending, failed and unset all correctly read "No".
                ((int)$r->usiverified === 1) ? 'Yes' : 'No',
                $r->dateofbirth ? date('d/m/Y', (int)$r->dateofbirth) : '',
                // AVETMISS-TERMINOLOGY (v6.2.9): fall back to "Not stated" for any null/blank
                // value so the roster never shows an empty cell for sex or state.
                ($r->sex === null || $r->sex === '') ? 'Not stated' : ($sexlabels[$r->sex] ?? (string)$r->sex),
                ($r->statecode === null || $r->statecode === '') ? 'Not stated' : ($statelabels[$r->statecode] ?? (string)$r->statecode),
                implode(' | ', $progs),
                $att,
                $comp,
                $pct . '%',
                ($att > 0 && $att === $comp) ? 'All competent' : 'In progress',
                $r->lastactivity ? date('d/m/Y', (int)$r->lastactivity) : '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ---- Count (respects filters + status HAVING) ----
    $rcountsql = "SELECT COUNT(*) FROM (
                    SELECT s.id
                    $rosterfrom
                    $rwheresql
                    GROUP BY s.id
                    $rhaving
                  ) subq";
    $rtotal = $DB->count_records_sql($rcountsql, $rparams);

    // ---- Sort ----
    switch ($rsort) {
        case 'progress': $orderby = "ORDER BY unitscompetent DESC, unitsattempted DESC"; break;
        case 'recent':   $orderby = "ORDER BY lastactivity DESC"; break;
        case 'units':    $orderby = "ORDER BY unitsattempted DESC"; break;
        default:         $orderby = "ORDER BY dlast ASC, dfirst ASC"; break;
    }

    // ---- Page of roster rows ----
    $rostersql = "SELECT s.id AS studentid, s.userid, s.clientid, s.usi, s.usiverified,
                         s.dateofbirth, s.sex, s.statecode,
                         COALESCE(NULLIF(u.firstname, ''), s.firstname) AS dfirst,
                         COALESCE(NULLIF(u.lastname, ''), s.lastname)  AS dlast,
                         u.email AS email,
                         $attexpr  AS unitsattempted,
                         $compexpr AS unitscompetent,
                         COUNT(DISTINCT e.programcode) AS programcount,
                         MAX(e.timemodified) AS lastactivity
                    $rosterfrom
                    $rwheresql
                GROUP BY s.id, s.userid, s.clientid, s.usi, s.usiverified, s.dateofbirth, s.sex, s.statecode,
                         COALESCE(NULLIF(u.firstname, ''), s.firstname),
                         COALESCE(NULLIF(u.lastname, ''), s.lastname), u.email
                         $rhaving
                    $orderby";
    $rosterrows = $DB->get_records_sql($rostersql, $rparams, $page * $perpage, $perpage);

    // Pre-load each rostered student's qualification codes (one query, keyed by studentid).
    $studentprograms = [];
    if (!empty($rosterrows)) {
        list($sidIn, $sidParams) = $DB->get_in_or_equal(array_keys($rosterrows), SQL_PARAMS_NAMED, 'sp');
        $sidParams['spempty'] = '';
        $progrows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('studentid', "'-'", 'programcode') . " AS ukey,
                    studentid, programcode
               FROM {local_rtocompliance_enrolments}
              WHERE studentid $sidIn AND programcode IS NOT NULL AND programcode <> :spempty
           GROUP BY studentid, programcode
           ORDER BY programcode",
            $sidParams
        );
        foreach ($progrows as $pr) {
            $studentprograms[$pr->studentid][] = $pr->programcode;
        }
    }

    // ---- Summary / pivot data (all respect the active filters) ----
    // Headline totals (status-consistent: $scopewhere folds in the status filter).
    $totalunitresults = (int)$DB->get_field_sql(
        "SELECT COUNT(*) $rosterfrom $scopewhere", $scopeparams);
    $totalcompetent = (int)$DB->get_field_sql(
        "SELECT COUNT(*) $rosterfrom $scopewhere "
        . ($scopewhere ? 'AND' : 'WHERE') . " e.outcomeidentifier IN $competentin", $scopeparams);

    // USI health across the (filtered) roster.
    $usihealth = $DB->get_record_sql(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN hasusi = 1 AND uv = 1 THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN hasusi = 1 AND uv <> 1 THEN 1 ELSE 0 END) AS unverified,
                SUM(CASE WHEN hasusi = 0 THEN 1 ELSE 0 END) AS missing
           FROM (
                SELECT s.id,
                       MAX(s.usiverified) AS uv,
                       MAX(CASE WHEN s.usi IS NOT NULL AND s.usi <> :uempty THEN 1 ELSE 0 END) AS hasusi
                  $rosterfrom
                  $rwheresql
              GROUP BY s.id
                  $rhaving
           ) sub",
        $rparams + ['uempty' => '']
    );

    // Outcome distribution (unit results) across the filtered roster.
    $outcomedist = $DB->get_records_sql(
        "SELECT e.outcomeidentifier AS oc, COUNT(*) AS cnt
           $rosterfrom
           $scopewhere
       GROUP BY e.outcomeidentifier
       ORDER BY cnt DESC",
        $scopeparams
    );

    // Students-per-qualification pivot (always global — this is the navigation map).
    $qualpivot = $DB->get_records_sql(
        "SELECT qb.id, qb.qualificationcode, qb.qualificationname, qb.producttype, qb.status, qb.categoryid,
                (SELECT COUNT(DISTINCT e.studentid)
                   FROM {local_rtocompliance_enrolments} e
                  WHERE e.programcode = qb.qualificationcode) AS studs
           FROM {local_rtocompliance_qualbuilder} qb
       ORDER BY studs DESC, qb.status ASC, qb.qualificationcode ASC"
    );

    // Dropdown data: qualifications + categories used.
    $qualoptions = $DB->get_records_sql(
        "SELECT id, qualificationcode, qualificationname FROM {local_rtocompliance_qualbuilder}
      ORDER BY qualificationcode ASC");
    $catoptions = [];
    if ($DB->get_manager()->table_exists('course_categories')) {
        $catoptions = $DB->get_records_sql(
            "SELECT DISTINCT cc.id, cc.name
               FROM {local_rtocompliance_qualbuilder} qb
               JOIN {course_categories} cc ON cc.id = qb.categoryid
              WHERE qb.categoryid IS NOT NULL AND qb.categoryid > 0
           ORDER BY cc.name ASC");
    }

    // AVETMISS outcome legend (shared with drill-down semantics).
    $outcomelegend = [
        '20' => ['Competent', 'badge-success'], '30' => ['Not Yet Competent', 'badge-danger'],
        '40' => ['Withdrawn', 'badge-warning'], '51' => ['RPL Granted', 'badge-success'],
        '52' => ['RPL Not Granted', 'badge-danger'], '60' => ['Credit Transfer', 'badge-success'],
        '70' => ['Continuing', 'badge-info'], '81' => ['Non-Assessed Satisfactory', 'badge-success'],
        '82' => ['Non-Assessed Unsatisfactory', 'badge-danger'], '85' => ['Not Yet Started', 'badge-secondary'],
        '00' => ['No Outcome', 'badge-secondary'],
    ];

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
    echo html_writer::tag('h2', 'Student Results');
    echo html_writer::end_div();

    // Plain-language "what is this" card.
    echo '<div style="background:linear-gradient(180deg,#eff6ff,#f8fafc);border:1px solid #bfdbfe;border-radius:10px;padding:16px 20px;margin-bottom:20px;">'
        . '<div style="font-weight:700;font-size:15px;color:#1e3a8a;margin-bottom:4px;">This is the master results table for every student.</div>'
        . '<div style="color:#334155;font-size:13.5px;line-height:1.55;">Every row is one student and pulls straight from the results register — '
        . 'both historical records imported from your old system and current Moodle completions, side by side. '
        . 'Use the filters to narrow by qualification, category, status or USI, then click a student to open their full profile, '
        . 'or open a qualification to see the unit-by-unit outcome grid. The qualification map on the right is the same link that '
        . 'connects each unit to its Moodle delivery course in the Qualification Builder.</div></div>';

    // ---- Headline stat cards ----
    $avgprogress = $totalunitresults > 0 ? round($totalcompetent / $totalunitresults * 100) : 0;
    echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom:20px;']);
    foreach ([
        ['label' => 'Students', 'value' => $rtotal, 'color' => 'blue', 'icon' => local_rtocompliance_stat_icon('users'), 'tip' => 'Number of learners shown in this roster after any filters.'],
        ['label' => 'Unit Results', 'value' => $totalunitresults, 'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('list'), 'tip' => 'Total individual unit outcomes recorded across all these learners.'],
        ['label' => 'Competent Results', 'value' => $totalcompetent, 'color' => 'green', 'icon' => local_rtocompliance_stat_icon('check'), 'tip' => 'Unit outcomes marked competent, including recognition of prior learning and credit transfer.'],
        ['label' => 'Competency Rate', 'value' => $avgprogress . '%', 'color' => $avgprogress >= 50 ? 'green' : 'amber', 'icon' => local_rtocompliance_stat_icon('percent'), 'tip' => 'Share of all recorded unit outcomes that are competent. Higher is better.'],
        ['label' => 'USI Verified', 'value' => (int)($usihealth->verified ?? 0), 'color' => 'green', 'icon' => local_rtocompliance_stat_icon('shield'), 'tip' => 'Learners whose USI (the national student ID number) has been confirmed against usi.gov.au.'],
        ['label' => 'USI Missing', 'value' => (int)($usihealth->missing ?? 0), 'color' => ((int)($usihealth->missing ?? 0) > 0) ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('alert'), 'tip' => 'Learners with no USI on file. Collect their USI before issuing certificates.'],
    ] as $sc) {
        echo html_writer::start_div('stat-card stat-' . $sc['color'], ['title' => $sc['tip']]);
        echo '<div class="stat-icon-wrap">' . $sc['icon'] . '</div>';
        echo html_writer::start_div('stat-info');
        echo html_writer::tag('span', $sc['value'], ['class' => 'stat-number']);
        echo html_writer::tag('span', $sc['label'], ['class' => 'stat-label']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // FULL-WIDTH-RESULTS (v6.2.43): stack (was a 2-column flex with a 300px right rail that
    // squeezed the table to a 820px min-width and forced a horizontal scrollbar). Now the table
    // gets the full width and the "Students by qualification" pivot sits full-width below it.
    echo '<div>';
    echo '<div style="width:100%;min-width:0;">';

    // Filter bar.
    // RESULTS-FILTER-BAR (v6.2.57): proper responsive filter bar. The old flex row collapsed
    // into a full-width vertical stack because Bootstrap's .form-control{width:100%} overrides
    // flex sizing. A CSS grid lays the filters out across the page (equal columns, wrapping on
    // narrow screens) with the search on top and the actions in their own row.
    echo html_writer::start_div('rtoc-results-filterbar');
    echo '<form method="get" action="" class="rtoc-rf-form">';
    echo '<input type="text" name="search" class="form-control rtoc-rf-search" placeholder="Search name, email, USI or client ID" value="' . s($search) . '">';
    echo '<div class="rtoc-rf-grid">';
    // Qualification filter.
    echo '<select name="rqual" class="form-control" onchange="this.form.submit()">';
    echo '<option value="">All qualifications</option>';
    foreach ($qualoptions as $qo) {
        $sel = ($rqual === $qo->qualificationcode) ? ' selected' : '';
        echo '<option value="' . s($qo->qualificationcode) . '"' . $sel . '>' . s($qo->qualificationcode) . ' ' . s(shorten_text($qo->qualificationname, 40)) . '</option>';
    }
    echo '</select>';
    // v6.2.84 CASCADE: Parent category -> Sub-category -> Course, sourced from the students'
    // actual result courses. Replaces the old near-empty $rcat dropdown (which read categoryid
    // off qualbuilder, populated on only a handful of products). All options are rendered with
    // data-parent / data-sub attributes; the small script below hides the ones that don't apply
    // to the current upstream selection and resets downstream picks when an upstream changes.
    if (!empty($resCourses)) {
        // Parent category.
        echo '<select id="rtoc-rf-parent" name="rparent" class="form-control" onchange="rtocRfCascade(this,\'parent\')" style="min-width:150px;">';
        echo '<option value="0">All categories</option>';
        foreach ($resParents as $pid => $pname) {
            echo '<option value="' . (int)$pid . '"' . ($rparent == $pid ? ' selected' : '') . '>'
                . s(shorten_text($pname, 30)) . '</option>';
        }
        echo '</select>';
        // Sub-category.
        echo '<select id="rtoc-rf-sub" name="rsub" class="form-control" onchange="rtocRfCascade(this,\'sub\')" style="min-width:150px;">';
        echo '<option value="0" data-parent="0">All sub-categories</option>';
        foreach ($resSubs as $sid => $sm) {
            echo '<option value="' . (int)$sid . '" data-parent="' . (int)$sm['parent'] . '"'
                . ($rsub == $sid ? ' selected' : '') . '>' . s(shorten_text($sm['name'], 30)) . '</option>';
        }
        echo '</select>';
        // Course.
        echo '<select id="rtoc-rf-course" name="rcourse" class="form-control" onchange="rtocRfCascade(this,\'course\')" style="min-width:170px;">';
        echo '<option value="0" data-sub="0" data-catpath="">All courses</option>';
        foreach ($resCourses as $coid => $cm) {
            echo '<option value="' . (int)$coid . '" data-sub="' . (int)$cm['catid'] . '"'
                . ' data-catpath="' . s($cm['catpath']) . '"'
                . ($rcourse == $coid ? ' selected' : '') . '>' . s(shorten_text($cm['name'], 40)) . '</option>';
        }
        echo '</select>';
    } else if (!empty($catoptions)) {
        // Fallback: no result courses carry a courseid — keep the legacy category dropdown.
        echo '<select name="rcat" class="form-control" onchange="this.form.submit()" style="min-width:150px;">';
        echo '<option value="0">All categories</option>';
        foreach ($catoptions as $co) {
            $sel = ($rcat == $co->id) ? ' selected' : '';
            echo '<option value="' . (int)$co->id . '"' . $sel . '>' . s(shorten_text($co->name, 30)) . '</option>';
        }
        echo '</select>';
    }
    // Status filter.
    echo '<select name="filter" class="form-control" onchange="this.form.submit()" style="min-width:140px;">';
    foreach (['all' => 'All statuses', 'complete' => 'All competent', 'inprogress' => 'In progress'] as $fv => $fl) {
        echo '<option value="' . $fv . '"' . ($filter === $fv ? ' selected' : '') . '>' . $fl . '</option>';
    }
    echo '</select>';
    // USI filter.
    echo '<select name="rusi" class="form-control" onchange="this.form.submit()" style="min-width:130px;">';
    foreach (['all' => 'Any USI', 'verified' => 'USI verified', 'unverified' => 'USI unverified', 'missing' => 'USI missing'] as $uv => $ul) {
        echo '<option value="' . $uv . '"' . ($rusi === $uv ? ' selected' : '') . '>' . $ul . '</option>';
    }
    echo '</select>';
    // Sort.
    echo '<select name="rsort" class="form-control" onchange="this.form.submit()" style="min-width:150px;">';
    foreach (['name' => 'Sort: Name', 'progress' => 'Sort: Most competent', 'recent' => 'Sort: Recent activity', 'units' => 'Sort: Most units'] as $sv => $sl) {
        echo '<option value="' . $sv . '"' . ($rsort === $sv ? ' selected' : '') . '>' . $sl . '</option>';
    }
    echo '</select>';
    echo '</div>'; // end rtoc-rf-grid
    echo '<div class="rtoc-rf-actions">';
    echo '<button type="submit" class="btn btn-primary">Apply</button>';
    $hasfilters = ($search !== '' || $rqual !== '' || $rcat > 0 || $rusi !== 'all' || $filter !== 'all'
        || $rsort !== 'name' || $rparent > 0 || $rsub > 0 || $rcourse > 0);
    if ($hasfilters) {
        echo ' <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php'))->out() . '" class="btn btn-outline-secondary">Clear</a>';
    }
    echo ' <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php',
        ['export' => 'csv', 'search' => $search, 'rqual' => $rqual, 'rcat' => $rcat, 'rusi' => $rusi, 'filter' => $filter,
         'rparent' => $rparent, 'rsub' => $rsub, 'rcourse' => $rcourse]))->out()
        . '" class="btn btn-outline-secondary">Export CSV</a>';
    echo '</div>'; // end rtoc-rf-actions
    echo '</form>';
    // v6.2.84 cascade behaviour: keep the sub/course dropdowns showing only options that belong
    // to the current upstream selection, and clear downstream picks when an upstream changes.
    // Runs on load (to apply the server-selected state) and on every change (then submits).
    echo <<<'RFCASCADE'
<script>
(function () {
  function opts(sel){ return Array.prototype.slice.call(sel ? sel.options : []); }
  function applyVisibility(){
    var p = document.getElementById('rtoc-rf-parent');
    var sub = document.getElementById('rtoc-rf-sub');
    var crs = document.getElementById('rtoc-rf-course');
    if (!sub || !crs) return;
    var pv = p ? p.value : '0';
    // Subs: show those whose data-parent matches the chosen parent (or the "all" row).
    opts(sub).forEach(function (o){
      var par = o.getAttribute('data-parent') || '0';
      var show = (o.value === '0') || (pv === '0') || (par === pv);
      o.hidden = !show; o.disabled = !show;
    });
    if (sub.selectedOptions.length && sub.selectedOptions[0].hidden) { sub.value = '0'; }
    var sv = sub.value;
    // Courses: show those whose data-sub matches the chosen sub; if no sub chosen, honour parent
    // by testing the sub's parent too via the sub dropdown lookup.
    var subParent = {};
    opts(sub).forEach(function (o){ subParent[o.value] = o.getAttribute('data-parent') || '0'; });
    opts(crs).forEach(function (o){
      var cs = o.getAttribute('data-sub') || '0';
      var show;
      if (o.value === '0') { show = true; }
      else if (sv !== '0') { show = (cs === sv); }
      else if (pv !== '0') { show = (subParent[cs] === pv); }
      else { show = true; }
      o.hidden = !show; o.disabled = !show;
    });
    if (crs.selectedOptions.length && crs.selectedOptions[0].hidden) { crs.value = '0'; }
  }
  window.rtocRfCascade = function (el, level){
    if (level === 'parent'){ var s=document.getElementById('rtoc-rf-sub'); var c=document.getElementById('rtoc-rf-course'); if(s)s.value='0'; if(c)c.value='0'; }
    if (level === 'sub'){ var c2=document.getElementById('rtoc-rf-course'); if(c2)c2.value='0'; }
    applyVisibility();
    if (el && el.form) { el.form.submit(); }
  };
  if (document.readyState !== 'loading') { applyVisibility(); }
  else { document.addEventListener('DOMContentLoaded', applyVisibility); }
})();
</script>
RFCASCADE;
    echo html_writer::end_div();

    echo html_writer::tag('p', 'Showing ' . count($rosterrows) . ' of ' . $rtotal . ' students', ['class' => 'text-muted', 'style' => 'margin-bottom:10px;']);

    if (empty($rosterrows)) {
        echo html_writer::div(
            '<div style="text-align:center;padding:40px;color:#6b7280;">'
            . '<p style="font-size:16px;margin-bottom:8px;">No students match these filters.</p>'
            . '<p style="font-size:14px;">Try clearing the filters, or import results in the Data Import tool. '
            . 'Students appear here as soon as they have any unit result in the register.</p></div>',
            'no-students-message'
        );
    } else {
        echo '<div style="overflow-x:auto;background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
        echo '<table class="table table-striped" style="margin:0;width:100%;">';
        echo '<thead style="background:#f1f5f9;"><tr>';
        echo '<th style="min-width:180px;" title="Learner name, with client ID and email underneath">Student</th>';
        echo '<th title="Unique Student Identifier and whether it is verified">USI</th><th title="Date of birth on record">DOB</th><th title="State or territory recorded for the learner">State</th>';
        echo '<th style="min-width:150px;" title="Qualification codes this learner has results against; click a code to filter">Qualification(s)</th>';
        echo '<th style="text-align:center;min-width:130px;" title="Units assessed as competent out of units attempted, shown as a progress bar">Competency</th>';
        echo '<th style="text-align:center;" title="Date of the most recent recorded result for this learner">Last activity</th>';
        echo '<th style="text-align:center;min-width:120px;" title="Open the full profile or the unit-by-unit results grid">Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ($rosterrows as $r) {
            $name = trim(trim((string)$r->dfirst) . ' ' . trim((string)$r->dlast));
            if ($name === '') {
                $name = $r->clientid ? ('Client ' . $r->clientid) : ('Student #' . $r->studentid);
            }
            // USI badge.
            if (!empty($r->usi)) {
                $usibadge = local_rtocompliance_usi_is_verified($r->usiverified)
                    ? '<span class="badge badge-success" title="USI verified">' . s($r->usi) . '</span>'
                    : '<span class="badge badge-warning" title="USI not verified">' . s($r->usi) . '</span>';
            } else {
                $usibadge = '<span class="badge badge-danger" title="No USI (national student ID number) on file. Collect it before issuing a certificate.">Missing</span>';
            }
            $dob   = $r->dateofbirth ? date('d/m/Y', (int)$r->dateofbirth) : '<span class="text-muted">—</span>';
            $state = $statelabels[$r->statecode] ?? ($r->statecode ? s($r->statecode) : '<span class="text-muted">—</span>');
            // Qualification chips.
            $progs = $studentprograms[$r->studentid] ?? [];
            $qchips = '';
            $shown = array_slice($progs, 0, 3);
            foreach ($shown as $pc) {
                $qchips .= '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['rqual' => $pc]))->out()
                    . '" class="badge badge-light" style="text-decoration:none;border:1px solid #e2e8f0;margin:1px;" title="Filter roster by ' . s($pc) . '">' . s($pc) . '</a> ';
            }
            if (count($progs) > 3) {
                $qchips .= '<span class="text-muted" style="font-size:11px;">+' . (count($progs) - 3) . ' more</span>';
            }
            if ($qchips === '') {
                $qchips = '<span class="text-muted">—</span>';
            }
            // Competency bar.
            $att  = (int)$r->unitsattempted;
            $comp = (int)$r->unitscompetent;
            $pct  = $att > 0 ? round($comp / $att * 100) : 0;
            $barcolor = $pct >= 100 ? '#22c55e' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
            $comptxt = '<div style="background:#e5e7eb;border-radius:4px;height:8px;width:90px;margin:0 auto 3px;">'
                . '<div style="background:' . $barcolor . ';height:100%;width:' . $pct . '%;border-radius:4px;"></div></div>'
                . '<small style="font-weight:600;">' . $comp . ' / ' . $att . ' units</small>';
            $last = $r->lastactivity ? userdate((int)$r->lastactivity, get_string('strftimedate', 'core_langconfig')) : '<span class="text-muted">—</span>';

            echo '<tr>';
            echo '<td><strong>' . s($name) . '</strong>'
                . ($r->clientid ? '<br><small class="text-muted">ID ' . s($r->clientid) . '</small>' : '')
                . ($r->email ? '<br><small class="text-muted">' . s($r->email) . '</small>' : '') . '</td>';
            echo '<td>' . $usibadge . '</td>';
            echo '<td style="white-space:nowrap;">' . $dob . '</td>';
            echo '<td>' . $state . '</td>';
            echo '<td>' . $qchips . '</td>';
            echo '<td style="text-align:center;">' . $comptxt . '</td>';
            echo '<td style="text-align:center;white-space:nowrap;font-size:12px;">' . $last . '</td>';
            $profileurl = $r->userid
                ? (new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $r->userid]))->out()
                : '';
            echo '<td style="text-align:center;white-space:nowrap;">';
            if ($profileurl) {
                echo '<a href="' . $profileurl . '" class="btn btn-sm btn-outline-primary" title="Full student profile">Profile</a> ';
            }
            if (!empty($progs)) {
                $firstqual = $DB->get_field('local_rtocompliance_qualbuilder', 'id', ['qualificationcode' => $progs[0]]);
                if ($firstqual) {
                    echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $firstqual]))->out()
                        . '" class="btn btn-sm btn-outline-secondary" title="Open the unit-by-unit grid for ' . s($progs[0]) . '">Units</a>';
                }
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo $OUTPUT->paging_bar($rtotal, $page, $perpage,
            new moodle_url('/local/rtocompliance/qualbuilder_results.php',
                ['search' => $search, 'rqual' => $rqual, 'rcat' => $rcat, 'rusi' => $rusi, 'filter' => $filter, 'rsort' => $rsort]));
    }

    echo '</div>'; // left column

    // ---- Right column: pivots ----
    echo '<div style="width:100%;margin-top:16px;">';

    // Qualification pivot (the navigation map, tied to Qual Builder / Course Map).
    echo '<div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">';
    echo '<div style="font-weight:700;font-size:14.5px;color:#334155;margin-bottom:8px;">Students by qualification</div>';
    if (empty($qualpivot)) {
        echo '<div class="text-muted" style="font-size:12px;">No qualifications built yet. <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out() . '">Open Qualification Builder</a>.</div>';
    } else {
        $maxstuds = 1;
        foreach ($qualpivot as $qp) { $maxstuds = max($maxstuds, (int)$qp->studs); }
        $shownpivot = array_slice($qualpivot, 0, 12);
        foreach ($shownpivot as $qp) {
            $w = round((int)$qp->studs / $maxstuds * 100);
            $statuscolor = ($qp->status === 'active') ? '#22c55e' : (($qp->status === 'draft') ? '#94a3b8' : '#f59e0b');
            echo '<div style="margin-bottom:8px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:2px;">';
            echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qp->id]))->out()
                . '" style="font-weight:600;text-decoration:none;color:#1d4ed8;" title="' . s($qp->qualificationname) . '">'
                . s($qp->qualificationcode) . '</a>';
            echo '<span style="color:#64748b;">' . (int)$qp->studs . '</span>';
            echo '</div>';
            echo '<div style="background:#eef2f7;border-radius:3px;height:6px;overflow:hidden;">'
                . '<div style="background:' . $statuscolor . ';height:100%;width:' . max($w, 3) . '%;"></div></div>';
            echo '</div>';
        }
        if (count($qualpivot) > 12) {
            echo '<div class="text-muted" style="font-size:11px;margin-top:4px;">+' . (count($qualpivot) - 12) . ' more qualifications</div>';
        }
    }
    echo '</div>';

    // USI health pivot.
    $uh_total = (int)($usihealth->total ?? 0);
    echo '<div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">';
    echo '<div style="font-weight:700;font-size:14.5px;color:#334155;margin-bottom:8px;">USI health</div>';
    foreach ([
        ['Verified', (int)($usihealth->verified ?? 0), '#22c55e', 'verified'],
        ['Unverified', (int)($usihealth->unverified ?? 0), '#f59e0b', 'unverified'],
        ['Missing', (int)($usihealth->missing ?? 0), '#ef4444', 'missing'],
    ] as $uh) {
        $w = $uh_total > 0 ? round($uh[1] / $uh_total * 100) : 0;
        echo '<div style="margin-bottom:8px;">';
        echo '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px;">';
        echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['rusi' => $uh[3]]))->out()
            . '" style="text-decoration:none;color:#334155;">' . $uh[0] . '</a>';
        echo '<span style="color:#64748b;">' . $uh[1] . ' (' . $w . '%)</span>';
        echo '</div>';
        echo '<div style="background:#eef2f7;border-radius:3px;height:6px;overflow:hidden;">'
            . '<div style="background:' . $uh[2] . ';height:100%;width:' . $w . '%;"></div></div>';
        echo '</div>';
    }
    echo '</div>';

    // Outcome distribution pivot.
    echo '<div style="background:white;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">';
    echo '<div style="font-weight:700;font-size:14.5px;color:#334155;margin-bottom:8px;">Outcome mix (unit results)</div>';
    if (empty($outcomedist)) {
        echo '<div class="text-muted" style="font-size:12px;">No results yet.</div>';
    } else {
        $maxoc = 1;
        foreach ($outcomedist as $od) { $maxoc = max($maxoc, (int)$od->cnt); }
        foreach ($outcomedist as $od) {
            $meta = $outcomelegend[$od->oc] ?? [$od->oc, 'badge-secondary'];
            $w = round((int)$od->cnt / $maxoc * 100);
            echo '<div style="margin-bottom:7px;">';
            echo '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px;">';
            echo '<span><span class="badge ' . $meta[1] . '" title="A national reporting (AVETMISS) unit outcome. The number beside it is how many unit results have this outcome." style="font-size:10px;">' . s($meta[0]) . '</span></span>';
            echo '<span style="color:#64748b;">' . (int)$od->cnt . '</span>';
            echo '</div>';
            echo '<div style="background:#eef2f7;border-radius:3px;height:6px;overflow:hidden;">'
                . '<div style="background:#64748b;height:100%;width:' . max($w, 3) . '%;"></div></div>';
            echo '</div>';
        }
    }
    echo '</div>';

    // Quick links — tie the components together.
    echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;">';
    echo '<div style="font-weight:700;font-size:14.5px;color:#334155;margin-bottom:8px;">Jump to</div>';
    echo '<div style="display:flex;flex-direction:column;gap:6px;">';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out() . '" class="btn btn-sm btn-outline-primary" title="Build and edit qualifications, skill sets and units">Qualification Builder</a>';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/course_map.php'))->out() . '" class="btn btn-sm btn-outline-secondary" title="View and confirm how units map to Moodle delivery courses">Course Map</a>';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out() . '" class="btn btn-sm btn-outline-secondary" title="Import historical results and NAT files from your old system">Data Import</a>';
    echo '</div></div>';

    // Sync-from-completions action card (v5.9.374).
    echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px;margin-top:16px;">';
    echo '<div style="font-weight:700;font-size:13px;color:#166534;margin-bottom:6px;">Pull in Moodle completions</div>';
    echo '<div style="font-size:11.5px;color:#3f6212;line-height:1.5;margin-bottom:10px;">'
        . s(get_string('reconcile_completions_help', 'local_rtocompliance')) . '</div>';
    echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php'))->out() . '" '
        . 'onsubmit="return confirm(\'Sync the results register from all Moodle course completions now? This only writes to the plugin\\\'s own results table — it never changes Moodle accounts, enrolments or completions.\');">';
    echo '<input type="hidden" name="action" value="reconcile_completions">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<button type="submit" class="btn btn-sm btn-success" style="width:100%;" title="Scan Moodle course completions and add matching results into the plugin results register">'
        . s(get_string('reconcile_completions_btn', 'local_rtocompliance')) . '</button>';
    echo '</form>';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['export' => 'unmapped']))->out()
        . '" class="btn btn-sm btn-outline-secondary" style="width:100%;margin-top:8px;" '
        . 'title="Download the courses the sync could not match to a unit — link or rename these in the Qualification Builder / Course Map">'
        . 'Download unmapped completions (CSV)</a>';
    echo '</div>';

    echo '</div>'; // right column
    echo '</div>'; // flex wrapper

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
// v5.9.375: uppercased set of THIS product's selected unit codes, used to scope
// the per-student outcome grid by unit (see the grid lookup below).
$productunitcodes = array_values(array_filter(array_map(function ($uc) {
    return strtoupper(trim((string)$uc));
}, $unitcodes), function ($uc) {
    return $uc !== '';
}));

// CROSS-CATEGORY-FIX (v5.9.373): the student LIST query below admits students
// who completed this product via a variant delivery course (any category) using
// `e.courseid IN (primary qualunits.courseid UNION qualunit_courses.courseid)`.
// But the per-student outcome grid used to re-filter strictly on
// `programcode = :programcode`, so a student pulled in by the courseid UNION —
// whose register rows carry a blank or different programcode because the unit
// was delivered from another Moodle category — showed every unit as "-" / 0%.
// We pre-compute the full set of delivery course IDs for this product (primary
// + active, non-archive variants, regardless of category) so the per-student
// lookup can match `programcode = :programcode OR courseid IN (...)` and the
// cross-category competencies actually render.
$productcourseids = $DB->get_fieldset_sql(
    "SELECT DISTINCT qu.courseid
       FROM {local_rtocompliance_qualunits} qu
      WHERE qu.qualbuilderid = :qbidpc
        AND qu.courseid IS NOT NULL
        AND qu.selected = 1
      UNION
     SELECT DISTINCT quc.courseid
       FROM {local_rtocompliance_qualunit_courses} quc
       JOIN {local_rtocompliance_qualunits} qu2 ON qu2.id = quc.qualunitid
      WHERE qu2.qualbuilderid = :qbidpc2
        AND quc.courseid IS NOT NULL",
    ['qbidpc' => $qualbuilderid, 'qbidpc2' => $qualbuilderid]
);
$productcourseids = array_values(array_filter(array_map('intval', $productcourseids)));

// TASK-72 (v5.9.356): Per-unit course discovery counts from the course_map table.
// Builds a map of unitcode → ['total', 'auto', 'qb', 'manual'] so unit column
// headers can show how many courses were found and whether they are QB-linked,
// auto-discovered, or manually added.  Units with zero map entries are highlighted
// so admins know completion falls back to raw enrolment outcome matching.
$unitMapCounts       = [];  // uc → ['total'=>int,'auto'=>int,'qb'=>int,'manual'=>int]
$mapTableExistsForResults = $DB->get_manager()->table_exists('local_rtocompliance_course_map');
if ($mapTableExistsForResults && !empty($unitcodes)) {
    list($ucInsql, $ucInparams) = $DB->get_in_or_equal($unitcodes, SQL_PARAMS_NAMED, 'uc');
    $ucInparams['mapqcode'] = strtoupper(trim($product->qualificationcode));
    $mapRows = $DB->get_records_sql(
        "SELECT unitcode, source, COUNT(*) AS cnt
           FROM {local_rtocompliance_course_map}
          WHERE qualcode = :mapqcode
            AND unitcode $ucInsql
          GROUP BY unitcode, source",
        $ucInparams
    );
    foreach ($mapRows as $mr) {
        $uc = strtoupper($mr->unitcode);
        if (!isset($unitMapCounts[$uc])) {
            $unitMapCounts[$uc] = ['total' => 0, 'auto' => 0, 'qb' => 0, 'manual' => 0];
        }
        $cnt = (int)$mr->cnt;
        $unitMapCounts[$uc]['total'] += $cnt;
        $src = $mr->source ?? 'auto';
        if (array_key_exists($src, $unitMapCounts[$uc])) {
            $unitMapCounts[$uc][$src] += $cnt;
        }
    }
}

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
    // v5.9.440: an enrolment that exists but has no recorded AVETMISS outcome (blank / NULL /
    // '00') is "not yet assessed" — it must render as a clear dash, NOT a confusing "?".
    '00' => ['label' => 'Not yet assessed',               'badge' => 'badge-light',     'short' => '—'],
];

// Match students who either:
//   (a) have an enrolment record with programcode set to this qualification, OR
//   (b) are enrolled in any Moodle course that is a primary delivery course for this QB, OR
//   (c) are enrolled in any variant delivery course for this QB (qualunit_courses).
// RESULTS-VARIANT-STUDENT-FIX (v5.9.295): added UNION with qualunit_courses so that
// students who completed via a variant course but whose enrolment was created before
// programcode auto-detection are not silently excluded from the results page.
$sql = "SELECT DISTINCT s.id as studentid, s.userid, s.usi, s.usiverified,
               s.clientid, s.dateofbirth, s.statecode,
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

// RPL-CT-FIX (v5.9.331): Added '51' (RPL) and '60' (Credit Transfer) to all
// outcome-completion checks. Previously both were absent — a student who completed
// every unit via RPL or Credit Transfer would not appear in the "Completed" filter
// and their units would not count toward the completion percentage. '30' (NYC) and
// '40' (Withdrawn) are kept because they represent a FINAL outcome — from an AVETMISS
// perspective the assessment cycle for that enrolment is closed (even if the result
// was not competent). The filter therefore means "all units have a resolved outcome",
// not "all units are competent" — cert generation uses ('20','51','60','81') for the
// competent-only check.
if ($filter === 'complete') {
    $sql .= " AND NOT EXISTS (
        SELECT 1 FROM {local_rtocompliance_qualunits} qu
        WHERE qu.qualbuilderid = :qbid1
        AND NOT EXISTS (
            SELECT 1 FROM {local_rtocompliance_enrolments} e2
            WHERE e2.studentid = s.id
            AND e2.unitcode = qu.unitcode
            AND e2.outcomeidentifier IN ('20', '51', '60', '81')
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
            AND e2.outcomeidentifier IN ('20', '51', '60', '81')
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
        // CROSS-CATEGORY-FIX (v5.9.373 / v5.9.375): the grid shows THIS product's
        // units, so match every register row for the student whose unit code is
        // one of this product's selected units — regardless of programcode or
        // which (archived / cross-category / reconciler-created) course delivered
        // it. This keeps the grid consistent with the unit-scoped "complete"
        // filter, the Master Roster headline and autocert, so a synced completion
        // never shows "-" here while counting as complete elsewhere.
        $cparams = ['studentid' => $student->studentid];
        $unitclause = '1 = 0';
        if (!empty($productunitcodes)) {
            list($gucin, $gucinparams) = $DB->get_in_or_equal($productunitcodes, SQL_PARAMS_NAMED, 'gu');
            $unitclause = "UPPER(unitcode) $gucin";
            $cparams += $gucinparams;
        }
        $enrolment_rows = $DB->get_records_sql(
            "SELECT id, unitcode, outcomeidentifier, status, timemodified
               FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND ($unitclause)
           ORDER BY CASE status
                    WHEN 'active'     THEN 1
                    WHEN 'completed'  THEN 2
                    WHEN 'hold'       THEN 3
                    WHEN 'withdrawn'  THEN 4
                    ELSE 5 END ASC,
                    timemodified DESC",
            $cparams
        );
        // Key by unitcode; first-wins preserves the priority ordering above.
        $enrolments = [];
        foreach ($enrolment_rows as $er) {
            $erkey = strtoupper(trim((string)$er->unitcode));
            if (!isset($enrolments[$erkey])) {
                $enrolments[$erkey] = $er;
            }
        }

        $completedunits = 0;
        $totalunits = count($units);
        
        $row = [
            fullname($student),
            $student->email,
            $student->usi ?? '',
            // USI-VERIFIED-ACCURACY (v6.2.8): only STATUS_VERIFIED (1) is a real "Yes".
            local_rtocompliance_usi_is_verified($student->usiverified) ? 'Yes' : 'No'
        ];
        
        foreach ($units as $unit) {
            $enrolment = $enrolments[strtoupper(trim((string)$unit->unitcode))] ?? null;
            if ($enrolment) {
                // v5.9.440: blank/NULL outcome exports as the "Not yet assessed" dash, not empty.
                $ocode = trim((string)($enrolment->outcomeidentifier ?? ''));
                if ($ocode === '') {
                    $ocode = '00';
                }
                $code = $outcomecodes[$ocode]['short'] ?? $ocode;
                $row[] = $code;
                // RPL-CT-FIX (v5.9.331): '51' RPL and '60' CT now count toward completion %.
                if (in_array($enrolment->outcomeidentifier, ['20', '51', '60', '81'])) {
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
echo html_writer::tag('p', $producttype . ': <strong>' . s($product->qualificationcode) . ' ' . s($product->qualificationname) . '</strong>', ['class' => 'text-muted', 'style' => 'margin: 0 0 0.5rem;']);
// v5.9.373: link back up to the cross-qualification Master Roster.
echo '<p style="margin:0 0 1rem;"><a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php'))->out()
    . '" style="font-size:13px;text-decoration:none;">&larr; All students (Master Roster)</a></p>';

// Plain-English explainer card for the unit-by-unit results grid.
echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;">'
    . '<div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">About this results grid</div>'
    . '<div style="font-size:14.5px;color:#334155;line-height:1.55;margin-bottom:8px;">Every row is one learner enrolled in this training product. Each unit has its own column showing that learner outcome for the unit, so you can see progress across the whole qualification at a glance. Hover a unit column heading to see the full unit name. Here is what each column means:</div>'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;">'
    . '<div><strong>Student</strong> &mdash; the learner name and email.</div>'
    . '<div><strong>USI</strong> &mdash; the Unique Student Identifier and whether it is verified.</div>'
    . '<div><strong>Unit columns</strong> &mdash; the AVETMISS outcome for each unit; the C or E badge marks a core or elective unit.</div>'
    . '<div><strong>Progress</strong> &mdash; the share of this product units the learner has completed.</div>'
    . '<div><strong>Actions</strong> &mdash; issue or view certificates and open the learner profile.</div>'
    . '</div></div>';

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
                                            AND e2.outcomeidentifier IN ('20', '51', '60', '81')
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
// DECLUTTER (v5.9.420): cut from 8 stat tiles to the 4 that inform a roster decision
// (Enrolled / Completed / In Progress / Completion Rate). The four static
// qualification-structure counts (Units in Product, Core Units, Elective Units, Total
// Unit Enrolments) don't change per cohort and belong in the Qualification Builder
// detail, not on the results roster.
foreach ([
    ['label' => 'Total Enrolled',        'value' => $totalstudents,       'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('users'), 'tip' => 'Learners enrolled in this qualification.'],
    ['label' => 'Completed',             'value' => $completedstudents,   'color' => 'green',  'icon' => local_rtocompliance_stat_icon('check'), 'tip' => 'Learners who are competent in every unit of this qualification and are ready for a certificate.'],
    ['label' => 'In Progress',           'value' => $inprogressstudents,  'color' => $inprogressstudents > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock'), 'tip' => 'Learners who have started but not yet finished every unit.'],
    ['label' => 'Completion Rate',       'value' => $completionrate . '%','color' => $completionrate >= 50 ? 'green' : 'amber', 'icon' => local_rtocompliance_stat_icon('percent'), 'tip' => 'Share of enrolled learners who have finished the whole qualification. Higher is better.'],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color'], ['title' => $s['tip']]);
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

// TASK-72 (v5.9.356): Course discovery summary panel.
// Shows which units have no mapped courses so admins can catch gaps before
// they affect certificate issuance, without having to scroll through the table.
if ($mapTableExistsForResults && !empty($units)) {
    $zeroUnits    = [];  // unit objects with no map entries
    $mappedTotal  = 0;
    foreach ($units as $unit) {
        $uc      = strtoupper($unit->unitcode);
        $mapInfo = $unitMapCounts[$uc] ?? null;
        if (!$mapInfo || $mapInfo['total'] === 0) {
            $zeroUnits[] = $unit;
        } else {
            $mappedTotal++;
        }
    }
    $totalUnitsCount = count($units);
    $mapUrl = new moodle_url('/local/rtocompliance/course_map.php', ['filterq' => strtoupper(trim($product->qualificationcode))]);

    if (!empty($zeroUnits)) {
        // At least one unit has no map entry — show amber warning panel.
        echo '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;margin-bottom:16px;">';
        echo '<strong style="color:#92400e;">&#9888; Course Coverage Gap</strong> &nbsp;';
        echo '<span style="color:#78350f;font-size:0.9em;">'
            . $mappedTotal . ' of ' . $totalUnitsCount . ' unit(s) have mapped courses; '
            . count($zeroUnits) . ' unit(s) have <strong>no mapped courses</strong> — '
            . 'completion detection for those units relies on enrolment outcome records only.</span>';
        echo '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">';
        foreach ($zeroUnits as $zu) {
            echo '<span style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;'
                . 'padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;" '
                . 'title="' . htmlspecialchars((string)$zu->unitname, ENT_QUOTES) . '">'
                . htmlspecialchars((string)$zu->unitcode, ENT_QUOTES) . '</span>';
        }
        echo '</div>';
        echo '<div style="margin-top:8px;">';
        echo '<a href="' . $mapUrl->out(false) . '" class="btn btn-xs btn-warning btn-sm" style="font-size:12px;" title="Open the Course Map filtered to this qualification to add the missing unit-to-course mappings">'
            . '&#9654; Open Course Map for ' . htmlspecialchars(strtoupper(trim($product->qualificationcode)), ENT_QUOTES)
            . '</a>';
        echo '</div>';
        echo '</div>';
    } else {
        // All units have at least one mapped course — show a green summary.
        echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 16px;margin-bottom:16px;font-size:0.9em;color:#166534;">';
        echo '&#10003; All ' . $totalUnitsCount . ' unit(s) have at least one mapped course. ';
        echo '<a href="' . $mapUrl->out(false) . '" style="color:#15803d;">Review on Course Map page &#8599;</a>';
        echo '</div>';
    }
}

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
    // TASK-45 (v5.9.345): Pre-load existing issued certs for all students on this
    // page so the Actions column can show cert numbers without N+1 queries.
    // Keyed as [userid][certtype] = certnumber.
    $existingcertsmap = [];
    if (!empty($students)) {
        $pageUserids = array_column(array_values($students), 'userid');
        list($certInsql, $certInparams) = $DB->get_in_or_equal($pageUserids, SQL_PARAMS_NAMED, 'cu');
        $certInparams['certqcode'] = $product->qualificationcode;
        $issuedcerts = $DB->get_records_sql(
            "SELECT id, userid, certtype, certnumber, issuedate
               FROM {local_rtocompliance_certs}
              WHERE userid $certInsql
                AND qualificationcode = :certqcode
                AND status            = 'issued'
                AND (reissued_at IS NULL OR reissued_at = 0)",
            $certInparams
        );
        foreach ($issuedcerts as $ic) {
            $existingcertsmap[$ic->userid][$ic->certtype] = $ic;
        }
    }

    // Determine which cert types this product requires.
    $isqualification = ($product->producttype === 'qualification');
    $requiredcerttypes = $isqualification ? ['testamur', 'record'] : ['statement'];

    echo html_writer::tag('p', 'Showing ' . count($students) . ' of ' . $totalcount . ' students', ['class' => 'text-muted', 'style' => 'margin-bottom: 12px;']);

    // "Issue All Pending" bulk action button — shown when filter is complete or all.
    if ($filter !== 'inprogress') {
        echo '<div id="rto-bulk-cert-bar" style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">';
        echo '<button id="rto-issue-all-btn" class="btn btn-success btn-sm"
                      onclick="rtoIssueAllPending()"
                      title="Issue certificates for every complete student on this page who does not yet have one">
                  &#9654; Issue All Pending on This Page
              </button>';
        echo '<span id="rto-bulk-status" style="font-size:13px; color:#6b7280;"></span>';
        echo '</div>';
    }

    echo '<div style="overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
    echo '<table class="table table-striped" style="margin: 0; min-width: 800px;">';
    echo '<thead style="background: #f1f5f9;">';
    echo '<tr>';
    echo '<th style="position: sticky; left: 0; background: #f1f5f9; z-index: 10; min-width: 180px;" title="Learner name and email">Student</th>';
    echo '<th style="min-width: 100px;" title="Unique Student Identifier and whether it is verified">USI</th>';
    
    foreach ($units as $unit) {
        $typebadge = $unit->unittype === 'core' ? 'C' : 'E';
        $typecolor = $unit->unittype === 'core' ? '#ef4444' : '#3b82f6';
        $typetip   = $unit->unittype === 'core'
            ? 'Core unit: a required unit that every learner must complete for this qualification.'
            : 'Elective unit: an optional unit chosen to help make up this qualification.';

        // TASK-72 (v5.9.356): Course discovery badge per unit header.
        // Green = courses found (shows breakdown); amber = zero map entries.
        $uc = strtoupper($unit->unitcode);
        $mapInfo = $unitMapCounts[$uc] ?? null;
        $discoveryHtml = '';
        $headerExtra   = '';
        if ($mapTableExistsForResults) {
            if (!$mapInfo || $mapInfo['total'] === 0) {
                // No courses in the map for this unit — warn admins.
                $discoveryHtml = '<br><span style="background:#fef3c7;color:#92400e;padding:1px 4px;'
                    . 'border-radius:3px;font-size:9px;font-weight:600;" '
                    . 'title="No courses mapped for this unit — completion detection uses enrolment outcome records only. '
                    . 'Click Map Coverage on the Qual Builder list to add a mapping.">'
                    . '&#9888; 0 courses</span>';
                $headerExtra = ' background:#fffbeb;';
            } else {
                $parts = [];
                if ($mapInfo['qb'] > 0)     $parts[] = $mapInfo['qb']     . ' QB-linked';
                if ($mapInfo['auto'] > 0)   $parts[] = $mapInfo['auto']   . ' auto';
                if ($mapInfo['manual'] > 0) $parts[] = $mapInfo['manual'] . ' manual';
                $breakdown = implode(', ', $parts);
                $tipText = $mapInfo['total'] . ' course(s) found: ' . $breakdown
                    . '. QB-linked = set in Qual Builder editor; auto = detected from category tree; manual = added on Course Map page.';
                $label = $mapInfo['total'] . ' course' . ($mapInfo['total'] !== 1 ? 's' : '');
                $discoveryHtml = '<br><span style="background:#d1fae5;color:#065f46;padding:1px 4px;'
                    . 'border-radius:3px;font-size:9px;" '
                    . 'title="' . htmlspecialchars($tipText, ENT_QUOTES) . '">'
                    . $label . '</span>';
            }
        }

        echo '<th style="text-align: center; min-width: 90px; font-size: 12px;' . $headerExtra . '" title="' . s($unit->unitname) . '">'
             . s($unit->unitcode) . '<br>'
             . '<span title="' . s($typetip) . '" style="background: ' . $typecolor . '; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px;">' . $typebadge . '</span>'
             . $discoveryHtml
             . '</th>';
    }
    
    echo '<th style="text-align: center; min-width: 80px;" title="Share of this product units the learner has completed">Progress</th>';
    echo '<th style="text-align: center; min-width: 100px;" title="Issue or view certificates and open the learner profile">Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($students as $student) {
        // FIX: same duplicate-key handling as the CSV export above.
        // CROSS-CATEGORY-FIX (v5.9.373 / v5.9.375): the grid shows THIS product's
        // units, so match every register row for the student whose unit code is
        // one of this product's selected units — regardless of programcode or
        // which (archived / cross-category / reconciler-created) course delivered
        // it. This keeps the grid consistent with the unit-scoped "complete"
        // filter, the Master Roster headline and autocert, so a synced completion
        // never shows "-" here while counting as complete elsewhere.
        $cparams = ['studentid' => $student->studentid];
        $unitclause = '1 = 0';
        if (!empty($productunitcodes)) {
            list($gucin, $gucinparams) = $DB->get_in_or_equal($productunitcodes, SQL_PARAMS_NAMED, 'gu');
            $unitclause = "UPPER(unitcode) $gucin";
            $cparams += $gucinparams;
        }
        $enrolment_rows = $DB->get_records_sql(
            "SELECT id, unitcode, outcomeidentifier, status, timemodified
               FROM {local_rtocompliance_enrolments}
              WHERE studentid = :studentid AND ($unitclause)
           ORDER BY CASE status
                    WHEN 'active'     THEN 1
                    WHEN 'completed'  THEN 2
                    WHEN 'hold'       THEN 3
                    WHEN 'withdrawn'  THEN 4
                    ELSE 5 END ASC,
                    timemodified DESC",
            $cparams
        );
        $enrolments = [];
        foreach ($enrolment_rows as $er) {
            $erkey = strtoupper(trim((string)$er->unitcode));
            if (!isset($enrolments[$erkey])) {
                $enrolments[$erkey] = $er;
            }
        }

        $completedunits = 0;
        $totalunits = count($units);
        // TASK-89 (v5.9.361): Collect units with no resolved final outcome so the
        // Progress cell can show an expandable "X units still needed" callout for
        // in-progress students without any extra database queries.
        $missingunits = [];

        echo '<tr>';
        
        echo '<td style="position: sticky; left: 0; background: white; z-index: 5;">';
        echo '<strong>' . fullname($student) . '</strong><br>';
        echo '<small class="text-muted">' . s($student->email) . '</small>';
        // v5.9.373: surface key AVETMISS identity fields inline so the RTO can
        // read client ID, DOB and state without leaving the grid.
        $ddstatelabels = ['01' => 'NSW', '02' => 'VIC', '03' => 'QLD', '04' => 'SA', '05' => 'WA',
                          '06' => 'TAS', '07' => 'NT', '08' => 'ACT', '09' => 'Other'];
        $ddmeta = [];
        if (!empty($student->clientid))   { $ddmeta[] = 'ID ' . s($student->clientid); }
        if (!empty($student->dateofbirth)) { $ddmeta[] = 'DOB ' . date('d/m/Y', (int)$student->dateofbirth); }
        if (!empty($student->statecode))  { $ddmeta[] = $ddstatelabels[$student->statecode] ?? s($student->statecode); }
        if (!empty($ddmeta)) {
            echo '<br><small class="text-muted" style="font-size:11px;">' . implode(' &middot; ', $ddmeta) . '</small>';
        }
        echo '</td>';
        
        $usibadge = '';
        if (!empty($student->usi)) {
            if (local_rtocompliance_usi_is_verified($student->usiverified)) {
                $usibadge = '<span class="badge badge-success" title="USI Verified">' . s($student->usi) . '</span>';
            } else {
                $usibadge = '<span class="badge badge-warning" title="USI Not Verified">' . s($student->usi) . '</span>';
            }
        } else {
            $usibadge = '<span class="badge badge-danger" title="No USI (national student ID number) on file. Collect it before issuing a certificate.">Missing</span>';
        }
        echo '<td>' . $usibadge . '</td>';
        
        foreach ($units as $unit) {
            $enrolment = $enrolments[strtoupper(trim((string)$unit->unitcode))] ?? null;
            if ($enrolment) {
                // v5.9.440: normalise a blank/NULL outcome to '00' ("Not yet assessed") and
                // show any unrecognised-but-present code as itself, so a cell is never "?".
                $ocode = trim((string)($enrolment->outcomeidentifier ?? ''));
                if ($ocode === '') {
                    $ocode = '00';
                }
                $outcome = $outcomecodes[$ocode] ?? ['label' => 'Outcome ' . $ocode, 'badge' => 'badge-secondary', 'short' => s($ocode)];
                echo '<td style="text-align: center;"><span class="badge ' . $outcome['badge'] . '" title="' . $outcome['label'] . '">' . $outcome['short'] . '</span></td>';
                // RPL-CT-FIX (v5.9.331): '51' RPL and '60' CT now count toward completion %.
                if (in_array($enrolment->outcomeidentifier, ['20', '51', '60', '81'])) {
                    $completedunits++;
                } else {
                    // TASK-89: outcome exists but is not a final resolved outcome — still needed.
                    $missingunits[] = $unit;
                }
            } else {
                echo '<td style="text-align: center;"><span class="badge badge-light" title="Not Enrolled">-</span></td>';
                // TASK-89: no enrolment record for this unit — still needed.
                $missingunits[] = $unit;
            }
        }
        
        $percentage = $totalunits > 0 ? round(($completedunits / $totalunits) * 100) : 0;
        $progresscolor = $percentage >= 100 ? '#22c55e' : ($percentage >= 50 ? '#f59e0b' : '#ef4444');
        $iscomplete = ($percentage >= 100 && $totalunits > 0);

        // TASK-89 (v5.9.361): Build the expandable missing-units callout for in-progress
        // students. Uses a plain HTML <details>/<summary> — no JS, no extra queries.
        // The list shows each missing unit's code + name so admins can see the gap
        // without having to open the Qual Cert Hub or scan across unit columns.
        $missinghtml = '';
        if (!$iscomplete && !empty($missingunits)) {
            $mcount = count($missingunits);
            $unitlistitems = '';
            foreach ($missingunits as $mu) {
                $unitlistitems .= '<li style="white-space:nowrap;">'
                    . '<strong>' . s($mu->unitcode) . '</strong>'
                    . ' <span style="color:#6b7280;">' . s($mu->unitname) . '</span>'
                    . '</li>';
            }
            $missinghtml = '<details style="margin-top:6px;">'
                . '<summary style="font-size:11px;font-weight:600;color:#dc2626;cursor:pointer;'
                . 'list-style:none;display:inline-block;" '
                . 'title="Click to expand the list of units still needed">'
                . '&#9656; ' . $mcount . ' unit' . ($mcount !== 1 ? 's' : '') . ' still needed'
                . '</summary>'
                . '<ul style="margin:4px 0 0 4px;padding-left:14px;list-style:disc;'
                . 'text-align:left;font-size:11px;">'
                . $unitlistitems
                . '</ul>'
                . '</details>';
        }

        echo '<td style="text-align: center;">
            <div style="background: #e5e7eb; border-radius: 4px; height: 8px; width: 60px; margin: 0 auto 4px;">
                <div style="background: ' . $progresscolor . '; height: 100%; width: ' . $percentage . '%; border-radius: 4px;"></div>
            </div>
            <small style="font-weight: 600;">' . $percentage . '%</small>
            ' . $missinghtml . '
        </td>';

        // TASK-45 (v5.9.345): Build the cert-issuance UI for this student row.
        // Complete students get an inline "Issue Certificate" button that calls the
        // AJAX endpoint and updates the row in-place.  If certs already exist we
        // show the cert numbers instead.
        $certhtml = '';
        if ($iscomplete) {
            $existingForStudent = $existingcertsmap[$student->userid] ?? [];
            $allCertsIssued = true;
            foreach ($requiredcerttypes as $ct) {
                if (empty($existingForStudent[$ct])) {
                    $allCertsIssued = false;
                    break;
                }
            }

            if ($allCertsIssued) {
                // All required certs already issued — show cert numbers.
                $certlines = [];
                foreach ($requiredcerttypes as $ct) {
                    $ic = $existingForStudent[$ct];
                    $certlines[] = '<span class="badge badge-success" title="' . ucfirst($ct) . ' cert issued ' .
                        userdate($ic->issuedate, get_string('strftimedate', 'core_langconfig')) . '">' .
                        s($ic->certnumber) . '</span>';
                }
                $certhtml = '<div id="rto-cert-row-' . $student->userid . '" style="margin-top:4px;">' .
                    implode(' ', $certlines) . '</div>';
            } else {
                // At least one cert not yet issued — show Issue Certificate button.
                $certhtml = '<div id="rto-cert-row-' . $student->userid . '" style="margin-top:4px;">' .
                    '<button class="btn btn-sm btn-success rto-issue-cert-btn"
                             data-userid="' . $student->userid . '"
                             data-qualbuilderid="' . $qualbuilderid . '"
                             data-studentname="' . s(fullname($student)) . '"
                             onclick="rtoIssueCert(this)"
                             title="Generate and issue certificate(s) for this student">
                         &#127941; Issue Certificate
                     </button>' .
                    '</div>';
            }
        }

        echo '<td style="text-align: center; min-width: 220px;">
            <a href="' . (new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-primary" title="View Profile">Profile</a>
            <a href="' . (new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-secondary" title="Manage Enrolments">Enrolments</a>
            <a href="' . (new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $student->userid]))->out() . '" class="btn btn-sm btn-outline-info" title="View Documents &amp; Certificates">Docs &amp; Certs</a>
            ' . $certhtml . '
        </td>';
        
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qualbuilderid, 'filter' => $filter, 'search' => $search]));
}

// TASK-45 (v5.9.345): Inline JavaScript for certificate issuance from this page.
// Uses plain XHR (no AMD dependency) to call ajax.php?action=issue_cert_from_results
// with userid + qualbuilderid + sesskey.  Updates the cert-row div in place.
$ajaxurljson  = json_encode((new moodle_url('/local/rtocompliance/ajax.php'))->out(false));
$sesskeyjson  = json_encode(sesskey());
echo '<script>
(function () {
    "use strict";

    var AJAX_URL = ' . $ajaxurljson . ';
    var SESSKEY  = ' . $sesskeyjson . ';

    window.rtoIssueCert = function (btn) {
        var userid        = btn.dataset.userid;
        var qualbuilderid = btn.dataset.qualbuilderid;
        var rowDiv        = document.getElementById("rto-cert-row-" + userid);

        btn.disabled    = true;
        btn.textContent = "\u23F3 Issuing\u2026";

        var xhr = new XMLHttpRequest();
        xhr.open("POST", AJAX_URL, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = function () {
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = {success:false, error:"Invalid server response"}; }

            if (data.success) {
                var badges = "";
                (data.issued || []).forEach(function (c) {
                    badges += "<span class=\"badge badge-success\" title=\"" + ucfirst(c.certtype) + " issued " + c.issuedate + "\">" + escHtml(c.certnumber) + "</span> ";
                });
                (data.skipped || []).forEach(function (c) {
                    badges += "<span class=\"badge badge-info\" title=\"" + ucfirst(c.certtype) + " already existed " + c.issuedate + "\">" + escHtml(c.certnumber) + "</span> ";
                });
                if (rowDiv) {
                    rowDiv.innerHTML = badges || "<span class=\"badge badge-secondary\">Issued<\/span>";
                }
            } else {
                btn.disabled    = false;
                btn.textContent = "\u{1F3C5} Issue Certificate";
                var errmsg = data.error || "Unknown error";
                if (rowDiv) {
                    rowDiv.innerHTML += " <span style=\"color:#ef4444;font-size:12px;\">\u26A0 " + escHtml(errmsg) + "<\/span>";
                }
            }
        };
        xhr.onerror = function () {
            btn.disabled    = false;
            btn.textContent = "\u{1F3C5} Issue Certificate";
        };
        xhr.send("action=issue_cert_from_results&userid=" + encodeURIComponent(userid) +
                 "&qualbuilderid=" + encodeURIComponent(qualbuilderid) +
                 "&sesskey=" + encodeURIComponent(SESSKEY));
    };

    window.rtoIssueAllPending = function () {
        var btns       = document.querySelectorAll(".rto-issue-cert-btn:not([disabled])");
        var bulkBtn    = document.getElementById("rto-issue-all-btn");
        var bulkStatus = document.getElementById("rto-bulk-status");
        if (!btns.length) {
            if (bulkStatus) bulkStatus.textContent = "No pending certificates on this page.";
            return;
        }
        if (bulkBtn) bulkBtn.disabled = true;
        var total = btns.length;
        function next(i) {
            if (i >= btns.length) {
                if (bulkStatus) bulkStatus.textContent = "Done \u2014 " + total + " student(s) processed.";
                if (bulkBtn) bulkBtn.disabled = false;
                return;
            }
            var btn = btns[i];
            var userid        = btn.dataset.userid;
            var qualbuilderid = btn.dataset.qualbuilderid;
            var rowDiv        = document.getElementById("rto-cert-row-" + userid);
            btn.disabled    = true;
            btn.textContent = "\u23F3 Issuing\u2026";
            if (bulkStatus) bulkStatus.textContent = "Issuing " + (i + 1) + " of " + total + "\u2026";
            var xhr = new XMLHttpRequest();
            xhr.open("POST", AJAX_URL, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onload = function () {
                var data;
                try { data = JSON.parse(xhr.responseText); } catch (e) { data = {success:false}; }
                if (data.success) {
                    var badges = "";
                    (data.issued || []).forEach(function (c) {
                        badges += "<span class=\"badge badge-success\">" + escHtml(c.certnumber) + "<\/span> ";
                    });
                    (data.skipped || []).forEach(function (c) {
                        badges += "<span class=\"badge badge-info\">" + escHtml(c.certnumber) + "<\/span> ";
                    });
                    if (rowDiv) rowDiv.innerHTML = badges || "<span class=\"badge badge-secondary\">Issued<\/span>";
                } else {
                    btn.disabled    = false;
                    btn.textContent = "\u{1F3C5} Issue Certificate";
                    if (rowDiv) rowDiv.innerHTML += " <span style=\"color:#ef4444;font-size:12px;\">\u26A0 " + escHtml(data.error || "Error") + "<\/span>";
                }
                next(i + 1);
            };
            xhr.onerror = function () { btn.disabled = false; btn.textContent = "\u{1F3C5} Issue Certificate"; next(i + 1); };
            xhr.send("action=issue_cert_from_results&userid=" + encodeURIComponent(userid) +
                     "&qualbuilderid=" + encodeURIComponent(qualbuilderid) +
                     "&sesskey=" + encodeURIComponent(SESSKEY));
        }
        next(0);
    };

    function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ""; }
    function escHtml(s) {
        var d = document.createElement("div");
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }
})();
<\/script>';

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
