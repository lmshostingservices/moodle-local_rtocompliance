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
 * RTO Compliance plugin — student_support_input.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_student_support_input');
require_login();

// Capability gate — consistent with the rest of the plugin (manage).
$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);

$PAGE->set_url('/local/rtocompliance/student_support_input.php');
$PAGE->set_title('Trainer Support Input');
$PAGE->set_heading('Trainer Support Input');

// API credentials — same resolution chain as tas_edit.php.
$_rtoc_apikey  = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: get_config('local_rtocompliance', 'apikey') ?: '')
    : (get_config('local_rtocompliance', 'apikey') ?: '');
$_rtoc_apibase = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

// =====================================================================
// Allowed value sets (mirror the local_rtocompliance_supportnotes schema).
// =====================================================================
$NOTETYPES = [
    'lln'                  => 'LLN observation & support (Standard 2.3)',
    'reasonable_adjustment'=> 'Reasonable adjustment (Standard 2.4)',
    'diversity'            => 'Diversity & inclusion (Standard 2.5)',
    'wellbeing'            => 'Wellbeing (Standard 2.6)',
    'referral'             => 'Support service referral',
    'at_risk'              => 'At-risk intervention',
    'support'              => 'General training support',
    'other'                => 'Other',
];
$OUTCOMES = [
    'open'       => 'Open',
    'inprogress' => 'In progress',
    'closed'     => 'Closed',
];

// Currently selected student (per-student records).
$studentid = optional_param('studentid', 0, PARAM_INT);

$returnurl = new moodle_url('/local/rtocompliance/student_support_input.php',
    $studentid ? ['studentid' => $studentid] : []);

