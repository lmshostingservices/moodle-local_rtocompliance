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

namespace local_rtocompliance\local;

/**
 * Trainers' display names, read from their Moodle user accounts.
 *
 * The trainers table has no name column on a fresh install (only sites upgraded through 2025121200 have the
 * cached `fullname`, and nothing keeps it current), so names come from the user record.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trainer_names {
    /**
     * Trainers who can validate (every status except inactive), by name.
     *
     * @param int $limit
     * @return array trainer id => full name
     */
    public static function active(int $limit = 200): array {
        global $DB;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $records = $DB->get_records_sql(
            "SELECT t.id AS trainerid, {$fields}
               FROM {local_rtocompliance_trainers} t
               JOIN {user} u ON u.id = t.userid AND u.deleted = 0
              WHERE t.status <> :inactive
           ORDER BY u.lastname, u.firstname, t.id",
            ['inactive' => 'inactive'], 0, $limit);
        $names = [];
        foreach ($records as $record) {
            $names[(int)$record->trainerid] = fullname($record);
        }
        return $names;
    }

    /**
     * One trainer's full name.
     *
     * @param int $trainerid
     * @return string the name, or '' when there is no such trainer
     */
    public static function name(int $trainerid): string {
        global $DB;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $record = $DB->get_record_sql(
            "SELECT t.id AS trainerid, {$fields}
               FROM {local_rtocompliance_trainers} t
               JOIN {user} u ON u.id = t.userid
              WHERE t.id = :id",
            ['id' => $trainerid]);
        return $record ? fullname($record) : '';
    }
}
