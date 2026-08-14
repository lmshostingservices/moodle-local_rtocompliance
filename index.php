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
 * RTO Compliance plugin — index.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
// v4.2.39 hotfix: lib.php must be explicitly included so that
// local_rtocompliance_render_nav_header() is available on this page.
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_dashboard');
require_login();
$PAGE->set_title(get_string('dashboard', 'local_rtocompliance'));
$PAGE->set_heading(get_string('pluginname', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

function rtoc_icon($name, $class = '') {
    $icons = [
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'user' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
        'award' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        'shield-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>',
        'file-text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'download' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'badge' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/></svg>',
        'user-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>',
        'clipboard-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg>',
        'book-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'briefcase' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
        'users-round' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 21a8 8 0 0 0-16 0"/><circle cx="10" cy="8" r="5"/><path d="M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3"/></svg>',
        'bar-chart-2' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'clipboard-list' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
        'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 1.9-5.7a8.5 8.5 0 1 1 3.8 3.8z"/></svg>',
        'flag' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>',
        'refresh-cw' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>',
        'building' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
        'network' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/></svg>',
        'repeat' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>',
        'wallet' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
        'shield' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'file-clock' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 22h2a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v3"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><circle cx="8" cy="16" r="6"/><path d="M9.5 17.5 8 16.25V14"/></svg>',
        'alert-triangle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'trending-up' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
        'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
        'rocket' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
        'target' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        'user-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
    ];
    
    $svg = $icons[$name] ?? $icons['file-text'];
    // Inject width/height attributes directly on the SVG element.
    // This ensures correct sizing even when the styles.css [class*="path-local-rtocompliance"]
    // scope selector fails to match (e.g. admin_externalpage_setup() changes the body class).
    // SVG attributes establish intrinsic size; Bootstrap's max-width:100% won't expand them.
    $svg = preg_replace('/<svg /', '<svg width="20" height="20" ', $svg);
    $classes = 'rtoc-icon' . ($class ? ' ' . $class : '');
    return '<span class="' . $classes . '">' . $svg . '</span>';
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
// Open the flex layout wrapper + sidebar for the dashboard.
// render_nav_header() detects the dashboard URL and returns the layout-wrap + sidebar
// + rtoc-main-content opening tags without any breadcrumb header.
echo local_rtocompliance_render_nav_header('');

$rtoname = get_config('local_rtocompliance', 'rtoname');
$rtocode = get_config('local_rtocompliance', 'rtocode');

if (empty($rtoname) || empty($rtocode)) {
    echo $OUTPUT->notification(
        get_string('error_missing_rto', 'local_rtocompliance') . ' ' .
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
            'Configure now',
            ['class' => 'btn btn-primary btn-sm ml-2']
        ),
        'warning'
    );
}

// USI-NOT-CONFIGURED CTA (v4.2.31): show a prominent setup CTA pointing to the
// Upload Machine Credential page when USI is not yet set up. Only shown to site
// admins who can actually act on it.
// USI-STATUS-UNIFY (v5.9.413): use the shared authoritative check so the dashboard
// agrees with the Machine Credential Setup page. The old check required BOTH legacy
// keys (usi_certificate_path AND usi_organization_id), so a per-tenant SaaS RTO —
// whose credential lives on the platform, not in those legacy configs — was wrongly
// told "USI is not set up" on the dashboard even when the setup page said "ready".
if (is_siteadmin()) {
    if (!local_rtocompliance_usi_is_configured()) {
        $usiuploadurl = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out();
        echo '<div style="background:#eff6ff;border:1px solid #3b82f6;border-radius:8px;padding:18px 22px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;">' .
            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>' .
            '<div style="flex:1;">' .
            '<div style="font-weight:600;color:#1e3a8a;font-size:15px;margin-bottom:4px;">USI verification is not yet set up for your RTO</div>' .
            '<div style="color:#1e40af;font-size:13px;line-height:1.5;margin-bottom:10px;">' .
            'Your myGovID Machine Credential and TOID are managed in the <strong>lms-labs.com admin panel</strong> — add them there and student USI lookups will verify against your own RTO automatically. Without this, the Verify USI button on the Students page will fail.' .
            '</div>' .
            '<a href="' . $usiuploadurl . '" class="btn btn-primary btn-sm" style="background:#1d4ed8;color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px;font-weight:600;display:inline-block;">View USI status →</a>' .
            '</div></div>';
    }
}

echo html_writer::start_div('rtocompliance-dashboard');

echo html_writer::start_div('dashboard-header');
echo html_writer::start_div('header-content');
echo html_writer::tag('h2', get_string('welcome_dashboard', 'local_rtocompliance'));
if ($rtoname) {
    echo html_writer::tag('p', $rtoname . ($rtocode ? ' (RTO ' . $rtocode . ')' : ''), ['class' => 'rto-name']);
}
echo html_writer::tag('p', 'Standards for RTOs 2025 Compliant Student Management System', ['class' => 'dashboard-subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();

$metrics = \local_rtocompliance\cache_helper::get_dashboard_metrics();

echo html_writer::start_div('dashboard-main');

// DECLUTTER (v5.9.417): removed the static "Get Started Guide" 7-card row — it
// mirrored the live Setup Progress tracker below exactly (same 7 steps, order and
// destinations), so the dashboard showed the same checklist twice. The live tracker
// (which reflects real completion state) is now the single source of truth, and How
// It Works is one click away in the sidebar.

// ─── Live Setup Progress Tracker ─────────────────────────────────────────────
// Each check queries the actual DB / config so the card reflects real state.
$dbman = $DB->get_manager();

// SETUP-PROGRESS-FULL-JOURNEY (v5.9.383): the tracker now covers the complete
// path from a blank install to the first certificate issued — configure, build,
// MAP units to courses, staff, students, record results, and issue — each check
// queries real DB/config state.
$setup_rtoname   = !empty(get_config('local_rtocompliance', 'rtoname'));
$setup_rtocode   = !empty(get_config('local_rtocompliance', 'rtocode'));
$setup_quals     = $dbman->table_exists('local_rtocompliance_qualbuilder')
                   && $DB->count_records('local_rtocompliance_qualbuilder') > 0;

// Units linked to their Moodle delivery course — the mapping that lets
// completions flow into results (primary link OR any course-map row).
$setup_mapping = false;
if ($dbman->table_exists('local_rtocompliance_qualunits')) {
    $setup_mapping = $DB->record_exists_select('local_rtocompliance_qualunits',
        'courseid IS NOT NULL AND courseid > 0 AND selected = 1');
}
if (!$setup_mapping && $dbman->table_exists('local_rtocompliance_course_map')) {
    $setup_mapping = $DB->count_records('local_rtocompliance_course_map') > 0;
}

$setup_trainers  = $dbman->table_exists('local_rtocompliance_trainers')
                   && $DB->count_records('local_rtocompliance_trainers') > 0;
$setup_students  = $dbman->table_exists('local_rtocompliance_students')
                   && $DB->count_records('local_rtocompliance_students') > 0;
// At least one unit result recorded in the register.
$setup_results   = $dbman->table_exists('local_rtocompliance_enrolments')
                   && $DB->count_records('local_rtocompliance_enrolments') > 0;
// At least one certificate issued — the end of the journey.
$setup_cert      = $dbman->table_exists('local_rtocompliance_certs')
                   && $DB->record_exists_select('local_rtocompliance_certs', "status = 'issued'");

$setup_checks = [
    [
        'done'  => $setup_rtoname && $setup_rtocode,
        'label' => 'Configure RTO details',
        'hint'  => 'Enter your RTO name, code and contact details — they appear on every certificate.',
        'url'   => new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
        'icon'  => 'settings',
    ],
    [
        'done'  => $setup_quals,
        'label' => 'Build a qualification',
        'hint'  => 'Add a qualification or skill set and its units in the Qualification Builder.',
        'url'   => new moodle_url('/local/rtocompliance/qualbuilder.php'),
        'icon'  => 'book-open',
    ],
    [
        'done'  => $setup_mapping,
        'label' => 'Link units to Moodle courses',
        'hint'  => 'Connect each unit to the Moodle course that delivers it, so completions flow into results.',
        'url'   => new moodle_url('/local/rtocompliance/course_map.php'),
        'icon'  => 'network',
    ],
    [
        'done'  => $setup_trainers,
        'label' => 'Register a trainer',
        'hint'  => 'Add trainer credentials and industry currency evidence.',
        'url'   => new moodle_url('/local/rtocompliance/trainers.php'),
        'icon'  => 'user-check',
    ],
    [
        'done'  => $setup_students,
        'label' => 'Add students',
        'hint'  => 'Create student records with USI and AVETMISS data (or import them).',
        'url'   => new moodle_url('/local/rtocompliance/students.php'),
        'icon'  => 'user-plus',
    ],
    [
        'done'  => $setup_results,
        'label' => 'Record unit results',
        'hint'  => 'Sync Moodle completions or import NAT files so unit outcomes appear in Student Results.',
        'url'   => new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'icon'  => 'bar-chart-2',
    ],
    [
        'done'  => $setup_cert,
        'label' => 'Issue a certificate',
        'hint'  => 'Issue a Testamur or Statement of Attainment to a student who has completed their units.',
        'url'   => new moodle_url('/local/rtocompliance/qual_cert_hub.php'),
        'icon'  => 'award',
    ],
];

$setup_total    = count($setup_checks);
$setup_complete = count(array_filter($setup_checks, fn($c) => $c['done']));
$setup_pct      = (int) round(($setup_complete / $setup_total) * 100);
$setup_all_done = $setup_complete === $setup_total;

// Only render the card when at least one step is still incomplete, OR always
// show it so new clients see their progress. Hide via JS once fully done.
echo html_writer::start_div('setup-progress-card' . ($setup_all_done ? ' setup-done' : ''));
echo html_writer::start_div('setup-progress-header');
echo html_writer::start_div('setup-progress-title-row');
echo rtoc_icon($setup_all_done ? 'check-circle' : 'rocket', 'setup-progress-icon');
echo html_writer::start_div('setup-progress-title-wrap');
echo html_writer::tag('h3', $setup_all_done ? 'Setup Complete — You\'re Ready!' : 'Setup Progress', ['class' => 'setup-progress-title']);
echo html_writer::tag('p',
    $setup_all_done
        ? 'All core setup steps are complete. Your compliance system is operational.'
        : $setup_complete . ' of ' . $setup_total . ' setup steps complete',
    ['class' => 'setup-progress-subtitle']
);
echo html_writer::end_div();
echo html_writer::tag('span', $setup_pct . '%', ['class' => 'setup-pct-badge' . ($setup_all_done ? ' pct-done' : '')]);
echo html_writer::end_div();

// Progress bar
echo html_writer::start_div('setup-progress-bar-wrap');
echo html_writer::start_div('setup-progress-bar');
echo html_writer::tag('div', '', [
    'class' => 'setup-progress-fill' . ($setup_all_done ? ' fill-done' : ''),
    'style' => 'width:' . $setup_pct . '%',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // setup-progress-header

// Checklist items
echo html_writer::start_div('setup-checklist');
foreach ($setup_checks as $check) {
    $itemclass = 'setup-check-item' . ($check['done'] ? ' check-done' : ' check-pending');
    echo html_writer::start_div($itemclass);

    // Status icon
    echo html_writer::start_div('check-status-icon');
    if ($check['done']) {
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    } else {
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>';
    }
    echo html_writer::end_div();

    echo html_writer::start_div('check-content');
    echo html_writer::tag('span', $check['label'], ['class' => 'check-label']);
    if (!$check['done']) {
        echo html_writer::tag('span', $check['hint'], ['class' => 'check-hint']);
    }
    echo html_writer::end_div();

    if (!$check['done']) {
        echo html_writer::tag('a', 'Go', [
            'href'  => $check['url'],
            'class' => 'check-action-btn',
        ]);
    }

    echo html_writer::end_div(); // setup-check-item
}
echo html_writer::end_div(); // setup-checklist

echo html_writer::end_div(); // setup-progress-card
// ─────────────────────────────────────────────────────────────────────────────

echo html_writer::start_div('stats-section');
echo html_writer::tag('h3', 'Overview', ['class' => 'section-heading']);
echo html_writer::start_div('stats-cards');

$statItems = [
    ['icon' => 'users', 'value' => $metrics['total_students'], 'label' => get_string('total_students', 'local_rtocompliance'), 'color' => 'blue'],
    ['icon' => 'graduation-cap', 'value' => $metrics['completed_enrolments'], 'label' => get_string('total_completions', 'local_rtocompliance'), 'color' => 'green'],
    ['icon' => 'award', 'value' => $metrics['issued_certs'], 'label' => get_string('certs_issued', 'local_rtocompliance'), 'color' => 'amber'],
    ['icon' => 'user-check', 'value' => $metrics['active_trainers'], 'label' => get_string('trainers_count', 'local_rtocompliance'), 'color' => 'purple'],
];

foreach ($statItems as $stat) {
    echo html_writer::start_div('stat-card stat-' . $stat['color']);
    echo html_writer::start_div('stat-icon-wrap');
    echo rtoc_icon($stat['icon']);
    echo html_writer::end_div();
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $stat['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $stat['label'], ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

$complianceSummary = \local_rtocompliance\cache_helper::get_compliance_summary();

$missingusi       = $metrics['missing_usi'];
$pendingCerts     = $metrics['pending_certs'];
$dbman            = $DB->get_manager();
$now              = time();

// --- QA3 Workforce ---
$expiringTrainers = $DB->count_records_select(
    'local_rtocompliance_trainers',
    'nextreviewdate IS NOT NULL AND nextreviewdate < ? AND nextreviewdate > ? AND status != ?',
    [strtotime('+30 days'), $now, 'expired']
);
$expiredTrainers = $DB->count_records('local_rtocompliance_trainers', ['status' => 'expired']);
$expiredTAE = $DB->count_records_select(
    'local_rtocompliance_trainers',
    'taeexpirydate > 0 AND taeexpirydate < ? AND status != ?',
    [$now, 'expired']
);
$expiredWWCC = $DB->count_records_select(
    'local_rtocompliance_trainers',
    "wwccstatus = 'expired' AND status = 'active'"
);
$pendingSupervision = 0;
if ($dbman->table_exists('local_rtocompliance_supervision')) {
    $pendingSupervision = $DB->count_records_select(
        'local_rtocompliance_supervision',
        'actionsduedate > 0 AND actionsduedate < ?',
        [$now]
    );
}

// --- QA2 Learner Support ---
$failedUSI = $DB->count_records_select(
    'local_rtocompliance_students',
    'usiverified = ?',
    [2]
);
$manualReviewUSI = $DB->count_records_select(
    'local_rtocompliance_students',
    'usiverified = ?',
    [3]
);

// --- QA1 Training & Assessment ---
$overdueValidations = 0;
$overdueTAS = 0;
$pendingTransitions = 0;
$overdueImprovements = 0;
if ($dbman->table_exists('local_rtocompliance_validations')) {
    $overdueValidations = $DB->count_records_select(
        'local_rtocompliance_validations',
        "scheduleddate < ? AND status = 'scheduled'",
        [$now]
    );
}
if ($dbman->table_exists('local_rtocompliance_tas')) {
    $overdueTAS = $DB->count_records_select(
        'local_rtocompliance_tas',
        "nextreviewdate > 0 AND nextreviewdate < ? AND status != 'archived'",
        [$now]
    );
}
if ($dbman->table_exists('local_rtocompliance_transitions')) {
    $pendingTransitions = $DB->count_records_select(
        'local_rtocompliance_transitions',
        "status IN ('identified', 'planning', 'inprogress')"
    );
}
if ($dbman->table_exists('local_rtocompliance_improvements')) {
    $overdueImprovements = $DB->count_records_select(
        'local_rtocompliance_improvements',
        "targetdate > 0 AND targetdate < ? AND status NOT IN ('completed', 'verified', 'closed')",
        [$now]
    );
}

// --- QA4 Governance & Compliance ---
$openComplaints         = 0;
$pendingAppeals         = 0;
$expiringInsurance      = 0;
$expiredInsurance       = 0;
$overdueDeadlines       = 0;
$highRisks              = 0;
$pendingMaterialChanges = 0;
$overdueMaterialChanges = 0;
$adcDue                 = 0;
$expiringThirdParty     = 0;
$expiredGovPersons      = 0;
$activeAIAlerts         = 0;
$overdueCricosEvents    = 0;

if ($dbman->table_exists('local_rtocompliance_complaints')) {
    $openComplaints = $DB->count_records_select(
        'local_rtocompliance_complaints',
        "status IN ('received', 'investigating')"
    );
}
if ($dbman->table_exists('local_rtocompliance_appeals')) {
    $pendingAppeals = $DB->count_records_select(
        'local_rtocompliance_appeals',
        "status IN ('lodged', 'reviewing')"
    );
}
if ($dbman->table_exists('local_rtocompliance_insurance')) {
    $expiredInsurance  = $DB->count_records_select('local_rtocompliance_insurance', 'expirydate > 0 AND expirydate < ?', [$now]);
    $expiringInsurance = $DB->count_records_select('local_rtocompliance_insurance', 'expirydate >= ? AND expirydate < ?', [$now, strtotime('+60 days')]);
}
if ($dbman->table_exists('local_rtocompliance_deadlines')) {
    $overdueDeadlines = $DB->count_records_select(
        'local_rtocompliance_deadlines',
        "duedate < ? AND status = 'pending'",
        [$now]
    );
}
if ($dbman->table_exists('local_rtocompliance_risks')) {
    $highRisks = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_risks} WHERE likelihood * impact >= 16 AND status = 'open'"
    );
}
if ($dbman->table_exists('local_rtocompliance_materialchanges')) {
    $pendingMaterialChanges = $DB->count_records_select(
        'local_rtocompliance_materialchanges',
        "status = 'pending'"
    );
    $overdueMaterialChanges = $DB->count_records_select(
        'local_rtocompliance_materialchanges',
        "status = 'overdue'"
    );
}
if ($dbman->table_exists('local_rtocompliance_adc')) {
    $adcDue = $DB->count_records_select(
        'local_rtocompliance_adc',
        "status IN ('due', 'inprogress')"
    );
}
if ($dbman->table_exists('local_rtocompliance_thirdparty')) {
    $expiringThirdParty = $DB->count_records_select(
        'local_rtocompliance_thirdparty',
        "agreementenddate > ? AND agreementenddate < ? AND status = 'active'",
        [$now, strtotime('+90 days')]
    );
}
if ($dbman->table_exists('local_rtocompliance_govpersons')) {
    $expiredGovPersons = $DB->count_records_select(
        'local_rtocompliance_govpersons',
        "policecheckstatus = 'expired' AND status = 'active'"
    );
}
if ($dbman->table_exists('local_rtocompliance_ai_alerts')) {
    $activeAIAlerts = $DB->count_records_select(
        'local_rtocompliance_ai_alerts',
        "status = 'active' AND severity IN ('critical', 'high')"
    );
}
if ($dbman->table_exists('local_rtocompliance_cricos_events')) {
    $overdueCricosEvents = $DB->count_records_select(
        'local_rtocompliance_cricos_events',
        "status IN ('overdue', 'pending')"
    );
}

// --- Build grouped action items ---
$actionGroups = [];

// Critical — requires immediate attention
$criticalItems = [];
if ($openComplaints > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $openComplaints, 'label' => 'Open Complaints', 'icon' => 'message-circle', 'url' => '/local/rtocompliance/complaints.php'];
}
if ($pendingAppeals > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $pendingAppeals, 'label' => 'Pending Appeals', 'icon' => 'flag', 'url' => '/local/rtocompliance/complaints.php?tab=appeals'];
}
if ($expiredInsurance > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $expiredInsurance, 'label' => 'Insurance Expired', 'icon' => 'shield-off', 'url' => '/local/rtocompliance/insurance.php'];
}
if ($expiredTrainers > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $expiredTrainers, 'label' => 'Trainer Credentials Expired', 'icon' => 'user-x', 'url' => '/local/rtocompliance/trainers.php'];
}
if ($overdueMaterialChanges > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $overdueMaterialChanges, 'label' => 'Overdue ASQA Notifications', 'icon' => 'alert-triangle', 'url' => '/local/rtocompliance/governance.php'];
}
if ($activeAIAlerts > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $activeAIAlerts, 'label' => 'High-Priority AI Alerts', 'icon' => 'zap', 'url' => '/local/rtocompliance/alerts.php'];
}
if ($overdueCricosEvents > 0) {
    $criticalItems[] = ['severity' => 'critical', 'value' => $overdueCricosEvents, 'label' => 'PRISMS Events Pending', 'icon' => 'globe', 'url' => '/local/rtocompliance/governance.php'];
}
if (!empty($criticalItems)) {
    $actionGroups[] = ['heading' => 'Critical — Immediate Action Required', 'headingClass' => 'action-group-critical', 'items' => $criticalItems];
}

