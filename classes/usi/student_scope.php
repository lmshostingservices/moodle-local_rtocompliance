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
 * Query builder for the USI student dashboard.
 *
 * Course classification uses an explicit positive nationally-recognised flag
 * or a current, valid Qual Builder/course-map unit relationship.  It does not
 * infer recognition from a course name, shortname or arbitrary map row.
 * A course without either recognition evidence is unclassified.
 *
 * The course/user-enrolment scope below intentionally has the same semantics
 * as the USI page before classification was added: any Moodle user_enrolment
 * row qualifies, regardless of enrolment status or Moodle enrolment instance
 * status.  This includes historical and suspended enrolments.  The separate
 * AVETMISS date-rule scope likewise continues to inspect all local training
 * activity rows.  This class only adds the course classification predicate.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\usi;

defined('MOODLE_INTERNAL') || die();

/**
 * Build composable predicates used by the USI table, cards and exports.
 */
class student_scope {
    /** No course classification. */
    const CLASS_ALL = 'all';

    /** Courses explicitly marked nationally recognised. */
    const CLASS_RECOGNISED = 'recognised';

    /** Courses explicitly configured as not nationally recognised. */
    const CLASS_NONRECOGNISED = 'nonrecognised';

    /** Courses without either recognition evidence source. */
    const CLASS_UNCLASSIFIED = 'unclassified';

    /** @var \moodle_database */
    private $db;

    /** @var int */
    private $rulecutoff;

    /**
     * @param \moodle_database|null $db Moodle database, or global $DB.
     * @param int $rulecutoff Unix timestamp for the AVETMISS USI date rule.
     */
    public function __construct($db = null, int $rulecutoff = 0) {
        if ($db === null) {
            global $DB;
            $db = $DB;
        }
        $this->db = $db;
        $this->rulecutoff = $rulecutoff;
    }

    /**
     * Normalise a URL value without allowing a hand-edited URL to broaden scope.
     *
     * @param string $classification
     * @return string
     */
    public static function normalise_classification(string $classification): string {
        if (in_array($classification, [
            self::CLASS_ALL,
            self::CLASS_RECOGNISED,
            self::CLASS_NONRECOGNISED,
            self::CLASS_UNCLASSIFIED,
        ], true)) {
            return $classification;
        }
        return self::CLASS_ALL;
    }

    /**
     * Return labels used by the page and by tests/integrations.
     *
     * @return array
     */
    public static function classification_labels(): array {
        return [
            self::CLASS_ALL => 'All courses (including unconfigured)',
            self::CLASS_RECOGNISED => 'Nationally recognised / accredited',
            self::CLASS_NONRECOGNISED => 'Non-accredited / CPD',
            self::CLASS_UNCLASSIFIED => 'Unclassified (no recognition data)',
        ];
    }

