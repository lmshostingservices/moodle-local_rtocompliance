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
 * Pins how AVETMISS outcome identifiers render on a Record of Results.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * WHY THIS TEST EXISTS (v6.3.36):
 * Five of the twelve current outcome codes rendered wrongly on an ASQA-facing
 * document, and the error survived a previous fix (v5.9.334) that looked at this
 * exact code. Reading the block found three of the five; SEEDING every code and
 * running the renderer found all five. This test does the seeding, so the next
 * person to edit those two arrays gets a failure instead of a wrong transcript.
 *
 * @covers \local_rtocompliance\cert_template_renderer::resolve_payload
 */
final class outcome_render_test extends \advanced_testcase {
    /** All twelve codes in DED 2.3 p107, CLASSIFICATION SCHEME. */
    private const CURRENT = ['20','30','40','41','51','52','60','61','70','81','82','85'];

    /**
     * Pairs whose meanings are OPPOSITE. Sharing an abbreviation between any of
     * these is the defect that shipped: '52' printed 'RPL' exactly like '51', so
     * a refused recognition read as a granted one.
     */
    private const OPPOSITES = [['51','52'], ['81','82'], ['20','30'], ['60','61']];

    /**
     * Build a Record of Results payload holding every supplied outcome code.
     *
     * @param string[] $codes
     * @return array
     */
    private function render(array $codes): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

        $units = [];
        foreach ($codes as $c) {
            $units[] = ['code' => 'TSTU' . $c, 'name' => 'Test unit ' . $c, 'outcome' => $c];
        }
        $cert = (object)[
            'id' => 1, 'userid' => 0, 'certnumber' => 'TST-1', 'certtype' => 'record',
            'qualificationcode' => 'TSTQ01', 'qualificationname' => 'Test Qualification',
            'units' => json_encode($units), 'issuedate' => mktime(0, 0, 0, 7, 1, 2026),
            'status' => 'active', 'issuedby' => 2,
        ];
        $user = (object)['id' => 0, 'firstname' => 'Test', 'lastname' => 'Student'];
        return cert_template_renderer::resolve_payload($cert, $user);
    }

    /**
     * Extract the per-unit short result codes from the rendered payload.
     *
     * @param string[] $codes
     * @return array outcome code => printed short code
     */
    private function short_codes(array $codes): array {
        $payload = $this->render($codes);
        $rows = json_decode($payload['qualification.units_table_rows_json'] ?? '[]', true);
        $out = [];
        foreach ($rows as $i => $row) {
            $out[$codes[$i]] = (string)($row['result'] ?? '');
        }
        return $out;
    }

    /**
     * No current code may print as its own bare number. '41' and '85' both did,
     * because neither had an entry in either map - the raw code landed on the
     * document with no label at all.
     */
    public function test_no_current_code_prints_raw(): void {
        $this->resetAfterTest();
        $short = $this->short_codes(self::CURRENT);
        foreach (self::CURRENT as $code) {
            $this->assertArrayHasKey($code, $short);
            $this->assertNotSame($code, $short[$code],
                "Outcome '$code' prints as its own bare number on the Record of "
                . 'Results, which means it has no entry in the result-code map.');
            $this->assertNotSame('', $short[$code],
                "Outcome '$code' prints as an empty RESULT cell.");
        }
    }

    /** Two codes with opposite meanings must never share an abbreviation. */
    public function test_opposite_outcomes_do_not_share_an_abbreviation(): void {
        $this->resetAfterTest();
        $short = $this->short_codes(self::CURRENT);
        foreach (self::OPPOSITES as [$a, $b]) {
            $this->assertNotSame($short[$a], $short[$b],
                "Outcomes '$a' and '$b' have opposite meanings but both print as "
                . "'{$short[$a]}'. On the RESULT column they are indistinguishable.");
        }
    }

    /**
     * '61' is Superseded subject (DED 2.3 p106). It was labelled 'Credit Transfer
     * Not Granted', a meaning that does not exist anywhere in AVETMISS, and its
     * meaning had been swapped with the deleted code '90'.
     */
    public function test_61_is_superseded_subject_not_credit_transfer(): void {
        $this->resetAfterTest();
        $labels = $this->render(['61'])['qualification.units_col_results'] ?? '';
        $this->assertStringContainsStringIgnoringCase('superseded', $labels,
            "Outcome '61' must render as Superseded subject.");
        $this->assertStringNotContainsStringIgnoringCase('credit transfer', $labels,
            "Outcome '61' must not render as any kind of credit transfer - that is '60'.");
    }

    /**
     * A superseded code still has to render, because these documents reprint
     * historical records. It must be labelled, not dropped and not printed raw.
     */
    public function test_superseded_codes_still_render_a_label(): void {
        $this->resetAfterTest();
        $legacy = ['90', '53', '54', '10', '00'];
        $short = $this->short_codes($legacy);
        foreach ($legacy as $code) {
            $this->assertNotSame($code, $short[$code],
                "Superseded outcome '$code' prints as its own bare number. A dead "
                . 'code still appears on historical records and needs a label.');
        }
    }
}
