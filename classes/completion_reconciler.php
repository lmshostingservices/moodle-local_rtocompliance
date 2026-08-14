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
 * RTO Compliance plugin — completion_reconciler.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * COMPLETION RECONCILER (v5.9.374)
 *
 * WHY THIS EXISTS
 * ---------------
 * The RTO delivers a qualification's units through MANY Moodle courses:
 * semester copies made every intake, spread across different categories, and
 * older "archived" intake courses. A student can take three or four years and
 * complete units across dozens of these copied courses. Moodle's category tree
 * is NOT a reliable source of the compliant unit set — categories frequently do
 * not contain the right units. The single source of truth for "which units make
 * up a qualification" is the Qualification Builder; the single source of truth
 * for "who achieved what" is the plugin's own results register
 * (local_rtocompliance_enrolments).
 *
 * This reconciler closes the gap between those two facts. It reads Moodle
 * {course_completions} (completions that already happened — it NEVER creates
 * them), resolves each completed course to its unit + qualification through the
 * Qualification Builder / course map (any category, INCLUDING archived/variant
 * intake copies), and upserts the resulting COMPETENT outcome into the results
 * register. After it runs, a student who finished a unit through any semester
 * copy in any category shows that competency on Student Results and is eligible
 * for certificates — seamlessly.
 *
 * HARD GUARANTEE (matches the standing project constraint):
 *   - Reads Moodle {course_completions}, {course}, and plugin tables only.
 *   - Writes ONLY to local_rtocompliance_enrolments (the plugin register).
 *   - Creates or deletes NO Moodle {user} accounts, {user_enrolments} or
 *     {course_completions}. It is completion-DRIVEN, never completion-WRITING.
 *   - Idempotent: safe to run repeatedly. Never overwrites a manually-set
 *     outcome (manualoutcome = 1) and never downgrades an existing competent
 *     result.
 *
 * The AVETMISS competent/credit set treated as "already achieved" (skip):
 *   20 Competent, 51 RPL Granted, 60 Credit Transfer, 81 Non-Assessed Satisfactory.
 */
class completion_reconciler {
    /** Outcomes that already represent an achieved unit — never downgrade these. */
    const COMPETENT = ['20', '51', '60', '81'];

    /** @var array|null Cached uppercased unitcode → [qualcode, programname, unitname]. */
    protected static $unitindexcache = null;

    /** @var array|null Cached superseded old-code → current-code map (uppercased). */
    protected static $supersededcache = null;

