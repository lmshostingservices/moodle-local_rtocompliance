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
 * RTO Compliance plugin — lib.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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
    // FIX-EDITOR-BRANDING-SERVE (v5.9.409): the certificate editor paints the RTO
    // branding assets in the canvas as <img src="pluginfile…"> URLs. Those assets
    // are uploaded via RTO Settings / Certificate Settings, which store them in
    // admin_setting_configstoredfile fileareas ('logo', 'nrt_logo_file', etc.) —
    // NONE of which this handler served, so every branding <img> 404'd and showed
    // an empty box (while the PDF, which reads the file off disk, rendered fine).
    // Serving them here (system context, managecerttemplates cap) makes the editor
    // canvas match the issued certificate.
    $brandingsettingareas = [
        'logo', 'nrt_logo_file', 'aqf_logo_file', 'organisation_seal_file',
        'compliance_logo_1', 'compliance_logo_2', 'ceo_signature_file',
        'secondary_logo', 'cert_background_file',
    ];
    $tmplareas = array_merge(['cert_template_bg', 'cert_template_image', 'rto_branding'], $brandingsettingareas);
    if (in_array($filearea, $tmplareas, true)) {
        require_capability('local/rtocompliance:managecerttemplates', $context);
    } else {
        // RPL-CT-EVIDENCE-UPLOAD (v5.9.410): 'rpl_evidence' (RPL evidence portfolio)
        // and 'ct_sourcecert' (credit-transfer source certificate/transcript from the
        // issuing RTO) are uploaded on the RPL & Credit Transfer register. Both hold
        // student assessment evidence, so they are gated by the general manage cap
        // (the register page itself is an admin_externalpage).
        $allowedareas = ['consultation_evidence', 'trainer_evidence', 'trainer_voccomp_evidence',
            'supervision_evidence', 'certificate9b', 'rpl_evidence', 'ct_sourcecert'];
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

/**
 * RPL-CT-EVIDENCE-UPLOAD (v5.9.410) — save one or more uploaded evidence files
 * (from a plain multipart <input type="file" multiple name="...[]">) into a
 * system-context filearea keyed by the RPL/CT record id. Used for both RPL
 * evidence ('rpl_evidence') and the credit-transfer source certificate
 * ('ct_sourcecert'). Mirrors the plugin's existing $_FILES-based upload style
 * (cert_template::save_branding_file) rather than the heavier form filemanager.
 *
 * @param int    $rplid     RPL/CT record id (used as the file itemid)
 * @param string $filearea  'rpl_evidence' | 'ct_sourcecert'
 * @param string $inputname the $_FILES key (the input's name without [])
 * @return int number of files stored
 */
function local_rtocompliance_save_rpl_evidence_files(int $rplid, string $filearea, string $inputname): int {
    if ($rplid <= 0 || empty($_FILES[$inputname]) || !is_array($_FILES[$inputname]['name'])) {
        return 0;
    }
    $allowedareas = ['rpl_evidence', 'ct_sourcecert'];
    if (!in_array($filearea, $allowedareas, true)) {
        return 0;
    }
    // Accept the document types RTOs actually receive as evidence.
    $allowedext = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx',
        'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'heic'];
    $maxbytes = 20 * 1024 * 1024; // 20 MB per file.
    $fs = get_file_storage();
    $contextid = \context_system::instance()->id;
    $saved = 0;
    $names = $_FILES[$inputname]['name'];
    foreach ($names as $i => $origname) {
        if (empty($origname) || !empty($_FILES[$inputname]['error'][$i])) {
            continue;
        }
        $tmp = $_FILES[$inputname]['tmp_name'][$i] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        if ((int) ($_FILES[$inputname]['size'][$i] ?? 0) > $maxbytes) {
            continue;
        }
        $clean = clean_param($origname, PARAM_FILE);
        if ($clean === '') {
            continue;
        }
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedext, true)) {
            continue;
        }
        // Avoid overwriting an existing same-named file — suffix a counter.
        $storename = $clean;
        $n = 1;
        while ($fs->file_exists($contextid, 'local_rtocompliance', $filearea, $rplid, '/', $storename)) {
            $base = pathinfo($clean, PATHINFO_FILENAME);
            $storename = $base . '_' . $n . ($ext !== '' ? '.' . $ext : '');
            $n++;
        }
        $filerecord = (object) [
            'contextid' => $contextid,
            'component' => 'local_rtocompliance',
            'filearea'  => $filearea,
            'itemid'    => $rplid,
            'filepath'  => '/',
            'filename'  => $storename,
        ];
        try {
            $fs->create_file_from_pathname($filerecord, $tmp);
            $saved++;
        } catch (\Throwable $e) {
            debugging('RPL evidence upload failed for ' . $clean . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    return $saved;
}

/**
 * RPL-CT-EVIDENCE-UPLOAD (v5.9.410) — return the stored evidence files for an
 * RPL/CT record, each as ['filename','url','size'] ready to list on the form.
 *
 * @param int    $rplid
 * @param string $filearea 'rpl_evidence' | 'ct_sourcecert'
 * @return array<int, array{filename:string,url:string,size:int}>
 */
function local_rtocompliance_get_rpl_evidence_files(int $rplid, string $filearea): array {
    if ($rplid <= 0) {
        return [];
    }
    $fs = get_file_storage();
    $contextid = \context_system::instance()->id;
    $files = $fs->get_area_files($contextid, 'local_rtocompliance', $filearea, $rplid,
        'filename', false);
    $out = [];
    foreach ($files as $f) {
        if ($f->is_directory()) {
            continue;
        }
        $url = \moodle_url::make_pluginfile_url($f->get_contextid(), $f->get_component(),
            $f->get_filearea(), $f->get_itemid(), $f->get_filepath(), $f->get_filename());
        $out[] = [
            'filename' => $f->get_filename(),
            'url'      => $url->out(false),
            'size'     => (int) $f->get_filesize(),
        ];
    }
    return $out;
}

/**
 * RPL-CT-EVIDENCE-UPLOAD (v5.9.410) — delete one stored evidence file by name.
 *
 * @param int    $rplid
 * @param string $filearea 'rpl_evidence' | 'ct_sourcecert'
 * @param string $filename
 * @return bool
 */
function local_rtocompliance_delete_rpl_evidence_file(int $rplid, string $filearea, string $filename): bool {
    if ($rplid <= 0 || $filename === '') {
        return false;
    }
    $allowedareas = ['rpl_evidence', 'ct_sourcecert'];
    if (!in_array($filearea, $allowedareas, true)) {
        return false;
    }
    $fs = get_file_storage();
    $contextid = \context_system::instance()->id;
    $file = $fs->get_file($contextid, 'local_rtocompliance', $filearea, $rplid, '/',
        clean_param($filename, PARAM_FILE));
    if ($file && !$file->is_directory()) {
        $file->delete();
        return true;
    }
    return false;
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

// NOTE (v5.9.341 HOOK-MIGRATION): the legacy local_rtocompliance_before_footer()
// callback was removed. It duplicated the tablesorter.js + tables.js injection that
// classes/hook/before_footer_html_generation.php already performs via the
// \core\hook\output\before_footer_html_generation hook (registered in db/hooks.php).
// The hook fires on every supported branch (Moodle 4.4+), so the legacy callback only
// ever double-injected the same <script> tags and raised the "should be migrated to
// hook" debug notice on 4.4/4.5. All footer injection now lives in the hook class.

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
    // FAQ link — on every page so users are always one click from the 100-question FAQ.
    $faqurl = (new moodle_url('/local/rtocompliance/faq.php'))->out();
    $html .= '<a href="' . $faqurl . '" class="rtoc-nav-help-btn rtoc-nav-faq-btn" title="Frequently Asked Questions — 100 plain-English answers">';
    $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="rtoc-nav-icon"><path d="M8 10h8M8 14h5"/><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $html .= '<span>FAQ</span>';
    $html .= '</a>';
    $html .= '<a href="' . $helplink . '" class="rtoc-nav-help-btn" target="_blank" title="Help &amp; Support">';
    $html .= '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="rtoc-nav-icon"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>';
    $html .= '<span>Help</span>';
    $html .= '</a>';
    $html .= '</div>';
    
    $html .= '</div>';

    // PAGE HELP CARD (v5.9.425): a consistent What / Why / How explainer directly under
    // the breadcrumb on every page that has content registered, so a first-time user
    // always lands on the same, easy-to-read orientation card (what the page is, why it
    // matters for compliance, and how to use it).
    $html .= local_rtocompliance_render_help_card(basename($PAGE->url->get_path()));

    return $html;
}

/**
 * PAGE BANNER (v5.9.433) — the standard royal-blue page banner shown at the top of every
 * main page, so every left-menu page opens with the same banner → help-card → content
 * structure. Reuses the existing .compliance-header gradient styling (identical to the
 * pages that already had a banner). The help card auto-positions itself directly below it.
 *
 * @param string $title       page title (plain text)
 * @param string $subtitle    optional one-line subtitle
 * @param string $actionshtml optional pre-built right-aligned action HTML (e.g. an Add button)
 * @return string HTML
 */
function local_rtocompliance_page_banner($title, $subtitle = '', $actionshtml = '') {
    $h  = '<div class="compliance-header rtoc-page-banner">';
    $h .= '<div class="rtoc-page-banner-text">';
    $h .= '<h2>' . s($title) . '</h2>';
    if ($subtitle !== '') {
        $h .= '<p class="rtoc-page-banner-sub">' . s($subtitle) . '</p>';
    }
    $h .= '</div>';
    if ($actionshtml !== '') {
        $h .= '<div class="rtoc-page-banner-actions">' . $actionshtml . '</div>';
    }
    $h .= '</div>';
    return $h;
}

/**
 * PAGE HELP CARD (v5.9.425) — render the consistent What / Why / How orientation card for
 * a page, keyed by its script filename. Returns '' when no content is registered for the
 * page (so pages opt in simply by having a registry entry). Uses pure-CSS radio tabs so it
 * needs no JavaScript and cannot fail after header output.
 *
 * @param string $script e.g. 'qualbuilder.php'
 * @return string HTML (empty if no content)
 */
function local_rtocompliance_render_help_card($script) {
    $all = local_rtocompliance_page_help_content();
    // TAB-AWARE (v5.9.430): the settings page hosts many sections under one script; resolve
    // a section-specific entry (script:section) so each settings tab gets its own card.
    $lookup = $script;
    if ($script === 'plugin_settings.php') {
        $sec = optional_param('section', '', PARAM_ALPHANUMEXT);
        if ($sec !== '' && isset($all[$script . ':' . $sec])) {
            $lookup = $script . ':' . $sec;
        }
    }
    if (empty($all[$lookup])) {
        return '';
    }
    $c = $all[$lookup];
    // RICH CONTENT OVERLAY (v5.9.430): layer page-specific, button-level "how" steps and
    // the top-3 "Key Features" over the base entry without editing the base registry.
    $overlay = local_rtocompliance_page_help_overlay();
    if (!empty($overlay[$lookup]) && is_array($overlay[$lookup])) {
        $c = array_merge($c, $overlay[$lookup]);
    }
    // Unique, CSS-safe key per page for the radio group + input ids.
    $key = preg_replace('/[^a-z0-9]+/', '', strtolower(str_replace(['.php', ':'], '', $lookup)));
    $name = 'rtochelp-' . $key;

    // PAGE HELP CARD (v5.9.429): the standard pill links to the relevant ASQA 2025
    // practice guide when one maps to this page (opens in a new tab). Falls back to a
    // plain pill otherwise.
    $badge = '';
    if (!empty($c['standard'])) {
        $guideurl = local_rtocompliance_practice_guide_url($lookup);
        if ($guideurl) {
            $extic = '<svg class="rtoc-help-badge-ic" xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
            $badge = '<a class="rtoc-help-badge rtoc-help-badge-link" href="' . $guideurl . '" target="_blank" rel="noopener noreferrer" title="Open the official reference for this standard (ASQA practice guide, or NCVER for AVETMISS) — opens in a new tab">'
                . s($c['standard']) . ' ' . $extic . '</a>';
        } else {
            $badge = '<span class="rtoc-help-badge">' . s($c['standard']) . '</span>';
        }
    }

    // Each panel accepts a short string or an array of bullet points.
    $panel = function ($body) {
        if (is_array($body)) {
            $out = '<ul class="rtoc-help-list">';
            foreach ($body as $li) {
                $out .= '<li>' . s($li) . '</li>';
            }
            return $out . '</ul>';
        }
        return '<p>' . s((string)$body) . '</p>';
    };

    $title = !empty($c['title']) ? $c['title'] : '';
    // Distinct, relevant icon per page (v5.9.430).
    $iconname = !empty($c['icon']) ? $c['icon'] : local_rtocompliance_help_icon_name($lookup);
    $iconsvg = local_rtocompliance_help_icon($iconname);

    $h  = '<div class="rtoc-help-card" data-rtoc-help="1">';
    $h .= '<div class="rtoc-help-head">';
    $h .= $iconsvg;
    $h .= '<span class="rtoc-help-title">' . s($title) . '</span>';
    $h .= $badge;
    $h .= '</div>';

    // KEY FEATURES (v5.9.430): optional 4th tab highlighting the top time-saving /
    // compliance features of the page as a numbered 1-2-3 list. Renders only when the
    // entry supplies a 'features' array (each item: a string, or ['title'=>,'desc'=>]).
    $hasfeat = !empty($c['features']) && is_array($c['features']);

    $h .= '<div class="rtoc-help-tabs">';
    // Radios first (siblings of panels) so the pure-CSS :checked ~ panel rules work.
    $h .= '<input type="radio" class="rtoc-help-radio rtoc-help-radio-what" name="' . $name . '" id="' . $name . '-what" checked>';
    $h .= '<input type="radio" class="rtoc-help-radio rtoc-help-radio-why" name="' . $name . '" id="' . $name . '-why">';
    $h .= '<input type="radio" class="rtoc-help-radio rtoc-help-radio-how" name="' . $name . '" id="' . $name . '-how">';
    if ($hasfeat) {
        $h .= '<input type="radio" class="rtoc-help-radio rtoc-help-radio-feat" name="' . $name . '" id="' . $name . '-feat">';
    }
    $h .= '<div class="rtoc-help-tabbar">';
    $h .= '<label for="' . $name . '-what" class="rtoc-help-tab rtoc-help-tab-what">What it is</label>';
    $h .= '<label for="' . $name . '-why" class="rtoc-help-tab rtoc-help-tab-why">Why it matters</label>';
    $h .= '<label for="' . $name . '-how" class="rtoc-help-tab rtoc-help-tab-how">How to use it</label>';
    if ($hasfeat) {
        $h .= '<label for="' . $name . '-feat" class="rtoc-help-tab rtoc-help-tab-feat">'
            . '<svg class="rtoc-help-tab-ic" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/></svg>'
            . 'Key Features</label>';
    }
    $h .= '</div>';
    $h .= '<div class="rtoc-help-panel rtoc-help-panel-what">' . $panel($c['what'] ?? '') . '</div>';
    $h .= '<div class="rtoc-help-panel rtoc-help-panel-why">' . $panel($c['why'] ?? '') . '</div>';
    $h .= '<div class="rtoc-help-panel rtoc-help-panel-how">' . $panel($c['how'] ?? '') . '</div>';
    if ($hasfeat) {
        // Each Key Feature is led by a small check icon (clean, not a heavy number badge).
        $ficon = '<span class="rtoc-help-feat-ic"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>';
        $fh = '<ul class="rtoc-help-featlist">';
        foreach ($c['features'] as $feat) {
            if (is_array($feat)) {
                $ft = trim((string)($feat['title'] ?? ''));
                $fd = trim((string)($feat['desc'] ?? ''));
                $fh .= '<li>' . $ficon . '<span>' . ($ft !== '' ? '<span class="rtoc-help-feat-t">' . s($ft) . '.</span> ' : '')
                    . '<span class="rtoc-help-feat-d">' . s($fd) . '</span></span></li>';
            } else {
                $fh .= '<li>' . $ficon . '<span class="rtoc-help-feat-d">' . s((string)$feat) . '</span></li>';
            }
        }
        $fh .= '</ul>';
        $h .= '<div class="rtoc-help-panel rtoc-help-panel-feat">' . $fh . '</div>';
    }
    $h .= '</div>';
    $h .= '</div>';

    // REORDER (v5.9.430): drop the help card directly BELOW the page's main banner (so the
    // banner stays at the top of the page). Pure DOM move on load; if a page has no banner
    // the card keeps its position under the breadcrumb. Runs once.
    $h .= "<script>(function (){function go(){var c=document.querySelector('.rtoc-help-card[data-rtoc-help=\"1\"]');"
        . "if(!c||c.getAttribute('data-placed'))return;"
        . "var m=c.closest('.rtoc-main-content')||document.body;"
        . "var b=m.querySelector('.compliance-header,.trainers-header,.qualbuilder-header,.students-header,.certificates-header,.support-header,.dashboard-header,.rtoc-page-banner');"
        . "if(b&&b.parentNode){b.parentNode.insertBefore(c,b.nextSibling);c.setAttribute('data-placed','1');}}"
        . "if(document.readyState!=='loading'){go();}else{document.addEventListener('DOMContentLoaded',go);}})();</script>";

    return $h;
}

/**
 * PAGE HELP CARD (v5.9.430) — return the inline SVG for a named icon (Lucide-style),
 * wrapped with the .rtoc-help-ic class. Falls back to an info glyph for unknown names.
 *
 * @param string $name
 * @return string
 */
function local_rtocompliance_help_icon($name) {
    $paths = [
        'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'shield'        => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'shieldalert'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'book'          => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'graduation'    => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1.66 2.69 3 6 3s6-1.34 6-3v-5"/>',
        'award'         => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
        'file'          => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'clipboardcheck'=> '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'clipboardlist' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'chart'         => '<line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>',
        'database'      => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'link'          => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'refresh'       => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'badgecheck'    => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
        'message'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'megaphone'     => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'dollar'        => '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'umbrella'      => '<path d="M22 12a10.06 10.06 0 0 0-20 0Z"/><path d="M12 12v8a2 2 0 0 0 4 0"/><path d="M12 2v1"/>',
        'briefcase'     => '<rect width="20" height="14" x="2" y="7" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'branch'        => '<line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        'pin'           => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'calendar'      => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'bell'          => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'gear'          => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'key'           => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'sparkles'      => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/>',
        'scroll'        => '<path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/>',
        'lifebuoy'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" x2="9.17" y1="4.93" y2="9.17"/><line x1="14.83" x2="19.07" y1="14.83" y2="19.07"/><line x1="14.83" x2="19.07" y1="9.17" y2="4.93"/><line x1="4.93" x2="9.17" y1="19.07" y2="14.83"/>',
        'send'          => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'checkcircle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'map'           => '<polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" x2="9" y1="3" y2="18"/><line x1="15" x2="15" y1="6" y2="21"/>',
    ];
    $inner = $paths[$name] ?? $paths['info'];
    // Chip wrapper (styled as the gradient square) with a centred white icon inside.
    return '<span class="rtoc-help-ic"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg></span>';
}

/**
 * PAGE HELP CARD (v5.9.430) — map a page (or settings section) to its icon name.
 *
 * @param string $lookup script or 'script:section'
 * @return string icon name
 */
function local_rtocompliance_help_icon_name($lookup) {
    $map = [
        'compliance_health.php' => 'shield',
        'students.php' => 'users', 'student_profile.php' => 'user', 'student_enrolments.php' => 'file',
        'student_support.php' => 'lifebuoy', 'student_support_input.php' => 'lifebuoy',
        'my_profile.php' => 'user', 'mydocs.php' => 'file',
        'qualbuilder.php' => 'graduation', 'qualbuilder_edit.php' => 'graduation', 'qualbuilder_unit.php' => 'graduation',
        'qualbuilder_courses.php' => 'link', 'qualbuilder_validate.php' => 'checkcircle', 'qualbuilder_results.php' => 'chart',
        'course_map.php' => 'map', 'data_import.php' => 'upload', 'nat_validate.php' => 'checkcircle',
        'natexport.php' => 'download', 'reconcile.php' => 'refresh', 'nominalhours_import.php' => 'clock',
        'certificates.php' => 'award', 'cert_templates.php' => 'award', 'cert_template_edit.php' => 'award',
        'cert_test.php' => 'award', 'qual_cert_hub.php' => 'award', 'soa_issue.php' => 'award',
        'issue_certificate.php' => 'award', 'generate_course_certs.php' => 'award', 'generate_qual_certs.php' => 'award',
        'mycerts.php' => 'award', 'email_cert.php' => 'send',
        'trainers.php' => 'users', 'trainer_edit.php' => 'user', 'trainer_dashboard.php' => 'user',
        'trainer_currency.php' => 'clock', 'trainer_voccomp.php' => 'badgecheck',
        'workforce_management.php' => 'users', 'supervision.php' => 'users', 'supervision_edit.php' => 'users',
        'validation.php' => 'clipboardcheck', 'validation_edit.php' => 'clipboardcheck', 'validator_edit.php' => 'user',
        'rpl.php' => 'badgecheck', 'rpl_edit.php' => 'badgecheck',
        'marketing_info.php' => 'megaphone', 'marketing_cards.php' => 'megaphone',
        'complaints.php' => 'message', 'complaint_edit.php' => 'message', 'appeal_edit.php' => 'message',
        'surveys.php' => 'clipboardlist', 'survey_send.php' => 'send', 'survey_responses.php' => 'chart',
        'qi_report.php' => 'chart', 'ai_analysis.php' => 'sparkles', 'ai_usage_report.php' => 'sparkles',
        'governance.php' => 'briefcase', 'governance_edit.php' => 'briefcase',
        'governance_minutes_edit.php' => 'file', 'governance_roles_edit.php' => 'users',
        'risk.php' => 'shieldalert', 'risk_edit.php' => 'shieldalert',
        'feeprotection.php' => 'dollar', 'feeprotection_edit.php' => 'dollar',
        'insurance.php' => 'umbrella', 'insurance_edit.php' => 'umbrella',
        'thirdparty.php' => 'briefcase', 'thirdparty_edit.php' => 'briefcase',
        'transitions.php' => 'branch', 'transition_edit.php' => 'branch',
        'asqa_standards_map.php' => 'checkcircle', 'compliance_map.php' => 'map',
        'tas.php' => 'clipboardlist', 'tas_edit.php' => 'clipboardlist', 'tas_consultation.php' => 'users',
        'usi_settings.php' => 'key', 'deadlines.php' => 'calendar',
        'locations.php' => 'pin', 'location_edit.php' => 'pin',
        'course_settings.php' => 'gear', 'plugin_settings.php' => 'gear',
        'alerts.php' => 'bell', 'auditlog.php' => 'scroll', 'audit.php' => 'scroll',
        'practice_guides.php' => 'book', 'student_declaration_send.php' => 'send',
        'suitability_bulk.php' => 'clipboardcheck', 'suitability_send.php' => 'send', 'suitability_view.php' => 'clipboardcheck',
        // settings sections
        'plugin_settings.php:local_rtocompliance_settings' => 'briefcase',
        'plugin_settings.php:local_rtocompliance_api' => 'link',
        'plugin_settings.php:local_rtocompliance_certs' => 'award',
        'plugin_settings.php:local_rtocompliance_usi' => 'key',
        'plugin_settings.php:local_rtocompliance_autosurvey' => 'clipboardlist',
        'plugin_settings.php:local_rtocompliance_asqa2025' => 'checkcircle',
        'plugin_settings.php:local_rtocompliance_statefunding' => 'dollar',
        'plugin_settings.php:local_rtocompliance_maintenance' => 'gear',
    ];
    return $map[$lookup] ?? 'info';
}

/**
 * PAGE HELP CARD (v5.9.430) — rich per-page overlay: button-level "how" steps and the
 * top-3 "Key Features" (time-saving / compliance) for each page. Merged over the base
 * entry at render time. Keyed by script (or 'script:section'). Populated per page as the
 * expert-authored content is written; pages without an overlay keep their base how/why
 * and simply do not show the Key Features tab.
 *
 * @return array
 */
function local_rtocompliance_page_help_overlay() {
    static $o = null;
    if ($o !== null) {
        return $o;
    }
    $o = [
        'qualbuilder.php' => [
            'how' => [
                'Click "Add Training Product" (the blue button top-right) to start a new qualification, skill set or single unit.',
                'Use the Type filter tabs — All, Qualification, Skill Set, Single Unit — to narrow the list to the product type you are working on.',
                'Read the "What the columns mean" legend, then check each row\'s Units, Linked Courses and Course Map counts to see how close a product is to auto-certificate readiness.',
                'Click "Edit" on a product to open the Smart Builder and attach Moodle delivery courses to its units via the inline dropdowns.',
                'Click the coloured Course Map badge (e.g. "8/12" or "All") to jump straight to the Course Map filtered to that qualification and fix any unconfirmed units.',
                'Click "Build Course Map from Links" (top-right) to fill the Course Map automatically from the delivery courses already attached to each unit — the one-click way to turn a "Course Map: None" or partial count into full coverage after creating or importing products. It only adds missing mappings and changes nothing else.',
                'Use the "View Results", "Certs" or "Delete" row actions to open the student progress grid, issue certificates, or remove a product.',
            ],
            'features' => [
                ['title' => 'See what is ready', 'desc' => 'The Units, Linked Courses, and Course Map columns show in one row how much of each product is set up for automatic certificates.'],
                ['title' => 'Fill the map in one click', 'desc' => 'The Build Course Map from Links button fills in the Course Map from links you already have, so you do not map each unit by hand.'],
                ['title' => 'Rules pass tick', 'desc' => 'A green tick next to the status shows the product\'s unit rules have passed the check before you make it Active.'],
            ],
        ],
        'qualbuilder_results.php' => [
            'how' => [
                'Type into the "Search name, email, USI or client ID" box to find a specific student across every qualification.',
                'Use the cascading Parent category, Sub-category and Course filters — plus the qualification, status and USI dropdowns — to narrow the Master Student Roster, then click "Apply" (or "Clear" to reset). The three cascade filters come from the courses students actually hold results in, so choosing a category or course narrows both the roster and every stat card together.',
                'Click a qualification bar in the "Students by qualification" panel, or a slice of the "USI health" panel, to instantly re-filter the roster.',
                'Click "Sync from completions" in the green card to pull the latest Moodle course completions into the results register, then "Download unmapped completions (CSV)" to see courses that could not be matched to a unit.',
                'Click a student\'s "Profile" button to open their full profile, or "Units" to open the unit-by-unit AVETMISS outcome grid.',
                'Click "Export CSV" to download the filtered roster with USI, DOB, state and competency counts for AVETMISS reporting.',
            ],
            'features' => [
                ['title' => 'One student list', 'desc' => 'It shows each student\'s imported and current Moodle results together in one table, so you never match up two systems by hand.'],
                ['title' => 'Flags missing ID numbers', 'desc' => 'Cards show which students lack a verified student ID number (USI is the student ID number) so you can fix it before reporting.'],
                ['title' => 'Matches completions', 'desc' => 'The sync scans all Moodle completions, including archived courses, and the download shows which courses still need linking.'],
            ],
        ],
        'course_map.php' => [
            'how' => [
                'Click "Scan & Seed All Quals" to auto-detect course-to-unit mappings from your Qualification Builder records and the Moodle category tree.',
                'Type a qualification code into the "Filter by qual code…" box and click "Filter" to show only that qualification\'s mappings.',
                'Review each row\'s Source badge (QB, Auto or Manual) and Status badge, and click the green confirm action to accept an Unconfirmed auto-detected mapping.',
                'Click the remove action to delete a wrong mapping, which excludes that course from completion detection until re-added.',
                'For a course that cannot be auto-detected, fill in the Qual Code, Unit Code and Moodle Course ID fields under "Add Manual Mapping" and click "Add Mapping".',
            ],
            'features' => [
                ['title' => 'One place for links', 'desc' => 'This one table decides which course counts for which unit, so results and certificates always match up correctly.'],
                ['title' => 'Fills itself in', 'desc' => 'The Scan and Seed button sets up the course-to-unit links for you in one click instead of adding each by hand.'],
                ['title' => 'You confirm each link', 'desc' => 'You decide which suggested links to accept, and links you add by hand are never wiped out by a later scan.'],
            ],
        ],
        'tas.php' => [
            'how' => [
                'Click "Create New TAS" (top-right) to start a new Training and Assessment Strategy document.',
                'Click any of the 9 numbered section cards (e.g. "Section 4 — Delivery Structure & Volume of Learning") to jump straight into editing that mandated section.',
                'Check the Completeness percentage badge on each row in the "Existing TAS Documents" table to see how much is still to be filled in.',
                'Use the Status badge (Draft, Review or Approved) and Last Modified date to identify which documents need attention.',
                'Click "Edit" to continue a document, "Export" to produce the finished TAS, or "Delete" to remove an obsolete one.',
            ],
            'features' => [
                ['title' => 'Nine-part template', 'desc' => 'The nine section cards make sure your plan covers everything the rules require, from industry input to trainer qualifications.'],
                ['title' => 'Completeness tracker', 'desc' => 'Each document shows a percentage complete, so you can see which plans are ready and which still have gaps.'],
                ['title' => 'Auto calculations', 'desc' => 'Learning-hours and trainer-qualification checks are worked out for you, removing manual maths and mistakes.'],
            ],
        ],
        'nominalhours_import.php' => [
            'how' => [
                'Check the info banner to see how many nominal-hours values the reference table currently holds.',
                'Choose the jurisdiction from the "Jurisdiction (state) for this import" dropdown — NAT for the NCVER national baseline or a state (e.g. VIC, WA) for an override.',
                'Enter a "Source reference (provenance)" such as "NCVER Nationally-agreed 2026Q2" so the import is traceable.',
                'Use the "Data file" chooser to select the NCVER tab-delimited .txt (or a .csv/.tsv with a unit code and hours column).',
                'Click "Import" and read the confirmation showing how many values were created, updated and skipped.',
            ],
            'features' => [
                ['title' => 'National standard hours', 'desc' => 'Load the free national list of set hours per unit as your baseline, which then feeds other tools automatically.'],
                ['title' => 'State overrides', 'desc' => 'Load a state file on top of the national one, so each unit uses the correct funded hours for your state.'],
                ['title' => 'Smart file reader', 'desc' => 'The importer reads your file, skips the heading row, and updates existing values instead of making duplicates when you import again.'],
            ],
        ],
        'transitions.php' => [
            'how' => [
                'Click "Add Transition Plan" (top-right) to record a superseded or deleted training product.',
                'Scan the table\'s "Teach-Out Deadline" and colour-coded Status column, where products show "Overdue", "X days left" or "Completed".',
                'Read the "Enrolments" column to see whether self-enrolment is "Closed in Moodle", "Still Open" (a risk past deadline), or has "No Moodle control".',
                'Check the "Students Affected" count to gauge the size of each teach-out cohort.',
                'Click "Manage" on a row to edit the plan and link a Moodle course so self-enrolment is disabled automatically when enrolments are closed.',
            ],
            'features' => [
                ['title' => 'Deadline warnings', 'desc' => 'The status column turns overdue plans red and near-deadline ones amber, so you see who must finish before a qualification expires.'],
                ['title' => 'Stops new enrolments', 'desc' => 'Linking a course lets the plan close off self-enrolment automatically, so no one signs up for a replaced qualification.'],
                ['title' => 'Still-open alert', 'desc' => 'A red "Still Open" badge flags any expired product where students can still enrol, catching a common audit problem.'],
            ],
        ],
        'students.php' => [
            'how' => [
                'Review the Quick Statistics cards at the top (Total Students, USI Missing, USI Has No DOB, Certificates Issued) to gauge roster health at a glance.',
                'Use the "Filter by status" dropdown to isolate problem cohorts such as "USI Present, DOB Missing" or "USI Verification Failed", optionally narrowing by "Filter by state", then click Search.',
                'When the DOB-sync bar appears, click "Choose NAT00080 file…" then "Upload & Sync DOBs" to backfill dates of birth so USI verification can proceed.',
                'In any student row click the amber "Verify via usi.gov.au →" button to run inline USI verification, watching the badge update in place.',
                'Pick a qualification in the bulk bar\'s TAS dropdown, tick students, then click "Send Selected" to issue eligibility checklists in bulk.',
                'Open a row\'s "Actions" menu to jump to Edit Profile, Enrolments, Certificates or Results.',
            ],
            'features' => [
                ['title' => 'Check student ID numbers', 'desc' => 'Each row has a Verify button that checks the student\'s ID number against the official service and updates the badge on the spot.'],
                ['title' => 'Fill in missing birthdays', 'desc' => 'Upload a national report file and the tool fills in missing dates of birth for all matching students at once.'],
                ['title' => 'Find students with gaps', 'desc' => 'Filters show exactly which students are missing details that would block their reporting or certificates.'],
            ],
        ],
        'student_profile.php' => [
            'how' => [
                'Read the blue hero header to confirm the student\'s Client ID, USI and the verification and profile-completeness pills.',
                'Check the "Pre-enrolment readiness" card\'s four Standard 2 gates and the amber "fields missing" list to see what still blocks the student.',
                'Scan the "Demographics (AVETMISS)" grid and per-qualification "Training results" progress bars, noting any units tagged "via Moodle" that need syncing.',
                'Use the action buttons — "Issue Statement of Attainment", "Issue Qualification Certificate" or "Record / edit results" — to act on competent units.',
                'Complete the editable AVETMISS form below the summary, filling required fields such as USI, date of birth, sex, suburb, postcode and state code, then click Save.',
                'On save, the profile auto-marks complete and, when USI and DOB are present, triggers an immediate USI verification.',
            ],
            'features' => [
                ['title' => 'Shows if they are ready', 'desc' => 'A readiness card brings together the checks you need to do before training starts, so staff spot anything missing early.'],
                ['title' => 'Live progress bars', 'desc' => 'Progress bars and coloured tags show how far each student has got and flag results still to be synced.'],
                ['title' => 'Checks before saving', 'desc' => 'The form checks all the required student details before saving, so you never end up with an incomplete record.'],
            ],
        ],
        'student_support.php' => [
            'how' => [
                'Read the "Standards 2.3 to 2.6" intro card to confirm these selections form the RTO\'s standing organisation-level support evidence.',
                'In the "Training Support Services (Standard 2.3)" card, tick every support the RTO offers, such as "Individual LLN support".',
                'In the "Reasonable Adjustments (Standard 2.4)" card, tick the standing adjustments available, such as "Extended time" or "Assistive technology".',
                'In the "Wellbeing Support (Standard 2.6)" card, tick supports like "Counselling referrals".',
                'Watch the green "Live Compliance Dashboard" recalculate coverage counts and the overall cover percentage as each box is ticked.',
                'Follow the "Trainer Input" link under Related pages to record per-student support against individual profiles.',
            ],
            'features' => [
                ['title' => 'Saved for everyone', 'desc' => 'Your ticks save to the site, not your browser, so the record is shared and visible on any computer.'],
                ['title' => 'Live coverage total', 'desc' => 'A running percentage shows how much support the RTO offers, so you can spot gaps early.'],
                ['title' => 'Grouped by area', 'desc' => 'Each list is tied to a support area, so what you offer lines up clearly with what is expected.'],
            ],
        ],
        'marketing_info.php' => [
            'how' => [
                'Read the "Standard 2.1" clause card confirming information must be disclosed before the student enrols and before they pay.',
                'Check the "Pre-enrolment disclosure coverage" hero percentage and progress bar to see how many mandatory items are marked provided.',
                'In the disclosure register, tick the "Provided" checkbox for each mandatory item (fees, refunds, USI requirement, complaints process, RPL/credit transfer, privacy).',
                'Enter where each item is disclosed in the "Where it is provided" field, e.g. "Website + Student Handbook v3".',
                'Tick the fee & obligation acknowledgement box confirming students sign and you retain it before payment, and set the "Marketing materials last reviewed" date.',
                'Click "Save disclosure register" to persist the Standard 2.1 evidence.',
            ],
            'features' => [
                ['title' => 'Shows how complete you are', 'desc' => 'A live percentage over the required items shows at a glance whether you are telling students everything they need before enrolling.'],
                ['title' => 'Records where you say it', 'desc' => 'Writing down exactly where each item is given builds the clear evidence a regulator looks for, not just a tick.'],
                ['title' => 'Proof before payment', 'desc' => 'It keeps a record that fees and obligations were explained to students before they paid, along with the review date.'],
            ],
        ],
        'complaints.php' => [
            'how' => [
                'Review the statistics cards (Open Complaints, Open Appeals, High / Critical Priority, Logged This Year) to gauge the register\'s current load.',
                'Click "Lodge New Complaint" to open the complaint form, or use the "Complaints", "Appeals" and "Continuous Improvement" tabs to switch registers.',
                'On the Complaints tab, scan the table\'s Reference, Status and Priority columns and click "View" on any row to open its full record.',
                'Switch to the Appeals tab and use "Lodge New Appeal" to record an appeal against a complaint decision.',
                'On the Continuous Improvement tab, click "Add Improvement Action" to log a corrective action with a Source, Date Identified and Target Date.',
                'Use each row\'s "View" or "Edit" button to progress an item from receipt to resolution.',
            ],
            'features' => [
                ['title' => 'Three lists in one', 'desc' => 'Complaints, appeals, and improvement actions all sit under one set of tabs so you can track each matter from start to finish.'],
                ['title' => 'See what\'s urgent', 'desc' => 'Cards for open and high-priority matters show serious or overdue items right away so nothing is missed.'],
                ['title' => 'Link the fixes', 'desc' => 'Improvement actions let you record a lasting fix for a complaint, showing the problem was properly closed out.'],
            ],
        ],
        'suitability_view.php' => [
            'how' => [
                'Read the summary header to confirm the student, qualification, current Status badge, and sent/completed dates.',
                'Work through Sections 1 to 5 — LLN self-report, Digital Literacy, Prior Skills, any Entry Requirements Gap Note, and disclosed Support Needs.',
                'Confirm the Student Declaration panel shows the typed electronic signature and signed-at timestamp.',
                'In the "Trainer Decision" panel, choose an outcome from "Suitable to Enrol", "Suitable with Support" or "Not Currently Suitable".',
                'Write the required "Advice to student" (emailed) and the separate "Trainer justification" (retained for audit), then tick the trainer declaration checkbox.',
                'Click "Save Decision & Notify Student" to record the judgement and email the student, or "Download PDF Report" to export the record.',
            ],
            'features' => [
                ['title' => 'Trainer decision saved', 'desc' => 'The required decision, advice, and reason with a ticked box create the recorded human judgement that is expected.'],
                ['title' => 'Student told automatically', 'desc' => 'Saving the decision emails the advice to the student, showing the outcome was shared with them.'],
                ['title' => 'Full record in one view', 'desc' => 'The five sections plus the student\'s signature give a complete, ready-to-show record before enrolment.'],
            ],
        ],
        'certificates.php' => [
            'how' => [
                'Use the filter bar to narrow the register by Search (name, email, or CERT-...), Cert type, Qualification, Issue year, date range, USI status, or Email status, then click "Apply".',
                'Toggle between the "Table" and "Cards" view buttons, and click a column header such as "Issued" or "Cert #" to sort.',
                'On a certificate row, click "Email" for one-click delivery to the student, "Download" or "Pack" for the PDF, or "Verify" to open the public QR page.',
                'To bulk-issue, click "Generate by Qualification" (Testamur + Record of Results) or "Generate by Course", pick from the dropdown, and click "Go".',
                'Tick the row checkboxes and use the floating bar\'s "Email", "Download ZIP", or "Export CSV" buttons to act on multiple certificates at once.',
                'Click "Reissue" to supersede a certificate with a fresh number, or "Delete" to revoke it, confirming the prompt.',
            ],
            'features' => [
                ['title' => 'Flags missing student ID', 'desc' => 'It clearly marks any certificate issued without a checked USI (the student\'s unique ID number) so nothing slips through before an audit.'],
                ['title' => 'Email many at once', 'desc' => 'Tick several students and press Email to send their certificates together, and it skips ones already sent or waiting on a student ID.'],
                ['title' => 'Keeps a full history', 'desc' => 'When you replace a certificate, the old one is kept and marked as replaced, so there is always a clear record for auditors.'],
            ],
        ],
        'cert_templates.php' => [
            'how' => [
                'If the quick-setup banner appears, click "Seed starter templates" to create and activate ASQA-compliant defaults for any certificate type missing an active template.',
                'Click a tab in the live preview panel (Testamur, Statement of Attainment, Record of Results, Certificate of Completion) to render a sample PDF with your current branding.',
                'In the create form, choose a Cert type, Orientation, Audience, and Name, then click "New template" to open the drag-and-drop editor.',
                'In the template table, use the row\'s "Edit", "Preview", and "Submit" buttons to move a draft toward approval.',
                'On an approved template, click "Activate" to make it the live layout for new certificates of that type.',
                'Use "Duplicate", "Deactivate", "Archive", or the "Show archived" toggle to manage older templates.',
            ],
            'features' => [
                ['title' => 'Ready-made designs', 'desc' => 'One click adds standard certificate designs for you to start from, and it never overwrites anything you have already built.'],
                ['title' => 'Built-in checks', 'desc' => 'Each template shows a simple badge telling you whether it is fine or has errors, so you catch problems before going live.'],
                ['title' => 'Branding reminder', 'desc' => 'A warning lists any missing logo, signature, or seal so your certificates never print with blank boxes in front of students.'],
            ],
        ],
        'qual_cert_hub.php' => [
            'how' => [
                'On the hub home, use the search box and the cascading Parent category, Sub-category and Course filters, then click "Search" to find a qualification, reading its Enrolled / Complete / Issued / Pending / Queue funnel columns. The cascade narrows the list to qualifications delivered in the chosen category or course.',
                'Click "Issue Pending" on a qualification row to bulk-issue Testamurs to every completed student who lacks one, or click "Detail" to open its tabs.',
                'In the qualification detail, work the "Ready to Issue" tab: tick students, optionally set the email checkbox, and click the issue button to generate Testamur + Record of Results.',
                'Open the "Autocert Queue" tab and click "Process Queue", "Retry", or "Retry all failed" to handle automatic issuance entries.',
                'Click "Run Global Scan" (or "Scan This Qual" on the queue tab) to catch historical completers and add them to the pending queue.',
                'Click "Refresh All Stats" after recording new results to update the cached funnel numbers.',
            ],
            'features' => [
                ['title' => 'See who is ready', 'desc' => 'Counts of enrolled, complete, issued and pending students show instantly who has finished but is still waiting on a certificate.'],
                ['title' => 'Finds every finisher', 'desc' => 'It spots finished students from both course results and prior-skills records (RPL, meaning credit for skills students already have), so no one is missed.'],
                ['title' => 'Waits for the student ID', 'desc' => 'When issuing in bulk, it skips anyone without a recorded USI (the student\'s unique ID number) and issues their certificate automatically once the ID is added.'],
            ],
        ],
        'soa_issue.php' => [
            'how' => [
                'In Step 1, narrow the student list with the cascading filters — Parent category, then Sub-category (shows only children of the chosen parent), then Course — or just start typing in the student search box. Choosing a Parent category also auto-fills the Step 3 Qualification Code and Name, because the parent categories are named after the qualification they deliver.',
                'Select the student and confirm their USI status on the info card.',
                'In the Step 2 eligible units table, use the search box, Qualification, Compliance, and Outcome filters, then tick the competency-achieved units to include. Each unit appears once even if the student completed it across several semesters — the latest completed date is kept — and the Unit Code column shows the national unit code read from the course.',
                'Click a chip under "Suggested SOA Groups" to auto-select a qualification\'s units and auto-fill the Step 3 code and name, or use "Select all compliant".',
                'In Step 3, set the Document Type (Part of Qualification, Skill Set, or Standalone), check the Qualification Code and Name, and add any internal notes.',
                'Review the selection and compliance summary, then click "Generate SOA" to produce the single PDF listing all selected units.',
            ],
            'features' => [
                ['title' => 'Fills itself in', 'desc' => 'The steps recognise the student\'s qualification, tick the right units and fill in the details, leaving you to just review and generate.'],
                ['title' => 'One row per unit', 'desc' => 'When a unit was completed in more than one semester, only the most recent competent result is shown, so the same unit is never listed twice on a Statement of Attainment.'],
                ['title' => 'Reads the real unit code', 'desc' => 'The national unit code comes from the course itself (its ID number or the code at the start of its name), so the document shows the recognised code, not a semester label.'],
                ['title' => 'Checks each unit', 'desc' => 'Green, amber and red badges flag problems on each unit and stop you generating a document that breaks the rules.'],
            ],
        ],
        'rpl.php' => [
            'how' => [
                'Click the "RPL Applications (Standard 1.6)", "Credit Transfers (Standard 1.7)", or "All Records" tab to switch the register view.',
                'Click "Add RPL Application" or "Add Credit Transfer" (the button follows the active tab) to record a new decision.',
                'Scan the table\'s Decision column badges (Approved, Partially Approved, Not Approved, Pending) to find records needing action.',
                'Check the "Student Notified" column to confirm the outcome was communicated, and on the Credit tab check the "USI Verified" column.',
                'Click "Edit" on any row to update the assessor, evidence, decision, or notification details.',
                'Review the top stat cards for Pending Decision and Not Approved counts to track outstanding assessments.',
            ],
            'features' => [
                ['title' => 'Two clear registers', 'desc' => 'Separate tabs for prior skills and credit transfers keep each type of evidence organised and easy to find.'],
                ['title' => 'Checks the student ID', 'desc' => 'The Verified tag records that the student\'s ID and past results were checked before you granted any credit.'],
                ['title' => 'Shows if the student was told', 'desc' => 'A column marks whether each finished decision was sent to the student in writing, so nothing is forgotten.'],
            ],
        ],
        'mycerts.php' => [
            'how' => [
                'Review the stat cards at the top to see your total certificates and a count for each certificate type held.',
                'If certificates span multiple years, click a year tab (or "All Years") to filter the portfolio.',
                'Scroll to the relevant section grouped by type (Testamur, Record of Results, Statement of Attainment, Completion) to find a certificate.',
                'On a Statement of Attainment card, click the "units of competency" link to expand and view the listed units.',
                'Click "Download PDF" on a certificate card to save your certificate, or "Verify" to open its public verification page.',
            ],
            'features' => [
                ['title' => 'Sorted by year', 'desc' => 'Your certificates are grouped by type and year so you can find a specific one quickly.'],
                ['title' => 'Easy to verify', 'desc' => 'The "Verify" link opens a public check page, so an employer can confirm your certificate is genuine without calling the college.'],
                ['title' => 'See the units', 'desc' => 'A Statement of Attainment can be opened up to show every unit it covers, giving you a full record.'],
            ],
        ],
        'compliance_health.php' => [
            'how' => [
                'Read the Audit Readiness ring at the top to see your overall percentage score and the \'On track\', \'Needs attention\' or \'Action required\' verdict.',
                'Scan the coloured chips beneath the verdict to see how many key checks are \'clear\', \'to watch\' or \'need action\'.',
                'Work through the five Quality-Area cards and read each row\'s count and \'Clear\', \'Watch\' or \'Action\' pill.',
                'Read the plain-English meaning line under any red or amber row to understand the exact audit exposure.',
                'Click the fix link on a flagged row (for example \'Open validation schedule\' or \'Verify student USIs\') to jump straight to the page that resolves it.',
                'Reload the page after making fixes to recompute every metric and confirm the score has improved.',
            ],
            'features' => [
                ['title' => 'Single readiness score', 'desc' => 'One live percentage tells you at a glance how ready you are for an audit, without opening every other page.'],
                ['title' => 'Direct fix links', 'desc' => 'Every flagged item links straight to the page that clears it, so you never have to guess where to go.'],
                ['title' => 'Safe to open anytime', 'desc' => 'This page only reads your data and never changes it, so you can safely refresh it even during an audit.'],
            ],
        ],
        'trainers.php' => [
            'how' => [
                'If the \'Moodle teacher(s) have no RTO compliance profile\' banner appears, click \'Import Moodle Teachers as RTO Trainer Profiles\' to create their register entries.',
                'Review the Quick Statistics cards (Total Trainer Profiles, TAE Current, TAE Expiring in 30 Days, TAE Expired, Missing TAE Credential) for a workforce snapshot.',
                'Use the \'Filter by status\' dropdown to narrow the list to Current, Expiring, Expired or \'Credential Policy Approved\' trainers.',
                'Click the sortable \'Trainer Name\' column header to order the register A-Z or Z-A.',
                'Check both the \'Status under TGA\' and \'Status under Credential Policy\' columns before any staffing decision, and hover the info icon to see why a status was calculated.',
                'Click the \'Edit\' action button on a row to update that trainer\'s credentials or delete the profile.',
            ],
            'features' => [
                ['title' => 'One-click import', 'desc' => 'The page spots teachers with no compliance profile and adds them together, so no trainer is left off the register.'],
                ['title' => 'Two status views', 'desc' => 'It shows both the official credential status and your own policy approval side by side for each trainer.'],
                ['title' => 'Explains the status', 'desc' => 'An info tooltip explains why each credential status was given, saving you time if you need to justify it.'],
            ],
        ],
        'workforce_management.php' => [
            'how' => [
                'In \'Step 1 — Core Workforce Inputs\', enter your number of active trainers/assessors and total enrolled students, then choose a \'Primary delivery mode\' to set the benchmark ratio.',
                'In \'Step 2 — Assessment Load Calculator\', enter qualifications delivered, assessments per qualification, hours per assessment, delivery weeks and trainer weekly capacity.',
                'In \'Step 3 — Unit-to-Trainer Assignment Checker\', list one unit per row as \'UNIT CODE | Trainer Name\', leaving the trainer blank to mark a coverage gap.',
                'Read the live result card for your ratio, benchmark capacity, utilisation and \'Sufficient Capacity\', \'Near Capacity\' or \'Over Benchmark\' verdict.',
                'Review the red, amber and green alert banners and the Trainer Load Breakdown table for overload or unassigned-unit warnings.',
                'Copy the DRAFT \'Workforce Planning Summary\' into your own workforce plan or TAS Section 6 as a starting point — then confirm the figures and verify each trainer\'s vocational competency in the Trainer & Assessor Register before relying on it.',
            ],
            'features' => [
                ['title' => 'Live staffing calculator', 'desc' => 'It works out whether your staffing is enough as you type, using planning benchmarks you can adjust, with no page reload.'],
                ['title' => 'Spots unit gaps', 'desc' => 'It flags any unit with no trainer named, so you catch coverage gaps early and can confirm the details in your trainer register.'],
                ['title' => 'Draft planning summary', 'desc' => 'It turns your inputs into a clearly-labelled draft planning worksheet that states on its face it is not audit evidence by itself.'],
            ],
        ],
        'validation.php' => [
            'how' => [
                'Click \'Schedule Validation\' in the header to plan a new risk-based validation event.',
                'Use the \'Validation Schedule\' tab to see upcoming events with their Scheduled Date, Risk Level and assigned Validator, and click \'Manage\' on any row to update it.',
                'Open the \'Completed Events\' tab to review Sample Size, Findings and Next Due dates, watching for the red \'OVERDUE\' and amber \'DUE SOON\' badges.',
                'Scroll to the \'Coverage Gaps\' panel to spot on-scope products with no completed validation or a lapsed cycle.',
                'Switch to the \'Validators\' tab to confirm each validator\'s 3A/3B role type and TAE credential before assigning them.',
                'Click \'View Report\' on a row to open its linked external validation report evidence.',
            ],
            'features' => [
                ['title' => 'Warns when due', 'desc' => 'Overdue and Due Soon tags on the next due dates help you keep every assessment tool checked on time.'],
                ['title' => 'Finds the gaps', 'desc' => 'It compares your active courses against completed checks to point out any course that has no current validation.'],
                ['title' => 'Checks the validators', 'desc' => 'It records each validator\'s role and qualifications so you only assign people who are eligible to take part.'],
            ],
        ],
        'surveys.php' => [
            'how' => [
                'Review the Quick Statistics cards for surveys sent, responses received, \'Awaiting Response\' and Learner/Employer Response Rate for the current year.',
                'In the AQTF Learner Questionnaire card, click \'Send Survey\' to issue the LQ, or \'View Responses\' to inspect returns.',
                'In the AQTF Employer Questionnaire card, click \'Send Survey\' to issue the EQ to employers.',
                'Once at least one response is completed, click the \'Run AI Analysis\' button and confirm the credit spend to analyse that survey type.',
                'Click \'View Summary Report\' to open the consolidated Quality Indicator report for AQTF submission.',
                'Use the standalone \'AI Survey Analysis\' button to switch survey type or year before running an analysis.',
            ],
            'features' => [
                ['title' => 'One-click analysis', 'desc' => 'You can run AI analysis on the replies straight from the survey card with a single confirmed click.'],
                ['title' => 'Live response rates', 'desc' => 'The page shows learner and employer response rates in colour against the 50% guide, so you can see health at a glance.'],
                ['title' => 'Standard forms', 'desc' => 'The page uses the official learner and employer questionnaires, so replies line up with the required measures.'],
            ],
        ],
        'nat_validate.php' => [
            'how' => [
                'Read the hero banner to see whether records are \'Ready to submit\' or how many \'errors to fix\' remain before export.',
                'Check the four stat tiles for Students checked, Enrolments checked, Errors and Warnings totals.',
                'Expand each collapsible finding category card (error cards open automatically) to read the ERROR and WARNING rows with their Client ID/Unit, Field and message.',
                'Fix each flagged record in the student or enrolment source data, prioritising ERROR-severity items that NCVER will reject.',
                'Click \'Export all findings (CSV)\' to download the complete finding set for offline remediation.',
                'Click \'Re-run validation\' after making corrections to confirm the errors are cleared.',
            ],
            'features' => [
                ['title' => 'Checks before you send', 'desc' => 'It checks every student and enrolment against the national reporting rules so your quarterly national report files (called NAT files) aren\'t rejected.'],
                ['title' => 'Sorts errors first', 'desc' => 'It separates blocking errors from minor warnings and lists errors first, so you fix the ones that stop submission before the rest.'],
                ['title' => 'Safe download', 'desc' => 'It saves the full findings list to a spreadsheet without changing any data, giving you a safe worklist.'],
            ],
        ],
        'data_import.php' => [
            'how' => [
                'Under \'How should students be matched to their Moodle accounts?\', select either the \'By email address\' or \'By student number\' radio button.',
                'Click \'Select NAT files to upload\' and Ctrl/Cmd-click every .txt file from the same SMS export batch (NAT00080, NAT00085, NAT00120, etc.) so they import together.',
                'Click \'Upload & Import\' to stage the files, confirm the detected format, and write the records into your student profiles and results register.',
                'Optionally click \'Verify NAT Data\' first to run a read-only check of which students in your files already exist in your system.',
                'In the import history table, click a row\'s \'View\' button to open the import detail and review the flagged records count.',
                'Use the Prev/Next pagination links beneath the history table to reopen older imports.',
            ],
            'features' => [
                ['title' => 'Upload all at once', 'desc' => 'You can upload a whole set of national report files (NAT files) together instead of one at a time, keeping each student\'s details and results matched up.'],
                ['title' => 'Won\'t touch accounts', 'desc' => 'The import only fills in student profiles and results inside the plugin and never creates or deletes any online course accounts.'],
                ['title' => 'Flags bad rows', 'desc' => 'The history table shows a badge on records that have a problem so you can fix them before they reach the regulator.'],
            ],
        ],
        'natexport.php' => [
            'how' => [
                'Choose the reporting period from the Collection Period year dropdown, which auto-loads that year\'s data.',
                'Click \'Validate\' to run the AVETMISS checks and read the Validation Errors, Validation Warnings and Record Counts panels.',
                'Resolve any listed errors — for example, follow a blank-qualification-code warning\'s \'review these enrolments\' link — before proceeding.',
                'Click \'Generate\' to build all ten NAT files (NAT00010 through NAT00130) and download them as a single timestamped ZIP.',
                'Copy your state portal reference IDs from the \'Portal Reference IDs\' panel and use each \'Go to portal\' link to upload the ZIP.',
                'Re-download any prior submission from the \'Recent Exports\' table using its \'Download\' button.',
            ],
            'features' => [
                ['title' => 'Checks before you send', 'desc' => 'It checks your data for errors before building the report file, so you do not send bad national reporting data (the AVETMISS standard) to the authorities.'],
                ['title' => 'Portal shortcuts', 'desc' => 'It shows your saved portal reference numbers and direct links right on the page, so you do not have to hunt for them while submitting.'],
                ['title' => 'Keeps a record of exports', 'desc' => 'Every report file you make is listed with its year, record count and check status, and you can download it again any time.'],
            ],
        ],
        'reconcile.php' => [
            'how' => [
                'From the \'Select NAT Import\' dropdown pick the most recent import for the collection year, heeding any duplicate-import warning.',
                'Optionally paste one or more NAT Client IDs into the \'Trace students\' box for a per-student unit-by-unit coverage panel.',
                'Optionally attach a Backup File CSV to enable RESTORE detection, or a Qualification Mapping CSV to override automatic discovery.',
                'Click \'Run Reconciliation Analysis\' to compare each student\'s NAT unit codes against their actual Moodle enrolments.',
                'Review the KEEP / ADD / REMOVE / POST-IMPORT / REVIEW classifications, then download the relevant CSV report.',
                'Use \'Download All as ZIP\' to grab every CSV report at once.',
            ],
            'features' => [
                ['title' => 'Safe to run', 'desc' => 'The check only looks at your data and never changes anything, so you can review problems without risk of deleting records.'],
                ['title' => 'Protects new enrolments', 'desc' => 'Enrolments added after the import are marked and kept safe, so valid recent records are never removed.'],
                ['title' => 'Clear reports', 'desc' => 'The result files sort each issue by likely cause so you know exactly what to check and fix.'],
            ],
        ],
        'qi_report.php' => [
            'how' => [
                'Select the reporting year from the dropdown to reload all figures for that period.',
                'Read the Learner Questionnaire (LQ) cards for Completed Responses, the Learner Engagement average and the response rate.',
                'Read the Employer Questionnaire (EQ) section for the Employer Satisfaction indicator score and its scale averages.',
                'Check the \'Competency Completion Rate\' panel for Total Enrolments, Competencies Achieved and the Completion Rate percentage.',
                'Click \'View Detailed Responses\' under either questionnaire to drill into individual records.',
                'Click \'Export QI Report (CSV)\' to produce the file supporting your Annual Declaration of Compliance.',
            ],
            'features' => [
                ['title' => 'Scores worked out for you', 'desc' => 'It calculates the survey averages automatically from completed learner and employer answers, with no manual adding up.'],
                ['title' => 'Shows response rates', 'desc' => 'Each measure shows how many people answered out of those invited, so you can prove good survey coverage.'],
                ['title' => 'Ready-to-file export', 'desc' => 'The download packages the results ready for your yearly compliance declaration.'],
            ],
        ],
        'deadlines.php' => [
            'how' => [
                'Scan the pending-deadlines table, using the colour-coded \'Days Left\' badge (Overdue, amber, or green) to spot what needs attention first.',
                'Under \'Add New Deadline\', enter a Title and optional Description and choose a Type (TVA Submission, Quality Indicator Data, Annual Declaration, Audit or Other).',
                'Set the Due Date and adjust \'Reminder Days Before\' (defaults to 30) to control when you are alerted.',
                'Tick \'Recurring\' and pick a Repeat Period (Yearly, Quarterly or Monthly) for obligations that repeat, then click \'Save Deadline\'.',
                'Click a row\'s \'Complete\' button to mark a deadline done — recurring items automatically regenerate the next occurrence.',
                'Click \'Delete\' and confirm to remove a deadline that no longer applies.',
            ],
            'features' => [
                ['title' => 'Repeating deadlines', 'desc' => 'When you complete a repeating deadline, the next one is created for you automatically, so yearly tasks are never forgotten.'],
                ['title' => 'Colour warnings', 'desc' => 'The days-left badges turn amber then red as a due date gets closer, so urgent tasks stand out instantly.'],
                ['title' => 'Ready-made categories', 'desc' => 'Built-in deadline types for your common reporting tasks keep every obligation neatly grouped in one place.'],
            ],
        ],
        'alerts.php' => [
            'how' => [
                'Click \'Run Compliance Scan\' to execute the predictive analysis and generate or update alerts.',
                'Read the summary cards for Overall Status, Risk Score and the Critical/High/Medium/Low severity counts.',
                'Use the All / Critical / High / Medium / Low filter tabs to narrow the alert list to a severity band.',
                'Open an alert card to read its title, description and \'Recommended Action\', and note any days-left or risk percentage.',
                'Click \'Acknowledge\' to mark an active alert as seen while you work on it.',
                'Click \'Resolve\' once the issue is fixed, or \'Dismiss\' to clear alerts that don\'t apply.',
            ],
            'features' => [
                ['title' => 'One-click check', 'desc' => 'The "Run Compliance Scan" button looks through your data and points out problems early, before an auditor would find them.'],
                ['title' => 'See urgent first', 'desc' => 'Each alert shows how serious it is, so you can fix the most important ones before the rest.'],
                ['title' => 'Actions are saved', 'desc' => 'When you acknowledge, resolve or dismiss an alert, the system records it so you have proof the issue was handled.'],
            ],
        ],
        'auditlog.php' => [
            'how' => [
                'View the chronological table sorted newest-first across the Time, User, Component, Action and Details columns.',
                'Read the User column to see who performed each action, or \'System\' for automated events.',
                'Use the Component badge to identify which module an entry came from.',
                'Read the Action and Details columns to see exactly what changed, such as generated NAT files or record counts.',
                'Use the paging bar beneath the table to move through history 50 entries at a time.',
            ],
            'features' => [
                ['title' => 'Full record of changes', 'desc' => 'Every certificate issued, report file exported, and trainer change is saved with the user, time, and computer address.'],
                ['title' => 'Who did what', 'desc' => 'Each entry shows the person who did the action, so you can answer who did what and when in one place.'],
                ['title' => 'Easy to browse', 'desc' => 'The list shows 50 entries per page so a long history stays easy to read without losing older records.'],
            ],
        ],
        'cert_test.php' => [
            'how' => [
                'Choose the certificate layout from the \'Certificate type\' dropdown, which shows whether each option has an active approved template or a default fallback.',
                'Set the \'Orientation\' dropdown to Auto, Portrait or Landscape to override the default page orientation.',
                'Type a name into the \'Student name\' field, or leave it blank to use the \'Jane Citizen\' placeholder.',
                'Tick \'Send a copy to my email\' if you also want the sample PDF mailed to your admin address.',
                'Click \'Generate test certificate\' to open the sample PDF in a new tab for layout QA.',
            ],
            'features' => [
                ['title' => 'No real data used', 'desc' => 'It makes a sample certificate without saving any record, so you can check the layout safely.'],
                ['title' => 'Shows template status', 'desc' => 'The Certificate Type list marks each type as active or default, so you know which design a real certificate would use.'],
                ['title' => 'Safe sample details', 'desc' => 'Test certificates use a fake student ID number (USI is the student ID number) and code, so no real details are used.'],
            ],
        ],
        'cert_template_edit.php' => [
            'how' => [
                'Add fields to the A4 canvas by clicking or dragging chips from the left \'Fields\' panel, using \'Search fields…\' to find student, qualification, compliance or mandatory-phrase elements.',
                'Select any placed element and adjust its Position & Size, Typography and Appearance in the right-hand \'Field properties\' panel.',
                'Use the top toolbar to Save draft, one-click \'Save & Approve\' (saves then runs the ASQA validation and submits if it passes), or Preview. The live preview of the finished PDF sits in the right column beside the canvas.',
                'Optionally click \'Floating properties\' in the toolbar to lift the Field properties into a draggable floating toolbar (drag it by its header) so the canvas and preview get the full width; click it again to dock the panel back. Your choice is remembered.',
                'Open the \'Branding\' and \'Page Design\' panels to upload the RTO logo, signature, STA logo, organisation seal and background and set orientation.',
                'Watch the \'ASQA validator\' panel and click any inline \'Fix\' button to auto-add a missing required field.',
                'For a draft click \'Submit for approval\', then once approved click \'Activate\' to make it the live template.',
            ],
            'features' => [
                ['title' => 'Built-in checker', 'desc' => 'The checker warns you if a required field is missing or not allowed, and offers a one-click fix so your certificate follows the rules.'],
                ['title' => 'True preview beside the canvas', 'desc' => 'The live preview builds the certificate the same way students receive it and sits next to the canvas, so what you see is exactly what they get.'],
                ['title' => 'Floating settings toolbar', 'desc' => 'Turn the field settings into a movable floating toolbar so the canvas and preview fill the screen while you design — like a word processor toolbar.'],
                ['title' => 'One-click Save & Approve', 'desc' => 'A single button saves the draft and runs the compliance checks, submitting it for approval when it passes.'],
            ],
        ],
        'issue_certificate.php' => [
            'how' => [
                'Select the recipient from the searchable \'Student\' autocomplete field, which filters the user list as you type.',
                'Choose the \'Certificate type\' and \'Audience\' that pins the correct testamur/SoA/RoR design onto this issuance.',
                'Enter the \'Qualification Code\' and \'Qualification Name\' (required for Testamur and Record of Results), or for a Statement of Attainment list units one per line in the \'Units of Competency\' box.',
                'Set the \'Issue Date\' and optional \'Expiry Date\', and leave \'Notify Student\' ticked to send a notification.',
                'Review the credit cost panel and \'Certificate Issuance Rules\', then click \'Issue Certificate\' to validate USI/AVETMISS requirements and create the record.',
            ],
            'features' => [
                ['title' => 'Checks before issuing', 'desc' => 'The page will not issue a certificate until the student\'s ID number, required details, and unit results are all in place.'],
                ['title' => 'Saved as issued', 'desc' => 'It saves a copy of the student\'s details and the template as they were, so later edits never change a certificate already given out.'],
                ['title' => 'Online check', 'desc' => 'Each certificate is added to a verification list, so its QR code can still be scanned even away from this site.'],
            ],
        ],
        'email_cert.php' => [
            'how' => [
                'Open the certificate from the Certificates list and trigger the \'Email\' action for the student.',
                'Confirm the send on the dialog, which names the recipient, their email, the certificate type and number.',
                'Note any USI advisory: for testamur or statement certs with an unverified USI a Clause 12 reminder appears, but the send still proceeds.',
                'Rely on the already-emailed guard, which stops a second send and warns if the PDF was previously dispatched.',
                'Return to the Certificates list where the row shows the \'Emailed\' badge once the send succeeds.',
            ],
            'features' => [
                ['title' => 'Send in one click', 'desc' => 'It creates the certificate and emails it to the student in a single step, with no download or attaching to do.'],
                ['title' => 'Stops double sends', 'desc' => 'It blocks sending a certificate that has already been sent, so students don\'t get confusing duplicates.'],
                ['title' => 'Reminds you to check ID', 'desc' => 'It shows a reminder when the student\'s ID number is not verified so you can follow up, while still letting the email go.'],
            ],
        ],
        'generate_course_certs.php' => [
            'how' => [
                'Type in the \'Type to search courses or categories\' box, select a course, and click \'Go\' (or click \'Select a Qualification\' to switch generators).',
                'Review the \'Smart Detection\' banner showing whether the course is nationally recognised and which certificate type(s) will be auto-issued.',
                'Use the \'Filter by Group\' and \'Filter by Student\' selectors with \'Apply Filter\', or the \'Switch Archive Course\' dropdown.',
                'Choose the generation mode radio: \'Issue Missing Certificates\' or \'Force Regenerate\'.',
                'Tick students with the checkboxes or use \'Select All Needing Certs\', and keep \'Email certificates to students\' ticked if desired.',
                'Click \'Generate / Regenerate Selected\', review the credit-cost modal, then click \'Confirm & Generate\'.',
            ],
            'features' => [
                ['title' => 'Picks the right type', 'desc' => 'The system works out which certificate each student should get, so you don\'t have to choose the type yourself.'],
                ['title' => 'Shows the cost first', 'desc' => 'Before you generate, a popup shows the total cost and your balance, and stops the run if you don\'t have enough credits.'],
                ['title' => 'Clean reissue record', 'desc' => 'When you remake a certificate, the old one is cancelled and linked to the new one so there is a clear history of the change.'],
            ],
        ],
        'generate_qual_certs.php' => [
            'how' => [
                'Choose an active qualification from the \'Select a qualification\' dropdown and click \'Go\' (or use \'Generate by Unit\' for the single-course generator).',
                'Review the qualification summary banner showing linked unit-course count, total units, and that Testamur + Record of Results will be issued.',
                'Check the student table listing everyone who completed every unit, noting any SUSPENDED badges.',
                'Tick \'Force regenerate (void existing)\' to replace existing certs, and leave \'Notify students\' ticked.',
                'Select students using the per-row checkboxes or the \'Select all\' / \'None\' links.',
                'Click \'Generate Testamur + Record of Results\' and confirm to issue both certificates for each selected student.',
            ],
            'features' => [
                ['title' => 'Genuine finishers only', 'desc' => 'The list only shows students who have actually completed every unit, so you never certify someone by mistake.'],
                ['title' => 'Both documents together', 'desc' => 'Each student gets their full certificate (the Testamur) and their Record of Results made together in one go.'],
                ['title' => 'Long-list warning', 'desc' => 'If a student\'s list of units is too long for one page, you get a warning so you can adjust the design first.'],
            ],
        ],
        'qualbuilder_edit.php' => [
            'how' => [
                'Set the Product Type dropdown to Qualification, Skill Set or Single Unit, then type the national code (e.g. BSB30120) into the Code field.',
                'Click Load from TGA to auto-fill the qualification name, AQF level and packaging rules from training.gov.au.',
                'Choose the qualification root in the Moodle Category picker, then select the matching Semester / Intake to filter linkable courses.',
                'Tick the required core and elective units in the unit builder, using the Search box and All/Core only/Electives only filters.',
                'Click Map All Courses on the green TGA banner to auto-match every selected unit to a Moodle course.',
                'Click Save Qualification, then open Full Validation Report to confirm the build meets packaging rules.',
            ],
            'features' => [
                ['title' => 'Auto-fill from the register', 'desc' => 'Loading the code from the national training register fills in the units and rules for you, so you never type them out.'],
                ['title' => 'Live compliance check', 'desc' => 'The panel recounts your required and optional units as you tick them, so any gaps show up before you save.'],
                ['title' => 'Match every course at once', 'desc' => 'One click links every unit to its course, so student enrolments feed into your national reporting data without extra work.'],
            ],
        ],
        'qualbuilder_unit.php' => [
            'how' => [
                'Type the Unit Code and Unit Name, letting the nominal-hours auto-fill populate hours from the reference table.',
                'Set the Unit Type to Core, Elective or Imported, and pick an Elective Group (A-D) when the unit belongs to a grouped bank.',
                'Enter Nominal Hours, Credit Points and Sequence Order to control roll-up totals and display order.',
                'Under Course Linking, choose the Linked Course from courses in the qualification\'s category.',
                'Click Save changes to store the unit, or Cancel to return without altering it.',
            ],
            'features' => [
                ['title' => 'Fills in the hours', 'desc' => 'Typing the unit code fills in the standard learning hours for you, so the numbers stay correct and consistent.'],
                ['title' => 'Groups electives', 'desc' => 'Putting electives into groups lets the system check that students take the right mix of units for the qualification.'],
                ['title' => 'Links to the course', 'desc' => 'Linking each unit to its course means student enrolments are picked up for national reporting without extra typing.'],
            ],
        ],
        'qualbuilder_courses.php' => [
            'how' => [
                'Confirm the blue category notice shows the correct qualification category, since it scopes which courses appear.',
                'Click Auto-Detect Courses to bulk-match unlinked units to Moodle courses by matching unit codes against course shortnames.',
                'For any remaining gaps, pick the correct course from each unit\'s Linked Course dropdown.',
                'Click Save Course Links and check that the linked count (e.g. 12 / 12 units linked) turns green.',
                'In the Archive Courses section, select a prior-semester course, add a label such as Archive S2, and click Link.',
                'Use the Remove button beside any archive entry to unlink a course added in error.',
            ],
            'features' => [
                ['title' => 'Auto matching', 'desc' => 'One click matches each unit to its course by code, so you don\'t have to pick every course by hand.'],
                ['title' => 'Progress counter', 'desc' => 'A colour-coded counter shows how many units are linked, so you can see at a glance whether any are left.'],
                ['title' => 'Keep old semesters', 'desc' => 'Linking past courses means students from earlier intakes still get correct records.'],
            ],
        ],
        'qualbuilder_validate.php' => [
            'how' => [
                'Open the page from the product\'s Full Validation Report link, which runs the packaging-rules check on load.',
                'Read the top banner to see whether the qualification is Compliant or Non-Compliant.',
                'Check the source badge to confirm whether rules were sourced live from TGA or fall back to stored values.',
                'Work down the Validation Checks table, comparing each Expected count against the Actual with its tick or cross.',
                'Resolve any items listed under Validation Errors or Warnings, then click Back to Product to adjust units.',
                'Re-open the report after editing to refresh the stored pass/fail result and validation date.',
            ],
            'features' => [
                ['title' => 'Automatic rules check', 'desc' => 'The page checks all the unit rules in one go, instead of you comparing them by hand.'],
                ['title' => 'Uses current rules', 'desc' => 'A badge shows when the rules came straight from the national training website, so the check uses up-to-date requirements.'],
                ['title' => 'Saved results', 'desc' => 'Each check is saved with a pass or fail and a time stamp, giving you dated proof the qualification was checked.'],
            ],
        ],
        'tas_edit.php' => [
            'how' => [
                'In Section 1, enter the Qualification Code, Qualification Name and Training.gov.au Link, noting the release in Revision Notes.',
                'In Section 2, use the Smart Cohort Builder: select the AQF Qualification Level, tick applicable cohorts, and click Apply to Section 2 to auto-fill cohort, entry and LLN fields.',
                'In Section 4, enter a Delivery Start Date and click Generate Delivery Plan to calculate volume of learning, weekly schedule and hour breakdown.',
                'In Section 5, tick assessment methods from the checklist and paste your Assessment Mapping Document Link.',
                'Open Manage Industry Consultations in Section 3 to attach evidence, then set Document Status, Version Number and Next Review Date in Section 9.',
                'Click Update TAS (or Create TAS) to save; the completeness score recalculates across all 9 sections.',
            ],
            'features' => [
                ['title' => 'Cohort helper', 'desc' => 'Choosing a level and student groups drafts the target-group, entry, and skills text for you, saving hours of writing.'],
                ['title' => 'Schedule builder', 'desc' => 'It works out the required amount of learning and a week-by-week schedule that skips public holidays automatically.'],
                ['title' => 'Progress score', 'desc' => 'A percentage across the nine sections shows which parts of the plan still need attention before an audit.'],
            ],
        ],
        'tas_consultation.php' => [
            'how' => [
                'Click Download Industry Consultation Log Template (DOCX) to get a Word log pre-filled with the qualification code and method options.',
                'Click Log New Consultation Record, then enter the Industry Representative Name, Organisation, Role and Consultation Method.',
                'Set the Consultation Date and use the quick-add checkboxes or the AI: Generate buttons to draft Key Feedback, Impact on Training Delivery and Impact on Assessment Design.',
                'Use Upload Evidence Document to attach the signed log or minutes, then click Add Consultation Record.',
                'Check the compliance badge (OK, DUE, OVERDUE or NO EVIDENCE) to confirm the latest consultation is within 12 months.',
                'Review the Auto-Generated TAS Narrative, which is written back into the TAS Industry Consultation Evidence field.',
            ],
            'features' => [
                ['title' => 'Ready-filled log', 'desc' => 'The Word log downloads already filled with the qualification code and common consultation methods, ready for your meetings.'],
                ['title' => 'Writes it up for you', 'desc' => 'Your consultation records are gathered into the industry evidence text for your training plan automatically.'],
                ['title' => 'Keeps it current', 'desc' => 'A simple badge based on a 12-month rule tells you when your consultation evidence is out of date and needs refreshing.'],
            ],
        ],
        'trainer_dashboard.php' => [
            'how' => [
                'Review the My Current Classes table to see each course you teach with its short name, full name and enrolled student count, then click View.',
                'Scroll to My Students and click the Name column header to sort learners A-Z or Z-A, checking each row\'s USI and ID number.',
                'Click a student\'s name to open their Moodle profile to verify or follow up on their details.',
                'In My Currency Profile, click Open My Currency Record to review and update your own TAE, vocational currency and PD evidence.',
                'In the validation events panel, click the open button to view the validation activities you are assigned to.',
            ],
            'features' => [
                ['title' => 'Just your view', 'desc' => 'You only see your own classes, students, and records, not management-only areas or other trainers\' data.'],
                ['title' => 'ID status at a glance', 'desc' => 'Each student\'s ID number status shows right in your list, so you spot missing ones before certificates are issued.'],
                ['title' => 'Manage your own records', 'desc' => 'A direct link lets you keep your own qualification and development records current without waiting on an administrator.'],
            ],
        ],
        'supervision.php' => [
            'how' => [
                'Read the Working Towards TAE deadline alerts at the top and click any trainer flagged OVERDUE or DUE SOON.',
                'Use the All Logs, Pending Validation, Validated and Overdue Actions filter tabs to narrow the list.',
                'Scan the log table\'s Date, Trainer, Supervisor, Type, Qualification, Duration and Status columns.',
                'Click View on a row to open the full supervision record and its validation controls.',
                'Click Add Supervision Log to record an observation, feedback, assessment review, QA check or mentoring session.',
                'Open the ASQA Trainer Credential Policy link to confirm which roles require documented supervision.',
            ],
            'features' => [
                ['title' => 'Deadline warnings', 'desc' => 'Trainers still earning their teaching qualification are flagged when their deadline is close or overdue, so you can act in time.'],
                ['title' => 'Quick filters', 'desc' => 'One-click buttons show only pending, validated or overdue logs, saving you time finding the ones that need attention.'],
                ['title' => 'Supervision record', 'desc' => 'Keeps a dated, checked log of supervision, which is the proof you need for trainers being supervised.'],
            ],
        ],
        'trainer_edit.php' => [
            'how' => [
                'For a new record, choose the person from the Trainer Name dropdown, then pick their qualification in the TAE Credential field.',
                'If you select Working Towards TAE, set the commencement date so the system computes the 2-year completion deadline.',
                'Tick the applicable Credential Role checkboxes (1A-3B) and the Vocational Competency Evidence checkboxes.',
                'Complete the WWCC / Blue Card and National Police Certificate sections and record LLN Capability, VET Currency Date and Approved Delivery Scope.',
                'Tick the Manager Sign-off checkbox to confirm credentials are verified, then click Save changes.',
                'After saving, use Manage Vocational Competency Activities and Manage Industry Currency Activities to attach evidence.',
            ],
            'features' => [
                ['title' => 'Works out deadlines', 'desc' => 'It calculates the two-year deadline for the teaching qualification from the start date, so you don\'t do the date maths.'],
                ['title' => 'Sign-off safeguards', 'desc' => 'It blocks manager approval when a trainer has no qualification or a missed working-towards deadline, preventing unsound approvals.'],
                ['title' => 'One full record', 'desc' => 'It keeps the teaching qualification, work evidence, background checks, and what they can deliver in one record, ready for audit.'],
            ],
        ],
        'trainer_currency.php' => [
            'how' => [
                'Click Add Currency Activity to open the activity form for the selected trainer.',
                'Choose an Activity Type such as Ongoing industry employment or Industry consulting, then enter the Activity Title/Role and Organisation.',
                'Set the Start Date and tick Still Ongoing, or enter an End Date, and record approximate Hours per Week.',
                'Choose an Evidence Type and use Upload Evidence to attach the document, then click Add Activity.',
                'Review the saved list, using Edit or Delete on any row, and check the Currency Summary counts.',
            ],
            'features' => [
                ['title' => 'Log each activity', 'desc' => 'Records jobs, consulting, memberships and conferences as separate dated entries, building stronger proof that a trainer keeps up with their industry.'],
                ['title' => 'Date kept current', 'desc' => 'The trainer\'s currency date updates from their most recent activity on its own, so their profile is always up to date.'],
                ['title' => 'Proof attached', 'desc' => 'Uploaded evidence shows which activities have documents attached, giving an auditor a record they can check.'],
            ],
        ],
        'trainer_voccomp.php' => [
            'how' => [
                'Click Add Vocational Competency Activity to open the form for the current trainer.',
                'Select an Activity Type, enter the Activity Title / Context, list Related Qualification(s) codes, and name the Organisation.',
                'Set the Start Date and tick Still Ongoing or add an End Date, and enter Total Hours where applicable.',
                'Optionally click AI Generate Description to draft the Description of Vocational Practice, then review and edit it.',
                'Choose an Evidence Type, attach a file via Upload Evidence, then click Add Activity.',
                'Check the Vocational Competency Summary and add evidence to any activity still marked No evidence attached.',
            ],
            'features' => [
                ['title' => 'Drafts the write-up', 'desc' => 'It can draft the activity description from the details you entered, saving writing time while you stay in control of the wording.'],
                ['title' => 'Links to qualifications', 'desc' => 'It links each activity to specific qualifications, showing that trainers keep their industry skills current for what they teach.'],
                ['title' => 'One tidy record', 'desc' => 'It keeps qualifications, work history and training in one place with attached files, making your records easy to show at audit.'],
            ],
        ],
        'supervision_edit.php' => [
            'how' => [
                'Select the trainer being supervised from the Trainer Supervised dropdown, then choose a fully credentialled trainer in the Supervisor field.',
                'Set the Supervision Date and pick the Supervision Type (Observation, Feedback, Assessment Review, QA Check or Mentoring).',
                'Enter the Qualification Code and Duration, then complete Activities, Feedback Provided and Development Needs.',
                'Under Follow-up Actions, record Action Items, an Actions Due Date and tick Actions Completed, plus any Next Supervision Date.',
                'Click Save changes to store the log.',
                'On an existing record, click Validate as RTO Manager to confirm the log, or Delete to remove it.',
            ],
            'features' => [
                ['title' => 'Qualified supervisors only', 'desc' => 'The supervisor list leaves out anyone still training or with expired credentials, so only fully qualified staff supervise.'],
                ['title' => 'Manager sign-off', 'desc' => 'A one-click manager check stamps who approved the record and when, giving you a clear, accountable sign-off.'],
                ['title' => 'Track follow-ups', 'desc' => 'Due dates and completion ticks on follow-up actions highlight anything overdue, so nothing slips through the cracks.'],
            ],
        ],
        'validation_edit.php' => [
            'how' => [
                'Enter the validation Reference, Product Code, Product Name and Unit Codes, then choose the Validation Type (Initial, Ongoing or Post-assessment).',
                'Set the Risk Level and tick the applicable Risk Factors, adding any Additional Risk Notes.',
                'Set the Scheduled Date and, once held, the Actual Date, and select the Status.',
                'Pick a Lead Validator and any Panel Members, then tick the Validation Methods Used (optionally clicking AI Suggest to draft methodology notes).',
                'In the Independence section, tick the validator-independence confirmation and complete the declaration before marking the event Completed.',
                'Record the Outcome, Rectification Actions, Findings and Report Document URL, tick Link to ADC if applicable, then click Save changes.',
            ],
            'features' => [
                ['title' => 'Sets the next date', 'desc' => 'The risk level you choose sets the next due date on a 2-year or 5-year cycle, keeping your schedule on track.'],
                ['title' => 'Independence check', 'desc' => 'The page will not let you mark a validation finished until the independence declaration is confirmed.'],
                ['title' => 'Trainer-course reminder', 'desc' => 'It spots trainer-training products and shows a reminder about the required independent check, so a common gap is avoided.'],
            ],
        ],
        'validator_edit.php' => [
            'how' => [
                'Enter the validator\'s Full Name, Email and Phone in the register details.',
                'Leave Is Internal ticked for in-house validators, or untick it and complete the Organisation field for external validators.',
                'Select the Role Type (3a Lead Validator or 3b Panel Validator) and record their TAE Credential and TAE Date Achieved.',
                'Complete the Vocational Qualifications, Industry Experience, years, Current Industry Engagement and Specialisations fields.',
                'Enter Validations Led, Validations Participated and Last Validation Date, set Status to Active or Inactive, then click Save changes.',
            ],
            'features' => [
                ['title' => 'Validator list', 'desc' => 'Keeps one central list of your internal and external validators with their qualifications and experience recorded as proof.'],
                ['title' => 'Skills recorded', 'desc' => 'Records each validator\'s teaching qualification, other qualifications and recent industry work so their suitability is easy to defend.'],
                ['title' => 'Ready to reuse', 'desc' => 'Active validators show up automatically in the validation dropdowns, saving you re-typing and making sure only checked people are picked.'],
            ],
        ],
        'student_enrolments.php' => [
            'how' => [
                'If a blue panel with the Moodle-enrolments-found notice appears, review the listed courses and click Import Moodle Enrolments to pull them into AVETMISS records.',
                'Click the blue Add Enrolment button to create a new unit enrolment, or click Edit in a row\'s Actions column to amend an existing one.',
                'In the form set the Unit, Activity start date, Outcome and Enrolment status (plus the Contract slot for QLD state-funded rows) and click Save.',
                'For a finished unit not yet completed, click the green Complete button to stamp outcome 20 (Competency Achieved).',
                'To remove an incorrect record, click the red Delete button and confirm.',
                'Read the success toast, which echoes the persisted outcome and status.',
            ],
            'features' => [
                ['title' => 'Import from Moodle', 'desc' => 'It pulls Moodle course enrolments into per-unit records that meet the national data standard, saving hours of re-typing.'],
                ['title' => 'Valid codes by default', 'desc' => 'Every field is set to a valid national-standard code, so records don\'t fail the checks when submitted to the state.'],
                ['title' => 'Quick complete', 'desc' => 'The green Complete button sets the Competency Achieved outcome and end date in one click.'],
            ],
        ],
        'student_support_input.php' => [
            'how' => [
                'In the Select student card choose the learner from the Student dropdown to load their support history.',
                'In the New Support Record card pick a Record type (mapped to Standards 2.3-2.6) and optionally enter a Category.',
                'Optionally set the LLN level (ACSF) and Risk level dropdowns, then click Auto Fill (AI) to draft compliance-aligned text into the Detail field.',
                'Complete the required Support detail field and any Action taken, and leave Mark as confidential ticked if sensitive.',
                'Set the Outcome status to Open, In progress or Closed, then click Save Support Record.',
                'Confirm the entry appears in the Saved Support Records table below.',
            ],
            'features' => [
                ['title' => 'AI draft help', 'desc' => 'The auto-fill button drafts the support notes for you from your choices, turning minutes of writing into seconds.'],
                ['title' => 'Ready for audit', 'desc' => 'Each record type is matched to the rules an auditor checks, so your notes line up with what they ask for.'],
                ['title' => 'Safe record trail', 'desc' => 'Every note is saved against the student with who wrote it and when, giving you a lasting record.'],
            ],
        ],
        'my_profile.php' => [
            'how' => [
                'Review the enrolled-in-accredited-course panel and the red action-required banner explaining why your AVETMISS details are needed.',
                'Enter your USI, date of birth, sex, and full residential address including suburb, postcode and state.',
                'Complete the demographic dropdowns — Indigenous status, country of birth, language at home, labour force status and highest school level.',
                'If applicable, set the At school and Disability flags to reveal and complete the extra fields.',
                'Click Save profile and check for the green Profile saved confirmation.',
                'If any mandatory field is still flagged, return and fill the highlighted field to clear the warning.',
            ],
            'features' => [
                ['title' => 'Enter your own details', 'desc' => 'You fill in your own details once, which saves staff time and keeps your record accurate from the source.'],
                ['title' => 'Checks for gaps', 'desc' => 'The page checks all required fields are filled before marking your profile finished, so nothing is missed later.'],
                ['title' => 'Protects your ID check', 'desc' => 'The check on your student ID number cannot be faked and resets if you change the number, keeping it correct.'],
            ],
        ],
        'mydocs.php' => [
            'how' => [
                'Open the document portal to see the Certificates and Documents stat tiles at the top.',
                'In the Issued Certificates table, click Download PDF for a testamur or statement, or click Verify to open its public verification page.',
                'Scroll to the Uploaded Documents table to review existing evidence files with their type, uploader and date.',
                'In the Upload Document panel, choose a Document Type (e.g. RPL Decision, USI Verification Letter, Enrolment Agreement) and add optional Notes.',
                'Select a File (max 20 MB) and click Upload Document to store it against the student.',
                'To remove an incorrect file, click its red Delete button and confirm.',
            ],
            'features' => [
                ['title' => 'One place for records', 'desc' => 'Certificates and uploaded documents sit together on one page per student, so any record is easy to find during an audit.'],
                ['title' => 'Labelled documents', 'desc' => 'Document-type labels, such as credit for prior skills or student ID number letters, keep every file sorted and easy to find.'],
                ['title' => 'Students help themselves', 'desc' => 'Students can download their own certificates and documents, while only staff can upload or delete, cutting reissue requests.'],
            ],
        ],
        'marketing_cards.php' => [
            'how' => [
                'Read the compliance status banner at the top, which lists any missing website, handbook or policy URLs.',
                'Review the auto-populated cards for Training Product Information, Support Services, Fees & Refunds and Changes to Training.',
                'Click a card\'s Show Evidence button to open the linked public website, Student Handbook or supporting page.',
                'On the Student Obligations card, click Send Declaration to Students to launch the declaration workflow.',
                'Resolve any red issues flagged in the banner by following its Configure or Set URL in RTO Settings links.',
                'Use the Related pages buttons to jump to Marketing Information, Student Support, Fee Protection or Transitions.',
            ],
            'features' => [
                ['title' => 'Automatic check', 'desc' => 'It flags missing website, handbook, and policy links so you can fix them before an auditor finds them.'],
                ['title' => 'Fills cards for you', 'desc' => 'It pulls course, fee, and support details from your existing records, so the information stays current with no re-typing.'],
                ['title' => 'Quick evidence links', 'desc' => 'The "Show Evidence" buttons open the public course list and handbook to prove the information was really provided.'],
            ],
        ],
        'suitability_bulk.php' => [
            'how' => [
                'Open the Fill Compliance Gaps page (or arrive from the Students list checkbox action to bulk-send to a selection).',
                'Choose a qualification from the Select TAS dropdown, which lists only approved TAS records with entry requirements.',
                'Read the live gap-count line stating how many students have not yet received a Student Suitability Check.',
                'Click the amber Fill Compliance Gaps button and confirm the prompt to email every student with no record.',
                'Wait for the redirect to Students and read the result toast showing how many were sent versus skipped.',
                'Note that already-suitable students are skipped and pending records are refreshed with a new secure link.',
            ],
            'features' => [
                ['title' => 'Finds who is missing', 'desc' => 'It counts and targets exactly the students who still need a suitability check for that qualification.'],
                ['title' => 'Sends to many at once', 'desc' => 'It emails the check links to lots of students in a single confirmed click, instead of dozens of separate sends.'],
                ['title' => 'Skips the done ones', 'desc' => 'It leaves students already marked suitable alone and only chases pending ones, avoiding duplicate emails.'],
            ],
        ],
        'suitability_send.php' => [
            'how' => [
                'Review any existing suitability records table, using View to inspect or Resend to re-issue a pending link.',
                'Under Send new, choose the course from the Select TAS dropdown.',
                'Set the Required prerequisite qualification and Required LLN level (ACSF) — usually Level 3 for Certificate III/IV.',
                'If the manual LLN adapter is active, record the student\'s assessed LLN level if already known.',
                'Click the Send button to email the student the structured evidence-collection link.',
                'Read the confirmation and check the What the student will see panel to understand the 4-stage flow.',
            ],
            'features' => [
                ['title' => 'Collects real evidence', 'desc' => 'It sends the student a proper evidence form instead of a simple yes or no question, so you get what you need to prove suitability.'],
                ['title' => 'Works out the result', 'desc' => 'It compares the student\'s evidence to your entry requirements and works out whether they are suitable, suitable with support, or not suitable.'],
                ['title' => 'Protects existing checks', 'desc' => 'It stops you from accidentally writing over a check the student has already submitted or passed.'],
            ],
        ],
        'student_declaration_send.php' => [
            'how' => [
                'Read the What this sends card listing the seven obligation items each student will confirm.',
                'Use the All, Not Sent, Sent — Pending and Completed filter buttons, or the Search box, to narrow the student list.',
                'Tick the checkbox beside each target student, or use the header Select all checkbox.',
                'Note that students marked Completed are locked and cannot be re-selected.',
                'Click the sticky Send Declaration to N selected student(s) button at the top of the list.',
                'After the redirect, read the result message showing how many declarations were sent, skipped or failed.',
            ],
            'features' => [
                ['title' => 'Send to the right students', 'desc' => 'Tick boxes and status filters let you email the form to exactly the right students instead of everyone at once.'],
                ['title' => 'See who has replied', 'desc' => 'Each student shows a Not Sent, Pending, or Completed badge so you can tell at a glance who has replied.'],
                ['title' => 'Signed and dated reply', 'desc' => 'Each student sends back a typed-signature form with a date and time saved to their record, showing they agreed.'],
            ],
        ],
        'governance.php' => [
            'how' => [
                'Select the tab for the record you need: Governing Persons, Roles & Responsibilities, Meeting Minutes, Material Changes, or Annual Declaration.',
                'Click the primary button (its label changes per tab, e.g. Add Governing Person or Record Material Change) to open the editor.',
                'In the Governing Persons table, check the Fit & Proper and Suitability Assessment badges and click Edit on any row showing Required or Pending.',
                'On the Material Changes tab, watch the Notification Deadline and Notified columns to keep every change inside the 10 business day ASQA window.',
                'On the Meeting Minutes tab, use View / Edit to confirm the Compliance Items badge reads Yes.',
                'On the Annual Declaration tab, open a year\'s View action to confirm submitted-by, date and Evidence Attached count.',
            ],
            'features' => [
                ['title' => 'Flags missing checks', 'desc' => 'Coloured badges show at a glance which people still need a fit and proper declaration or suitability check.'],
                ['title' => 'Tracks change deadlines', 'desc' => 'The Material Changes tab works out the 10 business day deadline to notify the regulator, so you never miss it.'],
                ['title' => 'Keeps meeting records', 'desc' => 'Saving meeting notes with the compliance topics discussed builds strong proof of active oversight.'],
            ],
        ],
        'risk.php' => [
            'how' => [
                'Choose a tab (Risk Register, Financial Oversight, Conflicts of Interest, or Under-18 Safety) to filter the register to that category.',
                'Click the primary button, whose label pre-selects the category (e.g. Add Financial Risk), to open a ready-to-use form.',
                'Read the summary stat cards for Open, Critical (score >= 16) and High (score 9-15) counts to prioritise.',
                'Scan the Risk Level and Review Date columns and click Edit on any risk showing a red Critical or OVERDUE badge.',
                'Work through the Standard 4.2 Risk Management Compliance Checklist at the foot of the page.',
            ],
            'features' => [
                ['title' => 'Automatic risk rating', 'desc' => 'Likelihood times impact sorts every risk into critical, high, medium, or low for you, so serious ones stand out on their own.'],
                ['title' => 'Separate risk areas', 'desc' => 'Dedicated tabs for financial, conflicts of interest, and under-18 risks keep your register split into clear, tidy areas.'],
                ['title' => 'Overdue review flags', 'desc' => 'Risks that are past their review date get a red overdue badge, so your register stays up to date instead of stale.'],
            ],
        ],
        'feeprotection.php' => [
            'how' => [
                'Review the Fee Protection Arrangement card to confirm your protection type (Protected Account, Bank Guarantee, TAS Arrangement, or Threshold Compliant) is recorded.',
                'If it shows unconfigured, click Configure Fee Protection (or Update Settings), or ask a site administrator when that button is not available.',
                'Click Add Student Fee Record to log a student\'s prepaid fees and mark whether the amount is protected.',
                'Check the Students Approaching/Exceeding Threshold table for any red Exceeds Threshold or amber Approaching badges.',
                'Click View on a flagged row to open the fee record and apply protection before accepting more than $1,500 in prepaid fees.',
            ],
            'features' => [
                ['title' => 'Warns before the limit', 'desc' => 'It flags any student getting close to or over the 1,500 dollar prepaid fee limit so you never go past it by mistake.'],
                ['title' => 'Records your protection', 'desc' => 'One card stores the details of how you protect student fees, ready to show as evidence at any time.'],
                ['title' => 'Colour-coded warnings', 'desc' => 'Simple colour tags for OK, Approaching and Exceeds Threshold let you spot at-risk accounts in a second.'],
            ],
        ],
        'insurance.php' => [
            'how' => [
                'Check the three coverage stat cards for Public Liability, Professional Indemnity and Workers Compensation, noting any showing a red Missing.',
                'Click Add Insurance Policy to record a policy for any missing or newly renewed cover.',
                'In the policy table, review the Status column for Expired, Expiring Soon (under 30 days) or day-count badges.',
                'Click Edit on any expiring or expired policy to update its provider, policy number, coverage amount and expiry date.',
                'Confirm each required type shows a green Active card so your delivery modes and locations stay covered.',
            ],
            'features' => [
                ['title' => 'Spots missing cover', 'desc' => 'The status cards show any of the three required insurance types you don\'t yet have, before the regulator does.'],
                ['title' => 'Warns before expiry', 'desc' => 'It marks policies as Expiring Soon or Expired based on the expiry date, so cover never lapses by accident.'],
                ['title' => 'One policy list', 'desc' => 'Provider, policy number, cover amount, and expiry all sit in one table, making audit evidence easy to find.'],
            ],
        ],
        'thirdparty.php' => [
            'how' => [
                'Click Add Arrangement to record each organisation delivering training or assessment on your behalf.',
                'In the register, check the Clauses Verified column for any Incomplete badge indicating a missing mandatory clause.',
                'Click Edit to confirm the third party cannot use the NRT logo, cannot issue AQF certification, and that students are told of their involvement.',
                'Review the ASQA Notified column and update records where changes still need reporting.',
                'Watch the End Date and Status columns and re-verify or close arrangements marked Expired or Terminated.',
            ],
            'features' => [
                ['title' => 'Checks the agreement', 'desc' => 'A Complete or Incomplete tag confirms the written agreement includes all the clauses it must have.'],
                ['title' => 'One place for partners', 'desc' => 'Every delivery, assessment and support arrangement sits in one place, so you can show you still oversee the work.'],
                ['title' => 'Tracks the dates', 'desc' => 'Start and end dates with Active, Expired and Terminated tags stop an out-of-date agreement from covering ongoing work.'],
            ],
        ],
        'governance_edit.php' => [
            'how' => [
                'For a governing person, enter their full name, position type and appointment date, then tick Fit & Proper and Suitability Assessment with their dates.',
                'For a Material Change, pick the Change type and set the Effective date so the 10 business day notification deadline is calculated automatically.',
                'Tick ASQA has been notified and acknowledged, adding the notification date and ASQA reference as confirmed.',
                'For an Annual Declaration, enter the declaration year, due date, declarant name and position, and the count of evidence items.',
                'Click Save (or Update when editing), or use Cancel to return without changes.',
                'Use the red Delete button, confirming the prompt, only to permanently remove a record.',
            ],
            'features' => [
                ['title' => 'Works out the deadline', 'desc' => 'When you enter the date of a big change, it works out the deadline to tell the regulator, so you do not miss the window.'],
                ['title' => 'One form for all records', 'desc' => 'The same form changes its fields to suit each record type and files it in the right place for you.'],
                ['title' => 'Builds your yearly record', 'desc' => 'It captures who declared what, along with the evidence, to build a solid yearly compliance record.'],
            ],
        ],
        'risk_edit.php' => [
            'how' => [
                'Enter a clear Risk Title and choose the matching Category (pre-selected from the tab you came from).',
                'Describe the risk, its causes and consequences in the Description field.',
                'Set Likelihood and Impact in the Risk Rating Matrix, using the guide that Score = Likelihood x Impact.',
                'Name the Risk Owner and document controls in the Mitigation Plan field.',
                'Set a Review Date and Status (Open, Mitigated or Closed), then click Save Risk or Update Risk.',
                'Use Back to Risk Register or Cancel to exit, or the red Delete button to remove an existing risk.',
            ],
            'features' => [
                ['title' => 'Guided scoring', 'desc' => 'Clear 1-to-5 scales for likelihood and impact, with an on-screen key, give consistent risk scores every time.'],
                ['title' => 'Records owner and plan', 'desc' => 'Saving a named owner, a plan, and a review date shows the risk is being actively managed.'],
                ['title' => 'Right category ready', 'desc' => 'The form opens on the category you came from, saving clicks and keeping risks filed correctly.'],
            ],
        ],
        'feeprotection_edit.php' => [
            'how' => [
                'Under Student & Course, pick the person from the Student dropdown and the qualification from Course/Qualification.',
                'In Fee Details enter the Fee Amount, choose the Fee Type, set the Payment Date, Payment Method and Receipt Reference.',
                'If the payment is covered, tick Fee Protected, then select a Protection Method and enter the Protection Reference/Policy Number.',
                'Read the $1,500 Threshold warning and add any context in Notes to explain unprotected balances.',
                'Click Save Fee Record; amounts over $1,500 are automatically flagged.',
            ],
            'features' => [
                ['title' => 'Automatic warning', 'desc' => 'Any fee over the $1,500 upfront limit is flagged for you, so you do not have to check the amount by hand.'],
                ['title' => 'Proof of protection', 'desc' => 'The page saves how the fee is protected and the policy number, so anyone can see how a student\'s money is kept safe.'],
                ['title' => 'Running total', 'desc' => 'On saved records the page adds up a student\'s other fees and warns you if the total goes over the limit unprotected.'],
            ],
        ],
        'insurance_edit.php' => [
            'how' => [
                'Select the Insurance Type (Public Liability, Professional Indemnity or Workers Comp) and enter the Provider and Policy Number.',
                'In Coverage Details record the Coverage Amount, Premium, Excess Amount, plus coverage details and exclusions.',
                'Under Coverage Mapping list the Qualifications Covered (TAS Link), Delivery Modes and Locations the policy protects.',
                'In Policy Period set the Start Date and Expiry Date and adjust the Renewal Reminder Days (default 30).',
                'Set the Status, add any Notes and click Save changes.',
            ],
            'features' => [
                ['title' => 'Reminds you to renew', 'desc' => 'It stores the expiry date and warns you ahead of time so your cover never runs out unexpectedly.'],
                ['title' => 'Shows what is covered', 'desc' => 'It links each policy to the courses, delivery modes and locations it covers, so you can show everything is insured.'],
                ['title' => 'One record per policy', 'desc' => 'It keeps the provider, policy number, amounts and excess in one place as a ready record of your current cover.'],
            ],
        ],
        'thirdparty_edit.php' => [
            'how' => [
                'Enter the Organisation Name, Trading Name and ABN, then choose the Arrangement Type (e.g. Subcontract, Auspice, Assessment Only).',
                'Record the Contact Name, Email and Phone and set the Agreement Start Date, optional End Date and Qualifications Covered.',
                'Tick ASQA Notified and enter the ASQA Notification Date to record when ASQA was notified of the arrangement as part of your material-change process (there is no fixed 30-day advance-notice rule).',
                'Under Mandatory Clauses, tick every clause confirmed in the written agreement and paste the Agreement Document link.',
                'Set the Monitoring Frequency, monitoring dates, Risk Rating, tick Staff Credentials Verified with its date, then click Save changes.',
            ],
            'features' => [
                ['title' => 'Required clause list', 'desc' => 'The page records which required terms are confirmed in the written agreement, so anyone can see they are there.'],
                ['title' => 'Notification record', 'desc' => 'It saves whether the regulator was told and the date, so notifying them of this arrangement is on record.'],
                ['title' => 'Ongoing checks', 'desc' => 'It logs how often you review the arrangement, the next review date, the risk rating, and trainer credential checks.'],
            ],
        ],
        'transition_edit.php' => [
            'how' => [
                'Under Superseded/Deleted Product enter the Old Product Code, Old Product Name and the Transition Type.',
                'In Replacement Product record the New Product Code and Name where a replacement qualification applies.',
                'Set the TGA Notification Date and the Teach-Out Deadline, and enter Students Affected and Students Contacted.',
                'Write the Transition Plan (or use AI: Generate Transition Plan) and add the Mapping Document reference.',
                'Tick Scope Updated, choose a Linked Course and tick Enrolments Closed to auto-disable self-enrolment, then click Save changes.',
            ],
            'features' => [
                ['title' => 'Teach-out dates', 'desc' => 'Records the notice date and teach-out deadline so an old course is wound down within the required timeframes.'],
                ['title' => 'Stops new sign-ups', 'desc' => 'Ticking "Enrolments Closed" on a linked course turns off self sign-up, so no new students join a closed course.'],
                ['title' => 'Student record', 'desc' => 'Tracks how many students were affected and contacted along with the plan, showing they were looked after.'],
            ],
        ],
        'location_edit.php' => [
            'how' => [
                'Under Location Identifier enter a unique alphanumeric Location ID and the Location Name.',
                'In Location Address complete the building name, street number and name, suburb, 4-digit postcode and State.',
                'Add the site\'s Phone and Email under Location Contact and set the Status to Active or Inactive.',
                'Under the ASQA Rule 9B section, tick Rule 9B Approved if the site is approved and upload the 9B certificate.',
                'Click Save changes; the Location ID is checked for duplicates and the postcode must be exactly four digits.',
            ],
            'features' => [
                ['title' => 'Address kept correct', 'desc' => 'Stores the site address in the standard format and keeps postcodes that start with zero, so your reporting stays valid.'],
                ['title' => 'Building approval proof', 'desc' => 'Records whether the building is approved and lets you attach the approval certificate as proof for the site.'],
                ['title' => 'Change history', 'desc' => 'Every time you add or change a location it is written to a log, giving you a full history of the site\'s records.'],
            ],
        ],
        'rpl_edit.php' => [
            'how' => [
                'Choose the Application Type (RPL or Credit Transfer) and select the Student, whose name auto-fills if left blank.',
                'Enter the Unit Code/Name and Qualification, and for a superseded unit record the Superseded unit held and its Equivalence.',
                'For Credit Transfer add the Source Qualification Code, Source RTO Identifier, tick USI Transcript Verified and upload the source certificate.',
                'Pick the Assessor from the registered-trainer dropdown, describe the evidence and complete the evidence-to-criteria matrix rows.',
                'Set the Decision, Decision Date and the required Decision Reason, tick Outcome communicated with its date and method, then click Save Record.',
            ],
            'features' => [
                ['title' => 'Written reason saved', 'desc' => 'The page makes you write a reason and choose a student before saving, so every credit decision is backed up.'],
                ['title' => 'Match the evidence', 'desc' => 'You can line up each piece of evidence with a unit requirement and the assessor\'s judgement in one place.'],
                ['title' => 'Result added for you', 'desc' => 'An approved decision with verified evidence adds the credit result straight into the student\'s results.'],
            ],
        ],
        'complaint_edit.php' => [
            'how' => [
                'Enter a unique complaint Reference and choose the Complainant Type, ticking Anonymous to suppress the contact fields.',
                'Under Issue Information pick the Category and Subcategory, then enter the Subject and a full Description.',
                'Record the Respondent name and their response, set the Priority, Status, Assigned To manager and Date Received.',
                'In Resolution Details capture the acknowledged, target and actual resolution dates and write the Resolution (or use Generate with AI).',
                'Set Outcome Satisfactory, tick Systemic if it signals a wider issue, add Notes and click Save changes.',
            ],
            'features' => [
                ['title' => 'Fair process record', 'desc' => 'You can record the person the complaint is about, their chance to respond, and the date the outcome was shared.'],
                ['title' => 'Track the dates', 'desc' => 'The page saves when the complaint came in, was acknowledged, and was resolved, so you can check timeframes at a glance.'],
                ['title' => 'Flag bigger issues', 'desc' => 'A tick box marks complaints that point to a wider problem to review and improve.'],
            ],
        ],
        'appeal_edit.php' => [
            'how' => [
                'Enter a unique appeal Reference, optionally link the originating Complaint, and select the Appeal Type.',
                'Record the Appellant\'s name, email and phone, then write the Grounds for Appeal and the Original Decision and decision-maker.',
                'Set the Status and Date Lodged, add acknowledgement and hearing dates, list the Panel Members and tick that the reviewer is independent.',
                'Record the Outcome and Outcome Reason, and for an upheld assessment appeal tick Underlying record corrected.',
                'Complete the External Review fields if offered, add Notes and click Save changes.',
            ],
            'features' => [
                ['title' => 'Fair, separate review', 'desc' => 'You record who made the first decision and confirm a different person is reviewing it, which shows the appeal was handled fairly.'],
                ['title' => 'Fixing the record', 'desc' => 'If an appeal about a mark is successful, ticking the correction box makes sure the student\'s result is updated everywhere it appears.'],
                ['title' => 'Full appeal history', 'desc' => 'You can note whether an outside review was offered and used, so there is a complete record of how the appeal was handled.'],
            ],
        ],
        'improvement_edit.php' => [
            'how' => [
                'Enter a unique Reference and a Title, then describe the improvement in the Description field.',
                'Select the Source Type and, for a complaint or validation source, pick the Linked Complaint or Linked Validation.',
                'Choose the Category and Priority, set the Status, and record the Date Identified with optional target and completion dates.',
                'Document the Action Plan and Outcome of the corrective action.',
                'Tick Effectiveness Verified and enter the verification date and method, add Notes, then click Save changes.',
            ],
            'features' => [
                ['title' => 'Link to the source', 'desc' => 'You can tie each improvement back to the complaint or review that raised it, showing a proper end-to-end process.'],
                ['title' => 'Proof it worked', 'desc' => 'You record that the fix was checked and actually worked, with the date and method, not just that it was attempted.'],
                ['title' => 'Full progress trail', 'desc' => 'You track each improvement from first spotted through to closed, with target and completion dates, keeping the register tidy.'],
            ],
        ],
        'survey_send.php' => [
            'how' => [
                'Use the Send To dropdown to choose your audience — for learner surveys: All active students, Students who completed this year, Students in a specific course, or Enter emails manually; for employer surveys: Registered employer contacts or manual entry.',
                'If you chose Students in a specific course, pick the qualification from the Select Course dropdown.',
                'If you chose Enter email addresses manually, type one email per line into the Email Addresses box.',
                'Edit the Email Subject and Email Message, keeping the {FIRSTNAME} and {SURVEY_LINK} placeholders.',
                'Click Send Surveys to dispatch the AQTF Learner or Employer Questionnaire and record every invitation for response-rate tracking.',
            ],
            'features' => [
                ['title' => 'Picks the right people', 'desc' => 'It can pull up all active students, this year\'s finishers, one course group, or employer contacts, so you never build a list by hand.'],
                ['title' => 'Personal survey links', 'desc' => 'The {FIRSTNAME} and {SURVEY_LINK} tags add each person\'s name and their own private survey link automatically.'],
                ['title' => 'Ready-made surveys', 'desc' => 'It sends the standard learner and employer surveys, giving you the feedback you need for continuous improvement.'],
            ],
        ],
        'survey_responses.php' => [
            'how' => [
                'Use the year dropdown beside the heading to select the survey year to review.',
                'Read the four stat cards — Completed Responses, Pending, Response Rate and Avg Satisfaction — for that year\'s campaign.',
                'Scan the Individual Responses table to see each respondent\'s name, email, colour-coded satisfaction badge and completion date.',
                'If the page shows No Responses Yet, click Send Survey Invitations to launch a new send.',
                'Click Analyse with AI to run automated theme and sentiment analysis, or Back to Surveys to return.',
            ],
            'features' => [
                ['title' => 'Live response rate', 'desc' => 'It works out how many people answered out of those invited, so you can show survey reach without a spreadsheet.'],
                ['title' => 'Colour-coded scores', 'desc' => 'Each response is coloured green, amber, or red, so unhappy people stand out for follow-up.'],
                ['title' => 'Yearly record', 'desc' => 'Filtering by year gives a dated record of survey collection for the regulator.'],
            ],
        ],
        'ai_analysis.php' => [
            'how' => [
                'Choose the survey type (Learner Survey or Employer Survey) and the year from the two dropdowns.',
                'Check the count of completed responses shown — AI analysis needs at least one completed response.',
                'Note the credit-cost badge, then click Run AI Analysis to start; the button shows a please-wait state while it works.',
                'Review the results — Overall Sentiment, Satisfaction Index, Strengths, Areas for Improvement, AI Recommendations, Key Themes and the Full Analysis Report.',
                'Open any earlier run from the Previous Analyses table by clicking its View button.',
            ],
            'features' => [
                ['title' => 'Reads the comments for you', 'desc' => 'It reads all the written survey answers and sorts them into strengths, things to improve, and common themes in about a minute.'],
                ['title' => 'Shows how happy people are', 'desc' => 'It gives an overall happiness score so you can see how satisfied your students or employers are at a glance.'],
                ['title' => 'Keeps past results', 'desc' => 'Every analysis you run is saved in a list so you can look back and compare results over time.'],
            ],
        ],
        'ai_usage_report.php' => [
            'how' => [
                'Click a date-range pill — All Time, Last 7 Days, Last 30 Days, Last 90 Days or Last Year — to set the reporting window.',
                'Read the summary stat cards for Total AI Calls, Credits Used, Credits Remaining and estimated cost in AUD.',
                'Review the Usage by Feature table to see which plugin tools consumed credits and each feature\'s percentage share.',
                'Study the Daily Activity chart to spot spikes in AI calls and credit consumption over time.',
                'Check Recent Activity for the last events and the Local Moodle Audit Log for admin actions on this site.',
            ],
            'features' => [
                ['title' => 'Track your spending', 'desc' => 'See how many AI credits you have used and how many are left, in dollars, so you do not run out partway through your work.'],
                ['title' => 'See what used credits', 'desc' => 'A table shows exactly which tool spent each credit, so you know where your AI use is going.'],
                ['title' => 'Record of admin actions', 'desc' => 'The page keeps a separate list of admin actions on this site, giving you a record of who did what.'],
            ],
        ],
        'asqa_standards_map.php' => [
            'how' => [
                'Read the four summary tiles — Outcomes mapped, Covered, Partial and Gap — for your overall self-assurance position.',
                'Work through each Quality Area section (QA1 to QA4) and the Compliance Requirements section, reading the clause reference and obligation on each row.',
                'Check the colour-coded status badge on every row — green Covered, amber Partial or red Gap.',
                'Read the italic note under Partial and Gap rows to understand the named improvement still in progress.',
                'Click the Open link on any row to jump straight to the feature that provides that evidence.',
            ],
            'features' => [
                ['title' => 'Honest coverage view', 'desc' => 'It marks each rule as covered, partly covered, or missing instead of showing everything as done, so you get a true picture.'],
                ['title' => 'Direct rule links', 'desc' => 'Each rule links straight to the tool that holds its proof, so you spend less time searching.'],
                ['title' => 'Shows what\'s left', 'desc' => 'It names the work still needed on partial and missing items so you can fix those first.'],
            ],
        ],
        'locations.php' => [
            'how' => [
                'Click Add Location (or Add your first location when empty) to register a new delivery site.',
                'Read the table columns — Location ID, Name, Suburb, Postcode, State, Status and the Rule 9B classification.',
                'Check the Rule 9B badge and any attached Class 9B certificate download link to confirm each site\'s building classification.',
                'Click Edit on a row to update a location\'s details or Class 9B status, or Delete (confirming) to remove one.',
                'Use the Related pages links to jump to TAS Section 7 or the ASQA Facilities Practice Guide.',
            ],
            'features' => [
                ['title' => 'Building evidence', 'desc' => 'Each site records its building classification and lets you attach the certificate that proves it is fit for training.'],
                ['title' => 'One site register', 'desc' => 'Every training location, its state, and its status sit in one sortable table so your sites are always documented.'],
                ['title' => 'Feeds your training plan', 'desc' => 'Your sites flow straight into your training and assessment strategy, so you do not have to type them in twice.'],
            ],
        ],
        'course_settings.php' => [
            'how' => [
                'Tick the Nationally Recognised Training checkbox to reveal the qualification fields for this course.',
                'Enter the Qualification Code, Qualification Name and Nominal Hours that identify the training product.',
                'If the course is delivered to overseas students, tick CRICOS Registered and enter the CRICOS Code.',
                'Click Save changes to store the course\'s compliance settings.',
                'Use Generate Certificates for This Course under Certificate Actions to bulk-issue certificates for completed students.',
            ],
            'features' => [
                ['title' => 'Label the course', 'desc' => 'Saving the national code, name, and hours means certificates and national report files pull the right course details for you.'],
                ['title' => 'International courses', 'desc' => 'You can mark a course as being for international students and save its CRICOS code with it.'],
                ['title' => 'Issue many at once', 'desc' => 'One click creates certificates for every student who has finished the course, with the right type chosen for you.'],
            ],
        ],
        'compliance_map.php' => [
            'how' => [
                'Read the intro line confirming the directory is organised by the Standards for RTOs 2025 Quality Areas.',
                'Scroll through each colour-coded Quality Area section to see its feature cards.',
                'Read each card\'s title, one-line description and the Clauses reference showing which Standards it supports.',
                'Click any feature card to open that module directly.',
                'Use the responsive full-width layout to browse the complete feature set on any screen size.',
            ],
            'features' => [
                ['title' => 'Tool directory', 'desc' => 'It puts every tool in one place so staff can find what they need quickly.'],
                ['title' => 'Grouped by topic', 'desc' => 'Tools are grouped by topic, making it clear which one to use and which rules it covers.'],
                ['title' => 'Opens in one click', 'desc' => 'Each card opens its tool straight away, so the map doubles as a launch point for daily tasks.'],
            ],
        ],
        'practice_guides.php' => [
            'how' => [
                'Browse the guide cards grouped by Quality Area (1-4), the Compliance Standards and the Credential Policy.',
                'Click a guide card to open its self-assurance detail page, noting the Standards reference and question count.',
                'Read each numbered self-assurance question in the left column and the How RTO Compliance Helps mapping on the right.',
                'Click Open Module beside any question to jump to the register that provides that evidence.',
                'Use View on ASQA to read the official guide online or Download PDF to save ASQA\'s source document.',
            ],
            'features' => [
                ['title' => 'Question-to-tool guide', 'desc' => 'Every official self-check question is paired with the exact page in this tool that answers it, so preparing is straightforward.'],
                ['title' => 'Links to the source', 'desc' => 'Each guide links straight to the official web page and PDF, keeping the exact wording one click away.'],
                ['title' => 'Step-by-step self-review', 'desc' => 'The guides are organised by Quality Area and standard, helping you review your own practice in a clear order.'],
            ],
        ],
        'governance_minutes_edit.php' => [
            'how' => [
                'Enter a descriptive Meeting Title such as Board Meeting March 2026.',
                'Set the Meeting Type dropdown (Board, Management, Quality, Staff or Other), pick the Date, and enter the Location.',
                'Fill the Attendees, Agenda Items, Decisions Made and Action Items boxes, recording responsible parties and due dates.',
                'Complete the Compliance / Regulatory Items box with risk reviews, financial monitoring and ASQA updates — key audit evidence.',
                'Click Save (or Update when editing) to store the minutes, or Delete to remove an existing record.',
            ],
            'features' => [
                ['title' => 'Ready for audit', 'desc' => 'The page saves decisions, action items, and a compliance section as dated proof of how the RTO is run.'],
                ['title' => 'Clear action items', 'desc' => 'Each action asks who is responsible and when it is due, turning notes into a to-do list you can follow up.'],
                ['title' => 'Meeting history', 'desc' => 'Every meeting is saved by type and date, so you can show a full record whenever it is needed.'],
            ],
        ],
        'governance_roles_edit.php' => [
            'how' => [
                'Enter the Role Title (e.g. RTO Manager or CEO) and the name of the Current Role Holder.',
                'Record the Department/Team and Reports To fields to place the role in your organisational structure.',
                'Detail the Key Responsibilities, including how the role contributes to ASQA compliance.',
                'Complete the How Holder is Kept Informed of Regulatory Changes box, and set a Review Date.',
                'Click Save (or Update when editing) to store the role, or Delete to remove it.',
            ],
            'features' => [
                ['title' => 'Who does what', 'desc' => 'Records each role\'s person, reporting line and duties so it is clear who is responsible for what.'],
                ['title' => 'Staying up to date', 'desc' => 'Captures how each person keeps up with rule changes, which is proof you can show an auditor.'],
                ['title' => 'Review reminders', 'desc' => 'Setting a review date on every role keeps the duties current so you don\'t forget to check them.'],
            ],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────────
    // HOW-TO REWRITE (v5.9.437) — first-time, page-specific MUST-DO steps that name
    // the EXACT on-page controls (button labels, field names, tabs), replacing the
    // older generic guidance. Each entry overrides ONLY the 'how' list for that page;
    // the What/Why text, icon, standard pill and Key Features are left untouched.
    // Written from each page's real UI so a brand-new user gets the precise initial
    // sequence ("enter X, choose Y, click Z") the way they would from a quick-start.
    // ─────────────────────────────────────────────────────────────────────────────
    $howrewrite = [
        'usi_settings.php' => [
                'This page only shows the status of your student ID number checking (USI is the student ID number); you do not enter anything here.',
                'Check that the "Current status" panel has no "API Connection required" warning; if it does, add the Platform API details (API URL, Site ID, API Key) in Plugin Settings first.',
                'Read the status panel to see whether your checking credential is set up and the connection is working.',
                'To add or update the credential, sign in to the lms-labs.com admin panel; for security it is kept only there and never in Moodle.',
                'Once a valid credential is in place, this page shows "Ready" and you can check student ID numbers from the student records page.',
            ],
        'plugin_settings.php' => [
                'Click the "Platform API" tab first and enter your API URL, Site ID and API Key.',
                'Click the other tabs as you need them, such as "RTO Details", "Certificates", "USI Settings" (student ID number settings), "Auto-Survey", "ASQA 2025", "State Funding" or "Maintenance".',
                'Fill in or update the fields on the tab you are on.',
                'Click "Save changes" before moving to another tab.',
                'Do the same for each tab, because every tab saves on its own.',
            ],
        'locations.php' => [
                'Click "Add Delivery Location" to add your first training site.',
                'For a site already listed, click "Edit" to update its address, status, or building details.',
                'In the building-classification column, click an attached certificate file to download the building evidence.',
                'Click "Delete" and confirm to remove a site you no longer deliver at.',
                'Use the training and assessment strategy link (labelled TAS Section 7) to carry these sites into your training plan.',
            ],
        'location_edit.php' => [
                'Enter a short "Location ID" (1 to 10 letters or numbers) and a "Location Name" - both are needed.',
                'Fill in the "Address Details" and pick the "State" from the dropdown.',
                'Add a phone and email under "Contact Details" if you have them.',
                'Set "Status" to "Active" or "Inactive".',
                'Tick "Building Approved (Class 9B)" if the site has that building approval.',
                'Upload proof under "Class 9B Certificate(s)" (PDF, JPG or PNG, up to 3 files).',
                'Click "Save changes" (or "Add" for a new location) to store it.',
            ],
        'feeprotection.php' => [
                '1. Click Configure Fee Protection (or Update Settings) to record how you protect student fees. Only site administrators can do this.',
                '2. Click Add Student Fee Record to log the fees a student has paid in advance.',
                '3. Look at the table for any student marked Approaching or Exceeds Threshold.',
                '4. Click View on a flagged student to check their fees against the 1,500 dollar limit.',
            ],
        'insurance.php' => [
                'Check the status cards to see which required cover, Public Liability, Professional Indemnity, or Workers Compensation, shows "Missing".',
                'Click "Add Insurance Policy" to record a policy.',
                'Add a policy for each type still marked "Missing" until all three show "Active".',
                'Check the "Status" column in the policy table for "Expiring Soon" or "Expired" entries.',
                'Click "Edit" on a row to update a policy\'s provider, cover amount, or expiry date.',
            ],
        'workforce_management.php' => [
                'Under Step 1, enter your number of active trainers and your total current students.',
                'Choose your main delivery mode and set the teaching hours per trainer per week.',
                'Under Step 2, fill in your qualifications, assessments each, hours per assessment, delivery weeks, and trainer capacity.',
                'Under Step 3, list each unit in the box as unit code, then trainer name, leaving the trainer blank to flag a gap.',
                'Read the result card, the alerts, and the trainer load breakdown to see if your staffing is enough.',
                'Copy the auto-written summary into your training plan or workforce evidence folder.',
            ],
        'trainers.php' => [
                'Look at the stat cards at the top for a quick view of trainer numbers and credential status.',
                'If a notice says some teachers have no compliance profile, click the Import button to add them to the register.',
                'Click Add Trainer to create a new trainer profile from a Moodle user.',
                'Use the Filter by status dropdown to show Current, Expiring, or Expired trainers.',
                'Click the Edit button at the end of a trainer\'s row to open, update, or delete that profile.',
            ],
        'trainer_edit.php' => [
                'For a new trainer, under "Trainer Name" choose the person from the "Select a user…" dropdown.',
                'Under "TAE Credential" choose the trainer\'s teaching qualification, set "Date Achieved", and leave "TAE Expiry Date" blank unless it really expires.',
                'Tick the "Credential Role" boxes that apply; roles 1C, 1D, 2A, and 2B need documented supervision.',
                'Fill in the Vocational Qualifications, Industry Currency, language and skills, Working With Children Check, and National Police Certificate sections.',
                'Upload the trainer\'s file under "Resume/CV Document" and enter their professional development hours.',
                'Tick "Manager Sign-off" and set the review date if needed, then click "Save changes" (or "Add" for a new trainer).',
            ],
        'trainer_currency.php' => [
                'Click "Add Currency Activity" to open the form.',
                'Pick an "Activity Type" and enter the "Activity Title/Role" and "Organisation/Employer".',
                'Set the "Start Date", then tick "Still Ongoing?" or set an "End Date", and add "Hours per Week (approx)".',
                'Fill in "Description of Activities", pick an "Evidence Type", and attach proof with "Upload Evidence".',
                'Click "Add Activity" (or "Update Activity") to save it.',
                'Back on the list, use "Edit" or "Delete" on each activity and check the "Currency Summary" banner.',
            ],
        'supervision.php' => [
                'Check the "Working Towards TAE - Deadline Alerts" panel for trainers marked overdue or due soon.',
                'Click "Add Supervision Log" to record a new supervision or check.',
                'Use the filter buttons ("All Logs", "Pending Validation", "Validated", "Overdue Actions") to shorten the list.',
                'Look at each row\'s status to see if it is pending, validated, or has overdue actions.',
                'Click "View" on a row to open a log you saved before.',
            ],
        'supervision_edit.php' => [
                'Pick the trainer being supervised from the "Select trainer" dropdown.',
                'Pick a supervisor from the list; only fully qualified trainers appear there.',
                'Set the Supervision Date and choose a Supervision Type, such as observation or mentoring.',
                'Type the Qualification Code and Duration, then fill in the activities, feedback given, and any development needs.',
                'Under Follow-up Actions, list the action items, set a due date, and tick "Actions Completed" when they are done.',
                'Click "Add" or "Save changes". On an existing record you can also click "Validate as RTO Manager" or "Delete".',
            ],
        'qualbuilder.php' => [
                'Click Add Training Product to create one by hand, or use the bulk buttons at the top right to build several from your Moodle courses.',
                'Use the filter tabs (All, Qualification, Skill Set, Single Unit) to narrow the list.',
                'Read the "What the columns mean" note to understand the Units, Linked Courses, Course Map, and Status columns.',
                'Click Edit on a product to set up its units and courses.',
                'Click Build Course Map from Links to fill the Course Map automatically from the courses already linked to each unit.',
                'Get the Linked Courses and Course Map counts to full green, then set the product to Active.',
            ],
        'qualbuilder_edit.php' => [
                'Choose the Product Type: a full qualification, a skill set, or a single unit.',
                'Type the code (for example BSB30120) and click "Load from TGA" to fill in the name and unit list automatically.',
                'Set the course category, and the semester or intake if needed, so the right courses show up.',
                'Tick the units you need, then attach a course to each one, or click "Map All Courses" to match them all at once.',
                'Set the Status and check the compliance panel for any missing pieces.',
                'Click "Save Qualification", then open the full report to confirm everything passes.',
            ],
        'qualbuilder_courses.php' => [
                'Click "Auto-Detect Courses" to match each unit to an online course by its unit code.',
                'For any unit, pick the course that teaches it from the "Linked Course" dropdown.',
                'Click "Save Links" to store the matches and update the linked count.',
                'In "Archive Courses", pick an old course, type a label like "Archive S2 2010", and click "Link" to keep past semesters.',
                'Click "Remove" to undo a wrong archive link.',
                'Click "Back to Product" to return to the qualification.',
            ],
        'course_map.php' => [
                '1. Click Scan and Seed All Quals to let the system fill in the links between your units and courses automatically.',
                '2. To look at one qualification, type its code in the filter box and click Filter.',
                '3. Check each row, then click the green tick to confirm any link that is still unconfirmed.',
                '4. Click the red cross to remove a link that is wrong.',
                '5. For a course the system could not find, fill in the Add Manual Mapping form with the qualification code, unit code and course ID, then click Add Mapping.',
            ],
        'tas.php' => [
                'Click "Create New TAS" to start a new training and assessment strategy (your plan for how a course is taught and marked).',
                'Click any of the 9 "Required TAS Sections" cards to jump straight to that part.',
                'Fill in and save each section; the "Existing TAS Documents" table shows how complete each one is.',
                'Click "Edit" on a document to keep working on it.',
                'Click "Export" to produce the finished document.',
                'Click "Delete" to remove a document you no longer need.',
            ],
        'nominalhours_import.php' => [
                'Choose the Jurisdiction (state): pick NAT for the national standard hours, or a state code to override them.',
                'Type a Source reference that says where the file came from, such as the year and source.',
                'Choose the Data file to upload (a text or spreadsheet file with a unit code column and an hours column).',
                'Click Import to load the values.',
                'Read the "Import complete" message to check how many were added, updated, or skipped.',
            ],
        'data_import.php' => [
                'First click "Open Qual Builder" and set up each qualification, its units, and the online course that teaches each unit.',
                'Under "How should students be matched to their Moodle accounts?", choose "By email address" or "By student number".',
                'At "Select NAT files to upload", pick your national report files (hold Ctrl or Cmd to select the whole set at once).',
                'Click "Upload & Import".',
                'On the review page, check the students grouped by qualification and semester, then click "Confirm & Import".',
                'Open "Student Results" to check the results loaded, then click "Verify NAT Data" to confirm nothing is missing.',
            ],
        'students.php' => [
                'Set "Filter by Status" (for example, all students, or only those missing a student ID number) to narrow the list.',
                'You can also set "Filter by State", type in the search box, then click "Search".',
                'Click a student\'s name to open their profile.',
                'To send checks in bulk, tick the row boxes (or the header box for all), then pick a qualification in the bulk check dropdown.',
                'Click "Send to Selected" to email those students their suitability check.',
                'Or click "Fill Compliance Gaps" to email every student who does not have a check yet.',
            ],
        'student_profile.php' => [
                '1. Fill in the required student details across the Personal, Address, Demographic and Education sections, such as the USI (unique ID number), date of birth and postcode.',
                '2. Click Save changes to create or update the profile and clear the incomplete warning.',
                '3. Look at the Training results section to see how many units the student has passed.',
                '4. Click Record or edit results to add or change the student\'s unit results.',
                '5. Once units show as Competent, click Issue Statement of Attainment (a record of the units passed) or Issue Qualification Certificate.',
            ],
        'student_enrolments.php' => [
                'Click "Add Enrolment" to record a unit for this student.',
                'In the form, choose the Course, enter the Unit code, set the Outcome, delivery, and enrolment status, then Save.',
                'If Moodle enrolments are found, click "Import Moodle Enrolment(s) as AVETMISS Records" (AVETMISS is the national VET data standard), then edit each row to correct the details.',
                'In the table, click "Complete" on a row to mark it Competency Achieved.',
                'Click "Edit" to change a row\'s outcome or dates, or "Delete" to remove it.',
                'Click "Edit Profile" to go back to the student\'s personal details.',
            ],
        'suitability_send.php' => [
                '1. Check the student name and email shown at the top of the form.',
                '2. In the course dropdown, choose the course you want to check the student against.',
                '3. Set the required entry qualification and the required reading and writing level for that course.',
                '4. If you know it, enter the student\'s assessed reading and writing level, or leave it as Not yet assessed.',
                '5. Click Send Student Suitability Check to email the student a link to send back their evidence.',
                '6. To send a pending check again, use Resend Email in the records table.',
            ],
        'suitability_bulk.php' => [
                'Choose the qualification in the "Select Training and Assessment Strategy (qualification)" dropdown (the training and assessment strategy is the plan for how a course is taught and assessed).',
                'Read the line under the dropdown showing how many students will be emailed.',
                'Click "Send to All Uncontacted Students".',
                'Confirm the pop-up to send the checks; students already marked suitable are skipped.',
            ],
        'qualbuilder_results.php' => [
                'Click the green "Sync results from Moodle completions" button to update the list from Moodle course completions.',
                'Click "Download unmapped completions (CSV)" to find courses the sync could not match, then link or rename them.',
                'Use the search box and the dropdowns to narrow the list, then click "Apply".',
                'Click "Profile" to open a student, or "Units" to see their unit-by-unit results.',
                'Click "Export CSV" to download the list you are currently viewing.',
            ],
        'qual_cert_hub.php' => [
                '1. Find the qualification using the search box and category dropdown, then click Search.',
                '2. Click Detail on the qualification row to open it, or Issue Pending to go straight to issuing.',
                '3. On the Ready to Issue tab, tick the students who have finished every unit, or use Select all.',
                '4. Choose whether to tick Email certificate to student.',
                '5. Click Issue Selected Certificates to create the certificates.',
                '6. Check the Certs Issued tab, and use the queue tab\'s Process Queue to catch anyone missed.',
            ],
        'certificates.php' => [
                '1. Choose how you want to issue a certificate using the buttons at the top, such as Issue Certificate or Generate by Qualification.',
                '2. To find an existing certificate, type a name, email or certificate number in the search box and click Apply filters.',
                '3. On a certificate row, click View to open the PDF, or Email to send it to the student.',
                '4. Click Pack to download the certificate and results together as a zip file.',
                '5. To send many at once, tick the boxes on several rows, then use the Email or Download Zip button that appears.',
                '6. Click Verify to open the public check page, or Delete to cancel a certificate.',
            ],
        'cert_templates.php' => [
                'If you see a banner offering to add starter certificate designs, click it to create ready-made defaults for any certificate type you are missing.',
                'If you see a branding warning, click "Open RTO Settings" and add your logo, signature, seal, and the nationally recognised training logo.',
                'In the create form, choose a Certificate Type, a page layout, and an audience, type a Name, then click "New template".',
                'On your new template\'s row, click "Edit" to design it, then click "Submit for approval".',
                'Once it is approved, click "Activate" so it becomes the live design for that certificate type.',
                'Click "Preview" to see the finished document before you print any real certificates.',
            ],
        'generate_qual_certs.php' => [
                'Choose a qualification from the dropdown and click "Go".',
                'Look at the list of students who finished every unit, and tick each one you want to certify (or use "Select all").',
                'If you need to replace certificates already issued, tick "Force regenerate", and tick "Notify students" to email them.',
                'Click "Generate Testamur + Record of Results" and confirm the prompt.',
                'Use "Change Qualification" to switch to another, or "Refresh" to check for newly finished students.',
            ],
        'soa_issue.php' => [
                'In Step 1, type a name or email in the picker (use the filters to narrow it) and pick the student.',
                'In Step 2, check the units that loaded and tick the ones to include, or click "Select all compliant".',
                'If you want, click a card under "Suggested SOA Groups" to pick a qualification\'s units and fill Step 3 for you.',
                'In Step 3, set the "Document Type" and enter the "Qualification Code" and "Qualification Name" if not already filled.',
                'Fix any units shown in red (only tick "Override compliance warnings" if you have a good reason).',
                'Click "Generate SOA" to make the Statement of Attainment (proof-of-units document) as a PDF.',
            ],
        'compliance_health.php' => [
                'Look at the round score at the top of the page and its verdict: on track, needs attention, or action required.',
                'Check the three summary chips showing how many checks are clear, need watching, or need action.',
                'Look down the Quality Area cards for any row marked with an Action or Watch tag.',
                'Click the fix link under a flagged row (such as "Verify student ID numbers") to jump straight to the page that sorts it out.',
                'After you fix items, reload this page to see your score update.',
            ],
        'nat_validate.php' => [
                'Read the top banner to see if records are "Ready to submit" or how many errors need fixing.',
                'Check the tiles for students checked, enrolments checked, errors, and warnings.',
                'Open each finding card to see the affected record, field, and message.',
                'Fix the flagged records on their own pages, then click "Re-run validation".',
                'Click "Export all findings (CSV)" to download the full list to work on offline.',
            ],
        'natexport.php' => [
                '1. Pick the reporting year from the dropdown, which loads on its own.',
                '2. Click Validate and read any errors, warnings and record counts that appear.',
                '3. If a warning shows a missing qualification code, open its link and fix those enrolments first.',
                '4. Click Generate to build and download the report file. This is blocked while errors remain.',
                '5. Copy any state portal reference numbers from the panel, using the Go to portal link when you upload.',
                '6. To get an earlier file again, download it from the Recent Exports table.',
            ],
        'rpl.php' => [
                '1. Choose a tab: RPL Applications, Credit Transfers, or All Records. RPL means credit for skills a student already has.',
                '2. Click Add RPL Application (or Add Credit Transfer on the Credit tab) to start a new record.',
                '3. Enter the student, the unit, the evidence, the assessor and the decision in the form.',
                '4. For credit transfers, check the USI Verified column reads Verified before you grant credit. USI is the student\'s unique ID number.',
                '5. Check the Student Notified column and tell each student their result in writing.',
                '6. Click Edit on any row to update a decision or its evidence.',
            ],
        'complaints.php' => [
                'Click "Lodge New Complaint" and enter its type, how urgent it is, and who will handle it.',
                'Click "View" on a complaint to work through it from investigation to being resolved.',
                'Open the "Appeals" tab and click "Lodge New Appeal" to record someone appealing a decision.',
                'Open the "Continuous Improvement" tab and click "Add Improvement Action" to record a fix that stops the problem happening again.',
                'Watch the "Open Complaints" and "High / Critical Priority" cards to see what needs attention first.',
            ],
        'governance.php' => [
                'On the "Governing Persons" tab, click "Add Governing Person" and record their fit and proper declaration and suitability check.',
                'On the "Roles & Responsibilities" tab, click "Add Role" and record each key role and who holds it.',
                'On the "Meeting Minutes" tab, click "Add Meeting Minutes" and note what was discussed.',
                'On the "Material Changes" tab, click "Record Material Change" and enter the date it took effect so the 10 business day deadline is tracked.',
                'On the "Annual Declaration" tab, click "Start ADC Submission" to log your yearly compliance declaration with its evidence.',
            ],
        'risk.php' => [
                'On the Risk Register tab, click "Add Risk" and set how likely it is and how bad it would be to work out its level.',
                'Give the risk an owner and a review date so overdue reviews get flagged.',
                'On the Financial Oversight tab, click "Add Financial Risk" to record money-related risks.',
                'On the Conflicts of Interest tab, click "Add Conflict of Interest" to log any declarations.',
                'On the Under-18 Safety tab, click "Add Under-18 Risk", and check the All Risks view for anything saved in the wrong place.',
                'Watch the Open, Critical, and High stat cards and clear any overdue reviews.',
            ],
        'validation.php' => [
                '1. Open the Validators tab and click Add Validator to record each validator\'s role and qualifications.',
                '2. On the Validation Schedule tab, click Schedule Validation and set the date, risk level and lead validator.',
                '3. Click Manage on a scheduled row to run the session and record the result.',
                '4. Open the Completed Events tab to review the sample size, findings and next due date.',
                '5. Check the Coverage Gaps table for any course with no current validation.',
            ],
        'issue_certificate.php' => [
                'Start typing the student\'s name in the Student box and pick them from the list.',
                'Choose the Certificate Type and the Audience, which sets the template design.',
                'Type the Qualification Code and Qualification Name.',
                'For a Statement of Attainment (a certificate for finished units), list each unit on its own line as \'CODE - Name\'.',
                'Set the Issue Date, add an Expiry Date if needed, and leave Notify Student ticked to email them.',
                'Click Issue Certificate to create and save it.',
            ],
        'email_cert.php' => [
                'Check the details shown: the student\'s name, email, certificate type, and certificate number.',
                'Read any warning that the student ID number (USI is the student ID number) is not verified; the email still sends, but check the number afterwards.',
                'Click Continue to email the certificate to the student.',
                'Click Cancel to go back to the Certificates list without sending.',
            ],
        'generate_course_certs.php' => [
                'Under "Generate by Unit of Competency", search for a course, pick it and click "Go" (or use "Select a Qualification" for a full qualification).',
                'To shorten the list, use "Filter by Group" or "Filter by Student", then click "Apply Filter".',
                'Choose "Issue Missing Certificates" to only make the ones not yet issued, or "Force Regenerate" to remake them.',
                'Tick each student you want, or click "Select All Needing Certs" or "Select All Shown".',
                'Leave "Email certificates to students" ticked if you want them emailed, then click "Generate / Regenerate Selected".',
                'Check the cost in the popup, then click "Confirm & Generate".',
            ],
        'cert_test.php' => [
                'Choose the page direction: Auto, Portrait, or Landscape.',
                'Choose the Certificate Type you want to preview.',
                'Type a Student Name, or leave it blank to use the sample name Jane Citizen.',
                'Tick "Send to my email" if you also want the sample sent to your inbox.',
                'Click Generate to open the sample certificate as a PDF in a new tab.',
            ],
        'cert_template_edit.php' => [
                'In the "Template Info" panel, type a name and pick who the certificate is for.',
                'Drag fields from the "Fields" list onto the certificate to place them.',
                'Click a field you placed, then change its text, font, colour or position in the "Properties" panel.',
                'Click "Save" to keep your design.',
                'Click "Preview" to open a sample copy in a new tab and check it looks right.',
                'Click "Submit for approval", then click "Activate" once approved to start using it.',
            ],
        'mycerts.php' => [
                'Use the year tabs ("All Years" or a single year) to filter your certificates.',
                'For a Statement of Attainment (a proof-of-units document), click the "units of competency" link to see the units.',
                'Click "Download PDF" to save a certificate to your device.',
                'Click "Verify" to open that certificate\'s public check page in a new tab.',
            ],
        'my_profile.php' => [
                'Fill in every required field shown, such as your student ID number (USI), date of birth, and postcode.',
                'Fill in any extra fields that appear, such as disability or school details.',
                'Type your student ID number carefully, as changing it clears any check already done on it.',
                'Click Save changes; all required fields must be filled for your profile to count as finished.',
            ],
        'mydocs.php' => [
                'Look at the Issued Certificates table and click Download PDF or Verify on any row.',
                'In the Upload Document panel, choose a Document Type from the dropdown.',
                'Type an optional note in the Notes field.',
                'Choose a File (any type, up to 20 MB) and click Upload Document.',
                'To remove an item, click Delete on its row and confirm.',
            ],
        'qualbuilder_unit.php' => [
                '1. Type the unit code and unit name in the Unit Details section. Both are required, and the hours may fill in on their own.',
                '2. Choose the unit type (Core, Elective or Imported). If it is an elective, pick its group.',
                '3. Set the nominal hours, credit points and order as needed.',
                '4. Under Course Linking, pick the matching course from the dropdown.',
                '5. Click Save changes to add the unit.',
            ],
        'qualbuilder_validate.php' => [
                'Read the top banner to see if the qualification passed or failed its rules check.',
                'Look at the checks table and compare the Expected and Actual columns for any failed check.',
                'Read the errors and warnings boxes to see exactly what to fix.',
                'Change the qualification\'s units, then click Check Packaging Rules to check again.',
                'Click Back to Product to go back to the editor.',
            ],
        'tas_edit.php' => [
                'In Section 1, fill in the Qualification Code and Qualification Name, and paste the Training.gov.au link.',
                'In Section 2, choose a Qualification Level, tick the student groups that apply, then click "Apply to Section 2".',
                'In Section 4, set the Delivery Start Date and click "Generate Delivery Plan" to build the schedule automatically.',
                'In Section 5, tick the Assessment Methods that apply and paste the Assessment Mapping Document link.',
                'In Section 9, set the Document Status and Version Number, then click "Create TAS" to save your training and assessment strategy (the plan for how a course is taught and assessed).',
            ],
        'tas_consultation.php' => [
                'Click Download Industry Consultation Log Template to get a Word log already filled in for this qualification.',
                'Click Log New Consultation Record to open the entry form.',
                'Type the industry contact\'s name, add their organisation and role, and choose how and when you consulted them.',
                'Fill in the key feedback and its impact on your delivery and assessment; you can use the quick-add tick boxes to help.',
                'Use Upload Evidence Document to attach the completed log, then click Add Consultation Record.',
            ],
        'transitions.php' => [
                'Click Add Transition Plan to record a training product that has been replaced or removed.',
                'Look down the register at the teach-out deadline, status, and enrolment columns to spot anything overdue or still open.',
                'Click Manage on any row to edit that plan or link a course to control who can enrol.',
            ],
        'transition_edit.php' => [
                'Under "Superseded/Deleted Product", enter the required old product code and name, and choose a "Transition Type".',
                'If there is a replacement, add its new product code and name.',
                'Under "Transition Timeline", set the required regulator notice date and teach-out deadline.',
                'Enter how many students are affected and contacted, then write the transition plan (or click "AI: Generate Transition Plan").',
                'Pick a "Linked Course", tick "Enrolments Closed" to turn off sign-ups, set the "Status", then click "Save changes".',
            ],
        'course_settings.php' => [
                'Tick Nationally Recognised to show the qualification fields for this course.',
                'Type the Qualification Code, Qualification Name, and Nominal Hours.',
                'If this course is for international students, tick CRICOS Registered and type the CRICOS Code.',
                'Click Save changes to store these settings.',
                'To issue certificates for students who have finished, click Generate Certificates for This Course.',
            ],
        'trainer_dashboard.php' => [
                'Look at the My current classes list and click View to open any course you teach.',
                'In My students, click the Full name heading to sort, then click a student\'s name to open their profile and check their ID number.',
                'Under My currency profile, click Open My Currency Record to log your qualifications and professional development.',
                'Under Validation events, click Open Validation to see the reviews you are assigned to.',
            ],
        'trainer_voccomp.php' => [
                '1. Click Add Vocational Competency Activity to open the form.',
                '2. Choose an activity type and enter the title and context, plus the related qualifications and the organisation. The title and context are required.',
                '3. Set the start date, tick Still Ongoing or set an end date, and add the total hours if you have them.',
                '4. Write the description yourself, or click AI Generate Description to draft it and then check the text.',
                '5. Choose an evidence type, click Upload Evidence to attach a file (up to 10 MB), then click Add Activity.',
            ],
        'validation_edit.php' => [
                'Type the Reference, Product Code, Product Name, and Unit Codes, then choose the Validation Type and Risk Level.',
                'Tick the validation methods used, then click AI Suggest to draft notes and check them.',
                'Choose the Risk Factors, set the Lead Validator and Panel Members, and set the Scheduled Date.',
                'For trainer-training products, complete the independence declaration and tick the confirmation before finishing.',
                'Set the Status and click Save changes.',
            ],
        'validator_edit.php' => [
                'Enter the validator\'s full name, email and phone.',
                'Tick "Internal" if they work for you, or enter their organisation if they are external.',
                'Choose the "Role type" and record their teaching qualification, the date they got it, and their other qualifications and experience.',
                'Set the "Status", add any notes, then click "Save changes".',
            ],
        'student_support.php' => [
                'Under Training Support Services, tick each support service this RTO offers.',
                'Under Reasonable Adjustments, tick the standing adjustments available to learners.',
                'Under Wellbeing Support, tick the wellbeing supports the RTO provides.',
                'Watch the dashboard update your coverage percentage as you tick items.',
                'Click the Trainer Input button to record support for individual students.',
            ],
        'student_support_input.php' => [
                'Pick a student from the "Student" dropdown to load their support records.',
                'Choose a "Record type", and set a "Category" and "Outcome" if you want.',
                'If helpful, set the reading/writing level and risk level, click "Auto Fill (AI)" to draft the notes, then read them over.',
                'Fill in the required detail field and any "Action taken", and tick "Mark as confidential" if needed.',
                'Click "Save Support Record" to store it against the student.',
            ],
        'student_declaration_send.php' => [
                'Use the filter buttons or the Search box to narrow the student list.',
                'Tick each student you want, or use the header box to select every student shown.',
                'Check the count in the bar at the bottom, then click Send Declaration to the selected students.',
            ],
        'feeprotection_edit.php' => [
                'Under Student and Course, choose the Student and their Course.',
                'In Fee Details, type the Fee Amount, choose the Fee Type and Payment Method, and set the Payment Date.',
                'Type a Receipt Reference if you have one.',
                'If this payment is protected, tick Fee Protected, choose a Protection Method, and type the policy or reference number.',
                'Click Save Fee Record.',
            ],
        'insurance_edit.php' => [
                '1. Choose the insurance type, such as Public Liability or Workers Compensation.',
                '2. Type in the provider name and policy number.',
                '3. Fill in the coverage amount, premium and excess amount.',
                '4. List the qualifications, delivery modes and locations this policy covers.',
                '5. Set the start date, expiry date and status.',
                '6. Click Save changes.',
            ],
        'thirdparty.php' => [
                '1. Read the notice at the top about written agreements and the clauses they must include.',
                '2. Click Add Arrangement to register a new third party you work with.',
                '3. In the table, check each row\'s tags for whether the regulator was told and the clauses were checked.',
                '4. Click Edit on a row to update an existing arrangement.',
            ],
        'thirdparty_edit.php' => [
                'Type the Organisation Name and choose the Arrangement Type.',
                'Add the contact details and set the Agreement Start Date.',
                'Under ASQA Notification, tick ASQA Notified and type the date if this applies.',
                'Under Mandatory Clauses, tick each clause confirmed in the written agreement.',
                'Paste the agreement link, then set the Monitoring Frequency, Risk Rating, and Status, and click Save changes.',
            ],
        'governance_edit.php' => [
                '1. Type the person\'s full name and choose their position type.',
                '2. Set the date they started, and add an end date if they have left.',
                '3. Tick the Fit and Proper Person declaration and set its date.',
                '4. Tick Suitability Assessed and type the name of the evidence document.',
                '5. Enter the police check date and its status.',
                '6. Click Save changes.',
            ],
        'governance_minutes_edit.php' => [
                'Type the Meeting Title.',
                'Choose the Meeting Type, set the Date, and add a Location if there is one.',
                'Fill in the Attendees, Agenda Items, Decisions Made, and Action Items.',
                'Fill in the Compliance section, as this is important evidence for an audit.',
                'Click Save.',
            ],
        'governance_roles_edit.php' => [
                'Type the "Role Title".',
                'Add the person in the role now, their "Department / Team" and who they "Report To".',
                'Describe the "Key Responsibilities" of the role.',
                'Fill in "How Holder is Kept Informed of Regulatory Changes" to show how they stay up to date.',
                'Set a "Review Date", then click "Save".',
            ],
        'risk_edit.php' => [
                'Enter the Risk Title and choose a Category.',
                'Describe the risk, its causes, and its effects in the Description field.',
                'Set the Likelihood and Impact to work out the risk score.',
                'Enter the Risk Owner and the Mitigation Plan.',
                'Set the Review Date and Status, then click Save Risk.',
            ],
        'rpl_edit.php' => [
                'Choose the Application Type: RPL (credit for skills you already have) or Credit Transfer.',
                'Choose the Student; the name fills in for you if you leave it blank.',
                'Type the Unit Code and Name and the Qualification Code and Name.',
                'For a Credit Transfer, type the source qualification and tick the transcript verified box, then save and re-open to upload the certificate.',
                'Choose the Assessor, write the Evidence Description, and use Add evidence row to match evidence to each requirement.',
                'Set the Decision, fill in the reason for it, then click Save Record.',
            ],
        'complaint_edit.php' => [
                'Type a Reference for this complaint (this must be filled in and not repeat another one).',
                'Choose the Complainant type, then either tick the anonymous box or type the person\'s name, email, and phone.',
                'Pick a Category, then fill in the Subject and Description.',
                'Type who the complaint is about and their response, then set the Priority, Status, Assigned to, and Date received.',
                'Fill in the resolution details, then click Save changes.',
            ],
        'appeal_edit.php' => [
                'In the Reference field, type your own short code for this appeal (any code, as long as you have not used it before).',
                'Choose the Appeal type, then type the person\'s name in the Appellant name field, plus their email and phone.',
                'Fill in the Grounds for appeal box (why they are appealing), the Original decision, and who made it.',
                'Choose a Status, set the Date lodged, and tick the box confirming the reviewer is not the person who made the first decision.',
                'Fill in the Outcome and the reason for it. If you are overturning a marking decision, tick "Underlying record corrected".',
                'Click Save changes.',
            ],
        'improvement_edit.php' => [
                'Type your own short Reference code, a Title, and a Description (all needed).',
                'Choose where the improvement came from. If it came from a complaint or a review, pick the matching one from the linked list.',
                'Choose a Category, set the Priority and Status, and set the date you identified it.',
                'Write out the Action Plan and the Outcome, and set a target date and completion date if you have them.',
                'If the fix has been checked and it worked, tick "Effectiveness verified", add the date and how you checked, then click Save changes.',
            ],
        'marketing_info.php' => [
                '1. In the checklist, tick Provided for each required item you actually tell students before they enrol.',
                '2. In the Where it is provided box, write where you give it (for example, Website plus Student Handbook version 3).',
                '3. Tick the box confirming students sign before any payment is taken.',
                '4. Set the date your marketing materials were last reviewed.',
                '5. Click Save disclosure register.',
            ],
        'marketing_cards.php' => [
                'Read the banner at the top for any issues it has found.',
                'Go through each card and click "Show Evidence" to open the linked website, handbook, or record.',
                'If the banner says a website or handbook link is missing, click "Configure RTO Website URL" and add the web addresses in Plugin Settings.',
                'On the Student Obligations card, click "Send Declaration to Students" to send the student checklist.',
                'Use the Related pages buttons to add the underlying details.',
            ],
        'suitability_view.php' => [
                'Read the summary and sections 1 to 5 covering language, digital skills, prior skills, and support needs, plus the student\'s declaration.',
                'Choose a Decision: Suitable to Enrol, Suitable with Support, or Not Currently Suitable.',
                'Write the Advice to student (this is emailed to them).',
                'Write the Trainer justification (this is kept for audit and not sent to the student).',
                'Tick the trainer declaration box, then click Save Decision and Notify Student.',
                'If you want a copy, click Download PDF Report.',
            ],
        'survey_send.php' => [
                '1. Choose who gets the survey from the Send To dropdown, such as All active students.',
                '2. If you picked a specific course, choose it in Select Course. If you are typing addresses, list one email per line.',
                '3. Edit the email subject and message, but leave the {FIRSTNAME} and {SURVEY_LINK} tags in place.',
                '4. Click Send Surveys to email the invitations.',
            ],
        'survey_responses.php' => [
                'Choose the reporting year from the dropdown at the top.',
                'Review the cards for completed responses, pending, response rate, and average satisfaction.',
                'Read the "Individual Responses" table for each person\'s name, email, satisfaction, and completion date.',
                'Click "Analyse with AI" to run the analysis, or "Back to Surveys" to return.',
            ],
        'surveys.php' => [
                'Look at the Quick Statistics cards for this year to see surveys sent, responses received, and response rates.',
                'In the Learner or Employer card, click Send Survey to invite people.',
                'Click Survey Responses to read the completed replies for that survey.',
                'Once you have replies, click Run AI Analysis on the card and confirm the 5-credit charge.',
                'Open the QI Summary Report for the yearly summary.',
            ],
        'qi_report.php' => [
                'Choose the reporting year from the dropdown at the top.',
                'Read the learner survey and employer survey scores and their tables.',
                'Check the "Competency Completion Rate" section for total enrolments, competencies achieved, and the rate.',
                'Click "View Detailed Responses" under a survey to see individual answers.',
                'Click "Export QI Report (CSV)" to download the summary.',
            ],
        'reconcile.php' => [
                'Pick the import to check from the "Select NAT Import" dropdown (use the most recent one for each year).',
                'If you want, enter student IDs in "Trace students" to see what happens to each one.',
                'If you want, attach a "Backup File CSV" and/or a "Qualification Mapping CSV" to help the check.',
                'Click "Run Reconciliation Analysis".',
                'Download the result files, such as "Missing Units", "Genuine Mismatches", "Manual Review" and "Full Audit Report".',
            ],
        'deadlines.php' => [
                'Look at the pending deadlines table and the "Days Left" badges to spot anything overdue or due soon.',
                'Click "Complete" to tick a deadline off, or "Delete" to remove one you no longer need.',
                'Under "Add New Deadline", type a Title and Description and choose a Type.',
                'Set the Due Date and how many days before you want a reminder, then tick "Recurring" and a repeat period if it happens regularly.',
                'Click "Save Deadline".',
            ],
        'alerts.php' => [
                'Click "Run Compliance Scan" to check your data and list any problems.',
                'Read the cards at the top to see how many issues are urgent or minor.',
                'Click the "All", "Critical", "High", "Medium" or "Low" tab to show only those alerts.',
                'Open an alert, read the "Recommended Action", then click "Acknowledge", "Resolve" or "Dismiss".',
            ],
        'auditlog.php' => [
                'Read down the table columns to find the entry you want: Time, User, what part of the system, the Action, and Details.',
                'Click into the Details cell to see the specifics saved with that action.',
                'Use the page buttons at the bottom to look at older entries (50 show per page).',
            ],
        'ai_analysis.php' => [
                '1. Pick the survey type (Learner Survey or Employer Survey) and the year from the two dropdown menus at the top.',
                '2. Check that the survey card shows some finished responses to work with.',
                '3. Click the Run AI Analysis button and wait about a minute for the results.',
                '4. Read the results to see what students liked, what to improve, and the main themes.',
                '5. To open a past result, click View on any row in the Previous Analyses list.',
            ],
        'ai_usage_report.php' => [
                'Pick a time period using the tabs at the top (such as Last 7 Days or All Time).',
                'Look at the summary cards to see how many AI actions ran, how many credits you have used, and how many are left.',
                'Read the "Usage by Feature" table to see which tools used your credits.',
                'Scroll down to the recent activity list to see the latest AI actions and admin actions.',
            ],
        'asqa_standards_map.php' => [
                'Look at the summary cards at the top to see how many rules are fully covered, partly covered, or missing.',
                'Go through each section row by row and read the coloured badge showing Covered, Partial, or Gap.',
                'Read the note next to a row to see what work is still needed.',
                'Click the "Open" link on a row to jump to the tool that handles that rule.',
            ],
        'compliance_map.php' => [
                'Browse the list of tools, which are grouped into sections.',
                'Read each card\'s title, short description, and rule reference.',
                'Click any card to open that tool.',
            ],
        'practice_guides.php' => [
                'Browse the guides grouped by Quality Area and pick a card (each shows its standards and how many questions it covers).',
                'On the guide page, read each self-check question and the note beside it explaining how this tool helps.',
                'Click "Open Module" on any row to jump to the feature that covers that requirement.',
                'Click "View on ASQA" or "Download PDF" to open the official guide.',
            ],
        'audit.php' => [
                '1. Pick a type of change from the Action dropdown (such as create, update, delete, export, approve or reject).',
                '2. To see just one person\'s changes, type their user ID number in the User ID box, then click Filter (or Clear to start over).',
                '3. Read the Time, User, Action and Details columns to see exactly what changed and who did it.',
                '4. Use the page buttons at the bottom to look through older entries.',
            ],
    ];
    foreach ($howrewrite as $rwk => $rwsteps) {
        if (isset($o[$rwk]) && is_array($o[$rwk])) {
            $o[$rwk]['how'] = $rwsteps;
        } else {
            $o[$rwk] = ['how' => $rwsteps];
        }
    }

    return $o;
}

/**
 * PAGE HELP CARD (v5.9.429) — map a page to the most relevant ASQA 2025 practice guide,
 * so the standard pill on the help card links straight to the official guidance. Returns
 * the full URL, or null when no single guide clearly fits the page (the pill then stays a
 * plain label). URLs are the official asqa.gov.au 2025-Standards practice-guide pages,
 * the same set referenced by the plugin's Practice Guides page.
 *
 * @param string $script e.g. 'rpl.php'
 * @return string|null
 */
/**
 * CERT-ORIENTATION-FILTER (v6.2.6): return the page orientation codes the RTO has
 * chosen to work with, from the cert_allowed_orientations multicheckbox setting.
 * Always returns a non-empty subset of ['L','P'] — if the setting is unset, empty, or
 * corrupt, it falls back to BOTH so nothing is ever hidden by accident.
 *
 * @return string[] e.g. ['L','P'], ['P'] or ['L']
 */
function local_rtocompliance_cert_allowed_orientations(): array {
    $raw = get_config('local_rtocompliance', 'cert_allowed_orientations');
    if ($raw === false || $raw === null || $raw === '') {
        return ['L', 'P'];
    }
    // configmulticheckbox stores a comma-separated list of the ticked keys.
    $picked = array_values(array_intersect(['L', 'P'], array_map('trim', explode(',', $raw))));
    return empty($picked) ? ['L', 'P'] : $picked;
}

/**
 * CERT-HEADER-THEME-COLOUR (v6.2.8): resolve the Moodle site's THEME PRIMARY (brand)
 * colour as a #rrggbb hex string, so certificate table headers (Record of Results,
 * Statement of Attainment) can match the site. Boost-derived themes expose this as the
 * theme 'brandcolor' setting; we check the active theme, then walk its parent chain, then
 * fall back to the Boost default. Always returns a valid #hex.
 *
 * @return string e.g. '#0f6cbf'
 */
function local_rtocompliance_site_primary_colour(): string {
    global $CFG, $PAGE;
    $candidates = [];
    try {
        if (!empty($PAGE->theme->name)) { $candidates[] = $PAGE->theme->name; }
    } catch (\Throwable $e) {
        // $PAGE->theme may not be initialised in some contexts (e.g. CLI/cron) — ignore.
    }
    if (!empty($CFG->theme)) { $candidates[] = $CFG->theme; }
    if (empty($candidates)) { $candidates[] = 'boost'; }

    // Expand each candidate with its parent themes so a child theme inherits the parent brand.
    $themes = [];
    foreach ($candidates as $t) {
        $themes[] = $t;
        try {
            $tc = \theme_config::load($t);
            if (!empty($tc->parents) && is_array($tc->parents)) {
                foreach ($tc->parents as $p) { $themes[] = $p; }
            }
        } catch (\Throwable $e) {
            // Unknown/broken theme name — skip parent expansion.
        }
    }
    $themes[] = 'boost';
    $themes = array_values(array_unique($themes));

    $keys = ['brandcolor', 'brandcolour', 'primarycolor', 'primarycolour', 'primary'];
    foreach ($themes as $t) {
        foreach ($keys as $k) {
            $v = get_config('theme_' . $t, $k);
            if (is_string($v) && $v !== '' && $v[0] === '#' && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                return $v;
            }
        }
    }
    return '#0f6cbf'; // Moodle Boost default primary.
}

/**
 * CERT-HEADER-THEME-COLOUR (v6.2.8): the colour to fill certificate table header bars with.
 * By default (and when the 'certheader_use_theme' setting is on or unset) this is the live
 * site theme primary colour, so headers track the site. If the admin has switched that
 * setting OFF, the explicit 'certheadercolour' custom value is used instead.
 *
 * @return string #rrggbb
 */
function local_rtocompliance_cert_header_colour(): string {
    $usetheme = get_config('local_rtocompliance', 'certheader_use_theme');
    // Unset (false) or '1' => use the site theme colour; only an explicit '0' means custom.
    if ((string) $usetheme !== '0') {
        $c = local_rtocompliance_site_primary_colour();
    } else {
        $c = (string) get_config('local_rtocompliance', 'certheadercolour');
        if (!($c !== '' && $c[0] === '#' && preg_match('/^#[0-9a-fA-F]{6}$/', $c))) {
            $c = local_rtocompliance_site_primary_colour();
        }
    }
    // HEADER-LEGIBILITY (v6.2.91): the units-table header bar prints WHITE heading text, so its
    // fill MUST be dark enough for that to read. Some themes (incl. academi) report a near-white
    // brand/primary colour, which made the header render as an invisible white bar on the issued
    // certificate. If the resolved colour is white / very light, fall back to the Moodle Boost
    // primary blue so the header is always visible. An admin can still set an explicit dark colour
    // in Certificate Settings.
    if (local_rtocompliance_colour_is_light($c)) {
        $c = '#0f6cbf';
    }
    return $c;
}

/**
 * HEADER-LEGIBILITY (v6.2.91): is this #rrggbb colour too light to carry white text? Used to keep
 * the certificate table header bar (which prints white headings) legible.
 *
 * @param  string $hex  #rrggbb
 * @return bool  true when the colour is light enough that white text would be illegible on it
 */
function local_rtocompliance_colour_is_light(string $hex): bool {
    if (!preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m)) {
        return false;
    }
    $r = hexdec($m[1]);
    $g = hexdec($m[2]);
    $b = hexdec($m[3]);
    // Relative luminance (sRGB approximation, 0..1). Above ~0.72 is too light for white text.
    $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    return $lum > 0.72;
}

/**
 * USI-VERIFIED-SEMANTICS (v6.2.8): the single source of truth for "is this student's USI
 * actually verified?". A USI counts as verified ONLY when its status is STATUS_VERIFIED (1) —
 * i.e. confirmed against the national USI Registry (usi.gov.au). Every other value is NOT
 * verified: 0 = never attempted, 2 = failed, 3 = pending/stuck (transient error, awaiting
 * retry), 4 = manual review. Historically the code tested this field with truthy/`!empty`
 * checks, which wrongly treated pending (3) as verified and overstated verification in
 * compliance reports, CSV exports and certificate-issuance gates. Route every "is verified"
 * decision through this helper so the semantics can never drift again.
 *
 * @param mixed $usiverified the raw usiverified column value (int|string|null)
 * @return bool true only when the USI is confirmed-verified
 */
function local_rtocompliance_usi_is_verified($usiverified): bool {
    return (int) $usiverified === \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED;
}

function local_rtocompliance_practice_guide_url($script) {
    // page script => practice-guide key (resolved to an official ASQA PDF below)
    $map = [
        // Quality Area 1 — Training & Assessment
        'qualbuilder.php'          => 'integrity-nationally-recognised-training-products',
        'qualbuilder_edit.php'     => 'integrity-nationally-recognised-training-products',
        'qualbuilder_unit.php'     => 'integrity-nationally-recognised-training-products',
        'qualbuilder_courses.php'  => 'practice-guide-training',
        'qualbuilder_validate.php' => 'integrity-nationally-recognised-training-products',
        'qualbuilder_results.php'  => 'practice-guide-assessment',
        'course_map.php'           => 'practice-guide-training',
        'tas.php'                  => 'practice-guide-training',
        'tas_edit.php'             => 'practice-guide-training',
        'tas_consultation.php'     => 'practice-guide-training',
        'nominalhours_import.php'  => 'practice-guide-training',
        'validation.php'           => 'practice-guide-assessment',
        'validation_edit.php'      => 'practice-guide-assessment',
        'validator_edit.php'       => 'practice-guide-assessment',
        'rpl.php'                  => 'practice-guide-recognition-prior-learning-and-credit-transfer',
        'rpl_edit.php'             => 'practice-guide-recognition-prior-learning-and-credit-transfer',
        'locations.php'            => 'practice-guide-facilities-resources-and-equipment',
        'location_edit.php'        => 'practice-guide-facilities-resources-and-equipment',
        'transitions.php'          => 'integrity-nationally-recognised-training-products',
        'transition_edit.php'      => 'integrity-nationally-recognised-training-products',
        // Certificates — integrity of issuance
        'certificates.php'         => 'integrity-nationally-recognised-training-products',
        'cert_templates.php'       => 'integrity-nationally-recognised-training-products',
        'cert_template_edit.php'   => 'integrity-nationally-recognised-training-products',
        'cert_test.php'            => 'integrity-nationally-recognised-training-products',
        'qual_cert_hub.php'        => 'integrity-nationally-recognised-training-products',
        'soa_issue.php'            => 'integrity-nationally-recognised-training-products',
        'issue_certificate.php'    => 'integrity-nationally-recognised-training-products',
        'generate_course_certs.php'=> 'integrity-nationally-recognised-training-products',
        'generate_qual_certs.php'  => 'integrity-nationally-recognised-training-products',
        'usi_settings.php'         => 'integrity-nationally-recognised-training-products',
        // Quality Area 2 — VET Student Support
        'marketing_info.php'       => 'practice-guide-information-and-transparency',
        'marketing_cards.php'      => 'practice-guide-information-and-transparency',
        'feeprotection.php'        => 'practice-guide-information-and-transparency',
        'feeprotection_edit.php'   => 'practice-guide-information-and-transparency',
        'student_declaration_send.php' => 'practice-guide-information-and-transparency',
        'student_support.php'      => 'practice-guide-training-support',
        'student_support_input.php'=> 'practice-guide-training-support',
        'suitability_bulk.php'     => 'practice-guide-training-support',
        'suitability_send.php'     => 'practice-guide-training-support',
        'suitability_view.php'     => 'practice-guide-training-support',
        'complaints.php'           => 'practice-guide-feedback-complaints-and-appeals',
        'complaint_edit.php'       => 'practice-guide-feedback-complaints-and-appeals',
        'appeal_edit.php'          => 'practice-guide-feedback-complaints-and-appeals',
        // Quality Area 3 — VET Workforce
        'trainers.php'             => 'practice-guide-trainer-and-assessor-competencies',
        'trainer_edit.php'         => 'practice-guide-trainer-and-assessor-competencies',
        'trainer_dashboard.php'    => 'practice-guide-trainer-and-assessor-competencies',
        'trainer_currency.php'     => 'practice-guide-trainer-and-assessor-competencies',
        'trainer_voccomp.php'      => 'practice-guide-trainer-and-assessor-competencies',
        'workforce_management.php' => 'practice-guide-vet-workforce-management',
        'supervision.php'          => 'practice-guide-vet-workforce-management',
        'supervision_edit.php'     => 'practice-guide-vet-workforce-management',
        // Quality Area 4 — Governance & Quality
        'governance.php'           => 'practice-guide-leadership-and-accountability',
        'governance_edit.php'      => 'practice-guide-fit-and-proper-person-requirements',
        'governance_minutes_edit.php' => 'practice-guide-leadership-and-accountability',
        'governance_roles_edit.php'   => 'practice-guide-leadership-and-accountability',
        'risk.php'                 => 'practice-guide-risk-management',
        'risk_edit.php'            => 'practice-guide-risk-management',
        'insurance.php'            => 'practice-guide-leadership-and-accountability',
        'insurance_edit.php'       => 'practice-guide-leadership-and-accountability',
        'thirdparty.php'           => 'practice-guide-accountability',
        'thirdparty_edit.php'      => 'practice-guide-accountability',
        'compliance_health.php'    => 'practice-guide-continuous-improvement',
        'improvement_edit.php'     => 'practice-guide-continuous-improvement',
        'alerts.php'               => 'practice-guide-continuous-improvement',
        'surveys.php'              => 'practice-guide-continuous-improvement',
        'survey_send.php'          => 'practice-guide-continuous-improvement',
        'survey_responses.php'     => 'practice-guide-continuous-improvement',
        'qi_report.php'            => 'practice-guide-continuous-improvement',
        'ai_analysis.php'          => 'practice-guide-continuous-improvement',
        'auditlog.php'             => 'practice-guide-accountability',
        'audit.php'                => 'practice-guide-accountability',
        // State Funding (a Plugin Settings tab) — AVETMISS funding fields, links to NCVER.
        'plugin_settings.php:local_rtocompliance_statefunding' => 'avetmiss-funding-fields',
    ];
    if (!isset($map[$script])) {
        return null;
    }
    $guidekey = $map[$script];

    // OFFICIAL-PDF-LINKS (v5.9.451): ASQA restructured their website, which broke the
    // old "/rtos/2025-standards-rtos/practice-guides/<slug>" links. Each guide key now
    // resolves to the OFFICIAL final ASQA PDF so the pill opens the actual practice
    // guide document (researched Aug 2026). Stable "/media/<id>" permalinks are used
    // where ASQA publishes them (these survive re-uploads); otherwise the current
    // dated PDF path is used. Three guides had no confirmed standalone final PDF at
    // research time and fall back to the current, stable ASQA practice-guides pages so
    // the link is never broken. Verify/refresh these if ASQA republishes.
    // LMS-LABS-MIRROR (v6.2.3): the pills now link to a stable, self-owned mirror of the official
    // ASQA guides on lms-labs.com (https://lms-labs.com/guides/<slug>.pdf), so a link never breaks
    // when ASQA restructures its site. The platform re-fetches each guide from ASQA monthly; until a
    // guide has been fetched, that URL returns a short 503 notice pointing back to the ASQA page, so
    // the link is never a hard dead-end. Each key below maps to the platform slug.
    $mirror = 'https://lms-labs.com/guides/';
    $guideurls = [
        'integrity-nationally-recognised-training-products' => $mirror . 'standards-rto-2025.pdf',
        'practice-guide-assessment'                         => $mirror . 'assessment.pdf',
        'practice-guide-recognition-prior-learning-and-credit-transfer' => $mirror . 'rpl-credit-transfer.pdf',
        'practice-guide-information-and-transparency'       => $mirror . 'information-transparency.pdf',
        'practice-guide-training-support'                   => $mirror . 'training-support.pdf',
        'practice-guide-feedback-complaints-and-appeals'    => $mirror . 'feedback-complaints-appeals.pdf',
        'practice-guide-trainer-and-assessor-competencies'  => $mirror . 'trainer-assessor-competency.pdf',
        'practice-guide-vet-workforce-management'           => $mirror . 'vet-workforce.pdf',
        'practice-guide-risk-management'                    => $mirror . 'risk-management.pdf',
        'practice-guide-fit-and-proper-person-requirements' => $mirror . 'fit-proper-person.pdf',
        'practice-guide-continuous-improvement'             => $mirror . 'continuous-improvement.pdf',
        'practice-guide-training'                           => $mirror . 'training-assessment-strategies.pdf',
        'practice-guide-facilities-resources-and-equipment' => $mirror . 'facilities-resources-equipment.pdf',
        'practice-guide-leadership-and-accountability'      => $mirror . 'leadership-accountability.pdf',
        'practice-guide-accountability'                     => $mirror . 'leadership-accountability.pdf',
        // State Funding is AVETMISS/NCVER territory, not an ASQA practice guide — point it at the
        // NCVER RTO Hub (the home of AVETMISS reporting resources and the VET Provider Collection
        // Specification that defines the funding-source fields).
        'avetmiss-funding-fields'
            => 'https://www.ncver.edu.au/rto-hub',
    ];
    if (isset($guideurls[$guidekey])) {
        return $guideurls[$guidekey];
    }
    // Safe fallback: the current, stable ASQA practice-guides hub (never a dead link).
    return 'https://www.asqa.gov.au/for-providers/standards-for-RTOs/practice-guides';
}

/**
 * PAGE HELP CARD (v5.9.425) — content registry for the What / Why / How orientation cards.
 * Keyed by script filename. Each entry: title, standard (badge, optional), what, why, how.
 * 'what'/'why' are short strings; 'how' is an array of 2–4 quick steps. A page opts into a
 * card simply by having an entry here — no per-page code change is needed.
 *
 * @return array
 */
function local_rtocompliance_page_help_content() {
    static $content = null;
    if ($content !== null) {
        return $content;
    }
    $content = [
        'compliance_health.php' => [
            'title' => 'Compliance Health',
            'standard' => 'Self-assurance · Std 4.1–4.2',
            'what' => 'Your home dashboard that gives one simple score showing how ready you are if an auditor turned up today.',
            'why' => 'It saves you digging through every list to answer one worry: are we in good shape right now?',
            'how' => [
                'Read the headline readiness score and the per-quality-area cards.',
                'Any amber or red metric has a one-click "fix" link to the page that resolves it.',
                'Work the flagged items down until the score is green.',
            ],
        ],
        'students.php' => [
            'title' => 'Student Records',
            'standard' => 'AVETMISS · Std 1.8',
            'what' => 'The master list of every student, with their personal details, USI (Unique Student Identifier) status, and a summary of their enrolments and results.',
            'why' => 'Your national VET (Vocational Education and Training) reports and certificates are only valid if student details are complete and correct here first.',
            'how' => [
                'Use the filters to find incomplete profiles or unverified USIs.',
                'Click a student name to open their full profile and pre-enrolment readiness.',
                'Verify USIs and complete missing AVETMISS fields before reporting or issuing certificates.',
            ],
        ],
        'student_support.php' => [
            'title' => 'Student Support',
            'standard' => 'Standard 2.3–2.6',
            'what' => 'This is where you record the extra help, adjustments and wellbeing services you offer students who need them.',
            'why' => 'You must find out what help each learner needs, give it, and be able to show you did.',
            'how' => [
                'Record the support services and reasonable adjustments available.',
                'Capture per-student support needs from the suitability review.',
                'Keep the record current so an auditor can see support was actually provided.',
            ],
        ],
        'qualbuilder.php' => [
            'title' => 'Qualification Builder',
            'standard' => 'Scope · Std 1.1–1.4',
            'what' => 'This is where you set up each course you\'re approved to deliver: its units, the rules for how they fit together, and which Moodle courses teach them.',
            'why' => 'Almost everything else builds on this, so if the course structure is wrong, completions, certificates and government reporting all go wrong too.',
            'how' => [
                'Add a qualification by code; the builder pulls its units and packaging rules from training.gov.au.',
                'Link each unit to the Moodle course that delivers it.',
                'Click "Build Course Map from Links" to fill the Course Map from those links — this is what makes the Course Map column match Linked Courses after creating or importing products.',
                'Nominal hours roll up automatically from the authoritative reference table.',
            ],
        ],
        'qualbuilder_results.php' => [
            'title' => 'Student Results',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'Shows which units each student has passed in a qualification, pulled together from Moodle, imports, and recognised prior skills.',
            'why' => 'It is the one place that decides who has finished and can get a certificate, and it feeds your national reporting.',
            'how' => [
                'Pick a qualification to see every student and their unit outcomes.',
                'A student competent in all required units can be issued a certificate.',
                'Record or edit outcomes here; RPL and credit transfer appear as competent.',
            ],
        ],
        'course_map.php' => [
            'title' => 'Moodle Course Map',
            'standard' => 'Delivery mapping',
            'what' => 'Where you link each online course to the real qualification and subject it teaches.',
            'why' => 'Without this link the system cannot tell which student results belong to which subject, so nothing counts.',
            'how' => [
                'Review the discovered mapping between categories, courses and units.',
                'Resolve any unmapped or ambiguous courses in the Qualification Builder.',
                'Use it to confirm the delivery structure before reporting.',
            ],
        ],
        'data_import.php' => [
            'title' => 'Data Import',
            'standard' => 'AVETMISS NAT',
            'what' => 'Where you load past student details and results from AVETMISS report files (the national VET data files) so they show up here for reporting and certificates.',
            'why' => 'It brings historical enrolments and results into the system without touching any existing Moodle accounts or enrolments.',
            'how' => [
                'Upload your NAT file set (including NAT00090/NAT00100 if you report disability and prior education).',
                'Review the student groups by qualification and semester.',
                'Confirm to write demographics, outcomes, disability and prior-education detail into your results register.',
            ],
        ],
        'nat_validate.php' => [
            'title' => 'AVETMISS Validation',
            'standard' => 'NCVER edit rules',
            'what' => 'This checks your student and enrolment data for errors before you send your official reporting files to the government.',
            'why' => 'One bad field can make the government reject your whole report, so fixing it here gets it accepted the first time.',
            'how' => [
                'Run the validation and read the "ready to submit / N errors" verdict.',
                'Fix each ERROR (they will fail NCVER); review WARNINGs.',
                'Re-run until clear, then export.',
            ],
        ],
        'natexport.php' => [
            'title' => 'NAT Export',
            'standard' => 'AVETMISS 2.3',
            'what' => 'This creates the AVETMISS report files (the national standard for VET data) that you send to your regulator or NCVER.',
            'why' => 'Sending this data is a legal requirement for every training organisation, and this makes the files in the exact format they ask for.',
            'how' => [
                'Validate first (AVETMISS Validation) to avoid rejections.',
                'Choose the reporting period and collection scope.',
                'Download the NAT file set and submit it to your regulator.',
            ],
        ],
        'certificates.php' => [
            'title' => 'Certificates',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'A list of every certificate, statement of attainment, and record of results your training organisation has issued.',
            'why' => 'Certificates are your legal proof that a student is competent, so they must be accurate and only go to genuinely qualified students.',
            'how' => [
                'Review issued certificates and their public verification tokens.',
                'Issue new certificates from Student Results or the Certificate Hub.',
                'Reissue or revoke here; revoked certificates verify as revoked publicly.',
            ],
        ],
        'cert_templates.php' => [
            'title' => 'Certificate Templates',
            'standard' => 'AQF issuance',
            'what' => 'Where you design how your certificates look, including logos, signatures and the wording that must appear.',
            'why' => 'It makes sure every certificate you hand out is correct and looks the same, without redoing the layout each time.',
            'how' => [
                'Edit a template to place your RTO branding and mandatory fields.',
                'Branding pulls automatically from your RTO / Certificate Settings.',
                'Activate a template; issued certificates snapshot it so they never change retroactively.',
            ],
        ],
        'cert_test.php' => [
            'title' => 'Test Certificate',
            'standard' => 'Template QA',
            'what' => 'Where you print a sample certificate from a template using fake student details, so you can check the layout before issuing real ones.',
            'why' => 'Spotting a branding or layout mistake on a test copy saves you reissuing real certificates to students later.',
            'how' => [
                'Pick a template and certificate type.',
                'Generate the sample and review every field and image.',
                'Return to Certificate Templates to fix anything, then re-test.',
            ],
        ],
        'qual_cert_hub.php' => [
            'title' => 'Qualification Certificate Hub',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'This is where you print and hand out full qualification certificates to many students at once.',
            'why' => 'It stops you issuing a certificate to anyone who has not truly passed or lacks a checked student number (USI, the Unique Student Identifier).',
            'how' => [
                'Search a qualification and review the Ready column.',
                'Issue individually or in bulk; students missing a USI are skipped (not failed).',
                'Add the USI, and the queued student issues automatically.',
            ],
        ],
        'soa_issue.php' => [
            'title' => 'Issue Statement of Attainment',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'This issues a Statement of Attainment (an official record of the units a student finished) for students who completed some units but not the whole qualification.',
            'why' => 'Students have a right to a record of the units they passed, even if they don\'t finish the full course.',
            'how' => [
                'Find the student (only those with recorded results appear).',
                'Select the competent units to include.',
                'Issue; the USI-verified and RTO-identity gates are enforced automatically.',
            ],
        ],
        'mycerts.php' => [
            'title' => 'My Certificates',
            'standard' => 'Learner access',
            'what' => 'The page where a student can view and download their own certificates.',
            'why' => 'Students have a right to their certificates, and letting them get their own saves your staff answering requests.',
            'how' => [
                'Students see only their own certificates here.',
                'Each certificate can be downloaded as a PDF.',
                'The public QR code lets a third party verify it.',
            ],
        ],
        'trainers.php' => [
            'title' => 'Trainers',
            'standard' => 'Standard 3.1–3.4',
            'what' => 'Your list of teachers and assessors, showing their teaching qualifications and up-to-date industry skills.',
            'why' => 'The rules say only properly qualified, current staff can teach, and this is where you prove it.',
            'how' => [
                'Add each trainer and record their TAE qualification and expiry.',
                'Capture vocational competencies and industry currency with evidence.',
                'Watch for expiry and currency warnings and act before they lapse.',
            ],
        ],
        'trainer_dashboard.php' => [
            'title' => 'Trainer Dashboard',
            'standard' => 'Standard 3',
            'what' => 'A single view for each trainer showing their qualifications, whether they are current, what they are approved to teach, and any tasks due.',
            'why' => 'It keeps you and your trainers on top of the rules about who is qualified to deliver, and any looming deadlines.',
            'how' => [
                'Select a trainer to see their credential and currency status.',
                'Follow the action prompts for anything overdue or due soon.',
                'Use it in supervision and professional-development conversations.',
            ],
        ],
        'workforce_management.php' => [
            'title' => 'Workforce Management',
            'standard' => 'Standard 3.1–3.4',
            'what' => 'This works out whether you have enough trainers and assessors for your students and workload.',
            'why' => 'You must have enough qualified staff for the students you teach, and this helps you plan and prove it.',
            'how' => [
                'Enter delivery mode, trainer/student numbers and assessment load; read the live ratio, capacity and utilisation result.',
                'List your units as "UNIT CODE | Trainer Name" to flag any unit with no nominated trainer.',
                'Use the DRAFT Workforce Planning Summary as a starting point, then confirm competency in the Trainer & Assessor Register.',
            ],
        ],
        'supervision.php' => [
            'title' => 'Supervision',
            'standard' => 'Standard 3.2',
            'what' => 'This records the supervision setup for trainers who are still finishing their teaching qualification (the TAE) and can\'t yet teach on their own.',
            'why' => 'These trainers can only teach while supervised by a fully qualified trainer, and auditors check this closely.',
            'how' => [
                'Record who supervises each working-towards trainer.',
                'Confirm the supervisor holds a full, current credential.',
                'Monitor the two-year completion deadline.',
            ],
        ],
        'validation.php' => [
            'title' => 'Assessment Validation',
            'standard' => 'Standard 1.5',
            'what' => 'Your plan and records for regularly checking that your assessments and marking are fair and correct.',
            'why' => 'The rules require you to check your assessments on a schedule and fix any problems, and auditors look closely at this.',
            'how' => [
                'Schedule validations across your products on the five-year cycle.',
                'Record the independent validator and the outcome.',
                'Capture and complete any rectification actions.',
            ],
        ],
        'rpl.php' => [
            'title' => 'RPL & Credit Transfer',
            'standard' => 'Standard 1.6–1.7',
            'what' => 'Where you record when a student is given credit for skills they already have instead of doing the training again.',
            'why' => 'Giving credit without proper evidence is risky, so this keeps the reasons and paperwork ready to show.',
            'how' => [
                'Create a record linked to the student and unit.',
                'Attach evidence, map it to the unit criteria, and record the assessor and decision.',
                'An approved decision posts the competent outcome into Student Results.',
            ],
        ],
        'marketing_info.php' => [
            'title' => 'Marketing Information',
            'standard' => 'Standard 2.1',
            'what' => 'A checklist of the information you must give people before they enrol and pay, whether you provide each item and where, plus their acknowledgement.',
            'why' => 'The rules require students to get clear, honest details about the course, all fees and refunds, and to confirm they saw them before paying.',
            'how' => [
                'Tick each mandatory item as disclosed and note where it is provided (website, handbook, enrolment form).',
                'Confirm you retain a signed fee/obligation acknowledgement taken before payment.',
                'Record the materials review date and save; the coverage bar tracks completeness.',
            ],
        ],
        'complaints.php' => [
            'title' => 'Complaints & Appeals',
            'standard' => 'Standard 2.7–2.8',
            'what' => 'This is your record of student complaints and appeals, how you handled them, and how they ended.',
            'why' => 'You must deal with complaints and appeals fairly and show everyone was told the outcome.',
            'how' => [
                'Log each complaint or appeal with dates and parties.',
                'Record the respondent, the review, and the outcome communicated.',
                'Track against the resolution timeframe so none go overdue.',
            ],
        ],
        'surveys.php' => [
            'title' => 'Quality Indicator Surveys',
            'standard' => 'Standard 4.4',
            'what' => 'This is where you send out and collect the yearly Learner and Employer surveys about the quality of your training.',
            'why' => 'You\'re required to gather this feedback and report the results every year.',
            'how' => [
                'Send the official Learner and Employer questionnaires.',
                'Monitor response rates.',
                'Use the QI Summary Report for your annual submission.',
            ],
        ],
        'qi_report.php' => [
            'title' => 'QI Summary Report',
            'standard' => 'Standard 4.4',
            'what' => 'A combined summary of your student and employer survey results for the yearly Quality Indicator report.',
            'why' => 'Your regulator wants the overall totals, not individual survey answers, so this gives you the right figures.',
            'how' => [
                'Review the learner and employer scale scores.',
                'Check response rates for representativeness.',
                'Export the summary for your regulator submission.',
            ],
        ],
        'governance.php' => [
            'title' => 'Governance',
            'standard' => 'Standard 4.1',
            'what' => 'Your list of the owners and senior people who run the training organisation, with their approval declarations.',
            'why' => 'Regulators want to know who is in charge and be told when that changes, and this keeps it all in one place.',
            'how' => [
                'Record each governing person and their fit-and-proper declaration.',
                'Log material changes and the annual declaration of compliance.',
                'Keep the register current as people and structures change.',
            ],
        ],
        'risk.php' => [
            'title' => 'Risk Management',
            'standard' => 'Standard 4.2',
            'what' => 'Your risk register, listing the things that could go wrong, how likely and serious they are, what you do about them, and when to review.',
            'why' => 'Spotting and actively managing risks keeps small problems from growing into quality or compliance failures.',
            'how' => [
                'Record each risk with its likelihood and impact.',
                'Document the mitigation and the owner.',
                'Review open risks on their review date.',
            ],
        ],
        'feeprotection.php' => [
            'title' => 'Fee Protection',
            'standard' => 'Standard 2.1',
            'what' => 'This tracks fees students paid in advance and how you keep that money safe.',
            'why' => 'If students pay you upfront above a set limit, you must protect their money and tell them how.',
            'how' => [
                'Review students with prepaid fees, including those approaching the threshold.',
                'Confirm the fee-protection measure in place.',
                'Keep the register current for audit.',
            ],
        ],
        'insurance.php' => [
            'title' => 'Insurance',
            'standard' => 'Standard 4.1',
            'what' => 'This is the list of your organisation\'s insurance policies and whether each one is still in date.',
            'why' => 'Having proper, up-to-date insurance is part of proving your organisation is well run and financially sound.',
            'how' => [
                'Record each policy, its cover and expiry.',
                'Watch for policies approaching expiry.',
                'Attach or reference the certificate of currency.',
            ],
        ],
        'thirdparty.php' => [
            'title' => 'Third-Party Arrangements',
            'standard' => 'Standard 4.1',
            'what' => 'A list of the outside organisations that deliver training or services for you, and the written agreements with them.',
            'why' => 'You are still responsible for training others deliver on your behalf, so you must have signed agreements and keep oversight.',
            'how' => [
                'Record each third party and the written agreement.',
                'Confirm the RTO retains quality oversight and NRT-logo/AQF controls.',
                'Ensure students are told when a third party is involved.',
            ],
        ],
        'transitions.php' => [
            'title' => 'Training Product Transitions',
            'standard' => 'Standard 1.4',
            'what' => 'Where you track courses that have been replaced by a newer version and move students over in time.',
            'why' => 'When a course is retired you usually have twelve months to switch students across, and missing that causes problems.',
            'how' => [
                'Review products flagged as superseded.',
                'Plan the teach-out or transition of affected students.',
                'Update your scope and delivery to the current product.',
            ],
        ],
        'asqa_standards_map.php' => [
            'title' => 'ASQA Compliance Mapping',
            'standard' => '2025 Standards',
            'what' => 'A table linking each 2025 Standard for RTOs to the plugin feature that supports it, marked Covered, Partial, or Gap.',
            'why' => 'It shows exactly where your proof for each rule lives, which saves hours when preparing for an audit or self-check.',
            'how' => [
                'Browse the Standards and the feature that addresses each.',
                'Follow the link to the page that holds the evidence.',
                'Note any Partial or Gap items to work on.',
            ],
        ],
        'tas.php' => [
            'title' => 'Training & Assessment Strategies',
            'standard' => 'Standard 1.1–1.2',
            'what' => 'This holds your training and assessment strategies (TAS): the plan for how each course is taught, assessed, resourced and timed.',
            'why' => 'A current plan for each course is a core rule and one of the first things an auditor asks to see.',
            'how' => [
                'Create a TAS per qualification you deliver.',
                'Complete all sections; nominal hours and volume of learning pre-fill from your data.',
                'Review and version it when the product or delivery changes.',
            ],
        ],
        'usi_settings.php' => [
            'title' => 'USI Settings',
            'standard' => 'Clause 12',
            'what' => 'This connects you to the national USI system (Unique Student Identifier, the ID number every student needs) so you can check a student\'s USI before issuing a certificate.',
            'why' => 'By law you can\'t issue a certificate without a verified USI, and this is what makes those checks possible.',
            'how' => [
                'Confirm the verification status shown here; the machine credential itself is configured on the lms-labs.com platform, not on this page.',
                'Confirm the connection reports as ready.',
                'Students\' USIs then verify automatically where DOB is present.',
            ],
        ],
        'deadlines.php' => [
            'title' => 'Upcoming Deadlines',
            'standard' => 'Self-assurance',
            'what' => 'One calendar showing all your compliance due dates, like validations, staff credential expiries, and reporting dates.',
            'why' => 'A missed due date is an easy way to breach the rules, and seeing them together helps you stay ahead.',
            'how' => [
                'Scan what is due soon and what is overdue.',
                'Click through to the page that resolves each item.',
                'Check it regularly as part of your routine.',
            ],
        ],
        'locations.php' => [
            'title' => 'Delivery Locations',
            'standard' => 'Scope',
            'what' => 'Your list of the places, in person or online, where you deliver training.',
            'why' => 'These locations are part of your official registration and national reporting, so they must be right.',
            'how' => [
                'Record each delivery site and its details.',
                'Keep it aligned with your scope of registration.',
                'Reference locations in AVETMISS reporting.',
            ],
        ],
        'reconcile.php' => [
            'title' => 'NAT Reconciliation',
            'standard' => 'AVETMISS',
            'what' => 'Where you compare your imported national VET records against what happened in Moodle, to spot gaps and mismatches before you report.',
            'why' => 'Checking this prevents reporting too few or too many enrolments in your national VET submission.',
            'how' => [
                'Run the reconciliation for the collection period.',
                'Review the trace of matched and unmatched records.',
                'Resolve gaps in the Qualification Builder or Student Results.',
            ],
        ],
        'nominalhours_import.php' => [
            'title' => 'Import Nominal Hours',
            'standard' => 'AVETMISS NAT00120',
            'what' => 'This loads the official list of set teaching hours for each unit into the plugin in bulk.',
            'why' => 'These hours drive your government reports and course planning, and there is no other single source for them.',
            'how' => [
                'Download the NCVER nationally-agreed nominal-hours file.',
                'Upload it here; the importer upserts one value per unit per jurisdiction.',
                'The Qualification Builder and TAS then resolve hours automatically.',
            ],
        ],
        'course_settings.php' => [
            'title' => 'Course Compliance Settings',
            'standard' => 'Configuration',
            'what' => 'This sets the compliance details for one Moodle course: whether it\'s nationally recognised training, its qualification code, name, hours, and any overseas-student (CRICOS) registration.',
            'why' => 'Getting these details right is what lets student completions count properly and certificates be issued from that course.',
            'how' => [
                'Tick "Nationally Recognised" and enter the qualification code, name and nominal hours for this course.',
                'Set the CRICOS registration and code if the course is delivered to overseas students.',
                'Use "Generate Certificates for This Course" to issue from here. (Org-wide legal identity, branding and USI live in Plugin Settings, not this page.)',
            ],
        ],

        // ── Qualification Builder sub-pages ──────────────────────────────
        'qualbuilder_edit.php' => [
            'title' => 'Add / Edit Qualification',
            'standard' => 'Scope · Std 1.1',
            'what' => 'Where you set up a course: its code, units, rules, hours, and the Moodle courses that teach each unit.',
            'why' => 'Getting this setup right is what lets the system spot who has finished, decide certificates, and report data correctly.',
            'how' => [
                'Enter the qualification code and fetch its units from training.gov.au.',
                'Select the semester, then link each unit to its Moodle course.',
                'Review the rolled-up nominal-hours total and save.',
            ],
        ],
        'qualbuilder_unit.php' => [
            'title' => 'Add / Edit Unit',
            'standard' => 'Scope · Std 1.1',
            'what' => 'Where you add or edit a single subject inside a qualification, including its code, name and study hours.',
            'why' => 'Getting each subject right means student passes count toward the qualification and the hours report correctly.',
            'how' => [
                'Enter or confirm the unit code and name.',
                'Set the unit type and elective group if applicable.',
                'Nominal hours auto-fill from the reference table; link the delivery course and save.',
            ],
        ],
        'qualbuilder_courses.php' => [
            'title' => 'Link Units to Courses',
            'standard' => 'Delivery mapping',
            'what' => 'Where you link each unit of competency to the Moodle course that teaches it.',
            'why' => 'The plugin reads course completions to mark a unit complete, so an unlinked unit can never be marked off automatically.',
            'how' => [
                'For each unit, choose the Moodle course that teaches it.',
                'Add variant courses where the same unit runs in multiple streams.',
                'Save so the reconciler watches every linked course.',
            ],
        ],
        'qualbuilder_validate.php' => [
            'title' => 'Check Packaging Rules',
            'standard' => 'Scope · Std 1.1',
            'what' => 'This checks that the units you picked add up to a real qualification under the training package rules.',
            'why' => 'Giving out a qualification with the wrong mix of units is a serious compliance mistake.',
            'how' => [
                'Run the check for the qualification.',
                'A green pass confirms the combination is valid for certification.',
                'A red fail shows exactly which rule is not met — fix the unit selection.',
            ],
        ],

        // ── Students & support sub-pages ─────────────────────────────────
        'student_profile.php' => [
            'title' => 'Student Profile',
            'standard' => 'AVETMISS · Std 1.8',
            'what' => 'This is one student\'s full record in one place: their personal and reporting details, USI (their student ID number) status, unit results and any certificates issued.',
            'why' => 'Complete, correct student information is what makes your government reporting valid and your certificates trustworthy.',
            'how' => [
                'Review the readiness and completeness cards for any gaps.',
                'Complete missing AVETMISS fields and verify the USI.',
                'Save; the profile feeds Student Results, certificates and reporting.',
            ],
        ],
        'student_enrolments.php' => [
            'title' => 'Student Enrolments',
            'standard' => 'AVETMISS NAT00120',
            'what' => 'A student\'s unit-by-unit enrolment records, showing their result, how they studied, dates, and hours.',
            'why' => 'These records are exactly what your national data reports contain, so they need to be accurate and complete.',
            'how' => [
                'Review each unit enrolment and its outcome.',
                'Scheduled hours default from the authoritative nominal hours when left blank.',
                'Correct any dates or outcomes before reporting.',
            ],
        ],
        'student_support_input.php' => [
            'title' => 'Student Support Input',
            'standard' => 'Standard 2.3–2.6',
            'what' => 'Where a teacher notes the extra help or adjustments a student was given.',
            'why' => 'The rules say you must spot what a student needs and show the help you actually gave them.',
            'how' => [
                'Record the support needs and reasonable adjustments for the student.',
                'Note wellbeing referrals where relevant.',
                'Save; the record is retained and auditable.',
            ],
        ],
        'my_profile.php' => [
            'title' => 'My AVETMISS Profile',
            'standard' => 'Learner data',
            'what' => 'The form where students fill in and check their own personal details and USI (Unique Student Identifier).',
            'why' => 'When students supply their own details it means less chasing for you and cleaner data for your national VET reports.',
            'how' => [
                'Complete every highlighted mandatory field.',
                'Enter or confirm your USI.',
                'Save so your record is ready for reporting and certificates.',
            ],
        ],
        'mydocs.php' => [
            'title' => 'My Documents',
            'standard' => 'Learner access',
            'what' => 'This is the student\'s own area where they can view documents you have shared with them.',
            'why' => 'Letting students get their own documents saves time and cuts down admin requests.',
            'how' => [
                'Browse the documents shared with you.',
                'Download any document you need.',
                'Check back when new documents are issued.',
            ],
        ],
        'marketing_cards.php' => [
            'title' => 'Pre-Enrolment Information',
            'standard' => 'Standard 2.1',
            'what' => 'This is the information you show people before they enrol, covering the training, fees, their obligations and likely outcomes.',
            'why' => 'You\'re required to give people accurate, clear information before they sign up so they know what they\'re committing to.',
            'how' => [
                'Review each information card for accuracy and currency.',
                'Ensure fees, refunds, support and complaints information are covered.',
                'Keep the set reviewed and version-controlled.',
            ],
        ],
        'suitability_bulk.php' => [
            'title' => 'Send Suitability Reviews',
            'standard' => 'Standard 2.2',
            'what' => 'Sends the before-you-start suitability check to a group of students at the same time.',
            'why' => 'You must check each student is ready for the course before they enrol, and doing it in bulk makes sure no one is missed.',
            'how' => [
                'Select the students to send the review to.',
                'Send; each student receives a secure form link.',
                'Track completion on the suitability records.',
            ],
        ],
        'suitability_send.php' => [
            'title' => 'Send Suitability Review',
            'standard' => 'Standard 2.2',
            'what' => 'Where you send one student the pre-enrolment check that asks if a course is right for them.',
            'why' => 'It gives you proof that you checked a course suited the student before they signed up.',
            'how' => [
                'Confirm the student and qualification.',
                'Send the secure form link.',
                'Review the outcome and any support needs when completed.',
            ],
        ],
        'suitability_view.php' => [
            'title' => 'Suitability Review',
            'standard' => 'Standard 2.2',
            'what' => 'The completed check of whether a student is ready for the course, covering their schooling, reading and maths level, computer skills, support needs, and the trainer\'s decision.',
            'why' => 'It is your proof that the student was assessed as suitable, with support if needed, before they enrolled.',
            'how' => [
                'Read the student\'s responses and self-reported levels.',
                'Record the trainer decision and any support to be provided.',
                'Record the decision, advice and justification, then tick the declaration to finalise.',
            ],
        ],
        'student_declaration_send.php' => [
            'title' => 'Send Student Declaration',
            'standard' => 'Standard 2.1',
            'what' => 'This sends a student the pre-enrolment form to sign, confirming they understood the course information.',
            'why' => 'A signed form proves the student made an informed choice before enrolling.',
            'how' => [
                'Confirm the student and the information provided.',
                'Send the declaration for signature.',
                'The signed declaration appears on the student\'s pre-enrolment readiness.',
            ],
        ],

        // ── Workforce sub-pages ──────────────────────────────────────────
        'trainer_edit.php' => [
            'title' => 'Add / Edit Trainer',
            'standard' => 'Standard 3.1',
            'what' => 'This is a trainer or assessor\'s record: their teaching qualification and its expiry, plus their industry skills and how current they are.',
            'why' => 'Training must be delivered by properly qualified, up-to-date people, and this keeps the evidence on file.',
            'how' => [
                'Record the TAE qualification and its expiry.',
                'Add vocational competencies and industry currency with evidence.',
                'Save; expiry and currency warnings then track automatically.',
            ],
        ],
        'trainer_currency.php' => [
            'title' => 'Industry Currency',
            'standard' => 'Standard 3.1',
            'what' => 'A record of a trainer\'s up-to-date, real-world industry skills, with evidence to back it up.',
            'why' => 'Trainers must keep current industry skills, not just a teaching qualification, and auditors often check this.',
            'how' => [
                'Describe the recent industry engagement or activity.',
                'Attach the supporting evidence file.',
                'Keep it current so the currency status stays green.',
            ],
        ],
        'trainer_voccomp.php' => [
            'title' => 'Vocational Competency',
            'standard' => 'Standard 3.1',
            'what' => 'Where you record the hands-on skills a trainer has, matched to the subjects they teach, with proof.',
            'why' => 'A trainer must actually hold the skills for every subject they teach or assess, and this shows it.',
            'how' => [
                'Record the qualifications and competencies the trainer holds.',
                'Attach evidence of each.',
                'Confirm they cover the units the trainer delivers.',
            ],
        ],
        'supervision_edit.php' => [
            'title' => 'Edit Supervision',
            'standard' => 'Standard 3.2',
            'what' => 'The supervision setup for a trainer who does not yet hold the full trainer qualification and teaches under someone who does.',
            'why' => 'A not-yet-qualified trainer may only teach while supervised, and must finish their trainer qualification within two years.',
            'how' => [
                'Assign a supervisor who holds a full, current credential.',
                'Record the arrangement and the TAE completion deadline.',
                'Monitor the two-year window.',
            ],
        ],
        'validation_edit.php' => [
            'title' => 'Record a Validation',
            'standard' => 'Standard 1.5',
            'what' => 'This records one assessment check: the course, the independent reviewers, what they found, and any fixes made.',
            'why' => 'You must have someone independent regularly check your assessments and act on what they find.',
            'how' => [
                'Record the product validated and the validators (confirming independence).',
                'Capture the outcome and any issues found.',
                'Log rectification actions and complete them.',
            ],
        ],
        'validator_edit.php' => [
            'title' => 'Edit Validator',
            'standard' => 'Standard 1.5',
            'what' => 'This is the record for one person on your validation panel and the qualifications that make them fit to check your assessments.',
            'why' => 'Assessment checks only count when done by people with the right skills who weren\'t involved in the original marking.',
            'how' => [
                'Record the validator\'s name and credentials.',
                'Note their independence from the delivery being validated.',
                'Save so they can be selected on a validation record.',
            ],
        ],

        // ── RPL / register edit pages ────────────────────────────────────
        'rpl_edit.php' => [
            'title' => 'RPL / Credit Transfer Record',
            'standard' => 'Standard 1.6–1.7',
            'what' => 'One record of giving a student credit for skills they already have, showing the evidence, assessor, and result.',
            'why' => 'Awarding a unit without teaching it must be justified, so the evidence and student notification all need recording.',
            'how' => [
                'Link the student and unit, and choose the assessor (their TAE currency is checked).',
                'Attach evidence, complete the evidence-to-criteria matrix, and record any superseded-unit mapping.',
                'Set the decision and tick "outcome communicated"; approval posts the outcome to Student Results.',
            ],
        ],
        'complaint_edit.php' => [
            'title' => 'Complaint Record',
            'standard' => 'Standard 2.7',
            'what' => 'Where you record a single complaint: who was involved, what happened, and how it was sorted out.',
            'why' => 'You must handle complaints fairly and on time, and show everyone was told the result.',
            'how' => [
                'Record the complainant, respondent and the issue.',
                'Log the handling steps and the resolution.',
                'Record the outcome communicated and the date.',
            ],
        ],
        'appeal_edit.php' => [
            'title' => 'Appeal Record',
            'standard' => 'Standard 2.8',
            'what' => 'One appeal record showing the original decision, who independently reviewed it, and whether the record was corrected.',
            'why' => 'Appeals must be reviewed by someone independent, and a successful appeal means the original result must be fixed.',
            'how' => [
                'Record the decision being appealed and the reviewer (confirming independence).',
                'Capture the review outcome.',
                'If upheld, correct the underlying assessment record.',
            ],
        ],
        'improvement_edit.php' => [
            'title' => 'Continuous Improvement',
            'standard' => 'Standard 4.1–4.2',
            'what' => 'This records one improvement: a problem or idea, what you did about it, and the result.',
            'why' => 'Always looking for ways to improve is central to running a quality RTO and showing it works.',
            'how' => [
                'Record the issue or opportunity and its source.',
                'Document the action and who is responsible.',
                'Close it out with the result achieved.',
            ],
        ],

        // ── Governance sub-pages ─────────────────────────────────────────
        'governance_edit.php' => [
            'title' => 'Governance Record',
            'standard' => 'Standard 4.1',
            'what' => 'This records a person in charge, a major change, or a yearly compliance declaration in your governance register.',
            'why' => 'The regulator requires suitable, accountable people running your organisation and expects to be told about major changes.',
            'how' => [
                'Record the governing person and their fit-and-proper declaration, or the material change.',
                'Capture dates and supporting detail.',
                'Keep the register current as people and structures change.',
            ],
        ],
        'governance_minutes_edit.php' => [
            'title' => 'Governance Minutes',
            'standard' => 'Standard 4.1',
            'what' => 'The written minutes of one of your organisation\'s management or governance meetings.',
            'why' => 'Minutes prove your leadership is actively meeting, making decisions, and recording them.',
            'how' => [
                'Record the meeting date and attendees.',
                'Capture the decisions and actions.',
                'Save as governance evidence.',
            ],
        ],
        'governance_roles_edit.php' => [
            'title' => 'Governance Roles',
            'standard' => 'Standard 4.1',
            'what' => 'Where you set out the jobs and responsibilities of the people who run your organisation.',
            'why' => 'Making clear who is responsible for what is part of showing the organisation is well run.',
            'how' => [
                'Define each role and who holds it.',
                'Record the responsibilities attached to it.',
                'Keep it aligned with your actual structure.',
            ],
        ],

        // ── Other register edit pages ────────────────────────────────────
        'risk_edit.php' => [
            'title' => 'Risk Record',
            'standard' => 'Standard 4.2',
            'what' => 'One risk in detail: how likely and serious it is, what you do about it, who owns it, and when to review it.',
            'why' => 'Managing each risk actively keeps small problems from growing into quality or compliance failures.',
            'how' => [
                'Describe the risk and rate its likelihood and impact.',
                'Record the mitigation and the owner.',
                'Set a review date and revisit it.',
            ],
        ],
        'feeprotection_edit.php' => [
            'title' => 'Fee Record',
            'standard' => 'Standard 2.1',
            'what' => 'This is one student\'s record of fees paid in advance and how that money is kept safe.',
            'why' => 'Fees paid upfront above a set limit must be protected, and the student must be told how.',
            'how' => [
                'Record the prepaid amount and the protection measure.',
                'Confirm the student was informed.',
                'Keep it current for audit.',
            ],
        ],
        'insurance_edit.php' => [
            'title' => 'Insurance Policy',
            'standard' => 'Standard 4.1',
            'what' => 'This is where you add or edit one insurance policy: what it covers, who provides it and when it expires.',
            'why' => 'Keeping insurance current and adequate is part of proving your organisation is well run and financially sound.',
            'how' => [
                'Record the policy, cover and expiry.',
                'Attach or reference the certificate of currency.',
                'Renew before expiry.',
            ],
        ],
        'thirdparty_edit.php' => [
            'title' => 'Third-Party Arrangement',
            'standard' => 'Standard 4.1',
            'what' => 'The details of one outside organisation delivering training or services for you, and its written agreement.',
            'why' => 'You stay responsible for what they deliver, so you must have a written agreement and keep oversight of their work.',
            'how' => [
                'Record the third party and the written agreement.',
                'Confirm quality oversight and NRT-logo / AQF controls.',
                'Ensure students are told when a third party is involved.',
            ],
        ],
        'transition_edit.php' => [
            'title' => 'Product Transition',
            'standard' => 'Standard 1.4',
            'what' => 'Where you write the plan for moving students off a retired course onto its newer version.',
            'why' => 'When a course is replaced you must move students across in time, and this keeps that plan on record.',
            'how' => [
                'Record the superseded product and the current replacement.',
                'Set the teach-out end date.',
                'Document how affected students complete or transition.',
            ],
        ],
        'location_edit.php' => [
            'title' => 'Delivery Location',
            'standard' => 'Scope',
            'what' => 'The details for one place you deliver training, whether a campus, a workplace, or online.',
            'why' => 'Your delivery locations are part of your registration and national VET reporting, so they must be accurate.',
            'how' => [
                'Record the location and its details.',
                'Note the environment type and any WHS status.',
                'Keep it aligned with your scope.',
            ],
        ],

        // ── Certificate sub-pages ────────────────────────────────────────
        'issue_certificate.php' => [
            'title' => 'Issue Certificate',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'This gives one student their certificate for a completed qualification or set of units.',
            'why' => 'A certificate is your legal proof a student is competent, so it can only go to someone who truly passed and has a checked student number (USI).',
            'how' => [
                'Confirm the student and what they have completed.',
                'The verified-USI and RTO-identity gates are enforced automatically.',
                'Issue; the certificate is registered and independently verifiable.',
            ],
        ],
        'cert_template_edit.php' => [
            'title' => 'Certificate Template Editor',
            'standard' => 'AQF issuance',
            'what' => 'This is the drag-and-drop designer for how a certificate looks: your branding, signatures, required wording and the verification code.',
            'why' => 'There are set rules for what must appear on each certificate, and this makes sure every one you issue meets them.',
            'how' => [
                'Place fields and branding; images pull from your RTO / Certificate Settings.',
                'Keep the mandatory statements and QR in place.',
                'Save and activate; issued certificates snapshot the template.',
            ],
        ],
        'email_cert.php' => [
            'title' => 'Email Certificate',
            'standard' => 'Learner access',
            'what' => 'Emails a student their certificate as a PDF.',
            'why' => 'Getting the certificate to the student quickly makes finishing the course a smooth experience.',
            'how' => [
                'Confirm the student and certificate.',
                'Send; the PDF is attached to the email.',
                'The student can also self-serve from My Certificates.',
            ],
        ],
        'generate_course_certs.php' => [
            'title' => 'Generate Certificates by Course',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'Where you print certificates for a whole class of finished students in one go.',
            'why' => 'It saves issuing them one by one while still checking each student actually passed and has a valid student number.',
            'how' => [
                'Select the course and review the eligible students.',
                'Students missing a USI are skipped, not failed.',
                'Generate; certificates are registered and verifiable.',
            ],
        ],
        'generate_qual_certs.php' => [
            'title' => 'Generate Certificates by Qualification',
            'standard' => 'Std 1.8 · Clause 12',
            'what' => 'Where you print full qualification certificates in bulk for students who have passed every required unit.',
            'why' => 'It issues certificates quickly while only allowing genuinely finished, USI-verified students through.',
            'how' => [
                'Select the qualification and review who is ready.',
                'Students missing a USI are skipped and issue later automatically.',
                'Generate; the register and verification update.',
            ],
        ],

        // ── Surveys / QI sub-pages ───────────────────────────────────────
        'survey_send.php' => [
            'title' => 'Send Survey',
            'standard' => 'Standard 4.4',
            'what' => 'This sends the official learner or employer satisfaction survey to the right people.',
            'why' => 'You must collect learner and employer feedback each year for your required quality reporting.',
            'how' => [
                'Choose the questionnaire and recipients.',
                'Send the secure survey links.',
                'Track responses on Survey Responses.',
            ],
        ],
        'survey_responses.php' => [
            'title' => 'Survey Responses',
            'standard' => 'Standard 4.4',
            'what' => 'This shows the answers you collected from your Learner and Employer surveys, plus how many people replied.',
            'why' => 'Having enough real responses is what makes your yearly quality report believable.',
            'how' => [
                'Review responses and the response rate.',
                'Follow up where the rate is low.',
                'Use the QI Summary Report for your submission.',
            ],
        ],

        // ── TAS sub-pages ────────────────────────────────────────────────
        'tas_edit.php' => [
            'title' => 'Edit Training & Assessment Strategy',
            'standard' => 'Standard 1.1–1.2',
            'what' => 'The full training and assessment strategy for one course, covering delivery, assessment, resources, and study hours.',
            'why' => 'An up-to-date strategy for each course is a core requirement and one of the first documents auditors ask to see.',
            'how' => [
                'Complete each of the nine sections (the progress bar tracks you).',
                'Nominal hours and volume of learning pre-fill from your data.',
                'Preview, then export to HTML or PDF as audit evidence.',
            ],
        ],
        'tas_consultation.php' => [
            'title' => 'Industry Consultation',
            'standard' => 'Standard 1.3',
            'what' => 'Where you record talking to employers and industry to shape how a course is taught and assessed.',
            'why' => 'The rules say industry input must guide your training, and this is where you prove that happened.',
            'how' => [
                'Record who you consulted and when.',
                'Capture the feedback and how it shaped delivery/assessment.',
                'Keep it with the TAS as evidence.',
            ],
        ],

        // ── Reporting / admin sub-pages ──────────────────────────────────
        'alerts.php' => [
            'title' => 'Compliance Alerts',
            'standard' => 'Self-assurance',
            'what' => 'An on-demand scan that flags compliance problems needing attention, which you can then acknowledge, resolve, or dismiss.',
            'why' => 'Finding issues yourself means they get fixed before they turn into audit findings.',
            'how' => [
                'Click "Run Compliance Scan" to check for current risks.',
                'Work each alert with Acknowledge, Resolve or Dismiss.',
                'Re-run the scan after fixing issues to confirm they clear.',
            ],
        ],
        'ai_analysis.php' => [
            'title' => 'AI Survey Analysis',
            'standard' => 'Standard 4.4',
            'what' => 'This uses AI to read your survey comments and pull out the main themes and mood.',
            'why' => 'Turning lots of written comments into clear themes helps you act on feedback and improve.',
            'how' => [
                'Select the survey set to analyse.',
                'Review the surfaced themes and sentiment.',
                'Feed insights into your continuous-improvement register.',
            ],
        ],
        'ai_usage_report.php' => [
            'title' => 'AI Usage Report',
            'standard' => 'Transparency',
            'what' => 'This shows which AI-assisted features in the plugin have been used and how much.',
            'why' => 'Seeing where AI was used keeps things open and helps you keep proper oversight.',
            'how' => [
                'Review where AI assistance has been used.',
                'Use it for internal oversight and reporting.',
            ],
        ],
        'auditlog.php' => [
            'title' => 'Audit Log',
            'standard' => 'Integrity',
            'what' => 'A time-stamped list of every time a compliance record was created, changed, or deleted in the plugin.',
            'why' => 'This trail proves your records were not quietly altered, which your regulator expects to see.',
            'how' => [
                'Filter the log by record type, user or date.',
                'Use it to trace who changed what and when.',
            ],
        ],
        'audit.php' => [
            'title' => 'Audit Log',
            'standard' => 'Integrity',
            'what' => 'A time-stamped history of every change made to your records in this tool.',
            'why' => 'It proves nothing was quietly changed, so your records can be trusted.',
            'how' => [
                'Filter by Entity Type (Student, Certificate, TAS, Trainer…), Action or User ID.',
                'Trace who changed what and when.',
            ],
        ],
        'practice_guides.php' => [
            'title' => 'Practice Guides',
            'standard' => 'Reference',
            'what' => 'A plain-English library summarising the regulator\'s practice guides for each part of the plugin.',
            'why' => 'Understanding what each rule is really for helps you meet it properly, not just tick a box.',
            'how' => [
                'Browse the guide relevant to your task.',
                'Follow the links to the pages that hold the evidence.',
            ],
        ],
        'plugin_settings.php' => [
            'title' => 'Plugin Settings',
            'standard' => 'Configuration',
            'what' => 'This is where you set up how the plugin works: its links to other systems, defaults and options.',
            'why' => 'Getting these settings right makes the plugin behave the way your RTO needs on every page.',
            'how' => [
                'Use the tabs (RTO Details, Platform API, Certificates, USI Settings, and more) to reach each area.',
                'Adjust the settings on the active tab.',
                'Click Save changes; they apply across the plugin.',
            ],
        ],
        // Per-tab cards for the settings page (resolved via ?section=).
        'plugin_settings.php:local_rtocompliance_settings' => [
            'title' => 'RTO Details', 'standard' => 'Configuration',
            'what' => 'This holds your organisation\'s identity: legal name, provider code (your official RTO number), the person who signs off, and contact details.',
            'why' => 'These print on every certificate and show up across reports, so they must be exact, and certificates can\'t be issued while the required ones are blank.',
            'how' => ['Enter your legal name and national provider code exactly as registered.', 'Set the authorised signatory name and title.', 'Click Save changes.'],
        ],
        'plugin_settings.php:local_rtocompliance_api' => [
            'title' => 'Platform API', 'standard' => 'Integration',
            'what' => 'The connection settings that link this plugin to the RTO Compliance platform, using a web address, Site ID, and key.',
            'why' => 'Some features, like checking a student\'s Unique Student Identifier and credit balance, only work once this is set up.',
            'how' => ['Enter the API URL, Site ID and API Key from your platform account.', 'Save changes.', 'Confirm the credit balance appears in the sidebar.'],
        ],
        'plugin_settings.php:local_rtocompliance_certs' => [
            'title' => 'Certificate Settings', 'standard' => 'AQF issuance',
            'what' => 'Where you set the default look and required wording used on every certificate you issue.',
            'why' => 'Setting these once means every certificate starts correct and on-brand without you redoing it each time.',
            'how' => ['Upload your logos, signatory signature and organisation seal.', 'Set the certificate number prefix and mandatory statements.', 'Save; the certificate templates and editor pick them up.'],
        ],
        'plugin_settings.php:local_rtocompliance_usi' => [
            'title' => 'USI Settings', 'standard' => 'Clause 12',
            'what' => 'Where you set up how the plugin checks each student\'s USI (Unique Student Identifier) against the national registry.',
            'why' => 'A verified USI is legally required before you can issue any nationally recognised certificate.',
            'how' => ['Confirm the USI verification method and configuration.', 'Save changes.', 'Students with a USI and DOB then verify automatically.'],
        ],
        'plugin_settings.php:local_rtocompliance_autosurvey' => [
            'title' => 'Auto-Survey', 'standard' => 'Standard 4.4',
            'what' => 'This sets the surveys to send themselves automatically to learners and employers.',
            'why' => 'Sending surveys automatically gets more replies and keeps your yearly feedback data up to date.',
            'how' => ['Enable auto-survey and set the timing/triggers.', 'Confirm the recipient rules.', 'Save changes.'],
        ],
        'plugin_settings.php:local_rtocompliance_asqa2025' => [
            'title' => 'ASQA 2025', 'standard' => '2025 Standards',
            'what' => 'This is where you switch on and adjust the features for the 2025 training standards, such as self-checks and compliance alerts.',
            'why' => 'These settings control how the plugin helps you meet the newer 2025 rules for training organisations.',
            'how' => ['Review each 2025-Standards option.', 'Adjust to your self-assurance approach.', 'Save changes.'],
        ],
        'plugin_settings.php:local_rtocompliance_statefunding' => [
            'title' => 'State Funding', 'standard' => 'AVETMISS below-the-line',
            'what' => 'Default funding details for each state, used to fill the government-funding fields in your national data reports.',
            'why' => 'Funded training needs state-specific codes, so setting defaults here fills them in automatically and cuts down mistakes.',
            'how' => ['Complete only the states where your RTO holds a funded contract.', 'Enter the STA identifier and purchasing contract codes.', 'Save; trainers can override per enrolment.'],
        ],
        'plugin_settings.php:local_rtocompliance_maintenance' => [
            'title' => 'Maintenance', 'standard' => 'Administration',
            'what' => 'Where you run tidy-up tasks that refresh the tool\'s saved data.',
            'why' => 'Running these now and then keeps figures and links accurate after big imports or setting changes.',
            'how' => ['Read each maintenance action before running it.', 'Run the relevant tool (e.g. rebuild caches after a big import).', 'Purge all caches afterwards if prompted.'],
        ],
        'compliance_map.php' => [
            'title' => 'Compliance Modules',
            'standard' => '2025 Standards',
            'what' => 'An overview of the plugin\'s compliance tools, grouped by quality area.',
            'why' => 'It shows you everything the plugin covers and lets you jump straight to the right tool.',
            'how' => [
                'Browse the modules by quality area.',
                'Open the module you need.',
                'For a Standard-by-Standard view, use ASQA Compliance Mapping.',
            ],
        ],
    ];
    return $content;
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
 * lms-labs.com/verify/<token> independently of the RTO's Moodle server.
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

/**
 * Return the upload/configuration status of every branding asset used across
 * certificate pages (cert_templates.php, cert_template_edit.php, etc.).
 *
 * Centralising this check means all certificate pages share identical logic —
 * any new asset can be added here once and all pages stay in sync.
 *
 * Keys and what they represent:
 *  - logo        : RTO logo image uploaded via the Branding panel
 *  - signature   : CEO / authorised-signatory signature image uploaded
 *  - seal        : Organisation seal image uploaded
 *  - nrt_override: NRT logo image uploaded (required for AQF certificates,
 *                  i.e. testamur and statement cert types)
 *  - signatory   : Authorised signatory name configured in RTO Settings
 *
 * @return array<string,bool>  Associative array; true = asset is present/configured.
 */
function local_rtocompliance_get_branding_status(): array {
    return [
        'logo'         => !empty(\local_rtocompliance\cert_template::get_branding_logo_url()),
        'signature'    => !empty(\local_rtocompliance\cert_template::get_branding_signature_url()),
        'seal'         => !empty(\local_rtocompliance\cert_template::get_branding_org_seal_url()),
        'nrt_override' => !empty(\local_rtocompliance\cert_template::get_branding_nrt_logo_url()),
        'signatory'    => (trim((string) get_config('local_rtocompliance', 'signatoryname')) !== ''),
    ];
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
 * CATEGORY-TREE-DETECTION (v5.9.329)
 *
 * Walk the full Moodle category ancestor chain for a course and return the first
 * AVETMISS qualification code found at the START of any ancestor category's name.
 *
 * RTOs organise Moodle courses like this:
 *   "ABC12345 — a Diploma qualification"   ← top-level category (qual code in NAME)
 *       └── "2023 S1"                          ← semester sub-category
 *             └── "ABC12345 CL1 2023 S1"       ← course (unit code in name)
 *       └── "2024 S2"
 *             └── "ABC12345 CL1 2024 S2"
 *
 * Walks upward through parent categories until a name beginning with an AVETMISS
 * qualification code is found (e.g. "ABC12345", "BSB50320", "CHC52021").
 * Stops at the first match so semester/cohort sub-categories are skipped.
 *
 * This is the authoritative source of truth for course → qualification mapping.
 * It replaces all previous fallbacks (category.idnumber, nationallyrecognised flag).
 *
 * @param int $courseid  Moodle course ID.
 * @return string  Qualification code (e.g. 'ABC12345') or '' if not found.
 */
function local_rtocompliance_detect_qualcode_from_category_ancestors(int $courseid): string {
    global $DB;

    $course = $DB->get_record('course', ['id' => $courseid], 'category');
    if (!$course) {
        return '';
    }

    $catid   = (int)$course->category;
    $visited = [];

    // AVETMISS qualification code: 2-8 uppercase letters + 3-6 digits + 0-2 optional letters,
    // at the very start of the category name, followed by whitespace / dash / em-dash / colon
    // or end of string. Examples: "ABC12345 — Diploma", "BSB50320 Business Services".
    $rx = '/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})(?:\s|$|-|—|:)/u';

    while ($catid > 0 && !isset($visited[$catid])) {
        $visited[$catid] = true;
        $cat = $DB->get_record('course_categories', ['id' => $catid], 'id, name, parent');
        if (!$cat) {
            break;
        }
        if (preg_match($rx, strtoupper(trim((string)$cat->name)), $m)) {
            return $m[1];
        }
        $catid = (int)$cat->parent;
    }

    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// CATEGORY-TREE-DETECTION utilities (v5.9.332)
// These three functions form the foundation for Task #44: QB automatically
// discovers every semester copy of a unit from the Moodle category tree,
// with no manual linking required.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Find the Moodle category whose name starts with the given AVETMISS qualification code.
 *
 * The RTO's top-level category is named like "ABC12345 — a Diploma qualification".
 * Returns the shallowest matching category ID (depth ASC) or 0 if not found.
 *
 * @param string $qualificationcode  e.g. 'ABC12345'
 * @return int  Moodle category ID, or 0.
 */
function local_rtocompliance_get_qual_root_category_id(string $qualificationcode): int {
    global $DB;
    if (empty($qualificationcode)) {
        return 0;
    }
    $code = strtoupper(trim($qualificationcode));
    $rx   = '/^(' . preg_quote($code, '/') . ')(?:\s|$|-|—|:)/u';
    // Performance (v5.9.333): pre-filter with a leading LIKE so large sites (10k+ categories)
    // avoid a full table scan. sql_like() is case-insensitive on MySQL/PostgreSQL/MSSQL.
    // The regex below provides the exact boundary validation; LIKE is just a fast sieve.
    $cats = $DB->get_records_sql(
        "SELECT id, name FROM {course_categories}
          WHERE " . $DB->sql_like('name', ':prefix', false) . "
          ORDER BY depth ASC, id ASC",
        ['prefix' => $code . '%']
    );
    if (empty($cats)) {
        // Safety fallback: if LIKE yields nothing (unusual encoding, leading whitespace),
        // do a full scan so no qualification category is silently missed.
        $cats = $DB->get_records('course_categories', null, 'depth ASC, id ASC', 'id, name');
    }
    foreach ($cats as $cat) {
        if (preg_match($rx, strtoupper(trim((string)$cat->name)))) {
            return (int)$cat->id;
        }
    }
    return 0;
}

/**
 * Return every category ID in the subtree rooted at $rootcatid (BFS, inclusive).
 *
 * Used to enumerate all categories under a qualification's Moodle category so
 * that semester-cohort sub-categories (2023 S1, 2024 S2 …) and their courses
 * are all visible to the completion checker.
 *
 * @param int $rootcatid  Top-level qualification category ID.
 * @return int[]
 */
function local_rtocompliance_get_category_subtree_ids(int $rootcatid): array {
    global $DB;
    if ($rootcatid <= 0) {
        return [];
    }
    $catids  = [$rootcatid];
    $queue   = [$rootcatid];
    $visited = [$rootcatid => true];
    while (!empty($queue)) {
        list($insql, $inparams) = $DB->get_in_or_equal($queue, SQL_PARAMS_NAMED, 'qpc');
        $children = $DB->get_fieldset_sql(
            "SELECT id FROM {course_categories} WHERE parent $insql",
            $inparams
        );
        $queue = [];
        foreach ($children as $cid) {
            $cid = (int)$cid;
            if (!isset($visited[$cid])) {
                $visited[$cid] = true;
                $catids[]  = $cid;
                $queue[]   = $cid;
            }
        }
    }
    return $catids;
}

/**
 * Return all Moodle course IDs under the given category subtree whose fullname or
 * shortname starts with the given AVETMISS unit code.
 *
 * Example: rootcatid for "ABC12345 — a Diploma qualification", unitcode "ABC12345"
 * returns the IDs of "ABC12345 CL1 2023 S1", "ABC12345 CL1 2024 S2", etc.
 * across ALL semester sub-categories, without any manual QB linking.
 *
 * Pass $preloadedSubtreeCatids (from get_category_subtree_ids) when calling for
 * multiple units under the same qualification to avoid repeating the BFS.
 *
 * @param int    $rootcatid               Root qualification category ID.
 * @param string $unitcode                AVETMISS unit code (e.g. 'ABC12345').
 * @param int[]  $preloadedSubtreeCatids  Pre-expanded subtree — omit to compute inline.
 * @return int[]  Moodle course IDs.
 */
function local_rtocompliance_get_category_tree_courseids_for_unit(
    int $rootcatid, string $unitcode, array $preloadedSubtreeCatids = []
): array {
    global $DB;
    if ($rootcatid <= 0 || empty($unitcode)) {
        return [];
    }
    $catids = !empty($preloadedSubtreeCatids)
        ? $preloadedSubtreeCatids
        : local_rtocompliance_get_category_subtree_ids($rootcatid);
    if (empty($catids)) {
        return [];
    }
    list($insql, $inparams) = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED, 'ctcat');
    $courses = $DB->get_records_sql(
        "SELECT id, fullname, shortname FROM {course} WHERE category $insql",
        $inparams
    );
    $code     = strtoupper(trim($unitcode));
    // REGEX-BOUNDARY-FIX (v5.9.333): use an explicit non-alphanumeric-or-end-of-string
    // boundary instead of \b. \b is correct when a space follows the code (e.g. "ABC12345 CL1")
    // but silently fails on names like "ABC12345" (no delimiter). The explicit class
    // [^A-Z0-9] also covers dashes, underscores, dots etc.; both strings are already
    // uppercased by this point so lowercase is not a concern.
    $rx       = '/^' . preg_quote($code, '/') . '(?:[^A-Z0-9]|$)/u';
    $matching = [];
    foreach ($courses as $c) {
        if (preg_match($rx, strtoupper(trim((string)$c->fullname))) ||
            preg_match($rx, strtoupper(trim((string)$c->shortname)))) {
            $matching[] = (int)$c->id;
        }
    }
    return $matching;
}

/**
 * Fast runtime lookup: return all delivery course IDs for a qual+unit pair from
 * the local_rtocompliance_course_map table.
 *
 * COURSE-MAP-TABLE (v5.9.335): this single indexed DB query replaces the three-step
 * (QB primary + variant + category-tree regex) course discovery that previously ran
 * on every completion event, every cert generation, and every autocert check.
 * The table is seeded once via local_rtocompliance_seed_course_map(); after seeding,
 * every completion path is a sub-millisecond index hit — no regex, no BFS.
 *
 * Returns empty when the table has no rows for this pair (not yet seeded for this
 * qualification). Callers should fall back to the legacy 3-step approach in that case.
 *
 * @param string $qualcode  AVETMISS qualification code (e.g. 'ABC12345').
 * @param string $unitcode  AVETMISS unit code (e.g. 'ABC12345').
 * @return int[]  Moodle course IDs.
 */
function local_rtocompliance_get_courseids_for_unit_from_map(string $qualcode, string $unitcode): array {
    global $DB;
    if (empty($qualcode) || empty($unitcode)) {
        return [];
    }
    // Table-exists guard: safe on pre-upgrade or partial-upgrade installs.
    if (!$DB->get_manager()->table_exists('local_rtocompliance_course_map')) {
        return [];
    }
    $rows = $DB->get_fieldset_select(
        'local_rtocompliance_course_map',
        'courseid',
        'qualcode = :qc AND unitcode = :uc',
        ['qc' => strtoupper(trim($qualcode)), 'uc' => strtoupper(trim($unitcode))]
    );
    return array_map('intval', $rows ?: []);
}

/**
 * Seed the local_rtocompliance_course_map table from three sources:
 *
 *   1. QB primary courses   (qualunits.courseid)          → source='qb',   confirmed=1
 *   2. QB variant courses   (qualunit_courses.courseid)   → source='qb',   confirmed=1
 *   3. Category-tree regex  (course name starts w/ code)  → source='auto', confirmed=0
 *
 * This is the ONLY place in the codebase where the category-tree regex fires at
 * runtime — all other paths read the table.  Idempotent: courses already in the table
 * are counted as skipped and left untouched.  Manual (source='manual') rows are never
 * modified.
 *
 * @param string $qualcode  Limit to this qual code; empty = seed all active quals.
 * @return array  ['inserted' => int, 'skipped' => int, 'quals_scanned' => string[]]
 */
function local_rtocompliance_seed_course_map(string $qualcode = ''): array {
    global $DB, $USER;

    // REQUEST-LEVEL DEDUP CACHE (v5.9.355): Guard against redundant reseed calls within
    // the same HTTP request. The catch-up scan (qual_cert_hub.php action=scan_missed),
    // the Partially Complete tab, and generate_qual_certs.php all call
    // check_full_qual_completion() / get_completed_units_for_qual() in a per-student loop,
    // each of which calls seed_course_map(). Without this guard a 200-student cohort
    // triggers 200 identical BFS walks for the same qualcode.
    // First call per qualcode per request: full BFS walk as normal.
    // Subsequent calls for the same qualcode: immediate no-op.
    // An empty $qualcode means "seed all quals" — tracked under the key '__all__'.
    static $seededThisRequest = [];
    $cacheKey = empty($qualcode) ? '__all__' : strtoupper(trim($qualcode));
    if (isset($seededThisRequest[$cacheKey])) {
        return ['inserted' => 0, 'skipped' => 0, 'quals_scanned' => []];
    }
    $seededThisRequest[$cacheKey] = true;

    if (!$DB->get_manager()->table_exists('local_rtocompliance_course_map')) {
        return ['inserted' => 0, 'skipped' => 0, 'quals_scanned' => []];
    }

    $inserted     = 0;
    $skipped      = 0;
    $qualsScanned = [];
    $now          = time();
    $userid       = isset($USER->id) ? (int)$USER->id : 0;

    // ── Helper: try to insert one mapping row; skip silently on duplicate. ────
    $tryInsert = function (
        int $cid, int $catid, string $qc, string $uc, string $source, int $confirmed
    ) use ($DB, $now, $userid, &$inserted, &$skipped) {
        if ($cid <= 0 || empty($qc) || empty($uc)) {
            return;
        }
        if ($DB->record_exists('local_rtocompliance_course_map', ['courseid' => $cid])) {
            $skipped++;
            return;
        }
        $row              = new \stdClass();
        $row->courseid    = $cid;
        $row->categoryid  = $catid;
        $row->qualcode    = strtoupper(trim($qc));
        $row->unitcode    = strtoupper(trim($uc));
        $row->source      = $source;
        $row->confirmed   = $confirmed;
        $row->timecreated  = $now;
        $row->timemodified = $now;
        $row->usermodified = $userid;
        try {
            $DB->insert_record('local_rtocompliance_course_map', $row);
            $inserted++;
        } catch (\dml_exception $e) {
            // Duplicate courseid from concurrent seeding — treat as skipped.
            $skipped++;
        }
    };

    // ── Load all active QB records (optionally filtered by qual code). ────────
    $qbWhere  = "status != 'superseded'";
    $qbParams = [];
    if (!empty($qualcode)) {
        // UPPER() for case-insensitive match: QB records may be stored in mixed case
        // while callers always pass an uppercased qualcode.
        $qbWhere        .= ' AND UPPER(qualificationcode) = :qc';
        $qbParams['qc'] = strtoupper(trim($qualcode));
    }
    $qbs = $DB->get_records_select(
        'local_rtocompliance_qualbuilder', $qbWhere, $qbParams, '', 'id, qualificationcode'
    );

    $variantsTableExists = $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses');

    foreach ($qbs as $qb) {
        $qc = strtoupper(trim((string)$qb->qualificationcode));
        if (empty($qc)) {
            continue;
        }
        $qualsScanned[] = $qc;

        // Source 1: QB primary courses. ────────────────────────────────────
        // Only seed active+selected units (mirrors runtime consumer filters).
        $primaries = $DB->get_records_sql(
            "SELECT qu.courseid, qu.unitcode, COALESCE(c.category, 0) AS catid
               FROM {local_rtocompliance_qualunits} qu
               LEFT JOIN {course} c ON c.id = qu.courseid
              WHERE qu.qualbuilderid = :qbid
                AND qu.courseid  IS NOT NULL
                AND qu.unitcode  IS NOT NULL AND qu.unitcode != ''
                AND qu.status    = 'active'
                AND qu.selected  = 1",
            ['qbid' => $qb->id]
        );
        foreach ($primaries as $p) {
            $tryInsert((int)$p->courseid, (int)$p->catid, $qc, $p->unitcode, 'qb', 1);
        }

        // Source 2: QB variant courses. ────────────────────────────────────
        // Only seed active+selected units (mirrors runtime consumer filters).
        // v5.9.374: is_archive is now INFORMATIONAL — archived / semester-copy
        // intake courses are legitimate deliveries of the unit, so they are
        // seeded into the map too (the RTO delivers units through many copied
        // courses across categories and years). The archive flag no longer
        // suppresses the mapping.
        if ($variantsTableExists) {
            $variants = $DB->get_records_sql(
                "SELECT quc.courseid, qu.unitcode, COALESCE(c.category, 0) AS catid
                   FROM {local_rtocompliance_qualunit_courses} quc
                   JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
                   LEFT JOIN {course} c ON c.id = quc.courseid
                  WHERE qu.qualbuilderid = :qbid
                    AND quc.courseid     IS NOT NULL
                    AND qu.unitcode      IS NOT NULL AND qu.unitcode != ''
                    AND qu.status        = 'active'
                    AND qu.selected      = 1",
                ['qbid' => $qb->id]
            );
            foreach ($variants as $v) {
                $tryInsert((int)$v->courseid, (int)$v->catid, $qc, $v->unitcode, 'qb', 1);
            }
        }

        // Source 3: Category-tree detection (regex fires only here). ────────
        $rootcatid = local_rtocompliance_get_qual_root_category_id($qc);
        if ($rootcatid <= 0) {
            continue;
        }
        $subtreeids = local_rtocompliance_get_category_subtree_ids($rootcatid);
        if (empty($subtreeids)) {
            continue;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($subtreeids, SQL_PARAMS_NAMED, 'scm');
        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.category
               FROM {course} c WHERE c.category $insql",
            $inparams
        );
        if (empty($courses)) {
            continue;
        }

        // All ACTIVE+SELECTED unit codes for this QB — used to match against course names.
        // Filters match Sources 1 and 2 so auto-detected courses are only seeded for
        // units that are currently active and selected (not deselected/inactive units).
        $allUnitcodes = $DB->get_fieldset_sql(
            "SELECT DISTINCT unitcode FROM {local_rtocompliance_qualunits}
              WHERE qualbuilderid = :qbid AND unitcode IS NOT NULL AND unitcode != ''
                AND status = 'active' AND selected = 1",
            ['qbid' => $qb->id]
        );
        $allUnitcodes = array_map('strtoupper', $allUnitcodes ?: []);
        if (empty($allUnitcodes)) {
            continue;
        }

        foreach ($courses as $course) {
            $fn = strtoupper(trim((string)$course->fullname));
            $sn = strtoupper(trim((string)$course->shortname));
            foreach ($allUnitcodes as $uc) {
                $rx = '/^' . preg_quote($uc, '/') . '(?:[^A-Z0-9]|$)/u';
                if (preg_match($rx, $fn) || preg_match($rx, $sn)) {
                    $tryInsert((int)$course->id, (int)$course->category, $qc, $uc, 'auto', 0);
                    break; // one unit code per course
                }
            }
        }
    }

    // ── Source 4 (v5.9.374): category-agnostic global discovery. ──────────────
    // The RTO delivers units through many copied courses scattered across
    // categories that do NOT reliably hold the compliant unit set, so Sources
    // 1-3 (QB-linked or within a qualification's own category subtree) miss the
    // strays. Source 4 matches ANY Moodle course whose name starts with a known
    // unit code to that unit's qualification, regardless of category.
    // Safeguards: (a) full reseed only — never on a single-qual on-the-fly call,
    // so per-enrolment resolution stays cheap; (b) only maps a unit that belongs
    // to exactly ONE active qualification (ambiguous units are left for manual
    // mapping and counted); (c) scans only courses not already mapped and relies
    // on $tryInsert's UNIQUE-courseid guard, so it never overrides Sources 1-3.
    $globalInserted  = 0;
    $globalAmbiguous = 0;
    if (empty($qualcode)) {
        $unitQualRows = $DB->get_records_sql(
            "SELECT " . $DB->sql_concat('UPPER(qu.unitcode)', "'|'", 'qb.id') . " AS ukey,
                    UPPER(qu.unitcode) AS unitcode, UPPER(qb.qualificationcode) AS qualcode
               FROM {local_rtocompliance_qualunits} qu
               JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
              WHERE qu.unitcode IS NOT NULL AND qu.unitcode != ''
                AND qu.status = 'active' AND qu.selected = 1
                AND qb.status != 'superseded'"
        );
        $unitToQuals = [];
        foreach ($unitQualRows as $r) {
            if ($r->unitcode === null || $r->unitcode === '' || $r->qualcode === null || $r->qualcode === '') {
                continue;
            }
            $unitToQuals[$r->unitcode][$r->qualcode] = true;
        }
        $unambiguous = [];
        foreach ($unitToQuals as $uc => $quals) {
            if (count($quals) === 1) {
                $unambiguous[$uc] = array_key_first($quals);
            } else {
                $globalAmbiguous++;
            }
        }
        if (!empty($unambiguous)) {
            $ucList  = array_keys($unambiguous);
            $courses = $DB->get_records_sql(
                "SELECT c.id, c.fullname, c.shortname, c.category
                   FROM {course} c
                  WHERE c.id > 1
                    AND c.id NOT IN (SELECT courseid FROM {local_rtocompliance_course_map})"
            );
            foreach ($courses as $course) {
                $fn = strtoupper(trim((string)$course->fullname));
                $sn = strtoupper(trim((string)$course->shortname));
                foreach ($ucList as $uc) {
                    $rx = '/^' . preg_quote($uc, '/') . '(?:[^A-Z0-9]|$)/u';
                    if (preg_match($rx, $fn) || preg_match($rx, $sn)) {
                        $before = $inserted;
                        // source='auto', confirmed=0 → appears as an unreviewed
                        // auto-mapping the RTO can confirm on the Course Map page.
                        $tryInsert((int)$course->id, (int)$course->category, $unambiguous[$uc], $uc, 'auto', 0);
                        if ($inserted > $before) {
                            $globalInserted++;
                        }
                        break; // one unit code per course
                    }
                }
            }
        }
    }

    return [
        'inserted'       => $inserted,
        'skipped'        => $skipped,
        'quals_scanned'  => array_values(array_unique($qualsScanned)),
        'global_inserted' => $globalInserted,
        'global_ambiguous' => $globalAmbiguous,
    ];
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
        // v5.9.367 ROR-OUTCOME-FIX: a full-qual issue produces a Record of Results, which
        // must carry each unit's REAL AVETMISS outcome (RPL 51 / Credit Transfer 60 / etc.),
        // not the hardcoded '20' Competent returned by get_qualbuilder_unit_list(). This
        // mirrors generate_qual_certs.php, which already uses the outcomes-aware lookup.
        // Falls back to the plain list only for the generic no-user display call (userid=0)
        // or when the student has no local_rtocompliance_students row yet.
        $fullunits = $allunits;
        if ($userid > 0) {
            $studentrec = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'id');
            if ($studentrec) {
                $fullunits = local_rtocompliance_get_qualbuilder_unit_list_with_outcomes(
                    (int) $qualbuilder->id, (int) $studentrec->id);
            }
        }
        return ['certtypes' => ['testamur', 'record'], 'qualificationcode' => $qualbuilder->qualificationcode, 'qualificationname' => $qualbuilder->qualificationname, 'units' => $fullunits, 'reason' => 'full_qualification'];
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

    // Build a unitcode -> best outcome map from the student's enrolment records.
    //
    // OUTCOME-ORDERING-FIX (v5.9.330): Previously ordered by id DESC (most recent row
    // wins). This caused a critical bug: a student re-enrolled in a later semester with
    // outcome '70' (Continuing) would have that outcome appear on their Record of Results
    // cert, masking an earlier '20' (Competent) from a prior semester. A cert bearing
    // '70 Continuing enrolment' on a unit is incorrect and would fail ASQA audit.
    //
    // Fix: order by outcome priority first — competent outcomes ('20','51','60','81')
    // sort before all others (CASE returns 0), so the first-seen entry in the loop is
    // always the best outcome. Ties within the same priority break on id DESC so the
    // most recent competent record wins when a student has multiple competent records
    // for the same unit (e.g. RPL then re-assessment).
    //
    // SEMESTER-COPY-OUTCOME-REVIEW (v5.9.358): Confirmed that the SEMESTER-COPY-OUTCOME-FIX
    // class of bug (v5.9.345 in get_completed_units_for_qual) does NOT apply here. That fix
    // added a programcode-free fallback query because get_completed_units_for_qual constrains
    // its enrolment lookup by programcode — so semester-copy rows created without a programcode
    // were invisible to it. This function queries by studentid alone (no programcode constraint),
    // so ANY enrolment row for this student and unit is found regardless of whether programcode
    // is blank or set. A student whose RPL/CT was recorded without a programcode will still have
    // their correct outcome (51 or 60) appear here. No secondary fallback query is needed.
    // The only remaining '20'-fallback scenario is a genuine data gap: the student completed
    // a unit via Moodle course completion (SOURCE 1) but no enrolment row was ever written for
    // that unit — the debugging() call below flags those cases for admin follow-up.
    $unitcodes = array_column(array_values($rows), 'unitcode');
    list($insql, $inparams) = $DB->get_in_or_equal($unitcodes, SQL_PARAMS_NAMED, 'uc');
    $enrolmentrows = $DB->get_records_sql(
        "SELECT unitcode, outcomeidentifier
           FROM {local_rtocompliance_enrolments}
          WHERE studentid = :studentid
            AND unitcode $insql
          ORDER BY CASE WHEN outcomeidentifier IN ('20','51','60','81') THEN 0 ELSE 1 END ASC,
                   id DESC",
        array_merge(['studentid' => $studentid], $inparams)
    );
    $outcomeMap = [];
    foreach ($enrolmentrows as $row) {
        // First-seen = best outcome (competent before continuing/fail), then most recent.
        if (!isset($outcomeMap[$row->unitcode]) && !empty($row->outcomeidentifier)) {
            $outcomeMap[$row->unitcode] = $row->outcomeidentifier;
        }
    }

    $units = [];
    foreach ($rows as $row) {
        // OUTCOME-FALLBACK-WARNING (v5.9.331): Fallback to '20' only fires for SOURCE 1
        // completers (Moodle course completion with no enrolment record). For SOURCE 2
        // completers every unit always has a mapped outcome. Logged so admins can detect
        // data-integrity gaps (missing enrolment row for a confirmed completer).
        // NOTE: unlike get_completed_units_for_qual, NO secondary fallback query is
        // attempted here — the initial query is already programcode-free, so any enrolment
        // row for this student/unit (including RPL/CT rows with no programcode set) has
        // already been considered. If no row was found, the data gap is genuine.
        if (!isset($outcomeMap[$row->unitcode])) {
            debugging(
                'rtocompliance get_qualbuilder_unit_list_with_outcomes: no enrolment outcome'
                . ' found for studentid=' . $studentid . ' unitcode=' . $row->unitcode
                . ' — falling back to \'20\' (Competent) on the Record of Results cert.'
                . ' Query was already programcode-free; this is a genuine missing enrolment row.',
                DEBUG_DEVELOPER
            );
        }
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

    // COURSE-MAP-TABLE (v5.9.335): runtime course discovery now reads from the
    // local_rtocompliance_course_map table (a single indexed DB lookup per unit)
    // rather than walking the Moodle category tree with a regex on every call.
    // The table is seeded once via the "Moodle Course Map" admin page.
    //
    // Fallback: if the table doesn't exist (pre-upgrade) OR has no rows for this
    // qualification (not yet seeded), the old 3-step approach (QB primary + variant
    // + category-tree BFS) is used so nothing breaks during the transition.
    $dbman          = $DB->get_manager();
    $courseMapExists = $dbman->table_exists('local_rtocompliance_course_map');
    $variantsExist   = $dbman->table_exists('local_rtocompliance_qualunit_courses');

    // SYNC-BEFORE-CHECK (v5.9.344): Run a targeted reseed for this qualification
    // before checking the map so any semester-copy courses added since the last
    // nightly sync are captured. Passing $programcode scopes the BFS walk to one
    // qual's category tree — typically < 100ms. Existing confirmed/manual entries
    // are never overwritten (INSERT-or-skip semantics, same as generate_qual_certs.php).
    if ($courseMapExists && !empty($programcode)) {
        local_rtocompliance_seed_course_map($programcode);
    }

    // BFS pre-load: needed when the map table is absent (pre-upgrade) OR when the
    // map exists but has no rows for this qualification yet (not seeded or qual not
    // found in the category tree).
    //
    // TASK-44-BFS-GATE-FIX (v5.9.344): previously this block only ran when
    // !$courseMapExists. A qualification that had the map table present but no rows
    // for its qualcode would fall through to primary+variant only, silently skipping
    // all semester-copy courses that were never manually linked in QB. Now the gate
    // checks whether the map actually has rows for this qual, so the BFS fallback
    // fires whenever the map cannot supply a complete course set.
    $cfqcRootCatid  = 0;
    $cfqcSubtreeIds = [];
    $cfqcNormCode   = !empty($programcode) ? strtoupper(trim($programcode)) : '';
    $cfqcMapHasRows = $courseMapExists && $cfqcNormCode !== ''
        && $DB->record_exists('local_rtocompliance_course_map', ['qualcode' => $cfqcNormCode]);
    if (!$cfqcMapHasRows && !empty($programcode)) {
        $cfqcRootCatid  = local_rtocompliance_get_qual_root_category_id($programcode);
        $cfqcSubtreeIds = $cfqcRootCatid > 0
            ? local_rtocompliance_get_category_subtree_ids($cfqcRootCatid)
            : [];
    }

    // All selected active units — no courseid IS NOT NULL filter (v5.9.331).
    $rows = $DB->get_records_sql(
        "SELECT qu.id, qu.courseid, qu.unitcode
         FROM {local_rtocompliance_qualunits} qu
         WHERE qu.qualbuilderid = :qbid
           AND qu.status = 'active'
           AND qu.selected = 1",
        ['qbid' => $qualbuilderid]
    );

    if (empty($rows)) {
        return false; // No units configured in this qualification
    }

    foreach ($rows as $row) {
        $done = false;

        // ── Course set for this unit ──────────────────────────────────────────
        $courseids = [];
        $mapHit    = false;

        if ($courseMapExists && $programcode && !empty($row->unitcode)) {
            // New path: single indexed lookup — no regex, no BFS.
            $courseids = local_rtocompliance_get_courseids_for_unit_from_map(
                $programcode, (string)$row->unitcode
            );
            $mapHit = !empty($courseids);
        }

        if (!$mapHit) {
            // Legacy fallback: QB primary + variant + category-tree BFS.
            if (!empty($row->courseid)) {
                $courseids = [(int)$row->courseid];
            }
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
            if ($cfqcSubtreeIds && !empty($row->unitcode)) {
                $catCids = local_rtocompliance_get_category_tree_courseids_for_unit(
                    $cfqcRootCatid, (string)$row->unitcode, $cfqcSubtreeIds
                );
                if ($catCids) {
                    $courseids = array_values(array_unique(array_merge($courseids, $catCids)));
                }
            }
        }

        // Course-completion pass — student must have completed at least ONE delivery course.
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cfqc');
            $inparams['cfqcuid'] = $userid;
            $done = $DB->record_exists_sql(
                "SELECT 1 FROM {course_completions}
                  WHERE course $insql AND userid = :cfqcuid
                    AND timecompleted IS NOT NULL AND timecompleted > 0",
                $inparams
            );
        }

        // Enrolment-outcome fallback — covers:
        //   (a) Moodle course completion not configured on this course
        //   (b) Outcomes imported via AVETMISS / entered manually
        //   (c) Category-tree-only units with no primary QB course link
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

    // COURSE-MAP-TABLE (v5.9.335): same table-lookup strategy as check_full_qual_completion().
    // TASK-44-BFS-GATE-FIX (v5.9.344): same two fixes applied here as in
    // check_full_qual_completion() — (1) targeted reseed before map lookup so
    // semester-copy courses added since the last nightly sync are captured; (2) BFS
    // gate changed from !$courseMapExists to !$gcuqMapHasRows so the category-tree
    // fallback fires whenever the map has no rows for this qual, not only when the
    // table itself is absent.
    $dbman           = $DB->get_manager();
    $courseMapExists  = $dbman->table_exists('local_rtocompliance_course_map');
    $variantsExist    = $dbman->table_exists('local_rtocompliance_qualunit_courses');

    if ($courseMapExists && !empty($programcode)) {
        local_rtocompliance_seed_course_map($programcode);
    }

    $gcuqRootCatid  = 0;
    $gcuqSubtreeIds = [];
    $gcuqNormCode   = !empty($programcode) ? strtoupper(trim($programcode)) : '';
    $gcuqMapHasRows = $courseMapExists && $gcuqNormCode !== ''
        && $DB->record_exists('local_rtocompliance_course_map', ['qualcode' => $gcuqNormCode]);
    if (!$gcuqMapHasRows && !empty($programcode)) {
        $gcuqRootCatid  = local_rtocompliance_get_qual_root_category_id($programcode);
        $gcuqSubtreeIds = $gcuqRootCatid > 0
            ? local_rtocompliance_get_category_subtree_ids($gcuqRootCatid)
            : [];
    }

    $rows = $DB->get_records_sql(
        "SELECT qu.id, qu.unitcode, qu.unitname, qu.courseid
         FROM {local_rtocompliance_qualunits} qu
         WHERE qu.qualbuilderid = :qbid
           AND qu.status = 'active'
           AND qu.selected = 1
         ORDER BY qu.sequenceorder ASC",
        ['qbid' => $qualbuilderid]
    );

    $units = [];
    foreach ($rows as $row) {
        $moodleDone = false;

        // Build full course set: table lookup (fast), or legacy fallback.
        $courseids = [];
        $mapHit    = false;

        if ($courseMapExists && $programcode && !empty($row->unitcode)) {
            $courseids = local_rtocompliance_get_courseids_for_unit_from_map(
                $programcode, (string)$row->unitcode
            );
            $mapHit = !empty($courseids);
        }

        if (!$mapHit) {
            // Legacy: QB primary + variant + category-tree BFS.
            if (!empty($row->courseid)) {
                $courseids = [(int)$row->courseid];
            }
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
            if ($gcuqSubtreeIds && !empty($row->unitcode)) {
                $catCids = local_rtocompliance_get_category_tree_courseids_for_unit(
                    $gcuqRootCatid, (string)$row->unitcode, $gcuqSubtreeIds
                );
                if ($catCids) {
                    $courseids = array_values(array_unique(array_merge($courseids, $catCids)));
                }
            }
        }
        // Unit is complete if student finished ANY delivery course for it.
        if (!empty($courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gcuq');
            $inparams['gcuquid'] = $userid;
            $moodleDone = $DB->record_exists_sql(
                "SELECT 1 FROM {course_completions}
                  WHERE course $insql AND userid = :gcuquid
                    AND timecompleted IS NOT NULL AND timecompleted > 0",
                $inparams
            );
        }

        // Resolve the actual AVETMISS outcome from the enrolments table so the SoA
        // shows the correct code (RPL, CT, Competent, etc.) rather than always '20'.
        //
        // OUTCOME-ORDERING-FIX (v5.9.331): Aligned with get_qualbuilder_unit_list_with_outcomes
        // — competent outcomes ('20','51','60','81') sort first; ties break by timemodified DESC.
        // Previously used timemodified DESC only, so a later Continuing (70) enrolment could
        // mask an earlier Competent (20) — producing an incorrect outcome on the partial SoA.
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
                  ORDER BY CASE WHEN e.outcomeidentifier IN ('20','51','60','81') THEN 0 ELSE 1 END ASC,
                           e.timemodified DESC",
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

        // SEMESTER-COPY-OUTCOME-FIX (v5.9.345): When the category-tree or course-map
        // detected Moodle completion ($moodleDone = true) but the programcode-constrained
        // enrolment lookup found nothing ($actualOutcome = null), it means the student's
        // enrolment row was created without a programcode (common for semester-copy courses
        // where the RTO enters RPL/CT outcomes directly in the enrolments table but does
        // not set programcode on those rows). Falling back to '20' in that case is wrong —
        // the student's SoA would show 'Competent' when the real outcome is RPL (51) or
        // Credit Transfer (60), which is factually incorrect and could be flagged by ASQA.
        //
        // Fix: perform a secondary search WITHOUT the programcode constraint. We still
        // apply the same competent-outcome priority ordering and take the most recent
        // qualifying row. A debugging() call fires when the fallback activates so admins
        // can identify affected enrolment records.
        if ($moodleDone && $actualOutcome === null && !empty($row->unitcode)) {
            $fallbackRec = $DB->get_record_sql(
                "SELECT e.outcomeidentifier
                   FROM {local_rtocompliance_enrolments} e
                   JOIN {local_rtocompliance_students} s ON s.id = e.studentid
                  WHERE s.userid           = :gcuq_fb_uid
                    AND e.unitcode         = :gcuq_fb_unitcode
                    AND e.outcomeidentifier IN ('20', '51', '60', '81')
                  ORDER BY CASE WHEN e.outcomeidentifier IN ('20','51','60','81') THEN 0 ELSE 1 END ASC,
                           e.timemodified DESC",
                [
                    'gcuq_fb_uid'      => $userid,
                    'gcuq_fb_unitcode' => $row->unitcode,
                ]
            );
            if ($fallbackRec) {
                $actualOutcome = $fallbackRec->outcomeidentifier;
                debugging(
                    'rtocompliance get_completed_units_for_qual: programcode-free enrolment fallback'
                    . ' fired for userid=' . $userid
                    . ' unitcode=' . $row->unitcode
                    . ' qualbuilderid=' . $qualbuilderid
                    . ' — resolved outcome ' . $actualOutcome
                    . '. Enrolment row has no programcode set; consider updating it to'
                    . ' programcode=' . ($programcode ?? '?') . ' for data integrity.',
                    DEBUG_DEVELOPER
                );
            }
        }

        if ($moodleDone || $actualOutcome !== null) {
            // Use the actual AVETMISS outcome when known; fall back to '20' only when
            // we know Moodle says the course is done but no enrolment record exists at all.
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

/**
 * v5.9.361 CERT-NUMBER-BY-TYPE: next unique certificate number for a cert type.
 *
 * Format (hyphens only): {BASEPREFIX}-{TYPECODE}-{YYYY}-{NNNN}
 *   BASEPREFIX = per-RTO 'certprefix' setting (the RTO enter 'ABC'; others their own)
 *   TYPECODE   = testamur=CER, statement=SOA, record=ROR, completion=COC
 *   YYYY       = issue year, always dynamic (date('Y')) — never hard-coded
 *   NNNN       = continuous per-type sequence (min 4 digits) beginning at the per-type
 *                'certstartnum_<type>' setting (default 1) — e.g. SoA can start at 1000.
 * e.g. ABC-SOA-2026-1000, ABC-ROR-2026-0001, ABC-CER-2026-0001, ABC-COC-2026-0001.
 *
 * @param string $certtype One of testamur|statement|record|completion.
 * @return string Unique certificate number.
 */
function local_rtocompliance_generate_cert_number(string $certtype): string {
    global $DB;

    $base = get_config('local_rtocompliance', 'certprefix') ?: 'RTO';

    $typecodes = [
        'testamur'   => 'CER',
        'statement'  => 'SOA',
        'record'     => 'ROR',
        'completion' => 'COC',
    ];
    $typecode = $typecodes[$certtype] ?? strtoupper(substr($certtype, 0, 3));

    $prefix = $base . '-' . $typecode;   // hyphens only, e.g. ABC-SOA
    $year   = date('Y');

    // Per-type starting number (RTO setting) — lets e.g. SoA begin at 1000. Default 1.
    $start = (int) get_config('local_rtocompliance', 'certstartnum_' . $certtype);
    if ($start < 1) {
        $start = 1;
    }

    // Continuous per-type count across all years so the start number stays meaningful.
    $prefix_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    $count = (int) $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_certs} WHERE certnumber LIKE ?",
        [$prefix_escaped . '-%']
    );
    $sequence = $start + $count;
    $certnumber = sprintf('%s-%s-%04d', $prefix, $year, $sequence);

    // Collision guard: step past any existing row (race window + gaps from voided certs).
    $guard = 0;
    while ($DB->record_exists('local_rtocompliance_certs', ['certnumber' => $certnumber]) && $guard++ < 5000) {
        $sequence++;
        $certnumber = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }

    return $certnumber;
}

/**
 * COMPLIANCE-MAP (v5.9.383): the feature directory, grouped by Standards-for-RTOs
 * 2025 Quality Area. Single source of truth shared by the Compliance Map page.
 *
 * @return array List of ['title', 'color', 'modules' => [['url','icon','title','desc','standards'?], ...]].
 */
function local_rtocompliance_compliance_modules(): array {
    return [
        [
            'title' => 'Quality Area 1: Training &amp; Assessment<br><span class="clause-ref">(Part 1, Divisions 1–4)</span>',
            'color' => 'amber',
            'modules' => [
                ['url' => '/local/rtocompliance/tas.php',         'icon' => 'clipboard-list', 'title' => 'Training',            'desc' => 'Standard 1.1–1.2: TAS, industry consultation', 'standards' => '1.1, 1.2'],
                ['url' => '/local/rtocompliance/tas.php',         'icon' => 'check-circle',   'title' => 'Assessment Plan',     'desc' => 'Standard 1.3–1.4: Assessment practices & methods (see Section 5 of your TAS)', 'standards' => '1.3, 1.4'],
                ['url' => '/local/rtocompliance/validation.php',  'icon' => 'badge-check',    'title' => 'Validation',          'desc' => 'Standard 1.5: Validation of assessment', 'standards' => '1.5'],
                ['url' => '/local/rtocompliance/rpl.php',         'icon' => 'repeat',         'title' => 'Recognition of Prior Learning &amp; Credit Transfer', 'desc' => 'Standard 1.6–1.7: RPL and credit transfer pathways', 'standards' => '1.6, 1.7'],
                ['url' => '/local/rtocompliance/transitions.php', 'icon' => 'refresh-cw',     'title' => 'Training Product Transitions', 'desc' => 'Clause 14: Manage superseded &amp; deleted training products, teach-out planning &amp; student notifications', 'standards' => 'Clause 14'],
                ['url' => '/local/rtocompliance/tas.php#tas-section-7', 'icon' => 'building-2', 'title' => 'Facilities, Resources &amp; Equipment', 'desc' => 'Standard 1.8: Delivery locations, tools & learning resources (TAS Section 7)', 'standards' => '1.8'],
            ],
        ],
        [
            'title' => 'Quality Area 2: VET Student Support<br><span class="clause-ref">(Part 2, Divisions 1–5)</span>',
            'color' => 'blue',
            'modules' => [
                ['url' => '/local/rtocompliance/marketing_info.php',  'icon' => 'info',            'title' => 'Marketing Information',       'desc' => 'Standard 2.1: Marketing Information — accurate and accessible information about training products and services', 'standards' => '2.1'],
                ['url' => '/local/rtocompliance/students.php',        'icon' => 'clipboard-check', 'title' => 'Pre-enrolment Suitability',   'desc' => 'Standard 2.2: Student Eligibility — pre-enrolment checks, suitability assessment and enrolment evidence', 'standards' => '2.2'],
                ['url' => '/local/rtocompliance/student_support.php', 'icon' => 'users',           'title' => 'Student Support',             'desc' => 'Standard 2.3–2.6: Training support, learner support, diversity, wellbeing & reasonable adjustment', 'standards' => '2.3, 2.4, 2.5, 2.6'],
                ['url' => '/local/rtocompliance/complaints.php',      'icon' => 'message-circle',  'title' => 'Feedback, Complaints &amp; Appeals', 'desc' => 'Standard 2.7–2.8: Feedback processes & appeals', 'standards' => '2.7, 2.8'],
            ],
        ],
        [
            'title' => 'Quality Area 3: VET Workforce<br><span class="clause-ref">(Part 3, Divisions 1–2)</span>',
            'color' => 'green',
            'modules' => [
                ['url' => '/local/rtocompliance/workforce_management.php', 'icon' => 'user-check',     'title' => 'VET Workforce Management',           'desc' => 'Standard 3.1: Appropriate staffing levels & workforce planning', 'standards' => '3.1'],
                ['url' => '/local/rtocompliance/trainers.php',            'icon' => 'graduation-cap', 'title' => 'Trainer &amp; Assessor Competencies', 'desc' => 'Standard 3.2–3.3: TAE qualifications, supervision & currency', 'standards' => '3.2, 3.3'],
            ],
        ],
        [
            'title' => 'Quality Area 4: Governance<br><span class="clause-ref">(Part 4, Divisions 1–3)</span>',
            'color' => 'purple',
            'modules' => [
                ['url' => '/local/rtocompliance/governance.php',                 'icon' => 'building',       'title' => 'Leadership &amp; Accountability', 'desc' => 'Standard 4.1–4.2: Governing persons, accountability obligations', 'standards' => '4.1, 4.2'],
                ['url' => '/local/rtocompliance/risk.php',                       'icon' => 'alert-triangle', 'title' => 'Risk Management',                 'desc' => 'Standard 4.3: Risk register, financial oversight, conflicts of interest', 'standards' => '4.3'],
                ['url' => '/local/rtocompliance/complaints.php?tab=improvement', 'icon' => 'refresh-cw',     'title' => 'Continuous Improvement',          'desc' => 'Standard 4.4: Systematic quality improvement processes', 'standards' => '4.4'],
            ],
        ],
        [
            'title' => 'Practice Guides – Compliance Standards<br><span class="clause-ref">(Information, Integrity, Accountability)</span>',
            'color' => 'rose',
            'modules' => [
                ['url' => '/local/rtocompliance/thirdparty.php',            'icon' => 'link',            'title' => 'Third-Party Arrangements',       'desc' => 'Standard 2.1, 4.2: Third-party delivery agreements', 'standards' => '2.1, 4.2'],
                ['url' => '/local/rtocompliance/feeprotection.php',         'icon' => 'wallet',          'title' => 'Fee Protection',                 'desc' => 'Part 2/Div 3: Prepaid fee threshold & refund policy', 'standards' => '2.3, 2.4'],
                ['url' => '/local/rtocompliance/insurance.php',             'icon' => 'shield',          'title' => 'Insurance Register',             'desc' => 'Part 2/Div 3: Public liability & professional indemnity', 'standards' => '2.3'],
                ['url' => '/local/rtocompliance/certificates.php',          'icon' => 'award',           'title' => 'Certificates &amp; Integrity',   'desc' => 'Integrity of Nationally Recognised Training: 30-day issuance, 30-year records', 'standards' => '2.3, 2.4'],
                ['url' => '/local/rtocompliance/governance.php?tab=adc',    'icon' => 'clipboard-check', 'title' => 'Fit &amp; Proper Person Requirements', 'desc' => 'Standard 4.1–4.2: ADC, governing persons declarations', 'standards' => '4.1, 4.2'],
            ],
        ],
        [
            'title' => 'Data Provision<br><span class="clause-ref">(NCVER/AVETMISS)</span>',
            'color' => 'teal',
            'modules' => [
                ['url' => '/local/rtocompliance/natexport.php', 'icon' => 'download',    'title' => 'NAT/AVETMISS Export',        'desc' => 'Total VET Activity reporting', 'standards' => 'Data Provision'],
                ['url' => '/local/rtocompliance/surveys.php',   'icon' => 'bar-chart-2', 'title' => 'Quality Indicator Surveys',  'desc' => 'Learner & employer questionnaires', 'standards' => 'QI Data'],
            ],
        ],
        [
            'title' => 'Administration',
            'color' => 'slate',
            'modules' => [
                ['url' => '/local/rtocompliance/qualbuilder.php', 'icon' => 'briefcase',  'title' => 'Qualification Builder', 'desc' => 'Build quals, skill sets & auto-certs'],
                ['url' => '/local/rtocompliance/audit.php',       'icon' => 'file-clock', 'title' => 'Audit Log',             'desc' => 'Full activity trail with filters'],
                ['url' => '/local/rtocompliance/deadlines.php',   'icon' => 'calendar',   'title' => 'Deadlines',             'desc' => 'Regulatory dates'],
                ['url' => '/admin/settings.php?section=local_rtocompliance_settings', 'icon' => 'settings', 'title' => 'Settings', 'desc' => 'RTO configuration'],
                ['url' => '/local/rtocompliance/support.php',     'icon' => 'book-open',  'title' => 'Support Docs',          'desc' => 'Help & compliance guides'],
            ],
        ],
    ];
}

/**
 * RPL-OUTCOME-BRIDGE (v5.9.381): write an approved RPL / Credit Transfer decision
 * into the results register so it flows to Student Results, certificates and the
 * AVETMISS NAT export. RPL granted = outcome 51, Credit Transfer = outcome 60.
 * manualoutcome=1 so the auto-grading observer never overwrites it. A Credit
 * Transfer is not delivered/assessed by this RTO, so scheduled hours are zeroed
 * and delivery mode set to "not applicable" (90). Writes ONLY to the plugin
 * register — never Moodle {user_enrolments} or {course_completions}.
 *
 * @return bool true if a register row was written/updated.
 */
function local_rtocompliance_apply_rpl_outcome(int $studentid, string $unitcode, string $unitname,
        string $qualcode, string $qualname, string $rpltype): bool {
    global $DB;
    $unitcode = strtoupper(trim($unitcode));
    if ($studentid <= 0 || $unitcode === '') {
        return false;
    }
    $isct    = ($rpltype === 'credit_transfer');
    $outcome = $isct ? '60' : '51';
    $now     = time();

    $existing = $DB->get_records_select('local_rtocompliance_enrolments',
        'studentid = :sid AND UPPER(unitcode) = :uc',
        ['sid' => $studentid, 'uc' => $unitcode], 'id ASC', 'id', 0, 1);
    if ($existing) {
        $row = reset($existing);
        $upd = (object)[
            'id'                => $row->id,
            'outcomeidentifier' => $outcome,
            'manualoutcome'     => 1,
            'status'            => 'completed',
            'timemodified'      => $now,
        ];
        if ($qualcode !== '') { $upd->programcode = $qualcode; }
        if ($qualname !== '') { $upd->programname = $qualname; }
        if ($unitname !== '') { $upd->unitname = $unitname; }
        // RPL-UPDATE-DELIVERY-FIX (v5.9.416): both RPL (51) and Credit Transfer (60)
        // involve NO delivery, so the unit must report delivery mode 90 (not
        // applicable). Previously only CT set mode 90 on the update path, so an RPL
        // granted over an existing delivery enrolment kept delivery mode 10
        // (classroom) and its original delivered hours — NCVER then saw an RPL unit
        // reported as classroom-delivered. Set mode 90 for both; zero the scheduled
        // hours for CT (national recognition attracts none).
        $upd->deliverymode = '90';
        if ($isct) { $upd->scheduledhours = 0; }
        $DB->update_record('local_rtocompliance_enrolments', $upd);
        return true;
    }

    $row = new stdClass();
    $row->studentid         = $studentid;
    $row->courseid          = 0; // RPL/CT is not tied to a Moodle course.
    $row->programcode       = ($qualcode !== '') ? $qualcode : null;
    $row->programname       = ($qualname !== '') ? $qualname : null;
    $row->unitcode          = $unitcode;
    $row->unitname          = ($unitname !== '') ? $unitname : null;
    $row->outcomeidentifier = $outcome;
    $row->manualoutcome     = 1;
    // P3-2 (v5.9.387): both RPL and Credit Transfer involve no delivery, so delivery
    // mode is 90 (not applicable) — RPL was previously reported as 10 (classroom).
    $row->deliverymode      = '90';
    $row->fundingsourcenat  = '30';
    $row->vetflag           = 'Y';
    $row->vetinschoolsflag  = 'N';
    $row->commencingprogramid = '3';
    $row->feecharged        = 'Y';
    $row->scheduledhours    = $isct ? 0 : null;
    $row->status            = 'completed';
    $row->assessmentdate    = $now;
    // A-P1-2 (v5.9.387): populate activitystartdate so RPL/CT rows are not silently
    // excluded from NAT00120/NAT00060 (whose filter is activitystartdate <= periodend,
    // which drops NULLs) and so the exported start-date field is not blank.
    $row->activitystartdate = $now;
    $row->activityenddate   = $now;
    $row->timecreated       = $now;
    $row->timemodified      = $now;
    try {
        $DB->insert_record('local_rtocompliance_enrolments', $row);
    } catch (\dml_exception $e) {
        return false;
    }
    return true;
}

/**
 * NOMINAL-HOURS (v5.9.418) — resolve the authoritative nominal hours for a unit code
 * from the local reference table. Prefers the RTO's configured reporting state
 * (setting defaultreportingstate), then falls back to the NCVER nationally-agreed
 * value (state = 'NAT'). Returns ['nominalhours','state','sourceref'] or null.
 *
 * @param string $unitcode
 * @return array|null
 */
function local_rtocompliance_lookup_nominalhours(string $unitcode): ?array {
    global $DB;
    $unitcode = strtoupper(trim($unitcode));
    if ($unitcode === '' || !$DB->get_manager()->table_exists('local_rtocompliance_nominalhours')) {
        return null;
    }
    $state = strtoupper(trim((string) (get_config('local_rtocompliance', 'defaultreportingstate') ?: 'NAT')));
    // Try the reporting state first, then the national baseline.
    foreach (array_unique([$state, 'NAT']) as $st) {
        $row = $DB->get_record('local_rtocompliance_nominalhours',
            ['unitcode' => $unitcode, 'state' => $st], 'nominalhours, state, sourceref');
        if ($row) {
            return ['nominalhours' => (int) $row->nominalhours, 'state' => $row->state, 'sourceref' => $row->sourceref];
        }
    }
    return null;
}

/**
 * NOMINAL-HOURS (v5.9.418) — upsert a nominal-hours reference row (unique on
 * unitcode+state). Used by the NCVER/state import. Returns 'created'|'updated'|'skip'.
 *
 * @param string $unitcode
 * @param int    $hours
 * @param string $state
 * @param string $sourceref
 * @param string $trainingpackage
 * @return string
 */
function local_rtocompliance_upsert_nominalhours(string $unitcode, int $hours, string $state = 'NAT',
        string $sourceref = '', string $trainingpackage = ''): string {
    global $DB;
    $unitcode = strtoupper(trim($unitcode));
    $state = strtoupper(trim($state)) ?: 'NAT';
    if ($unitcode === '' || $hours < 0) {
        return 'skip';
    }
    $now = time();
    $existing = $DB->get_record('local_rtocompliance_nominalhours', ['unitcode' => $unitcode, 'state' => $state]);
    if ($existing) {
        $existing->nominalhours    = $hours;
        $existing->sourceref       = $sourceref !== '' ? $sourceref : $existing->sourceref;
        $existing->trainingpackage = $trainingpackage !== '' ? $trainingpackage : $existing->trainingpackage;
        $existing->timemodified    = $now;
        $DB->update_record('local_rtocompliance_nominalhours', $existing);
        return 'updated';
    }
    $DB->insert_record('local_rtocompliance_nominalhours', (object) [
        'unitcode'        => $unitcode,
        'state'           => $state,
        'nominalhours'    => $hours,
        'trainingpackage' => $trainingpackage ?: null,
        'sourceref'       => $sourceref ?: null,
        'timecreated'     => $now,
        'timemodified'    => $now,
    ]);
    return 'created';
}

/**
 * NOMINAL HOURS PHASE 4 (v5.9.421) — authoritative total nominal hours for a whole
 * qualification, resolved by code. Used to pre-fill the TAS "Total Nominal Hours"
 * (which feeds the AQF volume-of-learning justification, Standard 1.1/1.2) so the
 * document is populated from the plugin's own data rather than left blank or hand-keyed.
 *
 * Resolution order (first non-zero wins):
 *   1) the Qualification Builder product's stored nominalhours (Phase 2 rolls this up on save);
 *   2) the live sum of the qualification's unit nominalhours (local_rtocompliance_qualunits);
 *   3) the sum resolved per unit from the authoritative reference table
 *      (local_rtocompliance_nominalhours) for the RTO's reporting jurisdiction.
 *
 * @param string $qualcode qualification code (e.g. BSB50420)
 * @return int total nominal hours (0 if none can be resolved)
 */
function local_rtocompliance_qual_nominal_total(string $qualcode): int {
    global $DB;
    $qualcode = strtoupper(trim($qualcode));
    if ($qualcode === '' || !$DB->get_manager()->table_exists('local_rtocompliance_qualbuilder')) {
        return 0;
    }
    // 1) Stored product total (most recent product row for this code).
    $products = $DB->get_records('local_rtocompliance_qualbuilder',
        ['qualificationcode' => $qualcode], 'id DESC', 'id, nominalhours', 0, 1);
    $product = $products ? reset($products) : null;
    if ($product && (int) $product->nominalhours > 0) {
        return (int) $product->nominalhours;
    }

    // Gather the qualification's units (for paths 2 and 3).
    $units = [];
    if ($product && $DB->get_manager()->table_exists('local_rtocompliance_qualunits')) {
        $units = $DB->get_records('local_rtocompliance_qualunits',
            ['qualbuilderid' => $product->id], '', 'id, unitcode, nominalhours');
    }

    // 2) Live sum of the stored per-unit hours.
    if ($units) {
        $sum = 0;
        foreach ($units as $u) {
            $sum += (int) $u->nominalhours;
        }
        if ($sum > 0) {
            return $sum;
        }
    }

    // 3) Resolve each unit from the authoritative reference table.
    if ($units) {
        $sum = 0;
        foreach ($units as $u) {
            $r = local_rtocompliance_lookup_nominalhours((string) $u->unitcode);
            if ($r !== null) {
                $sum += (int) $r['nominalhours'];
            }
        }
        return $sum;
    }

    return 0;
}

/**
 * PRE-ENROLMENT READINESS (v5.9.423) — consolidate the four ASQA pre-enrolment gates for
 * one student into an audit-ready signal. This does NOT create or block any Moodle
 * enrolment (the plugin only reads Moodle); it surfaces whether the pre-enrolment
 * obligations were met so gaps are visible before results/certificates flow.
 *
 * The four gates (all evidenced from the plugin's own suitability + student records):
 *   1) suitability   — a completed suitability review exists with a genuine decision
 *                       (Standard 2 PI 2 — the RTO assessed the learner's suitability);
 *   2) declaration   — the student signed the pre-enrolment declaration / informed-consent
 *                       attestation (procedural fairness / informed decision);
 *   3) usi           — the student's USI is verified with the Registry (Clause 12);
 *   4) information    — pre-enrolment information was provided to the learner, evidenced by
 *                       the suitability form having been issued and completed (the informed-
 *                       decision touchpoint) — the RTO's handbook/course-guide provision.
 *
 * @param int $userid Moodle user id of the student
 * @return array {
 *     gates: [key => ['label'=>, 'ok'=>bool, 'detail'=>string, 'warn'=>bool]],
 *     ready: bool (all gates ok),
 *     metcount: int, total: int
 * }
 */
function local_rtocompliance_preenrolment_readiness(int $userid): array {
    global $DB;

    $gates = [
        'suitability'  => ['label' => 'Suitability assessed', 'ok' => false, 'detail' => 'No suitability review on file', 'warn' => false],
        'declaration'  => ['label' => 'Student declaration signed', 'ok' => false, 'detail' => 'Not signed', 'warn' => false],
        'usi'          => ['label' => 'USI verified', 'ok' => false, 'detail' => 'USI not verified', 'warn' => false],
        'information'  => ['label' => 'Pre-enrolment information provided', 'ok' => false, 'detail' => 'Not evidenced', 'warn' => false],
    ];

    // --- Suitability + declaration + information (from the latest suitability review) ---
    if ($DB->get_manager()->table_exists('local_rtocompliance_suitability')) {
        $srecs = $DB->get_records('local_rtocompliance_suitability', ['userid' => $userid], 'timemodified DESC', '*', 0, 1);
        $s = $srecs ? reset($srecs) : null;
        if ($s) {
            // Resolve the effective outcome (override wins over engine status).
            $outcome = trim((string) ($s->override_outcome ?: $s->trainer_decision ?: $s->status));
            $done = $outcome !== '' && $outcome !== 'pending' && !empty($s->timecompleted);
            if ($done) {
                if ($outcome === 'not_suitable') {
                    // Reviewed, but the outcome was "not suitable" — a genuine flag, not a pass.
                    $gates['suitability']['ok'] = false;
                    $gates['suitability']['warn'] = true;
                    $gates['suitability']['detail'] = 'Reviewed — assessed NOT suitable';
                } else {
                    $gates['suitability']['ok'] = true;
                    $lbl = ($outcome === 'suitable_with_support') ? 'Suitable with support' : 'Suitable';
                    $gates['suitability']['detail'] = $lbl
                        . (!empty($s->timecompleted) ? ' — ' . userdate($s->timecompleted, '%d %b %Y') : '');
                }
            } else if (!empty($s->timesent)) {
                $gates['suitability']['warn'] = true;
                $gates['suitability']['detail'] = 'Form sent — awaiting completion';
            }

            // Declaration: student typed-name signature on the attestation.
            if (!empty($s->declaration_signed_at)) {
                $gates['declaration']['ok'] = true;
                $gates['declaration']['detail'] = 'Signed ' . userdate($s->declaration_signed_at, '%d %b %Y')
                    . (!empty($s->declaration_name) ? ' by ' . s($s->declaration_name) : '');
            }

            // Information provided: the suitability form was issued to the student and it
            // carries the informed-decision content — completion evidences receipt.
            if (!empty($s->timecompleted)) {
                $gates['information']['ok'] = true;
                $gates['information']['detail'] = 'Suitability form completed ' . userdate($s->timecompleted, '%d %b %Y');
            } else if (!empty($s->timesent)) {
                $gates['information']['warn'] = true;
                $gates['information']['detail'] = 'Information issued ' . userdate($s->timesent, '%d %b %Y') . ' — not yet acknowledged';
            }
        }
    }

    // --- USI verified (student record) ---
    if ($DB->get_manager()->table_exists('local_rtocompliance_students')) {
        $stu = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'id, usi, usiverified');
        if ($stu) {
            if (local_rtocompliance_usi_is_verified($stu->usiverified)) {
                $gates['usi']['ok'] = true;
                $gates['usi']['detail'] = 'Verified with USI Registry';
            } else if (!empty($stu->usi)) {
                $gates['usi']['warn'] = true;
                $gates['usi']['detail'] = 'USI recorded but not yet verified';
            }
        }
    }

    $met = 0;
    foreach ($gates as $g) {
        if ($g['ok']) {
            $met++;
        }
    }

    return [
        'gates'    => $gates,
        'ready'    => $met === count($gates),
        'metcount' => $met,
        'total'    => count($gates),
    ];
}

/**
 * RPL-CT-RETRACT (v5.9.416) — reverse a previously-posted RPL/CT competency when the
 * decision is changed away from approved/partially-approved. Without this, an approved
 * RPL/CT that was later set to Not Approved / Pending left the competent outcome
 * (51/60) sitting in the results register — the unit stayed competent, counted toward
 * completion and could be pulled onto a testamur and into AVETMISS (over-reporting).
 *
 * Retraction rule: find the plugin's own manual RPL/CT row for (student, unit). If it
 * was CREATED by the RPL/CT grant (courseid = 0), delete it — it only existed because
 * the credit was granted. If the grant had merely UPDATED an existing delivery
 * enrolment (courseid > 0), revert its outcome to '70' (continuing activity) and clear
 * the manual flag, preserving the underlying delivery record.
 *
 * @param int    $studentid
 * @param string $unitcode
 * @return bool true if a row was retracted
 */
function local_rtocompliance_retract_rpl_outcome(int $studentid, string $unitcode): bool {
    global $DB;
    $unitcode = strtoupper(trim($unitcode));
    if ($studentid <= 0 || $unitcode === '') {
        return false;
    }
    $rows = $DB->get_records_select('local_rtocompliance_enrolments',
        'studentid = :sid AND UPPER(unitcode) = :uc AND manualoutcome = 1 '
        . "AND outcomeidentifier IN ('51','60')",
        ['sid' => $studentid, 'uc' => $unitcode], 'id ASC');
    if (!$rows) {
        return false;
    }
    $now = time();
    foreach ($rows as $row) {
        if ((int) $row->courseid === 0) {
            $DB->delete_records('local_rtocompliance_enrolments', ['id' => $row->id]);
        } else {
            $DB->update_record('local_rtocompliance_enrolments', (object) [
                'id'                => $row->id,
                'outcomeidentifier' => '70',
                'manualoutcome'     => 0,
                'timemodified'      => $now,
            ]);
        }
    }
    return true;
}

/**
 * USI-STATUS-UNIFY (v5.9.413) — single authoritative test of whether USI
 * verification is set up, recognising BOTH supported methods so every page
 * agrees. Previously the dashboard (index.php) judged setup from the LEGACY
 * local-file keys (usi_certificate_path + usi_organization_id) while the
 * dedicated Machine Credential page (usi_settings.php) judged it from the
 * per-tenant platform status — so a SaaS RTO whose credential is stored on the
 * platform saw "USI verification is not yet set up" on the dashboard even though
 * the setup page correctly said "configured and ready". This helper is the one
 * source of truth.
 *
 * USI is considered configured if EITHER:
 *   (a) PER-TENANT PLATFORM method (recommended, lms-labs.com SaaS): the API
 *       connection is set (apiurl + siteid + apikey) AND a per-tenant machine
 *       credential has been uploaded/confirmed — signalled by the usi_cert_uploaded
 *       flag (set on a successful upload or a certReady status ping) or by the
 *       platform having pushed the credential path back (usi_certificate_path); OR
 *   (b) LEGACY LOCAL-FILE method (self-hosted single-tenant): a machine credential
 *       file path on this server plus the organisation code.
 *
 * @return bool
 */
function local_rtocompliance_usi_is_configured(): bool {
    $apiurl = trim((string) get_config('local_rtocompliance', 'apiurl'));
    $siteid = trim((string) get_config('local_rtocompliance', 'siteid'));
    $apikey = trim((string) get_config('local_rtocompliance', 'apikey'));
    $apiconfigured = ($apiurl !== '' && $siteid !== '' && $apikey !== '');

    $certpath     = trim((string) get_config('local_rtocompliance', 'usi_certificate_path'));
    $certuploaded = (bool) get_config('local_rtocompliance', 'usi_cert_uploaded');

    // (a) PLATFORM method (v5.9.452, credential-broker-only): the machine credential is
    // held on lms-labs.com, which verifies USIs on this site's behalf. USI is ready when
    // the platform connection is configured (apiurl + siteid + apikey) AND the platform
    // has signalled a credential is present (usi_cert_uploaded — set by the webhook push
    // or a first successful verification). The keystore is no longer stored in Moodle, so
    // this no longer looks for a local cert file.
    if ($apiconfigured && $certuploaded) {
        return true;
    }
    // (b) Legacy local-file method — only relevant to old self-hosted single-tenant
    // installs that still have a server-local keystore path set (never used on SaaS).
    $orgid = trim((string) get_config('local_rtocompliance', 'usi_organization_id'));
    if ($certpath !== '' && $orgid !== '') {
        return true;
    }
    return false;
}

/**
 * F3 (v5.9.389): the RTO details that are AQF-mandatory on a certificate. Returns
 * a list of human labels for any that are not yet configured, so issuance can be
 * refused rather than producing a certificate with a blank provider code/signatory.
 *
 * @return string[] missing mandatory setting labels (empty = all present)
 */
function local_rtocompliance_missing_cert_settings(): array {
    $missing = [];
    if (trim((string) get_config('local_rtocompliance', 'rtoname')) === '')       { $missing[] = 'RTO legal name'; }
    if (trim((string) get_config('local_rtocompliance', 'rtocode')) === '')       { $missing[] = 'RTO (national provider) code'; }
    if (trim((string) get_config('local_rtocompliance', 'signatoryname')) === '') { $missing[] = 'Authorised signatory name'; }
    return $missing;
}

/**
 * F1 (v5.9.389): JSON snapshot of the RTO identity settings at issue time, stored on
 * the cert so a later settings change cannot rewrite an already-issued certificate.
 */
function local_rtocompliance_cert_issue_snapshot(): string {
    $snap = [];
    foreach (['rtoname', 'rtocode', 'signatoryname', 'signatorytitle', 'aqfstatement'] as $k) {
        $snap[$k] = (string) (get_config('local_rtocompliance', $k) ?: '');
    }
    return json_encode($snap);
}

/**
 * MOODLE-COMPLETION-LINK (v5.9.406): the set of Moodle courses this user has
 * COMPLETED (course_completions.timecompleted set). READ-ONLY — used to show the
 * effective competency on the results screens: a unit that is not yet finalised in
 * the plugin register but whose delivery course is complete in Moodle displays as
 * Competent. Persisting it to the register (for NAT export / certificates) is done
 * by the completion reconciler ("Sync results from Moodle completions").
 *
 * @return array [courseid => timecompleted]
 */
function local_rtocompliance_moodle_completed_courses(int $userid): array {
    global $DB;
    if ($userid <= 0 || !$DB->get_manager()->table_exists('course_completions')) {
        return [];
    }
    $out = [];
    try {
        $rs = $DB->get_records_select('course_completions',
            'userid = :uid AND timecompleted IS NOT NULL AND timecompleted > 0',
            ['uid' => $userid], '', 'course, timecompleted');
        foreach ($rs as $r) {
            $out[(int) $r->course] = (int) $r->timecompleted;
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * MOODLE-COMPLETION-LINK (v5.9.406): the EFFECTIVE AVETMISS outcome for a unit,
 * factoring in Moodle course completion. If the register outcome is not a finalised
 * result (blank / 00 / 10 / 70 continuing / 85 not started) AND the unit's delivery
 * course is complete in Moodle, the effective outcome is 20 (Competency achieved).
 * Real finalised outcomes (competent, fail, withdrawn, RPL, CT, etc.) are respected.
 *
 * @param string $registeroutcome  the outcomeidentifier stored in the register
 * @param int    $courseid         the unit's Moodle delivery course id (0 if none)
 * @param array  $completedcourses [courseid => timecompleted] from local_rtocompliance_moodle_completed_courses()
 * @return array ['code' => string, 'frommoodle' => bool]
 */
function local_rtocompliance_effective_outcome(string $registeroutcome, int $courseid, array $completedcourses): array {
    $oc = trim($registeroutcome);
    $notfinal = ($oc === '' || in_array($oc, ['00', '10', '70', '85'], true));
    if ($notfinal && $courseid > 0 && isset($completedcourses[$courseid])) {
        return ['code' => '20', 'frommoodle' => true];
    }
    return ['code' => $oc, 'frommoodle' => false];
}

/**
 * DEMOG-PROPAGATION (v5.9.391): after a NAT import, refresh the AVETMISS demographic
 * fields on EXISTING student profiles from the freshly-staged NAT00080 data. This
 * writes ONLY to the plugin's own local_rtocompliance_students table — it never
 * creates or deletes Moodle users, enrolments or completions — so it is safe to run
 * automatically on every import. Brand-new students who have no profile yet are left
 * to the deliberate "Backfill Student Records" step (which can create accounts).
 *
 * A staged value only overwrites the profile when it is a REAL value (not blank and
 * not an AVETMISS "not stated" placeholder like @ / @@), so a sparse re-import never
 * wipes good data. USI is deliberately not touched here (its verification state must
 * be preserved).
 *
 * @return int number of existing profiles updated
 */
function local_rtocompliance_sync_student_demographics_from_staging(): int {
    global $DB;
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_rtocompliance_avetmiss_student')
        || !$dbman->table_exists('local_rtocompliance_students')) {
        return 0;
    }

    $rs = $DB->get_recordset_sql(
        "SELECT s.clientid, s.dob, s.sex, s.suburb, s.state,
                s.postcode, s.buildingname, s.unitno, s.streetname,
                s.indigenousstatus, s.labourforcestatus, s.highestschoollevel,
                s.languageathome, s.countryofbirth, s.disabilityflag,
                s.prioreducationflag, s.atschoolflag
           FROM {local_rtocompliance_avetmiss_student} s
           INNER JOIN (SELECT clientid, MAX(importid) AS mx
                         FROM {local_rtocompliance_avetmiss_student}
                        GROUP BY clientid) m
                   ON m.clientid = s.clientid AND m.mx = s.importid");

    $realval = function ($v) {
        $v = trim((string) $v);
        return ($v !== '' && !preg_match('/^@+$/', $v)) ? $v : null;
    };

    $updated = 0;
    foreach ($rs as $st) {
        $cid = trim((string) $st->clientid);
        if ($cid === '') {
            continue;
        }
        $existing = $DB->get_record('local_rtocompliance_students', ['clientid' => $cid], 'id', IGNORE_MULTIPLE);
        if (!$existing) {
            continue; // No profile yet — account creation is the manual Backfill step.
        }
        $upd = ['id' => $existing->id, 'timemodified' => time()];

        $dobstr = trim((string) ($st->dob ?? ''));
        if (strlen($dobstr) === 8 && ctype_digit($dobstr)) {
            $dd = (int) substr($dobstr, 0, 2); $mm = (int) substr($dobstr, 2, 2); $yy = (int) substr($dobstr, 4, 4);
            if ($dd >= 1 && $dd <= 31 && $mm >= 1 && $mm <= 12 && $yy >= 1900) {
                $ts = mktime(0, 0, 0, $mm, $dd, $yy);
                if ($ts) { $upd['dateofbirth'] = $ts; }
            }
        }
        $sex = strtoupper(trim((string) ($st->sex ?? '')));
        if (in_array($sex, ['M', 'F', 'X'], true)) { $upd['sex'] = $sex; }
        if ($realval($st->suburb) !== null) { $upd['suburb']    = $realval($st->suburb); }
        if ($realval($st->state)  !== null) { $upd['statecode'] = $realval($st->state); }
        if ($realval($st->postcode ?? '')     !== null) { $upd['postcode']     = $realval($st->postcode); }
        if ($realval($st->buildingname ?? '') !== null) { $upd['buildingname'] = $realval($st->buildingname); }
        if ($realval($st->unitno ?? '')       !== null) { $upd['unitno']       = $realval($st->unitno); }
        if ($realval($st->streetname ?? '')   !== null) { $upd['streetname']   = $realval($st->streetname); }
        foreach ([
            'indigenousstatus', 'labourforcestatus', 'highestschoollevel', 'languageathome',
            'countryofbirth', 'disabilityflag', 'prioreducationflag', 'atschoolflag',
        ] as $col) {
            $rv = $realval($st->$col ?? '');
            if ($rv !== null) { $upd[$col] = $rv; }
        }

        if (count($upd) > 2) { // more than id + timemodified
            $DB->update_record('local_rtocompliance_students', (object) $upd);
            $updated++;
        }
    }
    $rs->close();
    return $updated;
}

/**
 * Best-effort Microsoft Teams notification via an Incoming Webhook.
 *
 * Fires ONLY when the RTO has enabled Teams under Integrations and saved a valid
 * https webhook URL. Fully wrapped so a webhook failure can never throw or block
 * certificate issuance — it logs at DEBUG_DEVELOPER and returns false silently.
 * Message format matches the connected-test payload in integrations.php
 * (json_encode(['text' => ...]) posted with the Moodle \curl client).
 *
 * @param string $text Plain-text message body (Teams renders basic markdown).
 * @return bool True if a 2xx response was received, false otherwise.
 */
function local_rtocompliance_teams_notify(string $text): bool {
    global $CFG;
    try {
        if ((string) get_config('local_rtocompliance', 'integ_teams_enable') !== '1') {
            return false;
        }
        $url = trim((string) get_config('local_rtocompliance', 'integ_teams_webhookurl'));
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return false;
        }
        require_once($CFG->libdir . '/filelib.php');
        $payload = json_encode(['text' => $text]);
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl->setHeader(['Content-Type: application/json']);
        $curl->post($url, $payload);
        $code = (int) ($curl->info['http_code'] ?? 0);
        return ($code >= 200 && $code < 300);
    } catch (\Throwable $e) {
        debugging('teams_notify: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
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
    int    $timecompleted = 0,
    bool   $bypassusi     = false
): array {
    global $DB, $USER;

    require_once(__DIR__ . '/classes/cert_template.php');
    require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

    // ── Generate cert number (type-aware: ABC-<TYPE>-YYYY-NNNNN) ──────────────
    $certnumber = local_rtocompliance_generate_cert_number($certtype);

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

    // v5.9.367 EMPTY-SOA-GUARD: never issue a Statement of Attainment / Record of Results
    // that has NO units AND no qualification code to fall back on. The renderer's NULL-units
    // path re-fetches from enrolments by qualification code, so with both empty it would
    // silently emit a unit-less compliance document. Refuse before consuming credits and
    // return a structured skip so Generate-by-Course tallies it instead of issuing a blank.
    if (in_array($certtype, ['statement', 'record'], true) && empty($units) && trim($qualcode) === '') {
        return [
            'ok'         => false,
            'certid'     => null,
            'certnumber' => null,
            'error'      => 'NO_UNITS',
            'skipped'    => true,
            'reason'     => 'Refused to issue an empty ' . $certtype
                . ' — no units and no qualification code for this course/student.',
        ];
    }

    // USI-GATE (v5.9.381; hardened v5.9.387 per audit C-P1-1/C-P2-5): the Student
    // Identifiers Act requires a USI that is VERIFIED with the USI Registry before
    // AQF certification (testamur, record of results, or statement of attainment) is
    // issued. Refuse (structured skip, before consuming credits) when no USI is
    // recorded OR when the recorded USI has not been verified. Pass $bypassusi=true
    // for documented USI exemptions (e.g. wholly-offshore delivery). This now covers
    // 'statement' as well, so a programmatically issued SoA can no longer bypass the
    // gate, and it hard-blocks the UNVERIFIED state (previously presence-only).
    if (!$bypassusi && in_array($certtype, ['testamur', 'record', 'statement'], true)) {
        $stusi = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'usi, usiverified');
        $studusi = trim((string)($stusi->usi ?? ''));
        if ($studusi === '') {
            return [
                'ok'         => false,
                'certid'     => null,
                'certnumber' => null,
                'error'      => 'NO_USI',
                'skipped'    => true,
                'reason'     => 'Refused to issue ' . $certtype . ' — no USI recorded for this student. '
                    . 'A verified USI is required before issuing (or mark the student USI-exempt / override).',
            ];
        }
        if (!local_rtocompliance_usi_is_verified($stusi->usiverified)) {
            return [
                'ok'         => false,
                'certid'     => null,
                'certnumber' => null,
                'error'      => 'USI_UNVERIFIED',
                'skipped'    => true,
                'reason'     => 'Refused to issue ' . $certtype . ' — the USI on file has not been verified with '
                    . 'the USI Registry. Verify the USI (or mark the student USI-exempt / override) before issuing.',
            ];
        }
    }

    // F3 (v5.9.389): refuse to issue AQF certification when the mandatory RTO details
    // (legal name, national provider code, authorised signatory) are not configured —
    // otherwise the certificate renders with those AQF-required fields blank.
    if (in_array($certtype, ['testamur', 'record', 'statement'], true)) {
        $missingset = local_rtocompliance_missing_cert_settings();
        if (!empty($missingset)) {
            return [
                'ok'         => false,
                'certid'     => null,
                'certnumber' => null,
                'error'      => 'MISSING_RTO_SETTINGS',
                'skipped'    => true,
                'reason'     => 'Refused to issue ' . $certtype . ' — required RTO details are not configured: '
                    . implode(', ', $missingset) . '. Set them in RTO Settings before issuing.',
            ];
        }
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
    $cert->issuesnapshot     = local_rtocompliance_cert_issue_snapshot(); // F1: as-issued RTO identity.
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

    // ── Microsoft Teams notification (best-effort, no-op unless enabled) ────────
    $teamscerttypes = local_rtocompliance_get_certificate_types();
    $teamstypelabel = $teamscerttypes[$certtype] ?? strtoupper($certtype);
    $teamsstudent   = $certpublishuser ? fullname($certpublishuser) : ('User #' . $userid);
    local_rtocompliance_teams_notify(
        '✅ Certificate issued: **' . $certnumber . '** — ' . $teamstypelabel
        . ' for ' . $teamsstudent . ' (' . $qualcode . ' ' . $qualname . ').'
    );

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

    static $cache = [];
    $userid = (int)$userid;
    if ($userid <= 0) {
        return false;
    }
    // The gate can ask this question several times per page request, so the answer
    // is cached — but never under PHPUnit, where a test enrols/unenrols a user and
    // then re-asks within the same process and must see the new answer.
    $usecache = !(defined('PHPUNIT_TEST') && PHPUNIT_TEST);
    if ($usecache && array_key_exists($userid, $cache)) {
        return $cache[$userid];
    }

    // Note: Do NOT add LIMIT clause - record_exists_sql() adds its own LIMIT automatically
    $sql = "SELECT 1
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {local_rtocompliance_courses} lrc ON lrc.courseid = e.courseid
             WHERE ue.userid = :userid
               AND ue.status = 0
               AND lrc.nationallyrecognised = 1";

    $requires = $DB->record_exists_sql($sql, ['userid' => $userid]);

    // QUALBUILDER-PATH (v6.3.0): the nationallyrecognised flag on
    // local_rtocompliance_courses is the LEGACY signal. Since the Qual Builder /
    // course-map / category-ancestor resolution was introduced, a student can hold
    // real AVETMISS training-activity records (local_rtocompliance_enrolments) for a
    // qualification, skill set or standalone unit without that flag ever having been
    // ticked on the Moodle course. Those students are just as reportable, so they
    // must also be treated as requiring AVETMISS data.
    //
    // This must only match students who are CURRENTLY training. Three conditions,
    // all required, because none of them is trustworthy on its own:
    //   - the AVETMISS row says in-training ('active' / 'hold'), AND
    //   - the outcome is not a final one — results_importer::build_record() stamps
    //     every imported row 'active' regardless of outcome, so status alone would
    //     sweep in every student ever loaded from a NAT/results file, AND
    //   - the student still holds an ACTIVE Moodle enrolment in that same course,
    //     so people who finished or were unenrolled years ago are never chased for
    //     data they can no longer do anything about.
    // AVETMISS final outcomes: 20 competent, 30 not competent, 40 withdrawn,
    // 51 RPL granted, 52 RPL not granted, 60 credit transfer, 61 superseded CT,
    // 81/82 non-assessable outcomes, 85 not started, 90 result withheld.
    if (!$requires) {
        $finaloutcomes = "'20','30','40','51','52','60','61','81','82','85','90'";
        $requires = $DB->record_exists_sql(
            "SELECT 1
               FROM {local_rtocompliance_enrolments} en
               JOIN {local_rtocompliance_students} st ON st.id = en.studentid
               JOIN {user_enrolments} ue ON ue.userid = st.userid
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = en.courseid
              WHERE st.userid = :userid
                AND ue.status = 0
                AND en.status IN ('active', 'hold')
                AND (en.outcomeidentifier IS NULL
                     OR en.outcomeidentifier NOT IN ($finaloutcomes))",
            ['userid' => $userid]
        );
    }

    if ($usecache) {
        $cache[$userid] = $requires;
    }
    return $requires;
}

// ═════════════════════════════════════════════════════════════════════════════
// AVETMISS PROFILE GATE (v6.3.0)
//
// ASQA Standard 1.8 / Clause 12 and the AVETMISS 2.3 NAT files require accurate
// student data. Collecting it "when someone remembers to ask" does not survive an
// audit, so this group of functions lets the plugin REQUIRE a student in
// nationally recognised training to complete their AVETMISS profile before they
// can use the site: on login they land on My AVETMISS Profile and every other
// page bounces them back there until the mandatory fields are filled in.
//
// The pieces:
//   local_rtocompliance_avetmiss_field_labels()      human labels for each field
//   local_rtocompliance_avetmiss_mandatory_fields()  which fields are mandatory
//   local_rtocompliance_avetmiss_value_missing()     one field's "is it blank?" rule
//   local_rtocompliance_get_missing_avetmiss_fields() the gap list for a user
//   local_rtocompliance_profile_gate_applies()       should this user be locked?
//   local_rtocompliance_profile_gate_check()         the redirect itself
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Human-readable label for every field the profile gate can require.
 *
 * @return array field name => translated label
 */
function local_rtocompliance_avetmiss_field_labels(): array {
    return [
        'usi'                => get_string('usi', 'local_rtocompliance'),
        'dateofbirth'        => get_string('dateofbirth', 'local_rtocompliance'),
        'sex'                => get_string('sex', 'local_rtocompliance'),
        'suburb'             => get_string('suburb', 'local_rtocompliance'),
        'postcode'           => get_string('postcode', 'local_rtocompliance'),
        // Labels reuse the exact strings the profile form prints, so the "you are
        // missing X" list always names the field the student is looking at.
        'statecode'          => get_string('residentialstate', 'local_rtocompliance'),
        'indigenousstatus'   => get_string('atsi', 'local_rtocompliance'),
        'countryofbirth'     => get_string('countryofbirth', 'local_rtocompliance'),
        'languageathome'     => get_string('languageathome', 'local_rtocompliance'),
        'labourforcestatus'  => get_string('labourforcestatus', 'local_rtocompliance'),
        'highestschoollevel' => get_string('schoollevel', 'local_rtocompliance'),
    ];
}

/**
 * The full AVETMISS field set that defines a COMPLETE student profile.
 *
 * This list is deliberately fixed. profilecomplete is a compliance flag read by
 * certificate issuance, NAT readiness counts and the Compliance Health dashboard,
 * so it must not move when an administrator changes what the login gate asks for.
 *
 * @return string[] field names
 */
function local_rtocompliance_avetmiss_all_fields(): array {
    return [
        'usi', 'dateofbirth', 'sex', 'postcode', 'statecode', 'suburb',
        'indigenousstatus', 'countryofbirth', 'languageathome',
        'labourforcestatus', 'highestschoollevel',
    ];
}

/**
 * The AVETMISS fields a student must supply before the login gate lets them through.
 *
 * Defaults to every field EXCEPT the USI. A student cannot conjure a USI on demand —
 * it is issued by usi.gov.au and many enrol before they hold one (or the RTO applies
 * on their behalf) — so making it a condition of accessing the site would lock those
 * students out with no way to comply. Everything else on the list is information the
 * student simply knows and can type in. An administrator who does require a USI up
 * front can tick it in
 * Site administration → RTO Compliance → RTO details → Student data enforcement.
 *
 * Note this is the GATE list, not the completeness definition — a profile is still
 * only "complete" (and a certificate still only issuable) once the USI is present.
 *
 * @return string[] field names
 */
function local_rtocompliance_avetmiss_mandatory_fields(): array {
    $all = local_rtocompliance_avetmiss_all_fields();
    $default = array_values(array_diff($all, ['usi']));

    $raw = get_config('local_rtocompliance', 'mandatoryprofilefields');
    if ($raw === false || $raw === null || trim((string)$raw) === '') {
        return $default;
    }

    // admin_setting_configmulticheckbox stores a comma separated list of ticked keys.
    $chosen = array_filter(array_map('trim', explode(',', (string)$raw)));
    $fields = array_values(array_intersect($all, $chosen));

    // Never end up with an empty mandatory list by accident — that would make the
    // gate a no-op and silently stop collecting data.
    return $fields ?: $default;
}

/**
 * Is a single AVETMISS value effectively blank?
 *
 * AVETMISS uses "@" sentinels for not-stated values ('@' for one-character fields,
 * '@@' for two, '@@@@' for four), which are NOT the same as a real answer — a
 * profile full of sentinels fails NAT validation just as hard as an empty one.
 *
 * @param string $field Field name.
 * @param mixed $value Stored value.
 * @return bool True when the field still needs to be answered.
 */
function local_rtocompliance_avetmiss_value_missing(string $field, $value): bool {
    if ($field === 'dateofbirth') {
        return empty($value) || (int)$value <= 0;
    }

    if ($value === null) {
        return true;
    }

    $v = trim((string)$value);
    if ($v === '') {
        return true;
    }

    // '@', '@@', '@@@@' … any all-sentinel value means "not stated".
    if (trim($v, '@') === '') {
        return true;
    }

    return false;
}

/**
 * Which mandatory AVETMISS fields is this student still missing?
 *
 * @param int $userid Moodle user id.
 * @param stdClass|null $student Optional pre-loaded students row (saves a query).
 * @return array field name => human label, empty when the profile is complete.
 */
function local_rtocompliance_get_missing_avetmiss_fields($userid, $student = null): array {
    global $DB;

    if ($student === null) {
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => (int)$userid]);
    }

    $labels  = local_rtocompliance_avetmiss_field_labels();
    $missing = [];

    foreach (local_rtocompliance_avetmiss_mandatory_fields() as $field) {
        $value = ($student && isset($student->$field)) ? $student->$field : null;
        if (local_rtocompliance_avetmiss_value_missing($field, $value)) {
            $missing[$field] = $labels[$field] ?? $field;
        }
    }

    return $missing;
}

/**
 * Recompute the profilecomplete flag.
 *
 * Single source of truth for staff edits (student_profile.php) and student
 * self-service (my_profile.php), so the flag means the same thing whoever saved
 * the record. Uses the FIXED field set — never the configurable gate list — so
 * changing what the login gate asks for can never silently relax certificate
 * issuance, NAT readiness or the Compliance Health figures.
 *
 * @param stdClass|array $data Student record or submitted form data.
 * @return int 1 when complete, 0 when not.
 */
function local_rtocompliance_calculate_profilecomplete($data): int {
    $obj = is_array($data) ? (object)$data : $data;

    foreach (local_rtocompliance_avetmiss_all_fields() as $field) {
        $value = isset($obj->$field) ? $obj->$field : null;
        if (local_rtocompliance_avetmiss_value_missing($field, $value)) {
            return 0;
        }
    }

    return 1;
}

/**
 * Should the profile gate hold this user? (Ignores which page they are on.)
 *
 * @param int|null $userid Defaults to the current user.
 * @return array|false ['missing' => [field => label]] when the user must be held,
 *                     false when they are free to browse.
 */
function local_rtocompliance_profile_gate_applies($userid = null) {
    global $USER;

    $userid = (int)($userid ?? $USER->id);
    if ($userid <= 0) {
        return false;
    }

    // Off by default-safe: the setting has to be explicitly enabled.
    if (!get_config('local_rtocompliance', 'enforceprofile')) {
        return false;
    }

    // Never hold guests, admins, or a staff member who is logged in AS a student
    // (breaking out of a "login as" session is impossible if we redirect them).
    if (!isloggedin() || isguestuser($userid)) {
        return false;
    }
    if (is_siteadmin($userid)) {
        return false;
    }
    if (class_exists('\core\session\manager') && \core\session\manager::is_loggedinas()) {
        return false;
    }

    // Staff bypass — trainers, assessors, managers and anyone granted the
    // bypass capability keep working normally even if they are also a learner.
    if (has_capability('local/rtocompliance:bypassprofilegate', context_system::instance(), $userid)) {
        return false;
    }

    // NEVER hold a user who cannot actually use the destination page: my_profile.php
    // requires local/rtocompliance:editownprofile, so if a site has overridden that
    // capability away, redirecting there would throw a permissions exception on every
    // page and leave the user with nothing but the logout link.
    if (!has_capability('local/rtocompliance:editownprofile',
            context_user::instance($userid, IGNORE_MISSING) ?: context_system::instance(), $userid)) {
        return false;
    }

    // Only students actually in nationally recognised / reportable training.
    if (!local_rtocompliance_user_requires_avetmiss($userid)) {
        return false;
    }

    $missing = local_rtocompliance_get_missing_avetmiss_fields($userid);
    if (empty($missing)) {
        return false;
    }

    return ['missing' => $missing];
}

/**
 * Paths the gate must never redirect away from, or the user gets stuck in a loop
 * (or locked out of logging out / accepting policies / resetting a password).
 *
 * Matched against the tail of the running script path, so this works on sites
 * installed in a subdirectory too.
 *
 * @return string[]
 */
function local_rtocompliance_profile_gate_allowlist(): array {
    return [
        // The gate's own destination.
        'local/rtocompliance/my_profile.php',
        // Where my_profile.php sends a user it decides does not need AVETMISS data —
        // allowlisted so the two pages can never ping-pong if their conditions
        // ever diverge.
        'user/profile.php',
        // Authentication and account recovery.
        'login/index.php', 'login/logout.php', 'login/change_password.php',
        'login/forgot_password.php', 'login/confirm.php', 'login/signup.php',
        'login/token.php', 'login/set_password.php',
        // Site policies / GDPR consent must still be completable.
        'user/policy.php', 'admin/tool/policy/index.php', 'admin/tool/policy/view.php',
        // Moodle's own required-profile-field form.
        'user/edit.php', 'user/editadvanced.php',
        // Administration (managers are bypassed by capability, but never trap an admin).
        'admin/',
        // File serving, AJAX endpoints, JS/CSS and web services — these are not
        // "pages" and redirecting them breaks the page the student is already on.
        'pluginfile.php', 'draftfile.php', 'tokenpluginfile.php', 'webservice/',
        'lib/ajax/', 'lib/javascript.php', 'lib/requirejs.php', 'lib/scriptslib.php',
        'theme/styles.php', 'theme/javascript.php', 'theme/image.php', 'theme/font.php',
    ];
}

/**
 * The gate itself: bounce a student with an incomplete AVETMISS profile back to
 * My AVETMISS Profile, on every page, until the mandatory fields are filled in.
 *
 * Called from local_rtocompliance_extend_navigation() (which runs while the global
 * navigation is built — after require_login() and before any output) and, as a
 * backstop for page layouts that never build the navigation, from the
 * before_standard_head_html_generation hook.
 *
 * @return void
 */
function local_rtocompliance_profile_gate_check(): void {
    global $USER, $SESSION, $CFG;

    static $alreadychecked = false;
    if ($alreadychecked) {
        return;
    }
    $alreadychecked = true;

    // Emergency off switch for an administrator locked out by a misconfiguration:
    //   $CFG->local_rtocompliance_disable_profile_gate = true;  in config.php
    if (!empty($CFG->local_rtocompliance_disable_profile_gate)) {
        return;
    }

    // Not a browser page view — never redirect these.
    if ((defined('CLI_SCRIPT') && CLI_SCRIPT)
        || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
        || (defined('WS_SERVER') && WS_SERVER)
        || (defined('NO_MOODLE_COOKIES') && NO_MOODLE_COOKIES)) {
        return;
    }

    // Mid-install/upgrade the students table may not even exist yet.
    if (during_initial_install()) {
        return;
    }

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Is the current script one the student must still be able to reach?
    //
    // The allowlist entries are Moodle-root-relative ('login/logout.php', 'admin/'),
    // so the site's own subdirectory has to be stripped off the running script path
    // before comparing — otherwise a Moodle installed at /admin/ or /login/ would
    // match every entry and switch the gate off site-wide, and a plain substring
    // test would allowlist any path that merely contained 'admin/' somewhere.
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        $script = (string)($_SERVER['PHP_SELF'] ?? '');
    }
    $script = ltrim(str_replace('\\', '/', $script), '/');

    $wwwpath = trim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
    if ($wwwpath !== '' && strpos($script, $wwwpath . '/') === 0) {
        $script = substr($script, strlen($wwwpath) + 1);
    }

    foreach (local_rtocompliance_profile_gate_allowlist() as $allowed) {
        if ($allowed !== '' && strpos($script, $allowed) === 0) {
            return;
        }
    }

    $gate = local_rtocompliance_profile_gate_applies((int)$USER->id);
    if ($gate === false) {
        return;
    }

    // Remember where they were headed so we can send them back once the profile
    // is complete — the student finishes the form and lands where they wanted.
    if (empty($SESSION->local_rtocompliance_gate_return)) {
        $me = qualified_me();
        if (!empty($me)) {
            $SESSION->local_rtocompliance_gate_return = $me;
        }
    }

    redirect(new moodle_url('/local/rtocompliance/my_profile.php', ['prompt' => 1]));
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


// ═════════════════════════════════════════════════════════════════════════════
// STRANDED VERSION SELF-REPAIR (v6.3.9)
//
// A Moodle plugin version must be YYYYMMDDXX — 10 digits. Builds of this plugin
// before v6.3.0 used 13-digit savepoints, which are ~1000x larger numerically.
// Any site that ran those steps has a stored version HIGHER than the one
// version.php declares, and Moodle then refuses to ever upgrade the plugin again:
//
//     "A higher version of this plugin is already installed"
//
// The plugin cannot repair this during an upgrade, because Moodle compares the
// two versions BEFORE it runs a single line of plugin code. But a higher stored
// version does NOT put the site into upgrade mode — moodle_needs_upgrading()
// only reacts when the stored version is LOWER — so the site runs normally and
// this plugin's own code executes on every page. That is the window used here.
//
// Deliberately NOT automatic. Lowering a version number puts the site into
// "upgrade pending", and doing that silently to a production site mid-morning is
// not a decision a plugin should make for an administrator. Instead the condition
// is detected, a red banner is shown to site administrators only, and the repair
// happens when they click the button — sesskey-protected, capability-checked, and
// logged. It replaces having to SSH in and run SQL on every affected site.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Is this site's stored plugin version stranded above what version.php declares?
 *
 * @return array|false ['stored' => string, 'declared' => int, 'target' => int]
 *                     or false when everything is healthy.
 */
function local_rtocompliance_version_is_stranded() {
    global $CFG;

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;

    $stored = get_config('local_rtocompliance', 'version');
    if ($stored === false || $stored === null || $stored === '') {
        return $cached;   // Not installed yet — nothing to repair.
    }

    // Read what version.php on disk actually declares, without disturbing globals.
    $plugin = new stdClass();
    $versionfile = $CFG->dirroot . '/local/rtocompliance/version.php';
    if (!is_readable($versionfile)) {
        return $cached;
    }
    include($versionfile);
    if (empty($plugin->version)) {
        return $cached;
    }

    $declared = (int) $plugin->version;

    // Stranded when the stored value exceeds what the files declare. Comparing as
    // strings first guards against any float rounding on a 32-bit PHP build, where
    // a 13-digit integer overflows and comparisons stop being exact.
    $isstranded = (strlen((string) $stored) > strlen((string) $declared))
        || ((string) $stored !== (string) $declared && (float) $stored > (float) $declared);

    if (!$isstranded) {
        return $cached;
    }

    // Target one below the declared version, so the normal Moodle upgrade still
    // runs afterwards and applies anything the site missed — rather than simply
    // asserting "we are up to date" and skipping the schema reconciliation step.
    $cached = [
        'stored'   => (string) $stored,
        'declared' => $declared,
        'target'   => $declared - 1,
    ];
    return $cached;
}

/**
 * Repair a stranded version. Caller MUST have already checked sesskey and capability.
 *
 * @return array ['ok' => bool, 'from' => string, 'to' => int, 'message' => string]
 */
function local_rtocompliance_repair_stranded_version() {
    $state = local_rtocompliance_version_is_stranded();
    if ($state === false) {
        return ['ok' => false, 'from' => '', 'to' => 0,
                'message' => get_string('versionrepair_notneeded', 'local_rtocompliance')];
    }

    set_config('version', $state['target'], 'local_rtocompliance');

    // The plugin manager caches version information hard; without this the admin
    // would click the button, see no change, and reasonably conclude it failed.
    purge_all_caches();

    try {
        require_once(__DIR__ . '/classes/audit_logger.php');
        \local_rtocompliance\audit_logger::log_update(
            'system', 0,
            'Repaired stranded plugin version: ' . $state['stored'] . ' -> ' . $state['target'],
            ['version' => $state['stored']],
            ['version' => $state['target']]
        );
    } catch (\Throwable $e) {
        // Never let audit logging stop the repair itself.
        debugging('Version repair audit log failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return ['ok' => true, 'from' => $state['stored'], 'to' => $state['target'],
            'message' => get_string('versionrepair_done', 'local_rtocompliance',
                (object) ['from' => $state['stored'], 'to' => $state['target']])];
}

/**
 * The red banner shown to site administrators while the version is stranded.
 *
 * @return string HTML, or '' when there is nothing to say.
 */
function local_rtocompliance_version_banner() {
    global $CFG;

    if (!is_siteadmin()) {
        return '';   // Only an administrator can act on this, so only they see it.
    }
    $state = local_rtocompliance_version_is_stranded();
    if ($state === false) {
        return '';
    }

    $url = new moodle_url('/local/rtocompliance/version_repair.php', ['sesskey' => sesskey()]);

    $html  = '<div style="margin:12px;padding:14px 16px;border:2px solid #dc2626;border-radius:8px;'
           . 'background:#fef2f2;color:#7f1d1d;font-size:14px;line-height:1.6;">';
    $html .= '<strong style="font-size:15px;">RTO Compliance cannot be updated on this site</strong><br>';
    $html .= 'Moodle has recorded version <code>' . s($state['stored']) . '</code> for this plugin, which is '
           . 'higher than the <code>' . $state['declared'] . '</code> the installed files declare. That is a '
           . 'legacy numbering fault, not a real newer version — Moodle will refuse every future update with '
           . '<em>"A higher version of this plugin is already installed"</em>.';
    $html .= '<div style="margin-top:10px;">';
    $html .= '<a href="' . s($url->out(false)) . '" style="display:inline-block;padding:8px 16px;'
           . 'background:#dc2626;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">'
           . 'Fix this now</a>';
    $html .= '<span style="margin-left:12px;font-size:13px;">Sets the recorded version to <code>'
           . $state['target'] . '</code> so the normal upgrade can run. No student data is touched.</span>';
    $html .= '</div></div>';

    return $html;
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

    // TAS-NO-FABRICATION (v6.2.39): only assert that feedback was incorporated when the RTO
    // has actually recorded a training or assessment impact above — otherwise this put an
    // unsupported claim into a compliance document. With nothing recorded, prompt the RTO to
    // complete it rather than fabricating a conclusion.
    $lines[] = "";
    if ($trainingImpacts || $assessmentImpacts) {
        $lines[] = "The recorded industry feedback has informed the training and assessment design as documented above.";
    } else {
        $lines[] = "[RTO to record how this industry feedback was incorporated into the training and assessment design.]";
    }

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
    // v5.9.341: also capture the ?section= param so the four plugin_settings.php
    // items (which share one path and differ only by section) highlight correctly
    // instead of all matching — or none matching — via a path-only substring test.
    $currentsection = (!empty($PAGE->url)) ? (string)($PAGE->url->get_param('section') ?? '') : '';

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
        // v5.9.341: registered so Marketing Information, Issue Multi-Unit SOA and
        // NAT Reconciliation no longer fall back to the "?" help glyph.
        'info'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        'file-check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>',
        'search'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    ];

    $icon = function (string $name) use ($icons): string {
        $svg = $icons[$name] ?? $icons['help'];
        return '<span class="rtoc-sb-icon">' . $svg . '</span>';
    };

    // ── Nav structure: [ label, [ [path, label, icon], ... ] ] ──────────────
    // v5.9.341 GETTING-STARTED-ORDER: reordered from the ASQA Quality-Area grouping
    // to a first-step -> last-step onboarding journey that mirrors the dashboard's
    // "Get Started" guide (index.php): Set up the RTO -> Workforce -> Training products
    // -> Students -> Certificates -> Data/Reporting -> Quality & Governance -> Help.
    // Also: added USI Verification + Qualification Certificate Hub (previously
    // off-menu), and removed the dead "Testing Engine" link (testing.php does not exist).
    $groups = [
        [
            'label' => '',
            'items' => [
                ['/local/rtocompliance/how_it_works.php', 'How It Works', 'book'],
                ['/local/rtocompliance/faq.php', 'FAQ', 'help'],
                ['/local/rtocompliance/index.php', 'Dashboard', 'home'],
                ['/local/rtocompliance/compliance_health.php', 'Compliance Health', 'shield'],
                ['/local/rtocompliance/compliance_map.php', 'Compliance Map', 'layout'],
                ['/local/rtocompliance/asqa_standards_map.php', 'ASQA Compliance Mapping', 'check-sq'],
            ],
        ],
        [
            // STEP 1 — Configure the RTO before anything else.
            'label' => '1. Set Up Your RTO',
            'items' => [
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_settings',     'RTO Settings',          'settings'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_certs',        'Certificate Settings',  'award'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_api',          'Platform API Settings', 'link'],
                ['/local/rtocompliance/integrations.php',    'Integrations',                 'link'],
                ['/local/rtocompliance/plugin_settings.php?section=local_rtocompliance_statefunding', 'State Funding',         'dollar'],
                ['/local/rtocompliance/usi_settings.php',   'USI Verification',              'user-check'],
                ['/local/rtocompliance/locations.php',      'Delivery Locations',           'building-2'],
            ],
        ],
        [
            // STEP 2 — VET workforce (QA3).
            'label' => '2. Your Workforce',
            'items' => [
                ['/local/rtocompliance/workforce_management.php', 'Workforce Management',   'users'],
                ['/local/rtocompliance/trainers.php',    'Trainers & Assessors',            'user-check'],
                ['/local/rtocompliance/supervision.php', 'Supervision Log',                 'eye'],
            ],
        ],
        [
            // STEP 3 — Training products (QA1).
            'label' => '3. Training Products',
            'items' => [
                ['/local/rtocompliance/qualbuilder.php', 'Qualification Builder',           'graduation'],
                ['/local/rtocompliance/course_map.php',   'Moodle Course Map',              'network'],
                ['/local/rtocompliance/tas.php',         'Training Strategies (TAS)',       'book'],
                ['/local/rtocompliance/validation.php',  'Validation Register',             'check-sq'],
                ['/local/rtocompliance/rpl.php',         'RPL & Credit Transfer',           'rpl'],
                ['/local/rtocompliance/transitions.php', 'Training Transitions',            'refresh'],
            ],
        ],
        [
            // STEP 4 — Students & support (QA2).
            'label' => '4. Students & Support',
            'items' => [
                ['/local/rtocompliance/marketing_info.php',  'Marketing Information',       'info'],
                ['/local/rtocompliance/students.php',        'Student Records',             'users'],
                ['/local/rtocompliance/student_support.php', 'Student Support',             'shield'],
                ['/local/rtocompliance/qualbuilder_results.php', 'Student Results',         'bar-chart'],
            ],
        ],
        [
            // STEP 5 — Certificates & statements of attainment.
            'label' => '5. Certificates',
            'items' => [
                ['/local/rtocompliance/qual_cert_hub.php',         'Qual Certificate Hub',   'award'],
                ['/local/rtocompliance/certificates.php',          'Issued Certificates',    'file-check'],
                ['/local/rtocompliance/generate_course_certs.php', 'Generate by Course',     'graduation'],
                ['/local/rtocompliance/soa_issue.php',             'Issue Multi-Unit SOA',   'file-check'],
                ['/local/rtocompliance/cert_templates.php',        'Certificate Templates',  'layout'],
                ['/local/rtocompliance/cert_test.php',             'Test Certificate',       'play-sq'],
            ],
        ],
        [
            // STEP 6 — Data provision & AVETMISS/NAT reporting.
            'label' => '6. Data & Reporting',
            'items' => [
                ['/local/rtocompliance/data_import.php', 'Data Import',                     'upload'],
                ['/local/rtocompliance/nat_validate.php', 'AVETMISS Validation',            'check-sq'],
                ['/local/rtocompliance/natexport.php',   'AVETMISS Export',                 'download'],
                ['/local/rtocompliance/reconcile.php',   'NAT Reconciliation',              'search'],
                ['/local/rtocompliance/ai_usage_report.php', 'AI Credit Usage',             'trending'],
            ],
        ],
        [
            // STEP 7 — Ongoing quality assurance & governance (QA4 + compliance).
            'label' => '7. Quality & Governance',
            'items' => [
                ['/local/rtocompliance/surveys.php',     'Surveys & Quality Indicators',    'message'],
                ['/local/rtocompliance/complaints.php',  'Complaints & Appeals',            'alert'],
                ['/local/rtocompliance/governance.php',  'Leadership & Governance',         'building'],
                ['/local/rtocompliance/risk.php',        'Risk Management',                 'shield'],
                ['/local/rtocompliance/thirdparty.php',  'Third-Party Arrangements',        'link'],
                ['/local/rtocompliance/feeprotection.php','Fee Protection',                 'dollar'],
                ['/local/rtocompliance/insurance.php',   'Insurance Register',              'umbrella'],
            ],
        ],
        [
            // Help & reference — always last.
            'label' => 'Help',
            'items' => [
                ['/local/rtocompliance/support.php',         'Support Centre',              'help'],
                ['/local/rtocompliance/practice_guides.php', 'Practice Guides',             'scale'],
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
    // Show the ACTUAL installed version on every page, so "did the upgrade run?" is answerable
    // at a glance. The number shown is the version Moodle has recorded in its database (i.e. the
    // version the DB has actually been upgraded to) — not merely what the files claim.
    $rtoc_sub = 'ASQA 2025';
    $rtoc_dbver = '';
    try {
        $rtoc_dbver = (string) get_config('local_rtocompliance', 'version');
        $rtoc_rel = '';
        $rtoc_info = \core_plugin_manager::instance()->get_plugin_info('local_rtocompliance');
        if ($rtoc_info && isset($rtoc_info->release)) {
            $rtoc_rel = trim((string) $rtoc_info->release);
        }
        if ($rtoc_rel !== '') {
            $rtoc_sub .= ' &middot; v' . s($rtoc_rel);
        } else if ($rtoc_dbver !== '') {
            $rtoc_sub .= ' &middot; v' . s($rtoc_dbver);
        }
    } catch (\Throwable $e) {
        $rtoc_sub = 'ASQA 2025';
    }
    $rtoc_subtitleattr = $rtoc_dbver !== ''
        ? ' title="Database version (what your Moodle has actually upgraded to): ' . s($rtoc_dbver) . '"'
        : '';
    $html .= '<span class="rtoc-sb-brand-sub"' . $rtoc_subtitleattr . '>' . $rtoc_sub . '</span>';
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
            // v5.9.341: match on the resolved path, and when the menu item carries a
            // ?section= query (settings pages) require that section to match too.
            $itemparts = explode('?', $path, 2);
            $itempath  = $itemparts[0];
            $active    = '';
            if ($itempath !== '' && $itempath === $currentpath) {
                if (isset($itemparts[1])) {
                    parse_str($itemparts[1], $itemquery);
                    $active = (!empty($itemquery['section']) && $itemquery['section'] === $currentsection)
                        ? ' rtoc-sb-active' : '';
                } else {
                    $active = ' rtoc-sb-active';
                }
            }
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
    /* v5.9.341 LIGHT-SIDEBAR: recoloured from near-black (#0d1424, group labels
       failing WCAG at 1.77:1) to a modern light slate surface. Nav text #475569 on
       #f8fafc = 8.3:1, group labels #64748b = 4.9:1 — both pass AA. */
    --sb-bg:          #f8fafc;               /* slate-50 surface */
    --sb-header-bg:   #ffffff;               /* white header/footer */
    --sb-accent:      #2563eb;               /* blue-600 */
    --sb-accent-glow: #eef4ff;               /* blue-50 active pill fill */
    --sb-text:        #475569;               /* slate-600 nav label */
    --sb-text-bright: #0f172a;               /* slate-900 hover/active */
    --sb-text-active: #1d4ed8;               /* blue-700 active label */
    --sb-border:      #e5e9f0;               /* slate-200 hairline */
    --sb-hover-bg:    #eef2f7;               /* slate-100 hover */
    --sb-muted:       #64748b;               /* slate-500 for sub-labels/icons */
    --sb-chrome:      rgba(15,23,42,0.06);   /* dark alpha for dividers/scrollbars on light */
    --sb-chrome-2:    rgba(15,23,42,0.12);
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
    box-shadow: 1px 0 0 var(--sb-border), 6px 0 24px rgba(15,23,42,0.05);
    transition:
        width        0.24s var(--sb-ease),
        min-width    0.24s var(--sb-ease),
        flex-basis   0.24s var(--sb-ease);
    font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    z-index: 100;
    flex-shrink: 0;
    scrollbar-width: thin;
    scrollbar-color: var(--sb-chrome) transparent;
}
#rtoc-sidebar::-webkit-scrollbar { width: 3px; }
#rtoc-sidebar::-webkit-scrollbar-track { background: transparent; }
#rtoc-sidebar::-webkit-scrollbar-thumb { background: var(--sb-chrome); border-radius: 99px; }

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
    color: var(--sb-muted);
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
    border: 1px solid var(--sb-chrome);
    cursor: pointer;
    color: var(--sb-muted);
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
    background: var(--sb-chrome);
    border-color: var(--sb-chrome-2);
}
#rtoc-sidebar .rtoc-sb-toggle svg { width: 16px; height: 16px; }

/* ── Nav scroll area ─────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-nav {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 10px 8px 6px;
    scrollbar-width: thin;
    scrollbar-color: var(--sb-chrome) transparent;
}
#rtoc-sidebar .rtoc-sb-nav::-webkit-scrollbar { width: 3px; }
#rtoc-sidebar .rtoc-sb-nav::-webkit-scrollbar-thumb { background: var(--sb-chrome); border-radius: 99px; }

/* ── Group labels ────────────────────────────────────────────────────── */
#rtoc-sidebar .rtoc-sb-group-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--sb-muted);
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
    background: var(--sb-chrome);
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
    color: var(--sb-muted);
    flex-shrink: 0;
    transition: color 0.18s, background 0.18s;
}
#rtoc-sidebar .rtoc-sb-icon svg { width: 15px; height: 15px; }

#rtoc-sidebar .rtoc-sb-item:hover .rtoc-sb-icon {
    color: var(--sb-text-bright);
    background: var(--sb-hover-bg);
}
#rtoc-sidebar .rtoc-sb-item.rtoc-sb-active .rtoc-sb-icon {
    color: #2563eb;
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
    color: #2563eb;
}
#rtoc-sidebar .rtoc-sb-credits-icon svg { width: 13px; height: 13px; }
#rtoc-sidebar .rtoc-sb-credits-text {
    display: flex; flex-direction: column; overflow: hidden; min-width: 0;
}
#rtoc-sidebar .rtoc-sb-credits-val {
    font-size: 13px; font-weight: 700; color: #2563eb; white-space: nowrap;
}
#rtoc-sidebar .rtoc-sb-credits-lbl {
    font-size: 10px; color: var(--sb-muted); white-space: nowrap; font-weight: 500;
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
    border: 1px solid var(--sb-chrome);
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
    background: #ffffff;
    border: 1px solid #e5e9f0;
    border-radius: 8px;
    width: 40px; height: 40px;
    cursor: pointer;
    align-items: center; justify-content: center;
    color: #475569;
    box-shadow: 0 2px 10px rgba(15,23,42,0.12);
    transition: background 0.18s, color 0.18s;
}
#rtoc-mobile-btn:hover { background: #f1f5f9; color: #0f172a; }
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
(function () {
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
            mobileBtn.addEventListener('click', function () {
                sidebar.classList.toggle('rtoc-sb-mobile-open');
                if (overlay) overlay.classList.toggle('rtoc-overlay-visible');
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function () {
                sidebar.classList.remove('rtoc-sb-mobile-open');
                overlay.classList.remove('rtoc-overlay-visible');
            });
        }
        sidebar.querySelectorAll('.rtoc-sb-item').forEach(function (a) {
            a.addEventListener('click', function () {
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
        toggleBtn.addEventListener('click', function () {
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
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d) return;
            if (d.isUnlimited || d.creditsRaw === -1) {
                valEl.textContent = 'Unlimited';
                valEl.style.color = '#34d399';
            } else {
                var bal = d.credits !== undefined ? Number(d.credits) : 0;
                valEl.textContent = bal.toLocaleString() + ' credits';
                valEl.style.color = bal < 10 ? '#f87171' : (bal < 50 ? '#fbbf24' : '#2563eb');
            }
        })
        .catch(function () { /* silent — credits widget is non-critical UI */ });
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
        setTimeout(function () {
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

    window.addEventListener('resize', function () {
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

    // v6.2.68: Emit the floating AI assistant here, inside the plugin's own
    // main-region output, so it renders on themes that drop hook-injected HTML
    // (e.g. "academi"). Returns '' unless enabled + staff + creds ready.
    $html .= local_rtocompliance_assistant_widget_html();

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
    global $PAGE, $SESSION;

    // AVETMISS PROFILE GATE (v6.3.0, replaces the one-shot LOGIN-PROFILE-PROMPT
    // of v5.9.314) ─────────────────────────────────────────────────────────────
    // The old behaviour set a session flag at login and consumed it on the very
    // next page: one nudge, then the student could click away and carry on
    // training with no date of birth, no USI and no address — which is exactly
    // the data ASQA expects the RTO to hold. The gate below is persistent: it
    // re-checks on every page build, so an incomplete student keeps landing back
    // on My AVETMISS Profile until the mandatory fields are filled in.
    //
    // This runs while the global navigation is initialised — after require_login()
    // on the destination page and before any output — so redirect() is safe here.
    // Guests, admins, "login as" sessions, staff with the bypass capability, and
    // the allowlisted pages (login, logout, policies, admin, file serving) are all
    // excluded inside local_rtocompliance_profile_gate_check().
    local_rtocompliance_profile_gate_check();

    // Legacy flag from the pre-6.3.0 observer — clear it so an old session that
    // still carries it doesn't leave stale state behind. The gate above has
    // already made the redirect decision on its own.
    if (isset($SESSION->local_rtocompliance_needs_profile)) {
        unset($SESSION->local_rtocompliance_needs_profile);
    }

    if (!isloggedin() || isguestuser()) {
        return;
    }
    $role = local_rtocompliance_user_role();
    if ($role === 'student') {
        // v5.9.393: give students a persistent "My Certificates" navigation link so
        // they can reach their certificate download portal from anywhere after logging
        // in (previously students got no plugin nav entry at all — the only link was
        // buried on their Moodle profile page). mycerts.php enforces its own access
        // control (a student only ever sees their own certificates).
        if (has_capability('local/rtocompliance:viewown', context_system::instance())) {
            $nav->add(
                get_string('mycertificates', 'local_rtocompliance'),
                new moodle_url('/local/rtocompliance/mycerts.php'),
                navigation_node::TYPE_CUSTOM,
                null,
                'local_rtocompliance_mycerts',
                new pix_icon('i/badge', '')
            );
        }
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

    // v5.9.321 ORPHAN-FIX: emailcerts setting was defined in RTO Settings → Certificate
    // Settings but never checked here. When disabled, return early without sending or
    // recording. Callers should treat success=false + reason='disabled' as non-fatal.
    if (!get_config('local_rtocompliance', 'emailcerts')) {
        // EMAIL-RESULT-SHAPE (v5.9.406): every caller (email_cert.php, bulk_action_cert.php)
        // reads $res['ok'], $res['email'] and $res['error']. The disabled path previously
        // returned ['success'=>false,'reason'=>...] with none of those keys, producing an
        // "undefined array key 'ok'" warning and a blank failure reason. Return the
        // canonical shape so the disabled state surfaces as a clean, explained skip.
        return [
            'ok'      => false,
            'email'   => $user->email ?? '',
            'error'   => 'Certificate email delivery is disabled in RTO Settings → Certificate Settings.',
            'reason'  => 'disabled',
        ];
    }

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
    register_shutdown_function (function () use ($temppath) {
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
        if (!$activetmpl) {
            // v5.9.361 NO-MULTIPAGE: with no admin template active, render the
            // single-page ASQA starter design through the (single-page) template
            // renderer instead of dropping to the legacy multi-page TCPDF fallback
            // below (which uses SetAutoPageBreak(true) + flowing MultiCell content
            // and paginates to 2-3 pages). Guarantees exactly one page + a correctly
            // organised layout for every certificate, template or not.
            $startertype = in_array($cert->certtype, ['testamur', 'statement', 'record', 'completion'], true)
                ? $cert->certtype : 'statement';
            $starterdesign = \local_rtocompliance\cert_template::build_starter_design($startertype);
            $activetmpl = (object) [
                'id'         => 0,
                'name'       => ucfirst($startertype),
                'certtype'   => $startertype,
                'audience'   => 'default',
                'designjson' => json_encode($starterdesign),
            ];
        }
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
    // FIX-RTO-LOGO-SETTINGS-GAP (v5.9.318): use resolve_compliance_asset_path so the
    // RTO Settings page logo (filearea 'logo') is used as a fallback when no logo has
    // been uploaded via the cert template branding panel (FA_BRANDING filearea).
    $rtologopath = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
        \local_rtocompliance\cert_template::BRANDING_ITEMID_LOGO,
        'logo',
        ''
    );
    // FIX-LEGACY-SIG-SETTINGS-GAP (v5.9.327): the legacy fallback renderer was
    // calling get_branding_path() (FA_BRANDING filearea only) for the CEO signature,
    // so RTOs who uploaded the signature via RTO Settings → Certificate Elements
    // (filearea 'ceo_signature_file') always got a blank signature on the legacy path.
    // The modern renderer already uses resolve_compliance_asset_path() correctly since
    // v5.9.320. Now both paths are consistent.
    $sigpath     = \local_rtocompliance\cert_template::resolve_compliance_asset_path(
        \local_rtocompliance\cert_template::BRANDING_ITEMID_SIGNATURE, 'ceo_signature_file', ''
    );

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
    // v5.9.361 NO-MULTIPAGE: auto page-break OFF so this legacy fallback can never
    // paginate to 2-3 pages (the "3-page nightmare"). It is now only reached if the
    // single-page template renderer above throws; a clipped single page is the
    // correct degraded behaviour there.
    $pdf->SetAutoPageBreak(false, 0);
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
            $pdf->Cell($contentw, 8, $cert->qualificationcode . ' ' . $cert->qualificationname, 0, 1, 'C');
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

/**
 * AUTO-CREATE (v5.9.437): pull an AVETMISS code + human name out of a category name.
 *
 * RTO category names carry the code at either end, e.g. "ABC12345 — a Diploma
 * qualification" or the reverse "a Diploma qualification — ABC12345", and unit categories
 * like "ABC12345 — Load and unload goods". Returns the first code token found plus the
 * remaining text as the name (separators trimmed). Empty code = no AVETMISS token present.
 *
 * @param  string $text Category name.
 * @return array ['code' => CODE|'', 'name' => remainder|'']
 */
function local_rtocompliance_extract_code_from_text(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return ['code' => '', 'name' => ''];
    }
    if (!preg_match('/\b([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        return ['code' => '', 'name' => ''];
    }
    $code   = strtoupper($m[1][0]);
    $offset = (int)$m[1][1];
    $name   = substr($text, 0, $offset) . substr($text, $offset + strlen($m[1][0]));
    // EMPTY-BRACKET-FIX (v5.9.448): when the code sat INSIDE brackets — e.g.
    // "a Diploma qualification (ABC12345)" — removing the code leaves an empty
    // "()" (or "[]"/"{}") that then printed as "a Diploma qualification ()".
    // Strip any now-empty bracket pair, then collapse the doubled whitespace it
    // leaves behind, before the end-separator trim.
    $name = preg_replace('/[\(\[\{]\s*[\)\]\}]/u', ' ', (string)$name);
    $name = preg_replace('/\s{2,}/u', ' ', (string)$name);
    // Trim leftover separators / whitespace from both ends (– — - : | • and spaces).
    $name = preg_replace('/^[\s\x{2013}\x{2014}\-:|•]+|[\s\x{2013}\x{2014}\-:|•]+$/u', '', (string)$name);
    return ['code' => $code, 'name' => trim((string)$name)];
}

/**
 * AUTO-CREATE (v5.9.437): READ-ONLY scan of the Moodle category tree for qualifications
 * that could be built in the Qualification Builder.
 *
 * Structure assumed (the standard RTO layout this plugin already relies on):
 *   Parent category   → qualification (name carries the qual CODE + TITLE, either order)
 *     └ Leaf category → unit          (name carries the unit CODE + NAME)
 *          └ course(s) → the Moodle delivery course(s) for that unit
 *
 * A "unit category" is the BOTTOM-MOST coded category (a coded category with no coded
 * descendant); its nearest coded ancestor is its qualification. This handles both a
 * 2-level tree (qual → unit) and a 3-level tree (qual → stream → unit). Delivery courses
 * are gathered from each unit category's subtree.
 *
 * Writes NOTHING. Returns one entry per candidate qualification with its units, detected
 * courses and whether a Qual Builder product already exists for that code.
 *
 * @return array List of ['qualcode','qualname','categoryid','catname','exists',
 *               'unitcount','linkedcount','units'=>[['unitcode','unitname','courseids'[],'coursenames'[]]]]
 */
function local_rtocompliance_scan_categories_for_quals(): array {
    global $DB;

    $cats = $DB->get_records('course_categories', null, 'depth ASC, sortorder ASC',
        'id, name, parent, depth');
    if (empty($cats)) {
        return [];
    }

    $parentOf   = [];
    $childrenOf = [];
    $codeinfo   = [];   // catid => ['code','name']
    $catname    = [];
    foreach ($cats as $cat) {
        $cid = (int)$cat->id;
        $parentOf[$cid] = (int)$cat->parent;
        $childrenOf[(int)$cat->parent][] = $cid;
        $codeinfo[$cid] = local_rtocompliance_extract_code_from_text((string)$cat->name);
        $catname[$cid]  = (string)$cat->name;
    }

    // Does this category have any descendant category that itself carries a code?
    $hasCodedDescendant = function (int $catid) use (&$hasCodedDescendant, $childrenOf, $codeinfo): bool {
        foreach ($childrenOf[$catid] ?? [] as $ch) {
            if (!empty($codeinfo[$ch]['code']) || $hasCodedDescendant($ch)) {
                return true;
            }
        }
        return false;
    };

    // Nearest ancestor category carrying a code = the qualification of a unit category.
    $nearestCodedAncestor = function (int $catid) use ($parentOf, $codeinfo): int {
        $p = $parentOf[$catid] ?? 0;
        $hops = 0;
        while ($p > 0 && $hops < 20) {
            if (!empty($codeinfo[$p]['code'])) {
                return $p;
            }
            $p = $parentOf[$p] ?? 0;
            $hops++;
        }
        return 0;
    };

    $quals = []; // qualcatid => ['qualcode','qualname','categoryid','catname','units'=>[ucode=>['unitname','courses'=>[cid=>name]]]]
    foreach ($cats as $cat) {
        $cid = (int)$cat->id;
        if (empty($codeinfo[$cid]['code'])) {
            continue;                     // not a coded category
        }
        if ($hasCodedDescendant($cid)) {
            continue;                     // a container (qualification / grouping), not a unit
        }
        // $cid is the bottom-most coded category = a UNIT.
        $qualcatid = $nearestCodedAncestor($cid);
        if ($qualcatid <= 0) {
            continue;                     // no parent qualification category — cannot place it
        }
        if (!isset($quals[$qualcatid])) {
            $qn = $codeinfo[$qualcatid]['name'] !== '' ? $codeinfo[$qualcatid]['name'] : $codeinfo[$qualcatid]['code'];
            $quals[$qualcatid] = [
                'qualcode'   => $codeinfo[$qualcatid]['code'],
                'qualname'   => $qn,
                'categoryid' => $qualcatid,
                'catname'    => $catname[$qualcatid],
                'units'      => [],
            ];
        }
        $ucode = $codeinfo[$cid]['code'];
        $uname = $codeinfo[$cid]['name'] !== '' ? $codeinfo[$cid]['name'] : $ucode;
        if (!isset($quals[$qualcatid]['units'][$ucode])) {
            $quals[$qualcatid]['units'][$ucode] = ['unitname' => $uname, 'courses' => []];
        }
        // Gather delivery courses in this unit category's subtree (cohort/semester copies included).
        $subids = local_rtocompliance_get_category_subtree_ids($cid);
        if (!empty($subids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED, 'ac');
            $courses = $DB->get_records_sql(
                "SELECT id, fullname, shortname FROM {course} WHERE id > 1 AND category $insql ORDER BY id DESC",
                $inparams);
            foreach ($courses as $co) {
                $quals[$qualcatid]['units'][$ucode]['courses'][(int)$co->id] =
                    ($co->fullname !== '' ? $co->fullname : $co->shortname);
            }
        }
    }

    $out = [];
    foreach ($quals as $q) {
        if (empty($q['units'])) {
            continue;
        }
        $exists = $DB->record_exists_select('local_rtocompliance_qualbuilder',
            'UPPER(qualificationcode) = ?', [strtoupper($q['qualcode'])]);
        $unitlist = [];
        $linked   = 0;
        foreach ($q['units'] as $ucode => $u) {
            $courseids = array_keys($u['courses']);
            if (!empty($courseids)) {
                $linked++;
            }
            $unitlist[] = [
                'unitcode'    => $ucode,
                'unitname'    => $u['unitname'],
                'courseids'   => array_values(array_map('intval', $courseids)),
                'coursenames' => array_values($u['courses']),
            ];
        }
        usort($unitlist, function ($a, $b) {
            return strcmp($a['unitcode'], $b['unitcode']);
        });
        $out[] = [
            'qualcode'    => $q['qualcode'],
            'qualname'    => $q['qualname'],
            'categoryid'  => (int)$q['categoryid'],
            'catname'     => $q['catname'],
            'exists'      => (bool)$exists,
            'unitcount'   => count($unitlist),
            'linkedcount' => $linked,
            'units'       => $unitlist,
        ];
    }
    usort($out, function ($a, $b) {
        return strcmp($a['qualcode'], $b['qualcode']);
    });
    return $out;
}

/**
 * AUTO-CREATE (v5.9.438): READ-ONLY scan of the existing Moodle Course Map for
 * qualifications that could be built in the Qualification Builder.
 *
 * The Course Map already holds one row per Moodle course → (qualcode, unitcode), so this
 * groups those rows by qualification code, collects each qual's units and their delivery
 * courses, and (best-effort) reads the qualification's human name from its Moodle root
 * category. Unit names default to the unit code (the map stores no names) and can be
 * enriched from training.gov.au at create time. This is the source to use when your Course
 * Map is already seeded/confirmed — it reuses that data rather than re-walking categories.
 *
 * Writes NOTHING. Returns the same structure as local_rtocompliance_scan_categories_for_quals().
 *
 * @return array
 */
function local_rtocompliance_scan_coursemap_for_quals(): array {
    global $DB;

    if (!$DB->get_manager()->table_exists('local_rtocompliance_course_map')) {
        return [];
    }
    $rows = $DB->get_records_sql(
        "SELECT cm.id, cm.courseid, cm.qualcode, cm.unitcode, c.fullname, c.shortname
           FROM {local_rtocompliance_course_map} cm
           LEFT JOIN {course} c ON c.id = cm.courseid
          WHERE cm.qualcode IS NOT NULL AND cm.qualcode <> ''
            AND cm.unitcode IS NOT NULL AND cm.unitcode <> ''
          ORDER BY cm.qualcode ASC, cm.unitcode ASC, cm.courseid ASC");
    if (empty($rows)) {
        return [];
    }

    $quals = []; // qualcode => ['units' => [unitcode => ['courses' => [cid => name]]]]
    foreach ($rows as $r) {
        $qc = strtoupper(trim((string)$r->qualcode));
        $uc = strtoupper(trim((string)$r->unitcode));
        if ($qc === '' || $uc === '') {
            continue;
        }
        if (!isset($quals[$qc])) {
            $quals[$qc] = ['units' => []];
        }
        if (!isset($quals[$qc]['units'][$uc])) {
            $quals[$qc]['units'][$uc] = ['courses' => []];
        }
        if (!empty($r->courseid)) {
            $quals[$qc]['units'][$uc]['courses'][(int)$r->courseid] =
                ($r->fullname !== null && $r->fullname !== '') ? $r->fullname : (string)($r->shortname ?? '');
        }
    }

    // Resolve human names from the category tree (the SAME source the category-tree scanner
    // uses): every category whose name carries a code contributes CODE -> name, covering both
    // qualification categories ("ABC12345 — Diploma of …") and unit leaf categories
    // ("ABC12345 — Load and unload goods"). Unit/qual titles are national, so one map is safe.
    // Prefer the longest name found for a code. This is why the Course Map source still gets
    // real names even though the map table itself stores only codes.
    $namemap = [];
    foreach ($DB->get_records('course_categories', null, '', 'id, name') as $cat) {
        $ext = local_rtocompliance_extract_code_from_text((string)$cat->name);
        if ($ext['code'] !== '' && $ext['name'] !== '') {
            $code = $ext['code'];
            if (!isset($namemap[$code]) || strlen($ext['name']) > strlen($namemap[$code])) {
                $namemap[$code] = $ext['name'];
            }
        }
    }

    $out = [];
    foreach ($quals as $qc => $q) {
        // Qualification name resolved from the category-name map (falls back to the code).
        $qname   = $namemap[$qc] ?? $qc;
        $catname = '';
        $rootcat = local_rtocompliance_get_qual_root_category_id($qc);
        if ($rootcat > 0) {
            $catname = (string)($DB->get_field('course_categories', 'name', ['id' => $rootcat]) ?: '');
        }
        $exists = $DB->record_exists_select('local_rtocompliance_qualbuilder',
            'UPPER(qualificationcode) = ?', [strtoupper($qc)]);

        $unitlist = [];
        $linked   = 0;
        foreach ($q['units'] as $uc => $u) {
            $courseids = array_keys($u['courses']);
            if (!empty($courseids)) {
                $linked++;
            }
            $unitlist[] = [
                'unitcode'    => $uc,
                'unitname'    => $namemap[$uc] ?? $uc, // resolved from the unit's category name.
                'courseids'   => array_values(array_map('intval', $courseids)),
                'coursenames' => array_values($u['courses']),
            ];
        }
        usort($unitlist, function ($a, $b) {
            return strcmp($a['unitcode'], $b['unitcode']);
        });
        $out[] = [
            'qualcode'    => $qc,
            'qualname'    => $qname,
            'categoryid'  => (int)$rootcat,
            'catname'     => $catname,
            'exists'      => (bool)$exists,
            'unitcount'   => count($unitlist),
            'linkedcount' => $linked,
            'units'       => $unitlist,
        ];
    }
    usort($out, function ($a, $b) {
        return strcmp($a['qualcode'], $b['qualcode']);
    });
    return $out;
}

/**
 * AUTO-CREATE (v5.9.437): create Qualification Builder products from a scan (category tree
 * or Course Map), for the selected qual codes only.
 *
 * Writes ONLY to the plugin's own tables: local_rtocompliance_qualbuilder,
 * local_rtocompliance_qualunits and (for cohort/semester copies) local_rtocompliance_qualunit_courses.
 * It never creates or changes Moodle categories, courses, accounts or enrolments, and never
 * writes course_categories.idnumber. Products are created as DRAFT (validationpassed = 0).
 *
 * Names come from the category tree; when $usetga is true a best-effort training.gov.au
 * lookup enriches the qualification name, packaging counts, AQF level and per-unit names /
 * core-elective type. TGA failures never block creation (fall back to category names).
 *
 * Duplicate codes are skipped (never creates a second product for an existing qual code).
 *
 * @param string[] $codes  Qualification codes to create (case-insensitive).
 * @param bool     $usetga Attempt TGA enrichment.
 * @return array ['created'=>[...], 'skipped'=>[...], 'errors'=>[...]]
 */
function local_rtocompliance_autocreate_quals_from_scan(array $codes, bool $usetga = false, string $source = 'categories'): array {
    global $DB, $USER;

    $wanted = [];
    foreach ($codes as $c) {
        $c = strtoupper(trim((string)$c));
        if ($c !== '') {
            $wanted[$c] = true;
        }
    }
    $result = ['created' => [], 'skipped' => [], 'errors' => []];
    if (empty($wanted)) {
        return $result;
    }

    $scan = ($source === 'coursemap')
        ? local_rtocompliance_scan_coursemap_for_quals()
        : local_rtocompliance_scan_categories_for_quals();
    $now  = time();
    $userid = isset($USER->id) ? (int)$USER->id : 0;
    $variantsTableExists = $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses');

    foreach ($scan as $cand) {
        $qc = strtoupper($cand['qualcode']);
        if (!isset($wanted[$qc])) {
            continue;
        }
        if (!empty($cand['exists']) || $DB->record_exists_select('local_rtocompliance_qualbuilder',
                'UPPER(qualificationcode) = ?', [$qc])) {
            $result['skipped'][] = ['qualcode' => $qc, 'reason' => 'A Qual Builder product already exists for this code'];
            continue;
        }

        // ── Optional best-effort TGA enrichment (never blocks). ──────────────────
        $tgaName = ''; $tgaUnits = []; $tgaTotal = 0; $tgaCore = 0; $tgaElective = 0; $tgaAqf = null;
        if ($usetga) {
            try {
                $t = \local_rtocompliance\external::tga_get_builder_data($qc, (int)$cand['categoryid']);
                if (!empty($t['success'])) {
                    $tgaTotal    = (int)($t['totalunits'] ?? 0);
                    $tgaCore     = (int)($t['corerequired'] ?? 0);
                    $tgaElective = (int)($t['electiverequired'] ?? 0);
                    $qblob = json_decode($t['qualification'] ?? '{}', true);
                    if (is_array($qblob)) {
                        $tgaName = trim((string)($qblob['title'] ?? ($qblob['name'] ?? '')));
                        $aqfraw  = $qblob['aqfLevel'] ?? ($qblob['level'] ?? null);
                        if (is_numeric($aqfraw)) {
                            $tgaAqf = (int)$aqfraw;
                        }
                    }
                    foreach ($t['units'] ?? [] as $tu) {
                        $tcode = strtoupper(trim((string)($tu['unitcode'] ?? '')));
                        if ($tcode !== '') {
                            $tgaUnits[$tcode] = [
                                'name'          => trim((string)($tu['unitname'] ?? '')),
                                'iscore'        => !empty($tu['iscore']),
                                'nominalhours'  => (int)($tu['nominalhours'] ?? 0),
                                'electivegroup' => (string)($tu['electivegroup'] ?? ''),
                            ];
                        }
                    }
                }
            } catch (\Throwable $ex) {
                $result['errors'][] = ['qualcode' => $qc, 'reason' => 'TGA enrichment skipped: ' . $ex->getMessage()];
            }
        }

        // ── Insert product + units (plugin tables only). Manual cleanup on failure. ──
        $qbid = 0;
        try {
            $unitcount = count($cand['units']);
            $prod = new \stdClass();
            $prod->producttype       = 'qualification';
            $prod->qualificationcode = $qc;
            $prod->qualificationname = $tgaName !== '' ? $tgaName
                                        : ($cand['qualname'] !== '' ? $cand['qualname'] : $qc);
            $prod->streamname        = null;
            $prod->aqflevel          = $tgaAqf;
            $prod->categoryid        = (int)$cand['categoryid'] ?: null;
            $prod->totalunits        = $tgaTotal > 0 ? $tgaTotal : $unitcount;
            $prod->coreunitcount     = $tgaCore;
            $prod->electivecount     = $tgaElective;
            $prod->packagingrules    = null;
            $prod->electiverules     = null;
            $prod->nominalhours      = null;
            $prod->status            = 'draft';
            $prod->validationpassed  = 0;
            $prod->timecreated       = $now;
            $prod->timemodified      = $now;
            $prod->createdby         = $userid;
            $qbid = (int)$DB->insert_record('local_rtocompliance_qualbuilder', $prod);

            $seq = 0;
            foreach ($cand['units'] as $u) {
                $ucode = strtoupper($u['unitcode']);
                $enr   = $tgaUnits[$ucode] ?? null;
                $uname = ($enr && $enr['name'] !== '') ? $enr['name']
                        : ($u['unitname'] !== '' ? $u['unitname'] : $ucode);
                $courseids = array_values(array_unique(array_map('intval', $u['courseids'])));
                $primary   = !empty($courseids) ? $courseids[0] : null;

                $urec = new \stdClass();
                $urec->qualbuilderid = $qbid;
                $urec->unitcode      = $ucode;
                $urec->unitname      = $uname;
                $urec->unittype      = ($enr && $enr['iscore']) ? 'core' : 'elective';
                $urec->electivegroup = ($enr && $enr['electivegroup'] !== '') ? $enr['electivegroup'] : null;
                $urec->courseid      = $primary;
                $urec->nominalhours  = ($enr && $enr['nominalhours'] > 0) ? $enr['nominalhours'] : null;
                $urec->creditpoints  = 0;
                $urec->sequenceorder = $seq++;
                $urec->selected      = 1;
                $urec->status        = 'active';
                $urec->timecreated   = $now;
                $urec->timemodified  = $now;
                $uid = (int)$DB->insert_record('local_rtocompliance_qualunits', $urec);

                if ($variantsTableExists && count($courseids) > 1) {
                    foreach (array_slice($courseids, 1) as $vc) {
                        if ((int)$vc <= 0) {
                            continue;
                        }
                        try {
                            $DB->insert_record('local_rtocompliance_qualunit_courses', (object)[
                                'qualunitid'     => $uid,
                                'courseid'       => (int)$vc,
                                'semester_label' => null,
                                'is_archive'     => 1,
                                'timecreated'    => $now,
                            ]);
                        } catch (\dml_exception $ignore) {
                            true; // unique (qualunitid,courseid) already present — ignore.
                        }
                    }
                }
            }

            $result['created'][] = [
                'qualcode'  => $qc,
                'qualname'  => $prod->qualificationname,
                'qbid'      => $qbid,
                'unitcount' => $unitcount,
                'tga'       => ($tgaName !== '' || !empty($tgaUnits)),
            ];
        } catch (\Throwable $ex) {
            // Best-effort cleanup so no half-built product is left behind.
            if ($qbid > 0) {
                try {
                    $uids = $DB->get_fieldset_select('local_rtocompliance_qualunits', 'id',
                        'qualbuilderid = ?', [$qbid]);
                    if ($variantsTableExists && !empty($uids)) {
                        list($insql, $inparams) = $DB->get_in_or_equal($uids);
                        $DB->delete_records_select('local_rtocompliance_qualunit_courses',
                            "qualunitid $insql", $inparams);
                    }
                    $DB->delete_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qbid]);
                    $DB->delete_records('local_rtocompliance_qualbuilder', ['id' => $qbid]);
                } catch (\Throwable $ignore) {
                    true;
                }
            }
            $result['errors'][] = ['qualcode' => $qc, 'reason' => $ex->getMessage()];
        }
    }

    return $result;
}

/**
 * RECOVER (v5.9.442): normalise a name for comparison (lowercase, alphanumerics + single
 * spaces). Used to match an archived course's unit title to a current Qual Builder unit.
 *
 * @param  string $s
 * @return string
 */
function local_rtocompliance_norm_name(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * RECOVER (v5.9.442): READ-ONLY scan of the completions the sync could not map, proposing
 * how each unrecognised unit code maps to a CURRENT unit that actually exists in a Qual
 * Builder product. Two safe tiers:
 *   - 'version' — the code minus its trailing version letter(s) IS a current unit
 *                 (e.g. ABC12345 → ABC12345). High confidence.
 *   - 'name'    — on a SINGLE-unit course only, a current unit's full name appears in the
 *                 course name (e.g. "Comply with biosecurity border clearance" → ABC12345).
 *                 Name matching is skipped on multi-unit courses (ambiguous which code owns
 *                 which title), so it never guesses across a combined course title.
 * Anything with no current-unit match is returned as 'unresolved' (these belong to a
 * superseded qualification that isn't built — never force-mapped).
 *
 * Writes NOTHING.
 *
 * @return array ['proposals' => [OLDCODE => [...]], 'unresolved' => [OLDCODE => [...]]]
 */
function local_rtocompliance_recover_scan(): array {
    global $DB;

    // Current known units: CODE => [unitname, qualcode, qualname]; plus normalised name → code.
    $index   = [];
    $namemap = [];
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT UPPER(qu.unitcode) AS uc, qu.unitname,
                UPPER(qb.qualificationcode) AS qc, qb.qualificationname AS qn
           FROM {local_rtocompliance_qualunits} qu
           JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
          WHERE qu.selected = 1 AND qb.status <> 'superseded'
            AND qu.unitcode IS NOT NULL AND qu.unitcode <> ''");
    foreach ($rows as $r) {
        $uc = $r->uc;
        if (!isset($index[$uc])) {
            $index[$uc] = ['unitname' => (string)$r->unitname, 'qualcode' => $r->qc, 'qualname' => (string)$r->qn];
        }
        $nn = local_rtocompliance_norm_name((string)$r->unitname);
        if (strlen($nn) >= 14 && !isset($namemap[$nn])) {
            $namemap[$nn] = $uc; // unit-name → current code (long names only, to avoid false hits).
        }
    }

    $unmapped = \local_rtocompliance\completion_reconciler::unmapped_completions();
    $pat = '/\b([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/';

    $proposals  = [];
    $unresolved = [];
    foreach ($unmapped as $u) {
        $text = strtoupper((string)$u->fullname . ' ' . (string)$u->shortname);
        if (!preg_match_all($pat, $text, $m)) {
            continue;
        }
        $codes    = array_values(array_unique($m[1]));
        $singleunit = (count($codes) === 1);
        $normtext = local_rtocompliance_norm_name((string)$u->fullname);
        $compl    = (int)$u->completions;

        foreach ($codes as $code) {
            if (isset($index[$code])) {
                continue; // already a current unit — not this tool's problem.
            }
            $target = '';
            $conf   = '';
            $base   = preg_replace('/[A-Z]+$/', '', $code);
            if ($base !== $code && isset($index[$base])) {
                $target = $base;
                $conf   = 'version';
            } else if ($singleunit) {
                foreach ($namemap as $nn => $ucode) {
                    if (strpos($normtext, $nn) !== false) {
                        $target = $ucode;
                        $conf   = 'name';
                        break;
                    }
                }
            }
            if ($target !== '') {
                if (!isset($proposals[$code])) {
                    $proposals[$code] = [
                        'current'     => $target,
                        'conf'        => $conf,
                        'unitname'    => $index[$target]['unitname'],
                        'qualcode'    => $index[$target]['qualcode'],
                        'completions' => 0,
                        'examples'    => [],
                    ];
                }
                $proposals[$code]['completions'] += $compl;
                if (count($proposals[$code]['examples']) < 2) {
                    $proposals[$code]['examples'][] = (string)$u->fullname;
                }
            } else {
                if (!isset($unresolved[$code])) {
                    $unresolved[$code] = ['completions' => 0, 'examples' => []];
                }
                $unresolved[$code]['completions'] += $compl;
                if (count($unresolved[$code]['examples']) < 2) {
                    $unresolved[$code]['examples'][] = (string)$u->fullname;
                }
            }
        }
    }

    uasort($proposals, function ($a, $b) {
        return $b['completions'] <=> $a['completions'];
    });
    uasort($unresolved, function ($a, $b) {
        return $b['completions'] <=> $a['completions'];
    });
    return ['proposals' => $proposals, 'unresolved' => $unresolved];
}

/**
 * RECOVER (v5.9.442): merge confirmed OLD => CURRENT lines into the plugin's
 * 'supersededunitmap' setting (the same map the completion reconciler already reads to
 * translate a superseded unit code to its current equivalent). Existing lines are kept;
 * a code already mapped is left untouched. Writes ONLY plugin config — no table writes,
 * no Moodle-core writes. After this runs, a fresh Sync credits the affected completions.
 *
 * @param  array $mappings  [oldcode => currentcode]
 * @return array ['added' => string[]]
 */
function local_rtocompliance_recover_apply(array $mappings): array {
    $existing = (string)get_config('local_rtocompliance', 'supersededunitmap');

    // Collect old codes already present so we never duplicate a mapping.
    $have = [];
    foreach (preg_split('/\r\n|\r|\n/', $existing) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s*(?:=>|->|=)\s*/', $line, 2);
        if (count($parts) === 2) {
            foreach (preg_split('/[\s,|]+/', strtoupper(trim($parts[0]))) as $o) {
                if ($o !== '') {
                    $have[$o] = true;
                }
            }
        }
    }

    $added = [];
    $out   = rtrim($existing);
    foreach ($mappings as $old => $cur) {
        $old = strtoupper(trim((string)$old));
        $cur = strtoupper(trim((string)$cur));
        if ($old === '' || $cur === '' || $old === $cur || isset($have[$old])) {
            continue;
        }
        $out .= ($out === '' ? '' : "\n") . $old . ' => ' . $cur;
        $added[]     = $old . ' => ' . $cur;
        $have[$old]  = true;
    }
    if (!empty($added)) {
        set_config('supersededunitmap', $out, 'local_rtocompliance');
    }
    return ['added' => $added];
}

/**
 * SEMESTER INTAKES (v5.9.444): READ-ONLY scan of the Moodle category tree for per-semester
 * intake candidates. Every category that directly contains unit-code-named courses is one
 * candidate intake (e.g. "Archive - 23 XYZ S1"): the category name is the semester label, the
 * unit codes parsed from its course names are its units, and those courses are the deliveries.
 * The qualification CODE is not in these category names, so it is AUTO-SUGGESTED by matching the
 * intake's unit set against existing Qual Builder products (best overlap; falls back to a
 * version-letter-stripped match); the admin confirms/sets it per row.
 *
 * Writes NOTHING.
 *
 * @return array List of ['categoryid','semester','suggestqual','unitcount','coursecount','units'=>[['unitcode','unitname','courseids'[]]]]
 */
function local_rtocompliance_scan_semester_intakes(): array {
    global $DB;
    $pat = '/\b([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})\b/';

    // Existing units → their qualification(s) + a good unit name, and each qualification's title.
    $unitqual = [];
    $unitnamebycode = [];
    $qualnamebycode = [];
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT UPPER(qu.unitcode) AS uc, qu.unitname, UPPER(qb.qualificationcode) AS qc, qb.qualificationname AS qn
           FROM {local_rtocompliance_qualunits} qu
           JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
          WHERE qu.selected = 1 AND qb.status <> 'superseded'
            AND qu.unitcode IS NOT NULL AND qu.unitcode <> ''");
    foreach ($rows as $r) {
        $unitqual[$r->uc][$r->qc] = true;
        if (empty($unitnamebycode[$r->uc]) && trim((string)$r->unitname) !== '') {
            $unitnamebycode[$r->uc] = trim((string)$r->unitname);
        }
        if (empty($qualnamebycode[$r->qc]) && trim((string)$r->qn) !== '') {
            $qualnamebycode[$r->qc] = trim((string)$r->qn);
        }
    }

    // Category names + ID numbers + parent map + a code→name map for qualification/unit titles.
    $catnames = [];
    $catidnum = [];
    $parentof = [];
    $namemap  = [];
    foreach ($DB->get_records('course_categories', null, '', 'id, name, idnumber, parent') as $cat) {
        $catnames[(int)$cat->id] = (string)$cat->name;
        $catidnum[(int)$cat->id] = trim((string)$cat->idnumber);
        $parentof[(int)$cat->id] = (int)$cat->parent;
        $ext = local_rtocompliance_extract_code_from_text((string)$cat->name);
        if ($ext['code'] !== '' && $ext['name'] !== '') {
            if (!isset($namemap[$ext['code']]) || strlen($ext['name']) > strlen($namemap[$ext['code']])) {
                $namemap[$ext['code']] = $ext['name'];
            }
        }
    }
    // A qualification code may live in a category's Moodle ID NUMBER (the canonical field) as
    // well as in its name. Read the ID number when it is, on its own, a qualification code
    // (e.g. ABC12345). This is fully generic — it works for any RTO, including sites that record
    // the national code only in the ID number and not in the category title. No code is hardcoded.
    $codefromidnumber = function (int $catid) use ($catidnum, $namemap) {
        $idn = isset($catidnum[$catid]) ? strtoupper($catidnum[$catid]) : '';
        if ($idn !== '' && preg_match('/^[A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2}$/', $idn)) {
            return ['code' => $idn, 'name' => isset($namemap[$idn]) ? $namemap[$idn] : ''];
        }
        return ['code' => '', 'name' => ''];
    };
    // The qualification of a detected version category: check the category ITSELF (ID number,
    // then name), then walk UP its ancestors doing the same, taking the nearest qualification
    // code found. Deterministic — the code is read straight off the tree, never guessed. Where
    // no category in the branch carries a code, this returns blank and the admin sets it once.
    $nearestqual = function (int $catid) use ($parentof, $catnames, $codefromidnumber) {
        $c = $catid;
        $hops = 0;
        while ($c > 0 && $hops < 21) {
            $byid = $codefromidnumber($c);
            if ($byid['code'] !== '') {
                return $byid;
            }
            $ext = local_rtocompliance_extract_code_from_text(isset($catnames[$c]) ? $catnames[$c] : '');
            if ($ext['code'] !== '') {
                return ['code' => $ext['code'], 'name' => $ext['name']];
            }
            $c = isset($parentof[$c]) ? $parentof[$c] : 0;
            $hops++;
        }
        return ['code' => '', 'name' => ''];
    };

    // FALLBACK ONLY — used when the tree carries no qualification code anywhere in the branch.
    // Match this category's unit set against the qualifications the RTO has ALREADY defined in
    // the plugin (their own qualification→unit mappings, nothing hardcoded). Each unit "votes"
    // for every qualification that contains it; the qualification that accounts for the most of
    // this category's units wins, provided the match is strong and unambiguous. This never
    // overrides a code read from the tree — it only offers a suggestion where there was none,
    // and the UI marks it clearly so the admin confirms before building.
    $inferqual = function (array $unitcodes) use ($unitqual, $namemap, $qualnamebycode) {
        $total = count($unitcodes);
        if ($total < 2) {
            return ['code' => '', 'name' => '', 'coverage' => 0];
        }
        $votes = [];
        foreach ($unitcodes as $uc) {
            $uc = strtoupper((string)$uc);
            if (empty($unitqual[$uc])) {
                continue;
            }
            foreach (array_keys($unitqual[$uc]) as $qc) {
                $votes[$qc] = (isset($votes[$qc]) ? $votes[$qc] : 0) + 1;
            }
        }
        if (empty($votes)) {
            return ['code' => '', 'name' => '', 'coverage' => 0];
        }
        arsort($votes);
        $best = null;
        $bestcount = 0;
        $secondcount = 0;
        $i = 0;
        foreach ($votes as $qc => $n) {
            if ($i === 0) { $best = $qc; $bestcount = $n; }
            else if ($i === 1) { $secondcount = $n; break; }
            $i++;
        }
        $coverage = $total > 0 ? ($bestcount / $total) : 0;
        // Require a solid, clearly-winning match: at least 2 of this category's units belong to
        // the qualification, it covers ≥60% of them, and it is either strictly ahead of the
        // runner-up or an overwhelming (≥85%) match. Otherwise stay blank for the admin.
        $strong = ($bestcount >= 2)
            && ($coverage >= 0.60)
            && (($bestcount > $secondcount) || ($coverage >= 0.85));
        if (!$strong || $best === null) {
            return ['code' => '', 'name' => '', 'coverage' => 0];
        }
        $name = isset($qualnamebycode[$best]) ? $qualnamebycode[$best]
            : (isset($namemap[$best]) ? $namemap[$best] : '');
        return ['code' => $best, 'name' => $name, 'coverage' => $coverage,
                'matched' => $bestcount, 'total' => $total];
    };

    // Group every course by its category, extracting unit codes from the course name.
    $bycat = [];
    $rs = $DB->get_recordset_sql("SELECT id, fullname, shortname, category FROM {course} WHERE id > 1");
    foreach ($rs as $c) {
        $text = strtoupper((string)$c->fullname . ' ' . (string)$c->shortname);
        if (!preg_match_all($pat, $text, $m)) {
            continue;
        }
        $codes = array_values(array_unique($m[1]));
        if (empty($codes)) {
            continue;
        }
        $cat = (int)$c->category;
        if (!isset($bycat[$cat])) {
            $bycat[$cat] = [];
        }
        foreach ($codes as $code) {
            if (!isset($bycat[$cat][$code])) {
                $bycat[$cat][$code] = [];
            }
            $bycat[$cat][$code][(int)$c->id] = ($c->fullname !== '' ? (string)$c->fullname : (string)$c->shortname);
        }
    }
    $rs->close();

    $out = [];
    foreach ($bycat as $catid => $units) {
        if (empty($units)) {
            continue;
        }
        // Qualification code + title come first from the category tree (parent/idnumber/ancestors)
        // — deterministic, never guessed. Only when the tree carries no code anywhere do we fall
        // back to matching the unit set against the RTO's own qualification definitions.
        $anc         = $nearestqual((int)$catid);
        $suggest     = $anc['code'];
        $suggestn    = $anc['name'];
        $suggestsrc  = $suggest !== '' ? 'tree' : '';
        $inferconf   = 0;
        if ($suggest === '') {
            $inf = $inferqual(array_keys($units));
            if ($inf['code'] !== '') {
                $suggest    = $inf['code'];
                $suggestn   = $inf['name'];
                $suggestsrc = 'units';
                $inferconf  = (int) round(($inf['coverage'] ?? 0) * 100);
            }
        }

        $unitlist    = [];
        $coursecount = 0;
        foreach ($units as $uc => $courses) {
            $nm = isset($unitnamebycode[$uc]) ? $unitnamebycode[$uc]
                : (isset($namemap[$uc]) ? $namemap[$uc] : $uc);
            $cids = array_map('intval', array_keys($courses));
            $coursecount += count($cids);
            $unitlist[] = ['unitcode' => $uc, 'unitname' => $nm, 'courseids' => $cids];
        }
        usort($unitlist, function ($a, $b) {
            return strcmp($a['unitcode'], $b['unitcode']);
        });

        // Parent category + the full category path (root → parent), so the admin can see exactly
        // where this version sits in Moodle and verify it — the path excludes the version itself
        // (that name is shown as the row's linked title).
        $parentid   = isset($parentof[$catid]) ? (int)$parentof[$catid] : 0;
        $parentname = ($parentid > 0 && isset($catnames[$parentid])) ? $catnames[$parentid] : '';
        $pathparts  = [];
        $pc = $parentid;
        $ph = 0;
        while ($pc > 0 && $ph < 25) {
            array_unshift($pathparts, isset($catnames[$pc]) ? $catnames[$pc] : ('#' . $pc));
            $pc = isset($parentof[$pc]) ? (int)$parentof[$pc] : 0;
            $ph++;
        }
        $categorypath = implode(' / ', $pathparts);

        $out[] = [
            'categoryid'      => (int)$catid,
            'semester'        => isset($catnames[$catid]) ? $catnames[$catid] : ('Category ' . $catid),
            'parentid'        => $parentid,
            'parentname'      => $parentname,
            'categorypath'    => $categorypath,
            'suggestqual'     => $suggest,
            'suggestqualname' => $suggestn,
            'suggestsource'   => $suggestsrc,
            'inferconfidence' => $inferconf,
            'unitcount'       => count($unitlist),
            'coursecount'     => $coursecount,
            'units'           => $unitlist,
        ];
    }
    usort($out, function ($a, $b) {
        return strcmp($a['semester'], $b['semester']);
    });
    return $out;
}

/**
 * SEMESTER INTAKES (v5.9.444): create one Draft qualification product per confirmed semester
 * intake — qualification code as chosen, streamname = the semester label, its units, and that
 * semester's courses linked (extra copies as archive variants). Writes ONLY plugin tables; no
 * Moodle categories/courses/accounts/enrolments touched. A (qualcode + streamname) that already
 * exists is skipped. The semester label is taken from the scan; only the categoryid + qualcode
 * come from the caller.
 *
 * @param  array $intakes  [['categoryid' => int, 'qualcode' => string]]
 * @return array ['created'=>[...], 'skipped'=>[...], 'errors'=>[...]]
 */
function local_rtocompliance_create_semester_intakes(array $intakes): array {
    global $DB, $USER;

    $result = ['created' => [], 'skipped' => [], 'errors' => []];
    if (empty($intakes)) {
        return $result;
    }

    $scan = local_rtocompliance_scan_semester_intakes();
    $byid = [];
    foreach ($scan as $s) {
        $byid[(int)$s['categoryid']] = $s;
    }

    // Qualification names: existing product name per code, then category-derived.
    $qualname = [];
    foreach ($DB->get_records_sql(
        "SELECT UPPER(qualificationcode) AS qc, qualificationname AS qn
           FROM {local_rtocompliance_qualbuilder} WHERE qualificationname <> ''") as $r) {
        if (!isset($qualname[$r->qc])) {
            $qualname[$r->qc] = (string)$r->qn;
        }
    }
    $namemap = [];
    foreach ($DB->get_records('course_categories', null, '', 'id, name') as $cat) {
        $ext = local_rtocompliance_extract_code_from_text((string)$cat->name);
        if ($ext['code'] !== '' && $ext['name'] !== '' && !isset($namemap[$ext['code']])) {
            $namemap[$ext['code']] = $ext['name'];
        }
    }

    $now      = time();
    $userid   = isset($USER->id) ? (int)$USER->id : 0;
    $variants = $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses');

    foreach ($intakes as $intk) {
        $catid = (int)($intk['categoryid'] ?? 0);
        $qc    = strtoupper(trim((string)($intk['qualcode'] ?? '')));
        if ($catid <= 0 || !isset($byid[$catid])) {
            continue;
        }
        $cand = $byid[$catid];
        $sem  = substr(trim((string)$cand['semester']), 0, 150);
        if ($qc === '') {
            $result['skipped'][] = ['semester' => $sem, 'reason' => 'No qualification code assigned'];
            continue;
        }
        if ($DB->record_exists_select('local_rtocompliance_qualbuilder',
                'UPPER(qualificationcode) = ? AND streamname = ?', [$qc, $sem])) {
            $result['skipped'][] = ['semester' => $sem, 'reason' => 'A ' . $qc . ' / ' . $sem . ' product already exists'];
            continue;
        }
        // Qualification name: an existing product's name for this code wins; otherwise the
        // PARENT category's title (read off the tree), then the category-name map, then the code.
        $qn = isset($qualname[$qc]) ? $qualname[$qc]
            : (!empty($cand['suggestqualname']) ? $cand['suggestqualname']
            : (isset($namemap[$qc]) ? $namemap[$qc] : $qc));

        $qbid = 0;
        try {
            $prod = new \stdClass();
            $prod->producttype       = 'qualification';
            $prod->qualificationcode = $qc;
            $prod->qualificationname = $qn;
            $prod->streamname        = $sem !== '' ? $sem : null;
            $prod->totalunits        = (int)$cand['unitcount'];
            $prod->coreunitcount     = 0;
            $prod->electivecount     = 0;
            $prod->status            = 'draft';
            $prod->validationpassed  = 0;
            $prod->timecreated       = $now;
            $prod->timemodified      = $now;
            $prod->createdby         = $userid;
            $qbid = (int)$DB->insert_record('local_rtocompliance_qualbuilder', $prod);

            $seq = 0;
            foreach ($cand['units'] as $u) {
                $cids    = array_values(array_unique(array_map('intval', $u['courseids'])));
                $primary = !empty($cids) ? $cids[0] : null;
                $urec = new \stdClass();
                $urec->qualbuilderid = $qbid;
                $urec->unitcode      = strtoupper($u['unitcode']);
                $urec->unitname      = $u['unitname'] !== '' ? $u['unitname'] : $u['unitcode'];
                $urec->unittype      = 'elective';
                $urec->courseid      = $primary;
                $urec->creditpoints  = 0;
                $urec->sequenceorder = $seq++;
                $urec->selected      = 1;
                $urec->status        = 'active';
                $urec->timecreated   = $now;
                $urec->timemodified  = $now;
                $uid = (int)$DB->insert_record('local_rtocompliance_qualunits', $urec);

                if ($variants && count($cids) > 1) {
                    foreach (array_slice($cids, 1) as $vc) {
                        if ((int)$vc <= 0) {
                            continue;
                        }
                        try {
                            $DB->insert_record('local_rtocompliance_qualunit_courses', (object)[
                                'qualunitid'     => $uid,
                                'courseid'       => (int)$vc,
                                'semester_label' => $sem !== '' ? $sem : null,
                                'is_archive'     => 1,
                                'timecreated'    => $now,
                            ]);
                        } catch (\dml_exception $ig) {
                            true;
                        }
                    }
                }
            }
            $result['created'][] = ['qualcode' => $qc, 'semester' => $sem, 'units' => (int)$cand['unitcount']];
        } catch (\Throwable $ex) {
            if ($qbid > 0) {
                try {
                    $uids = $DB->get_fieldset_select('local_rtocompliance_qualunits', 'id', 'qualbuilderid = ?', [$qbid]);
                    if ($variants && !empty($uids)) {
                        list($insql, $inparams) = $DB->get_in_or_equal($uids);
                        $DB->delete_records_select('local_rtocompliance_qualunit_courses', "qualunitid $insql", $inparams);
                    }
                    $DB->delete_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qbid]);
                    $DB->delete_records('local_rtocompliance_qualbuilder', ['id' => $qbid]);
                } catch (\Throwable $ig) {
                    true;
                }
            }
            $result['errors'][] = ['semester' => $sem, 'reason' => $ex->getMessage()];
        }
    }
    return $result;
}

/**
 * AI ASSISTANT (v5.9.456) — build the knowledge base the in-product assistant is
 * grounded on. It is generated LIVE from the plugin's own help registry
 * (local_rtocompliance_page_help_content() + _overlay()), so it auto-updates every
 * release with zero manual work: add or edit a page's help and the assistant knows
 * about it on the next question. The KB is a compact map of every page (title, the
 * ASQA standard it supports, a one-line "what", and its URL script), PLUS the full
 * help for the page the user is currently on (context-aware answers), PLUS a curated
 * troubleshooting section for the most common cross-page tasks.
 *
 * @param string $currentscript the basename of the page the user is on (e.g. 'students.php')
 * @return string knowledge-base text for the assistant's system context
 */
function local_rtocompliance_assistant_kb(string $currentscript = ''): string {
    global $CFG;
    $siteurl = rtrim((string) ($CFG->wwwroot ?? ''), '/');
    $base = local_rtocompliance_page_help_content();
    $overlay = function_exists('local_rtocompliance_page_help_overlay')
        ? local_rtocompliance_page_help_overlay() : [];
    if (!is_array($base)) {
        $base = [];
    }
    // Merge overlay how-steps / features over the base entries.
    $pages = $base;
    if (is_array($overlay)) {
        foreach ($overlay as $script => $ov) {
            if (!isset($pages[$script]) || !is_array($pages[$script])) {
                $pages[$script] = [];
            }
            if (is_array($ov)) {
                $pages[$script] = array_merge($pages[$script], $ov);
            }
        }
    }

    $version = (string) (get_config('local_rtocompliance', 'version') ?: '');
    $lines = [];
    $lines[] = 'You are the RTO Compliance Assistant, an expert on the "AI RTO Compliance" Moodle plugin'
        . ($version !== '' ? ' (installed version ' . $version . ')' : '') . '.';
    $lines[] = 'This plugin helps Australian RTOs meet the Standards for RTOs 2025 (ASQA): student records and'
        . ' AVETMISS data, qualifications and units, results and completions, certificates, USI verification,'
        . ' trainers, governance, and NAT/AVETMISS reporting.';
    $lines[] = '';
    if ($siteurl !== '') {
        $lines[] = 'This RTO\'s Moodle site base URL is ' . $siteurl . '. Every plugin page lives at '
            . $siteurl . '/local/rtocompliance/<page-file>. When you suggest an action, ALWAYS include a'
            . ' clickable Markdown link to the exact page using this base URL (the direct link is given for'
            . ' each page below). For example, link to Generate Qualification Certificates as '
            . '[Generate Qualification Certificates](' . $siteurl . '/local/rtocompliance/generate_qual_certs.php).';
    }
    $lines[] = '';
    $lines[] = '## Every page in the plugin (title — standard — what it does — direct link)';
    ksort($pages);
    foreach ($pages as $script => $p) {
        if (!is_array($p)) {
            continue;
        }
        $title = trim((string)($p['title'] ?? $script));
        $std   = trim((string)($p['standard'] ?? ''));
        $what  = trim((string)($p['what'] ?? ''));
        if ($title === '' && $what === '') {
            continue;
        }
        $line = '- ' . $title;
        if ($std !== '') {
            $line .= ' [' . $std . ']';
        }
        if ($what !== '') {
            $line .= ' — ' . $what;
        }
        $url = $siteurl !== '' ? ($siteurl . '/local/rtocompliance/' . $script) : $script;
        $line .= ' (link: ' . $url . ')';
        $lines[] = $line;
    }

    // Full detail for the page the user is currently viewing.
    $cur = $currentscript !== '' && isset($pages[$currentscript]) ? $pages[$currentscript] : null;
    if (is_array($cur)) {
        $lines[] = '';
        $lines[] = '## The user is currently on: ' . trim((string)($cur['title'] ?? $currentscript))
            . ' (' . $currentscript . ')';
        if (!empty($cur['what'])) {
            $lines[] = 'What it is: ' . trim((string)$cur['what']);
        }
        if (!empty($cur['why'])) {
            $lines[] = 'Why it matters: ' . trim((string)$cur['why']);
        }
        if (!empty($cur['how']) && is_array($cur['how'])) {
            $lines[] = 'How to use it:';
            $n = 1;
            foreach ($cur['how'] as $step) {
                $lines[] = '  ' . $n++ . '. ' . trim((string)$step);
            }
        }
        if (!empty($cur['features']) && is_array($cur['features'])) {
            $lines[] = 'Key features: ' . implode('; ', array_map('strval', $cur['features']));
        }
    }

    // Curated troubleshooting / common tasks — the cross-page answers users ask for most.
    $lines[] = '';
    $lines[] = '## Common tasks & troubleshooting (authoritative)';
    $faq = [
        'Issue certificates for a qualification' => 'Open Generate Qualification Certificates (generate_qual_certs.php): it lists students who have completed the qualification; select and issue. Templates are designed in Certificate Templates (cert_templates.php → Edit).',
        'A certificate template will not save' => 'Purge caches (Site admin → Development → Purge all caches) then reload the editor — the save uses JavaScript that must be re-served after an upgrade. The design is also seeded server-side so a save never posts an empty payload.',
        'Verify a student USI' => 'On Student Records (students.php) open the student and click Verify USI. The machine credential is held on the lms-labs.com platform (managed in its admin panel); the plugin calls the platform to verify against your TOID. Status shows on the USI Verification Status page (usi_settings.php).',
        'Auto-create qualifications' => 'Qualification Builder → Auto-Create (qualbuilder_autocreate.php) builds one Draft product per qualification code from your Course Map. For per-semester/archive copies (S1 26, S2 26 …), use the Semester Intake Builder (qualbuilder_semester.php) — it creates a separate product per semester sub-category with its exact course list.',
        'Sync results from Moodle completions' => 'Student Results (qualbuilder_results.php) → Sync: this reconciles Moodle course completions into the results register (all students at once) so competencies and certificate eligibility are up to date. It reads Moodle completions and writes only the plugin register.',
        'Course Map column shows None or a partial count' => 'The Course Map column counts, of a product\'s units, how many are confirmed in the Course -> Unit -> Qualification map (local_rtocompliance_course_map). It is SEPARATE from Linked Courses: Linked Courses can be full while Course Map is None because the map has not been rebuilt since the products were created. Fix: Qualification Builder -> "Build Course Map from Links" — it populates the confirmed map from the courses already linked to each unit (only adds missing rows, changes nothing else). Units that remain unmapped afterwards have no linked delivery course, or use a retired/superseded unit code with no course of its own (common in old archived intakes); those are expected to stay blank unless you deliberately map them.',
        'Record of Results / Statement of Attainment units table' => 'Both certificates render a shaded 3-column units table — Unit Code | Unit Title | Completion Date — with a header colour set in Certificate Settings (defaults to the site brand colour).',
        'Generate the AVETMISS / NAT files' => 'Use the NAT export (data_import.php / nat_generator) to produce the NAT files; validate first with the NAT validator. RTO organisation details for NAT00010 come from Plugin Settings.',
        'The plugin only READS Moodle' => 'The plugin never creates or deletes Moodle enrolments, accounts or categories. It reads Moodle (enrolments, completions, users, categories) and writes only to its own local_rtocompliance_* tables.',
        'Who created RTO Compliance / who is behind this software' => 'Bronwyn Blencowe was the principal researcher and advisor for RTO Compliance. She is a consultant with over 30 years in the Australian VET industry, and brought her deep knowledge of RTOs and ASQA compliance into the design of this software. If asked who created, built, or is behind RTO Compliance, answer with Bronwyn Blencowe and this background.',
    ];
    foreach ($faq as $q => $a) {
        $lines[] = '- ' . $q . ': ' . $a;
    }

    // ── ASQA 2025 STANDARDS FRAMEWORK — so answers reason from the actual regulatory structure,
    // not just plugin help. Maps each Quality Area to what it requires and the plugin pages that
    // support it. (Authoritative structure; where a specific clause number or the latest practice
    // guide wording matters, say so and point the user to asqa.gov.au for the current text.)
    $lines[] = '';
    $lines[] = '## ASQA — Standards for RTOs 2025 framework (authoritative structure)';
    $lines[] = 'The 2025 Standards took effect on 1 July 2025 (Standards for Registered Training Organisations 2025, a legislative instrument). They are structured as OUTCOME STANDARDS across four Quality Areas, plus CREDENTIAL/COMPLIANCE REQUIREMENTS. ASQA supports them with a set of PRACTICE GUIDES (guidance, not law). Reason from the outcome the Standard seeks, then map it to what the RTO must evidence and the plugin page that helps.';
    $lines[] = '- Quality Area 1 — Training and assessment: training and assessment strategies and practices, assessment (validity, reliability, sufficiency, currency, authenticity), assessment validation, industry engagement, recognition of prior learning and credit transfer, issuance of certification. Plugin: Qualification Builder, Course Map, TAS Generator, Validation Schedule, RPL & Credit Transfer, Certificates, Student Results.';
    $lines[] = '- Quality Area 2 — VET student support: accurate information before enrolment, support through the learner journey, wellbeing and a safe environment, complaints and appeals. Plugin: Marketing Information, Student Support, Student Suitability Check, Student Records, Complaints & Appeals.';
    $lines[] = '- Quality Area 3 — VET workforce: sufficient trainers and assessors, their vocational competency, current industry skills and VET knowledge, and supervision of "working towards" trainers. Plugin: Trainer & Assessor Register, Trainer Currency/Vocational Competency, Supervision Log, Workforce Management (planning aid only — it does not verify competency).';
    $lines[] = '- Quality Area 4 — Governance and administration: accountable governance and leadership, risk management, financial viability and fee protection, third-party arrangements, records and data integrity (including AVETMISS/NAT reporting) and self-assurance. Plugin: Governance, Risk, Fee Protection, Insurance, Third-Party Arrangements, Audit Log, Data Import, NAT Export, Compliance Health.';
    $lines[] = 'Credential/Compliance Requirements sit alongside the Outcome Standards (e.g. trainer/assessor credential requirements, USI, AQF issuance rules). Self-assurance is the overarching expectation: the RTO must continuously monitor its own performance and fix issues before an audit — Compliance Health, Validation Schedule and Alerts support this.';

    // ── PLUGIN INTERNALS — how the key subsystems actually work, so the assistant can explain the
    // mechanism (the "why it behaves this way"), not just the button to click.
    $lines[] = '';
    $lines[] = '## How the plugin works internally (mechanisms, for detailed answers)';
    $lines[] = '- Reads-only-Moodle guarantee: the plugin READS Moodle (enrolments, course completions, users, categories) and WRITES only to its own local_rtocompliance_* tables. It never creates/edits/deletes Moodle accounts, courses, categories or enrolments (enrolment writes are hard-disabled).';
    $lines[] = '- Course Map (local_rtocompliance_course_map) is the single source of truth linking a Moodle course to a (qualification code, unit code) pair, one row per course. Every completion-detection and certificate path reads it — nothing parses course names at runtime. "Build Course Map from Links" seeds confirmed rows from the Qualification Builder unit->course links (source=qb, confirmed=1), including archive/semester copies.';
    $lines[] = '- Completion detection & auto-certificates: for a product, the engine checks each selected unit for a completion in any mapped delivery course (current, archive or semester copy). When all required units are Competent (C/RPL/CT), a Testamur (and Record of Results) is queued; the 30-day issuance clock runs from the final unit completion date. "Linked Courses" (a course is attached to a unit) is separate from "Course Map" (that link is confirmed in the map) — both must be full for reliable autocert.';
    $lines[] = '- AVETMISS/NAT: imports land in a staging table then reconcile into the live students register; the importer round-trips NAT00080/85/90/100/120/130 etc., and NAT00090 (disability) + NAT00100 (prior education) are written back onto matching students so a re-import never loses them. NAT export validates against NCVER edit rules before generating the files; org details for NAT00010 come from Plugin Settings.';
    $lines[] = '- USI verification is brokered by the lms-labs.com platform: the myGovID Machine Credential keystore is held ONLY on the platform (never in Moodle); the plugin calls the platform to verify a USI against the RTO TOID. The USI Verification Status page is read-only.';
    $lines[] = '- Certificates render via TCPDF from a saved design (fields, images, a 3-column units table: Unit Code | Unit Title | Completion Date) authored in the drag-and-drop template editor; issuance is gated on eligibility and (for full quals) on all units competent.';
    $lines[] = '- The AI assistant itself: grounded live on this knowledge base (regenerated every release from the plugin help registry + this framework), so it stays in sync with the software automatically; 1 credit per question.';

    // ── STATE / TERRITORY VET FUNDING — so the assistant can advise on subsidised-training
    // programs, not just national compliance. Current as at 2026; programs and codes change, so
    // always tell the user to confirm rates/codes against the relevant STA's current specification.
    $lines[] = '';
    $lines[] = '## Australian VET funding systems (state/territory + Commonwealth), as at 2026';
    $lines[] = 'VET funding is shared: the Commonwealth funds national incentives and co-funds state systems via the National Skills Agreement; each state/territory runs its own subsidised-training system, contracts RTOs, and defines its own AVETMISS "Funding source - state training authority" codes. In the plugin these per-jurisdiction defaults are set in Plugin Settings -> State Funding, and flow into the AVETMISS/NAT export. Always confirm current programs, subsidy rates and funding-source codes against the relevant authority before reporting or claiming.';
    $lines[] = '- NSW — Smart and Skilled (Training Services NSW; provider portal rto.nsw.gov.au, activity/claims via STS Online). Fixed price per qualification split into a set student fee + government subsidy; fee-free/concession categories. Per-enrolment identifier: Commitment ID. Report AVETMISS via STS Online.';
    $lines[] = '- VIC — Skills First and Free TAFE (Dept of Jobs, Skills, Industry and Regions; SVTS = Skills Victoria Training System for data, claims and contract notifications). Subsidy toward tuition; concession fee capped at 20% of standard; ATSI and priority groups fee-waived. RTO holds a VET Funding Contract (2026 versions: Standard/TAFE/Dual-sector).';
    $lines[] = '- QLD — Career Start and Career Boost (current from 1 July 2025; they REPLACED Certificate 3 Guarantee, User Choice and Higher Level Skills). Provider framework is Skills Assure Supplier (SAS, replaced Pre-qualified Supplier). Dept of Trade, Employment and Training (DTET; formerly DESBT); report AVETMISS via the Partner Portal (ATA). NAT00120 must carry the QLD Funding source - STA code plus the Purchasing Contract Identifier to claim. Qualifications must be on the Queensland Subsidised Training List.';
    $lines[] = '- WA — Jobs and Skills WA (Dept of Training and Workforce Development); "Lower fees, local skills" and Fee-Free training; annual student fee caps ($400 concession/jobseeker/youth, $1,200 others). WA reports through its OWN system: RAPT (Resource Allocation Program for Training) / TAMS text-file spec, not plain AVETMISS; contracts managed in the TAMS RTO Portal; providers selected via Tenders WA.';
    $lines[] = '- SA — Skills SA / Subsidised Training List (STL) under a Funded Activities Agreement (Dept of State Development; "Skills for All" and "WorkReady" are superseded names). Data/claims via mySkillsSA and STELA (AVETMISS). Independent apprenticeship regulator: SA Skills Commission.';
    $lines[] = '- TAS — Skills Tasmania (Dept of State Growth); Apprentice & Trainee Training Fund, Energising Tasmania, Fee-Free TAFE, etc. Provider approval moving from Endorsed RTO to the TasVET Supplier program (from early 2026). Report AVETMISS via the eVET Portal with Tasmania-specific mandatory fields; TAS publishes a state->national funding-source code mapping.';
    $lines[] = '- ACT — Skilled Capital and User Choice (Skills Canberra; AVETARS system; ACT Qualifications Register lists funded quals, subsidies and minimum fees). RTO needs a Training Initiative Funding Agreement (TIFA). Unit-based payment on successful completion; student co-contribution $100-$500/qualification; quarterly AVETMISS.';
    $lines[] = '- NT — Skills NT User Choice for apprentices/trainees (paid on nominal hours at industry rates plus a regional/remote loading; calendar-year administration); VET for Secondary Students. Report AVETMISS with NT-specific fields.';
    $lines[] = '- Commonwealth/national — Fee-Free TAFE (National Skills Agreement; made ongoing by the Free TAFE Act 2024) WAIVES the student tuition fee on eligible state-subsidised places at public providers; and the Australian Apprenticeships Incentive System (AAIS) pays cash incentives to employers/apprentices (rates changed 1 Jan 2026), claimed through an Australian Apprenticeship Support Services provider — separate from the state training subsidy.';
    $lines[] = 'AVETMISS funding-source fields: "Funding source - national" is a standard 2-digit NCVER classification (e.g. 11/13/15 government, 20 domestic fee-for-service, 30 international); "Funding source - state training authority" is a state-defined code identifying the specific program/contract, which maps up to a national code. A subsidised enrolment carries both; a purely fee-for-service enrolment uses the national field only. The plugin\'s State Funding code lists are indicative — confirm the exact current code with the STA.';

    // ── FAQ — the 100 plain-English answers shown on the FAQ page, so the assistant gives the
    // same simple answers a first-time user reads there (and can point them to the FAQ page).
    if (is_readable(__DIR__ . '/faq_content.php')) {
        require_once(__DIR__ . '/faq_content.php');
        if (function_exists('local_rtocompliance_faq_data')) {
            $lines[] = '';
            $lines[] = '## Frequently asked questions (plain-English answers — mirror these)';
            foreach (local_rtocompliance_faq_data() as $fc) {
                $lines[] = '### ' . $fc['cat'];
                foreach ($fc['items'] as $fi) {
                    $pg = trim((string) ($fi['page'] ?? ''));
                    $lines[] = '- Q: ' . $fi['q'] . ' A: ' . $fi['a']
                        . ($pg !== '' ? ' (page: ' . $pg . ')' : '');
                }
            }
        }
    }

    $lines[] = '';
    // Support-centre Q&A (single source of truth: support_content.php, also rendered on support.php).
    if (is_readable(__DIR__ . '/support_content.php')) {
        require_once(__DIR__ . '/support_content.php');
        if (function_exists('local_rtocompliance_support_faq_data')) {
            $lines[] = '';
            $lines[] = '## Support centre answers (detailed troubleshooting — mirror these)';
            foreach (local_rtocompliance_support_faq_data() as $sc) {
                $lines[] = '### ' . (string) ($sc['category'] ?? '');
                foreach (($sc['faqs'] ?? []) as $si) {
                    $lines[] = '- Q: ' . (string) ($si['question'] ?? '') . ' A: ' . (string) ($si['answer'] ?? '');
                }
            }
        }
    }

    $lines[] = '## How to answer';
    $lines[] = 'Answer as a senior VET compliance adviser and former ASQA auditor who also knows this exact software inside-out. Be the RTO\'s personal expert: lead with a direct, specific answer, then give the concrete steps (naming the exact page, menu location and button/field). When a question is about compliance, reason from the RELEVANT 2025 Standard and its underlying outcome and practice guide — explain WHAT ASQA is looking for and WHY, then HOW this plugin helps evidence it, and the risk if it is not addressed. Tailor advice to the RTO\'s situation where the context gives it. ALWAYS give the user a clickable Markdown link to the exact plugin page your suggestion refers to, built from this site\'s base URL (e.g. "to issue a certificate, go to [Generate Qualification Certificates](<site>/local/rtocompliance/generate_qual_certs.php)") — one link per page you mention, using the direct links listed above; never show a bare page-file name without linking it. Do not merely regurgitate steps and do not invent features or clause numbers: if you are not certain of a specific clause or the latest practice-guide wording, say so and point to asqa.gov.au for the current text. If something is genuinely not a feature of this plugin, say so plainly. Keep every ASQA reference accurate. Be thorough but readable.';

    return implode("\n", $lines);
}

/**
 * AI ASSISTANT (v5.9.456) — answer a question. Primary path routes through the
 * lms-labs.com platform (siteid + apikey), which holds the Claude API key and bills
 * ONE credit per question. A direct Anthropic key configured in plugin settings is
 * supported as a fallback for self-hosted installs (no platform credits then).
 *
 * @param array  $messages conversation as [['role'=>'user'|'assistant','content'=>'...'], ...]
 * @param string $page     basename of the current page (for context)
 * @return array ['ok'=>bool, 'reply'=>string, 'credits'=>?int, 'mode'=>string, 'error'=>string]
 */
/**
 * AI ASSISTANT (v6.2.50) — resolve the assistant's platform/API credentials, Central Config
 * FIRST (local_aiconfig, the way every other AI feature is configured), falling back to this
 * plugin's own settings. This is the single source of truth used BOTH by the footer hook that
 * decides whether to render the widget AND by local_rtocompliance_assistant_ask(), so the two
 * can never disagree (previously the hook read plugin-local siteid/apikey only, so a site
 * configured purely through Central Config never showed the assistant at all).
 *
 * @return array ['apiurl'=>string, 'siteid'=>string, 'apikey'=>string, 'directkey'=>string,
 *                'model'=>string, 'platform_ready'=>bool, 'ready'=>bool]
 */
/**
 * AI ASSISTANT (v6.2.68) — return the floating, Claude-powered help-assistant widget
 * markup (scoped CSS + root div + external script), or '' when it should not show.
 *
 * WHY THIS IS A FUNCTION EMITTED INTO THE MAIN REGION (not a hook):
 * The widget was injected via the before_footer / before_standard_top_of_body output
 * hooks. Some third-party themes (e.g. "academi") silently drop HTML added through
 * those hooks — the plugin's own tablesorter.js and the assistant never reached the
 * page, even though the feature was enabled and creds were ready. The plugin's
 * server-side main-region output (render_sidebar / render_nav_header) DOES render on
 * every theme, so the assistant is emitted from there instead. The FAB is
 * position:fixed, so it still floats bottom-right regardless of where in the main
 * region it is emitted.
 *
 * Same gating as before: enabled (assistant_enabled != '0', default on) + staff/admin
 * + platform-or-direct creds ready (local_rtocompliance_assistant_creds()).
 *
 * @return string widget HTML, or '' when hidden.
 */
function local_rtocompliance_assistant_widget_html(): string {
    global $CFG, $PAGE;

    $asst_en = get_config('local_rtocompliance', 'assistant_enabled');
    $asst_on = ($asst_en === false) || ((string) $asst_en !== '0');
    if (!$asst_on) {
        return '';
    }

    $sysctx = context_system::instance();
    $staff = is_siteadmin()
        || has_capability('local/rtocompliance:viewall', $sysctx)
        || has_capability('local/rtocompliance:manage', $sysctx)
        || has_capability('local/rtocompliance:viewreports', $sysctx);
    if (!$staff) {
        return '';
    }

    require_once($CFG->dirroot . '/local/rtocompliance/lib.php');
    $creds = local_rtocompliance_assistant_creds();
    if (empty($creds['ready'])) {
        return '';
    }

    $asst_endpoint = (new moodle_url('/local/rtocompliance/assistant.php'))->out(false);
    $asst_js       = (new moodle_url('/local/rtocompliance/js/rtoc_assistant.js'))->out();
    $asst_page     = ($PAGE && $PAGE->url) ? basename((string) $PAGE->url->get_path()) : '';
    $asst_sk       = sesskey();
                $asst_css = <<<CSS
<style>
#rtoc-asst-root{position:fixed;right:22px;bottom:22px;z-index:100060;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
#rtoc-asst-root *{box-sizing:border-box;}
#rtoc-asst-root .rtoc-asst-fab{display:inline-flex;align-items:center;gap:9px;border:0;cursor:pointer;padding:13px 20px 13px 16px;border-radius:999px;color:#fff!important;
  background:linear-gradient(135deg,#2563eb,#1d4ed8 55%,#1e40af)!important;box-shadow:0 12px 30px -8px rgba(37,99,235,.6),0 4px 10px rgba(15,23,42,.18);
  font-size:15px;font-weight:650;letter-spacing:.2px;text-decoration:none!important;transition:transform .18s ease,box-shadow .18s ease,opacity .15s ease;}
/* v6.2.69: harden against theme button:hover/.btn overrides (academi flipped the FAB
   background to a light colour on hover, making the white "Ask AI" label unreadable). */
#rtoc-asst-root .rtoc-asst-fab:hover,#rtoc-asst-root .rtoc-asst-fab:focus{transform:translateY(-2px);color:#fff!important;
  background:linear-gradient(135deg,#1d4ed8,#1e40af 55%,#1e3a8a)!important;box-shadow:0 18px 40px -10px rgba(37,99,235,.7),0 6px 14px rgba(15,23,42,.22);}
#rtoc-asst-root .rtoc-asst-fab .rtoc-asst-fab-lbl,#rtoc-asst-root .rtoc-asst-fab .rtoc-asst-fab-ic{color:#fff!important;}
.rtoc-asst-fab-ic{display:inline-flex;filter:drop-shadow(0 1px 2px rgba(0,0,0,.25));animation:rtoc-asst-tw 3.2s ease-in-out infinite;}
@keyframes rtoc-asst-tw{0%,100%{transform:rotate(0) scale(1);}50%{transform:rotate(-8deg) scale(1.08);}}
#rtoc-asst-root.is-open .rtoc-asst-fab{opacity:0;pointer-events:none;transform:scale(.6);}
.rtoc-asst-panel{position:absolute;right:0;bottom:0;width:390px;max-width:calc(100vw - 32px);height:600px;max-height:calc(100vh - 48px);
  background:#fff;border-radius:20px;overflow:hidden;display:flex;flex-direction:column;opacity:0;transform:translateY(16px) scale(.98);pointer-events:none;
  box-shadow:0 30px 70px -18px rgba(15,23,42,.5),0 10px 24px rgba(15,23,42,.18);border:1px solid rgba(15,23,42,.06);transition:opacity .2s ease,transform .22s cubic-bezier(.2,.8,.2,1);}
#rtoc-asst-root.is-open .rtoc-asst-panel{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;}
.rtoc-asst-head{background:linear-gradient(135deg,#2563eb,#1d4ed8 60%,#1e40af);color:#fff;padding:15px 14px 15px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
.rtoc-asst-head-l{display:flex;align-items:center;gap:11px;min-width:0;}
.rtoc-asst-orb{flex:0 0 auto;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px rgba(255,255,255,.25);}
.rtoc-asst-ttl{font-size:15.5px;font-weight:700;line-height:1.15;}
.rtoc-asst-sub{font-size:11.5px;opacity:.9;margin-top:2px;}
.rtoc-asst-head-r{display:flex;align-items:center;gap:8px;}
.rtoc-asst-credits{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);border-radius:999px;padding:3px 9px;font-size:11px;font-weight:650;white-space:nowrap;}
.rtoc-asst-x{background:transparent;border:0;color:#fff;font-size:24px;line-height:1;cursor:pointer;opacity:.85;padding:0 4px;border-radius:8px;}
.rtoc-asst-x:hover{opacity:1;}
.rtoc-asst-body{flex:1 1 auto;overflow-y:auto;padding:16px 14px 8px;background:#f7f8fb;}
.rtoc-asst-body::-webkit-scrollbar{width:8px;}
.rtoc-asst-body::-webkit-scrollbar-thumb{background:#d5dae3;border-radius:8px;}
.rtoc-asst-msg{display:flex;align-items:flex-start;gap:9px;margin-bottom:13px;}
.rtoc-asst-msg.is-user{justify-content:flex-end;}
.rtoc-asst-av{flex:0 0 auto;width:27px;height:27px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;display:flex;align-items:center;justify-content:center;margin-top:2px;box-shadow:0 2px 6px rgba(37,99,235,.4);}
.rtoc-asst-bub{max-width:80%;padding:11px 14px;border-radius:15px;font-size:14px;line-height:1.55;color:#1e293b;background:#fff;border:1px solid #eceef3;box-shadow:0 1px 2px rgba(15,23,42,.04);}
.rtoc-asst-msg.is-user .rtoc-asst-bub{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:0;border-bottom-right-radius:5px;}
.rtoc-asst-msg.is-bot .rtoc-asst-bub{border-bottom-left-radius:5px;}
.rtoc-asst-bub.is-error{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
.rtoc-asst-bub p{margin:0 0 8px;}
.rtoc-asst-bub p:last-child{margin-bottom:0;}
.rtoc-asst-bub .rtoc-asst-h{font-weight:700;margin:4px 0 6px;color:#0f172a;}
.rtoc-asst-ul,.rtoc-asst-ol{margin:4px 0 8px;padding-left:20px;}
.rtoc-asst-ul li,.rtoc-asst-ol li{margin:3px 0;}
.rtoc-asst-bub code{background:#eef0f6;border-radius:5px;padding:1px 5px;font-size:12.5px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#1d4ed8;}
.rtoc-asst-msg.is-user .rtoc-asst-bub code{background:rgba(255,255,255,.2);color:#fff;}
.rtoc-asst-pre{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:11px 13px;overflow:auto;font-size:12.5px;margin:6px 0;}
.rtoc-asst-pre code{background:transparent;color:inherit;padding:0;}
.rtoc-asst-bub a{color:#2563eb;font-weight:600;text-decoration:underline;}
.rtoc-asst-msg.is-user .rtoc-asst-bub a{color:#fff;}
.rtoc-asst-chips{display:flex;flex-wrap:wrap;gap:8px;margin:2px 0 10px 36px;}
/* v6.2.70: scope + !important so the theme's global button:hover{color:#fff} can't turn
   the indigo chip text white on the near-white chip background (unreadable on hover). */
#rtoc-asst-root .rtoc-asst-chip{background:#fff!important;border:1px solid #e2e8f0;color:#1d4ed8!important;border-radius:999px;padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;text-align:left;line-height:1.35;text-decoration:none!important;transition:background .15s,border-color .15s,transform .12s;}
#rtoc-asst-root .rtoc-asst-chip:hover,#rtoc-asst-root .rtoc-asst-chip:focus{background:#eff6ff!important;border-color:#bfdbfe!important;color:#1d4ed8!important;transform:translateY(-1px);}
.rtoc-asst-dots{display:inline-flex;gap:4px;padding:2px 0;}
.rtoc-asst-dots i{width:7px;height:7px;border-radius:50%;background:#93c5fd;display:inline-block;animation:rtoc-asst-bounce 1.2s infinite ease-in-out;}
.rtoc-asst-dots i:nth-child(2){animation-delay:.15s;}
.rtoc-asst-dots i:nth-child(3){animation-delay:.3s;}
@keyframes rtoc-asst-bounce{0%,80%,100%{transform:scale(.6);opacity:.5;}40%{transform:scale(1);opacity:1;}}
.rtoc-asst-foot{border-top:1px solid #eceef3;background:#fff;padding:9px 12px 12px;}
.rtoc-asst-cost{display:flex;align-items:center;gap:5px;color:#94a3b8;font-size:11.5px;font-weight:600;margin:0 2px 8px;}
.rtoc-asst-inrow{display:flex;align-items:flex-end;gap:8px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:14px;padding:6px 6px 6px 12px;transition:border-color .15s,box-shadow .15s;}
.rtoc-asst-inrow:focus-within{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(37,99,235,.15);background:#fff;}
.rtoc-asst-input{flex:1 1 auto;border:0;background:transparent;resize:none;outline:none;font-size:14px;line-height:1.5;color:#1e293b;max-height:120px;padding:5px 0;font-family:inherit;}
#rtoc-asst-root .rtoc-asst-send{flex:0 0 auto;width:38px;height:38px;border:0;border-radius:11px;cursor:pointer;color:#fff!important;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#2563eb,#1d4ed8)!important;box-shadow:0 4px 10px -2px rgba(37,99,235,.5);transition:transform .12s,opacity .15s;}
#rtoc-asst-root .rtoc-asst-send:hover,#rtoc-asst-root .rtoc-asst-send:focus{color:#fff!important;background:linear-gradient(135deg,#1d4ed8,#1e40af)!important;transform:translateY(-1px);}
/* v6.2.72: voice-input mic button — hardened against the theme's global button styling
   like the FAB/chips. Turns red + pulses while listening. */
#rtoc-asst-root .rtoc-asst-mic{flex:0 0 auto;width:38px;height:38px;border:0!important;border-radius:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;background:#eff6ff!important;color:#2563eb!important;transition:background .15s,color .15s,transform .12s;}
#rtoc-asst-root .rtoc-asst-mic:hover,#rtoc-asst-root .rtoc-asst-mic:focus{background:#dbeafe!important;color:#2563eb!important;transform:translateY(-1px);}
#rtoc-asst-root .rtoc-asst-mic.is-listening{background:#ef4444!important;color:#fff!important;animation:rtoc-asst-pulse 1.4s ease-in-out infinite;}
@keyframes rtoc-asst-pulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.45);}50%{box-shadow:0 0 0 7px rgba(239,68,68,0);}}
/* v6.2.73: pin every assistant icon size. Since v6.2.68 the widget renders inside the
   plugin's main region, where scoped SVG rules were shrinking these icons to a few px.
   Enforce their intended sizes under #rtoc-asst-root so no plugin/theme rule can override. */
#rtoc-asst-root .rtoc-asst-send svg,#rtoc-asst-root .rtoc-asst-mic svg{width:18px!important;height:18px!important;flex:0 0 auto;}
#rtoc-asst-root .rtoc-asst-fab-ic svg{width:24px!important;height:24px!important;}
#rtoc-asst-root .rtoc-asst-orb svg{width:20px!important;height:20px!important;}
#rtoc-asst-root .rtoc-asst-av svg{width:15px!important;height:15px!important;}
#rtoc-asst-root .rtoc-asst-cost svg{width:13px!important;height:13px!important;flex:0 0 auto;}
.rtoc-asst-send:disabled,.rtoc-asst-send.is-busy{opacity:.55;cursor:default;transform:none;}
@media (max-width:480px){#rtoc-asst-root{right:12px;bottom:12px;}.rtoc-asst-panel{width:calc(100vw - 24px);height:calc(100vh - 90px);}}
</style>
CSS;
    return $asst_css
        . '<div id="rtoc-asst-root" data-endpoint="' . s($asst_endpoint)
        . '" data-sesskey="' . s($asst_sk) . '" data-page="' . s($asst_page)
        . '" data-cost="1" data-credits=""></div>'
        . '<script src="' . s($asst_js) . '"></script>';
}

function local_rtocompliance_assistant_creds(): array {
    global $CFG;

    // Pull in Central Config (local_aiconfig) if present; it takes priority.
    $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($aiconfiglib)) {
        require_once($aiconfiglib);
    }

    $siteid = function_exists('local_aiconfig_get_siteid')
        ? trim((string) (local_aiconfig_get_siteid('local_rtocompliance') ?: get_config('local_rtocompliance', 'siteid') ?: ''))
        : trim((string) (get_config('local_rtocompliance', 'siteid') ?: ''));

    $apikey = function_exists('local_aiconfig_get_apikey')
        ? trim((string) (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: ''))
        : trim((string) (get_config('local_rtocompliance', 'apikey') ?: ''));

    // apiurl has no Central Config equivalent — plugin setting, defaulting to the platform.
    $apiurl = rtrim(trim((string) (get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com')), '/');

    $directkey = trim((string) (get_config('local_rtocompliance', 'assistant_claude_key') ?: ''));
    $model     = trim((string) (get_config('local_rtocompliance', 'assistant_model') ?: 'claude-sonnet-4-20250514'));

    $platformready = ($apiurl !== '' && $siteid !== '' && $apikey !== '');

    return [
        'apiurl'         => $apiurl,
        'siteid'         => $siteid,
        'apikey'         => $apikey,
        'directkey'      => $directkey,
        'model'          => $model,
        'platform_ready' => $platformready,
        'ready'          => ($platformready || $directkey !== ''),
    ];
}

function local_rtocompliance_assistant_ask(array $messages, string $page = ''): array {
    $creds = local_rtocompliance_assistant_creds();
    $apiurl    = $creds['apiurl'];
    $siteid    = $creds['siteid'];
    $apikey    = $creds['apikey'];
    $directkey = $creds['directkey'];
    $model     = $creds['model'];

    // Normalise + cap the conversation (last 10 turns, trim overly long messages).
    $clean = [];
    foreach (array_slice($messages, -10) as $m) {
        $role = (isset($m['role']) && $m['role'] === 'assistant') ? 'assistant' : 'user';
        $content = trim((string) ($m['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if (\core_text::strlen($content) > 4000) {
            $content = \core_text::substr($content, 0, 4000);
        }
        $clean[] = ['role' => $role, 'content' => $content];
    }
    if (empty($clean)) {
        return ['ok' => false, 'reply' => '', 'credits' => null, 'mode' => 'none', 'error' => 'Empty question'];
    }

    $kb = local_rtocompliance_assistant_kb($page);

    \core\session\manager::write_close();

    // v6.2.69: assistant.php is an AJAX_SCRIPT that does NOT auto-include lib/filelib.php,
    // where Moodle's \curl class lives — so the first question errored with
    // 'Class "curl" not found'. Load it here (idempotent) before either curl branch below.
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    // ── Primary: platform broker (1 credit/question) ─────────────────────────────
    if ($apiurl !== '' && $siteid !== '' && $apikey !== '') {
        $payload = json_encode([
            // v6.2.70: the platform's /api/credits endpoint (which correctly reports this
            // site's 3,469 credits) reads camelCase 'siteId'/'apiKey' from the body, but the
            // ask endpoint was only sent lowercase 'siteid'/'apikey' — so the ask endpoint did
            // not recognise the site and reported "out of credits" despite a healthy balance.
            // Send BOTH camelCase (to match the credits contract) and lowercase (back-compat).
            'siteId'         => $siteid,
            'apiKey'         => $apikey,
            'siteid'         => $siteid,
            'apikey'         => $apikey,
            'product'        => 'local_rtocompliance',
            'plugin_version' => (string) (get_config('local_rtocompliance', 'version') ?: ''),
            'page'           => $page,
            'knowledge_base' => $kb,
            'messages'       => $clean,
        ]);
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 60, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json',
            'X-Site-Id: ' . $siteid, 'X-Api-Key: ' . $apikey]);
        $raw  = $curl->post(rtrim($apiurl, '/') . '/api/assistant/ask', $payload);
        $code = (int) ($curl->info['http_code'] ?? 0);
        $data = json_decode((string) $raw, true);
        if ($code === 200 && is_array($data) && !empty($data['reply'])) {
            return [
                'ok'      => true,
                'reply'   => (string) $data['reply'],
                'credits' => isset($data['creditsRemaining']) ? (int) $data['creditsRemaining'] : null,
                'mode'    => 'platform',
                'error'   => '',
            ];
        }
        // Out of credits / disabled — surface the platform's message cleanly.
        if ($code === 402 || (is_array($data) && !empty($data['error']))) {
            $err = is_array($data) ? (string) ($data['error'] ?? 'Assistant unavailable') : 'Assistant unavailable';
            // Fall through to direct key only if there is one; else report.
            if ($directkey === '') {
                return ['ok' => false, 'reply' => '', 'credits' => null, 'mode' => 'platform', 'error' => $err];
            }
        }
        if ($directkey === '') {
            return ['ok' => false, 'reply' => '', 'credits' => null, 'mode' => 'platform',
                'error' => 'The assistant could not be reached (HTTP ' . $code . ').'];
        }
    }

    // ── Fallback: direct Anthropic key (no platform credits) ──────────────────────
    if ($directkey !== '') {
        $system = $kb;
        $body = json_encode([
            'model'      => $model,
            'max_tokens' => 1024,
            'system'     => $system,
            'messages'   => $clean,
        ]);
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 60, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $curl->setHeader([
            'Content-Type: application/json',
            'x-api-key: ' . $directkey,
            'anthropic-version: 2023-06-01',
        ]);
        $raw  = $curl->post('https://api.anthropic.com/v1/messages', $body);
        $code = (int) ($curl->info['http_code'] ?? 0);
        $data = json_decode((string) $raw, true);
        if ($code === 200 && is_array($data) && !empty($data['content'][0]['text'])) {
            return ['ok' => true, 'reply' => (string) $data['content'][0]['text'],
                'credits' => null, 'mode' => 'direct', 'error' => ''];
        }
        $err = is_array($data) && !empty($data['error']['message'])
            ? (string) $data['error']['message'] : ('Anthropic API error (HTTP ' . $code . ')');
        return ['ok' => false, 'reply' => '', 'credits' => null, 'mode' => 'direct', 'error' => $err];
    }

    return ['ok' => false, 'reply' => '', 'credits' => null, 'mode' => 'none',
        'error' => 'The assistant is not configured yet. Connect the platform API (or add a Claude API key) in Plugin Settings.'];
}

/**
 * CERT-AUTODESIGN (v6.2.7): send an uploaded certificate image to the platform's vision
 * endpoint and return detected field placements expressed as page FRACTIONS (0..1). The
 * caller (cert_template_autodesign.php) maps those fractions to mm and drops the fields on
 * the editor canvas for the author to review, nudge and Save. Charges platform AI credits
 * (the platform enforces the exact cost and refuses when out of credits).
 *
 * @param string $certtype    testamur|statement|record|completion
 * @param string $orientation 'P'|'L'
 * @param string $imagebase64 data URL or raw base64 PNG/JPEG/WebP of the uploaded cert
 * @param float  $pw          page width in mm
 * @param float  $ph          page height in mm
 * @param array  $availablefields list of ['key'=>, 'label'=>, 'group'=>] the plugin supports
 * @return array ['ok'=>bool, 'fields'=>array, 'credits'=>?int, 'error'=>string]
 */
function local_rtocompliance_cert_autodesign(string $certtype, string $orientation, string $imagebase64,
        float $pw, float $ph, array $availablefields): array {
    $apiurl = trim((string) (get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com'));
    $siteid = trim((string) get_config('local_rtocompliance', 'siteid'));
    $apikey = trim((string) get_config('local_rtocompliance', 'apikey'));
    if ($apiurl === '' || $siteid === '' || $apikey === '') {
        return ['ok' => false, 'fields' => [], 'credits' => null,
            'error' => 'The platform API is not connected. Add your Site ID and API key in Plugin Settings to use auto-design.'];
    }

    $payload = json_encode([
        'siteid'         => $siteid,
        'apikey'         => $apikey,
        'product'        => 'local_rtocompliance',
        'plugin_version' => (string) (get_config('local_rtocompliance', 'version') ?: ''),
        'certtype'       => $certtype,
        'orientation'    => $orientation,
        'page_w_mm'      => $pw,
        'page_h_mm'      => $ph,
        'image_base64'   => $imagebase64,
        'available_fields' => array_values($availablefields),
    ]);

    // CURL-CLASS-FIX (v6.2.12): ensure Moodle's \curl class is loaded even when called
    // from an AJAX_SCRIPT (which does not auto-include lib/filelib.php).
    require_once($GLOBALS['CFG']->libdir . '/filelib.php');

    \core\session\manager::write_close();

    $curl = new \curl();
    $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
    $curl->setHeader(['Content-Type: application/json', 'Accept: application/json',
        'X-Site-Id: ' . $siteid, 'X-Api-Key: ' . $apikey]);
    $raw  = $curl->post(rtrim($apiurl, '/') . '/api/certtemplate/autodesign', $payload);
    $code = (int) ($curl->info['http_code'] ?? 0);
    $data = json_decode((string) $raw, true);

    if ($code === 200 && is_array($data) && !empty($data['ok']) && isset($data['fields']) && is_array($data['fields'])) {
        // FIELD-VALIDATION (v6.2.28): never trust the platform response structurally. Keep
        // only well-formed detections — a key we actually offered (available_fields), with
        // numeric in-range fractions. Sanitise align to L/C/R; preserve an optional numeric
        // confidence and element_type for the editor's gating/cross-checks.
        $validkeys = [];
        foreach ($availablefields as $af) {
            if (isset($af['key'])) {
                $validkeys[(string) $af['key']] = true;
            }
        }
        $clean = [];
        foreach ($data['fields'] as $f) {
            if (!is_array($f) || !isset($f['key']) || empty($validkeys[(string) $f['key']])) {
                continue;
            }
            if (!isset($f['x_frac'], $f['y_frac']) || !is_numeric($f['x_frac']) || !is_numeric($f['y_frac'])) {
                continue;
            }
            $rec = [
                'key'    => (string) $f['key'],
                'x_frac' => min(1.0, max(0.0, (float) $f['x_frac'])),
                'y_frac' => min(1.0, max(0.0, (float) $f['y_frac'])),
            ];
            if (isset($f['w_frac']) && is_numeric($f['w_frac'])) {
                $rec['w_frac'] = min(1.0, max(0.0, (float) $f['w_frac']));
            }
            if (isset($f['h_frac']) && is_numeric($f['h_frac'])) {
                $rec['h_frac'] = min(1.0, max(0.0, (float) $f['h_frac']));
            }
            if (isset($f['align']) && in_array($f['align'], ['L', 'C', 'R'], true)) {
                $rec['align'] = $f['align'];
            }
            if (isset($f['confidence']) && is_numeric($f['confidence'])) {
                $rec['confidence'] = (float) $f['confidence'];
            }
            if (isset($f['element_type']) && is_string($f['element_type'])) {
                $rec['element_type'] = $f['element_type'];
            }
            $clean[] = $rec;
        }
        $result = [
            'ok'      => true,
            'fields'  => $clean,
            'credits' => isset($data['creditsRemaining']) ? (int) $data['creditsRemaining'] : null,
            'error'   => '',
        ];
        // Pass through the platform's detected SOURCE orientation, if it reports one, so the
        // editor can warn when the image orientation disagrees with the template.
        if (isset($data['detected_orientation']) && in_array($data['detected_orientation'], ['P', 'L'], true)) {
            $result['detected_orientation'] = $data['detected_orientation'];
        }
        return $result;
    }
    $err = (is_array($data) && !empty($data['error']))
        ? (string) $data['error']
        : ('Auto-design could not be completed (HTTP ' . $code . ').');
    return [
        'ok'      => false,
        'fields'  => [],
        'credits' => (is_array($data) && isset($data['creditsRemaining'])) ? (int) $data['creditsRemaining'] : null,
        'error'   => $err,
    ];
}
