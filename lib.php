<?php
defined('MOODLE_INTERNAL') || die();
// FIX-LIB-DOUBLE-LOAD (v5.9.55): Guard against lib.php being loaded twice on symlinked
// Moodle installs (confirmed root cause of a 4-week HTTP 500 on soa_issue.php and
// support.php at a customer Moodle installation).
//
// THE PROBLEM:
// settings.php line 5 uses: require_once($CFG->dirroot . '/local/rtocompliance/lib.php')
// soa_issue.php line 47 uses: require_once(__DIR__ . '/lib.php')
// support.php (and other admin pages) follow the same __DIR__ pattern.
//
// On a Moodle install with symlinked directories (e.g. the Moodle webroot is a symlink),
// $CFG->dirroot and __DIR__ resolve to DIFFERENT PATH STRINGS even though they point to
// the same physical file. PHP's require_once uses the literal path string as its cache key,
// NOT the real/resolved path, so it cannot detect the duplicate → lib.php executes a second
// time → PHP fatal "Cannot redeclare function local_rtocompliance_extend_navigation_frontpage"
// (first function in the file) → blank HTTP 500 with empty body.
//
// THE TIMING:
// 1. soa_issue.php line 47: require_once(__DIR__ . '/lib.php')  → first load, all functions defined
// 2. admin_externalpage_setup() processes the admin tree, which triggers settings.php
// 3. settings.php line 5: require_once($CFG->dirroot . '/local/rtocompliance/lib.php')
//    → PHP sees a DIFFERENT path string → tries to execute lib.php again → fatal
//
// THE FIX:
// Define a constant on first load. Any subsequent require_once that PHP does not detect
// as a duplicate (because the path string differs) will still reach this guard, see the
// constant is already defined, and return immediately — preventing any function redeclaration.
if (defined('LOCAL_RTOCOMPLIANCE_LIB_LOADED')) { return; }
define('LOCAL_RTOCOMPLIANCE_LIB_LOADED', true);

function local_rtocompliance_extend_navigation_frontpage(navigation_node $frontpage) {
    if (has_capability('local/rtocompliance:manage', context_system::instance())) {
        $frontpage->add(
            get_string('pluginname', 'local_rtocompliance'),
            new moodle_url('/local/rtocompliance/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            null,
            new pix_icon('i/settings', '')
        );
    }
}

function local_rtocompliance_extend_settings_navigation($settingsnav, $context) {
    global $PAGE;

    if ($PAGE->context->contextlevel != CONTEXT_SYSTEM) {
        return;
    }

    if (!has_capability('local/rtocompliance:manage', context_system::instance())) {
        return;
    }

    // Ensure the body always carries the path-local-rtocompliance class on RTOC admin pages.
    // admin_externalpage_setup() sets pagetype to 'admin-setting-local_rtocompliance_*', so
    // Moodle generates a body class like 'page-type-admin-setting-...' instead of the
    // URL-derived 'path-local-rtocompliance-*'. All styles.css rules are scoped to
    // [class*="path-local-rtocompliance"], so without this explicit add_body_class() call
    // the entire stylesheet is silently ignored on admin pages (selectors never match).
    if (!empty($PAGE->url) && strpos($PAGE->url->get_path(), '/local/rtocompliance/') !== false) {
        $PAGE->add_body_class('path-local-rtocompliance');
    }

    if ($settingnode = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN)) {
        $node = $settingnode->add(
            get_string('pluginname', 'local_rtocompliance'),
            new moodle_url('/local/rtocompliance/index.php'),
            navigation_node::TYPE_CONTAINER,
            null,
            'rtocompliance',
            new pix_icon('i/settings', '')
        );

        $menuitems = [
            ['getting_started', 'index.php'],
            ['qualificationbuilder', 'qualbuilder.php'],
            ['student_results', 'qualbuilder_results.php'],
            ['student_records', 'students.php'],
            ['certificates', 'certificates.php'],
            ['cert_templates', 'cert_templates.php'],
            ['cert_test_link', 'cert_test.php'],
            ['trainers', 'trainers.php'],
            ['supervision_log', 'supervision.php'],
            ['natexport', 'natexport.php'],
            ['surveys', 'surveys.php'],
            ['complaints_appeals', 'complaints.php'],
            ['validation', 'validation.php'],
            ['tas', 'tas.php'],
            ['thirdparty', 'thirdparty.php'],
            ['governance', 'governance.php'],
            ['rpl_credit', 'rpl.php'],
            ['risk_management', 'risk.php'],
            ['feeprotection', 'feeprotection.php'],
            ['insurance', 'insurance.php'],
            ['transitions', 'transitions.php'],
            ['testdata', 'test_data.php'],
            ['support_internal', 'support.php'],
            ['support', 'https://lms-labs.com/docs/rto-compliance'],
            ['dataimport', 'data_import.php'],
        ];

        foreach ($menuitems as $item) {
            $stringkey = $item[0];
            $urlpath = $item[1];
            
            if (strpos($urlpath, 'http') === 0) {
                $url = new moodle_url($urlpath);
            } else {
                $url = new moodle_url('/local/rtocompliance/' . $urlpath);
            }
            $node->add(
                get_string($stringkey, 'local_rtocompliance'),
                $url,
                navigation_node::TYPE_SETTING
            );
        }
    }
}

function local_rtocompliance_pluginfile($course, $birecord_or_cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }
    require_login();

    // CERT-TEMPLATE-BUILDER (v4.2.40) — background + per-field images use
    // the managecerttemplates capability instead of the broader manage cap.
    // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — added 'rto_branding' filearea
    // for the system-wide RTO logo + CEO signature uploaded via the new
    // RTO Branding panel.  Same managecerttemplates capability gates it.
    $tmplareas = ['cert_template_bg', 'cert_template_image', 'rto_branding'];
    if (in_array($filearea, $tmplareas, true)) {
        require_capability('local/rtocompliance:managecerttemplates', $context);
    } else {
        $allowedareas = ['consultation_evidence', 'trainer_evidence', 'supervision_evidence', 'certificate9b'];
        if (!in_array($filearea, $allowedareas, true)) {
            return false;
        }
        require_capability('local/rtocompliance:manage', $context);
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_rtocompliance', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function local_rtocompliance_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('local/rtocompliance:manage', $context)) {
        $navigation->add(
            get_string('rtocompliance_settings', 'local_rtocompliance'),
            new moodle_url('/local/rtocompliance/course_settings.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'rtocompliance_course_settings',
            new pix_icon('i/settings', '')
        );
    }
}

/**
 * Inject the sidebar HTML exactly once per request, regardless of which code path fires.
 *
 * Both the legacy local_rtocompliance_before_footer() callback and the Moodle 4.3+ Hook
 * callback in classes/hook/before_footer_html_generation.php call this helper.
 * The static $injected guard ensures the sidebar is never duplicated when both paths run
 * on the same request (e.g. Moodle 5.0+ where legacy callbacks and hooks both fire).
 *
 * @return string  Sidebar HTML on first call; empty string on subsequent calls.
 */
function local_rtocompliance_inject_sidebar_once(): string {
    static $injected = false;
    if ($injected) {
        return '';
    }
    $injected = true;
    return local_rtocompliance_render_sidebar();
}

/**
 * Inject sidebar navigation + table-sorting JavaScript (RTOC pages only).
 * Called before the footer is output on ALL Moodle versions.
 *
 * On Moodle 4.3+ the Hook callback also fires, but local_rtocompliance_inject_sidebar_once()
 * ensures the sidebar HTML is only emitted once via the static guard above.
 *
 * IMPORTANT: Do NOT add any site-wide JS here. Pre-defining core/first or
 * overriding requirejs.onError in the footer breaks Moodle's primary/secondary
 * navigation by replacing the real core/first module with a noop before Moodle's
 * async AMD loader has finished fetching it. See upgrade.php v3.7.78 and v3.8.19.
 */
function local_rtocompliance_before_footer() {
    global $PAGE;

    if (empty($PAGE->url)) {
        return;
    }

    $path = $PAGE->url->get_path();

    if (strpos($path, '/local/rtocompliance/') === false) {
        return;
    }

    // v4.4.27 CSP-LEGACY-CALLBACK: This legacy before_footer() callback runs on
    // Moodle 4.x. The inline $sorting_script heredoc was being echoed directly here,
    // which Moodle 4.3+ CSP (script-src 'self') silently blocks — causing a blank page.
    // v4.4.26 fixed the new Hook class but missed THIS legacy function which runs on
    // the same request. Fix: serve the same external js/tablesorter.js file.
    // A static guard prevents double-injection if both this callback AND the Hook
    // class fire on the same request (Moodle 5.0+).
    static $tablesorter_injected = false;
    if (!$tablesorter_injected) {
        $tablesorter_injected = true;
        $tablesorter_url = (new moodle_url('/local/rtocompliance/js/tablesorter.js'))->out();
        echo '<script src="' . s($tablesorter_url) . '"></script>';
    }

    // v4.4.40 TABLES-FOOTER: tables.js must load on every plugin page, including
    // trainer_dashboard.php which uses $OUTPUT->header() directly and never calls
    // render_nav_header(). init() in tables.js is fully idempotent — the
    // closestScrollContainer + addToolbar duplicate-guard make a second run a no-op
    // on pages that already received tables.js via render_nav_header().
    static $tables_injected = false;
    if (!$tables_injected) {
        $tables_injected = true;
        $tables_url = (new moodle_url('/local/rtocompliance/js/tables.js'))->out();
        echo '<script src="' . s($tables_url) . '"></script>';
    }
}

/**
 * Render the RTO Compliance navigation header bar.
 * Provides consistent navigation across all plugin pages.
 * 
 * @param string $current_page The current page title
 * @param string|null $parent_page Optional parent page for breadcrumb
 * @param string|null $parent_url Optional parent page URL
 * @return string HTML for the navigation header
 */
function local_rtocompliance_render_nav_header($current_page, $parent_page = null, $parent_url = null, $help_anchor = '') {
    global $PAGE;
    
    // NOTE: Do NOT call $PAGE->requires->css() here - it crashes after header output
    // CSS is automatically loaded by Moodle from styles.css in the plugin folder

    // Build the sidebar HTML directly (server-side flex layout approach v3.8.67).
    // The sidebar is rendered as a flex sibling of the content — no JS positioning needed.
    // inject_sidebar_once() is no longer called here; sidebar renders unconditionally.
    $sidebar_html = local_rtocompliance_render_sidebar();

    $dashboardurl = new moodle_url('/local/rtocompliance/index.php');
    $isdashboard = ($PAGE->url->compare($dashboardurl, URL_MATCH_BASE));

    // Open the two-column flex layout wrapper.
    // rtoc-layout-wrap and rtoc-main-content are intentionally left UNCLOSED here.
    // The browser auto-closes them when #region-main ends — this produces valid layout.
    $wrap = '<div class="rtoc-layout-wrap">' . $sidebar_html . '<div class="rtoc-main-content">';

    // Dashboard: no breadcrumb header — just open the layout and return.
    if ($isdashboard) {
        return $wrap;
    }

    $html = $wrap . '<div class="rtoc-nav-header">';
    $html .= '<div class="rtoc-nav-left">';
    
    // Dashboard button
    $html .= '<a href="' . $dashboardurl->out() . '" class="rtoc-nav-dashboard-btn">';
    $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="rtoc-nav-icon"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>';
    $html .= '<span>Dashboard</span>';
    $html .= '</a>';
    
    // Breadcrumb separator and trail
    $html .= '<span class="rtoc-nav-separator">/</span>';
    
    if ($parent_page && $parent_url) {
        $html .= '<a href="' . $parent_url . '" class="rtoc-nav-breadcrumb-link">' . s($parent_page) . '</a>';
        $html .= '<span class="rtoc-nav-separator">/</span>';
    }
    
    $html .= '<span class="rtoc-nav-current">' . s($current_page) . '</span>';
    
    $html .= '</div>';
    
    // Right side - Help button
    $supporturl = new moodle_url('/local/rtocompliance/support.php');
    $helplink = $supporturl->out() . ($help_anchor ? '#' . $help_anchor : '');
    $html .= '<div class="rtoc-nav-right">';
    $html .= '<a href="' . $helplink . '" class="rtoc-nav-help-btn" target="_blank" title="Help &amp; Support">';
    $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="rtoc-nav-icon"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>';
    $html .= '<span>Help</span>';
    $html .= '</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

function local_rtocompliance_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;
    
    if (!has_capability('local/rtocompliance:viewown', context_user::instance($user->id))) {
        return;
    }

    $category = new core_user\output\myprofile\category(
        'rtocompliance',
        get_string('rtocomplianceinfo', 'local_rtocompliance'),
        'contact'
    );
    $tree->add_category($category);

    $certs = \local_rtocompliance\cache_helper::get_user_certificate_count($user->id);
    $tree->add_node(new core_user\output\myprofile\node(
        'rtocompliance',
        'mydocuments',
        get_string('mydocuments', 'local_rtocompliance'),
        null,
        new moodle_url('/local/rtocompliance/mydocs.php', ['userid' => $user->id])
    ));
    $tree->add_node(new core_user\output\myprofile\node(
        'rtocompliance',
        'certificates',
        get_string('mycertificates', 'local_rtocompliance') . ': ' . $certs,
        null,
        new moodle_url('/local/rtocompliance/mycerts.php', ['userid' => $user->id])
    ));
    
    if ($iscurrentuser && local_rtocompliance_user_requires_avetmiss($user->id)) {
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $user->id]);
        $profilestatus = '';
        if (!$student || !$student->profilecomplete) {
            $profilestatus = ' (' . get_string('incomplete', 'local_rtocompliance') . ')';
        }
        
        $tree->add_node(new core_user\output\myprofile\node(
            'rtocompliance',
            'avetmissprofile',
            get_string('myavetmissprofile', 'local_rtocompliance') . $profilestatus,
            null,
            new moodle_url('/local/rtocompliance/my_profile.php')
        ));
    }
}

function local_rtocompliance_generate_certificate_token() {
    return bin2hex(random_bytes(16));
}

/**
 * Publish a newly issued certificate to the AI Grader central verification registry.
 *
 * Best-effort, fire-and-forget. Any curl error or non-200 response is logged at
 * DEBUG_DEVELOPER level but does NOT abort the calling issuance workflow —
 * the Moodle DB record is always the authoritative source of truth.
 *
 * Publishing here enables QR codes on printed certificates to resolve via
 * essaygradeai.app/verify/<token> independently of the RTO's Moodle server.
 *
 * @param stdClass $cert  The newly inserted cert row (id, verifytoken, certnumber, etc.)
 * @param stdClass $user  The Moodle student user record.
 */
