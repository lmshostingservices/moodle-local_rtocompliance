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
 * PHPUnit tests for the AVETMISS profile gate (v6.3.0).
 *
 * Covers the field-completeness rules that decide whether a student is held at
 * My AVETMISS Profile, and the guard conditions that must NEVER hold someone
 * (gate disabled, site admin, staff with the bypass capability, a student not in
 * nationally recognised training, a student whose profile is already complete).
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
 * @coversDefaultClass \local_rtocompliance
 */
class profile_gate_test extends \advanced_testcase {
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->student = $this->getDataGenerator()->create_user([
            'firstname' => 'Gate', 'lastname' => 'Student', 'email' => 'gate.student@test.com',
        ]);
        $this->course = $this->getDataGenerator()->create_course([
            'fullname' => 'TLI50816 Diploma of Customs Broking', 'shortname' => 'TLI50816',
        ]);

        set_config('enforceprofile', 1, 'local_rtocompliance');
        unset_config('mandatoryprofilefields', 'local_rtocompliance');
    }

    /**
     * Flag the course as nationally recognised so enrolments in it are reportable.
     */
    protected function mark_recognised(int $courseid): void {
        global $DB;
        $DB->insert_record('local_rtocompliance_courses', (object)[
            'courseid' => $courseid,
            'nationallyrecognised' => 1,
            'qualificationcode' => 'TLI50816',
            'qualificationname' => 'Diploma of Customs Broking',
            'cricosregistered' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Insert a students row. Pass overrides to fill in AVETMISS answers.
     */
    protected function make_student_record(int $userid, array $overrides = []): int {
        global $DB;
        $record = (object)array_merge([
            'userid' => $userid,
            'usi' => null,
            'usiverified' => 0,
            'dateofbirth' => null,
            'sex' => null,
            'indigenousstatus' => '@',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'englishproficiency' => '@',
            'disabilityflag' => 'N',
            'suburb' => null,
            'postcode' => null,
            'statecode' => null,
            'highestschoollevel' => '@@',
            'atschoolflag' => 'N',
            'labourforcestatus' => '@@',
            'studyreason' => '@@',
            'prioreducationflag' => '@',
            'surveycontactstatus' => 'N',
            'profilecomplete' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);
        return (int)$DB->insert_record('local_rtocompliance_students', $record);
    }

    /**
     * Every AVETMISS answer filled in with a real (non-sentinel) value.
     */
    protected function complete_answers(): array {
        return [
            'usi' => 'D82MGTUMGS',
            'dateofbirth' => 852076800,
            'sex' => 'M',
            'suburb' => 'Melbourne',
            'postcode' => '3000',
            'statecode' => '2',
            'indigenousstatus' => 'A',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'labourforcestatus' => '01',
            'highestschoollevel' => '12',
        ];
    }

    // ── Field-level rules ────────────────────────────────────────────────────

    public function test_blank_and_sentinel_values_count_as_missing(): void {
        // Empty values.
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('usi', null));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('usi', ''));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('suburb', '   '));

        // A date of birth of 0 is not a date of birth.
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('dateofbirth', 0));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('dateofbirth', null));

        // AVETMISS "not stated" sentinels of every width.
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('sex', '@'));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('labourforcestatus', '@@'));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('highestschoollevel', '@@'));
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('countryofbirth', '@@@@'));
        // Robust to a corrupted single '@' in a two-character column.
        $this->assertTrue(local_rtocompliance_avetmiss_value_missing('labourforcestatus', '@'));
    }

    public function test_real_answers_do_not_count_as_missing(): void {
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('usi', 'D82MGTUMGS'));
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('dateofbirth', 852076800));
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('sex', 'F'));
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('postcode', '3000'));
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('countryofbirth', '1101'));
        // A leading-zero code is a real answer, not an empty one.
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('labourforcestatus', '01'));
    }

    public function test_profilecomplete_uses_the_fixed_field_set(): void {
        // A brand new row carrying only the NOT NULL sentinel defaults is incomplete.
        $fresh = (object)[
            'indigenousstatus' => '@', 'countryofbirth' => '1101', 'languageathome' => '1201',
            'highestschoollevel' => '@@', 'labourforcestatus' => '@@',
        ];
        $this->assertSame(0, local_rtocompliance_calculate_profilecomplete($fresh));

        $full = (object)$this->complete_answers();
        $this->assertSame(1, local_rtocompliance_calculate_profilecomplete($full));

        // One field away from complete.
        $nodob = clone $full;
        $nodob->dateofbirth = 0;
        $this->assertSame(0, local_rtocompliance_calculate_profilecomplete($nodob));

        // Narrowing the GATE list must not relax the completeness flag — it feeds
        // certificate issuance and NAT readiness.
        set_config('mandatoryprofilefields', 'sex', 'local_rtocompliance');
        $this->assertSame(0, local_rtocompliance_calculate_profilecomplete($nodob));
    }

    public function test_usi_is_not_required_by_the_gate_by_default(): void {
        $fields = local_rtocompliance_avetmiss_mandatory_fields();
        $this->assertNotContains('usi', $fields,
            'A student cannot obtain a USI on demand, so it must not block site access by default');
        $this->assertContains('dateofbirth', $fields);
        $this->assertCount(10, $fields);

        // ... but it IS part of the definition of a complete profile.
        $this->assertContains('usi', local_rtocompliance_avetmiss_all_fields());
    }

    public function test_mandatory_field_setting_is_honoured_and_cannot_be_emptied(): void {
        set_config('mandatoryprofilefields', 'dateofbirth,usi', 'local_rtocompliance');
        $this->assertSame(['usi', 'dateofbirth'], array_values(
            array_intersect(local_rtocompliance_avetmiss_all_fields(),
                local_rtocompliance_avetmiss_mandatory_fields())));

        // Unknown keys are ignored, and an all-unknown setting falls back to the
        // default rather than silently disabling collection.
        set_config('mandatoryprofilefields', 'notafield', 'local_rtocompliance');
        $this->assertCount(10, local_rtocompliance_avetmiss_mandatory_fields());
    }

    public function test_missing_field_list_names_the_gaps(): void {
        $this->make_student_record($this->student->id, ['dateofbirth' => null, 'sex' => 'M']);
        $missing = local_rtocompliance_get_missing_avetmiss_fields($this->student->id);

        $this->assertArrayHasKey('dateofbirth', $missing);
        $this->assertArrayNotHasKey('sex', $missing, 'An answered field must not be reported as missing');
        // Labels are human-readable, not raw column names.
        $this->assertNotSame('dateofbirth', $missing['dateofbirth']);
    }

    public function test_student_with_no_record_at_all_is_missing_everything(): void {
        $missing = local_rtocompliance_get_missing_avetmiss_fields($this->student->id);
        $this->assertCount(count(local_rtocompliance_avetmiss_mandatory_fields()), $missing);
    }

    // ── Gate guard conditions ────────────────────────────────────────────────

    public function test_gate_holds_an_incomplete_student_in_recognised_training(): void {
        $this->mark_recognised($this->course->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id);
        $this->make_student_record($this->student->id);
        $this->setUser($this->student);

        $result = local_rtocompliance_profile_gate_applies($this->student->id);
        $this->assertIsArray($result, 'A student missing AVETMISS data must be held');
        $this->assertNotEmpty($result['missing']);
    }

    public function test_gate_releases_a_complete_student(): void {
        $this->mark_recognised($this->course->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id);
        $this->make_student_record($this->student->id, $this->complete_answers());
        $this->setUser($this->student);

        $this->assertFalse(local_rtocompliance_profile_gate_applies($this->student->id));
    }

    public function test_gate_ignores_students_not_in_recognised_training(): void {
        // Enrolled, but the course is not flagged and there are no AVETMISS
        // enrolment records — nothing about this learner is reportable.
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id);
        $this->make_student_record($this->student->id);
        $this->setUser($this->student);

        $this->assertFalse(local_rtocompliance_profile_gate_applies($this->student->id));
    }

    public function test_gate_is_off_when_the_setting_is_off(): void {
        set_config('enforceprofile', 0, 'local_rtocompliance');
        $this->mark_recognised($this->course->id);
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id);
        $this->make_student_record($this->student->id);
        $this->setUser($this->student);

        $this->assertFalse(local_rtocompliance_profile_gate_applies($this->student->id));
    }

    public function test_gate_never_holds_a_site_administrator(): void {
        $this->mark_recognised($this->course->id);
        $admin = get_admin();
        $this->getDataGenerator()->enrol_user($admin->id, $this->course->id);
        $this->make_student_record($admin->id);
        $this->setAdminUser();

        $this->assertFalse(local_rtocompliance_profile_gate_applies($admin->id));
    }

    public function test_gate_never_holds_staff_with_the_bypass_capability(): void {
        global $DB;
        $this->mark_recognised($this->course->id);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id);
        $this->make_student_record($teacher->id);

        // Grant the bypass at system level, as the manager archetype does.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/rtocompliance:bypassprofilegate', CAP_ALLOW, $roleid,
            \context_system::instance()->id, true);
        role_assign($roleid, $teacher->id, \context_system::instance()->id);

        $this->setUser($teacher);
        $this->assertFalse(local_rtocompliance_profile_gate_applies($teacher->id),
            'A trainer who is also a learner must not be locked out of the site they run');
    }

    public function test_my_profile_and_logout_are_always_reachable(): void {
        $allowlist = local_rtocompliance_profile_gate_allowlist();
        $this->assertContains('local/rtocompliance/my_profile.php', $allowlist);
        $this->assertContains('login/logout.php', $allowlist);
        $this->assertContains('login/index.php', $allowlist);
        $this->assertContains('user/policy.php', $allowlist);
    }
}
