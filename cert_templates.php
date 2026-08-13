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
 * RTO Compliance plugin — cert_templates.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// CERT-TEMPLATE-BUILDER (v4.2.40) — list page.
//
// Per-cert-type table view of every template (draft, approved, archived).
// Each row exposes the appropriate status-transition buttons.  Activating
// a template immediately swaps it in for new certificate issuance — see
// classes/cert_template.php::activate() and lib.php render dispatch.
//
// v5.9.327 CERT-PAGE-OVERHAUL:
//   - Orientation picker on create form (Portrait / Landscape, smart JS default).
//   - Quick-setup banner: when any cert type has no active template, a prominent
//     one-click "Seed starter templates" button creates and activates the ASQA
//     default templates for those types only — does not touch existing ones.
//   - Live preview panel: tabbed PDF previews rendered via cert_test.php using
//     current RTO Settings and sample data, loaded on demand per tab click.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');

use local_rtocompliance\cert_template;

admin_externalpage_setup('local_rtocompliance_cert_templates');
require_login();
require_capability('local/rtocompliance:managecerttemplates', context_system::instance());

$action       = optional_param('action',      '', PARAM_ALPHA);
$certtype_new = optional_param('certtype',    '', PARAM_ALPHA);
$name_new     = trim(optional_param('name',   '', PARAM_TEXT));
// v4.3.0 CERT-TEMPLATE-AUDIENCES — optional audience pin at create time.
$audience_new      = optional_param('audience',      'default', PARAM_ALPHANUMEXT);
$audiencelabel_new = trim(optional_param('audiencelabel', '',   PARAM_TEXT));
// v5.9.327 — orientation at create time.
$orientation_new   = optional_param('orientation', '', PARAM_ALPHA);
if ($orientation_new !== 'P' && $orientation_new !== 'L') {
    $orientation_new = '';
}

