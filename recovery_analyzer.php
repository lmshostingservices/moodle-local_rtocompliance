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
 * RTO Compliance plugin — recovery_analyzer.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// Enrolment Recovery Analyzer  (local_rtocompliance v5.9.118)
//
// READ-ONLY.  Never calls enrol_user(), unenrol_user(), or any write method.
// Safe to run on production at any time.
//
// PURPOSE
// -------
// Compare a Friday backup CSV export of Moodle enrolments against current
// live Moodle enrolments to answer:
//
//   Report 1 — Lost Enrolments     (Friday MINUS Current)
//              What did FoE remove?
//
//   Report 2 — New Since Friday    (Current MINUS Friday)
//              What was added after the backup? (IMIS, manual admin, etc.)
//
//   Report 3 — Recovery Candidates Classify each lost enrolment:
//              ✅ Restore   unit still in NAT, semester matches
//              ⚠  Review   unit in NAT, semester unclear
//              ❌ Skip      unit no longer in NAT
//
// INPUT
// -----
// Admin exports a CSV from the Friday backup database using the SQL template
// provided on the upload form.  Minimum columns: username, course_shortname.
//
// STUDENT MATCHING (current Moodle)
// ----------------------------------
// Uses username from the backup CSV to find the current Moodle user.
// Falls back to idnumber / client_id column if username lookup fails.
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_recovery_analyzer');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);
\core\session\manager::write_close();

// ── Parameters ───────────────────────────────────────────────────────────────
$action   = optional_param('action',   '', PARAM_ALPHA);
$token    = optional_param('token',    '', PARAM_ALPHANUM);
$download = optional_param('download', '', PARAM_ALPHA);
$importid = optional_param('importid', 0,  PARAM_INT);

// ── Helpers ──────────────────────────────────────────────────────────────────
function _ra_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function _ra_n(int $n): string    { return number_format($n); }
// Classify logstore event array into a human-readable history status.
// Returns one of: never_enrolled | currently_enrolled | enrolled_then_removed |
//                 restored_after_deletion | multiple_cycles | history_incomplete
function _ra_ls_history_status(array $events): string {
    if (empty($events)) return 'never_enrolled';
    $creates = 0; $deletes = 0; $lastEvt = '';
    foreach ($events as $_hse) {
        if (strpos($_hse['event'], 'enrolment_created') !== false) { $creates++; $lastEvt = 'c'; }
        if (strpos($_hse['event'], 'enrolment_deleted') !== false) { $deletes++; $lastEvt = 'd'; }
    }
    if ($creates === 0 && $deletes > 0) return 'history_incomplete';
    if ($creates > 1 || $deletes > 1)   return 'multiple_cycles';
    if ($creates > 0 && $deletes === 0) return 'currently_enrolled';
    if ($lastEvt === 'c')               return 'restored_after_deletion';
    if ($lastEvt === 'd')               return 'enrolled_then_removed';
    return 'never_enrolled';
}
function _ra_csvpath(string $tok, string $name): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rtoc_ra_' . $tok . '_' . $name . '.csv';
}

/**
 * Parse a CSV string (handles \r\n, \r, \n; quoted fields).
 * Returns [headers => [], rows => [assoc_array, ...]].
 * Returns null on empty input.
 */
function _ra_parse_csv(string $raw): ?array {
    $raw = str_replace("\r\n", "\n", str_replace("\r", "\n", $raw));
    $lines = explode("\n", trim($raw));
    if (count($lines) < 2) return null;
    $headers = str_getcsv(array_shift($lines));
    $headers = array_map('trim', $headers);
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        $row  = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($cols[$i]) ? trim($cols[$i]) : '';
        }
        $rows[] = $row;
    }
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Extract AVETMISS unit code from course idnumber / shortname / fullname.
 * Same regex as reconcile.php _reconcile_extract_unitcode().
 */
function _ra_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
    $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
    $idn = strtoupper(trim($idnumber));
    if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) return $idn;
    if (preg_match($pat, $idn, $m)) return $m[1];
    if (preg_match($pat, strtoupper(trim($shortname)), $m)) return $m[1];
    if (preg_match($pat, strtoupper(trim($fullname)),  $m)) return $m[1];
    return '';
}

// ── Download action ───────────────────────────────────────────────────────────
if ($action === 'download' && $download && $token) {
    $allowed = ['lost', 'new', 'recovery'];
    if (!in_array($download, $allowed, true)) {
        redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'));
    }
    $filenames = [
        'lost'     => 'lost_enrolments.csv',
        'new'      => 'new_since_friday.csv',
        'recovery' => 'recovery_candidates.csv',
    ];
    $path = _ra_csvpath($token, $download);
    if (!file_exists($path)) {
        redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenames[$download] . '"');
    header('Cache-Control: no-cache, must-revalidate');
    readfile($path);
    exit;
}

// ── SQL template for admin to run against the Friday backup ──────────────────
$_sqlTemplate = <<<'SQL'
SELECT
    u.username,
    u.idnumber          AS client_id,
    u.firstname,
    u.lastname,
    u.email,
    c.id                AS courseid,
    c.shortname         AS course_shortname,
    c.fullname          AS course_fullname,
    cc.name             AS category,
    FROM_UNIXTIME(ue.timestart) AS enrolled_on,
    e.enrol             AS enrol_method
FROM mdl_user_enrolments ue
JOIN mdl_enrol e              ON e.id  = ue.enrolid
JOIN mdl_user u               ON u.id  = ue.userid
JOIN mdl_course c             ON c.id  = e.courseid
LEFT JOIN mdl_course_categories cc ON cc.id = c.category
WHERE ue.status = 0
  AND u.deleted  = 0
  AND c.id <> 1
