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
 * RTO Compliance plugin — transition_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\transition_form;

admin_externalpage_setup('local_rtocompliance_transitions');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/transition_edit.php', ['id' => $id]));

$transition = null;
if ($id) {
    $transition = $DB->get_record('local_rtocompliance_transitions', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Product Transition');
    $PAGE->navbar->add(get_string('transitions', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/transitions.php'));
    $PAGE->navbar->add('Edit Transition');
} else {
    $PAGE->set_title('New Product Transition');
    $PAGE->navbar->add(get_string('transitions', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/transitions.php'));
    $PAGE->navbar->add('New Transition');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_transitions', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/transitions.php'),
        get_string('transition_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new transition_form(null, ['transition' => $transition]);

if ($transition) {
    $form->set_data($transition);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/transitions.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->oldproductcode = $data->oldproductcode;
    $record->oldproductname = $data->oldproductname;
    $record->transitiontype = $data->transitiontype;
    $record->newproductcode = $data->newproductcode ?? '';
    $record->newproductname = $data->newproductname ?? '';
    $record->tganotificationdate = $data->tganotificationdate;
    $record->teachoutdeadline = $data->teachoutdeadline;
    $record->studentsaffected = $data->studentsaffected ?? 0;
    $record->studentscontacted = $data->studentscontacted ?? 0;
    $record->transitionplan = $data->transitionplan ?? '';
    $record->mappingdocument = $data->mappingdocument ?? '';
    $record->scopeupdated = $data->scopeupdated;
    $record->linkedcourseid = !empty($data->linkedcourseid) ? (int)$data->linkedcourseid : null;
    $record->enrolmentsclosed = (int)$data->enrolmentsclosed;
    $record->status = $data->status;
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_transitions', $record);
        $message = get_string('transition_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_transitions', $record);
        $message = get_string('transition_created', 'local_rtocompliance');
    }

    // -----------------------------------------------------------------------
    // Moodle enrolment control — when a course is linked, automatically
    // disable/enable self-enrolment to match the enrolmentsclosed flag.
    // This ensures superseded products cannot accept new student self-enrolments.
    // -----------------------------------------------------------------------
    if (!empty($record->linkedcourseid)) {
        $linkedcourseid = (int)$record->linkedcourseid;
        $targetstatus = $record->enrolmentsclosed ? 1 : 0; // 1=disabled, 0=enabled in {enrol}.status
        $selfenrols = $DB->get_records('enrol', ['courseid' => $linkedcourseid, 'enrol' => 'self']);
        if ($selfenrols) {
            foreach ($selfenrols as $enrolinstance) {
                $DB->set_field('enrol', 'status', $targetstatus, ['id' => $enrolinstance->id]);
            }
            // BUG-16 FIX: Invalidate the enrolment instances cache after directly writing
            // to the {enrol} table via set_field. Without this, Moodle continues serving
            // the stale cached enrolment status until the next scheduled cache rebuild,
            // meaning newly enrolled students can still access a "closed" course.
            // The core/enrolinstances cache definition is not available on all Moodle
            // versions (coding_exception on Moodle < 4.x); wrap in try/catch so the
            // save always completes — the DB update above is the authoritative change.
            try {
                \cache::make('core', 'enrolinstances')->delete($linkedcourseid);
            } catch (\coding_exception $e) {
                // Cache definition absent on this Moodle version — not fatal.
                // The {enrol}.status write above is the source of truth.
            }
            $message .= ' ' . get_string($record->enrolmentsclosed
                ? 'transition_moodle_enrol_closed'
                : 'transition_moodle_enrol_opened', 'local_rtocompliance');
        } else {
            if ($record->enrolmentsclosed) {
                // Warn only when trying to close — if opening there's nothing to re-enable.
                $message .= ' ' . get_string('transition_moodle_no_enrol', 'local_rtocompliance');
            }
        }
    }

    redirect(
        new moodle_url('/local/rtocompliance/transitions.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
$PAGE->requires->js('/local/rtocompliance/js/ai_suggest.js', false);
$PAGE->requires->js('/local/rtocompliance/js/transition_ai.js', false);
echo $OUTPUT->header();

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

echo local_rtocompliance_render_nav_header($id ? 'Edit Transition' : 'New Transition', get_string('transitions', 'local_rtocompliance'), '/local/rtocompliance/transitions.php');
echo local_rtocompliance_page_banner($id ? 'Edit Transition' : 'New Transition');
echo $OUTPUT->heading($id ? 'Edit Product Transition' : 'New Product Transition');

$form->display();

echo $OUTPUT->footer();
