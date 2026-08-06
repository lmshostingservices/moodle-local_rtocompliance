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
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_locations');
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_url('/local/rtocompliance/location_edit.php', ['id' => $id]);

$location = null;
if ($id) {
    $location = $DB->get_record('local_rtocompliance_locations', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_location', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('edit_location', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('add_location', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('add_location', 'local_rtocompliance'));
}

class location_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'identifierheader', get_string('location_identifier', 'local_rtocompliance'));

        $mform->addElement('text', 'locationid', get_string('location_id', 'local_rtocompliance'), ['size' => 10, 'maxlength' => 10]);
        $mform->setType('locationid', PARAM_ALPHANUMEXT);
        $mform->addRule('locationid', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('locationid', 'location_id', 'local_rtocompliance');

        $mform->addElement('text', 'locationname', get_string('location_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 100]);
        $mform->setType('locationname', PARAM_TEXT);
        $mform->addRule('locationname', get_string('required'), 'required', null, 'client');

        $mform->addElement('header', 'addressheader', get_string('location_address', 'local_rtocompliance'));

        $mform->addElement('text', 'buildingname', get_string('buildingname', 'local_rtocompliance'), ['size' => 50, 'maxlength' => 50]);
        $mform->setType('buildingname', PARAM_TEXT);

        $mform->addElement('text', 'streetno', get_string('streetno', 'local_rtocompliance'), ['size' => 10, 'maxlength' => 15]);
        $mform->setType('streetno', PARAM_TEXT);

        $mform->addElement('text', 'streetname', get_string('streetname', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 70]);
        $mform->setType('streetname', PARAM_TEXT);

        $mform->addElement('text', 'suburb', get_string('suburb', 'local_rtocompliance'), ['size' => 40, 'maxlength' => 50]);
        $mform->setType('suburb', PARAM_TEXT);

        $mform->addElement('text', 'postcode', get_string('postcode', 'local_rtocompliance'), ['size' => 6, 'maxlength' => 4]);
        // FIX-POSTCODE-PARAM (v5.9.276): PARAM_INT stripped leading zeros, so
        // NT postcodes like 0800 (Darwin) were saved as 800 — producing an
        // invalid AVETMISS NAT85 postcode field.  PARAM_ALPHANUM preserves the
        // leading zero; the preg_match /^\d{4}$/ in validation() still enforces
        // the 4-digit format on submit.
        $mform->setType('postcode', PARAM_ALPHANUM);

        $states = [
            ''   => get_string('choosedots'),
            '01' => 'NSW - New South Wales',
            '02' => 'VIC - Victoria',
            '03' => 'QLD - Queensland',
            '04' => 'SA - South Australia',
            '05' => 'WA - Western Australia',
            '06' => 'TAS - Tasmania',
            '07' => 'NT - Northern Territory',
            '08' => 'ACT - Australian Capital Territory',
            '99' => 'Other / Overseas',
        ];
        $mform->addElement('select', 'statecode', get_string('state', 'local_rtocompliance'), $states);

        $mform->addElement('header', 'contactheader', get_string('location_contact', 'local_rtocompliance'));

        $mform->addElement('text', 'phone', get_string('phone', 'local_rtocompliance'), ['size' => 20, 'maxlength' => 20]);
        $mform->setType('phone', PARAM_TEXT);

        $mform->addElement('text', 'email', get_string('email'), ['size' => 50, 'maxlength' => 100]);
        $mform->setType('email', PARAM_EMAIL);

        $mform->addElement('header', 'statusheader', get_string('status'));

        $statuses = [
            'active'   => get_string('active', 'local_rtocompliance'),
            'inactive' => get_string('inactive', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'status', get_string('status'), $statuses);
        $mform->setDefault('status', 'active');

        // ── ASQA Rule 9B ──────────────────────────────────────────────────────
        $mform->addElement('header', 'rule9bheader', get_string('rule9b_header', 'local_rtocompliance'));
        $mform->addElement('advcheckbox', 'rule9b_approved',
            get_string('rule9b_approved', 'local_rtocompliance'),
            get_string('rule9b_approved', 'local_rtocompliance'),
            [],
            [0, 1]
        );
        $mform->addHelpButton('rule9b_approved', 'rule9b_approved', 'local_rtocompliance');
        $mform->setDefault('rule9b_approved', 0);

        // 9B certificate upload
        $mform->addElement('filemanager', 'certificate9b_filemanager',
            get_string('certificate9b_upload', 'local_rtocompliance'),
            null,
            ['subdirs' => 0, 'maxfiles' => 3, 'accepted_types' => ['.pdf', '.jpg', '.jpeg', '.png']]
        );
        $mform->addHelpButton('certificate9b_filemanager', 'certificate9b_upload', 'local_rtocompliance');

        $this->add_action_buttons(true, $location = $this->_customdata['location'] ?? null
            ? get_string('savechanges') : get_string('add'));
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        $locationid = strtoupper(trim($data['locationid']));
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $locationid)) {
            $errors['locationid'] = get_string('error_location_id_format', 'local_rtocompliance');
        } else {
            $sql = "SELECT id FROM {local_rtocompliance_locations} WHERE locationid = :locationid AND id != :id";
            if ($DB->record_exists_sql($sql, ['locationid' => $locationid, 'id' => (int)$data['id']])) {
                $errors['locationid'] = get_string('error_location_id_duplicate', 'local_rtocompliance');
            }
        }

        if (!empty($data['postcode']) && !preg_match('/^\d{4}$/', $data['postcode'])) {
            $errors['postcode'] = get_string('error_postcode_format', 'local_rtocompliance');
        }

        return $errors;
    }
}

