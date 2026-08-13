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

$id = optional_param('id', 0, PARAM_INT);
$qualbuilderid = required_param('qualbuilderid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

admin_externalpage_setup('local_rtocompliance_qualbuilder');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$context = context_system::instance();

$product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualbuilderid], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/local/rtocompliance/qualbuilder_unit.php', ['id' => $id, 'qualbuilderid' => $qualbuilderid]));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add($product->qualificationcode, new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid]));


if ($action === 'delete' && $id) {
    $unit = $DB->get_record('local_rtocompliance_qualunits', ['id' => $id], '*', MUST_EXIST);
    
    if ($confirm) {
        // Bug P fix: validate sesskey before any destructive DB operation.
        // Without this, an attacker can forge a GET request with confirm=1 to delete
        // any qual unit by tricking an authenticated admin into visiting a crafted URL.
        require_sesskey();
        $DB->delete_records('local_rtocompliance_qualunits', ['id' => $id]);
        
        audit_logger::log_delete(
            'qualunit',
            $id,
            'Unit deleted from product: ' . $unit->unitcode,
            ['unit_code' => $unit->unitcode, 'product_id' => $qualbuilderid]
        );
        
        $DB->set_field('local_rtocompliance_qualbuilder', 'validationpassed', 0, ['id' => $qualbuilderid]);
        
        redirect(
            new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid]),
            get_string('unit_deleted', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $PAGE->add_body_class("path-local-rtocompliance");
        $PAGE->requires->css('/local/rtocompliance/styles.css');
        echo $OUTPUT->header();
        echo local_rtocompliance_render_nav_header(
            get_string('edit_unit', 'local_rtocompliance'),
            get_string('qualificationbuilder', 'local_rtocompliance'),
            '/local/rtocompliance/qualbuilder.php',
            'qualbuilder'
        );
        echo $OUTPUT->confirm(
            get_string('confirm_delete_unit', 'local_rtocompliance', $unit->unitcode . ' - ' . $unit->unitname),
            new moodle_url('/local/rtocompliance/qualbuilder_unit.php', ['action' => 'delete', 'id' => $id, 'qualbuilderid' => $qualbuilderid, 'confirm' => 1]),
            new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid])
        );
        echo $OUTPUT->footer();
        return;
    }
}