ORDER BY u.lastname, u.firstname, c.shortname
SQL;

// ── Default page — upload form ────────────────────────────────────────────────
if ($action !== 'analyse') {
    // Load available NAT imports for the optional comparison selector
    $_imports = $DB->get_records_sql(
        "SELECT id, collectionyear, timecreated
           FROM {local_rtocompliance_imports}
          ORDER BY id DESC
          LIMIT 10"
    );

    local_rtocompliance_render_nav_header($PAGE);
    $PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
    echo $OUTPUT->header();
    ?>
    <div class="rtoc-main-content">

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= (new moodle_url('/local/rtocompliance/reconcile.php'))->out() ?>">NAT Reconciliation</a></li>
        <li class="breadcrumb-item active">Enrolment Recovery Analyzer</li>
      </ol>
    </nav>

    <h3>&#128269; Enrolment Recovery Analyzer</h3>
    <div class="alert alert-info" style="font-size:0.92em;">
      <strong>Read-only — no changes are made to Moodle.</strong><br>
      Upload a CSV of enrolments exported from the <strong>Friday backup database</strong>.
      This tool will compare it against current live Moodle enrolments and produce three reports:
      Lost Enrolments, New Since Friday, and Recovery Candidates.
    </div>

    <div class="card mb-4" style="border:2px solid #0d6efd;">
      <div class="card-header" style="background:#0d6efd;color:#fff;font-weight:600;">
        Step 1 &mdash; Export enrolments from the Friday backup database
      </div>
      <div class="card-body">
        <p style="font-size:0.9em;">
          Run this SQL query against the <strong>Friday backup Moodle database</strong>
          (e.g. via phpMyAdmin, TablePlus, or MySQL CLI) and export the result as CSV.
        </p>
        <pre style="background:#1e1e1e;color:#d4d4d4;padding:1rem;border-radius:6px;font-size:0.8em;overflow-x:auto;"><?= _ra_h($_sqlTemplate) ?></pre>
        <small class="text-muted">
          Export as CSV with headers. Save the file and upload it below.
        </small>
      </div>
    </div>

    <div class="card mb-4" style="border:2px solid #0d6efd;">
      <div class="card-header" style="background:#0d6efd;color:#fff;font-weight:600;">
        Step 2 &mdash; Upload the backup CSV and run analysis
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data"
              action="<?= (new moodle_url('/local/rtocompliance/recovery_analyzer.php', ['action' => 'analyse']))->out(false) ?>">
          <?= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) ?>

          <div class="form-group mb-3">
            <label style="font-weight:600;">Friday backup enrolments CSV <span style="color:#dc3545;">*</span></label>
            <input type="file" name="backupcsv" accept=".csv,text/csv" class="form-control" required>
            <small class="form-text text-muted">
              Required columns: <code>username</code>, <code>course_shortname</code>.
              All other columns from the SQL template above are optional but recommended.
            </small>
          </div>

          <div class="form-group mb-3">
            <label style="font-weight:600;">NAT import for recovery classification <span style="font-weight:400;color:#6c757d;">(recommended)</span></label>
            <select name="importid" class="form-control" style="max-width:480px;">
              <option value="0">— Skip NAT comparison (Reports 1 &amp; 2 only) —</option>
              <?php foreach ($_imports as $_imp): ?>
              <option value="<?= (int)$_imp->id ?>">
                Import #<?= (int)$_imp->id ?>
                <?= $_imp->collectionyear ? ' (' . _ra_h($_imp->collectionyear) . ')' : '' ?>
                &mdash; <?= date('d M Y', (int)$_imp->timecreated) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">
              If selected, Report 3 (Recovery Candidates) will classify each lost enrolment
              using the NAT data and semester matching.
            </small>
          </div>

          <button type="submit" class="btn btn-primary">
            &#128202; Run Recovery Analysis
          </button>
        </form>
      </div>
    </div>

    <hr>
    <h5>&#128203; What each report contains</h5>
    <div class="row" style="max-width:900px;">
      <?php
      $docs = [
          ['Lost Enrolments',      'danger',  'lost_enrolments.csv',
           'Enrolments present in the Friday backup that are missing from current Moodle. These are the enrolments that FoE removed (or that were manually removed since Friday).'],
          ['New Since Friday',     'warning', 'new_since_friday.csv',
           'Enrolments present in current Moodle that did NOT exist in the Friday backup. Likely IMIS enrolments, manual admin additions, or auto-enrol wizard runs since Friday.'],
          ['Recovery Candidates',  'success', 'recovery_candidates.csv',
           'Every lost enrolment classified as: ✅ Restore (unit still in NAT, semester matches), ⚠ Review (in NAT, semester unclear or no NAT loaded), or ❌ Skip (unit not in NAT).'],
      ];
      foreach ($docs as [$title, $clr, $file, $desc]): ?>
      <div class="col-md-4 mb-3">
        <div class="card h-100 border-<?= $clr ?>">
          <div class="card-header bg-<?= $clr ?> text-white fw-bold" style="font-size:0.85em;"><?= _ra_h($title) ?></div>
          <div class="card-body" style="font-size:0.83em;">
            <code><?= _ra_h($file) ?></code><br><br>
            <?= $desc ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ANALYSE ACTION
// ─────────────────────────────────────────────────────────────────────────────
require_sesskey();

if (empty($_FILES['backupcsv']['tmp_name']) || !is_uploaded_file($_FILES['backupcsv']['tmp_name'])) {
    redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'), 'No file uploaded.', null, \core\output\notification::NOTIFY_ERROR);
}

