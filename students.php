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

require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/tablelib.php');

use local_rtocompliance\avetmiss_codes;

$filter      = optional_param('filter', 'all', PARAM_ALPHA);
$state       = optional_param('state', '', PARAM_ALPHANUMEXT);
$search      = optional_param('search', '', PARAM_TEXT);
$page        = max(0, optional_param('page', 0, PARAM_INT)); // Guard: PARAM_INT allows negatives
$perpage     = optional_param('perpage', 50, PARAM_INT);
$perpage     = max(10, min(200, $perpage)); // Guard: prevent crafted URLs loading unlimited rows
$action      = optional_param('action', '', PARAM_ALPHANUMEXT); // FIX-BULK-UNSUSPEND-PARAM: PARAM_ALPHA strips underscores so 'bulk_unsuspend' was silently cleaned to 'bulkunsuspend', never matching, handler never ran.
$actionuserid = optional_param('actionuserid', 0, PARAM_INT);
// SORT: only 'name' is supported for now; default ascending.
$sort        = optional_param('sort', 'name', PARAM_ALPHA);
$sortdir     = optional_param('sortdir', 'asc', PARAM_ALPHA);
if (!in_array($sort, ['name'], true)) { $sort = 'name'; }
if (!in_array($sortdir, ['asc', 'desc'], true)) { $sortdir = 'asc'; }

admin_externalpage_setup('local_rtocompliance_students');

