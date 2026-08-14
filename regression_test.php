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
 * RTO Compliance plugin — regression_test.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// Complaint Student Acceptance Test Engine  (local_rtocompliance v5.9.127)
//
// READ-ONLY.  Never modifies Moodle state.  Safe to run on production.
//
// PURPOSE
// -------
// Final sign-off tool: compares the client's confirmed expected enrolment state
// against NAT data, current Moodle enrolments, and the reconciler's decisions.
//
// Four data sources per unit/course:
//   1. Client Expected — what the RTO admin has confirmed should happen
//   2. NAT             — what the AVETMISS import file says
//   3. Current Moodle  — Active (visible) / Archived (hidden) / Not enrolled
//   4. Reconciler      — KEEP / ADD / REMOVE / POST-IMPORT / REVIEW
//
// PASS criteria per expected course pattern:
//   Expected Active   → Moodle=Active AND Reconciler=KEEP
//   Expected Archived → Reconciler NOT KEEP (REMOVE or already gone)
//
// Every future release should show 5/5 PASS before shipping.
// ─────────────────────────────────────────────────────────────────────────────

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$PAGE->set_url(new moodle_url('/local/rtocompliance/regression_test.php'));
$PAGE->set_title('Complaint Student Acceptance Tests');
$PAGE->set_heading('Complaint Student Acceptance Tests');

admin_externalpage_setup('local_rtocompliance_regression_test');
// ACCESS (v6.3.8): admin_externalpage_setup() above already enforces the capability this
// page is registered with in settings.php. Stating it explicitly keeps the requirement
// visible in this file instead of only in the settings registration — same check, no cost.
require_capability('local/rtocompliance:manage', context_system::instance());
require_login();

// ─── Helper functions — same algorithm as reconcile.php ──────────────────────

if (!function_exists('_rt_h')) {
    function _rt_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('_rt_extract_unitcode')) {
    function _rt_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
        $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
        $idn = strtoupper(trim($idnumber));
        if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) return $idn;
        if (preg_match($pat, $idn, $m)) return $m[1];
        if (preg_match($pat, strtoupper(trim($shortname)), $m)) return $m[1];
        if (preg_match($pat, strtoupper(trim($fullname)),  $m)) return $m[1];
        return '';
    }
}

if (!function_exists('_rt_delivery_key')) {
    function _rt_delivery_key(string $ddmmyyyy): string {
        $ddmmyyyy = trim($ddmmyyyy);
        if (strlen($ddmmyyyy) !== 8 || !ctype_digit($ddmmyyyy)) return '';
        $month = (int)substr($ddmmyyyy, 2, 2);
        $year  = substr($ddmmyyyy, 4, 4);
        if ($month < 1 || $month > 12) return '';
        return $year . '-' . ($month <= 6 ? 'S1' : 'S2');
    }
}

if (!function_exists('_rt_course_delivery_key')) {
    function _rt_course_delivery_key(string $shortname, string $catname = ''): string {
        foreach ([$catname, $shortname] as $src) {
            if ($src === '') continue;
            if (preg_match('/Semester\s+([12])\s+(\d{4})/i', $src, $m)) return $m[2] . '-S' . $m[1];
            if (preg_match('/(\d{4})\s+Semester\s+([12])/i',  $src, $m)) return $m[1] . '-S' . $m[2];
            if (preg_match('/\b(\d{2})S([12])\b/i', $src, $m)) {
                $yr = (int)$m[1] < 50 ? '20' . $m[1] : '19' . $m[1];
                return $yr . '-S' . $m[2];
            }
            if (preg_match('/\bS([12])(\d{2})\b/i', $src, $m)) {
                $yr = (int)$m[2] < 50 ? '20' . $m[2] : '19' . $m[2];
                return $yr . '-S' . $m[1];
            }
        }
        return '';
    }
}

if (!function_exists('_rt_matches_pattern')) {
    function _rt_matches_pattern(string $shortname, string $pattern): bool {
        $normSn  = preg_replace('/[\s\-_]/', '', strtoupper($shortname));
        $normPat = preg_replace('/[\s\-_]/', '', strtoupper($pattern));
        return $normPat !== '' && strpos($normSn, $normPat) === 0;
    }
}

// ─── Pattern-level query helpers ─────────────────────────────────────────────

/**
 * Find the unit code of the first Moodle course that matches the pattern.
 * Returns '' if no course found or no unit code extractable.
 */
if (!function_exists('_rt_pat_nat_unit')) {
    function _rt_pat_nat_unit(string $pattern, array $courseDetail, array $courseToUnit): string {
        foreach ($courseDetail as $cid => $cd) {
            if (_rt_matches_pattern($cd->shortname, $pattern)) {
                $uc = $courseToUnit[$cid] ?? '';
                if ($uc !== '') return $uc;
            }
        }
        return '';
    }
}

/**
 * Find the reconciler verdict for the first course matching the pattern.
 * Buckets checked in priority order: KEEP > REMOVE > ADD > POST-IMPORT > REVIEW.
 */
if (!function_exists('_rt_pat_reconciler')) {
    function _rt_pat_reconciler(
        string $pattern, int $uid,
        array $keepEnrolments, array $removeEnrolments,
        array $addEnrolments, array $postImportEnrolments, array $reviewEnrolments,
        array $courseDetail
    ): string {
        $buckets = [
            'KEEP'        => $keepEnrolments[$uid]        ?? [],
            'REMOVE'      => $removeEnrolments[$uid]      ?? [],
            'ADD'         => $addEnrolments[$uid]         ?? [],
            'POST-IMPORT' => $postImportEnrolments[$uid]  ?? [],
            'REVIEW'      => $reviewEnrolments[$uid]      ?? [],
        ];
        foreach ($buckets as $verdict => $cidMap) {
            foreach (array_keys($cidMap) as $cid) {
                $cd = $courseDetail[$cid] ?? null;
                if ($cd && _rt_matches_pattern($cd->shortname, $pattern)) return $verdict;
            }
        }
        return '—';
    }
}

