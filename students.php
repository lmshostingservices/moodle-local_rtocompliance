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
 * RTO Compliance plugin — students.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
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
require_login();

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

    // Helper: DDMMYYYY -> validated noon-UTC Unix timestamp (0 if invalid).
    $dobToTs = function ($dobStr) {
        if (strlen((string)$dobStr) !== 8 || !ctype_digit((string)$dobStr)) return 0;
        $dd = (int)substr($dobStr, 0, 2);
        $mm = (int)substr($dobStr, 2, 2);
        $yy = (int)substr($dobStr, 4, 4);
        if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12 || $yy < 1900 || $yy > 2100) return 0;
        $ts = gmmktime(12, 0, 0, $mm, $dd, $yy);
        // v6.3.10: pre-1970 DOBs are NEGATIVE timestamps and valid — the
        // 1900-2100 year gate above bounds the range; only false is invalid.
        return ($ts === false) ? 0 : (int)$ts;
    };

    // Step 2: Build clientid -> timestamp map (validate + convert DDMMYYYY -> Unix).
    $clientToTs = [];
    foreach ($clientToDob as $clientid => $dobStr) {
        $ts = $dobToTs($dobStr);
        if ($ts !== 0) $clientToTs[$clientid] = $ts;
    }

    $filledById = 0;        // Path A: local_rtocompliance_students.clientid
    $filledByIdnumber = 0;  // Path B: mdl_user.idnumber
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
                if ($ts === 0) continue;
                $DB->update_record('local_rtocompliance_students', (object)[
                    'id'           => $row->id,
                    'dateofbirth'  => $ts,
                    'timemodified' => time(),
                ]);
                $filledById++;
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
                if ($ts === 0) continue;
                $stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$ur->userid], 'id, clientid, dateofbirth');
                if (!$stud) continue;
                if (!empty($stud->dateofbirth) && (int)$stud->dateofbirth !== 0) continue;
                // Also backfill clientid so future syncs can use Path A.
                $upd = (object)['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()];
                if (empty($stud->clientid)) {
                    $upd->clientid = trim($ur->idnumber);
                }
                $DB->update_record('local_rtocompliance_students', $upd);
                $filledByIdnumber++;
            }
        }
    }

    // Path C (v6.2.22): validated full-name match. The NAT client-identifier numbering
    // does not line up with newer students (5-digit clientid / blank idnumber), so the
    // ID paths alone miss most of them. Match mdl_user first+last name to the NAT
    // firstname+familyname, case- and word-order-insensitive. ONLY accept a name that
    // maps to exactly ONE date of birth in the NAT data; if a name has conflicting DOBs,
    // skip it and never guess. Also skip when two students share the same name.
    $normName = function ($first, $last) {
        $toks = preg_split('/[^a-z]+/', strtolower(trim((string)$first . ' ' . (string)$last)), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($toks)) return '';
        sort($toks);
        return implode(' ', $toks);
    };

    // Build normalized-name -> set of distinct DOB timestamps from the NAT staging data.
    $nameRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        $DB->sql_isnotempty('local_rtocompliance_avetmiss_student', 'dob', false, false),
        [], '', 'firstname, familyname, name, dob'
    );
    $nameToTsSet = [];
    foreach ($nameRs as $nr) {
        $ts = $dobToTs($nr->dob);
        if ($ts === 0) continue;
        $first = (string)($nr->firstname ?? '');
        $last  = (string)($nr->familyname ?? '');
        if (trim($first . $last) === '' && !empty($nr->name)) {
            // NAT "name" is stored as one string; word-order-insensitive norm handles it.
            $last  = (string)$nr->name;
            $first = '';
        }
        $key = $normName($first, $last);
        if ($key === '') continue;
        if (!isset($nameToTsSet[$key])) $nameToTsSet[$key] = [];
        $nameToTsSet[$key][$ts] = true;
    }
    $nameRs->close();

    // Reduce to unambiguous name -> single timestamp; remember ambiguous names.
    $nameToTs = [];
    $ambiguousNames = [];
    foreach ($nameToTsSet as $key => $tsset) {
        if (count($tsset) === 1) {
            $nameToTs[$key] = (int)array_key_first($tsset);
        } else {
            $ambiguousNames[$key] = true;
        }
    }
    unset($nameToTsSet);

    // Students still missing a DOB after the two ID paths.
    $missing = $DB->get_records_sql(
        "SELECT s.id, u.firstname, u.lastname
           FROM {local_rtocompliance_students} s
           JOIN {user} u ON u.id = s.userid
          WHERE (s.dateofbirth IS NULL OR s.dateofbirth = 0)
            AND u.deleted = 0"
    );
    // Guard against student-side name collisions: if two students share a normalized
    // name, we cannot safely assign either, so skip both.
    $missingNameCount = [];
    foreach ($missing as $ms) {
        $k = $normName($ms->firstname ?? '', $ms->lastname ?? '');
        if ($k === '') continue;
        $missingNameCount[$k] = ($missingNameCount[$k] ?? 0) + 1;
    }

    $filledByName = 0;
    $skippedAmbiguous = 0;
    $stillUnmatched = 0;
    foreach ($missing as $ms) {
        $key = $normName($ms->firstname ?? '', $ms->lastname ?? '');
        if ($key !== '' && isset($nameToTs[$key]) && ($missingNameCount[$key] ?? 0) === 1) {
            $DB->update_record('local_rtocompliance_students', (object)[
                'id'           => $ms->id,
                'dateofbirth'  => $nameToTs[$key],
                'timemodified' => time(),
            ]);
            $filledByName++;
        } else if ($key !== '' && (isset($ambiguousNames[$key]) || ($missingNameCount[$key] ?? 0) > 1)) {
            $skippedAmbiguous++;
        } else {
            $stillUnmatched++;
        }
    }

    $totalFilled = $filledById + $filledByIdnumber + $filledByName;
    $msg = 'DOB backfill complete: ' . $totalFilled . ' student record(s) updated. '
         . 'Matched by client ID: ' . $filledById . '; by ID number: ' . $filledByIdnumber
         . '; by unique name: ' . $filledByName . '. '
         . 'Skipped (name maps to conflicting DOBs, or shared by two students): ' . $skippedAmbiguous . '. '
         . 'Still unmatched (no DOB found in NAT data): ' . $stillUnmatched . '.';
    redirect(
        new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']),
        $msg,
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

    if ($lines === false || count($lines) === 0) {
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            'Could not read the uploaded file. Please try again.', null, \core\output\notification::NOTIFY_ERROR);
    }

    foreach ($lines as $line) {
        // Need at least 81 chars to read the DOB field (positions 73-80 inclusive).
        if (strlen($line) < 81) {
            $skippedRows++;
            continue;
        }
        $clientid = trim(substr($line, 0, 10));
        $dobStr   = trim(substr($line, 73, 8));
        if ($clientid === '' || strlen($dobStr) !== 8 || !ctype_digit($dobStr)) {
            $skippedRows++;
            continue;
        }
        // First DOB seen for this client ID wins (same policy as sync_dobs_from_nat).
        if (!isset($clientToDob[$clientid])) {
            $clientToDob[$clientid] = $dobStr; // DDMMYYYY
        }
        $parsedRows++;
    }

    if (empty($clientToDob)) {
        redirect(new moodle_url('/local/rtocompliance/students.php'),
            "No valid DOB records found in the uploaded file ($parsedRows rows parsed, $skippedRows skipped). "
            . "This parser expects a standard fixed-width NAT00080 file (AVETMISS 8.0). "
            . "For tab-delimited or variant formats, use the Data Import page.",
            null, \core\output\notification::NOTIFY_WARNING);
    }

    // Convert DDMMYYYY → Unix timestamps (same validation as sync_dobs_from_nat).
    $clientToTs = [];
    foreach ($clientToDob as $clientid => $dobStr) {
        $dd = (int)substr($dobStr, 0, 2);
        $mm = (int)substr($dobStr, 2, 2);
        $yy = (int)substr($dobStr, 4, 4);
        if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12 || $yy < 1900 || $yy > 2100) continue;
        $ts = gmmktime(12, 0, 0, $mm, $dd, $yy);
        if ($ts === false) continue; // v6.3.10: negative (pre-1970) is valid.
        $clientToTs[$clientid] = (int)$ts;
    }

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
                if ($ts === 0) continue;
                $DB->update_record('local_rtocompliance_students', (object)[
                    'id' => $row->id, 'dateofbirth' => $ts, 'timemodified' => time(),
                ]);
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
                if ($ts === 0) continue;
                $stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int)$ur->userid], 'id, clientid, dateofbirth');
                if (!$stud || (!empty($stud->dateofbirth) && (int)$stud->dateofbirth !== 0)) continue;
                $upd = (object)['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()];
                if (empty($stud->clientid)) {
                    $upd->clientid = trim($ur->idnumber); // backfill clientid for future Path A
                }
                $DB->update_record('local_rtocompliance_students', $upd);
                $updated++;
            }
        }
    }

    $fname = s(basename($upload['name']));
    redirect(
        new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']),
        "DOB sync complete: $updated student record(s) updated from $parsedRows NAT00080 rows in '$fname'.",
        null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// ── EXPORT: CSV of students who have a USI but no date of birth (v6.2.21) ──────
// A simple, editable template: the admin fills the "Date of birth" column and
// re-uploads it via the "Upload DOB CSV" action below.
if ($action === 'export_dob_csv' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    $rs = $DB->get_recordset_sql(
        "SELECT u.firstname, u.lastname, u.email, s.clientid, s.usi
           FROM {user} u
           JOIN {local_rtocompliance_students} s ON s.userid = u.id
          WHERE u.deleted = 0 AND s.usi IS NOT NULL AND s.usi <> ''
            AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)
          ORDER BY u.lastname ASC, u.firstname ASC"
    );
    $filename = 'italc-students-missing-dob-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['Family name', 'Given name', 'Email', 'Client identifier', 'USI', 'Date of birth']);
    foreach ($rs as $r) {
        fputcsv($out, [$r->lastname, $r->firstname, $r->email, (string) $r->clientid, (string) $r->usi, '']);
    }
    $rs->close();
    fclose($out);
    exit;
}

