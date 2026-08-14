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
 * RTO Compliance plugin — appeal_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/audit_logger.php');

use local_rtocompliance\form\appeal_form;
use local_rtocompliance\audit_logger;

admin_externalpage_setup('local_rtocompliance_complaints');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/appeal_edit.php', ['id' => $id]));

$appeal = null;
if ($id) {
    $appeal = $DB->get_record('local_rtocompliance_appeals', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title(get_string('edit_appeal', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('edit_appeal', 'local_rtocompliance'));
} else {
    $PAGE->set_title(get_string('new_appeal', 'local_rtocompliance'));
    $PAGE->navbar->add(get_string('complaints', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/complaints.php'));
    $PAGE->navbar->add(get_string('new_appeal', 'local_rtocompliance'));
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_appeals', ['id' => $id]);
    // ASQA compliance-evidence: record deletion of the appeals register entry.
    try {
        if (class_exists('\\local_rtocompliance\\audit_logger')) {
            audit_logger::log_delete(
                'appeal',
                $id,
                'Appeal #' . $id . ' deleted' . (!empty($appeal->reference) ? ': ' . $appeal->reference : ''),
                $appeal ? (array) $appeal : null
            );
        }
    } catch (\Throwable $e) {
        debugging('Appeal audit log (delete) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    redirect(
        new moodle_url('/local/rtocompliance/complaints.php'),
        get_string('appeal_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new appeal_form(null, ['appeal' => $appeal]);

if ($appeal) {
    $formdata = clone $appeal;
    $form->set_data($formdata);

    // Standard 2.8 advisory checks (non-blocking reminders — no results are auto-changed).
    // Outcome value 'upheld' (and 'partially_upheld') indicate the appeal was upheld;
    // appealtype 'assessment_decision' indicates an assessment-decision appeal.
    $upheld = in_array($appeal->outcome ?? '', ['upheld', 'partially_upheld'], true);
    if ($upheld && ($appeal->appealtype ?? '') === 'assessment_decision' && empty($appeal->resultcorrected)) {
        \core\notification::add(
            'This assessment appeal was upheld. Please correct the underlying assessment record and tick "Underlying record corrected".',
            \core\output\notification::NOTIFY_WARNING
        );
    }
    if (!empty($appeal->outcome) && empty($appeal->independenceconfirmed)) {
        \core\notification::add(
            'An outcome has been recorded but reviewer/panel independence has not been confirmed. Independence must be confirmed for procedural fairness.',
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/complaints.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->reference = $data->reference;
    $record->complaintid = !empty($data->complaintid) ? $data->complaintid : null;
    $record->appealtype = $data->appealtype;
    $record->appellantname = $data->appellantname;
    $record->appellantemail = $data->appellantemail ?? '';
    $record->appellantphone = $data->appellantphone ?? '';
    // Audit P2-9: populate appellantuserid so the privacy provider can export/erase
    // this record against the person's Moodle account. Resolve from the email when the
    // id is not already set.
    $record->appellantuserid = (!empty($data->appellantuserid)) ? (int)$data->appellantuserid : null;
    if (empty($record->appellantuserid) && !empty($record->appellantemail)) {
        $appellantuser = $DB->get_record('user',
            ['email' => $record->appellantemail, 'deleted' => 0], 'id', IGNORE_MULTIPLE);
        if ($appellantuser) {
            $record->appellantuserid = $appellantuser->id;
        }
    }
    $record->groundsforappeal = $data->groundsforappeal;
    $record->originaldecision = $data->originaldecision ?? '';
    $record->originaldecisiondate = $data->originaldecisiondate ?? null;
    // Standard 2.8 independence and record-correction fields.
    $record->originaldecisionmaker = $data->originaldecisionmaker ?? '';
    $record->independenceconfirmed = !empty($data->independenceconfirmed) ? 1 : 0;
    $record->resultcorrected = !empty($data->resultcorrected) ? 1 : 0;
    $record->status = $data->status;
    $record->datelodged = $data->datelodged;
    $record->dateacknowledged = $data->dateacknowledged ?? null;
    $record->hearingdate = $data->hearingdate ?? null;
    $record->panelmembers = $data->panelmembers ?? '';
    $record->outcome = !empty($data->outcome) ? $data->outcome : null;
    $record->outcomereason = $data->outcomereason ?? '';
    $record->decisiondate = $data->decisiondate ?? null;
    $record->externalreviewoffered = $data->externalreviewoffered;
    $record->externalreviewtaken = $data->externalreviewoffered ? ($data->externalreviewtaken ?? 0) : 0;
    $record->externalreviewbody = $data->externalreviewoffered ? ($data->externalreviewbody ?? '') : '';
    $record->notes = $data->notes ?? '';
    $record->timemodified = $now;

    if (!empty($data->id)) {
        $record->id = $data->id;
        $DB->update_record('local_rtocompliance_appeals', $record);
        // ASQA compliance-evidence: record update of the appeals register entry.
        try {
            if (class_exists('\\local_rtocompliance\\audit_logger')) {
                audit_logger::log_update(
                    'appeal',
                    $record->id,
                    'Appeal #' . $record->id . ' updated: ' . $record->reference . ' (' . $record->status . ')',
                    null,
                    ['reference' => $record->reference, 'appealtype' => $record->appealtype, 'status' => $record->status]
                );
            }
        } catch (\Throwable $e) {
            debugging('Appeal audit log (update) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $message = get_string('appeal_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $record->id = $DB->insert_record('local_rtocompliance_appeals', $record);
        // ASQA compliance-evidence: record creation of the appeals register entry.
        try {
            if (class_exists('\\local_rtocompliance\\audit_logger')) {
                audit_logger::log_create(
                    'appeal',
                    $record->id,
                    'Appeal #' . $record->id . ' created: ' . $record->reference . ' (' . $record->status . ')',
                    ['reference' => $record->reference, 'appealtype' => $record->appealtype, 'status' => $record->status]
                );
            }
        } catch (\Throwable $e) {
            debugging('Appeal audit log (create) failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $message = get_string('appeal_created', 'local_rtocompliance');
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
echo local_rtocompliance_render_nav_header($id ? get_string('edit_appeal', 'local_rtocompliance') : get_string('new_appeal', 'local_rtocompliance'), get_string('complaints_appeals', 'local_rtocompliance'), '/local/rtocompliance/complaints.php', 'complaints');
echo local_rtocompliance_page_banner($id ? get_string('edit_appeal', 'local_rtocompliance') : get_string('new_appeal', 'local_rtocompliance'));

$form->display();

echo $OUTPUT->footer();
