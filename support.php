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
 * RTO Compliance plugin — support.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// SUPPORT-DIAG-REMOVED (v5.9.415): the v5.9.50 step-by-step breadcrumb block that
// wrote a /tmp file, spammed error_log, registered a shutdown handler and wrote the
// Moodle config table four times on EVERY Help-page load has been removed — the past
// HTTP 500 it was chasing is long resolved and the diagnostics were pure overhead.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_supportinternal');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$PAGE->set_title(get_string('support_docs', 'local_rtocompliance'));
$PAGE->set_heading(get_string('pluginname', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

// FIX-SUPPORT-ICON-REDECLARE (v5.9.50): guard against "Cannot redeclare function support_icon"
// if this file is somehow loaded twice under different path contexts (same root cause as
// FIX-SOA-ISSUE-500 — PSR-4 autoloader + explicit require path conflict).
if (!function_exists('support_icon')) {
function support_icon($name, $class = '') {
    $icons = [
        'layout-dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
        'user-check' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'eye' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
        'award' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
        'database' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>',
        'bar-chart' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 21 1.9-5.7a8.5 8.5 0 1 1 3.8 3.8z"/></svg>',
        'handshake' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg>',
        'building' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>',
        'wallet' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>',
        'shield' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>',
        'clipboard-list' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
        'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
        'arrow-left' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        'search' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'book-open' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        'chevron-down' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
        'chevron-right' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
        'help-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>',
        'lightbulb' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg>',
        'rocket' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
        'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
        'play-circle' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
        'settings' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'user-plus' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
        'file-text' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'target' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        'star' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    ];
    
    $svg = $icons[$name] ?? $icons['help-circle'];
    $svg = preg_replace('/<svg /', '<svg width="20" height="20" ', $svg);
    $classes = 'support-icon' . ($class ? ' ' . $class : '');
    return '<span class="' . $classes . '">' . $svg . '</span>';
}
} // end if (!function_exists('support_icon'))

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('support_internal', 'local_rtocompliance'));

// Prominent link to the quick-answer FAQ.
$faqurl = (new moodle_url('/local/rtocompliance/faq.php'))->out();
echo '<a href="' . $faqurl . '" style="display:flex;align-items:center;gap:14px;text-decoration:none;'
    . 'background:linear-gradient(135deg,#eef2ff,#faf5ff);border:1px solid #e0e7ff;border-radius:12px;'
    . 'padding:16px 20px;margin-bottom:20px;">'
    . '<span style="flex:0 0 auto;width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#4f46e5,#7c3aed);'
    . 'color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 14px -4px rgba(79,70,229,.5);">'
    . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 10h8M8 14h5"/><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>'
    . '<span style="flex:1;"><span style="display:block;font-size:16px;font-weight:800;color:#3730a3;">Looking for a quick answer? Read the FAQ</span>'
    . '<span style="display:block;font-size:13.5px;color:#475569;margin-top:2px;">100 plain-English questions across 20 topics — how to link courses, import NAT files, issue an SoA, and more.</span></span>'
    . '<span style="flex:0 0 auto;color:#6366f1;font-weight:700;font-size:14px;">Open FAQ &rarr;</span>'
    . '</a>';

$rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';

echo html_writer::start_div('support-container');

echo html_writer::start_div('support-header');
echo html_writer::tag('h1', support_icon('book-open', 'header-icon') . ' Support Centre', ['class' => 'support-title']);
echo html_writer::tag('p', 'Help and compliance guides for ' . s($rtoname), ['class' => 'support-subtitle']);
echo html_writer::end_div();

echo html_writer::start_div('support-intro');
echo html_writer::tag('p', 
    "Welcome to the RTO Compliance Support Centre. Whether you're new to the Standards for RTOs 2025 " .
    "or preparing for an ASQA audit, these guides explain the <strong>what</strong>, <strong>why</strong>, " .
    "and <strong>how</strong> of each compliance area in plain English.", 
    ['class' => 'intro-text']
);
echo html_writer::start_div('intro-badges');
echo html_writer::tag('span', 'ASQA 2025 Aligned', ['class' => 'intro-badge badge-rose']);
echo html_writer::tag('span', 'Beginner Friendly', ['class' => 'intro-badge badge-sky']);
echo html_writer::tag('span', 'Step-by-Step Guides', ['class' => 'intro-badge badge-green']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('get-started-section');
echo html_writer::start_div('get-started-header');
echo html_writer::start_div('get-started-icon-wrap');
echo support_icon('rocket', 'get-started-icon');
echo html_writer::end_div();
echo html_writer::start_div('get-started-title-wrap');
echo html_writer::tag('h2', 'Get Started Guide', ['class' => 'get-started-title']);
echo html_writer::tag('p', 'New to RTO Compliance? Follow these 6 simple steps to set up your system.', ['class' => 'get-started-subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('get-started-steps');

$getStartedSteps = [
    [
        'number' => '1',
        'icon' => 'settings',
        'title' => 'Configure Your RTO Details',
        'description' => 'Enter your RTO name, code, and contact details in the plugin settings. This information appears on certificates and reports.',
        'link' => new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
        'link_text' => 'Open Settings',
        'color' => 'sky',
    ],
    [
        'number' => '2',
        'icon' => 'book-open',
        'title' => 'Build Your Qualifications',
        'description' => 'Create qualifications, skill sets, or single unit courses. Use the training.gov.au integration to auto-fetch units and packaging rules.',
        'link' => new moodle_url('/local/rtocompliance/qualbuilder.php'),
        'link_text' => 'Open Qualification Builder',
        'color' => 'purple',
    ],
    [
        'number' => '3',
        'icon' => 'user-plus',
        'title' => 'Add Your Students',
        'description' => 'Create student records with USI and AVETMISS data, or let them populate from a NAT import. Click any student\'s name to open their full profile — AVETMISS summary, completeness check, unit results and issued certificates. The plugin only reads your Moodle enrolments; it never creates accounts or enrols anyone.',
        'link' => new moodle_url('/local/rtocompliance/students.php'),
        'link_text' => 'Open Student Records',
        'color' => 'blue',
    ],
    [
        'number' => '4',
        'icon' => 'user-check',
        'title' => 'Register Your Trainers',
        'description' => 'Add your trainers and assessors with their TAE qualifications, vocational competency evidence, and industry currency records.',
        'link' => new moodle_url('/local/rtocompliance/trainers.php'),
        'link_text' => 'Open Trainer Register',
        'color' => 'amber',
    ],
    [
        'number' => '5',
        'icon' => 'target',
        'title' => 'Track Student Results',
        'description' => 'The Master Roster shows every student across every qualification in one table, with search and filters. Use "Sync results from Moodle completions" to pull in course completions, then drill into any qualification for the unit-by-unit grid.',
        'link' => new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'link_text' => 'Open Student Results',
        'color' => 'emerald',
    ],
    [
        'number' => '6',
        'icon' => 'award',
        'title' => 'Issue Certificates',
        'description' => 'Generate AQF-compliant testamurs and statements of attainment. Certificates are automatically added to your 30-year register.',
        'link' => new moodle_url('/local/rtocompliance/certificates.php'),
        'link_text' => 'Open Certificates',
        'color' => 'green',
    ],
];

foreach ($getStartedSteps as $step) {
    echo html_writer::start_div('get-started-step step-' . $step['color']);
    
    echo html_writer::start_div('step-number-wrap number-' . $step['color']);
    echo html_writer::tag('span', $step['number'], ['class' => 'step-number']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('step-content');
    echo html_writer::start_div('step-header');
    echo html_writer::start_div('step-icon-small icon-' . $step['color']);
    echo support_icon($step['icon']);
    echo html_writer::end_div();
    echo html_writer::tag('h3', $step['title'], ['class' => 'step-title']);
    echo html_writer::end_div();
    echo html_writer::tag('p', $step['description'], ['class' => 'step-description']);
    echo html_writer::tag('a', $step['link_text'] . ' ' . support_icon('arrow-right'), [
        'href' => $step['link'],
        'class' => 'step-link link-' . $step['color']
    ]);
    echo html_writer::end_div();
    
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo html_writer::start_div('get-started-tip');
echo support_icon('lightbulb', 'tip-icon');
echo html_writer::tag('p',
    '<strong>Pro Tip:</strong> New here? Open <a href="' . (new moodle_url('/local/rtocompliance/how_it_works.php'))->out() . '"><strong>How It Works</strong></a> (top of the left menu) for a plain-English overview of the whole system first. ' .
    'Then start with steps 1-3 to get your basic setup running — you can add trainers and configure advanced features as you go.',
    ['class' => 'tip-text']
);
echo html_writer::end_div();

echo html_writer::end_div();

// What's New panel — updated each audit/release cycle
echo html_writer::start_div('whats-new-panel');
echo html_writer::start_div('whats-new-header');
echo support_icon('star', 'whats-new-icon');
echo html_writer::tag('h2', "What's New — v5.9.425", ['class' => 'whats-new-title']);
echo html_writer::end_div();
echo html_writer::tag('p',
    'The latest releases (v5.9.420–425) sharpen the compliance depth and make the whole system easier to learn. '
    . 'Highlights: every page now opens with a consistent <strong>What&nbsp;/&nbsp;Why&nbsp;/&nbsp;How</strong> orientation card; '
    . '<strong>nominal hours</strong> are wired end-to-end (a real qualification total in the Qualification Builder and the TAS volume of learning, '
    . 'sourced from the plugin&rsquo;s own authoritative reference table because training.gov.au does not publish them); '
    . '<strong>RPL &amp; Credit Transfer</strong> now capture the assessor&rsquo;s identity with a live TAE-currency check, whether the student was told the outcome, '
    . 'superseded&rarr;current unit mapping, and an evidence-to-criteria matrix; a per-student <strong>pre-enrolment readiness</strong> panel and a Compliance Health metric '
    . 'surface the four pre-enrolment gates; and a declutter pass removed duplicated on-page noise. '
    . 'These build on the v5.9.399 integrity foundation — the plugin still creates and deletes <strong>nothing</strong> in Moodle core; it only reads it. '
    . 'The full list is below.',
    ['class' => 'whats-new-intro']
);
echo html_writer::start_tag('ul', ['class' => 'whats-new-list']);
$whatsNew = [
    '<strong>Guided help on every page (v5.9.425)</strong> — '
    . 'Every page now opens with a consistent, tabbed &ldquo;What it is / Why it matters / How to use it&rdquo; card directly under the breadcrumb, so a first-time user always gets the same clear orientation: what the page does, the compliance it supports, and the steps to use it.',
    '<strong>RPL evidence rigour (v5.9.424)</strong> — '
    . 'RPL &amp; Credit Transfer records now capture the superseded/prior unit a student holds and its TGA equivalence (Equivalent / Not equivalent) to the current unit — with an advisory that a not-equivalent mapping needs gap assessment — plus an evidence-to-criteria matrix mapping each item of evidence to the unit requirement it satisfies with an assessor judgement (rules of evidence, Standard 1.2).',
    '<strong>Pre-enrolment readiness (v5.9.423)</strong> — '
    . 'Each student profile shows a four-gate readiness card (suitability assessed, student declaration signed, USI verified, information provided), and Compliance Health flags students who have results but no completed suitability review. It reuses your existing suitability and USI data and never touches Moodle enrolments.',
    '<strong>RPL assessor identity &amp; procedural fairness (v5.9.422)</strong> — '
    . 'RPL/CT decisions are now tied to a registered assessor chosen from your trainer workforce, with a live green/amber check on their TAE currency (Standard 1.5), and record whether the outcome was communicated to the student, when and how (Standard 1.6). The register gained a &ldquo;Student Notified&rdquo; column.',
    '<strong>Nominal hours wired end-to-end (v5.9.421)</strong> — '
    . 'The Qualification Builder now rolls a real qualification nominal-hours total up from the plugin&rsquo;s own authoritative reference table (training.gov.au does not publish nominal hours), flags units still missing a value, and the TAS &ldquo;Total Nominal Hours&rdquo; and volume-of-learning pre-fill from that total.',
    '<strong>Page declutter (v5.9.420)</strong> — '
    . 'Removed duplicated, no-compliance-purpose noise: Student Results trimmed to the four meaningful summary tiles, the triple &ldquo;no Moodle accounts created&rdquo; assurance on Data Import collapsed to one, and the Marketing &ldquo;Related pages&rdquo; card removed.',
    '<strong>Compliance Health (v5.9.399)</strong> — '
    . 'A live &ldquo;are we audit-ready right now?&rdquo; command centre — first item in the left menu. It shows an overall audit-readiness score plus Quality-Area cards for overdue validations, working-towards trainer deadlines, stale trainer currency, students with results but an unverified USI (certificates you can\'t yet issue), incomplete AVETMISS profiles, survey response rate, and open complaints/appeals — each with a one-click fix link.',
    '<strong>AVETMISS Validation (v5.9.399)</strong> — '
    . 'A pre-submission checker (Data &amp; Reporting) that validates every student and enrolment against NCVER edit rules <em>before</em> you export NAT files, so your quarterly submission isn\'t rejected. It splits findings into Errors (will fail) and Warnings, gives a &ldquo;Ready to submit&rdquo; verdict, and exports the full list to CSV.',
    '<strong>ASQA Compliance Mapping (v5.9.399)</strong> — '
    . 'Maps every 2025 Standard for RTOs (QA1–QA4) to the plugin feature that supports it, with an honest Covered / Partial / Gap status. Doubles as Standard 4.3/4.4 self-assurance evidence.',
    '<strong>World-class student profile (v5.9.399)</strong> — '
    . 'Student Records now has clickable names. Each profile opens with a read-only AVETMISS summary (all codes shown as human-readable labels), a completeness indicator that lists missing fields, the student\'s unit results, and their issued certificates with one-click download.',
    '<strong>Student certificate access (v5.9.399)</strong> — '
    . 'Students get a persistent &ldquo;My Certificates&rdquo; link in their navigation and a companion &ldquo;My Certificates&rdquo; dashboard block (installed separately), plus the profile-page link. The portal (mycerts.php) lets them download their own certificate PDFs.',
    '<strong>Automated weekly compliance alerts (v5.9.399)</strong> — '
    . 'A scheduled task emails administrators a digest when validations, working-towards deadlines, USI-blocked certificates, 30-day certificate breaches, or incomplete profiles need attention. It sends only when action is actually needed.',
    '<strong>No writes to Moodle core (v5.9.399)</strong> — '
    . 'Following a full ASQA practice-guide audit, the plugin now creates and deletes nothing in Moodle core — no enrolments, no course completions, no user accounts. It only reads them. NAT imports flow into the results register and student profiles without touching Moodle enrolments.',
    '<strong>Certificate integrity safeguards (v5.9.399)</strong> — '
    . 'Certificates now require a USI that is <em>verified</em> with the Registry (not merely present) before issuing, and issuance is blocked if mandatory RTO details (legal name, provider code, signatory) aren\'t configured. Each certificate snapshots the RTO identity and USI as-issued, so a later settings change can\'t rewrite an old certificate; reissues are faithful copies; and a render-time backstop enforces AQF rules (NRT logo never on a Record of Results, USI never on a Testamur/SoA).',
    '<strong>Validation, workforce &amp; QI hardening (v5.9.399)</strong> — '
    . 'Assessment validation now records validator independence and a five-year cycle with overdue tracking; &ldquo;working towards&rdquo; trainers have an enforced 2-year TAE deadline; the Quality Indicator surveys are now the official AQTF Learner/Employer Questionnaires with a proper QI Annual Summary; per-student support/wellbeing records are stored server-side; and complaints, appeals and improvements are audit-logged.',
    '<strong>Full NAT demographic + address propagation (v5.9.399)</strong> — '
    . 'After a NAT import, the student\'s AVETMISS demographics AND full street address now flow into their existing profile automatically. The manual &ldquo;Backfill Student Records&rdquo; step carries the full profile too.',
    '<strong>How It Works page (v5.9.378)</strong> — '
    . 'A new plain-English overview page, first item in the left menu, explaining the whole system for newcomers.',
    '<strong>Master Student Roster (v5.9.373)</strong> — '
    . 'Student Results now opens on a cross-qualification roster: every student in one table with search, qualification/category/status/USI filters, summary pivots, CSV export, and a drill-down to each qualification\'s unit-by-unit grid.',
    '<strong>Sync results from Moodle completions (v5.9.374)</strong> — '
    . 'A button on Student Results reads Moodle course completions across every delivery course (any category, including archived and semester-copy courses) and records the competent outcomes in the results register. A "Download unmapped completions" CSV lists any courses it could not match to a unit. Writes only to the plugin register — never creates Moodle accounts, enrolments or completions.',
    '<strong>Multi-unit courses &amp; old-code equivalents (v5.9.377)</strong> — '
    . 'A course that teaches more than one unit (e.g. a title listing two unit codes) now credits every unit it delivers. A new setting maps retired unit codes to their current equivalent so completions in older-coded courses still count.',
    '<strong>Data Import simplified (v5.9.372)</strong> — '
    . 'The import is now data-only: it populates student profiles and writes unit outcomes to the results register, and sends unmatched students to a review CSV. The old auto-enrol wizard and the Backfill / Fix-Over-Enrolments / Rollback tools have been removed — import never creates Moodle accounts or enrolments.',
];
foreach ($whatsNew as $item) {
    echo html_writer::tag('li', $item);
}
echo html_writer::end_tag('ul');
echo html_writer::end_div();

$supportModules = [

    // ── COMPLIANCE HEALTH ──────────────────────────────────────────────────
    [
        'anchor'      => 'compliancehealth',
        'icon'        => 'shield',
        'color'       => 'rose',
        'title'       => 'Compliance Health',
        'subtitle'    => 'Are we audit-ready right now?',
        'description' => "The first item in the left menu and your live audit-readiness command centre. It shows an overall readiness score plus a card for each Quality Area, surfacing overdue validations, working-towards trainer deadlines, stale trainer currency, students who have results but an unverified USI (certificates you can't yet issue), incomplete AVETMISS profiles, your survey response rate, and open complaints/appeals. Every item has a one-click fix link so you can act immediately.",
        'clause_ref'  => 'Standards QA4.3–QA4.4',
        'clause_text' => 'RTOs must self-assure against the Standards and use monitoring information to identify and address non-compliance before it affects learners',
        'url'         => new moodle_url('/local/rtocompliance/compliance_health.php'),
        'link_text'   => 'Open Compliance Health',
        'how_to'      => [
            'Open Compliance Health — it is the first item at the top of the left menu',
            'Read the audit-readiness score at the top for an at-a-glance verdict',
            'Work down the Quality-Area cards — each lists exactly what is overdue or blocking',
            'Click any item\'s fix link to jump straight to the record that needs attention',
            'Re-open the page after resolving items to watch the score climb',
        ],
    ],

    // ── ASQA COMPLIANCE MAPPING ────────────────────────────────────────────
    [
        'anchor'      => 'asqamap',
        'icon'        => 'check-circle',
        'color'       => 'teal',
        'title'       => 'ASQA Compliance Mapping',
        'subtitle'    => 'Every 2025 Standard mapped to a feature',
        'description' => "Maps every 2025 Standard for RTOs (QA1–QA4) to the plugin feature that supports it, with an honest Covered / Partial / Gap status for each. It shows at a glance where you are protected and where you still need to do work outside the system — and doubles as Standard 4.3/4.4 self-assurance evidence you can show an auditor.",
        'clause_ref'  => 'Standards QA1–QA4 (self-assurance 4.3/4.4)',
        'clause_text' => 'RTOs must be able to demonstrate how they meet each Standard and maintain evidence of ongoing self-assurance',
        'url'         => new moodle_url('/local/rtocompliance/asqa_standards_map.php'),
        'link_text'   => 'Open ASQA Compliance Mapping',
        'how_to'      => [
            'Open ASQA Compliance Mapping from the top group of the left menu',
            'Scan the QA1–QA4 rows to see which plugin feature covers each Standard',
            'Note any row marked Partial or Gap — these are the areas to address manually',
            'Use the page as self-assurance evidence in your next ASQA engagement',
        ],
    ],

    // ── DASHBOARD ──────────────────────────────────────────────────────────
    [
        'anchor'      => 'dashboard',
        'icon'        => 'layout-dashboard',
        'color'       => 'sky',
        'title'       => 'Compliance Dashboard',
        'subtitle'    => 'Your compliance command centre',
        'description' => "The dashboard gives you a real-time view of your RTO's compliance health across all four QA areas. Colour-coded action groups highlight overdue items so you know exactly what needs attention before ASQA comes knocking.",
        'clause_ref'  => 'Standard QA4.2',
        'clause_text' => 'RTOs must maintain a quality management system that drives continuous improvement and monitors compliance across all standards',
        'url'         => new moodle_url('/local/rtocompliance/index.php'),
        'link_text'   => 'Open Dashboard',
    ],

    // ── QA1 – TRAINING & ASSESSMENT ────────────────────────────────────────
    [
        'anchor'      => 'tas',
        'icon'        => 'clipboard-list',
        'color'       => 'amber',
        'title'       => 'TAS Generator',
        'subtitle'    => 'Training & Assessment Strategy',
        'description' => "Create compliant Training and Assessment Strategies covering all 9 mandated sections — industry engagement, volume of learning, trainer credentials, assessment design, and more. Export to HTML or PDF as audit evidence.",
        'clause_ref'  => 'Standards QA1.1–QA1.4',
        'clause_text' => 'RTOs must develop and implement a documented training and assessment strategy for each qualification and skill set, incorporating industry engagement and volume of learning requirements',
        'url'         => new moodle_url('/local/rtocompliance/tas.php'),
        'link_text'   => 'Open TAS Generator',
        'how_to'      => [
            'Navigate to TAS Generator in the left sidebar',
            'Click "Create New TAS"',
            'Select the qualification — units are auto-populated from training.gov.au',
            'Total Nominal Hours and Volume of Learning pre-fill from the qualification\'s authoritative nominal-hours total (you can refine against AQF expectations)',
            'Complete each of the 9 sections (the progress bar shows completion)',
            'Use the AI Generate buttons to draft industry consultation feedback and impact statements',
            'Click "Preview" to review the full document before export',
            'Click "Export HTML" or print to PDF for your audit evidence folder',
        ],
    ],
    [
        'anchor'      => 'validation',
        'icon'        => 'check-circle',
        'color'       => 'blue',
        'title'       => 'Validation Schedule',
        'subtitle'    => 'Assessment validation register',
        'description' => "Plan and track all assessment validation activities. Ensures every training product in scope is validated within a 5-year cycle using risk-based prioritisation. Flags overdue validations on the dashboard.",
        'clause_ref'  => 'Standard QA1.5',
        'clause_text' => 'RTOs must systematically validate assessment practices and tools within a 5-year cycle using risk-based prioritisation',
        'url'         => new moodle_url('/local/rtocompliance/validation.php'),
        'link_text'   => 'Open Validation Schedule',
        'how_to'      => [
            'Open the Validation Schedule from the QA1 sidebar group',
            'Click "Add Validation" to schedule a new validation activity',
            'Select the qualification and assessment tool being validated',
            'Set the due date (risk-based: high-risk products should be validated more frequently)',
            'Record the validation panel members and outcome',
            'Any overdue validations appear as warnings on the compliance dashboard',
        ],
    ],
    [
        'anchor'      => 'rpl',
        'icon'        => 'rpl',
        'color'       => 'teal',
        'title'       => 'RPL & Credit Transfer',
        'subtitle'    => 'Recognition of prior learning register',
        'description' => "Record and track Recognition of Prior Learning (RPL) applications and Credit Transfer (CT) grants with a full evidence trail. Capture the assessor (from your trainer workforce, with a live TAE-currency check), upload RPL evidence and the source certificate for CT, map each item of evidence to the unit criteria it satisfies, record the superseded→current unit mapping and its TGA equivalence, and note that the outcome was communicated to the student. An approved decision posts the competent outcome (RPL 51 / CT 60) straight into Student Results, certificates and AVETMISS.",
        'clause_ref'  => 'Standard 1.5–1.7',
        'clause_text' => 'RTOs must have fair, flexible, valid and consistent processes for recognising the current skills and knowledge of applicants, including RPL and credit transfer, delivered by competent assessors.',
        'url'         => new moodle_url('/local/rtocompliance/rpl.php'),
        'link_text'   => 'Open RPL & Credit Transfer',
        'how_to'      => [
            'Open RPL & Credit Transfer from the QA1 sidebar group',
            'Record an application — select the student, qualification and unit; choose the assessor from your registered trainers (the form checks their TAE currency)',
            'Attach the evidence files and complete the evidence-to-criteria matrix (each evidence item → the unit requirement it meets + judgement)',
            'For a superseded unit, enter the prior code and its TGA equivalence; a "not equivalent" mapping needs gap assessment',
            'For Credit Transfer, upload the issuing RTO source certificate and verify the USI transcript; record the decision and tick "outcome communicated"',
            'Approved RPL/CT posts the competent outcome into Student Results and flows to certificates and NAT',
        ],
    ],
    [
        'anchor'      => 'locations',
        'icon'        => 'building-2',
        'color'       => 'sky',
        'title'       => 'Delivery Locations',
        'subtitle'    => 'Training environment register',
        'description' => "Maintain a register of all locations where training and assessment is delivered — physical campuses, workplaces, online environments, and third-party venues. ASQA requires evidence that every delivery location is suitable and properly resourced.",
        'clause_ref'  => 'Standard QA1.8',
        'clause_text' => 'RTOs must ensure training and assessment is delivered in environments that are safe and appropriate for the training product and learner cohort',
        'url'         => new moodle_url('/local/rtocompliance/locations.php'),
        'link_text'   => 'Open Delivery Locations',
        'how_to'      => [
            'Open Delivery Locations from the QA1 sidebar group',
            'Click "Add Location" to register a new site',
            'Enter the location name, address, type (campus / workplace / online), and capacity',
            'Record the WHS compliance status and last inspection date',
            'Link qualifications to the locations where they are delivered',
        ],
    ],
    [
        'anchor'      => 'transitions',
        'icon'        => 'refresh',
        'color'       => 'amber',
        'title'       => 'Training Product Transitions',
        'subtitle'    => 'Superseded qualification teach-out',
        'description' => "Track qualifications and units that have been superseded or deleted from training.gov.au. Manage student transition planning, teach-out periods, and enrolment controls. Can automatically close Moodle course self-enrolment when a product enters teach-out.",
        'clause_ref'  => 'Standard QA1.5',
        'clause_text' => 'RTOs must ensure students are transitioned to current training products within teach-out periods and cannot be enrolled in superseded qualifications after transition',
        'url'         => new moodle_url('/local/rtocompliance/transitions.php'),
        'link_text'   => 'Open Training Transitions',
        'how_to'      => [
            'Open Training Transitions from the QA1 sidebar group',
            'Click "Add Transition Plan" for any superseded qualification',
            'Set the teach-out end date and link the Moodle course',
            'Enable "Close enrolments on teach-out date" to auto-disable self-enrolment',
            'Record the transition strategy for students still enrolled',
            'The dashboard will flag overdue transition plans as compliance actions',
        ],
    ],
    [
        'anchor'      => 'qualbuilder',
        'icon'        => 'book-open',
        'color'       => 'purple',
        'title'       => 'Qualification Builder',
        'subtitle'    => 'Qualifications, skill sets &amp; course variants',
        'description' => "The Qualification Builder is the central blueprint for every qualification and skill set your RTO delivers. " .
                         "It integrates with the training.gov.au SOAP API to auto-fetch TGA unit data, links each unit to its Moodle delivery course, " .
                         "and drives the enrolment reconciler and autocert engine. " .
                         "Each QB record is a blueprint: select a semester, choose your units, link each unit to a primary Moodle course, " .
                         "and optionally attach teacher-cohort variant courses (e.g. EL, CD, ND delivery streams of the same unit). " .
                         "When a student completes all units — in any linked course — their AVETMISS enrolment record is created and " .
                         "their Testamur is issued automatically. Use the Stream / Variant Name field to distinguish multiple QB records " .
                         "for the same qualification code (e.g. evening intake vs day class).",
        'clause_ref'  => 'Standard QA1.1',
        'clause_text' => 'RTOs must develop and implement training and assessment strategies for each qualification and skill set on their scope of registration',
        'url'         => new moodle_url('/local/rtocompliance/qualbuilder.php'),
        'link_text'   => 'Open Qualification Builder',
        'how_to'      => [
            'Navigate to Qualification Builder from the Data &amp; Reports sidebar group and click "Add Qualification"',
            'Enter the qualification code (e.g. ABC12345) and click "Fetch from training.gov.au" — core units are added automatically and electives are presented for selection',
            'Select your semester from the dropdown — the unit-course mapping panel then shows all Moodle courses in that semester',
            'Click "Map All Courses" to auto-link each unit to its matching Moodle course based on the unit code in the course name',
            'Review the auto-detected teacher-cohort variant chips on each unit row (e.g. [✓ EL] [CD ×] [ND ×]) — remove any streams that should not be tracked',
            'Use the [+ add variant…] dropdown on any unit to add a course that was not auto-detected',
            'Nominal hours resolve automatically from the plugin\'s authoritative reference table (training.gov.au does not publish them) and roll up to a qualification total; the compliance card flags any unit still missing a value. They flow into your AVETMISS NAT00120 export and the TAS volume of learning',
            'Optionally fill in the Stream / Variant Name field to distinguish this QB record from others with the same qualification code',
            'Click "Validate Packaging" to confirm the unit mix meets training package rules (core count, elective count, prerequisites)',
            'Click "Save" — the reconciler is now watching all linked and variant courses; AVETMISS records and certificates are created automatically',
            'After creating or importing products, click "Build Course Map from Links" at the top of the Qualification Builder to populate the Course Map from the courses already linked to each unit — this is what turns the Course Map column from "None"/partial into "All" so completion detection and automatic certificate/SoA issuance can find completers. It only adds missing mappings, changes nothing else, and is safe to run repeatedly',
        ],
    ],
    [
        'anchor'      => 'qualresults',
        'icon'        => 'bar-chart',
        'color'       => 'emerald',
        'title'       => 'Student Results',
        'subtitle'    => 'Unit-by-unit competency tracking &amp; autocert',
        'description' => "The main table your RTO looks at. It opens on the Master Roster — every student across every qualification in one place, with search (name, email, USI, client ID) and filters by qualification, category, status and USI health, plus summary pivots and a full-field CSV export. " .
                         "Click any student to open their qualification's unit-by-unit grid (C / NYC / RPL / CT). Results come from both live Moodle completions and imported history. " .
                         "Use \"Sync results from Moodle completions\" to pull in completions across every delivery course (any category, including archived/semester-copy courses), and \"Download unmapped completions\" to see any courses that could not be matched to a unit. " .
                         "When all of a qualification's units are Competent (C, RPL, or CT), the system automatically queues a Testamur — no manual trigger required. The 30-day issuance clock starts from the final unit completion date.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'RTOs must issue AQF qualifications and statements of attainment only to learners who have been assessed as meeting all requirements',
        'url'         => new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'link_text'   => 'Open Student Results',
        'how_to'      => [
            'Open Student Results from the sidebar — you land on the Master Roster showing every student across all qualifications',
            'Search by name, email, USI or client ID, and filter by qualification, category, status or USI health; sort and export the whole roster to CSV',
            'Click "Sync results from Moodle completions" to record competent outcomes from Moodle course completions (safe to run repeatedly; writes only to the plugin register)',
            'Click "Download unmapped completions" to get a CSV of courses the sync could not match to a unit — link or rename those, then sync again',
            'Click a student\'s "Units" (or open a qualification) to see the unit-by-unit grid: C = Competent (20), NYC = Not Yet Competent (30), RPL = Prior Learning (51/52), CT = Credit Transfer (60)',
            'Students with all units complete can be issued a Testamur automatically (if autocert is on) or manually via the "Issue Certificate" button',
        ],
    ],

    // ── QA2 – STUDENT SUPPORT ──────────────────────────────────────────────
    [
        'anchor'      => 'marketing',
        'icon'        => 'info',
        'color'       => 'blue',
        'title'       => 'Marketing Information',
        'subtitle'    => 'Standard 2.1 compliance register',
        'description' => "Maintain a register of marketing review schedules, approval records, and marketing materials to demonstrate that all advertising and promotional content is accurate, honest, and not misleading — as required by Standard 2.1.",
        'clause_ref'  => 'Standard QA2.1',
        'clause_text' => 'RTOs must provide accurate and accessible information to prospective and current students about its training products, services, fees, and requirements',
        'url'         => new moodle_url('/local/rtocompliance/marketing_info.php'),
        'link_text'   => 'Open Marketing Information',
        'how_to'      => [
            'Open Marketing Information from the QA2 sidebar group',
            'Review the Standard 2.1 Information Cards checklist for required disclosures',
            'Record each marketing asset (website, brochure, social) and its last review date',
            'Note the approver and any corrections made',
            'This register is your evidence that marketing content has been reviewed for accuracy',
        ],
    ],
    [
        'anchor'      => 'students',
        'icon'        => 'users',
        'color'       => 'blue',
        'title'       => 'Student Records',
        'subtitle'    => 'AVETMISS student profiles',
        'description' => "Manage student records with complete AVETMISS data fields. Track USI verification status, personal details, disabilities and support needs, and enrolment history for all nationally recognised training. Student names in the list are now clickable: each opens a world-class profile with a read-only AVETMISS summary (codes shown as plain-English labels), a completeness indicator that lists any missing fields, the student's unit results, and their issued certificates with one-click download. The plugin only reads Moodle enrolments — it never creates accounts or enrols anyone.",
        'clause_ref'  => 'Compliance Standard 7',
        'clause_text' => 'RTOs must accurately collect and report training activity data in accordance with the AVETMISS standard for all nationally recognised training',
        'url'         => new moodle_url('/local/rtocompliance/students.php'),
        'link_text'   => 'Open Student Records',
        'how_to'      => [
            'Navigate to Student Records from the Students &amp; Support sidebar group',
            'Click "Add Student" to create a new record, or let profiles populate from a NAT import',
            'Enter personal details and USI, then complete all AVETMISS fields (employment status, prior education, disability, indigenous status, country of birth, language at home)',
            'Click a student\'s name to open their full profile — AVETMISS summary, completeness check, unit results, issued certificates, and a pre-enrolment readiness panel',
            'Read the pre-enrolment readiness card: suitability assessed, student declaration signed, USI verified, information provided — each shown as met / warning / not met',
            'Use the completeness indicator to see exactly which mandatory fields are still missing',
            'Use the USI Verification button to confirm the USI against the national registry (a verified USI is required before certificates can be issued)',
        ],
    ],
    [
        'anchor'      => 'studentsupport',
        'icon'        => 'users',
        'color'       => 'teal',
        'title'       => 'Student Support System',
        'subtitle'    => 'Standards 2.3–2.6 compliance',
        'description' => "Configure the organisation-level student support, reasonable adjustments, diversity, and wellbeing options that are offered to all learners. These selections are the source of the support options shown to trainers when they complete per-student support plans.",
        'clause_ref'  => 'Standards QA2.3–QA2.6',
        'clause_text' => 'RTOs must provide support to learners, make reasonable adjustments for learners with disabilities, and promote and protect the wellbeing and safety of all learners',
        'url'         => new moodle_url('/local/rtocompliance/student_support.php'),
        'link_text'   => 'Open Student Support',
        'how_to'      => [
            'Open Student Support from the QA2 sidebar group',
            'Select which support services your RTO offers (language support, counselling, financial assistance, etc.)',
            'Configure reasonable adjustment types available',
            'Set diversity and wellbeing policies and references',
            'Trainers use the Trainer Input page to record individual student support arrangements against these options',
        ],
    ],
    [
        'anchor'      => 'suitability',
        'icon'        => 'check-sq',
        'color'       => 'purple',
        'title'       => 'Student Suitability Check',
        'subtitle'    => 'Entry requirements & LLN assessment',
        'description' => "Send students a 4-stage Student Suitability Check covering evidence of entry requirements, LLN (Language, Literacy and Numeracy) level, a system-generated suitability decision, and a signed student declaration. Supports both manual and webhook-based LLN adapters.",
        'clause_ref'  => 'Standard QA2.2',
        'clause_text' => 'RTOs must have fair and transparent admissions processes, assess applicants\' suitability and LLN skills prior to enrolment, and provide pre-enrolment information',
        'url'         => new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => 0]),
        'link_text'   => 'Open Student Suitability Check',
        'how_to'      => [
            'Navigate to a student in Student Records and click "Send Student Suitability Check"',
            'Or go to the Suitability Send page directly and select a student',
            'Select the qualification and enter any trainer-assessed LLN level (if using manual adapter)',
            'Click "Send" — the student receives an email with a link to their personalised checklist',
            'The student completes Stage 1 (evidence), Stage 2 (LLN review), Stage 3 (system decision), and Stage 4 (declaration)',
            'You receive an admin notification when the form is submitted',
            'Review the outcome in Suitability View — override the decision if required',
        ],
    ],
    [
        'anchor'      => 'certificates',
        'icon'        => 'award',
        'color'       => 'green',
        'title'       => 'Certificates',
        'subtitle'    => 'AQF-compliant issuance register',
        'description' => "Issue all four AQF certificate types: Testamur (full qualification), Statement of Attainment (partial completion), Record of Results, and Certificate of Completion (non-accredited training). Issuance is now gated for integrity: the student's USI must be VERIFIED with the Registry (not merely present), and mandatory RTO details (legal name, provider code, signatory) must be configured, or issuance is blocked. Each certificate snapshots the RTO identity and USI as-issued, so a later settings change can't rewrite an old certificate, and reissues are faithful copies. A render-time backstop enforces the AQF rules (NRT logo never on a Record of Results, USI never on a Testamur/SoA). Certificates are stored in the 30-year register with QR code verification, and students can download their own via the My Certificates portal.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'RTOs must issue AQF qualifications and statements of attainment that meet all AQF requirements within 30 calendar days of a student completing all requirements',
        'url'         => new moodle_url('/local/rtocompliance/certificates.php'),
        'link_text'   => 'Open Certificates',
        'how_to'      => [
            'Navigate to Issued Certificates in the Certificates sidebar group',
            'View the list of issued certificates — the dashboard flags any certificates overdue for issue (30+ days since completion)',
            'To issue a new certificate, go to Student Results and click "Issue Certificate" for a completed student',
            'Select the certificate type (Testamur / Statement of Attainment / Record of Results / Certificate of Completion)',
            'Select the certificate audience (general, apprentice, VET-FEE, etc.) to apply the correct template design',
            'Click "Generate" — the PDF is created using your active custom template and the RTO identity + USI are snapshotted onto it',
            'Download, email to the student, or reissue from the certificate register (reissues are exact copies of the original)',
            'Issuance is blocked if the student\'s USI is not verified or mandatory RTO details are missing — resolve these first (Compliance Health lists the blocked students)',
        ],
    ],
    [
        'anchor'      => 'certtemplates',
        'icon'        => 'layout',
        'color'       => 'purple',
        'title'       => 'Certificate Templates',
        'subtitle'    => 'Visual template builder',
        'description' => "Design ASQA-compliant certificate templates with a drag-and-drop visual editor. Supports all four certificate types and nine audience variants (apprentice, VET-FEE, international, etc.). Validates mandatory fields per the ASQA Sample Forms fact sheet before allowing approval.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'AQF certification documentation must meet the requirements of the AQF certification documentation specification, including all mandatory elements',
        'url'         => new moodle_url('/local/rtocompliance/cert_templates.php'),
        'link_text'   => 'Open Certificate Templates',
        'how_to'      => [
            'Open Certificate Templates from the QA2 sidebar group (or from the Certificates page header)',
            'Click "Create New Template" — a pre-populated ASQA-compliant starter design is loaded automatically',
            'Use the visual editor to move, resize, and configure fields',
            'The validator panel on the right shows which mandatory ASQA fields are present/missing',
            'Upload your RTO logo, NRT mark, and AQF logo via the Branding panel',
            'Click "Submit for Approval" — the validator hard-blocks submission if mandatory fields are missing',
            'Activate the template to make it the live design for its certificate type and audience',
            'Use "Reset to ASQA Starter" to restore the default layout at any time',
        ],
    ],
    [
        'anchor'      => 'certtest',
        'icon'        => 'play-sq',
        'color'       => 'emerald',
        'title'       => 'Test Certificate',
        'subtitle'    => 'Preview your certificate design',
        'description' => "Generate a test PDF certificate for any certificate type using a synthetic student record. The test PDF uses the exact same render pipeline as real certificates — including your active custom template — so what you see is exactly what students will receive. Nothing is saved; no credits are charged.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'RTOs must ensure their certification documentation meets AQF requirements before issuing certificates to students',
        'url'         => new moodle_url('/local/rtocompliance/cert_test.php'),
        'link_text'   => 'Open Test Certificate Generator',
        'how_to'      => [
            'Open Test Certificate from the QA2 sidebar group',
            'Select the certificate type to test (Testamur / Statement of Attainment / Record of Results / Certificate of Completion)',
            'The page shows which active template will be used (or "built-in default" if no template is active)',
            'Optionally enter a sample student name',
            'Click "Generate Test PDF" — the PDF opens in a new tab',
            'Use this to check layout, logos, wording, and mandatory fields before approving for live use',
        ],
    ],
    [
        'anchor'      => 'natvalidate',
        'icon'        => 'check-circle',
        'color'       => 'emerald',
        'title'       => 'AVETMISS Validation',
        'subtitle'    => 'Pre-submission NAT checker',
        'description' => "Run this before you export your NAT files. It validates every student and enrolment against the NCVER edit rules that the collection system applies — so your quarterly submission isn't rejected after you upload it. Findings are split into Errors (which will fail the submission) and Warnings, you get a clear \"Ready to submit\" verdict, and the full list exports to CSV so you can work through it.",
        'clause_ref'  => 'Compliance Standard 7 / AVETMISS 8.0',
        'clause_text' => 'RTOs must submit training activity data that conforms to the AVETMISS standard and NCVER validation rules',
        'url'         => new moodle_url('/local/rtocompliance/nat_validate.php'),
        'link_text'   => 'Open AVETMISS Validation',
        'how_to'      => [
            'Open AVETMISS Validation from the Data &amp; Reporting sidebar group',
            'Run the check — every student and enrolment is tested against the NCVER edit rules',
            'Fix the Errors first (these will fail your submission); then review the Warnings',
            'Export the findings to CSV if you want to work through them offline',
            'When you reach the "Ready to submit" verdict, go to AVETMISS Export and generate your NAT files',
        ],
    ],
    [
        'anchor'      => 'natexport',
        'icon'        => 'database',
        'color'       => 'blue',
        'title'       => 'AVETMISS / NAT Export',
        'subtitle'    => 'National data collection files',
        'description' => "Generate all 10 NAT files required for NCVER reporting. All AVETMISS outcome codes are verified against NCVER Release 8.0 specifications. AVETMISS reporting is mandatory for all nationally recognised training — government funding depends on accurate submissions.",
        'clause_ref'  => 'Compliance Standard 7',
        'clause_text' => 'RTOs must report training activity data in accordance with AVETMISS requirements for all nationally recognised training delivered',
        'url'         => new moodle_url('/local/rtocompliance/natexport.php'),
        'link_text'   => 'Open NAT Export',
        'how_to'      => [
            'Navigate to AVETMISS Export in the Data & Reports sidebar group',
            'Select the reporting year and collection type (government-funded or fee-for-service)',
            'Review any validation warnings before generating (missing USIs, incomplete AVETMISS profiles, etc.)',
            'Click "Generate NAT Files" to produce all 10 files as a ZIP download',
            'Upload the ZIP to your State Training Authority portal or NCVER direct submission',
        ],
    ],
    [
        'anchor'      => 'statefunding',
        'icon'        => 'wallet',
        'color'       => 'emerald',
        'title'       => 'State Funding & Reporting',
        'subtitle'    => 'Configure state-funded training for all eight states & territories',
        'description' => "If your RTO holds a government training contract with any Australian State or Territory, the State Funding settings page is your single control panel. Configure QLD DTET contract codes, NSW Smart &amp; Skilled commitment IDs, VIC Skills First contract references, and equivalents for SA, WA, TAS, NT, and ACT — all in one place. Once saved, these values pre-fill the relevant fields on every new enrolment so staff never have to type contract codes manually. State funding data flows automatically into your AVETMISS NAT export.",
        'clause_ref'  => 'AVETMISS Standard 8 / State STA requirements',
        'clause_text' => 'RTOs delivering government-funded training must capture state-specific data fields (funding source, concession status, purchasing contract codes) and report them accurately to their State Training Authority via AVETMISS NAT files',
        'url'         => new moodle_url('/local/rtocompliance/plugin_settings.php', ['section' => 'local_rtocompliance_statefunding']),
        'link_text'   => 'Open State Funding Settings',
        'how_to'      => [
            '<strong>Step 1 — Open State Funding Settings:</strong> Go to <em>Settings &amp; Support &rarr; State Funding</em> in the left-hand sidebar, or via <em>Site Administration &rarr; Plugins &rarr; Local plugins &rarr; AI RTO Compliance &rarr; State Funding</em>.',
            '<strong>Step 2 — Complete only the states you deliver in:</strong> Each state has its own collapsible section. If your RTO holds a QLD DTET contract, fill in the QLD section — enter your QLD RTO Identifier (the DTET-assigned ID, different from your ASQA code) and up to three purchasing contract codes (e.g. QS102922). Leave all other state sections blank if you don\'t deliver there.',
            '<strong>Step 3 — Set default funding source codes:</strong> Each state section includes a default funding source code dropdown. Select the code that applies to most of your funded enrolments (e.g. QL1 for Cert 3 Guarantee in QLD, code 22 for Smart &amp; Skilled Standard in NSW). Staff can override this per enrolment if needed.',
            '<strong>Step 4 — Save settings:</strong> Click "Save changes" at the bottom of the page. Default values are now active — new enrolments will pre-populate with these codes automatically.',
            '<strong>Step 5 — Enrolment form:</strong> When a trainer creates a new enrolment for a state-funded student, the enrolment form will show a <em>Funding Source State Code</em> field (pre-filled with your default), a <em>Concession Status</em> field (F / C / E), and a <em>Purchasing Contract Code</em> dropdown (pre-filled with your contract codes). Staff select or confirm the values for that specific enrolment.',
            '<strong>Step 6 — Student profile (school-based only):</strong> For students flagged as <em>At School</em>, a <em>School Type</em> field (GOV / CAT / IND / OTH) will appear on their student profile. This is required for QLD (DTET) and VIC (Skills First) school-based apprenticeship and VETiS enrolments.',
            '<strong>Step 7 — Check your NAT export:</strong> Before your next AVETMISS submission, run the pre-export validation in NAT Export. It will flag any enrolments with missing state funding fields so you can resolve them before generating the NAT files.',
        ],
    ],
    [
        'anchor'      => 'dataimport',
        'icon'        => 'upload',
        'color'       => 'sky',
        'title'       => 'Data Import',
        'subtitle'    => 'Bulk import from NAT files or CSV',
        'description' => "Import student profile and outcome data in bulk from AVETMISS NAT files or CSV, e.g. when migrating from another student management system or bringing in historical records. Import is data-only: it populates student profiles and writes unit outcomes to the results register. It does NOT create Moodle accounts, enrol students, or issue certificates. Students who can't be matched to an existing profile go to a review CSV. Set up Qual Builder first — it defines your qualifications, units, and which Moodle courses deliver each unit.",
        'clause_ref'  => 'Compliance Standard 7',
        'clause_text' => 'RTOs must maintain accurate and complete student records in accordance with AVETMISS data requirements',
        'url'         => new moodle_url('/local/rtocompliance/data_import.php'),
        'link_text'   => 'Open Data Import',
        'how_to'      => [
            '<strong>Before you start — Set up Qual Builder:</strong> Go to <em>Qualification Builder</em> in the sidebar. Add every qualification your RTO delivers (use "Fetch from TGA" to auto-fill units), and for each unit link the Moodle course that delivers it. You only need to do this once — update it when you add new qualifications.',
            '<strong>Step 1 — Upload NAT files:</strong> On the Data Import page, choose your NAT file set (NAT00080, NAT00120, etc.) and upload. This loads the student data into the RTO Compliance database. No Moodle accounts are created.',
            '<strong>Step 2 — Confirm &amp; import:</strong> Review the groups of students by qualification and semester, then confirm each group. This populates the matching student profiles and writes their unit outcomes into the results register — the same register Student Results reads from.',
            '<strong>Step 3 — Review unmatched students:</strong> Any students in the file that can\'t be matched to an existing profile are listed for you to download as a review CSV and reconcile manually. Import never creates Moodle logins or enrolments — the auto-enrol wizard and the old Backfill / Fix-Over-Enrolments / Rollback tools have been removed.',
            '<strong>Check your work:</strong> Use <em>Verify NAT Data</em> to cross-check students against the AVETMISS database and their Student Records, and open <em>Student Results</em> to see the imported outcomes in the roster. To pull in current Moodle completions as well, use "Sync results from Moodle completions" on Student Results.',
        ],
    ],
    [
        'anchor'      => 'surveys',
        'icon'        => 'message',
        'color'       => 'rose',
        'title'       => 'Quality Indicator Surveys',
        'subtitle'    => 'Learner & employer feedback',
        'description' => "Collect, track, and analyse Quality Indicator feedback from learners and employers. QI data must be submitted to NCVER annually. Results drive continuous improvement actions and can be exported for the annual NCVER Quality Indicator submission.",
        'clause_ref'  => 'Standard QA4.4',
        'clause_text' => 'Surveys relate to Outcome Standard 4.4(2). RTOs must collect and respond to feedback from learners and employers as part of their continuous improvement system, and submit Quality Indicator data to NCVER annually',
        'url'         => new moodle_url('/local/rtocompliance/surveys.php'),
        'link_text'   => 'Open QI Surveys',
        'how_to'      => [
            'Open Quality Indicator Surveys from the QA2 sidebar group',
            'Click "Send Survey" to email a learner or employer survey to one or more students',
            'Students complete the survey online — responses are captured automatically',
            'Use the Reports tab to view aggregated results and satisfaction rates',
            'Export to CSV for your annual NCVER Quality Indicator submission',
        ],
    ],
    [
        'anchor'      => 'complaints',
        'icon'        => 'message-circle',
        'color'       => 'rose',
        'title'       => 'Complaints & Appeals',
        'subtitle'    => 'Feedback, complaints & appeals register',
        'description' => "Record, investigate, and resolve complaints, appeals, and improvement actions. Full audit trail from lodgement through investigation to outcome. Link systemic issues to continuous improvement actions.",
        'clause_ref'  => 'Standards QA2.7–QA2.8',
        'clause_text' => 'RTOs must have a complaints and appeals policy, manage all matters fairly and in a timely manner, and maintain records of all complaints and appeals received',
        'url'         => new moodle_url('/local/rtocompliance/complaints.php'),
        'link_text'   => 'Open Complaints Register',
        'how_to'      => [
            'Navigate to Feedback, Complaints & Appeals in the QA2 sidebar group',
            'Use the Complaints tab to record a new complaint (anonymous or identified)',
            'Assign an investigator and set a resolution due date',
            'Document investigation notes and the resolution outcome',
            'Use the Appeals tab for formal appeals against assessment decisions',
            'Use the Improvement tab to record improvement actions — link complaints to improvements for ASQA evidence',
        ],
    ],

    // ── QA3 – VET WORKFORCE ────────────────────────────────────────────────
    [
        'anchor'      => 'workforce',
        'icon'        => 'users',
        'color'       => 'sky',
        'title'       => 'VET Workforce Management',
        'subtitle'    => 'Workforce planning register',
        'description' => "Maintain a workforce planning register demonstrating that your RTO has sufficient qualified trainers and assessors to meet its scope of registration. Records current workforce summary, capability assessments, succession arrangements, and staffing ratios aligned to student load.",
        'clause_ref'  => 'Standard QA3.2',
        'clause_text' => 'RTOs must have, and maintain, a sufficient number of trainers and assessors who collectively have the vocational competencies, industry currency, and training and assessment competencies required for the training products on the RTO\'s scope',
        'url'         => new moodle_url('/local/rtocompliance/workforce_management.php'),
        'link_text'   => 'Open VET Workforce Management',
        'how_to'      => [
            'Open VET Workforce Management from the QA3 sidebar group',
            'Review the current workforce summary — trainers and assessors mapped to qualification scope',
            'Record workforce planning notes, succession arrangements, and planned recruitment',
            'Use this page as evidence for Standard QA3.2 during ASQA audits',
        ],
    ],
    [
        'anchor'      => 'trainers',
        'icon'        => 'user-check',
        'color'       => 'amber',
        'title'       => 'Trainers & Assessors',
        'subtitle'    => 'Credential & currency register',
        'description' => "ASQA requires trainers to hold TAE qualifications AND current vocational competency. Track credentials, TAE qualification details, industry currency evidence, expiry dates, and role classifications (1A–3B) for every trainer and assessor.",
        'clause_ref'  => 'Standard QA3.2',
        'clause_text' => 'Trainers and assessors must hold the required TAE qualifications and demonstrate current vocational competency and industry currency relevant to the training they deliver',
        'url'         => new moodle_url('/local/rtocompliance/trainers.php'),
        'link_text'   => 'Open Trainer Register',
        'how_to'      => [
            'Open Trainer & Assessor Competencies from the QA3 sidebar group',
            'Click "Add Trainer" and select the Moodle user',
            'Choose their role classification (1A–1E trainer, 2A–2C assessor, 3A–3B validator)',
            'Enter TAE qualification details (cert number, date achieved, expiry)',
            'Select vocational competency evidence types (formal qual, industry experience, professional development)',
            'Set the next review date — overdue reviews appear on the dashboard',
            'Attach credential documents for the audit evidence trail',
        ],
    ],
    [
        'anchor'      => 'supervision',
        'icon'        => 'eye',
        'color'       => 'purple',
        'title'       => 'Supervision Log',
        'subtitle'    => 'Trainee trainer supervision records',
        'description' => "When trainers are working towards full qualifications (roles 1C, 1D, 2B), RTOs must supervise and document their progress. This log records every supervision session with the supervisor, date, duration, activities covered, and sign-off.",
        'clause_ref'  => 'Standard QA3.2',
        'clause_text' => 'RTOs must support trainers and assessors to maintain and develop their competencies, including providing supervision for those working towards required qualifications',
        'url'         => new moodle_url('/local/rtocompliance/supervision.php'),
        'link_text'   => 'Open Supervision Log',
        'how_to'      => [
            'Open the Supervision Log from the QA3 sidebar group',
            'Click "Add Supervision Session"',
            'Select the trainee trainer and their supervising trainer',
            'Enter the session date, duration, and activities covered',
            'Record the supervisor sign-off',
            'Sessions are linked to the trainee\'s trainer profile as evidence of supervised practice',
        ],
    ],

    // ── QA4 – GOVERNANCE ───────────────────────────────────────────────────
    [
        'anchor'      => 'governance',
        'icon'        => 'building',
        'color'       => 'purple',
        'title'       => 'Governance',
        'subtitle'    => 'ADC, governing persons & minutes',
        'description' => "Manage your Annual Declaration of Compliance (ADC), governing persons register, board/committee meeting minutes, and material change notifications to ASQA. All governance records are stored with timestamps for the audit trail.",
        'clause_ref'  => 'Standard QA4.1',
        'clause_text' => 'RTOs must have effective governance and leadership arrangements, including governing persons who are fit and proper, and must notify ASQA of any material changes',
        'url'         => new moodle_url('/local/rtocompliance/governance.php'),
        'link_text'   => 'Open Governance',
        'how_to'      => [
            'Open Governance from the QA4 sidebar group',
            'Use the Governing Persons tab to register all directors, trustees, and key management personnel',
            'Record the annual ADC lodgement date and reference number',
            'Upload board/committee meeting minutes as governance evidence',
            'Use the Material Changes tab to record and track any changes notified to ASQA',
        ],
    ],
    [
        'anchor'      => 'risk',
        'icon'        => 'shield',
        'color'       => 'rose',
        'title'       => 'Risk Management',
        'subtitle'    => 'Risk register & treatment plans',
        'description' => "Maintain a risk register covering strategic, operational, financial, and compliance risks. Record risk ratings (likelihood × consequence), treatment plans, and review dates. Demonstrates to ASQA that your RTO has a systematic approach to risk management.",
        'clause_ref'  => 'Standard QA4.2',
        'clause_text' => 'RTOs must have systematic and continuous improvement processes, including identifying and managing risks that may affect the quality of training and assessment',
        'url'         => new moodle_url('/local/rtocompliance/risk.php'),
        'link_text'   => 'Open Risk Register',
        'how_to'      => [
            'Open Risk Management from the QA4 sidebar group',
            'Click "Add Risk" to record a new risk',
            'Select the risk category (strategic / operational / financial / compliance)',
            'Rate the likelihood and consequence — the system calculates the overall risk rating',
            'Record the treatment plan and risk owner',
            'Set a review date — overdue risks appear on the compliance dashboard',
        ],
    ],

    // ── COMPLIANCE STANDARDS ───────────────────────────────────────────────
    [
        'anchor'      => 'thirdparty',
        'icon'        => 'handshake',
        'color'       => 'teal',
        'title'       => 'Third-Party Arrangements',
        'subtitle'    => 'Partner & subcontractor register',
        'description' => "Track all written agreements with partner organisations, subcontractors, and auspicing arrangements. Record quality control oversight obligations and maintain evidence that your RTO monitors all third-party delivery on its behalf.",
        'clause_ref'  => 'Compliance Requirements, Division 3 Clause 17',
        'clause_text' => 'RTOs must enter into written agreements with third parties and ensure the quality of training and assessment delivered by or through those parties',
        'url'         => new moodle_url('/local/rtocompliance/thirdparty.php'),
        'link_text'   => 'Open Third-Party Register',
    ],
    [
        'anchor'      => 'feeprotection',
        'icon'        => 'wallet',
        'color'       => 'green',
        'title'       => 'Fee Protection',
        'subtitle'    => '$1,500 prepaid fee threshold',
        'description' => "Track prepaid fees to ensure you never collect more than $1,500 per student before training commences. Record fee agreements, refund policy acknowledgements, and any approved fee protection mechanisms in use.",
        'clause_ref'  => 'Compliance Requirements, Division 3 Clause 18',
        'clause_text' => 'RTOs must not collect more than $1,500 in prepaid fees from an individual student before the commencement of training',
        'url'         => new moodle_url('/local/rtocompliance/feeprotection.php'),
        'link_text'   => 'Open Fee Protection',
    ],
    [
        'anchor'      => 'insurance',
        'icon'        => 'umbrella',
        'color'       => 'teal',
        'title'       => 'Insurance Register',
        'subtitle'    => 'Policy expiry tracking',
        'description' => "Maintain records of public liability, professional indemnity, and workers compensation insurance policies. Receive alerts before policies expire so your coverage never lapses during an ASQA audit.",
        'clause_ref'  => 'Compliance Standard 8',
        'clause_text' => 'RTOs must maintain appropriate insurance coverage, including public liability and professional indemnity insurance, adequate for the scale and nature of their operations',
        'url'         => new moodle_url('/local/rtocompliance/insurance.php'),
        'link_text'   => 'Open Insurance Register',
        'how_to'      => [
            'Open Insurance Register from the Compliance Standards sidebar group',
            'Click "Add Policy" to record a new insurance policy',
            'Enter the insurer, policy type, policy number, coverage amount, and expiry date',
            'The dashboard flags any policies within 60 days of expiry',
            'Upload certificate of currency documents for your audit evidence file',
        ],
    ],

    // ── PRACTICE GUIDES ────────────────────────────────────────────────────
    [
        'anchor'      => 'practiceguides',
        'icon'        => 'scale',
        'color'       => 'sky',
        'title'       => 'ASQA Practice Guides',
        'subtitle'    => 'Self-assurance against all 14 guides',
        'description' => "Interactive self-assurance checklists aligned to all 14 ASQA Practice Guides. Work through each guide to assess your RTO's current compliance position, record evidence, and generate a self-assurance report for your next ASQA audit.",
        'clause_ref'  => 'Standards QA1–QA4',
        'clause_text' => 'RTOs are expected to use ASQA\'s Practice Guides as part of their self-assurance activities to continuously improve their compliance with the Standards',
        'url'         => new moodle_url('/local/rtocompliance/practice_guides.php'),
        'link_text'   => 'Open Practice Guides',
        'how_to'      => [
            'Open Practice Guides from the Settings & Support sidebar group',
            'Select a Practice Guide to begin a self-assurance review',
            'Work through each checklist item — mark Compliant, Partially Compliant, or Not Yet Compliant',
            'Record evidence references for each item',
            'Items marked non-compliant can be linked to improvement actions in the Complaints & Improvement register',
            'Print or export your completed self-assurance report as ASQA audit evidence',
        ],
    ],
];

echo html_writer::tag('h2', 'Compliance Modules', ['class' => 'section-title']);
echo html_writer::start_div('support-grid');

foreach ($supportModules as $module) {
    $card_attrs = !empty($module['anchor']) ? ['id' => $module['anchor']] : [];
    echo html_writer::start_div('support-card support-card-' . $module['color'], $card_attrs);
    
    echo html_writer::start_div('support-card-header');
    echo html_writer::start_div('support-card-icon icon-' . $module['color']);
    echo support_icon($module['icon']);
    echo html_writer::end_div();
    echo html_writer::start_div('support-card-titles');
    echo html_writer::tag('h3', $module['title'], ['class' => 'support-card-title']);
    echo html_writer::tag('p', $module['subtitle'], ['class' => 'support-card-subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::tag('p', $module['description'], ['class' => 'support-card-desc']);
    
    echo html_writer::start_div('support-clause-box clause-box-' . $module['color']);
    echo html_writer::tag('span', 'ASQA Standard Reference', ['class' => 'clause-label']);
    echo html_writer::tag('p', '<strong>' . $module['clause_ref'] . ':</strong> ' . $module['clause_text'], ['class' => 'clause-text']);
    echo html_writer::end_div();
    
    if (!empty($module['how_to'])) {
        echo html_writer::start_div('support-how-to');
        echo html_writer::tag('h4', support_icon('lightbulb') . ' How to Use', ['class' => 'how-to-title']);
        echo html_writer::start_tag('ol', ['class' => 'how-to-list']);
        foreach ($module['how_to'] as $step) {
            echo html_writer::tag('li', $step);
        }
        echo html_writer::end_tag('ol');
        echo html_writer::end_div();
    }
    
    echo html_writer::tag('a', $module['link_text'] . ' ' . support_icon('arrow-right'), [
        'href' => $module['url'],
        'class' => 'support-card-link link-' . $module['color']
    ]);
    
    echo html_writer::end_div();
}

echo html_writer::end_div();

require_once(__DIR__ . '/support_content.php');
$faq_categories = local_rtocompliance_support_faq_data();

echo html_writer::tag('h2', 'Frequently Asked Questions', ['class' => 'section-title faq-title']);

$faq_index = 0;
foreach ($faq_categories as $cat) {
    echo html_writer::start_div('faq-category');
    echo html_writer::start_div('faq-category-heading');
    echo support_icon($cat['icon'], 'faq-cat-icon');
    echo html_writer::tag('h3', $cat['category'], ['class' => 'faq-cat-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('faq-section');
    foreach ($cat['faqs'] as $faq) {
        echo html_writer::start_div('faq-item', ['data-faq' => $faq_index]);
        echo html_writer::start_div('faq-question');
        echo html_writer::tag('span', $faq['question'], ['class' => 'faq-q-text']);
        echo support_icon('chevron-down', 'faq-chevron');
        echo html_writer::end_div();
        echo html_writer::tag('div', $faq['answer'], ['class' => 'faq-answer']);
        echo html_writer::end_div();
        $faq_index++;
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo '<style>
.support-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-size: 16px;
}

.support-header {
    text-align: center;
    margin-bottom: 32px;
    position: relative;
}

.support-back-link {
    position: absolute;
    left: 0;
    top: 0;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
    text-decoration: none;
    font-size: 14px;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.2s;
}

.support-back-link:hover {
    background: #f3f4f6;
    color: #111827;
}

.support-back-link .support-icon {
    width: 16px;
    height: 16px;
}

.support-title {
    font-size: 32px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.support-title .header-icon {
    width: 36px;
    height: 36px;
    color: #0284c7;
}

.support-subtitle {
    font-size: 18px;
    color: #6b7280;
    margin: 0;
}

.support-intro {
    background: #f9fafb;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 40px;
    text-align: center;
}

.intro-text {
    font-size: 16px;
    color: #4b5563;
    margin: 0 0 16px 0;
    line-height: 1.6;
}

.intro-badges {
    display: flex;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
}

.intro-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    color: white;
}

.badge-rose { background: #e11d48; }
.badge-sky { background: #0284c7; }
.badge-green { background: #16a34a; }

.section-title {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 24px 0;
}

.support-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
    gap: 24px;
    margin-bottom: 48px;
}

.support-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 24px;
    transition: all 0.3s ease;
}

.support-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
}

.support-card-sky:hover { border-color: #0ea5e9; }
.support-card-amber:hover { border-color: #f59e0b; }
.support-card-purple:hover { border-color: #a855f7; }
.support-card-green:hover { border-color: #22c55e; }
.support-card-blue:hover { border-color: #3b82f6; }
.support-card-rose:hover { border-color: #f43f5e; }
.support-card-teal:hover { border-color: #14b8a6; }
.support-card-emerald:hover { border-color: #10b981; }

.support-card-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
}

.support-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.2s;
}

.support-card-icon .support-icon {
    width: 26px;
    height: 26px;
}

.icon-sky { background: #e0f2fe; color: #0284c7; }
.icon-amber { background: #fef3c7; color: #d97706; }
.icon-purple { background: #f3e8ff; color: #9333ea; }
.icon-green { background: #dcfce7; color: #16a34a; }
.icon-blue { background: #dbeafe; color: #2563eb; }
.icon-rose { background: #ffe4e6; color: #e11d48; }
.icon-teal { background: #ccfbf1; color: #0d9488; }
.icon-emerald { background: #d1fae5; color: #059669; }

.support-card:hover .icon-sky { background: #0284c7; color: white; }
.support-card:hover .icon-amber { background: #d97706; color: white; }
.support-card:hover .icon-purple { background: #9333ea; color: white; }
.support-card:hover .icon-green { background: #16a34a; color: white; }
.support-card:hover .icon-blue { background: #2563eb; color: white; }
.support-card:hover .icon-rose { background: #e11d48; color: white; }
.support-card:hover .icon-teal { background: #0d9488; color: white; }
.support-card:hover .icon-emerald { background: #059669; color: white; }

.support-card-titles {
    flex: 1;
}

.support-card-title {
    font-size: 20px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 4px 0;
}

.support-card-subtitle {
    font-size: 14px;
    color: #6b7280;
    margin: 0;
}

.support-card-desc {
    font-size: 15px;
    color: #4b5563;
    line-height: 1.6;
    margin: 0 0 16px 0;
}

.support-clause-box {
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
}

.clause-box-sky { background: #f0f9ff; border: 1px solid #bae6fd; }
.clause-box-amber { background: #fffbeb; border: 1px solid #fde68a; }
.clause-box-purple { background: #faf5ff; border: 1px solid #e9d5ff; }
.clause-box-green { background: #f0fdf4; border: 1px solid #bbf7d0; }
.clause-box-blue { background: #eff6ff; border: 1px solid #bfdbfe; }
.clause-box-rose { background: #fff1f2; border: 1px solid #fecdd3; }
.clause-box-teal { background: #f0fdfa; border: 1px solid #99f6e4; }

.clause-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.clause-box-sky .clause-label { color: #0369a1; }
.clause-box-amber .clause-label { color: #b45309; }
.clause-box-purple .clause-label { color: #7e22ce; }
.clause-box-green .clause-label { color: #15803d; }
.clause-box-blue .clause-label { color: #1d4ed8; }
.clause-box-rose .clause-label { color: #be123c; }
.clause-box-teal .clause-label { color: #0f766e; }

.clause-text {
    font-size: 13px;
    margin: 0;
    line-height: 1.5;
}

.clause-box-sky .clause-text { color: #0c4a6e; }
.clause-box-amber .clause-text { color: #78350f; }
.clause-box-purple .clause-text { color: #581c87; }
.clause-box-green .clause-text { color: #14532d; }
.clause-box-blue .clause-text { color: #1e3a8a; }
.clause-box-rose .clause-text { color: #881337; }
.clause-box-teal .clause-text { color: #134e4a; }
.clause-box-emerald { background: #ecfdf5; border: 1px solid #a7f3d0; }
.clause-box-emerald .clause-label { color: #065f46; }
.clause-box-emerald .clause-text { color: #064e3b; }

.support-how-to {
    background: #f9fafb;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
}

.how-to-title {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.how-to-title .support-icon {
    width: 18px;
    height: 18px;
    color: #f59e0b;
}

.how-to-list {
    margin: 0;
    padding: 0 0 0 24px;
    font-size: 14px;
    color: #4b5563;
    line-height: 1.7;
}

.how-to-list li {
    margin-bottom: 4px;
}

.support-card-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}

.support-card-link .support-icon {
    width: 16px;
    height: 16px;
    transition: transform 0.2s;
}

.support-card-link:hover .support-icon {
    transform: translateX(4px);
}

.link-sky { color: #0284c7; }
.link-amber { color: #d97706; }
.link-purple { color: #9333ea; }
.link-green { color: #16a34a; }
.link-blue { color: #2563eb; }
.link-rose { color: #e11d48; }
.link-teal { color: #0d9488; }

.link-sky:hover { color: #0369a1; }
.link-amber:hover { color: #b45309; }
.link-purple:hover { color: #7e22ce; }
.link-green:hover { color: #15803d; }
.link-blue:hover { color: #1d4ed8; }
.link-rose:hover { color: #be123c; }
.link-teal:hover { color: #0f766e; }

.faq-title {
    margin-top: 56px;
    margin-bottom: 32px;
    font-size: 1.5rem;
    font-weight: 600;
    color: #0f172a;
    letter-spacing: -0.02em;
}

.faq-category {
    margin-bottom: 40px;
}

.faq-category-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e2e8f0;
}

.faq-cat-icon {
    width: 22px;
    height: 22px;
    color: #0284c7;
    flex-shrink: 0;
}

.faq-cat-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    letter-spacing: -0.01em;
}

.faq-section {
    max-width: 800px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.faq-item {
    border: 1px solid #e2e8f0;
    border-left: 4px solid #3b82f6;
    border-radius: 12px;
    overflow: hidden;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.faq-item:hover {
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.08);
    border-left-color: #2563eb;
}

.faq-item.open {
    border-left-color: #1d4ed8;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.12);
}

.faq-question {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    cursor: pointer;
    transition: background 0.15s ease;
    gap: 16px;
}

.faq-question:hover {
    background: #f8fafc;
}

.faq-q-text {
    font-size: 1rem;
    font-weight: 500;
    color: #1e293b;
    line-height: 1.5;
}

.faq-chevron {
    width: 20px;
    height: 20px;
    color: #3b82f6;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    flex-shrink: 0;
}

.faq-item.open .faq-chevron {
    transform: rotate(180deg);
    color: #1d4ed8;
}

.faq-answer {
    display: none;
    padding: 0 24px 24px 24px;
    font-size: 1rem;
    color: #475569;
    line-height: 1.7;
    border-top: 1px solid #f1f5f9;
    margin-top: -4px;
    padding-top: 20px;
}

.faq-item.open .faq-answer {
    display: block;
}

.support-icon {
    display: inline-flex;
    width: 20px;
    height: 20px;
}

.support-icon svg {
    width: 100%;
    height: 100%;
}

.whats-new-panel {
    background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
    border: 1px solid #bfdbfe;
    border-radius: 16px;
    padding: 28px 32px;
    margin-bottom: 40px;
}

.whats-new-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.whats-new-icon {
    width: 24px;
    height: 24px;
    color: #f59e0b;
}

.whats-new-title {
    font-size: 20px;
    font-weight: 700;
    color: #1e3a5f;
    margin: 0;
}

.whats-new-intro {
    font-size: 15px;
    color: #374151;
    margin: 0 0 16px 0;
}

.whats-new-list {
    margin: 0;
    padding-left: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.whats-new-list li {
    font-size: 14px;
    color: #374151;
    line-height: 1.6;
}

.whats-new-list code {
    background: #e0f2fe;
    color: #0369a1;
    padding: 1px 5px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
}

@media (max-width: 768px) {
    .support-container {
        padding: 16px;
    }
    
    .support-back-link {
        position: static;
        margin-bottom: 16px;
        display: inline-flex;
    }
    
    .support-title {
        font-size: 26px;
    }
    
    .support-grid {
        grid-template-columns: 1fr;
    }
    
    .support-card {
        padding: 20px;
    }
    
    .support-card-title {
        font-size: 18px;
    }

    .whats-new-panel {
        padding: 20px;
    }
}
</style>';

echo '<script>
document.querySelectorAll(".faq-question").forEach(function (question) {
    question.addEventListener("click", function () {
        var item = this.closest(".faq-item");
        item.classList.toggle("open");
    });
});
</script>';

echo $OUTPUT->footer();
