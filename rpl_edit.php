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
 * RTO Compliance plugin — rpl_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id  = optional_param('id', 0, PARAM_INT);
$tab = optional_param('tab', 'rpl', PARAM_ALPHA);

admin_externalpage_setup('local_rtocompliance_rpl');
require_login();
$PAGE->set_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'tab' => $tab]);
$PAGE->set_title($id ? 'Edit RPL / Credit Transfer Record' : 'Add RPL / Credit Transfer Record');
$PAGE->set_heading($id ? 'Edit Record' : 'Add Record');

$dbman = $DB->get_manager();

$record = null;
if ($id && $dbman->table_exists('local_rtocompliance_rpl')) {
    $record = $DB->get_record('local_rtocompliance_rpl', ['id' => $id]);
    if (!$record) {
        redirect(new moodle_url('/local/rtocompliance/rpl.php'), 'Record not found.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $tab = $record->rpltype === 'credit_transfer' ? 'credit' : 'rpl';
}

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    // Bug I note: studentname is stored as free text with no foreign key to
    // local_rtocompliance_students or the Moodle user table. This means RPL
    // records cannot be reconciled with AVETMISS enrolments, USI records, or
    // certificates (a name typo silently orphans the record). A future schema
    // upgrade should add a nullable `userid` column and resolve the student via
    // a Moodle user selector rather than a plain text field.
    $data = new stdClass();
    $data->studentid          = optional_param('studentid', 0, PARAM_INT) ?: null;
    $data->studentname        = optional_param('studentname', '', PARAM_TEXT);
    // Auto-fill the display name from the linked student when left blank.
    if (($data->studentname === '' || $data->studentname === null) && $data->studentid) {
        $srec = $DB->get_record_sql(
            "SELECT u.firstname, u.lastname FROM {local_rtocompliance_students} s
               JOIN {user} u ON u.id = s.userid WHERE s.id = :sid",
            ['sid' => $data->studentid]);
        if ($srec) {
            $data->studentname = trim($srec->firstname . ' ' . $srec->lastname);
        }
    }
    $data->unitcode           = optional_param('unitcode', '', PARAM_TEXT);
    $data->unitname           = optional_param('unitname', '', PARAM_TEXT);
    // RPL-P1 (v5.9.424): superseded→current unit mapping. Capture the prior/superseded
    // unit the student actually holds and its TGA equivalence to the current unit being
    // granted, so credit for a current unit based on a superseded one is documented
    // (and a "not equivalent" mapping is visibly flagged as needing gap assessment).
    $data->supersededunitcode = strtoupper(trim(optional_param('supersededunitcode', '', PARAM_TEXT)));
    $data->unitequivalence    = optional_param('unitequivalence', '', PARAM_ALPHAEXT);
    // RPL-P1 (v5.9.424): evidence-to-criteria matrix arrives as a JSON string from the
    // repeating table. Normalise it (decode → keep only well-formed rows → re-encode) so
    // only clean data is stored; an empty/garbage payload becomes null.
    $matrixraw = optional_param('evidencematrix', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    $data->evidencematrix = null;
    if (trim($matrixraw) !== '') {
        $decoded = json_decode($matrixraw, true);
        if (is_array($decoded)) {
            $clean = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $criterion = trim((string)($row['criterion'] ?? ''));
                $evidence  = trim((string)($row['evidence'] ?? ''));
                $judgement = trim((string)($row['judgement'] ?? ''));
                if ($criterion === '' && $evidence === '' && $judgement === '') {
                    continue;
                }
                $clean[] = [
                    'criterion' => clean_param($criterion, PARAM_TEXT),
                    'evidence'  => clean_param($evidence, PARAM_TEXT),
                    'judgement' => clean_param($judgement, PARAM_TEXT),
                ];
            }
            if (!empty($clean)) {
                $data->evidencematrix = json_encode($clean);
            }
        }
    }
    $data->qualcode           = optional_param('qualcode', '', PARAM_TEXT);
    $data->qualname           = optional_param('qualname', '', PARAM_TEXT);
    $data->rpltype            = required_param('rpltype', PARAM_ALPHANUMEXT);
    $data->evidencedescription = optional_param('evidencedescription', '', PARAM_TEXT);
    $data->evidencefiles      = optional_param('evidencefiles', '', PARAM_TEXT);
    $data->assessorname       = optional_param('assessorname', '', PARAM_TEXT);
    // RPL-P2 (v5.9.421): capture the real assessor (trainer selector → user id) so the
    // decision can be tied to a person whose competence/currency the RTO can evidence
    // (Standard 1.5), not just a free-typed name.
    $data->assessoruserid     = optional_param('assessoruserid', 0, PARAM_INT) ?: null;
    // Keep the display name in sync so the register and exports still show a name even
    // when the assessor was chosen from the trainer dropdown and no name was typed.
    if (!empty($data->assessoruserid) && trim((string) $data->assessorname) === '') {
        $au = $DB->get_record('user', ['id' => $data->assessoruserid], 'firstname, lastname');
        if ($au) {
            $data->assessorname = trim($au->firstname . ' ' . $au->lastname);
        }
    }
    $data->decision           = required_param('decision', PARAM_ALPHAEXT);
    $decdateraw               = optional_param('decisiondate', '', PARAM_TEXT);
    $data->decisiondate       = $decdateraw ? strtotime($decdateraw) : null;
    $data->decisionreason     = optional_param('decisionreason', '', PARAM_TEXT);
    $data->sourcequalcode     = optional_param('sourcequalcode', '', PARAM_TEXT);
    $data->sourcertoid        = optional_param('sourcertoid', '', PARAM_TEXT);
    $data->usitranscriptverified = optional_param('usitranscriptverified', 0, PARAM_INT);
    // RPL-P2 (v5.9.421): procedural-fairness — record that the outcome was communicated
    // to the student, and when/how (Standard 1.6 requires the student be informed of the
    // decision). The date defaults to the decision date when the flag is set but no date
    // is entered, so the common case needs one click.
    $data->outcomecommunicated = optional_param('outcomecommunicated', 0, PARAM_INT);
    $occdateraw               = optional_param('outcomecommunicateddate', '', PARAM_TEXT);
    $data->outcomecommunicateddate = $occdateraw ? strtotime($occdateraw) : null;
    if ($data->outcomecommunicated && empty($data->outcomecommunicateddate)) {
        $data->outcomecommunicateddate = $data->decisiondate ?: time();
    }
    $data->outcomecommunicatedmethod = optional_param('outcomecommunicatedmethod', '', PARAM_TEXT);
    $data->timemodified       = time();

    // RPL-REQUIRED-FIELDS (v5.9.416): the form marks Student, Decision and Decision
    // Reason as required (*), but only rpltype/decision were enforced server-side, so
    // a record could be saved with no student link (silently orphaning it from Student
    // Results) and no documented rationale (ASQA requires written justification for
    // every RPL/CT decision). Enforce them here before writing.
    $rplerrors = [];
    if (empty($data->studentid)) {
        $rplerrors[] = 'select a student';
    }
    if (trim((string)$data->decisionreason) === '') {
        $rplerrors[] = 'provide a decision reason / justification (ASQA requires documented rationale)';
    }
    if (!empty($rplerrors)) {
        redirect(
            new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'tab' => $tab]),
            'Please ' . implode(' and ', $rplerrors) . '.',
            null, \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($dbman->table_exists('local_rtocompliance_rpl')) {
        // v5.9.381: an APPROVED (or partially approved) decision linked to a real
        // student + unit posts the RPL/CT outcome into the results register.
        $postoutcome = function () use ($data, $id) {
            if (in_array($data->decision, ['approved', 'partially_approved'], true)
                    && !empty($data->studentid) && trim((string)$data->unitcode) !== '') {
                // RPL-P1 (v5.9.424): a NOT-EQUIVALENT superseded-unit mapping cannot justify
                // credit on its own — the gap must be assessed. Advise (non-blocking) so the
                // assessor confirms the current unit was actually met, not merely mapped.
                if ((string)($data->unitequivalence ?? '') === 'not_equivalent') {
                    \core\notification::add(
                        'Note: the superseded unit is marked NOT equivalent to ' . s($data->unitcode)
                        . '. A not-equivalent mapping does not by itself grant the current unit — ensure the '
                        . 'gap has been assessed and the evidence-to-criteria matrix supports full competency.',
                        \core\output\notification::NOTIFY_WARNING
                    );
                }
                // CT-SOURCE-GATE (v5.9.419, hardening Standard 1.7 / A-P2-2): credit
                // transfer is national recognition — the RTO must sight and authenticate
                // the ORIGINAL AQF certification from the issuing RTO. Before the CT
                // outcome (60) is posted to the results register we now require BOTH an
                // authenticated source — a verified USI transcript OR an uploaded source
                // certificate/transcript on this record — AND the source qualification
                // code. Previously only the self-attested "USI transcript verified"
                // checkbox was required, with no source document or code enforced.
                if ((string)$data->rpltype === 'credit_transfer') {
                    $hassource = !empty($data->usitranscriptverified);
                    if (!$hassource && $id && function_exists('local_rtocompliance_get_rpl_evidence_files')) {
                        $hassource = count(local_rtocompliance_get_rpl_evidence_files((int)$id, 'ct_sourcecert')) > 0;
                    }
                    $hasqualcode = trim((string)($data->sourcequalcode ?? '')) !== '';
                    if (!$hassource || !$hasqualcode) {
                        \core\notification::add(
                            'Credit transfer outcome was NOT recorded in the results register. '
                            . 'Standard 1.7 (national recognition) requires an authenticated source before credit '
                            . 'is granted: a verified USI transcript OR an uploaded source certificate/transcript from '
                            . 'the issuing RTO, AND the source qualification code. The application record has been '
                            . 'saved — add the source document/code and set the decision to Approved to record the outcome.',
                            \core\output\notification::NOTIFY_WARNING
                        );
                        return;
                    }
                }
                local_rtocompliance_apply_rpl_outcome((int)$data->studentid, (string)$data->unitcode,
                    (string)$data->unitname, (string)$data->qualcode, (string)$data->qualname, (string)$data->rpltype);
            }
        };
        if ($id && $record) {
            // RPL-CT-RETRACT (v5.9.416): if this record was previously approved (so its
            // competency was posted to the results register) and is now being changed to
            // a non-approved decision, retract that posted outcome so a reversed decision
            // can't leave a competent unit over-reporting into completion/certs/NAT.
            $wasapproved = in_array($record->decision, ['approved', 'partially_approved'], true);
            $nowapproved = in_array($data->decision, ['approved', 'partially_approved'], true);
            if ($wasapproved && !$nowapproved && !empty($record->studentid) && trim((string)$record->unitcode) !== '') {
                local_rtocompliance_retract_rpl_outcome((int)$record->studentid, (string)$record->unitcode);
            }
            $data->id = $id;
            $DB->update_record('local_rtocompliance_rpl', $data);
            local_rtocompliance_log_action('update', 'rpl', $id, ['student' => $data->studentname, 'decision' => $data->decision]);
            // RPL-CT-EVIDENCE-UPLOAD (v5.9.410): store any newly-uploaded evidence /
            // source-certificate files against this record id.
            $upev = local_rtocompliance_save_rpl_evidence_files($id, 'rpl_evidence', 'rpl_evidence');
            $upct = local_rtocompliance_save_rpl_evidence_files($id, 'ct_sourcecert', 'ct_sourcecert');
            $postoutcome();
            $upmsg = ($upev + $upct) > 0 ? ' ' . ($upev + $upct) . ' file(s) uploaded.' : '';
            redirect(new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]), 'Record updated successfully.' . $upmsg, null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            $data->timecreated = time();
            $newid = $DB->insert_record('local_rtocompliance_rpl', $data);
            local_rtocompliance_log_action('create', 'rpl', $newid, ['student' => $data->studentname, 'type' => $data->rpltype]);
            // RPL-CT-EVIDENCE-UPLOAD (v5.9.410): store uploads against the new record id.
            $upev = local_rtocompliance_save_rpl_evidence_files((int) $newid, 'rpl_evidence', 'rpl_evidence');
            $upct = local_rtocompliance_save_rpl_evidence_files((int) $newid, 'ct_sourcecert', 'ct_sourcecert');
            $postoutcome();
            $upmsg = ($upev + $upct) > 0 ? ' ' . ($upev + $upct) . ' file(s) uploaded.' : '';
            redirect(new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]), 'Record saved successfully.' . $upmsg, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

if ($action === 'delete' && $id && confirm_sesskey()) {
    if ($dbman->table_exists('local_rtocompliance_rpl')) {
        // RPL-CT-EVIDENCE-UPLOAD (v5.9.410): clean up the record's uploaded files too.
        try {
            $fs = get_file_storage();
            $ctxid = context_system::instance()->id;
            $fs->delete_area_files($ctxid, 'local_rtocompliance', 'rpl_evidence', $id);
            $fs->delete_area_files($ctxid, 'local_rtocompliance', 'ct_sourcecert', $id);
        } catch (\Throwable $e) {
            debugging('RPL file cleanup on delete failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $DB->delete_records('local_rtocompliance_rpl', ['id' => $id]);
        local_rtocompliance_log_action('delete', 'rpl', $id, []);
        redirect(new moodle_url('/local/rtocompliance/rpl.php'), 'Record deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// RPL-CT-EVIDENCE-UPLOAD (v5.9.410): delete a single uploaded evidence file.
if ($action === 'delfile' && $id && confirm_sesskey()) {
    $delarea = optional_param('filearea', '', PARAM_ALPHANUMEXT);
    $delname = optional_param('filename', '', PARAM_FILE);
    if (in_array($delarea, ['rpl_evidence', 'ct_sourcecert'], true) && $delname !== '') {
        local_rtocompliance_delete_rpl_evidence_file($id, $delarea, $delname);
        local_rtocompliance_log_action('update', 'rpl', $id, ['deleted_file' => $delname]);
    }
    redirect(new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'tab' => $tab]),
        'Evidence file removed.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->add_body_class("path-local-rtocompliance");
$PAGE->requires->js('/local/rtocompliance/js/ai_suggest.js', false);
echo $OUTPUT->header();

// Use local_aiconfig (Central Config plugin) if installed — same priority chain as external.php.
$_rtoc_aicfglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($_rtoc_aicfglib)) {
    require_once($_rtoc_aicfglib);
}
$_rtoc_apikey = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
echo html_writer::tag('div', '', [
    'id'            => 'rtoc-ai-config',
    'data-api-key'  => $_rtoc_apikey,
    'data-api-base' => $_rtoc_apibase,
    'style'         => 'display:none',
    'aria-hidden'   => 'true',
]);

echo local_rtocompliance_render_nav_header('RPL & Credit Transfer');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', $id ? 'Edit RPL / Credit Transfer Record' : 'Add RPL / Credit Transfer Record');
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]),
    'Back to Register',
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

$formdata = $record ?: new stdClass();

echo html_writer::start_tag('form', [
    'method'  => 'post',
    'action'  => $PAGE->url->out_omit_querystring(),
    'enctype' => 'multipart/form-data',
    'style'   => 'max-width: 780px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);

// RPL-CT-EVIDENCE-UPLOAD (v5.9.410): renders an evidence upload block — the
// instructions, any files already uploaded (with download + remove links), and a
// multi-file input. Files can only be attached once the record exists (they are
// stored against the saved record id), so on a brand-new record we show a hint to
// save first. $id / $tab / $formdata are captured from the surrounding scope.
$renderEvidenceUpload = function (string $filearea, string $inputname, string $heading,
        string $instructions, string $emptyhint) use ($id) {
    echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
    echo html_writer::tag('label', $heading, ['class' => 'form-label', 'style' => 'font-weight:600;']);
    echo html_writer::tag('p', $instructions,
        ['class' => 'text-muted', 'style' => 'font-size:0.85rem; margin:4px 0 8px;']);

    if ($id) {
        $existing = local_rtocompliance_get_rpl_evidence_files($id, $filearea);
        if ($existing) {
            echo html_writer::start_tag('ul', ['style' => 'list-style:none; padding:0; margin:0 0 10px;']);
            foreach ($existing as $ef) {
                $kb = $ef['size'] > 0 ? ' (' . ceil($ef['size'] / 1024) . ' KB)' : '';
                $delurl = new moodle_url('/local/rtocompliance/rpl_edit.php', [
                    'id' => $id, 'action' => 'delfile', 'filearea' => $filearea,
                    'filename' => $ef['filename'], 'sesskey' => sesskey(),
                ]);
                echo html_writer::start_tag('li', ['style' => 'display:flex; align-items:center; gap:10px; padding:6px 10px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:6px;']);
                echo html_writer::tag('span', '📎', ['aria-hidden' => 'true']);
                echo html_writer::link($ef['url'], s($ef['filename']) . $kb,
                    ['target' => '_blank', 'rel' => 'noopener', 'style' => 'flex:1; word-break:break-all;']);
                echo html_writer::link($delurl, 'Remove', [
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => 'return confirm("Remove this file? This cannot be undone.");',
                ]);
                echo html_writer::end_tag('li');
            }
            echo html_writer::end_tag('ul');
        } else {
            echo html_writer::tag('p', 'No files uploaded yet.',
                ['class' => 'text-muted', 'style' => 'font-size:0.85rem;']);
        }
        echo html_writer::empty_tag('input', [
            'type' => 'file', 'name' => $inputname . '[]', 'id' => $inputname,
            'multiple' => 'multiple', 'class' => 'form-control',
            'accept' => '.pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.odt,.heic',
        ]);
        echo html_writer::tag('small',
            'You can select multiple files. Accepted: PDF, image, Word, Excel, PowerPoint, text — up to 20 MB each.',
            ['class' => 'form-text text-muted']);
    } else {
        echo html_writer::tag('div', $emptyhint, [
            'class' => 'alert alert-info',
            'style' => 'font-size:0.85rem; padding:10px 12px;',
        ]);
    }
    echo html_writer::end_div();
};

$currentType = $record->rpltype ?? ($tab === 'credit' ? 'credit_transfer' : 'rpl');

echo html_writer::start_div('form-section');
echo html_writer::tag('h4', 'Application Type & Student', ['style' => 'margin-bottom: 16px; color: #374151;']);

$typeOptions = ['rpl' => 'RPL -- Recognition of Prior Learning (Standard 1.6)', 'credit_transfer' => 'Credit Transfer (Standard 1.7)'];
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Application Type *', ['for' => 'rpltype', 'class' => 'form-label']);
echo html_writer::select($typeOptions, 'rpltype', $currentType, null, ['id' => 'rpltype', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();

// v5.9.381: link the record to a real student so an APPROVED decision writes the
// RPL (51) / Credit Transfer (60) outcome into the results register automatically.
$studentopts = ['' => '-- Select a student --'];
$strecs = $DB->get_records_sql(
    "SELECT s.id, u.firstname, u.lastname, s.usi
       FROM {local_rtocompliance_students} s
       JOIN {user} u ON u.id = s.userid
      WHERE u.deleted = 0
   ORDER BY u.lastname, u.firstname", null, 0, 2000);
foreach ($strecs as $sr) {
    $studentopts[$sr->id] = trim($sr->firstname . ' ' . $sr->lastname)
        . ($sr->usi ? ' (USI ' . $sr->usi . ')' : '');
}
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Student *', ['for' => 'studentid', 'class' => 'form-label']);
echo html_writer::select($studentopts, 'studentid', $formdata->studentid ?? '', false,
    ['id' => 'studentid', 'class' => 'form-control']);
echo html_writer::tag('small',
    'Linking a student lets an approved RPL / Credit Transfer decision post the outcome to Student Results automatically.',
    ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Student Name', ['for' => 'studentname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'studentname', 'id' => 'studentname',
    'value' => s($formdata->studentname ?? ''),
    'class' => 'form-control', 'placeholder' => 'Auto-filled from the selected student if left blank',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Unit / Qualification Details', ['style' => 'margin-bottom: 16px; color: #374151;']);

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 2fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Unit Code', ['for' => 'unitcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'unitcode', 'id' => 'unitcode',
    'value' => s($formdata->unitcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. BSBWHS411',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Unit Name', ['for' => 'unitname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'unitname', 'id' => 'unitname',
    'value' => s($formdata->unitname ?? ''),
    'class' => 'form-control', 'placeholder' => 'Unit of competency name',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 2fr; gap: 16px; margin-top: 12px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Qualification Code', ['for' => 'qualcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'qualcode', 'id' => 'qualcode',
    'value' => s($formdata->qualcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. BSB50120',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Qualification Name', ['for' => 'qualname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'qualname', 'id' => 'qualname',
    'value' => s($formdata->qualname ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. Diploma of Business',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// RPL-P1 (v5.9.424): superseded → current unit mapping. Only relevant when the student
// holds a superseded/prior version of the unit being granted.
echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Superseded unit held (optional)', ['for' => 'supersededunitcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'supersededunitcode', 'id' => 'supersededunitcode',
    'value' => s($formdata->supersededunitcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'e.g. BSBWHS401 (prior version the student holds)',
]);
echo html_writer::tag('div',
    'If the student holds an older, superseded version of the unit above, enter that code here. Credit for the current unit is then justified by the mapping.',
    ['class' => 'form-text text-muted', 'style' => 'font-size:0.82rem;']);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Equivalence (per TGA transition table)', ['for' => 'unitequivalence', 'class' => 'form-label']);
$equivOptions = [
    ''               => '-- Not applicable --',
    'equivalent'     => 'Equivalent (E) — direct credit',
    'not_equivalent' => 'Not equivalent (N) — gap assessment required',
    'not_applicable' => 'Not applicable',
];
echo html_writer::select($equivOptions, 'unitequivalence', $formdata->unitequivalence ?? '', false,
    ['id' => 'unitequivalence', 'class' => 'form-control']);
echo html_writer::tag('div',
    'TGA marks superseded-unit relationships as Equivalent (E) or Not Equivalent (N). An "N" mapping cannot be credited on its own — the gap must be assessed.',
    ['class' => 'form-text text-muted', 'style' => 'font-size:0.82rem;']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['id' => 'credit-transfer-section', 'style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Credit Transfer Details (Standard 1.7)', ['style' => 'margin-bottom: 16px; color: #374151;']);

echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Source Qualification Code', ['for' => 'sourcequalcode', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'sourcequalcode', 'id' => 'sourcequalcode',
    'value' => s($formdata->sourcequalcode ?? ''),
    'class' => 'form-control', 'placeholder' => 'Code of previously held qualification',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Source RTO Identifier', ['for' => 'sourcertoid', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'sourcertoid', 'id' => 'sourcertoid',
    'value' => s($formdata->sourcertoid ?? ''),
    'class' => 'form-control', 'placeholder' => 'RTO code of issuing provider',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
$usiChecked = !empty($formdata->usitranscriptverified) ? ['checked' => 'checked'] : [];
echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']);
echo html_writer::empty_tag('input', array_merge([
    'type' => 'checkbox', 'name' => 'usitranscriptverified', 'id' => 'usitranscriptverified',
    'value' => '1', 'class' => 'form-check-input',
], $usiChecked));
echo html_writer::tag('label', 'USI Transcript Verified -- Clause 12 requirement: USI transcript sighted or verified before credit granted', [
    'for' => 'usitranscriptverified', 'class' => 'form-check-label',
    'style' => 'font-weight: 500; color: #374151;',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// RPL-CT-EVIDENCE-UPLOAD (v5.9.410): upload the issuing RTO's certificate / transcript.
$renderEvidenceUpload(
    'ct_sourcecert',
    'ct_sourcecert',
    'Source certificate / transcript (from the issuing RTO)',
    'Upload the ORIGINAL evidence that authenticates this credit transfer: the testamur, '
        . 'Statement of Attainment, Record of Results, or USI transcript issued by the other RTO '
        . 'that shows the student already holds this unit. This is the ASQA Clause 1.7 / Clause 12 '
        . 'source document — attach a clear scan or PDF, then tick "USI Transcript Verified" above '
        . 'once you have confirmed it. Credit is only posted to Student Results once verified.',
    'Save the record first, then re-open it to attach the source certificate / transcript.'
);
echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Evidence & Assessment', ['style' => 'margin-bottom: 16px; color: #374151;']);

// RPL-P2 (v5.9.421): assessor selector drawn from the registered trainer workforce, so
// an RPL/CT decision is tied to a person whose TAE competence/currency the RTO holds
// evidence for (Standard 1.5). Falls back to the free-text name for an assessor not yet
// registered as a trainer. A current-TAE advisory is shown for the selected trainer.
$assessorOptions = ['' => '-- Select registered assessor (trainer) --'];
$trainerTae = [];   // userid => [hasTae, current]
try {
    if ($DB->get_manager()->table_exists('local_rtocompliance_trainers')) {
        $trainers = $DB->get_records_sql(
            "SELECT t.userid, u.firstname, u.lastname, t.taecredential, t.taeexpirydate
               FROM {local_rtocompliance_trainers} t
               JOIN {user} u ON u.id = t.userid
              WHERE u.deleted = 0
           ORDER BY u.lastname, u.firstname");
        foreach ($trainers as $t) {
            $nm = trim($t->firstname . ' ' . $t->lastname);
            $assessorOptions[$t->userid] = $nm . ($t->taecredential ? ' (' . $t->taecredential . ')' : '');
            $hastae  = trim((string) $t->taecredential) !== '';
            $current = $hastae && (empty($t->taeexpirydate) || $t->taeexpirydate >= time());
            $trainerTae[(int) $t->userid] = ['name' => $nm, 'hastae' => $hastae, 'current' => $current];
        }
    }
} catch (\Throwable $e) {
    debugging('RPL assessor list failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Assessor (registered trainer)', ['for' => 'assessoruserid', 'class' => 'form-label']);
echo html_writer::select($assessorOptions, 'assessoruserid', (int) ($formdata->assessoruserid ?? 0), false,
    ['id' => 'assessoruserid', 'class' => 'form-control']);
$selassessor = (int) ($formdata->assessoruserid ?? 0);
if ($selassessor && isset($trainerTae[$selassessor])) {
    $ti = $trainerTae[$selassessor];
    if (!$ti['hastae']) {
        echo html_writer::tag('div',
            '⚠ This trainer has no TAE credential recorded. Standard 1.5 requires the assessor to hold the '
            . 'relevant TAE qualification (or be supervised by someone who does).',
            ['class' => 'form-text', 'style' => 'color:#b45309;margin-top:4px;']);
    } else if (!$ti['current']) {
        echo html_writer::tag('div',
            '⚠ This trainer\'s TAE credential has an expiry date in the past. Confirm currency before relying on '
            . 'this assessment (Standard 1.5).',
            ['class' => 'form-text', 'style' => 'color:#b45309;margin-top:4px;']);
    } else {
        echo html_writer::tag('div', '✓ Selected assessor holds a current TAE credential.',
            ['class' => 'form-text', 'style' => 'color:#15803d;margin-top:4px;']);
    }
}
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Assessor name (if not a registered trainer)', ['for' => 'assessorname', 'class' => 'form-label']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'assessorname', 'id' => 'assessorname',
    'value' => s($formdata->assessorname ?? ''),
    'class' => 'form-control', 'placeholder' => 'Optional — use only when the assessor is not in the Trainers register',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Evidence Description', ['for' => 'evidencedescription', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->evidencedescription ?? ''), [
    'name' => 'evidencedescription', 'id' => 'evidencedescription',
    'class' => 'form-control', 'rows' => '4',
    'placeholder' => 'Describe the evidence provided: portfolio, work samples, references, statements, prior certificates, employer declarations, etc.',
]);
echo html_writer::end_div();

// RPL-P1 (v5.9.424): evidence-to-criteria matrix. Maps each evidence item to the unit
// assessment requirement it satisfies and the assessor's judgement — the core of a valid,
// sufficient, current & authentic RPL determination (Standard 1.2 principles of assessment
// / rules of evidence). Stored as JSON in the hidden field; rendered as a repeating table.
$matrixrows = [];
if (!empty($formdata->evidencematrix)) {
    $dec = json_decode($formdata->evidencematrix, true);
    if (is_array($dec)) {
        $matrixrows = $dec;
    }
}
echo html_writer::start_div('form-group', ['style' => 'margin-top: 16px;']);
echo html_writer::tag('label', 'Evidence-to-criteria matrix', ['class' => 'form-label']);
echo html_writer::tag('p',
    'Map each item of evidence to the unit requirement (element / performance criterion / performance or knowledge evidence) it addresses, and record the assessor judgement. This demonstrates the evidence is valid, sufficient, current and authentic (Standard 1.2).',
    ['class' => 'text-muted', 'style' => 'font-size: 0.85rem; margin-bottom: 8px;']);
echo '<table class="table table-sm" id="rpl-matrix-table" style="margin-bottom:8px;">';
echo '<thead><tr>'
    . '<th style="width:34%;">Unit requirement / criterion</th>'
    . '<th style="width:40%;">Evidence provided</th>'
    . '<th style="width:20%;">Judgement</th>'
    . '<th style="width:6%;"></th>'
    . '</tr></thead><tbody id="rpl-matrix-body"></tbody></table>';
echo html_writer::tag('button', '+ Add evidence row', [
    'type' => 'button', 'id' => 'rpl-matrix-add', 'class' => 'btn btn-sm btn-outline-secondary',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'evidencematrix', 'id' => 'evidencematrix',
    'value' => s($formdata->evidencematrix ?? ''),
]);
echo html_writer::end_div();
// Seed data for the JS renderer (safe JSON in a data island).
echo html_writer::tag('script', json_encode(array_values(array_filter($matrixrows, 'is_array'))),
    ['type' => 'application/json', 'id' => 'rpl-matrix-seed']);

// RPL-CT-EVIDENCE-UPLOAD (v5.9.410): real RPL evidence file upload.
$renderEvidenceUpload(
    'rpl_evidence',
    'rpl_evidence',
    'RPL evidence files',
    'Upload the evidence the assessor relied on to grant Recognition of Prior Learning: '
        . 'portfolio documents, work samples, third-party / employer references, prior qualifications '
        . 'or Statements of Attainment, résumé, position descriptions, photos of work, or a completed '
        . 'competency-conversation / observation record. Attach the actual documents here so the '
        . 'evidence is retained with the decision for audit (ASQA Standard 1.6).',
    'Save the record first, then re-open it to attach RPL evidence files.'
);

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Evidence notes / external references (optional)', ['for' => 'evidencefiles', 'class' => 'form-label']);
echo html_writer::tag('textarea', s($formdata->evidencefiles ?? ''), [
    'name' => 'evidencefiles', 'id' => 'evidencefiles',
    'class' => 'form-control', 'rows' => '3',
    'placeholder' => 'Optional: note any evidence held outside this system (e.g. original hard-copy documents on file, external drive references).',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('form-section', ['style' => 'margin-top: 20px;']);
echo html_writer::tag('h4', 'Decision', ['style' => 'margin-bottom: 16px; color: #374151;']);

$decisionOptions = [
    'pending'            => 'Pending -- Application under review',
    'approved'           => 'Approved -- RPL/credit granted in full',
    'partially_approved' => 'Partially Approved -- Some units granted',
    'not_approved'       => 'Not Approved -- Evidence insufficient',
];
echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Decision *', ['for' => 'decision', 'class' => 'form-label']);
echo html_writer::select($decisionOptions, 'decision', $formdata->decision ?? 'pending', null, ['id' => 'decision', 'class' => 'form-control', 'required' => 'required']);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Decision Date', ['for' => 'decisiondate', 'class' => 'form-label']);
$decdateVal = !empty($formdata->decisiondate) ? date('Y-m-d', $formdata->decisiondate) : '';
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'decisiondate', 'id' => 'decisiondate',
    'value' => $decdateVal, 'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-top: 12px;']);
echo html_writer::tag('label', 'Decision Reason / Justification *', ['for' => 'decisionreason', 'class' => 'form-label']);
echo html_writer::tag('p', 'ASQA requires documented rationale for all RPL/credit transfer decisions.', ['class' => 'text-muted', 'style' => 'font-size: 0.875rem; margin-bottom: 6px;']);
echo html_writer::tag('textarea', s($formdata->decisionreason ?? ''), [
    'name' => 'decisionreason', 'id' => 'decisionreason',
    'class' => 'form-control', 'rows' => '5',
    'placeholder' => 'Provide written justification for the decision. For RPL: explain how evidence demonstrates competency. For credit transfer: describe equivalence mapping. For not approved: explain gap in evidence.',
]);
echo html_writer::end_div();

// RPL-P2 (v5.9.421): procedural fairness — the student must be told the outcome of
// their RPL/credit-transfer application (Standard 1.6). Capture that this happened,
// when, and how, so the register evidences the whole decision-to-notification loop.
echo html_writer::start_div('form-group', ['style' => 'margin-top: 16px; padding-top: 12px; border-top: 1px solid #e5e7eb;']);
echo html_writer::start_div('form-check');
echo html_writer::empty_tag('input', array_merge([
    'type' => 'checkbox', 'name' => 'outcomecommunicated', 'id' => 'outcomecommunicated',
    'value' => '1', 'class' => 'form-check-input',
], !empty($formdata->outcomecommunicated) ? ['checked' => 'checked'] : []));
echo html_writer::tag('label', 'Outcome communicated to the student',
    ['for' => 'outcomecommunicated', 'class' => 'form-check-label', 'style' => 'margin-left: 6px;']);
echo html_writer::end_div();
echo html_writer::tag('p',
    'Tick once the student has been notified of the decision. Standard 1.6 requires the applicant be informed of the outcome of their RPL / credit-transfer application.',
    ['class' => 'text-muted', 'style' => 'font-size: 0.85rem; margin: 6px 0;']);
echo html_writer::start_div('form-row', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px;']);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Date communicated', ['for' => 'outcomecommunicateddate', 'class' => 'form-label']);
$occdateVal = !empty($formdata->outcomecommunicateddate) ? date('Y-m-d', $formdata->outcomecommunicateddate) : '';
echo html_writer::empty_tag('input', [
    'type' => 'date', 'name' => 'outcomecommunicateddate', 'id' => 'outcomecommunicateddate',
    'value' => $occdateVal, 'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Method', ['for' => 'outcomecommunicatedmethod', 'class' => 'form-label']);
$occMethods = [
    ''          => '-- Select --',
    'email'     => 'Email',
    'letter'    => 'Letter',
    'interview' => 'Interview / meeting',
    'portal'    => 'Student portal',
    'phone'     => 'Phone',
];
echo html_writer::select($occMethods, 'outcomecommunicatedmethod', $formdata->outcomecommunicatedmethod ?? '', false,
    ['id' => 'outcomecommunicatedmethod', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 24px; display: flex; gap: 12px; align-items: center;']);
echo html_writer::tag('button', $id ? 'Update Record' : 'Save Record', [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::link(
    new moodle_url('/local/rtocompliance/rpl.php', ['tab' => $tab]),
    'Cancel',
    ['class' => 'btn btn-secondary']
);
if ($id) {
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/rpl_edit.php', ['id' => $id, 'action' => 'delete', 'sesskey' => sesskey()]),
        'Delete Record',
        ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete this record permanently?")']
    );
}
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo html_writer::end_div();

echo html_writer::tag('script', '
document.addEventListener("DOMContentLoaded", function () {
    var typeSelect = document.getElementById("rpltype");
    var creditSection = document.getElementById("credit-transfer-section");
    function toggleCreditSection() {
        if (!typeSelect || !creditSection) return;
        if (typeSelect.value === "credit_transfer") {
            creditSection.style.display = "";
        } else {
            creditSection.style.display = "none";
        }
    }
    if (typeSelect) {
        typeSelect.addEventListener("change", toggleCreditSection);
        toggleCreditSection();
    }

    // RPL-P1 (v5.9.424): evidence-to-criteria matrix editor. Renders seeded rows,
    // supports add/remove, and serialises to the hidden evidencematrix field.
    var mBody = document.getElementById("rpl-matrix-body");
    var mAdd = document.getElementById("rpl-matrix-add");
    var mHidden = document.getElementById("evidencematrix");
    var mSeedEl = document.getElementById("rpl-matrix-seed");
    if (mBody && mHidden) {
        var judgeOpts = [
            ["", "-- Judgement --"],
            ["satisfactory", "Satisfactory"],
            ["not_satisfactory", "Not satisfactory"],
            ["further_evidence", "Further evidence needed"]
        ];
        function serialise() {
            var rows = [];
            mBody.querySelectorAll("tr").forEach(function (tr) {
                var c = tr.querySelector(".m-crit").value.trim();
                var e = tr.querySelector(".m-evid").value.trim();
                var j = tr.querySelector(".m-judge").value;
                if (c === "" && e === "" && j === "") { return; }
                rows.push({ criterion: c, evidence: e, judgement: j });
            });
            mHidden.value = rows.length ? JSON.stringify(rows) : "";
        }
        function addRow(data) {
            data = data || {};
            var tr = document.createElement("tr");
            var td1 = document.createElement("td");
            var i1 = document.createElement("input");
            i1.type = "text"; i1.className = "form-control form-control-sm m-crit";
            i1.placeholder = "e.g. PC 1.2 / Performance evidence";
            i1.value = data.criterion || "";
            td1.appendChild(i1);
            var td2 = document.createElement("td");
            var i2 = document.createElement("input");
            i2.type = "text"; i2.className = "form-control form-control-sm m-evid";
            i2.placeholder = "e.g. Employer reference dated 12/03; work sample A";
            i2.value = data.evidence || "";
            td2.appendChild(i2);
            var td3 = document.createElement("td");
            var sel = document.createElement("select");
            sel.className = "form-control form-control-sm m-judge";
            judgeOpts.forEach(function (o) {
                var op = document.createElement("option");
                op.value = o[0]; op.textContent = o[1];
                if ((data.judgement || "") === o[0]) { op.selected = true; }
                sel.appendChild(op);
            });
            td3.appendChild(sel);
            var td4 = document.createElement("td");
            var rm = document.createElement("button");
            rm.type = "button"; rm.className = "btn btn-sm btn-link text-danger";
            rm.textContent = "Remove";
            rm.addEventListener("click", function () { tr.remove(); serialise(); });
            td4.appendChild(rm);
            tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
            [i1, i2, sel].forEach(function (el) {
                el.addEventListener("input", serialise);
                el.addEventListener("change", serialise);
            });
            mBody.appendChild(tr);
        }
        var seed = [];
        if (mSeedEl) {
            try { seed = JSON.parse(mSeedEl.textContent || "[]"); } catch (e) { seed = []; }
        }
        if (seed && seed.length) {
            seed.forEach(function (r) { addRow(r); });
        } else {
            addRow({});
        }
        if (mAdd) { mAdd.addEventListener("click", function () { addRow({}); }); }
        var mForm = mHidden.closest("form");
        if (mForm) { mForm.addEventListener("submit", serialise); }
        serialise();
    }
});
');

echo $OUTPUT->footer();