// QA1 Training & Assessment
$qa1Items = [];
if ($overdueValidations > 0) {
    $qa1Items[] = ['severity' => 'warning', 'value' => $overdueValidations, 'label' => 'Overdue Validations', 'icon' => 'clipboard-check', 'url' => '/local/rtocompliance/validation.php'];
}
if ($overdueTAS > 0) {
    $qa1Items[] = ['severity' => 'warning', 'value' => $overdueTAS, 'label' => 'TAS Reviews Overdue', 'icon' => 'file-text', 'url' => '/local/rtocompliance/tas.php'];
}
if ($pendingTransitions > 0) {
    $qa1Items[] = ['severity' => 'warning', 'value' => $pendingTransitions, 'label' => 'Training Product Transitions', 'icon' => 'refresh-cw', 'url' => '/local/rtocompliance/transitions.php'];
}
if ($overdueImprovements > 0) {
    $qa1Items[] = ['severity' => 'warning', 'value' => $overdueImprovements, 'label' => 'Overdue Improvement Actions', 'icon' => 'trending-up', 'url' => '/local/rtocompliance/complaints.php?tab=improvement'];
}
if (!empty($qa1Items)) {
    $actionGroups[] = ['heading' => 'QA1 – Training & Assessment', 'headingClass' => 'action-group-qa', 'items' => $qa1Items];
}

