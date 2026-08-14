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
 * RTO Compliance plugin — compliance_health.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * COMPLIANCE HEALTH — the flagship "command centre".
 *
 * A strictly READ-ONLY, at-a-glance answer to one question: "are we audit-ready
 * right now?". Every metric is computed defensively (missing table / query error
 * never breaks the page — the count simply falls back to 0), grouped by the
 * Standards for RTOs 2025 Quality Areas, and each row carries a plain-English
 * meaning plus a one-click "fix" link to the page that resolves it.
 *
 * This page performs NO writes to any table.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_compliancehealth');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

// Resolve the page title with a graceful fallback if the lang string is not yet registered.
$healthtitle = 'Compliance Health';
if (get_string_manager()->string_exists('compliancehealth', 'local_rtocompliance')) {
    $healthtitle = get_string('compliancehealth', 'local_rtocompliance');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/rtocompliance/compliance_health.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title($healthtitle);
$PAGE->set_heading($healthtitle);
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ---------------------------------------------------------------------------
//  Defensive metric helpers — READ ONLY. Any failure yields 0, never an error.
// ---------------------------------------------------------------------------

$dbman = $DB->get_manager();

/**
 * Run a counting closure inside a table-existence guard and try/catch so a
 * missing table or malformed query can never break the command centre.
 *
 * @param array $tables    Table names (without prefix) that MUST all exist.
 * @param callable $fn     Closure returning an integer count.
 * @return int
 */
$chc_count = function (array $tables, callable $fn) use ($dbman): int {
    try {
        foreach ($tables as $t) {
            if (!$dbman->table_exists($t)) {
                return 0;
            }
        }
        return (int) $fn();
    } catch (\Throwable $e) {
        return 0;
    }
};

$now = time();
$d90 = 90 * DAYSECS;
$d365 = 365 * DAYSECS;
$currentyear = (int) date('Y');

// == QA1 — Training & Assessment =====================================

// Validations overdue (nextduedate in the past) and due-soon (within 90 days).
$val_overdue = $chc_count(['local_rtocompliance_validations'], function () use ($DB, $now) {
    return $DB->count_records_select('local_rtocompliance_validations',
        'nextduedate IS NOT NULL AND nextduedate > 0 AND nextduedate < ?', [$now]);
});
$val_duesoon = $chc_count(['local_rtocompliance_validations'], function () use ($DB, $now, $d90) {
    return $DB->count_records_select('local_rtocompliance_validations',
        'nextduedate IS NOT NULL AND nextduedate >= ? AND nextduedate < ?', [$now, $now + $d90]);
});

// Active training products on scope with NO completed validation ever (advisory).
$quals_novalidation = $chc_count(['local_rtocompliance_qualbuilder', 'local_rtocompliance_validations'],
    function () use ($DB) {
        $sql = "SELECT COUNT(q.id)
                  FROM {local_rtocompliance_qualbuilder} q
                 WHERE q.status = 'active'
                   AND NOT EXISTS (
                       SELECT 1 FROM {local_rtocompliance_validations} v
                        WHERE v.productcode = q.qualificationcode
                          AND v.status = 'completed')";
        return $DB->count_records_sql($sql);
    });

// == QA2 — Student Support ===========================================

// Incomplete AVETMISS student profiles.
$students_incomplete = $chc_count(['local_rtocompliance_students'], function () use ($DB) {
    return $DB->count_records('local_rtocompliance_students', ['profilecomplete' => 0]);
});

// Open complaints (not resolved/closed/withdrawn) and those past their target resolution date.
$complaints_open = $chc_count(['local_rtocompliance_complaints'], function () use ($DB) {
    list($insql, $params) = $DB->get_in_or_equal(['resolved', 'closed', 'withdrawn'], SQL_PARAMS_QM, 'param', false);
    return $DB->count_records_select('local_rtocompliance_complaints', "status $insql", $params);
});
$complaints_overdue = $chc_count(['local_rtocompliance_complaints'], function () use ($DB, $now) {
    list($insql, $params) = $DB->get_in_or_equal(['resolved', 'closed', 'withdrawn'], SQL_PARAMS_QM, 'param', false);
    $params[] = $now;
    return $DB->count_records_select('local_rtocompliance_complaints',
        "status $insql AND targetresolutiondate IS NOT NULL AND targetresolutiondate > 0 AND targetresolutiondate < ?", $params);
});

// Open appeals (not decided/closed).
$appeals_open = $chc_count(['local_rtocompliance_appeals'], function () use ($DB) {
    list($insql, $params) = $DB->get_in_or_equal(['decided', 'closed'], SQL_PARAMS_QM, 'param', false);
    return $DB->count_records_select('local_rtocompliance_appeals', "status $insql", $params);
});

// == QA3 — Workforce =================================================

// Working-towards trainers past their 2-year TAE deadline (Std 3.2).
$trainers_wtoverdue = $chc_count(['local_rtocompliance_trainers'], function () use ($DB, $now) {
    return $DB->count_records_select('local_rtocompliance_trainers',
        'taecredential = ? AND wtdeadline IS NOT NULL AND wtdeadline > 0 AND wtdeadline < ?',
        ['Working Towards', $now]);
});

// Trainers with stale (or missing) industry currency — older than ~12 months.
$trainers_stalecurrency = $chc_count(['local_rtocompliance_trainers'], function () use ($DB, $now, $d365) {
    return $DB->count_records_select('local_rtocompliance_trainers',
        "status <> 'inactive' AND (industrycurrencydate IS NULL OR industrycurrencydate = 0 OR industrycurrencydate < ?)",
        [$now - $d365]);
});

// == QA4 — Governance & Quality ======================================

// Quality Indicator survey response rate for the current year.
$survey_total = $chc_count(['local_rtocompliance_surveys'], function () use ($DB, $currentyear) {
    return $DB->count_records('local_rtocompliance_surveys', ['year' => $currentyear]);
});
$survey_completed = $chc_count(['local_rtocompliance_surveys'], function () use ($DB, $currentyear) {
    return $DB->count_records('local_rtocompliance_surveys', ['year' => $currentyear, 'status' => 'completed']);
});
$survey_rate = $survey_total > 0 ? (int) round($survey_completed / $survey_total * 100) : 0;

// Open risks, and open risks that are high-severity (likelihood x impact >= 15).
$risks_open = $chc_count(['local_rtocompliance_risks'], function () use ($DB) {
    return $DB->count_records('local_rtocompliance_risks', ['status' => 'open']);
});
$risks_high = $chc_count(['local_rtocompliance_risks'], function () use ($DB) {
    return $DB->count_records_select('local_rtocompliance_risks',
        "status = 'open' AND (likelihood * impact) >= 15");
});

// == Certification & Data ============================================

// Students with recorded results (an enrolment) but an UNVERIFIED USI — blocked from cert issuance.
$usi_blocked = $chc_count(['local_rtocompliance_students', 'local_rtocompliance_enrolments'],
    function () use ($DB) {
        $sql = "SELECT COUNT(DISTINCT s.id)
                  FROM {local_rtocompliance_students} s
                  JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
                 WHERE s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified = 0";
        return $DB->count_records_sql($sql);
    });

// Certificates issued (informational, always green).
$certs_issued = $chc_count(['local_rtocompliance_certs'], function () use ($DB) {
    return $DB->count_records('local_rtocompliance_certs', ['status' => 'issued']);
});

// Students with recorded results but no issued certificate (informational).
$students_nocert = $chc_count(['local_rtocompliance_students', 'local_rtocompliance_enrolments', 'local_rtocompliance_certs'],
    function () use ($DB) {
        $sql = "SELECT COUNT(DISTINCT s.id)
                  FROM {local_rtocompliance_students} s
                  JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
                 WHERE NOT EXISTS (
                       SELECT 1 FROM {local_rtocompliance_certs} c
                        WHERE c.userid = s.userid AND c.status = 'issued')";
        return $DB->count_records_sql($sql);
    });

// PRE-ENROLMENT READINESS (v5.9.423): students with recorded results (training under
// way) for whom no completed suitability review exists — i.e. the Standard 2 pre-enrolment
// suitability assessment was not evidenced before training began.
$preenrol_gap = $chc_count(['local_rtocompliance_students', 'local_rtocompliance_enrolments', 'local_rtocompliance_suitability'],
    function () use ($DB) {
        $sql = "SELECT COUNT(DISTINCT s.id)
                  FROM {local_rtocompliance_students} s
                  JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
                 WHERE NOT EXISTS (
                       SELECT 1 FROM {local_rtocompliance_suitability} su
                        WHERE su.userid = s.userid
                          AND su.timecompleted IS NOT NULL AND su.timecompleted > 0)";
        return $DB->count_records_sql($sql);
    });

// ---------------------------------------------------------------------------
//  Assemble metric rows. status: good | warn | alert | info. key: counts toward score.
// ---------------------------------------------------------------------------

$url = function ($file) {
    return (new moodle_url('/local/rtocompliance/' . $file))->out();
};

$areas = [];

// QA1
$areas[] = [
    'title' => 'Quality Area 1 — Training & Assessment',
    'ref' => 'Std 1.1–1.5',
    'color' => 'blue',
    'rows' => [
        [
            'label' => 'Validations overdue',
            'value' => $val_overdue,
            'status' => $val_overdue > 0 ? 'alert' : ($val_duesoon > 0 ? 'warn' : 'good'),
            'meaning' => $val_overdue > 0
                ? 'Assessment validation is past its scheduled due date — a direct Std 1.5 finding.'
                : ($val_duesoon > 0
                    ? $val_duesoon . ' validation(s) fall due within the next 90 days — schedule them now.'
                    : 'Every training product is inside its validation cycle.'),
            'fix' => $url('validation.php'),
            'fixlabel' => 'Open validation schedule',
            'key' => true,
        ],
        [
            'label' => 'Products never validated',
            'value' => $quals_novalidation,
            'status' => $quals_novalidation > 0 ? 'warn' : 'good',
            'meaning' => $quals_novalidation > 0
                ? $quals_novalidation . ' active product(s) on scope have no completed validation on record (advisory).'
                : 'Every active product on scope has at least one completed validation.',
            'fix' => $url('validation.php'),
            'fixlabel' => 'Plan a validation',
            'key' => false,
        ],
    ],
];

// QA2
$areas[] = [
    'title' => 'Quality Area 2 — Student Support',
    'ref' => 'Std 2.1–2.8',
    'color' => 'teal',
    'rows' => [
        [
            'label' => 'Incomplete AVETMISS profiles',
            'value' => $students_incomplete,
            'status' => $students_incomplete > 0 ? 'warn' : 'good',
            'meaning' => $students_incomplete > 0
                ? $students_incomplete . ' student profile(s) are missing mandatory AVETMISS fields — they will fail NAT submission.'
                : 'All student profiles have their mandatory AVETMISS fields completed.',
            'fix' => $url('students.php'),
            'fixlabel' => 'Complete student profiles',
            'key' => true,
        ],
        [
            'label' => 'Pre-enrolment suitability not evidenced',
            'value' => $preenrol_gap,
            'status' => $preenrol_gap > 0 ? 'warn' : 'good',
            'meaning' => $preenrol_gap > 0
                ? $preenrol_gap . ' student(s) with recorded results have no completed suitability review — the Standard 2 pre-enrolment assessment was not evidenced before training. Each student profile shows a pre-enrolment readiness panel.'
                : 'Every student with results has a completed pre-enrolment suitability review on file.',
            'fix' => $url('suitability_bulk.php'),
            'fixlabel' => 'Send suitability reviews',
            'key' => true,
        ],
        [
            'label' => 'Open complaints',
            'value' => $complaints_open,
            'status' => $complaints_overdue > 0 ? 'alert' : ($complaints_open > 0 ? 'warn' : 'good'),
            'meaning' => $complaints_overdue > 0
                ? $complaints_overdue . ' open complaint(s) are past their target resolution date — a Std 2.7 breach.'
                : ($complaints_open > 0
                    ? $complaints_open . ' complaint(s) are still open and within timeframe — keep them moving.'
                    : 'No open complaints on the register.'),
            'fix' => $url('complaints.php'),
            'fixlabel' => 'Open complaints register',
            'key' => true,
        ],
        [
            'label' => 'Open appeals',
            'value' => $appeals_open,
            'status' => $appeals_open > 0 ? 'warn' : 'good',
            'meaning' => $appeals_open > 0
                ? $appeals_open . ' appeal(s) are lodged and not yet decided or closed.'
                : 'No appeals are currently awaiting a decision.',
            'fix' => $url('complaints.php'),
            'fixlabel' => 'Open appeals register',
            'key' => true,
        ],
    ],
];

// QA3
$areas[] = [
    'title' => 'Quality Area 3 — VET Workforce',
    'ref' => 'Std 3.1–3.3',
    'color' => 'purple',
    'rows' => [
        [
            'label' => 'Working-towards past TAE deadline',
            'value' => $trainers_wtoverdue,
            'status' => $trainers_wtoverdue > 0 ? 'alert' : 'good',
            'meaning' => $trainers_wtoverdue > 0
                ? $trainers_wtoverdue . ' trainer(s) working towards their TAE have passed the 2-year deadline (Std 3.2).'
                : 'No working-towards trainer has exceeded the 2-year TAE deadline.',
            'fix' => $url('supervision.php'),
            'fixlabel' => 'Review supervision & TAE',
            'key' => true,
        ],
        [
            'label' => 'Stale industry currency',
            'value' => $trainers_stalecurrency,
            'status' => $trainers_stalecurrency > 0 ? 'warn' : 'good',
            'meaning' => $trainers_stalecurrency > 0
                ? $trainers_stalecurrency . ' active trainer(s) have industry currency older than 12 months or none recorded.'
                : 'All active trainers have industry currency evidence within the last 12 months.',
            'fix' => $url('trainers.php'),
            'fixlabel' => 'Update trainer currency',
            'key' => true,
        ],
    ],
];

// QA4
$areas[] = [
    'title' => 'Quality Area 4 — Governance & Quality',
    'ref' => 'Std 4.1–4.3',
    'color' => 'amber',
    'rows' => [
        [
            'label' => 'QI survey response rate (' . $currentyear . ')',
            'value' => $survey_rate . '%',
            'status' => $survey_total > 0 ? ($survey_rate >= 30 ? 'good' : 'warn') : 'warn',
            'meaning' => $survey_total > 0
                ? $survey_completed . ' of ' . $survey_total . ' invited responses completed this year' .
                    ($survey_rate < 30 ? ' — below the 30% guide.' : ' — healthy engagement.')
                : 'No Quality Indicator surveys recorded for this year yet.',
            'fix' => $url('qi_report.php'),
            'fixlabel' => 'Open QI report',
            'key' => true,
        ],
        [
            'label' => 'Open risks',
            'value' => $risks_open,
            'status' => $risks_high > 0 ? 'alert' : ($risks_open > 0 ? 'warn' : 'good'),
            'meaning' => $risks_high > 0
                ? $risks_high . ' of ' . $risks_open . ' open risk(s) are high-severity (likelihood x impact >= 15).'
                : ($risks_open > 0
                    ? $risks_open . ' risk(s) are open on the register and being managed.'
                    : 'No open risks on the register.'),
            'fix' => $url('risk.php'),
            'fixlabel' => 'Open risk register',
            'key' => true,
        ],
    ],
];

// Certification & Data
$areas[] = [
    'title' => 'Certification & Data Integrity',
    'ref' => 'AVETMISS · USI · Testamurs',
    'color' => 'rose',
    'rows' => [
        [
            'label' => 'Certs blocked by unverified USI',
            'value' => $usi_blocked,
            'status' => $usi_blocked > 0 ? 'alert' : 'good',
            'meaning' => $usi_blocked > 0
                ? $usi_blocked . ' student(s) with results have a USI that is not yet verified — you cannot issue their certificate.'
                : 'No student with results is blocked by an unverified USI.',
            'fix' => $url('students.php'),
            'fixlabel' => 'Verify student USIs',
            'key' => true,
        ],
        [
            'label' => 'Awaiting certificate',
            'value' => $students_nocert,
            'status' => 'info',
            'meaning' => $students_nocert > 0
                ? $students_nocert . ' student(s) with recorded results do not yet have an issued certificate (informational).'
                : 'Every student with results has an issued certificate.',
            'fix' => $url('certificates.php'),
            'fixlabel' => 'Open certificates',
            'key' => false,
        ],
        [
            'label' => 'Certificates issued',
            'value' => $certs_issued,
            'status' => 'info',
            'meaning' => 'Total testamurs, statements and records currently issued and active.',
            'fix' => $url('certificates.php'),
            'fixlabel' => 'Open certificates',
            'key' => false,
        ],
    ],
];

// ---------------------------------------------------------------------------
//  Audit-readiness score — of the KEY checks, how many are currently clear (green).
// ---------------------------------------------------------------------------

$keytotal = 0;
$keyclear = 0;
foreach ($areas as $area) {
    foreach ($area['rows'] as $row) {
        if (!empty($row['key'])) {
            $keytotal++;
            if ($row['status'] === 'good') {
                $keyclear++;
            }
        }
    }
}
$score = $keytotal > 0 ? (int) round($keyclear / $keytotal * 100) : 100;

if ($score >= 90) {
    $verdict = 'On track';
    $verdictclass = 'good';
    $verdictblurb = 'You are audit-ready. Keep the cadence going.';
} else if ($score >= 60) {
    $verdict = 'Needs attention';
    $verdictclass = 'warn';
    $verdictblurb = 'Mostly in shape — clear the flagged items before your next audit window.';
} else {
    $verdict = 'Action required';
    $verdictclass = 'alert';
    $verdictblurb = 'Several audit-critical checks are open. Prioritise the red items below.';
}

// Ring geometry.
$ringradius = 84;
$ringcirc = 2 * M_PI * $ringradius;
$ringoffset = $ringcirc * (1 - ($score / 100));

// Status pill labels.
$pilllabels = ['good' => 'Clear', 'warn' => 'Watch', 'alert' => 'Action', 'info' => 'Info'];
// Plain-English tooltip for each status pill — teaches a first-time user what the colour means.
$pilltips = [
    'good' => 'Clear: this check passes right now, nothing to do.',
    'warn' => 'Watch: not urgent, but review this soon before it becomes a problem.',
    'alert' => 'Action: an auditor could raise this today. Fix it as a priority.',
    'info' => 'Info: a background figure for context, no action needed.',
];

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header($healthtitle);
echo local_rtocompliance_page_banner($healthtitle);
?>
<style>
.chc-wrap{max-width:1200px;margin:0 auto;padding:4px 2px 48px;color:#0f172a;}
.chc-lead{color:#64748b;font-size:14px;margin:0 0 22px;}

/* Hero */
.chc-hero{display:flex;flex-wrap:wrap;gap:28px;align-items:center;background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#334155 100%);border-radius:18px;padding:28px 32px;color:#fff;margin-bottom:26px;box-shadow:0 18px 40px -18px rgba(15,23,42,.6);}
.chc-ring{position:relative;flex:0 0 200px;width:200px;height:200px;}
.chc-ring svg{transform:rotate(-90deg);}
.chc-ring-num{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.chc-ring-num b{font-size:52px;line-height:1;font-weight:800;letter-spacing:-1px;}
.chc-ring-num span{font-size:12px;letter-spacing:.14em;text-transform:uppercase;opacity:.7;margin-top:6px;}
.chc-hero-body{flex:1;min-width:260px;}
.chc-hero-body h2{margin:0 0 4px;font-size:13px;letter-spacing:.16em;text-transform:uppercase;color:#94a3b8;font-weight:700;}
.chc-verdict{display:inline-flex;align-items:center;gap:9px;font-size:27px;font-weight:800;margin:2px 0 10px;letter-spacing:-.3px;}
.chc-dot{width:15px;height:15px;border-radius:50%;box-shadow:0 0 0 5px rgba(255,255,255,.12);}
.chc-verdict.good .chc-dot{background:#34d399;}.chc-verdict.warn .chc-dot{background:#fbbf24;}.chc-verdict.alert .chc-dot{background:#fb7185;}
.chc-blurb{color:#cbd5e1;font-size:14.5px;max-width:560px;line-height:1.5;margin:0 0 16px;}
.chc-chips{display:flex;flex-wrap:wrap;gap:10px;}
.chc-chip{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:6px 13px;font-size:12.5px;font-weight:600;color:#e2e8f0;}
.chc-chip b{font-weight:800;}
.chc-chip .cdot{width:9px;height:9px;border-radius:50%;}
.cdot.good{background:#34d399;}.cdot.warn{background:#fbbf24;}.cdot.alert{background:#fb7185;}

/* Grid of Quality-Area cards */
.chc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px;}
.chc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 1px 2px rgba(15,23,42,.04);}
.chc-card-head{padding:14px 18px;color:#fff;display:flex;align-items:baseline;justify-content:space-between;gap:10px;}
.chc-card-head .ct{font-weight:700;font-size:14.5px;line-height:1.25;}
.chc-card-head .cr{font-size:11.5px;font-weight:600;opacity:.85;white-space:nowrap;}
.chc-blue .chc-card-head{background:#2563eb;}
.chc-teal .chc-card-head{background:#0d9488;}
.chc-purple .chc-card-head{background:#7c3aed;}
.chc-amber .chc-card-head{background:#d97706;}
.chc-rose .chc-card-head{background:#e11d48;}
.chc-rows{padding:6px 4px 10px;}
.chc-row{display:flex;gap:14px;align-items:flex-start;padding:13px 15px;border-top:1px solid #f1f5f9;}
.chc-row:first-child{border-top:none;}
.chc-num{flex:0 0 62px;text-align:center;}
.chc-num b{display:block;font-size:26px;font-weight:800;line-height:1;letter-spacing:-.5px;color:#0f172a;}
.chc-num.good b{color:#059669;}.chc-num.warn b{color:#d97706;}.chc-num.alert b{color:#e11d48;}.chc-num.info b{color:#475569;}
.chc-body{min-width:0;flex:1;}
.chc-rowtop{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:3px;}
.chc-label{font-weight:700;font-size:13.5px;color:#111827;}
.chc-pill{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:2px 9px;border-radius:999px;}
.chc-pill.good{background:#d1fae5;color:#065f46;}
.chc-pill.warn{background:#fef3c7;color:#92400e;}
.chc-pill.alert{background:#ffe4e6;color:#9f1239;}
.chc-pill.info{background:#e2e8f0;color:#334155;}
.chc-mean{font-size:12.5px;color:#64748b;line-height:1.45;margin:0 0 7px;}
.chc-fix{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#4338ca;text-decoration:none;}
.chc-fix:hover{text-decoration:underline;color:#3730a3;}
.chc-fix svg{width:13px;height:13px;}
.chc-foot{color:#94a3b8;font-size:11.5px;margin-top:22px;text-align:center;}
.chc-accent{display:inline-block;width:5px;height:5px;border-radius:50%;background:currentColor;margin-left:1px;}
@media (max-width:640px){.chc-hero{padding:22px;}.chc-ring{flex-basis:160px;width:160px;height:160px;}}
</style>
<div class="chc-wrap">
  <p class="chc-lead">A live, read-only snapshot of your audit readiness across the Standards for RTOs 2025 Quality Areas. Every red item is something an auditor could raise today &mdash; click any fix link to resolve it.</p>

  <div class="chc-hero">
    <div class="chc-ring">
      <svg width="200" height="200" viewBox="0 0 200 200">
        <circle cx="100" cy="100" r="<?php echo $ringradius; ?>" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="16"/>
        <circle cx="100" cy="100" r="<?php echo $ringradius; ?>" fill="none"
                stroke="<?php echo $verdictclass === 'good' ? '#34d399' : ($verdictclass === 'warn' ? '#fbbf24' : '#fb7185'); ?>"
                stroke-width="16" stroke-linecap="round"
                stroke-dasharray="<?php echo round($ringcirc, 1); ?>"
                stroke-dashoffset="<?php echo round($ringoffset, 1); ?>"/>
      </svg>
      <div class="chc-ring-num" title="Share of your audit-critical checks that are currently passing. Higher is better; aim for 90 percent or more."><b><?php echo $score; ?>%</b><span>Audit ready</span></div>
    </div>
    <div class="chc-hero-body">
      <h2>Audit readiness</h2>
      <div class="chc-verdict <?php echo $verdictclass; ?>" title="Overall read on how audit-ready you are right now, based on the passing checks below. Green means on track, amber needs attention, red means act now."><span class="chc-dot"></span><?php echo s($verdict); ?></div>
      <p class="chc-blurb"><?php echo s($verdictblurb); ?> <strong><?php echo $keyclear; ?> of <?php echo $keytotal; ?></strong> audit-critical checks are currently clear.</p>
      <div class="chc-chips">
<?php
// Roll-up chips across all key checks.
$sumgood = 0; $sumwarn = 0; $sumalert = 0;
foreach ($areas as $area) {
    foreach ($area['rows'] as $row) {
        if (empty($row['key'])) {
            continue;
        }
        if ($row['status'] === 'good') { $sumgood++; }
        else if ($row['status'] === 'warn') { $sumwarn++; }
        else if ($row['status'] === 'alert') { $sumalert++; }
    }
}
?>
        <span class="chc-chip" title="Number of audit-critical checks that currently pass. Nothing to do on these."><span class="cdot good"></span><b><?php echo $sumgood; ?></b> clear</span>
        <span class="chc-chip" title="Checks that need reviewing soon but are not yet urgent."><span class="cdot warn"></span><b><?php echo $sumwarn; ?></b> to watch</span>
        <span class="chc-chip" title="Checks an auditor could raise today. Fix these first."><span class="cdot alert"></span><b><?php echo $sumalert; ?></b> need action</span>
      </div>
    </div>
  </div>

  <div class="chc-grid">
<?php
foreach ($areas as $area) {
    echo '<div class="chc-card chc-' . s($area['color']) . '">';
    echo '  <div class="chc-card-head"><span class="ct">' . s($area['title']) . '</span><span class="cr">' . s($area['ref']) . '</span></div>';
    echo '  <div class="chc-rows">';
    foreach ($area['rows'] as $row) {
        $st = $row['status'];
        $pill = $pilllabels[$st] ?? 'Info';
        echo '<div class="chc-row">';
        echo '  <div class="chc-num ' . $st . '"><b>' . s((string) $row['value']) . '</b></div>';
        echo '  <div class="chc-body">';
        echo '    <div class="chc-rowtop"><span class="chc-label">' . s($row['label']) . '</span>'
            . '<span class="chc-pill ' . $st . '" title="' . s($pilltips[$st] ?? '') . '">' . s($pill) . '</span></div>';
        echo '    <p class="chc-mean">' . s($row['meaning']) . '</p>';
        echo '    <a class="chc-fix" href="' . $row['fix'] . '">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>'
            . s($row['fixlabel']) . '</a>';
        echo '  </div>';
        echo '</div>';
    }
    echo '  </div>';
    echo '</div>';
}
?>
  </div>

  <p class="chc-foot">Read-only overview generated <?php echo userdate($now, get_string('strftimedatetime', 'langconfig')); ?>. Figures refresh each time you load this page.</p>
</div>
<?php
echo $OUTPUT->footer();