$unit = null;
if ($id) {
    $unit = $DB->get_record('local_rtocompliance_qualunits', ['id' => $id, 'qualbuilderid' => $qualbuilderid], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_unit', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('edit_unit', 'local_rtocompliance') . ': ' . $unit->unitcode);
    $PAGE->navbar->add(get_string('edit_unit', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('add_unit', 'local_rtocompliance'));
    $PAGE->set_heading(get_string('add_unit', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('add_unit', 'local_rtocompliance'));
}

class qualunit_form extends moodleform {
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $qualbuilderid = $this->_customdata['qualbuilderid'];
        $product = $this->_customdata['product'];
        
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        
        $mform->addElement('hidden', 'qualbuilderid');
        $mform->setType('qualbuilderid', PARAM_INT);
        
        $mform->addElement('header', 'general', get_string('unit_details', 'local_rtocompliance'));
        
        $mform->addElement('text', 'unitcode', get_string('unit_code', 'local_rtocompliance'), ['size' => 12, 'maxlength' => 12]);
        $mform->setType('unitcode', PARAM_ALPHANUMEXT);
        $mform->addRule('unitcode', null, 'required', null, 'client');
        $mform->addHelpButton('unitcode', 'unit_code', 'local_rtocompliance');
        
        $mform->addElement('text', 'unitname', get_string('unit_name', 'local_rtocompliance'), ['size' => 60, 'maxlength' => 150]);
        $mform->setType('unitname', PARAM_TEXT);
        $mform->addRule('unitname', null, 'required', null, 'client');
        
        $unittypes = [
            'core' => get_string('unit_type_core', 'local_rtocompliance'),
            'elective' => get_string('unit_type_elective', 'local_rtocompliance'),
            'imported' => get_string('unit_type_imported', 'local_rtocompliance'),
        ];
        $mform->addElement('select', 'unittype', get_string('unit_type', 'local_rtocompliance'), $unittypes);
        $mform->setDefault('unittype', $product->producttype === 'singleunit' ? 'core' : 'elective');
        
        $groups = ['' => get_string('none'), 'A' => 'Group A', 'B' => 'Group B', 'C' => 'Group C', 'D' => 'Group D'];
        $mform->addElement('select', 'electivegroup', get_string('elective_group', 'local_rtocompliance'), $groups);
        $mform->hideIf('electivegroup', 'unittype', 'eq', 'core');
        $mform->addHelpButton('electivegroup', 'elective_group', 'local_rtocompliance');
        
        $mform->addElement('text', 'nominalhours', get_string('nominal_hours', 'local_rtocompliance'), ['size' => 6]);
        $mform->setType('nominalhours', PARAM_INT);

        $mform->addElement('text', 'creditpoints', get_string('credit_points', 'local_rtocompliance'), ['size' => 6]);
        $mform->setType('creditpoints', PARAM_INT);
        $mform->setDefault('creditpoints', 0);
        $mform->addHelpButton('creditpoints', 'credit_points', 'local_rtocompliance');

        $mform->addElement('text', 'sequenceorder', get_string('sequence_order', 'local_rtocompliance'), ['size' => 4]);
        $mform->setType('sequenceorder', PARAM_INT);
        $mform->setDefault('sequenceorder', 0);
        
        $mform->addElement('header', 'linkingheader', get_string('course_linking', 'local_rtocompliance'));
        
        if ($product->categoryid) {
            $courses = $DB->get_records_sql(
                "SELECT c.id, c.shortname, c.fullname 
                 FROM {course} c 
                 WHERE c.category = :categoryid 
                 ORDER BY c.sortorder ASC",
                ['categoryid' => $product->categoryid]
            );
        } else {
            $courses = $DB->get_records_sql(
                "SELECT c.id, c.shortname, c.fullname 
                 FROM {course} c 
                 WHERE c.id > 1 
                 ORDER BY c.fullname ASC 
                 LIMIT 500"
            );
        }
        
        $courseoptions = ['' => get_string('select_course', 'local_rtocompliance')];
        foreach ($courses as $course) {
            $courseoptions[$course->id] = $course->shortname . ' - ' . $course->fullname;
        }
        $mform->addElement('select', 'courseid', get_string('linked_course', 'local_rtocompliance'), $courseoptions);
        $mform->addHelpButton('courseid', 'linked_course', 'local_rtocompliance');
        
        $this->add_action_buttons(true);
    }
}

$form = new qualunit_form(null, ['qualbuilderid' => $qualbuilderid, 'product' => $product]);

if ($unit) {
    $form->set_data($unit);
} else {
    $form->set_data(['id' => 0, 'qualbuilderid' => $qualbuilderid]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid]));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->qualbuilderid = $qualbuilderid;
    $record->unitcode = strtoupper(trim($data->unitcode));
    $record->unitname = trim($data->unitname);
    $record->unittype = $data->unittype;
    $record->electivegroup = !empty($data->electivegroup) ? $data->electivegroup : null;
    $record->courseid = !empty($data->courseid) ? $data->courseid : null;
    $record->nominalhours = !empty($data->nominalhours) ? $data->nominalhours : null;
    $record->creditpoints = isset($data->creditpoints) ? (int)$data->creditpoints : 0;
    $record->sequenceorder = $data->sequenceorder ?? 0;
    $record->selected = 1;
    $record->status = 'active';
    $record->timemodified = $now;
    
    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_qualunits', $record);
        
        audit_logger::log_update(
            'qualunit',
            $record->id,
            'Unit updated: ' . $record->unitcode,
            null,
            ['unit_code' => $record->unitcode, 'unit_type' => $record->unittype]
        );
        
        $message = get_string('unit_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('local_rtocompliance_qualunits', $record);
        
        audit_logger::log_create(
            'qualunit',
            $record->id,
            'Unit added: ' . $record->unitcode,
            ['unit_code' => $record->unitcode, 'product_id' => $qualbuilderid]
        );
        
        $message = get_string('unit_added', 'local_rtocompliance');
    }
    
    $DB->set_field('local_rtocompliance_qualbuilder', 'validationpassed', 0, ['id' => $qualbuilderid]);
    $DB->set_field('local_rtocompliance_qualbuilder', 'timemodified', $now, ['id' => $qualbuilderid]);
    
    redirect(
        new moodle_url('/local/rtocompliance/qualbuilder_edit.php', ['id' => $qualbuilderid]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");

// Auto-fill nominal hours from NCVER when the unit code is entered.
$apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
$PAGE->requires->js_call_amd('local_rtocompliance/nominalhours_autofill', 'init', [
    'id_unitcode', 'id_unitname', 'id_nominalhours', $apiurl,
]);

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? get_string('edit_unit', 'local_rtocompliance') : get_string('add_unit', 'local_rtocompliance'), get_string('qualificationbuilder', 'local_rtocompliance'), '/local/rtocompliance/qualbuilder.php', 'qualbuilder');
$form->display();
echo $OUTPUT->footer();
