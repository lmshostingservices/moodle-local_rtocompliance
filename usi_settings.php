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
// SUBCATEGORY-FILTER (v6.3.3): RTOs nest their Moodle tree qualification → intake/year →
// unit, so a single top-level category can hold hundreds of courses (240 in one of them
// on this site). Jumping straight from Category to Course made the Course dropdown
// unusable. The Category list is now top-level only, with a Sub-category step between.
$usisubcat = optional_param('usisubcat', 0, PARAM_INT);
$usicourse = optional_param('usicourse', 0, PARAM_INT);
// PERPAGE-SELECTOR (v6.3.0): let the admin choose how many students to show per page.
// Anything outside the allowed set falls back to 50 so a hand-edited URL can never
// ask the database for an unbounded result set.
$usiperpageopts = [25, 50, 100, 200];
$usiperpage = optional_param('usiperpage', 50, PARAM_INT);
if (!in_array($usiperpage, $usiperpageopts, true)) {
    $usiperpage = 50;
}

// SORTABLE-COLUMNS (v6.3.0): read here, above the export handlers, so a CSV or PDF
// download comes out in the same order as the table the admin is looking at.
// The sort key is whitelisted and the direction coerced — nothing user-supplied
// is ever interpolated into the ORDER BY clause.
$usisort = optional_param('usisort', 'name', PARAM_ALPHA);
$usidir  = (strtoupper(optional_param('usidir', 'ASC', PARAM_ALPHA)) === 'DESC') ? 'DESC' : 'ASC';
$usisortcols = [
    'name'     => 'Name',
    'email'    => 'Email',
    'clientid' => 'Client ID',
    'usi'      => 'USI',
    'dob'      => 'Date of birth',
    'status'   => 'USI status',
    'vdate'    => 'Verified date',
];
if (!isset($usisortcols[$usisort])) {
    $usisort = 'name';
}
switch ($usisort) {
    case 'email':    $usiorderby = "u.email $usidir"; break;
    case 'clientid': $usiorderby = "s.clientid $usidir, u.lastname ASC"; break;
    case 'usi':      $usiorderby = "s.usi $usidir, u.lastname ASC"; break;
    case 'dob':      $usiorderby = "s.dateofbirth $usidir, u.lastname ASC"; break;
    case 'status':   $usiorderby = "s.usiverified $usidir, u.lastname ASC"; break;
    case 'vdate':    $usiorderby = "s.usiverifieddate $usidir, u.lastname ASC"; break;
    default:         $usiorderby = "u.lastname $usidir, u.firstname $usidir"; break;
}

// USI-RULE-DATE-FILTER (v6.3.1): the Unique Student Identifier became mandatory for
// nationally recognised training from 1 January 2015. A student whose training activity
// finished before that date was never required to hold a USI, and chasing them is wasted
// effort — but they still sit in the "No USI recorded" bucket and inflate it badly
// (on a long-running RTO that bucket is mostly historical students).
//
// This filter separates the two populations using the AVETMISS training activity dates on
// local_rtocompliance_enrolments, which is the correct basis: the rule keys off WHEN the
// training happened, not the student's age or when their record was created.
$usirule = optional_param('usirule', 'all', PARAM_ALPHA);
if (!in_array($usirule, ['all', 'post', 'pre', 'undated'], true)) {
    $usirule = 'all';
}
// Server-local midnight on 1 Jan 2015. make_timestamp() honours the site's timezone, so
// an activity that started on 1 Jan 2015 local time is counted as on-or-after the rule.
$usirulecutoff = make_timestamp(2015, 1, 1, 0, 0, 0);

$usisvc = new \local_rtocompliance\usi\usi_verification_service();

// Shared WHERE builder — the table view and every CSV export run the SAME
// filter/search logic so an export always matches exactly what is on screen.
$usi_build_where = function (string $filter, string $search, int $catid = 0, int $courseid = 0,
                             string $usirule = 'all', int $subcatid = 0) use ($DB, $usirulecutoff) {
    // Only rows that have a linked student profile (this is the USI page).
    $where  = "u.deleted = 0";
    $params = [];

    // FILTER-AUDIT (v6.3.0): every status filter below now ALSO requires a USI to be
    // present. Previously 'verified' / 'failed' / 'review' matched on the status code
    // alone, so a student with no USI at all but a stale status code was counted in a
    // USI status bucket. With this guard the buckets are mutually exclusive and add up:
    //   students with a USI = verified + not yet verified + failed + manual review
    //   all students        = students with a USI + no USI recorded
    $hasusi = "s.usi IS NOT NULL AND s.usi <> ''";
    switch ($filter) {
        case 'verified':
            $where .= " AND $hasusi AND s.usiverified = 1";
            break;
        case 'unverified':
            // USI present but not yet verified (never attempted = 0, transient/pending = 3).
            $where .= " AND $hasusi AND s.usiverified IN (0, 3)";
            break;
        case 'failed':
            $where .= " AND $hasusi AND s.usiverified = 2";
            break;
        case 'review':
            $where .= " AND $hasusi AND s.usiverified = 4";
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

    // USI-RULE-DATE-FILTER (v6.3.1): the USI became mandatory for nationally recognised
    // training on 1 January 2015. Split the population by WHEN the training activity
    // happened, using the AVETMISS activity dates.
    //
    // A row counts as "on or after the rule" when either its start OR its end date falls
    // on/after the cutoff — training that began in late 2014 and ran into 2015 is caught
    // by the rule, so the end date has to be considered too.
    if ($usirule !== 'all') {
        $onafter = "(en.activitystartdate >= :ruleco1 OR en.activityenddate >= :ruleco2)";
        $dated   = "((en.activitystartdate IS NOT NULL AND en.activitystartdate > 0)
                     OR (en.activityenddate IS NOT NULL AND en.activityenddate > 0))";

        if ($usirule === 'post') {
            // USI REQUIRED: at least one training activity on or after 1 Jan 2015.
            $where .= " AND EXISTS (
                SELECT 1 FROM {local_rtocompliance_enrolments} en
                  JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                 WHERE s2.userid = u.id AND $onafter)";
            $params['ruleco1'] = $usirulecutoff;
            $params['ruleco2'] = $usirulecutoff;
        } else if ($usirule === 'pre') {
            // USI NOT REQUIRED: the student HAS dated training activity, and none of it
            // falls on or after the cutoff. Students with no dated activity at all are
            // deliberately excluded here — absence of a date is not evidence the training
            // was historical, and quietly writing those off is how a genuinely reportable
            // student stops being chased for a USI.
            $where .= " AND EXISTS (
                SELECT 1 FROM {local_rtocompliance_enrolments} en
                  JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                 WHERE s2.userid = u.id AND $dated)
                AND NOT EXISTS (
                SELECT 1 FROM {local_rtocompliance_enrolments} en
                  JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                 WHERE s2.userid = u.id AND $onafter)";
            $params['ruleco1'] = $usirulecutoff;
            $params['ruleco2'] = $usirulecutoff;
        } else if ($usirule === 'undated') {
            // Cannot be judged either way — no training activity carries a date, so the
            // rule cannot be applied. Surfaced as its own option rather than hidden,
            // because these are exactly the records that need a data fix.
            $where .= " AND NOT EXISTS (
                SELECT 1 FROM {local_rtocompliance_enrolments} en
                  JOIN {local_rtocompliance_students} s2 ON s2.id = en.studentid
                 WHERE s2.userid = u.id AND $dated)";
        }
    }

    // USI-CAT-COURSE-FILTER (v6.2.32): scope by Moodle enrolment. A specific course wins;
    // otherwise a category filters to that category AND all its descendant subcategories
    // (matched on the category path). Students are "in" a course when they hold a
    // user_enrolment on any enrol instance of that course.
    // Precedence: a specific course beats a sub-category, which beats the top-level
    // category. Each level still includes everything beneath it.
    if ($courseid > 0) {
        $where .= " AND u.id IN (
            SELECT ue.userid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE e.courseid = :ficourse)";
        $params['ficourse'] = $courseid;
    } else if ($subcatid > 0) {
        $subcat = $DB->get_record('course_categories', ['id' => $subcatid], 'id, path');
        if ($subcat) {
            $where .= " AND u.id IN (
                SELECT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE cc.id = :fisub OR " . $DB->sql_like('cc.path', ':fisubpath') . ")";
            $params['fisub'] = $subcatid;
            $params['fisubpath'] = $subcat->path . '/%';
        }
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