// ─── Hard-coded acceptance test cases ────────────────────────────────────────
// Client-confirmed expected state for the five complaint students.
// expect_active   → patterns for courses the student SHOULD be actively enrolled in (KEEP)
// expect_archived → patterns for courses the student SHOULD NOT be in KEEP (old semester)
$regressionCases = [
    [
        'name'            => 'Luke Skidmore',
        'uid'             => 15452,
        'clientid'        => '8424',
        'expect_active'   => ['OPF 26S1', 'VL1 26S1', 'CL1 26S1', 'ADC 26S1'],
        'expect_archived' => ['MGC 25S2', 'CP1 25S2'],
    ],
    [
        'name'            => 'Rowan Bridge',
        'uid'             => 15137,
        'clientid'        => '8189',
        'expect_active'   => ['ADC 26S1', 'CP2 26S1'],
        'expect_archived' => ['DPR 25S1', 'CP1 25S1', 'ACF 25S1', 'OPF 25S2', 'VL1 25S2', 'MGC 25S2'],
    ],
    [
        'name'            => 'Ria Dsouza',
        'uid'             => 15187,
        'clientid'        => '8234',
        'expect_active'   => ['CP2 26S1', 'DIT 26S1', 'OPF 26S1', 'ADC 26S1', 'VL2 26S1', 'DPR 26S1'],
        'expect_archived' => ['VL1 25S2', 'CL1 25S2', 'OPF 25S2', 'CP1 25S1', 'MGC 25S1', 'ACF 25S1', 'DPR 25S1'],
    ],
    [
        'name'            => 'Claire Escoto',
        'uid'             => 15304,
        'clientid'        => '8320',
        'expect_active'   => ['VL1 26S1', 'CL1 26S1', 'ADC 26S1', 'OPF 26S1'],
        'expect_archived' => ['ACF 25S2', 'CP1 25S2', 'DPR 25S2', 'MGC 25S2'],
    ],
    [
        'name'            => 'Nathan Yim',
        'uid'             => 15247,
        'clientid'        => '8290',
        'expect_active'   => ['DIT 26S1', 'CP2 26S1', 'CL1 26S1'],
        'expect_archived' => ['VL1 25S2', 'ADC 25S2', 'DPR 25S2', 'ACF 25S2', 'CP1 25S1', 'MGC 25S1', 'ACF 25S1'],
    ],
];

// ─── Run the acceptance test pipeline ────────────────────────────────────────
set_time_limit(300);

$testClientIds = array_column($regressionCases, 'clientid');
$testUids      = array_column($regressionCases, 'uid');

// Find the latest import containing at least one test student.
[$_tcInsql, $_tcInp] = $DB->get_in_or_equal($testClientIds, SQL_PARAMS_NAMED, 'rtci');
$importForTestId = (int)$DB->get_field_sql(
    "SELECT MAX(importid) FROM {local_rtocompliance_avetmiss_enrolment} WHERE clientid $_tcInsql",
    $_tcInp
);
$importRec = $importForTestId
    ? $DB->get_record('local_rtocompliance_avetmiss', ['id' => $importForTestId], '*', IGNORE_MISSING)
    : null;

// Build lookup maps.
$clientToCase = [];
$uidToCase    = [];
foreach ($regressionCases as $i => $rc) {
    $clientToCase[strtolower((string)$rc['clientid'])] = $i;
    $uidToCase[(int)$rc['uid']] = $i;
}

// ── Step 1: Load NAT00120 staging data ────────────────────────────────────────
$natUnits     = []; // lc_clientid → [UNITCODE => outcome]
$natStartdate = []; // lc_clientid → [UNITCODE => DDMMYYYY]

if ($importForTestId) {
    $_s1rs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_enrolment',
        "importid = :iid AND clientid $_tcInsql",
        array_merge(['iid' => $importForTestId], $_tcInp),
        '',
        'clientid, unitcode, outcome, startdate'
    );
    foreach ($_s1rs as $_nr) {
        $_lc = strtolower(trim((string)$_nr->clientid));
        $_uc = strtoupper(trim((string)$_nr->unitcode));
        if ($_lc === '' || $_uc === '') continue;
        $natUnits[$_lc][$_uc]     = trim((string)($_nr->outcome   ?? ''));
        $natStartdate[$_lc][$_uc] = trim((string)($_nr->startdate ?? ''));
    }
    $_s1rs->close();
}

// ── Step 2: Load Moodle user details ─────────────────────────────────────────
$uidToDetails = [];
if (!empty($testUids)) {
    [$_uidsql, $_uidp] = $DB->get_in_or_equal($testUids, SQL_PARAMS_NAMED, 'rtud');
    $_uRs = $DB->get_recordset_sql(
        "SELECT id, firstname, lastname FROM {user} WHERE id $_uidsql AND deleted = 0",
        $_uidp
    );
    foreach ($_uRs as $_u) {
        $uidToDetails[(int)$_u->id] = $_u;
    }
    $_uRs->close();
}

// ── Step 3: Scan all Moodle courses ──────────────────────────────────────────
$courseToUnit          = []; // courseid → unitcode
$unitToPreferredCid    = []; // unitcode → newest visible courseid
$courseDetail          = []; // courseid → stdClass
$unitDeliveryCourseMap = []; // unitcode → [deliveryKey → courseid]

$_diagNatUcSet = [];
foreach ($natUnits as $_unitMap) {
    foreach (array_keys($_unitMap) as $_uc3) { $_diagNatUcSet[$_uc3] = true; }
}

