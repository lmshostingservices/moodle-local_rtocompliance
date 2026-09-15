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
 * Tests for the saved-view page/field allowlist.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

use local_rtocompliance\local\saved_view_pages;

/**
 * The registry must stay explicit and free of request/mutation controls.
 */
class saved_view_pages_test extends \advanced_testcase {
    /**
     * v6.3.32: test_access_rules() creates a user and calls setUser(), so the case has
     * to declare that it changes state. Without this the whole class errored with
     * "unexpected database modification, resetting DB state / unexpected change of
     * $USER" the first time it was run against a real Moodle.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Required student and USI page filters are preserved exactly.
     */
    public function test_required_operational_page_fields(): void {
        $this->assertSame(
            ['filter', 'state', 'search', 'perpage', 'sort', 'sortdir'],
            saved_view_pages::fields('students.php')
        );
        $this->assertSame(
            [
                'usifilter',
                'usisearch',
                'usicat',
                'usisubcat',
                'usicourse',
                'usiclass',
                'usiperpage',
                'usisort',
                'usidir',
                'usirule',
            ],
            saved_view_pages::fields('usi_settings.php')
        );
    }

    /**
     * Audited list pages and their actual view controls are registered.
     */
    public function test_audited_table_and_list_pages_are_registered(): void {
        $expected = [
            'alerts.php' => ['filter'],
            'audit.php' => ['entitytype', 'from', 'to'],
            'auditlog.php' => [],
            'cert_templates.php' => ['showarchived'],
            'certificates.php' => [
                'search', 'certtype', 'qualcode', 'year', 'datefrom', 'dateto',
                'usistatus', 'emailstatus', 'view', 'sort', 'dir',
            ],
            'qualbuilder.php' => ['filter'],
            'qualbuilder_results.php' => [
                'filter', 'search', 'rqual', 'rcat', 'rusi', 'rsort',
                'rparent', 'rsub', 'rcourse',
            ],
            'course_map.php' => ['filterq'],
            'student_declaration_send.php' => ['declfilter', 'search'],
            'supervision.php' => ['filter'],
            'trainer_dashboard.php' => ['sort', 'sortdir'],
            'trainers.php' => ['status', 'perpage', 'sort', 'sortdir'],
            'governance.php' => ['tab'],
            'complaints.php' => ['tab'],
            'risk.php' => ['tab'],
            'rpl.php' => ['tab'],
            'validation.php' => ['tab'],
        ];

        foreach ($expected as $page => $fields) {
            $this->assertTrue(saved_view_pages::supported($page), $page);
            $this->assertSame($fields, saved_view_pages::fields($page), $page);
        }
    }

    /**
     * The published page list is explicit, including pages with no query
     * fields. Keep this assertion in sync with the hand-audited registry so a
     * page cannot be silently added or dropped from the saved-view surface.
     */
    public function test_pages_returns_complete_registry(): void {
        $expected = [
            'ai_usage_report.php',
            'alerts.php',
            'audit.php',
            'auditlog.php',
            'cert_templates.php',
            'certificates.php',
            'complaints.php',
            'course_map.php',
            'data_import.php',
            'deadlines.php',
            'feeprotection.php',
            'foe_audit.php',
            'foe_bulk_audit.php',
            'governance.php',
            'insurance.php',
            'locations.php',
            'mycerts.php',
            'nat_validate.php',
            'qualbuilder.php',
            'qualbuilder_courses.php',
            'qualbuilder_recover.php',
            'qualbuilder_results.php',
            'qual_cert_hub.php',
            'qi_report.php',
            'reconcile.php',
            'risk.php',
            'rpl.php',
            'skipped_programcodes.php',
            'student_declaration_send.php',
            'student_enrolments.php',
            'student_profile.php',
            'students.php',
            'support.php',
            'supervision.php',
            'survey_responses.php',
            'surveys.php',
            'tas.php',
            'thirdparty.php',
            'trainer_dashboard.php',
            'trainers.php',
            'transitions.php',
            'usi_settings.php',
            'validation.php',
            'workforce_management.php',
        ];

        $this->assertSame($expected, saved_view_pages::pages());
    }

