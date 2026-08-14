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
 * RTO Compliance plugin — settings.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

global $CFG;
// FIX-SETTINGS-FUNCGUARD (v5.9.58): Switch from bare require_once to a function_exists()
// guard. The lib.php constant guard (LOCAL_RTOCOMPLIANCE_LIB_LOADED, added in v5.9.55)
// prevents re-execution when the same physical file is loaded via two different path strings
// (symlink vs $CFG->dirroot). However, if PHP OPcache is serving a stale cached bytecode of
// lib.php (from before v5.9.55 was installed), the constant guard never runs and the
// functions are never defined in the first load — so the second load from $CFG->dirroot also
// sees an uncached path and re-executes → "Cannot redeclare function" → HTTP 500.
//
// A function_exists() guard is immune to OPcache stale bytecode: it checks whether the
// RESULT of loading lib.php (i.e., the functions being defined) is already present — if yes,
// the require_once is skipped entirely regardless of which path string loaded lib.php or
// whether OPcache served a stale copy. This is the correct defence for all three scenarios:
//   (a) normal non-symlinked install: require_once already deduped by PHP → function exists → skip
//   (b) symlinked install, fresh lib.php (v5.9.55+): constant guard fires → function exists → skip
//   (c) symlinked install, OPcache serving stale lib.php (pre-v5.9.55): constant guard absent
//       but function IS defined from the first __DIR__ load → function exists → skip ← KEY FIX
if (!function_exists('local_rtocompliance_extend_navigation_frontpage')) {
    require_once($CFG->dirroot . '/local/rtocompliance/lib.php');
}
// FIX-SETTINGS-REDECLARE (v5.9.51): Guard the explicit avetmiss_codes load with
// class_exists to prevent "Cannot redeclare class local_rtocompliance\avetmiss_codes"
// fatal on Moodle instances with symlinked directories (same root cause as
// FIX-SOA-ISSUE-500). settings.php is processed by the admin tree builder on EVERY
// admin_externalpage_setup() call. On symlinked installs, Moodle's PSR-4 autoloader
// may already have loaded the class via the real (resolved) path, then the
// unconditional require_once below tries to load it via the $CFG->dirroot path
// (a different string) → PHP fatal "Cannot redeclare class" → HTTP 500 on all
// plugin admin pages (soa_issue.php, data_import.php, support.php, etc.).
// Fix: only require_once if the class has not yet been loaded by any mechanism.
if (!class_exists('\\local_rtocompliance\\avetmiss_codes', false)) {
    require_once($CFG->dirroot . '/local/rtocompliance/classes/avetmiss_codes.php');
}

// BUG-MGR-DASHBOARD-INVISIBLE (v4.2.28, 30 Apr 2026): Site Administration tree
// previously gated entirely on $hassiteconfig — meaning ONLY site admins (users
// with moodle/site:config) could see the RTO Compliance dashboard / qualbuilder
// / students / etc. menu entries OR successfully load /local/rtocompliance/
// index.php (admin_externalpage_setup() refuses to load a page that isn't in
// the registered $ADMIN tree for the calling user).  Result: a user with the
// "Manager" archetype role — which db/access.php correctly grants
// local/rtocompliance:manage AND local/rtocompliance:viewall to — saw a blank
// site admin tree, couldn't reach the dashboard from any nav menu, and even a
// direct URL gave them an access-denied page.  Fix: register the externalpage
// menu entries (lines 21-238 below) for any user who holds either of the two
// system-context view/manage caps; KEEP the actual admin_settingpage entries
// (245+ — RTO API keys, USI cert, AI keys, certificate fonts, etc.) wrapped in
// $hassiteconfig so non-admins still cannot tamper with secrets / global config.
$systemcontext = context_system::instance();
// ROLE-SPLIT (v4.2.30, 30 Apr 2026): three-way visibility gate.
// - Managers (siteadmin or :manage / :viewall) see the full menu tree below.
// - Trainers (:viewtrainer only, no :manage) see ONLY the Trainer Dashboard,
//   their currency profile, and read-only Validation Schedule.
// - Students (no caps) see nothing in Site Administration (and no nav entry
//   either — see lib.php local_rtocompliance_extend_navigation).
$canviewfull    = $hassiteconfig
    || has_capability('local/rtocompliance:manage', $systemcontext)
    || has_capability('local/rtocompliance:viewall', $systemcontext)
    || has_capability('local/rtocompliance:issuecerts', $systemcontext);
$canviewtrainer = $canviewfull
    || has_capability('local/rtocompliance:viewtrainer', $systemcontext);

if ($canviewfull || $canviewtrainer) {
    // Main category - appears as tab in Site Administration
    $maincategory = new admin_category(
        'local_rtocompliance_category',
        get_string('pluginname', 'local_rtocompliance')
    );
    $ADMIN->add('localplugins', $maincategory);
}

// Trainer-only branch: Trainer Dashboard surfaces in Site Administration too
// for trainers who DO happen to land there (e.g. via a custom theme that
// shows Site Admin to all logged-in users).  Managers see this AND the full
// tree below.
if ($canviewtrainer) {
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_trainerdashboard',
        get_string('trainerdashboard', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/trainer_dashboard.php'),
        'local/rtocompliance:viewtrainer'
    ));
}

