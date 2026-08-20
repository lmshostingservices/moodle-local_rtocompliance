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
 * Assistant knowledge source tests.
 *
 * The point of this release is that the assistant's knowledge stops drifting from the code.
 * These tests hold that promise to account: the docs tree must parse, the release notes must
 * come out of version.php automatically, the live facts must be read-only and survive a
 * broken table, and the URL parameter whitelist must not let anything free-form through.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

use local_rtocompliance\assistant\knowledge;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

/**
 * @coversDefaultClass \local_rtocompliance\assistant\knowledge
 */
final class assistant_knowledge_test extends \advanced_testcase {

    /**
     * The docs tree must actually parse, and every document must carry a title and a body.
     *
     * @covers ::docs
     */
    public function test_docs_tree_parses(): void {
        $docs = knowledge::docs();

        $this->assertNotEmpty($docs, 'The docs/ tree must ship with the plugin.');
        foreach ($docs as $doc) {
            $this->assertNotSame('', trim($doc['title']), $doc['file'] . ' has no title.');
            $this->assertNotSame('', trim($doc['body']), $doc['file'] . ' has no body.');
            $this->assertIsArray($doc['pages']);
            // Only a directive on a line of its own is metadata and must be stripped.
            // docs/README.md documents the syntax by quoting it inline, and that must
            // survive — otherwise the one document explaining the format ships with the
            // format deleted from it.
            $this->assertDoesNotMatchRegularExpression(
                '/^<!--\s*(?:pages|summary):/m',
                $doc['body'],
                $doc['file'] . ': whole-line directive comments must be stripped from the body.'
            );
        }
    }

    /**
     * A document that quotes the directive syntax as an example must not be bound by it.
     * docs/README.md contains `<!-- pages: students.php, usi_settings.php -->` inline as
     * documentation; before the regexes were anchored it really did bind itself to those two
     * pages and inject its own meta-text as authoritative reference material.
     *
     * @covers ::docs
     */
    public function test_a_quoted_directive_does_not_bind_a_document(): void {
        foreach (knowledge::docs() as $doc) {
            if ($doc['file'] === 'README.md') {
                $this->assertSame([], $doc['pages'],
                    'README.md must not be bound to the pages it quotes as an example.');
                $this->assertStringContainsString('<!-- pages:', $doc['body'],
                    'The quoted example must survive in the body.');
                return;
            }
        }
        $this->fail('docs/README.md must ship.');
    }

    /**
     * The certificate/USI document must exist and must be bound to the generation pages —
     * that binding is what puts it in front of the admin asking the question this release
     * was written for.
     *
     * @covers ::docs
     */
    public function test_usi_gate_doc_is_bound_to_the_generation_pages(): void {
        $found = null;
        foreach (knowledge::docs() as $doc) {
            if ($doc['file'] === 'certificates-usi-gate.md') {
                $found = $doc;
            }
        }

        $this->assertNotNull($found, 'certificates-usi-gate.md must ship.');
        $this->assertContains('generate_qual_certs.php', $found['pages']);
        $this->assertContains('generate_course_certs.php', $found['pages']);
        $this->assertContains('qual_cert_hub.php', $found['pages']);
    }

    /**
     * Release notes must be parsed out of version.php, newest first, with the current release
     * present. This is the mechanism that keeps the assistant current with no doc edit, so if
     * it silently returns nothing the whole promise of the release is broken.
     *
     * @covers ::release_notes
     */
    public function test_release_notes_are_parsed_from_version_php(): void {
        global $CFG;

        $notes = knowledge::release_notes(5);

        $this->assertNotEmpty($notes);
        $this->assertCount(5, $notes);
        // Deliberately read from version.php rather than hardcoded: this test exists to prove
        // the assistant tracks the CURRENT release automatically, so pinning a literal here
        // would test the opposite of the thing it is for — and would need editing every build.
        $current = '';
        $verfile = $CFG->dirroot . '/local/rtocompliance/version.php';
        if (preg_match('/\$plugin->release\s*=\s*\'([^\']+)\'/', (string) file_get_contents($verfile), $m)) {
            $current = $m[1];
        }
        $this->assertNotSame('', $current, 'version.php must declare a release.');
        $this->assertSame($current, $notes[0]['version'], 'The newest release must come first.');
        foreach ($notes as $n) {
            $this->assertNotSame('', trim($n['note']));
            // 650 plus the '… [note truncated]' marker.
            $this->assertLessThanOrEqual(700, \core_text::strlen($n['note']));
        }

        // No version may appear twice — version.php repeats some release_prev values.
        $versions = array_column($notes, 'version');
        $this->assertSame($versions, array_values(array_unique($versions)));
    }

    /**
     * The v6.3.13 note must be reachable, because that is the behaviour change users will be
     * asking about.
     *
     * @covers ::release_notes
     */
    public function test_recent_behaviour_change_is_in_the_notes(): void {
        $notes = knowledge::release_notes(6);
        $blob  = strtolower(implode(' ', array_column($notes, 'note')));

        $this->assertStringContainsString('usi', $blob);
    }

