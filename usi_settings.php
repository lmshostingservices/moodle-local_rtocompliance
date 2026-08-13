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
 * RTO Compliance plugin — usi_settings.php.
 *
 * CREDENTIAL-BROKER (v5.9.452, doc corrected v6.2.46): the myID Machine Credential
 * (.p12/.pfx keystore) and its passphrase are stored ONLY on the lms-labs.com platform and
 * are NEVER written to this Moodle site's database or disk. This page shows the live
 * verification status pulled from the platform, and — via the self-service upload form
 * (v6.2.18) — lets an admin send a new credential to the platform: the keystore/passphrase
 * are read into memory, forwarded to the platform in the request, and immediately wiped;
 * they pass THROUGH Moodle in memory only, never persisted. The plugin verifies USIs by
 * calling the platform (POST /api/usi/verify), which signs each lookup against this site's
 * TOID.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php'); // Ensure the \curl class is loaded for the status call.
require_once($CFG->dirroot . '/local/rtocompliance/lib.php');

// This page is registered as an admin external page (local_rtocompliance_usi_pertenant) in
// settings.php, so it MUST be set up with admin_externalpage_setup() — that is what lets the admin
// navigation / left menu resolve it and provides login, capability, context and the admin layout in
// one call. Doing manual require_login()/set_pagelayout('admin') here instead caused the page to
// error when opened from the admin tree.
admin_externalpage_setup('local_rtocompliance_usi_pertenant');
$systemcontext = context_system::instance();

require_once(__DIR__ . '/classes/usi/usi_verification_service.php');

// ── USI STUDENT DASHBOARD (v6.2.17) ──────────────────────────────────────────
// A dedicated, filterable/searchable/paginated USI student table lives on this
// page (below the credential status panel) so an admin can see verification
// coverage at a glance, re-verify everyone, and export the students who still
// need a date of birth — all without leaving the USI Verification page.
$usifilter = optional_param('usifilter', 'all', PARAM_ALPHA);
$usisearch = trim((string) optional_param('usisearch', '', PARAM_TEXT));
$usipage   = optional_param('usipage', 0, PARAM_INT);
$usiaction = optional_param('usiaction', '', PARAM_ALPHA);
$usiexport = optional_param('usiexport', '', PARAM_ALPHA);
// USI-CAT-COURSE-FILTER (v6.2.32): scope the USI view to a Moodle course category
// (including its subcategories) and/or a specific course, via enrolments.
$usicat    = optional_param('usicat', 0, PARAM_INT);
$usicourse = optional_param('usicourse', 0, PARAM_INT);
$usiperpage = 50;

$usisvc = new \local_rtocompliance\usi\usi_verification_service();

// Shared WHERE builder — the table view and every CSV export run the SAME
// filter/search logic so an export always matches exactly what is on screen.
$usi_build_where = function (string $filter, string $search, int $catid = 0, int $courseid = 0) use ($DB) {
    // Only rows that have a linked student profile (this is the USI page).
    $where  = "u.deleted = 0";
    $params = [];

    switch ($filter) {
        case 'verified':
            $where .= " AND s.usiverified = 1";
            break;
        case 'unverified':
            // USI present but not yet verified (never attempted = 0, transient/pending = 3).
            $where .= " AND s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified IN (0, 3)";
            break;
        case 'failed':
            $where .= " AND s.usiverified = 2";
            break;
        case 'review':
            $where .= " AND s.usiverified = 4";
            break;
        case 'missingdob':
            $where .= " AND s.usi IS NOT NULL AND s.usi <> '' AND (s.dateofbirth IS NULL OR s.dateofbirth = 0)";
            break;
        case 'nousi':
            $where .= " AND (s.usi IS NULL OR s.usi = '')";
            break;
        case 'withusi':
            $where .= " AND s.usi IS NOT NULL AND s.usi <> ''";
            break;
        case 'all':
        default:
            // no extra clause — every student profile.
            break;
    }

    // USI-CAT-COURSE-FILTER (v6.2.32): scope by Moodle enrolment. A specific course wins;
    // otherwise a category filters to that category AND all its descendant subcategories
    // (matched on the category path). Students are "in" a course when they hold a
    // user_enrolment on any enrol instance of that course.
    if ($courseid > 0) {
        $where .= " AND u.id IN (
            SELECT ue.userid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE e.courseid = :ficourse)";
        $params['ficourse'] = $courseid;
    } else if ($catid > 0) {
        $cat = $DB->get_record('course_categories', ['id' => $catid], 'id, path');
        if ($cat) {
            $where .= " AND u.id IN (
                SELECT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE cc.id = :ficat OR " . $DB->sql_like('cc.path', ':ficatpath') . ")";
            $params['ficat'] = $catid;
            $params['ficatpath'] = $cat->path . '/%';
        }
    }

    if ($search !== '') {
        $fullfwd = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $like  = $DB->sql_like('u.firstname', ':us1', false, false);
        $like .= ' OR ' . $DB->sql_like('u.lastname',  ':us2', false, false);
        $like .= ' OR ' . $DB->sql_like('u.email',     ':us3', false, false);
        $like .= ' OR ' . $DB->sql_like('s.usi',       ':us4', false, false);
        $like .= ' OR ' . $DB->sql_like('s.clientid',  ':us5', false, false);
        $like .= ' OR ' . $DB->sql_like($fullfwd,      ':us6', false, false);
        $where .= " AND ($like)";
        $params['us1'] = '%' . $search . '%';
        $params['us2'] = '%' . $search . '%';
        $params['us3'] = '%' . $search . '%';
        $params['us4'] = '%' . $search . '%';
        $params['us5'] = '%' . $search . '%';
        $params['us6'] = '%' . $search . '%';
    }

    return [$where, $params];
};

// Human-readable, ASQA/AVETMISS-aligned label for each verification status code.
$usi_status_label = function ($code, $usi) {
    if ($usi === null || trim((string) $usi) === '') {
        return 'No USI recorded';
    }
    switch ((int) $code) {
        case \local_rtocompliance\usi\usi_verification_service::STATUS_VERIFIED:
            return 'Verified (usi.gov.au)';
        case \local_rtocompliance\usi\usi_verification_service::STATUS_FAILED:
            return 'Verification failed';
        case \local_rtocompliance\usi\usi_verification_service::STATUS_PENDING:
            return 'Pending / not yet verified';
        case \local_rtocompliance\usi\usi_verification_service::STATUS_MANUAL_REVIEW:
            return 'Manual review required';
        case \local_rtocompliance\usi\usi_verification_service::STATUS_UNVERIFIED:
        default:
            return 'Not yet verified';
    }
};

