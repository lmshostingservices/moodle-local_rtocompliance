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
 * RTO Compliance plugin — student_declaration_send.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// student_declaration_send.php — Send a Student Declaration to selected students.
// FIX-RTO-DECL-SELECT (v4.0.78): replaced "send to all N students" button with a
// checkbox-selection table so admin can target 1 or more specific students.
// Declarations status (Not Sent / Pending / Completed) is shown per row.
// POST now accepts userids[] (selected array) instead of userid=0 (all).

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_students');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

// userid is kept for single-student shortcut (called from student profile page).
$userid       = optional_param('userid', 0, PARAM_INT);
$declfilter   = optional_param('declfilter', 'all', PARAM_ALPHA); // all|notsent|pending|completed
$search       = optional_param('search', '', PARAM_TEXT);
$page         = max(0, optional_param('page', 0, PARAM_INT));
$perpage      = 50;

$PAGE->set_url(new moodle_url('/local/rtocompliance/student_declaration_send.php', [
    'userid'     => $userid,
    'declfilter' => $declfilter,
    'search'     => $search,
    'page'       => $page,
]));
$PAGE->set_title('Send Student Declaration');
$PAGE->set_heading('Send Student Declaration');
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/students.php'));
$PAGE->navbar->add('Send Student Declaration');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// The fixed declaration checklist items (ASQA compliance requirement)
$declarationItems = [
    'The Student Handbook, including my rights and obligations',
    'Student behaviour expectations and code of conduct',
    'How to lodge complaints and appeals',
    'My responsibility to participate in training and assessment activities',
    'Requirements for providing accurate information and evidence',
    'My obligation to complete assessments honestly and without plagiarism',
    'Any support services and adjustments available to me',
];
$declarationFootnote = 'I understand that failure to meet these obligations may affect my enrolment, '
    . 'progress, or results. I acknowledge that this information was provided to me prior to or at '
    . 'enrolment and I have had the opportunity to ask questions.';

// ── Build site-admin exclusion list ─────────────────────────────────────────
$siteadminlist = '0';
if (!empty($CFG->siteadmins)) {
    $ids = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
    if (!empty($ids)) {
        $siteadminlist = implode(',', $ids);
    }
}

