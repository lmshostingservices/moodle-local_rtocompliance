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
 * RTO Compliance plugin — governance_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\governance_form;

admin_externalpage_setup('local_rtocompliance_governancepage');
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$type = optional_param('type', 'persons', PARAM_ALPHA);
if (!in_array($type, ['persons', 'changes', 'adc'], true)) {
    $type = 'persons';
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $id, 'type' => $type]));

// GOVERNANCE-TYPE-ROUTING (v5.9.414): governance.php links to this page with
// type=changes (Material Changes) and type=adc (Annual Declaration of Compliance),
// but the editor previously ignored $type and always read/wrote the govpersons
// table — so those two records could never be created and clicking "Edit" on one
// threw a fatal MUST_EXIST ("can not find data record") because the id belonged to
// a different table. These two branches handle their own tables and then exit,
// leaving the original governing-person flow (type=persons) below untouched.
if ($type === 'changes' || $type === 'adc') {
    $backurl = new moodle_url('/local/rtocompliance/governance.php',
        ['tab' => $type === 'changes' ? 'changes' : 'adc']);
    $table   = $type === 'changes' ? 'local_rtocompliance_materialchanges' : 'local_rtocompliance_adc';
    $record  = $id ? $DB->get_record($table, ['id' => $id], '*', MUST_EXIST) : null;

    // Delete.
    if ($delete && $id && confirm_sesskey()) {
        $DB->delete_records($table, ['id' => $id]);
        if (function_exists('local_rtocompliance_log_action')) {
            local_rtocompliance_log_action('delete', $type === 'changes' ? 'materialchange' : 'adc', $id, []);
        }
        redirect($backurl, 'Record deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Save.
    if (optional_param('savegov', 0, PARAM_INT) && confirm_sesskey()) {
        $now = time();
        $rec = new stdClass();
        if ($type === 'changes') {
            $effective = strtotime(optional_param('effectivedate', '', PARAM_TEXT)) ?: $now;
            $rec->reference             = trim(optional_param('reference', '', PARAM_TEXT)) ?: ('MC-' . date('Y') . '-' . substr((string)$now, -4));
            $rec->changetype            = optional_param('changetype', 'other', PARAM_ALPHA);
            $rec->changedescription     = optional_param('changedescription', '', PARAM_TEXT);
            $rec->effectivedate         = $effective;
            // ASQA notification deadline: 10 business days ≈ 14 calendar days from effective date.
            $rec->notificationdeadline  = $effective + (14 * DAYSECS);
            $rec->asqanotified          = optional_param('asqanotified', 0, PARAM_INT) ? 1 : 0;
            $ndate                      = optional_param('asqanotificationdate', '', PARAM_TEXT);
            $rec->asqanotificationdate  = ($rec->asqanotified && $ndate) ? strtotime($ndate) : null;
            $rec->asqaacknowledged      = optional_param('asqaacknowledged', 0, PARAM_INT) ? 1 : 0;
            $rec->asqareference         = optional_param('asqareference', '', PARAM_TEXT);
            $rec->status                = optional_param('status', 'pending', PARAM_ALPHA);
            $rec->impactassessment      = optional_param('impactassessment', '', PARAM_TEXT);
            $rec->mitigationactions     = optional_param('mitigationactions', '', PARAM_TEXT);
            $rec->evidence              = optional_param('evidence', '', PARAM_TEXT);
            $rec->notes                 = optional_param('notes', '', PARAM_TEXT);
        } else { // adc
            $rec->year                  = optional_param('year', (int) date('Y'), PARAM_INT);
            $rec->duedate               = strtotime(optional_param('duedate', '', PARAM_TEXT)) ?: $now;
            $sdate                      = optional_param('submissiondate', '', PARAM_TEXT);
            $rec->submissiondate        = $sdate ? strtotime($sdate) : null;
            $rec->submittedby           = $rec->submissiondate ? $USER->id : null;
            $rec->declarantname         = optional_param('declarantname', '', PARAM_TEXT);
            $rec->declarantposition     = optional_param('declarantposition', '', PARAM_TEXT);
            $rec->declarationtext       = optional_param('declarationtext', '', PARAM_TEXT);
            $rec->evidencecount         = optional_param('evidencecount', 0, PARAM_INT);
            $rec->status                = optional_param('status', 'due', PARAM_ALPHA);
            $rec->asqaconfirmationref   = optional_param('asqaconfirmationref', '', PARAM_TEXT);
            $rec->notes                 = optional_param('notes', '', PARAM_TEXT);
        }
        $rec->timemodified = $now;
        if ($id > 0) {
            $rec->id = $id;
            $DB->update_record($table, $rec);
            $msg = 'Record updated.';
        } else {
            $rec->timecreated = $now;
            if ($type === 'adc') { $rec->createdby = $USER->id; }
            $DB->insert_record($table, $rec);
            $msg = 'Record saved.';
        }
        if (function_exists('local_rtocompliance_log_action')) {
            local_rtocompliance_log_action($id ? 'update' : 'create',
                $type === 'changes' ? 'materialchange' : 'adc', $id, []);
        }
        redirect($backurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Render.
    $heading = $type === 'changes'
        ? ($id ? 'Edit Material Change' : 'Record Material Change')
        : ($id ? 'Edit Annual Declaration' : 'Start Annual Declaration of Compliance');
    $PAGE->set_title($heading);
    $PAGE->add_body_class('path-local-rtocompliance');
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header($heading, get_string('governance', 'local_rtocompliance'),
        '/local/rtocompliance/governance.php', 'governance');
    echo local_rtocompliance_page_banner($heading);

    $fdate = function ($ts) { return (!empty($ts)) ? date('Y-m-d', (int) $ts) : ''; };
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => (new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $id, 'type' => $type]))->out(false),
        'style' => 'max-width:760px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savegov', 'value' => '1']);

    $text = function ($name, $label, $val, $ph = '', $inputtype = 'text') {
        echo html_writer::start_div('form-group', ['style' => 'margin-bottom:12px;']);
        echo html_writer::tag('label', $label, ['class' => 'form-label', 'style' => 'font-weight:600;']);
        echo html_writer::empty_tag('input', ['type' => $inputtype, 'name' => $name, 'value' => s($val),
            'class' => 'form-control', 'placeholder' => $ph]);
        echo html_writer::end_div();
    };
    $area = function ($name, $label, $val, $ph = '') {
        echo html_writer::start_div('form-group', ['style' => 'margin-bottom:12px;']);
        echo html_writer::tag('label', $label, ['class' => 'form-label', 'style' => 'font-weight:600;']);
        echo html_writer::tag('textarea', s($val), ['name' => $name, 'class' => 'form-control', 'rows' => '3', 'placeholder' => $ph]);
        echo html_writer::end_div();
    };
    $select = function ($name, $label, $opts, $val) {
        echo html_writer::start_div('form-group', ['style' => 'margin-bottom:12px;']);
        echo html_writer::tag('label', $label, ['class' => 'form-label', 'style' => 'font-weight:600;']);
        echo html_writer::select($opts, $name, $val, false, ['class' => 'form-control']);
        echo html_writer::end_div();
    };
    $check = function ($name, $label, $val) {
        echo html_writer::start_div('form-group', ['style' => 'margin-bottom:12px;']);
        echo html_writer::tag('label',
            html_writer::empty_tag('input', array_merge(['type' => 'checkbox', 'name' => $name, 'value' => '1', 'style' => 'margin-right:8px;'],
                $val ? ['checked' => 'checked'] : [])) . $label,
            ['style' => 'font-weight:500;']);
        echo html_writer::end_div();
    };

    if ($type === 'changes') {
        $text('reference', 'Reference', $record->reference ?? '', 'Auto-generated if left blank');
        $select('changetype', 'Change type', [
            'governance' => 'Governance / management', 'ownership' => 'Ownership', 'location' => 'Delivery location',
            'scope' => 'Scope of registration', 'other' => 'Other',
        ], $record->changetype ?? 'governance');
        $area('changedescription', 'Description of the change *', $record->changedescription ?? '');
        $text('effectivedate', 'Effective date', $fdate($record->effectivedate ?? 0), '', 'date');
        echo html_writer::tag('p', 'ASQA must be notified within 10 business days of a material change. The notification deadline is calculated automatically from the effective date.',
            ['class' => 'text-muted', 'style' => 'font-size:0.85rem;']);
        $check('asqanotified', 'ASQA has been notified', !empty($record->asqanotified));
        $text('asqanotificationdate', 'Date ASQA notified', $fdate($record->asqanotificationdate ?? 0), '', 'date');
        $check('asqaacknowledged', 'ASQA has acknowledged', !empty($record->asqaacknowledged));
        $text('asqareference', 'ASQA reference', $record->asqareference ?? '');
        $select('status', 'Status', [
            'pending' => 'Pending', 'notified' => 'Notified', 'acknowledged' => 'Acknowledged',
            'completed' => 'Completed', 'overdue' => 'Overdue',
        ], $record->status ?? 'pending');
        $area('impactassessment', 'Impact assessment', $record->impactassessment ?? '');
        $area('mitigationactions', 'Mitigation actions', $record->mitigationactions ?? '');
        $area('evidence', 'Evidence', $record->evidence ?? '');
        $area('notes', 'Notes', $record->notes ?? '');
    } else { // adc
        $text('year', 'Declaration year', $record->year ?? date('Y'), '', 'number');
        $text('duedate', 'Due date', $fdate($record->duedate ?? 0), '', 'date');
        $text('declarantname', 'Declarant name', $record->declarantname ?? '');
        $text('declarantposition', 'Declarant position', $record->declarantposition ?? '');
        $area('declarationtext', 'Declaration statement', $record->declarationtext ?? '');
        $text('evidencecount', 'Evidence items collected', $record->evidencecount ?? 0, '', 'number');
        $text('submissiondate', 'Submission date (leave blank until submitted)', $fdate($record->submissiondate ?? 0), '', 'date');
        $select('status', 'Status', [
            'due' => 'Due', 'inprogress' => 'In progress', 'submitted' => 'Submitted', 'confirmed' => 'Confirmed',
        ], $record->status ?? 'due');
        $text('asqaconfirmationref', 'ASQA confirmation reference', $record->asqaconfirmationref ?? '');
        $area('notes', 'Notes', $record->notes ?? '');
    }

    echo html_writer::start_div('', ['style' => 'margin-top:16px; display:flex; gap:12px;']);
    echo html_writer::tag('button', $id ? 'Update' : 'Save', ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::link($backurl, 'Cancel', ['class' => 'btn btn-secondary']);
    if ($id) {
        echo html_writer::link(
            new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $id, 'type' => $type, 'delete' => 1, 'sesskey' => sesskey()]),
            'Delete', ['class' => 'btn btn-danger', 'onclick' => 'return confirm("Delete this record permanently?");']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

$person = null;
if ($id) {
    $person = $DB->get_record('local_rtocompliance_govpersons', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Governing Person');
    $PAGE->navbar->add(get_string('governance', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/governance.php'));
    $PAGE->navbar->add('Edit Person');
} else {
    $PAGE->set_title('New Governing Person');
    $PAGE->navbar->add(get_string('governance', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/governance.php'));
    $PAGE->navbar->add('New Person');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_govpersons', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/governance.php'),
        get_string('governance_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new governance_form(null, ['person' => $person]);

if ($person) {
    $form->set_data($person);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/governance.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->fullname = $data->fullname;
    $record->positiontype = $data->positiontype;
    $record->position = $data->positiontitle ?? '';
    $record->email = $data->email ?? '';
    $record->phone = $data->phone ?? '';
    $record->appointmentdate = $data->appointmentdate;
    $record->cessationdate = $data->cessationdate ?? null;
    $record->fitproperdeclared = $data->fitproperdeclared;
    $record->fitproperdeclareddate = $data->fitproperdeclared ? ($data->fitproperdeclareddate ?? null) : null;
    $record->suitabilityassessed = $data->suitabilityassessed;
    $record->suitabilityassesseddate = $data->suitabilityassessed ? ($data->suitabilityassesseddate ?? null) : null;
    // Note: suitabilityevidencefile stored in notes if no dedicated column exists
    if (!empty($data->suitabilityevidencefile)) {
        $evidenceNote = 'Suitability Evidence: ' . $data->suitabilityevidencefile;
        $record->notes = !empty($data->notes) ? ($evidenceNote . "\n" . $data->notes) : $evidenceNote;
    } else {
        $record->notes = $data->notes ?? '';
    }
    $record->policecheckdate = $data->policecheckdate ?? null;
    $record->policecheckstatus = $data->policecheckstatus ?? '';
    $record->status = $data->status;
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_govpersons', $record);
        $message = get_string('governance_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_govpersons', $record);
        $message = get_string('governance_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/governance.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Governing Person' : 'New Governing Person', get_string('governance', 'local_rtocompliance'), '/local/rtocompliance/governance.php', 'governance');
echo local_rtocompliance_page_banner($id ? 'Edit Governing Person' : 'New Governing Person');

$form->display();

echo $OUTPUT->footer();