// ── "Seed" action — one-click starter template seeding ─────────────────────
// Creates and activates the ASQA default template(s) for any cert type that
// currently has NO active approved template.  Existing templates are untouched.
if ($action === 'seed' && data_submitted() && confirm_sesskey()) {
    $seeded = 0;
    foreach (['testamur', 'statement', 'record', 'completion'] as $ct) {
        // Skip if an active approved template already exists for this type.
        if (cert_template::get_active_template($ct)) {
            continue;
        }
        $defaultorientation = cert_template::default_orientation($ct);
        $now = time();
        foreach (['L', 'P'] as $orientation) {
            $design = cert_template::build_starter_design($ct, $orientation);
            $isactive = ($orientation === $defaultorientation) ? 1 : 0;
            $orientlabel = ($orientation === 'L') ? 'Landscape' : 'Portrait';
            $ctlabel = [
                'testamur'   => 'Testamur',
                'statement'  => 'Statement of Attainment',
                'record'     => 'Record of Results',
                'completion' => 'Certificate of Completion',
            ][$ct] ?? ucfirst($ct);
            $rec = (object) [
                'name'         => 'Default ' . $ctlabel . ' (' . $orientlabel . ')',
                'certtype'     => $ct,
                'audience'     => 'default',
                'audiencelabel' => null,
                'status'       => 'approved',
                'isactive'     => $isactive,
                'designjson'   => json_encode($design, JSON_UNESCAPED_SLASHES),
                'createdby'    => 0,
                'approvedby'   => 0,
                'timeapproved' => $now,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_rtocompliance_certtmpl', $rec);
            if ($isactive) {
                $seeded++;
            }
        }
    }
    $msg = $seeded > 0
        ? get_string('cert_template_seed_ok', 'local_rtocompliance', $seeded)
        : get_string('cert_template_seed_none', 'local_rtocompliance');
    redirect(new moodle_url('/local/rtocompliance/cert_templates.php'),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── "Create" form submit (POST + sesskey) ──────────────────────────────────
if ($action === 'create' && data_submitted() && confirm_sesskey()) {
    if (!in_array($certtype_new, cert_template::CERT_TYPES, true) || $name_new === '') {
        redirect(new moodle_url('/local/rtocompliance/cert_templates.php'),
            get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_ERROR);
    }
    $newid = cert_template::create(
        $certtype_new,
        $name_new,
        $audience_new,
        $audiencelabel_new !== '' ? $audiencelabel_new : null,
        $orientation_new !== '' ? $orientation_new : null
    );
    redirect(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $newid]),
        get_string('cert_template_action_ok_saved', 'local_rtocompliance'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Active-template status (for seed banner + preview tabs) ───────────────
$active_by_type = [];
foreach (cert_template::CERT_TYPES as $ct) {
    $active_by_type[$ct] = cert_template::get_active_template($ct);
}
$any_missing_active = in_array(null, $active_by_type, true);

$PAGE->set_title(get_string('cert_templates', 'local_rtocompliance'));
$PAGE->set_heading(get_string('cert_templates', 'local_rtocompliance'));
$PAGE->set_url(new moodle_url('/local/rtocompliance/cert_templates.php'));
$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('cert_templates', 'local_rtocompliance'), null, null, 'certificates');
echo local_rtocompliance_page_banner(get_string('cert_templates', 'local_rtocompliance'));
echo html_writer::div(get_string('cert_templates_desc', 'local_rtocompliance'), 'rtoc-tmpl-intro alert alert-info');

// ── BRANDING STATUS NOTICE ─────────────────────────────────────────────────
// Uses the shared helper so this check stays consistent with cert_template_edit.php.
// Shows a single compact warning when any core branding asset is missing so the
// admin knows to upload them before activating a template.
$_branding_status = local_rtocompliance_get_branding_status();
$_branding_missing = array_filter([
    !$_branding_status['logo']         ? 'RTO logo'                    : null,
    !$_branding_status['signature']    ? 'CEO / signatory signature'   : null,
    !$_branding_status['seal']         ? 'Organisation seal'           : null,
    !$_branding_status['nrt_override'] ? 'NRT logo (AQF certs)'       : null,
    !$_branding_status['signatory']    ? 'Authorised signatory name'   : null,
]);
if (!empty($_branding_missing)) {
    $_settings_url = (new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']))->out(false);
    echo html_writer::start_div('alert alert-warning rtoc-branding-status-notice mb-3');
    echo html_writer::tag('strong', '⚠ Some branding assets are not yet configured');
    echo html_writer::tag('p',
        'Certificates will render with placeholder boxes until these are set up. ' .
        html_writer::link($_settings_url, 'Open RTO Settings →', ['class' => 'alert-link']),
        ['class' => 'mb-1 mt-1 small']);
    echo html_writer::tag('ul',
        implode('', array_map(fn($item) => html_writer::tag('li', s($item)), $_branding_missing)),
        ['class' => 'mb-0 small']);
    echo html_writer::end_div();
}
unset($_branding_status, $_branding_missing, $_settings_url);

// ── QUICK-SETUP BANNER ─────────────────────────────────────────────────────
// Show when any cert type has no active approved template. Certificates of
// those types will fall back to the legacy TCPDF layout (still ASQA-compliant)
// but won't use custom branding or the drag-and-drop template builder.
if ($any_missing_active) {
    $missing_types = [];
    $type_labels = [
        'testamur'   => get_string('cert_template_certtype_testamur',   'local_rtocompliance'),
        'statement'  => get_string('cert_template_certtype_statement',   'local_rtocompliance'),
        'record'     => get_string('cert_template_certtype_record',      'local_rtocompliance'),
        'completion' => get_string('cert_template_certtype_completion',  'local_rtocompliance'),
    ];
    foreach (cert_template::CERT_TYPES as $ct) {
        if (!$active_by_type[$ct]) {
            $missing_types[] = html_writer::tag('strong', $type_labels[$ct]);
        }
    }
    echo html_writer::start_div('rtoc-tmpl-seed-banner card border-warning mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3',
        get_string('cert_template_seed_heading', 'local_rtocompliance'),
        ['class' => 'h5 text-warning mb-2']);
    echo html_writer::tag('p',
        get_string('cert_template_seed_desc', 'local_rtocompliance') . ' ' .
        implode(', ', $missing_types) . '.',
        ['class' => 'mb-3']);

    $seedform_url = new moodle_url('/local/rtocompliance/cert_templates.php');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $seedform_url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'seed']);
    echo html_writer::tag('button',
        get_string('cert_template_seed_btn', 'local_rtocompliance'),
        ['type' => 'submit', 'class' => 'btn btn-warning',
         'title' => 'Create and activate ASQA default templates for the missing certificate types',
         'onclick' => 'return confirm(' . json_encode(
             get_string('cert_template_seed_confirm', 'local_rtocompliance')
         ) . ')']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// ── LIVE PREVIEW PANEL ─────────────────────────────────────────────────────
// Tabbed panel showing a sample PDF for each cert type rendered with current
// RTO Settings. PDFs are loaded on-demand when the admin clicks a tab — not
// auto-loaded on page render (avoids 4× PDF renders on every page load).
$sk = sesskey();
$cert_type_labels = [
    'testamur'   => '📜 ' . get_string('cert_template_certtype_testamur',   'local_rtocompliance'),
    'statement'  => '📄 ' . get_string('cert_template_certtype_statement',   'local_rtocompliance'),
    'record'     => '📋 ' . get_string('cert_template_certtype_record',      'local_rtocompliance'),
    'completion' => '🎓 ' . get_string('cert_template_certtype_completion',  'local_rtocompliance'),
];
// v5.9.365 PREVIEW-ORIENTATION-FIX: '' (auto) so the preview renders the ACTIVE
// template in ITS OWN orientation instead of forcing a rotate+rescale of every field.
// CERT-ORIENTATION-FILTER (v6.2.6): when the RTO has restricted itself to a SINGLE
// orientation in Certificate Settings, force the preview into that orientation so the
// first thing shown on this page matches what they issue. Both allowed = '' (auto).
$allowed_orients = local_rtocompliance_cert_allowed_orientations();
$preview_orient  = (count($allowed_orients) === 1) ? $allowed_orients[0] : '';
$cert_type_orientations = [
    'testamur'   => $preview_orient,
    'statement'  => $preview_orient,
    'record'     => $preview_orient,
    'completion' => $preview_orient,
];

echo html_writer::start_div('rtoc-cert-preview-panel card mb-4');
echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');
echo html_writer::tag('h3',
    get_string('cert_template_preview_panel_heading', 'local_rtocompliance'),
    ['class' => 'h5 mb-0']);
echo html_writer::tag('small',
    get_string('cert_template_preview_panel_intro', 'local_rtocompliance'),
    ['class' => 'text-muted']);
echo html_writer::end_div(); // card-header

echo html_writer::start_div('card-body p-0');

// Nav tabs.
echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs px-3 pt-3', 'id' => 'rtocPreviewTabs', 'role' => 'tablist']);
$first = true;
foreach (cert_template::CERT_TYPES as $ct) {
    $active = $active_by_type[$ct];
    $statusbadge = $active
        ? html_writer::span('✓ Active', 'badge bg-success ms-1 small')
        : html_writer::span('No active template', 'badge bg-warning text-dark ms-1 small');
    echo html_writer::start_tag('li', ['class' => 'nav-item', 'role' => 'presentation']);
    echo html_writer::start_tag('button', [
        'class'          => 'nav-link' . ($first ? ' active' : ''),
        'id'             => 'rtoc-preview-tab-' . $ct,
        'data-certtype'  => $ct,
        'data-sesskey'   => $sk,
        'data-orient'    => $cert_type_orientations[$ct],
        'type'           => 'button',
        'role'           => 'tab',
    ]);
    echo s($cert_type_labels[$ct]) . ' ' . $statusbadge;
    echo html_writer::end_tag('button');
    echo html_writer::end_tag('li');
    $first = false;
}
echo html_writer::end_tag('ul');

// iframe container.
echo html_writer::start_div('rtoc-preview-iframe-wrap', ['style' => 'position:relative;']);
echo html_writer::tag('div',
    html_writer::tag('p', '⏳ Click a certificate type tab above to load the preview.', ['class' => 'text-muted m-0']),
    ['id' => 'rtocPreviewPlaceholder', 'class' => 'p-4 text-center bg-light', 'style' => 'min-height:120px;display:flex;align-items:center;justify-content:center;']);
echo html_writer::tag('iframe', '', [
    'id'             => 'rtocPreviewIframe',
    'src'            => '',
    'style'          => 'width:100%;height:640px;border:0;display:none;',
    'title'          => 'Certificate preview',
    'allowfullscreen' => 'true',
]);

// "Open full size" link shown when a preview is loaded.
echo html_writer::tag('div', '', [
    'id'    => 'rtocPreviewActions',
    'class' => 'p-2 bg-light border-top text-end',
    'style' => 'display:none;',
]);

echo html_writer::end_div(); // rtoc-preview-iframe-wrap
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // rtoc-cert-preview-panel

// Preview JS — on tab click: set iframe src to cert_test.php with sesskey.
echo html_writer::script('
(function () {
    var previewBase = ' . json_encode(
        (new moodle_url('/local/rtocompliance/cert_test.php'))->out(false)
    ) . ';
    var tabs = document.querySelectorAll("[data-certtype]");
    var iframe = document.getElementById("rtocPreviewIframe");
    var placeholder = document.getElementById("rtocPreviewPlaceholder");
    var actions = document.getElementById("rtocPreviewActions");
    var currentType = null;

    function loadPreview(certtype, sesskey, orient) {
        if (currentType === certtype) return;
        currentType = certtype;
        var url = previewBase + "?generate=1&certtype=" + encodeURIComponent(certtype)
                  + "&sesskey=" + encodeURIComponent(sesskey)
                  + "&orientation=" + encodeURIComponent(orient)
                  + "&studentname=" + encodeURIComponent("Alex Sample");
        placeholder.style.display = "flex";
        placeholder.querySelector("p").textContent = "⏳ Rendering " + certtype + " preview…";
        iframe.style.display = "none";
        actions.style.display = "none";
        iframe.onload = function () {
            placeholder.style.display = "none";
            iframe.style.display = "block";
            actions.style.display = "block";
            actions.innerHTML = \'<a href="\' + url + \'" target="_blank" class="btn btn-sm btn-outline-primary">Open full size ↗</a>\';
        };
        // CLEAN-PDF-PREVIEW (v6.2.57): hide the browser PDF viewer chrome — the thumbnail side
        // panel ("that left side"), the toolbar and the page-mode panel — and fit to width, so
        // the preview shows the full certificate cleanly instead of a two-pane document viewer.
        iframe.src = url + "#toolbar=0&navpanes=0&scrollbar=0&statusbar=0&pagemode=none&view=FitH";
    }

    tabs.forEach(function (tab) {
        tab.addEventListener("click", function () {
            tabs.forEach(function (t) { t.classList.remove("active"); });
            tab.classList.add("active");
            loadPreview(tab.dataset.certtype, tab.dataset.sesskey, tab.dataset.orient);
        });
    });

    // Auto-load the first tab (Testamur).
    if (tabs.length > 0) {
        var first = tabs[0];
        loadPreview(first.dataset.certtype, first.dataset.sesskey, first.dataset.orient);
    }
})();
');

// ── CREATE FORM ────────────────────────────────────────────────────────────
echo html_writer::start_div('rtoc-tmpl-create card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3',
    get_string('cert_template_create_heading', 'local_rtocompliance'),
    ['class' => 'h5 mb-2']);
echo html_writer::tag('p',
    get_string('cert_template_create_intro', 'local_rtocompliance'),
    ['class' => 'text-muted small mb-3']);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/rtocompliance/cert_templates.php'),
    'class'  => 'd-flex flex-wrap align-items-end gap-2',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'create']);

// Cert type.
echo html_writer::start_tag('div', ['class' => 'form-group mb-2']);
echo html_writer::tag('label',
    get_string('cert_template_certtype', 'local_rtocompliance'),
    ['for' => 'certtype', 'class' => 'd-block small fw-bold mb-1']);
echo html_writer::start_tag('select', [
    'id' => 'rtocCreateCerttype', 'name' => 'certtype',
    'class' => 'form-control', 'required' => 'required']);
foreach (cert_template::CERT_TYPES as $ct) {
    echo html_writer::tag('option',
        get_string('cert_template_certtype_' . $ct, 'local_rtocompliance'),
        ['value' => $ct]);
}
echo html_writer::end_tag('select');
echo html_writer::end_tag('div');

// v5.9.327 — Orientation at create time.
echo html_writer::start_tag('div', ['class' => 'form-group mb-2']);
echo html_writer::tag('label',
    get_string('cert_template_create_orientation', 'local_rtocompliance'),
    ['for' => 'rtocCreateOrientation', 'class' => 'd-block small fw-bold mb-1']);
echo html_writer::start_tag('select', [
    'id' => 'rtocCreateOrientation', 'name' => 'orientation', 'class' => 'form-control']);
echo html_writer::tag('option',
    get_string('cert_template_create_orientation_default', 'local_rtocompliance'),
    ['value' => '']);
echo html_writer::tag('option',
    get_string('cert_template_page_orientation_l', 'local_rtocompliance'),
    ['value' => 'L']);
echo html_writer::tag('option',
    get_string('cert_template_page_orientation_p', 'local_rtocompliance'),
    ['value' => 'P']);
echo html_writer::end_tag('select');
echo html_writer::end_tag('div');

// v4.3.0 CERT-TEMPLATE-AUDIENCES — audience picker on the create form.
echo html_writer::start_tag('div', ['class' => 'form-group mb-2']);
echo html_writer::tag('label',
    get_string('cert_template_audience', 'local_rtocompliance'),
    ['for' => 'audience', 'class' => 'd-block small fw-bold mb-1']);
echo html_writer::start_tag('select', [
    'id' => 'audience', 'name' => 'audience', 'class' => 'form-control']);
foreach (cert_template::AUDIENCES as $aud) {
    echo html_writer::tag('option',
        get_string('cert_template_audience_' . $aud, 'local_rtocompliance'),
        ['value' => $aud] + ($aud === 'default' ? ['selected' => 'selected'] : []));
}
echo html_writer::end_tag('select');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group mb-2']);
echo html_writer::tag('label',
    get_string('cert_template_audiencelabel', 'local_rtocompliance'),
    ['for' => 'audiencelabel', 'class' => 'd-block small fw-bold mb-1']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'id' => 'audiencelabel', 'name' => 'audiencelabel',
    'class' => 'form-control', 'maxlength' => 255,
    'placeholder' => get_string('cert_template_audiencelabel_placeholder', 'local_rtocompliance'),
    'style' => 'min-width: 200px;',
]);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'form-group mb-2']);
echo html_writer::tag('label',
    get_string('cert_template_name', 'local_rtocompliance'),
    ['for' => 'name', 'class' => 'd-block small fw-bold mb-1']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'id' => 'name', 'name' => 'name',
    'class' => 'form-control', 'placeholder' => 'e.g. Testamur 2026',
    'required' => 'required', 'maxlength' => 255, 'style' => 'min-width: 260px;',
]);
echo html_writer::end_tag('div');