function local_rtocompliance_publish_cert_to_registry(stdClass $cert, stdClass $user): void {
    $siteid = get_config('local_aiconfig', 'siteid') ?: get_config('local_rtocompliance', 'siteid');
    $apikey = get_config('local_aiconfig', 'apikey') ?: get_config('local_rtocompliance', 'apikey');
    $apiurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

    if (empty($siteid) || empty($apikey) || empty($cert->verifytoken)) {
        return; // Plugin not configured for platform API — silently skip.
    }

    $certstatusmap = ['issued' => 'active', 'superseded' => 'superseded', 'revoked' => 'revoked'];
    $payload = json_encode([
        'siteId'             => (string) $siteid,
        'apiKey'             => (string) $apikey,
        'token'              => (string) $cert->verifytoken,
        'certNumber'         => (string) ($cert->certnumber ?? ''),
        'certType'           => (string) ($cert->certtype ?? 'completion'),
        'qualificationCode'  => $cert->qualificationcode ?? null,
        'qualificationName'  => $cert->qualificationname ?? null,
        'studentFirstName'   => (string) ($user->firstname ?? ''),
        'studentLastInitial' => substr((string) ($user->lastname ?? ''), 0, 1),
        'rtoName'            => (string) (get_config('local_rtocompliance', 'rtoname')
                                    ?: get_config('moodle', 'fullname')
                                    ?: 'Training Organisation'),
        'issueDate'          => (int) ($cert->issuedate ?? time()),
        'expiryDate'         => !empty($cert->expirydate) ? (int) $cert->expirydate : null,
        'status'             => $certstatusmap[$cert->status ?? 'issued'] ?? 'active',
    ]);

    try {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'     => 8,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $raw      = $curl->post($apiurl . '/api/cert-registry/publish', $payload);
        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        if ($httpcode !== 200) {
            debugging(
                'local_rtocompliance_publish_cert_to_registry: HTTP ' . $httpcode
                    . ' for cert ' . ($cert->certnumber ?? '?')
                    . ' — ' . substr((string) ($raw ?? ''), 0, 200),
                DEBUG_DEVELOPER
            );
        }
    } catch (\Throwable $e) {
        debugging('local_rtocompliance_publish_cert_to_registry: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Update the status of an existing certificate in the AI Grader central registry.
 *
 * Call this when a cert is voided (force-regen supersede) so that scanning
 * the old QR code shows "Superseded" instead of "Valid".
 * Best-effort — failures are logged but never propagate to the caller.
 *
 * @param string $verifytoken  The verifytoken of the cert whose status is changing.
 * @param string $status       New status: 'superseded' | 'revoked'
 */
function local_rtocompliance_update_registry_status(string $verifytoken, string $status): void {
    if (empty($verifytoken)) {
        return;
    }
    $siteid = get_config('local_aiconfig', 'siteid') ?: get_config('local_rtocompliance', 'siteid');
    $apikey = get_config('local_aiconfig', 'apikey') ?: get_config('local_rtocompliance', 'apikey');
    $apiurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

    if (empty($siteid) || empty($apikey)) {
        return;
    }

    // Publish with just the token + status; the endpoint does an upsert so
    // the existing record gets its status column updated without touching other fields.
    // We send a minimal but schema-valid payload (certNumber/certType/etc. are
    // ignored on update — only status + updatedAt change, see routes.ts).
    $payload = json_encode([
        'siteId'             => (string) $siteid,
        'apiKey'             => (string) $apikey,
        'token'              => $verifytoken,
        'certNumber'         => 'VOID',    // ignored on update
        'certType'           => 'completion', // ignored on update
        'studentFirstName'   => '',         // ignored on update
        'studentLastInitial' => '',         // ignored on update
        'rtoName'            => '',         // ignored on update
        'issueDate'          => time(),     // ignored on update
        'status'             => $status,
    ]);

    try {
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 5, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $raw      = $curl->post($apiurl . '/api/cert-registry/publish', $payload);
        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        if ($httpcode !== 200) {
            debugging(
                'local_rtocompliance_update_registry_status: HTTP ' . $httpcode
                    . ' token=' . substr($verifytoken, 0, 8) . '… — ' . substr((string)($raw ?? ''), 0, 200),
                DEBUG_DEVELOPER
            );
        }
    } catch (\Throwable $e) {
        debugging('local_rtocompliance_update_registry_status: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

function local_rtocompliance_get_state_regulators() {
    return [
        // National regulator — covers QLD, NSW, SA, ACT, NT, TAS (and international) for RTO registration.
        'asqa'          => 'ASQA (Australian Skills Quality Authority — National)',
        // State-based RTO registration regulators.
        'vrqa_vic'      => 'VRQA (Victorian Registration and Qualifications Authority — VIC)',
        'tac_wa'        => 'TAC (Training Accreditation Council — WA)',
        // State/territory training authorities — relevant for state-funded training and AVETMISS reporting.
        'desbt_qld'     => 'DESBT (Dept of Employment, Small Business & Training — QLD)',
        'skills_nsw'    => 'Skills NSW (Department of Education — NSW)',
        'sa_skills'     => 'SA Skills (Dept for Innovation and Skills — SA)',
        'dtwd_wa'       => 'DTWD (Dept of Training and Workforce Development — WA)',
        'tasc_tas'      => 'TASC (Tasmanian Assessment, Standards and Certification — TAS)',
        'skills_tas'    => 'Skills Tasmania (TAS)',
        'skills_act'    => 'Skills Canberra (ACT)',
        'nt_ditt'       => 'NT DITT (Dept of Industry, Tourism and Trade — NT)',
    ];
}

function local_rtocompliance_get_certificate_types() {
    return [
        'testamur'   => get_string('cert_testamur', 'local_rtocompliance'),
        'statement'  => get_string('cert_statement', 'local_rtocompliance'),
        'record'     => get_string('cert_record', 'local_rtocompliance'),
        // CERT-OF-COMPLETION (v4.2.41) — non-accredited / general training.
        'completion' => get_string('cert_completion', 'local_rtocompliance'),
    ];
}

function local_rtocompliance_is_course_nationally_recognised($courseid) {
    $settings = \local_rtocompliance\cache_helper::get_course_settings($courseid);
    return $settings && !empty($settings->nationallyrecognised);
}

// ── v4.7.104 BULK-COURSE-CERTS — Smart cert type resolution ──────────────────

/**
 * Extracts an Australian unit code from the start of a course name or shortname.
 *
 * Unit code pattern: 2–8 uppercase letters + 3–6 digits + optional 1–2 trailing letters.
 * Examples: BSBFIN301, CHCECE001, HLTAAP001, SITHCCC006, BSBWHS211, MSAPMOPS200A.
 *
 * @param  string $name  Course fullname or shortname.
 * @return string        Detected unit code (uppercased), or empty string if none found.
 */
function local_rtocompliance_extract_unit_code_from_name(string $name): string {
    if (preg_match('/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/i', trim($name), $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

/**
 * Determine which certificate type(s) to issue for a student who completed a course.
 *
 * Smart logic:
 *   - Not nationally recognised (DB flag) AND no unit code in course name   → completion
 *   - Course name/shortname starts with an Australian unit code              → statement (auto-detected)
 *   - Nationally recognised + qualbuilder skillset/singleunit               → statement
 *   - Nationally recognised + qualification + all unit-courses complete     → testamur + record
 *   - Nationally recognised + qualification + some unit-courses complete    → statement
 *   - Nationally recognised + no qualbuilder link                           → statement
 *
 * @param int $courseid  Moodle course ID.
 * @param int $userid    Student user ID (0 = generic/type-only, skips per-student completion check).
 * @return array  Keys: certtypes (array), qualificationcode, qualificationname, units (array), reason.
 */
function local_rtocompliance_resolve_cert_types_for_course(int $courseid, int $userid): array {
    global $DB;

    $empty = ['certtypes' => ['completion'], 'qualificationcode' => '', 'qualificationname' => '', 'units' => [], 'reason' => 'non_accredited'];

    $coursesettings  = $DB->get_record('local_rtocompliance_courses', ['courseid' => $courseid]);
    $course          = $DB->get_record('course', ['id' => $courseid], 'fullname, shortname');
    $coursefullname  = $course ? $course->fullname  : '';
    $courseshortname = $course ? $course->shortname : '';

    // Step 1: not nationally recognised in DB → try unit-code auto-detection before giving up
    if (!$coursesettings || empty($coursesettings->nationallyrecognised)) {
        // Auto-detect: if course fullname OR shortname begins with an Australian unit code,
        // the course is nationally accredited → issue a Statement of Attainment.
        $detectedcode = local_rtocompliance_extract_unit_code_from_name($coursefullname)
                     ?: local_rtocompliance_extract_unit_code_from_name($courseshortname);
        if ($detectedcode) {
            return [
                'certtypes'        => ['statement'],
                'qualificationcode' => $detectedcode,
                'qualificationname' => $coursefullname,
                'units'            => [],
                'reason'           => 'unit_code_detected',
            ];
        }
        return array_merge($empty, ['qualificationname' => $coursefullname]);
    }

    // Step 2: find qualbuilder product that has a unit linked to this course.
    // Primary lookup: qualunits.courseid (the main delivery course for each unit).
    $qualbuilder = $DB->get_record_sql(
        "SELECT qb.*
         FROM {local_rtocompliance_qualbuilder} qb
         JOIN {local_rtocompliance_qualunits} qu ON qu.qualbuilderid = qb.id
         WHERE qu.courseid = :courseid
           AND qb.status = 'active'
         ORDER BY qb.id ASC
         LIMIT 1",
        ['courseid' => $courseid]
    );

    // VARIANT-GAP-FIX (v5.9.294): if the course is not a primary delivery course for any
    // unit, also check qualunit_courses (variant/archive courses). Without this, the
    // "Generate by Course" page showed the wrong cert type (SoA with no qual code) when
    // an admin selected a variant course — the qualbuilder lookup returned null and the
    // code fell through to the legacy-settings path.
    if (!$qualbuilder) {
        $dbman_rctc = $DB->get_manager();
        if ($dbman_rctc->table_exists('local_rtocompliance_qualunit_courses')) {
            $qualbuilder = $DB->get_record_sql(
                "SELECT qb.*
                   FROM {local_rtocompliance_qualbuilder} qb
                   JOIN {local_rtocompliance_qualunits} qu ON qu.qualbuilderid = qb.id
                   JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu.id
                  WHERE quc.courseid = :courseid
                    AND qb.status = 'active'
                  ORDER BY qb.id ASC
                  LIMIT 1",
                ['courseid' => $courseid]
            );
        }
    }

    // No qualbuilder link — use course settings qual code, issue SoA
    if (!$qualbuilder) {
        $qualcode = trim($coursesettings->qualificationcode ?? '');
        $qualname = trim($coursesettings->qualificationname ?? '');
        if (!$qualcode) {
            return ['certtypes' => ['statement'], 'qualificationcode' => '', 'qualificationname' => $coursefullname, 'units' => [], 'reason' => 'nationally_recognised_no_qualbuilder'];
        }
        return ['certtypes' => ['statement'], 'qualificationcode' => $qualcode, 'qualificationname' => $qualname, 'units' => [], 'reason' => 'nationally_recognised_course_settings_only'];
    }

    $producttype = $qualbuilder->producttype ?? 'qualification';

    // Skill set or single unit → always SoA
    if ($producttype === 'skillset' || $producttype === 'singleunit') {
        $units = local_rtocompliance_get_qualbuilder_unit_list($qualbuilder->id);
        return ['certtypes' => ['statement'], 'qualificationcode' => $qualbuilder->qualificationcode, 'qualificationname' => $qualbuilder->qualificationname, 'units' => $units, 'reason' => 'skillset_or_singleunit'];
    }

    // Full qualification — check completion of all linked unit-courses
    if ($userid > 0) {
        $fullcomplete = local_rtocompliance_check_full_qual_completion($qualbuilder->id, $userid);
    } else {
        // Generic call (no specific user): default to showing full-qual path for display
        $fullcomplete = true;
    }

    $allunits = local_rtocompliance_get_qualbuilder_unit_list($qualbuilder->id);

    if ($fullcomplete) {
        return ['certtypes' => ['testamur', 'record'], 'qualificationcode' => $qualbuilder->qualificationcode, 'qualificationname' => $qualbuilder->qualificationname, 'units' => $allunits, 'reason' => 'full_qualification'];
    } else {
        // Partial — only include units whose linked course the student completed
        $completedunits = local_rtocompliance_get_completed_units_for_qual($qualbuilder->id, $userid);
        return ['certtypes' => ['statement'], 'qualificationcode' => $qualbuilder->qualificationcode, 'qualificationname' => $qualbuilder->qualificationname, 'units' => $completedunits, 'reason' => 'partial_qualification'];
    }
}

/**
 * Returns all SELECTED active units for a qualbuilder product as array of
 * ['code'=>, 'name'=>, 'outcome'=>'20'].
 * NOTE: outcome is hardcoded to '20' (Competent). Use
 * local_rtocompliance_get_qualbuilder_unit_list_with_outcomes() when you need
 * the student's actual AVETMISS outcome per unit (e.g. Record of Results cert).
 */
function local_rtocompliance_get_qualbuilder_unit_list(int $qualbuilderid): array {
    global $DB;
    // BUG-B-FIX (v5.9.221): added selected=1 so only required units are returned.
    // Previously missing, causing deselected/optional units to appear on certs.
    $rows = $DB->get_records('local_rtocompliance_qualunits',
        ['qualbuilderid' => $qualbuilderid, 'status' => 'active', 'selected' => 1],
        'sequenceorder ASC',
        'unitcode, unitname'
    );
    $units = [];
    foreach ($rows as $row) {
        $units[] = ['code' => $row->unitcode, 'name' => $row->unitname, 'outcome' => '20'];
    }
    return $units;
}

/**
 * Returns all SELECTED active units for a qualbuilder product with the student's
 * actual AVETMISS outcome code from their enrolment records.
 * Used for Record of Results cert issuance so the Results column reflects the
 * real outcome (Competent, RPL Granted, Credit Transfer, etc.) not a hardcoded '20'.
 * Falls back to '20' (Competent) when no enrolment record exists for a unit.
 *
 * BUG-C-FIX (v5.9.221): replaces the hardcoded '20' fallback for all units.
 *
 * @param int $qualbuilderid
 * @param int $studentid   local_rtocompliance_students.id (NOT the Moodle userid)
 * @return array  [['code'=>, 'name'=>, 'outcome'=>], ...]
 */
function local_rtocompliance_get_qualbuilder_unit_list_with_outcomes(int $qualbuilderid, int $studentid): array {
    global $DB;
    $rows = $DB->get_records('local_rtocompliance_qualunits',
        ['qualbuilderid' => $qualbuilderid, 'status' => 'active', 'selected' => 1],
        'sequenceorder ASC',
        'unitcode, unitname'
    );
    if (!$rows) {
        return [];
    }

    // Build a unitcode -> actual outcome map from the student's enrolment records.
    // ORDER BY id DESC gives us the most recent record per unit; first seen = most recent.
    $unitcodes = array_column(array_values($rows), 'unitcode');
    list($insql, $inparams) = $DB->get_in_or_equal($unitcodes, SQL_PARAMS_NAMED, 'uc');
    $enrolmentrows = $DB->get_records_sql(
        "SELECT unitcode, outcomeidentifier
           FROM {local_rtocompliance_enrolments}
          WHERE studentid = :studentid
            AND unitcode $insql
          ORDER BY id DESC",
        array_merge(['studentid' => $studentid], $inparams)
    );
    $outcomeMap = [];
    foreach ($enrolmentrows as $row) {
        // First-seen = highest id = most recent outcome for this unit.
        if (!isset($outcomeMap[$row->unitcode]) && !empty($row->outcomeidentifier)) {
            $outcomeMap[$row->unitcode] = $row->outcomeidentifier;
        }
    }

    $units = [];
    foreach ($rows as $row) {
        $units[] = [
            'code'    => $row->unitcode,
            'name'    => $row->unitname,
            'outcome' => $outcomeMap[$row->unitcode] ?? '20',
        ];
    }
    return $units;
}

/**
 * Check whether a student has completed ALL courses linked to units in a qualification.
 * Returns true only when every unit that has a linked courseid shows as complete.
 * Units without a linked course are ignored (not a blocker).
 */
function local_rtocompliance_check_full_qual_completion(int $qualbuilderid, int $userid): bool {
    global $DB;

    // QB-VARIANT-UNION-FIX (v5.9.293): For each unit, student must have completed
    // the primary course OR any variant course (qualunit_courses).  Previously this
    // function only checked qualunits.courseid — a student who completed via a variant
    // course always returned false here, causing them to receive a SoA instead of a
    // Testamur + Record of Results.
    //
    // RESULTS-COMPLETION-ENROLMENTS-FIX (v5.9.296): also check local_rtocompliance_enrolments
    // for a positive AVETMISS outcome (20/51/60/81) per unit as a fallback when Moodle course
    // completion is not recorded.  Previously if an RTO imported outcomes via AVETMISS import
    // or manual entry but Moodle course completion was not enabled on the course, this function
    // always returned false — the generate_course_certs.php path issued a SoA instead of
    // Testamur even when every unit had a final positive outcome in the enrolments table.
    $qbrec = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid], 'qualificationcode');
    $programcode = $qbrec ? (string)$qbrec->qualificationcode : null;

    $rows = $DB->get_records_sql(
        "SELECT qu.id, qu.courseid, qu.unitcode
         FROM {local_rtocompliance_qualunits} qu
         WHERE qu.qualbuilderid = :qbid
           AND qu.courseid IS NOT NULL
           AND qu.status = 'active'
           AND qu.selected = 1",
        ['qbid' => $qualbuilderid]
    );

    if (empty($rows)) {
        return false; // No linked courses — can't auto-determine full completion
    }

    $dbman = $DB->get_manager();
    $variantsExist = $dbman->table_exists('local_rtocompliance_qualunit_courses');

    foreach ($rows as $row) {
        // Collect primary + all variant courseids for this unit.
        $courseids = [(int)$row->courseid];
        if ($variantsExist) {
            $variantCids = $DB->get_fieldset_sql(
                "SELECT courseid FROM {local_rtocompliance_qualunit_courses}
                  WHERE qualunitid = :quid AND courseid IS NOT NULL",
                ['quid' => $row->id]
            );
            if ($variantCids) {
                $courseids = array_values(array_unique(
                    array_merge($courseids, array_map('intval', $variantCids))
                ));
            }
        }
        // Student must have completed at least ONE delivery course for this unit.
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cfqc');
        $inparams['cfqcuid'] = $userid;
        $done = $DB->record_exists_sql(
            "SELECT 1 FROM {course_completions}
              WHERE course $insql AND userid = :cfqcuid
                AND timecompleted IS NOT NULL AND timecompleted > 0",
            $inparams
        );
        // Fallback: Moodle course completion not recorded but a positive AVETMISS
        // outcome exists in the enrolments table (manual entry / AVETMISS import).
        if (!$done && $programcode && !empty($row->unitcode)) {
            $done = $DB->record_exists_sql(
                "SELECT 1
                   FROM {local_rtocompliance_enrolments} e
                   JOIN {local_rtocompliance_students} s ON s.id = e.studentid
                  WHERE s.userid           = :cfqc_euid
                    AND e.unitcode         = :cfqc_eunitcode
                    AND e.programcode      = :cfqc_eprogcode
                    AND e.outcomeidentifier IN ('20', '51', '60', '81')",
                [
                    'cfqc_euid'      => $userid,
                    'cfqc_eunitcode' => $row->unitcode,
                    'cfqc_eprogcode' => $programcode,
                ]
            );
        }
        if (!$done) {
            return false;
        }
    }
    return true;
}

/**
 * Get only the units whose linked course the student has completed.
 * Used to build a partial-qualification SoA unit list.
 */
function local_rtocompliance_get_completed_units_for_qual(int $qualbuilderid, int $userid): array {
    global $DB;

    // QB-VARIANT-UNION-FIX (v5.9.293): A unit is considered completed if the student
    // finished the primary course OR any variant course (qualunit_courses).  Previously
    // only qualunits.courseid was checked, so units completed via a variant course were
    // omitted from partial SoA cert unit lists.
    //
    // RESULTS-COMPLETION-ENROLMENTS-FIX (v5.9.296): three additional fixes:
    // (1) Added selected=1 filter — unselected units were appearing on partial SoA PDFs.
    // (2) Enrolments table fallback — same gap as check_full_qual_completion: if Moodle
    //     course completion is not recorded but a positive AVETMISS outcome exists in the
    //     enrolments table, the unit now correctly appears on the SoA.
    // (3) Actual outcome returned instead of hardcoded '20' — units completed via RPL (51),
    //     Credit Transfer (60), or Non-Assessed Satisfactory (81) were all being labelled
    //     "Competent" on the issued SoA document, which is incorrect for ASQA purposes.
    $qbrec = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid], 'qualificationcode');
    $programcode = $qbrec ? (string)$qbrec->qualificationcode : null;

    $rows = $DB->get_records_sql(
        "SELECT qu.id, qu.unitcode, qu.unitname, qu.courseid
         FROM {local_rtocompliance_qualunits} qu
         WHERE qu.qualbuilderid = :qbid
           AND qu.status = 'active'
           AND qu.selected = 1
         ORDER BY qu.sequenceorder ASC",
        ['qbid' => $qualbuilderid]
    );

    $dbman = $DB->get_manager();
    $variantsExist = $dbman->table_exists('local_rtocompliance_qualunit_courses');

    $units = [];
    foreach ($rows as $row) {
        if (!$row->courseid) {
            continue; // skip units with no linked course
        }
        // Collect primary + all variant courseids for this unit.
        $courseids = [(int)$row->courseid];
        if ($variantsExist) {
            $variantCids = $DB->get_fieldset_sql(
                "SELECT courseid FROM {local_rtocompliance_qualunit_courses}
                  WHERE qualunitid = :quid AND courseid IS NOT NULL",
                ['quid' => $row->id]
            );
            if ($variantCids) {
                $courseids = array_values(array_unique(
                    array_merge($courseids, array_map('intval', $variantCids))
                ));
            }
        }
        // Unit is complete if student finished ANY delivery course for it.
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gcuq');
        $inparams['gcuquid'] = $userid;
        $moodleDone = $DB->record_exists_sql(
            "SELECT 1 FROM {course_completions}
              WHERE course $insql AND userid = :gcuquid
                AND timecompleted IS NOT NULL AND timecompleted > 0",
            $inparams
        );

        // Resolve the actual AVETMISS outcome from the enrolments table so the SoA
        // shows the correct code (RPL, CT, Competent, etc.) rather than always '20'.
        $actualOutcome = null;
        if ($programcode && !empty($row->unitcode)) {
            $enrolRec = $DB->get_record_sql(
                "SELECT outcomeidentifier
                   FROM {local_rtocompliance_enrolments} e
                   JOIN {local_rtocompliance_students} s ON s.id = e.studentid
                  WHERE s.userid           = :gcuq_euid
                    AND e.unitcode         = :gcuq_eunitcode
                    AND e.programcode      = :gcuq_eprogcode
                    AND e.outcomeidentifier IN ('20', '51', '60', '81')
                  ORDER BY e.timemodified DESC",
                [
                    'gcuq_euid'      => $userid,
                    'gcuq_eunitcode' => $row->unitcode,
                    'gcuq_eprogcode' => $programcode,
                ]
            );
            if ($enrolRec) {
                $actualOutcome = $enrolRec->outcomeidentifier;
            }
        }

        if ($moodleDone || $actualOutcome !== null) {
            // Use the actual AVETMISS outcome when known; fall back to '20' only when
            // we know Moodle says the course is done but no enrolment record exists yet.
            $units[] = [
                'code'    => $row->unitcode,
                'name'    => $row->unitname,
                'outcome' => $actualOutcome ?? '20',
            ];
        }
    }
    return $units;
}

/**
 * Returns the EARLIEST Unix timestamp when a student first completed a course,
 * using {course_completion_crit_compl} as the authoritative source.
 *
 * Moodle writes one row per criterion per student when the criterion is FIRST
 * satisfied and never updates it on grade re-saves or re-enrollments, making it
 * immune to the {course_completions}.timecompleted drift caused by grade
 * recalculation.  Falls back to {course_completions}.timecompleted when no
 * crit_compl rows exist (e.g. completion tracking set to "manual", or site has
 * not enabled completion criteria for the course).
 *
 * @param int $userid
 * @param int $courseid
 * @return int  Unix timestamp of earliest completion, or 0 when none found.
 */
function local_rtocompliance_get_initial_timecompleted(int $userid, int $courseid): int {
    global $DB;
    $ts = (int) $DB->get_field_sql(
        "SELECT MIN(timecompleted)
           FROM {course_completion_crit_compl}
          WHERE userid = :uid
            AND course = :cid
            AND timecompleted IS NOT NULL
            AND timecompleted > 0",
        ['uid' => $userid, 'cid' => $courseid]
    );
    if ($ts > 0) {
        return $ts;
    }
    // Fallback: course_completions row (may drift on grade re-saves but is
    // the only source available when completion criteria are not configured).
    $cc = (int) $DB->get_field('course_completions', 'timecompleted',
        ['userid' => $userid, 'course' => $courseid]);
    return $cc > 0 ? $cc : 0;
}

function local_rtocompliance_programmatic_issue_cert(
    int    $userid,
    string $certtype,
    string $qualcode,
    string $qualname,
    array  $units,
    int    $issuedate,
    string $audience     = 'default',
    int    $sendemail    = 1,
    int    $timecompleted = 0
): array {
    global $DB, $USER;

    require_once(__DIR__ . '/classes/cert_template.php');
    require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

    // ── Generate cert number ─────────────────────────────────────────────────
    $prefix   = get_config('local_rtocompliance', 'certprefix') ?: 'CERT';
    $year     = date('Y');
    // FIX-LIKE-ESCAPE (v5.9.276): escape LIKE wildcards in prefix.
    $prefix_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    // CERTNUMBER-COLLISION-FIX (v5.9.301): COUNT(*)+1 has a race condition — two
    // parallel cert generations in the same second both read count=N, both produce
    // CERT-YYYY-000(N+1), and (without a DB UNIQUE constraint) both insert successfully,
    // creating duplicate cert numbers.
    // Fix: after computing the candidate number, loop-increment while a row with that
    // number already exists.  This is safe under any DB engine and handles both the
    // race window and gap-in-sequence (voided/deleted certs) edge cases.
    $sequence = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_certs} WHERE certnumber LIKE ?",
        [$prefix_escaped . '-' . $year . '-%']
    ) + 1;
    $certnumber = sprintf('%s-%s-%05d', $prefix, $year, $sequence);
    // Increment past any existing row (catches both normal gaps and collision window).
    $guard = 0;
    while ($DB->record_exists('local_rtocompliance_certs', ['certnumber' => $certnumber]) && $guard++ < 100) {
        $sequence++;
        $certnumber = sprintf('%s-%s-%05d', $prefix, $year, $sequence);
    }

    // ── Units JSON ───────────────────────────────────────────────────────────
    // BUG-A-FIX (v5.9.221): also store units JSON for 'record' (Record of Results)
    // certs. Previously only 'statement' certs stored units, so ALL programmatically
    // issued RoR certs had cert.units = NULL in the DB — the v5.9.220 3-column
    // template fix rendered blank columns because there was nothing to read.
    // 'testamur' intentionally excluded: the Testamur design does not list units.
    $unitsjson = null;
    if (in_array($certtype, ['statement', 'record'], true) && !empty($units)) {
        $unitsjson = json_encode($units);
    }

    // ── Resolve template ─────────────────────────────────────────────────────
    $certtmplid = null;
    if (!in_array($audience, \local_rtocompliance\cert_template::AUDIENCES, true)) {
        $audience = 'default';
    }
    try {
        $picked = \local_rtocompliance\cert_template::pick_for_audience($certtype, $audience);
        if (!$picked && $audience !== 'default') {
            $picked = \local_rtocompliance\cert_template::pick_for_audience($certtype, 'default');
        }
        if ($picked && !empty($picked->id)) {
            $certtmplid = (int) $picked->id;
        }
    } catch (\Throwable $e) {
        // Non-fatal — cert will use legacy renderer fallback
    }

    // ── Deduct credits (5 per cert) ──────────────────────────────────────────
    $platformclient = new \local_rtocompliance\usi\usi_platform_client();
    $creditresult   = $platformclient->consume_credits(
        5,
        'certificate',
        'local_rtocompliance',
        ['certtype' => $certtype, 'studentid' => $userid, 'certnumber' => $certnumber]
    );
    if (!$creditresult['ok']) {
        return ['ok' => false, 'certid' => null, 'certnumber' => $certnumber, 'error' => $creditresult['error'] ?? 'CREDIT_ERROR'];
    }

    // ── Insert cert record ───────────────────────────────────────────────────
    // C2-USI-SNAPSHOT-FIX (v5.9.305): snapshot the student's current USI at the
    // moment of issuance.  Previously certs had no usi column — every re-render
    // pulled the live usi from local_rtocompliance_students.  If the student later
    // corrected their USI (e.g. a typo was fixed), every historical cert silently
    // showed the new USI, breaking the forensic audit trail required under ASQA
    // regulatory practice guide (the cert must reflect what was verified at issue time).
    $issuedusi = (string)($DB->get_field('local_rtocompliance_students', 'usi', ['userid' => $userid]) ?? '');

    $cert = new stdClass();
    $cert->userid            = $userid;
    $cert->certnumber        = $certnumber;
    $cert->certtype          = $certtype;
    $cert->qualificationcode = $qualcode;
    $cert->qualificationname = $qualname;
    $cert->usi               = $issuedusi;
    $cert->units             = $unitsjson;
    $cert->issuedate         = $issuedate;
    $cert->expirydate        = null;
    $cert->verifytoken       = local_rtocompliance_generate_certificate_token();
    $cert->status            = 'issued';
    $cert->issuedby          = $USER->id ?? 0;
    $cert->notes             = 'Bulk-issued via Generate Course Certificates';
    $cert->emailsent         = 0;
    $cert->certtmplid        = $certtmplid;
    // INITIAL-COMPLETION-DATE-FIX (v5.9.232): store the EARLIEST completion
    // timestamp so cert.completiondate always shows the date the student first
    // completed the course/qualification, not the date of the latest grade
    // re-save. Callers pass the MIN from {course_completion_crit_compl} (immune
    // to grade re-saves). Stored as NULL when unknown to preserve legacy behaviour.
    $cert->timecompleted     = $timecompleted > 0 ? $timecompleted : null;
    $cert->timecreated       = time();
    $cert->timemodified      = time();
    // CERT-INSERT-CATCH-FIX (v5.9.301): an uncaught dml_write_exception from
    // insert_record() bubbled up through generate_qual_certs.php's bulk loop,
    // abandoning all remaining students with no error in the summary.
    // Wrap and return a structured error so the caller can tally the failure
    // and continue processing the next student.
    try {
        $cert->id = $DB->insert_record('local_rtocompliance_certs', $cert);
    } catch (\Throwable $e) {
        debugging('programmatic_issue_cert: DB insert failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        return ['ok' => false, 'certid' => null, 'certnumber' => $certnumber, 'error' => 'DB_INSERT_FAILED'];
    }

    // Publish to AI Grader central verification registry (best-effort).
    $certpublishuser = core_user::get_user($userid);
    if ($certpublishuser) {
        local_rtocompliance_publish_cert_to_registry($cert, $certpublishuser);
    }

    // ── Audit log ────────────────────────────────────────────────────────────
    $log              = new stdClass();
    $log->action      = 'bulk_issue';
    $log->component   = 'certs';
    $log->itemid      = $cert->id;
    $log->userid      = $USER->id ?? 0;
    $log->targetuserid = $userid;
    $log->details     = json_encode(['certnumber' => $certnumber, 'certtype' => $certtype, 'qualification' => $qualcode]);
    $log->ipaddress   = getremoteaddr();
    $log->timecreated = time();
    $DB->insert_record('local_rtocompliance_log', $log);

    // ── Moodle notification ──────────────────────────────────────────────────
    if ($sendemail) {
        try {
            $recipient = core_user::get_user($userid);
            if ($recipient) {
                $certtypes    = local_rtocompliance_get_certificate_types();
                $certtypename = $certtypes[$certtype] ?? $certtype;
                $qualstr      = $qualcode ? ($qualcode . ($qualname ? ' - ' . $qualname : '')) : '';
                $downloadurl  = new moodle_url('/local/rtocompliance/mycerts.php');
                $messagehtml  = get_string('certificate_notification_message', 'local_rtocompliance', [
                    'firstname'   => $recipient->firstname,
                    'certtype'    => $certtypename,
                    'certnumber'  => $certnumber,
                    'qualification' => $qualstr,
                    'downloadlink'  => $downloadurl->out(false),
                    'rtoname'       => get_config('local_rtocompliance', 'rtoname') ?: 'Training Organisation',
                ]);
                $eventdata                    = new \core\message\message();
                $eventdata->component         = 'local_rtocompliance';
                $eventdata->name              = 'certificate_issued';
                $eventdata->userfrom          = \core_user::get_noreply_user();
                $eventdata->userto            = $recipient;
                $eventdata->subject           = get_string('certificate_notification_subject', 'local_rtocompliance', $certtypename);
                $eventdata->fullmessage       = strip_tags($messagehtml);
                $eventdata->fullmessageformat = FORMAT_HTML;
                $eventdata->fullmessagehtml   = $messagehtml;
                $eventdata->smallmessage      = $eventdata->subject;
                $eventdata->notification      = 1;
                message_send($eventdata);
                $DB->set_field('local_rtocompliance_certs', 'emailsent',    1,      ['id' => $cert->id]);
                $DB->set_field('local_rtocompliance_certs', 'emailsentdate', time(), ['id' => $cert->id]);
            }
        } catch (\Throwable $e) {
            // Non-fatal — cert is issued, notification silently failed
        }
    }

    return ['ok' => true, 'certid' => $cert->id, 'certnumber' => $certnumber, 'error' => ''];
}

function local_rtocompliance_get_course_settings($courseid) {
    return \local_rtocompliance\cache_helper::get_course_settings($courseid);
}

function local_rtocompliance_user_requires_avetmiss($userid) {
    global $DB;

    // Note: Do NOT add LIMIT clause - record_exists_sql() adds its own LIMIT automatically
    $sql = "SELECT 1
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {local_rtocompliance_courses} lrc ON lrc.courseid = e.courseid
             WHERE ue.userid = :userid
               AND ue.status = 0
               AND lrc.nationallyrecognised = 1";

    return $DB->record_exists_sql($sql, ['userid' => $userid]);
}

function local_rtocompliance_get_user_nationally_recognised_courses($userid) {
    global $DB;

    $sql = "SELECT c.id, c.fullname, c.shortname, lrc.qualificationcode, lrc.qualificationname
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {course} c ON c.id = e.courseid
              JOIN {local_rtocompliance_courses} lrc ON lrc.courseid = c.id
             WHERE ue.userid = :userid
               AND ue.status = 0
               AND lrc.nationallyrecognised = 1
          ORDER BY c.fullname";

    return $DB->get_records_sql($sql, ['userid' => $userid]);
}

function local_rtocompliance_get_avetmiss_fields() {
    return \local_rtocompliance\avetmiss_fields::get_all();
}

/**
 * BUG-TAS-OVERLAP-2 (v4.2.21): renderer for the "quick-add" helper above each
 * consultation textarea.  Replaces the previous fixed-height multi-select listbox
 * with a flex-wrap checkbox grid that can never overflow its parent column,
 * eliminating the visual overlap that appeared on the EDIT view of an existing
 * consultation record.
 *
 * @param string $gridid     DOM id assigned to the checkbox grid container.
 * @param string $textareaid DOM id of the destination textarea (e.g. "id_feedback").
 * @param string $heading    Heading shown above the grid.
 * @param array  $options    Map of value => human label (the label is what gets
 *                           appended to the textarea, one bullet per ticked item).
 * @return string Safe HTML.
 */
function local_rtocompliance_render_quickadd_helper($gridid, $textareaid, $heading, array $options) {
    // BUG-TAS-OVERLAP-3 (v4.2.23): The previous flex-wrap implementation rendered
    // the checkboxes inside an mform "static" element wrapper.  On the EDIT view
    // that wrapper applies an effective height constraint via Moodle's
    // .fitem .felement layout — so checkbox rows past the first ~5 items
    // visually overflowed past the blue border and overlapped the next form
    // field's label.  Now wrapped in a native <details> element (collapsed by
    // default with item count in the summary) so the helper stays compact, is
    // explicitly self-sizing on expand, and can never overflow its container.
    // Also rendered via $mform->addElement('html', ...) at the call site
    // instead of addElement('static', ...) so no Moodle form-row wrapper is
    // applied around the helper at all.
    $optioncount = count($options);
    $detailsid   = $gridid . '-details';
    $html  = '<div class="rtoc-dropdown-helper" style="background:#f0f6ff;border:1px solid #b3d1ff;'
           . 'border-radius:6px;padding:10px 12px;margin:0 0 12px 0;clear:both;'
           . 'box-sizing:border-box;width:100%;max-width:100%;overflow:visible;display:block;">';
    $html .= '<details id="' . s($detailsid) . '" style="margin:0;padding:0;">';
    $html .= '<summary style="font-size:12px;font-weight:600;cursor:pointer;'
           . 'list-style:revert;outline:none;padding:2px 0;user-select:none;">'
           . s($heading) . ' <span style="font-weight:400;color:#666;">('
           . (int)$optioncount . ' suggestions — click to expand)</span></summary>';
    $html .= '<div style="margin:10px 0 0 0;">';
    $html .= '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">';
    $html .= '<button type="button" onclick="rtocAppendChecked(\'' . s($gridid) . '\',\''
           . s($textareaid) . '\')" class="btn btn-sm btn-primary" style="font-size:12px;">'
           . 'Add Selected &rarr;</button>';
    $html .= '<button type="button" onclick="document.getElementById(\'' . s($textareaid)
           . '\').value=\'\'" class="btn btn-sm btn-outline-secondary" style="font-size:12px;">'
           . 'Clear field</button>';
    $html .= '</div>';
    // Single-column block layout — every option is its own row, so the helper
    // grows predictably with the number of items and can never wrap into a
    // hidden second column that overlaps subsequent content.
    $html .= '<div id="' . s($gridid) . '" style="display:block;font-size:12px;line-height:1.5;">';
    foreach ($options as $val => $label) {
        $html .= '<label style="display:flex;align-items:center;gap:6px;font-weight:normal;'
               . 'margin:0 0 4px 0;padding:2px 0;cursor:pointer;width:100%;">'
               . '<input type="checkbox" value="' . s($label) . '" style="margin:0;flex:0 0 auto;">'
               . '<span style="flex:1 1 auto;">' . s($label) . '</span>'
               . '</label>';
    }
    $html .= '</div>';
    $html .= '<p style="font-size:11px;color:#666;margin:6px 0 0;">'
           . 'Tick the categories you want, then click <strong>Add Selected</strong>.</p>';
    $html .= '</div>'; // close inner padding wrapper
    $html .= '</details>';
    $html .= '</div>'; // close .rtoc-dropdown-helper
    return $html;
}

function local_rtocompliance_generate_consultation_narrative($tas, $consultations) {
    if (empty($consultations)) {
        return '';
    }

    $qualCode = $tas->qualificationcode;
    $qualName = $tas->qualificationname ?: $qualCode;

    $methodLabels = [
        'meeting' => 'an industry meeting',
        'email' => 'email consultation',
        'informal' => 'informal industry discussion',
        'trainer' => 'trainer-industry discussion',
        'review' => 'workplace documentation review',
        'advisory' => 'industry advisory committee participation',
        'site_visit' => 'a workplace site visit',
        'survey' => 'an industry survey',
        'other' => 'industry consultation',
    ];

    $lines = [];
    $lines[] = "Industry Consultation Evidence";
    $lines[] = "";
    $lines[] = "The following industry consultations have been conducted to inform the training and assessment strategy for {$qualCode} {$qualName}:";
    $lines[] = "";

    $feedbackThemes = [];
    $trainingImpacts = [];
    $assessmentImpacts = [];
    $nextMeetings = [];

    foreach ($consultations as $c) {
        $date = userdate($c->consultationdate, '%B %Y');
        $method = $methodLabels[$c->consultationtype] ?? ($c->consultationtype ?: 'industry consultation');
        $org = !empty($c->participantorg) ? $c->participantorg : 'an industry organisation';
        $role = !empty($c->participantrole) ? " ({$c->participantrole})" : '';

        $line = "- {$date}: {$c->participantname}{$role} from {$org} was consulted via {$method}.";
        if (!empty($c->feedback)) {
            $line .= " " . trim($c->feedback);
            $feedbackThemes[] = trim($c->feedback);
        }
        $lines[] = $line;

        if (!empty($c->impacttraining)) {
            $trainingImpacts[] = trim($c->impacttraining);
        }
        if (!empty($c->impactassessment)) {
            $assessmentImpacts[] = trim($c->impactassessment);
        }
        if (!empty($c->nextmeetingdate) && $c->nextmeetingdate > 0) {
            $nextMeetings[] = userdate($c->nextmeetingdate, '%d %B %Y') . ' with ' . $c->participantname . ' (' . $org . ')';
        }
    }

    if ($trainingImpacts) {
        $lines[] = "";
        $lines[] = "Impact on Training Delivery:";
        foreach ($trainingImpacts as $impact) {
            $lines[] = "- " . $impact;
        }
    }

    if ($assessmentImpacts) {
        $lines[] = "";
        $lines[] = "Impact on Assessment Design:";
        foreach ($assessmentImpacts as $impact) {
            $lines[] = "- " . $impact;
        }
    }

    if ($feedbackThemes) {
        $lines[] = "";
        $lines[] = "Key Feedback Themes:";
        $uniqueThemes = array_unique($feedbackThemes);
        foreach ($uniqueThemes as $theme) {
            $lines[] = "- " . $theme;
        }
    }

    $lines[] = "";
    $lines[] = "The feedback received has been incorporated into the training and assessment design to ensure the qualification reflects real workplace expectations.";

    $lines[] = "";
    $lines[] = "Ongoing Industry Engagement:";
    $lines[] = "Industry consultation will continue throughout the delivery of this qualification through periodic meetings with industry representatives, employer feedback from workplaces hosting learners, trainer industry engagement and networking, and review of industry trends and practices.";

    if ($nextMeetings) {
        $lines[] = "";
        $lines[] = "Scheduled upcoming consultations:";
        foreach ($nextMeetings as $meeting) {
            $lines[] = "- " . $meeting;
        }
    }

    return implode("\n", $lines);
}

/**
 * Log a compliance action to the local_rtocompliance_log table.
 *
 * @param string $action     Action performed (create/update/delete/view)
 * @param string $component  Component name (rpl/certificate/trainer/etc.)
 * @param int    $itemid     ID of the affected record (0 for new records)
 * @param array  $data       Additional details to store as JSON
 */
/**
 * AUDIT-REWRITE (v4.2.47): pre-enrolment suitability decision engine.
 *
 * Reads the structured evidence captured on a $suit record (the
 * local_rtocompliance_suitability row) and the admin-supplied context
 * (req_prereq, req_lln_level, lln_actual_level) and returns the system
 * outcome, the list of reasons that drove that outcome, and the list of
 * support / recommendation messages that should be shown to the student
 * and recorded in the audit PDF.  Pure function — no DB writes.
 *
 * Outcome values (mirror the status enum on local_rtocompliance_suitability):
 *   - 'suitable'              — appears to meet all requirements
 *   - 'suitable_with_support' — meets entry req, but LLN/digital flag added
 *   - 'not_suitable'          — entry-requirement (prereq qualification) gap
 *
 * @param  stdClass $suit  the suitability row with evidence fields populated
 * @return array{outcome:string,reasons:string[],support:string[]}
 */
function local_rtocompliance_calculate_suitability(stdClass $suit): array {
    $reasons = [];
    $support = [];
    $outcome = 'suitable';

    $qualLevels = [
        'school'     => 0, 'none' => 0,
        'cert1'      => 1, 'cert2' => 2,
        'cert3'      => 3, 'cert4' => 4,
        'diploma'    => 5, 'advdiploma' => 6,
        'bachelor'   => 7,
    ];
    $qualLabels = [
        'school'     => 'schooling only', 'none' => 'no prior qualification',
        'cert1'      => 'Certificate I',  'cert2' => 'Certificate II',
        'cert3'      => 'Certificate III', 'cert4' => 'Certificate IV',
        'diploma'    => 'Diploma',         'advdiploma' => 'Advanced Diploma',
        'bachelor'   => 'a Bachelor degree or higher',
    ];

    // 1. Entry-requirement (prerequisite qualification) check.
    $reqPrereq   = $suit->req_prereq ?? 'none';
    $studentQual = $suit->qualification ?? 'none';
    if ($reqPrereq !== 'none' && isset($qualLevels[$reqPrereq])) {
        $reqL = $qualLevels[$reqPrereq];
        $stuL = $qualLevels[$studentQual] ?? 0;
        if ($stuL < $reqL) {
            $outcome   = 'not_suitable';
            $reasons[] = 'Entry requirement not met: this course requires a minimum of ' .
                         ($qualLabels[$reqPrereq] ?? $reqPrereq) .
                         ' but you have indicated ' . ($qualLabels[$studentQual] ?? 'no prior qualification') . '.';
            $support[] = 'Consider enrolling in a lower-level qualification first, or contact us about Recognition of Prior Learning (RPL) if you have equivalent industry experience.';
        }
    }

    // 2. LLN check.
    $reqLLN = (float)($suit->req_lln_level ?? 3);
    $actualLLN = (isset($suit->lln_actual_level) && $suit->lln_actual_level !== '' && $suit->lln_actual_level !== null)
        ? (float)$suit->lln_actual_level
        : null;
    if ($actualLLN === null) {
        if ($outcome !== 'not_suitable') { $outcome = 'suitable_with_support'; }
        $reasons[] = 'Your Language, Literacy and Numeracy (LLN) level has not yet been assessed. This course requires ACSF Level ' . $reqLLN . '.';
        $support[] = 'You will be invited to complete a short LLN assessment before your enrolment is confirmed, so we can plan any support you may need.';
    } else if ($actualLLN < $reqLLN) {
        if ($outcome !== 'not_suitable') { $outcome = 'suitable_with_support'; }
        $reasons[] = 'Your assessed LLN level (ACSF ' . $actualLLN . ') is below the level required for this course (ACSF ' . $reqLLN . ').';
        $support[] = 'A Foundation Skills program or one-on-one LLN support will be arranged before you commence. Your trainer will discuss the options with you.';
    }

    // 3. Digital literacy check (5 skills total, threshold = 3).
    $digital = !empty($suit->digital_skills) ? json_decode($suit->digital_skills, true) : [];
    if (!is_array($digital)) { $digital = []; }
    $digitalCount = count($digital);
    if ($digitalCount < 3) {
        if ($outcome === 'suitable') { $outcome = 'suitable_with_support'; }
        $reasons[] = 'You indicated confidence with ' . $digitalCount . ' of 5 core digital skills, which is below the level expected for self-paced online learning.';
        $support[] = 'A short digital-literacy orientation will be provided before you begin (covering email, file uploads and online forms).';
    }

    // 4. Disability / reasonable-adjustment disclosure (informational —
    //    never makes a student unsuitable, but always adds a support note).
    if (!empty(trim($suit->disability_disclosure ?? ''))) {
        $support[] = 'You disclosed a learning need or disability. Your trainer will contact you to plan reasonable adjustments before enrolment, in line with the Disability Standards for Education 2005.';
    }

    return [
        'outcome' => $outcome,
        'reasons' => $reasons,
        'support' => $support,
    ];
}

/**
 * AUDIT-REWRITE (v4.2.47): builds the plain-language advice block shown
 * to the student on the confirmation screen and saved on the suitability
 * record (local_rtocompliance_suitability.advice) for inclusion in the
 * audit PDF.  Wording is tailored per outcome and reuses the reasons +
 * support arrays returned by local_rtocompliance_calculate_suitability().
 *
 * @param  string   $outcome  one of suitable / suitable_with_support /
 *                            not_suitable / override_*
 * @param  string[] $reasons  reasons array from the decision engine
 * @param  string[] $support  support array from the decision engine
 * @param  stdClass $tas      TAS row (qualificationcode + qualificationname)
 * @param  string   $rtoname  display name of the RTO
 * @return string             plain-text advice (newlines preserved for PDF)
 */
function local_rtocompliance_format_suitability_advice(string $outcome, array $reasons, array $support, stdClass $tas, string $rtoname): string {
    $course = $tas->qualificationcode . ' ' . $tas->qualificationname;

    switch ($outcome) {
        case 'suitable':
        case 'override_suitable':
            $msg  = "Based on the information you have provided, you appear to be suitable to enrol in {$course}.\n\n";
            $msg .= "Next steps:\n";
            $msg .= "  • {$rtoname} will be in touch shortly to finalise your enrolment.\n";
            $msg .= "  • You will receive separate emails for USI verification and pre-enrolment information.\n";
            $msg .= "  • If you have not already completed an LLN assessment, this may still be requested as part of routine enrolment.\n";
            break;

        case 'suitable_with_support':
        case 'override_suitable_with_support':
            $msg  = "Based on the information you have provided, you appear suitable to enrol in {$course} with some additional support.\n\n";
            if (!empty($reasons)) {
                $msg .= "Why we have flagged this:\n";
                foreach ($reasons as $r) { $msg .= "  • " . $r . "\n"; }
                $msg .= "\n";
            }
            if (!empty($support)) {
                $msg .= "Support that will be arranged:\n";
                foreach ($support as $s) { $msg .= "  • " . $s . "\n"; }
                $msg .= "\n";
            }
            $msg .= "Next steps:\n";
            $msg .= "  • A trainer from {$rtoname} will contact you to discuss the support options above.\n";
            $msg .= "  • Your enrolment can proceed once you and the trainer agree on a support plan.\n";
            break;

        case 'not_suitable':
        case 'override_not_suitable':
            $msg  = "Based on the information you have provided, you do not currently meet all of the entry requirements for {$course}.\n\n";
            if (!empty($reasons)) {
                $msg .= "Why:\n";
                foreach ($reasons as $r) { $msg .= "  • " . $r . "\n"; }
                $msg .= "\n";
            }
            $msg .= "What you can do:\n";
            foreach ($support as $s) { $msg .= "  • " . $s . "\n"; }
            $msg .= "  • A trainer from {$rtoname} will contact you to discuss alternative pathways — including lower-level qualifications, RPL, or building the prerequisite skills first.\n";
            $msg .= "  • You are welcome to reapply once any prerequisite has been completed.\n";
            break;

        default:
            $msg = "Your suitability is being reviewed by {$rtoname}. We will be in touch shortly.";
    }

    return $msg;
}

function local_rtocompliance_log_action(string $action, string $component, int $itemid = 0, array $data = []): void {
    global $DB, $USER;
    try {
        $record = new stdClass();
        $record->action      = substr($action, 0, 50);
        $record->component   = substr($component, 0, 50);
        $record->itemid      = $itemid;
        $record->userid      = $USER->id ?? 0;
        $record->targetuserid = $data['targetuserid'] ?? null;
        $record->details     = !empty($data) ? json_encode($data) : null;
        // Bug N fix: use Moodle's getremoteaddr() instead of $_SERVER['REMOTE_ADDR']
        // directly — prevents IP spoofing via X-Forwarded-For / X-Real-IP headers on
        // unconfigured reverse proxies, consistent with audit_logger.php.
        $record->ipaddress   = getremoteaddr('0.0.0.0') ?: null;
        $record->timecreated = time();
        $DB->insert_record('local_rtocompliance_log', $record);
    } catch (Exception $e) {
        // Fail silently — logging must never break the primary workflow.
        error_log('local_rtocompliance_log_action error: ' . $e->getMessage());
    }
}

/**
 * Fix 9: Validate USI format — 10 alphanumeric uppercase characters.
 * Format: [A-Z0-9]{10} as specified by the USI Regulator.
 * Use this before sending a USI to the registry API to avoid unnecessary
 * API calls and to surface format errors to the user immediately.
 *
 * @param string $usi The USI value to validate.
 * @return bool True if format is valid, false otherwise.
 */
function local_rtocompliance_validate_usi_format($usi) {
    if (empty($usi) || !is_string($usi)) {
        return false;
    }
    return (bool) preg_match('/^[A-Z0-9]{10}$/', strtoupper(trim($usi)));
}

/**
 * Parse TAS entryrequirements text into an array of Yes/No question strings.
 * Each non-empty line (stripped of leading bullets/numbers) becomes one question.
 * Shared by suitability_send.php and suitability_bulk.php.
 */
function local_rtocompliance_parse_suitability_questions(string $text): array {
    $lines = preg_split('/\r?\n/', $text);
    $questions = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $line = preg_replace('/^[\-\*\+•·◦▪▸►]\s+/', '', $line);
        $line = preg_replace('/^\d+[\.\)\:]\s*/', '', $line);
        $line = trim($line);
        if (mb_strlen($line) < 5) continue;
        $questions[] = $line;
    }
    return $questions;
}

/**
 * Email the suitability checklist link to the student.
 * Shared by suitability_send.php and suitability_bulk.php.
 */
function local_rtocompliance_send_suitability_email(object $user, object $tas, string $token): void {
    global $CFG;
    // FIX-RTO-TESTER-FEEDBACK-MAY1 #8 (v4.2.42): explicit recipient validation.
    // Tester reported "User 1 (Guest user) email (root@localhost) is invalid"
    // — that happens when an empty/zero $userid falls through to core_user::get_user
    // which returns the guest record (id=1).  Surface a clear error here instead
    // of silently sending to root@localhost.
    if (empty($user->id) || (int)$user->id <= 1) {
        throw new \moodle_exception('error', 'local_rtocompliance', '',
            'Cannot send suitability checklist: no valid student selected (got user id ' . (int)($user->id ?? 0) . ').');
    }
    if (!empty($user->deleted)) {
        throw new \moodle_exception('error', 'local_rtocompliance', '',
            'Cannot send suitability checklist to ' . fullname($user) . ': account is deleted.');
    }
    if (empty($user->email) || strpos($user->email, '@') === false) {
        throw new \moodle_exception('error', 'local_rtocompliance', '',
            'Cannot send suitability checklist to ' . fullname($user) . ': missing or invalid email address.');
    }
    $formurl = $CFG->wwwroot . '/local/rtocompliance/suitability_form.php?token=' . $token;
    $rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';
    $subject = 'Student Suitability Check – ' . $tas->qualificationname;
    $body  = 'Dear ' . $user->firstname . ",\n\n";
    $body .= 'Before you can be enrolled in ' . $tas->qualificationcode . ' ' . $tas->qualificationname . ', ';
    $body .= 'we need to confirm that you meet the entry requirements for this qualification.' . "\n\n";
    $body .= 'Please click the link below to complete your Student Suitability Check. ';
    $body .= 'It takes less than 5 minutes and covers your background, LLN, and a short declaration.' . "\n\n";
    $body .= 'Your Student Suitability Check: ' . $formurl . "\n\n";
    $body .= 'If you have any questions, please contact us directly.' . "\n\n";
    $body .= 'Regards,' . "\n" . $rtoname;

    $bodyhtml  = '<p>Dear ' . htmlspecialchars($user->firstname) . ',</p>';
    $bodyhtml .= '<p>Before you can be enrolled in <strong>' . htmlspecialchars($tas->qualificationcode . ' ' . $tas->qualificationname) . '</strong>, ';
    $bodyhtml .= 'we need to confirm that you meet the entry requirements for this qualification.</p>';
    $bodyhtml .= '<p>Please click the button below to complete your Student Suitability Check. ';
    $bodyhtml .= 'It takes less than 5 minutes and covers your background, LLN, and a short declaration.</p>';
    $bodyhtml .= '<p style="margin:24px 0"><a href="' . $formurl . '" style="background:#0069d9;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:bold;display:inline-block">Complete Student Suitability Check</a></p>';
    $bodyhtml .= '<p>Or copy this link: <a href="' . $formurl . '">' . $formurl . '</a></p>';
    $bodyhtml .= '<p>If you have any questions, please contact us directly.</p>';
    $bodyhtml .= '<p>Regards,<br>' . htmlspecialchars($rtoname) . '</p>';

    $noreply = core_user::get_noreply_user();
    email_to_user($user, $noreply, $subject, $body, $bodyhtml);
}

/**
 * Auto-send a suitability checklist when a student is enrolled (called by observer).
 * Skips silently if the student already has any suitability record for this TAS,
 * or if the TAS has no entry requirements, or if the user has no valid email.
 */
function local_rtocompliance_auto_send_suitability(int $userid, int $tasid): void {
    global $DB;
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_rtocompliance_suitability')) {
        return;
    }
    if ($DB->record_exists('local_rtocompliance_suitability', ['userid' => $userid, 'tasid' => $tasid])) {
        return;
    }
    $user = core_user::get_user($userid);
    if (!$user || !validate_email($user->email)) {
        return;
    }
    $tas = $DB->get_record('local_rtocompliance_tas', ['id' => $tasid]);
    if (!$tas || empty($tas->entryrequirements)) {
        return;
    }
    $questions = local_rtocompliance_parse_suitability_questions($tas->entryrequirements);
    if (empty($questions)) {
        return;
    }
    $token = bin2hex(random_bytes(32));
    $suitabilityid = $DB->insert_record('local_rtocompliance_suitability', (object)[
        'tasid'        => $tasid,
        'userid'       => $userid,
        'token'        => $token,
        'status'       => 'pending',
        'timesent'     => time(),
        'timecreated'  => time(),
        'timemodified' => time(),
    ]);
    foreach ($questions as $i => $q) {
        $DB->insert_record('local_rtocompliance_suitability_answers', (object)[
            'suitabilityid' => $suitabilityid,
            'question'      => $q,
            'answer'        => null,
            'displayorder'  => $i,
        ]);
    }
    local_rtocompliance_send_suitability_email($user, $tas, $token);
    local_rtocompliance_log_action('suitability_auto_sent', 'suitability', $suitabilityid,
        ['userid' => $userid, 'tasid' => $tasid]);
}

/**
 * Render the collapsible left-hand sidebar navigation.
 * Called from before_footer_html_generation hook — injected as position:fixed HTML.
 *
 * @return string Full sidebar HTML + init script.
 */
function local_rtocompliance_render_sidebar(): string {
    global $PAGE, $CFG;

    $currentpath = (!empty($PAGE->url)) ? $PAGE->url->get_path() : '';

    // ── Inline SVG helper ──────────────────────────────────────────────────
    $icons = [
        'home'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'users'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'award'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        'graduation' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
        'upload'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        'download'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'user-check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>',
        'eye'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
        'bar-chart'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
        'message'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'alert'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'check-sq'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg>',
        'book'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'shield'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>',
        'building'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M12 10h.01M8 10h.01M16 10h.01M12 14h.01M8 14h.01M16 14h.01"/></svg>',
        'building-2' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>',
        'link'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        'scale'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>',
        'trending'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        'dollar'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'umbrella'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 12a11.05 11.05 0 0 0-22 0zm-5 7a3 3 0 0 1-6 0v-7"/></svg>',
        'refresh'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
        'settings'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'help'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
        'flask'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6l1 10H8z"/><path d="M8 13c0 4 8 4 8 0"/><path d="M6 3h12"/></svg>',
        'rpl'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"/><polyline points="22 2 22 8 16 8"/><path d="M22 2 12 12"/></svg>',
        'chevron-l'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',
        'chevron-r'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',
        'menu'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="18" x2="20" y2="18"/></svg>',
        'layout'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        'play-sq'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
    ];

    $icon = function(string $name) use ($icons): string {
        $svg = $icons[$name] ?? $icons['help'];
        return '<span class="rtoc-sb-icon">' . $svg . '</span>';
    };

    // ── Nav structure: [ label, [ [path, label, icon], ... ] ] ──────────────
    // Reorganised v3.8.65 to mirror ASQA 2025 Quality Areas per tester feedback.
    $groups = [
        [
            'label' => '',
            'items' => [
                ['/local/rtocompliance/index.php', 'Dashboard', 'home'],
            ],
        ],
        [
            // QA1 – Training and Assessment (Part 1, Divisions 1-4)
            'label' => 'QA1 – Training & Assessment',
            'items' => [
                ['/local/rtocompliance/tas.php',         'Training (TAS)',               'book'],
                ['/local/rtocompliance/validation.php',  'Validation Register',          'check-sq'],
                ['/local/rtocompliance/rpl.php',         'RPL & Credit Transfer',        'rpl'],
                ['/local/rtocompliance/locations.php',   'Delivery Locations',           'building-2'],
                ['/local/rtocompliance/transitions.php', 'Training Transitions',         'refresh'],
            ],
        ],
        [
            // QA2 – VET Student Support (Part 2, Divisions 1-5)
            'label' => 'QA2 – Student Support',
            'items' => [
                ['/local/rtocompliance/marketing_info.php', 'Marketing Information',         'info'],
                ['/local/rtocompliance/student_support.php', 'Student Support',              'users'],
                ['/local/rtocompliance/students.php',    'Student Records',                  'users'],
                ['/local/rtocompliance/certificates.php',           'Certificates',              'award'],
                ['/local/rtocompliance/generate_course_certs.php', 'Generate by Course',     'award'],
                ['/local/rtocompliance/soa_issue.php',             'Issue Multi-Unit SOA',   'file-check'],
                ['/local/rtocompliance/cert_templates.php',        'Certificate Templates',  'layout'],
                ['/local/rtocompliance/cert_test.php',             'Test Certificate',       'play-sq'],
                ['/local/rtocompliance/surveys.php',     'Surveys & Quality Indicators',     'message'],
                ['/local/rtocompliance/complaints.php',  'Feedback, Complaints & Appeals',   'alert'],
            ],
        ],
        [
            // QA3 – VET Workforce (Part 3, Divisions 1-2)
            'label' => 'QA3 – VET Workforce',
            'items' => [
                ['/local/rtocompliance/workforce_management.php', 'VET Workforce Management', 'users'],
                ['/local/rtocompliance/trainers.php',    'Trainer & Assessor Competencies', 'user-check'],
                ['/local/rtocompliance/supervision.php', 'Supervision Log',              'eye'],
            ],
        ],
        [
            // QA4 – Governance (Part 4, Divisions 1-3)
            'label' => 'QA4 – Governance',
            'items' => [
                ['/local/rtocompliance/governance.php',  'Leadership & Accountability',  'building'],
                ['/local/rtocompliance/risk.php',        'Risk Management',              'shield'],
            ],
        ],
        [
            // Practice Guides – Compliance Standards
            'label' => 'Compliance Standards',
            'items' => [
                ['/local/rtocompliance/thirdparty.php',   'Third-Party Arrangements',   'link'],
                ['/local/rtocompliance/feeprotection.php','Fee Protection',              'dollar'],
                ['/local/rtocompliance/insurance.php',    'Insurance Register',          'umbrella'],
            ],
        ],
        [
            // Data Provision & Reporting
            'label' => 'Data & Reports',
            'items' => [
                ['/local/rtocompliance/ai_usage_report.php',     'AI Credit Usage Report','trending'],
                ['/local/rtocompliance/natexport.php',           'AVETMISS Export',       'download'],
                ['/local/rtocompliance/qualbuilder.php',         'Qualification Builder', 'graduation'],
                ['/local/rtocompliance/qualbuilder_results.php', 'Student Results',       'bar-chart'],
                ['/local/rtocompliance/data_import.php',         'Data Import',           'upload'],
                ['/local/rtocompliance/reconcile.php',           'NAT Reconciliation Tool','search'],
            ],
        ],
        [
            'label' => 'Settings & Support',
            'items' => [
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_settings', 'Plugin Settings',       'settings'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_certs',    'Certificate Settings',  'award'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_api',      'Platform API Settings', 'link'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_statefunding', 'State Funding',  'dollar'],
                ['/local/rtocompliance/support.php',                         'Support Centre',        'help'],
                ['/local/rtocompliance/practice_guides.php',                 'Practice Guides',       'scale'],
                ['/local/rtocompliance/testing.php',                         'Testing Engine',        'flask'],
            ],
        ],
    ];

    // ── Build HTML ─────────────────────────────────────────────────────────
    $html  = '<div id="rtoc-sidebar-overlay"></div>';
    $html .= '<button id="rtoc-mobile-btn" aria-label="Open navigation">' . $icons['menu'] . '</button>';
    $html .= '<nav id="rtoc-sidebar" role="navigation" aria-label="RTO Compliance">';

    // Header
    $dashurl = (new moodle_url('/local/rtocompliance/index.php'))->out();
    $html .= '<div class="rtoc-sb-header">';
    $html .= '<a href="' . $dashurl . '" class="rtoc-sb-brand">';
    $html .= '<div class="rtoc-sb-brand-icon">RTO</div>';
    $html .= '<div class="rtoc-sb-brand-text">';
    $html .= '<span class="rtoc-sb-brand-title">RTO Compliance</span>';
    $html .= '<span class="rtoc-sb-brand-sub">ASQA 2025</span>';
    $html .= '</div>';
    $html .= '</a>';
    $html .= '<button class="rtoc-sb-toggle" id="rtoc-sb-toggle-btn" aria-label="Collapse sidebar" title="Collapse sidebar">';
    $html .= $icons['chevron-l'];
    $html .= '</button>';
    $html .= '</div>';

    // Nav
    $html .= '<div class="rtoc-sb-nav">';

    $first = true;
    foreach ($groups as $group) {
        if (!$first) {
            $html .= '<div class="rtoc-sb-divider"></div>';
        }
        $first = false;

        if (!empty($group['label'])) {
            $html .= '<div class="rtoc-sb-group-label">' . htmlspecialchars($group['label']) . '</div>';
        }

        foreach ($group['items'] as [$path, $label, $iconname]) {
            $active = (strpos($currentpath, $path) !== false) ? ' rtoc-sb-active' : '';
            $url    = (new moodle_url($path))->out();
            $html .= '<a href="' . $url . '" class="rtoc-sb-item' . $active . '" data-label="' . htmlspecialchars($label) . '">';
            $html .= $icon($iconname);
            $html .= '<span class="rtoc-sb-label">' . htmlspecialchars($label) . '</span>';
            $html .= '</a>';
        }
    }

    $html .= '</div>'; // .rtoc-sb-nav

    // Footer: Credit balance widget + Settings link
    $settingsurl  = (new moodle_url('/local/rtocompliance/plugin_settings.php', ['section' => 'local_rtocompliance_settings']))->out();
    $purchaseurl  = 'https://lms-labs.com/pricing';

    // Credit icon SVG (lightning bolt)
    $creditsvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';

    $html .= '<div class="rtoc-sb-footer">';

    // Credits widget — clickable link to purchase page, hidden when collapsed
    $html .= '<a href="' . htmlspecialchars($purchaseurl) . '" class="rtoc-sb-credits" id="rtoc-sb-credits-widget"'
           . ' title="Platform credits — click to purchase more" target="_blank" rel="noopener">';
    $html .= '<div class="rtoc-sb-credits-icon">' . $creditsvg . '</div>';
    $html .= '<div class="rtoc-sb-credits-text">';
    $html .= '<span class="rtoc-sb-credits-val" id="rtoc-sb-credits-val">—</span>';
    $html .= '<span class="rtoc-sb-credits-lbl">Platform credits</span>';
    $html .= '</div>';
    $html .= '</a>';

    $html .= '</div>'; // .rtoc-sb-footer

    // Hidden config div for JS credit fetch (only if not already injected by edit pages)
    // Resolve API key via same priority chain as external.php.
    $_rtoc_sb_cfglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($_rtoc_sb_cfglib) && !function_exists('local_aiconfig_get_apikey')) {
        require_once($_rtoc_sb_cfglib);
    }
    $_rtoc_sb_apikey  = function_exists('local_aiconfig_get_apikey')
        ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
        : (get_config('local_rtocompliance', 'apikey') ?: '');
    $_rtoc_sb_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
    $_rtoc_sb_siteid  = function_exists('local_aiconfig_get_siteid')
        ? (local_aiconfig_get_siteid('local_rtocompliance') ?: get_config('local_rtocompliance', 'siteid') ?: '')
        : (get_config('local_rtocompliance', 'siteid') ?: '');

    // Only inject if no existing rtoc-ai-config div (edit pages already do this)
    $html .= '<div id="rtoc-sb-api-config" style="display:none"'
           . ' data-apikey="' . htmlspecialchars($_rtoc_sb_apikey, ENT_QUOTES) . '"'
           . ' data-apiurl="' . htmlspecialchars($_rtoc_sb_apibase, ENT_QUOTES) . '"'
           . ' data-siteid="' . htmlspecialchars($_rtoc_sb_siteid, ENT_QUOTES) . '"'
           . '></div>';

    $html .= '</nav>'; // #rtoc-sidebar

    // ── Inline styles v9: world-class sidebar ─────────────────────────────
    $html .= <<<'CSSEND'
<style>
/* ════════════════════════════════════════════════════════════════════════
   RTOC Sidebar v9 — World-class navigation
   Two-column PHP flex layout. No JS positioning. No visibility hacks.
   ════════════════════════════════════════════════════════════════════════ */

/* Layout wrapper */
.rtoc-layout-wrap {
    display: flex !important;
    align-items: flex-start !important;
    width: 100% !important;
    min-height: 100vh;
}
.rtoc-main-content {
    flex: 1 1 0% !important;
    min-width: 0 !important;
    /* Belt-and-suspenders: padding also defined in styles.css with higher
       specificity. This inline rule ensures the padding is present even if
       styles.css is stale-cached by Moodle. Values must stay in sync with
       [class*="path-local-rtocompliance"] .rtoc-main-content in styles.css. */
    padding: 0 24px 40px 32px !important;
    /* overflow-x:hidden would implicitly set overflow-y:auto, creating an
       internal scroll container that breaks position:fixed/sticky scoping
       and can cause click-blocking. Use clip instead — it clips without
       creating a scroll container (no BFC side-effects). */
    overflow-x: clip;
}

/* ── Sidebar shell ──────────────────────────────────────────────────── */
#rtoc-sidebar {
    --sb-bg:          #0d1424;
    --sb-header-bg:   #0a0f1c;
    --sb-accent:      #3b82f6;
    --sb-accent-glow: rgba(59,130,246,0.18);
    --sb-text:        #94a3b8;
    --sb-text-bright: #e2e8f0;
    --sb-text-active: #ffffff;
    --sb-border:      rgba(255,255,255,0.05);
    --sb-hover-bg:    rgba(148,163,184,0.07);
    --sb-w: 258px;
    --sb-cw: 62px;
    --sb-ease: cubic-bezier(0.4, 0, 0.2, 1);

    flex: 0 0 var(--sb-w);
    width: var(--sb-w);
    min-width: var(--sb-w);
    background: var(--sb-bg);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    height: 100vh;
    position: sticky;
    top: 0;
    border-right: 1px solid var(--sb-border);
    box-shadow: 4px 0 24px rgba(0,0,0,0.35);
    transition:
        width        0.24s var(--sb-ease),
        min-width    0.24s var(--sb-ease),
        flex-basis   0.24s var(--sb-ease);
    font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    z-index: 100;
    flex-shrink: 0;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.06) transparent;
}
#rtoc-sidebar::-webkit-scrollbar { width: 3px; }
#rtoc-sidebar::-webkit-scrollbar-track { background: transparent; }
#rtoc-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 99px; }