// ── UPLOAD: CSV of dates of birth to backfill the missing ones (v6.2.21) ───────
// Round-trips with the export above. Matches each row to a student by Client
// identifier first, then USI, then email; only writes where DOB is blank.
// Accepts DD/MM/YYYY, YYYY-MM-DD, DD-MM-YYYY and DDMMYYYY date formats.
if ($action === 'upload_dob_csv' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    $redirurl = new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']);

    $upload = $_FILES['dobcsv'] ?? null;
    if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
        redirect($redirurl, 'No CSV file was received. Please choose a file and try again.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if ((int) ($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        redirect($redirurl, 'File too large (max 10 MB).', null, \core\output\notification::NOTIFY_ERROR);
    }

    $parsedob = function ($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return 0; }
        $d = $m = $y = 0;
        if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $raw, $mm)) {
            $d = (int) $mm[1]; $m = (int) $mm[2]; $y = (int) $mm[3];
        } else if (preg_match('#^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})$#', $raw, $mm)) {
            $y = (int) $mm[1]; $m = (int) $mm[2]; $d = (int) $mm[3];
        } else if (preg_match('#^(\d{2})(\d{2})(\d{4})$#', $raw, $mm)) {
            $d = (int) $mm[1]; $m = (int) $mm[2]; $y = (int) $mm[3];
        } else {
            return 0;
        }
        if ($d < 1 || $d > 31 || $m < 1 || $m > 12 || $y < 1900 || $y > 2100) { return 0; }
        $ts = gmmktime(12, 0, 0, $m, $d, $y);
        return ($ts === false) ? 0 : (int) $ts; // v6.3.10: negative (pre-1970) is valid.
    };

    $handle = @fopen($upload['tmp_name'], 'r');
    if ($handle === false) {
        redirect($redirurl, 'Could not read the uploaded file.', null, \core\output\notification::NOTIFY_ERROR);
    }
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        redirect($redirurl, 'The CSV appears to be empty.', null, \core\output\notification::NOTIFY_ERROR);
    }
    if (isset($header[0])) { $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); }
    $idx = [];
    foreach ($header as $i => $col) { $idx[strtolower(trim((string) $col))] = $i; }
    $col_client = $idx['client identifier'] ?? $idx['clientid'] ?? $idx['client id'] ?? null;
    $col_usi    = $idx['usi'] ?? null;
    $col_email  = $idx['email'] ?? null;
    $col_dob    = $idx['date of birth'] ?? $idx['dob'] ?? $idx['dateofbirth'] ?? null;

    if ($col_dob === null || ($col_client === null && $col_usi === null && $col_email === null)) {
        fclose($handle);
        redirect($redirurl,
            'CSV must have a "Date of birth" column and at least one of "Client identifier", "USI" or "Email". '
            . 'Tip: use "Download DOB template (CSV)", fill in the Date of birth column, then re-upload.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $updated = 0; $skipped = 0; $nomatch = 0; $baddate = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) { continue; }
        $ts = $parsedob($col_dob !== null ? ($row[$col_dob] ?? '') : '');
        if ($ts === 0) { $baddate++; continue; } // v6.3.10: negative (pre-1970) is valid.

        $stud = null;
        if ($col_client !== null && trim((string) ($row[$col_client] ?? '')) !== '') {
            $stud = $DB->get_record('local_rtocompliance_students',
                ['clientid' => trim((string) $row[$col_client])], 'id, dateofbirth', IGNORE_MULTIPLE);
        }
        if (!$stud && $col_usi !== null && trim((string) ($row[$col_usi] ?? '')) !== '') {
            $stud = $DB->get_record('local_rtocompliance_students',
                ['usi' => trim((string) $row[$col_usi])], 'id, dateofbirth', IGNORE_MULTIPLE);
        }
        if (!$stud && $col_email !== null && trim((string) ($row[$col_email] ?? '')) !== '') {
            $u = $DB->get_record('user', ['email' => trim((string) $row[$col_email]), 'deleted' => 0], 'id', IGNORE_MULTIPLE);
            if ($u) {
                $stud = $DB->get_record('local_rtocompliance_students',
                    ['userid' => (int) $u->id], 'id, dateofbirth', IGNORE_MULTIPLE);
            }
        }
        if (!$stud) { $nomatch++; continue; }
        if (!empty($stud->dateofbirth) && (int) $stud->dateofbirth !== 0) { $skipped++; continue; }

        $DB->update_record('local_rtocompliance_students',
            (object) ['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()]);
        $updated++;
    }
    fclose($handle);

    $msg = "DOB CSV upload complete: {$updated} updated.";
    $extra = [];
    if ($skipped > 0) { $extra[] = "{$skipped} already had a DOB"; }
    if ($nomatch > 0) { $extra[] = "{$nomatch} not matched to a student"; }
    if ($baddate > 0) { $extra[] = "{$baddate} had an unreadable date"; }
    if ($extra) { $msg .= ' (' . implode(', ', $extra) . ').'; }
    redirect($redirurl, $msg, null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
}

// SYNC-AVETMISS-FIELDS (v5.9.316): Back-fill sex, indigenousstatus, labourforcestatus,
// highestschoollevel, suburb, statecode from the avetmiss_student staging table into
// local_rtocompliance_students for every student whose profile fields are still at the
// hardcoded '@'/'@@' defaults (i.e. they were enrolled before this fix shipped).
// Matches by clientid — only overwrites fields that are still at "not stated" defaults.
if ($action === 'sync_avetmiss_fields' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());

    // Load the most-recent staging row per clientid (latest importid wins).
    $stagingRs = $DB->get_recordset_sql(
        "SELECT s.clientid, s.sex, s.suburb, s.state,
                s.indigenousstatus, s.labourforcestatus, s.highestschoollevel,
                s.languageathome, s.countryofbirth, s.disabilityflag, s.prioreducationflag, s.atschoolflag
           FROM {local_rtocompliance_avetmiss_student} s
           JOIN (
                 SELECT clientid, MAX(importid) AS maxid
                   FROM {local_rtocompliance_avetmiss_student}
                  WHERE clientid IS NOT NULL AND clientid <> ''
                  GROUP BY clientid
                ) mx ON mx.clientid = s.clientid AND mx.maxid = s.importid
          WHERE s.clientid IS NOT NULL AND s.clientid <> ''"
    );

    $updated = 0;
    foreach ($stagingRs as $stg) {
        // Find the matching student record.
        $stud = $DB->get_record('local_rtocompliance_students',
            ['clientid' => trim((string)$stg->clientid)],
            'id, sex, indigenousstatus, labourforcestatus, highestschoollevel, suburb, statecode, languageathome, countryofbirth, disabilityflag, prioreducationflag, atschoolflag',
            IGNORE_MISSING);
        if (!$stud) continue;

        $upd = (object)['id' => $stud->id];
        $changed = false;

        // Sex: update only if currently '@' (not stated) and staging has M/F/X.
        $sexRaw = strtoupper(trim((string)($stg->sex ?? '')));
        if (in_array($sexRaw, ['M','F','X'], true) && (empty($stud->sex) || $stud->sex === '@')) {
            $upd->sex = $sexRaw;
            $changed  = true;
        }
        // Indigenous status: update if currently '@' and staging has a real code.
        $indRaw = trim((string)($stg->indigenousstatus ?? ''));
        if ($indRaw !== '' && $indRaw !== '@' && (empty($stud->indigenousstatus) || $stud->indigenousstatus === '@')) {
            $upd->indigenousstatus = $indRaw;
            $changed = true;
        }
        // Labour force: update if currently '@@' and staging has a real code.
        $labRaw = trim((string)($stg->labourforcestatus ?? ''));
        if ($labRaw !== '' && $labRaw !== '@@' && (empty($stud->labourforcestatus) || $stud->labourforcestatus === '@@')) {
            $upd->labourforcestatus = $labRaw;
            $changed = true;
        }
        // Highest school level: update if currently '@@' and staging has a real code.
        $schlRaw = trim((string)($stg->highestschoollevel ?? ''));
        if ($schlRaw !== '' && $schlRaw !== '@@' && (empty($stud->highestschoollevel) || $stud->highestschoollevel === '@@')) {
            $upd->highestschoollevel = $schlRaw;
            $changed = true;
        }
        // Suburb: update if currently blank and staging has a value.
        $suburbRaw = trim((string)($stg->suburb ?? ''));
        if ($suburbRaw !== '' && empty($stud->suburb)) {
            $upd->suburb = $suburbRaw;
            $changed = true;
        }
        // State code: update if currently blank and staging has a value.
        $stateRaw = trim((string)($stg->state ?? ''));
        if ($stateRaw !== '' && empty($stud->statecode)) {
            $upd->statecode = $stateRaw;
            $changed = true;
        }
        // Language at home: update if currently '1201' (default English) and staging has a different real code.
        $langRaw = trim((string)($stg->languageathome ?? ''));
        if ($langRaw !== '' && !preg_match('/^[@\s]+$/', $langRaw) && (empty($stud->languageathome) || $stud->languageathome === '1201')) {
            $upd->languageathome = $langRaw;
            $changed = true;
        }
        // Country of birth: update if currently '1101' (default Australia) and staging has a different real code.
        $cntryRaw = trim((string)($stg->countryofbirth ?? ''));
        if ($cntryRaw !== '' && $cntryRaw !== '@@@@' && !preg_match('/^[@\s]+$/', $cntryRaw) && (empty($stud->countryofbirth) || $stud->countryofbirth === '1101')) {
            $upd->countryofbirth = $cntryRaw;
            $changed = true;
        }
        // Disability flag: update if currently 'N' (default) and staging has 'Y'.
        $disRaw = trim((string)($stg->disabilityflag ?? ''));
        if ($disRaw === 'Y' && (empty($stud->disabilityflag) || $stud->disabilityflag === 'N')) {
            $upd->disabilityflag = 'Y';
            $changed = true;
        }
        // Prior educational achievement flag: update if currently '@' and staging has a real value.
        $priorRaw = trim((string)($stg->prioreducationflag ?? ''));
        if ($priorRaw !== '' && $priorRaw !== '@' && (empty($stud->prioreducationflag) || $stud->prioreducationflag === '@')) {
            $upd->prioreducationflag = $priorRaw;
            $changed = true;
        }
        // At school flag: update only if staging says 'Y' (default is 'N', no reason to overwrite a real 'N').
        $atschRaw = trim((string)($stg->atschoolflag ?? ''));
        if ($atschRaw === 'Y' && (empty($stud->atschoolflag) || $stud->atschoolflag !== 'Y')) {
            $upd->atschoolflag = 'Y';
            $changed = true;
        }

        if ($changed) {
            $upd->timemodified = time();
            $DB->update_record('local_rtocompliance_students', $upd);
            $updated++;
        }
    }
    $stagingRs->close();

    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        "AVETMISS profile sync complete: $updated student record(s) updated from imported NAT data.",
        null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// SYNC-PROGRAMCODES (v5.9.329): Back-fill programcode on enrolment records where it is
// blank, by walking the Moodle category ancestor chain for each course.
// The RTO's top-level Moodle category name contains the qualification code
// (e.g. "ABC12345 — a Diploma qualification") — any course nested anywhere under
// that category inherits that qualification code. This replaces all previous fallbacks.
if ($action === 'rebuild_course_map' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    $result = local_rtocompliance_seed_course_map();
    $notice = 'Course map rebuilt: ' . $result['inserted'] . ' new mapping(s) added, '
            . $result['skipped'] . ' already existed across '
            . count($result['quals_scanned']) . ' qualification(s). '
            . 'View and confirm mappings at: <a href="/local/rtocompliance/course_map.php">Moodle Course Map</a>';
    \core\notification::success(html_entity_decode($notice));
    redirect(new moodle_url('/local/rtocompliance/students.php'));
}

if ($action === 'sync_programcodes' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());

    // Load every category once — avoids per-row DB queries during the walk.
    $allcats = $DB->get_records('course_categories', null, '', 'id, name, parent');

    // AVETMISS qual code at start of category name.
    $pcRx = '/^([A-Z]{2,8}[0-9]{3,6}[A-Z]{0,2})(?:\s|$|-|—|:)/u';

    $detectQualcode = function (int $courseid) use ($DB, $allcats, $pcRx): string {
        $course = $DB->get_record('course', ['id' => $courseid], 'category');
        if (!$course) {
            return '';
        }
        $catid   = (int)$course->category;
        $visited = [];
        while ($catid > 0 && !isset($visited[$catid])) {
            $visited[$catid] = true;
            if (!isset($allcats[$catid])) {
                break;
            }
            $cat = $allcats[$catid];
            if (preg_match($pcRx, strtoupper(trim((string)$cat->name)), $m)) {
                return $m[1];
            }
            $catid = (int)$cat->parent;
        }
        return '';
    };

    // Stream all enrolments with blank programcode — use recordset to avoid OOM on large sites.
    $blankRs = $DB->get_recordset_sql(
        "SELECT id, courseid
           FROM {local_rtocompliance_enrolments}
          WHERE programcode IS NULL OR programcode = ''
          ORDER BY courseid ASC"
    );

    $updated     = 0;
    $skipped     = 0;
    $courseCache = [];   // courseid → detected qual code (avoid duplicate walks)

    foreach ($blankRs as $row) {
        $cid = (int)$row->courseid;
        if (!array_key_exists($cid, $courseCache)) {
            $courseCache[$cid] = $detectQualcode($cid);
        }
        $qualcode = $courseCache[$cid];
        if ($qualcode === '') {
            $skipped++;
            continue;
        }
        $DB->set_field('local_rtocompliance_enrolments', 'programcode', $qualcode, ['id' => $row->id]);
        $updated++;
    }
    $blankRs->close();

    // TASK-46 (v5.9.346): When records were skipped, redirect to the detailed
    // skipped-programcodes report so admins can see which students/courses were
    // affected and take corrective action (Link to QB or Mark as non-VET).
    if ($skipped > 0) {
        redirect(
            new moodle_url('/local/rtocompliance/skipped_programcodes.php', ['updated' => $updated]),
            "Sync complete: $updated record(s) updated. $skipped record(s) could not be resolved automatically — see below.",
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    redirect(
        new moodle_url('/local/rtocompliance/students.php'),
        "Qualification code sync complete: $updated enrolment record(s) updated from Moodle category tree. All records now have a qualification code.",
        null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
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
// Read same config keys used by usi_settings.php so we can detect if
// the API connection (and cert upload) has been done.
$_usi_apiurl = trim((string) get_config('local_rtocompliance', 'apiurl'));
$_usi_siteid = trim((string) get_config('local_rtocompliance', 'siteid'));
$_usi_apikey = trim((string) get_config('local_rtocompliance', 'apikey'));
$_usi_api_configured = ($_usi_apiurl !== '' && $_usi_siteid !== '' && $_usi_apikey !== '');

// Check for a stored cert status flag (set by usi_settings.php on successful upload).
// Falls back to a lightweight /api/usi/status ping when API is connected.
$_usi_cert_ok = false;
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
        // Platform returns 'certReady' (not 'hasCert' — hasCert was a typo that
        // caused the banner to show as "not uploaded" even after a successful
        // credential upload). Fixed v5.9.315.
        if (is_array($decoded) && !empty($decoded['certReady'])) {
            $_usi_cert_ok = true;
            // Cache locally so a transient ping timeout doesn't re-show the banner.
            set_config('usi_cert_uploaded', 1, 'local_rtocompliance');
        }
    }
    // Fallback: trust the locally-cached flag set on last successful upload or ping.
    if (!$_usi_cert_ok && get_config('local_rtocompliance', 'usi_cert_uploaded')) {
        $_usi_cert_ok = true;
    }
}

$_usi_settings_url = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false);

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('student_records', 'local_rtocompliance'), null, null, 'students');

// ── USI setup popup / banner ──────────────────────────────────────────────────
if (!$_usi_api_configured) {
    // Hard gate: API not connected at all — show auto-opening modal.
    // DECLUTTER (v5.9.419): converted the auto-opening full-screen USI modal into an
    // inline banner (matching the softer one below). The modal popped up on every
    // Students-page load, blocking the roster and duplicating the dashboard USI CTA —
    // the same actionable message now sits inline without interrupting the workflow.
    echo '
<div style="background:#fee2e2;border:1px solid #ef4444;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#b91c1c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#991b1b;">USI verification not configured</strong>
    <span style="font-size:13px;color:#7f1d1d;margin-left:8px;">Your site isn\'t connected yet, so USI verification will fail. Connect the site + upload your machine credential.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-primary btn-sm" style="white-space:nowrap;flex-shrink:0;" title="Open USI verification settings to connect your site and upload your machine credential">Set Up USI Verification</a>
</div>';
} else if (!$_usi_cert_ok) {
    // API connected but no cert uploaded — show a softer inline banner.
    echo '
<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
  <svg style="flex-shrink:0;width:20px;height:20px" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
  <div style="flex:1;min-width:200px;">
    <strong style="font-size:14px;color:#92400e;">USI Machine Credential not uploaded</strong>
    <span style="font-size:13px;color:#78350f;margin-left:8px;">USI verification will fail until you upload your myID certificate.</span>
  </div>
  <a href="' . s($_usi_settings_url) . '" class="btn btn-warning btn-sm" style="white-space:nowrap;flex-shrink:0;" title="Open USI verification settings to upload your myID machine credential">
    <svg style="width:13px;height:13px;vertical-align:middle;margin-right:5px;margin-top:-2px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
    Set Up USI Verification
  </a>
</div>';
}

echo html_writer::start_div('students-container');

echo html_writer::start_div('students-header');
echo html_writer::tag('h2', get_string('student_records', 'local_rtocompliance'));
echo html_writer::end_div();

// Plain-English explainer card for the student roster table.
echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;">'
    . '<div style="font-weight:700;color:#1e3a8a;margin-bottom:6px;font-size:15px;">About this student list</div>'
    . '<div style="font-size:14.5px;color:#334155;line-height:1.55;margin-bottom:8px;">Every row is one learner known to the RTO. Use the filters and search above to narrow the list, then use the Actions menu on a row to edit a profile, manage enrolments or view certificates. Here is what each column means:</div>'
    . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;">'
    . '<div><strong>Name</strong> &mdash; the learner shown surname-first; click it to open the full profile.</div>'
    . '<div><strong>Email</strong> &mdash; the email address on the Moodle account.</div>'
    . '<div><strong>USI</strong> &mdash; the Unique Student Identifier and its verification status against usi.gov.au.</div>'
    . '<div><strong>Residential State</strong> &mdash; the state or territory recorded for the learner.</div>'
    . '<div><strong>Profile Status</strong> &mdash; whether the AVETMISS profile is complete, incomplete or missing.</div>'
    . '<div><strong>Suitability</strong> &mdash; the pre-enrolment suitability check and its outcome.</div>'
    . '<div><strong>Actions</strong> &mdash; per-row menu to edit, enrol, or view results and certificates.</div>'
    . '</div></div>';

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
$stats['missing_usi']     = max(0, $stats['withprofile'] - $stats['withusi']); // v5.9.368: clamp (withprofile excludes trainers, withusi doesn't → could go negative)
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
// RPL-CT-COMPETENT-COUNT (v5.9.410): count ALL competent AVETMISS outcomes, not just 20 —
// RPL (51), Credit Transfer (60) and non-assessable-satisfactory (81) are competent too, so
// the "Competency Achieved (Units)" card no longer under-counts RTOs that use RPL/CT.
$stats['competent_units'] = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {local_rtocompliance_enrolments} WHERE outcomeidentifier IN ('20','51','60','81')");

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
    ['label' => get_string('total_students', 'local_rtocompliance'),                      'value' => $stats['total'],           'color' => 'blue',   'icon' => $iconUsers, 'tip' => 'Every learner on record here, current and past.'],
    ['label' => 'Students with AVETMISS Profile',                                        'value' => $stats['withprofile'],     'color' => 'purple', 'icon' => $iconDoc, 'tip' => 'Learners who have a national VET data profile started. AVETMISS is the student data every training organisation must report to government.'],
    ['label' => get_string('complete', 'local_rtocompliance') . ' Profiles',              'value' => $stats['complete'],        'color' => 'green',  'icon' => $iconCheck, 'tip' => 'Profiles with every mandatory reporting field filled in. Only these can go into your national data submission.'],
    ['label' => get_string('usi', 'local_rtocompliance') . ' Recorded',                   'value' => $stats['withusi'],         'color' => 'amber',  'icon' => $iconKey, 'tip' => 'Learners who have a USI on file. The USI is the national student ID number needed before a certificate can be issued.'],
    ['label' => 'USI Missing',                                                             'value' => $stats['missing_usi'],     'color' => $stats['missing_usi'] > 0 ? 'rose' : 'green', 'icon' => $iconAlert, 'tip' => 'Learners with a profile but no USI yet. Collect their USI so results can be reported and certificates issued.'],
    ['label' => 'USI Has No DOB (can\'t verify)',                                          'value' => $stats['usi_missing_dob'], 'color' => $stats['usi_missing_dob'] > 0 ? 'rose' : 'green', 'icon' => $iconAlert, 'tip' => 'Learners who have a USI but no date of birth, so it cannot be checked against usi.gov.au. Add their date of birth to verify.'],
    ['label' => 'Total Enrolments',                                                        'value' => $stats['enrolments'],      'color' => 'blue',   'icon' => $iconBook, 'tip' => 'Count of every enrolment record (one learner in one course). A single learner can have several.'],
    ['label' => 'Certificates Issued',                                                     'value' => $stats['certs_issued'],    'color' => 'green',  'icon' => $iconAward, 'tip' => 'Testamurs and statements already issued to learners.'],
    ['label' => 'Competency Achieved (Units)',                                             'value' => $stats['competent_units'], 'color' => 'amber',  'icon' => $iconBar, 'tip' => 'Number of individual units learners have been marked competent in, including recognition of prior learning.'],
];
foreach ($summaryStats as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color'], ['title' => $s['tip']]);
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
        .   '<button type="submit" id="rtoc-nat-submit" class="btn btn-warning btn-sm rtoc-dob-sync-btn" style="display:none" title="Upload the chosen NAT00080 file and fill in missing dates of birth from it">'
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
        // ── SIMPLE DOB CSV round-trip (v6.2.21): download a template of the
        // missing-DOB students, fill the Date of birth column, re-upload it. ──
        . '<span style="flex-basis:100%;height:0"></span>'
        . '<span style="font-size:12px;color:#78350f;align-self:center;">Or use a simple CSV:</span>'
        . '<a href="' . htmlspecialchars(
                (new moodle_url('/local/rtocompliance/students.php',
                    ['action' => 'export_dob_csv', 'sesskey' => sesskey()]))->out(false), ENT_QUOTES) . '"'
            . ' class="btn btn-outline-secondary btn-sm rtoc-dob-sync-btn"'
            . ' title="Download a CSV of the students missing a DOB, ready to fill in and re-upload">'
            . 'Download DOB template (CSV)</a>'
        . '<form method="post" action="' . htmlspecialchars($uploadActionUrl, ENT_QUOTES) . '"'
            . ' enctype="multipart/form-data" style="display:inline-flex;align-items:center;gap:6px;margin:0">'
            . '<input type="hidden" name="action" value="upload_dob_csv">'
            . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
            . '<label for="rtoc-dobcsv-file" class="btn btn-secondary btn-sm rtoc-dob-sync-btn mb-0" style="cursor:pointer;margin:0" title="Choose a CSV with a Date of birth column and a Client identifier, USI or Email column">'
            .   'Choose DOB CSV…</label>'
            . '<input type="file" id="rtoc-dobcsv-file" name="dobcsv" accept=".csv,text/csv"'
            .   ' style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none"'
            .   ' onchange="document.getElementById(\'rtoc-dobcsv-submit\').style.display=\'inline-block\';">'
            . '<button type="submit" id="rtoc-dobcsv-submit" class="btn btn-warning btn-sm rtoc-dob-sync-btn" style="display:none" title="Upload the CSV and fill in missing dates of birth">'
            .   $svgSync . 'Upload DOB CSV</button>'
          . '</form>'
        . '</div>';
}

// SYNC-AVETMISS-FIELDS (v5.9.316): Show a "Sync AVETMISS Fields" button when NAT data
// has been imported but student profiles still have the hardcoded '@'/'@@' defaults
// for sex, indigenous status, labour force, school level, suburb or state code.
//
// AVETMISS-COLUMN-GUARD (v5.9.328): indigenousstatus / labourforcestatus /
// highestschoollevel were added to local_rtocompliance_avetmiss_student in
// v5.9.316.  On sites where Moodle's upgrade notification has not yet been
// triggered the columns don't exist yet, which threw "Error reading from
// database" and crashed the entire students.php page.  Wrap both queries in
// a try-catch so the page degrades gracefully — the sync banner is simply
// hidden until after the admin visits Site Administration → Notifications.
$_hasNatWithDemo   = false;
$_needsAvetmissSync = false;
try {
    $_hasNatWithDemo = $DB->record_exists_sql(
        "SELECT 1 FROM {local_rtocompliance_avetmiss_student}
          WHERE (indigenousstatus IS NOT NULL AND indigenousstatus <> '')
             OR (labourforcestatus IS NOT NULL AND labourforcestatus <> '')
             OR (highestschoollevel IS NOT NULL AND highestschoollevel <> '')
             OR (sex IS NOT NULL AND sex <> '' AND sex <> '@')
         LIMIT 1"
    );
    if ($_hasNatWithDemo) {
        $_needsAvetmissSync = $DB->record_exists_sql(
            "SELECT 1 FROM {local_rtocompliance_students}
              WHERE (indigenousstatus = '@' OR indigenousstatus IS NULL)
                AND clientid IS NOT NULL AND clientid <> ''
             LIMIT 1"
        );
    }
} catch (\dml_exception $e) {
    // Columns not yet added (upgrade pending) — skip the sync banner silently.
    $_hasNatWithDemo   = false;
    $_needsAvetmissSync = false;
}
if ($_needsAvetmissSync) {
    $_syncAvetmissUrl = new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'sync_avetmiss_fields',
        'sesskey' => sesskey(),
    ]);
    $_svgSync = '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#8b5cf6;">'
        . '<span class="rtoc-dob-sync-msg">'
        . '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px;color:#8b5cf6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . 'Student AVETMISS profiles have blank fields (sex, indigenous status, labour force, school level) even though NAT data has been imported. '
        . 'Click <strong>Sync AVETMISS Fields</strong> to back-fill these from your imported NAT00080 data.'
        . '</span>'
        . ' <a href="' . htmlspecialchars($_syncAvetmissUrl->out(false), ENT_QUOTES) . '"'
        .   ' class="btn btn-sm rtoc-dob-sync-btn" style="background:#7c3aed;color:#fff !important;border:none"'
        .   ' title="Read staged NAT00080 data and fill in blank AVETMISS fields for all matched students">'
        . $_svgSync . 'Sync AVETMISS Fields'
        . '</a>'
        . '</div>';
}

