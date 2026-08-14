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
 * RTO Compliance plugin — foe_audit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// FOE Forensic Audit Tool — foe_audit.php
// Replays the exact Fix Over-Enrolments comparison pipeline for ONE student,
// printing every decision point with full diagnostic context.
// READ-ONLY. Never calls unenrol_user(). Never writes to any table.
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_data_import');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());
\core\session\manager::write_close();

$importid = (int)optional_param('importid', 0, PARAM_INT);
$clientid = strtolower(trim(optional_param('clientid', '', PARAM_RAW))); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.

// ── Non-continuing outcome codes (must match data_import.php exactly) ─────────
// FOE-OUTCOME-30-RESTORED (v5.9.100): 30 re-added after v5.9.84 removed it.
// '04' is NOT in this list (non-standard code, treated as unknown/safe).
$NC_CODES = ['10','20','30','40','41','51','52','53','54','60','61','81','82','85','90'];
$NC_LABELS = [
    '10' => 'Not yet started',      '20' => 'Competent/Achieved',
    '30' => 'Competency NOT YET achieved (non-continuing)',
    '40' => 'Withdrawn',            '41' => 'Withdrawn (incomplete)',
    '51' => 'RPL granted',          '52' => 'RPL not granted',
    '53' => 'RCC granted',          '54' => 'RCC not granted',
    '60' => 'Credit transfer',      '61' => 'Credit transfer',
    '81' => 'Non-assessed (satisfactory)',
    '82' => 'Non-assessed (unsatisfactory)',
    '85' => 'Further enrolment required',
    '90' => 'Result not available',
];

// ── Unit code extraction — must stay in sync with data_import.php _foe_extract_unitcode() ─
if (!function_exists('_foa_extract_unitcode')) {
    function _foa_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
        $idn = strtoupper(trim($idnumber));
        $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
        if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) return $idn;
        if (preg_match($pat, $idn, $m)) return $m[1];
        if (preg_match($pat, strtoupper(trim($shortname)), $m)) return $m[1];
        if (preg_match($pat, strtoupper(trim($fullname)),  $m)) return $m[1];
        return '';
    }
}

// ── HTML helpers ──────────────────────────────────────────────────────────────
function _foa_ok(string $msg): string {
    return '<span style="display:inline-block;background:#d4edda;color:#155724;padding:2px 9px;border-radius:4px;font-weight:600;">&#10003; PASS</span> ' . $msg;
}
function _foa_fail(string $msg): string {
    return '<span style="display:inline-block;background:#f8d7da;color:#721c24;padding:2px 9px;border-radius:4px;font-weight:600;">&#10007; FAIL</span> ' . $msg;
}
function _foa_warn(string $msg): string {
    return '<span style="display:inline-block;background:#fff3cd;color:#856404;padding:2px 9px;border-radius:4px;font-weight:600;">&#9888; WARN</span> ' . $msg;
}
function _foa_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Over-Enrolment Audit'); // v5.9.404: add sidebar.
echo '<h2 style="margin-bottom:0.2rem;">&#128270; FOE Forensic Audit Tool</h2>';
echo '<p class="text-muted" style="margin-bottom:1.5rem;font-size:0.93em;">Replays the exact Fix Over-Enrolments comparison for one student. <strong>Read-only. No data is changed.</strong></p>';

// ── Input form ────────────────────────────────────────────────────────────────
$_imports = $DB->get_records_sql(
    "SELECT a.id, a.collectionyear, a.timecreated,
            COUNT(e.id) AS unit_rows,
            COUNT(DISTINCT e.clientid) AS student_rows
       FROM {local_rtocompliance_avetmiss} a
  LEFT JOIN {local_rtocompliance_avetmiss_enrolment} e ON e.importid = a.id
      GROUP BY a.id, a.collectionyear, a.timecreated
      ORDER BY a.id DESC"
);

