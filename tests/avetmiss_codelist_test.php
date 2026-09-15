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
 * Pins the plugin's AVETMISS code lists to NCVER's published system files.
 *
 * WHY THIS TEST EXISTS (v6.3.33):
 * The country and language lists shipped up to v6.3.32 were not SACC and ASCL. They had
 * the right shape - four digits, plausible labels - and were wrong from their first
 * entry, so no amount of reading the code or eyeballing a NAT file would have shown it.
 * Only comparison against NCVER's own files exposed it: 119 country codes and 85
 * language codes named a DIFFERENT place or language than the label the operator saw.
 *
 * So the lists are no longer trusted as source; db/codelists/ holds a copy of NCVER's
 * files and these tests fail if the arrays and the files ever disagree. That makes the
 * only supported way to change a list an obvious one: replace the file, regenerate the
 * array, and see the test go green. A hand-edit fails the build instead of reaching a
 * site and rewriting students' country of birth.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_rtocompliance\avetmiss_codes
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

final class avetmiss_codelist_test extends \advanced_testcase {
    /**
     * Read a shipped NCVER code list into code => label.
     *
     * @param string $file Basename inside db/codelists/.
     * @return array<string, string>
     */
    private function load_shipped_list(string $file): array {
        global $CFG;

        $path = $CFG->dirroot . '/local/rtocompliance/db/codelists/' . $file;
        $this->assertFileExists($path, "$file must ship with the plugin - it is the source of truth for the code array.");

        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode("\t", $line, 2);
            $this->assertCount(2, $parts, "Malformed row in $file: $line");
            $out[$parts[0]] = $parts[1];
        }
        $this->assertNotEmpty($out, "$file parsed to nothing.");
        return $out;
    }

    /**
     * The code list and the shipped NCVER file must agree exactly, both ways.
     *
     * @param string $getter
     * @param string $file
     * @param string $notspecified
     */
    private function assert_list_matches_file(string $getter, string $file, string $notspecified): void {
        $shipped = $this->load_shipped_list($file);
        $actual  = avetmiss_codes::$getter();

        // '@@@@' is the last row of NCVER's own file for both lists and is a legal
        // submitted value, so the shipped copy keeps it and the array must offer it.
        // Its LABEL is excluded from the comparison below, deliberately: NCVER writes
        // it 'Not Specified' in the country file and 'not specified' in the language
        // file, and neither is a label to show an operator in a menu. The identifier is
        // what has to match; the words next to it are ours.
        $this->assertArrayHasKey($notspecified, $actual,
            "$getter() must offer NCVER's not-specified value '$notspecified'.");
        unset($shipped[$notspecified], $actual[$notspecified]);

        $expectedkeys = array_map('strval', array_keys($shipped));
        $actualkeys   = array_map('strval', array_keys($actual));
        sort($expectedkeys);
        sort($actualkeys);

        $missing = array_values(array_diff($expectedkeys, $actualkeys));
        $extra   = array_values(array_diff($actualkeys, $expectedkeys));

        $this->assertSame([], $missing,
            "$getter() is missing identifiers that NCVER publishes. A code that cannot be "
            . 'selected is a code that gets recorded as something else: ' . implode(', ', array_slice($missing, 0, 20)));
        $this->assertSame([], $extra,
            "$getter() offers identifiers NCVER does not publish. These cannot be reported "
            . 'and will be rejected on load: ' . implode(', ', array_slice($extra, 0, 20)));

        // Labels matter as much as codes. A right code under a wrong label is the exact
        // defect that put 6103 (Macau) into an Indian student's record: the operator
        // clicked a label that said India.
        $mismatched = [];
        foreach ($shipped as $code => $label) {
            $code = (string)$code;
            if (!array_key_exists($code, $actual)) {
                continue;
            }
            if ((string)$actual[$code] !== (string)$label) {
                $mismatched[] = "$code: file '" . $label . "' vs array '" . $actual[$code] . "'";
            }
        }
        $this->assertSame([], $mismatched,
            "$getter() labels disagree with NCVER's file: " . implode(' | ', array_slice($mismatched, 0, 10)));
    }

    public function test_country_codes_match_ncver_sacc_file(): void {
        $this->assert_list_matches_file('get_country_codes', 'countryidentifier.txt', '@@@@');
    }

    public function test_language_codes_match_ncver_ascl_file(): void {
        $this->assert_list_matches_file('get_language_codes', 'language_systemfile.txt', '@@@@');
    }

    /**
     * Specific values from the corrupted lists, asserted by hand.
     *
     * The file comparison above would catch all of these, but it catches them as a
     * diff of hundreds of lines. These name the ones that were actually observed
     * damaging production records, so a regression says what broke rather than that
     * something did.
     */
    public function test_known_corruptions_are_gone(): void {
        $country  = avetmiss_codes::get_country_codes();
        $language = avetmiss_codes::get_language_codes();

        // The first option of each menu. The browser submits the first option when the
        // stored value has no match, so what sits here decides what a silent save writes.
        $this->assertSame('Unknown', $language['0000']);
        $this->assertSame('Non verbal', $language['0001'],
            "ASCL 0001 is 'Non verbal'. The old list labelled it 'Inadequately described' and "
            . 'put it first, so students the browser defaulted were recorded as non-verbal.');

        // The four entries whose wrongness was traced to real audit-log corruptions.
        $this->assertSame('Macau (SAR of China)', $country['6103']);
        $this->assertSame('India', $country['7103']);
        $this->assertSame('Iran', $country['4203']);
        $this->assertSame('Russian Federation', $country['3308']);

        // Fiji: the plugin was right and my own reference was wrong. Pinned so it is
        // not "corrected" back.
        $this->assertSame('Fiji', $country['1502']);

        // Every UK constituent was unselectable in the old list.
        $this->assertSame('England', $country['2102']);
        $this->assertSame('Scotland', $country['2105']);
        $this->assertSame('Wales', $country['2106']);
        $this->assertSame('Northern Ireland', $country['2104']);

        // SACC puts the Virgin Islands at 84xx. The old list had 8527/8528.
        $this->assertSame('Virgin Islands, British', $country['8427']);
        $this->assertArrayNotHasKey('8527', $country);

        // The language list's first five entries, which were wrong in the old list.
        $this->assertSame('Gaelic (Scotland)', $language['1101']);
        $this->assertSame('Irish', $language['1102']);
        $this->assertSame('Welsh', $language['1103']);
        $this->assertSame('English', $language['1201']);

        // Codes the narrow-group range fetch missed on the first pass. They were only
        // found by reading NCVER's file directly, and they cover 78 students on the
        // site under audit.
        $this->assertSame('Chinese', $language['7100']);
        $this->assertSame('Other Eastern Asian Languages', $language['7900']);

        // '9999' is not an identifier in either standard. 1,621 students held it.
        $this->assertArrayNotHasKey('9999', $country);
        $this->assertArrayNotHasKey('9999', $language);

        // Themne, on 1,280 students on the site under audit, IS a real ASCL code. It is
        // pinned here so nobody removes it while cleaning up that population - the
        // implausible count is a data question, not a code-list question.
        $this->assertSame('Themne', $language['9261']);

        // The Australian Indigenous block must be present in full, not omitted because
        // one site happens not to use it.
        $this->assertSame('Pitjantjatjara', $language['8714']);
        $this->assertSame('Auslan', $language['9701']);
        $this->assertGreaterThan(200, count(preg_grep('/^8/', array_keys($language))));
    }

    /**
     * The two codes that 2,168 live records held and the shipped lists did not.
     *
     * Found by running the audit SQL against a production site, not by reading the
     * code: 782 students on Gender 'X' and 1,386 on State identifier '@@'. Neither
     * came from this plugin - its menus never offered them - so both arrived by NAT
     * import and had been correct all along, while v6.3.33's first draft would have
     * shown them as "Unrecognised code" and refused to let staff set either.
     */
    public function test_codes_missing_before_the_production_audit(): void {
        $sex = avetmiss_codes::get_sex_codes();
        $this->assertSame('Other', $sex['X'],
            "AVETMISS Release 8.0 renamed Sex to Gender and the scheme is M/F/X. NCVER's "
            . "Data Support Bulletin: a response other than Male/Female 'should be coded "
            . "for the National VET Provider Collection at this point in time as Other'.");
        $this->assertSame('Not stated', $sex['@']);
        $this->assertSame('Male', $sex['M']);
        $this->assertSame('Female', $sex['F']);
        $this->assertCount(4, $sex);

        $state = avetmiss_codes::get_state_codes();
        $this->assertArrayHasKey('@@', $state,
            "'@@' is the standard's own not-stated value for State identifier: "
            . "\"If State identifier is not '@@', then State identifier and Postcode "
            . 'combination must match" (Collection Specifications, Client file).');
        $this->assertSame('New South Wales', $state['01']);
        $this->assertSame('Other (Overseas but not an Australian Territory or Dependency)', $state['99']);
    }

    /**
     * Every key must be a bare four-character identifier or NCVER's '@@@@'.
     *
     * Guards against a regenerated array picking up a header row, a stray comment, or
     * a label in the key position.
     */
    public function test_code_keys_are_well_formed(): void {
        foreach (['get_country_codes', 'get_language_codes'] as $getter) {
            foreach (avetmiss_codes::$getter() as $code => $label) {
                $code = (string)$code;
                $this->assertMatchesRegularExpression('/^(\d{4}|@{4})$/', $code,
                    "$getter() has a malformed identifier: '$code'");
                $this->assertNotSame('', trim((string)$label), "$getter()['$code'] has an empty label.");
                $this->assertSame(trim((string)$label), (string)$label,
                    "$getter()['$code'] label has leading or trailing whitespace, which would be "
                    . 'padded into a NAT file.');
            }
        }
    }

    /**
     * Delivery mode is a Release 8.0 Y/N triplet, and legacy values convert on output only.
     *
     * The live site this was found on holds a Release 7.0 numeric code on all 12,911 of its
     * enrolments, so the conversion is what makes its NAT00120 valid - and the fact that it
     * happens on output, not in the database, is what makes the change non-destructive.
     */
    public function test_delivery_mode_is_release_8(): void {
        $codes = avetmiss_codes::get_delivery_mode_nat_codes();
        $this->assertCount(8, $codes, 'Release 8.0 defines exactly eight internal/external/workplace combinations.');
        foreach (array_keys($codes) as $c) {
            $this->assertMatchesRegularExpression('/^[YN]{3}$/', (string)$c,
                "Delivery mode must be a three-character Y/N triplet in Release 8.0, got '$c'.");
        }
        $this->assertSame('Internal only (classroom-based)', $codes['YNN']);
        $this->assertSame('Not applicable (recognition of prior learning or credit transfer)', $codes['NNN']);

        // Legacy conversion.
        $this->assertSame('YNN', avetmiss_codes::to_release8_delivery_mode('10'));
        $this->assertSame('NYN', avetmiss_codes::to_release8_delivery_mode('20'));
        $this->assertSame('NNY', avetmiss_codes::to_release8_delivery_mode('30'));
        $this->assertSame('NNN', avetmiss_codes::to_release8_delivery_mode('90'));
        // Already-correct values pass through, including lower case.
        $this->assertSame('YYY', avetmiss_codes::to_release8_delivery_mode('YYY'));
        $this->assertSame('NNN', avetmiss_codes::to_release8_delivery_mode('nnn'));
        // '40 - Other delivery' has no Release 8.0 equivalent and must NOT be invented.
        $this->assertSame('40', avetmiss_codes::to_release8_delivery_mode('40'),
            "Release 8.0's three flags are exhaustive. Mapping '40' would put an invented "
            . 'claim about how training was delivered into a statutory return.');
    }

    /**
     * The form's guarded-field map and the audit's field map must stay in step.
     *
     * If a coded field is added to one and not the other it is either protected but
     * unaudited, or audited but left able to corrupt itself on save.
     */
    public function test_form_and_audit_cover_the_same_fields(): void {
        $formfields = array_keys(\local_rtocompliance\form\student_profile_form::CODE_FIELDS);
        $auditfields = array_keys(\local_rtocompliance\local\codelist_audit::get_coded_fields());
        sort($formfields);
        sort($auditfields);
        $this->assertSame($formfields, $auditfields);
    }

    /**
     * Every getter named by those maps must exist and return a non-empty array.
     */
    public function test_every_mapped_getter_resolves(): void {
        foreach (\local_rtocompliance\local\codelist_audit::get_coded_fields() as $field => $getter) {
            $this->assertTrue(method_exists(avetmiss_codes::class, $getter),
                "codelist_audit maps $field to a getter that does not exist: $getter");
            $codes = avetmiss_codes::$getter();
            $this->assertIsArray($codes);
            $this->assertNotEmpty($codes, "$getter() returned nothing.");
        }
        foreach (\local_rtocompliance\form\student_profile_form::CODE_FIELDS as $field => [$getter, $notstated]) {
            $codes = avetmiss_codes::$getter();
            if ($notstated !== null) {
                $this->assertArrayHasKey($notstated, $codes,
                    "The form declares '$notstated' as the not-stated value for $field and puts it "
                    . "first in the menu, but $getter() does not define it - so the browser's "
                    . 'first-option fallback would land on a real value again.');
            }
        }
    }
}