// QA2 Learner Support
$qa2Items = [];
if ($missingusi > 0) {
    $qa2Items[] = ['severity' => 'warning', 'value' => $missingusi, 'label' => get_string('missing_usi_title', 'local_rtocompliance'), 'icon' => 'user', 'url' => '/local/rtocompliance/students.php'];
}
if ($failedUSI > 0) {
    $qa2Items[] = ['severity' => 'warning', 'value' => $failedUSI, 'label' => 'USI Verification Failed', 'icon' => 'user-x', 'url' => '/local/rtocompliance/students.php'];
}
if ($manualReviewUSI > 0) {
    $qa2Items[] = ['severity' => 'info', 'value' => $manualReviewUSI, 'label' => 'USI Manual Review Needed', 'icon' => 'user-check', 'url' => '/local/rtocompliance/students.php'];
}
if ($pendingCerts > 0) {
    $qa2Items[] = ['severity' => 'info', 'value' => $pendingCerts, 'label' => get_string('pending_certificates', 'local_rtocompliance'), 'icon' => 'award', 'url' => '/local/rtocompliance/certificates.php'];
}
if (!empty($qa2Items)) {
    $actionGroups[] = ['heading' => 'QA2 – Learner Support', 'headingClass' => 'action-group-qa', 'items' => $qa2Items];
}