$_allCrs = $DB->get_recordset_sql(
    "SELECT c.id, c.shortname, c.fullname, c.idnumber, c.category, c.visible,
            cc.name AS catname
       FROM {course} c
       LEFT JOIN {course_categories} cc ON cc.id = c.category
      WHERE c.id <> 1
      ORDER BY c.visible DESC, c.id DESC"
);
foreach ($_allCrs as $_c) {
    $_uc  = _rt_extract_unitcode(
        (string)$_c->idnumber, (string)$_c->shortname, (string)$_c->fullname
    );
    $_cid = (int)$_c->id;
    $courseDetail[$_cid] = (object)[
        'shortname' => (string)$_c->shortname,
        'fullname'  => (string)$_c->fullname,
        'catid'     => (int)$_c->category,
        'catname'   => (string)($_c->catname ?? ''),
        'visible'   => (int)$_c->visible,
    ];
    $courseToUnit[$_cid] = $_uc;
    if ($_uc === '') continue;
    if (!isset($unitToPreferredCid[$_uc])) {
        $unitToPreferredCid[$_uc] = $_cid;
    }
    if (isset($_diagNatUcSet[$_uc])) {
        $_dk = _rt_course_delivery_key(
            (string)$_c->shortname,
            isset($_c->catname) ? (string)$_c->catname : ''
        );
        if ($_dk !== '' && !isset($unitDeliveryCourseMap[$_uc][$_dk])) {
            $unitDeliveryCourseMap[$_uc][$_dk] = $_cid;
        }
    }
}
$_allCrs->close();

// ── Step 4: Load current ACTIVE manual enrolments (for reconciler) ────────────
$currentEnrolments = []; // uid → [courseid => unitcode]
$enrolTimecreated  = []; // uid → [courseid => unix timestamp]

if (!empty($testUids)) {
    [$_uidsql2, $_uidp2] = $DB->get_in_or_equal($testUids, SQL_PARAMS_NAMED, 'rtce');
    $_curRs = $DB->get_recordset_sql(
        "SELECT ue.userid, e.courseid, ue.timecreated
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.enrol = 'manual'
            AND ue.status = 0
            AND ue.userid $_uidsql2",
        $_uidp2
    );
    foreach ($_curRs as $_ce) {
        $_uid4 = (int)$_ce->userid;
        $_cid4 = (int)$_ce->courseid;
        $currentEnrolments[$_uid4][$_cid4] = $courseToUnit[$_cid4] ?? '';
        $enrolTimecreated[$_uid4][$_cid4]  = (int)$_ce->timecreated;
    }
    $_curRs->close();
}

// ── Step 4b: Load ALL enrolments including hidden/archived courses ─────────────
// Used for the enriched Moodle Status column (Active / Archived / Not enrolled).
$allMoodleEnrolCids = []; // uid → [courseid => true]

if (!empty($testUids)) {
    [$_uidsqlB, $_uidpB] = $DB->get_in_or_equal($testUids, SQL_PARAMS_NAMED, 'rtab');
    $_abRs = $DB->get_recordset_sql(
        "SELECT ue.userid, e.courseid
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.enrol = 'manual'
            AND ue.status = 0
            AND ue.userid $_uidsqlB",
        $_uidpB
    );
    foreach ($_abRs as $_ab) {
        $allMoodleEnrolCids[(int)$_ab->userid][(int)$_ab->courseid] = true;
    }
    $_abRs->close();
}

// ── Step 5: KEEP / POST-IMPORT / REMOVE / REVIEW classification ───────────────
$keepEnrolments       = []; // uid → [courseid => true]
$postImportEnrolments = []; // uid → [courseid => true]
$removeEnrolments     = []; // uid → [courseid => true]
$reviewEnrolments     = []; // uid → [courseid => true]
$actualUnitCoverage   = []; // uid → [unitcode => courseid]
$_importTs = $importRec ? (int)$importRec->timecreated : 0;

foreach ($regressionCases as $rc) {
    $_uid5 = (int)$rc['uid'];
    $_lc5  = strtolower((string)$rc['clientid']);
    $_natUnitSet5 = $natUnits[$_lc5] ?? [];
    foreach ($currentEnrolments[$_uid5] ?? [] as $_cid5 => $_uc5) {
        if ($_uc5 !== '' && isset($_natUnitSet5[$_uc5])) {
            $keepEnrolments[$_uid5][$_cid5] = true;
            if (!isset($actualUnitCoverage[$_uid5][$_uc5])) {
                $actualUnitCoverage[$_uid5][$_uc5] = $_cid5;
            }
        } elseif ($_uc5 === '') {
            $reviewEnrolments[$_uid5][$_cid5] = true;
        } else {
            if (($enrolTimecreated[$_uid5][$_cid5] ?? 0) >= $_importTs) {
                $postImportEnrolments[$_uid5][$_cid5] = true;
            } else {
                $removeEnrolments[$_uid5][$_cid5] = true;
            }
        }
    }
}

// ── Step 6: ADD recommendations ───────────────────────────────────────────────
$addEnrolments = []; // uid → [courseid => unitcode]

foreach ($regressionCases as $rc) {
    $_uid6  = (int)$rc['uid'];
    $_lc6   = strtolower((string)$rc['clientid']);
    $_natSet6   = $natUnits[$_lc6] ?? [];
    $_covered6  = $actualUnitCoverage[$_uid6] ?? [];
    foreach (array_keys($_natSet6) as $_uc6) {
        if (isset($_covered6[$_uc6])) continue;
        $_sd6  = $natStartdate[$_lc6][$_uc6] ?? '';
        $_dk6  = _rt_delivery_key($_sd6);
        $_prefCid6 = ($_dk6 !== '') ? ($unitDeliveryCourseMap[$_uc6][$_dk6] ?? null) : null;
        if ($_prefCid6 === null) { $_prefCid6 = $unitToPreferredCid[$_uc6] ?? null; }
        if ($_prefCid6 === null) continue;
        $addEnrolments[$_uid6][$_prefCid6] = $_uc6;
    }
}

// ── Step 7: Build unit → Moodle status map (uses all enrolments incl. hidden) ─
// 'active'     = enrolled in a visible (active) course with this unit code
// 'archived'   = enrolled only in hidden course(s) with this unit code
// 'not_enrolled' = no Moodle enrolment for this unit code at all
$unitMoodleStatusMap = []; // uid → [unitcode → ['status', 'shortname', 'courseid']]

