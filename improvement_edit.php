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
 * RTO Compliance plugin — improvement_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\form\improvement_form;
use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_complaints');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$sourcetype = optional_param('sourcetype', '', PARAM_ALPHA);
$sourceid = optional_param('sourceid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/improvement_edit.php', ['id' => $id]));

$improvement = null;
if ($id) {
    $improvement = $DB->get_record('local_rtocompliance_improvements', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_improvement', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('continuous_improvement', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('edit_improvement', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('new_improvement', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('continuous_improvement', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('new_improvement', 'local_rtocompliance'));
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_improvements', ['id' => $id]);
    // ASQA compliance-evidence: record deletion of the continuous-improvement register entry.
    try {
        if (class_exists('\\local_rtocompliance\\audit_logger')) {
            audit_logger::log_delete(
                'improvement',
                $id,
                'Improvement #' . $id . ' deleted' . (!empty($improvement->title) ? ': ' . $improvement->title : ''),
                $improvement ? (array) $improvement : null
            );
        }
    } catch (\Throwable $e) {
        debugging('Improvement audit log (delete) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        get_string('improvement_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new improvement_form(null, ['improvement' => $improvement]);

if ($improvement) {
    $formdata = clone $improvement;
    $form->set_data($formdata);
} else if ($sourcetype && $sourceid) {
    $formdata = new stdClass();
    $formdata->sourcetype = $sourcetype;
    $formdata->sourceid = $sourceid;
    $formdata->dateidentified = time();
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/complaints.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->title = $data->title;
    $record->description = $data->description;
    $record->sourcetype = $data->sourcetype;
    // Set sourceid based on linked selection
    if ($data->sourcetype === 'complaint' && !empty($data->linkedcomplaintid)) {
        $record->sourceid = $data->linkedcomplaintid;
    } elseif ($data->sourcetype === 'validation' && !empty($data->linkedvalidationid)) {
        $record->sourceid = $data->linkedvalidationid;
    } else {
        $record->sourceid = $data->sourceid ?? null;
    }
    $record->category = $data->category;
    $record->priority = $data->priority;
    $record->status = $data->status;
    $record->dateidentified = $data->dateidentified;
    $record->targetdate = $data->targetdate ?? null;
    $record->completiondate = $data->completiondate ?? null;
    $record->actionplan = $data->actionplan ?? '';
    $record->outcome = $data->outcome ?? '';
    $record->effectivenessverified = $data->effectivenessverified;
    $record->verificationdate = $data->effectivenessverified ? ($data->verificationdate ?? null) : null;
    $record->verificationmethod = $data->effectivenessverified ? ($data->verificationmethod ?? '') : '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_improvements', $record);
        // ASQA compliance-evidence: record update of the continuous-improvement register entry.
        try {
            if (class_exists('\\local_rtocompliance\\audit_logger')) {
                audit_logger::log_update(
                    'improvement',
                    $record->id,
                    'Improvement #' . $record->id . ' updated: ' . $record->title . ' (' . $record->status . ')',
                    null,
                    ['reference' => $record->reference, 'title' => $record->title, 'status' => $record->status]
                );
            }
        } catch (\Throwable $e) {
            debugging('Improvement audit log (update) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $message = get_string('improvement_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $record->id = $DB->insert_record('local_rtocompliance_improvements', $record);
        // ASQA compliance-evidence: record creation of the continuous-improvement register entry.
        try {
            if (class_exists('\\local_rtocompliance\\audit_logger')) {
                audit_logger::log_create(
                    'improvement',
                    $record->id,
                    'Improvement #' . $record->id . ' created: ' . $record->title . ' (' . $record->status . ')',
                    ['reference' => $record->reference, 'title' => $record->title, 'status' => $record->status]
                );
            }
        } catch (\Throwable $e) {
            debugging('Improvement audit log (create) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $message = get_string('improvement_created', 'local_rtocompliance');
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
echo local_rtocompliance_render_nav_header($id ? get_string('edit_improvement', 'local_rtocompliance') : get_string('new_improvement', 'local_rtocompliance'), get_string('complaints_appeals', 'local_rtocompliance'), '/local/rtocompliance/complaints.php', 'complaints');
echo local_rtocompliance_page_banner($id ? get_string('edit_improvement', 'local_rtocompliance') : get_string('new_improvement', 'local_rtocompliance'));

$form->display();

echo $OUTPUT->footer();