// QA3 Workforce
$qa3Items = [];
if ($expiringTrainers > 0) {
    $qa3Items[] = ['severity' => 'warning', 'value' => $expiringTrainers, 'label' => get_string('expiring_trainers', 'local_rtocompliance'), 'icon' => 'user-check', 'url' => '/local/rtocompliance/trainers.php'];
}
if ($expiredTAE > 0) {
    $qa3Items[] = ['severity' => 'warning', 'value' => $expiredTAE, 'label' => 'TAE Credentials Expired', 'icon' => 'graduation-cap', 'url' => '/local/rtocompliance/trainers.php'];
}
if ($expiredWWCC > 0) {
    $qa3Items[] = ['severity' => 'warning', 'value' => $expiredWWCC, 'label' => 'WWCC / Blue Card Expired', 'icon' => 'shield', 'url' => '/local/rtocompliance/trainers.php'];
}
if ($pendingSupervision > 0) {
    $qa3Items[] = ['severity' => 'info', 'value' => $pendingSupervision, 'label' => 'Supervision Actions Overdue', 'icon' => 'eye', 'url' => '/local/rtocompliance/supervision.php'];
}
if (!empty($qa3Items)) {
    $actionGroups[] = ['heading' => 'QA3 – Workforce', 'headingClass' => 'action-group-qa', 'items' => $qa3Items];
}

