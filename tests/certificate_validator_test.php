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
 * PHPUnit tests for Certificate Validator class
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/classes/certificate_validator.php');
require_once($CFG->dirroot . '/local/rtocompliance/classes/avetmiss_codes.php');

class certificate_validator_test extends \advanced_testcase {
    private $testuser;
    private $teststudent;
    private $testcourse;
    
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        
        $this->testuser = $this->getDataGenerator()->create_user([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane.doe@test.com',
        ]);
        
        $this->testcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Certificate Test Course',
            'shortname' => 'CERTTEST001',
        ]);
    }
    
    protected function tearDown(): void {
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
            'usi' => 'XYZ2ABC3DE',
            'usiverified' => 1,
            'dateofbirth' => strtotime('1992-08-20'),
            'sex' => 'F',
            'indigenousstatus' => '4',
            'countryofbirth' => '1101',
            'languageathome' => '1201',
            'englishproficiency' => '1',
            'disabilityflag' => 'N',
            'streetno' => '456',
            'streetname' => 'Certificate Lane',
            'suburb' => 'Sydney',
            'postcode' => '2000',
            'statecode' => '01',
            'highestschoollevel' => '12',
            'yearschoolcompleted' => '2010',
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
    
    public function test_can_issue_testamur_fails_without_profile() {
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $this->assertFalse($result['can_issue']);
        $this->assertNotEmpty($result['errors']);
    }
    
    public function test_can_issue_testamur_fails_without_usi() {
        $student = $this->create_test_student(['usi' => null]);
        
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $this->assertFalse($result['can_issue']);
        $errorFound = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'USI') !== false || strpos($error, 'usi') !== false) {
                $errorFound = true;
                break;
            }
        }
        $this->assertTrue($errorFound);
    }
    
    public function test_can_issue_testamur_fails_with_invalid_usi() {
        $student = $this->create_test_student(['usi' => 'INVALID01']);
        
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $this->assertFalse($result['can_issue']);
    }
    
    public function test_can_issue_testamur_fails_without_complete_qualification() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'outcomeidentifier' => '20',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'unitname' => 'Develop critical thinking',
            'outcomeidentifier' => '70',
        ]);
        
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $this->assertFalse($result['can_issue']);
    }
    
    public function test_can_issue_testamur_succeeds_with_all_units_complete() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '20',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'outcomeidentifier' => '51',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBLDR523',
            'outcomeidentifier' => '52',
        ]);
        
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $this->assertTrue($result['can_issue']);
        $this->assertEmpty($result['errors']);
    }
    
    public function test_can_issue_statement_fails_without_profile() {
        $result = certificate_validator::can_issue_statement($this->testuser->id);
        
        $this->assertFalse($result['can_issue']);
        $this->assertNotEmpty($result['errors']);
    }
    
    public function test_can_issue_statement_fails_without_units() {
        $student = $this->create_test_student();
        
        $result = certificate_validator::can_issue_statement($this->testuser->id);
        
        $this->assertFalse($result['can_issue']);
    }
    
    public function test_can_issue_statement_fails_without_competent_units() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'outcomeidentifier' => '30',
            'status' => 'completed',
        ]);
        
        $result = certificate_validator::can_issue_statement($this->testuser->id, [
            ['outcomeidentifier' => '30'],
        ]);
        
        $this->assertFalse($result['can_issue']);
    }
    
    public function test_can_issue_statement_succeeds_with_competent_units() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'outcomeidentifier' => '20',
            'status' => 'completed',
        ]);
        
        $result = certificate_validator::can_issue_statement($this->testuser->id);
        
        $this->assertTrue($result['can_issue']);
    }
    
    public function test_can_issue_statement_warns_about_continuing_units() {
        $student = $this->create_test_student();
        
        $units = [
            (object)['outcomeidentifier' => '20'],
            (object)['outcomeidentifier' => '70'],
        ];
        
        $result = certificate_validator::can_issue_statement($this->testuser->id, $units);
        
        $this->assertNotEmpty($result['warnings']);
    }
    
    public function test_can_issue_attendance_succeeds_without_accredited_training() {
        $student = $this->create_test_student();
        
        $result = certificate_validator::can_issue_attendance($this->testuser->id);
        
        $this->assertTrue($result['can_issue']);
        $this->assertNotEmpty($result['warnings']);
    }
    
    public function test_can_issue_attendance_fails_with_hold() {
        global $DB;
        
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'status' => 'hold',
            'holduntil' => strtotime('+1 month'),
            'holdreason' => 'Fee outstanding',
        ]);
        
        $result = certificate_validator::can_issue_attendance($this->testuser->id);
        
        $this->assertFalse($result['can_issue']);
    }
    
    public function test_check_holds_detects_active_holds() {
        global $DB;
        
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'status' => 'hold',
            'holduntil' => strtotime('+1 month'),
            'holdreason' => 'Academic misconduct investigation',
        ]);
        
        $holds = certificate_validator::check_holds($student->id);
        
        $this->assertNotEmpty($holds);
        $this->assertEquals('enrolment_hold', $holds[0]['type']);
    }
    
    public function test_check_holds_ignores_expired_holds() {
        global $DB;
        
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'status' => 'hold',
            'holduntil' => strtotime('-1 month'),
            'holdreason' => 'Expired hold',
        ]);
        
        $holds = certificate_validator::check_holds($student->id);
        
        $this->assertEmpty($holds);
    }
    
    public function test_check_qualification_completion_returns_correct_status() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '20',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'outcomeidentifier' => '20',
        ]);
        
        $result = certificate_validator::check_qualification_completion($student->id, 'BSB50420');
        
        $this->assertTrue($result['complete']);
        $this->assertTrue($result['all_finalized']);
        $this->assertEquals(2, $result['units_completed']);
    }
    
    public function test_check_qualification_completion_detects_continuing_units() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '20',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'outcomeidentifier' => '70',
        ]);
        
        $result = certificate_validator::check_qualification_completion($student->id, 'BSB50420');
        
        $this->assertFalse($result['complete']);
        $this->assertFalse($result['all_finalized']);
        $this->assertNotEmpty($result['missing_units']);
    }
    
    public function test_validate_certificate_issuance_dispatches_correctly() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'outcomeidentifier' => '20',
            'status' => 'completed',
        ]);
        
        $testamurResult = certificate_validator::validate_certificate_issuance(
            'testamur',
            $this->testuser->id,
            ['qualificationcode' => 'BSB50420']
        );
        $this->assertArrayHasKey('can_issue', $testamurResult);
        
        $statementResult = certificate_validator::validate_certificate_issuance(
            'statement',
            $this->testuser->id
        );
        $this->assertArrayHasKey('can_issue', $statementResult);
        
        $attendanceResult = certificate_validator::validate_certificate_issuance(
            'attendance',
            $this->testuser->id
        );
        $this->assertArrayHasKey('can_issue', $attendanceResult);
        
        $unknownResult = certificate_validator::validate_certificate_issuance(
            'unknown',
            $this->testuser->id
        );
        $this->assertFalse($unknownResult['can_issue']);
    }
    
    public function test_get_student_avetmiss_status_returns_complete_profile_info() {
        $student = $this->create_test_student();
        
        $status = certificate_validator::get_student_avetmiss_status($this->testuser->id);
        
        $this->assertTrue($status['has_profile']);
        $this->assertArrayHasKey('fields', $status);
        $this->assertArrayHasKey('percentage', $status);
        $this->assertArrayHasKey('ready_for_cert', $status);
    }
    
    public function test_get_student_avetmiss_status_detects_missing_fields() {
        $student = $this->create_test_student([
            'usi' => null,
            'postcode' => null,
            'profilecomplete' => 0,
        ]);
        
        $status = certificate_validator::get_student_avetmiss_status($this->testuser->id);
        
        $this->assertTrue($status['has_profile']);
        $this->assertFalse($status['ready_for_cert']);
        $this->assertFalse($status['fields']['usi']['complete']);
    }
    
    public function test_get_issuable_units_returns_completed_units() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '20',
            'status' => 'completed',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'outcomeidentifier' => '70',
            'status' => 'active',
        ]);
        
        $units = certificate_validator::get_issuable_units($this->testuser->id);
        
        $this->assertCount(1, $units);
        $this->assertEquals('BSBCMM511', $units[0]['unitcode']);
    }
    
    public function test_get_issuable_units_marks_already_issued() {
        global $DB;
        
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '20',
            'status' => 'completed',
        ]);
        
        $cert = (object)[
            'userid' => $this->testuser->id,
            'certnumber' => 'CERT-2025-0001',
            'certtype' => 'statement',
            'qualificationcode' => 'BSB50420',
            'units' => json_encode([['code' => 'BSBCMM511', 'name' => 'Communicate with influence']]),
            'issuedate' => time(),
            'verifytoken' => bin2hex(random_bytes(32)),
            'status' => 'issued',
            'issuedby' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('local_rtocompliance_certs', $cert);
        
        $units = certificate_validator::get_issuable_units($this->testuser->id);
        
        $this->assertTrue($units[0]['already_issued']);
        $this->assertEquals('CERT-2025-0001', $units[0]['issued_cert']);
    }
    
    public function test_get_issuable_qualifications_returns_qualification_status() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'programcode' => 'BSB50420',
            'programname' => 'Diploma of Leadership and Management',
            'outcomeidentifier' => '20',
        ]);
        $this->create_test_enrolment($student->id, [
            'programcode' => 'BSB40520',
            'programname' => 'Certificate IV in Leadership and Management',
            'outcomeidentifier' => '70',
        ]);
        
        $quals = certificate_validator::get_issuable_qualifications($this->testuser->id);
        
        $this->assertCount(2, $quals);
        
        $bsb50420 = array_filter($quals, fn($q) => $q['programcode'] === 'BSB50420');
        $bsb50420 = reset($bsb50420);
        $this->assertTrue($bsb50420['complete']);
        
        $bsb40520 = array_filter($quals, fn($q) => $q['programcode'] === 'BSB40520');
        $bsb40520 = reset($bsb40520);
        $this->assertFalse($bsb40520['complete']);
    }
    
    public function test_usi_validation() {
        $student = $this->create_test_student(['usi' => 'XYZ2ABC3DE']);
        
        $result = certificate_validator::can_issue_testamur($this->testuser->id, 'BSB50420');
        
        $usiError = false;
        foreach ($result['errors'] as $error) {
            if (stripos($error, 'usi') !== false && stripos($error, 'invalid') !== false) {
                $usiError = true;
                break;
            }
        }
        $this->assertFalse($usiError);
    }
    
    public function test_multiple_rpl_outcomes_accepted() {
        $student = $this->create_test_student();
        
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCMM511',
            'outcomeidentifier' => '51',
            'status' => 'completed',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBCRT511',
            'outcomeidentifier' => '52',
            'status' => 'completed',
        ]);
        $this->create_test_enrolment($student->id, [
            'unitcode' => 'BSBLDR523',
            'outcomeidentifier' => '60',
            'status' => 'completed',
        ]);
        
        $result = certificate_validator::can_issue_statement($this->testuser->id);
        
        $this->assertTrue($result['can_issue']);
    }
}