// COURSE-MAP-TABLE (v5.9.335): Show a banner prompting the admin to seed the course map
// if the table is empty. When the table is not seeded, completion detection falls back
// to the old runtime regex walk — correct but slower. Once seeded, all paths are fast.
try {
    $_courseMapEmpty = $DB->get_manager()->table_exists('local_rtocompliance_course_map')
        && !$DB->record_exists('local_rtocompliance_course_map', []);
} catch (\Throwable $e) {
    $_courseMapEmpty = false;
}
if ($_courseMapEmpty) {
    $_rebuildUrl = new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'rebuild_course_map',
        'sesskey' => sesskey(),
    ]);
    echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#6366f1;">'
        . '<span class="rtoc-dob-sync-msg">'
        . '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px;color:#6366f1" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/><rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/><rect x="14" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/><rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/></svg>'
        . '<strong>Moodle Course Map is empty.</strong> The course → qual/unit mapping table has not yet been seeded. '
        . 'Completion detection is currently using the slower regex fallback. '
        . 'Click <strong>Seed Course Map</strong> to build the permanent mapping from your Qual Builder records and Moodle category tree — takes seconds, runs once.'
        . '</span>'
        . ' <a href="' . htmlspecialchars($_rebuildUrl->out(false), ENT_QUOTES) . '"'
        .   ' class="btn btn-sm rtoc-dob-sync-btn" style="background:#4f46e5;color:#fff !important;border:none">'
        . '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . 'Seed Course Map</a>'
        . ' <a href="/local/rtocompliance/course_map.php" class="btn btn-sm btn-outline-secondary" style="margin-left:4px">View Map</a>'
        . '</div>';
}

