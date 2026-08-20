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
 * RTO Compliance plugin — qualbuilder.php.
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

admin_externalpage_setup('local_rtocompliance_qualbuilder');
require_login();

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$PAGE->set_title(get_string('qualbuilder', 'local_rtocompliance'));
$PAGE->set_heading(get_string('qualbuilder', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$navheader = local_rtocompliance_render_nav_header(get_string('qualificationbuilder', 'local_rtocompliance'), null, null, 'qualbuilder');

if ($action === 'delete' && $id) {
    $product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $id], '*', MUST_EXIST);
    
    if ($confirm) {
        // Bug Q fix: require_sesskey() before any destructive DB operation.
        // Without this, an attacker could wipe an entire qualification product
        // and all its units by tricking an admin into visiting a crafted URL
        // with action=delete&id=X&confirm=1.
        require_sesskey();
        $DB->delete_records('local_rtocompliance_qualunits', ['qualbuilderid' => $id]);
        $DB->delete_records('local_rtocompliance_qualbuilder', ['id' => $id]);
        
        audit_logger::log_delete(
            'qualbuilder',
            $id,
            'Training product deleted: ' . $product->qualificationcode . ' ' . $product->qualificationname,
            ['product_type' => $product->producttype, 'code' => $product->qualificationcode]
        );
        
        redirect(
            new moodle_url('/local/rtocompliance/qualbuilder.php'),
            get_string('product_deleted', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $PAGE->add_body_class("path-local-rtocompliance");
        echo $OUTPUT->header();
        echo $navheader;
        echo $OUTPUT->confirm(
            get_string('confirm_delete_product', 'local_rtocompliance', $product->qualificationcode . ' ' . $product->qualificationname),
            new moodle_url('/local/rtocompliance/qualbuilder.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1]),
            new moodle_url('/local/rtocompliance/qualbuilder.php')
        );
        echo $OUTPUT->footer();
        return;
    }
}

// BUILD COURSE MAP (v6.0.7): populate the confirmed Course → Unit → Qualification map straight
// from each product's own unit→course links (plus this site's category-tree / regex detection).
// Fully generic and RTO-agnostic: it reads ONLY this Moodle's Qualification Builder products and
// its own category tree — nothing about any particular RTO is hardcoded — so it works on any RTO /
// Moodle setup. It only ADDS missing mappings (never deletes), turning "Course Map: None/partial"
// into full coverage for every unit that already has a linked delivery course.
if ($action === 'buildmap') {
    require_sesskey();
    require_capability('local/rtocompliance:manage', context_system::instance());
    \core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_HUGE);
    $res   = local_rtocompliance_seed_course_map('');
    $ins   = (int) ($res['inserted'] ?? 0);
    $skip  = (int) ($res['skipped'] ?? 0);
    $quals = isset($res['quals_scanned']) && is_array($res['quals_scanned']) ? count($res['quals_scanned']) : 0;
    $msg = 'Course map rebuilt from Builder links: ' . $ins . ' new mapping(s) added'
        . ($skip ? ', ' . $skip . ' already mapped' : '')
        . ' across ' . $quals . ' qualification(s). Any unit still showing no map has no linked'
        . ' delivery course, or uses a retired unit code with no course of its own.';
    redirect(new moodle_url('/local/rtocompliance/qualbuilder.php'),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo $navheader;

echo html_writer::start_div('qualbuilder-container');

echo html_writer::start_div('qualbuilder-header');
echo html_writer::tag('h2', get_string('qualbuilder', 'local_rtocompliance'));
echo html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_edit.php'),
    get_string('add_product', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
// AUTO-CREATE (v5.9.437): build products straight from the Moodle category tree.
echo ' ' . html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_autocreate.php'),
    'Auto-Create from Categories',
    ['class' => 'btn btn-outline-primary', 'title' =>
        'Scan your Moodle category tree and create Qualification Builder products (with units and course links) automatically.']
);
// RECOVER (v5.9.442): map superseded/renumbered unit codes from archived courses to current units.
echo ' ' . html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_recover.php'),
    'Recover Unmapped Completions',
    ['class' => 'btn btn-outline-secondary', 'title' =>
        'Map old/superseded unit codes from archived Moodle courses to your current units so the sync can credit those completions.']
);
// SEMESTER INTAKES (v5.9.444): create a separate product per semester intake category.
echo ' ' . html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder_semester.php'),
    'Semester Intake Builder',
    ['class' => 'btn btn-outline-secondary', 'title' =>
        'Create a separate qualification product for every semester intake category, with its units and courses linked.']
);
// BUILD COURSE MAP (v6.0.7): one-click populate the confirmed course map from the links already
// in the Builder, so the Course Map column matches Linked Courses. Generic for any RTO / Moodle.
echo ' ' . html_writer::link(
    new moodle_url('/local/rtocompliance/qualbuilder.php', ['action' => 'buildmap', 'sesskey' => sesskey()]),
    'Build Course Map from Links',
    ['class' => 'btn btn-outline-success', 'title' =>
        'Populate the Course Map from the delivery courses already linked to each unit here in the Builder, '
        . 'so the Course Map column matches Linked Courses. Reads only your own products and categories, '
        . 'adds only missing mappings, and changes nothing else.']
);
echo html_writer::end_div();

echo html_writer::tag('p', get_string('qualbuilder_desc', 'local_rtocompliance'), ['class' => 'rtoc-page-desc']);

echo html_writer::start_div('filter-tabs', ['id' => 'filters']);
$filters = [
    'all' => get_string('all'),
    'qualification' => get_string('product_type_qualification', 'local_rtocompliance'),
    'skillset' => get_string('product_type_skillset', 'local_rtocompliance'),
    'singleunit' => get_string('product_type_singleunit', 'local_rtocompliance'),
];
foreach ($filters as $key => $label) {
    $url = new moodle_url('/local/rtocompliance/qualbuilder.php', ['filter' => $key]);
    echo html_writer::link(
        $url->out(false) . '#filters',
        $label,
        ['class' => 'btn btn-sm ' . ($filter === $key ? 'btn-primary' : 'btn-secondary')]
    );
}
echo html_writer::end_div();

// v5.9.369 QB-COLUMN-CLARITY: a plain-language legend so the three "readiness" columns
// (Units / Linked Courses / Course Map) are unambiguous. The old table showed a fourth,
// redundant "Map Coverage" column that duplicated "Course Map" with a different number.
echo html_writer::start_div('', ['style' => 'background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin:0 0 16px;font-size:0.85rem;color:#334155;']);
echo html_writer::tag('div', 'What the columns mean', ['style' => 'font-weight:700;color:#0369a1;margin-bottom:6px;']);
echo html_writer::tag('div',
    '<strong>Units</strong> — how many units make up this qualification (from its packaging rules).<br>' .
    '<strong>Linked Courses</strong> — of those units, how many have a Moodle delivery course attached here in the Builder. This is what tells the system “completing <em>this course</em> = completing <em>this unit</em>”.<br>' .
    '<strong>Course Map</strong> — of those units, how many are confirmed in the Course → Unit → Qualification map. The map also recognises completions in <em>archive / semester copies</em> of a course, and is what the SoA and certificate tools use to find completers.<br>' .
    '<strong>Status</strong> — <em>Draft</em> while you build it; <em>Active</em> once it’s live and students completing it are queued for certificates. A green tick = packaging rules validated.',
    ['style' => 'line-height:1.6;']);
echo html_writer::tag('div',
    'For reliable auto-certificates, aim to get both <strong>Linked Courses</strong> and <strong>Course Map</strong> to the full count (green), then set the product to <strong>Active</strong>.',
    ['style' => 'margin-top:6px;color:#475569;']);
echo html_writer::end_div();

$sql = "SELECT qb.*,
        (SELECT COUNT(*) FROM {local_rtocompliance_qualunits} qu WHERE qu.qualbuilderid = qb.id AND qu.selected = 1) as unitcount,
        (SELECT COUNT(*) FROM {local_rtocompliance_qualunits} qu WHERE qu.qualbuilderid = qb.id AND qu.selected = 1 AND qu.courseid IS NOT NULL) as linkedcount,
        (SELECT COUNT(DISTINCT qu2.unitcode)
           FROM {local_rtocompliance_qualunits} qu2
          WHERE qu2.qualbuilderid = qb.id AND qu2.selected = 1
            AND EXISTS (SELECT 1 FROM {local_rtocompliance_course_map} cm
                         WHERE cm.qualcode = qb.qualificationcode
                           AND cm.unitcode  = qu2.unitcode
                           AND cm.confirmed = 1)) as mapconfirmed
        FROM {local_rtocompliance_qualbuilder} qb";

$params = [];
if ($filter !== 'all') {
    $sql .= " WHERE qb.producttype = :producttype";
    $params['producttype'] = $filter;
}
$sql .= " ORDER BY qb.status ASC, qb.qualificationcode ASC";

$products = $DB->get_records_sql($sql, $params);

// v5.9.369 QB-COLUMN-CLARITY: the old "Map Coverage" column counted confirmed
// course_map unitcodes by qualcode WITHOUT restricting to the qualification's own
// selected units, so it double-counted, disagreed with the "Map" column (which does
// restrict to selected units), and could even read "15/12". It has been removed — the
// single accurate "Course Map" column below (mapconfirmed, from the main query) is kept.
$mapTableExists = $DB->get_manager()->table_exists('local_rtocompliance_course_map');

$producttypes = [
    'qualification' => ['label' => get_string('product_type_qualification', 'local_rtocompliance'), 'badge' => 'badge-primary', 'tip' => 'A full nationally recognised qualification made up of several units.'],
    'skillset' => ['label' => get_string('product_type_skillset', 'local_rtocompliance'), 'badge' => 'badge-info', 'tip' => 'A skill set: a smaller nationally recognised group of units, not a full qualification.'],
    'singleunit' => ['label' => get_string('product_type_singleunit', 'local_rtocompliance'), 'badge' => 'badge-secondary', 'tip' => 'A single unit of competency delivered on its own.'],
];

$statusbadges = [
    'draft' => 'badge-warning',
    'active' => 'badge-success',
    'superseded' => 'badge-danger',
];
// Plain-English tooltip for each product status badge.
$statustips = [
    'draft' => 'Draft: still being set up and not yet issuing certificates automatically.',
    'active' => 'Active: live; completing students are queued for certificate issue.',
    'superseded' => 'Superseded: replaced by a newer version on the national register. Move learners to the current version.',
];

if (empty($products)) {
    echo html_writer::div(
        html_writer::tag('p', get_string('no_products', 'local_rtocompliance')) .
        html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder_edit.php'),
            get_string('add_first_product', 'local_rtocompliance'),
            ['class' => 'btn btn-primary']
        ),
        'no-deadlines'
    );
} else {
    // v5.9.369 QB-COLUMN-CLARITY: header cells carry a plain-language tooltip so each
    // column's meaning is obvious, and the redundant "Map Coverage" column is gone.
    $hcell = function ($label, $tip) {
        return html_writer::tag('span', $label,
            ['title' => $tip, 'style' => 'cursor:help;border-bottom:1px dotted #9ca3af;']);
    };
    $table = new html_table();
    $table->head = [
        get_string('product_type', 'local_rtocompliance'),
        get_string('qualification_code', 'local_rtocompliance'),
        get_string('qualification_name', 'local_rtocompliance'),
        'Stream / Variant',
        $hcell(get_string('units', 'local_rtocompliance'),
            'How many units this training product packages (from TGA / packaging rules).'),
        $hcell(get_string('linked_courses', 'local_rtocompliance'),
            'How many units have a Moodle delivery course attached in the Qualification Builder. This is how the system knows which course completion counts as which unit.'),
        $hcell('Course Map',
            'How many units are confirmed in the Course → Unit → Qualification map. This recognises completions even across semester / archive copies of a course, and is what the SoA and certificate generators rely on.'),
        $hcell(get_string('status'),
            'Draft = still being set up. Active = live; completing students are queued for certificate issue. A green tick means the packaging rules passed validation.'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'generaltable qualbuilder-table';
    
    foreach ($products as $product) {
        $typeinfo = $producttypes[$product->producttype] ?? ['label' => $product->producttype, 'badge' => 'badge-secondary', 'tip' => 'The kind of training product this row represents.'];
        $typebadge = html_writer::tag('span', $typeinfo['label'], ['class' => 'badge ' . $typeinfo['badge'], 'title' => $typeinfo['tip'] ?? 'The kind of training product this row represents.']);

        $statusbadge = html_writer::tag('span', ucfirst($product->status), ['class' => 'badge ' . ($statusbadges[$product->status] ?? 'badge-secondary'), 'title' => $statustips[$product->status] ?? 'The current lifecycle status of this training product.']);
        
        if ($product->validationpassed) {
            $statusbadge .= ' ' . html_writer::tag('span', '&#10003;', ['class' => 'text-success', 'title' => get_string('packaging_validated', 'local_rtocompliance')]);
        }
        
        // QB-LIST-TOTAL-FIX (v5.9.294): use TGA-sourced totalunits (stored on the QB record
        // when the admin loads from TGA or pastes packaging rules) as the denominator for
        // both the UNITS column and the LINKED X/Y counter.  This matches what the Edit page
        // compliance panel shows — e.g. "6/12 linked" instead of "6/10 linked" when 2 elective
        // units are required but not yet selected/saved.  Fall back to the DB selected-unit count
        // only for old records that predate TGA loading (totalunits = 0).
        $displayTotal = (!empty($product->totalunits) && (int)$product->totalunits > 0)
            ? (int)$product->totalunits
            : (int)$product->unitcount;

        // v5.9.369: cap the numerator so the fraction can never read e.g. "15/12"
        // when a product has more selected/linked units than its TGA total.
        $linkedCountDisp = min((int)$product->linkedcount, (int)$displayTotal);
        $linkedtext = $linkedCountDisp . '/' . $displayTotal;
        $linkedtip = 'How many of the ' . $displayTotal . ' units have a delivery course linked. Link the rest so course completions count towards this product.';
        if ($linkedCountDisp < $displayTotal) {
            $linkedtext = html_writer::tag('span', $linkedtext, ['class' => 'text-warning', 'title' => $linkedtip]);
        } else {
            $linkedtext = html_writer::tag('span', $linkedtext, ['class' => 'text-success', 'title' => $linkedtip]);
        }

        // FIX-QB-SIMPLIFY (v5.9.270): removed the separate "Link Courses" button.
        // Course linking happens inside the Edit (Smart Builder) page via inline dropdowns
        // and auto-link — pointing users to a second page (qualbuilder_courses.php) was
        // confusing because it used a cruder auto-detect and duplicated what Edit already does.
        $actions = html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $product->id]),
            get_string('edit'),
            ['class' => 'btn btn-sm btn-primary mr-1']
        );
        $actions .= html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder_results.php', ['id' => $product->id]),
            get_string('view_results', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-outline-primary mr-1', 'title' => get_string('student_results_desc', 'local_rtocompliance')]
        );
        // QUAL-CERT-HUB (v5.9.339): direct link to cert hub detail for this qual.
        $actions .= html_writer::link(
            new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $product->id, 'tab' => 'ready']),
            'Certs',
            ['class' => 'btn btn-sm btn-outline-secondary mr-1', 'title' => 'Qualification Certificate Hub — view completers and issue certificates']
        );
        $actions .= html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder.php', ['action' => 'delete', 'id' => $product->id]),
            get_string('delete'),
            ['class' => 'btn btn-sm btn-outline-danger']
        );
        
        // TASK-50 (v5.9.349): Map coverage badge — shows how many selected units
        // have at least one confirmed entry in local_rtocompliance_course_map.
        $mapTotal     = (int)$displayTotal;
        $mapConfirmed = min((int)($product->mapconfirmed ?? 0), $mapTotal); // v5.9.369: cap ≤ total
        if ($mapTotal === 0) {
            $mapbadge = html_writer::tag('span', '—', ['class' => 'text-muted', 'title' => 'No units selected']);
        } elseif ($mapConfirmed >= $mapTotal) {
            $mapbadge = html_writer::link(
                new moodle_url('/local/rtocompliance/course_map.php', ['filter' => $product->qualificationcode]),
                '&#10003; All',
                ['class' => 'badge badge-success', 'title' => 'All ' . $mapTotal . ' units have a confirmed course map entry']
            );
        } elseif ($mapConfirmed > 0) {
            $mapbadge = html_writer::link(
                new moodle_url('/local/rtocompliance/course_map.php', ['filter' => $product->qualificationcode]),
                '&#9888; ' . $mapConfirmed . '/' . $mapTotal,
                ['class' => 'badge badge-warning', 'title' => $mapConfirmed . ' of ' . $mapTotal . ' units confirmed in course map — click to review']
            );
        } else {
            $mapbadge = html_writer::link(
                new moodle_url('/local/rtocompliance/course_map.php', ['filter' => $product->qualificationcode]),
                '&#10007; None',
                ['class' => 'badge badge-danger', 'title' => 'No units have a confirmed course map entry — click to set up mapping']
            );
        }

        $table->data[] = [
            $typebadge,
            $product->qualificationcode,
            $product->qualificationname,
            !empty($product->streamname) ? html_writer::tag('span', s($product->streamname), ['class' => 'badge badge-light text-secondary border', 'title' => 'The specific stream or variant of this training product.']) : html_writer::tag('span', '—', ['class' => 'text-muted']),
            $displayTotal,
            $linkedtext,   // Linked Courses
            $mapbadge,     // Course Map (v5.9.369: adjacent to Linked Courses; Map Coverage removed)
            $statusbadge,
            $actions,
        ];
    }
    
    echo html_writer::start_div('table-responsive');
    echo html_writer::table($table);
    echo html_writer::end_div();
}

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', get_string('certificate_credits', 'local_rtocompliance'));
echo html_writer::tag('ul', 
    html_writer::tag('li', '<strong>' . get_string('product_type_singleunit', 'local_rtocompliance') . ':</strong> ' . get_string('soa_credits', 'local_rtocompliance')) .
    html_writer::tag('li', '<strong>' . get_string('product_type_skillset', 'local_rtocompliance') . ':</strong> ' . get_string('soa_credits', 'local_rtocompliance')) .
    html_writer::tag('li', '<strong>' . get_string('product_type_qualification', 'local_rtocompliance') . ':</strong> ' . get_string('qual_credits', 'local_rtocompliance'))
);
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
