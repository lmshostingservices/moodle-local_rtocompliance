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
 * RTO Compliance plugin — natexport.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/nat_generator.php');

use local_rtocompliance\nat_generator;

admin_externalpage_setup('local_rtocompliance_natexport');
require_login();
require_capability('local/rtocompliance:exportnat', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$year = optional_param('year', date('Y'), PARAM_INT);
$PAGE->set_title(get_string('natexport', 'local_rtocompliance'));
$PAGE->set_heading(get_string('nat_export_title', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$navheader = local_rtocompliance_render_nav_header(get_string('natexport', 'local_rtocompliance'), null, null, 'natexport');

if ($action === 'validate' && confirm_sesskey()) {
    $generator = new nat_generator($year);
    $result = $generator->validate();
    
    $SESSION->nat_validation = $result;
    redirect(new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]));
}

if ($action === 'generate' && confirm_sesskey()) {
    $generator = new nat_generator($year);
    $result = $generator->validate();
    
    if (!empty($result['errors'])) {
        // Bug 30: Validation errors may contain student names/USIs from the DB.
        // Sanitise before passing to redirect() which renders them in the notification.
        $safeerrors = array_map('htmlspecialchars', $result['errors']);
        redirect(
            new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]),
            'Cannot generate NAT files: ' . implode(', ', $safeerrors),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    
    $files = $generator->generate_all();
    
    $zipfilename = 'AVETMISS_' . $year . '_' . date('Ymd_His') . '.zip';
    $tempdir = make_temp_directory('natexport');
    $zippath = $tempdir . '/' . $zipfilename;
    
    $zip = new ZipArchive();
    if ($zip->open($zippath, ZipArchive::CREATE) === true) {
        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }
        $zip->close();
        
        $totalrecords = 0;
        foreach ($result['record_counts'] as $count) {
            $totalrecords += $count;
        }
        
        $export = new stdClass();
        $export->exporttype = 'nat';
        $export->exportedby = $USER->id;
        $export->periodstart = strtotime("$year-01-01");
        $export->periodend = strtotime("$year-12-31 23:59:59");
        $export->recordcount = $totalrecords;
        $export->validationerrors = count($result['errors']);
        $export->validationwarnings = count($result['warnings']);
        $export->filepath = $zipfilename;
        $export->status = 'generated';
        $export->timecreated = time();
        $export->id = $DB->insert_record('local_rtocompliance_exports', $export);
        
        $log = new stdClass();
        $log->action = 'generate';
        $log->component = 'natexport';
        $log->itemid = $export->id;
        $log->userid = $USER->id;
        $log->targetuserid = null;
        $log->details = json_encode([
            'year' => $year,
            'files' => array_keys($files),
            'record_counts' => $result['record_counts'],
        ]);
        $log->ipaddress = getremoteaddr();
        $log->timecreated = time();
        $DB->insert_record('local_rtocompliance_log', $log);
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipfilename . '"');
        header('Content-Length: ' . filesize($zippath));
        readfile($zippath);
        unlink($zippath);
        exit;
    } else {
        redirect(
            new moodle_url('/local/rtocompliance/natexport.php', ['year' => $year]),
            'Failed to create ZIP file',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

// TASK-77 (v5.9.357): Count VET enrolments with no qualification code — warn before export.
$_blank_programcode_count = (int)$DB->count_records_sql(
    "SELECT COUNT(*)
       FROM {local_rtocompliance_enrolments}
      WHERE (programcode IS NULL OR programcode = '')
        AND courseid IS NOT NULL
        AND courseid > 0
        AND (vetflag IS NULL OR vetflag != 'N')"
);

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo $navheader;

echo html_writer::start_div('natexport-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('nat_export_title', 'local_rtocompliance'));
echo html_writer::end_div();
echo html_writer::tag('p', get_string('nat_export_desc', 'local_rtocompliance'), ['class' => 'text-muted', 'style' => 'margin-bottom:1.5rem;']);

// TASK-24 (v5.9.323): State portal reference ID panel.
// These config values are NOT NAT file fields — they are identifiers admins must type into
// state portals (QLD RAPT, NSW Smart & Skilled, VIC SVTS, etc.) when uploading their AVETMISS
// files. Showing them here saves navigating away to RTO Settings mid-export.
// TASK-30 (v5.9.325): Each configured row now also shows a "Go to portal →" link.
$_portal_urls = [
    'qld_dtet_rtoid'    => 'https://rapt.training.qld.gov.au/',
    'nsw_commitment_id' => 'https://smartandskilled.nsw.gov.au/',
    'vic_contract_id'   => 'https://www.svts.vic.gov.au/',
    'sa_contract_ref'   => 'https://starr.sa.gov.au/',
    'wa_contract_number'=> 'https://rapt.dtwd.wa.gov.au/',
    'tas_contract_ref'  => 'https://www.skills.tas.gov.au/',
    'nt_contract_ref'   => 'https://training.nt.gov.au/',
    'act_avetars_ref'   => 'https://www.avetars.act.gov.au/',
];
$_portal_ids = [
    'qld_dtet_rtoid'   => get_config('local_rtocompliance', 'qld_dtet_rtoid'),
    'nsw_commitment_id' => get_config('local_rtocompliance', 'nsw_commitment_id'),
    'vic_contract_id'  => get_config('local_rtocompliance', 'vic_contract_id'),
    'sa_contract_ref'  => get_config('local_rtocompliance', 'sa_contract_ref'),
    'wa_contract_number' => get_config('local_rtocompliance', 'wa_contract_number'),
    'tas_contract_ref' => get_config('local_rtocompliance', 'tas_contract_ref'),
    'nt_contract_ref'  => get_config('local_rtocompliance', 'nt_contract_ref'),
    'act_avetars_ref'  => get_config('local_rtocompliance', 'act_avetars_ref'),
];
$_configured_ids = array_filter($_portal_ids);

$_statefundingurl = new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_statefunding']);
$_codeStyle = 'background:#e0f2fe;padding:2px 8px;border-radius:4px;font-size:0.875rem;letter-spacing:0.04em;';
$_labelStyle = 'padding:4px 16px 4px 0;font-size:0.875rem;color:#374151;white-space:nowrap;font-weight:600;';
$_valueStyle = 'padding:4px 0;';

if (!empty($_configured_ids)) {
    // One or more portal IDs are configured — show collapsible panel.
    echo '<details style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:0;margin-bottom:24px;" open>';
    echo '<summary style="padding:14px 20px;cursor:pointer;font-size:0.95rem;font-weight:600;color:#0c4a6e;list-style:none;display:flex;align-items:center;gap:8px;">';
    echo '&#128193; ' . get_string('portal_reference_ids_heading', 'local_rtocompliance');
    echo '</summary>';
    echo '<div style="padding:0 20px 16px 20px;">';
    echo html_writer::tag('p',
        get_string('portal_reference_ids_desc', 'local_rtocompliance') . ' ' .
        html_writer::link($_statefundingurl, get_string('portal_reference_ids_edit_link', 'local_rtocompliance'),
            ['style' => 'font-size:0.85rem;']),
        ['style' => 'font-size:0.85rem;color:#0369a1;margin:0 0 12px 0;']
    );
    echo html_writer::start_tag('table', ['style' => 'border-collapse:collapse;width:100%;max-width:700px;']);
    foreach ($_configured_ids as $_key => $_val) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            get_string($_key, 'local_rtocompliance') . ':',
            ['style' => $_labelStyle]
        );
        echo html_writer::tag('td',
            html_writer::tag('code', s($_val), ['style' => $_codeStyle]),
            ['style' => $_valueStyle]
        );
        $_portalLink = '';
        if (!empty($_portal_urls[$_key])) {
            $_portalLink = html_writer::link(
                $_portal_urls[$_key],
                get_string('portal_go_to_portal', 'local_rtocompliance') . ' →',
                ['target' => '_blank', 'rel' => 'noopener noreferrer',
                 'style' => 'font-size:0.8rem;white-space:nowrap;margin-left:8px;']
            );
        }
        echo html_writer::tag('td', $_portalLink, ['style' => 'padding:4px 0 4px 8px;']);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('table');
    echo '</div>';
    echo '</details>';
} else {
    // None configured — show a subtle prompt so admins know the feature exists.
    echo html_writer::start_div('', [
        'style' => 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin-bottom:24px;font-size:0.85rem;color:#6b7280;',
    ]);
    echo get_string('portal_reference_ids_unconfigured', 'local_rtocompliance',
        html_writer::link($_statefundingurl, get_string('statefunding_settings', 'local_rtocompliance')));
    echo html_writer::end_div();
}

$currentyear = date('Y');
$years = [];
for ($y = $currentyear; $y >= $currentyear - 5; $y--) {
    $years[$y] = $y;
}

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url, 'style' => 'margin-bottom: 24px;']);
echo html_writer::start_div('form-group', ['style' => 'display: flex; align-items: center; gap: 12px;']);
echo html_writer::label(get_string('nat_period', 'local_rtocompliance'), 'year');
echo html_writer::select(
    $years,
    'year',
    $year,
    null,
    ['id' => 'year', 'class' => 'form-control', 'style' => 'max-width: 120px;', 'onchange' => 'this.form.submit();']
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (!empty($SESSION->nat_validation)) {
    $validation = $SESSION->nat_validation;
    unset($SESSION->nat_validation);
    
    echo html_writer::start_div('', ['style' => 'margin-bottom: 24px;']);
    
    if (!empty($validation['errors'])) {
        echo html_writer::tag('div',
            html_writer::tag('h4', 'Validation Errors') .
            html_writer::alist($validation['errors']),
            ['style' => 'background: #fef2f2; border: 1px solid #ef4444; border-radius: 8px; padding: 16px; margin-bottom: 12px; color: #991b1b;']
        );
    }
    
    if (!empty($validation['warnings'])) {
        echo html_writer::tag('div',
            html_writer::tag('h4', 'Validation Warnings') .
            html_writer::alist($validation['warnings']),
            ['style' => 'background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 12px; color: #92400e;']
        );
    }
    
    if (empty($validation['errors']) && empty($validation['warnings'])) {
        echo html_writer::tag('div',
            html_writer::tag('strong', 'Validation Passed') . ' - Data is ready for AVETMISS export.',
            ['style' => 'background: #f0fdf4; border: 1px solid #22c55e; border-radius: 8px; padding: 16px; color: #166534;']
        );
    }
    
    if (!empty($validation['record_counts'])) {
        echo html_writer::tag('h4', 'Record Counts', ['style' => 'margin-top: 16px;']);
        $countlist = [];
        foreach ($validation['record_counts'] as $file => $count) {
            $countlist[] = "$file: $count records";
        }
        echo html_writer::alist($countlist);
    }
    
    echo html_writer::end_div();
}

$natfiles = [
    'NAT00010' => ['name' => 'Training Organisation', 'desc' => 'RTO details, contact information'],
    'NAT00020' => ['name' => 'Training Organisation Delivery Location', 'desc' => 'Campus and delivery site addresses'],
    'NAT00030' => ['name' => 'Program (Course/Qualification)', 'desc' => 'Qualifications, skill sets, accredited courses'],
    'NAT00060' => ['name' => 'Subject (Module/Unit of Competency)', 'desc' => 'Units being delivered'],
    'NAT00080' => ['name' => 'Client (Student)', 'desc' => 'Student demographics and AVETMISS data'],
    'NAT00085' => ['name' => 'Client Postal Details', 'desc' => 'Student address information'],
    'NAT00090' => ['name' => 'Client Disability', 'desc' => 'Disability type codes for applicable students'],
    'NAT00100' => ['name' => 'Client Prior Educational Achievement', 'desc' => 'Previous qualifications'],
    'NAT00120' => ['name' => 'Enrolment', 'desc' => 'Unit enrolments with outcomes, dates, delivery mode'],
    'NAT00130' => ['name' => 'Program (Qualification) Completion', 'desc' => 'Completed qualifications issued'],
];

echo html_writer::tag('h3', 'NAT Files to Generate');

echo html_writer::start_div('nat-files-list');
foreach ($natfiles as $code => $file) {
    echo html_writer::start_div('nat-file-item');
    echo html_writer::start_div('nat-file-info');
    echo html_writer::tag('span', $code, ['class' => 'nat-file-code']);
    echo html_writer::tag('span', $file['name'], ['class' => 'nat-file-desc']);
    echo html_writer::end_div();
    echo html_writer::tag('small', $file['desc'], ['class' => 'text-muted']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// TASK-77 (v5.9.357): Warn if any VET enrolments still have no qualification code.
if ($_blank_programcode_count > 0) {
    $_skipped_url = new moodle_url('/local/rtocompliance/skipped_programcodes.php');
    echo html_writer::tag(
        'div',
        html_writer::tag('strong',
            html_writer::tag('svg', '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
                ['xmlns' => 'http://www.w3.org/2000/svg', 'viewBox' => '0 0 24 24', 'fill' => 'none', 'style' => 'width:16px;height:16px;vertical-align:middle;margin-right:6px;flex-shrink:0;']
            ) .
            get_string('nat_warn_blank_programcode_heading', 'local_rtocompliance', $_blank_programcode_count)
        ) .
        html_writer::tag('p',
            get_string('nat_warn_blank_programcode_body', 'local_rtocompliance') . ' ' .
            html_writer::link($_skipped_url,
                get_string('nat_warn_blank_programcode_link', 'local_rtocompliance'),
                ['style' => 'color:#92400e;font-weight:600;text-decoration:underline;']
            ),
            ['style' => 'margin:6px 0 0 22px;font-size:0.9rem;']
        ),
        ['style' => 'background:#fffbeb;border:1px solid #f59e0b;border-left:4px solid #d97706;border-radius:8px;padding:14px 18px;margin-bottom:20px;color:#92400e;']
    );
}

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px;']);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/natexport.php', ['action' => 'validate', 'year' => $year, 'sesskey' => sesskey()]),
    get_string('nat_validate', 'local_rtocompliance'),
    ['class' => 'btn btn-secondary', 'title' => 'Check the selected reporting period for data errors before generating files']
);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/natexport.php', ['action' => 'generate', 'year' => $year, 'sesskey' => sesskey()]),
    get_string('nat_generate', 'local_rtocompliance'),
    ['class' => 'btn btn-primary', 'title' => 'Generate and download the AVETMISS NAT files for the selected period']
);
echo html_writer::end_div();

