<?php
/**
 * PHPUnit tests for AVETMISS Codes class
 *
 * @package    local_rtocompliance
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/classes/avetmiss_codes.php');

class avetmiss_codes_test extends \advanced_testcase {
    
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }
    
    public function test_get_outcome_identifiers_returns_all_codes() {
        $outcomes = avetmiss_codes::get_outcome_identifiers();
        
        $this->assertIsArray($outcomes);
        $this->assertNotEmpty($outcomes);
        
        $this->assertArrayHasKey('20', $outcomes);
        $this->assertArrayHasKey('30', $outcomes);
        $this->assertArrayHasKey('40', $outcomes);
        $this->assertArrayHasKey('51', $outcomes);
        $this->assertArrayHasKey('70', $outcomes);
    }
    
    public function test_get_completion_outcomes_returns_competent_codes() {
        $completionOutcomes = avetmiss_codes::get_completion_outcomes();
        
        $this->assertContains('20', $completionOutcomes);
        $this->assertContains('51', $completionOutcomes);
        $this->assertContains('52', $completionOutcomes);
        $this->assertContains('60', $completionOutcomes);
        $this->assertContains('81', $completionOutcomes);
        $this->assertContains('82', $completionOutcomes);
        
        $this->assertNotContains('30', $completionOutcomes);
        $this->assertNotContains('40', $completionOutcomes);
        $this->assertNotContains('70', $completionOutcomes);
    }
    
    public function test_get_continuing_outcomes_returns_in_progress_codes() {
        $continuingOutcomes = avetmiss_codes::get_continuing_outcomes();
        
        $this->assertContains('00', $continuingOutcomes);
        $this->assertContains('65', $continuingOutcomes);
        $this->assertContains('66', $continuingOutcomes);
        $this->assertContains('70', $continuingOutcomes);
        $this->assertContains('90', $continuingOutcomes);
        
        $this->assertNotContains('20', $continuingOutcomes);
        $this->assertNotContains('51', $continuingOutcomes);
    }
    
    public function test_get_country_codes_contains_australia() {
        $countries = avetmiss_codes::get_country_codes();
        
        $this->assertArrayHasKey('1101', $countries);
        $this->assertEquals('Australia', $countries['1101']);
        
        $this->assertArrayHasKey('1201', $countries);
        $this->assertEquals('New Zealand', $countries['1201']);
        
        $this->assertArrayHasKey('6101', $countries);
        $this->assertStringContainsString('China', $countries['6101']);
    }
    
    public function test_get_language_codes_contains_english() {
        $languages = avetmiss_codes::get_language_codes();
        
        $this->assertArrayHasKey('1201', $languages);
        $this->assertEquals('English', $languages['1201']);
        
        $this->assertArrayHasKey('4302', $languages);
        $this->assertEquals('Mandarin', $languages['4302']);
        
        $this->assertArrayHasKey('5102', $languages);
        $this->assertEquals('Hindi', $languages['5102']);
    }
    
    public function test_get_indigenous_status_codes() {
        $statuses = avetmiss_codes::get_indigenous_status_codes();
        
        $this->assertArrayHasKey('@', $statuses);
        $this->assertArrayHasKey('1', $statuses);
        $this->assertArrayHasKey('2', $statuses);
        $this->assertArrayHasKey('3', $statuses);
        $this->assertArrayHasKey('4', $statuses);
        
        $this->assertStringContainsString('Aboriginal', $statuses['1']);
        $this->assertStringContainsString('Torres Strait', $statuses['2']);
    }
    
    public function test_get_disability_codes() {
        $codes = avetmiss_codes::get_disability_codes();
        
        $this->assertArrayHasKey('Y', $codes);
        $this->assertArrayHasKey('N', $codes);
        $this->assertArrayHasKey('@', $codes);
    }
    
    public function test_get_disability_type_codes() {
        $types = avetmiss_codes::get_disability_type_codes();
        
        $this->assertArrayHasKey('11', $types);
        $this->assertArrayHasKey('12', $types);
        $this->assertArrayHasKey('13', $types);
        $this->assertArrayHasKey('14', $types);
        $this->assertArrayHasKey('15', $types);
        
        $this->assertStringContainsString('Hearing', $types['11']);
        $this->assertStringContainsString('Physical', $types['12']);
        $this->assertStringContainsString('Learning', $types['14']);
    }
    
    public function test_get_prior_education_codes() {
        $codes = avetmiss_codes::get_prior_education_codes();
        
        $this->assertArrayHasKey('008', $codes);
        $this->assertArrayHasKey('410', $codes);
        $this->assertArrayHasKey('420', $codes);
        $this->assertArrayHasKey('511', $codes);
        $this->assertArrayHasKey('@@', $codes);
        
        $this->assertStringContainsString('Bachelor', $codes['008']);
        $this->assertStringContainsString('Diploma', $codes['420']);
    }
    
    public function test_get_school_level_codes() {
        $codes = avetmiss_codes::get_school_level_codes();
        
        $this->assertArrayHasKey('02', $codes);
        $this->assertArrayHasKey('12', $codes);
        $this->assertArrayHasKey('@@', $codes);
        
        $this->assertStringContainsString('Year 12', $codes['12']);
    }
    
    public function test_get_state_codes() {
        $states = avetmiss_codes::get_state_codes();
        
        $this->assertArrayHasKey('01', $states);
        $this->assertArrayHasKey('02', $states);
        $this->assertArrayHasKey('03', $states);
        $this->assertArrayHasKey('04', $states);
        $this->assertArrayHasKey('05', $states);
        $this->assertArrayHasKey('06', $states);
        $this->assertArrayHasKey('07', $states);
        $this->assertArrayHasKey('08', $states);
        
        $this->assertEquals('New South Wales', $states['01']);
        $this->assertEquals('Victoria', $states['02']);
        $this->assertEquals('Queensland', $states['03']);
        $this->assertEquals('Australian Capital Territory', $states['08']);
    }
    
    public function test_get_sex_codes() {
        $codes = avetmiss_codes::get_sex_codes();
        
        $this->assertArrayHasKey('M', $codes);
        $this->assertArrayHasKey('F', $codes);
        $this->assertArrayHasKey('@', $codes);
        
        $this->assertEquals('Male', $codes['M']);
        $this->assertEquals('Female', $codes['F']);
    }
    
    public function test_get_delivery_mode_codes() {
        $modes = avetmiss_codes::get_delivery_mode_codes();
        
        $this->assertArrayHasKey('N', $modes);
        $this->assertArrayHasKey('I', $modes);
        $this->assertArrayHasKey('E', $modes);
        $this->assertArrayHasKey('W', $modes);
        $this->assertArrayHasKey('C', $modes);
    }
    
    public function test_get_funding_source_codes() {
        $codes = avetmiss_codes::get_funding_source_codes();
        
        $this->assertNotEmpty($codes);
        $this->assertArrayHasKey('10', $codes);
        $this->assertArrayHasKey('20', $codes);
    }
    
    public function test_get_certificate_types() {
        $types = avetmiss_codes::get_certificate_types();
        
        $this->assertArrayHasKey('testamur', $types);
        $this->assertArrayHasKey('statement', $types);
        $this->assertArrayHasKey('record', $types);
        $this->assertArrayHasKey('attendance', $types);
        
        $this->assertArrayHasKey('name', $types['testamur']);
        $this->assertArrayHasKey('description', $types['testamur']);
        $this->assertArrayHasKey('requires', $types['testamur']);
    }
    
    public function test_validate_usi_accepts_valid_usi() {
        $result = avetmiss_codes::validate_usi('ABC2DEF3GH');
        $this->assertTrue($result['valid']);
        $this->assertEquals('ABC2DEF3GH', $result['usi']);
        
        $result = avetmiss_codes::validate_usi('xyz2abc3de');
        $this->assertTrue($result['valid']);
        $this->assertEquals('XYZ2ABC3DE', $result['usi']);
    }
    
    public function test_validate_usi_rejects_empty() {
        $result = avetmiss_codes::validate_usi('');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('required', $result['error']);
        
        $result = avetmiss_codes::validate_usi(null);
        $this->assertFalse($result['valid']);
    }
    
    public function test_validate_usi_rejects_wrong_length() {
        $result = avetmiss_codes::validate_usi('ABC');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('10 characters', $result['error']);
        
        $result = avetmiss_codes::validate_usi('ABCDEFGHIJKLMNO');
        $this->assertFalse($result['valid']);
    }
    
    public function test_validate_usi_rejects_invalid_characters() {
        $result = avetmiss_codes::validate_usi('0123456789');
        $this->assertFalse($result['valid']);
        
        $result = avetmiss_codes::validate_usi('ABCIDEFGHO');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error']);
        
        $result = avetmiss_codes::validate_usi('ABC1DEF0GH');
        $this->assertFalse($result['valid']);
    }
    
    public function test_validate_postcode_accepts_valid_postcodes() {
        $result = avetmiss_codes::validate_postcode('3000', '02');
        $this->assertTrue($result['valid']);
        $this->assertEquals('3000', $result['postcode']);
        
        $result = avetmiss_codes::validate_postcode('2000', '01');
        $this->assertTrue($result['valid']);
        
        $result = avetmiss_codes::validate_postcode('4000', '03');
        $this->assertTrue($result['valid']);
    }
    
    public function test_validate_postcode_rejects_invalid_format() {
        $result = avetmiss_codes::validate_postcode('ABC', '02');
        $this->assertFalse($result['valid']);
        
        $result = avetmiss_codes::validate_postcode('12', '02');
        $this->assertFalse($result['valid']);
        
        $result = avetmiss_codes::validate_postcode('12345', '02');
        $this->assertFalse($result['valid']);
    }
    
    public function test_validate_postcode_warns_on_state_mismatch() {
        $result = avetmiss_codes::validate_postcode('3000', '01');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('match', $result['error']);
        
        $result = avetmiss_codes::validate_postcode('2000', '02');
        $this->assertFalse($result['valid']);
    }
    
    public function test_get_mandatory_avetmiss_fields() {
        $fields = avetmiss_codes::get_mandatory_avetmiss_fields();
        
        $this->assertArrayHasKey('personal', $fields);
        $this->assertArrayHasKey('contact', $fields);
        $this->assertArrayHasKey('demographic', $fields);
        $this->assertArrayHasKey('enrolment', $fields);
        
        $this->assertContains('firstname', $fields['personal']);
        $this->assertContains('lastname', $fields['personal']);
        $this->assertContains('dateofbirth', $fields['personal']);
        
        $this->assertContains('postcode', $fields['contact']);
        $this->assertContains('state', $fields['contact']);
    }
    
    public function test_get_labour_force_status_codes() {
        $codes = avetmiss_codes::get_labour_force_status_codes();
        
        $this->assertArrayHasKey('01', $codes);
        $this->assertArrayHasKey('02', $codes);
        $this->assertArrayHasKey('06', $codes);
        $this->assertArrayHasKey('08', $codes);
        $this->assertArrayHasKey('@@', $codes);
        
        $this->assertStringContainsString('Full-time', $codes['01']);
        $this->assertStringContainsString('Part-time', $codes['02']);
    }
    
    public function test_get_study_reason_codes() {
        $codes = avetmiss_codes::get_study_reason_codes();
        
        $this->assertArrayHasKey('01', $codes);
        $this->assertArrayHasKey('05', $codes);
        $this->assertArrayHasKey('11', $codes);
        
        $this->assertStringContainsString('job', $codes['01']);
        $this->assertStringContainsString('promotion', $codes['05']);
    }
    
    public function test_get_english_proficiency_codes() {
        $codes = avetmiss_codes::get_english_proficiency_codes();
        
        $this->assertArrayHasKey('1', $codes);
        $this->assertArrayHasKey('2', $codes);
        $this->assertArrayHasKey('3', $codes);
        $this->assertArrayHasKey('4', $codes);
        $this->assertArrayHasKey('@', $codes);
        
        $this->assertStringContainsString('Very well', $codes['1']);
        $this->assertStringContainsString('Not at all', $codes['4']);
    }
    
    public function test_get_commencing_program_codes() {
        $codes = avetmiss_codes::get_commencing_program_codes();
        
        $this->assertArrayHasKey('1', $codes);
        $this->assertArrayHasKey('2', $codes);
        $this->assertArrayHasKey('3', $codes);
        $this->assertArrayHasKey('4', $codes);
        
        $this->assertStringContainsString('Commencing', $codes['1']);
        $this->assertStringContainsString('Continuing', $codes['3']);
    }
    
    public function test_get_program_outcome_codes() {
        $codes = avetmiss_codes::get_program_outcome_codes();
        
        $this->assertArrayHasKey('01', $codes);
        $this->assertArrayHasKey('02', $codes);
        $this->assertArrayHasKey('03', $codes);
        $this->assertArrayHasKey('04', $codes);
        $this->assertArrayHasKey('05', $codes);
        
        $this->assertStringContainsString('AQF', $codes['01']);
        $this->assertStringContainsString('withdrawn', $codes['04']);
    }
    
    public function test_get_vet_flag_codes() {
        $codes = avetmiss_codes::get_vet_flag_codes();
        
        $this->assertArrayHasKey('Y', $codes);
        $this->assertArrayHasKey('N', $codes);
    }
    
    public function test_get_fee_charged_codes() {
        $codes = avetmiss_codes::get_fee_charged_codes();
        
        $this->assertArrayHasKey('Y', $codes);
        $this->assertArrayHasKey('N', $codes);
        $this->assertArrayHasKey('P', $codes);
    }
    
    public function test_code_arrays_are_not_duplicated() {
        $outcomes = avetmiss_codes::get_outcome_identifiers();
        $this->assertEquals(count($outcomes), count(array_unique(array_keys($outcomes))));
        
        $states = avetmiss_codes::get_state_codes();
        $this->assertEquals(count($states), count(array_unique(array_keys($states))));
        
        $countries = avetmiss_codes::get_country_codes();
        $this->assertEquals(count($countries), count(array_unique(array_keys($countries))));
    }
}
