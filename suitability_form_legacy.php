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
 * RTO Compliance plugin — suitability_form_legacy.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// LEGACY pre-enrolment suitability form — preserved verbatim for v4.2.46
// and earlier records that still have rows in
// local_rtocompliance_suitability_answers.  New sends from v4.2.47 use the
// evidence-based flow in suitability_form.php.  This file is included by
// suitability_form.php only when $isLegacy is true.
//
// Variables expected in scope: $suit, $student, $tas, $rtoname,
// $legacyAnswers (the answer rows), and $OUTPUT/$DB/$PAGE.

defined('MOODLE_INTERNAL') || die();

// FIX-MAY2-LEGACY-FATAL (v4.2.56): the admin-notification helper below
// was previously defined at the bottom of this file inside an
// if (!function_exists()) guard.  PHP does NOT hoist function definitions
// declared inside any conditional block, so the call at line ~55 of the
// POST handler hit "Call to undefined function
// local_rtocompliance_notify_admin_failure()" — fatal.  Moving the same
// guarded definition ABOVE the POST handler ensures the function is
// in scope by the time the form is submitted.  Body is unchanged.
if (!function_exists('local_rtocompliance_notify_admin_failure')) {
    function local_rtocompliance_notify_admin_failure(object $student, object $tas, object $suit, $answers, array $answervals, array $evidencevals = [], int $disabilityIdx = -1): void {
        global $CFG;
        $admin    = get_admin();
        $rtoname  = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';
        $viewurl  = $CFG->wwwroot . '/local/rtocompliance/suitability_view.php?id=' . $suit->id;
        $noreply  = core_user::get_noreply_user();

        $rows = [];
        foreach ($answers as $idx => $a) {
            $v = $answervals[$a->id] ?? null;
            if ($idx === $disabilityIdx) {
                if ($v === 1) { $rows[] = ['DISABILITY DISCLOSED', $a->question, $evidencevals[$a->id] ?? '']; }
            } else if ($v === 0 || $v === 2) {
                $label = ($v === 2) ? 'UNSURE' : 'NO';
                $rows[] = [$label, $a->question, $evidencevals[$a->id] ?? ''];
            }
        }

        $subject = 'SUITABILITY (legacy): ' . fullname($student) . ' – ' . $tas->qualificationcode;
        $body  = "A legacy suitability checklist has been submitted.\n\n";
        $body .= "Student: " . fullname($student) . " <" . $student->email . ">\n";
        $body .= "Qualification: " . $tas->qualificationcode . " " . $tas->qualificationname . "\n";
        $body .= "Date: " . userdate(time()) . "\n\n";
        if (!empty($rows)) {
            $body .= "ITEMS REQUIRING REVIEW:\n";
            foreach ($rows as $r) {
                $body .= "  [" . $r[0] . "] " . $r[1] . "\n";
                if ($r[2] !== '') { $body .= "    Student explanation: " . $r[2] . "\n"; }
            }
        }
        $body .= "\nReview here: " . $viewurl . "\n\n" . $rtoname;
        email_to_user($admin, $noreply, $subject, $body);
    }
}

$answers = $legacyAnswers;