// QA4 Governance & Compliance
$qa4Items = [];
if ($adcDue > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $adcDue, 'label' => 'Annual Declaration Due', 'icon' => 'file-check', 'url' => '/local/rtocompliance/governance.php'];
}
if ($overdueDeadlines > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $overdueDeadlines, 'label' => 'Overdue Compliance Deadlines', 'icon' => 'clock', 'url' => '/local/rtocompliance/deadlines.php'];
}
if ($highRisks > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $highRisks, 'label' => 'High-Risk Items Open', 'icon' => 'alert-octagon', 'url' => '/local/rtocompliance/risk.php'];
}
if ($expiringInsurance > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $expiringInsurance, 'label' => 'Insurance Expiring (60 days)', 'icon' => 'shield', 'url' => '/local/rtocompliance/insurance.php'];
}
if ($expiringThirdParty > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $expiringThirdParty, 'label' => 'Third-Party Agreements Expiring', 'icon' => 'link', 'url' => '/local/rtocompliance/thirdparty.php'];
}
if ($expiredGovPersons > 0) {
    $qa4Items[] = ['severity' => 'warning', 'value' => $expiredGovPersons, 'label' => 'Police Check Expired', 'icon' => 'badge-alert', 'url' => '/local/rtocompliance/governance.php'];
}
if ($pendingMaterialChanges > 0) {
    $qa4Items[] = ['severity' => 'info', 'value' => $pendingMaterialChanges, 'label' => 'Material Changes Pending', 'icon' => 'bell', 'url' => '/local/rtocompliance/governance.php'];
}
if (!empty($qa4Items)) {
    $actionGroups[] = ['heading' => 'QA4 – Governance & Compliance', 'headingClass' => 'action-group-qa', 'items' => $qa4Items];
}

