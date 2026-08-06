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

function xmldb_local_rtocompliance_uninstall() {
    global $DB;

    $avetmissshortnames = [
        'usi',
        'usiexemption',
        'dateofbirth',
        'sex',
        'countryofbirth',
        'languageathome',
        'atsi',
        'disability',
        'disabilitytype',
        'prioreducation',
    ];

    foreach ($avetmissshortnames as $shortname) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if ($field) {
            $DB->delete_records('user_info_data', ['fieldid' => $field->id]);
            $DB->delete_records('user_info_field', ['id' => $field->id]);
        }
    }

    $category = $DB->get_record('user_info_category', ['name' => 'AVETMISS Data']);
    if ($category) {
        $remainingfields = $DB->count_records('user_info_field', ['categoryid' => $category->id]);
        if ($remainingfields == 0) {
            $DB->delete_records('user_info_category', ['id' => $category->id]);
        }
    }

    return true;
}
