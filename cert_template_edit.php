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

// CERT-TEMPLATE-BUILDER (v4.2.40) — visual drag-and-drop editor.
//
// 3-column layout:
//   LEFT   — Collapsible accordion panels: Fields (open), Page Design, Branding, Template Info, Quick Guide
//   CENTRE — A4 canvas; absolutely-positioned divs the user drags around
//   RIGHT  — ASQA validator (top) + Field properties + Action buttons (bottom)
//
// All drag/drop/resize/selection is in amd/src/cert_template_editor.js.
// Designs are saved via POST to this same page (action=savedraft).
// Background image upload uses Moodle's filemanager → on POST we move
// the draft area to a permanent itemid stamped onto the design JSON.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/certificate_validator.php');
require_once(__DIR__ . '/classes/cert_template_renderer.php');

use local_rtocompliance\cert_template;
use local_rtocompliance\certificate_validator;
use local_rtocompliance\cert_template_renderer;

$id = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:managecerttemplates', $context);

$template = cert_template::get($id);
if (!$template) {
    throw new moodle_exception('invalidaccess');
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(format_string($template->name) . ' — ' . get_string('cert_templates', 'local_rtocompliance'));
$PAGE->set_heading(format_string($template->name));
$PAGE->requires->css('/local/rtocompliance/styles.css');

// Handle POST save.
if (data_submitted() && confirm_sesskey()) {
    $designjson = required_param('designjson', PARAM_RAW);
    $name       = trim(optional_param('name', '', PARAM_TEXT));
    // v4.3.0 CERT-TEMPLATE-AUDIENCES — admins can re-target an existing
    // template's audience from the editor at any time. Empty string means
    // "leave audience unchanged" (the field was not rendered, e.g. on an
    // older browser cached page).
    $audience_in      = optional_param('audience',      '', PARAM_ALPHANUMEXT);
    $audiencelabel_in = trim(optional_param('audiencelabel', '', PARAM_TEXT));

    $design = json_decode($designjson, true);
    if (!is_array($design)) {
        redirect($PAGE->url, 'Malformed design payload.', null, \core\output\notification::NOTIFY_ERROR);
    }

    // CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — system-wide RTO Branding
    // uploads (logo + CEO signature). Stored in the FA_BRANDING filearea
    // on the system context with pseudo-itemids 1 (logo) and 2 (signature)
    // so every template auto-paints them where rto.logo / signatory.signature
    // dynamic fields are placed.
    if (!empty($_FILES['brandinglogo']) && empty($_FILES['brandinglogo']['error']) && !empty($_FILES['brandinglogo']['tmp_name'])) {
        cert_template::save_branding_file(cert_template::BRANDING_ITEMID_LOGO, $_FILES['brandinglogo']);
    }
    if (!empty($_FILES['brandingsig']) && empty($_FILES['brandingsig']['error']) && !empty($_FILES['brandingsig']['tmp_name'])) {
        cert_template::save_branding_file(cert_template::BRANDING_ITEMID_SIGNATURE, $_FILES['brandingsig']);
    }
    // ASQA-COMPLIANCE-PASS-3 (v4.2.60) — State / Territory Training Authority logo upload.
    if (!empty($_FILES['brandingsta']) && empty($_FILES['brandingsta']['error']) && !empty($_FILES['brandingsta']['tmp_name'])) {
        cert_template::save_branding_file(cert_template::BRANDING_ITEMID_STA_LOGO, $_FILES['brandingsta']);
    }
    if (!empty(optional_param('brandinglogoclear', 0, PARAM_INT))) {
        cert_template::delete_branding_file(cert_template::BRANDING_ITEMID_LOGO);
    }
    if (!empty(optional_param('brandingsigclear', 0, PARAM_INT))) {
        cert_template::delete_branding_file(cert_template::BRANDING_ITEMID_SIGNATURE);
    }
    if (!empty(optional_param('brandingstaclear', 0, PARAM_INT))) {
        cert_template::delete_branding_file(cert_template::BRANDING_ITEMID_STA_LOGO);
    }
    // FIX-ORG-SEAL-UPLOAD (v5.0.3): Organisation seal was completely missing from the
    // POST handler. BRANDING_ITEMID_ORG_SEAL = 6 and get_branding_org_seal_url() existed
    // in cert_template.php since v4.4.0 but were never wired into the edit page.
    if (!empty($_FILES['brandingsealsave']) && empty($_FILES['brandingsealsave']['error']) && !empty($_FILES['brandingsealsave']['tmp_name'])) {
        cert_template::save_branding_file(cert_template::BRANDING_ITEMID_ORG_SEAL, $_FILES['brandingsealsave']);
    }
    if (!empty(optional_param('brandingsealclear', 0, PARAM_INT))) {
        cert_template::delete_branding_file(cert_template::BRANDING_ITEMID_ORG_SEAL);
    }

    // Background image upload (raw $_FILES['bgfile'], JS-attached).
    if (!empty($_FILES['bgfile']) && empty($_FILES['bgfile']['error']) && !empty($_FILES['bgfile']['tmp_name'])) {
        $upload = $_FILES['bgfile'];
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];
        if (in_array($upload['type'], $allowed, true)) {
            $fs = get_file_storage();
            // Replace any existing file in this area.
            $fs->delete_area_files($context->id, 'local_rtocompliance', cert_template::FA_BG, $template->id);
            $filerecord = (object) [
                'contextid' => $context->id,
                'component' => 'local_rtocompliance',
                'filearea'  => cert_template::FA_BG,
                'itemid'    => $template->id,
                'filepath'  => '/',
                'filename'  => clean_filename($upload['name']),
                'mimetype'  => $upload['type'],
            ];
            try {
                $fs->create_file_from_pathname($filerecord, $upload['tmp_name']);
                $design['page']['bg_itemid'] = $template->id;
                $url = moodle_url::make_pluginfile_url(
                    $context->id, 'local_rtocompliance', cert_template::FA_BG,
                    $template->id, '/', $filerecord->filename
                );
                $design['page']['bg_image_url'] = $url->out(false);
            } catch (\Throwable $e) {
                // Non-fatal — design saves without background.
                debugging('Cert template bg upload failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    $validation = cert_template::save_design($id, $design, $name !== '' ? $name : null);
    // Persist the resolved bgitemid to the template row too.
    if (!empty($design['page']['bg_itemid'])) {
        global $DB;
        $DB->update_record('local_rtocompliance_certtmpl', (object) [
            'id' => $id,
            'bgitemid' => (int) $design['page']['bg_itemid'],
        ]);
    }

    // v4.3.0 CERT-TEMPLATE-AUDIENCES — re-target audience if posted.
    // set_audience() coerces unknown codes to 'default' and is a no-op
    // on isactive (admins must re-activate via the list page so the
    // swap is visible and audited).
    if ($audience_in !== '') {
        cert_template::set_audience($id, $audience_in,
            $audiencelabel_in !== '' ? $audiencelabel_in : null);
    }

    $msg = get_string('cert_template_action_ok_saved', 'local_rtocompliance');
    if (!empty($validation['errors'])) {
        $msg .= ' (' . count($validation['errors']) . ' ASQA error(s) — see panel)';
    }
    redirect($PAGE->url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

$design = cert_template::decode_design($template);

// Build a serving URL for the existing background image, if any.
$bgurl = '';
if (!empty($template->bgitemid)) {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'local_rtocompliance', cert_template::FA_BG,
        $template->bgitemid, 'sortorder, filename', false);
    foreach ($files as $f) {
        if ($f->is_directory()) {
            continue;
        }
        $bgurl = moodle_url::make_pluginfile_url(
            $f->get_contextid(), $f->get_component(), $f->get_filearea(),
            $f->get_itemid(), $f->get_filepath(), $f->get_filename()
        )->out(false);
        break;
    }
}
if (!empty($bgurl)) {
    $design['page']['bg_image_url'] = $bgurl;
}

// Run validator immediately for the panel.
$validation = certificate_validator::validate_template_design($template->certtype, $design);

// Catalogue for the palette.
$catalogue = cert_template::get_dynamic_field_catalogue();

// Group catalogue entries by group.
$grouped = [];
foreach ($catalogue as $key => $meta) {
    $grouped[$meta['group']][$key] = $meta;
}

// Pre-fetch all branding URLs — needed for left panel status chips and JS data.
$brandinglogourl    = cert_template::get_branding_logo_url();
$brandingsigurl     = cert_template::get_branding_signature_url();
$brandingstaurl     = cert_template::get_branding_sta_logo_url();
$brandingsealsaveurl = cert_template::get_branding_org_seal_url();

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('cert_templates', 'local_rtocompliance'), null, null, 'certificates');

// Top-of-page title bar with back link + status.
echo html_writer::start_div('rtoc-tmpl-titlebar mb-3');
echo html_writer::link(new moodle_url('/local/rtocompliance/cert_templates.php'),
    '« ' . get_string('cert_templates', 'local_rtocompliance'),
    ['class' => 'btn btn-sm btn-outline-secondary mr-2']);
echo html_writer::tag('span',
    get_string('cert_template_certtype_' . $template->certtype, 'local_rtocompliance'),
    ['class' => 'badge bg-info text-white mr-1']);
echo html_writer::tag('span',
    get_string('cert_template_status_' . $template->status, 'local_rtocompliance'),
    ['class' => 'badge bg-secondary text-white mr-1']);
echo html_writer::end_div();

// Main 3-column grid.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url,
    'id'     => 'rtoc-tmpl-form',
    'enctype' => 'multipart/form-data',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'designjson', 'id' => 'rtoc-tmpl-designjson', 'value' => '']);

// Background image draft area (filemanager).
$draftitemid = file_get_submitted_draft_itemid('bgupload');
file_prepare_draft_area(
    $draftitemid, $context->id, 'local_rtocompliance', cert_template::FA_BG,
    $template->id, ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']]
);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bgdraftitemid', 'id' => 'rtoc-tmpl-bgdraftitemid', 'value' => $draftitemid]);

echo html_writer::start_div('rtoc-tmpl-grid');

// ── LEFT PANEL ────────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-left');

// ── SECTION 1: FIELDS ─────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-panel-section', ['id' => 'rtoc-acc-fields']);
echo html_writer::tag('div', 'Fields <span class="rtoc-panel-heading-hint">click or drag to add</span>', ['class' => 'rtoc-panel-heading']);
echo html_writer::start_div('rtoc-panel-body rtoc-panel-body--palette');

// Search box.
echo '<div class="rtoc-palette-search-wrap">'
   . '<svg class="rtoc-palette-search-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">'
   . '<circle cx="6.5" cy="6.5" r="4"/><line x1="10" y1="10" x2="14" y2="14"/>'
   . '</svg>'
   . html_writer::empty_tag('input', [
       'type'         => 'text',
       'id'           => 'rtoc-palette-search',
       'class'        => 'rtoc-palette-search',
       'placeholder'  => 'Search fields…',
       'autocomplete' => 'off',
   ])
   . '</div>';

// Group meta — color for each group.
$groupmeta = [
    'Student'              => ['color' => '#3b82f6'],
    'Qualification'        => ['color' => '#8b5cf6'],
    'Certificate'          => ['color' => '#f59e0b'],
    'RTO'                  => ['color' => '#0d9488'],
    'Signatory'            => ['color' => '#f43f5e'],
    'Mandatory phrases'    => ['color' => '#64748b'],
    'Compliance'           => ['color' => '#22c55e'],
    'Optional descriptors' => ['color' => '#f97316'],
    'Verification'         => ['color' => '#6366f1'],
];

// SVG icons for each group — meaningful, form-builder style.
$svgattr = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"';
$groupicons = [
    // Person silhouette — represents a student.
    'Student'              => '<svg ' . $svgattr . '><circle cx="12" cy="8" r="4"/><path d="M4 20c0-3.87 3.58-7 8-7s8 3.13 8 7"/></svg>',
    // Graduation cap — represents a qualification/course.
    'Qualification'        => '<svg ' . $svgattr . '><path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12.5v4.5c3 2 9 2 12 0v-4.5"/><line x1="22" y1="10" x2="22" y2="15"/></svg>',
    // Award ribbon — represents an issued certificate.
    'Certificate'          => '<svg ' . $svgattr . '><circle cx="12" cy="8" r="5"/><path d="M8.56 13.89L7 23l5-3 5 3-1.56-9.11"/></svg>',
    // Office building — represents the RTO organisation.
    'RTO'                  => '<svg ' . $svgattr . '><path d="M3 21h18M3 7l9-4 9 4v14M9 21v-8h6v8"/></svg>',
    // Pen/edit — represents signing/authorised signatory.
    'Signatory'            => '<svg ' . $svgattr . '><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 13.5-13.5z"/></svg>',
    // Align-left text lines — represents mandatory text phrases.
    'Mandatory phrases'    => '<svg ' . $svgattr . '><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>',
    // Shield with checkmark — represents compliance/regulatory fields.
    'Compliance'           => '<svg ' . $svgattr . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
    // Tag/label — represents optional descriptor fields.
    'Optional descriptors' => '<svg ' . $svgattr . '><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    // Magnifying glass — represents verification/lookup.
    'Verification'         => '<svg ' . $svgattr . '><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
];
// Custom-element icons.
$customicons = [
    // T-bar — freeform text.
    'text'  => '<svg ' . $svgattr . '><polyline points="4 7 4 4 20 4 20 7"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="9" y1="20" x2="15" y2="20"/></svg>',
    // Calendar — date field.
    'date'  => '<svg ' . $svgattr . '><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    // Picture frame — image placeholder.
    'image' => '<svg ' . $svgattr . '><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
    // Horizontal rule — divider line.
    'line'  => '<svg ' . $svgattr . '><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    // Empty square — box/rectangle.
    'box'   => '<svg ' . $svgattr . '><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
];

echo html_writer::start_div('rtoc-field-list', ['id' => 'rtoc-field-list']);

foreach ($grouped as $group => $items) {
    $gm = $groupmeta[$group] ?? ['color' => '#9ca3af', 'mono' => strtoupper(substr($group, 0, 2))];

    // Section header.
    echo html_writer::tag('div',
        html_writer::tag('span', '', ['class' => 'rtoc-fgh-dot', 'style' => 'background:' . $gm['color']]) .
        html_writer::tag('span', s($group), ['class' => 'rtoc-fgh-text']),
        ['class' => 'rtoc-field-group-header', 'data-group' => s($group)]
    );

    foreach ($items as $key => $meta) {
        $required = !empty($meta['required_for']) && in_array($template->certtype, $meta['required_for']);
        $forbidden = !empty($meta['forbidden_for']) && in_array($template->certtype, $meta['forbidden_for']);

        // Icon badge.
        $badge = html_writer::tag('span', $groupicons[$group] ?? '', [
            'class' => 'rtoc-field-icon',
            'style' => 'background:' . $gm['color'],
        ]);

        // Body: label + key.
        $body = html_writer::tag('span', s($meta['label']), ['class' => 'rtoc-field-label']) .
                html_writer::tag('span', s($key),            ['class' => 'rtoc-field-key']);

        // Right-side indicators.
        $indicators = '';
        if ($required) {
            $indicators .= html_writer::tag('span', '!', [
                'class' => 'rtoc-chip-req',
                'title' => 'Required for this certificate type',
            ]);
        }
        if ($forbidden) {
            $indicators .= html_writer::tag('span', '✕', [
                'class' => 'rtoc-field-forbidden-badge',
                'title' => 'Not allowed on this certificate type',
            ]);
        }

        $rowclass = 'rtoc-field-row';
        if ($forbidden) { $rowclass .= ' rtoc-field-row--forbidden'; }

        echo html_writer::tag('button',
            $badge .
            html_writer::tag('span', $body, ['class' => 'rtoc-field-body']) .
            ($indicators ? html_writer::tag('span', $indicators, ['class' => 'rtoc-field-indicators']) : ''),
            [
                'type'            => 'button',
                'class'           => $rowclass,
                'draggable'       => 'true',
                'data-add'        => 'dynamic',
                'data-dynamickey' => $key,
                'data-label'      => $meta['label'],
                'data-searchtext' => strtolower($meta['label'] . ' ' . $key . ' ' . $group),
                'title'           => s($meta['label']) . ' (' . s($key) . ')'
                    . ($required ? ' — required for this cert type' : '')
                    . ($forbidden ? ' — NOT ALLOWED on this cert type' : '')
                    . ' — drag onto canvas or click to add',
            ]
        );
    }
}

// Custom elements section.
$custommeta = [
    'text'  => ['string' => 'cert_template_palette_text',  'color' => '#64748b'],
    'date'  => ['string' => 'cert_template_palette_date',  'color' => '#0d9488'],
    'image' => ['string' => 'cert_template_palette_image', 'color' => '#f59e0b'],
    'line'  => ['string' => 'cert_template_palette_line',  'color' => '#94a3b8'],
    'box'   => ['string' => 'cert_template_palette_box',   'color' => '#94a3b8'],
];

echo html_writer::tag('div',
    html_writer::tag('span', '', ['class' => 'rtoc-fgh-dot', 'style' => 'background:#9ca3af']) .
    html_writer::tag('span', get_string('cert_template_palette_custom', 'local_rtocompliance'), ['class' => 'rtoc-fgh-text']),
    ['class' => 'rtoc-field-group-header', 'data-group' => 'custom']
);
foreach ($custommeta as $kind => $cm) {
    $clabel = get_string($cm['string'], 'local_rtocompliance');
    echo html_writer::tag('button',
        html_writer::tag('span', $customicons[$kind] ?? '', ['class' => 'rtoc-field-icon', 'style' => 'background:' . $cm['color']]) .
        html_writer::tag('span',
            html_writer::tag('span', $clabel,         ['class' => 'rtoc-field-label']) .
            html_writer::tag('span', 'custom element', ['class' => 'rtoc-field-key']),
            ['class' => 'rtoc-field-body']
        ),
        [
            'type'           => 'button',
            'class'          => 'rtoc-field-row rtoc-field-row--custom',
            'draggable'      => 'true',
            'data-add'       => $kind,
            'data-label'     => $clabel,
            'data-searchtext' => strtolower($clabel . ' custom ' . $kind),
            'title'          => $clabel . ' — drag onto canvas or click to add',
        ]
    );
}

echo html_writer::end_div(); // rtoc-field-list
echo html_writer::end_div(); // panel-body palette
echo html_writer::end_div(); // panel-section fields

// ── SECTION 2: PAGE DESIGN ────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-panel-section', ['id' => 'rtoc-acc-page']);
echo html_writer::tag('div', 'Page Design', ['class' => 'rtoc-panel-heading']);
echo html_writer::start_div('rtoc-panel-body');

echo html_writer::start_div('form-group mb-2');
echo html_writer::tag('label', get_string('cert_template_page_orientation', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-orient', 'class' => 'rtoc-form-label']);
echo html_writer::start_tag('select', ['id' => 'rtoc-tmpl-orient', 'class' => 'form-control form-control-sm']);
echo html_writer::tag('option', get_string('cert_template_page_orientation_l', 'local_rtocompliance'),
    ['value' => 'L'] + (($design['page']['orientation'] ?? 'L') === 'L' ? ['selected' => 'selected'] : []));
echo html_writer::tag('option', get_string('cert_template_page_orientation_p', 'local_rtocompliance'),
    ['value' => 'P'] + (($design['page']['orientation'] ?? 'L') === 'P' ? ['selected' => 'selected'] : []));
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-2');
echo html_writer::tag('label', get_string('cert_template_page_bgcolor', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-bgcolor', 'class' => 'rtoc-form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'color', 'id' => 'rtoc-tmpl-bgcolor', 'class' => 'form-control form-control-sm',
    'value' => $design['page']['bg_color'] ?? '#ffffff',
    'style' => 'height:32px;padding:2px 4px;',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-0');
echo html_writer::tag('label', get_string('cert_template_page_bgimage', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-bgupload', 'class' => 'rtoc-form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'file', 'id' => 'rtoc-tmpl-bgupload', 'class' => 'form-control-file form-control-sm',
    'accept' => 'image/png,image/jpeg,image/webp',
]);
echo html_writer::tag('small',
    'PNG / JPEG / WebP — 2480 × 1754 px at 300 DPI recommended.',
    ['class' => 'form-text text-muted']);
if (!empty($bgurl)) {
    echo html_writer::div(html_writer::img($bgurl, 'background', [
        'style' => 'max-width:100%; max-height:56px; margin-top:4px; border:1px solid #ccc; border-radius:3px;'
    ]), 'rtoc-tmpl-bgpreview');
    echo html_writer::tag('button',
        get_string('cert_template_page_bgimage_clear', 'local_rtocompliance'),
        ['type' => 'button', 'id' => 'rtoc-tmpl-bgclear', 'class' => 'btn btn-sm btn-outline-danger mt-1']);
}
echo html_writer::end_div();

echo html_writer::end_div(); // panel-body page
echo html_writer::end_div(); // panel-section page

// ── SECTION 3: BRANDING ───────────────────────────────────────────────────────
$missingBranding = empty($brandinglogourl) || empty($brandingsigurl);

echo html_writer::start_div('rtoc-panel-section', ['id' => 'rtoc-acc-branding']);
echo html_writer::tag('div', 'Branding', ['class' => 'rtoc-panel-heading']);
echo html_writer::start_div('rtoc-panel-body');
echo html_writer::tag('p', get_string('cert_template_branding_help', 'local_rtocompliance'),
    ['class' => 'small text-muted mb-2']);

// Each branding upload as a compact side-by-side row: thumbnail left, controls right.
foreach ([
    [
        'url'       => $brandinglogourl,
        'name'      => 'brandinglogo',
        'id'        => 'rtoc-tmpl-brandinglogo',
        'clearname' => 'brandinglogoclear',
        'clearid'   => 'rtoc-tmpl-brandinglogoclear',
        'label'     => get_string('cert_template_branding_logo', 'local_rtocompliance'),
        'alt'       => 'RTO logo',
    ],
    [
        'url'       => $brandingsigurl,
        'name'      => 'brandingsig',
        'id'        => 'rtoc-tmpl-brandingsig',
        'clearname' => 'brandingsigclear',
        'clearid'   => 'rtoc-tmpl-brandingsigclear',
        'label'     => get_string('cert_template_branding_signature', 'local_rtocompliance'),
        'alt'       => 'CEO signature',
    ],
    [
        'url'       => $brandingstaurl,
        'name'      => 'brandingsta',
        'id'        => 'rtoc-tmpl-brandingsta',
        'clearname' => 'brandingstaclear',
        'clearid'   => 'rtoc-tmpl-brandingstaclear',
        'label'     => get_string('cert_template_branding_sta_logo', 'local_rtocompliance'),
        'alt'       => 'STA logo',
    ],
    [
        'url'       => $brandingsealsaveurl,
        'name'      => 'brandingsealsave',
        'id'        => 'rtoc-tmpl-brandingsealsave',
        'clearname' => 'brandingsealclear',
        'clearid'   => 'rtoc-tmpl-brandingsealclear',
        'label'     => 'Organisation Seal',
        'alt'       => 'Organisation seal',
    ],
] as $brow) {
    echo html_writer::start_div('rtoc-brand-row');
    // Thumbnail column.
    echo html_writer::start_div('rtoc-brand-thumb');
    if (!empty($brow['url'])) {
        echo html_writer::img($brow['url'], $brow['alt'], [
            'style' => 'max-width:72px; max-height:40px; object-fit:contain; background:#f5f5f5; padding:3px; border:1px solid #ddd; border-radius:3px;',
        ]);
    } else {
        echo html_writer::tag('div', 'None', ['class' => 'rtoc-brand-none']);
    }
    echo html_writer::end_div();
    // Controls column.
    echo html_writer::start_div('rtoc-brand-controls');
    echo html_writer::tag('label', $brow['label'],
        ['for' => $brow['id'], 'class' => 'rtoc-form-label mb-1']);
    echo html_writer::empty_tag('input', [
        'type'   => 'file',
        'id'     => $brow['id'],
        'name'   => $brow['name'],
        'class'  => 'form-control-file form-control-sm',
        'accept' => 'image/png,image/jpeg,image/webp,image/svg+xml',
    ]);
    if (!empty($brow['url'])) {
        echo html_writer::start_div('form-check mt-1');
        echo html_writer::empty_tag('input', [
            'type'  => 'checkbox',
            'id'    => $brow['clearid'],
            'name'  => $brow['clearname'],
            'value' => '1',
            'class' => 'form-check-input',
        ]);
        echo html_writer::tag('label', get_string('cert_template_branding_clear', 'local_rtocompliance'),
            ['for' => $brow['clearid'], 'class' => 'form-check-label small']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div(); // brand-row
}

if ($missingBranding) {
    echo html_writer::div(
        get_string('cert_template_branding_missing', 'local_rtocompliance'),
        'alert alert-info small mt-2 mb-0 p-2');
}
echo html_writer::end_div(); // panel-body branding
echo html_writer::end_div(); // panel-section branding

// ── SECTION 4: TEMPLATE INFO ──────────────────────────────────────────────────
echo html_writer::start_div('rtoc-panel-section', ['id' => 'rtoc-acc-info']);
echo html_writer::tag('div', 'Template Info', ['class' => 'rtoc-panel-heading']);
echo html_writer::start_div('rtoc-panel-body');

echo html_writer::start_div('form-group mb-2');
echo html_writer::tag('label', get_string('cert_template_name', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-name', 'class' => 'rtoc-form-label']);
echo html_writer::empty_tag('input', [
    'type'      => 'text',
    'name'      => 'name',
    'id'        => 'rtoc-tmpl-name',
    'class'     => 'form-control form-control-sm',
    'value'     => $template->name,
    'required'  => 'required',
    'maxlength' => 255,
]);
echo html_writer::end_div();

// v4.3.0 CERT-TEMPLATE-AUDIENCES — audience picker + free-text label override.
$currentaudience = !empty($template->audience) ? $template->audience : 'default';
echo html_writer::start_div('form-group mb-2');
echo html_writer::tag('label', get_string('cert_template_audience', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-audience', 'class' => 'rtoc-form-label']);
echo html_writer::tag('p',
    get_string('cert_template_audience_help', 'local_rtocompliance'),
    ['class' => 'text-muted small mb-1']);
echo html_writer::start_tag('select', [
    'id' => 'rtoc-tmpl-audience', 'name' => 'audience', 'class' => 'form-control form-control-sm',
]);
foreach (cert_template::AUDIENCES as $aud) {
    echo html_writer::tag('option',
        get_string('cert_template_audience_' . $aud, 'local_rtocompliance'),
        ['value' => $aud] + ($aud === $currentaudience ? ['selected' => 'selected'] : []));
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('form-group mb-0');
echo html_writer::tag('label', get_string('cert_template_audiencelabel', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-audiencelabel', 'class' => 'rtoc-form-label']);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'id'          => 'rtoc-tmpl-audiencelabel',
    'name'        => 'audiencelabel',
    'class'       => 'form-control form-control-sm',
    'value'       => !empty($template->audiencelabel) ? $template->audiencelabel : '',
    'maxlength'   => 255,
    'placeholder' => get_string('cert_template_audiencelabel_placeholder', 'local_rtocompliance'),
]);
echo html_writer::end_div();

echo html_writer::end_div(); // panel-body info
echo html_writer::end_div(); // panel-section info

// ── SECTION 5: QUICK GUIDE ────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-panel-section', ['id' => 'rtoc-acc-guide']);
echo html_writer::tag('div', 'Quick Guide', ['class' => 'rtoc-panel-heading']);
echo html_writer::start_div('rtoc-panel-body small');
echo html_writer::tag('p',
    get_string('cert_template_quickstart_intro', 'local_rtocompliance'),
    ['class' => 'mb-2']);
echo html_writer::start_tag('ol', ['class' => 'mb-2', 'style' => 'padding-left:16px;']);
foreach ([
    'cert_template_quickstart_step1',
    'cert_template_quickstart_step2',
    'cert_template_quickstart_step3',
    'cert_template_quickstart_step4',
    'cert_template_quickstart_step5',
] as $stepkey) {
    echo html_writer::tag('li', get_string($stepkey, 'local_rtocompliance'), ['class' => 'mb-1']);
}
echo html_writer::end_tag('ol');
echo html_writer::tag('p',
    get_string('cert_template_quickstart_safety', 'local_rtocompliance'),
    ['class' => 'mb-0 text-muted']);
echo html_writer::end_div(); // panel-body guide
echo html_writer::end_div(); // panel-section guide

echo html_writer::end_div(); // left

// ── CENTRE PANEL ─────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-centre');

// CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — editor toolbar.
echo html_writer::start_div('rtoc-tmpl-toolbar mb-2 d-flex flex-wrap align-items-center gap-2',
    ['style' => 'gap:6px;']);
// Undo / redo.
echo html_writer::tag('button',
    '&#8630; ' . get_string('cert_template_toolbar_undo', 'local_rtocompliance'),
    ['type' => 'button', 'id' => 'rtoc-tmpl-undo', 'class' => 'btn btn-sm btn-outline-secondary',
     'disabled' => 'disabled', 'title' => 'Ctrl+Z']);
echo html_writer::tag('button',
    '&#8631; ' . get_string('cert_template_toolbar_redo', 'local_rtocompliance'),
    ['type' => 'button', 'id' => 'rtoc-tmpl-redo', 'class' => 'btn btn-sm btn-outline-secondary',
     'disabled' => 'disabled', 'title' => 'Ctrl+Shift+Z']);
// Zoom.
echo html_writer::tag('span', get_string('cert_template_toolbar_zoom', 'local_rtocompliance'),
    ['class' => 'small text-muted ml-2']);
echo html_writer::start_tag('select', ['id' => 'rtoc-tmpl-zoom', 'class' => 'form-control form-control-sm',
    'style' => 'width:auto;display:inline-block;']);
foreach ([50, 75, 100, 125, 150, 200] as $z) {
    echo html_writer::tag('option', $z . '%',
        ['value' => $z] + ($z === 100 ? ['selected' => 'selected'] : []));
}
echo html_writer::end_tag('select');
// Grid + sample-data toggles.
echo html_writer::start_div('form-check form-check-inline ml-2');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox', 'id' => 'rtoc-tmpl-grid', 'class' => 'form-check-input',
    'checked' => 'checked',
]);
echo html_writer::tag('label', get_string('cert_template_toolbar_grid', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-grid', 'class' => 'form-check-label small']);
echo html_writer::end_div();
echo html_writer::start_div('form-check form-check-inline');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox', 'id' => 'rtoc-tmpl-sample', 'class' => 'form-check-input',
]);
echo html_writer::tag('label', get_string('cert_template_toolbar_sample', 'local_rtocompliance'),
    ['for' => 'rtoc-tmpl-sample', 'class' => 'form-check-label small']);
echo html_writer::end_div();
echo html_writer::tag('span',
    get_string('cert_template_keyboard_help', 'local_rtocompliance'),
    ['class' => 'small text-muted ml-auto']);
echo html_writer::end_div();

echo html_writer::start_div('rtoc-tmpl-canvas-wrap');
$bgcolor = $design['page']['bg_color'] ?? '#ffffff';
$bgimage = !empty($design['page']['bg_image_url']) ? 'background-image:url(' . s($design['page']['bg_image_url']) . ');background-size:100% 100%;' : '';
echo html_writer::div('', 'rtoc-tmpl-canvas', [
    'id' => 'rtoc-tmpl-canvas',
    'data-orientation' => $design['page']['orientation'] ?? 'L',
    'style' => 'background-color:' . $bgcolor . ';' . $bgimage,
]);
echo html_writer::tag('div',
    'A4 ' . (($design['page']['orientation'] ?? 'L') === 'L' ? '297 × 210 mm landscape' : '210 × 297 mm portrait')
    . ' — drag fields to reposition; click to select; corner handle to resize',
    ['class' => 'rtoc-tmpl-canvas-caption text-muted small mt-2', 'id' => 'rtoc-tmpl-canvas-caption']);
echo html_writer::end_div();
echo html_writer::end_div(); // centre

// ── RIGHT PANEL ───────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-right');

// ── ASQA VALIDATOR — moved to top of right panel ─────────────────────────────
echo html_writer::start_div('rtoc-tmpl-section rtoc-validator-top');
echo html_writer::tag('h4', get_string('cert_template_validation', 'local_rtocompliance'),
    ['class' => 'h6 rtoc-section-h']);
echo html_writer::div('', 'rtoc-tmpl-validation', ['id' => 'rtoc-tmpl-validation']);

// Render the initial validation state server-side. Every error/warning that
// points at a missing dynamickey gets a one-click "Fix" button rendered inline.
$fixlabel = get_string('cert_template_validation_fix', 'local_rtocompliance');
$rendervalitem = function ($item) use ($fixlabel, $catalogue) {
    $msg = s($item['message']);
    $key = $item['field'] ?? '';
    $hasField = is_string($key) && $key !== '' && isset($catalogue[$key]);
    $isNotUsiError = !(strpos((string)($item['rule'] ?? ''), 'USI must NOT appear') !== false);
    $btn = '';
    if ($hasField && $isNotUsiError) {
        $btn = ' ' . html_writer::tag('button', $fixlabel, [
            'type'        => 'button',
            'class'       => 'btn btn-sm btn-outline-primary py-0 px-2 ml-1',
            'data-fix-key' => $key,
            'style'       => 'font-size:0.75rem;line-height:1.2;',
        ]);
    }
    return html_writer::tag('li', $msg . $btn);
};
ob_start();
if (empty($validation['errors']) && empty($validation['warnings'])) {
    echo html_writer::div(get_string('cert_template_validation_passed', 'local_rtocompliance'),
        'alert alert-success small mb-0 p-2');
} else {
    if (!empty($validation['errors'])) {
        echo html_writer::tag('div',
            html_writer::tag('strong', get_string('cert_template_validation_errors', 'local_rtocompliance')) .
            html_writer::start_tag('ul', ['class' => 'mb-0 pl-3']) .
            implode('', array_map($rendervalitem, $validation['errors'])) .
            html_writer::end_tag('ul'),
            ['class' => 'alert alert-danger small mb-2 p-2']);
    }
    if (!empty($validation['warnings'])) {
        echo html_writer::tag('div',
            html_writer::tag('strong', get_string('cert_template_validation_warnings', 'local_rtocompliance')) .
            html_writer::start_tag('ul', ['class' => 'mb-0 pl-3']) .
            implode('', array_map($rendervalitem, $validation['warnings'])) .
            html_writer::end_tag('ul'),
            ['class' => 'alert alert-warning small mb-0 p-2']);
    }
}
$initialValidationHtml = ob_get_clean();
echo html_writer::tag('script', 'document.getElementById("rtoc-tmpl-validation").innerHTML = ' . json_encode($initialValidationHtml) . ';');
echo html_writer::end_div(); // validator section

// ── FIELD PROPERTIES ──────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-section rtoc-props-section');
echo html_writer::tag('h4', get_string('cert_template_props', 'local_rtocompliance'),
    ['class' => 'h6 rtoc-section-h']);
echo html_writer::div(get_string('cert_template_props_select', 'local_rtocompliance'),
    'rtoc-tmpl-props-empty text-muted small', ['id' => 'rtoc-tmpl-props-empty']);

echo html_writer::start_div('rtoc-tmpl-props', ['id' => 'rtoc-tmpl-props', 'style' => 'display:none;']);

// ── Sub-group: Position & Size ────────────────────────────────────────────────
echo html_writer::start_tag('details', ['class' => 'rtoc-props-group', 'open' => 'open']);
echo html_writer::tag('summary', 'Position &amp; Size', ['class' => 'rtoc-props-group-summary']);
echo html_writer::start_div('rtoc-props-group-body');
foreach ([
    ['x', 'cert_template_prop_x', '0',   '500', '0.5'],
    ['y', 'cert_template_prop_y', '0',   '500', '0.5'],
    ['w', 'cert_template_prop_w', '1',   '500', '0.5'],
    ['h', 'cert_template_prop_h', '1',   '500', '0.5'],
] as [$name, $strkey, $min, $max, $step]) {
    echo html_writer::start_div('form-group form-row mb-1');
    echo html_writer::tag('label', get_string($strkey, 'local_rtocompliance'),
        ['for' => 'p-' . $name, 'class' => 'col-5 col-form-label col-form-label-sm']);
    echo html_writer::start_div('col-7');
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'id' => 'p-' . $name, 'class' => 'form-control form-control-sm',
        'min' => $min, 'max' => $max, 'step' => $step,
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div(); // pos group body
echo html_writer::end_tag('details');

// ── Sub-group: Typography ─────────────────────────────────────────────────────
echo html_writer::start_tag('details', ['class' => 'rtoc-props-group', 'open' => 'open', 'id' => 'p-typo-wrap']);
echo html_writer::tag('summary', 'Typography', ['class' => 'rtoc-props-group-summary']);
echo html_writer::start_div('rtoc-props-group-body');

// Text content (text/dynamic kinds).
echo html_writer::start_div('form-group mb-2', ['id' => 'p-text-wrap']);
echo html_writer::tag('label', get_string('cert_template_prop_text', 'local_rtocompliance'),
    ['for' => 'p-text', 'class' => 'rtoc-form-label']);
echo html_writer::tag('textarea', '',
    ['id' => 'p-text', 'class' => 'form-control form-control-sm', 'rows' => 2]);
echo html_writer::end_div();

// Date format (date kind).
echo html_writer::start_div('form-group mb-2', ['id' => 'p-dateformat-wrap']);
echo html_writer::tag('label', get_string('cert_template_prop_dateformat', 'local_rtocompliance'),
    ['for' => 'p-dateformat', 'class' => 'rtoc-form-label']);
echo html_writer::start_tag('select', ['id' => 'p-dateformat', 'class' => 'form-control form-control-sm']);
foreach (['d M Y' => '01 Jan 2026', 'd/m/Y' => '01/01/2026', 'D, j F Y' => 'Mon, 1 January 2026', 'F j, Y' => 'January 1, 2026'] as $f => $label) {
    echo html_writer::tag('option', $label, ['value' => $f]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

// Font family.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_font', 'local_rtocompliance'),
    ['for' => 'p-font', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::start_tag('select', ['id' => 'p-font', 'class' => 'form-control form-control-sm']);
foreach (['helvetica' => 'Helvetica', 'times' => 'Times', 'courier' => 'Courier'] as $v => $l) {
    echo html_writer::tag('option', $l, ['value' => $v]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();

// Font size.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_fontsize', 'local_rtocompliance'),
    ['for' => 'p-fontsize', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::empty_tag('input', [
    'type' => 'number', 'id' => 'p-fontsize', 'class' => 'form-control form-control-sm',
    'min' => 6, 'max' => 96, 'step' => 1,
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Font style.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_fontstyle', 'local_rtocompliance'),
    ['for' => 'p-fontstyle', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::start_tag('select', ['id' => 'p-fontstyle', 'class' => 'form-control form-control-sm']);
foreach (['' => 'Regular', 'B' => 'Bold', 'I' => 'Italic', 'BI' => 'Bold italic'] as $v => $l) {
    echo html_writer::tag('option', $l, ['value' => $v]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();

// Colour.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_color', 'local_rtocompliance'),
    ['for' => 'p-color', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::empty_tag('input', [
    'type' => 'color', 'id' => 'p-color', 'class' => 'form-control form-control-sm',
    'style' => 'height:28px;padding:1px 3px;',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Alignment.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_align', 'local_rtocompliance'),
    ['for' => 'p-align', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::start_tag('select', ['id' => 'p-align', 'class' => 'form-control form-control-sm']);
foreach (['L' => 'Left', 'C' => 'Centre', 'R' => 'Right'] as $v => $l) {
    echo html_writer::tag('option', $l, ['value' => $v]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // typo group body
echo html_writer::end_tag('details'); // typo group

// ── Sub-group: Appearance ─────────────────────────────────────────────────────
echo html_writer::start_tag('details', ['class' => 'rtoc-props-group', 'open' => 'open']);
echo html_writer::tag('summary', 'Appearance', ['class' => 'rtoc-props-group-summary']);
echo html_writer::start_div('rtoc-props-group-body');

// Image upload (image kind).
echo html_writer::start_div('form-group mb-2', ['id' => 'p-image-wrap']);
echo html_writer::tag('label', get_string('cert_template_prop_image', 'local_rtocompliance'),
    ['for' => 'p-image', 'class' => 'rtoc-form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'file', 'id' => 'p-image', 'class' => 'form-control-file form-control-sm',
    'accept' => 'image/png,image/jpeg,image/webp',
]);
echo html_writer::tag('small', 'Uploaded when you save.', ['class' => 'form-text text-muted']);
echo html_writer::end_div();

// Line width (line/box kinds).
echo html_writer::start_div('form-group form-row mb-1', ['id' => 'p-linewidth-wrap']);
echo html_writer::tag('label', get_string('cert_template_prop_linewidth', 'local_rtocompliance'),
    ['for' => 'p-linewidth', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::empty_tag('input', [
    'type' => 'number', 'id' => 'p-linewidth', 'class' => 'form-control form-control-sm',
    'min' => 0.1, 'max' => 5, 'step' => 0.1,
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // appearance group body
echo html_writer::end_tag('details'); // appearance group

echo html_writer::tag('button', get_string('cert_template_prop_delete', 'local_rtocompliance'),
    ['type' => 'button', 'id' => 'p-delete', 'class' => 'btn btn-sm btn-outline-danger mt-2 w-100']);

echo html_writer::end_div(); // props
echo html_writer::end_div(); // props section

// ── ACTION BUTTONS (sticky at bottom of right panel) ──────────────────────────
echo html_writer::start_div('rtoc-tmpl-actions');
echo html_writer::tag('button', get_string('cert_template_save_btn', 'local_rtocompliance'),
    ['type' => 'submit', 'class' => 'btn btn-primary w-100 mb-2', 'id' => 'rtoc-tmpl-save']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/cert_template_preview.php', ['id' => $id]),
    get_string('cert_template_preview_btn', 'local_rtocompliance'),
    ['class' => 'btn btn-outline-secondary w-100 mb-2', 'target' => '_blank', 'rel' => 'noopener']
);
if ($template->status === 'draft') {
    $submiturl = new moodle_url('/local/rtocompliance/cert_template_action.php', [
        'action' => 'submit', 'id' => $id, 'sesskey' => sesskey(),
    ]);
    echo html_writer::link($submiturl, get_string('cert_template_submit_btn', 'local_rtocompliance'),
        ['class' => 'btn btn-success w-100 mb-2', 'id' => 'rtoc-tmpl-submit']);
}
if ($template->status === 'approved' && !$template->isactive) {
    $activateurl = new moodle_url('/local/rtocompliance/cert_template_action.php', [
        'action' => 'activate', 'id' => $id, 'sesskey' => sesskey(),
    ]);
    echo html_writer::link($activateurl, get_string('cert_template_activate_btn', 'local_rtocompliance'),
        ['class' => 'btn btn-primary w-100 mb-2',
         'onclick' => "return confirm(" . json_encode(get_string('cert_template_confirm_activate', 'local_rtocompliance')) . ");"]);
}
echo html_writer::end_div(); // actions

echo html_writer::end_div(); // right
echo html_writer::end_div(); // grid
echo html_writer::end_tag('form');

// CERT-TEMPLATE-BUILDER-PRO (v4.2.43) — pass everything the editor JS
// needs: design state, dynamic-field catalogue, the starter-design
// coordinates so one-click Fix buttons can re-add a missing required
// field at the recommended position, and the realistic sample payload
// so the sample-data toolbar toggle can swap field placeholders for
// real-looking text in-place.
$starterdesign = cert_template::build_starter_design($template->certtype);
$starterindex = [];
foreach (($starterdesign['fields'] ?? []) as $sf) {
    if (($sf['kind'] ?? '') === 'dynamic' && !empty($sf['dynamickey'])) {
        $starterindex[$sf['dynamickey']] = $sf;
    }
}
$samplepayload = cert_template_renderer::sample_payload($template->certtype);
// Pass branding URLs so the JS can paint live previews of the rto.logo,
// signatory.signature, and organisation_seal dynamic fields in the canvas.
$brandinglogourl    = $brandinglogourl ?? cert_template::get_branding_logo_url();
$brandingsigurl     = $brandingsigurl  ?? cert_template::get_branding_signature_url();
// FIX-ORG-SEAL-JSDATA (v5.0.3): org seal URL was never passed to JS, so
// even with a seal uploaded, the canvas preview showed a text placeholder.
$brandingstaurl     = $brandingstaurl  ?? cert_template::get_branding_sta_logo_url();
$brandingorgsealurl = cert_template::get_branding_org_seal_url() ?: '';

// v4.2.48 BUG-MAY2-AUDIT — the canvas was hardcoded to 297x210 (landscape),
// which broke portrait templates (statement of attainment, record of
// results) by stretching their fields into the wrong aspect ratio in the
// editor. Use the saved design's actual page dimensions, falling back to
// the orientation-aware A4 default.
$pageW = (float) ($design['page']['width_mm']  ?? (($design['page']['orientation'] ?? 'L') === 'P' ? 210 : 297));
$pageH = (float) ($design['page']['height_mm'] ?? (($design['page']['orientation'] ?? 'L') === 'P' ? 297 : 210));

$jsdata = [
    'design'         => $design,
    'catalogue'      => $catalogue,
    'certtype'       => $template->certtype,
    'pageW'          => $pageW,
    'pageH'          => $pageH,
    'starterindex'   => $starterindex,
    'samplepayload'  => $samplepayload,
    'brandinglogourl'    => $brandinglogourl    ?: '',
    'brandingsigurl'     => $brandingsigurl     ?: '',
    'brandingorgsealurl' => $brandingorgsealurl ?: '',
];

echo html_writer::tag('script', 'window.RTOC_TMPL_DATA = ' . json_encode($jsdata) . ';');
$PAGE->requires->js_call_amd('local_rtocompliance/cert_template_editor', 'init');

echo $OUTPUT->footer();
