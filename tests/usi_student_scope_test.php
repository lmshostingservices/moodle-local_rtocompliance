<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the USI dashboard's course classification scope.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/classes/usi/student_scope.php');

/**
 * Covers classified, unclassified and mixed Moodle enrolments.
 */
class usi_student_scope_test extends \advanced_testcase {
    /** @var \local_rtocompliance\usi\student_scope */
    private $scope;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        $this->scope = new \local_rtocompliance\usi\student_scope($DB, make_timestamp(2015, 1, 1));
    }

    /**
     * Add one course settings row using the existing authority.
     *
     * @param int $courseid
     * @param int $recognised
     */
    private function configure_course(int $courseid, int $recognised): void {
        global $DB;
        $DB->insert_record('local_rtocompliance_courses', (object) [
            'courseid' => $courseid,
            'nationallyrecognised' => $recognised,
            'qualificationcode' => $recognised ? 'BSB50420' : '',
            'qualificationname' => $recognised ? 'Test qualification' : '',
            'nominalhours' => $recognised ? 100 : null,
            'cricosregistered' => 0,
            'cricoscode' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a current active/selected Qual Builder unit relationship.
     *
     * @param string $qualcode
     * @param string $unitcode
     * @param int|null $courseid Primary QB course, or null for map-only test.
     * @return void
     */
    private function create_qualunit(string $qualcode, string $unitcode, ?int $courseid): void {
        global $DB;
        $qbid = $DB->insert_record('local_rtocompliance_qualbuilder', (object) [
            'producttype' => 'qualification',
            'qualificationcode' => $qualcode,
            'qualificationname' => 'Mapped test qualification',
            'totalunits' => 1,
            'coreunitcount' => 1,
            'electivecount' => 0,
            'status' => 'active',
            'validationpassed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_rtocompliance_qualunits', (object) [
            'qualbuilderid' => $qbid,
            'unitcode' => $unitcode,
            'unitname' => 'Mapped test unit',
            'unittype' => 'core',
            'courseid' => $courseid,
            'sequenceorder' => 1,
            'selected' => 1,
            'status' => 'active',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Add an administrator-confirmed course map to an active QB unit.
     *
     * @param int $courseid
     * @param string $qualcode
     * @param string $unitcode
     * @param string $source
     * @return void
     */
    private function create_confirmed_map(
        int $courseid,
        string $qualcode,
        string $unitcode,
        string $source = 'manual'
    ): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], 'category');
        $DB->insert_record('local_rtocompliance_course_map', (object) [
            'courseid' => $courseid,
            'categoryid' => (int) $course->category,
            'qualcode' => $qualcode,
            'unitcode' => $unitcode,
            'source' => $source,
            'confirmed' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 2,
        ]);
    }

    /**
     * Add a student profile sufficient for the dashboard join.
     *
     * @param int $userid
     * @return int
     */
    private function create_profile(int $userid): int {
        global $DB;
        return (int) $DB->insert_record('local_rtocompliance_students', (object) [
            'userid' => $userid,
            'clientid' => 'C' . $userid,
            'usi' => 'USI' . str_pad((string) $userid, 7, '0', STR_PAD_LEFT),
            'usiverified' => 0,
            'dateofbirth' => strtotime('1990-01-01'),
            'sex' => 'M',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'englishproficiency' => '1',
            'disabilityflag' => 'N',
            'highestschoollevel' => '12',
            'atschoolflag' => 'N',
            'labourforcestatus' => '01',
            'studyreason' => '01',
            'prioreducationflag' => 'N',
            'surveycontactstatus' => 'N',
            'profilecomplete' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Return dashboard user IDs for a classification and optional scope.
     *
     * @param string $classification
     * @param int $catid
     * @param int $courseid
     * @return array
     */
    // v6.3.32: the expected ids at every call site are cast to int. This helper
    // intvals its results, but a generator record's ->id is a STRING on PostgreSQL,
    // so assertSame('187000', 187000) failed on every classification test the first
    // time these tests were run against a real Moodle database.
    private function matching_userids(string $classification, int $catid = 0, int $courseid = 0): array {
        global $DB;
        [$where, $params] = $this->scope->build_where(
            'all', '', $catid, $courseid, 'all', 0, $classification
        );
        return array_map(
            'intval',
            $DB->get_fieldset_sql(
                "SELECT u.id
                   FROM {user} u
                   JOIN {local_rtocompliance_students} s ON s.userid = u.id
                  WHERE $where
               ORDER BY u.id",
                $params
            )
        );
    }

    /**
     * Return the single-row student result for a search/filter combination.
     *
     * @param string $filter
     * @param string $search
     * @param string $classification
     * @return array
     */
    private function matching_search_userids(
        string $filter,
        string $search,
        string $classification = 'all'
    ): array {
        global $DB;
        [$where, $params] = $this->scope->build_where(
            $filter, $search, 0, 0, 'all', 0, $classification
        );
        $rows = $DB->get_records_sql(
            $this->scope->student_select_sql($where, 'u.id ASC'),
            $params
        );
        return array_map(
            static function ($row): int {
                return (int) $row->userid;
            },
            array_values($rows)
        );
    }

    /**
     * A configured zero flag is non-accredited; a missing row is unclassified.
     */
    public function test_classified_and_unconfigured_courses(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $recognised = $generator->create_course(['fullname' => 'Mapped recognised course']);
        $nonrecognised = $generator->create_course(['fullname' => 'CPD workshop']);
        $unconfigured = $generator->create_course(['fullname' => 'Unconfigured course']);
        $this->configure_course($recognised->id, 1);
        $this->configure_course($nonrecognised->id, 0);

        $users = [];
        foreach (['Recognised', 'CPD', 'Unconfigured'] as $name) {
            $user = $generator->create_user(['firstname' => $name]);
            $this->create_profile($user->id);
            $users[$name] = $user;
        }
        $noenrol = $generator->create_user(['firstname' => 'No enrolment']);
        $this->create_profile($noenrol->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $generator->enrol_user($users['Recognised']->id, $recognised->id, $roleid);
        $generator->enrol_user($users['CPD']->id, $nonrecognised->id, $roleid);
        $generator->enrol_user($users['Unconfigured']->id, $unconfigured->id, $roleid);

        $this->assertSame([(int) $users['CPD']->id], $this->matching_userids('nonrecognised'));
        $this->assertSame([(int) $users['Recognised']->id], $this->matching_userids('recognised'));
        $this->assertSame([(int) $users['Unconfigured']->id], $this->matching_userids('unclassified'));
        $this->assertCount(4, $this->matching_userids('all'));

        $recognisedoptions = $this->scope->get_course_options('recognised');
        $nonrecognisedoptions = $this->scope->get_course_options('nonrecognised');
        $this->assertArrayHasKey($recognised->id, $recognisedoptions);
        $this->assertArrayNotHasKey($nonrecognised->id, $recognisedoptions);
        $this->assertArrayNotHasKey($unconfigured->id, $recognisedoptions);
        $this->assertArrayHasKey($nonrecognised->id, $nonrecognisedoptions);
        $this->assertArrayNotHasKey($unconfigured->id, $nonrecognisedoptions);
        $unclassifiedoptions = $this->scope->get_course_options('unclassified');
        $this->assertArrayHasKey($unconfigured->id, $unclassifiedoptions);
        $this->assertArrayNotHasKey($recognised->id, $unclassifiedoptions);
        $this->assertArrayNotHasKey($nonrecognised->id, $unclassifiedoptions);
    }

    /**
     * EXISTS classification makes mixed-enrolment users appear once in each
     * compatible view rather than duplicating their dashboard row.
     */
    public function test_mixed_enrolment_matches_both_once(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $recognised = $generator->create_course(['fullname' => 'National course']);
        $nonrecognised = $generator->create_course(['fullname' => 'CPD course']);
        $this->configure_course($recognised->id, 1);
        $this->configure_course($nonrecognised->id, 0);
        $user = $generator->create_user(['firstname' => 'Mixed']);
        $this->create_profile($user->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $generator->enrol_user($user->id, $recognised->id, $roleid);
        $generator->enrol_user($user->id, $nonrecognised->id, $roleid);

        $this->assertSame([(int) $user->id], $this->matching_userids('recognised'));
        $this->assertSame([(int) $user->id], $this->matching_userids('nonrecognised'));
    }

    /**
     * Category and exact-course predicates must constrain the same enrolment
     * as classification; recognition in another course cannot leak in.
     */
    public function test_mixed_category_and_incompatible_course_do_not_broaden(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $recognisedcat = $generator->create_category(['name' => 'Recognised category']);
        $nonrecognisedcat = $generator->create_category(['name' => 'CPD category']);
        $recognised = $generator->create_course(['category' => $recognisedcat->id, 'fullname' => 'National delivery']);
        $nonrecognised = $generator->create_course(['category' => $nonrecognisedcat->id, 'fullname' => 'CPD delivery']);
        $this->configure_course($recognised->id, 1);
        $this->configure_course($nonrecognised->id, 0);
        $user = $generator->create_user(['firstname' => 'Cross category']);
        $this->create_profile($user->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $generator->enrol_user($user->id, $recognised->id, $roleid);
        $generator->enrol_user($user->id, $nonrecognised->id, $roleid);

        $this->assertSame([], $this->matching_userids('recognised', $nonrecognisedcat->id));
        $this->assertSame([], $this->matching_userids('recognised', 0, $nonrecognised->id));
        $this->assertSame([(int) $user->id], $this->matching_userids('nonrecognised', $nonrecognisedcat->id));
        $this->assertFalse($this->scope->course_is_compatible($nonrecognised->id, 'recognised'));
    }

    /**
     * Current QB links and a confirmed valid map override a stale zero flag;
     * a zero-only and no-settings course remain non-recognised/unclassified.
     */
    public function test_qualbuilder_and_valid_course_map_recognition_evidence(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $qbcourse = $generator->create_course(['fullname' => 'Mapped primary']);
        $qbnoflagcourse = $generator->create_course(['fullname' => 'Mapped without legacy flag']);
        $mapcourse = $generator->create_course(['fullname' => 'Mapped manual']);
        $zerocourse = $generator->create_course(['fullname' => 'Explicit zero']);
        $unknowncourse = $generator->create_course(['fullname' => 'No recognition data']);
        $arbitrarymapcourse = $generator->create_course(['fullname' => 'Untrusted map']);
        foreach ([$qbcourse, $mapcourse, $zerocourse] as $course) {
            $this->configure_course($course->id, 0);
        }
        $this->create_qualunit('TAE00001', 'TAEUNIT01', $qbcourse->id);
        $this->create_qualunit('TAE00003', 'TAEUNIT03', $qbnoflagcourse->id);
        $this->create_qualunit('TAE00002', 'TAEUNIT02', null);
        $this->create_confirmed_map($mapcourse->id, 'TAE00002', 'TAEUNIT02');
        // An automatically detected/arbitrary map row is not recognition
        // evidence, even when it has a non-empty qual/unit pair.
        $this->create_confirmed_map($arbitrarymapcourse->id, 'FAKE0001', 'FAKEUNIT', 'auto');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $users = [];
        foreach ([
            'QB' => $qbcourse,
            'QBNoFlag' => $qbnoflagcourse,
            'Map' => $mapcourse,
            'Zero' => $zerocourse,
            'Unknown' => $unknowncourse,
            'Arbitrary' => $arbitrarymapcourse,
        ] as $name => $course) {
            $user = $generator->create_user(['firstname' => $name]);
            $this->create_profile($user->id);
            $generator->enrol_user($user->id, $course->id, $roleid);
            $users[$name] = $user;
        }

        $this->assertSame(
            [(int) $users['QB']->id, (int) $users['QBNoFlag']->id, (int) $users['Map']->id],
            $this->matching_userids('recognised')
        );
        $this->assertSame([(int) $users['Zero']->id], $this->matching_userids('nonrecognised'));
        $this->assertSame(
            [(int) $users['Unknown']->id, (int) $users['Arbitrary']->id],
            $this->matching_userids('unclassified')
        );
        $this->assertCount(6, $this->matching_userids('all'));
    }

    /**
     * Category/course predicates compose with classification, and the shared
     * count/select SQL has the same result set.
     */
    public function test_category_course_and_count_export_parity(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => 'VET category']);
        $course = $generator->create_course(['category' => $category->id, 'fullname' => 'VET course']);
        $other = $generator->create_course(['fullname' => 'Other course']);
        $this->configure_course($course->id, 1);
        $this->configure_course($other->id, 0);
        $user = $generator->create_user(['firstname' => 'Scoped']);
        $this->create_profile($user->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $generator->enrol_user($user->id, $course->id, $roleid);

        [$where, $params] = $this->scope->build_where(
            'all', 'Scoped', $category->id, 0, 'all', 0, 'recognised'
        );
        $selectsql = $this->scope->student_select_sql($where, 'u.id ASC');
        $rows = $DB->get_records_sql($selectsql, $params);
        $count = (int) $DB->count_records_sql($this->scope->student_count_sql($where), $params);
        $this->assertCount(1, $rows);
        $this->assertSame($count, count($rows));

        // A specific compatible course composes with status/search and still
        // returns one row for the student.
        [$coursewhere, $courseparams] = $this->scope->build_where(
            'withusi', 'Scoped', 0, $course->id, 'all', 0, 'recognised'
        );
        $this->assertSame(
            1,
            (int) $DB->count_records_sql($this->scope->student_count_sql($coursewhere), $courseparams)
        );
    }

    /**
     * Username is a live Moodle-user search field, and LIKE metacharacters are
     * literal in every existing search field.
     */
    public function test_username_search_is_literal_and_preserves_other_fields(): void {
        global $DB;
        $generator = $this->getDataGenerator();

        $usernameonly = $generator->create_user([
            'username' => 'usi_username_only',
            'firstname' => 'No',
            'lastname' => 'OtherFields',
            'email' => 'different@example.test',
        ]);
        $literalunderscore = $generator->create_user([
            'username' => 'wild_user',
            'firstname' => 'Literal',
            'lastname' => 'Underscore',
        ]);
        $wildunderscore = $generator->create_user([
            'username' => 'wildxuser',
            'firstname' => 'Wildcard',
            'lastname' => 'Underscore',
        ]);
        $wildpercent = $generator->create_user([
            'username' => 'percentxuser',
            'firstname' => 'Wildcard',
            'lastname' => 'Percent',
        ]);
        $emailmatch = $generator->create_user([
            'username' => 'email_account',
            'firstname' => 'Email',
            'lastname' => 'Match',
            'email' => 'find-by-email@example.test',
        ]);
        $namematch = $generator->create_user([
            'username' => 'name_account',
            'firstname' => 'Findable',
            'lastname' => 'ByName',
        ]);

        foreach ([
            $usernameonly, $literalunderscore, $wildunderscore, $wildpercent,
            $emailmatch, $namematch,
        ] as $user) {
            $this->create_profile($user->id);
        }

        // The username-only hit must not be dependent on a name/email/USI
        // match, and the old fields must continue to work.
        $this->assertSame(
            [(int) $usernameonly->id],
            $this->matching_search_userids('all', 'username_only')
        );
        $this->assertSame(
            [(int) $emailmatch->id],
            $this->matching_search_userids('all', 'find-by-email')
        );
        $this->assertSame(
            [(int) $namematch->id],
            $this->matching_search_userids('all', 'Findable ByName')
        );

        // A supplied underscore or percent must not broaden the username
        // predicate into a SQL wildcard.
        $this->assertSame(
            [(int) $literalunderscore->id],
            $this->matching_search_userids('all', 'wild_')
        );
        $this->assertSame(
            [],
            $this->matching_search_userids('all', 'percent%')
        );

        // Search must also retain status filtering and return an enrolled
        // student only once when classification has multiple enrolments.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $recognised = $generator->create_course(['fullname' => 'Recognised search course']);
        $other = $generator->create_course(['fullname' => 'Other search course']);
        $this->configure_course($recognised->id, 1);
        $this->configure_course($other->id, 0);
        $DB->set_field('local_rtocompliance_students', 'usiverified', 1, ['userid' => $usernameonly->id]);
        $generator->enrol_user($usernameonly->id, $recognised->id, $roleid);
        $generator->enrol_user($usernameonly->id, $other->id, $roleid);
        $this->assertSame(
            [(int) $usernameonly->id],
            $this->matching_search_userids('verified', 'username_only', 'recognised')
        );

        // The scope reads Moodle's current username, not a copied value on
        // the local student profile.
        $DB->set_field('user', 'username', 'usi_username_renamed', ['id' => $usernameonly->id]);
        $this->assertSame(
            [(int) $usernameonly->id],
            $this->matching_search_userids('all', 'username_renamed')
        );
    }
}