// =====================================================================
// POST handlers (server-side persistence) — sesskey guarded.
// =====================================================================
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'save' && confirm_sesskey()) {
    $sid = required_param('studentid', PARAM_INT);
    $student = $DB->get_record('local_rtocompliance_students', ['id' => $sid], '*', MUST_EXIST);

    $notetype     = optional_param('notetype', 'other', PARAM_ALPHAEXT);
    $category     = trim(optional_param('category', '', PARAM_TEXT));
    $detail       = trim(optional_param('detail', '', PARAM_TEXT));
    $actiontaken  = trim(optional_param('actiontaken', '', PARAM_TEXT));
    $outcomestatus= optional_param('outcomestatus', '', PARAM_ALPHA);
    $confidential = optional_param('confidential', 0, PARAM_INT) ? 1 : 0;

    if (!isset($NOTETYPES[$notetype])) {
        $notetype = 'other';
    }
    if ($outcomestatus !== '' && !isset($OUTCOMES[$outcomestatus])) {
        $outcomestatus = '';
    }

    if ($detail === '') {
        redirect(new moodle_url('/local/rtocompliance/student_support_input.php', ['studentid' => $sid]),
            'A detail note is required — nothing was saved.', null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $record = new stdClass();
    $record->studentid    = $sid;
    $record->userid       = !empty($student->userid) ? $student->userid : null;
    $record->notetype     = $notetype;
    $record->category     = $category !== '' ? $category : null;
    $record->detail       = $detail;
    $record->actiontaken  = $actiontaken !== '' ? $actiontaken : null;
    $record->outcomestatus= $outcomestatus !== '' ? $outcomestatus : null;
    $record->confidential = $confidential;
    $record->recordedby   = $USER->id;
    $record->timecreated  = time();
    $record->timemodified = time();
    $DB->insert_record('local_rtocompliance_supportnotes', $record);

    redirect(new moodle_url('/local/rtocompliance/student_support_input.php', ['studentid' => $sid]),
        'Support record saved securely.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && confirm_sesskey()) {
    $sid    = required_param('studentid', PARAM_INT);
    $noteid = required_param('noteid', PARAM_INT);
    // Ensure the note belongs to this student before deleting.
    if ($DB->record_exists('local_rtocompliance_supportnotes', ['id' => $noteid, 'studentid' => $sid])) {
        $DB->delete_records('local_rtocompliance_supportnotes', ['id' => $noteid, 'studentid' => $sid]);
    }
    redirect(new moodle_url('/local/rtocompliance/student_support_input.php', ['studentid' => $sid]),
        'Support record deleted.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// =====================================================================
// Build the student list for the selector.
// =====================================================================
$studentoptions = [];
$studentrows = $DB->get_records_sql(
    "SELECT s.id, s.userid, s.firstname AS sfn, s.lastname AS sln,
            u.firstname AS ufn, u.lastname AS uln
       FROM {local_rtocompliance_students} s
       LEFT JOIN {user} u ON u.id = s.userid
   ORDER BY COALESCE(NULLIF(s.lastname, ''), u.lastname),
            COALESCE(NULLIF(s.firstname, ''), u.firstname)"
);
foreach ($studentrows as $row) {
    $first = $row->sfn !== null && $row->sfn !== '' ? $row->sfn : $row->ufn;
    $last  = $row->sln !== null && $row->sln !== '' ? $row->sln : $row->uln;
    $name  = trim($first . ' ' . $last);
    if ($name === '') {
        $name = 'Student #' . $row->id;
    }
    $studentoptions[$row->id] = $name;
}

// Resolve the selected student record.
$selectedstudent = null;
$selectedname = '';
if ($studentid && isset($studentoptions[$studentid])) {
    $selectedstudent = $DB->get_record('local_rtocompliance_students', ['id' => $studentid]);
    $selectedname = $studentoptions[$studentid];
}

$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class("path-local-rtocompliance");

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Trainer Support Input', null, null, 'student_support');

echo html_writer::start_div('compliance-container');

echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', 'Trainer Support Input (Standards 2.3 & 2.4)');
echo html_writer::end_div();

echo html_writer::start_div('info-card');
echo html_writer::tag('h4', 'Per-student support record');
echo html_writer::tag('p', '
    Use this form to capture an individual student\'s support, adjustments, referrals, interventions,
    diversity considerations and wellbeing notes. Records are stored securely on the server against the
    selected student and retained as the per-student evidence trail for Standards 2.3 (Training Support),
    2.4 (Reasonable Adjustment) and 2.6 (Wellbeing). They are visible to authorised staff and auditors —
    nothing is kept only in your browser.
    Use the <strong>Auto Fill (AI)</strong> button to draft compliance-aligned text from the LLN level
    and risk dropdowns — review and edit before saving.
');
echo html_writer::end_div();

// =====================================================================
// STUDENT SELECTOR
// =====================================================================
echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
echo html_writer::tag('h4', 'Select student');
echo '<form method="get" action="' . (new moodle_url('/local/rtocompliance/student_support_input.php'))->out() . '" style="margin-top:0.5rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">';
echo '<label for="studentid" style="font-weight:600;">Student</label>';
echo html_writer::select($studentoptions, 'studentid', $studentid, ['' => 'Choose a student…'],
    ['id' => 'studentid', 'onchange' => 'this.form.submit();']);
echo '<noscript><button type="submit" class="btn btn-primary btn-sm">Go</button></noscript>';
echo '</form>';
if (!$selectedstudent) {
    echo html_writer::tag('p', 'Choose a student above to view and add their support records.',
        ['style' => 'margin-top:0.75rem;color:#6b7280;']);
}
echo html_writer::end_div();

if ($selectedstudent) {
    // =====================================================================
    // FORM — add a new support record for the selected student.
    // =====================================================================
    echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
    echo html_writer::tag('h4', 'New Support Record for ' . s($selectedname));

    echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/student_support_input.php'))->out() . '">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="studentid" value="' . (int)$studentid . '">';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:0.75rem;">';

    // Note type.
    echo '<div><label for="notetype" style="display:block;font-weight:600;margin-bottom:0.25rem;">Record type</label>';
    echo '<select id="notetype" name="notetype" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
    foreach ($NOTETYPES as $val => $lbl) {
        echo '<option value="' . s($val) . '">' . s($lbl) . '</option>';
    }
    echo '</select></div>';

    // Category (free text).
    echo '<div><label for="category" style="display:block;font-weight:600;margin-bottom:0.25rem;">Category (optional)</label>';
    echo '<input type="text" id="category" name="category" placeholder="e.g. Extended time, LLN" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;"></div>';

    // Outcome status.
    echo '<div><label for="outcomestatus" style="display:block;font-weight:600;margin-bottom:0.25rem;">Outcome status</label>';
    echo '<select id="outcomestatus" name="outcomestatus" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
    echo '<option value="">Not set</option>';
    foreach ($OUTCOMES as $val => $lbl) {
        echo '<option value="' . s($val) . '">' . s($lbl) . '</option>';
    }
    echo '</select></div>';

    // LLN level (AI context only — not stored).
    echo '<div><label for="llnLevel" style="display:block;font-weight:600;margin-bottom:0.25rem;">LLN level (ACSF) — AI context</label>';
    echo '<select id="llnLevel" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
    echo '<option value="">Not assessed</option>';
    echo '<option value="Below ACSF 3">Below ACSF 3</option>';
    echo '<option value="ACSF 3">ACSF 3 (course level)</option>';
    echo '<option value="Above ACSF 3">Above ACSF 3</option>';
    echo '</select></div>';

    // Risk level (AI context only — not stored).
    echo '<div><label for="risk" style="display:block;font-weight:600;margin-bottom:0.25rem;">Risk level — AI context</label>';
    echo '<select id="risk" style="width:100%;padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;">';
    echo '<option value="Low">Low</option>';
    echo '<option value="Medium">Medium</option>';
    echo '<option value="High">High</option>';
    echo '</select></div>';
    echo '</div>';

    // Detail (required).
    echo '<div style="margin-top:1rem;">';
    echo '<label for="detail" style="display:block;font-weight:600;margin-bottom:0.25rem;">Support / adjustment / wellbeing detail <span style="color:#b91c1c;">*</span></label>';
    echo '<textarea id="detail" name="detail" rows="5" required placeholder="Observations, support provided, adjustments made, referrals, diversity considerations or wellbeing notes." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-family:inherit;line-height:1.5;"></textarea>';
    echo '</div>';

    // Action taken.
    echo '<div style="margin-top:1rem;">';
    echo '<label for="actiontaken" style="display:block;font-weight:600;margin-bottom:0.25rem;">Action taken (optional)</label>';
    echo '<textarea id="actiontaken" name="actiontaken" rows="3" placeholder="What was actioned, by whom, and any follow-up scheduled." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-family:inherit;line-height:1.5;"></textarea>';
    echo '</div>';

    // Confidential.
    echo '<div style="margin-top:1rem;">';
    echo '<label style="display:flex;align-items:center;gap:0.5rem;font-weight:600;">';
    echo '<input type="checkbox" name="confidential" value="1" checked> Mark as confidential';
    echo '</label>';
    echo '</div>';

    // AI Auto-Fill controls.
    echo '<div style="margin-top:1rem;" id="rtocSupportInputConfig"'
        . ' data-api-key="' . s($_rtoc_apikey) . '"'
        . ' data-api-base="' . s($_rtoc_apibase) . '"'
        . ' data-student-name="' . s($selectedname) . '">';
    echo '<button type="button" id="autoFillBtn" class="btn btn-outline-primary btn-sm" style="margin-right:0.5rem;">&#9889; Auto Fill (AI)</button>';
    echo '<span style="margin-left:0.25rem;font-size:0.82rem;color:#9ca3af;">50 credits (&frac12;&cent;)</span>';
    echo '<span id="autofillStatus" style="margin-left:0.75rem;font-size:0.88rem;color:#6b7280;"></span>';
    echo '</div>';

    echo '<div style="margin-top:1.25rem;">';
    echo '<button type="submit" class="btn btn-primary">Save Support Record</button>';
    echo '</div>';

    echo '</form>';
    echo html_writer::end_div(); // info-card

    // =====================================================================
    // SAVED RECORDS — loaded from the server, most recent first.
    // =====================================================================
    echo html_writer::start_div('info-card', ['style' => 'margin-top:1.5rem;']);
    echo html_writer::tag('h4', 'Saved Support Records for ' . s($selectedname));
    echo html_writer::tag('p',
        'These records are stored securely on the server and retained as the per-student evidence trail for Standards 2.3–2.6. They are visible to authorised staff and auditors.',
        ['style' => 'font-size:0.88rem;color:#6b7280;']);

    $notes = $DB->get_records('local_rtocompliance_supportnotes',
        ['studentid' => $studentid], 'timecreated DESC');

    if (!$notes) {
        echo html_writer::tag('p', 'No records saved yet for this student.',
            ['style' => 'color:#6b7280;font-style:italic;']);
    } else {
        $usercache = [];
        echo '<table style="width:100%;border-collapse:collapse;font-size:0.92rem;margin-top:0.5rem;">';
        echo '<thead><tr style="background:#f3f4f6;text-align:left;">';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;white-space:nowrap;">Date</th>';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Type</th>';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Detail</th>';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Outcome</th>';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Recorded by</th>';
        echo '<th style="padding:8px 10px;border-bottom:2px solid #e5e7eb;">Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($notes as $note) {
            if (!array_key_exists($note->recordedby, $usercache)) {
                $usercache[$note->recordedby] = $DB->get_record('user',
                    ['id' => $note->recordedby], '*', IGNORE_MISSING);
            }
            $rbname = $usercache[$note->recordedby]
                ? fullname($usercache[$note->recordedby]) : 'Unknown user';

            $typelabel = isset($NOTETYPES[$note->notetype]) ? $NOTETYPES[$note->notetype] : $note->notetype;
            $outlabel  = ($note->outcomestatus !== null && isset($OUTCOMES[$note->outcomestatus]))
                ? $OUTCOMES[$note->outcomestatus] : '—';

            $detailhtml = format_text($note->detail, FORMAT_PLAIN);
            if ($note->category) {
                $detailhtml = '<div style="font-size:0.82rem;color:#6b7280;margin-bottom:0.2rem;">'
                    . s($note->category) . '</div>' . $detailhtml;
            }
            if ($note->actiontaken) {
                $detailhtml .= '<div style="margin-top:0.4rem;font-size:0.86rem;color:#374151;"><strong>Action:</strong> '
                    . format_text($note->actiontaken, FORMAT_PLAIN) . '</div>';
            }
            if ((int)$note->confidential === 1) {
                $detailhtml .= '<div style="margin-top:0.3rem;"><span style="font-size:0.72rem;background:#fee2e2;color:#b91c1c;padding:1px 6px;border-radius:4px;">Confidential</span></div>';
            }

            echo '<tr style="border-bottom:1px solid #e5e7eb;vertical-align:top;">';
            echo '<td style="padding:8px 10px;white-space:nowrap;">' . s(userdate($note->timecreated, '%d %b %Y')) . '</td>';
            echo '<td style="padding:8px 10px;">' . s($typelabel) . '</td>';
            echo '<td style="padding:8px 10px;color:#1f2937;">' . $detailhtml . '</td>';
            echo '<td style="padding:8px 10px;white-space:nowrap;">' . s($outlabel) . '</td>';
            echo '<td style="padding:8px 10px;white-space:nowrap;">' . s($rbname) . '</td>';
            echo '<td style="padding:8px 6px;white-space:nowrap;">';
            echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/student_support_input.php'))->out() . '" onsubmit="return confirm(\'Delete this support record permanently?\');" style="display:inline;">';
            echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="studentid" value="' . (int)$studentid . '">';
            echo '<input type="hidden" name="noteid" value="' . (int)$note->id . '">';
            echo '<button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo html_writer::end_div();
}

// Related.
echo html_writer::start_div('', ['style' => 'margin-top:2rem;padding:1.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;']);
echo html_writer::tag('h4', 'Related pages', ['style' => 'margin:0 0 0.75rem;color:#0369a1;']);
echo '<div style="display:flex;flex-wrap:wrap;gap:0.75rem;">';
echo '<a href="' . (new moodle_url('/local/rtocompliance/student_support.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Support</a>';
echo '<a href="' . (new moodle_url('/local/rtocompliance/students.php'))->out() . '" class="btn btn-outline-primary btn-sm">Student Records</a>';
echo '</div>';
echo html_writer::end_div();

echo html_writer::end_div(); // compliance-container
?>
<script>
(function () {
    'use strict';

    // ---------------------------------------------------------------
    // AI Auto-Fill — calls /api/rto/ai-support-autofill (50 credits).
    // Drafts ASQA-compliant text and drops it into the detail field.
    // Trainer reviews and edits before saving to the server.
    // ---------------------------------------------------------------
    var _rtocCfg = document.getElementById('rtocSupportInputConfig');
    if (!_rtocCfg) { return; }
    var API_KEY  = _rtocCfg.getAttribute('data-api-key')  || '';
    var API_BASE = _rtocCfg.getAttribute('data-api-base') || 'https://lms-labs.com';
    var STUDENT  = _rtocCfg.getAttribute('data-student-name') || '';

    function stripMd(s) {
        return String(s)
            .replace(/#{1,6}\s*/g, '')
            .replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1')
            .replace(/_{1,3}([^_]+)_{1,3}/g, '$1')
            .replace(/`{1,3}[^`]*`{1,3}/g, '')
            .replace(/^[-*+]\s+/gm, '')
            .replace(/^\d+\.\s+/gm, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function autoFillSupport() {
        var llnLevel = document.getElementById('llnLevel').value;
        var risk     = document.getElementById('risk').value;
        var status   = document.getElementById('autofillStatus');

        if (!API_KEY) {
            status.textContent = 'API key not configured — check Plugin Settings.';
            status.style.color = '#b91c1c';
            setTimeout(function () { status.textContent = ''; }, 5000);
            return;
        }

        var btn = document.getElementById('autoFillBtn');
        btn.disabled    = true;
        btn.textContent = 'Generating…';
        status.textContent = 'Calling AI (50 credits)…';
        status.style.color  = '#6b7280';

        fetch(API_BASE + '/api/rto/ai-support-autofill', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
            body: JSON.stringify({
                apiKey:      API_KEY,
                studentName: STUDENT,
                llnLevel:    llnLevel,
                riskLevel:   risk
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled    = false;
            btn.textContent = '⚡ Auto Fill (AI)';
            if (!data.success) {
                status.textContent = data.error || 'Auto-fill failed. Please try again.';
                status.style.color  = '#b91c1c';
                setTimeout(function () { status.textContent = ''; }, 8000);
                return;
            }
            var f = data.fields || {};
            var labels = {
                lln:           'LLN observations & support (Standard 2.3)',
                adjustments:   'Reasonable adjustments (Standard 2.4)',
                referrals:     'Support service referrals',
                interventions: 'Intervention strategies',
                diversity:     'Diversity & inclusion considerations (Standard 2.5)',
                wellbeing:     'Wellbeing notes (Standard 2.6)'
            };
            var parts = [];
            ['lln','adjustments','referrals','interventions','diversity','wellbeing'].forEach(function (k) {
                if (f[k]) { parts.push(labels[k] + ':\n' + stripMd(f[k])); }
            });
            if (parts.length) {
                document.getElementById('detail').value = parts.join('\n\n');
            }

            var credMsg = '';
            if (data.creditsRemaining !== undefined && data.creditsRemaining !== -1) {
                credMsg = ' • ' + data.creditsRemaining + ' credits remaining';
            } else if (data.creditsRemaining === -1) {
                credMsg = ' • Unlimited credits';
            }
            status.textContent = '50 credits used' + credMsg + ' — review and edit before saving.';
            status.style.color  = '#166534';
            setTimeout(function () { status.textContent = ''; }, 8000);
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = '⚡ Auto Fill (AI)';
            status.textContent = 'Connection error. Check your internet connection and try again.';
            status.style.color  = '#b91c1c';
            setTimeout(function () { status.textContent = ''; }, 6000);
        });
    }

    var afBtn = document.getElementById('autoFillBtn');
    if (afBtn) { afBtn.addEventListener('click', autoFillSupport); }
})();
</script>
<?php
echo $OUTPUT->footer();