// FIX-SUSPENDED-UNSUSPEND (v5.2.38): Handle unsuspend action before any output.
if ($action === 'unsuspend' && $actionuserid > 0 && confirm_sesskey()) {
    require_capability('moodle/user:update', context_system::instance());
    $DB->set_field('user', 'suspended', 0, ['id' => $actionuserid]);
    \core\session\manager::gc(); // clear any stale session locks for this user
    redirect(new moodle_url('/local/rtocompliance/students.php', [
        'filter' => 'all',
        'sesskey' => sesskey(),
    ]), get_string('user_unsuspended', 'local_rtocompliance'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// BULK-UNSUSPEND (v5.2.78): Root-cause fix — replaced fragile JS-hidden-form
// approach (built userids[] inputs dynamically; could silently POST nothing) with
// a native HTML form wrapping the student table.  The existing students[] checkboxes
// are submitted directly — no JS input-building needed.  Also switched from raw
// set_field() to user_update_user() to clear Moodle's user cache after each update.
if ($action === 'bulk_unsuspend' && confirm_sesskey()) {
    require_capability('moodle/user:update', context_system::instance());
    $userids = optional_param_array('students', [], PARAM_INT);
    $count   = 0;
    foreach ($userids as $uid) {
        if ($uid > 1) { // never touch guest/admin id 1
            $updateuser            = new stdClass();
            $updateuser->id        = $uid;
            $updateuser->suspended = 0;
            user_update_user($updateuser, false, false); // update DB + clear user cache
            $count++;
        }
    }
    redirect(new moodle_url('/local/rtocompliance/students.php', [
        'filter' => 'suspended',
    ]), $count . ' account(s) activated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// USI-STUCK-PENDING-FIX (v5.9.302): admin action to reset all usiverified=3 students
// back to usiverified=0 so the next scheduled batch task re-attempts their verification.
// This is needed when the machine credential was first accepted AFTER the batch had
// already tried (and set everyone to STATUS_PENDING=3 due to CERT_PENDING/503 responses).
if ($action === 'retry_pending_usi' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    require_once(__DIR__ . '/classes/usi/usi_verification_service.php');
    $usiservice = new \local_rtocompliance\usi\usi_verification_service();
    $resetcount = $usiservice->reset_stuck_pending();
    redirect(new moodle_url('/local/rtocompliance/students.php', ['filter' => $filter]),
        $resetcount . ' student(s) reset — verification will be retried on the next scheduled run.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// DOB-FROM-NAT: Bulk backfill DOBs from all uploaded NAT00080 data into student records.
// Two-path matching: (1) local_rtocompliance_students.clientid, (2) mdl_user.idnumber.
// Many students have clientid='' in local_rtocompliance_students (enrolled before doenrol
// ran, profile created by the retroactive-profiles fix which leaves clientid empty).
// When doenrol creates a NEW Moodle user it sets mdl_user.idnumber = clientid, so the
// idnumber path catches those cases.  Only writes dateofbirth where currently 0/NULL.
if ($action === 'sync_dobs_from_nat' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());

    // Step 1: Load all (clientid → DOB) pairs from every uploaded NAT00080.
    $dobRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        $DB->sql_isnotempty('local_rtocompliance_avetmiss_student', 'dob', false, false)
        . ' AND ' . $DB->sql_isnotempty('local_rtocompliance_avetmiss_student', 'clientid', false, false),
        [],
        '',
        'clientid, dob'
    );
    $clientToDob = [];
    foreach ($dobRs as $dr) {
        if (!isset($clientToDob[$dr->clientid]) && strlen($dr->dob) === 8 && ctype_digit($dr->dob)) {
            $clientToDob[$dr->clientid] = $dr->dob;
        }
    }
    $dobRs->close();

    // Step 2: Build clientid → timestamp map (validate + convert DDMMYYYY → Unix).
    $clientToTs = [];
    foreach ($clientToDob as $clientid => $dobStr) {
        $dd = (int)substr($dobStr, 0, 2);
        $mm = (int)substr($dobStr, 2, 2);
        $yy = (int)substr($dobStr, 4, 4);
        if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12 || $yy < 1900 || $yy > 2100) continue;
        $ts = gmmktime(12, 0, 0, $mm, $dd, $yy);
        if ($ts === false || $ts <= 0) continue;
        $clientToTs[$clientid] = (int)$ts;
    }

    $updated = 0;
    if (!empty($clientToTs)) {
        $clientids = array_values(array_keys($clientToTs));
        $chunks    = array_chunk($clientids, 200); // keep IN() params well under DB limits

        foreach ($chunks as $chunk) {
            // Path A: match directly by local_rtocompliance_students.clientid.
            list($inSql, $inParams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cida');
            $rowsA = $DB->get_records_select(
                'local_rtocompliance_students',
                "clientid $inSql AND (dateofbirth IS NULL OR dateofbirth = 0)",
                $inParams,
                '',
                'id, clientid'
            );
            foreach ($rowsA as $row) {
                $ts = $clientToTs[$row->clientid] ?? 0;
                if ($ts <= 0) continue;
                $DB->update_record('local_rtocompliance_students', (object)[
                    'id'           => $row->id,
                    'dateofbirth'  => $ts,
                    'timemodified' => time(),
                ]);
                $updated++;
            }

            // Path B: match via mdl_user.idnumber = clientid.
            // Catches students whose local_rtocompliance_students.clientid is '' because
            // their profile was created by the retroactive-profiles fix, not by doenrol.
            // doenrol sets mdl_user.idnumber = clientid when it creates the Moodle account.
            list($inSql2, $inParams2) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cidb');
            $userRows = $DB->get_records_sql(
                "SELECT u.id AS userid, u.idnumber
                   FROM {user} u
                  WHERE u.idnumber $inSql2
                    AND u.deleted = 0",
                $inParams2
            );
            foreach ($userRows as $ur) {
                $ts = $clientToTs[trim($ur->idnumber)] ?? 0;
                if ($ts <= 0) continue;
                $stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$ur->userid], 'id, clientid, dateofbirth');
                if (!$stud) continue;
                if (!empty($stud->dateofbirth) && (int)$stud->dateofbirth > 0) continue;
                // Also backfill clientid so future syncs can use Path A.
                $upd = (object)['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()];
                if (empty($stud->clientid)) {
                    $upd->clientid = trim($ur->idnumber);
                }
                $DB->update_record('local_rtocompliance_students', $upd);
                $updated++;
            }
        }
    }

    redirect(
        new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']),
        'DOB backfill complete: ' . $updated . ' student record(s) updated from NAT00080 data.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// INLINE-NAT-UPLOAD (v5.9.307): Accept a NAT00080 file uploaded directly from the
// Students page DOB-sync bar. Parses clientid+DOB from the fixed-width record and
// applies them using the same two-path matching as sync_dobs_from_nat — without
// requiring the user to navigate to the full Data Import wizard.
if ($action === 'upload_nat_dobs' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());

    $upload = $_FILES['nat00080file'] ?? null;
    if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
        $errcodes = [UPLOAD_ERR_INI_SIZE=>'file too large (server limit)',UPLOAD_ERR_FORM_SIZE=>'file too large (form limit)',UPLOAD_ERR_PARTIAL=>'partial upload',UPLOAD_ERR_NO_FILE=>'no file selected',UPLOAD_ERR_NO_TMP_DIR=>'no temp directory',UPLOAD_ERR_CANT_WRITE=>'cannot write to disk',UPLOAD_ERR_EXTENSION=>'upload blocked by extension'];
        $errmsg = $errcodes[$upload['error'] ?? UPLOAD_ERR_NO_FILE] ?? ('PHP upload error code ' . ($upload['error'] ?? '?'));
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            'Upload failed: ' . $errmsg, null, \core\output\notification::NOTIFY_ERROR);
    }

    // Safety: cap at 20 MB to prevent memory exhaustion on very large exports.
    if ((int)($upload['size'] ?? 0) > 20 * 1024 * 1024) {
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            'File too large (max 20 MB). Use the Data Import page for files this size.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Parse NAT00080 — fixed-width format only (AVETMISS 8.0 Provider Collection).
    // Tab-delimited variants and multi-format detection are handled by the full
    // Data Import wizard; this inline parser handles the common standard format.
    //
    // Field positions (0-indexed):
    //   0–9   : Client identifier (10 chars)
    //   73–80 : Date of birth (8 chars, DDMMYYYY)
    $lines       = @file($upload['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $parsedRows  = 0;
    $skippedRows = 0;
    $clientToDob = [];
    // LOG-UNREADABLE: collect (clientid → raw DOB string) for rows that fail the
    // parse check so admins can see exactly which identifiers had bad dates.
    $unreadableRows = []; // ['clientid' => 'XXXXXXX', 'raw_dob' => '...', 'reason' => '...']

    if ($lines === false || count($lines) === 0) {
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            'Could not read the uploaded file. Please try again.', null, \core\output\notification::NOTIFY_ERROR);
    }

    foreach ($lines as $line) {
        // Need at least 81 chars to read the DOB field (positions 73-80 inclusive).
        if (strlen($line) < 81) {
            $skippedRows++;
            // Can't reliably extract clientid from a truncated line.
            $unreadableRows[] = ['clientid' => '(line too short: ' . strlen($line) . ' chars)', 'raw_dob' => '', 'reason' => 'line_too_short'];
            continue;
        }
        $clientid = trim(substr($line, 0, 10));
        $dobStr   = trim(substr($line, 73, 8));
        if ($clientid === '' || strlen($dobStr) !== 8 || !ctype_digit($dobStr)) {
            $skippedRows++;
            $reason = $clientid === '' ? 'empty_clientid' : (strlen($dobStr) !== 8 ? 'dob_wrong_length' : 'dob_non_digit');
            $unreadableRows[] = ['clientid' => $clientid ?: '(empty)', 'raw_dob' => $dobStr, 'reason' => $reason];
            continue;
        }
        // First DOB seen for this client ID wins (same policy as sync_dobs_from_nat).
        if (!isset($clientToDob[$clientid])) {
            $clientToDob[$clientid] = $dobStr; // DDMMYYYY
        }
        $parsedRows++;
    }

    if (empty($clientToDob)) {
        $unreadableDetail = '';
        if (!empty($unreadableRows)) {
            $sample = array_slice($unreadableRows, 0, 20);
            $lines2 = array_map(fn($r) => $r['clientid'] . ' raw_dob=' . (strlen($r['raw_dob']) ? $r['raw_dob'] : '(blank)') . ' (' . $r['reason'] . ')', $sample);
            $unreadableDetail = ' First ' . count($sample) . ' unreadable: ' . implode('; ', $lines2) . (count($unreadableRows) > 20 ? ' ...' : '');
        }
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            "No valid DOB records found in the uploaded file ($parsedRows rows parsed, $skippedRows skipped). "
            . "This parser expects a standard fixed-width NAT00080 file (AVETMISS 8.0). "
            . "For tab-delimited or variant formats, use the Data Import page.$unreadableDetail",
            null, \core\output\notification::NOTIFY_WARNING);
    }

    // Convert DDMMYYYY → Unix timestamps (same validation as sync_dobs_from_nat).
    $clientToTs = [];
    foreach ($clientToDob as $clientid => $dobStr) {
        $dd = (int)substr($dobStr, 0, 2);
        $mm = (int)substr($dobStr, 2, 2);
        $yy = (int)substr($dobStr, 4, 4);
        if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12 || $yy < 1900 || $yy > 2100) {
            // Calendar values are out of range — classify as unreadable.
            $unreadableRows[] = ['clientid' => $clientid, 'raw_dob' => $dobStr, 'reason' => 'calendar_out_of_range'];
            continue;
        }
        $ts = gmmktime(12, 0, 0, $mm, $dd, $yy);
        if ($ts === false || $ts <= 0) {
            $unreadableRows[] = ['clientid' => $clientid, 'raw_dob' => $dobStr, 'reason' => 'gmmktime_failed'];
            continue;
        }
        $clientToTs[$clientid] = (int)$ts;
    }

    // LOG-UNMATCHED: track which clientids were successfully parsed but not matched
    // to any student in either Path A or Path B.
    $matchedClientids = []; // clientids that were actually updated

    $updated = 0;
    if (!empty($clientToTs)) {
        $chunks = array_chunk(array_values(array_keys($clientToTs)), 200);
        foreach ($chunks as $chunk) {
            // Path A: match directly by local_rtocompliance_students.clientid.
            list($inSql, $inParams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cida');
            $rowsA = $DB->get_records_select(
                'local_rtocompliance_students',
                "clientid $inSql AND (dateofbirth IS NULL OR dateofbirth = 0)",
                $inParams, '', 'id, clientid'
            );
            foreach ($rowsA as $row) {
                $ts = $clientToTs[$row->clientid] ?? 0;
                if ($ts <= 0) continue;
                $DB->update_record('local_rtocompliance_students', (object)[
                    'id' => $row->id, 'dateofbirth' => $ts, 'timemodified' => time(),
                ]);
                $matchedClientids[$row->clientid] = true;
                $updated++;
            }
            // Path B: match via mdl_user.idnumber = clientid (catches students whose
            // local_rtocompliance_students.clientid is '' — created by retroactive-profiles
            // fix; doenrol sets mdl_user.idnumber when it creates the Moodle account).
            list($inSql2, $inParams2) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'cidb');
            $userRows = $DB->get_records_sql(
                "SELECT u.id AS userid, u.idnumber FROM {user} u WHERE u.idnumber $inSql2 AND u.deleted = 0",
                $inParams2
            );
            foreach ($userRows as $ur) {
                $ts = $clientToTs[trim($ur->idnumber)] ?? 0;
                if ($ts <= 0) continue;
                $stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$ur->userid], 'id, clientid, dateofbirth');
                if (!$stud || (!empty($stud->dateofbirth) && (int)$stud->dateofbirth > 0)) continue;
                $upd = (object)['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()];
                if (empty($stud->clientid)) {
                    $upd->clientid = trim($ur->idnumber); // backfill clientid for future Path A
                }
                $DB->update_record('local_rtocompliance_students', $upd);
                $matchedClientids[trim($ur->idnumber)] = true;
                $updated++;
            }
        }
    }

    // Build the unmatched list: parsed-and-valid clientids that never hit Path A or B.
    $unmatchedClientids = array_values(array_diff(array_keys($clientToTs), array_keys($matchedClientids)));

    // Log full detail to Moodle debugging so it's queryable without cluttering the UI.
    $fname = s(basename($upload['name']));
    $unreadableCount = count($unreadableRows);
    $unmatchedCount  = count($unmatchedClientids);

    if ($unreadableCount > 0) {
        $detail = array_map(
            fn($r) => $r['clientid'] . ' raw_dob=' . (strlen($r['raw_dob']) ? $r['raw_dob'] : '(blank)') . ' (' . $r['reason'] . ')',
            $unreadableRows
        );
        debugging('[DOB upload] ' . $unreadableCount . ' unreadable row(s) in ' . $fname . ': ' . implode('; ', $detail), DEBUG_DEVELOPER);
    }
    if ($unmatchedCount > 0) {
        debugging('[DOB upload] ' . $unmatchedCount . ' unmatched clientid(s) in ' . $fname . ' (no student in Path A or B): ' . implode(', ', $unmatchedClientids), DEBUG_DEVELOPER);
    }

    // Build the admin-visible notification message.
    $msg = "DOB sync complete: $updated student record(s) updated from $parsedRows NAT00080 rows in '$fname'.";
    if ($unreadableCount > 0) {
        $sampleUnread = array_slice($unreadableRows, 0, 10);
        $unreadList   = implode(', ', array_map(fn($r) => $r['clientid'] . ' (' . $r['raw_dob'] . ')', $sampleUnread));
        $msg .= " $unreadableCount unreadable date(s) — clientid (raw DOB): $unreadList" . ($unreadableCount > 10 ? ' …' : '') . '.';
    }
    if ($unmatchedCount > 0) {
        $sampleUnmatch = array_slice($unmatchedClientids, 0, 10);
        $msg .= " $unmatchedCount not matched to any student — clientid(s): " . implode(', ', $sampleUnmatch) . ($unmatchedCount > 10 ? ' …' : '') . '.';
    }

    redirect(
        new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']),
        $msg,
        null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// ── CSV cell sanitizer ────────────────────────────────────────────────────────
// Prevents spreadsheet-formula injection (CSV injection / formula injection).
// Excel, LibreOffice, and Google Sheets evaluate cells that start with =, +, -,
// @, tab, or carriage-return as formulas.  Attackers who control profile fields
// (name, suburb, etc.) can inject data-exfiltration formulas into exported CSVs.
// Mitigation: prepend a tab character to any cell whose first non-whitespace
// character is a formula trigger.  The tab is invisible in most spreadsheet
// applications and forces the cell to be read as plain text.
// Applied to ALL user-controlled fields in every CSV export from this page.
function rtoc_csv_safe($value) {
    $value = (string) $value;
    // Strip only leading tabs/CR/LF before checking the trigger character,
    // so =formula and \t=formula are both caught.
    $first = ltrim($value, "\t\r\n");
    if ($first !== '' && in_array($first[0], ['=', '+', '-', '@'], true)) {
        return "\t" . $value;
    }
    // Also guard leading tab/CR/LF themselves (can be used to bypass naive checks).
    if ($value !== '' && in_array($value[0], ["\t", "\r", "\n"], true)) {
        return "\t" . $value;
    }
    return $value;
}

// ── EXPORT CSV: current filtered view ────────────────────────────────────────
// ACTION: export_csv — streams the currently filtered/searched student list as a
// downloadable CSV.  Mirrors the same WHERE logic as the main table query but runs
// without pagination so all matching rows are included.  Must execute before any
// output so it can send its own Content-Type/Content-Disposition headers.
if ($action === 'export_csv' && confirm_sesskey()) {
    require_capability('local/rtocompliance:manage', context_system::instance());

    $siteadminlist_exp = '0';
    if (!empty($CFG->siteadmins)) {
        $ids = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
        if (!empty($ids)) { $siteadminlist_exp = implode(',', $ids); }
    }
    $suspendedfilter_exp = ($filter === 'suspended') ? 1 : 0;

    $expsql = "SELECT u.id, u.firstname, u.lastname, u.email, u.suspended,
                      s.usi, s.usiverified, s.usiverifieddate, s.statecode,
                      s.postcode, s.suburb, s.dateofbirth, s.profilecomplete
                 FROM {user} u
                 LEFT JOIN {local_rtocompliance_students} s ON s.userid = u.id
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
                WHERE u.deleted = 0 AND u.suspended = :suspendedfilter
                  AND u.id NOT IN ($siteadminlist_exp)
                  AND staff.userid IS NULL
                  AND rtoc_trainer.userid IS NULL";
    $expparams = ['suspendedfilter' => $suspendedfilter_exp];

    if (!empty($search)) {
        $fullNameFwd = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $fullNameRev = $DB->sql_concat('u.lastname',  "' '", 'u.firstname');
        $srchsql  = $DB->sql_like('u.firstname', ':search1', false, false);
        $srchsql .= ' OR ' . $DB->sql_like('u.lastname',  ':search2', false, false);
        $srchsql .= ' OR ' . $DB->sql_like('u.email',     ':search3', false, false);
        $srchsql .= ' OR ' . $DB->sql_like('s.usi',       ':search4', false, false);
        $srchsql .= ' OR ' . $DB->sql_like($fullNameFwd,  ':search5', false, false);
        $srchsql .= ' OR ' . $DB->sql_like($fullNameRev,  ':search6', false, false);
        $expsql .= " AND ($srchsql)";
        $expparams['search1'] = '%' . $search . '%';
        $expparams['search2'] = '%' . $search . '%';
        $expparams['search3'] = '%' . $search . '%';
        $expparams['search4'] = '%' . $search . '%';
        $expparams['search5'] = '%' . $search . '%';
        $expparams['search6'] = '%' . $search . '%';
    }

    if ($filter === 'incomplete')    { $expsql .= " AND (s.profilecomplete = 0 OR s.profilecomplete IS NULL)"; }
    elseif ($filter === 'nousi')     { $expsql .= " AND (s.usi IS NULL OR s.usi = '')"; }
    elseif ($filter === 'usiverified')   { $expsql .= " AND s.usiverified = 1"; }
    elseif ($filter === 'usiunverified') { $expsql .= " AND s.usi IS NOT NULL AND s.usi != '' AND s.usiverified IN (0, 3)"; }
    elseif ($filter === 'usifailed')     { $expsql .= " AND s.usiverified = 2"; }
    elseif ($filter === 'usimissingdob') { $expsql .= " AND s.usi IS NOT NULL AND s.usi != '' AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)"; }

    if (!empty($state)) {
        $expsql .= " AND s.statecode = :state";
        $expparams['state'] = $state;
    }
    $expsql .= " ORDER BY u.lastname ASC, u.firstname ASC";

    $expstudents = $DB->get_records_sql($expsql, $expparams);

    $vstatLabels = [0 => 'Not verified', 1 => 'Verified', 2 => 'Failed', 3 => 'Pending', 4 => 'Manual review'];
    $filterLabel = $filter ?: 'all';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . $filterLabel . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['First name', 'Last name', 'Email', 'USI', 'USI Status', 'USI Verified Date',
                   'State', 'Postcode', 'Suburb', 'Date of Birth', 'Profile Complete', 'Account Status']);
    foreach ($expstudents as $row) {
        $dob = '';
        if (!empty($row->dateofbirth) && (int)$row->dateofbirth > 0) {
            $dob = date('d/m/Y', (int)$row->dateofbirth);
        }
        $vdate = '';
        if (!empty($row->usiverifieddate)) {
            $vdate = date('d/m/Y', (int)$row->usiverifieddate);
        }
        // rtoc_csv_safe() applied to every user-controlled field to prevent
        // spreadsheet-formula injection (CSV injection) when opened in Excel/Sheets.
        fputcsv($out, [
            rtoc_csv_safe($row->firstname),
            rtoc_csv_safe($row->lastname),
            rtoc_csv_safe($row->email),
            rtoc_csv_safe($row->usi ?? ''),
            rtoc_csv_safe($vstatLabels[(int)($row->usiverified ?? 0)] ?? 'Unknown'),
            rtoc_csv_safe($vdate),
            rtoc_csv_safe($row->statecode ?? ''),
            rtoc_csv_safe($row->postcode  ?? ''),
            rtoc_csv_safe($row->suburb    ?? ''),
            rtoc_csv_safe($dob),
            empty($row->profilecomplete) ? 'No' : 'Yes',
            empty($row->suspended)       ? 'Active' : 'Suspended',
        ]);
    }
    fclose($out);
    exit;
}

// ── EXPORT CSV: students with USI but missing DOB ─────────────────────────────
// ACTION: export_missing_dob_csv — exports all students who have a USI recorded
// but are missing a date of birth (so USI verification cannot proceed for them).
// Must run before any output so it can send Content-Type/Content-Disposition headers.
if ($action === 'export_missing_dob_csv' && confirm_sesskey()) {
    require_capability('local/rtocompliance:manage', context_system::instance());

    $siteadminlist_exp = '0';
    if (!empty($CFG->siteadmins)) {
        $ids = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
        if (!empty($ids)) { $siteadminlist_exp = implode(',', $ids); }
    }

    // Apply same staff/trainer exclusions as the main student table so this export
    // never includes teacher, manager, or local_rtocompliance_trainers accounts.
    $expstudents = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email,
                s.usi, s.statecode, s.postcode, s.suburb
           FROM {user} u
           JOIN {local_rtocompliance_students} s ON s.userid = u.id
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
          WHERE u.deleted = 0 AND u.suspended = 0
            AND u.id NOT IN ($siteadminlist_exp)
            AND staff.userid IS NULL
            AND rtoc_trainer.userid IS NULL
            AND s.usi IS NOT NULL AND s.usi != ''
            AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)
          ORDER BY u.lastname ASC, u.firstname ASC"
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_missing_dob_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['First name', 'Last name', 'Email', 'USI', 'State', 'Postcode', 'Suburb']);
    foreach ($expstudents as $row) {
        // rtoc_csv_safe() applied to every user-controlled field to prevent
        // spreadsheet-formula injection (CSV injection) when opened in Excel/Sheets.
        fputcsv($out, [
            rtoc_csv_safe($row->firstname),
            rtoc_csv_safe($row->lastname),
            rtoc_csv_safe($row->email),
            rtoc_csv_safe($row->usi       ?? ''),
            rtoc_csv_safe($row->statecode ?? ''),
            rtoc_csv_safe($row->postcode  ?? ''),
            rtoc_csv_safe($row->suburb    ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// ── CONNECTION TEST: server-side proxy for /api/usi/status ───────────────────
// Called via AJAX from the "Run connection test" button.  The platform credentials
// (API URL, Site ID, API Key) stay entirely server-side — they are NEVER sent to
// the browser.  The handler makes the cURL call to /api/usi/status using the
// already-configured Moodle plugin settings and returns only safe status fields.
// Requires moodle/site:config capability + valid sesskey to prevent unauthenticated
// actors from triggering ATO API calls via the plugin machine credential.
if ($action === 'connection_test' && confirm_sesskey()) {
    require_capability('local/rtocompliance:manage', context_system::instance());

    // Resolve credentials using the same Central Config-aware precedence as
    // usi_platform_client: local_aiconfig takes priority when installed.
    $ct_apiurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');
    $ct_aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
    if (file_exists($ct_aiconfiglib)) {
        require_once($ct_aiconfiglib);
    }
    // Exact mirror of usi_platform_client credential resolution:
    // Central Config is the ONLY source when installed — no plugin-local fallback.
    $ct_siteid = function_exists('local_aiconfig_get_siteid')
        ? (local_aiconfig_get_siteid('local_rtocompliance') ?: '')
        : trim((string) get_config('local_rtocompliance', 'siteid'));
    $ct_apikey = function_exists('local_aiconfig_get_apikey')
        ? (local_aiconfig_get_apikey('local_rtocompliance') ?: '')
        : trim((string) get_config('local_rtocompliance', 'apikey'));

    if (!$ct_siteid || !$ct_apikey) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'      => false,
            'message' => 'USI API not configured — set API URL, Site ID, and API Key in USI Verification Settings.',
        ]);
        exit;
    }

    $curl = curl_init(rtrim($ct_apiurl, '/') . '/api/usi/status');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'X-Site-Id: ' . $ct_siteid,
            'X-Api-Key: ' . $ct_apikey,
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $ct_resp    = curl_exec($curl);
    $ct_code    = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $ct_curlerr = curl_error($curl);
    curl_close($curl);

    header('Content-Type: application/json');

    if ($ct_resp === false || $ct_code === 0) {
        echo json_encode([
            'ok'      => false,
            'message' => 'Could not reach the AI Grader platform — check your API URL setting and network connectivity.'
                         . ($ct_curlerr ? ' (' . s($ct_curlerr) . ')' : ''),
        ]);
        exit;
    }

    $ct_data = json_decode($ct_resp, true);
    if (!is_array($ct_data)) {
        echo json_encode([
            'ok'      => false,
            'message' => 'Unexpected response from the platform (HTTP ' . $ct_code . '). Contact support.',
        ]);
        exit;
    }

    // Return only safe, read-only status fields.  The API credentials are never echoed.
    echo json_encode([
        'ok'               => !empty($ct_data['ok']) && $ct_code === 200,
        'certReady'        => !empty($ct_data['certReady']),
        'certUploaded'     => !empty($ct_data['certUploaded']),
        'certDecryptError' => $ct_data['certDecryptError'] ?? null,
        'testMode'         => $ct_data['testMode'] ?? null,
        'source'           => $ct_data['source'] ?? null,
        'orgId'            => $ct_data['orgId'] ?? null,
        'certExpiry'       => $ct_data['certExpiry'] ?? null,
        'certSubject'      => $ct_data['certSubject'] ?? null,
        'daysToExpiry'     => $ct_data['daysToExpiry'] ?? null,
        'expiryWarn'       => $ct_data['expiryWarn'] ?? false,
        'expired'          => $ct_data['expired'] ?? false,
        'message'          => $ct_data['message'] ?? '',
        'httpStatus'       => $ct_code,
    ]);
    exit;
}

// ── REVERIFY ALL: re-queue failed + stuck USI students ────────────────────────
// ACTION: reverify_all — resets all students whose USI verification failed (status=2)
// or is stuck in pending (status=3) back to unverified (status=0) so the next
// scheduled batch task re-attempts them.  Students already verified (status=1) are
// deliberately excluded — this action never undoes a successful verification.
if ($action === 'reverify_all' && confirm_sesskey()) {
    require_capability('local/rtocompliance:manage', context_system::instance());

    // Count before resetting so the confirmation message is accurate.
    $resetcount = $DB->count_records_select(
        'local_rtocompliance_students',
        "usiverified IN (2, 3)
           AND usi IS NOT NULL AND usi != ''
           AND dateofbirth IS NOT NULL AND dateofbirth != 0"
    );

    if ($resetcount > 0) {
        $DB->execute(
            "UPDATE {local_rtocompliance_students}
                SET usiverified = 0, timemodified = :now
              WHERE usiverified IN (2, 3)
                AND usi IS NOT NULL AND usi != ''
                AND dateofbirth IS NOT NULL AND dateofbirth != 0",
            ['now' => time()]
        );
    }

    redirect(
        new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usiunverified']),
        $resetcount > 0
            ? $resetcount . ' student(s) queued for re-verification — they will be re-attempted on the next scheduled verification run.'
            : 'No students with failed or pending USI status found — nothing to reset.',
        null,
        $resetcount > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/students.php', [
    'filter'  => $filter,
    'state'   => $state,
    'search'  => $search,
    'sort'    => $sort,
    'sortdir' => $sortdir,
]));
$PAGE->set_title(get_string('students', 'local_rtocompliance'));
$PAGE->set_heading(get_string('students', 'local_rtocompliance'));

$PAGE->navbar->add(get_string('pluginname', 'local_rtocompliance'), new moodle_url('/local/rtocompliance/index.php'));
$PAGE->navbar->add(get_string('students', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
// ── USI Verification setup check ─────────────────────────────────────────────
// Resolve credentials using the same Central Config-aware precedence as
// usi_platform_client: local_aiconfig takes priority over local_rtocompliance
// settings when installed, so this check reflects the same effective configuration
// that actual USI verification calls will use.
$_usi_apiurl = rtrim(get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com', '/');

$_usi_aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($_usi_aiconfiglib)) {
    require_once($_usi_aiconfiglib);
}
// Exact mirror of usi_platform_client credential resolution:
// When local_aiconfig functions exist, Central Config is the ONLY source — an empty
// Central Config value stays empty (no fallback to plugin-local settings).  This
// prevents the toolbar from showing as "configured" on installations where aiconfig is
// installed but credentials are not yet entered there.
$_usi_siteid = function_exists('local_aiconfig_get_siteid')
    ? (local_aiconfig_get_siteid('local_rtocompliance') ?: '')
    : trim((string) get_config('local_rtocompliance', 'siteid'));
$_usi_apikey = function_exists('local_aiconfig_get_apikey')
    ? (local_aiconfig_get_apikey('local_rtocompliance') ?: '')
    : trim((string) get_config('local_rtocompliance', 'apikey'));

$_usi_api_configured = ($_usi_apiurl !== '' && $_usi_siteid !== '' && $_usi_apikey !== '');

// Check credential status via a lightweight /api/usi/status ping.
// certReady  = cert is present, decryptable, and non-expired (good to verify).
// certUploaded = cert file is present regardless of password/expiry validity.
// certExpiry = ISO 8601 expiry date string (may be null for non-expiring certs).
$_usi_cert_ready    = false; // cert is fully operational
$_usi_cert_uploaded = false; // cert file exists but may have wrong password / be expired
$_usi_cert_expiry   = null;  // ISO date string or null
$_usi_days_to_expiry = null; // integer days remaining, or null if unknown
$_usi_expired        = false; // true when the cert is past its expiry date
if ($_usi_api_configured) {
    // Quick status ping — short timeout so it doesn't slow the page.
    $curl = curl_init(rtrim($_usi_apiurl, '/') . '/api/usi/status');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Site-Id: ' . $_usi_siteid,
            'X-Api-Key: ' . $_usi_apikey,
        ],
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($code === 200 && $resp) {
        $decoded = json_decode($resp, true);
        if (is_array($decoded)) {
            $_usi_cert_ready    = !empty($decoded['certReady']);
            $_usi_cert_uploaded = !empty($decoded['certUploaded']);
            $_usi_cert_expiry   = isset($decoded['certExpiry']) && $decoded['certExpiry'] ? $decoded['certExpiry'] : null;
            $_usi_days_to_expiry = isset($decoded['daysToExpiry']) ? (int)$decoded['daysToExpiry'] : null;
            $_usi_expired        = !empty($decoded['expired']);
        }
    }
}
// Legacy alias used by any remaining references below.
$_usi_cert_ok = $_usi_cert_ready || $_usi_cert_uploaded;

$_usi_settings_url = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false);

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('student_records', 'local_rtocompliance'), null, null, 'students');

// ── USI setup popup / banner ──────────────────────────────────────────────────
if (!$_usi_api_configured) {
    // Hard gate: API not connected at all — show auto-opening modal.
    echo '
<div id="rtoc-usi-modal-overlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;max-width:520px;width:90%;padding:32px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3);position:relative;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
      <div style="flex-shrink:0;width:44px;height:44px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
      </div>
      <div>
        <h3 style="margin:0;font-size:17px;font-weight:700;color:#1e293b;">USI Verification Not Configured</h3>
        <p style="margin:4px 0 0;font-size:13px;color:#64748b;">USI verification requires a one-time setup before it will work.</p>
      </div>
    </div>
    <p style="margin:0 0 12px;font-size:14px;color:#374151;line-height:1.6;">
      Your site has <strong>not yet been connected</strong> to the AI Grader platform, so USI verification calls will fail for every student.
    </p>
    <p style="margin:0 0 20px;font-size:14px;color:#374151;line-height:1.6;">
      To fix this, go to <strong>USI Verification Settings</strong> and:
    </p>
    <ol style="margin:0 0 20px;padding-left:20px;font-size:14px;color:#374151;line-height:1.8;">
      <li>Connect your site (API URL, Site ID and API Key)</li>
      <li>Upload your myID Machine Credential (.xml or .pfx)</li>
      <li>Enter your RTO TOID and certificate password</li>
    </ol>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <a href="' . s($_usi_settings_url) . '" class="btn btn-primary" style="font-size:14px;">
        <svg style="width:14px;height:14px;vertical-align:middle;margin-right:6px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        Set Up USI Verification
      </a>
      <button type="button" onclick="rtocDismissUsiModal()" style="background:none;border:none;color:#6b7280;font-size:13px;cursor:pointer;padding:6px 10px;">Dismiss</button>
    </div>
  </div>
</div>
<script>
(function () {
    var dismissed = sessionStorage.getItem("rtoc_usi_modal_dismissed");
    if (!dismissed) {
        var overlay = document.getElementById("rtoc-usi-modal-overlay");
        if (overlay) { overlay.style.display = "flex"; }
    }
})();
function rtocDismissUsiModal() {
    sessionStorage.setItem("rtoc_usi_modal_dismissed", "1");
    var overlay = document.getElementById("rtoc-usi-modal-overlay");
    if (overlay) { overlay.style.display = "none"; }
}
document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { rtocDismissUsiModal(); }
});
</script>';
} else if ($_usi_cert_ready || ($_usi_cert_uploaded && $_usi_expired)) {
    // Cert is uploaded — show green badge, or amber/red warning when expiry is near/past.
    $expiryTs = $_usi_cert_expiry ? strtotime($_usi_cert_expiry) : 0;

    if ($_usi_expired) {
        // Red: cert has already expired — USI verifications will fail.
        $daysAgo = $_usi_days_to_expiry !== null ? abs((int)$_usi_days_to_expiry) : '?';
        echo '
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#991b1b;">USI Credential Expired</strong>
    <span style="font-size:13px;color:#7f1d1d;margin-left:8px;">The machine credential expired ' . s((string)$daysAgo) . ' day(s) ago — USI verifications are failing. Upload a renewed credential immediately.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-danger btn-sm" style="white-space:nowrap;flex-shrink:0;">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:5px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    Renew Credential
  </a>
</div>';
    } else if ($_usi_days_to_expiry !== null && $_usi_days_to_expiry <= 30) {
        // Amber: expiry within 30 days — proactive warning.
        $daysLeft = (int)$_usi_days_to_expiry;
        $expiryLabel = ($expiryTs && $expiryTs > 0) ? ' (expires ' . date('d M Y', $expiryTs) . ')' : '';
        echo '
<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#92400e;">USI Credential Expiring Soon</strong>
    <span style="font-size:13px;color:#78350f;margin-left:8px;">Credential expires in <strong>' . s((string)$daysLeft) . ' day(s)</strong>' . s($expiryLabel) . ' — renew before it expires to avoid verification failures.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-warning btn-sm" style="white-space:nowrap;flex-shrink:0;">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:5px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    Renew Now
  </a>
</div>';
    } else {
        // Green: cert is healthy — show compact confirmation badge.
        $expiryHtml = '';
        if ($expiryTs && $expiryTs > 0) {
            $expiryHtml = ' <span style="font-size:12px;color:#166534;margin-left:6px;">Expires ' . date('d M Y', $expiryTs) . '</span>';
        }
        echo '
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:18px;height:18px" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  <span style="font-size:13px;font-weight:600;color:#15803d;">USI Credential Active</span>' . $expiryHtml . '
  <a href="' . s($_usi_settings_url) . '" style="font-size:12px;color:#16a34a;margin-left:auto;text-decoration:underline;white-space:nowrap;">View settings</a>
</div>';
    }
} else if ($_usi_cert_uploaded) {
    // Cert file is present but certReady is false (e.g. wrong password or decryption error).
    echo '
<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#92400e;">USI Machine Credential uploaded but not ready</strong>
    <span style="font-size:13px;color:#78350f;margin-left:8px;">The certificate was uploaded but could not be decrypted — check the certificate password in USI Settings.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-warning btn-sm" style="white-space:nowrap;flex-shrink:0;">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:5px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    Fix Certificate Password
  </a>
</div>';
} else {
    // API connected but no cert uploaded at all — show a softer inline banner.
    echo '
<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#92400e;">USI Machine Credential not uploaded</strong>
    <span style="font-size:13px;color:#78350f;margin-left:8px;">USI verification will fail until you upload your myID certificate.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-warning btn-sm" style="white-space:nowrap;flex-shrink:0;">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:5px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    Set Up USI Verification
  </a>
</div>';
}