$hasAlerts = !empty($actionGroups);

if ($hasAlerts) {
    $totalActionCount = array_sum(array_map(fn($g) => count($g['items']), $actionGroups));
    echo html_writer::start_div('alerts-section');
    echo '<div class="alerts-section-header">';
    echo html_writer::tag('h3', 'Action Required', ['class' => 'section-heading alerts-heading']);
    echo '<span class="alerts-total-badge">' . $totalActionCount . ' item' . ($totalActionCount !== 1 ? 's' : '') . '</span>';
    echo '</div>';

    foreach ($actionGroups as $group) {
        echo '<div class="action-group">';
        echo '<div class="action-group-label ' . $group['headingClass'] . '">' . $group['heading'] . '</div>';
        echo '<div class="alert-cards">';
        foreach ($group['items'] as $alert) {
            echo html_writer::start_tag('a', ['href' => new moodle_url($alert['url']), 'class' => 'alert-card alert-' . $alert['severity']]);
            echo html_writer::start_div('alert-icon-wrap');
            echo rtoc_icon($alert['icon']);
            echo html_writer::end_div();
            echo html_writer::start_div('alert-content');
            echo html_writer::tag('span', $alert['value'], ['class' => 'alert-number']);
            echo html_writer::tag('span', $alert['label'], ['class' => 'alert-label']);
            echo html_writer::end_div();
            echo html_writer::tag('span', rtoc_icon('arrow-right'), ['class' => 'alert-arrow']);
            echo html_writer::end_tag('a');
        }
        echo '</div>';
        echo '</div>';
    }

    echo html_writer::end_div();
}