// ── Ensure declarations table exists ────────────────────────────────────────
$dbman = $DB->get_manager();
if (!$dbman->table_exists('local_rtocompliance_declarations')) {
    $xmldb = new xmldb_table('local_rtocompliance_declarations');
    $xmldb->add_field('id',            XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $xmldb->add_field('userid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $xmldb->add_field('token',         XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL);
    $xmldb->add_field('status',        XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'sent');
    $xmldb->add_field('fullname',      XMLDB_TYPE_CHAR,   '200',  null, null);
    $xmldb->add_field('signature',     XMLDB_TYPE_CHAR,   '200',  null, null);
    $xmldb->add_field('agreed',        XMLDB_TYPE_INTEGER,  '1',  null, XMLDB_NOTNULL, null, '0');
    $xmldb->add_field('timecreated',   XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
    $xmldb->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10',  null, null);
    $xmldb->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $dbman->create_table($xmldb);
}

// ── Handle POST (confirmed send to selected userids[]) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $now     = time();
    $sent    = 0;
    $skipped = 0;
    $errs    = [];

    // Accept either userids[] (new multi-select) or legacy single userid.
    $raw_ids = [];
    $postedids = optional_param_array('userids', [], PARAM_INT);
    if (!empty($postedids)) {
        foreach ($postedids as $raw) {
            $i = (int)$raw;
            if ($i > 0) {
                $raw_ids[] = $i;
            }
        }
    } else if ($userid > 0) {
        $raw_ids = [$userid];
    }

    if (empty($raw_ids)) {
        redirect(
            new moodle_url('/local/rtocompliance/student_declaration_send.php'),
            'No students selected.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Load only the selected users (validate they exist + not deleted/suspended)
    list($insql, $inparams) = $DB->get_in_or_equal($raw_ids, SQL_PARAMS_NAMED);
    $users = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
           FROM {user} u
          WHERE u.id $insql AND u.deleted = 0 AND u.suspended = 0",
        $inparams
    );

    foreach ($users as $u) {
        // Skip if already pending or completed (same deduplication as before).
        $existing = $DB->get_record_select(
            'local_rtocompliance_declarations',
            'userid = ? AND (status = ? OR agreed = 1)',
            [$u->id, 'sent']
        );
        if ($existing) {
            $skipped++;
            continue;
        }

        $token = bin2hex(random_bytes(32));
        $rec              = new stdClass();
        $rec->userid      = $u->id;
        $rec->token       = $token;
        $rec->status      = 'sent';
        $rec->agreed      = 0;
        $rec->timecreated = $now;
        $DB->insert_record('local_rtocompliance_declarations', $rec);

        $respondUrl = (new moodle_url('/local/rtocompliance/student_declaration_respond.php', ['token' => $token]))->out(false);
        $firstName  = $u->firstname ?: 'Student';

        $subject = 'Student Declaration — Action Required';
        $body = '<p>Dear ' . htmlspecialchars($firstName) . ',</p>'
            . '<p>As part of your enrolment, we require you to confirm that you have read and understood your student obligations by completing the Student Declaration below.</p>'
            . '<p><strong>Please click the link below to complete your declaration:</strong></p>'
            . '<p><a href="' . $respondUrl . '">' . $respondUrl . '</a></p>'
            . '<p>This takes approximately 2 minutes to complete.</p>'
            . '<p>Thank you.</p>';

        $tempuser                    = new stdClass();
        $tempuser->id                = $u->id;
        $tempuser->email             = $u->email;
        $tempuser->firstname         = $u->firstname;
        $tempuser->lastname          = $u->lastname;
        $tempuser->firstnamephonetic = $u->firstnamephonetic ?? '';
        $tempuser->lastnamephonetic  = $u->lastnamephonetic  ?? '';
        $tempuser->middlename        = $u->middlename        ?? '';
        $tempuser->alternatename     = $u->alternatename     ?? '';
        $tempuser->mailformat        = 1;
        $tempuser->emailstop         = 0;
        $tempuser->maildisplay       = 1;
        $tempuser->auth              = 'manual';
        $tempuser->confirmed         = 1;
        $tempuser->suspended         = 0;
        $tempuser->deleted           = 0;
        $tempuser->mnethostid        = $CFG->mnet_localhost_id ?? 1;
        $tempuser->lang              = current_language();
        $tempuser->timezone          = '99';
        $noreply = core_user::get_noreply_user();

        try {
            $result = email_to_user($tempuser, $noreply, $subject, html_to_text($body), $body);
            if ($result) {
                $sent++;
            } else {
                $errs[] = $u->email . ' — delivery failed';
            }
        } catch (Exception $e) {
            $errs[] = $u->email . ': ' . $e->getMessage();
        }
    }

    $msg = "Student Declaration sent to $sent student(s).";
    if ($skipped > 0) {
        $msg .= " $skipped skipped (already sent or completed).";
    }
    if (!empty($errs)) {
        $msg .= ' ' . count($errs) . ' failed: ' . implode('; ', array_slice($errs, 0, 3));
    }
    $level = $sent > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect(new moodle_url('/local/rtocompliance/students.php'), $msg, null, $level);
}

// ── Render the page ──────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Send Student Declaration', get_string('students', 'local_rtocompliance'), '/local/rtocompliance/students.php', 'students');
echo local_rtocompliance_page_banner('Send Student Declaration');
echo html_writer::start_div('compliance-container');
echo html_writer::tag('h2', 'Student Declaration — Pre-Enrolment Obligations');

// Declaration summary card
echo html_writer::start_div('info-card', ['style' => 'margin-bottom:1.5rem;']);
echo html_writer::tag('h4', 'What this sends');
echo html_writer::tag('p',
    'This emails each selected student a unique link to a Student Declaration form. '
    . 'Students confirm they have read and understood the following obligations by entering their full name '
    . 'and typed signature. A timestamp is recorded and the completed declaration is stored against their student record.'
);
echo html_writer::start_tag('ul');
foreach ($declarationItems as $item) {
    echo html_writer::tag('li', htmlspecialchars($item));
}
echo html_writer::end_tag('ul');
echo html_writer::tag('p', '<em>' . htmlspecialchars($declarationFootnote) . '</em>');
echo html_writer::end_div();

// ── Load students + declaration status ──────────────────────────────────────
// Get latest declaration record per student.
$declRecords = $DB->get_records_sql(
    "SELECT d.userid,
            d.status,
            d.agreed,
            d.timecreated,
            d.timecompleted
       FROM {local_rtocompliance_declarations} d
       JOIN (
           SELECT userid, MAX(id) AS maxid
             FROM {local_rtocompliance_declarations}
            GROUP BY userid
       ) latest ON latest.userid = d.userid AND latest.maxid = d.id"
);

// Build student query (same exclusions as students.php)
$sql = "SELECT u.id, u.firstname, u.lastname, u.email,
               u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
          FROM {user} u
          LEFT JOIN (
              SELECT DISTINCT ra.userid
                FROM {role_assignments} ra
                JOIN {role} r ON r.id = ra.roleid
               WHERE r.shortname IN ('editingteacher','teacher','manager','coursecreator',
                                     'trainer','assessor','trainerassessor')
                  OR r.archetype IN ('editingteacher','teacher','manager')
          ) staff ON staff.userid = u.id
          LEFT JOIN (
              SELECT DISTINCT userid FROM {local_rtocompliance_trainers}
          ) rtoc_trainer ON rtoc_trainer.userid = u.id
         WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1
           AND u.id NOT IN ($siteadminlist)
           AND staff.userid IS NULL
           AND rtoc_trainer.userid IS NULL";

$params = [];

if ($userid > 0) {
    // Single-student shortcut from profile page.
    $sql .= " AND u.id = :userid";
    $params['userid'] = $userid;
} else {
    // Apply declaration status filter.
    if ($declfilter === 'notsent') {
        // Students with NO declaration record at all.
        $sql .= " AND u.id NOT IN (SELECT DISTINCT userid FROM {local_rtocompliance_declarations})";
    } else if ($declfilter === 'pending') {
        // Students with a sent-but-not-agreed declaration.
        $sql .= " AND u.id IN (
            SELECT d.userid FROM {local_rtocompliance_declarations} d
            JOIN (SELECT userid, MAX(id) AS maxid FROM {local_rtocompliance_declarations} GROUP BY userid) lx
              ON lx.userid = d.userid AND lx.maxid = d.id
           WHERE d.agreed = 0
        )";
    } else if ($declfilter === 'completed') {
        // Students who have agreed.
        $sql .= " AND u.id IN (
            SELECT DISTINCT userid FROM {local_rtocompliance_declarations} WHERE agreed = 1
        )";
    }
}

// Search filter
if (!empty($search)) {
    $s1 = $DB->sql_like('u.firstname', ':s1', false, false);
    $s2 = $DB->sql_like('u.lastname',  ':s2', false, false);
    $s3 = $DB->sql_like('u.email',     ':s3', false, false);
    $sql .= " AND ($s1 OR $s2 OR $s3)";
    $params['s1'] = '%' . $search . '%';
    $params['s2'] = '%' . $search . '%';
    $params['s3'] = '%' . $search . '%';
}

$countsql   = "SELECT COUNT(DISTINCT u.id) " . substr($sql, strpos($sql, 'FROM'));
$totalcount = $DB->count_records_sql($countsql, $params);

$sql .= " ORDER BY u.lastname, u.firstname";
$students = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Status summary counts (for filter bar badges)
$allCount       = $DB->count_records_sql("SELECT COUNT(DISTINCT u.id) " . substr($sql, strpos($sql, 'FROM')),
    // Re-use all params but without status filter — do a separate count query.
    []
);
$countNotSent   = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
       FROM {user} u
       LEFT JOIN (SELECT DISTINCT ra.userid FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                   WHERE r.shortname IN ('editingteacher','teacher','manager','coursecreator','trainer','assessor','trainerassessor')
                      OR r.archetype IN ('editingteacher','teacher','manager')) staff ON staff.userid = u.id
       LEFT JOIN (SELECT DISTINCT userid FROM {local_rtocompliance_trainers}) rtoc_trainer ON rtoc_trainer.userid = u.id
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1
        AND u.id NOT IN ($siteadminlist)
        AND staff.userid IS NULL AND rtoc_trainer.userid IS NULL
        AND u.id NOT IN (SELECT DISTINCT userid FROM {local_rtocompliance_declarations})"
);
$countPending   = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT d.userid)
       FROM {local_rtocompliance_declarations} d
       JOIN (SELECT userid, MAX(id) AS maxid FROM {local_rtocompliance_declarations} GROUP BY userid) lx
         ON lx.userid = d.userid AND lx.maxid = d.id
      WHERE d.agreed = 0"
);
$countCompleted = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {local_rtocompliance_declarations} WHERE agreed = 1"
);