#rtoc-sidebar.rtoc-sb-is-collapsed {
    flex-basis: var(--sb-cw) !important;
    width: var(--sb-cw) !important;
    min-width: var(--sb-cw) !important;
}

/* ── Header ─────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 10px 0 14px;
    height: 60px;
    min-height: 60px;
    background: var(--sb-header-bg);
    border-bottom: 1px solid var(--sb-border);
    flex-shrink: 0;
    position: relative;
}
/* Subtle top accent line on the header */
#rtoc-sidebar .rtoc-sb-header::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--sb-accent), #818cf8, transparent);
    opacity: 0.7;
}

/* ── Brand ──────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-brand {
    display: flex;
    align-items: center;
    gap: 11px;
    text-decoration: none !important;
    overflow: hidden;
    flex: 1;
}
.rtoc-sb-brand-icon {
    width: 36px !important;
    height: 36px !important;
    min-width: 36px !important;
    min-height: 36px !important;
    border-radius: 8px !important;
    background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-weight: 700 !important;
    font-size: 11px !important;
    color: #fff !important;
    flex-shrink: 0 !important;
    letter-spacing: 0.5px !important;
    box-shadow: 0 2px 8px rgba(14,165,233,0.35) !important;
    padding: 0 !important;
}
#rtoc-sidebar .rtoc-sb-brand-text {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: opacity 0.2s var(--sb-ease), width 0.24s var(--sb-ease);
}
#rtoc-sidebar .rtoc-sb-brand-title {
    font-weight: 700;
    font-size: 13.5px;
    color: var(--sb-text-bright);
    white-space: nowrap;
    letter-spacing: -0.01em;
}
#rtoc-sidebar .rtoc-sb-brand-sub {
    font-size: 10px;
    color: #475569;
    white-space: nowrap;
    margin-top: 1px;
    font-weight: 500;
    letter-spacing: 0.04em;
}
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-brand-text {
    opacity: 0;
    width: 0;
    pointer-events: none;
}

/* ── Collapse toggle ─────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-toggle {
    background: none;
    border: 1px solid rgba(255,255,255,0.06);
    cursor: pointer;
    color: #475569;
    border-radius: 6px;
    padding: 4px 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: color 0.18s, background 0.18s, border-color 0.18s;
    line-height: 1;
}
#rtoc-sidebar .rtoc-sb-toggle:hover {
    color: var(--sb-text-bright);
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.12);
}
#rtoc-sidebar .rtoc-sb-toggle svg { width: 16px; height: 16px; }

/* ── Nav scroll area ─────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-nav {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 10px 8px 6px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.06) transparent;
}
#rtoc-sidebar .rtoc-sb-nav::-webkit-scrollbar { width: 3px; }
#rtoc-sidebar .rtoc-sb-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.08); border-radius: 99px; }

/* ── Group labels ────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-group-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: #334155;
    text-transform: uppercase;
    padding: 12px 10px 5px;
    white-space: nowrap;
    overflow: hidden;
    transition: opacity 0.2s var(--sb-ease);
    display: flex;
    align-items: center;
    gap: 7px;
}
#rtoc-sidebar .rtoc-sb-group-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,0.04);
    display: block;
    min-width: 0;
}
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-group-label { opacity: 0; height: 0; padding: 0; }

/* ── Nav items ───────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-item {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 8px 10px;
    margin-bottom: 1px;
    border-radius: 8px;
    color: var(--sb-text) !important;
    text-decoration: none !important;
    white-space: nowrap;
    overflow: hidden;
    position: relative;
    transition:
        background  0.18s var(--sb-ease),
        color       0.18s var(--sb-ease),
        transform   0.18s var(--sb-ease);
}
#rtoc-sidebar .rtoc-sb-item:hover {
    background: var(--sb-hover-bg);
    color: var(--sb-text-bright) !important;
    transform: translateX(2px);
}

/* Active item — filled accent pill */
#rtoc-sidebar .rtoc-sb-item.rtoc-sb-active {
    background: var(--sb-accent-glow);
    color: var(--sb-text-active) !important;
    transform: none;
}
#rtoc-sidebar .rtoc-sb-item.rtoc-sb-active::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: var(--sb-accent);
    box-shadow: 0 0 8px rgba(59,130,246,0.5);
}

