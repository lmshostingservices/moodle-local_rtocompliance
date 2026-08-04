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

// PDF report for a completed pre-enrolment suitability review.
// AUDIT-REWRITE (v4.2.47): provides downloadable evidence for ASQA audit
// of Standard 2 PI 2(a) & 2(b) — structured review + system-generated
// decision + documented advice.  Trainer override, if applied, is also
// shown on the report.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/pdflib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$suit    = $DB->get_record('local_rtocompliance_suitability', ['id' => $id], '*', MUST_EXIST);
$student = core_user::get_user($suit->userid, '*', MUST_EXIST);
$tas     = $DB->get_record('local_rtocompliance_tas', ['id' => $suit->tasid], '*', MUST_EXIST);
$rtoname = get_config('local_rtocompliance', 'rtoname') ?: 'Your RTO';
$rtocode = get_config('local_rtocompliance', 'rtocode') ?: '';

$qualLabels = [
    'school' => 'Schooling only', 'none' => 'None / not stated',
    'cert1' => 'Certificate I', 'cert2' => 'Certificate II',
    'cert3' => 'Certificate III', 'cert4' => 'Certificate IV',
    'diploma' => 'Diploma', 'advdiploma' => 'Advanced Diploma',
    'bachelor' => 'Bachelor degree or higher',
];
$schoolLabels = [
    'year9' => 'Year 9 or below', 'year10' => 'Year 10', 'year11' => 'Year 11',
    'year12' => 'Year 12', 'other' => 'Other / overseas equivalent',
];
$digitalLabels = [
    'email' => 'Email', 'upload' => 'File upload', 'forms' => 'Online forms',
    'video' => 'Video calls', 'browse' => 'Internet research',
];
$outcomeLabels = [
    'pending'                          => 'Pending Student Response',
    'submitted'                        => 'Submitted — Awaiting Trainer Review',
    'suitable'                         => 'SUITABLE',
    'suitable_with_support'            => 'SUITABLE WITH SUPPORT',
    'not_suitable'                     => 'NOT SUITABLE',
    'override_suitable'                => 'SUITABLE (Trainer Override)',
    'override_suitable_with_support'   => 'SUITABLE WITH SUPPORT (Trainer Override)',
    'override_not_suitable'            => 'NOT SUITABLE (Trainer Override)',
];

$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('RTO Compliance');
$pdf->SetAuthor($rtoname);
$pdf->SetTitle('Pre-Enrolment Suitability Review Report - ' . fullname($student));
$pdf->SetMargins(15, 18, 15);
$pdf->SetAutoPageBreak(true, 18);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// Header
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 7, 'Pre-Enrolment Suitability Review Report', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetTextColor(90, 90, 90);
$pdf->Cell(0, 5, $rtoname . ($rtocode ? '  (RTO ' . $rtocode . ')' : ''), 0, 1, 'L');
$pdf->Cell(0, 5, 'Standards for RTOs 2025 - Standard 2, PI 2(a) & 2(b)', 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);
$pdf->SetDrawColor(180, 180, 180);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(4);

// Helper to render a labelled row.
$row = function (string $label, string $value) use ($pdf) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(60, 6, $label, 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(120, 6, $value, 0, 'L');
};

// Student & course summary
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Student & Course', 0, 1, 'L');
$pdf->Ln(1);
$row('Student name:',   fullname($student));
$row('Student ID:',     'STU-' . str_pad((string)$student->id, 6, '0', STR_PAD_LEFT));
$row('Student email:',  $student->email);
$row('Qualification:',  $tas->qualificationcode . ' - ' . $tas->qualificationname);
$row('Date submitted:', $suit->timecompleted ? userdate($suit->timecompleted) : 'Not yet submitted');
$row('Report generated:', userdate(time()));
$pdf->Ln(4);