// SYNC-PROGRAMCODES (v5.9.329 / updated v5.9.346): Show a banner when enrolment records
// have a blank programcode — these students will not appear as completers in
// generate_qual_certs.php SOURCE 2, so certificates cannot be generated for them.
// TASK-46 (v5.9.346): exclude vetflag='N' enrolments — those have been explicitly
// marked non-VET by the admin via the skipped-programcodes report and do not need
// a qualification code.
$_hasBlankProgramcodes = false;
try {
    $_hasBlankProgramcodes = $DB->record_exists_sql(
        "SELECT 1 FROM {local_rtocompliance_enrolments}
          WHERE (programcode IS NULL OR programcode = '')
            AND courseid IS NOT NULL AND courseid > 0
            AND (vetflag IS NULL OR vetflag != 'N')
         LIMIT 1"
    );
} catch (\dml_exception $e) {
    $_hasBlankProgramcodes = false;
}
if ($_hasBlankProgramcodes) {
    $_syncPcUrl     = new moodle_url('/local/rtocompliance/students.php', [
        'action'  => 'sync_programcodes',
        'sesskey' => sesskey(),
    ]);
    $_skippedRptUrl = new moodle_url('/local/rtocompliance/skipped_programcodes.php');
    echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#f59e0b;">'
        . '<span class="rtoc-dob-sync-msg">'
        . '<svg style="width:15px;height:15px;vertical-align:middle;margin-right:5px;color:#f59e0b" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        . 'Some enrolment records have no qualification code — these students will <strong>not appear as completers</strong> when generating certificates. '
        . 'Click <strong>Sync Qualification Codes</strong> to auto-detect from the Moodle category tree, '
        . 'or <strong>View report</strong> to see which courses are affected and why.'
        . '</span>'
        . ' <a href="' . htmlspecialchars($_syncPcUrl->out(false), ENT_QUOTES) . '"'
        .   ' class="btn btn-sm rtoc-dob-sync-btn" style="background:#b45309;color:#fff !important;border:none"'
        .   ' title="Walk the Moodle category ancestor tree to fill in the blank qualification code on enrolment records">'
        . '<svg style="width:13px;height:13px;vertical-align:middle;margin-right:4px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . 'Sync Qualification Codes'
        . '</a>'
        . ' <a href="' . htmlspecialchars($_skippedRptUrl->out(false), ENT_QUOTES) . '"'
        .   ' class="btn btn-sm btn-outline-secondary" style="margin-left:4px">'
        . 'View report</a>'
        . ' <a href="' . htmlspecialchars(
                (new moodle_url('/local/rtocompliance/repair_programcodes.php'))->out(false),
                ENT_QUOTES
            ) . '"'
        .   ' class="btn btn-sm btn-outline-warning" style="margin-left:4px"'
        .   ' title="Bulk-apply the correct qualification code to all enrolment rows that are still missing one">'
        . 'Repair codes →</a>'
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
    echo '<button id="bulk-send-btn" type="button" class="btn btn-sm btn-primary mr-3" disabled title="Send the suitability checklist to all selected learners for the chosen training product">'
        . get_string('suitability_bulk_send_selected', 'local_rtocompliance') . '</button>';
}