echo html_writer::start_div('modules-section');

// DECLUTTER (v5.9.417): removed the "Quick Access" cards — the three destinations
// (Qualification Builder, Student Records, Certificates) are all in the left sidebar,
// and their stat lines just repeated the Overview tiles above (students, certs
// issued). The live Student-Results product grid below is the useful, non-duplicated
// content and is kept.

echo html_writer::tag('h3', get_string('student_results', 'local_rtocompliance'), ['class' => 'section-heading mt-4']);
echo html_writer::tag('p', get_string('student_results_desc', 'local_rtocompliance'), ['class' => 'section-subtitle text-muted mb-3']);

$qualproducts = $DB->get_records_sql("SELECT qb.id, qb.qualificationcode, qb.qualificationname, qb.producttype,
    (SELECT COUNT(DISTINCT s.id) FROM {local_rtocompliance_students} s 
     JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id 
     WHERE e.programcode = qb.qualificationcode) as studentcount
    FROM {local_rtocompliance_qualbuilder} qb
    WHERE qb.status = 'active'
    ORDER BY qb.qualificationcode", [], 0, 6);

if (!empty($qualproducts)) {
    echo '<div class="rtoc-products-grid">';
    foreach ($qualproducts as $qp) {
        $typeicons = ['qualification' => 'graduation-cap', 'skillset' => 'briefcase', 'singleunit' => 'book-open'];
        $icon = $typeicons[$qp->producttype] ?? 'briefcase';
        echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $qp->id]))->out() . '" class="rtoc-product-card">
            <div class="product-icon">' . rtoc_icon($icon) . '</div>
            <div class="product-info">
                <div class="product-code">' . s($qp->qualificationcode) . '</div>
                <div class="product-name">' . s($qp->qualificationname) . '</div>
            </div>
            <div class="product-count">
                <div class="count-number">' . $qp->studentcount . '</div>
                <div class="count-label">students</div>
            </div>
        </a>';
    }
    echo '</div>';
    
    $totalproducts = $DB->count_records('local_rtocompliance_qualbuilder', ['status' => 'active']);
    if ($totalproducts > 6) {
        echo '<div class="text-center mt-3">';
        echo '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out() . '" class="btn btn-outline-primary btn-sm">View All ' . $totalproducts . ' Products</a>';
        echo '</div>';
    }
} else {
    echo '<div class="empty-state">';
    echo '<p>No active training products yet. <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_edit.php'))->out() . '">Add your first qualification</a> to see student results here.</p>';
    echo '</div>';
}