// Detect which form version was used.
// FIX-MAY2026-PDF-DETECT (v4.4.48): broaden new-form detection to catch records
// where digital_literacy is empty but other new-form columns (lln_evidence,
// prior_skills, declaration_name) are set, or where the trainer has already
// moved the status beyond 'submitted' (suitable, not_suitable, etc.).
$newFormStatuses = ['submitted', 'suitable', 'suitable_with_support', 'not_suitable',
                    'override_suitable', 'override_suitable_with_support', 'override_not_suitable'];
$isNewFormPdf = !empty($suit->digital_literacy)
             || !empty($suit->lln_evidence)
             || !empty($suit->prior_skills)
             || !empty($suit->declaration_name)
             || in_array($suit->status, $newFormStatuses, true);

if ($isNewFormPdf) {
    // ── v4.4.42 Student Eligibility Checklist sections ──
    $dlLabelsPdf = [
        'basic'    => 'Unable to use basic computer systems',
        'limited'  => 'Limited skills in using a computer',
        'adequate' => 'Adequate skills in using a computer',
        'strong'   => 'Strong skills in using a computer',
    ];
    $psLabelsPdf = [
        'none'      => 'No skills or experience in this area',
        'limited'   => 'Limited skills or experience in this area',
        'relevant'  => 'Relevant skills or experience in this area',
        'extensive' => 'Extensive skills or experience in this area',
    ];
    $snLabelsPdf = [
        'lln'       => 'LLN support',
        'digital'   => 'Digital skills support',
        'mentoring' => 'Workplace mentoring',
        'moretime'  => 'More time to complete assessments',
        'flexible'  => 'Flexible study arrangements',
        'english'   => 'English support',
        'disability' => 'Other support relating to a disability',
        'cultural'  => 'Cultural or language support',
        'carer'     => 'Carer / family responsibilities',
        'other'     => 'Other support needs',
    ];

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 1 - Language, Literacy and Numeracy (LLN)', 0, 1, 'L');
    $pdf->Ln(1);
    if (!empty($suit->req_lln_level)) {
        $row('ACSF level required:', 'Level ' . $suit->req_lln_level);
    }
    $row('LLN self-report:', !empty(trim($suit->lln_evidence ?? '')) ? $suit->lln_evidence : 'Not provided');
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 2 - Digital Literacy', 0, 1, 'L');
    $pdf->Ln(1);
    $row('Digital literacy level:', $dlLabelsPdf[$suit->digital_literacy ?? ''] ?? '-');
    if (!empty(trim($suit->digital_literacy_evidence ?? ''))) {
        $row('Digital evidence:', $suit->digital_literacy_evidence);
    }
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 3 - Prior Skills and Experience', 0, 1, 'L');
    $pdf->Ln(1);
    $row('Prior skills level:', $psLabelsPdf[$suit->prior_skills ?? ''] ?? '-');
    if (!empty(trim($suit->prior_skills_evidence ?? ''))) {
        $row('Prior skills evidence:', $suit->prior_skills_evidence);
    }
    $pdf->Ln(4);

    if (!empty(trim($suit->course_req_note ?? ''))) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Section 4 - Entry Requirements Gap Note', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, $suit->course_req_note, 0, 'L');
        $pdf->Ln(4);
    }

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 5 - Learning Support Needs', 0, 1, 'L');
    $pdf->Ln(1);
    $snSelected = !empty($suit->support_needs) ? json_decode($suit->support_needs, true) : [];
    if (!empty($snSelected)) {
        $snNames = [];
        foreach ($snSelected as $snKey) {
            $snLabel = $snLabelsPdf[$snKey] ?? $snKey;
            if ($snKey === 'disability' && !empty(trim($suit->disability_disclosure ?? ''))) {
                $snLabel .= ': ' . trim($suit->disability_disclosure);
            }
            $snNames[] = $snLabel;
        }
        $row('Support needs disclosed:', implode('; ', $snNames));
    } else {
        $row('Support needs:', 'None disclosed');
    }
    $pdf->Ln(4);

    // FIX-MAY4-SUIT-PDF-DECL (v4.4.43): Student Declaration block was missing
    // from the new-form PDF — trainer viewed it in suitability_view.php but it
    // was never printed, so the audit PDF lacked the signed declaration evidence.
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Student Declaration', 0, 1, 'L');
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->MultiCell(0, 5, 'The student confirmed all of the following statements when submitting this form:', 0, 'L');
    $pdf->Ln(1);
    $declarations = [
        '1. The information provided is true and complete to the best of my knowledge.',
        '2. I understand that providing false or misleading information may result in my enrolment being cancelled or withdrawn.',
        '3. I consent to my training provider collecting and using this information to assess my suitability for enrolment and to keep records as required.',
        '4. I understand that completing this form does not guarantee enrolment.',
        '5. I agree to notify my training provider of any significant changes to my circumstances before my first scheduled training day.',
    ];
    foreach ($declarations as $dec) {
        $pdf->MultiCell(0, 5, '   ' . $dec, 0, 'L');
    }
    $pdf->Ln(2);
    $pdf->SetTextColor(0, 0, 0);
    if (!empty($suit->declaration_name)) {
        $row('Signed by (typed name):', $suit->declaration_name);
        if (!empty($suit->declaration_signed_at)) {
            $row('Signed at:', userdate((int)$suit->declaration_signed_at));
        }
    } else {
        $row('Signature:', 'Not on file');
    }
    $pdf->Ln(4);

} else {
    // ── Old form sections (v4.2.50 - v4.4.41) ──
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 1 - Evidence Provided by Student', 0, 1, 'L');
    $pdf->Ln(1);
    $row('Highest qualification:', $qualLabels[$suit->qualification ?? ''] ?? '-');
    if (!empty(trim($suit->qualification_evidence ?? ''))) {
        $row('Qualification evidence:', $suit->qualification_evidence);
    }
    $row('Highest school level:',  $schoolLabels[$suit->school_level ?? ''] ?? '-');
    if (!empty(trim($suit->school_evidence ?? ''))) {
        $row('School evidence:', $suit->school_evidence);
    }
    if ((int)($suit->experience ?? 0) === 1) {
        $row('Industry experience:', 'Yes - ' . ($suit->experience_years ?? '') . ' years (' . ($suit->industry_type ?? '') . ')');
    } else {
        $row('Industry experience:', 'No');
    }
    $skills = !empty($suit->digital_skills) ? json_decode($suit->digital_skills, true) : [];
    $skillNames = [];
    foreach ($digitalLabels as $k => $l) {
        if (in_array($k, (array)$skills, true)) { $skillNames[] = $l; }
    }
    $row('Digital skills:', empty($skillNames) ? 'None ticked' : implode(', ', $skillNames) . ' (' . count($skillNames) . ' of 5)');
    if (!empty(trim($suit->disability_disclosure ?? ''))) {
        $row('Reasonable adjustment:', $suit->disability_disclosure);
    } else {
        $row('Reasonable adjustment:', 'Nothing disclosed');
    }
    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Section 2 - Language, Literacy & Numeracy (LLN)', 0, 1, 'L');
    $pdf->Ln(1);
    $row('Required for course:',   'ACSF Level ' . ($suit->req_lln_level ?? '3'));
    $row('Student assessed level:', !empty($suit->lln_actual_level) ? 'ACSF Level ' . $suit->lln_actual_level : 'Not yet assessed');
    $row('Required prerequisite:', $qualLabels[$suit->req_prereq ?? 'none'] ?? 'None');
    $pdf->Ln(4);
}