    /**
     * Candidate pages with mutation, export, security, target context, or
     * presentation-only state remain outside the operational saved-view list.
     */
    public function test_non_operational_candidate_pages_are_excluded(): void {
        // v6.3.32: ai_usage_report.php, foe_audit.php, support.php and surveys.php were
        // moved INTO the registry. They are read-only reports with no parameter that
        // mutates, exports, downloads or carries a token, and one unambiguous capability
        // each, so they meet the same bar as auditlog.php and insurance.php, which were
        // already registered. The pages below stay out: ai_analysis.php,
        // marketing_info.php, recovery_analyzer.php and tas_consultation.php act on a
        // request; natexport.php and qi_export.php are export endpoints; mydocs.php and
        // student_support.php authorise a target user inside the page, so one registry
        // capability would state an access rule the page does not have.
        foreach ([
            'ai_analysis.php',
            'marketing_info.php',
            'mydocs.php',
            'natexport.php',
            'qi_export.php',
            'recovery_analyzer.php',
            'student_support.php',
            'tas_consultation.php',
        ] as $page) {
            $this->assertFalse(saved_view_pages::supported($page), $page);
            $this->assertSame([], saved_view_pages::fields($page), $page);
        }
    }

    /**
     * The registry never persists mutation, security, context, or pagination
     * controls. This also protects future additions to the page registry.
     */
    public function test_prohibited_request_parameters_are_not_registered(): void {
        $prohibited = [
            'action',
            'confirm',
            'code',
            'download',
            'export',
            'file',
            'importid',
            'id',
            'page',
            'sesskey',
            'targetuserid',
            'token',
            'upload',
            'userid',
            'userids',
        ];

        foreach (saved_view_pages::pages() as $page) {
            foreach (saved_view_pages::fields($page) as $field) {
                $this->assertNotContains($field, $prohibited, $page . ': ' . $field);
            }
        }
    }

    /**
     * Page paths and extensionless basenames resolve to one registry entry.
     */
    public function test_page_normalisation_and_unknown_pages(): void {
        $this->assertTrue(saved_view_pages::supported('/local/rtocompliance/students.php'));
        $this->assertTrue(saved_view_pages::supported('students'));
        $this->assertSame(
            saved_view_pages::fields('students.php'),
            saved_view_pages::fields('https://example.test/local/rtocompliance/students.php?filter=all')
        );
        $this->assertFalse(saved_view_pages::supported('download_cert.php'));
        $this->assertFalse(saved_view_pages::supported('not-a-page.php'));
        $this->assertSame([], saved_view_pages::fields('not-a-page.php'));
        $this->assertNull(saved_view_pages::capability('not-a-page.php'));
    }

    /**
     * Capabilities mirror direct page checks where the page has one.
     */
    public function test_capability_map(): void {
        $this->assertSame(
            'local/rtocompliance:issuecerts',
            saved_view_pages::capability('certificates.php')
        );
        $this->assertSame(
            'moodle/site:config',
            saved_view_pages::capability('usi_settings.php')
        );
        $this->assertSame(
            'local/rtocompliance:managetrainers',
            saved_view_pages::capability('trainers.php')
        );
        $this->assertSame(
            'local/rtocompliance:manage',
            saved_view_pages::capability('students.php')
        );
        $this->assertSame(
            'local/rtocompliance:managesurveys',
            saved_view_pages::capability('qi_report.php')
        );
        $this->assertNull(saved_view_pages::capability('trainer_dashboard.php'));
        $this->assertNull(saved_view_pages::capability('mycerts.php'));
        $this->assertNull(saved_view_pages::capability('student_profile.php'));
    }

    /**
     * Access checks mirror the pages' actual gates, without inventing a
     * target-user context for detail pages.
     */
    public function test_access_rules(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // The ordinary test user can use own-context pages only.
        $this->assertTrue(saved_view_pages::can_access('student_profile.php'));
        $this->assertTrue(saved_view_pages::can_access('mycerts.php'));
        $this->assertFalse(saved_view_pages::can_access('students.php'));
        $this->assertFalse(saved_view_pages::can_access('qi_report.php'));
        $this->assertFalse(saved_view_pages::can_access('trainer_dashboard.php'));
        $this->assertFalse(saved_view_pages::can_access('not-a-page.php'));

        $this->setAdminUser();
        $this->assertTrue(saved_view_pages::can_access('students.php'));
        $this->assertTrue(saved_view_pages::can_access('qi_report.php'));
        $this->assertTrue(saved_view_pages::can_access('trainer_dashboard.php'));
    }
}