// ── ACTION: live connection test (v6.2.47) — verify a dummy record to check the platform ↔
// usi.gov.au link. A dummy USI that reaches the registry returns a "no match" (NOT an error) —
// that proves the link works. Auth/config/STS errors (e.g. E2003) mean it does NOT. Lets the
// admin instantly see whether verification is actually working, separate from the queue. ─
// CONNTEST-INLINE (v6.2.49): verify_usi() calls session write_close() during its API call, so a
// redirect() afterwards cannot persist its notification (the session is closed) — the result was
// silently lost ("no results"). Instead we run the test here, capture the outcome, and RENDER IT
// INLINE further down (after the header), which always shows. try/catch guards any exception.
$conntestmsg = '';
$conntestok = false;
if ($usiaction === 'conntest' && confirm_sesskey()) {
    require_capability('moodle/site:config', $systemcontext);
    require_once(__DIR__ . '/classes/usi/usi_platform_client.php');
    try {
        $client = new \local_rtocompliance\usi\usi_platform_client([]);
        $res = $client->verify_usi('AAAAAAAAAA', 'Connection', 'Test', '2000-01-01');
    } catch (\Throwable $e) {
        $res = ['status' => 'ERROR', 'message' => $e->getMessage()];
    }
    $status = strtoupper(trim((string) ($res['status'] ?? '')));
    $tmsg = (string) ($res['message'] ?? '');
    // Broken = the call never reached usi.gov.au (auth / config / network / STS/E2003 fault).
    $brokenstatuses = ['AUTH_ERROR', 'NOT_CONFIGURED', 'NETWORK_ERROR', 'PLATFORM_ERROR', 'CERT_PENDING', 'ERROR', 'INVALID_INPUT'];
    $conntestok = ($status !== '') && !in_array($status, $brokenstatuses, true)
        && stripos($tmsg, 'E2003') === false && stripos($tmsg, 'HTTP 5') === false && stripos($tmsg, '502') === false;
    if ($conntestok) {
        $conntestmsg = 'USI connection test PASSED — the platform reached the USI registry and returned status "'
            . s($status) . '" for the dummy record (an expected "no match"). Verification is working — '
            . 'use "Re-verify all students" to process your backlog.';
    } else {
        // USI-DIAG (v6.2.57): decode the underlying cause so the admin sees the ACTUAL reason,
        // not just a raw SOAP envelope. The most common failure now is the ATO Machine-to-Machine
        // (M2M) security-token step (MAS-ST / softwareauthorisations.ato.gov.au STS) returning
        // HTTP 400/401 — which means the credential the platform holds for this TOID is not an
        // ACCEPTED ATO M2M credential yet, or the ATO-side software authorisation is not granted.
        $lc = strtolower($tmsg);
        $isatosts = (strpos($lc, 'mas-st') !== false || strpos($lc, 'softwareauthorisations.ato.gov.au') !== false
            || strpos($lc, 'sts:') !== false || $status === 'ATO_ERROR');
        // Pull a human fault reason out of the SOAP envelope if one is present.
        $faultreason = '';
        if (preg_match('#<(?:soap:)?(?:Reason|faultstring)[^>]*>(?:\s*<(?:soap:)?Text[^>]*>)?(.*?)</#is', $tmsg, $m)) {
            $faultreason = trim(html_entity_decode(strip_tags($m[1])));
        }
        $detail = ($faultreason !== '') ? $faultreason : substr($tmsg, 0, 300);

        // E2003-DIAG (v6.2.61): "E2003 / relying party in AppliesTo not recognised" is a DISTINCT
        // failure from a credential/authorisation problem. It means the platform requested the ATO
        // token for a relying-party (AppliesTo) URL the ATO does not recognise — i.e. the WRONG USI
        // service URL, or the wrong environment (EVTE vs production). This is a PLATFORM config fix
        // (lms-labs.com), not the keystore or the Access Manager authorisation.
        $ise2003 = (stripos($tmsg, 'E2003') !== false || stripos($lc, 'relying party') !== false
                    || stripos($lc, 'appliesto') !== false);
        if ($ise2003) {
            // E2003-DIAG (v6.2.65): the lms-labs.com platform logs CONFIRM it is sending the
            // correct production relying party (https://portal.usi.gov.au/Service/v5/UsiService.svc)
            // and the production STS for TOID 30772. With the correct URL confirmed, E2003 "relying
            // party not recognised" almost always means the ATO has NOT AUTHORISED this software
            // credential for the USI Registry service. That is fixed in ATO Access Manager, on the
            // RTO's side — NOT in Moodle, and re-uploading the credential will not change it.
            $conntestmsg = 'USI connection test FAILED with ATO error E2003 — "the relying party specified in the '
                . 'AppliesTo element is not recognised". The platform is confirmed to be sending the CORRECT '
                . 'production USI URL (https://portal.usi.gov.au/Service/v5/UsiService.svc), so this is almost '
                . 'certainly an ATO AUTHORISATION issue on your side, not a Moodle or platform fault, and '
                . 're-uploading the credential will not change it. THE FIX (about 5 minutes, in ATO Access Manager): '
                . 'sign in at https://am.ato.gov.au with the myGovID used to create your machine credential, open '
                . 'the software/credential for your ABN (TOID 30772), and make sure the "USI Registry" service is in '
                . 'its list of authorised services — if it is missing, add it (Add / nominate service → USI Registry '
                . '→ Save) and allow up to 24 hours to take effect, then run this test again. If "USI Registry" is '
                . 'already listed, send lms-labs.com a screenshot of that screen plus this error and they will read '
                . 'the ATO fault from the platform logs. (Rare alternative cause: a production credential being sent '
                . 'to the EVTE environment — confirm TOID 30772 is production end-to-end.) No student data was changed.';
        } else if ($isatosts) {
            $conntestmsg = 'USI connection test FAILED at the ATO security-token step (status "' . s($status ?: 'ATO_ERROR')
                . '"). The platform reached the ATO Machine-to-Machine (M2M) authentication service '
                . '(softwareauthorisations.ato.gov.au) but it REJECTED the request'
                . ($detail !== '' ? ' — ATO said: "' . s($detail) . '"' : '') . '. '
                . 'This is almost always an ATO credential/authorisation problem, NOT a Moodle or template fault, '
                . 'and re-uploading the same keystore will not change it. Check, in order: (1) the credential held '
                . 'for TOID 30772 on lms-labs.com is a current ATO M2M "machine credential" (from myGovID / RAM) that '
                . 'has NOT expired; (2) in ATO Access Manager the ABN that owns that credential has been granted the '
                . 'USI Registry software authorisation (the "software provider" relationship) — a keystore alone is '
                . 'not enough without this; (3) the credential\'s keystore password on the platform matches the file. '
                . 'No student data was changed.';
        } else {
            $conntestmsg = 'USI connection test FAILED — status "' . s($status ?: 'no response') . '"'
                . ($detail !== '' ? (': ' . s($detail)) : '')
                . '. This is a platform / credential / setup issue on lms-labs.com (most commonly: no credential '
                . 'uploaded yet for your TOID, or the Site ID / API key is not set in Central Config) — not a Moodle '
                . 'code fault. No student data was changed.';
        }
    }
}