echo html_writer::start_div('students-container');

echo html_writer::start_div('students-header');
echo html_writer::tag('h2', get_string('student_records', 'local_rtocompliance'));
echo html_writer::end_div();

// ── Site admin exclusion list (used by stats AND the main query below) ────────
// In Moodle, site admins are stored in $CFG->siteadmins (comma-separated IDs),
// NOT in role_assignments. Must be built before the stats queries run.
$siteadminlist = '0';
if (!empty($CFG->siteadmins)) {
    $ids = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
    if (!empty($ids)) {
        $siteadminlist = implode(',', $ids);
    }
}

// ── Quick Statistics cards ────────────────────────────────────────────────────
// PROBLEM 2 FIX: exclude teacher/manager/admin roles from student count
// so teachers never inflate the headline "Total Students" figure.
$stats = [];
// LEFT JOIN derived-table instead of NOT IN (SELECT ...) — avoids a correlated
// subquery that MySQL re-executes for every outer row; equivalent result, much
// faster on installs with thousands of users.
$stats['total'] = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT u.id)
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
      WHERE u.deleted = 0 AND u.suspended = 0
        AND u.id NOT IN ($siteadminlist)
        AND staff.userid IS NULL
        AND rtoc_trainer.userid IS NULL"
);
// Apply same trainer exclusion so "with profile" never exceeds "total students" headline.
$stats['withprofile']     = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT s.userid)
       FROM {local_rtocompliance_students} s
       LEFT JOIN (
           SELECT DISTINCT userid FROM {local_rtocompliance_trainers}
       ) rtoc_trainer ON rtoc_trainer.userid = s.userid
      WHERE rtoc_trainer.userid IS NULL"
);
$stats['complete']        = $DB->count_records('local_rtocompliance_students', ['profilecomplete' => 1]);
$stats['withusi']         = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_students} WHERE usi IS NOT NULL AND usi != ''");
$stats['missing_usi']     = $stats['withprofile'] - $stats['withusi'];
// DOB-MISSING-USI-FIX (v5.2.88): count of students who have a USI but are missing DOB
// (verification cannot proceed without DOB).
$stats['usi_missing_dob'] = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_students}
      WHERE usi IS NOT NULL AND usi != ''
        AND (dateofbirth IS NULL OR dateofbirth = 0)"
);
// USI-STUCK-PENDING-FIX (v5.9.302): count students whose verification is stuck
// at STATUS_PENDING (3) — transient error (CERT_PENDING/NETWORK_ERROR) from an
// earlier attempt that will be re-tried on the next batch run now that the query
// includes usiverified IN (0, 3).  Shown to the admin as an actionable banner.
$stats['usi_pending_retry'] = $DB->count_records_select(
    'local_rtocompliance_students',
    "usiverified = 3 AND usi IS NOT NULL AND usi != '' AND dateofbirth IS NOT NULL AND dateofbirth != 0"
);
$stats['enrolments']      = $DB->count_records('local_rtocompliance_enrolments');
$stats['certs_issued']    = $DB->count_records('local_rtocompliance_certs', ['status' => 'issued']);
$stats['competent_units'] = $DB->count_records_sql("SELECT COUNT(*) FROM {local_rtocompliance_enrolments} WHERE outcomeidentifier = '20'");