/* ── Icons ───────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    min-width: 22px;
    height: 22px;
    border-radius: 6px;
    color: #475569;
    flex-shrink: 0;
    transition: color 0.18s, background 0.18s;
}
#rtoc-sidebar .rtoc-sb-icon svg { width: 15px; height: 15px; }

#rtoc-sidebar .rtoc-sb-item:hover .rtoc-sb-icon {
    color: var(--sb-text-bright);
    background: rgba(255,255,255,0.05);
}
#rtoc-sidebar .rtoc-sb-item.rtoc-sb-active .rtoc-sb-icon {
    color: #93c5fd;
    background: rgba(59,130,246,0.15);
}

/* ── Labels ──────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-label {
    font-size: 13px;
    font-weight: 450;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: opacity 0.2s var(--sb-ease);
    letter-spacing: -0.005em;
}
#rtoc-sidebar .rtoc-sb-item.rtoc-sb-active .rtoc-sb-label { font-weight: 600; }
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-label { opacity: 0; }

/* ── Dividers ────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-divider {
    height: 1px;
    background: var(--sb-border);
    margin: 4px 0;
}

/* ── Footer ──────────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-footer {
    flex-shrink: 0;
    padding: 6px 8px 8px;
    border-top: 1px solid var(--sb-border);
    background: var(--sb-header-bg);
}
#rtoc-sidebar .rtoc-sb-credits {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    margin-bottom: 4px;
    border-radius: 8px;
    background: rgba(59,130,246,0.06);
    border: 1px solid rgba(59,130,246,0.12);
    overflow: hidden;
    text-decoration: none !important;
    cursor: pointer;
    transition: opacity 0.22s, height 0.24s, padding 0.24s, margin 0.24s, border 0.24s, background 0.18s;
}
#rtoc-sidebar .rtoc-sb-credits:hover {
    background: rgba(59,130,246,0.12);
    border-color: rgba(59,130,246,0.22);
}
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-credits {
    opacity: 0;
    height: 0;
    padding-top: 0;
    padding-bottom: 0;
    margin-bottom: 0;
    border-width: 0;
    pointer-events: none;
}
#rtoc-sidebar .rtoc-sb-credits-icon {
    width: 22px; min-width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(59,130,246,0.15); border-radius: 6px; flex-shrink: 0;
    color: #60a5fa;
}
#rtoc-sidebar .rtoc-sb-credits-icon svg { width: 13px; height: 13px; }
#rtoc-sidebar .rtoc-sb-credits-text {
    display: flex; flex-direction: column; overflow: hidden; min-width: 0;
}
#rtoc-sidebar .rtoc-sb-credits-val {
    font-size: 13px; font-weight: 700; color: #93c5fd; white-space: nowrap;
}
#rtoc-sidebar .rtoc-sb-credits-lbl {
    font-size: 10px; color: #334155; white-space: nowrap; font-weight: 500;
}

/* ── Collapsed tooltip ───────────────────────────────────────────────── */
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-item {
    justify-content: center;
    padding-left: 0;
    padding-right: 0;
}
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-item::after {
    content: attr(data-label);
    position: absolute;
    left: calc(var(--sb-cw) + 6px);
    top: 50%;
    transform: translateY(-50%);
    background: #1e293b;
    color: var(--sb-text-bright);
    font-size: 12px;
    font-weight: 500;
    padding: 5px 12px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s;
    z-index: 2000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    border: 1px solid rgba(255,255,255,0.08);
}
#rtoc-sidebar.rtoc-sb-is-collapsed .rtoc-sb-item:hover::after { opacity: 1; }