    /**
     * Reconcile Moodle course completions into the plugin results register.
     *
     * @param array $opts Optional: ['limit' => int cap on completions scanned (0 = all),
     *                               'dryrun' => bool (compute counts, write nothing)].
     * @return array Summary counts + a short sample of unresolved courses.
     */
    public static function reconcile(array $opts = []): array {
        global $DB;

        $limit  = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;
        $dryrun = !empty($opts['dryrun']);
        $now    = time();

        $summary = [
            'scanned'           => 0,
            'created'           => 0,
            'updated'           => 0,
            'already_competent' => 0,
            'manual_preserved'  => 0,
            'conflict_terminal' => 0,
            'skipped_nostudent' => 0,
            'skipped_nomap'     => 0,
            'unresolved_sample' => [],
        ];

        // userid → plugin studentid (welded via local_rtocompliance_students.userid).
        $studentbyuser = [];
        $srs = $DB->get_records('local_rtocompliance_students', null, '', 'id, userid');
        foreach ($srs as $sr) {
            if (!empty($sr->userid)) {
                $studentbyuser[(int)$sr->userid] = (int)$sr->id;
            }
        }

        // Course resolution cache: courseid → [unitcode, qualcode, unitname, programname] | false.
        $resolvecache = [];

        // Completed completions only. Order by timecompleted so the earliest
        // completing course wins the register row's courseid when several
        // semester copies map to the same unit.
        $sql = "SELECT cc.id, cc.userid, cc.course AS courseid, cc.timecompleted
                  FROM {course_completions} cc
                 WHERE cc.timecompleted IS NOT NULL AND cc.timecompleted > 0
              ORDER BY cc.timecompleted ASC, cc.id ASC";
        $rs = $DB->get_recordset_sql($sql, [], 0, $limit ?: 0);

        foreach ($rs as $cc) {
            $summary['scanned']++;

            $studentid = $studentbyuser[(int)$cc->userid] ?? 0;
            if ($studentid <= 0) {
                $summary['skipped_nostudent']++;
                continue;
            }

            $courseid = (int)$cc->courseid;
            if (!array_key_exists($courseid, $resolvecache)) {
                $resolvecache[$courseid] = self::resolve_course_units($courseid);
            }
            $units = $resolvecache[$courseid];
            if (empty($units)) {
                $summary['skipped_nomap']++;
                if (count($summary['unresolved_sample']) < 25) {
                    $summary['unresolved_sample'][$courseid] = true;
                }
                continue;
            }

            // A single completed course can deliver MORE THAN ONE unit (e.g. a
            // course named "ABC12345 & ABC12345 ..."). Credit every unit it
            // resolves to — one register row per (student, unit).
            foreach ($units as $u) {
                list($unitcode, $qualcode, $unitname, $programname) = $u;
                $programcode = $qualcode; // may be '' if the qualification is unknown.

                // UNIT-scoped lookup so a unit already recorded (with or without a
                // program) is never duplicated; programcode only backfills a blank.
                $existing = self::find_existing($studentid, $unitcode);

                if ($existing) {
                    if ((int)$existing->manualoutcome === 1) {
                        // Never touch a manual outcome — but safe to fill a blank program.
                        if (!$dryrun && empty($existing->programcode) && $programcode !== '') {
                            $DB->update_record('local_rtocompliance_enrolments', (object)[
                                'id' => $existing->id, 'programcode' => $programcode,
                                'programname' => ($programname !== '' ? $programname : $existing->programname),
                                'timemodified' => $now,
                            ]);
                        }
                        $summary['manual_preserved']++;
                        continue;
                    }
                    $needsbackfill = (empty($existing->programcode) && $programcode !== '')
                        || (empty($existing->programname) && $programname !== '')
                        || (empty($existing->unitname) && $unitname !== '');

                    if (in_array($existing->outcomeidentifier, self::COMPETENT, true)) {
                        if (!$dryrun && $needsbackfill) {
                            $upd = (object)['id' => $existing->id, 'timemodified' => $now];
                            if (empty($existing->programcode) && $programcode !== '') { $upd->programcode = $programcode; }
                            if (empty($existing->programname) && $programname !== '') { $upd->programname = $programname; }
                            if (empty($existing->unitname) && $unitname !== '')       { $upd->unitname = $unitname; }
                            $DB->update_record('local_rtocompliance_enrolments', $upd);
                        }
                        $summary['already_competent']++;
                        continue;
                    }
                    // A-P2-1 (v5.9.387): only upgrade genuinely NON-FINAL states from a
                    // Moodle completion. Terminal non-competent outcomes — 30 (fail),
                    // 40 (withdrawn), 41 (RTO closure), 52 (RPL not granted), 61
                    // (superseded) and 82 (non-assessable not satisfactory) — are real
                    // assessment results. Flipping them to Competent because a Moodle
                    // course-completion row exists would fabricate a pass and misreport
                    // to NCVER, so they are counted as a conflict for human review and
                    // left untouched. Only 70/85/00/blank are treated as "in progress".
                    $curout = (string)($existing->outcomeidentifier ?? '');
                    if (!in_array($curout, ['70', '85', '00', ''], true)) {
                        $summary['conflict_terminal']++;
                        continue;
                    }
                    // Upgrade a non-final outcome to competent from the completion.
                    if (!$dryrun) {
                        $upd = new \stdClass();
                        $upd->id                = $existing->id;
                        $upd->outcomeidentifier = '20';
                        $upd->status            = 'completed';
                        $upd->activityenddate   = (int)$cc->timecompleted;
                        $upd->assessmentdate    = (int)$cc->timecompleted;
                        $upd->timemodified      = $now;
                        if (empty($existing->unitname) && $unitname !== '')       { $upd->unitname = $unitname; }
                        if (empty($existing->programname) && $programname !== '') { $upd->programname = $programname; }
                        if (empty($existing->programcode) && $programcode !== '') { $upd->programcode = $programcode; }
                        $DB->update_record('local_rtocompliance_enrolments', $upd);
                    }
                    $summary['updated']++;
                    continue;
                }

                // Insert a new competent register row for this unit.
                if (!$dryrun) {
                    $row = new \stdClass();
                    $row->studentid         = $studentid;
                    $row->courseid          = $courseid;
                    $row->programcode       = ($programcode !== '') ? $programcode : null;
                    $row->programname       = ($programname !== '') ? $programname : null;
                    $row->unitcode          = $unitcode;
                    $row->unitname          = ($unitname !== '') ? $unitname : null;
                    // A-P1-2 (v5.9.387): give the row a non-NULL activity start date
                    // (the completion date, the only date we have) so it is not silently
                    // excluded from the NAT00120/NAT00060 exports, whose filter
                    // "activitystartdate <= periodend" drops NULLs.
                    $row->activitystartdate = (int)$cc->timecompleted;
                    $row->activityenddate   = (int)$cc->timecompleted;
                    $row->assessmentdate    = (int)$cc->timecompleted;
                    $row->outcomeidentifier = '20';
                    $row->manualoutcome     = 0;
                    $row->deliverymode      = '10';
                    $row->fundingsourcenat  = '30';
                    $row->vetflag           = 'Y';
                    $row->vetinschoolsflag  = 'N';
                    $row->commencingprogramid = '3';
                    $row->feecharged        = 'Y';
                    $row->status            = 'completed';
                    $row->timecreated       = $now;
                    $row->timemodified      = $now;
                    try {
                        $DB->insert_record('local_rtocompliance_enrolments', $row);
                    } catch (\dml_exception $e) {
                        // Defensive: a DB error on one row shouldn't abort the run.
                        $summary['skipped_nomap']++;
                        continue;
                    }
                }
                $summary['created']++;
            }
        }
        $rs->close();

        $summary['unresolved_sample'] = array_keys($summary['unresolved_sample']);
        return $summary;
    }

