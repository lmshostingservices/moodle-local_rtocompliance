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
 * local_rtocompliance file.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// DIAG-v5.9.50: Step-by-step breadcrumb logging. Each marker writes to a file in
// /tmp AND to the PHP error log AND to Moodle DB config (fallback).
// Check /tmp/rtoc_sc_*.txt or PHP error log, or run in Moodle DB:
//   SELECT value FROM mdl_config WHERE plugin='local_rtocompliance' AND name='_sc_cp';
// Remove this block once the root cause of the HTTP 500 is identified.
$_sc_log = sys_get_temp_dir() . '/rtoc_sc_' . date('Ymd_His') . '_' . getmypid() . '.txt';
if (!function_exists('_sc_log')) {
    function _sc_log(string $step): void {
        global $_sc_log;
        $line = date('[H:i:s] ') . $step . "\n";
        @file_put_contents($_sc_log, $line, FILE_APPEND);
        error_log('[RTOC-SC] ' . $step);
    }
}
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err) {
        _sc_log('SHUTDOWN fatal type=' . $err['type'] . ' msg=' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    } else {
        _sc_log('SHUTDOWN normal');
    }
});
_sc_log('START pid=' . getmypid() . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '?'));

require_once(__DIR__ . '/../../config.php');
require_login();
_sc_log('config.php loaded');
try { set_config('_sc_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'config_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}

require_once($CFG->libdir . '/adminlib.php');
_sc_log('adminlib.php loaded');
try { set_config('_sc_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'adminlib_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}

require_once(__DIR__ . '/lib.php');
_sc_log('lib.php loaded');
try { set_config('_sc_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'lib_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}

admin_externalpage_setup('local_rtocompliance_supportinternal');
_sc_log('admin_externalpage_setup done');
try { set_config('_sc_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'setup_done']), 'local_rtocompliance'); } catch (\Throwable $_e) {}
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
        'description' => 'Create student records with USI and AVETMISS data. Students are automatically linked when they enrol in your Moodle courses.',
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
        'description' => 'View competency outcomes (C/NYC/RPL) for each qualification. This is your central hub for tracking who is ready for certification.',
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
    '<strong>Pro Tip:</strong> Start with steps 1-3 to get your basic setup running. ' .
    'You can add trainers and configure advanced features as you go. The dashboard will guide you on what needs attention.',
    ['class' => 'tip-text']
);
echo html_writer::end_div();

echo html_writer::end_div();

// What's New panel — updated each audit/release cycle
echo html_writer::start_div('whats-new-panel');
echo html_writer::start_div('whats-new-header');
echo support_icon('star', 'whats-new-icon');
echo html_writer::tag('h2', "What's New — v5.9.307", ['class' => 'whats-new-title']);
echo html_writer::end_div();
echo html_writer::tag('p',
    'v5.9.307 adds an inline NAT00080 file upload to the Students page DOB-sync bar. '
    . 'Previously the "Upload NAT00080" button sent you to the Data Import wizard — now you can pick your NAT00080 .txt file directly on the Students page and click "Upload &amp; Sync DOBs" to apply dates of birth in one step. '
    . 'The parser reads the fixed-width AVETMISS 8.0 format (client ID at positions 0–9, DOB at positions 73–80). For tab-delimited or variant-format files the full Data Import wizard is still available.',
    'v5.9.306 fixes two bugs found during a full VET data collection audit (every AVETMISS field traced from enrolment through form through NAT export). ' .
    'SENTINEL FIX: the admin profile validator checked labourforcestatus and highestschoollevel against single-@ but both fields use double-@@ as their not-stated value. ' .
    'A student with default values (@@) passed admin validation and was marked profile-complete, then immediately flipped back to incomplete the moment they opened their own profile. ' .
    'DEPENDENT FIELD FIX: Moodle hideIf is JS-only — hidden fields still POST their cached values. ' .
    'schooltype was being emitted in NAT00120 for non-school-based students (wrong AVETMISS data); contact details were included in NAT00085 for students who withdrew consent.',
    'v5.9.305 fixes three deferred bugs from the E2E audit requiring schema or policy decisions. ' .
    '(C1) The USI client validator accepted the forbidden characters 0, 1, I, and O due to a case-insensitive regex flag — aligned with the authoritative avetmiss_codes validator. ' .
    '(C2) Certificates had no USI column — re-rendering a historical cert after a USI correction silently showed the new USI, breaking forensic audit trails required under ASQA practice. ' .
    'A usi column is now added to the certs table and snapshotted at issuance time; existing rows backfilled. ' .
    '(H3) The cert template fallback to legacy TCPDF was completely silent on production — admins issued non-compliant certs with no warning. ' .
    'Fallbacks now log to the plugin audit table and set a dashboard config flag that shows an amber warning banner.',
    ['class' => 'whats-new-intro']
);
echo html_writer::start_tag('ul', ['class' => 'whats-new-list']);
$whatsNew = [
    '<strong>View Results — selected units consistency fix (v5.9.295)</strong> — ' .
    'The "Units in Product" stat and per-student progress grid were built from all <code>qualunits</code> rows, ' .
    'including unselected/deselected units. Core Units and Elective Units stats already filtered ' .
    '<code>selected=1</code>, making the four numbers inconsistent and inflating the progress % denominator. ' .
    'Fixed: <code>selected=1</code> added to the <code>$units</code> query so all four metrics share the same unit set.',
    '<strong>View Results — variant-course student matching fix (v5.9.295)</strong> — ' .
    'The student table only found students whose enrolment <code>programcode</code> matched the qualification, ' .
    'or who were enrolled in a primary <code>qualunits.courseid</code>. Students who enrolled via a variant ' .
    'delivery course (stored in <code>qualunit_courses</code>) and had no programcode set were silently ' .
    'excluded. Fixed: UNION with <code>qualunit_courses</code> (is_archive=0) added to the courseid fallback ' .
    'subquery, matching the same pattern applied across process_enrolment_task.php and lib.php in v5.9.293.',
    '<strong>View Results — Total Enrolled / Completed / In Progress stat alignment fix (v5.9.295)</strong> — ' .
    'The three summary stat cards used programcode-only matching — narrower than the student table query — ' .
    'so "Total Enrolled" could show fewer students than the table row count and "In Progress" ' .
    '(= Total − Completed) could go negative. All three stat queries now use the same ' .
    'programcode-OR-courseid(primary+variant) approach as the student table.',
    '<strong>View Results — completed units filter consistency fix (v5.9.295)</strong> — ' .
    'The "all units finalised" subquery inside the Completed stat did not filter <code>selected=1</code>, ' .
    'so deselected units could prevent a student from being counted as complete even though every ' .
    'active unit had a final outcome. Fixed: <code>selected=1</code> added to match the display unit set.',
    '<strong>QB list page total unit count fix (v5.9.294)</strong> — ' .
    'The UNITS column and the linked X/Y counter on the Qualification Builder list page now use the ' .
    'TGA-sourced total from the qualification\'s packaging rules (e.g. 12 for TLI50119: 10 core + 2 elective) ' .
    'instead of only the units saved so far in the database. Previously a record with 10 core units saved ' .
    'and 2 elective units not yet selected showed "10" / "6/10" — the list now shows "12" / "6/12", ' .
    'matching the edit page\'s Live Compliance Status panel exactly. Falls back to the DB count for ' .
    'records that predate TGA loading (totalunits = 0).',
    '<strong>Generate by Course variant cert-type fix (v5.9.294)</strong> — ' .
    'When an admin opened the "Generate by Course" page for a <em>variant</em> course (one stored in ' .
    '<code>qualunit_courses</code> rather than as the primary <code>qualunits.courseid</code>), the ' .
    'qualbuilder lookup returned null and the system fell back to a generic SoA from course settings ' .
    'instead of the correct Testamur + Record of Results. Fixed: if the primary lookup finds nothing, ' .
    'a second query checks <code>qualunit_courses</code> to locate the owning qualbuilder and resolve ' .
    'the cert type correctly.',
    '<strong>Enrolment creation UNION fix (v5.9.293)</strong> — ' .
    'When a student enrolled in a Moodle course, the plugin created AVETMISS enrolment records for any ' .
    'Qualification Builder units that listed that course as their <em>primary</em> delivery course. ' .
    'If the same course was also a <em>variant</em> for a unit in a different QB record (e.g. a shared ' .
    'unit course across two qualification streams), no enrolment record was created for that second QB. ' .
    'Fixed: both the primary and variant tables are always queried and merged, deduped by unit ID.',
    '<strong>Autocert UNION fix (v5.9.293)</strong> — ' .
    'The same OR/fallback pattern in <code>queue_autocert_if_all_units_complete()</code> meant that ' .
    'when a course is primary in QB-A and a variant in QB-B, completing that course only triggered the ' .
    'qualification-completion check for QB-A. QB-B\'s auto-certificate was never queued. ' .
    'Fixed: both qualbuilderid sets are always merged before the per-unit competency check.',
    '<strong>Testamur vs SoA determination fix (v5.9.293)</strong> — ' .
    'CRITICAL: <code>check_full_qual_completion()</code> determined whether a student received a full ' .
    'Testamur + Record of Results (all units complete) or a partial Statement of Attainment. ' .
    'It checked only the <em>primary</em> course in <code>course_completions</code> for each unit — ' .
    'a student who completed via a variant course always returned false, resulting in an incorrect SoA. ' .
    'Fixed: per-unit check now accepts ANY delivery course (primary or variant) as satisfying the unit.',
    '<strong>Partial SoA unit list fix (v5.9.293)</strong> — ' .
    '<code>get_completed_units_for_qual()</code> builds the unit list for partial SoA certs. ' .
    'Previously it only looked at primary-course completions, so units completed via variant courses ' .
    'were omitted from the SoA. Fixed: variant courseids included in the per-unit completion check.',
    '<strong>Cert issue date fix for variant completers (v5.9.293)</strong> — ' .
    'The Generate Certificates page looked up the earliest completion timestamp from ' .
    '<code>course_completion_crit_compl</code> and <code>course_completions</code> using only primary ' .
    'courseids. A student who completed via a variant course got no timestamp from either table, ' .
    'causing the cert to use the current time as the issue date instead of when they actually finished. ' .
    'Fixed: all delivery courseids (primary + variants) included in the timestamp lookup.',
    '<strong>SQL mixed-params crash fix (v5.9.292)</strong> — ' .
    'Opening any saved Qualification Builder record threw "Mixed types of sql query parameters". ' .
    'The page-load query that fetches variant course chips used positional ? placeholders from ' .
    '<code>get_in_or_equal()</code> then mixed in a named <code>:is_archive_val</code> parameter — ' .
    'Moodle\'s DB layer rejects this combination. Fixed by passing <code>SQL_PARAMS_NAMED</code> to ' .
    '<code>get_in_or_equal()</code> so all parameters use named placeholders.',
    '<strong>Plugin version display fix (v5.9.292)</strong> — ' .
    'Moodle\'s plugin overview page showed 5.9.267 on every install regardless of the actual installed version. ' .
    'A <code>$plugin->release = \'5.9.267\'</code> line in version.php was missing the <code>_prev</code> suffix — ' .
    'in PHP the last assignment wins, so it overwrote the correct 5.9.29x value. ' .
    'The portal\'s <code>head -1</code> grep happened to pick the first (correct) line, masking this bug. Fixed.',
    '<strong>Packaging rules paste box (v5.9.291)</strong> — ' .
    'Some TGA qualifications (particularly TLI-series) return <code>packagingInformation: null</code> from the ' .
    'TGA REST API. Previously the system silently saved the number of currently-selected units as the required ' .
    'total — so a record with 10 units showed "10 required" even though the real rule was 12 (10 core + 2 elective). ' .
    'The compliance dashboard now shows an amber prompt when this happens. Paste the full Packaging Rules section ' .
    'from training.gov.au; the text is parsed client-side to extract total/core/elective counts; the three ' .
    'compliance cards appear immediately and the values are stored when you click Save Qualification.',
    '<strong>Variant badge readability fix (v5.9.291)</strong> — ' .
    'The primary linked-course badge (e.g. <em>✓ TLIX0037 26S1</em>) changed from a washed-out green-on-green-tint ' .
    'to white background with a crisp green border and bold green text — much easier to read at a glance.',
    '<strong>Compact + add-variant button (v5.9.291)</strong> — ' .
    'The wide dashed "+ add variant…" select box has been replaced by a small circle + button (18 px). ' .
    'Clicking it reveals the course dropdown inline; clicking away or selecting a course hides it again. ' .
    'The button disappears entirely when all available semester courses are already linked.',
    '<strong>Variant system info banner (v5.9.291)</strong> — ' .
    'A dismissible amber-blue info panel appears above the unit list once a semester is selected and at least ' .
    'one unit is linked. It explains in plain English what the primary course badge means, what variant chips ' .
    'do, and gives a real-world example (three trainer-stream courses all being watched, all students getting certs).',
    '<strong>Teacher-cohort variant courses per unit (v5.9.290)</strong> — ' .
    'Each unit row in the Qualification Builder now shows all Moodle courses in the selected semester ' .
    'that share the same TGA unit code as small chips alongside the primary linked course badge — ' .
    'for example <em>[✓ TLIX0037 26S1–EL]&nbsp;&nbsp;[26S1–CD ×]&nbsp;&nbsp;[26S1–ND ×]&nbsp;&nbsp;[+]</em>. ' .
    'Chips are auto-detected when a semester is selected. Remove any you do not want. ' .
    'Add extras with the + button. Saved to the <code>qualunit_courses</code> junction table.',
    '<strong>Reconciler watches all variant courses (v5.9.290)</strong> — ' .
    'The enrolment reconciler previously only queried <code>qualunit_courses</code> for archive ' .
    '(prior-semester) courses. That restriction is removed — the reconciler now fires for both ' .
    'teacher-cohort variants (<code>is_archive = 0</code>) and archive courses (<code>is_archive = 1</code>). ' .
    'Students in any variant course get their AVETMISS NAT00120 enrolment record created automatically.',
    '<strong>Autocert fires across all variant courses (v5.9.290)</strong> — ' .
    'Qualification-completion detection now checks variant courses for unit completions in addition to ' .
    'the primary linked course. This means the system issues a Testamur automatically when a student ' .
    'achieves Competent on every unit — regardless of which teacher-variant course they were enrolled in.',
    '<strong>Stream / Variant Name field on qualification (v5.9.285)</strong> — ' .
    'Each qualification record now has an optional <em>Stream / Variant Name</em> field. ' .
    'Use it to label delivery streams (e.g. "EL Stream", "CD Cohort", "Evening Intake 26S1") ' .
    'so multiple QB records for the same qualification code are clearly distinguishable in the list view. ' .
    'The stream label appears as a small badge on the qualification card.',
    '<strong>All-semester course dropdown on unit rows (v5.9.285)</strong> — ' .
    'The primary course dropdown on each unit row previously only showed courses from the selected semester. ' .
    'It now includes courses from all active semesters so you can link a unit to an archive course or ' .
    'a cross-semester delivery without switching semesters first.',
    '<strong>State Funding tab in Plugin Settings (v5.9.49)</strong> — ' .
    '"State Funding" now appears as a dedicated tab inside Plugin Settings alongside RTO Details, ' .
    'Platform API, Certificates, USI Settings, ASQA 2025, and Maintenance. ' .
    'The left-hand sidebar "State Funding" link and the Support Centre guide button both land here directly.',
    '<strong>Full state/territory regulator list (v5.9.48)</strong> — ' .
    'The State/Territory Regulator dropdown now covers all 8 states and territories: ' .
    'ASQA (national), VRQA (VIC), TAC (WA), DESBT/QLD, Skills NSW, SA Skills, DTWD/WA, TASC/TAS, ' .
    'Skills Tasmania, Skills Canberra/ACT, and NT DITT.',
    '<strong>State Funding admin settings page (v5.9.43)</strong> — ' .
    'Configure your RTO\'s state-specific funding parameters for QLD (DTET), NSW (Smart &amp; Skilled), ' .
    'VIC (Skills First), SA, WA, TAS, NT, and ACT. Once saved, contract codes and funding source ' .
    'codes pre-fill automatically on every new enrolment.',
];
foreach ($whatsNew as $item) {
    echo html_writer::tag('li', $item);
}
echo html_writer::end_tag('ul');
echo html_writer::end_div();

$supportModules = [

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
        'description' => "Record and track Recognition of Prior Learning (RPL) applications and Credit Transfer (CT) grants. Maintains the evidence trail ASQA requires to demonstrate fair, consistent, and flexible RPL processes.",
        'clause_ref'  => 'Standard QA1.5',
        'clause_text' => 'RTOs must have fair, flexible, and consistent processes for recognising the current skills and knowledge of applicants, including RPL',
        'url'         => new moodle_url('/local/rtocompliance/rpl.php'),
        'link_text'   => 'Open RPL & Credit Transfer',
        'how_to'      => [
            'Open RPL & Credit Transfer from the QA1 sidebar group',
            'Use the RPL tab to record an RPL application — select the student, qualification, and units being assessed',
            'Document the evidence portfolio and assessor decision for each unit',
            'Use the Credit Transfer tab to record formal CT from a recognised equivalent qualification',
            'All RPL and CT records are retained as evidence of your Standard 1.5 compliance',
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
            'Enter the qualification code (e.g. TLI50321) and click "Fetch from training.gov.au" — core units are added automatically and electives are presented for selection',
            'Select your semester from the dropdown — the unit-course mapping panel then shows all Moodle courses in that semester',
            'Click "Map All Courses" to auto-link each unit to its matching Moodle course based on the unit code in the course name',
            'Review the auto-detected teacher-cohort variant chips on each unit row (e.g. [✓ EL] [CD ×] [ND ×]) — remove any streams that should not be tracked',
            'Use the [+ add variant…] dropdown on any unit to add a course that was not auto-detected',
            'Set nominal hours for each unit if they differ from TGA defaults — these flow into your AVETMISS NAT00120 export',
            'Optionally fill in the Stream / Variant Name field to distinguish this QB record from others with the same qualification code',
            'Click "Validate Packaging" to confirm the unit mix meets training package rules (core count, elective count, prerequisites)',
            'Click "Save" — the reconciler is now watching all linked and variant courses; AVETMISS records and certificates are created automatically',
        ],
    ],
    [
        'anchor'      => 'qualresults',
        'icon'        => 'bar-chart',
        'color'       => 'emerald',
        'title'       => 'Student Results',
        'subtitle'    => 'Unit-by-unit competency tracking &amp; autocert',
        'description' => "View all students enrolled in each qualification with unit-by-unit competency outcomes (C / NYC / RPL / CT). " .
                         "Track completion percentages, identify students ready for certification, and export results for AVETMISS reporting. " .
                         "When all units are Competent (C, RPL, or CT), the system automatically queues a Testamur for the student — " .
                         "no manual trigger required. The 30-day issuance clock starts from the date the final unit is completed.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'RTOs must issue AQF qualifications and statements of attainment only to learners who have been assessed as meeting all requirements',
        'url'         => new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'link_text'   => 'Open Student Results',
        'how_to'      => [
            'Access via the "View Results" button on any qualification in Qualification Builder, or navigate directly from the sidebar',
            'Select a qualification to view all enrolled students with their unit-by-unit outcomes',
            'Outcomes: C = Competent (AVETMISS 20), NYC = Not Yet Competent (AVETMISS 30), RPL = Prior Learning granted (AVETMISS 51/52), CT = Credit Transfer (AVETMISS 60)',
            'Students with 100% completion are highlighted in green — if autocert is enabled they receive their Testamur automatically',
            'To issue manually: click the "Issue Certificate" button, select the certificate type and audience variant, then confirm',
            'The issued certificate is saved to the 30-year register and the student can receive it by email or download link',
            'Export to CSV for external reporting, AVETMISS preparation, or state funding audit evidence',
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
        'description' => "Manage student enrolment records with complete AVETMISS data fields. Track USI verification status, personal details, disabilities and support needs, and enrolment history for all nationally recognised training.",
        'clause_ref'  => 'Compliance Standard 7',
        'clause_text' => 'RTOs must accurately collect and report training activity data in accordance with the AVETMISS standard for all nationally recognised training',
        'url'         => new moodle_url('/local/rtocompliance/students.php'),
        'link_text'   => 'Open Student Records',
        'how_to'      => [
            'Navigate to Student Records from the QA2 sidebar group',
            'Click "Add Student" to create a new record',
            'Enter personal details and USI',
            'Complete all AVETMISS fields (employment status, prior education, disability, indigenous status, country of birth, language at home)',
            'Link to course enrolments',
            'The system auto-prompts for missing AVETMISS data when students enrol in NRT courses',
            'Use the USI Verification button to confirm USI against the national registry',
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
        'description' => "Issue all four AQF certificate types: Testamur (full qualification), Statement of Attainment (partial completion), Record of Results, and Certificate of Completion (non-accredited training). Certificates are rendered using your custom visual template, include USI Clause 12 compliance, and are stored in the 30-year register with QR code verification.",
        'clause_ref'  => 'Compliance Requirements Clause 9(2)',
        'clause_text' => 'RTOs must issue AQF qualifications and statements of attainment that meet all AQF requirements within 30 calendar days of a student completing all requirements',
        'url'         => new moodle_url('/local/rtocompliance/certificates.php'),
        'link_text'   => 'Open Certificates',
        'how_to'      => [
            'Navigate to Certificates in the QA2 sidebar group',
            'View the list of issued certificates — the dashboard flags any certificates overdue for issue (30+ days since completion)',
            'To issue a new certificate, go to Student Results and click "Issue Certificate" for a completed student',
            'Select the certificate type (Testamur / Statement of Attainment / Record of Results / Certificate of Completion)',
            'Select the certificate audience (general, apprentice, VET-FEE, etc.) to apply the correct template design',
            'Click "Generate" — the PDF is created using your active custom template',
            'Download, email to the student, or reissue from the certificate register',
            'USI Clause 12 alert appears if the student\'s USI has not been verified — this is a warning, not a block',
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
        'description' => "Import student enrolment data in bulk from AVETMISS NAT files or CSV. Useful when migrating from another SMS, importing historical records, or bulk-loading enrolments from an external system. Before importing, make sure Qual Builder is set up — it defines your qualifications, units, and which Moodle courses deliver each unit. Without it, students cannot be matched to courses or issued certificates.",
        'clause_ref'  => 'Compliance Standard 7',
        'clause_text' => 'RTOs must maintain accurate and complete student records in accordance with AVETMISS data requirements',
        'url'         => new moodle_url('/local/rtocompliance/data_import.php'),
        'link_text'   => 'Open Data Import',
        'how_to'      => [
            '<strong>Before you start — Set up Qual Builder:</strong> Go to <em>Qual Builder</em> in the sidebar. Add every qualification your RTO delivers (use "Fetch from TGA" to auto-fill units). For each unit, link it to the Moodle course that delivers it. You only need to do this once — come back and update it when you add new qualifications.',
            '<strong>Step 1 — Upload NAT Files:</strong> On the Data Import page, choose your NAT file set (NAT00060, NAT00080, NAT00120, etc.) and upload. This loads all student data into the RTO Compliance database. Nothing appears in Student Records yet and no Moodle accounts are created.',
            '<strong>Step 2 — Confirm &amp; Import:</strong> Review the groups of students by qualification and semester shown on the next page. Confirm each group to save their data to the database. Still no Moodle accounts or enrolments at this stage.',
            '<strong>Step 3a — In-progress students (Auto-Enrol):</strong> For students who are still actively working towards their certificate and need Moodle access — use the Auto-Enrol step. <strong>Only students with at least one unit still in progress</strong> (AVETMISS outcome code <code>70</code> — Continuing Enrolment) are processed. Students whose <em>all</em> units have terminal outcomes (20 Competent, 30 Not Yet Achieved, 40 Withdrawn, 51/52/53 RPL, 60/61 Credit Transfer, etc.) are automatically skipped — they don\'t need Moodle course access. For each in-progress student, it creates their Moodle login, creates their Student Record, and enrols them in courses. <strong>Unit-accurate enrolment (v5.9.57, on by default):</strong> each student is only enrolled into the specific Moodle courses whose <em>Course ID number</em> matches a unit code in their NAT00120 file — so a student studying three units only gets access to those three courses, not every course in the category. Courses with no ID number configured are enrolled unconditionally. Turn the toggle off on the form to revert to the legacy behaviour (enrol into all visible courses in the category).',
            '<strong>Step 3b — Completed &amp; historical students (Backfill Qual Builder):</strong> For students with terminal outcomes — completed, withdrawn, RPL, credit transfer — who don\'t need Moodle access but do need a certificate, use <em>Backfill Qual Builder</em> (shortcut button on the Data Import page). This creates a Student Record and qualification history for every eligible student in the NAT database who doesn\'t have one yet, without creating Moodle accounts or course enrolments.',
            '<strong>Check your work:</strong> Use <em>Verify NAT Data</em> to cross-check every student against the AVETMISS database, Moodle accounts, and Student Records. It shows you at a glance who is complete, who is missing a record, and who needs attention.',
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

$faq_categories = [

    // ── GETTING STARTED ────────────────────────────────────────────────────
    [
        'category' => 'Getting Started',
        'icon'     => 'layout-dashboard',
        'faqs'     => [
            [
                'question' => 'What should I do first after installing the plugin?',
                'answer'   => 'Start with four quick setup steps: (1) Go to Site Administration &rarr; AI RTO Compliance &rarr; Settings and enter your RTO name, RTO number, and AVETMISS reporting identifier. (2) Open Qualification Builder and add your qualifications — fetch their units from training.gov.au. (3) Add your trainers in the Trainer Register and record their TAE qualifications. (4) Add your students in Student Records. Once those four are complete, your compliance dashboard will start showing real data.',
            ],
            [
                'question' => 'What do the coloured tiles on the compliance dashboard mean?',
                'answer'   => 'Each tile represents one area of the Standards for RTOs 2015. Red tiles require urgent attention — something is overdue or non-compliant. Amber tiles are approaching a deadline. Green tiles are compliant. Grey tiles have no records yet (which is itself a compliance risk if that area is part of your scope). Click any tile to jump straight to that module.',
            ],
            [
                'question' => 'Does the plugin work with any version of Moodle?',
                'answer'   => 'The plugin is developed and tested against Moodle 4.1 LTS and later. It will install on Moodle 3.11 but some hook-based features (such as the automatic header/footer injections) require Moodle 4.3+. If you are on an older Moodle, those features degrade gracefully — they simply do not appear rather than causing errors.',
            ],
            [
                'question' => 'Where do I enter my RTO number and ABN?',
                'answer'   => 'Go to Site Administration &rarr; Plugins &rarr; Local plugins &rarr; AI RTO Compliance &rarr; Settings. The General section at the top has fields for your RTO name, RTO number (your ASQA registration number), ABN, AVETMISS reporting identifier, and contact details. These values are used on certificates, in NAT file headers, and throughout the plugin.',
            ],
            [
                'question' => 'Can more than one administrator use the plugin at the same time?',
                'answer'   => 'Yes — the plugin is fully multi-user. Any Moodle user with the site administrator role or the custom <code>local/rtocompliance:manage</code> capability can access and edit records simultaneously. All changes are written to the Audit Log with the user\'s name and timestamp, so you always know who changed what.',
            ],
            [
                'question' => 'I just installed the plugin and the dashboard shows no data — is something wrong?',
                'answer'   => 'No — this is normal for a fresh install. The dashboard tiles only show counts from records you have created. Start by adding at least one qualification in Qualification Builder, one trainer in the Trainer Register, and one student in Student Records. After that, the relevant dashboard tiles will update within a few minutes (some counts are cached by Moodle for up to 10 minutes).',
            ],
        ],
    ],

    // ── QA1 – TRAINING & ASSESSMENT ────────────────────────────────────────
    [
        'category' => 'QA1 – Training & Assessment',
        'icon'     => 'clipboard-list',
        'faqs'     => [
            [
                'question' => 'How many sections does a TAS need to cover?',
                'answer'   => 'The TAS Generator has 9 sections, which cover everything ASQA expects to see in a Training and Assessment Strategy: qualification details, target cohort, industry consultation evidence, volume of learning, delivery modes, resources and facilities, trainer credentials mapped to units, assessment design, reasonable adjustments, LLN considerations, third-party delivery, transition arrangements, and continuous improvement. All 9 sections must be completed before the system allows you to export.',
            ],
            [
                'question' => 'How often do I need to validate my assessments?',
                'answer'   => 'Standard QA1.5 requires assessment tools and practices to be validated within a 5-year cycle, with high-risk products prioritised for more frequent review. The Validation Schedule uses a risk-based approach — products flagged as high risk are recommended for annual validation. The compliance dashboard shows any overdue validations so nothing slips past the 5-year mark.',
            ],
            [
                'question' => 'What is the difference between RPL and Credit Transfer?',
                'answer'   => 'Recognition of Prior Learning (RPL) involves an assessor evaluating a student\'s existing skills and knowledge against unit competency requirements — it requires an assessment process and a documented evidence portfolio. Credit Transfer (CT) is simpler: it recognises a completed unit from another RTO or institution where the unit code is identical or equivalent, with no reassessment required. Both are recorded in the RPL & Credit Transfer register and appear as AVETMISS outcome codes 51/52 (RPL) and 60 (CT).',
            ],
            [
                'question' => 'Do I need to register every site where I deliver training?',
                'answer'   => 'Yes — Standard QA1.8 requires you to ensure all training and assessment environments are safe and appropriate. ASQA will ask for evidence of this during an audit. Use the Delivery Locations register to record every physical campus, workplace delivery site, and online environment used for training. Include the WHS compliance status and last inspection date for each location.',
            ],
            [
                'question' => 'What happens when a qualification on my scope gets superseded?',
                'answer'   => 'When training.gov.au marks a qualification as superseded or deleted, you have a teach-out period (typically 12 months) during which existing students can complete, but you cannot enrol new students. Open Training Product Transitions and create a Transition Plan for the affected qualification. You can set the teach-out end date and optionally link the Moodle course to automatically close self-enrolment on that date. All current students need a documented transition strategy recorded in the plan.',
            ],
            [
                'question' => 'The training.gov.au fetch is not returning any units — what do I check?',
                'answer'   => 'First, check that the qualification code is correct (e.g. TLI50321 — no spaces, correct version suffix). Second, check your server can reach <code>training.gov.au</code> on port 443 — some hosting providers block outbound SOAP calls. Third, try purging Moodle caches (Site Administration &rarr; Development &rarr; Purge all caches) and retrying. If the problem persists, the training.gov.au SOAP API may be experiencing downtime — check their status page and try again later.',
            ],
            [
                'question' => 'What do the competency outcome codes C, NYC, RPL, and CT mean?',
                'answer'   => 'C = Competent (AVETMISS 20) — the student has demonstrated competency in the unit. NYC = Not Yet Competent (AVETMISS 30) — the student has not yet met the standard. RPL = Recognition of Prior Learning granted (AVETMISS 51). CT = Credit Transfer (AVETMISS 60). A student needs C, RPL, or CT on every unit in a qualification before a Testamur can be issued. In Student Results, units showing these codes are shaded accordingly so you can see at a glance who is ready for certification.',
            ],
            [
                'question' => 'Can I run packaging rules validation before issuing a certificate?',
                'answer'   => 'Yes — in Qualification Builder, click the "Validate Packaging" button on any qualification. The packagingrules validator checks that the unit selection meets the minimum core count, minimum and maximum elective counts, and any prerequisite rules specified in the training package. A green pass means the combination is valid for certification; a red fail shows exactly which rule was not met.',
            ],
            [
                'question' => 'What are teacher-cohort variant courses and why do I need them?',
                'answer'   => 'Many RTOs run the same unit across multiple Moodle courses at the same time — for example TLIX0037 delivered separately by three different trainers (a CD stream, an EL stream, and an ND stream). Without variant course support, only students in the single "primary" linked course get their AVETMISS enrolment record created and their certificate issued automatically. Students in the other streams were invisible to the system. Variant courses solve this: on each unit row in the Qualification Builder you will see small chips for every Moodle course in the semester that shares that unit code — e.g. <strong>[✓ EL] [CD ×] [ND ×] [+ add variant…]</strong>. All chipped courses are watched by the reconciler, so students in any stream get their records and certs automatically.',
            ],
            [
                'question' => 'How do variant course chips work in the Qualification Builder?',
                'answer'   => 'When you select a semester in the Qualification Builder, clicking "Map All Courses" (or changing the semester) auto-detects all Moodle courses whose short name contains the unit code. The primary course gets the green ✓ badge; all others appear as grey chips. Click the × on a chip to remove a course you do not want watched (e.g. an old test course). Use the [+ add variant…] dropdown to manually add a course that was not auto-detected. If you promote a variant to the primary course (by changing the primary dropdown), that course is automatically removed from the chips. Changes only take effect when you click "Save".',
            ],
            [
                'question' => 'How does automatic certificate issuance (autocert) work?',
                'answer'   => 'Every time a Moodle course completion event fires, the enrolment reconciler checks whether the student now has Competent outcomes (C, RPL, or CT) on every unit in the qualification — across the primary linked course and all variant courses. If all units are complete, the system automatically generates and saves a Testamur to the certificate register, sets the student\'s programme outcome to Complete, and records the AVETMISS result code. No manual action is required. The 30-day issuance clock in Compliance Requirements Clause 9(2) begins from the date the final course completion fires.',
            ],
            [
                'question' => 'Can I have two Qualification Builder records for the same qualification code?',
                'answer'   => 'Yes — this is intentional and supported. Use the optional <em>Stream / Variant Name</em> field on the qualification record to distinguish them (e.g. "Evening Intake 26S1", "CD Stream"). The stream label appears as a small badge in the QB list view so staff can tell them apart at a glance. Each record has its own unit–course mappings and variant chips, allowing completely different delivery configurations under the same TGA qualification code.',
            ],
        ],
    ],

    // ── QA2 – STUDENT SUPPORT ──────────────────────────────────────────────
    [
        'category' => 'QA2 – Student Support & Enrolment',
        'icon'     => 'users',
        'faqs'     => [
            [
                'question' => 'What does Standard 2.1 require me to show prospective students?',
                'answer'   => 'Standard QA2.1 requires you to provide accurate, accessible, and up-to-date information about your training products, services, fees, refund policy, complaints process, and the certificate each course leads to — before a student enrols. The Marketing Information register helps you document that your website, brochures, and social media content have been reviewed for accuracy. Keep a record of what was reviewed, who approved it, and when — ASQA will ask for this.',
            ],
            [
                'question' => 'How do I add a new student?',
                'answer'   => 'Navigate to Student Records in the QA2 sidebar group and click "Add Student". Enter the student\'s full legal name, date of birth, contact details, and USI. For nationally recognised training, complete all AVETMISS fields — the system highlights the mandatory fields in red and will not allow the record to be saved with missing mandatory data. Once saved, the student appears in Student Results for any qualification linked to their course enrolment.',
            ],
            [
                'question' => 'What AVETMISS fields are mandatory for every student?',
                'answer'   => 'For nationally recognised training (NRT), NCVER requires 11 fields as mandatory: given name, family name, date of birth, gender, residential address (suburb, state, postcode), country of birth, indigenous status, language spoken at home, highest school level completed, labour force status, and disability/impairment indicator. The student profile completeness checker flags any of these that are missing so you can follow up before your AVETMISS submission deadline.',
            ],
            [
                'question' => 'What is a USI and do all students need one?',
                'answer'   => 'A Unique Student Identifier (USI) is a reference number that creates an online record of an individual\'s nationally recognised VET training. Every student enrolled in NRT must provide a USI before a certificate can be issued (Clause 12 of the USI Act). International students and some other exempt groups may be exempt. Use the USI Verification button on a student\'s profile to confirm their USI against the national registry in real time.',
            ],
            [
                'question' => 'What is the Student Suitability Check for?',
                'answer'   => 'Standard QA2.2 requires RTOs to assess whether a prospective student has the LLN (Language, Literacy and Numeracy) skills and meets the entry requirements for the qualification before they enrol. The Student Suitability Check is a 4-stage digital form sent by email to the student. It collects evidence of entry requirements (Stage 1), shows the LLN assessment result (Stage 2), displays the system\'s suitability decision with plain-language advice (Stage 3), and captures the student\'s signed declaration (Stage 4). This creates an auditable record that your admissions process is fair and transparent.',
            ],
            [
                'question' => 'What is the Student Support System page for — is it per student?',
                'answer'   => 'No — the Student Support page is the organisation-level configuration. Here you set which support services your RTO offers (e.g. language support, counselling, financial assistance, flexible scheduling), which types of reasonable adjustments are available, and your diversity and wellbeing policies. These settings become the options that trainers choose from when they complete a per-student support plan via the Trainer Input page. Think of it as the master menu — trainers select from it for each student.',
            ],
            [
                'question' => 'How do I issue a certificate?',
                'answer'   => 'Navigate to Student Results and find a student who shows 100% completion across all required units. Click the "Issue Certificate" button. Select the certificate type (Testamur for a full qualification, Statement of Attainment for partial, Record of Results, or Certificate of Completion for non-accredited). Select the audience variant if applicable (e.g. apprentice, VET-FEE, international). The PDF is generated using your active custom template and saved to the 30-year certificate register automatically. You can then download it or email it directly to the student.',
            ],
            [
                'question' => 'What is the 30-day rule for issuing certificates?',
                'answer'   => 'Compliance Requirements Clause 9(2) requires RTOs to issue AQF qualifications and statements of attainment within 30 calendar days of the student meeting all requirements for the certification. The Certificates module tracks the gap between the date of completion and the date of issue. Any certificate that took more than 30 days to issue is flagged as "Issued Late" on the dashboard — this is an ASQA compliance breach if it occurs regularly.',
            ],
            [
                'question' => 'What are the four certificate types and when do I use each one?',
                'answer'   => '<strong>Testamur</strong> — issued when a student completes all required units in a nationally recognised qualification. This is the actual qualification certificate. <strong>Statement of Attainment (SOA)</strong> — issued when a student completes one or more nationally recognised units but not a full qualification. <strong>Record of Results (ROR)</strong> — a supplementary document listing all units attempted and their outcomes; often issued alongside a Testamur. <strong>Certificate of Completion</strong> — for non-accredited (non-NRT) training where no AQF qualification is awarded.',
            ],
            [
                'question' => 'What mandatory elements must appear on an AQF certificate?',
                'answer'   => 'Per the AQF Certification Documentation specification and the ASQA Sample Forms fact sheet, a Testamur must include: the RTO\'s registered legal name and RTO number; the AQF certification logo; the NRT logo; the student\'s full legal name; the qualification code and full title exactly as it appears on training.gov.au; the words "This qualification is recognised within the Australian Qualifications Framework"; the issue date; and the authorised signatory\'s name and title. The Certificate Templates validator will flag any missing mandatory element before allowing a template to be approved.',
            ],
            [
                'question' => 'Can I have a different certificate design for each certificate type?',
                'answer'   => 'Yes — the Certificate Templates module supports separate active templates for each of the four certificate types (Testamur, SOA, Record of Results, Certificate of Completion) and up to nine audience variants (general, apprentice, VET-FEE, international, traineeship, school-based, recognition, workplace, fee-for-service). Each combination can have its own approved template. If no custom template is active for a particular type/audience combination, the system falls back to the built-in ASQA-compliant default.',
            ],
            [
                'question' => 'How do I preview what my certificate will look like before issuing real ones?',
                'answer'   => 'Use the Test Certificate Generator (QA2 sidebar group &rarr; Test Certificate). Select the certificate type, choose the audience variant, and click "Generate Test PDF". The test uses the exact same rendering pipeline as a live certificate — your active custom template, your uploaded logos, and real field positions — but uses a synthetic student name and does not save anything. Use this every time you update your template design to confirm it looks correct before it goes to real students.',
            ],
            [
                'question' => 'What NAT files do I need to generate for NCVER?',
                'answer'   => 'For a standard AVETMISS collection you need 10 NAT files: NAT00010 (training organisation), NAT00020 (training organisation delivery locations), NAT00030 (qualification/course), NAT00060 (subject/unit), NAT00080 (client), NAT00085 (disability), NAT00090 (prior educational achievement), NAT00100 (enrolment), NAT00120 (subject enrolment), and NAT00130 (outcome). The NAT Export module generates all 10 as a ZIP file ready for submission to your State Training Authority or NCVER directly.',
            ],
            [
                'question' => 'Can I import student data from my previous student management system?',
                'answer'   => 'Yes — use Data Import. It accepts two formats: a NAT file set (if your old system can export AVETMISS-compliant NAT files) or the plugin\'s own CSV import template. The importer validates every row before writing to the database — it checks for duplicate USIs, invalid AVETMISS codes, missing mandatory fields, and date format errors. A preview screen shows exactly how many records will be created, updated, or skipped before you confirm the import.',
            ],
            [
                'question' => 'How do I send a Quality Indicator survey to students?',
                'answer'   => 'Open Quality Indicator Surveys from the QA2 sidebar group and click "Send Survey". Select either Learner Engagement or Employer Satisfaction as the survey type, then choose the recipients (individual students, all students in a course, or all completions within a date range). Students receive an email with a unique link to their survey — no Moodle login is required to respond. Results appear in the Reports tab as they come in. Export to CSV for your annual NCVER QI submission.',
            ],
            [
                'question' => 'How do I record a formal complaint?',
                'answer'   => 'Open Complaints & Appeals and click "Add Complaint" on the Complaints tab. Complete the complaint form — you can mark it as anonymous if the complainant has requested confidentiality. Select a category (academic, administrative, fees, discrimination, etc.), assign an investigator, and set a resolution target date. ASQA expects complaints to be acknowledged within 5 business days and resolved within 60 days. Document all investigation notes and the final outcome in the system. If the issue is systemic, use the Improvement tab to create a linked improvement action.',
            ],
            [
                'question' => 'What is the difference between a complaint and an appeal?',
                'answer'   => 'A complaint is about any aspect of your RTO\'s services, products, staff, or facilities — it can come from a student, employer, or member of the public. An appeal is specifically a formal challenge to an assessment decision — a student who believes they were assessed unfairly. Both are managed through the Complaints & Appeals module but on separate tabs, and both feed into your continuous improvement register. ASQA requires written policies covering both, separate processes, and records of all matters received and resolved.',
            ],
        ],
    ],

    // ── QA3 – VET WORKFORCE ────────────────────────────────────────────────
    [
        'category' => 'QA3 – VET Workforce',
        'icon'     => 'user-check',
        'faqs'     => [
            [
                'question' => 'What TAE qualifications must trainers hold?',
                'answer'   => 'Under Standard QA3.2, trainers who design and deliver training must hold at minimum a TAE40116 (or TAE40122) Certificate IV in Training and Assessment, or a higher-level qualification in adult education. Trainers who only assess (roles 2A–2C) must hold both a relevant Skill Set from the TAE Training Package and the TAESS00001 or TAESS00011 assessor skill set, or a TAE40116/40122 qualification. There is no exemption — every trainer and assessor in your register must meet these requirements, or be currently working towards them under supervision.',
            ],
            [
                'question' => 'What are the trainer role classifications (1A through 3B)?',
                'answer'   => 'The plugin uses ASQA\'s role matrix: <strong>1A</strong> — holds TAE Cert IV + vocational competency + industry currency (can train and assess). <strong>1B</strong> — holds higher adult education qual + vocational competency + currency (can train and assess). <strong>1C</strong> — holds TAE Cert IV but vocational competency is current practice only (must be supervised by 1A/1B for assessment). <strong>1D</strong> — significant industry experience, no TAE yet (can train under supervision, cannot assess). <strong>1E</strong> — working towards TAE (must be supervised for all delivery). <strong>2A/2B/2C</strong> — assessors with varying levels of TAE completion (cannot deliver training, only assess). <strong>3A/3B</strong> — validators (review assessment tools and practices, typically 1A or 1B holders with additional validation experience).',
            ],
            [
                'question' => 'What is vocational competency and how do I record it?',
                'answer'   => 'Vocational competency means the trainer has relevant skills and knowledge in the vocation they are training — they are not just qualified to train, they also know their subject matter. ASQA accepts several types of evidence: a formal qualification in the relevant field, demonstrated industry experience (usually 3+ years), participation in professional development activities, or a combination. In the Trainer Register, click on a trainer and use the Vocational Competency section to select the evidence types they hold and record the details. These records must be kept current.',
            ],
            [
                'question' => 'What is industry currency and how often does it need updating?',
                'answer'   => 'Industry currency means the trainer has maintained up-to-date knowledge of current industry practices — they are not teaching outdated methods. ASQA expects trainers to have engaged with their industry within the last 12–24 months, usually through workplace visits, professional memberships, short courses, or attending industry events. Use the Industry Currency tab on a trainer\'s profile to record each currency activity with its date. The dashboard flags trainers whose last recorded currency activity is more than 12 months old.',
            ],
            [
                'question' => 'How often should trainer credentials be reviewed?',
                'answer'   => 'Best practice under Standard QA3.2 is an annual credential review for every trainer and assessor. During each review, confirm that their TAE qualification is still current (some units have expiry requirements), that vocational competency evidence is documented and recent, and that industry currency activities have been recorded within the last 12 months. Set the "Next Review Date" on each trainer\'s profile — overdue reviews are flagged in red on the compliance dashboard and in the Trainers & Assessors module.',
            ],
            [
                'question' => 'What is the Supervision Log for?',
                'answer'   => 'When a trainer is in roles 1C, 1D, 1E, 2B, or 2C — meaning they are still working towards a full TAE qualification or have limited assessment rights — the RTO must supervise their delivery and/or assessment and document that supervision. The Supervision Log records each session: who supervised whom, the date, how long the session ran, what activities were covered, and the supervisor\'s sign-off. These records are the evidence ASQA needs to confirm you are not leaving unqualified trainers to assess students unsupervised.',
            ],
            [
                'question' => 'What does ASQA look for in Standard QA3.2 during an audit?',
                'answer'   => 'ASQA auditors will check: (1) that every trainer and assessor in your Trainer Register holds the required TAE qualification for their role classification; (2) that vocational competency evidence exists for each trainer, mapped to the qualifications they deliver; (3) that industry currency activities are documented and current; (4) that any trainer working under supervision has a Supervision Log; and (5) that your VET Workforce Management register shows you have sufficient staffing to meet your scope. The VET Workforce Management page in this plugin is designed to generate the evidence for item 5.',
            ],
        ],
    ],

    // ── QA4 – GOVERNANCE ───────────────────────────────────────────────────
    [
        'category' => 'QA4 – Governance & Quality',
        'icon'     => 'building',
        'faqs'     => [
            [
                'question' => 'What is the Annual Declaration of Compliance?',
                'answer'   => 'The Annual Declaration of Compliance (ADC) is a statutory declaration that the CEO or equivalent of your RTO must submit to ASQA every year — typically due by 30 June. It declares that your RTO has complied with the Standards for RTOs 2015 throughout the preceding year. Use the Governance module to record the ADC lodgement date and ASQA reference number each year. ASQA will check this record during any audit.',
            ],
            [
                'question' => 'Who counts as a "governing person" and do I need to register them all?',
                'answer'   => 'A governing person is any individual with significant influence over your RTO\'s operations — directors, trustees, partners, and senior managers (CEO, CFO, RTO Manager). Standard QA4.1 requires all governing persons to be "fit and proper" (no relevant convictions, not a disqualified person under the NVETR Act). Use the Governing Persons register in the Governance module to record each person\'s name, role, appointment date, and fit-and-proper declaration status.',
            ],
            [
                'question' => 'What is a material change and when must I notify ASQA?',
                'answer'   => 'A material change is any significant change to your RTO\'s structure, operations, or compliance that ASQA needs to know about — for example: a change in ownership or controlling entity, a change in CEO or RTO Manager, adding or removing a principal delivery location, a significant change in scope, or a legal/financial issue affecting your ability to operate. The NVETR Act requires notification to ASQA within 90 days (for most changes) or immediately for urgent matters. Record all material changes in the Governance module\'s Material Changes tab so you have evidence of timely notification.',
            ],
            [
                'question' => 'How do I record and rate a risk?',
                'answer'   => 'Open Risk Management and click "Add Risk". Give the risk a clear title (e.g. "Key trainer leaving with no succession plan"), select the category (strategic, operational, financial, or compliance), and write a brief description. Rate the likelihood (1–5 scale) and the consequence (1–5 scale) — the system multiplies these to calculate an overall risk rating. Write a treatment plan describing what you will do to reduce the risk, assign a risk owner, and set a review date. High-rated risks (rating of 15+) are flagged in red on the compliance dashboard.',
            ],
            [
                'question' => 'What continuous improvement evidence does ASQA expect?',
                'answer'   => 'Standard QA4.4 requires your RTO to have a systematic continuous improvement system. ASQA auditors look for: (1) Quality Indicator data collected from learners and employers and submitted to NCVER; (2) improvement actions that were triggered by complaints, appeals, survey feedback, or self-assurance activities; (3) evidence that those actions were actually implemented and their effectiveness reviewed; and (4) the cycle repeating each year. The Complaints & Improvement module, the QI Surveys module, and the ASQA Practice Guides self-assurance tool together provide this evidence trail.',
            ],
        ],
    ],

    // ── COMPLIANCE STANDARDS ───────────────────────────────────────────────
    [
        'category' => 'Compliance Standards',
        'icon'     => 'shield',
        'faqs'     => [
            [
                'question' => 'What counts as a third-party arrangement?',
                'answer'   => 'Any agreement where another organisation delivers training or assessment on your RTO\'s behalf — or manages your students — counts as a third-party arrangement under Compliance Requirements, Division 3 Clause 17. This includes subcontractors who deliver in workplaces, auspiced arrangements, licensed trainer networks, and online content providers. Every such arrangement must be covered by a written agreement, and your RTO must monitor the quality of the delivery. The Third-Party Arrangements register is where you record these agreements and document your quality oversight activities.',
            ],
            [
                'question' => 'What is the $1,500 prepaid fee rule?',
                'answer'   => 'Compliance Requirements, Division 3 Clause 18 of the Standards for RTOs 2025 prohibits RTOs from collecting more than $1,500 in prepaid fees from any individual student before the commencement of the training they have paid for. This protects students from losing large sums if the RTO closes. You can collect the full course fee in advance — but only up to $1,500 before training starts, with the remainder collected progressively as training proceeds. The Fee Protection module tracks prepaid balances so you never accidentally breach the $1,500 threshold.',
            ],
            [
                'question' => 'Can I collect more than $1,500 if I have a fee protection mechanism in place?',
                'answer'   => 'Yes — there is an exemption if you hold an approved fee protection mechanism. This typically means having an approved trust account, bank guarantee, or professional indemnity arrangement specifically for student fee protection. If your RTO is approved for a fee protection mechanism, record this in the Fee Protection module settings. The module will then allow you to record prepaid fees above $1,500 as protected rather than flagging them as a compliance breach.',
            ],
            [
                'question' => 'What insurance must an RTO hold?',
                'answer'   => 'Compliance Standard 8 requires RTOs to hold insurance adequate to their operations. At minimum this means: <strong>Public Liability</strong> insurance (typically at least $20 million cover) to protect against claims from students, visitors, and the public; <strong>Professional Indemnity</strong> insurance to protect against claims arising from your training and assessment services. Depending on your structure, you may also need Workers Compensation and Building/Contents insurance. The Insurance Register tracks all policies, coverage amounts, and expiry dates, and alerts you 60 days before any policy expires.',
            ],
        ],
    ],

    // ── AVETMISS & REPORTING ───────────────────────────────────────────────
    [
        'category' => 'AVETMISS & Reporting',
        'icon'     => 'database',
        'faqs'     => [
            [
                'question' => 'When do I need to submit AVETMISS data to NCVER?',
                'answer'   => 'Submission deadlines depend on your funding type. For government-funded training, most State Training Authorities require quarterly or monthly data submissions. For fee-for-service (non-funded) activity, NCVER\'s Total VET Activity (TVA) collection is typically due by 28 February each year, covering the previous calendar year. Check with your State Training Authority for your specific deadline — it varies by state. Start your AVETMISS export in this plugin at least 2 weeks before your deadline so you have time to resolve any validation errors.',
            ],
            [
                'question' => 'What AVETMISS outcome codes does the plugin use?',
                'answer'   => 'The plugin uses only the NCVER-approved codes from AVETMISS VET Provider Collection Specifications Release 8.0: <strong>20</strong> — Competent (C); <strong>30</strong> — Not Yet Competent (NYC); <strong>40</strong> — Withdrawn; <strong>51</strong> — RPL Granted; <strong>52</strong> — RPL Not Granted; <strong>60</strong> — Credit Transfer; <strong>70</strong> — Continuing Enrolment. Non-standard codes (65, 53, 54, 66, 90) that appeared in older versions have been removed.',
            ],
            [
                'question' => 'My NAT export has validation errors — what do I do?',
                'answer'   => 'The most common causes are: (1) students with missing AVETMISS fields — check the Student Records completeness indicator and fill in any gaps; (2) enrolments still coded as "70 Continuing" from a previous year — these should be updated to their final outcome (20/30/40/51/52/60) before export; (3) an invalid qualification code — confirm the code still exists on training.gov.au as an active qualification; (4) a USI that failed verification — the student may have provided an incorrect USI. After fixing issues, purge Moodle caches and re-run the export.',
            ],
            [
                'question' => 'What is the difference between the government-funded and fee-for-service collections?',
                'answer'   => 'The government-funded collection includes all training subsidised by your State Training Authority — it must include client-level data (NAT00080, NAT00085, NAT00090) for every enrolled student. The fee-for-service (Total VET Activity) collection covers all other NRT training your RTO delivers. Some fields that are optional in fee-for-service (like disability information) are mandatory in government-funded. The NAT Export module lets you select which collection type you are generating, and adjusts the validation rules accordingly.',
            ],
            [
                'question' => 'Can I resubmit AVETMISS data after I have already submitted?',
                'answer'   => 'Yes — you can regenerate the NAT files at any time and submit a revised collection. Most collection portals (including NCVER\'s direct submission portal and State Training Authority portals) support replacement submissions. Simply generate a new set of NAT files, fix the errors, and submit the corrected files. Keep a copy of both the original and corrected submission in your records.',
            ],
        ],
    ],

    // ── CERTIFICATES & TEMPLATES ───────────────────────────────────────────
    [
        'category' => 'Certificates & Templates',
        'icon'     => 'award',
        'faqs'     => [
            [
                'question' => 'How long must certificate records be kept?',
                'answer'   => 'AQF certification documentation (certificates and statements of attainment) must be retained for 30 years from the date of issue. Student enrolment records and training activity data must be retained for a minimum of 7 years. The Certificates register in this plugin is designed to be your permanent 30-year record. If your RTO ceases to operate, you are required to transfer your certificate records to NCVER\'s National VET Data collection.',
            ],
            [
                'question' => 'What is USI Clause 12 and why does it appear as a warning on certificates?',
                'answer'   => 'Clause 12 of the Student Identifiers Act 2014 states that an RTO must not issue a VET qualification or statement of attainment to a student unless their USI has been verified. The warning appears when a student\'s USI has not been verified against the national registry before the certificate is generated. It is a warning, not a hard block — the system still generates the certificate, but the warning is logged. You should verify the USI as soon as possible and reissue or annotate the certificate record. Knowingly issuing a certificate without a verified USI is a statutory breach.',
            ],
            [
                'question' => 'How do I fix the NRT logo on my certificates?',
                'answer'   => 'The plugin includes the official ASQA-issued NRT mark (red/green chevron triangle with Fritz Quadrata-style lettering) as the default NRT logo. If your certificates are showing a blank or incorrect NRT mark, it may be because a custom template is active that references an old or missing logo file. Open Certificate Templates, edit the active template, and use the Branding panel to re-upload or re-select the NRT logo. Use the Test Certificate Generator to confirm it renders correctly before reissuing.',
            ],
            [
                'question' => 'My certificate PDF is blank or not rendering — what do I check?',
                'answer'   => 'Try the following in order: (1) Open Test Certificate Generator and test with the same certificate type and audience — if the test is also blank, the issue is with the template, not the student data. (2) Check that the active template for that type/audience has all required fields mapped. (3) Check that the template\'s logo image files still exist (go to Certificate Templates and re-upload if missing). (4) If the test certificate renders correctly but the live one does not, check that the student record has a full legal name and completion date. (5) If using the legacy renderer fallback, check your server\'s TCPDF installation. Contact support if none of these resolve the issue.',
            ],
            [
                'question' => 'Can I email a certificate directly to a student from the plugin?',
                'answer'   => 'Yes — in the Certificates register, find the certificate record and click the "Email" button (envelope icon). The system generates a fresh PDF and attaches it to an email sent to the student\'s Moodle email address. The email uses a standard template that includes your RTO name and a link to the public QR code verification page. You can also download the PDF and email it manually from your own email client if you prefer.',
            ],
        ],
    ],

    // ── SECURITY & DATA ────────────────────────────────────────────────────
    [
        'category' => 'Security & Data Protection',
        'icon'     => 'lock',
        'faqs'     => [
            [
                'question' => 'Can an attacker delete my compliance records by sending me a crafted link?',
                'answer'   => 'No. All destructive admin actions — deleting qualifications, enrolments, units, and running any write operations — are protected with Moodle\'s session key (require_sesskey()). Even if someone sends you a URL designed to trigger a deletion, clicking it will not modify any data because the request does not carry a valid session key. This protection covers the CSRF (Cross-Site Request Forgery) vulnerability class across all admin pages in the plugin.',
            ],
            [
                'question' => 'Is student PII protected on the public certificate verification page?',
                'answer'   => 'Yes. The public QR code verification page shows only the student\'s first name and last initial — for example "Jane S." — not the full surname. The certificate number, qualification title, issue date, and RTO details are shown in full so employers can confirm authenticity. The student\'s full name, date of birth, USI, and address are never exposed on the public verification page.',
            ],
            [
                'question' => 'What is the difference between the Compliance Log and the Audit Log?',
                'answer'   => 'The Compliance Log records user-facing compliance actions — issuing a certificate, creating a student record, submitting a complaint, adding a trainer, etc. The Audit Log records security-significant administrative events — login events, bulk operations, setting changes, and data deletions. Both logs include a timestamp and the name of the Moodle user who performed the action. During an ASQA audit, both logs serve as evidence of your compliance management activities. Both logs are pruned on separate retention schedules configured in plugin settings.',
            ],
            [
                'question' => 'How long are audit logs retained?',
                'answer'   => 'By default, the Compliance Log is retained for 7 years and the Audit Log is retained for 7 years. These schedules can be changed in plugin settings (Site Administration &rarr; AI RTO Compliance &rarr; Settings &rarr; Data Retention). For compliance purposes, we recommend keeping both logs for at least 7 years — ASQA can conduct audits up to 5 years retrospectively, and some state funding agreements require 7 years.',
            ],
            [
                'question' => 'Where is compliance data stored — is it sent to any external service?',
                'answer'   => 'All compliance data (student records, certificates, trainer credentials, complaints, etc.) is stored exclusively in your Moodle site\'s database. The only outbound connections the plugin makes are: (1) training.gov.au SOAP API for qualification lookups (read-only, no student data sent); (2) the USI registry for USI verification (student\'s name and USI are sent — this is a legal requirement); (3) the AI generation features if configured (sends only the text you choose to generate against — no student PII). No compliance data is stored or sent to any Replit or third-party cloud service.',
            ],
        ],
    ],

    // ── STATE FUNDING ──────────────────────────────────────────────────────
    [
        'category' => 'State Funding',
        'icon'     => 'target',
        'faqs'     => [
            [
                'question' => 'Do I need to set up state funding if I only deliver fee-for-service training?',
                'answer'   => 'No. The state funding fields (school type, concession status, purchasing contracts) are completely optional. They only appear on student profile and enrolment forms when you have entered data in the State Funding settings page. If your RTO only delivers private fee-for-service (non-government-funded) training, leave the State Funding page blank and your forms and AVETMISS export will be unchanged.',
            ],
            [
                'question' => 'Where do I enter our QLD DTET purchasing contract codes?',
                'answer'   => 'Go to <strong>Site Administration &rarr; AI RTO Compliance &rarr; State Funding</strong> and scroll to the Queensland section. Enter your QLD RTO ID (issued by DTET — different from your national ASQA RTO code) and up to three purchasing contract codes in the format QS###### (e.g. QS102922). Once saved, these codes appear as a dropdown on enrolment forms for QLD state-funded students so staff can select the correct contract rather than typing it manually. The selected code exports to NAT00120 field 17.',
            ],
            [
                'question' => 'What are the QLD school sector codes and when do I use them?',
                'answer'   => 'School sector codes are required for school-based apprenticeship and VETiS enrolments in Queensland. The valid codes are: <strong>B01</strong> (State/government school), <strong>S01</strong> (Catholic school), <strong>QL1</strong> (QLD general), <strong>QC1</strong> (QLD Catholic sector), <strong>UC1</strong> (UCLES school), <strong>B11</strong> (Other non-government school), <strong>B02</strong> (Other government school), <strong>VE1</strong> (VET in Schools / VETiS), <strong>QNS</strong> (Not school-based). These appear on the enrolment form for QLD-funded students when the student is also flagged as "At School".',
            ],
            [
                'question' => 'What does the "School type" field on the student profile do?',
                'answer'   => 'The School type field (GOV / CAT / IND / OTH) identifies the sector of the secondary school the student currently attends. It only appears on the student profile form when the student is flagged as <em>At School</em>. It is a mandatory field for school-based apprenticeship and VETiS enrolments in both QLD (DTET) and VIC (Skills First) state funding submissions and maps to NAT00080 field 14. Leave it blank for students who are not currently at school.',
            ],
            [
                'question' => 'What do the concession status codes F, C, and E mean?',
                'answer'   => 'These codes appear on the enrolment form for state-funded students and map to NAT00120 field 15 (Concession type identifier). <strong>F &mdash; Full fee exempt:</strong> the student pays no tuition fee under a state exemption category (e.g. welfare recipient, Indigenous student under an exemption program). <strong>C &mdash; Concession card holder:</strong> the student holds a valid Health Care Card or Pensioner Concession Card and is entitled to a reduced tuition fee. <strong>E &mdash; Eligible individual:</strong> the student meets the eligibility criteria for the funded program (e.g. Smart &amp; Skilled priority cohort, Skills First target group) but does not hold a concession card. Leave blank for full-fee students.',
            ],
            [
                'question' => 'How do the NSW Smart & Skilled funding source codes (22–26) work?',
                'answer'   => 'NSW uses numeric codes for the state funding source field in NAT00120. The codes supported in the plugin are: <strong>22</strong> (Smart &amp; Skilled Standard), <strong>23</strong> (Smart &amp; Skilled Fee-Free), <strong>24</strong> (Smart &amp; Skilled Higher Skills), <strong>25</strong> (NSW other state funding), <strong>26</strong> (NSW Foundation Skills). Select the appropriate code in the NSW section of the State Funding settings page as the default for NSW-funded enrolments. Individual enrolments can override the default.',
            ],
            [
                'question' => 'What are the VIC Skills First funding source codes?',
                'answer'   => 'Victoria\'s Skills First program uses alphanumeric codes: <strong>VSKI</strong> (Skills First General), <strong>VHLS</strong> (Higher Level Skills), <strong>VLLN</strong> (Language, Literacy and Numeracy), <strong>VFFS</strong> (Fee-Free TAFE), <strong>VAPP</strong> (Apprenticeships and Traineeships), <strong>VVIS</strong> (VETiS — Victorian schools). Configure your VIC default funding source code in the VIC section of the State Funding settings page. Data is submitted to the Victorian Department of Jobs, Skills, Industry and Regions (DJSIR) via SVTS.',
            ],
            [
                'question' => 'My RTO delivers in two or more states — how do I manage different funding systems?',
                'answer'   => 'The State Funding settings page has a separate, independent section for each state and territory. Configure each state you deliver in separately — QLD RTO ID and contracts go in the QLD section, NSW codes in the NSW section, and so on. On each enrolment form, the staff member selects the funding source state code first. The form then shows only the contracts and codes applicable to that state. If a student has a QLD-funded enrolment and a separate NSW-funded enrolment, each enrolment can carry its own state-specific data.',
            ],
            [
                'question' => 'How does state funding data flow into my AVETMISS NAT export?',
                'answer'   => 'All state funding fields are stored on the student and enrolment records and are included automatically when you generate NAT files. There is no extra export step. School type exports to NAT00080 field 14. Concession status exports to NAT00120 field 15. Purchasing contract code exports to NAT00120 field 17. Funding source state code exports to NAT00120 field 18. Run the pre-export validation check in the NAT Export module to confirm all required state funding fields are populated before submitting to your State Training Authority.',
            ],
        ],
    ],

    // ── TROUBLESHOOTING ────────────────────────────────────────────────────
    [
        'category' => 'Troubleshooting',
        'icon'     => 'tool',
        'faqs'     => [
            [
                'question' => 'A trainer shows as "Credential Expired" on the dashboard — what do I do?',
                'answer'   => 'Open the Trainer Register and click on the trainer. The Credentials tab will show which credential has expired (TAE qualification, vocational competency review, or industry currency). Update the record with the renewed credential details and a new expiry date. If the trainer has not yet renewed, document the situation in the trainer\'s notes section and set a follow-up date. The dashboard tile will update to amber (upcoming expiry) or green (compliant) within the next cache refresh cycle (up to 10 minutes).',
            ],
            [
                'question' => 'The USI verification is returning an error — what does it mean?',
                'answer'   => 'The most common USI verification errors are: <strong>"USI not found"</strong> — the USI the student provided does not exist in the national registry, or it does not match their legal name. Ask the student to log in to usi.gov.au and confirm their USI and the name on their account. <strong>"Name mismatch"</strong> — the name in your student record does not exactly match the name registered against that USI (including middle names and hyphens). <strong>"Service unavailable"</strong> — the USI registry is temporarily down; try again later. Check your RTO\'s USI certificate and settings in Site Administration &rarr; AI RTO Compliance &rarr; USI Settings if errors persist.',
            ],
            [
                'question' => 'The compliance dashboard is showing stale numbers — how do I refresh it?',
                'answer'   => 'Some dashboard counts are cached by Moodle for up to 10 minutes to reduce database load. For an immediate refresh: go to Site Administration &rarr; Development &rarr; Purge all caches and click "Purge all caches". Then reload the dashboard. If counts are still wrong after a cache purge, check that the relevant scheduled task (Site Administration &rarr; Server &rarr; Scheduled tasks &rarr; Refresh compliance metrics) has run recently.',
            ],
            [
                'question' => 'The sidebar navigation is not showing some modules — how do I fix it?',
                'answer'   => 'The left-hand sidebar in the plugin is rendered by the lib.php navigation hook, which only runs when the user has the <code>local/rtocompliance:manage</code> capability. If you are logged in as a site administrator and still see missing items, try: (1) purging Moodle caches; (2) checking you are accessing the plugin from a URL under <code>/local/rtocompliance/</code> (the nav hook only activates on plugin pages); (3) confirming the plugin is installed correctly in Site Administration &rarr; Plugins &rarr; Local plugins.',
            ],
            [
                'question' => 'I upgraded the plugin and some tables seem to be missing — what happened?',
                'answer'   => 'This usually means the Moodle upgrade process did not run the plugin\'s upgrade.php script. Go to Site Administration &rarr; Notifications — if Moodle sees a version mismatch it will prompt you to run the upgrade. Click "Upgrade Moodle database now" and follow the prompts. If the notifications page shows everything is up to date but tables are still missing, contact support with the version number you upgraded from and the error message from the Moodle upgrade log.',
            ],
        ],
    ],
];

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