    /**
     * Return the current explicit Qual Builder/course-map evidence predicate.
     *
     * Direct Qual Builder unit links and their current (non-archive) variant links are
     * checked first. A confirmed course-map row is accepted only when it is
     * a QB/manual mapping to an active, selected Qual Builder unit; this
     * prevents an unconfirmed/title-derived map row from becoming recognition
     * evidence.
     *
     * @param string $coursefield SQL expression containing a course ID.
     * @return string
     */
    private function recognised_course_sql(string $coursefield): string {
        $dbman = $this->db->get_manager();
        $parts = [];
        if ($dbman->table_exists('local_rtocompliance_qualunits')
                && $dbman->table_exists('local_rtocompliance_qualbuilder')) {
            $parts[] = "EXISTS (
                SELECT 1
                  FROM {local_rtocompliance_qualunits} qu
                  JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                 WHERE qu.courseid = $coursefield
                   AND qu.courseid IS NOT NULL
                   AND qu.unitcode IS NOT NULL AND qu.unitcode <> ''
                   AND qu.status = 'active' AND qu.selected = 1
                   AND qb.status <> 'superseded'
            )";
        }
        if ($dbman->table_exists('local_rtocompliance_qualunit_courses')
                && $dbman->table_exists('local_rtocompliance_qualunits')
                && $dbman->table_exists('local_rtocompliance_qualbuilder')) {
            $parts[] = "EXISTS (
                SELECT 1
                  FROM {local_rtocompliance_qualunit_courses} quc
                  JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                  JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
                 WHERE quc.courseid = $coursefield
                   AND quc.courseid IS NOT NULL
                   AND (quc.is_archive IS NULL OR quc.is_archive = 0)
                   AND qu.unitcode IS NOT NULL AND qu.unitcode <> ''
                   AND qu.status = 'active' AND qu.selected = 1
                   AND qb.status <> 'superseded'
            )";
        }
        if ($dbman->table_exists('local_rtocompliance_course_map')
                && $dbman->table_exists('local_rtocompliance_qualunits')
                && $dbman->table_exists('local_rtocompliance_qualbuilder')) {
            $maplink = 'qu.courseid = cm.courseid';
            if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
                $maplink .= " OR EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_qualunit_courses} quc2
                     WHERE quc2.qualunitid = qu.id
                       AND quc2.courseid = cm.courseid
                       AND (quc2.is_archive IS NULL OR quc2.is_archive = 0)
                )";
            }
            $parts[] = "EXISTS (
                SELECT 1
                  FROM {local_rtocompliance_course_map} cm
                  JOIN {local_rtocompliance_qualbuilder} qb
                    ON UPPER(qb.qualificationcode) = UPPER(cm.qualcode)
                  JOIN {local_rtocompliance_qualunits} qu
                    ON qu.qualbuilderid = qb.id
                   AND UPPER(qu.unitcode) = UPPER(cm.unitcode)
                 WHERE cm.courseid = $coursefield
                   AND cm.source IN ('qb', 'manual')
                   AND cm.confirmed = 1
                   AND cm.qualcode IS NOT NULL AND cm.qualcode <> ''
                   AND cm.unitcode IS NOT NULL AND cm.unitcode <> ''
                   AND qu.status = 'active' AND qu.selected = 1
                   AND qb.status <> 'superseded'
                   AND (cm.source = 'manual'
                        OR ($maplink))
            )";
        }
        return empty($parts) ? '(1 = 0)' : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Build the WHERE predicate and parameters for a student query.
     *
     * @param string $filter Existing USI status filter.
     * @param string $search Existing student search, including live Moodle username.
     * @param int $catid Category scope.
     * @param int $courseid Course scope.
     * @param string $usirule Existing AVETMISS date-rule scope.
     * @param int $subcatid Sub-category scope.
     * @param string $classification Course classification scope.
     * @return array [where SQL, parameters]
     */
    public function build_where(
        string $filter,
        string $search,
        int $catid = 0,
        int $courseid = 0,
        string $usirule = 'all',
        int $subcatid = 0,
        string $classification = self::CLASS_ALL
    ): array {
        $classification = self::normalise_classification($classification);
        $where = 'u.deleted = 0';
        $params = [];

        $hasusi = "s.usi IS NOT NULL AND s.usi <> ''";
        switch ($filter) {
            case 'verified':
                $where .= " AND $hasusi AND s.usiverified = 1";
                break;
            case 'unverified':
                $where .= " AND $hasusi AND s.usiverified IN (0, 3)";
                break;
            case 'failed':
                $where .= " AND $hasusi AND s.usiverified = 2";
                break;
            case 'review':
                $where .= " AND $hasusi AND s.usiverified = 4";
                break;
            case 'missingdob':
                $where .= " AND $hasusi AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)";
                break;
            case 'nousi':
                $where .= " AND (s.usi IS NULL OR s.usi = '')";
                break;
            case 'withusi':
                $where .= " AND $hasusi";
                break;
            case 'all':
            default:
                break;
        }

        // Keep the existing date-rule semantics: all local training activity
        // rows are considered, irrespective of their active/completed status.
        if ($usirule !== 'all') {
            $onafter = '(en.activitystartdate >= :ruleco1 OR en.activityenddate >= :ruleco2)';
            $dated = '((en.activitystartdate IS NOT NULL AND en.activitystartdate > 0)
                     OR (en.activityenddate IS NOT NULL AND en.activityenddate > 0))';

            if ($usirule === 'post') {
                $where .= " AND EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_enrolments} en
                      JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                     WHERE s2.userid = u.id AND $onafter)";
                $params['ruleco1'] = $this->rulecutoff;
                $params['ruleco2'] = $this->rulecutoff;
            } else if ($usirule === 'pre') {
                $where .= " AND EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_enrolments} en
                      JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                     WHERE s2.userid = u.id AND $dated)
                    AND NOT EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_enrolments} en
                      JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                     WHERE s2.userid = u.id AND $onafter)";
                $params['ruleco1'] = $this->rulecutoff;
                $params['ruleco2'] = $this->rulecutoff;
            } else if ($usirule === 'undated') {
                $where .= " AND NOT EXISTS (
                    SELECT 1
                      FROM {local_rtocompliance_enrolments} en
                      JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                     WHERE s2.userid = u.id AND $dated)";
            }
        }

        // Keep classification and course/category scope in one enrolment
        // predicate. This is important for a mixed-enrolment student: an
        // enrolment in a CPD course in the selected category must not borrow
        // recognition from a different course elsewhere.
        $scopeconditions = ['ue.userid = u.id'];
        $scopejoins = '';
        $scopeapplies = ($courseid > 0);
        if ($classification !== self::CLASS_ALL) {
            $scopeapplies = true;
        }
        if ($scopeapplies) {
            $scopejoins = " JOIN {course} c ON c.id = e.courseid
                            JOIN {course_categories} cc ON cc.id = c.category
                            LEFT JOIN {local_rtocompliance_courses} cs ON cs.courseid = e.courseid";
        }

        // Preserve the page's existing course/category enrolment policy:
        // every user_enrolment row is in scope, including historical or
        // suspended rows. A selected course takes precedence over category
        // and sub-category, as it did before the classification filter.
        if ($courseid > 0) {
            $scopeconditions[] = 'e.courseid = :ficourse';
            $params['ficourse'] = $courseid;
        } else if ($subcatid > 0) {
            $subcat = $this->db->get_record('course_categories', ['id' => $subcatid], 'id, path');
            if ($subcat) {
                $scopeapplies = true;
                $scopeconditions[] = '(cc.id = :fisub OR '
                    . $this->db->sql_like('cc.path', ':fisubpath') . ')';
                $params['fisub'] = $subcatid;
                $params['fisubpath'] = $subcat->path . '/%';
            }
        } else if ($catid > 0) {
            $cat = $this->db->get_record('course_categories', ['id' => $catid], 'id, path');
            if ($cat) {
                $scopeapplies = true;
                $scopeconditions[] = '(cc.id = :ficat OR '
                    . $this->db->sql_like('cc.path', ':ficatpath') . ')';
                $params['ficat'] = $catid;
                $params['ficatpath'] = $cat->path . '/%';
            }
        }

        if ($scopeapplies && empty($scopejoins)) {
            $scopejoins = " JOIN {course} c ON c.id = e.courseid
                            JOIN {course_categories} cc ON cc.id = c.category
                            LEFT JOIN {local_rtocompliance_courses} cs ON cs.courseid = e.courseid";
        }

        if ($classification !== self::CLASS_ALL) {
            $recognised = $this->recognised_course_sql('e.courseid');
            if ($classification === self::CLASS_RECOGNISED) {
                $scopeconditions[] = "(cs.nationallyrecognised = 1 OR $recognised)";
            } else if ($classification === self::CLASS_NONRECOGNISED) {
                $scopeconditions[] = "(cs.courseid IS NOT NULL
                    AND cs.nationallyrecognised = 0
                    AND NOT $recognised)";
            } else {
                $scopeconditions[] = "((cs.courseid IS NULL
                    OR cs.nationallyrecognised NOT IN (0, 1))
                    AND NOT $recognised)";
            }
        }

        if (!empty($scopejoins)) {
            $where .= " AND EXISTS (
                SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  $scopejoins
                 WHERE " . implode(' AND ', $scopeconditions) . ')';
        }

        if ($search !== '') {
            $fullfwd = $this->db->sql_concat('u.firstname', "' '", 'u.lastname');
            $like = $this->db->sql_like('u.firstname', ':us1', false, false);
            $like .= ' OR ' . $this->db->sql_like('u.lastname', ':us2', false, false);
            $like .= ' OR ' . $this->db->sql_like('u.email', ':us3', false, false);
            $like .= ' OR ' . $this->db->sql_like('s.usi', ':us4', false, false);
            $like .= ' OR ' . $this->db->sql_like('s.clientid', ':us5', false, false);
            $like .= ' OR ' . $this->db->sql_like($fullfwd, ':us6', false, false);
            // Search the live Moodle username as well as the copied/profile
            // fields above. Escape the term before adding the surrounding
            // wildcards so "%" and "_" supplied by an administrator remain
            // literal characters rather than broadening the result set.
            $like .= ' OR ' . $this->db->sql_like('u.username', ':us7', false, false);
            $where .= " AND ($like)";
            $searchlike = '%' . $this->db->sql_like_escape($search) . '%';
            $params['us1'] = $searchlike;
            $params['us2'] = $searchlike;
            $params['us3'] = $searchlike;
            $params['us4'] = $searchlike;
            $params['us5'] = $searchlike;
            $params['us6'] = $searchlike;
            $params['us7'] = $searchlike;
        }

        return [$where, $params];
    }

    /**
     * Build the common student SELECT used by the table and exports.
     *
     * @param string $where
     * @param string $orderby Whitelisted by the caller.
     * @return string
     */
    public function student_select_sql(string $where, string $orderby): string {
        return "SELECT u.id AS userid, u.firstname, u.lastname, u.username, u.email,
                       s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
                  FROM {user} u
                  JOIN {local_rtocompliance_students} s ON s.userid = u.id
                 WHERE $where
                 ORDER BY $orderby";
    }

    /**
     * Build the count query paired with student_select_sql().
     *
     * @param string $where
     * @return string
     */
    public function student_count_sql(string $where): string {
        return "SELECT COUNT(u.id)
                  FROM {user} u
                  JOIN {local_rtocompliance_students} s ON s.userid = u.id
                 WHERE $where";
    }

    /**
     * Return a selected course's authoritative classification.
     *
     * @param int $courseid
     * @param string $classification Requested filter.
     * @return bool|null True/false for compatible/incompatible; null when the
     *                   selected Moodle course does not exist.
     */
    public function course_is_compatible(int $courseid, string $classification): ?bool {
        $classification = self::normalise_classification($classification);
        if ($courseid <= 0 || $classification === self::CLASS_ALL) {
            return true;
        }

        $recognised = $this->recognised_course_sql('c.id');
        $course = $this->db->get_record_sql(
            "SELECT c.id, cs.courseid AS configured, cs.nationallyrecognised,
                    CASE WHEN cs.nationallyrecognised = 1 OR $recognised
                         THEN 1 ELSE 0 END AS recognised
               FROM {course} c
               LEFT JOIN {local_rtocompliance_courses} cs ON cs.courseid = c.id
              WHERE c.id = :courseid",
            ['courseid' => $courseid]
        );
        if (!$course) {
            return null;
        }

        $isrecognised = ((int) $course->recognised === 1);
        $isnonrecognised = !empty($course->configured)
            && ((int) $course->nationallyrecognised === 0) && !$isrecognised;
        $isunclassified = (empty($course->configured)
                || !in_array((int) $course->nationallyrecognised, [0, 1], true))
            && !$isrecognised;
        if ($classification === self::CLASS_RECOGNISED) {
            return $isrecognised;
        } else if ($classification === self::CLASS_NONRECOGNISED) {
            return $isnonrecognised;
        }
        return $isunclassified;
    }

    /**
     * SQL for the course dropdown. Each classified option uses the same
     * recognition evidence as the student enrolment predicate.
     *
     * @param string $classification
     * @return array Moodle records
     */
    public function get_course_options(string $classification = self::CLASS_ALL): array {
        $classification = self::normalise_classification($classification);
        $recognised = $this->recognised_course_sql('c.id');
        $clasause = '';
        if ($classification === self::CLASS_RECOGNISED) {
            $clasause = " AND (cs.nationallyrecognised = 1 OR $recognised)";
        } else if ($classification === self::CLASS_NONRECOGNISED) {
            $clasause = " AND cs.courseid IS NOT NULL
                              AND cs.nationallyrecognised = 0
                              AND NOT $recognised";
        } else if ($classification === self::CLASS_UNCLASSIFIED) {
            $clasause = " AND (cs.courseid IS NULL
                               OR cs.nationallyrecognised NOT IN (0, 1))
                           AND NOT $recognised";
        }

        return $this->db->get_records_sql(
            "SELECT c.id, c.fullname, cc.path AS catpath,
                    CASE WHEN cs.courseid IS NULL THEN 0 ELSE 1 END AS configured,
                    COALESCE(cs.nationallyrecognised, 0) AS nationallyrecognised,
                    CASE WHEN cs.nationallyrecognised = 1 OR $recognised
                         THEN 'recognised'
                         WHEN cs.courseid IS NOT NULL AND cs.nationallyrecognised = 0
                         THEN 'nonrecognised'
                         ELSE 'unclassified' END AS courseclass
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
               LEFT JOIN {local_rtocompliance_courses} cs ON cs.courseid = c.id
              WHERE c.id <> :siteid $clasause
           ORDER BY c.fullname",
            ['siteid' => SITEID]
        );
    }
}