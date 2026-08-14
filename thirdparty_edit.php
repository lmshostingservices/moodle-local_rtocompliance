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
 * RTO Compliance plugin — thirdparty_edit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\form\thirdparty_form;

admin_externalpage_setup('local_rtocompliance_thirdparty');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();
$context = context_system::instance();

$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/rtocompliance/thirdparty_edit.php', ['id' => $id]));

$arrangement = null;
if ($id) {
    $arrangement = $DB->get_record('local_rtocompliance_thirdparty', ['id' => $id], '*', MUST_EXIST);
    $PAGE->set_title('Edit Third-Party Arrangement');
    $PAGE->navbar->add(get_string('thirdparty', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/thirdparty.php'));
    $PAGE->navbar->add('Edit Arrangement');
} else {
    $PAGE->set_title('New Third-Party Arrangement');
    $PAGE->navbar->add(get_string('thirdparty', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/thirdparty.php'));
    $PAGE->navbar->add('New Arrangement');
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('local_rtocompliance_thirdparty', ['id' => $id]);
    redirect(
        new moodle_url('/local/rtocompliance/thirdparty.php'),
        get_string('thirdparty_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$form = new thirdparty_form(null, ['arrangement' => $arrangement]);

if ($arrangement) {
    $formdata = clone $arrangement;
    // Deserialize extra mandatory clauses from JSON into virtual form fields
    if (!empty($arrangement->mandatoryclausesextra)) {
        $extra = @json_decode($arrangement->mandatoryclausesextra, true);
        if (is_array($extra)) {
            foreach (['priortodelivery', 'cooperateregulator', 'accurateresponses', 'rtocertification', 'partiesnames', 'datesincluded', 'obligations', 'monitorquality'] as $key) {
                $formdata->{'clause_' . $key} = isset($extra[$key]) ? (int)$extra[$key] : 0;
            }
        }
    }
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/rtocompliance/thirdparty.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    
    $record = new stdClass();
    $record->organisationname = $data->organisationname;
    $record->tradingname = $data->tradingname ?? '';
    $record->abn = $data->abn ?? '';
    $record->arrangementtype = $data->arrangementtype;
    $record->contactname = $data->contactname ?? '';
    $record->contactemail = $data->contactemail ?? '';
    $record->contactphone = $data->contactphone ?? '';
    $record->agreementstartdate = $data->agreementstartdate;
    $record->agreementenddate = $data->agreementenddate ?? null;
    $record->qualificationscovered = $data->qualificationscovered ?? '';
    $record->asqanotified = $data->asqanotified;
    $record->asqanotificationdate = $data->asqanotified ? ($data->asqanotificationdate ?? null) : null;
    $record->mandatoryclausesnrtlogo = $data->mandatoryclausesnrtlogo;
    $record->mandatoryclausesaqf = $data->mandatoryclausesaqf;
    $record->mandatoryclausestransparency = $data->mandatoryclausestransparency;
    // Serialize extra mandatory clauses to JSON
    $record->mandatoryclausesextra = json_encode([
        'priortodelivery'    => (int)($data->clause_priortodelivery ?? 0),
        'cooperateregulator' => (int)($data->clause_cooperateregulator ?? 0),
        'accurateresponses'  => (int)($data->clause_accurateresponses ?? 0),
        'rtocertification'   => (int)($data->clause_rtocertification ?? 0),
        'partiesnames'       => (int)($data->clause_partiesnames ?? 0),
        'datesincluded'      => (int)($data->clause_datesincluded ?? 0),
        'obligations'        => (int)($data->clause_obligations ?? 0),
        'monitorquality'     => (int)($data->clause_monitorquality ?? 0),
    ]);
    $record->agreementdocument = $data->agreementdocument ?? '';
    $record->monitoringfrequency = $data->monitoringfrequency;
    $record->lastmonitoringdate = $data->lastmonitoringdate ?? null;
    $record->nextmonitoringdate = $data->nextmonitoringdate ?? null;
    $record->riskrating = $data->riskrating;
    $record->staffcredentialsverified = $data->staffcredentialsverified;
    // Staff credentials verification details stored in notes if no dedicated columns
    $credentialNote = '';
    if (!empty($data->staffcredentialsverified)) {
        if (!empty($data->staffcredentialsverifieddate)) {
            $credentialNote .= 'Credentials verified: ' . userdate($data->staffcredentialsverifieddate, '%d/%m/%Y');
        }
        if (!empty($data->staffcredentialsdocument)) {
            $credentialNote .= ($credentialNote ? ', ' : 'Credentials ') . 'Document: ' . $data->staffcredentialsdocument;
        }
    }
    $record->status = $data->status;
    if (!empty($credentialNote)) {
        $record->notes = $credentialNote . (!empty($data->notes) ? "\n" . $data->notes : '');
    } else {
        $record->notes = $data->notes ?? '';
    }
    $record->timemodified = $now;

    if ($id > 0) {
        $record->id = $id;
        $DB->update_record('local_rtocompliance_thirdparty', $record);
        $message = get_string('thirdparty_updated', 'local_rtocompliance');
    } else {
        $record->timecreated = $now;
        $record->createdby = $USER->id;
        $DB->insert_record('local_rtocompliance_thirdparty', $record);
        $message = get_string('thirdparty_created', 'local_rtocompliance');
    }

    redirect(
        new moodle_url('/local/rtocompliance/thirdparty.php'),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($id ? 'Edit Arrangement' : 'New Arrangement', get_string('thirdparty', 'local_rtocompliance'), '/local/rtocompliance/thirdparty.php', 'thirdparty');
echo local_rtocompliance_page_banner($id ? 'Edit Arrangement' : 'New Arrangement');
echo $OUTPUT->heading($id ? 'Edit Third-Party Arrangement' : 'New Third-Party Arrangement');

$form->display();

echo $OUTPUT->footer();
