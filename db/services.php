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

$functions = [
    'local_rtocompliance_get_student' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'get_student',
        'description' => 'Get student AVETMISS profile data by Moodle user ID',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:viewreports',
        'ajax'        => true,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_rtocompliance_get_enrolments' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'get_enrolments',
        'description' => 'Get training activity enrolments for a student',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:viewreports',
        'ajax'        => true,
        'services'    => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_rtocompliance_get_compliance_summary' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'get_compliance_summary',
        'description' => 'Get RTO compliance summary with risk score and alert counts',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:viewreports',
        'ajax'        => true,
    ],
    'local_rtocompliance_get_certificates' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'get_certificates',
        'description' => 'Get AQF certificates issued to students',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:viewreports',
        'ajax'        => true,
    ],
    'local_rtocompliance_update_enrolment_outcome' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'update_enrolment_outcome',
        'description' => 'Update AVETMISS outcome for a training activity enrolment',
        'type'        => 'write',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_run_compliance_scan' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'run_compliance_scan',
        'description' => 'Run predictive compliance analysis and generate alerts',
        'type'        => 'write',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_tga_search_qualification' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'tga_search_qualification',
        'description' => 'Search TGA for qualification details and unit grid',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_tga_search_unit' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'tga_search_unit',
        'description' => 'Search TGA for units by code or keyword',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_qualbuilder_import_units' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'qualbuilder_import_units',
        'description' => 'Bulk import units into qualification builder',
        'type'        => 'write',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_tga_get_builder_data' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'tga_get_builder_data',
        'description' => 'Fetch full TGA qualification builder data: packaging rules, grouped units, and Moodle category/course suggestions',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_qualbuilder_auto_build' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'qualbuilder_auto_build',
        'description' => 'Atomically save qualification builder product metadata and all units in one call',
        'type'        => 'write',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
    'local_rtocompliance_get_courses_for_category' => [
        'classname'   => 'local_rtocompliance\external',
        'methodname'  => 'get_courses_for_category',
        'description' => 'Fetch Moodle courses for a qualification category subtree (lightweight refresh without a TGA call)',
        'type'        => 'read',
        'capabilities' => 'local/rtocompliance:manage',
        'ajax'        => true,
    ],
];

$services = [
    'RTO Compliance API' => [
        'functions' => [
            'local_rtocompliance_get_student',
            'local_rtocompliance_get_enrolments',
            'local_rtocompliance_get_compliance_summary',
            'local_rtocompliance_get_certificates',
            'local_rtocompliance_update_enrolment_outcome',
            'local_rtocompliance_run_compliance_scan',
            'local_rtocompliance_tga_search_qualification',
            'local_rtocompliance_tga_search_unit',
            'local_rtocompliance_qualbuilder_import_units',
            'local_rtocompliance_tga_get_builder_data',
            'local_rtocompliance_qualbuilder_auto_build',
            'local_rtocompliance_get_courses_for_category',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'rtocompliance_api',
    ],
];
