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
 * Allowlist of RTO Compliance operational pages that support saved views.
 *
 * The values in this registry are deliberately hand-audited page filters.
 * They are not assembled from request data: page actions, object/context
 * identifiers, upload/download controls, security tokens, and offsets are
 * intentionally absent. An empty value list is useful for a table whose
 * state is entirely client-side (for example, a sortable table).
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Registry for saved-view page query fields.
 */
class saved_view_pages {
    /**
     * Explicitly permitted, user-controlled list/filter fields by page.
     *
     * Keep this list as page basenames. Do not add a request parameter merely
     * because it happens to be accepted by a page; it must be a safe list
     * filter or a display/sort control audited in that page's PHP/HTML/JS.
     *
     * v6.3.32: the rule for adding a page is stated here so it is applied consistently.
 * A page qualifies only if it is a read-only list or report: no request parameter that
 * mutates, exports, downloads or carries a token, and no sesskey handling of its own.
 * That is why ai_usage_report.php, foe_audit.php, support.php and surveys.php were
 * added (they were simply missed), while ai_analysis.php, marketing_info.php,
 * recovery_analyzer.php, tas_consultation.php, natexport.php, qi_export.php,
 * mydocs.php and student_support.php stay out - the first four act on a request, the
 * next two are export endpoints, and the last two authorise a target user inside the
 * page, so one registry capability would state an access rule the page does not have.
 *
 * @var array<string, string[]>
     */
    private const FIELDS = [
        'ai_usage_report.php' => [
            'days',
        ],
        'alerts.php' => [
            'filter',
        ],
        'audit.php' => [
            'entitytype',
            'from',
            'to',
        ],
        'auditlog.php' => [],
        'cert_templates.php' => [
            'showarchived',
        ],
        'certificates.php' => [
            'search',
            'certtype',
            'qualcode',
            'year',
            'datefrom',
            'dateto',
            'usistatus',
            'emailstatus',
            'view',
            'sort',
            'dir',
        ],
        'complaints.php' => [
            'tab',
        ],
        'course_map.php' => [
            'filterq',
        ],
        'data_import.php' => [
            'tab',
            'search',
        ],
        'deadlines.php' => [],
        'feeprotection.php' => [],
        'foe_audit.php' => [],
        'foe_bulk_audit.php' => [
            'datestart',
            'dateend',
        ],
        'governance.php' => [
            'tab',
        ],
        'insurance.php' => [],
        'locations.php' => [],
        'mycerts.php' => [
            'year',
        ],
        'nat_validate.php' => [],
        'qualbuilder.php' => [
            'filter',
        ],
        'qualbuilder_courses.php' => [],
        'qualbuilder_recover.php' => [],
        'qualbuilder_results.php' => [
            'filter',
            'search',
            'rqual',
            'rcat',
            'rusi',
            'rsort',
            'rparent',
            'rsub',
            'rcourse',
        ],
        'qual_cert_hub.php' => [
            'tab',
            'fq',
            'rparent',
            'rsub',
            'rcourse',
            'fcat',
            'q',
        ],
        'qi_report.php' => [
            'year',
        ],
        'reconcile.php' => [],
        'risk.php' => [
            'tab',
        ],
        'rpl.php' => [
            'tab',
        ],
        'skipped_programcodes.php' => [
            'showexcluded',
        ],
        'student_declaration_send.php' => [
            'declfilter',
            'search',
        ],
        'student_enrolments.php' => [],
        'student_profile.php' => [],
        'students.php' => [
            'filter',
            'state',
            'search',
            'perpage',
            'sort',
            'sortdir',
        ],
        'support.php' => [],
        'supervision.php' => [
            'filter',
        ],
        'survey_responses.php' => [
            'type',
            'year',
        ],
        'surveys.php' => [],
        'tas.php' => [],
        'thirdparty.php' => [],
        'trainer_dashboard.php' => [
            'sort',
            'sortdir',
        ],
        'trainers.php' => [
            'status',
            'perpage',
            'sort',
            'sortdir',
        ],
        'transitions.php' => [],
        'usi_settings.php' => [
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
        'validation.php' => [
            'tab',
        ],
        'workforce_management.php' => [],
    ];

    /**
     * Capabilities explicitly required by the corresponding page.
     *
     * Pages with conditional/multiple access checks are intentionally omitted:
     * returning one capability for those pages would imply an access rule that
     * the page itself does not have.
     *
     * @var array<string, string>
     */
    private const CAPABILITIES = [
        'ai_usage_report.php' => 'local/rtocompliance:manage',
        'alerts.php' => 'local/rtocompliance:viewreports',
        'audit.php' => 'local/rtocompliance:manage',
        'auditlog.php' => 'local/rtocompliance:manage',
        'cert_templates.php' => 'local/rtocompliance:managecerttemplates',
        'certificates.php' => 'local/rtocompliance:issuecerts',
        'complaints.php' => 'local/rtocompliance:manage',
        'course_map.php' => 'moodle/site:config',
        'data_import.php' => 'local/rtocompliance:manage',
        'deadlines.php' => 'local/rtocompliance:manage',
        'feeprotection.php' => 'local/rtocompliance:manage',
        'foe_audit.php' => 'local/rtocompliance:manage',
        'foe_bulk_audit.php' => 'local/rtocompliance:manage',
        'governance.php' => 'local/rtocompliance:manage',
        'insurance.php' => 'local/rtocompliance:manage',
        'locations.php' => 'local/rtocompliance:manage',
        'nat_validate.php' => 'local/rtocompliance:manage',
        'qualbuilder.php' => 'local/rtocompliance:manage',
        'qualbuilder_courses.php' => 'local/rtocompliance:manage',
        'qualbuilder_recover.php' => 'local/rtocompliance:manage',
        'qualbuilder_results.php' => 'local/rtocompliance:manage',
        'qual_cert_hub.php' => 'local/rtocompliance:issuecerts',
        'qi_report.php' => 'local/rtocompliance:managesurveys',
        'reconcile.php' => 'local/rtocompliance:manage',
        'risk.php' => 'local/rtocompliance:manage',
        'rpl.php' => 'local/rtocompliance:manage',
        'skipped_programcodes.php' => 'moodle/site:config',
        'student_declaration_send.php' => 'local/rtocompliance:manage',
        'student_enrolments.php' => 'local/rtocompliance:viewall',
        'students.php' => 'local/rtocompliance:manage',
        'support.php' => 'local/rtocompliance:manage',
        'supervision.php' => 'local/rtocompliance:managetrainers',
        'survey_responses.php' => 'local/rtocompliance:managesurveys',
        'surveys.php' => 'local/rtocompliance:managesurveys',
        'tas.php' => 'moodle/site:config',
        'thirdparty.php' => 'local/rtocompliance:manage',
        'trainers.php' => 'local/rtocompliance:managetrainers',
        'transitions.php' => 'local/rtocompliance:manage',
        'usi_settings.php' => 'moodle/site:config',
        'validation.php' => 'local/rtocompliance:manage',
        'workforce_management.php' => 'local/rtocompliance:manage',
    ];

    /**
     * Return the allowlisted fields for a page.
     *
     * A path, query string, or extensionless basename is accepted defensively
     * so callers cannot accidentally create separate namespaces for the same
     * page. Unknown pages have no allowlisted fields.
     *
     * @param string $page Page basename or path.
     * @return string[]
     */
    public static function fields(string $page): array {
        $page = self::normalise_page($page);
        return self::FIELDS[$page] ?? [];
    }

    /**
     * Whether a page is an approved saved-view page.
     *
     * @param string $page Page basename or path.
     * @return bool
     */
    public static function supported(string $page): bool {
        return array_key_exists(self::normalise_page($page), self::FIELDS);
    }

    /**
     * Return every approved page basename.
     *
     * @return string[]
     */
    public static function pages(): array {
        return array_keys(self::FIELDS);
    }

    /**
     * Return the explicitly audited page capability, where one is available.
     *
     * @param string $page Page basename or path.
     * @return string|null
     */
    public static function capability(string $page): ?string {
        $page = self::normalise_page($page);
        return self::CAPABILITIES[$page] ?? null;
    }

    /**
     * Whether the current user can use saved views on a registered page.
     *
     * This is deliberately an access check for the current request only. It
     * never accepts or interprets a target userid/context from a saved-view
     * request. Pages which protect a target context themselves are allowed
     * here only after authentication; the page remains authoritative for
     * deciding whether that target may be viewed.
     *
     * @param string $page Page basename or path.
     * @return bool
     */
    public static function can_access(string $page): bool {
        $page = self::normalise_page($page);
        if (!array_key_exists($page, self::FIELDS) || !isloggedin() || isguestuser()) {
            return false;
        }

        $context = \context_system::instance();

        // trainer_dashboard.php deliberately mirrors its page-level OR gate.
        if ($page === 'trainer_dashboard.php') {
            return has_capability('local/rtocompliance:viewtrainer', $context)
                || has_capability('local/rtocompliance:viewall', $context)
                || has_capability('local/rtocompliance:manage', $context);
        }

        // student_profile.php authorises the requested target userid in the
        // page itself (own profile, or viewall/manage for another user).
        // Saved-view state never contains userid, so this check must not guess
        // which target the current URL will contain.
        if ($page === 'student_profile.php') {
            return true;
        }

        // mycerts.php similarly resolves its optional target and enforces
        // viewown/viewall in the page. Keep this registry check login-only;
        // it has no safe target context to inspect.
        if ($page === 'mycerts.php') {
            return true;
        }

        $capability = self::CAPABILITIES[$page] ?? null;
        return $capability === null || has_capability($capability, $context);
    }

    /**
     * Convert a caller-supplied page identifier to a registry key.
     *
     * @param string $page Page basename, path, or extensionless basename.
     * @return string
     */
    private static function normalise_page(string $page): string {
        $page = trim($page);
        if ($page === '') {
            return '';
        }

        $path = parse_url($page, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $page = $path;
        }
        $page = basename(str_replace('\\', '/', $page));
        if (!preg_match('/\.php$/i', $page)) {
            $page .= '.php';
        }
        return strtolower($page);
    }
}