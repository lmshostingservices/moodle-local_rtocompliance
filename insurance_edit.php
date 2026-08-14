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
 * RTO Compliance plugin — insurance_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\insurance_form;

admin_externalpage_setup('local_rtocompliance_insurance');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/insurance_edit.php', ['id' => $id]));

$insurance = null;
if ($id) {
    $insurance = $DB->get_record('local_rtocompliance_insurance', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Insurance Policy');
    $PAGE->navbar->add(get_string('insurance', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/insurance.php'));
    $PAGE->navbar->add('Edit Policy');
} else {
    $PAGE->set_title('New Insurance Policy');
    $PAGE->navbar->add(get_string('insurance', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/insurance.php'));
    $PAGE->navbar->add('New Policy');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_insurance', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/insurance.php'),
        get_string('insurance_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new insurance_form(null, ['insurance' => $insurance]);

if ($insurance) {
    $form->set_data($insurance);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/insurance.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->insurancetype = $data->insurancetype;
    $record->provider = $data->provider;
    $record->policynumber = $data->policynumber;
    $record->coverageamount = $data->coverageamount ?? 0;
    $record->premium = $data->premium ?? 0;
    $record->excessamount = $data->excessamount ?? 0;
    $record->coveragedetails = $data->coveragedetails ?? '';
    $record->exclusions = $data->exclusions ?? '';
    $record->qualificationscovered = $data->qualificationscovered ?? '';
    $record->deliverymodes = $data->deliverymodes ?? '';
    $record->locations = $data->locations ?? '';
    $record->startdate = $data->startdate;
    $record->expirydate = $data->expirydate;
    $record->renewalreminderdays = $data->renewalreminderdays ?? 30;
    $record->status = $data->status;
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_insurance', $record);
        $message = get_string('insurance_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_insurance', $record);
        $message = get_string('insurance_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/insurance.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Policy' : 'New Policy', get_string('insurance', 'local_rtocompliance'), '/local/rtocompliance/insurance.php', 'insurance');
echo local_rtocompliance_page_banner($id ? 'Edit Policy' : 'New Policy');
echo $OUTPUT->heading($id ? 'Edit Insurance Policy' : 'New Insurance Policy');

$form->display();

echo $OUTPUT->footer();
