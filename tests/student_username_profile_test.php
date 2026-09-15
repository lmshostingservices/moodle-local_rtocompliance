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

/**
 * Tests read-only Moodle username presentation on the student profile.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * Student profile username display tests.
 */
class student_username_profile_test extends \advanced_testcase {
    /**
     * The profile must render the live user username and escape it for HTML.
     */
    public function test_profile_displays_username_read_only_and_escaped(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/rtocompliance/student_profile.php');

        $this->assertStringContainsString(
            "Moodle username: <strong>' . s((string)\$user->username) . '</strong>",
            $source
        );
        $this->assertStringNotContainsString(
            "'username'",
            $this->valid_columns_source($source),
            'Username must not be accepted as editable RTO profile data'
        );
        $this->assertSame(
            '&lt;current&amp;user&gt;',
            s('<current&user>'),
            'The displayed username must use Moodle output escaping'
        );
    }

    /**
     * Isolate the profile form allow-list so the assertion cannot match display text.
     *
     * @param string $source Profile page source.
     * @return string
     */
    private function valid_columns_source(string $source): string {
        $start = strpos($source, '$validcolumns = [');
        $end = strpos($source, '];', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($source, $start, $end - $start + 2);
    }
}