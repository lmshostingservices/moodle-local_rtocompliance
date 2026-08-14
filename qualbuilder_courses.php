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
 * RTO Compliance plugin — qualbuilder_courses.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT); // PARAM_ALPHA stripped underscores, so add_archive / remove_archive never matched.

admin_externalpage_setup('local_rtocompliance_qualbuilder');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$context = context_system::instance();

$product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $id], '*', MUST_EXIST);
$units = $DB->get_records('local_rtocompliance_qualunits', ['qualbuilderid' => $id], 'unittype ASC, sequenceorder ASC');

$PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id]));
$PAGE->set_title(get_string('link_courses', 'local_rtocompliance'));
$PAGE->set_heading(get_string('link_courses', 'local_rtocompliance') . ': ' . $product->qualificationcode);

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add($product->qualificationcode, new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]));
$PAGE->navbar->add(get_string('link_courses', 'local_rtocompliance'));


if ($action === 'autodetect') {
    require_sesskey();
    $linked = 0;
    foreach ($units as $unit) {
        if (!empty($unit->courseid)) {
            continue;
        }
        
        $sql = "SELECT c.id FROM {course} c
                JOIN {local_rtocompliance_courses} lrc ON lrc.courseid = c.id
                WHERE (c.shortname LIKE :code1 OR c.shortname LIKE :code2 OR lrc.qualificationcode LIKE :code3)
                LIMIT 1";
        
        $course = $DB->get_record_sql($sql, [
            'code1' => $unit->unitcode . '%',
            'code2' => '%' . $unit->unitcode . '%',
            'code3' => '%' . $unit->unitcode . '%',
        ]);
        
        if (!$course && $product->categoryid) {
            $course = $DB->get_record_sql(
                "SELECT c.id FROM {course} c 
                 WHERE c.category = :catid AND (c.shortname LIKE :code1 OR c.shortname LIKE :code2)
                 LIMIT 1",
                ['catid' => $product->categoryid, 'code1' => $unit->unitcode . '%', 'code2' => '%' . $unit->unitcode . '%']
            );
        }
        
        if ($course) {
            $DB->set_field('local_rtocompliance_qualunits', 'courseid', $course->id, ['id' => $unit->id]);
            $linked++;
        }
    }
    
    if ($linked > 0) {
        audit_logger::log_update(
            'qualbuilder',
            $id,
            "Auto-detect linked $linked courses",
            null,
            ['linked_count' => $linked]
        );
    }
    
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id]),
        get_string('autodetect_complete', 'local_rtocompliance', $linked),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    require_sesskey();
    
    $courselinks = optional_param_array('courseid', [], PARAM_INT);
    $updated = 0;
    
    foreach ($courselinks as $unitid => $courseid) {
        $unit = $DB->get_record('local_rtocompliance_qualunits', ['id' => $unitid, 'qualbuilderid' => $id]);
        if ($unit) {
            $newcourseid = $courseid > 0 ? $courseid : null;
            if ($unit->courseid !== $newcourseid) {
                $DB->set_field('local_rtocompliance_qualunits', 'courseid', $newcourseid, ['id' => $unitid]);
                $DB->set_field('local_rtocompliance_qualunits', 'timemodified', time(), ['id' => $unitid]);
                $updated++;
            }
        }
    }
    
    if ($updated > 0) {
        $DB->set_field('local_rtocompliance_qualbuilder', 'validationpassed', 0, ['id' => $id]);
        $DB->set_field('local_rtocompliance_qualbuilder', 'timemodified', time(), ['id' => $id]);
        
        audit_logger::log_update(
            'qualbuilder',
            $id,
            "Course links updated: $updated units",
            null,
            ['updated_count' => $updated]
        );
    }
    
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id]),
        get_string('links_saved', 'local_rtocompliance', $updated),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── Add archive course link ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_archive') {
    require_sesskey();
    $unitid          = required_param('unitid',          PARAM_INT);
    $archivecourseid = required_param('archivecourseid', PARAM_INT);
    $semesterlabel   = optional_param('semester_label',  '', PARAM_TEXT);

    $unit = $DB->get_record('local_rtocompliance_qualunits', ['id' => $unitid, 'qualbuilderid' => $id], '*', MUST_EXIST);

    $added = false;
    if ($archivecourseid > 0 && $DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {
        if (!$DB->record_exists('local_rtocompliance_qualunit_courses', ['qualunitid' => $unitid, 'courseid' => $archivecourseid])) {
            $DB->insert_record('local_rtocompliance_qualunit_courses', (object)[
                'qualunitid'     => $unitid,
                'courseid'       => $archivecourseid,
                'semester_label' => substr(trim($semesterlabel), 0, 100),
                'is_archive'     => 1,
                'timecreated'    => time(),
            ]);
            audit_logger::log_update('qualbuilder', $id, "Archive course linked: unit={$unitid} course={$archivecourseid} label={$semesterlabel}", null, ['unitid' => $unitid, 'courseid' => $archivecourseid]);
            $added = true;
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id]),
        $added ? 'Archive course linked.' : 'Already linked or invalid course.',
        null,
        $added ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// ─── Remove archive course link ───────────────────────────────────────────────
if ($action === 'remove_archive' && confirm_sesskey()) {
    $archiveid = required_param('archiveid', PARAM_INT);

    if ($DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {
        $rec = $DB->get_record_sql(
            "SELECT quc.id FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
              WHERE quc.id = :archiveid AND qu.qualbuilderid = :qbid",
            ['archiveid' => $archiveid, 'qbid' => $id]
        );
        if ($rec) {
            $DB->delete_records('local_rtocompliance_qualunit_courses', ['id' => $archiveid]);
            audit_logger::log_update('qualbuilder', $id, "Archive course unlinked: record={$archiveid}", null, ['archiveid' => $archiveid]);
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id]),
        'Archive course removed.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('link_courses', 'local_rtocompliance'), get_string('qualificationbuilder', 'local_rtocompliance'), '/local/rtocompliance/qualbuilder.php', 'qualbuilder');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('link_courses', 'local_rtocompliance'));
// FIX: sesskey must be embedded in the autodetect URL so require_sesskey()
// above can validate the request and prevent CSRF.
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_courses.php', [
        'id'      => $id,
        'action'  => 'autodetect',
        'sesskey' => sesskey(),
    ]),
    get_string('autodetect_courses', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary', 'title' => get_string('autodetect_courses_tip', 'local_rtocompliance')]
);
echo html_writer::end_div();

echo html_writer::tag('p', get_string('link_courses_desc', 'local_rtocompliance'), ['class' => 'text-muted']);

// Category context notice / warning.
if (!empty($product->categoryid)) {
    $catname = $DB->get_field('course_categories', 'name', ['id' => $product->categoryid]);
    if ($catname) {
        echo html_writer::div(
            html_writer::tag('strong', get_string('link_courses_category_label', 'local_rtocompliance') . ': ') .
            htmlspecialchars($catname, ENT_QUOTES) .
            ' &mdash; ' . get_string('link_courses_category_hint', 'local_rtocompliance'),
            'alert alert-info',
            ['style' => 'margin-bottom: 16px;']
        );
    }
} else {
    echo html_writer::div(
        html_writer::tag('strong', get_string('link_courses_no_category_title', 'local_rtocompliance') . ': ') .
        get_string('link_courses_no_category_hint', 'local_rtocompliance') . ' ' .
        html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]),
            get_string('link_courses_go_to_settings', 'local_rtocompliance')
        ),
        'alert alert-warning',
        ['style' => 'margin-bottom: 16px;']
    );
}

