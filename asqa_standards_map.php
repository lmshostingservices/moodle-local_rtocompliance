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
 * RTO Compliance plugin — asqa_standards_map.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * ASQA COMPLIANCE MAPPING (v5.9.388)
 *
 * A self-assurance map: each Standard for RTOs 2025 outcome (Quality Areas 1-4)
 * and the certification/records Compliance Requirements, mapped to the RTO
 * Compliance feature that supports it, with an HONEST coverage status
 * (Covered / Partial / Gap). This page is itself Standard 4.3/4.4 self-assurance
 * evidence — it is deliberately not an "all green" marketing sheet; Partial and
 * Gap items name the remaining work so the RTO can see and close them.
 *
 * Obligation wording is summarised in plain English; confirm exact clause
 * numbering against the legislative instrument (F2025L00354 Outcome Standards
 * and F2025L00355 Compliance Requirements) before quoting in a formal submission.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_asqamap');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/rtocompliance/asqa_standards_map.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('asqamap', 'local_rtocompliance'));
$PAGE->set_heading(get_string('asqamap', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ── The map ──────────────────────────────────────────────────────────────────
// status: 'covered' | 'partial' | 'gap'
$u = function ($p) { return (new moodle_url('/local/rtocompliance/' . $p))->out(); };

$areas = [
    [
        'code' => 'QA1', 'title' => 'Quality Area 1 — Training & Assessment', 'color' => 'blue',
        'rows' => [
            ['1.1', 'Training & assessment strategies and practices meet the training product and learner needs',
                'Training Strategies (TAS) + Qualification Builder + Packaging Rules validator', 'tas.php', 'covered', ''],
            ['1.2', 'Industry engagement informs training and assessment',
                'Industry engagement evidence trail (dated, with impact + evidence upload)', 'tas.php', 'covered', ''],
            ['1.3', 'Facilities, resources and equipment are fit for purpose',
                'Documented in the TAS resources section', 'tas.php', 'partial', 'No dedicated resource register — captured as TAS narrative.'],
            ['1.4', 'Assessment system reflects the principles of assessment and rules of evidence',
                'Assessment via Moodle; outcomes flow to the results register', 'qualbuilder_results.php', 'partial', 'Assessment tools are delivered in Moodle; the plugin governs outcomes, not tool design.'],
            ['1.5', 'Assessment is systematically validated',
                'Validation Register — with validator-independence declaration + 5-year cycle & overdue/coverage tracking', 'validation.php', 'covered', 'Independence + five-year register added in v5.9.388.'],
            ['1.6', 'Assessment is conducted in accordance with the assessment system',
                'Results register records the outcome, assessor and date per unit', 'qualbuilder_results.php', 'partial', 'Conduct evidence lives in Moodle; the register holds outcomes.'],
            ['1.7', 'Recognition — RPL, credit transfer and mutual recognition',
                'RPL & Credit Transfer register — assessor identity + TAE-currency check, evidence-to-criteria matrix, superseded→current unit mapping, outcome-communicated, wired to outcomes (RPL 51 / CT 60) and NAT', 'rpl.php', 'covered', 'CT source authentication enforced (v5.9.419); assessor identity, evidence matrix & superseded mapping added (v5.9.422–424).'],
            ['1.8', 'Assessment outcomes and student records are accurate and complete',
                'Master Roster + AVETMISS/NAT export (RPL/CT & reconciled results now included)', 'qualbuilder_results.php', 'covered', 'NAT under-reporting fixed in v5.9.387.'],
        ],
    ],
    [
        'code' => 'QA2', 'title' => 'Quality Area 2 — VET Student Support', 'color' => 'teal',
        'rows' => [
            ['2.1', 'Clear, accurate, current information & transparency before enrolment (training, ALL fees, obligations)',
                'Marketing Information — pre-enrolment disclosure register (13 mandatory items + where provided), retained pre-payment fee/obligation acknowledgement, and materials review date', 'marketing_info.php', 'covered', 'Disclosure register + retained pre-payment acknowledgement added in v5.9.428.'],
            ['2.2', 'Pre-enrolment review of suitability (LLN + digital literacy)',
                'Suitability assessment (ACSF levels, digital literacy, prior skills) + per-student pre-enrolment readiness panel and a Compliance Health gap metric', 'suitability_form.php', 'covered', 'Readiness consolidation (suitability + declaration + USI + information) added in v5.9.423.'],
            ['2.3', 'Training support services determined and made available per student',
                'Student Support — per-student support records (server-side, retained & auditable)', 'student_support_input.php', 'covered', 'Moved out of browser storage into the database in v5.9.388.'],
            ['2.4', 'Reasonable adjustments; voluntary, privacy-protected disability disclosure',
                'Support records (reasonable-adjustment type), marked confidential', 'student_support_input.php', 'covered', 'A dedicated sensitive-data capability is a scheduled hardening item.'],
            ['2.5', 'Safe, inclusive and culturally safe learning environment',
                'Largely organisational; the system does not obstruct (accessible UI)', 'student_support.php', 'partial', 'Primarily a policy/operational obligation.'],
            ['2.6', 'Wellbeing needs identified and services advised',
                'Student Support — wellbeing records per student/cohort', 'student_support_input.php', 'covered', ''],
            ['2.7', 'Complaints — all parties, procedural fairness, timeframes, escalation, communicated outcomes',
                'Complaints & Appeals register', 'complaints.php', 'partial', 'Respondent/decision-maker/outcome-communicated fields + audit logging are scheduled.'],
            ['2.8', 'Appeals — adverse decisions, independent review at no/low cost, record correction',
                'Complaints & Appeals register', 'complaints.php', 'partial', 'Reviewer independence + upheld-appeal record correction are scheduled.'],
        ],
    ],
    [
        'code' => 'QA3', 'title' => 'Quality Area 3 — VET Workforce', 'color' => 'purple',
        'rows' => [
            ['3.1', 'Trainers & assessors hold the required credentials, vocational competencies and current industry skills',
                'Trainers register + Vocational Competency + TAE credential tracking', 'trainers.php', 'covered', ''],
            ['3.2', 'Currency and professional development maintained; "working towards" is supervised & time-bound',
                'Trainer Currency + Supervision — working-towards 2-year deadline enforced, unqualified sign-off blocked', 'supervision.php', 'covered', 'Working-towards 2-year enforcement added in v5.9.388.'],
            ['3.3', 'Delivery under supervision is appropriately supervised and quality-assured',
                'Supervision Log — supervisor must hold a full credential', 'supervision.php', 'covered', ''],
        ],
    ],
    [
        'code' => 'QA4', 'title' => 'Quality Area 4 — Governance', 'color' => 'slate',
        'rows' => [
            ['4.1', 'Effective governance; accountable executive; fit & proper persons',
                'Leadership & Governance register', 'governance.php', 'covered', ''],
            ['4.2', 'Risk is identified and managed',
                'Risk Management register', 'risk.php', 'covered', ''],
            ['4.3', 'Compliance and self-assurance systems monitor performance',
                'This ASQA Compliance Mapping + Validation + Audit Log', 'auditlog.php', 'covered', ''],
            ['4.4', 'Continuous improvement informed by data, including the Quality Indicators',
                'Surveys & Quality Indicators — AQTF Learner/Employer Questionnaires + QI Annual Summary Report', 'qi_report.php', 'covered', 'Real AQTF LQ/EQ + QISR added in v5.9.388.'],
        ],
    ],
    [
        'code' => 'CR', 'title' => 'Compliance Requirements — Certification, Records, USI & Privacy', 'color' => 'green',
        'rows' => [
            ['Cert', 'Issue AQF certification only to assessed-competent students, within 30 days, with a verified USI',
                'Qualification Certificate Hub — verified-USI gate on every issuance path', 'qual_cert_hub.php', 'covered', 'Verified-USI gate added in v5.9.387; automatic 30-day overdue tracking is scheduled.'],
            ['Rec', '30-year retention of certification records; students (incl. former) can access their documents',
                'Issued Certificates register + student certificate portal', 'certificates.php', 'covered', ''],
            ['NAT', 'AVETMISS / NAT reporting to NCVER is accurate and complete',
                'AVETMISS Export (RPL, credit transfer & reconciled results now included)', 'natexport.php', 'covered', ''],
            ['USI', 'USI collected/verified per the Student Identifiers Act; personal data handled per privacy law',
                'USI Verification + privacy provider', 'usi_settings.php', 'partial', 'Sensitive-data access scoping + complaint/appeal privacy linkage are scheduled.'],
            ['Enrol', 'The system must not corrupt authoritative Moodle enrolment/completion data',
                'Enrolment/completion writes fully removed — the plugin only reads them', 'data_import.php', 'covered', 'All enrolment/completion write paths hard-disabled in v5.9.387.'],
        ],
    ],
];

// Tally.
$counts = ['covered' => 0, 'partial' => 0, 'gap' => 0];
foreach ($areas as $a) { foreach ($a['rows'] as $r) { $counts[$r[4]]++; } }
$total = array_sum($counts);

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('asqamap', 'local_rtocompliance'));
echo local_rtocompliance_page_banner(get_string('asqamap', 'local_rtocompliance'));

$badge = function ($status) {
    $map = [
        'covered' => ['Covered', '#059669', '#d1fae5', '#065f46'],
        'partial' => ['Partial', '#d97706', '#fef3c7', '#92400e'],
        'gap'     => ['Gap',     '#e11d48', '#ffe4e6', '#9f1239'],
    ];
    $m = $map[$status] ?? $map['gap'];
    return '<span style="display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;background:' . $m[2] . ';color:' . $m[3] . ';border:1px solid ' . $m[1] . '33;white-space:nowrap;">' . $m[0] . '</span>';
};
$headcolor = ['blue' => '#2563eb', 'teal' => '#0d9488', 'purple' => '#7c3aed', 'slate' => '#475569', 'green' => '#059669'];
?>
<style>
.amap-wrap{max-width:1180px;margin:0 auto;padding:4px 2px 48px;}
.amap-lead{color:#475569;font-size:14px;line-height:1.55;margin:0 0 18px;}
.amap-summary{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 26px;}
.amap-stat{flex:1 1 150px;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;background:#fff;}
.amap-stat .n{font-size:26px;font-weight:800;line-height:1;}
.amap-stat .l{font-size:12px;color:#64748b;margin-top:5px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;}
.amap-section{margin-bottom:22px;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.03);}
.amap-head{padding:13px 20px;font-weight:700;font-size:15.5px;color:#fff;display:flex;align-items:center;gap:10px;}
.amap-head .tag{background:rgba(255,255,255,.22);font-size:12px;font-weight:700;padding:2px 8px;border-radius:6px;}
.amap-row{display:grid;grid-template-columns:64px 1fr 108px;gap:14px;padding:14px 20px;border-top:1px solid #f1f5f9;align-items:start;}
.amap-row:first-child{border-top:none;}
.amap-ref{font-weight:800;color:#0f172a;font-size:13.5px;}
.amap-oblig{font-size:13.5px;color:#0f172a;font-weight:600;line-height:1.4;}
.amap-feat{font-size:12.5px;color:#334155;margin-top:4px;line-height:1.45;}
.amap-feat a{color:#2563eb;font-weight:600;text-decoration:none;}
.amap-feat a:hover{text-decoration:underline;}
.amap-note{font-size:11.5px;color:#94a3b8;margin-top:4px;font-style:italic;line-height:1.4;}
.amap-status{text-align:right;padding-top:2px;}
.amap-foot{font-size:11.5px;color:#94a3b8;margin-top:8px;line-height:1.5;}
@media(max-width:640px){.amap-row{grid-template-columns:52px 1fr;}.amap-status{grid-column:2;text-align:left;margin-top:2px;}}
</style>
<div class="amap-wrap">
  <p class="amap-lead">How each outcome of the <strong>Standards for RTOs 2025</strong> (in force 1 July 2025) is supported by RTO Compliance. Statuses are honest self-assurance signals — <strong>Covered</strong>, <strong>Partial</strong> (supported, with a named improvement in progress), or <strong>Gap</strong> — so this page doubles as Standard 4.3/4.4 self-assurance evidence rather than a marketing sheet.</p>
  <div class="amap-summary">
    <div class="amap-stat"><div class="n" style="color:#0f172a;"><?php echo $total; ?></div><div class="l">Outcomes mapped</div></div>
    <div class="amap-stat"><div class="n" style="color:#059669;"><?php echo $counts['covered']; ?></div><div class="l">Covered</div></div>
    <div class="amap-stat"><div class="n" style="color:#d97706;"><?php echo $counts['partial']; ?></div><div class="l">Partial</div></div>
    <div class="amap-stat"><div class="n" style="color:#e11d48;"><?php echo $counts['gap']; ?></div><div class="l">Gap</div></div>
  </div>
<?php
foreach ($areas as $area) {
    $hc = $headcolor[$area['color']] ?? '#475569';
    echo '<div class="amap-section">';
    echo '<div class="amap-head" style="background:' . $hc . ';"><span class="tag">' . s($area['code']) . '</span>' . s($area['title']) . '</div>';
    foreach ($area['rows'] as $r) {
        list($ref, $oblig, $feat, $page, $status, $note) = $r;
        echo '<div class="amap-row">';
        echo '<div class="amap-ref">' . s($ref) . '</div>';
        echo '<div>';
        echo '<div class="amap-oblig">' . s($oblig) . '</div>';
        echo '<div class="amap-feat">' . s($feat) . ' &nbsp;·&nbsp; <a href="' . $u($page) . '">Open →</a></div>';
        if ($note !== '') {
            echo '<div class="amap-note">' . s($note) . '</div>';
        }
        echo '</div>';
        echo '<div class="amap-status">' . $badge($status) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}
?>
  <p class="amap-foot">Obligation wording is summarised in plain English. Confirm exact clause numbering against the legislative instruments (F2025L00354 Outcome Standards; F2025L00355 Compliance Requirements) before quoting in a formal ASQA submission. "Partial" items reflect improvements scheduled in the current remediation plan.</p>
</div>
<?php
echo $OUTPUT->footer();