echo html_writer::link(
    $fillgapsurl,
    get_string('suitability_fill_gaps_btn_short', 'local_rtocompliance'),
    ['class' => 'btn btn-sm btn-warning', 'title' => 'Send suitability checklists to learners who have not yet received one']
);

// BULK-UNSUSPEND (v5.2.72): Show "Activate Selected" button when viewing suspended accounts.
if ($filter === 'suspended') {
    echo ' <button id="bulk-unsuspend-btn" type="button" class="btn btn-sm btn-success ml-2" disabled title="Re-activate the selected suspended Moodle accounts">'
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
    html_writer::tag('span', get_string('email'), ['title' => 'Email address on the learner Moodle account']),
    html_writer::tag('span', get_string('usi', 'local_rtocompliance'), ['title' => 'Unique Student Identifier and its verification status against usi.gov.au']),
    html_writer::tag('span', get_string('residentialstate', 'local_rtocompliance'), ['title' => 'State or territory recorded as the learner residential address']),
    html_writer::tag('span', get_string('profilestatus', 'local_rtocompliance'), ['title' => 'Whether the AVETMISS profile is complete, incomplete or missing']),
    html_writer::tag('span', get_string('suitability_col', 'local_rtocompliance'), ['title' => 'Pre-enrolment suitability check and its outcome']),
    html_writer::tag('span', get_string('actions'), ['title' => 'Per-row actions such as edit, enrolments, results and certificates']),
];
$table->attributes['class'] = 'generaltable';