/* ── Mobile ──────────────────────────────────────────────────────────── */
#rtoc-sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(2px);
    z-index: 1199;
}
#rtoc-mobile-btn {
    display: none;
    position: fixed; top: 12px; left: 12px;
    z-index: 1300;
    background: #0d1424;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 8px;
    width: 40px; height: 40px;
    cursor: pointer;
    align-items: center; justify-content: center;
    color: #94a3b8;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    transition: background 0.18s, color 0.18s;
}
#rtoc-mobile-btn:hover { background: #1e293b; color: #e2e8f0; }
#rtoc-mobile-btn svg { width: 20px; height: 20px; stroke: currentColor; }

@media (max-width: 880px) {
    #rtoc-sidebar {
        position: fixed !important;
        top: 0 !important; left: -270px !important;
        height: 100vh !important;
        z-index: 1200 !important;
        transition: left 0.26s var(--sb-ease) !important;
        width: var(--sb-w) !important;
        flex: none !important;
        min-width: 0 !important;
    }
    #rtoc-sidebar.rtoc-sb-mobile-open { left: 0 !important; }
    #rtoc-sidebar-overlay.rtoc-overlay-visible { display: block; }
    #rtoc-mobile-btn { display: flex; }
    .rtoc-main-content { width: 100% !important; }
}
</style>
CSSEND;

    // -- Sidebar JS v9 -------------------------------------------------------
    // Loaded as an external file to comply with Moodle 4.3+ CSP 'self' directive.
    // Inline <script> blocks are blocked by Moodle's Content-Security-Policy unless
    // they carry a server-issued nonce. External same-origin scripts are always allowed.
    global $CFG;
    $_rtoc_sb_jsurl = (new moodle_url('/local/rtocompliance/js/sidebar.js'))->out();
    $html .= '<script src="' . $_rtoc_sb_jsurl . '"></script>';

    // v4.4.40: table scroll-wrapper + full-screen expand (tables.js)
    $_rtoc_tbl_jsurl = (new moodle_url('/local/rtocompliance/js/tables.js'))->out();
    $html .= '<script src="' . $_rtoc_tbl_jsurl . '"></script>';

    // (Old inline <script> block moved to js/sidebar.js — see above)
    if (false) { // DEAD CODE: preserves the heredoc delimiter JSEND so PHP parses cleanly
    $html .= <<<'JSEND'
<script>
(function() {
    var STORAGE_KEY = 'rtoc_sb_collapsed';
    var SB_W  = 258;
    var SB_CW = 62;

    var sidebar   = document.getElementById('rtoc-sidebar');
    var toggleBtn = document.getElementById('rtoc-sb-toggle-btn');
    var mobileBtn = document.getElementById('rtoc-mobile-btn');
    var overlay   = document.getElementById('rtoc-sidebar-overlay');

    if (!sidebar) return;

    function isMobile() { return window.innerWidth <= 880; }

    // -- MOBILE: slide-in overlay ------------------------------------------
    function setupMobile() {
        if (mobileBtn) {
            mobileBtn.addEventListener('click', function() {
                sidebar.classList.toggle('rtoc-sb-mobile-open');
                if (overlay) overlay.classList.toggle('rtoc-overlay-visible');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('rtoc-sb-mobile-open');
                overlay.classList.remove('rtoc-overlay-visible');
            });
        }
        sidebar.querySelectorAll('.rtoc-sb-item').forEach(function(a) {
            a.addEventListener('click', function() {
                sidebar.classList.remove('rtoc-sb-mobile-open');
                if (overlay) overlay.classList.remove('rtoc-overlay-visible');
            });
        });
    }

    // -- Toggle collapse (desktop only) ------------------------------------
    var chevronL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>';
    var chevronR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

    function applyCollapsed(collapsed) {
        if (isMobile()) return;
        if (collapsed) {
            sidebar.classList.add('rtoc-sb-is-collapsed');
            sidebar.style.setProperty('width',      SB_CW + 'px', 'important');
            sidebar.style.setProperty('min-width',  SB_CW + 'px', 'important');
            sidebar.style.setProperty('flex-basis', SB_CW + 'px', 'important');
            if (toggleBtn) toggleBtn.innerHTML = chevronR;
        } else {
            sidebar.classList.remove('rtoc-sb-is-collapsed');
            sidebar.style.setProperty('width',      SB_W + 'px', 'important');
            sidebar.style.setProperty('min-width',  SB_W + 'px', 'important');
            sidebar.style.setProperty('flex-basis', SB_W + 'px', 'important');
            if (toggleBtn) toggleBtn.innerHTML = chevronL;
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var willCollapse = !sidebar.classList.contains('rtoc-sb-is-collapsed');
            applyCollapsed(willCollapse);
            localStorage.setItem(STORAGE_KEY, willCollapse ? '1' : '0');
        });
    }

    // -- Credit balance (async, non-blocking) ------------------------------
    function fetchCredits() {
        // Prefer rtoc-ai-config (edit pages), fall back to rtoc-sb-api-config (all pages).
        var cfg = document.getElementById('rtoc-ai-config') || document.getElementById('rtoc-sb-api-config');
        var valEl = document.getElementById('rtoc-sb-credits-val');
        if (!cfg || !valEl) return;
        var apikey = cfg.dataset.apikey || '';
        var apiurl = (cfg.dataset.apiurl || 'https://lms-labs.com').replace(/\/$/, '');
        var siteid = cfg.dataset.siteid || '';
        if (!apikey) return;
        // POST /api/credits — returns { credits, creditsRaw, isUnlimited }
        fetch(apiurl + '/api/credits', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ siteId: siteid, apiKey: apikey })
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(d) {
            if (!d) return;
            if (d.isUnlimited || d.creditsRaw === -1) {
                valEl.textContent = 'Unlimited';
                valEl.style.color = '#34d399';
            } else {
                var bal = d.credits !== undefined ? Number(d.credits) : 0;
                valEl.textContent = bal.toLocaleString() + ' credits';
                valEl.style.color = bal < 10 ? '#f87171' : (bal < 50 ? '#fbbf24' : '#93c5fd');
            }
        })
        .catch(function() { /* silent — credits widget is non-critical UI */ });
    }

    // -- Init --------------------------------------------------------------
    function doInit() {
        // Always wire up mobile handlers — user may resize desktop→mobile at any time.
        setupMobile();
        // Apply desktop collapse state only if we started on desktop.
        if (!isMobile()) {
            applyCollapsed(localStorage.getItem(STORAGE_KEY) === '1');
        }
        // Fetch credits after a short delay to not compete with page load.
        setTimeout(fetchCredits, 600);
        // Scroll active sidebar item into view so it is always visible on page load.
        var activeItem = sidebar.querySelector('.rtoc-sb-active');
        if (activeItem) {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        // Prevent the browser restoring a saved scroll position on nav, which
        // would fire before our scroll and trip the guard below.
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        // Auto-scroll: push the page so Moodle's fixed primary header stays visible
        // but ALL secondary chrome (secondary-nav, breadcrumbs, page-header) scrolls away,
        // leaving plugin content flush against the bottom edge of the fixed header.
        setTimeout(function() {
            if (window.scrollY > 80) return; // user already scrolled — respect their position
            // Measure the actual height of Moodle's fixed primary navbar at runtime.
            var navEl = document.querySelector('.navbar.fixed-top')
                     || document.querySelector('#page.fixed-top')
                     || document.querySelector('.fixed-top');
            var navH = navEl ? Math.round(navEl.getBoundingClientRect().height) : 56;
            // Find the plugin content region.
            var main = document.querySelector('#region-main')
                    || document.querySelector('.main-inner')
                    || document.querySelector('[role="main"]');
            if (main) {
                var mainTop = Math.round(main.getBoundingClientRect().top + window.scrollY);
                // Only scroll if there is actually secondary chrome to hide.
                if (mainTop > navH + 4) {
                    window.scrollTo({ top: mainTop - navH, behavior: 'smooth' });
                }
            }
        }, 300);
    }

    document.addEventListener('DOMContentLoaded', doInit);

    window.addEventListener('resize', function() {
        if (!isMobile()) {
            applyCollapsed(localStorage.getItem(STORAGE_KEY) === '1');
            sidebar.classList.remove('rtoc-sb-mobile-open');
            if (overlay) overlay.classList.remove('rtoc-overlay-visible');
        }
    });
})();