$linkedcount = 0;
foreach ($units as $unit) {
    if (!empty($unit->courseid)) {
        $linkedcount++;
    }
}

$totalcount = count($units);
$progressclass = $linkedcount === $totalcount ? 'text-success' : ($linkedcount > 0 ? 'text-warning' : 'text-danger');
echo html_writer::tag('p', 
    html_writer::tag('strong', get_string('linked_status', 'local_rtocompliance') . ': ') .
    html_writer::tag('span', "$linkedcount / $totalcount " . get_string('units_linked', 'local_rtocompliance'), ['class' => $progressclass]),
    ['style' => 'margin-bottom: 20px;']
);

if ($product->categoryid) {
    $courses = $DB->get_records_sql(
        "SELECT c.id, c.shortname, c.fullname 
         FROM {course} c 
         WHERE c.category = :categoryid 
         ORDER BY c.sortorder ASC",
        ['categoryid' => $product->categoryid]
    );
} else {
    $courses = $DB->get_records_sql(
        "SELECT c.id, c.shortname, c.fullname 
         FROM {course} c 
         WHERE c.id > 1 
         ORDER BY c.fullname ASC 
         LIMIT 500"
    );
}

$courseoptions = [0 => '-- ' . get_string('not_linked', 'local_rtocompliance') . ' --'];
foreach ($courses as $course) {
    $courseoptions[$course->id] = $course->shortname . ' - ' . substr($course->fullname, 0, 50) . (strlen($course->fullname) > 50 ? '...' : '');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id, 'action' => 'save'])]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$table = new html_table();