$form = new location_form(null, ['location' => $location]);

$syscontext = context_system::instance();

if ($location) {
    // Prepare the 9B certificate draft file area so existing files show in filemanager.
    $draftitemid = file_get_submitted_draft_itemid('certificate9b_filemanager');
    file_prepare_draft_area($draftitemid, $syscontext->id, 'local_rtocompliance', 'certificate9b',
        $location->id, ['subdirs' => 0, 'maxfiles' => 3]);
    $location->certificate9b_filemanager = $draftitemid;
    $form->set_data($location);
} else {
    $draftitemid = file_get_submitted_draft_itemid('certificate9b_filemanager');
    file_prepare_draft_area($draftitemid, $syscontext->id, 'local_rtocompliance', 'certificate9b',
        0, ['subdirs' => 0, 'maxfiles' => 3]);
    $form->set_data(['id' => 0, 'certificate9b_filemanager' => $draftitemid]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/locations.php'));
} elseif ($data = $form->get_data()) {
    $now = time();

    $record = new stdClass();
    $record->locationid   = strtoupper(trim($data->locationid));
    $record->locationname = trim($data->locationname);
    $record->buildingname = trim($data->buildingname ?? '');
    $record->streetno     = trim($data->streetno ?? '');
    $record->streetname   = trim($data->streetname ?? '');
    $record->suburb       = trim($data->suburb ?? '');
    $record->postcode     = trim($data->postcode ?? '');
    $record->statecode    = $data->statecode ?? '';
    $record->country      = '1101';
    $record->phone        = trim($data->phone ?? '');
    $record->email        = trim($data->email ?? '');
    $record->status          = $data->status;
    $record->rule9b_approved = !empty($data->rule9b_approved) ? 1 : 0;
    $record->timemodified    = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_locations', $record);
        audit_logger::log_update('location', $record->id, 'Delivery location updated: ' . $record->locationname, null, [
            'locationid' => $record->locationid, 'name' => $record->locationname,
        ]);
        $message = get_string('location_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('local_rtocompliance_locations', $record);
        audit_logger::log_create('location', $record->id, 'Delivery location created: ' . $record->locationname, [
            'locationid' => $record->locationid, 'name' => $record->locationname,
        ]);
        $message = get_string('location_created', 'local_rtocompliance');
    }

    // Save the 9B certificate file(s) from the draft area into the plugin file area.
    if (!empty($data->certificate9b_filemanager)) {
        file_save_draft_area_files($data->certificate9b_filemanager, $syscontext->id,
            'local_rtocompliance', 'certificate9b', $record->id,
            ['subdirs' => 0, 'maxfiles' => 3]);
    }

    redirect(
        new moodle_url('/local/rtocompliance/locations.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    $id ? get_string('edit_location', 'local_rtocompliance') : get_string('add_location', 'local_rtocompliance'),
    get_string('delivery_locations', 'local_rtocompliance'),
    '/local/rtocompliance/locations.php'
);

echo html_writer::start_div('', ['style' => 'max-width:700px;margin:0 auto;padding:20px;']);

$form->display();

echo html_writer::end_div();
echo $OUTPUT->footer();