// SCOPED-STATS (v6.3.0): the stat cards used to come from
// usi_verification_service::get_verification_stats(), which counts the WHOLE site and
// ignores the Category / Course / Search scope — so the cards never moved when the
// admin filtered, and "Not yet verified" silently included every student with no USI
// at all (it counted usiverified = 0 outright), which is why that card could read
// higher than "Students with a USI".
//
// This closure recomputes all seven buckets in a single grouped query, inside the
// SAME scope as the table (category / course / search), and deliberately ignores the
// status filter — each card IS a status filter, so a card must keep showing its own
// total while another status is selected. COUNT(CASE WHEN ...) is portable across
// MySQL/MariaDB, PostgreSQL and SQL Server.
$usi_scope_counts = function (string $search, int $catid, int $courseid, string $usirule = 'all',
                              int $subcatid = 0) use ($DB, $usi_build_where) {
    list($w, $p) = $usi_build_where('all', $search, $catid, $courseid, $usirule, $subcatid);
    $sql = "SELECT
                COUNT(1) AS total,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> '' THEN 1 END) AS withusi,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified = 1 THEN 1 END) AS verified,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified IN (0, 3) THEN 1 END) AS unverified,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified = 2 THEN 1 END) AS failed,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> '' AND s.usiverified = 4 THEN 1 END) AS review,
                COUNT(CASE WHEN s.usi IS NOT NULL AND s.usi <> ''
                            AND (s.dateofbirth IS NULL OR s.dateofbirth = 0) THEN 1 END) AS missingdob,
                COUNT(CASE WHEN s.usi IS NULL OR s.usi = '' THEN 1 END) AS nousi
              FROM {user} u
              JOIN {local_rtocompliance_students} s ON s.userid = u.id
             WHERE $w";
    $rec = $DB->get_record_sql($sql, $p);
    return [
        'total'      => (int) ($rec->total ?? 0),
        'withusi'    => (int) ($rec->withusi ?? 0),
        'verified'   => (int) ($rec->verified ?? 0),
        'unverified' => (int) ($rec->unverified ?? 0),
        'failed'     => (int) ($rec->failed ?? 0),
        'review'     => (int) ($rec->review ?? 0),
        'missingdob' => (int) ($rec->missingdob ?? 0),
        'nousi'      => (int) ($rec->nousi ?? 0),
    ];
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