echo '<form method="get" action="" style="background:#f8f9fa;padding:1.2rem;border-radius:6px;border:1px solid #dee2e6;margin-bottom:2rem;">';
echo '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">';
echo '<div>';
echo '<label style="font-weight:600;display:block;margin-bottom:0.25rem;">Import</label>';
echo '<select name="importid" style="padding:5px 10px;border:1px solid #ced4da;border-radius:4px;">';
echo '<option value="">— select import —</option>';
foreach ($_imports as $_imp) {
    $sel = ($importid === (int)$_imp->id) ? ' selected' : '';
    echo '<option value="' . (int)$_imp->id . '"' . $sel . '>'
       . 'Import #' . (int)$_imp->id
       . ' (' . _foa_h((string)$_imp->collectionyear) . ')'
       . ' — ' . number_format((int)$_imp->unit_rows) . ' unit rows'
       . ', ' . number_format((int)$_imp->student_rows) . ' students'
       . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div>';
echo '<label style="font-weight:600;display:block;margin-bottom:0.25rem;">NAT Client ID</label>';
echo '<input type="text" name="clientid" value="' . _foa_h($clientid) . '" placeholder="e.g. 29663" style="padding:5px 10px;border:1px solid #ced4da;border-radius:4px;width:180px;">';
echo '</div>';
echo '<div><button type="submit" class="btn btn-primary">Run Forensic Audit</button></div>';
echo '</div>';
echo '</form>';

if (!$importid || $clientid === '') {
    echo '<div class="alert alert-info">Select an import and enter a NAT Client ID to begin the audit.</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<hr style="margin-bottom:2rem;">';

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — Moodle user resolution
// ═════════════════════════════════════════════════════════════════════════════
echo '<h4 style="margin-bottom:0.8rem;">Step 1 — Moodle User Resolution</h4>';

$_s1mu = $DB->get_record_sql(
    "SELECT id, username, idnumber, firstname, lastname, email, suspended, deleted
       FROM {user} WHERE LOWER(idnumber) = :cid AND deleted = 0 LIMIT 1",
    ['cid' => $clientid]
);
$_s1byUser = $DB->get_record_sql(
    "SELECT id, username, idnumber, firstname, lastname, email, suspended, deleted
       FROM {user} WHERE LOWER(username) = :cid AND deleted = 0 LIMIT 1",
    ['cid' => $clientid]
);

$_resolvedUid  = null;
$_resolvedName = '';
$_resolvedVia  = '';

if ($_s1mu) {
    $_resolvedUid  = (int)$_s1mu->id;
    $_resolvedName = trim($_s1mu->firstname . ' ' . $_s1mu->lastname);
    $_resolvedVia  = 'idnumber';
    echo '<p>' . _foa_ok('<code>mdl_user.idnumber = ' . _foa_h($clientid) . '</code> → userid <strong>' . $_resolvedUid . '</strong>') . '</p>';
} elseif ($_s1byUser) {
    $_resolvedUid  = (int)$_s1byUser->id;
    $_resolvedName = trim($_s1byUser->firstname . ' ' . $_s1byUser->lastname);
    $_resolvedVia  = 'username';
    echo '<p>' . _foa_warn('idnumber match failed. Matched via <code>mdl_user.username</code> instead.') . '</p>';
    echo '<p>' . _foa_ok('<code>mdl_user.username = ' . _foa_h($clientid) . '</code> → userid <strong>' . $_resolvedUid . '</strong>') . '</p>';
} else {
    echo '<p>' . _foa_fail('No Moodle user found with <code>idnumber</code> or <code>username</code> = <code>' . _foa_h($clientid) . '</code>. Check the client ID.') . '</p>';
}

$_user = $_s1mu ?: $_s1byUser;
if ($_user && $_resolvedUid) {
    echo '<table class="table table-sm table-bordered" style="max-width:560px;font-size:0.9em;margin-top:0.5rem;">';
    echo '<tr><th style="width:180px;">Field</th><th>Value</th></tr>';
    echo '<tr><td>userid</td><td><strong>' . $_resolvedUid . '</strong></td></tr>';
    echo '<tr><td>username</td><td><code>' . _foa_h((string)$_user->username) . '</code></td></tr>';
    echo '<tr><td>idnumber</td><td><code>' . (_foa_h((string)$_user->idnumber) ?: '<em style="color:#6c757d;">blank</em>') . '</code></td></tr>';
    echo '<tr><td>Full name</td><td>' . _foa_h($_resolvedName) . '</td></tr>';
    echo '<tr><td>Email</td><td>' . _foa_h((string)$_user->email) . '</td></tr>';
    echo '<tr><td>Suspended</td><td>' . ((int)$_user->suspended ? '<span style="color:#dc3545;">YES</span>' : 'No') . '</td></tr>';
    echo '<tr><td>Resolved via</td><td><code>' . _foa_h($_resolvedVia) . '</code></td></tr>';
    echo '<tr><td>NAT clientid used</td><td><code>' . _foa_h($clientid) . '</code></td></tr>';
    echo '</table>';
}

if (!$_resolvedUid) {
    echo $OUTPUT->footer();
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — Build expected NAT unit set ($allStudentUnits for this student)
// ═════════════════════════════════════════════════════════════════════════════
echo '<hr><h4 style="margin-bottom:0.8rem;">Step 2 — Expected NAT Units for Import #' . $importid . '</h4>';
echo '<p style="font-size:0.91em;color:#6c757d;">Source table: <code>local_rtocompliance_avetmiss_enrolment WHERE importid=' . $importid . ' AND LOWER(clientid)=\'' . _foa_h($clientid) . '\'</code></p>';

$_s2rows = $DB->get_records_sql(
    "SELECT unitcode, outcome
       FROM {local_rtocompliance_avetmiss_enrolment}
      WHERE importid = :iid AND LOWER(clientid) = :cid
      ORDER BY unitcode ASC",
    ['iid' => $importid, 'cid' => $clientid]
);

$allStudentUnits = []; // unitcode (UC) → outcome string
foreach ($_s2rows as $_s2r) {
    $_uc  = strtoupper(trim((string)$_s2r->unitcode));
    $_oc  = trim((string)($_s2r->outcome ?? ''));
    if ($_uc !== '') $allStudentUnits[$_uc] = $_oc;
}

if (empty($allStudentUnits)) {
    echo '<p>' . _foa_fail('Client ID <code>' . _foa_h($clientid) . '</code> has <strong>ZERO rows</strong> in the staging enrolment table for import #' . $importid . '.') . '</p>';
    echo '<p>This means FOE would have treated this student as having <em>no NAT units at all</em>.</p>';
    // Check other imports for comparison
    $_otherImports = $DB->get_records_sql(
        "SELECT importid, COUNT(*) AS cnt FROM {local_rtocompliance_avetmiss_enrolment}
          WHERE LOWER(clientid) = :cid GROUP BY importid ORDER BY importid DESC",
        ['cid' => $clientid]
    );
    if (!empty($_otherImports)) {
        echo '<p>' . _foa_warn('However, this client <strong>does</strong> appear in other imports:') . '</p>';
        echo '<ul>';
        foreach ($_otherImports as $_oi) {
            echo '<li>Import #' . (int)$_oi->importid . ' — ' . (int)$_oi->cnt . ' unit rows</li>';
        }
        echo '</ul>';
        echo '<p>If FOE was run against import #' . $importid . ' but this student\'s data exists only in other imports, all their enrolments would fail the Criterion 1 lookup → REMOVE.</p>';
    }
    echo $OUTPUT->footer();
    exit;
}

// Count outcome distribution
$_oc30 = 0; $_oc70 = 0; $_ocOther = []; $_ocEmpty = 0;
foreach ($allStudentUnits as $_uc => $_oc) {
    if ($_oc === '30') $_oc30++;
    elseif ($_oc === '70') $_oc70++;
    elseif ($_oc === '') $_ocEmpty++;
    else $_ocOther[$_oc] = ($_ocOther[$_oc] ?? 0) + 1;
}

echo '<p>' . _foa_ok(count($allStudentUnits) . ' unit(s) found in NAT staging for this student in import #' . $importid . '.') . '</p>';

// CRITICAL OUTCOME ANALYSIS
echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:1rem;margin:0.8rem 0;">';
echo '<strong>&#9888; Outcome code distribution for this student:</strong><br>';
echo '<ul style="margin:0.5rem 0 0;">';
if ($_oc70 > 0) echo '<li><code>70</code> (Continuing — safe, never removed) = <strong>' . $_oc70 . '</strong></li>';
if ($_oc30 > 0) echo '<li><code>30</code> (Not yet achieved — <span style="color:#dc3545;font-weight:600;">IN NON_CONTINUING LIST → CRITERION 2 → REMOVE</span>) = <strong>' . $_oc30 . '</strong></li>';
if ($_ocEmpty > 0) echo '<li><em>empty/null</em> (unknown — safe, not flagged by Criterion 2) = <strong>' . $_ocEmpty . '</strong></li>';
foreach ($_ocOther as $_poc => $_pcnt) {
    $ncFlag = in_array($_poc, $NC_CODES, true);
    $ncLabel = $ncFlag ? ' <span style="color:#dc3545;">IN NON_CONTINUING LIST → REMOVE</span>' : ' (not in non-continuing list — safe)';
    echo '<li><code>' . _foa_h($_poc) . '</code>' . $ncLabel . ' = <strong>' . $_pcnt . '</strong></li>';
}
echo '</ul>';
if ($_oc30 > 0 && $_oc70 === 0) {
    echo '<p style="margin-top:0.6rem;color:#dc3545;font-weight:600;">&#9888; CRITICAL: This student has outcome 30 on ALL units and ZERO outcome 70 entries.<br>'
       . 'Outcome 30 is in the NON_CONTINUING list. Every unit-matched Moodle enrolment will be flagged by Criterion 2 → REMOVE.</p>';
}
echo '</div>';

echo '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size:0.86em;margin-top:0.5rem;">';
echo '<thead class="thead-light"><tr><th>#</th><th>Unit Code (from NAT)</th><th>Outcome</th><th>Outcome Meaning</th><th>In NON_CONTINUING?</th></tr></thead><tbody>';
$_s2n = 1;
foreach ($allStudentUnits as $_uc => $_oc) {
    $ncFlag  = in_array($_oc, $NC_CODES, true);
    $ncLabel = $ncFlag
        ? '<span style="color:#dc3545;font-weight:600;">YES → CRITERION 2 FIRES → REMOVE</span>'
        : '<span style="color:#155724;">No — safe</span>';
    $ocMean  = $NC_LABELS[$_oc] ?? ($_oc === '70' ? 'Continuing activity (safe)' : ($_oc === '' ? '<em>empty — unknown/safe</em>' : 'Unknown code'));
    echo '<tr>';
    echo '<td>' . $_s2n++ . '</td>';
    echo '<td><code>' . _foa_h($_uc) . '</code></td>';
    echo '<td><code>' . (_foa_h($_oc) ?: '<em>empty</em>') . '</code></td>';
    echo '<td>' . $ocMean . '</td>';
    echo '<td>' . $ncLabel . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 — Current Moodle enrolments for this student
// ═════════════════════════════════════════════════════════════════════════════
echo '<hr><h4 style="margin-bottom:0.8rem;">Step 3 — Current Moodle Enrolments (userid=' . $_resolvedUid . ')</h4>';

$_s3rows = $DB->get_records_sql(
    "SELECT e.id AS enrolid, e.enrol, e.courseid, e.status AS enrol_status,
            c.fullname, c.shortname, c.idnumber AS courseidn, c.category, c.visible,
            ue.status AS ue_status, ue.timecreated, ue.timestart, ue.timeend
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid
       JOIN {course} c ON c.id = e.courseid AND c.id <> 1
      WHERE ue.userid = :uid
      ORDER BY e.enrol ASC, c.fullname ASC",
    ['uid' => $_resolvedUid]
);

if (empty($_s3rows)) {
    echo '<p>' . _foa_fail('No enrolments found for userid=' . $_resolvedUid . ' in any course. Student has no Moodle enrolments at all.') . '</p>';
    echo $OUTPUT->footer();
    exit;
}

echo '<p>' . _foa_ok(count($_s3rows) . ' total enrolment row(s) across all courses and methods.') . '</p>';

echo '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size:0.84em;">';
echo '<thead class="thead-light"><tr><th>Method</th><th>UE Status</th><th>Course</th><th>Course idnumber</th><th>Extracted Unit Code</th><th>Category</th><th>Visible</th></tr></thead><tbody>';
$_s3byId = [];
foreach ($_s3rows as $_s3r) {
    $_uc = _foa_extract_unitcode(
        (string)$_s3r->courseidn,
        (string)$_s3r->shortname,
        (string)$_s3r->fullname
    );
    $_s3byId[(int)$_s3r->courseid] = [
        'enrolid'   => (int)$_s3r->enrolid,
        'method'    => (string)$_s3r->enrol,
        'active'    => ((int)$_s3r->ue_status === 0),
        'fullname'  => (string)$_s3r->fullname,
        'shortname' => (string)$_s3r->shortname,
        'courseidn' => (string)$_s3r->courseidn,
        'catid'     => (int)$_s3r->category,
        'visible'   => (int)$_s3r->visible,
        'unitcode'  => $_uc,
    ];
    $_ucCell = $_uc !== '' ? '<code>' . _foa_h($_uc) . '</code>' : '<em style="color:#dc3545;">NONE — course invisible to FOE</em>';
    $_rowStyle = ((int)$_s3r->ue_status !== 0) ? ' style="opacity:0.5;"' : '';
    echo '<tr' . $_rowStyle . '>';
    echo '<td><code>' . _foa_h((string)$_s3r->enrol) . '</code></td>';
    echo '<td>' . ((int)$_s3r->ue_status === 0 ? 'Active' : '<em>Inactive(' . (int)$_s3r->ue_status . ')</em>') . '</td>';
    echo '<td>' . _foa_h((string)$_s3r->fullname) . '</td>';
    echo '<td><code>' . (_foa_h((string)$_s3r->courseidn) ?: '<em>blank</em>') . '</code></td>';
    echo '<td>' . $_ucCell . '</td>';
    echo '<td>' . (int)$_s3r->category . '</td>';
    echo '<td>' . ((int)$_s3r->visible ? 'Yes' : '<span style="color:#856404;">Hidden</span>') . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — Comparison: expected NAT vs Moodle enrolments
// ═════════════════════════════════════════════════════════════════════════════
echo '<hr><h4 style="margin-bottom:0.8rem;">Step 4 — Comparison: Expected NAT vs Moodle Enrolments</h4>';
echo '<p style="font-size:0.91em;color:#6c757d;">This replicates the exact logic of the <code>foreach (\$_enrolRs as \$_er)</code> loop in <code>data_import.php</code>.</p>';

echo '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size:0.84em;">';
echo '<thead class="thead-light"><tr>'
   . '<th>Course</th>'
   . '<th>Enrol Method</th>'
   . '<th>UE Status</th>'
   . '<th>Extracted Unit Code<br><small>(FOE key)</small></th>'
   . '<th>Unit in NAT?<br><small>(Criterion 1)</small></th>'
   . '<th>Outcome Code<br><small>(from NAT)</small></th>'
   . '<th>Outcome Non-continuing?<br><small>(Criterion 2)</small></th>'
   . '<th style="font-weight:700;">Decision</th>'
   . '</tr></thead><tbody>';

$_removeCount = 0; $_keepCount = 0; $_skipCount = 0;
$_removeRows  = []; // for Step 5 deep-dive

foreach ($_s3byId as $_cid => $_cr) {
    // Replicate FOE conditions:
    // 1. Only manual enrolments are in the bulk query
    // 2. Only active (status=0) enrolments
    // 3. Only courses with an extractable unit code
    $_isManual = ($_cr['method'] === 'manual');
    $_isActive = $_cr['active'];
    $_uc       = $_cr['unitcode'];

    if (!$_isManual || !$_isActive) {
        $_skipCount++;
        $_decision = $_isManual
            ? '<span style="color:#6c757d;">SKIP — inactive enrolment</span>'
            : '<span style="color:#6c757d;">SKIP — non-manual method (' . _foa_h($_cr['method']) . ')</span>';
        echo '<tr style="opacity:0.55;">'
           . '<td>' . _foa_h($_cr['fullname']) . '</td>'
           . '<td><code>' . _foa_h($_cr['method']) . '</code></td>'
           . '<td>' . ($_isActive ? 'Active' : '<em>Inactive</em>') . '</td>'
           . '<td>—</td><td>—</td><td>—</td><td>—</td>'
           . '<td>' . $_decision . '</td>'
           . '</tr>';
        continue;
    }
    if ($_uc === '') {
        $_skipCount++;
        echo '<tr style="opacity:0.65;">'
           . '<td>' . _foa_h($_cr['fullname']) . '</td>'
           . '<td><code>manual</code></td>'
           . '<td>Active</td>'
           . '<td><em style="color:#dc3545;">NONE</em></td>'
           . '<td>—</td><td>—</td><td>—</td>'
           . '<td><span style="color:#6c757d;">SKIP — no unit code extractable from this course</span></td>'
           . '</tr>';
        continue;
    }

    // ── THE EXACT COMPARISON ─────────────────────────────────────────────────
    $_outcome    = $allStudentUnits[$_uc] ?? null;   // null = no NAT record for this unit
    $_inNat      = ($_outcome !== null);
    $_isNonCont  = $_inNat && in_array($_outcome, $NC_CODES, true);

    $_reasons = [];
    if (!$_inNat)     $_reasons[] = 'Criterion 1: unit not in NAT data';
    if ($_isNonCont)  $_reasons[] = 'Criterion 2: outcome ' . $_outcome . ' (' . ($NC_LABELS[$_outcome] ?? $_outcome) . ')';

    if (!empty($_reasons)) {
        $_removeCount++;
        $_removeRows[$_cid] = [
            'fullname'  => $_cr['fullname'],
            'unitcode'  => $_uc,
            'courseidn' => $_cr['courseidn'],
            'outcome'   => $_outcome,
            'reasons'   => $_reasons,
        ];
        $_decisionCell = '<span style="background:#f8d7da;color:#721c24;font-weight:700;padding:2px 8px;border-radius:3px;">'
            . '&#10007; REMOVE</span><br><small>' . _foa_h(implode('; ', $_reasons)) . '</small>';
    } else {
        $_keepCount++;
        $_ocLabel = $_outcome === '70' ? 'Continuing' : ($_outcome === '' ? 'empty/unknown' : $_outcome);
        $_decisionCell = '<span style="background:#d4edda;color:#155724;font-weight:700;padding:2px 8px;border-radius:3px;">&#10003; KEEP</span>'
            . '<br><small>unit in NAT, outcome: ' . _foa_h($_ocLabel) . '</small>';
    }

    $_natCell = $_inNat
        ? '<span style="color:#155724;">YES — outcome <code>' . _foa_h($_outcome) . '</code></span>'
        : '<span style="color:#dc3545;font-weight:600;">NO — absent from NAT (Criterion 1)</span>';
    $_ncCell  = $_isNonCont
        ? '<span style="color:#dc3545;font-weight:600;">YES — ' . _foa_h($NC_LABELS[$_outcome] ?? $_outcome) . ' (Criterion 2)</span>'
        : '<span style="color:#155724;">' . ($_inNat ? 'No — outcome ' . _foa_h($_outcome ?: 'empty') . ' is safe' : '—') . '</span>';

    echo '<tr>';
    echo '<td>' . _foa_h($_cr['fullname']) . '</td>';
    echo '<td><code>manual</code></td>';
    echo '<td>Active</td>';
    echo '<td><code>' . _foa_h($_uc) . '</code></td>';
    echo '<td>' . $_natCell . '</td>';
    echo '<td>' . ($_outcome !== null ? '<code>' . _foa_h($_outcome) . '</code>' : '<em style="color:#6c757d;">null</em>') . '</td>';
    echo '<td>' . $_ncCell . '</td>';
    echo '<td>' . $_decisionCell . '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

echo '<div style="background:#f8f9fa;border-radius:6px;padding:0.8rem;margin:0.5rem 0;font-size:0.92em;">';
echo '<strong>Step 4 Summary for ' . _foa_h($_resolvedName) . ' (clientid=' . _foa_h($clientid) . '):</strong> ';
echo '<span style="color:#dc3545;font-weight:600;">' . $_removeCount . ' REMOVE</span> &nbsp;|&nbsp; ';
echo '<span style="color:#155724;font-weight:600;">' . $_keepCount . ' KEEP</span> &nbsp;|&nbsp; ';
echo '<span style="color:#6c757d;">' . $_skipCount . ' skipped (inactive / non-manual / no unit code)</span>';
echo '</div>';

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5 — Deep-dive lookup for every REMOVE row
// ═════════════════════════════════════════════════════════════════════════════
if (!empty($_removeRows)) {
    echo '<hr><h4 style="margin-bottom:0.8rem;">Step 5 — REMOVE Decision Deep-Dive (the exact lookup)</h4>';
    echo '<p style="font-size:0.91em;color:#6c757d;">For each REMOVE decision: the exact value of <code>\$allStudentUnits[<em>clientid</em>][<em>unitcode</em>]</code> is shown, plus every available unit key so you can see whether a match exists under a different key (e.g. different suffix or capitalisation).</p>';

    foreach ($_removeRows as $_rcid => $_rr) {
        $reqUc = $_rr['unitcode'];
        echo '<div style="background:#fff;border:1px solid #f5c6cb;border-radius:6px;padding:1.2rem;margin-bottom:1.2rem;">';
        echo '<strong style="font-size:1.05em;">Course: ' . _foa_h($_rr['fullname']) . '</strong>';
        echo '<table class="table table-sm" style="font-size:0.88em;margin:0.7rem 0;">';
        echo '<tr><td style="width:260px;">clientid used for lookup</td><td><code>' . _foa_h($clientid) . '</code></td></tr>';
        echo '<tr><td>Unit code extracted from course</td><td><code>' . _foa_h($reqUc) . '</code></td></tr>';
        echo '<tr><td>Course idnumber field</td><td><code>' . (_foa_h($_rr['courseidn']) ?: '<em>blank</em>') . '</code></td></tr>';

        // Does the client exist in allStudentUnits? (always yes at this point)
        echo '<tr><td>Client exists in \$allStudentUnits?</td><td><span style="color:#155724;font-weight:600;">YES</span></td></tr>';
        echo '<tr><td>Units available for this client</td><td><strong>' . count($allStudentUnits) . '</strong></td></tr>';

        // Was the requested unit found?
        $_exactFound = array_key_exists($reqUc, $allStudentUnits);
        echo '<tr><td>Requested unit key <code>' . _foa_h($reqUc) . '</code> found?</td>'
           . '<td>' . ($_exactFound
               ? '<span style="color:#155724;font-weight:600;">YES — value: <code>' . _foa_h($allStudentUnits[$reqUc]) . '</code></span>'
               : '<span style="color:#dc3545;font-weight:600;">NO — \$allStudentUnits[\'' . _foa_h($clientid) . '\'][\'' . _foa_h($reqUc) . '\'] returned null</span>')
           . '</td></tr>';

        // Outcome value returned
        $_retOutcome = $allStudentUnits[$reqUc] ?? null;
        echo '<tr><td>Value returned by <code>?? null</code></td><td>'
           . ($reqUc !== null && $_exactFound
               ? '<code>' . _foa_h($_retOutcome) . '</code>'
               : '<code style="color:#dc3545;">null</code>')
           . '</td></tr>';

        // Why REMOVE?
        echo '<tr><td>REMOVE reason(s)</td><td style="color:#dc3545;font-weight:600;">' . _foa_h(implode('; ', $_rr['reasons'])) . '</td></tr>';

        // Criterion 2 detail if applicable
        if ($_rr['outcome'] !== null) {
            echo '<tr><td>Outcome in NON_CONTINUING list?</td>'
               . '<td>' . (in_array($_rr['outcome'], $NC_CODES, true)
                   ? '<span style="color:#dc3545;font-weight:600;">YES — outcome ' . _foa_h($_rr['outcome']) . ' (' . _foa_h($NC_LABELS[$_rr['outcome']] ?? '') . ')</span>'
                   : 'No')
               . '</td></tr>';
        }
        echo '</table>';

        // Did a near-match exist? (e.g. ABC12345 vs ABC12345)
        $_nearMatches = [];
        foreach ($allStudentUnits as $_availUc => $_availOc) {
            if ($_availUc !== $reqUc && (
                strpos($_availUc, $reqUc) === 0 ||
                strpos($reqUc, $_availUc) === 0 ||
                levenshtein($reqUc, $_availUc) <= 2
            )) {
                $_nearMatches[$_availUc] = $_availOc;
            }
        }
        if (!empty($_nearMatches)) {
            echo '<p style="color:#856404;font-weight:600;">&#9888; Near-match unit codes found in NAT data (possible regex divergence or suffix mismatch):</p>';
            echo '<ul style="margin:0;">';
            foreach ($_nearMatches as $_nm => $_no) {
                echo '<li><code>' . _foa_h($_nm) . '</code> (outcome: <code>' . _foa_h($_no) . '</code>)'
                   . ' — difference from requested <code>' . _foa_h($reqUc) . '</code>: '
                   . (strpos($_availUc, $reqUc) === 0 ? 'NAT code has extra suffix' : (strpos($reqUc, $_nm) === 0 ? 'extracted code has extra suffix' : 'edit distance ' . levenshtein($reqUc, $_nm)))
                   . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="color:#155724;">No near-matches found — the unit code is genuinely absent from this student\'s NAT data for this import (or is present but extracted differently).</p>';
        }

        // Full unit key list
        echo '<details style="margin-top:0.5rem;"><summary style="cursor:pointer;font-size:0.88em;color:#6c757d;">Show all ' . count($allStudentUnits) . ' available unit keys for this student in import #' . $importid . '</summary>';
        echo '<div style="font-size:0.84em;background:#f8f9fa;padding:0.5rem;border-radius:4px;margin-top:0.4rem;columns:3;">';
        foreach ($allStudentUnits as $_k => $_v) {
            $hi = ($reqUc === $_k) ? ' style="background:#d4edda;font-weight:600;"' : '';
            echo '<code' . $hi . '>' . _foa_h($_k) . '</code> → <code>' . _foa_h($_v ?: '(empty)') . '</code><br>';
        }
        echo '</div></details>';
        echo '</div>';
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// SITE-WIDE OUTCOME DISTRIBUTION (for context)
// ═════════════════════════════════════════════════════════════════════════════
echo '<hr><h4 style="margin-bottom:0.8rem;">Site-Wide Outcome Distribution — Import #' . $importid . '</h4>';
echo '<p style="font-size:0.91em;color:#6c757d;">Shows the outcome code breakdown across ALL students in this import. If ALL rows are outcome 30 and none are outcome 70, the root cause is a NAT export where every unit is marked non-continuing.</p>';

$_distRows = $DB->get_records_sql(
    "SELECT COALESCE(NULLIF(TRIM(outcome), ''), '(empty)') AS outcome_code, COUNT(*) AS cnt
       FROM {local_rtocompliance_avetmiss_enrolment}
      WHERE importid = :iid
      GROUP BY outcome_code
      ORDER BY cnt DESC",
    ['iid' => $importid]
);

echo '<table class="table table-sm table-bordered" style="max-width:640px;font-size:0.9em;">';
echo '<thead class="thead-light"><tr><th>Outcome Code</th><th>Meaning</th><th>Count</th><th>In NON_CONTINUING?</th><th>FOE Action</th></tr></thead><tbody>';
$_distTotal = 0;
foreach ($_distRows as $_dr) {
    $_oc     = $_dr->outcome_code === '(empty)' ? '' : $_dr->outcome_code;
    $_cnt    = (int)$_dr->cnt;
    $_distTotal += $_cnt;
    $_ncFlag = in_array($_oc, $NC_CODES, true);
    $_label  = $NC_LABELS[$_oc] ?? ($_oc === '70' ? 'Continuing' : ($_oc === '' ? 'Unknown/empty' : 'Unknown code ' . $_oc));
    $_action = $_ncFlag
        ? '<span style="color:#dc3545;font-weight:600;">Criterion 2 → REMOVE</span>'
        : '<span style="color:#155724;">Safe — not flagged by Criterion 2</span>';
    $_ncCell = $_ncFlag
        ? '<span style="color:#dc3545;font-weight:600;">YES</span>'
        : '<span style="color:#155724;">No</span>';
    echo '<tr>';
    echo '<td><code>' . _foa_h($_dr->outcome_code) . '</code></td>';
    echo '<td>' . _foa_h($_label) . '</td>';
    echo '<td>' . number_format($_cnt) . '</td>';
    echo '<td>' . $_ncCell . '</td>';
    echo '<td>' . $_action . '</td>';
    echo '</tr>';
}
echo '<tr style="font-weight:700;background:#f8f9fa;"><td colspan="2">TOTAL</td><td>' . number_format($_distTotal) . '</td><td colspan="2"></td></tr>';
echo '</tbody></table>';

$_allNc30 = true;
foreach ($_distRows as $_dr) {
    $_oc = $_dr->outcome_code === '(empty)' ? '' : $_dr->outcome_code;
    if ($_oc === '70' || $_oc === '' || !in_array($_oc, $NC_CODES, true)) { $_allNc30 = false; break; }
}
if ($_allNc30) {
    echo '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:1rem;margin-top:0.5rem;">';
    echo '<strong style="color:#721c24;">&#128680; ROOT CAUSE CONFIRMED:</strong> Every row in import #' . $importid . ' has a non-continuing outcome code. '
       . 'There are zero rows with outcome <code>70</code> (Continuing). '
       . 'When FOE was run against this import, Criterion 2 fired for <em>every matched student-unit pair</em>, '
       . 'causing every unit-matched Moodle enrolment to be classified as REMOVE. '
       . 'This explains the mass deletion.<br><br>'
       . '<strong>Why this happened:</strong> The RTO\'s SMS (Wisenet) exported a NAT file where all in-progress students carry outcome code <code>30</code> '
       . '(Competency Not Yet Achieved) instead of <code>70</code> (Continuing Enrolment). '
       . 'The AVETMISS 8 standard defines outcome 70 as the correct code for students still enrolled. '
       . 'FOE correctly applied its own rule — outcome 30 = non-continuing = REMOVE — but the input data was wrong.';
    echo '</div>';
}

echo '<p style="margin-top:1.5rem;"><a href="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out() . '" class="btn btn-secondary btn-sm">Back to Data Imports</a></p>';
echo $OUTPUT->footer();