$iconUsers   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$iconDoc     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
$iconCheck   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
$iconKey     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>';
$iconAlert   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
$iconBook    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>';
$iconAward   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>';
$iconBar     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>';

echo html_writer::start_div('stats-cards');
$summaryStats = [
    ['label' => get_string('total_students', 'local_rtocompliance'),                      'value' => $stats['total'],           'color' => 'blue',   'icon' => $iconUsers],
    ['label' => 'Students with AVETMISS Profile',                                        'value' => $stats['withprofile'],     'color' => 'purple', 'icon' => $iconDoc],
    ['label' => get_string('complete', 'local_rtocompliance') . ' Profiles',              'value' => $stats['complete'],        'color' => 'green',  'icon' => $iconCheck],
    ['label' => get_string('usi', 'local_rtocompliance') . ' Recorded',                   'value' => $stats['withusi'],         'color' => 'amber',  'icon' => $iconKey],
    ['label' => 'USI Missing',                                                             'value' => $stats['missing_usi'],     'color' => $stats['missing_usi'] > 0 ? 'rose' : 'green', 'icon' => $iconAlert],
    ['label' => 'USI Has No DOB (can\'t verify)',                                          'value' => $stats['usi_missing_dob'], 'color' => $stats['usi_missing_dob'] > 0 ? 'rose' : 'green', 'icon' => $iconAlert],
    ['label' => 'Total Enrolments',                                                        'value' => $stats['enrolments'],      'color' => 'blue',   'icon' => $iconBook],
    ['label' => 'Certificates Issued',                                                     'value' => $stats['certs_issued'],    'color' => 'green',  'icon' => $iconAward],
    ['label' => 'Competency Achieved (Units)',                                             'value' => $stats['competent_units'], 'color' => 'amber',  'icon' => $iconBar],
];
foreach ($summaryStats as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'],  ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

$filterform = '
<div class="rtoc-filter-bar">
<form method="get" id="filters" action="">
    <div class="rtoc-filter-fields">
        <div class="rtoc-filter-group">
            <label for="filter">' . get_string('filterbystatus', 'local_rtocompliance') . '</label>
            <select name="filter" id="filter" class="form-control">
                <option value="all"' . ($filter === 'all' ? ' selected' : '') . '>' . get_string('allstudents', 'local_rtocompliance') . '</option>
                <option value="incomplete"' . ($filter === 'incomplete' ? ' selected' : '') . '>' . get_string('incompleteonly', 'local_rtocompliance') . '</option>
                <option value="nousi"' . ($filter === 'nousi' ? ' selected' : '') . '>' . get_string('missingusionly', 'local_rtocompliance') . '</option>
                <option value="usiverified"' . ($filter === 'usiverified' ? ' selected' : '') . '>USI Verified (usi.gov.au)</option>
                <option value="usiunverified"' . ($filter === 'usiunverified' ? ' selected' : '') . '>USI Not Yet Verified</option>
                <option value="usifailed"' . ($filter === 'usifailed' ? ' selected' : '') . '>USI Verification Failed</option>
                <option value="usimissingdob"' . ($filter === 'usimissingdob' ? ' selected' : '') . '>USI Present, DOB Missing</option>
                <option value="suspended"' . ($filter === 'suspended' ? ' selected' : '') . '>Suspended Accounts</option>
            </select>
        </div>
        <div class="rtoc-filter-group">
            <label for="state">' . get_string('filterbystate', 'local_rtocompliance') . '</label>
            <select name="state" id="state" class="form-control">
                <option value="">' . get_string('all') . '</option>';

$states = avetmiss_codes::get_state_codes();
foreach ($states as $code => $name) {
    $selected = ($state === $code) ? ' selected' : '';
    $filterform .= '<option value="' . $code . '"' . $selected . '>' . $name . '</option>';
}

$filterform .= '
            </select>
        </div>
        <div class="rtoc-filter-group rtoc-filter-search">
            <label for="search">' . get_string('searchstudent', 'local_rtocompliance') . '</label>
            <input type="text" name="search" id="search" class="form-control"
                   placeholder="' . get_string('searchstudent', 'local_rtocompliance') . '"
                   value="' . s($search) . '">
        </div>
        <div class="rtoc-filter-group rtoc-filter-action">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary">' . get_string('search') . '</button>
        </div>
    </div>
</form>
</div>';

echo $filterform;

// ── USI Action Toolbar ────────────────────────────────────────────────────────
// Four action buttons recovered from the live IFCBAA site (code drift):
//   1. "Run connection test"     — AJAX ping of /api/usi/status; shows real API response
//   2. "Re-verify all students"  — resets failed/stuck USI back to unverified for next batch
//   3. "Export this view (CSV)"  — exports the current filtered list as a CSV download
//   4. "Export students without DOB" — exports students who have USI but no DOB recorded
//
// "Run connection test" intentionally reads the live /api/usi/status response rather than
// returning a hardcoded pass/fail string.  The result is displayed inline without a page reload.
if ($_usi_api_configured) {
    $csvurl = (new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'export_csv',
        'sesskey' => sesskey(),
        'filter'  => $filter,
        'state'   => $state,
        'search'  => $search,
    ]))->out(false);

    $missingdoburl = (new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'export_missing_dob_csv',
        'sesskey' => sesskey(),
    ]))->out(false);

    $reverifyurl = (new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'reverify_all',
        'sesskey' => sesskey(),
    ]))->out(false);

    // Build the same-origin connection test URL — credentials stay server-side.
    // The AJAX call goes to students.php?action=connection_test which proxies
    // /api/usi/status server-side and returns only safe status fields.
    $jsConnTestUrl = json_encode((new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'connection_test',
        'sesskey' => sesskey(),
    ]))->out(false));

    // Students page URL without action — used by the re-verify POST form.
    $jsStudentsUrl = json_encode((new moodle_url('/local/rtocompliance/students.php'))->out(false));

    echo '
