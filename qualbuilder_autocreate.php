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
 * RTO Compliance plugin — qualbuilder_autocreate.php.
 *
 * Auto-Create Qualifications from the Moodle category tree. Reads the category tree
 * (parent category = qualification code + title, leaf category = unit code + name, with
 * the delivery courses underneath), previews the qualifications it can build, and — on
 * confirmation — creates the selected Qual Builder products. Writes ONLY to the plugin's
 * own qualbuilder / qualunits / qualunit_courses tables: no Moodle categories, courses,
 * accounts or enrolments are ever created or changed.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_qualbuilder');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
// v5.9.443: default to the Course Map — it is the already-seeded source of truth (course →
// qual → unit), whereas the category-tree walk only works when units are named sub-categories
// (this RTO delivers units as courses, so that source finds nothing).
$source = optional_param('source', 'coursemap', PARAM_ALPHA);
if (!in_array($source, ['categories', 'coursemap'], true)) {
    $source = 'coursemap';
}

$PAGE->set_url('/local/rtocompliance/qualbuilder_autocreate.php');
$PAGE->set_title('Auto-Create Qualifications');
$PAGE->set_heading('Auto-Create Qualifications');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add('Auto-Create Qualifications');

// ── CREATE (POST) ─────────────────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    \core_php_time_limit::raise(300);
    $codes  = optional_param_array('codes', [], PARAM_ALPHANUMEXT);
    $usetga = optional_param('usetga', 0, PARAM_INT) ? true : false;

    $summary = local_rtocompliance_autocreate_quals_from_scan($codes, $usetga, $source);

    $created = count($summary['created']);
    $units   = array_sum(array_map(function ($c) {
        return (int)$c['unitcount'];
    }, $summary['created']));
    $skipped = count($summary['skipped']);
    $errors  = count($summary['errors']);

    $msg = 'Auto-Create: ' . $created . ' qualification(s) created with ' . $units . ' unit(s)'
        . ($skipped ? ', ' . $skipped . ' already existed' : '')
        . ($errors ? ', ' . $errors . ' error(s)' : '') . '.';

    redirect(new moodle_url('/local/rtocompliance/qualbuilder.php'),
        $msg, null,
        $errors ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
}

// ── PREVIEW (GET) ─────────────────────────────────────────────────────────────
\core_php_time_limit::raise(120);
$scan = ($source === 'coursemap')
    ? local_rtocompliance_scan_coursemap_for_quals()
    : local_rtocompliance_scan_categories_for_quals();

$newquals = array_values(array_filter($scan, function ($q) {
    return empty($q['exists']);
}));
$existing = array_values(array_filter($scan, function ($q) {
    return !empty($q['exists']);
}));

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Auto-Create Qualifications', null, null, 'qualbuilder');
echo local_rtocompliance_page_banner('Auto-Create Qualifications',
    'Build Qualification Builder products from your existing course data.');

echo html_writer::start_div('', ['style' => 'max-width:1100px;']);

// Source selector — build from the Course Map you already have, or from the category tree.
$caturl = new moodle_url('/local/rtocompliance/qualbuilder_autocreate.php', ['source' => 'categories']);
$mapurl = new moodle_url('/local/rtocompliance/qualbuilder_autocreate.php', ['source' => 'coursemap']);
echo '<div class="filter-tabs" style="margin-bottom:14px;">';
echo html_writer::link($caturl, 'From Category Tree',
    ['class' => 'btn btn-sm ' . ($source === 'categories' ? 'btn-primary' : 'btn-secondary')]);
echo ' ' . html_writer::link($mapurl, 'From Course Map',
    ['class' => 'btn btn-sm ' . ($source === 'coursemap' ? 'btn-primary' : 'btn-secondary')]);
echo '</div>';

// Intro / safety note (source-aware).
$srcexplain = ($source === 'coursemap')
    ? 'This builds your qualifications from the <strong>Course Map</strong> — the source of truth you already '
        . 'seeded from your Moodle categories and courses (course → unit → qualification). It groups those rows into '
        . 'qualifications, links each unit\'s delivery courses, and reads the qualification and unit names from your '
        . 'category names. <strong>This is the recommended source.</strong> (Tick the training.gov.au option to pull '
        . 'official TGA names and packaging instead.)'
    : 'This walks your Moodle <strong>category tree</strong> directly, treating each qualification-code category as a '
        . 'qualification and its unit-code <em>sub-categories</em> as its units. Use this only if your units are named '
        . 'sub-categories — if you deliver units as <em>courses</em> (as your site does), use <strong>From Course '
        . 'Map</strong> above instead, which already has that mapping.';
echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#6366f1;margin-bottom:18px;">'
    . '<strong style="font-size:16px;">How this works</strong><br>'
    . '<span style="font-size:15px;color:#475569;line-height:1.65;">'
    . $srcexplain
    . ' It <strong>only creates records in this plugin</strong> (as <em>Draft</em> products) — it never creates or '
    . 'changes Moodle categories, courses, accounts or enrolments. Review the preview below and tick the '
    . 'qualifications you want to build.'
    . '</span></div>';

