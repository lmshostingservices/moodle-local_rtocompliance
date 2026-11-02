<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for Moodle versus RTO Compliance student-name comparison.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * Ensures identity comparison matches the verifier's effective-name fallback.
 */
class student_name_comparison_test extends \advanced_testcase {
    /**
     * A missing local name follows the verifier's existing Moodle fallback.
     */
    public function test_null_local_names_fall_back_to_moodle(): void {
        $result = \local_rtocompliance\local\student_name_comparison::compare_values(
            null,
            null,
            'Xuesong',
            'Yang'
        );

        $this->assertFalse($result['mismatch']);
        $this->assertSame('Xuesong', $result['rto_firstname']);
        $this->assertSame('Yang', $result['rto_lastname']);
    }

    /**
     * Case and outer whitespace do not create a false mismatch.
     */
    public function test_case_and_outer_whitespace_are_ignored(): void {
        $result = \local_rtocompliance\local\student_name_comparison::compare_values(
            '  XUESONG ',
            'Yang  ',
            'Xuesong',
            'Yang'
        );

        $this->assertFalse($result['mismatch']);
    }

    /**
     * A different RTO first name is flagged and remains the effective submitted name.
     */
    public function test_real_name_difference_is_flagged(): void {
        $result = \local_rtocompliance\local\student_name_comparison::compare_values(
            'Xue',
            'Yang',
            'Xuesong',
            'Yang'
        );

        $this->assertTrue($result['mismatch']);
        $this->assertFalse($result['firstname_matches']);
        $this->assertTrue($result['lastname_matches']);
        $this->assertSame('Xue', $result['rto_firstname']);
    }

    /**
     * An explicitly blank local value is not mistaken for an absent value.
     */
    public function test_blank_local_name_is_flagged(): void {
        $result = \local_rtocompliance\local\student_name_comparison::compare_values(
            '',
            'Yang',
            'Xuesong',
            'Yang'
        );

        $this->assertTrue($result['mismatch']);
        $this->assertSame('', $result['rto_firstname']);
    }
}