<div class="rtoc-usi-action-bar" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;
     background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-bottom:12px;">
  <span style="font-size:13px;font-weight:600;color:#475569;white-space:nowrap;margin-right:4px;">USI Actions:</span>

  <!-- 1. Run connection test (AJAX — server-side proxy; no credentials in browser) -->
  <button id="rtoc-conn-test-btn" type="button" class="btn btn-sm btn-outline-primary"
          style="white-space:nowrap;"
          title="Ping the AI Grader platform and show the live USI credential status">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px;margin-top:-2px"
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
      <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
      <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
      <line x1="12" y1="20" x2="12.01" y2="20"/>
    </svg>
    Run connection test
  </button>

  <!-- 2. Re-verify all students (POST form — state-changing; not a plain GET link) -->
  <form id="rtoc-reverify-form" method="post"
        action="' . htmlspecialchars((new moodle_url('/local/rtocompliance/students.php'))->out(false), ENT_QUOTES) . '"
        style="display:inline;margin:0">
    <input type="hidden" name="action"  value="reverify_all">
    <input type="hidden" name="sesskey" value="' . s(sesskey()) . '">
    <button type="submit" class="btn btn-sm btn-outline-warning" style="white-space:nowrap;"
            title="Reset all failed and stuck-pending USI verifications so the next scheduled batch re-attempts them"
            onclick="return confirm(\'This will reset all failed and pending USI verifications so they are re-attempted on the next scheduled run. Students already verified will not be affected. Continue?\');">
      <svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px;margin-top:-2px"
           viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
        <path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
      </svg>
      Re-verify all students
    </button>
  </form>

  <!-- 3. Export this view (CSV) — respects current filter/state/search params -->
  <a href="' . htmlspecialchars($csvurl, ENT_QUOTES) . '"
     class="btn btn-sm btn-outline-secondary"
     style="white-space:nowrap;"
     title="Download the currently filtered student list as a CSV file">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px;margin-top:-2px"
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="7 10 12 15 17 10"/>
      <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>
    Export this view (CSV)
  </a>

  <!-- 4. Export students without DOB — always targets the missing-DOB cohort -->
  <a href="' . htmlspecialchars($missingdoburl, ENT_QUOTES) . '"
     class="btn btn-sm btn-outline-secondary"
     style="white-space:nowrap;"
     title="Download a CSV of all students who have a USI but are missing a date of birth">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px;margin-top:-2px"
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
      <line x1="3" y1="10" x2="21" y2="10"/>
      <line x1="8" y1="14" x2="8.01" y2="14"/>
    </svg>
    Export students without DOB
  </a>

  <!-- Connection test result panel (hidden until test runs) -->
  <div id="rtoc-conn-test-result" style="display:none;width:100%;margin-top:8px;
       padding:10px 14px;border-radius:6px;font-size:13px;line-height:1.5;"></div>
</div>

<script>
(function () {
    var btn    = document.getElementById("rtoc-conn-test-btn");
    var result = document.getElementById("rtoc-conn-test-result");
    if (!btn || !result) { return; }

    // Connection test endpoint is same-origin (students.php?action=connection_test).
    // The platform API credentials are never sent to or stored in the browser.
    var CONN_TEST_URL = ' . $jsConnTestUrl . ';

    btn.addEventListener("click", function () {
        btn.disabled = true;
        btn.textContent = "Testing…";
        result.style.display = "none";

        fetch(CONN_TEST_URL, {
            method: "GET",
            headers: { "Accept": "application/json" },
            credentials: "same-origin"
        })
        .then(function (r) {
            return r.json().then(function (data) {
                return { status: r.status, data: data };
            });
        })
        .then(function (resp) {
            var data    = resp.data;
            var ok      = resp.status === 200 && (data.ok || data.certReady || data.certUploaded);
            var bgColor = ok ? "#f0fdf4" : "#fef2f2";
            var border  = ok ? "#86efac" : "#fca5a5";
            var txtColor= ok ? "#15803d" : "#b91c1c";

            var icon = ok
                ? "<svg style=\"width:14px;height:14px;vertical-align:middle;margin-right:6px\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>"
                : "<svg style=\"width:14px;height:14px;vertical-align:middle;margin-right:6px\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"15\" y1=\"9\" x2=\"9\" y2=\"15\"/><line x1=\"9\" y1=\"9\" x2=\"15\" y2=\"15\"/></svg>";

            var lines = [];
            if (data.message) { lines.push("<strong>" + rtocHtmlEsc(data.message) + "</strong>"); }
            if (data.certExpiry) {
                var expLine = "Credential expiry: " + rtocHtmlEsc(data.certExpiry.substring(0, 10));
                if (data.daysToExpiry !== null && data.daysToExpiry !== undefined) {
                    expLine += " (" + (data.daysToExpiry >= 0 ? data.daysToExpiry + " days remaining" : "EXPIRED " + Math.abs(data.daysToExpiry) + " days ago") + ")";
                }
                lines.push(expLine);
            }
            if (data.certSubject)  { lines.push("Subject: " + rtocHtmlEsc(data.certSubject)); }
            if (data.orgId)        { lines.push("Org ID: "  + rtocHtmlEsc(String(data.orgId))); }
            if (data.testMode !== undefined) { lines.push("Mode: " + (data.testMode ? "EVTE test environment" : "Production")); }
            if (data.certDecryptError) { lines.push("<span style=\"color:#b91c1c\">Decrypt error: " + rtocHtmlEsc(data.certDecryptError) + "</span>"); }

            result.style.background   = bgColor;
            result.style.border       = "1px solid " + border;
            result.style.color        = txtColor;
            result.innerHTML          = icon + lines.join(" &nbsp;|&nbsp; ");
            result.style.display      = "block";
        })
        .catch(function (err) {
            result.style.background = "#fef2f2";
            result.style.border     = "1px solid #fca5a5";
            result.style.color      = "#b91c1c";
            result.innerHTML        = "<svg style=\"width:14px;height:14px;vertical-align:middle;margin-right:6px\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"15\" y1=\"9\" x2=\"9\" y2=\"15\"/><line x1=\"9\" y1=\"9\" x2=\"15\" y2=\"15\"/></svg>"
                + "<strong>Connection failed</strong> — could not reach the AI Grader platform. Check your API URL setting and network connectivity.";
            result.style.display    = "block";
        })
        .finally(function () {
            btn.disabled    = false;
            btn.textContent = "";
            var svg = "<svg style=\"width:13px;height:13px;vertical-align:middle;margin-right:4px;margin-top:-2px\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M5 12.55a11 11 0 0 1 14.08 0\"/><path d=\"M1.42 9a16 16 0 0 1 21.16 0\"/><path d=\"M8.53 16.11a6 6 0 0 1 6.95 0\"/><line x1=\"12\" y1=\"20\" x2=\"12.01\" y2=\"20\"/></svg>";
            btn.innerHTML = svg + "Run connection test";
        });
    });

    function rtocHtmlEsc(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }
})();
</script>';
}