// ARCHIVE-ROUTE (v5.9.448): this tool builds ONE product per national qualification
// code — every S1/S2 archive (semester) copy of a qualification collapses into that
// single code, so they show as one row (and skip if already built). Point the admin
// at the Semester Intake Builder, which lists every semester/archive sub-category as
// its own product. This is the #1 point of confusion ("where are my archive versions?").
echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#d97706;background:#fffbeb;margin-bottom:22px;">'
    . '<strong style="font-size:16px;color:#92400e;">Looking for your S1 / S2 archive versions?</strong><br>'
    . '<span style="font-size:15px;color:#78350f;line-height:1.65;">'
    . 'This page builds <strong>one product per qualification code</strong>, so every semester copy of a '
    . 'qualification (e.g. <code>ABC12345</code> S1&nbsp;26, S2&nbsp;26, S1&nbsp;27&nbsp;…) is grouped into that '
    . 'single code — you\'ll only ever see one row per code here, and it skips once a product exists. To create a '
    . '<strong>separate product for every semester/archive intake</strong>, use the '
    . '<a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_semester.php'))->out(false) . '"><strong>Semester '
    . 'Intake Builder</strong></a> — it lists every semester sub-category with its exact course list and reads the '
    . 'qualification code and title from the parent category.'
    . '</span>'
    . '<div style="margin-top:10px;">'
    . html_writer::link(new moodle_url('/local/rtocompliance/qualbuilder_semester.php'),
        'Open Semester Intake Builder →', ['class' => 'btn btn-sm btn-warning'])
    . '</div>'
    . '</div>';

if (empty($newquals) && empty($existing)) {
    if ($source === 'coursemap') {
        echo '<div class="alert alert-info" style="font-size:15px;line-height:1.6;">Your Course Map has no rows yet. '
            . 'Open <a href="' . (new moodle_url('/local/rtocompliance/course_map.php'))->out(false) . '">Moodle Course Map</a> '
            . 'and click <strong>Scan &amp; Seed All Quals</strong> first, then come back here.</div>';
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }
    echo '<div class="alert alert-info" style="font-size:15px;line-height:1.6;">No qualifications could be detected in your category tree. '
        . 'This tool expects categories named with a qualification code (e.g. <code>ABC12345 — Diploma of '
        . 'a qualification</code>) that contain unit-code sub-categories (e.g. <code>ABC12345 — Load and '
        . 'unload goods</code>). If your courses are organised differently, build products manually in the '
        . 'Qualification Builder.</div>';
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ── New qualifications form ───────────────────────────────────────────────────
if (!empty($newquals)) {
    echo '<form method="post" action="qualbuilder_autocreate.php">';
    echo '<input type="hidden" name="action" value="create">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="source" value="' . s($source) . '">';

    echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px;">';
    echo '<h4 style="margin:0;">' . count($newquals) . ' qualification(s) ready to create</h4>';
    echo '<label style="font-size:0.88em;color:#334155;display:flex;align-items:center;gap:6px;cursor:pointer;">'
        . '<input type="checkbox" name="usetga" value="1"> Enrich from training.gov.au '
        . '<span style="color:#94a3b8;">(official names, packaging &amp; AQF — needs the platform API; falls back to category names)</span>'
        . '</label>';
    echo '</div>';

    echo '<table class="table table-sm table-hover" style="font-size:0.9em;">';
    echo '<thead class="thead-light"><tr>'
        . '<th style="width:36px;"><input type="checkbox" id="rtoc-ac-all" checked title="Select all"></th>'
        . '<th>Qual Code</th><th>Qualification Name</th>'
        . '<th style="text-align:center;">Units</th><th style="text-align:center;">Courses linked</th>'
        . '<th>Source category</th></tr></thead><tbody>';

    foreach ($newquals as $q) {
        $qc = s($q['qualcode']);
        echo '<tr>';
        echo '<td><input type="checkbox" class="rtoc-ac-cb" name="codes[]" value="' . $qc . '" checked></td>';
        echo '<td><code>' . $qc . '</code></td>';
        echo '<td>' . s($q['qualname']) . '</td>';
        echo '<td style="text-align:center;">' . (int)$q['unitcount'] . '</td>';
        $linkcol = ($q['linkedcount'] >= $q['unitcount']) ? '#16a34a' : '#d97706';
        echo '<td style="text-align:center;color:' . $linkcol . ';font-weight:600;">'
            . (int)$q['linkedcount'] . '/' . (int)$q['unitcount'] . '</td>';
        echo '<td style="color:#64748b;">' . s($q['catname']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<button type="submit" class="btn btn-primary" style="margin-top:6px;">Create selected qualifications</button> ';
    echo html_writer::link(new moodle_url('/local/rtocompliance/qualbuilder.php'), 'Cancel',
        ['class' => 'btn btn-outline-secondary', 'style' => 'margin-top:6px;']);
    echo '</form>';

    // Select-all toggle (no external JS).
    echo '<script>(function () {var a=document.getElementById("rtoc-ac-all");if(!a){return;}'
        . 'a.addEventListener("change",function () {document.querySelectorAll(".rtoc-ac-cb").forEach(function (c) {c.checked=a.checked;});});})();</script>';
}

// ── Already-existing qualifications (informational) ───────────────────────────
if (!empty($existing)) {
    echo '<hr style="margin:26px 0 16px;">';
    echo '<h5 style="color:#64748b;">' . count($existing) . ' qualification(s) already built (skipped)</h5>';
    echo '<p style="color:#94a3b8;font-size:0.86em;max-width:720px;">These qual codes already have a '
        . 'Qualification Builder product, so they are left untouched. Edit them in the Qualification Builder '
        . 'if you need to add or re-link units.</p>';
    echo '<table class="table table-sm" style="font-size:0.86em;max-width:720px;"><thead class="thead-light"><tr>'
        . '<th>Qual Code</th><th>Detected name</th><th style="text-align:center;">Units in tree</th></tr></thead><tbody>';
    foreach ($existing as $q) {
        echo '<tr><td><code>' . s($q['qualcode']) . '</code></td><td>' . s($q['qualname'])
            . '</td><td style="text-align:center;">' . (int)$q['unitcount'] . '</td></tr>';
    }
    echo '</tbody></table>';
}

echo html_writer::end_div();
echo $OUTPUT->footer();