$rawCsv = file_get_contents($_FILES['backupcsv']['tmp_name']);
if ($rawCsv === false || strlen(trim($rawCsv)) < 10) {
    redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'), 'Uploaded file is empty or unreadable.', null, \core\output\notification::NOTIFY_ERROR);
}

$parsed = _ra_parse_csv($rawCsv);
if ($parsed === null || count($parsed['rows']) === 0) {
    redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'), 'CSV could not be parsed or has no data rows.', null, \core\output\notification::NOTIFY_ERROR);
}

// ── Validate required columns ─────────────────────────────────────────────────
$headers = array_map('strtolower', $parsed['headers']);
$hasUsername = in_array('username', $headers, true);
$hasShortname = in_array('course_shortname', $headers, true);
if (!$hasUsername || !$hasShortname) {
    redirect(new moodle_url('/local/rtocompliance/recovery_analyzer.php'),
        'CSV must contain "username" and "course_shortname" columns. Found: ' . implode(', ', $headers),
        null, \core\output\notification::NOTIFY_ERROR);
}

// ── Step 1: Parse backup CSV into keyed structures ────────────────────────────
// $fridayRows[lc_username][lc_shortname] = row_data_array
// Also build a username→clientid map for NAT matching
$fridayRows    = []; // lc_username → [lc_shortname → row]
$usernameToClientId = []; // lc_username → client_id (for NAT lookup)
$lcUsernameSet = [];

foreach ($parsed['rows'] as $_row) {
    // Normalise column names to lowercase
    $_nr = [];
    foreach ($_row as $k => $v) { $_nr[strtolower($k)] = $v; }

    $_un  = strtolower(trim($_nr['username'] ?? ''));
    $_sn  = strtolower(trim($_nr['course_shortname'] ?? ''));
    if ($_un === '' || $_sn === '') continue;

    if (!isset($fridayRows[$_un])) $fridayRows[$_un] = [];
    if (!isset($fridayRows[$_un][$_sn])) {
        $fridayRows[$_un][$_sn] = [
            'username'        => $_nr['username'] ?? $_un,
            'client_id'       => $_nr['client_id'] ?? ($_nr['idnumber'] ?? ''),
            'firstname'       => $_nr['firstname'] ?? '',
            'lastname'        => $_nr['lastname'] ?? '',
            'email'           => $_nr['email'] ?? '',
            'courseid'        => (int)($_nr['courseid'] ?? 0),
            'course_shortname'=> $_nr['course_shortname'] ?? $_sn,
            'course_fullname' => $_nr['course_fullname'] ?? '',
            'category'        => $_nr['category'] ?? '',
            'enrolled_on'     => $_nr['enrolled_on'] ?? '',
            'enrol_method'    => $_nr['enrol_method'] ?? '',
        ];
    }
    $lcUsernameSet[$_un] = true;
    $cid = $_nr['client_id'] ?? ($_nr['idnumber'] ?? '');
    if ($cid !== '' && !isset($usernameToClientId[$_un])) {
        $usernameToClientId[$_un] = strtolower(trim($cid));
    }
}

$_totalBackupRows = array_sum(array_map('count', $fridayRows));

// ── Step 2: Resolve backup usernames → current Moodle userids ────────────────
// $uidToUser[userid] = stdClass {username, idnumber, firstname, lastname}
// $usernameToUid[lc_username] = userid
// $idnumberToUid[lc_idnumber] = userid
$usernameToUid = [];
$idnumberToUid = [];
$uidToUser     = [];