// DOB-FROM-NAT: Show a "Sync DOBs from NAT" button when any students are missing DOB.
// This one-click backfill reads all previously uploaded NAT00080 data and writes
// dateofbirth into every student record that is currently missing it.
if ($stats['usi_missing_dob'] > 0) {
    $syncurl      = new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'sync_dobs_from_nat',
        'sesskey' => sesskey(),
    ]);
    $importurl = new moodle_url('/local/rtocompliance/data_import.php');
    // Check whether any NAT00080 data has been previously uploaded so we can
    // tailor the message: if there is no NAT data yet, the Sync button is useless
    // and we should lead with the Upload link instead.
    $hasNatData = $DB->record_exists('local_rtocompliance_avetmiss_student', []);
    if ($hasNatData) {
        $dobmsg = $stats['usi_missing_dob'] . ' student(s) have a USI but no date of birth — USI verification will be skipped. '
            . 'Click <strong>Sync DOBs</strong> to fill from your uploaded NAT00080 data, '
            . 'or <strong>Upload NAT00080</strong> if you have a newer export.';
    } else {
        $dobmsg = $stats['usi_missing_dob'] . ' student(s) have a USI but no date of birth — USI verification will be skipped. '
            . 'Upload a NAT00080 file first, then click <strong>Sync DOBs</strong> to automatically fill their date of birth.';
    }
    // INLINE-NAT-UPLOAD (v5.9.307): Build the DOB-sync bar with an inline file
    // upload form so the admin can pick a NAT00080 file right here without
    // navigating to the full Data Import wizard first.
    $uploadActionUrl = (new moodle_url('/local/rtocompliance/students.php'))->out(false);
    $svgWarn  = '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" stroke="currentColor" stroke-width="2" fill="none"/><path d="M12 9v4M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    $svgSync  = '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    echo '<div class="rtoc-dob-sync-bar" style="flex-wrap:wrap;gap:8px;">'
        // ── Warning message ───────────────────────────────────────────────
        . '<span class="rtoc-dob-sync-msg" style="flex:1 1 100%">' . $svgWarn . $dobmsg . '</span>'
        // ── Inline file upload form ───────────────────────────────────────
        // Uses multipart/form-data to POST the NAT00080 file directly to
        // students.php?action=upload_nat_dobs, which parses it and syncs DOBs
        // in the same request — no redirect to the Data Import wizard needed.
        . '<form method="post" action="' . htmlspecialchars($uploadActionUrl, ENT_QUOTES) . '"'
        .       ' enctype="multipart/form-data"'
        .       ' style="display:inline-flex;align-items:center;gap:6px;margin:0"'
        .       ' id="rtoc-nat-upload-form">'
        .   '<input type="hidden" name="action"  value="upload_nat_dobs">'
        .   '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
        .   '<label for="rtoc-nat-file" class="btn btn-secondary btn-sm rtoc-dob-sync-btn mb-0" style="cursor:pointer;margin:0" title="Select your NAT00080 .txt file">'
        .     '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="17 8 12 3 7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="3" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        .     '<span id="rtoc-nat-file-label">Choose NAT00080 file…</span>'
        .   '</label>'
        .   '<input type="file" id="rtoc-nat-file" name="nat00080file" accept=".txt,text/plain"'
        .         ' style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none"'
        .         ' onchange="'
        .           'var n=this.files[0]?this.files[0].name:\'No file chosen\';'
        .           'document.getElementById(\'rtoc-nat-file-label\').textContent=n;'
        .           'document.getElementById(\'rtoc-nat-submit\').style.display=\'inline-block\';'
        .         '">'
        .   '<button type="submit" id="rtoc-nat-submit" class="btn btn-warning btn-sm rtoc-dob-sync-btn" style="display:none">'
        .     $svgSync . 'Upload &amp; Sync DOBs'
        .   '</button>'
        . '</form>'
        // ── Sync from previously-uploaded NAT data (if any exists) ────────
        . ($hasNatData
            ? '<a href="' . htmlspecialchars($syncurl->out(false), ENT_QUOTES) . '" class="btn btn-outline-warning btn-sm rtoc-dob-sync-btn"'
                . ' title="Re-sync from NAT00080 data already imported via the Data Import page">'
                . $svgSync . 'Sync from imported data'
              . '</a>'
            : '')
        . '</div>';
}

// USI-STUCK-PENDING-FIX (v5.9.302): Show a "Retry pending USI verifications" button
// when students are stuck at usiverified=3 (CERT_PENDING or transient error). These
// students have a USI and DOB but were never successfully verified because the machine
// credential was not yet active when the batch last ran. Resetting them to usiverified=0
// causes the next scheduled batch to re-attempt them automatically.
if ($stats['usi_pending_retry'] > 0) {
    $retryurl = new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'retry_pending_usi',
        'sesskey' => sesskey(),
    ]);
    echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#0ea5e9;">'
        . '<span class="rtoc-dob-sync-msg">'
        . '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 8v4l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        . $stats['usi_pending_retry'] . ' student(s) are stuck in USI pending status — their verification was attempted before the machine credential was active '
        . '(platform returned CERT_PENDING). Now that the credential is accepted, click <strong>Retry</strong> to queue them for re-verification on the next scheduled run.'
        . '</span>'
        . ' <a href="' . htmlspecialchars($retryurl->out(false), ENT_QUOTES) . '" class="btn btn-info btn-sm rtoc-dob-sync-btn">'
        . '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . 'Retry Pending USI Verifications'
        . '</a>'
        . '</div>';
}

// Exclude site administrators (stored in $CFG->siteadmins, not in role_assignments)
// $siteadminlist already built at top of file (before stats queries).

// FIX-SUSPENDED-ACCOUNTS (v5.2.38): Include u.suspended in SELECT so rows
// can be badged; use :suspendedfilter param so the WHERE clause works for
// both the normal view (0 = active only) and the "Suspended accounts" filter (1).
$suspendedfilter = ($filter === 'suspended') ? 1 : 0;

$sql = "SELECT u.id, u.firstname, u.lastname, u.email,
               u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
               u.suspended,
               s.id as profileid, s.usi, s.usiverified, s.usiverifieddate, s.profilecomplete,
               s.statecode, s.postcode, s.suburb, s.dateofbirth,
               suit.id as suitabilityid, suit.status as suitabilitystatus
        FROM {user} u
        LEFT JOIN {local_rtocompliance_students} s ON s.userid = u.id
        LEFT JOIN (
            SELECT userid, MAX(id) AS maxid
              FROM {local_rtocompliance_suitability}
             GROUP BY userid
        ) lsuit ON lsuit.userid = u.id
        LEFT JOIN {local_rtocompliance_suitability} suit ON suit.id = lsuit.maxid
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
        WHERE u.deleted = 0 AND u.suspended = :suspendedfilter
          AND u.id NOT IN ($siteadminlist)
          AND staff.userid IS NULL
          AND rtoc_trainer.userid IS NULL";

$params = ['suspendedfilter' => $suspendedfilter];

if (!empty($search)) {
    // FIX-SEARCH-FULLNAME (v5.9.80): also match "Firstname Lastname" and "Lastname Firstname"
    // so full-name searches return results (previously only single-field searches worked).
    $fullNameFwd = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
    $fullNameRev = $DB->sql_concat('u.lastname',  "' '", 'u.firstname');
    $searchsql  = $DB->sql_like('u.firstname',  ':search1', false, false);
    $searchsql .= ' OR ' . $DB->sql_like('u.lastname',   ':search2', false, false);
    $searchsql .= ' OR ' . $DB->sql_like('u.email',      ':search3', false, false);
    $searchsql .= ' OR ' . $DB->sql_like('s.usi',        ':search4', false, false);
    $searchsql .= ' OR ' . $DB->sql_like($fullNameFwd,   ':search5', false, false);
    $searchsql .= ' OR ' . $DB->sql_like($fullNameRev,   ':search6', false, false);
    $sql .= " AND ($searchsql)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
    $params['search4'] = '%' . $search . '%';
    $params['search5'] = '%' . $search . '%';
    $params['search6'] = '%' . $search . '%';
}

if ($filter === 'incomplete') {
    $sql .= " AND (s.profilecomplete = 0 OR s.profilecomplete IS NULL)";
} else if ($filter === 'nousi') {
    $sql .= " AND (s.usi IS NULL OR s.usi = '')";
} else if ($filter === 'usiverified') {
    $sql .= " AND s.usiverified = 1";
} else if ($filter === 'usiunverified') {
    // USI-PENDING-FILTER-FIX (v5.9.304): previously only showed usiverified=0; students
    // stuck at usiverified=3 (STATUS_PENDING — transient error on first attempt) were
    // invisible in the "Unverified USI" view even though they still need verification.
    $sql .= " AND s.usi IS NOT NULL AND s.usi != '' AND s.usiverified IN (0, 3)";
} else if ($filter === 'usifailed') {
    $sql .= " AND s.usiverified = 2";
} else if ($filter === 'usimissingdob') {
    // DOB-MISSING-USI-FIX (v5.2.88): students who have a USI but are missing DOB.
    $sql .= " AND s.usi IS NOT NULL AND s.usi != '' AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)";
}

if (!empty($state)) {
    $sql .= " AND s.statecode = :state";
    $params['state'] = $state;
}

$countsql   = "SELECT COUNT(DISTINCT u.id) " . substr($sql, strpos($sql, 'FROM'));
$totalcount = $DB->count_records_sql($countsql, $params);

// SORT: build ORDER BY from user-selected column + direction.
$sortSqlDir = ($sortdir === 'desc') ? 'DESC' : 'ASC';
if ($sort === 'name') {
    $sql .= " ORDER BY u.lastname $sortSqlDir, u.firstname $sortSqlDir";
} else {
    $sql .= " ORDER BY u.lastname ASC, u.firstname ASC";
}

$students = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Load TAS options for the bulk-send bar
$tas_records = $DB->get_records_sql(
    "SELECT id, qualificationcode, qualificationname
       FROM {local_rtocompliance_tas}
      WHERE status IN ('approved','review')
        AND entryrequirements IS NOT NULL AND entryrequirements != ''
      ORDER BY qualificationcode",
    []
);
$tasoptions_bulk = [];
foreach ($tas_records as $t) {
    $tasoptions_bulk[$t->id] = s($t->qualificationcode) . ': ' . s($t->qualificationname);
}

// ── Bulk action bar ────────────────────────────────────────────────────────────
$bulkformurl = (new moodle_url('/local/rtocompliance/suitability_bulk.php', [
    'action'  => 'bulk_send',
    'sesskey' => sesskey(),
]))->out(false);

$fillgapsurl = (new moodle_url('/local/rtocompliance/suitability_bulk.php', [
    'action' => 'fill_gaps',
]))->out(false);

