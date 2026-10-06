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
 * PHPUnit tests for STAFF-NOT-LEARNER (v6.6.4).
 *
 * A trainer enrolled in a course linked to a qualification must not receive an AVETMISS
 * learner enrolment. Reproduces the live case: a trainer enrolled as editing teacher in
 * a Qual Builder-linked delivery course was given a continuing (70) TLIA5060 row, which
 * made the profile lock treat him as a current learner.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * @coversDefaultClass \local_rtocompliance\task\process_enrolment_task
 */
class staff_enrolment_task_test extends \advanced_testcase {
    /** @var \stdClass Course linked to TLIA5060 in Qual Builder. */
    private $course;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course(
            [
                'fullname' => 'TLIA5060 Plan and Organise the International Forwarding of Freight by Air Transport (ATI) 26S2',
                'shortname' => 'TLIA5060-26S2',
        ]);
        $qbid = $DB->insert_record('local_rtocompliance_qualbuilder', (object) [
            'producttype' => 'qualification',
            'qualificationcode' => 'TLI50119',
            'qualificationname' => 'Diploma of International Freight Forwarding',
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
            'unitcode' => 'TLIA5060',
            'unitname' => 'Plan and organise the international forwarding of freight by air transport',
            'unittype' => 'core',
            'courseid' => $this->course->id,
            'sequenceorder' => 1,
            'selected' => 1,
            'status' => 'active',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Run the create step exactly as cron would, after the enrolment and its role exist.
     *
     * @param int $userid
     * @param int $courseid
     */
    private function run_create(int $userid, int $courseid): void {
        $task = new task\process_enrolment_task();
        $task->set_custom_data(['action' => 'create', 'userid' => $userid, 'courseid' => $courseid, 'enrolid' => 0]);
        $task->execute();
    }

    /**
     * Number of AVETMISS enrolment rows for a user in a course.
     *
     * @param int $userid
     * @param int $courseid
     * @return int
     */
    private function rows_for(int $userid, int $courseid): int {
        global $DB;
        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {local_rtocompliance_enrolments} e
               JOIN {local_rtocompliance_students} s ON s.id = e.studentid
              WHERE s.userid = :uid AND e.courseid = :cid",
            ['uid' => $userid, 'cid' => $courseid]
        );
    }

    /**
     * A learner still gets their row - the fix must not stop real enrolments.
     */
    public function test_student_enrolment_creates_row(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        $this->run_create($user->id, $this->course->id);

        $this->assertSame(1, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * The live case: an editing teacher gets no learner row and no learner record.
     */
    public function test_editing_teacher_enrolment_creates_nothing(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'editingteacher');

        $this->expectOutputRegex('/holds a staff role in this course/');
        $this->run_create($user->id, $this->course->id);

        $this->assertSame(0, $this->rows_for($user->id, $this->course->id));
        $this->assertFalse($DB->record_exists('local_rtocompliance_students', ['userid' => $user->id]));
        $this->assertFalse(local_rtocompliance_user_requires_avetmiss($user->id));
    }

    /**
     * A non-editing teacher is staff too.
     */
    public function test_non_editing_teacher_enrolment_creates_nothing(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'teacher');

        $this->expectOutputRegex('/holds a staff role in this course/');
        $this->run_create($user->id, $this->course->id);

        $this->assertSame(0, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * Teaching one course does not stop a person being a learner in another.
     */
    public function test_teacher_elsewhere_is_still_a_learner_here(): void {
        $user = $this->getDataGenerator()->create_user();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $othercourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        $this->run_create($user->id, $this->course->id);

        $this->assertSame(1, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * A trainer role given at category level is recognised in every course under it.
     */
    public function test_category_level_staff_role_is_recognised(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $catcontext = \context_coursecat::instance($this->course->category);
        role_assign($roleid, $user->id, $catcontext->id);

        $this->assertTrue(local_rtocompliance_user_is_staff_in_course($user->id, $this->course->id));
    }

    /**
     * Staff who are ALSO enrolled as a student in the course are learners there and keep
     * their row - a staff role must never suppress a genuine student enrolment.
     */
    public function test_teacher_and_student_in_same_course_is_a_learner(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        role_assign($roleid, $user->id, \context_course::instance($this->course->id)->id);

        $this->run_create($user->id, $this->course->id);

        $this->assertSame(1, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * A site-level manager doing their own unit as a student is a learner.
     */
    public function test_system_manager_enrolled_as_student_is_a_learner(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        $this->run_create($user->id, $this->course->id);

        $this->assertSame(1, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * A category-level trainer enrolled as a student in a course under that category is a learner.
     */
    public function test_category_trainer_enrolled_as_student_is_a_learner(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        role_assign($roleid, $user->id, \context_coursecat::instance($this->course->category)->id);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        $this->assertFalse(local_rtocompliance_user_is_staff_in_course($user->id, $this->course->id));
        $this->run_create($user->id, $this->course->id);
        $this->assertSame(1, $this->rows_for($user->id, $this->course->id));
    }

    /**
     * The real sequence: Moodle's manual enrolment (event first, role second), the observer
     * queueing the task, and the task runner. A teacher gets nothing; a student gets a row.
     */
    public function test_real_enrolment_sequence(): void {
        global $DB;
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->enrol_user($instance, $teacher->id, $DB->get_field('role', 'id', ['shortname' => 'editingteacher']));
        $manual->enrol_user($instance, $student->id, $DB->get_field('role', 'id', ['shortname' => 'student']));

        $this->expectOutputRegex('/holds a staff role in this course/');
        $this->runAdhocTasks('\local_rtocompliance\task\process_enrolment_task');

        $this->assertSame(0, $this->rows_for($teacher->id, $this->course->id));
        $this->assertSame(1, $this->rows_for($student->id, $this->course->id));
    }

    /**
     * A plain learner is not staff.
     */
    public function test_student_is_not_staff(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');

        $this->assertFalse(local_rtocompliance_user_is_staff_in_course($user->id, $this->course->id));
        $this->assertFalse(local_rtocompliance_user_is_staff_in_course(0, $this->course->id));
    }
}
