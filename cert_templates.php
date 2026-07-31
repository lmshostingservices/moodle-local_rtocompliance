<?php
// CERT-TEMPLATE-BUILDER (v4.2.40) — list page.
//
// Per-cert-type table view of every template (draft, approved, archived).
// Each row exposes the appropriate status-transition buttons.  Activating
// a template immediately swaps it in for new certificate issuance — see
// classes/cert_template.php::activate() and lib.php render dispatch.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');

use local_rtocompliance\cert_template;

admin_externalpage_setup('local_rtocompliance_cert_templates');
require_capability('local/rtocompliance:managecerttemplates', context_system::instance());

$action  = optional_param('action',  '', PARAM_ALPHA);
$certtype_new = optional_param('certtype', '', PARAM_ALPHA);
$name_new     = trim(optional_param('name',     '', PARAM_TEXT));
// v4.3.0 CERT-TEMPLATE-AUDIENCES — optional audience pin at create time.
// PARAM_ALPHANUMEXT covers values like "funded_state".
$audience_new      = optional_param('audience',      'default', PARAM_ALPHANUMEXT);
$audiencelabel_new = trim(optional_param('audiencelabel', '',   PARAM_TEXT));

// "Create" form submit (POST + sesskey).
if ($action === 'create' && data_submitted() && confirm_sesskey()) {
    if (!in_array($certtype_new, cert_template::CERT_TYPES, true) || $name_new === '') {
        redirect(new moodle_url('/local/rtocompliance/cert_templates.php'),
            get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_ERROR);
    }
    $newid = cert_template::create($certtype_new, $name_new,
        $audience_new, $audiencelabel_new !== '' ? $audiencelabel_new : null);
    redirect(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $newid]),
        get_string('cert_template_action_ok_saved', 'local_rtocompliance'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_title(get_string('cert_templates', 'local_rtocompliance'));
$PAGE->set_heading(get_string('cert_templates', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('cert_templates', 'local_rtocompliance'), null, null, 'certificates');
echo $OUTPUT->heading(get_string('cert_templates', 'local_rtocompliance'));
echo html_writer::div(get_string('cert_templates_desc', 'local_rtocompliance'), 'rtoc-tmpl-intro alert alert-info');

// "New template" creation form.
echo html_writer::start_div('rtoc-tmpl-create card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('cert_template_create_heading', 'local_rtocompliance'), ['class' => 'h5 mb-2']);
echo html_writer::tag('p', get_string('cert_template_create_intro', 'local_rtocompliance'), ['class' => 'text-muted small mb-3']);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/rtocompliance/cert_templates.php'),
    'class'  => 'form-inline',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'create']);
echo html_writer::start_tag('div', ['class' => 'form-group mr-2 mb-2']);
echo html_writer::tag('label', get_string('cert_template_certtype', 'local_rtocompliance'), ['for' => 'certtype', 'class' => 'mr-2']);
echo html_writer::start_tag('select', ['id' => 'certtype', 'name' => 'certtype', 'class' => 'form-control', 'required' => 'required']);
foreach (cert_template::CERT_TYPES as $ct) {
    echo html_writer::tag('option', get_string('cert_template_certtype_' . $ct, 'local_rtocompliance'), ['value' => $ct]);
}
echo html_writer::end_tag('select');
echo html_writer::end_tag('div');
// v4.3.0 CERT-TEMPLATE-AUDIENCES — audience picker on the create form.
echo html_writer::start_tag('div', ['class' => 'form-group mr-2 mb-2']);
echo html_writer::tag('label', get_string('cert_template_audience', 'local_rtocompliance'),
    ['for' => 'audience', 'class' => 'mr-2']);
echo html_writer::start_tag('select', [
    'id' => 'audience', 'name' => 'audience', 'class' => 'form-control',
]);
foreach (cert_template::AUDIENCES as $aud) {
    echo html_writer::tag('option',
        get_string('cert_template_audience_' . $aud, 'local_rtocompliance'),
        ['value' => $aud] + ($aud === 'default' ? ['selected' => 'selected'] : []));
}
echo html_writer::end_tag('select');
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', ['class' => 'form-group mr-2 mb-2']);
echo html_writer::tag('label', get_string('cert_template_audiencelabel', 'local_rtocompliance'),
    ['for' => 'audiencelabel', 'class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'id' => 'audiencelabel', 'name' => 'audiencelabel',
    'class' => 'form-control', 'maxlength' => 255,
    'placeholder' => get_string('cert_template_audiencelabel_placeholder', 'local_rtocompliance'),
    'style' => 'min-width: 240px;',
]);
echo html_writer::end_tag('div');
echo html_writer::start_tag('div', ['class' => 'form-group mr-2 mb-2']);
echo html_writer::tag('label', get_string('cert_template_name', 'local_rtocompliance'), ['for' => 'name', 'class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'name',
    'name' => 'name',
    'class' => 'form-control',
    'placeholder' => 'e.g. Testamur 2026',
    'required' => 'required',
    'maxlength' => 255,
    'style' => 'min-width: 320px;',
]);
echo html_writer::end_tag('div');
echo html_writer::tag('button', get_string('cert_template_new', 'local_rtocompliance'), [
    'type' => 'submit', 'class' => 'btn btn-primary mb-2',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// CERT-OF-COMPLETION + TEST-CERT (v4.2.41) — quick link to the test generator.
echo html_writer::start_div('mb-3');
$testurl = new moodle_url('/local/rtocompliance/cert_test.php');
echo html_writer::link($testurl, get_string('cert_test_link', 'local_rtocompliance'),
    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']);
echo html_writer::end_div();

// Table of existing templates.
// HIDE-ARCHIVED (v7.5.0): archived templates are hidden by default so the list
// stays tidy.  A toggle link lets admins reveal them when needed.
$showarchived = optional_param('showarchived', 0, PARAM_INT);
$templates = cert_template::list_all();

// Count archived separately so we can show a toggle hint.
$archivedcount = count(array_filter($templates, fn($t) => $t->status === 'archived'));
if (!$showarchived) {
    $templates = array_values(array_filter($templates, fn($t) => $t->status !== 'archived'));
}

// Toggle link.
$pageurl = new moodle_url('/local/rtocompliance/cert_templates.php');
if ($archivedcount > 0) {
    if ($showarchived) {
        $toggleurl  = new moodle_url($pageurl, ['showarchived' => 0]);
        $toggletext = 'Hide archived (' . $archivedcount . ')';
        $togglecls  = 'btn btn-sm btn-outline-secondary mb-3';
    } else {
        $toggleurl  = new moodle_url($pageurl, ['showarchived' => 1]);
        $toggletext = 'Show archived (' . $archivedcount . ')';
        $togglecls  = 'btn btn-sm btn-outline-secondary mb-3';
    }
    echo html_writer::link($toggleurl, $toggletext, ['class' => $togglecls]);
}

if (empty($templates)) {
    echo html_writer::div(get_string('cert_template_none_yet', 'local_rtocompliance'), 'alert alert-secondary');
} else {
    $table = new html_table();
    $table->head = [
        get_string('cert_template_certtype', 'local_rtocompliance'),
        get_string('cert_template_audience', 'local_rtocompliance'),
        get_string('cert_template_name', 'local_rtocompliance'),
        get_string('cert_template_status', 'local_rtocompliance'),
        get_string('cert_template_modified', 'local_rtocompliance'),
        get_string('cert_template_actions', 'local_rtocompliance'),
    ];
    $table->attributes['class'] = 'generaltable rtoc-tmpl-table';

    foreach ($templates as $t) {
        $row = new html_table_row();
        $row->cells[] = get_string('cert_template_certtype_' . $t->certtype, 'local_rtocompliance');
        // v4.3.0 CERT-TEMPLATE-AUDIENCES — audience badge per row.
        // Older rows back-fill to 'default' via the schema DEFAULT.
        $audcode = !empty($t->audience) ? $t->audience : 'default';
        $audtext = !empty($t->audiencelabel) ? $t->audiencelabel
            : get_string('cert_template_audience_' . $audcode, 'local_rtocompliance');
        $audcls  = ($audcode === 'default') ? 'badge bg-secondary text-white'
                                            : 'badge bg-info text-white';
        $row->cells[] = html_writer::span(format_string($audtext), $audcls);
        $row->cells[] = format_string($t->name);

        // Status badges.
        $statusbadge = '';
        $cls = match ($t->status) {
            'approved' => 'badge bg-success text-white',
            'archived' => 'badge bg-secondary text-white',
            default    => 'badge bg-warning text-dark',
        };
        $statusbadge = html_writer::span(get_string('cert_template_status_' . $t->status, 'local_rtocompliance'), $cls);
        if ($t->isactive) {
            $statusbadge .= ' ' . html_writer::span(get_string('cert_template_active_badge', 'local_rtocompliance'),
                'badge bg-primary text-white ml-1');
        }
        // ASQA validation badge from lastvalidation.
        if (!empty($t->lastvalidation)) {
            $val = json_decode($t->lastvalidation, true);
            $errs = is_array($val) ? count($val['errors'] ?? []) : 0;
            $warns = is_array($val) ? count($val['warnings'] ?? []) : 0;
            if ($errs > 0) {
                $statusbadge .= ' ' . html_writer::span($errs . ' ASQA errors', 'badge bg-danger text-white ml-1');
            } else if ($warns > 0) {
                $statusbadge .= ' ' . html_writer::span($warns . ' warnings', 'badge bg-warning text-dark ml-1');
            } else {
                $statusbadge .= ' ' . html_writer::span('ASQA OK', 'badge bg-success text-white ml-1');
            }
        }
        $row->cells[] = $statusbadge;

        $row->cells[] = userdate($t->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'));

        // Actions.
        $actions = [];

        $editurl = new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $t->id]);
        $actions[] = html_writer::link($editurl, get_string('cert_template_edit_btn', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-outline-primary mr-1 mb-1']);

        $previewurl = new moodle_url('/local/rtocompliance/cert_template_preview.php', ['id' => $t->id]);
        $actions[] = html_writer::link($previewurl, get_string('cert_template_preview_btn', 'local_rtocompliance'),
            ['class' => 'btn btn-sm btn-outline-secondary mr-1 mb-1', 'target' => '_blank', 'rel' => 'noopener']);

        $sk = sesskey();
        $mkaction = function (string $act, string $label, string $btnclass, ?string $confirm = null) use ($t, $sk) {
            $url = new moodle_url('/local/rtocompliance/cert_template_action.php', [
                'action' => $act, 'id' => $t->id, 'sesskey' => $sk,
            ]);
            $attrs = ['class' => 'btn btn-sm ' . $btnclass . ' mr-1 mb-1'];
            if ($confirm !== null) {
                $attrs['onclick'] = "return confirm(" . json_encode($confirm) . ");";
            }
            return html_writer::link($url, $label, $attrs);
        };

        if ($t->status === 'draft') {
            $actions[] = $mkaction('submit', get_string('cert_template_submit_btn', 'local_rtocompliance'),
                'btn-success');
            // Deletable only if never approved.
            if (empty($t->timeapproved)) {
                $actions[] = $mkaction('delete', get_string('cert_template_delete_btn', 'local_rtocompliance'),
                    'btn-outline-danger',
                    get_string('cert_template_confirm_delete', 'local_rtocompliance'));
            }
        }

        if ($t->status === 'approved' && !$t->isactive) {
            $actions[] = $mkaction('activate', get_string('cert_template_activate_btn', 'local_rtocompliance'),
                'btn-primary',
                get_string('cert_template_confirm_activate', 'local_rtocompliance'));
        }

        if (in_array($t->status, ['draft', 'approved'], true)) {
            $actions[] = $mkaction('archive', get_string('cert_template_archive_btn', 'local_rtocompliance'),
                'btn-outline-secondary',
                get_string('cert_template_confirm_archive', 'local_rtocompliance'));
        }

        // HIDE-ARCHIVED (v7.5.0): archived templates can now be permanently deleted.
        if ($t->status === 'archived') {
            $actions[] = $mkaction('delete', get_string('cert_template_delete_btn', 'local_rtocompliance'),
                'btn-outline-danger',
                'Permanently delete this archived template? This cannot be undone.');
        }

        $actions[] = $mkaction('duplicate', get_string('cert_template_duplicate_btn', 'local_rtocompliance'),
            'btn-outline-secondary');

        // ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Reset to ASQA starter button.
        // One-click recovery if an admin breaks a template while editing.
        $actions[] = $mkaction('reset', get_string('cert_template_reset_btn', 'local_rtocompliance'),
            'btn-outline-warning',
            get_string('cert_template_confirm_reset', 'local_rtocompliance'));

        $row->cells[] = implode('', $actions);
        $table->data[] = $row;
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