echo html_writer::tag('div',
    html_writer::tag('button',
        get_string('cert_template_new', 'local_rtocompliance'),
        ['type' => 'submit', 'class' => 'btn btn-primary mb-2',
         'title' => 'Create the template and open it in the designer']),
    ['class' => 'form-group mb-2 align-self-end']);

echo html_writer::end_tag('form');
echo html_writer::end_div(); // card-body
echo html_writer::end_div(); // card

// JS: auto-update orientation select to the ASQA default when cert type changes.
echo html_writer::script('
(function () {
    var typeDefaults = {testamur:"L", statement:"P", record:"P", completion:"L"};
    var typeSelect   = document.getElementById("rtocCreateCerttype");
    var orientSelect = document.getElementById("rtocCreateOrientation");
    if (!typeSelect || !orientSelect) return;
    typeSelect.addEventListener("change", function () {
        var def = typeDefaults[typeSelect.value] || "L";
        // Only override if the user has not already picked a specific orientation.
        // We treat "" (default) as "please sync with cert type".
        if (orientSelect.value === "" || orientSelect.value === typeDefaults[typeSelect.value]) {
            // Leave the "— type default —" option selected to keep the UX clean.
        }
        // Update the placeholder text of the first option to show the actual default.
        var placeholder = orientSelect.querySelector("option[value=\'\']");
        if (placeholder) {
            placeholder.textContent = (def === "L")
                ? "' . s(get_string('cert_template_create_orientation_default_l', 'local_rtocompliance')) . '"
                : "' . s(get_string('cert_template_create_orientation_default_p', 'local_rtocompliance')) . '";
        }
    });
    // Fire on load so the placeholder reflects the initial cert type.
    typeSelect.dispatchEvent(new Event("change"));
})();
');

// ── TEST CERT QUICK LINK ────────────────────────────────────────────────────
echo html_writer::start_div('mb-3');
$testurl = new moodle_url('/local/rtocompliance/cert_test.php');
echo html_writer::link($testurl,
    get_string('cert_test_link', 'local_rtocompliance'),
    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank',
     'title' => 'Open a sample certificate rendered with your current settings']);
