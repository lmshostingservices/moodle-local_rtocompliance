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
 * ASYNC-EMAIL (v4.4.52): Adhoc task that delivers the admin notification email
 * for a completed Student Eligibility Checklist submission.
 *
 * Queued by suitability_form.php immediately after the student submits so the
 * page can return to the student without waiting for SMTP. Cron picks up the
 * task within its next run (typically ≤ 1 minute) and calls email_to_user()
 * for each site admin in the background.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class send_suitability_notification extends \core\task\adhoc_task {
    public function get_name() {
        return 'Send eligibility check admin notification';
    }

    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->suitabilityid)) {
            mtrace('[send_suitability_notification] Missing suitabilityid — skipping.');
            return;
        }

        $suit = $DB->get_record('local_rtocompliance_suitability', ['id' => (int)$data->suitabilityid]);
        if (!$suit) {
            mtrace('[send_suitability_notification] Suitability record ' . (int)$data->suitabilityid . ' not found — skipping.');
            return;
        }

        $student = \core_user::get_user($suit->userid);
        if (!$student || !empty($student->deleted)) {
            mtrace('[send_suitability_notification] Student user not found or deleted — skipping.');
            return;
        }

        $tas = $DB->get_record('local_rtocompliance_tas', ['id' => $suit->tasid]);
        if (!$tas) {
            mtrace('[send_suitability_notification] TAS record ' . $suit->tasid . ' not found — skipping.');
            return;
        }

        $rtoname_n = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';
        $viewurl   = (new \moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $suit->id]))->out(false);

        $admins_nfy = get_admins();
        $sent = 0;
        foreach ($admins_nfy as $adm) {
            $subj = '[' . $rtoname_n . '] Eligibility Check awaiting review — ' . fullname($student);
            $body = "A student has completed their Pre-Enrolment Eligibility Check and is awaiting your review.\n\n"
                . "Student: " . fullname($student) . " <" . $student->email . ">\n"
                . "Qualification: " . $tas->qualificationcode . " " . $tas->qualificationname . "\n"
                . "Submitted: " . userdate($suit->timecompleted ?: time()) . "\n\n"
                . "Review the submission at:\n" . $viewurl;
            $result = email_to_user($adm, $student, $subj, $body);
            if ($result) {
                $sent++;
                mtrace('[send_suitability_notification] Notified admin ' . $adm->email);
            } else {
                mtrace('[send_suitability_notification] email_to_user() returned false for ' . $adm->email);
            }
        }
        mtrace('[send_suitability_notification] Done — ' . $sent . '/' . count($admins_nfy) . ' admin(s) notified.');
    }
}