/* ── Collapsible fieldset fallback (v4.0.60) ─────────────────────────────
   Moodle's core/collapsible_section AMD module may not fire if RequireJS
   is delayed or Bootstrap collapse init is skipped. This plain-JS handler
   directly toggles fieldset.collapsible sections so TAS Generator accordion
   sections always open/close regardless of AMD module state.
   ──────────────────────────────────────────────────────────────────────── */
(function () {
    function initCollapsibleFallback() {
        var fieldsets = document.querySelectorAll('.mform fieldset.collapsible');
        if (!fieldsets.length) return;

        fieldsets.forEach(function (fs) {
            var header = fs.querySelector('> div:first-child');
            if (!header || header.dataset.rtocInited) return;
            header.dataset.rtocInited = '1';

            header.addEventListener('click', function (e) {
                /* Let direct child buttons / links in collapsible-actions handle
                   themselves (Expand all / Collapse all). */
                if (e.target.closest('.collapsible-actions')) return;

                var isCollapsed = fs.classList.contains('collapsed');
                var container   = fs.querySelector('.fcontainer');
                if (!container) return;

                /* If Moodle / Bootstrap already toggled the state by the time
                   our listener fires, do nothing (they handled it). We detect
                   this by checking whether the state changed since mousedown. */
                var stateBeforeClick = header.dataset.rtocState;
                var stateNow = isCollapsed ? 'collapsed' : 'open';
                if (stateBeforeClick && stateBeforeClick !== stateNow) {
                    /* State already changed by another handler — sync and exit. */
                    header.dataset.rtocState = stateNow;
                    return;
                }

                /* Manually toggle. */
                if (isCollapsed) {
                    fs.classList.remove('collapsed');
                    container.style.display = '';
                    container.classList.add('show');
                    container.classList.remove('collapse');
                    var fheader = header.querySelector('a.fheader');
                    if (fheader) { fheader.setAttribute('aria-expanded', 'true'); fheader.classList.remove('collapsed'); }
                } else {
                    fs.classList.add('collapsed');
                    container.style.display = 'none';
                    container.classList.remove('show');
                    var fheader2 = header.querySelector('a.fheader');
                    if (fheader2) { fheader2.setAttribute('aria-expanded', 'false'); fheader2.classList.add('collapsed'); }
                }
                header.dataset.rtocState = isCollapsed ? 'open' : 'collapsed';
            });

            /* Record initial state so we can detect external toggles. */
            header.dataset.rtocState = fs.classList.contains('collapsed') ? 'collapsed' : 'open';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibleFallback);
    } else {
        initCollapsibleFallback();
        /* Also run after a short delay in case Moodle's AMD fires late. */
        setTimeout(initCollapsibleFallback, 800);
    }
})();
</script>
JSEND;
    } // end if (false) — dead code block

    return $html;
}


