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
 * RTO Compliance plugin — validator_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\validator_form;

admin_externalpage_setup('local_rtocompliance_validation');
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/validator_edit.php', ['id' => $id]));

$validator = null;
if ($id) {
    $validator = $DB->get_record('local_rtocompliance_validators', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Validator');
    $PAGE->navbar->add(get_string('validation', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']));
    $PAGE->navbar->add('Edit Validator');
} else {
    $PAGE->set_title('New Validator');
    $PAGE->navbar->add(get_string('validation', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']));
    $PAGE->navbar->add('New Validator');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_validators', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']),
        get_string('validator_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new validator_form(null, ['validator' => $validator]);

if ($validator) {
    $form->set_data($validator);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->fullname = $data->fullname;
    $record->email = $data->email ?? '';
    $record->phone = $data->phone ?? '';
    $record->isinternal = $data->isinternal;
    $record->organisation = $data->isinternal ? '' : ($data->organisation ?? '');
    $record->roletype = $data->roletype;
    $record->taecredential = $data->taecredential ?? '';
    $record->taedateachieved = $data->taedateachieved ?? null;
    $record->vocationalqualifications = $data->vocationalqualifications ?? '';
    $record->industryexperience = $data->industryexperience ?? '';
    $record->industryexperienceyears = $data->industryexperienceyears ?? 0;
    $record->currentindustryengagement = $data->currentindustryengagement ?? '';
    $record->specialisations = $data->specialisations ?? '';
    $record->validationsled = $data->validationsled ?? 0;
    $record->validationsparticipated = $data->validationsparticipated ?? 0;
    $record->lastvalidationdate = $data->lastvalidationdate ?? null;
    $record->status = $data->status;
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_validators', $record);
        $message = get_string('validator_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_validators', $record);
        $message = get_string('validator_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/validation.php', ['tab' => 'validators']),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Validator' : 'New Validator', get_string('validation', 'local_rtocompliance'), '/local/rtocompliance/validation.php', 'validation');
echo local_rtocompliance_page_banner($id ? 'Edit Validator' : 'New Validator');

$form->display();

echo $OUTPUT->footer();
