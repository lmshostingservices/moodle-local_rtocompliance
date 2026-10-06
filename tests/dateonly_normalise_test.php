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
 * PHPUnit tests for local_rtocompliance_normalise_dateonly() (v6.6.4).
 *
 * The date-of-birth save path runs every value through this function. v6.4.8 shipped
 * it with a "less than or equal to zero" guard, so every date before 1 January 1970 -
 * a negative timestamp - was saved as 0, i.e. "not entered". These cases pin the
 * behaviour so a pre-1970 date of birth can never be discarded a third time.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * @coversDefaultClass \local_rtocompliance
 */
class dateonly_normalise_test extends \advanced_testcase {
    /**
     * Midnight of a calendar day in a timezone - what a date_selector submits.
     *
     * @param int $y Year.
     * @param int $m Month.
     * @param int $d Day.
     * @param string $tz Timezone name.
     * @return int
     */
    private function picked(int $y, int $m, int $d, string $tz): int {
        $dt = new \DateTime('now', new \DateTimeZone($tz));
        $dt->setDate($y, $m, $d);
        $dt->setTime(0, 0, 0);
        return $dt->getTimestamp();
    }

    /**
     * The calendar day a stored value renders as in the export timezone.
     *
     * @param int $ts Timestamp.
     * @return string
     */
    private function exported_day(int $ts): string {
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone(new \DateTimeZone('Australia/Sydney'));
        return $dt->format('Y-m-d');
    }

    /**
     * Dates either side of 1970, in timezones either side of the export zone.
     *
     * @return array
     */
    public static function dates_provider(): array {
        $cases = [];
        foreach (['Asia/Bangkok', 'Australia/Perth', 'Australia/Sydney'] as $tz) {
            foreach ([[1920, 1, 1], [1964, 2, 29], [1965, 5, 14], [1968, 1, 1], [1969, 12, 31],
                    [1970, 1, 1], [1970, 1, 2], [1990, 2, 28]] as $ymd) {
                $cases[$tz . ' ' . implode('-', $ymd)] = [$tz, $ymd[0], $ymd[1], $ymd[2]];
            }
        }
        return $cases;
    }

    /**
     * A picked date - including any date before 1970 - keeps its calendar day.
     *
     * @dataProvider dates_provider
     * @param string $tz User timezone.
     * @param int $y Year.
     * @param int $m Month.
     * @param int $d Day.
     */
    public function test_picked_date_is_kept(string $tz, int $y, int $m, int $d): void {
        $this->resetAfterTest(true);
        $this->setTimezone($tz);
        $this->setUser($this->getDataGenerator()->create_user());

        $stored = local_rtocompliance_normalise_dateonly($this->picked($y, $m, $d, $tz));

        $this->assertNotSame(0, $stored, 'A real date must never be stored as "no date".');
        $this->assertSame(sprintf('%04d-%02d-%02d', $y, $m, $d), $this->exported_day($stored));
    }

    /**
     * A date before 1970 is stored as a negative timestamp, not zero.
     */
    public function test_pre_1970_date_is_negative_not_zero(): void {
        $this->resetAfterTest(true);
        $this->setTimezone('Australia/Perth');
        $this->setUser($this->getDataGenerator()->create_user());

        $stored = local_rtocompliance_normalise_dateonly($this->picked(1965, 5, 14, 'Australia/Perth'));

        $this->assertLessThan(0, $stored);
        $this->assertFalse(local_rtocompliance_avetmiss_value_missing('dateofbirth', $stored));
    }

    /**
     * Empty, non-numeric and zero inputs still mean "no date".
     */
    public function test_empty_values_mean_no_date(): void {
        $this->assertSame(0, local_rtocompliance_normalise_dateonly(0));
        $this->assertSame(0, local_rtocompliance_normalise_dateonly('0'));
        $this->assertSame(0, local_rtocompliance_normalise_dateonly(''));
        $this->assertSame(0, local_rtocompliance_normalise_dateonly(null));
        $this->assertSame(0, local_rtocompliance_normalise_dateonly('not a date'));
    }
}