/**
 * Return a minimal SVG icon string for use in stat-icon-wrap divs.
 */
function local_rtocompliance_stat_icon(string $name): string {
    static $icons = [];
    if (empty($icons)) {
        $s = function (string $paths): string {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
                . ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
                . ' stroke-linejoin="round">' . $paths . '</svg>';
        };
        $icons = [
            'bar'      => $s('<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'),
            'check'    => $s('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'),
            'clock'    => $s('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
            'alert'    => $s('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
            'shield'   => $s('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
            'users'    => $s('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
            'user'     => $s('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
            'calendar' => $s('<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'),
            'award'    => $s('<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>'),
            'mail'     => $s('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'),
            'file'     => $s('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'),
            'x'        => $s('<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'),
            'dollar'   => $s('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'),
            'book'     => $s('<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>'),
            'list'     => $s('<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'),
            'activity' => $s('<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'),
            'target'   => $s('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>'),
            'percent'  => $s('<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>'),
            'star'     => $s('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'),
            'trending' => $s('<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>'),
        ];
    }
    return $icons[$name] ?? $icons['bar'];
}

/**
 * ROLE-SPLIT (v4.2.30, 30 Apr 2026): resolve the calling user's RTO Compliance
 * role tier as a single string, used by trainer_dashboard.php, settings.php,
 * and the Primary Navigation hook below.  Result tiers (highest → lowest):
 *   'siteadmin' — moodle/site:config
 *   'manager'   — local/rtocompliance:manage  (full dashboard, governance, financials)
 *   'trainer'   — local/rtocompliance:viewtrainer  (own classes / students / surveys)
 *   'student'   — none of the above (own profile only)
 */
function local_rtocompliance_user_role(): string {
    global $DB, $USER;

    if (!isloggedin() || isguestuser()) {
        return 'student';
    }
    $sysctx = context_system::instance();
    if (is_siteadmin()) {
        return 'siteadmin';
    }
    if (has_capability('local/rtocompliance:manage', $sysctx)
        || has_capability('local/rtocompliance:viewall', $sysctx)) {
        return 'manager';
    }
    if (has_capability('local/rtocompliance:viewtrainer', $sysctx)) {
        return 'trainer';
    }
    // Fallback: users with Moodle's built-in editingteacher or teacher role
    // in any course context are treated as trainers without requiring a manual
    // capability assignment in Site Administration → Permissions.  This means
    // teachers see their trainer-scoped view straight away after installation,
    // before the administrator has had a chance to assign custom capabilities.
    $teacherroleids = $DB->get_fieldset_select('role', 'id',
        "archetype IN ('editingteacher', 'teacher')");
    if ($teacherroleids) {
        list($insql, $params) = $DB->get_in_or_equal($teacherroleids);
        $params[] = (int)$USER->id;
        // FIX-BLOCK-TEACHER-BLANK (v4.4.47): Changed ctx.contextlevel >= 50 to >= 10.
        // The previous query only detected teacher role assignments at course level
        // or below (50=course, 70=module, 80=block). Teachers assigned at category
        // level (40) or system level (10) were silently missed → role fell through
        // to 'student' → block rendered completely blank for those users.
        // Changing to >= 10 catches all valid Moodle context levels.
        $hasrole = $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.roleid $insql
                AND ra.userid = ?
                AND ctx.contextlevel >= 10",
            $params
        );
        if ($hasrole) {
            return 'trainer';
        }
    }
    return 'student';
}

/**
 * NAV-PRIMARY (v4.2.30, 30 Apr 2026): the dashboard previously lived ONLY in
 * the Site Administration tree (settings.php), which Moodle's standard themes
 * (Boost, Classic, etc.) only surface to users holding moodle/site:config.
 * A role-mapped manager / trainer therefore HAD the local/rtocompliance:manage
 * cap but had no way to discover or navigate to the dashboard from any nav
 * menu.  This callback registers an "RTO Compliance" entry in Moodle's global
 * navigation tree (rendered as a top-level item in the Boost primary nav and
 * as a Site-pages branch in Classic) that routes every authenticated user to
 * the correct role-scoped landing page:
 *   siteadmin / manager → /local/rtocompliance/index.php   (full dashboard)
 *   trainer             → /local/rtocompliance/trainer_dashboard.php
 *   student             → no nav entry shown
 *
 * Site Administration → RTO Compliance is preserved (settings.php) for the
 * actual configuration screens, but it is no longer the only entry point.
 */
function local_rtocompliance_extend_navigation(global_navigation $nav) {
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $role = local_rtocompliance_user_role();
    if ($role === 'student') {
        return;
    }
    if ($role === 'trainer') {
        $url   = new moodle_url('/local/rtocompliance/trainer_dashboard.php');
        $label = get_string('trainerdashboard', 'local_rtocompliance');
    } else {
        $url   = new moodle_url('/local/rtocompliance/index.php');
        $label = get_string('rtocompliance_navtitle', 'local_rtocompliance');
    }
    $node = $nav->add(
        $label,
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_rtocompliance_primary',
        new pix_icon('i/competencies', '')
    );
    if ($node) {
        $node->showinflatnavigation = true; // Boost primary nav (top bar)
    }
}

/**
 * v4.2.36/v4.2.37 — Generate the certificate PDF, email it to the student, mark
 * the cert emailed, log the action. Shared by:
 *   - email_cert.php (single-cert legacy + AJAX paths)
 *   - bulk_action_cert.php (bulk email loop)
 *
 * Caller is responsible for capability checks and USI gating before invocation.
 *
 * @param object $cert Row from local_rtocompliance_certs (must have ->id, ->certnumber,
 *                     ->certtype, ->qualificationcode, ->qualificationname, ->units,
 *                     ->issuedate, ->replacement_of, ->emailsent, ->verifytoken).
 * @param object $user Row from {user} (must have ->id, ->email, ->firstname, ->lastname).
 * @return array {ok: bool, email: string, error?: string}
 */
function local_rtocompliance_send_certificate_email(object $cert, object $user): array {
    global $CFG, $DB, $USER;

    $rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Training Organisation';
    $certtypes = local_rtocompliance_get_certificate_types();
    $certtypename = $certtypes[$cert->certtype] ?? $cert->certtype;

    // v4.2.58 — Single source of truth for PDF rendering: route through the
    // canonical render_certificate_pdf_string() (which itself dispatches
    // to template renderer first, then ASQA-compliant legacy fallback).
    // This eliminates the second hard-coded TCPDF generator that used to
    // live inline in this function and was missing five ASQA mandates
    // (see audit notes in v4.2.58 changelog).
    $pdfbytes = local_rtocompliance_render_certificate_pdf_string($cert, $user);
    $filename = 'certificate_' . $cert->certnumber . '.pdf';
    $temppath = $CFG->tempdir . '/' . $filename;
    file_put_contents($temppath, $pdfbytes);

    // FIX-TEMPFILE-CLEANUP (v5.9.277): register a shutdown function to clean up
    // the temp PDF even if email_to_user() throws an uncaught exception before
    // the explicit @unlink() below is reached.  The shutdown callback is a no-op
    // if the file has already been deleted by the normal path.
    register_shutdown_function(function() use ($temppath) {
        if (file_exists($temppath)) {
            @unlink($temppath);
        }
    });

    // Subject/body — reissues use different strings.
    $isreissue = !empty($cert->replacement_of);
    $originalcert = $isreissue ? $DB->get_record('local_rtocompliance_certs', ['id' => $cert->replacement_of]) : null;

    if ($isreissue && $originalcert) {
        $subject = get_string('email_reissue_subject', 'local_rtocompliance', [
            'certtype'       => $certtypename,
            'originalnumber' => $originalcert->certnumber,
        ]);
        $messagehtml = get_string('email_reissue_body', 'local_rtocompliance', [
            'fullname'       => fullname($user),
            'certtype'       => $certtypename,
            'certnumber'     => $cert->certnumber,
            'originalnumber' => $originalcert->certnumber,
            'originaldate'   => userdate($originalcert->issuedate, '%d %B %Y'),
            'rtoname'        => $rtoname,
        ]);
    } else {
        $subject = get_string('email_certificate_subject', 'local_rtocompliance', $certtypename);
        $messagehtml = get_string('email_certificate_body', 'local_rtocompliance', [
            'fullname'   => fullname($user),
            'certtype'   => $certtypename,
            'certnumber' => $cert->certnumber,
            'rtoname'    => $rtoname,
        ]);
    }

    $messagetext = html_to_text($messagehtml);
    $supportuser = core_user::get_support_user();

    $sent = email_to_user($user, $supportuser, $subject, $messagetext, $messagehtml, $temppath, $filename);
    @unlink($temppath);

    if (!$sent) {
        return ['ok' => false, 'email' => $user->email, 'error' => 'email_to_user() returned false'];
    }

    $now = time();
    $DB->set_field('local_rtocompliance_certs', 'emailsent', 1, ['id' => $cert->id]);
    $DB->set_field('local_rtocompliance_certs', 'emailsentdate', $now, ['id' => $cert->id]);
    $DB->set_field('local_rtocompliance_certs', 'timemodified', $now, ['id' => $cert->id]);

    $DB->insert_record('local_rtocompliance_log', [
        'action'       => 'email_certificate',
        'component'    => 'certificates',
        'itemid'       => $cert->id,
        'userid'       => $USER->id,
        'targetuserid' => $user->id,
        'details'      => json_encode([
            'certnumber' => $cert->certnumber,
            'email'      => $user->email,
            'isreissue'  => $isreissue,
        ]),
        'ipaddress'    => getremoteaddr(),
        'timecreated'  => $now,
    ]);

    return ['ok' => true, 'email' => $user->email];
}

/**
 * v4.2.37 — Render the certificate PDF to an in-memory string (no file I/O,
 * no email, no DB writes). Used by bulk_action_cert.php "download_zip" action
 * to build a ZipArchive of multiple PDFs to stream to the admin.
 *
 * @param object $cert Row from local_rtocompliance_certs.
 * @param object $user Row from {user}.
 * @return string Raw PDF bytes.
 */
function local_rtocompliance_render_certificate_pdf_string(object $cert, object $user, string $orientation_override = ''): string {
    global $CFG;
    require_once($CFG->libdir . '/pdflib.php');

    // CERT-TEMPLATE-BUILDER (v4.2.40+) — if an active approved template
    // exists for this cert type, render it via the template renderer.
    // After v4.2.58 the upgrade routine seeds default approved templates
    // for all four cert types on a fresh install, so the legacy fallback
    // below is only ever hit when an admin explicitly deactivates every
    // template.  Even so, the fallback is now also ASQA-compliant.
    require_once(__DIR__ . '/classes/cert_template.php');
    require_once(__DIR__ . '/classes/cert_template_renderer.php');
    try {
        // v4.3.0 CERT-TEMPLATE-AUDIENCES — pick_for_cert() honours
        // (1) the cert's saved certtmplid (set at issue time on every
        //     v4.3.0+ cert, stable across reissues), then
        // (2) (certtype + cert->audience) when an audience hint was
        //     attached to the row, then
        // (3) (certtype + 'default'), then
        // (4) any active template for the certtype (back-compat).
        $activetmpl = \local_rtocompliance\cert_template::pick_for_cert($cert);
        if ($activetmpl) {
            $payload = \local_rtocompliance\cert_template_renderer::resolve_payload($cert, $user);
            // v4.3.0 — apply per-template payload overrides written into
            // designjson.overrides{} by cert_template_edit.php so an
            // audience-specific template can stamp e.g. its own
            // apprenticeship statement without re-typing every field.
            try {
                $design = \local_rtocompliance\cert_template::decode_design($activetmpl);
                if (!empty($design['overrides']) && is_array($design['overrides'])) {
                    foreach ($design['overrides'] as $ovkey => $ovval) {
                        if ($ovval === '' || $ovval === null) {
                            continue;
                        }
                        $payload[(string) $ovkey] = $ovval;
                    }
                }
            } catch (\Throwable $eov) {
                debugging('cert_template overrides apply failed (non-fatal): ' . $eov->getMessage(),
                    DEBUG_DEVELOPER);
            }
            return \local_rtocompliance\cert_template_renderer::render($activetmpl, $payload, $orientation_override);
        }
    } catch (\Throwable $e) {
        // Template render failure must NEVER break certificate issuance —
        // fall through to the legacy renderer.
        //
        // H3-TEMPLATE-FALLBACK-LOG (v5.9.305): the previous code only called
        // debugging() at DEBUG_DEVELOPER, which is invisible on production sites.
        // Admins were unknowingly issuing certs from the legacy TCPDF layout
        // (which may lack current branding, NRT logo positioning, or custom fields)
        // with no indication that the template system had failed.
        //
        // Fix: always log to local_rtocompliance_log (visible in the admin audit view)
        // AND write a lightweight config flag so the dashboard can surface a warning
        // banner even if no one is watching the logs.
        debugging('cert_template render failed, falling back to legacy layout: ' . $e->getMessage(),
            DEBUG_DEVELOPER);
        try {
            global $DB, $USER;
            if ($DB->get_manager()->table_exists('local_rtocompliance_log')) {
                $fallbacklog = new stdClass();
                $fallbacklog->action       = 'cert_template_fallback';
                $fallbacklog->component    = 'certs';
                $fallbacklog->itemid       = $cert->id ?? 0;
                $fallbacklog->userid       = $USER->id ?? 0;
                $fallbacklog->targetuserid = $cert->userid ?? null;
                $fallbacklog->details      = json_encode([
                    'certnumber' => $cert->certnumber ?? '',
                    'certtype'   => $cert->certtype   ?? '',
                    'error'      => substr($e->getMessage(), 0, 500),
                ]);
                $fallbacklog->ipaddress   = getremoteaddr('');
                $fallbacklog->timecreated = time();
                $DB->insert_record('local_rtocompliance_log', $fallbacklog);
            }
            // Lightweight config flag — dashboard reads this and shows an amber warning.
            $prev = (int) get_config('local_rtocompliance', 'cert_template_fallback_count');
            set_config('cert_template_fallback_count', $prev + 1, 'local_rtocompliance');
            set_config('cert_template_fallback_last', json_encode([
                'certnumber' => $cert->certnumber ?? '',
                'certtype'   => $cert->certtype   ?? '',
                'time'       => time(),
                'error'      => substr($e->getMessage(), 0, 255),
            ]), 'local_rtocompliance');
        } catch (\Throwable $logex) {
            // Log write must never throw — this is inside a fallback handler.
            debugging('cert_template fallback logger error: ' . $logex->getMessage(), DEBUG_DEVELOPER);
        }
    }

    return local_rtocompliance_render_certificate_legacy_pdf($cert, $user, $orientation_override);
}

/**
 * v4.2.58 — Single ASQA-COMPLIANT legacy/fallback PDF generator.
 *
 * Before v4.2.58 there were two near-identical hard-coded TCPDF
 * generators (one inline in send_certificate_email(), one inline in
 * render_certificate_pdf_string()).  The ASQA cert audit hit on
 * An ASQA cert audit found the fallback was missing
 * five ASQA mandates.  v4.2.58 collapses both copies into this single
 * helper and fixes all five gaps:
 *
 *   1. NRT logo top-right (official ASQA PNG artwork, v4.4.1+) — required on
 *      testamur and Statement of Attainment only (not Record of Results
 *      or Completion, per the NRT Logo Conditions of Use Policy).
 *      AQF logo is painted below the NRT logo on the same two types.
 *      Organisation seal (RTO corporate identifier) is painted at the
 *      bottom-centre on testamur and SoA per ASQA Practice Guide items
 *      1(e) and 2(e) (v4.4.2 ASQA-AUDIT-LEGACY-LOGOS).
 *   2. AQF statement uses the ASQA-mandated wording "is recognised
 *      within the Australian Qualifications Framework" (was "is
 *      issued under" — wrong).
 *   3. Attainment wording "has fulfilled the requirements for"
 *      (was "has successfully completed all requirements for the" —
 *      wrong wording per ASQA fact sheet).
 *   4. Authorised signatory block (name + title from settings) above
 *      a printed signature line, bottom-left of the cert.
 *   5. Authenticity measure: verify URL + cert number bottom-right
 *      so a third party can verify the cert is real.
 *
 * Default page orientation per cert type matches the ASQA fact sheet
 * sample diagrams (testamur LANDSCAPE, statement/record PORTRAIT,
 * completion LANDSCAPE).
 *
 * @param object $cert
 * @param object $user
 * @return string Raw PDF bytes
 */
function local_rtocompliance_render_certificate_legacy_pdf(object $cert, object $user, string $orientation_override = ''): string {
    global $CFG;
    require_once($CFG->libdir . '/pdflib.php');
    require_once(__DIR__ . '/classes/cert_template.php');

    $rtoname        = get_config('local_rtocompliance', 'rtoname') ?: 'Training Organisation';
    $rtocode        = get_config('local_rtocompliance', 'rtocode') ?: '';
    $signatoryname  = get_config('local_rtocompliance', 'signatoryname') ?: '';
    $signatorytitle = get_config('local_rtocompliance', 'signatorytitle') ?: '';
    $aqfstmt        = get_config('local_rtocompliance', 'aqfstatement');
    if (!$aqfstmt) {
        $code = $cert->qualificationcode ?: '';
        $aqfstmt = $code
            ? ($code . ' is recognised within the Australian Qualifications Framework.')
            : 'This qualification is recognised within the Australian Qualifications Framework.';
    }
    $verifyurl = get_config('local_rtocompliance', 'verifyurl') ?: '';

    // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptor settings.
    $industrydescriptor   = get_config('local_rtocompliance', 'industrydescriptor')   ?: '';
    $occupationalstream   = get_config('local_rtocompliance', 'occupationalstream')   ?: '';
    $apprenticeshipstmt   = get_config('local_rtocompliance', 'apprenticeshipstatement') ?: '';
    $languagestmt         = get_config('local_rtocompliance', 'languagestatement')    ?: '';
    $skillsetstmt         = get_config('local_rtocompliance', 'skillsetstatement')    ?: '';
    // ASQA-COMPLIANCE-PASS-3 (v4.2.60) — completion-of-course statement (SoA).
    $completionstmt       = get_config('local_rtocompliance', 'completionofcoursestatement') ?: '';

    // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — uploaded RTO logo + signature image.
    $rtologopath = \local_rtocompliance\cert_template::get_branding_path(
        \local_rtocompliance\cert_template::BRANDING_ITEMID_LOGO) ?? '';
    $sigpath     = \local_rtocompliance\cert_template::get_branding_path(
        \local_rtocompliance\cert_template::BRANDING_ITEMID_SIGNATURE) ?? '';

    $certtypes = local_rtocompliance_get_certificate_types();
    $certtypename = $certtypes[$cert->certtype] ?? $cert->certtype;

    // Default orientation per cert type (ASQA fact sheet diagrams).
    // NRT-ORIENT-OVERRIDE (v4.4.7): caller may pass 'P' or 'L' to force orientation
    // (used by cert_test.php so admins can preview both sides of a design).
    if ($orientation_override === 'P' || $orientation_override === 'L') {
        $orientation = $orientation_override;
    } else {
        $orientation = ($cert->certtype === 'statement' || $cert->certtype === 'record') ? 'P' : 'L';
    }

    $pdf = new pdf($orientation, 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('RTO Compliance');
    $pdf->SetAuthor($rtoname);
    $pdf->SetTitle($certtypename . ' - ' . fullname($user));
    $pdf->SetSubject('Certificate');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(20, 18, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    $pagew = ($orientation === 'L') ? 297 : 210;
    $pageh = ($orientation === 'L') ? 210 : 297;
    $contentw = $pagew - 40;

    // ── ASQA-COMPLIANCE-PASS-2 (v4.2.59) — SoA prominent banner at TOP ─────
    // Fact sheet (page 4) requires the "A Statement of Attainment is
    // issued..." statement to be PROMINENT — placing it at the bottom as a
    // footer is not enough. Render it as a bordered banner before any
    // other content on the SoA.
    $topoffset = 18;
    if ($cert->certtype === 'statement') {
        $pdf->SetFillColor(255, 247, 230);   // light amber wash
        $pdf->SetDrawColor(217, 119, 6);     // amber border
        $pdf->Rect(20, $topoffset, $contentw, 14, 'DF');
        $pdf->SetXY(22, $topoffset + 2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(120, 53, 15);
        $pdf->MultiCell($contentw - 4, 4,
            'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.',
            0, 'C');
        $topoffset += 18;
    }

    // ── Header row: RTO logo top-left (if uploaded) ────────────────────────
    if ($rtologopath && file_exists($rtologopath)) {
        try {
            $pdf->Image($rtologopath, 20, $topoffset, 40, 0, '', '', '', false, 300, '', false, false, 0, 'LT');
        } catch (\Throwable $e) {
            // Bad image — non-fatal.
        }
    }

    // ── Header row: NRT logo top-right (testamur + statement only) ─────────
    // ASQA fact sheet: NRT logo appears on testamur and SoA. Record of
    // Results and Certificate of Completion do NOT carry the NRT mark
    // (NRT Logo Conditions of Use Policy — enforced here and in the
    // modern template validator). v4.4.1 replaced the placeholder SVG
    // with the official ASQA-supplied PNG artwork; v4.4.2 wires this
    // legacy fallback renderer to the same resolve_compliance_asset_path()
    // helper used by the modern template renderer so admin-uploaded
    // artwork takes precedence over the bundled pix/ fallback.
    if (in_array($cert->certtype, ['testamur', 'statement'], true)) {
        $nrtpath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_NRT_LOGO,
            'nrt_logo_file',
            'nrt_logo.png'
        );
        if ($nrtpath && file_exists($nrtpath)) {
            try {
                $pdf->Image($nrtpath, $pagew - 60, $topoffset, 40, 0, '', '', '', false, 300, '', false, false, 0, 'LT');
            } catch (\Throwable $e) {
                // Bad image — non-fatal.
            }
        }
    }

    // ── Header row: AQF logo below NRT (testamur + statement only) ──────────
    // ASQA fact sheet: AQF logo required on testamur and SoA. Previously
    // missing from this legacy fallback renderer entirely (v4.4.2 fix).
    if (in_array($cert->certtype, ['testamur', 'statement'], true)) {
        $aqfpath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_AQF_LOGO,
            'aqf_logo_file',
            'aqf_logo.jpg'
        );
        if ($aqfpath && file_exists($aqfpath)) {
            try {
                $pdf->Image($aqfpath, $pagew - 60, $topoffset + 20, 40, 0, '', '', '', false, 300, '', false, false, 0, 'LT');
            } catch (\Throwable $e) {
                // Bad image — non-fatal.
            }
        }
    }

    // ── RTO name + code centred ────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColor(0, 51, 102);
    $pdf->SetXY(20, $topoffset);
    $pdf->Cell($contentw, 12, $rtoname, 0, 1, 'C');

    if ($rtocode) {
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell($contentw, 6, 'RTO Code: ' . $rtocode, 0, 1, 'C');
    }

    $pdf->Ln(6);
    $pdf->SetDrawColor(0, 102, 153);
    $pdf->Line(20, $pdf->GetY(), $pagew - 20, $pdf->GetY());
    $pdf->Ln(8);

    // ── Cert type heading ──────────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($contentw, 10, strtoupper($certtypename), 0, 1, 'C');
    $pdf->Ln(2);

    // ── Record of Results: ASQA-style "Name of student / qualification" labels ─
    if ($cert->certtype === 'record') {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(45, 6, 'Name of student:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell($contentw - 45, 6, fullname($user), 0, 1, 'L');
        if ($cert->qualificationcode || $cert->qualificationname) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(45, 6, 'Name of qualification:', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 11);
            $qualline = trim(($cert->qualificationcode ?? '') . ' ' . ($cert->qualificationname ?? ''));
            $pdf->Cell($contentw - 45, 6, $qualline, 0, 1, 'L');
        }
    } else {
        // ── Intro line (testamur / statement / completion) ─────────────────
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->SetTextColor(60, 60, 60);
        if ($cert->certtype === 'statement') {
            // ASQA-COMPLIANCE-PASS-2 (v4.2.59): correct SoA wording.
            $pdf->Cell($contentw, 7, 'This is a statement that', 0, 1, 'C');
        } else if ($cert->certtype === 'completion') {
            $pdf->Cell($contentw, 7, 'This certificate is awarded to', 0, 1, 'C');
        } else {
            $pdf->Cell($contentw, 7, 'This is to certify that', 0, 1, 'C');
        }

        // ── Student fullname ───────────────────────────────────────────────
        $pdf->Ln(2);
        $pdf->SetFont('times', 'B', 24);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($contentw, 12, fullname($user), 0, 1, 'C');
        $pdf->Ln(2);

        // ── Attainment wording (ASQA-CORRECT) ──────────────────────────────
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->SetTextColor(60, 60, 60);
        if ($cert->certtype === 'testamur') {
            $pdf->Cell($contentw, 7, 'has fulfilled the requirements for', 0, 1, 'C');
        } else if ($cert->certtype === 'statement') {
            // ASQA-COMPLIANCE-PASS-2 (v4.2.59): correct SoA wording.
            $pdf->Cell($contentw, 7, 'has attained', 0, 1, 'C');
        } else if ($cert->certtype === 'completion') {
            $pdf->Cell($contentw, 7, 'in recognition of completion of', 0, 1, 'C');
        }

        // ── Qualification code + name ──────────────────────────────────────
        $pdf->Ln(3);
        if ($cert->qualificationcode && $cert->qualificationname) {
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor(0, 51, 102);
            $pdf->Cell($contentw, 8, $cert->qualificationcode . ' - ' . $cert->qualificationname, 0, 1, 'C');
        } else if ($cert->certtype === 'completion') {
            $coursetitle = !empty($cert->coursetitle) ? $cert->coursetitle
                         : (!empty($cert->qualificationname) ? $cert->qualificationname : 'Course / activity');
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor(0, 51, 102);
            $pdf->Cell($contentw, 8, $coursetitle, 0, 1, 'C');
        }
    }

    // ── Units list (statement / record) ────────────────────────────────────
    if (!empty($cert->units) && ($cert->certtype === 'statement' || $cert->certtype === 'record')) {
        $units = json_decode($cert->units, true) ?: [];
        if (!empty($units)) {
            $pdf->Ln(6);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(0, 0, 0);
            $label = ($cert->certtype === 'record')
                ? 'Units / modules enrolled:'
                : 'Units of competency:';
            $pdf->Cell($contentw, 6, $label, 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            foreach ($units as $unit) {
                $unitcode = $unit['code'] ?? '';
                $unitname = $unit['name'] ?? '';
                // SOA-DUPCODE-UNIT-FIX (v5.9.264): strip code prefix from name if already present.
                if ($unitcode !== '' && $unitname !== '' && strpos($unitname, $unitcode) === 0) {
                    $unitname = ltrim(substr($unitname, strlen($unitcode)), " \t-\xe2\x80\x94\xe2\x80\x93");
                }
                $pdf->Cell($contentw, 6, $unitcode . '  ' . $unitname, 0, 1, 'L');
            }
        }
    }

    // ── ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptors ────────────
    // Industry descriptor / occupational stream (testamur).
    if ($cert->certtype === 'testamur' && ($industrydescriptor || $occupationalstream)) {
        $pdf->Ln(4);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        if ($industrydescriptor) {
            $pdf->Cell($contentw, 5, $industrydescriptor, 0, 1, 'C');
        }
        if ($occupationalstream) {
            $pdf->Cell($contentw, 5, '(' . $occupationalstream . ')', 0, 1, 'C');
        }
    }
    // Skill set statement (SoA).
    if ($cert->certtype === 'statement' && $skillsetstmt) {
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell($contentw, 5, $skillsetstmt, 0, 'C');
    }
    // ASQA-COMPLIANCE-PASS-3 (v4.2.60) — completion-of-course statement (SoA).
    // Per ASQA Sample Forms fact sheet (page 4) — optional descriptor when
    // the statement of attainment relates to units that completed a course.
    if ($cert->certtype === 'statement' && $completionstmt) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell($contentw, 5, $completionstmt, 0, 'C');
    }
    // Apprenticeship + language (testamur + statement).
    if (in_array($cert->certtype, ['testamur', 'statement'], true)) {
        if ($apprenticeshipstmt) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->MultiCell($contentw, 4, $apprenticeshipstmt, 0, 'C');
        }
        if ($languagestmt) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->MultiCell($contentw, 4, $languagestmt, 0, 'C');
        }
    }

    // ── AQF statement (testamur + statement) — ASQA mandate ────────────────
    if ($cert->certtype === 'testamur' || $cert->certtype === 'statement') {
        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell($contentw, 5, $aqfstmt, 0, 'C');
    }

    // ── Signatory block (bottom-left) — ASQA mandate ───────────────────────
    $bottomy = $pageh - 30;

    // ── Organisation seal / corporate identifier — ASQA mandate (v4.4.2) ────
    // Required on testamur and SoA per ASQA Practice Guide (Issue of VET
    // qualifications and VET statements of attainment, items 1(e) and 2(e)).
    // Previously missing from this legacy fallback renderer. No bundled
    // fallback — RTO must upload their own via Site admin → Plugins →
    // Local plugins → AI RTO Compliance → Settings → Compliance logos.
    if (in_array($cert->certtype, ['testamur', 'statement'], true)) {
        $sealpath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
            \local_rtocompliance\cert_template::BRANDING_ITEMID_ORG_SEAL,
            'organisation_seal_file',
            ''
        );
        if ($sealpath && file_exists($sealpath)) {
            try {
                $sealx = ($pagew / 2) - 15;
                $pdf->Image($sealpath, $sealx, $bottomy - 16, 30, 0, '', '', '', false, 300, '', false, false, 0, 'LT');
            } catch (\Throwable $e) {
                // Bad image — non-fatal.
            }
        }
    }

    // ASQA-COMPLIANCE-PASS-2 (v4.2.59) — paint uploaded signature image
    // above the signatory line if available.
    if ($sigpath && file_exists($sigpath)) {
        try {
            $pdf->Image($sigpath, 20, $bottomy - 16, 50, 14, '', '', '', false, 300, '', false, false, 0, 'LB');
        } catch (\Throwable $e) {
            // Bad image — non-fatal.
        }
    }

    $pdf->SetDrawColor(80, 80, 80);
    $pdf->Line(20, $bottomy, 90, $bottomy);
    $pdf->SetXY(20, $bottomy + 1);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(70, 5, $signatoryname ?: 'Authorised Signatory', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(70, 5, $signatorytitle ?: ('Authorised by ' . $rtoname), 0, 1, 'L');

    // ── Authenticity measure + cert metadata (bottom-right) — ASQA mandate ─
    $rightx = $pagew - 90;
    $pdf->SetXY($rightx, $bottomy + 1);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(70, 5, 'Certificate Number: ' . $cert->certnumber, 0, 1, 'R');
    $pdf->SetX($rightx);
    $pdf->Cell(70, 5, 'Date of Issue: ' . userdate($cert->issuedate, '%d %B %Y'), 0, 1, 'R');
    if ($verifyurl) {
        $pdf->SetX($rightx);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(70, 5, 'Verify at: ' . $verifyurl, 0, 1, 'R');
    }

    return $pdf->Output('cert.pdf', 'S');
}
