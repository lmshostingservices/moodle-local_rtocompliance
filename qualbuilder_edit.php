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
 * RTO Compliance plugin — qualbuilder_edit.php.
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

$id = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_qualbuilder');
require_login();
$context = context_system::instance();

$PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $id]));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/qualbuilder.php'));

$product = null;
$units   = [];

if ($id) {
    $product    = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $id], '*', MUST_EXIST);
    $qualunits  = $DB->get_records('local_rtocompliance_qualunits',
        ['qualbuilderid' => $id, 'selected' => 1], 'unittype ASC, sequenceorder ASC');

    // Build variants map: qualunitid → [courseid, ...] from qualunit_courses.
    // IMPORTANT: filter to is_archive = 0 only.  Archive-semester courses
    // (is_archive = 1) must NOT appear as variant chips — they are a separate
    // feature (the archive-linking wizard) and exposing them here would:
    //   (a) clutter the UI with every prior semester's course, and
    //   (b) corrupt is_archive back to 0 when the admin next saves the QB.
    $variantsMap = [];
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('local_rtocompliance_qualunit_courses') && !empty($qualunits)) {
        $unitIds = array_keys($qualunits);
        list($insql, $inparams) = $DB->get_in_or_equal($unitIds, SQL_PARAMS_NAMED, 'quid');
        $inparams['is_archive_val'] = 0;
        $qucRows = $DB->get_records_select('local_rtocompliance_qualunit_courses',
            "qualunitid $insql AND is_archive = :is_archive_val", $inparams, '', 'qualunitid, courseid');
        foreach ($qucRows as $qr) {
            $variantsMap[(int)$qr->qualunitid][] = (int)$qr->courseid;
        }
    }

    $units = array_values(array_map('array_values', array_map(function ($u) use ($variantsMap) {
        return [
            'id'            => (int)$u->id,
            'unitcode'      => $u->unitcode,
            'unitname'      => $u->unitname,
            'unittype'      => $u->unittype,
            'electivegroup' => (string)($u->electivegroup ?? ''),
            'nominalhours'  => (int)($u->nominalhours ?? 0),
            'courseid'      => (int)($u->courseid ?? 0),
            'creditpoints'  => (int)($u->creditpoints ?? 0),
            'variants'      => $variantsMap[(int)$u->id] ?? [],   // [8] extra course IDs
        ];
    }, $qualunits)));
    $PAGE->set_title(get_string('edit_product', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('edit_product', 'local_rtocompliance') . ': ' . $product->qualificationcode);
    $PAGE->navbar->add(get_string('edit_product', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('add_product', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('add_product', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('add_product', 'local_rtocompliance'));
}

// Build full category tree with parent and idnumber for the smart two-level picker.
// No LIMIT — use get_recordset_sql (streamed) to avoid silently dropping categories
// on large sites. Results stored in an id-keyed array to match get_records() behaviour
// so the pre-selection logic below ($catrows[(int)$product->categoryid]) still works.
$_catRs  = $DB->get_recordset_sql(
    "SELECT id, name, parent, idnumber FROM {course_categories} ORDER BY sortorder ASC"
);
$catrows = [];
foreach ($_catRs as $_cr) { $catrows[(int)$_cr->id] = $_cr; }
$_catRs->close();

// JS-friendly tree array (passed via qb-init-data JSON payload)
$catTree = [];
foreach ($catrows as $cat) {
    $catTree[] = [
        'id'       => (int)$cat->id,
        'name'     => $cat->name,
        'parent'   => (int)$cat->parent,
        'idnumber' => (string)($cat->idnumber ?? ''),
    ];
}

// Step-1 picker: root categories only (parent = 0) — qualification roots live here.
// Include all root categories so nothing is hidden; idnumber shown in brackets where set.
$rootCatsSel = '<option value="0">' . get_string('select_category', 'local_rtocompliance') . '</option>';
foreach ($catrows as $cat) {
    if ((int)$cat->parent === 0) {
        $idn = trim((string)($cat->idnumber ?? ''));
        $label = htmlspecialchars($cat->name, ENT_QUOTES) . ($idn !== '' ? ' [' . htmlspecialchars($idn, ENT_QUOTES) . ']' : '');
        // For an existing record: pre-select if this root IS the saved categoryid,
        // or is the parent of the saved categoryid (child/semester was saved).
        $isRoot = $product && (int)$product->categoryid === (int)$cat->id;
        $savedCat = ($product && isset($catrows[(int)$product->categoryid])) ? $catrows[(int)$product->categoryid] : null;
        $isParentOfSaved = $savedCat && (int)$savedCat->parent === (int)$cat->id;
        $sel = ($isRoot || $isParentOfSaved) ? ' selected' : '';
        $rootCatsSel .= '<option value="' . (int)$cat->id . '"' . $sel . '>' . $label . '</option>';
    }
}

// Legacy flat select kept for the hidden storage field (JS reads #qb-category-select on save).
$categoriesSel = '<option value="0">' . get_string('select_category', 'local_rtocompliance') . '</option>';
foreach ($catrows as $cat) {
    $sel = ($product && (int)$product->categoryid === (int)$cat->id) ? ' selected' : '';
    $categoriesSel .= '<option value="' . (int)$cat->id . '"' . $sel . '>' . htmlspecialchars($cat->name, ENT_QUOTES) . '</option>';
}

// Prepare the JSON data payload for JavaScript
$productData = null;
if ($product) {
    $productData = [
        'producttype'       => $product->producttype,
        'qualificationcode' => $product->qualificationcode,
        'qualificationname' => $product->qualificationname,
        'streamname'        => $product->streamname ?? '',
        'aqflevel'          => (int)($product->aqflevel ?? 0),
        'categoryid'        => (int)($product->categoryid ?? 0),
        'nominalhours'      => (int)($product->nominalhours ?? 0),
        'status'            => $product->status ?? 'draft',
        'totalunits'        => (int)($product->totalunits ?? 0),
        'coreunitcount'     => (int)($product->coreunitcount ?? 0),
        'electivecount'     => (int)($product->electivecount ?? 0),
        // Decode JSON strings so they arrive as native JS objects, not double-encoded strings.
        // Passing a raw JSON string through json_encode() wraps it in quotes and escapes all
        // inner quotes - making the outer payload valid but leaving a time-bomb: any JS that
        // does JSON.parse(INIT.product.electiverules) will explode if the DB string is malformed.
        // Decoding here means json_encode() will serialise the PHP array directly as an object.
        'electiverules'     => !empty($product->electiverules)
                                    ? (json_decode($product->electiverules, true) ?: null)
                                    : null,
        'validationpassed'  => (int)($product->validationpassed ?? 0),
        'validationerrors'  => !empty($product->validationerrors)
                                    ? (json_decode($product->validationerrors, true) ?: [])
                                    : [],
        'validationdate'    => (int)($product->validationdate ?? 0),
    ];
}

// Try to parse existing group rules from electiverules JSON
$existingGroupRules = [];
if ($product && !empty($product->electiverules)) {
    $rulesParsed = json_decode($product->electiverules, true);
    if (!empty($rulesParsed['requiredGroups'])) {
        $existingGroupRules = $rulesParsed['requiredGroups'];
    }
}

// JSON_HEX_TAG escapes < and > to \u003C/\u003E so "</script>" can never appear
// literally inside the payload when it is embedded in a <script type="application/json">
// DOM element.  JSON.parse() in the browser decodes the unicode escapes transparently.
$jsPayload = json_encode([
    'qualbuilderid'    => $id,
    'product'          => $productData,
    'existingUnits'    => $units,
    'existingGroupRules' => $existingGroupRules,
    'wwwroot'          => $CFG->wwwroot,
    'sesskey'          => sesskey(),
    // NOMINAL HOURS PHASE 2 (v5.9.421): batch endpoint used to populate authoritative
    // nominal hours for every unit at once (TGA does not publish them), so the
    // qualification total rolls up from the plugin's own reference table.
    'nominalHoursEndpoint' => (new moodle_url('/local/rtocompliance/nominalhours_lookup.php'))->out(false),
    // Full category tree for the two-level picker (qual root → semester child).
    // Available immediately on page load — no TGA call required.
    'categoryTree'     => $catTree,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);

$PAGE->add_body_class('path-local-rtocompliance');

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    $id ? get_string('edit_product', 'local_rtocompliance') : get_string('add_product', 'local_rtocompliance'),
    get_string('qualificationbuilder', 'local_rtocompliance'),
    '/local/rtocompliance/qualbuilder.php',
    'qualbuilder'
);
echo local_rtocompliance_page_banner($id ? get_string('edit_product', 'local_rtocompliance') : get_string('add_product', 'local_rtocompliance'));
?>

<style>
/* Smart Qualification Builder styles */
#qb-smart-builder { max-width: 1100px; }
.qb-card { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin-bottom: 18px; }
.dark-mode .qb-card, .pagelayout-admin.dark .qb-card { background: #1e1e2e; border-color: #3a3a5c; }
.qb-card-title { font-size: 1rem; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.qb-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.72rem; font-weight: 700; letter-spacing: .04em; }
.qb-badge-core     { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
.qb-badge-group    { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; }
.qb-badge-elective { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
.qb-badge-imported { background: #f3e5f5; color: #6a1b9a; border: 1px solid #e1bee7; }

/* Compliance dashboard */
.qb-compliance-cards { display: flex; flex-wrap: wrap; gap: 10px; }
.qb-status-card { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 6px; border: 1px solid; min-width: 140px; }
.qb-status-card.pass { background: #f0fdf4; border-color: #86efac; color: #166534; }
.qb-status-card.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.qb-status-card.fail { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
.qb-status-card.info { background: #eff6ff; border-color: #93c5fd; color: #1e40af; }
.qb-status-icon { font-size: 1.4rem; line-height: 1; }
.qb-status-label { font-size: 0.78rem; font-weight: 600; }
.qb-status-value { font-size: 0.9rem; }
.qb-status-sub { font-size: 0.75rem; opacity: 0.8; margin-top: 1px; }

/* Unit sections */
.qb-section { margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
.qb-section-header { background: #f9fafb; border-bottom: 1px solid #e5e7eb; padding: 10px 14px; display: flex; align-items: center; gap: 8px; }
.qb-section-header h5 { margin: 0; font-size: 0.92rem; font-weight: 600; }
.qb-section-status { margin-left: auto; font-size: 0.82rem; font-weight: 600; }
.qb-unit-row { display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.qb-unit-row:last-child { border-bottom: none; }
.qb-unit-row:hover { background: #fafafa; }
.qb-unit-row.selected { background: #f0fdf4; }
.qb-unit-check { width: 22px; flex-shrink: 0; display: flex; justify-content: center; }
.qb-unit-lock { color: #9ca3af; font-size: 0.85rem; }
.qb-unit-info { flex: 1; min-width: 0; }
.qb-unit-code { font-family: monospace; font-weight: 700; font-size: 0.85rem; color: #1f2937; }
.qb-unit-name { font-size: 0.88rem; color: #374151; margin-left: 4px; }
.qb-unit-hours { display: inline-block; background: #f3f4f6; color: #6b7280; border-radius: 10px; padding: 0 6px; font-size: 0.72rem; margin-left: 6px; }
.qb-unit-course { flex-shrink: 0; width: 220px; }
.qb-unit-course select { font-size: 0.8rem; padding: 2px 4px; height: auto; }
.qb-unit-course.linked select { border-color: #86efac; }
.qb-unit-course.unlinked select { border-color: #d1d5db; }
.qb-empty-section { padding: 12px 14px; color: #9ca3af; font-size: 0.88rem; font-style: italic; }
.qb-tga-banner { padding: 10px 14px; border-radius: 6px; border: 1px solid #93c5fd; background: #eff6ff; margin-bottom: 14px; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; }
.qb-suggestion-pill { display: inline-flex; align-items: center; gap: 6px; background: #fef9c3; border: 1px solid #fde68a; border-radius: 20px; padding: 2px 10px; font-size: 0.8rem; cursor: pointer; }
.qb-form-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; margin-bottom: 14px; }
.qb-form-group { display: flex; flex-direction: column; gap: 4px; }
.qb-form-group label { font-size: 0.82rem; font-weight: 600; color: #374151; }
.qb-form-group select, .qb-form-group input { font-size: 0.88rem; }
.qb-rules-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 0.84rem; color: #475569; }
.qb-rules-box ul { margin: 6px 0 0 0; padding-left: 18px; }
.qb-rules-box li { margin-bottom: 2px; }
#qb-loading-overlay { display: none; position: fixed; inset: 0; background: rgba(255,255,255,.7); z-index: 9999; justify-content: center; align-items: center; }
#qb-loading-overlay.active { display: flex; }
.qb-add-imported-form { padding: 12px 14px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: none; }
.qb-add-imported-form .qb-form-row { margin-bottom: 0; }

/* Category suggestions */
.qb-suggestion-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.qb-suggestion-pill { display: inline-flex; align-items: center; gap: 6px; background: #fef9c3; border: 1px solid #fde68a; border-radius: 20px; padding: 3px 12px; font-size: 0.8rem; cursor: pointer; transition: background .12s; }
.qb-suggestion-pill:hover { background: #fef08a; }
.qb-suggestion-pill.primary { background: #e0f2fe; border-color: #7dd3fc; }
.qb-suggestion-pill.primary:hover { background: #bae6fd; }
.qb-map-all-feedback { font-size: 0.8rem; color: #166534; background: #dcfce7; border: 1px solid #86efac; border-radius: 4px; padding: 3px 10px; display:none; margin-top:4px; }

/* Credit points badge */
.qb-unit-points { display: inline-block; background: #ede9fe; color: #5b21b6; border-radius: 10px; padding: 0 6px; font-size: 0.72rem; margin-left: 4px; font-weight: 700; }
.qb-status-card.points { background: #f5f3ff; border-color: #c4b5fd; color: #4c1d95; }

/* Auto-suggest missing units panel */
.qb-autofix { margin-top: 12px; border-top: 1px dashed #e5e7eb; padding-top: 10px; }
.qb-autofix-title { font-size: 0.8rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
.qb-autofix-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 0.82rem; border-bottom: 1px solid #f3f4f6; }
.qb-autofix-item:last-child { border-bottom: none; }
.qb-autofix-add { font-size: 0.75rem; }

/* Compact Moodle course link badge — shown when unit is linked, click to change */
/* White background is more readable than the old green-50 tint */
.qb-linked-badge { display:inline-flex; align-items:center; gap:4px; background:#ffffff; border:1.5px solid #16a34a; border-radius:10px; padding:2px 9px; font-size:0.76rem; color:#15803d; cursor:pointer; max-width:210px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; }
.qb-linked-badge:hover { background:#f0fdf4; border-color:#15803d; }
/* QB-VARIANTS: teacher-cohort variant chips */
.qb-variant-chip { display:inline-flex; align-items:center; gap:3px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; padding:1px 7px; font-size:0.72rem; color:#475569; white-space:nowrap; margin-left:3px; vertical-align:middle; }
.qb-variant-chip .qb-variant-remove { cursor:pointer; font-weight:700; opacity:0.5; margin-left:2px; line-height:1; font-size:0.85rem; }
.qb-variant-chip .qb-variant-remove:hover { opacity:1; color:#dc2626; }
/* + button that reveals the add-variant select */
.qb-variant-add-wrap { display:inline-flex; align-items:center; vertical-align:middle; margin-left:4px; position:relative; }
.qb-variant-add-btn { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; border:1.5px dashed #94a3b8; background:#f8fafc; color:#64748b; cursor:pointer; font-size:13px; font-weight:700; line-height:1; padding:0; transition:border-color .12s, color .12s; }
.qb-variant-add-btn:hover { border-color:#475569; color:#1e293b; background:#f1f5f9; }
.qb-variant-add { min-width:160px !important; font-size:0.72rem !important; height:22px !important; padding:0 4px !important; border-radius:6px !important; border:1px solid #94a3b8 !important; background:#fff !important; color:#1e293b !important; cursor:pointer; }
/* Info panel explaining the variant system */
.qb-variants-info { display:flex; align-items:flex-start; gap:10px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:10px 14px; margin:8px 0 10px; font-size:0.82rem; color:#0c4a6e; line-height:1.5; }
.qb-variants-info-icon { font-size:1rem; flex-shrink:0; margin-top:1px; }
.qb-variants-info-body { flex:1; }
.qb-variants-info-body strong { color:#075985; }
.qb-variants-info-body code { background:#e0f2fe; border-radius:3px; padding:0 3px; font-size:0.78rem; }
.qb-variants-info-dismiss { float:right; background:none; border:none; cursor:pointer; color:#64748b; font-size:1rem; font-weight:700; line-height:1; padding:0; margin-left:8px; opacity:0.6; }
.qb-variants-info-dismiss:hover { opacity:1; }
.dark-mode .qb-variant-chip { background:#2d3748; border-color:#4a5568; color:#a0aec0; }
.dark-mode .qb-linked-badge { background:#1a2e1a; border-color:#22c55e; color:#4ade80; }
.dark-mode .qb-variants-info { background:#0c1a2e; border-color:#1e3a5f; color:#7dd3fc; }

/* QPR paste-box — shown when TGA doesn't return structured packaging rule counts */
.qb-qpr-paste { display:flex; align-items:flex-start; gap:10px; background:#fffbeb; border:1.5px solid #fde68a; border-radius:6px; padding:12px 14px; margin-bottom:12px; font-size:0.83rem; color:#78350f; }
.qb-qpr-paste-icon { font-size:1.1rem; flex-shrink:0; margin-top:1px; }
.qb-qpr-paste-body { flex:1; }
.qb-qpr-paste-body strong { color:#92400e; }
.qb-qpr-paste-body a { color:#b45309; }
.qb-qpr-paste-textarea { display:block; width:100%; margin-top:8px; font-size:0.78rem; font-family:monospace; border:1px solid #fcd34d; border-radius:4px; padding:6px 8px; background:#fffef5; resize:vertical; min-height:68px; color:#1c1917; }
.qb-qpr-paste-textarea:focus { outline:none; border-color:#f59e0b; box-shadow:0 0 0 2px rgba(245,158,11,.2); }
.qb-qpr-parse-btn { margin-top:7px; font-size:0.78rem; padding:3px 12px; }
.qb-qpr-parse-result { font-size:0.8rem; margin-left:10px; vertical-align:middle; }
/* QPR overall compliance banner */
.qb-qpr-banner { padding:10px 16px; border-radius:6px; font-size:0.95rem; font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
.qb-qpr-banner.pass { background:#f0fdf4; border:1px solid #86efac; color:#166534; }
.qb-qpr-banner.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.qb-qpr-banner.fail { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }
.qb-qpr-banner.info { background:#eff6ff; border:1px solid #93c5fd; color:#1e40af; }
.qb-engine-errors { background:#fef2f2; border:1px solid #fca5a5; border-radius:6px; padding:8px 14px; margin-top:4px; margin-bottom:12px; }
.qb-engine-error-item { font-size:0.82rem; color:#991b1b; padding:2px 0; }

/* Auto-link result notice */
.qb-autolink-notice { font-size:0.82rem; color:#166534; background:#dcfce7; border:1px solid #86efac; border-radius:6px; padding:6px 12px; margin-top:8px; display:none; }

/* Unit search bar */
#qb-unit-search-bar { padding:10px 14px; margin-bottom:0; border-bottom:none; border-radius:6px 6px 0 0; }
#qb-unit-search { border-radius:4px; font-size:0.88rem; }
.qb-type-btn { font-size:0.78rem; padding:2px 10px; }
.qb-type-btn.active { font-weight:700; }
.qb-type-btn[data-type="all"].active   { background:#1f2937; border-color:#1f2937; color:#fff; }
.qb-type-btn[data-type="core"].active  { background:#2e7d32; border-color:#2e7d32; color:#fff; }
.qb-type-btn[data-type="elective"].active { background:#e65100; border-color:#e65100; color:#fff; }
#qb-unit-count { font-size:0.8rem; color:#6b7280; }
.qb-no-match-msg { padding:10px 14px; color:#9ca3af; font-size:0.88rem; font-style:italic; display:none; }

/* Toast */
.qb-toast { position: fixed; bottom: 28px; right: 28px; background: #1f2937; color: #f9fafb; padding: 10px 20px; border-radius: 6px; font-size: 0.88rem; z-index: 99999; opacity: 0; transition: opacity .3s; pointer-events: none; }
.qb-toast.show { opacity: 1; }
</style>

<div id="qb-toast" class="qb-toast"></div>

<div id="qb-loading-overlay">
    <div class="text-center">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 text-primary fw-bold" id="qb-loading-msg">Loading from training.gov.au...</div>
    </div>
</div>

<div id="qb-smart-builder">

    <!-- Setup Panel -->
    <div class="qb-card" id="qb-setup-card">
        <div class="qb-card-title">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
            Product Setup
        </div>

        <div class="qb-form-row">
            <div class="qb-form-group">
                <label for="qb-type-select">Product Type</label>
                <select id="qb-type-select" class="form-control form-control-sm" style="min-width:170px">
                    <option value="qualification">Qualification</option>
                    <option value="skillset">Skill Set</option>
                    <option value="singleunit">Single Unit</option>
                </select>
            </div>
            <div class="qb-form-group">
                <label for="qb-code-input">Code <span class="text-muted" style="font-weight:400">(e.g. BSB30120)</span></label>
                <div style="display:flex;gap:6px;align-items:center">
                    <input type="text" id="qb-code-input" class="form-control form-control-sm" placeholder="BSB30120" style="width:130px;text-transform:uppercase">
                    <button type="button" class="btn btn-primary btn-sm" id="qb-load-tga-btn" style="white-space:nowrap">
                        Load from TGA
                    </button>
                    <span id="qb-tga-spinner" class="spinner-border spinner-border-sm text-primary" style="display:none"></span>
                </div>
            </div>
            <div class="qb-form-group">
                <label for="qb-status-select">Status</label>
                <select id="qb-status-select" class="form-control form-control-sm" style="min-width:130px">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="superseded">Superseded</option>
                </select>
            </div>
        </div>

        <div class="qb-form-row">
            <div class="qb-form-group" style="flex:1;min-width:260px">
                <label for="qb-name-input">Qualification Name</label>
                <input type="text" id="qb-name-input" class="form-control form-control-sm" placeholder="Auto-filled from TGA" maxlength="255">
            </div>
            <div class="qb-form-group" style="flex:1;min-width:200px">
                <label for="qb-stream-input">Stream / Variant Name <span class="text-muted" style="font-weight:400">(optional)</span></label>
                <input type="text" id="qb-stream-input" class="form-control form-control-sm" placeholder="e.g. Import Pathway, Night School" maxlength="150">
            </div>
            <div class="qb-form-group">
                <label for="qb-aqf-select">AQF Level</label>
                <select id="qb-aqf-select" class="form-control form-control-sm" style="min-width:140px">
                    <option value="0">— Auto —</option>
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                    <option value="<?= $i ?>">AQF Level <?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="qb-form-group">
                <label for="qb-nominalhours-input">Nominal Hours</label>
                <input type="number" id="qb-nominalhours-input" class="form-control form-control-sm" placeholder="0" min="0" style="width:100px">
            </div>
        </div>

        <div class="qb-form-row">
            <div class="qb-form-group" style="flex:1;min-width:260px">
                <!-- ── TWO-LEVEL CATEGORY PICKER ──────────────────────────────────────
                     Step 1: Qualification root category (parent=0; stores the qual code
                             in its idnumber field; used for AVETMISS qualmap).
                     Step 2: Semester / Intake child (courses live here; used for
                             filtering the per-unit course dropdowns only — not stored
                             in qualmap).
                     The hidden #qb-category-select keeps the ROOT categoryid that gets
                     written to the DB on save (and read by the reconciler).
                ─────────────────────────────────────────────────────────────────── -->
                <label for="qb-qualcat-root">
                    Moodle Category <small class="text-muted">(qualification root)</small>
                </label>
                <select id="qb-qualcat-root" class="form-control form-control-sm">
                    <?= $rootCatsSel ?>
                </select>

                <label for="qb-semester-select" style="margin-top:6px">
                    Semester / Intake <small class="text-muted">(courses to link)</small>
                </label>
                <select id="qb-semester-select" class="form-control form-control-sm" disabled>
                    <option value="0">— pick qualification category first —</option>
                </select>
                <div id="qb-twolevel-note" class="text-muted" style="font-size:0.76rem;margin-top:3px">
                    The qualification root is used for AVETMISS reporting. The semester filters which courses appear below.
                </div>

                <!-- Hidden: keeps root categoryid in sync for the save payload.
                     JS always calls $('#qb-category-select').val(rootId) when root changes. -->
                <select id="qb-category-select" class="form-control form-control-sm" style="display:none" aria-hidden="true">
                    <?= $categoriesSel ?>
                </select>
                <div id="qb-category-suggestion" class="qb-suggestion-pills" style="display:none"></div>
                <div id="qb-map-all-feedback" class="qb-map-all-feedback"></div>
            </div>
        </div>

        <!-- TGA loaded banner -->
        <div id="qb-tga-banner" class="qb-tga-banner" style="display:none">
            <span style="font-size:1.2rem">&#10003;</span>
            <div id="qb-tga-banner-text" style="flex:1"></div>
            <button type="button" class="btn btn-outline-success btn-sm" id="qb-map-all-btn" style="white-space:nowrap" title="Auto-match all units to Moodle courses using the selected category">&#128279; Map All Courses</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="qb-reload-tga-btn" style="white-space:nowrap">&#8635; Reload TGA</button>
        </div>
        <div id="qb-tga-error" class="alert alert-warning" style="display:none"></div>
    </div>

    <!-- Packaging Rules -->
    <div class="qb-card" id="qb-rules-card" style="display:none">
        <div class="qb-card-title">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Packaging Rules
        </div>
        <div id="qb-rules-content" class="qb-rules-box"></div>
    </div>

    <!-- Compliance Dashboard -->
    <div class="qb-card" id="qb-compliance-card" style="display:none">
        <div class="qb-card-title">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Live Compliance Status
        </div>
        <div id="qb-compliance-cards" class="qb-compliance-cards"></div>
    </div>

    <!-- Unit Search Bar — shown once units are rendered; hidden until then -->
    <div class="qb-card" id="qb-unit-search-bar" style="display:none">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="flex-shrink:0;color:#6b7280"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35"/></svg>
            <input type="text" id="qb-unit-search" class="form-control form-control-sm"
                   placeholder="Search units by code or title…" style="max-width:300px"
                   autocomplete="off" spellcheck="false">
            <div style="display:flex;gap:4px" id="qb-unit-type-btns">
                <button type="button" class="btn btn-sm btn-outline-secondary qb-type-btn active" data-type="all">All</button>
                <button type="button" class="btn btn-sm btn-outline-secondary qb-type-btn" data-type="core">Core only</button>
                <button type="button" class="btn btn-sm btn-outline-secondary qb-type-btn" data-type="elective">Electives only</button>
            </div>
            <span id="qb-unit-count" style="margin-left:auto"></span>
        </div>
    </div>

    <!-- Unit Builder -->
    <div id="qb-unit-builder"></div>

    <!-- Actions -->
    <div class="qb-card" id="qb-actions-card">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <!-- BUG-MAY1-AUDIT #9 (v4.2.44): button was permanently btn-success
                 (green), which the tester read as "saved" even before saving.
                 Default colour is now btn-primary (blue); JS flips it to
                 btn-success with "Saved ✓" label for 2 s after a successful
                 save, then reverts to blue so the visual state always matches
                 the actual save status. -->
            <button type="button" class="btn btn-primary" id="qb-save-btn">
                Save Qualification
            </button>
            <a href="<?= $CFG->wwwroot ?>/local/rtocompliance/qualbuilder.php" class="btn btn-outline-secondary">
                Cancel
            </a>
            <?php if ($id): ?>
            <a href="<?= $CFG->wwwroot ?>/local/rtocompliance/qualbuilder_validate.php?id=<?= $id ?>&sesskey=<?= sesskey() ?>" class="btn btn-outline-primary ms-auto">
                Full Validation Report
            </a>
            <?php endif; ?>
            <span id="qb-save-status" class="text-muted" style="font-size:0.85rem"></span>
        </div>
    </div>

</div><!-- end #qb-smart-builder -->

<?php
// ROOT-CAUSE FIX for "Uncaught SyntaxError: Invalid or unexpected token" in first.js and
// "Uncaught Error: No define call for core/first" which caused Moodle site-admin primary and
// secondary navigation menus to disappear on the Qualification Builder edit page.
//
// HOW THE BUG WORKED:
//   Passing a large or complex PHP array via js_call_amd() causes Moodle to json_encode() it
//   inline inside a RequireJS require() call.  If the resulting JSON is malformed (e.g. from
//   invalid UTF-8 in DB text, stray backslashes, or encoding round-trip bugs from the now-
//   removed json_encode→json_decode→re-encode cycle), the embedded literal is a JavaScript
//   syntax error.  RequireJS encounters the error while loading core/first, throws
//   "No define call for core/first", and the entire AMD chain — including all navigation JS —
//   is aborted.
//
// FIX:
//   Embed the payload as a <script type="application/json"> DOM element instead.  The browser
//   never executes application/json content as JavaScript, so no RequireJS error can occur.
//   The AMD module reads and JSON.parse()s the element at runtime.  js_call_amd() is called
//   with an empty args array so it only boots the module with no inline data.
echo '<script type="application/json" id="qb-init-data">' . $jsPayload . '</script>';

$PAGE->requires->js_call_amd('local_rtocompliance/qualbuilder_edit', 'init', []);
?>

<?php echo $OUTPUT->footer(); ?>
