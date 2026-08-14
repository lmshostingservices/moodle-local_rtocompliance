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
 * RTO Compliance plugin — suitability_bulk.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Bulk suitability checklist sender.
// Two modes (action param):
//   bulk_send  – POST a list of userids[] from the students.php checkbox form
//   fill_gaps  – POST from this page's own confirm form: sends to ALL students
//                with no suitability record for the chosen TAS

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$action = optional_param('action', 'fill_gaps', PARAM_ALPHANUMEXT); // PARAM_ALPHA stripped the underscore from a submitted 'fill_gaps'.

admin_externalpage_setup('local_rtocompliance_students');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();

$PAGE->set_url(new moodle_url('/local/rtocompliance/suitability_bulk.php', ['action' => $action]));
$PAGE->set_title(get_string('suitability_fill_gaps_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('suitability_fill_gaps_title', 'local_rtocompliance'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add(get_string('suitability_fill_gaps_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ─── POST handler (both modes) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $tasid = required_param('tasid', PARAM_INT);
    $tas   = $DB->get_record('local_rtocompliance_tas', ['id' => $tasid], '*', MUST_EXIST);

    $questions = local_rtocompliance_parse_suitability_questions($tas->entryrequirements ?? '');
    if (empty($questions)) {
        redirect(
            new moodle_url('/local/rtocompliance/students.php'),
            get_string('suitability_no_questions', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Build the list of users to process
    if ($action === 'fill_gaps') {
        // All users with no suitability record at all for this TAS
        $users = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
               FROM {user} u
              WHERE u.deleted = 0 AND u.suspended = 0
                AND NOT EXISTS (
                      SELECT 1 FROM {local_rtocompliance_suitability} s
                       WHERE s.userid = u.id AND s.tasid = :tasid
                    )",
            ['tasid' => $tasid]
        );
    } else {
        // bulk_send: POST'd list of user IDs from the students.php form
        $userids = optional_param_array('userids', [], PARAM_INT);
        $userids = array_filter(array_map('intval', $userids));
        if (empty($userids)) {
            redirect(
                new moodle_url('/local/rtocompliance/students.php'),
                get_string('suitability_bulk_none_selected', 'local_rtocompliance'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        $users = [];
        foreach ($userids as $uid) {
            $u = core_user::get_user($uid);
            if ($u && !$u->deleted && !$u->suspended) {
                $users[$uid] = $u;
            }
        }
    }

    $sent    = 0;
    $skipped = 0;

    foreach ($users as $user) {
        if (empty($user->email) || !validate_email($user->email)) {
            $skipped++;
            continue;
        }

        $existing = $DB->get_record('local_rtocompliance_suitability', ['userid' => $user->id, 'tasid' => $tasid]);

        if ($existing) {
            // Skip students who are already confirmed suitable — don't disturb them
            if (in_array($existing->status, ['suitable', 'override_suitable'])) {
                $skipped++;
                continue;
            }
            // Re-send for pending / not_suitable: reset record with a fresh token
            $DB->delete_records('local_rtocompliance_suitability_answers', ['suitabilityid' => $existing->id]);
            $token = bin2hex(random_bytes(32));
            $existing->token          = $token;
            $existing->status         = 'pending';
            $existing->overridenotes  = null;
            $existing->overriddenby   = null;
            $existing->overriddentime = null;
            $existing->timesent       = time();
            $existing->timecompleted  = null;
            $existing->timemodified   = time();
            $DB->update_record('local_rtocompliance_suitability', $existing);
            $suitabilityid = $existing->id;
        } else {
            $token = bin2hex(random_bytes(32));
            $suitabilityid = $DB->insert_record('local_rtocompliance_suitability', (object)[
                'tasid'        => $tasid,
                'userid'       => $user->id,
                'token'        => $token,
                'status'       => 'pending',
                'timesent'     => time(),
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        foreach ($questions as $i => $q) {
            $DB->insert_record('local_rtocompliance_suitability_answers', (object)[
                'suitabilityid' => $suitabilityid,
                'question'      => $q,
                'answer'        => null,
                'displayorder'  => $i,
            ]);
        }

        local_rtocompliance_send_suitability_email($user, $tas, $token);
        local_rtocompliance_log_action('suitability_bulk_sent', 'suitability', $suitabilityid,
            ['userid' => $user->id, 'tasid' => $tasid, 'action' => $action]);
        $sent++;
    }

    $msgdata = (object)['sent' => $sent, 'skipped' => $skipped];
    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        get_string('suitability_bulk_result', 'local_rtocompliance', $msgdata),
        null,
        $sent > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// ─── GET: Fill-Compliance-Gaps confirmation form ──────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('students', 'local_rtocompliance'), null, null, 'students');
echo local_rtocompliance_page_banner(get_string('students', 'local_rtocompliance'));
echo $OUTPUT->heading(get_string('suitability_fill_gaps_title', 'local_rtocompliance'), 2);

// Load approved TAS records with entry requirements
$tas_records = $DB->get_records_sql(
    "SELECT id, qualificationcode, qualificationname, entryrequirements
       FROM {local_rtocompliance_tas}
      WHERE status IN ('approved','review')
        AND entryrequirements IS NOT NULL AND entryrequirements != ''
      ORDER BY qualificationcode",
    []
);

if (empty($tas_records)) {
    echo $OUTPUT->notification(
        get_string('suitability_no_tas', 'local_rtocompliance'),
        \core\output\notification::NOTIFY_WARNING
    );
} else {
    // Pre-calculate how many students have no record per TAS
    $gapcount = [];
    $tasoptions = [];
    foreach ($tas_records as $t) {
        $tasoptions[$t->id] = s($t->qualificationcode) . ': ' . s($t->qualificationname);
        $gapcount[$t->id] = (int) $DB->count_records_sql(
            "SELECT COUNT(u.id) FROM {user} u
              WHERE u.deleted = 0 AND u.suspended = 0
                AND NOT EXISTS (
                      SELECT 1 FROM {local_rtocompliance_suitability} s
                       WHERE s.userid = u.id AND s.tasid = :tasid
                    )",
            ['tasid' => $t->id]
        );
    }

    echo html_writer::start_div('generalbox mb-4 p-4');
    echo html_writer::tag('p', get_string('suitability_fill_gaps_desc', 'local_rtocompliance'));

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/rtocompliance/suitability_bulk.php', [
            'action'  => 'fill_gaps',
            'sesskey' => sesskey(),
        ]))->out(false),
    ]);

    echo html_writer::tag('label',
        get_string('suitability_select_tas', 'local_rtocompliance'),
        ['for' => 'tasid', 'class' => 'd-block mb-1 font-weight-bold']
    );
    echo html_writer::select($tasoptions, 'tasid', '', false, [
        'id'    => 'tasid',
        'class' => 'form-control mb-2',
        'style' => 'max-width:500px',
    ]);

    echo html_writer::tag('p', '', ['id' => 'gap-count', 'class' => 'text-muted small mt-1 mb-3']);

    echo html_writer::tag('button',
        get_string('suitability_fill_gaps_btn', 'local_rtocompliance'),
        [
            'type'    => 'submit',
            'class'   => 'btn btn-warning',
            'title'   => 'Email a Student Suitability Check to every student who has no record yet for the selected qualification',
            'onclick' => 'return confirm("' . get_string('suitability_fill_gaps_confirm', 'local_rtocompliance') . '")',
        ]
    );
    echo ' ';
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/students.php'),
        get_string('cancel'),
        ['class' => 'btn btn-outline-secondary ml-2']
    );

    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    echo html_writer::script('(function () {
        var gaps = ' . json_encode($gapcount) . ';
        var sel  = document.getElementById("tasid");
        var cnt  = document.getElementById("gap-count");
        function update() {
            if (!sel || !cnt) return;
            var n = gaps[sel.value] || 0;
            cnt.textContent = n + " student(s) have not yet received a Student Suitability Check for this qualification and will be emailed.";
        }
        if (sel) { sel.addEventListener("change", update); update(); }
    })();');
}

echo $OUTPUT->footer();