foreach ($students as $student) {
    // Display as "Surname, Firstname" so the list reads surname-first
    // and sorts naturally on the already-applied ORDER BY u.lastname.
    $fullname = s(trim($student->lastname)) . ', ' . s(trim($student->firstname));

    // Pre-build URLs here so the USI cell (DOB-missing branch) can use $editurl.
    $editurl_pre  = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->id]);

    // WORLD-CLASS-PROFILE: the student name is now a one-click link straight to the
    // full student profile page. Keeps the "Surname, Firstname" text; any future
    // badges can be appended to $namecell after the link.
    $namecell = html_writer::link(
        $editurl_pre,
        $fullname,
        ['class' => 'rtoc-student-name-link', 'title' => get_string('studentprofile', 'local_rtocompliance')]
    );

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
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-verified" title="This USI has been checked and confirmed against the national USI registry at usi.gov.au. Nothing more to do.">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M5.5 8l1.8 1.8 3.2-3.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . ' Verified via <strong>usi.gov.au</strong>'
                . ($vdate ? '<span class="rtoc-usi-date"> &mdash; ' . $vdate . '</span>' : '')
                . '</span>';
        } else if ($vstat === 2) {
            // Verification failed — red badge + retry
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-failed" title="The USI could not be confirmed against usi.gov.au. Check the number and the learner name and date of birth, then retry.">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                . ' Verification failed'
                . '</span>'
                . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '" title="Retry USI verification against usi.gov.au for this learner">Retry &#x21BB;</button>' : '');
        } else if ($vstat === 3) {
            // Pending
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-pending" title="The USI check has been sent to usi.gov.au and is waiting for a result. No action needed right now.">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                . ' Verification pending'
                . '</span>';
        } else if ($vstat === 4) {
            // Manual review needed
            $usicell .= '<span class="rtoc-usi-badge rtoc-usi-review" title="The automatic USI check was inconclusive and needs a person to look at it before it can be confirmed.">'
                . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2l1.3 2.6 2.9.4-2.1 2.1.5 2.9L8 8.6 5.4 10l.5-2.9L3.8 5l2.9-.4L8 2z" stroke="currentColor" stroke-width="1.2"/></svg>'
                . ' Needs manual review'
                . '</span>'
                . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '" title="Run USI verification against usi.gov.au for this learner">Verify</button>' : '');
        } else {
            // Not yet verified (status=0).
            // DOB-MISSING-USI-FIX (v5.2.88): if DOB is absent, USI verification will
            // silently skip this student. Show a specific warning + "Add DOB" link
            // instead of the misleading "Verify" button that does nothing.
            $hasdob = !empty($student->dateofbirth) && (int)$student->dateofbirth !== 0;
            if (!$hasdob) {
                $usicell .= '<span class="rtoc-usi-badge rtoc-usi-nodob" title="A date of birth is needed before this USI can be checked against usi.gov.au. Add one to the learner profile.">'
                    . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3.5M8 10h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                    . ' DOB required to verify'
                    . '</span>';
            } else {
                // DOB present — normal unverified state with Verify button.
                $usicell .= '<span class="rtoc-usi-badge rtoc-usi-unverified" title="This USI has not been checked against usi.gov.au yet. Run a check to confirm it before issuing a certificate.">'
                    . '<svg class="rtoc-usi-icon-svg" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L2 3.5V8c0 3.3 2.5 5.8 6 6.9 3.5-1.1 6-3.6 6-6.9V3.5L8 1z" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M8 6v3M8 10.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
                    . ' Not yet verified'
                    . '</span>'
                    . ($student->profileid ? ' <button type="button" class="rtoc-usi-verify-btn" data-profileid="' . $student->profileid . '" title="Run USI verification against usi.gov.au for this learner">Verify via usi.gov.au &#x2192;</button>' : '');
            }
        }
    } else {
        $usicell = '<span class="rtoc-usi-missing" title="No USI on file for this learner. Collect their USI number before issuing any certificate.">'
            . '<svg style="width:11px;height:11px;vertical-align:middle;margin-right:3px" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2C4.7 2 2 4.7 2 8s2.7 6 6 6 6-2.7 6-6-2.7-6-6-6zm0 3v3.5M8 11h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            . 'Missing'
            . '</span>';
    }

    // USI-CELL-STACK (v6.2.37): stack the USI code, status badge and verify button
    // vertically so the pill + button no longer sit side-by-side and force the column wide.
    // Layout is handled by the .rtoc-usi-cell CSS (flex column).
    $usicell = '<div class="rtoc-usi-cell">' . $usicell . '</div>';

    $statename = '';
    if (!empty($student->statecode)) {
        $statename = $states[$student->statecode] ?? $student->statecode;
    }

    $statuscell = '';
    if (!empty($student->suspended)) {
        $statuscell = '<span class="badge badge-danger" title="This account is suspended in Moodle">SUSPENDED</span>';
    } else if ($student->profileid && $student->profilecomplete) {
        $statuscell = '<span class="badge badge-success" title="This learner profile has every mandatory national reporting field filled in and is ready to submit.">' . get_string('complete', 'local_rtocompliance') . '</span>';
    } else if ($student->profileid) {
        $statuscell = '<span class="badge badge-warning" title="Some mandatory national reporting fields are still missing. Open the profile to finish it.">' . get_string('incomplete', 'local_rtocompliance') . '</span>';
    } else {
        $statuscell = '<span class="badge badge-secondary" title="No national reporting (AVETMISS) profile has been created for this learner yet.">' . get_string('noprofile', 'local_rtocompliance') . '</span>';
    }

    // NOTE: $editurl/$enrolurl are also used in the USI cell above (DOB-missing link), so they
    // must be defined before the USI cell is rendered. Keep this definition in place — both
    // the USI cell code above and the Actions cell below reference these variables.
    $editurl  = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $student->id]);
    $enrolurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $student->id]);
    // WORLD-CLASS-PROFILE: quick jumps to this student's certificates and their
    // results/enrolments, surfaced directly in the row Actions menu.
    $certsurl   = new moodle_url('/local/rtocompliance/mycerts.php', ['userid' => $student->id]);
    $resultsurl = new moodle_url('/local/rtocompliance/student_enrolments.php', ['userid' => $student->id]);

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
    $actions  = '<button class="btn btn-sm btn-outline-secondary rtoc-act-btn" type="button" title="Open the actions menu for this learner (edit profile, enrolments, results, certificates)"'
        . ' data-edit-url="'  . htmlspecialchars($editurl->out(false),  ENT_QUOTES) . '"'
        . ' data-enrol-url="' . htmlspecialchars($enrolurl->out(false), ENT_QUOTES) . '"'
        . ' data-edit-label="'  . htmlspecialchars(get_string('editprofile', 'local_rtocompliance'), ENT_QUOTES) . '"'
        . ' data-enrol-label="' . htmlspecialchars(get_string('enrolments',  'local_rtocompliance'), ENT_QUOTES) . '"'
        . ' data-certs-url="'    . htmlspecialchars($certsurl->out(false),   ENT_QUOTES) . '"'
        . ' data-certs-label="Certificates"'
        . ' data-results-url="'  . htmlspecialchars($resultsurl->out(false), ENT_QUOTES) . '"'
        . ' data-results-label="Results / Enrolments">'
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
        $suitcell = html_writer::link($sendurl, get_string('suitability_send_btn_short', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Send the pre-enrolment suitability checklist to this learner']);
    } else {
        $viewurl  = new moodle_url('/local/rtocompliance/suitability_view.php', ['id' => $student->suitabilityid]);
        $sendurl  = new moodle_url('/local/rtocompliance/suitability_send.php', ['userid' => $student->id]);
        $ststatus = $student->suitabilitystatus;

        if ($ststatus === 'pending') {
            $suitcell  = '<span class="badge badge-info" title="The suitability checklist has been sent and is waiting for the learner to complete it.">' . get_string('suitability_status_pending', 'local_rtocompliance') . '</span><br>';
            $resendurl = new moodle_url('/local/rtocompliance/suitability_send.php', [
                'userid'   => $student->id,
                'resendid' => $student->suitabilityid,
                'sesskey'  => sesskey(),
            ]);
            $suitcell .= html_writer::link($resendurl, get_string('suitability_resend', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary mt-1', 'title' => 'Resend the suitability checklist to this learner']);
        } else if ($ststatus === 'suitable') {
            $cbDisabled = true;
            $suitcell   = '<span class="badge badge-success" title="The learner completed the pre-enrolment check and was found suitable for the course.">' . get_string('suitability_status_suitable', 'local_rtocompliance') . '</span><br>';
            $suitcell  .= html_writer::link($viewurl, get_string('view'), ['class' => 'btn btn-sm btn-outline-success mt-1', 'title' => 'View this completed suitability check']);
        } else if ($ststatus === 'not_suitable') {
            $suitcell  = '<span class="badge badge-danger" title="The pre-enrolment check flagged the learner as not suitable. Review the result and override it if appropriate.">' . get_string('suitability_status_not_suitable', 'local_rtocompliance') . '</span><br>';
            $suitcell .= html_writer::link($viewurl, get_string('suitability_view_override', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-danger mt-1', 'title' => 'View the not-suitable result and override it if needed']);
        } else if ($ststatus === 'override_suitable') {
            $cbDisabled = true;
            $suitcell   = '<span class="badge badge-warning" title="A staff member manually marked this learner suitable, overriding the checklist result.">' . get_string('suitability_status_override', 'local_rtocompliance') . '</span><br>';
            $suitcell  .= html_writer::link($viewurl, get_string('view'), ['class' => 'btn btn-sm btn-outline-warning mt-1', 'title' => 'View this overridden suitability check']);
        } else {
            $suitcell = html_writer::link($sendurl, get_string('suitability_send_btn_short', 'local_rtocompliance'), ['class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Send the pre-enrolment suitability checklist to this learner']);
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
        $namecell,
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
        var editUrl    = btn.getAttribute("data-edit-url");
        var enrolUrl   = btn.getAttribute("data-enrol-url");
        var certsUrl   = btn.getAttribute("data-certs-url");
        var resultsUrl = btn.getAttribute("data-results-url");
        var editLabel  = btn.getAttribute("data-edit-label")    || "Edit Profile";
        var enrolLabel = btn.getAttribute("data-enrol-label")   || "Enrolments";
        var certsLabel = btn.getAttribute("data-certs-label")   || "Certificates";
        var resultsLabel = btn.getAttribute("data-results-label") || "Results / Enrolments";

        var menu = document.createElement("div");
        menu.className = "dropdown-menu show rtoc-body-menu";
        menu.style.cssText = "position:absolute;z-index:100001;min-width:10rem;";

        function rtocAddItem(url, label) {
            if (!url) return;
            var a = document.createElement("a");
            a.className = "dropdown-item";
            a.href = url;
            a.textContent = label;
            menu.appendChild(a);
        }

        rtocAddItem(editUrl, editLabel);
        rtocAddItem(enrolUrl, enrolLabel);
        rtocAddItem(certsUrl, certsLabel);
        rtocAddItem(resultsUrl, resultsLabel);

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
