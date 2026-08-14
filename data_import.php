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
 * RTO Compliance plugin — data_import.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// AVETMISS NAT file import for local_rtocompliance.
// Parses NAT00010, NAT00080, NAT00085, NAT00120, NAT00130 fixed-width text files
// exported from Wisenet and other AVETMISS-compliant Student Management Systems.
// Stores results in local_rtocompliance_avetmiss* tables.

// DIAG-v5.9.7: Step-by-step breadcrumb logging. Each marker writes to a file in
// /tmp AND to the PHP error log. Check /tmp/rtoc_di_*.txt or PHP error log.
// Remove this block once the root cause is identified.
$_di_log = sys_get_temp_dir() . '/rtoc_di_' . date('Ymd_His') . '_' . getmypid() . '.txt';
function _di_log(string $step): void {
    global $_di_log;
    $line = date('[H:i:s] ') . $step . "\n";
    @file_put_contents($_di_log, $line, FILE_APPEND);
    error_log('[RTOC-DI] ' . $step);
}
register_shutdown_function (function () {
    $err = error_get_last();
    if ($err) {
        _di_log('SHUTDOWN fatal type=' . $err['type'] . ' msg=' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    } else {
        _di_log('SHUTDOWN normal');
    }
});
_di_log('START pid=' . getmypid() . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '?'));

require_once(__DIR__ . '/../../config.php');
_di_log('config.php loaded');
// DB fallback: write checkpoint to Moodle config for post-mortem diagnostics
// even if /tmp writes or OPcache are causing the file-based log to be missed.
try { set_config('_di_cp', json_encode(['t'=>date('c'),'pid'=>getmypid(),'step'=>'config_loaded']), 'local_rtocompliance'); } catch(\Throwable $_e) {}

require_once($CFG->libdir . '/adminlib.php');
_di_log('adminlib.php loaded');
try { set_config('_di_cp', json_encode(['t'=>date('c'),'pid'=>getmypid(),'step'=>'adminlib_loaded']), 'local_rtocompliance'); } catch(\Throwable $_e) {}

require_once(__DIR__ . '/lib.php');
_di_log('lib.php loaded');
try { set_config('_di_cp', json_encode(['t'=>date('c'),'pid'=>getmypid(),'step'=>'lib_loaded']), 'local_rtocompliance'); } catch(\Throwable $_e) {}

admin_externalpage_setup('local_rtocompliance_dataimport');
_di_log('admin_externalpage_setup done');
try { set_config('_di_cp', json_encode(['t'=>date('c'),'pid'=>getmypid(),'step'=>'setup_done']), 'local_rtocompliance'); } catch(\Throwable $_e) {}

// FIX-CONTEXT-UNDEFINED (v5.9.2): $context was never set in this file, causing a TypeError
// on PHP 8+ whenever any of the require_capability() calls below were reached (qcm_search,
// qcm_save, qcm_children). admin_externalpage_setup() sets $PAGE context internally but does
// not expose a $context variable to the caller's scope.
$context = context_system::instance();

$action         = optional_param('action',         '',         PARAM_ALPHANUMEXT);
$importid       = optional_param('importid',       0,          PARAM_INT);
$tab            = optional_param('tab',            'students', PARAM_ALPHA);
$search         = optional_param('search',         '',         PARAM_TEXT);
$autoenrol_done = optional_param('autoenrol_done', 0,          PARAM_INT);
$enrolled_count = optional_param('enrolled',       0,          PARAM_INT);
$histpage       = max(0, optional_param('histpage',       0,          PARAM_INT));

$PAGE->set_url(new moodle_url('/local/rtocompliance/data_import.php'));
$PAGE->set_title(get_string('dataimport_title', 'local_rtocompliance'));
$PAGE->set_heading(get_string('dataimport_title', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

// ─────────────────────────────────────────────────────────────────────────────
// ENROLMENT-WRITE HARD BLOCK (v5.9.387 — audit A-P1-3).
//
// This plugin must NEVER create or delete Moodle enrolments or course
// completions — it reads them as an authoritative source only. Historically
// this file contained an auto-enrolment / over-enrolment-repair wizard whose
// UI entry points were removed in v5.9.380, but whose POST handlers
// (doenrol, autoenrol, foe_apply_chunk, rollback, fix_overenrolments,
// fix_overenrolments_apply) were left in place and remained reachable by URL.
// Those handlers are the ONLY code in the whole plugin that calls
// enrol_user()/unenrol_user() or writes to {course_completions}.
//
// This guard intercepts every one of those actions BEFORE any handler runs, so
// none of that code can execute. The individual write calls downstream are ALSO
// disabled behind the RTOC_ENROL_WRITES_DISABLED constant (defense in depth) —
// see the enrol/completion call sites below. Together these guarantee the
// "no Moodle enrolments created or deleted" constraint holds absolutely.
define('RTOC_ENROL_WRITES_DISABLED', true);
// NO-CORE-WRITES (v5.9.414): audit found three more actions that still wrote to
// Moodle core tables and were NOT in this retired list — 'repairnames' (edits
// {user} firstname/lastname/email), 'backfill_records' (set_field {user}.idnumber),
// and 'hide_archive_cats' (toggles {course_categories}.visible). They are now
// blocked here too, so the plugin's guarantee — it only READS Moodle
// enrolments/completions/users/categories and writes ONLY to its own tables —
// holds with no reachable exception.
$rtoc_retired_enrol_actions = [
    'doenrol', 'autoenrol', 'foe_apply_chunk', 'rollback',
    'fix_overenrolments', 'fix_overenrolments_apply',
    'repairnames', 'backfill_records', 'hide_archive_cats',
];
if (in_array($action, $rtoc_retired_enrol_actions, true)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        'This function has been retired. To protect the integrity of your Moodle '
        . 'data, RTO Compliance no longer creates, removes or modifies Moodle '
        . 'enrolments, course completions, user accounts or categories — it only '
        . 'reads them. Import your NAT files as data (they flow into Student Results '
        . 'and the register without touching Moodle core).',
        \core\output\notification::NOTIFY_INFO
    );
    echo html_writer::link(
        new moodle_url('/local/rtocompliance/data_import.php'),
        '← Back to Data Import',
        ['class' => 'btn btn-secondary']
    );
    echo $OUTPUT->footer();
    exit;
}

// ─── AVETMISS outcome code labels ────────────────────────────────────────────

function local_rtocompliance_avetmiss_outcome_label(string $code): string {
    // FIX-OUTCOME-LABELS-5.2.13: Codes 20/30/40 were swapped. Correct AVETMISS 8.0 mapping:
    //   20 = Competency achieved/pass   30 = Competency not yet achieved   40 = Withdrawn
    $labels = [
        '10' => 'Not Yet Started',
        '20' => 'Competency Achieved',
        '30' => 'Competency Not Yet Achieved',
        '40' => 'Withdrawn',
        '41' => 'Satisfactorily Completed',
        '51' => 'RPL Granted',
        '52' => 'RPL Not Granted',
        '53' => 'RCC Granted',
        '60' => 'Credit Transfer',
        '61' => 'Credit Transfer (Advanced)',
        '70' => 'Continuing Enrolment',
        '81' => 'Non-Assessable Enrolment',
        '82' => 'Non-Assessable Enrolment – Not Satisfactorily Completed',
        '85' => 'Non-Assessable Enrolment – Satisfactorily Completed',
        '90' => 'Result Not Available',
    ];
    return $labels[$code] ?? $code;
}

// ─── NAT file parsing functions ───────────────────────────────────────────────

// NAT00080 – Client demographics (fixed-width, AVETMISS 8.0, updated Oct 2022)
// Source: AVETMISS VET Provider Collection specifications release 8.0 (NCVER).
// All positions are 1-indexed (AVETMISS convention); 0-indexed in parentheses.
//
//  STANDARD AVETMISS 8.0 LAYOUT (1-indexed, record length 327):
//   Field 1  Client Identifier                   1–10   (0–9,    10 chars)
//   Field 2  Name for Encryption                11–70   (10–69,  60 chars)
//   Field 3  Highest School Level Completed     71–72   (70–71,   2 chars)
//   Field 4  Gender                               73    (72,      1 char)  M/F/X/@
//   Field 5  Date of Birth                      74–81   (73–80,   8 chars) DDMMYYYY or @@@@@@@@
//   Field 6  Postcode                           82–85   (81–84,   4 chars)
//   Field 7  Indigenous Status Identifier         86    (85,      1 char)
//   Field 8  Language Identifier               87–90    (86–89,   4 chars)
//   Field 9  Labour Force Status Identifier    91–92    (90–91,   2 chars)
//   Field 10 Country Identifier               93–96    (92–95,   4 chars)
//   Field 11 Disability Flag                    97      (96,      1 char)
//   Field 12 Prior Ed. Achievement Flag         98      (97,      1 char)
//   Field 13 At School Flag                     99      (98,      1 char)
//   Field 14 Address – Suburb/Locality/Town  100–149   (99–148,  50 chars)
//   Field 15 Unique Student Identifier       150–159  (149–158,  10 chars)
//   Field 16 State Identifier               160–161  (159–160,   2 chars)
//   Field 17 Address Building/Property Name 162–211  (161–210,  50 chars)
//   Field 18 Address Flat/Unit Details      212–241  (211–240,  30 chars)
//   Field 19 Address Street Number         242–256  (241–255,  15 chars)
//   Field 20 Address Street Name           257–326  (256–325,  70 chars)
//   Field 21 Survey Contact Status            327     (326,      1 char)
//            Record length (national):         327
//
//  NOTE: Some SMS vendors export a shorter NAT00080 (pre-2022 / non-compliant) with:
//   - Name 50 chars instead of 60
//   - Labour Force Status 1 char instead of 2
//   - No Suburb field
//  This places the USI at different positions (e.g. pos 90, 0-indexed).
//  The auto-detect algorithm handles all formats regardless of vendor.
//
//  USI FORMAT NOTE: Australian USIs are 10 uppercase alphanumeric chars per the
//  Student Identifiers Act 2014 and AVETMISS 8.0 spec (length = 10).
//  Some SMS vendors allocate a 12-char field for USI in their export layout.
//  We therefore accept 10–12 uppercase alphanumeric chars and store whatever is found.
//  The correct validation regex is [A-Z0-9]{10,12}, NOT [A-Z][A-Z0-9]{9}.
//
/**
 * Auto-detect the USI column position in a NAT00080 file.
 *
 * Works by anchoring on the sex+DOB pair (reliably identifiable in ALL
 * AVETMISS formats) and voting on which absolute column position consistently
 * contains a 10-char uppercase-alphanumeric sequence across many records.
 *
 * The USI column receives a vote from almost every student record.
 * Suburb text (if present) is mostly space-padded, so gets far fewer votes.
 * When two positions tie on vote count AND total value diversity (e.g. when
 * the demographic flag immediately before the USI happens to be a letter),
 * first-character diversity breaks the tie: USI first chars are pseudo-random
 * across students; a demographic flag (e.g. atSchool = 'N') is always the
 * same, giving first-char diversity of 1.
 *
 * The algorithm requires no prior knowledge of the SMS vendor.  It handles
 * standard AVETMISS 8, Wisenet extended, and any other vendor format where
 * a suburb or other extra block is inserted before the USI.
 *
 * @param  string[] $sampleLines  Up to 100 raw lines from the NAT00080 file.
 * @return int                    Detected 0-indexed USI start position, or -1
 *                                if detection is inconclusive (caller falls back
 *                                to fixed-position Methods 1–3).
 */
// ─── Quote-handling helpers (v4.9.125) ───────────────────────────────────────
// Some SMS vendors export NAT files where the client-ID field is wrapped in
// quote characters.  The quote may be ASCII 0x22, Windows-1252 curly-quote
// 0x93/0x94, or UTF-8 smart quotes (U+201C/U+201D, encoded as E2 80 9C/9D).
// These helpers centralise detection and stripping so both detect_nat00080_usi_pos
// and parse_nat00080 behave consistently regardless of vendor encoding.

/**
 * Strip a leading field-delimiter quote from a NAT line.
 * Handles: ASCII " (0x22), Windows-1252 0x93/0x94, UTF-8 U+201C/U+201D/U+201E.
 */
function local_rtocompliance_strip_leading_quote(string $line): string {
    if (!isset($line[0])) return $line;
    // ASCII double-quote (most common vendor format)
    if ($line[0] === '"') return substr($line, 1);
    // Windows-1252 opening smart-quote 0x93 or closing 0x94
    if ($line[0] === "\x93" || $line[0] === "\x94") return substr($line, 1);
    // UTF-8 left/right double quotation mark (U+201C = E2 80 9C, U+201D = E2 80 9D)
    // and low-9 quotation mark (U+201E = E2 80 9E) — all 3 bytes
    if (isset($line[2]) && $line[0] === "\xE2" && $line[1] === "\x80"
            && ($line[2] === "\x9C" || $line[2] === "\x9D" || $line[2] === "\x9E")) {
        return substr($line, 3);
    }
    return $line;
}

/**
 * Find the first field-delimiter quote in $str starting at $offset.
 * Checks ASCII " (0x22), Windows-1252 0x93/0x94, and UTF-8 U+201C/U+201D/U+201E.
 * Returns the byte offset (0-indexed within $str) or false if not found.
 */
function local_rtocompliance_find_field_quote(string $str, int $offset = 0) {
    $best = false;
    // ASCII "
    $p = strpos($str, '"', $offset);
    if ($p !== false) $best = $p;
    // Windows-1252 0x93 / 0x94
    foreach (["\x93", "\x94"] as $q) {
        $p = strpos($str, $q, $offset);
        if ($p !== false && ($best === false || $p < $best)) $best = $p;
    }
    // UTF-8 3-byte smart quotes
    foreach (["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E"] as $q) {
        $p = strpos($str, $q, $offset);
        if ($p !== false && ($best === false || $p < $best)) $best = $p;
    }
    return $best;
}

/**
 * Return the byte length of the quote character at position $pos in $str.
 * Returns 3 for UTF-8 smart quotes (E2 80 9x), 1 for ASCII/Windows-1252.
 */
function local_rtocompliance_quote_byte_len(string $str, int $pos): int {
    if (!isset($str[$pos])) return 1;
    return ($str[$pos] === "\xE2" && isset($str[$pos + 2])
            && $str[$pos + 1] === "\x80"
            && in_array($str[$pos + 2], ["\x9C", "\x9D", "\x9E"], true)) ? 3 : 1;
}

// ─────────────────────────────────────────────────────────────────────────────

function local_rtocompliance_detect_nat00080_usi_pos(array $sampleLines): int {
    // FIX-NAT00080-TABDELIM (v4.9.164): Some SMS vendors export NAT00080 as
    // tab-separated values with a quoted first field containing clientid+name,
    // followed by a demographics column and a USI column.  The vote-based
    // approach below fails for this format because the USI's absolute byte
    // offset varies per record (variable-length name → different tab position).
    // Detect at least 2 tab-containing lines in the first 5 samples and return
    // the sentinel -2 so parse_nat00080() switches to the tab-split path.
    $tabLineCount = 0;
    foreach (array_slice($sampleLines, 0, min(5, count($sampleLines))) as $tl) {
        if (strpos($tl, "\t") !== false) {
            $tabLineCount++;
        }
    }
    if ($tabLineCount >= 2) {
        return -2;   // -2 = confirmed tab-delimited format; USI in field index 2
    }

    $votes   = [];
    $samples = [];   // position => [value, value, ...]

    foreach ($sampleLines as $rawLine) {
        $line   = ltrim(rtrim($rawLine, "\r"), "\xEF\xBB\xBF");
        // FIX-NAT00080-QUOTED-CSV (v4.9.122, hardened v4.9.125): Strip leading
        // field-delimiter quote — handles ASCII 0x22, Windows-1252 0x93/0x94,
        // and UTF-8 smart quotes (U+201C/U+201D/U+201E).
        $line   = local_rtocompliance_strip_leading_quote($line);
        $lineUp = strtoupper($line);
        if (strlen($lineUp) < 10) continue;

        // FIX-QUOTED-FIELD-DELIMITER (v4.9.123, hardened v4.9.125): Detect closing
        // field-delimiter quote in the name area (positions 10–59); sex+DOB search
        // starts immediately after it.  Handles all quote variants via helper.
        $quoteInLine = local_rtocompliance_find_field_quote($lineUp, 10);
        $searchFrom  = ($quoteInLine !== false && $quoteInLine < 60)
                         ? ($quoteInLine + local_rtocompliance_quote_byte_len($lineUp, $quoteInLine))
                         : 60;

        // Find the sex+DOB anchor — reliable in every AVETMISS format.
        if (!preg_match('/[MFX@][0-3]\d[0-1]\d(?:19|20)\d{2}/',
                        substr($lineUp, $searchFrom), $m, PREG_OFFSET_CAPTURE)) {
            continue;   // DOB not stated or unparseable; skip
        }
        $dobEnd = $searchFrom + $m[0][1] + 9;

        // Scan +15 to +90 chars past DOB-end and vote for every position
        // that holds a valid 10-char USI value.
        // USI-CHARSET (v4.9.156): Use the official USI character set [2-9A-HJ-NP-Z]
        // which excludes 0, 1, I, O — per the USI specification. This avoids false
        // positives from client IDs, dates, and other fixed-width fields that contain
        // digits 0/1 or letters I/O. The stricter regex drastically reduces noise and
        // makes the winning column unambiguous for any SMS vendor format.
        $maxPos = min(strlen($lineUp) - 10, $dobEnd + 90);
        for ($pos = $dobEnd + 15; $pos <= $maxPos; $pos++) {
            $cand = substr($lineUp, $pos, 10);
            if (preg_match('/^[2-9A-HJ-NP-Z]{10}$/', $cand)) {
                $votes[$pos]   = ($votes[$pos]   ?? 0) + 1;
                $samples[$pos][] = $cand;
            }
        }
    }

    if (empty($votes)) return -1;

    $maxVotes = max($votes);
    if ($maxVotes < 2) return -1;   // need at least 2 matching records

    // Tiebreaker 1: most distinct full values (USIs are unique per student).
    // Tiebreaker 2: most distinct FIRST CHARS.
    //   Demographic flags before USI are single-char codes (N/Y/@) — always
    //   the same across students → first-char diversity of 1.
    //   Real USI first chars are pseudo-random → much higher diversity.
    $bestPos   = -1;
    $bestScore = [-1, -1];
    foreach ($votes as $pos => $v) {
        if ($v !== $maxVotes) continue;
        $vals         = $samples[$pos];
        $totalUnique  = count(array_unique($vals));
        $firstCharDiv = count(array_unique(array_map(fn($s) => $s[0], $vals)));
        $score = [$totalUnique, $firstCharDiv];
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestPos   = $pos;
        }
    }
    return $bestPos;
}

/**
 * Find ALL candidate USI column positions in a NAT00080 file for the column-picker UI.
 *
 * Uses the official USI character set [2-9A-HJ-NP-Z] (excludes 0, 1, I, O) so only
 * genuine USI data wins votes — client IDs, numeric codes, and suburb text are
 * automatically excluded. Returns candidates sorted by vote count descending so the
 * most-likely column appears first.
 *
 * @param  string[] $sampleLines  Raw lines from the NAT00080 file.
 * @param  int      $maxSamples   Max sample values to collect per candidate position.
 * @return array[]                Array of ['pos'=>int, 'votes'=>int, 'samples'=>string[]]
 *                                sorted by votes desc. Empty if no candidates found.
 */
function local_rtocompliance_find_usi_candidates(array $sampleLines, int $maxSamples = 6): array {
    // FIX-NAT00080-TABDELIM (v4.9.164): Tab-delimited format — USI is always the
    // first 10 chars of field index 2 (third tab-delimited column). Build a single
    // candidate entry directly from the sample lines so the column-picker UI shows
    // something meaningful instead of an empty list.
    $tabLineCount = 0;
    foreach (array_slice($sampleLines, 0, min(5, count($sampleLines))) as $tl) {
        if (strpos($tl, "\t") !== false) {
            $tabLineCount++;
        }
    }
    if ($tabLineCount >= 2) {
        $tabCandidates = [];
        $tabTotal      = 0;
        foreach ($sampleLines as $rawLine) {
            $ln = ltrim(rtrim($rawLine, "\r"), "\xEF\xBB\xBF");
            if (strpos($ln, "\t") === false) continue;
            $tabTotal++;
            $parts  = explode("\t", $ln);
            $f0     = local_rtocompliance_strip_leading_quote(trim($parts[0] ?? ''));
            $f0     = rtrim($f0, '"');
            $cid    = trim(substr($f0, 0, 10));
            $f2     = strtoupper(trim($parts[2] ?? ''));
            if (strlen($f2) >= 10 &&
                preg_match('/^([2-9A-HJ-NP-Z]{10})/', $f2, $um)) {
                if (count($tabCandidates) < $maxSamples) {
                    $tabCandidates[] = ['usi' => $um[1], 'clientid' => $cid];
                }
            }
        }
        if (!empty($tabCandidates)) {
            return [[
                'pos'     => -2,
                'votes'   => count($tabCandidates),
                'total'   => $tabTotal,
                'samples' => $tabCandidates,
            ]];
        }
        return [];
    }

    $votes   = [];
    $samples = []; // pos => [['usi'=>string,'clientid'=>string], ...]
    $total   = 0;  // total parseable lines

    foreach ($sampleLines as $rawLine) {
        $line   = ltrim(rtrim($rawLine, "\r"), "\xEF\xBB\xBF");
        $line   = local_rtocompliance_strip_leading_quote($line);
        $lineUp = strtoupper($line);
        if (strlen($lineUp) < 10) continue;
        $total++;

        // Extract client ID (first 10 chars, trimmed).
        $clientid = trim(substr($line, 0, 10));

        $quoteInLine = local_rtocompliance_find_field_quote($lineUp, 10);
        $searchFrom  = ($quoteInLine !== false && $quoteInLine < 60)
                         ? ($quoteInLine + local_rtocompliance_quote_byte_len($lineUp, $quoteInLine))
                         : 60;

        // Find DOB anchor — required to know where non-USI fields end.
        if (!preg_match('/[MFX@][0-3]\d[0-1]\d(?:19|20)\d{2}/',
                        substr($lineUp, $searchFrom), $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $dobEnd = $searchFrom + $m[0][1] + 9;

        $maxPos = min(strlen($lineUp) - 10, $dobEnd + 90);
        for ($pos = $dobEnd + 15; $pos <= $maxPos; $pos++) {
            $cand = substr($lineUp, $pos, 10);
            // Official USI character set — excludes 0, 1, I, O.
            if (preg_match('/^[2-9A-HJ-NP-Z]{10}$/', $cand)) {
                $votes[$pos] = ($votes[$pos] ?? 0) + 1;
                if (!isset($samples[$pos])) $samples[$pos] = [];
                if (count($samples[$pos]) < $maxSamples) {
                    $samples[$pos][] = ['usi' => $cand, 'clientid' => $clientid];
                }
            }
        }
    }

    if (empty($votes)) return [];

    $result = [];
    foreach ($votes as $pos => $count) {
        $result[] = [
            'pos'     => (int)$pos,
            'votes'   => $count,
            'total'   => $total,
            'samples' => $samples[$pos] ?? [],
        ];
    }
    // Sort by votes descending, then by position ascending for stable ordering.
    usort($result, fn($a, $b) => $b['votes'] <=> $a['votes'] ?: $a['pos'] <=> $b['pos']);
    return $result;
}

// FIX-NAT00080-AVETMISS8 (v4.9.111): Fixed USI regex, sex X/@, DOB @-marker.
// FIX-NAT00080-EXTENDED  (v4.9.113): Added fixed fallback for Wisenet format.
// FIX-NAT00080-AUTODETECT (v4.9.114): Added format-agnostic auto-detection;
//   $detectedUsiPos from local_rtocompliance_detect_nat00080_usi_pos() is now
//   tried first, making USI extraction vendor-independent.
// FIX-NAT00080-STRICT-OVERRIDE (v4.9.122): When $strict=true the caller has manually
// specified $detectedUsiPos and only Method 0 is tried.  Fallback Methods 1/2/3 are
// skipped so the displayed USI column always reflects what the admin entered.
function local_rtocompliance_parse_nat00080(string $line, int $detectedUsiPos = -1, bool $strict = false): ?array {
    // Strip BOM and stray \r that some Windows exports prepend/append.
    $line = ltrim($line, "\xEF\xBB\xBF");   // UTF-8 BOM
    $line = rtrim($line, "\r");

    // FIX-NAT00080-TABDELIM (v4.9.164): Tab-delimited format (quoted first field).
    // Format: "<clientid><SURNAME, FIRSTNAME>"\t<demographics>\t<USI>05\t<address>
    // The fixed-width parser cannot handle this because the name is variable-length,
    // so the USI's absolute byte offset differs on every line.  We detect a tab in
    // the line and split on tabs instead of using byte-offset arithmetic.
    //
    // Demographics field (field index 1) structure:
    //   pos 0–1 : 2-char AVETMISS code (labour/indigenous)
    //   pos 2   : sex identifier (M/F/X/@ = not stated)
    //   pos 3–10: DOB DDMMYYYY  (or @@@@@@@@  = not stated)
    //   pos 11+  : postcode, state, other codes, suburb
    //
    // USI field (field index 2): 10-char USI from [2-9A-HJ-NP-Z] followed by "05",
    //   or just "5" (no address data) when no USI is recorded.
    if (strpos($line, "\t") !== false) {
        $parts  = explode("\t", $line);
        // Strip surrounding ASCII/smart quotes from the first field.
        $field0 = local_rtocompliance_strip_leading_quote(trim($parts[0] ?? ''));
        $field0 = rtrim($field0, '"');   // trailing ASCII closing quote
        if (strlen($field0) < 10) return null;
        $clientid = trim(substr($field0, 0, 10));
        if ($clientid === '') return null;

        // Name: everything after the 10-char clientid, format "SURNAME, FIRSTNAME".
        $rawname = trim(substr($field0, 10));
        if (strpos($rawname, ',') !== false) {
            [$familyname, $firstname] = array_map('trim', explode(',', $rawname, 2));
        } else {
            $familyname = $rawname;
            $firstname  = '';
        }

        // Demographics field: sex at pos 2, DOB DDMMYYYY at pos 3–10.
        $demo   = $parts[1] ?? '';
        $demoUp = strtoupper($demo);
        $sex    = null;
        $dob    = null;
        if (strlen($demoUp) >= 11) {
            $sc = $demoUp[2];
            if (in_array($sc, ['M', 'F', 'X', '@'], true)) {
                // FIX-TABDELIM-SEX-AT (v5.2.17): '@' is the AVETMISS "not stated" gender code.
                // Previously this path stored '@' as null, causing a false-positive
                // 'sex_not_stated' data-issue flag for every student in a tab-delimited
                // file whose gender is legitimately not stated.  The fixed-width path
                // already stores '@' correctly — align the tab-delimited path to match.
                $sex = in_array($sc, ['M', 'F', 'X', '@'], true) ? $sc : null;
            }
            $dobRaw = substr($demoUp, 3, 8);
            if (preg_match('/^\d{8}$/', $dobRaw)) {
                $dob = $dobRaw;
            }
        }

        // USI field: first 10 chars from [2-9A-HJ-NP-Z], or null if absent/invalid.
        $usi    = null;
        $field2 = strtoupper(trim($parts[2] ?? ''));
        if (strlen($field2) >= 10 &&
            preg_match('/^([2-9A-HJ-NP-Z]{10})/', $field2, $um)) {
            $usi = $um[1];
        }

        $dataissuefields = [];
        if (!$usi)         $dataissuefields[] = 'usi_missing';
        if ($dob === null) $dataissuefields[] = 'dob_not_stated';
        if ($sex === null) $dataissuefields[] = 'sex_not_stated';

        return [
            'clientid'           => $clientid,
            'name'               => $familyname . ($firstname !== '' ? ', ' . $firstname : ''),
            'firstname'          => $firstname  !== '' ? $firstname  : null,
            'familyname'         => $familyname !== '' ? $familyname : null,
            'sex'                => $sex,
            'dob'                => $dob,
            'usi'                => $usi,
            // Tab-delimited format does not expose these fields at extractable positions.
            'indigenousstatus'   => null,
            'labourforcestatus'  => null,
            'highestschoollevel' => null,
            'languageathome'     => null,
            'countryofbirth'     => null,
            'disabilityflag'     => null,
            'prioreducationflag' => null,
            'atschoolflag'       => null,
            'hasdataissues'      => !empty($dataissuefields) ? 1 : 0,
            'dataissuefields'    => json_encode($dataissuefields),
        ];
    }

    // FIX-NAT00080-QUOTED-CSV (v4.9.122, hardened v4.9.125): Strip leading
    // field-delimiter quote — ASCII 0x22, Windows-1252 0x93/0x94, or UTF-8
    // smart quotes (U+201C/U+201D/U+201E).
    $line = local_rtocompliance_strip_leading_quote($line);

    if (strlen($line) < 10) return null;
    $clientid = trim(substr($line, 0, 10));
    if ($clientid === '') return null;

    // FIX-QUOTED-FIELD-DELIMITER (v4.9.123, hardened v4.9.125): Some SMS exports
    // use a field-delimiter quote to terminate the name — after stripping the
    // leading quote the name ends at the NEXT quote, not at fixed position 69.
    // Helper handles ASCII, Windows-1252 0x93/0x94, and UTF-8 smart quotes.
    // AVETMISS 8.0 spec: Name for Encryption is 60 chars (pos 10–69, 0-indexed).
    // Read 60 chars so a closing quote anywhere in that range is detected.
    $nameRaw       = substr($line, 10, 60);
    $quoteInName   = local_rtocompliance_find_field_quote($nameRaw);
    $hasQuoteDelim = ($quoteInName !== false);
    $qLen          = $hasQuoteDelim ? local_rtocompliance_quote_byte_len($nameRaw, $quoteInName) : 0;
    $name          = trim($hasQuoteDelim ? substr($nameRaw, 0, $quoteInName) : $nameRaw);
    // Position in the full stripped line where sex+DOB search begins.
    $searchStart   = $hasQuoteDelim ? (10 + $quoteInName + $qLen) : 60;

    // ── Sex + DOB extraction (done first — provides anchor for USI scan) ────────
    // Primary: regex search for sex-code immediately followed by 8-digit DOB.
    // The sex+DOB pair is adjacent in ALL AVETMISS formats regardless of vendor,
    // and the year constraint (19xx/20xx) prevents false matches in address text.
    // PREG_OFFSET_CAPTURE records the absolute position so Method 3 can anchor on it.
    // Fallback: vendor-specific fixed positions when DOB contains @@@@@@@@.
    $sex       = null;
    $dob       = null;
    $dobAbsEnd = null;   // absolute index of first byte AFTER the 8-char DOB
    $sexAbsPos = -1;     // absolute index of sex identifier byte (set in both branches below)
    $lineUp    = strtoupper($line);

    if (preg_match('/([MFX@])([0-3]\d[0-1]\d(?:19|20)\d{2})/',
                   substr($lineUp, $searchStart), $sdm, PREG_OFFSET_CAPTURE)) {
        $sexAbsPos = $searchStart + $sdm[0][1];
        $dobAbsEnd = $sexAbsPos + 9;     // 1 (sex) + 8 (DOB)
        $sexraw    = $sdm[1][0];
        // '@' is the official AVETMISS "not stated" gender code — treat as valid, not missing.
        $sex       = in_array($sexraw, ['M', 'F', 'X', '@'], true) ? $sexraw : null;
        $dob       = $sdm[2][0];         // DDMMYYYY
    } else {
        // Fallback: fixed positions per known format (sex/DOB not stated = @@@@@@@@).
        // For quote-delimited format, sex+DOB sit right after the closing `"`.
        if ($hasQuoteDelim) {
            $sexpos = $searchStart;
            $dobpos = $searchStart + 1;
        } else {
            $isExtended = (strlen($line) >= 159);
            $sexpos     = $isExtended ? 72 : 62;
            $dobpos     = $isExtended ? 73 : 63;
        }
        $dobAbsEnd  = $dobpos + 8;
        $sexAbsPos  = $sexpos;   // unify with regex-branch variable name
        $sexraw     = isset($lineUp[$sexpos]) ? $lineUp[$sexpos] : '';
        // '@' is the official AVETMISS "not stated" gender code — treat as valid, not missing.
        $sex        = in_array($sexraw, ['M', 'F', 'X', '@'], true) ? $sexraw : null;
        $dobTmp     = (strlen($line) >= $dobpos + 8) ? substr($lineUp, $dobpos, 8) : '';
        if ($dobTmp !== '' && !preg_match('/^[@\s]+$/', $dobTmp)) {
            $dob = rtrim($dobTmp);
        }
    }

    // ── USI extraction ────────────────────────────────────────────────────────
    // Method 0 — Auto-detected position (highest priority, format-agnostic).
    //   The caller passes $detectedUsiPos computed by
    //   local_rtocompliance_detect_nat00080_usi_pos() over a sample of lines.
    //   When available this is always correct for any SMS vendor.
    //
    // Method 1 — Fixed fallback: Standard AVETMISS 8.0 (327-char records).
    //   Name=60, labour=2, suburb=50 → USI at pos 149 (AVETMISS 8.0 spec, Oct 2022).
    //
    // Method 2 — Fixed fallback: Pre-2022 / non-compliant short format (~101 chars).
    //   USI at pos 90 (name=50, labour=1, no suburb).
    //
    // Method 3 — DOB-anchored scan: last-resort for unknown format when the
    //   caller had too few lines for reliable detection (< 2 valid records).
    //   Tries offsets 19, 68, 69, 20, 18, 17, 67, 70 past DOB-end.
    //
    // USI validation: 10–12 uppercase alphanumeric chars, not all @/spaces.
    // AVETMISS 8.0 defines USI as 10 chars; some SMS vendors use a 12-char field.
    // We read up to 12 chars and rtrim trailing spaces so both widths are handled.
    $usi = null;

    // USI-CHARSET (v4.9.156): All extraction methods now use the official USI
    // character set [2-9A-HJ-NP-Z] which excludes 0, 1, I, O per the USI spec.
    // This prevents other fixed-width fields (client IDs, numeric codes) from
    // being accepted as a USI — the previous [A-Z0-9] regex was too broad.

    // Method 0 — Admin-confirmed or auto-detected position.
    // FIX-METHOD0-10CHAR-FALLBACK (v4.9.166): Australian USIs are exactly 10 chars from the
    // charset [2-9A-HJ-NP-Z].  In NAT00080 fixed-width exports the 10-char USI is immediately
    // followed by a 2-char check digit (e.g. "05").  The check digit digits 0 and 1 are NOT in
    // the USI charset (they were excluded to prevent false positives from numeric fields).
    // The previous code read 12 chars and validated the whole string — "832HXX8RQW05" failed
    // because "0" in the check digit is outside the charset.  Method 0 returned null, the
    // fallback methods (1/2/3) also failed because this file's USI sits at offset 154, not
    // the hardcoded 149 or 90.  Zero USIs extracted despite the voter being exactly right.
    // Fix: if the 12-char read fails charset validation, fall back to just the first 10 chars.
    // 12-char vendor USI formats (where all 12 chars are valid USI charset) continue to work.
    if ($detectedUsiPos >= 0 && strlen($lineUp) >= $detectedUsiPos + 10) {
        $candidate = rtrim(substr($lineUp, $detectedUsiPos, 12));
        if (preg_match('/^[2-9A-HJ-NP-Z]{10,12}$/', $candidate)) {
            $usi = $candidate;
        } elseif (preg_match('/^[2-9A-HJ-NP-Z]{10}$/', substr($candidate, 0, 10))) {
            $usi = substr($candidate, 0, 10);
        }
    }

    // Method 1 — Standard AVETMISS 8.0: pos 149–158 (or 149–160 for 12-char vendors).
    // Skipped when $strict=true (admin confirmed position via column-picker).
    // FIX-METHOD1-10CHAR-FALLBACK (v5.2.23): Mirror the same 10-char fallback applied to
    // Method 0 in v4.9.166.  Standard 327-char files have the 10-char USI at pos 149
    // immediately followed by a 2-char check-digit suffix (e.g. "MNJ4UPAPDX05").
    // The 12-char read fails /[2-9A-HJ-NP-Z]{10,12}/ because '0' and '5' are excluded
    // from the USI charset — returning null when preview/fallback used usiPos=-1.
    if (!$usi && !$strict && strlen($line) >= 159) {
        $candidate = rtrim(substr($lineUp, 149, 12));
        if (preg_match('/^[2-9A-HJ-NP-Z]{10,12}$/', $candidate)) {
            $usi = $candidate;
        } elseif (preg_match('/^[2-9A-HJ-NP-Z]{10}$/', substr($candidate, 0, 10))) {
            $usi = substr($candidate, 0, 10);
        }
    }

    // Method 2 — Pre-2022 short format: pos 90–99 (or 90–101 for 12-char vendors).
    // Skipped when $strict=true.
    // FIX-METHOD2-10CHAR-FALLBACK (v5.2.23): Same 10-char fallback as Methods 0 and 1.
    if (!$usi && !$strict && strlen($line) >= 100) {
        $candidate = rtrim(substr($lineUp, 90, 12));
        if (preg_match('/^[2-9A-HJ-NP-Z]{10,12}$/', $candidate)) {
            $usi = $candidate;
        } elseif (preg_match('/^[2-9A-HJ-NP-Z]{10}$/', substr($candidate, 0, 10))) {
            $usi = substr($candidate, 0, 10);
        }
    }

    // Method 3 — DOB-anchored offsets (last resort).
    // Skipped when $strict=true.
    if (!$usi && !$strict && $dobAbsEnd !== null) {
        foreach ([19, 68, 69, 20, 18, 17, 67, 70] as $off) {
            $tryPos = $dobAbsEnd + $off;
            if (strlen($lineUp) < $tryPos + 10) continue;
            $candidate = rtrim(substr($lineUp, $tryPos, 12));
            if (preg_match('/^[2-9A-HJ-NP-Z]{10,12}$/', $candidate)) {
                $usi = $candidate;
                break;
            }
        }
    }

    // ── AVETMISS demographic fields relative to sex+DOB anchor ──────────────────
    // STANDARD AVETMISS 8.0 layout (0-indexed) relative to $sexAbsPos / $dobAbsEnd:
    //   sexAbsPos - 2 (2A): Highest school level completed (Field 3; 02–12, @@)
    //   dobAbsEnd + 0 (4A): Postcode (Field 6; 4 digits or @@@@)
    //   dobAbsEnd + 4 (1A): Indigenous status identifier   (Field 7; 1–4, @)
    //   dobAbsEnd + 5 (4A): Language identifier / lang at home (Field 8; ASCL code)
    //   dobAbsEnd + 9 (2A): Labour force status identifier (Field 9; 01–08, @@)  [standard: 2A]
    //   dobAbsEnd +11 (4A): Country identifier             (Field 10; SACC code)  [standard offset]
    //   dobAbsEnd +15 (1A): Disability flag                (Field 11; Y/N/@)      [standard offset]
    //   dobAbsEnd +16 (1A): Prior educational achievement  (Field 12; Y/N/@)      [standard offset]
    //   dobAbsEnd +17 (1A): At school flag                 (Field 13; Y/N)        [standard offset]
    //
    // Pre-2022 / short format (USI at pos 90, name=50, labour=1A): labour is 1 char,
    // so country/disability/priored/atschool offsets are each 1 less (10/14/15/16).
    //
    // FIX-NAT00080-FULL-DEMOGRAPHICS (v5.9.317): v5.9.316 read wrong offsets
    // (dobAbsEnd+0,1,3) because it missed the 4-char postcode block between DOB
    // and indigenous status.  englishproficiency is intentionally not extracted —
    // it was removed from NAT00080 in AVETMISS Release 8.

    $highestschoollevel = null;
    $indigenousstatus   = null;
    $languageathome     = null;
    $labourforcestatus  = null;
    $countryofbirth     = null;
    $disabilityflag     = null;
    $prioreducationflag = null;
    $atschoolflag       = null;

    // Highest school level sits 2 bytes BEFORE sex in all fixed-width formats.
    if ($sexAbsPos >= 2 && strlen($line) >= $sexAbsPos) {
        $schlRaw = strtoupper(substr($line, $sexAbsPos - 2, 2));
        if ($schlRaw !== '' && !preg_match('/^\s+$/', $schlRaw)) $highestschoollevel = $schlRaw;
    }

    if ($dobAbsEnd !== null && strlen($line) > $dobAbsEnd + 4) {
        // Determine labour force field width: standard=2 chars, pre-2022 short=1 char.
        // Heuristic: if detected USI is at pos 90 the file is pre-2022 short format.
        // Fall back to line-length: standard records are >= 159 chars (up to State field).
        if ($detectedUsiPos === 90) {
            $labW = 1;
        } elseif ($detectedUsiPos >= 100) {
            $labW = 2;
        } else {
            $labW = (strlen($line) >= 159) ? 2 : 1;
        }
        $cntryOff  = 9 + $labW;      // 11 (standard) or 10 (short)
        $disOff    = $cntryOff + 4;  // 15 or 14
        $priorOff  = $disOff   + 1;  // 16 or 15
        $atschOff  = $priorOff + 1;  // 17 or 16

        // Indigenous status (dobAbsEnd+4, 1 char).
        $indRaw = strtoupper(substr($line, $dobAbsEnd + 4, 1));
        if ($indRaw !== '' && !preg_match('/^\s+$/', $indRaw)) $indigenousstatus = $indRaw;

        // Language at home (dobAbsEnd+5, 4 chars).
        if (strlen($line) >= $dobAbsEnd + 9) {
            $langRaw = strtoupper(substr($line, $dobAbsEnd + 5, 4));
            if ($langRaw !== '' && !preg_match('/^[@\s]+$/', $langRaw)) $languageathome = $langRaw;
        }

        // Labour force status (dobAbsEnd+9, $labW chars).
        if (strlen($line) >= $dobAbsEnd + 9 + $labW) {
            $labRaw = strtoupper(substr($line, $dobAbsEnd + 9, $labW));
            if ($labRaw !== '' && !preg_match('/^\s+$/', $labRaw)) $labourforcestatus = $labRaw;
        }

        // Country of birth (dobAbsEnd+$cntryOff, 4 chars).
        if (strlen($line) >= $dobAbsEnd + $cntryOff + 4) {
            $cntryRaw = strtoupper(substr($line, $dobAbsEnd + $cntryOff, 4));
            if ($cntryRaw !== '' && !preg_match('/^[@\s]+$/', $cntryRaw)) $countryofbirth = $cntryRaw;
        }

        // Disability flag (dobAbsEnd+$disOff, 1 char).
        if (strlen($line) >= $dobAbsEnd + $disOff + 1) {
            $disRaw = strtoupper(substr($line, $dobAbsEnd + $disOff, 1));
            if ($disRaw !== '' && !preg_match('/^\s+$/', $disRaw)) $disabilityflag = $disRaw;
        }

        // Prior educational achievement flag (dobAbsEnd+$priorOff, 1 char).
        if (strlen($line) >= $dobAbsEnd + $priorOff + 1) {
            $priorRaw = strtoupper(substr($line, $dobAbsEnd + $priorOff, 1));
            if ($priorRaw !== '' && !preg_match('/^\s+$/', $priorRaw)) $prioreducationflag = $priorRaw;
        }

        // At school flag (dobAbsEnd+$atschOff, 1 char).
        if (strlen($line) >= $dobAbsEnd + $atschOff + 1) {
            $atschRaw = strtoupper(substr($line, $dobAbsEnd + $atschOff, 1));
            if ($atschRaw !== '' && !preg_match('/^\s+$/', $atschRaw)) $atschoolflag = $atschRaw;
        }
    }

    // ── Data-issue flags ──────────────────────────────────────────────────────
    $dobAbsent = ($dob === null);
    $sexKnown  = ($sex !== null);
    $dataissuefields = [];
    if (!$usi)      $dataissuefields[] = 'usi_missing';
    if ($dobAbsent) $dataissuefields[] = 'dob_not_stated';
    if (!$sexKnown) $dataissuefields[] = 'sex_not_stated';
    $hasdataissues = count($dataissuefields) > 0;

    return [
        'clientid'           => $clientid,
        'name'               => $name,
        'sex'                => $sex,
        'dob'                => $dob,
        'usi'                => $usi,
        'indigenousstatus'   => $indigenousstatus,
        'labourforcestatus'  => $labourforcestatus,
        'highestschoollevel' => $highestschoollevel,
        'languageathome'     => $languageathome,
        'countryofbirth'     => $countryofbirth,
        'disabilityflag'     => $disabilityflag,
        'prioreducationflag' => $prioreducationflag,
        'atschoolflag'       => $atschoolflag,
        'hasdataissues'      => $hasdataissues ? 1 : 0,
        'dataissuefields'    => json_encode($dataissuefields),
    ];
}

// NAT00085 – Client contact details (AVETMISS 8.0, record length 557)
// All positions are 0-indexed (spec uses 1-indexed — subtract 1).
//   pos   0–9   (10A): Client identifier
//   pos  10–13  ( 4A): Client title
//   pos  14–53  (40A): Client first given name
//   pos  54–93  (40A): Client family name
//   pos  94–143 (50A): Address building/property name
//   pos 144–173 (30A): Address flat/unit details
//   pos 174–188 (15A): Address street number
//   pos 189–258 (70A): Address street name
//   pos 259–280 (22A): Address postal delivery box
//   pos 281–330 (50A): Address – suburb, locality or town
//   pos 331–334 ( 4A): Postcode
//   pos 335–336 ( 2A): State identifier (01–09 for AU states/territories, 99 for overseas)
//   pos 337–356 (20A): Telephone number [home]
//   pos 357–376 (20A): Telephone number [work]
//   pos 377–396 (20A): Telephone number [mobile]
//   pos 397–476 (80A): Email address
//   pos 477–556 (80A): Email address [alternative]
function local_rtocompliance_parse_nat00085(string $line): ?array {
    if (strlen($line) < 10) return null;
    $clientid = trim(substr($line, 0, 10));
    if ($clientid === '') return null;

    // Name fields — fixed positions per spec.
    $firstname  = strlen($line) >= 54  ? trim(substr($line, 14, 40)) ?: null : null;
    $familyname = strlen($line) >= 94  ? trim(substr($line, 54, 40)) ?: null : null;

    // Address fields — fixed positions per spec.
    // Suburb (pos 281, len 50): strip AVETMISS not-stated placeholder.
    $rawSuburb = strlen($line) >= 331 ? trim(substr($line, 281, 50)) : '';
    $suburb    = ($rawSuburb !== '' && !preg_match('/^[@\s]+$/', $rawSuburb)) ? $rawSuburb : null;

    // Postcode (pos 331, len 4): valid AU postcode is 4 digits.
    $rawPostcode = strlen($line) >= 335 ? trim(substr($line, 331, 4)) : '';
    $postcode    = (preg_match('/^\d{4}$/', $rawPostcode) || $rawPostcode === 'OSPC' || $rawPostcode === '@@@@')
                    ? $rawPostcode : null;

    // State identifier (pos 335, len 2): AVETMISS codes 01–09 (AU) or 99 (overseas).
    $rawState = strlen($line) >= 337 ? trim(substr($line, 335, 2)) : '';
    $state    = (preg_match('/^(0[1-9]|99|@@)$/', $rawState)) ? $rawState : null;

    // ADDRESS-PARSE (v5.9.396): the street address was previously never extracted.
    // Building/property name (pos 94, len 50); flat/unit (pos 144, len 30);
    // street number (pos 174, len 15) + street name (pos 189, len 70) combined into
    // the profile's single streetname column. AVETMISS "not stated" (@) is stripped.
    $notstated = fn($v) => ($v !== '' && !preg_match('/^[@\s]+$/', $v));
    $rawBuilding  = strlen($line) >= 144 ? trim(substr($line, 94, 50)) : '';
    $buildingname = $notstated($rawBuilding) ? $rawBuilding : null;
    $rawUnit      = strlen($line) >= 174 ? trim(substr($line, 144, 30)) : '';
    $unitno       = $notstated($rawUnit) ? $rawUnit : null;
    $rawStreetNo  = strlen($line) >= 189 ? trim(substr($line, 174, 15)) : '';
    $rawStreetNm  = strlen($line) >= 259 ? trim(substr($line, 189, 70)) : '';
    if (!$notstated($rawStreetNo)) { $rawStreetNo = ''; }
    if (!$notstated($rawStreetNm)) { $rawStreetNm = ''; }
    $streetcombined = trim($rawStreetNo . ' ' . $rawStreetNm);
    $streetname     = ($streetcombined !== '') ? substr($streetcombined, 0, 70) : null;

    // Phone — priority: home (pos 337) → mobile (pos 377) → work (pos 357).
    // Strip spaces; reject all-@ (not stated) and blank.
    $phone = null;
    $phoneSlots = [[337, 20], [377, 20], [357, 20]];  // home, mobile, work
    foreach ($phoneSlots as [$pPos, $pLen]) {
        if (strlen($line) < $pPos + $pLen) continue;
        $raw = trim(substr($line, $pPos, $pLen));
        if ($raw === '' || preg_match('/^[@\s]+$/', $raw)) continue;
        // Strip internal spaces/hyphens for storage; keep original digits only.
        $digits = preg_replace('/[\s\-()]/', '', $raw);
        if (strlen($digits) >= 8) {
            $phone = $digits;
            break;
        }
    }

    // Email — fixed position (pos 397, len 80); fall back to alternative (pos 477, len 80).
    $email = null;
    foreach ([[397, 80], [477, 80]] as [$ePos, $eLen]) {
        if (strlen($line) < $ePos + $eLen) continue;
        $raw = trim(substr($line, $ePos, $eLen));
        if ($raw !== '' && !preg_match('/^[@\s]+$/', $raw) && strpos($raw, '@') !== false) {
            $email = strtolower($raw);
            break;
        }
    }
    // Fallback: if fixed-position read found nothing, try regex scan (handles short/non-standard records).
    if ($email === null && preg_match('/[\w.+%\-]+@[\w.\-]+\.[a-zA-Z]{2,}/', $line, $m)) {
        $email = strtolower($m[0]);
    }

    return [
        'clientid'     => $clientid,
        'firstname'    => $firstname,
        'familyname'   => $familyname,
        'email'        => $email,
        'phone'        => $phone,
        'suburb'       => $suburb,
        'state'        => $state,
        'postcode'     => $postcode,
        'buildingname' => $buildingname,
        'unitno'       => $unitno,
        'streetname'   => $streetname,
    ];
}

// NAT00120 – Enrolments (Training activity)
// BUG-NAT00120-FIELDPOS (v4.9.171): Previous version (v4.9.126) incorrectly inserted a
// "Training organisation delivery location identifier" field at positions 10-19, which
// does NOT exist at that position in the AVETMISS 8.0 standard. This pushed every
// subsequent field 10 positions too far into the line, causing clientid to read the unit
// code, qualcode to read the activity start date (confirmed: "0101200804" = "01/01/2008"
// + first 2 bytes of end date), startdate to read the end date, etc.
//
// FIX-NAT00120-PROGID-10A (v5.2.10): NAT-FIX-5.2.4 incorrectly changed the Programme
// Identifier field (field 4) from 10A to 12A, pushing startdate/enddate/outcome 2 bytes
// too late. AVETMISS 8.0 NAT00120 field 4 is 10 chars. Reverted to 10A, correcting all
// downstream positions.  Symptoms: start/end dates displayed with impossible month "20"
// (e.g. "01/20/1621") and Qualification column showed only "01" (first 2 bytes of
// start date leaking into the 12-char qualcode read).
//
// Correct AVETMISS 8.0 NAT00120 field layout (0-indexed, per NCVER spec):
//   pos  0- 9 (10A): Training organisation delivery location identifier
//   pos 10-19 (10A): Client identifier
//   pos 20-31 (12A): Subject identifier (unit/competency code)
//   pos 32-41 (10A): Programme identifier (qual code)   ← FIX-5.2.10: 10A not 12A
//   pos 42-49  (8D): Activity start date (DDMMYYYY)     ← FIX-5.2.10: was 44
//   pos 50-57  (8D): Activity end date (DDMMYYYY)       ← FIX-5.2.10: was 52
//   pos 58-59  (2A): Outcome identifier – national      ← FIX-5.2.10: was 60
//              (some SMS vendors insert 2-char Delivery Mode here → outcome shifts to 60–61;
//               auto-detected at parse time by checking AVETMISS outcome code validity)
//   pos 60+    (3A): Funding source – national (position varies with delivery mode presence)
//   ...        (study reason, hours all shift accordingly)
function local_rtocompliance_parse_nat00120(string $line): ?array {
    // BUG-NAT00120-FIELDPOS (v4.9.171): All field positions were shifted +10 from the
    // AVETMISS 8.0 standard because the parser skipped the Client Identifier field
    // (positions 10-19), causing every subsequent field to land in the wrong slot.
    // Confirmed empirically: "qualcode" was returning the activity start date (e.g.
    // "01012013" + 2 bytes = "0101201308") instead of the Program Identifier ("ICA20111").
    //
    // FIX-NAT00120-PROGID-10A (v5.2.10): NAT-FIX-5.2.4 incorrectly changed Programme
    // Identifier (field 4) from 10A to 12A. Reverted. All downstream positions corrected:
    //
    // AVETMISS 8.0 NAT00120 field layout (0-indexed) — corrected by FIX-5.2.10:
    //   0-9   Training Organisation Delivery Location Identifier (10)
    //  10-19  Client Identifier (10)
    //  20-31  Subject Identifier / unit code (12)
    //  32-41  Programme Identifier / qual code (10)  ← FIX-5.2.10: 10A (was wrongly 12A)
    //  42-49  Activity Start Date DDMMYYYY (8)       ← FIX-5.2.10: pos 42 (was wrong 44)
    //  50-57  Activity End Date DDMMYYYY (8)         ← FIX-5.2.10: pos 50 (was wrong 52)
    //  58-59  Outcome Identifier – National (2)      ← FIX-5.2.10: pos 58 (was wrong 60)
    //         NOTE: vendor SMS files (Velixio, older Wisenet) add a 2-char Delivery Mode
    //         field at 58-59, pushing Outcome to 60-61.  Auto-detected below.
    //  60+    Funding Source – National (3A) [shifts +2 if delivery mode present]
    //  ...    Study reason, hours shift accordingly
    if (strlen($line) < 58) return null;

    // FIX-NAT00120-VENDOR-PREFIX (v5.2.22): Some vendor SMS systems (confirmed: Wisenet,
    // all 158-char exports) prepend a 10-char Training Organisation Identifier at pos 0–9
    // BEFORE the standard fields, shifting the Delivery Location ID to pos 10–19 and the
    // Client Identifier to pos 20–29.  This causes every field to land 10 bytes too late:
    // the standard parser reads clientid="000002" (the location ID, not the student ID),
    // unitcode="0000057272BSBS" (digits+letters garbage), startdate="ICT20115" (letters),
    // and outcome from the middle of the start-date field — completely wrong data for every
    // enrolment record in these files.
    //
    // Additionally, these files place 3 vendor flag bytes ("YNN" = VETiS/Y/N flags) between
    // the end date and the outcome code, positioning the outcome at raw-line pos 71–72 (not
    // 68–69 as a simple +10 shift would suggest).
    //
    // Detection: if the standard start-date field (pos 42–49) is NOT a valid DDMMYYYY date
    // but the +10-shifted start-date field (pos 52–59) IS, the file uses the vendor-prefix
    // format.  Confirmed across 5 independent vendor files (4 datasets, 2 RTOs).
    //
    // Field layout for vendor-prefix (158-char) format (0-indexed):
    //   pos  0– 9  Extra Training Organisation Identifier (non-standard 10A prefix)
    //   pos 10–19  Training Org Delivery Location Identifier (10A)
    //   pos 20–29  Client Identifier (10A)
    //   pos 30–41  Subject Identifier / unit code (12A)
    //   pos 42–51  Programme Identifier / qual code (10A)
    //   pos 52–59  Activity Start Date DDMMYYYY (8D)
    //   pos 60–67  Activity End Date DDMMYYYY (8D)
    //   pos 68–70  Vendor flag bytes (3A: e.g. "YNN" = VETiS flag + 2 binary flags)
    //   pos 71–72  Outcome Identifier – National (2A)
    //   pos 73–75  Funding Source – National (3A)
    //   pos 96–97  Study Reason Identifier (2A)
    //   hours:     end-of-line (strip trailing vendor indicator 'I', read last 4 digits)
    $rtoid    = trim(substr($line, 0, 10));
    $clientid = trim(substr($line, 10, 10));
    if ($clientid === '') return null;

    $unitcode  = trim(substr($line, 20, 12));
    $qualcode  = trim(substr($line, 32, 10));   // FIX-5.2.10: 10A (was wrongly 12A in v5.2.4)
    $startdate = trim(substr($line, 42, 8));    // FIX-5.2.10: pos 42 (was wrongly 44)
    $enddate   = trim(substr($line, 50, 8));    // FIX-5.2.10: pos 50 (was wrongly 52)

    // Vendor-prefix format detection (see full comment above).
    $isVendorPrefix = false;
    if (!preg_match('/^[0-3]\d[0-1]\d(?:19|20)\d{2}$/', $startdate)
        && strlen($line) >= 60
        && preg_match('/^[0-3]\d[0-1]\d(?:19|20)\d{2}$/', substr($line, 52, 8))
    ) {
        $isVendorPrefix = true;
        // Re-read all core fields at +10 offset.
        $rtoid     = trim(substr($line, 10, 10));   // delivery location (was the standard pos 0–9)
        $clientid  = trim(substr($line, 20, 10));
        if ($clientid === '') return null;
        $unitcode  = trim(substr($line, 30, 12));
        $qualcode  = trim(substr($line, 42, 10));
        $startdate = trim(substr($line, 52, 8));
        $enddate   = trim(substr($line, 60, 8));
    }

    // ── Vendor-prefix format: outcome + downstream fields ─────────────────────
    // For vendor-prefix files the 3-byte flag block and fixed downstream positions
    // are handled here; the general outcome detection block below is skipped.
    if ($isVendorPrefix) {
        // Outcome at pos 71–72 (after 3 vendor flag bytes at 68–70).
        $outcome       = null;
        $fundingsource = null;
        $studyreason   = null;
        $supervisedhours = null;

        $raw71 = strlen($line) >= 73 ? trim(substr($line, 71, 2)) : '';
        if (in_array($raw71, ['10','20','30','40','41','51','52','53','60','61','70','81','82','85','90'], true)) {
            $outcome = $raw71;
        } else {
            // Fallback: scan pos 68–72 in case vendor flag count varies.
            foreach ([68, 69, 70, 72] as $op) {
                $candidate = strlen($line) >= $op + 2 ? trim(substr($line, $op, 2)) : '';
                if (in_array($candidate, ['10','20','30','40','41','51','52','53','60','61','70','81','82','85','90'], true)) {
                    $outcome = $candidate;
                    break;
                }
            }
            if ($outcome === null && $raw71 !== '' && $raw71 !== '@@') {
                $outcome = $raw71;  // store raw value for audit
            }
        }

        // Funding source at pos 73–75 (confirmed across 2 independent vendor datasets).
        if (strlen($line) >= 76) {
            $rawf = trim(substr($line, 73, 3));
            if ($rawf !== '' && $rawf !== '@@@') $fundingsource = $rawf;
        }

        // Study reason at pos 96–97 (confirmed across 2 independent vendor datasets).
        if (strlen($line) >= 98) {
            $raws = trim(substr($line, 96, 2));
            if ($raws !== '' && $raws !== '@@') $studyreason = $raws;
        }

        // Hours: strip trailing vendor indicator byte 'I', then read last 4 digits.
        $stripped = rtrim(rtrim($line), 'I');
        if (strlen($stripped) >= 4) {
            $last4 = substr($stripped, -4);
            if (ctype_digit($last4) && (int)$last4 > 0) {
                $supervisedhours = (int)$last4;
            }
        }

        return [
            'rtoid'           => $rtoid,
            'clientid'        => $clientid,
            'unitcode'        => $unitcode,
            'qualcode'        => $qualcode,
            'startdate'       => $startdate ?: null,
            'enddate'         => $enddate   ?: null,
            'outcome'         => $outcome,
            'fundingsource'   => $fundingsource,
            'studyreason'     => $studyreason,
            'supervisedhours' => $supervisedhours,
        ];
    }

    // Outcome identifier – national.
    // FIX-5.2.10: After the correct 10-char Programme ID and 8-char dates, the next
    // field starts at position 58.  In standard AVETMISS 8.0 this IS the outcome code
    // (2A).  However many SMS vendors (Velixio, older Wisenet builds) insert a 2-char
    // Delivery Mode Identifier at 58–59 before the outcome, pushing it to 60–61.
    //
    // Auto-detection: check whether the value at 58–59 is a recognised AVETMISS national
    // outcome code.  If it is, use it directly (standard format).  If it is not but the
    // value at 60–61 IS a recognised outcome code, treat 58–59 as delivery mode and read
    // outcome from 60–61.  Fall back to the raw value at 58 for vendor-specific codes.
    //
    // Recognised AVETMISS 8.0 outcome codes (field 7, 1-indexed pos 59–60 = 0-indexed 58–59):
    //   10 = Not yet started              51 = RPL granted      81 = Non-assessable (sat)
    //   20 = Competency achieved/pass     52 = RPL not granted  82 = Non-assessable (not sat)
    //   30 = Not yet achieved             53 = RCC granted      85 = Further enrolment
    //   40 = Withdrawn                    60 = Credit transfer  90 = Result not available
    //   61 = Credit transfer              70 = Continuing enrolment
    //
    // FIX-5.2.11: Added outcome code '10' (Not Yet Started), which was missing from this
    // list.  When '10' appeared at pos 58–59 the parser fell through and incorrectly read
    // '90' from pos 60–61 (a vendor-specific field).
    //
    // Secondary delivery-mode check: some SMS vendors (including the one producing this
    // RTO's NAT files) insert a 2-char vendor field at pos 60–61 immediately after the
    // standard outcome.  When outcome IS correctly found at pos 58–59, we also check
    // whether pos 60–61 looks like an outcome code (e.g. '90').  If it does, it is that
    // vendor field — not the outcome — and we set deliveryModePresent so that fundingPos
    // shifts to 62 (matching the verified raw data: pos 62–64 = funding in this SMS).
    // FIX-OUTCOME-41-DETECTION (v5.2.16): '41' (Satisfactorily Completed – VETiS/school-based)
    // was missing from this detection array.  Same class of bug as missing '10' fixed in v5.2.11.
    // For vendor-format files where a 2-char Delivery Mode field sits at pos 58–59 (pushing the
    // real outcome to pos 60–61), the parser checks whether the value at pos 60–61 is in this
    // set to decide whether to treat pos 58–59 as delivery mode.  With '41' absent, outcome '41'
    // at pos 60–61 was not recognised, causing the parser to fall through to the raw-value branch
    // and store the delivery mode string (e.g. 'AA') as the outcome instead.
    static $AVETMISS_OUTCOME_CODES = ['10','20','30','40','41','51','52','53','60','61','70','81','82','85','90'];
    // AVETMISS 8.0 delivery mode identifier codes (2-digit, padded to 3A in vendor files).
    static $AVETMISS_DELIVERY_MODE_CODES = ['10','20','30','40','90'];
    $outcome             = null;
    $deliveryModePresent = false;
    $deliveryModeLen     = 0; // 0 = absent, 2 = 2-char DM field, 3 = 3-char DM field
    if (strlen($line) >= 60) {
        $raw58 = trim(substr($line, 58, 2));
        $raw60 = (strlen($line) >= 62) ? trim(substr($line, 60, 2)) : '';
        $raw61 = (strlen($line) >= 63) ? trim(substr($line, 61, 2)) : '';

        // FIX-BLANK-OUTCOME-MARKER (v5.9.66): '@' and '@@' are the AVETMISS "no data"
        // placeholder characters. When pos 58–59 = '@@' (or '' / '@'), the outcome field
        // is explicitly absent. We must NOT fall through to read the delivery mode field
        // at pos 60–61 as if it were an outcome code. This was causing delivery mode 30
        // (online/distance) to be misread as outcome 30 (Competency Not Yet Achieved) for
        // Wisenet exports where the file layout is: pos 58-59 = outcome (blank), pos 60-61
        // = delivery mode. All such records were incorrectly labelled "Competency Not Yet
        // Achieved" and treated as non-continuing enrolments.
        if ($raw58 === '' || $raw58 === '@' || $raw58 === '@@') {
            // Outcome field is explicitly blank — leave $outcome = null.
            // (delivery mode or other fields follow at pos 60+ but are not the outcome)
        }

        // BUG-NAT120-3CHAR-DM (v5.2.19): AVETMISS 8.0 spec defines the Delivery Mode
        // Identifier as 3A (3 characters). Some vendor SMS systems (e.g. this vendor's file)
        // emit the full 3-char field right-padded with a space: "10 " (Internal/Classroom),
        // "20 " (External), etc. — placing the actual outcome at pos 61–62, not pos 58–59.
        //
        // The previous 2-char-only detection read pos 58–59 = "10" and, since "10" is also a
        // valid AVETMISS outcome code (Not Commenced), stored outcome = "10" and skipped the
        // real outcome "20" at pos 61–62.  Students then showed "Not Yet Started" in Moodle
        // despite having outcome code 20 (Competency Achieved) in the NAT file.
        //
        // Detection: if pos 60 is exactly ' ' (the 3-char DM right-pad) AND pos 58–59 is a
        // known delivery mode code AND pos 61–62 is a valid outcome code, treat the field as
        // a 3-char delivery mode and read the outcome from pos 61–62.
        elseif (strlen($line) >= 63
            && substr($line, 60, 1) === ' '
            && in_array($raw58, $AVETMISS_DELIVERY_MODE_CODES, true)
            && in_array($raw61, $AVETMISS_OUTCOME_CODES, true)
        ) {
            // 3-char delivery mode field (e.g. "10 " = Internal right-padded to 3A).
            $deliveryModePresent = true;
            $deliveryModeLen     = 3;
            $outcome             = $raw61;
        } elseif (in_array($raw58, $AVETMISS_OUTCOME_CODES, true)) {
            if (in_array($raw60, $AVETMISS_OUTCOME_CODES, true)
                && in_array($raw58, $AVETMISS_DELIVERY_MODE_CODES, true)
            ) {
                // FIX-2CHAR-DM-OUTCOME (v5.2.21): raw58 is simultaneously a valid 2-char
                // delivery mode code (10=Internal, 20=External, 30=Workplace, 40=Combination,
                // 90=Other) AND a valid outcome code.  raw60 is also a valid outcome code.
                // In this vendor format pos 58-59 is the delivery mode field and pos 60-61
                // is the actual outcome (e.g. "10"=DM-Internal + "20"=Competent).
                // The previous logic took raw58 as the outcome for every such record,
                // storing the delivery-mode code instead of the real result — every
                // student appeared as "Not Commenced" (10) or "Not Applicable" (90)
                // regardless of their actual result.
                $deliveryModePresent = true;
                $deliveryModeLen     = 2;
                $outcome             = $raw60;
            } elseif (in_array($raw60, $AVETMISS_OUTCOME_CODES, true)) {
                // raw58 is an outcome-only code (41, 51, 52, 53, 61, 70, 81, 82, 85) that
                // cannot be a delivery mode.  raw60 also looks like an outcome code — treat
                // raw60 as a vendor-specific extra field and shift fundingPos to 62.
                $outcome             = $raw58;
                $deliveryModePresent = true;
                $deliveryModeLen     = 2;
            } else {
                // Standard AVETMISS 8.0: outcome at pos 58–59, no delivery mode field.
                $outcome         = $raw58;
                $deliveryModeLen = 0;
            }
        } elseif (in_array($raw60, $AVETMISS_OUTCOME_CODES, true)) {
            // Vendor format: 2-char delivery mode / extra field at 58–59, outcome at 60–61.
            $deliveryModePresent = true;
            $deliveryModeLen     = 2;
            $outcome             = $raw60;
        } elseif ($raw58 !== '' && $raw58 !== '@@') {
            // Non-standard / vendor-specific code at 58 — store as-is.
            $outcome = $raw58;
        } elseif ($raw60 !== '' && $raw60 !== '@@') {
            $outcome             = $raw60;
            $deliveryModePresent = true;
            $deliveryModeLen     = 2;
        }
    }

    // Funding source – national (3A, immediately after outcome).
    // FIX-5.2.10: position is 60 (standard) or 62 (if 2-char delivery mode present at 58).
    // FIX-5.2.19: position is 63 (if 3-char delivery mode present at 58–60).
    $fundingPos    = ($deliveryModeLen === 3) ? 63 : ($deliveryModePresent ? 62 : 60);
    $fundingsource = null;
    if (strlen($line) >= $fundingPos + 3) {
        $raw = trim(substr($line, $fundingPos, 3));
        if ($raw !== '' && $raw !== '@@@') {
            $fundingsource = $raw;
        }
    }

    // Study reason identifier (2A).
    // FIX-5.2.10: position corrected back to 94 (standard) or 96 (2-char delivery mode).
    // FIX-5.2.19: position is 97 if 3-char delivery mode present.
    $studyReasonPos = ($deliveryModeLen === 3) ? 97 : ($deliveryModePresent ? 96 : 94);
    $studyreason    = null;
    if (strlen($line) >= $studyReasonPos + 2) {
        $raw = trim(substr($line, $studyReasonPos, 2));
        if ($raw !== '' && $raw !== '@@') {
            $studyreason = $raw;
        }
    }

    // Hours attended (4N) — primary supervised-hours field.
    // FIX-5.2.10: positions corrected back: attended=134, scheduled=130 (standard).
    // With 2-char delivery mode: attended=136, scheduled=132.
    // FIX-5.2.19: With 3-char delivery mode: attended=137, scheduled=133.
    $hoursAttendedPos  = ($deliveryModeLen === 3) ? 137 : ($deliveryModePresent ? 136 : 134);
    $hoursScheduledPos = ($deliveryModeLen === 3) ? 133 : ($deliveryModePresent ? 132 : 130);
    $supervisedhours   = null;
    if (strlen($line) >= $hoursAttendedPos + 4) {
        $raw = trim(substr($line, $hoursAttendedPos, 4));
        if (ctype_digit($raw) && (int)$raw > 0) {
            $supervisedhours = (int)$raw;
        }
    }
    if ($supervisedhours === null && strlen($line) >= $hoursScheduledPos + 4) {
        $raw = trim(substr($line, $hoursScheduledPos, 4));
        if (ctype_digit($raw) && (int)$raw > 0) {
            $supervisedhours = (int)$raw;
        }
    }
    // FIX-HOURS-ENDOFLINE (v5.2.21): Some vendor SMS systems place nominal hours as
    // the last four non-space characters of the NAT00120 line rather than at the
    // standard positions above (e.g. vendors that emit attended hours immediately
    // after the outcome at pos 62-65 and carry nominal hours at the very end of the
    // record at pos 128-131 in a 142-char line).  The standard positional reads
    // return null for these files.  Safe fallback: strip trailing whitespace and
    // read the last 4 characters; if they are all digits and non-zero, use them.
    // This does NOT affect vendors where the standard positions succeed (the guard
    // above already set $supervisedhours in those cases).
    // FIX-HOURS-TRAILING-I (v5.2.22): Wisenet vendor-prefix (158-char) lines end with
    // a single 'I' indicator byte after the hours digits (e.g. "...0010I").  Strip
    // trailing 'I' before the last-4 read so the digit check is not blocked by it.
    if ($supervisedhours === null) {
        $stripped = rtrim(rtrim($line), 'I');
        if (strlen($stripped) >= 4) {
            $last4 = substr($stripped, -4);
            if (ctype_digit($last4) && (int)$last4 > 0) {
                $supervisedhours = (int)$last4;
            }
        }
    }

    return [
        'rtoid'          => $rtoid,
        'clientid'       => $clientid,
        'unitcode'       => $unitcode,
        'qualcode'       => $qualcode,
        'startdate'      => $startdate ?: null,
        'enddate'        => $enddate ?: null,
        'outcome'        => $outcome,
        'fundingsource'  => $fundingsource,
        'studyreason'    => $studyreason,
        'supervisedhours'=> $supervisedhours,
    ];
}

// NAT00130 – Qualification completions
// Standard positions (0-indexed): 0-9 RTO, 10-19 qualCode, 20-29 clientId,
//   30-37 completionDate (8D DDMMYYYY), 38 parchment flag, 39-46 certificateDate (8D),
//   47+ parchmentNumber.
//
// FIX-NAT00130-SHORT-FORMAT (v5.2.21): some vendor SMS systems (e.g. older VETiS /
// Wisenet exports) emit a compact 35-char NAT00130 format:
//   pos 30-33 = collection year (4N, e.g. "2016")
//   pos 34    = successful completion flag (1A: "Y" or "N")
// The standard parser reads 8 chars from pos 30 and gets "2016Y<3 spaces>" which is
// stored as the completiondate — a five-character garbage value that can never be
// parsed as a date.  Detection: if the line is shorter than 38 chars, treat as the
// compact year+flag format and extract only the 4-digit year.
function local_rtocompliance_parse_nat00130(string $line): ?array {
    if (strlen($line) < 30) return null;
    $clientid = trim(substr($line, 20, 10));
    if ($clientid === '') return null;

    $qualcode = trim(substr($line, 10, 10));

    if (strlen($line) < 38) {
        // Compact format: collection year (4N) at pos 30-33, flag (Y/N) at pos 34.
        $year            = substr($line, 30, 4);
        $completiondate  = ctype_digit($year) ? $year : null;
        $certificatedate = null;
        $parchmentnumber = null;
        // FIX-NAT00130-SUCCESSFUL-COMPLETION (v5.2.32): Read Y/N flag at pos 34 in compact format.
        $rawflag = strlen($line) >= 35 ? strtoupper(trim(substr($line, 34, 1))) : null;
        $successfulcompletion = ($rawflag === 'Y' || $rawflag === 'N') ? $rawflag : null;
    } else {
        // Standard format: 8D completion date at pos 30-37.
        $completiondate  = trim(substr($line, 30, 8));
        // FIX-NAT00130-SUCCESSFUL-COMPLETION (v5.2.32): Read Y/N flag at pos 38 in standard format.
        // AVETMISS 8.0 Data Element 514 — Successful Programme Completion Indicator (1A).
        $rawflag = strlen($line) >= 39 ? strtoupper(trim(substr($line, 38, 1))) : null;
        $successfulcompletion = ($rawflag === 'Y' || $rawflag === 'N') ? $rawflag : null;
        // certificateDate reads [39:47] → shifted to [40:48] because flag occupies pos 38.
        // AVETMISS 8.0 spec: Parchment Issue Date at pos 39 (after the 1-char flag at pos 38).
        $certificatedate = strlen($line) >= 47 ? trim(substr($line, 39, 8)) : null;
        $parchmentnumber = strlen($line) > 47  ? trim(substr($line, 47)) : null;
    }

    return [
        'clientid'              => $clientid,
        'qualcode'              => $qualcode,
        'completiondate'        => $completiondate ?: null,
        'successfulcompletion'  => $successfulcompletion,
        'certificatedate'       => $certificatedate ?: null,
        'parchmentnumber'       => $parchmentnumber ?: null,
    ];
}

// NAT00030 – Programme/Qualification register
// Field layout (0-indexed, AVETMISS 8.0):
//   pos  0- 9 (10A): Training Package or Accreditation Code (qualcode)
//   pos 10-109 (100A): Subject/Course/Programme Name
//   pos 110+  : Training Package year, field-of-education codes, ANZSCO, etc.
//   last char : VET Programme Indicator — Y = VET qualification or skill set, N = non-AQF short course/accredited course
//
// ─── Archive Index: qualification family → keyword map ───────────────────────
// AUTO-DERIVE FAMILIES (v5.9.459) — build the qualification "family" groupings
// automatically from THIS RTO's own Moodle category tree at runtime, so archive
// grouping works out of the box with no configuration and no client data shipped in
// the product. Every category whose name carries a qualification code + title
// contributes code → family, where the family is the normalised qualification TITLE
// (year/version-agnostic). So all versions of one qualification that share a title —
// e.g. two codes both named "Diploma of Example" — automatically land in the same
// family. Reads only the site's own categories; nothing is hardcoded. Cached per request.
function local_rtocompliance_autoderive_families(): array {
    global $DB;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $map = [];
    $kw  = [];
    if ($DB->get_manager()->table_exists('course_categories')
            && function_exists('local_rtocompliance_extract_code_from_text')) {
        foreach ($DB->get_records('course_categories', null, '', 'id, name') as $cat) {
            $ext  = local_rtocompliance_extract_code_from_text((string) $cat->name);
            $code = strtoupper(trim((string) $ext['code']));
            $name = trim((string) $ext['name']);
            // Only QUALIFICATION codes (3 letters + 5 digits, e.g. TLI50119, BSB30120) —
            // never unit codes (which carry a letter inside, e.g. TLIX0036, BSBWHS311).
            if ($name === '' || !preg_match('/^[A-Z]{3}[0-9]{5}$/', $code)) {
                continue;
            }
            // Family slug = normalised title, minus any year, as a safe identifier.
            $norm = function_exists('local_rtocompliance_norm_name')
                ? local_rtocompliance_norm_name($name) : strtolower($name);
            $norm = trim(preg_replace('/\b(19|20)\d{2}\b/', '', $norm));
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($norm)), '_');
            if ($slug === '') {
                continue;
            }
            if (!isset($map[$code])) {
                $map[$code] = $slug;
            }
            if (!isset($kw[$slug])) {
                $kw[$slug] = [];
            }
            $kw[$slug][strtolower($name)] = true;   // the title itself is a keyword
            $kw[$slug][strtolower($code)] = true;   // and the code
        }
    }
    foreach ($kw as $s => $set) {
        $kw[$s] = array_keys($set);
    }
    $cache = ['map' => $map, 'keywords' => $kw];
    return $cache;
}

// Used by rebuild_archive_index() to detect family from ancestor category names.
// Auto-derived from the site's own category tree, then MERGED with the optional
// 'archivefamilykeywords' admin setting (manual entries win). No scope is hardcoded
// in the product; a new RTO gets sensible grouping with zero configuration, and the
// setting is only for corrections (e.g. two versions whose titles drifted apart).
function local_rtocompliance_archive_family_keywords(): array {
    $out = local_rtocompliance_autoderive_families()['keywords'];
    $raw = (string) get_config('local_rtocompliance', 'archivefamilykeywords');
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $fam = trim(strtolower(substr($line, 0, $pos)));
        $kws = array_values(array_filter(array_map('trim', explode(',', substr($line, $pos + 1)))));
        if ($fam !== '' && !empty($kws)) {
            // Manual keywords augment the auto-derived set for that family.
            $existing = isset($out[$fam]) ? $out[$fam] : [];
            $out[$fam] = array_values(array_unique(array_merge($existing, array_map('strtolower', $kws))));
        }
    }
    return $out;
}

// ─── Archive Index: qual-code → family map ───────────────────────────────────
// Auto-derived from the site's own category tree, then MERGED with the optional
// 'archivefamilymap' admin setting (manual "CODE = family" lines win). No client
// qualification codes are hardcoded in the product.
function local_rtocompliance_qual_to_family(): array {
    $map = local_rtocompliance_autoderive_families()['map'];
    $raw = (string) get_config('local_rtocompliance', 'archivefamilymap');
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Za-z0-9]+)\s*[=:]\s*(.+)$/', $line, $m)) {
            $map[strtoupper(trim($m[1]))] = trim(strtolower($m[2]));
        }
    }
    return $map;
}

// ─── Archive Index: detect year + semester from a single category name ────────
// Returns ['year'=>'2014','sem'=>'S1'|'S2'|''] with either possibly empty.
function local_rtocompliance_archive_detect_year_sem(string $name): array {
    $year = '';
    if (preg_match('/\b(20\d{2})\b/', $name, $m)) {
        $year = $m[1];
    } elseif (preg_match('/(?<!\d)([0-9]{2})(?!\d)/', $name, $m)) {
        $year = '20' . $m[1];
    }
    $sem = '';
    if (preg_match('/\bS[-.\s]?1\b|\bSem[-.\s]?1\b|\bSemester[-.\s]?1\b/i', $name)) {
        $sem = 'S1';
    } elseif (preg_match('/\bS[-.\s]?2\b|\bSem[-.\s]?2\b|\bSemester[-.\s]?2\b/i', $name)) {
        $sem = 'S2';
    }
    return ['year' => $year, 'sem' => $sem];
}

// ─── Archive Index: detect family from an ancestor path text string ───────────
function local_rtocompliance_archive_detect_family(string $ancestorText): string {
    $lower = mb_strtolower($ancestorText);
    foreach (local_rtocompliance_archive_family_keywords() as $family => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($kw, '*')) {
                if (preg_match('/' . $kw . '/i', $lower)) return $family;
            } else {
                if (mb_strpos($lower, $kw) !== false) return $family;
            }
        }
    }
    return '';
}

// ─── Archive Index: set or update a meta key ─────────────────────────────────
function local_rtocompliance_archive_set_meta(string $key, string $value): void {
    global $DB;
    $existing = $DB->get_record('local_rtocompliance_archive_meta', ['metakey' => $key]);
    if ($existing) {
        $existing->value = $value;
        $DB->update_record('local_rtocompliance_archive_meta', $existing);
    } else {
        $r = new stdClass();
        $r->metakey = $key;
        $r->value   = $value;
        $DB->insert_record('local_rtocompliance_archive_meta', $r);
    }
}

// ─── Archive Index: get a meta value ─────────────────────────────────────────
function local_rtocompliance_archive_get_meta(string $key): string {
    global $DB;
    $r = $DB->get_record('local_rtocompliance_archive_meta', ['metakey' => $key]);
    return $r ? (string)$r->value : '';
}

// ─── Archive Index: compute hash of current Moodle category structure ─────────
// Used to auto-trigger rebuild when any category is renamed, moved, or deleted.
function local_rtocompliance_archive_category_hash(): string {
    global $DB;
    $rows = $DB->get_records('course_categories', null, 'id ASC', 'id,parent,name,path');
    $data = [];
    foreach ($rows as $r) {
        $data[] = $r->id . '|' . $r->parent . '|' . $r->name . '|' . $r->path;
    }
    return sha1(implode("\n", $data));
}

// ─── Archive Index: rebuild ───────────────────────────────────────────────────
// Scans all Moodle course categories, extracts year+sem from each leaf/sub-cat
// name, and derives the qualification family from ancestor names.
//
// Returns an array: ['inserted'=>N, 'null_family'=>N, 'duplicates'=>N, 'hash'=>'...']
//
// Admin-assigned families are preserved across rebuilds: we snapshot the current
// family column before truncating and reuse it if the category reappears with the
// same categoryid.
function local_rtocompliance_rebuild_archive_index(): array {
    global $DB;

    // 1. Snapshot existing admin-assigned families (keyed by categoryid)
    $existingFamilies = [];
    $existing = $DB->get_records('local_rtocompliance_archive_index', null, '', 'categoryid,family');
    foreach ($existing as $e) {
        if ($e->family !== null && $e->family !== '') {
            $existingFamilies[(int)$e->categoryid] = $e->family;
        }
    }

    // 2. Clear the index (we rebuild from scratch)
    $DB->delete_records('local_rtocompliance_archive_index', []);

    // 3. Load all Moodle categories (keyed by id for path resolution)
    $allCats = $DB->get_records('course_categories', null, 'id ASC', 'id,parent,path,depth,name');
    $catMap  = [];
    foreach ($allCats as $c) {
        $catMap[(int)$c->id] = $c;
    }

    // Helper: build a human-readable full path for a category
    $buildFullPath = function (object $cat) use ($catMap): string {
        $pathIds = array_filter(explode('/', $cat->path), 'strlen');
        $names   = [];
        foreach ($pathIds as $pid) {
            if (isset($catMap[(int)$pid])) {
                $names[] = $catMap[(int)$pid]->name;
            }
        }
        return implode(' > ', $names);
    };

    // Helper: build ancestor text (all ancestor names concatenated, excluding self)
    $buildAncestorText = function (object $cat) use ($catMap): string {
        $pathIds    = array_filter(explode('/', $cat->path), 'strlen');
        $ancestorIds = array_slice($pathIds, 0, -1);
        $parts      = [];
        foreach ($ancestorIds as $pid) {
            if (isset($catMap[(int)$pid])) {
                $parts[] = $catMap[(int)$pid]->name;
            }
        }
        return implode(' ', $parts);
    };

    // 4. Load admin-selected active picks (family+year+sem → preferred categoryid)
    $activePicks = [];
    foreach ($DB->get_records('local_rtocompliance_archive_active_pick') as $p) {
        $activePicks[$p->family . '|' . $p->year . '|' . $p->sem] = (int)$p->active_categoryid;
    }

    // 5. Scan every category
    $inserted   = 0;
    $nullFamily = 0;
    $inserted_ids = []; // track (family|year|sem) → [categoryids] for duplicate detection

    foreach ($allCats as $cat) {
        $ys = local_rtocompliance_archive_detect_year_sem($cat->name);
        if ($ys['year'] === '') continue; // no year = not an archive category
        if ($ys['sem']  === '') continue; // no S1/S2 = CPD/CBC/short course, not a student enrolment archive

        $ancestorText = $buildAncestorText($cat);
        $family       = local_rtocompliance_archive_detect_family($ancestorText);

        // If keyword detection failed, try admin-assigned family from previous rebuild
        if ($family === '' && isset($existingFamilies[(int)$cat->id])) {
            $family = $existingFamilies[(int)$cat->id];
        }

        $fullPath = $buildFullPath($cat);

        $row               = new stdClass();
        $row->categoryid   = (int)$cat->id;
        $row->categoryname = $cat->name;
        $row->fullpath     = $fullPath;
        $row->family       = ($family !== '') ? $family : null;
        $row->year         = (int)$ys['year'];
        $row->sem          = $ys['sem'];
        $row->is_active    = 1;

        $DB->insert_record('local_rtocompliance_archive_index', $row);
        $inserted++;
        if ($family === '') $nullFamily++;

        if ($family !== '') {
            $pkey = $family . '|' . $ys['year'] . '|' . $ys['sem'];
            $inserted_ids[$pkey][] = (int)$cat->id;
        }
    }

    // 6. Handle duplicates: same family+year+sem → multiple rows
    $duplicates = 0;
    foreach ($inserted_ids as $pkey => $catIds) {
        if (count($catIds) < 2) continue;
        $duplicates++;
        [$fam, $yr, $sm] = explode('|', $pkey, 3);

        if (isset($activePicks[$pkey])) {
            // Admin has already resolved this: deactivate all except the chosen one
            $chosenId = $activePicks[$pkey];
            $DB->execute(
                'UPDATE {local_rtocompliance_archive_index}
                    SET is_active = CASE WHEN categoryid = ? THEN 1 ELSE 0 END
                  WHERE family = ? AND year = ? AND sem = ?',
                [$chosenId, $fam, (int)$yr, $sm]
            );
        } else {
            // No admin resolution yet — set all to inactive (imports blocked until resolved)
            $DB->set_field_select(
                'local_rtocompliance_archive_index',
                'is_active', 0,
                'family = ? AND year = ? AND sem = ?',
                [$fam, (int)$yr, $sm]
            );
        }
    }

    // 7. Store hash + metadata
    $hash = local_rtocompliance_archive_category_hash();
    local_rtocompliance_archive_set_meta('last_hash',       $hash);
    local_rtocompliance_archive_set_meta('last_rebuilt',    (string)time());
    local_rtocompliance_archive_set_meta('last_inserted',   (string)$inserted);
    local_rtocompliance_archive_set_meta('last_null_family',(string)$nullFamily);
    local_rtocompliance_archive_set_meta('last_duplicates', (string)$duplicates);

    return [
        'inserted'    => $inserted,
        'null_family' => $nullFamily,
        'duplicates'  => $duplicates,
        'hash'        => substr($hash, 0, 8),
    ];
}

// NOTE: TLISS* codes (skill sets) have isvetprog=Y but are classified separately by their code prefix.
function local_rtocompliance_parse_nat00030(string $line): ?array {
    if (strlen($line) < 11) return null;
    $qualcode = trim(substr($line, 0, 10));
    if ($qualcode === '') return null;
    $qualname  = trim(substr($line, 10, 100));
    $trimmed   = rtrim($line);
    $lastchar  = ($trimmed !== '') ? strtoupper(substr($trimmed, -1)) : null;
    $isvetprog = ($lastchar === 'Y' || $lastchar === 'N') ? $lastchar : null;
    return ['qualcode' => $qualcode, 'qualname' => $qualname, 'isvetprog' => $isvetprog];
}

// Detect collection year from enrolment start dates (most common year)
function local_rtocompliance_detect_collection_year(array $enrolments): ?string {
    // FIX-COLLECTION-YEAR-ENDDATE (v4.9.128): AVETMISS 8 collection year is determined
    // by the activity END date year, not the start date year (training that starts in
    // December 2023 and ends in February 2024 belongs to the 2024 collection).
    // Use enddate year as primary; fall back to startdate year when enddate is absent.
    $yearcounts = [];
    foreach ($enrolments as $e) {
        $datestr = (!empty($e['enddate']) && $e['enddate'] !== '@@@@@@@@' && strlen($e['enddate']) === 8)
            ? $e['enddate']
            : ($e['startdate'] ?? '');
        if (strlen($datestr) === 8) {
            $year = substr($datestr, 4, 4);
            if (ctype_digit($year)) {
                $yearcounts[$year] = ($yearcounts[$year] ?? 0) + 1;
            }
        }
    }
    if (empty($yearcounts)) return null;
    arsort($yearcounts);
    return array_key_first($yearcounts);
}

// Extract NAT type from filename (e.g. "NAT00080_12345.txt" → "NAT00080")
function local_rtocompliance_get_nat_type(string $filename): ?string {
    if (preg_match('/(NAT\d+)/i', $filename, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

// Group files by timestamp suffix so we can process one export-group at a time.
//
// FIX-TIMESTAMP-GROUPING (v4.9.128): Wisenet exports assign slightly different
// timestamps to different files within the SAME batch — e.g. NAT00080 gets
// _1778752696696 while NAT00120 gets _1778752696697 (1-digit difference).
// The old code used the exact timestamp as the group key, so every file landed
// in a separate group: students in one group, enrolments in another.  The import
// ran without error but produced unlinked datasets (students with no enrolments,
// enrolments with no students).
//
// Fix: divide the timestamp by 10,000,000 (≈2.8 hours) so all files from the
// same Wisenet session share the same group key.  Timestamps from different
// collection years differ by ~31,536,000,000 ms (1 year) so they are never
// accidentally merged.
function local_rtocompliance_group_by_timestamp(array $files): array {
    $groups = [];
    foreach ($files as $file) {
        if (preg_match('/_(\d+)\.txt$/i', $file['name'], $m)) {
            // Round to nearest 10-million ms window (~2.8 hours) so all files
            // in a single Wisenet export batch share one key even when their
            // individual timestamps differ by a few seconds / digits.
            // FIX-TIMESTAMP-32BIT (v4.9.130): (int)$m[1] overflows PHP_INT_MAX on 32-bit PHP
            // for any 13-digit Wisenet timestamp (> 2,147,483,647), silently producing a
            // wrong negative group key so files from the same batch land in separate groups.
            // floatval() handles arbitrarily large digit strings safely on all platforms.
            $key = (string)(int)(floatval($m[1]) / 10000000);
        } else {
            $key = 'default';
        }
        $groups[$key][] = $file;
    }
    return $groups;
}

/**
 * Human-readable label for an auto-detected USI column position.
 */
function local_rtocompliance_nat_format_label(int $usiPos): string {
    if ($usiPos === -2)  return 'Tab-delimited SMS export (quoted name field, USI in 3rd column)';
    if ($usiPos === 149) return 'Wisenet Extended (60-char name, 50-char suburb before USI)';
    if ($usiPos === 90)  return 'Standard AVETMISS 8 (no suburb block)';
    if ($usiPos === 140) return 'Standard AVETMISS 8 + suburb block before USI';
    if ($usiPos  <  0)  return 'Unknown — fallback detection active';
    return 'Custom / other SMS vendor (column ' . $usiPos . ')';
}

/**
 * BUG-MEMORY-NATIMPORT (v4.9.168): Stream lines from a NAT file entry one at a time
 * to avoid loading the entire file into a PHP array. For disk-backed tmppath entries
 * the file is opened with fgets(); for legacy in-memory 'content' entries the already-
 * normalised string is split with explode(). Yields non-blank lines only.
 */
function local_rtocompliance_nat_lines(array $file): Generator {
    // BUG-ENCODING-NATIMPORT (v4.9.169): AVETMISS NAT files from some SMS vendors
    // (e.g. Wisenet) are encoded in ISO-8859-1 / Windows-1252 rather than ASCII/UTF-8.
    // Extended bytes such as \xE9 (é in latin-1) are illegal in UTF-8 and cause a
    // MySQL dml_write_exception "Incorrect string value" when the raw byte is inserted.
    // Fix: after reading each line, check whether it is valid UTF-8; if not, convert it
    // from ISO-8859-1 to UTF-8 via mb_convert_encoding().  Lines that are already valid
    // UTF-8 (the common case) are passed through unchanged.
    $ensure_utf8 = function (string $l): string {
        return mb_check_encoding($l, 'UTF-8') ? $l : mb_convert_encoding($l, 'UTF-8', 'ISO-8859-1');
    };

    if (isset($file['content'])) {
        foreach (explode("\n", (string)$file['content']) as $line) {
            $l = rtrim($line, "\r");
            if (trim($l) !== '') yield $ensure_utf8($l);
        }
        return;
    }
    if (empty($file['tmppath'])) return;
    $fh = @fopen($file['tmppath'], 'r');
    if (!$fh) return;
    // Strip UTF-8 BOM defensively (should have been removed at upload time, v4.9.130).
    $peek = fread($fh, 3);
    if ($peek !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    while (($raw = fgets($fh)) !== false) {
        $l = rtrim($raw, "\r\n");
        if (trim($l) !== '') yield $ensure_utf8($l);
    }
    fclose($fh);
}

/**
 * Parse a small preview sample from a raw NAT00080 file content string.
 * Returns up to $limit parsed student records using the given USI column position.
 */
// FIX-NAT00080-STRICT-OVERRIDE (v4.9.122): $strict=true skips fallback Methods 1/2/3
// so the preview reflects exactly what the admin entered in the position field.
function local_rtocompliance_preview_nat00080_records(string $content, int $usiPos, int $limit = 12, bool $strict = false): array {
    // Content stored in session is already normalised to \n at upload time (v4.9.130).
    // Keep the defensive split pattern so this function is safe if called with raw content.
    $lines   = preg_split('/\r\n|\r|\n/', $content);
    $records = [];
    foreach ($lines as $line) {
        if (count($records) >= $limit) break;
        if (trim($line) === '') continue;
        $p = local_rtocompliance_parse_nat00080($line, $usiPos, $strict);
        if ($p) $records[] = $p;
    }
    return $records;
}

// Parse one group of NAT files and return structured data.
// $usiPosOverride: when >= 0 skips auto-detection and uses this column directly.
function local_rtocompliance_parse_nat_group(array $files, int $usiPosOverride = -1): array {
    // BUG-MEMORY-NATIMPORT (v4.9.168): large NAT exports (10k+ students, 50k+ enrolments)
    // easily exhaust the default 128 MB PHP memory limit when the full file is loaded
    // into a preg_split() array.  Raise to MEMORY_HUGE (512 MB on standard Moodle
    // installs) before building $studentmap / $enrolments.  Files are now streamed
    // line-by-line via local_rtocompliance_nat_lines() — see below.
    raise_memory_limit(MEMORY_HUGE);

    $studentmap  = [];
    $enrolments  = [];
    $completions = [];
    $programmes  = []; // NAT00030: qualcode → ['qualcode','qualname','isvetprog']
    // AVETMISS-ROUNDTRIP (v5.9.460): NAT00090 (disability) and NAT00100 (prior education)
    // DETAIL, accumulated per client identifier. One NAT00090 row per disability type and
    // one NAT00100 row per prior-education code, so we collect lists and apply them to the
    // live student register after import (see local_rtocompliance_apply_nat_detail_to_students).
    $natdisability = []; // clientid => [type, type, …]
    $natpriored    = []; // clientid => [code, code, …]
    $filesprocessed = [];
    $rtoid   = '';
    $rtoname = null;

    // FIX-NAT130-FALLBACK-IMPORT (v4.9.125): The upload handler (Step 1) already
    // detects when the only student file in a batch is named NAT00130 and stores
    // its content as nat80content for USI detection and the preview.  But
    // parse_nat_group was still routing NAT00130 to parse_nat00130() (completions
    // format) at import time — so no students were ever stored.  Fix: when no
    // NAT00080 file exists in the group, treat any NAT00130 file as student data
    // and parse it with parse_nat00080(), mirroring the upload-handler logic.
    $hasNat80File = false;
    foreach ($files as $_f) {
        if (local_rtocompliance_get_nat_type($_f['name']) === 'NAT00080') {
            $hasNat80File = true;
            break;
        }
    }
    $nat130AsStudentFallback = !$hasNat80File;

    foreach ($files as $file) {
        $filetype = local_rtocompliance_get_nat_type($file['name']);
        if (!$filetype) continue;
        $filesprocessed[] = $file['name'];

        // BUG-MEMORY-NATIMPORT (v4.9.168): replaced file_get_contents() + preg_split()
        // with local_rtocompliance_nat_lines() which streams the file line-by-line.
        // NAT00080 / NAT00130-as-students need USI position detection before parsing,
        // so they use a two-pass approach: first collect up to 100 lines for detection,
        // then re-stream all lines for the actual parse — two fgets passes on disk,
        // or two explode passes on an already-in-memory 'content' string (negligible).

        if ($filetype === 'NAT00010') {
            // Single-line header file — only need the first line.
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                if (!$rtoid) $rtoid = trim(substr($line, 0, 10));
                // FIX-NAT00010-RTONAME-50CHAR (v5.2.23): Previous code read 50 chars
                // (pos 10-59) which truncated org names longer than 50 chars — e.g.
                // "Australian Institute of Professionals (AIP) Pty Ltd" (51 chars) was
                // returned as "...Pty Lt".  The AVETMISS 8.0 spec field is 40A but some
                // SMS vendors write a longer padded string.  Read 100 chars and trim so
                // any reasonable org name is captured in full.
                if (!$rtoname) $rtoname = trim(substr($line, 10, 100)) ?: null;
                break;
            }

        } elseif ($filetype === 'NAT00080') {
            // First pass: collect up to 100 lines for USI position auto-detection.
            $sampleLines = [];
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $sampleLines[] = $line;
                if (count($sampleLines) >= 100) break;
            }
            $detectedUsiPos = ($usiPosOverride >= 0)
                ? $usiPosOverride
                : local_rtocompliance_detect_nat00080_usi_pos($sampleLines);
            $sampleLines = null; // free sample buffer before second pass

            // Second pass: stream all lines using the detected position.
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00080($line, $detectedUsiPos);
                if (!$parsed) continue;
                $cid = $parsed['clientid'];
                if (isset($studentmap[$cid])) {
                    $studentmap[$cid] = array_merge($studentmap[$cid], $parsed);
                } else {
                    $studentmap[$cid] = $parsed;
                }
            }

        } elseif ($filetype === 'NAT00085') {
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00085($line);
                if (!$parsed || empty($parsed['clientid'])) continue;
                $cid = $parsed['clientid'];
                if (!isset($studentmap[$cid])) {
                    $studentmap[$cid] = ['clientid' => $cid, 'name' => '', 'hasdataissues' => 0, 'dataissuefields' => '[]'];
                }
                $studentmap[$cid] = array_merge($studentmap[$cid], array_filter($parsed, fn($v) => $v !== null));
            }

        } elseif ($filetype === 'NAT00120') {
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00120($line);
                if (!$parsed) continue;
                if (!$rtoid && !empty($parsed['rtoid'])) $rtoid = $parsed['rtoid'];
                unset($parsed['rtoid']);
                $enrolments[] = $parsed;
            }

        } elseif ($filetype === 'NAT00130') {
            if ($nat130AsStudentFallback) {
                // NAT130-FALLBACK-IMPORT: vendor named the student demographics file
                // NAT00130 instead of NAT00080.  Two-pass: detect USI pos, then parse.
                $sampleLines130 = [];
                foreach (local_rtocompliance_nat_lines($file) as $line) {
                    $sampleLines130[] = $line;
                    if (count($sampleLines130) >= 100) break;
                }
                $detectedUsiPos130 = ($usiPosOverride >= 0)
                    ? $usiPosOverride
                    : local_rtocompliance_detect_nat00080_usi_pos($sampleLines130);
                $sampleLines130 = null;

                foreach (local_rtocompliance_nat_lines($file) as $line) {
                    $parsed = local_rtocompliance_parse_nat00080($line, $detectedUsiPos130);
                    if (!$parsed) continue;
                    $cid = $parsed['clientid'];
                    if (isset($studentmap[$cid])) {
                        $studentmap[$cid] = array_merge($studentmap[$cid], $parsed);
                    } else {
                        $studentmap[$cid] = $parsed;
                    }
                }
            } else {
                foreach (local_rtocompliance_nat_lines($file) as $line) {
                    $parsed = local_rtocompliance_parse_nat00130($line);
                    if (!$parsed) continue;
                    $completions[] = $parsed;
                }
            }

        } elseif ($filetype === 'NAT00030') {
            // NAT00030: Programme/Qualification register — one line per programme.
            // Keyed by qualcode so duplicates (rare) are de-duped automatically.
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00030($line);
                if (!$parsed || $parsed['qualcode'] === '') continue;
                $programmes[$parsed['qualcode']] = $parsed;
            }

        } elseif ($filetype === 'NAT00090') {
            // AVETMISS-ROUNDTRIP (v5.9.460): disability detail — one row per disability type.
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00090_line($line);
                if (!$parsed) continue;
                $natdisability[$parsed['clientid']][] = $parsed['type'];
            }

        } elseif ($filetype === 'NAT00100') {
            // AVETMISS-ROUNDTRIP (v5.9.460): prior educational achievement — one row per code.
            foreach (local_rtocompliance_nat_lines($file) as $line) {
                $parsed = local_rtocompliance_parse_nat00100_line($line);
                if (!$parsed) continue;
                $natpriored[$parsed['clientid']][] = $parsed['code'];
            }
        }
    }

    // FIX-NAT85-ONLY-FLAGS (v4.9.136): Data-issue flags (usi_missing, dob_not_stated,
    // sex_not_stated) were only computed inside parse_nat00080() and baked into the
    // per-line return value.  When a clientid appeared in NAT00085 but NOT in NAT00080
    // (a common SMS data-quality scenario where the demographics export is partial),
    // parse_nat_group created a stub entry with hasdataissues=0, 'dataissuefields'=>'[]'.
    // After merging the NAT85 contact data those students still had no USI, DOB, or sex,
    // but they were stored unflagged — invisible in the "Flagged" count on the list view
    // and without a yellow warning row in the detail view.
    //
    // Fix: after the file-processing loop, re-derive flags from the FINAL merged state of
    // every student.  For students that came from NAT00080 this produces an identical
    // result (correctness-preserving).  For NAT85-only stubs it now correctly sets
    // hasdataissues=1 with the relevant missing-field codes.
    foreach ($studentmap as $cid => &$stud) {
        $issues = [];
        if (empty($stud['usi']))                          $issues[] = 'usi_missing';
        if (!isset($stud['dob'])  || $stud['dob']  === null) $issues[] = 'dob_not_stated';
        // sex='@' is the official AVETMISS "not stated" code — treat as stated (not an issue).
        if (!isset($stud['sex'])  || $stud['sex']  === null) $issues[] = 'sex_not_stated';
        $stud['hasdataissues']   = !empty($issues) ? 1 : 0;
        $stud['dataissuefields'] = json_encode(array_values($issues));
    }
    unset($stud);

    $collectionyear = local_rtocompliance_detect_collection_year($enrolments);
    $students       = array_values($studentmap);

    return [
        'rtoid'          => $rtoid,
        'rtoname'        => $rtoname,
        'collectionyear' => $collectionyear,
        'students'       => $students,
        'enrolments'     => $enrolments,
        'completions'    => $completions,
        'programmes'     => $programmes, // NAT00030 qual name data; empty if file not uploaded
        'disabilitydetail' => $natdisability, // AVETMISS-ROUNDTRIP: NAT00090 clientid => [types]
        'prioreddetail'    => $natpriored,    // AVETMISS-ROUNDTRIP: NAT00100 clientid => [codes]
        'filesprocessed' => $filesprocessed,
    ];
}

/**
 * AVETMISS-ROUNDTRIP (v5.9.460) — parse one NAT00090 (Disability) line.
 * The disability type identifier is the LAST 2 characters and the client identifier is
 * the 10 characters immediately before it. Parsing from the end makes it robust to
 * whether the file carries the optional leading 10-char training-organisation id
 * (22-char rows, as this plugin exports) or not (12-char rows).
 *
 * @return array|null ['clientid'=>string,'type'=>string]
 */
function local_rtocompliance_parse_nat00090_line(string $line): ?array {
    $line = rtrim($line, "\r\n");
    if (strlen($line) < 12) {
        return null;
    }
    $type     = trim(substr($line, -2));
    $clientid = trim(substr($line, -12, 10));
    if ($clientid === '' || $type === '') {
        return null;
    }
    return ['clientid' => $clientid, 'type' => $type];
}

/**
 * AVETMISS-ROUNDTRIP (v5.9.460) — parse one NAT00100 (Prior educational achievement)
 * line. The 3-char achievement code is the LAST 3 characters; the client identifier is
 * the 10 characters before it. Robust to the optional org-id prefix (23 vs 13 chars).
 *
 * @return array|null ['clientid'=>string,'code'=>string]
 */
function local_rtocompliance_parse_nat00100_line(string $line): ?array {
    $line = rtrim($line, "\r\n");
    if (strlen($line) < 13) {
        return null;
    }
    $code     = trim(substr($line, -3));
    $clientid = trim(substr($line, -13, 10));
    if ($clientid === '' || $code === '') {
        return null;
    }
    return ['clientid' => $clientid, 'code' => $code];
}

/**
 * AVETMISS-ROUNDTRIP (v5.9.460) — apply NAT00090 / NAT00100 detail to EXISTING students
 * in the live register, matched by client identifier (then Moodle userid). Sets
 * disabilitytypes + disabilityflag and priorachevement1-4 + prioreducationflag. This
 * closes the round-trip gap where the importer previously read only the NAT00080 flag
 * byte and never the detail files, so those fields (which NAT00090/00100 export and the
 * validator checks) could only ever be blank. Never creates students; returns counts.
 *
 * @param array $disability [clientid => [type, …]]
 * @param array $priored    [clientid => [code, …]]
 * @return array ['disability'=>int,'priored'=>int,'unmatched'=>int]
 */
function local_rtocompliance_apply_nat_detail_to_students(array $disability, array $priored): array {
    global $DB;
    $res = ['disability' => 0, 'priored' => 0, 'unmatched' => 0];
    if (!$DB->get_manager()->table_exists('local_rtocompliance_students')) {
        return $res;
    }
    $clientids = array_unique(array_merge(array_keys($disability), array_keys($priored)));
    foreach ($clientids as $cid) {
        $cid = trim((string) $cid);
        if ($cid === '') {
            continue;
        }
        $student = $DB->get_record('local_rtocompliance_students', ['clientid' => $cid], 'id', IGNORE_MULTIPLE);
        if (!$student && ctype_digit($cid)) {
            $student = $DB->get_record('local_rtocompliance_students', ['userid' => (int) $cid], 'id', IGNORE_MULTIPLE);
        }
        if (!$student) {
            $res['unmatched']++;
            continue;
        }
        $upd = ['id' => $student->id];
        if (!empty($disability[$cid])) {
            $types = array_values(array_unique(array_filter(array_map('trim', $disability[$cid]))));
            if (!empty($types)) {
                $upd['disabilitytypes'] = substr(implode(',', $types), 0, 50);
                $upd['disabilityflag']  = 'Y';
                $res['disability']++;
            }
        }
        if (!empty($priored[$cid])) {
            $codes = array_values(array_unique(array_filter(array_map('trim', $priored[$cid]))));
            if (!empty($codes)) {
                for ($i = 0; $i < 4; $i++) {
                    $upd['priorachevement' . ($i + 1)] = isset($codes[$i]) ? substr($codes[$i], 0, 3) : null;
                }
                $upd['prioreducationflag'] = 'Y';
                $res['priored']++;
            }
        }
        if (count($upd) > 1) {
            $DB->update_record('local_rtocompliance_students', (object) $upd);
        }
    }
    return $res;
}

// FIX-AUTOENROL-PASSWORD-POLICY (v4.9.163): Generate a password that satisfies
// Moodle's standard password policy (passwordpolicy=1, the default on most sites).
// The previous code used random_string(20) which returns only [a-z0-9] — missing
// the uppercase and non-alphanumeric characters that Moodle's policy requires.
// user_create_user() validates the password against the site policy BEFORE hashing
// it; when validation fails it throws a moodle_exception, which was caught by our
// catch(\Exception) block and counted as a 'createfailed' skip.  Admins saw "0
// students enrolled" with no clear explanation.  Fix: build a 12-char password
// that guarantees at least one character from each required class.
function local_rtocompliance_generate_policy_password(): string {
    $lower   = 'abcdefghjkmnpqrstuvwxyz';    // no ambiguous l
    $upper   = 'ABCDEFGHJKMNPQRSTUVWXYZ';    // no ambiguous I
    $digits  = '23456789';                    // no ambiguous 0/1
    $special = '!@#$%^*-+=';
    $all     = $lower . $upper . $digits . $special;
    // Guarantee one char from each class so the Moodle default policy always passes.
    $pw  = $lower  [random_int(0, strlen($lower)   - 1)];
    $pw .= $upper  [random_int(0, strlen($upper)   - 1)];
    $pw .= $digits [random_int(0, strlen($digits)  - 1)];
    $pw .= $special[random_int(0, strlen($special) - 1)];
    // Pad to 12 total characters from the full set.
    for ($i = 0; $i < 8; $i++) {
        $pw .= $all[random_int(0, strlen($all) - 1)];
    }
    // Shuffle so the guaranteed-class chars are not always at positions 0–3.
    return str_shuffle($pw);
}

// Format DDMMYYYY as DD/MM/YYYY for display.
// FIX-AVETMISS-NOT-STATED (v4.9.131): AVETMISS uses '@@@@@@@@' to indicate a
// date that is "not stated".  Previously this was passed through and rendered as
// '@@/@@/@@@@' in every date column.  Now explicitly mapped to the dash sentinel.
// FIX-ZEROS-DATE (v5.2.5): Some SMS vendors export '00000000' for unrecorded dates;
// this is now mapped to '—' alongside the @-sentinel and empty-string cases.
function local_rtocompliance_format_ddmmyyyy(?string $raw): string {
    if (!$raw) return '—';
    if (preg_match('/^[@\s0]+$/', $raw)) return '—';   // AVETMISS not-stated (@@@@@@@@) or all-zeros
    // FIX-COMPACT-YEAR (v5.2.24): Compact NAT00130 stores only a 4-digit collection year
    // (e.g. "2016") — display it as-is rather than '—'.
    if (strlen($raw) === 4 && ctype_digit($raw)) return $raw;
    if (strlen($raw) < 8) return '—';
    return substr($raw, 0, 2) . '/' . substr($raw, 2, 2) . '/' . substr($raw, 4, 4);
}

// ─── FOE-BATCH: AJAX chunk processor (v5.9.94) ───────────────────────────────
// Called repeatedly by the JS progress bar on the foe_progress page.
// Processes up to 200 pending rows from local_rtocompliance_foe_pending per
// request and returns JSON so the caller can update the progress bar.
// Each request completes in ~1-2s regardless of total batch size.
if ($action === 'foe_apply_chunk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // READ-ONLY MODE: enrolment write operations have been disabled.
    // All enrolment changes must be handled directly at the database level by the developer.
    header('Content-Type: application/json');
    echo json_encode([
        'error'       => 'read_only_mode',
        'message'     => 'Enrolment write operations are disabled. Please contact your developer to apply changes directly.',
        'processed'   => 0,
        'remaining'   => 0,
        'total'       => 0,
        'errors'      => 0,
        'completions' => 0,
        'finished'    => true,
    ]);
    exit;

    $batchid = required_param('batchid', PARAM_ALPHANUMEXT);

    // How many rows are left to process?
    $remaining = (int)$DB->count_records('local_rtocompliance_foe_pending',
        ['batchid' => $batchid, 'status' => 'pending']);
    $total = (int)$DB->count_records('local_rtocompliance_foe_pending',
        ['batchid' => $batchid]);

    if ($total === 0) {
        // Batch not found or already fully processed.
        // batch_empty=true lets the JS distinguish "nothing to do" (already done)
        // from a real count, so the progress bar doesn't silently report "N removed"
        // when the pending rows were never inserted.
        header('Content-Type: application/json');
        echo json_encode(['processed' => 0, 'remaining' => 0, 'total' => 0,
            'errors' => 0, 'completions' => 0, 'finished' => true, 'batch_empty' => true]);
        exit;
    }

    // Grab next chunk of up to 200 pending rows.
    $rows = $DB->get_records_sql(
        "SELECT * FROM {local_rtocompliance_foe_pending}
          WHERE batchid = :batchid AND status = 'pending'
          ORDER BY id ASC",
        ['batchid' => $batchid],
        0, 200
    );

    $_enrolPlugin = enrol_get_plugin('manual');
    $_processed   = 0;
    $_errors      = 0;
    $_completions = 0;

    foreach ($rows as $row) {
        $_rowUid  = (int)$row->userid;
        $_rowCid  = (int)$row->courseid;
        $_rowEid  = (int)$row->enrolid;

        $inst = $DB->get_record('enrol', ['id' => $_rowEid], '*', IGNORE_MISSING);
        if (!$inst || !$_enrolPlugin) {
            $DB->set_field('local_rtocompliance_foe_pending', 'status', 'error', ['id' => (int)$row->id]);
            $_errors++;
            continue;
        }

        // CRITICAL: Verify the user_enrolments record exists BEFORE calling unenrol_user().
        // unenrol_user() returns void with no exception when the record is missing — so
        // $_processed++ would fire even if nothing was removed, giving a false "Done! N removed".
        // Root cause scenario: if the enrol instance was recreated between the detection query
        // and this apply step, foe_pending.enrolid holds the OLD instance id but
        // user_enrolments.enrolid now points to the NEW instance — unenrol_user() silently
        // finds no row and returns. The sweep below catches this, but we must count correctly.
        $_ueBeforeRow = $DB->get_record(
            'user_enrolments',
            ['enrolid' => $_rowEid, 'userid' => $_rowUid],
            'id',
            IGNORE_MISSING
        );
        // Also count ALL active enrolments for this user+course (any plugin) so we
        // can verify the student is actually unenrolled after the full sweep.
        $_ueAllBefore = (int)$DB->count_records_sql(
            "SELECT COUNT(*) FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :cid AND ue.userid = :uid AND ue.status = 0",
            ['cid' => $_rowCid, 'uid' => $_rowUid]
        );

        try {
            // Primary unenrolment via the manual enrol plugin instance stored in foe_pending.
            if ($_ueBeforeRow) {
                if (!RTOC_ENROL_WRITES_DISABLED) { $_enrolPlugin->unenrol_user($inst, $_rowUid); }
            }

            // SWEEP: Remove ANY remaining active enrolments for this user+course via
            // any enrolment method. Covers the case where:
            //  (a) the manual enrol instance was recreated (foe_pending.enrolid is stale)
            //  (b) the student also has a cohort/self/meta enrolment keeping them visible
            // We exclude the already-processed instance only if it was found above.
            $_skipId = $_ueBeforeRow ? $_rowEid : -1;
            $_otherInsts = $DB->get_records_sql(
                "SELECT e.id, e.enrol FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                 WHERE e.courseid = :cid AND ue.userid = :uid
                   AND e.id <> :skipid AND ue.status = 0",
                ['cid' => $_rowCid, 'uid' => $_rowUid, 'skipid' => $_skipId]
            );
            foreach ($_otherInsts as $_oi) {
                $_oPlugin = enrol_get_plugin($_oi->enrol);
                $_oInst   = $DB->get_record('enrol', ['id' => (int)$_oi->id], '*', IGNORE_MISSING);
                if ($_oPlugin && $_oInst) {
                    try { if (!RTOC_ENROL_WRITES_DISABLED) { $_oPlugin->unenrol_user($_oInst, $_rowUid); } } catch (\Exception $_oe) {}
                }
            }

            // VERIFY: confirm the student is actually removed from the course.
            // If any active user_enrolments records remain, count this as an error
            // with a diagnostic message in the pending table so it is visible on retry.
            $_ueAllAfter = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :cid AND ue.userid = :uid AND ue.status = 0",
                ['cid' => $_rowCid, 'uid' => $_rowUid]
            );

            if ($_ueAllAfter > 0 && $_ueAllBefore > 0) {
                // unenrol_user() did not remove the student. This indicates either:
                //  - The enrol instance was recreated (foe_pending.enrolid is stale) AND
                //    the sweep also failed to catch it (shouldn't happen but guard anyway)
                //  - A Moodle-level permission or configuration blocked the deletion
                // Mark as error so the admin can see it in the "N error(s)" count.
                $DB->set_field('local_rtocompliance_foe_pending', 'status', 'error', ['id' => (int)$row->id]);
                $_errors++;
                // Log full context to PHP error log for server-side diagnosis.
                error_log('[FOE] unenrol_user() SILENT FAIL: userid=' . $_rowUid
                    . ' courseid=' . $_rowCid . ' enrolid=' . $_rowEid
                    . ' ue_before=' . $_ueAllBefore . ' ue_after=' . $_ueAllAfter
                    . ' inst_enrol=' . ($inst->enrol ?? '?')
                    . ' inst_status=' . ($inst->status ?? '?'));
                continue;
            }

            $_processed++;

            // Delete phantom course_completions rows.
            try {
                $ccRows = $DB->get_records('course_completions', [
                    'userid' => $_rowUid,
                    'course' => $_rowCid,
                ]);
                foreach ($ccRows as $ccRec) {
                    if (!RTOC_ENROL_WRITES_DISABLED) { $DB->delete_records('course_completions', ['id' => (int)$ccRec->id]); }
                    $_completions++;
                }
            } catch (\Exception $cce) { /* ignore */ }

            $DB->set_field('local_rtocompliance_foe_pending', 'status', 'done', ['id' => (int)$row->id]);
        } catch (\Exception $fe) {
            $DB->set_field('local_rtocompliance_foe_pending', 'status', 'error', ['id' => (int)$row->id]);
            $_errors++;
            error_log('[FOE] unenrol_user() EXCEPTION: userid=' . $_rowUid
                . ' courseid=' . $_rowCid . ' enrolid=' . $_rowEid
                . ' msg=' . $fe->getMessage());
        }
    }

    // Release session lock AFTER the unenrol loop.
    // unenrol_user() triggers events that update $USER->enrolments (a session write).
    // Closing the session before those writes causes them to fail silently, leaving
    // the student still enrolled with no exception thrown.
    \core\session\manager::write_close();

    $remaining = (int)$DB->count_records('local_rtocompliance_foe_pending',
        ['batchid' => $batchid, 'status' => 'pending']);
    $finished  = ($remaining === 0);

    // Clean up the batch table when all rows are processed.
    if ($finished) {
        $DB->delete_records('local_rtocompliance_foe_pending', ['batchid' => $batchid]);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'processed'   => $_processed,
        'remaining'   => $remaining,
        'total'       => $total,
        'errors'      => $_errors,
        'completions' => $_completions,
        'finished'    => $finished,
    ]);
    exit;
}

// ─── Handle delete ────────────────────────────────────────────────────────────

if ($action === 'delete' && $importid && confirm_sesskey()) {
    // FIX-DELETE-TRANSACTION (v4.9.131): Wrap all four deletes in a single
    // delegated transaction so a mid-delete server crash cannot leave orphaned
    // child rows (enrolments/students with no parent import header).
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('local_rtocompliance_avetmiss_completion',  ['importid' => $importid]);
    $DB->delete_records('local_rtocompliance_avetmiss_enrolment',   ['importid' => $importid]);
    $DB->delete_records('local_rtocompliance_avetmiss_student',     ['importid' => $importid]);
    $DB->delete_records('local_rtocompliance_enrol_rollback',       ['importid' => $importid]);
    $DB->delete_records('local_rtocompliance_avetmiss',             ['id'       => $importid]);
    $transaction->allow_commit();
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php'),
        get_string('dataimport_deleted', 'local_rtocompliance'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── Handle rollback enrolments ───────────────────────────────────────────────

if ($action === 'rollback' && $importid && confirm_sesskey()) {
    // READ-ONLY MODE: enrolment write operations have been disabled.
    // All enrolment changes must be handled directly at the database level by the developer.
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]),
        'Enrolment write operations are disabled. Please contact your developer to apply changes directly.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );

    $rbRecords = $DB->get_records('local_rtocompliance_enrol_rollback', ['importid' => $importid]);

    $unenroled           = 0;
    $completionsRemoved  = 0;
    $usersSuspended      = 0;
    $suspendedUserIds    = [];

    foreach ($rbRecords as $rb) {
        // Unenrol from course.
        if ($enrolplugin) {
            $inst = $DB->get_record('enrol', ['id' => (int)$rb->enrolid]);
            if ($inst) {
                try {
                    if (!RTOC_ENROL_WRITES_DISABLED) { $enrolplugin->unenrol_user($inst, (int)$rb->userid); }
                    $unenroled++;
                } catch (\Exception $e) {
                    debugging('rtocompliance rollback: unenrol_user failed for userid='
                        . $rb->userid . ' courseid=' . $rb->courseid . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER);
                }
            }
        }

        // Remove or reset course completion.
        if ((int)$rb->cc_id > 0) {
            try {
                if ((int)$rb->cc_inserted === 1) {
                    if (!RTOC_ENROL_WRITES_DISABLED) { $DB->delete_records('course_completions', ['id' => (int)$rb->cc_id]); }
                } else {
                    $DB->set_field('course_completions', 'timecompleted', null, ['id' => (int)$rb->cc_id]);
                }
                $completionsRemoved++;
            } catch (\Exception $e) {
                debugging('rtocompliance rollback: completion removal failed for cc_id='
                    . $rb->cc_id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Suspend auto-created accounts (safer than deleting; deduplicated across courses).
        if ((int)$rb->user_created === 1 && !in_array((int)$rb->userid, $suspendedUserIds, true)) {
            try {
                $DB->set_field('user', 'suspended', 1, ['id' => (int)$rb->userid]);
                $suspendedUserIds[] = (int)$rb->userid;
                $usersSuspended++;
            } catch (\Exception $e) {
                debugging('rtocompliance rollback: user suspend failed for userid='
                    . $rb->userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    $DB->delete_records('local_rtocompliance_enrol_rollback', ['importid' => $importid]);

    $msg = 'Rollback complete: ' . $unenroled . ' enrolment(s) removed'
        . ($completionsRemoved ? ', ' . $completionsRemoved . ' completion record(s) cleared' : '')
        . ($usersSuspended ? ', ' . $usersSuspended . ' auto-created account(s) suspended' : '') . '.';

    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// FIX-OVER-ENROLMENTS re-enabled — v5.9.69

// ── FOE unit-code extraction helper (v5.9.93) ─────────────────────────────────
// Finds an AVETMISS unit code in priority order:
//   (1) The course idnumber field, if it already looks like a unit code.
//   (2) The START of the course shortname (e.g. "ABC12345 BIO" → "ABC12345").
//   (3) The START of the course fullname  (e.g. "ABC12345 Comply with..." → "ABC12345").
// Returns '' if no unit code can be identified.
// Pattern: 2-7 uppercase letters followed by 3-5 digits — covers virtually all
// AVETMISS unit codes (ABC12345, BSBOPS505, BSBLDR522, ABC12345 …) while
// rejecting Wisenet-style IDs like "LIE5020226" (7 digits, too many) and
// combined IDs like "LIE5020226/LIE5020226" (contains "/").
if (!function_exists('_foe_extract_unitcode')) {
    /**
     * Extract an AVETMISS unit code from a Moodle course's idnumber / shortname / fullname.
     *
     * Matching priority:
     *   1. idnumber is EXACTLY a unit code          e.g. "ABC12345"
     *   2. idnumber STARTS WITH a unit code         e.g. "ABC12345 (CP1) S1-2016"
     *   3. shortname starts with a unit code        e.g. "ABC12345 26S1"
     *   4. fullname starts with a unit code
     *
     * FIX-FOE-REGEX-TRAILING-LETTER: Added [A-Z]? to both exact-match and prefix-match
     * patterns so unit codes ending in a letter (e.g. ABC12345, BSBOHS201A) are correctly
     * extracted. Previously the missing [A-Z]? caused these courses to return '' — making
     * them invisible to FOE entirely (not flagged, but also not checked).
     * Also added step 2 (idnumber prefix match) that _reconcile_extract_unitcode() gained
     * in v5.9.112 but was never backported here.
     * These two functions must stay in sync.
     */
    function _foe_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
        $idn = strtoupper(trim($idnumber));
        $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
        // Step 1: idnumber is exactly a unit code (with optional trailing letter)
        if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) {
            return $idn;
        }
        // Step 2: idnumber starts with a unit code (unit code followed by space/paren/dash/etc.)
        if (preg_match($pat, $idn, $m)) {
            return $m[1];
        }
        // Step 3: shortname starts with a unit code
        if (preg_match($pat, strtoupper(trim($shortname)), $m)) {
            return $m[1];
        }
        // Step 4: fullname starts with a unit code
        if (preg_match($pat, strtoupper(trim($fullname)), $m)) {
            return $m[1];
        }
        return '';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FOE-TRACE: Step-by-step diagnostic for a single student (v5.9.91)
// Usage: data_import.php?action=foe_trace&importid=X&tracecid=8215
// Proves every step of the Fix Over-Enrolments pipeline with real DB data.
// Each step is independently queried so results cannot be confused with the
// main FOE code path. Designed to be run BEFORE applying FOE so data is live.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'foe_trace' && $importid) {
    require_capability('local/rtocompliance:manage', $context);
    \core\session\manager::write_close();

    $_traceCid   = strtolower(trim(optional_param('tracecid', '', PARAM_RAW))); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    $_traceLcCid = $_traceCid; // already lowercased

    echo $OUTPUT->header();
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb">'
       . '<li class="breadcrumb-item"><a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '">Import Detail</a></li>'
       . '<li class="breadcrumb-item"><a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'fix_overenrolments', 'importid' => $importid]))->out() . '">Fix Over-Enrolments</a></li>'
       . '<li class="breadcrumb-item active">Student Diagnostic Trace</li></ol></nav>';
    echo '<h3>&#128270; FOE Student Diagnostic Trace</h3>';

    // Input form
    echo '<form method="get" action="" style="margin-bottom:1.5rem;background:#f8f9fa;padding:1rem;border-radius:6px;border:1px solid #dee2e6;">';
    echo '<input type="hidden" name="action"   value="foe_trace">';
    echo '<input type="hidden" name="importid" value="' . (int)$importid . '">';
    echo '<label style="font-weight:600;">NAT Client ID to trace: </label> ';
    echo '<input type="text" name="tracecid" value="' . htmlspecialchars($_traceCid) . '" placeholder="e.g. 8215" style="width:160px;padding:4px 8px;border:1px solid #ced4da;border-radius:4px;"> ';
    echo '<button type="submit" class="btn btn-primary btn-sm" title="Trace this client ID through the import to see why enrolment did or did not happen">Run Trace</button>';
    echo '</form>';

    if ($_traceLcCid === '') {
        echo '<div class="alert alert-info">Enter a NAT Client ID above and click Run Trace.</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Helper: green tick / red cross badge
    $tracePass = function (string $msg) {
        return '<span style="color:#155724;background:#d4edda;padding:2px 8px;border-radius:4px;font-weight:600;">&#10003; PASS</span> ' . $msg;
    };
    $traceFail = function (string $msg) {
        return '<span style="color:#721c24;background:#f8d7da;padding:2px 8px;border-radius:4px;font-weight:600;">&#10007; FAIL</span> ' . $msg;
    };
    $traceWarn = function (string $msg) {
        return '<span style="color:#856404;background:#fff3cd;padding:2px 8px;border-radius:4px;font-weight:600;">&#9888; WARN</span> ' . $msg;
    };

    echo '<p style="font-size:0.93em;color:#6c757d;">Tracing Client ID <strong>' . htmlspecialchars($_traceCid) . '</strong> against import <strong>#' . (int)$importid . '</strong>. Each step is an independent DB query.</p>';

    $traceSteps = [];

    // ── STEP 1: Is this clientid in the NAT00120 staging table for THIS import? ─
    $step1Rows = $DB->get_records_sql(
        "SELECT clientid, unitcode, outcome
           FROM {local_rtocompliance_avetmiss_enrolment}
          WHERE importid = :iid AND LOWER(clientid) = :cid
          ORDER BY unitcode ASC",
        ['iid' => $importid, 'cid' => $_traceLcCid]
    );
    echo '<h5 style="margin-top:1.5rem;">Step 1 — NAT00120 staging data for this import</h5>';
    if (empty($step1Rows)) {
        echo '<p>' . $traceFail('Client ID <code>' . htmlspecialchars($_traceCid) . '</code> has <strong>ZERO rows</strong> in <code>local_rtocompliance_avetmiss_enrolment</code> for importid=' . (int)$importid . '.<br>'
           . 'This is fatal — the student is completely invisible to Fix Over-Enrolments for this import.<br>'
           . '<strong>Check:</strong> (a) is this the correct import? (b) was the correct NAT00120 file uploaded? (c) is the Client ID correct?</p>') . '</p>';
    } else {
        echo '<p>' . $tracePass(count($step1Rows) . ' unit(s) found in staging for this import. <strong>Step 1 OK — student IS in the NAT data.</strong>') . '</p>';
        echo '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size:0.86em;max-width:600px;">';
        echo '<thead class="thead-light"><tr><th title="Unit of competency code from the NAT file">Unit Code</th><th title="AVETMISS outcome code recorded for the unit">Outcome Code</th></tr></thead><tbody>';
        foreach ($step1Rows as $_s1) {
            echo '<tr><td><code>' . htmlspecialchars($_s1->unitcode) . '</code></td><td>'
               . (trim((string)($_s1->outcome ?? '')) === '' ? '<em style="color:#6c757d;">(empty)</em>' : '<code>' . htmlspecialchars((string)$_s1->outcome) . '</code>')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $step1Units = [];
        foreach ($step1Rows as $_s1r) {
            $step1Units[strtoupper(trim((string)$_s1r->unitcode))] = trim((string)($_s1r->outcome ?? ''));
        }
    }
    if (empty($step1Rows)) { echo $OUTPUT->footer(); exit; }

    // ── STEP 2: Can the clientid be matched to a Moodle user? (all 5 paths) ────
    echo '<h5 style="margin-top:1.5rem;">Step 2 — Moodle account matching (5 paths)</h5>';
    $_traceMatchedUid  = null;
    $_traceMatchedName = '';
    $_traceMatchedVia  = '';

    // Path A: idnumber
    $_paMu = $DB->get_record_sql(
        "SELECT id, firstname, lastname FROM {user} WHERE LOWER(idnumber) = :cid AND deleted = 0 LIMIT 1",
        ['cid' => $_traceLcCid]
    );
    if ($_paMu) {
        $_traceMatchedUid  = (int)$_paMu->id;
        $_traceMatchedName = trim($_paMu->firstname . ' ' . $_paMu->lastname);
        $_traceMatchedVia  = 'Path A (mdl_user.idnumber)';
        echo '<p>' . $tracePass('Path A matched — <code>mdl_user.idnumber = ' . htmlspecialchars($_traceCid) . '</code> → userid <strong>' . $_traceMatchedUid . '</strong> (<em>' . htmlspecialchars($_traceMatchedName) . '</em>)') . '</p>';
    } else {
        echo '<p>' . $traceWarn('Path A miss — no row in <code>mdl_user</code> where <code>LOWER(idnumber) = \'' . htmlspecialchars($_traceLcCid) . '\'</code>. Trying Path B…') . '</p>';
    }

    // Path B: student profile table
    if ($_traceMatchedUid === null) {
        $_pbStu = $DB->get_record_sql(
            "SELECT s.userid, u.firstname, u.lastname
               FROM {local_rtocompliance_students} s
               JOIN {user} u ON u.id = s.userid AND u.deleted = 0
              WHERE LOWER(s.clientid) = :cid AND s.clientid IS NOT NULL AND s.clientid <> ''
              LIMIT 1",
            ['cid' => $_traceLcCid]
        );
        if ($_pbStu) {
            $_traceMatchedUid  = (int)$_pbStu->userid;
            $_traceMatchedName = trim($_pbStu->firstname . ' ' . $_pbStu->lastname);
            $_traceMatchedVia  = 'Path B (student profile table)';
            echo '<p>' . $tracePass('Path B matched — <code>local_rtocompliance_students.clientid = ' . htmlspecialchars($_traceCid) . '</code> → userid <strong>' . $_traceMatchedUid . '</strong> (<em>' . htmlspecialchars($_traceMatchedName) . '</em>)') . '</p>';
        } else {
            echo '<p>' . $traceWarn('Path B miss — no row in <code>local_rtocompliance_students</code> with <code>LOWER(clientid) = \'' . htmlspecialchars($_traceLcCid) . '\'</code>. Trying Path C…') . '</p>';
        }
    }

    // Path C: username
    if ($_traceMatchedUid === null) {
        $_pcMu = $DB->get_record_sql(
            "SELECT id, firstname, lastname FROM {user} WHERE LOWER(username) = :cid AND deleted = 0 LIMIT 1",
            ['cid' => $_traceLcCid]
        );
        if ($_pcMu) {
            $_traceMatchedUid  = (int)$_pcMu->id;
            $_traceMatchedName = trim($_pcMu->firstname . ' ' . $_pcMu->lastname);
            $_traceMatchedVia  = 'Path C (mdl_user.username)';
            echo '<p>' . $tracePass('Path C matched — <code>mdl_user.username = ' . htmlspecialchars($_traceCid) . '</code> → userid <strong>' . $_traceMatchedUid . '</strong> (<em>' . htmlspecialchars($_traceMatchedName) . '</em>)') . '</p>';
        } else {
            echo '<p>' . $traceWarn('Path C miss — no row in <code>mdl_user</code> where <code>LOWER(username) = \'' . htmlspecialchars($_traceLcCid) . '\'</code>. Trying Paths D/E…') . '</p>';
        }
    }

    // Paths D & E: NAT00085 staging (email + USI) — across all imports, most recent first
    if ($_traceMatchedUid === null) {
        $_stagRows = $DB->get_records_sql(
            "SELECT clientid, email, usi, importid
               FROM {local_rtocompliance_avetmiss_student}
              WHERE LOWER(clientid) = :cid
              ORDER BY importid DESC",
            ['cid' => $_traceLcCid]
        );
        $_traceEmail = ''; $_traceUsi = '';
        foreach ($_stagRows as $_sg) {
            $_em = strtolower(trim((string)($_sg->email ?? '')));
            if ($_traceEmail === '' && $_em !== '' && str_contains($_em, '@') && !str_ends_with($_em, '.invalid')) {
                $_traceEmail = $_em;
            }
            $_us = strtoupper(trim((string)($_sg->usi ?? '')));
            if ($_traceUsi === '' && $_us !== '' && strlen($_us) >= 10) { $_traceUsi = $_us; }
        }
        if ($_traceEmail === '' && $_traceUsi === '') {
            echo '<p>' . $traceFail('Paths D/E miss — no rows in <code>local_rtocompliance_avetmiss_student</code> for this Client ID across any import (no email, no USI to try).') . '</p>';
        } else {
            // Path D: email
            if ($_traceEmail !== '') {
                $_pdMu = $DB->get_record_sql(
                    "SELECT id, firstname, lastname FROM {user} WHERE LOWER(email) = :em AND deleted = 0 LIMIT 1",
                    ['em' => $_traceEmail]
                );
                if ($_pdMu) {
                    $_traceMatchedUid  = (int)$_pdMu->id;
                    $_traceMatchedName = trim($_pdMu->firstname . ' ' . $_pdMu->lastname);
                    $_traceMatchedVia  = 'Path D (email match)';
                    echo '<p>' . $tracePass('Path D matched — email <code>' . htmlspecialchars($_traceEmail) . '</code> → userid <strong>' . $_traceMatchedUid . '</strong> (<em>' . htmlspecialchars($_traceMatchedName) . '</em>)') . '</p>';
                } else {
                    echo '<p>' . $traceWarn('Path D miss — NAT00085 has email <code>' . htmlspecialchars($_traceEmail) . '</code> but no <code>mdl_user</code> row matches it.') . '</p>';
                }
            }
            // Path E: USI
            if ($_traceMatchedUid === null && $_traceUsi !== '') {
                $_peStu = $DB->get_record_sql(
                    "SELECT s.userid, u.firstname, u.lastname
                       FROM {local_rtocompliance_students} s
                       JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                      WHERE UPPER(s.usi) = :usi AND s.usi IS NOT NULL AND s.usi <> ''
                      LIMIT 1",
                    ['usi' => $_traceUsi]
                );
                if ($_peStu) {
                    $_traceMatchedUid  = (int)$_peStu->userid;
                    $_traceMatchedName = trim($_peStu->firstname . ' ' . $_peStu->lastname);
                    $_traceMatchedVia  = 'Path E (USI match)';
                    echo '<p>' . $tracePass('Path E matched — USI <code>' . htmlspecialchars($_traceUsi) . '</code> → userid <strong>' . $_traceMatchedUid . '</strong> (<em>' . htmlspecialchars($_traceMatchedName) . '</em>)') . '</p>';
                } else {
                    echo '<p>' . $traceWarn('Path E miss — NAT00085 has USI <code>' . htmlspecialchars($_traceUsi) . '</code> but no <code>local_rtocompliance_students</code> row matches it.') . '</p>';
                }
            }
        }
    }

    if ($_traceMatchedUid === null) {
        echo '<p style="color:#721c24;font-weight:600;background:#f8d7da;padding:0.6rem;border-radius:4px;">'
           . '&#10007; FATAL — All 5 matching paths failed. This student is completely invisible to Fix Over-Enrolments. '
           . 'Fix: open their Moodle profile → Other fields → set <strong>ID number</strong> to <code>' . htmlspecialchars($_traceCid) . '</code>.</p>';
        echo $OUTPUT->footer();
        exit;
    }

    // ── STEP 3: What are ALL the matched user's current enrolments? ─────────────
    echo '<h5 style="margin-top:1.5rem;">Step 3 — All enrolments for userid ' . $_traceMatchedUid . ' (<em>' . htmlspecialchars($_traceMatchedName) . '</em>, matched via ' . htmlspecialchars($_traceMatchedVia) . ')</h5>';
    $_allEnrolRs = $DB->get_recordset_sql(
        "SELECT e.enrol, e.courseid, c.fullname, c.shortname, c.idnumber, c.category, c.visible,
                ue.status, ue.timecreated
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {course} c ON c.id = e.courseid AND c.id <> 1
          WHERE ue.userid = :uid
          ORDER BY e.enrol ASC, c.fullname ASC",
        ['uid' => $_traceMatchedUid]
    );
    $_allEnrolRows = [];
    foreach ($_allEnrolRs as $_ae) { $_allEnrolRows[] = $_ae; }
    $_allEnrolRs->close();

    if (empty($_allEnrolRows)) {
        echo '<p>' . $traceFail('Zero enrolments found for this userid in any course. The student has NO Moodle course enrolments at all.') . '</p>';
        echo $OUTPUT->footer();
        exit;
    }

    echo '<p>' . $tracePass(count($_allEnrolRows) . ' total enrolment row(s) found across all courses.') . '</p>';
    echo '<div class="table-responsive"><table class="table table-sm table-bordered" style="font-size:0.84em;">';
    echo '<thead class="thead-light"><tr><th title="Moodle enrolment method for this enrolment">Enrol Method</th><th title="Enrolment status (active or suspended)">Status</th><th title="Moodle course the student is enrolled in">Course</th><th title="Unit of competency code">Unit Code</th><th title="Moodle course category ID">Cat ID</th><th title="Whether this unit appears in the uploaded NAT data">In NAT data?</th><th title="Result of the over-enrolment check for this row">FOE result</th></tr></thead><tbody>';

    $_step3ManualWithCode  = 0;
    $_step3ManualNoCode    = 0;
    $_step3NonManual       = 0;
    $_step3Flagged         = 0;

    foreach ($_allEnrolRows as $_ae) {
        // FOE-NAME-EXTRACT (v5.9.93): try idnumber, then shortname, then fullname
        $_aeUc       = _foe_extract_unitcode(
            (string)($_ae->idnumber ?? ''),
            (string)($_ae->shortname ?? ''),
            (string)($_ae->fullname ?? '')
        );
        $_aeUcRaw    = strtoupper(trim((string)($_ae->idnumber ?? '')));
        $_aeUcSource = ($_aeUc !== '' && $_aeUcRaw !== $_aeUc) ? 'name' : 'idnumber';
        $_aeManual = ($_ae->enrol === 'manual');
        $_aeActive = ((int)$_ae->status === 0);
        $_aeNatOut = isset($step1Units[$_aeUc]) ? $step1Units[$_aeUc] : null;
        $_aeInNat  = ($step1Units !== null) && ($_aeUc !== '') && isset($step1Units[$_aeUc]);

        if (!$_aeManual) { $_step3NonManual++; }
        elseif ($_aeUc === '') { $_step3ManualNoCode++; }
        else { $_step3ManualWithCode++; }

        // Determine what FOE would do
        $_foeTxt = '—';
        if (!$_aeActive) {
            $_foeTxt = '<span style="color:#6c757d;">Inactive enrolment — skipped</span>';
        } elseif (!$_aeManual) {
            $_foeTxt = '<span style="color:#856404;">Non-manual — invisible to FOE main table</span>';
            if ($_aeUc === '') { $_foeTxt .= ' + no unit code'; }
        } elseif ($_aeUc === '') {
            $_foeTxt = '<span style="color:#856404;">No unit code — invisible to FOE main table. May appear in Section C.</span>';
        } elseif (!$_aeInNat) {
            $_foeTxt = '<span style="color:#155724;font-weight:600;">&#10003; FLAGGED — Criterion 1: Over-enrolment (unit not in NAT data)</span>';
            $_step3Flagged++;
        } else {
            $_aeOc = $_aeNatOut ?? '';
            static $_ncCodes = ['10','20','30','40','41','51','52','53','54','60','61','81','82','85','90'];
            if (in_array($_aeOc, $_ncCodes, true)) {
                $_foeTxt = '<span style="color:#155724;font-weight:600;">&#10003; FLAGGED — Criterion 2: Non-continuing outcome (' . htmlspecialchars($_aeOc) . ')</span>';
                $_step3Flagged++;
            } else {
                $_aeOcDesc = ($_aeOc === '70') ? 'continuing (safe)' : (($_aeOc === '') ? 'empty/unknown (safe — not flagged without explicit code)' : 'code ' . $_aeOc . ' (see NON_CONTINUING list — may be flagged)');
                $_foeTxt = '<span style="color:#6c757d;">Not flagged — unit IS in NAT data, outcome: ' . htmlspecialchars($_aeOcDesc) . '</span>';
            }
        }

        echo '<tr' . (!$_aeActive ? ' style="opacity:0.5;"' : '') . '>';
        echo '<td><code>' . htmlspecialchars($_ae->enrol) . '</code></td>';
        echo '<td>' . ($_aeActive ? 'Active' : '<em>Inactive</em>') . '</td>';
        echo '<td>' . htmlspecialchars($_ae->fullname) . ' <small style="color:#6c757d;">(cat=' . (int)$_ae->category . ')</small></td>';
        if ($_aeUc === '') {
            $_aeUcCell = '<em style="color:#dc3545;">NOT SET</em>';
        } elseif ($_aeUcSource === 'name') {
            $_aeUcCell = '<code>' . htmlspecialchars($_aeUc) . '</code>'
                . ' <span title="Extracted from course name (idnumber not set)" style="color:#856404;font-size:0.8em;">&#128221; name</span>';
        } else {
            $_aeUcCell = '<code>' . htmlspecialchars($_aeUc) . '</code>';
        }
        echo '<td>' . $_aeUcCell . '</td>';
        echo '<td>' . (int)$_ae->category . '</td>';
        echo '<td>' . ($_aeUc === '' ? '—' : ($_aeInNat
            ? '<span style="color:#155724;">Yes (outcome: <code>' . htmlspecialchars($step1Units[$_aeUc] ?: 'empty') . '</code>)</span>'
            : '<span style="color:#721c24;font-weight:600;">NO</span>')) . '</td>';
        echo '<td style="font-size:0.9em;">' . $_foeTxt . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<div style="background:#f8f9fa;padding:0.8rem;border-radius:4px;margin-bottom:1rem;font-size:0.92em;">';
    echo '<strong>Summary:</strong> '
       . '<span style="' . ($_step3Flagged > 0 ? 'color:#155724;font-weight:600;' : 'color:#dc3545;font-weight:600;') . '">'
       . $_step3Flagged . ' enrolment(s) would be flagged by FOE</span> &nbsp;|&nbsp; '
       . $_step3ManualWithCode . ' manual+unit-code (scanned by main table) &nbsp;|&nbsp; '
       . $_step3ManualNoCode . ' manual+NO unit code (invisible to main table, may appear in Section C) &nbsp;|&nbsp; '
       . $_step3NonManual . ' non-manual (cohort/self/meta — invisible to FOE)';
    echo '</div>';

    if ($_step3Flagged === 0) {
        echo '<div class="alert alert-warning" style="border-radius:6px;">';
        echo '<strong>&#9888; Smoking gun identified:</strong> Zero enrolments were flagged. ';
        if ($_step3ManualNoCode > 0 && $_step3ManualWithCode === 0) {
            echo 'The student is manually enrolled in <strong>' . $_step3ManualNoCode . ' course(s)</strong> but none of those courses have an AVETMISS unit code that can be identified. '
               . 'FOE tried (1) the Course ID number field, (2) the start of the course shortname, and (3) the start of the course fullname — none matched the pattern <code>[LETTERS][DIGITS]</code> (e.g. ABC12345, BSBOPS505). '
               . '<br><strong>Fix options:</strong> (a) Set the Course ID number field to the unit code (e.g. ABC12345) in each course\'s settings, OR '
               . '(b) Rename the course so its fullname or shortname starts with the unit code (e.g. "ABC12345 Comply with..."), then re-run Fix Over-Enrolments.';
        } elseif ($_step3NonManual > 0 && $_step3ManualWithCode === 0) {
            echo 'The student\'s ' . $_step3NonManual . ' enrolment(s) in courses with unit codes are ALL <strong>non-manual</strong> (cohort/self/meta). '
               . 'FOE only processes manual enrolments. <br><strong>Fix:</strong> Remove the student from the cohort, or unenrol them manually from each course.';
        } elseif ($_step3ManualWithCode > 0) {
            echo 'The student HAS manual enrolments in courses with unit codes, but every one of those unit codes IS in their NAT data. '
               . 'Either: (a) they are genuinely enrolled correctly in Moodle matching exactly their NAT units, or '
               . '(b) there are additional Moodle courses (the over-enrolments) that have <strong>no unit code set</strong> — check the table rows marked "NOT SET" above.';
        }
        echo '</div>';
    } else {
        echo '<div class="alert alert-success" style="border-radius:6px;">'
           . '<strong>&#10003; ' . $_step3Flagged . ' flagged row(s) found.</strong> '
           . 'This student SHOULD appear in the Fix Over-Enrolments main table. '
           . 'If they do not appear there, re-run this trace using the same importid — the FOE page may have been run against a different import. '
           . 'Also check: is the page showing a stale cached version? Confirm by looking at the "Students matched" count on the FOE page.</div>';
    }

    echo '<p style="margin-top:1rem;"><a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'fix_overenrolments', 'importid' => $importid]))->out() . '" class="btn btn-secondary btn-sm">Back to Fix Over-Enrolments</a></p>';
    echo $OUTPUT->footer();
    exit;
}

// ─── FOE-BATCH: Progress page (v5.9.94) ──────────────────────────────────────
// Shown after fix_overenrolments_apply queues the batch. A JS polling loop
// calls foe_apply_chunk repeatedly and updates the progress bar in real time.
if ($action === 'foe_progress' && $importid) {
    require_capability('local/rtocompliance:manage', $context);
    $_batchId = required_param('batchid', PARAM_ALPHANUMEXT);
    $_total   = optional_param('total', 0, PARAM_INT);
    $_sesskey = sesskey();
    $_ajaxUrl = (new moodle_url('/local/rtocompliance/data_import.php'))->out(false);
    $_backUrl = (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out();

    echo $OUTPUT->header();
    echo '<h3 class="mb-3">Fix Over-Enrolments &mdash; Applying Changes</h3>';
    echo '<div class="card mb-4" style="max-width:640px;border:1px solid #dee2e6;">';
    echo '<div class="card-body">';
    echo '<p class="mb-2"><strong>Removing <span id="foe-count">' . (int)$_total . '</span> over-enrolment(s)…</strong></p>';
    echo '<div class="progress mb-3" style="height:28px;border-radius:4px;">';
    echo '<div id="foe-bar" class="progress-bar bg-danger progress-bar-striped progress-bar-animated" '
       . 'role="progressbar" style="width:1%;min-width:3rem;" aria-valuenow="1" aria-valuemin="0" aria-valuemax="100">0%</div>';
    echo '</div>';
    echo '<p id="foe-status" class="text-muted mb-0" style="font-size:0.93em;">Starting&hellip;</p>';
    echo '</div></div>';
    echo '<p class="text-muted" style="font-size:0.88em;">Do not close this tab. The page will redirect automatically when finished.</p>';

    // Inline JS — drives the chunk polling loop.
    $batchJson  = json_encode($_batchId);
    $totalJson  = (int)$_total;
    $sesskeyJson = json_encode($_sesskey);
    $ajaxJson   = json_encode($_ajaxUrl);
    $backJson   = json_encode($_backUrl);
    echo <<<FOEJS
<script>
(function (){
  var batchid  = {$batchJson};
  var total    = {$totalJson};
  var sesskey  = {$sesskeyJson};
  var ajaxUrl  = {$ajaxJson};
  var backUrl  = {$backJson};
  var doneCount = 0, errCount = 0, compCount = 0;

  // dbTotal is set from the first chunk response so we always display
  // the real DB count, never the URL-param estimate (which can be stale
  // if the pending rows were never inserted).
  var dbTotal = 0;
  var dbTotalKnown = false;

  function nextChunk() {
    var fd = new FormData();
    fd.append('action',  'foe_apply_chunk');
    fd.append('batchid', batchid);
    fd.append('sesskey', sesskey);
    fetch(ajaxUrl, {method: 'POST', body: fd})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // Catch the case where the batch was never created (pending rows never
        // inserted). This can happen on a double-submit or sesskey timeout during
        // fix_overenrolments_apply. The URL total param would still show a
        // non-zero number, masking the fact that nothing was actually removed.
        if (d.batch_empty) {
          var bar = document.getElementById('foe-bar');
          bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
          bar.classList.add('bg-warning');
          bar.style.width = '100%';
          bar.textContent = '0%';
          document.getElementById('foe-status').innerHTML =
            '<span class="text-danger"><strong>&#9888; No pending rows found.</strong> '
            + 'The unenrolments were not queued — please go back and click '
            + '&ldquo;Fix Over-Enrolments&rdquo; again. '
            + '(<a href="' + backUrl + '">Back to Import</a>)</span>';
          document.getElementById('foe-count').textContent = '0';
          return;
        }

        // Capture actual DB total from first response.
        if (!dbTotalKnown) {
          dbTotal = d.total;
          dbTotalKnown = true;
          // Update the displayed count to the real DB value.
          document.getElementById('foe-count').textContent = dbTotal;
        }

        doneCount += d.processed;
        errCount  += d.errors;
        compCount += d.completions;

        var displayTotal = dbTotal > 0 ? dbTotal : total;
        var pct = displayTotal > 0 ? Math.min(100, Math.round((displayTotal - d.remaining) / displayTotal * 100)) : 100;
        var bar = document.getElementById('foe-bar');
        bar.style.width = Math.max(pct, 1) + '%';
        bar.textContent = pct + '%';
        bar.setAttribute('aria-valuenow', pct);
        var done = displayTotal - d.remaining;
        document.getElementById('foe-status').textContent =
          done + ' of ' + displayTotal + ' removed' + (errCount ? ' (' + errCount + ' error(s))' : '') + '…';

        if (d.finished) {
          bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
          var actualRemoved = doneCount;
          var msg = actualRemoved + ' over-enrolment(s) removed';
          if (compCount > 0) msg += ', ' + compCount + ' phantom completion record(s) cleared';
          if (errCount  > 0) msg += ' (' + errCount + ' error(s) — check Moodle debugging log)';
          var st = document.getElementById('foe-status');
          st.innerHTML = '<strong class="text-success">&#10003; Done!</strong> ' + msg +
            '. <a href="' + backUrl + '">Back to Import &rarr;</a>';
          document.getElementById('foe-count').textContent = actualRemoved;
        } else {
          setTimeout(nextChunk, 250);
        }
      })
      .catch(function (e) {
        document.getElementById('foe-status').innerHTML =
          '<span class="text-danger"><strong>Error:</strong> ' + e.message + '</span> — reload the page to retry.';
      });
  }

  nextChunk();
})();
</script>
FOEJS;
    echo $OUTPUT->footer();
    exit;
}

if (($action === 'fix_overenrolments' || $action === 'fix_overenrolments_apply') && $importid) {
    require_capability('local/rtocompliance:manage', $context);
    require_once($CFG->libdir . '/enrollib.php');
    \core\session\manager::write_close();

    // ── Import timestamp — for display only (shown in UI breadcrumb/intro) ──
    $_foeImportRec = $DB->get_record('local_rtocompliance_avetmiss', ['id' => $importid], 'timecreated', IGNORE_MISSING);
    $_foeImportTs  = (int)($_foeImportRec->timecreated ?? 0);

    // ── Step 1: Load all NAT unit codes + outcome codes per student ──────────
    // outcomeidentifier '70' = Continuing enrolment (still active).
    // Any other code (20=Competent, 30=Not achieved, 40=Withdrawn, 51/52=RPL,
    // 53/54=RCC, 60=Credit transfer, 81/82=Non-assessed) means the student is
    // a historical record only — they should NOT remain enrolled in Moodle.
    // $allStudentUnits: clientid (lc) → [UNITCODE => outcome_code_string|'']
    // outcome is the raw 2-char AVETMISS code from the staging table ('70'=Continuing,
    // '20'=Competent, '40'=Withdrawn, etc.). Many export formats omit it → stored as NULL.
    // Safety rule: only flag as non-continuing when outcome is an EXPLICIT known code other
    // than '70'. NULL/empty/'00' = unknown → do NOT unenrol based on outcome alone.
    $allStudentUnits = [];
    $allUnitsRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_enrolment',
        'importid = :iid',
        ['iid' => $importid],
        '',
        'clientid, unitcode, outcome'
    );
    foreach ($allUnitsRs as $_aur) {
        $_uc  = strtoupper(trim((string)$_aur->unitcode));
        $_cid = strtolower(trim((string)$_aur->clientid));
        if ($_uc === '' || $_cid === '') continue;
        $allStudentUnits[$_cid][$_uc] = trim((string)($_aur->outcome ?? ''));
    }
    $allUnitsRs->close();

    // FOE-AVETMISS-SCOPE (v5.9.150): Build the set of unit codes that actually appear
    // in this import's NAT staging data. A Moodle course is only "in scope" for FOE
    // if its extracted unit code is in this set — i.e. the unit code is a real AVETMISS
    // unit being delivered in this training period. Courses whose extracted unit code does
    // NOT appear in NAT (CPD, orientation, admin-access, resource courses whose name
    // happens to match the AVETMISS pattern) are excluded from the comparison entirely.
    // Derived from $allStudentUnits with no extra DB query — O(n) pass.
    $natUnitCodeSet = [];
    foreach ($allStudentUnits as $_natLc => $_natUnits) {
        foreach ($_natUnits as $_natUcKey => $_natOc) {
            $natUnitCodeSet[$_natUcKey] = true;
        }
    }

    if (empty($allStudentUnits)) {
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]),
            'No enrolment records found in this import staging data — cannot analyse over-enrolments.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // ── Step 2: Match clientids → Moodle userids (two-path) ──────────────────
    // Path A: mdl_user.idnumber = clientid.
    //   Works for accounts created by the auto-enrol wizard (wizard always sets idnumber).
    // Path B (fallback): local_rtocompliance_students.clientid → userid.
    //   Covers students whose Moodle account was created manually — the idnumber field is
    //   typically blank on manual accounts. The RTO Compliance plugin stores the clientid in
    //   its own students table when the student profile is imported from NAT data.
    // FOE-TWO-PATH-MATCH (v5.9.73): Previously only Path A was used, so manually-created
    // student accounts were completely invisible to Fix Over-Enrolments even when their
    // NAT clientid existed in local_rtocompliance_students. Now both paths are checked.
    $_clientids = array_keys($allStudentUnits);
    list($_insql, $_inparams) = $DB->get_in_or_equal($_clientids, SQL_PARAMS_NAMED, 'foe');
    $_muRs = $DB->get_recordset_sql(
        "SELECT id, LOWER(idnumber) AS lc_idnumber, firstname, lastname
           FROM {user}
          WHERE LOWER(idnumber) $_insql AND deleted = 0",
        $_inparams
    );
    $foeClientToUserid = []; // lc clientid → userid
    $foeClientToName   = []; // lc clientid → display name
    $foeMatchedVia     = []; // lc clientid → 'idnumber' | 'profile'
    foreach ($_muRs as $_mu) {
        $_lcid = trim((string)$_mu->lc_idnumber);
        if ($_lcid !== '' && isset($allStudentUnits[$_lcid])) {
            $foeClientToUserid[$_lcid] = (int)$_mu->id;
            $foeClientToName[$_lcid]   = trim($_mu->firstname . ' ' . $_mu->lastname);
            $foeMatchedVia[$_lcid]     = 'idnumber';
        }
    }
    $_muRs->close();

    // Path B: local_rtocompliance_students.clientid → userid.
    // Catches students whose profile was imported from NAT data but idnumber was never set.
    $_unmatchedCids = array_values(array_diff($_clientids, array_keys($foeClientToUserid)));
    if (!empty($_unmatchedCids)) {
        list($_insql2, $_inparams2) = $DB->get_in_or_equal($_unmatchedCids, SQL_PARAMS_NAMED, 'foeb');
        $_stuRs = $DB->get_recordset_sql(
            "SELECT s.clientid, s.userid, u.firstname, u.lastname
               FROM {local_rtocompliance_students} s
               JOIN {user} u ON u.id = s.userid AND u.deleted = 0
              WHERE LOWER(s.clientid) $_insql2
                AND s.clientid IS NOT NULL AND s.clientid <> ''",
            $_inparams2
        );
        foreach ($_stuRs as $_sr) {
            $_lcid = strtolower(trim((string)$_sr->clientid));
            if ($_lcid === '' || !isset($allStudentUnits[$_lcid]) || isset($foeClientToUserid[$_lcid])) continue;
            $foeClientToUserid[$_lcid] = (int)$_sr->userid;
            $foeClientToName[$_lcid]   = trim($_sr->firstname . ' ' . $_sr->lastname);
            $foeMatchedVia[$_lcid]     = 'profile';
        }
        $_stuRs->close();
    }

    // Path C: mdl_user.username = clientid.
    // The auto-enrol wizard sets username = clientid when it creates accounts.
    // Many RTOs also create manual accounts where the username is the student/client ID.
    $_unmatchedCids = array_values(array_diff($_clientids, array_keys($foeClientToUserid)));
    if (!empty($_unmatchedCids)) {
        list($_insql3, $_inparams3) = $DB->get_in_or_equal($_unmatchedCids, SQL_PARAMS_NAMED, 'foec');
        $_uRs = $DB->get_recordset_sql(
            "SELECT id, LOWER(username) AS lc_username, firstname, lastname
               FROM {user}
              WHERE LOWER(username) $_insql3 AND deleted = 0",
            $_inparams3
        );
        foreach ($_uRs as $_ur) {
            $_lcid = trim((string)$_ur->lc_username);
            if ($_lcid === '' || !isset($allStudentUnits[$_lcid]) || isset($foeClientToUserid[$_lcid])) continue;
            $foeClientToUserid[$_lcid] = (int)$_ur->id;
            $foeClientToName[$_lcid]   = trim($_ur->firstname . ' ' . $_ur->lastname);
            $foeMatchedVia[$_lcid]     = 'username';
        }
        $_uRs->close();
    }

    // Paths D & E: use NAT00080/85 staging data for still-unmatched students.
    // Load email + USI from the avetmiss_student staging table for this import.
    // Path D: match by email (NAT00085) → mdl_user.email.
    // Path E: match by USI  (NAT00080) → local_rtocompliance_students.usi.
    // Both paths only fire when the relevant data exists in the staging table AND
    // the student still has no Moodle account found by Paths A-C.
    $_unmatchedCids = array_values(array_diff($_clientids, array_keys($foeClientToUserid)));
    if (!empty($_unmatchedCids)) {
        list($_insql4, $_inparams4) = $DB->get_in_or_equal($_unmatchedCids, SQL_PARAMS_NAMED, 'foed');
        // BUG-FIX-ALL-IMPORTS (v5.9.76): previously scoped to importid = :iid only.
        // If NAT00085 was NOT uploaded with the current import (a common scenario where
        // RTOs only upload NAT00120 enrolment files), email and USI were invisible even
        // though they existed in an earlier import. Fix: select the most-recent staging
        // row per clientid across ALL historical imports so email/USI matching works
        // regardless of which import NAT00085 was included in.
        // FOE-SUBQUERY-COMPAT (v5.9.78): replaced INNER JOIN + MAX(importid) subquery with
        // a plain ORDER BY importid DESC query — MySQL ONLY_FULL_GROUP_BY mode can reject
        // the correlated subquery pattern, causing "Error reading from database". The most-
        // recent row per clientid is now selected in PHP by skipping already-seen clientids.
        $_stagRs = $DB->get_recordset_sql(
            "SELECT clientid, email, usi, importid
               FROM {local_rtocompliance_avetmiss_student}
              WHERE LOWER(clientid) $_insql4
              ORDER BY importid DESC",
            $_inparams4
        );
        $_stagEmail   = []; // lc email → lc clientid
        $_stagUsi     = []; // UC USI  → lc clientid
        $_stagSeenCid = []; // lc clientid → true (dedup: keep first = highest importid)
        foreach ($_stagRs as $_sg) {
            $_lcid = strtolower(trim((string)$_sg->clientid));
            if ($_lcid === '' || isset($foeClientToUserid[$_lcid])) continue;
            if (isset($_stagSeenCid[$_lcid])) continue; // already captured most-recent row
            $_stagSeenCid[$_lcid] = true;
            $_em = strtolower(trim((string)($_sg->email ?? '')));
            if ($_em !== '' && str_contains($_em, '@') && !str_ends_with($_em, '.invalid')) {
                $_stagEmail[$_em] = $_lcid;
            }
            $_us = strtoupper(trim((string)($_sg->usi ?? '')));
            if ($_us !== '' && strlen($_us) >= 10) {
                $_stagUsi[$_us] = $_lcid;
            }
        }
        $_stagRs->close();

        // Path D: email match → mdl_user.email.
        if (!empty($_stagEmail)) {
            list($_esql, $_eparams) = $DB->get_in_or_equal(array_keys($_stagEmail), SQL_PARAMS_NAMED, 'foee');
            $_emRs = $DB->get_recordset_sql(
                "SELECT id, LOWER(email) AS lc_email, firstname, lastname
                   FROM {user}
                  WHERE LOWER(email) $_esql AND deleted = 0",
                $_eparams
            );
            foreach ($_emRs as $_er) {
                $_em   = trim((string)$_er->lc_email);
                $_lcid = $_stagEmail[$_em] ?? null;
                if ($_lcid === null || !isset($allStudentUnits[$_lcid]) || isset($foeClientToUserid[$_lcid])) continue;
                $foeClientToUserid[$_lcid] = (int)$_er->id;
                $foeClientToName[$_lcid]   = trim($_er->firstname . ' ' . $_er->lastname);
                $foeMatchedVia[$_lcid]     = 'email';
            }
            $_emRs->close();
        }

        // Path E: USI match → local_rtocompliance_students.usi.
        if (!empty($_stagUsi)) {
            list($_usiSql, $_usiParams) = $DB->get_in_or_equal(array_keys($_stagUsi), SQL_PARAMS_NAMED, 'foef');
            $_usiRs = $DB->get_recordset_sql(
                "SELECT s.userid, UPPER(s.usi) AS uc_usi, u.firstname, u.lastname
                   FROM {local_rtocompliance_students} s
                   JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                  WHERE UPPER(s.usi) $_usiSql
                    AND s.usi IS NOT NULL AND s.usi <> ''",
                $_usiParams
            );
            foreach ($_usiRs as $_ur) {
                $_us   = trim((string)$_ur->uc_usi);
                $_lcid = $_stagUsi[$_us] ?? null;
                if ($_lcid === null || !isset($allStudentUnits[$_lcid]) || isset($foeClientToUserid[$_lcid])) continue;
                $foeClientToUserid[$_lcid] = (int)$_ur->userid;
                $foeClientToName[$_lcid]   = trim($_ur->firstname . ' ' . $_ur->lastname);
                $foeMatchedVia[$_lcid]     = 'usi';
            }
            $_usiRs->close();
        }
    }

    $foeMatchedUserids  = array_unique(array_values($foeClientToUserid));
    $_foeViaIdnumber    = count(array_filter($foeMatchedVia, fn($v) => $v === 'idnumber'));
    $_foeViaProfile     = count(array_filter($foeMatchedVia, fn($v) => $v === 'profile'));
    $_foeViaUsername    = count(array_filter($foeMatchedVia, fn($v) => $v === 'username'));
    $_foeViaEmail       = count(array_filter($foeMatchedVia, fn($v) => $v === 'email'));
    $_foeViaUsi         = count(array_filter($foeMatchedVia, fn($v) => $v === 'usi'));
    $_foeUnmatched      = count(array_diff($_clientids, array_keys($foeClientToUserid)));

    // ── Diagnostic: names of unmatched students from NAT00080 staging ─────────
    // FOE-UNMATCHED-NAMES (v5.9.74): previously only a count was shown — admins
    // had no way to identify WHICH students were invisible. Now we pull
    // firstname/lastname/email from the avetmiss_student staging table so the
    // diagnostic panel can name them and tell the admin exactly what to fix
    // (set ID number, or check enrolment type).
    $foeUnmatchedDetails = []; // lc clientid → [clientid, name, email]
    $_foeUnmatchedCids   = array_values(array_diff($_clientids, array_keys($foeClientToUserid)));
    if (!empty($_foeUnmatchedCids)) {
        list($_umSql, $_umParams) = $DB->get_in_or_equal($_foeUnmatchedCids, SQL_PARAMS_NAMED, 'foeum');
        $_umRs = $DB->get_recordset_sql(
            "SELECT LOWER(clientid) AS lc_cid, firstname, familyname, name AS fullname, email
               FROM {local_rtocompliance_avetmiss_student}
              WHERE importid = :iid AND LOWER(clientid) $_umSql",
            array_merge(['iid' => $importid], $_umParams)
        );
        foreach ($_umRs as $_umR) {
            $_lcid = trim((string)$_umR->lc_cid);
            if ($_lcid === '' || isset($foeUnmatchedDetails[$_lcid])) continue;
            // Build name: prefer firstname+familyname (NAT00085), fall back to fullname (NAT00080).
            $_umFirstname = trim((string)($_umR->firstname ?? ''));
            $_umFamily    = trim((string)($_umR->familyname ?? ''));
            $_umName      = ($_umFirstname !== '' || $_umFamily !== '')
                ? trim($_umFirstname . ' ' . $_umFamily)
                : trim((string)($_umR->fullname ?? ''));
            $foeUnmatchedDetails[$_lcid] = [
                'clientid' => $_lcid,
                'name'     => $_umName,
                'email'    => trim((string)($_umR->email ?? '')),
            ];
        }
        $_umRs->close();
        foreach ($_foeUnmatchedCids as $_ucid) {
            if (!isset($foeUnmatchedDetails[$_ucid])) {
                $foeUnmatchedDetails[$_ucid] = ['clientid' => $_ucid, 'name' => '', 'email' => ''];
            }
        }
        ksort($foeUnmatchedDetails);
    }

    // ── Step 3: Load ALL courses — all categories, hidden or visible ──────────
    // FIX-OVER-ENROLMENTS-ALL-CATS (v5.9.61): scan every category at once.
    // FOE-NAME-EXTRACT (v5.9.93): No longer filters by idnumber. Courses with a
    // blank/non-AVETMISS idnumber are included — _foe_extract_unitcode() tries
    // to pull the unit code from the course shortname or fullname instead
    // (e.g. "ABC12345 Comply with…" → "ABC12345"). This covers the very common
    // Moodle pattern where admins embed unit codes in course names but never
    // set the Course ID number field.
    $_allCourses = $DB->get_records_sql(
        "SELECT id, fullname, shortname, idnumber, category, visible
           FROM {course}
          WHERE id <> 1
          ORDER BY fullname ASC"
    );
    $_courseIdnumber    = []; // courseid → UNITCODE
    $_courseNames       = []; // courseid → fullname
    $_courseCatid       = []; // courseid → categoryid
    $_courseVisible     = []; // courseid → 1|0
    $_courseUcFromName  = []; // courseid → true when unit code was extracted from name (not idnumber)
    // FOE-AVETMISS-SCOPE (v5.9.150): Courses excluded because their extracted unit code
    // does not appear in any student's NAT records for this import.
    // Key = courseid, value = ['unitcode' => ..., 'fullname' => ..., 'reason' => ...]
    $_courseExcludedNotInNat = [];
    foreach ($_allCourses as $_cc) {
        $_uc = _foe_extract_unitcode(
            (string)$_cc->idnumber,
            (string)$_cc->shortname,
            (string)$_cc->fullname
        );
        if ($_uc === '') continue; // No unit code pattern at all — already outside FOE scope.

        // Safety gate: only include this course if the extracted unit code appears in at
        // least one student's NAT records for this import. If no student has a NAT record
        // for this unit code, the course is treated as non-AVETMISS (CPD, orientation,
        // admin-access, legacy resource course, etc.) and must not be touched by FOE.
        if (!isset($natUnitCodeSet[$_uc])) {
            $_courseExcludedNotInNat[(int)$_cc->id] = [
                'unitcode' => $_uc,
                'fullname' => (string)$_cc->fullname,
                'shortname'=> (string)$_cc->shortname,
                'reason'   => 'Unit code "' . $_uc . '" not found in any student\'s NAT records — treated as non-AVETMISS course (CPD, orientation, resource, etc.)',
            ];
            continue;
        }

        $_courseIdnumber[(int)$_cc->id]   = $_uc;
        $_courseNames[(int)$_cc->id]      = (string)$_cc->fullname;
        $_courseCatid[(int)$_cc->id]      = (int)$_cc->category;
        $_courseVisible[(int)$_cc->id]    = (int)$_cc->visible;
        // Track whether the unit code came from the name (not the idnumber field)
        $_idnClean = strtoupper(trim((string)$_cc->idnumber));
        if ($_idnClean !== $_uc) {
            $_courseUcFromName[(int)$_cc->id] = true;
        }
    }
    $_nameExtractCount = count($_courseUcFromName);

    if (empty($foeMatchedUserids)) {
        echo $OUTPUT->header();
        echo '<ul class="nav nav-tabs mb-4">'
           . '<li class="nav-item"><a class="nav-link" href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '">&larr; Import Detail</a></li>'
           . '<li class="nav-item"><a class="nav-link active" style="font-weight:600;" href="#">Fix Over-Enrolments</a></li>'
           . '</ul>';
        echo '<div class="alert alert-warning"><strong>No matched Moodle accounts found.</strong> '
           . count($allStudentUnits) . ' student(s) are in the NAT staging data, but none could be matched to a Moodle account.<br>'
           . '<strong>Five matching methods were tried (in order):</strong>'
           . '<ol style="margin:0.4rem 0 0.2rem 1.2rem;">'
           . '<li><strong>ID number field</strong> — <code>mdl_user.idnumber = clientid</code>. Set automatically by the auto-enrol wizard.</li>'
           . '<li><strong>Student profile table</strong> — <code>local_rtocompliance_students.clientid → userid</code>. Set when a student\'s AVETMISS profile is imported.</li>'
           . '<li><strong>Moodle username</strong> — <code>mdl_user.username = clientid</code>. The auto-enrol wizard sets username = client ID; many RTOs do the same for manual accounts.</li>'
           . '<li><strong>Email address</strong> — email from the NAT00085 staging data matched against <code>mdl_user.email</code>. Only fires if NAT00085 was uploaded with this import.</li>'
           . '<li><strong>USI</strong> — USI from the NAT00080 staging data matched against the RTO Compliance student profile USI field. Only fires if both NAT00080 was uploaded and student profiles have the USI stored.</li>'
           . '</ol>'
           . '<strong>To fix a missing student manually:</strong> edit their Moodle profile (<em>Administration &rarr; Users &rarr; Browse list of users &rarr; Edit</em>), '
           . 'scroll to <em>Other fields</em> and set the <strong>ID number</strong> to their NAT Client ID (e.g. <code>8215</code>), then re-run Fix Over-Enrolments.'
           . '</div>';
        echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '" class="btn btn-secondary">Back to Import</a>';
        echo $OUTPUT->footer();
        exit;
    }

    if (empty($_courseIdnumber)) {
        echo $OUTPUT->header();
        echo '<div class="alert alert-warning"><strong>No courses with an identifiable unit code found across any category.</strong> '
           . 'FOE looks for unit codes in three places per course: (1) the <strong>Course ID number</strong> field, '
           . '(2) the start of the <strong>short name</strong>, and (3) the start of the <strong>full name</strong>. '
           . 'None of the courses in your Moodle site have a name or ID number that starts with an AVETMISS unit code pattern (e.g. <code>ABC12345</code>, <code>BSBOPS505</code>). '
           . 'Fix: either set the Course ID number field to the unit code in each course\'s settings, or ensure the course name begins with the unit code.</div>';
        echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '" class="btn btn-secondary">Back to Import</a>';
        echo $OUTPUT->footer();
        exit;
    }

    // ── Bulk query: one SQL call finds all over-enrolments across all courses ─
    $_courseids = array_keys($_courseIdnumber);
    list($_csql, $_cparams) = $DB->get_in_or_equal($_courseids, SQL_PARAMS_NAMED, 'fcc');
    list($_uidsql, $_uidparams) = $DB->get_in_or_equal($foeMatchedUserids, SQL_PARAMS_NAMED, 'fuid');
    $_bulkParams = array_merge($_cparams, $_uidparams);
    $_enrolRs = $DB->get_recordset_sql(
        "SELECT ue.userid, e.courseid, e.id AS enrolid
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE e.enrol = 'manual'
            AND e.courseid $_csql
            AND ue.userid $_uidsql
            AND ue.status = 0",
        $_bulkParams
    );
    // Non-continuing outcome codes — any EXPLICIT code other than '70' (continuing).
    // NULL / '' / '00' = outcome not recorded in this export → treat as unknown, never flag.
    // FOE-OUTCOME-30-RESTORED (v5.9.100): Outcome 30 (Competency Not Yet Achieved) re-added.
    // v5.9.84 removed it assuming some RTOs use 30 as an in-progress placeholder, but AVETMISS 8
    // defines 30 as a final terminal outcome — in-progress students should carry outcome 70 (Continuing).
    // Root cause confirmed via FOE debug panel: students with ALL units at outcome 30 were passing both
    // criteria and receiving 0 flagged rows despite being genuinely over-enrolled.
    static $_NON_CONTINUING = ['10','20','30','40','41','51','52','53','54','60','61','81','82','85','90'];

    $foeToUnenrol = [];
    // FOE-REVERSE-MAP (v5.9.79): replaced O(n) array_search with O(1) reverse-map lookup.
    // array_search scanned the full $foeClientToUserid array (up to thousands of entries)
    // for EVERY row returned by the bulk enrolment query — with 6,000+ matched students this
    // produces hundreds of millions of comparisons and causes a PHP execution timeout.
    // Fix: build the reverse map (userid → lc_clientid) in a single O(n) pass before the loop.
    $_uidToLcCid = [];
    foreach ($foeClientToUserid as $_mapLc => $_mapUid) {
        if (!isset($_uidToLcCid[$_mapUid])) {
            $_uidToLcCid[$_mapUid] = $_mapLc;
        }
    }
    foreach ($_enrolRs as $_er) {
        $_uid   = (int)$_er->userid;
        $_cid   = (int)$_er->courseid;
        $_lcCid = $_uidToLcCid[$_uid] ?? false;
        if ($_lcCid === false) continue;
        $_unitCode = $_courseIdnumber[$_cid] ?? '';
        if ($_unitCode === '') continue;

        $_outcome      = $allStudentUnits[$_lcCid][$_unitCode] ?? null; // null = no NAT record
        $_courseHidden = (($_courseVisible[$_cid] ?? 1) === 0);

        $_reasons = [];

        // Criterion 1: over-enrolment — student has NO NAT record for this unit.
        // Every student reaching this point was matched FROM the NAT file (their Moodle
        // idnumber == a clientid in $allStudentUnits). So $_outcome === null means the
        // student IS in our NAT data but has NO record for this specific unit — a classic
        // over-enrolment (e.g. enrolled in whole qualification when only 5 of 14 units
        // appear in the NAT file).
        // FIX-FOE-REASON-HIDDEN (v5.9.71): Previously the !$_courseHidden guard suppressed
        // this reason for hidden courses — admins only saw "Course is hidden" and had no
        // way to tell that the real root cause was category-wide over-enrolment from the
        // auto-enrol wizard. Now fires for ALL courses (visible and hidden). When a course
        // is both hidden AND over-enrolled, both reasons are shown so the pattern is clear.
        if ($_outcome === null) {
            $_reasons[] = 'Over-enrolment: unit not in NAT data';
        }

        // Criterion 2: explicit non-continuing outcome code on record.
        // Safety guard: only flag when outcome is a known non-continuing code.
        // NULL/empty/'00'/'70' = outcome unknown or continuing → skip outcome check.
        if ($_outcome !== null && in_array($_outcome, $_NON_CONTINUING, true)) {
            static $_OUTCOME_LABELS = [
                '10' => 'Not yet started', '20' => 'Achieved', '30' => 'Not achieved',
                '40' => 'Withdrawn', '41' => 'Withdrawn (incomplete)',
                '51' => 'RPL granted', '52' => 'RPL not granted',
                '53' => 'RCC granted', '54' => 'RCC not granted',
                '60' => 'Credit transfer', '61' => 'Credit transfer',
                '81' => 'Non-assessed (satisfactory)', '82' => 'Non-assessed (unsatisfactory)',
                '85' => 'Further enrolment required', '90' => 'Result not available',
            ];
            $_label = $_OUTCOME_LABELS[$_outcome] ?? 'Code ' . $_outcome;
            $_reasons[] = 'Outcome: ' . $_label . ' (' . $_outcome . ')';
        }

        // Criterion 3 (REMOVED v5.9.72): "Course is hidden" was removed as a standalone
        // criterion. Students can be enrolled in hidden courses legitimately (the course
        // was visible when they enrolled and was hidden later). The only flag that matters
        // is whether the student has a NAT record for the unit (Criterion 1 above), which
        // already fires for hidden courses since v5.9.71. $_courseHidden kept for reference.

        if (!empty($_reasons)) {
            $foeToUnenrol[] = [
                'userid'     => $_uid,
                'clientid'   => $_lcCid,
                'name'       => $foeClientToName[$_lcCid] ?? ('User ' . $_uid),
                'courseid'   => $_cid,
                'coursename' => $_courseNames[$_cid] ?? ('Course ' . $_cid),
                'unitcode'   => $_unitCode,
                'enrolid'    => (int)$_er->enrolid,
                'catid'      => $_courseCatid[$_cid] ?? 0,
                'reason'     => implode('; ', $_reasons),
            ];
        }
    }
    $_enrolRs->close();

    // ── APPLY (POST) ─────────────────────────────────────────────────────────
    if ($action === 'fix_overenrolments_apply') {
        // FOE-BATCH-CHUNKED (v5.9.94): replaced the old synchronous all-at-once loop
        // with a chunked AJAX approach so large batches (e.g. 16,000 rows) don't hit
        // the PHP web-request timeout. We bulk-insert all pending rows into
        // local_rtocompliance_foe_pending, then redirect to a progress page. JS on that
        // page calls foe_apply_chunk (200 rows/request) until done.
        require_sesskey();
        // Generate a unique batch identifier.
        $_batchId = bin2hex(random_bytes(16));
        // Bulk-insert all pending unenrolment rows — fast single-row inserts in a loop
        // are fine here because the bottleneck was the unenrol_user() calls, not the inserts.
        foreach ($foeToUnenrol as $_row) {
            $DB->insert_record('local_rtocompliance_foe_pending', (object)[
                'batchid'  => $_batchId,
                'importid' => (int)$importid,
                'userid'   => (int)$_row['userid'],
                'enrolid'  => (int)$_row['enrolid'],
                'courseid' => (int)$_row['courseid'],
                'status'   => 'pending',
            ], false);
        }
        // Redirect to the progress page — JS will drive the rest.
        redirect(new moodle_url('/local/rtocompliance/data_import.php', [
            'action'   => 'foe_progress',
            'batchid'  => $_batchId,
            'importid' => (int)$importid,
            'total'    => count($foeToUnenrol),
        ]));
    }

    // ── PREVIEW ───────────────────────────────────────────────────────────────
    echo $OUTPUT->header();

    // ── FOE DEBUG PANEL (v5.9.99): activated by &debugcid=XXXX in URL ────────
    // Shows exactly what the detection computed for a specific client ID so you
    // can pinpoint where the flag fails to fire without reading server logs.
    $_debugCid = strtolower(trim(optional_param('debugcid', '', PARAM_RAW))); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    if ($_debugCid !== '') {
        $debugOut  = '<div style="background:#fff8e1;border:2px solid #e65100;border-radius:6px;'
                   . 'padding:1rem 1.2rem;margin-bottom:1.5rem;font-size:0.87em;">';
        $debugOut .= '<strong style="color:#bf360c;font-size:1.1em;">&#128270; FOE Debug — Client ID: '
                   . htmlspecialchars($_debugCid) . '</strong>';

        // ── Block 1: NAT data for this client ─────────────────────────────
        $debugOut .= '<p style="margin:0.7rem 0 0.2rem;"><strong>1. NAT staging data ($allStudentUnits):</strong> ';
        if (isset($allStudentUnits[$_debugCid])) {
            $debugOut .= count($allStudentUnits[$_debugCid]) . ' unit(s) &rarr; ';
            foreach ($allStudentUnits[$_debugCid] as $_dbNatUc => $_dbNatOc) {
                $debugOut .= '<code>' . htmlspecialchars($_dbNatUc) . '</code>'
                           . ' (outcome: <code>' . htmlspecialchars($_dbNatOc !== '' ? $_dbNatOc : 'null/empty') . '</code>) ';
            }
        } else {
            $debugOut .= '<span style="color:#c62828;font-weight:600;">NOT FOUND — client ID not in staging table for this import.</span>';
        }
        $debugOut .= '</p>';

        // ── Block 2: Matched userid ────────────────────────────────────────
        $_dbMatchedUid = $foeClientToUserid[$_debugCid] ?? 0;
        $debugOut .= '<p style="margin:0.3rem 0;"><strong>2. Matched Moodle userid ($foeClientToUserid):</strong> ';
        if ($_dbMatchedUid > 0) {
            $debugOut .= '<code>' . $_dbMatchedUid . '</code> via <em>'
                       . htmlspecialchars($foeMatchedVia[$_debugCid] ?? '?') . '</em>';
        } else {
            $debugOut .= '<span style="color:#c62828;font-weight:600;">NOT MATCHED — student is completely invisible to the bulk query.</span>';
        }
        $debugOut .= '</p>';

        // ── Block 3: Is userid in $foeMatchedUserids array? ───────────────
        $debugOut .= '<p style="margin:0.3rem 0;"><strong>3. Userid in $foeMatchedUserids:</strong> ';
        if ($_dbMatchedUid > 0 && in_array($_dbMatchedUid, $foeMatchedUserids, true)) {
            $debugOut .= '<span style="color:#2e7d32;font-weight:600;">YES — included in bulk SQL IN clause.</span>';
        } elseif ($_dbMatchedUid > 0) {
            $debugOut .= '<span style="color:#c62828;font-weight:600;">NO — userid ' . $_dbMatchedUid
                       . ' is missing from $foeMatchedUserids! This is why the bulk query skips this student.</span>';
        } else {
            $debugOut .= '<em>n/a (no matched userid)</em>';
        }
        $debugOut .= '</p>';

        // ── Block 4: Per-course detection table ───────────────────────────
        if ($_dbMatchedUid > 0) {
            $_dbEnrols = $DB->get_records_sql(
                "SELECT e.courseid, c.fullname, c.shortname, c.idnumber, c.category, c.visible
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
                   JOIN {course} c ON c.id = e.courseid AND c.id <> 1
                  WHERE ue.userid = :uid AND ue.status = 0
                  ORDER BY c.fullname ASC",
                ['uid' => $_dbMatchedUid]
            );
            $debugOut .= '<p style="margin:0.5rem 0 0.2rem;"><strong>4. Active manual enrolments ('
                       . count($_dbEnrols) . ' total) — per-course detection walk-through:</strong></p>';
            $debugOut .= '<div style="overflow-x:auto;">'
                       . '<table style="width:100%;border-collapse:collapse;font-size:0.88em;">'
                       . '<thead><tr style="background:#f5f5f5;">'
                       . '<th style="border:1px solid #bbb;padding:3px 7px;" title="Moodle course and its category">Course (cat)</th>'
                       . '<th style="border:1px solid #bbb;padding:3px 7px;" title="Whether the course ID number was detected">In $_courseIdnumber?</th>'
                       . '<th style="border:1px solid #bbb;padding:3px 7px;" title="Unit of competency code">Unit code</th>'
                       . '<th style="border:1px solid #bbb;padding:3px 7px;" title="AVETMISS outcome recorded in the NAT file">NAT outcome</th>'
                       . '<th style="border:1px solid #bbb;padding:3px 7px;" title="Whether this row was flagged by the detection walk-through">Flag?</th>'
                       . '</tr></thead><tbody>';
            foreach ($_dbEnrols as $_dbe) {
                $_dbCrsId  = (int)$_dbe->courseid;
                $_dbInMap  = isset($_courseIdnumber[$_dbCrsId]);
                $_dbUcMapped = $_courseIdnumber[$_dbCrsId] ?? '';
                // What unit code would _foe_extract_unitcode return for this course right now?
                $_dbUcLive = _foe_extract_unitcode(
                    (string)($_dbe->idnumber ?? ''),
                    (string)($_dbe->shortname ?? ''),
                    (string)($_dbe->fullname ?? '')
                );
                $_dbOutcome = null;
                if ($_dbInMap && $_dbUcMapped !== '') {
                    $_dbOutcome = $allStudentUnits[$_debugCid][$_dbUcMapped] ?? null;
                }
                $_dbNcCodes = ['10','20','40','41','51','52','53','54','60','61','81','82','85','90'];
                $_dbWouldFlag = $_dbInMap && ($_dbOutcome === null || in_array($_dbOutcome, $_dbNcCodes, true));
                $debugOut .= '<tr style="' . ($_dbWouldFlag ? 'background:#e8f5e9;' : '') . '">'
                           . '<td style="border:1px solid #bbb;padding:3px 7px;">'
                           . htmlspecialchars($_dbe->fullname)
                           . ' <small style="color:#888;">(cat=' . (int)$_dbe->category . ', '
                           . ((int)$_dbe->visible === 0 ? 'hidden' : 'visible') . ')</small></td>'
                           . '<td style="border:1px solid #bbb;padding:3px 7px;">'
                           . ($_dbInMap
                               ? '<span style="color:#2e7d32;">YES</span>'
                               : '<span style="color:#c62828;">NO</span>'
                             . ' (live extract: <code>' . htmlspecialchars($_dbUcLive !== '' ? $_dbUcLive : 'empty') . '</code>)')
                           . '</td>'
                           . '<td style="border:1px solid #bbb;padding:3px 7px;">'
                           . ($_dbInMap
                               ? '<code>' . htmlspecialchars($_dbUcMapped) . '</code>'
                               . ($_dbUcLive !== $_dbUcMapped ? ' <small style="color:#e65100;">(live differs: <code>' . htmlspecialchars($_dbUcLive) . '</code>)</small>' : '')
                               : '<em style="color:#888;">—</em>')
                           . '</td>'
                           . '<td style="border:1px solid #bbb;padding:3px 7px;">'
                           . ($_dbInMap
                               ? ($_dbOutcome === null
                                   ? '<em style="color:#c62828;">null — no NAT record &rarr; Criterion 1</em>'
                                   : '<code>' . htmlspecialchars($_dbOutcome) . '</code>')
                               : '<em style="color:#888;">—</em>')
                           . '</td>'
                           . '<td style="border:1px solid #bbb;padding:3px 7px;">'
                           . ($_dbWouldFlag
                               ? '<span style="color:#2e7d32;font-weight:700;">&#10003; YES</span>'
                               : '<span style="color:#888;">No</span>')
                           . '</td>'
                           . '</tr>';
            }
            $debugOut .= '</tbody></table></div>';
        }

        // ── Block 5: Actual $foeToUnenrol rows for this client ─────────────
        $_dbFoeRows = array_filter($foeToUnenrol, function ($_fr) use ($_debugCid) {
            return $_fr['clientid'] === $_debugCid;
        });
        $debugOut .= '<p style="margin:0.6rem 0 0.2rem;"><strong>5. Rows in $foeToUnenrol for this client:</strong> ';
        if (empty($_dbFoeRows)) {
            $debugOut .= '<span style="color:#c62828;font-weight:700;">0 rows.</span> '
                       . 'If the table above shows green (flaggable) rows, there is a detection bug.';
        } else {
            $debugOut .= '<span style="color:#2e7d32;font-weight:700;">' . count($_dbFoeRows) . ' row(s)</span> — detection fired correctly.';
            foreach ($_dbFoeRows as $_fr) {
                $debugOut .= '<br>&nbsp;&nbsp;&rarr; <code>' . htmlspecialchars($_fr['unitcode']) . '</code> '
                           . htmlspecialchars($_fr['coursename']) . ' — ' . htmlspecialchars($_fr['reason']);
            }
        }
        $debugOut .= '</p>';

        $debugOut .= '</div>';
        echo $debugOut;
    }
    // ── END DEBUG PANEL ───────────────────────────────────────────────────────

    echo '<ul class="nav nav-tabs mb-4">'
       . '<li class="nav-item"><a class="nav-link" href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '">&larr; Import Detail</a></li>'
       . '<li class="nav-item"><a class="nav-link active" style="font-weight:600;" href="#">Fix Over-Enrolments</a></li>'
       . '</ul>';
    echo '<h3 class="mb-3">Fix Over-Enrolments &mdash; All Categories</h3>';
    // ── How it works ─────────────────────────────────────────────────────────
    echo '<div class="card mb-4" style="border:1px solid #dee2e6;">';
    echo '<div class="card-header d-flex align-items-center" style="cursor:pointer;background:#f8f9fa;" '
       . 'onclick="var b=this.nextElementSibling;b.style.display=b.style.display===\'none\'?\'block\':\'none\';">'
       . '<strong>&#9432; How does Fix Over-Enrolments work?</strong>'
       . '<small class="ml-auto text-muted" style="font-size:0.85em;">(click to expand)</small></div>';
    echo '<div class="card-body" style="display:none;padding:1.25rem;">';
    // Plain-English summary
    echo '<p class="text-muted mb-3" style="font-size:0.93em;">'
       . 'The auto-enrol wizard enrols students into <strong>every course in a Moodle category</strong> when importing a NAT file. '
       . 'Students often end up in courses they have no NAT record for. This tool finds and removes those extra enrolments.'
       . '</p>';
    // Criterion cards
    echo '<div class="row mb-3">';
    echo '<div class="col-md-6 mb-3">';
    echo '<div class="card h-100" style="border-left:4px solid #fd7e14;background:#fffbf5;">';
    echo '<div class="card-body" style="padding:0.9rem;font-size:0.9em;">';
    echo '<p style="font-weight:700;margin-bottom:0.35rem;">Criterion 1 — Over-enrolment</p>';
    echo '<p class="mb-0 text-muted">Student is enrolled in a course whose unit code does not appear anywhere in their NAT data — the wizard added it automatically and they have no training record for it.</p>';
    echo '</div></div></div>';
    echo '<div class="col-md-6 mb-3">';
    echo '<div class="card h-100" style="border-left:4px solid #fd7e14;background:#fffbf5;">';
    echo '<div class="card-body" style="padding:0.9rem;font-size:0.9em;">';
    echo '<p style="font-weight:700;margin-bottom:0.35rem;">Criterion 2 — Finished / withdrawn outcome</p>';
    echo '<p class="mb-0 text-muted">The NAT file has a record for the unit, but the outcome code shows the student has a non-continuing result. Non-continuing codes: 10, 20, 30, 40, 41, 51&ndash;54, 60, 61, 81, 82, 85, 90. Code 30 (Competency Not Yet Achieved) is included — AVETMISS 8 defines it as a terminal outcome; in-progress students should carry code 70 (Continuing).</p>';
    echo '</div></div></div>';
    echo '</div>'; // end row
    // Section labels
    echo '<p style="font-weight:700;font-size:0.9em;margin-bottom:0.5rem;">What the sections on this page mean:</p>';
    echo '<div class="row" style="font-size:0.88em;">';
    echo '<div class="col-md-6 mb-2"><div style="padding:0.55rem 0.8rem;border-radius:4px;border-left:4px solid #ffc107;background:#fff9ed;">'
       . '<strong>Main table</strong> <span class="text-muted">(orange)</span> — courses that triggered Criterion 1 or 2. The &ldquo;Remove All&rdquo; button removes these.'
       . '</div></div>';
    echo '<div class="col-md-6 mb-2"><div style="padding:0.55rem 0.8rem;border-radius:4px;border-left:4px solid #dc3545;background:#fff5f5;">'
       . '<strong>Section A</strong> <span class="text-muted">(red)</span> — students in the NAT file with no matching Moodle account. Invisible to this tool — see the &ldquo;How to fix&rdquo; column.'
       . '</div></div>';
    echo '<div class="col-md-6 mb-2"><div style="padding:0.55rem 0.8rem;border-radius:4px;border-left:4px solid #ffc107;background:#fff9ed;">'
       . '<strong>Section B</strong> <span class="text-muted">(yellow)</span> — cohort sync, self-enrolment, or meta-course enrolments. This tool only removes <em>manual</em> enrolments; handle these separately.'
       . '</div></div>';
    echo '<div class="col-md-6 mb-2"><div style="padding:0.55rem 0.8rem;border-radius:4px;border-left:4px solid #dc3545;background:#fff5f5;">'
       . '<strong>Section C</strong> <span class="text-muted">(red)</span> — courses with no unit code set (e.g. Orientation, LLN, SA Trade). Cannot be auto-removed — add a unit code or unenrol students manually.'
       . '</div></div>';
    echo '<div class="col-md-6 mb-2"><div style="padding:0.55rem 0.8rem;border-radius:4px;border-left:4px solid #198754;background:#f0fff4;">'
       . '<strong>Section D</strong> <span class="text-muted">(green)</span> — courses with an AVETMISS-pattern unit code in their name/ID, but that unit code does not appear in any student\'s NAT data for this import. '
       . 'These are CPD, orientation, admin, or other non-accredited courses. <strong>Enrolments in these courses are never touched.</strong>'
       . '</div></div>';
    echo '</div>'; // end row
    echo '<div class="alert alert-warning mb-0 mt-2" style="padding:0.5rem 0.9rem;font-size:0.88em;">'
       . '<strong>&#9888; Review the list before applying.</strong> '
       . 'The Remove button removes all flagged enrolments at once. To keep a specific enrolment, re-enrol that student manually after applying.'
       . '</div>';
    echo '</div></div>'; // end card-body / card
    // ── Brief intro ──────────────────────────────────────────────────────────
    echo '<p class="text-muted" style="font-size:0.93em;">Compares each student\'s NAT00120 unit codes against their current Moodle enrolments across <strong>all categories</strong>. '
       . 'Courses are identified by unit code via (1) the Course ID number field, (2) the start of the course shortname, or (3) the start of the course fullname — whichever matches first. '
       . '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'foe_trace', 'importid' => $importid]))->out() . '" '
       . 'style="font-weight:600;">&#128270; Trace a specific student</a> — step-by-step proof of why a student does or doesn\'t appear here.</p>';
    $_matchDetail = [];
    if ($_foeViaIdnumber > 0) { $_matchDetail[] = $_foeViaIdnumber . ' via ID number'; }
    if ($_foeViaProfile  > 0) { $_matchDetail[] = $_foeViaProfile  . ' via student profile'; }
    if ($_foeViaUsername > 0) { $_matchDetail[] = $_foeViaUsername . ' via username'; }
    if ($_foeViaEmail    > 0) { $_matchDetail[] = $_foeViaEmail    . ' via email'; }
    if ($_foeViaUsi      > 0) { $_matchDetail[] = $_foeViaUsi      . ' via USI'; }
    $_unmatchedNote = ($_foeUnmatched > 0)
        ? ' <span style="color:#856404;font-size:0.88em;">(' . $_foeUnmatched . ' student(s) in NAT data could not be matched to any Moodle account — set their ID number field to fix)</span>'
        : '';
    echo '<p class="text-muted">Students matched: <strong>' . count($foeMatchedUserids) . '</strong>'
       . ($_matchDetail ? ' <span style="font-size:0.88em;color:#6c757d;">(' . implode(', ', $_matchDetail) . ')</span>' : '')
       . $_unmatchedNote
       . ' &nbsp;&middot;&nbsp; AVETMISS courses in scope: <strong>' . count($_courseIdnumber) . '</strong>'
       . ($_nameExtractCount > 0 ? ' <span style="color:#856404;font-size:0.85em;">(' . $_nameExtractCount . ' extracted from course name)</span>' : '')
       . (!empty($_courseExcludedNotInNat) ? ' &nbsp;&middot;&nbsp; <span style="color:#198754;font-weight:600;">Non-AVETMISS excluded: ' . count($_courseExcludedNotInNat) . '</span> <span style="font-size:0.82em;color:#6c757d;">(unit code pattern found but not in NAT — enrolments safe)</span>' : '')
       . '</p>';


    if (empty($foeToUnenrol)) {
        echo '<div class="alert alert-success" style="border-radius:6px;">'
           . '<strong style="font-size:1.05em;">&#10003; No over-enrolments found in the main table.</strong><br>'
           . 'All ' . count($foeMatchedUserids) . ' matched student(s) pass both outcome checks across all categories. '
           . 'Check the diagnostic sections below — if a student is still missing from the report, they will appear '
           . 'in <strong>Section A</strong> (Moodle account not matched to NAT data) '
           . 'or <strong>Section C</strong> (enrolled in a course with no unit code set in Moodle).</div>';
    }

    if (!empty($foeToUnenrol)) {
    // ── Build category breadcrumb labels for grouping display ─────────────────
    $_usedCatids = array_unique(array_column($foeToUnenrol, 'catid'));
    $_catNames   = [];
    foreach ($_usedCatids as $_caid) {
        $_crumbs = []; $_par = (int)$_caid; $_depth = 0;
        while ($_par > 0 && $_depth < 6) {
            $_cRec = $DB->get_record('course_categories', ['id' => $_par], 'id,name,parent', IGNORE_MISSING);
            if (!$_cRec) break;
            array_unshift($_crumbs, $_cRec->name);
            $_par = (int)$_cRec->parent; $_depth++;
        }
        $_catNames[$_caid] = implode(' › ', $_crumbs) ?: 'Category ' . $_caid;
    }

    // Group: catid → stuKey → rows
    $_byCat     = [];
    $_totalRows = count($foeToUnenrol);
    $_totalStus = count(array_unique(array_column($foeToUnenrol, 'clientid')));
    foreach ($foeToUnenrol as $_row) {
        $_stuKey = $_row['name'] . '||' . $_row['clientid'];
        $_byCat[$_row['catid']][$_stuKey][] = $_row;
    }
    ksort($_byCat);

    echo '<div class="alert alert-warning" style="border-radius:6px;">'
       . '<strong>' . $_totalRows . ' over-enrolment(s)</strong> found across '
       . '<strong>' . count($_byCat) . '</strong> category/categories and '
       . '<strong>' . $_totalStus . '</strong> student(s). '
       . 'Each row shows <em>why</em> the enrolment was flagged. Review before applying.<br>'
       . '<small class="d-block mt-1"><strong>Note:</strong> Applying will also delete any <strong>phantom 100% completion records</strong> '
       . 'that the NAT importer wrote for these courses. These appear as "100% &mdash; Last: Not yet accessed" '
       . 'because the importer writes <code>course_completions</code> rows when a NAT outcome is Competent/RPL/Credit Transfer. '
       . 'These phantom records are removed together with the enrolment.</small>'
       . '</div>';

    // Apply form — placed ABOVE the category tables so the button is reachable
    // without scrolling through potentially thousands of preview rows.
    echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out(false) . '" style="margin-bottom:1.5rem;">';
    echo '<input type="hidden" name="action"   value="fix_overenrolments_apply">';
    echo '<input type="hidden" name="importid" value="' . (int)$importid . '">';
    echo '<input type="hidden" name="sesskey"  value="' . sesskey() . '">';
    echo '<button type="submit" class="btn btn-danger" title="Remove all detected over-enrolments for this import (cannot be undone)" '
       . 'onclick="return confirm(\'Remove ' . $_totalRows . ' over-enrolment(s) from ' . $_totalStus . ' student(s) across all categories? This cannot be undone. Proceed?\')">'
       . 'Remove All ' . $_totalRows . ' Over-Enrolments</button> ';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '" class="btn btn-secondary ml-2">Back to Import</a>';
    echo '</form>';

    // Category tables — ALL rows rendered so the search box covers the full list.
    // Rows are flat (no rowspan) so JS show/hide works on every <tr> independently.

    // ── Search box ────────────────────────────────────────────────────────────
    echo '<div class="d-flex align-items-center mb-3 mt-2" style="gap:0.5rem;">';
    echo '<div class="input-group" style="max-width:420px;">';
    echo '<div class="input-group-prepend"><span class="input-group-text" style="background:#fff;">&#128270;</span></div>';
    echo '<input type="text" id="foe-search" class="form-control" '
       . 'placeholder="Search by name, client ID, course or unit code…" '
       . 'autocomplete="off" '
       . 'style="border-left:0;" '
       . 'oninput="foeFilter()">';
    echo '<div class="input-group-append">'
       . '<button class="btn btn-outline-secondary" type="button" onclick="document.getElementById(\'foe-search\').value=\'\';foeFilter();" title="Clear">&#10005;</button>'
       . '</div>';
    echo '</div>';
    echo '<span id="foe-match-count" class="text-muted" style="font-size:0.87em;white-space:nowrap;"></span>';
    echo '</div>';

    // ── Category tables (all rows, flat — no rowspan) ─────────────────────────
    foreach ($_byCat as $_catId => $_stuData) {
        ksort($_stuData);
        $_catCount = array_sum(array_map('count', $_stuData));
        echo '<h5 class="foe-cat-heading" style="margin-top:1.2rem;margin-bottom:0.4rem;font-weight:600;">'
           . htmlspecialchars($_catNames[$_catId] ?? 'Category ' . $_catId)
           . ' <span class="badge badge-secondary foe-cat-badge" style="font-size:0.75em;" title="Number of records in this category.">' . $_catCount . '</span></h5>';
        echo '<div class="table-responsive mb-3">';
        echo '<table class="table table-sm table-bordered generaltable foe-cat-table" style="font-size:0.88em;">';
        echo '<thead class="thead-light"><tr>'
           . '<th title="Student name">Student</th><th title="AVETMISS client identifier">Client ID</th><th title="Moodle course">Course</th><th title="Unit of competency code">Unit Code</th><th title="Why this enrolment was flagged">Reason</th></tr></thead><tbody>';
        foreach ($_stuData as $_stuKey => $_rows) {
            foreach ($_rows as $_row) {
                $_searchVal = strtolower(
                    ($_row['name']       ?? '') . ' ' .
                    ($_row['clientid']   ?? '') . ' ' .
                    ($_row['coursename'] ?? '') . ' ' .
                    ($_row['unitcode']   ?? '') . ' ' .
                    ($_row['reason']     ?? '')
                );
                echo '<tr data-foe-search="' . htmlspecialchars($_searchVal) . '">';
                echo '<td style="font-weight:600;">'    . htmlspecialchars($_row['name'])       . '</td>';
                echo '<td style="color:#6c757d;">'      . htmlspecialchars($_row['clientid'])   . '</td>';
                echo '<td>'                             . htmlspecialchars($_row['coursename'])  . '</td>';
                echo '<td><code>'                       . htmlspecialchars($_row['unitcode'])    . '</code></td>';
                echo '<td style="color:#856404;">'      . htmlspecialchars($_row['reason'] ?? '') . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    // ── Search JS ─────────────────────────────────────────────────────────────
    echo '<script>
function foeFilter() {
    var q = (document.getElementById("foe-search").value || "").toLowerCase().trim();
    var rows = document.querySelectorAll("tr[data-foe-search]");
    var shown = 0, total = rows.length;
    rows.forEach(function (tr) {
        var match = !q || tr.getAttribute("data-foe-search").indexOf(q) !== -1;
        tr.style.display = match ? "" : "none";
        if (match) shown++;
    });
    // Update count label
    var lbl = document.getElementById("foe-match-count");
    if (q) {
        lbl.textContent = shown + " of " + total + " row" + (total !== 1 ? "s" : "") + " match";
    } else {
        lbl.textContent = "";
    }
    // Show/hide category headings based on whether any of their table rows are visible
    document.querySelectorAll(".foe-cat-table").forEach(function (tbl) {
        var hasVisible = Array.from(tbl.querySelectorAll("tr[data-foe-search]")).some(function (r) {
            return r.style.display !== "none";
        });
        var wrap = tbl.closest(".table-responsive");
        var heading = wrap ? wrap.previousElementSibling : null;
        if (wrap) wrap.style.display = hasVisible ? "" : "none";
        if (heading && heading.classList.contains("foe-cat-heading")) {
            heading.style.display = hasVisible ? "" : "none";
        }
    });
}
// Auto-focus the search box when the page loads
document.addEventListener("DOMContentLoaded", function () {
    var box = document.getElementById("foe-search");
    if (box) box.focus();
});
</script>';

    // Repeat button at bottom for convenience after scrolling.
    echo '<form method="post" action="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out(false) . '" style="margin-top:1rem;">';
    echo '<input type="hidden" name="action"   value="fix_overenrolments_apply">';
    echo '<input type="hidden" name="importid" value="' . (int)$importid . '">';
    echo '<input type="hidden" name="sesskey"  value="' . sesskey() . '">';
    echo '<button type="submit" class="btn btn-danger" title="Remove all detected over-enrolments for this import (cannot be undone)" '
       . 'onclick="return confirm(\'Remove ' . $_totalRows . ' over-enrolment(s) from ' . $_totalStus . ' student(s) across all categories? This cannot be undone. Proceed?\')">'
       . 'Remove All ' . $_totalRows . ' Over-Enrolments</button> ';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '" class="btn btn-secondary ml-2">Back to Import</a>';
    echo '</form>';
    } // end if (!empty($foeToUnenrol))

    // ── Diagnostic panel ─────────────────────────────────────────────────────
    // Three sections explain WHY a student may not appear in the main table:
    // (A) Unmatched students — in NAT data but no Moodle account was found.
    // (B) Non-manual enrolments — enrolled via cohort/self/meta, not manual.
    // (C) Courses with no unit code in the student's qualification category.
    echo '<hr style="margin-top:2rem;">';
    echo '<h5 style="margin-top:1.5rem;">&#128270; Why might a student not appear in the main table above?</h5>';
    echo '<p class="text-muted" style="font-size:0.91em;margin-bottom:0.5rem;">'
       . 'There are three common reasons. Each is explained in its own section below.</p>';
    echo '<ul class="text-muted" style="font-size:0.91em;margin-bottom:1rem;">';
    echo '<li><strong>Section A</strong> — student is in the NAT file but we couldn\'t find their Moodle account (no ID number match, no email match, no USI match). They are invisible to the whole tool.</li>';
    echo '<li><strong>Section B</strong> — student is enrolled via cohort sync or self-enrolment, not a manual enrolment. This tool only removes manual enrolments.</li>';
    echo '<li><strong>Section C</strong> — student is in courses in their qualification category that have no unit code set, so the main check can\'t compare them against the NAT file.</li>';
    echo '</ul>';

    // ── Section A: Unmatched students ────────────────────────────────────────
    if (!empty($foeUnmatchedDetails)) {
        echo '<div class="alert" style="border:1px solid #f5c6cb;background:#fff5f5;border-radius:6px;margin-top:1rem;">';
        echo '<strong style="color:#721c24;">&#10007; ' . count($foeUnmatchedDetails)
           . ' student(s) in this NAT file could not be matched to any Moodle account</strong><br>'
           . '<small style="color:#856404;">These students are <strong>completely invisible</strong> to Fix Over-Enrolments — '
           . 'even if they are genuinely over-enrolled. Five matching methods were tried: '
           . 'ID number field, student profile table, username, email (NAT00085), and USI (NAT00080).</small>';
        echo '<div class="table-responsive" style="margin-top:0.8rem;">';
        echo '<table class="table table-sm table-bordered generaltable" style="font-size:0.86em;margin-bottom:0;">';
        echo '<thead class="thead-light"><tr>'
           . '<th title="Student name as recorded in the NAT file">Name in NAT file</th>'
           . '<th title="AVETMISS client identifier">Client ID</th>'
           . '<th title="Email address as recorded in the NAT file">Email in NAT file</th>'
           . '<th title="Suggested steps to resolve this record">How to fix</th></tr></thead><tbody>';
        foreach ($foeUnmatchedDetails as $_ud) {
            $_displayName = htmlspecialchars(trim($_ud['name']) ?: '(name not in NAT00080)');
            $_displayCid  = htmlspecialchars($_ud['clientid']);
            $_displayEm   = htmlspecialchars(trim($_ud['email']) ?: '—');
            echo '<tr>'
               . '<td style="font-weight:600;">' . $_displayName . '</td>'
               . '<td><code>' . $_displayCid . '</code></td>'
               . '<td>' . $_displayEm . '</td>'
               . '<td style="color:#495057;">Set this student\'s Moodle <strong>ID number</strong> to <code>' . $_displayCid . '</code>'
               . ' (<em>Site Admin &rarr; Users &rarr; Browse list of users &rarr; Edit &rarr; Other fields &rarr; ID number</em>), '
               . 'then re-run Fix Over-Enrolments.</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-success" style="border-radius:6px;margin-top:1rem;">'
           . '&#10003; All students in this NAT file were matched to a Moodle account. '
           . 'If a student is still missing from the list above, they are enrolled only via a non-manual method '
           . '(cohort sync, self-enrolment, meta-course) — see below.</div>';
    }

    // ── Section B: Non-manual enrolment warning ───────────────────────────────
    // Query: for the matched users, how many active non-manual enrolments exist
    // in courses that have a unit code? These are the "invisible enrolments".
    $_nonManualCount = 0;
    $_nonManualPlugins = [];
    if (!empty($foeMatchedUserids) && !empty($_courseids ?? array_keys($_courseIdnumber))) {
        $_nmCourseids = array_keys($_courseIdnumber);
        if (!empty($_nmCourseids)) {
            list($_nmCsql, $_nmCparams) = $DB->get_in_or_equal($_nmCourseids, SQL_PARAMS_NAMED, 'foenmc');
            list($_nmUsql, $_nmUparams) = $DB->get_in_or_equal($foeMatchedUserids, SQL_PARAMS_NAMED, 'foenmuid');
            $_nmRs = $DB->get_recordset_sql(
                "SELECT e.enrol, COUNT(*) AS cnt
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.enrol <> 'manual'
                    AND e.courseid $_nmCsql
                    AND ue.userid $_nmUsql
                    AND ue.status = 0
                  GROUP BY e.enrol",
                array_merge($_nmCparams, $_nmUparams)
            );
            foreach ($_nmRs as $_nr) {
                $_nonManualCount += (int)$_nr->cnt;
                $_nonManualPlugins[] = htmlspecialchars($_nr->enrol) . ' (' . (int)$_nr->cnt . ')';
            }
            $_nmRs->close();
        }
    }
    if ($_nonManualCount > 0) {
        echo '<div class="alert" style="border:1px solid #ffc107;background:#fffbf0;border-radius:6px;margin-top:1rem;">';
        echo '<strong style="color:#856404;">&#9888; ' . $_nonManualCount
           . ' active non-manual enrolment(s) found in courses with unit codes — these are NOT shown above</strong><br>'
           . '<small>Fix Over-Enrolments only analyses <code>enrol = \'manual\'</code> enrolments. '
           . 'The following enrolment methods were found and are <strong>ignored</strong>: '
           . implode(', ', $_nonManualPlugins) . '.<br>'
           . 'If a student is enrolled via cohort sync or self-enrolment in a unit-code course, '
           . 'you must unenrol them manually from the course, or remove them from the cohort.</small>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-info" style="border-radius:6px;margin-top:1rem;">'
           . '&#10003; No non-manual enrolments (cohort/self/meta) found in unit-code courses for matched students.</div>';
    }

    // ── Section C: Category-scoped enrolments in courses WITHOUT a unit code ────
    // FOE-CATEGORY-SCOPE (v5.9.86): The correct approach is:
    // 1. Use the student's NAT unit codes to find which Moodle CATEGORY holds
    //    those unit courses (e.g. "ABC12345 / 2025 S1").
    // 2. Look at ALL courses in that category where the student is enrolled.
    // 3. Any course in that category with NO unit code set is flagged — because
    //    the auto-enrol wizard enrolled the student into every course in the
    //    category (including Orientation, LLN, SA Trade, etc.) not just their
    //    specific units.
    // This is targeted, not sitewide: a no-unit-code course in a completely
    // different category is NOT flagged. Enrolment date is shown so admins can
    // distinguish recent legitimate new enrolments from stale auto-wizard ones.
    //
    // Build: unitcode (UC) → catid using the already-loaded $_courseIdnumber map.
    $_unitToCatid = []; // UC unitcode → catid
    foreach ($_courseIdnumber as $_ucCid => $_ucCode) {
        if (!isset($_unitToCatid[$_ucCode]) && isset($_courseCatid[$_ucCid])) {
            $_unitToCatid[$_ucCode] = $_courseCatid[$_ucCid];
        }
    }

    // For each matched student, determine their "home" category IDs by mapping
    // their NAT unit codes → Moodle courses → category.
    $_studentHomeCats = []; // lcCid → [catid => true]
    foreach ($allStudentUnits as $_shLcCid => $_shNatUnits) {
        if (!isset($foeClientToUserid[$_shLcCid])) continue;
        foreach (array_keys($_shNatUnits) as $_shUc) {
            $_shCatid = $_unitToCatid[$_shUc] ?? 0;
            if ($_shCatid > 0) {
                $_studentHomeCats[$_shLcCid][$_shCatid] = true;
            }
        }
    }

    // Collect all unique home catids across all students for the SQL IN clause.
    $_allHomeCatids = [];
    foreach ($_studentHomeCats as $_hcArr) {
        foreach (array_keys($_hcArr) as $_hcId) {
            $_allHomeCatids[$_hcId] = true;
        }
    }
    $_allHomeCatids = array_keys($_allHomeCatids);

    // Query: manual enrolments for matched students in home-category courses
    // that have NO unit code set. Include enrolment timestamp so admin can
    // judge whether it predates or postdates the NAT file (recent = may be
    // a new legitimate enrolment; old = likely auto-wizard over-enrolment).
    $_noCodeRows = [];
    if (!empty($_allHomeCatids) && !empty($foeMatchedUserids)) {
        list($_ncCatSql, $_ncCatParams) = $DB->get_in_or_equal($_allHomeCatids, SQL_PARAMS_NAMED, 'foenccat');
        list($_ncUsql, $_ncUparams)     = $DB->get_in_or_equal($foeMatchedUserids, SQL_PARAMS_NAMED, 'foenc');
        $_ncRs = $DB->get_recordset_sql(
            "SELECT ue.userid, ue.timecreated AS enroldate, e.courseid,
                    c.fullname, c.category, c.visible
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid AND e.enrol = 'manual'
               JOIN {course} c ON c.id = e.courseid AND c.id <> 1
              WHERE c.category $_ncCatSql
                AND ue.userid $_ncUsql
                AND ue.status = 0
                AND (c.idnumber IS NULL OR TRIM(c.idnumber) = '')
              ORDER BY c.fullname ASC",
            array_merge($_ncCatParams, $_ncUparams)
        );
        foreach ($_ncRs as $_nr) {
            $_nrUid   = (int)$_nr->userid;
            $_nrLcCid = $_uidToLcCid[$_nrUid] ?? null;
            if ($_nrLcCid === null) continue;
            $_nrCatid = (int)$_nr->category;
            // Only flag if this category is actually a home category for THIS student.
            if (!isset($_studentHomeCats[$_nrLcCid][$_nrCatid])) continue;
            $_noCodeRows[] = [
                'userid'     => $_nrUid,
                'courseid'   => (int)$_nr->courseid,
                'coursename' => (string)$_nr->fullname,
                'catid'      => $_nrCatid,
                'visible'    => (int)$_nr->visible,
                'enroldate'  => (int)($_nr->enroldate ?? 0),
                'name'       => $foeClientToName[$_nrLcCid] ?? ('User ' . $_nrUid),
                'clientid'   => $_nrLcCid,
            ];
        }
        $_ncRs->close();
    }

    if (!empty($_noCodeRows)) {
        $_noCodeByStu = [];
        foreach ($_noCodeRows as $_ncr) {
            $_ncStuKey = $_ncr['name'] . '||' . $_ncr['clientid'];
            $_noCodeByStu[$_ncStuKey][] = $_ncr;
        }
        ksort($_noCodeByStu);

        echo '<div class="alert" style="border:1px solid #dc3545;background:#fff5f5;border-radius:6px;margin-top:1rem;">';
        echo '<strong style="color:#721c24;">&#9888; Section C — ' . count($_noCodeRows)
           . ' enrolment(s) in courses with no unit code, within the student\'s qualification category ('
           . count($_noCodeByStu) . ' student(s))</strong><br>';
        echo '<small style="color:#495057;">'
           . 'These courses sit in the <strong>same Moodle category</strong> as the student\'s actual unit courses '
           . '(e.g. the same ABC12345 / 2025 S1 category), but they have <strong>no Course ID number</strong> set. '
           . 'Because there is no unit code, the main FOE check has nothing to compare against the NAT file — '
           . 'so they are listed here separately for you to review manually.<br><br>'
           . '<strong>Why are they here?</strong> The auto-enrol wizard adds students into <em>every</em> course in the selected Moodle category when it imports a NAT file — '
           . 'including Orientation, LLN, SA Trade, and any other support or admin courses that happen to sit in that category alongside the real units.<br><br>'
           . '<strong>What should you do?</strong>'
           . '<ul style="margin:0.3rem 0 0 1.2rem;">'
           . '<li>If the student genuinely should <strong>not</strong> be in the course (e.g. Orientation is auto-added but they never do it) — <strong>manually unenrol</strong> them from the course (Course &rarr; Participants &rarr; Unenrol).</li>'
           . '<li>If the course is actually a real training unit — <strong>add the unit code</strong> to the Course ID number field (Course Settings &rarr; Course ID number), then re-run Fix Over-Enrolments so it will be checked automatically next time.</li>'
           . '</ul>'
           . '</small>';
        echo '<div class="table-responsive" style="margin-top:0.8rem;">';
        echo '<table class="table table-sm table-bordered generaltable" style="font-size:0.86em;margin-bottom:0;">';
        echo '<thead class="thead-light"><tr>'
           . '<th title="Student name">Student</th><th title="AVETMISS client identifier">Client ID</th><th title="Moodle course that has no unit code set">Course (no unit code set)</th>'
           . '<th title="Whether the student is enrolled in the course">Enrolled</th><th title="Whether the course is visible">Visible</th></tr></thead><tbody>';
        $_ncShown = 0;
        foreach ($_noCodeByStu as $_ncStuKey => $_ncStuRows) {
            if ($_ncShown >= 100) break;
            $_ncFirst = true;
            $_ncCnt   = min(count($_ncStuRows), 100 - $_ncShown);
            $_ncRi    = 0;
            foreach ($_ncStuRows as $_ncRow) {
                if ($_ncShown >= 100 || $_ncRi >= $_ncCnt) break;
                echo '<tr>';
                if ($_ncFirst) {
                    echo '<td rowspan="' . $_ncCnt . '" style="vertical-align:middle;font-weight:600;">'
                       . htmlspecialchars($_ncRow['name']) . '</td>';
                    echo '<td rowspan="' . $_ncCnt . '" style="vertical-align:middle;color:#6c757d;">'
                       . htmlspecialchars($_ncRow['clientid']) . '</td>';
                    $_ncFirst = false;
                }
                $_ncDateStr = $_ncRow['enroldate'] > 0
                    ? date('d M Y', $_ncRow['enroldate'])
                    : '—';
                $_ncVis = ($_ncRow['visible'] ? 'Visible' : '<span style="color:#6c757d;">Hidden</span>');
                echo '<td>' . htmlspecialchars($_ncRow['coursename']) . '</td>';
                echo '<td style="white-space:nowrap;">' . $_ncDateStr . '</td>';
                echo '<td>' . $_ncVis . '</td>';
                echo '</tr>';
                $_ncShown++;
                $_ncRi++;
            }
        }
        if ($_ncShown >= 100 && count($_noCodeRows) > 100) {
            echo '<tr><td colspan="5" style="color:#6c757d;font-style:italic;">'
               . '&#8230; and ' . (count($_noCodeRows) - 100) . ' more row(s) not shown.</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-success" style="border-radius:6px;margin-top:1rem;">'
           . '&#10003; No category-scoped enrolments found in non-unit-code courses for matched students.</div>';
    }

    // ── Section D: Non-AVETMISS courses excluded from FOE scope ─────────────
    // FOE-AVETMISS-SCOPE (v5.9.150): Courses whose extracted unit code pattern does
    // NOT appear in any student's NAT records — excluded from FOE entirely.
    echo '<h4 style="margin-top:2rem;">&#128994; Section D — Non-AVETMISS Courses (excluded from FOE scope)</h4>';
    if (!empty($_courseExcludedNotInNat)) {
        echo '<div class="alert alert-success" style="border-left:4px solid #198754;border-radius:4px;font-size:0.9em;">'
           . '&#9989; <strong>' . count($_courseExcludedNotInNat) . ' course(s) excluded.</strong> '
           . 'These courses have an AVETMISS-pattern unit code in their name or ID number, but that unit code does not appear in any student\'s NAT records for this import. '
           . 'They are treated as non-AVETMISS courses (CPD, orientation, admin access, resource courses, etc.). '
           . '<strong>No enrolments in these courses were analysed or flagged.</strong>'
           . '</div>';
        echo '<div style="overflow-x:auto;">';
        echo '<table class="table table-sm table-bordered" style="font-size:0.86em;">'
           . '<thead style="background:#f8f9fa;">'
           . '<tr>'
           . '<th title="Moodle course full name">Course</th>'
           . '<th title="Moodle course short name">Shortname</th>'
           . '<th style="width:130px;" title="Unit code extracted from the course">Extracted Unit Code</th>'
           . '<th title="Why this course was excluded">Reason excluded</th>'
           . '</tr></thead><tbody>';
        $_dShown = 0;
        foreach ($_courseExcludedNotInNat as $_dCid => $_dRow) {
            if ($_dShown >= 200) break;
            echo '<tr>'
               . '<td>' . htmlspecialchars($_dRow['fullname']) . '</td>'
               . '<td><code style="font-size:0.88em;">' . htmlspecialchars($_dRow['shortname']) . '</code></td>'
               . '<td><code>' . htmlspecialchars($_dRow['unitcode']) . '</code></td>'
               . '<td style="color:#6c757d;">' . htmlspecialchars($_dRow['reason']) . '</td>'
               . '</tr>';
            $_dShown++;
        }
        if (count($_courseExcludedNotInNat) > 200) {
            echo '<tr><td colspan="4" style="color:#6c757d;font-style:italic;">'
               . '&#8230; and ' . (count($_courseExcludedNotInNat) - 200) . ' more course(s) not shown.</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div class="alert alert-success" style="border-radius:6px;margin-top:0.5rem;">'
           . '&#10003; No non-AVETMISS courses detected. Every course with an AVETMISS-pattern unit code also appears in this import\'s NAT staging data.</div>';
    }

    echo '<p style="margin-top:1.5rem;">'
       . '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]))->out() . '" class="btn btn-secondary">Back to Import</a>'
       . '</p>';
    echo $OUTPUT->footer();
    exit;
}

// ─── Archive Index: action handlers ─────────────────────────────────────────
//  action=rebuild_archive_index  — POST — triggers a full rebuild + redirects
//  action=assign_archive_family  — POST — admin assigns family to a NULL row
//  action=set_active_archive     — POST — admin resolves a duplicate period
//  action=archive_index          — GET  — shows the index admin page

if ($action === 'rebuild_archive_index' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $result = local_rtocompliance_rebuild_archive_index();
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_index']),
        'Archive index rebuilt: ' . $result['inserted'] . ' categories indexed, '
            . $result['null_family'] . ' need family assignment, '
            . $result['duplicates'] . ' duplicate period(s).',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'assign_archive_family' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $catid      = required_param('categoryid', PARAM_INT);
    $newFamily  = required_param('family',     PARAM_ALPHANUMEXT);
    $validFamilies = array_keys(local_rtocompliance_archive_family_keywords());
    if (in_array($newFamily, $validFamilies, true)) {
        $DB->set_field('local_rtocompliance_archive_index', 'family', $newFamily, ['categoryid' => $catid]);
        // Also check if this assignment resolved a duplicate
        $row = $DB->get_record('local_rtocompliance_archive_index', ['categoryid' => $catid]);
        if ($row) {
            $dupeCount = $DB->count_records('local_rtocompliance_archive_index',
                ['family' => $newFamily, 'year' => $row->year, 'sem' => $row->sem]);
            if ($dupeCount === 1) {
                $DB->set_field('local_rtocompliance_archive_index', 'is_active', 1, ['categoryid' => $catid]);
            }
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_index']),
        'Family assigned.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'set_active_archive' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $family   = required_param('family',     PARAM_ALPHANUMEXT);
    $year     = required_param('year',       PARAM_INT);
    $sem      = required_param('sem',        PARAM_ALPHANUMEXT);
    $chosenId = required_param('categoryid', PARAM_INT);
    // Upsert active pick
    $existing = $DB->get_record('local_rtocompliance_archive_active_pick',
        ['family' => $family, 'year' => $year, 'sem' => $sem]);
    if ($existing) {
        $existing->active_categoryid = $chosenId;
        $DB->update_record('local_rtocompliance_archive_active_pick', $existing);
    } else {
        $pick = new stdClass();
        $pick->family            = $family;
        $pick->year              = $year;
        $pick->sem               = $sem;
        $pick->active_categoryid = $chosenId;
        $DB->insert_record('local_rtocompliance_archive_active_pick', $pick);
    }
    // Update is_active flags
    $DB->execute(
        'UPDATE {local_rtocompliance_archive_index}
            SET is_active = CASE WHEN categoryid = ? THEN 1 ELSE 0 END
          WHERE family = ? AND year = ? AND sem = ?',
        [$chosenId, $family, $year, $sem]
    );
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_index']),
        'Active archive set.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'archive_index') {
    echo $OUTPUT->header();

    // ── Page header ──────────────────────────────────────────────────────────
    echo '<h2>Archive Index</h2>';
    echo '<p class="text-muted">This page scans your Moodle course categories and builds a lookup table so the NAT import knows exactly which '
       . 'Moodle category to put each group of students into. <strong>Rebuild any time you add, rename, or reorganise your archive categories.</strong></p>';

    // ── Stats bar ────────────────────────────────────────────────────────────
    $totalRows    = $DB->count_records('local_rtocompliance_archive_index');
    $nullRows     = $DB->count_records_select('local_rtocompliance_archive_index', 'family IS NULL');
    $inactiveRows = $DB->count_records('local_rtocompliance_archive_index', ['is_active' => 0]);
    $lastRebuilt  = local_rtocompliance_archive_get_meta('last_rebuilt');
    $lastHash     = local_rtocompliance_archive_get_meta('last_hash');
    $currentHash  = local_rtocompliance_archive_category_hash();
    $needsRebuild = ($lastHash !== $currentHash);

    // Count conflict groups (where >1 row exists for same family+year+sem) and truly-unresolved ones.
    // "Truly unresolved" excludes conflicts where every candidate has a distinct qual code in its
    // path — the NAT import auto-routes those groups without admin intervention.
    $allFamilyQcKeys = array_keys(local_rtocompliance_qual_to_family());
    try {
        $preCheckGroups = $DB->get_records_sql(
            'SELECT ' . $DB->sql_concat('family', "'-'", 'year', "'-'", 'sem') . ' AS rowkey,
                    family, year, sem
               FROM {local_rtocompliance_archive_index}
              WHERE family IS NOT NULL
              GROUP BY family, year, sem
             HAVING COUNT(*) > 1'
        );
    } catch (Exception $e) { $preCheckGroups = []; }
    $conflictGroups   = count($preCheckGroups);
    $unresolvedGroups = 0;
    foreach ($preCheckGroups as $pcg) {
        try {
            $pcCands = $DB->get_records('local_rtocompliance_archive_index',
                ['family' => $pcg->family, 'year' => $pcg->year, 'sem' => $pcg->sem]);
        } catch (Exception $e) { $pcCands = []; }
        $pcHasActive = false;
        foreach ($pcCands as $pcc) { if ($pcc->is_active) { $pcHasActive = true; break; } }
        if ($pcHasActive) continue; // already resolved
        // Auto-routeable if: at least one candidate has a qual code in its path AND no two
        // candidates share the same qual code (so the import can pick exactly one per qual).
        // Candidates WITHOUT qual codes are dead/legacy categories — the import bypasses them.
        $pcSeenQcs = []; $pcAllDistinct = true;
        foreach ($pcCands as $pcc) {
            $pcHay = strtoupper(($pcc->fullpath ?? '') . ' ' . ($pcc->categoryname ?? ''));
            foreach ($allFamilyQcKeys as $kqc) {
                if (strpos($pcHay, strtoupper($kqc)) !== false) {
                    if (isset($pcSeenQcs[$kqc])) { $pcAllDistinct = false; break 2; }
                    $pcSeenQcs[$kqc] = true;
                }
            }
        }
        if ($pcAllDistinct && !empty($pcSeenQcs)) continue; // auto-routeable — not a blocker
        $unresolvedGroups++;
    }

    echo '<div class="row mb-3">';
    echo '<div class="col-auto"><div class="card text-center px-3 py-2"><div class="card-body p-0">'
       . '<small class="text-muted d-block">Archive periods indexed</small><strong>' . $totalRows . '</strong></div></div></div>';
    if ($unresolvedGroups > 0) {
        echo '<div class="col-auto"><div class="card border-danger text-center px-3 py-2"><div class="card-body p-0">'
           . '<small class="text-muted d-block">Conflicts to resolve</small><strong class="text-danger">' . $unresolvedGroups . '</strong></div></div></div>';
    } elseif ($conflictGroups > 0) {
        echo '<div class="col-auto"><div class="card border-success text-center px-3 py-2"><div class="card-body p-0">'
           . '<small class="text-muted d-block">Conflicts</small><strong class="text-success">All resolved ✓</strong></div></div></div>';
    }
    if ($nullRows > 0) {
        echo '<div class="col-auto"><div class="card border-warning text-center px-3 py-2"><div class="card-body p-0">'
           . '<small class="text-muted d-block">Unknown category type</small><strong class="text-warning">' . $nullRows . '</strong></div></div></div>';
    }
    $rebuildLabel = $lastRebuilt
        ? 'Last rebuilt ' . userdate((int)$lastRebuilt, '%d %b %Y %H:%M') . ' · hash ' . substr($lastHash, 0, 8)
        : 'Not yet built';
    echo '<div class="col-auto d-flex align-items-center"><small class="text-muted">' . htmlspecialchars($rebuildLabel) . '</small></div>';
    echo '</div>';

    if ($needsRebuild) {
        echo '<div class="alert alert-warning"><strong>Your Moodle category structure has changed since the last rebuild.</strong> '
           . 'Please click "Rebuild Index" below before running a NAT import to make sure the lookup table is up to date.</div>';
    }

    // ── Rebuild button ───────────────────────────────────────────────────────
    $rebuildUrl = new moodle_url('/local/rtocompliance/data_import.php',
        ['action' => 'rebuild_archive_index', 'sesskey' => sesskey()]);
    echo '<form method="post" action="' . $rebuildUrl->out(false) . '" class="mb-4">';
    echo '<button type="submit" class="btn btn-primary" title="Scan the Moodle category tree and rebuild the archive index">Rebuild Archive Index</button>';
    echo ' <a href="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out(false) . '" class="btn btn-secondary ml-2">Back to Import</a>';
    echo '</form>';

    if ($totalRows === 0) {
        echo '<p class="text-muted"><em>No index rows yet. Click "Rebuild Archive Index" to scan your Moodle category tree.</em></p>';
        echo $OUTPUT->footer();
        exit;
    }

    // ── Null-family rows — admin must assign before imports can proceed ───────
    if ($nullRows > 0) {
        echo '<h4 class="mt-4">Unassigned Categories <span class="badge badge-warning" title="Number of course categories that still need a qualification family assigned before imports can use them.">' . $nullRows . '</span></h4>';
        echo '<p class="text-muted mb-3">These categories have a year in their name but no qualification family could be detected from their ancestors. '
           . 'Assign a family to each so imports can use them.</p>';
        echo '<table class="table table-sm table-bordered mb-4">';
        echo '<thead class="thead-light"><tr>'
           . '<th title="Moodle course category name">Category</th><th title="Full category path in the hierarchy">Full Path</th><th title="Collection year">Year</th><th title="Semester">Sem</th><th title="Assign a qualification family to this category">Assign Family</th></tr></thead><tbody>';
        $nullRecords = $DB->get_records_select('local_rtocompliance_archive_index',
            'family IS NULL', [], 'year DESC, sem DESC', 'id,categoryid,categoryname,fullpath,year,sem');
        $assignUrl = new moodle_url('/local/rtocompliance/data_import.php',
            ['action' => 'assign_archive_family', 'sesskey' => sesskey()]);
        $familyOptions = array_keys(local_rtocompliance_archive_family_keywords());
        foreach ($nullRecords as $r) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($r->categoryname, ENT_QUOTES) . '</td>';
            echo '<td><small class="text-muted">' . htmlspecialchars($r->fullpath, ENT_QUOTES) . '</small></td>';
            echo '<td>' . $r->year . '</td><td>' . htmlspecialchars($r->sem, ENT_QUOTES) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . $assignUrl->out(false) . '" class="d-flex" style="gap:6px;">';
            echo '<input type="hidden" name="categoryid" value="' . $r->categoryid . '">';
            echo '<select name="family" class="form-control form-control-sm" style="min-width:180px;">';
            foreach ($familyOptions as $f) {
                echo '<option value="' . htmlspecialchars($f, ENT_QUOTES) . '">'
                   . htmlspecialchars(ucwords(str_replace('_', ' ', $f)), ENT_QUOTES) . '</option>';
            }
            echo '</select>';
            echo '<button type="submit" class="btn btn-sm btn-warning" title="Assign the selected qualification family to this category">Assign</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ── Duplicate/conflict rows — admin must pick one ────────────────────────
    // Show ALL groups that have more than one candidate (resolved and unresolved)
    // IMPORTANT: get_records_sql() keys rows by the FIRST column.
    // All conflict groups share the same family (e.g. 'customs_broking'), so we must
    // use a unique composite key as the first column to avoid rows overwriting each other.
    $dupeSql = 'SELECT ' . $DB->sql_concat('family', "'-'", 'year', "'-'", 'sem') . ' AS rowkey,
                       family, year, sem
                  FROM {local_rtocompliance_archive_index}
                 WHERE family IS NOT NULL
                 GROUP BY family, year, sem
                HAVING COUNT(*) > 1
                 ORDER BY family, year DESC, sem DESC';
    $dupeGroups = $DB->get_records_sql($dupeSql);

    if (!empty($dupeGroups)) {
        // Count truly-unresolved conflicts (excludes auto-routeable ones).
        $unresolvedCount = 0;
        foreach ($dupeGroups as $g) {
            $hasActive = $DB->count_records_select('local_rtocompliance_archive_index',
                'family = ? AND year = ? AND sem = ? AND is_active = 1',
                [$g->family, $g->year, $g->sem]);
            if ($hasActive) continue;
            // Auto-routeable if at least one candidate has a distinct qual code in its path.
            try {
                $ugCands = $DB->get_records('local_rtocompliance_archive_index',
                    ['family' => $g->family, 'year' => $g->year, 'sem' => $g->sem]);
            } catch (Exception $e) { $ugCands = []; }
            $ugSeenQcs = []; $ugAllDistinct = true;
            foreach ($ugCands as $ugc) {
                $ugHay = strtoupper(($ugc->fullpath ?? '') . ' ' . ($ugc->categoryname ?? ''));
                foreach ($allFamilyQcKeys as $kqc) {
                    if (strpos($ugHay, strtoupper($kqc)) !== false) {
                        if (isset($ugSeenQcs[$kqc])) { $ugAllDistinct = false; break 2; }
                        $ugSeenQcs[$kqc] = true;
                    }
                }
            }
            if ($ugAllDistinct && !empty($ugSeenQcs)) continue; // auto-routeable
            $unresolvedCount++;
        }

        echo '<div class="alert ' . ($unresolvedCount > 0 ? 'alert-danger' : 'alert-success') . ' mt-3">';
        if ($unresolvedCount > 0) {
            echo '<strong>' . $unresolvedCount . ' period' . ($unresolvedCount > 1 ? 's need' : ' needs') . ' your decision.</strong> ';
            echo 'Your Moodle archive folder has two (or more) categories for the same time period. '
               . 'The system doesn\'t know which one to use, so it\'s waiting for you to pick. '
               . 'Scroll down and click <strong>Set Active</strong> on the correct one for each group.';
        } else {
            echo '<strong>All conflicts resolved.</strong> Every period has one active category selected.';
        }
        echo '</div>';

        echo '<h4 class="mt-3">Conflicts <span class="badge ' . ($unresolvedCount > 0 ? 'badge-danger' : 'badge-success') . '" title="How many periods have more than one matching category, and how many of those still need one chosen.">'
           . count($dupeGroups) . ' total, ' . $unresolvedCount . ' unresolved</span></h4>';

        $setActiveUrl = new moodle_url('/local/rtocompliance/data_import.php',
            ['action' => 'set_active_archive', 'sesskey' => sesskey()]);

        foreach ($dupeGroups as $g) {
            $candidates = $DB->get_records('local_rtocompliance_archive_index',
                ['family' => $g->family, 'year' => $g->year, 'sem' => $g->sem],
                'categoryid ASC');
            $hasActive = false;
            foreach ($candidates as $c) { if ($c->is_active) { $hasActive = true; break; } }
            $familyLabel = ucwords(str_replace('_', ' ', $g->family));

            // Determine auto-routeability early so the card header can use the right colour.
            // (The detailed $fullyAuto/$partiallyAuto calculation below reuses $candidateQcMap.)
            $archQcPreview = [];
            foreach ($candidates as $c) {
                $phay = strtoupper(($c->fullpath ?? '') . ' ' . ($c->categoryname ?? ''));
                $pf   = [];
                foreach ($allFamilyQcKeys as $kqc) {
                    if (strpos($phay, strtoupper($kqc)) !== false) $pf[] = $kqc;
                }
                $archQcPreview[$c->categoryid] = $pf;
            }
            // Auto-routeable = at least one candidate has a distinct qual code in its path.
            // Candidates without qual codes are dead/legacy folders — the import bypasses them.
            $hdrSeenQcs = []; $hdrAllDistinct = true;
            foreach ($archQcPreview as $pqcs) {
                foreach ($pqcs as $pqc) {
                    if (isset($hdrSeenQcs[$pqc])) { $hdrAllDistinct = false; break 2; }
                    $hdrSeenQcs[$pqc] = true;
                }
            }
            $isFullyAuto = !$hasActive && $hdrAllDistinct && !empty($hdrSeenQcs);

            if ($hasActive) {
                $cardClass   = 'border-success';
                $headerClass = 'bg-light';
            } elseif ($isFullyAuto) {
                $cardClass   = 'border-info';
                $headerClass = 'bg-info text-white';
            } else {
                $cardClass   = 'border-danger';
                $headerClass = 'bg-danger text-white';
            }

            echo '<div class="card mb-3 ' . $cardClass . '">';
            echo '<div class="card-header ' . $headerClass . '">';
            echo '<strong>' . htmlspecialchars($familyLabel, ENT_QUOTES)
               . ' — ' . $g->year . ($g->sem ? ' ' . $g->sem : '') . '</strong>';
            if ($hasActive) {
                echo ' <span class="badge badge-success ml-2" title="One active category has been chosen for this period. Nothing more to do here.">Resolved</span>';
            } elseif ($isFullyAuto) {
                echo ' <span class="badge badge-light ml-2" title="The import picks the correct category on its own, so no action is needed.">Auto-routed by import</span>';
            } else {
                echo ' <span class="badge badge-light ml-2" title="Two or more categories match this period. Choose the correct one below.">Pick one below</span>';
            }
            echo '</div>';
            echo '<div class="card-body py-2">';

            // Extract qual codes from each candidate's fullpath (e.g. ABC12345, ABC12345)
            // A qual code looks like TLI##### or SC### etc — pull all uppercase letter+digit tokens
            // that appear in the family's qual_to_family map.
            $allFamilyQualCodes = array_keys(local_rtocompliance_qual_to_family());
            $candidateQcMap = []; // categoryid → [qualcodes found in fullpath]
            foreach ($candidates as $c) {
                $haystack = strtoupper($c->fullpath . ' ' . $c->categoryname);
                $found = [];
                foreach ($allFamilyQualCodes as $kqc) {
                    if (strpos($haystack, strtoupper($kqc)) !== false) {
                        $found[] = $kqc;
                    }
                }
                $candidateQcMap[$c->categoryid] = $found;
            }
            // Auto-routeable = at least one candidate has a distinct qual code in its path.
            // Candidates without qual codes are dead/legacy folders bypassed by the import.
            // Genuinely ambiguous = no candidate has a qual code, OR two candidates share the same one.
            $allDistinct  = true;
            $seenQcs      = [];
            $qcCandidates = []; // categoryid → first qual code found (for suggested-pick highlight)
            foreach ($candidateQcMap as $cid => $qcs) {
                if (!empty($qcs)) {
                    $qcCandidates[$cid] = $qcs[0];
                    foreach ($qcs as $qc) {
                        if (isset($seenQcs[$qc])) { $allDistinct = false; }
                        $seenQcs[$qc] = $cid;
                    }
                }
            }
            $isAutoRouteable = !$hasActive && $allDistinct && !empty($qcCandidates);

            if ($hasActive) {
                // Already resolved — no banner needed, card header shows "Resolved"
            } elseif ($isAutoRouteable) {
                $qcList = implode(', ', array_unique(array_values($qcCandidates)));
                echo '<div class="alert alert-info py-2 small mb-2">';
                echo '<strong>No action needed for imports.</strong> ';
                echo 'The NAT import reads each student\'s qualification code from the breadcrumb and routes them to the correct category automatically. ';
                echo 'The other option(s) have no qualification code in their path (usually old "Closed short courses" folders) and are bypassed. ';
                echo 'You only need to click Set Active if you want to make the selection explicit.';
                echo '</div>';
            } else {
                echo '<p class="text-danger small mb-2"><strong>Action needed:</strong> Two or more Moodle categories both match '
                   . htmlspecialchars($familyLabel, ENT_QUOTES) . ' ' . $g->year . ' ' . $g->sem
                   . '. Select the one that has the actual student courses in it, then click Set Active.</p>';
            }

            echo '<form method="post" action="' . $setActiveUrl->out(false) . '">';
            echo '<input type="hidden" name="family" value="' . htmlspecialchars($g->family, ENT_QUOTES) . '">';
            echo '<input type="hidden" name="year"   value="' . $g->year . '">';
            echo '<input type="hidden" name="sem"    value="' . htmlspecialchars($g->sem, ENT_QUOTES) . '">';
            foreach ($candidates as $c) {
                $isActive   = (int)$c->is_active;
                $cQcs       = $candidateQcMap[$c->categoryid] ?? [];
                $isSuggested = $isAutoRouteable && !empty($cQcs);
                echo '<div class="form-check mb-1">';
                echo '<input class="form-check-input" type="radio" name="categoryid" value="' . $c->categoryid . '"'
                   . ($isActive || $isSuggested ? ' checked' : '') . ' id="pick_' . $c->categoryid . '">';
                echo '<label class="form-check-label" for="pick_' . $c->categoryid . '">';
                echo '<strong>' . htmlspecialchars($c->categoryname, ENT_QUOTES) . '</strong>';
                // Show qual code badge(s) extracted from the full path
                foreach ($cQcs as $cQc) {
                    echo ' <span class="badge badge-info" title="Qual code found in category path">' . htmlspecialchars($cQc, ENT_QUOTES) . '</span>';
                }
                if ($isSuggested) {
                    echo ' <span class="badge badge-warning" title="Recommended: set this as active">Suggested</span>';
                }
                echo ' <span class="text-muted small">(' . htmlspecialchars($c->fullpath, ENT_QUOTES) . ')</span>';
                if ($isActive) echo ' <span class="badge badge-success" title="This is the category currently in use for this period.">currently active</span>';
                echo '</label></div>';
            }
            echo '<button type="submit" class="btn btn-sm btn-warning mt-2" title="Set the selected category as the active one for this family">Set Active</button>';
            echo '</form></div></div>';
        }
    }

    // ── Full index table ──────────────────────────────────────────────────────
    echo '<h4 class="mt-4">Full Index</h4>';

    // Group by family for readability
    $allRows = $DB->get_records_select('local_rtocompliance_archive_index',
        'family IS NOT NULL', [], 'family ASC, year DESC, sem DESC');
    if (empty($allRows)) {
        echo '<p class="text-muted"><em>No indexed rows with assigned families yet.</em></p>';
    } else {
        $byFamily = [];
        foreach ($allRows as $r) {
            $byFamily[$r->family][] = $r;
        }
        foreach ($byFamily as $fam => $rows) {
            $famLabel = ucwords(str_replace('_', ' ', $fam));
            echo '<h6 class="mt-3 text-secondary">' . htmlspecialchars($famLabel, ENT_QUOTES) . '</h6>';
            echo '<table class="table table-sm table-bordered mb-3">';
            echo '<thead class="thead-light"><tr><th title="Moodle course category name">Category</th><th title="Full category path in the hierarchy">Full Path</th><th title="Collection year">Year</th><th title="Semester">Sem</th><th title="Current status of this category">Status</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $statusBadge = $r->is_active
                    ? '<span class="badge badge-success">active</span>'
                    : '<span class="badge badge-secondary">inactive</span>';
                echo '<tr' . ($r->is_active ? '' : ' class="table-warning"') . '>';
                echo '<td>' . htmlspecialchars($r->categoryname, ENT_QUOTES) . '</td>';
                echo '<td><small class="text-muted">' . htmlspecialchars($r->fullpath, ENT_QUOTES) . '</small></td>';
                echo '<td>' . $r->year . '</td>';
                echo '<td>' . htmlspecialchars($r->sem, ENT_QUOTES) . '</td>';
                echo '<td>' . $statusBadge . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    echo $OUTPUT->footer();
    exit;
}

// ─── Archive Linking Wizard: upload form (GET action=archive_wizard) ──────────
// Reads intake_groups.json uploaded by admin, scores each group against Moodle
// course categories, and presents approve/skip cards for the admin to confirm.

if ($action === 'archive_wizard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header('Archive Linking Wizard', 'Data Import', '/local/rtocompliance/data_import.php', 'dataimport');

    // Step bar
    echo '<div class="rtoc-step-bar mb-4">';
    echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Upload NAT Files</span></div>';
    echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
    echo '<div class="rtoc-step rtoc-step-active"><span class="rtoc-step-num">&#8734;</span><span class="rtoc-step-lbl">Link Archive Courses</span></div>';
    echo '</div>';

    echo '<div class="card rtoc-explainer-card mb-4"><div class="card-body">';
    echo '<h5 class="card-title mb-2">Archive Linking Wizard</h5>';
    echo '<p class="mb-1">This wizard reads the <code>intake_groups.json</code> file from a Wisenet NAT export ';
    echo '(generated by the <strong>AI Grader Wisenet Converter</strong>) and automatically links each ';
    echo 'semester intake group to the matching Moodle archive course for every Qual Builder unit.</p>';
    echo '<p class="mb-1">For each intake group the wizard will:</p>';
    echo '<ul class="mb-2">';
    echo '<li>Score candidate Moodle courses based on year and semester matching</li>';
    echo '<li>Show you the best match — you <strong>approve or skip</strong> each one</li>';
    echo '<li>On approval, links the course via the Archive Courses junction table, so future enrolments trigger AVETMISS records</li>';
    echo '</ul>';
    echo '</div></div>';

    $formurl = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'do_archive_link']);
    echo '<div class="card mb-4"><div class="card-body">';
    echo '<h6 class="card-title">Upload intake_groups.json</h6>';
    echo '<p class="text-muted mb-3">Extract <code>intake_groups.json</code> from your Wisenet NAT ZIP and upload it here. ';
    echo 'The file is included automatically when you use the Wisenet CSV to AVETMISS Converter in the AI Grader portal.</p>';
    echo '<form method="post" action="' . $formurl->out(false) . '" enctype="multipart/form-data">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<div class="form-group mb-3">';
    echo '<label for="intake_json_file" class="form-label"><strong>intake_groups.json</strong></label>';
    echo '<input type="file" class="form-control-file" id="intake_json_file" name="intake_json_file" accept=".json,application/json" required>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary" title="Parse the uploaded file and review the proposed intake matches">Parse &amp; Review Matches &rarr;</button> ';
    echo html_writer::link(new moodle_url('/local/rtocompliance/data_import.php'), 'Cancel', ['class' => 'btn btn-secondary']);
    echo '</form>';
    echo '</div></div>';

    echo $OUTPUT->footer();
    exit;
}

// ─── Archive Linking Wizard: parse JSON + score matches (POST do_archive_link) ─

if ($action === 'do_archive_link' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // Step 1: Read uploaded intake_groups.json
    $jsonContent = '';
    if (!empty($_FILES['intake_json_file']['tmp_name']) && $_FILES['intake_json_file']['error'] === UPLOAD_ERR_OK) {
        $jsonContent = @file_get_contents($_FILES['intake_json_file']['tmp_name']);
    }

    // Step 2: If this is the second POST (approvals submitted), process them
    $approvals = optional_param_array('approve_group', [], PARAM_INT); // array of group indices to approve
    $groupsJson = optional_param('groups_json', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    if (!empty($approvals) && !empty($groupsJson)) {
        // Process approved group → course mappings
        $groupsData = @json_decode($groupsJson, true);
        $linked = 0;
        $skipped = 0;
        $errors = [];

        if ($DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {
            foreach ($approvals as $idx => $courseid) {
                if ((int)$courseid <= 0) { $skipped++; continue; }
                $semesterLabel = optional_param("semester_label_{$idx}", '', PARAM_TEXT);
                $qualunitid    = (int)optional_param("qualunitid_{$idx}", 0, PARAM_INT);
                if ($qualunitid <= 0) { $skipped++; continue; }

                if (!$DB->record_exists('local_rtocompliance_qualunit_courses', ['qualunitid' => $qualunitid, 'courseid' => (int)$courseid])) {
                    try {
                        $DB->insert_record('local_rtocompliance_qualunit_courses', (object)[
                            'qualunitid'     => $qualunitid,
                            'courseid'       => (int)$courseid,
                            'semester_label' => substr(trim($semesterLabel), 0, 100),
                            'is_archive'     => 1,
                            'timecreated'    => time(),
                        ]);
                        $linked++;
                    } catch (\Throwable $e) {
                        $errors[] = "Unit {$qualunitid} → course {$courseid}: " . $e->getMessage();
                    }
                } else {
                    $skipped++; // already linked
                }
            }
        } else {
            $errors[] = 'Archive courses table does not exist. Please upgrade the plugin first (v5.2.37+).';
        }

        $msg = "Archive Linking Wizard complete: {$linked} course link(s) created.";
        if ($skipped) $msg .= " {$skipped} skipped (already linked or no match).";
        if (!empty($errors)) $msg .= ' Errors: ' . implode('; ', array_slice($errors, 0, 3));
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php'),
            $msg,
            null,
            $linked > 0 ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }

    // Step 3: Parse JSON and score matches
    $intakeGroups = [];
    $parseError   = '';
    if ($jsonContent) {
        $decoded = @json_decode($jsonContent, true);
        if (is_array($decoded)) {
            $intakeGroups = $decoded;
        } else {
            $parseError = 'Could not parse intake_groups.json — invalid JSON. Please re-export from the Wisenet Converter.';
        }
    } else {
        $parseError = 'No file uploaded or file could not be read.';
    }

    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header('Archive Linking Wizard', 'Data Import', '/local/rtocompliance/data_import.php', 'dataimport');

    echo '<div class="rtoc-step-bar mb-4">';
    echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Upload NAT Files</span></div>';
    echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
    echo '<div class="rtoc-step rtoc-step-active"><span class="rtoc-step-num">&#8734;</span><span class="rtoc-step-lbl">Link Archive Courses</span></div>';
    echo '</div>';

    if ($parseError) {
        echo $OUTPUT->notification($parseError, 'error');
        echo html_writer::link(new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_wizard']), '&larr; Back', ['class' => 'btn btn-secondary']);
        echo $OUTPUT->footer();
        exit;
    }

    if (empty($intakeGroups)) {
        echo $OUTPUT->notification('intake_groups.json is empty — nothing to link.', 'warning');
        echo html_writer::link(new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_wizard']), '&larr; Back', ['class' => 'btn btn-secondary']);
        echo $OUTPUT->footer();
        exit;
    }

    // Fetch all Qual Builder units for scoring
    $allQualunits = $DB->get_records_sql(
        "SELECT qu.id, qu.unitcode, qu.unitname, qu.qualbuilderid, qu.courseid,
                qb.qualificationcode, qb.qualificationname, qb.categoryid
           FROM {local_rtocompliance_qualunits} qu
           JOIN {local_rtocompliance_qualbuilder} qb ON qb.id = qu.qualbuilderid
          ORDER BY qb.qualificationcode, qu.unitcode"
    );

    // Fetch all Moodle courses with their category names for smart scoring
    $allCourses = $DB->get_records_sql(
        "SELECT c.id, c.shortname, c.fullname, c.category,
                cat.name AS catname, cat.parent AS catparent,
                pcat.name AS parentcatname
           FROM {course} c
           JOIN {course_categories} cat   ON cat.id   = c.category
      LEFT JOIN {course_categories} pcat  ON pcat.id  = cat.parent
          WHERE c.id > 1
          ORDER BY c.fullname ASC"
    );

    // Check which unit→course links already exist (to show "already linked" on cards)
    $existingLinks = [];
    if ($DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {
        $existingRows = $DB->get_records('local_rtocompliance_qualunit_courses', null, '', 'qualunitid, courseid');
        foreach ($existingRows as $row) {
            $existingLinks[$row->qualunitid . '_' . $row->courseid] = true;
        }
    }

    /**
     * Score a Moodle course against an intake group.
     * Returns 0-100; higher = better match.
     */
    $scoreMatch = function (object $course, array $group) use ($allCourses): int {
        $score = 0;
        $label  = strtoupper($course->catname . ' ' . $course->parentcatname . ' ' . $course->fullname . ' ' . $course->shortname);
        $offerDesc = strtoupper($group['offerDesc'] ?? '');
        $year   = (int)($group['year'] ?? 0);
        $sem    = (int)($group['semester'] ?? 0);
        $qtr    = (int)($group['quarter'] ?? 0);
        $courseCode = strtoupper($group['courseCode'] ?? '');

        // Qual code match — high weight
        if ($courseCode && (strpos($label, $courseCode) !== false)) $score += 30;

        // Year match
        if ($year && strpos($label, (string)$year) !== false) $score += 25;

        // Semester match
        if ($sem === 1 && (preg_match('/SEMESTER\s*1|SEM\s*1|\bS1\b/', $label))) $score += 20;
        if ($sem === 2 && (preg_match('/SEMESTER\s*2|SEM\s*2|\bS2\b/', $label))) $score += 20;
        if ($qtr && preg_match('/TERM\s*' . $qtr . '|\bT' . $qtr . '\b/', $label)) $score += 20;

        // "Archive" keyword in category name
        if (strpos($label, 'ARCHIVE') !== false) $score += 10;
        if (strpos($label, 'HIST') !== false || strpos($label, 'LEGACY') !== false) $score += 5;

        return min(100, $score);
    };

    // For each intake group, find best course match per unique unit code
    echo '<p class="text-muted mb-3">Review the matches below. For each semester intake group and unit, ';
    echo 'approve or choose a different course. All approved links will be saved at once.</p>';

    $approveFormUrl = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'do_archive_link']);
    echo '<form method="post" action="' . $approveFormUrl->out(false) . '">';
    echo '<input type="hidden" name="sesskey"    value="' . s(sesskey()) . '">';
    echo '<input type="hidden" name="groups_json" value="' . s($jsonContent) . '">';

    $rowIdx = 0;
    $hasAnyMatch = false;

    foreach ($intakeGroups as $gIdx => $group) {
        $offerCode   = $group['offerCode']   ?? '';
        $offerDesc   = $group['offerDesc']   ?? '';
        $periodLabel = $group['periodLabel'] ?? ($offerCode ?: 'Unknown period');
        $courseCode  = $group['courseCode']  ?? '';
        $unitCodes   = $group['unitCodes']   ?? [];
        $studentCount= (int)($group['studentCount'] ?? 0);

        if (empty($unitCodes)) continue;

        echo '<div class="card mb-3">';
        echo '<div class="card-header d-flex align-items-center justify-content-between" style="gap:8px;">';
        echo '<div><strong>' . htmlspecialchars($periodLabel, ENT_QUOTES) . '</strong>';
        echo ' &nbsp;<span class="badge badge-secondary" title="The offer or intake code that identifies this group of students.">' . htmlspecialchars($offerCode, ENT_QUOTES) . '</span>';
        if ($courseCode) echo ' &nbsp;<span class="badge badge-info" title="The course code for this group.">' . htmlspecialchars($courseCode, ENT_QUOTES) . '</span>';
        echo ' &nbsp;<small class="text-muted">' . $studentCount . ' student(s), ' . count($unitCodes) . ' unit(s)</small>';
        echo '</div></div>';
        echo '<div class="card-body" style="padding:12px 16px;">';

        // Find units in Qual Builder matching this group's unit codes
        $matchedUnits = [];
        foreach ($allQualunits as $qu) {
            if (in_array($qu->unitcode, $unitCodes)) {
                $matchedUnits[] = $qu;
            }
        }

        if (empty($matchedUnits)) {
            echo '<p class="text-muted mb-0"><em>No matching units found in Qual Builder for this group\'s unit codes (' . implode(', ', array_slice($unitCodes, 0, 5)) . (count($unitCodes) > 5 ? '...' : '') . '). Ensure Qual Builder is set up first.</em></p>';
            echo '</div></div>';
            continue;
        }

        // Score all courses against this group and pick the top candidates
        $scored = [];
        foreach ($allCourses as $course) {
            $s = $scoreMatch($course, $group);
            if ($s > 0) $scored[$course->id] = $s;
        }
        arsort($scored);
        $topCourseIds = array_slice(array_keys($scored), 0, 1); // top 1 best match

        foreach ($matchedUnits as $qu) {
            $alreadyLinkedToTop = !empty($topCourseIds) && isset($existingLinks[$qu->id . '_' . $topCourseIds[0]]);

            echo '<div class="d-flex align-items-start mb-2 p-2 border rounded" style="gap:12px;background:#f8f9fa;">';
            // Unit info
            echo '<div style="min-width:200px;">';
            echo '<strong class="d-block" style="font-size:0.85em;">' . htmlspecialchars($qu->unitcode, ENT_QUOTES) . '</strong>';
            echo '<small class="text-muted">' . htmlspecialchars(substr($qu->unitname, 0, 50), ENT_QUOTES) . '</small>';
            echo '</div>';
            // Course selector
            echo '<div style="flex:1;">';
            echo '<select name="approve_group[' . $rowIdx . ']" class="form-control form-control-sm" style="margin-bottom:4px;">';
            echo '<option value="0">-- Skip this unit --</option>';
            foreach ($allCourses as $course) {
                $sc = $scored[$course->id] ?? 0;
                if ($sc < 5 && count($allCourses) > 20) continue; // hide low-score noise when many courses
                $sel = (!empty($topCourseIds) && $course->id == $topCourseIds[0] && !$alreadyLinkedToTop) ? ' selected' : '';
                $scoreBadge = $sc >= 40 ? ' [' . $sc . '%]' : '';
                echo '<option value="' . $course->id . '"' . $sel . '>' . htmlspecialchars($course->shortname . ' — ' . substr($course->fullname, 0, 60), ENT_QUOTES) . $scoreBadge . '</option>';
            }
            echo '</select>';
            if ($alreadyLinkedToTop && !empty($topCourseIds)) {
                echo '<small class="text-success">&#10003; Already linked to top match</small>';
            }
            echo '</div>';
            // Hidden fields
            echo '<input type="hidden" name="qualunitid_' . $rowIdx . '"    value="' . (int)$qu->id . '">';
            echo '<input type="hidden" name="semester_label_' . $rowIdx . '" value="' . s($periodLabel) . '">';
            echo '</div>';

            $rowIdx++;
            $hasAnyMatch = true;
        }

        echo '</div></div>';
    }

    if ($hasAnyMatch) {
        echo '<div style="margin-top:20px;">';
        echo '<button type="submit" class="btn btn-primary btn-lg" title="Save the approved student-to-intake links">Save Approved Links &rarr;</button> ';
        echo html_writer::link(new moodle_url('/local/rtocompliance/data_import.php'), 'Cancel', ['class' => 'btn btn-secondary']);
        echo '</div>';
    } else {
        echo $OUTPUT->notification('No matching units found in Qual Builder for any intake group. Set up Qual Builder units first.', 'warning');
        echo html_writer::link(new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_wizard']), '&larr; Back', ['class' => 'btn btn-secondary']);
    }

    echo '</form>';
    echo $OUTPUT->footer();
    exit;
}

// ─── VERIFY NAT DATA ─────────────────────────────────────────────────────────
// Upload NAT00080 (+ optional NAT00120) and cross-check every student against
// Moodle user accounts, RTO Compliance student records, and AVETMISS enrolments.
// v5.9.27
if ($action === 'verify_nat') {
    require_capability('local/rtocompliance:manage', context_system::instance());
    \core\session\manager::write_close();

    $PAGE->set_title(get_string('pluginname', 'local_rtocompliance') . ' — Verify NAT Data');
    $PAGE->set_heading(get_string('pluginname', 'local_rtocompliance'));
    echo $OUTPUT->header();
    echo local_rtocompliance_render_nav_header('Verify NAT Data', 'Data Import',
        '/local/rtocompliance/data_import.php', 'data_import');
    echo html_writer::start_div('compliance-container');
    echo html_writer::start_div('compliance-header');
    echo html_writer::tag('h2', 'Verify NAT Data');
    echo html_writer::tag('p',
        'Upload your NAT files to check which students have been successfully imported, '
        . 'enrolled in Moodle, and linked to RTO Compliance records.',
        ['style' => 'color:#fff;opacity:0.9;']);
    echo html_writer::end_div();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
        raise_memory_limit(MEMORY_HUGE);

        $nat80file  = $_FILES['nat00080'] ?? null;
        $nat85file  = $_FILES['nat00085'] ?? null;
        $nat120file = $_FILES['nat00120'] ?? null;

        if (!$nat80file || $nat80file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($nat80file['tmp_name'])) {
            echo $OUTPUT->notification('NAT00080 file is required.', 'error');
        } else {
            // ── 1. Parse NAT00080 ──────────────────────────────────────────────
            $nat80lines = file($nat80file['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $usiPos = local_rtocompliance_detect_nat00080_usi_pos(array_slice($nat80lines, 0, 100));

            $natClients = []; // clientid => [name, dob, usi, email]
            foreach ($nat80lines as $line80) {
                $p80 = local_rtocompliance_parse_nat00080($line80, $usiPos);
                if (!$p80) continue;
                $cid = trim((string)($p80['clientid'] ?? ''));
                if ($cid === '') continue;
                $natClients[$cid] = [
                    'name'  => $p80['name'] ?? '',
                    'dob'   => $p80['dob'] ?? '',
                    'usi'   => $p80['usi'] ?? '',
                    'email' => '',
                ];
            }

            // ── 2. Parse NAT00085 (optional) — contact details with email ──────
            $hasContactFile = ($nat85file && $nat85file['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($nat85file['tmp_name']));
            $nat85EmailByCid = []; // clientid => email
            if ($hasContactFile) {
                $nat85lines = file($nat85file['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($nat85lines as $line85) {
                    $p85 = local_rtocompliance_parse_nat00085($line85);
                    if (!$p85) continue;
                    $cid85   = trim((string)($p85['clientid'] ?? ''));
                    $email85 = trim((string)($p85['email'] ?? ''));
                    if ($cid85 !== '' && $email85 !== '') {
                        $nat85EmailByCid[$cid85] = strtolower($email85);
                    }
                }
                // Attach emails to natClients
                foreach ($natClients as $cid => &$nc) {
                    if (isset($nat85EmailByCid[$cid])) {
                        $nc['email'] = $nat85EmailByCid[$cid];
                    }
                }
                unset($nc);
            }

            // ── 3. Parse NAT00120 (optional) ──────────────────────────────────
            $natQuals = []; // clientid => [qualcode, ...]
            $hasQualFile = ($nat120file && $nat120file['error'] === UPLOAD_ERR_OK
                && is_uploaded_file($nat120file['tmp_name']));
            if ($hasQualFile) {
                $nat120lines = file($nat120file['tmp_name'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($nat120lines as $line120) {
                    $p120 = local_rtocompliance_parse_nat00120($line120);
                    if (!$p120) continue;
                    $cid120 = trim((string)($p120['clientid'] ?? ''));
                    $qc120  = trim((string)($p120['qualcode'] ?? ''));
                    if ($cid120 === '' || $qc120 === '') continue;
                    if (!isset($natQuals[$cid120])) $natQuals[$cid120] = [];
                    $qcUpper = strtoupper($qc120);
                    if (!in_array($qcUpper, $natQuals[$cid120], true)) {
                        $natQuals[$cid120][] = $qcUpper;
                    }
                }
            }

            $totalNat = count($natClients);
            if ($totalNat === 0) {
                echo $OUTPUT->notification('No students found in the NAT00080 file. Please check the file format.', 'warning');
            } else {
                $allCids   = array_keys($natClients);
                $chunkSize = 500;
                $dbErrors  = [];

                // ── 3. Bulk DB: check avetmiss_student staging table ──────────
                // "Imported" = clientid present in local_rtocompliance_avetmiss_student
                $rtoImportedByCid = []; // clientid => true
                try {
                    foreach (array_chunk($allCids, $chunkSize) as $chunk) {
                        [$insqlC, $inparamsC] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                        $rows = $DB->get_records_select(
                            'local_rtocompliance_avetmiss_student',
                            "clientid $insqlC",
                            $inparamsC,
                            '',
                            'id,clientid'
                        );
                        foreach ($rows as $r) {
                            $rtoImportedByCid[trim((string)$r->clientid)] = true;
                        }
                    }
                } catch (Exception $e) {
                    $dbErrors[] = 'RTO Compliance import check: ' . $e->getMessage();
                }

                // ── 4. Bulk DB: Moodle accounts (user.idnumber = clientid, or email fallback) ────
                // Primary match: user.idnumber = clientid (set by auto-enrol).
                // Fallback (when NAT00085 uploaded): match by email address — catches students
                // whose Moodle account predates the plugin or was created manually.
                $moodleUserByCid      = []; // clientid => userid (idnumber match)
                $moodleUserByEmail    = []; // clientid => userid (email fallback match)
                $moodleMatchMethod    = []; // clientid => 'idnumber' | 'email'
                $moodleUserIdList     = []; // flat list of all matched userids
                try {
                    foreach (array_chunk($allCids, $chunkSize) as $chunk) {
                        [$insqlC, $inparamsC] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                        $users = $DB->get_records_select(
                            'user',
                            "idnumber $insqlC AND deleted = 0",
                            $inparamsC,
                            '',
                            'id,idnumber'
                        );
                        foreach ($users as $u) {
                            $cid = trim((string)$u->idnumber);
                            $moodleUserByCid[$cid]   = (int)$u->id;
                            $moodleMatchMethod[$cid] = 'idnumber';
                            $moodleUserIdList[]      = (int)$u->id;
                        }
                    }
                } catch (Exception $e) {
                    $dbErrors[] = 'Moodle account check: ' . $e->getMessage();
                }

                // Email fallback — only for students not already matched by idnumber.
                if ($hasContactFile && !empty($nat85EmailByCid)) {
                    // Build a list of emails for students not yet matched.
                    $unmatchedEmails = [];
                    $emailToCid      = []; // lowercase email => clientid
                    foreach ($nat85EmailByCid as $cid => $email) {
                        if (!isset($moodleUserByCid[$cid]) && $email !== '') {
                            $lc = strtolower($email);
                            $unmatchedEmails[]   = $lc;
                            $emailToCid[$lc]     = $cid;
                        }
                    }
                    if (!empty($unmatchedEmails)) {
                        try {
                            foreach (array_chunk($unmatchedEmails, $chunkSize) as $chunk) {
                                [$esql, $eparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                                $eusers = $DB->get_records_select(
                                    'user',
                                    "LOWER(email) $esql AND deleted = 0",
                                    $eparams,
                                    '',
                                    'id,email'
                                );
                                foreach ($eusers as $eu) {
                                    $lc  = strtolower(trim((string)$eu->email));
                                    $cid = $emailToCid[$lc] ?? null;
                                    if ($cid !== null && !isset($moodleUserByCid[$cid])) {
                                        $moodleUserByEmail[$cid]  = (int)$eu->id;
                                        $moodleMatchMethod[$cid]  = 'email';
                                        $moodleUserIdList[]       = (int)$eu->id;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            $dbErrors[] = 'Moodle email match: ' . $e->getMessage();
                        }
                    }
                }

                // ── 5. Bulk DB: Moodle course enrolments ──────────────────────
                $moodleEnrolledUids = []; // userid => true
                try {
                    $uniqueUids = array_unique($moodleUserIdList);
                    foreach (array_chunk($uniqueUids, $chunkSize) as $chunk) {
                        [$usqlC, $uparamsC] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                        $rows = $DB->get_records_sql(
                            "SELECT DISTINCT ue.userid FROM {user_enrolments} ue WHERE ue.userid $usqlC",
                            $uparamsC
                        );
                        foreach ($rows as $row) {
                            $moodleEnrolledUids[(int)$row->userid] = true;
                        }
                    }
                } catch (Exception $e) {
                    $dbErrors[] = 'Moodle enrolment check: ' . $e->getMessage();
                }

                // ── 6. Bulk DB: qual-code check via avetmiss_enrolment ─────────
                // "clientid|QUALCODE" => count of enrolment rows
                $qualCountByCidQc = [];
                try {
                    if ($hasQualFile && !empty($allCids)) {
                        foreach (array_chunk($allCids, $chunkSize) as $chunk) {
                            [$insqlC, $inparamsC] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                            $qrows = $DB->get_records_sql(
                                "SELECT clientid, qualcode, COUNT(*) AS cnt
                                   FROM {local_rtocompliance_avetmiss_enrolment}
                                  WHERE clientid $insqlC
                                    AND qualcode IS NOT NULL AND qualcode <> ''
                                  GROUP BY clientid, qualcode",
                                $inparamsC
                            );
                            foreach ($qrows as $qr) {
                                $key = trim((string)$qr->clientid) . '|' . strtoupper(trim((string)$qr->qualcode));
                                $qualCountByCidQc[$key] = ($qualCountByCidQc[$key] ?? 0) + (int)$qr->cnt;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $dbErrors[] = 'Qualification check: ' . $e->getMessage();
                }

                // Show any DB errors as a warning (partial results may still display)
                foreach ($dbErrors as $dbErr) {
                    echo $OUTPUT->notification('Database error — ' . s($dbErr), 'error');
                }

                // ── 7. Build per-student result rows ──────────────────────────
                $results = [];
                foreach ($natClients as $cid => $nc) {
                    $isImported      = isset($rtoImportedByCid[$cid]);
                    $uid             = $moodleUserByCid[$cid] ?? $moodleUserByEmail[$cid] ?? 0;
                    $hasMoodleAcct   = ($uid > 0);
                    $matchMethod     = $moodleMatchMethod[$cid] ?? '';
                    $hasMoodleEnrol  = $hasMoodleAcct && isset($moodleEnrolledUids[$uid]);

                    // Qual status (from NAT00120 vs avetmiss_enrolment)
                    $qualStatus = [];
                    if ($hasQualFile && isset($natQuals[$cid])) {
                        foreach ($natQuals[$cid] as $qcExpected) {
                            $key   = $cid . '|' . strtoupper($qcExpected);
                            $found = isset($qualCountByCidQc[$key]) && $qualCountByCidQc[$key] > 0;
                            $qualStatus[] = ['qc' => $qcExpected, 'found' => $found];
                        }
                    }
                    $qualMissingCount = count(array_filter($qualStatus, fn($q) => !$q['found']));

                    // Overall status
                    if (!$isImported && !$hasMoodleAcct) {
                        $status = 'missing';
                    } elseif (!$hasMoodleAcct || $qualMissingCount > 0) {
                        $status = 'partial';
                    } elseif (!$hasMoodleEnrol) {
                        $status = 'notenrolled';
                    } else {
                        $status = 'ok';
                    }

                    $results[] = [
                        'clientid'       => $cid,
                        'name'           => $nc['name'],
                        'dob'            => $nc['dob'],
                        'usi'            => $nc['usi'],
                        'email'          => $nc['email'],
                        'isImported'     => $isImported,
                        'hasMoodleAcct'  => $hasMoodleAcct,
                        'matchMethod'    => $matchMethod,
                        'hasMoodleEnrol' => $hasMoodleEnrol,
                        'qualStatus'     => $qualStatus,
                        'status'         => $status,
                    ];
                }

                // ── 8. Summary tiles ──────────────────────────────────────────
                $countOk          = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
                $countPartial     = count(array_filter($results, fn($r) => $r['status'] === 'partial'));
                $countNotEnrolled = count(array_filter($results, fn($r) => $r['status'] === 'notenrolled'));
                $countMissing     = count(array_filter($results, fn($r) => $r['status'] === 'missing'));

                echo '<div class="row mb-4" style="gap:0;">';
                echo '<div class="col-6 col-md-3 mb-3"><div class="card text-center h-100" title="Students from the national file who are fully set up in Moodle, with both an account and a course enrolment. Nothing to do here."><div class="card-body py-3">';
                echo '<h2 class="text-success mb-1">' . $countOk . '</h2>';
                echo '<p class="mb-0 small">Fully imported &amp; enrolled</p>';
                echo '</div></div></div>';
                echo '<div class="col-6 col-md-3 mb-3"><div class="card text-center h-100" title="Students who are in the system but not fully set up yet, for example an account exists but a course enrolment is still missing."><div class="card-body py-3">';
                echo '<h2 class="text-warning mb-1">' . ($countPartial + $countNotEnrolled) . '</h2>';
                echo '<p class="mb-0 small">Partially set up</p>';
                echo '</div></div></div>';
                echo '<div class="col-6 col-md-3 mb-3"><div class="card text-center h-100" title="Students in the national file who have no matching record in Moodle yet."><div class="card-body py-3">';
                echo '<h2 class="text-danger mb-1">' . $countMissing . '</h2>';
                echo '<p class="mb-0 small">Not found in system</p>';
                echo '</div></div></div>';
                echo '<div class="col-6 col-md-3 mb-3"><div class="card text-center h-100" title="Total number of students in the national report file. NAT files are the national reporting files sent to the government."><div class="card-body py-3">';
                echo '<h2 class="mb-1">' . $totalNat . '</h2>';
                echo '<p class="mb-0 small">Total students in NAT file</p>';
                echo '</div></div></div>';
                echo '</div>';

                // ── 9. Filter buttons ─────────────────────────────────────────
                echo '<div class="mb-3 d-flex align-items-center flex-wrap" style="gap:6px;">';
                echo '<strong class="mr-2">Show:</strong>';
                echo '<button type="button" class="btn btn-sm btn-primary verify-filter-btn" data-filter="issues" onclick="verifyFilter(\'issues\')" title="Show only students with verification issues">Issues only (' . ($countPartial + $countNotEnrolled + $countMissing) . ')</button>';
                echo '<button type="button" class="btn btn-sm btn-outline-secondary verify-filter-btn" data-filter="all" onclick="verifyFilter(\'all\')" title="Show all students in the file">All students (' . $totalNat . ')</button>';
                echo '<button type="button" class="btn btn-sm btn-outline-secondary verify-filter-btn" data-filter="ok" onclick="verifyFilter(\'ok\')" title="Show only students that fully passed verification">Complete only (' . $countOk . ')</button>';
                echo '</div>';

                // ── 10. Results table ─────────────────────────────────────────
                echo '<div class="table-responsive">';
                echo '<table class="table table-sm table-hover" id="verify-results-table">';
                echo '<thead class="thead-light"><tr>';
                echo '<th title="AVETMISS client identifier">Client ID</th><th title="Student name">Name</th><th title="Date of birth from the NAT file">DOB (NAT)</th><th title="Unique Student Identifier from the NAT file">USI (NAT)</th>';
                echo '<th title="Whether the student exists in RTO Compliance records">In RTO Compliance</th><th title="Whether a matching Moodle account exists">Moodle Account</th><th title="Whether the student is enrolled in Moodle">Moodle Enrolled</th>';
                if ($hasQualFile) echo '<th title="Qualifications recorded for the student">Qualifications</th>';
                echo '<th title="Overall verification status for this student">Status</th>';
                echo '</tr></thead><tbody id="verify-tbody">';

                foreach ($results as $r) {
                    $rowClass = match($r['status']) {
                        'partial', 'notenrolled' => 'table-warning',
                        'missing'                => 'table-danger',
                        default                  => '',
                    };
                    $statusBadge = match($r['status']) {
                        'ok'          => '<span class="badge badge-success" title="Fully set up: imported, has a Moodle account, and is enrolled in a course.">Complete</span>',
                        'partial'     => '<span class="badge badge-warning" title="Imported, but something is still missing, such as a Moodle account or a course enrolment.">Incomplete</span>',
                        'notenrolled' => '<span class="badge badge-warning" title="Has a Moodle account but is not enrolled in any course yet.">Not enrolled</span>',
                        'missing'     => '<span class="badge badge-danger" title="This student from the national file has no matching record in Moodle yet.">Not in system</span>',
                        default       => '',
                    };

                    echo '<tr class="verify-row ' . $rowClass . '" data-status="' . s($r['status']) . '">';
                    echo '<td><code>' . s($r['clientid']) . '</code></td>';
                    echo '<td>' . s($r['name']) . '</td>';

                    // DOB: DDMMYYYY → DD/MM/YYYY
                    $dobRaw = $r['dob'];
                    if (!empty($dobRaw) && strlen($dobRaw) === 8 && ctype_digit($dobRaw)) {
                        $dobDisp = substr($dobRaw, 0, 2) . '/' . substr($dobRaw, 2, 2) . '/' . substr($dobRaw, 4, 4);
                    } else {
                        $dobDisp = '';
                    }
                    echo '<td>' . ($dobDisp !== '' ? $dobDisp : '<span class="text-muted">—</span>') . '</td>';

                    // USI (hide @-masked values)
                    $usiRaw  = $r['usi'];
                    $usiDisp = (!empty($usiRaw) && strpos($usiRaw, '@') === false) ? s($usiRaw) : '<span class="text-muted">—</span>';
                    echo '<td>' . $usiDisp . '</td>';

                    // In RTO Compliance (imported from NAT)
                    echo '<td class="text-center">' . ($r['isImported']
                        ? '<span class="text-success" title="Imported">&#10003;</span>'
                        : '<span class="text-danger" title="Not imported">&#10007;</span>') . '</td>';

                    // Moodle account (idnumber match, or email fallback)
                    if ($r['hasMoodleAcct']) {
                        if ($r['matchMethod'] === 'email') {
                            $acctTitle = 'Account found (matched by email: ' . s($r['email']) . ')';
                            echo '<td class="text-center"><span class="text-success" title="' . $acctTitle . '" style="cursor:help;">&#10003; <small style="font-size:0.7em;vertical-align:middle;">@</small></span></td>';
                        } else {
                            echo '<td class="text-center"><span class="text-success" title="Account found (matched by ID number)">&#10003;</span></td>';
                        }
                    } else {
                        $noAcctTitle = $hasContactFile && !empty($r['email'])
                            ? 'No account (email ' . s($r['email']) . ' not found in Moodle)'
                            : 'No account (no idnumber match)';
                        echo '<td class="text-center"><span class="text-danger" title="' . $noAcctTitle . '">&#10007;</span></td>';
                    }

                    // Moodle enrolment
                    if (!$r['hasMoodleAcct']) {
                        echo '<td class="text-center"><span class="text-muted">—</span></td>';
                    } elseif ($r['hasMoodleEnrol']) {
                        echo '<td class="text-center"><span class="text-success" title="Enrolled">&#10003;</span></td>';
                    } else {
                        echo '<td class="text-center"><span class="text-warning" title="Not enrolled in any course">&#8212;</span></td>';
                    }

                    // Qualifications
                    if ($hasQualFile) {
                        if (empty($r['qualStatus'])) {
                            echo '<td><span class="text-muted">—</span></td>';
                        } else {
                            $qparts = [];
                            foreach ($r['qualStatus'] as $qs) {
                                $qparts[] = $qs['found']
                                    ? '<span class="badge badge-success" title="This qualification code was found in Moodle.">' . s($qs['qc']) . '</span>'
                                    : '<span class="badge badge-danger" title="This qualification code was not found in Moodle.">' . s($qs['qc']) . ' !</span>';
                            }
                            echo '<td>' . implode(' ', $qparts) . '</td>';
                        }
                    }

                    echo '<td>' . $statusBadge . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table></div>';

                // Filter JS
                echo '<script>
function verifyFilter(f) {
    document.querySelectorAll(".verify-row").forEach(function (row) {
        var s = row.getAttribute("data-status");
        if (f === "all") row.style.display = "";
        else if (f === "ok") row.style.display = (s === "ok") ? "" : "none";
        else row.style.display = (s !== "ok") ? "" : "none";
    });
    document.querySelectorAll(".verify-filter-btn").forEach(function (btn) {
        btn.classList.toggle("btn-primary", btn.getAttribute("data-filter") === f);
        btn.classList.toggle("btn-outline-secondary", btn.getAttribute("data-filter") !== f);
    });
}
document.addEventListener("DOMContentLoaded", function () { verifyFilter("issues"); });
</script>';

                // Re-run button.
                echo '<div class="mt-3 d-flex flex-wrap gap-2" style="gap:.5rem">';
                echo html_writer::link(
                    new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'verify_nat']),
                    '&larr; Run another check', ['class' => 'btn btn-secondary mr-2']);
                echo '</div>';
            }
        }

    } else {
        // ── Upload form ────────────────────────────────────────────────────────
        $formUrl = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'verify_nat']);
        echo '<form method="post" action="' . $formUrl->out(false) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

        // What does Verify NAT Data check?
        echo '<div class="card border-info mb-4" style="background:#f0f8ff;">';
        echo '<div class="card-body py-3 px-4">';
        echo '<h6 class="font-weight-bold mb-2" style="color:#0c5460;">&#9432;&nbsp; What does this tool check?</h6>';
        echo '<p class="small mb-2">Upload your NAT files and this tool checks <strong>every student</strong> against three systems:</p>';
        echo '<ul class="small mb-2">';
        echo '<li><strong>AVETMISS Records</strong> — is the student in the RTO Compliance staging database (i.e. have they been imported via NAT import)?</li>';
        echo '<li><strong>Moodle Account</strong> — does the student have a Moodle login? Matched by client ID or email (if NAT00085 is uploaded).</li>';
        echo '<li><strong>Student Records</strong> — do they appear in the Students tab? This is what allows certificates to be issued.</li>';
        echo '</ul>';
        echo '<div class="alert alert-warning mb-0 py-2 px-3 small">';
        echo '<strong>If students are missing from Student Records:</strong> re-run the NAT import from the Data Import page — it populates the matching student profiles and writes their unit outcomes to the results register. '
            . 'Students in the file that can\'t be matched to an existing profile are listed in the downloadable review file. Import never creates Moodle accounts or enrolments.';
        echo '</div>';
        echo '</div></div>';

        echo '<div class="card mb-4">';
        echo '<div class="card-body">';
        echo html_writer::tag('h5', 'Upload NAT Files', ['class' => 'card-title mb-3']);

        echo '<div class="form-group">';
        echo '<label for="nat00080"><strong>NAT00080</strong> — Client file <span class="text-danger">*</span></label>';
        echo '<input type="file" class="form-control-file mt-1" id="nat00080" name="nat00080" accept=".txt,.csv" required>';
        echo '<small class="form-text text-muted">Student demographics and client identifiers.</small>';
        echo '</div>';

        echo '<div class="form-group mt-3">';
        echo '<label for="nat00085"><strong>NAT00085</strong> — Client contact details <span class="text-muted">(optional)</span></label>';
        echo '<input type="file" class="form-control-file mt-1" id="nat00085" name="nat00085" accept=".txt,.csv">';
        echo '<small class="form-text text-muted">If uploaded, students are also matched by email address — finds accounts that predate the auto-enrol process.</small>';
        echo '</div>';

        echo '<div class="form-group mt-3">';
        echo '<label for="nat00120"><strong>NAT00120</strong> — Program enrolments <span class="text-muted">(optional)</span></label>';
        echo '<input type="file" class="form-control-file mt-1" id="nat00120" name="nat00120" accept=".txt,.csv">';
        echo '<small class="form-text text-muted">If uploaded, each student\'s expected qualification codes are also verified.</small>';
        echo '</div>';

        echo '<div class="mt-4">';
        echo '<button type="submit" class="btn btn-primary" title="Check the uploaded NAT data against your system records">Run Verification Check</button>';
        echo '</div>';
        echo '</div></div>';
        echo '</form>';

        // What this checks
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo html_writer::tag('h5', 'What this verifies', ['class' => 'card-title']);
        echo '<table class="table table-sm mb-0">';
        echo '<tbody>';
        echo '<tr><td><strong>Moodle Account</strong></td><td>Is the student\'s client ID linked to a Moodle user account?</td></tr>';
        echo '<tr><td><strong>Moodle Enrolled</strong></td><td>Is the student enrolled in at least one Moodle course?</td></tr>';
        echo '<tr><td><strong>AVETMISS Records</strong></td><td>Does the student have unit enrolment records in RTO Compliance?</td></tr>';
        echo '<tr><td><strong>Qualifications</strong></td><td>Are the expected qualification records present? (requires NAT00120)</td></tr>';
        echo '</tbody></table>';
        echo '</div></div>';
    }

    echo html_writer::end_div(); // compliance-container
    echo $OUTPUT->footer();
    exit;
}

// ─── Backfill Student Records ─────────────────────────────────────────────────
// Creates a Moodle account (if missing) and a local_rtocompliance_students row
// for every student in the avetmiss_student staging table who is not yet in
// Student Records.  This allows certificates to be issued for students from any
// year without requiring them to go through the full auto-enrol flow.
// No course enrolments are created.
if ($action === 'backfill_records') {
    \core\session\manager::write_close();

    $confirmed = optional_param('confirmed', 0, PARAM_INT);

    $PAGE->set_title('Backfill Student Records');
    $PAGE->set_heading(get_string('pluginname', 'local_rtocompliance'));
    echo $OUTPUT->header();
    echo html_writer::start_div('compliance-container container-fluid py-4');

    $backfillUrl = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'backfill_records']);
    $verifyUrl   = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'verify_nat']);

    echo '<h2 class="mb-1">Backfill Student Records</h2>';
    echo '<p class="text-muted mb-4">Creates a Student Record (and Moodle account if needed) for every student '
        . 'in the NAT staging database who does not yet appear in Student Records. '
        . 'This means certificates can be issued for any student from any year. '
        . 'No course enrolments are changed.</p>';

    // ── Count how many need backfilling ───────────────────────────────────────
    $allClientIds      = [];
    $existingClientMap = [];
    $stagingCount      = 0;
    $alreadyCount      = 0;
    $toProcessCount    = 0;
    $countErr          = '';

    try {
        $allClientIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT clientid FROM {local_rtocompliance_avetmiss_student}"
        );
        $allClientIds = array_values(array_unique(array_map('strtolower', array_map('trim', $allClientIds))));
        $stagingCount = count($allClientIds);

        if ($stagingCount > 0) {
            foreach (array_chunk($allClientIds, 500) as $chunk) {
                list($insql, $inparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                $rows = $DB->get_fieldset_sql(
                    "SELECT DISTINCT clientid FROM {local_rtocompliance_students} WHERE clientid $insql",
                    $inparams
                );
                foreach ($rows as $cid) {
                    $existingClientMap[strtolower(trim((string)$cid))] = true;
                }
            }
        }
        $alreadyCount   = count($existingClientMap);
        $toProcessCount = max(0, $stagingCount - $alreadyCount);
    } catch (Exception $e) {
        $countErr = $e->getMessage();
    }

    if ($countErr !== '') {
        echo '<div class="alert alert-danger"><strong>Error loading counts:</strong> '
            . htmlspecialchars($countErr) . '</div>';
        echo html_writer::link($verifyUrl, '&larr; Back', ['class' => 'btn btn-secondary']);
        echo html_writer::end_div();
        echo $OUTPUT->footer();
        exit;
    }

    if (!$confirmed) {
        // ── Summary + confirm page ────────────────────────────────────────────
        echo '<div class="card mb-4" style="max-width:600px"><div class="card-body">';
        echo '<h5 class="card-title mb-3">What will happen</h5>';
        echo '<table class="table table-sm mb-3">';
        echo '<tr><td>Students in NAT staging database</td>'
            . '<td class="text-right"><strong>' . number_format($stagingCount) . '</strong></td></tr>';
        echo '<tr class="table-success"><td>Already in Student Records</td>'
            . '<td class="text-right"><strong>' . number_format($alreadyCount) . '</strong></td></tr>';
        $rowClass = ($toProcessCount > 0) ? 'table-warning' : 'table-success';
        echo '<tr class="' . $rowClass . '"><td>Will be created</td>'
            . '<td class="text-right"><strong>' . number_format($toProcessCount) . '</strong></td></tr>';
        echo '</table>';

        if ($toProcessCount === 0) {
            echo '<div class="alert alert-success mb-0">All students already have Student Records. Nothing to do.</div>';
        } else {
            echo '<div class="alert alert-info mb-3">For each of the <strong>'
                . number_format($toProcessCount) . '</strong> missing student(s):<ul class="mb-0 mt-2">'
                . '<li>A Moodle account is created if they do not already have one '
                . '(username = client ID, email from NAT files or a placeholder).</li>'
                . '<li>A Student Record is created in RTO Compliance so certificates can be issued.</li>'
                . '<li>No course enrolments are changed.</li>'
                . '</ul></div>';
            echo '<form method="post" action="' . $backfillUrl->out(false) . '">';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
            echo '<input type="hidden" name="confirmed" value="1">';
            echo '<button type="submit" class="btn btn-warning mr-2" title="Backfill qualification records for the listed students">'
                . 'Run Backfill (' . number_format($toProcessCount) . ' students)</button>';
            echo html_writer::link($verifyUrl, 'Cancel', ['class' => 'btn btn-secondary']);
            echo '</form>';
        }
        echo '</div></div>';

    } else {
        // ── Run the backfill ──────────────────────────────────────────────────
        require_sesskey();
        raise_memory_limit(MEMORY_HUGE);
        @set_time_limit(600);

        $bfCreatedUser   = 0; // Retained for compatibility; account creation removed in v5.9.392.
        $bfNoAccount     = 0;
        $bfMatchedUser   = 0;
        $bfCreatedRecord = 0;
        $bfSkipped       = 0;
        $bfFailed        = [];

        try {
            // 1. Load latest staging data per clientid (most recent importid wins)
            $latestData = [];
            $stagingRs  = $DB->get_recordset_sql(
                // DEMOG-PROPAGATION-FIX (v5.9.391): the SELECT previously omitted the
                // eight core AVETMISS demographic columns, so the INSERT below (which
                // reads them) always fell back to defaults (@ / @@ / 1101 / 1201 / N)
                // even though the real values were sitting in staging. Select them all
                // so a NAT import propagates the FULL demographic profile.
                "SELECT s.clientid, s.name, s.firstname, s.familyname, s.email,
                        s.dob, s.sex, s.usi, s.suburb, s.state, s.importid,
                        s.postcode, s.buildingname, s.unitno, s.streetname,
                        s.indigenousstatus, s.labourforcestatus, s.highestschoollevel,
                        s.languageathome, s.countryofbirth, s.disabilityflag,
                        s.prioreducationflag, s.atschoolflag
                   FROM {local_rtocompliance_avetmiss_student} s
                  INNER JOIN (
                        SELECT clientid, MAX(importid) AS maxiid
                          FROM {local_rtocompliance_avetmiss_student}
                         GROUP BY clientid
                  ) mx ON mx.clientid = s.clientid AND mx.maxiid = s.importid"
            );
            foreach ($stagingRs as $row) {
                $lcId = strtolower(trim((string)$row->clientid));
                $latestData[$lcId] = $row;
            }
            $stagingRs->close();

            // 2. Pre-load existing Moodle users (idnumber / username / email maps)
            $moodleByIdnumber = [];
            $moodleByUsername = [];
            $moodleByEmail    = [];
            $userRs = $DB->get_recordset_select('user',
                'deleted = 0 AND mnethostid = :mnet',
                ['mnet' => $CFG->mnet_localhost_id],
                '', 'id, username, idnumber, email');
            foreach ($userRs as $u) {
                $lcIdnum = strtolower(trim((string)$u->idnumber));
                $lcUser  = strtolower(trim((string)$u->username));
                $lcEmail = strtolower(trim((string)$u->email));
                if ($lcIdnum !== '') $moodleByIdnumber[$lcIdnum] = (int)$u->id;
                if ($lcUser  !== '') $moodleByUsername[$lcUser]  = (int)$u->id;
                if ($lcEmail !== '') $moodleByEmail[$lcEmail]    = (int)$u->id;
            }
            $userRs->close();

            // 3. Pre-load existing student records (by userid) to skip duplicates
            $existingByUserid = [];
            $srRs = $DB->get_recordset('local_rtocompliance_students', null, '', 'id, userid');
            foreach ($srRs as $sr) {
                $existingByUserid[(int)$sr->userid] = true;
            }
            $srRs->close();

            // 4. Process each clientid that is missing from Student Records
            require_once($CFG->dirroot . '/user/lib.php');

            $toProcess = array_filter($allClientIds, function ($cid) use ($existingClientMap) {
                return !isset($existingClientMap[$cid]);
            });

            foreach ($toProcess as $lcCid) {
                $staging = $latestData[$lcCid] ?? null;
                if ($staging === null) {
                    $bfFailed[] = ['clientid' => $lcCid, 'name' => '', 'reason' => 'No staging data found'];
                    continue;
                }

                // Resolve name parts from NAT00085 first, fall back to NAT00080 full name
                $fnRaw = trim((string)($staging->firstname  ?? ''));
                $lnRaw = trim((string)($staging->familyname ?? ''));
                if ($fnRaw === '' && $lnRaw === '') {
                    $fullName = trim((string)($staging->name ?? $lcCid));
                    $sp = strpos($fullName, ' ');
                    if ($sp !== false) {
                        $fnRaw = substr($fullName, 0, $sp);
                        $lnRaw = substr($fullName, $sp + 1);
                    } else {
                        $fnRaw = $fullName;
                        $lnRaw = '-';
                    }
                }
                $firstname = ($fnRaw !== '') ? $fnRaw : $lcCid;
                $lastname  = ($lnRaw !== '') ? $lnRaw  : '-';

                // Resolve email
                $emailRaw = strtolower(trim((string)($staging->email ?? '')));
                $useEmail = ($emailRaw !== '') ? $emailRaw : ($lcCid . '@no-email.placeholder');

                // Find existing Moodle user
                $userid = false;
                if (isset($moodleByIdnumber[$lcCid])) {
                    $userid = $moodleByIdnumber[$lcCid];
                    $bfMatchedUser++;
                } elseif (isset($moodleByUsername[$lcCid])) {
                    $userid = $moodleByUsername[$lcCid];
                    $bfMatchedUser++;
                } elseif ($emailRaw !== '' && isset($moodleByEmail[$emailRaw])) {
                    $userid = $moodleByEmail[$emailRaw];
                    $bfMatchedUser++;
                    // BUG-FIX-IDNUMBER-UPDATE (v5.9.76): when a pre-existing Moodle account is
                    // found via email (not idnumber/username) its idnumber field is typically
                    // blank (self-registered or manually created by admin). Without idnumber,
                    // Fix Over-Enrolments Path A never fires for this student. Fix: set idnumber
                    // to the NAT clientid so Path A works immediately after backfill runs.
                    if (!isset($moodleByIdnumber[$lcCid])) {
                        try {
                            $DB->set_field('user', 'idnumber', trim((string)$staging->clientid), ['id' => (int)$userid, 'deleted' => 0]);
                            $moodleByIdnumber[$lcCid] = (int)$userid;
                        } catch (Exception $eIdnum) {
                            // Non-fatal — Path B (clientid in student profile) will still work.
                            debugging('rtocompliance backfill: idnumber update failed for userid='
                                . $userid . ': ' . $eIdnum->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                } elseif (isset($moodleByEmail[$useEmail])) {
                    $userid = $moodleByEmail[$useEmail];
                    $bfMatchedUser++;
                    if (!isset($moodleByIdnumber[$lcCid])) {
                        try {
                            $DB->set_field('user', 'idnumber', trim((string)$staging->clientid), ['id' => (int)$userid, 'deleted' => 0]);
                            $moodleByIdnumber[$lcCid] = (int)$userid;
                        } catch (Exception $eIdnum2) {
                            debugging('rtocompliance backfill: idnumber update failed (2) for userid='
                                . $userid . ': ' . $eIdnum2->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                }

                // ACCOUNT-CREATION REMOVED (v5.9.392): the plugin no longer creates
                // Moodle user accounts. If no existing Moodle account matches this
                // student (by client-id/idnumber, username or email), the student is
                // SKIPPED and reported — an administrator must create the Moodle user
                // account first, then re-run Backfill to attach and populate the
                // student's profile. This keeps the plugin from writing to Moodle's
                // core {user} table (it only reads it and writes its own tables).
                if ($userid === false) {
                    $bfNoAccount++;
                    $bfFailed[] = [
                        'clientid' => $lcCid,
                        'name'     => $firstname . ' ' . $lastname,
                        'reason'   => 'No Moodle account for this student — an administrator must create the '
                            . 'user account first, then re-run Backfill to create their profile.',
                    ];
                    continue;
                }

                // Skip if a Student Record already exists for this userid.
                // BUG-FIX-CLIENTID-UPDATE (v5.9.76): previously skipped unconditionally when a
                // student record existed. This meant a student who self-registered and got a
                // Student Record (with blank clientid) was forever invisible to Fix Over-Enrolments
                // Path B, because the clientid was never written. Fix: if the existing record has
                // a blank or different clientid, update it to the NAT clientid before skipping.
                if (isset($existingByUserid[(int)$userid])) {
                    try {
                        $existSR = $DB->get_record('local_rtocompliance_students',
                            ['userid' => (int)$userid], 'id, clientid', IGNORE_MISSING);
                        if ($existSR) {
                            $existCid = strtolower(trim((string)($existSR->clientid ?? '')));
                            if ($existCid === '' || $existCid !== $lcCid) {
                                $DB->set_field('local_rtocompliance_students', 'clientid',
                                    trim((string)$staging->clientid), ['id' => $existSR->id]);
                                $DB->set_field('local_rtocompliance_students', 'timemodified',
                                    time(), ['id' => $existSR->id]);
                            }
                        }
                    } catch (Exception $eCid) {
                        debugging('rtocompliance backfill: clientid update failed for userid='
                            . $userid . ': ' . $eCid->getMessage(), DEBUG_DEVELOPER);
                    }
                    $bfSkipped++;
                    continue;
                }
                $existingByUserid[(int)$userid] = true;

                // Parse DOB: DDMMYYYY → Unix timestamp
                $dobTs  = null;
                $dobStr = trim((string)($staging->dob ?? ''));
                if (strlen($dobStr) === 8 && ctype_digit($dobStr)) {
                    $dd = (int)substr($dobStr, 0, 2);
                    $mm = (int)substr($dobStr, 2, 2);
                    $yy = (int)substr($dobStr, 4, 4);
                    if ($dd >= 1 && $dd <= 31 && $mm >= 1 && $mm <= 12 && $yy >= 1900) {
                        $dobTs = mktime(0, 0, 0, $mm, $dd, $yy) ?: null;
                    }
                }

                $sexVal   = strtoupper(trim((string)($staging->sex   ?? '')));
                if (!in_array($sexVal, ['M', 'F', 'X', '@'], true)) $sexVal = '@';
                $usiVal   = strtoupper(trim((string)($staging->usi   ?? '')));
                $stateVal = trim((string)($staging->state ?? ''));
                $indVal   = trim((string)($staging->indigenousstatus  ?? '@'));
                $labVal   = trim((string)($staging->labourforcestatus ?? '@@'));
                $schlVal  = trim((string)($staging->highestschoollevel ?? '@@'));
                $suburbVal = trim((string)($staging->suburb ?? ''));

                try {
                    $rec                      = new \stdClass();
                    $rec->userid              = (int)$userid;
                    $rec->clientid            = trim((string)$staging->clientid);
                    $rec->usi                 = $usiVal;
                    $rec->usiverified         = 0;
                    $rec->firstname           = $firstname;
                    $rec->lastname            = $lastname;
                    $rec->dateofbirth         = $dobTs;
                    $rec->sex                 = $sexVal;
                    $rec->indigenousstatus    = ($indVal  !== '') ? $indVal  : '@';
                    $rec->countryofbirth      = ($staging && !empty($staging->countryofbirth)     && $staging->countryofbirth     !== '@@@@') ? $staging->countryofbirth     : '1101';
                    $rec->languageathome      = ($staging && !empty($staging->languageathome)     && !preg_match('/^[@\s]+$/', (string)$staging->languageathome)) ? $staging->languageathome : '1201';
                    $rec->englishproficiency  = '@';  // Removed from AVETMISS Release 8; cannot be read from NAT files.
                    $rec->disabilityflag      = ($staging && !empty($staging->disabilityflag)     && $staging->disabilityflag     !== '@') ? $staging->disabilityflag     : 'N';
                    $rec->highestschoollevel  = ($schlVal !== '') ? $schlVal : '@@';
                    $rec->labourforcestatus   = ($labVal  !== '') ? $labVal  : '@@';
                    $rec->studyreason         = '@@';
                    $rec->prioreducationflag  = ($staging && !empty($staging->prioreducationflag) && $staging->prioreducationflag !== '@') ? $staging->prioreducationflag : '@';
                    $rec->surveycontactstatus = 'N';
                    $rec->atschoolflag        = ($staging && !empty($staging->atschoolflag)       && in_array($staging->atschoolflag, ['Y','N'], true)) ? $staging->atschoolflag : 'N';
                    $rec->suburb              = ($suburbVal !== '') ? $suburbVal : null;
                    $rec->statecode           = ($stateVal  !== '') ? $stateVal  : null;
                    // ADDRESS (v5.9.396): street address from NAT00085 staging.
                    $rec->postcode            = ($staging && !empty($staging->postcode))     ? trim((string)$staging->postcode)     : null;
                    $rec->buildingname        = ($staging && !empty($staging->buildingname)) ? trim((string)$staging->buildingname) : null;
                    $rec->unitno              = ($staging && !empty($staging->unitno))       ? trim((string)$staging->unitno)       : null;
                    $rec->streetname          = ($staging && !empty($staging->streetname))   ? trim((string)$staging->streetname)   : null;
                    $rec->profilecomplete     = 0;
                    $rec->timecreated         = time();
                    $rec->timemodified        = time();
                    $DB->insert_record('local_rtocompliance_students', $rec);
                    $bfCreatedRecord++;
                } catch (Exception $esr) {
                    $bfFailed[] = ['clientid' => $lcCid,
                        'name'   => $firstname . ' ' . $lastname,
                        'reason' => 'Cannot create Student Record: ' . $esr->getMessage()];
                }
            }

        } catch (Exception $eBig) {
            echo '<div class="alert alert-danger"><strong>Backfill error:</strong> '
                . htmlspecialchars($eBig->getMessage()) . '</div>';
        }

        // Results summary
        echo '<div class="card mb-4" style="max-width:700px"><div class="card-body">';
        echo '<h5 class="card-title mb-3">Backfill Complete</h5>';
        echo '<table class="table table-sm mb-3">';
        echo '<tr class="table-success"><td>Student Records created</td>'
            . '<td class="text-right"><strong>' . number_format($bfCreatedRecord) . '</strong></td></tr>';
        echo '<tr><td>Moodle accounts matched (existing)</td>'
            . '<td class="text-right"><strong>' . number_format($bfMatchedUser) . '</strong></td></tr>';
        echo '<tr class="table-warning"><td>Skipped — no Moodle account (admin must create one first)</td>'
            . '<td class="text-right"><strong>' . number_format($bfNoAccount) . '</strong></td></tr>';
        echo '<tr><td>Already had Student Record (skipped)</td>'
            . '<td class="text-right"><strong>' . number_format($bfSkipped) . '</strong></td></tr>';
        if (count($bfFailed) > 0) {
            echo '<tr class="table-danger"><td>Failed</td>'
                . '<td class="text-right"><strong>' . number_format(count($bfFailed)) . '</strong></td></tr>';
        }
        echo '</table>';
        echo '<div class="alert alert-success mb-3">Done. Run '
            . '<a href="' . $verifyUrl->out(false) . '">Verify NAT Data</a>'
            . ' again to confirm all students now appear in Student Records.</div>';
        echo html_writer::link($verifyUrl, '&larr; Back to Verify NAT', ['class' => 'btn btn-secondary']);

        if (count($bfFailed) > 0) {
            echo '<h6 class="mt-4">Failed students (' . count($bfFailed) . '):</h6>';
            echo '<div style="max-height:250px;overflow-y:auto"><table class="table table-sm table-bordered">';
            echo '<thead><tr><th title="AVETMISS client identifier">Client ID</th><th title="Student name">Name</th><th title="Why the backfill failed for this student">Reason</th></tr></thead><tbody>';
            foreach (array_slice($bfFailed, 0, 200) as $fail) {
                echo '<tr><td>' . htmlspecialchars($fail['clientid'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($fail['name'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($fail['reason']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }

    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

// ─── Shared save-to-DB helper ─────────────────────────────────────────────────

function local_rtocompliance_save_nat_groups(array $groups, array $usiOverrides = []): array {
    global $DB;
    raise_memory_limit(MEMORY_HUGE); // BUG-MEMORY-NATIMPORT (v4.9.168)
    $importcount  = 0;
    $lastimportid = 0;
    // AVETMISS-ROUNDTRIP (v5.9.460): running totals of NAT00090/00100 detail applied.
    $natdetail = ['disability' => 0, 'priored' => 0, 'unmatched' => 0];

    foreach ($groups as $idx => $groupfiles) {
        $override = $usiOverrides[$idx] ?? -1;
        $data     = local_rtocompliance_parse_nat_group($groupfiles, (int)$override);
        if (empty($data['students']) && empty($data['enrolments']) && empty($data['completions'])) {
            continue;
        }

        $flagged = 0;
        foreach ($data['students'] as $s) {
            if (!empty($s['hasdataissues'])) $flagged++;
        }

        $importrec = (object)[
            'rtoid'           => $data['rtoid'] ?: null,
            'rtoname'         => $data['rtoname'],
            'collectionyear'  => $data['collectionyear'],
            'filesprocessed'  => json_encode($data['filesprocessed']),
            'totalstudents'   => count($data['students']),
            'totalenrolments' => count($data['enrolments']),
            'totalcompletions'=> count($data['completions']),
            'flaggedrecords'  => $flagged,
            'timecreated'     => time(),
        ];
        // FIX-IMPORT-TRANSACTION (v4.9.131): Wrap every group's inserts in a
        // delegated transaction.  Without this, a DB timeout or server crash
        // mid-loop left a header record showing e.g. "2400 students" while only
        // 150 rows were actually written.  Also guard against the header insert
        // returning false (DB error) — in that case all child inserts would use
        // importid = 0, silently contaminating the data.
        $transaction = $DB->start_delegated_transaction();
        $importid_new = $DB->insert_record('local_rtocompliance_avetmiss', $importrec);
        if (!$importid_new) {
            $transaction->rollback(new \dml_exception('Insert of import header failed'));
            continue;
        }
        $lastimportid = $importid_new;

        foreach ($data['students'] as $s) {
            $rec = (object)[
                'importid'           => $importid_new,
                'clientid'           => $s['clientid'],
                'name'               => $s['name']              ?? '',
                'firstname'          => $s['firstname']         ?? null,
                'familyname'         => $s['familyname']        ?? null,
                'email'              => $s['email']             ?? null,
                'phone'              => $s['phone']             ?? null,
                'dob'                => $s['dob']               ?? null,
                'sex'                => $s['sex']               ?? null,
                'usi'                => $s['usi']               ?? null,
                'suburb'             => $s['suburb']            ?? null,
                'state'              => $s['state']             ?? null,
                'postcode'           => $s['postcode']          ?? null,
                'buildingname'       => $s['buildingname']      ?? null,
                'unitno'             => $s['unitno']            ?? null,
                'streetname'         => $s['streetname']        ?? null,
                'indigenousstatus'   => $s['indigenousstatus']   ?? null,
                'labourforcestatus'  => $s['labourforcestatus']  ?? null,
                'highestschoollevel' => $s['highestschoollevel']  ?? null,
                'languageathome'     => $s['languageathome']      ?? null,
                'countryofbirth'     => $s['countryofbirth']      ?? null,
                'disabilityflag'     => $s['disabilityflag']      ?? null,
                'prioreducationflag' => $s['prioreducationflag']  ?? null,
                'atschoolflag'       => $s['atschoolflag']        ?? null,
                'hasdataissues'      => (int)($s['hasdataissues'] ?? 0),
                'dataissuefields'    => $s['dataissuefields']   ?? '[]',
            ];
            $DB->insert_record('local_rtocompliance_avetmiss_student', $rec);
        }
        foreach ($data['enrolments'] as $e) {
            // AUTO-ASSIGN-70 (v5.9.63): If outcome is blank/null/00 — common in Wisenet
            // exports that don't set an explicit outcome for active students — infer
            // '70' = Continuing Enrolment when the unit's end date has not yet passed.
            // If end date is in the past the record is historical → leave outcome null.
            // ChatGPT rule: no final outcome + end date in future (or blank) = in progress.
            // Course visibility is checked separately at enrol/unenrol time — a student
            // with outcome 70 in a hidden course is still flagged for removal.
            $_rawOutcome = trim((string)($e['outcome'] ?? ''));
            if ($_rawOutcome === '' || $_rawOutcome === '00') {
                $_endDate = trim((string)($e['enddate'] ?? ''));
                if (strlen($_endDate) === 8 && ctype_digit($_endDate)) {
                    // DDMMYYYY → compare to today
                    $_ed_ts = mktime(0, 0, 0,
                        (int)substr($_endDate, 2, 2),  // month
                        (int)substr($_endDate, 0, 2),  // day
                        (int)substr($_endDate, 4, 4)   // year
                    );
                    $_rawOutcome = ($_ed_ts < time()) ? '' : '70'; // past = historical, future = continuing
                } else {
                    $_rawOutcome = '70'; // no end date → assume in progress
                }
            }
            $rec = (object)[
                'importid'       => $importid_new,
                'clientid'       => $e['clientid'],
                'unitcode'       => $e['unitcode']       ?? '',
                'qualcode'       => $e['qualcode']       ?? '',
                'startdate'      => $e['startdate']      ?? null,
                'enddate'        => $e['enddate']        ?? null,
                'outcome'        => $_rawOutcome !== '' ? $_rawOutcome : null,
                'fundingsource'  => $e['fundingsource']  ?? null,
                'studyreason'    => $e['studyreason']    ?? null,
                'supervisedhours'=> $e['supervisedhours'] ?? null,
            ];
            $DB->insert_record('local_rtocompliance_avetmiss_enrolment', $rec);
        }
        foreach ($data['completions'] as $c) {
            $rec = (object)[
                'importid'             => $importid_new,
                'clientid'             => $c['clientid'],
                'qualcode'             => $c['qualcode']             ?? '',
                'completiondate'       => $c['completiondate']       ?? null,
                'successfulcompletion' => $c['successfulcompletion'] ?? null,
                'certificatedate'      => $c['certificatedate']      ?? null,
                'parchmentnumber'      => $c['parchmentnumber']      ?? null,
            ];
            $DB->insert_record('local_rtocompliance_avetmiss_completion', $rec);
        }
        // NAT00030 programme records (qual names + VET flag) — stored when NAT00030 is included in the upload.
        foreach ($data['programmes'] ?? [] as $p) {
            try {
                $DB->insert_record('local_rtocompliance_avetmiss_programme', (object)[
                    'importid'  => $importid_new,
                    'qualcode'  => $p['qualcode']  ?? '',
                    'qualname'  => $p['qualname']  ?? '',
                    'isvetprog' => $p['isvetprog'] ?? null,
                ]);
            } catch (Exception $e) { /* table may not exist on older DB — safe to skip */ }
        }
        $transaction->allow_commit();
        $importcount++;

        // AVETMISS-ROUNDTRIP (v5.9.460): apply NAT00090 (disability) and NAT00100 (prior
        // education) DETAIL to matching students in the live register. Runs after the
        // group commits; updates existing students only (never creates them), so the
        // detail that NAT00090/00100 export — and that the AVETMISS validator checks —
        // is no longer silently dropped on re-import.
        if (!empty($data['disabilitydetail']) || !empty($data['prioreddetail'])) {
            $dr = local_rtocompliance_apply_nat_detail_to_students(
                $data['disabilitydetail'] ?? [], $data['prioreddetail'] ?? []);
            $natdetail['disability'] += $dr['disability'];
            $natdetail['priored']    += $dr['priored'];
            $natdetail['unmatched']  += $dr['unmatched'];
        }
    }
    return ['count' => $importcount, 'lastid' => $lastimportid, 'natdetail' => $natdetail];
}

// ─── Handle file upload — Step 1: read files, detect format, show confirmation ─

$pendingConfirm = false;   // flag to render the confirmation UI instead of the list

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '' && confirm_sesskey()) {
    raise_memory_limit(MEMORY_HUGE); // BUG-MEMORY-NATIMPORT (v4.9.168): file read + normalise
    $uploaded = [];
    if (!empty($_FILES['natfiles']['name'])) {
        $names  = (array)$_FILES['natfiles']['name'];
        $tmps   = (array)$_FILES['natfiles']['tmp_name'];
        $errors = (array)$_FILES['natfiles']['error'];
        $sizes = (array)($_FILES['natfiles']['size'] ?? []);
        foreach ($names as $i => $name) {
            if ($errors[$i] !== UPLOAD_ERR_OK) continue;
            if (!preg_match('/NAT\d+.*\.txt$/i', $name)) continue;
            // FIX-UPLOAD-SIZELIMIT (v4.9.130): Reject files larger than 50 MB before reading
            // into memory.  No valid AVETMISS NAT file exceeds this; anything larger is almost
            // certainly an upload mistake or an attempt to exhaust PHP memory.
            if (isset($sizes[$i]) && (int)$sizes[$i] > 52428800) continue;
            $content = file_get_contents($tmps[$i]);
            if ($content === false) continue;
            // FIX-LINEENDING-BOM (v4.9.130): Normalise line endings and strip UTF-8 BOM once
            // at read time so every downstream parser (NAT00080, 00085, 00120, 00130) receives
            // clean \n-delimited content regardless of the exporting SMS vendor or OS.
            // (1) Bare \r (old Mac) line endings silently produced one giant "line" and only
            //     the first student record was ever parsed — a silent 99%+ data-loss bug.
            // (2) A UTF-8 BOM (EF BB BF) at the start of the file shifted every field
            //     position in the first record by 3 bytes, producing garbage or null values
            //     for NAT00085, NAT00120 and NAT00130 (only NAT00080 stripped BOM per-line).
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $content = substr($content, 3);
            }
            $content = str_replace(["\r\n", "\r"], "\n", $content);
            $uploaded[] = ['name' => $name, 'content' => $content];
        }
    }

    if (empty($uploaded)) {
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php'),
            get_string('dataimport_no_data', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Detect the USI column position for each batch group.
    // FIX-NATUPLOAD-SESSION-SIZE (v4.9.144): Storing full raw file content in $SESSION
    // caused HTTP 500 on real NAT exports (1–3 MB) because Moodle serialises the session
    // object into the DB; if the serialised blob exceeds MySQL max_allowed_packet the DB
    // write fails → error page.  Fix: write each uploaded file to a temp directory and
    // store only on-disk paths in the session (a few hundred bytes).
    // FIX-NATUPLOAD-TMPDIR (v4.9.145): make_temp_directory() depends on $CFG->tempdir
    // permissions/quota and can itself throw a moodle_exception.  Use PHP's native
    // sys_get_temp_dir() (/tmp on Linux) which is always writable by the web server.
    // Fall back to session storage if even that fails.
    $groups    = local_rtocompliance_group_by_timestamp($uploaded);
    $natTmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'rtoc_nat_' . (int)$USER->id . '_' . time() . '_' . mt_rand(1000, 9999);
    if (!@mkdir($natTmpDir, 0700, true) && !is_dir($natTmpDir)) {
        $natTmpDir = ''; // temp-dir creation failed — fall back to session storage
    }
    $useTmpFiles = ($natTmpDir !== '');
    $pending = ['expires' => time() + 1800, 'tmpdir' => $natTmpDir, 'groups' => []];
    foreach ($groups as $idx => $groupfiles) {
        // FIX-NAT00080-NAT130-FALLBACK (v4.9.122): Some SMS vendors export student data
        // in a file named NAT00130 instead of the standard NAT00080.  Accept NAT00130
        // as a fallback source of student records when no NAT00080 file is present.
        $nat80content = '';
        $nat80fallback = '';
        foreach ($groupfiles as $f) {
            $ftype = local_rtocompliance_get_nat_type($f['name']);
            if ($ftype === 'NAT00080') {
                $nat80content = $f['content'];
                break;
            }
            if ($ftype === 'NAT00130' && $nat80fallback === '') {
                $nat80fallback = $f['content'];
            }
        }
        if ($nat80content === '' && $nat80fallback !== '') {
            $nat80content = $nat80fallback;
        }
        $sampleLines = [];
        if ($nat80content !== '') {
            // Content is pre-normalised to \n at upload time (v4.9.130). Defensive pattern kept.
            $allLines    = preg_split('/\r\n|\r|\n/', $nat80content);
            $sampleLines = array_values(array_filter(
                array_slice($allLines, 0, 100),
                fn($l) => trim($l) !== ''
            ));
        }
        $detected = ($nat80content !== '')
            ? local_rtocompliance_detect_nat00080_usi_pos($sampleLines)
            : -1;

        // Write each file to the temp directory (paths-only session); fall back to
        // inline content if the temp directory isn't available.
        $sessFiles    = [];
        $nat80tmppath = '';
        if ($useTmpFiles) {
            foreach ($groupfiles as $gf) {
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $gf['name']);
                $tmpPath  = $natTmpDir . DIRECTORY_SEPARATOR . $idx . '_' . $safeName;
                @file_put_contents($tmpPath, $gf['content']);
                $sessFiles[] = ['name' => $gf['name'], 'tmppath' => $tmpPath];
            }
            if ($nat80content !== '') {
                $nat80tmppath = $natTmpDir . DIRECTORY_SEPARATOR . 'nat80_' . $idx . '.txt';
                @file_put_contents($nat80tmppath, $nat80content);
            }
        } else {
            // Temp dir unavailable — store content inline (original behaviour).
            // This may exceed session size on large exports but is a safe fallback.
            foreach ($groupfiles as $gf) {
                $sessFiles[] = ['name' => $gf['name'], 'content' => $gf['content']];
            }
        }

        $pending['groups'][] = [
            'files'          => $sessFiles,
            'nat80tmppath'   => $nat80tmppath,
            'nat80content'   => $useTmpFiles ? '' : $nat80content, // inline fallback only
            'usiposdetected' => $detected,
            'usiposoverride' => -1,
        ];
    }
    $SESSION->rtocompliance_nat_pending = $pending;
    // MATCH-METHOD: validate and store chosen match method so it survives
    // the redirect chain all the way to the doenrol action.
    $SESSION->rtocompliance_nat_match_method =
        (optional_param('match_method', 'email', PARAM_ALPHA) === 'studentid')
            ? 'studentid' : 'email';
    $pendingConfirm = true;
}

// ─── Step 1b: user adjusts USI position and requests re-preview ───────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'previewnat' && confirm_sesskey()) {
    if (!empty($SESSION->rtocompliance_nat_pending)
            && time() < ($SESSION->rtocompliance_nat_pending['expires'] ?? 0)) {
        foreach ($SESSION->rtocompliance_nat_pending['groups'] as $idx => &$grp) {
            $rawOverride = optional_param('usipos_' . $idx, -2, PARAM_INT);
            $grp['usiposoverride'] = ($rawOverride >= 0) ? $rawOverride : -1;
        }
        unset($grp);
        $SESSION->rtocompliance_nat_pending['expires'] = time() + 1800;
        $pendingConfirm = true;
    } else {
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php'),
            get_string('dataimport_session_expired', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

// ─── Step 2: user confirms — save to DB ───────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'finalizenat' && confirm_sesskey()) {
    raise_memory_limit(MEMORY_HUGE); // BUG-MEMORY-NATIMPORT (v4.9.168)
    if (empty($SESSION->rtocompliance_nat_pending)
            || time() >= ($SESSION->rtocompliance_nat_pending['expires'] ?? 0)) {
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php'),
            get_string('dataimport_session_expired', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $pending  = $SESSION->rtocompliance_nat_pending;
    $groups   = array_column($pending['groups'], 'files');
    $overrides = [];
    foreach ($pending['groups'] as $idx => $grp) {
        $rawOverride = optional_param('usipos_' . $idx, -2, PARAM_INT);
        if ($rawOverride >= 0) {
            $overrides[$idx] = $rawOverride;
        } else {
            $eff = ($grp['usiposoverride'] >= 0)
                ? $grp['usiposoverride']
                : $grp['usiposdetected'];
            $overrides[$idx] = $eff;
        }
    }

    // Clear session data before releasing the session lock so that concurrent
    // browser tabs are not held waiting during the bulk DB inserts below.
    // MATCH-METHOD: re-read from POST (passed as hidden field from Step 2 form)
    // before clearing session; restore to session so autoenrol wizard can read it.
    $matchMethodCarry = (optional_param('match_method', 'email', PARAM_ALPHA) === 'studentid')
        ? 'studentid' : 'email';
    // Fallback: if POST has no match_method, use whatever was stored at upload time.
    if (optional_param('match_method', '', PARAM_ALPHA) === '') {
        $matchMethodCarry = ($SESSION->rtocompliance_nat_match_method ?? 'email') === 'studentid'
            ? 'studentid' : 'email';
    }
    unset($SESSION->rtocompliance_nat_pending);
    $SESSION->rtocompliance_nat_match_method = $matchMethodCarry;
    \core\session\manager::write_close();

    $result = local_rtocompliance_save_nat_groups($groups, $overrides);

    // FIX-NATUPLOAD-SESSION-SIZE (v4.9.144): Clean up temp NAT files after successful import.
    if (!empty($pending['tmpdir']) && is_dir($pending['tmpdir'])) {
        $tmpFiles = glob($pending['tmpdir'] . '/*') ?: [];
        array_map('unlink', $tmpFiles);
        @rmdir($pending['tmpdir']);
    }

    if ($result['count'] === 0) {
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php'),
            get_string('dataimport_no_data', 'local_rtocompliance'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // v5.9.371 NAT-RESULTS-REGISTER: populate the plugin's own results register
    // (local_rtocompliance_enrolments) directly from the just-staged NAT enrolments,
    // for students already in the system. This writes ONLY plugin tables — it creates
    // and deletes NO Moodle accounts, enrolments or completions. Students with no
    // matching record are surfaced via the "review unmatched" export on the detail view.
    require_once(__DIR__ . '/classes/results_importer.php');
    $regsummary = '';
    try {
        $reg = \local_rtocompliance\results_importer::populate_from_import((int) $result['lastid']);
        $regsummary = ' Results register: ' . (int) $reg['written'] . ' new, ' . (int) $reg['updated']
            . ' updated outcome row(s) for ' . (int) $reg['students'] . ' matched student(s).';
        if (!empty($reg['unmatched'])) {
            $regsummary .= ' ' . count($reg['unmatched'])
                . ' student(s) in the file are not in your system yet — use "Download unmatched students" on the import to review them.';
        }
    } catch (\Throwable $e) {
        debugging('NAT results register population failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $regsummary = ' (Note: the results register could not be populated automatically — ' . s($e->getMessage()) . ')';
    }

    // DEMOG-PROPAGATION (v5.9.391): automatically refresh the AVETMISS demographic
    // fields on EXISTING student profiles from the freshly-staged NAT data. This
    // writes ONLY to the plugin's students table (no Moodle user/enrolment/completion
    // writes), so it is safe to run on every import. New students without a profile
    // yet are handled by the deliberate "Backfill Student Records" step.
    $demogsummary = '';
    try {
        $demogupdated = local_rtocompliance_sync_student_demographics_from_staging();
        if ($demogupdated > 0) {
            $demogsummary = ' Demographics: refreshed the AVETMISS profile data for '
                . $demogupdated . ' existing student(s).';
        }
    } catch (\Throwable $e) {
        debugging('NAT demographic propagation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // AVETMISS-ROUNDTRIP (v5.9.460): summarise NAT00090/00100 detail applied to students.
    $detailsummary = '';
    if (!empty($result['natdetail'])) {
        $nd = $result['natdetail'];
        if ((int) $nd['disability'] > 0 || (int) $nd['priored'] > 0 || (int) $nd['unmatched'] > 0) {
            $detailsummary = ' Disability / prior-education detail (NAT00090/00100): applied to '
                . (int) $nd['disability'] . ' disability and ' . (int) $nd['priored']
                . ' prior-education record(s)'
                . ((int) $nd['unmatched'] > 0
                    ? ', ' . (int) $nd['unmatched'] . ' client(s) not yet in the register '
                        . '(re-import the NAT00090/00100 file once their student records exist)'
                    : '')
                . '.';
        }
    }

    // v5.9.371: go straight to the import detail view (Students / Enrolments / Quality
    // tabs). The old auto-enrol wizard redirect is retired — the import no longer pushes
    // students into Moodle; it populates the plugin's results register instead.
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $result['lastid']]),
        get_string('dataimport_success', 'local_rtocompliance', $result['count']) . $regsummary . $demogsummary . $detailsummary,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── Step 3b: Process auto-enrolments (POST doenrol) ─────────────────────────
// Called when the wizard form is submitted.  For each qual-code → course-id
// mapping the admin has chosen, find matching Moodle users (via email from the
// NAT00085 contact records) and enrol them using the manual enrolment plugin.
// Students whose client record has no email, or whose email does not match any
// active Moodle account, are silently skipped and reported in the redirect.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'doenrol' && $importid && confirm_sesskey()) {
    // READ-ONLY MODE: enrolment write operations have been disabled.
    // All enrolment changes must be handled directly at the database level by the developer.
    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]),
        'Enrolment write operations are disabled. Please contact your developer to apply changes directly.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
    require_once($CFG->libdir . '/enrollib.php');

    // NOTE (v4.9.175): write_close() was previously called here BEFORE the enrolment
    // loop.  This caused ALL session writes that follow (skip report, diagLog, etc.)
    // to be silently lost — the session is closed and PHP ignores further writes.
    // The admin would always see an empty skip report regardless of what happened.
    // Fix: session stays open throughout the handler so results are persisted.
    // The session lock is held for a few seconds at most (the loop is O(1) in-memory
    // lookups thanks to pre-fetch), which is acceptable.

    // qualcodes[], categories[], and yearsems[] are parallel arrays submitted by the wizard form.
    // FIX-AUTOENROL-CATEGORIES (v4.9.179): in this RTO's Moodle, each qualification is a
    // Moodle *category* and each unit of competency is a *course* inside that category.
    // The wizard now collects a category ID per qual code. During enrolment, every visible
    // course (unit) inside the selected category is processed — students are enrolled into
    // all of them at once.
    // SPLIT-BY-YEAR-SEM (v5.2.63): yearsems[] carries the year/semester for each card (e.g.
    // "2015 S1"). When present, only students whose NAT00120 startdate falls within that
    // year/semester are enrolled — allowing ABC12345 2015 S1 and ABC12345 2016 S2 to map to
    // different Moodle categories independently.
    $qualcodes   = optional_param_array('qualcodes',   [], PARAM_TEXT);
    $categoryids = optional_param_array('categories',  [], PARAM_INT);
    $yearsems    = optional_param_array('yearsems',    [], PARAM_TEXT);

    // ENROL-UNIT-ACCURATE (v5.9.57): when checked, only enrol each student into Moodle
    // courses whose idnumber matches one of their NAT00120 unit codes.  Courses with no
    // idnumber configured are enrolled unconditionally (backward-compatible fallback).
    $targetedEnrol = (bool)optional_param('targeted_enrol', 0, PARAM_INT);

    $enrolplugin   = enrol_get_plugin('manual');

    // Resolve the student role ID — try 'student' shortname first, then fall back
    // to any role with the student archetype in case the site has renamed it.
    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
    if (!$studentroleid) {
        $archetypes    = get_archetype_roles('student');
        $studentroleid = $archetypes ? (int)reset($archetypes)->id : 0;
    }

    // Guard: if the manual enrolment plugin is unavailable OR no student role
    // exists on this site, redirect immediately with a clear error message.
    if (!$enrolplugin || !$studentroleid) {
        $guardstr = !$enrolplugin
            ? get_string('autoenrol_fail_noplugin', 'local_rtocompliance')
            : get_string('autoenrol_fail_norole',   'local_rtocompliance');
        redirect(
            new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]),
            $guardstr,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // FIX-AUTOENROL-NPLUS1 (v4.9.133): The original loop ran one get_record() per student to
    // fetch their email, one get_records_sql(LOWER(email)=?) per student to look up the Moodle
    // user, and one is_enrolled() per student (which is itself a JOIN query). For a 3000-student
    // import with 5 qual codes that is up to 9000 individual DB round-trips — PHP's
    // max_execution_time (60–300 s on typical Moodle installs) kills the request silently,
    // leaving the admin staring at a blank page with zero enrolments done.
    //
    // Fix: three pre-fetch queries replace all of that:
    //   (a) One query loads every student's email for this import into a clientid→email map.
    //   (b) One query loads every active Moodle user into a lowercase-email→userid map.
    //   (c) One query per qual code loads all currently-enrolled user IDs for the target course.
    //
    // All inner-loop lookups are now O(1) in-memory hash lookups with zero DB queries.

    global $SESSION, $CFG;

    // MATCH-METHOD (v4.9.159): Two strategies for linking NAT file students to
    // Moodle accounts. The choice was made by the admin at upload time and
    // passed through as a hidden form field.
    //   'email'     — match by email address (from NAT00085). Requires NAT00085.
    //   'studentid' — match by Client ID → Moodle username. No NAT00085 needed.
    $matchMethod = optional_param('match_method', 'email', PARAM_ALPHA);
    if (!in_array($matchMethod, ['email', 'studentid'], true)) {
        $matchMethod = 'email';
    }

    // Pre-load student details — name, email — needed for skip reports and
    // automatic account creation (v4.9.161: accounts created when no match found).
    $studentNameMap    = [];
    $studentDetailsMap = [];   // clientid → stdClass{firstname, familyname, email}
    // FIX-DOENROL-DUPKEY-2 (v5.2.8): get_records() uses the first selected column as the
    // PHP array key — selecting 'clientid, ...' from avetmiss_student (where duplicate
    // clientid rows can exist from malformed NAT files) triggers the Moodle duplicate-key
    // debug error.  Switched to get_recordset_select() (no uniqueness requirement).
    $sdRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        'importid = :importid',
        ['importid' => $importid],
        '',
        'clientid, firstname, familyname, email'
    );
    foreach ($sdRs as $sr) {
        $studentNameMap[$sr->clientid]    = trim(($sr->firstname ?? '') . ' ' . ($sr->familyname ?? ''));
        $studentDetailsMap[$sr->clientid] = $sr;
    }
    $sdRs->close();
    // FIX-AUTOENROL-CROSSIMPORT-NAMES (v4.9.180): NAT00080 and NAT00120 can land in
    // different import batches when file timestamps differ (the timestamp-grouping
    // window does not always cover every SMS vendor's export layout).  In that case
    // the loop above is empty even though real names exist in the DB under a different
    // importid.  Fix: do one extra query across ALL imports for any clientid that still
    // has no proper name — this covers both the "different batch" and "NAT00080 not
    // included at all in this run but uploaded previously" scenarios.
    $allEnrolClientids = array_unique($DB->get_fieldset_select(
        'local_rtocompliance_avetmiss_enrolment', 'clientid',
        'importid = :importid', ['importid' => $importid]
    ));
    if (!empty($allEnrolClientids)) {
        $needsName = [];
        foreach ($allEnrolClientids as $_cid) {
            $sd = $studentDetailsMap[$_cid] ?? null;
            if (!$sd || (empty($sd->firstname) && empty($sd->familyname))) {
                $needsName[] = $_cid;
            }
        }
        if (!empty($needsName)) {
            list($insql, $inparams) = $DB->get_in_or_equal($needsName, SQL_PARAMS_NAMED, 'cn');
            // FIX-DOENROL-DUPKEY-2 (v5.2.8): switched to get_recordset_sql() — clientid is
            // not unique across all imports so get_records_sql() would crash on duplicates.
            // ORDER BY id ASC so the last record for each clientid wins (most recent import).
            $cnRs = $DB->get_recordset_sql(
                "SELECT clientid, firstname, familyname, email
                   FROM {local_rtocompliance_avetmiss_student}
                  WHERE clientid $insql
                    AND (firstname IS NOT NULL OR familyname IS NOT NULL)
                  ORDER BY id ASC",
                $inparams
            );
            foreach ($cnRs as $sr) {
                $studentDetailsMap[$sr->clientid] = $sr;
                $studentNameMap[$sr->clientid] = trim(($sr->firstname ?? '') . ' ' . ($sr->familyname ?? ''));
            }
            $cnRs->close();
        }
    }

    $aeSkipped = [];   // per-student skip detail — stored in session for the results page
    $diagLog   = [];   // DIAG (v4.9.174): per-qual diagnostic counters for results page

    // FIX-AUTOENROL-MATCH-FALLBACK (v4.9.172): Previously each match method only built its
    // own lookup map and set the other to null.  In email mode $moodleUserByUsername was null,
    // so when email matching failed (changed email, old NAT data, etc.) the code jumped
    // straight to user_create_user() with username=clientid.  If that username already existed
    // (from a previous import or manual account creation) the call threw → caught as
    // 'createfailed' → 0 enrolments with no useful error shown to the admin.
    //
    // Fix: build ALL THREE lookup maps unconditionally.  The inner loop then does a two-step
    // match: primary (the admin-selected method), then fallback (the other method + idnumber/
    // username check).  user_create_user() is only attempted when every possible existing-account
    // path has been exhausted first, so username-conflict createfailed errors disappear entirely
    // for sites that already have student accounts.

    // Map 1: lowercase idnumber → userid
    // Map 2: lowercase username → userid
    $moodleUserByIdnumber = [];
    $moodleUserByUsername = [];
    foreach ($DB->get_records_sql(
        "SELECT id, LOWER(username) AS lc_username, LOWER(idnumber) AS lc_idnumber
           FROM {user}
          WHERE deleted = 0 AND suspended = 0"
    ) as $mu) {
        if ($mu->lc_idnumber !== '' && !isset($moodleUserByIdnumber[$mu->lc_idnumber])) {
            $moodleUserByIdnumber[$mu->lc_idnumber] = (int)$mu->id;
        }
        if (!isset($moodleUserByUsername[$mu->lc_username])) {
            $moodleUserByUsername[$mu->lc_username] = (int)$mu->id;
        }
    }

    // Map 3: lowercase email → userid (always built — used for email-mode primary match,
    // fallback match in studentid mode, and placeholder-email dedup during account creation).
    $moodleUserByEmail = [];
    foreach ($DB->get_records_sql(
        "SELECT id, LOWER(email) AS lc_email
           FROM {user}
          WHERE deleted = 0 AND suspended = 0 AND " . $DB->sql_isnotempty('user', 'email', false, false)
    ) as $mu) {
        if (!isset($moodleUserByEmail[$mu->lc_email])) {
            $moodleUserByEmail[$mu->lc_email] = (int)$mu->id;
        }
    }

    // Student email map (clientid → lowercased email from NAT00085).
    // Always built — needed for account creation regardless of match method.
    // FIX-DOENROL-DUPKEY-2 (v5.2.8): same get_records() → get_recordset_select() fix.
    $studentEmailMap = [];
    $seRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        'importid = :importid',
        ['importid' => $importid],
        '',
        'clientid, email'
    );
    foreach ($seRs as $sr) {
        $studentEmailMap[$sr->clientid] = strtolower(trim($sr->email ?? ''));
    }
    $seRs->close();

    $totalenrolled          = 0;
    $totalcreated           = 0;   // new Moodle accounts created automatically
    $totalmatched           = 0;   // matched by primary method (email or studentid)
    $totalmatched_fallback  = 0;   // matched by fallback method (cross-mode lookup)
    $totalskipnostudent     = 0;   // kept for compatibility — rarely triggered now
    $totalskipnoemail       = 0;   // kept for compatibility — rarely triggered now
    $totalskipnouser        = 0;   // account creation failed (username conflict etc.)
    $totalskipalready       = 0;
    $totalskipterminal      = 0;   // ENROL-CONTINUING-ONLY (v5.9.52): students with terminal outcomes (no '70' units)
    $enrolledQualcodes  = [];

    // FIX-USI-FROM-NAT: pre-load USI values from the NAT staging table so they can be
    // written directly to local_rtocompliance_students during auto-enrol.
    // Previously, process_enrolment_task created student profiles with usi='' and the
    // NAT USI data was never transferred, causing ALL students to show "Missing" in
    // Student Records even after a successful NAT import + auto-enrol run.
    // FIX-DOENROL-DUPKEY (v5.2.6): get_records_select() keys the result by the first
    // selected column — using 'clientid' crashes when the same clientid appears more than
    // once in the staging table (duplicate rows in source NAT file).  Switched to
    // get_recordset_select() which streams rows without imposing a uniqueness requirement.
    $clientidToUsi = [];
    $usiRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        "importid = :importid AND " . $DB->sql_isnotempty('local_rtocompliance_avetmiss_student', 'usi', false, false),
        ['importid' => $importid],
        '',
        'clientid, usi'
    );
    foreach ($usiRs as $sr) {
        // Keep first non-empty USI found for each clientid.
        if (!isset($clientidToUsi[$sr->clientid]) && $sr->usi !== '') {
            $clientidToUsi[$sr->clientid] = $sr->usi;
        }
    }
    $usiRs->close();

    // DOB-FROM-NAT: also load DOB values from the same staging table so we can
    // write them to local_rtocompliance_students.dateofbirth alongside the USI.
    $clientidToDob = [];
    $dobRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_student',
        "importid = :importid AND " . $DB->sql_isnotempty('local_rtocompliance_avetmiss_student', 'dob', false, false),
        ['importid' => $importid],
        '',
        'clientid, dob'
    );
    foreach ($dobRs as $dr) {
        if (!isset($clientidToDob[$dr->clientid]) && $dr->dob !== '') {
            $clientidToDob[$dr->clientid] = $dr->dob; // DDMMYYYY string
        }
    }
    $dobRs->close();

    $usiToWrite      = [];   // userid (int) => usi (string) — collected per matched student
    $dobToWrite      = [];   // userid (int) => dob string DDMMYYYY — collected per matched student
    $profilesEnsured = [];   // userid (int) => true — profiles created synchronously this run

    // NAT-FIX-5.2.4 (NAT-COMPLETION): Pre-load every NAT enrolment outcome for this
    // import so that, when a student is enrolled into a Moodle course, we can
    // immediately mark the course complete if the NAT file shows a competent outcome.
    // Structure: $natOutcomeMap[clientid][UNITCODE] = ['outcome' => '20', 'enddate' => 'DDMMYYYY']
    // FIX-DOENROL-DUPKEY (v5.2.6): also switched enrolment fetch to get_recordset_select()
    // because clientid is NOT unique in avetmiss_enrolment (one row per unit per student).
    $natOutcomeMap = [];
    $natEnrolRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_enrolment',
        'importid = :iid',
        ['iid' => $importid],
        '',
        'clientid, unitcode, outcome, enddate'
    );
    foreach ($natEnrolRs as $nr) {
        $uc = strtoupper(trim((string)$nr->unitcode));
        if ($uc === '' || $nr->outcome === null || $nr->outcome === '' || $nr->outcome === '00') continue;
        $natOutcomeMap[(string)$nr->clientid][$uc] = ['outcome' => $nr->outcome, 'enddate' => (string)($nr->enddate ?? '')];
    }
    $natEnrolRs->close();

    // ENROL-UNIT-ACCURATE (v5.9.57): Pre-load ALL unit codes per student (regardless of
    // outcome) so Phase 3 can skip Moodle courses this student has no NAT00120 record for.
    // Uses a separate query (not $natOutcomeMap) because outcome='00'/empty records are
    // excluded from $natOutcomeMap but still represent real enrolments that should receive
    // Moodle access.  Only built when targeted_enrol is checked to avoid wasted memory on
    // large imports where the admin wants the old "enrol into everything" behaviour.
    $allStudentUnits = [];  // clientid → [UNITCODE => true]
    if ($targetedEnrol) {
        $allUnitsRs = $DB->get_recordset_select(
            'local_rtocompliance_avetmiss_enrolment',
            'importid = :asuiid',
            ['asuiid' => $importid],
            '',
            'clientid, unitcode'
        );
        foreach ($allUnitsRs as $aur) {
            $auc = strtoupper(trim((string)$aur->unitcode));
            if ($auc === '') continue;
            $allStudentUnits[(string)$aur->clientid][$auc] = true;
        }
        $allUnitsRs->close();
    }

    // ARCHIVE-COURSE-AUTOENROL (v5.2.39): Pre-fetch all archive course links from the
    // qualunit_courses junction table, indexed by primary Moodle course ID so that
    // inside the per-qual-course loop we can expand $instanceByCourse/$enrolledByCourse
    // with archive courses — meaning the per-student enrolment loop picks them up
    // automatically with zero extra DB queries per student.
    //
    // Map structure: $archiveLinksByPrimary[primaryCourseid] = [
    //     ['courseid' => N, 'semester_label' => 'S2 2010', 'unitcode' => 'TLJA5061'],
    //     ...
    // ]
    //
    // The unit code is stored so that NAT outcome matching ($courseIdnumber) works for
    // archive courses — a "S2 2010" archive course of TLJA5061 is still the SAME unit,
    // so a competent NAT outcome for that unit should trigger course_completions.
    $archiveLinksByPrimary = [];   // primaryCourseid → list of archive link records
    $archiveCourseIdSet    = [];   // flat set: archiveCourseid → true (for stats)
    $totalarchiveenrolled  = 0;    // grand total archive-course enrolments this run
    if ($DB->get_manager()->table_exists('local_rtocompliance_qualunit_courses')) {
        $arcRs = $DB->get_recordset_sql(
            "SELECT qu.courseid AS primary_courseid,
                    quc.courseid AS archive_courseid,
                    quc.semester_label,
                    qu.unitcode
               FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
              WHERE quc.is_archive = 1
                AND qu.courseid IS NOT NULL AND qu.courseid > 0"
        );
        foreach ($arcRs as $ar) {
            $archiveLinksByPrimary[(int)$ar->primary_courseid][] = [
                'courseid'       => (int)$ar->archive_courseid,
                'semester_label' => (string)($ar->semester_label ?? ''),
                'unitcode'       => strtoupper(trim((string)($ar->unitcode ?? ''))),
            ];
            $archiveCourseIdSet[(int)$ar->archive_courseid] = true;
        }
        $arcRs->close();
    }

    $autoUnhiddenCatIds = []; // track categories we temporarily made visible for enrolment

    foreach ($qualcodes as $i => $qualcode) {
        $qualcode   = trim(clean_param($qualcode, PARAM_TEXT));
        $categoryid = (int)($categoryids[$i] ?? 0);
        $yearsem    = trim(clean_param($yearsems[$i] ?? '', PARAM_TEXT)); // e.g. "2015 S1" or ""
        if ($categoryid <= 0 || $qualcode === '') continue;

        // AUTO-UNHIDE: if the archive category is hidden in Moodle, make it visible
        // now so the course fetch below returns results and enrolment proceeds.
        // The preview page already flagged this as AUTO with an explanatory note.
        $catVisibleNow = (int)$DB->get_field('course_categories', 'visible', ['id' => $categoryid]);
        if ($catVisibleNow === 0) {
            try {
                $catObj = core_course_category::get($categoryid, IGNORE_MISSING);
                if ($catObj) {
                    $catObj->update(['visible' => 1]);
                    $autoUnhiddenCatIds[] = $categoryid; // remember for re-hide offer
                    if (!isset($diagLog[$qualcode])) $diagLog[$qualcode] = [];
                    $diagLog[$qualcode]['auto_unhide'] = true;
                }
            } catch (Throwable $_unhideEx) {
                // If unhide fails, courses won't be found and the qual will be skipped silently.
                if (!isset($diagLog[$qualcode])) $diagLog[$qualcode] = [];
                $diagLog[$qualcode]['auto_unhide_error'] = $_unhideEx->getMessage();
            }
        }

        // FIX-AUTOENROL-CATEGORIES (v4.9.179): fetch every visible course (unit of
        // competency) inside the selected Moodle category (= qualification).
        $catCourses = $DB->get_records_select(
            'course',
            'category = :catid AND visible = 1',
            ['catid' => $categoryid],
            'fullname ASC',
            'id, fullname, shortname, category, idnumber'
        );
        if (empty($catCourses)) continue;

        // Pre-fetch / create manual enrolment instances for every course in this
        // category, and pre-load the set of already-enrolled users per course.
        // This keeps the per-student inner loop free of DB queries.
        $instanceByCourse   = [];   // courseid → enrol record
        $enrolledByCourse   = [];   // courseid → [userid => true]
        $courseIdnumber     = [];   // courseid → idnumber (unit code) for NAT completion matching
        foreach ($catCourses as $catCourse) {
            $cid  = (int)$catCourse->id;
            $inst = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $cid]);
            if (!$inst) {
                $instid = $enrolplugin->add_default_instance($catCourse);
                if (!$instid) continue;
                $inst = $DB->get_record('enrol', ['id' => $instid]);
            }
            if (!$inst) continue;
            // Re-enable a disabled instance so students are not silently suspended.
            if ((int)$inst->status !== 0) {
                $DB->set_field('enrol', 'status', 0, ['id' => $inst->id]);
                $inst->status = 0;
            }
            $instanceByCourse[$cid] = $inst;
            if (!empty($catCourse->idnumber)) {
                $courseIdnumber[$cid] = strtoupper(trim((string)$catCourse->idnumber));
            }
            $enrolledByCourse[$cid] = array_flip($DB->get_fieldset_sql(
                "SELECT DISTINCT ue.userid
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.courseid = :cid AND ue.status = 0",
                ['cid' => $cid]
            ));

            // ARCHIVE-COURSE-AUTOENROL (v5.2.39): for each primary course in this
            // category, also wire up any archive courses linked via qualunit_courses
            // so the per-student loop enrols them into those courses too.
            if (!empty($archiveLinksByPrimary[$cid])) {
                foreach ($archiveLinksByPrimary[$cid] as $arcLink) {
                    $arcCid = $arcLink['courseid'];
                    if (isset($instanceByCourse[$arcCid])) continue;   // already wired
                    $arcCourse = $DB->get_record('course', ['id' => $arcCid, 'visible' => 1],
                        'id, fullname, shortname, category, idnumber', IGNORE_MISSING);
                    if (!$arcCourse) continue;   // deleted or hidden — skip silently
                    $arcInst = $DB->get_record('enrol', ['enrol' => 'manual', 'courseid' => $arcCid]);
                    if (!$arcInst) {
                        $arcInstId = $enrolplugin->add_default_instance($arcCourse);
                        if (!$arcInstId) continue;
                        $arcInst = $DB->get_record('enrol', ['id' => $arcInstId]);
                    }
                    if (!$arcInst) continue;
                    if ((int)$arcInst->status !== 0) {
                        $DB->set_field('enrol', 'status', 0, ['id' => $arcInst->id]);
                        $arcInst->status = 0;
                    }
                    $instanceByCourse[$arcCid] = $arcInst;
                    // Archive course is the same unit in a different semester.
                    // Use the stored unit code (from qualunits) for NAT outcome matching.
                    $arcUnitcode = $arcLink['unitcode'] !== ''
                        ? $arcLink['unitcode']
                        : ($courseIdnumber[$cid] ?? '');
                    if ($arcUnitcode !== '') {
                        $courseIdnumber[$arcCid] = $arcUnitcode;
                    }
                    $enrolledByCourse[$arcCid] = array_flip($DB->get_fieldset_sql(
                        "SELECT DISTINCT ue.userid
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON e.id = ue.enrolid
                          WHERE e.courseid = :acid AND ue.status = 0",
                        ['acid' => $arcCid]
                    ));
                }
            }
        }
        if (empty($instanceByCourse)) continue;   // no usable enrolment instances

        $enrolledQualcodes[] = $qualcode;

        // SPLIT-BY-YEAR-SEM (v5.2.63): fetch all (clientid, startdate) for this qualcode,
        // then filter in PHP to only those whose startdate falls within the selected year/semester.
        // This ensures "ABC12345 2015 S1" only enrols students from that intake, not 2016 S2 students.
        //
        // ENROL-CONTINUING-ONLY (v5.9.52): Count all distinct clients for this qual (all outcomes)
        // so we can report how many were excluded due to terminal outcomes.
        $allClientCount = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT clientid) FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid AND qualcode = :qualcode",
            ['importid' => $importid, 'qualcode' => $qualcode]
        );

        // ENROL-CONTINUING-ONLY (v5.9.52): Only auto-enrol students who have at least one
        // unit still in progress (outcomeidentifier = '70' = Continuing Enrolment).
        // Students whose ALL units carry terminal outcomes — 20 Competent, 30 Not Yet Achieved,
        // 40 Withdrawn, 41 VETiS, 51/52/53 RPL, 60/61 Credit Transfer, 81/82/85/90 — do NOT
        // need Moodle course access.  They belong in the Backfill Qual Builder for cert re-issue.
        // A student with MIXED outcomes (some units complete + at least one '70' in-progress)
        // IS included because they are still actively working towards their certificate.
        // FIX-FIELD-NAME (v5.9.63): staging table field is 'outcome', not 'outcomeidentifier'.
        $allClientRows = $DB->get_records_sql(
            "SELECT DISTINCT clientid, startdate
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid AND qualcode = :qualcode
                AND outcome = '70'",
            ['importid' => $importid, 'qualcode' => $qualcode]
        );
        if ($yearsem !== '') {
            $ysParts    = explode(' ', $yearsem, 2);
            $ysYear     = $ysParts[0] ?? '';
            $ysSem      = $ysParts[1] ?? '';  // "S1" or "S2"
            $filteredCids = [];
            foreach ($allClientRows as $cr) {
                $sd = trim($cr->startdate ?? '');
                if (strlen($sd) < 8) continue;
                $month = (int)substr($sd, 2, 2);
                $year  = substr($sd, 4, 4);
                if ($year !== $ysYear) continue;
                $sem = ($month <= 6) ? 'S1' : 'S2';
                if ($sem !== $ysSem) continue;
                $filteredCids[] = $cr->clientid;
            }
            $clientids = array_unique($filteredCids);
        } else {
            $clientids = array_unique(array_column((array)$allClientRows, 'clientid'));
        }

        // ENROL-CONTINUING-ONLY (v5.9.52): How many students in this qual had ONLY terminal outcomes?
        $terminalSkippedCount = max(0, $allClientCount - count($clientids));
        $totalskipterminal += $terminalSkippedCount;

        // FIX-EMPTY-QUALCODE (v5.2.27): Some SMS vendors export NAT00120 records with a blank
        // Program Identifier (qualcode). These students appear correctly in the Enrolments tab
        // (their unitcode is correct) but the query above finds 0 clientids for them because
        // qualcode = '' or NULL never matches the selected qualcode — they are silently skipped,
        // never enrolled in Moodle, and never appear in Student Records.
        //
        // Fix: after the qualcode query, also fetch students whose qualcode is empty/null but
        // whose unitcode (uppercased) matches the idnumber of a course in the selected Moodle
        // category. The admin has already told us which category = qualification to use, so
        // matching by unit code is the correct fallback for missing program identifiers.
        $unitCodesInCat = array_unique(array_filter(array_values($courseIdnumber)));  // non-empty, deduped (primary + archive share unit codes)
        if (!empty($unitCodesInCat)) {
            list($ucInSQL, $ucInParams) = $DB->get_in_or_equal($unitCodesInCat, SQL_PARAMS_NAMED, 'ucq');
            // ENROL-CONTINUING-ONLY (v5.9.52): same filter — only no-qual students still in progress.
            // FIX-FIELD-NAME (v5.9.63): staging table field is 'outcome', not 'outcomeidentifier'.
            $noqualClientids = $DB->get_fieldset_sql(
                "SELECT DISTINCT clientid
                   FROM {local_rtocompliance_avetmiss_enrolment}
                  WHERE importid = :nqimportid
                    AND (qualcode IS NULL OR qualcode = '')
                    AND UPPER(unitcode) $ucInSQL
                    AND outcome = '70'",
                array_merge(['nqimportid' => $importid], $ucInParams)
            );
            if (!empty($noqualClientids)) {
                $clientids = array_unique(array_merge($clientids, $noqualClientids));
            }
        }

        $diagEntry = [
            'qualcode'            => $qualcode,
            'categoryid'          => $categoryid,
            'course_count'        => count($instanceByCourse),
            'archive_course_count' => count(array_intersect_key($instanceByCourse, $archiveCourseIdSet)),
            'clientids_db'        => count($clientids),
            'terminal_skipped'    => $terminalSkippedCount,  // ENROL-CONTINUING-ONLY (v5.9.52)
            'phase1_matched'      => 0,
            'phase2_fallback'     => 0,
            'phase2_created'      => 0,
            'phase2_createfailed' => 0,
            'phase3_already'      => 0,
            'phase3_enrolled'     => 0,
            'phase3_enrolfailed'  => 0,
            'skipped_nostudent'    => 0,
            'skipped_noemail'      => 0,
            'skipped_nouser'       => 0,
            'phase3_skipped_nonat' => 0,   // ENROL-UNIT-ACCURATE (v5.9.57)
            'actual_qualcodes_in_db' => [],
        ];
        if (count($clientids) === 0) {
            $allQualcodesInImport = $DB->get_fieldset_select(
                'local_rtocompliance_avetmiss_enrolment',
                'DISTINCT qualcode',
                'importid = :importid',
                ['importid' => $importid]
            );
            $diagEntry['actual_qualcodes_in_db'] = $allQualcodesInImport;
            $diagEntry['total_rows_in_import'] = $DB->count_records(
                'local_rtocompliance_avetmiss_enrolment', ['importid' => $importid]
            );
        }

        foreach ($clientids as $clientid) {
            // Phase 1 — find an existing Moodle account.
            // Strategy: primary match (selected method), then cross-mode fallback,
            // then account creation. All lookups are O(1) in-memory hash lookups.
            $userid      = false;
            $wasMatched  = false;
            $wasCreated  = false;
            $lcClientid  = strtolower(trim((string)$clientid));

            // ── 1a. Primary match ────────────────────────────────────────────
            if ($matchMethod === 'studentid') {
                // idnumber first (SMS-synced), then username.
                if (isset($moodleUserByIdnumber[$lcClientid])) {
                    $userid = $moodleUserByIdnumber[$lcClientid];
                    $wasMatched = true;
                } elseif (isset($moodleUserByUsername[$lcClientid])) {
                    $userid = $moodleUserByUsername[$lcClientid];
                    $wasMatched = true;
                }
            } else {
                // Email mode: look up the student's email, then find Moodle account.
                $email = $studentEmailMap[$clientid] ?? '';
                if ($email !== '' && isset($moodleUserByEmail[$email])) {
                    $userid = $moodleUserByEmail[$email];
                    $wasMatched = true;
                }
            }

            // ── 1b. Cross-mode fallback ──────────────────────────────────────
            // FIX-AUTOENROL-MATCH-FALLBACK (v4.9.172): if primary match failed,
            // try the other method before attempting account creation.  This prevents
            // false 'createfailed' when a student's email changed but their Moodle
            // account still uses the clientid as username/idnumber (extremely common
            // on sites with previous imports), or vice-versa.
            $matchedByFallback = false;
            if ($userid === false) {
                if ($matchMethod === 'studentid') {
                    // Studentid-mode fallback: try email.
                    $email = $studentEmailMap[$clientid] ?? '';
                    if ($email !== '' && isset($moodleUserByEmail[$email])) {
                        $userid = $moodleUserByEmail[$email];
                        $wasMatched = true;
                        $matchedByFallback = true;
                    }
                } else {
                    // Email-mode fallback: try idnumber, then username (client ID).
                    if (isset($moodleUserByIdnumber[$lcClientid])) {
                        $userid = $moodleUserByIdnumber[$lcClientid];
                        $wasMatched = true;
                        $matchedByFallback = true;
                    } elseif (isset($moodleUserByUsername[$lcClientid])) {
                        $userid = $moodleUserByUsername[$lcClientid];
                        $wasMatched = true;
                        $matchedByFallback = true;
                    }
                }
            }

            if ($wasMatched) {
                if ($matchedByFallback) {
                    $totalmatched_fallback++;
                    $diagEntry['phase2_fallback']++;
                } else {
                    $totalmatched++;
                    $diagEntry['phase1_matched']++;
                }
            }

            // Phase 2 — if no existing account found, create one automatically.
            // (v4.9.161: account creation for all RTOs regardless of whether
            //  students were already in Moodle.)
            $useEmail = '';   // initialised here so the enrolfailed catch can reference it safely
            if ($userid === false) {
                $details  = $studentDetailsMap[$clientid] ?? null;

                // Resolve email: prefer real email from NAT00085, otherwise placeholder.
                $natEmail = '';
                if (array_key_exists($clientid, $studentEmailMap)) {
                    $natEmail = $studentEmailMap[$clientid];
                }
                if ($natEmail === '' && $details) {
                    $natEmail = strtolower(trim($details->email ?? ''));
                }
                $useEmail = ($natEmail !== '')
                    ? $natEmail
                    : ($lcClientid . '@no-email.placeholder');

                // FIX-AUTOENROL-PLACEHOLDER-COLLISION (v4.9.173): if $useEmail (real or
                // placeholder) already belongs to an active Moodle account, use that account
                // rather than trying to create a duplicate.  Without this check, a second
                // import run for the same students would call user_create_user() with an
                // email that was stored in the first run → duplicate-email exception →
                // 'createfailed' for every student on the second run.
                if ($useEmail !== '' && isset($moodleUserByEmail[$useEmail])) {
                    $userid    = $moodleUserByEmail[$useEmail];
                    $wasMatched = true;
                    $matchedByFallback = true;
                    $totalmatched_fallback++;
                    $diagEntry['phase2_fallback']++;
                    // Skip to Phase 3 — no account creation needed.
                    goto phase3;
                }

                // FIX-AUTOENROL-USERNAME-COLLISION (v5.2.12): A user whose username
                // matches this clientid may already exist even when none of the
                // earlier lookup maps matched them — e.g. the account was created by
                // a previous import that used a different match method, or an admin
                // created the account manually.  Without this check,
                // user_create_user() hits the mdl_user_mneuse_uix unique constraint
                // (mnethostid + username) and throws a DB duplicate-entry exception,
                // which the catch block below then counts as 'createfailed' and skips
                // the student entirely — leaving them un-enrolled.
                // Fix: do a direct DB lookup by username before attempting creation.
                // If the account already exists, reuse it and jump straight to Phase 3.
                $existingByUsername = $DB->get_record('user',
                    ['username' => $lcClientid, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id],
                    'id', IGNORE_MISSING);
                if ($existingByUsername) {
                    $userid            = (int)$existingByUsername->id;
                    $wasMatched        = true;
                    $matchedByFallback = true;
                    $totalmatched_fallback++;
                    $diagEntry['phase2_fallback']++;
                    goto phase3;
                }

                // NAT files store names in UPPERCASE — convert to Title Case.
                $firstName = $details
                    ? ucwords(mb_strtolower(trim($details->firstname  ?? 'Student')))
                    : 'Student';
                $lastName  = $details
                    ? ucwords(mb_strtolower(trim($details->familyname ?? $lcClientid)))
                    : $lcClientid;

                $newUser              = new \stdClass();
                $newUser->auth        = 'manual';
                $newUser->confirmed   = 1;
                $newUser->mnethostid  = $CFG->mnet_localhost_id;
                $newUser->username    = $lcClientid;
                $newUser->idnumber    = (string)$clientid;
                $newUser->firstname   = $firstName;
                $newUser->lastname    = $lastName;
                $newUser->email       = $useEmail;
                $newUser->lang        = $CFG->lang ?? 'en';
                // FIX-AUTOENROL-PASSWORD-POLICY (v4.9.163): Use policy-compliant password.
                // random_string() returns only [a-z0-9] and fails Moodle's standard
                // password policy (requires uppercase + non-alphanumeric chars) — causing
                // user_create_user() to throw a moodle_exception on every account creation
                // attempt, silently counting every student as 'createfailed'.
                $newUser->password    = local_rtocompliance_generate_policy_password();

                // Suppress system emails for placeholder addresses so bounces
                // don't fill the mail queue.
                $newUser->emailstop = ($natEmail === '') ? 1 : 0;

                try {
                    $newUser->id = user_create_user($newUser, true, false);
                    // Force password change on first login — the admin-generated password
                    // is random and never shown to the student.  This ensures they set their
                    // own password when they first log in via the Moodle login page.
                    set_user_preference('auth_forcepasswordchange', 1, $newUser->id);
                    // Register in lookup maps so subsequent quals find this account.
                    // Each map may be null if it isn't used by the active match method,
                    // so guard with is_array() to avoid fatal errors.
                    if (is_array($moodleUserByIdnumber)) {
                        $moodleUserByIdnumber[$lcClientid] = (int)$newUser->id;
                    }
                    if (is_array($moodleUserByUsername)) {
                        $moodleUserByUsername[$lcClientid] = (int)$newUser->id;
                    }
                    if (is_array($moodleUserByEmail) && $useEmail !== '') {
                        $moodleUserByEmail[$useEmail] = (int)$newUser->id;
                    }
                    $userid     = (int)$newUser->id;
                    $wasCreated = true;
                    $totalcreated++;
                    $diagEntry['phase2_created']++;
                } catch (\Exception $e) {
                    // FIX-AUTOENROL-USERNAME-COLLISION (v5.2.12): If user_create_user()
                    // throws a duplicate-key exception for this username (race condition
                    // or a case the pre-create lookup above missed), try to recover by
                    // fetching the existing account and continuing to Phase 3 rather than
                    // silently skipping the student and leaving them un-enrolled.
                    $isDuplicateUsername = (stripos($e->getMessage(), 'Duplicate entry') !== false
                        && stripos($e->getMessage(), 'mneuse_uix') !== false);
                    if ($isDuplicateUsername) {
                        $recoveredUser = $DB->get_record('user',
                            ['username' => $lcClientid, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id],
                            'id', IGNORE_MISSING);
                        if ($recoveredUser) {
                            debugging('local_rtocompliance auto-enrol: user_create_user() duplicate for '
                                . 'clientid=' . $clientid . ' — recovered existing userid=' . $recoveredUser->id,
                                DEBUG_DEVELOPER);
                            $userid            = (int)$recoveredUser->id;
                            $wasMatched        = true;
                            $matchedByFallback = true;
                            $totalmatched_fallback++;
                            $diagEntry['phase2_fallback']++;
                            goto phase3;
                        }
                    }
                    // For all other create failures, log and skip as before.
                    debugging('local_rtocompliance auto-enrol: user_create_user() failed for '
                        . 'clientid=' . $clientid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    $totalskipnouser++;
                    $diagEntry['phase2_createfailed']++;
                    $aeSkipped[] = [
                        'clientid' => $clientid,
                        'name'     => $studentNameMap[$clientid] ?? $lcClientid,
                        'email'    => $useEmail,
                        'reason'   => 'createfailed',
                        'qual'     => $qualcode,
                    ];
                    continue;
                }
            }

            // Phase 3 — enrol the resolved user into EVERY unit course in the category.
            // FIX-AUTOENROL-CATEGORIES (v4.9.179): one student → enrolled into all
            // unit courses that belong to the selected qualification category.
            phase3:
            // FIX-USI-FROM-NAT: record the USI for this matched/created student so
            // it can be batch-written to local_rtocompliance_students after the loop.
            if ($userid && !empty($clientidToUsi[$clientid])) {
                $usiToWrite[(int)$userid] = $clientidToUsi[$clientid];
            }
            // DOB-FROM-NAT: similarly record DOB so it is written to dateofbirth.
            if ($userid && !empty($clientidToDob[$clientid])) {
                $dobToWrite[(int)$userid] = $clientidToDob[$clientid];
            }

            // FIX-SYNC-PROFILE (v5.2.27): Create the student profile row in
            // local_rtocompliance_students SYNCHRONOUSLY so the student appears in
            // Student Records immediately after doenrol — not after Moodle cron fires.
            //
            // Previously, profile creation relied on process_enrolment_task(action='create')
            // being queued by the enrolment observer and then executed by Moodle cron.
            // If cron was slow or hadn't run since doenrol, enrolled students were visible
            // in course participants but completely invisible in Student Records — the admin
            // would have to wait (or manually trigger cron) to see them.
            //
            // This pre-creation is intentionally minimal (AVETMISS defaults only).
            // process_enrolment_task(action='create') — still queued via the observer —
            // will later fill in the RTO enrolment records (unit/qual linkage, commencing ID
            // etc.) and will safely skip the INSERT because the profile already exists.
            if ($userid && !isset($profilesEnsured[(int)$userid])) {
                $profilesEnsured[(int)$userid] = true;
                if (!$DB->record_exists('local_rtocompliance_students', ['userid' => (int)$userid])) {
                    // FIX-NAT00080-AVETMISS-DEMOGRAPHICS (v5.9.316): pull staging demographics.
                    $_stg3 = $DB->get_record_sql(
                        "SELECT sex, suburb, state, indigenousstatus, labourforcestatus, highestschoollevel, languageathome, countryofbirth, disabilityflag, prioreducationflag, atschoolflag
                           FROM {local_rtocompliance_avetmiss_student}
                          WHERE clientid = :cid
                          ORDER BY importid DESC
                          LIMIT 1",
                        ['cid' => (string)$clientid], IGNORE_MISSING);
                    $_sex3 = strtoupper(trim((string)($_stg3->sex ?? '')));
                    $syncStud                      = new \stdClass();
                    $syncStud->userid              = (int)$userid;
                    $syncStud->clientid            = (string)$clientid;
                    $syncStud->usi                 = $clientidToUsi[$clientid] ?? '';
                    $syncStud->usiverified         = 0;
                    $syncStud->indigenousstatus    = ($_stg3 && $_stg3->indigenousstatus  !== null && $_stg3->indigenousstatus  !== '') ? $_stg3->indigenousstatus  : '@';
                    $syncStud->countryofbirth      = ($_stg3 && !empty($_stg3->countryofbirth)     && $_stg3->countryofbirth     !== '@@@@') ? $_stg3->countryofbirth     : '1101';
                    $syncStud->languageathome      = ($_stg3 && !empty($_stg3->languageathome)     && !preg_match('/^[@\s]+$/', (string)$_stg3->languageathome)) ? $_stg3->languageathome : '1201';
                    $syncStud->englishproficiency  = '@';  // Removed from AVETMISS Release 8.
                    $syncStud->disabilityflag      = ($_stg3 && !empty($_stg3->disabilityflag)     && $_stg3->disabilityflag     !== '@') ? $_stg3->disabilityflag     : 'N';
                    $syncStud->highestschoollevel  = ($_stg3 && $_stg3->highestschoollevel !== null && $_stg3->highestschoollevel !== '') ? $_stg3->highestschoollevel : '@@';
                    $syncStud->labourforcestatus   = ($_stg3 && $_stg3->labourforcestatus  !== null && $_stg3->labourforcestatus  !== '') ? $_stg3->labourforcestatus  : '@@';
                    $syncStud->studyreason         = '@@';
                    $syncStud->prioreducationflag  = ($_stg3 && !empty($_stg3->prioreducationflag) && $_stg3->prioreducationflag !== '@') ? $_stg3->prioreducationflag : '@';
                    $syncStud->sex                 = in_array($_sex3, ['M','F','X'], true) ? $_sex3 : '@';
                    $syncStud->suburb              = ($_stg3 && !empty($_stg3->suburb)) ? $_stg3->suburb : null;
                    $syncStud->statecode           = ($_stg3 && !empty($_stg3->state))  ? $_stg3->state  : null;
                    $syncStud->atschoolflag        = ($_stg3 && !empty($_stg3->atschoolflag) && in_array($_stg3->atschoolflag, ['Y','N'], true)) ? $_stg3->atschoolflag : 'N';
                    $syncStud->surveycontactstatus = 'N';
                    $syncStud->profilecomplete     = 0;
                    $syncStud->timecreated         = time();
                    $syncStud->timemodified        = time();
                    try {
                        $DB->insert_record('local_rtocompliance_students', $syncStud);
                    } catch (\dml_exception $esync) {
                        // Race: observer task ran concurrently and beat us to the insert. Fine.
                        debugging('rtocompliance doenrol FIX-SYNC-PROFILE: race on userid='
                            . $userid . ': ' . $esync->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }

            // FIX-AUTOENROL-UPDATE-PLACEHOLDER-NAMES (v4.9.180): If this account was
            // auto-created in a previous run with a placeholder name ("Student" +
            // clientid) because NAT00080 was absent, and we now have the real name
            // from the cross-import lookup above, update the Moodle user profile so
            // the participant list shows the student's actual name.
            $details2 = $studentDetailsMap[$clientid] ?? null;
            if ($details2 && $userid) {
                $realFirst = ucwords(mb_strtolower(trim($details2->firstname  ?? '')));
                $realLast  = ucwords(mb_strtolower(trim($details2->familyname ?? '')));
                if ($realFirst !== '' || $realLast !== '') {
                    $existingUser = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
                    if ($existingUser
                            && (strtolower($existingUser->firstname) === 'student'
                                || strtolower($existingUser->lastname) === strtolower($lcClientid))) {
                        $upd = new \stdClass();
                        $upd->id = $userid;
                        if ($realFirst !== '') { $upd->firstname = $realFirst; }
                        if ($realLast  !== '') { $upd->lastname  = $realLast; }
                        $DB->update_record('user', $upd);
                    }
                }
            }
            $anyNewEnrolment = false;
            foreach ($instanceByCourse as $cid => $inst) {
                // ENROL-UNIT-ACCURATE (v5.9.57): when targeted_enrol is checked, only enrol
                // this student into courses whose idnumber matches one of their NAT unit codes.
                // Courses with no idnumber are always enrolled (backward-compatible fallback for
                // unit courses that haven't had their ID number configured yet).
                if ($targetedEnrol) {
                    $cuCode = $courseIdnumber[$cid] ?? '';
                    if ($cuCode !== '' && !isset($allStudentUnits[(string)$clientid][$cuCode])) {
                        $diagEntry['phase3_skipped_nonat']++;
                        continue;  // student has no NAT00120 record for this unit — skip
                    }
                }
                if (isset($enrolledByCourse[$cid][$userid])) {
                    $diagEntry['phase3_already']++;
                    // FIX-COMPLETION-EXISTING (v5.2.29): Student is already enrolled in
                    // this unit course but may still be missing a course_completions row
                    // (e.g. they were enrolled before v5.2.27, or doenrol is being re-run
                    // with the same import to trigger the retroactive profile fix).
                    // Write the completion record now if the NAT file has a competent
                    // outcome for this student+unit and no completion exists yet.
                    $natUnitcode = $courseIdnumber[$cid] ?? '';
                    if ($natUnitcode !== '' && isset($natOutcomeMap[$clientid][$natUnitcode])) {
                        $natData   = $natOutcomeMap[$clientid][$natUnitcode];
                        $competent = ['20','51','60','81']; // A-P1-1 (v5.9.387): canonical competent set (was wrongly incl. 41/53/61/85).
                        if (in_array(trim((string)($natData['outcome'] ?? '')), $competent, true)) {
                            $endTs = 0;
                            $dstr  = (string)($natData['enddate'] ?? '');
                            if (strlen($dstr) === 8) {
                                $ed = (int)substr($dstr, 0, 2);
                                $em = (int)substr($dstr, 2, 2);
                                $ey = (int)substr($dstr, 4, 4);
                                if ($ed > 0 && $em >= 1 && $em <= 12 && $ey >= 2000) {
                                    $ts = mktime(12, 0, 0, $em, $ed, $ey);
                                    if ($ts !== false && $ts > 0) $endTs = (int)$ts;
                                }
                            }
                            $ctime = $endTs > 0 ? $endTs : time();
                            try {
                                $existCC = $DB->get_record('course_completions',
                                    ['userid' => $userid, 'course' => $cid], 'id, timecompleted');
                                if (!$existCC) {
                                    $DB->insert_record('course_completions', (object)[
                                        'userid'        => $userid,
                                        'course'        => $cid,
                                        'timeenrolled'  => time(),
                                        'timestarted'   => time(),
                                        'timecompleted' => $ctime,
                                        'reaggregate'   => 0,
                                    ]);
                                } elseif (empty($existCC->timecompleted)) {
                                    $DB->set_field('course_completions', 'timecompleted',
                                        $ctime, ['id' => $existCC->id]);
                                }
                            } catch (\Exception $ecc) {
                                debugging('rtocompliance doenrol FIX-COMPLETION-EXISTING: '
                                    . 'course_completions write failed userid=' . $userid
                                    . ' course=' . $cid . ': ' . $ecc->getMessage(), DEBUG_DEVELOPER);
                            }
                            // Queue the complete task so local_rtocompliance_enrolments.outcomeidentifier
                            // is updated from '70' (Continuing) to the correct NAT outcome on cron.
                            if (class_exists('\local_rtocompliance\task\process_enrolment_task')) {
                                \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
                                    'action'   => 'complete',
                                    'userid'   => (int)$userid,
                                    'courseid' => (int)$cid,
                                ]);
                            }
                        }
                    }
                    continue;   // already enrolled in this specific unit course
                }
                try {
                    if (!RTOC_ENROL_WRITES_DISABLED) { $enrolplugin->enrol_user($inst, $userid, $studentroleid, time(), 0); }
                    $enrolledByCourse[$cid][$userid] = true;
                    $totalenrolled++;
                    if (isset($archiveCourseIdSet[$cid])) { $totalarchiveenrolled++; }
                    $diagEntry['phase3_enrolled']++;
                    $anyNewEnrolment = true;

                    // ROLLBACK-TRACK (v5.2.9): record this enrolment so it can be reversed.
                    $rbId = 0;
                    try {
                        $rbId = (int)$DB->insert_record('local_rtocompliance_enrol_rollback', (object)[
                            'importid'     => $importid,
                            'userid'       => (int)$userid,
                            'courseid'     => (int)$cid,
                            'enrolid'      => (int)$inst->id,
                            'user_created' => $wasCreated ? 1 : 0,
                            'cc_id'        => 0,
                            'cc_inserted'  => 0,
                            'timecreated'  => time(),
                        ]);
                    } catch (\Exception $erb) {
                        debugging('rtocompliance rollback-track: insert failed userid=' . $userid
                            . ' courseid=' . $cid . ': ' . $erb->getMessage(), DEBUG_DEVELOPER);
                    }

                    // NAT-FIX-5.2.4 (NAT-COMPLETION): If the NAT file recorded a competent
                    // outcome for this student+unit, mark the Moodle course as complete now.
                    // This populates {course_completions} so generate_course_certs.php can
                    // find students without requiring separate Moodle completion tracking.
                    $natUnitcode = $courseIdnumber[$cid] ?? '';
                    if ($natUnitcode !== '' && isset($natOutcomeMap[$clientid][$natUnitcode])) {
                        $natData    = $natOutcomeMap[$clientid][$natUnitcode];
                        // FIX-COMPETENT-5.2.13: Added '41' (Satisfactorily Completed – VETiS/school-based)
                        // and '85' (Non-assessable Satisfactorily Completed) to competent outcomes.
                        // FIX-COMPETENT-5.2.15: Removed '52' (RPL Not Granted) — a student who was
                        // DENIED RPL has not demonstrated competency and must not be marked complete.
                        $competent  = ['20','51','60','81']; // A-P1-1 (v5.9.387): canonical competent set (was wrongly incl. 41/53/61/85).
                        if (in_array(trim((string)($natData['outcome'] ?? '')), $competent, true)) {
                            $endTs  = 0;
                            $dstr   = (string)($natData['enddate'] ?? '');
                            if (strlen($dstr) === 8) {
                                $ed = (int)substr($dstr, 0, 2);
                                $em = (int)substr($dstr, 2, 2);
                                $ey = (int)substr($dstr, 4, 4);
                                if ($ed > 0 && $em >= 1 && $em <= 12 && $ey >= 2000) {
                                    $ts = mktime(12, 0, 0, $em, $ed, $ey);
                                    if ($ts !== false && $ts > 0) $endTs = (int)$ts;
                                }
                            }
                            $ctime = $endTs > 0 ? $endTs : time();
                            try {
                                $existCC = $DB->get_record('course_completions',
                                    ['userid' => $userid, 'course' => $cid], 'id, timecompleted');
                                if (!$existCC) {
                                    $newCcId = (int)$DB->insert_record('course_completions', (object)[
                                        'userid'        => $userid,
                                        'course'        => $cid,
                                        'timeenrolled'  => time(),
                                        'timestarted'   => time(),
                                        'timecompleted' => $ctime,
                                        'reaggregate'   => 0,
                                    ]);
                                    if ($rbId && $newCcId) {
                                        $DB->update_record('local_rtocompliance_enrol_rollback',
                                            (object)['id' => $rbId, 'cc_id' => $newCcId, 'cc_inserted' => 1]);
                                    }
                                } elseif (empty($existCC->timecompleted)) {
                                    $DB->set_field('course_completions', 'timecompleted',
                                        $ctime, ['id' => $existCC->id]);
                                    if ($rbId && $existCC->id) {
                                        $DB->update_record('local_rtocompliance_enrol_rollback',
                                            (object)['id' => $rbId, 'cc_id' => (int)$existCC->id, 'cc_inserted' => 0]);
                                    }
                                }
                            } catch (\Exception $ecc) {
                                debugging('rtocompliance doenrol NAT-COMPLETION: course_completions '
                                    . 'write failed userid=' . $userid . ' course=' . $cid
                                    . ': ' . $ecc->getMessage(), DEBUG_DEVELOPER);
                            }
                            // FIX-DOENROL-OUTCOME (v5.2.24): Doenrol inserts into course_completions
                            // directly — this bypasses the standard Moodle course_completed event so
                            // the observer never queues a 'complete' task.  Queue it explicitly here
                            // so that local_rtocompliance_enrolments.outcomeidentifier is updated
                            // from '70' (Continuing) to the correct NAT outcome on the next cron run.
                            if (class_exists('\local_rtocompliance\task\process_enrolment_task')) {
                                \local_rtocompliance\task\process_enrolment_task::queue_if_not_pending([
                                    'action'   => 'complete',
                                    'userid'   => (int)$userid,
                                    'courseid' => (int)$cid,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    debugging('local_rtocompliance auto-enrol: enrol_user() failed for userid='
                        . $userid . ' courseid=' . $cid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                    $totalskipnouser++;
                    $diagEntry['phase3_enrolfailed']++;
                    $aeSkipped[] = [
                        'clientid' => $clientid,
                        'name'     => $studentNameMap[$clientid] ?? $lcClientid,
                        'email'    => $useEmail ?? '',
                        'reason'   => 'enrolfailed',
                        'qual'     => $qualcode,
                        'errmsg'   => $e->getMessage(),
                    ];
                }
            }
            if (!$anyNewEnrolment) {
                $totalskipalready++;   // student was already in every unit in this category
            }
        }

        // DIAG: push this qualification's counters into the log.
        $diagLog[] = $diagEntry;
    }

    // FIX-USI-FROM-NAT: batch-write USI values from the NAT staging table into
    // local_rtocompliance_students.  This runs synchronously after all enrolments so
    // that USIs are visible immediately in Student Records without requiring a separate
    // data-entry step.
    //
    // Rules:
    //   • Existing profile with empty USI  → update USI + timemodified.
    //   • Existing profile with a USI already → skip (don't overwrite verified data).
    //   • No profile yet (task still queued) → pre-create a minimal record so
    //     process_enrolment_task finds it and leaves USI intact.
    if (!empty($usiToWrite)) {
        foreach ($usiToWrite as $uid => $usi) {
            $existing = $DB->get_record('local_rtocompliance_students', ['userid' => $uid], 'id, usi');
            if ($existing) {
                if (empty($existing->usi)) {
                    $DB->update_record('local_rtocompliance_students', (object)[
                        'id'           => $existing->id,
                        'usi'          => $usi,
                        'timemodified' => time(),
                    ]);
                }
            } else {
                // Profile doesn't exist yet — pre-create with USI so the queued task
                // finds the record and skips re-creating it (preserving the USI).
                $newstud                      = new \stdClass();
                $newstud->userid              = $uid;
                $newstud->clientid            = '';
                $newstud->usi                 = $usi;
                $newstud->usiverified         = 0;
                $newstud->indigenousstatus    = '@';
                $newstud->countryofbirth      = '1101';
                $newstud->languageathome      = '1201';
                $newstud->englishproficiency  = '@';
                $newstud->disabilityflag      = 'N';
                $newstud->highestschoollevel  = '@@';
                $newstud->labourforcestatus   = '@@';
                $newstud->studyreason         = '@@';
                $newstud->prioreducationflag  = '@';
                $newstud->sex                 = '@';
                $newstud->surveycontactstatus = 'N';
                $newstud->atschoolflag        = 'N';
                $newstud->profilecomplete     = 0;
                $newstud->timecreated         = time();
                $newstud->timemodified        = time();
                try {
                    $DB->insert_record('local_rtocompliance_students', $newstud);
                } catch (\dml_exception $e) {
                    // Duplicate key — process_enrolment_task ran concurrently.
                    // Try to update the now-existing record if USI is still empty.
                    $existing2 = $DB->get_record('local_rtocompliance_students', ['userid' => $uid], 'id, usi');
                    if ($existing2 && empty($existing2->usi)) {
                        $DB->update_record('local_rtocompliance_students', (object)[
                            'id'           => $existing2->id,
                            'usi'          => $usi,
                            'timemodified' => time(),
                        ]);
                    }
                }
            }
        }
    }

    // DOB-FROM-NAT: batch-write DOB values from the NAT staging table into
    // local_rtocompliance_students.dateofbirth.  Mirrors the USI write-back above.
    // Rules:
    //   • Only writes if dateofbirth is currently 0 or NULL (never overwrites existing data).
    //   • Converts DDMMYYYY string → Unix timestamp (noon UTC to avoid timezone boundary issues).
    if (!empty($dobToWrite)) {
        foreach ($dobToWrite as $uid => $dobStr) {
            if (strlen($dobStr) !== 8 || !ctype_digit($dobStr)) continue;
            $dd = (int)substr($dobStr, 0, 2);
            $mm = (int)substr($dobStr, 2, 2);
            $yy = (int)substr($dobStr, 4, 4);
            if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12 || $yy < 1900 || $yy > 2100) continue;
            $ts = gmmktime(12, 0, 0, $mm, $dd, $yy);
            if ($ts === false || $ts <= 0) continue;
            $existing = $DB->get_record('local_rtocompliance_students', ['userid' => $uid], 'id, dateofbirth');
            if ($existing && (empty($existing->dateofbirth) || (int)$existing->dateofbirth === 0)) {
                $DB->update_record('local_rtocompliance_students', (object)[
                    'id'           => $existing->id,
                    'dateofbirth'  => (int)$ts,
                    'timemodified' => time(),
                ]);
            }
        }
    }

    // FIX-RETROACTIVE-PROFILES (v5.2.27): Create Student Records profiles for any
    // course-enrolled users in the selected qualification categories who still don't
    // have a local_rtocompliance_students row — regardless of which import run enrolled
    // them.  This catches students enrolled before v5.2.27 whose profiles were never
    // created because (a) cron hadn't fired yet at the time, or (b) process_enrolment_task
    // returned early because the course wasn't in Qual Builder / nationally recognised at
    // that time.  The repair is intentionally lightweight: AVETMISS defaults only (same as
    // process_enrolment_created), and clientid is left empty since we can't reliably derive
    // it from the Moodle user record alone.  process_enrolment_task(action='create') — still
    // queued per normal observer flow — will later fill in qual/unit linkage and commencing ID.
    $allCategoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryids))));
    if (!empty($allCategoryIds)) {
        list($catInSQL, $catInParams) = $DB->get_in_or_equal($allCategoryIds, SQL_PARAMS_NAMED, 'rpcat');
        $enrolledUserIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e          ON e.id = ue.enrolid
               JOIN {course} c         ON c.id = e.courseid
              WHERE c.category $catInSQL
                AND ue.status = 0
                AND e.enrol = 'manual'",
            $catInParams
        );
        if (!empty($enrolledUserIds)) {
            foreach ($enrolledUserIds as $rpUid) {
                $rpUid = (int)$rpUid;
                if ($rpUid <= 0) continue;
                if ($DB->record_exists('local_rtocompliance_students', ['userid' => $rpUid])) continue;
                // Retroactive profiles: clientid unknown, use pure AVETMISS defaults.
                $rpStud                      = new \stdClass();
                $rpStud->userid              = $rpUid;
                $rpStud->clientid            = '';
                $rpStud->usi                 = '';
                $rpStud->usiverified         = 0;
                $rpStud->indigenousstatus    = '@';
                $rpStud->countryofbirth      = '1101';
                $rpStud->languageathome      = '1201';
                $rpStud->englishproficiency  = '@';
                $rpStud->disabilityflag      = 'N';
                $rpStud->highestschoollevel  = '@@';
                $rpStud->labourforcestatus   = '@@';
                $rpStud->studyreason         = '@@';
                $rpStud->prioreducationflag  = '@';
                $rpStud->sex                 = '@';
                $rpStud->surveycontactstatus = 'N';
                $rpStud->atschoolflag        = 'N';
                $rpStud->profilecomplete     = 0;
                $rpStud->timecreated         = time();
                $rpStud->timemodified        = time();
                try {
                    $DB->insert_record('local_rtocompliance_students', $rpStud);
                } catch (\dml_exception $erp) {
                    // Duplicate key — process_enrolment_task raced us. Fine.
                    debugging('rtocompliance doenrol FIX-RETROACTIVE-PROFILES: race on userid='
                        . $rpUid . ': ' . $erp->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    // Store the per-student skip report in the session so the results page can render it.
    // Keyed by importid so it survives page refresh and parallel imports.
    $SESSION->{'rtoc_ae_report_' . $importid} = json_encode([
        'totalenrolled'         => $totalenrolled,
        'totalarchiveenrolled'  => $totalarchiveenrolled,
        'totalcreated'          => $totalcreated,
        'totalmatched'          => $totalmatched,
        'totalmatched_fallback' => $totalmatched_fallback,
        'totalskipalready'      => $totalskipalready,
        'enrolledQualcodes'     => $enrolledQualcodes,
        'skipped'               => $aeSkipped,
        'matchMethod'           => $matchMethod,
        'diagLog'               => $diagLog,
        'diagImportId'          => $importid,
        'diagMatchMethod'       => $matchMethod,
    ]);

    $totalskipped    = $totalskipnostudent + $totalskipnoemail + $totalskipnouser;
    $totalAllMatched = $totalmatched + $totalmatched_fallback;

    // Build a human-readable match breakdown for the flash message.
    $matchParts = [];
    if ($totalmatched > 0) {
        $matchParts[] = $totalmatched . ' matched by ' . ($matchMethod === 'studentid' ? 'student ID' : 'email');
    }
    if ($totalmatched_fallback > 0) {
        $matchParts[] = $totalmatched_fallback . ' matched by ' . ($matchMethod === 'studentid' ? 'email (fallback)' : 'student ID (fallback)');
    }
    if ($totalcreated > 0) {
        $matchParts[] = $totalcreated . ' new Moodle account' . ($totalcreated !== 1 ? 's' : '') . ' created';
    }
    $createdPart = !empty($matchParts) ? ' (' . implode(', ', $matchParts) . ')' : '';

    $archivePart = ($totalarchiveenrolled > 0)
        ? ' (incl. ' . $totalarchiveenrolled . ' archive course enrolment' . ($totalarchiveenrolled !== 1 ? 's' : '') . ')'
        : '';
    if ($totalenrolled > 0 && $totalskipped === 0) {
        $msg = $totalenrolled . ' unit enrolment(s) completed across Moodle courses' . $archivePart . '.' . $createdPart;
    } elseif ($totalenrolled > 0) {
        $msg = $totalenrolled . ' student(s) enrolled' . $archivePart . $createdPart . '. ' . $totalskipped . ' student(s) could not be processed — see the skip report below.';
    } else {
        $msg = 'No students were enrolled. ';
        if ($totalskipalready > 0) {
            $msg .= $totalskipalready . ' student(s) were already enrolled. ';
        }
        $msg .= 'See the skip report below for details.';
    }
    if ($totalskipterminal > 0) {
        $msg .= ' ' . $totalskipterminal . ' student(s) were skipped because all their units have terminal outcomes'
            . ' (completed, withdrawn, RPL, credit transfer, etc.) — use <strong>Backfill Qual Builder</strong>'
            . ' to create Student Records for these students without Moodle enrolment.';
    }

    // Store auto-unhidden category IDs in the session so the results page can offer
    // to re-hide them (put the archive categories back to hidden from students).
    global $SESSION;
    if (!empty($autoUnhiddenCatIds)) {
        $SESSION->rtoc_auto_unhidden_cats = array_unique($autoUnhiddenCatIds);
    }

    // Pre-filter the results page to the first enrolled qual code so the admin
    // lands directly on those enrolments instead of seeing all 3000+ records.
    $redirectSearch = !empty($enrolledQualcodes) ? reset($enrolledQualcodes) : '';

    redirect(
        new moodle_url('/local/rtocompliance/data_import.php', [
            'importid'       => $importid,
            'tab'            => 'enrolments',
            'autoenrol_done' => 1,
            'enrolled'       => $totalenrolled,
            'search'         => $redirectSearch,
        ]),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─── CSV download for the per-student skip report ─────────────────────────────
// GET ?action=ae_skipcsv&importid=N — must be before any output.
if ($action === 'ae_skipcsv' && $importid) {
    global $SESSION;
    $reportKey = 'rtoc_ae_report_' . $importid;
    $report    = !empty($SESSION->$reportKey) ? json_decode($SESSION->$reportKey, true) : null;
    if ($report && !empty($report['skipped'])) {
        $csvMatchMethod  = $report['matchMethod'] ?? 'email';
        $reasonLabels = [
            'nostudent'   => 'No student demographics record (NAT00085 not uploaded)',
            'noemail'     => 'No email address in student record',
            'nouser'      => ($csvMatchMethod === 'studentid')
                ? 'No Moodle account found with this student number as username'
                : 'No matching active Moodle account found (email not found in Moodle)',
            'createfailed' => 'Could not create Moodle account (username already taken by a different user — check Moodle for a duplicate)',
        ];
        $filename = 'skipped_students_import_' . $importid . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF";   // UTF-8 BOM for Excel compatibility
        echo "Client ID,Student Name,Email Address,Reason Not Enrolled,Qualification Code\n";
        foreach ($report['skipped'] as $s) {
            $row = [
                '"' . str_replace('"', '""', $s['clientid'] ?? '') . '"',
                '"' . str_replace('"', '""', $s['name']     ?? '') . '"',
                '"' . str_replace('"', '""', $s['email']    ?? '') . '"',
                '"' . str_replace('"', '""', $reasonLabels[$s['reason'] ?? ''] ?? ($s['reason'] ?? '')) . '"',
                '"' . str_replace('"', '""', $s['qual']     ?? '') . '"',
            ];
            echo implode(',', $row) . "\n";
        }
        exit;
    }
    // Report expired or not found — fall through to normal page.
}

// GET ?action=export_quality_csv&importid=N — export flagged students as CSV. Must be before any output.
if ($action === 'export_quality_csv' && $importid) {
    require_login();
    // v5.9.368 CAP-FIX: 'manageimports' is not a declared capability (db/access.php)
    // — it threw a coding_exception on this CSV export. Use the plugin's manage cap.
    require_capability('local/rtocompliance:manage', context_system::instance());
    $dqexp = $DB->get_records_select(
        'local_rtocompliance_avetmiss_student',
        'importid = :importid AND hasdataissues = 1',
        ['importid' => $importid],
        'name ASC'
    );
    $dlfilename = 'data_quality_issues_import_' . $importid . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dlfilename . '"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    echo "Client ID,Name,Email,Missing USI,Missing DOB,Missing Sex\n";
    foreach ($dqexp as $fl) {
        $flissues = json_decode($fl->dataissuefields ?? '[]', true) ?: [];
        $flname = trim(($fl->firstname ?? '') . ' ' . ($fl->familyname ?? '')) ?: $fl->name;
        $flrow = [
            '"' . str_replace('"', '""', $fl->clientid) . '"',
            '"' . str_replace('"', '""', $flname) . '"',
            '"' . str_replace('"', '""', $fl->email ?? '') . '"',
            in_array('usi_missing',    $flissues) ? 'YES' : 'NO',
            in_array('dob_not_stated', $flissues) ? 'YES' : 'NO',
            in_array('sex_not_stated', $flissues) ? 'YES' : 'NO',
        ];
        echo implode(',', $flrow) . "\n";
    }
    exit;
}

// v5.9.371 NAT-RESULTS-REGISTER: GET ?action=export_unmatched&importid=N — download the
// students in this NAT file who are NOT yet in your system (no plugin student record), so
// their results could not be imported. Read-only; touches no Moodle data.
if ($action === 'export_unmatched' && $importid) {
    require_login();
    require_capability('local/rtocompliance:manage', context_system::instance());
    require_once(__DIR__ . '/classes/results_importer.php');
    $unmatched = \local_rtocompliance\results_importer::unmatched_students((int) $importid);
    $dlfilename = 'nat_students_not_in_system_import_' . $importid . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dlfilename . '"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    echo "Client ID,Name,Email,Qualification Codes in File\n";
    foreach ($unmatched as $u) {
        $urow = [
            '"' . str_replace('"', '""', $u['clientid'])  . '"',
            '"' . str_replace('"', '""', $u['name'])      . '"',
            '"' . str_replace('"', '""', $u['email'])     . '"',
            '"' . str_replace('"', '""', $u['qualcodes']) . '"',
        ];
        echo implode(',', $urow) . "\n";
    }
    exit;
}

// ─── FIX-REPAIRNAMES (v4.9.183) ──────────────────────────────────────────────
// POST ?action=repairnames — scan every Moodle account that was auto-created with
// a placeholder name ("Student" + clientid) and update it with the real name from
// the avetmiss_student table (any import, not just the current one).  Handles the
// common case where NAT00080 was in a different import batch than NAT00120, or
// where the first enrolment run pre-dated the NAT00080 upload.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'repairnames' && confirm_sesskey()) {
    require_once($CFG->libdir . '/moodlelib.php');

    // FIX-REPAIRNAMES-ROBUST (v4.9.187): Previous version did string-level clientid
    // matching which silently failed when the NAT00080 and NAT00120 batches used
    // different leading-zero padding (e.g. '5776' vs '0000005776').  New approach:
    //
    // 1. Load ALL avetmiss_student records into PHP memory.
    // 2. Index each by its NUMERIC clientid (all leading zeros stripped) so
    //    any padding difference is invisible to the lookup.
    // 3. For records where firstname/familyname are null, fall back to parsing
    //    the always-populated 'name' column (stored as "SURNAME, FIRSTNAME"
    //    or "SURNAME FIRSTNAME" depending on SMS vendor).
    // 4. Also try matching placeholder accounts by username (= lowercase clientid)
    //    in addition to idnumber, in case idnumber was not set on some accounts.

    // ── Step 1: build name lookup map from ALL avetmiss_student records ────────
    // FIX-REPAIRNAMES-ORDER (v5.9.79): added ORDER BY importid DESC so that when a clientid
    // appears in multiple imports, the most-recent import's name wins instead of an arbitrary one.
    $allStudentRecs = $DB->get_records_sql(
        "SELECT id, clientid, name, firstname, familyname, email
           FROM {local_rtocompliance_avetmiss_student}
          ORDER BY importid DESC"
    );

    // Key: stripped numeric clientid (no leading zeros).
    // Value: ['firstname', 'familyname', 'email'].
    $nameMap = [];
    foreach ($allStudentRecs as $s) {
        $numId = ltrim((string)$s->clientid, '0') ?: '0';
        if (isset($nameMap[$numId])) { continue; }   // keep first (= most-recent importid, DESC ordered)

        $first = trim($s->firstname  ?? '');
        $last  = trim($s->familyname ?? '');

        // Fallback: parse the combined 'name' field when individual fields are absent.
        // Formats seen in the wild: "SURNAME, FIRSTNAME" or "SURNAME FIRSTNAME".
        if ($first === '' && $last === '' && !empty(trim($s->name ?? ''))) {
            $combined = trim($s->name);
            if (strpos($combined, ',') !== false) {
                [$last, $first] = array_map('trim', explode(',', $combined, 2));
            } elseif (strpos($combined, ' ') !== false) {
                $parts = explode(' ', $combined, 2);
                $last  = trim($parts[0]);
                $first = trim($parts[1]);
            } else {
                $last = $combined;
            }
        }

        if ($first === '' && $last === '') { continue; }

        $nameMap[$numId] = [
            'firstname'  => $first,
            'familyname' => $last,
            'email'      => $s->email ?? null,
        ];
    }

    // ── Step 2: find placeholder Moodle accounts ───────────────────────────────
    // Match by firstname='Student'.  Also accept accounts where lastname looks
    // like a raw clientid (all-digit, length 4-12) in case idnumber was cleared.
    $placeholders = $DB->get_records_select(
        'user',
        "firstname = 'Student'",
        [],
        '',
        'id, username, idnumber, firstname, lastname, email'
    );

    $fixed   = 0;
    $scanned = count($placeholders);

    foreach ($placeholders as $u) {
        // Determine the numeric clientid to look up.
        // Prefer idnumber; fall back to username (auto-enrol sets both to clientid).
        $rawId = (string)($u->idnumber !== '' ? $u->idnumber : $u->username);
        $numId = ltrim($rawId, '0') ?: '0';

        $entry = $nameMap[$numId] ?? null;
        if (!$entry) { continue; }

        $realFirst = ucwords(mb_strtolower(trim($entry['firstname'])));
        $realLast  = ucwords(mb_strtolower(trim($entry['familyname'])));
        if ($realFirst === '' && $realLast === '') { continue; }

        $upd = new \stdClass();
        $upd->id = $u->id;
        if ($realFirst !== '') { $upd->firstname = $realFirst; }
        if ($realLast  !== '') { $upd->lastname  = $realLast; }
        // Repair placeholder email if a real address is available.
        if (!empty($entry['email']) && strpos($u->email ?? '', '@no-email.placeholder') !== false) {
            $upd->email = $entry['email'];
        }
        $DB->update_record('user', $upd);
        $fixed++;
    }

    // ── Step 3: redirect with a clear result message ───────────────────────────
    $redirectUrl = new moodle_url('/local/rtocompliance/data_import.php',
        $importid ? ['importid' => $importid] : []);
    $natCount = count($nameMap);
    if ($fixed > 0) {
        $msg = $fixed . ' student account' . ($fixed !== 1 ? 's' : '') . ' updated with real names.';
        $msgType = \core\output\notification::NOTIFY_SUCCESS;
    } elseif ($scanned > 0 && $natCount > 0) {
        $msg = 'Found ' . $natCount . ' student name record(s) in NAT00080 data, but none matched the '
             . $scanned . ' placeholder account(s). '
             . 'This can happen if the student IDs in NAT00080 differ from those used when accounts were created. '
             . 'Please contact support with this information.';
        $msgType = \core\output\notification::NOTIFY_WARNING;
    } elseif ($scanned > 0) {
        $msg = 'No NAT00080 name data found in the database for the ' . $scanned . ' placeholder account(s). '
             . 'Upload an import that includes the NAT00080 file, then click Fix Student Names again.';
        $msgType = \core\output\notification::NOTIFY_WARNING;
    } else {
        $msg = 'No placeholder accounts found — all student names are already set correctly.';
        $msgType = \core\output\notification::NOTIFY_SUCCESS;
    }
    redirect($redirectUrl, $msg, 8, $msgType);
}

// ─── Hide archive categories (POST action=hide_archive_cats) ──────────────────
// Re-hides Moodle course categories that were auto-unhidden during a NAT import
// enrolment run.  The IDs are read from $SESSION->rtoc_auto_unhidden_cats which
// is populated by the doenrol handler.  This is safe to call any number of times.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'hide_archive_cats' && confirm_sesskey()) {
    global $SESSION;
    $catIdsRaw = $SESSION->rtoc_auto_unhidden_cats ?? [];
    unset($SESSION->rtoc_auto_unhidden_cats); // consume immediately so the card disappears

    $hidden  = 0;
    $failed  = 0;
    $errors  = [];
    foreach ($catIdsRaw as $catId) {
        $catId = (int)$catId;
        if ($catId <= 0) continue;
        try {
            $catObj = core_course_category::get($catId, IGNORE_MISSING);
            if ($catObj) {
                $catObj->update(['visible' => 0]);
                $hidden++;
            }
        } catch (Throwable $_hideEx) {
            $failed++;
            $errors[] = 'Category ' . $catId . ': ' . $_hideEx->getMessage();
        }
    }

    $importid   = optional_param('importid', 0, PARAM_INT);
    $redirectTo = new moodle_url('/local/rtocompliance/data_import.php', [
        'importid'       => $importid,
        'tab'            => 'enrolments',
        'autoenrol_done' => 1,
    ]);

    if ($failed === 0 && $hidden > 0) {
        $msg     = $hidden . ' archive categor' . ($hidden === 1 ? 'y' : 'ies') . ' hidden from students successfully.';
        $msgType = \core\output\notification::NOTIFY_SUCCESS;
    } elseif ($hidden === 0 && $failed === 0) {
        $msg     = 'No archive categories to re-hide (may have already been hidden or the session expired).';
        $msgType = \core\output\notification::NOTIFY_WARNING;
    } else {
        $msg     = $hidden . ' hidden, ' . $failed . ' failed: ' . implode('; ', $errors);
        $msgType = \core\output\notification::NOTIFY_ERROR;
    }
    redirect($redirectTo, $msg, 6, $msgType);
}

// ── QUAL-CATEGORY MAP: live search AJAX (v5.4.0) ─────────────────────────────
// FIX-AJAX-AFTER-HEADER (v5.9.2): these three handlers were mistakenly placed AFTER
// echo $OUTPUT->header(), which means HTML was already in the output buffer when they
// tried to call header('Content-Type: application/json') and die. Moved here — BEFORE
// any HTML output — so each handler sends a clean JSON-only response.
// Searches Moodle course_categories by name fragment and returns matching
// categories with their full path + up to 3 immediate children for confirmation.
if ($action === 'qcm_search') {
    require_sesskey();
    // v5.9.368 CAP-FIX: 'importavetmiss' is undeclared — use the manage capability.
    require_capability('local/rtocompliance:manage', $context);
    header('Content-Type: application/json');
    $q = trim(optional_param('q', '', PARAM_TEXT));
    if (strlen($q) < 2) { echo json_encode(['cats' => []]); die; }
    $allCats = $DB->get_records_select('course_categories', '', [], 'name ASC',
        'id,name,parent,depth,path,idnumber');
    $catById2 = [];
    foreach ($allCats as $c) { $catById2[(int)$c->id] = $c; }
    foreach ($allCats as $c) {
        $parts = []; $cur = $c;
        while ($cur) {
            array_unshift($parts, $cur->name);
            $cur = isset($catById2[(int)$cur->parent]) ? $catById2[(int)$cur->parent] : null;
        }
        $c->fullpath = implode(' / ', $parts);
    }
    $results = [];
    foreach ($allCats as $c) {
        if (stripos($c->fullpath, $q) === false && stripos($c->name, $q) === false) continue;
        $children = [];
        foreach ($allCats as $ch) {
            if ((int)$ch->parent === (int)$c->id) {
                $children[] = ['id' => (int)$ch->id, 'name' => $ch->name];
            }
        }
        $results[] = [
            'id'         => (int)$c->id,
            'name'       => $c->name,
            'path'       => $c->fullpath,
            'childCount' => count($children),
            'children'   => array_slice($children, 0, 3),
        ];
        if (count($results) >= 15) break;
    }
    echo json_encode(['cats' => $results]);
    die;
}

// ── QUAL-CATEGORY MAP: save mapping (v5.5.0) ─────────────────────────────────
// Saves BOTH the parent-category selection AND explicit per-intake sub-category
// assignments.  Format stored:
//   qualcat_map[QCODE] = {
//     cat_id, cat_name, cat_path,           ← parent category
//     intakes: { "2021 S1": {cat_id, cat_name}, … }  ← per-intake sub-cats
//   }
// Pass G uses ONLY the intakes[] IDs — zero text parsing.
if ($action === 'qcm_save') {
    require_sesskey();
    // v5.9.368 CAP-FIX: 'importavetmiss' is undeclared — use the manage capability.
    require_capability('local/rtocompliance:manage', $context);
    header('Content-Type: application/json');
    $mapRaw  = optional_param('map', '', PARAM_RAW); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    $newMap  = json_decode($mapRaw ?: '{}', true);
    if (!is_array($newMap)) $newMap = [];
    $existing = json_decode(get_config('local_rtocompliance', 'qualcat_map') ?: '{}', true) ?: [];
    foreach ($newMap as $qc => $entry) {
        $qcUpper = strtoupper(trim($qc));
        if ($qcUpper === '') continue;
        $catId = (int)($entry['cat_id'] ?? 0);
        if ($catId <= 0) {
            unset($existing[$qcUpper]);
        } else {
            $existingIntakes = $existing[$qcUpper]['intakes'] ?? [];
            $existing[$qcUpper] = [
                'cat_id'   => $catId,
                'cat_name' => substr(trim($entry['cat_name'] ?? ''), 0, 255),
                'cat_path' => substr(trim($entry['cat_path'] ?? ''), 0, 500),
                'intakes'  => $existingIntakes,
            ];
            // Merge explicit intake → sub-category assignments if provided.
            if (isset($entry['intakes']) && is_array($entry['intakes'])) {
                foreach ($entry['intakes'] as $ys => $intake) {
                    $iCatId = (int)($intake['cat_id'] ?? 0);
                    if ($iCatId > 0) {
                        $existing[$qcUpper]['intakes'][$ys] = [
                            'cat_id'   => $iCatId,
                            'cat_name' => substr(trim($intake['cat_name'] ?? ''), 0, 255),
                        ];
                    } else {
                        unset($existing[$qcUpper]['intakes'][$ys]);
                    }
                }
            }
        }
    }
    set_config('qualcat_map', json_encode($existing), 'local_rtocompliance');
    echo json_encode(['ok' => true, 'saved' => count($newMap)]);
    die;
}

// ── QUAL-CATEGORY MAP: fetch sub-categories (v5.5.0) ─────────────────────────
// Returns ALL direct children of a Moodle category by parent ID.
// Used by the mapping-card JS to populate the intake→sub-category dropdowns
// with exact names and IDs straight from the DB — no parsing involved.
if ($action === 'qcm_children') {
    require_sesskey();
    // v5.9.368 CAP-FIX: 'importavetmiss' is undeclared — use the manage capability.
    require_capability('local/rtocompliance:manage', $context);
    header('Content-Type: application/json');
    $parentId = (int)optional_param('cat_id', 0, PARAM_INT);
    if ($parentId <= 0) { echo json_encode(['children' => [], 'parent_name' => '']); die; }
    $parent = $DB->get_record_select('course_categories', 'id = :id', ['id' => $parentId], 'id,name');
    $kids   = $DB->get_records_select('course_categories', 'parent = :pid',
        ['pid' => $parentId], 'name ASC', 'id,name');
    $result = [];
    foreach ($kids as $k) {
        $result[] = ['id' => (int)$k->id, 'name' => $k->name];
    }
    echo json_encode(['children' => $result, 'parent_name' => $parent ? $parent->name : '']);
    die;
}

// ─── Output ───────────────────────────────────────────────────────────────────

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Data Import', null, null, 'data_import');

echo html_writer::start_div('compliance-container');
echo html_writer::start_div('compliance-header');
echo html_writer::tag('h2', get_string('dataimport_title', 'local_rtocompliance'));
echo html_writer::end_div();

// ── Shortcuts row ─────────────────────────────────────────────────────────────
// v5.9.372 DATA-IMPORT-DECLUTTER: only "Verify NAT Data" remains. "Backfill Student
// Records" created Moodle accounts — removed now the import is data-only.
echo '<div class="mb-3 d-flex flex-wrap" style="gap:.5rem;">';
echo html_writer::link(
    new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'verify_nat']),
    '&#10003; Verify NAT Data',
    ['class' => 'btn btn-outline-secondary btn-sm', 'title' => 'Read-only check: which students in your files are already in your system']
);
echo '</div>';

// ── AUTO-ENROL WIZARD ─────────────────────────────────────────────────────────
// Shown after a successful NAT import as optional Step 3.
// Groups enrolment records from the just-completed import by qualification code
// and lets the admin map each qual to a Moodle course for bulk enrolment.
if ($action === 'autoenrol' && $importid) {

    // Read session vars we need before write_close().
    $p2MatchMethod = (($SESSION->rtocompliance_nat_match_method ?? 'email') === 'studentid') ? 'studentid' : 'email';
    \core\session\manager::write_close();
    require_once($CFG->libdir . '/enrollib.php');

    // ── 1. Archive index health check ──────────────────────────────────────────
    $p2ArchiveCount = 0;
    try { $p2ArchiveCount = $DB->count_records('local_rtocompliance_archive_index'); } catch (Exception $e) {}
    $p2ArchiveAdminUrl = (new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'archive_index']))->out(false);

    // Phase 3: Stale index check — warn if rebuilt more than 30 days ago.
    $p2IndexBuiltAt = '';
    $p2IndexIsStale = false;
    $p2IndexDaysOld = 0;
    try { $p2IndexBuiltAt = local_rtocompliance_archive_get_meta('last_rebuilt'); } catch (Exception $e) {}
    if ($p2ArchiveCount > 0 && $p2IndexBuiltAt !== '' && is_numeric($p2IndexBuiltAt)) {
        $p2IndexDaysOld = (int)floor((time() - (int)$p2IndexBuiltAt) / 86400);
        $p2IndexIsStale = ($p2IndexDaysOld > 30);
    }

    // ── 2. Qual → Family map (defined in Phase 1 helpers above) ────────────────
    $p2QualToFamily = local_rtocompliance_qual_to_family();

    // ── 3. Load enrolments + group by family + year + sem ──────────────────────
    // Dedup by client_id so each student counts once per family+period group.
    $p2Groups = []; // gkey → [family, year, sem, qualcodes_map, cid_map, studentcount]
    try {
        $p2EnrolRs = $DB->get_recordset_sql(
            "SELECT qualcode, startdate, clientid
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid
                AND " . $DB->sql_isnotempty('local_rtocompliance_avetmiss_enrolment', 'qualcode', false, true) . "
              ORDER BY qualcode, startdate",
            ['importid' => $importid]
        );
        foreach ($p2EnrolRs as $p2Er) {
            $p2QcUpper = strtoupper(trim((string)$p2Er->qualcode));
            $p2Family  = $p2QualToFamily[$p2QcUpper] ?? '';

            // Extract year+sem from DDMMYYYY start date.
            $p2Sd   = trim((string)($p2Er->startdate ?? ''));
            $p2Year = 0; $p2Sem = '';
            if (strlen($p2Sd) === 8) {
                $p2Mon = (int)substr($p2Sd, 2, 2);
                $p2Yr  = (int)substr($p2Sd, 4, 4);
                if ($p2Yr >= 2000 && $p2Mon >= 1 && $p2Mon <= 12) {
                    $p2Year = $p2Yr;
                    $p2Sem  = ($p2Mon <= 6) ? 'S1' : 'S2';
                }
            }

            // Unknown families get their own bucket per qual code so they
            // appear as separate MANUAL cards rather than being merged.
            $p2Gkey = ($p2Family !== '' ? $p2Family : '__unk__' . $p2QcUpper)
                    . '|||' . $p2Year . '|||' . $p2Sem;

            if (!isset($p2Groups[$p2Gkey])) {
                $p2Groups[$p2Gkey] = [
                    'family'       => $p2Family,
                    'year'         => $p2Year,
                    'sem'          => $p2Sem,
                    '_qcodes'      => [],
                    '_cids'        => [],
                    '_qc_cids'     => [], // qualcode → [clientid => true]
                    'studentcount' => 0,
                ];
            }
            $p2Groups[$p2Gkey]['_qcodes'][$p2QcUpper] = true;
            // Track per-qual-code client IDs so groups can be split later.
            $p2Groups[$p2Gkey]['_qc_cids'][$p2QcUpper][$p2Er->clientid] = true;
            if (!isset($p2Groups[$p2Gkey]['_cids'][$p2Er->clientid])) {
                $p2Groups[$p2Gkey]['_cids'][$p2Er->clientid] = true;
                $p2Groups[$p2Gkey]['studentcount']++;
            }
        }
        $p2EnrolRs->close();
    } catch (Exception $e) { /* safe */ }

    // Flatten qualcodes; compute per-qc student counts; drop dedup maps.
    foreach ($p2Groups as &$p2G) {
        $p2G['qualcodes'] = array_keys($p2G['_qcodes']);
        sort($p2G['qualcodes']);
        $p2G['_qc_studentcounts'] = [];
        foreach ($p2G['_qc_cids'] as $p2FQc => $p2FCids) {
            $p2G['_qc_studentcounts'][$p2FQc] = count($p2FCids);
        }
        unset($p2G['_qcodes'], $p2G['_cids'], $p2G['_qc_cids']);
    }
    unset($p2G);

    // ── 3b. Qual-code-aware group splitting ─────────────────────────────────────
    // When a family group spans multiple qual codes AND the archive index has a
    // distinct qual-code-matched category for each, split the group into one
    // sub-group per qual code so each auto-routes independently.
    // Example: "customs_broking 2023 S2" with ABC12345+ABC12345 students splits
    // into two AUTO cards pointing to their respective archive categories.
    $p2SplitKeys = [];
    foreach ($p2Groups as $p2Gk => $p2G) {
        if (count($p2G['qualcodes']) < 2)          continue;
        if ($p2G['family'] === '' || $p2G['year'] === 0) continue;

        // Fetch archive candidates for this period.
        try {
            $p2SArchRows = $DB->get_records('local_rtocompliance_archive_index', [
                'family' => $p2G['family'],
                'year'   => $p2G['year'],
                'sem'    => $p2G['sem'],
            ]);
        } catch (Exception $e) { $p2SArchRows = []; }
        if (count($p2SArchRows) < 2) continue;

        // Map each qual code to the archive whose fullpath contains it (1-to-1).
        $p2QcToArch = [];
        foreach ($p2G['qualcodes'] as $p2SQc) {
            $p2SMatched = [];
            foreach ($p2SArchRows as $p2SAr) {
                $p2SHay = strtoupper(($p2SAr->fullpath ?? '') . ' ' . ($p2SAr->categoryname ?? ''));
                if (strpos($p2SHay, strtoupper($p2SQc)) !== false) {
                    $p2SMatched[] = $p2SAr;
                }
            }
            if (count($p2SMatched) === 1) {
                $p2QcToArch[$p2SQc] = $p2SMatched[0];
            }
        }

        // Only split when EVERY qual code has exactly one unique archive match.
        if (count($p2QcToArch) !== count($p2G['qualcodes'])) continue;
        $p2SplitCatIds = array_map(fn($ar) => $ar->categoryid, $p2QcToArch);
        if (count(array_unique($p2SplitCatIds)) !== count($p2SplitCatIds)) continue;

        // All clear — replace the combined group with per-qual-code sub-groups.
        $p2SplitKeys[] = $p2Gk;
        foreach ($p2QcToArch as $p2SQc2 => $p2SArch2) {
            $p2SubKey = $p2Gk . '|||split|||' . $p2SQc2;
            $p2Groups[$p2SubKey] = [
                'family'              => $p2G['family'],
                'year'                => $p2G['year'],
                'sem'                 => $p2G['sem'],
                'qualcodes'           => [$p2SQc2],
                '_qc_studentcounts'   => [$p2SQc2 => $p2G['_qc_studentcounts'][$p2SQc2] ?? 0],
                'studentcount'        => $p2G['_qc_studentcounts'][$p2SQc2] ?? 0,
                '_preresolved_arch'   => $p2SArch2, // archive already matched — skip DB lookup
            ];
        }
    }
    foreach ($p2SplitKeys as $p2Sk) { unset($p2Groups[$p2Sk]); }

    // ── 4. Archive index lookup + AUTO / REVIEW / MANUAL classification ─────────
    foreach ($p2Groups as $p2Gk => &$p2G) {
        $p2G['archive_row']  = null;
        $p2G['course_count'] = 0;
        $p2G['enrol_count']  = 0;
        $p2G['cat_visible']  = 0;

        // ── Archive lookup + active-row selection ────────────────────────────────
        // Pre-resolved split sub-groups (from step 3b) already have their archive;
        // all other groups go through the normal DB lookup path.
        $p2ActiveRow      = null;
        $p2QcResolved     = false;
        $p2AnnualFallback = false;

        if (isset($p2G['_preresolved_arch'])) {
            // Split sub-group: archive was matched by qual code in step 3b.
            $p2ActiveRow      = $p2G['_preresolved_arch'];
            $p2QcResolved     = true;
        } else {
            if ($p2G['family'] === '') {
                $p2G['status']        = 'manual';
                $p2G['status_reason'] = 'Qualification code(s) not in the qualification&rarr;family map. '
                    . 'Add them under <strong>Archive family map</strong> in the plugin settings '
                    . '(one "CODE = family" per line).';
                continue;
            }

            if ($p2G['year'] === 0) {
                $p2G['status']        = 'manual';
                $p2G['status_reason'] = 'No valid start date in NAT00120 for this group. Cannot determine year/semester.';
                continue;
            }

            // Look up archive index — exact year+sem first, then annual (sem='') fallback.
            // RTOs sometimes name categories without a semester suffix (e.g. "CB Archive 2014")
            // which gets indexed as sem=''. NAT groups always derive S1/S2 from startdate, so
            // without the fallback those annual categories would never match.
            $p2ArchRows = [];
            try {
                $p2ArchRows = $DB->get_records('local_rtocompliance_archive_index', [
                    'family' => $p2G['family'],
                    'year'   => $p2G['year'],
                    'sem'    => $p2G['sem'],
                ]);
                if (empty($p2ArchRows) && $p2G['sem'] !== '') {
                    // Annual fallback: category was indexed without a semester suffix.
                    $p2ArchRows = $DB->get_records('local_rtocompliance_archive_index', [
                        'family' => $p2G['family'],
                        'year'   => $p2G['year'],
                        'sem'    => '',
                    ]);
                    $p2AnnualFallback = !empty($p2ArchRows);
                }
            } catch (Exception $e) { /* safe */ }

            if (empty($p2ArchRows)) {
                $p2Period = $p2G['year'] . ($p2G['sem'] !== '' ? ' ' . $p2G['sem'] : '');
                $p2G['status']        = 'manual';
                $p2G['status_reason'] = 'No archive category indexed for <strong>'
                    . htmlspecialchars($p2G['family'], ENT_QUOTES) . ' ' . htmlspecialchars($p2Period, ENT_QUOTES)
                    . '</strong>. '
                    . '<a href="' . s($p2ArchiveAdminUrl) . '">Rebuild the archive index</a> if the Moodle category exists.';
                continue;
            }

            // Prefer is_active=1; fall back to qual-code tie-break for single-qual groups.
            foreach ($p2ArchRows as $p2Ar) {
                if ((int)$p2Ar->is_active === 1) { $p2ActiveRow = $p2Ar; break; }
            }

            // Qual-code tie-break: when there is no is_active=1 row AND there are
            // multiple candidates, check whether the group's qual code(s) appear in
            // each candidate's fullpath.  Only applies when exactly one candidate matches
            // (multi-qual-code groups are already split into sub-groups in step 3b).
            if (!$p2ActiveRow && count($p2ArchRows) > 1 && !empty($p2G['qualcodes'])) {
                $p2QcMatched = [];
                foreach ($p2ArchRows as $p2Ar) {
                    $p2Haystack = strtoupper(($p2Ar->fullpath ?? '') . ' ' . ($p2Ar->categoryname ?? ''));
                    foreach ($p2G['qualcodes'] as $p2Qc) {
                        if ($p2Qc !== '' && strpos($p2Haystack, strtoupper($p2Qc)) !== false) {
                            $p2QcMatched[] = $p2Ar;
                            break;
                        }
                    }
                }
                if (count($p2QcMatched) === 1) {
                    $p2ActiveRow  = $p2QcMatched[0];
                    $p2QcResolved = true;
                }
            }

            if (!$p2ActiveRow) {
                $p2G['status']      = 'review';
                $p2G['archive_row'] = reset($p2ArchRows);
                if (count($p2ArchRows) === 1) {
                    $p2InactName = $p2G['archive_row']->fullpath ?? $p2G['archive_row']->categoryname;
                    $p2G['status_reason'] = 'Archive category <strong>' . htmlspecialchars($p2InactName, ENT_QUOTES)
                        . '</strong> exists but is marked inactive (is_active = 0). '
                        . 'Enable it in the <a href="' . s($p2ArchiveAdminUrl) . '">Archive Index</a> page.';
                } else {
                    $p2G['status_reason'] = count($p2ArchRows) . ' archive categories found for this period but none is '
                        . 'marked active. Resolve the conflict in the <a href="' . s($p2ArchiveAdminUrl) . '">Archive Index</a> page.';
                }
                continue;
            }
        } // end else (normal DB lookup path)

        $p2G['qual_code_resolved'] = $p2QcResolved;
        $p2G['archive_row']        = $p2ActiveRow;
        $p2G['annual_fallback']    = $p2AnnualFallback;

        // Check category visibility, course count, enrolment methods.
        try {
            $p2G['cat_visible']  = (int)$DB->get_field('course_categories', 'visible', ['id' => (int)$p2ActiveRow->categoryid]);
            $p2G['course_count'] = (int)$DB->count_records('course', ['category' => (int)$p2ActiveRow->categoryid, 'visible' => 1]);
            if ($p2G['course_count'] > 0) {
                $p2G['enrol_count'] = (int)$DB->count_records_select(
                    'enrol',
                    "courseid IN (SELECT id FROM {course} WHERE category = :catid AND visible = 1) AND status = 0",
                    ['catid' => (int)$p2ActiveRow->categoryid]
                );
            }
        } catch (Exception $e) { /* safe */ }

        if ($p2G['cat_visible'] == 0 && $p2G['course_count'] > 0 && $p2G['enrol_count'] > 0) {
            // Category is hidden but otherwise ready — will be auto-unhidden at enrolment time.
            $p2G['status']        = 'auto';
            $p2G['status_reason'] = 'Category is currently hidden in Moodle — it will be made visible automatically when you click Enrol.';
        } elseif ($p2G['cat_visible'] == 0 && $p2G['course_count'] === 0) {
            $p2G['status']        = 'review';
            $p2G['status_reason'] = 'Archive category is hidden and has no visible courses inside it. Make the category and its courses visible in Moodle before enrolling.';
        } elseif ($p2G['cat_visible'] == 0 && $p2G['enrol_count'] === 0) {
            $p2G['status']        = 'review';
            $p2G['status_reason'] = 'Archive category is hidden and its courses have no active manual enrolment methods. Make the category visible and add a manual enrolment method to its courses.';
        } elseif ($p2G['course_count'] === 0) {
            $p2G['status']        = 'review';
            $p2G['status_reason'] = 'Archive category found but has no visible courses.';
        } elseif ($p2G['enrol_count'] === 0) {
            $p2G['status']        = 'review';
            $p2G['status_reason'] = 'Archive category has visible courses but no active manual enrolment methods.';
        } else {
            $p2G['status']        = 'auto';
            $p2G['status_reason'] = '';
        }
    }
    unset($p2G);

    // ── 5. Sample students (up to 3 names per group, for display only) ────────────
    // BUG-FIX v5.9.0: table was incorrectly named avetmiss_client (does not exist);
    // column was incorrectly named lastname (column is familyname). Fixed to use the
    // actual avetmiss_student table with the correct firstname + familyname columns.
    $p2Samples = [];
    try {
        $p2SRs = $DB->get_recordset_sql(
            "SELECT e.qualcode, e.startdate, e.clientid, s.firstname, s.familyname, s.name
               FROM {local_rtocompliance_avetmiss_enrolment} e
               JOIN {local_rtocompliance_avetmiss_student}   s
                 ON s.clientid = e.clientid AND s.importid = e.importid
              WHERE e.importid = :importid
              ORDER BY e.qualcode, e.startdate, s.familyname",
            ['importid' => $importid]
        );
        foreach ($p2SRs as $p2Sr) {
            $p2SQcUp = strtoupper(trim((string)$p2Sr->qualcode));
            $p2SFam  = $p2QualToFamily[$p2SQcUp] ?? '';
            $p2SSd   = trim((string)($p2Sr->startdate ?? ''));
            $p2SYear = 0; $p2SSem = '';
            if (strlen($p2SSd) === 8) {
                $p2SMon = (int)substr($p2SSd, 2, 2); $p2SYr = (int)substr($p2SSd, 4, 4);
                if ($p2SYr >= 2000 && $p2SMon >= 1 && $p2SMon <= 12) {
                    $p2SYear = $p2SYr; $p2SSem = ($p2SMon <= 6) ? 'S1' : 'S2';
                }
            }
            $p2Sk = ($p2SFam !== '' ? $p2SFam : '__unk__' . $p2SQcUp) . '|||' . $p2SYear . '|||' . $p2SSem;
            if (!isset($p2Samples[$p2Sk]) || count($p2Samples[$p2Sk]) < 3) {
                // Prefer firstname+familyname; fall back to composite name from NAT00080.
                $p2Nm = trim(($p2Sr->firstname ?? '') . ' ' . ($p2Sr->familyname ?? ''));
                if ($p2Nm === '' || $p2Nm === ' ') {
                    $p2Nm = trim($p2Sr->name ?? '');
                }
                if ($p2Nm !== '' && $p2Nm !== ' ') $p2Samples[$p2Sk][] = $p2Nm;
            }
        }
        $p2SRs->close();
    } catch (Exception $e) { /* safe */ }

    // ── 6. Sort: auto → review → manual; within each group: family, year DESC, sem ──
    $p2SortOrd = ['auto' => 0, 'review' => 1, 'manual' => 2];
    uasort($p2Groups, function ($a, $b) use ($p2SortOrd) {
        $sa = $p2SortOrd[$a['status']] ?? 2;
        $sb = $p2SortOrd[$b['status']] ?? 2;
        if ($sa !== $sb) return $sa - $sb;
        $fc = strcmp($a['family'], $b['family']);
        if ($fc !== 0) return $fc;
        if ($b['year'] !== $a['year']) return $b['year'] - $a['year'];
        return strcmp($a['sem'], $b['sem']);
    });

    $p2NAuto   = count(array_filter($p2Groups, fn($g) => $g['status'] === 'auto'));
    $p2NReview = count(array_filter($p2Groups, fn($g) => $g['status'] === 'review'));
    $p2NManual = count(array_filter($p2Groups, fn($g) => $g['status'] === 'manual'));

    $p2ImportRec = $DB->get_record('local_rtocompliance_avetmiss', ['id' => $importid]);
    $p2YearLbl   = $p2ImportRec && $p2ImportRec->collectionyear
        ? $p2ImportRec->collectionyear . ' Collection' : 'AVETMISS Import';

    $formurl  = new moodle_url('/local/rtocompliance/data_import.php');
    $skipurl  = new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid]);
    $p2JsSkip = json_encode($skipurl->out(false));
    $p2JsNAuto = (int)$p2NAuto;

    // ── 7. Render ───────────────────────────────────────────────────────────────

    // Step progress indicator.
    echo '<div class="rtoc-step-bar mb-4">';
    echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Upload NAT Files</span></div>';
    echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
    echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Review &amp; Confirm</span></div>';
    echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
    echo '<div class="rtoc-step rtoc-step-active"><span class="rtoc-step-num">3</span><span class="rtoc-step-lbl">Enrol into Courses <em class="text-muted">(optional)</em></span></div>';
    echo '</div>';

    // ── Step 3 context banner ─────────────────────────────────────────────────
    echo '<div class="alert alert-secondary mb-3 py-2 px-3 small">';
    echo '<strong>Step 3 — Enrol into Courses (only for currently in-progress students).</strong> ';
    echo 'This step creates Moodle accounts for students who don\'t have one, enrols them in their unit courses, '
        . 'and creates their <strong>Student Record</strong> so certificates can be issued.<br>';
    echo '<strong>Who gets enrolled?</strong> Only students who have at least one unit still ';
    echo '<strong>in progress</strong> (AVETMISS outcome code <code>70</code> — Continuing Enrolment). ';
    echo 'These are the students actively working towards their certificate who may be audited by ASQA.<br>';
    echo '<strong>Who is skipped?</strong> Students with <em>only</em> terminal outcomes — completed (20), ';
    echo 'withdrawn (40), not-yet-achieved (30), RPL (51/52/53), credit transfer (60/61), etc. ';
    echo 'These students do not need Moodle access. Use ';
    $bfUrl = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'backfill_records']);
    echo html_writer::link($bfUrl, 'Backfill Qual Builder', ['class' => 'alert-link font-weight-bold']);
    echo ' instead — it creates Student Records and qualification history for completed/withdrawn students ';
    echo 'without enrolling them in any course (for certificate re-issue purposes).';
    echo '</div>';

    // Archive index warning banners.
    if ($p2ArchiveCount === 0) {
        echo '<div class="alert alert-danger mb-3">';
        echo '<strong>&#9888; Archive index is empty &mdash; action required before you can enrol.</strong>';
        echo '<p class="mb-1 mt-2">The archive index maps your Moodle course categories to qualification families so the importer can automatically match each NAT intake to the right category. '
           . 'Until the index is built, every intake is classified as <strong>MANUAL</strong> and cannot be auto-enrolled.</p>';
        echo '<p class="mb-1"><strong>What to do:</strong></p>';
        echo '<ol class="mb-2">';
        echo '<li>Click <strong><a href="' . s($p2ArchiveAdminUrl) . '">Open Archive Index Manager</a></strong>.</li>';
        echo '<li>On that page, click the <strong>&ldquo;Rebuild Archive Index&rdquo;</strong> button — it scans your Moodle category tree automatically (takes a few seconds).</li>';
        echo '<li>If any categories are listed under <em>Unassigned</em>, assign them a qualification family before returning here.</li>';
        echo '<li>Come back to this page and re-upload your NAT file.</li>';
        echo '</ol>';
        echo '<a href="' . s($p2ArchiveAdminUrl) . '" class="btn btn-danger btn-sm" title="Open the Archive Index Manager to assign qualification families">Open Archive Index Manager &rarr;</a>';
        echo '</div>';
    } elseif ($p2IndexIsStale) {
        // Phase 3: Stale index warning — non-blocking, just informs the admin.
        echo '<div class="alert alert-warning mb-3">';
        echo '<strong>&#9888; Archive index may be out of date</strong>';
        echo ' (last rebuilt ' . $p2IndexDaysOld . ' day' . ($p2IndexDaysOld !== 1 ? 's' : '') . ' ago). ';
        echo 'If Moodle categories were added or renamed recently, '
           . '<a href="' . s($p2ArchiveAdminUrl) . '">rebuild the archive index</a> to pick them up before enrolling.';
        echo '</div>';
    }

    // Summary badges.
    echo '<div class="d-flex align-items-center flex-wrap mb-3" style="gap:0.6rem">';
    if ($p2NAuto > 0) {
        echo '<span class="badge badge-success py-2 px-3" style="font-size:0.875rem" title="Auto means these groups are fully matched to a Moodle course and can be enrolled with no admin action.">&#10003; ' . $p2NAuto
           . ' AUTO — ready to enrol</span>';
    }
    if ($p2NReview > 0) {
        echo '<span class="badge badge-warning py-2 px-3" style="font-size:0.875rem" title="Review means a course was found but needs an admin to check or fix it before students can be enrolled.">&#9888; ' . $p2NReview
           . ' REVIEW — admin action needed</span>';
    }
    if ($p2NManual > 0) {
        echo '<span class="badge badge-danger py-2 px-3" style="font-size:0.875rem" title="Manual means no matching Moodle course was found. These students are skipped until someone sets them up by hand.">&#10007; ' . $p2NManual
           . ' MANUAL — not matched</span>';
    }
    if (empty($p2Groups)) {
        echo '<span class="text-muted">No qualification enrolment data found in this import.</span>';
    }
    echo '</div>';

    // ── Step 3 plain-language help block ─────────────────────────────────────
    echo '<div class="card mb-4 border-0 bg-light">';
    echo '<div class="card-body py-3 px-4">';
    echo '<h6 class="mb-3"><strong>&#8505; How does Step 3 work? What do the colours mean?</strong></h6>';
    echo '<div class="row mb-3">';

    echo '<div class="col-md-4 mb-2">';
    echo '<span class="badge badge-success">&#10003; AUTO</span>';
    echo '<p class="mb-0 mt-1 small">The Archive Index matched this qualification + year to a Moodle course category '
        . 'that has at least one visible course. Students in this group will be processed automatically. '
        . 'No admin action needed — just include the card and click <strong>Enrol Now</strong>.</p>';
    echo '</div>';

    echo '<div class="col-md-4 mb-2">';
    echo '<span class="badge badge-warning">&#9888; REVIEW</span>';
    echo '<p class="mb-0 mt-1 small">A Moodle category was found, but it is either hidden or has no visible courses. '
        . 'Students cannot be enrolled here until you fix the visibility. '
        . 'Either make the category / its courses visible in Moodle, or expand the card and choose a different category.</p>';
    echo '</div>';

    echo '<div class="col-md-4 mb-2">';
    echo '<span class="badge badge-danger">&#10007; MANUAL</span>';
    echo '<p class="mb-0 mt-1 small">No matching Moodle category was found in the Archive Index. '
        . 'Students in MANUAL groups are <strong>skipped entirely</strong> — no accounts created, no enrolments, no Student Records. '
        . 'Fix by rebuilding the Archive Index, or expand the card and assign a category manually. '
        . 'Alternatively, use <a href="' . s($bfUrl->out(false)) . '">Backfill</a> to add these students to Student Records without course access.</p>';
    echo '</div>';

    echo '</div>';

    echo '<hr class="my-2">';
    echo '<h6 class="mb-2"><strong>How does the system match each student to a Moodle account?</strong></h6>';
    echo '<ol class="mb-2 small pl-4">';
    echo '<li class="mb-1"><strong>Email match</strong> — finds an existing Moodle account whose email matches the student\'s email from NAT00085. '
        . 'This is the preferred method when NAT00085 is uploaded.</li>';
    echo '<li class="mb-1"><strong>Client ID match</strong> — if email fails, looks for a Moodle account whose username or ID number equals the student\'s Client ID.</li>';
    echo '<li class="mb-1"><strong>Auto-create account</strong> — if no existing account is found by either method, a new Moodle account is created automatically '
        . '(username = Client ID; email from NAT00085 or a placeholder if none is available).</li>';
    echo '<li class="mb-1"><strong>Skip</strong> — if account creation fails for a technical reason (very rare), the student is logged in the skip report but not enrolled. '
        . 'Check the skip report shown after enrolment for names and reasons.</li>';
    echo '</ol>';

    echo '<hr class="my-2">';
    echo '<h6 class="mb-2"><strong>What gets created for each enrolled student?</strong></h6>';
    echo '<ul class="mb-2 small pl-4">';
    echo '<li>Enrolled into Moodle courses inside the matched category. With <strong>Unit-accurate enrolment</strong> on: only the specific units in their NAT00120 file. With it off: every visible course in the category.</li>';
    echo '<li>A <strong>Student Record</strong> is created in RTO Compliance so certificates can be issued.</li>';
    echo '<li><strong>USI and date of birth</strong> from the NAT file are written directly to the Student Record.</li>';
    echo '<li>If NAT00130 shows a <strong>Competent outcome (code 20)</strong> for a unit, '
        . 'the matching Moodle course is immediately marked as complete — no manual grading needed.</li>';
    echo '<li>Students already enrolled in a course are silently skipped (no duplicate enrolments).</li>';
    echo '</ul>';

    echo '<div class="alert alert-secondary py-2 px-3 mb-0 small">';
    echo '<strong>For historical students</strong> who have already completed their training and do not need Moodle course access, '
        . 'skip Step 3 and use ';
    echo '<a href="' . s($bfUrl->out(false)) . '" class="alert-link font-weight-bold">Backfill Student Records</a>';
    echo ' instead. Backfill reads every student from all your NAT uploads, creates their Student Record '
        . '(and a Moodle account if needed), and does <strong>not</strong> touch any course enrolments.';
    echo '</div>';

    echo '</div>'; // card-body
    echo '</div>'; // card
    // ── end help block ────────────────────────────────────────────────────────

    if (!empty($p2Groups)) {

        echo '<form id="rtoc-autoenrol-form" method="post" action="' . $formurl->out(false) . '">';
        echo '<input type="hidden" name="sesskey"      value="' . sesskey() . '">';
        echo '<input type="hidden" name="action"       value="doenrol">';
        echo '<input type="hidden" name="importid"     value="' . (int)$importid . '">';
        echo '<input type="hidden" name="match_method" value="' . s($p2MatchMethod) . '">';

        // ENROL-UNIT-ACCURATE (v5.9.57): Targeted enrolment toggle.
        // Let the admin choose between "all courses in category" and "only the student's own units".
        echo '<div class="card mb-3" style="border:1px solid #17a2b8">';
        echo '<div class="card-body py-2 px-3 d-flex align-items-start flex-wrap" style="gap:0.75rem">';
        echo '<div class="custom-control custom-switch mt-1 flex-shrink-0">';
        echo '<input type="checkbox" class="custom-control-input" id="targeted_enrol_toggle" '
            . 'name="targeted_enrol" value="1" checked>';
        echo '<label class="custom-control-label" for="targeted_enrol_toggle"></label>';
        echo '</div>';
        echo '<div>';
        echo '<strong style="color:#117a8b">Unit-accurate enrolment</strong> '
            . '<span class="badge badge-info ml-1">Recommended</span><br>';
        echo '<small class="text-muted">'
            . '<strong>On (recommended):</strong> each student is only enrolled into Moodle courses '
            . 'whose <strong>Course ID number</strong> matches a unit code in their NAT00120 file. '
            . 'Students are not enrolled into units they are not actually studying.<br>'
            . '<strong>Off:</strong> every student is enrolled into <em>all</em> visible courses '
            . 'in the matched category, regardless of their individual unit enrolments.</small>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Human-readable family labels are derived generically from the family slug
        // (v5.9.457) — e.g. "my_diploma" → "My Diploma". No provider-specific
        // qualification names are hardcoded; the fallback below humanises any slug.
        $p2FamLabels = [];

        foreach ($p2Groups as $p2Gk => $p2G) {
            $p2GFam      = $p2G['family'];
            $p2GYear     = (int)$p2G['year'];
            $p2GSem      = $p2G['sem'];
            $p2GStudents = (int)$p2G['studentcount'];
            $p2GStatus   = $p2G['status'];
            $p2GQcStr    = htmlspecialchars(implode(', ', $p2G['qualcodes']), ENT_QUOTES);
            $p2GArchRow  = $p2G['archive_row'];
            $p2GSampleArr = $p2Samples[$p2Gk] ?? [];

            $p2GPeriod   = ($p2GYear > 0)
                ? $p2GYear . ($p2GSem !== '' ? ' ' . $p2GSem : '')
                : 'Unknown period';
            $p2GFamLbl   = $p2FamLabels[$p2GFam]
                ?? ($p2GFam !== '' ? ucwords(str_replace('_', ' ', $p2GFam)) : 'Unknown (' . $p2GQcStr . ')');

            // Card styling by status.
            if ($p2GStatus === 'auto') {
                $p2CardBorder = '#28a745';
                $p2HdrBg     = '#d4edda';
                $p2HdrColor  = '#155724';
                $p2Badge     = '<span class="badge badge-success ml-1" title="Ready to enrol automatically. This group is matched to a Moodle course and needs no admin action.">&#10003; AUTO</span>';
            } elseif ($p2GStatus === 'review') {
                $p2CardBorder = '#ffc107';
                $p2HdrBg     = '#fff3cd';
                $p2HdrColor  = '#856404';
                $p2Badge     = '<span class="badge badge-warning ml-1" title="A course was found but needs an admin to check or fix it before these students can be enrolled.">&#9888; REVIEW</span>';
            } else {
                $p2CardBorder = '#dc3545';
                $p2HdrBg     = '#f8d7da';
                $p2HdrColor  = '#721c24';
                $p2Badge     = '<span class="badge badge-danger ml-1" title="No matching Moodle course was found. These students are skipped until someone sets them up by hand.">&#10007; MANUAL</span>';
            }

            echo '<div class="card mb-3" style="border:2px solid ' . $p2CardBorder . '">';
            echo '<div class="card-header d-flex justify-content-between align-items-center flex-wrap"'
               . ' style="background:' . $p2HdrBg . ';color:' . $p2HdrColor . ';gap:0.5rem">';
            echo '<div><strong>' . htmlspecialchars($p2GFamLbl, ENT_QUOTES) . '</strong>'
               . ' &mdash; <strong>' . htmlspecialchars($p2GPeriod, ENT_QUOTES) . '</strong>'
               . ' <span class="text-muted" style="font-size:0.85rem;font-weight:normal">'
               . '(' . $p2GStudents . ' student' . ($p2GStudents !== 1 ? 's' : '') . ')'
               . '</span>' . $p2Badge . '</div>';
            echo '<small style="font-size:0.78rem;opacity:0.7">' . $p2GQcStr . '</small>';
            echo '</div>'; // card-header
            echo '<div class="card-body py-2 px-3">';

            if ($p2GStatus === 'auto' && $p2GArchRow) {
                // Show matched category details.
                $p2CatPath = htmlspecialchars($p2GArchRow->fullpath ?? $p2GArchRow->categoryname, ENT_QUOTES);
                echo '<div class="d-flex align-items-start flex-wrap mb-1" style="gap:1rem">';
                echo '<div style="min-width:0;flex:1">';
                echo '<small class="text-muted d-block">Moodle category</small>';
                echo '<strong>' . $p2CatPath . '</strong>';
                if ($p2G['annual_fallback'] ?? false) {
                    echo ' <span class="badge badge-info" style="font-size:0.73rem;vertical-align:middle" title="This qualification was matched to its yearly intake category rather than an exact semester, because no exact match was found.">annual intake</span>';
                }
                echo '<br><small class="text-muted">'
                   . $p2G['course_count'] . ' course' . ($p2G['course_count'] !== 1 ? 's' : '')
                   . ' &nbsp;&#183;&nbsp; '
                   . $p2G['enrol_count']  . ' enrolment method' . ($p2G['enrol_count']  !== 1 ? 's' : '')
                   . '</small>';
                echo '</div>';
                if (!empty($p2GSampleArr)) {
                    echo '<div style="font-size:0.82rem;color:#6c757d;align-self:center">';
                    echo 'e.g. ' . htmlspecialchars(implode(', ', $p2GSampleArr), ENT_QUOTES);
                    echo '</div>';
                }
                echo '</div>';

                // Hidden doenrol inputs — one entry per qual code in this family group,
                // all pointing to the same archive category and year/sem.
                foreach ($p2G['qualcodes'] as $p2HQc) {
                    echo '<input type="hidden" name="qualcodes[]"  value="' . htmlspecialchars($p2HQc, ENT_QUOTES) . '">';
                    echo '<input type="hidden" name="categories[]" value="' . (int)$p2GArchRow->categoryid . '">';
                    echo '<input type="hidden" name="yearsems[]"   value="' . htmlspecialchars($p2GPeriod, ENT_QUOTES) . '">';
                }

            } elseif ($p2GStatus === 'review') {
                if ($p2GArchRow) {
                    $p2CatPath = htmlspecialchars($p2GArchRow->fullpath ?? $p2GArchRow->categoryname, ENT_QUOTES);
                    echo '<small class="text-muted">Matched category:</small> <strong>' . $p2CatPath . '</strong><br>';
                }
                echo '<div class="alert alert-warning py-1 px-2 mt-1 mb-0" style="font-size:0.85rem">';
                echo $p2G['status_reason'];
                echo '</div>';

            } else { // manual
                echo '<div class="alert alert-danger py-1 px-2 mb-0" style="font-size:0.85rem">';
                echo $p2G['status_reason'];
                echo '</div>';
                if (!empty($p2GSampleArr)) {
                    echo '<small class="text-muted d-block mt-1">';
                    echo 'Students: ' . htmlspecialchars(implode(', ', $p2GSampleArr), ENT_QUOTES);
                    echo '</small>';
                }
            }

            echo '</div>'; // card-body
            echo '</div>'; // card
        }

        // Submit footer.
        echo '<div class="d-flex align-items-center flex-wrap mt-3 mb-4" style="gap:1rem">';
        if ($p2NAuto > 0) {
            echo '<button type="submit" id="rtoc-enrol-submit-btn" class="btn btn-success btn-lg" title="Confirm and enrol the matched students into their courses">';
            echo '&#10003; Confirm &amp; Enrol (' . $p2NAuto . ' intake' . ($p2NAuto !== 1 ? 's' : '') . ')</button>';
        }
        echo '<a href="' . $skipurl->out() . '" class="btn btn-outline-secondary">';
        echo 'Skip &mdash; go to import results</a>';
        if ($p2NAuto > 0) {
            echo '<small class="text-muted">Students without a Moodle account will have one created automatically.</small>';
        }
        if ($p2NReview > 0 || $p2NManual > 0) {
            // Phase 3: Re-check button — lets admin fix REVIEW/MANUAL issues then quickly reload.
            $p2RecheckUrl = (new moodle_url('/local/rtocompliance/data_import.php', [
                'action'   => 'autoenrol',
                'importid' => $importid,
            ]))->out(false);
            echo '<a href="' . s($p2RecheckUrl) . '" class="btn btn-outline-info">&#8635; Re-check after fixing</a>';
            if ($p2NAuto === 0) {
                echo '<small class="text-muted">Fix REVIEW / MANUAL items above, then re-check to enable enrolment.</small>';
            }
        }
        echo '</div>';

        // Submit guard JS.
        echo '<script>';
        echo '(function () {';
        echo '  var form = document.getElementById("rtoc-autoenrol-form");';
        echo '  if (!form) return;';
        echo '  form.addEventListener("submit", function (e) {';
        echo '    e.preventDefault();';
        echo '    var skUrl = ' . $p2JsSkip . ';';
        echo '    var qi = form.querySelectorAll("input[name=\'qualcodes[]\']");';
        echo '    if (qi.length === 0) {';
        echo '      if (confirm("No AUTO matches \u2014 no students will be enrolled.\nClick OK to skip to results.")) {';
        echo '        window.location.href = skUrl;';
        echo '      }';
        echo '      return;';
        echo '    }';
        echo '    var n = ' . $p2JsNAuto . ';';
        echo '    var msg = "Enrol students from " + n + " matched archive intake" + (n !== 1 ? "s" : "") + "?\n\nClick OK to enrol, or Cancel to review.";';
        echo '    if (confirm(msg)) form.submit();';
        echo '  });';
        echo '})();';
        echo '</script>';

        echo '</form>';

    } // end if !empty($p2Groups)

    // Fix Student Names button (outside main form, always shown).
    $p2RepairUrl = new moodle_url('/local/rtocompliance/data_import.php');
    echo '<div class="alert alert-info d-flex align-items-start mt-4 mb-4" style="gap:0.75rem">';
    echo '<span style="font-size:1.25rem;line-height:1.4">&#9998;</span>';
    echo '<div>';
    echo '<strong>Fix placeholder student names now</strong><br>';
    echo '<span class="text-muted small">If any student accounts still show &ldquo;Student 0000005776&rdquo;, '
       . 'click the button below. The NAT00080 data from this import is saved and the tool will update '
       . 'all placeholder accounts immediately.</span>';
    echo '<br><form method="post" action="' . $p2RepairUrl->out(false) . '" style="display:inline;margin-top:0.5rem">';
    echo '<input type="hidden" name="sesskey"  value="' . sesskey() . '">';
    echo '<input type="hidden" name="action"   value="repairnames">';
    echo '<input type="hidden" name="importid" value="' . (int)$importid . '">';
    echo '<button type="submit" class="btn btn-info mt-1" title="Repair student names on the affected records now">Fix Student Names Now</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

// ── CONFIRMATION VIEW ─────────────────────────────────────────────────────────
// USI-NOMORE-PICKER (v4.9.158): The USI column picker has been removed entirely.
// USI detection now runs silently in the background using the existing auto-detect
// algorithm. The admin never needs to make a decision about USI — it is either
// found automatically or the import proceeds without it. This matches how other
// AVETMISS-compliant student management systems handle USI data.
} elseif ($pendingConfirm && !empty($SESSION->rtocompliance_nat_pending)) {

    $pending = $SESSION->rtocompliance_nat_pending;
    $formurl = new moodle_url('/local/rtocompliance/data_import.php');

    // Step bar.
    echo '<div class="rtoc-step-bar mb-4">';
    echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Upload NAT Files</span></div>';
    echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
    echo '<div class="rtoc-step rtoc-step-active"><span class="rtoc-step-num">2</span><span class="rtoc-step-lbl">Review &amp; Confirm</span></div>';
    echo '<div class="rtoc-step-line"></div>';
    echo '<div class="rtoc-step"><span class="rtoc-step-num">3</span><span class="rtoc-step-lbl">Enrol into Courses <em class="text-muted">(optional)</em></span></div>';
    echo '</div>';

    echo '<div class="alert alert-info mb-3 py-2 px-3">';
    echo '<strong>Step 2 — Review your data, then click Confirm &amp; Import.</strong><br>';
    echo '<span class="small">Check that the student records below look correct. '
        . 'When you confirm, all student data is saved to the RTO Compliance database — but <strong>no Moodle accounts are created yet</strong> and <strong>no course enrolments happen yet</strong>. '
        . 'That is the optional Step 3 (Enrol into Courses), which you can run for current students who need Moodle access. '
        . 'Historical/completed students who just need certificates do not need Step 3 — use the Backfill tool instead.</span>';
    echo '</div>';

    echo '<form method="post" action="' . $formurl->out(false) . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    // MATCH-METHOD: carry the chosen method through to finalizenat.
    $currentMatchMethod = (($SESSION->rtocompliance_nat_match_method ?? 'email') === 'studentid')
        ? 'studentid' : 'email';
    echo '<input type="hidden" name="match_method" value="' . $currentMatchMethod . '">';

    foreach ($pending['groups'] as $idx => $grp) {
        $filenames  = array_map(fn($f) => $f['name'], $grp['files']);
        $effUsiPos  = ($grp['usiposoverride'] >= 0) ? $grp['usiposoverride'] : $grp['usiposdetected'];

        // FIX-NATUPLOAD-SESSION-SIZE (v4.9.144): nat80content now stored on disk.
        $nat80forPreview = (!empty($grp['nat80tmppath']) && file_exists($grp['nat80tmppath']))
            ? @file_get_contents($grp['nat80tmppath'])
            : ($grp['nat80content'] ?? '');

        $nat80lines = ($nat80forPreview !== '' && $nat80forPreview !== false)
            ? array_filter(explode("\n", $nat80forPreview), fn($l) => trim($l) !== '')
            : [];

        $previewRows = !empty($nat80lines)
            ? local_rtocompliance_preview_nat00080_records($nat80forPreview, $effUsiPos, 12, false)
            : [];

        // Detect which file types are present in this batch.
        $batchTypes = [];
        foreach ($grp['files'] as $f) {
            $t = local_rtocompliance_get_nat_type($f['name']);
            if ($t) $batchTypes[$t] = true;
        }
        $hasNat85 = isset($batchTypes['NAT00085']);
        $hasNat80 = isset($batchTypes['NAT00080']) || isset($batchTypes['NAT00130']);

        // Pass the auto-detected USI position through to finalizenat unchanged.
        echo '<input type="hidden" name="usipos_' . $idx . '" value="' . (int)$effUsiPos . '">';

        echo '<div class="card mb-4">';
        echo '<div class="card-header d-flex align-items-center justify-content-between">';
        echo '<h5 class="mb-0">Batch ' . ($idx + 1) . '</h5>';
        echo '<small class="text-muted">' . s(implode(', ', $filenames)) . '</small>';
        echo '</div>';
        echo '<div class="card-body">';

        // ── Missing NAT00085 warning ───────────────────────────────────────────
        // Without NAT00085 there are no real email addresses. Auto-switch the
        // session match method to 'studentid' so the enrol step works without emails
        // — students are matched by Client ID and accounts created automatically.
        if (!$hasNat85) {
            $SESSION->rtocompliance_nat_match_method = 'studentid';
            echo '<div class="alert alert-warning mb-3">';
            echo '<strong>&#9888; No contact details file included (NAT00085).</strong><br>';
            echo 'Student records will be saved without email addresses. The matching method has been automatically ';
            echo 'set to <strong>By student number</strong> — students will be matched to their Moodle accounts ';
            echo '(or new accounts created) using their Client ID. You can still proceed to enrol students in the next step.';
            echo '</div>';
        }

        // ── USI status (informational only — no user action needed) ──────────
        $usiFound    = count(array_filter($previewRows, fn($r) => !empty($r['usi'])));
        $usiTotal    = count($previewRows);
        // FIX-USI-DETECT-PREVIEW-MISS (v5.2.79): Previously the success banner required
        // BOTH a valid detected position AND at least one USI visible in the first 12
        // preview rows.  When all USI-bearing students happen to fall beyond row 12 (e.g.
        // Wisenet exports where clients 14, 20, 24, 27 are USI-holders but the first 12
        // client IDs are 2–13), $usiFound was 0 and the banner incorrectly showed "No USI
        // codes detected" even though detection correctly found position 149.
        // Fix: trigger the success banner whenever detection found a valid column position,
        // regardless of how many USIs appear in the capped preview rows.
        $usiDetected = ($effUsiPos >= 0 || $effUsiPos === -2);

        if ($usiDetected) {
            echo '<div class="alert alert-success py-2 mb-3" style="font-size:0.9rem">';
            echo '<strong>&#10003; USI column detected automatically</strong> — ';
            if ($usiFound > 0) {
                echo 'found for ' . $usiFound . ' of the first ' . $usiTotal . ' students shown below.';
            } else {
                echo 'no USI codes visible in the first ' . $usiTotal . ' students previewed, '
                    . 'but USIs will be read from the full file during import.';
            }
            if ($effUsiPos === -2) {
                // Pull a real USI example from the preview rows to show the admin.
                $usiExample = '';
                foreach ($previewRows as $pr) {
                    if (!empty($pr['usi']) && preg_match('/^[2-9A-HJ-NP-Z]{10}$/', $pr['usi'])) {
                        $usiExample = $pr['usi'];
                        break;
                    }
                }
                echo ' <span class="badge badge-light border ml-1" style="font-size:0.85rem;font-family:monospace">'
                    . 'Tab-delimited format &mdash; USI is in column&nbsp;3'
                    . ($usiExample !== '' ? ' (e.g.&nbsp;<strong>' . s($usiExample) . '</strong>)' : '')
                    . '</span>';
            }
            if ($usiFound > 0 && $usiFound < $usiTotal) {
                echo ' Students without a USI in the file will be imported with USI left blank — this is normal.';
            }
            echo '</div>';
        } else {
            echo '<div class="alert alert-info py-2 mb-3" style="font-size:0.9rem">';
            echo '<strong>No USI codes detected in this file.</strong> ';
            echo 'Students will be imported without a USI. This will not prevent the import or enrolment.';
            echo '</div>';
        }

        // ── Preview table ─────────────────────────────────────────────────────
        // FIX-PREVIEW-ROW-COUNT (v4.9.163): was max(count,12) which printed "First 12
        // student records" even when only 2–3 rows were present, making admins think
        // data was missing from their upload.
        $previewRowCount = count($previewRows);
        echo '<h6 class="font-weight-bold mb-2">First ' . $previewRowCount . ' student record' . ($previewRowCount !== 1 ? 's' : '') . '</h6>';
        echo '<p class="small text-muted mb-2">Check that these look right. If students are missing or the names look wrong, cancel and check your NAT00080 file.</p>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-bordered mb-0">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS client identifier">Client ID</th><th title="Student name">Name</th><th title="Student date of birth">Date of Birth</th><th title="Unique Student Identifier">USI</th>';
        echo '</tr></thead><tbody>';
        if (empty($previewRows)) {
            if (!$hasNat80) {
                echo '<tr><td colspan="4" class="text-center text-warning py-3">';
                echo 'No NAT00080 or NAT00130 file was found in this batch. Student records cannot be previewed.';
                echo '</td></tr>';
            } else {
                echo '<tr><td colspan="4" class="text-center text-muted py-3">No student records could be read from the file.</td></tr>';
            }
        }
        foreach ($previewRows as $r) {
            $hasUsi = !empty($r['usi']);
            echo '<tr>';
            echo '<td><code class="small">' . s($r['clientid']) . '</code></td>';
            echo '<td class="small">' . s(substr($r['name'] ?? '', 0, 40)) . '</td>';
            $dob = !empty($r['dob']) ? local_rtocompliance_format_ddmmyyyy($r['dob']) : '—';
            echo '<td class="text-muted small">' . $dob . '</td>';
            echo $hasUsi
                ? '<td><code class="text-success">' . s($r['usi']) . '</code></td>'
                : '<td class="text-muted small">—</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // table-responsive

        if (!empty($previewRows)) {
            echo '<p class="small text-muted mt-1 mb-0">Showing first ' . count($previewRows) . ' records. All records in the file will be imported.</p>';
        }

        echo '</div>'; // card-body
        echo '</div>'; // card
    }

    // Action buttons — single clear CTA, no "Update Preview" needed.
    echo '<div class="d-flex align-items-center mb-4" style="gap:1rem;flex-wrap:wrap">';
    echo '<button type="submit" name="action" value="finalizenat" class="btn btn-primary btn-lg" title="Confirm and import the NAT data into your student records">';
    echo '&#10003; Confirm &amp; Import</button>';
    echo '<a href="' . $formurl->out() . '" class="btn btn-outline-secondary">Cancel &amp; re-upload</a>';
    echo '</div>';

    echo '</form>';

    // v5.9.372 DATA-IMPORT-DECLUTTER: the "Fix Student Names" tool repaired names on
    // auto-created Moodle accounts. The import no longer creates Moodle accounts, so it
    // has been removed.

// ── DETAIL VIEW ───────────────────────────────────────────────────────────────
} elseif ($importid) {
    $imp = $DB->get_record('local_rtocompliance_avetmiss', ['id' => $importid], '*', MUST_EXIST);

    // Back link + delete button
    echo '<div class="rto-flex rto-flex-between rto-mb-4">';
    echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php'))->out() . '" class="btn btn-secondary btn-sm">';
    echo '&larr; ' . get_string('dataimport_back', 'local_rtocompliance');
    echo '</a>';
    $deleteurl = new moodle_url('/local/rtocompliance/data_import.php', [
        'action'   => 'delete',
        'importid' => $importid,
        'sesskey'  => sesskey(),
    ]);
    echo '<a href="' . $deleteurl->out() . '" class="btn btn-danger btn-sm" '
        . 'title="Permanently delete this import and its records" '
        . 'onclick="return confirm(\'' . get_string('dataimport_confirm_delete', 'local_rtocompliance') . '\')">'
        . get_string('dataimport_delete', 'local_rtocompliance') . '</a>';

    // v5.9.371 NAT-RESULTS-REGISTER: download the students in this file who are not yet in
    // your system (so their results could not be imported into the register). Read-only.
    $unmatchedUrl = new moodle_url('/local/rtocompliance/data_import.php', [
        'action'   => 'export_unmatched',
        'importid' => $importid,
    ]);
    echo '<a href="' . $unmatchedUrl->out() . '" class="btn btn-outline-secondary btn-sm" '
        . 'title="CSV of students in this NAT file who have no record in your system yet">'
        . '&#8681; Download unmatched students</a>';

    // v5.9.372 DATA-IMPORT-DECLUTTER: the Rollback and Fix-Over-Enrolments buttons and
    // the rollback status panel were auto-enrol cleanup tools. The import no longer creates
    // Moodle enrolments, so there is nothing to roll back or over-enrol — removed.
    echo '</div>';

    // ── Post-enrolment results report ────────────────────────────────────────
    if ($autoenrol_done) {
        global $SESSION;
        $reportKey = 'rtoc_ae_report_' . $importid;
        $report    = !empty($SESSION->$reportKey) ? json_decode($SESSION->$reportKey, true) : null;

        $rEnrolled         = $report ? (int)$report['totalenrolled']          : (int)$enrolled_count;
        $rAlready          = $report ? (int)$report['totalskipalready']       : 0;
        $rMatched          = $report ? (int)($report['totalmatched'] ?? 0)    : 0;
        $rMatchedFallback  = $report ? (int)($report['totalmatched_fallback'] ?? 0) : 0;
        $rCreated          = $report ? (int)($report['totalcreated'] ?? 0)    : 0;
        $rMatchMethod      = $report ? ($report['matchMethod'] ?? 'email')    : 'email';
        $rSkipped          = $report ? $report['skipped']                     : [];
        $rSkipCount        = count($rSkipped);

        // Step bar — all three steps done.
        echo '<div class="rtoc-step-bar mb-4">';
        echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Upload NAT Files</span></div>';
        echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
        echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Review &amp; Confirm</span></div>';
        echo '<div class="rtoc-step-line rtoc-step-line-done"></div>';
        echo '<div class="rtoc-step rtoc-step-done"><span class="rtoc-step-num">&#10003;</span><span class="rtoc-step-lbl">Enrol into Courses</span></div>';
        echo '</div>';

        // ── Enrolled count (green / amber) ───────────────────────────────────
        $borderClass = $rEnrolled > 0 ? 'border-success' : 'border-warning';
        echo '<div class="card ' . $borderClass . ' mb-3">';
        echo '<div class="card-body py-3">';
        echo '<div class="d-flex align-items-start" style="gap:1rem">';
        echo '<span style="font-size:2rem;color:' . ($rEnrolled > 0 ? '#28a745' : '#ffc107') . '">' . ($rEnrolled > 0 ? '&#10003;' : '&#9888;') . '</span>';
        echo '<div style="flex:1">';
        if ($rEnrolled > 0) {
            echo '<h5 class="mb-2 text-success">' . $rEnrolled . ' student' . ($rEnrolled !== 1 ? 's' : '') . ' enrolled into Moodle</h5>';
        } else {
            echo '<h5 class="mb-2" style="color:#856404">No students were enrolled this run</h5>';
        }

        // ── Breakdown pills ──
        $pills = [];
        if ($rMatched > 0) {
            $methodLabel = $rMatchMethod === 'studentid' ? 'student ID' : 'email';
            $pills[] = '<span class="badge badge-success mr-1" title="Existing Moodle accounts found and linked to the national records, so no new account was needed.">' . $rMatched . ' matched by ' . $methodLabel . '</span>';
        }
        if ($rMatchedFallback > 0) {
            $fbLabel = $rMatchMethod === 'studentid' ? 'email (fallback)' : 'student ID (fallback)';
            $pills[] = '<span class="badge badge-info mr-1" title="Students found by a second matching method after the first one did not find them.">' . $rMatchedFallback . ' matched by ' . $fbLabel . '</span>';
        }
        if ($rCreated > 0) {
            $pills[] = '<span class="badge badge-primary mr-1" title="New Moodle accounts created for students who did not already have one.">' . $rCreated . ' new account' . ($rCreated !== 1 ? 's' : '') . ' created</span>';
        }
        if ($rAlready > 0) {
            $pills[] = '<span class="badge badge-secondary mr-1" title="Students already enrolled in the course, so they were left unchanged.">' . $rAlready . ' already enrolled (skipped)</span>';
        }
        if (!empty($pills)) {
            echo '<div class="mb-2">' . implode('', $pills) . '</div>';
        }

        if ($rEnrolled === 0 && $rAlready > 0 && $rSkipCount === 0) {
            echo '<p class="mb-0 text-muted small">All students from the selected qualification(s) were already enrolled — nothing to do.</p>';
        } elseif ($rEnrolled === 0 && $rSkipCount === 0) {
            echo '<p class="mb-0 text-muted small">No courses were matched to a qualification, so no enrolments were attempted. Go back and select a course for each program code.</p>';
        } elseif ($rEnrolled > 0 && $rSkipCount === 0 && $rAlready === 0) {
            echo '<p class="mb-0 text-muted small">All students from the selected qualification(s) were enrolled successfully.</p>';
        }
        echo '</div></div></div></div>';

        // ── Fix Student Names button (v4.9.183) ──────────────────────────────
        // Always shown on the results page so the admin can repair placeholder
        // names ("Student 0000005776") regardless of whether new accounts were
        // created THIS run.  The NAT00080 data from the current (or any prior)
        // import is used to update every placeholder account site-wide.
        $repairUrl = new moodle_url('/local/rtocompliance/data_import.php');
        echo '<div class="card border-info mb-3">';
        echo '<div class="card-body py-3 d-flex align-items-start" style="gap:1rem">';
        echo '<span style="font-size:1.5rem;color:#0c5460">&#9998;</span>';
        echo '<div style="flex:1">';
        echo '<h6 class="mb-1" style="color:#0c5460">Fix placeholder student names</h6>';
        echo '<p class="mb-2 small text-muted">If any accounts still show &ldquo;Student 0000005776&rdquo; instead of a real name, click the button below. '
           . 'The tool reads the NAT00080 data from this import (and all previous imports) and updates every placeholder account in one go.</p>';
        echo '<form method="post" action="' . $repairUrl->out(false) . '" style="display:inline">';
        echo '<input type="hidden" name="sesskey"  value="' . sesskey() . '">';
        echo '<input type="hidden" name="action"   value="repairnames">';
        if ($importid) { echo '<input type="hidden" name="importid" value="' . (int)$importid . '">'; }
        echo '<button type="submit" class="btn btn-info btn-sm" title="Repair student names on the affected records now">Fix Student Names Now</button>';
        echo '</form>';
        echo '</div></div></div>';

        // ── Re-hide archive categories card ───────────────────────────────────
        // Only shown when this import auto-unhid one or more archive categories
        // during the enrolment step.  Clicking the button re-hides them so students
        // no longer see the old courses in their My Courses list.
        global $SESSION;
        if (!empty($SESSION->rtoc_auto_unhidden_cats)) {
            $hideCatCount = count($SESSION->rtoc_auto_unhidden_cats);
            $hideUrl = new moodle_url('/local/rtocompliance/data_import.php');
            echo '<div class="card border-warning mb-3">';
            echo '<div class="card-body py-3 d-flex align-items-start" style="gap:1rem">';
            echo '<span style="font-size:1.5rem;color:#856404">&#128065;</span>';
            echo '<div style="flex:1">';
            echo '<h6 class="mb-1" style="color:#856404">Hide old archive courses from students</h6>';
            echo '<p class="mb-2 small text-muted">';
            echo $hideCatCount . ' archive categor' . ($hideCatCount === 1 ? 'y was' : 'ies were') . ' temporarily made visible so the enrolment could proceed. ';
            echo 'Click the button below to hide ' . ($hideCatCount === 1 ? 'it' : 'them') . ' again — ';
            echo 'students will stay enrolled but the old courses will no longer appear in their My Courses list.';
            echo '</p>';
            echo '<form method="post" action="' . $hideUrl->out(false) . '" style="display:inline">';
            echo '<input type="hidden" name="sesskey"  value="' . sesskey() . '">';
            echo '<input type="hidden" name="action"   value="hide_archive_cats">';
            if ($importid) { echo '<input type="hidden" name="importid" value="' . (int)$importid . '">'; }
            echo '<button type="submit" class="btn btn-warning btn-sm" title="Re-hide the archive courses that were temporarily made visible">Hide Archive Courses Now</button>';
            echo '</form>';
            echo '</div></div></div>';
        }

        // ── Skip report (amber/red) ───────────────────────────────────────────
        if ($rSkipCount > 0) {
            $csvUrl = (new moodle_url('/local/rtocompliance/data_import.php', [
                'action'   => 'ae_skipcsv',
                'importid' => $importid,
            ]))->out(false);

            echo '<div class="card border-warning mb-4">';
            echo '<div class="card-header d-flex align-items-center justify-content-between" style="background:#fff8e1;flex-wrap:wrap;gap:0.5rem">';
            echo '<span class="font-weight-bold" style="color:#856404">&#9888; ' . $rSkipCount . ' student' . ($rSkipCount !== 1 ? 's' : '') . ' could not be enrolled</span>';
            echo '<a href="' . s($csvUrl) . '" class="btn btn-outline-secondary btn-sm">&#8595; Download list as CSV</a>';
            echo '</div>';
            echo '<div class="card-body p-0">';

            // FIX-AUTOENROL-CREATEFAILED-REPORT (v4.9.163): 'createfailed' students
            // were silently dropped from the UI skip report because the $byReason map
            // only had three keys.  Add it so they appear in the accordion alongside
            // the other skip reasons.  The CSV download already included the reason
            // label (via the $reasonLabels array above), but the on-screen report did
            // not — meaning the admin could only discover missing students by downloading
            // the CSV.  Before the password-policy fix these would all be password
            // failures; after the fix genuine username-collision cases can still occur.
            $byReason = ['nostudent' => [], 'noemail' => [], 'nouser' => [], 'createfailed' => [], 'enrolfailed' => []];
            foreach ($rSkipped as $sk) {
                $reason = $sk['reason'] ?? 'nouser';
                if (!isset($byReason[$reason])) {
                    $reason = 'nouser';   // fallback for any unexpected future reason codes
                }
                $byReason[$reason][] = $sk;
            }
            $reasonInfo = [
                'nouser'    => [
                    'label'  => 'No matching Moodle account',
                    'detail' => 'These students have an email address in the NAT file but no active Moodle account with that email. Ask them to register, or correct their email in your student management system.',
                    'color'  => '#dc3545',
                ],
                'createfailed' => [
                    'label'  => 'Moodle account could not be created',
                    'detail' => 'A new Moodle account was attempted for these students but failed — most commonly because another account already uses the same username (Client ID). Check Moodle for a duplicate account and either merge or delete it, then re-run enrolment.',
                    'color'  => '#dc3545',
                ],
                'noemail'   => [
                    'label'  => 'No email address on record',
                    'detail' => 'These students have no email stored in the NAT00085 file. Update their contact details in your student management system and re-import.',
                    'color'  => '#fd7e14',
                ],
                'nostudent' => [
                    'label'  => 'No student demographics (NAT00085 missing)',
                    'detail' => 'These client IDs appear in the enrolment records (NAT00120) but have no matching demographics row. Re-upload including the NAT00085 file.',
                    'color'  => '#6c757d',
                ],
                'enrolfailed' => [
                    'label'  => 'Moodle enrolment call failed',
                    'detail' => 'The student account was found or created, but Moodle rejected the enrolment. The error message below identifies the cause — common reasons are the manual enrolment plugin being disabled site-wide, or a required capability missing for this course.',
                    'color'  => '#dc3545',
                ],
            ];

            $firstGroup = true;
            foreach ($reasonInfo as $reason => $info) {
                $students = $byReason[$reason] ?? [];
                if (empty($students)) continue;
                $n = count($students);
                $toggleId = 'rtoc-skip-' . $reason;

                echo $firstGroup ? '' : '<hr class="my-0">';
                $firstGroup = false;

                echo '<div class="p-3">';
                echo '<div class="d-flex align-items-start" style="gap:0.75rem">';
                echo '<span style="color:' . $info['color'] . ';font-size:1.2rem;line-height:1.3">&#9679;</span>';
                echo '<div style="flex:1">';
                echo '<strong>' . $n . ' student' . ($n !== 1 ? 's' : '') . ' — ' . $info['label'] . '</strong>';
                echo '<p class="text-muted small mb-2">' . $info['detail'] . '</p>';

                // Expandable student list.
                echo '<button type="button" class="btn btn-link btn-sm p-0" style="font-size:0.85rem" '
                   . 'onclick="var t=document.getElementById(\'' . $toggleId . '\');t.style.display=t.style.display===\'none\'?\'block\':\'none\';this.textContent=t.style.display===\'none\'?\'&#9660; Show names\':\'&#9650; Hide names\'">'
                   . '&#9660; Show names</button>';
                echo '<div id="' . $toggleId . '" style="display:none;margin-top:0.5rem">';
                echo '<table class="table table-sm table-bordered mb-0" style="font-size:0.82rem">';
                echo '<thead class="thead-light"><tr>'
                   . '<th title="AVETMISS client identifier">Client ID</th><th title="Student name">Name</th>'
                   . ($reason === 'nouser' ? '<th title="Email address from the NAT file">Email (from NAT file)</th>' : '')
                   . '<th title="Qualification the student is enrolled in">Qualification</th>'
                   . '</tr></thead><tbody>';
                foreach ($students as $sk) {
                    echo '<tr>';
                    echo '<td>' . s($sk['clientid'] ?? '') . '</td>';
                    echo '<td>' . s($sk['name']     ?? '—') . '</td>';
                    if ($reason === 'nouser') {
                        echo '<td>' . s($sk['email'] ?? '') . '</td>';
                    }
                    echo '<td>' . s($sk['qual'] ?? '') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>'; // toggle div
                echo '</div></div>';
                echo '</div>'; // p-3
            }

            echo '</div></div>'; // card-body, card
        }

        // ── DIAG: per-qualification diagnostic table ─────────────────────────
        // Always shown after an enrolment run (v4.9.174) so admins can see
        // exactly how many client IDs were found in the DB and where each one went.
        $rDiagLog = $report ? ($report['diagLog'] ?? []) : [];
        if (!empty($rDiagLog)) {
            echo '<div class="card border-info mb-4" style="font-size:0.875rem">';
            echo '<div class="card-header d-flex align-items-center justify-content-between" style="background:#e8f4fd;flex-wrap:wrap;gap:0.5rem">';
            echo '<span class="font-weight-bold" style="color:#0c5460">&#128203; Enrolment diagnostic — per-qualification breakdown</span>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="this.closest(\'.card\').querySelector(\'.rtoc-diag-body\').classList.toggle(\'d-none\')">';
            echo 'Show / Hide</button>';
            echo '</div>';
            echo '<div class="rtoc-diag-body card-body p-0">';
            echo '<div style="overflow-x:auto">';
            echo '<table class="table table-sm table-bordered mb-0" style="font-size:0.8rem">';
            echo '<thead class="thead-light">';
            echo '<tr>';
            echo '<th title="Qualification code">Qual</th>';
            echo '<th title="Distinct client IDs returned from the DB for this qual+importid">DB rows</th>';
            echo '<th title="Matched by primary method (email or student ID)">P1 match</th>';
            echo '<th title="Matched by cross-mode fallback (student ID / email)">P2 fallback</th>';
            echo '<th title="New Moodle accounts created">P2 created</th>';
            echo '<th title="Account creation failed (duplicate username etc)">P2 create-fail</th>';
            echo '<th title="Courses in category">Units</th>';
            echo '<th title="Student already enrolled in all units in the category — skipped">P3 already</th>';
            echo '<th title="Successfully enrolled this run">P3 enrolled</th>';
            echo '<th title="enrol_user() threw an exception">P3 enrol-fail</th>';
            echo '<th title="Students skipped: all units have terminal outcomes (20/30/40/51-53/60/61/81/82/85/90) — use Backfill Qual Builder">Terminal skipped</th>';
            echo '<th title="Course-enrolments skipped because the student had no NAT00120 record for that unit (Unit-accurate mode only)">P3 unit-skip</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            foreach ($rDiagLog as $de) {
                $total = (int)$de['clientids_db'];
                $enrolled = (int)$de['phase3_enrolled'];
                $termSk = (int)($de['terminal_skipped'] ?? 0);
                $rowStyle = ($total === 0 && $termSk === 0) ? 'background:#fff3cd' : (($enrolled === 0 && $total > 0) ? 'background:#f8d7da' : '');
                echo '<tr' . ($rowStyle ? ' style="' . $rowStyle . '"' : '') . '>';
                echo '<td><strong>' . s($de['qualcode']) . '</strong></td>';
                echo '<td' . ($total === 0 && $termSk === 0 ? ' style="color:#856404;font-weight:bold"' : '') . '>' . $total . '</td>';
                echo '<td>' . (int)($de['course_count'] ?? 0) . '</td>';
                echo '<td>' . (int)$de['phase1_matched'] . '</td>';
                echo '<td>' . (int)$de['phase2_fallback'] . '</td>';
                echo '<td>' . (int)$de['phase2_created'] . '</td>';
                echo '<td' . ((int)$de['phase2_createfailed'] > 0 ? ' style="color:#721c24;font-weight:bold"' : '') . '>' . (int)$de['phase2_createfailed'] . '</td>';
                echo '<td>' . (int)$de['phase3_already'] . '</td>';
                echo '<td' . ($enrolled > 0 ? ' style="color:#155724;font-weight:bold"' : '') . '>' . $enrolled . '</td>';
                echo '<td' . ((int)$de['phase3_enrolfailed'] > 0 ? ' style="color:#721c24;font-weight:bold"' : '') . '>' . (int)$de['phase3_enrolfailed'] . '</td>';
                echo '<td' . ($termSk > 0 ? ' style="color:#856404;font-weight:bold"' : '') . '>' . $termSk . '</td>';
                $unitSkip = (int)($de['phase3_skipped_nonat'] ?? 0);
                echo '<td' . ($unitSkip > 0 ? ' style="color:#0c5460;font-weight:bold"' : '') . '>' . $unitSkip . '</td>';
                echo '</tr>';
                // v4.9.175: When DB rows = 0, show a supplementary row with actual qualcodes in DB.
                if ($total === 0 && !empty($de['actual_qualcodes_in_db'])) {
                    echo '<tr style="background:#fff3cd">';
                    echo '<td colspan="12" class="small" style="color:#856404;padding:0.4rem 0.75rem">';
                    echo '<strong>&#9888; Qualcode mismatch detected.</strong> ';
                    echo 'The form sent qualcode <code>' . s($de['qualcode']) . '</code> but the DB has '
                        . (int)($de['total_rows_in_import'] ?? 0) . ' total rows for this import. ';
                    echo 'Actual qualcodes stored in DB for this import: ';
                    $qcHtml = [];
                    foreach ($de['actual_qualcodes_in_db'] as $aqc) {
                        $qcHtml[] = '<code>' . s((string)$aqc) . '</code>';
                    }
                    echo implode(', ', $qcHtml) . '. ';
                    echo 'If the qualcode in the form does not match any of the above exactly (case/spaces), '
                        . 'no students will be enrolled. Please report the exact values above to support.';
                    echo '</td>';
                    echo '</tr>';
                } elseif ($total === 0 && isset($de['total_rows_in_import'])) {
                    echo '<tr style="background:#fff3cd">';
                    echo '<td colspan="12" class="small" style="color:#856404;padding:0.4rem 0.75rem">';
                    echo '<strong>&#9888; DB rows = 0</strong> and this import has ';
                    echo (int)$de['total_rows_in_import'] . ' total enrolment rows but <strong>none</strong> ';
                    echo 'with qualcode = <code>' . s($de['qualcode']) . '</code>. ';
                    echo 'The enrolment table may be empty for this import.';
                    echo '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table></div>';
            echo '<p class="small text-muted m-2"><strong>DB rows = 0</strong> means no client IDs were found for that exact qualcode in the database. '
                . 'The expanded amber row shows what qualcodes ARE actually stored — check for case or spacing differences.</p>';
            echo '</div></div>'; // card-body, card
        }

        // ── Context note: what the tabs below show ────────────────────────────
        echo '<div class="alert alert-light border mb-4 small">';
        echo '<strong>What are the tabs below?</strong> ';
        echo 'The Students and Enrolments tabs show <em>AVETMISS records from your NAT file</em> — ';
        echo 'all ' . (int)$imp->totalstudents . ' students and all their units across every qualification in the file. ';
        echo 'This is <strong>not</strong> the same as Moodle enrolments. ';
        if ($search !== '') {
            echo 'The list is currently filtered to <strong>' . s($search) . '</strong>. ';
            echo '<a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid, 'tab' => 'enrolments']))->out() . '">Clear filter to see all qualifications.</a>';
        } else {
            echo 'Use the search box below to filter by qualification code (e.g. <code>MEM20413</code>).';
        }
        echo '</div>';
    }

    // Summary cards
    $fileslist = json_decode($imp->filesprocessed ?? '[]', true) ?: [];
    echo '<div class="rto-grid-4 rto-mb-4">';
    $cards = [
        ['label' => get_string('dataimport_students', 'local_rtocompliance'),    'value' => $imp->totalstudents,    'class' => 'text-primary'],
        ['label' => get_string('dataimport_enrolments', 'local_rtocompliance'),  'value' => $imp->totalenrolments,  'class' => 'text-success'],
        ['label' => get_string('dataimport_completions', 'local_rtocompliance'), 'value' => $imp->totalcompletions, 'class' => 'text-warning'],
        ['label' => get_string('dataimport_flagged', 'local_rtocompliance'),     'value' => $imp->flaggedrecords,   'class' => 'text-danger'],
    ];
    foreach ($cards as $card) {
        echo '<div class="card"><div class="card-body py-3">';
        echo '<div class="h4 mb-0 ' . $card['class'] . '">' . $card['value'] . '</div>';
        echo '<small class="text-muted">' . $card['label'] . '</small>';
        echo '</div></div>';
    }
    echo '</div>';

    // Import metadata
    $meta = [];
    if ($imp->rtoid) $meta[] = get_string('dataimport_rto', 'local_rtocompliance') . ': ' . s($imp->rtoid);
    if ($imp->rtoname) $meta[] = s($imp->rtoname);
    if ($imp->collectionyear) $meta[] = get_string('dataimport_collection_year', 'local_rtocompliance') . ': ' . s($imp->collectionyear);
    $meta[] = get_string('dataimport_imported_at', 'local_rtocompliance') . ': ' . userdate($imp->timecreated);
    if ($fileslist) $meta[] = count($fileslist) . ' file(s): ' . s(implode(', ', $fileslist));
    echo '<p class="text-muted small mb-4">' . implode(' &nbsp;·&nbsp; ', $meta) . '</p>';

    // Tabs
    $tabs = [
        'students'    => get_string('dataimport_students', 'local_rtocompliance')    . ' (' . $imp->totalstudents . ')',
        'enrolments'  => get_string('dataimport_enrolments', 'local_rtocompliance')  . ' (' . $imp->totalenrolments . ')',
        'completions' => get_string('dataimport_completions', 'local_rtocompliance') . ' (' . $imp->totalcompletions . ')',
        'audit'       => '&#128203; Data Audit',
        'quality'     => '&#9888; Data Quality' . ($imp->flaggedrecords > 0
            ? ' <span class="badge badge-warning" style="font-size:0.75rem;vertical-align:middle" title="Number of records in this import flagged with missing or invalid data.">' . $imp->flaggedrecords . '</span>'
            : ''),
    ];
    echo '<ul class="nav nav-tabs mb-3">';
    foreach ($tabs as $key => $label) {
        $active = ($tab === $key) ? ' active' : '';
        $url = new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid, 'tab' => $key]);
        echo '<li class="nav-item"><a class="nav-link' . $active . '" href="' . $url->out() . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    // Search form — students, enrolments, completions only (not the audit tab).
    // ADD-COMPLETIONS-SEARCH (v4.9.138): completions previously had no search form.
    // FIX-PHP74-MATCH (v4.9.139): match() is PHP 8.0+ only; Moodle 4.1 LTS still supports PHP 7.4.
    // Replaced with if/elseif.
    if ($tab !== 'audit' && $tab !== 'quality') {
        if ($tab === 'enrolments') {
            $placeholder = get_string('dataimport_search_enrolments',  'local_rtocompliance');
        } elseif ($tab === 'completions') {
            $placeholder = get_string('dataimport_search_completions', 'local_rtocompliance');
        } else {
            $placeholder = get_string('dataimport_search_students',    'local_rtocompliance');
        }
        $formurl = new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid, 'tab' => $tab]);
        echo '<form method="get" action="' . $formurl->out(false) . '" class="mb-3">';
        echo '<input type="hidden" name="importid" value="' . $importid . '">';
        echo '<input type="hidden" name="tab" value="' . s($tab) . '">';
        echo '<input type="text" name="search" value="' . s($search) . '" placeholder="' . s($placeholder) . '" class="form-control" style="max-width:400px">';
        echo '</form>';
    }

    // ── Students tab ──────────────────────────────────────────────────────────
    if ($tab === 'students') {
        // Push the search filter to the DB so the 200-row display cap applies to
        // matching records, not to the unfiltered full dataset.
        $sparams = ['importid' => $importid];
        $swhere  = 'importid = :importid';
        if ($search !== '') {
            $slike = '%' . $DB->sql_like_escape($search) . '%';
            $swhere .= ' AND ('
                . $DB->sql_like('name',     ':srch1', false) . ' OR '
                . $DB->sql_like('clientid', ':srch2', false) . ' OR '
                . $DB->sql_like('email',    ':srch3', false) . ')';
            $sparams['srch1'] = $slike;
            $sparams['srch2'] = $slike;
            $sparams['srch3'] = $slike;
        }
        $totalStudentsMatching = $DB->count_records_select('local_rtocompliance_avetmiss_student', $swhere, $sparams);
        $students = $DB->get_records_select('local_rtocompliance_avetmiss_student', $swhere, $sparams, 'name ASC', '*', 0, 200);

        if ($search !== '') {
            echo '<p class="text-muted small mb-2">Showing ' . count($students) . ' of ' . $totalStudentsMatching
                . ' student' . ($totalStudentsMatching !== 1 ? 's' : '') . ' matching <strong>' . s($search) . '</strong>.'
                . ' <a href="' . (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid, 'tab' => 'students']))->out() . '">Clear filter</a></p>';
        } else {
            echo '<p class="text-muted small mb-2">Showing first ' . count($students) . ' of ' . $totalStudentsMatching
                . ' student' . ($totalStudentsMatching !== 1 ? 's' : '') . ' in this import.'
                . ($totalStudentsMatching > 200 ? ' <strong>Use the search box above to find specific students.</strong>' : '') . '</p>';
        }

        echo '<div class="table-responsive"><table class="table table-sm table-hover">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS client identifier">' . get_string('dataimport_clientid', 'local_rtocompliance') . '</th>';
        echo '<th title="Student name">' . get_string('dataimport_name', 'local_rtocompliance') . '</th>';
        echo '<th title="Student date of birth">' . get_string('dataimport_dob', 'local_rtocompliance') . '</th>';
        echo '<th title="Unique Student Identifier">' . get_string('dataimport_usi', 'local_rtocompliance') . '</th>';
        echo '<th title="Student email address">' . get_string('dataimport_email', 'local_rtocompliance') . '</th>';
        echo '<th title="Data quality flags raised for this student">' . get_string('dataimport_flags', 'local_rtocompliance') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($students as $s) {
            $rowclass = $s->hasdataissues ? ' class="table-warning"' : '';
            echo '<tr' . $rowclass . '>';
            echo '<td><code class="text-muted small">' . s($s->clientid) . '</code></td>';
            $displayname = trim(($s->firstname ?? '') . ' ' . ($s->familyname ?? '')) ?: $s->name;
            echo '<td><strong>' . s($displayname) . '</strong></td>';
            echo '<td class="text-muted small">' . local_rtocompliance_format_ddmmyyyy($s->dob) . '</td>';
            if ($s->usi) {
                echo '<td><span class="text-success"><small>&#10003;</small> <code class="small">' . s($s->usi) . '</code></span></td>';
            } else {
                echo '<td><span class="text-muted small">&#10007; —</span></td>';
            }
            echo '<td class="text-muted small">' . s($s->email ?? '—') . '</td>';
            echo '<td>';
            if ($s->hasdataissues) {
                echo '<span class="badge badge-warning" title="This student record has missing or invalid data that may need fixing before national reporting.">' . get_string('dataimport_data_issue', 'local_rtocompliance') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        if (empty($students)) {
            echo '<tr><td colspan="6" class="text-center text-muted py-3">No students match your search.</td></tr>';
        }
        echo '</tbody></table></div>';

    // ── Enrolments tab ────────────────────────────────────────────────────────
    } elseif ($tab === 'enrolments') {
        // FIX-ENROL-NAME-SEARCH (v5.2.19): Previous search only matched clientid/unitcode/qualcode.
        // Searching by student name (e.g. "hunter, riley") always returned 0 because the
        // enrolments table has no name column. Fixed: LEFT JOIN to avetmiss_student so that
        // the search also matches against the student name field.
        // Build the WHERE clause and params once; used for both COUNT and SELECT.
        $eparams = ['importid' => $importid];
        $ewhereclause = 'e.importid = :importid';
        if ($search !== '') {
            $elike = '%' . $DB->sql_like_escape($search) . '%';
            $ewhereclause .= ' AND ('
                . $DB->sql_like('e.clientid', ':srch1', false) . ' OR '
                . $DB->sql_like('e.unitcode',  ':srch2', false) . ' OR '
                . $DB->sql_like('e.qualcode',  ':srch3', false) . ' OR '
                . $DB->sql_like('s.name',      ':srch4', false) . ')';
            $eparams['srch1'] = $elike;
            $eparams['srch2'] = $elike;
            $eparams['srch3'] = $elike;
            $eparams['srch4'] = $elike;
        }
        $ejoinclause = "FROM {local_rtocompliance_avetmiss_enrolment} e
                        LEFT JOIN {local_rtocompliance_avetmiss_student} s
                               ON s.clientid = e.clientid AND s.importid = e.importid
                        WHERE $ewhereclause";
        $totalEnrolsMatching = $DB->count_records_sql(
            "SELECT COUNT(*) $ejoinclause", $eparams);
        // Use get_records_sql to support the LEFT JOIN (get_records_select doesn't allow JOINs).
        $enrolments = $DB->get_records_sql(
            "SELECT e.* $ejoinclause ORDER BY e.qualcode ASC, e.clientid ASC", $eparams, 0, 500);

        $clearUrl = (new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $importid, 'tab' => 'enrolments']))->out();
        if ($search !== '') {
            echo '<p class="text-muted small mb-2">Showing ' . count($enrolments) . ' of ' . $totalEnrolsMatching
                . ' enrolment record' . ($totalEnrolsMatching !== 1 ? 's' : '') . ' matching <strong>' . s($search) . '</strong>.'
                . ' <a href="' . $clearUrl . '">Clear filter</a></p>';
        } else {
            echo '<p class="text-muted small mb-2">Showing first ' . count($enrolments) . ' of ' . $totalEnrolsMatching
                . ' enrolment record' . ($totalEnrolsMatching !== 1 ? 's' : '') . '.'
                . ($totalEnrolsMatching > 500 ? ' <strong>Type a qualification code (e.g. MEM20413) in the search box above to filter by qualification.</strong>' : '') . '</p>';
        }

        echo '<div class="table-responsive"><table class="table table-sm table-hover">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS client identifier">' . get_string('dataimport_clientid', 'local_rtocompliance') . '</th>';
        echo '<th title="Unit of competency code">' . get_string('dataimport_unit', 'local_rtocompliance') . '</th>';
        echo '<th title="Qualification code">' . get_string('dataimport_qual', 'local_rtocompliance') . '</th>';
        echo '<th title="Activity start date">' . get_string('dataimport_start', 'local_rtocompliance') . '</th>';
        echo '<th title="Activity end date">' . get_string('dataimport_end', 'local_rtocompliance') . '</th>';
        echo '<th title="AVETMISS outcome identifier">' . get_string('dataimport_outcome', 'local_rtocompliance') . '</th>';
        echo '<th title="National funding source code">' . get_string('dataimport_funding', 'local_rtocompliance') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($enrolments as $e) {
            echo '<tr>';
            echo '<td><code class="text-muted small">' . s($e->clientid) . '</code></td>';
            echo '<td><strong class="small">' . s($e->unitcode) . '</strong></td>';
            echo '<td class="text-muted small">' . s($e->qualcode) . '</td>';
            echo '<td class="text-muted small">' . local_rtocompliance_format_ddmmyyyy($e->startdate) . '</td>';
            echo '<td class="text-muted small">' . local_rtocompliance_format_ddmmyyyy($e->enddate) . '</td>';
            echo '<td>';
            if ($e->outcome) {
                // FIX-OUTCOME-BADGE-COLORS (v4.9.131): Full AVETMISS 8 colour map.
                // FIX-PHP74-MATCH (v4.9.139): match() is PHP 8.0+ only — replaced with
                // a lookup array which is PHP 7.4-compatible and equally readable.
                // FIX-OUTCOME-BADGE-COLORS-5.2.14: 20/40 were swapped (same bug as label swap
                // fixed in v5.2.13). 20=Competency Achieved must be SUCCESS (green).
                // 40=Withdrawn must be WARNING (amber), not success. 53 added. 81 → success.
                static $outcomecolormap = [
                    '20' => 'badge-success', '41' => 'badge-success', '51' => 'badge-success',
                    '53' => 'badge-success', '60' => 'badge-success', '61' => 'badge-success',
                    '81' => 'badge-success', '85' => 'badge-success',
                    '30' => 'badge-danger',  '52' => 'badge-danger',
                    '40' => 'badge-warning', '82' => 'badge-warning',
                    '10' => 'badge-secondary', '70' => 'badge-secondary', '90' => 'badge-secondary',
                ];
                $outcomeclass = $outcomecolormap[$e->outcome] ?? 'badge-secondary';
                echo '<span class="badge ' . $outcomeclass . ' small" title="The result recorded for this unit. Green means achieved, amber means withdrawn or partial, red means not achieved.">' . s(local_rtocompliance_avetmiss_outcome_label($e->outcome)) . '</span>';
            } else {
                echo '<span class="text-muted small">—</span>';
            }
            echo '</td>';
            echo '<td>';
            if ($e->fundingsource) {
                echo '<span class="badge badge-secondary small">' . s($e->fundingsource) . '</span>';
            } else {
                echo '<span class="text-muted small">—</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        if (empty($enrolments)) {
            echo '<tr><td colspan="7" class="text-center text-muted py-3">No enrolments match your search.</td></tr>';
        }
        echo '</tbody></table></div>';

    // ── Completions tab ───────────────────────────────────────────────────────
    } elseif ($tab === 'completions') {
        // FIX-COMPLETIONS-ROW-CAP (v4.9.131): capped at 500 rows.
        // ADD-COMPLETIONS-SEARCH (v4.9.138): completions tab now supports search.
        // FIX-COMPLETIONS-NAME-SEARCH (v5.2.20): previous search only matched clientid/qualcode.
        // Searching by name (e.g. "hunter, riley") returned 0. Fixed: LEFT JOIN to avetmiss_student.
        // FIX-NAT00130-SUCCESSFUL-COMPLETION (v5.2.32): Default view hides N records (partial/SoA
        // only). showincomplete=1 param reveals them. All rows now show a Y/N badge.
        $showincomplete = optional_param('showincomplete', 0, PARAM_INT);
        $cparams = ['importid' => $importid];
        $cwhereclause = 'c.importid = :importid';
        if (!$showincomplete) {
            // Hide records explicitly flagged as N (not completed). NULL = unknown (pre-v5.2.32
            // imports or files without the flag) — show those to avoid hiding valid data.
            $cwhereclause .= ' AND (c.successfulcompletion IS NULL OR c.successfulcompletion != :notcomplete)';
            $cparams['notcomplete'] = 'N';
        }
        if ($search !== '') {
            $clike = '%' . $DB->sql_like_escape($search) . '%';
            $cwhereclause .= ' AND ('
                . $DB->sql_like('c.clientid', ':srch1', false) . ' OR '
                . $DB->sql_like('c.qualcode', ':srch2', false) . ' OR '
                . $DB->sql_like('s.name',     ':srch3', false) . ')';
            $cparams['srch1'] = $clike;
            $cparams['srch2'] = $clike;
            $cparams['srch3'] = $clike;
        }
        $cjoinclause = "FROM {local_rtocompliance_avetmiss_completion} c
                        LEFT JOIN {local_rtocompliance_avetmiss_student} s
                               ON s.clientid = c.clientid AND s.importid = c.importid
                        WHERE $cwhereclause";
        $totalCompletionsMatching = $DB->count_records_sql(
            "SELECT COUNT(*) $cjoinclause", $cparams);
        $completions = $DB->get_records_sql(
            "SELECT c.* $cjoinclause ORDER BY c.qualcode ASC, c.clientid ASC", $cparams, 0, 500);

        // DERIVE-COMPLETION-DATE (v5.2.7): When the NAT00130 file has blank completion/
        // certificate dates (common — many SMSs record completion at unit level in NAT00120
        // rather than at qualification level), derive the completion date as the latest
        // end date of any satisfactorily-completed unit for that client+qualification in
        // the same import.  This mirrors standard RTO practice: qualification completion
        // date = date of last passing unit outcome.
        // Derived dates are displayed in italic to distinguish from authoritative NAT00130 dates.
        // FIX-COMPETENT-5.2.13: Added '41' (Satisfactorily Completed – VETiS) to derive list.
        // FIX-COMPETENT-5.2.15: Removed '52' (RPL Not Granted) — not a competent outcome.
        // Competent outcomes: 20/41/51/53/60/61/81/85 (52 excluded — RPL Denied ≠ competent).
        // A-P1-1 (v5.9.387): canonical competent set. Previously wrongly included
        // 41 (RTO closure), 53 (deleted RCC code), 61 (superseded) and 85 (not started).
        $COMPETENT_OUTCOMES = ['20', '51', '60', '81'];
        $derivedDates = [];
        $pairsNeedingDerived = [];
        foreach ($completions as $c) {
            // FIX-COMPACT-YEAR (v5.2.24): Also attempt derivation when completiondate is
            // not a full DDMMYYYY string (< 8 chars, e.g. compact NAT00130 year "2016"),
            // so we can show a specific end date instead of just the year.
            $rawcd = (string)($c->completiondate ?? '');
            if ($rawcd === '' || strlen($rawcd) < 8 || preg_match('/^[@\s0]+$/', $rawcd)) {
                $pairsNeedingDerived[$c->clientid . ':' . $c->qualcode] = true;
            }
        }
        if (!empty($pairsNeedingDerived)) {
            list($ocSQL, $ocParams) = $DB->get_in_or_equal($COMPETENT_OUTCOMES, SQL_PARAMS_NAMED, 'oc');
            $enrolSql = "SELECT clientid, qualcode, enddate
                           FROM {local_rtocompliance_avetmiss_enrolment}
                          WHERE importid = :importid
                            AND outcome $ocSQL
                            AND enddate IS NOT NULL
                            AND " . $DB->sql_length('enddate') . " = 8";
            // FIX-DUPKEY-DERIVEDATE (v5.2.26): get_records_sql() uses the first column as the PHP
            // array key — clientid is NOT unique here (one student has multiple units per import),
            // causing Moodle's "Duplicate value found in column clientid" debug warning and silently
            // dropping all but the last row per student. Switched to get_recordset_sql() which
            // streams rows as a forward-only cursor with no uniqueness requirement.
            $enrolRs = $DB->get_recordset_sql($enrolSql, array_merge(['importid' => $importid], $ocParams));
            foreach ($enrolRs as $er) {
                $key = $er->clientid . ':' . $er->qualcode;
                if (!isset($pairsNeedingDerived[$key])) continue;
                // Convert DDMMYYYY → YYYYMMDD for string-safe MAX comparison.
                $yyyymmdd = substr($er->enddate, 4, 4) . substr($er->enddate, 2, 2) . substr($er->enddate, 0, 2);
                if (!isset($derivedDates[$key]) || $yyyymmdd > $derivedDates[$key]['sort']) {
                    $derivedDates[$key] = ['ddmmyyyy' => $er->enddate, 'sort' => $yyyymmdd];
                }
            }
            $enrolRs->close();
        }

        $clearUrlC = (new moodle_url('/local/rtocompliance/data_import.php',
            ['importid' => $importid, 'tab' => 'completions']))->out();
        $toggleUrl = (new moodle_url('/local/rtocompliance/data_import.php',
            ['importid' => $importid, 'tab' => 'completions', 'showincomplete' => $showincomplete ? 0 : 1]))->out();
        if ($search !== '') {
            echo '<p class="text-muted small mb-2">Showing ' . count($completions) . ' of ' . $totalCompletionsMatching
                . ' completion record' . ($totalCompletionsMatching !== 1 ? 's' : '') . ' matching <strong>' . s($search) . '</strong>.'
                . ' <a href="' . $clearUrlC . '">Clear filter</a></p>';
        } else {
            echo '<p class="text-muted small mb-2">Showing first ' . count($completions) . ' of ' . $totalCompletionsMatching
                . ' completion record' . ($totalCompletionsMatching !== 1 ? 's' : '') . '.'
                . ($totalCompletionsMatching > 500 ? ' <strong>Use the search box above to filter by student ID or qualification code.</strong>' : '') . '</p>';
        }
        // FIX-NAT00130-SUCCESSFUL-COMPLETION (v5.2.32): Show toggle for N records.
        if (!$showincomplete) {
            echo '<p class="text-muted small mb-2" style="border-left:3px solid #f59e0b;padding-left:8px;background:#fffbeb;">'
                . '<strong>Showing full qualification completions only (flag = Y).</strong>'
                . ' Records where the student did <em>not</em> complete the full qualification (flag = N) are hidden.'
                . ' <a href="' . $toggleUrl . '">Show all records including partial/SoA</a></p>';
        } else {
            echo '<p class="text-muted small mb-2" style="border-left:3px solid #3b82f6;padding-left:8px;background:#eff6ff;">'
                . 'Showing <strong>all</strong> NAT00130 records including partial completions (flag = N).'
                . ' <a href="' . $toggleUrl . '">Hide partial/SoA records</a></p>';
        }

        echo '<div class="table-responsive"><table class="table table-sm table-hover">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS client identifier">' . get_string('dataimport_clientid', 'local_rtocompliance') . '</th>';
        echo '<th title="Qualification code">' . get_string('dataimport_qual', 'local_rtocompliance') . '</th>';
        echo '<th title="AVETMISS DE514 — Successful Programme Completion Indicator. Y = full qualification awarded. N = did not complete full qualification (SoA/partial only). Blank = flag not present in NAT file (pre-v5.2.32 import).">Full Qual?</th>';
        echo '<th title="Whether the qualification was completed">' . get_string('dataimport_completed', 'local_rtocompliance') . '</th>';
        echo '<th title="Certificate issue date">' . get_string('dataimport_cert_date', 'local_rtocompliance') . '</th>';
        echo '<th title="AVETMISS Data Element 515 — the certificate/parchment number reported to NCVER. '
            . 'This identifier is independent of internal cert numbering and provides an immutable audit trail.">'
            . get_string('dataimport_parchment', 'local_rtocompliance')
            . ' <span class="badge badge-info badge-sm" style="font-size:0.65rem;vertical-align:middle" title="NCVER is the national body that collects vocational training data. This number is reported to them.">NCVER</span></th>';
        echo '</tr></thead><tbody>';
        foreach ($completions as $c) {
            $flag = strtoupper(trim($c->successfulcompletion ?? ''));
            $rowclass = ($flag === 'N') ? ' class="table-warning"' : '';
            echo '<tr' . $rowclass . '>';
            echo '<td><code class="text-muted small">' . s($c->clientid) . '</code></td>';
            echo '<td><strong>' . s($c->qualcode) . '</strong></td>';
            if ($flag === 'Y') {
                echo '<td><span class="badge badge-success" title="Full qualification completed">Y</span></td>';
            } elseif ($flag === 'N') {
                echo '<td><span class="badge badge-warning text-dark" title="Did NOT complete full qualification — SoA or partial only">N</span></td>';
            } else {
                echo '<td><span class="text-muted small" title="Flag not recorded in this import (pre-v5.2.32 or absent from NAT file)">—</span></td>';
            }
            $fcd  = local_rtocompliance_format_ddmmyyyy($c->completiondate);
            $fcdt = local_rtocompliance_format_ddmmyyyy($c->certificatedate);
            $dkey = $c->clientid . ':' . $c->qualcode;
            // FIX-COMPACT-YEAR (v5.2.24): Prefer a derived full date; fall back to year-only
            // (e.g. "2016" from compact NAT00130) if no derived date is found.
            $rawLen = strlen((string)($c->completiondate ?? ''));
            if ($rawLen < 8 || $fcd === '—') {
                if (isset($derivedDates[$dkey])) {
                    $derivedFmt = local_rtocompliance_format_ddmmyyyy($derivedDates[$dkey]['ddmmyyyy']);
                    echo '<td class="text-muted small"><em title="Derived from latest passing unit outcome in NAT00120">'
                        . $derivedFmt . '</em></td>';
                } elseif ($fcd !== '—') {
                    // Year-only fallback (compact format stores only a 4-digit year).
                    echo '<td class="text-muted small"><em title="Completion year only (compact NAT00130 format)">'
                        . $fcd . '</em></td>';
                } else {
                    echo '<td class="text-muted small"><em class="text-muted">not recorded</em></td>';
                }
            } else {
                echo '<td class="text-muted small">' . $fcd . '</td>';
            }
            echo '<td class="text-muted small">' . ($fcdt === '—' ? '<em class="text-muted">not recorded</em>' : $fcdt) . '</td>';
            if (!empty($c->parchmentnumber)) {
                echo '<td><code class="text-success font-weight-bold">' . s($c->parchmentnumber) . '</code></td>';
            } else {
                echo '<td class="text-muted small"><em>not recorded</em></td>';
            }
            echo '</tr>';
        }
        if (empty($completions)) {
            echo '<tr><td colspan="6" class="text-center text-muted py-3">No completions in this import.</td></tr>';
        }
        echo '</tbody></table></div>';

    // ── Data Audit tab ────────────────────────────────────────────────────────
    } elseif ($tab === 'audit') {

        // All valid AVETMISS 8.0 outcome codes.
        $KNOWN_OUTCOMES = [
            '10' => 'Not Yet Started',
            '20' => 'Competency Achieved',
            '30' => 'Competency Not Yet Achieved',
            '40' => 'Withdrawn',
            '41' => 'Satisfactorily Completed',
            '51' => 'RPL Granted',
            '52' => 'RPL Not Granted',
            '53' => 'RCC Granted',
            '60' => 'Credit Transfer',
            '61' => 'Credit Transfer (Advanced)',
            '70' => 'Continuing Enrolment',
            '81' => 'Non-Assessable – Sat.',
            '82' => 'Non-Assessable – Not Sat.',
            '85' => 'Non-Assessable – Sat. Completed',
            '90' => 'Result Not Available',
        ];

        // ── Section 1: Outcome code distribution ──────────────────────────────
        echo '<div class="card mb-4">';
        echo '<div class="card-header d-flex align-items-center justify-content-between" style="gap:1rem">';
        echo '<strong>Outcome Code Distribution</strong>';
        echo '<span class="text-muted small">All enrolment records in this import, grouped by AVETMISS outcome code</span>';
        echo '</div>';
        echo '<div class="card-body p-0">';

        $outcomeRows = $DB->get_records_sql(
            "SELECT COALESCE(outcome, '__null__') AS outcome_val, COUNT(*) AS cnt
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid
              GROUP BY outcome
              ORDER BY cnt DESC",
            ['importid' => $importid]
        );

        $totalEnrols = array_sum(array_column((array)$outcomeRows, 'cnt'));
        $unknownCodes = [];

        echo '<table class="table table-sm mb-0">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS code value">Code</th><th title="Human-readable label for the code">Label</th><th style="width:200px" title="Number of records with this code">Count</th><th title="Share of the import that has this code">% of import</th><th title="Whether this code is valid or needs attention">Status</th>';
        echo '</tr></thead><tbody>';

        foreach ($outcomeRows as $row) {
            $code = ($row->outcome_val === '__null__') ? '' : $row->outcome_val;
            $cnt  = (int)$row->cnt;
            $pct  = $totalEnrols > 0 ? round($cnt / $totalEnrols * 100, 1) : 0;

            if ($code === '') {
                $label    = '<em class="text-muted">No outcome recorded</em>';
                $badgeClass = 'badge-light border';
                $status   = '<span class="badge badge-warning" title="No result code was recorded for these enrolments.">⚠ Missing</span>';
            } elseif (isset($KNOWN_OUTCOMES[$code])) {
                $label    = s($KNOWN_OUTCOMES[$code]);
                $colorMap = [
                    '20'=>'badge-success','41'=>'badge-success','51'=>'badge-success','53'=>'badge-success',
                    '60'=>'badge-success','61'=>'badge-success','81'=>'badge-success','85'=>'badge-success',
                    '30'=>'badge-danger','52'=>'badge-danger',
                    '40'=>'badge-warning','82'=>'badge-warning',
                    '10'=>'badge-secondary','70'=>'badge-secondary','90'=>'badge-secondary',
                ];
                $badgeClass = $colorMap[$code] ?? 'badge-secondary';
                $status   = '<span class="badge badge-success small" title="This result code is a valid national code.">✓ Recognised</span>';
            } else {
                $label    = '<em class="text-danger">UNRECOGNISED CODE</em>';
                $badgeClass = 'badge-dark';
                $status   = '<span class="badge badge-danger" title="This result code is not a recognised national code and will be rejected until corrected.">✗ Unknown</span>';
                $unknownCodes[] = $code;
            }

            $barWidth = max(2, (int)$pct);
            echo '<tr>';
            echo '<td><span class="badge ' . $badgeClass . '">' . ($code !== '' ? s($code) : '—') . '</span></td>';
            echo '<td>' . $label . '</td>';
            echo '<td>';
            echo '<div style="display:flex;align-items:center;gap:8px">';
            echo '<div style="flex:1;background:#e9ecef;border-radius:3px;height:12px;min-width:80px">';
            echo '<div style="width:' . $barWidth . '%;background:' . ($code === '' || !isset($KNOWN_OUTCOMES[$code]) ? '#dc3545' : '#28a745') . ';height:100%;border-radius:3px"></div>';
            echo '</div>';
            echo '<strong>' . number_format($cnt) . '</strong>';
            echo '</div>';
            echo '</td>';
            echo '<td class="text-muted small">' . $pct . '%</td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }

        if (empty($outcomeRows)) {
            echo '<tr><td colspan="5" class="text-center text-muted py-3">No enrolment records in this import.</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';

        // ── Alert: unrecognised codes ─────────────────────────────────────────
        if (!empty($unknownCodes)) {
            echo '<div class="alert alert-danger mb-4">';
            echo '<strong>⚠ Unrecognised outcome codes found:</strong> ';
            foreach ($unknownCodes as $uc) {
                echo '<code class="mx-1">' . s($uc) . '</code>';
            }
            echo '<br><small>These codes do not match any AVETMISS 8.0 national outcome identifier. '
               . 'They may be vendor-specific codes or field-position parsing errors. '
               . 'Check the raw NAT00120 file at positions 58–61 for these records.</small>';
            echo '</div>';
        }

        // ── Alert: suspicious "Not Yet Started" with past end date ─────────────
        // Find enrolments where outcome='10' and enddate < today (past end date but no result)
        // FIX-DEAD-TODAY8 (v5.2.16): $today8 was defined here as date('dmY') but never used.
        // The SQL below correctly uses date('Ymd') (YYYYMMDD) directly for the CONCAT/SUBSTR
        // comparison which converts stored DDMMYYYY to YYYYMMDD for lexicographic ordering.
        $suspiciousSQL = "SELECT COUNT(*) AS cnt
                           FROM {local_rtocompliance_avetmiss_enrolment}
                          WHERE importid = :importid
                            AND outcome = '10'
                            AND enddate IS NOT NULL
                            AND " . $DB->sql_length('enddate') . " = 8";
        $suspCount = (int)($DB->get_field_sql($suspiciousSQL, ['importid' => $importid]) ?? 0);
        // Filter to those whose end date is actually in the past.
        $pastSQL = "SELECT COUNT(*) AS cnt
                      FROM {local_rtocompliance_avetmiss_enrolment}
                     WHERE importid = :importid
                       AND outcome = '10'
                       AND enddate IS NOT NULL
                       AND " . $DB->sql_length('enddate') . " = 8
                       AND CONCAT(SUBSTR(enddate,5,4), SUBSTR(enddate,3,2), SUBSTR(enddate,1,2)) < :today";
        $pastCount = 0;
        try {
            $pastCount = (int)($DB->get_field_sql($pastSQL, ['importid' => $importid, 'today' => date('Ymd')]) ?? 0);
        } catch (\Exception $e) {
            // Fallback: skip date-filter on DB engines that don't support CONCAT/SUBSTR this way.
            $pastCount = $suspCount;
        }
        if ($pastCount > 0) {
            echo '<div class="alert alert-warning mb-4">';
            echo '<strong>&#9888; ' . number_format($pastCount) . ' enrolment(s)</strong> have outcome <code>10</code> (Not Yet Started) ';
            echo 'but their activity end date has already passed. ';
            echo 'This is suspicious — these students may have a result in the SMS that did not export correctly, '
               . 'or the outcome field may have been parsed from the wrong byte position. ';
            echo 'Check the raw NAT00120 file for these records and compare byte positions 58–61 against the outcome column.';
            echo '</div>';
        }

        // ── Section 2: Field completeness ─────────────────────────────────────
        echo '<div class="card mb-4">';
        echo '<div class="card-header"><strong>Field Completeness</strong></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-sm mb-0">';
        echo '<thead class="thead-light"><tr><th title="Data field name">Field</th><th title="Number of records where this field has a value">Populated</th><th title="Number of records where this field is blank or null">Blank/null</th><th title="Percentage of records with this field populated">% complete</th></tr></thead><tbody>';

        $enrolTotal = (int)$imp->totalenrolments;
        $fields = [
            'outcome'      => 'Outcome Code',
            'enddate'      => 'Activity End Date',
            'startdate'    => 'Activity Start Date',
            'fundingsource'=> 'Funding Source',
            'qualcode'     => 'Qualification Code',
            'unitcode'     => 'Unit Code',
            'studyreason'  => 'Study Reason',
        ];
        foreach ($fields as $col => $label) {
            $filled = (int)$DB->count_records_select(
                'local_rtocompliance_avetmiss_enrolment',
                "importid = :importid AND $col IS NOT NULL AND $col != ''",
                ['importid' => $importid]
            );
            $blank = $enrolTotal - $filled;
            $pct   = $enrolTotal > 0 ? round($filled / $enrolTotal * 100, 1) : 0;
            $pctClass = $pct < 50 ? 'text-danger' : ($pct < 90 ? 'text-warning' : 'text-success');
            echo '<tr>';
            echo '<td>' . s($label) . '</td>';
            echo '<td>' . number_format($filled) . '</td>';
            echo '<td>' . ($blank > 0 ? '<span class="text-muted">' . number_format($blank) . '</span>' : '<span class="text-success">0</span>') . '</td>';
            echo '<td class="' . $pctClass . ' font-weight-bold">' . $pct . '%</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';

        // ── Section 3: Qualification code summary ─────────────────────────────
        echo '<div class="card mb-4">';
        echo '<div class="card-header"><strong>Qualification Codes Found</strong></div>';
        echo '<div class="card-body p-0">';
        $qualRows = $DB->get_records_sql(
            "SELECT qualcode, COUNT(*) AS enrol_cnt,
                    SUM(CASE WHEN outcome IN ('20','51','60','81') THEN 1 ELSE 0 END) AS competent_cnt,
                    SUM(CASE WHEN outcome = '10' THEN 1 ELSE 0 END) AS nostart_cnt,
                    SUM(CASE WHEN outcome IS NULL OR outcome = '' THEN 1 ELSE 0 END) AS null_cnt
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid AND qualcode IS NOT NULL AND qualcode != ''
              GROUP BY qualcode ORDER BY enrol_cnt DESC",
            ['importid' => $importid]
        );
        if (!empty($qualRows)) {
            echo '<table class="table table-sm mb-0">';
            echo '<thead class="thead-light"><tr>';
            echo '<th title="Qualification code">Qual Code</th><th title="Total units in this qualification">Total Units</th><th title="Units with a competent outcome">Competent</th><th title="Units not yet started">Not Yet Started</th><th title="Units with no recorded outcome">No Outcome</th>';
            echo '</tr></thead><tbody>';
            foreach ($qualRows as $qr) {
                $tot = (int)$qr->enrol_cnt;
                $comp = (int)$qr->competent_cnt;
                $ns   = (int)$qr->nostart_cnt;
                $nul  = (int)$qr->null_cnt;
                echo '<tr>';
                echo '<td><strong>' . s($qr->qualcode) . '</strong></td>';
                echo '<td>' . number_format($tot) . '</td>';
                echo '<td>' . ($comp > 0 ? '<span class="text-success font-weight-bold">' . number_format($comp) . '</span>' : '<span class="text-muted">0</span>') . '</td>';
                echo '<td>' . ($ns > 0 ? '<span class="text-secondary">' . number_format($ns) . '</span>' : '<span class="text-muted">0</span>') . '</td>';
                echo '<td>' . ($nul > 0 ? '<span class="text-danger">' . number_format($nul) . '</span>' : '<span class="text-success">0</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="text-muted p-3 mb-0">No qualification codes found in this import.</p>';
        }
        echo '</div></div>';

    // ── Data Quality tab ──────────────────────────────────────────────────────
    } elseif ($tab === 'quality') {

        // Gather flagged students.
        $dqFlagged = $DB->get_records_select(
            'local_rtocompliance_avetmiss_student',
            'importid = :importid AND hasdataissues = 1',
            ['importid' => $importid],
            'name ASC'
        );
        $dqTotal = count($dqFlagged);
        $dqUsi = $dqDob = $dqSex = 0;
        foreach ($dqFlagged as $dqf) {
            $dqi = json_decode($dqf->dataissuefields ?? '[]', true) ?: [];
            if (in_array('usi_missing',    $dqi)) $dqUsi++;
            if (in_array('dob_not_stated', $dqi)) $dqDob++;
            if (in_array('sex_not_stated', $dqi)) $dqSex++;
        }

        // Unrecognised outcome codes.
        $DQKNOWN = ['10','20','30','40','41','51','52','53','60','61','70','81','82','85','90'];
        $dqOutcomes = $DB->get_records_sql(
            "SELECT outcome, COUNT(*) AS cnt
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE importid = :importid
              GROUP BY outcome ORDER BY cnt DESC",
            ['importid' => $importid]
        );
        $dqBadOutcomes = [];
        foreach ($dqOutcomes as $dqo) {
            if (!in_array(trim($dqo->outcome ?? ''), $DQKNOWN)) {
                $dqBadOutcomes[] = $dqo;
            }
        }

        // Completion quality.
        $dqTotalComps      = $DB->count_records('local_rtocompliance_avetmiss_completion', ['importid' => $importid]);
        $dqMissingDate     = (int)$DB->count_records_select(
            'local_rtocompliance_avetmiss_completion',
            "importid = :importid AND (completiondate IS NULL OR completiondate = '')",
            ['importid' => $importid]
        );
        $dqMissingParchment = (int)$DB->count_records_select(
            'local_rtocompliance_avetmiss_completion',
            "importid = :importid AND (parchmentnumber IS NULL OR parchmentnumber = '')",
            ['importid' => $importid]
        );

        $dqExportUrl = (new moodle_url('/local/rtocompliance/data_import.php', [
            'importid' => $importid, 'action' => 'export_quality_csv',
        ]))->out(false);

        // ── Alert banner ──────────────────────────────────────────────────────
        $dqClean = ($dqTotal === 0 && empty($dqBadOutcomes) && $dqMissingDate === 0);
        if ($dqClean) {
            echo '<div class="alert alert-success"><strong>&#10003; No data quality issues found</strong>'
                . ' — this import looks clean and ready to submit to NCVER.</div>';
        } else {
            $dqIssueList = [];
            if ($dqTotal > 0)
                $dqIssueList[] = $dqTotal . ' student' . ($dqTotal !== 1 ? 's' : '') . ' with incomplete data';
            if (!empty($dqBadOutcomes))
                $dqIssueList[] = count($dqBadOutcomes) . ' unrecognised outcome code' . (count($dqBadOutcomes) !== 1 ? 's' : '');
            if ($dqMissingDate > 0)
                $dqIssueList[] = $dqMissingDate . ' completion' . ($dqMissingDate !== 1 ? 's' : '') . ' missing date';
            echo '<div class="alert alert-warning py-2 px-3 mb-4">'
                . '<strong>&#9888; ' . implode(' &nbsp;·&nbsp; ', $dqIssueList) . '</strong>'
                . ' — fix these before submitting your annual AVETMISS report to NCVER.</div>';
        }

        // ── Summary cards ─────────────────────────────────────────────────────
        echo '<div class="row mb-4">';
        $dqCards = [
            ['value' => $dqTotal,               'label' => 'Flagged Students',    'bad' => $dqTotal > 0,           'tip' => 'Number of students in this import with data that is missing or invalid. The national reporting system will reject these until they are fixed.'],
            ['value' => $dqUsi,                 'label' => 'Missing USI',         'bad' => $dqUsi > 0,             'tip' => 'Students with no valid Unique Student Identifier. The USI is the student identity number required for national reporting.'],
            ['value' => $dqDob,                 'label' => 'Missing DOB',         'bad' => $dqDob > 0,             'tip' => 'Students with no valid date of birth on record.'],
            ['value' => $dqSex,                 'label' => 'Missing Sex',         'bad' => $dqSex > 0,             'tip' => 'Students with no valid sex code on record.'],
            ['value' => count($dqBadOutcomes),  'label' => 'Bad Outcome Codes',   'bad' => !empty($dqBadOutcomes), 'tip' => 'Enrolments whose result code is not a recognised national code. These will be rejected until corrected.'],
            ['value' => $dqMissingDate,         'label' => 'Comps Missing Date',  'bad' => $dqMissingDate > 0,     'tip' => 'Completed units that are missing a completion date.'],
        ];
        foreach ($dqCards as $dqc) {
            $bg  = $dqc['bad'] ? '#fff3cd' : '#d4edda';
            $br  = $dqc['bad'] ? '#ffc107' : '#28a745';
            $clr = $dqc['bad'] ? '#856404' : '#155724';
            echo '<div class="col-6 col-md-2 mb-2">';
            echo '<div class="card h-100 text-center" title="' . s($dqc['tip']) . '" style="border-color:' . $br . ';background:' . $bg . '">';
            echo '<div class="card-body py-3">';
            echo '<div style="font-size:1.8rem;font-weight:700;color:' . $clr . '">' . $dqc['value'] . '</div>';
            echo '<div class="small" style="color:#555">' . $dqc['label'] . '</div>';
            echo '</div></div></div>';
        }
        echo '</div>';

        // ── Action buttons ────────────────────────────────────────────────────
        if ($dqTotal > 0) {
            echo '<div class="mb-4 d-flex flex-wrap align-items-center" style="gap:0.5rem">';
            echo '<a href="' . $dqExportUrl . '" class="btn btn-warning btn-sm">&#11015; Export Flagged Students CSV</a>';
            $dqStudUrl = (new moodle_url('/local/rtocompliance/data_import.php',
                ['importid' => $importid, 'tab' => 'students']))->out(false);
            echo '<a href="' . $dqStudUrl . '" class="btn btn-outline-secondary btn-sm" title="View all students in this import">View All Students &rarr;</a>';
            $dqSRUrl = (new moodle_url('/local/rtocompliance/students.php'))->out(false);
            echo '<a href="' . $dqSRUrl . '" class="btn btn-outline-secondary btn-sm" title="Open the Student Records page">Open Student Records &rarr;</a>';
            echo '<span class="text-muted small">Fix in your SMS and re-import, or update profiles directly in Student Records</span>';
            echo '</div>';
        }

        // ── Section 1: Flagged students table ─────────────────────────────────
        if ($dqTotal > 0) {
            echo '<div class="card mb-4">';
            echo '<div class="card-header d-flex align-items-center" style="gap:0.75rem">';
            echo '<strong>&#9888; Students with Incomplete Data</strong>';
            echo '<span class="badge badge-warning" title="Number of students in this import with missing or invalid data.">' . $dqTotal . '</span>';
            echo '<span class="text-muted small ml-auto">NCVER will reject these records until fixed</span>';
            echo '</div>';
            echo '<div class="card-body p-0">';
            echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
            echo '<thead class="thead-light"><tr>';
            echo '<th title="AVETMISS client identifier">Client ID</th><th title="Student name">Name</th><th title="Student email address">Email</th>';
            echo '<th class="text-center" title="Whether the Unique Student Identifier is missing or invalid"><span class="badge badge-danger">USI</span></th>';
            echo '<th class="text-center" title="Whether the date of birth is missing or invalid"><span class="badge badge-warning">DOB</span></th>';
            echo '<th class="text-center" title="Whether the sex code is missing or invalid"><span class="badge badge-warning">Sex</span></th>';
            echo '<th title="Link to fix this student record">Fix</th>';
            echo '</tr></thead><tbody>';
            $dqTick = '<span class="text-danger font-weight-bold">&#10007;</span>';
            $dqOk   = '<span class="text-success">&#10003;</span>';
            foreach ($dqFlagged as $dqf) {
                $dqi    = json_decode($dqf->dataissuefields ?? '[]', true) ?: [];
                $dqname = trim(($dqf->firstname ?? '') . ' ' . ($dqf->familyname ?? '')) ?: $dqf->name;
                $dqpUrl = (new moodle_url('/local/rtocompliance/students.php',
                    ['search' => $dqf->clientid]))->out(false);
                echo '<tr class="table-warning">';
                echo '<td><code class="small text-muted">' . s($dqf->clientid) . '</code></td>';
                echo '<td><strong>' . s($dqname) . '</strong></td>';
                echo '<td class="text-muted small">' . s($dqf->email ?? '—') . '</td>';
                echo '<td class="text-center">' . (in_array('usi_missing',    $dqi) ? $dqTick : $dqOk) . '</td>';
                echo '<td class="text-center">' . (in_array('dob_not_stated', $dqi) ? $dqTick : $dqOk) . '</td>';
                echo '<td class="text-center">' . (in_array('sex_not_stated', $dqi) ? $dqTick : $dqOk) . '</td>';
                echo '<td><a href="' . $dqpUrl . '" class="btn btn-outline-secondary"'
                    . ' title="Find this student in Student Records"'
                    . ' style="font-size:0.7rem;padding:2px 8px">Find in Student Records &rarr;</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div></div>';
        }

        // ── Section 2: Unrecognised outcome codes ─────────────────────────────
        if (!empty($dqBadOutcomes)) {
            echo '<div class="card mb-4">';
            echo '<div class="card-header d-flex align-items-center" style="gap:0.75rem">';
            echo '<strong>&#10007; Unrecognised Outcome Codes</strong>';
            echo '<span class="badge badge-danger" title="Number of result codes in this import that are not recognised national codes.">' . count($dqBadOutcomes) . '</span>';
            echo '</div>';
            echo '<div class="card-body">';
            echo '<p class="small text-muted mb-3">These codes from NAT00120 (positions 58–61) are not valid AVETMISS 8.0 '
                . 'outcome identifiers. NCVER will reject these enrolment records. Contact your SMS vendor to correct the export.</p>';
            echo '<table class="table table-sm mb-0">';
            echo '<thead class="thead-light"><tr>'
                . '<th title="Invalid outcome code found in the import">Code</th><th title="Number of records affected by this code">Records Affected</th><th title="Recommended action to resolve">Action</th>'
                . '</tr></thead><tbody>';
            foreach ($dqBadOutcomes as $dqo) {
                echo '<tr class="table-danger">';
                echo '<td><code>' . s($dqo->outcome ?? '(blank)') . '</code></td>';
                echo '<td>' . number_format((int)$dqo->cnt) . '</td>';
                echo '<td class="small text-muted">Correct in SMS &rarr; re-export NAT00120 &rarr; re-import</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div></div>';
        }

        // ── Section 3: Completion quality ─────────────────────────────────────
        if ($dqTotalComps > 0) {
            echo '<div class="card mb-4">';
            echo '<div class="card-header"><strong>&#9432; Completion Record Quality</strong></div>';
            echo '<div class="card-body">';
            echo '<p class="small text-muted mb-3">NAT00130 qualification completion records for this import. '
                . '"Not recorded" means the NAT file did not include this data — expected for students not yet formally issued their certificate.</p>';
            echo '<div class="row mb-3">';
            $dqCompCards = [
                ['value' => $dqTotalComps,       'label' => 'Total Completions',   'bad' => false,                    'tip' => 'Number of finished-qualification records in this import.'],
                ['value' => $dqMissingDate,      'label' => 'Missing Comp. Date',  'bad' => $dqMissingDate > 0,        'tip' => 'Completed qualifications that are missing the date they were completed.'],
                ['value' => $dqMissingParchment, 'label' => 'Missing Parchment #', 'bad' => $dqMissingParchment > 0,   'tip' => 'Completed qualifications with no certificate number recorded. The parchment number is the certificate number.'],
            ];
            foreach ($dqCompCards as $dqcc) {
                $bg2  = $dqcc['bad'] ? '#fff3cd' : '#d4edda';
                $br2  = $dqcc['bad'] ? '#ffc107' : '#28a745';
                $clr2 = $dqcc['bad'] ? '#856404' : '#155724';
                echo '<div class="col-md-4 mb-2">';
                echo '<div class="card text-center" title="' . s($dqcc['tip']) . '" style="border-color:' . $br2 . ';background:' . $bg2 . '">';
                echo '<div class="card-body py-2">';
                echo '<div style="font-size:1.5rem;font-weight:700;color:' . $clr2 . '">' . $dqcc['value'] . '</div>';
                echo '<div class="small" style="color:#555">' . $dqcc['label'] . '</div>';
                echo '</div></div></div>';
            }
            echo '</div>';
            $dqCompUrl = (new moodle_url('/local/rtocompliance/data_import.php', [
                'importid' => $importid, 'tab' => 'completions', 'showincomplete' => 1,
            ]))->out(false);
            echo '<a href="' . $dqCompUrl . '" class="btn btn-sm btn-outline-secondary" title="View the completion records for this import">View completion records &rarr;</a>';
            echo '</div></div>';
        }

        // ── Section 4: How to fix ─────────────────────────────────────────────
        echo '<div class="card mb-4" style="border-color:#bee5eb">';
        echo '<div class="card-header" style="background:#d1ecf1;border-color:#bee5eb">';
        echo '<strong>&#128196; How to fix these issues</strong>';
        echo '</div>';
        echo '<div class="card-body small">';
        echo '<ol class="mb-0">';
        echo '<li class="mb-2"><strong>Missing USI</strong> — Collect USIs from students '
            . '(they can look theirs up at <a href="https://www.usi.gov.au" target="_blank">usi.gov.au</a>). '
            . 'Enter them in <strong>Student Records</strong> → student profile. '
            . 'Or export the CSV above, bulk-add USIs in your SMS, then re-import the NAT file.</li>';
        echo '<li class="mb-2"><strong>Missing DOB or Sex</strong> — Update in your SMS, re-export the NAT file, and re-import. '
            . 'You can also update individual student profiles directly in Student Records.</li>';
        echo '<li class="mb-2"><strong>Unrecognised outcome codes</strong> — Contact your SMS vendor. '
            . 'The code in your NAT00120 file is not a valid AVETMISS 8.0 identifier (e.g. code "04" is not standard). '
            . 'The vendor needs to map it to the correct AVETMISS code before re-exporting.</li>';
        echo '<li><strong>Missing completion dates / parchment numbers</strong> — '
            . 'Expected if the certificate has not been formally issued yet. '
            . 'Once issued, update via Student Records → Certificates, or correct in your SMS and re-import.</li>';
        echo '</ol>';
        echo '</div></div>';

    }

// ── LIST / UPLOAD VIEW ────────────────────────────────────────────────────────
} else {

    echo '<p class="text-muted">' . get_string('dataimport_desc', 'local_rtocompliance') . '</p>';

    // ── Qual Builder prerequisite check ──────────────────────────────────────
    // Check if any quals exist in Qual Builder — if not, show a blocking notice.
    $qbCount = 0;
    try {
        $qbCount = $DB->count_records('local_rtocompliance_qualbuilder');
    } catch (Exception $e) { $qbCount = -1; }

    if ($qbCount === 0) {
        $qbUrl = new moodle_url('/local/rtocompliance/qualbuilder.php');
        echo '<div class="alert alert-warning mb-4">';
        echo '<h6 class="font-weight-bold">&#9888;&nbsp; Set up Qual Builder before importing NAT files</h6>';
        echo '<p class="mb-2 small">No qualifications have been set up in Qual Builder yet. '
            . 'Qual Builder is where you define every qualification your RTO delivers — the qualification code, title, units of competency, '
            . 'and which Moodle courses deliver each unit. The NAT import and auto-enrol process use this data to correctly match '
            . 'students to their courses and to generate accurate certificates.</p>';
        echo '<p class="mb-2 small"><strong>What to do:</strong></p>';
        echo '<ol class="small mb-2">';
        echo '<li>Click <strong>Open Qual Builder</strong> below.</li>';
        echo '<li>Add each qualification your RTO delivers (e.g. BSB50820 — Diploma of Business). Use the <strong>Fetch from TGA</strong> button to auto-fill units from training.gov.au.</li>';
        echo '<li>For each unit, link it to the Moodle course that delivers it.</li>';
        echo '<li>Come back to this page and upload your NAT files.</li>';
        echo '</ol>';
        echo html_writer::link($qbUrl, '&#9654; Open Qual Builder', ['class' => 'btn btn-warning btn-sm', 'title' => 'Open the Qualification Builder to define qualifications and units']);
        echo '</div>';
    } else {
        // Qual Builder has quals — show a subtle reminder with a link.
        $qbUrl = new moodle_url('/local/rtocompliance/qualbuilder.php');
        echo '<div class="alert alert-light border mb-3 py-2 px-3 small d-flex align-items-center justify-content-between flex-wrap" style="gap:0.5rem;">';
        echo '<span><strong>&#10003; Qual Builder:</strong> ' . $qbCount . ' qualification' . ($qbCount !== 1 ? 's' : '') . ' set up. '
            . 'Make sure every qualification in your NAT file has a matching entry in Qual Builder before importing.</span>';
        echo html_writer::link($qbUrl, 'Review Qual Builder &rarr;', ['class' => 'btn btn-outline-secondary btn-sm flex-shrink-0', 'title' => 'Review the qualifications set up in the Qualification Builder']);
        echo '</div>';
    }

    // ── How the NAT Import process works ─────────────────────────────────────
    echo '<div class="card border-info mb-4" style="background:#f0f8ff;">';
    echo '<div class="card-body py-3 px-4">';
    echo '<h6 class="font-weight-bold mb-2" style="color:#0c5460;">&#9432;&nbsp; How this works — getting students into Student Records</h6>';
    echo '<div class="row mt-2">';

    // Step 0
    $qbUrlCard = (new moodle_url('/local/rtocompliance/qualbuilder.php'))->out(false);
    echo '<div class="col-md-3 mb-2">';
    echo '<div class="p-2 border rounded h-100" style="background:#fff;border-color:#bee5eb!important">';
    echo '<div class="mb-1"><span class="badge badge-secondary" style="font-size:0.7rem;">Before you start</span></div>';
    echo '<strong style="color:#495057">Set up Qual Builder</strong>';
    echo '<p class="small mb-1 mt-1">Define every qualification your RTO delivers — qual code, units, and which Moodle course delivers each unit. '
        . 'The NAT import uses this to match students to courses. Do this once, update when you add new quals.</p>';
    echo '<a href="' . $qbUrlCard . '" class="btn btn-outline-secondary btn-sm mt-1" title="Open the Qualification Builder to define qualifications and units">Open Qual Builder &rarr;</a>';
    echo '</div></div>';

    // Step 1
    echo '<div class="col-md-3 mb-2">';
    echo '<div class="p-2 border rounded h-100" style="background:#fff;border-color:#bee5eb!important">';
    echo '<div class="mb-1"><span class="badge badge-primary" style="font-size:0.7rem;">Step 1</span></div>';
    echo '<strong style="color:#007bff">Upload NAT Files</strong>';
    echo '<p class="small mb-0 mt-1">Upload your NAT file set below. All student data is saved into the RTO Compliance database.</p>';
    echo '</div></div>';

    // Step 2
    echo '<div class="col-md-3 mb-2">';
    echo '<div class="p-2 border rounded h-100" style="background:#fff;border-color:#bee5eb!important">';
    echo '<div class="mb-1"><span class="badge badge-success" style="font-size:0.7rem;">Step 2</span></div>';
    echo '<strong style="color:#28a745">Confirm &amp; Import</strong>';
    echo '<p class="small mb-0 mt-1">Review student groups by qualification and semester on the next page. '
        . 'Confirming writes each student&rsquo;s demographics <strong>and their unit outcomes into your results register</strong>.</p>';
    echo '</div></div>';

    // Step 3
    echo '<div class="col-md-3 mb-2">';
    echo '<div class="p-2 border rounded h-100" style="background:#fff;border-color:#bee5eb!important">';
    echo '<div class="mb-1"><span class="badge" style="background:#6f42c1;font-size:0.7rem;">Step 3</span></div>';
    echo '<strong style="color:#6f42c1">See results &amp; review</strong>';
    echo '<p class="small mb-1 mt-1">Imported outcomes appear in <strong>Student Results</strong> immediately for every student already in your system. '
        . 'Any student in the file who is <strong>not yet in your system</strong> is listed in a downloadable review file on the import.</p>';
    echo '</div></div>';

    echo '</div>';
    echo '<div class="alert alert-info mb-0 mt-2 py-2 px-3 small d-flex align-items-center flex-wrap" style="gap:0.5rem;">';
    echo '<span><strong>&#9432; No Moodle changes.</strong> Importing populates your plugin&rsquo;s student profiles and results register only &mdash; it never creates or removes Moodle accounts or course enrolments. The link to Moodle is defined once, in the Qualification Builder.</span>';
    $verifyLinkUrl   = new moodle_url('/local/rtocompliance/data_import.php', ['action' => 'verify_nat']);
    echo html_writer::link($verifyLinkUrl, 'Verify NAT Data &rarr;', ['class' => 'btn btn-sm btn-outline-secondary flex-shrink-0', 'title' => 'Check the NAT data against your records before importing']);
    echo '</div>';
    echo '</div></div>';

    // Upload form
    echo '<div class="card mb-4">';
    echo '<div class="card-header"><h5 class="mb-0">' . get_string('dataimport_upload_heading', 'local_rtocompliance') . '</h5></div>';
    echo '<div class="card-body">';
    echo '<p class="text-muted small">' . get_string('dataimport_upload_desc', 'local_rtocompliance') . '</p>';
    $formurl = new moodle_url('/local/rtocompliance/data_import.php');
    echo '<form method="post" enctype="multipart/form-data" action="' . $formurl->out(false) . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

    // MATCH-METHOD (v4.9.159): Let the admin choose how students are matched
    // to Moodle accounts before uploading. This setting travels through the
    // entire import flow and is used by the auto-enrol step.
    echo '<div class="mb-3">';
    echo '<label class="font-weight-bold d-block mb-1">How should students be matched to their Moodle accounts?</label>';
    echo '<div class="form-check">';
    echo '<input class="form-check-input" type="radio" name="match_method" id="mm_email" value="email" checked>';
    echo '<label class="form-check-label" for="mm_email">';
    echo '<strong>By email address</strong> — uses the email from your NAT00085 file to find the matching Moodle account';
    echo '</label>';
    echo '</div>';
    echo '<div class="form-check mt-1">';
    echo '<input class="form-check-input" type="radio" name="match_method" id="mm_studentid" value="studentid">';
    echo '<label class="form-check-label" for="mm_studentid">';
    echo '<strong>By student number</strong> — matches the student\'s Client ID from the NAT file against their Moodle <em>ID number</em> or <em>username</em> (whichever is set)';
    echo '</label>';
    echo '</div>';
    echo '<small class="form-text text-muted mt-1">Choose <em>student number</em> if your students don\'t have email in the NAT file. Works whether student numbers are stored in the Moodle ID number field (most SMS integrations) or the username field.</small>';
    echo '</div>';

    echo '<div class="mb-3">';
    echo '<label class="font-weight-bold d-block mb-1" for="natfiles">Select NAT files to upload</label>';
    echo '<input type="file" name="natfiles[]" multiple accept=".txt" class="form-control" id="natfiles">';
    echo '<small class="form-text text-muted">Hold Ctrl/Cmd to select multiple files. All files from the same SMS export batch should be uploaded together.</small>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary" title="Upload the selected NAT files and import them into your records">Upload &amp; Import</button>';
    echo '</form>';
    echo '</div></div>';

    // v5.9.372 DATA-IMPORT-DECLUTTER: the always-visible "Fix Placeholder Student Names"
    // utility repaired auto-created Moodle accounts. The import no longer creates Moodle
    // accounts, so it has been removed.

    // Import history — ADD-HISTORY-PAGINATION (v4.9.138): previously hard-capped at 50
    // records with no way to see older imports. Replaced with 25-per-page pagination.
    echo '<h5>' . get_string('dataimport_history_heading', 'local_rtocompliance') . '</h5>';
    $histperpage = 25;
    $totalimps   = $DB->count_records('local_rtocompliance_avetmiss');
    $imports     = $DB->get_records('local_rtocompliance_avetmiss', null, 'timecreated DESC', '*', $histpage * $histperpage, $histperpage);

    if (empty($imports)) {
        echo $OUTPUT->notification(get_string('dataimport_no_imports', 'local_rtocompliance'), 'info');
    } else {
        echo '<div class="table-responsive"><table class="table table-hover">';
        echo '<thead class="thead-light"><tr>';
        echo '<th title="AVETMISS collection year for the import">' . get_string('dataimport_collection_year', 'local_rtocompliance') . '</th>';
        echo '<th title="Registered training organisation">' . get_string('dataimport_rto', 'local_rtocompliance') . '</th>';
        echo '<th title="Number of student records imported">' . get_string('dataimport_students', 'local_rtocompliance') . '</th>';
        echo '<th title="Number of enrolment records imported">' . get_string('dataimport_enrolments', 'local_rtocompliance') . '</th>';
        echo '<th title="Number of completion records imported">' . get_string('dataimport_completions', 'local_rtocompliance') . '</th>';
        echo '<th title="Number of records flagged for review">' . get_string('dataimport_flagged', 'local_rtocompliance') . '</th>';
        echo '<th title="Date and time the import was performed">' . get_string('dataimport_imported_at', 'local_rtocompliance') . '</th>';
        echo '<th title="View the details of this import"></th>';
        echo '</tr></thead><tbody>';
        foreach ($imports as $imp) {
            $detailurl = new moodle_url('/local/rtocompliance/data_import.php', ['importid' => $imp->id]);
            echo '<tr>';
            echo '<td><a href="' . $detailurl->out() . '"><strong>' . ($imp->collectionyear ? s($imp->collectionyear) . ' Import' : 'AVETMISS Import') . '</strong></a></td>';
            $rtoinfo = $imp->rtoid ? 'RTO ' . s($imp->rtoid) . ($imp->rtoname ? ' — ' . s($imp->rtoname) : '') : '—';
            echo '<td class="text-muted small">' . $rtoinfo . '</td>';
            echo '<td>' . $imp->totalstudents . '</td>';
            echo '<td>' . $imp->totalenrolments . '</td>';
            echo '<td>' . $imp->totalcompletions . '</td>';
            echo '<td>';
            if ($imp->flaggedrecords > 0) {
                echo '<span class="badge badge-warning" title="Number of records in this import with data problems that may need fixing.">' . $imp->flaggedrecords . ' flagged</span>';
            } else {
                echo '<span class="text-muted">0</span>';
            }
            echo '</td>';
            echo '<td class="text-muted small">' . userdate($imp->timecreated) . '</td>';
            echo '<td><a href="' . $detailurl->out() . '" class="btn btn-sm btn-outline-secondary" title="View the details of this import">View &rarr;</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Pagination controls — shown only when there are multiple pages.
        if ($totalimps > $histperpage) {
            $totalpages  = (int)ceil($totalimps / $histperpage);
            $firstonpage = $histpage * $histperpage + 1;
            $lastonpage  = min(($histpage + 1) * $histperpage, $totalimps);
            echo '<p class="text-muted small mt-2">Showing ' . $firstonpage . '–' . $lastonpage . ' of ' . $totalimps
                . ' import' . ($totalimps !== 1 ? 's' : '') . '</p>';
            echo '<nav aria-label="Import history pages"><ul class="pagination pagination-sm">';
            if ($histpage > 0) {
                $prevurl = new moodle_url('/local/rtocompliance/data_import.php', ['histpage' => $histpage - 1]);
                echo '<li class="page-item"><a class="page-link" href="' . $prevurl->out() . '">&laquo; Prev</a></li>';
            }
            for ($p = 0; $p < $totalpages; $p++) {
                $pageurl     = new moodle_url('/local/rtocompliance/data_import.php', ['histpage' => $p]);
                $activeclass = ($p === $histpage) ? ' active' : '';
                echo '<li class="page-item' . $activeclass . '"><a class="page-link" href="' . $pageurl->out() . '">' . ($p + 1) . '</a></li>';
            }
            if ($histpage < $totalpages - 1) {
                $nexturl = new moodle_url('/local/rtocompliance/data_import.php', ['histpage' => $histpage + 1]);
                echo '<li class="page-item"><a class="page-link" href="' . $nexturl->out() . '">Next &raquo;</a></li>';
            }
            echo '</ul></nav>';
        }
    }

} // end if ($action === 'autoenrol') / elseif / else chain

echo html_writer::end_div(); // close compliance-container

echo $OUTPUT->footer();