// DECLUTTER (v5.9.417): removed the two full-width cross-link banners (ASQA Practice
// Guides and Compliance Map) from the dashboard — both destinations are already in
// the left sidebar, so the banners were pure navigation duplication.

if ($dbman->table_exists('local_rtocompliance_deadlines')) {
    $deadlines = $DB->get_records_sql(
        "SELECT id, title, duedate FROM {local_rtocompliance_deadlines} 
         WHERE status = 'pending' AND duedate > ? 
         ORDER BY duedate ASC",
        [time()], 0, 4
    );
} else {
    $deadlines = [];
}

if ($deadlines) {
    echo html_writer::start_div('deadlines-section');
    echo html_writer::start_div('deadlines-header');
    echo html_writer::tag('h3', 'Upcoming Deadlines', ['class' => 'section-heading']);
    echo html_writer::link(new moodle_url('/local/rtocompliance/deadlines.php'), 'View all', ['class' => 'view-all-link']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('deadline-cards');
    foreach ($deadlines as $deadline) {
        $daysuntil = ceil(($deadline->duedate - time()) / 86400);
        $urgency = $daysuntil <= 7 ? 'urgent' : ($daysuntil <= 30 ? 'soon' : 'normal');
        
        echo html_writer::start_div('deadline-card deadline-' . $urgency);
        echo html_writer::start_div('deadline-date');
        echo html_writer::tag('span', date('d', $deadline->duedate), ['class' => 'deadline-day']);
        echo html_writer::tag('span', date('M', $deadline->duedate), ['class' => 'deadline-month']);
        echo html_writer::end_div();
        echo html_writer::start_div('deadline-info');
        echo html_writer::tag('span', format_string($deadline->title), ['class' => 'deadline-title']);
        echo html_writer::tag('span', $daysuntil . ' days remaining', ['class' => 'deadline-remaining']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
