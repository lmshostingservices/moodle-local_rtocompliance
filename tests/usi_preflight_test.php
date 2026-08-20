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
 * USI preflight tests (v6.3.13).
 *
 * The certificate generation pages must be able to answer, before the admin ticks anything,
 * the question the issuance gate answers after they press Generate: would this student's
 * certificate be refused?  Every assertion here is about the two agreeing — a preflight that
 * says "issuable" where the gate refuses is the exact defect this release fixes, only moved
 * one layer up.
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
final class usi_preflight_test extends \advanced_testcase {

    /**
     * Create a Moodle user plus an optional RTO Compliance student row.
     *
     * @param  string|null $usi         null = create no student row at all
     * @param  int         $usiverified verification status constant
     * @return int the Moodle user id
     */
    private function make_student(?string $usi, int $usiverified = 0): int {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        if ($usi !== null) {
            $DB->insert_record('local_rtocompliance_students', (object) [
                'userid'       => $user->id,
                'usi'          => $usi,
                'usiverified'  => $usiverified,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }
        return (int) $user->id;
    }

    /**
     * A student with a registry-verified USI is the only one that can be issued.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_verified_usi_can_issue(): void {
        $this->resetAfterTest();
        $uid = $this->make_student('AB12CD34EF', \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED);

        $map = local_rtocompliance_usi_issue_status_map([$uid]);

        $this->assertTrue($map[$uid]['canissue']);
        $this->assertSame('verified', $map[$uid]['status']);
        $this->assertSame('AB12CD34EF', $map[$uid]['usi']);
    }

    /**
     * A blank USI must be held — this is the reported Adrian Huang case.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_blank_usi_is_held(): void {
        $this->resetAfterTest();
        $uid = $this->make_student('');

        $map = local_rtocompliance_usi_issue_status_map([$uid]);

        $this->assertFalse($map[$uid]['canissue']);
        $this->assertSame('missing', $map[$uid]['status']);
        $this->assertNotEmpty($map[$uid]['reason'], 'A held student must carry a human-readable reason.');
    }

    /**
     * A USI that is present but not verified must be held too — presence is not enough.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_unverified_usi_is_held(): void {
        $this->resetAfterTest();
        $uid = $this->make_student('AB12CD34EF', 0);

        $map = local_rtocompliance_usi_issue_status_map([$uid]);

        $this->assertFalse($map[$uid]['canissue']);
        $this->assertSame('unverified', $map[$uid]['status']);
    }

    /**
     * A user with no student row at all must be held, not silently treated as verified.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_missing_student_record_is_held(): void {
        $this->resetAfterTest();
        $uid = $this->make_student(null);

        $map = local_rtocompliance_usi_issue_status_map([$uid]);

        $this->assertArrayHasKey($uid, $map, 'Every requested userid must appear in the map.');
        $this->assertFalse($map[$uid]['canissue']);
        $this->assertSame('norecord', $map[$uid]['status']);
    }

    /**
     * The map must be resolvable for a mixed cohort in one call — the generation pages build
     * a whole worklist from it.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_mixed_cohort_resolves_in_one_call(): void {
        $this->resetAfterTest();
        $ok      = $this->make_student('AB12CD34EF', \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED);
        $blank   = $this->make_student('');
        $unver   = $this->make_student('ZZ99YY88XX', 0);
        $norec   = $this->make_student(null);

        $map = local_rtocompliance_usi_issue_status_map([$ok, $blank, $unver, $norec]);

        $this->assertCount(4, $map);
        $this->assertTrue($map[$ok]['canissue']);
        $this->assertFalse($map[$blank]['canissue']);
        $this->assertFalse($map[$unver]['canissue']);
        $this->assertFalse($map[$norec]['canissue']);
    }

    /**
     * An empty request must not query anything or invent entries.
     *
     * @covers ::local_rtocompliance_usi_issue_status_map
     */
    public function test_empty_input_returns_empty_map(): void {
        $this->resetAfterTest();
        $this->assertSame([], local_rtocompliance_usi_issue_status_map([]));
    }

    /**
     * The gated-type list must match the runtime gate exactly.  'completion' is NOT gated —
     * that is why Generate by Course appeared to work while Generate by Qualification did not.
     *
     * @covers ::local_rtocompliance_usi_certtypes_are_gated
     * @covers ::local_rtocompliance_usi_gated_certtypes
     */
    public function test_only_aqf_certificate_types_are_gated(): void {
        $this->assertTrue(local_rtocompliance_usi_certtypes_are_gated(['testamur']));
        $this->assertTrue(local_rtocompliance_usi_certtypes_are_gated(['record']));
        $this->assertTrue(local_rtocompliance_usi_certtypes_are_gated(['statement']));
        $this->assertTrue(local_rtocompliance_usi_certtypes_are_gated(['testamur', 'record']));
        $this->assertFalse(local_rtocompliance_usi_certtypes_are_gated(['completion']));
        $this->assertFalse(local_rtocompliance_usi_certtypes_are_gated([]));
        $this->assertSame(
            ['testamur', 'record', 'statement'],
            local_rtocompliance_usi_gated_certtypes()
        );
    }

    /**
     * The badge must name the status; a verified badge shows the USI, a held one never
     * claims to be verified.
     *
     * @covers ::local_rtocompliance_usi_status_badge
     */
    public function test_status_badge_reflects_status(): void {
        $verified = local_rtocompliance_usi_status_badge(
            ['status' => 'verified', 'usi' => 'AB12CD34EF', 'canissue' => true, 'reason' => '']);
        $this->assertStringContainsString('Verified', $verified);
        $this->assertStringContainsString('AB12CD34EF', $verified);

        $missing = local_rtocompliance_usi_status_badge(
            ['status' => 'missing', 'usi' => '', 'canissue' => false, 'reason' => '']);
        $this->assertStringContainsString('Missing', $missing);

        // An unknown status must degrade to a held-looking badge, never to "Verified".
        $junk = local_rtocompliance_usi_status_badge(['status' => 'not-a-real-status']);
        $this->assertStringNotContainsString('>Verified<', $junk);
    }

    /**
     * The callout is silent when nothing is held, and names the numbers when something is.
     *
     * @covers ::local_rtocompliance_usi_blocked_callout
     */
    public function test_blocked_callout_only_renders_when_something_is_held(): void {
        $this->assertSame('', local_rtocompliance_usi_blocked_callout(0, 25));

        $html = local_rtocompliance_usi_blocked_callout(3, 25);
        $this->assertStringContainsString('3 of 25', $html);
        $this->assertStringContainsString('no verified USI', $html);
    }

    /**
     * The fix link must point at the student's own profile, so the admin can correct the USI
     * without hunting for them.
     *
     * @covers ::local_rtocompliance_usi_fix_link
     */
    public function test_fix_link_targets_the_student_profile(): void {
        $this->resetAfterTest();
        $link = local_rtocompliance_usi_fix_link(4242);
        $this->assertStringContainsString('student_profile.php', $link);
        $this->assertStringContainsString('userid=4242', $link);
    }

    /**
     * The generation summary must survive the redirect that follows write_close(), and must
     * be delivered exactly once.
     *
     * @covers ::local_rtocompliance_stash_gen_summary
     * @covers ::local_rtocompliance_pop_gen_summary
     */
    public function test_gen_summary_round_trips_once(): void {
        $this->resetAfterTest();
        $key = 'unittest_' . uniqid();

        $this->assertNull(local_rtocompliance_pop_gen_summary($key), 'Nothing stashed yet.');

        local_rtocompliance_stash_gen_summary($key, 'Issued: 2 certificate(s).', 'success');

        $got = local_rtocompliance_pop_gen_summary($key);
        $this->assertNotNull($got);
        $this->assertSame('Issued: 2 certificate(s).', $got['summary']);
        $this->assertSame('success', $got['type']);

        $this->assertNull(local_rtocompliance_pop_gen_summary($key), 'A summary must be shown once, not on every reload.');
    }

    /**
     * Two admins generating at the same time must not read each other's summary.
     *
     * @covers ::local_rtocompliance_stash_gen_summary
     * @covers ::local_rtocompliance_pop_gen_summary
     */
    public function test_gen_summary_keys_do_not_collide(): void {
        $this->resetAfterTest();
        local_rtocompliance_stash_gen_summary('qualcerts_11_5', 'admin one', 'success');
        local_rtocompliance_stash_gen_summary('qualcerts_22_5', 'admin two', 'warning');

        $this->assertSame('admin one', local_rtocompliance_pop_gen_summary('qualcerts_11_5')['summary']);
        $this->assertSame('admin two', local_rtocompliance_pop_gen_summary('qualcerts_22_5')['summary']);
    }
}
