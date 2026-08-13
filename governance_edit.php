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

use local_rtocompliance\form\governance_form;

admin_externalpage_setup('local_rtocompliance_governancepage');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/governance_edit.php', ['id' => $id]));

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
echo $OUTPUT->heading($id ? 'Edit Governing Person' : 'New Governing Person');

$form->display();

echo $OUTPUT->footer();