foreach ($regressionCases as $rc) {
    $_uid7  = (int)$rc['uid'];
    $_lc7   = strtolower((string)$rc['clientid']);
    // Check all NAT units for this student.
    foreach (array_keys($natUnits[$_lc7] ?? []) as $_uc7) {
        $status7 = 'not_enrolled';
        $sn7     = '';
        $cid7    = 0;
        foreach ($allMoodleEnrolCids[$_uid7] ?? [] as $_ecid => $_) {
            if (($courseToUnit[$_ecid] ?? '') !== $_uc7) continue;
            $cd7 = $courseDetail[$_ecid] ?? null;
            if (!$cd7) continue;
            $thisStatus = ((int)$cd7->visible === 1) ? 'active' : 'archived';
            if ($status7 === 'not_enrolled' || $thisStatus === 'active') {
                $status7 = $thisStatus;
                $sn7     = $cd7->shortname;
                $cid7    = $_ecid;
                if ($thisStatus === 'active') break; // prefer active; stop searching
            }
        }
        $unitMoodleStatusMap[$_uid7][$_uc7] = [
            'status'    => $status7,
            'shortname' => $sn7,
            'courseid'  => $cid7,
        ];
    }
}

// ─── Evaluate test cases ──────────────────────────────────────────────────────
$results = [];

foreach ($regressionCases as $i => $rc) {
    $_uid   = (int)$rc['uid'];
    $_lc    = strtolower((string)$rc['clientid']);
    $_natOk = !empty($natUnits[$_lc]);

    // ── 4-column comparison rows (one per expected course pattern) ────────────
    $comparisonRows = []; // [pattern, client_expects, nat_unit, nat_present, moodle_status, moodle_sn, reconciler, pass]

    foreach (['active' => $rc['expect_active'], 'archived' => $rc['expect_archived']] as $clientExpects => $patterns) {
        foreach ($patterns as $pat) {
            // NAT: find unit code for this pattern and check if it's in NAT data.
            $natUnit    = _rt_pat_nat_unit($pat, $courseDetail, $courseToUnit);
            $natPresent = ($natUnit !== '') && isset(($natUnits[$_lc] ?? [])[$natUnit]);

            // Moodle status: check all enrolments (including archived courses).
            $moodleStatus = 'not_enrolled';
            $moodleSn     = '';
            foreach ($allMoodleEnrolCids[$_uid] ?? [] as $_pc => $_) {
                $cd = $courseDetail[$_pc] ?? null;
                if (!$cd || !_rt_matches_pattern($cd->shortname, $pat)) continue;
                $thisMs = ((int)$cd->visible === 1) ? 'active' : 'archived';
                if ($moodleStatus === 'not_enrolled' || $thisMs === 'active') {
                    $moodleStatus = $thisMs;
                    $moodleSn     = $cd->shortname;
                    if ($thisMs === 'active') break;
                }
            }

            // Reconciler verdict.
            $reconciler = _rt_pat_reconciler(
                $pat, $_uid,
                $keepEnrolments, $removeEnrolments,
                $addEnrolments, $postImportEnrolments, $reviewEnrolments,
                $courseDetail
            );

            // Pass/fail for this row.
            if ($clientExpects === 'active') {
                $rowPass = ($reconciler === 'KEEP');
            } else {
                $rowPass = ($reconciler !== 'KEEP');
            }

            // Best-matching courseid for logstore history (Step 8).
            $_histCid = null;
            foreach ($courseDetail as $_hcid => $_hcd) {
                if (_rt_matches_pattern($_hcd->shortname, $pat)) { $_histCid = $_hcid; break; }
            }

            $comparisonRows[] = [
                'pattern'       => $pat,
                'client_expects'=> $clientExpects,
                'nat_unit'      => $natUnit,
                'nat_present'   => $natPresent,
                'moodle_status' => $moodleStatus,
                'moodle_sn'     => $moodleSn,
                'reconciler'    => $reconciler,
                'pass'          => $rowPass,
                'courseid'      => $_histCid,
            ];
        }
    }

    // ── Enriched NAT unit trace ───────────────────────────────────────────────
    $natTrace = []; // [unitcode, outcome, delivery_key, expected_course_sn, moodle_status, moodle_sn]

    foreach ($natUnits[$_lc] ?? [] as $_uc => $_outcome) {
        $_sd   = $natStartdate[$_lc][$_uc] ?? '';
        $_dk   = _rt_delivery_key($_sd);
        // Expected course from date-aware map, fallback to newest visible.
        $_expCid = ($_dk !== '') ? ($unitDeliveryCourseMap[$_uc][$_dk] ?? null) : null;
        if ($_expCid === null) $_expCid = $unitToPreferredCid[$_uc] ?? null;
        $_expSn  = ($_expCid && isset($courseDetail[$_expCid])) ? $courseDetail[$_expCid]->shortname : '—';

        $msInfo = $unitMoodleStatusMap[$_uid][$_uc] ?? ['status' => 'not_enrolled', 'shortname' => ''];

        $natTrace[] = [
            'unitcode'        => $_uc,
            'outcome'         => $_outcome,
            'delivery_key'    => $_dk,
            'expected_sn'     => $_expSn,
            'moodle_status'   => $msInfo['status'],
            'moodle_sn'       => $msInfo['shortname'],
        ];
    }

    // Sort: not_enrolled first (most interesting), then archived, then active.
    usort($natTrace, function ($a, $b) {
        $ord = ['not_enrolled' => 0, 'archived' => 1, 'active' => 2];
        return ($ord[$a['moodle_status']] ?? 0) <=> ($ord[$b['moodle_status']] ?? 0);
    });

    // Overall pass: all comparison rows pass.
    $_allPass = !empty($comparisonRows) && array_reduce($comparisonRows, fn($c, $r) => $c && $r['pass'], true);

    $results[$i] = [
        'pass'             => $_allPass,
        'nat_found'        => $_natOk,
        'comparison_rows'  => $comparisonRows,
        'nat_trace'        => $natTrace,
        'keep_count'       => count($keepEnrolments[$_uid] ?? []),
        'remove_count'     => count($removeEnrolments[$_uid] ?? []),
        'add_count'        => count($addEnrolments[$_uid] ?? []),
        'review_count'     => count($reviewEnrolments[$_uid] ?? []),
        'pi_count'         => count($postImportEnrolments[$_uid] ?? []),
        'nat_units'        => count($natUnits[$_lc] ?? []),
    ];
}

