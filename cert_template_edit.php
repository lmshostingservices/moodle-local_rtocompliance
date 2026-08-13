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
 * RTO Compliance plugin — cert_template_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        // MALFORMED-PAYLOAD-FIX (v5.9.449): distinguish an empty payload (layout never
        // reached the server) from invalid JSON, and reassure the admin their saved
        // copy is unchanged. The editor now guards against posting an empty designjson,
        // so reaching here should be rare.
        $reason = (trim((string)$designjson) === '')
            ? 'the layout data did not reach the server'
            : 'the layout data was not valid (' . json_last_error_msg() . ')';
        redirect($PAGE->url,
            'Could not save the template — ' . $reason . '. Your previously saved version is unchanged.',
            null, \core\output\notification::NOTIFY_ERROR);
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

    // CERT-FIELD-IMAGE-UPLOAD (v5.9.366) — per-field image fields.
    // The editor stores a picked image as a base64 data URL in field.imageurl
    // and renders it in-canvas, but the renderer only paints per-field images
    // from a stored file resolved by imageitemid. Convert any data-URL image
    // field into a stored FA_IMAGE file with a unique itemid, stamp imageitemid,
    // and clear imageurl so the base64 blob never bloats designjson. Image fields
    // that already carry an imageitemid (and only a stale display URL) get their
    // imageurl cleared too, keeping the persisted design clean.
    if (!empty($design['fields']) && is_array($design['fields'])) {
        $fs = get_file_storage();
        foreach ($design['fields'] as $i => &$fld) {
            if (($fld['kind'] ?? '') !== 'image') {
                continue;
            }
            $durl = (string) ($fld['imageurl'] ?? '');
            if (preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#s', $durl, $m)) {
                $bytes = base64_decode($m[2], true);
                if ($bytes !== false && $bytes !== '') {
                    // Unique, stable itemid per field slot within this template.
                    $itemid = (int) $template->id * 1000 + (int) $i;
                    $ext = ($m[1] === 'jpeg' || $m[1] === 'jpg') ? 'jpg' : $m[1];
                    try {
                        $fs->delete_area_files($context->id, 'local_rtocompliance', cert_template::FA_IMAGE, $itemid);
                        $fs->create_file_from_string((object) [
                            'contextid' => $context->id,
                            'component' => 'local_rtocompliance',
                            'filearea'  => cert_template::FA_IMAGE,
                            'itemid'    => $itemid,
                            'filepath'  => '/',
                            'filename'  => 'field' . $i . '.' . $ext,
                        ], $bytes);
                        $fld['imageitemid'] = $itemid;
                        $fld['imageurl']    = '';
                    } catch (\Throwable $e) {
                        // Non-fatal — field just renders blank until re-uploaded.
                        debugging('Cert field image save failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            } else if (!empty($fld['imageitemid']) && strpos($durl, 'data:image/') !== 0) {
                // Stale display URL (pluginfile) — the stored file is the source of
                // truth; keep designjson free of environment-specific URLs.
                $fld['imageurl'] = '';
            }
        }
        unset($fld);
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
    // v6.2.78 SAVE & APPROVE (one click): after saving the draft, hand off to the submit-for-
    // approval action, which validates and either submits it or reports the ASQA errors.
    if (optional_param('saveandapprove', 0, PARAM_INT)) {
        redirect(new moodle_url('/local/rtocompliance/cert_template_action.php',
            ['action' => 'submit', 'id' => $id, 'sesskey' => sesskey()]));
    }
    redirect($PAGE->url, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

$design = cert_template::decode_design($template);
// LAYOUT-RULES (v5.9.449): apply the global layout rules (drop DATE / AUTHORISED
// PERSON captions; 30mm NRT logo, org seal and QR) to the editor canvas too, so
// what the admin sees matches the issued PDF exactly and the next save persists it.
$design = cert_template::normalise_design($design);

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
// CERT-EDITOR-BRANDING-GAPS (v5.9.339) — use the shared helper for type-specific gap check.
$_branding_status   = local_rtocompliance_get_branding_status();

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('cert_templates', 'local_rtocompliance'), null, null, 'certificates');
echo local_rtocompliance_page_banner(get_string('cert_templates', 'local_rtocompliance'));

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

// CERT-EDITOR-BRANDING-GAPS (v5.9.339) — compact inline notice when required
// branding assets are missing.  Only assets relevant to this cert type are shown
// (NRT logo and organisation seal are only required on testamur + statement).
// Each warning links directly to the matching RTO Settings field so the admin
// can fix the gap without leaving their workflow.
// Uses the shared local_rtocompliance_get_branding_status() helper so the gap
// check stays in sync with cert_templates.php and any future certificate pages.
$_rtoc_settings_base = (new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']))->out(false);
$_is_aqf_type = in_array($template->certtype, ['testamur', 'statement'], true);
$_rtoc_missing_items = [];
if (!$_branding_status['logo']) {
    $_rtoc_missing_items[] = html_writer::tag('li',
        html_writer::tag('strong', 'RTO logo') . ' — ' .
        html_writer::link($_rtoc_settings_base . '#adminsetting-local_rtocompliance_logo',
            'Upload in RTO Settings →', ['class' => 'alert-link']));
}
if (!$_branding_status['signature']) {
    $_rtoc_missing_items[] = html_writer::tag('li',
        html_writer::tag('strong', 'CEO / authorised signatory signature') . ' — ' .
        html_writer::link($_rtoc_settings_base . '#adminsetting-local_rtocompliance_ceo_signature_file',
            'Upload in RTO Settings →', ['class' => 'alert-link']));
}
if ($_is_aqf_type && !$_branding_status['nrt_override']) {
    $_rtoc_missing_items[] = html_writer::tag('li',
        html_writer::tag('strong', 'NRT logo (required for AQF certificates)') . ' — ' .
        html_writer::link($_rtoc_settings_base . '#adminsetting-local_rtocompliance_nrt_logo_file',
            'Upload in RTO Settings →', ['class' => 'alert-link']));
}
if ($_is_aqf_type && !$_branding_status['seal']) {
    $_rtoc_missing_items[] = html_writer::tag('li',
        html_writer::tag('strong', 'Organisation seal') . ' — ' .
        html_writer::link($_rtoc_settings_base . '#adminsetting-local_rtocompliance_organisation_seal_file',
            'Upload in RTO Settings →', ['class' => 'alert-link']));
}
if (!empty($_rtoc_missing_items)) {
    echo html_writer::start_div('alert alert-warning rtoc-branding-gap-notice mb-3 py-2 px-3');
    echo html_writer::tag('strong', '⚠ Missing branding assets for this certificate type');
    echo html_writer::tag('p',
        'The canvas preview will show blank boxes until these assets are uploaded.',
        ['class' => 'mb-1 mt-1 small']
    );
    echo html_writer::tag('ul', implode('', $_rtoc_missing_items), ['class' => 'mb-0 pl-4 small']);
    echo html_writer::end_div();
}
unset($_rtoc_settings_base, $_is_aqf_type, $_rtoc_missing_items);

// Main 3-column grid.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url,
    'id'     => 'rtoc-tmpl-form',
    'enctype' => 'multipart/form-data',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
// MALFORMED-PAYLOAD-FIX (v5.9.454): seed the hidden field with the CURRENT saved design
// (not an empty string). The editor JS overwrites this with the edited design on load and
// on every change; but if the JS ever fails to run (stale cache, a JS error, JS disabled),
// the form now posts a valid existing design instead of an empty payload — so a save can
// never fail with "malformed / did not reach the server", and nothing is lost.
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'designjson', 'id' => 'rtoc-tmpl-designjson',
    'value' => json_encode($design, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)]);

// Background image draft area (filemanager).
$draftitemid = file_get_submitted_draft_itemid('bgupload');
file_prepare_draft_area(
    $draftitemid, $context->id, 'local_rtocompliance', cert_template::FA_BG,
    $template->id, ['subdirs' => 0, 'maxfiles' => 1, 'accepted_types' => ['image']]
);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bgdraftitemid', 'id' => 'rtoc-tmpl-bgdraftitemid', 'value' => $draftitemid]);

// v6.2.78 TOP ACTION BAR: Save draft + one-click Save & Approve + Preview at the top of the
// editor (in addition to the existing buttons in the right panel). Sticky so it stays reachable
// as the page scrolls. Submit buttons post the main editor form (the JS submit handler serialises
// the design for any submit button).
echo html_writer::start_div('rtoc-tmpl-topbar', ['id' => 'rtoc-tmpl-topbar']);
echo html_writer::tag('div', format_string($template->name), ['class' => 'rtoc-tmpl-topbar-title']);
echo html_writer::start_div('rtoc-tmpl-topbar-actions');
echo html_writer::tag('button', get_string('cert_template_save_btn', 'local_rtocompliance'),
    ['type' => 'submit', 'class' => 'btn btn-primary btn-sm', 'id' => 'rtoc-tmpl-save-top']);
if ($template->status === 'draft') {
    echo html_writer::tag('button', 'Save &amp; Approve',
        ['type' => 'submit', 'name' => 'saveandapprove', 'value' => '1',
         'class' => 'btn btn-success btn-sm', 'id' => 'rtoc-tmpl-saveapprove-top',
         'title' => 'Save the draft and submit it for approval in one click']);
}
echo html_writer::link(
    new moodle_url('/local/rtocompliance/cert_template_preview.php', ['id' => $id]),
    get_string('cert_template_preview_btn', 'local_rtocompliance'),
    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank', 'rel' => 'noopener']);
echo html_writer::end_div(); // topbar-actions
echo html_writer::end_div(); // topbar



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
            $indicators .= html_writer::tag('span', 'Required', [
                'class' => 'rtoc-chip-req',
                'title' => 'This field is required for this certificate type (it is not an error).',
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

// ROR-TABLE-AUTHOR (v5.9.366) — dedicated palette chip for the Record-of-Results
// units table (kind=ror_table). Previously this field could only be recovered via
// "Reset to ASQA starter"; now it can be added, moved and resized like any element.
$rortableicon = '<svg ' . $svgattr . '><rect x="3" y="3" width="18" height="18" rx="2"/>'
    . '<line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/>'
    . '<line x1="15" y1="9" x2="15" y2="21"/></svg>';
echo html_writer::tag('div',
    html_writer::tag('span', '', ['class' => 'rtoc-fgh-dot', 'style' => 'background:#8b5cf6']) .
    html_writer::tag('span', get_string('cert_template_palette_rortable_group', 'local_rtocompliance'), ['class' => 'rtoc-fgh-text']),
    ['class' => 'rtoc-field-group-header', 'data-group' => 'rortable']
);
echo html_writer::tag('button',
    html_writer::tag('span', $rortableicon, ['class' => 'rtoc-field-icon', 'style' => 'background:#8b5cf6']) .
    html_writer::tag('span',
        html_writer::tag('span', get_string('cert_template_palette_rortable', 'local_rtocompliance'), ['class' => 'rtoc-field-label']) .
        html_writer::tag('span', 'Record of Results table', ['class' => 'rtoc-field-key']),
        ['class' => 'rtoc-field-body']
    ),
    [
        'type'            => 'button',
        'class'           => 'rtoc-field-row rtoc-field-row--custom',
        'draggable'       => 'true',
        'data-add'        => 'ror_table',
        'data-label'      => get_string('cert_template_palette_rortable', 'local_rtocompliance'),
        'data-searchtext' => 'record of results units table ror semester result',
        'title'           => get_string('cert_template_palette_rortable', 'local_rtocompliance') . ' — drag onto canvas or click to add',
    ]
);

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
$missingBranding = !$_branding_status['logo'] || !$_branding_status['signature'];

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
// WIDE-CANVAS (v6.2.31): collapse the right properties inspector to give the canvas the
// full width; click again to bring it back. Purely a layout convenience.
echo html_writer::tag('button',
    '&#8596; Wide canvas',
    ['type' => 'button', 'id' => 'rtoc-tmpl-wide', 'class' => 'btn btn-sm btn-outline-secondary ml-2',
     'title' => 'Hide the properties panel to widen the design canvas']);
// v6.2.88: the "Float properties" button now lives on the properties BAR itself (the full-width
// horizontal bar above the two pages), not in the canvas toolbar — see .rtoc-props-bar below.
// v6.2.88 TWO-EQUAL-PDFS: the sample-data note and keyboard help were long spans that made the
// toolbar wrap onto extra rows, pushing the canvas down out of line with the preview. They now
// sit BELOW the canvas (in the caption block), keeping the toolbar to a single row so the edit
// canvas and the live preview top-align.
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
echo html_writer::end_div(); // canvas-wrap

// LIVE-PREVIEW (v5.9.366) — real TCPDF preview of the CURRENT unsaved design,
// rendered by cert_template_preview.php through the identical issuance path.
// The editor JS posts the serialized design into the hidden form (debounced) and
// the PDF streams into the iframe, so authors see edits as a true-to-issue preview
// without saving first. Kept in a separate <form> from the main editor form so a
// preview refresh never submits/saves the template.

echo html_writer::end_div(); // centre

// ── RIGHT PANEL ───────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-right');

// v6.2.91 PROPERTIES ON THE RIGHT: single page in the centre, field properties docked as the
// right-hand column (no floating). Shown when a field is selected; the canvas takes the full
// width when nothing is selected (the existing slide-over).
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
// ALIGN-ON-PAGE (v6.2.58): one-click alignment of the selected field to the page — the fastest
// way to get "equal distance from the edges" (centre) and clean edges without dragging.
echo html_writer::start_div('rtoc-align-toolbar', ['style' => 'margin-top:8px;']);
echo html_writer::tag('div', 'Align on page', ['style' => 'font-size:0.75rem;color:#6b7280;font-weight:600;margin-bottom:4px;']);
echo html_writer::start_div('', ['style' => 'display:flex;gap:4px;']);
foreach ([
    ['left', 'Align to left margin', '⇤'], ['centerh', 'Centre horizontally (equal side margins)', '↔'], ['right', 'Align to right margin', '⇥'],
    ['top', 'Align to top margin', '⤒'], ['centerv', 'Centre vertically (equal top/bottom)', '↕'], ['bottom', 'Align to bottom margin', '⤓'],
] as [$a, $title, $icon]) {
    echo html_writer::tag('button', $icon, [
        'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary rtoc-align-btn',
        'data-align' => $a, 'title' => $title, 'style' => 'flex:1 1 0;min-width:0;font-size:1rem;line-height:1;padding:5px 0;',
    ]);
}
echo html_writer::end_div();
echo html_writer::end_div(); // rtoc-align-toolbar
echo html_writer::end_div(); // pos group body
echo html_writer::end_tag('details');

// ROR-CAPACITY-HINT (v5.9.340) — overflow estimate hint shown only when a
// ror_table-type dynamic field (units or RoR columns) is selected. Updated
// live by cert_template_editor.js as the admin changes height or font size.
echo html_writer::div('', 'rtoc-ror-capacity-hint', ['id' => 'p-ror-capacity-hint', 'style' => 'display:none;']);

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
// FONTS (v6.2.63): built-in families + a curated Google Fonts list. The canvas previews the real
// Google font (webfont); the PDF uses the real font when embedded, else the closest built-in.
echo html_writer::start_tag('optgroup', ['label' => 'Standard (always exact on PDF)']);
foreach (['helvetica' => 'Helvetica', 'times' => 'Times', 'courier' => 'Courier'] as $v => $l) {
    echo html_writer::tag('option', $l, ['value' => $v]);
}
echo html_writer::end_tag('optgroup');
echo html_writer::start_tag('optgroup', ['label' => 'Google Fonts']);
foreach (\local_rtocompliance\cert_template::font_catalogue() as $fkey => $fmeta) {
    echo html_writer::tag('option', $fmeta['label'], ['value' => $fkey]);
}
echo html_writer::end_tag('optgroup');
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();

// Font size.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_fontsize', 'local_rtocompliance'),
    ['for' => 'p-fontsize', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::empty_tag('input', [
    // NO-MIN-FONT (v6.2.52): the forced 12pt minimum was removed — authors may choose any
    // legible size. The input allows down to 4pt (tables auto-shrink to fit regardless).
    'type' => 'number', 'id' => 'p-fontsize', 'class' => 'form-control form-control-sm',
    'min' => 4, 'max' => 96, 'step' => 1,
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

// SNAP-TO-CENTRE (v6.2.25): one-click centring of the selected element on the page —
// easier than reading the X/Y boxes. Horizontal and vertical are independent.
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', 'Centre on page',
    ['class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7 d-flex', ['style' => 'gap:6px;']);
echo html_writer::tag('button', 'Centre across',
    ['type' => 'button', 'id' => 'p-centre-h', 'class' => 'btn btn-sm btn-outline-primary py-0 px-2',
     'title' => 'Centre this element horizontally on the page']);
echo html_writer::tag('button', 'Centre down',
    ['type' => 'button', 'id' => 'p-centre-v', 'class' => 'btn btn-sm btn-outline-primary py-0 px-2',
     'title' => 'Centre this element vertically on the page']);
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

// ROR-TABLE-AUTHOR (v5.9.366) — column widths (mm) for the Record-of-Results
// units table field. Shown only when a ror_table field is selected (toggled by
// cert_template_editor.js). Defaults 30 / 110 / 36 mm match the renderer.
echo html_writer::start_div('form-group mb-1', ['id' => 'p-rorcols-wrap', 'style' => 'display:none;']);
echo html_writer::tag('label', get_string('cert_template_prop_rorcols', 'local_rtocompliance'),
    ['class' => 'rtoc-form-label']);
foreach ([
    ['p-col1w', 'cert_template_prop_rorcol1', '30'],
    ['p-col2w', 'cert_template_prop_rorcol2', '110'],
    ['p-col3w', 'cert_template_prop_rorcol3', '36'],
] as [$cid, $cstr, $cdef]) {
    echo html_writer::start_div('form-group form-row mb-1');
    echo html_writer::tag('label', get_string($cstr, 'local_rtocompliance'),
        ['for' => $cid, 'class' => 'col-5 col-form-label col-form-label-sm']);
    echo html_writer::start_div('col-7');
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'id' => $cid, 'class' => 'form-control form-control-sm',
        'min' => 5, 'max' => 260, 'step' => 1, 'value' => $cdef,
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
// ROR-RESULTS-COLUMN (v6.2.9): choose what the third column of the units table shows —
// a completion date (Statements of Attainment) or the assessment Results (Records of Results).
echo html_writer::start_div('form-group form-row mb-1');
echo html_writer::tag('label', get_string('cert_template_prop_col3mode', 'local_rtocompliance'),
    ['for' => 'p-col3mode', 'class' => 'col-5 col-form-label col-form-label-sm']);
echo html_writer::start_div('col-7');
echo html_writer::start_tag('select', ['id' => 'p-col3mode', 'class' => 'form-control form-control-sm']);
echo html_writer::tag('option', get_string('cert_template_prop_col3mode_date', 'local_rtocompliance'), ['value' => 'date']);
echo html_writer::tag('option', get_string('cert_template_prop_col3mode_result', 'local_rtocompliance'), ['value' => 'result']);
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // appearance group body
echo html_writer::end_tag('details'); // appearance group

echo html_writer::tag('button', get_string('cert_template_prop_delete', 'local_rtocompliance'),
    ['type' => 'button', 'id' => 'p-delete', 'class' => 'btn btn-sm btn-outline-danger mt-2 w-100']);

echo html_writer::end_div(); // props
echo html_writer::end_div(); // props section


// v6.2.90 SINGLE PAGE: the separate live-preview PDF was removed. The editing canvas already shows
// the coherent sample record (Jane Citizen / BSB30120) exactly as issued, so it IS the preview —
// one page, centred and full width. The true TCPDF render is still one click away via the "Preview"
// button in the top action bar (opens the issued PDF in a new tab). The hidden preview form below
// is also removed, so the live-preview JS simply no-ops.


// ── ASQA VALIDATOR — moved to top of right panel ─────────────────────────────
echo html_writer::start_div('rtoc-tmpl-section rtoc-validator-top');
echo html_writer::tag('h4', get_string('cert_template_validation', 'local_rtocompliance'),
    ['class' => 'h6 rtoc-section-h']);
echo html_writer::div('', 'rtoc-tmpl-validation', ['id' => 'rtoc-tmpl-validation']);

// Render the initial validation state server-side. Every error/warning that
// points at a missing dynamickey gets a one-click "Fix" button rendered inline.
// LIVE-VALIDATION (v6.2.16): the panel markup is now produced by the shared
// certificate_validator::render_validation_panel_html() so the initial state and
// the live AJAX re-validation (cert_template_validate.php) render identically —
// meaning a recommendation clears the instant its field is added, no reload.
$initialValidationHtml = \local_rtocompliance\certificate_validator::render_validation_panel_html(
    $validation, $catalogue);
// CERT-EDITOR-XSS (v5.9.406): JSON_HEX_* flags neutralise </script>, quotes and
// ampersands so template text fields can never break out of this inline <script>.
echo html_writer::tag('script', 'document.getElementById("rtoc-tmpl-validation").innerHTML = ' . json_encode($initialValidationHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';');
echo html_writer::end_div(); // validator section


// ── ACTION BUTTONS (sticky at bottom of right panel) ──────────────────────────
echo html_writer::start_div('rtoc-tmpl-actions');

// CERT-AUTODESIGN removed (v6.2.51) — the "Auto-design from an image (AI)" button was
// unreliable at placing fields, so it has been withdrawn. Authors build from the ASQA-
// compliant starter template and the drag-and-drop palette instead.

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

// v6.2.85 STAGE 3 (beta, opt-in): "Floating properties" toggle. Lifts the field-properties
// panel into a draggable, TinyMCE-style docked toolbar so the canvas + live preview take the
// full width. Pure additive inline JS + a CSS class — it does NOT touch any property control's
// id, so the editor AMD module is unaffected; turning it off restores the docked column exactly.
// While floating, we set data-manual-wide=1 on the grid so the existing auto slide-over stops
// collapsing the right column (the live preview must stay visible). State + last drag position
// are remembered per browser so the author's choice sticks between visits.
echo html_writer::tag('script', <<<'FLOATPROPS'
(function () {
  var grid = document.querySelector('.rtoc-tmpl-grid');
  var bar  = document.getElementById('rtoc-props-bar');
  var btn  = document.getElementById('rtoc-tmpl-floatprops');
  var props = document.querySelector('.rtoc-props-section');
  if (!bar || !btn || !props) { return; }
  // Properties now dock in a bar ABOVE the two pages, so pin the old right-column slide-over open
  // — it must never collapse the preview column (which would hide the second page).
  if (grid) { grid.setAttribute('data-manual-wide', '1'); grid.classList.remove('rtoc-inspector-collapsed'); }
  var KEY = 'rtoc_floatprops';
  var POSKEY = 'rtoc_floatprops_pos';
  function store(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }

  function applyPos() {
    var raw = load(POSKEY);
    if (!raw) { return; }
    try {
      var p = JSON.parse(raw);
      if (p && typeof p.left === 'number' && typeof p.top === 'number') {
        // Clamp into the viewport so a saved position can never strand the panel off-screen.
        var maxL = Math.max(0, window.innerWidth - 80);
        var maxT = Math.max(0, window.innerHeight - 60);
        props.style.left = Math.min(Math.max(0, p.left), maxL) + 'px';
        props.style.top = Math.min(Math.max(0, p.top), maxT) + 'px';
        props.style.right = 'auto';
        props.style.bottom = 'auto';
      }
    } catch (e) {}
  }

  function setFloating(on) {
    bar.classList.toggle('rtoc-props-floating', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.classList.toggle('btn-primary', on);
    btn.classList.toggle('btn-outline-secondary', !on);
    if (on) {
      applyPos();
    } else {
      // Dock back into the bar (clear any dragged coordinates).
      props.style.left = props.style.top = props.style.right = props.style.bottom = '';
    }
    store(KEY, on ? '1' : '0');
  }

  btn.addEventListener('click', function () {
    setFloating(!bar.classList.contains('rtoc-props-floating'));
  });

  // Lightweight drag by the "Field properties" header while floating (TinyMCE-style).
  var header = props.querySelector('.rtoc-section-h');
  if (header) {
    header.addEventListener('mousedown', function (e) {
      if (!bar.classList.contains('rtoc-props-floating')) { return; }
      e.preventDefault();
      var rect = props.getBoundingClientRect();
      var offX = e.clientX - rect.left, offY = e.clientY - rect.top;
      function move(ev) {
        var left = ev.clientX - offX, top = ev.clientY - offY;
        left = Math.min(Math.max(0, left), window.innerWidth - 80);
        top = Math.min(Math.max(0, top), window.innerHeight - 60);
        props.style.left = left + 'px';
        props.style.top = top + 'px';
        props.style.right = 'auto';
        props.style.bottom = 'auto';
      }
      function up() {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
        store(POSKEY, JSON.stringify({ left: parseFloat(props.style.left) || 0, top: parseFloat(props.style.top) || 0 }));
      }
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
    });
  }

  // v6.2.88: the properties are a DOCKED column by DEFAULT that the author can pop out into a
  // floating draggable panel with the "Float properties" button (and dock it back). Only a stored
  // '1' (the author chose to float) starts floated; default and stored '0' stay docked.
  if (load(KEY) === '1') { setFloating(true); }
})();
FLOATPROPS
);

// v6.2.90 SINGLE PAGE: the hidden live-preview form was removed along with the inline preview
// iframe — the editing canvas is the single WYSIWYG page now. The live-preview JS guards on this
// form's absence (scheduleLivePreview/refreshLivePreview return early when the form is missing),
// so nothing renders in the background and no stray window opens. The "Preview" button in the top
// action bar still opens the true issued PDF in a new tab.

// ROR-CAPACITY-HINT (v5.9.340) — fetch the top qualifying qualifications
// by unit count so the JS overflow hint can tell designers "Qual XYZ has
// M units" when they configure a ror_table-style field.  Non-fatal: if the
// qualbuilder tables don't exist yet (fresh install) the hint just omits
// the qual-specific line.
$rorqualdata = [];
try {
    $rorrows = $DB->get_records_sql(
        "SELECT qb.id, qb.qualificationcode AS code, qb.qualificationname AS name,
                COUNT(qu.id) AS unit_count
           FROM {local_rtocompliance_qualbuilder} qb
           JOIN {local_rtocompliance_qualunits} qu ON qu.qualbuilderid = qb.id
          WHERE qb.status = 'active'
            AND qu.selected = 1
          GROUP BY qb.id, qb.qualificationcode, qb.qualificationname
          ORDER BY unit_count DESC
          LIMIT 10",
        []
    );
    foreach ($rorrows as $row) {
        $rorqualdata[] = [
            'code'       => (string) $row->code,
            'name'       => (string) $row->name,
            'unit_count' => (int)    $row->unit_count,
        ];
    }
} catch (\Throwable $e) {
    // Non-fatal — hint will still show capacity estimate without qual name.
    debugging('ROR capacity hint query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

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
// FIX-EDITOR-BRANDING-CHAIN (v5.9.406): NRT + AQF logos were never passed to
// the editor JS, so nrt_logo/aqf_logo dynamic fields always showed the
// "[NRT logo]" text placeholder even when the RTO had uploaded the artwork
// via Certificate Settings. All four now resolve through the same fallback
// chain the TCPDF renderer uses (settings filearea → bundled pix).
$brandingnrturl     = cert_template::get_branding_nrt_logo_url() ?: '';
$brandingaqfurl     = cert_template::get_branding_aqf_logo_url() ?: '';

// v4.2.48 BUG-MAY2-AUDIT — the canvas was hardcoded to 297x210 (landscape),
// which broke portrait templates (statement of attainment, record of
// results) by stretching their fields into the wrong aspect ratio in the
// editor. Use the saved design's actual page dimensions, falling back to
// the orientation-aware A4 default.
$pageW = (float) ($design['page']['width_mm']  ?? (($design['page']['orientation'] ?? 'L') === 'P' ? 210 : 297));
$pageH = (float) ($design['page']['height_mm'] ?? (($design['page']['orientation'] ?? 'L') === 'P' ? 297 : 210));

// CERT-FIELD-IMAGE-UPLOAD (v5.9.366) — display-only hydration: give every saved
// per-field image field a pluginfile URL in imageurl so the canvas shows it after
// reload. This URL is display-only; the POST handler strips it again on save (the
// stored file resolved by imageitemid is the source of truth for rendering).
if (!empty($design['fields']) && is_array($design['fields'])) {
    $imgfs = get_file_storage();
    foreach ($design['fields'] as &$_imgfld) {
        if (($_imgfld['kind'] ?? '') !== 'image' || empty($_imgfld['imageitemid'])) {
            continue;
        }
        $_imgfiles = $imgfs->get_area_files($context->id, 'local_rtocompliance',
            cert_template::FA_IMAGE, (int) $_imgfld['imageitemid'], 'sortorder, filename', false);
        foreach ($_imgfiles as $_imgf) {
            if ($_imgf->is_directory()) {
                continue;
            }
            $_imgfld['imageurl'] = moodle_url::make_pluginfile_url(
                $_imgf->get_contextid(), $_imgf->get_component(), $_imgf->get_filearea(),
                $_imgf->get_itemid(), $_imgf->get_filepath(), $_imgf->get_filename()
            )->out(false);
            break;
        }
    }
    unset($_imgfld);
}

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
    'brandingnrturl'     => $brandingnrturl     ?: '',
    'brandingaqfurl'     => $brandingaqfurl     ?: '',
    'brandingstaurl'     => $brandingstaurl     ?: '',
    // EDITOR-REAL-RTO-IDENTITY (v5.9.409): the RTO's OWN identity is fixed, not
    // per-student sample data, so the canvas should always show the real
    // configured values (RTO name/code, authorised signatory, AQF statement)
    // regardless of the "Show sample data" toggle — otherwise the editor looked
    // like it "wasn't pulling RTO settings" even though issued certs were correct.
    'rtoidentity'    => (function () use ($template) {
        // EDITOR-REAL-RTO-IDENTITY (v5.9.412): expose EVERY fixed, RTO-configured
        // value so the editor canvas reflects the actual settings (not generic
        // placeholders) regardless of the "Show sample data" toggle. Per-student /
        // per-cert data (student name, units, dates) is deliberately NOT included
        // here — that stays as sample data. Empty values fall through to the
        // catalogue placeholder (handled in the JS), so an unconfigured field is
        // never shown blank.
        $cfg = function ($k, $default = '') {
            $v = get_config('local_rtocompliance', $k);
            return ($v !== false && $v !== null && $v !== '') ? (string) $v : $default;
        };
        // v5.9.442: fall back to the real Moodle site name (the RTO's own name) when the
        // RTO legal name hasn't been entered in RTO Settings yet, so the canvas never
        // shows the generic "National Compliance Training" catalogue placeholder.
        global $SITE;
        $rtositefallback = format_string($SITE->fullname ?? '');
        // Certificate code preview using the RTO's real prefix + type code + year,
        // matching local_rtocompliance_generate_cert_number() (e.g. ABC-SOA-2026-0001).
        $certbase  = $cfg('certprefix', 'RTO');
        $typecodes = ['testamur' => 'CER', 'statement' => 'SOA', 'record' => 'ROR', 'completion' => 'COC'];
        $typecode  = $typecodes[$template->certtype] ?? strtoupper(substr($template->certtype, 0, 3));
        $certnumberpreview = $certbase . '-' . $typecode . '-' . date('Y') . '-0001';

        return [
            // RTO identity.
            'rto.name'        => $cfg('rtoname', $rtositefallback),
            'rto.code'        => $cfg('rtocode'),
            'signatory.name'  => $cfg('signatoryname'),
            'signatory.title' => $cfg('signatorytitle'),
            // Certificate code (real prefix format).
            'cert.number'     => $certnumberpreview,
            'cert.footer'     => $cfg('certfooter'),
            // Mandatory phrases (RTO-configurable; fall back to the ASQA default wording).
            'aqf_statement'                   => $cfg('aqfstatement',
                'This qualification is recognised within the Australian Qualifications Framework.'),
            'certify_statement'               => $cfg('certify_statement', 'This is to certify that'),
            'attained_statement'              => $cfg('attained_statement', 'has fulfilled the requirements for'),
            'soa_intro_statement'             => $cfg('soa_intro_statement', 'This is a statement that'),
            'soa_attained_statement'          => $cfg('soa_attained_statement', 'has attained'),
            'statement_of_attainment_heading' => $cfg('statement_of_attainment_heading', 'Statement of Attainment'),
            'record_of_results_heading'       => $cfg('record_of_results_heading', 'Record of Results'),
            'not_a_testamur_statement'        => $cfg('not_a_testamur_statement',
                'A STATEMENT OF ATTAINMENT IS ISSUED BY A REGISTERED TRAINING ORGANISATION WHEN AN INDIVIDUAL HAS COMPLETED ONE OR MORE ACCREDITED UNITS. THIS IS NOT A TESTAMUR.'),
            // Optional descriptors (blank unless configured — JS shows placeholder when blank).
            'industry_descriptor'             => $cfg('industrydescriptor'),
            'occupational_stream'             => $cfg('occupationalstream'),
            'australian_apprenticeship'       => $cfg('apprenticeshipstatement'),
            'language_statement'              => $cfg('languagestatement'),
            'skill_set_statement'             => $cfg('skillsetstatement'),
        ];
    })(),
    // ROR-CAPACITY-HINT (v5.9.340) — qualifications sorted by unit count descending.
    'rorqualdata'    => $rorqualdata,
    // STYLE-A-TABLE (v5.9.447) — units table header colour so the canvas mock
    // matches the issued PDF's shaded header bar. CERT-HEADER-THEME-COLOUR (v6.2.8):
    // resolved via the shared helper so the editor canvas, the live preview and the
    // issued PDF all use the SAME colour — by default the site theme's primary colour.
    'headercolour'   => local_rtocompliance_cert_header_colour(),
    // FONTS (v6.2.63): font-key -> CSS family for the canvas, plus the Google font family list so
    // the editor can load them as webfonts and preview the real typeface.
    'fontcss'        => (function () {
        $m = ['helvetica' => '"Helvetica Neue", Helvetica, Arial, sans-serif', 'times' => 'Times, serif', 'courier' => '"Courier New", monospace'];
        foreach (\local_rtocompliance\cert_template::font_catalogue() as $k => $v) { $m[$k] = $v['css']; }
        return $m;
    })(),
    'googlefonts'    => array_values(array_map(function ($v) { return $v['google']; }, \local_rtocompliance\cert_template::font_catalogue())),
    // CERT-AUTODESIGN removed (v6.2.51) — the auto-design-from-image button was withdrawn.
    // LIVE-VALIDATION (v6.2.16) — the editor re-POSTs the current (unsaved) design
    // here after every field add/delete/change and swaps the ASQA validator panel
    // with the freshly rendered result, so recommendations clear live.
    'validateurl'    => (new moodle_url('/local/rtocompliance/cert_template_validate.php'))->out(false),
    'sesskey'        => sesskey(),
    'orientation'    => ($design['page']['orientation'] ?? 'L') === 'P' ? 'P' : 'L',
];

// CERT-EDITOR-XSS (v5.9.406): design/catalogue JSON may contain author-entered
// text (field labels, custom text fields). JSON_HEX_* flags prevent a crafted
// value from terminating this inline <script> and injecting markup.
echo html_writer::tag('script', 'window.RTOC_TMPL_DATA = ' . json_encode($jsdata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';');
$PAGE->requires->js_call_amd('local_rtocompliance/cert_template_editor', 'init');

echo $OUTPUT->footer();