// ── Filter bar ───────────────────────────────────────────────────────────────
$baseUrl = new moodle_url('/local/rtocompliance/student_declaration_send.php');
$filterBar = '<div class="rtoc-filter-bar" style="margin-bottom:1rem;">';
$filterBar .= '<div class="rtoc-filter-fields">';

$filterBtn = function ($label, $value, $count, $current) use ($baseUrl, $search) {
    $active = ($current === $value) ? ' btn-primary' : ' btn-outline-secondary';
    $url = clone $baseUrl;
    $url->param('declfilter', $value);
    if ($search) { $url->param('search', $search); }
    return '<a href="' . $url->out(false) . '" class="btn btn-sm' . $active . ' mr-1">'
        . htmlspecialchars($label) . ' <span class="badge badge-light">' . $count . '</span></a>';
};

$filterBar .= '<div class="rtoc-filter-group">';
$filterBar .= $filterBtn('All', 'all', $totalcount, $declfilter);
$filterBar .= $filterBtn('Not Sent', 'notsent', $countNotSent, $declfilter);
$filterBar .= $filterBtn('Sent — Pending', 'pending', $countPending, $declfilter);
$filterBar .= $filterBtn('Completed', 'completed', $countCompleted, $declfilter);
$filterBar .= '</div>';

