<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Regression tests for the USI name-mismatch safety guard.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * Confirms a mismatched RTO name cannot reach the USI client without staff approval.
 */
class usi_name_mismatch_test extends \advanced_testcase {
    /**
     * A mismatch returns the exact proposed values without calling the USI client
     * or changing the saved verification state.
     */
    public function test_mismatch_requires_confirmation_before_registry_call(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Xuesong',
            'lastname' => 'Yang',
        ]);
        $studentid = $DB->insert_record('local_rtocompliance_students', (object)[
            'userid' => $user->id,
            'usi' => 'ABC1234567',
            'firstname' => 'Xue',
            'lastname' => 'Yang',
            'dateofbirth' => make_timestamp(2001, 3, 6, 12),
        ]);

        $client = new class {
            public $called = false;

            public function verify_usi(...$arguments) {
                $this->called = true;
                return [
                    'verified' => true,
                    'status' => 'VERIFIED',
                    'message' => 'Test result',
                    'details' => [],
                ];
            }
        };
        $service = new \local_rtocompliance\usi\usi_verification_service($client);

        $result = $service->verify_student_usi($studentid);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['confirmation_required']);
        $this->assertSame('Xue', $result['rto_firstname']);
        $this->assertSame('Xuesong', $result['moodle_firstname']);
        $this->assertFalse($client->called);
        $this->assertSame(
            0,
            (int)$DB->get_field('local_rtocompliance_students', 'usiverified', ['id' => $studentid])
        );
    }
}
