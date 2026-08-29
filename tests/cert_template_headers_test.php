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
 * PHPUnit tests for the certificate table headings (v6.3.20).
 *
 * Covers the duplicate-header strip — the stale plain-text caption row older Record of
 * Results designs carry above a table that now draws its own shaded header — and the
 * configurable heading wording resolved from the admin settings.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/classes/cert_template.php');
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * @coversDefaultClass \local_rtocompliance\cert_template
 */
class cert_template_headers_test extends \advanced_testcase {
    /**
     * A Record of Results with the legacy caption row above a ror_table: the captions go,
     * everything else stays, and the transform is idempotent.
     *
     * @covers ::strip_legacy_table_headers
     */
    public function test_strip_removes_legacy_caption_row(): void {
        $design = ['fields' => [
            ['kind' => 'dynamic', 'dynamickey' => 'student.detailstable',
                'x_mm' => 15, 'y_mm' => 64, 'w_mm' => 180, 'h_mm' => 22],
            ['kind' => 'text', 'text' => 'Semester / Year', 'x_mm' => 15, 'y_mm' => 86, 'w_mm' => 50],
            ['kind' => 'text', 'text' => 'Units / modules enrolled', 'x_mm' => 65, 'y_mm' => 86, 'w_mm' => 80],
            ['kind' => 'text', 'text' => 'Results', 'x_mm' => 150, 'y_mm' => 86, 'w_mm' => 40],
            ['kind' => 'ror_table', 'x_mm' => 15, 'y_mm' => 92, 'w_mm' => 180, 'h_mm' => 112],
            ['kind' => 'text', 'text' => 'Date of issue:', 'x_mm' => 110, 'y_mm' => 252, 'w_mm' => 85],
        ]];

        $out = cert_template::strip_legacy_table_headers($design);
        $texts = [];
        foreach ($out['fields'] as $f) {
            if (($f['kind'] ?? '') === 'text') {
                $texts[] = $f['text'];
            }
        }

        $this->assertSame(['Date of issue:'], $texts, 'Only the caption row should be removed.');
        $this->assertCount(3, $out['fields']);
        $this->assertSame($out, cert_template::strip_legacy_table_headers($out), 'Strip must be idempotent.');
    }

    /**
     * A design still using the legacy per-column fields keeps its captions — they are the
     * only column headings that layout has.
     *
     * @covers ::strip_legacy_table_headers
     */
    public function test_strip_leaves_legacy_percolumn_layout_alone(): void {
        $design = ['fields' => [
            ['kind' => 'text', 'text' => 'Semester / Year', 'x_mm' => 15, 'y_mm' => 86, 'w_mm' => 50],
            ['kind' => 'dynamic', 'dynamickey' => 'qualification.units_col_semester',
                'x_mm' => 15, 'y_mm' => 92, 'w_mm' => 30],
        ]];

        $this->assertSame($design, cert_template::strip_legacy_table_headers($design));
    }

    /**
     * The stacked identity captions survive while the shaded student details table is absent,
     * so upgrade_record_identity_to_table() can still recognise the block it replaces.
     *
     * @covers ::strip_legacy_table_headers
     */
    public function test_strip_keeps_identity_captions_without_details_table(): void {
        $design = ['fields' => [
            ['kind' => 'text', 'text' => 'Name of student:', 'x_mm' => 15, 'y_mm' => 55, 'w_mm' => 45],
            ['kind' => 'text', 'text' => 'USI:', 'x_mm' => 15, 'y_mm' => 63, 'w_mm' => 45],
            ['kind' => 'ror_table', 'x_mm' => 15, 'y_mm' => 82, 'w_mm' => 267],
        ]];

        $this->assertSame($design, cert_template::strip_legacy_table_headers($design));
    }

    /**
     * A caption far from any table (an author's own section label) is never touched.
     *
     * @covers ::strip_legacy_table_headers
     */
    public function test_strip_keeps_distant_text(): void {
        $design = ['fields' => [
            ['kind' => 'text', 'text' => 'Results', 'x_mm' => 15, 'y_mm' => 20, 'w_mm' => 50],
            ['kind' => 'ror_table', 'x_mm' => 15, 'y_mm' => 92, 'w_mm' => 180],
        ]];

        $this->assertSame($design, cert_template::strip_legacy_table_headers($design));
    }

    /**
     * Heading wording falls back to the ASQA defaults, and an admin override replaces it.
     *
     * @coversNothing
     */
    public function test_headings_default_and_override(): void {
        $this->resetAfterTest(true);

        $defaults = local_rtocompliance_cert_table_headings();
        $this->assertSame('UNIT CODE', $defaults['code']);
        $this->assertSame('RESULT', $defaults['result']);
        $this->assertSame('STUDENT NAME', $defaults['student']);

        set_config('certtablehead_code', 'COMPETENCY CODE', 'local_rtocompliance');
        set_config('certtablehead_result', '  ', 'local_rtocompliance');

        $custom = local_rtocompliance_cert_table_headings();
        $this->assertSame('COMPETENCY CODE', $custom['code']);
        $this->assertSame('RESULT', $custom['result'], 'A whitespace-only override keeps the default.');
        $this->assertSame('UNIT TITLE', $custom['title']);
    }
}