echo html_writer::end_div();

// ── TEMPLATE TABLE ─────────────────────────────────────────────────────────
$showarchived = optional_param('showarchived', 0, PARAM_INT);
$templates = cert_template::list_all();

$archivedcount = count(array_filter($templates, fn($t) => $t->status === 'archived'));
if (!$showarchived) {
    $templates = array_values(array_filter($templates, fn($t) => $t->status !== 'archived'));
}

// CERT-ORIENTATION-FILTER (v6.2.6): hide templates whose page orientation the RTO has
// switched off in Certificate Settings, decluttering the list for single-orientation RTOs.
// A template's orientation lives in designjson.page.orientation ('P'|'L'); default 'L' if
// unreadable. Both orientations allowed = no filtering. We count what was hidden so we can
// tell the admin (rather than silently dropping templates they may be looking for).
$orient_hidden_count = 0;
if (count($allowed_orients) < 2) {
    $before = count($templates);
    $templates = array_values(array_filter($templates, function ($t) use ($allowed_orients) {
        $d = json_decode($t->designjson ?? '', true);
        $o = (is_array($d) && !empty($d['page']['orientation'])) ? $d['page']['orientation'] : 'L';
        return in_array($o, $allowed_orients, true);
    }));
    $orient_hidden_count = $before - count($templates);
}
if ($orient_hidden_count > 0) {
    $only = ($allowed_orients[0] === 'P')
        ? get_string('cert_template_page_orientation_p', 'local_rtocompliance')
        : get_string('cert_template_page_orientation_l', 'local_rtocompliance');
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']);
    echo html_writer::div(
        get_string('cert_orientation_filter_note', 'local_rtocompliance',
            (object) ['count' => $orient_hidden_count, 'orientation' => $only])
        . ' ' . html_writer::link($settingsurl,
            get_string('cert_orientation_filter_change', 'local_rtocompliance')),
        'alert alert-info py-2 small mb-3');
}

