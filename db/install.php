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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_rtocompliance_install() {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

    // Create default regulatory deadlines.
    // Note: AVETMISS profile fields are no longer created in Moodle's user profile system.
    // All AVETMISS data is stored exclusively in the local_rtocompliance_students table.
    // This prevents admins/teachers from seeing AVETMISS prompts on their profile page.
    local_rtocompliance_create_default_deadlines();

    return true;
}

function local_rtocompliance_create_default_deadlines() {
    global $DB;

    $currentyear = date('Y');
    $nextyear = $currentyear + 1;

    $deadlines = [
        [
            'deadlinetype' => 'tva',
            'title' => 'Total VET Activity (TVA) Submission ' . $nextyear,
            'description' => 'Submit AVETMISS data to NCVER for the previous calendar year.',
            'duedate' => strtotime("$nextyear-02-28"),
            'reminderdays' => 30,
            'recurring' => 1,
            'recurringperiod' => 'yearly',
        ],
        [
            'deadlinetype' => 'qi',
            'title' => 'Quality Indicator Data Submission ' . $nextyear,
            'description' => 'Submit learner and employer satisfaction survey data to NCVER.',
            'duedate' => strtotime("$nextyear-06-30"),
            'reminderdays' => 30,
            'recurring' => 1,
            'recurringperiod' => 'yearly',
        ],
    ];

    foreach ($deadlines as $deadline) {
        $deadline['status'] = 'pending';
        $deadline['timecreated'] = time();
        $deadline['timemodified'] = time();
        $DB->insert_record('local_rtocompliance_deadlines', (object)$deadline);
    }
}