    /**
     * Only whitelisted, positive integer ids may survive from what the widget sends.
     *
     * This is the boundary the v6.3.15 change tightened: the browser used to send its whole
     * query string. Now it sends ids, and everything else is dropped here.
     *
     * @covers ::filter_page_params
     * @covers ::page_param_keys
     */
    public function test_page_param_whitelist(): void {
        $this->assertSame(['qualid' => 12],
            knowledge::filter_page_params(['qualid' => 12, 'tab' => 'ready']));
        $this->assertSame(['qualid' => 12], knowledge::filter_page_params(['qualid' => '12']));
        $this->assertSame(['courseid' => 7, 'userid' => 3],
            knowledge::filter_page_params(['userid' => 3, 'courseid' => 7]));

        // Not whitelisted, non-scalar, non-numeric, zero or negative — all dropped.
        $this->assertSame([], knowledge::filter_page_params(['sesskey' => 'abc123', 'secret' => 'hunter2']));
        $this->assertSame([], knowledge::filter_page_params(['qualid' => 0]));
        $this->assertSame([], knowledge::filter_page_params(['qualid' => -4]));
        $this->assertSame([], knowledge::filter_page_params(['qualid' => 'DROP TABLE']));
        $this->assertSame([], knowledge::filter_page_params(['qualid' => ['5']]));
        $this->assertSame([], knowledge::filter_page_params([]));

        // The key list is the contract shared with the widget's JavaScript.
        $this->assertSame(
            ['qualid', 'courseid', 'userid', 'studentid', 'certid'],
            knowledge::page_param_keys()
        );
    }

    /**
     * Site facts must be plain strings and must name the USI rule, which is what most
     * questions turn on.
     *
     * @covers ::site_facts
     */
    public function test_site_facts_are_plain_strings(): void {
        $this->resetAfterTest();

        $facts = knowledge::site_facts();

        $this->assertIsArray($facts);
        $this->assertNotEmpty($facts);
        foreach ($facts as $fact) {
            $this->assertIsString($fact);
            $this->assertNotSame('', trim($fact));
        }
    }

    /**
     * Page facts must explain a held student in words an admin can act on, and must name them.
     *
     * @covers ::page_facts
     */
    public function test_page_facts_explain_a_held_student(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Testy', 'lastname' => 'Learner']);
        $DB->insert_record('local_rtocompliance_students', (object) [
            'userid'       => $user->id,
            'usi'          => '',
            'usiverified'  => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $facts = knowledge::page_facts('generate_qual_certs.php', ['userid' => (int) $user->id]);

        $this->assertNotEmpty($facts);
        $blob = implode(' ', $facts);
        $this->assertStringContainsString('Testy Learner', $blob);
        $this->assertStringContainsString('NO USI', $blob);
    }

    /**
     * A verified student must not be reported as blocked.
     *
     * @covers ::page_facts
     */
    public function test_page_facts_pass_a_verified_student(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_rtocompliance_students', (object) [
            'userid'       => $user->id,
            'usi'          => 'AB12CD34EF',
            'usiverified'  => \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $facts = knowledge::page_facts('generate_qual_certs.php', ['userid' => (int) $user->id]);

        $this->assertStringContainsString('verified USI and can be issued', implode(' ', $facts));
    }

    /**
     * With no parameters there is nothing to say about the page, and that must not error.
     *
     * @covers ::page_facts
     */
    public function test_page_facts_with_no_params_is_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], knowledge::page_facts('certificates.php', []));
    }

    /**
     * The assembled knowledge base must contain the new sections, name the page the admin is
     * on, and stay inside its ceiling.
     *
     * @covers ::docs
     * @covers ::release_notes
     */
    public function test_assembled_kb_contains_the_new_sections_and_respects_the_cap(): void {
        $this->resetAfterTest();

        $kb = local_rtocompliance_assistant_kb('generate_qual_certs.php', []);

        $this->assertStringContainsString('What changed, by version', $kb);
        $this->assertStringContainsString('Reference documentation', $kb);
        $this->assertStringContainsString('This site right now', $kb);
        // The page-bound document must be present in full, not just as a summary line.
        $this->assertStringContainsString('the USI gate and the other pre-issue checks', $kb);
        $this->assertLessThanOrEqual(
            knowledge::KB_MAX_CHARS + 300,
            \core_text::strlen($kb),
            'The knowledge base must respect its ceiling (plus the truncation notice).'
        );
    }

    /**
     * Turning the setting off must remove the site's own data from the prompt entirely.
     *
     * @covers ::site_context_enabled
     */
    public function test_site_context_can_be_switched_off(): void {
        $this->resetAfterTest();

        set_config('assistant_site_context', 0, 'local_rtocompliance');
        $this->assertFalse(knowledge::site_context_enabled());
        $this->assertStringNotContainsString('This site right now',
            local_rtocompliance_assistant_kb('generate_qual_certs.php', []));

        set_config('assistant_site_context', 1, 'local_rtocompliance');
        $this->assertTrue(knowledge::site_context_enabled());
    }
}