if ($canviewfull) {
    // ========================================================================
    // ALL PAGES - FLAT STRUCTURE (no nested categories = no clickable headers)
    // CSS will create visual grouping with a beautiful grid layout
    // ========================================================================

    // How it works — plain-language overview, first item so newcomers read it first.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_howitworks',
        get_string('howitworks', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/how_it_works.php'),
        'local/rtocompliance:manage'
    ));

    // FAQ — 100 plain-English questions across 20 topics.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_faq',
        get_string('faq_title', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/faq.php'),
        'local/rtocompliance:manage'
    ));

    // Compliance Map — the full feature directory by ASQA 2025 Quality Area.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_compliancemap',
        get_string('compliancemap', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/compliance_map.php'),
        'local/rtocompliance:manage'
    ));

    // ASQA Compliance Mapping — each 2025 Standard mapped to a feature, with an
    // honest Covered/Partial/Gap status (Standard 4.3/4.4 self-assurance).
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_asqamap',
        get_string('asqamap', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/asqa_standards_map.php'),
        'local/rtocompliance:manage'
    ));

    // Compliance Health — live audit-readiness command centre.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_compliancehealth',
        get_string('compliancehealth', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/compliance_health.php'),
        'local/rtocompliance:manage'
    ));

    // AVETMISS Validation — pre-submission NCVER edit-rule check.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_natvalidate',
        get_string('natvalidate', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/nat_validate.php'),
        'local/rtocompliance:manage'
    ));

    // Quick Access
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_dashboard',
        get_string('dashboard', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/index.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_qualbuilder',
        get_string('qualificationbuilder', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/qualbuilder.php'),
        'local/rtocompliance:manage'
    ));

    // NOMINAL-HOURS (v5.9.418): import page for the authoritative nominal-hours dataset.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_nominalhours_import',
        'Import Nominal Hours',
        new moodle_url('/local/rtocompliance/nominalhours_import.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_results',
        get_string('student_results', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'local/rtocompliance:manage'
    ));

    // Student Management
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_students',
        get_string('student_records', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/students.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_certificates',
        get_string('certificates', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/certificates.php'),
        'local/rtocompliance:manage'
    ));

    // MULTI-UNIT-SOA (v4.6.101) — multi-unit SOA wizard.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_soa_issue',
        get_string('soa_issue', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/soa_issue.php'),
        'local/rtocompliance:issuecerts'
    ));

    // BULK-COURSE-CERTS (v4.7.104) — generate all certificates for a course.
    // FIX-NAV-LINK (v5.0.5): removed hardcoded courseid=1 (site course). Landing on the
    // picker screen is the correct entry point — courseid=1 showed "no completions" and
    // confused admins who clicked the menu item expecting a course selector.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_generate_course_certs',
        get_string('generate_course_certs', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/generate_course_certs.php'),
        'local/rtocompliance:issuecerts'
    ));

    // GEN-BY-QUAL (v5.2.0) — generate Testamur + RoR for a full qualification.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_generate_qual_certs',
        get_string('generate_qual_certs', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/generate_qual_certs.php'),
        'local/rtocompliance:issuecerts'
    ));

    // QUAL-CERT-HUB (v5.9.339) — unified hub: all qualification completion
    // status and certificate issuance from one page.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_qual_cert_hub',
        'Qualification Certificate Hub',
        new moodle_url('/local/rtocompliance/qual_cert_hub.php'),
        'local/rtocompliance:issuecerts'
    ));

    // CERT-TEMPLATE-BUILDER (v4.2.40) — visual template builder.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_cert_templates',
        get_string('cert_templates', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/cert_templates.php'),
        'local/rtocompliance:managecerttemplates'
    ));

    // CERT-OF-COMPLETION + TEST-CERT (v4.2.41) — sample PDF generator for any cert type.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_cert_test',
        get_string('cert_test_pagetitle', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/cert_test.php'),
        'local/rtocompliance:managecerttemplates'
    ));

    // TASK-46 (v5.9.346) — skipped qualification codes report.
    // Registered so admin_externalpage_setup() resolves correctly when an admin
    // is redirected here after running Sync Qualification Codes.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_skipped_programcodes',
        'Skipped Qualification Codes Report',
        new moodle_url('/local/rtocompliance/skipped_programcodes.php'),
        'moodle/site:config'
    ));

    // TASK-81 (v5.9.359): Bulk-repair enrolment rows with missing programcode.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_repair_programcodes',
        get_string('repair_programcodes_title', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/repair_programcodes.php'),
        'moodle/site:config'
    ));

    // COURSE-MAP-TABLE (v5.9.335) — admin-managed Moodle course → qual/unit mapping.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_course_map',
        'Moodle Course Map',
        new moodle_url('/local/rtocompliance/course_map.php'),
        'moodle/site:config'
    ));

    // Trainer Compliance
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_trainers',
        get_string('trainers', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/trainers.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_supervision',
        get_string('supervision_log', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/supervision.php'),
        'local/rtocompliance:manage'
    ));

    // Reporting
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_dataimport',
        get_string('dataimport', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/data_import.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_reconcile',
        'NAT Reconciliation Tool',
        new moodle_url('/local/rtocompliance/reconcile.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_regression_test',
        'Complaint Student Regression Tests',
        new moodle_url('/local/rtocompliance/regression_test.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_recovery_analyzer',
        'Enrolment Recovery Analyzer',
        new moodle_url('/local/rtocompliance/recovery_analyzer.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_foe_bulk_audit',
        'FOE Bulk Deletion Audit',
        new moodle_url('/local/rtocompliance/foe_bulk_audit.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_natexport',
        get_string('natexport', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/natexport.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_surveys',
        get_string('surveys', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/surveys.php'),
        'local/rtocompliance:manage'
    ));

    // Continuous Improvement
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_complaints',
        get_string('complaints_appeals', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/complaints.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_validation',
        get_string('validation', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/validation.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_tas',
        get_string('tas', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/tas.php'),
        'local/rtocompliance:manage'
    ));

    // RTO Governance
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_thirdparty',
        get_string('thirdparty', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/thirdparty.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_governancepage',
        get_string('governance', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/governance.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_rpl',
        get_string('rpl_credit', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/rpl.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_risk',
        get_string('risk_management', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/risk.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_feeprotection',
        get_string('feeprotection', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/feeprotection.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_insurance',
        get_string('insurance', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/insurance.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_transitions',
        get_string('transitions', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/transitions.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_locations',
        get_string('delivery_locations', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/locations.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_marketing_info',
        'Marketing Information',
        new moodle_url('/local/rtocompliance/marketing_info.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_student_support',
        'Student Support',
        new moodle_url('/local/rtocompliance/student_support.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_student_support_input',
        'Trainer Support Input',
        new moodle_url('/local/rtocompliance/student_support_input.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_workforce_management',
        'VET Workforce Management',
        new moodle_url('/local/rtocompliance/workforce_management.php'),
        'local/rtocompliance:manage'
    ));

    // Help & Support
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_supportinternal',
        get_string('support_internal', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/support.php'),
        'local/rtocompliance:manage'
    ));

    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_practiceguides',
        get_string('practice_guides', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/practice_guides.php'),
        'local/rtocompliance:manage'
    ));

    // AI Credit Usage Report
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_ai_usage_report',
        get_string('ai_usage_report', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/ai_usage_report.php'),
        'local/rtocompliance:manage'
    ));

    // Help & Support
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_support',
        get_string('support', 'local_rtocompliance'),
        new moodle_url('https://lms-labs.com/docs/rto-compliance'),
        'local/rtocompliance:manage'
    ));


    // ========================================================================
    // SETTINGS PAGES — site-admin only (RTO API keys, USI cert path, AI keys,
    // certificate fonts, log retention, etc.).  Never expose to manager role.
    // ========================================================================
    if ($hassiteconfig) {

    // PLUGIN-SETTINGS-WRAPPER (v5.2.87): custom page that shows our sidebar nav
    // and renders all admin settings sections inside it. Replaces the bare
    // /admin/settings.php?section=... links that had no sidebar.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_plugin_settings',
        get_string('pluginsettings', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/plugin_settings.php'),
        'moodle/site:config'
    ));

    $settings = new admin_settingpage('local_rtocompliance_settings', get_string('rtodetails', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $settings);

    if ($ADMIN->fulltree && isset($settings)) {
        // SUPERSEDED-UNIT MAP (v5.9.377): translate retired unit codes to their
        // current equivalent so completions in older-coded courses credit the
        // current unit. One mapping per line, e.g.  ABC12345 => ABC12345
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/supersededheading',
            get_string('supersededunitmap', 'local_rtocompliance'),
            get_string('supersededunitmap_desc', 'local_rtocompliance')
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_rtocompliance/supersededunitmap',
            get_string('supersededunitmap', 'local_rtocompliance'),
            get_string('supersededunitmap_help', 'local_rtocompliance'),
            '',
            PARAM_RAW, // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
            '70',
            '12'
        ));

        // ── ARCHIVE FAMILY MAPS (v5.9.457) — per-RTO configuration, ship empty ──
        // Groups a provider's own archived/superseded qualification codes into
        // "families" so the NAT importer can match old semester copies. No codes are
        // hardcoded in the product; each RTO enters its own here (or leaves blank).
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/archivefamilyheading',
            get_string('archivefamilyheading', 'local_rtocompliance'),
            get_string('archivefamilyheading_desc', 'local_rtocompliance')
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_rtocompliance/archivefamilymap',
            get_string('archivefamilymap', 'local_rtocompliance'),
            get_string('archivefamilymap_help', 'local_rtocompliance'),
            '',
            PARAM_RAW, // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
            '70',
            '8'
        ));
        $settings->add(new admin_setting_configtextarea(
            'local_rtocompliance/archivefamilykeywords',
            get_string('archivefamilykeywords', 'local_rtocompliance'),
            get_string('archivefamilykeywords_help', 'local_rtocompliance'),
            '',
            PARAM_RAW, // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
            '70',
            '8'
        ));

        $settings->add(new admin_setting_heading(
            'local_rtocompliance/rtodetails',
            get_string('rtodetails', 'local_rtocompliance'),
            get_string('rtodetails_desc', 'local_rtocompliance')
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/rtoname',
            get_string('rtoname', 'local_rtocompliance'),
            get_string('rtoname_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/rtocode',
            get_string('rtocode', 'local_rtocompliance'),
            get_string('rtocode_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/abn',
            get_string('abn', 'local_rtocompliance'),
            get_string('abn_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/logo',
            get_string('rtologo', 'local_rtocompliance'),
            get_string('rtologo_desc', 'local_rtocompliance'),
            'logo',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.svg']]
        ));

        // RTO-IDENTITY-IN-LOGO (v6.2.27): many RTOs' logo artwork already prints the RTO
        // legal/trading name and RTO code (TOID). When ticked, the certificate validator
        // stops requiring separate "RTO name" and "RTO code" text fields on templates.
        $settings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/logo_includes_rto_identity',
            get_string('logo_includes_rto_identity', 'local_rtocompliance'),
            get_string('logo_includes_rto_identity_desc', 'local_rtocompliance'),
            0
        ));

        // Cert-type option array reused by all 'applies to' multicheckbox settings.
        $cert_type_options = [
            'testamur'   => get_string('certtype_testamur',   'local_rtocompliance'),
            'statement'  => get_string('certtype_statement',  'local_rtocompliance'),
            'record'     => get_string('certtype_record',     'local_rtocompliance'),
            'completion' => get_string('certtype_completion', 'local_rtocompliance'),
        ];

        // v4.4.0 NRT-LOGO-COMPLIANCE — Compliance asset upload slots.
        // The renderer prefers admin-uploaded artwork over the bundled
        // pix/ fallbacks. NRT and AQF have ASQA-supplied bundled defaults
        // that work out of the box; the two free-form compliance slots
        // and the organisation seal are admin-upload-only.
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/compliance_logos_heading',
            get_string('compliance_logos_heading', 'local_rtocompliance'),
            get_string('compliance_logos_heading_desc', 'local_rtocompliance')
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/nrt_logo_file',
            get_string('nrt_logo_file', 'local_rtocompliance'),
            get_string('nrt_logo_file_desc', 'local_rtocompliance'),
            'nrt_logo_file',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/aqf_logo_file',
            get_string('aqf_logo_file', 'local_rtocompliance'),
            get_string('aqf_logo_file_desc', 'local_rtocompliance'),
            'aqf_logo_file',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/organisation_seal_file',
            get_string('organisation_seal_file', 'local_rtocompliance'),
            get_string('organisation_seal_file_desc', 'local_rtocompliance'),
            'organisation_seal_file',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configmulticheckbox(
            'local_rtocompliance/org_seal_cert_types',
            get_string('org_seal_cert_types', 'local_rtocompliance'),
            get_string('org_seal_cert_types_desc', 'local_rtocompliance'),
            ['testamur' => 1, 'statement' => 1],  // default: testamur + SoA
            $cert_type_options
        ));

        // CERT-ORIENTATION-FILTER (v6.2.6): let the RTO choose which page orientation(s)
        // they use. The Certificate Templates page then only lists templates in the ticked
        // orientation(s) and shows the preview in that orientation, decluttering the page for
        // RTOs that only ever issue e.g. portrait. Both ticked (default) = show everything.
        // CERT-HEADER-THEME-COLOUR (v6.2.8): default the certificate table header bar to the
        // site's Moodle theme primary colour so certs match the site brand automatically. When
        // ON (default), the custom colour below is ignored and the live theme colour is used.
        $settings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/certheader_use_theme',
            get_string('certheader_use_theme', 'local_rtocompliance'),
            get_string('certheader_use_theme_desc', 'local_rtocompliance'),
            1
        ));

        $settings->add(new admin_setting_configmulticheckbox(
            'local_rtocompliance/cert_allowed_orientations',
            get_string('cert_allowed_orientations', 'local_rtocompliance'),
            get_string('cert_allowed_orientations_desc', 'local_rtocompliance'),
            ['L' => 1, 'P' => 1],  // default: both orientations shown
            ['L' => get_string('cert_template_page_orientation_l', 'local_rtocompliance'),
             'P' => get_string('cert_template_page_orientation_p', 'local_rtocompliance')]
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/compliance_logo_1',
            get_string('compliance_logo_1', 'local_rtocompliance'),
            get_string('compliance_logo_1_desc', 'local_rtocompliance'),
            'compliance_logo_1',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/compliance_logo_2',
            get_string('compliance_logo_2', 'local_rtocompliance'),
            get_string('compliance_logo_2_desc', 'local_rtocompliance'),
            'compliance_logo_2',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        // ──────────────────────────────────────────────────────────────────────
        // v5.9.320 CERT-ASSETS — Certificate Elements
        //
        // Four branding assets are managed here.  For each, an "Apply to"
        // multi-checkbox lets the admin tick which certificate types should
        // include that asset.  Leaving every box unticked means the asset
        // applies to all four types (backwards-compatible default).
        //
        // Cert types: Testamur | Statement of Attainment | Record of Results
        //             | Certificate of Completion
        //
        // The renderer checks these settings at render time and withholds the
        // asset for cert types that are not ticked.
        // ──────────────────────────────────────────────────────────────────────
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/cert_elements_heading',
            get_string('cert_elements_heading', 'local_rtocompliance'),
            get_string('cert_elements_heading_desc', 'local_rtocompliance')
        ));

        // ── CEO / Authorised Signatory Signature ─────────────────────────────
        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/ceo_signature_file',
            get_string('ceo_signature_file', 'local_rtocompliance'),
            get_string('ceo_signature_file_desc', 'local_rtocompliance'),
            'ceo_signature_file',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configmulticheckbox(
            'local_rtocompliance/ceo_signature_cert_types',
            get_string('cert_asset_applies_to', 'local_rtocompliance'),
            get_string('cert_asset_applies_to_desc', 'local_rtocompliance'),
            [],     // default: empty = all cert types
            $cert_type_options
        ));

        // ── Secondary RTO Logo ────────────────────────────────────────────────
        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/secondary_logo',
            get_string('secondary_logo', 'local_rtocompliance'),
            get_string('secondary_logo_desc', 'local_rtocompliance'),
            'secondary_logo',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.svg']]
        ));

        $settings->add(new admin_setting_configmulticheckbox(
            'local_rtocompliance/secondary_logo_cert_types',
            get_string('cert_asset_applies_to', 'local_rtocompliance'),
            get_string('cert_asset_applies_to_desc', 'local_rtocompliance'),
            [],     // default: empty = all cert types
            $cert_type_options
        ));

        // ── Certificate Background Image ─────────────────────────────────────
        $settings->add(new admin_setting_configstoredfile(
            'local_rtocompliance/cert_background_file',
            get_string('cert_background_file', 'local_rtocompliance'),
            get_string('cert_background_file_desc', 'local_rtocompliance'),
            'cert_background_file',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
        ));

        $settings->add(new admin_setting_configmulticheckbox(
            'local_rtocompliance/cert_background_cert_types',
            get_string('cert_asset_applies_to', 'local_rtocompliance'),
            get_string('cert_asset_applies_to_desc', 'local_rtocompliance'),
            ['testamur' => 1],     // default: testamur only (most common use case)
            $cert_type_options
        ));

        // ── Units Table Header Colour (v5.9.447) ─────────────────────────────
        // Style A units table on the Statement of Attainment and Record of
        // Results certificates draws a shaded header bar (Unit Code / Unit Title
        // / Completion Date) with white bold labels.  The fill colour defaults to
        // the Moodle site's primary brand colour so the certificate matches the
        // site theme out of the box, and can be overridden here.
        $rtoc_default_headercolour = get_config('theme_' . $CFG->theme, 'brandcolor');
        if (empty($rtoc_default_headercolour)) {
            $rtoc_default_headercolour = get_config('theme_boost', 'brandcolor');
        }
        if (empty($rtoc_default_headercolour) || $rtoc_default_headercolour[0] !== '#') {
            $rtoc_default_headercolour = '#0f6cbf'; // Moodle Boost default primary.
        }
        $settings->add(new admin_setting_configcolourpicker(
            'local_rtocompliance/certheadercolour',
            get_string('certheadercolour', 'local_rtocompliance'),
            get_string('certheadercolour_desc', 'local_rtocompliance'),
            $rtoc_default_headercolour
        ));

        // ────────────────────────────────────────────────────────────────────

        $regulators = local_rtocompliance_get_state_regulators();
        $settings->add(new admin_setting_configselect(
            'local_rtocompliance/regulator',
            get_string('regulator', 'local_rtocompliance'),
            get_string('regulator_desc', 'local_rtocompliance'),
            'asqa',
            $regulators
        ));

        $settings->add(new admin_setting_heading(
            'local_rtocompliance/contactdetails',
            get_string('contactdetails', 'local_rtocompliance'),
            ''
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/address',
            get_string('address', 'local_rtocompliance'),
            get_string('address_nat_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        // FIX-NAT00010-ADDRESS-FIELDS (v5.9.319): suburb, state, postcode, contactname
        // are required for NAT00010 (Training Organisation) AVETMISS export.
        // These config keys were read by nat_generator.php but had no settings.php
        // definitions — they always returned empty, producing blank NAT00010 address fields.
        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/suburb',
            get_string('suburb', 'local_rtocompliance'),
            get_string('suburb_nat_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/state',
            get_string('state', 'local_rtocompliance'),
            get_string('state_nat_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/postcode',
            get_string('postcode', 'local_rtocompliance'),
            get_string('postcode_nat_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/contactname',
            get_string('contactname', 'local_rtocompliance'),
            get_string('contactname_nat_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/phone',
            get_string('phone', 'local_rtocompliance'),
            '',
            '',
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/email',
            get_string('email', 'local_rtocompliance'),
            '',
            '',
            PARAM_EMAIL
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/website',
            get_string('website', 'local_rtocompliance'),
            '',
            '',
            PARAM_URL
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/student_handbook_url',
            get_string('student_handbook_url', 'local_rtocompliance'),
            get_string('student_handbook_url_desc', 'local_rtocompliance'),
            '',
            PARAM_URL
        ));

        // ── Pre-Enrolment Policy Document URLs (Standard 2.1) ────────────────
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/policy_docs_heading',
            'Pre-Enrolment Policy Document URLs',
            'Configure direct URLs to your policy PDF documents. These are displayed on the Standard 2.1 Marketing Information cards. '
            . 'You can use a full URL (https://example.com/policy.pdf) or a local path (/moodledata/policy.pdf). Leave blank if not yet available.'
        ));

        foreach ([
            'policy_safe_learning_url'      => 'Safe & Inclusive Learning Policy URL',
            'policy_equity_url'             => 'Equity & Diversity Policy URL',
            'policy_cultural_url'           => 'Cultural Safety Policy URL',
            'policy_antidiscrimination_url' => 'Anti-Discrimination Policy URL',
            'policy_codeofconduct_url'      => 'Student Code of Conduct URL',
        ] as $key => $label) {
            $settings->add(new admin_setting_configtext(
                'local_rtocompliance/' . $key,
                $label,
                'Full URL (https://...) or local path (/path/to/file.pdf). Leave blank if not yet configured.',
                '',
                PARAM_LOCALURL
            ));
        }

        // ── Pre-Enrolment Suitability Auto-Send ─────────────────────────────
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/suitability_autosend_heading',
            get_string('autosend_suitability_heading', 'local_rtocompliance'),
            get_string('autosend_suitability_heading_desc', 'local_rtocompliance')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/autosend_suitability',
            get_string('autosend_suitability', 'local_rtocompliance'),
            get_string('autosend_suitability_desc', 'local_rtocompliance'),
            0
        ));

        // Populate TAS dropdown — guard against fresh install (tables may not exist yet)
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_rtocompliance_tas')) {
            $tasrows = $DB->get_records_sql(
                "SELECT id, qualificationcode, qualificationname
                   FROM {local_rtocompliance_tas}
                  WHERE status IN ('approved','review')
                    AND entryrequirements IS NOT NULL AND entryrequirements != ''
                  ORDER BY qualificationcode",
                []
            );
            $tasopts = ['' => get_string('autosend_suitability_tasid_none', 'local_rtocompliance')];
            foreach ($tasrows as $t) {
                $tasopts[$t->id] = $t->qualificationcode . ': ' . $t->qualificationname;
            }
        } else {
            $tasopts = ['' => get_string('autosend_suitability_tasid_none', 'local_rtocompliance')];
        }

        $settings->add(new admin_setting_configselect(
            'local_rtocompliance/autosend_suitability_tasid',
            get_string('autosend_suitability_tasid', 'local_rtocompliance'),
            get_string('autosend_suitability_tasid_desc', 'local_rtocompliance'),
            '',
            $tasopts
        ));

        // ── LLN Integration (v4.2.50 — pluggable adapter) ──────────────────
        $settings->add(new admin_setting_heading(
            'local_rtocompliance/lln_heading',
            get_string('lln_heading', 'local_rtocompliance'),
            get_string('lln_heading_desc', 'local_rtocompliance')
        ));

        $settings->add(new admin_setting_configselect(
            'local_rtocompliance/lln_adapter',
            get_string('lln_adapter', 'local_rtocompliance'),
            get_string('lln_adapter_desc', 'local_rtocompliance'),
            'manual',
            \local_rtocompliance\lln\lln_dispatcher::available_adapters()
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/lln_provider_label',
            get_string('lln_provider_label', 'local_rtocompliance'),
            get_string('lln_provider_label_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT,
            60
        ));

        $settings->add(new admin_setting_configtext(
            'local_rtocompliance/lln_webhook_url',
            get_string('lln_webhook_url', 'local_rtocompliance'),
            get_string('lln_webhook_url_desc', 'local_rtocompliance'),
            '',
            PARAM_URL,
            80
        ));

        $settings->add(new admin_setting_configpasswordunmask(
            'local_rtocompliance/lln_webhook_secret',
            get_string('lln_webhook_secret', 'local_rtocompliance'),
            get_string('lln_webhook_secret_desc', 'local_rtocompliance'),
            ''
        ));
    }

    $apisettings = new admin_settingpage('local_rtocompliance_api', get_string('apiheading', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $apisettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $apisettings->add(new admin_setting_heading(
            'local_rtocompliance/apiheading',
            get_string('apiheading', 'local_rtocompliance'),
            get_string('apiheading_desc', 'local_rtocompliance')
        ));

        $apisettings->add(new admin_setting_configtext(
            'local_rtocompliance/siteid',
            get_string('siteid', 'local_rtocompliance'),
            get_string('siteid_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $apisettings->add(new admin_setting_configpasswordunmask(
            'local_rtocompliance/apikey',
            get_string('apikey', 'local_rtocompliance'),
            get_string('apikey_desc', 'local_rtocompliance'),
            ''
        ));

        $apisettings->add(new admin_setting_configtext(
            'local_rtocompliance/apiurl',
            get_string('apiurl', 'local_rtocompliance'),
            get_string('apiurl_desc', 'local_rtocompliance'),
            'https://lms-labs.com',
            PARAM_URL
        ));

        $apisettings->add(new admin_setting_heading(
            'local_rtocompliance/webhook_heading',
            'Remote Configuration',
            'Leave this blank. If LMS-Labs.com support provides you with a key, enter it below — this allows our team to configure your site remotely.'
        ));
        $apisettings->add(new admin_setting_configpasswordunmask(
            'local_rtocompliance/webhookapikey',
            get_string('webhookapikey', 'local_rtocompliance'),
            get_string('webhookapikey_desc', 'local_rtocompliance'),
            ''
        ));

        // ── AI ASSISTANT (v5.9.456) ──────────────────────────────────────────────
        $apisettings->add(new admin_setting_heading(
            'local_rtocompliance/assistant_heading',
            get_string('assistant_heading', 'local_rtocompliance'),
            get_string('assistant_heading_desc', 'local_rtocompliance')
        ));
        $apisettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/assistant_enabled',
            get_string('assistant_enabled', 'local_rtocompliance'),
            get_string('assistant_enabled_desc', 'local_rtocompliance'),
            1
        ));
        // Optional: a direct Anthropic key for self-hosted installs that do NOT bill
        // through the lms-labs.com platform. Leave blank to use the platform (recommended;
        // 1 credit per question). When set, questions call Anthropic directly on your key.
        $apisettings->add(new admin_setting_configpasswordunmask(
            'local_rtocompliance/assistant_claude_key',
            get_string('assistant_claude_key', 'local_rtocompliance'),
            get_string('assistant_claude_key_desc', 'local_rtocompliance'),
            ''
        ));
        $apisettings->add(new admin_setting_configtext(
            'local_rtocompliance/assistant_model',
            get_string('assistant_model', 'local_rtocompliance'),
            get_string('assistant_model_desc', 'local_rtocompliance'),
            'claude-sonnet-4-20250514',
            PARAM_TEXT
        ));
    }

    $certsettings = new admin_settingpage('local_rtocompliance_certs', get_string('certificatesettings', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $certsettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/certificatesettings',
            get_string('certificatesettings', 'local_rtocompliance'),
            ''
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/certprefix',
            get_string('certprefix', 'local_rtocompliance'),
            get_string('certprefix_desc', 'local_rtocompliance'),
            'RTO',
            PARAM_ALPHANUMEXT
        ));

        // v5.9.361: per-type starting number — lets an RTO begin a stream at, e.g.,
        // 1000. Applied once (sequences are continuous), so set before the first issue.
        foreach ([
            'testamur'   => 'certstartnum_testamur_label',
            'statement'  => 'certstartnum_statement_label',
            'record'     => 'certstartnum_record_label',
            'completion' => 'certstartnum_completion_label',
        ] as $_ct => $_lbl) {
            $certsettings->add(new admin_setting_configtext(
                'local_rtocompliance/certstartnum_' . $_ct,
                get_string($_lbl, 'local_rtocompliance'),
                get_string('certstartnum_desc', 'local_rtocompliance'),
                '1',
                PARAM_INT
            ));
        }

        $certsettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/enableqr',
            get_string('enableqr', 'local_rtocompliance'),
            get_string('enableqr_desc', 'local_rtocompliance'),
            1
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/verifyurl',
            get_string('verifyurl', 'local_rtocompliance'),
            get_string('verifyurl_desc', 'local_rtocompliance'),
            '',
            PARAM_URL
        ));

        $certsettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/emailcerts',
            get_string('emailcerts', 'local_rtocompliance'),
            get_string('emailcerts_desc', 'local_rtocompliance'),
            1
        ));

        // ── ASQA-COMPLIANCE-SETTINGS (v4.2.58) ─────────────────────────────
        // These four settings populate the mandatory ASQA elements on
        // every certificate (signatory block, AQF statement, footer).
        // They are read by both the cert template renderer and the
        // legacy fallback PDF generator in lib.php so the cert is
        // ASQA-compliant regardless of which path renders it.
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/asqaheading',
            'ASQA-mandated certificate elements',
            'These four settings ensure every issued certificate carries the elements required by the ASQA Sample Testamur and Statement of Attainment fact sheet (signatory, AQF statement, footer).'
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/signatoryname',
            get_string('signatoryname', 'local_rtocompliance'),
            get_string('signatoryname_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/signatorytitle',
            get_string('signatorytitle', 'local_rtocompliance'),
            get_string('signatorytitle_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/aqfstatement',
            get_string('aqfstatement', 'local_rtocompliance'),
            get_string('aqfstatement_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        // FIX-MANDATORY-WORDING (v5.2.38): Allow RTOs to adjust mandatory
        // ASQA phrase wording (e.g. correct capitalisation, language).
        // Leave any field blank to use the built-in ASQA default.
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/mandatoryphrasesheading',
            get_string('mandatoryphrasesheading', 'local_rtocompliance'),
            get_string('mandatoryphrasesheading_desc', 'local_rtocompliance')
        ));

        // ── TESTAMUR phrases ─────────────────────────────────────────────────
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/testamurphrasesheading',
            'Testamur phrases',
            'Phrases used on Testamurs only. See <a href="https://www.asqa.gov.au/sites/default/files/2026-04/fact_sheet_-_sample_forms_of_aqf_certification_documentation.pdf" target="_blank" rel="noopener">ASQA Sample Forms of AQF Certification Documentation</a> (page 2).'
        ));
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/certify_statement',
            get_string('certify_statement_setting', 'local_rtocompliance'),
            get_string('certify_statement_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/attained_statement',
            get_string('attained_statement_setting', 'local_rtocompliance'),
            get_string('attained_statement_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        // ── STATEMENT OF ATTAINMENT phrases ──────────────────────────────────
        // Per ASQA fact sheet p.4 (two-column layout):
        //   Centre column = content that goes on the cert.
        //   Right margin  = ASQA annotations (not cert content).
        // Settings are ordered top-to-bottom as they appear on the physical SoA:
        //   1. Mandatory banner (VERY TOP — above RTO name/logo, per ASQA layout p.4)
        //   2. Document heading (RTO-branded title below the banner)
        //   3. Intro phrase before student name
        //   4. Attained phrase after student name
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/soaphrasesheading',
            'Statement of Attainment phrases',
            'Phrases used on Statements of Attainment only. Per the <a href="https://www.asqa.gov.au/sites/default/files/2026-04/fact_sheet_-_sample_forms_of_aqf_certification_documentation.pdf" target="_blank" rel="noopener">ASQA fact sheet</a> (page 4): settings are listed in the order they appear on the document from top to bottom.'
        ));
        // 1 — Mandatory banner: appears at the VERY TOP of the SoA, above RTO name/logo (ASQA p.4)
        $certsettings->add(new admin_setting_configtextarea(
            'local_rtocompliance/not_a_testamur_statement',
            get_string('not_a_testamur_setting', 'local_rtocompliance'),
            get_string('not_a_testamur_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
        // 2 — Document heading (below the banner, above student name)
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/statement_of_attainment_heading',
            get_string('statement_of_attainment_heading_setting', 'local_rtocompliance'),
            get_string('statement_of_attainment_heading_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
        // 3 — Intro phrase before student name
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/soa_intro_statement',
            get_string('soa_intro_statement_setting', 'local_rtocompliance'),
            get_string('soa_intro_statement_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
        // 4 — Attained phrase after student name
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/soa_attained_statement',
            get_string('soa_attained_statement_setting', 'local_rtocompliance'),
            get_string('soa_attained_statement_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        // ── RECORD OF RESULTS heading ─────────────────────────────────────────
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/rorheading',
            'Record of Results',
            'Settings for the Record of Results document type.'
        ));
        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/record_of_results_heading',
            get_string('record_of_results_heading_setting', 'local_rtocompliance'),
            get_string('record_of_results_heading_setting_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtextarea(
            'local_rtocompliance/certfooter',
            get_string('certfooter', 'local_rtocompliance'),
            get_string('certfooter_desc', 'local_rtocompliance'),
            'This qualification is recognised within the Australian Qualifications Framework.',
            PARAM_TEXT
        ));

        // ── ASQA-COMPLIANCE-PASS-2 (v4.2.59) — optional descriptors ────────
        // Per ASQA "Sample forms of AQF certification documentation" fact
        // sheet: testamurs and statements of attainment may carry an
        // industry descriptor, occupational stream, Australian Apprenticeship
        // statement, language-of-issue statement, or skill set statement.
        // These are optional but if used must be configured here so they
        // appear consistently on every issued certificate.
        $certsettings->add(new admin_setting_heading(
            'local_rtocompliance/asqaoptionalheading',
            'ASQA optional descriptors',
            'Optional descriptors permitted by the ASQA Sample Forms fact sheet. Leave blank to omit. Industry descriptor and occupational stream appear on testamurs; the language and Australian Apprenticeship statements appear on testamurs and statements of attainment; the skill set statement appears only on statements of attainment.'
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/industrydescriptor',
            get_string('industrydescriptor', 'local_rtocompliance'),
            get_string('industrydescriptor_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/occupationalstream',
            get_string('occupationalstream', 'local_rtocompliance'),
            get_string('occupationalstream_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/apprenticeshipstatement',
            get_string('apprenticeshipstatement', 'local_rtocompliance'),
            get_string('apprenticeshipstatement_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/languagestatement',
            get_string('languagestatement', 'local_rtocompliance'),
            get_string('languagestatement_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));

        // NOTE: The "completion of course" statement (ASQA fact sheet p.4:
        // "These competencies were attained in completion of {CODE} course in {NAME}.")
        // is now AUTO-GENERATED at render time using the live qualification code and
        // name from the certificate record — no manual configuration required.
        // This setting is intentionally no longer displayed here to avoid confusion.
        // (Formerly: local_rtocompliance/completionofcoursestatement)

        $certsettings->add(new admin_setting_configtext(
            'local_rtocompliance/skillsetstatement',
            get_string('skillsetstatement', 'local_rtocompliance'),
            get_string('skillsetstatement_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
    }

    $reportsettings = new admin_settingpage('local_rtocompliance_reportconfig', get_string('reportsettings', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $reportsettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $reportsettings->add(new admin_setting_heading(
            'local_rtocompliance/reportsettings',
            get_string('reportsettings', 'local_rtocompliance'),
            ''
        ));

        $states = [
            'NSW' => 'New South Wales',
            'VIC' => 'Victoria',
            'QLD' => 'Queensland',
            'SA'  => 'South Australia',
            'WA'  => 'Western Australia',
            'TAS' => 'Tasmania',
            'NT'  => 'Northern Territory',
            'ACT' => 'Australian Capital Territory',
            'OTH' => 'Other Australian Territory',
            'OS'  => 'Overseas',
        ];
        $reportsettings->add(new admin_setting_configselect(
            'local_rtocompliance/defaultstate',
            get_string('defaultstate', 'local_rtocompliance'),
            get_string('defaultstate_desc', 'local_rtocompliance'),
            'NSW',
            $states
        ));

        // NOMINAL-HOURS (v5.9.418): the jurisdiction whose nominal-hours values the
        // Qualification Builder / enrolments resolve first (falls back to the NCVER
        // national baseline 'NAT'). Literal strings so no lang entries are required.
        $reportsettings->add(new admin_setting_configselect(
            'local_rtocompliance/defaultreportingstate',
            'Nominal hours — reporting jurisdiction',
            'The state/territory whose nominal-hours values are used first when resolving a unit\'s nominal hours. '
                . 'Falls back to the NCVER nationally-agreed value (NAT) when the state has no value on file.',
            'NAT',
            ['NAT' => 'NAT — NCVER nationally agreed', 'VIC' => 'VIC', 'QLD' => 'QLD', 'NSW' => 'NSW',
                'SA' => 'SA', 'WA' => 'WA', 'TAS' => 'TAS', 'NT' => 'NT', 'ACT' => 'ACT']
        ));

        $reportyears = [];
        for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++) {
            $reportyears[$y] = $y;
        }
        $reportsettings->add(new admin_setting_configselect(
            'local_rtocompliance/reportyear',
            get_string('reportyear', 'local_rtocompliance'),
            get_string('reportyear_desc', 'local_rtocompliance'),
            date('Y'),
            $reportyears
        ));
    }

    // ── State Funding & Reporting ─────────────────────────────────────────────
    // v5.9.43: Per-state configuration for AVETMISS "below the line" fields
    // required by each Australian State Training Authority (STA). Settings here
    // provide RTO-level defaults (RTO identifier, purchasing contracts, default
    // funding code) that pre-fill per-enrolment fields but can be overridden
    // at the individual enrolment level.
    $statefundingsettings = new admin_settingpage(
        'local_rtocompliance_statefunding',
        get_string('statefunding_settings', 'local_rtocompliance')
    );
    $ADMIN->add('local_rtocompliance_category', $statefundingsettings);

    if ($ADMIN->fulltree && isset($settings)) {

        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_heading',
            get_string('statefunding_settings', 'local_rtocompliance'),
            get_string('statefunding_settings_desc', 'local_rtocompliance')
        ));

        // ── Queensland (QLD) ───────────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_qld_heading',
            get_string('statefunding_qld', 'local_rtocompliance'),
            get_string('statefunding_qld_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/qld_dtet_rtoid',
            get_string('qld_dtet_rtoid', 'local_rtocompliance'),
            get_string('qld_dtet_rtoid_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/qld_funding_code_default',
            get_string('qld_funding_code_default', 'local_rtocompliance'),
            get_string('qld_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('QLD')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/qld_purchasing_contract_1',
            get_string('qld_purchasing_contract_1', 'local_rtocompliance'),
            get_string('qld_purchasing_contract_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/qld_purchasing_contract_2',
            get_string('qld_purchasing_contract_2', 'local_rtocompliance'),
            '',
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/qld_purchasing_contract_3',
            get_string('qld_purchasing_contract_3', 'local_rtocompliance'),
            '',
            '',
            PARAM_ALPHANUMEXT
        ));

        // ── New South Wales (NSW) ──────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_nsw_heading',
            get_string('statefunding_nsw', 'local_rtocompliance'),
            get_string('statefunding_nsw_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/nsw_commitment_id',
            get_string('nsw_commitment_id', 'local_rtocompliance'),
            get_string('nsw_commitment_id_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/nsw_funding_code_default',
            get_string('nsw_funding_code_default', 'local_rtocompliance'),
            get_string('nsw_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('NSW')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/nsw_purchasing_contract',
            get_string('nsw_purchasing_contract', 'local_rtocompliance'),
            get_string('nsw_purchasing_contract_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));

        // ── Victoria (VIC) ────────────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_vic_heading',
            get_string('statefunding_vic', 'local_rtocompliance'),
            get_string('statefunding_vic_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/vic_contract_id',
            get_string('vic_contract_id', 'local_rtocompliance'),
            get_string('vic_contract_id_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/vic_funding_code_default',
            get_string('vic_funding_code_default', 'local_rtocompliance'),
            get_string('vic_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('VIC')
        ));

        // ── South Australia (SA) ──────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_sa_heading',
            get_string('statefunding_sa', 'local_rtocompliance'),
            get_string('statefunding_sa_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/sa_contract_ref',
            get_string('sa_contract_ref', 'local_rtocompliance'),
            get_string('sa_contract_ref_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/sa_funding_code_default',
            get_string('sa_funding_code_default', 'local_rtocompliance'),
            get_string('sa_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('SA')
        ));

        // ── Western Australia (WA) ────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_wa_heading',
            get_string('statefunding_wa', 'local_rtocompliance'),
            get_string('statefunding_wa_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/wa_contract_number',
            get_string('wa_contract_number', 'local_rtocompliance'),
            get_string('wa_contract_number_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/wa_funding_code_default',
            get_string('wa_funding_code_default', 'local_rtocompliance'),
            get_string('wa_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('WA')
        ));

        // ── Tasmania (TAS) ────────────────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_tas_heading',
            get_string('statefunding_tas', 'local_rtocompliance'),
            get_string('statefunding_tas_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/tas_contract_ref',
            get_string('tas_contract_ref', 'local_rtocompliance'),
            get_string('tas_contract_ref_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/tas_funding_code_default',
            get_string('tas_funding_code_default', 'local_rtocompliance'),
            get_string('tas_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('TAS')
        ));

        // ── Northern Territory (NT) ───────────────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_nt_heading',
            get_string('statefunding_nt', 'local_rtocompliance'),
            get_string('statefunding_nt_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/nt_contract_ref',
            get_string('nt_contract_ref', 'local_rtocompliance'),
            get_string('nt_contract_ref_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/nt_funding_code_default',
            get_string('nt_funding_code_default', 'local_rtocompliance'),
            get_string('nt_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('NT')
        ));

        // ── Australian Capital Territory (ACT) ────────────────────────────────
        $statefundingsettings->add(new admin_setting_heading(
            'local_rtocompliance/statefunding_act_heading',
            get_string('statefunding_act', 'local_rtocompliance'),
            get_string('statefunding_act_desc', 'local_rtocompliance')
        ));
        $statefundingsettings->add(new admin_setting_configtext(
            'local_rtocompliance/act_avetars_ref',
            get_string('act_avetars_ref', 'local_rtocompliance'),
            get_string('act_avetars_ref_desc', 'local_rtocompliance'),
            '',
            PARAM_ALPHANUMEXT
        ));
        $statefundingsettings->add(new admin_setting_configselect(
            'local_rtocompliance/act_funding_code_default',
            get_string('act_funding_code_default', 'local_rtocompliance'),
            get_string('act_funding_code_default_desc', 'local_rtocompliance'),
            '',
            \local_rtocompliance\avetmiss_codes::get_state_funding_source_codes('ACT')
        ));

    } // end state funding settings

    // ── USI — Upload Machine Credential (PER-TENANT, RECOMMENDED) ───────────
    // PER-TENANT-USI (v4.2.31, 30 Apr 2026): the new self-service .pfx
    // upload page sits FIRST so it's the obvious choice for new customers.
    // Site-admin only because the credential auths the entire RTO.
    $ADMIN->add('local_rtocompliance_category', new admin_externalpage(
        'local_rtocompliance_usi_pertenant',
        get_string('usi_pertenant_title', 'local_rtocompliance'),
        new moodle_url('/local/rtocompliance/usi_settings.php'),
        'moodle/site:config'
    ));

    // ── USI Verification Settings (LEGACY) ──────────────────────────────────
    // These fields are still here for backwards compatibility with single-
    // tenant installs that set the cert via a server-local file path.  Modern
    // SaaS customers should use the Upload Machine Credential page above.
    $usisettings = new admin_settingpage('local_rtocompliance_usi', get_string('usi_settings_legacy', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $usisettings);

    if ($ADMIN->fulltree && isset($settings)) {
        // LEGACY-REDIRECT-BANNER (v4.2.31): point everyone at the upload page.
        $uploadurl = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out();
        $redirectbanner = '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:16px 20px;margin-bottom:24px;">' .
            '<div style="display:flex;align-items:flex-start;gap:12px;">' .
            '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' .
            '<div style="flex:1;">' .
            '<div style="font-weight:600;color:#92400e;margin-bottom:4px;">Legacy page — you do not need this</div>' .
            '<div style="color:#78350f;font-size:13px;line-height:1.5;">' .
            'Your myID Machine Credential and password are managed in the <strong>lms-labs.com admin panel</strong> and ' .
            'are never stored on this Moodle server. USI verification is performed by the platform on your behalf. ' .
            'You can view the live verification status on the ' .
            '<a href="' . $uploadurl . '" style="color:#1d4ed8;text-decoration:underline;font-weight:600;">USI Verification Status page →</a>. ' .
            'The only settings below that still apply are the non-secret organisation ID and test-mode flag (normally ' .
            'pushed automatically from the platform).' .
            '</div></div></div></div>';
        $usisettings->add(new admin_setting_heading('local_rtocompliance/usi_legacy_redirect', '', $redirectbanner));

        $usisettings->add(new admin_setting_heading(
            'local_rtocompliance/usi_heading',
            get_string('usi_settings_legacy', 'local_rtocompliance'),
            get_string('usi_settings_desc', 'local_rtocompliance')
        ));
        $usisettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/usi_verification_enabled',
            get_string('usi_verification_enabled', 'local_rtocompliance'),
            get_string('usi_verification_enabled_desc', 'local_rtocompliance'),
            1
        ));
        $usisettings->add(new admin_setting_configtext(
            'local_rtocompliance/usi_organization_id',
            get_string('usi_organization_id', 'local_rtocompliance'),
            get_string('usi_organization_id_desc', 'local_rtocompliance'),
            '',
            PARAM_TEXT
        ));
        $usisettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/usi_test_mode',
            get_string('usi_test_mode', 'local_rtocompliance'),
            get_string('usi_test_mode_desc', 'local_rtocompliance'),
            0
        ));
        // CREDENTIAL-BROKER-ONLY (v5.9.452): the machine credential keystore (.p12/.pfx)
        // and its passphrase are NO LONGER entered or stored in Moodle. They live only in
        // the lms-labs.com admin panel, which verifies USIs on this site's behalf. The old
        // 'usi_certificate_path' and 'usi_certificate_password' fields have been removed so
        // the sensitive keystore can never be stored on the Moodle server. Only the
        // non-secret organisation ID and test-mode flag remain here (and even those are
        // normally pushed automatically from the platform).
        $credinfo = '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:12px 16px;margin-top:8px;font-size:13px;color:#065f46;">' .
            '<strong>Credentials are managed in lms-labs.com.</strong> Your myGovID machine credential ' .
            '(.p12/.pfx) and its password are uploaded in the <strong>lms-labs.com admin panel</strong> and never ' .
            'stored on this Moodle server. USI verification is performed by the platform on your behalf, so there ' .
            'is nothing to enter here beyond the organisation ID and test-mode flag above.' .
            '</div>';
        $usisettings->add(new admin_setting_heading('local_rtocompliance/usi_push_info', '', $credinfo));
    }

    $surveysettings = new admin_settingpage('local_rtocompliance_autosurvey', get_string('autosurveysettings', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $surveysettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $surveysettings->add(new admin_setting_heading(
            'local_rtocompliance/autosurveysettings',
            get_string('autosurveysettings', 'local_rtocompliance'),
            get_string('autosurveysettings_desc', 'local_rtocompliance')
        ));

        $surveysettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/autosurveyenable',
            get_string('autosurveyenable', 'local_rtocompliance'),
            get_string('autosurveyenable_desc', 'local_rtocompliance'),
            0
        ));

        $delays = [
            0 => 'Immediately on completion',
            1 => '1 day after completion',
            3 => '3 days after completion',
            7 => '1 week after completion',
            14 => '2 weeks after completion',
        ];
        $surveysettings->add(new admin_setting_configselect(
            'local_rtocompliance/autosurveydelay',
            get_string('autosurveydelay', 'local_rtocompliance'),
            get_string('autosurveydelay_desc', 'local_rtocompliance'),
            1,
            $delays
        ));

        $surveysettings->add(new admin_setting_configtextarea(
            'local_rtocompliance/autosurveyemailsubject',
            get_string('autosurveyemailsubject', 'local_rtocompliance'),
            get_string('autosurveyemailsubject_desc', 'local_rtocompliance'),
            'We value your feedback - Quality Indicator Survey',
            PARAM_TEXT
        ));
    }

    $asqasettings = new admin_settingpage('local_rtocompliance_asqa2025', get_string('asqa2025settings', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $asqasettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $asqasettings->add(new admin_setting_heading(
            'local_rtocompliance/asqa2025heading',
            get_string('asqa2025settings', 'local_rtocompliance'),
            get_string('asqa2025settings_desc', 'local_rtocompliance')
        ));

        $asqasettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/enforcecredentialpolicy',
            get_string('enforcecredentialpolicy', 'local_rtocompliance'),
            get_string('enforcecredentialpolicy_desc', 'local_rtocompliance'),
            1
        ));

        $asqasettings->add(new admin_setting_configtext(
            'local_rtocompliance/currencyexpirydays',
            get_string('currencyexpirydays', 'local_rtocompliance'),
            get_string('currencyexpirydays_desc', 'local_rtocompliance'),
            365,
            PARAM_INT
        ));

        $asqasettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/requiresupervision',
            get_string('requiresupervision', 'local_rtocompliance'),
            get_string('requiresupervision_desc', 'local_rtocompliance'),
            1
        ));

        $asqasettings->add(new admin_setting_configtext(
            'local_rtocompliance/feeprotectionthreshold',
            get_string('feeprotectionthreshold', 'local_rtocompliance'),
            get_string('feeprotectionthreshold_desc', 'local_rtocompliance'),
            1500,
            PARAM_INT
        ));

        $asqasettings->add(new admin_setting_configselect(
            'local_rtocompliance/feeprotectiontype',
            get_string('feeprotectiontype', 'local_rtocompliance'),
            get_string('feeprotectiontype_desc', 'local_rtocompliance'),
            '',
            [
                ''                   => get_string('feeprotectiontype_none', 'local_rtocompliance'),
                'protected_account'  => get_string('feeprotectiontype_protected_account', 'local_rtocompliance'),
                'bank_guarantee'     => get_string('feeprotectiontype_bank_guarantee', 'local_rtocompliance'),
                'tas_arrangement'    => get_string('feeprotectiontype_tas_arrangement', 'local_rtocompliance'),
                'threshold_compliant' => get_string('feeprotectiontype_threshold_compliant', 'local_rtocompliance'),
            ]
        ));

        $asqasettings->add(new admin_setting_configtextarea(
            'local_rtocompliance/feeprotectiondetails',
            get_string('feeprotectiondetails', 'local_rtocompliance'),
            get_string('feeprotectiondetails_desc', 'local_rtocompliance'),
            ''
        ));

        $asqasettings->add(new admin_setting_configcheckbox(
            'local_rtocompliance/enablegovernance',
            get_string('enablegovernance', 'local_rtocompliance'),
            get_string('enablegovernance_desc', 'local_rtocompliance'),
            1
        ));
    }

    $maintsettings = new admin_settingpage('local_rtocompliance_maintenance', get_string('maintenancesettings', 'local_rtocompliance'));
    $ADMIN->add('local_rtocompliance_category', $maintsettings);

    if ($ADMIN->fulltree && isset($settings)) {
        $maintsettings->add(new admin_setting_configtext(
            'local_rtocompliance/log_retentiondays',
            get_string('log_retentiondays', 'local_rtocompliance'),
            get_string('log_retentiondays_desc', 'local_rtocompliance'),
            730,
            PARAM_INT
        ));
    }

    } // end if ($hassiteconfig) — settings pages
} // end if ($canviewfull) — full admin tree registration (v4.2.30 ROLE-SPLIT)