$errors      = [];
$answervals  = [];
$evidencevals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($answers as $a) {
        $val = optional_param('q_' . $a->id, '', PARAM_ALPHANUM);
        $ev  = trim(optional_param('e_' . $a->id, '', PARAM_TEXT));
        if ($val !== '0' && $val !== '1' && $val !== '2') {
            $errors[] = 'Please answer all questions.';
            break;
        }
        $answervals[$a->id]   = (int)$val;
        $evidencevals[$a->id] = substr($ev, 0, 2000);
    }
    if (!optional_param('declaration', '', PARAM_RAW)) { // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
        $errors[] = 'You must tick the declaration before submitting.';
    }
    if (empty($errors)) {
        $answersList    = array_values($answers);
        $totalQuestions = count($answersList);
        $disabilityIdx  = $totalQuestions - 1;
        $allCriticalYes = true;
        $disabilityFlag = false;
        foreach ($answersList as $idx => $a) {
            $a->answer   = $answervals[$a->id];
            $a->evidence = $evidencevals[$a->id] ?: null;
            $DB->update_record('local_rtocompliance_suitability_answers', $a);
            if ($idx === $disabilityIdx) {
                if ($a->answer === 1) { $disabilityFlag = true; }
            } else if ($a->answer !== 1) {
                $allCriticalYes = false;
            }
        }
        $suit->status        = $allCriticalYes ? 'suitable' : 'not_suitable';
        $suit->timecompleted = time();
        $suit->timemodified  = time();
        $DB->update_record('local_rtocompliance_suitability', $suit);

        if (!$allCriticalYes || $disabilityFlag) {
            local_rtocompliance_notify_admin_failure($student, $tas, $suit, $answersList, $answervals, $evidencevals, $disabilityIdx);
        }

        $msg = $allCriticalYes
            ? 'Thank you, ' . s($student->firstname) . '. You have confirmed that you meet all entry requirements for ' . s($tas->qualificationcode . ' ' . $tas->qualificationname) . '. Your training provider will be in touch to finalise your enrolment.'
            : 'Thank you, ' . s($student->firstname) . '. Your checklist has been received. You indicated that you may not meet one or more entry requirements. Your training provider has been notified and will contact you to discuss your options.';
        $cls = $allCriticalYes ? 'rtoc-suit-success' : 'rtoc-suit-review';
        $iconCls = $allCriticalYes ? 'Y' : '!';
        $title = $allCriticalYes ? 'You are Suitable for Enrolment' : 'Checklist Requires Review';
        echo html_writer::start_div('rtoc-suit-container');
        echo html_writer::start_div('rtoc-suit-card ' . $cls);
        echo html_writer::tag('div', $iconCls, ['class' => 'rtoc-suit-icon rtoc-suit-icon-' . ($allCriticalYes ? 'success' : 'review')]);
        echo html_writer::tag('h2', $title, ['class' => 'rtoc-suit-title']);
        echo html_writer::tag('p', $msg, ['class' => 'rtoc-suit-desc']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        return;
    }
}