// ── EXPORT: CSV/PDF of the current filtered view, OR CSV of students missing a DOB ──
// All three exports run through $usi_build_where() with the SAME filter + category +
// course + search values as the on-screen table, so a download always contains exactly
// the rows the admin is looking at — every row, not just the current page.
if ($usiexport === 'csv' || $usiexport === 'nodob') {
    require_capability('moodle/site:config', $systemcontext);
    // Every export link on this page carries a sesskey — enforce it, so a bulk
    // download of student records can never be triggered by a crafted link.
    require_sesskey();

    if ($usiexport === 'nodob') {
        list($ewhere, $eparams) = $usi_build_where('missingdob', $usisearch, $usicat, $usicourse, $usirule, $usisubcat);
        $fnamebit = 'usi-missing-dob';
    } else {
        list($ewhere, $eparams) = $usi_build_where($usifilter, $usisearch, $usicat, $usicourse, $usirule, $usisubcat);
        $fnamebit = 'usi-' . $usifilter;
    }

    $esql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
                    s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE $ewhere
              ORDER BY $usiorderby";
    $rows = $DB->get_recordset_sql($esql, $eparams);

    // Filename is derived from the RTO's own name rather than a hard-coded site
    // code, so a downloaded file is identifiable on any site running the plugin.
    $fnrto = preg_replace('/[^a-z0-9]+/', '-',
        strtolower((string)(get_config('local_rtocompliance', 'rtoname') ?: 'rto')));
    $filename = trim($fnrto, '-') . '-' . $fnamebit . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 names correctly.
    fprintf($out, "\xEF\xBB\xBF");
    if ($usiexport === 'nodob') {
        // DOB-ROUND-TRIP (v6.3.0): this file IS the upload template for the
        // "Upload DOBs (CSV)" box on this page. The Date of birth column is left
        // blank for the admin to fill in as DD/MM/YYYY (YYYY-MM-DD, DD-MM-YYYY and
        // DDMMYYYY are also accepted on re-upload), and the three matching columns
        // the importer looks for — Client identifier, USI, Email — are all present
        // and must not be renamed or removed. The header is plain "Date of birth"
        // so the round trip is obvious; the importer still also accepts the older
        // "Date of birth (missing)" heading from previously downloaded files.
        fputcsv($out, ['Family name', 'Given name', 'Email', 'Client identifier', 'USI', 'Date of birth']);
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

// ── EXPORT: print-ready PDF of the current filtered view (v6.3.0) ─────────────
// Same WHERE clause as the CSV and the table, so the PDF is an auditable snapshot
// of exactly what the admin filtered to — suitable for attaching to an ASQA
// audit response or emailing to a compliance manager.
if ($usiexport === 'pdf') {
    require_capability('moodle/site:config', $systemcontext);
    require_sesskey();
    require_once($CFG->libdir . '/pdflib.php');

    list($pwhere, $pparams) = $usi_build_where($usifilter, $usisearch, $usicat, $usicourse, $usirule, $usisubcat);
    $psql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
                    s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
               FROM {user} u
               JOIN {local_rtocompliance_students} s ON s.userid = u.id
              WHERE $pwhere
              ORDER BY $usiorderby";

    // Hard cap so a whole-of-site export can never exhaust PHP memory mid-render.
    // The cap is reported inside the PDF itself — never silently truncate a
    // compliance document.
    $pdfmax = 3000;
    $ptotal = (int) $DB->count_records_sql(
        "SELECT COUNT(u.id) FROM {user} u
           JOIN {local_rtocompliance_students} s ON s.userid = u.id
          WHERE $pwhere", $pparams);
    $prows = $DB->get_records_sql($psql, $pparams, 0, $pdfmax);

    // Human-readable description of the applied filters, printed under the title.
    $filterlabels = [
        'all' => 'All students', 'withusi' => 'Students with a USI',
        'verified' => 'Verified (usi.gov.au)', 'unverified' => 'Not yet verified',
        'failed' => 'Verification failed', 'review' => 'Manual review required',
        'missingdob' => 'USI present, DOB missing', 'nousi' => 'No USI recorded',
    ];
    $scopebits = ['Status: ' . ($filterlabels[$usifilter] ?? $usifilter)];
    if ($usicourse > 0) {
        $cxr = $DB->get_record('course', ['id' => $usicourse], 'fullname');
        $scopebits[] = 'Course: ' . ($cxr ? format_string($cxr->fullname) : $usicourse);
    } else if ($usisubcat > 0) {
        $scr = $DB->get_record('course_categories', ['id' => $usisubcat], 'name');
        $scopebits[] = 'Sub-category: ' . ($scr ? format_string($scr->name) : $usisubcat) . ' (incl. everything below)';
    } else if ($usicat > 0) {
        $ccr = $DB->get_record('course_categories', ['id' => $usicat], 'name');
        $scopebits[] = 'Category: ' . ($ccr ? format_string($ccr->name) : $usicat) . ' (incl. subcategories)';
    }
    if ($usirule !== 'all') {
        $rulelabels = [
            'post'    => 'Training on/after 1 Jan 2015 (USI required)',
            'pre'     => 'Training before 1 Jan 2015 only (USI not required)',
            'undated' => 'No dated training activity',
        ];
        $scopebits[] = 'USI rule: ' . ($rulelabels[$usirule] ?? $usirule);
    }
    if ($usisearch !== '') {
        $scopebits[] = 'Search: "' . $usisearch . '"';
    }

    $rtoname = get_config('local_rtocompliance', 'rtoname') ?: format_string($SITE->fullname);
    $rtocode = get_config('local_rtocompliance', 'rtocode');

    $pdf = new pdf('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Moodle / RTO Compliance');
    $pdf->SetAuthor($rtoname);
    $pdf->SetTitle('USI verification report');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();

    $head = '<h1 style="font-size:15pt;margin:0;">USI verification report</h1>'
        . '<p style="font-size:9pt;color:#444;margin:2px 0 0;">'
        . htmlspecialchars($rtoname, ENT_QUOTES)
        . ($rtocode ? ' &nbsp;·&nbsp; RTO code ' . htmlspecialchars($rtocode, ENT_QUOTES) : '')
        . ' &nbsp;·&nbsp; Generated ' . userdate(time(), '%d/%m/%Y %H:%M')
        . '</p>'
        . '<p style="font-size:9pt;color:#444;margin:2px 0 6px;">'
        // Escape each part, THEN join with the HTML separator — escaping the joined
        // string would print the separator entity literally.
        . implode(' &nbsp;|&nbsp; ', array_map(function ($b) {
            return htmlspecialchars($b, ENT_QUOTES);
        }, $scopebits))
        . ' &nbsp;|&nbsp; ' . $ptotal . ' student(s) matched'
        . ($ptotal > $pdfmax ? ' — first ' . $pdfmax . ' shown in this PDF (use the CSV export for the full list)' : '')
        . '</p>';
    $pdf->writeHTML($head, true, false, false, false, '');

    $tbl = '<table border="0.4" cellpadding="3" style="font-size:7.6pt;">'
        . '<thead><tr style="background-color:#f1f5f9;font-weight:bold;">'
        . '<th width="17%">Name</th><th width="22%">Email</th><th width="9%">Client ID</th>'
        . '<th width="12%">USI</th><th width="11%">Date of birth</th>'
        . '<th width="17%">USI status</th><th width="12%">Verified date</th>'
        . '</tr></thead><tbody>';
    foreach ($prows as $r) {
        $dob = (!empty($r->dateofbirth) && (int) $r->dateofbirth > 0)
            ? userdate((int) $r->dateofbirth, '%d/%m/%Y') : 'MISSING';
        $vdate = (!empty($r->usiverifieddate) && (int) $r->usiverifieddate > 0)
            ? userdate((int) $r->usiverifieddate, '%d/%m/%Y') : '-';
        $tbl .= '<tr>'
            . '<td>' . htmlspecialchars(trim($r->lastname . ', ' . $r->firstname), ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars((string) $r->email, ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars((string) $r->clientid, ENT_QUOTES) . '</td>'
            . '<td>' . htmlspecialchars((string) $r->usi, ENT_QUOTES) . '</td>'
            . '<td>' . $dob . '</td>'
            . '<td>' . htmlspecialchars($usi_status_label($r->usiverified, $r->usi), ENT_QUOTES) . '</td>'
            . '<td>' . $vdate . '</td>'
            . '</tr>';
    }
    $tbl .= '</tbody></table>';
    $pdf->writeHTML($tbl, true, false, false, false, '');

    $filename = 'usi-report-' . ($usifilter !== '' ? $usifilter : 'all') . '-' . date('Ymd-His') . '.pdf';
    $pdf->Output($filename, 'D');
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
    // NAME-TIEBREAK (v6.3.5): the name columns are optional, but when the sheet came from
    // "Export students without DOB" they are always present — and they are the only field
    // that can settle a shared identifier. The date of birth cannot do it: the whole point
    // of the upload is that the date of birth is the thing we do not have yet.
    $col_family = $idx['family name'] ?? $idx['lastname'] ?? $idx['last name'] ?? $idx['surname'] ?? null;
    $col_given  = $idx['given name'] ?? $idx['firstname'] ?? $idx['first name'] ?? null;

    if ($col_dob === null || ($col_client === null && $col_usi === null && $col_email === null)) {
        fclose($handle);
        redirect($redirurl,
            'CSV must have a "Date of birth" column and at least one of "Client identifier", "USI" or "Email". '
            . 'Tip: use the "Export students without DOB" download, fill in the Date of birth column, then re-upload.',
            null, \core\output\notification::NOTIFY_ERROR);
    }

    $updated = 0; $skipped = 0; $nomatch = 0; $baddate = 0; $ambiguous = 0;
    $implausible = 0; $conflict = 0;

    // AMBIGUOUS-MATCH-GUARD (v6.3.4): match on a unique identifier or not at all.
    //
    // Every lookup below previously used IGNORE_MULTIPLE, which hands back an ARBITRARY
    // row when the identifier is shared. That is not theoretical on a real site: one
    // live install has 1,576 students with no client identifier (so every one of their
    // rows falls through to email matching) and 1,323 email addresses shared by more than
    // one account — employers enrolling their staff under a single company address.
    // A shared key plus IGNORE_MULTIPLE writes a date of birth onto the WRONG student,
    // silently, while the upload still reports success. For an AVETMISS field that feeds
    // USI verification and the NAT files, that is a serious data-integrity fault.
    //
    // Now: an identifier matching more than one student is treated as unusable. The next
    // identifier is tried, and if none resolves to exactly one student the row is skipped
    // and counted as ambiguous, so the admin is told instead of misled.
    // NAME-TIEBREAK (v6.3.5): when an identifier is shared, fall back to the name on the
    // CSV row to pick the right student out of the candidates — but only when the name
    // singles out exactly one of them. Two students on the same company email with
    // genuinely different names resolve cleanly; two people with the same name on the same
    // email stay ambiguous and are skipped, which is the correct outcome.
    //
    // Names are compared case-insensitively with spaces, hyphens, apostrophes and full
    // stops removed, so "O'Brien" / "OBrien" and "Anne-Marie" / "Anne Marie" still match.
    $normalisename = function ($v) {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $v));
    };

    // Resolve a CSV row to exactly ONE student.
    //
    // $field is the column to match on ('clientid' or 'usi' on the student record, or
    // 'email' on the Moodle account). The student's name is taken from the student record
    // where it is populated and from the Moodle account otherwise — on real data the
    // AVETMISS name columns are very often empty, so without that fallback the tie-break
    // would almost never have anything to compare.
    $findstudent = function (string $field, string $value, $rowfamily, $rowgiven)
            use ($DB, &$wasambiguous, $normalisename) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $select = "SELECT s.id, s.dateofbirth,
                          CASE WHEN s.firstname IS NULL OR s.firstname = '' THEN u.firstname ELSE s.firstname END AS firstname,
                          CASE WHEN s.lastname  IS NULL OR s.lastname  = '' THEN u.lastname  ELSE s.lastname  END AS lastname
                     FROM {local_rtocompliance_students} s
                     JOIN {user} u ON u.id = s.userid
                    WHERE u.deleted = 0 AND ";
        if ($field === 'email') {
            $sql = $select . "u.email = :v";
        } else if ($field === 'usi') {
            $sql = $select . "s.usi = :v";
        } else {
            $sql = $select . "s.clientid = :v";
        }

        $rows = $DB->get_records_sql($sql, ['v' => $value], 0, 25);
        if (count($rows) === 1) {
            return reset($rows);
        }
        if (count($rows) === 0) {
            return null;
        }

        // Several candidates share this identifier — settle it on the name if we can.
        $wantfamily = $normalisename($rowfamily);
        $wantgiven  = $normalisename($rowgiven);
        if ($wantfamily !== '' || $wantgiven !== '') {
            $hits = [];
            foreach ($rows as $candidate) {
                $cf = $normalisename($candidate->lastname);
                $cg = $normalisename($candidate->firstname);
                $familyok = ($wantfamily === '') || ($cf !== '' && $cf === $wantfamily);
                $givenok  = ($wantgiven  === '') || ($cg !== '' && $cg === $wantgiven);
                if ($familyok && $givenok) {
                    $hits[] = $candidate;
                }
            }
            if (count($hits) === 1) {
                return reset($hits);
            }
        }

        // Still not certain — refuse to guess.
        $wasambiguous = true;
        return null;
    };

    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) { continue; } // blank line
        $dobstr = $col_dob !== null ? ($row[$col_dob] ?? '') : '';
        $ts = $parsedob($dobstr);
        if ($ts <= 0) { $baddate++; continue; }

        // Locate the student — clientid, then USI, then email. Unique matches only,
        // with the name on the row used to settle a shared identifier.
        $stud = null;
        $wasambiguous = false;
        $rowfamily = ($col_family !== null) ? ($row[$col_family] ?? '') : '';
        $rowgiven  = ($col_given  !== null) ? ($row[$col_given]  ?? '') : '';

        if ($col_client !== null) {
            $stud = $findstudent('clientid', (string) ($row[$col_client] ?? ''), $rowfamily, $rowgiven);
        }
        if (!$stud && $col_usi !== null) {
            $stud = $findstudent('usi', (string) ($row[$col_usi] ?? ''), $rowfamily, $rowgiven);
        }
        if (!$stud && $col_email !== null) {
            $stud = $findstudent('email', (string) ($row[$col_email] ?? ''), $rowfamily, $rowgiven);
        }

        if (!$stud) {
            // Distinguish "nobody has this identifier" from "several students do" —
            // they need completely different follow-up from the administrator.
            if ($wasambiguous) { $ambiguous++; } else { $nomatch++; }
            continue;
        }

        // PLAUSIBILITY GATE (v6.3.5): $parsedob() only checks the date is well formed, so
        // a typo in the year still produces a perfectly valid timestamp. A date of birth
        // that is in the future, or that makes the student under 10 or over 100, is a data
        // entry error rather than a fact — and once written it flows into USI verification
        // and the NAT00080 file. Reject it and say so instead of storing it.
        $ageyears = (int) floor((time() - $ts) / (365.25 * 86400));
        if ($ts > time() || $ageyears < 10 || $ageyears > 100) {
            $implausible++;
            continue;
        }

        if (!empty($stud->dateofbirth) && (int) $stud->dateofbirth > 0) {
            // Already has one. Report a DIFFERENT date separately from a matching one:
            // a conflict means either the sheet or the record is wrong and a human has to
            // decide, whereas a repeat of the same date is a harmless re-upload.
            if ((int) $stud->dateofbirth !== $ts) { $conflict++; } else { $skipped++; }
            continue;
        }

        $DB->update_record('local_rtocompliance_students',
            (object) ['id' => $stud->id, 'dateofbirth' => $ts, 'timemodified' => time()]);
        $updated++;
    }
    fclose($handle);

    $msg = "DOB upload complete: {$updated} updated.";
    $extra = [];
    if ($skipped > 0)     { $extra[] = "{$skipped} already had the same DOB"; }
    if ($conflict > 0)    { $extra[] = "{$conflict} already had a DIFFERENT DOB on file and were left "
                                     . "untouched — check which one is right before overwriting"; }
    if ($nomatch > 0)     { $extra[] = "{$nomatch} not matched to a student"; }
    if ($baddate > 0)     { $extra[] = "{$baddate} had an unreadable date"; }
    if ($implausible > 0) { $extra[] = "{$implausible} had a date that is not believable (in the future, "
                                     . "or an age under 10 or over 100) and were rejected"; }
    if ($ambiguous > 0) {
        $extra[] = "{$ambiguous} SKIPPED because the client ID, USI or email matched more "
            . "than one student — these were NOT written, because guessing which student "
            . "the date belongs to would corrupt the record. Give those rows a unique "
            . "client identifier and upload them again";
    }
    if ($extra) { $msg .= ' (' . implode('; ', $extra) . ').'; }
    // An ambiguous row is a data-integrity problem, not a routine skip — never let it
    // hide behind a green success notice.
    $msgtype = ($ambiguous > 0 || $conflict > 0 || $implausible > 0)
        ? \core\output\notification::NOTIFY_WARNING
        : ($updated > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    redirect($redirurl, $msg, null, $msgtype);
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

    $orgid = trim((string) optional_param('certorgid', '', PARAM_ALPHANUMEXT));
    if ($orgid === '') {
        $orgid = (string) (get_config('local_rtocompliance', 'usi_organization_id')
            ?: get_config('local_rtocompliance', 'rtocode') ?: '');
    }
    $certpass    = (string) optional_param('certpass', '', PARAM_RAW);  // pipeline-ignore: PARAM_RAW — a password may legitimately contain any character, so no narrower type is safe
    $certtestmode = (optional_param('certenv', 'prod', PARAM_ALPHA) === 'test');
    $notifemail  = trim((string) optional_param('certemail', '', PARAM_TEXT));

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
// USI STUDENT DASHBOARD — coverage, actions, scoped stat cards, filter toolbar
// and the student table.  (v6.2.17, redesigned v6.3.0)
//
// v6.3.0 changes:
//  • Stat cards are now SCOPED — they recount inside the current Category /
//    Course / Search selection instead of always showing whole-of-site totals,
//    and they are rendered directly above the filter toolbar (previously they
//    sat above the DOB banner, far away from the controls they drive).
//  • Every card is a one-click status filter with a visible active state.
//  • New: Clear filters, active-filter chips, per-page selector (25/50/100/200),
//    sortable column headers, PDF export, and a proper empty state.
//  • The Course dropdown is rebuilt (options added/removed, not just hidden) so
//    it genuinely narrows to the selected Category in every browser.
// ═══════════════════════════════════════════════════════════════════════════

// ── Scoped counts (category / course / search aware) ─────────────────────────
$scope = $usi_scope_counts($usisearch, $usicat, $usicourse, $usirule, $usisubcat);

// Filtered result set + total, computed HERE rather than just before the table, because
// the download row inside the filter card states how many rows the export will contain
// and therefore needs the count before the table is rendered.
list($twhere, $tparams) = $usi_build_where($usifilter, $usisearch, $usicat, $usicourse, $usirule, $usisubcat);
$usitotal = (int) $DB->count_records_sql(
    "SELECT COUNT(u.id)
       FROM {user} u
       JOIN {local_rtocompliance_students} s ON s.userid = u.id
      WHERE $twhere", $tparams);
// The DOB banner stays whole-of-site: it is a data-quality alert, not a view.
$nmissingdob_all = (int) $DB->count_records_select('local_rtocompliance_students',
    "usi IS NOT NULL AND usi <> '' AND (dateofbirth IS NULL OR dateofbirth = 0)");

$usiscoped   = ($usicat > 0 || $usisubcat > 0 || $usicourse > 0 || $usisearch !== '' || $usirule !== 'all');
$usihasfilter = ($usiscoped || $usifilter !== 'all');

// Friendly names for the current scope (used in chips and the coverage note).
$usicatname = '';
if ($usicat > 0 && ($cc = $DB->get_record('course_categories', ['id' => $usicat], 'id, name'))) {
    $usicatname = format_string($cc->name);
}
$usisubcatname = '';
if ($usisubcat > 0 && ($sc = $DB->get_record('course_categories', ['id' => $usisubcat], 'id, name'))) {
    $usisubcatname = format_string($sc->name);
}
$usicoursename = '';
if ($usicourse > 0 && ($cx = $DB->get_record('course', ['id' => $usicourse], 'id, fullname'))) {
    $usicoursename = format_string($cx->fullname);
}

// ── URL builder: keeps the whole view state (filter + scope + sort + paging) ──
$usi_url = function (array $overrides = []) use ($usifilter, $usisearch, $usicat, $usicourse,
                                                 $usiperpage, $usisort, $usidir, $usirule, $usisubcat) {
    $params = array_merge([
        'usifilter'  => $usifilter,
        'usisearch'  => $usisearch,
        'usicat'     => $usicat,
        'usisubcat'  => $usisubcat,
        'usicourse'  => $usicourse,
        'usirule'    => $usirule,
        'usiperpage' => $usiperpage,
        'usisort'    => $usisort,
        'usidir'     => $usidir,
    ], $overrides);
    // Drop defaults so shared/bookmarked URLs stay readable.
    if (($params['usifilter'] ?? 'all') === 'all')   { unset($params['usifilter']); }
    if (($params['usirule'] ?? 'all') === 'all')     { unset($params['usirule']); }
    if (trim((string) ($params['usisearch'] ?? '')) === '') { unset($params['usisearch']); }
    if ((int) ($params['usicat'] ?? 0) === 0)        { unset($params['usicat']); }
    if ((int) ($params['usisubcat'] ?? 0) === 0)     { unset($params['usisubcat']); }
    if ((int) ($params['usicourse'] ?? 0) === 0)     { unset($params['usicourse']); }
    if ((int) ($params['usiperpage'] ?? 50) === 50)  { unset($params['usiperpage']); }
    if (($params['usisort'] ?? 'name') === 'name' && ($params['usidir'] ?? 'ASC') === 'ASC') {
        unset($params['usisort'], $params['usidir']);
    }
    return new moodle_url('/local/rtocompliance/usi_settings.php', $params);
};

// ── Page-scoped styles for the dashboard (kept here so the block is portable) ─
echo '<style>
.rtoc-usi-wrap{--usi-line:#e5e7eb;--usi-ink:#0f172a;--usi-mute:#64748b;}
.rtoc-usi-card{background:#fff;border:1px solid var(--usi-line);border-radius:10px;}
.rtoc-usi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:10px;margin:0 0 14px;}
.rtoc-usi-stat{display:block;text-decoration:none;background:#fff;border:1px solid var(--usi-line);
  border-left:4px solid #cbd5e1;border-radius:10px;padding:12px 14px;transition:box-shadow .15s,transform .15s,border-color .15s;}
.rtoc-usi-stat:hover{box-shadow:0 4px 14px rgba(15,23,42,.10);transform:translateY(-1px);text-decoration:none;}
.rtoc-usi-stat .n{font-size:23px;font-weight:700;line-height:1.1;}
.rtoc-usi-stat .l{font-size:12.5px;color:#475569;margin-top:2px;}
.rtoc-usi-stat.is-active{box-shadow:0 0 0 2px rgba(37,99,235,.35);background:#f8fbff;}
.rtoc-usi-stat.is-active .l{font-weight:600;color:#1d4ed8;}
.rtoc-usi-toolbar{padding:14px 16px;margin-bottom:14px;}
.rtoc-usi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:end;}
.rtoc-usi-grid label{display:block;font-weight:600;font-size:12px;color:#334155;margin:0 0 4px;
  text-transform:uppercase;letter-spacing:.03em;}
.rtoc-usi-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;
  padding-top:12px;border-top:1px dashed var(--usi-line);}
.rtoc-usi-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 12px;}
.rtoc-usi-chip{display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1px solid #bfdbfe;
  color:#1e40af;border-radius:999px;padding:3px 10px;font-size:12px;text-decoration:none;}
.rtoc-usi-chip b{font-weight:600;}
.rtoc-usi-chip .x{font-weight:700;opacity:.65;}
.rtoc-usi-chip:hover{background:#dbeafe;text-decoration:none;color:#1e3a8a;}
.rtoc-usi-tablewrap{overflow:auto;max-height:70vh;border:1px solid var(--usi-line);border-radius:10px;background:#fff;}
.rtoc-usi-table{width:100%;border-collapse:separate;border-spacing:0;margin:0;font-size:13.5px;}
.rtoc-usi-table thead th{position:sticky;top:0;z-index:2;background:#f8fafc;text-align:left;
  padding:10px 12px;border-bottom:1px solid var(--usi-line);white-space:nowrap;font-size:12px;
  text-transform:uppercase;letter-spacing:.03em;color:#475569;}
.rtoc-usi-table thead th a{color:#334155;text-decoration:none;display:inline-flex;gap:4px;align-items:center;}
.rtoc-usi-table thead th a:hover{color:#1d4ed8;}
.rtoc-usi-table tbody td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.rtoc-usi-table tbody tr:nth-child(even){background:#fcfdff;}
.rtoc-usi-table tbody tr:hover{background:#f1f7ff;}
.rtoc-usi-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:600;white-space:nowrap;}
.rtoc-usi-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.5px;}
.rtoc-usi-empty{text-align:center;padding:44px 20px;color:#475569;}
.rtoc-usi-empty h4{margin:0 0 6px;font-size:16px;color:#0f172a;}
.rtoc-usi-foot{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:10px;}
</style>';

echo '<div class="rtoc-usi-wrap">';

// ── Verification coverage banner (scoped) ────────────────────────────────────
$covtot = $scope['withusi'];
$covver = $scope['verified'];
$pctverified = $covtot > 0 ? round($covver / $covtot * 100, 1) : 0.0;
$barcol = $pctverified >= 90 ? '#10b981' : ($pctverified >= 50 ? '#f59e0b' : '#ef4444');
echo '<div class="rtoc-usi-card" style="padding:20px;margin-bottom:16px;">';
echo '<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;">';
echo '<h3 style="margin:0;font-size:16px;">USI verification coverage'
    . ($usiscoped ? ' <span style="font-size:12px;font-weight:500;color:#64748b;">(current filter)</span>' : '')
    . '</h3>';
echo '<div style="font-size:28px;font-weight:700;color:' . $barcol . ';">' . $pctverified
    . '<span style="font-size:13px;color:#6b7280;font-weight:500;">% verified</span></div>';
echo '</div>';
echo '<div style="height:12px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin:12px 0 6px;">';
echo '<div style="height:100%;width:' . max(0, min(100, $pctverified)) . '%;background:' . $barcol
    . ';border-radius:999px;transition:width .3s;"></div>';
echo '</div>';
echo '<div style="font-size:12.5px;color:#6b7280;">' . $covver . ' of ' . $covtot
    . ' students with a USI have been verified against the Government USI Registry';
if ($usicoursename !== '') {
    echo ' — in <strong>' . $usicoursename . '</strong>';
} else if ($usisubcatname !== '') {
    echo ' — in <strong>' . $usisubcatname . '</strong> (including everything below it)';
} else if ($usicatname !== '') {
    echo ' — in <strong>' . $usicatname . '</strong> (including subcategories)';
}
echo '.</div>';
echo '</div>';

// ── Action bar ───────────────────────────────────────────────────────────────
$svgdl = '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none">'
    . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor"'
    . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$reverifyurl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiaction' => 'reverifyall', 'sesskey' => sesskey()]);
$conntesturl = new moodle_url('/local/rtocompliance/usi_settings.php',
    ['usiaction' => 'conntest', 'sesskey' => sesskey()]);
$exportcururl   = $usi_url(['usiexport' => 'csv', 'sesskey' => sesskey()]);
$exportpdfurl   = $usi_url(['usiexport' => 'pdf', 'sesskey' => sesskey()]);
$exportnodoburl = $usi_url(['usiexport' => 'nodob', 'sesskey' => sesskey()]);
$fixdoburl      = new moodle_url('/local/rtocompliance/students.php', ['filter' => 'usimissingdob']);

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">';
echo html_writer::link($conntesturl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Run connection test',
    ['class' => 'btn btn-info btn-sm',
     'title' => 'Verify a dummy record to confirm the platform + usi.gov.au link is working (a "no match" result means it works)']);
echo html_writer::link($reverifyurl,
    '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>Re-verify all students',
    ['class' => 'btn btn-primary btn-sm',
     'title' => 'Queue every student with a USI and date of birth for re-verification on the next scheduled USI run']);
echo html_writer::link($exportcururl, $svgdl . 'Export this view (CSV)',
    ['class' => 'btn btn-outline-secondary btn-sm',
     'title' => 'Download every student currently matched by the filters (not just this page) as a CSV']);
echo html_writer::link($exportpdfurl, $svgdl . 'Export this view (PDF)',
    ['class' => 'btn btn-outline-secondary btn-sm',
     'title' => 'Download every student currently matched by the filters as a print-ready PDF report']);
echo html_writer::link($exportnodoburl, $svgdl . 'Export students without DOB',
    ['class' => 'btn btn-outline-warning btn-sm',
     'title' => 'Download the students who have a USI but no date of birth — these can never be verified until a DOB is added']);
echo html_writer::link($fixdoburl, 'Fix missing DOBs →',
    ['class' => 'btn btn-link btn-sm',
     'title' => 'Go to Student Records to backfill dates of birth from your NAT00080 file']);
echo '</div>';

// ── Missing-DOB alert + round-trip CSV upload (whole-of-site count) ──────────
if ($nmissingdob_all > 0) {
    $uploaddoburl = (new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false);
    echo '<div class="alert alert-warning" style="padding:12px 14px;font-size:13px;margin-bottom:16px;">';
    echo '<strong>' . $nmissingdob_all . ' student(s) have a USI but no date of birth</strong>'
        . ' — the USI Registry cannot verify them until a DOB is recorded.';
    echo '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">';

    // Step 1 — download the pre-filled template (this IS the correct upload format).
    echo '<div style="background:#fff;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;min-width:250px;">';
    echo '<div style="font-weight:700;font-size:12px;color:#78350f;margin-bottom:6px;">Step 1 — download the template</div>';
    echo html_writer::link($exportnodoburl, $svgdl . 'Download DOB template (CSV)',
        ['class' => 'btn btn-warning btn-sm',
         'title' => 'A CSV of every student who has a USI but no date of birth, in the exact format this page accepts back']);
    echo '<div style="font-size:11.5px;color:#78350f;margin-top:6px;line-height:1.5;">'
        . 'Pre-filled with the students missing a DOB. Keep the column headings as they are.</div>';
    echo '</div>';

    // Step 2 — re-upload the same file with the Date of birth column filled in.
    echo '<div style="background:#fff;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;">';
    echo '<div style="font-weight:700;font-size:12px;color:#78350f;margin-bottom:6px;">Step 2 — fill in the dates and upload it back</div>';
    echo '<form method="post" action="' . s($uploaddoburl) . '" enctype="multipart/form-data" '
        . 'style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
    echo '<input type="hidden" name="usiaction" value="uploaddob">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<input type="file" name="dobcsv" accept=".csv,text/csv" required class="form-control-file" style="font-size:12.5px;">';
    echo '<button type="submit" class="btn btn-warning btn-sm">Upload &amp; backfill</button>';
    echo '</form>';
    echo '<div style="font-size:11.5px;color:#78350f;margin-top:6px;line-height:1.6;">'
        . 'Required columns: <code>Date of birth</code> plus at least one of <code>Client identifier</code>, '
        . '<code>USI</code> or <code>Email</code> (matched in that order). '
        . 'Dates accepted: <strong>DD/MM/YYYY</strong>, YYYY-MM-DD, DD-MM-YYYY or DDMMYYYY. '
        . 'Column order does not matter and extra columns are ignored; only students whose DOB is currently blank are updated.'
        . '</div>';
    echo '</div>';

    // Alternative — backfill straight from an already-imported NAT00080.
    echo '<div style="font-size:12px;color:#78350f;max-width:260px;line-height:1.6;">'
        . '<strong>No spreadsheet?</strong> Use <a href="' . s($fixdoburl->out(false)) . '">Fix missing DOBs</a> '
        . 'to backfill automatically from your uploaded NAT00080 file instead.'
        . '</div>';

    echo '</div></div>';
}

// ── Scoped stat cards — each one is a status filter ─────────────────────────
$cards = [
    ['All students',              $scope['total'],      '#334155', 'all',
     'Every student profile in the current category / course / search scope.'],
    ['Students with a USI',       $scope['withusi'],    '#0f172a', 'withusi',
     'Students who have a USI recorded (verified or not).'],
    ['Verified',                  $scope['verified'],   '#10b981', 'verified',
     'Confirmed against the Government USI Registry.'],
    ['Not yet verified',          $scope['unverified'], '#6b7280', 'unverified',
     'A USI is on file but it has not been confirmed with the registry yet.'],
    ['Verification failed',       $scope['failed'],     '#ef4444', 'failed',
     'The registry rejected the USI + name + date of birth combination.'],
    ['Manual review',             $scope['review'],     '#f59e0b', 'review',
     'The registry returned a partial match that a person needs to check.'],
    ['USI present, DOB missing',  $scope['missingdob'], '#b45309', 'missingdob',
     'Cannot be verified until a date of birth is recorded.'],
    ['No USI recorded',           $scope['nousi'],      '#64748b', 'nousi',
     'No USI on file — results cannot be reported and certificates cannot be issued.'],
];
echo '<div class="rtoc-usi-cards">';
foreach ($cards as $c) {
    $isactive = ($usifilter === $c[3]);
    $curl = $usi_url(['usifilter' => $c[3], 'usipage' => 0]);
    echo '<a href="' . s($curl->out(false)) . '" class="rtoc-usi-stat' . ($isactive ? ' is-active' : '') . '"'
        . ' style="border-left-color:' . $c[2] . ';" title="' . s($c[4]) . '">';
    echo '<div class="n" style="color:' . $c[2] . ';">' . $c[1] . '</div>';
    echo '<div class="l">' . s($c[0]) . '</div>';
    echo '</a>';
}
echo '</div>';

// ── Filter toolbar ───────────────────────────────────────────────────────────
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
// SUBCATEGORY-FILTER (v6.3.3): three levels, not two.
//   Category     — top-level categories only (depth 1)
//   Sub-category — every category beneath the selected one, at any depth, indented
//   Course       — narrowed by whichever of the two is selected
// Each option carries its category path so the cascade can be done client-side using
// exactly the same containment rule the server uses.
//
// ESCAPING (v6.3.3): format_string() already converts a bare "&" to "&amp;", so wrapping
// it in s() escaped it a SECOND time and printed a literal "&amp;" on screen — visible in
// every category and course name containing an ampersand. Category and course names are
// therefore emitted through format_string() alone; s() is reserved for raw values and for
// attribute contexts, where format_string() does not escape quotes.
$usi_allcats = $DB->get_records('course_categories', null, 'path', 'id, name, depth, parent, path');
$usi_topcats = [];
$usi_submenu = [];
foreach ($usi_allcats as $cc) {
    if ((int) $cc->depth === 1) {
        $usi_topcats[$cc->id] = format_string($cc->name);
    } else {
        $usi_submenu[$cc->id] = [
            'label' => str_repeat('— ', max(0, (int) $cc->depth - 2)) . format_string($cc->name),
            'path'  => (string) $cc->path,
        ];
    }
}
// Each course carries its category path so the Course dropdown can be narrowed to the
// selected Category (and every subcategory below it) client-side, matching the
// server-side scope exactly.
$usi_coursemeta = $DB->get_records_sql(
    "SELECT c.id, c.fullname, cc.path AS catpath
       FROM {course} c
       JOIN {course_categories} cc ON cc.id = c.category
      WHERE c.id <> :siteid
   ORDER BY c.fullname",
    ['siteid' => SITEID]
);

echo '<div class="rtoc-usi-card rtoc-usi-toolbar">';
echo '<form method="get" action="" id="rtoc-usi-filterform">';
// Sort state travels with the search so filtering never silently resets the sort.
echo '<input type="hidden" name="usisort" value="' . s($usisort) . '">';
echo '<input type="hidden" name="usidir" value="' . s($usidir) . '">';
echo '<div class="rtoc-usi-grid">';

echo '<div><label for="usifilter">USI status</label><select name="usifilter" id="usifilter" class="form-control">';
foreach ($filteropts as $k => $lbl) {
    echo '<option value="' . $k . '"' . ($usifilter === $k ? ' selected' : '') . '>' . s($lbl) . '</option>';
}
echo '</select></div>';

echo '<div><label for="usicat">Category</label><select name="usicat" id="usicat" class="form-control">';
echo '<option value="0">All categories</option>';
foreach ($usi_topcats as $cid => $cname) {
    echo '<option value="' . (int) $cid . '"' . ((int) $usicat === (int) $cid ? ' selected' : '') . '>'
        . $cname . '</option>';
}
echo '</select></div>';

echo '<div><label for="usisubcat">Sub-category</label>'
    . '<select name="usisubcat" id="usisubcat" class="form-control">';
echo '<option value="0" data-catpath="">All sub-categories</option>';
foreach ($usi_submenu as $sid => $sm) {
    echo '<option value="' . (int) $sid . '" data-catpath="' . s($sm['path']) . '"'
        . ((int) $usisubcat === (int) $sid ? ' selected' : '') . '>' . $sm['label'] . '</option>';
}
echo '</select></div>';

echo '<div><label for="usicourse">Course</label><select name="usicourse" id="usicourse" class="form-control">';
echo '<option value="0" data-catpath="">All courses</option>';
foreach ($usi_coursemeta as $co) {
    echo '<option value="' . (int) $co->id . '" data-catpath="' . s($co->catpath) . '"'
        . ((int) $usicourse === (int) $co->id ? ' selected' : '') . '>' . format_string($co->fullname) . '</option>';
}
echo '</select></div>';

// USI-RULE-DATE-FILTER (v6.3.1): the USI became mandatory for nationally recognised
// training on 1 January 2015. Combining this with the "No USI recorded" status filter is
// the whole point of it — it turns a bucket that is mostly historical students into the
// list that actually needs chasing.
$usiruleopts = [
    'all'     => 'All students (ignore the rule)',
    'post'    => 'Training on/after 1 Jan 2015 — USI required',
    'pre'     => 'Training before 1 Jan 2015 only — not required',
    'undated' => 'No dated training activity — cannot tell',
];
echo '<div><label for="usirule">USI rule (1 Jan 2015)</label>'
    . '<select name="usirule" id="usirule" class="form-control"'
    . ' title="The USI became mandatory for nationally recognised training on 1 January 2015. '
    . 'Judged on the AVETMISS training activity start/end dates, not the student&#39;s age or record date.">';
foreach ($usiruleopts as $k => $lbl) {
    echo '<option value="' . $k . '"' . ($usirule === $k ? ' selected' : '') . '>' . s($lbl) . '</option>';
}
echo '</select></div>';

echo '<div><label for="usisearch">Search</label>'
    . '<input type="text" name="usisearch" id="usisearch" class="form-control" '
    . 'placeholder="Name, email, USI or client ID" value="' . s($usisearch) . '"></div>';

echo '<div><label for="usiperpage">Show</label><select name="usiperpage" id="usiperpage" class="form-control">';
foreach ($usiperpageopts as $pp) {
    echo '<option value="' . $pp . '"' . ($usiperpage === $pp ? ' selected' : '') . '>' . $pp . ' per page</option>';
}
echo '</select></div>';
echo '</div>'; // grid

echo '<div class="rtoc-usi-actions">';
echo '<button type="submit" class="btn btn-primary btn-sm">'
    . '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
    . 'Apply filters</button>';
if ($usihasfilter) {
    echo html_writer::link(new moodle_url('/local/rtocompliance/usi_settings.php'),
        '<svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none"><path d="M18 6 6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Clear filters',
        ['class' => 'btn btn-outline-secondary btn-sm', 'title' => 'Reset the status, category, course and search back to defaults']);
} else {
    echo '<span style="font-size:12px;color:#94a3b8;">No filters applied — showing every student.</span>';
}
echo '<span style="margin-left:auto;font-size:12.5px;color:#475569;" id="rtoc-usi-coursehint"></span>';
echo '</div>';
echo '</form>';

// DOWNLOAD-ROW (v6.3.6): the export links also live in the action bar higher up the page,
// but that bar is a row of theme .btn elements — and a theme print stylesheet (or any rule
// that hides .btn) makes them vanish, which is exactly what happened on a live site: the
// admin could not find the CSV export at all.
//
// These duplicates sit INSIDE the filter card, immediately under the filters they act on,
// which is where an admin looks for them anyway. They are styled entirely with inline
// rules and carry no .btn class, so no theme stylesheet can hide or restyle them, and the
// row states the exact number of rows the download will contain so there is no doubt that
// it follows the filters rather than the current page.
echo '<div style="margin-top:12px;padding-top:12px;border-top:1px dashed #e5e7eb;'
    . 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
echo '<span style="font-size:13px;color:#334155;font-weight:600;">Download these results:</span>';

$dlstyle = 'display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;'
    . 'font-size:13px;font-weight:600;text-decoration:none;border:1px solid #0f6cbf;'
    . 'background:#0f6cbf;color:#ffffff;line-height:1.2;';
$dlstyle2 = 'display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;'
    . 'font-size:13px;font-weight:600;text-decoration:none;border:1px solid #0f6cbf;'
    . 'background:#ffffff;color:#0f6cbf;line-height:1.2;';

echo '<a href="' . s($exportcururl->out(false)) . '" style="' . $dlstyle . '"'
    . ' title="Download every student matching the filters above — all pages, not just this one">'
    . '&#11015; CSV (Excel)</a>';
echo '<a href="' . s($exportpdfurl->out(false)) . '" style="' . $dlstyle2 . '"'
    . ' title="Download the same list as a print-ready PDF report">'
    . '&#11015; PDF</a>';
echo '<span style="font-size:12.5px;color:#64748b;">'
    . number_format($usitotal) . ' row' . ($usitotal === 1 ? '' : 's')
    . ' will be downloaded — every match, not just this page.</span>';
echo '</div>';

echo '</div>';

// ── Active filter chips (each chip removes just that one filter) ─────────────
if ($usihasfilter) {
    echo '<div class="rtoc-usi-chips">';
    echo '<span style="font-size:12px;color:#64748b;font-weight:600;">Active filters:</span>';
    if ($usifilter !== 'all') {
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usifilter' => 'all', 'usipage' => 0])->out(false)) . '">'
            . '<b>Status:</b> ' . s($filteropts[$usifilter] ?? $usifilter) . ' <span class="x">×</span></a>';
    }
    if ($usicat > 0) {
        // $usicatname already came through format_string(), which escapes '&' — do NOT
        // pass it through s() as well or an ampersand prints as a literal "&amp;".
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usicat' => 0, 'usisubcat' => 0, 'usipage' => 0])->out(false)) . '">'
            . '<b>Category:</b> ' . $usicatname . ' <span class="x">×</span></a>';
    }
    if ($usisubcat > 0) {
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usisubcat' => 0, 'usipage' => 0])->out(false)) . '">'
            . '<b>Sub-category:</b> ' . $usisubcatname . ' <span class="x">×</span></a>';
    }
    if ($usicourse > 0) {
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usicourse' => 0, 'usipage' => 0])->out(false)) . '">'
            . '<b>Course:</b> ' . $usicoursename . ' <span class="x">×</span></a>';
    }
    if ($usirule !== 'all') {
        $rulechip = [
            'post'    => 'Training on/after 1 Jan 2015',
            'pre'     => 'Training before 1 Jan 2015 only',
            'undated' => 'No dated training activity',
        ];
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usirule' => 'all', 'usipage' => 0])->out(false)) . '">'
            . '<b>USI rule:</b> ' . s($rulechip[$usirule] ?? $usirule) . ' <span class="x">×</span></a>';
    }
    if ($usisearch !== '') {
        echo '<a class="rtoc-usi-chip" href="' . s($usi_url(['usisearch' => '', 'usipage' => 0])->out(false)) . '">'
            . '<b>Search:</b> "' . s($usisearch) . '" <span class="x">×</span></a>';
    }
    echo '<a class="rtoc-usi-chip" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;" href="'
        . s((new moodle_url('/local/rtocompliance/usi_settings.php'))->out(false)) . '">Clear all</a>';
    echo '</div>';
}

// ── Dynamic Sub-category and Course dropdowns (v6.3.3) ──────────────────────
// Three-level cascade: Category narrows Sub-category, and both narrow Course.
// Options are REBUILT (nodes removed and re-added) rather than hidden — several
// browsers ignore the hidden attribute on an <option> inside a native <select>,
// which is why picking a category previously appeared to do nothing.
// Containment is tested on the category path, exactly as the server does it.
echo html_writer::script('
(function () {
    var cat    = document.getElementById("usicat");
    var sub    = document.getElementById("usisubcat");
    var course = document.getElementById("usicourse");
    var hint   = document.getElementById("rtoc-usi-coursehint");
    if (!cat || !sub || !course) { return; }

    function snapshot(sel) {
        return Array.prototype.map.call(sel.options, function (o) {
            var p = String(o.getAttribute("data-catpath") || "").replace(/^\/+|\/+$/g, "");
            return {value: o.value, text: o.text, path: "/" + p + "/"};
        });
    }
    var allSubs    = snapshot(sub);
    var allCourses = snapshot(course);

    function contains(path, id) {
        return id === "0" || id === "" || path.indexOf("/" + id + "/") !== -1;
    }

    function fill(sel, items, keep, emptyText, allText) {
        var want = keep ? String(sel.value || "0") : "0";
        while (sel.options.length) { sel.remove(0); }
        var first = document.createElement("option");
        first.value = "0";
        sel.appendChild(first);
        var shown = 0;
        for (var i = 1; i < items.length; i++) {
            var el = document.createElement("option");
            el.value = items[i].value;
            el.text  = items[i].text;
            sel.appendChild(el);
            shown++;
        }
        first.text = shown ? allText : emptyText;
        sel.disabled = (shown === 0);
        sel.value = want;
        if (!sel.value) { sel.value = "0"; }
        return shown;
    }

    function apply(keep) {
        var cid = String(cat.value || "0");

        // Sub-categories that sit beneath the chosen category.
        var subs = [allSubs[0]].concat(allSubs.slice(1).filter(function (o) {
            return contains(o.path, cid);
        }));
        var nsubs = fill(sub, subs, keep, "No sub-categories here", "All sub-categories");

        // Courses beneath the chosen sub-category, or the whole category if none chosen.
        var sid = String(sub.value || "0");
        var scope = (sid !== "0" && sid !== "") ? sid : cid;
        var courses = [allCourses[0]].concat(allCourses.slice(1).filter(function (o) {
            return contains(o.path, scope);
        }));
        var ncourses = fill(course, courses, keep, "No courses here", "All courses in this scope");

        if (hint) {
            if (cid === "0" || cid === "") {
                hint.textContent = "";
            } else {
                hint.textContent = ncourses + " course" + (ncourses === 1 ? "" : "s")
                    + (nsubs ? " in " + nsubs + " sub-categor" + (nsubs === 1 ? "y" : "ies") : " in this category");
            }
        }
    }

    cat.addEventListener("change", function () { sub.value = "0"; apply(false); });
    sub.addEventListener("change", function () { apply(false); });
    apply(true);
})();
');

// ── Query + render the table ─────────────────────────────────────────────────
// Never leave the admin stranded on an out-of-range page after tightening a filter.
$usimaxpage = ($usitotal > 0) ? (int) ceil($usitotal / $usiperpage) - 1 : 0;
if ($usipage > $usimaxpage) {
    $usipage = max(0, $usimaxpage);
}

$listsql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
                   s.clientid, s.usi, s.usiverified, s.usiverifieddate, s.dateofbirth
              FROM {user} u
              JOIN {local_rtocompliance_students} s ON s.userid = u.id
             WHERE $twhere
             ORDER BY $usiorderby";
$rows = $DB->get_records_sql($listsql, $tparams, $usipage * $usiperpage, $usiperpage);

// Status badge renderer.
$usi_badge = function ($code, $usi) {
    if ($usi === null || trim((string) $usi) === '') {
        return '<span class="rtoc-usi-pill" style="background:#f1f5f9;color:#64748b;">No USI</span>';
    }
    switch ((int) $code) {
        case 1: $bg = '#dcfce7'; $fg = '#166534'; $t = 'Verified'; break;
        case 2: $bg = '#fee2e2'; $fg = '#991b1b'; $t = 'Failed'; break;
        case 3: $bg = '#e0f2fe'; $fg = '#075985'; $t = 'Pending'; break;
        case 4: $bg = '#fef3c7'; $fg = '#92400e'; $t = 'Manual review'; break;
        default: $bg = '#f1f5f9'; $fg = '#475569'; $t = 'Not yet verified'; break;
    }
    return '<span class="rtoc-usi-pill" style="background:' . $bg . ';color:' . $fg . ';">' . $t . '</span>';
};

if (empty($rows)) {
    echo '<div class="rtoc-usi-card rtoc-usi-empty">';
    echo '<h4>No students match these filters</h4>';
    echo '<p style="margin:0 0 14px;font-size:13px;">'
        . ($usihasfilter
            ? 'Try widening the category, clearing the search box, or resetting the filters.'
            : 'There are no student profiles recorded yet.')
        . '</p>';
    if ($usihasfilter) {
        echo html_writer::link(new moodle_url('/local/rtocompliance/usi_settings.php'),
            'Clear filters', ['class' => 'btn btn-primary btn-sm']);
    }
    echo '</div>';
} else {
    // Sortable header helper: click to sort, click again to flip direction.
    $sorthead = function (string $key, string $label) use ($usisort, $usidir, $usi_url) {
        $isactive = ($usisort === $key);
        $nextdir  = ($isactive && $usidir === 'ASC') ? 'DESC' : 'ASC';
        $arrow    = $isactive ? ($usidir === 'ASC' ? '▲' : '▼') : '<span style="opacity:.3">↕</span>';
        $url = $usi_url(['usisort' => $key, 'usidir' => $nextdir, 'usipage' => 0]);
        return '<th><a href="' . s($url->out(false)) . '" title="Sort by ' . s($label) . '">'
            . s($label) . ' <span style="font-size:10px;">' . $arrow . '</span></a></th>';
    };

    echo '<div class="rtoc-usi-tablewrap"><table class="rtoc-usi-table"><thead><tr>';
    echo $sorthead('name', 'Name');
    echo $sorthead('email', 'Email');
    echo $sorthead('clientid', 'Client ID');
    echo $sorthead('usi', 'USI');
    echo $sorthead('dob', 'Date of birth');
    echo $sorthead('status', 'USI status');
    echo $sorthead('vdate', 'Verified date');
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $name = s(trim($r->firstname . ' ' . $r->lastname));
        $studenturl = new moodle_url('/local/rtocompliance/student_profile.php', ['userid' => $r->userid]);
        $dob = (!empty($r->dateofbirth) && (int) $r->dateofbirth > 0)
            ? userdate((int) $r->dateofbirth, '%d/%m/%Y')
            : '<span style="color:#b45309;font-weight:600;">Missing</span>';
        $vdate = (!empty($r->usiverifieddate) && (int) $r->usiverifieddate > 0)
            ? userdate((int) $r->usiverifieddate, '%d/%m/%Y') : '—';
        $usidisp = ($r->usi !== null && trim((string) $r->usi) !== '')
            ? '<span class="rtoc-usi-mono">' . s($r->usi) . '</span>' : '—';
        echo '<tr>';
        echo '<td><a href="' . s($studenturl->out(false)) . '">' . $name . '</a></td>';
        echo '<td>' . s($r->email) . '</td>';
        echo '<td>' . s((string) $r->clientid) . '</td>';
        echo '<td>' . $usidisp . '</td>';
        echo '<td>' . $dob . '</td>';
        echo '<td>' . $usi_badge($r->usiverified, $r->usi) . '</td>';
        echo '<td>' . $vdate . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Pagination + range summary (both keep the full view state).
    $baseurl = $usi_url();
    echo '<div class="rtoc-usi-foot">';
    echo '<div style="font-size:12.5px;color:#6b7280;">Showing '
        . (min($usipage * $usiperpage + 1, $usitotal)) . '–' . min(($usipage + 1) * $usiperpage, $usitotal)
        . ' of ' . $usitotal . ' student(s)'
        . ($usihasfilter ? ' matching the current filters' : '') . '. '
        // Repeated at the foot of the table: an admin who has just paged through the
        // results is at the BOTTOM of the page, and should not have to scroll back up to
        // find the download. Inline-styled and class-free for the same reason as above.
        . '<a href="' . s($exportcururl->out(false)) . '" style="color:#0f6cbf;font-weight:600;'
        . 'text-decoration:underline;">Download all ' . number_format($usitotal) . ' as CSV</a>'
        . ' &nbsp;·&nbsp; '
        . '<a href="' . s($exportpdfurl->out(false)) . '" style="color:#0f6cbf;font-weight:600;'
        . 'text-decoration:underline;">PDF</a>'
        . '</div>';
    echo '<div>' . $OUTPUT->paging_bar($usitotal, $usipage, $usiperpage, $baseurl, 'usipage') . '</div>';
    echo '</div>';
}

echo '</div>'; // .rtoc-usi-wrap

echo $OUTPUT->footer();
