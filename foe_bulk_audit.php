<?php
// ─────────────────────────────────────────────────────────────────────────────
// FOE Bulk Deletion Audit  (local_rtocompliance v5.9.150)
//
// READ-ONLY.  Never calls enrol_user(), unenrol_user(), or any write method.
// Safe to run on production at any time.
//
// PURPOSE
// -------
// For every enrolment deletion fired by the Fix Over-Enrolments button in a
// given date window, apply the reconciliation logic and answer:
//
//   Should this student be enrolled in this course today?
//   Are they enrolled today?
//
// Per-row verdict:
//   RESTORE REQUIRED  — in NAT, not enrolled → FOE deletion was likely wrong
//   Correctly removed — not in NAT, not enrolled → FOE was correct
//   Successfully restored — in NAT, currently enrolled → already fixed
//   Review            — undetermined (no unit code / no NAT data / no client ID)
//
// INPUTS
// ------
//   Date window  — covers the FOE run (default: 27–28 Jun 2026 inclusive)
//   NAT import   — which local_rtocompliance import to use as current NAT truth
//   Actor filter — optional: only include deletions by a specific Moodle user ID
//
// DATA SOURCES
// ------------
//   mdl_logstore_standard_log              → source of deletion events
//   mdl_user                               → student + actor identity/client ID
//   mdl_course                             → course shortname/fullname/idnumber
//   local_rtocompliance_avetmiss_enrolment → NAT unit records per student
//   mdl_user_enrolments + mdl_enrol        → current live enrolment status
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_foe_bulk_audit');
$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);
\core\session\manager::write_close();

// ── Parameters ────────────────────────────────────────────────────────────────
$action    = optional_param('action',    '',           PARAM_ALPHA);
$importid  = optional_param('importid',  0,            PARAM_INT);
$datestart = optional_param('datestart', '2026-06-27', PARAM_ALPHANUMEXT);
$dateend   = optional_param('dateend',   '2026-06-29', PARAM_ALPHANUMEXT);
$actorid   = optional_param('actorid',   0,            PARAM_INT);
$download  = optional_param('download',  '',           PARAM_ALPHA);
$token     = optional_param('token',     '',           PARAM_ALPHANUM);

// ── Helpers ───────────────────────────────────────────────────────────────────
function _fba_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function _fba_n(int $n): string    { return number_format($n); }

function _fba_csvpath(string $tok): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rtoc_fba_' . $tok . '.csv';
}

/**
 * Extract AVETMISS unit code from course idnumber / shortname / fullname.
 * Same regex as data_import.php _foe_extract_unitcode() and reconcile.php.
 */
function _fba_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
    $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
    $idn = strtoupper(trim($idnumber));
    if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) return $idn;
    if (preg_match($pat, $idn, $m)) return $m[1];
    if (preg_match($pat, strtoupper(trim($shortname)), $m)) return $m[1];
    if (preg_match($pat, strtoupper(trim($fullname)),  $m)) return $m[1];
    // Last-resort: search anywhere in fullname (handles "CBP - BSBCMM201 / …")
    if (preg_match('/([A-Z]{2,7}[0-9]{3,5}[A-Z]?)/', strtoupper(trim($fullname)), $m)) return $m[1];
    return '';
}

function _fba_verdict_chip(string $verdict): string {
    static $map = [
        'RESTORE REQUIRED'      => ['#dc3545', '#fff'],
        'Correctly removed'     => ['#198754', '#fff'],
        'Successfully restored' => ['#0d6efd', '#fff'],
        'Review'                => ['#856404', '#fff4cd'],
    ];
    [$bg, $fg] = $map[$verdict] ?? ['#6c757d', '#fff'];
    return '<span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:3px;'
         . 'font-size:0.82em;font-weight:700;white-space:nowrap;background:' . $bg . ';color:' . $fg . ';">'
         . _fba_h($verdict) . '</span>';
}