// ── ACTION: re-verify everyone (reset pending → unverified so cron re-attempts) ─
if ($usiaction === 'reverifyall' && confirm_sesskey()) {
    require_capability('moodle/site:config', $systemcontext);
    $resetcount = $usisvc->reset_stuck_pending();
    // Also nudge any STATUS_FAILED (2) back to unverified so a full re-sweep
    // re-attempts them too — the admin explicitly asked to re-verify ALL.
    $failedreset = $DB->execute(
        "UPDATE {local_rtocompliance_students}
            SET usiverified = 0, timemodified = :now
          WHERE usiverified = 2
            AND usi IS NOT NULL AND usi <> ''
            AND dateofbirth IS NOT NULL AND dateofbirth <> 0",
        ['now' => time()]
    );
    $failedcount = (int) $DB->count_records_select(
        'local_rtocompliance_students',
        "usiverified = 0 AND usi IS NOT NULL AND usi <> '' AND dateofbirth IS NOT NULL AND dateofbirth <> 0"
    );
    redirect(
        new moodle_url('/local/rtocompliance/usi_settings.php'),
        'All eligible students queued for re-verification — the scheduled USI task re-attempts them (25 per run). '
        . 'Students with a USI and date of birth now awaiting verification: ' . $failedcount . '.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── EXPORT: CSV of the current filtered view, OR of students missing a DOB ─────
if ($usiexport === 'csv' || $usiexport === 'nodob') {
    require_capability('moodle/site:config', $systemcontext);

    if ($usiexport === 'nodob') {
        list($ewhere, $eparams) = $usi_build_where('missingdob', $usisearch, $usicat, $usicourse);
        $fnamebit = 'usi-missing-dob';
    } else {
        list($ewhere, $eparams) = $usi_build_where($usifilter, $usisearch, $usicat, $usicourse);
        $fnamebit = 'usi-' . $usifilter;
    }

    $esql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
                    s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE $ewhere
              ORDER BY u.lastname ASC, u.firstname ASC";
    $rows = $DB->get_recordset_sql($esql, $eparams);

    $filename = 'italc-' . $fnamebit . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 names correctly.
    fprintf($out, "\xEF\xBB\xBF");
    if ($usiexport === 'nodob') {
        fputcsv($out, ['Family name', 'Given name', 'Email', 'Client identifier', 'USI', 'Date of birth (missing)']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r->lastname, $r->firstname, $r->email,
                (string) $r->clientid, (string) $r->usi, '',
            ]);
        }
    } else {
        fputcsv($out, ['Family name', 'Given name', 'Email', 'Client identifier', 'USI',
                       'Date of birth', 'USI verification status', 'Verified date']);
        foreach ($rows as $r) {
            $dob = (!empty($r->dateofbirth) && (int) $r->dateofbirth > 0)
                ? userdate((int) $r->dateofbirth, '%d/%m/%Y') : '';
            $vdate = (!empty($r->usiverifieddate) && (int) $r->usiverifieddate > 0)
                ? userdate((int) $r->usiverifieddate, '%d/%m/%Y') : '';
            fputcsv($out, [
                $r->lastname, $r->firstname, $r->email,
                (string) $r->clientid, (string) $r->usi, $dob,
                $usi_status_label($r->usiverified, $r->usi), $vdate,
            ]);
        }
    }
    $rows->close();
    fclose($out);
    exit;
}