$_lcUsernames = array_keys($lcUsernameSet);
if (!empty($_lcUsernames)) {
    $chunks = array_chunk($_lcUsernames, 500);
    foreach ($chunks as $chunk) {
        list($_s, $_p) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rau');
        $_rs = $DB->get_recordset_sql(
            "SELECT id, LOWER(username) AS lc_un, LOWER(idnumber) AS lc_idn,
                    username, idnumber, firstname, lastname
               FROM {user} WHERE LOWER(username) $_s AND deleted = 0", $_p);
        foreach ($_rs as $_u) {
            $uidToUser[(int)$_u->id]            = $_u;
            $usernameToUid[trim((string)$_u->lc_un)]  = (int)$_u->id;
            if ($_u->lc_idn !== '') {
                $idnumberToUid[trim((string)$_u->lc_idn)] = (int)$_u->id;
            }
        }
        $_rs->close();
    }
}

// Build lc_username → userid  (try username first, then idnumber from backup)
$resolvedUids = []; // lc_username → userid (or 0 if unresolved)
foreach ($lcUsernameSet as $_un => $_) {
    $uid = $usernameToUid[$_un] ?? null;
    if ($uid === null) {
        $cid = $usernameToClientId[$_un] ?? '';
        if ($cid !== '') $uid = $idnumberToUid[$cid] ?? null;
    }
    $resolvedUids[$_un] = $uid ?? 0;
}

$_matchedUserids   = array_filter($resolvedUids);   // lc_username → userid (non-zero)
$_unmatchedCount   = count(array_filter($resolvedUids, fn($v) => $v === 0));
$_allCurrentUids   = array_unique(array_values($_matchedUserids));

// ── Step 3: Load current Moodle manual enrolments for those users ─────────────
// $currentEnrolled[userid][lc_shortname] = [courseid, shortname, fullname, category, timestart]
$currentEnrolled = [];
if (!empty($_allCurrentUids)) {
    $chunks = array_chunk($_allCurrentUids, 500);
    foreach ($chunks as $chunk) {
        list($_s, $_p) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cur');
        $_rs = $DB->get_recordset_sql(
            "SELECT ue.userid, c.id AS courseid,
                    LOWER(c.shortname) AS lc_sn, c.shortname, c.fullname,
                    c.idnumber, cc.name AS catname,
                    ue.timestart, e.enrol
               FROM {user_enrolments} ue
               JOIN {enrol}  e  ON e.id  = ue.enrolid
               JOIN {course} c  ON c.id  = e.courseid
               LEFT JOIN {course_categories} cc ON cc.id = c.category
              WHERE ue.userid $_s
                AND ue.status = 0
                AND c.id <> 1", $_p);
        foreach ($_rs as $_row) {
            $_uid = (int)$_row->userid;
            $_sn  = trim((string)$_row->lc_sn);
            if (!isset($currentEnrolled[$_uid])) $currentEnrolled[$_uid] = [];
            if (!isset($currentEnrolled[$_uid][$_sn])) {
                $currentEnrolled[$_uid][$_sn] = [
                    'courseid'  => (int)$_row->courseid,
                    'shortname' => (string)$_row->shortname,
                    'fullname'  => (string)$_row->fullname,
                    'idnumber'  => (string)$_row->idnumber,
                    'category'  => (string)($_row->catname ?? ''),
                    'timestart' => (int)$_row->timestart,
                    'enrol'     => (string)$_row->enrol,
                ];
            }
        }
        $_rs->close();
    }
}

// ── Step 4: Load NAT data (optional — for Report 3) ──────────────────────────
$natUnits       = []; // lc_clientid → [UNITCODE → outcome]
$natStartdate   = []; // lc_clientid → [UNITCODE → startdate DDMMYYYY]
$importRec      = null;
$natLoaded      = false;

if ($importid > 0) {
    $importRec = $DB->get_record('local_rtocompliance_imports', ['id' => $importid]);
    if ($importRec) {
        $natLoaded = true;
        $_natRs = $DB->get_recordset_select(
            'local_rtocompliance_avetmiss_enrolment',
            'importid = :iid', ['iid' => $importid], '',
            'clientid, unitcode, outcome, startdate'
        );
        foreach ($_natRs as $_nr) {
            $_lc = strtolower(trim((string)$_nr->clientid));
            $_uc = strtoupper(trim((string)$_nr->unitcode));
            if ($_lc === '' || $_uc === '') continue;
            $natUnits[$_lc][$_uc]     = trim((string)$_nr->outcome);
            $natStartdate[$_lc][$_uc] = trim((string)$_nr->startdate);
        }
        $_natRs->close();
    }
}

// Build course maps for all courses in current Moodle.
// $courseUnitCode[lc_shortname] = UNITCODE   (used for Report 3 NAT classification)
// $courseIdByLcSn[lc_shortname] = courseid   (used for Step 5b logstore query)
$courseUnitCode = [];
$courseIdByLcSn = [];
$_crsAll = $DB->get_recordset_sql(
    "SELECT c.id, c.shortname, c.fullname, c.idnumber
       FROM {course} c WHERE c.id <> 1"
);
foreach ($_crsAll as $_ca) {
    $_sna = strtolower(trim((string)$_ca->shortname));
    $courseIdByLcSn[$_sna] = (int)$_ca->id;
    if ($natLoaded) {
        $_uca = _ra_extract_unitcode(
            (string)$_ca->idnumber, (string)$_ca->shortname, (string)$_ca->fullname
        );
        if ($_uca !== '') $courseUnitCode[$_sna] = $_uca;
    }
}
$_crsAll->close();

// ── Step 5: Build the three reports ──────────────────────────────────────────
$rptLost     = []; // rows for Report 1
$rptNew      = []; // rows for Report 2
$rptRecovery = []; // rows for Report 3

// ── Report 1 & 3: Lost Enrolments (Friday MINUS Current) ──────────────────────
$_countLostRestore = 0;
$_countLostReview  = 0;
$_countLostSkip    = 0;

foreach ($fridayRows as $lcUn => $courses) {
    $uid = $resolvedUids[$lcUn] ?? 0;
    foreach ($courses as $lcSn => $row) {
        $stillEnrolled = ($uid > 0 && isset($currentEnrolled[$uid][$lcSn]));
        if ($stillEnrolled) continue; // Not lost — still enrolled

        // This enrolment is lost.
        $lostRow = [
            'username'         => $row['username'],
            'client_id'        => $row['client_id'],
            'firstname'        => $row['firstname'],
            'lastname'         => $row['lastname'],
            'course_shortname' => $row['course_shortname'],
            'course_fullname'  => $row['course_fullname'],
            'category'         => $row['category'],
            'enrolled_on'      => $row['enrolled_on'],
            'enrol_method'     => $row['enrol_method'],
            'moodle_matched'   => $uid > 0 ? 'YES' : 'NO (username not found in current Moodle)',
        ];
        $rptLost[] = $lostRow;

        // ── Recovery classification (Report 3) ────────────────────────────────
        $classification = '⚠ Review';
        $classReason    = 'No NAT import selected — manual review required';

        if ($natLoaded) {
            // Map backup client_id → NAT clientid key
            $lcCid = strtolower(trim($row['client_id']));
            if ($lcCid === '' && $uid > 0) {
                // Try to get clientid from current Moodle user's idnumber
                $lcCid = strtolower(trim($uidToUser[$uid]->idnumber ?? ''));
            }

            $unitCode = $courseUnitCode[$lcSn] ?? '';

            if ($unitCode === '') {
                $classification = '⚠ Review';
                $classReason    = 'Cannot extract unit code from course shortname — verify manually';
            } elseif ($lcCid !== '' && isset($natUnits[$lcCid][$unitCode])) {
                $classification = '✅ Restore';
                $classReason    = 'Unit ' . $unitCode . ' is present in NAT data for this student';
                $_countLostRestore++;
            } elseif ($lcCid !== '' && isset($natUnits[$lcCid])) {
                $classification = '❌ Skip';
                $classReason    = 'Unit ' . $unitCode . ' is NOT present in NAT data for this student';
                $_countLostSkip++;
            } elseif ($lcCid === '') {
                $classification = '⚠ Review';
                $classReason    = 'Student not matched to NAT data (no client ID) — verify manually';
                $_countLostReview++;
            } else {
                $classification = '⚠ Review';
                $classReason    = 'Student client ID "' . $lcCid . '" not found in NAT import — verify manually';
                $_countLostReview++;
            }
        } else {
            $_countLostReview++;
        }

        $rptRecovery[] = array_merge($lostRow, [
            'unit_code'      => $courseUnitCode[$lcSn] ?? '',
            'classification' => $classification,
            'reason'         => $classReason,
            // Internal fields for Step 5b logstore enrichment (not in CSV output).
            '_uid'           => $uid,
            '_courseid'      => $courseIdByLcSn[$lcSn] ?? 0,
            // Placeholder fields populated by Step 5b.
            'created_at'     => '',
            'deleted_at'     => '',
            'deleted_by'     => '',
        ]);
    }
}

// ── Step 5b: Logstore enrolment history for lost enrolments ──────────────────
// Batch-queries mdl_logstore_standard_log for creation + deletion events per
// student+course pair.  Uids chunked in groups of 500 for scalability.
// Gracefully skips if logstore table is absent.
$raLogstoreHistory    = []; // uid:cid => [[event, ts, actor], ...] ORDER BY timecreated ASC
$raLogstoreActorNames = []; // userid => username
$raLogstoreAvailable  = false;

try { $raLogstoreAvailable = $DB->get_manager()->table_exists('logstore_standard_log'); }
catch (Throwable $_rex5b) { $raLogstoreAvailable = false; }

if ($raLogstoreAvailable && !empty($rptRecovery)) {
    $_rLsUids = [];
    $_rLsCids = [];
    foreach ($rptRecovery as $_rr5b) {
        if ($_rr5b['_uid'] > 0)      $_rLsUids[$_rr5b['_uid']]      = true;
        if ($_rr5b['_courseid'] > 0) $_rLsCids[$_rr5b['_courseid']] = true;
    }
    if (!empty($_rLsUids) && !empty($_rLsCids)) {
        $_raActorSet5b = [];
        $_rCidsAll5b   = array_keys($_rLsCids);
        // Chunk uids into groups of 500 to avoid over-long IN clauses.
        foreach (array_chunk(array_keys($_rLsUids), 500) as $_rUidChunk5b) {
            [$_ruSql5b, $_ruP5b] = $DB->get_in_or_equal($_rUidChunk5b,  SQL_PARAMS_NAMED, 'rlu');
            [$_rcSql5b, $_rcP5b] = $DB->get_in_or_equal($_rCidsAll5b,   SQL_PARAMS_NAMED, 'rlc');
            $_rlRs5b = $DB->get_recordset_sql(
                "SELECT relateduserid, courseid, eventname, userid AS actor, timecreated
                   FROM {logstore_standard_log}
                  WHERE eventname IN ('\core\event\user_enrolment_created',
                                      '\core\event\user_enrolment_deleted')
                    AND relateduserid $_ruSql5b
                    AND courseid $_rcSql5b
                  ORDER BY timecreated ASC",
                array_merge($_ruP5b, $_rcP5b)
            );
            foreach ($_rlRs5b as $_rlr5b) {
                $rk5b = (int)$_rlr5b->relateduserid . ':' . (int)$_rlr5b->courseid;
                $raLogstoreHistory[$rk5b][] = [
                    'event' => (string)$_rlr5b->eventname,
                    'ts'    => (int)$_rlr5b->timecreated,
                    'actor' => (int)$_rlr5b->actor,  // userid = who Moodle considers performed the action
                ];
                if ((int)$_rlr5b->actor > 0) $_raActorSet5b[(int)$_rlr5b->actor] = true;
            }
            $_rlRs5b->close();
        }
        if (!empty($_raActorSet5b)) {
            [$_raSql5b, $_raP5b] = $DB->get_in_or_equal(array_keys($_raActorSet5b), SQL_PARAMS_NAMED, 'rla');
            $_raARs5b = $DB->get_recordset_sql("SELECT id, username FROM {user} WHERE id $_raSql5b", $_raP5b);
            foreach ($_raARs5b as $_raa5b) { $raLogstoreActorNames[(int)$_raa5b->id] = (string)$_raa5b->username; }
            $_raARs5b->close();
        }
    }
}

// Enrich rptRecovery with logstore data and derive History Status.
// created_at / deleted_at / deleted_by use the FIRST create and LAST delete
// (for backward CSV compatibility). history_status is derived from the full
// event array, correctly handling multiple enrolment cycles, restore-after-
// delete, and deleted-without-create scenarios.
foreach ($rptRecovery as &$_rrecRef) {
    $rk          = $_rrecRef['_uid'] . ':' . $_rrecRef['_courseid'];
    $_rEvs5b     = $raLogstoreHistory[$rk] ?? [];
    $_rCreated5b = null; $_rDeleted5b = null;
    foreach ($_rEvs5b as $_rev5b) {
        // First create (for created_at CSV column).
        if (strpos($_rev5b['event'], 'enrolment_created') !== false && $_rCreated5b === null) {
            $_rCreated5b = $_rev5b;
        }
        // Always overwrite deleted with latest (last delete = current state).
        if (strpos($_rev5b['event'], 'enrolment_deleted') !== false) {
            $_rDeleted5b = $_rev5b;
        }
    }
    if ($_rCreated5b) {
        $_rrecRef['created_at'] = date('Y-m-d', $_rCreated5b['ts']);
    } elseif (!empty($_rEvs5b)) {
        // Deleted without a preceding create in logstore (truncated/imported logs).
        $_rrecRef['created_at'] = 'Unknown';
    }
    if ($_rDeleted5b) {
        $_rrecRef['deleted_at'] = date('Y-m-d', $_rDeleted5b['ts']);
        $_rrecRef['deleted_by'] = $raLogstoreActorNames[$_rDeleted5b['actor']] ?? 'user#' . $_rDeleted5b['actor'];
    }
    $_rrecRef['history_status']   = _ra_ls_history_status($_rEvs5b);
    $_rrecRef['_logstore_events'] = $_rEvs5b; // full event list for HTML timeline
}
unset($_rrecRef);

// ── Report 2: New Since Friday (Current MINUS Friday) ──────────────────────────
foreach ($resolvedUids as $lcUn => $uid) {
    if ($uid === 0) continue;
    $_fridayForUser = $fridayRows[$lcUn] ?? [];
    foreach ($currentEnrolled[$uid] ?? [] as $lcSn => $enr) {
        if (isset($_fridayForUser[$lcSn])) continue; // Was on Friday — not new
        $u = $uidToUser[$uid];
        $rptNew[] = [
            'username'         => $u->username ?? $lcUn,
            'client_id'        => $u->idnumber  ?? '',
            'firstname'        => $u->firstname  ?? '',
            'lastname'         => $u->lastname   ?? '',
            'course_shortname' => $enr['shortname'],
            'course_fullname'  => $enr['fullname'],
            'category'         => $enr['category'],
            'enrolled_on'      => $enr['timestart'] ? date('Y-m-d H:i', $enr['timestart']) : '',
            'enrol_method'     => $enr['enrol'],
            'unit_code'        => $courseUnitCode[$lcSn] ?? '',
        ];
    }
}

// ── Step 6: Write CSV temp files ──────────────────────────────────────────────
$newToken = bin2hex(random_bytes(16));

// Report 1 — Lost
$fLost = fopen(_ra_csvpath($newToken, 'lost'), 'w');
fputcsv($fLost, ['username','client_id','firstname','lastname',
    'course_shortname','course_fullname','category','enrolled_on','enrol_method','moodle_matched']);
foreach ($rptLost as $r) {
    fputcsv($fLost, [$r['username'],$r['client_id'],$r['firstname'],$r['lastname'],
        $r['course_shortname'],$r['course_fullname'],$r['category'],$r['enrolled_on'],
        $r['enrol_method'],$r['moodle_matched']]);
}
fclose($fLost);

// Report 2 — New
$fNew = fopen(_ra_csvpath($newToken, 'new'), 'w');
fputcsv($fNew, ['username','client_id','firstname','lastname',
    'course_shortname','course_fullname','category','enrolled_on','enrol_method','unit_code']);
foreach ($rptNew as $r) {
    fputcsv($fNew, [$r['username'],$r['client_id'],$r['firstname'],$r['lastname'],
        $r['course_shortname'],$r['course_fullname'],$r['category'],$r['enrolled_on'],
        $r['enrol_method'],$r['unit_code']]);
}
fclose($fNew);

// Report 3 — Recovery Candidates (includes logstore history columns)
$fRec = fopen(_ra_csvpath($newToken, 'recovery'), 'w');
fputcsv($fRec, ['username','client_id','firstname','lastname',
    'course_shortname','course_fullname','category','enrolled_on','enrol_method',
    'unit_code','classification','reason','created_at','deleted_at','deleted_by',
    'history_status']);
foreach ($rptRecovery as $r) {
    fputcsv($fRec, [$r['username'],$r['client_id'],$r['firstname'],$r['lastname'],
        $r['course_shortname'],$r['course_fullname'],$r['category'],$r['enrolled_on'],
        $r['enrol_method'],$r['unit_code'],$r['classification'],$r['reason'],
        $r['created_at'] ?? '', $r['deleted_at'] ?? '', $r['deleted_by'] ?? '',
        $r['history_status'] ?? '']);
}
fclose($fRec);

// ── Step 7: Render results ────────────────────────────────────────────────────
$_dlBase = new moodle_url('/local/rtocompliance/recovery_analyzer.php', [
    'action' => 'download',
    'token'  => $newToken,
]);

local_rtocompliance_render_nav_header($PAGE);
echo $OUTPUT->header();
?>
<div class="rtoc-main-content">

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= (new moodle_url('/local/rtocompliance/recovery_analyzer.php'))->out() ?>">Enrolment Recovery Analyzer</a></li>
    <li class="breadcrumb-item active">Results</li>
  </ol>
</nav>

<h3>&#128202; Recovery Analysis Results</h3>
<p class="text-muted" style="font-size:0.93em;">
  Backup rows parsed: <strong><?= _ra_n($_totalBackupRows) ?></strong> &nbsp;|&nbsp;
  Unique students in backup: <strong><?= _ra_n(count($fridayRows)) ?></strong> &nbsp;|&nbsp;
  Matched to current Moodle: <strong><?= _ra_n(count(array_filter($resolvedUids))) ?></strong>
  <?php if ($_unmatchedCount > 0): ?>
  &nbsp;|&nbsp; <span style="color:#dc3545;">Not found in current Moodle: <strong><?= _ra_n($_unmatchedCount) ?></strong></span>
  <?php endif; ?>
  <?php if ($natLoaded && $importRec): ?>
  &nbsp;|&nbsp; NAT Import: <strong>#<?= (int)$importRec->id ?>
  <?= $importRec->collectionyear ? '(' . _ra_h($importRec->collectionyear) . ')' : '' ?></strong>
  <?php endif; ?>
</p>

<!-- Summary cards -->
<div class="row mb-4" style="max-width:860px;">
  <div class="col-md-4">
    <div class="card text-white bg-danger mb-3">
      <div class="card-body text-center">
        <div style="font-size:2.2rem;font-weight:700;"><?= _ra_n(count($rptLost)) ?></div>
        <div>Lost Enrolments</div>
        <small>(FoE removed)</small>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-white bg-warning mb-3">
      <div class="card-body text-center">
        <div style="font-size:2.2rem;font-weight:700;"><?= _ra_n(count($rptNew)) ?></div>
        <div>New Since Friday</div>
        <small>(IMIS / manual admin)</small>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-white bg-success mb-3">
      <div class="card-body text-center">
        <div style="font-size:2.2rem;font-weight:700;"><?= _ra_n($_countLostRestore) ?></div>
        <div>&#10003; Restore</div>
        <small><?= _ra_n($_countLostReview) ?> review &nbsp; <?= _ra_n($_countLostSkip) ?> skip</small>
      </div>
    </div>
  </div>
</div>

<!-- Download buttons -->
<div class="mb-4">
  <h5>&#128229; Download reports</h5>
  <a href="<?= $_dlBase->out(false, ['download' => 'lost']) ?>"
     class="btn btn-danger" style="margin-right:0.5rem;">
    &#128229; lost_enrolments.csv
    <span class="badge bg-light text-danger ms-1"><?= count($rptLost) ?></span>
  </a>
  <a href="<?= $_dlBase->out(false, ['download' => 'new']) ?>"
     class="btn btn-warning" style="margin-right:0.5rem;">
    &#128229; new_since_friday.csv
    <span class="badge bg-light text-dark ms-1"><?= count($rptNew) ?></span>
  </a>
  <a href="<?= $_dlBase->out(false, ['download' => 'recovery']) ?>"
     class="btn btn-success" style="margin-right:0.5rem;">
    &#128229; recovery_candidates.csv
    <span class="badge bg-light text-success ms-1"><?= count($rptRecovery) ?></span>
  </a>
</div>

<?php if ($natLoaded): ?>
<!-- Recovery Candidates preview (first 50 rows) -->
<div class="card mb-4" style="border:2px solid #198754;">
  <div class="card-header" style="background:#198754;color:#fff;font-weight:600;cursor:pointer;"
       onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none';">
    &#10003; Recovery Candidates preview (first 50 of <?= _ra_n(count($rptRecovery)) ?>) — click to expand
  </div>
  <div class="card-body" style="display:none;overflow-x:auto;">
    <table class="table table-sm table-bordered" style="font-size:0.82em;">
      <thead>
        <tr>
          <th>Student</th><th>Course</th><th>Unit code</th>
          <th>Category</th><th>Enrolled on (Fri)</th><th>Classification</th>
          <th>History Status</th><th>Enrolment Timeline</th>
        </tr>
      </thead>
      <tbody>
      <?php
      // Status chip meta (shared for all rows).
      $_raStatusMeta = [
          'never_enrolled'         => ['Never Enrolled',           '#6c757d'],
          'currently_enrolled'     => ['Currently Enrolled',       '#198754'],
          'enrolled_then_removed'  => ['Enrolled then Removed',    '#dc3545'],
          'restored_after_deletion'=> ['Restored after Deletion',  '#0d6efd'],
          'multiple_cycles'        => ['Multiple Enrolment Cycles','#fd7e14'],
          'history_incomplete'     => ['History Incomplete',       '#6c757d'],
          'never_enrolled'         => ['Never Enrolled',           '#6c757d'],
      ];
      foreach (array_slice($rptRecovery, 0, 50) as $_rv):
        $_bg = str_starts_with($_rv['classification'], '✅') ? '#d1e7dd'
             : (str_starts_with($_rv['classification'], '❌') ? '#f8d7da' : '#fff3cd');
        // History Status chip.
        $_rvStatus = $_rv['history_status'] ?? 'never_enrolled';
        [$_rvStLabel, $_rvStColor] = $_raStatusMeta[$_rvStatus] ?? ['Unknown', '#6c757d'];
        $_statusChip = '<span style="display:inline-block;padding:0.1rem 0.5rem;border-radius:3px;font-size:0.82em;font-weight:700;white-space:nowrap;background:' . $_rvStColor . ';color:#fff;">' . _ra_h($_rvStLabel) . '</span>';
        // Full timeline cell — iterate all events in chronological order.
        $_timelineCell = '<span style="color:#adb5bd;font-size:0.9em;">—</span>';
        if ($raLogstoreAvailable) {
            $_rvEvs = $_rv['_logstore_events'] ?? null;
            if ($_rvEvs !== null && !empty($_rvEvs)) {
                $_rvParts = [];
                foreach ($_rvEvs as $_rvEv) {
                    $_rvActor = $raLogstoreActorNames[$_rvEv['actor']] ?? 'user#' . $_rvEv['actor'];
                    if (strpos($_rvEv['event'], 'enrolment_created') !== false) {
                        $_rvParts[] = '<span style="color:#198754;">&#10003; ' . date('d M Y', $_rvEv['ts']) . '</span>';
                    } elseif (strpos($_rvEv['event'], 'enrolment_deleted') !== false) {
                        $_rvParts[] = '<span style="color:#dc3545;">&#10007; ' . date('d M Y', $_rvEv['ts'])
                                   . ' <code style="font-size:0.95em;">' . _ra_h($_rvActor) . '</code></span>';
                    }
                }
                if (!empty($_rvParts)) {
                    $_timelineCell = implode(' &rarr; ', $_rvParts);
                    if (in_array($_rvStatus, ['enrolled_then_removed','history_incomplete','multiple_cycles'])) {
                        $_timelineCell .= ' &rarr; <strong style="color:#0d6efd;">&#10140; Restore</strong>';
                    }
                } else {
                    $_timelineCell = '<span style="color:#adb5bd;font-size:0.9em;">No relevant events</span>';
                }
            } elseif ($_rvEvs !== null) {
                $_timelineCell = '<span style="color:#adb5bd;font-size:0.9em;">No events in logstore</span>';
            } elseif ($_rv['_uid'] > 0 && $_rv['_courseid'] > 0) {
                $_timelineCell = '<span style="color:#adb5bd;font-size:0.9em;">No logstore record</span>';
            }
        }
      ?>
      <tr style="background:<?= $_bg ?>;">
        <td><?= _ra_h($_rv['firstname'] . ' ' . $_rv['lastname']) ?>
            <br><small style="color:#6c757d;"><?= _ra_h($_rv['username']) ?></small></td>
        <td><code><?= _ra_h($_rv['course_shortname']) ?></code></td>
        <td><code><?= _ra_h($_rv['unit_code']) ?></code></td>
        <td><?= _ra_h($_rv['category']) ?></td>
        <td><?= _ra_h($_rv['enrolled_on']) ?></td>
        <td><strong><?= _ra_h($_rv['classification']) ?></strong>
            <br><small style="color:#6c757d;font-size:0.85em;"><?= _ra_h($_rv['reason']) ?></small></td>
        <td><?= $_statusChip ?></td>
        <td style="font-size:0.85em;"><?= $_timelineCell ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($rptRecovery) > 50): ?>
    <p class="text-muted" style="font-size:0.85em;">
      Showing 50 of <?= _ra_n(count($rptRecovery)) ?> rows. Download the CSV for the full list.
    </p>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info" style="font-size:0.9em;">
  <strong>Report 3 (Recovery Candidates)</strong> requires a NAT import selection.
  <a href="<?= (new moodle_url('/local/rtocompliance/recovery_analyzer.php'))->out() ?>">
    Run again with a NAT import selected</a> to classify lost enrolments.
</div>
<?php endif; ?>

<!-- Lost Enrolments preview (first 50) -->
<div class="card mb-4" style="border:2px solid #dc3545;">
  <div class="card-header" style="background:#dc3545;color:#fff;font-weight:600;cursor:pointer;"
       onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none';">
    &#128683; Lost Enrolments preview (first 50 of <?= _ra_n(count($rptLost)) ?>) — click to expand
  </div>
  <div class="card-body" style="display:none;overflow-x:auto;">
    <?php if (empty($rptLost)): ?>
    <p class="text-success">&#9989; No lost enrolments — Friday backup matches current Moodle exactly.</p>
    <?php else: ?>
    <table class="table table-sm table-bordered" style="font-size:0.82em;">
      <thead>
        <tr>
          <th>Student</th><th>Course</th><th>Category</th>
          <th>Enrolled on (Fri)</th><th>Enrol method</th><th>Moodle match</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($rptLost, 0, 50) as $_r): ?>
      <tr>
        <td><?= _ra_h($_r['firstname'] . ' ' . $_r['lastname']) ?>
            <br><small style="color:#6c757d;"><?= _ra_h($_r['username']) ?></small></td>
        <td><code><?= _ra_h($_r['course_shortname']) ?></code></td>
        <td><?= _ra_h($_r['category']) ?></td>
        <td><?= _ra_h($_r['enrolled_on']) ?></td>
        <td><?= _ra_h($_r['enrol_method']) ?></td>
        <td><?= _ra_h($_r['moodle_matched']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($rptLost) > 50): ?>
    <p class="text-muted" style="font-size:0.85em;">Showing 50 of <?= _ra_n(count($rptLost)) ?>. Download the CSV for the full list.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- New Since Friday preview -->