// Search box
$filterBar .= '<div class="rtoc-filter-group rtoc-filter-search" style="margin-left:auto;">';
$searchUrl = clone $baseUrl;
$filterBar .= '<form method="get" action="' . $searchUrl->out(false) . '" style="display:flex;gap:0.4rem;align-items:center;">';
$filterBar .= '<input type="hidden" name="declfilter" value="' . s($declfilter) . '">';
$filterBar .= '<input type="text" name="search" class="form-control form-control-sm" placeholder="Search name or email" value="' . s($search) . '" style="width:200px;">';
$filterBar .= '<button type="submit" class="btn btn-sm btn-secondary">Search</button>';
if ($search) {
    $clearUrl = clone $baseUrl;
    $clearUrl->param('declfilter', $declfilter);
    $filterBar .= '<a href="' . $clearUrl->out(false) . '" class="btn btn-sm btn-outline-secondary">Clear</a>';
}
$filterBar .= '</form>';
$filterBar .= '</div>';
$filterBar .= '</div></div>';
echo $filterBar;

// ── Student selection form ───────────────────────────────────────────────────
$sendUrl = new moodle_url('/local/rtocompliance/student_declaration_send.php', [
    'userid'  => 0,
    'sesskey' => sesskey(),
]);
echo '<form id="decl-send-form" method="post" action="' . $sendUrl->out(false) . '">';
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Sticky send bar
echo '<div id="decl-send-bar" style="'
    . 'position:sticky;top:0;z-index:100;background:#fff;border-bottom:1px solid #e2e8f0;'
    . 'padding:0.75rem 0;margin-bottom:1rem;display:flex;align-items:center;gap:1rem;">';