echo '<div class="rto-bulk-bar">';
echo '<span class="rto-bulk-bar__label">' . get_string('suitability_bulk_heading', 'local_rtocompliance') . '</span>';

if (!empty($tasoptions_bulk)) {
    echo '<select id="bulk-tasid" class="form-control form-control-sm mr-2">';
    echo '<option value="">' . get_string('suitability_bulk_select_tas', 'local_rtocompliance') . '</option>';
    foreach ($tasoptions_bulk as $tid => $tlabel) {
        echo '<option value="' . $tid . '">' . $tlabel . '</option>';
    }
    echo '</select>';
    echo '<button id="bulk-send-btn" type="button" class="btn btn-sm btn-primary mr-3" disabled>'
        . get_string('suitability_bulk_send_selected', 'local_rtocompliance') . '</button>';
}

echo html_writer::link(
    $fillgapsurl,
    get_string('suitability_fill_gaps_btn_short', 'local_rtocompliance'),
    ['class' => 'btn btn-sm btn-warning']
);

// BULK-UNSUSPEND (v5.2.72): Show "Activate Selected" button when viewing suspended accounts.
if ($filter === 'suspended') {
    echo ' <button id="bulk-unsuspend-btn" type="button" class="btn btn-sm btn-success ml-2" disabled>'
        . 'Activate Selected Accounts</button>';
}

echo '</div>';

// ── Hidden bulk-send form (submitted via JS) ──────────────────────────────────
echo '<form id="bulk-send-form" method="post" action="' . s($bulkformurl) . '" style="display:none">';
echo '<div id="bulk-form-fields"></div>';
echo '</form>';

// ── Student action form (v5.2.78): wraps the table so students[] checkboxes ───
// POST natively when "Activate Selected Accounts" is clicked — no JS form-building.
$studentactionurl = (new moodle_url('/local/rtocompliance/students.php'))->out(false);
echo '<form id="student-action-form" method="post" action="' . s($studentactionurl) . '">';
echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
echo '<input type="hidden" name="action"  value="bulk_unsuspend">';

// ── Student table ─────────────────────────────────────────────────────────────
// Build sortable "Name" column header.
$nameNextDir   = ($sort === 'name' && $sortdir === 'asc') ? 'desc' : 'asc';
$nameSortUrl   = new moodle_url('/local/rtocompliance/students.php', [
    'filter'  => $filter,
    'state'   => $state,
    'search'  => $search,
    'sort'    => 'name',
    'sortdir' => $nameNextDir,
    'page'    => 0,
]);
$nameSortArrow = '';
if ($sort === 'name') {
    $nameSortArrow = $sortdir === 'asc'
        ? ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 2L9 8H1z" fill="currentColor"/></svg>'
        : ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 8L1 2h8z" fill="currentColor"/></svg>';
}
$nameHeader = html_writer::link($nameSortUrl, get_string('name') . $nameSortArrow,
    ['style' => 'white-space:nowrap;text-decoration:none;color:inherit;font-weight:bold']);

