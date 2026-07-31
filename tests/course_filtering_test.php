<?php
/**
 * PHPUnit tests for course-level AVETMISS filtering
 *
 * Tests the local_rtocompliance_user_requires_avetmiss() function and related
 * course-level filtering logic to ensure AVETMISS profile requirements are
 * correctly applied only to students enrolled in nationally recognised courses.
 *
 * @package    local_rtocompliance
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

class course_filtering_test extends \advanced_testcase {

    private $student1;
    private $student2;
    private $teacher;
    private $admin;
    private $course_recognised;
    private $course_nonrecognised;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->student1 = $this->getDataGenerator()->create_user([
            'firstname' => 'Student',
            'lastname' => 'One',
            'email' => 'student1@test.com',
        ]);

        $this->student2 = $this->getDataGenerator()->create_user([
            'firstname' => 'Student',
            'lastname' => 'Two',
            'email' => 'student2@test.com',
        ]);

        $this->teacher = $this->getDataGenerator()->create_user([
            'firstname' => 'Teacher',
            'lastname' => 'Test',
            'email' => 'teacher@test.com',
        ]);

        $this->admin = $this->getDataGenerator()->create_user([
            'firstname' => 'Admin',
            'lastname' => 'Test',
            'email' => 'admin@test.com',
        ]);

        $this->course_recognised = $this->getDataGenerator()->create_course([
            'fullname' => 'BSB50420 Diploma of Leadership and Management',
            'shortname' => 'BSB50420',
        ]);

        $this->course_nonrecognised = $this->getDataGenerator()->create_course([
            'fullname' => 'Internal Training Course',
            'shortname' => 'INTERNAL001',
        ]);
    }

    protected function tearDown(): void {
        $this->student1 = null;
        $this->student2 = null;
        $this->teacher = null;
        $this->admin = null;
        $this->course_recognised = null;
        $this->course_nonrecognised = null;
        parent::tearDown();
    }

    protected function mark_course_nationally_recognised($courseid, $recognised = 1) {
        global $DB;

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->nationallyrecognised = $recognised;
        $record->qualificationcode = $recognised ? 'BSB50420' : '';
        $record->qualificationname = $recognised ? 'Diploma of Leadership and Management' : '';
        $record->nominalhours = $recognised ? 600 : null;
        $record->cricosregistered = 0;
        $record->cricoscode = '';
        $record->timecreated = time();
        $record->timemodified = time();

        $existing = $DB->get_record('local_rtocompliance_courses', ['courseid' => $courseid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_rtocompliance_courses', $record);
        } else {
            $DB->insert_record('local_rtocompliance_courses', $record);
        }
    }

    protected function enrol_user_in_course($userid, $courseid, $rolename = 'student') {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => $rolename]);
        $this->getDataGenerator()->enrol_user($userid, $courseid, $roleid);
    }

    /**
     * Test: User not enrolled in any course does not require AVETMISS
     */
    public function test_user_not_enrolled_does_not_require_avetmiss() {
        $result = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertFalse($result, 'User not enrolled in any course should not require AVETMISS');
    }

    /**
     * Test: User enrolled only in non-recognised course does not require AVETMISS
     */
    public function test_user_in_nonrecognised_course_does_not_require_avetmiss() {
        $this->enrol_user_in_course($this->student1->id, $this->course_nonrecognised->id);

        $result = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertFalse($result, 'User in non-recognised course should not require AVETMISS');
    }

    /**
     * Test: User enrolled in recognised course requires AVETMISS
     */
    public function test_user_in_recognised_course_requires_avetmiss() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);

        $result = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertTrue($result, 'User in nationally recognised course should require AVETMISS');
    }

    /**
     * Test: User enrolled in both recognised and non-recognised courses requires AVETMISS
     */
    public function test_user_in_mixed_courses_requires_avetmiss() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);
        $this->enrol_user_in_course($this->student1->id, $this->course_nonrecognised->id);

        $result = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertTrue($result, 'User enrolled in at least one recognised course should require AVETMISS');
    }

    /**
     * Test: Teacher enrolled in recognised course still triggers requirement check
     * Note: Role-based filtering should be handled at the UI/page level, not in this function
     */
    public function test_teacher_in_recognised_course() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->teacher->id, $this->course_recognised->id, 'editingteacher');

        $result = local_rtocompliance_user_requires_avetmiss($this->teacher->id);
        $this->assertTrue($result, 'Function returns true for any user enrolled in recognised course - role filtering is UI responsibility');
    }

    /**
     * Test: Multiple students with different enrolments
     */
    public function test_multiple_students_different_requirements() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);

        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);
        $this->enrol_user_in_course($this->student2->id, $this->course_nonrecognised->id);

        $result1 = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $result2 = local_rtocompliance_user_requires_avetmiss($this->student2->id);

        $this->assertTrue($result1, 'Student1 in recognised course should require AVETMISS');
        $this->assertFalse($result2, 'Student2 in non-recognised course should not require AVETMISS');
    }

    /**
     * Test: Course marked as not nationally recognised after being recognised
     */
    public function test_course_unmarked_as_recognised() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);

        $result_before = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertTrue($result_before, 'Should require AVETMISS when course is recognised');

        $this->mark_course_nationally_recognised($this->course_recognised->id, 0);

        $result_after = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertFalse($result_after, 'Should not require AVETMISS after course is unmarked');
    }

    /**
     * Test: Get nationally recognised courses for user
     */
    public function test_get_user_nationally_recognised_courses() {
        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);
        $this->enrol_user_in_course($this->student1->id, $this->course_nonrecognised->id);

        $courses = local_rtocompliance_get_user_nationally_recognised_courses($this->student1->id);

        $this->assertCount(1, $courses, 'Should return only 1 nationally recognised course');
        $course = reset($courses);
        $this->assertEquals($this->course_recognised->id, $course->id);
        $this->assertEquals('BSB50420', $course->qualificationcode);
    }

    /**
     * Test: User with no nationally recognised courses returns empty array
     */
    public function test_get_user_nationally_recognised_courses_empty() {
        $this->enrol_user_in_course($this->student1->id, $this->course_nonrecognised->id);

        $courses = local_rtocompliance_get_user_nationally_recognised_courses($this->student1->id);

        $this->assertCount(0, $courses, 'Should return empty array for user with no recognised courses');
    }

    /**
     * Test: Suspended enrolment does not trigger AVETMISS requirement
     */
    public function test_suspended_enrolment_does_not_require_avetmiss() {
        global $DB;

        $this->mark_course_nationally_recognised($this->course_recognised->id, 1);
        $this->enrol_user_in_course($this->student1->id, $this->course_recognised->id);

        $result_active = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertTrue($result_active, 'Active enrolment should require AVETMISS');

        $enrol = $DB->get_record('enrol', ['courseid' => $this->course_recognised->id, 'enrol' => 'manual']);
        $ue = $DB->get_record('user_enrolments', ['enrolid' => $enrol->id, 'userid' => $this->student1->id]);
        $ue->status = 1;
        $DB->update_record('user_enrolments', $ue);

        $result_suspended = local_rtocompliance_user_requires_avetmiss($this->student1->id);
        $this->assertFalse($result_suspended, 'Suspended enrolment should not require AVETMISS');
    }
}
