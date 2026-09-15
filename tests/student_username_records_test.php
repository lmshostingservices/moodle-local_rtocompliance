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
 * Tests the live Moodle username used by Student Records.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * Student Records username query tests.
 */
class student_username_records_test extends \advanced_testcase {
    /**
     * Student Records must join the Moodle user row rather than copy a username.
     */
    public function test_records_read_the_current_username_from_moodle_user(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user([
            'username' => 'current_roster_user',
            'firstname' => 'Roster',
            'lastname' => 'User',
        ]);
        $studentid = $DB->insert_record('local_rtocompliance_students', (object)[
            'userid' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $row = $DB->get_record_sql(
            "SELECT u.username
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE s.id = :studentid",
            ['studentid' => $studentid],
            MUST_EXIST
        );
        $this->assertSame('current_roster_user', $row->username);

        // A changed Moodle username must be reflected without touching the RTO row.
        $DB->set_field('user', 'username', 'renamed_roster_user', ['id' => $user->id]);
        $row = $DB->get_record_sql(
            "SELECT u.username
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE s.id = :studentid",
            ['studentid' => $studentid],
            MUST_EXIST
        );
        $this->assertSame('renamed_roster_user', $row->username);
        $this->assertFalse(
            property_exists($DB->get_record('local_rtocompliance_students', ['id' => $studentid]), 'username'),
            'The RTO student record must not store a copied username'
        );
    }

    /**
     * Username search must remain a literal substring search for wildcard input.
     */
    public function test_username_search_escapes_like_wildcards(): void {
        global $DB;
        $this->resetAfterTest(true);

        $literal = $this->getDataGenerator()->create_user(['username' => 'roster_user']);
        $wildcardmatch = $this->getDataGenerator()->create_user(['username' => 'rosterxuser']);
        $search = 'roster_';
        $sql = $DB->sql_like('u.username', ':search', false, false);
        $rows = $DB->get_records_sql(
            "SELECT u.id, u.username
               FROM {user} u
              WHERE u.deleted = 0 AND $sql",
            ['search' => '%' . $DB->sql_like_escape($search) . '%']
        );

        $this->assertArrayHasKey($literal->id, $rows);
        $this->assertArrayNotHasKey(
            $wildcardmatch->id,
            $rows,
            'An underscore in the search term must not act as a SQL LIKE wildcard'
        );

        // Keep this test tied to the page wiring as well as the database behaviour.
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/rtocompliance/students.php');
        $this->assertStringContainsString('u.username', $source);
        $this->assertStringContainsString('$DB->sql_like_escape($search)', $source);
        $this->assertStringContainsString(":search7", $source);
        $this->assertStringContainsString(
            "'<code class=\"rtoc-username\">' . s((string)\$student->username) . '</code>'",
            $source
        );
    }
}