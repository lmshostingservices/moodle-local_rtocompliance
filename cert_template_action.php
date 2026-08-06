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

// CERT-TEMPLATE-BUILDER (v4.2.40) — POST action handler.
//
// Single endpoint for all status transitions: submit, activate, archive,
// delete, duplicate.  Requires sesskey + manage capability.  Always
// redirects back to the list page with a success/failure notification.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');

use local_rtocompliance\cert_template;

require_login();
require_capability('local/rtocompliance:managecerttemplates', context_system::instance());
require_sesskey();

$action = required_param('action', PARAM_ALPHA);
$id     = required_param('id',     PARAM_INT);

$listurl = new moodle_url('/local/rtocompliance/cert_templates.php');

$template = cert_template::get($id);
if (!$template) {
    redirect($listurl, get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
        null, \core\output\notification::NOTIFY_ERROR);
}

switch ($action) {
    case 'submit':
        $result = cert_template::submit_for_approval($id);
        if (!empty($result['ok'])) {
            redirect($listurl,
                get_string('cert_template_action_ok_approved', 'local_rtocompliance'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
        $msg = get_string('cert_template_action_err_validation', 'local_rtocompliance');
        if (!empty($result['validation']['errors'])) {
            $details = [];
            foreach ($result['validation']['errors'] as $e) {
                $details[] = $e['message'] ?? '';
            }
            $msg .= ' ' . implode(' ', $details);
        }
        redirect(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $id]),
            $msg, null, \core\output\notification::NOTIFY_ERROR);
        break;

    case 'activate':
        if (!cert_template::activate($id)) {
            redirect($listurl, get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect($listurl, get_string('cert_template_action_ok_activated', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_SUCCESS);
        break;

    case 'archive':
        cert_template::archive($id);
        redirect($listurl, get_string('cert_template_action_ok_archived', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_SUCCESS);
        break;

    case 'delete':
        if (!cert_template::delete($id)) {
            redirect($listurl, get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect($listurl, get_string('cert_template_action_ok_deleted', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_SUCCESS);
        break;

    case 'duplicate':
        $newid = cert_template::duplicate($id);
        redirect(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $newid]),
            get_string('cert_template_action_ok_duplicated', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_SUCCESS);
        break;

    case 'reset':
        // ASQA-COMPLIANCE-PASS-3 (v4.2.60) — Reset a template's design back
        // to the ASQA-recommended starter for its certtype + orientation.
        // Status / approval / activation are preserved; only the design_json
        // is replaced. Confirm prompt is enforced on the list page.
        if (!cert_template::reset_to_starter($id)) {
            redirect($listurl, get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
                null, \core\output\notification::NOTIFY_ERROR);
        }
        redirect(new moodle_url('/local/rtocompliance/cert_template_edit.php', ['id' => $id]),
            get_string('cert_template_action_ok_reset', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_SUCCESS);
        break;

    default:
        redirect($listurl, get_string('cert_template_action_err_notallowed', 'local_rtocompliance'),
            null, \core\output\notification::NOTIFY_ERROR);
}