// ── CSV download handler ───────────────────────────────────────────────────────
if ($action === 'download' && $token) {
    $path = _fba_csvpath($token);
    if (!file_exists($path)) {
        redirect(new moodle_url('/local/rtocompliance/foe_bulk_audit.php'));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="foe_bulk_audit.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    readfile($path);
    exit;
}

// ── Load imports for form selector ────────────────────────────────────────────
$_imports = $DB->get_records_sql(
    "SELECT id, collectionyear, timecreated FROM {local_rtocompliance_imports} ORDER BY id DESC"
);

// ─────────────────────────────────────────────────────────────────────────────
// FORM (shown when action !== 'run')
// ─────────────────────────────────────────────────────────────────────────────
if ($action !== 'run') {
    echo $OUTPUT->header();
    echo '<h2>FOE Bulk Deletion Audit</h2>';
    echo '<p class="text-muted" style="max-width:800px;">'
       . 'Reads every <code>user_enrolment_deleted</code> event from Moodle\'s standard log for the '
       . 'selected date window, then applies the same AVETMISS unit-code matching logic used by '
       . 'the Fix Over-Enrolments tool to classify each deletion. '
       . 'This answers: <em>"Of the enrolments FOE deleted, which should be restored?"</em>'
       . '</p>';

    // Logstore check
    $_logstoreOk = false;
    try { $_logstoreOk = $DB->get_manager()->table_exists('logstore_standard_log'); } catch (Throwable $e) {}
    if (!$_logstoreOk) {
        echo '<div class="alert alert-danger">'
           . '<strong>&#10060; Standard logstore table not found.</strong> '
           . 'Enable the Standard log at <em>Site Admin → Plugins → Logging → Manage log stores</em>.'
           . '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Total deletion events in log (sanity check)
    $_totalEver = 0;
    try {
        $_totalEver = (int)$DB->count_records_select(
            'logstore_standard_log',
            "eventname = '\\\\core\\\\event\\\\user_enrolment_deleted'"
        );
    } catch (Throwable $e) {}

    echo '<div class="alert alert-info" style="max-width:700px;font-size:0.9em;">'
       . '<strong>Logstore check:</strong> '
       . number_format($_totalEver) . ' total <code>user_enrolment_deleted</code> event(s) in the log. '
       . (($_totalEver === 0)
           ? '<strong style="color:#dc3545;">Zero events — standard logging was either not enabled or no enrolments have been removed.</strong>'
           : 'Standard logging is active.')
       . '</div>';

    echo '<div class="card mb-4" style="max-width:700px;">';
    echo '<div class="card-header fw-bold" style="background:#0d6efd;color:#fff;">Configure Audit</div>';
    echo '<div class="card-body">';
    echo '<form method="post" action="'
       . (new moodle_url('/local/rtocompliance/foe_bulk_audit.php', ['action' => 'run']))->out(false)
       . '">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo '<div class="form-group mb-3">'
       . '<label class="fw-bold">FOE run window — start date</label>'
       . '<input type="date" name="datestart" class="form-control" style="max-width:220px;" value="' . _fba_h($datestart) . '" required>'
       . '<small class="form-text text-muted">First day of the FOE run, inclusive. Widen by one day if uncertain of server timezone vs AEST.</small>'
       . '</div>';

    echo '<div class="form-group mb-3">'
       . '<label class="fw-bold">FOE run window — end date <span class="fw-normal text-muted">(exclusive)</span></label>'
       . '<input type="date" name="dateend" class="form-control" style="max-width:220px;" value="' . _fba_h($dateend) . '" required>'
       . '<small class="form-text text-muted">The day AFTER the last day of the run, e.g. 2026-06-29 to include 28 June.</small>'
       . '</div>';

    echo '<div class="form-group mb-3">'
       . '<label class="fw-bold">NAT import — current truth</label>'
       . '<select name="importid" class="form-control" style="max-width:500px;">'
       . '<option value="0">— No NAT import (show deletions only, no verdict) —</option>';
    foreach ($_imports as $_imp) {
        $_sel = ((int)$_imp->id === $importid) ? ' selected' : '';
        echo '<option value="' . (int)$_imp->id . '"' . $_sel . '>'
           . 'Import #' . (int)$_imp->id
           . (!empty($_imp->collectionyear) ? ' (' . _fba_h($_imp->collectionyear) . ')' : '')
           . ' — ' . date('d M Y', (int)$_imp->timecreated)
           . '</option>';
    }
    echo '</select>';
    echo '<small class="form-text text-muted">Recommended: pick the most recent import. "Should Exist Today" checks whether each student+unit appears in this import\'s NAT enrolment data.</small>';
    echo '</div>';

    echo '<div class="form-group mb-3">'
       . '<label class="fw-bold">Actor filter <span class="fw-normal text-muted">(optional)</span></label>'
       . '<input type="number" name="actorid" class="form-control" style="max-width:220px;" value="' . (int)$actorid . '" min="0" placeholder="0 = all actors">'
       . '<small class="form-text text-muted">Moodle user ID of the admin who clicked the Remove button. Leave 0 to include all actors (recommended first run).</small>'
       . '</div>';

    echo '<button type="submit" class="btn btn-primary">&#128202; Run Audit</button>';
    echo '</form>';
    echo '</div></div>';

    // Verdict legend
    echo '<div class="card" style="max-width:700px;">';
    echo '<div class="card-header fw-bold">Verdict meanings</div>';
    echo '<div class="card-body" style="font-size:0.88em;">';
    $vlegend = [
        ['RESTORE REQUIRED',      '#dc3545', 'Student has a current NAT record for this unit code, but is NOT enrolled today. The FOE deletion was likely a false positive and should be reversed.'],
        ['Correctly removed',     '#198754', 'Student does NOT have a current NAT record for this unit code and is not enrolled. FOE was correct.'],
        ['Successfully restored', '#0d6efd', 'Student has a current NAT record for this unit code AND is enrolled today. Already fixed — no action needed.'],
        ['Review',                '#856404', 'Cannot determine: course has no extractable unit code, student has no Moodle idnumber (client ID), student not found in NAT, or no NAT import selected.'],
    ];
    foreach ($vlegend as [$lbl, $clr, $desc]) {
        echo '<div class="mb-2 d-flex align-items-start gap-2">'
           . '<span style="flex-shrink:0;display:inline-block;padding:0.15rem 0.55rem;border-radius:3px;font-size:0.82em;font-weight:700;background:' . $clr . ';color:#fff;">' . _fba_h($lbl) . '</span>'
           . '<span>' . _fba_h($desc) . '</span>'
           . '</div>';
    }
    echo '</div></div>';

    echo $OUTPUT->footer();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// RUN ACTION
// ─────────────────────────────────────────────────────────────────────────────
require_sesskey();

$_tsStart = strtotime($datestart . ' 00:00:00');
$_tsEnd   = strtotime($dateend   . ' 00:00:00');
if (!$_tsStart || !$_tsEnd || $_tsEnd <= $_tsStart) {
    throw new moodle_exception('invalidargument', 'error', '', 'Invalid date range.');
}

// ── 1. Verify logstore ────────────────────────────────────────────────────────
$_logstoreOk = false;
try { $_logstoreOk = $DB->get_manager()->table_exists('logstore_standard_log'); } catch (Throwable $e) {}
if (!$_logstoreOk) {
    throw new moodle_exception('invalidargument', 'error', '', 'logstore_standard_log not found.');
}

// ── 2. Pull deletions from logstore ───────────────────────────────────────────
// Moodle event fields:
//   userid        = the admin/actor who triggered the unenrolment
//   relateduserid = the student who was unenrolled
//   courseid      = the Moodle course
//   other         = JSON blob containing enrol method + enrolid
$_actorSql   = '';
$_logParams  = ['ts_start' => $_tsStart, 'ts_end' => $_tsEnd];
if ($actorid > 0) {
    $_actorSql = ' AND l.userid = :actorid';
    $_logParams['actorid'] = $actorid;
}

$_logRs = $DB->get_recordset_sql(
    "SELECT l.id, l.timecreated,
            l.userid        AS actor_userid,
            l.relateduserid AS student_userid,
            l.courseid,
            l.other
       FROM {logstore_standard_log} l
      WHERE l.eventname  = '\\\\core\\\\event\\\\user_enrolment_deleted'
        AND l.timecreated >= :ts_start
        AND l.timecreated <  :ts_end"
        . $_actorSql . "
      ORDER BY l.timecreated ASC",
    $_logParams
);

$_deletions   = []; // raw deletion tuples
$_stuUidSet   = [];
$_actorUidSet = [];
$_crsIdSet    = [];

foreach ($_logRs as $_lr) {
    $stuUid   = (int)$_lr->student_userid;
    $actorUid = (int)$_lr->actor_userid;
    $cid      = (int)$_lr->courseid;
    if ($stuUid < 1 || $cid < 1) continue;
    $_deletions[]            = [$actorUid, $stuUid, $cid, (int)$_lr->timecreated, (string)$_lr->other];
    $_stuUidSet[$stuUid]     = true;
    $_actorUidSet[$actorUid] = true;
    $_crsIdSet[$cid]         = true;
}
$_logRs->close();

$_totalDeletions = count($_deletions);

if ($_totalDeletions === 0) {
    echo $OUTPUT->header();
    echo '<h2>FOE Bulk Deletion Audit</h2>';
    echo '<div class="alert alert-warning">'
       . '<strong>No <code>user_enrolment_deleted</code> events found</strong> between '
       . date('d M Y', $_tsStart) . ' and ' . date('d M Y', $_tsEnd)
       . ($actorid > 0 ? ' for actor user ID ' . $actorid : '')
       . '.</div>';
    echo '<p><a href="' . (new moodle_url('/local/rtocompliance/foe_bulk_audit.php'))->out() . '" class="btn btn-secondary">Back</a></p>';
    echo $OUTPUT->footer();
    exit;
}

// ── 3. Bulk load student users (idnumber = NAT client ID) ─────────────────────
$_stuUsers   = []; // userid → {username, firstname, lastname, idnumber}
$_actorUsers = []; // userid → {username}

foreach (array_chunk(array_keys($_stuUidSet), 500) as $_chunk) {
    [$_sql, $_p] = $DB->get_in_or_equal($_chunk, SQL_PARAMS_NAMED, 'su');
    $_rs = $DB->get_recordset_sql(
        "SELECT id, username, firstname, lastname, idnumber FROM {user} WHERE id $_sql", $_p
    );
    foreach ($_rs as $_u) { $_stuUsers[(int)$_u->id] = $_u; }
    $_rs->close();
}
foreach (array_chunk(array_keys($_actorUidSet), 200) as $_chunk) {
    [$_sql, $_p] = $DB->get_in_or_equal($_chunk, SQL_PARAMS_NAMED, 'au');
    $_rs = $DB->get_recordset_sql(
        "SELECT id, username, firstname, lastname FROM {user} WHERE id $_sql", $_p
    );
    foreach ($_rs as $_u) { $_actorUsers[(int)$_u->id] = $_u; }
    $_rs->close();
}

// ── 4. Bulk load course info + extract unit codes ─────────────────────────────
$_courseMap = []; // courseid → {shortname, fullname, unitcode}
foreach (array_chunk(array_keys($_crsIdSet), 500) as $_chunk) {
    [$_sql, $_p] = $DB->get_in_or_equal($_chunk, SQL_PARAMS_NAMED, 'cr');
    $_rs = $DB->get_recordset_sql(
        "SELECT id, shortname, fullname, idnumber FROM {course} WHERE id $_sql", $_p
    );
    foreach ($_rs as $_c) {
        $_uc = _fba_extract_unitcode((string)$_c->idnumber, (string)$_c->shortname, (string)$_c->fullname);
        $_courseMap[(int)$_c->id] = (object)[
            'shortname' => (string)$_c->shortname,
            'fullname'  => (string)$_c->fullname,
            'unitcode'  => $_uc,
        ];
    }
    $_rs->close();
}

// ── 5. Load NAT data ──────────────────────────────────────────────────────────
// natUnits[lc_clientid][UNITCODE] = outcome code
$natUnits  = [];
$natLoaded = false;
$importRec = null;

if ($importid > 0) {
    $importRec = $DB->get_record('local_rtocompliance_imports', ['id' => $importid]);
    if ($importRec) {
        $natLoaded = true;
        $_natRs = $DB->get_recordset_select(
            'local_rtocompliance_avetmiss_enrolment',
            'importid = :iid', ['iid' => $importid], '',
            'clientid, unitcode, outcome'
        );
        foreach ($_natRs as $_nr) {
            $_lc = strtolower(trim((string)$_nr->clientid));
            $_uc = strtoupper(trim((string)$_nr->unitcode));
            if ($_lc === '' || $_uc === '') continue;
            $natUnits[$_lc][$_uc] = trim((string)$_nr->outcome);
        }
        $_natRs->close();
    }
}

// ── 6. Bulk load current enrolments ──────────────────────────────────────────
// Only load pairs that actually appear in the deletion list.
$_currentlyEnrolled = []; // "uid:cid" => true
if (!empty($_stuUidSet) && !empty($_crsIdSet)) {
    foreach (array_chunk(array_keys($_stuUidSet), 500) as $_uChunk) {
        foreach (array_chunk(array_keys($_crsIdSet), 500) as $_cChunk) {
            [$_uSql, $_uP] = $DB->get_in_or_equal($_uChunk, SQL_PARAMS_NAMED, 'eu');
            [$_cSql, $_cP] = $DB->get_in_or_equal($_cChunk, SQL_PARAMS_NAMED, 'ec');
            $_rs = $DB->get_recordset_sql(
                "SELECT ue.userid, e.courseid
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid $_uSql AND e.courseid $_cSql AND ue.status = 0",
                array_merge($_uP, $_cP)
            );
            foreach ($_rs as $_er) {
                $_currentlyEnrolled[(int)$_er->userid . ':' . (int)$_er->courseid] = true;
            }
            $_rs->close();
        }
    }
}

// ── 7. Build result rows ──────────────────────────────────────────────────────
$_rows         = [];
$_cntRestore   = 0;
$_cntCorrect   = 0;
$_cntRestored  = 0;
$_cntReview    = 0;

foreach ($_deletions as [$_actorUid, $_stuUid, $_cid, $_ts, $_other]) {
    $_stu    = $_stuUsers[$_stuUid]   ?? null;
    $_actor  = $_actorUsers[$_actorUid] ?? null;
    $_course = $_courseMap[$_cid]     ?? null;

    $_stuUsername  = $_stu   ? (string)$_stu->username  : 'uid:' . $_stuUid;
    $_stuName      = $_stu   ? trim((string)$_stu->firstname . ' ' . (string)$_stu->lastname) : '';
    $_clientid     = $_stu   ? strtolower(trim((string)$_stu->idnumber)) : '';
    $_actorName    = $_actor ? (string)$_actor->username : 'uid:' . $_actorUid;
    $_csn          = $_course ? (string)$_course->shortname : 'cid:' . $_cid;
    $_cfn          = $_course ? (string)$_course->fullname  : '';
    $_unitcode     = $_course ? (string)$_course->unitcode  : '';
    $_existsNow    = isset($_currentlyEnrolled[$_stuUid . ':' . $_cid]);

    // Extract enrol method from 'other' JSON field
    $_enrolMethod = '';
    if ($_other) {
        $_od = json_decode($_other, true);
        if (is_array($_od) && isset($_od['enrol'])) $_enrolMethod = (string)$_od['enrol'];
    }

    // Determine "Should Exist Today" using current NAT data
    $_shouldExist  = null; // null = unknown / cannot determine
    $_shouldReason = 'No NAT import selected';

    if ($natLoaded) {
        if ($_unitcode === '') {
            $_shouldReason = 'No AVETMISS unit code found for this course';
        } elseif ($_clientid === '') {
            $_shouldReason = 'Student has no idnumber in Moodle (cannot match to NAT)';
        } elseif (!isset($natUnits[$_clientid])) {
            $_shouldReason = 'Client ID "' . $_clientid . '" not found in this NAT import at all';
        } elseif (isset($natUnits[$_clientid][$_unitcode])) {
            $_shouldExist  = true;
            $_shouldReason = 'Unit ' . $_unitcode . ' present in NAT for this student (outcome: ' . ($natUnits[$_clientid][$_unitcode] ?: '—') . ')';
        } else {
            $_shouldExist  = false;
            $_shouldReason = 'Unit ' . $_unitcode . ' NOT in NAT for client "' . $_clientid . '"';
        }
    }

    // Assign verdict
    if ($_shouldExist === true  && !$_existsNow)  { $_verdict = 'RESTORE REQUIRED';      $_cntRestore++;  }
    elseif ($_shouldExist === false && !$_existsNow)  { $_verdict = 'Correctly removed';     $_cntCorrect++;  }
    elseif ($_shouldExist === true  &&  $_existsNow)  { $_verdict = 'Successfully restored'; $_cntRestored++; }
    else                                               { $_verdict = 'Review';                $_cntReview++;   }

    $_rows[] = [
        'deleted_at'       => date('d M Y H:i:s', $_ts),
        'deleted_by'       => $_actorName,
        'student_username' => $_stuUsername,
        'student_name'     => $_stuName,
        'client_id'        => $_clientid,
        'course_shortname' => $_csn,
        'course_fullname'  => $_cfn,
        'unit_code'        => $_unitcode,
        'enrol_method'     => $_enrolMethod,
        'should_exist'     => $_shouldExist === true ? 'Yes' : ($_shouldExist === false ? 'No' : 'Unknown'),
        'should_reason'    => $_shouldReason,
        'exists_now'       => $_existsNow ? 'Yes' : 'No',
        'verdict'          => $_verdict,
    ];
}

// ── 8. Write CSV ──────────────────────────────────────────────────────────────
$_tok     = md5(uniqid('fba', true));
$_csvPath = _fba_csvpath($_tok);
$_fh = @fopen($_csvPath, 'w');
if ($_fh) {
    fprintf($_fh, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($_fh, [
        'deleted_at','deleted_by','student_username','student_name','client_id',
        'course_shortname','course_fullname','unit_code','enrol_method',
        'should_exist_today','should_reason','exists_today','verdict',
    ]);
    foreach ($_rows as $_r) {
        fputcsv($_fh, [
            $_r['deleted_at'],  $_r['deleted_by'],       $_r['student_username'],
            $_r['student_name'],$_r['client_id'],        $_r['course_shortname'],
            $_r['course_fullname'], $_r['unit_code'],    $_r['enrol_method'],
            $_r['should_exist'],    $_r['should_reason'],$_r['exists_now'],
            $_r['verdict'],
        ]);
    }
    fclose($_fh);
}

// ─────────────────────────────────────────────────────────────────────────────
// RENDER RESULTS
// ─────────────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo '<h2>FOE Bulk Deletion Audit — Results</h2>';

// ── Summary tiles ─────────────────────────────────────────────────────────────
echo '<div class="row mb-3" style="max-width:1000px;">';
foreach ([
    [$_totalDeletions, 'Total deletions in window', '#495057', '#fff'],
    [$_cntRestore,     'RESTORE REQUIRED',           '#dc3545', '#fff'],
    [$_cntCorrect,     'Correctly removed',           '#198754', '#fff'],
    [$_cntRestored,    'Successfully restored',       '#0d6efd', '#fff'],
    [$_cntReview,      'Review',                      '#856404', '#fff4cd'],
] as [$n, $lbl, $bg, $fg]) {
    echo '<div class="col-auto mb-2"><div style="padding:0.6rem 1.1rem;border-radius:5px;'
       . 'background:' . $bg . ';color:' . $fg . ';text-align:center;min-width:120px;">'
       . '<div style="font-size:1.7rem;font-weight:700;line-height:1;">' . number_format($n) . '</div>'
       . '<div style="font-size:0.74rem;font-weight:600;margin-top:0.25rem;">' . _fba_h($lbl) . '</div>'
       . '</div></div>';
}
echo '</div>';

// ── Context bar ───────────────────────────────────────────────────────────────
echo '<p class="text-muted mb-2" style="font-size:0.88em;">'
   . 'Window: <strong>' . _fba_h($datestart) . '</strong> to <strong>' . _fba_h($dateend) . '</strong>'
   . ($importRec ? ' &nbsp;|&nbsp; NAT import: <strong>#' . (int)$importRec->id
       . (!empty($importRec->collectionyear) ? ' (' . _fba_h($importRec->collectionyear) . ')' : '')
       . ' — ' . date('d M Y', (int)$importRec->timecreated) . '</strong>'
     : ' &nbsp;|&nbsp; <em>No NAT import — verdicts not available</em>')
   . ($actorid > 0 ? ' &nbsp;|&nbsp; Actor filter: userid <strong>' . $actorid . '</strong>' : '')
   . '</p>';

// ── Download link ─────────────────────────────────────────────────────────────
if ($_fh !== false && file_exists($_csvPath)) {
    echo '<p class="mb-3"><a href="'
       . (new moodle_url('/local/rtocompliance/foe_bulk_audit.php', [
           'action' => 'download', 'token' => $_tok
       ]))->out()
       . '" class="btn btn-outline-secondary btn-sm">&#11015; Download full CSV ('
       . number_format($_totalDeletions) . ' rows)</a>'
       . ' <span class="text-muted" style="font-size:0.83em;">UTF-8 with BOM (Excel-compatible)</span>'
       . '</p>';
}

// ── Verdict filter tabs ───────────────────────────────────────────────────────
echo '<ul class="nav nav-tabs mb-3">';
foreach ([
    ['all',       'All ('                               . number_format($_totalDeletions) . ')', ''],
    ['restore',   'RESTORE REQUIRED ('                  . number_format($_cntRestore)    . ')', 'RESTORE REQUIRED'],
    ['correct',   'Correctly removed ('                 . number_format($_cntCorrect)    . ')', 'Correctly removed'],
    ['restored',  'Successfully restored ('             . number_format($_cntRestored)   . ')', 'Successfully restored'],
    ['review',    'Review ('                            . number_format($_cntReview)     . ')', 'Review'],
] as [$id, $label, $fverdict]) {
    echo '<li class="nav-item">'
       . '<a class="nav-link' . ($id === 'all' ? ' active' : '') . '" href="#" '
       . 'onclick="fbaFilter(' . json_encode($fverdict) . ');'
       . 'document.querySelectorAll(\'#fba-tabs .nav-link\').forEach(function(x){x.classList.remove(\'active\')});'
       . 'this.classList.add(\'active\');return false;" '
       . 'id="fba-tabs">' . _fba_h($label) . '</a>'
       . '</li>';
}
echo '</ul>';

// ── HTML table (capped at 2,000 rows for page performance) ────────────────────
$_htmlCap  = 2000;
$_htmlRows = 0;

echo '<div style="overflow-x:auto;">';
echo '<table class="table table-sm table-bordered table-hover" style="font-size:0.82em;">';
echo '<thead style="background:#f8f9fa;">';
echo '<tr>'
   . '<th>Deleted At</th>'
   . '<th>Deleted By</th>'
   . '<th>Student</th>'
   . '<th>Client ID</th>'
   . '<th>Course</th>'
   . '<th>Unit Code</th>'
   . '<th>Enrol Method</th>'
   . '<th>Should Exist Today?</th>'
   . '<th>Exists Today?</th>'
   . '<th>Verdict</th>'
   . '</tr>';
echo '</thead>';
echo '<tbody id="fba-tbody">';

foreach ($_rows as $_r) {
    if ($_htmlRows >= $_htmlCap) break;
    echo '<tr data-verdict="' . _fba_h($_r['verdict']) . '">';
    echo '<td style="white-space:nowrap;color:#6c757d;">' . _fba_h($_r['deleted_at']) . '</td>';
    echo '<td><code style="font-size:0.85em;">' . _fba_h($_r['deleted_by']) . '</code></td>';
    echo '<td>'
       . '<strong>' . _fba_h($_r['student_name']) . '</strong><br>'
       . '<code style="font-size:0.8em;">' . _fba_h($_r['student_username']) . '</code>'
       . '</td>';
    echo '<td><code style="font-size:0.8em;">' . _fba_h($_r['client_id']) . '</code></td>';
    echo '<td>'
       . '<strong>' . _fba_h($_r['course_shortname']) . '</strong><br>'
       . '<span style="font-size:0.8em;color:#6c757d;">'
       . _fba_h(mb_strimwidth($_r['course_fullname'], 0, 70, '…'))
       . '</span></td>';
    echo '<td><code>' . _fba_h($_r['unit_code']) . '</code></td>';
    echo '<td>' . _fba_h($_r['enrol_method']) . '</td>';
    // Should Exist Today
    echo '<td>';
    if ($_r['should_exist'] === 'Yes') {
        echo '<span style="color:#198754;font-weight:700;">Yes</span>';
    } elseif ($_r['should_exist'] === 'No') {
        echo '<span style="color:#dc3545;">No</span>';
    } else {
        echo '<span style="color:#6c757d;">Unknown</span>';
    }
    echo '<br><span style="font-size:0.78em;color:#6c757d;">'
       . _fba_h(mb_strimwidth($_r['should_reason'], 0, 90, '…'))
       . '</span></td>';
    // Exists Today
    echo '<td>';
    if ($_r['exists_now'] === 'Yes') {
        echo '<span style="color:#198754;font-weight:700;">Yes</span>';
    } else {
        echo '<span style="color:#dc3545;">No</span>';
    }
    echo '</td>';
    echo '<td>' . _fba_verdict_chip($_r['verdict']) . '</td>';
    echo '</tr>';
    $_htmlRows++;
}
echo '</tbody></table>';
echo '</div>';

if ($_totalDeletions > $_htmlCap) {
    echo '<div class="alert alert-info mt-2" style="font-size:0.87em;">'
       . '&#8505; Table shows first ' . number_format($_htmlCap) . ' of '
       . number_format($_totalDeletions) . ' rows. Download the CSV for the complete dataset.'
       . '</div>';
}

echo '<p class="mt-3">'
   . '<a href="' . (new moodle_url('/local/rtocompliance/foe_bulk_audit.php'))->out() . '" class="btn btn-secondary btn-sm">&#8592; Back</a>'
   . '</p>';
?>
<script>
function fbaFilter(verdict) {
    document.querySelectorAll('#fba-tbody tr').forEach(function(row) {
        row.style.display = (verdict === '' || row.getAttribute('data-verdict') === verdict) ? '' : 'none';
    });
}
</script>
<?php
echo $OUTPUT->footer();