echo '
<style>
.rtoc-suit-container { max-width: 720px; margin: 32px auto; padding: 0 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #222; }
.rtoc-suit-card { background: #fff; border: 1px solid #d0d4d9; border-radius: 6px; padding: 32px 36px; margin-bottom: 24px; }
.rtoc-suit-header { border-bottom: 2px solid #0069d9; padding-bottom: 18px; margin-bottom: 24px; }
.rtoc-suit-header h1 { font-size: 1.35rem; color: #1a1a2e; margin: 0 0 4px; }
.rtoc-suit-header p { color: #555; font-size: 0.9rem; margin: 0; }
.rtoc-suit-intro { background: #eef5ff; border-left: 4px solid #0069d9; padding: 12px 16px; border-radius: 4px; margin-bottom: 22px; font-size: 0.92rem; line-height: 1.5; }
.rtoc-suit-question { border: 1px solid #e2e6ea; border-radius: 6px; padding: 14px 18px; margin-bottom: 12px; background: #fcfcfd; }
.rtoc-suit-question-num { font-size: 0.72rem; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
.rtoc-suit-question-text { font-size: 0.95rem; color: #222; font-weight: 500; margin-bottom: 10px; }
.rtoc-suit-radio-group { display: flex; gap: 8px; flex-wrap: wrap; }
.rtoc-suit-radio-label { display: flex; align-items: center; gap: 6px; padding: 6px 14px; border: 2px solid #dee2e6; border-radius: 4px; cursor: pointer; font-size: 0.9rem; min-width: 72px; justify-content: center; }
.rtoc-suit-evidence { display: none; margin-top: 10px; }
.rtoc-suit-question[data-show-evidence="1"] .rtoc-suit-evidence { display: block; }
.rtoc-suit-evidence textarea { width: 100%; min-height: 64px; border: 1px solid #ced4da; border-radius: 4px; padding: 8px 10px; font: inherit; }
.rtoc-suit-confirm { margin: 22px 0 12px; padding: 14px 18px; background: #f7f9fc; border-radius: 4px; }
.rtoc-suit-confirm label { display: flex; align-items: flex-start; gap: 10px; font-size: 0.92rem; cursor: pointer; }
.rtoc-suit-submit { text-align: right; }
.rtoc-suit-submit button { background: #0069d9; color: #fff; border: none; padding: 11px 28px; border-radius: 4px; font-size: 0.98rem; font-weight: 600; cursor: pointer; }
.rtoc-suit-error-msg { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; padding: 12px 14px; margin-bottom: 18px; font-size: 0.9rem; }
</style>
';

echo html_writer::start_div('rtoc-suit-container');
echo html_writer::start_div('rtoc-suit-card');
echo html_writer::start_div('rtoc-suit-header');
echo html_writer::tag('h1', 'Pre-Enrolment Suitability Checklist (legacy)');
echo html_writer::tag('p', 'Student: <strong>' . s(fullname($student)) . '</strong>');
echo html_writer::tag('p', 'Qualification: ' . s($tas->qualificationcode . ' ' . $tas->qualificationname));
echo html_writer::end_div();

echo html_writer::start_div('rtoc-suit-intro');
echo '<p>This checklist was sent before the new evidence-based review was rolled out.  Please complete it as you would have, and your training provider will follow up.</p>';
echo html_writer::end_div();

if (!empty($errors)) {
    echo html_writer::tag('div', end($errors), ['class' => 'rtoc-suit-error-msg']);
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'novalidate' => 'novalidate']);

$total = count($answers);
$i = 1;
foreach ($answers as $a) {
    $prevval     = isset($answervals[$a->id]) ? $answervals[$a->id] : ($a->answer ?? null);
    $prevevidence = $evidencevals[$a->id] ?? ($a->evidence ?? '');
    $showev = ($prevval === 1) ? '1' : '0';

    echo html_writer::start_div('rtoc-suit-question', ['data-show-evidence' => $showev]);
    echo html_writer::tag('div', 'Requirement ' . $i . ' of ' . $total, ['class' => 'rtoc-suit-question-num']);
    echo html_writer::tag('div', s($a->question), ['class' => 'rtoc-suit-question-text']);

    echo html_writer::start_div('rtoc-suit-radio-group');
    $yc = ($prevval === 1) ? ' checked' : '';
    $uc = ($prevval === 2) ? ' checked' : '';
    $nc = ($prevval === 0) ? ' checked' : '';
    echo '<label class="rtoc-suit-radio-label"><input type="radio" name="q_' . $a->id . '" value="1"' . $yc . ' required> Yes</label>';
    echo '<label class="rtoc-suit-radio-label"><input type="radio" name="q_' . $a->id . '" value="2"' . $uc . '> Unsure</label>';
    echo '<label class="rtoc-suit-radio-label"><input type="radio" name="q_' . $a->id . '" value="0"' . $nc . '> No</label>';
    echo html_writer::end_div();

    echo html_writer::start_div('rtoc-suit-evidence');
    echo '<label for="e_' . $a->id . '">If you selected Yes, please list any evidence (optional):</label>';
    echo '<textarea id="e_' . $a->id . '" name="e_' . $a->id . '" rows="2" maxlength="2000">' . s($prevevidence) . '</textarea>';
    echo html_writer::end_div();

    echo html_writer::end_div();
    $i++;
}

echo html_writer::start_div('rtoc-suit-confirm');
echo '<label><input type="checkbox" name="declaration" value="1" required> I confirm that my responses above are true and accurate to the best of my knowledge.</label>';
echo html_writer::end_div();

echo html_writer::start_div('rtoc-suit-submit');
echo '<button type="submit">Submit Checklist</button>';
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::tag('p', s($rtoname), ['style' => 'text-align:center;color:#888;font-size:0.82rem;margin-top:18px']);
echo html_writer::end_div();

echo html_writer::script('
(function () {
    var qs = document.querySelectorAll(".rtoc-suit-question");
    qs.forEach(function (q) {
        q.querySelectorAll("input[type=radio]").forEach(function (r) {
            r.addEventListener("change", function () {
                q.setAttribute("data-show-evidence", (r.value === "1") ? "1" : "0");
            });
        });
    });
})();
');

// (v4.2.56) admin-notification helper definition was MOVED to the top of
// this file (above the POST handler) so it is in scope when called.