$table = new html_table();
$table->head = [
    html_writer::checkbox('selectall', '1', false, '', ['id' => 'selectall-cb', 'title' => get_string('suitability_selectall', 'local_rtocompliance')]),
    $nameHeader,
    get_string('email'),
    get_string('usi', 'local_rtocompliance'),
    get_string('residentialstate', 'local_rtocompliance'),
    get_string('profilestatus', 'local_rtocompliance'),
    get_string('suitability_col', 'local_rtocompliance'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';

foreach ($students as $student) {
    // Display as "Surname, Firstname" so the list reads surname-first
    // and sorts naturally on the already-applied ORDER BY u.lastname.
    $fullname = s(trim($student->lastname)) . ', ' . s(trim($student->firstname));

    // Pre-build URLs here so the USI cell (DOB-missing branch) can use $editurl.
    $editurl_pre  = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->id]);

    // ── USI cell: rich verification status display ──────────────────────────
    // Pattern: USI code in monospace + status badge referencing usi.gov.au
    // Modelled on VETtrak / WISENET / aXcelerate conventions.
    $usicell = '';
    if (!empty($student->usi)) {
        $vstat = (int)($student->usiverified ?? 0);
        $vdate = '';
        if (!empty($student->usiverifieddate)) {
            $vdate = userdate($student->usiverifieddate, get_string('strftimedatefullshort', 'langconfig'));
        }

        // USI code in monospace
        $usicell = '<code class="rtoc-usi-code">' . s($student->usi) . '</code>';

        if ($vstat === 1) {
            // Verified against usi.gov.au — green shield
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-verified">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M5.5 8l1.8 1.8 3.2-3.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . ' Verified via <strong>usi.gov.au</strong>'
                . ($vdate ? '<span class="rtoc-usi-date"> &mdash; ' . $vdate . '</span>' : '')
                . '</span>';
        } else if ($vstat === 2) {
            // Verification failed — red badge + retry
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-failed">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                . ' Verification failed'
                . '</span>'
                . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '">Retry &#x21BB;</button>' : '');
        } else if ($vstat === 3) {
            // Pending
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-pending">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                . ' Verification pending'
                . '</span>';
        } else if ($vstat === 4) {
            // Manual review needed
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-review">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2l1.3 2.6 2.9.4-2.1 2.1.5 2.9L8 8.6 5.4 10l.5-2.9L3.8 5l2.9-.4L8 2z" stroke="currentColor" stroke-width="1.2"/></svg>'
                . ' Needs manual review'
                . '</span>'
                . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '">Verify</button>' : '');
        } else {
            // Not yet verified (status=0).
            // DOB-MISSING-USI-FIX (v5.2.88): if DOB is absent, USI verification will
            // silently skip this student. Show a specific warning + "Add DOB" link
            // instead of the misleading "Verify" button that does nothing.
            $hasdob = !empty($student->dateofbirth) && (int)$student->dateofbirth > 0;
            if (!$hasdob) {
                $usicell .= '<span class="rtoc-usi-badge rtoc-usi-nodob">'
                    . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5M8 10h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                    . ' DOB required to verify'
                    . '</span>';
            } else {
                // DOB present — normal unverified state with Verify button.
                $usicell .= '<span class="rtoc-usi-badge rtoc-usi-unverified">'
                    . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M8 6v3M8 10.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                    . ' Not yet verified'
                    . '</span>'
                    . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '">Verify via usi.gov.au &#x2192;</button>' : '');
            }
        }
    } else {
        $usicell = '<span class="rtoc-usi-missing">'
            . '<svg style="width:11px;height:11px;vertical-align:middle;margin-right:3px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2C4.7 2 2 4.7 2 8s2.7 6 6 6 6-2.7 6-6-2.7-6-6-6zm0 3v3.5M8 11h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            . 'Missing'
            . '</span>';
    }

    $statename = '';
    if (!empty($student->statecode)) {
        $statename = $states[$student->statecode] ?? $student->statecode;
    }

    $statuscell = '';
    if (!empty($student->suspended)) {
        $statuscell = '<span class="badge badge-danger" title="This account is suspended in Moodle">SUSPENDED</span>';
    } else if ($student->profileid && $student->profilecomplete) {
        $statuscell = '<span class="badge badge-success">' . get_string('complete', 'local_rtocompliance') . '</span>';
    } else if ($student->profileid) {
        $statuscell = '<span class="badge badge-warning">' . get_string('incomplete', 'local_rtocompliance') . '</span>';
    } else {
        $statuscell = '<span class="badge badge-secondary">' . get_string('noprofile', 'local_rtocompliance') . '</span>';
    }

    // NOTE: $editurl/$enrolurl are also used in the USI cell above (DOB-missing link), so they
    // must be defined before the USI cell is rendered. Keep this definition in place — both
    // the USI cell code above and the Actions cell below reference these variables.
    $editurl  = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->id]);
    $enrolurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $student->id]);

    // FIX-SUSPENDED-UNSUSPEND (v5.2.38): for suspended accounts show an Unsuspend button
    // instead of the normal Actions menu (suspended users can't be enrolled/edited anyway).
    if (!empty($student->suspended)) {
        $unsuspendurl = new moodle_url('/local/rtocompliance/students.php', [
            'action'       => 'unsuspend',
            'actionuserid' => $student->id,
            'sesskey'      => sesskey(),
        ]);
        $actions = html_writer::link(
            $unsuspendurl,
            'Unsuspend Account',
            ['class' => 'btn btn-sm btn-warning', 'title' => 'Re-activate this Moodle account']
        );
    } else {
    // Body-appended custom menu — no Bootstrap dropdown, escapes overflow/transform clipping.
    $actions  = '<button class="btn btn-sm btn-outline-secondary rtoc-act-btn" type="button"'
        . ' data-edit-url="'  . htmlspecialchars($editurl->out(false),  ENT_QUOTES) . '"'
        . ' data-enrol-url="' . htmlspecialchars($enrolurl->out(false), ENT_QUOTES) . '"'
        . ' data-edit-label="'  . htmlspecialchars(get_string('editprofile', 'local_rtocompliance'), ENT_QUOTES) . '"'
        . ' data-enrol-label="' . htmlspecialchars(get_string('enrolments',  'local_rtocompliance'), ENT_QUOTES) . '">'
        . 'Actions &#9660;'
        . '</button>';
    }

    // Suitability column + checkbox logic
    $suitcell    = '';
    $cbDisabled  = false;

    // BUG-MAY1-AUDIT #8 (v4.2.44): tester clicked Send Checklist on the
    // Guest user row and got the email_to_user error "User 1 (Guest user)
    // email (root@localhost) is invalid".  v4.2.42 already added a hard
    // server-side guard inside suitability_send.php; here we hide the
    // button entirely on the list when the student row obviously cannot
    // receive email (no address, root@localhost, or guest user id 1) so
    // the admin never reaches the broken state in the first place.
    $cannotEmail = empty($student->email)
        || strpos($student->email, '@') === false
        || $student->email === 'root@localhost'
        || (int)$student->id === 1;

    if ($cannotEmail) {
        $suitcell = '<span class="badge badge-secondary" title="No valid email address">No email</span>';
    } else if (empty($student->suitabilityid)) {
        $sendurl  = new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $student->id]);
        $suitcell = html_writer::link($sendurl, get_string('suitability_send_btn_short', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary']);
    } else {
        $viewurl  = new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $student->suitabilityid]);
        $sendurl  = new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $student->id]);
        $ststatus = $student->suitabilitystatus;

        if ($ststatus === 'pending') {
            $suitcell  = '<span class="badge badge-info">' . get_string('suitability_status_pending', 'local_rtocompliance') . '</span><br>';
            $resendurl = new moodle_url('/local/rtocompliance/suitability_send.php', [
                'userid'   => $student->id,
                'resendid' => $student->suitabilityid,
                'sesskey'  => sesskey(),
            ]);
            $suitcell .= html_writer::link($resendurl, get_string('suitability_resend', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary mt-1']);
        } else if ($ststatus === 'suitable') {
            $cbDisabled = true;
            $suitcell   = '<span class="badge badge-success">' . get_string('suitability_status_suitable', 'local_rtocompliance') . '</span><br>';
            $suitcell  .= html_writer::link($viewurl, get_string('view'), ['class' => 'btn btn-sm btn-outline-success mt-1']);
        } else if ($ststatus === 'not_suitable') {
            $suitcell  = '<span class="badge badge-danger">' . get_string('suitability_status_not_suitable', 'local_rtocompliance') . '</span><br>';
            $suitcell .= html_writer::link($viewurl, get_string('suitability_view_override', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-danger mt-1']);
        } else if ($ststatus === 'override_suitable') {
            $cbDisabled = true;
            $suitcell   = '<span class="badge badge-warning">' . get_string('suitability_status_override', 'local_rtocompliance') . '</span><br>';
            $suitcell  .= html_writer::link($viewurl, get_string('view'), ['class' => 'btn btn-sm btn-outline-warning mt-1']);
        } else {
            $suitcell = html_writer::link($sendurl, get_string('suitability_send_btn_short', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary']);
        }
    }

    $cbAttrs = ['class' => 'student-cb', 'data-userid' => $student->id];
    if ($cbDisabled) {
        $cbAttrs['disabled'] = 'disabled';
        $cbAttrs['title']    = get_string('suitability_cb_already_suitable', 'local_rtocompliance');
    }
    $checkbox = html_writer::checkbox('students[]', $student->id, false, '', $cbAttrs);

    $table->data[] = [
        $checkbox,
        $fullname,
        $student->email,
        $usicell,
        $statename,
        $statuscell,
        $suitcell,
        $actions,
    ];
}

if (empty($students)) {
    echo $OUTPUT->notification(get_string('noresults'), \core\output\notification::NOTIFY_INFO);
} else {
    echo '<div class="rtoc-table-wrapper">';
    echo html_writer::table($table);
    echo '</div>';
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
}
echo '</form>'; // closes student-action-form

// ── JS for checkboxes + bulk send ─────────────────────────────────────────────
echo html_writer::script('
(function () {
    var selectAll  = document.getElementById("selectall-cb");
    var sendBtn    = document.getElementById("bulk-send-btn");
    var tasSelect  = document.getElementById("bulk-tasid");
    var form       = document.getElementById("bulk-send-form");
    var fields     = document.getElementById("bulk-form-fields");
    var bulkUrl    = ' . json_encode($bulkformurl) . ';

    function countChecked() {
        return document.querySelectorAll(".student-cb:checked").length;
    }

    function refreshBtn() {
        if (!sendBtn) return;
        var ok = countChecked() > 0 && tasSelect && tasSelect.value;
        sendBtn.disabled = !ok;
    }

    if (selectAll) {
        selectAll.addEventListener("change", function () {
            document.querySelectorAll(".student-cb:not([disabled])").forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            refreshBtn();
        });
    }

    document.querySelectorAll(".student-cb").forEach(function (cb) {
        cb.addEventListener("change", function () {
            refreshBtn();
            if (selectAll && !cb.checked) selectAll.checked = false;
        });
    });

    if (tasSelect) {
        tasSelect.addEventListener("change", refreshBtn);
    }

    if (sendBtn) {
        sendBtn.addEventListener("click", function () {
            if (!tasSelect || !tasSelect.value) {
                alert("' . get_string('suitability_bulk_select_tas', 'local_rtocompliance') . '");
                return;
            }
            var checked = document.querySelectorAll(".student-cb:checked");
            if (!checked.length) {
                alert("' . get_string('suitability_bulk_none_selected', 'local_rtocompliance') . '");
                return;
            }
            fields.innerHTML = "";
            var inp = document.createElement("input");
            inp.type = "hidden"; inp.name = "tasid"; inp.value = tasSelect.value;
            fields.appendChild(inp);
            checked.forEach(function (cb) {
                var uid = document.createElement("input");
                uid.type = "hidden"; uid.name = "userids[]"; uid.value = cb.dataset.userid;
                fields.appendChild(uid);
            });
            form.submit();
        });
    }

    // BULK-UNSUSPEND JS (v5.2.72): Wire up "Activate Selected Accounts" button.
    // BULK-UNSUSPEND (v5.2.78): students[] checkboxes are inside #student-action-form
    // and POST natively — no dynamic input-building needed.
    var unsuspendBtn       = document.getElementById("bulk-unsuspend-btn");
    var studentActionForm  = document.getElementById("student-action-form");

    function refreshUnsuspendBtn() {
        if (!unsuspendBtn) return;
        unsuspendBtn.disabled = countChecked() === 0;
    }

    document.querySelectorAll(".student-cb").forEach(function (cb) {
        cb.addEventListener("change", refreshUnsuspendBtn);
    });
    if (selectAll) {
        selectAll.addEventListener("change", refreshUnsuspendBtn);
    }

    if (unsuspendBtn && studentActionForm) {
        unsuspendBtn.addEventListener("click", function () {
            var checked = document.querySelectorAll(".student-cb:checked");
            if (!checked.length) {
                alert("Please select at least one student.");
                return;
            }
            if (!confirm("Activate " + checked.length + " selected account(s)? They will be able to log in again.")) {
                return;
            }
            studentActionForm.submit();
        });
    }

    // ── Custom body-appended action menu (v4.0.83) ──────────────────────────────
    // Bootstrap dropdowns fail inside overflow-x:auto wrappers when any Moodle
    // theme ancestor has a CSS transform (which cancels position:fixed).
    // Solution: build a plain <div> menu and append it to document.body so it
    // escapes ALL overflow containers and stacking contexts unconditionally.
    var _openMenu = null;
    var _openBtn  = null;

    function rtocCloseMenu() {
        if (_openMenu) {
            _openMenu.parentNode && _openMenu.parentNode.removeChild(_openMenu);
            _openMenu = null;
            _openBtn  = null;
        }
    }

    function rtocPositionMenu(btn, menu) {
        var r   = btn.getBoundingClientRect();
        var sx  = window.pageXOffset || 0;
        var sy  = window.pageYOffset || 0;
        var mw  = menu.offsetWidth  || 180;
        var mh  = menu.offsetHeight || 64;
        var top = r.bottom + sy + 2;
        var left = r.left  + sx;
        if (r.bottom + mh > window.innerHeight && r.top >= mh) {
            top = r.top + sy - mh - 2;
        }
        if (r.left + mw > window.innerWidth) {
            left = r.right + sx - mw;
        }
        if (left < 4) left = 4;
        menu.style.top  = top  + "px";
        menu.style.left = left + "px";
    }

    function rtocOpenMenu(btn) {
        rtocCloseMenu();
        var editUrl   = btn.getAttribute("data-edit-url");
        var enrolUrl  = btn.getAttribute("data-enrol-url");
        var editLabel = btn.getAttribute("data-edit-label")  || "Edit Profile";
        var enrolLabel= btn.getAttribute("data-enrol-label") || "Enrolments";

        var menu = document.createElement("div");
        menu.className = "dropdown-menu show rtoc-body-menu";
        menu.style.cssText = "position:absolute;z-index:99999;min-width:10rem;";

        var a1 = document.createElement("a");
        a1.className = "dropdown-item";
        a1.href = editUrl;
        a1.textContent = editLabel;
        menu.appendChild(a1);

        var a2 = document.createElement("a");
        a2.className = "dropdown-item";
        a2.href = enrolUrl;
        a2.textContent = enrolLabel;
        menu.appendChild(a2);

        document.body.appendChild(menu);
        _openMenu = menu;
        _openBtn  = btn;
        rtocPositionMenu(btn, menu);
    }

    document.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest && e.target.closest(".rtoc-act-btn");
        if (btn) {
            e.stopPropagation();
            if (_openBtn === btn) { rtocCloseMenu(); return; }
            rtocOpenMenu(btn);
            return;
        }
        if (_openMenu && !_openMenu.contains(e.target)) {
            rtocCloseMenu();
        }
    }, true);

    window.addEventListener("scroll", rtocCloseMenu, true);
    window.addEventListener("resize", rtocCloseMenu, true);

    // ── USI "Verify via usi.gov.au" inline AJAX ──────────────────────────────
    // Clicking any .rtoc-usi-verify-btn POSTs to student_usi_verify.php and
    // replaces the USI cell HTML in-place so the admin sees the result immediately
    // without a full page reload.
    var USI_VERIFY_URL = ' . json_encode((new moodle_url('/local/rtocompliance/student_usi_verify.php'))->out(false)) . ';
    var USI_SESSKEY    = ' . json_encode(sesskey()) . ';

    // Show a dismissible error message directly below the USI cell.
    function rtocShowVerifyError(cell, msg) {
        var existing = cell.querySelector(".rtoc-usi-error-msg");
        if (existing) existing.remove();
        var el = document.createElement("div");
        el.className = "rtoc-usi-error-msg";
        el.style.cssText = "margin-top:4px;padding:5px 8px;background:#fef2f2;border:1px solid #fca5a5;border-radius:4px;color:#b91c1c;font-size:12px;line-height:1.4;";
        el.textContent = msg;
        var dismiss = document.createElement("button");
        dismiss.textContent = "\u00d7";
        dismiss.style.cssText = "float:right;background:none;border:none;cursor:pointer;color:#b91c1c;font-size:14px;line-height:1;padding:0 0 0 6px;";
        dismiss.addEventListener("click", function () { el.remove(); });
        el.prepend(dismiss);
        cell.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.remove(); }, 10000);
    }

    document.addEventListener("click", function (e) {
        var btn = e.target && e.target.closest && e.target.closest(".rtoc-usi-verify-btn");
        if (!btn) return;
        e.preventDefault();  // VERIFY-BTN-FORM-FIX (v5.9.298): prevent the button from submitting
        e.stopPropagation(); // the enclosing student-action-form — buttons default to type="submit"

        var profileid = btn.getAttribute("data-profileid");
        if (!profileid) return;

        var cell = btn.closest("td");
        if (!cell) return;

        // Show spinner inline while we wait for the API call
        btn.disabled = true;
        var origHtml = cell.innerHTML;
        var codeEl = cell.querySelector(".rtoc-usi-code");
        var usiCode = codeEl ? codeEl.textContent : "";
        var spinner = "<span class=\"rtoc-usi-badge rtoc-usi-pending\" style=\"margin-top:3px\">"
            + "<span class=\"rtoc-usi-spinner\"></span> Verifying via usi.gov.au..."
            + "</span>";
        if (codeEl) {
            // Keep the USI code visible, replace just the badge+button portion
            codeEl.nextSibling && (codeEl.parentNode.innerHTML = "<code class=\"rtoc-usi-code\">" + usiCode + "</code>" + spinner);
        }

        fetch(USI_VERIFY_URL + "?profileid=" + encodeURIComponent(profileid) + "&sesskey=" + encodeURIComponent(USI_SESSKEY) + "&ajax=1", {
            method: "GET",
            headers: { "Accept": "application/json" },
            credentials: "same-origin"
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || typeof data.html === "undefined") {
                cell.innerHTML = origHtml;
                rtocShowVerifyError(cell, "Verification request failed — please try again.");
                return;
            }
            cell.innerHTML = data.html;
            if (!data.success && data.message) {
                rtocShowVerifyError(cell, data.message);
            }
        })
        .catch(function () {
            cell.innerHTML = origHtml;
            rtocShowVerifyError(cell, "Could not reach the verification service — check your connection.");
        });
    }, true);
})();
');

echo html_writer::end_div(); // .students-container
echo $OUTPUT->footer();
