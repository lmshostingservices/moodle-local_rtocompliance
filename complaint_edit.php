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

use local_rtocompliance\form\complaint_form;

admin_externalpage_setup('local_rtocompliance_complaints');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/complaint_edit.php', ['id' => $id]));

$complaint = null;
if ($id) {
    $complaint = $DB->get_record('local_rtocompliance_complaints', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_complaint', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('edit_complaint', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('new_complaint', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('new_complaint', 'local_rtocompliance'));
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_complaints', ['id' => $id]);
    $DB->delete_records('local_rtocompliance_appeals', ['complaintid' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        get_string('complaint_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new complaint_form(null, ['complaint' => $complaint]);

if ($complaint) {
    $formdata = clone $complaint;
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/complaints.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->complainanttype = $data->complainanttype;
    $record->complainantname = $data->isanonymous ? '' : ($data->complainantname ?? '');
    $record->complainantemail = $data->isanonymous ? '' : ($data->complainantemail ?? '');
    $record->complainantphone = $data->isanonymous ? '' : ($data->complainantphone ?? '');
    $record->isanonymous = $data->isanonymous;
    $record->category = $data->category;
    $record->subcategory = $data->subcategory ?? '';
    $record->subject = $data->subject;
    $record->description = $data->description;
    $record->priority = $data->priority;
    $record->status = $data->status;
    $record->assignedto = (!empty($data->assignedto)) ? (int)$data->assignedto : null;
    $record->datereceived = $data->datereceived;
    $record->targetresolutiondate = !empty($data->targetresolutiondate) ? $data->targetresolutiondate : null;
    $record->dateacknowledged = !empty($data->dateacknowledged) ? $data->dateacknowledged : null;
    $record->actualresolutiondate = !empty($data->actualresolutiondate) ? $data->actualresolutiondate : null;
    $record->resolution = $data->resolution ?? '';
    $record->outcomesatisfactory = (isset($data->outcomesatisfactory) && $data->outcomesatisfactory !== '') ? (int)$data->outcomesatisfactory : null;
    $record->issystemic = $data->issystemic;
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;
    $record->modifiedby = $USER->id;

    try {
        if (!empty($data->id)) {
            $record->id = $data->id;
            $DB->update_record('local_rtocompliance_complaints', $record);
            $message = get_string('complaint_updated', 'local_rtocompliance');
        } else {
            $record->timecreated = $now;
            $record->createdby = $USER->id;
            $record->id = $DB->insert_record('local_rtocompliance_complaints', $record);
            $message = get_string('complaint_created', 'local_rtocompliance');
        }
    } catch (\dml_exception $e) {
        redirect(
            new moodle_url('/local/rtocompliance/complaint_edit.php', ['id' => $data->id ?? 0]),
            'Could not save complaint: ' . $e->getMessage(),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? get_string('edit_complaint', 'local_rtocompliance') : get_string('new_complaint', 'local_rtocompliance'), get_string('complaints_appeals', 'local_rtocompliance'), '/local/rtocompliance/complaints.php', 'complaints');
echo $OUTPUT->heading($id ? get_string('edit_complaint', 'local_rtocompliance') : get_string('new_complaint', 'local_rtocompliance'));

$form->display();

echo $OUTPUT->footer();