$pageurl = new moodle_url('/local/rtocompliance/cert_templates.php');
if ($archivedcount > 0) {
    if ($showarchived) {
        $toggleurl  = new moodle_url($pageurl, ['showarchived' => 0]);
        $toggletext = 'Hide archived (' . $archivedcount . ')';
    } else {
        $toggleurl  = new moodle_url($pageurl, ['showarchived' => 1]);
        $toggletext = 'Show archived (' . $archivedcount . ')';
    }
    echo html_writer::link($toggleurl, $toggletext,
        ['class' => 'btn btn-sm btn-outline-secondary mb-3',
         'title' => 'Show or hide archived templates']);
}

if (empty($templates)) {
    echo html_writer::div(
        get_string('cert_template_none_yet', 'local_rtocompliance'),
        'alert alert-secondary');
} else {
    $table = new html_table();
    // v-tooltips: header cells carry plain-English title tooltips.
    $mkhead = function (string $text, string $title) {
        $cell = new html_table_cell($text);
        $cell->header = true;
        $cell->attributes['title'] = $title;
        return $cell;
    };
    $table->head = [
        $mkhead(get_string('cert_template_certtype',  'local_rtocompliance'), 'Which type of certificate this template produces'),
        $mkhead(get_string('cert_template_audience',  'local_rtocompliance'), 'Which learner group this template applies to'),
        $mkhead(get_string('cert_template_name',      'local_rtocompliance'), 'Template name for your own reference'),
        $mkhead(get_string('cert_template_status',    'local_rtocompliance'), 'Draft, approved or archived, plus active and ASQA-check badges'),
        $mkhead(get_string('cert_template_modified',  'local_rtocompliance'), 'When this template was last changed'),
        $mkhead(get_string('cert_template_actions',   'local_rtocompliance'), 'Edit, preview, activate, archive, duplicate or delete this template'),
    ];
    $table->attributes['class'] = 'generaltable rtoc-tmpl-table';

    foreach ($templates as $t) {
        $row = new html_table_row();
        $row->cells[] = get_string('cert_template_certtype_' . $t->certtype, 'local_rtocompliance');

        $audcode = !empty($t->audience) ? $t->audience : 'default';
        $audtext = !empty($t->audiencelabel) ? $t->audiencelabel
            : get_string('cert_template_audience_' . $audcode, 'local_rtocompliance');
        $audcls = ($audcode === 'default') ? 'badge bg-secondary text-white'
                                           : 'badge bg-info text-white';
        $row->cells[] = html_writer::span(format_string($audtext), $audcls);
        $row->cells[] = format_string($t->name);

        $statusbadge = '';
        $cls = match ($t->status) {
            'approved' => 'badge bg-success text-white',
            'archived' => 'badge bg-secondary text-white',
            default    => 'badge bg-warning text-dark',
        };
        $statusbadge = html_writer::span(
            get_string('cert_template_status_' . $t->status, 'local_rtocompliance'), $cls);
        if ($t->isactive) {
            $statusbadge .= ' ' . html_writer::span(
                get_string('cert_template_active_badge', 'local_rtocompliance'),
                'badge bg-primary text-white ml-1');
        }
        if (!empty($t->lastvalidation)) {
            $val  = json_decode($t->lastvalidation, true);
            $errs = is_array($val) ? count($val['errors']   ?? []) : 0;
            $wrns = is_array($val) ? count($val['warnings'] ?? []) : 0;
            if ($errs > 0) {
                $statusbadge .= ' ' . html_writer::span(
                    $errs . ' ASQA errors', 'badge bg-danger text-white ml-1');
            } else if ($wrns > 0) {
                $statusbadge .= ' ' . html_writer::span(
                    $wrns . ' warnings', 'badge bg-warning text-dark ml-1');
            } else {
                $statusbadge .= ' ' . html_writer::span(
                    'ASQA OK', 'badge bg-success text-white ml-1');
            }
        }
        $row->cells[] = $statusbadge;
        $row->cells[] = userdate($t->timemodified,
            get_string('strftimedatetimeshort', 'core_langconfig'));

        // Actions.
        $sk2 = sesskey();
        $mkaction = function (string $act, string $label, string $btnclass,
                ?string $confirm = null, ?string $title = null) use ($t, $sk2) {
            $url = new moodle_url('/local/rtocompliance/cert_template_action.php', [
                'action' => $act, 'id' => $t->id, 'sesskey' => $sk2,
            ]);
            $attrs = ['class' => 'btn btn-sm ' . $btnclass . ' mr-1 mb-1'];
            if ($title !== null) {
                $attrs['title'] = $title;
            }
            if ($confirm !== null) {
                $attrs['onclick'] = 'return confirm(' . json_encode($confirm) . ');';
            }
            return html_writer::link($url, $label, $attrs);
        };

        $actions = [];
        $editurl = new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $t->id]);
        $actions[] = html_writer::link($editurl,
            get_string('cert_template_edit_btn', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-outline-primary mr-1 mb-1',
             'title' => 'Open this template in the drag-and-drop designer']);

        $previewurl = new moodle_url('/local/rtocompliance/cert_template_preview.php', ['id' => $t->id]);
        $actions[] = html_writer::link($previewurl,
            get_string('cert_template_preview_btn', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-outline-secondary mr-1 mb-1',
             'target' => '_blank', 'rel' => 'noopener',
             'title' => 'Open a sample PDF of this template in a new tab']);

        if ($t->status === 'draft') {
            $actions[] = $mkaction('submit',
                get_string('cert_template_submit_btn', 'local_rtocompliance'), 'btn-success',
                null, 'Submit this draft for approval');
        }
        if ($t->status === 'approved' && !$t->isactive) {
            $actions[] = $mkaction('activate',
                get_string('cert_template_activate_btn', 'local_rtocompliance'),
                'btn-primary',
                get_string('cert_template_confirm_activate', 'local_rtocompliance'),
                'Make this the template used for newly issued certificates');
        }
        // DEACTIVATE (v5.9.408): "Make non-active" button on the currently active
        // template so an admin can turn it off without archiving it.
        if (!empty($t->isactive)) {
            $actions[] = $mkaction('deactivate',
                get_string('cert_template_deactivate_btn', 'local_rtocompliance'),
                'btn-outline-warning',
                get_string('cert_template_confirm_deactivate', 'local_rtocompliance'),
                'Turn off this active template without archiving it');
        }
        if (in_array($t->status, ['draft', 'approved'], true)) {
            $actions[] = $mkaction('archive',
                get_string('cert_template_archive_btn', 'local_rtocompliance'),
                'btn-outline-secondary',
                get_string('cert_template_confirm_archive', 'local_rtocompliance'),
                'Move this template to the archive');
        }
        $actions[] = $mkaction('duplicate',
            get_string('cert_template_duplicate_btn', 'local_rtocompliance'),
            'btn-outline-secondary',
            null, 'Create an editable copy of this template');
        $actions[] = $mkaction('reset',
            get_string('cert_template_reset_btn', 'local_rtocompliance'),
            'btn-outline-warning',
            get_string('cert_template_confirm_reset', 'local_rtocompliance'),
            'Reset this template back to the ASQA starter layout');

        // FORCE-DELETE (v5.9.408): a Delete button on EVERY template row (any
        // status, including the active one) so an admin can clear a template out
        // and regenerate a fresh ASQA starter. Deleting the active template is
        // safe — issuance falls back to the built-in starter until a new template
        // is activated. Extra-strong confirm because it is permanent.
        $delconfirm = ($t->isactive)
            ? 'This is the ACTIVE template for this certificate type. Deleting it means certificates fall back to the built-in ASQA starter layout until you create and activate a new one. Permanently delete it? This cannot be undone.'
            : 'Permanently delete this template? This cannot be undone.';
        $actions[] = $mkaction('forcedelete',
            get_string('cert_template_delete_btn', 'local_rtocompliance'),
            'btn-outline-danger',
            $delconfirm,
            'Permanently delete this template');

        $row->cells[] = implode('', $actions);
        $table->data[] = $row;
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
