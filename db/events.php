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

$observers = [
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => 'local_rtocompliance_observer::course_deleted',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\\user_enrolment_created',
        'callback' => 'local_rtocompliance_observer::user_enrolment_created',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\\user_enrolment_deleted',
        'callback' => 'local_rtocompliance_observer::user_enrolment_deleted',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => 'local_rtocompliance_observer::course_completed',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => 'local_rtocompliance_observer::course_module_completion_updated',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback' => 'local_rtocompliance_observer::user_graded',
        'includefile' => '/local/rtocompliance/classes/observer.php',
        'priority' => 0,
        'internal' => true,
    ],
];