// Section 3 (new) / Section 3 (old) — Outcome/Decision
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, ($isNewFormPdf ? 'Trainer Decision' : 'Section 3 - System Decision'), 0, 1, 'L');
$pdf->Ln(1);
$pdf->SetFont('helvetica', 'B', 12);
$colour = [200, 200, 200];
if (in_array($suit->status, ['suitable', 'override_suitable'])) { $colour = [212, 237, 218]; }
else if (in_array($suit->status, ['suitable_with_support', 'override_suitable_with_support'])) { $colour = [255, 243, 205]; }
else if (in_array($suit->status, ['not_suitable', 'override_not_suitable'])) { $colour = [248, 215, 218]; }
$pdf->SetFillColor($colour[0], $colour[1], $colour[2]);
$pdf->Cell(0, 9, '  ' . ($outcomeLabels[$suit->status] ?? strtoupper($suit->status)), 0, 1, 'L', true);
$pdf->Ln(2);

if ($isNewFormPdf) {
    // New form: show trainer decision details.
    if (!empty($suit->trainer_decision)) {
        $decLabelsPdf = [
            'suitable'              => 'Suitable to Enrol',
            'suitable_with_support' => 'Suitable with Support',
            'not_suitable'          => 'Not Currently Suitable',
        ];
        $row('Decision:', $decLabelsPdf[$suit->trainer_decision] ?? $suit->trainer_decision);
        if (!empty($suit->trainerid)) {
            $trainerUserPdf = core_user::get_user($suit->trainerid);
            $row('Decided by:', $trainerUserPdf ? fullname($trainerUserPdf) : 'Unknown trainer');
        }
        if (!empty($suit->trainer_declared_at)) {
            $row('Decision date:', userdate((int)$suit->trainer_declared_at));
        }
        if (!empty($suit->trainer_justification)) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 5, 'Trainer justification:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $suit->trainer_justification, 0, 'L');
        }
        if (!empty($suit->trainer_advice)) {
            $pdf->Ln(1);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 5, 'Advice provided to student:', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 5, $suit->trainer_advice, 0, 'L');
        }
    } else {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 6, 'Awaiting trainer review — no decision recorded at time of report.', 0, 1, 'L');
    }
} else {
    // Old form: system decision reasons + support + advice + override.
    $reasons = !empty($suit->reasons) ? json_decode($suit->reasons, true) : [];
    $support = !empty($suit->support_required) ? json_decode($suit->support_required, true) : [];

    if (!empty($reasons)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'Reasons:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        foreach ($reasons as $r) {
            $pdf->MultiCell(0, 5, '   - ' . $r, 0, 'L');
        }
        $pdf->Ln(2);
    }
    if (!empty($support)) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'Support / Recommendations:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        foreach ($support as $sp) {
            $pdf->MultiCell(0, 5, '   - ' . $sp, 0, 'L');
        }
        $pdf->Ln(2);
    }

    if (!empty($suit->advice)) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Section 4 - Advice Provided to Student', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, $suit->advice, 0, 'L');
        $pdf->Ln(3);
    }

    if (in_array($suit->status, ['override_suitable', 'override_suitable_with_support', 'override_not_suitable'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Trainer Override Applied', 0, 1, 'L');
        $pdf->Ln(1);
        $overriddenby = $suit->overriddenby ? core_user::get_user($suit->overriddenby) : null;
        $row('Override outcome:', $suit->override_outcome ?? '-');
        $row('Overridden by:',    $overriddenby ? fullname($overriddenby) : 'Unknown');
        $row('Date:',             $suit->overriddentime ? userdate($suit->overriddentime) : '-');
        $row('Reason:',           $suit->overridenotes ?? '-');
        $pdf->Ln(2);
    }
}
$pdf->Ln(3);

// Footer
$pdf->Ln(6);
$pdf->SetDrawColor(180, 180, 180);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(2);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(0, 4, 'This report is the documented evidence of the pre-enrolment suitability review required by Standard 2 PI 2(a) & 2(b).', 0, 1, 'L');
$pdf->Cell(0, 4, $rtoname . ($rtocode ? ' (RTO ' . $rtocode . ')' : '') . ' - generated ' . userdate(time()), 0, 1, 'L');

local_rtocompliance_log_action('suitability_pdf_downloaded', 'suitability', $suit->id);

$filename = 'suitability_' . preg_replace('/[^A-Za-z0-9_]/', '_', fullname($student)) . '_' . date('Ymd', $suit->timecompleted ?: time()) . '.pdf';
$pdf->Output($filename, 'I');
exit;
