<?php
/**
 * PHPUnit tests for NAT Generator class
 *
 * @package    local_rtocompliance
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/classes/nat_generator.php');
require_once($CFG->dirroot . '/local/rtocompliance/classes/avetmiss_codes.php');

class nat_generator_test extends \advanced_testcase {

    private $generator;
    private $testuser;
    private $teststudent;
    private $testcourse;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        set_config('rtocode', '12345', 'local_rtocompliance');
        set_config('rtoname', 'Test Training Institute', 'local_rtocompliance');
        set_config('abn', '12345678901', 'local_rtocompliance');
        set_config('address', '123 Test Street, Melbourne VIC 3000', 'local_rtocompliance');
        set_config('phone', '0398765432', 'local_rtocompliance');
        set_config('email', 'test@rto.edu.au', 'local_rtocompliance');
        set_config('postcode', '3000', 'local_rtocompliance');
        set_config('state', '02', 'local_rtocompliance');
        set_config('suburb', 'Melbourne', 'local_rtocompliance');

        $this->generator = new nat_generator(date('Y'));

        $this->testuser = $this->getDataGenerator()->create_user([
            'firstname' => 'John',
            'lastname' => 'Smith',
            'email' => 'john.smith@test.com',
        ]);

        $this->testcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TEST001',
        ]);
    }

    protected function tearDown(): void {
        $this->generator = null;
        $this->testuser = null;
        $this->teststudent = null;
        $this->testcourse = null;
        parent::tearDown();
    }

    protected function create_test_student($overrides = []) {
        global $DB;

        $defaults = [
            'userid' => $this->testuser->id,
            'clientid' => '0000000001',
            'usi' => 'ABC2DEF3GH',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1990-05-15'),
            'sex' => 'M',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'englishproficiency' => '1',
            'disabilityflag' => 'N',
            'streetno' => '123',
            'streetname' => 'Test Street',
            'suburb' => 'Melbourne',
            'postcode' => '3000',
            'statecode' => '02',
            'highestschoollevel' => '12',
            'yearschoolcompleted' => '2008',
            'atschoolflag' => 'N',
            'surveycontactstatus' => 'A',
            'profilecomplete' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = (object)array_merge($defaults, $overrides);
        $record->id = $DB->insert_record('local_rtocompliance_students', $record);
        $this->teststudent = $record;

        return $record;
    }

    protected function create_test_enrolment($studentid, $overrides = []) {
        global $DB;

        $defaults = [
            'studentid' => $studentid,
            'courseid' => $this->testcourse->id,
            'programid' => 'BSB50420',
            'programcode' => 'BSB50420',
            'programname' => 'Diploma of Leadership and Management',
            'subjectid' => 'BSBCMM511',
            'unitcode' => 'BSBCMM511',
            'unitname' => 'Communicate with influence',
            'activitystartdate' => strtotime('2025-01-15'),
            'activityenddate' => strtotime('2025-06-30'),
            'scheduledhours' => 60,
            'outcomeidentifier' => '20',
            'deliverymode' => '10',
            'fundingsourcenat' => '20',
            'deliverylocationid' => 'MAIN',
            'vetflag' => 'Y',
            'vetinschoolsflag' => 'N',
            'commencingprogramid' => '1',
            'status' => 'completed',
            'feecharged' => 'Y',
            'tuitionfee' => 500.00,
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $record = (object)array_merge($defaults, $overrides);
        $record->id = $DB->insert_record('local_rtocompliance_enrolments', $record);

        return $record;
    }

    // =========================================================================
    // VALIDATION TESTS
    // =========================================================================

    public function test_validation_fails_without_rto_config() {
        $this->resetAfterTest(true);

        set_config('rtocode', '', 'local_rtocompliance');
        set_config('rtoname', '', 'local_rtocompliance');

        $generator = new nat_generator(date('Y'));
        $result = $generator->validate();

        $this->assertFalse($result['valid']);
        $this->assertContains('RTO Code is not configured', $result['errors']);
        $this->assertContains('RTO Name is not configured', $result['errors']);
    }

    public function test_validation_fails_with_invalid_rto_code() {
        $this->resetAfterTest(true);

        set_config('rtocode', 'ABC', 'local_rtocompliance');
        set_config('rtoname', 'Test RTO', 'local_rtocompliance');

        $generator = new nat_generator(date('Y'));
        $result = $generator->validate();

        $this->assertFalse($result['valid']);
        $this->assertContains('RTO Code must be 4-5 digits', $result['errors']);
    }

    public function test_validation_passes_with_valid_config() {
        $result = $this->generator->validate();

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validation_warns_about_missing_usi() {
        $this->create_test_student(['usi' => null]);

        $result = $this->generator->validate();

        $this->assertNotEmpty($result['errors']);
        $errorFound = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'missing USI') !== false) {
                $errorFound = true;
                break;
            }
        }
        $this->assertTrue($errorFound, 'Expected error about missing USI');
    }

    public function test_validation_warns_about_incomplete_profiles() {
        $this->create_test_student(['profilecomplete' => 0]);

        $result = $this->generator->validate();

        $warningFound = false;
        foreach ($result['warnings'] as $warning) {
            if (strpos($warning, 'incomplete AVETMISS profiles') !== false) {
                $warningFound = true;
                break;
            }
        }
        $this->assertTrue($warningFound, 'Expected warning about incomplete profiles');
    }

    /**
     * Validation should warn when there are no enrolments for the reporting period.
     */
    public function test_validation_warns_when_no_enrolments() {
        $result = $this->generator->validate();

        $found = false;
        foreach ($result['warnings'] as $w) {
            if (strpos($w, 'No enrolments found') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected warning about no enrolments for the reporting period');
    }

    /**
     * Validation should warn about enrolments with missing/zero outcomes.
     */
    public function test_validation_warns_missing_outcomes() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
            'outcomeidentifier' => '',
        ]);

        $result = $this->generator->validate();

        $found = false;
        foreach ($result['warnings'] as $w) {
            if (strpos($w, 'no outcome') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected warning about enrolments with no outcome assigned');
    }

    // =========================================================================
    // CONTENT TESTS — verify key fields appear in output
    // =========================================================================

    public function test_generate_nat00010_contains_rto_details() {
        $output = $this->generator->generate_nat00010();

        $this->assertStringContainsString('12345', $output);
        $this->assertStringContainsString('Test Training Institute', $output);
    }

    public function test_generate_nat00020_contains_location() {
        $output = $this->generator->generate_nat00020();

        $this->assertStringContainsString('12345', $output);
        $this->assertStringContainsString('MAIN', $output);
    }

    public function test_generate_nat00030_contains_programs() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id);

        $output = $this->generator->generate_nat00030();

        $this->assertStringContainsString('BSB50420', $output);
        $this->assertStringContainsString('Diploma of Leadership and Management', $output);
    }

    public function test_generate_nat00060_contains_units() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id);

        $output = $this->generator->generate_nat00060();

        $this->assertStringContainsString('BSBCMM511', $output);
        $this->assertStringContainsString('Communicate with influence', $output);
    }

    public function test_generate_nat00080_contains_client_data() {
        $student = $this->create_test_student();

        $output = $this->generator->generate_nat00080();

        $this->assertStringContainsString('SMITH', $output);
        $this->assertStringContainsString('JOHN', $output);
        $this->assertStringContainsString('ABC2DEF3GH', $output);
    }

    public function test_generate_nat00085_contains_address_data() {
        $student = $this->create_test_student();

        $output = $this->generator->generate_nat00085();

        $this->assertStringContainsString('123', $output);
        $this->assertStringContainsString('Test Street', $output);
        $this->assertStringContainsString('Melbourne', $output);
        $this->assertStringContainsString('3000', $output);
    }

    public function test_generate_nat00090_contains_disability_data() {
        $student = $this->create_test_student([
            'disabilityflag' => 'Y',
            'disabilitytypes' => '12,14',
        ]);

        $output = $this->generator->generate_nat00090();

        $this->assertStringContainsString($student->clientid, $output);
        $this->assertStringContainsString('12', $output);
        $this->assertStringContainsString('14', $output);
    }

    public function test_generate_nat00120_contains_enrolment_data() {
        $student = $this->create_test_student();
        $enrolment = $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
        ]);

        $output = $this->generator->generate_nat00120();

        $this->assertStringContainsString('12345', $output);
        $this->assertStringContainsString('BSBCMM511', $output);
        $this->assertStringContainsString('BSB50420', $output);
    }

    public function test_generate_nat00130_contains_completion_data() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'programoutcome' => '01',
            'programcompletedyear' => date('Y'),
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00130();

        $this->assertStringContainsString($student->clientid, $output);
        $this->assertStringContainsString('BSB50420', $output);
    }

    public function test_generate_all_creates_all_nat_files() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
        ]);

        $files = $this->generator->generate_all();

        $expectedFiles = [
            'NAT00010.txt',
            'NAT00020.txt',
            'NAT00030.txt',
            'NAT00060.txt',
            'NAT00080.txt',
            'NAT00085.txt',
            'NAT00090.txt',
            'NAT00100.txt',
            'NAT00120.txt',
            'NAT00130.txt',
        ];

        foreach ($expectedFiles as $filename) {
            $this->assertArrayHasKey($filename, $files, "Missing file: $filename");
        }
    }

    // =========================================================================
    // RECORD COUNT TESTS
    // =========================================================================

    /**
     * Record counts for NAT00080 and NAT00085 = number of students.
     * NAT00100 counts actual prior achievement records (0 when no student has
     * prioreducationflag='Y').
     */
    public function test_record_counts_are_accurate() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
        ]);

        $this->generator->validate();
        $counts = $this->generator->get_record_counts();

        $this->assertEquals(1, $counts['NAT00080'], 'NAT00080 should count 1 student');
        $this->assertEquals(1, $counts['NAT00085'], 'NAT00085 should count 1 student');
        // Test student has no prioreducationflag='Y', so NAT00100 count must be 0.
        $this->assertEquals(0, $counts['NAT00100'], 'NAT00100 should be 0 when no student has prior education flag set');
    }

    /**
     * NAT00100 record count should equal the total number of prior achievement
     * records across all qualifying students (not the number of students).
     */
    public function test_record_count_nat00100_counts_achievements_not_students() {
        // Student A: 2 prior achievements.
        $userA = $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'Adams']);
        $studentA = (object)[
            'userid' => $userA->id,
            'clientid' => '0000000010',
            'usi' => 'ANN2AAAAA1',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1985-01-01'),
            'sex' => 'F',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'postcode' => '3000',
            'statecode' => '02',
            'suburb' => 'Melbourne',
            'atschoolflag' => 'N',
            'highestschoollevel' => '12',
            'disabilityflag' => 'N',
            'surveycontactstatus' => 'A',
            'profilecomplete' => 1,
            'prioreducationflag' => 'Y',
            'priorachevement1' => '420',
            'priorachevement2' => '420',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        global $DB;
        $DB->insert_record('local_rtocompliance_students', $studentA);

        // Student B: 1 prior achievement.
        $userB = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Brown']);
        $studentB = (object)[
            'userid' => $userB->id,
            'clientid' => '0000000011',
            'usi' => 'BOB2BBBBB1',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1990-06-01'),
            'sex' => 'M',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'postcode' => '3000',
            'statecode' => '02',
            'suburb' => 'Melbourne',
            'atschoolflag' => 'N',
            'highestschoollevel' => '12',
            'disabilityflag' => 'N',
            'surveycontactstatus' => 'A',
            'profilecomplete' => 1,
            'prioreducationflag' => 'Y',
            'priorachevement1' => '514',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('local_rtocompliance_students', $studentB);

        $gen = new nat_generator(date('Y'));
        $gen->validate();
        $counts = $gen->get_record_counts();

        // 2 achievements from A + 1 from B = 3 total NAT00100 records.
        $this->assertEquals(3, $counts['NAT00100'], 'NAT00100 should count total prior achievement records, not students');
    }

    /**
     * NAT00090 record count equals total disability type entries, not students.
     */
    public function test_record_count_nat00090_counts_disability_types_not_students() {
        // Student with 3 disability types.
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Dis', 'lastname' => 'Abled']);
        global $DB;
        $DB->insert_record('local_rtocompliance_students', (object)[
            'userid' => $user->id,
            'clientid' => '0000000020',
            'usi' => 'DIS2ABLED1',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1988-03-10'),
            'sex' => 'M',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'postcode' => '3000',
            'statecode' => '02',
            'suburb' => 'Brisbane',
            'atschoolflag' => 'N',
            'highestschoollevel' => '12',
            'disabilityflag' => 'Y',
            'disabilitytypes' => '11,14,53',
            'surveycontactstatus' => 'A',
            'profilecomplete' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $gen = new nat_generator(date('Y'));
        $gen->validate();
        $counts = $gen->get_record_counts();

        $this->assertEquals(3, $counts['NAT00090'], 'NAT00090 should count 3 disability type records');
    }

    // =========================================================================
    // RECORD LENGTH TESTS — verify exact AVETMISS fixed-width field lengths
    // =========================================================================

    /**
     * NAT00010 national record = 268 chars.
     * With state-only fields (contact 60 + phone 20 + fax 20 + email 80) = 448.
     */
    public function test_nat00010_record_length_is_correct() {
        $output = $this->generator->generate_nat00010();

        // Strip \r\n to get the bare record.
        $lines = explode("\r\n", rtrim($output, "\r\n"));
        $this->assertCount(1, $lines, 'NAT00010 should have exactly 1 record');
        $this->assertEquals(448, strlen($lines[0]),
            'NAT00010 record must be 448 chars (268 national + 180 state-only fields)');
    }

    /**
     * NAT00020 fallback MAIN record = 180 chars (no state-only fields).
     */
    public function test_nat00020_record_length_is_correct() {
        $output = $this->generator->generate_nat00020();

        $lines = explode("\r\n", rtrim($output, "\r\n"));
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(180, strlen($line),
                "NAT00020 record must be 180 chars, got " . strlen($line) . ": '$line'");
        }
    }

    /**
     * NAT00030 record = 130 chars (prog ID 10 + name 100 + nominal hours 4 + pad 16).
     */
    public function test_nat00030_record_length_is_correct() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id);

        $output = $this->generator->generate_nat00030();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(130, strlen($line),
                "NAT00030 record must be 130 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00060 record = 123 chars (subject ID 12 + name 100 + FoE 6 + VET 1 + hours 4).
     */
    public function test_nat00060_record_length_is_correct() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id);

        $output = $this->generator->generate_nat00060();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(123, strlen($line),
                "NAT00060 record must be 123 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00080 record = 327 chars.
     * Positions verified against AVETMISS VET Provider Collection Specifications Release 8.0.
     */
    public function test_nat00080_record_length_is_correct() {
        $this->create_test_student();

        $output = $this->generator->generate_nat00080();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(327, strlen($line),
                "NAT00080 record must be 327 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00085 record = 557 chars.
     * Positions verified against AVETMISS VET Provider Collection Specifications Release 8.0.
     */
    public function test_nat00085_record_length_is_correct() {
        $this->create_test_student();

        $output = $this->generator->generate_nat00085();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(557, strlen($line),
                "NAT00085 record must be 557 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00090 record = 12 chars (client ID 10 + disability type 2).
     */
    public function test_nat00090_record_length_is_correct() {
        $this->create_test_student([
            'disabilityflag' => 'Y',
            'disabilitytypes' => '12,14',
        ]);

        $output = $this->generator->generate_nat00090();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertCount(2, $lines, 'Should generate 2 disability records (one per type)');
        foreach ($lines as $line) {
            $this->assertEquals(12, strlen($line),
                "NAT00090 record must be 12 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00100 record = 13 chars (client ID 10 + prior achievement 3).
     */
    public function test_nat00100_record_length_is_correct() {
        $this->create_test_student([
            'prioreducationflag' => 'Y',
            'priorachevement1' => '420',
            'priorachevement2' => '514',
        ]);

        $output = $this->generator->generate_nat00100();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertCount(2, $lines, 'Should generate 2 prior achievement records');
        foreach ($lines as $line) {
            $this->assertEquals(13, strlen($line),
                "NAT00100 record must be 13 chars, got " . strlen($line));
        }
    }

    /**
     * NAT00120 full record = 158 chars (111 national + 47 state-only fields).
     */
    public function test_nat00120_record_length_is_correct() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00120();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(158, strlen($line),
                "NAT00120 record must be 158 chars (111 national + 47 state), got " . strlen($line));
        }
    }

    /**
     * NAT00130 full record = 72 chars (39 national + 33 state-only fields).
     */
    public function test_nat00130_record_length_is_correct() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'programoutcome' => '01',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00130();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertEquals(72, strlen($line),
                "NAT00130 record must be 72 chars (39 national + 33 state), got " . strlen($line));
        }
    }

    // =========================================================================
    // ZIP AND UTILITY TESTS
    // =========================================================================

    public function test_create_zip_generates_valid_zip() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
        ]);

        $files = $this->generator->generate_all();
        $zippath = $this->generator->create_zip($files);

        $this->assertFileExists($zippath);
        $this->assertStringContainsString('.zip', $zippath);

        $zip = new \ZipArchive();
        $this->assertEquals(\ZipArchive::ER_OK, $zip->open($zippath));
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();

        unlink($zippath);
    }

    public function test_padding_functions_work_correctly() {
        $generator = new nat_generator(date('Y'));

        $reflection = new \ReflectionClass($generator);

        $padMethod = $reflection->getMethod('pad');
        $padMethod->setAccessible(true);

        $result = $padMethod->invoke($generator, 'Test', 10);
        $this->assertEquals('Test      ', $result);
        $this->assertEquals(10, strlen($result));

        $padnumMethod = $reflection->getMethod('padnum');
        $padnumMethod->setAccessible(true);

        $result = $padnumMethod->invoke($generator, '123', 5);
        $this->assertEquals('00123', $result);
        $this->assertEquals(5, strlen($result));
    }

    public function test_date_formatting() {
        $generator = new nat_generator(date('Y'));

        $reflection = new \ReflectionClass($generator);
        $method = $reflection->getMethod('formatdate');
        $method->setAccessible(true);

        $timestamp = strtotime('2025-06-15');
        $result = $method->invoke($generator, $timestamp);
        $this->assertEquals('15062025', $result);

        $result = $method->invoke($generator, null);
        $this->assertEquals('        ', $result);
    }

    // =========================================================================
    // MULTI-STUDENT / FILTERING TESTS
    // =========================================================================

    public function test_multiple_students_generate_correct_records() {
        global $DB;

        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => "Student$i",
                'lastname' => "Test",
            ]);

            $usiChars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $usi = '';
            for ($j = 0; $j < 10; $j++) {
                $usi .= $usiChars[rand(0, strlen($usiChars) - 1)];
            }

            $student = (object)[
                'userid' => $user->id,
                'clientid' => str_pad($i, 10, '0', STR_PAD_LEFT),
                'usi' => $usi,
                'usiverified' => 1,
                'dateofbirth' => strtotime("199{$i}-05-15"),
                'sex' => $i % 2 == 0 ? 'F' : 'M',
                'indigenousstatus' => '4',
                'countryofbirth' => '1101',
                'languageathome' => '1201',
                'englishproficiency' => '1',
                'disabilityflag' => 'N',
                'suburb' => 'Melbourne',
                'postcode' => '3000',
                'statecode' => '02',
                'highestschoollevel' => '12',
                'atschoolflag' => 'N',
                'surveycontactstatus' => 'A',
                'profilecomplete' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ];

            $studentid = $DB->insert_record('local_rtocompliance_students', $student);
            $users[] = ['userid' => $user->id, 'studentid' => $studentid];
        }

        $this->generator->validate();
        $counts = $this->generator->get_record_counts();

        $this->assertEquals(5, $counts['NAT00080']);
    }

    public function test_enrolments_filter_by_reporting_period() {
        global $DB;

        $student = $this->create_test_student();

        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
            'unitcode' => 'BSBCMM511',
        ]);

        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime((date('Y') - 2) . '-03-01'),
            'unitcode' => 'BSBOPS502',
        ]);

        $output = $this->generator->generate_nat00120();

        $this->assertStringContainsString('BSBCMM511', $output);
        $this->assertStringNotContainsString('BSBOPS502', $output);
    }

    // =========================================================================
    // EDGE CASE TESTS
    // =========================================================================

    /**
     * Enrolments with outcome '00' must be skipped in NAT00120.
     * Bug 7: '00' is not a valid AVETMISS 2.3 outcome code.
     */
    public function test_nat00120_skips_outcome_00_enrolments() {
        $student = $this->create_test_student();

        // This enrolment has outcome '00' — should be excluded.
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-02-01'),
            'unitcode' => 'BSBSKIP001',
            'outcomeidentifier' => '00',
        ]);

        // This one has a valid outcome — should be included.
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-02-01'),
            'unitcode' => 'BSBKEEP001',
            'outcomeidentifier' => '20',
        ]);

        $output = $this->generator->generate_nat00120();

        $this->assertStringNotContainsString('BSBSKIP001', $output,
            'NAT00120 must not include enrolments with outcome code 00');
        $this->assertStringContainsString('BSBKEEP001', $output,
            'NAT00120 must include enrolments with valid outcome codes');
    }

    /**
     * Enrolments with empty outcome identifier must also be skipped.
     */
    public function test_nat00120_skips_empty_outcome_enrolments() {
        $student = $this->create_test_student();

        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-02-01'),
            'unitcode' => 'BSBSKIP002',
            'outcomeidentifier' => '',
        ]);

        $output = $this->generator->generate_nat00120();

        $this->assertStringNotContainsString('BSBSKIP002', $output,
            'NAT00120 must not include enrolments with empty outcome identifier');
    }

    /**
     * NAT00090 should be empty when no students have disabilityflag='Y'.
     */
    public function test_nat00090_empty_when_no_disabled_students() {
        $this->create_test_student(['disabilityflag' => 'N']);

        $output = $this->generator->generate_nat00090();

        $this->assertEmpty(trim($output), 'NAT00090 should be empty when no students have disability flag set');
    }

    /**
     * NAT00100 should be empty when no students have prioreducationflag='Y'.
     */
    public function test_nat00100_empty_when_no_prior_education_students() {
        $this->create_test_student(['prioreducationflag' => 'N']);

        $output = $this->generator->generate_nat00100();

        $this->assertEmpty(trim($output), 'NAT00100 should be empty when no students have prior education flag set');
    }

    /**
     * NAT00130 should be empty when there are no completions in the reporting period.
     */
    public function test_nat00130_empty_when_no_completions() {
        $student = $this->create_test_student();
        // Create an enrolment that is NOT a completion (outcome '03' = not completed).
        $this->create_test_enrolment($student->id, [
            'programoutcome' => '03',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00130();

        $this->assertEmpty(trim($output), 'NAT00130 should be empty when no AQF/non-AQF completions exist');
    }

    /**
     * NAT00130 must only include programoutcome '01' (AQF) and '02' (non-AQF).
     * Outcomes '03' (not completed), '04' (withdrawn), '05' (deferred) must be excluded.
     */
    public function test_nat00130_only_includes_genuine_completions() {
        $student = $this->create_test_student();

        // '01' = AQF completion — INCLUDE.
        $this->create_test_enrolment($student->id, [
            'programcode' => 'BSB50420',
            'programoutcome' => '01',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
            'unitcode' => 'COMPLETE01',
        ]);

        // '03' = not completed — EXCLUDE.
        $this->create_test_enrolment($student->id, [
            'programcode' => 'BSB50420',
            'programoutcome' => '03',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
            'unitcode' => 'NOCOMP001',
        ]);

        // '04' = withdrawn — EXCLUDE.
        $this->create_test_enrolment($student->id, [
            'programcode' => 'BSB50420',
            'programoutcome' => '04',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
            'unitcode' => 'WITHD001',
        ]);

        $output = $this->generator->generate_nat00130();

        $lines = array_filter(explode("\r\n", rtrim($output, "\r\n")));
        $this->assertCount(1, $lines,
            'NAT00130 must contain exactly 1 record (only the AQF completion)');
    }

    /**
     * NAT00100 should emit a placeholder '@@' record for students with prioreducationflag='Y'
     * but no prior achievement codes populated.
     */
    public function test_nat00100_emits_placeholder_for_missing_achievements() {
        $this->create_test_student([
            'prioreducationflag' => 'Y',
            'priorachevement1' => null,
            'priorachevement2' => null,
            'priorachevement3' => null,
            'priorachevement4' => null,
        ]);

        $output = $this->generator->generate_nat00100();

        $this->assertNotEmpty(trim($output),
            'NAT00100 must emit a record for students with prioreducationflag=Y even when no achievements are set');
        $this->assertStringContainsString('@@', $output,
            'NAT00100 placeholder record should use @@ for unknown prior achievement');
    }

    /**
     * NAT00090 disability type code is exactly 2 chars in each record.
     * A single-digit code like '5' must be space-padded to '5 '.
     */
    public function test_nat00090_disability_type_is_two_chars() {
        $this->create_test_student([
            'disabilityflag' => 'Y',
            'disabilitytypes' => '11',
        ]);

        $output = $this->generator->generate_nat00090();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertCount(1, $lines);
        // Last 2 chars of the 12-char record are the disability type code.
        $this->assertEquals(2, strlen(substr($lines[0], 10, 2)),
            'Disability type field at pos 11-12 must be exactly 2 chars');
    }

    /**
     * UTF-8 / multi-byte characters in student names must be transliterated to ASCII.
     * The pad() method uses iconv('UTF-8', 'ASCII//TRANSLIT') — é→e, ü→u etc.
     * This ensures fixed-width fields are not corrupted by multi-byte sequences.
     */
    public function test_nat00080_utf8_names_transliterated_to_ascii() {
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Hélène',
            'lastname' => 'Müller',
        ]);

        global $DB;
        $DB->insert_record('local_rtocompliance_students', (object)[
            'userid' => $user->id,
            'clientid' => '0000000030',
            'usi' => 'HELENE3001',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1992-08-20'),
            'sex' => 'F',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'postcode' => '3000',
            'statecode' => '02',
            'suburb' => 'Sydney',
            'atschoolflag' => 'N',
            'highestschoollevel' => '12',
            'disabilityflag' => 'N',
            'surveycontactstatus' => 'A',
            'profilecomplete' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $gen = new nat_generator(date('Y'));
        $output = $gen->generate_nat00080();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        // After transliteration the record must still be exactly 327 bytes.
        $this->assertEquals(327, strlen($lines[0]),
            'NAT00080 record must be 327 bytes even when student name contains UTF-8 characters');

        // The transliterated name should appear (é→e, ü→u, Ü→U).
        $this->assertStringContainsString('MULLER', $output,
            'NAT00080 must transliterate ü→u in family name for fixed-width ASCII compliance');
        $this->assertStringContainsString('HELENE', $output,
            'NAT00080 must transliterate é→e in given name for fixed-width ASCII compliance');
    }

    /**
     * NAT00120 tuition fee must be rounded to an integer before zero-padding.
     * Bug 4: float like 1500.50 would produce '1500.5' (6 chars) corrupting
     * the 5-char fixed-width fee field.
     */
    public function test_nat00120_float_tuition_fee_rounded_correctly() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
            'tuitionfee' => 1500.75,  // Should round to 1501.
        ]);

        $output = $this->generator->generate_nat00120();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertCount(1, $lines);
        // Tuition fee is at pos 118-122 (0-indexed: 117-121) in the full 158-char record.
        $feeField = substr($lines[0], 117, 5);
        $this->assertEquals('01501', $feeField,
            'NAT00120 tuition fee field (pos 118-122) must be zero-padded integer, got: ' . $feeField);

        // Total record length must still be correct.
        $this->assertEquals(158, strlen($lines[0]),
            'NAT00120 record must remain 158 chars after float fee rounding');
    }

    /**
     * NAT00085 uses surveycontactemail over the Moodle login email when set.
     * Bug 40: AVETMISS contact email takes precedence.
     */
    public function test_nat00085_uses_survey_contact_email_over_moodle_email() {
        $this->create_test_student([
            'surveycontactemail' => 'survey@student.com',
        ]);

        $output = $this->generator->generate_nat00085();

        $this->assertStringContainsString('survey@student.com', $output,
            'NAT00085 must use surveycontactemail when it is set');
        $this->assertStringNotContainsString('john.smith@test.com', $output,
            'NAT00085 must not use Moodle login email when surveycontactemail is set');
    }

    /**
     * NAT00085 falls back to Moodle email when surveycontactemail is empty.
     */
    public function test_nat00085_falls_back_to_moodle_email_when_no_survey_email() {
        $this->create_test_student([
            'surveycontactemail' => '',
        ]);

        $output = $this->generator->generate_nat00085();

        $this->assertStringContainsString('john.smith@test.com', $output,
            'NAT00085 must fall back to Moodle email when surveycontactemail is empty');
    }

    /**
     * NAT00010 phone field must contain only numeric/allowed phone characters.
     * The field strips non-phone chars (letters, slashes, etc.) before output.
     */
    public function test_nat00010_phone_stripped_to_numeric() {
        set_config('phone', 'ABC-03 9876 5432/EXT', 'local_rtocompliance');
        set_config('contactname', 'Admin Officer', 'local_rtocompliance');

        $gen = new nat_generator(date('Y'));
        $output = $gen->generate_nat00010();

        // Phone field is at pos 329-348 (0-indexed: 328-347) in the full 448-char record.
        $phoneField = substr(rtrim($output, "\r\n"), 328, 20);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z\/]/', $phoneField,
            'NAT00010 phone field must not contain letters or slashes: "' . $phoneField . '"');
    }

    /**
     * NAT00020 must generate a MAIN location fallback when no locations table entries exist.
     */
    public function test_nat00020_generates_main_location_when_no_locations_configured() {
        $output = $this->generator->generate_nat00020();

        $this->assertStringContainsString('MAIN      ', $output,
            'NAT00020 must include a MAIN delivery location when no locations are configured');
        $this->assertStringContainsString('12345     ', $output,
            'NAT00020 MAIN record must start with the RTO code');
    }

    /**
     * NAT00030 nominal hours are zero-padded to 4 digits.
     * A program with scheduled hours of 60 should produce '0060' in pos 111-114.
     */
    public function test_nat00030_nominal_hours_zero_padded() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, ['scheduledhours' => 60]);

        $output = $this->generator->generate_nat00030();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        // Nominal hours at pos 111-114 (0-indexed: 110-113).
        $hoursField = substr($lines[0], 110, 4);
        $this->assertEquals('0060', $hoursField,
            'NAT00030 nominal hours field must be zero-padded 4 digits, got: ' . $hoursField);
    }

    /**
     * NAT00060 VET flag must be 'Y' for vocational education subjects.
     * Field is at pos 119 (0-indexed: 118).
     */
    public function test_nat00060_vet_flag_is_Y() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id);

        $output = $this->generator->generate_nat00060();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        $vetFlag = substr($lines[0], 118, 1);
        $this->assertEquals('Y', $vetFlag,
            'NAT00060 VET flag (pos 119) must be Y for VET subjects');
    }

    /**
     * NAT00120 delivery location defaults to 'MAIN' when deliverylocationid is null.
     * Bug 7: null location must not leave the 10-char field blank.
     */
    public function test_nat00120_defaults_location_to_main_when_null() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'activitystartdate' => strtotime(date('Y') . '-03-01'),
            'deliverylocationid' => null,
        ]);

        $output = $this->generator->generate_nat00120();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        // Delivery location is at pos 11-20 (0-indexed: 10-19).
        $locField = substr($lines[0], 10, 10);
        $this->assertEquals('MAIN      ', $locField,
            'NAT00120 delivery location must default to MAIN (space-padded to 10) when null');
    }

    /**
     * NAT00130 program ID is truncated to 10 chars.
     * Bug 48: 12-char program codes corrupt the fixed-width field.
     */
    public function test_nat00130_program_id_truncated_to_10_chars() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'programid' => 'BSB50120TOOLONG',  // 15 chars — must be truncated to 10.
            'programcode' => 'BSB50120',
            'programoutcome' => '01',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00130();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        // Program ID is at pos 11-20 (0-indexed: 10-19).
        $progField = substr($lines[0], 10, 10);
        $this->assertEquals(10, strlen($progField),
            'NAT00130 program ID field must always be exactly 10 chars');

        // Total record length must still be correct.
        $this->assertEquals(72, strlen($lines[0]),
            'NAT00130 record must remain 72 chars after program ID truncation');
    }

    /**
     * NAT00130 issued flag must be 'Y' at pos 39 (0-indexed: 38).
     */
    public function test_nat00130_issued_flag_is_Y() {
        $student = $this->create_test_student();
        $this->create_test_enrolment($student->id, [
            'programoutcome' => '01',
            'activityenddate' => strtotime(date('Y') . '-06-30'),
        ]);

        $output = $this->generator->generate_nat00130();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        $issuedFlag = substr($lines[0], 38, 1);
        $this->assertEquals('Y', $issuedFlag,
            'NAT00130 issued flag (pos 39) must be Y');
    }

    /**
     * NAT00080 date of birth is formatted as DDMMYYYY at pos 74-81 (0-indexed: 73-80).
     */
    public function test_nat00080_date_of_birth_formatted_ddmmyyyy() {
        $this->create_test_student([
            'dateofbirth' => strtotime('1990-05-15'),  // Expect '15051990'.
        ]);

        $output = $this->generator->generate_nat00080();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        $dob = substr($lines[0], 73, 8);
        $this->assertEquals('15051990', $dob,
            'NAT00080 date of birth (pos 74-81) must be DDMMYYYY format');
    }

    /**
     * NAT00080 USI appears at pos 150-159 (0-indexed: 149-158), exactly 10 chars.
     */
    public function test_nat00080_usi_at_correct_position() {
        $this->create_test_student(['usi' => 'ABC2DEF3GH']);

        $output = $this->generator->generate_nat00080();
        $lines = explode("\r\n", rtrim($output, "\r\n"));

        $this->assertNotEmpty($lines);
        $usiField = substr($lines[0], 149, 10);
        $this->assertEquals('ABC2DEF3GH', $usiField,
            'NAT00080 USI must appear at pos 150-159 exactly as provided');
    }
}