$passCount   = count(array_filter($results, fn($r) => $r['pass']));
$totalCount  = count($results);
$overallPass = $passCount === $totalCount;

// ── Helper: classify logstore event array into a human-readable status ────────
// Returns one of: never_enrolled | currently_enrolled | enrolled_then_removed |
//                 restored_after_deletion | multiple_cycles | history_incomplete
function _rt_history_status(array $events): string {
    if (empty($events)) return 'never_enrolled';
    $creates = 0; $deletes = 0; $lastEvt = '';
    foreach ($events as $_hse) {
        if (strpos($_hse['event'], 'enrolment_created') !== false) { $creates++; $lastEvt = 'c'; }
        if (strpos($_hse['event'], 'enrolment_deleted') !== false) { $deletes++; $lastEvt = 'd'; }
    }
    if ($creates === 0 && $deletes > 0) return 'history_incomplete';   // deleted with no create on record
    if ($creates > 1 || $deletes > 1)   return 'multiple_cycles';      // enrolled/removed more than once
    if ($creates > 0 && $deletes === 0) return 'currently_enrolled';   // create but no delete
    if ($lastEvt === 'c')               return 'restored_after_deletion';
    if ($lastEvt === 'd')               return 'enrolled_then_removed';
    return 'never_enrolled';
}

// ── Step 8: Logstore enrolment history for failing ACTIVE patterns ────────────
// Batch-queries mdl_logstore_standard_log for creation + deletion events per
// student+course pair.  Uids chunked in groups of 500 to avoid over-long IN
// clauses on large sites.  Gracefully skips if logstore table is absent.
$logstoreHistory    = []; // uid:cid => [[event, ts, actor], ...] ORDER BY timecreated ASC
$logstoreActorNames = []; // userid => username (resolved after all chunks)
$logstoreAvailable  = false;

try { $logstoreAvailable = $DB->get_manager()->table_exists('logstore_standard_log'); }
catch (Throwable $_ex8) { $logstoreAvailable = false; }

