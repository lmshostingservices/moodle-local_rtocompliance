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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_qualbuilder');

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
            'Training product deleted: ' . $product->qualificationcode . ' - ' . $product->qualificationname,
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
            get_string('confirm_delete_product', 'local_rtocompliance', $product->qualificationcode . ' - ' . $product->qualificationname),
            new moodle_url('/local/rtocompliance/qualbuilder.php', ['action' => 'delete', 'id' => $id, 'confirm' => 1]),
            new moodle_url('/local/rtocompliance/qualbuilder.php')
        );
        echo $OUTPUT->footer();
        return;
    }
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

$sql = "SELECT qb.*, 
        (SELECT COUNT(*) FROM {local_rtocompliance_qualunits} qu WHERE qu.qualbuilderid = qb.id AND qu.selected = 1) as unitcount,
        (SELECT COUNT(*) FROM {local_rtocompliance_qualunits} qu WHERE qu.qualbuilderid = qb.id AND qu.selected = 1 AND qu.courseid IS NOT NULL) as linkedcount
        FROM {local_rtocompliance_qualbuilder} qb";

$params = [];
if ($filter !== 'all') {
    $sql .= " WHERE qb.producttype = :producttype";
    $params['producttype'] = $filter;
}
$sql .= " ORDER BY qb.status ASC, qb.qualificationcode ASC";

$products = $DB->get_records_sql($sql, $params);

$producttypes = [
    'qualification' => ['label' => get_string('product_type_qualification', 'local_rtocompliance'), 'badge' => 'badge-primary'],
    'skillset' => ['label' => get_string('product_type_skillset', 'local_rtocompliance'), 'badge' => 'badge-info'],
    'singleunit' => ['label' => get_string('product_type_singleunit', 'local_rtocompliance'), 'badge' => 'badge-secondary'],
];

$statusbadges = [
    'draft' => 'badge-warning',
    'active' => 'badge-success',
    'superseded' => 'badge-danger',
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
    $table = new html_table();
    $table->head = [
        get_string('product_type', 'local_rtocompliance'),
        get_string('qualification_code', 'local_rtocompliance'),
        get_string('qualification_name', 'local_rtocompliance'),
        'Stream / Variant',
        get_string('units', 'local_rtocompliance'),
        get_string('linked_courses', 'local_rtocompliance'),
        get_string('status'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'generaltable qualbuilder-table';
    
    foreach ($products as $product) {
        $typeinfo = $producttypes[$product->producttype] ?? ['label' => $product->producttype, 'badge' => 'badge-secondary'];
        $typebadge = html_writer::tag('span', $typeinfo['label'], ['class' => 'badge ' . $typeinfo['badge']]);
        
        $statusbadge = html_writer::tag('span', ucfirst($product->status), ['class' => 'badge ' . ($statusbadges[$product->status] ?? 'badge-secondary')]);
        
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

        // Task #85: cap displayed linked count at displayTotal so the fraction never
        // exceeds 1/1 (100%). Stale map entries can leave linkedcount > totalunits when
        // units are removed from packaging rules after courses were already mapped.
        $displayLinked = min((int)$product->linkedcount, $displayTotal);
        $linkedtext = $displayLinked . '/' . $displayTotal;
        if ($product->linkedcount < $displayTotal) {
            $linkedtext = html_writer::tag('span', $linkedtext, ['class' => 'text-warning']);
        } else {
            $linkedtext = html_writer::tag('span', $linkedtext, ['class' => 'text-success']);
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
        $actions .= html_writer::link(
            new moodle_url('/local/rtocompliance/qualbuilder.php', ['action' => 'delete', 'id' => $product->id]),
            get_string('delete'),
            ['class' => 'btn btn-sm btn-outline-danger']
        );
        
        $table->data[] = [
            $typebadge,
            $product->qualificationcode,
            $product->qualificationname,
            !empty($product->streamname) ? html_writer::tag('span', s($product->streamname), ['class' => 'badge badge-light text-secondary border']) : html_writer::tag('span', '—', ['class' => 'text-muted']),
            $displayTotal,
            $linkedtext,
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