// ── UPLOAD: CSV of dates of birth to backfill missing DOBs (v6.2.17) ──────────
// Designed to round-trip with the "Export students without DOB" download: fill in
// the Date of birth column and re-upload. Matches each row to a student by Client
// identifier first, then USI, then email. Only writes where DOB is currently blank.
// Accepts DD/MM/YYYY, YYYY-MM-DD, DD-MM-YYYY and DDMMYYYY date formats.
if ($usiaction === 'uploaddob' && confirm_sesskey()) {
    require_capability('moodle/site:config', $systemcontext);

    $redirurl = new moodle_url('/local/rtocompliance/usi_settings.php', ['usifilter' => 'missingdob']);
    $upload = $_FILES['dobcsv'] ?? null;
    if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
        redirect($redirurl, 'No CSV file was received. Please choose a file and try again.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if ((int) ($upload['size'] ?? 0) > 10 * 1024 * 1024) {
        redirect($redirurl, 'File too large (max 10 MB).', null, \core\output\notification::NOTIFY_ERROR);
    }

    // Parse a DOB string in the accepted formats → Unix timestamp (or 0 if invalid).
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
        return ($ts === false || $ts <= 0) ? 0 : (int) $ts;
    };

    $handle = @fopen($upload['tmp_name'], 'r');
    if ($handle === false) {
        redirect($redirurl, 'Could not read the uploaded file.', null, \core\output\notification::NOTIFY_ERROR);
    }

    // Read header row and locate the columns we care about (case-insensitive).
    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        redirect($redirurl, 'The CSV appears to be empty.', null, \core\output\notification::NOTIFY_ERROR);
    }
    // Strip a UTF-8 BOM from the first header cell if present.
    if (isset($header[0])) { $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); }
    $idx = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim((string) $col));
        $idx[$key] = $i;
    }
    $col_client = $idx['client identifier'] ?? $idx['clientid'] ?? $idx['client id'] ?? null;
    $col_usi    = $idx['usi'] ?? null;
    $col_email  = $idx['email'] ?? null;
    $col_dob    = $idx['date of birth'] ?? $idx['dob'] ?? $idx['dateofbirth'] ?? $idx['date of birth (missing)'] ?? null;

    if ($col_dob === null || ($col_client === null && $col_usi === null && $col_email === null)) {
        fclose($handle);
        redirect($redirurl,
            'CSV must have a "Date of birth" column and at least one of "Client identifier", "USI" or "Email". '
            . 'Tip: use the "Export students without DOB" download, fill in the Date of birth column, then re-upload.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $updated = 0; $skipped = 0; $nomatch = 0; $baddate = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) { continue; } // blank line
        $dobstr = $col_dob !== null ? ($row[$col_dob] ?? '') : '';
        $ts = $parsedob($dobstr);
        if ($ts <= 0) { $baddate++; continue; }

        // Locate the student — clientid, then USI, then email.
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
        if (!empty($stud->dateofbirth) && (int) $stud->dateofbirth > 0) { $skipped++; continue; } // already set

        $DB->update_record('local_rtocompliance_students',
            (object) ['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()]);
        $updated++;
    }
    fclose($handle);

    $msg = "DOB upload complete: {$updated} updated.";
    $extra = [];
    if ($skipped > 0) { $extra[] = "{$skipped} already had a DOB"; }
    if ($nomatch > 0) { $extra[] = "{$nomatch} not matched to a student"; }
    if ($baddate > 0) { $extra[] = "{$baddate} had an unreadable date"; }
    if ($extra) { $msg .= ' (' . implode(', ', $extra) . ').'; }
    redirect($redirurl, $msg, null,
        $updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
}

// ── SELF-SERVICE CREDENTIAL UPLOAD (v6.2.18) ─────────────────────────────────
// Lets an RTO admin upload their own myGovID Machine Credential (.pfx/.p12)
// straight to the lms-labs.com platform — no phone call to support. The keystore
// bytes and password are read into memory, base64-encoded, POSTed to the platform
// via usi_platform_client::upload_cert(), then wiped. Nothing is written to this
// Moodle site's database or disk, preserving the "credentials never stored in
// Moodle" security posture while removing the manual-onboarding bottleneck.
if ($usiaction === 'uploadcert' && confirm_sesskey()) {
    require_capability('moodle/site:config', $systemcontext);
    require_once(__DIR__ . '/classes/usi/usi_platform_client.php');
    $redir = new moodle_url('/local/rtocompliance/usi_settings.php');

    $orgid = trim((string) optional_param('certorgid', '', PARAM_RAW));
    if ($orgid === '') {
        $orgid = (string) (get_config('local_rtocompliance', 'usi_organization_id')
            ?: get_config('local_rtocompliance', 'rtocode') ?: '');
    }
    $certpass    = (string) optional_param('certpass', '', PARAM_RAW);
    $certtestmode = (optional_param('certenv', 'prod', PARAM_ALPHA) === 'test');
    $notifemail  = trim((string) optional_param('certemail', '', PARAM_RAW));

    $upload = $_FILES['certfile'] ?? null;
    // ROBUST-UPLOAD (v6.2.33): decode $upload['error'] into a SPECIFIC message instead of
    // collapsing every cause into one guess — the old handler hid the real reason.
    $uerr = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if (!$upload || $uerr !== UPLOAD_ERR_OK) {
        $errmap = [
            UPLOAD_ERR_INI_SIZE   => 'The credential file exceeds this server\'s upload limit (upload_max_filesize). A myGovID keystore is only a few KB, so check you picked the right file.',
            UPLOAD_ERR_FORM_SIZE  => 'The credential file exceeds the form size limit.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted before it finished — please try again.',
            UPLOAD_ERR_NO_FILE    => 'No credential file was chosen. Select your .pfx/.p12 (or ABR keystore.xml) and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory configured — contact your host.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk — contact your host.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
        ];
        redirect($redir, $errmap[$uerr] ?? ('Credential upload failed (PHP upload error ' . $uerr . ').'),
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        redirect($redir, 'The uploaded credential could not be validated on the server. Please try again.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        redirect($redir, 'Credential file too large (max 5 MB) — a myGovID keystore is only a few KB, so please check the file.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($orgid === '') {
        redirect($redir, 'RTO code (TOID) is required. Set it on the RTO Settings page or enter it in the upload form.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    // Move the upload out of PHP's tmp dir before reading. file_get_contents() on the raw
    // tmp path fails under open_basedir on hardened hosts (returns false), which previously
    // surfaced as the misleading "empty/too small" message. move_uploaded_file() is
    // open_basedir-exempt, so read from a controlled request directory instead — and no '@',
    // so any genuine read problem is reported specifically.
    $bytes = false;
    $dest  = make_request_directory() . '/usi-cred.bin';
    if (move_uploaded_file($upload['tmp_name'], $dest)) {
        $bytes = file_get_contents($dest);
    }
    if ($bytes === false) {
        redirect($redir, 'The server could not read the uploaded credential file (check server file permissions / open_basedir). Nothing was sent to the platform.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    if (strlen($bytes) < 200) {
        redirect($redir, 'That file is empty or too small to be a keystore (' . strlen($bytes) . ' bytes). Upload your myGovID Machine Credential (.pfx/.p12) or ABR keystore.xml.',
            null, \core\output\notification::NOTIFY_ERROR);
    }
    $b64 = base64_encode($bytes);

    $client = new \local_rtocompliance\usi\usi_platform_client(['test_mode' => $certtestmode]);
    $res = $client->upload_cert($b64, $certpass, $orgid, $certtestmode, $notifemail);

    // Wipe sensitive material from memory as soon as the call returns — never persisted.
    $bytes = null; $b64 = null; $certpass = null;
    unset($_FILES['certfile']);

    if (!empty($res['ok'])) {
        // Persist the API key the platform issued for this site (fresh-created clients
        // get a new key). Without this, later verify calls keep an old/mismatched key
        // and 401. Only overwrite when the platform actually returned one.
        global $CFG;
        $keysaved = false;
        // USI-KEY-PERSIST-FIX (v6.2.59): the platform issues (or re-confirms) the API key + site
        // id on upload — on FIRST registration (fresh auto-generated key) and on any RE-UPLOAD
        // where the stored key had drifted (the cert proves ownership, so the platform returns
        // the correct key). Persist BOTH values to the plugin config AND to Central Config
        // (local_aiconfig) whenever it is installed, because the USI client reads local_aiconfig
        // FIRST. Saving only to the plugin config (the old behaviour) left the OLD/mismatched key
        // in effect, so every later /api/usi/verify 401'd — the exact "uploaded many times but
        // nothing verifies" loop. This is THE fix for that loop.
        $returnedapikey = (!empty($res['apikey']) && strlen((string) $res['apikey']) >= 16)
            ? (string) $res['apikey'] : null;
        $returnedsiteid = !empty($res['siteid']) ? (string) $res['siteid'] : null;
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        $hasaiconfig = file_exists($aiconfiglib);
        if ($returnedapikey !== null) {
            set_config('apikey', $returnedapikey, 'local_rtocompliance');
            if ($hasaiconfig) { set_config('apikey', $returnedapikey, 'local_aiconfig'); }
            $keysaved = true;
        }
        if ($returnedsiteid !== null) {
            set_config('siteid', $returnedsiteid, 'local_rtocompliance');
            if ($hasaiconfig) { set_config('siteid', $returnedsiteid, 'local_aiconfig'); }
            $keysaved = true;
        }
        $detail = '';
        if (!empty($res['certBytes'])) { $detail .= ' (' . (int) $res['certBytes'] . ' bytes'; }
        if (!empty($res['certExpiry'])) { $detail .= ($detail ? ', expires ' : ' (expires ') . s($res['certExpiry']); }
        if ($detail && $detail[0] === ' ' && strpos($detail, '(') !== false) { $detail .= ')'; }
        redirect($redir,
            'USI credential uploaded to the platform for TOID ' . s($orgid) . ' ('
            . ($certtestmode ? 'test/EVTE' : 'production') . ' mode).' . $detail
            . ($keysaved ? ' Your platform API key was updated automatically.' : '')
            . ' Verification can now run — use "Re-verify all students" below.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect($redir,
            'Credential upload failed: ' . s($res['error'] ?? $res['message'] ?? 'unknown error')
            . '. Check the keystore password and that the file is a valid myGovID Machine Credential (ABR keystore .xml, or .pfx/.p12).',
            null, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->set_title(get_string('usi_pertenant_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('usi_pertenant_title', 'local_rtocompliance'));

$apiurl = trim((string) (get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com'));
$siteid = trim((string) get_config('local_rtocompliance', 'siteid'));
$apikey = trim((string) get_config('local_rtocompliance', 'apikey'));

$apiconfigured = ($apiurl !== '' && $siteid !== '' && $apikey !== '');

// Fetch current platform-side status (read-only). Never let a platform/network problem take the
// whole page down — any failure leaves $status null and the page shows a graceful "status
// unavailable" message instead of erroring.
$status = null;
if ($apiconfigured) {
    try {
        // Use Moodle's \curl wrapper so site proxy settings are respected.
        \core\session\manager::write_close();
        $mcurl = new \curl();
        $mcurl->setopt(['CURLOPT_TIMEOUT' => 12, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2]);
        $mcurl->setHeader(['X-Site-Id: ' . $siteid, 'X-Api-Key: ' . $apikey]);
        $resp = $mcurl->get(rtrim($apiurl, '/') . '/api/usi/status');
        $code = isset($mcurl->info['http_code']) ? (int) $mcurl->info['http_code'] : 0;
        if ($code === 200 && $resp) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                $status = $decoded;
            }
        }
    } catch (\Throwable $e) {
        $status = null;
    }
}

$PAGE->add_body_class('path-local-rtocompliance'); // Scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('usi_pertenant_title', 'local_rtocompliance'), null, null, 'usi');

// Inline result of the "Run connection test" action. Rendered here (not via redirect) because
// verify_usi() calls \core\session\manager::write_close(), which closes the session for the rest
// of the request — a redirect()-carried notification would be silently lost.
if ($conntestmsg !== '') {
    echo $OUTPUT->notification($conntestmsg, $conntestok ? 'notifysuccess' : 'notifyproblem');
}

// Header banner — shared royal-blue gradient so it matches every other main page banner.
echo '<div class="support-header">';
echo '<h2 style="margin:0;color:#fff;font-size:22px;">' . get_string('usi_pertenant_title', 'local_rtocompliance') . '</h2>';
echo '<div style="opacity:.92;font-size:14px;margin-top:6px;color:#fff;">' . get_string('usi_pertenant_intro', 'local_rtocompliance') . '</div>';
echo '</div>';

// Where-managed note — credentials live only on the platform.
$adminpanelurl = rtrim($apiurl, '/') . '/admin';
echo '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:16px 20px;margin-bottom:20px;">';
echo '<div style="display:flex;align-items:flex-start;gap:12px;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
echo '<div style="flex:1;">';
echo '<div style="font-weight:600;color:#065f46;margin-bottom:4px;">Your credential is held securely on the platform — never on this Moodle site</div>';
echo '<div style="color:#065f46;font-size:13.5px;line-height:1.6;">Upload your myID Machine Credential keystore (.p12/.pfx) below and it is sent straight to the '
    . '<a href="' . s($adminpanelurl) . '" target="_blank" rel="noopener noreferrer" style="color:#047857;text-decoration:underline;font-weight:600;">lms-labs.com</a> platform, '
    . 'which signs each USI lookup against your TOID on your behalf. The keystore and its password are <strong>never written to this Moodle server</strong> — they pass through in memory only. '
    . 'You can also rotate or manage the credential later here or in the platform admin panel.</div>';
echo '</div></div></div>';

// Current status panel (read-only).
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 12px 0;font-size:16px;">' . get_string('usi_pertenant_currentstatus', 'local_rtocompliance') . '</h3>';
if ($status && !empty($status['ok'])) {
    $ready   = !empty($status['certReady']);
    $expired = !empty($status['expired']);
    $warn    = !empty($status['expiryWarn']) && !$expired;
    if ($expired) {
        $colour = '#ef4444';
        $label  = get_string('usi_pertenant_expired', 'local_rtocompliance');
    } else if (!$ready) {
        $colour = '#ef4444';
        $label  = get_string('usi_pertenant_notready', 'local_rtocompliance');
    } else {
        $colour = '#10b981';
        $label  = get_string('usi_pertenant_ready', 'local_rtocompliance');
    }
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">';
    echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $colour . ';"></span>';
    echo '<strong style="color:' . $colour . ';">' . $label . '</strong>';
    echo '</div>';

    if ($warn) {
        echo '<div class="alert alert-warning" style="margin-bottom:12px;">' . get_string('usi_pertenant_expirywarn', 'local_rtocompliance') . '</div>';
    }

    echo '<div style="font-size:13px;color:#374151;display:grid;grid-template-columns:200px 1fr;gap:6px 16px;">';
    echo '<div><b>' . get_string('usi_pertenant_source', 'local_rtocompliance') . ':</b></div><div>' . s($status['source'] ?? '—') . '</div>';
    echo '<div><b>' . get_string('usi_pertenant_orgid', 'local_rtocompliance') . ':</b></div><div>' . s((string)($status['orgId'] ?? '—')) . '</div>';
    echo '<div><b>' . get_string('usi_pertenant_mode', 'local_rtocompliance') . ':</b></div><div>' . (!empty($status['testMode']) ? 'EVTE (test sandbox)' : 'PRODUCTION (live USI Registry)') . '</div>';
    if (!empty($status['certSubject'])) {
        echo '<div><b>' . get_string('usi_pertenant_certsubject', 'local_rtocompliance') . ':</b></div><div>' . s($status['certSubject']) . '</div>';
    }
    if (!empty($status['certExpiry'])) {
        $expts = strtotime($status['certExpiry']);
        $expdate = $expts ? userdate($expts, '%d %b %Y') : s($status['certExpiry']);
        echo '<div><b>' . get_string('usi_pertenant_certexpiry', 'local_rtocompliance') . ':</b></div><div>' . s($expdate) . '</div>';
    }
    if (isset($status['daysToExpiry']) && $status['daysToExpiry'] !== null) {
        $days = (int)$status['daysToExpiry'];
        $dcol = $expired ? '#ef4444' : ($warn ? '#f59e0b' : '#10b981');
        echo '<div><b>' . get_string('usi_pertenant_daystoexpiry', 'local_rtocompliance') . ':</b></div><div style="color:' . $dcol . ';font-weight:600;">' . $days . '</div>';
    }
    if (!empty($status['notificationEmail'])) {
        echo '<div><b>' . get_string('usi_pertenant_notifemail', 'local_rtocompliance') . ':</b></div><div>' . s($status['notificationEmail']) . '</div>';
    }
    echo '</div>';

    if (!empty($status['message'])) {
        echo '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #f3f4f6;color:#6b7280;font-size:12px;">' . s($status['message']) . '</div>';
    }
} else if (!$apiconfigured) {
    echo '<div class="alert alert-warning" style="margin:0;">' . get_string('usi_pertenant_err_noapi', 'local_rtocompliance') . '</div>';
} else {
    echo '<div class="alert alert-warning" style="margin:0;">' . get_string('usi_pertenant_err_status', 'local_rtocompliance') . '</div>';
}
echo '</div>';

// ── SELF-SERVICE CREDENTIAL UPLOAD PANEL (v6.2.18) ───────────────────────────
$statusready = ($status && !empty($status['ok']) && !empty($status['certReady']) && empty($status['expired']));
$defaultorg = (string) (get_config('local_rtocompliance', 'usi_organization_id')
    ?: get_config('local_rtocompliance', 'rtocode') ?: '');
$defaultprod = ((string) get_config('local_rtocompliance', 'usi_test_mode') === '0');
$uploadposturl = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false);

echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<h3 style="margin:0 0 6px 0;font-size:16px;">' . ($statusready ? 'Rotate / re-upload your USI credential' : 'Upload your USI machine credential') . '</h3>';
echo '<p style="font-size:13px;color:#475569;margin:0 0 14px;line-height:1.55;">'
    . 'Upload your myGovID <strong>Machine Credential</strong> and its password. Both formats are accepted: the ABR keystore '
    . '<strong>.xml</strong> file you download from RAM/myGovID, or a <strong>.pfx / .p12</strong> keystore. It is forwarded directly to the secure platform and '
    . '<strong>never stored on this Moodle site</strong>. This registers your RTO so USI verification runs automatically — no need to contact support.</p>';

echo '<form method="post" action="' . s($uploadposturl) . '" enctype="multipart/form-data" '
    . 'onsubmit="return this.certfile.value ? confirm(\'Upload this credential to the USI platform for TOID \' + this.certorgid.value + \'?\') : true;">';
echo '<input type="hidden" name="usiaction" value="uploadcert">';
echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;align-items:start;">';

echo '<div><label style="display:block;font-weight:600;font-size:12.5px;margin-bottom:4px;">Machine Credential file (.xml, .pfx or .p12)</label>'
    . '<input type="file" name="certfile" accept=".xml,.pfx,.p12,application/x-pkcs12,text/xml,application/xml" required class="form-control-file" style="font-size:12.5px;"></div>';

echo '<div><label style="display:block;font-weight:600;font-size:12.5px;margin-bottom:4px;">Keystore password</label>'
    . '<input type="password" name="certpass" autocomplete="new-password" class="form-control" placeholder="Password for the keystore"></div>';

echo '<div><label style="display:block;font-weight:600;font-size:12.5px;margin-bottom:4px;">RTO code (TOID)</label>'
    . '<input type="text" name="certorgid" value="' . s($defaultorg) . '" class="form-control" placeholder="e.g. 30772"></div>';

echo '<div><label style="display:block;font-weight:600;font-size:12.5px;margin-bottom:4px;">Environment</label>'
    . '<select name="certenv" class="form-control">'
    . '<option value="prod"' . ($defaultprod ? ' selected' : '') . '>Production (live USI Registry)</option>'
    . '<option value="test"' . (!$defaultprod ? ' selected' : '') . '>Test / EVTE sandbox</option>'
    . '</select></div>';

echo '<div><label style="display:block;font-weight:600;font-size:12.5px;margin-bottom:4px;">Expiry reminder email (optional)</label>'
    . '<input type="email" name="certemail" class="form-control" placeholder="alerts@yourrto.edu.au"></div>';

echo '</div>'; // grid

echo '<div style="margin-top:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
echo '<button type="submit" class="btn btn-primary">'
    . '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:6px" viewBox="0 0 24 24" fill="none"><path d="M12 3v13M7 8l5-5 5 5M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    . 'Upload credential to platform</button>';
echo '<span style="font-size:12px;color:#64748b;">Sent over HTTPS to the platform; the file and password are wiped from memory immediately after and never saved here.</span>';
echo '</div>';
echo '</form>';
echo '</div>';

// ═══════════════════════════════════════════════════════════════════════════
// USI STUDENT DASHBOARD — stats, bulk actions, filter/search, table (v6.2.17)
// ═══════════════════════════════════════════════════════════════════════════
$stats = $usisvc->get_verification_stats();
$totwithusi = (int) ($stats['total_with_usi'] ?? 0);
$nverified  = (int) ($stats['verified'] ?? 0);
$nunverified = (int) ($stats['unverified'] ?? 0);
$nfailed    = (int) ($stats['failed'] ?? 0);
$nreview    = (int) ($stats['pending_review'] ?? 0);
$npending   = (int) ($stats['pending_retry'] ?? 0);
$nmissingusi = (int) ($stats['missing_usi'] ?? 0);
$nmissingdob = (int) $DB->count_records_select('local_rtocompliance_students',
    "usi IS NOT NULL AND usi <> '' AND (dateofbirth IS NULL OR dateofbirth = 0)");
$pctverified = $totwithusi > 0 ? round($nverified / $totwithusi * 100, 1) : 0.0;

// ── Verification coverage banner + progress bar ──────────────────────────────
$barcol = $pctverified >= 90 ? '#10b981' : ($pctverified >= 50 ? '#f59e0b' : '#ef4444');
echo '<div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px;">';
echo '<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;">';
echo '<h3 style="margin:0;font-size:16px;">USI verification coverage</h3>';
echo '<div style="font-size:28px;font-weight:700;color:' . $barcol . ';">' . $pctverified . '%<span style="font-size:13px;color:#6b7280;font-weight:500;"> verified</span></div>';
echo '</div>';
echo '<div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin:12px 0 6px;">';
echo '<div style="height:100%;width:' . max(0, min(100, $pctverified)) . '%;background:' . $barcol . ';border-radius:999px;transition:width .3s;"></div>';
echo '</div>';
echo '<div style="font-size:12.5px;color:#6b7280;">' . $nverified . ' of ' . $totwithusi . ' students with a USI have been verified against the Government USI Registry.</div>';
echo '</div>';

// ── Stat cards ───────────────────────────────────────────────────────────────
$cards = [
    ['Students with a USI', $totwithusi, '#0f172a', 'withusi'],
    ['Verified',            $nverified,  '#10b981', 'verified'],
    ['Not yet verified',    $nunverified, '#6b7280', 'unverified'],
    ['Verification failed', $nfailed,    '#ef4444', 'failed'],
    ['Manual review',       $nreview,    '#f59e0b', 'review'],
    ['USI present, DOB missing', $nmissingdob, '#b45309', 'missingdob'],
    ['No USI recorded',     $nmissingusi, '#64748b', 'nousi'],
];
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;">';
foreach ($cards as $c) {
    $curl = new moodle_url('/local/rtocompliance/usi_settings.php', ['usifilter' => $c[3]]);
    echo '<a href="' . s($curl->out(false)) . '" style="text-decoration:none;background:white;border:1px solid #e5e7eb;border-left:4px solid ' . $c[2] . ';border-radius:8px;padding:14px 16px;display:block;transition:box-shadow .15s;" onmouseover="this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.08)\'" onmouseout="this.style.boxShadow=\'none\'">';
    echo '<div style="font-size:24px;font-weight:700;color:' . $c[2] . ';line-height:1.1;">' . $c[1] . '</div>';
    echo '<div style="font-size:12.5px;color:#475569;margin-top:2px;">' . s($c[0]) . '</div>';
    echo '</a>';
}
echo '</div>';

// ── Action bar ───────────────────────────────────────────────────────────────
$reverifyurl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiaction' => 'reverifyall', 'sesskey' => sesskey()]);
$exportcururl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiexport' => 'csv', 'usifilter' => $usifilter, 'usisearch' => $usisearch,
     'usicat' => $usicat, 'usicourse' => $usicourse, 'sesskey' => sesskey()]);
$exportnodoburl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiexport' => 'nodob', 'sesskey' => sesskey()]);
$fixdoburl = new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']);

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">';
// CONNECTION-TEST (v6.2.47): instantly check whether the platform ↔ usi.gov.au link works.
$conntesturl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiaction' => 'conntest', 'sesskey' => sesskey()]);
echo html_writer::link($conntesturl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Run connection test',
    ['class' => 'btn btn-info btn-sm',
     'title' => 'Verify a dummy record to confirm the platform + usi.gov.au link is working (a "no match" result means it works)']);
echo html_writer::link($reverifyurl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Re-verify all students',
    ['class' => 'btn btn-primary btn-sm',
     'title' => 'Queue every student with a USI and date of birth for re-verification on the next scheduled USI run']);
echo html_writer::link($exportcururl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Export this view (CSV)',
    ['class' => 'btn btn-outline-secondary btn-sm', 'title' => 'Download the students currently shown by the filter/search as a CSV']);
echo html_writer::link($exportnodoburl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Export students without DOB',
    ['class' => 'btn btn-outline-warning btn-sm',
     'title' => 'Download the students who have a USI but no date of birth — these can never be verified until a DOB is added']);
echo html_writer::link($fixdoburl, 'Fix missing DOBs →',
    ['class' => 'btn btn-link btn-sm', 'title' => 'Go to Student Records to backfill dates of birth from your NAT00080 file']);
echo '</div>';

if ($nmissingdob > 0) {
    $uploaddoburl = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false);
    echo '<div class="alert alert-warning" style="padding:12px 14px;font-size:13px;margin-bottom:16px;">';
    echo '<strong>' . $nmissingdob . ' student(s) have a USI but no date of birth</strong> — the USI Registry cannot verify them until a DOB is recorded.';
    echo '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">';

    // Round-trip CSV upload: export the no-DOB list, fill the Date of birth column, re-upload here.
    echo '<form method="post" action="' . s($uploaddoburl) . '" enctype="multipart/form-data" '
        . 'style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;">';
    echo '<input type="hidden" name="usiaction" value="uploaddob">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<label style="font-weight:600;margin:0;font-size:12.5px;">Upload DOBs (CSV):</label>';
    echo '<input type="file" name="dobcsv" accept=".csv,text/csv" required class="form-control-file" style="font-size:12.5px;">';
    echo '<button type="submit" class="btn btn-warning btn-sm">Upload &amp; backfill</button>';
    echo '</form>';

    echo '<div style="font-size:12px;color:#78350f;max-width:420px;line-height:1.5;">'
        . 'Click <em>Export students without DOB</em> above, fill in the <strong>Date of birth</strong> column '
        . '(DD/MM/YYYY), then upload the file here. Rows are matched by Client identifier, then USI, then email; '
        . 'only students with a blank DOB are updated. Or use <a href="' . s($fixdoburl->out(false)) . '">Fix missing DOBs</a> '
        . 'to backfill automatically from your NAT00080.'
        . '</div>';
    echo '</div></div>';
}

// ── Filter + search bar (matches the Student Records page styling) ────────────
$filteropts = [
    'all'        => 'All students',
    'withusi'    => 'Students with a USI',
    'verified'   => 'Verified (usi.gov.au)',
    'unverified' => 'Not yet verified',
    'failed'     => 'Verification failed',
    'review'     => 'Manual review required',
    'missingdob' => 'USI present, DOB missing',
    'nousi'      => 'No USI recorded',
];
// USI-CAT-COURSE-FILTER (v6.2.32): build the category list (indented to show the
// subcategory hierarchy) and the course list for the scope dropdowns.
$usi_catmenu = [];
$usi_allcats = $DB->get_records('course_categories', null, 'path', 'id, name, depth');
foreach ($usi_allcats as $cc) {
    $usi_catmenu[$cc->id] = str_repeat('— ', max(0, (int) $cc->depth - 1)) . format_string($cc->name);
}
// DYNAMIC-COURSE (v6.2.57): fetch each course WITH its category path so the Course dropdown can
// be filtered client-side to the selected Category (and its subcategories). The category path
// contains every ancestor category id, matching the server-side category+descendants scope.
$usi_coursemeta = $DB->get_records_sql(
    "SELECT c.id, c.fullname, cc.path AS catpath
       FROM {course} c
       JOIN {course_categories} cc ON cc.id = c.category
      WHERE c.id <> :siteid
   ORDER BY c.fullname",
    ['siteid' => SITEID]
);

echo '<div class="rtoc-filter-bar"><form method="get" action="" class="rtoc-filter-fields" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';
echo '<div class="rtoc-filter-group"><label for="usifilter">Filter</label><select name="usifilter" id="usifilter" class="form-control">';
foreach ($filteropts as $k => $lbl) {
    echo '<option value="' . $k . '"' . ($usifilter === $k ? ' selected' : '') . '>' . s($lbl) . '</option>';
}
echo '</select></div>';
// Category scope (includes subcategories).
echo '<div class="rtoc-filter-group"><label for="usicat">Category</label><select name="usicat" id="usicat" class="form-control">';
echo '<option value="0">All categories</option>';
foreach ($usi_catmenu as $cid => $cname) {
    echo '<option value="' . $cid . '"' . ((int) $usicat === (int) $cid ? ' selected' : '') . '>' . s($cname) . '</option>';
}
echo '</select></div>';
// Course scope (a specific course overrides the category).
echo '<div class="rtoc-filter-group"><label for="usicourse">Course</label><select name="usicourse" id="usicourse" class="form-control">';
echo '<option value="0">All courses</option>';
foreach ($usi_coursemeta as $co) {
    echo '<option value="' . (int) $co->id . '" data-catpath="' . s($co->catpath) . '"'
        . ((int) $usicourse === (int) $co->id ? ' selected' : '') . '>' . s(format_string($co->fullname)) . '</option>';
}
echo '</select></div>';
echo '<div class="rtoc-filter-group rtoc-filter-search"><label for="usisearch">Search</label>'
    . '<input type="text" name="usisearch" id="usisearch" class="form-control" placeholder="Name, email, USI or client ID" value="' . s($usisearch) . '"></div>';
echo '<div class="rtoc-filter-group rtoc-filter-action"><label>&nbsp;</label>'
    . '<button type="submit" class="btn btn-primary">Search</button></div>';
echo '</form></div>';

// DYNAMIC-COURSE (v6.2.57): when a Category is chosen, show only the courses that live in that
// category or any of its subcategories (matched on the category path). Choosing "All categories"
// shows every course. If the currently-selected course is filtered out, it resets to "All courses".
echo html_writer::script('
(function () {
    var cat = document.getElementById("usicat");
    var course = document.getElementById("usicourse");
    if (!cat || !course) { return; }
    var opts = Array.prototype.slice.call(course.options);
    function apply() {
        var cid = String(cat.value || "0");
        opts.forEach(function (o) {
            if (!o.value || o.value === "0") { o.hidden = false; o.disabled = false; return; }
            var path = (o.getAttribute("data-catpath") || "") + "/";
            var show = (cid === "0" || cid === "") || (path.indexOf("/" + cid + "/") !== -1);
            o.hidden = !show;
            o.disabled = !show;
        });
        var sel = course.options[course.selectedIndex];
        if (sel && sel.hidden) { course.value = "0"; }
    }
    cat.addEventListener("change", apply);
    apply();
})();
');

// ── Query + render the table ─────────────────────────────────────────────────
list($twhere, $tparams) = $usi_build_where($usifilter, $usisearch, $usicat, $usicourse);
$countsql = "SELECT COUNT(u.id)
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE $twhere";
$usitotal = (int) $DB->count_records_sql($countsql, $tparams);

$listsql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
                   s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
              FROM {user} u
              JOIN {local_rtocompliance_students} s ON s.userid = u.id
             WHERE $twhere
             ORDER BY u.lastname ASC, u.firstname ASC";
$rows = $DB->get_records_sql($listsql, $tparams, $usipage * $usiperpage, $usiperpage);

// Status badge renderer.
$usi_badge = function ($code, $usi) {
    if ($usi === null || trim((string) $usi) === '') {
        return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:600;background:#f1f5f9;color:#64748b;">No USI</span>';
    }
    switch ((int) $code) {
        case 1: $bg = '#dcfce7'; $fg = '#166534'; $t = 'Verified'; break;
        case 2: $bg = '#fee2e2'; $fg = '#991b1b'; $t = 'Failed'; break;
        case 3: $bg = '#e0f2fe'; $fg = '#075985'; $t = 'Pending'; break;
        case 4: $bg = '#fef3c7'; $fg = '#92400e'; $t = 'Manual review'; break;
        default: $bg = '#f1f5f9'; $fg = '#475569'; $t = 'Not yet verified'; break;
    }
    return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:600;background:' . $bg . ';color:' . $fg . ';">' . $t . '</span>';
};

if (empty($rows)) {
    echo '<div class="alert alert-info" style="margin-top:8px;">No students match this filter/search.</div>';
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable rtoc-table';
    $table->head = ['Name', 'Email', 'Client ID', 'USI', 'Date of birth', 'USI status', 'Verified date'];
    $table->data = [];
    foreach ($rows as $r) {
        $name = s(trim($r->firstname . ' ' . $r->lastname));
        $studenturl = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $r->userid]);
        $dob = (!empty($r->dateofbirth) && (int) $r->dateofbirth > 0)
            ? userdate((int) $r->dateofbirth, '%d/%m/%Y')
            : '<span style="color:#b45309;font-weight:600;">Missing</span>';
        $vdate = (!empty($r->usiverifieddate) && (int) $r->usiverifieddate > 0)
            ? userdate((int) $r->usiverifieddate, '%d/%m/%Y') : '—';
        $usidisp = ($r->usi !== null && trim((string) $r->usi) !== '')
            ? '<span style="font-family:monospace;letter-spacing:.5px;">' . s($r->usi) . '</span>' : '—';
        $table->data[] = [
            '<a href="' . s($studenturl->out(false)) . '">' . $name . '</a>',
            s($r->email),
            s((string) $r->clientid),
            $usidisp,
            $dob,
            $usi_badge($r->usiverified, $r->usi),
            $vdate,
        ];
    }
    echo html_writer::table($table);

    // Pagination.
    $baseurl = new moodle_url('/local/rtocompliance/usi_settings.php',
        ['usifilter' => $usifilter, 'usisearch' => $usisearch, 'usicat' => $usicat, 'usicourse' => $usicourse]);
    echo $OUTPUT->paging_bar($usitotal, $usipage, $usiperpage, $baseurl, 'usipage');
    echo '<div style="font-size:12px;color:#6b7280;margin-top:6px;">Showing '
        . (min($usipage * $usiperpage + 1, $usitotal)) . '–' . min(($usipage + 1) * $usiperpage, $usitotal)
        . ' of ' . $usitotal . ' student(s).</div>';
}

echo $OUTPUT->footer();