    /**
     * Report the completions the reconciler cannot resolve to a unit — the
     * review list behind the "unmapped courses" count. One row per Moodle course
     * whose completions could not be matched to any Qualification Builder unit,
     * with how many completions and how many known students it is blocking, so
     * the RTO can go and link (or rename) exactly those courses.
     *
     * Read-only. Writes nothing.
     *
     * @param int $limit Cap on completions scanned (0 = all).
     * @return array List of objects: courseid, shortname, fullname, category,
     *               completions, students (distinct plugin students affected).
     */
    public static function unmapped_completions(int $limit = 0): array {
        global $DB;

        // userid → plugin studentid (to count how many real students are blocked).
        $studentbyuser = [];
        foreach ($DB->get_records('local_rtocompliance_students', null, '', 'id, userid') as $sr) {
            if (!empty($sr->userid)) {
                $studentbyuser[(int)$sr->userid] = (int)$sr->id;
            }
        }

        $resolvecache = [];
        $unmapped = []; // courseid => ['completions'=>int, 'students'=>[studentid=>true]]

        $rs = $DB->get_recordset_sql(
            "SELECT cc.id, cc.userid, cc.course AS courseid
               FROM {course_completions} cc
              WHERE cc.timecompleted IS NOT NULL AND cc.timecompleted > 0
           ORDER BY cc.course ASC, cc.id ASC",
            [], 0, $limit ?: 0
        );
        foreach ($rs as $cc) {
            $courseid = (int)$cc->courseid;
            if (!array_key_exists($courseid, $resolvecache)) {
                $resolvecache[$courseid] = self::resolve_course_units($courseid);
            }
            if (!empty($resolvecache[$courseid])) {
                continue; // resolves to at least one unit — not an issue.
            }
            // Mirror reconcile()'s ordering: a completion with no plugin student
            // record is skipped as "no student", never counted as "unmapped".
            // Only count student completions so every listed course genuinely
            // blocks at least one real student (no Students Affected = 0 noise).
            $sid = $studentbyuser[(int)$cc->userid] ?? 0;
            if ($sid <= 0) {
                continue;
            }
            if (!isset($unmapped[$courseid])) {
                $unmapped[$courseid] = ['completions' => 0, 'students' => []];
            }
            $unmapped[$courseid]['completions']++;
            $unmapped[$courseid]['students'][$sid] = true;
        }
        $rs->close();

        if (empty($unmapped)) {
            return [];
        }

        // Enrich with course + category names.
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($unmapped), SQL_PARAMS_NAMED, 'uc');
        $courses = $DB->get_records_select('course', "id $insql", $inparams, '',
            'id, shortname, fullname, category');
        $catnames = [];
        $catids = array_values(array_unique(array_map(function ($c) {
            return (int)$c->category;
        }, $courses)));
        if (!empty($catids) && $DB->get_manager()->table_exists('course_categories')) {
            list($cin, $cinp) = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'cc');
            foreach ($DB->get_records_select('course_categories', "id $cin", $cinp, '', 'id, name') as $cat) {
                $catnames[(int)$cat->id] = $cat->name;
            }
        }

        $out = [];
        foreach ($unmapped as $courseid => $info) {
            $c = $courses[$courseid] ?? null;
            $row = new \stdClass();
            $row->courseid    = $courseid;
            $row->shortname   = $c ? (string)$c->shortname : '';
            $row->fullname    = $c ? (string)$c->fullname : '(course deleted)';
            $row->category    = ($c && isset($catnames[(int)$c->category])) ? $catnames[(int)$c->category] : '';
            $row->completions = (int)$info['completions'];
            $row->students    = count($info['students']);
            $out[] = $row;
        }
        // Most impactful first: courses blocking the most students, then completions.
        usort($out, function ($a, $b) {
            return ($b->students <=> $a->students) ?: ($b->completions <=> $a->completions);
        });
        return $out;
    }

    /**
     * Resolve a Moodle course id to the LIST of units it delivers, each as
     * [unitcode, qualcode, unitname, programname]. Handles genuine multi-unit
     * courses (two or more units assessed in one course → competent in ALL of
     * them) and superseded unit codes (translated to their current equivalent).
     * Category-agnostic.
     *
     * Sources, all merged (a course may legitimately deliver several units):
     *   1. QB primary links   (qualunits.courseid)        — may be many.
     *   2. QB variant links   (qualunit_courses.courseid) — may be many; this is
     *      the explicit way to declare a double-unit course whose name doesn't
     *      spell out both codes (link the one course to both units).
     *   3. Course map          (courseid → one unit).
     *   4. Course NAME         (every AVETMISS code in the name) — so a course
     *      named "ABC12345 & ABC12345 ..." automatically credits BOTH units.
     *
     * @param int $courseid
     * @return array List of [unitcode, qualcode, unitname, programname].
     */
    protected static function resolve_course_units(int $courseid): array {
        global $DB;
        if ($courseid <= 0) {
            return [];
        }
        $index = self::unit_index();
        $found = []; // current unitcode => [unitcode, qualcode, unitname, programname]

        $add = function (string $uc, string $qc, string $un, string $pn) use (&$found, $index) {
            $cur = self::current_code($uc); // superseded → current
            if ($cur === '') {
                return;
            }
            // Fill qualification / names from the unit index when the source did
            // not supply them (name-matched or map-only units).
            if ($qc === '' && isset($index[$cur]['qualcode']))    { $qc = $index[$cur]['qualcode']; }
            if ($pn === '' && isset($index[$cur]['programname'])) { $pn = $index[$cur]['programname']; }
            if ($un === '' && isset($index[$cur]['unitname']))    { $un = $index[$cur]['unitname']; }
            if (!isset($found[$cur])) {
                $found[$cur] = [$cur, $qc, $un, $pn];
            } else {
                if ($found[$cur][1] === '' && $qc !== '') { $found[$cur][1] = $qc; }
                if ($found[$cur][2] === '' && $un !== '') { $found[$cur][2] = $un; }
                if ($found[$cur][3] === '' && $pn !== '') { $found[$cur][3] = $pn; }
            }
        };

        // 1) QB primary links — ALL units whose primary course is this course.
        $prims = $DB->get_records_sql(
            "SELECT qu.id, qu.unitcode, qu.unitname, qb.qualificationcode, qb.qualificationname
               FROM {local_rtocompliance_qualunits} qu
               JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
              WHERE qu.courseid = :courseid AND qu.selected = 1 AND qb.status <> 'superseded'",
            ['courseid' => $courseid]);
        foreach ($prims as $p) {
            if (!empty($p->unitcode)) {
                $add(strtoupper(trim($p->unitcode)), strtoupper(trim((string)$p->qualificationcode)),
                     (string)$p->unitname, (string)$p->qualificationname);
            }
        }

        // 2) QB variant links — ALL units this course is linked to (incl.
        //    archived intakes). Multiple rows here is the explicit way to declare
        //    a genuine double-unit course.
        $vars = $DB->get_records_sql(
            "SELECT quc.id, qu.unitcode, qu.unitname, qb.qualificationcode, qb.qualificationname
               FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
               JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
              WHERE quc.courseid = :courseid AND qb.status <> 'superseded'",
            ['courseid' => $courseid]);
        foreach ($vars as $v) {
            if (!empty($v->unitcode)) {
                $add(strtoupper(trim($v->unitcode)), strtoupper(trim((string)$v->qualificationcode)),
                     (string)$v->unitname, (string)$v->qualificationname);
            }
        }

        // 3) Course map (one unit per course).
        $map = $DB->get_record('local_rtocompliance_course_map', ['courseid' => $courseid], 'qualcode, unitcode');
        if ($map && !empty($map->unitcode)) {
            $add(strtoupper(trim($map->unitcode)), strtoupper(trim((string)$map->qualcode)), '', '');
        }

        // 4) Course NAME — every AVETMISS code present, so a genuine double-unit
        //    course credits BOTH units. Only codes that resolve (after superseded
        //    translation) to a real selected unit are kept; mnemonics/semester
        //    tags (20S2, CP1) never match the code pattern.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname, idnumber');
        if ($course) {
            $codes = array_merge(
                self::extract_all_unitcodes(strtoupper(trim((string)$course->shortname))),
                self::extract_all_unitcodes(strtoupper(trim((string)$course->fullname))),
                self::extract_all_unitcodes(strtoupper(trim((string)$course->idnumber)))
            );
            foreach (array_unique($codes) as $code) {
                $cur = self::current_code($code);
                if ($cur !== '' && isset($index[$cur])) {
                    $add($cur, '', '', '');
                }
            }
        }

        return array_values($found);
    }

    /**
     * Uppercased unitcode → [qualcode, programname, unitname]. qualcode is set
     * only when the unit belongs to exactly ONE active qualification (otherwise
     * left blank for a course/map link to disambiguate). Cached per request.
     */
    protected static function unit_index(): array {
        global $DB;
        if (self::$unitindexcache !== null) {
            return self::$unitindexcache;
        }
        $tmp = []; // uc => ['unitname'=>string, 'quals'=>[qc=>qn]]
        $rows = $DB->get_records_sql(
            "SELECT qu.id, UPPER(qu.unitcode) AS uc, qu.unitname,
                    UPPER(qb.qualificationcode) AS qc, qb.qualificationname AS qn
               FROM {local_rtocompliance_qualunits} qu
               JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
              WHERE qu.unitcode IS NOT NULL AND qu.unitcode <> ''
                AND qu.selected = 1 AND qb.status <> 'superseded'");
        foreach ($rows as $r) {
            $uc = $r->uc;
            if (!isset($tmp[$uc])) {
                $tmp[$uc] = ['unitname' => (string)$r->unitname, 'quals' => []];
            }
            if (empty($tmp[$uc]['unitname']) && !empty($r->unitname)) {
                $tmp[$uc]['unitname'] = (string)$r->unitname;
            }
            if ($r->qc !== null && $r->qc !== '') {
                $tmp[$uc]['quals'][$r->qc] = (string)$r->qn;
            }
        }
        $idx = [];
        foreach ($tmp as $uc => $info) {
            $single = (count($info['quals']) === 1);
            $qc = $single ? array_key_first($info['quals']) : '';
            $idx[$uc] = [
                'qualcode'    => $qc,
                'programname' => $single ? $info['quals'][$qc] : '',
                'unitname'    => $info['unitname'],
            ];
        }
        self::$unitindexcache = $idx;
        return $idx;
    }

    /**
     * Parse the admin-editable superseded-unit map (plugin setting
     * "supersededunitmap") into an uppercased old-code → current-code array.
     * One mapping per line, e.g.   ABC12345 => ABC12345
     * The left side may list several old codes:  ABC12345, ABC12345 => ABC12345
     * Separator may be "=>", "->" or "="; lines starting with # are comments.
     * Cached per request.
     */
    public static function superseded_map(): array {
        if (self::$supersededcache !== null) {
            return self::$supersededcache;
        }
        $map = [];
        $raw = (string)get_config('local_rtocompliance', 'supersededunitmap');
        if (trim($raw) !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parts = preg_split('/\s*(?:=>|->|=)\s*/', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $new = strtoupper(trim($parts[1]));
                if ($new === '') {
                    continue;
                }
                foreach (preg_split('/[\s,|]+/', strtoupper(trim($parts[0]))) as $old) {
                    $old = trim($old);
                    if ($old !== '' && $old !== $new) {
                        $map[$old] = $new;
                    }
                }
            }
        }
        self::$supersededcache = $map;
        return $map;
    }

    /**
     * Translate a (possibly superseded) unit code to its current equivalent,
     * following chained supersessions with a loop guard.
     */
    public static function current_code(string $uc): string {
        $uc = strtoupper(trim($uc));
        if ($uc === '') {
            return '';
        }
        $map = self::superseded_map();
        $seen = [];
        while (isset($map[$uc]) && empty($seen[$uc])) {
            $seen[$uc] = true;
            $uc = $map[$uc];
        }
        return $uc;
    }

    /**
     * Find the single best existing register row for a student + unit, scoped by
     * unit only (case-insensitive) so we never create parallel rows for the same
     * unit. Preference order: a row that already carries a programcode, then a
     * more-progressed status, then the most recently modified.
     */
    protected static function find_existing(int $studentid, string $unitcode) {
        global $DB;
        $rows = $DB->get_records_select('local_rtocompliance_enrolments',
            "studentid = :sid AND UPPER(unitcode) = :uc",
            ['sid' => $studentid, 'uc' => strtoupper($unitcode)],
            "CASE WHEN programcode IS NOT NULL AND programcode <> '' THEN 0 ELSE 1 END ASC,
             CASE status WHEN 'active' THEN 1 WHEN 'completed' THEN 2 WHEN 'hold' THEN 3 WHEN 'withdrawn' THEN 4 ELSE 5 END ASC,
             timemodified DESC",
            'id, outcomeidentifier, manualoutcome, status, unitname, programname, programcode', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Extract EVERY AVETMISS unit/qual code appearing anywhere in a course name,
     * so a genuine double-unit course ("ABC12345 & ABC12345 ...") credits all its
     * units. Semester/mnemonic tags (16S2, 20S2, MSP, CP1) do not match the code
     * pattern, so they are never mistaken for units.
     *
     * @return string[] uppercased codes in order of appearance.
     */
    protected static function extract_all_unitcodes(string $name): array {
        if ($name === '') {
            return [];
        }
        $out = [];
        if (preg_match_all('/\b([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/', $name, $m)) {
            foreach ($m[1] as $code) {
                $out[] = $code;
            }
        }
        return $out;
    }
}
