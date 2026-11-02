<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Compare the Moodle account name with the name RTO Compliance will submit.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps the display, manual verification and batch-verification name rules aligned.
 */
class student_name_comparison {
    /**
     * Compare effective RTO names with Moodle account names.
     *
     * A NULL RTO value follows the existing verifier contract and falls back to Moodle.
     * An empty string is an explicitly stored value and is therefore not silently replaced.
     *
     * @param string|null $rtofirstname
     * @param string|null $rtolastname
     * @param string $moodlefirstname
     * @param string $moodlelastname
     * @return array
     */
    public static function compare_values($rtofirstname, $rtolastname, $moodlefirstname, $moodlelastname) {
        $moodlefirstname = (string)$moodlefirstname;
        $moodlelastname = (string)$moodlelastname;
        $rtofirstname = $rtofirstname === null ? $moodlefirstname : (string)$rtofirstname;
        $rtolastname = $rtolastname === null ? $moodlelastname : (string)$rtolastname;
        $firstname_matches = self::normalise($rtofirstname) === self::normalise($moodlefirstname);
        $lastname_matches = self::normalise($rtolastname) === self::normalise($moodlelastname);

        return [
            'rto_firstname' => $rtofirstname,
            'rto_lastname' => $rtolastname,
            'moodle_firstname' => $moodlefirstname,
            'moodle_lastname' => $moodlelastname,
            'firstname_matches' => $firstname_matches,
            'lastname_matches' => $lastname_matches,
            'mismatch' => !$firstname_matches || !$lastname_matches,
        ];
    }

    /**
     * Compare a local RTO Compliance profile record with a Moodle user record.
     *
     * @param \stdClass $student
     * @param \stdClass $user
     * @return array
     */
    public static function compare_records($student, $user) {
        return self::compare_values(
            $student->firstname ?? null,
            $student->lastname ?? null,
            $user->firstname ?? '',
            $user->lastname ?? ''
        );
    }

    /**
     * SQL equivalent used to keep mismatched identities out of automatic batches and report them.
     *
     * The comparison intentionally trims outer whitespace and ignores letter case only.
     * It does not alter names or collapse internal whitespace.
     *
     * @return string
     */
    public static function sql_mismatch_condition() {
        return "(LOWER(TRIM(COALESCE(s.firstname, u.firstname))) <> LOWER(TRIM(u.firstname))"
            . " OR LOWER(TRIM(COALESCE(s.lastname, u.lastname))) <> LOWER(TRIM(u.lastname)))";
    }

    /**
     * Normalise only case and outer whitespace; preserve the submitted name itself.
     *
     * @param string $value
     * @return string
     */
    private static function normalise($value) {
        // Match SQL TRIM() semantics used by the report and batch query.
        return \core_text::strtolower(trim((string)$value, ' '));
    }
}
