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

namespace local_rtocompliance\task;

defined('MOODLE_INTERNAL') || die();

class send_completion_survey_task extends \core\task\scheduled_task {
    
    public function get_name() {
        return get_string('task_send_completion_survey', 'local_rtocompliance');
    }
    
    public function execute() {
        global $DB, $CFG;
        
        $enabled = get_config('local_rtocompliance', 'autosurvey_enabled');
        if (!$enabled) {
            return;
        }
        
        $delaydays = get_config('local_rtocompliance', 'autosurvey_delay_days') ?: 7;
        $cutoff = time() - ($delaydays * 24 * 60 * 60);
        $maxage = time() - (30 * 24 * 60 * 60);
        
        // Bug 41: Include Qual Builder courses (local_rtocompliance_qualunits) even when
        // nationallyrecognised flag is not set. ASQA QI surveys are required for all VET
        // activity, not only courses explicitly flagged in the legacy course_settings table.
        // Bug 20: Use get_records_sql $limitnum parameter for DB portability.
        $sql = "SELECT DISTINCT cc.userid, cc.course, cc.timecompleted,
                       u.email, u.firstname, u.lastname,
                       c.fullname AS coursename,
                       COALESCE(lrc.qualificationcode, qu_qb.qualificationcode) AS qualificationcode
                FROM {course_completions} cc
                JOIN {user} u ON u.id = cc.userid AND u.deleted = 0 AND u.suspended = 0
                JOIN {course} c ON c.id = cc.course
                LEFT JOIN {local_rtocompliance_courses} lrc ON lrc.courseid = cc.course
                    AND lrc.nationallyrecognised = 1
                LEFT JOIN {local_rtocompliance_qualunits} qu ON qu.courseid = cc.course
                LEFT JOIN {local_rtocompliance_qualbuilder} qu_qb ON qu_qb.id = qu.qualbuilderid
                    AND qu_qb.status != 'superseded'
                LEFT JOIN {local_rtocompliance_surveys} ls ON ls.respondentid = cc.userid
                    AND ls.surveytype = 'learner'
                    AND ls.timecreated > :recentcutoff
                WHERE cc.timecompleted IS NOT NULL
                  AND cc.timecompleted <= :cutoff
                  AND cc.timecompleted >= :maxage
                  AND ls.id IS NULL
                  AND (lrc.id IS NOT NULL OR qu.id IS NOT NULL)
                ORDER BY cc.timecompleted DESC";

        $completions = $DB->get_records_sql($sql, [
            'cutoff'       => $cutoff,
            'maxage'       => $maxage,
            'recentcutoff' => $maxage,
        ], 0, 50);
        
        $sentcount = 0;
        // BUG-4 FIX: date('Y') uses server timezone (likely UTC). On servers running UTC,
        // this produces the wrong year for surveys sent at 11pm on 31 Dec in AEST.
        // Use Australia/Sydney timezone for the survey year to match the RTO's reporting period.
        $tz  = new \DateTimeZone('Australia/Sydney');
        $now = time();
        $year = (new \DateTime('now', $tz))->format('Y');

        $subject = get_config('local_rtocompliance', 'autosurvey_subject') 
            ?: get_string('default_survey_subject', 'local_rtocompliance');
        
        foreach ($completions as $completion) {
            // BUG-3 FIX: Previously the survey DB record was inserted BEFORE checking
            // whether the user still exists. If the user was deleted between the SQL query
            // and this check, the insert would create an orphaned 'pending' survey record.
            // Because the outer SQL filters ls.id IS NULL (no recent survey), this orphaned
            // record would permanently block any future re-queue for the same user.
            // Fix: fetch the user object first, validate, then insert the survey record.
            $userto = \core_user::get_user($completion->userid);
            if (!$userto || !empty($userto->deleted)) {
                continue;
            }

            $accesstoken = bin2hex(random_bytes(32));

            // BUG-21 FIX: Previously the survey DB record was inserted BEFORE message_send().
            // If message_send() threw an exception, the record was left with status='pending'
            // and the outer SQL (ls.id IS NULL filter) permanently excluded this user from
            // all future survey re-queues — the user would NEVER receive a survey.
            // Fix: build the message using the pre-generated $accesstoken (the survey link
            // only depends on the token, not the DB row ID), attempt message_send() first,
            // and only insert the DB record after a successful send. If message_send() throws,
            // no orphaned record is created and the user will be retried on the next cron run.
            $surveylink = new \moodle_url('/local/rtocompliance/survey_respond.php', ['token' => $accesstoken]);

            $messagetext = get_string('autosurvey_message', 'local_rtocompliance', [
                'firstname' => $completion->firstname,
                'coursename' => $completion->coursename,
                'surveylink' => $surveylink->out(false),
            ]);

            $eventdata                    = new \core\message\message();
            $eventdata->component         = 'local_rtocompliance';
            $eventdata->name              = 'survey_invitation';
            $eventdata->userfrom          = \core_user::get_noreply_user();
            $eventdata->userto            = $userto;
            $eventdata->subject           = $subject;
            $eventdata->fullmessage       = strip_tags($messagetext);
            $eventdata->fullmessageformat = FORMAT_HTML;
            $eventdata->fullmessagehtml   = $messagetext;
            $eventdata->smallmessage      = $subject;

            try {
                message_send($eventdata);

                // Insert the survey record only after the message was successfully queued.
                // This guarantees the ls.id IS NULL filter in the outer SQL will suppress
                // future re-sends only for users who actually received the invitation.
                $survey = new \stdClass();
                $survey->surveytype       = 'learner';
                $survey->respondentid     = $completion->userid;
                $survey->respondentname   = trim($completion->firstname . ' ' . $completion->lastname);
                $survey->respondentemail  = $completion->email;
                $survey->responses        = '';
                $survey->year             = $year;
                $survey->accesstoken      = $accesstoken;
                $survey->status           = 'pending';
                $survey->timecreated      = $now;
                $survey->courseid         = $completion->course;
                $DB->insert_record('local_rtocompliance_surveys', $survey);

                $sentcount++;
                mtrace("Sent learner survey to user {$completion->userid} for course {$completion->course}");
            } catch (\Exception $e) {
                mtrace("Failed to send survey to user {$completion->userid}: " . $e->getMessage());
            }
        }
        
        if ($sentcount > 0) {
            mtrace("Sent $sentcount automatic learner surveys");
        }
    }
}