<div class="card mb-4" style="border:2px solid #ffc107;">
  <div class="card-header" style="background:#ffc107;color:#000;font-weight:600;cursor:pointer;"
       onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none';">
    &#43; New Since Friday preview (first 50 of <?= _ra_n(count($rptNew)) ?>) — click to expand
  </div>
  <div class="card-body" style="display:none;overflow-x:auto;">
    <?php if (empty($rptNew)): ?>
    <p>No new enrolments since Friday for the matched students.</p>
    <?php else: ?>
    <table class="table table-sm table-bordered" style="font-size:0.82em;">
      <thead>
        <tr>
          <th>Student</th><th>Course</th><th>Unit code</th>
          <th>Category</th><th>Enrolled on</th><th>Method</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($rptNew, 0, 50) as $_r): ?>
      <tr>
        <td><?= _ra_h($_r['firstname'] . ' ' . $_r['lastname']) ?>
            <br><small style="color:#6c757d;"><?= _ra_h($_r['username']) ?></small></td>
        <td><code><?= _ra_h($_r['course_shortname']) ?></code></td>
        <td><code><?= _ra_h($_r['unit_code']) ?></code></td>
        <td><?= _ra_h($_r['category']) ?></td>
        <td><?= _ra_h($_r['enrolled_on']) ?></td>
        <td><?= _ra_h($_r['enrol_method']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($rptNew) > 50): ?>
    <p class="text-muted" style="font-size:0.85em;">Showing 50 of <?= _ra_n(count($rptNew)) ?>. Download the CSV for the full list.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="mt-3">
  <a href="<?= (new moodle_url('/local/rtocompliance/recovery_analyzer.php'))->out() ?>"
     class="btn btn-outline-secondary">&#8592; Run a new analysis</a>
</div>

</div>
<?php
echo $OUTPUT->footer();