$table->head = [
    get_string('unit_type', 'local_rtocompliance'),
    get_string('unit_code', 'local_rtocompliance'),
    get_string('unit_name', 'local_rtocompliance'),
    get_string('linked_course', 'local_rtocompliance'),
];
$table->attributes['class'] = 'generaltable linking-table';

foreach ($units as $unit) {
    $typebadges = [
        'core' => 'badge-primary',
        'elective' => 'badge-info',
        'imported' => 'badge-secondary',
    ];
    $typebadge = html_writer::tag('span', ucfirst($unit->unittype), ['class' => 'badge ' . ($typebadges[$unit->unittype] ?? 'badge-secondary')]);
    
    $select = html_writer::select($courseoptions, "courseid[{$unit->id}]", $unit->courseid ?: 0, null, ['class' => 'form-control']);
    
    $table->data[] = [
        $typebadge,
        $unit->unitcode,
        $unit->unitname,
        $select,
    ];
}

echo html_writer::table($table);

echo html_writer::start_div('', ['style' => 'margin-top: 20px;']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('save_links', 'local_rtocompliance'), 'class' => 'btn btn-primary']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]),
    get_string('back_to_product', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary', 'style' => 'margin-left: 10px;']
);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// ─── Archive Courses section ──────────────────────────────────────────────────
// Only shown when the junction table exists (i.e. after the v5.2.37 DB upgrade).
if ($DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {

    // Fetch all archive links for units in this qual, keyed by qualunitid.
    $archivelinks = [];
    $unitids = array_keys((array) $units);
    if (!empty($unitids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED, 'uid');
        $rawlinks = $DB->get_records_sql(
            "SELECT quc.id, quc.qualunitid, quc.courseid, quc.semester_label,
                    c.fullname AS coursefullname, c.shortname AS courseshortname
               FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {course} c ON c.id = quc.courseid
              WHERE quc.qualunitid $insql
              ORDER BY quc.qualunitid ASC, quc.timecreated ASC",
            $inparams
        );
        foreach ($rawlinks as $link) {
            $archivelinks[$link->qualunitid][] = $link;
        }
    }

    // Build all-courses options for the add picker (same scope as primary dropdown).
    if ($product->categoryid) {
        // Include all courses in the qual category and its child categories.
        $allarchivecourses = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, cat.name AS catname
               FROM {course} c
               JOIN {course_categories} cat ON cat.id = c.category
              WHERE c.category = :catid
                 OR cat.parent = :catid2
              ORDER BY c.fullname ASC",
            ['catid' => $product->categoryid, 'catid2' => $product->categoryid]
        );
    } else {
        $allarchivecourses = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, cat.name AS catname
               FROM {course} c
               JOIN {course_categories} cat ON cat.id = c.category
              WHERE c.id > 1
              ORDER BY c.fullname ASC
              LIMIT 500"
        );
    }

    $archivecourseoptionshtml = '<option value="0">-- Select archive course --</option>';
    foreach ($allarchivecourses as $ac) {
        $label = htmlspecialchars($ac->shortname . ' — ' . substr($ac->fullname, 0, 60), ENT_QUOTES);
        $archivecourseoptionshtml .= "<option value=\"{$ac->id}\">{$label}</option>";
    }

    echo '<div style="margin-top:36px;">';
    echo '<h4 style="margin-bottom:4px;">Archive Courses</h4>';
    echo '<p class="text-muted" style="margin-bottom:16px;">Link each unit to its archive semester courses. ';
    echo 'Enrolments in any linked course will create AVETMISS records automatically, ';
    echo 'so historical data is fully captured regardless of which semester a student attended.</p>';

    $atbl = new html_table();
    $atbl->head = ['Unit', 'Archive courses linked', 'Add archive course'];
    $atbl->attributes['class'] = 'generaltable';
    $atbl->attributes['style'] = 'font-size:0.9rem;';

    foreach ($units as $unit) {
        $links = $archivelinks[$unit->id] ?? [];

        // Column 1: unit code + name
        $unitcell = html_writer::tag('strong', htmlspecialchars($unit->unitcode, ENT_QUOTES));
        $unitcell .= '<br><span class="text-muted" style="font-size:0.85em;">' . htmlspecialchars($unit->unitname, ENT_QUOTES) . '</span>';

        // Column 2: current linked archive courses with Remove links
        if (empty($links)) {
            $linkedcell = html_writer::tag('span', 'None', ['class' => 'text-muted']);
        } else {
            $linkedcell = '<ul class="list-unstyled mb-0">';
            foreach ($links as $link) {
                $removeurl = new moodle_url('/local/rtocompliance/qualbuilder_courses.php', [
                    'id'        => $id,
                    'action'    => 'remove_archive',
                    'archiveid' => $link->id,
                    'sesskey'   => sesskey(),
                ]);
                $label = htmlspecialchars($link->courseshortname, ENT_QUOTES);
                if (!empty($link->semester_label)) {
                    $label .= ' <span class="badge badge-secondary" style="font-size:0.75em;">' . htmlspecialchars($link->semester_label, ENT_QUOTES) . '</span>';
                }
                $linkedcell .= '<li style="margin-bottom:4px;">' . $label . ' ';
                $linkedcell .= html_writer::link($removeurl, '&times; Remove', ['class' => 'btn btn-sm btn-outline-danger', 'style' => 'padding:0 4px;font-size:0.75em;']);
                $linkedcell .= '</li>';
            }
            $linkedcell .= '</ul>';
        }

        // Column 3: add mini-form (separate form, no nesting issue)
        $addurl = new moodle_url('/local/rtocompliance/qualbuilder_courses.php', ['id' => $id, 'action' => 'add_archive']);
        $addcell = '<form method="post" action="' . $addurl->out(false) . '" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">';
        $addcell .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
        $addcell .= '<input type="hidden" name="unitid"  value="' . (int)$unit->id . '">';
        $addcell .= '<select name="archivecourseid" class="form-control form-control-sm" style="min-width:260px;max-width:360px;">';
        $addcell .= $archivecourseoptionshtml;
        $addcell .= '</select>';
        $addcell .= '<input type="text" name="semester_label" placeholder="Label e.g. Archive S2 2010" class="form-control form-control-sm" style="min-width:180px;max-width:220px;">';
        $addcell .= '<button type="submit" class="btn btn-sm btn-primary">Link</button>';
        $addcell .= '</form>';

        $atbl->data[] = [$unitcell, $linkedcell, $addcell];
    }

    echo html_writer::table($atbl);
    echo '</div>';
}

echo $OUTPUT->footer();