echo '<button type="submit" id="decl-send-btn" class="btn btn-primary" disabled>'
    . 'Send Declaration to <span id="decl-count">0</span> selected student(s)</button>';
echo '<span class="text-muted" style="font-size:0.875rem;">Select students below, then click Send.</span>';
echo '</div>';

if (empty($students)) {
    echo $OUTPUT->notification(get_string('noresults'), \core\output\notification::NOTIFY_INFO);
} else {
    // ── Table ─────────────────────────────────────────────────────────────
    echo '<div class="rtoc-table-wrapper">';
    echo '<table class="generaltable" style="min-width:900px;">';
    echo '<thead><tr>';
    echo '<th style="width:40px;">'
        . html_writer::checkbox('selectall-decl', '1', false, '', ['id' => 'selectall-decl', 'title' => 'Select all visible'])
        . '</th>';
    echo '<th>Name</th>';
    echo '<th>Email</th>';
    echo '<th>Declaration Status</th>';
    echo '<th>Date Sent</th>';
    echo '<th>Date Completed</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($students as $student) {
        $decl       = $declRecords[$student->id] ?? null;
        $statusBadge = '';
        $sentDate    = '-';
        $completedDate = '-';
        $cbDisabled  = false;

        if (!$decl) {
            $statusBadge = '<span class="badge badge-secondary">Not Sent</span>';
        } else if ($decl->agreed) {
            $statusBadge  = '<span class="badge badge-success">Completed</span>';
            $cbDisabled   = true; // Don't re-send to students who already completed
            $completedDate = $decl->timecompleted ? userdate($decl->timecompleted, get_string('strftimedatefullshort')) : '-';
            $sentDate      = $decl->timecreated ? userdate($decl->timecreated, get_string('strftimedatefullshort')) : '-';
        } else {
            $statusBadge = '<span class="badge badge-warning">Sent — Pending</span>';
            $sentDate    = $decl->timecreated ? userdate($decl->timecreated, get_string('strftimedatefullshort')) : '-';
        }

        $cbAttrs = ['class' => 'decl-student-cb', 'data-userid' => $student->id];
        if ($cbDisabled) {
            $cbAttrs['disabled'] = 'disabled';
            $cbAttrs['title']    = 'Already completed — will not be re-sent';
        }
        $checkbox = html_writer::checkbox('userids[]', $student->id, false, '', $cbAttrs);

        $fullname  = htmlspecialchars(fullname($student));
        echo '<tr>';
        echo '<td>' . $checkbox . '</td>';
        echo '<td>' . $fullname . '</td>';
        echo '<td>' . htmlspecialchars($student->email) . '</td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '<td>' . $sentDate . '</td>';
        echo '<td>' . $completedDate . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}

echo '</form>';

// ── Cancel link ──────────────────────────────────────────────────────────────
echo '<div style="margin-top:1rem;">';
echo html_writer::link(new moodle_url('/local/rtocompliance/students.php'), 'Cancel', ['class' => 'btn btn-secondary']);
echo '</div>';

echo html_writer::end_div(); // .compliance-container

// ── JS for checkbox counter ───────────────────────────────────────────────────
echo html_writer::script('
(function () {
    var sendBtn   = document.getElementById("decl-send-btn");
    var countSpan = document.getElementById("decl-count");
    var selectAll = document.getElementById("selectall-decl");

    function updateCount() {
        var checked = document.querySelectorAll(".decl-student-cb:checked").length;
        if (countSpan) countSpan.textContent = checked;
        if (sendBtn)   sendBtn.disabled = (checked === 0);
    }

    document.querySelectorAll(".decl-student-cb").forEach(function (cb) {
        cb.addEventListener("change", function () {
            updateCount();
            if (selectAll && !cb.checked) selectAll.indeterminate = true;
        });
    });

    if (selectAll) {
        selectAll.addEventListener("change", function () {
            document.querySelectorAll(".decl-student-cb:not([disabled])").forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateCount();
        });
    }

    updateCount();
})();
');

echo $OUTPUT->footer();