if ($logstoreAvailable) {
    $_ls8Uids = [];
    $_ls8Cids = [];
    foreach ($regressionCases as $i8 => $rc8) {
        $_uid8 = (int)$rc8['uid'];
        foreach ($results[$i8]['comparison_rows'] as $crow8) {
            if (!$crow8['pass'] && $crow8['client_expects'] === 'active' && $crow8['courseid'] !== null) {
                $_ls8Uids[$_uid8]                  = true;
                $_ls8Cids[(int)$crow8['courseid']] = true;
            }
        }
    }
    if (!empty($_ls8Uids) && !empty($_ls8Cids)) {
        $_actorSet8 = [];
        $_cids8All  = array_keys($_ls8Cids);
        // Chunk uids into groups of 500 to keep queries within safe length limits.
        foreach (array_chunk(array_keys($_ls8Uids), 500) as $_uidChunk8) {
            [$_uSql8, $_uP8] = $DB->get_in_or_equal($_uidChunk8, SQL_PARAMS_NAMED, 'lsu');
            [$_cSql8, $_cP8] = $DB->get_in_or_equal($_cids8All,  SQL_PARAMS_NAMED, 'lsc');
            $_lRs8 = $DB->get_recordset_sql(
                "SELECT relateduserid, courseid, eventname, userid AS actor, timecreated
                   FROM {logstore_standard_log}
                  WHERE eventname IN ('\core\event\user_enrolment_created',
                                      '\core\event\user_enrolment_deleted')
                    AND relateduserid $_uSql8
                    AND courseid $_cSql8
                  ORDER BY timecreated ASC",
                array_merge($_uP8, $_cP8)
            );
            foreach ($_lRs8 as $_lr8) {
                $k8 = (int)$_lr8->relateduserid . ':' . (int)$_lr8->courseid;
                $logstoreHistory[$k8][] = [
                    'event' => (string)$_lr8->eventname,
                    'ts'    => (int)$_lr8->timecreated,
                    'actor' => (int)$_lr8->actor,   // userid = who Moodle considers performed the action
                ];
                if ((int)$_lr8->actor > 0) $_actorSet8[(int)$_lr8->actor] = true;
            }
            $_lRs8->close();
        }
        if (!empty($_actorSet8)) {
            [$_aSql8, $_aP8] = $DB->get_in_or_equal(array_keys($_actorSet8), SQL_PARAMS_NAMED, 'lsa');
            $_aRs8 = $DB->get_recordset_sql("SELECT id, username FROM {user} WHERE id $_aSql8", $_aP8);
            foreach ($_aRs8 as $_a8) { $logstoreActorNames[(int)$_a8->id] = (string)$_a8->username; }
            $_aRs8->close();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────────────────────────────────────
$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
local_rtocompliance_render_nav_header();
echo '<div class="rtoc-main-content" style="max-width:1200px;margin:0 auto;padding:1rem;">';
?>

<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
  <h4 style="margin:0;font-weight:700;">Complaint Student Acceptance Tests</h4>
  <a href="<?= (new moodle_url('/local/rtocompliance/reconcile.php'))->out() ?>"
     class="btn btn-secondary btn-sm">&#9664; NAT Reconciliation Tool</a>
</div>

<?php if (!$importRec): ?>
<div class="alert alert-warning">No NAT import found containing the test students. Import a NAT file first.</div>
<?php else: ?>

<!-- Overall result banner -->
<div style="background:<?= $overallPass ? '#d1e7dd' : '#f8d7da' ?>;border:2px solid <?= $overallPass ? '#198754' : '#dc3545' ?>;border-radius:6px;padding:0.8rem 1rem;margin-bottom:1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:0.7rem;">
    <span style="font-size:1.8em;line-height:1;"><?= $overallPass ? '&#10003;' : '&#10007;' ?></span>
    <div>
      <strong style="font-size:1.15em;color:<?= $overallPass ? '#198754' : '#dc3545' ?>;">
        Overall: <?= $passCount ?> / <?= $totalCount ?> PASS
        <?= !$overallPass ? '&mdash; ' . ($totalCount - $passCount) . ' FAILED' : '' ?>
      </strong><br>
      <small style="color:#6c757d;">
        Import #<?= (int)$importForTestId ?>
        &mdash; <?= date('d M Y H:i', (int)$importRec->timecreated) ?>
        &mdash; run at <?= date('H:i:s') ?>
      </small>
    </div>
  </div>
  <!-- Per-student badges -->
  <div style="margin-left:auto;display:flex;gap:0.4rem;flex-wrap:wrap;">
    <?php foreach ($regressionCases as $i => $rc): ?>
    <span style="padding:0.25rem 0.65rem;border-radius:4px;font-size:0.82em;font-weight:700;
                 background:<?= $results[$i]['pass'] ? '#198754' : '#dc3545' ?>;color:#fff;"
          title="<?= _rt_h($rc['name']) ?>">
      <?= _rt_h(explode(' ', $rc['name'])[0]) ?>
      <?= $results[$i]['pass'] ? 'PASS' : 'FAIL' ?>
    </span>
    <?php endforeach; ?>
  </div>
</div>

<?php
// ── Legend ────────────────────────────────────────────────────────────────────
?>
<div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:0.78em;color:#6c757d;margin-bottom:1rem;align-items:center;">
  <strong>Legend:</strong>
  <span style="display:flex;align-items:center;gap:0.25rem;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#198754;"></span> Active (visible)
  </span>
  <span style="display:flex;align-items:center;gap:0.25rem;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#6c757d;"></span> Archived (hidden)
  </span>
  <span style="display:flex;align-items:center;gap:0.25rem;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#dc3545;"></span> Not enrolled
  </span>
  <span style="display:flex;align-items:center;gap:0.25rem;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#0d6efd;"></span> ADD (reconciler recommendation)
  </span>
  <span style="display:flex;align-items:center;gap:0.25rem;">
    <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#fd7e14;"></span> REMOVE recommendation
  </span>
</div>

<!-- Per-student cards -->
<?php foreach ($regressionCases as $i => $rc):
    $res       = $results[$i];
    $cardBord  = $res['pass'] ? '#198754' : '#dc3545';
    $headerBg  = $res['pass'] ? '#d1e7dd' : '#f8d7da';
    $headerClr = $res['pass'] ? '#198754' : '#dc3545';
    $expanded  = !$res['pass'] ? 'block' : 'none';
?>
<div style="border:2px solid <?= $cardBord ?>;border-radius:6px;margin-bottom:1.2rem;">

  <!-- Card header (click to expand/collapse) -->
  <div style="background:<?= $headerBg ?>;padding:0.55rem 0.9rem;display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap;cursor:pointer;border-radius:4px 4px 0 0;"
       onclick="(function (el){el.style.display=el.style.display==='none'?'block':'none';})(document.getElementById('rtbd<?= $i ?>'));">
    <span style="font-size:1.1em;font-weight:700;color:<?= $headerClr ?>;">
      <?= $res['pass'] ? '&#10003;' : '&#10007;' ?>
    </span>
    <strong style="color:<?= $headerClr ?>;font-size:1em;"><?= _rt_h($rc['name']) ?></strong>
    <span style="color:#6c757d;font-size:0.82em;">
      UID <?= (int)$rc['uid'] ?> &bull; Client <?= _rt_h($rc['clientid']) ?>
    </span>
    <?php if (!$res['nat_found']): ?>
    <span style="background:#ffc107;color:#000;border-radius:4px;padding:0.1rem 0.4rem;font-size:0.78em;">
      &#9888; Not in import
    </span>
    <?php endif; ?>
    <span style="margin-left:auto;font-weight:700;color:<?= $headerClr ?>;">
      <?= $res['pass'] ? 'PASS' : 'FAIL' ?>
    </span>
    <span style="font-size:0.78em;color:#6c757d;">
      KEEP <?= $res['keep_count'] ?>
      &bull; REMOVE <?= $res['remove_count'] ?>
      &bull; ADD <?= $res['add_count'] ?>
      <?= $res['review_count'] ? '&bull; REVIEW ' . $res['review_count'] : '' ?>
      <?= $res['pi_count']     ? '&bull; POST-IMPORT ' . $res['pi_count'] : '' ?>
      &bull; NAT <?= $res['nat_units'] ?>
    </span>
    <span style="font-size:0.75em;color:#6c757d;">&#9660;</span>
  </div>

  <!-- Card body -->
  <div id="rtbd<?= $i ?>" style="display:<?= $expanded ?>;padding:0.85rem;">

    <!-- ── Part A: 4-column comparison ──────────────────────────────────────── -->
    <div style="margin-bottom:0.9rem;">
      <div style="font-weight:700;font-size:0.85em;margin-bottom:0.4rem;color:#495057;
                  border-bottom:1px solid #dee2e6;padding-bottom:0.3rem;">
        Client Expected vs Reconciler Decision
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:0.83em;">
        <thead>
          <tr style="background:#f8f9fa;color:#495057;">
            <th style="padding:0.3rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Course Pattern</th>
            <th style="padding:0.3rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Client Expects</th>
            <th style="padding:0.3rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">NAT Unit</th>
            <th style="padding:0.3rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Moodle Status</th>
            <th style="padding:0.3rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Reconciler</th>
            <th style="padding:0.3rem 0.4rem;text-align:center;border-bottom:2px solid #dee2e6;white-space:nowrap;">Result</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $prevExpects = null;
          foreach ($res['comparison_rows'] as $crow):
              $rowBg = $crow['pass'] ? 'transparent' : '#fff5f5';
              if ($prevExpects !== null && $crow['client_expects'] !== $prevExpects): ?>
          <tr><td colspan="6" style="height:4px;background:#dee2e6;"></td></tr>
          <?php endif;
              $prevExpects = $crow['client_expects'];

              // Client expects badge
              $expBg  = $crow['client_expects'] === 'active' ? '#198754' : '#6c757d';
              $expLbl = $crow['client_expects'] === 'active' ? 'Active' : 'Archive';

              // NAT present badge
              if ($crow['nat_unit'] === '') {
                  $natHtml = '<span style="color:#dc3545;">No unit code</span>';
              } elseif ($crow['nat_present']) {
                  $natHtml = '<code style="font-size:0.9em;">' . _rt_h($crow['nat_unit']) . '</code>'
                           . ' <span style="color:#198754;font-size:0.85em;">&#10003; In NAT</span>';
              } else {
                  $natHtml = '<code style="font-size:0.9em;">' . _rt_h($crow['nat_unit']) . '</code>'
                           . ' <span style="color:#6c757d;font-size:0.85em;">Not in NAT</span>';
              }

              // Moodle status
              switch ($crow['moodle_status']) {
                  case 'active':
                      $msHtml = '<span style="color:#198754;font-weight:600;">&#9679; Active</span>';
                      if ($crow['moodle_sn']) $msHtml .= '<br><span style="color:#6c757d;font-size:0.85em;">' . _rt_h($crow['moodle_sn']) . '</span>';
                      break;
                  case 'archived':
                      $msHtml = '<span style="color:#6c757d;font-weight:600;">&#9679; Archived</span>';
                      if ($crow['moodle_sn']) $msHtml .= '<br><span style="color:#6c757d;font-size:0.85em;">' . _rt_h($crow['moodle_sn']) . '</span>';
                      break;
                  default:
                      $msHtml = '<span style="color:#dc3545;font-weight:600;">&#9675; Not enrolled</span>';
              }

              // Reconciler badge
              $recColors = [
                  'KEEP'        => ['#198754', '#d1e7dd'],
                  'REMOVE'      => ['#fd7e14', '#fff3cd'],
                  'ADD'         => ['#0d6efd', '#cfe2ff'],
                  'POST-IMPORT' => ['#6c757d', '#e9ecef'],
                  'REVIEW'      => ['#856404', '#fff3cd'],
                  '—'           => ['#adb5bd', '#f8f9fa'],
              ];
              [$recFg, $recBg] = $recColors[$crow['reconciler']] ?? ['#6c757d', '#f8f9fa'];
              $recHtml = '<span style="background:' . $recBg . ';color:' . $recFg . ';border-radius:4px;padding:0.1rem 0.45rem;font-weight:700;">'
                       . _rt_h($crow['reconciler']) . '</span>';

              // Result cell
              $resultHtml = $crow['pass']
                  ? '<span style="color:#198754;font-weight:700;font-size:1.05em;">&#10003;</span>'
                  : '<span style="color:#dc3545;font-weight:700;font-size:1.05em;">&#10007;</span>';
          ?>
          <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #f0f0f0;">
            <td style="padding:0.3rem 0.4rem;white-space:nowrap;">
              <code style="font-size:0.92em;"><?= _rt_h($crow['pattern']) ?></code>
            </td>
            <td style="padding:0.3rem 0.4rem;">
              <span style="background:<?= $expBg ?>;color:#fff;border-radius:4px;padding:0.1rem 0.4rem;font-size:0.82em;font-weight:600;">
                <?= $expLbl ?>
              </span>
            </td>
            <td style="padding:0.3rem 0.4rem;"><?= $natHtml ?></td>
            <td style="padding:0.3rem 0.4rem;"><?= $msHtml ?></td>
            <td style="padding:0.3rem 0.4rem;"><?= $recHtml ?></td>
            <td style="padding:0.3rem 0.4rem;text-align:center;"><?= $resultHtml ?></td>
          </tr>
          <?php
          // ── Logstore history sub-row ──────────────────────────────────────
          if (!$crow['pass'] && $crow['client_expects'] === 'active' && $crow['courseid'] !== null && $logstoreAvailable):
              $_hKey    = ((int)$rc['uid']) . ':' . ((int)$crow['courseid']);
              $_hEvs    = $logstoreHistory[$_hKey] ?? null;
              $_hStatus = _rt_history_status($_hEvs ?? []);
              // Status chip: label + colour per status value.
              static $_hStatusMeta = [
                  'never_enrolled'        => ['Never Enrolled',           '#6c757d'],
                  'currently_enrolled'    => ['Currently Enrolled',       '#198754'],
                  'enrolled_then_removed' => ['Enrolled then Removed',    '#dc3545'],
                  'restored_after_deletion'=>['Restored after Deletion',  '#0d6efd'],
                  'multiple_cycles'       => ['Multiple Enrolment Cycles','#fd7e14'],
                  'history_incomplete'    => ['History Incomplete',       '#6c757d'],
              ];
              [$_hLabel, $_hColor] = $_hStatusMeta[$_hStatus] ?? ['Unknown', '#6c757d'];
          ?>
          <tr style="background:#fff0f0;">
            <td colspan="6" style="padding:0.25rem 0.5rem 0.5rem 1.8rem;font-size:0.77em;color:#6c757d;border-bottom:1px solid #f0f0f0;">
              <span style="font-weight:600;color:#495057;">History:</span>
              <span style="display:inline-block;margin:0 0.4rem;padding:0.1rem 0.55rem;border-radius:3px;font-size:0.88em;font-weight:700;background:<?= $_hColor ?>;color:#fff;"><?= _rt_h($_hLabel) ?></span>
              <?php if ($_hEvs !== null):
                  // Render every event in chronological order (already ASC from query).
                  $_htParts = [];
                  foreach ($_hEvs as $_htEv) {
                      $_htActor = $logstoreActorNames[$_htEv['actor']] ?? 'user#' . $_htEv['actor'];
                      if (strpos($_htEv['event'], 'enrolment_created') !== false) {
                          $_htParts[] = '<span style="color:#198754;">&#10003; Enrolled ' . date('d M Y H:i', $_htEv['ts']) . '</span>';
                      } elseif (strpos($_htEv['event'], 'enrolment_deleted') !== false) {
                          $_htParts[] = '<span style="color:#dc3545;">&#10007; Unenrolled ' . date('d M Y H:i', $_htEv['ts'])
                                      . ' by <code style="font-size:1em;">' . _rt_h($_htActor) . '</code></span>';
                      }
                  }
                  if (empty($_htParts)) {
                      echo '<span style="color:#adb5bd;">No relevant events in logstore</span>';
                  } else {
                      echo implode(' &rarr; ', $_htParts);
                      if (in_array($_hStatus, ['enrolled_then_removed','history_incomplete','multiple_cycles'])) {
                          echo ' &rarr; <strong style="color:#0d6efd;">Suggested: Restore</strong>';
                      }
                  }
              else: ?>
                <span style="color:#adb5bd;">No logstore record &mdash; student may never have been enrolled in this course</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; // end logstore sub-row ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$res['pass']): ?>
    <!-- Differences summary -->
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:0.5rem 0.75rem;margin-bottom:0.9rem;font-size:0.83em;">
      <strong>&#9888; What needs fixing:</strong>
      <?php
      $diffs = [];
      foreach ($res['comparison_rows'] as $crow) {
          if (!$crow['pass']) {
              if ($crow['client_expects'] === 'active') {
                  if ($crow['reconciler'] === 'ADD') {
                      $diffs[] = '"' . $crow['pattern'] . '" — client expects Active, but student is not enrolled (reconciler says ADD)';
                  } elseif ($crow['reconciler'] === '—') {
                      $diffs[] = '"' . $crow['pattern'] . '" — client expects Active, not in any reconciler list';
                  } else {
                      $diffs[] = '"' . $crow['pattern'] . '" — client expects Active but reconciler says ' . $crow['reconciler'];
                  }
              } else {
                  if ($crow['reconciler'] === 'KEEP') {
                      $diffs[] = '"' . $crow['pattern'] . '" — client expects Archived, but reconciler says KEEP (still enrolled in active course)';
                  } else {
                      $diffs[] = '"' . $crow['pattern'] . '" — unexpected issue (reconciler: ' . $crow['reconciler'] . ')';
                  }
              }
          }
      }
      echo implode('<br>', array_map(fn($d) => '&bull; ' . _rt_h($d), $diffs));
      ?>
    </div>
    <?php endif; ?>

    <!-- ── Part B: Enriched NAT Unit Trace ──────────────────────────────────── -->
    <details style="margin-top:0.3rem;">
      <summary style="cursor:pointer;font-weight:700;font-size:0.85em;color:#495057;padding:0.25rem 0;
                      border-top:1px solid #dee2e6;padding-top:0.5rem;">
        NAT Unit Detail (<?= count($res['nat_trace']) ?> units) — click to expand
      </summary>
      <table style="width:100%;border-collapse:collapse;font-size:0.8em;margin-top:0.45rem;">
        <thead>
          <tr style="background:#f8f9fa;color:#495057;">
            <th style="padding:0.28rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">NAT Unit</th>
            <th style="padding:0.28rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Outcome</th>
            <th style="padding:0.28rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Delivery</th>
            <th style="padding:0.28rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Expected Course</th>
            <th style="padding:0.28rem 0.4rem;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Moodle Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($res['nat_trace'] as $tr):
              switch ($tr['moodle_status']) {
                  case 'active':
                      $msClr   = '#198754';
                      $msLabel = '&#9679; Active';
                      $rowBg   = 'transparent';
                      break;
                  case 'archived':
                      $msClr   = '#6c757d';
                      $msLabel = '&#9679; Archived';
                      $rowBg   = '#f8f9fa';
                      break;
                  default:
                      $msClr   = '#dc3545';
                      $msLabel = '&#9675; Not enrolled';
                      $rowBg   = '#fff5f5';
              }
              $outcomeLabel = $tr['outcome'] !== '' ? $tr['outcome'] : '<span style="color:#adb5bd;">—</span>';
              $deliveryLabel = $tr['delivery_key'] !== '' ? '<code style="font-size:0.92em;">' . _rt_h($tr['delivery_key']) . '</code>' : '<span style="color:#adb5bd;">—</span>';
          ?>
          <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #f0f0f0;">
            <td style="padding:0.25rem 0.4rem;"><code style="font-size:0.92em;"><?= _rt_h($tr['unitcode']) ?></code></td>
            <td style="padding:0.25rem 0.4rem;"><?= $outcomeLabel ?></td>
            <td style="padding:0.25rem 0.4rem;"><?= $deliveryLabel ?></td>
            <td style="padding:0.25rem 0.4rem;color:#495057;">
              <?= $tr['expected_sn'] !== '—' ? _rt_h($tr['expected_sn']) : '<span style="color:#adb5bd;">No course found</span>' ?>
            </td>
            <td style="padding:0.25rem 0.4rem;">
              <span style="color:<?= $msClr ?>;font-weight:600;"><?= $msLabel ?></span>
              <?php if ($tr['moodle_sn'] && $tr['moodle_sn'] !== $tr['expected_sn']): ?>
              <br><span style="color:#6c757d;font-size:0.9em;"><?= _rt_h($tr['moodle_sn']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </details>

  </div><!-- card body -->
</div><!-- card -->
<?php endforeach; ?>

<!-- Footer -->
<div style="font-size:0.8em;color:#6c757d;margin-top:1rem;border-top:1px solid #dee2e6;padding-top:0.7rem;">
  <strong>Import used:</strong> #<?= (int)$importForTestId ?>
  &mdash; <?= _rt_h($importRec->filename ?? '(unknown)') ?>
  &mdash; <?= date('d M Y H:i', (int)$importRec->timecreated) ?><br>
  <strong>How to add test cases:</strong> Add entries to <code>$regressionCases</code> in
  <code>local/rtocompliance/regression_test.php</code>.
  PASS = all expected Active courses are KEEP, all expected Archived are not KEEP.
</div>

<?php endif; // $importRec ?>
<?php
echo '</div>';
echo $OUTPUT->footer();