$exports = $DB->get_records('local_rtocompliance_exports', [], 'timecreated DESC', '*', 0, 10);

if ($exports) {
    echo html_writer::tag('h3', 'Recent Exports', ['style' => 'margin-top: 32px;']);
    echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;"><div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">Recent Exports</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;"><div><strong>Date</strong> &mdash; when the export was generated</div><div><strong>Period</strong> &mdash; reporting year the files cover</div><div><strong>Records</strong> &mdash; total number of records included</div><div><strong>Validation</strong> &mdash; errors or warnings found, or Passed</div><div><strong>Download</strong> &mdash; retrieve the generated ZIP of NAT files</div></div></div>';
    echo html_writer::start_tag('table', ['class' => 'table', 'style' => 'background: white; border: 1px solid #e5e7eb; border-radius: 12px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Date', ['title' => 'Date and time the export was generated']);
    echo html_writer::tag('th', 'Period', ['title' => 'Reporting year the export covers']);
    echo html_writer::tag('th', 'Records', ['title' => 'Total number of records included in the export']);
    echo html_writer::tag('th', 'Validation', ['title' => 'Validation outcome: error or warning counts, or Passed']);
    echo html_writer::tag('th', '', ['title' => 'Download the generated NAT export ZIP file']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($exports as $export) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($export->timecreated, '%d %b %Y %H:%M'));
        echo html_writer::tag('td', date('Y', $export->periodstart));
        echo html_writer::tag('td', $export->recordcount);

        $validationhtml = '';
        if ($export->validationerrors > 0) {
            $validationhtml .= html_writer::tag('span', $export->validationerrors . ' errors', ['class' => 'status-badge status-urgent', 'title' => 'Serious problems in this export that the national reporting system will reject. These need fixing before you submit.']);
        }
        if ($export->validationwarnings > 0) {
            $validationhtml .= ($validationhtml ? ' ' : '') .
                html_writer::tag('span', $export->validationwarnings . ' warnings', ['class' => 'status-badge status-warning', 'title' => 'Possible problems worth checking. These will not stop you submitting, but may still need a look.']);
        }
        if (!$validationhtml) {
            $validationhtml = html_writer::tag('span', 'Passed', ['class' => 'status-badge status-ok', 'title' => 'No problems found. This export is ready to submit to the national reporting system.']);
        }
        echo html_writer::tag('td', $validationhtml);

        echo html_writer::tag('td',
            html_writer::link(
                new moodle_url('/local/rtocompliance/download_nat.php', ['id' => $export->id, 'sesskey' => sesskey()]),
                get_string('nat_download', 'local_rtocompliance'),
                ['class' => 'btn btn-sm btn-primary', 'title' => 'Download the ZIP of NAT files for this export']
            )
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::end_div();

echo $OUTPUT->footer();
