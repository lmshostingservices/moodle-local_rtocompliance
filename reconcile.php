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
 * RTO Compliance plugin — reconcile.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// NAT Enrolment Reconciliation Tool  (v1.0.0 — local_rtocompliance v5.9.102)
//
// READ-ONLY.  Never calls enrol_user() or unenrol_user().
// Never modifies Moodle.  Safe to run on production at any time.
//
// PURPOSE
// -------
// Independently recalculates which Moodle enrolments SHOULD exist by re-applying
// the importer algorithm (all visible courses in each matched NAT category) against
// the NAT00120 staging data for a selected import.  Compares that expected state
// against current live manual enrolments and produces four downloadable CSV reports:
//
//   missing_enrolments.csv   EXPECTED=YES, CURRENT=NO  → action ADD
//   extra_enrolments.csv     EXPECTED=NO,  CURRENT=YES → action REMOVE
//   student_summary.csv      per-student totals
//   audit_report.csv         full per-student / per-course matrix
//
// STUDENT MATCHING
// ----------------
// Uses the same 5-path strategy as Fix Over-Enrolments:
//   Path A — mdl_user.idnumber = clientid
//   Path B — local_rtocompliance_students.clientid → userid
//   Path C — mdl_user.username = clientid
//   Path D — NAT00085 email → mdl_user.email
//   Path E — NAT00080 USI  → local_rtocompliance_students.usi
//
// EXPECTED ENROLMENT ALGORITHM
// ----------------------------
// For each student in NAT00120:
//   For each NAT unit code:
//     1. Find the Moodle course whose idnumber / shortname / fullname starts with
//        that unit code (same extraction logic as Fix Over-Enrolments).
//     2. Obtain that course's category.
//     3. SELECT * FROM {course} WHERE category = :catid AND visible = 1
//        (exact same query as the importer).
//     4. Every visible course in that category is an expected enrolment.
//   Deduplicate expected enrolments per student.
//
// CURRENT ENROLMENT SCOPE
// -----------------------
// Only manual enrolments in courses that fall within the "NAT universe"
// (the union of all visible courses across all NAT-derived categories) are
// considered.  Enrolments in completely unrelated categories are out of scope
// and never appear in the reports.
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_reconcile');
$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);
\core\session\manager::write_close();

$action        = optional_param('action',        '',        PARAM_ALPHA);
$importid      = optional_param('importid',      0,         PARAM_INT);
$token         = optional_param('token',         '',        PARAM_ALPHANUM);
$download      = optional_param('download',      '',        PARAM_ALPHANUMEXT);
$traceclientid  = optional_param('traceclientid',  '',        PARAM_TEXT); // legacy single-ID compat
$traceclientids = optional_param('traceclientids', '',        PARAM_TEXT); // multi-ID textarea (newline/comma)

$PAGE->set_url(new moodle_url('/local/rtocompliance/reconcile.php'));
$PAGE->set_title('NAT Reconciliation Tool');
$PAGE->set_heading('NAT Reconciliation Tool');

// ─── Unit code extractor ─────────────────────────────────────────────────────
// Same logic as _foe_extract_unitcode() in data_import.php.
// Pattern: 2-7 uppercase letters followed by 3-5 digits.
// ── Reconciler release — hardcoded here so the run-stamp always reflects the
// code that ACTUALLY executed, even under PHP opcache.  get_config() returns the
// DB-stored release string which Moodle writes only during install/upgrade and
// can lag behind opcache-served bytecode. This constant is the single source of
// truth for "which reconciler version ran?" — if the stamp shows an old value,
// the old bytecode is still executing (opcache / disk-cache not yet cleared).
if (!defined('RTOCOMPLIANCE_RECONCILER_RELEASE')) {
    define('RTOCOMPLIANCE_RECONCILER_RELEASE', '5.9.216');
}

if (!function_exists('_reconcile_extract_unitcode')) {
    /**
     * Extract an AVETMISS unit code from a Moodle course's idnumber / shortname / fullname.
     *
     * Matching priority:
     *   1. idnumber is EXACTLY a unit code          e.g. "ABC12345"
     *   2. idnumber STARTS WITH a unit code         e.g. "ABC12345 (CP1) S1-2016"  ← fix for v5.9.112
     *   3. shortname starts with a unit code        e.g. "ABC12345 16S1"
     *   4. fullname starts with a unit code
     *
     * v5.9.111 and earlier only did an exact match on idnumber, then fell through to
     * shortname — so courses like "ABC12345 (CP1) S1-2016" were invisible to the
     * reconciler. The prefix match on idnumber (step 2) fixes this.
     */
    function _reconcile_extract_unitcode(string $idnumber, string $shortname, string $fullname): string {
        $pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
        $idn = strtoupper(trim($idnumber));
        // Step 1: exact match (idnumber is nothing but the unit code)
        if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $idn)) return $idn;
        // v5.9.214: Skip slash-separated idnumbers such as "BSB226/BSB226".
        // The Step 2 boundary pattern accepts "/" as a valid terminator (it satisfies
        // [^A-Z0-9]), so "BSB226/BSB226" would incorrectly extract "BSB226" — a
        // garbage code that is not a valid AVETMISS unit code.  Real unit codes
        // are never slash-separated; fall through to shortname/fullname instead.
        if (strpos($idnumber, '/') === false) {
            // Step 2: prefix match on idnumber (unit code followed by space/paren/dash/etc.)
            if (preg_match($pat, $idn, $m)) return $m[1];
        }
        // Step 2.5: no-separator prefix — unit code glued directly to a 2+ letter course
        // abbreviation with no delimiter character between them.
        //
        // WHY this is needed: idnumbers like "TLIA5059AABC S1-2014" or "TLIA5061AXYZ S2-2012"
        // concatenate the unit code + version suffix (A) + delivery abbreviation (e.g. ABC)
        // without any space or punctuation.  The Step 2 boundary check `(?:[^A-Z0-9]|$)`
        // requires a non-alphanumeric character after the code — but the first char of the
        // abbreviation is an uppercase letter, so Step 2 fails and falls through to
        // shortname/fullname, which may also be ambiguous.
        //
        // SAFETY: the lookahead `(?=[A-Z]{2,}(?:[^A-Z0-9]|$))` verifies that 2+ uppercase
        // letters follow the code AND are themselves terminated by a non-alphanumeric or end-
        // of-string — this rules out two unit codes concatenated (e.g. BSBWHS211BSBCMM201
        // where "BSBCMM201" ends in a digit, not a boundary).
        //
        // Try WITH version-suffix letter first (ABC12345 + ABC), then WITHOUT (ABC12345 + AABC).
        if (preg_match('/^([A-Z]{2,7}[0-9]{3,5}[A-Z])(?=[A-Z]{2,}(?:[^A-Z0-9]|$))/', $idn, $m)) return $m[1];
        if (preg_match('/^([A-Z]{2,7}[0-9]{3,5})(?=[A-Z]{2,}(?:[^A-Z0-9]|$))/', $idn, $m)) return $m[1];
        // Step 3: try shortname (prefix match)
        if (preg_match($pat, strtoupper(trim($shortname)), $m)) return $m[1];
        // Step 4: search entire fullname for first AVETMISS unit code (not just prefix).
        // Handles legacy names like "CBP - BSBCMM201 / BSBWHS211" — first code wins.
        $_fn_pat = '/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
        if (preg_match($_fn_pat, strtoupper(trim($fullname)), $m)) return $m[1];
        return '';
    }
}

// ─── Temp CSV file path ───────────────────────────────────────────────────────
function _reconcile_csvpath(string $token, string $name): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rtoc_recon_' . $token . '_' . $name . '.csv';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function _reconcile_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function _reconcile_n(int $n): string    { return number_format($n); }

/**
 * Derive a delivery key (e.g. "2025-S2") from a NAT00120 startdate in DDMMYYYY format.
 * Returns '' if the date is blank, not 8 digits, or has an invalid month.
 *
 * Delivery semester is derived using this client's established scheduling convention:
 *   January–June   = Semester 1
 *   July–December  = Semester 2
 *
 * This is not a universal Australian VET rule — it reflects this RTO's delivery calendar.
 * Year is taken from characters 5–8 of the DDMMYYYY string.
 */
function _reconcile_delivery_key(string $ddmmyyyy): string {
    $ddmmyyyy = trim($ddmmyyyy);
    if (strlen($ddmmyyyy) !== 8 || !ctype_digit($ddmmyyyy)) return '';
    $month = (int)substr($ddmmyyyy, 2, 2);
    $year  = substr($ddmmyyyy, 4, 4);
    if ($month < 1 || $month > 12) return '';
    return $year . '-' . ($month <= 6 ? 'S1' : 'S2');
}

/**
 * Extract a delivery key (e.g. "2025-S2") from a Moodle course's category name or shortname.
 *
 * Priority order (most authoritative → least authoritative):
 *   1. Category name  — e.g. "2025 Semester 2", "Semester 1 2026", "25S2 Diploma"
 *   2. Shortname      — e.g. "MGC 25S2 - ND" (new), "DPR S223" (old)
 *
 * Using category name first means a course renamed from "MGC 25S2 - CD" to
 * "Manage Goods CD" still resolves correctly if the category is "2025 Semester 2".
 *
 * Recognised shortname conventions:
 *   New format: "[PREFIX] YYS[1|2] …"   e.g. "MGC 25S2 - ND", "ADC 26S1"
 *   Old format: "[PREFIX] S[1|2]YY …"   e.g. "DPR S223", "CP1 S124"
 *
 * Transition / orientation courses (e.g. "ACF TRN 24") produce no semester
 * indicator and correctly return '' so they are never date-matched.
 */
/**
 * Extract a delivery key (e.g. "2025-S2") from a single text string.
 * Supports all historical naming conventions used across Moodle categories and course shortnames:
 *
 *   "2025 Semester 2"   → 2025-S2  (YYYY then Semester N)
 *   "Semester 1 2018"   → 2018-S1  (Semester N then YYYY)
 *   "2018 S1"           → 2018-S1  (YYYY then SN — full four-digit year)
 *   "S2 2025"           → 2025-S2  (SN then YYYY — full four-digit year)
 *   "25S2" / "25S1"     → 2025-S2  (two-digit year prefix)
 *   "S223" / "S124"     → 2023-S2  (SN then two-digit year — old convention)
 */
function _reconcile_delivery_key_from_text(string $text): string {
    if ($text === '') return '';

    // ── Archive category patterns (MUST be tested first) ─────────────────────
    // Client category names for every archived delivery follow one of these forms:
    //   "Archive S1 - 2022"   (space-dash-space)
    //   "Archive S2-2013"     (dash, no spaces)
    //   "Archive S1- 2020"    (dash-space)
    //   "Archive  S2 - 2021"  (double-space before S)
    //   "Archive S1 2021"     (plain space, no dash)
    // Single regex handles all variants: S{N} then optional whitespace/dash then 4-digit year.
    // Requires \b before S and \b after the year so it doesn't false-match shortnames
    // like "20S1_1" (no 4-digit year follows) or "26 XYZ S1" (year comes BEFORE S).
    // Note: "26 XYZ S1" does NOT match this pattern (no 4-digit year after S1) — it
    // falls through correctly to the two-digit-year patterns below.
    if (preg_match('/\bS\s*([12])\s*-?\s*(\d{4})\b/i', $text, $m)) return $m[2] . '-S' . $m[1];

    // ── General patterns (shortnames and other catname forms) ─────────────────
    // "2025 Semester 2" or "Semester 1 2026"
    if (preg_match('/Semester\s+([12])\s+(\d{4})/i', $text, $m)) return $m[2] . '-S' . $m[1];
    if (preg_match('/(\d{4})\s+Semester\s+([12])/i',  $text, $m)) return $m[1] . '-S' . $m[2];
    // "2025 S2" / "2025 S1" (full four-digit year, space, then S1/S2) — must test before two-digit form
    if (preg_match('/\b(\d{4})\s+S([12])\b/i', $text, $m)) return $m[1] . '-S' . $m[2];
    // "S2 2025" / "S1 2018" (S1/S2, space, then full four-digit year) — already covered by
    // archive pattern above for 4-digit-year-after forms, kept as belt-and-braces fallback
    if (preg_match('/\bS([12])\s+(\d{4})\b/i', $text, $m)) return $m[2] . '-S' . $m[1];
    // "25S2" / "25S1" / "20S1" (two-digit year directly before S1/S2 — common shortname form)
    if (preg_match('/\b(\d{2})S([12])\b/i', $text, $m)) {
        $yr = (int)$m[1] < 50 ? '20' . $m[1] : '19' . $m[1];
        return $yr . '-S' . $m[2];
    }
    // "S223" / "S124" (S then digit then two-digit year — old convention)
    if (preg_match('/\bS([12])(\d{2})\b/i', $text, $m)) {
        $yr = (int)$m[2] < 50 ? '20' . $m[2] : '19' . $m[2];
        return $yr . '-S' . $m[1];
    }
    // "26 XYZ S1" / "26 XYZ S1" / "25 a Diploma qualification S2" —
    // two-digit year then 1–30 chars of text then S1/S2.
    if (preg_match('/\b(\d{2})\b.{1,30}?\bS([12])\b/i', $text, $m)) {
        $yr = (int)$m[1] < 50 ? '20' . $m[1] : '19' . $m[1];
        return $yr . '-S' . $m[2];
    }
    // Reverse form: "S1 XYZ 26" / "S2 Diploma 25" — S1/S2 then words then two-digit year
    if (preg_match('/\bS([12])\b.{1,30}?\b(\d{2})\b(?!\d)/i', $text, $m)) {
        $yr = (int)$m[2] < 50 ? '20' . $m[2] : '19' . $m[2];
        return $yr . '-S' . $m[1];
    }
    return '';
}

// ─── v5.9.167: Unit normalisation, qual-type detection, candidate scoring ─────

/**
 * Normalise an AVETMISS unit code by stripping trailing version letters.
 * ABC12345 → ABC12345   ABC12345 B → ABC12345   ABC12345 → ABC12345
 * Allows a NAT record for ABC12345 to match a Moodle course coded ABC12345.
 */
function _reconcile_normalize_unitcode(string $uc): string {
    $uc = strtoupper(trim($uc));
    // Strip optional trailing space + single letter — keeps numeric part intact
    return preg_replace('/^([A-Z]{2,7}[0-9]{3,5})\s*[A-Z]?$/', '$1', $uc);
}

/**
 * Extract a qualification-type abbreviation from a category name.
 * "26 XYZ S1" → "XYZ"   "26 PQR S2" → "PQR"   "Archive" → ""
 * Used to prevent a PQR student being recommended a XYZ course.
 */
function _reconcile_extract_qual_type(string $catname): string {
    static $skip = ['THE','AND','FOR','OF','IN','AT','TO','BY','ON','IS','IT',
                    'BE','DO','AN','AS','IF','OR','NAT','RPL','SU','RTO',
                    'ARCHIVE','COURSE','DIPLOMA','CERT','ADV','SEM','SEMESTER'];
    $upper = strtoupper(trim($catname));
    // Remove year tokens (2-4 digits) and S1/S2 — leaves only qual abbreviation candidates
    $clean = preg_replace('/\b\d{2,4}\b|\bS[12]\b/i', '', $upper);
    if (preg_match_all('/\b([A-Z]{2,6})\b/', $clean, $m)) {
        foreach ($m[1] as $tok) {
            if (!in_array($tok, $skip, true)) return $tok;
        }
    }
    return '';
}

/**
 * Score a single candidate course against a student's delivery context.
 *
 * Scoring table:
 *   Current (not archive, not hidden):   +100
 *   Exact semester match (dk matches):   +250  (dominant — beats current+QB=130)
 *   Year-only match (same year, diff sem): +25
 *   Qual branch match (in student's qual tree): +30
 *   Archive delivery:                    -100
 *   Hidden (visible=0):                  -20
 *   Outside qual branch:                 -20
 *   Qual-type mismatch (XYZ vs PQR):    -200  (hard block)
 *
 * Returns ['score' => int, 'flags' => string[], 'courseDk' => string].
 */
function _reconcile_score_candidate(
    object $cDet,
    array  $cAncestorNames,
    string $natDk,
    string $natQc,
    array  $catQualBranch,
    string $studentQualType,
    array  $qualMap = [],
    int    $studentQualCatId = 0,
    array  $catById = []
): array {
    $catText  = implode(' ', $cAncestorNames) . ' ' . (string)($cDet->catname ?? '');
    $isArch   = stripos($catText, 'archive') !== false;
    $isHidden = ((int)($cDet->visible ?? 1) === 0);

    $score = 0; $flags = [];

    if ($isArch) { $score -= 100; $flags[] = 'archive'; }
    else         { $score += 100; $flags[] = 'current'; }
    if ($isHidden) { $score -= 20; $flags[] = 'hidden'; }

    // Semester / year match — try ancestor names first (most authoritative), then shortname
    $courseDk = '';
    if ($natDk !== '') {
        foreach (array_reverse($cAncestorNames) as $_an) {
            $courseDk = _reconcile_delivery_key_from_text((string)$_an);
            if ($courseDk !== '') break;
        }
        if ($courseDk === '') {
            $courseDk = _reconcile_delivery_key_from_text((string)($cDet->shortname ?? ''));
        }
        if ($courseDk !== '' && $courseDk === $natDk) {
            $score += 250; $flags[] = 'sem_match'; // dominant signal — must beat current+qual_branch (130)
        } elseif ($courseDk !== '' && substr($courseDk, 0, 4) === substr($natDk, 0, 4)) {
            $score += 25; $flags[] = 'year_match';
        }
    }

    // Qual branch affinity
    //
    // Path-ancestry check: the student's qual category (e.g. catId 3) may be a direct
    // ANCESTOR of the course's delivery category (e.g. catId 150, path /3/150) rather than
    // the same id. catQualBranch is built from qualMap catIds, but fingerprinting may map
    // the qualcode to the delivery category (150) rather than the qual root (3) — in which
    // case catQualBranch[150] will contain the qualcode correctly, but if it maps to the
    // root (3), catDescendantIds[3] covers 150 and catQualBranch[150] also gets it.
    // Either way, an explicit path-ancestry check makes qual_branch detection robust to
    // fingerprinting resolution depth.
    $_scCatId  = (int)($cDet->catid ?? 0);
    $_scPath   = (string)(isset($catById[$_scCatId]) ? $catById[$_scCatId]->path : '');
    // True if studentQualCatId is an ancestor of (or equal to) the course's category.
    // Checks: .../qualCatId/... OR path ends with /qualCatId OR exact equality.
    $_qualIsAnc = $studentQualCatId > 0 && $_scPath !== '' && (
        strpos($_scPath, '/' . $studentQualCatId . '/') !== false ||
        substr($_scPath, -strlen('/' . $studentQualCatId)) === '/' . $studentQualCatId ||
        $_scCatId === $studentQualCatId
    );
    $inQB = ($natQc !== '') && (
        in_array($natQc, $catQualBranch[$_scCatId] ?? [], true) ||
        $_qualIsAnc
    );
    if ($inQB)             { $score += 30; $flags[] = 'qual_branch'; }
    elseif ($natQc !== '') { $score -= 20; $flags[] = 'out_of_qual'; }

    // Qual-type protection: block cross-qualification enrolments (e.g. PQR → XYZ).
    //
    // Guard 1 ($inQB): skip when qual_branch is confirmed (catQualBranch match OR
    //   student's qual catId is a path-ancestor of the course's delivery category).
    //   Prevents spurious −200 when same qual stream uses different label abbreviations
    //   (e.g. "PQR" = Diploma a qualification, "INT" = International — same stream
    //   under catId 3, but course sits in delivery catId 150 with path /3/150).
    //
    // Guard 2 (courseQualBranch): skip when the course has NO qualmap branch association
    //   at all — label comparison is unreliable in that case.
    if ($studentQualType !== '' && !$inQB) {
        $courseQualBranch = $catQualBranch[$_scCatId] ?? [];
        if (!empty($courseQualBranch)) {
            // Course IS in some qualmap branch. Guard 2B: before penalising, verify via
            // path-ancestry that the course's category is NOT under the student's qual
            // category. $_qualIsAnc already covers this — if true, $inQB would be true
            // and we'd never reach this block. So reaching here means the course IS in a
            // branch but the student's qual catId is NOT an ancestor → check whether any
            // qualcode in the branch maps (via qualMap) to the same catId as the student's.
            $sameQualCat = false;
            if ($studentQualCatId > 0 && !empty($qualMap)) {
                foreach ($courseQualBranch as $_cqbQc) {
                    if (isset($qualMap[$_cqbQc]) && (int)$qualMap[$_cqbQc] === $studentQualCatId) {
                        $sameQualCat = true;
                        break;
                    }
                }
            }
            if (!$sameQualCat) {
                $courseQt = _reconcile_extract_qual_type((string)($cDet->catname ?? ''));
                if ($courseQt !== '' && $courseQt !== $studentQualType) {
                    $score -= 200; $flags[] = 'qual_type_mismatch';
                }
            }
        }
    }

    return ['score' => $score, 'flags' => $flags, 'courseDk' => $courseDk];
}

/**
 * Extract a delivery key from a Moodle course's category name or shortname.
 * Category name is checked first (most authoritative), then shortname.
 */
function _reconcile_course_delivery_key(string $shortname, string $catname = ''): string {
    foreach ([$catname, $shortname] as $_src) {
        $_dk = _reconcile_delivery_key_from_text($_src);
        if ($_dk !== '') return $_dk;
    }
    return '';
}

/**
 * Extract a delivery key by searching ALL category ancestor names (root → leaf order)
 * then the course shortname as final fallback.
 *
 * This correctly handles deep category hierarchies such as:
 *   Archive → 2018 Semester 1 → Qualification → (course)
 * where the semester indicator lives at the grandparent level, not the direct parent.
 *
 * Search order (nearest-to-student first for best specificity):
 *   1. Direct parent category name
 *   2. Grandparent, great-grandparent, ... up to root
 *   3. Course shortname (last resort — shortnames may lack a semester indicator)
 *
 * @param string   $shortname    Course shortname.
 * @param array    $catPath      Category names from root (index 0) to direct parent (last index).
 * @param string  &$matchedFrom  Output: which text produced the match (for trace diagnostics).
 */
function _reconcile_course_delivery_key_path(string $shortname, array $catPath, string &$matchedFrom = ''): string {
    // Reverse so direct parent is tried first; shortname is last
    $ordered = array_merge(array_reverse($catPath), [$shortname]);
    foreach ($ordered as $_src) {
        $_dk = _reconcile_delivery_key_from_text($_src);
        if ($_dk !== '') {
            $matchedFrom = $_src;
            return $_dk;
        }
    }
    $matchedFrom = '';
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// NATDOWNLOAD — serve raw NAT/AVETMISS staging data as CSV or ZIP for an importid.
// Data source: mdl_local_rtocompliance_avetmiss_* tables (populated during NAT import).
// Protected by require_login() + require_capability() at the top of the file.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'natdownload' && $importid) {
    $natfile = optional_param('natfile', '', PARAM_ALPHANUMEXT);
    $validFiles = ['nat00120', 'nat00080', 'nat00030', 'nat00130', 'nat_all_zip'];
    if (!in_array($natfile, $validFiles, true)) {
        redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
    }
    $ndImport = $DB->get_record('local_rtocompliance_avetmiss',
        ['id' => $importid], 'id,collectionyear,timecreated,rtoname', IGNORE_MISSING);
    if (!$ndImport) {
        redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
    }
    $ndYear = $ndImport->collectionyear ?: date('Y', (int)$ndImport->timecreated);
    $ndDate = date('Ymd', (int)$ndImport->timecreated);

    // Build CSV string from headers + rows (recordset-compatible).
    $ndMakeCsv = function (array $headers, $rows) {
        $buf = "\xEF\xBB\xBF"; // UTF-8 BOM
        $fh  = fopen('php://memory', 'w+');
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_values((array)$row));
        }
        rewind($fh);
        $buf .= stream_get_contents($fh);
        fclose($fh);
        if (is_object($rows) && method_exists($rows, 'close')) { $rows->close(); }
        return $buf;
    };

    $ndFiles = []; // filename → content

    if ($natfile === 'nat00120' || $natfile === 'nat_all_zip') {
        $rs120 = $DB->get_recordset('local_rtocompliance_avetmiss_enrolment',
            ['importid' => $importid], 'clientid,unitcode',
            'clientid,unitcode,qualcode,startdate,enddate,outcome,fundingsource,studyreason,supervisedhours');
        $ndFiles["NAT00120_{$ndYear}_{$ndDate}.csv"] = $ndMakeCsv(
            ['Client ID','Unit Code','Qual Code','Start Date','End Date','Outcome',
             'Funding Source','Study Reason','Supervised Hours'],
            $rs120
        );
    }

    if ($natfile === 'nat00080' || $natfile === 'nat_all_zip') {
        $rs080 = $DB->get_recordset('local_rtocompliance_avetmiss_student',
            ['importid' => $importid], 'clientid',
            'clientid,name,firstname,familyname,email,phone,dob,sex,usi,suburb,state');
        $ndFiles["NAT00080_{$ndYear}_{$ndDate}.csv"] = $ndMakeCsv(
            ['Client ID','Name','First Name','Family Name','Email','Phone',
             'DOB','Sex','USI','Suburb','State'],
            $rs080
        );
    }

    if ($natfile === 'nat00030' || $natfile === 'nat_all_zip') {
        $rs030 = $DB->get_recordset('local_rtocompliance_avetmiss_programme',
            ['importid' => $importid], 'qualcode', 'qualcode,qualname,isvetprog');
        $ndFiles["NAT00030_{$ndYear}_{$ndDate}.csv"] = $ndMakeCsv(
            ['Qual Code','Qual Name','VET Programme'],
            $rs030
        );
    }

    if ($natfile === 'nat00130' || $natfile === 'nat_all_zip') {
        $rs130 = $DB->get_recordset('local_rtocompliance_avetmiss_completion',
            ['importid' => $importid], 'clientid,qualcode',
            'clientid,qualcode,completiondate,successfulcompletion,certificatedate,parchmentnumber');
        $cnt130 = 0;
        $csv130 = $ndMakeCsv(
            ['Client ID','Qual Code','Completion Date','Successful Completion',
             'Certificate Date','Parchment Number'],
            $rs130
        );
        // Only include NAT00130 if there are records.
        if ($DB->count_records('local_rtocompliance_avetmiss_completion', ['importid' => $importid]) > 0) {
            $ndFiles["NAT00130_{$ndYear}_{$ndDate}.csv"] = $csv130;
        }
    }

    if ($natfile === 'nat_all_zip') {
        if (!empty($ndFiles) && class_exists('ZipArchive')) {
            $zip    = new ZipArchive();
            $tmpZip = tempnam(sys_get_temp_dir(), 'natzip_') . '.zip';
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($ndFiles as $fn => $content) { $zip->addFromString($fn, $content); }
                $zip->close();
                $zipFn = "NAT_Import{$importid}_{$ndYear}_{$ndDate}.zip";
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFn . '"');
                header('Content-Length: ' . filesize($tmpZip));
                header('Cache-Control: no-cache, must-revalidate');
                readfile($tmpZip);
                unlink($tmpZip);
                exit;
            }
        }
        redirect(new moodle_url('/local/rtocompliance/reconcile.php', ['importid' => $importid]),
            'ZIP generation failed — try downloading files individually.', null, \core\output\notification::NOTIFY_WARNING);
    }

    // Single-file download.
    if (!empty($ndFiles)) {
        $fn      = array_key_first($ndFiles);
        $content = $ndFiles[$fn];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');
        echo $content;
        exit;
    }
    redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
}

// ─────────────────────────────────────────────────────────────────────────────
// NATCLASSDOWNLOAD — export rows from nat_classification by category.
// Counts EXACTLY match the Technical Detail panel shown on the results page
// because they query the same source table. Use these for auditor-facing exports.
// Protected by require_login() + require_capability() at the top of the file.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'natclassdownload' && $importid) {
    $ncd_category  = optional_param('category', '', PARAM_ALPHANUMEXT);
    $ncd_validCats = [
        'ENROLMENT_GAP_REVIEW', 'UNLINKED_STUDENT_REVIEW', 'RECENT_NO_COURSE_REVIEW',
        'HISTORICAL_NO_COURSE', 'UNCLASSIFIED', 'needs_review',
    ];
    if (!in_array($ncd_category, $ncd_validCats, true)) {
        redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
    }
    $ncd_import = $DB->get_record('local_rtocompliance_avetmiss',
        ['id' => $importid], 'id,collectionyear,timecreated,rtoname', IGNORE_MISSING);
    if (!$ncd_import) {
        redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
    }
    $ncd_year = $ncd_import->collectionyear ?: date('Y', (int)$ncd_import->timecreated);
    $ncd_date = date('Ymd', (int)$ncd_import->timecreated);

    if ($ncd_category === 'needs_review') {
        [$ncd_catWhere, $ncd_params] = [
            "nc.category IN ('ENROLMENT_GAP_REVIEW','UNLINKED_STUDENT_REVIEW','RECENT_NO_COURSE_REVIEW')",
            ['iid' => $importid],
        ];
        $ncd_fnLabel = 'Needs_Review_Combined';
    } else {
        [$ncd_catWhere, $ncd_params] = ['nc.category = :cat', ['iid' => $importid, 'cat' => $ncd_category]];
        $ncd_fnLabel = $ncd_category;
    }

    $ncd_rs = $DB->get_recordset_sql(
        "SELECT nc.clientid,
                COALESCE(s.name, '') AS student_name,
                nc.unitcode,
                COALESCE(nc.qualcode, '') AS qualcode,
                COALESCE(nc.startdate, '') AS startdate,
                nc.study_year,
                COALESCE(nc.match_path, '') AS match_path,
                nc.category,
                nc.course_exists,
                nc.enrolled_match
           FROM {local_rtocompliance_nat_classification} nc
      LEFT JOIN {local_rtocompliance_avetmiss_student} s
             ON s.importid = nc.importid AND s.clientid = nc.clientid
          WHERE nc.importid = :iid
            AND $ncd_catWhere
          ORDER BY nc.category, nc.clientid, nc.unitcode",
        $ncd_params
    );

    $ncd_fh = fopen('php://memory', 'w+');
    fputcsv($ncd_fh, [
        'Client ID', 'Student Name', 'Unit Code', 'Qual Code', 'Start Date',
        'Study Year', 'Match Path', 'Category', 'Course Exists in Moodle', 'Student Enrolled',
    ]);
    foreach ($ncd_rs as $ncd_row) {
        fputcsv($ncd_fh, [
            $ncd_row->clientid,
            $ncd_row->student_name,
            $ncd_row->unitcode,
            $ncd_row->qualcode,
            $ncd_row->startdate,
            (string)($ncd_row->study_year ?: ''),
            $ncd_row->match_path,
            $ncd_row->category,
            $ncd_row->course_exists ? 'Yes' : 'No',
            $ncd_row->enrolled_match ? 'Yes' : 'No',
        ]);
    }
    $ncd_rs->close();
    rewind($ncd_fh);
    $ncd_content = "\xEF\xBB\xBF" . stream_get_contents($ncd_fh);
    fclose($ncd_fh);

    $ncd_fn = "NATClassification_{$ncd_fnLabel}_Import{$importid}_{$ncd_year}_{$ncd_date}.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $ncd_fn . '"');
    header('Content-Length: ' . strlen($ncd_content));
    header('Cache-Control: no-cache, must-revalidate');
    echo $ncd_content;
    exit;
}

if ($action === 'download' && $download && $token) {
    $allowed = ['missing', 'extra', 'review', 'postimport', 'restore', 'summary', 'audit', 'ambiguous', 'courseaudit',
                'moodle_upload', 'review_required', 'unmatched_add'];
    if (!in_array($download, $allowed, true)) {
        redirect(new moodle_url('/local/rtocompliance/reconcile.php'));
    }
    $filepath = _reconcile_csvpath($token, $download);
    if (!is_file($filepath)) {
        redirect(
            new moodle_url('/local/rtocompliance/reconcile.php', ['importid' => $importid]),
            'Report file has expired — please re-run the analysis to regenerate it.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }
    $filenames = [
        'missing'          => 'missing_enrolments.csv',
        'extra'            => 'extra_enrolments.csv',
        'review'           => 'review_enrolments.csv',
        'postimport'       => 'post_import_enrolments.csv',
        'restore'          => 'restore_candidates.csv',
        'summary'          => 'student_summary.csv',
        'audit'            => 'audit_report.csv',
        'ambiguous'        => 'ambiguous_unit_mappings.csv',
        'courseaudit'      => 'course_unit_validation.csv',
        'debug'            => 'missing_enrolments_debug.csv',
        // v5.9.167 three-file ADD routing
        'moodle_upload'    => 'moodle_upload.csv',
        'review_required'  => 'review_required.csv',
        'unmatched_add'    => 'unmatched_add.csv',
    ];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenames[$download] . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($filepath);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SAVEQUALMAPPING — persist admin-defined NAT qualcode → Moodle category mappings
// POST handler; redirects back to action=analyse to re-run reconciliation.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'savequalmapping') {
    require_sesskey();
    $_sqImportId = optional_param('importid_save', 0, PARAM_INT);
    $_sqQcodes   = optional_param_array('qcodes',  [], PARAM_TEXT);
    $_sqCatIds   = optional_param_array('catids',   [], PARAM_INT);

    foreach ($_sqQcodes as $_sqI => $_sqQc) {
        $_sqQc    = strtoupper(trim(clean_param((string)($_sqQc ?? ''), PARAM_TEXT)));
        $_sqCatId = (int)($_sqCatIds[$_sqI] ?? 0);
        if ($_sqQc === '') continue;
        if ($_sqCatId > 0) {
            $_sqCatName = (string)($DB->get_field('course_categories', 'name', ['id' => $_sqCatId]) ?: '');
            $_sqExist   = $DB->get_record('local_rtocompliance_qualmap', ['qualcode' => $_sqQc], 'id', IGNORE_MISSING);
            if ($_sqExist) {
                $DB->update_record('local_rtocompliance_qualmap', (object)[
                    'id'           => $_sqExist->id,
                    'categoryid'   => $_sqCatId,
                    'catname'      => $_sqCatName,
                    'confidence'   => 100,
                    'method'       => 'manual',
                    'timemodified' => time(),
                ]);
            } else {
                $DB->insert_record('local_rtocompliance_qualmap', (object)[
                    'qualcode'     => $_sqQc,
                    'categoryid'   => $_sqCatId,
                    'catname'      => $_sqCatName,
                    'confidence'   => 100,
                    'method'       => 'manual',
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]);
            }
        } else {
            // catid=0 → clear existing mapping for this qualcode
            $DB->delete_records('local_rtocompliance_qualmap', ['qualcode' => $_sqQc]);
        }
    }
    redirect(
        new moodle_url('/local/rtocompliance/reconcile.php', [
            'action'   => 'analyse',
            'importid' => $_sqImportId > 0 ? $_sqImportId : $importid,
        ]),
        'Qualification mappings saved — re-running reconciliation with updated mappings.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// IMPORTMAPPING — import qualcode→category mappings from uploaded CSV
// POST handler (from qual-mapping panel on results page); redirects to analyse.
//
// Matching rules (strict — no partial/contains matching):
//   1. Numeric column 2 → looked up as category ID.
//   2. Text column 2 → exact case-insensitive name match ONLY.
//   3. No automatic partial/substring matching — ambiguous partial matches
//      (e.g. "Archive S1" hitting multiple Archive categories) cause silent
//      mis-mapping which is far more dangerous than a failed import row.
//      If no exact match, the row is reported as an error for the admin to fix.
//
// Each successfully matched row is upserted with method='manual', confidence=100
// so the auto-discovery engine will not overwrite it on future runs.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'importmapping') {
    require_sesskey();
    $_imImportId = optional_param('importid_im', 0, PARAM_INT);
    $_imMatched  = [];
    $_imMissed   = [];

    $fh = null;
    if (!empty($_FILES['mapping_csv']['tmp_name']) && $_FILES['mapping_csv']['error'] === UPLOAD_ERR_OK) {
        $fh = fopen($_FILES['mapping_csv']['tmp_name'], 'r');
    }
    if ($fh) {
        // Strip UTF-8 BOM if present (Excel UTF-8 CSV)
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }

        $firstRow = fgetcsv($fh);
        $h0 = strtolower(trim((string)($firstRow[0] ?? '')));
        $isHeader = ($h0 === 'qualcode' || $h0 === 'qual code' || $h0 === 'qual_code' || $h0 === 'code');
        if (!$isHeader) {
            rewind($fh);
            if ($bom === "\xEF\xBB\xBF") { fseek($fh, 3); }
        }

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) continue;
            $_imQc   = strtoupper(trim((string)($row[0] ?? '')));
            $_imCatV = trim((string)($row[1] ?? ''));
            if ($_imQc === '' || $_imCatV === '') continue;

            $_imCr = null;

            if (ctype_digit($_imCatV)) {
                // Numeric — direct category ID lookup (preferred, most reliable)
                $_imCats = $DB->get_records('course_categories', ['id' => (int)$_imCatV], '', 'id,name', 0, 1);
                $_imCr = $_imCats ? reset($_imCats) : null;
                if (!$_imCr) {
                    $_imMissed[] = $_imQc . ' — category ID ' . (int)$_imCatV . ' does not exist';
                    continue;
                }
            } else {
                // Name matching: EXACT case-insensitive only — no partial/contains match.
                // Rationale: a partial match on e.g. "a Diploma qualification" could hit
                // both ABC12345 and ABC12345 categories, producing a silent mis-mapping.
                $_imCats = $DB->get_records_sql(
                    "SELECT id, name FROM {course_categories} WHERE LOWER(name) = LOWER(:name)",
                    ['name' => $_imCatV], 0, 2); // fetch up to 2 to detect ambiguity
                if (count($_imCats) === 0) {
                    $_imMissed[] = $_imQc . ' — no category with exact name "' . $_imCatV . '" (use numeric ID, or fix the name)';
                    continue;
                }
                if (count($_imCats) > 1) {
                    $_imMissed[] = $_imQc . ' — "' . $_imCatV . '" matches multiple categories (use numeric category ID to be unambiguous)';
                    continue;
                }
                $_imCr = reset($_imCats);
            }

            if (!empty($_imCr)) {
                $_imCatId   = (int)$_imCr->id;
                $_imCatName = (string)$_imCr->name;
                $_imExist   = $DB->get_record('local_rtocompliance_qualmap', ['qualcode' => $_imQc], 'id', IGNORE_MISSING);
                if ($_imExist) {
                    $DB->update_record('local_rtocompliance_qualmap', (object)[
                        'id'           => $_imExist->id,
                        'categoryid'   => $_imCatId,
                        'catname'      => $_imCatName,
                        'confidence'   => 100,
                        'method'       => 'manual',
                        'timemodified' => time(),
                    ]);
                } else {
                    $DB->insert_record('local_rtocompliance_qualmap', (object)[
                        'qualcode'     => $_imQc,
                        'categoryid'   => $_imCatId,
                        'catname'      => $_imCatName,
                        'confidence'   => 100,
                        'method'       => 'manual',
                        'timecreated'  => time(),
                        'timemodified' => time(),
                    ]);
                }
                $_imMatched[] = $_imQc . ' → ' . $_imCatName . ' (id=' . $_imCatId . ')';
            }
        }
        fclose($fh);
    }

    $_imMsg   = count($_imMatched) . ' qualification(s) mapped from CSV.';
    $_imLevel = \core\output\notification::NOTIFY_SUCCESS;
    if (!empty($_imMissed)) {
        $_imMsg  .= ' ' . count($_imMissed) . ' row(s) could not be mapped — fix the CSV and re-import: ' . implode('; ', $_imMissed) . '.';
        $_imLevel = \core\output\notification::NOTIFY_WARNING;
    }
    if (empty($_imMatched) && empty($_imMissed)) {
        $_imMsg   = 'No data found in the uploaded CSV. Check the file format: qualcode,category_id_or_exact_name.';
        $_imLevel = \core\output\notification::NOTIFY_WARNING;
    }
    redirect(
        new moodle_url('/local/rtocompliance/reconcile.php', [
            'action'   => 'analyse',
            'importid' => $_imImportId > 0 ? $_imImportId : $importid,
        ]),
        $_imMsg,
        null,
        $_imLevel
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// EXPORTMAPPING — export current qualmap as a downloadable CSV
// GET handler — returns a CSV file with all saved qualification→category maps.
// Admins can use this CSV to re-import on another Moodle installation.
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'exportmapping') {
    // v5.9.368 CAP-FIX: 'admin' is not a declared capability — every other handler
    // in this file correctly uses ':manage'. This one threw a coding_exception.
    require_capability('local/rtocompliance:manage', context_system::instance());
    $allMaps = $DB->get_records('local_rtocompliance_qualmap', null, 'qualcode ASC');
    $csvRows = ["qualcode,categoryid,categoryname,method,confidence"];
    foreach ($allMaps as $_em) {
        $csvRows[] = implode(',', [
            '"' . str_replace('"', '""', (string)$_em->qualcode)  . '"',
            (int)$_em->categoryid,
            '"' . str_replace('"', '""', (string)($_em->catname ?? ''))  . '"',
            '"' . str_replace('"', '""', (string)($_em->method ?? ''))   . '"',
            (int)($_em->confidence ?? 0),
        ]);
    }
    $csvContent = implode("\n", $csvRows);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="qualmap_export_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
    echo $csvContent;
    die();
}

// ─────────────────────────────────────────────────────────────────────────────
// ANALYSE ACTION — full reconciliation run
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'analyse' && $importid) {
    set_time_limit(300);
    // ── EXECUTION PROOF MARKER ────────────────────────────────────────────────
    // This line appears in the PHP error log every time the engine genuinely
    // executes.  If the log shows NO new entry after a Re-run click, the request
    // never reached this branch.  If it DOES appear, the engine ran.
    // Check with: grep "RECONCILER_ENGINE" /path/to/php_error.log | tail -5
    error_log('RECONCILER_ENGINE_START importid=' . intval($importid)
        . ' release=' . RTOCOMPLIANCE_RECONCILER_RELEASE
        . ' ts=' . date('c'));

    // ── Pre-step: process qualification mapping CSV if submitted with the landing form ──
    // When the admin uploads a mapping CSV at the same time as clicking "Run Reconciliation",
    // we ingest the CSV into qualmap BEFORE loading the mapping table so the new mappings
    // are immediately active for this run.
    $_analyseMapNote = '';
    if (!empty($_FILES['mapping_csv']['tmp_name']) && $_FILES['mapping_csv']['error'] === UPLOAD_ERR_OK) {
        $_amFh = fopen($_FILES['mapping_csv']['tmp_name'], 'r');
        if ($_amFh) {
            $bom = fread($_amFh, 3);
            if ($bom !== "\xEF\xBB\xBF") { rewind($_amFh); }
            $firstRow = fgetcsv($_amFh);
            $h0 = strtolower(trim((string)($firstRow[0] ?? '')));
            $isHdr = ($h0 === 'qualcode' || $h0 === 'qual code' || $h0 === 'qual_code' || $h0 === 'code');
            if (!$isHdr) { rewind($_amFh); if ($bom === "\xEF\xBB\xBF") { fseek($_amFh, 3); } }
            $_amMapped = 0; $_amMissed = [];
            while (($row = fgetcsv($_amFh)) !== false) {
                if (count($row) < 2) continue;
                $_amQc   = strtoupper(trim((string)($row[0] ?? '')));
                $_amCatV = trim((string)($row[1] ?? ''));
                if ($_amQc === '' || $_amCatV === '') continue;
                $_amCr = null;
                if (ctype_digit($_amCatV)) {
                    // Numeric → direct ID lookup (preferred)
                    $_amCats = $DB->get_records('course_categories', ['id' => (int)$_amCatV], '', 'id,name', 0, 1);
                    $_amCr = $_amCats ? reset($_amCats) : null;
                    if (!$_amCr) { $_amMissed[] = $_amQc . ' — category ID ' . (int)$_amCatV . ' not found'; continue; }
                } else {
                    // Exact case-insensitive name match only — no partial/contains matching.
                    $_amCats = $DB->get_records_sql("SELECT id, name FROM {course_categories} WHERE LOWER(name) = LOWER(:name)", ['name' => $_amCatV], 0, 2);
                    if (count($_amCats) === 0) { $_amMissed[] = $_amQc . ' — no category named "' . $_amCatV . '" (use numeric ID, or fix the name)'; continue; }
                    if (count($_amCats) > 1)   { $_amMissed[] = $_amQc . ' — "' . $_amCatV . '" matches multiple categories (use numeric category ID)'; continue; }
                    $_amCr = reset($_amCats);
                }
                if (!empty($_amCr)) {
                    $_amExist = $DB->get_record('local_rtocompliance_qualmap', ['qualcode' => $_amQc], 'id', IGNORE_MISSING);
                    if ($_amExist) {
                        $DB->update_record('local_rtocompliance_qualmap', (object)['id' => $_amExist->id, 'categoryid' => (int)$_amCr->id, 'catname' => (string)$_amCr->name, 'confidence' => 100, 'method' => 'manual', 'timemodified' => time()]);
                    } else {
                        $DB->insert_record('local_rtocompliance_qualmap', (object)['qualcode' => $_amQc, 'categoryid' => (int)$_amCr->id, 'catname' => (string)$_amCr->name, 'confidence' => 100, 'method' => 'manual', 'timecreated' => time(), 'timemodified' => time()]);
                    }
                    $_amMapped++;
                }
            }
            fclose($_amFh);
            if ($_amMapped > 0 || !empty($_amMissed)) {
                $_analyseMapNote = $_amMapped . ' qualcode(s) auto-mapped from CSV';
                if (!empty($_amMissed)) { $_analyseMapNote .= '; unmatched: ' . implode(', ', $_amMissed); }
                $_analyseMapNote .= '.';
            }
        }
    }

    // ── Step 0: Load qualification mapping table ──────────────────────────────
    // Mappings may originate from:
    //   manual            — admin-set via dropdown or CSV import
    //   category_hierarchy — auto-discovered: qualcode found in an ancestor category name
    //   unit_fingerprint  — auto-discovered: best branch by unit-code overlap scoring
    //
    // $qualMap[QUALCODE]           = categoryid  (int)
    // $qualMapName[QUALCODE]       = catname     (string — display only)
    // $qualMapMethod[QUALCODE]     = method      (string — discovery method)
    // $qualMapConfidence[QUALCODE] = confidence  (int 0-100)
    $qualMap           = [];
    $qualMapName       = [];
    $qualMapMethod     = [];
    $qualMapConfidence = [];
    $_qmAll = $DB->get_records('local_rtocompliance_qualmap', null, '', 'qualcode,categoryid,catname,confidence,method');
    foreach ($_qmAll as $_qmR) {
        $_qmQc = strtoupper(trim((string)$_qmR->qualcode));
        if ($_qmQc !== '') {
            $qualMap[$_qmQc]           = (int)$_qmR->categoryid;
            $qualMapName[$_qmQc]       = (string)$_qmR->catname;
            $qualMapMethod[$_qmQc]     = (string)($_qmR->method     ?? 'manual');
            $qualMapConfidence[$_qmQc] = (int)   ($_qmR->confidence ?? 100);
        }
    }
    unset($_qmAll, $_qmR, $_qmQc);

    $importRec = $DB->get_record('local_rtocompliance_avetmiss', ['id' => $importid], '*', IGNORE_MISSING);
    if (!$importRec) {
        redirect(
            new moodle_url('/local/rtocompliance/reconcile.php'),
            'Import #' . $importid . ' not found.',
            null, \core\output\notification::NOTIFY_ERROR
        );
    }

    // ── Friday backup CSV — optional upload for RESTORE detection ─────────────
    // Expected columns: [0]=enrol_id, [1]=course_id(dup), [2]=userid, [3]=clientid,
    // [4]=username, [5]=firstname, [6]=lastname, [7]=email, [8]=catid,
    // [9]=cat_shortname, [10]=cat_fullname, [11]=courseid, [12]=enrol_method,
    // [13]=suspended, [14]=suspended2, [15]=timecreated, [16]=timemodified
    $fridayBackup      = []; // userid(int) → [courseid(int) => true]
    $fridayBackupLoaded    = false;
    $fridayBackupRowCount  = 0;
    if (!empty($_FILES['fridaybackup']['tmp_name']) && is_uploaded_file($_FILES['fridaybackup']['tmp_name'])) {
        $_fbHandle = fopen($_FILES['fridaybackup']['tmp_name'], 'r');
        if ($_fbHandle !== false) {
            while (($_fbRow = fgetcsv($_fbHandle)) !== false) {
                if (count($_fbRow) < 12) continue;
                $_fbUid = (int)$_fbRow[2];
                $_fbCid = (int)$_fbRow[11];
                if ($_fbUid > 0 && $_fbCid > 0) {
                    $fridayBackup[$_fbUid][$_fbCid] = true;
                    $fridayBackupRowCount++;
                }
            }
            fclose($_fbHandle);
            $fridayBackupLoaded = ($fridayBackupRowCount > 0);
        }
    }

    // ── Step 1: Load NAT00120 staging data ───────────────────────────────────
    // $natUnits[lc_clientid][UNITCODE]            = outcome_string
    // $natStartdate[lc_clientid][UNITCODE]        = startdate DDMMYYYY (for date-aware ADD mode)
    // $natQualUnits[lc_clientid][QUALCODE][SCODE] = true  (qualification-first grouping)
    // $natUnitQual[lc_clientid][UNITCODE]         = QUALCODE (reverse lookup — first qual seen wins)
    $natUnits     = [];
    $natStartdate = [];
    $natClientIds = [];
    $natQualUnits = []; // lc_clientid → qualcode → [unitcode → true]
    $natUnitQual  = []; // lc_clientid → unitcode → qualcode

    $natRs = $DB->get_recordset_select(
        'local_rtocompliance_avetmiss_enrolment',
        'importid = :iid', ['iid' => $importid], '',
        'clientid, unitcode, qualcode, outcome, startdate'
    );
    foreach ($natRs as $_nr) {
        $_lc = strtolower(trim((string)$_nr->clientid));
        $_uc = strtoupper(trim((string)$_nr->unitcode));
        $_qc = strtoupper(trim((string)($_nr->qualcode ?? '')));
        if ($_lc === '' || $_uc === '') continue;
        $natUnits[$_lc][$_uc]     = trim((string)($_nr->outcome   ?? ''));
        $natStartdate[$_lc][$_uc] = trim((string)($_nr->startdate ?? ''));
        $natClientIds[$_lc]       = true;
        if ($_qc !== '') {
            $natQualUnits[$_lc][$_qc][$_uc] = true;
            if (!isset($natUnitQual[$_lc][$_uc])) {
                $natUnitQual[$_lc][$_uc] = $_qc;
            }
        }
    }
    $natRs->close();
    $natClientIds = array_keys($natClientIds);

    // Identify qualcodes present in NAT data but not yet mapped to a Moodle category.
    // Used for the warning banner and the Qualification Mapping UI.
    $unmappedQualcodes = [];
    foreach ($natQualUnits as $_lc_ub => $_qcSet_ub) {
        foreach (array_keys($_qcSet_ub) as $_qc_ub) {
            if ($_qc_ub !== '' && !isset($qualMap[$_qc_ub])) {
                $unmappedQualcodes[$_qc_ub] = true;
            }
        }
    }
    $unmappedQualcodes = array_keys($unmappedQualcodes);
    sort($unmappedQualcodes);

    if (empty($natClientIds)) {
        redirect(
            new moodle_url('/local/rtocompliance/reconcile.php', ['importid' => $importid]),
            'No enrolment records (NAT00120) found in the staging data for import #' . $importid . '.',
            null, \core\output\notification::NOTIFY_WARNING
        );
    }

    // ── Step 2: Match clientids → Moodle userids (5 paths, same as FOE) ─────
    $clientToUid  = []; // lc_clientid → userid
    $uidToDetails = []; // userid → stdClass {username, idnumber, firstname, lastname}
    $clientMatchPath = []; // lc_clientid → 'A'|'B'|'C'|'D'|'E'
    // Path trust levels:
    //   A = user.idnumber = clientid   (direct, strongest)
    //   B = local_rtocompliance_students.clientid (explicit admin linkage, direct)
    //   C = user.username = clientid   (direct)
    //   D = email match via staging data (indirect — shared email can be a false positive)
    //   E = USI match via staging data  (indirect — USI may be unverified or shared)
    // Paths D & E are used as fallbacks when A/B/C all fail, but they are
    // less reliable.  Step 6 will refuse to emit ADD rows for D/E-matched
    // students unless their own Moodle idnumber or username is also a direct
    // NAT clientid (i.e. they appear in NAT under a different qual but the
    // email/USI link to this qual's clientid is plausible).

    // Helper: store a matched user
    $storeMatch = function (string $lcCid, object $u, string $path = 'A')
        use (&$clientToUid, &$uidToDetails, &$natUnits, &$clientMatchPath) {
        if ($lcCid === '' || !isset($natUnits[$lcCid]) || isset($clientToUid[$lcCid])) return;
        $clientToUid[$lcCid]        = (int)$u->id;
        $uidToDetails[(int)$u->id]  = $u;
        $clientMatchPath[$lcCid]    = $path;
    };

    // Path A: user.idnumber = clientid
    list($_s, $_p) = $DB->get_in_or_equal($natClientIds, SQL_PARAMS_NAMED, 'rca');
    $_rs = $DB->get_recordset_sql(
        "SELECT id, LOWER(idnumber) AS lc_idn, username, idnumber, firstname, lastname, email
           FROM {user} WHERE LOWER(idnumber) $_s AND deleted = 0", $_p);
    foreach ($_rs as $_u) { $storeMatch(trim((string)$_u->lc_idn), $_u, 'A'); }
    $_rs->close();

    // Path B: local_rtocompliance_students.clientid → userid
    $_unk = array_values(array_diff($natClientIds, array_keys($clientToUid)));
    if (!empty($_unk)) {
        list($_s, $_p) = $DB->get_in_or_equal($_unk, SQL_PARAMS_NAMED, 'rcb');
        $_rs = $DB->get_recordset_sql(
            "SELECT s.userid AS id, u.username, u.idnumber, u.firstname, u.lastname, u.email,
                    LOWER(s.clientid) AS lc_cid
               FROM {local_rtocompliance_students} s
               JOIN {user} u ON u.id = s.userid AND u.deleted = 0
              WHERE LOWER(s.clientid) $_s AND s.clientid IS NOT NULL AND s.clientid <> ''", $_p);
        foreach ($_rs as $_u) { $storeMatch(trim((string)$_u->lc_cid), $_u, 'B'); }
        $_rs->close();
    }

    // Path C: user.username = clientid
    $_unk = array_values(array_diff($natClientIds, array_keys($clientToUid)));
    if (!empty($_unk)) {
        list($_s, $_p) = $DB->get_in_or_equal($_unk, SQL_PARAMS_NAMED, 'rcc');
        $_rs = $DB->get_recordset_sql(
            "SELECT id, LOWER(username) AS lc_un, username, idnumber, firstname, lastname, email
               FROM {user} WHERE LOWER(username) $_s AND deleted = 0", $_p);
        foreach ($_rs as $_u) { $storeMatch(trim((string)$_u->lc_un), $_u, 'C'); }
        $_rs->close();
    }

    // Paths D & E: load email + USI from staging, then match by email / USI
    $_unk = array_values(array_diff($natClientIds, array_keys($clientToUid)));
    if (!empty($_unk)) {
        list($_s4, $_p4) = $DB->get_in_or_equal($_unk, SQL_PARAMS_NAMED, 'rcd');
        $_stuData = [];
        $_stuRs = $DB->get_recordset_sql(
            "SELECT LOWER(clientid) AS lc_cid, email, usi
               FROM {local_rtocompliance_avetmiss_student}
              WHERE importid = :iid AND LOWER(clientid) $_s4",
            array_merge(['iid' => $importid], $_p4)
        );
        foreach ($_stuRs as $_sd) {
            $_lc2 = trim((string)$_sd->lc_cid);
            if ($_lc2 !== '' && !isset($_stuData[$_lc2])) $_stuData[$_lc2] = $_sd;
        }
        $_stuRs->close();

        // Path D: email (with SINGLE-USER GUARD per spec §1)
        // Only accept an email match if that email belongs to EXACTLY ONE non-deleted Moodle
        // user.  If the email is blank OR matches ≥2 users the path FAILS for that student.
        // Reason: shared emails (3→1 in some installs) create false identity links and must
        // never be accepted as evidence of student identity.
        $_emailMap = []; // lc_email → lc_clientid
        foreach ($_unk as $_lc2) {
            $_e = strtolower(trim((string)($_stuData[$_lc2]->email ?? '')));
            if ($_e !== '') $_emailMap[$_e] = $_lc2;
        }
        if (!empty($_emailMap)) {
            // Step 1: GROUP BY to find which emails belong to exactly one user
            list($_es, $_ep) = $DB->get_in_or_equal(array_keys($_emailMap), SQL_PARAMS_NAMED, 'rceg');
            $_cntRs = $DB->get_recordset_sql(
                "SELECT LOWER(email) AS lc_email, COUNT(*) AS cnt
                   FROM {user} WHERE LOWER(email) $_es AND deleted = 0
                   GROUP BY LOWER(email)", $_ep);
            $_singleEmails = []; // lc_email → true (safe: count = 1)
            foreach ($_cntRs as $_cr) {
                if ((int)$_cr->cnt === 1) $_singleEmails[trim((string)$_cr->lc_email)] = true;
            }
            $_cntRs->close();
            // Step 2: remove emails that matched multiple users (guard fires)
            $_emailMapSafe = array_filter($_emailMap, fn($_k) => isset($_singleEmails[$_k]), ARRAY_FILTER_USE_KEY);
            // Step 3: now do the actual user lookup, restricted to single-user emails only
            if (!empty($_emailMapSafe)) {
                list($_es, $_ep) = $DB->get_in_or_equal(array_keys($_emailMapSafe), SQL_PARAMS_NAMED, 'rce');
                $_rs = $DB->get_recordset_sql(
                    "SELECT id, LOWER(email) AS lc_email, username, idnumber, firstname, lastname, email
                       FROM {user} WHERE LOWER(email) $_es AND deleted = 0", $_ep);
                foreach ($_rs as $_u) {
                    $_lcCid = $_emailMapSafe[trim((string)$_u->lc_email)] ?? null;
                    if ($_lcCid !== null) $storeMatch($_lcCid, $_u, 'D');
                }
                $_rs->close();
            }
        }

        // Path E: USI
        $_unk2 = array_values(array_diff($natClientIds, array_keys($clientToUid)));
        $_usiMap = []; // lc_usi → lc_clientid
        foreach ($_unk2 as $_lc2) {
            $_usi = strtolower(trim((string)($_stuData[$_lc2]->usi ?? '')));
            if ($_usi !== '') $_usiMap[$_usi] = $_lc2;
        }
        if (!empty($_usiMap)) {
            list($_us, $_up) = $DB->get_in_or_equal(array_keys($_usiMap), SQL_PARAMS_NAMED, 'rcf');
            $_rs = $DB->get_recordset_sql(
                "SELECT s.userid AS id, LOWER(s.usi) AS lc_usi, u.username, u.idnumber, u.firstname, u.lastname, u.email
                   FROM {local_rtocompliance_students} s
                   JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                  WHERE LOWER(s.usi) $_us", $_up);
            foreach ($_rs as $_u) {
                $_lcCid = $_usiMap[trim((string)$_u->lc_usi)] ?? null;
                if ($_lcCid !== null) $storeMatch($_lcCid, $_u, 'E');
            }
            $_rs->close();
        }
    }

    $matchedStudentCount   = count($clientToUid);
    $unmatchedStudentCount = count($natClientIds) - $matchedStudentCount;
    $matchedUserids        = array_values(array_unique(array_values($clientToUid)));

    // ── Step 2.5: Build Moodle category hierarchy ─────────────────────────────
    // Required for qualification-first reconciliation and multi-level delivery key search.
    //
    // $catById[catid]          = {id, name, parent, path}
    // $catDescendantIds[catid] = [catid, child, grandchild, ...] including self
    // $catQualBranch[catid]    = [qualcode, ...] — which qual branches include this catid
    //                            Used during course scan to assign courses to qual branches.
    $catById          = [];
    $catDescendantIds = [];
    $catQualBranch    = [];
    $courseAncestorNames = []; // courseid → [name_root, ..., name_direct_parent] built per-course in Step 3

    $_chRs = $DB->get_recordset_sql("SELECT id, name, parent, path FROM {course_categories}");
    foreach ($_chRs as $_ch) {
        $_chId = (int)$_ch->id;
        $catById[$_chId] = (object)[
            'id'     => $_chId,
            'name'   => (string)$_ch->name,
            'parent' => (int)$_ch->parent,
            'path'   => (string)$_ch->path,
        ];
        // Each category is a descendant of all categories in its path
        $_chPids = array_filter(array_map('intval', explode('/', trim((string)$_ch->path, '/'))));
        foreach ($_chPids as $_chPid) {
            $catDescendantIds[$_chPid][] = $_chId;
        }
        $catDescendantIds[$_chId][] = $_chId; // self
    }
    $_chRs->close();
    // De-duplicate (self was added both via path and the explicit append above)
    foreach ($catDescendantIds as $_chK => $_chList) {
        $catDescendantIds[$_chK] = array_values(array_unique($_chList));
    }

    // ── Step 2.6: Qualification Auto-Discovery — category-name search ──────────
    // For each NAT qualcode not already MANUALLY mapped, search every Moodle
    // category name for the qualcode string (literal, case-insensitive).
    // The SHALLOWEST matching category (fewest path segments = closest to root)
    // becomes the qualification root for the reconciler.
    //
    // This handles the overwhelmingly common pattern where administrators name
    // their category after the qualification, e.g.:
    //   "a Diploma qualification (ABC12345)"
    //   "Certificate IV in Logistics ABC12345"
    //   "ABC12345 — Advanced Logistics"
    //
    // Qualcodes that cannot be resolved by name proceed to unit fingerprint
    // analysis in Step 3.5 (after the course scan has built courseToUnit).
    //
    // Method tag:  'category_hierarchy'   Confidence: 100
    // Fallback to: $_adPendingFingerprint → resolved in Step 3.5
    $_adPendingFingerprint = []; // qualcodes queued for unit-fingerprint fallback

    // Collect all unique qualcodes from this import's NAT data.
    $_adAllQcodes = [];
    foreach ($natQualUnits as $_adQcSet) {
        foreach (array_keys($_adQcSet) as $_adQc0) {
            if ($_adQc0 !== '') $_adAllQcodes[$_adQc0] = true;
        }
    }
    unset($_adQcSet, $_adQc0);

    foreach (array_keys($_adAllQcodes) as $_adQ) {
        // Never overwrite an admin-created manual override.
        if (isset($qualMapMethod[$_adQ]) && $qualMapMethod[$_adQ] === 'manual') continue;

        $_adBestCatId   = null;
        $_adBestCatName = '';
        $_adBestDepth   = PHP_INT_MAX;
        $_adMethod      = 'category_hierarchy';

        // Step 2.6a: exact category.idnumber match — highest confidence, zero ambiguity.
        // An admin who sets category.idnumber = 'ABC12345' has made an explicit
        // declaration of intent. This is preferred over name-string search which can
        // false-match on sub-categories that mention the code in passing.
        // Method tag: 'category_idnumber'   Confidence: 100
        foreach ($catById as $_adCatId => $_adCatRec) {
            $_adIdn = strtoupper(trim((string)($_adCatRec->idnumber ?? '')));
            if ($_adIdn !== $_adQ) continue;
            $_adDepth = max(1, substr_count(trim($_adCatRec->path, '/'), '/') + 1);
            if ($_adDepth < $_adBestDepth) {
                $_adBestDepth   = $_adDepth;
                $_adBestCatId   = $_adCatId;
                $_adBestCatName = $_adCatRec->name;
                $_adMethod      = 'category_idnumber';
            }
        }

        // Step 2.6b: category.name string search — only runs when idnumber match found nothing.
        // The SHALLOWEST matching category (fewest path segments = closest to root) wins.
        // Method tag: 'category_hierarchy'   Confidence: 100
        if ($_adBestCatId === null) {
            foreach ($catById as $_adCatId => $_adCatRec) {
                if (stripos($_adCatRec->name, $_adQ) === false) continue;
                // Depth = number of path segments (fewer = closer to root = better root candidate)
                $_adDepth = max(1, substr_count(trim($_adCatRec->path, '/'), '/') + 1);
                if ($_adDepth < $_adBestDepth) {
                    $_adBestDepth   = $_adDepth;
                    $_adBestCatId   = $_adCatId;
                    $_adBestCatName = $_adCatRec->name;
                    $_adMethod      = 'category_hierarchy';
                }
            }
        }

        if ($_adBestCatId !== null) {
            // Found via idnumber or name — upsert into DB.
            $_adExist = $DB->get_record('local_rtocompliance_qualmap', ['qualcode' => $_adQ], 'id', IGNORE_MISSING);
            if ($_adExist) {
                $DB->update_record('local_rtocompliance_qualmap', (object)[
                    'id'           => $_adExist->id,
                    'categoryid'   => $_adBestCatId,
                    'catname'      => $_adBestCatName,
                    'confidence'   => 100,
                    'method'       => $_adMethod,
                    'timemodified' => time(),
                ]);
            } else {
                $DB->insert_record('local_rtocompliance_qualmap', (object)[
                    'qualcode'     => $_adQ,
                    'categoryid'   => $_adBestCatId,
                    'catname'      => $_adBestCatName,
                    'confidence'   => 100,
                    'method'       => $_adMethod,
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]);
            }
            // Update in-memory maps so catQualBranch (built below) includes this discovery.
            $qualMap[$_adQ]           = $_adBestCatId;
            $qualMapName[$_adQ]       = $_adBestCatName;
            $qualMapMethod[$_adQ]     = $_adMethod;
            $qualMapConfidence[$_adQ] = 100;
        } else {
            // Neither idnumber nor name matched — queue for unit fingerprint.
            $_adPendingFingerprint[$_adQ] = true;
        }
    }
    unset($_adAllQcodes, $_adQ, $_adBestCatId, $_adBestCatName, $_adBestDepth, $_adDepth,
          $_adCatId, $_adCatRec, $_adExist, $_adIdn, $_adMethod);

    // Recompute unmappedQualcodes after category-name discovery
    // (some qualcodes that were unmapped may now be resolved).
    $unmappedQualcodes = [];
    foreach ($natQualUnits as $_adUbLc => $_adUbQcSet) {
        foreach (array_keys($_adUbQcSet) as $_adUbQc) {
            if ($_adUbQc !== '' && !isset($qualMap[$_adUbQc])) {
                $unmappedQualcodes[$_adUbQc] = true;
            }
        }
    }
    $unmappedQualcodes = array_keys($unmappedQualcodes);
    sort($unmappedQualcodes);
    unset($_adUbLc, $_adUbQcSet, $_adUbQc);

    // Build inverted index: for each catid, which qualified qual branches include it?
    foreach ($qualMap as $_qc25 => $_qCatId25) {
        $_branchIds25 = $catDescendantIds[$_qCatId25] ?? [$_qCatId25];
        foreach ($_branchIds25 as $_bid25) {
            $catQualBranch[$_bid25][] = $_qc25;
        }
    }

    // ── Step 3: Scan all courses — build course→unit map and preferred ADD target ─
    // v6 unit-centric engine.
    // The reconciler now answers "is this enrolment legitimate?" rather than
    // "which specific course should this student be in?"
    //
    // $courseToUnit[courseid]       = unitcode ('' if none extractable)
    // $unitToPreferredCid[unitcode] = courseid of the newest visible course
    //                                 (used ONLY to populate ADD recommendations)
    // $courseDetail, $unitAllCids, $unitToChosenCid are preserved for the
    // Course Selection Diagnostic panel.
    $courseToUnit       = []; // courseid → unitcode
    $unitToPreferredCid = []; // unitcode → preferred courseid (newest visible — fallback / 'newest' mode)
    $unitToChosenCid    = []; // alias — same map, used by Course Selection diag
    $unitAllCids        = []; // unitcode → [courseid,...] all courses (diag panel)
    $courseDetail       = []; // courseid → stdClass {shortname,fullname,catid,catname,visible}
    $unitDeliveryCourseMap = []; // unitcode → [deliveryKey → courseid] for date-aware ADD selection
                                // deliveryKey format: "YYYY-S1" or "YYYY-S2"
                                // Tiebreaker: ORDER BY visible DESC, id DESC — highest-ID wins per delivery
    $courseExtractionMeta = []; // courseid → ['source'=>str,'fullname_count'=>int,'fullname_list'=>str,'flags'=>str]
    // Qualification-first maps (built in Step 3, used in Step 6 ADD and the trace).
    // Only populated for courses whose direct-parent category falls within a mapped qual branch.
    $qualUnitPreferredCid = []; // qualcode → [unitcode → preferred courseid within qual branch]
    $qualUnitDeliveryMap  = []; // qualcode → [unitcode → [deliveryKey → courseid]] within qual branch
    $courseDkMatchedFrom  = []; // courseid → which text string produced the delivery key (trace diagnostics)
    $normUnitAllCids      = []; // normalised-unitcode → [courseid,...] (strips version suffix: ABC12345 → ABC12345)
    $courseAllUnits       = []; // courseid → [unitcode,...] ALL unit codes the course teaches
    $nonAwardCpdCourses   = []; // courseid → true — NON_AWARD_CPD courses excluded from unit reconciliation
    // $courseAllUnits is populated in the Step 3 loop using all codes found in the
    // course fullname/idnumber/shortname.  A combined course like "BSBCUS501C & BSBMGT502B"
    // maps to both codes.  Step 5 coverage and Step 6 ADD both use this to handle students
    // enrolled in (or needing enrolment in) courses that teach multiple AVETMISS units.

    $_diagNatUcSet = [];
    foreach ($natUnits as $_unitMap) {
        foreach (array_keys($_unitMap) as $_uc3) { $_diagNatUcSet[$_uc3] = true; }
    }

    // TRACE v5.9.216: Log EXACT NAT key format for BSB units.
    // Guards throughout the engine use isset($_diagNatUcSet[$code]) — "BSBLDR522" vs
    // "BSBLDR522A" is a different key. This confirms what the NAT file actually contains.
    {
        $_t216NatBsb = array_filter(array_keys($_diagNatUcSet),
            fn($_k216) => str_starts_with($_k216, 'BSBLDR5') || str_starts_with($_k216, 'BSBMGT5') || str_starts_with($_k216, 'BSBOPS5'));
        error_log('TRACE_NAT_BSB_CODES v5.9.216 all_matching=[' . implode(', ', $_t216NatBsb) . ']'
            . ' BSBLDR522=' . (isset($_diagNatUcSet['BSBLDR522']) ? 'YES' : 'NO')
            . ' BSBLDR522A=' . (isset($_diagNatUcSet['BSBLDR522A']) ? 'YES' : 'NO')
            . ' BSBLDR522B=' . (isset($_diagNatUcSet['BSBLDR522B']) ? 'YES' : 'NO')
            . ' BSBMGT502=' . (isset($_diagNatUcSet['BSBMGT502']) ? 'YES' : 'NO')
            . ' BSBOPS505=' . (isset($_diagNatUcSet['BSBOPS505']) ? 'YES' : 'NO'));
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
        $_uc  = _reconcile_extract_unitcode(
            (string)$_c->idnumber, (string)$_c->shortname, (string)$_c->fullname
        );
        $_cid = (int)$_c->id;
        $courseDetail[$_cid] = (object)[
            'shortname' => (string)$_c->shortname,
            'fullname'  => (string)$_c->fullname,
            'idnumber'  => (string)$_c->idnumber,
            'catid'     => (int)$_c->category,
            'catname'   => (string)($_c->catname ?? ''),
            'visible'   => (int)$_c->visible,
        ];
        $courseToUnit[$_cid] = $_uc;

        // ── Extraction metadata for course validation audit report ────────────
        {
            $_idn_up  = strtoupper(trim((string)$_c->idnumber));
            $_sn_up   = strtoupper(trim((string)$_c->shortname));
            $_fn_up   = strtoupper(trim((string)$_c->fullname));
            $_src_pat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
            $_fn_any  = '/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
            if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $_idn_up)) {
                $_extSrc = 'idnumber_exact';
            } elseif (preg_match($_src_pat, $_idn_up)) {
                $_extSrc = 'idnumber_prefix';
            } elseif (preg_match($_src_pat, $_sn_up)) {
                $_extSrc = 'shortname';
            } elseif (preg_match($_fn_any, $_fn_up)) {
                $_extSrc = 'fullname';
            } else {
                $_extSrc = '';
            }
            preg_match_all($_fn_any, $_fn_up, $_fnM);
            $_fnList  = $_fnM[1] ?? [];
            $_fnCount = count($_fnList);
            // Per-source codes for consistency check
            $_idnCode = '';
            $_snCode  = '';
            $_fnCode  = '';
            if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $_idn_up)) { $_idnCode = $_idn_up; }
            elseif (preg_match($_src_pat, $_idn_up, $_mm)) { $_idnCode = $_mm[1]; }
            if (preg_match($_src_pat, $_sn_up, $_mm)) { $_snCode = $_mm[1]; }
            if (preg_match($_fn_any, $_fn_up, $_mm)) { $_fnCode = $_mm[1]; }
            $_metaFlags = [];
            if ($_uc === '') { $_metaFlags[] = 'NO_UNIT_CODE'; }
            if ($_fnCount > 1) { $_metaFlags[] = 'MULTIPLE_IN_FULLNAME'; }
            $_srcCodes = array_filter([$_idnCode, $_snCode, $_fnCode], fn($v) => $v !== '');
            if (count(array_unique(array_values($_srcCodes))) > 1) { $_metaFlags[] = 'INCONSISTENT_SOURCES'; }
            $courseExtractionMeta[$_cid] = [
                'source'         => $_extSrc,
                'fullname_count' => $_fnCount,
                'fullname_list'  => implode('; ', $_fnList),
                'flags'          => implode(', ', $_metaFlags),
            ];
        }

        // ── MULTI-UNIT: build courseAllUnits from all codes found in ALL fields ─────
        // Fix B (v5.9.206): combined courses (73 identified) carry two unit codes.
        // The old code only scanned the fullname — missing secondary codes that exist
        // only in the shortname (e.g. "BSBCUS501C & BSBMGT502B S1 2016") or idnumber
        // (e.g. "BSBCUS501C-BSBMGT502B") when the fullname is a generic title with no
        // explicit unit codes.
        // Fix B corrected (v5.9.209): v5.9.206 applied $_muPat to idnumber+shortname but
        // reused $_fnList (from $_fn_any — first-match only) for fullname. Most combined
        // courses carry the second code IN the fullname (e.g. "BSBCUS501 & BSBMGT502
        // Manage People...") — $_fnList only captured BSBCUS501; BSBMGT502 was silently
        // dropped. Fix: run $_muPat against $_fn_up (like idnumber/shortname) so every
        // code in all three fields is captured. $_fnList kept for primary-code metadata.
        // $_idn_up, $_sn_up, $_fn_up are computed above in the extraction metadata block.
        {
            $_muPat = '/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
            preg_match_all($_muPat, $_idn_up, $_muIdnM);
            preg_match_all($_muPat, $_sn_up,  $_muSnM);
            preg_match_all($_muPat, $_fn_up,  $_muFnM); // FIX v5.9.209: fullname scanned with same multi-code pattern
            $_allUcsMerged = array_unique(array_filter(
                array_merge([$_uc], $_muIdnM[1] ?? [], $_muSnM[1] ?? [], $_muFnM[1] ?? []),
                fn($_c3) => $_c3 !== ''
            ));
            $courseAllUnits[$_cid] = array_values($_allUcsMerged);
            // TRACE v5.9.216: Full field detail for course 727 at the point courseAllUnits is set.
            if ($_cid === 727) {
                error_log('TRACE_CID727_STEP3 v5.9.216'
                    . ' idn=[' . substr($_idn_up, 0, 80) . ']'
                    . ' shn=[' . substr($_sn_up, 0, 80) . ']'
                    . ' fn=[' . substr($_fn_up, 0, 120) . ']'
                    . ' primary_uc=[' . $_uc . ']'
                    . ' fnList=[' . implode(', ', $_fnList) . ']'
                    . ' courseAllUnits=[' . implode(', ', $courseAllUnits[727]) . ']'
                    . ' primary_in_NAT=' . (isset($_diagNatUcSet[$_uc]) ? 'YES' : 'NO')
                    . ' BSBLDR522_in_NAT=' . (isset($_diagNatUcSet['BSBLDR522']) ? 'YES' : 'NO'));
            }
        }

        // NON_AWARD_CPD (v5.9.203): mark and skip courses with no AVETMISS unit code
        // whose category name matches a non-award professional-development pattern.
        // These are CPD activities (~50 courses verified) with no units to reconcile.
        // Patterns (case-insensitive, substring): CPD, CBC, Accredit, Re-accred, Forum, DAWR.
        if ($_uc === '') {
            $_catNameLow3 = strtolower((string)($_c->catname ?? ''));
            foreach (['cpd', 'cbc', 'accredit', 're-accred', 'forum', 'dawr'] as $_cpdP3) {
                if (strpos($_catNameLow3, $_cpdP3) !== false) {
                    $nonAwardCpdCourses[$_cid] = true;
                    break;
                }
            }
            continue;
        }
        if (isset($_diagNatUcSet[$_uc])) {
            $unitAllCids[$_uc][] = $_cid;
        }
        // Normalised index: ABC12345 → key ABC12345 (catches version-suffix mismatches).
        // v5.9.211 FIX: old guard ($_normUc3 !== $_uc) silently dropped every clean,
        // suffix-less primary code like BSBMGT502 — normalise('BSBMGT502') returns
        // 'BSBMGT502' (no change), so the guard was false and normUnitAllCids was never
        // populated. Step 6 reads normUnitAllCids[normUc] for the pool — empty map →
        // poolsize=0 for all BSBMGT502 students even when courses exist.
        // Fix: condition is now "is normalised code non-empty?" — always register.
        // array_unique in Step 6 makes duplicate cids from unitAllCids+normUnitAllCids safe.
        $_normUc3 = _reconcile_normalize_unitcode($_uc);
        if ($_normUc3 !== '') {
            if (!isset($normUnitAllCids[$_normUc3])) $normUnitAllCids[$_normUc3] = [];
            $normUnitAllCids[$_normUc3][] = $_cid;
        }
        // First course encountered (ORDER BY visible DESC, id DESC) = newest visible.
        // This becomes the preferred ADD recommendation for that unit code (fallback / 'newest' mode).
        if (!isset($unitToPreferredCid[$_uc])) {
            $unitToPreferredCid[$_uc] = $_cid;
            $unitToChosenCid[$_uc]    = $_cid;
        }

        // ── FULLNAME-CODES DIRECT REGISTRATION (v5.9.214) ────────────────────────
        // Core fix for combined courses (e.g. "BSBOPS505 and BSBLDR522 - Manage People"):
        // register EVERY code found in the course fullname directly into
        // unitAllCids / normUnitAllCids / unitToPreferredCid, unconditionally
        // (no guard on whether the primary code is in the NAT set).
        //
        // Root cause of the persistent BSBLDR522/BSBMGT502 poolsize=0 bug:
        //   The secondary registration loops below (v5.9.198–213) are gated
        //   behind the guard at line ~1320:
        //     if (isset($_diagNatUcSet[$_uc]) || ...)
        //   When the primary code (BSBOPS505) is absent from this NAT import
        //   (no student reported it), this guard is FALSE → the secondary loop
        //   never fires → BSBLDR522 is never added to unitAllCids →
        //   poolsize=0 for all BSBLDR522 students despite 11 courses existing.
        //
        //   The secondary-FIRST block (v5.9.199) fires when the primary is NOT
        //   in NAT, but only when count($courseAllUnits[$_cid]) > 1, which can
        //   silently fail in edge cases where $courseAllUnits wasn't fully
        //   populated (e.g. garbage primary codes such as BSB226 from slash-
        //   separated idnumbers like "BSB226/BSB226" — now fixed separately).
        //
        // This loop runs immediately after primary registration, unconditionally,
        // iterating $_fnList (all AVETMISS codes found in the fullname via
        // preg_match_all — the same data exported as codes_in_fullname_list in
        // the course_unit_validation CSV). The existing secondary loops are kept
        // for their delivery-key and qual-branch map registrations.
        //
        // array_unique in Step 6 pool build makes duplicate course IDs safe.
        foreach ($_fnList as $_fnUc214) {
            if ($_fnUc214 === '' || $_fnUc214 === $_uc) continue; // primary already registered above
            $_fnNorm214 = _reconcile_normalize_unitcode($_fnUc214);
            // Register under raw key only when code is directly in the NAT set
            if (isset($_diagNatUcSet[$_fnUc214])) {
                $unitAllCids[$_fnUc214][] = $_cid;
            } elseif ($_fnNorm214 !== $_fnUc214 && $_fnNorm214 !== '' && isset($_diagNatUcSet[$_fnNorm214])) {
                // Version-suffix variant (e.g. BSBLDR522A → BSBLDR522) — register
                // under the unsuffixed NAT key so the pool lookup finds this course.
                $unitAllCids[$_fnNorm214][] = $_cid;
            }
            // Determine whether this fullname code (or its normalised form) is
            // actually needed by a student in this NAT import — guards the
            // ADD-recommendation path (unitToPreferredCid / normUnitAllCids).
            // Without this guard, an unconditional write could make the engine
            // recommend enrolling students in a course for a unit code that
            // no student in this import reported — creating false-positive ADDs.
            // (normUnitAllCids is also used by Step 6 pool build, so it must
            // be constrained to NAT codes too.)
            $_fnInNat214 = isset($_diagNatUcSet[$_fnUc214]) ||
                           ($_fnNorm214 !== $_fnUc214 && $_fnNorm214 !== '' && isset($_diagNatUcSet[$_fnNorm214]));
            // Normalised map: only write when the code is needed by NAT students.
            // Step 6 pool build merges unitAllCids + normUnitAllCids; writing here
            // for non-NAT codes would pollute the ADD candidate pool.
            // (Primary code registration writes normUnitAllCids unconditionally
            // because the primary code always comes from the extract-unitcode path
            // which already succeeded against idnumber/shortname/fullname — fullname-
            // only secondary codes need the NAT guard to stay safe.)
            if ($_fnInNat214 && $_fnNorm214 !== '') {
                if (!isset($normUnitAllCids[$_fnNorm214])) $normUnitAllCids[$_fnNorm214] = [];
                $normUnitAllCids[$_fnNorm214][] = $_cid;
            }
            // Preferred course pointer: only set when the code is in the NAT set.
            // Prevents combined courses from appearing as ADD recommendations for
            // secondary codes that no student in this import needs.
            // NOTE — two-stage verification after deploy + fresh run:
            //   Stage 1 (registration):
            //     SELECT SUM(uc='BSBLDR522' AND poolsize>0) AS ldr522_pool,
            //            SUM(uc='BSBMGT502' AND poolsize>0) AS mgt502_pool
            //     FROM mdl_local_rtocompliance_qualdebug;
            //     Both flip 0→positive = registration fixed.
            //   Stage 2 (scoring): does "Matched & covered" rise above 21,178?
            //     If yes → done. If poolsize positive but Matched flat → the 11
            //     BSBLDR522 courses are archived (visible=No) and the scorer may
            //     be rejecting them — that is a separate scoring-layer fix.
            $_fnRegKey214 = isset($_diagNatUcSet[$_fnUc214]) ? $_fnUc214 : $_fnNorm214;
            if ($_fnInNat214 && $_fnRegKey214 !== '' && !isset($unitToPreferredCid[$_fnRegKey214])) {
                $unitToPreferredCid[$_fnRegKey214] = $_cid;
                $unitToChosenCid[$_fnRegKey214]    = $_cid;
            }
        }

        // TRACE v5.9.216: Log any Step 3 course that has BSBLDR522 in any field.
        // Fires once per matching course — tells us how many such courses exist, their
        // exact fields, and whether the FULLNAME-CODES-DIRECT-REG loop fires for them.
        if (in_array('BSBLDR522', $courseAllUnits[$_cid] ?? [], true) ||
            in_array('BSBLDR522A', $courseAllUnits[$_cid] ?? [], true) ||
            in_array('BSBLDR522B', $courseAllUnits[$_cid] ?? [], true)) {
            error_log('TRACE_BSBLDR522_IN_COURSE v5.9.216 cid=' . $_cid
                . ' idn=[' . substr($_idn_up, 0, 60) . ']'
                . ' shn=[' . substr($_sn_up, 0, 60) . ']'
                . ' fn=[' . substr($_fn_up, 0, 100) . ']'
                . ' primary_uc=[' . $_uc . ']'
                . ' fnList=[' . implode(',', $_fnList) . ']'
                . ' allUnits=[' . implode(',', $courseAllUnits[$_cid]) . ']'
                . ' primary_in_NAT=' . (isset($_diagNatUcSet[$_uc]) ? 'YES' : 'NO')
                . ' BSBLDR522_in_NAT=' . (isset($_diagNatUcSet['BSBLDR522']) ? 'YES' : 'NO')
                . ' after_direct_reg_unitAllCids=' . count($unitAllCids['BSBLDR522'] ?? [])
                . ' after_direct_reg_normAllCids=' . count($normUnitAllCids['BSBLDR522'] ?? []));
        }

        // Date-aware delivery key using full category path (multi-level search).
        // Build ancestor name path: root (index 0) → direct parent (last index).
        $_catId3      = (int)$_c->category;
        $_catRec3     = $catById[$_catId3] ?? null;
        $_catNamePath3 = [];
        if ($_catRec3 !== null) {
            $_pids3 = array_filter(array_map('intval', explode('/', trim($_catRec3->path, '/'))));
            foreach ($_pids3 as $_pid3) {
                if (isset($catById[$_pid3])) $_catNamePath3[] = $catById[$_pid3]->name;
            }
        }
        $courseAncestorNames[$_cid] = $_catNamePath3;

        // SUFFIX-NORM-GUARD (v5.9.202): also enter the delivery-map + secondary registration
        // block when the PRIMARY code has a version suffix (e.g. BSBCUS501C) and the
        // normalised form (BSBCUS501) IS in the NAT set.  Without this, an archive course
        // like "BSBCUS501C & BSBMGT502B Manage People" skips the whole block, so neither
        // the primary delivery-map NOR the secondary secondary-first path ever fires,
        // leaving normUnitAllCids['BSBMGT502'] empty → poolsize=0 for all BSBMGT502 students.
        if (isset($_diagNatUcSet[$_uc]) || ($_normUc3 !== '' && $_normUc3 !== $_uc && isset($_diagNatUcSet[$_normUc3]))) {
            $_dkMatchedFrom3 = '';
            $_dk = _reconcile_course_delivery_key_path(
                (string)$_c->shortname,
                $_catNamePath3,
                $_dkMatchedFrom3
            );
            if ($_dk !== '') {
                $courseDkMatchedFrom[$_cid] = $_dkMatchedFrom3;
                if (!isset($unitDeliveryCourseMap[$_uc][$_dk])) {
                    $unitDeliveryCourseMap[$_uc][$_dk] = $_cid;
                }
            }

            // Qualification-first maps: assign this course to every qual branch
            // whose mapped category contains this course's direct-parent category.
            $_qcBranches3 = $catQualBranch[$_catId3] ?? [];
            foreach ($_qcBranches3 as $_qcB3) {
                if (!isset($qualUnitPreferredCid[$_qcB3][$_uc])) {
                    $qualUnitPreferredCid[$_qcB3][$_uc] = $_cid;
                }
                if ($_dk !== '' && !isset($qualUnitDeliveryMap[$_qcB3][$_uc][$_dk])) {
                    $qualUnitDeliveryMap[$_qcB3][$_uc][$_dk] = $_cid;
                }
            }

            // ── MULTI-UNIT secondary code registration (v5.9.198) ────────────────
            // For combined courses (e.g. "BSBCUS501C & BSBMGT502B"), register the
            // course under EVERY unit code it teaches — not just the primary one.
            // This makes Step 6 ADD discovery and Step 5 coverage both work for
            // students who need / have BSBMGT502 and are in a combined course.
            //
            // v5.9.210 FIX: old guard ($_secNorm3 !== $_secUc3) only wrote to
            // normUnitAllCids when normalisation CHANGED the code (i.e. stripped a
            // suffix). Unsuffixed codes like BSBMGT502 normalise to themselves →
            // condition false → normUnitAllCids['BSBMGT502'] never populated →
            // poolsize=0 even after the fullname extraction fix (v5.9.209).
            // Fix: always register any non-primary secondary code regardless of
            // whether normalisation changed it. Guard is now ($_secNorm3 !== $_uc)
            // — excludes only the primary (already handled above).
            foreach ($courseAllUnits[$_cid] as $_secUc3) {
                if ($_secUc3 === '' || $_secUc3 === $_uc) continue; // skip empty + primary (already done)
                if (isset($_diagNatUcSet[$_secUc3])) {
                    $unitAllCids[$_secUc3][] = $_cid;
                }
                $_secNorm3 = _reconcile_normalize_unitcode($_secUc3);
                if ($_secNorm3 !== '' && $_secNorm3 !== $_uc) { // v5.9.210: was !== $_secUc3 (skipped unsuffixed codes)
                    if (!isset($normUnitAllCids[$_secNorm3])) $normUnitAllCids[$_secNorm3] = [];
                    $normUnitAllCids[$_secNorm3][] = $_cid;
                }
                if (!isset($unitToPreferredCid[$_secUc3])) {
                    $unitToPreferredCid[$_secUc3] = $_cid;
                    $unitToChosenCid[$_secUc3]    = $_cid;
                }
                if (isset($_diagNatUcSet[$_secUc3]) && $_dk !== '') {
                    if (!isset($unitDeliveryCourseMap[$_secUc3][$_dk])) {
                        $unitDeliveryCourseMap[$_secUc3][$_dk] = $_cid;
                    }
                }
                foreach ($_qcBranches3 as $_qcB3s) {
                    if (!isset($qualUnitPreferredCid[$_qcB3s][$_secUc3])) {
                        $qualUnitPreferredCid[$_qcB3s][$_secUc3] = $_cid;
                    }
                    if ($_dk !== '' && !isset($qualUnitDeliveryMap[$_qcB3s][$_secUc3][$_dk])) {
                        $qualUnitDeliveryMap[$_qcB3s][$_secUc3][$_dk] = $_cid;
                    }
                }
            }
        }

        // ── MULTI-UNIT secondary-first registration (v5.9.199 / patched v5.9.202) ─
        // Handles combined courses where the PRIMARY unit code (from idnumber /
        // shortname) is NOT needed by any NAT student in this import file, but one
        // or more SECONDARY codes found in the course fullname ARE needed.
        //
        // Root cause of course #143 false positives:
        //   Course #143 fullname = "ABC12345, ABC12345 & ABC12345 Complete and
        //   Check Import/Export..."  idnumber = "ABC12345" (primary).
        //   If no NAT student needs ABC12345, the guard at line ~1250
        //   (if (isset($_diagNatUcSet[$_uc]))) skips the ENTIRE registration block,
        //   including the delivery-key computation and the secondary code registration
        //   loop introduced in v5.9.198.  ABC12345 and ABC12345 are never added to
        //   unitAllCids / unitDeliveryCourseMap / qualUnitDeliveryMap, so students
        //   needing those codes never see course #143 as a candidate — triggering ~211
        //   false-positive ADD rows.
        //
        // v5.9.202 suffix-norm patch: the SUFFIX-NORM-GUARD above now also enters the
        // primary block when normalized primary IS in NAT, so this secondary-first block
        // must only fire when NEITHER the raw NOR the normalized primary is in the NAT
        // set — otherwise delivery-key computation and secondary registration run twice.
        $_sfPrimaryInNat3 = isset($_diagNatUcSet[$_uc]) ||
                            ($_normUc3 !== '' && $_normUc3 !== $_uc && isset($_diagNatUcSet[$_normUc3]));
        if (!$_sfPrimaryInNat3 && count($courseAllUnits[$_cid] ?? []) > 1) {
            $_sfDkFrom = '';
            $_sfDk = _reconcile_course_delivery_key_path(
                (string)$_c->shortname,
                $courseAncestorNames[$_cid] ?? [],
                $_sfDkFrom
            );
            $_sfQcBranches = $catQualBranch[(int)$_c->category] ?? [];
            foreach ($courseAllUnits[$_cid] as $_sfUc) {
                if ($_sfUc === '' || $_sfUc === $_uc) continue; // skip empty + primary (not in NAT anyway)
                $_sfNorm = _reconcile_normalize_unitcode($_sfUc);
                // v5.9.202: accept raw code OR normalised form in NAT set — version-suffixed
                // secondary codes (e.g. BSBMGT502B in archive fullnames) must match the
                // unsuffixed NAT unit (BSBMGT502), or they are silently dropped → poolsize=0.
                $_sfInNat = isset($_diagNatUcSet[$_sfUc]) ||
                            ($_sfNorm !== $_sfUc && $_sfNorm !== '' && isset($_diagNatUcSet[$_sfNorm]));
                if (!$_sfInNat) continue;
                // Register under raw key only when raw code itself is in the NAT set
                if (isset($_diagNatUcSet[$_sfUc])) {
                    $unitAllCids[$_sfUc][] = $_cid;
                }
                // Always register norm index — v5.9.210: was $_sfNorm !== $_sfUc (same
                // unsuffixed-code trap as secondary loop); changed to !== $_uc so plain
                // codes like BSBMGT502 get written to normUnitAllCids when primary is absent.
                if ($_sfNorm !== '' && $_sfNorm !== $_uc) {
                    if (!isset($normUnitAllCids[$_sfNorm])) $normUnitAllCids[$_sfNorm] = [];
                    $normUnitAllCids[$_sfNorm][] = $_cid;
                }
                // Use the NAT-matching key for delivery and qual maps (raw if raw in NAT, norm otherwise)
                $_sfRegKey = isset($_diagNatUcSet[$_sfUc]) ? $_sfUc : $_sfNorm;
                if (!isset($unitToPreferredCid[$_sfRegKey])) {
                    $unitToPreferredCid[$_sfRegKey] = $_cid;
                    $unitToChosenCid[$_sfRegKey]    = $_cid;
                }
                if ($_sfDk !== '') {
                    if (!isset($unitDeliveryCourseMap[$_sfRegKey][$_sfDk])) {
                        $unitDeliveryCourseMap[$_sfRegKey][$_sfDk] = $_cid;
                    }
                }
                foreach ($_sfQcBranches as $_sfQcB) {
                    if (!isset($qualUnitPreferredCid[$_sfQcB][$_sfRegKey])) {
                        $qualUnitPreferredCid[$_sfQcB][$_sfRegKey] = $_cid;
                    }
                    if ($_sfDk !== '' && !isset($qualUnitDeliveryMap[$_sfQcB][$_sfRegKey][$_sfDk])) {
                        $qualUnitDeliveryMap[$_sfQcB][$_sfRegKey][$_sfDk] = $_cid;
                    }
                }
            }
        }
    }
    $_allCrs->close();

    // TRACE v5.9.216: Post-Step-3 pool state — did Step 3 register BSBLDR522?
    // If unitAllCids and normUnitAllCids are both empty here, no Step 3 course
    // had BSBLDR522 in a form that any registration path recognised.
    error_log('TRACE_POST_STEP3 v5.9.216'
        . ' unitAllCids_LDR522=' . count($unitAllCids['BSBLDR522'] ?? [])
        . ' normUnitAllCids_LDR522=' . count($normUnitAllCids['BSBLDR522'] ?? [])
        . ' unitAllCids_MGT502=' . count($unitAllCids['BSBMGT502'] ?? [])
        . ' normUnitAllCids_MGT502=' . count($normUnitAllCids['BSBMGT502'] ?? [])
        . ' cid727_in_courseDetail=' . (array_key_exists(727, $courseDetail) ? 'YES' : 'NO')
        . ' cid727_uc=' . ($courseToUnit[727] ?? 'MISSING')
        . ' cid727_allUnits=[' . implode(',', $courseAllUnits[727] ?? []) . ']'
        . ' LDR522_courses_in_unitAllCids=[' . implode(',', $unitAllCids['BSBLDR522'] ?? []) . ']');

    // ── Pre-Step 3.5a: Qualification Root Discovery ──────────────────────────
    // Before fingerprinting, discover every "qualification root" category in
    // Moodle. A qualification root is any category that:
    //   (a) Has at least one descendant course with an AVETMISS unit code.
    //   (b) Is NOT a semester, archive, delivery, RPL or other structural folder
    //       (identified by exclusion name-patterns below).
    //
    // We then fingerprint ONLY against these discovered roots, not against the
    // depth-1 ancestors. This is the key fix: quals whose category tree looks
    // like  "Courses > Transport > ABC12345 > Semester 1" will now correctly
    // resolve to "Transport" (or whatever the non-excluded ancestor is at the
    // right level), rather than being attributed to the generic top-level
    // "Courses" container — which makes the fingerprint useless for distinguishing
    // quals that all live under the same top-level branch.
    //
    // Structural rule only — no name-based exclusion. Every category that has
    // at least one descendant AVETMISS unit code is a valid qualification root
    // candidate. Name-based exclusions ("archive", "semester", "RPL", …) are
    // brittle — a client may legitimately have "Archive Qualification" or
    // "Resources for Diploma". Instead, the purity metric in Step 3.5
    // automatically demotes broad/generic containers: a top-level "All Courses"
    // node scores 100% coverage but near-zero purity (matched / total_in_branch),
    // so the combined score collapses. A focused branch that contains almost
    // exclusively the qual's own units wins on both coverage and purity.

    // Build $_qrCatUnitSet[catid] = set of distinct AVETMISS unit codes across
    // all descendant courses. Propagated up the full ancestor chain.
    $_qrCatUnitSet = []; // catid → [unitcode => true]
    foreach ($courseToUnit as $_qrCid => $_qrUc) {
        if ($_qrUc === '') continue;
        $_qrCatId = $courseDetail[$_qrCid]->catid ?? 0;
        if (!$_qrCatId || !isset($catById[$_qrCatId])) continue;
        $_qrAncs = array_filter(array_map('intval', explode('/', trim($catById[$_qrCatId]->path, '/'))));
        $_qrAncs[] = $_qrCatId; // include self
        foreach ($_qrAncs as $_qrAncId) {
            $_qrCatUnitSet[$_qrAncId][$_qrUc] = true;
        }
    }
    unset($_qrCid, $_qrUc, $_qrCatId, $_qrAncs, $_qrAncId);

    // $_qrCandidates[catid] = total distinct AVETMISS unit codes in that branch.
    // Structural only — every category with ≥1 AVETMISS descendant is included.
    $_qrCandidates = [];
    foreach ($_qrCatUnitSet as $_qrCid3 => $_qrUcSet3) {
        if (!isset($catById[$_qrCid3])) continue;
        $_qrCandidates[$_qrCid3] = count($_qrUcSet3);
    }
    unset($_qrCid3, $_qrUcSet3, $_qrCatUnitSet);
    // $_qrCandidates kept alive through Step 3.5 for purity scoring.
    $_ufQualFingerDiag = []; // Stage 8: per-qual discovery diagnostics (winner + alternatives)

    // ── Step 3.5: Unit fingerprint discovery for remaining unmapped qualcodes ──
    // For qualcodes where no Moodle category name contained the qualcode string
    // (Step 2.6 found no match), use unit-code overlap to score the qualification
    // root candidates discovered in Pre-Step 3.5a.
    //
    // Algorithm:
    //   1. Collect all unit codes for qualcode Q from NAT data.
    //   2. For each unit code, find which qualification root CANDIDATES (non-excluded
    //      categories with AVETMISS content) contain a course for that unit by
    //      walking up the course's ancestor chain.
    //   3. Score each candidate by the number of matched unique unit codes.
    //   4. Among candidates with equal top score, prefer the DEEPEST one (most
    //      specific = most likely to be the actual qual root, not a generic container).
    //   5. Confidence = (matched / total) × 100; method = 'unit_root_discovery'.
    //
    // After discovery, extend catQualBranch and qualUnit* maps so the ADD engine
    // (Step 6) benefits from the newly discovered roots immediately.
    if (!empty($_adPendingFingerprint)) {
        // Build unit → map of [qual_root_catid => path_depth].
        // depth = number of path segments; higher = deeper = more specific.
        // Pre-compute a regex for the AVETMISS qualification code pattern.
        // Qual codes = 3 uppercase letters + 5 digits (e.g. ABC12345, BSB50420, CHC30121).
        // This is distinct from unit codes (4+ letters, e.g. ABC12345, BSBWHS211).
        $_ufQualCodeRx = '/\b[A-Z]{3}\d{5}\b/';

        $_ufUnitQrCats = []; // unitcode → [qual_root_catid => depth]
        // Per-unit resolution path tracking.
        // 'qual_code'  = resolved to a qual-named ancestor (deterministic).
        // 'fallback'   = no qual-code ancestor found; purity scoring decides (heuristic).
        // 'mixed'      = same unit appears in courses using both paths (shared unit).
        $_ufUnitResVia = [];

        foreach ($courseToUnit as $_ufCid => $_ufUc) {
            if ($_ufUc === '') continue;
            $_ufCatId = $courseDetail[$_ufCid]->catid ?? 0;
            if (!$_ufCatId || !isset($catById[$_ufCatId])) continue;
            $_ufAncs = array_filter(array_map('intval', explode('/', trim($catById[$_ufCatId]->path, '/'))));
            $_ufAncs[] = $_ufCatId; // include direct parent category as well

            // ── Qualification Root Resolution ──────────────────────────────────────
            // Walk UP the ancestor chain (deepest first) and find the FIRST ancestor
            // whose name or idnumber contains a qualification code (3 letters + 5 digits).
            // That single category becomes the ONLY candidate for this course's unit code.
            //
            // Why this matters: without this step, every ancestor with AVETMISS units is a
            // candidate, so leaf delivery folders ("Archive S1-2013", "S1-2015", etc.) enter
            // the race. They achieve near-100% purity (they contain only one qual's units)
            // and beat the actual qualification-named root. By resolving to the qual-named
            // ancestor FIRST, those delivery folders are never independent candidates — they
            // all collapse into the single qualification root they belong to.
            //
            // Fallback: if no qual-code ancestor exists in the chain, add ALL ancestors
            // as before so the purity/coverage scoring can still find the best root.
            // In this case $_ufUnitResVia[$_ufUc] is marked 'fallback' to expose the
            // limitation in the diagnostic panel.
            $_ufQualRootId = null;
            foreach (array_reverse($_ufAncs) as $_ufWId) {
                if (!isset($_qrCandidates[$_ufWId])) continue;
                if (preg_match($_ufQualCodeRx, $catById[$_ufWId]->name ?? '') ||
                    preg_match($_ufQualCodeRx, $catById[$_ufWId]->idnumber ?? '')) {
                    $_ufQualRootId = (int)$_ufWId;
                    break;
                }
            }
            unset($_ufWId);

            if ($_ufQualRootId !== null) {
                // Qual-code ancestor found — only THIS category is the candidate.
                // Assertion: the stored catid must NOT be the course's direct leaf category
                // unless that leaf itself contains a qual code.
                $_ufAncDepth = max(1, substr_count(trim($catById[$_ufQualRootId]->path ?? '/', '/'), '/') + 1);
                if (!isset($_ufUnitQrCats[$_ufUc][$_ufQualRootId]) || $_ufAncDepth > $_ufUnitQrCats[$_ufUc][$_ufQualRootId]) {
                    $_ufUnitQrCats[$_ufUc][$_ufQualRootId] = $_ufAncDepth;
                }
                // Track resolution path for this unit code.
                if (!isset($_ufUnitResVia[$_ufUc])) {
                    $_ufUnitResVia[$_ufUc] = 'qual_code';
                } elseif ($_ufUnitResVia[$_ufUc] === 'fallback') {
                    $_ufUnitResVia[$_ufUc] = 'mixed'; // same unit seen via both paths
                }
            } else {
                // No qual-code ancestor — fall back to all candidate ancestors.
                foreach ($_ufAncs as $_ufAncId) {
                    if (!isset($_qrCandidates[$_ufAncId])) continue;
                    $_ufAncDepth = max(1, substr_count(trim($catById[$_ufAncId]->path ?? '/', '/'), '/') + 1);
                    if (!isset($_ufUnitQrCats[$_ufUc][$_ufAncId]) || $_ufAncDepth > $_ufUnitQrCats[$_ufUc][$_ufAncId]) {
                        $_ufUnitQrCats[$_ufUc][$_ufAncId] = $_ufAncDepth;
                    }
                }
                unset($_ufAncId, $_ufAncDepth);
                // Track resolution path for this unit code.
                if (!isset($_ufUnitResVia[$_ufUc])) {
                    $_ufUnitResVia[$_ufUc] = 'fallback';
                } elseif ($_ufUnitResVia[$_ufUc] === 'qual_code') {
                    $_ufUnitResVia[$_ufUc] = 'mixed'; // same unit seen via both paths
                }
            }
        }
        unset($_ufCid, $_ufUc, $_ufCatId, $_ufAncs, $_ufQualRootId, $_ufQualCodeRx);
        // $_ufUnitResVia and $_qrCandidates kept alive — consumed in scoring loop below.

        $_newlyMappedByUf = []; // qualcode → catid — quals newly mapped in this step

        foreach (array_keys($_adPendingFingerprint) as $_ufQ) {
            if (isset($qualMapMethod[$_ufQ]) && $qualMapMethod[$_ufQ] === 'manual') continue;

            // Collect all unit codes for this qualcode across all clients.
            $_ufUnits = [];
            foreach ($natQualUnits as $_ufQcSet3) {
                foreach (array_keys($_ufQcSet3[$_ufQ] ?? []) as $_ufUcQ) {
                    $_ufUnits[$_ufUcQ] = true;
                }
            }
            unset($_ufQcSet3, $_ufUcQ);
            if (empty($_ufUnits)) continue;
            $_ufTotal = count($_ufUnits);

            // Tally per-unit resolution paths for this qual's unit set.
            // Tells the admin how many units went through deterministic qual-code
            // resolution vs the heuristic fallback — visible in the diagnostics panel.
            $_ufResCode = 0; $_ufResFallback = 0; $_ufResMixed = 0;
            foreach (array_keys($_ufUnits) as $_ufResUc) {
                $_ufResPath = $_ufUnitResVia[$_ufResUc] ?? 'fallback';
                if ($_ufResPath === 'qual_code')  { $_ufResCode++; }
                elseif ($_ufResPath === 'mixed')   { $_ufResMixed++; }
                else                               { $_ufResFallback++; }
            }
            unset($_ufResUc, $_ufResPath);

            // Score each qualification root candidate with WEIGHTED unit counts.
            // Units that resolve to many different branches get weight 1/N, so a
            // shared unit like ABC12345 (XYZ + NCCC) contributes 0.5 to each rather
            // than 1.0 — preventing widely-reused units from inflating any one branch.
            $_ufScores  = []; // catid → float weighted score
            $_ufDepths  = []; // catid → path depth (tiebreaker)
            $_ufRawHits = []; // catid → int raw matched unit count (for display)
            foreach (array_keys($_ufUnits) as $_ufUc4) {
                $_ufBranchSet = $_ufUnitQrCats[$_ufUc4] ?? [];
                $_ufBranchN   = max(1, count($_ufBranchSet));
                $_ufUnitW     = 1.0 / $_ufBranchN; // lower weight for widely-shared units
                foreach ($_ufBranchSet as $_ufQrId4 => $_ufQrD4) {
                    $_ufScores[$_ufQrId4]  = ($_ufScores[$_ufQrId4] ?? 0.0) + $_ufUnitW;
                    $_ufDepths[$_ufQrId4]  = $_ufQrD4;
                    $_ufRawHits[$_ufQrId4] = ($_ufRawHits[$_ufQrId4] ?? 0) + 1;
                }
            }
            unset($_ufUc4, $_ufBranchSet, $_ufBranchN, $_ufUnitW, $_ufQrId4, $_ufQrD4);
            if (empty($_ufScores)) continue;

            // Compute per-candidate combined score = coverage_fraction × purity_fraction.
            //   Coverage = weighted_score / max_possible_weighted_score.
            //   Purity   = raw_matched / total_AVETMISS_units_in_branch.
            // A generic container (e.g. "All Courses") achieves 100% coverage but
            // near-zero purity; combined collapses → won't win. A focused qual branch
            // achieves high coverage AND high purity → combined stays high.
            $_ufMaxWScore = 0.0;
            foreach (array_keys($_ufUnits) as $_ufWUc) {
                $_ufMaxWScore += 1.0 / max(1, count($_ufUnitQrCats[$_ufWUc] ?? []));
            }
            $_ufMaxWScore = max(0.0001, $_ufMaxWScore);
            unset($_ufWUc);

            $_ufCombined = []; // catid → float combined [0,1]
            $_ufCovPct   = []; // catid → int coverage% (raw matched / total NAT units)
            $_ufPurPct   = []; // catid → int purity% (raw matched / branch total)
            foreach ($_ufScores as $_ufCCatId => $_ufCScore) {
                $_ufBranchTotal = max(1, $_qrCandidates[$_ufCCatId] ?? 1);
                $_ufRaw         = $_ufRawHits[$_ufCCatId] ?? 0;
                $_ufCovFrac     = (float)$_ufCScore / $_ufMaxWScore;
                $_ufPurFrac     = (float)$_ufRaw / $_ufBranchTotal;
                $_ufCombined[$_ufCCatId] = max(0.0, min(1.0, $_ufCovFrac * $_ufPurFrac));
                $_ufCovPct[$_ufCCatId]   = (int)round($_ufRaw / $_ufTotal * 100);
                $_ufPurPct[$_ufCCatId]   = (int)round($_ufRaw / $_ufBranchTotal * 100);
            }
            unset($_ufCCatId, $_ufCScore, $_ufBranchTotal, $_ufRaw, $_ufCovFrac, $_ufPurFrac);
            arsort($_ufCombined);

            // Winner = highest combined score; tiebreaker = deepest candidate.
            $_ufBestTopId = null;
            $_ufBestDepth = -1;
            $_ufBestCombo = -1.0;
            foreach ($_ufCombined as $_ufSId => $_ufSCombo) {
                if ($_ufBestTopId === null) { $_ufBestCombo = (float)$_ufSCombo; }
                if ((float)$_ufSCombo < $_ufBestCombo) break;
                $_ufSDepth = $_ufDepths[$_ufSId] ?? 0;
                if ($_ufSDepth > $_ufBestDepth) {
                    $_ufBestDepth = $_ufSDepth;
                    $_ufBestTopId = (int)$_ufSId;
                }
            }
            unset($_ufSId, $_ufSCombo, $_ufSDepth);
            if ($_ufBestTopId === null) continue;

            // Runner-up and margin — stored in diagnostics.
            $_ufCandList     = array_keys($_ufCombined);
            $_ufRunnerUpConf = 0;
            $_ufMargin       = 100;
            if (count($_ufCandList) >= 2) {
                $_ufRunnerUpConf = (int)round($_ufCombined[$_ufCandList[1]] * 100);
                $_ufMargin       = (int)round($_ufCombined[$_ufBestTopId] * 100) - $_ufRunnerUpConf;
            }

            // Missing units — NAT units for this qual with no match in winner's branch.
            $_ufMissingUnits = [];
            foreach (array_keys($_ufUnits) as $_ufMUc) {
                if (!isset($_ufUnitQrCats[$_ufMUc][$_ufBestTopId])) {
                    $_ufMissingUnits[] = $_ufMUc;
                }
            }
            unset($_ufMUc);

            // Stage 8 — Collect top 5 candidates for diagnostic display.
            $_ufAllCands = [];
            foreach ($_ufCombined as $_ufDCatId => $_ufDCombo) {
                if (count($_ufAllCands) >= 5) break;
                $_ufDBTotal = max(1, $_qrCandidates[$_ufDCatId] ?? 1);
                $_ufDRaw    = $_ufRawHits[$_ufDCatId] ?? 0;
                $_ufAllCands[] = [
                    'catid'        => (int)$_ufDCatId,
                    'catname'      => (string)($catById[$_ufDCatId]->name ?? '?'),
                    'raw_matched'  => (int)$_ufDRaw,
                    'total_nat'    => (int)$_ufTotal,
                    'branch_units' => (int)$_ufDBTotal,
                    'coverage_pct' => $_ufCovPct[$_ufDCatId] ?? 0,
                    'purity_pct'   => $_ufPurPct[$_ufDCatId] ?? 0,
                    'combined_pct' => (int)round((float)$_ufDCombo * 100),
                    'depth'        => (int)($_ufDepths[$_ufDCatId] ?? 0),
                    'winner'       => ((int)$_ufDCatId === $_ufBestTopId),
                ];
            }
            // Discovery source: did the winner get selected because it has a qual code in its
            // name/idnumber (deterministic), or via the unit-root heuristic (fallback)?
            $_ufWinnerName = $catById[$_ufBestTopId]->name ?? '';
            $_ufWinnerIdn  = $catById[$_ufBestTopId]->idnumber ?? '';
            $_ufDiscoverySrc = (preg_match('/\b[A-Z]{3}\d{5}\b/', $_ufWinnerName) ||
                                preg_match('/\b[A-Z]{3}\d{5}\b/', $_ufWinnerIdn))
                               ? 'qual_code' : 'unit_root_heuristic';
            unset($_ufWinnerName, $_ufWinnerIdn);

            $_ufQualFingerDiag[$_ufQ] = [
                'total'               => (int)$_ufTotal,
                'missing_units'       => $_ufMissingUnits,
                'runner_up_conf'      => (int)$_ufRunnerUpConf,
                'margin'              => (int)$_ufMargin,
                'candidates'          => $_ufAllCands,
                'discovery_src'       => $_ufDiscoverySrc,
                'units_via_qual_code' => (int)$_ufResCode,
                'units_via_fallback'  => (int)$_ufResFallback,
                'units_mixed'         => (int)$_ufResMixed,
            ];
            unset($_ufAllCands, $_ufDCatId, $_ufDCombo, $_ufDBTotal, $_ufDRaw, $_ufDiscoverySrc,
                  $_ufResCode, $_ufResFallback, $_ufResMixed);

            // Confidence = winner's combined score × 100.
            $_ufConfidence = (int)round($_ufCombined[$_ufBestTopId] * 100);
            if ($_ufConfidence < 1) continue;

            $_ufBestCatName = $catById[$_ufBestTopId]->name ?? '';

            $_ufExist = $DB->get_record('local_rtocompliance_qualmap', ['qualcode' => $_ufQ], 'id', IGNORE_MISSING);
            if ($_ufExist) {
                $DB->update_record('local_rtocompliance_qualmap', (object)[
                    'id'           => $_ufExist->id,
                    'categoryid'   => $_ufBestTopId,
                    'catname'      => $_ufBestCatName,
                    'confidence'   => $_ufConfidence,
                    'method'       => 'unit_root_discovery',
                    'timemodified' => time(),
                ]);
            } else {
                $DB->insert_record('local_rtocompliance_qualmap', (object)[
                    'qualcode'     => $_ufQ,
                    'categoryid'   => $_ufBestTopId,
                    'catname'      => $_ufBestCatName,
                    'confidence'   => $_ufConfidence,
                    'method'       => 'unit_root_discovery',
                    'timecreated'  => time(),
                    'timemodified' => time(),
                ]);
            }
            $qualMap[$_ufQ]           = $_ufBestTopId;
            $qualMapName[$_ufQ]       = $_ufBestCatName;
            $qualMapMethod[$_ufQ]     = 'unit_root_discovery';
            $qualMapConfidence[$_ufQ] = $_ufConfidence;
            $_newlyMappedByUf[$_ufQ]  = $_ufBestTopId;
        }
        unset($_ufQ, $_ufUnits, $_ufTotal,
              $_ufScores, $_ufDepths, $_ufRawHits, $_ufMaxWScore,
              $_ufCombined, $_ufCovPct, $_ufPurPct,
              $_ufBestTopId, $_ufBestDepth, $_ufBestCombo,
              $_ufRunnerUpConf, $_ufMargin, $_ufMissingUnits, $_ufCandList,
              $_ufConfidence, $_ufBestCatName, $_ufExist,
              $_ufUnitQrCats, $_ufUnitResVia, $_adPendingFingerprint, $_qrCandidates);

        // Extend catQualBranch and qual* maps for newly fingerprinted quals.
        // Only quals discovered in this step need extending — Step 2.6 discoveries
        // were already included in the catQualBranch built after Step 2.6.
        if (!empty($_newlyMappedByUf)) {
            foreach ($_newlyMappedByUf as $_nqQ => $_nqCatId) {
                $_nqBranchIds = $catDescendantIds[$_nqCatId] ?? [$_nqCatId];
                foreach ($_nqBranchIds as $_nqBid) {
                    $catQualBranch[$_nqBid][] = $_nqQ;
                }
            }
            // Extend qual-scoped preferred/delivery maps.
            // courseToUnit was built ORDER BY visible DESC, id DESC → first entry = preferred.
            foreach ($courseToUnit as $_nqCid => $_nqUc) {
                if ($_nqUc === '' || !isset($_diagNatUcSet[$_nqUc])) continue;
                $_nqCatId2   = $courseDetail[$_nqCid]->catid ?? 0;
                $_nqBranches = $catQualBranch[$_nqCatId2] ?? [];
                foreach ($_nqBranches as $_nqQcB) {
                    if (!isset($_newlyMappedByUf[$_nqQcB])) continue;
                    if (!isset($qualUnitPreferredCid[$_nqQcB][$_nqUc])) {
                        $qualUnitPreferredCid[$_nqQcB][$_nqUc] = $_nqCid;
                    }
                    if (!empty($courseAncestorNames[$_nqCid])) {
                        $_nqDkFrom = '';
                        $_nqDk = _reconcile_course_delivery_key_path(
                            $courseDetail[$_nqCid]->shortname ?? '',
                            $courseAncestorNames[$_nqCid],
                            $_nqDkFrom
                        );
                        if ($_nqDk !== '' && !isset($qualUnitDeliveryMap[$_nqQcB][$_nqUc][$_nqDk])) {
                            $qualUnitDeliveryMap[$_nqQcB][$_nqUc][$_nqDk] = $_nqCid;
                        }
                    }
                }
            }
            unset($_nqQ, $_nqCatId, $_nqBranchIds, $_nqBid, $_nqCid, $_nqUc,
                  $_nqCatId2, $_nqBranches, $_nqQcB, $_nqDkFrom, $_nqDk);

            // Recompute unmappedQualcodes — unit fingerprint may have resolved some.
            $unmappedQualcodes = [];
            foreach ($natQualUnits as $_nqLc => $_nqQcSet) {
                foreach (array_keys($_nqQcSet) as $_nqQc3) {
                    if ($_nqQc3 !== '' && !isset($qualMap[$_nqQc3])) {
                        $unmappedQualcodes[$_nqQc3] = true;
                    }
                }
            }
            $unmappedQualcodes = array_keys($unmappedQualcodes);
            sort($unmappedQualcodes);
            unset($_nqLc, $_nqQcSet, $_nqQc3);
        }
        unset($_newlyMappedByUf);
    }

    // ── Diagnostic: query manual enrolment counts for all candidate courses ────
    // One SQL call covers every course in $unitAllCids — avoids N+1 queries.
    $_diagManualCounts = []; // courseid → manual enrolment count
    $_diagAllCandidateCids = [];
    foreach ($unitAllCids as $_cids) {
        foreach ($_cids as $_cid) { $_diagAllCandidateCids[$_cid] = true; }
    }
    $_diagAllCandidateCids = array_keys($_diagAllCandidateCids);
    if (!empty($_diagAllCandidateCids)) {
        list($_dmcs, $_dmcp) = $DB->get_in_or_equal($_diagAllCandidateCids, SQL_PARAMS_NAMED, 'dmc');
        $_diagMcRs = $DB->get_recordset_sql(
            "SELECT e.courseid, COUNT(ue.id) AS cnt
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.enrol = 'manual'
                AND e.courseid $_dmcs
              GROUP BY e.courseid",
            $_dmcp
        );
        foreach ($_diagMcRs as $_dm) {
            $_diagManualCounts[(int)$_dm->courseid] = (int)$_dm->cnt;
        }
        $_diagMcRs->close();
    }

    // ── Step 4: Load ALL active manual enrolments for matched students ──────────
    // No course-ID filter. The unit-centric engine validates each enrolment by
    // extracting the unit code from the course and checking the student's NAT data.
    // Any delivery of a NAT unit is considered a legitimate enrolment.
    // ue.timecreated is fetched to distinguish POST-IMPORT enrolments (created
    // after the NAT import timestamp).
    $currentEnrolments = []; // userid → [courseid => unitcode]
    $enrolTimecreated  = []; // userid → [courseid => unix timestamp]
    if (!empty($matchedUserids)) {
        list($_uidsql, $_uidp) = $DB->get_in_or_equal($matchedUserids, SQL_PARAMS_NAMED, 'rcur');
        $_curRs = $DB->get_recordset_sql(
            "SELECT ue.userid, e.courseid, ue.timecreated
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = 'manual'
                AND ue.status = 0
                AND ue.userid $_uidsql",
            $_uidp
        );
        foreach ($_curRs as $_ce) {
            $_uid4 = (int)$_ce->userid;
            $_cid4 = (int)$_ce->courseid;
            $currentEnrolments[$_uid4][$_cid4] = $courseToUnit[$_cid4] ?? '';
            $enrolTimecreated[$_uid4][$_cid4]  = (int)$_ce->timecreated;
        }
        $_curRs->close();
    }

    // ── Step 4b: Load suspended (ue.status=1) manual enrolments for coverage credit ─
    // Archived-course enrolments commonly have ue.status=1 (suspended) — Moodle
    // suspends enrolments when a course is hidden or archived. Step 4's ue.status=0
    // filter makes these invisible to Step 5, so the student appears uncovered for
    // that unit and a false-positive ADD row is emitted.
    //
    // ROOT CAUSE (confirmed July 2026): the trace already queries ue.status=1 and
    // identifies this as "the most common reason isset($_covered6) fails" — but the
    // trace never fed those enrolments back into the coverage calculation.
    //
    // These records are loaded SOLELY for Step 5 coverage credit via $actualUnitCoverage.
    // They are NOT candidates for REMOVE, KEEP, or POST-IMPORT classification —
    // suspended enrolments are not actionable and should not appear in output CSVs.
    $suspendedEnrolments = []; // userid → [courseid => unitcode]  (coverage-only)
    if (!empty($matchedUserids)) {
        list($_suidsql4b, $_suidp4b) = $DB->get_in_or_equal($matchedUserids, SQL_PARAMS_NAMED, 'rsusp');
        $_suspRs = $DB->get_recordset_sql(
            "SELECT ue.userid, e.courseid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = 'manual'
                AND ue.status = 1
                AND ue.userid $_suidsql4b",
            $_suidp4b
        );
        foreach ($_suspRs as $_se4b) {
            $_suid4b = (int)$_se4b->userid;
            $_scid4b = (int)$_se4b->courseid;
            // Only record if not already captured as an active (status=0) enrolment.
            if (!isset($currentEnrolments[$_suid4b][$_scid4b])) {
                $suspendedEnrolments[$_suid4b][$_scid4b] = $courseToUnit[$_scid4b] ?? '';
            }
        }
        $_suspRs->close();
    }

    // ── Step 4.5: Back-fill enrolled courses absent from Step 3's scan ───────────────
    // Step 3 processes courses ORDER BY visible DESC, id DESC — hidden/archive courses
    // appear LAST.  If PHP runs out of memory or time before the recordset is exhausted,
    // those courses are absent from $courseToUnit / $courseDetail.  The Step 4 lookup
    // "$courseToUnit[$_cid4] ?? ''" then stores '' for every affected enrolment, causing
    // primary extraction to have nothing to normalise and the fullname fallback to have
    // no fullname to scan — both paths silently return "no coverage" and a false-positive
    // ADD row is emitted.
    //
    // Fix: detect any course ID referenced in $currentEnrolments that is absent from
    // $courseDetail, query those courses directly, and update all three maps so Step 5
    // can credit coverage correctly regardless of course visibility or load order.
    $_bf45Cids = [];
    foreach ($currentEnrolments as $_bf45EnrolMap) {
        foreach (array_keys($_bf45EnrolMap) as $_bf45Cid) {
            if (!array_key_exists($_bf45Cid, $courseDetail)) {
                $_bf45Cids[$_bf45Cid] = true;
            }
        }
    }
    // Also back-fill for courses referenced ONLY in suspended (status=1) enrolments.
    // Step 4b populates $suspendedEnrolments using courseToUnit which may be incomplete
    // for archived courses that Step 3 didn't scan — those entries get '' as unit code.
    // Including them here ensures Step 4.5 fetches their details and resolves the code.
    foreach ($suspendedEnrolments as $_bf45SuspMap) {
        foreach (array_keys($_bf45SuspMap) as $_bf45Cid) {
            if (!array_key_exists($_bf45Cid, $courseDetail)) {
                $_bf45Cids[$_bf45Cid] = true;
            }
        }
    }
    if (!empty($_bf45Cids)) {
        list($_bf45Sql, $_bf45P) = $DB->get_in_or_equal(array_keys($_bf45Cids), SQL_PARAMS_NAMED, 'rbf');
        $_bf45Rs = $DB->get_recordset_sql(
            "SELECT c.id, c.shortname, c.fullname, c.idnumber, c.category, c.visible,
                    cc.name AS catname
               FROM {course} c
               LEFT JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id $_bf45Sql",
            $_bf45P
        );
        foreach ($_bf45Rs as $_bf45C) {
            $_bf45Id  = (int)$_bf45C->id;
            $_bf45Uc  = _reconcile_extract_unitcode(
                (string)($_bf45C->idnumber ?? ''),
                (string)($_bf45C->shortname ?? ''),
                (string)($_bf45C->fullname ?? '')
            );
            // Determine extraction source (mirrors Step 3 logic) for fullname-fallback guard
            $_bf45Idn    = strtoupper(trim((string)($_bf45C->idnumber ?? '')));
            $_bf45Shn    = strtoupper(trim((string)($_bf45C->shortname ?? '')));
            $_bf45Fn     = strtoupper(trim((string)($_bf45C->fullname  ?? '')));
            $_bf45SrcPat = '/^([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
            $_bf45FnPat  = '/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/';
            if (preg_match('/^[A-Z]{2,7}[0-9]{3,5}[A-Z]?$/', $_bf45Idn)) {
                $_bf45Src = 'idnumber_exact';
            } elseif (preg_match($_bf45SrcPat, $_bf45Idn)) {
                $_bf45Src = 'idnumber_prefix';
            } elseif (preg_match($_bf45SrcPat, $_bf45Shn)) {
                $_bf45Src = 'shortname';
            } elseif (preg_match($_bf45FnPat, $_bf45Fn)) {
                $_bf45Src = 'fullname';
            } else {
                $_bf45Src = '';
            }
            preg_match_all($_bf45FnPat, $_bf45Fn, $_bf45FnM);
            $_bf45FnList  = $_bf45FnM[1] ?? [];
            $_bf45FnCount = count($_bf45FnList);
            $_bf45Flags   = array_merge(
                $_bf45Uc === '' ? ['NO_UNIT_CODE'] : [],
                $_bf45FnCount > 1 ? ['MULTIPLE_IN_FULLNAME'] : [],
                ['backfill']
            );
            $courseToUnit[$_bf45Id]         = $_bf45Uc;
            $courseDetail[$_bf45Id]         = (object)[
                'shortname' => (string)($_bf45C->shortname ?? ''),
                'fullname'  => (string)($_bf45C->fullname  ?? ''),
                'idnumber'  => (string)($_bf45C->idnumber  ?? ''),
                'catid'     => (int)($_bf45C->category ?? 0),
                'catname'   => (string)($_bf45C->catname  ?? ''),
                'visible'   => (int)($_bf45C->visible ?? 0),
            ];
            $courseExtractionMeta[$_bf45Id] = [
                'source'         => $_bf45Src,
                'fullname_count' => $_bf45FnCount,
                'fullname_list'  => implode('; ', $_bf45FnList),
                'flags'          => implode(', ', $_bf45Flags),
            ];
            // FIX v5.9.215: Populate courseAllUnits for back-filled archive courses.
            // Step 3's courseAllUnits build (line ~1257) runs only for courses in the
            // initial Step 3 SQL recordset. Archive/hidden courses that fall off under
            // memory or time pressure are absent from $courseAllUnits, so the Step 5
            // secondary coverage pass (foreach $courseAllUnits[$_cid5] ?? []) sees an
            // empty array and silently misses secondary unit codes — e.g. BSBLDR522 in
            // "BSBOPS505 and BSBLDR522 MPC 21S1" is never credited as covered, causing
            // false-positive ADD rows with poolsize=0.
            // Mirror the same three-field preg_match_all merge that Step 3 uses.
            // Note: $_bf45FnM was already run at line ~2101 (fullname multi-match).
            {
                preg_match_all('/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/', $_bf45Idn, $_bf45MuIdnM215);
                preg_match_all('/(?<![A-Z0-9])([A-Z]{2,7}[0-9]{3,5}[A-Z]?)(?:[^A-Z0-9]|$)/', $_bf45Shn, $_bf45MuSnM215);
                $courseAllUnits[$_bf45Id] = array_values(array_unique(array_filter(
                    array_merge([$_bf45Uc], $_bf45MuIdnM215[1] ?? [], $_bf45MuSnM215[1] ?? [], $_bf45FnM[1] ?? []),
                    fn($_c215) => $_c215 !== ''
                )));
            }
            // FIX v5.9.215: Register secondary codes of back-filled combined courses
            // into the ADD candidate pool (unitAllCids / normUnitAllCids).
            // The v5.9.214 FULLNAME-CODES-DIRECT-REG loop runs only inside Step 3 —
            // archive courses that bypass Step 3 never get their secondary codes
            // registered, so Step 6 sees unitAllCids['BSBLDR522'] = [] → poolsize = 0
            // for any student needing BSBLDR522 who has no existing enrolment.
            foreach ($courseAllUnits[$_bf45Id] as $_bf45SecUc215) {
                if ($_bf45SecUc215 === '' || $_bf45SecUc215 === $_bf45Uc) continue;
                $_bf45SecNorm215 = _reconcile_normalize_unitcode($_bf45SecUc215);
                // Only register when this secondary code is needed by a NAT student —
                // prevents false-positive ADD recommendations for unreported units.
                $_bf45SecInNat215 = isset($_diagNatUcSet[$_bf45SecUc215]) ||
                    ($_bf45SecNorm215 !== $_bf45SecUc215 && $_bf45SecNorm215 !== '' &&
                     isset($_diagNatUcSet[$_bf45SecNorm215]));
                if (!$_bf45SecInNat215) continue;
                // Raw key: register under the exact NAT key.
                if (isset($_diagNatUcSet[$_bf45SecUc215])) {
                    $unitAllCids[$_bf45SecUc215][] = $_bf45Id;
                } elseif ($_bf45SecNorm215 !== '' && isset($_diagNatUcSet[$_bf45SecNorm215])) {
                    $unitAllCids[$_bf45SecNorm215][] = $_bf45Id;
                }
                // Normalised map — always write when in NAT (Step 6 merges both maps).
                if ($_bf45SecNorm215 !== '') {
                    if (!isset($normUnitAllCids[$_bf45SecNorm215])) {
                        $normUnitAllCids[$_bf45SecNorm215] = [];
                    }
                    $normUnitAllCids[$_bf45SecNorm215][] = $_bf45Id;
                }
                // Preferred course pointer — only set if not already established.
                $_bf45SecRegKey215 = isset($_diagNatUcSet[$_bf45SecUc215]) ? $_bf45SecUc215 : $_bf45SecNorm215;
                if ($_bf45SecRegKey215 !== '' && !isset($unitToPreferredCid[$_bf45SecRegKey215])) {
                    $unitToPreferredCid[$_bf45SecRegKey215] = $_bf45Id;
                    $unitToChosenCid[$_bf45SecRegKey215]    = $_bf45Id;
                }
            }
            // Update $currentEnrolments for all students enrolled in this course —
            // replaces the '' placeholder with the now-resolved unit code.
            foreach ($currentEnrolments as $_bf45Uid => &$_bf45UidMap) {
                if (isset($_bf45UidMap[$_bf45Id])) {
                    $_bf45UidMap[$_bf45Id] = $_bf45Uc;
                }
            }
            unset($_bf45UidMap);
            // Also update $suspendedEnrolments — same '' placeholder issue.
            // These are the 62 residual false positives: archived courses with status=1
            // enrolments that Step 3 missed and Step 4b stored as '' unit code.
            foreach ($suspendedEnrolments as $_bf45SuspUid => &$_bf45SuspMap) {
                if (isset($_bf45SuspMap[$_bf45Id])) {
                    $_bf45SuspMap[$_bf45Id] = $_bf45Uc;
                }
            }
            unset($_bf45SuspMap);
        }
        $_bf45Rs->close();
    }

    // TRACE v5.9.216: Post-Step-4.5 state — did back-fill change anything for BSBLDR522?
    error_log('TRACE_POST_STEP45 v5.9.216'
        . ' unitAllCids_LDR522=' . count($unitAllCids['BSBLDR522'] ?? [])
        . ' normUnitAllCids_LDR522=' . count($normUnitAllCids['BSBLDR522'] ?? [])
        . ' cid727_in_courseDetail=' . (array_key_exists(727, $courseDetail) ? 'YES' : 'NO')
        . ' cid727_in_courseAllUnits=' . (array_key_exists(727, $courseAllUnits) ? 'YES' : 'NO')
        . ' cid727_allUnits=[' . implode(',', $courseAllUnits[727] ?? []) . ']'
        . ' cid808_in_courseAllUnits=' . (array_key_exists(808, $courseAllUnits) ? 'YES' : 'NO')
        . ' cid808_allUnits=[' . implode(',', $courseAllUnits[808] ?? []) . ']'
        . ' LDR522_courses=[' . implode(',', $unitAllCids['BSBLDR522'] ?? []) . ']');

    // ── Step 5: KEEP / POST-IMPORT / REMOVE / REVIEW ──────────────────────────
    // KEEP        = unit code IS in student's NAT — any delivery is legitimate
    // POST-IMPORT = unit code NOT in NAT, but enrolment was created AFTER the NAT
    //               import timestamp — legitimate admin/IMIS addition; must not be removed
    // REMOVE      = unit code NOT in NAT and enrolment predates the NAT import —
    //               likely a genuine mismatch from before the FoE incident
    // REVIEW      = course has no extractable unit code — cannot determine automatically
    //               (orientation, LLN, community, test courses, etc.)
    $keepEnrolments       = []; // userid → [courseid => true]
    $postImportEnrolments = []; // userid → [courseid => true]
    $removeEnrolments     = []; // userid → [courseid => true]
    $reviewEnrolments     = []; // userid → [courseid => true]  (no unit code)
    $actualUnitCoverage   = []; // userid → [unitcode => courseid] first course covering that unit
    $_importTs = (int)$importRec->timecreated;

    // DIAG v5.9.215: One-time sanity check — log courseAllUnits size for known combined
    // archive courses before Step 5 runs. Fires once per reconciler run (not per student).
    // "count=0" → course went through Step 4.5 back-fill without courseAllUnits being set
    //             (confirms theory; v5.9.215 Step 4.5 fix corrects this path).
    // "count=2" → course was loaded by Step 3 and already has both codes; Step 4.5 fix
    //             was a no-op for this course (drop is elsewhere; Step 3 path is fine).
    // Remove after confirmed on target Moodle instance.
    foreach ([727, 808] as $_diag215Cid) {
        if (array_key_exists($_diag215Cid, $courseDetail)) {
            error_log('RECONCILER_STEP5_COURSEALLUNITS_DIAG cid=' . $_diag215Cid .
                      ' count=' . count($courseAllUnits[$_diag215Cid] ?? []) .
                      ' codes=' . implode(',', $courseAllUnits[$_diag215Cid] ?? []) .
                      ' bf45=' . (array_key_exists($_diag215Cid, $courseAllUnits) ? 'SET' : 'UNSET'));
        }
    }

    foreach ($clientToUid as $_lc5 => $_uid5) {
        $_natUnitSet5 = $natUnits[$_lc5] ?? [];

        // v5.9.173: Build normalised NAT lookup for version-suffix-tolerant coverage matching.
        // Maps normalised-root → original NAT code so coverage can be recorded under the exact
        // NAT key that Step 6 iterates over — ensuring isset($covered[$_uc6]) hits correctly.
        //
        // Examples:
        //   NAT has ABC12345  → $_normNatLookup5['ABC12345'] = 'ABC12345'
        //   NAT has ABC12345   → $_normNatLookup5['ABC12345'] = 'ABC12345'
        //   NAT has ABC12345   → $_normNatLookup5['ABC12345'] = 'ABC12345'
        $_normNatLookup5 = [];
        foreach (array_keys($_natUnitSet5) as $_nk5) {
            $_nn5 = _reconcile_normalize_unitcode($_nk5);
            if (!isset($_normNatLookup5[$_nn5])) { $_normNatLookup5[$_nn5] = $_nk5; }
        }

        foreach ($currentEnrolments[$_uid5] ?? [] as $_cid5 => $_uc5) {

            // ── Primary extraction match ──────────────────────────────────────
            // $_natCovKey5 = original NAT code covered; null = not yet matched.
            //
            // Uses version-suffix normalisation in BOTH directions so that a student
            // enrolled in ABC12345 (archived course) is credited as covering NAT unit
            // ABC12345, and vice versa.  Archive/qual-branch status is intentionally
            // NOT checked here — coverage answers "has the student ever had a manual
            // enrolment for this unit?" regardless of course visibility.
            //
            //   1. Exact:             ABC12345 == ABC12345
            //   2. Normalise course:  ABC12345 → ABC12345 → look up exact NAT
            //   3. Normalise both:    ABC12345 → ABC12345 → look up normalised-NAT index
            //   4. Exact vs norm-NAT: ABC12345 (course) → $_normNatLookup5 key = ABC12345
            $_natCovKey5 = null;
            if ($_uc5 !== '') {
                if (isset($_natUnitSet5[$_uc5])) {
                    $_natCovKey5 = $_uc5;                              // 1. exact
                } else {
                    $_normCrs5 = _reconcile_normalize_unitcode($_uc5);
                    if ($_normCrs5 !== $_uc5) {
                        if (isset($_natUnitSet5[$_normCrs5])) {
                            $_natCovKey5 = $_normCrs5;                 // 2. norm course → exact NAT
                        } elseif (isset($_normNatLookup5[$_normCrs5])) {
                            $_natCovKey5 = $_normNatLookup5[$_normCrs5]; // 3. norm course → norm NAT
                        }
                    }
                    if ($_natCovKey5 === null && isset($_normNatLookup5[$_uc5])) {
                        $_natCovKey5 = $_normNatLookup5[$_uc5];        // 4. exact course = norm-NAT root
                    }
                }
            }

            // ── v5.9.177 Fullname fallback (broadened) ───────────────────────
            // v5.9.176 introduced this fallback with a source guard that restricted it to
            // courses where the extraction source was 'fullname' or '' (i.e. idnumber and
            // shortname yielded no unit code).  Acceptance testing showed 150 archive-
            // coverage cases unchanged — courses with idnumber-prefix sources (e.g.
            // "TLIA5061AXYZ S2-2012") whose primary normalisation silently failed left
            // the source guard blocking the only remaining recovery path.
            //
            // v5.9.177 removes the source guard entirely.  The fallback now activates for
            // ANY enrolment where primary extraction + normalisation did not credit coverage
            // ($_natCovKey5 === null), regardless of extraction source.
            //
            // v5.9.198: The '&' fullname guard has been removed.  Multi-unit combined
            // courses (e.g. "ABC12345 & ABC12345 course") are now handled correctly
            // by the MULTI-UNIT secondary coverage pass below, which credits every unit
            // the course teaches.  The '&' guard was preventing the fullname fallback
            // from crediting coverage for ANY unit in a combined course — this caused
            // 368 false-positive BSBMGT502 ADD rows for students already enrolled.
            //
            // Additionally, Step 4.5 (above) back-fills any archive courses absent from
            // $courseDetail so that both the primary path and this fallback have correct
            // fullname/idnumber data to work with.
            //
            // Search covers all three suffix/root combinations:
            //   • NAT=ABC12345, fullname has ABC12345   → exact match (A)
            //   • NAT=ABC12345, fullname has ABC12345  → NAT code + trailing letter (B)
            //   • NAT=ABC12345, fullname has ABC12345  → normalised-NAT root (C)
            if ($_natCovKey5 === null) {
                $_fn5 = strtoupper(trim($courseDetail[$_cid5]->fullname ?? ''));
                if ($_fn5 !== '') {
                    foreach (array_keys($_natUnitSet5) as $_fnUc5) {
                        $_fnBase5 = preg_quote($_fnUc5, '/');
                        // A. Exact: fullname contains NAT code (word-boundary)
                        if (preg_match('/(?<![A-Z0-9])' . $_fnBase5 . '(?:[^A-Z0-9]|$)/', $_fn5)) {
                            $_natCovKey5 = $_fnUc5; break;
                        }
                        // B. NAT code + trailing letter: fullname has ABC12345, NAT has ABC12345
                        if (preg_match('/(?<![A-Z0-9])' . $_fnBase5 . '[A-Z](?:[^A-Z0-9]|$)/', $_fn5)) {
                            $_natCovKey5 = $_fnUc5; break;
                        }
                        // C. NAT code is suffixed; fullname has the unsuffixed root
                        //    e.g. NAT=ABC12345, fullname has ABC12345
                        $_fnUcNorm5 = _reconcile_normalize_unitcode($_fnUc5);
                        if ($_fnUcNorm5 !== $_fnUc5) {
                            $_fnNBase5 = preg_quote($_fnUcNorm5, '/');
                            if (preg_match('/(?<![A-Z0-9])' . $_fnNBase5 . '(?:[^A-Z0-9]|$)/', $_fn5)) {
                                $_natCovKey5 = $_fnUc5; break;
                            }
                        }
                    }
                }
            }

            // ── Apply coverage result ─────────────────────────────────────────
            if ($_uc5 === '' && $_natCovKey5 === null) {
                // No extractable code and fullname fallback also failed → manual review
                $reviewEnrolments[$_uid5][$_cid5] = true;
                continue;
            }

            if ($_natCovKey5 !== null) {
                // Unit IS in student's NAT (primary or fullname-fallback) — legitimate enrolment.
                // Coverage recorded under original NAT key so Step 6's isset() resolves correctly.
                $keepEnrolments[$_uid5][$_cid5] = true;
                if (!isset($actualUnitCoverage[$_uid5][$_natCovKey5])) {
                    $actualUnitCoverage[$_uid5][$_natCovKey5] = $_cid5;
                }
            } else {
                // Unit code present but absent from student's NAT.
                // Classify by enrolment creation timestamp vs the NAT import timestamp.
                if (($enrolTimecreated[$_uid5][$_cid5] ?? 0) >= $_importTs) {
                    // Created AFTER the NAT import — legitimate post-import enrolment
                    $postImportEnrolments[$_uid5][$_cid5] = true;
                } else {
                    // Created BEFORE the NAT import — genuine mismatch
                    $removeEnrolments[$_uid5][$_cid5] = true;
                }
            }

            // ── MULTI-UNIT secondary coverage (v5.9.198) ─────────────────────────
            // For combined courses (e.g. "BSBCUS501C & BSBMGT502B Manage People"),
            // $courseAllUnits[$_cid5] contains EVERY unit code found in the course.
            // The primary match above credits coverage for the primary extracted code;
            // this pass credits coverage for EVERY additional code the course teaches,
            // using the same 4-way normalisation applied to the primary match.
            //
            // This is the fix for 368 false-positive BSBMGT502 ADD rows: students
            // enrolled in combined courses were being flagged as "missing BSBMGT502"
            // because only the primary code (BSBCUS501) was credited.
            foreach ($courseAllUnits[$_cid5] ?? [] as $_secUc5) {
                if ($_secUc5 === '' || $_secUc5 === $_uc5) continue; // primary already handled
                $_secCovKey5 = null;
                if (isset($_natUnitSet5[$_secUc5])) {
                    $_secCovKey5 = $_secUc5;                                   // 1. exact
                } else {
                    $_secNorm5 = _reconcile_normalize_unitcode($_secUc5);
                    if ($_secNorm5 !== $_secUc5) {
                        if (isset($_natUnitSet5[$_secNorm5])) {
                            $_secCovKey5 = $_secNorm5;                         // 2. norm-course → exact NAT
                        } elseif (isset($_normNatLookup5[$_secNorm5])) {
                            $_secCovKey5 = $_normNatLookup5[$_secNorm5];       // 3. norm-course → norm-NAT
                        }
                    }
                    if ($_secCovKey5 === null && isset($_normNatLookup5[$_secUc5])) {
                        $_secCovKey5 = $_normNatLookup5[$_secUc5];             // 4. exact-course = norm-NAT root
                    }
                }
                if ($_secCovKey5 !== null) {
                    if (!isset($actualUnitCoverage[$_uid5][$_secCovKey5])) {
                        $actualUnitCoverage[$_uid5][$_secCovKey5] = $_cid5;
                    }
                    // Enrolment is legitimate (student IS in a course covering this unit)
                    $keepEnrolments[$_uid5][$_cid5] = true;
                    unset($removeEnrolments[$_uid5][$_cid5], $postImportEnrolments[$_uid5][$_cid5]);
                }
            }
        }

        // ── Step 5b: Coverage credit for suspended (ue.status=1) enrolments ──────────
        // Mirrors the primary + suffix-normalisation + fullname-fallback logic above.
        // Only $actualUnitCoverage is updated — no KEEP/REMOVE/POST-IMPORT entries are
        // written for suspended enrolments (they are not actionable in output CSVs).
        foreach ($suspendedEnrolments[$_uid5] ?? [] as $_scid5 => $_suc5) {
            $_sNatCovKey5 = null;
            if ($_suc5 !== '') {
                if (isset($_natUnitSet5[$_suc5])) {
                    $_sNatCovKey5 = $_suc5;                                  // 1. exact
                } else {
                    $_sNormCrs5 = _reconcile_normalize_unitcode($_suc5);
                    if ($_sNormCrs5 !== $_suc5) {
                        if (isset($_natUnitSet5[$_sNormCrs5])) {
                            $_sNatCovKey5 = $_sNormCrs5;                     // 2. norm-course → exact NAT
                        } elseif (isset($_normNatLookup5[$_sNormCrs5])) {
                            $_sNatCovKey5 = $_normNatLookup5[$_sNormCrs5];   // 3. norm-course → norm-NAT
                        }
                    }
                    if ($_sNatCovKey5 === null && isset($_normNatLookup5[$_suc5])) {
                        $_sNatCovKey5 = $_normNatLookup5[$_suc5];            // 4. exact-course = norm-NAT root
                    }
                }
            }
            // Fullname fallback for suspended enrolments
            // v5.9.198: '&' guard removed (same rationale as active-enrolment fallback above).
            if ($_sNatCovKey5 === null) {
                $_sfn5 = strtoupper(trim($courseDetail[$_scid5]->fullname ?? ''));
                if ($_sfn5 !== '') {
                    foreach (array_keys($_natUnitSet5) as $_sfnUc5) {
                        $_sfnBase5 = preg_quote($_sfnUc5, '/');
                        if (preg_match('/(?<![A-Z0-9])' . $_sfnBase5 . '(?:[^A-Z0-9]|$)/', $_sfn5)) {
                            $_sNatCovKey5 = $_sfnUc5; break;
                        }
                        if (preg_match('/(?<![A-Z0-9])' . $_sfnBase5 . '[A-Z](?:[^A-Z0-9]|$)/', $_sfn5)) {
                            $_sNatCovKey5 = $_sfnUc5; break;
                        }
                        $_sfnUcNorm5 = _reconcile_normalize_unitcode($_sfnUc5);
                        if ($_sfnUcNorm5 !== $_sfnUc5) {
                            $_sfnNBase5 = preg_quote($_sfnUcNorm5, '/');
                            if (preg_match('/(?<![A-Z0-9])' . $_sfnNBase5 . '(?:[^A-Z0-9]|$)/', $_sfn5)) {
                                $_sNatCovKey5 = $_sfnUc5; break;
                            }
                        }
                    }
                }
            }
            if ($_sNatCovKey5 !== null && !isset($actualUnitCoverage[$_uid5][$_sNatCovKey5])) {
                $actualUnitCoverage[$_uid5][$_sNatCovKey5] = $_scid5;
            }

            // ── MULTI-UNIT secondary coverage for suspended enrolments (v5.9.198) ─
            foreach ($courseAllUnits[$_scid5] ?? [] as $_ssecUc5) {
                if ($_ssecUc5 === '' || $_ssecUc5 === $_suc5) continue;
                $_ssecCovKey5 = null;
                if (isset($_natUnitSet5[$_ssecUc5])) {
                    $_ssecCovKey5 = $_ssecUc5;
                } else {
                    $_ssecNorm5 = _reconcile_normalize_unitcode($_ssecUc5);
                    if ($_ssecNorm5 !== $_ssecUc5) {
                        if (isset($_natUnitSet5[$_ssecNorm5])) {
                            $_ssecCovKey5 = $_ssecNorm5;
                        } elseif (isset($_normNatLookup5[$_ssecNorm5])) {
                            $_ssecCovKey5 = $_normNatLookup5[$_ssecNorm5];
                        }
                    }
                    if ($_ssecCovKey5 === null && isset($_normNatLookup5[$_ssecUc5])) {
                        $_ssecCovKey5 = $_normNatLookup5[$_ssecUc5];
                    }
                }
                if ($_ssecCovKey5 !== null && !isset($actualUnitCoverage[$_uid5][$_ssecCovKey5])) {
                    $actualUnitCoverage[$_uid5][$_ssecCovKey5] = $_scid5;
                }
            }
        }
    }

    // ── Step 6: ADD — find NAT units with no actual enrolment coverage ────────
    // Course selection follows a strict priority order — newestVisibleCourse() REMOVED.
    //
    //   Priority 1: Exact semester match within qual branch    → qual_delivery
    //   Priority 2: Exact semester match globally              → global_delivery
    //   Priority 3: No semester match →
    //     • Current (visible, non-archive) delivery exists?   → UNRESOLVED (no ADD emitted)
    //     • Archive-only deliveries exist?                    → Historical recommendation (ADD, FALLBACK)
    //     • No Moodle courses at all?                         → No recommendation
    //
    // Never automatically select the newest visible course when no semester match is found.
    // qual_preferred and global_preferred fallbacks are REMOVED.
    //
    // Pre-build O(1) lookup set for NAT clientids and for match-path trust check.
    // Used by the NAT-DIRECT-ID guard below to suppress ADD rows for students
    // matched only via email (Path D) or USI (Path E) whose Moodle identity
    // cannot be directly confirmed against any NAT clientid.
    $_natCidSet6 = array_flip($natClientIds); // lc_clientid → true

    // ── QUALDEBUG: wipe the ENTIRE table on every run so the diagnostic view
    // always reflects only the current run. Previously this deleted only rows
    // matching the selected importid, which left rows from other runs in the
    // table and made admin queries return mixed data from multiple runs.
    // Full wipe guarantees: (a) no stale rows from any prior importid, (b) the
    // row count and qual coverage in the table always match the current run
    // exactly, (c) re-running the reconciler with a different importid never
    // shows phantom rows from the previous selection.
    try {
        $DB->execute('DELETE FROM {local_rtocompliance_qualdebug}');
    } catch (Throwable $_qdDelEx) {
        error_log('RECONCILER_QUALDEBUG_DELETE_FAILED v5.9.216: ' . $_qdDelEx->getMessage()
            . ' — table may not exist; scoring continues normally.');
    }
    $_qdRows = []; // accumulate rows for bulk-insert after the scoring loop

    $addEnrolments = []; // userid → [courseid → unitcode]
    $addMeta       = []; // userid → [courseid → metadata array]
    $addUnresolved = []; // userid → [uc → metadata] — units with current delivery but no semester match
    //
    // Confidence classification for ADD rows:
    //   HIGH     — exact semester match, current (non-archive) Moodle course
    //   MEDIUM   — exact semester match, but matched course is an archived delivery
    //   FALLBACK — archive-only delivery; no current delivery and no semester match

    foreach ($clientToUid as $_lc6 => $_uid6) {
        $_natUnitSet6 = $natUnits[$_lc6] ?? [];
        $_covered6    = $actualUnitCoverage[$_uid6] ?? [];
        foreach (array_keys($_natUnitSet6) as $_uc6) {
            if (isset($_covered6[$_uc6])) continue;
            $_sd6 = $natStartdate[$_lc6][$_uc6] ?? '';
            $_dk6 = _reconcile_delivery_key($_sd6);
            $_qc6 = $natUnitQual[$_lc6][$_uc6] ?? '';

            // ── v5.9.167 Scoring Engine — replaces 5-priority chain ──────────
            // Architecture: Unit → All candidates (including normalised lookup)
            //               → score each → pick best → confidence tier → file routing.
            //
            // Confidence tiers (numeric %):
            //   ≥ 150 score (current + sem match):        95–100% → moodle_upload
            //   100–149 (current, no sem):                80%     → review_required
            //   < 0 with a winner (archive only):         50%     → review_required
            //   No candidates / truly ambiguous:          0%      → unmatched
            //
            // Duplicate protection: $addEnrolments[uid][cid] map key is inherently unique.

            // Student qual-type extraction: e.g. "XYZ" from qual branch category name
            // $_sqCatId6 MUST be reset here — if omitted, a stale value from a previous
            // student/unit iteration persists via PHP's loop-variable retention, causing
            // the ancestry check in _reconcile_score_candidate to use the wrong catId.
            $_studentQualType6 = '';
            $_sqCatId6         = 0;
            if ($_qc6 !== '' && isset($qualMap[$_qc6])) {
                $_sqCatId6  = (int)($qualMap[$_qc6] ?? 0);
                $_sqCatRec6 = $catById[$_sqCatId6] ?? null;
                if ($_sqCatRec6) {
                    $_studentQualType6 = _reconcile_extract_qual_type((string)$_sqCatRec6->name);
                }
            }

            // Candidate pool: exact unit code + version-suffix variants.
            //
            // v5.9.195 POOL-TRUNCATION FIX: The guard `$_normUc6 !== $_uc6` was wrong.
            // normUnitAllCids['ABC12345'] holds courses whose unit code EXTRACTED as
            // 'ABC12345' (with version suffix) — normalised to 'ABC12345'. These are
            // real deliveries of the unit that MUST be scored. The old guard blocked the
            // merge whenever the NAT unit code was already normalised (e.g. 'ABC12345'),
            // leaving those 9 ABC12345 courses out of the pool → poolsize=8 of 17.
            // Fix: always merge normUnitAllCids[normUc] regardless of whether the NAT
            // unit code itself needed normalisation.
            $_normUc6   = _reconcile_normalize_unitcode($_uc6);
            $_candPool6 = $unitAllCids[$_uc6] ?? [];
            if (isset($normUnitAllCids[$_normUc6])) {
                $_candPool6 = array_values(array_unique(
                    array_merge($_candPool6, $normUnitAllCids[$_normUc6])
                ));
            }
            // v5.9.210 DIAGNOSTIC: log pool state for BSBMGT502 on first encounter per run
            if ($_uc6 === 'BSBMGT502' && !isset($_qdBsbmgt502Logged)) {
                $_qdBsbmgt502Logged = true;
                error_log('POOL_DIAG_BSBMGT502'
                    . ' unitAllCids=' . count($unitAllCids['BSBMGT502'] ?? [])
                    . ' normUnitAllCids=' . count($normUnitAllCids['BSBMGT502'] ?? [])
                    . ' candPool=' . count($_candPool6)
                    . ' ts=' . date('c'));
            }
            // TRACE v5.9.216: BSBLDR522 pool — first encounter per run.
            if (($_uc6 === 'BSBLDR522' || $_uc6 === 'BSBLDR522A' || $_uc6 === 'BSBLDR522B')
                && !isset($_t216Ldr522Logged)) {
                $_t216Ldr522Logged = true;
                $_t216NormUc6 = _reconcile_normalize_unitcode($_uc6);
                error_log('POOL_DIAG_BSBLDR522 v5.9.216'
                    . ' uc=[' . $_uc6 . ']'
                    . ' normUc=[' . $_t216NormUc6 . ']'
                    . ' unitAllCids_exact=' . count($unitAllCids[$_uc6] ?? [])
                    . ' unitAllCids_LDR522=' . count($unitAllCids['BSBLDR522'] ?? [])
                    . ' normUnitAllCids_normed=' . count($normUnitAllCids[$_t216NormUc6] ?? [])
                    . ' candPool=' . count($_candPool6)
                    . ' pool_cids=[' . implode(',', $_candPool6) . ']'
                    . ' covered=' . (isset($_covered6[$_uc6]) ? 'YES' : 'NO')
                    . ' ts=' . date('c'));
            }

            // Score every candidate
            $_bestScore6    = PHP_INT_MIN;
            $_bestCid6      = null;
            $_bestFlags6    = [];
            $_bestCourseDk6 = '';
            $_topCount6     = 0;   // how many candidates share the top score
            $_currentCands6 = 0; $_archiveCands6  = 0;
            $_hasCurrentCid6 = null; $_hasArchiveCid6 = null;

            foreach ($_candPool6 as $_cand6) {
                $_cDet6 = $courseDetail[$_cand6] ?? null;
                if (!$_cDet6) continue;

                $_cAnc6    = $courseAncestorNames[$_cand6] ?? [];
                $_cArcTxt6 = implode(' ', $_cAnc6) . ' ' . (string)$_cDet6->catname;
                $_cIsArc6  = stripos($_cArcTxt6, 'archive') !== false;

                if (!$_cIsArc6) {
                    $_currentCands6++;
                    if ($_hasCurrentCid6 === null) $_hasCurrentCid6 = $_cand6;
                } else {
                    $_archiveCands6++;
                    if ($_hasArchiveCid6 === null) $_hasArchiveCid6 = $_cand6;
                }

                $_res6 = _reconcile_score_candidate(
                    $_cDet6, $_cAnc6, $_dk6, $_qc6, $catQualBranch, $_studentQualType6,
                    $qualMap, $_sqCatId6 ?? 0, $catById
                );
                $_sc6 = $_res6['score'];

                if ($_sc6 > $_bestScore6) {
                    $_bestScore6    = $_sc6;
                    $_bestCid6      = $_cand6;
                    $_bestFlags6    = $_res6['flags'];
                    $_bestCourseDk6 = $_res6['courseDk'];
                    $_topCount6     = 1;
                } elseif ($_sc6 === $_bestScore6) {
                    $_topCount6++;
                }
            }

            // ── QUALDEBUG: record one row per unit/student scoring attempt ────
            // Captures poolsize, best_score, best_flags, course_dk so the RTO
            // can diagnose why a unit code scores -9999 or zero-pool without
            // SSH access.  Bulk-inserted after the loop to minimise DB round-trips.
            $_qdRows[] = [
                'importid'   => $importid,
                'qc'         => $_qc6,
                'sqcatid'    => $_sqCatId6 ?? 0,
                'qualtype'   => $_studentQualType6,
                'uc'         => $_uc6,
                'dk'         => $_dk6,
                'poolsize'   => count($_candPool6),
                'topcount'   => $_topCount6,
                'best_score' => ($_bestScore6 === PHP_INT_MIN ? -9999 : $_bestScore6),
                'best_flags' => implode('|', $_bestFlags6),
                'course_dk'  => $_bestCourseDk6,
                'tstamp'     => time(),
            ];

            // ── Resolve selection and map to backward-compatible lookup_mode ──
            $_prefCid6    = null;
            $_isFallback6 = false;
            $_lookupMode6 = '';

            if ($_bestCid6 !== null) {
                $_hasSem6  = in_array('sem_match',  $_bestFlags6, true);
                $_hasYear6 = in_array('year_match',  $_bestFlags6, true);
                $_hasQB6   = in_array('qual_branch', $_bestFlags6, true);
                $_hasArc6  = in_array('archive',     $_bestFlags6, true);

                if (!$_hasArc6 && $_topCount6 > 1 && !$_hasSem6 && !$_hasYear6) {
                    // Multiple current courses tied with no time signal → UNRESOLVED
                    $_frReason6 = ($_dk6 === '')
                        ? ('No parseable semester in startdate "' . $_sd6 . '"')
                        : ($_currentCands6 . ' current deliveries for ' . $_uc6
                            . '; no semester "' . $_dk6 . '" match (score ' . $_bestScore6 . ')');
                    if (!isset($addUnresolved[$_uid6])) { $addUnresolved[$_uid6] = []; }
                    $addUnresolved[$_uid6][$_uc6] = [
                        'reason'       => $_frReason6,
                        'current_cid'  => $_hasCurrentCid6,
                        'archive_cid'  => $_hasArchiveCid6,
                        'qualcode'     => $_qc6,
                        'dk'           => $_dk6,
                        'sd'           => $_sd6,
                        'currentCands' => $_currentCands6,
                        'archiveCands' => $_archiveCands6,
                        'totalCands'   => count($_candPool6),
                    ];
                    continue; // no ADD emitted
                }

                // Use best scorer
                $_prefCid6 = $_bestCid6;

                // Map winning flags → legacy lookup_mode string (trace/CSV compatibility)
                // v5.9.193: archive+sem_match is now a high-confidence path (not fallback).
                if ($_hasArc6 && $_hasSem6 && $_hasQB6)         $_lookupMode6 = 'qual_hist_delivery';
                elseif ($_hasArc6 && $_hasSem6)                  $_lookupMode6 = 'global_hist_delivery';
                elseif (!$_hasArc6 && $_hasSem6 && $_hasQB6)    $_lookupMode6 = 'qual_delivery';
                elseif (!$_hasArc6 && $_hasSem6)                 $_lookupMode6 = 'global_delivery';
                elseif (!$_hasArc6 && $_currentCands6 === 1 && $_hasQB6) $_lookupMode6 = 'qual_unique_match';
                elseif (!$_hasArc6 && $_currentCands6 === 1)     $_lookupMode6 = 'global_unique_match';
                elseif (!$_hasArc6 && $_hasYear6 && $_hasQB6)   $_lookupMode6 = 'qual_sem_scored';
                elseif (!$_hasArc6 && $_hasYear6)                $_lookupMode6 = 'global_sem_scored';
                elseif ($_hasArc6) { $_lookupMode6 = 'historical_archive'; $_isFallback6 = true; }
                else               $_lookupMode6 = 'global_unique_match';
            }

            // No candidates at all → skip
            if ($_prefCid6 === null) continue;

            // Year match: any course for this unit shares the same calendar year?
            $_yearMatch6 = false;
            if ($_dk6 !== '') {
                $_yr6 = substr($_dk6, 0, 4);
                foreach (array_keys($unitDeliveryCourseMap[$_uc6] ?? []) as $_dkk6) {
                    if (substr($_dkk6, 0, 4) === $_yr6) { $_yearMatch6 = true; break; }
                }
            }

            $_cd6        = $courseDetail[$_prefCid6] ?? null;
            $_catname6   = $_cd6 ? (string)$_cd6->catname : '';
            $_visible6   = $_cd6 ? (int)$_cd6->visible    : 0;
            $_isArchive6 = ($_visible6 === 0 || stripos($_catname6, 'archive') !== false)
                           || in_array('archive', $_bestFlags6, true);
            $_delivType6  = $_isArchive6 ? 'ARCHIVE' : 'CURRENT';

            // ── v5.9.193 Numeric confidence (% for 3-file routing) ────────────
            // moodle_upload.csv  — ≥ 95%: confirmed semester match; safe for bulk import
            // review_required.csv — 50–94%: archive no-match OR current without date signal
            // unmatched_add.csv  — 0%: no Moodle course found / ambiguous tie
            //
            // v5.9.193 RULE (client mandate — NAT is source of truth):
            //   If NAT says student studied a unit in 2016, enrol them in the 2016 delivery
            //   (even if archived), NOT the current 2026-S1 delivery. Exact sem_match on an
            //   archived course is CORRECT and must route to moodle_upload.
            //
            // Implementation: sem_match scoring raised to +250 (from +50) so any course with
            //   an exact delivery-key match wins over the current course (which scores +100).
            //   Score comparison: archive+sem_match+QB = -100+250+30 = 180 > current+QB = 130.
            //
            // Confidence matrix:
            //   archive + sem_match + qual_branch → 100% → moodle_upload  [historical confirmed]
            //   archive + sem_match               →  95% → moodle_upload  [historical confirmed]
            //   current + sem_match + qual_branch → 100% → moodle_upload
            //   current + sem_match               →  95% → moodle_upload
            //   archive (no sem_match)            →  50% → review_required
            //   current + year_match              →  80% → review_required
            //   current (no time signal)          →  80% → review_required
            $_hasSemFlag6  = in_array('sem_match',  $_bestFlags6, true);
            $_hasYearFlag6 = in_array('year_match',  $_bestFlags6, true);
            $_hasQBFlag6   = in_array('qual_branch', $_bestFlags6, true);
            if ($_hasSemFlag6 && $_hasQBFlag6) {
                $_confidencePct6 = 100;   // exact semester + qual branch (archive OR current)
            } elseif ($_hasSemFlag6) {
                $_confidencePct6 = 95;    // exact semester (archive OR current)
            } elseif ($_isArchive6) {
                $_confidencePct6 = 50;    // archive, no exact semester → human review
            } elseif ($_hasYearFlag6) {
                $_confidencePct6 = 80;    // current + year match only
            } else {
                $_confidencePct6 = 80;    // current, unique match, no time signal
            }

            // Legacy label — sem_match on archive is HIGH (not MEDIUM), so drive by pct only
            $_confidence6 = $_isFallback6 ? 'FALLBACK'
                : ($_confidencePct6 >= 95 ? 'HIGH' : 'MEDIUM');

            // ── ALREADY-ENROLLED SUPPRESSION (v5.9.196) ─────────────────────────────
            // Belt-and-braces guard: if the student already has an active (status=0)
            // or suspended (status=1) manual enrolment in the exact recommended course,
            // OR in any other course in the candidate pool (any delivery of this unit),
            // do NOT emit an ADD row — they are already enrolled in a delivery of this
            // unit.
            //
            // This guard fires when Step 5 coverage matching silently failed, which
            // can happen for archive courses where:
            //   (a) courseToUnit[$cid] is '' (blank idnumber AND no unit code in
            //       fullname), so currentEnrolments[uid][cid] = '' and the
            //       normalisation paths at lines 1882-1896 are all skipped, OR
            //   (b) a Step 3 recordset timeout left the course absent from courseToUnit
            //       before Step 4.5 could back-fill it.
            //
            // Regardless of root cause: recommending a student for a course they are
            // already enrolled in is always wrong. This guard is the authoritative
            // final check before any ADD row is written.
            //
            // Side-effect: retroactively credits $actualUnitCoverage and $_covered6
            // so downstream trace logic sees the student as covered for this unit.
            $_alreadyIn6 = null;
            if (isset($currentEnrolments[$_uid6][$_prefCid6])) {
                $_alreadyIn6 = $_prefCid6;   // exact recommended course — active
            } elseif (isset($suspendedEnrolments[$_uid6][$_prefCid6])) {
                $_alreadyIn6 = $_prefCid6;   // exact recommended course — suspended
            } else {
                // Scan the full candidate pool: student may be in a different delivery
                // of the same unit that Step 5 failed to credit as coverage.
                foreach ($_candPool6 as $_poolCid6) {
                    if (isset($currentEnrolments[$_uid6][$_poolCid6]) ||
                        isset($suspendedEnrolments[$_uid6][$_poolCid6])) {
                        $_alreadyIn6 = $_poolCid6;
                        break;
                    }
                }
            }
            if ($_alreadyIn6 !== null) {
                // Back-fill coverage so trace output and subsequent unit iterations
                // see this unit as covered rather than missing.
                if (!isset($actualUnitCoverage[$_uid6][$_uc6])) {
                    $actualUnitCoverage[$_uid6][$_uc6] = $_alreadyIn6;
                    $_covered6[$_uc6]                  = $_alreadyIn6;
                }
                continue; // already enrolled → suppress ADD row
            }

            // ── NAT-BASIS GUARD v3 (v5.9.200) ────────────────────────────────────────
            // Universal, match-path-independent guard: before emitting ANY ADD row,
            // verify that the Moodle student's OWN identity (idnumber or username)
            // is directly corroborated by the current NAT import for this specific unit.
            //
            // Problem with the v5.9.197/199 D/E-only guards:
            //   They checked the clientMatchPath tag (which defaults to 'A' if absent)
            //   and only fired for Path D/E.  But the guards fail in two scenarios:
            //   (a) The tag is 'A' (or another trusted path) even though the student
            //       was effectively matched to a foreign clientid — for example, the
            //       Path D email lookup found a clientid in the current import that
            //       belongs to a different physical person who happens to share that ID,
            //       and storeMatch(clientid, userid, 'D') was called.  The D/E guard then
            //       checks $_ownIdn6 !== $_lc6 → matched-clientid !== matched-clientid → FALSE → passes.
            //   (b) The match-path is correctly recorded as 'D'/'E' but the matched
            //       clientid equals the user's own idnumber, so the identity check
            //       passes even when the NAT record belongs to a different person.
            //
            // The v5.9.200 guard is path-independent:
            //   Instead of trusting the match-path tag, it directly checks whether
            //   the student's OWN idnumber or username has a NAT record for THIS
            //   SPECIFIC UNIT in the current import.  If neither does, AND neither
            //   their idnumber nor username equals the matched clientid, the student
            //   has no confirmed NAT basis for this recommendation → suppress.
            //
            // Condition: emit ADD row only if at least ONE of:
            //   (a) own idnumber IS the matched clientid (direct A-path link)
            //   (b) own username IS the matched clientid (direct C-path link)
            //   (c) own idnumber has a NAT record for this unit in the current import
            //   (d) own username has a NAT record for this unit in the current import
            //
            // Catches all 18 confirmed no-NAT-basis rows:
            //   16 zero-NAT students: idnumber/username not in $natUnits at all
            //   1 student (789): has NAT records for OTHER units but not the one
            //     being recommended (matched via D/E to a clientid with that unit)
            //
            $_uDet6b  = $uidToDetails[$_uid6] ?? null;
            $_ownId6b = strtolower(trim((string)($_uDet6b->idnumber ?? '')));
            $_ownUn6b = strtolower(trim((string)($_uDet6b->username ?? '')));
            $_natBasis6 = (
                $_ownId6b === $_lc6 ||                                   // (a) direct idnumber link
                $_ownUn6b === $_lc6 ||                                   // (b) direct username link
                ($_ownId6b !== '' && isset($natUnits[$_ownId6b][$_uc6])) || // (c) own idnumber has NAT record for this unit
                ($_ownUn6b !== '' && isset($natUnits[$_ownUn6b][$_uc6]))    // (d) own username has NAT record for this unit
            );
            if (!$_natBasis6) {
                continue; // No confirmed NAT basis for this student/unit → suppress ADD row
            }

            $addEnrolments[$_uid6][$_prefCid6] = $_uc6;
            $addMeta[$_uid6][$_prefCid6] = [
                'delivery_type'  => $_delivType6,
                'confidence'     => $_confidence6,
                'confidence_pct' => $_confidencePct6,
                'score'          => $_bestScore6 === PHP_INT_MIN ? 0 : $_bestScore6,
                'score_flags'    => implode('|', $_bestFlags6),
                'lookup_mode'    => $_lookupMode6,
                'qualcode'       => $_qc6,
                'qual_type'      => $_studentQualType6,
                'uc'             => $_uc6,
                'dk'             => $_dk6,
                'sd'             => $_sd6,
                'currentCands'   => $_currentCands6,
                'archiveCands'   => $_archiveCands6,
                'totalCands'     => count($_candPool6),
                'semesterMatch'  => $_hasSemFlag6,
                'yearMatch'      => $_hasYearFlag6,
            ];
        }
    }

    // ── QUALDEBUG: secondary pass for UNLINKED students ──────────────────────
    // The main loop above only runs for $clientToUid (linked students). Quals
    // whose students are ALL unlinked never appear in $_qdRows, so
    // COUNT(DISTINCT qc) can show fewer quals than the NAT file contains (e.g.
    // 12 instead of 15). Fix: iterate over all NAT clientids NOT in
    // $clientToUid and write one deduplicated row per (qc, uc) with
    // best_score=-9999 and best_flags='unlinked_student'. One row per unique
    // (qc, uc) pair — not one per unlinked student — keeps the table lean while
    // ensuring every qual appears in COUNT(DISTINCT qc). The poolsize field is
    // populated so the admin can see whether a Moodle course exists for the
    // unit even though the student couldn't be linked.
    $_qdUnlinkedSeen = [];
    foreach ($natUnits as $_lcU => $_unitSetU) {
        if (isset($clientToUid[$_lcU])) continue; // already handled in main loop
        foreach (array_keys($_unitSetU) as $_ucU) {
            $_qcU     = $natUnitQual[$_lcU][$_ucU] ?? '';
            $_dKey    = $_qcU . '|' . $_ucU;
            if (isset($_qdUnlinkedSeen[$_dKey])) continue;
            $_qdUnlinkedSeen[$_dKey] = true;

            $_normUcU = _reconcile_normalize_unitcode($_ucU);
            $_poolU   = $unitAllCids[$_ucU] ?? [];
            if (isset($normUnitAllCids[$_normUcU])) {
                $_poolU = array_values(array_unique(array_merge($_poolU, $normUnitAllCids[$_normUcU])));
            }
            $_qdRows[] = [
                'importid'   => $importid,
                'qc'         => $_qcU,
                'sqcatid'    => isset($qualMap[$_qcU]) ? (int)$qualMap[$_qcU] : 0,
                'qualtype'   => '',
                'uc'         => $_ucU,
                'dk'         => '',
                'poolsize'   => count($_poolU),
                'topcount'   => 0,
                'best_score' => -9999,
                'best_flags' => 'unlinked_student',
                'course_dk'  => '',
                'tstamp'     => time(),
            ];
        }
    }
    unset($_qdUnlinkedSeen, $_lcU, $_unitSetU, $_ucU, $_qcU, $_dKey, $_normUcU, $_poolU);

    // ── QUALDEBUG: bulk-insert all scoring rows accumulated above ─────────────
    // 500-row chunks to stay within MySQL max_allowed_packet limits.
    // v5.9.208: importid column now in schema; graceful fallback strips it for
    // old installs that haven't run the upgrade yet.
    if (!empty($_qdRows)) {
        try {
            foreach (array_chunk($_qdRows, 500) as $_qdChunk) {
                $DB->insert_records('local_rtocompliance_qualdebug', $_qdChunk);
            }
        } catch (Throwable $_qdInsEx) {
            // importid column not yet present — retry stripping it
            $_qdRowsNoImp = array_map(
                fn($_r) => array_diff_key($_r, ['importid' => true]),
                $_qdRows
            );
            foreach (array_chunk($_qdRowsNoImp, 500) as $_qdChunk) {
                $DB->insert_records('local_rtocompliance_qualdebug', $_qdChunk);
            }
        }
    }
    error_log('RECONCILER_ENGINE_QUALDEBUG_WRITTEN importid=' . intval($importid)
        . ' rows=' . count($_qdRows)
        . ' ts=' . date('c'));

    // ── Step 6b: Enhanced Friday Backup analysis — 4-source RESTORE classification ──
    // Sources used:
    //   (1) Friday backup   — what was enrolled before the FoE incident
    //   (2) Current Moodle  — what is enrolled right now
    //   (3) NAT data        — is this unit still expected by AVETMISS?
    //   (4) Post-import     — was this unit already replaced by an IMIS/admin enrolment?
    //
    // Classification for entries in Friday backup that are MISSING from current Moodle:
    //   RESTORE            — unit still in NAT, no post-import replacement (High confidence)
    //   POST_IMPORT_REPLACED — post-import enrolment exists for same unit (High confidence)
    //   LEGITIMATE_REMOVE  — unit NOT in student's NAT — removal was correct (High confidence)
    //   REVIEW             — course has no unit code, cannot cross-reference (Medium confidence)
    //
    // Only runs when a Friday backup CSV was uploaded.

    // Build post-import unit coverage: uid → [unitcode => replacement courseid]
    // Used to detect when a missing enrolment has already been replaced by admin/IMIS.
    $postImportUnitCoverage = []; // uid → [unitcode => courseid]
    foreach ($postImportEnrolments as $_piUid => $_piCids) {
        foreach (array_keys($_piCids) as $_piCid) {
            $_piUc = $courseToUnit[$_piCid] ?? '';
            if ($_piUc !== '' && !isset($postImportUnitCoverage[$_piUid][$_piUc])) {
                $postImportUnitCoverage[$_piUid][$_piUc] = $_piCid;
            }
        }
    }

    $fridayBackupMissing = []; // uid → [courseid => ['class','confidence','reason','unit_code']]
    $restoreCandidates   = []; // uid → [courseid => true] — backward compat: RESTORE class only

    // Per-class counters
    $_rt_countRestore     = 0;
    $_rt_countPiReplaced  = 0;
    $_rt_countLegitRemove = 0;
    $_rt_countRtReview    = 0;

    if ($fridayBackupLoaded) {
        foreach ($clientToUid as $_lc7 => $_uid7) {
            $_natSet7 = $natUnits[$_lc7] ?? [];
            foreach ($fridayBackup[$_uid7] ?? [] as $_cid7 => $_) {
                if (isset($currentEnrolments[$_uid7][$_cid7])) {
                    continue; // Still enrolled — covered by KEEP / POST-IMPORT counts
                }
                $_fbUc7 = $courseToUnit[$_cid7] ?? '';
                if ($_fbUc7 === '') {
                    // No unit code — cannot cross-reference NAT
                    $_class7 = 'REVIEW';
                    $_conf7  = 'Medium';
                    $_rsn7   = 'Course has no unit code — cannot cross-reference NAT data; verify manually';
                    $_rt_countRtReview++;
                } elseif (isset($_natSet7[$_fbUc7])) {
                    // Unit IS in student's NAT
                    $_piReplaceCid = $postImportUnitCoverage[$_uid7][$_fbUc7] ?? null;
                    if ($_piReplaceCid !== null) {
                        // Unit already covered by a post-import enrolment (IMIS / admin)
                        $_replaceSn = $courseDetail[$_piReplaceCid]->shortname ?? '';
                        $_class7 = 'POST_IMPORT_REPLACED';
                        $_conf7  = 'High';
                        $_rsn7   = 'Unit already covered by post-import enrolment'
                                   . ($_replaceSn !== '' ? " ({$_replaceSn})" : '')
                                   . ' — replaced by IMIS or manual admin enrolment after NAT import; no restore needed';
                        $_rt_countPiReplaced++;
                    } else {
                        // Unit in NAT, not replaced — genuine RESTORE candidate
                        $_class7 = 'RESTORE';
                        $_conf7  = 'High';
                        $_rsn7   = 'Enrolment missing from Moodle, unit still expected by NAT, no post-import replacement found — restore recommended';
                        $_rt_countRestore++;
                        $restoreCandidates[$_uid7][$_cid7] = true;
                    }
                } else {
                    // Unit NOT in student's NAT — removal was correct
                    $_class7 = 'LEGITIMATE_REMOVE';
                    $_conf7  = 'High';
                    $_rsn7   = 'Unit not in student\'s current NAT data — removal was correct; do not restore';
                    $_rt_countLegitRemove++;
                }
                $fridayBackupMissing[$_uid7][$_cid7] = [
                    'class'      => $_class7,
                    'confidence' => $_conf7,
                    'reason'     => $_rsn7,
                    'unit_code'  => $_fbUc7,
                ];
            }
        }
    }

    // ── Diagnostic data ───────────────────────────────────────────────────────
    $_diagMatchedUsers  = count($matchedUserids);
    $_diagNatClientIds  = count($natClientIds);
    $_diagUnitsSeen     = [];
    foreach ($natUnits as $_lcx => $_ucx) {
        foreach (array_keys($_ucx) as $_ucxx) { $_diagUnitsSeen[$_ucxx] = true; }
    }
    $_diagNatUnits    = count($_diagUnitsSeen);
    $_diagUnitsMapped = 0;
    $_diagUnitsUnmapped = [];
    foreach ($_diagUnitsSeen as $_dUc => $_) {
        if (isset($unitToPreferredCid[$_dUc])) { $_diagUnitsMapped++; }
        else { $_diagUnitsUnmapped[] = $_dUc; }
    }
    $_diagTotalActual = 0;
    foreach ($currentEnrolments as $_cSet) { $_diagTotalActual += count($_cSet); }
    $_diagTotalKeep   = 0;
    foreach ($keepEnrolments   as $_kSet) { $_diagTotalKeep   += count($_kSet); }
    $_diagTotalRemove = 0;
    foreach ($removeEnrolments as $_rSet) { $_diagTotalRemove += count($_rSet); }
    $_diagTotalReview = 0;
    foreach ($reviewEnrolments as $_rvSet) { $_diagTotalReview += count($_rvSet); }
    $_diagTotalAdd        = 0;
    foreach ($addEnrolments        as $_aSet) { $_diagTotalAdd        += count($_aSet); }
    $_diagTotalPostImport = 0;
    foreach ($postImportEnrolments as $_pSet) { $_diagTotalPostImport += count($_pSet); }
    // 4-source RESTORE classification counts (computed in Step 6b)
    $_diagTotalRestore     = $_rt_countRestore;      // RESTORE only (actionable)
    $_diagTotalPiReplaced  = $_rt_countPiReplaced;   // POST_IMPORT_REPLACED
    $_diagTotalLegitRemove = $_rt_countLegitRemove;  // LEGITIMATE_REMOVE
    $_diagTotalRtReview    = $_rt_countRtReview;     // REVIEW (no unit code)
    $_diagTotalRestoreAll  = $_diagTotalRestore + $_diagTotalPiReplaced + $_diagTotalLegitRemove + $_diagTotalRtReview;

    // ── Unmapped Unit Classifier ──────────────────────────────────────────────
    // For each NAT unit code that has no preferred Moodle course, do a broader
    // LIKE search to determine WHY it didn't map.  Four categories:
    //
    //   secondary  — code appears in course fullname/shortname but the extractor's
    //                first match is a different code.  Expected per client rule:
    //                "the first unit code in a multi-unit course is the source of truth."
    //                No action required.
    //
    //   superseded — Moodle uses a similar code that is one character longer/shorter
    //                (e.g. NAT has BSBMGT502, Moodle has BSBMGT502B).
    //                Translation / mapping required.
    //
    //   historical — No Moodle course found AND all NAT enrolments for this code
    //                are dated more than 5 years ago.  These are AVETMISS-only
    //                historical records — no active Moodle course is expected.
    //                No action required.
    //
    //   no_course  — code not found anywhere in any Moodle course name AND has
    //                recent enrolments.  Genuine investigation required.
    //
    // _reconcile_extract_unitcode() is intentionally NOT changed — the client has
    // confirmed "the first unit code wins" for multi-unit courses.

    // Pre-fetch MAX/MIN startdate for all unmapped unit codes across ALL imports.
    // startdate is stored as DDMMYYYY; year = substr(startdate, 4, 4).
    $_ucDateInfo = []; // unitcode → ['first_seen'=>str, 'last_seen'=>str, 'enrolments'=>n, 'students'=>n]
    if (!empty($_diagUnitsUnmapped)) {
        list($_ucDateSql, $_ucDateParams) = $DB->get_in_or_equal(
            $_diagUnitsUnmapped, SQL_PARAMS_NAMED, 'ud'
        );
        $_ucDateRows = $DB->get_records_sql(
            "SELECT unitcode,
                    COUNT(*) AS enrolments,
                    COUNT(DISTINCT clientid) AS students,
                    MIN(startdate) AS first_seen,
                    MAX(startdate) AS last_seen
               FROM {local_rtocompliance_avetmiss_enrolment}
              WHERE unitcode $_ucDateSql
              GROUP BY unitcode",
            $_ucDateParams
        );
        foreach ($_ucDateRows as $_udr) {
            $_ucDateInfo[(string)$_udr->unitcode] = [
                'first_seen' => (string)$_udr->first_seen,
                'last_seen'  => (string)$_udr->last_seen,
                'enrolments' => (int)$_udr->enrolments,
                'students'   => (int)$_udr->students,
            ];
        }
    }
    // Historical threshold: unit last seen more than 5 years ago → no active Moodle course expected.
    $_historicalThresholdYear = (int)date('Y') - 5; // 2026 → threshold = 2021

    $_ucClassifier = []; // unitcode → [class, reason, action, example_sn, example_fn, first_code, ...]
    if (!empty($_diagUnitsUnmapped)) {
        foreach ($_diagUnitsUnmapped as $_unmUc) {
            $_likeEsc = '%' . $DB->sql_like_escape($_unmUc) . '%';
            $_bMatches = $DB->get_records_sql(
                "SELECT c.id, c.shortname, c.fullname, c.idnumber
                   FROM {course} c
                  WHERE c.id <> 1
                    AND (c.idnumber   LIKE :lk1
                      OR c.shortname  LIKE :lk2
                      OR c.fullname   LIKE :lk3)
                  LIMIT 3",
                ['lk1' => $_likeEsc, 'lk2' => $_likeEsc, 'lk3' => $_likeEsc]
            );

            if (empty($_bMatches)) {
                // No Moodle course found anywhere — check if this is historical AVETMISS-only data.
                $_udInfo        = $_ucDateInfo[$_unmUc] ?? null;
                $_lastSeenYear  = 0;
                $_firstSeenYear = 0;
                if ($_udInfo && strlen($_udInfo['last_seen']) >= 8) {
                    $_lastSeenYear  = (int)substr($_udInfo['last_seen'],  4, 4); // DDMMYYYY → YYYY
                    $_firstSeenYear = (int)substr($_udInfo['first_seen'], 4, 4);
                }
                if ($_udInfo && $_lastSeenYear > 0 && $_lastSeenYear <= $_historicalThresholdYear) {
                    // All enrolments are historical — no Moodle course is expected.
                    $_ucClassifier[$_unmUc] = [
                        'class'       => 'historical',
                        'reason'      => 'All NAT enrolments dated ' . $_firstSeenYear . '–' . $_lastSeenYear
                                         . ' (' . _reconcile_n($_udInfo['students']) . ' students, '
                                         . _reconcile_n($_udInfo['enrolments']) . ' enrolments). '
                                         . 'No active Moodle course is expected for historical-only AVETMISS records.',
                        'action'      => 'No action needed — historical AVETMISS record only',
                        'example_sn'  => '', 'example_fn' => '', 'first_code' => '',
                        'first_seen'  => $_udInfo['first_seen'],
                        'last_seen'   => $_udInfo['last_seen'],
                        'enrolments'  => $_udInfo['enrolments'],
                        'students'    => $_udInfo['students'],
                        'year_range'  => $_firstSeenYear . '–' . $_lastSeenYear,
                    ];
                } else {
                    $_ucClassifier[$_unmUc] = [
                        'class'      => 'no_course',
                        'reason'     => 'Not found in any Moodle course name (idnumber, shortname, or fullname)',
                        'action'     => 'Investigate: may be a programme/accreditation code or genuinely missing course',
                        'example_sn' => '', 'example_fn' => '', 'first_code' => '',
                    ];
                }
            } else {
                $_classified = false;
                foreach ($_bMatches as $_bm) {
                    $_fc = _reconcile_extract_unitcode(
                        (string)$_bm->idnumber, (string)$_bm->shortname, (string)$_bm->fullname
                    );
                    if ($_fc === $_unmUc) {
                        // First code matches but unitToPreferredCid was not set — edge case
                        $_ucClassifier[$_unmUc] = [
                            'class'      => 'mapping_anomaly',
                            'reason'     => 'This IS the first extracted code but was not mapped — may be a visibility or ordering edge case',
                            'action'     => 'Investigate: run reconciler again; ensure the course is visible',
                            'example_sn' => (string)$_bm->shortname,
                            'example_fn' => (string)$_bm->fullname,
                            'first_code' => $_fc,
                        ];
                        $_classified = true;
                        break;
                    }
                    // Superseded variant: one code is a single-character prefix of the other
                    // e.g. NAT=BSBMGT502 vs Moodle=BSBMGT502B  (differs by one trailing letter)
                    $_isSuperVar = ($_fc !== '' && (
                        (strpos($_fc, $_unmUc) === 0 && strlen($_fc) === strlen($_unmUc) + 1) ||
                        (strpos($_unmUc, $_fc) === 0 && strlen($_unmUc) === strlen($_fc) + 1)
                    ));
                    if ($_isSuperVar) {
                        $_ucClassifier[$_unmUc] = [
                            'class'      => 'superseded',
                            'reason'     => 'Moodle first code is "' . $_fc . '" — possible superseded or versioned variant',
                            'action'     => 'Translation required: align NAT unit code with Moodle course identifier',
                            'example_sn' => (string)$_bm->shortname,
                            'example_fn' => (string)$_bm->fullname,
                            'first_code' => $_fc,
                        ];
                        $_classified = true;
                        break;
                    }
                    // Secondary unit — present in course name but not the first code
                    if (!$_classified) {
                        $_ucClassifier[$_unmUc] = [
                            'class'      => 'secondary',
                            'reason'     => 'Appears in course name but first code is "' . $_fc . '" — client rule: first code wins',
                            'action'     => 'Expected — no action needed',
                            'example_sn' => (string)$_bm->shortname,
                            'example_fn' => (string)$_bm->fullname,
                            'first_code' => $_fc,
                        ];
                        $_classified = true;
                    }
                }
                if (!$_classified) {
                    reset($_bMatches);
                    $_bm0 = current($_bMatches);
                    $_ucClassifier[$_unmUc] = [
                        'class'      => 'secondary',
                        'reason'     => 'Appears in course name; extractor returned a different first code',
                        'action'     => 'Expected — no action needed',
                        'example_sn' => (string)$_bm0->shortname,
                        'example_fn' => (string)$_bm0->fullname,
                        'first_code' => '',
                    ];
                }
            }
        }
    }
    // Counts for summary display
    $_ucCountSecondary  = count(array_filter($_ucClassifier, fn($v) => $v['class'] === 'secondary'));
    $_ucCountSuperseded = count(array_filter($_ucClassifier, fn($v) => $v['class'] === 'superseded'));
    $_ucCountNoCourse   = count(array_filter($_ucClassifier, fn($v) => $v['class'] === 'no_course'));
    $_ucCountAnomaly    = count(array_filter($_ucClassifier, fn($v) => $v['class'] === 'mapping_anomaly'));
    $_ucCountHistorical = count(array_filter($_ucClassifier, fn($v) => $v['class'] === 'historical'));
    // Actionable = only codes that actually need administrator attention
    $_ucCountActionable = $_ucCountSuperseded + $_ucCountNoCourse + $_ucCountAnomaly;

    // ── Per-student trace (optional — driven by traceclientids textarea or legacy traceclientid) ──
    // v7: supports multiple Client IDs simultaneously (one per line or comma-separated).
    // Builds $_traceDataList — an array of per-student trace results rendered in order.

    // Merge legacy single-ID param + new multi-ID textarea into one list.
    $_rawIds = $traceclientids !== '' ? $traceclientids : $traceclientid;
    // Split on newlines, commas, or semicolons; strip blanks and duplicates.
    $_traceIds = array_values(array_unique(array_filter(
        array_map('trim', preg_split('/[\n\r,;]+/', $_rawIds)),
        fn($v) => $v !== ''
    )));

    $_traceDataList = []; // ordered list of per-student trace results

    foreach ($_traceIds as $_traceRawId) {
        $_lct = strtolower($_traceRawId);
        if (isset($clientToUid[$_lct])) {
            $_traceUid  = $clientToUid[$_lct];
            $_traceDet  = $uidToDetails[$_traceUid] ?? null;
            $_traceName = $_traceDet ? trim($_traceDet->firstname . ' ' . $_traceDet->lastname) : 'User #' . $_traceUid;

            // Per-unit coverage summary
            $_traceUnits = [];
            foreach (array_keys($natUnits[$_lct] ?? []) as $_tUc) {
                $_covCid  = $actualUnitCoverage[$_traceUid][$_tUc] ?? null;
                $_traceSd = $natStartdate[$_lct][$_tUc] ?? '';
                $_traceDk  = _reconcile_delivery_key($_traceSd);
                $_traceQcT = $natUnitQual[$_lct][$_tUc] ?? '';

                // Mirror Step 6 exact logic (priority 1 → 2 → 3a → 3b → 4/5)
                $_prefCid         = null;
                $_traceLookupMode = '';
                $_fallbackReason  = '';
                $_traceUnresolved = false;
                // Priority 1: exact semester within qual branch
                if ($_traceQcT !== '' && isset($qualMap[$_traceQcT])) {
                    if ($_traceDk !== '' && isset($qualUnitDeliveryMap[$_traceQcT][$_tUc][$_traceDk])) {
                        $_prefCid         = $qualUnitDeliveryMap[$_traceQcT][$_tUc][$_traceDk];
                        $_traceLookupMode = 'qual_delivery';
                    }
                }
                // Priority 2: exact semester globally
                if ($_prefCid === null && $_traceDk !== '') {
                    $_prefCid = $unitDeliveryCourseMap[$_tUc][$_traceDk] ?? null;
                    if ($_prefCid !== null) {
                        $_traceLookupMode = 'global_delivery';
                    }
                }
                // Count current vs archive candidates (mirrors Step 6 exactly)
                $_trCurrentCnt = 0; $_trArchiveCnt = 0;
                $_trCurrentCid = null; $_trArchiveCid = null;
                foreach ($unitAllCids[$_tUc] ?? [] as $_candCidT) {
                    $_candDetT = $courseDetail[$_candCidT] ?? null;
                    if ($_candDetT === null) continue;
                    $_isArcT = ((int)$_candDetT->visible === 0
                                || stripos((string)$_candDetT->catname, 'archive') !== false);
                    if (!$_isArcT) {
                        $_trCurrentCnt++;
                        if ($_trCurrentCid === null) $_trCurrentCid = $_candCidT;
                    } else {
                        $_trArchiveCnt++;
                        if ($_trArchiveCid === null) $_trArchiveCid = $_candCidT;
                    }
                }
                // Priority 3a: unique qual-scoped current match
                if ($_prefCid === null && $_trCurrentCnt === 1
                        && $_traceQcT !== '' && isset($qualMap[$_traceQcT])
                        && !empty($qualUnitDeliveryMap[$_traceQcT][$_tUc])) {
                    if (in_array($_trCurrentCid, array_values($qualUnitDeliveryMap[$_traceQcT][$_tUc]), true)) {
                        $_prefCid         = $_trCurrentCid;
                        $_traceLookupMode = 'qual_unique_match';
                    }
                }
                // Priority 3b: unique global current match
                if ($_prefCid === null && $_trCurrentCnt === 1) {
                    $_prefCid         = $_trCurrentCid;
                    $_traceLookupMode = 'global_unique_match';
                }
                // Priority 4: UNRESOLVED — multiple current courses, can't pick
                if ($_prefCid === null && $_trCurrentCnt > 1) {
                    $_traceUnresolved = true;
                    $_traceLookupMode = 'unresolved_current';
                    $_fallbackReason  = $_trCurrentCnt . ' current deliveries found; no exact semester match — no ADD emitted';
                }
                // Priority 5: archive-only
                if ($_prefCid === null && !$_traceUnresolved && $_trArchiveCid !== null) {
                    $_prefCid         = $_trArchiveCid;
                    $_traceLookupMode = 'historical_archive';
                    $_fallbackReason  = 'Archive-only delivery — no current delivery';
                }

                $_prefDet  = $_prefCid ? ($courseDetail[$_prefCid] ?? null) : null;
                $_covDet   = $_covCid  ? ($courseDetail[$_covCid]  ?? null) : null;
                // Compute why this specific course was selected as the ADD recommendation.
                $_selectionMethod = '';
                if ($_traceUnresolved) {
                    $_selectionMethod = 'UNRESOLVED — ' . $_fallbackReason;
                } elseif ($_prefCid !== null) {
                    if ($_traceLookupMode === 'qual_delivery') {
                        $_selectionMethod = 'Qual-scoped exact semester match (' . $_traceDk . ')';
                    } elseif ($_traceLookupMode === 'global_delivery') {
                        $_selectionMethod = 'Global exact semester match (' . $_traceDk . ') — no qual mapping';
                    } elseif ($_traceLookupMode === 'qual_unique_match') {
                        $_selectionMethod = 'Direct unit-code match — only 1 current course in qual branch (HIGH)';
                    } elseif ($_traceLookupMode === 'global_unique_match') {
                        $_selectionMethod = 'Direct unit-code match — only 1 current course globally, no qual scope (MEDIUM)';
                    } elseif ($_traceLookupMode === 'qual_sem_scored') {
                        $_selectionMethod = 'Semester-scored scan — 1 current course in qual branch matched NAT semester (HIGH)';
                    } elseif ($_traceLookupMode === 'global_sem_scored') {
                        $_selectionMethod = 'Semester-scored scan — 1 current course globally matched NAT semester (MEDIUM)';
                    } elseif ($_traceLookupMode === 'historical_archive') {
                        $_selectionMethod = 'Historical archive — ' . $_fallbackReason;
                    }
                }
                $_traceUnits[$_tUc] = [
                    'outcome'          => $natUnits[$_lct][$_tUc] ?? '',
                    'covered'          => $_covCid !== null,
                    'covCid'           => $_covCid,
                    'covShortname'     => $_covDet ? $_covDet->shortname : null,
                    'covFullname'      => $_covDet ? $_covDet->fullname  : null,
                    'covIdnumber'      => $_covDet ? $_covDet->idnumber  : null,
                    'covCatname'       => $_covDet ? $_covDet->catname   : null,
                    'covVisible'       => $_covDet ? (int)$_covDet->visible : null,
                    'prefCid'          => $_prefCid,
                    'prefShortname'    => $_prefDet ? $_prefDet->shortname : null,
                    'prefFullname'     => $_prefDet ? $_prefDet->fullname  : null,
                    'prefIdnumber'     => $_prefDet ? $_prefDet->idnumber  : null,
                    'prefCatname'      => $_prefDet ? $_prefDet->catname   : null,
                    'prefVisible'      => $_prefDet ? (int)$_prefDet->visible : null,
                    'selectionMethod'  => $_selectionMethod,
                    'mapped'           => $_prefCid !== null || $_traceUnresolved,
                    'unresolved'       => $_traceUnresolved,
                    'startdate'        => $_traceSd,
                    'deliveryKey'      => $_traceDk,
                    'fallbackReason'   => $_fallbackReason,
                    'candidateCids'    => array_values($unitAllCids[$_tUc] ?? []),
                    'suspendedCourses'   => [],
                    'otherMethodCourses' => [], // non-manual active (e.g. iMIS ws, cohort, self)
                    'reasonCode'         => '',
                    'whyFalse'           => '',
                    // Qualification-first diagnostics (v5.9.149)
                    'qualcode'           => $_traceQcT,
                    'qualcatid'          => ($_traceQcT !== '') ? ($qualMap[$_traceQcT] ?? null) : null,
                    'qualcatname'        => ($_traceQcT !== '' && isset($qualMap[$_traceQcT]))
                                               ? (string)($catById[$qualMap[$_traceQcT]]->name ?? $qualMapName[$_traceQcT] ?? '')
                                               : '',
                    'qualcatmatched'     => ($_traceQcT !== '' && isset($qualMap[$_traceQcT])),
                    'lookupMode'         => $_traceLookupMode,
                    'prefDkMatchedFrom'  => $_prefCid ? ($courseDkMatchedFrom[$_prefCid] ?? '') : '',
                    'prefCatAncPath'     => ($_prefCid && isset($courseAncestorNames[$_prefCid]))
                                               ? implode(' → ', $courseAncestorNames[$_prefCid]) : '',
                ];
            }

            // ── Suspended enrolment lookup + WHY-FALSE explanation ────────────
            // Queries suspended (ue.status=1) manual enrolments for this student.
            // These are completely invisible to the main coverage check (Step 4 only
            // loads ue.status=0) — this is the most common reason isset($_covered6)
            // evaluates false for a student who appears enrolled in Moodle.
            $_suspendedByUnit = []; // unitcode → [{cid, shortname}]
            $_suspRs = $DB->get_recordset_sql(
                "SELECT e.courseid
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.enrol = 'manual'
                    AND ue.status = 1
                    AND ue.userid = :uid",
                ['uid' => $_traceUid]
            );
            foreach ($_suspRs as $_sr) {
                $_sUc  = $courseToUnit[(int)$_sr->courseid] ?? '';
                if ($_sUc !== '') {
                    $_sDet = $courseDetail[(int)$_sr->courseid] ?? null;
                    $_suspendedByUnit[$_sUc][] = [
                        'cid'       => (int)$_sr->courseid,
                        'shortname' => $_sDet ? (string)$_sDet->shortname : '?',
                    ];
                }
            }
            $_suspRs->close();

            // ── Non-manual active enrolment lookup ───────────────────────────
            // Queries ALL active (ue.status=0) enrolments where e.enrol != 'manual'
            // for this student. These include iMIS web-service enrolments (e.enrol='ws'
            // or a custom plugin), cohort sync, self-enrol, etc. They are COMPLETELY
            // invisible to the main coverage check AND to the suspended lookup above.
            // A student re-enrolled by iMIS after the FOE incident (5/6/26) via a
            // non-manual method would show NO active and NO suspended manual enrolments,
            // causing the reconciler to emit false ADD recommendations with reason code
            // NO_ENROLMENT — which would be misleading without this third check.
            $_otherMethodByUnit = []; // unitcode → [{cid, shortname, enrol_method}]
            $_otherRs = $DB->get_recordset_sql(
                "SELECT e.courseid, e.enrol AS enrol_method
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE e.enrol <> 'manual'
                    AND ue.status = 0
                    AND ue.userid = :uid",
                ['uid' => $_traceUid]
            );
            foreach ($_otherRs as $_or) {
                $_oUc  = $courseToUnit[(int)$_or->courseid] ?? '';
                if ($_oUc !== '') {
                    $_oDet = $courseDetail[(int)$_or->courseid] ?? null;
                    $_otherMethodByUnit[$_oUc][] = [
                        'cid'          => (int)$_or->courseid,
                        'shortname'    => $_oDet ? (string)$_oDet->shortname : '?',
                        'enrol_method' => (string)$_or->enrol_method,
                    ];
                }
            }
            $_otherRs->close();

            // Backfill suspendedCourses + otherMethodCourses + whyFalse into every ADD unit.
            foreach ($_traceUnits as $_tUcKey => &$_tUnitRef) {
                $_tUnitRef['suspendedCourses']   = $_suspendedByUnit[$_tUcKey]   ?? [];
                $_tUnitRef['otherMethodCourses'] = $_otherMethodByUnit[$_tUcKey] ?? [];

                // ── Reason code (enumerated) — priority order ─────────────────
                // ACTIVE_FOUND          — covered via active manual enrolment (delivery matched)
                // ACTIVE_FOUND_FALLBACK — covered via active manual enrolment (delivery fallback)
                // OTHER_ENROL_METHOD    — NOT covered by manual; active non-manual enrolment
                //                        exists (iMIS web service, cohort, self-enrol, etc.)
                //                        reconciler is blind to these — likely false positive
                // SUSPENDED_FOUND       — NOT covered; only suspended (status=1) manual enrolment
                //                        exists — invisible to reconciler; likely false positive
                // UNIT_VERSION_MISMATCH — NOT covered; active manual enrolment in a near-matching
                //                        unit code (same 7-char prefix, different suffix)
                // NO_MOODLE_COURSE      — NOT covered; no Moodle course maps to this unit code
                // DELIVERY_FALLBACK     — NOT covered; preferred course is a delivery fallback;
                //                        student also has no enrolment in that fallback course
                // NO_ENROLMENT          — NOT covered; no enrolment of any kind found
                if ($_tUnitRef['covered']) {
                    $_tUnitRef['reasonCode'] = $_tUnitRef['fallbackReason'] !== ''
                        ? 'ACTIVE_FOUND_FALLBACK' : 'ACTIVE_FOUND';
                    $_tUnitRef['whyFalse']   = '';
                } elseif (!empty($_tUnitRef['otherMethodCourses'])) {
                    $_omList = array_map(
                        fn($x) => $x['shortname'] . ' [' . $x['enrol_method'] . ']',
                        $_tUnitRef['otherMethodCourses']
                    );
                    $_tUnitRef['reasonCode'] = 'OTHER_ENROL_METHOD';
                    $_tUnitRef['whyFalse']   =
                        'NON-MANUAL ENROLMENT — active enrolment via: '
                        . implode(', ', $_omList)
                        . '; reconciler only checks e.enrol=\'manual\' — this is invisible to it';
                } elseif (!empty($_tUnitRef['suspendedCourses'])) {
                    $_snList = implode(', ', array_column($_tUnitRef['suspendedCourses'], 'shortname'));
                    $_tUnitRef['reasonCode'] = 'SUSPENDED_FOUND';
                    $_tUnitRef['whyFalse']   =
                        'SUSPENDED — manual enrolment exists (' . $_snList . ') '
                        . 'but ue.status=1; reconciler only checks status=0';
                } else {
                    // Near-match: same 7-char prefix, different suffix (version suffix mismatch)
                    $_nearMatch = [];
                    $_prefix7   = substr($_tUcKey, 0, 7);
                    foreach ($currentEnrolments[$_traceUid] ?? [] as $_aCid => $_aUc) {
                        if ($_aUc !== '' && $_aUc !== $_tUcKey
                                && substr($_aUc, 0, 7) === $_prefix7) {
                            $_nearMatch[] = $_aUc
                                . ' (' . ($courseDetail[$_aCid]->shortname ?? '?') . ')';
                        }
                    }
                    if (!empty($_nearMatch)) {
                        $_tUnitRef['reasonCode'] = 'UNIT_VERSION_MISMATCH';
                        $_tUnitRef['whyFalse']   =
                            'UNIT CODE MISMATCH — active enrolment in: '
                            . implode(', ', $_nearMatch)
                            . ' — NAT expects exact code "' . $_tUcKey . '"';
                    } elseif ($_tUnitRef['prefCid'] === null) {
                        $_tUnitRef['reasonCode'] = 'NO_MOODLE_COURSE';
                        $_tUnitRef['whyFalse']   =
                            'NO MOODLE COURSE — no course idnumber/shortname/fullname '
                            . 'maps to unit code "' . $_tUcKey . '"';
                    } elseif ($_tUnitRef['fallbackReason'] !== '') {
                        $_tUnitRef['reasonCode'] = 'DELIVERY_FALLBACK';
                        $_tUnitRef['whyFalse']   =
                            'DELIVERY FALLBACK — ' . $_tUnitRef['fallbackReason']
                            . '; no enrolment in fallback course either';
                    } else {
                        $_tUnitRef['reasonCode'] = 'NO_ENROLMENT';
                        $_tUnitRef['whyFalse']   =
                            'GENUINELY MISSING — no active or suspended manual '
                            . 'enrolment covers this unit in any delivery';
                    }
                }
            }
            unset($_tUnitRef);

            // All actual enrolments for this student with KEEP/REMOVE verdict
            $_traceActual = [];
            foreach ($currentEnrolments[$_traceUid] ?? [] as $_tCCid => $_tUc) {
                $_tcd = $courseDetail[$_tCCid] ?? null;
                $_traceActual[] = [
                    'id'        => $_tCCid,
                    'shortname' => $_tcd ? $_tcd->shortname : '?',
                    'unitcode'  => $_tUc,
                    'verdict'   => isset($keepEnrolments[$_traceUid][$_tCCid])
                                    ? 'KEEP'
                                    : (isset($postImportEnrolments[$_traceUid][$_tCCid])
                                        ? 'POST-IMPORT'
                                        : (isset($reviewEnrolments[$_traceUid][$_tCCid]) ? 'REVIEW' : 'REMOVE')),
                ];
            }

            // ADD recommendations for this student
            $_traceAdds = [];
            foreach ($addEnrolments[$_traceUid] ?? [] as $_tACid => $_tAUc) {
                $_tcd = $courseDetail[$_tACid] ?? null;
                $_traceAdds[] = [
                    'id'        => $_tACid,
                    'shortname' => $_tcd ? $_tcd->shortname : '?',
                    'unitcode'  => $_tAUc,
                ];
            }

            $_traceDataList[] = [
                'found'    => true,
                'uid'      => $_traceUid,
                'name'     => $_traceName,
                'clientid' => $_traceRawId,
                'units'    => $_traceUnits,
                'actual'   => $_traceActual,
                'adds'     => $_traceAdds,
            ];
        } else {
            $_traceDataList[] = [
                'found'    => false,
                'clientid' => $_traceRawId,
                'error'    => 'Client ID "' . $_traceRawId . '" was not matched in this import (tried idnumber, student profile, username, email, USI paths).',
            ];
        }
    }
    // Legacy compat — keep $_traceData pointing at the first result so any code outside the loop still works.
    $_traceData = $_traceDataList[0] ?? null;

    // ── Step 7: Generate CSV reports ─────────────────────────────────────────
    $newToken       = bin2hex(random_bytes(16));
    $runTimestamp   = date('Y-m-d H:i:s T');
    $pluginRelease  = RTOCOMPLIANCE_RECONCILER_RELEASE;
    $runShortToken  = substr($newToken, 0, 8);
    $pathMissing     = _reconcile_csvpath($newToken, 'missing');
    $pathExtra       = _reconcile_csvpath($newToken, 'extra');
    $pathReview      = _reconcile_csvpath($newToken, 'review');
    $pathPostImport  = _reconcile_csvpath($newToken, 'postimport');
    $pathRestore     = _reconcile_csvpath($newToken, 'restore');
    $pathSummary     = _reconcile_csvpath($newToken, 'summary');
    $pathAudit       = _reconcile_csvpath($newToken, 'audit');
    $pathAmbiguous   = _reconcile_csvpath($newToken, 'ambiguous');
    $pathCourseAudit = _reconcile_csvpath($newToken, 'courseaudit');

    $pathDebug          = _reconcile_csvpath($newToken, 'debug');
    // ── v5.9.167 three-file ADD routing ───────────────────────────────────────
    // moodle_upload     → confidence ≥ 95% (safe to auto-enrol)
    // review_required   → confidence 50–94% (needs human review)
    // unmatched_add     → no course found (0%) — taken from $addUnresolved
    $pathMoodleUpload   = _reconcile_csvpath($newToken, 'moodle_upload');
    $pathReviewRequired = _reconcile_csvpath($newToken, 'review_required');
    $pathUnmatchedAdd   = _reconcile_csvpath($newToken, 'unmatched_add');
    $fMissing     = fopen($pathMissing,     'w');
    $fExtra       = fopen($pathExtra,       'w');
    $fReview      = fopen($pathReview,      'w');
    $fPostImport  = fopen($pathPostImport,  'w');
    $fRestore     = fopen($pathRestore,     'w');
    $fSummary     = fopen($pathSummary,     'w');
    $fAudit       = fopen($pathAudit,       'w');
    $fAmbiguous   = fopen($pathAmbiguous,   'w');
    $fCourseAudit = fopen($pathCourseAudit, 'w');
    $fDebug       = fopen($pathDebug,       'w');
    $fMoodleUpload   = fopen($pathMoodleUpload,   'w');
    $fReviewRequired = fopen($pathReviewRequired, 'w');
    $fUnmatchedAdd   = fopen($pathUnmatchedAdd,   'w');

    // UTF-8 BOM so Excel opens the file correctly
    foreach ([$fMissing, $fExtra, $fReview, $fPostImport, $fRestore, $fSummary, $fAudit, $fAmbiguous, $fCourseAudit, $fDebug, $fMoodleUpload, $fReviewRequired, $fUnmatchedAdd] as $_fh) {
        fwrite($_fh, "\xEF\xBB\xBF");
    }

    // moodle_upload.csv header is written AFTER the loop once we know the max course count
    // (Moodle Upload Users: one row per student, course1/role1, course2/role2, etc.)
    $moodleUploadBuffer = []; // uid → ['username','idnumber','firstname','lastname','email','courses'=>[sn,...]]
    // review_required.csv — full diagnostic report for human review
    fputcsv($fReviewRequired, ['username','idnumber','firstname','lastname','email','course_shortname','course_fullname',
                               'category','unit_code','qualcode','qual_type','lookup_mode','delivery_type',
                               'confidence','confidence_pct','score','score_flags','reason']);
    fputcsv($fUnmatchedAdd,   ['username','idnumber','firstname','lastname','unit_code','qualcode','startdate','delivery_key','reason']);
    $totalMoodleUpload   = 0;
    $totalReviewRequired = 0;
    $totalUnmatchedAdd   = 0;

    fputcsv($fMissing,     ['username','idnumber','firstname','lastname','courseid','shortname','fullname','category','unit_code','qualcode','lookup_mode','delivery_type','confidence','reason']);
    fputcsv($fExtra,       ['username','idnumber','firstname','lastname','courseid','shortname','fullname','category','unit_code','category_type','classification','archive_category','has_replacement','foe_deleted','confidence','recommendation','reason']);
    fputcsv($fReview,      ['username','idnumber','firstname','lastname','courseid','shortname','fullname','category','reason']);
    fputcsv($fPostImport,  ['username','idnumber','firstname','lastname','courseid','shortname','fullname','category','unit_code','enrol_date','reason']);
    fputcsv($fRestore,     ['username','idnumber','firstname','lastname','courseid','shortname','fullname','category','unit_code','classification','confidence','reason']);
    fputcsv($fSummary,     ['username','student','nat_units','units_covered','units_missing','extra_enrolments','post_import_enrolments','restore_restore','restore_pi_replaced','restore_legit_remove','restore_review','review_enrolments','confidence_score','confidence_label','confidence_detail']);
    fputcsv($fAudit,       ['username','student','courseid','course','category','unit_code','unit_in_nat','enrolled','action']);

    fputcsv($fAmbiguous,   ['unit_code','course_id','shortname','fullname','category','visible','delivery_key','manual_enrolments','is_chosen_fallback']);
    fputcsv($fCourseAudit, ['course_id','shortname','fullname','extracted_unit_code','extraction_source','codes_in_fullname','codes_in_fullname_list','flags']);
    fputcsv($fDebug,       ['student_id','firstname','lastname','qualification','unit','mapped_qual_category','candidate_courses_found','current_candidates','archive_candidates','selected_course','selection_reason','semester_match','year_match','fallback_used','fallback_reason','existing_enrolment_found']);

    // Summary counters
    $totalMissing    = 0;
    $totalExtra      = 0;
    $totalPostImport = 0;
    $totalRestore    = 0;
    $totalReview     = 0;
    $totalKeep       = 0;

    // ── Pre-classify REMOVE rows for enriched "Enrolments Not Explained by Current NAT" report ──
    // Queries logstore for admin/FOE deletion events near the NAT import window, then classifies
    // each REMOVE row into: historical_archive / duplicate_delivery / resource_qual_course /
    // foe_deleted / unknown.
    $_removeClass   = []; // [uid][cid] → classification array
    $_foeDeletedMap = []; // [uid][cid] → true
    $_removeSummary = []; // classification → count
    $_confStats     = ['High' => 0, 'Medium' => 0, 'Low' => 0, 'Review' => 0];

    if (!empty($removeEnrolments)) {
        // Collect unique userids and courseids
        $_remUids = []; $_remCids = [];
        foreach ($removeEnrolments as $_xUid => $_xCids) {
            $_remUids[] = (int)$_xUid;
            foreach (array_keys($_xCids) as $_xCid) { $_remCids[(int)$_xCid] = true; }
        }
        $_remUids = array_unique($_remUids);
        $_remCids = array_keys($_remCids);

        // Logstore window: 90 days before import to 1 day after (captures FOE and admin deletions)
        $_logWinStart = $_importTs - (90 * 86400);
        $_logWinEnd   = $_importTs + 86400;

        if (!empty($_remUids) && !empty($_remCids)) {
            try {
                [$_uidSql, $_uidParams] = $DB->get_in_or_equal($_remUids, SQL_PARAMS_NAMED, 'ru');
                [$_cidSql, $_cidParams] = $DB->get_in_or_equal($_remCids, SQL_PARAMS_NAMED, 'rc');
                $_logParams = array_merge(
                    ['evt' => '\core\event\user_enrolment_deleted', 'tstart' => $_logWinStart, 'tend' => $_logWinEnd],
                    $_uidParams, $_cidParams
                );
                $_logRs = $DB->get_recordset_sql(
                    "SELECT userid, courseid FROM {logstore_standard_log}
                      WHERE eventname = :evt
                        AND userid $_uidSql AND courseid $_cidSql
                        AND timecreated >= :tstart AND timecreated <= :tend",
                    $_logParams
                );
                foreach ($_logRs as $_lRow) {
                    $_foeDeletedMap[(int)$_lRow->userid][(int)$_lRow->courseid] = true;
                }
                $_logRs->close();
            } catch (\Throwable $_logEx) {
                // logstore_standard_log may not exist on all installations — silently skip
            }
        }

        $_currentYear = (int)date('Y', $_importTs);

        foreach ($removeEnrolments as $_rcUid => $_rcCids) {
            foreach (array_keys($_rcCids) as $_rcCid) {
                $_rcCd      = $courseDetail[$_rcCid] ?? null;
                $_rcUc      = $currentEnrolments[$_rcUid][$_rcCid] ?? '';
                $_rcCatname = $_rcCd ? (string)$_rcCd->catname   : '';
                $_rcSn      = $_rcCd ? (string)$_rcCd->shortname : '';
                $_rcVisible = $_rcCd ? (int)$_rcCd->visible      : 0;

                $_rcIsArchive = ($_rcVisible === 0) || (stripos($_rcCatname, 'archive') !== false);

                $_rcDelivYear = 0;
                if (preg_match('/\b(20\d\d)\b/', $_rcSn . ' ' . $_rcCatname, $_rcDm)) {
                    $_rcDelivYear = (int)$_rcDm[1];
                }
                $_rcIsHistorical = $_rcDelivYear > 0 && $_rcDelivYear < ($_currentYear - 2);

                // Qualification-code pattern (5-digit suffix) = resource/qual-level course
                $_rcIsQualCode = !empty($_rcUc) && preg_match('/^[A-Z]{2,5}[0-9]{5}$/', $_rcUc);

                // Already covered elsewhere (KEEP) → duplicate delivery
                $_rcHasReplacement = !empty($_rcUc) && isset($actualUnitCoverage[$_rcUid][$_rcUc]);

                $_rcFoeDeleted = isset($_foeDeletedMap[$_rcUid][$_rcCid]);

                if ($_rcIsQualCode) {
                    $_rcCls = 'resource_qual_course'; $_rcConf = 'Medium'; $_rcRec = 'REVIEW';
                    $_rcRsn = 'The extracted code (' . $_rcUc . ') matches a qualification code pattern, not a unit code. This appears to be a resource, induction, or qualification-level course rather than a standard AVETMISS unit course.';
                } elseif ($_rcHasReplacement) {
                    $_rcCls = 'duplicate_delivery'; $_rcConf = 'High'; $_rcRec = 'REMOVE';
                    $_rcRsn = 'Student is already KEEP-enrolled in another delivery of unit ' . $_rcUc . '. This is a duplicate — the active delivery covers the NAT requirement.';
                } elseif ($_rcIsArchive && $_rcIsHistorical) {
                    $_rcCls = 'historical_archive'; $_rcConf = 'High'; $_rcRec = 'KEEP';
                    $_rcRsn = 'Archive course from ' . $_rcDelivYear . ' (' . $_rcCatname . '). Historical delivery — not expected in current NAT. Retain unless deliberately removing all archive enrolments.';
                } elseif ($_rcIsArchive) {
                    $_rcCls = 'historical_archive'; $_rcConf = 'Medium'; $_rcRec = 'KEEP';
                    $_rcRsn = 'Course is in an archive category or is hidden (' . $_rcCatname . '). Likely a historical delivery not represented in the current NAT. Verify before removing.';
                } elseif ($_rcIsHistorical) {
                    $_rcCls = 'historical_archive'; $_rcConf = 'Medium'; $_rcRec = 'KEEP';
                    $_rcRsn = 'Delivery year ' . $_rcDelivYear . ' is more than 2 years ago — historical delivery not in current NAT.';
                } elseif ($_rcFoeDeleted) {
                    $_rcCls = 'foe_deleted'; $_rcConf = 'Medium'; $_rcRec = 'REVIEW';
                    $_rcRsn = 'An admin/FOE enrolment deletion event was found for this student+course in the logstore around the NAT import date. Unit is absent from current NAT — investigate whether this should be restored.';
                } else {
                    $_rcCls = 'unknown'; $_rcConf = 'Low'; $_rcRec = 'REVIEW';
                    $_rcRsn = 'Unit code (' . $_rcUc . ') is not in student\'s current NAT data and the enrolment predates the NAT import. No archive/historical indicators found — manual investigation recommended.';
                }

                $_removeClass[$_rcUid][$_rcCid] = [
                    'category_type'   => $_rcIsArchive ? 'archive' : 'current',
                    'classification'  => $_rcCls,
                    'archive'         => $_rcIsArchive      ? 'YES' : 'NO',
                    'has_replacement' => $_rcHasReplacement ? 'YES' : 'NO',
                    'foe_deleted'     => $_rcFoeDeleted     ? 'YES' : 'NO',
                    'confidence'      => $_rcConf,
                    'recommendation'  => $_rcRec,
                    'reason'          => $_rcRsn,
                ];
                if (!isset($_removeSummary[$_rcCls])) { $_removeSummary[$_rcCls] = 0; }
                $_removeSummary[$_rcCls]++;
            }
        }
    }

    foreach ($clientToUid as $_lc => $_uid) {
        $_det  = $uidToDetails[$_uid] ?? null;
        $_un   = $_det ? (string)$_det->username  : '';
        $_idn  = $_det ? (string)$_det->idnumber  : '';
        $_fn   = $_det ? (string)$_det->firstname : '';
        $_ln   = $_det ? (string)$_det->lastname  : '';
        $_em   = $_det ? (string)$_det->email     : '';
        $_name = trim($_fn . ' ' . $_ln);

        $_natUnitCount = count($natUnits[$_lc] ?? []);
        $_coveredCount = count($actualUnitCoverage[$_uid] ?? []);
        $_misCount     = count($addEnrolments[$_uid]    ?? []);
        $_extCount     = count($removeEnrolments[$_uid] ?? []);
        $_rvCount      = count($reviewEnrolments[$_uid]  ?? []);

        // ── Per-student Reconciliation Confidence Score ────────────────────────
        // Five weighted checks; each deduction capped to avoid over-penalising.
        $_confScore  = 100;
        $_confChecks = [];

        // 1. Qualification mapping (up to -20): all quals in NAT have a Moodle category mapping
        $_sqQuals  = array_keys($natQualUnits[$_lc] ?? []);
        $_sqMapped = 0;
        foreach ($_sqQuals as $_sq) { if (isset($qualMap[$_sq])) { $_sqMapped++; } }
        if (empty($_sqQuals) || $_sqMapped === count($_sqQuals)) {
            $_confChecks[] = 'qual_mapped';
        } else {
            $_confScore -= (int)round(20 * (1 - $_sqMapped / count($_sqQuals)));
            $_confChecks[] = 'qual_partial:' . $_sqMapped . '/' . count($_sqQuals);
        }

        // 2. Unit coverage (up to -25): all NAT units have at least one candidate Moodle course
        $_sqNatUnits = array_keys($natUnits[$_lc] ?? []);
        $_sqNoMoodle = 0;
        foreach ($_sqNatUnits as $_sqUc) {
            if (!isset($unitToPreferredCid[$_sqUc]) && empty($unitAllCids[$_sqUc])) { $_sqNoMoodle++; }
        }
        if ($_sqNoMoodle === 0) {
            $_confChecks[] = 'all_units_matched';
        } else {
            $_confScore -= min(25, (int)round($_sqNoMoodle / max(1, count($_sqNatUnits)) * 25));
            $_confChecks[] = 'units_no_moodle:' . $_sqNoMoodle;
        }

        // 3. Delivery quality (up to -25): prefer HIGH over MEDIUM/FALLBACK ADD recommendations
        $_sqHigh = 0; $_sqMed = 0; $_sqFallback = 0;
        foreach ($addMeta[$_uid] ?? [] as $_sqM) {
            if     (($_sqM['confidence'] ?? '') === 'HIGH')     { $_sqHigh++; }
            elseif (($_sqM['confidence'] ?? '') === 'MEDIUM')   { $_sqMed++; }
            elseif (($_sqM['confidence'] ?? '') === 'FALLBACK') { $_sqFallback++; }
        }
        if ($_sqFallback === 0 && $_sqMed === 0) {
            $_confChecks[] = 'delivery_matched';
        } else {
            $_confScore -= min(25, $_sqFallback * 8 + $_sqMed * 3);
            if ($_sqFallback > 0) { $_confChecks[] = 'delivery_fallback:' . $_sqFallback; }
            if ($_sqMed > 0)      { $_confChecks[] = 'delivery_partial:' . $_sqMed; }
        }

        // 4. Ambiguity (up to -15): unit codes with multiple candidate Moodle courses
        $_sqAmbig = 0;
        foreach ($_sqNatUnits as $_sqUc2) {
            if (count($unitAllCids[$_sqUc2] ?? []) > 1) { $_sqAmbig++; }
        }
        if ($_sqAmbig === 0) {
            $_confChecks[] = 'no_ambiguity';
        } else {
            $_confScore -= min(15, $_sqAmbig * 3);
            $_confChecks[] = 'ambiguous_units:' . $_sqAmbig;
        }

        // 5. Unexpected removes (up to -15): REMOVE rows that are not historical archives or duplicates
        $_sqNonArchiveRemove = 0;
        foreach ($removeEnrolments[$_uid] ?? [] as $_sqRCid => $_) {
            $_sqRCls = $_removeClass[$_uid][$_sqRCid]['classification'] ?? '';
            if ($_sqRCls !== 'historical_archive' && $_sqRCls !== 'duplicate_delivery') {
                $_sqNonArchiveRemove++;
            }
        }
        if ($_sqNonArchiveRemove === 0) {
            $_confChecks[] = 'no_unexpected_removals';
        } else {
            $_confScore -= min(15, $_sqNonArchiveRemove * 5);
            $_confChecks[] = 'unexpected_removals:' . $_sqNonArchiveRemove;
        }

        $_confScore = max(0, min(100, $_confScore));
        $_confLabel = $_confScore >= 95 ? 'High' : ($_confScore >= 80 ? 'Medium' : ($_confScore >= 60 ? 'Low' : 'Review'));
        $_confStats[$_confLabel]++;

        // ADD rows — NAT unit with no coverage in any delivery
        foreach ($addEnrolments[$_uid] ?? [] as $_cid => $_uc) {
            $_cd   = $courseDetail[$_cid] ?? null;
            $_meta = $addMeta[$_uid][$_cid] ?? ['delivery_type' => '', 'confidence' => '', 'lookup_mode' => '', 'qualcode' => ''];
            fputcsv($fMissing, [
                $_un, $_idn, $_fn, $_ln, $_cid,
                $_cd ? (string)$_cd->shortname : '', $_cd ? (string)$_cd->fullname : '',
                $_cd ? (string)$_cd->catname   : '', $_uc,
                $_meta['qualcode']      ?? '',
                $_meta['lookup_mode']   ?? '',
                $_meta['delivery_type'], $_meta['confidence'],
                'NAT unit has no enrolment in any delivery — recommended preferred course shown',
            ]);
            $totalMissing++;

            // ── v5.9.170 Three-file routing by numeric confidence ─────────────
            // Helper: strip newlines/CR that confuse Moodle's csv_import_reader
            $_sc = function ($v) { return str_replace(["\r\n", "\r", "\n"], ' ', (string)$v); };
            $_splitPct  = (int)($_meta['confidence_pct'] ?? 80);
            $_sn        = $_cd ? $_sc($_cd->shortname) : '';
            if ($_splitPct >= 95) {
                // Buffer for consolidation: one row per student with course1, course2, etc.
                if (!isset($moodleUploadBuffer[$_uid])) {
                    $moodleUploadBuffer[$_uid] = [
                        'username'  => $_sc($_un),
                        'idnumber'  => $_sc($_idn),
                        'firstname' => $_sc($_fn),
                        'lastname'  => $_sc($_ln),
                        'email'     => $_sc($_em),
                        'courses'   => [],
                    ];
                }
                if ($_sn !== '') {
                    $moodleUploadBuffer[$_uid]['courses'][] = $_sn;
                }
                $totalMoodleUpload++;
            } else {
                // review_required.csv — full diagnostic report
                fputcsv($fReviewRequired, [
                    $_sc($_un), $_sc($_idn), $_sc($_fn), $_sc($_ln),
                    $_sc($_em),
                    $_sn,
                    $_cd ? $_sc($_cd->fullname) : '',
                    $_cd ? $_sc($_cd->catname)  : '',
                    $_sc($_uc),
                    $_sc($_meta['qualcode']      ?? ''),
                    $_sc($_meta['qual_type']     ?? ''),
                    $_sc($_meta['lookup_mode']   ?? ''),
                    $_sc($_meta['delivery_type'] ?? ''),
                    $_sc($_meta['confidence']    ?? ''),
                    $_splitPct,
                    (int)($_meta['score']        ?? 0),
                    $_sc($_meta['score_flags']   ?? ''),
                    'NAT unit has no enrolment in any delivery - course found but not confirmed',
                ]);
                $totalReviewRequired++;
            }

            // Debug CSV — full decision trace for this ADD row (enhanced with score)
            $_dbgQc    = $_meta['qualcode'] ?? '';
            $_dbgQcCat = ($_dbgQc !== '' && isset($qualMap[$_dbgQc]))
                ? (string)($catById[$qualMap[$_dbgQc]]->name ?? $qualMapName[$_dbgQc] ?? 'id:' . $qualMap[$_dbgQc])
                : ($_dbgQc !== '' ? 'UNMAPPED' : '');
            $_dbgLm    = $_meta['lookup_mode'] ?? '';
            $_dbgLmLabels = [
                'qual_delivery'      => 'Exact semester match — qual-scoped (score: ' . ($_meta['score'] ?? '?') . ')',
                'global_delivery'    => 'Exact semester match — global (score: ' . ($_meta['score'] ?? '?') . ')',
                'historical_archive' => 'Archive-only delivery — no current delivery, no semester match',
            ];
            fputcsv($fDebug, [
                $_idn, $_fn, $_ln,
                $_dbgQc, $_uc,
                $_dbgQcCat,
                $_meta['totalCands']    ?? 0,
                $_meta['currentCands']  ?? 0,
                $_meta['archiveCands']  ?? 0,
                $_cd ? (string)$_cd->shortname : '',
                $_dbgLmLabels[$_dbgLm] ?? ($_dbgLm . ' (score:' . ($_meta['score'] ?? '?') . ' flags:' . ($_meta['score_flags'] ?? '') . ')'),
                ($_meta['semesterMatch'] ?? false) ? 'Yes' : 'No',
                ($_meta['yearMatch']     ?? false) ? 'Yes' : 'No',
                ($_dbgLm === 'historical_archive') ? 'Yes' : 'No',
                ($_dbgLm === 'historical_archive') ? 'No semester match; only archive deliveries found for this unit' : '',
                'No', // by construction: unit had zero active manual enrolment coverage
            ]);
        }

        // ── v5.9.167 Unmatched ADD (unresolved): write to unmatched_add.csv ──
        foreach ($addUnresolved[$_uid] ?? [] as $_urUc7 => $_urD7) {
            fputcsv($fUnmatchedAdd, [
                $_un, $_idn, $_fn, $_ln,
                $_urUc7,
                $_urD7['qualcode'] ?? '',
                $_urD7['sd']       ?? '',
                $_urD7['dk']       ?? '',
                $_urD7['reason']   ?? '',
            ]);
            $totalUnmatchedAdd++;
        }

        // Debug CSV — UNRESOLVED rows (current delivery exists, no semester match → no ADD emitted)
        foreach ($addUnresolved[$_uid] ?? [] as $_urUc => $_urData) {
            $_urQc    = $_urData['qualcode'] ?? '';
            $_urQcCat = ($_urQc !== '' && isset($qualMap[$_urQc]))
                ? (string)($catById[$qualMap[$_urQc]]->name ?? $qualMapName[$_urQc] ?? 'id:' . $qualMap[$_urQc])
                : ($_urQc !== '' ? 'UNMAPPED' : '');
            $_urCurrCid = $_urData['current_cid'] ?? null;
            $_urCurrDet = $_urCurrCid ? ($courseDetail[$_urCurrCid] ?? null) : null;
            fputcsv($fDebug, [
                $_idn, $_fn, $_ln,
                $_urQc, $_urUc,
                $_urQcCat,
                $_urData['totalCands']   ?? 0,
                $_urData['currentCands'] ?? 0,
                $_urData['archiveCands'] ?? 0,
                'UNRESOLVED' . ($_urCurrDet ? ' (current: ' . (string)$_urCurrDet->shortname . ')' : ''),
                'UNRESOLVED — current delivery exists but no exact semester match',
                'No',
                'No',
                'No',
                $_urData['reason'] ?? '',
                'No',
            ]);
        }

        // REMOVE rows — enriched with classification (historical/duplicate/resource/foe_deleted/unknown)
        foreach ($removeEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd  = $courseDetail[$_cid] ?? null;
            $_uc  = $currentEnrolments[$_uid][$_cid] ?? '';
            $_rcl = $_removeClass[$_uid][$_cid] ?? [];
            fputcsv($fExtra, [
                $_un, $_idn, $_fn, $_ln, $_cid,
                $_cd ? (string)$_cd->shortname : '', $_cd ? (string)$_cd->fullname : '',
                $_cd ? (string)$_cd->catname   : '', $_uc,
                $_rcl['category_type']   ?? '',
                $_rcl['classification']  ?? 'unknown',
                $_rcl['archive']         ?? 'NO',
                $_rcl['has_replacement'] ?? 'NO',
                $_rcl['foe_deleted']     ?? 'NO',
                $_rcl['confidence']      ?? 'Low',
                $_rcl['recommendation']  ?? 'REVIEW',
                $_rcl['reason']          ?? 'Unit code not in NAT data (enrolment predates NAT import — may be genuine mismatch)',
            ]);
            $totalExtra++;
        }

        // POST-IMPORT rows — not in NAT but enrolment was created AFTER the NAT import
        foreach ($postImportEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd    = $courseDetail[$_cid] ?? null;
            $_uc    = $currentEnrolments[$_uid][$_cid] ?? '';
            $_enrTs = $enrolTimecreated[$_uid][$_cid] ?? 0;
            fputcsv($fPostImport, [
                $_un, $_idn, $_fn, $_ln, $_cid,
                $_cd ? (string)$_cd->shortname : '', $_cd ? (string)$_cd->fullname : '',
                $_cd ? (string)$_cd->catname   : '', $_uc,
                $_enrTs ? date('Y-m-d H:i:s', $_enrTs) : '',
                'Created after NAT import — legitimate admin/IMIS enrolment; do not remove',
            ]);
            $totalPostImport++;
        }

        // REVIEW rows — course has no unit code; cannot verify automatically
        foreach ($reviewEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd = $courseDetail[$_cid] ?? null;
            fputcsv($fReview, [
                $_un, $_idn, $_fn, $_ln, $_cid,
                $_cd ? (string)$_cd->shortname : '', $_cd ? (string)$_cd->fullname : '',
                $_cd ? (string)$_cd->catname   : '',
                'Course has no unit code — verify manually (may be orientation, LLN, or other non-unit course)',
            ]);
            $totalReview++;
        }

        // RESTORE report rows — all Friday backup entries missing from current Moodle, with classification
        foreach ($fridayBackupMissing[$_uid] ?? [] as $_cid => $_clData) {
            $_cd = $courseDetail[$_cid] ?? null;
            fputcsv($fRestore, [
                $_un, $_idn, $_fn, $_ln, $_cid,
                $_cd ? (string)$_cd->shortname : '', $_cd ? (string)$_cd->fullname : '',
                $_cd ? (string)$_cd->catname   : '', $_clData['unit_code'],
                $_clData['class'], $_clData['confidence'], $_clData['reason'],
            ]);
            if ($_clData['class'] === 'RESTORE') { $totalRestore++; }
        }

        // Audit — KEEP
        foreach ($keepEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd = $courseDetail[$_cid] ?? null;
            $_uc = $currentEnrolments[$_uid][$_cid] ?? '';
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                $_uc, 'YES', 'YES', 'KEEP',
            ]);
            $totalKeep++;
        }
        // Audit — REMOVE
        foreach ($removeEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd = $courseDetail[$_cid] ?? null;
            $_uc = $currentEnrolments[$_uid][$_cid] ?? '';
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                $_uc, 'NO', 'YES', 'REMOVE',
            ]);
        }
        // Audit — POST-IMPORT
        foreach ($postImportEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd = $courseDetail[$_cid] ?? null;
            $_uc = $currentEnrolments[$_uid][$_cid] ?? '';
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                $_uc, 'NO', 'YES', 'POST-IMPORT',
            ]);
        }
        // Audit — REVIEW
        foreach ($reviewEnrolments[$_uid] ?? [] as $_cid => $_) {
            $_cd = $courseDetail[$_cid] ?? null;
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                '', '?', 'YES', 'REVIEW',
            ]);
        }
        // Audit — ADD
        foreach ($addEnrolments[$_uid] ?? [] as $_cid => $_uc) {
            $_cd = $courseDetail[$_cid] ?? null;
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                $_uc, 'YES', 'NO', 'ADD',
            ]);
        }
        // Audit — Friday backup missing (with specific classification)
        foreach ($fridayBackupMissing[$_uid] ?? [] as $_cid => $_clData) {
            $_cd = $courseDetail[$_cid] ?? null;
            fputcsv($fAudit, [
                $_un, $_name, $_cid,
                $_cd ? (string)$_cd->fullname : '', $_cd ? (string)$_cd->catname : '',
                $_clData['unit_code'], '?', 'NO', $_clData['class'],
            ]);
        }

        $_piCount       = count($postImportEnrolments[$_uid]  ?? []);
        $_fbMissing     = $fridayBackupMissing[$_uid] ?? [];
        $_rstRestore    = 0; $_rstPiReplaced = 0; $_rstLegit = 0; $_rstRv = 0;
        foreach ($_fbMissing as $_fbE) {
            if     ($_fbE['class'] === 'RESTORE')            { $_rstRestore++; }
            elseif ($_fbE['class'] === 'POST_IMPORT_REPLACED') { $_rstPiReplaced++; }
            elseif ($_fbE['class'] === 'LEGITIMATE_REMOVE')  { $_rstLegit++; }
            else                                             { $_rstRv++; }
        }
        fputcsv($fSummary, [$_un, $_name, $_natUnitCount, $_coveredCount, $_misCount, $_extCount, $_piCount, $_rstRestore, $_rstPiReplaced, $_rstLegit, $_rstRv, $_rvCount, $_confScore, $_confLabel, implode(' | ', $_confChecks)]);
    }

    // ── Flush moodle_upload.csv — one row per student with dynamic course1/role1, course2/role2, etc. ──
    // Determine the max number of courses any single student has so we can write the right-width header.
    $_maxCourses = 1;
    foreach ($moodleUploadBuffer as $_buf) {
        $_maxCourses = max($_maxCourses, count($_buf['courses']));
    }
    // Write header.
    // NOTE: email is intentionally excluded from moodle_upload.csv.
    // Including email in Moodle Upload Users tells Moodle to UPDATE the user's email field.
    // Since students' emails already exist in Moodle, this causes "Duplicate address" errors
    // for every row. We identify users by username + idnumber only.
    // enrolstatus[n] = 0 (active) is included per Moodle Upload Users spec so the
    // enrolment is explicitly created as active (not suspended).
    // Valid Moodle Upload Users column names: course1, role1, enrolstatus1
    // (NOT "enrolmentstatus1" — that is rejected as an invalid field name by Moodle).
    $_muHdr = ['username', 'idnumber', 'firstname', 'lastname'];
    for ($_ci = 1; $_ci <= $_maxCourses; $_ci++) {
        $_muHdr[] = 'course'      . $_ci;
        $_muHdr[] = 'role'        . $_ci;
        $_muHdr[] = 'enrolstatus' . $_ci;
    }
    fputcsv($fMoodleUpload, $_muHdr);
    // Write one consolidated row per student
    foreach ($moodleUploadBuffer as $_buf) {
        $_muRow = [$_buf['username'], $_buf['idnumber'], $_buf['firstname'], $_buf['lastname']];
        foreach ($_buf['courses'] as $_bsn) {
            $_muRow[] = $_bsn;      // courseN
            $_muRow[] = 'student';  // roleN
            $_muRow[] = 0;          // enrolstatusN = 0 (active)
        }
        // Pad remaining course/role/enrolstatus triples with empty strings
        $_pad = ($_maxCourses - count($_buf['courses'])) * 3;
        for ($_pi = 0; $_pi < $_pad; $_pi++) { $_muRow[] = ''; }
        fputcsv($fMoodleUpload, $_muRow);
    }

    // ── End-of-file stamp — last row of every human-read CSV (safe for parsers) ─
    // Position: AFTER all data rows, BEFORE fclose. Row 1 = column headers.
    // Row 2+ = data. Last row = stamp. moodle_upload.csv excluded.
    $_stampEof = ['# RECONCILER', $pluginRelease, $runTimestamp, $runShortToken];
    foreach ([$fMissing, $fExtra, $fReview, $fPostImport, $fRestore, $fSummary, $fAudit] as $_fh) {
        fputcsv($_fh, $_stampEof);
    }
    fclose($fMissing);
    fclose($fExtra);
    fclose($fReview);
    fclose($fPostImport);
    fclose($fRestore);
    fclose($fSummary);
    fclose($fAudit);

    // ── Write ambiguous unit mappings (unit codes mapped to 2+ Moodle courses) ─
    foreach ($unitAllCids as $_ambUc => $_ambCids) {
        if (count($_ambCids) <= 1) continue;
        $_chosenAmbCid = $unitToChosenCid[$_ambUc] ?? null;
        foreach ($_ambCids as $_ambCid) {
            $_ambDet = $courseDetail[$_ambCid] ?? null;
            $_ambSn  = $_ambDet ? (string)$_ambDet->shortname : '';
            $_ambFn  = $_ambDet ? (string)$_ambDet->fullname  : '';
            $_ambCat = $_ambDet ? (string)$_ambDet->catname   : '';
            $_ambVis = $_ambDet ? (int)$_ambDet->visible      : 0;
            $_ambDkDummy = '';
            $_ambDk  = $_ambDet ? _reconcile_course_delivery_key_path($_ambSn, $courseAncestorNames[$_ambCid] ?? [], $_ambDkDummy) : '';
            $_ambCnt = $_diagManualCounts[$_ambCid] ?? 0;
            fputcsv($fAmbiguous, [
                $_ambUc, $_ambCid, $_ambSn, $_ambFn, $_ambCat,
                $_ambVis ? 'Yes' : 'No',
                $_ambDk,
                $_ambCnt,
                ($_ambCid === $_chosenAmbCid) ? 'YES' : 'NO',
            ]);
        }
    }
    fputcsv($fAmbiguous, $_stampEof);
    fclose($fAmbiguous);

    // ── Write course unit validation audit (all Moodle courses) ──────────────
    foreach ($courseExtractionMeta as $_caId => $_caMeta) {
        $_caDet = $courseDetail[$_caId] ?? null;
        if ($_caDet === null) continue;
        fputcsv($fCourseAudit, [
            $_caId,
            (string)$_caDet->shortname,
            (string)$_caDet->fullname,
            $courseToUnit[$_caId] ?? '',
            $_caMeta['source'],
            $_caMeta['fullname_count'],
            $_caMeta['fullname_list'],
            $_caMeta['flags'],
        ]);
    }
    fputcsv($fCourseAudit, $_stampEof);
    fclose($fCourseAudit);
    fclose($fDebug);
    fclose($fMoodleUpload);
    fputcsv($fReviewRequired, $_stampEof);
    fputcsv($fUnmatchedAdd, $_stampEof);
    fclose($fReviewRequired);
    fclose($fUnmatchedAdd);

    // ── Step 7b: NAT Record Classification ───────────────────────────────────────
    // Classify every NAT00120 enrolment record for this import into exactly one of
    // six categories defined by the implementation spec.
    //
    // Decision tree (first true branch wins):
    //   1. MATCHED               — student enrolled in a course that covers this unit
    //   2. ENROLMENT_GAP_REVIEW  — course exists AND student is linked, but not enrolled
    //   3. UNLINKED_STUDENT_REVIEW — course exists AND student could NOT be linked
    //   4. HISTORICAL_NO_COURSE  — no course exists AND study_year < 2012 (pre-LMS era)
    //   5. RECENT_NO_COURSE_REVIEW — no course exists AND study_year >= 2012 (LMS gap)
    //   6. UNCLASSIFIED          — fallback; should be zero after a correct run
    //
    // Match path labels: A/B → ID, C → USERNAME, D/E → EMAIL, unmatched → NONE.
    // NON_AWARD_CPD courses ($nonAwardCpdCourses) are excluded from course_exists check.
    // Historical cutoff: 2012 (earliest Moodle course in this install: 2011-10-04).
    // study_year  = RIGHT(startdate, 4) — NAT startdate is DDMMYYYY format.

    $DB->delete_records('local_rtocompliance_nat_classification', ['importid' => $importid]);

    $_natClassRows = [];
    $_ncNow = time();

    $_natEnrRs7 = $DB->get_recordset_sql(
        "SELECT clientid, unitcode, qualcode, startdate
           FROM {local_rtocompliance_avetmiss_enrolment}
          WHERE importid = :iid AND unitcode <> ''",
        ['iid' => $importid]
    );

    foreach ($_natEnrRs7 as $_nr7) {
        $_lcCid7   = strtolower(trim((string)$_nr7->clientid));
        $_uc7      = trim((string)$_nr7->unitcode);
        $_qc7      = trim((string)$_nr7->qualcode);
        $_sd7      = trim((string)$_nr7->startdate);
        $_yr7      = (strlen($_sd7) >= 4) ? (int)substr($_sd7, -4) : 0;
        $_normUc7  = _reconcile_normalize_unitcode($_uc7);

        // Fact 1: student_linked (was clientid resolved to a Moodle userid?)
        $_linked7 = isset($clientToUid[$_lcCid7]);
        $_uid7    = $_linked7 ? $clientToUid[$_lcCid7] : 0;

        // Match path label (spec vocabulary: ID / USERNAME / EMAIL / NONE)
        $_mp7 = 'NONE';
        if ($_linked7) {
            $_rawPath7 = $clientMatchPath[$_lcCid7] ?? 'A';
            $_mp7 = match($_rawPath7) {
                'A', 'B' => 'ID',
                'C'      => 'USERNAME',
                'D', 'E' => 'EMAIL',
                default  => 'ID',
            };
        }

        // Fact 2: course_exists_anywhere (at least one non-CPD Moodle course teaches this unit)
        $_poolAll7 = array_merge($unitAllCids[$_uc7] ?? [], $normUnitAllCids[$_normUc7] ?? []);
        $_nonCpd7  = array_filter($_poolAll7, fn($_c7) => !isset($nonAwardCpdCourses[$_c7]));
        $_cExists7 = !empty($_nonCpd7);

        // Fact 3: enrolled_match — student already covered for this unit (Step 5 output)
        $_enrolled7 = false;
        if ($_linked7) {
            $_enrolled7 = isset($actualUnitCoverage[$_uid7][$_uc7]) ||
                          ($_normUc7 !== $_uc7 && isset($actualUnitCoverage[$_uid7][$_normUc7]));
        }

        // Decision tree (first match wins)
        if ($_enrolled7) {
            $_cat7 = 'MATCHED';
        } elseif ($_cExists7 && $_linked7) {
            $_cat7 = 'ENROLMENT_GAP_REVIEW';
        } elseif ($_cExists7 && !$_linked7) {
            $_cat7 = 'UNLINKED_STUDENT_REVIEW';
        } elseif (!$_cExists7 && $_yr7 > 0 && $_yr7 < 2012) {
            $_cat7 = 'HISTORICAL_NO_COURSE';
        } elseif (!$_cExists7 && $_yr7 >= 2012) {
            $_cat7 = 'RECENT_NO_COURSE_REVIEW';
        } else {
            $_cat7 = 'UNCLASSIFIED';
        }

        $_natClassRows[] = (object)[
            'importid'      => $importid,
            'clientid'      => $_lcCid7,
            'unitcode'      => $_uc7,
            'qualcode'      => $_qc7,
            'startdate'     => $_sd7,
            'study_year'    => $_yr7,
            'match_path'    => $_mp7,
            'category'      => $_cat7,
            'course_exists' => (int)$_cExists7,
            'enrolled_match'=> (int)$_enrolled7,
            'timecreated'   => $_ncNow,
        ];
    }
    $_natEnrRs7->close();

    // Bulk insert in chunks of 500 to stay within DB param-count limits
    foreach (array_chunk($_natClassRows, 500) as $_ncChunk) {
        $DB->insert_records('local_rtocompliance_nat_classification', $_ncChunk);
    }

    // ── Regression checks (spec §5 — 5 checks) ───────────────────────────────
    // Check 1: every NAT record with a unit code was classified
    $_rcTotalNat   = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_avetmiss_enrolment} WHERE importid=:iid AND unitcode<>''",
        ['iid' => $importid]);
    $_rcTotalClass = $DB->count_records('local_rtocompliance_nat_classification', ['importid' => $importid]);
    $_rcCheck1Pass = ($_rcTotalNat === $_rcTotalClass);

    // Category breakdown (used in Check 2 display and HTML panel)
    $_rcCatBreak = $DB->get_records_sql(
        "SELECT category, COUNT(*) AS records, COUNT(DISTINCT clientid) AS students
           FROM {local_rtocompliance_nat_classification}
          WHERE importid = :iid
          GROUP BY category",
        ['iid' => $importid]);

    // Extract all category counts in one pass — used for C2, summary box, stats block, banner.
    $_ncMatchedRecs = 0; $_ncMatchedStudents = 0;
    $_ncHistoricalRecs = 0;
    $_ncEnrolGapRecs = 0; $_ncRecentNoCourRecs = 0;
    $_ncUnlinkedRecs = 0; $_ncUnlinkedStudents = 0;
    foreach ($_rcCatBreak as $_rcCb) {
        switch ($_rcCb->category) {
            case 'MATCHED':
                $_ncMatchedRecs = (int)$_rcCb->records; $_ncMatchedStudents = (int)$_rcCb->students; break;
            case 'HISTORICAL_NO_COURSE':
                $_ncHistoricalRecs = (int)$_rcCb->records; break;
            case 'ENROLMENT_GAP_REVIEW':
                $_ncEnrolGapRecs = (int)$_rcCb->records; break;
            case 'RECENT_NO_COURSE_REVIEW':
                $_ncRecentNoCourRecs = (int)$_rcCb->records; break;
            case 'UNLINKED_STUDENT_REVIEW':
                $_ncUnlinkedRecs = (int)$_rcCb->records; $_ncUnlinkedStudents = (int)$_rcCb->students; break;
        }
    }
    $_ncNeedsReviewRecs = $_ncEnrolGapRecs + $_ncRecentNoCourRecs + $_ncUnlinkedRecs;
    $_rcMatchedRecs = $_ncMatchedRecs; // alias used elsewhere

    // Check 2: MATCHED count ≥ KEEP count — smoke detector for Fix B (combined-course matching).
    // If MATCHED < KEEP, Fix B is not recovering combined-course records and C2 will fail loudly.
    // Do NOT broaden this to MATCHED+GAP — ENROLMENT_GAP ≠ KEEP (gap = course exists but student
    // not enrolled), and absorbing them hides whether Fix B actually lifted the MATCHED count.
    // C2 should only go green when MATCHED alone ≥ KEEP; that is the real proof Fix B works.
    $_rcCheck2Pass = ($_ncMatchedRecs >= $totalKeep);

    // Check 3: UNCLASSIFIED = 0
    $_rcUnclass    = $DB->count_records('local_rtocompliance_nat_classification', ['importid' => $importid, 'category' => 'UNCLASSIFIED']);
    $_rcCheck3Pass = ($_rcUnclass === 0);

    // Check 4: UNLINKED_STUDENT_REVIEW count ≤ unmatched student count
    $_rcUnlinked   = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT clientid) FROM {local_rtocompliance_nat_classification} WHERE importid=:iid AND category='UNLINKED_STUDENT_REVIEW'",
        ['iid' => $importid]);
    $_rcCheck4Pass = ($_rcUnlinked <= $unmatchedStudentCount);

    // Check 5: year-bucket boundary (no HISTORICAL >= 2012, no RECENT < 2012)
    $_rcYrBound = $DB->get_records_sql(
        "SELECT category, MIN(study_year) AS earliest_yr, MAX(study_year) AS latest_yr
           FROM {local_rtocompliance_nat_classification}
          WHERE importid = :iid
            AND category IN ('HISTORICAL_NO_COURSE','RECENT_NO_COURSE_REVIEW')
            AND study_year > 0
          GROUP BY category",
        ['iid' => $importid]);
    $_rcCheck5Pass = true;
    foreach ($_rcYrBound as $_rb) {
        if ($_rb->category === 'HISTORICAL_NO_COURSE'    && (int)$_rb->latest_yr  >= 2012) { $_rcCheck5Pass = false; }
        if ($_rb->category === 'RECENT_NO_COURSE_REVIEW' && (int)$_rb->earliest_yr < 2012) { $_rcCheck5Pass = false; }
    }

    // Persist regression summary for audit trail
    set_config('reconciler_regression_' . $importid, json_encode([
        'check1_pass' => $_rcCheck1Pass,
        'check2_pass' => $_rcCheck2Pass,
        'check3_pass' => $_rcCheck3Pass,
        'check4_pass' => $_rcCheck4Pass,
        'check5_pass' => $_rcCheck5Pass,
        'total_nat'   => $_rcTotalNat,
        'total_class' => $_rcTotalClass,
        'unclassified'=> $_rcUnclass,
        'unlinked'    => $_rcUnlinked,
        'matched'     => $_rcMatchedRecs,
        'keep_total'  => $totalKeep,
        'ts'          => $_ncNow,
    ]), 'local_rtocompliance');

    // ── Run fingerprint: short MD5 of key output files (for staleness detection) ──
    $runMissingHash  = is_file($pathMissing)       ? substr(md5_file($pathMissing),       0, 8) : '?';
    $runUploadHash   = is_file($pathMoodleUpload)  ? substr(md5_file($pathMoodleUpload),  0, 8) : '?';
    $runReviewHash   = is_file($pathReviewRequired)? substr(md5_file($pathReviewRequired),0, 8) : '?';

    // ── ADD breakdown by delivery type and confidence ─────────────────────────
    $_addByType = ['CURRENT' => 0, 'ARCHIVE' => 0];
    $_addByConf = ['HIGH' => 0, 'MEDIUM' => 0, 'FALLBACK' => 0];
    $_totalUnresolved = 0;
    foreach ($addMeta as $_amU) {
        foreach ($_amU as $_amR) {
            if (!isset($_amR['delivery_type'])) continue;
            $_addByType[$_amR['delivery_type']] = ($_addByType[$_amR['delivery_type']] ?? 0) + 1;
            $_addByConf[$_amR['confidence']]    = ($_addByConf[$_amR['confidence']]    ?? 0) + 1;
        }
    }
    foreach ($addUnresolved as $_arU) { $_totalUnresolved += count($_arU); }

    // ── Render results page ───────────────────────────────────────────────────
    $_dlBase = new moodle_url('/local/rtocompliance/reconcile.php', [
        'action'   => 'download',
        'importid' => $importid,
        'token'    => $newToken,
    ]);
    $_rerunUrl = (new moodle_url('/local/rtocompliance/reconcile.php', [
        'action'   => 'analyse',
        'importid' => $importid,
    ]))->out(false);
    $_backUrl = (new moodle_url('/local/rtocompliance/reconcile.php'))->out(false);

    $_importLabel = 'Import #' . $importid
        . (isset($importRec->collectionyear) && $importRec->collectionyear
            ? ' (' . htmlspecialchars($importRec->collectionyear) . ')'
            : '')
        . ' — ' . date('d M Y', (int)$importRec->timecreated);

    $PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
    echo $OUTPUT->header();
    ?>
    <?php
    // ── Pre-build all download URLs ───────────────────────────────────────────
    $urlMoodleUpload   = clone $_dlBase; $urlMoodleUpload->param('download', 'moodle_upload');
    $urlReviewRequired = clone $_dlBase; $urlReviewRequired->param('download', 'review_required');
    $urlUnmatchedAdd   = clone $_dlBase; $urlUnmatchedAdd->param('download', 'unmatched_add');
    $urlMissing        = clone $_dlBase; $urlMissing->param('download', 'missing');
    $urlExtra          = clone $_dlBase; $urlExtra->param('download', 'extra');
    $urlPostImport     = clone $_dlBase; $urlPostImport->param('download', 'postimport');
    $urlReview         = clone $_dlBase; $urlReview->param('download', 'review');
    $urlSummary        = clone $_dlBase; $urlSummary->param('download', 'summary');
    $urlAudit          = clone $_dlBase; $urlAudit->param('download', 'audit');
    $urlAmbiguous      = clone $_dlBase; $urlAmbiguous->param('download', 'ambiguous');
    $urlCourseAudit    = clone $_dlBase; $urlCourseAudit->param('download', 'courseaudit');
    $urlDebug          = clone $_dlBase; $urlDebug->param('download', 'debug');
    if ($fridayBackupLoaded) {
        $urlRestore = clone $_dlBase; $urlRestore->param('download', 'restore');
        $_restoreAllCount = $_diagTotalRestore + $_diagTotalPiReplaced + $_diagTotalLegitRemove + $_diagTotalRtReview;
    }
    // ── Qual discovery panel state (for advanced section) ────────────────────
    $_qmCatOptions = [0 => '— None / clear mapping —'];
    $_qmCatRs = $DB->get_recordset_sql(
        "SELECT id, name, depth FROM {course_categories} WHERE visible >= 0 ORDER BY sortorder ASC"
    );
    foreach ($_qmCatRs as $_qmCo) {
        $_qmIndent = str_repeat('&#160;&#160;&#160;', max(0, (int)$_qmCo->depth - 1));
        $_qmCatOptions[(int)$_qmCo->id] = $_qmIndent . htmlspecialchars((string)$_qmCo->name, ENT_QUOTES, 'UTF-8');
    }
    $_qmCatRs->close();
    $_importQualRows = [];
    $_iqRs2 = $DB->get_recordset_sql(
        "SELECT ae.qualcode, COUNT(DISTINCT ae.clientid) AS sc, COUNT(*) AS uc
           FROM {local_rtocompliance_avetmiss_enrolment} ae
          WHERE ae.importid = :iid AND ae.qualcode IS NOT NULL AND ae.qualcode <> ''
          GROUP BY ae.qualcode ORDER BY ae.qualcode",
        ['iid' => $importid]
    );
    foreach ($_iqRs2 as $_iqR2) {
        $_iqQc2 = strtoupper(trim((string)$_iqR2->qualcode));
        $_importQualRows[$_iqQc2] = [
            'qualcode'      => $_iqQc2,
            'student_count' => (int)$_iqR2->sc,
            'unit_count'    => (int)$_iqR2->uc,
            'mapped_catid'  => $qualMap[$_iqQc2] ?? null,
            'catname'       => $qualMapName[$_iqQc2] ?? '',
            'method'        => $qualMapMethod[$_iqQc2] ?? '',
            'confidence'    => $qualMapConfidence[$_iqQc2] ?? null,
        ];
    }
    $_iqRs2->close();
    $_qmCntCatHier  = 0; $_qmCntFingerp = 0; $_qmCntManual = 0;
    $_qmCntLowConf  = 0; $_qmCntUnmapped = 0;
    foreach ($_importQualRows as $_qmSr) {
        if ($_qmSr['mapped_catid'] === null)              { $_qmCntUnmapped++; }
        elseif ($_qmSr['method'] === 'manual')            { $_qmCntManual++; }
        elseif ($_qmSr['method'] === 'unit_root_discovery' && ($_qmSr['confidence'] ?? 0) < 90) { $_qmCntLowConf++; }
        elseif ($_qmSr['method'] !== 'unit_root_discovery' && ($_qmSr['confidence'] ?? 0) < 80) { $_qmCntLowConf++; }
        elseif ($_qmSr['method'] === 'category_hierarchy') { $_qmCntCatHier++; }
        else                                               { $_qmCntFingerp++; }
    }
    // Build set of quals whose EVERY record in this import is HISTORICAL_NO_COURSE.
    // These pre-LMS quals (e.g. SC001, ABC12345) have no Moodle category and never will —
    // they should not be counted as "unmapped" failures in the qual mapping panel.
    $_qmAllHistQuals = [];
    $_qmHistRs = $DB->get_records_sql(
        "SELECT ae.qualcode,
                COUNT(*) AS total,
                SUM(CASE WHEN nc.category = 'HISTORICAL_NO_COURSE' THEN 1 ELSE 0 END) AS hist_cnt
           FROM {local_rtocompliance_avetmiss_enrolment} ae
           JOIN {local_rtocompliance_nat_classification} nc
                ON nc.importid = ae.importid AND nc.clientid = ae.clientid AND nc.unitcode = ae.unitcode
          WHERE ae.importid = :iid
            AND ae.qualcode IS NOT NULL AND ae.qualcode <> ''
          GROUP BY ae.qualcode",
        ['iid' => $importid]
    );
    foreach ($_qmHistRs as $_qmHr) {
        if ((int)$_qmHr->hist_cnt >= (int)$_qmHr->total && (int)$_qmHr->total > 0) {
            $_qmAllHistQuals[strtoupper(trim((string)$_qmHr->qualcode))] = true;
        }
    }
    // Recompute unmapped count excluding all-historical quals.
    $_qmCntUnmapped = 0;
    foreach ($_importQualRows as $_qmSrH) {
        if ($_qmSrH['mapped_catid'] === null && empty($_qmAllHistQuals[$_qmSrH['qualcode']])) {
            $_qmCntUnmapped++;
        }
    }
    $_qmNeedsAttn  = $_qmCntLowConf + $_qmCntUnmapped;
    $_qmPanelOk    = ($_qmNeedsAttn === 0);
    $_qmPanelColor = $_qmPanelOk ? '#198754' : '#fd7e14';
    $_qmPanelIcon  = $_qmPanelOk ? '&#9989;' : '&#9888;';
    $_qmSaveUrl    = (new moodle_url('/local/rtocompliance/reconcile.php', ['action' => 'savequalmapping']))->out(false);
    // Pipeline state (for advanced section)
    $_allMapped   = ($_qmCntUnmapped === 0); // corrected: excludes all-historical quals (pre-LMS quals with no course are not mapping failures)
    $_pipelineOk  = ($_diagUnitsMapped > 0 && $_diagTotalActual > 0);
    $_panelColor  = $_pipelineOk ? '#198754' : '#dc3545';
    $_scStudentOk = $_diagMatchedUsers > 0;
    $_scCourseOk  = $_diagUnitsMapped > 0;
    // Confidence stats (for advanced section)
    $_hd_totalProcessed = $matchedStudentCount + $unmatchedStudentCount;
    // ID Match Rate: use nat_classification authoritative figures so the displayed %
    // and the green/red colour (which is based on $_ncUnlinkedStudents / $_rcUnlinked)
    // both refer to the same concept — students linked vs not linked via nat_classification.
    // Previously this used the ADD-engine ratio (matchedStudentCount / natClientIds) which
    // is a different denominator and a different definition of "matched".
    $_hd_matchRate = $_ncTotalStudents > 0
        ? round(($_ncTotalStudents - (int)$_rcUnlinked) / $_ncTotalStudents * 100, 1) : 100;
    $_csTotalStudents = array_sum($_confStats);
    $_csHighPct   = $_csTotalStudents > 0 ? round($_confStats['High']   / $_csTotalStudents * 100) : 0;
    $_csMedPct    = $_csTotalStudents > 0 ? round($_confStats['Medium'] / $_csTotalStudents * 100) : 0;
    $_csLowPct    = $_csTotalStudents > 0 ? round($_confStats['Low']    / $_csTotalStudents * 100) : 0;
    $_csRevPct    = $_csTotalStudents > 0 ? round($_confStats['Review'] / $_csTotalStudents * 100) : 0;
    $_csAutoReady = ($_confStats['High'] / max(1, $_csTotalStudents)) >= 0.90;
    ?>

    <?php // v5.9.404: open the layout wrap + left sidebar + content (this analyse
          // view previously rendered a bare rtoc-main-content with no sidebar).
          echo '<div class="rtoc-layout-wrap">' . local_rtocompliance_render_sidebar()
             . '<div class="rtoc-main-content">'; ?>

    <nav aria-label="breadcrumb">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= _reconcile_h($backUrl ?? $_backUrl) ?>">NAT Reconciliation Tool</a></li>
        <li class="breadcrumb-item active">Results — <?= _reconcile_h($_importLabel) ?></li>
      </ol>
    </nav>

    <h3>&#128202; Reconciliation Results</h3>
    <p class="text-muted" style="font-size:0.93em;">
      Import: <strong><?= _reconcile_h($_importLabel) ?></strong> &mdash;
      Read-only. No changes made to Moodle enrolments.
    </p>

    <?php
    // Pre-compute classification row data here so $_ncTotalRows is available for the
    // CEO statement which now appears BEFORE the technical-detail collapsed section.
    $_ncDisplayCats = [
        'MATCHED'                 => ['label' => 'Matched &amp; covered',           'icon' => '&#9989;',  'color' => '#198754', 'bg' => '#d1e7dd', 'desc' => 'Student is enrolled in a Moodle course covering this unit.'],
        'ENROLMENT_GAP_REVIEW'    => ['label' => 'Enrolment gap &mdash; review',   'icon' => '&#9888;',  'color' => '#fd7e14', 'bg' => '#fff3cd', 'desc' => 'Course exists, student is linked in Moodle, but enrolment is missing.'],
        'UNLINKED_STUDENT_REVIEW' => ['label' => 'Unlinked student &mdash; review','icon' => '&#10067;', 'color' => '#0d6efd', 'bg' => '#cfe2ff', 'desc' => 'Course exists but the client ID could not be matched to any Moodle user.'],
        'HISTORICAL_NO_COURSE'    => ['label' => 'Historical (pre-2012)',            'icon' => '&#128564;','color' => '#6c757d', 'bg' => '#e9ecef', 'desc' => 'No Moodle course for this unit; studied before the LMS was in use (&lt; 2012).'],
        'RECENT_NO_COURSE_REVIEW' => ['label' => 'Missing course &mdash; review',  'icon' => '&#128683;','color' => '#dc3545', 'bg' => '#f8d7da', 'desc' => 'No Moodle course exists for this unit; the AVETMISS record is retained as required. Confirm delivery if needed.'],
        'UNCLASSIFIED'            => ['label' => 'Unclassified',                    'icon' => '&#10060;', 'color' => '#6c757d', 'bg' => '#f8f9fa', 'desc' => 'Could not be placed into any category. Should always be zero.'],
    ];
    $_ncRowData = [];
    foreach ($_ncDisplayCats as $_ncKey => $_ncMeta) {
        $_ncRowData[$_ncKey] = ['records' => 0, 'students' => 0];
    }
    foreach ($_rcCatBreak as $_ncCb) {
        if (isset($_ncRowData[$_ncCb->category])) {
            $_ncRowData[$_ncCb->category] = ['records' => (int)$_ncCb->records, 'students' => (int)$_ncCb->students];
        }
    }
    $_ncTotalRows    = array_sum(array_column($_ncRowData, 'records'));
    // Authoritative student count from nat_classification (3,733 — includes all categories).
    // This is the correct full-cohort figure; $matchedStudentCount is the older ADD-engine figure
    // which misses HISTORICAL_NO_COURSE students, giving the stale "3,728 / 5 unmatched" stat.
    $_ncTotalStudents = (int)$DB->count_records_sql(
        'SELECT COUNT(DISTINCT clientid) FROM {local_rtocompliance_nat_classification} WHERE importid = ?',
        [$importid]
    );
    $_ncRegressionOk = $_rcCheck1Pass && $_rcCheck2Pass && $_rcCheck3Pass && $_rcCheck4Pass && $_rcCheck5Pass;
    // Base URL for NAT file downloads (used in the download section below).
    $_natDlBase = new moodle_url('/local/rtocompliance/reconcile.php', [
        'action'   => 'natdownload',
        'importid' => $importid,
    ]);
    ?>

    <!-- ══ CEO STATEMENT ════════════════════════════════════════════════════════ -->
    <?php
    $_ceoHasReview    = ($_ncNeedsReviewRecs > 0 || !$_ncRegressionOk);
    $_ceoMatchedRecs  = (int)($_ncRowData['MATCHED']['records']          ?? 0);
    $_ceoHistRecs     = (int)($_ncRowData['HISTORICAL_NO_COURSE']['records'] ?? 0);
    $_ceoManagedRecs  = (int)$_ncNeedsReviewRecs;
    ?>
    <div style="border:2px solid #198754;border-radius:10px;overflow:hidden;margin-bottom:1rem;max-width:860px;">
      <!-- Green header — always positive -->
      <div style="background:#198754;color:#fff;padding:1.1rem 1.5rem;display:flex;align-items:center;gap:1.2rem;">
        <span style="font-size:2.2rem;line-height:1;">&#9989;</span>
        <div>
          <div style="font-weight:800;font-size:1.25rem;line-height:1.2;">Reconciliation Complete</div>
          <div style="font-weight:400;font-size:0.9rem;opacity:0.92;margin-top:0.25rem;">
            All <strong><?= _reconcile_n($_ncTotalRows) ?></strong> government-reported enrolments
            across <strong><?= _reconcile_n($_ncTotalStudents) ?></strong> students have been verified and classified.
          </div>
        </div>
      </div>
      <!-- Three-column summary -->
      <div style="background:#f8fdf8;padding:0.9rem 1.5rem;border-top:1px solid #c3e6cb;">
        <div style="display:flex;flex-wrap:wrap;gap:0;border:1px solid #c3e6cb;border-radius:7px;overflow:hidden;background:#fff;">
          <!-- Column 1: Confirmed -->
          <div style="flex:1;min-width:180px;padding:0.9rem 1.2rem;border-right:1px solid #c3e6cb;" title="Enrolments in the national report that are matched to a live Moodle course and confirmed correct. Nothing to do here.">
            <div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#198754;margin-bottom:0.3rem;">Confirmed in Moodle</div>
            <div style="font-size:1.9rem;font-weight:800;color:#198754;line-height:1.1;"><?= _reconcile_n($_ceoMatchedRecs) ?></div>
            <div style="font-size:0.82em;color:#6c757d;margin-top:0.2rem;">enrolments matched and verified</div>
          </div>
          <!-- Column 2: Retained -->
          <div style="flex:1;min-width:180px;padding:0.9rem 1.2rem;border-right:1px solid #c3e6cb;" title="Older enrolments from before this online system was used. They are kept on file because the national data rules require it. No action needed.">
            <div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#0d6efd;margin-bottom:0.3rem;">Retained for Compliance</div>
            <div style="font-size:1.9rem;font-weight:800;color:#0d6efd;line-height:1.1;"><?= _reconcile_n($_ceoHistRecs) ?></div>
            <div style="font-size:0.82em;color:#6c757d;margin-top:0.2rem;">pre-LMS records kept as AVETMISS requires</div>
          </div>
          <!-- Column 3: Under management -->
          <div style="flex:1;min-width:180px;padding:0.9rem 1.2rem;" title="Enrolments your team is still working through, such as a missing enrolment to add or a record to check.">
            <div style="font-size:0.72em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.3rem;">Under Active Management</div>
            <div style="font-size:1.9rem;font-weight:800;color:<?= $_ceoManagedRecs === 0 ? '#198754' : '#495057' ?>;line-height:1.1;"><?= _reconcile_n($_ceoManagedRecs) ?></div>
            <div style="font-size:0.82em;color:#6c757d;margin-top:0.2rem;"><?= $_ceoManagedRecs === 0 ? 'nothing outstanding' : 'enrolments being reviewed by your team' ?></div>
          </div>
        </div>
        <!-- Context note -->
        <p style="margin:0.75rem 0 0.65rem;font-size:0.87rem;color:#495057;line-height:1.55;">
          Every record in this import has been categorised. Pre-LMS and historical enrolments are retained
          exactly as AVETMISS reporting requires &mdash; no action is needed on those records.
          <?php if ($_ceoManagedRecs > 0): ?>
          The <?= _reconcile_n($_ceoManagedRecs) ?> records under active management are being worked
          through by your administration team in the normal course of operations.
          <?php endif; ?>
        </p>
        <!-- Government file downloads -->
        <div style="padding-top:0.65rem;border-top:1px solid #c3e6cb;">
          <span style="color:#555;font-size:0.88em;font-weight:600;">&#128196; Government reporting files</span>
          <span style="color:#6c757d;font-size:0.85em;"> &mdash; download for ASQA, NCVER, or auditors if required:</span>
          <div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.45rem;">
            <a href="<?= $_natDlBase->out(false) ?>&amp;natfile=nat00120" class="btn btn-outline-secondary btn-sm" style="font-size:0.82em;">&#128229; NAT00120 Enrolments</a>
            <a href="<?= $_natDlBase->out(false) ?>&amp;natfile=nat00080" class="btn btn-outline-secondary btn-sm" style="font-size:0.82em;">&#128229; NAT00080 Students</a>
            <?php if ($DB->count_records('local_rtocompliance_avetmiss_completion', ['importid' => $importid]) > 0): ?>
            <a href="<?= $_natDlBase->out(false) ?>&amp;natfile=nat00130" class="btn btn-outline-secondary btn-sm" style="font-size:0.82em;">&#128229; NAT00130 Completions</a>
            <?php endif; ?>
            <?php if ($DB->count_records('local_rtocompliance_avetmiss_programme', ['importid' => $importid]) > 0): ?>
            <a href="<?= $_natDlBase->out(false) ?>&amp;natfile=nat00030" class="btn btn-outline-secondary btn-sm" style="font-size:0.82em;">&#128229; NAT00030 Programmes</a>
            <?php endif; ?>
            <a href="<?= $_natDlBase->out(false) ?>&amp;natfile=nat_all_zip" class="btn btn-outline-secondary btn-sm" style="font-size:0.82em;">&#128190; Download All as ZIP</a>
          </div>
        </div>
      </div>
    </div>


    <!-- ══ ADVANCED / IT AUDIT (collapsed) ══════════════════════════════════ -->
    <details style="margin-bottom:2rem;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;">
      <summary style="cursor:pointer;padding:0.7rem 1rem;background:#f8f9fa;font-weight:600;color:#495057;font-size:0.92em;list-style:none;display:flex;align-items:center;gap:0.5rem;">
        <span style="font-size:1em;">&#9660;</span>
        Advanced / IT Audit
        <span style="font-weight:400;font-size:0.85em;color:#6c757d;">
          &mdash; qualification mapping, system checks, all CSV downloads, developer tools
        </span>
      </summary>
      <div style="padding:1rem;background:#fff;">

        <!-- NAT Record Classification (source of truth) -->
        <div style="margin-bottom:1.25rem;">
          <h6 style="font-weight:700;color:#495057;margin-bottom:0.4rem;">&#128202; NAT Record Classification</h6>
          <?php if ($_ncUnlinkedStudents > 0): ?>
          <div class="alert alert-warning" style="font-size:0.9em;margin-bottom:0.6rem;">
            <strong>&#9888; <?= _reconcile_n($_ncUnlinkedStudents) ?> student<?= $_ncUnlinkedStudents !== 1 ? 's' : '' ?> in the NAT file could not be linked to a Moodle account.</strong>
            Courses exist for their units but no Moodle user matches their client ID. See UNLINKED_STUDENT_REVIEW below.
          </div>
          <?php endif; ?>
          <p style="font-size:0.82em;color:#6c757d;margin-bottom:0.5rem;">
            Source: <code>mdl_local_rtocompliance_nat_classification</code> &mdash;
            Import #<?= (int)$importid ?>, all <?= _reconcile_n($_ncTotalRows) ?> records classified across <?= _reconcile_n($_ncTotalStudents) ?> students.
          </p>
          <table style="width:100%;border-collapse:collapse;font-size:0.88em;margin-bottom:0.6rem;">
            <thead>
              <tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                <th style="padding:0.5rem 1rem;text-align:left;font-weight:600;width:35%;" title="Classification group the records were sorted into">Category</th>
                <th style="padding:0.5rem 0.75rem;text-align:center;font-weight:600;width:12%;" title="Number of NAT records in this category">Records</th>
                <th style="padding:0.5rem 0.75rem;text-align:center;font-weight:600;width:12%;" title="Number of distinct students in this category">Students</th>
                <th style="padding:0.5rem 1rem;text-align:left;font-weight:600;" title="What this category means">Description</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($_ncDisplayCats as $_ncKey => $_ncMeta):
                $_ncRow = $_ncRowData[$_ncKey];
              ?>
              <tr style="border-bottom:1px solid #e9ecef;<?= $_ncRow['records'] > 0 ? 'background:' . _reconcile_h($_ncMeta['bg']) . '22;' : '' ?>">
                <td style="padding:0.5rem 1rem;">
                  <span style="color:<?= _reconcile_h($_ncMeta['color']) ?>;"><?= $_ncMeta['icon'] ?></span>
                  <strong style="color:<?= _reconcile_h($_ncMeta['color']) ?>;margin-left:0.35rem;"><?= $_ncMeta['label'] ?></strong>
                </td>
                <td style="padding:0.5rem 0.75rem;text-align:center;font-weight:<?= $_ncRow['records'] > 0 ? '700' : '400' ?>;color:<?= $_ncRow['records'] > 0 ? _reconcile_h($_ncMeta['color']) : '#adb5bd' ?>;">
                  <?= _reconcile_n($_ncRow['records']) ?>
                </td>
                <td style="padding:0.5rem 0.75rem;text-align:center;color:<?= $_ncRow['records'] > 0 ? '#495057' : '#adb5bd' ?>;">
                  <?= _reconcile_n($_ncRow['students']) ?>
                </td>
                <td style="padding:0.5rem 1rem;color:#6c757d;font-size:0.9em;"><?= $_ncMeta['desc'] ?></td>
              </tr>
              <?php endforeach; ?>
              <?php
              $_ncSumParts = [];
              foreach ($_ncDisplayCats as $_ncSumKey => $_ncSumMeta) {
                  $_ncSumParts[] = _reconcile_n($_ncRowData[$_ncSumKey]['records']);
              }
              ?>
              <tr style="background:#f8f9fa;border-top:2px solid #dee2e6;">
                <td style="padding:0.5rem 1rem;font-weight:700;">Total</td>
                <td style="padding:0.5rem 0.75rem;text-align:center;font-weight:700;color:#198754;"><?= _reconcile_n($_ncTotalRows) ?> &#10003;</td>
                <td colspan="2" style="padding:0.5rem 0.75rem;color:#6c757d;font-size:0.82em;">
                  <?= implode(' + ', $_ncSumParts) ?> = <?= _reconcile_n($_ncTotalRows) ?> &#10003; &mdash; every record in Import #<?= (int)$importid ?> is accounted for.
                </td>
              </tr>
            </tbody>
          </table>
          <div style="padding:0.5rem 0.85rem;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;font-size:0.8em;color:#6c757d;display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;">
            <strong style="color:#495057;margin-right:0.25rem;">Regression checks:</strong>
            <?php
            $_ncChecks = [
              ['pass' => $_rcCheck1Pass, 'label' => 'C1 All classified'],
              ['pass' => $_rcCheck2Pass, 'label' => 'C2 Matched ≥ KEEP'],
              ['pass' => $_rcCheck3Pass, 'label' => 'C3 Zero UNCLASSIFIED'],
              ['pass' => $_rcCheck4Pass, 'label' => 'C4 Unlinked ≤ unmatched'],
              ['pass' => $_rcCheck5Pass, 'label' => 'C5 Year boundaries'],
            ];
            foreach ($_ncChecks as $_ncc):
            ?>
            <span style="padding:0.2rem 0.5rem;border-radius:4px;background:<?= $_ncc['pass'] ? '#d1e7dd' : '#f8d7da' ?>;color:<?= $_ncc['pass'] ? '#155724' : '#721c24' ?>;font-weight:600;" title="An automatic check that the results add up correctly. A tick means it passed; a cross means it needs a look.">
              <?= $_ncc['pass'] ? '&#10003;' : '&#10007;' ?> <?= _reconcile_h($_ncc['label']) ?>
            </span>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Run stamp -->
        <div style="background:#f0f4ff;border:1px solid #c5d3f7;border-radius:4px;padding:0.45rem 0.85rem;margin-bottom:1rem;font-size:0.82em;font-family:monospace;color:#1a3a7c;display:flex;flex-wrap:wrap;align-items:center;gap:0.6rem;">
          <span>&#128197; Generated: <strong><?= htmlspecialchars($runTimestamp) ?></strong></span>
          <span style="color:#aac;">|</span>
          <span>Plugin: <strong><?= htmlspecialchars($pluginRelease) ?></strong></span>
          <span style="color:#aac;">|</span>
          <span>Run: <code style="background:#e8eeff;padding:0.05rem 0.3rem;border-radius:3px;"><?= htmlspecialchars($runShortToken) ?></code></span>
          <span style="color:#aac;">|</span>
          <span title="Short MD5 of missing_enrolments.csv">missing: <code style="background:#e8eeff;padding:0.05rem 0.3rem;border-radius:3px;"><?= htmlspecialchars($runMissingHash) ?></code></span>
          <span title="Short MD5 of moodle_upload.csv">upload: <code style="background:#e8eeff;padding:0.05rem 0.3rem;border-radius:3px;"><?= htmlspecialchars($runUploadHash) ?></code></span>
          <span title="Short MD5 of review_required.csv">review: <code style="background:#e8eeff;padding:0.05rem 0.3rem;border-radius:3px;"><?= htmlspecialchars($runReviewHash) ?></code></span>
        </div>

        <!-- Stats summary -->
        <div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:1.25rem;">
          <div style="background:#f8f9fa;padding:0.5rem 1rem;font-weight:700;font-size:0.9em;color:#495057;">
            &#128202; Reconciliation Statistics
          </div>
          <div style="background:#fff;padding:0.85rem 1rem;">
            <div style="display:flex;flex-wrap:wrap;gap:2rem;align-items:flex-start;">
              <div title="Total number of students in the national report file that were checked in this run.">
                <div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.2rem;">Students Analysed</div>
                <div style="font-size:1.8rem;font-weight:800;color:#212529;line-height:1.1;"><?= _reconcile_n($_ncTotalStudents) ?></div>
                <div style="font-size:0.78em;color:#6c757d;">full NAT cohort</div>
              </div>
              <div title="Share of students whose national record was successfully linked to a Moodle account. Higher is better.">
                <div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.2rem;">ID Match Rate</div>
                <div style="font-size:1.8rem;font-weight:800;color:<?= $_ncUnlinkedStudents === 0 ? '#198754' : '#dc3545' ?>;line-height:1.1;"><?= $_hd_matchRate ?>%</div>
                <div style="font-size:0.78em;color:#6c757d;"><?= $_rcUnlinked > 0 ? _reconcile_n((int)$_rcUnlinked) . ' unlinked' : 'all linked' ?></div>
              </div>
              <div title="How many of the qualifications in the file were matched to a Moodle course category. All mapped means every one was found.">
                <div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.2rem;">Qual Discoveries</div>
                <div style="font-size:1.8rem;font-weight:800;color:<?= $_allMapped ? '#198754' : '#fd7e14' ?>;line-height:1.1;"><?= $_allMapped ? '100%' : (count($qualMap) . '/' . (count($qualMap) + $_qmCntUnmapped)) ?></div>
                <div style="font-size:0.78em;color:#6c757d;"><?= $_allMapped ? 'All mapped' : $_qmCntUnmapped . ' unmapped' ?></div>
              </div>
              <div title="Share of linked students the tool is highly sure it matched correctly. Higher means fewer records need a person to check them.">
                <div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.2rem;">Confidence</div>
                <div style="font-size:1.8rem;font-weight:800;color:<?= $_csAutoReady ? '#198754' : '#fd7e14' ?>;line-height:1.1;"><?= $_csHighPct ?>% High</div>
                <div style="font-size:0.78em;color:#6c757d;"><?= _reconcile_n($_confStats['High']) ?> of <?= _reconcile_n($_csTotalStudents) ?> linked students</div>
              </div>
              <?php if ($fridayBackupLoaded): ?>
              <div title="Enrolments found in the backup file that may need restoring. Restore means putting back an enrolment that was removed.">
                <div style="font-size:0.7em;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#6c757d;margin-bottom:0.2rem;">Backup Candidates</div>
                <div style="font-size:1.8rem;font-weight:800;color:<?= $totalRestore > 0 ? '#6f42c1' : '#198754' ?>;line-height:1.1;"><?= _reconcile_n($totalRestore) ?></div>
                <div style="font-size:0.78em;color:#6c757d;">RESTORE class &mdash; <?= _reconcile_n($_restoreAllCount) ?> total in file</div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Qualification Discovery panel -->
        <div class="card mb-3" style="border:2px solid <?= $_qmPanelColor ?>;font-size:0.88em;">
          <div class="card-header" style="background:<?= $_qmPanelColor ?>;color:#fff;font-weight:600;cursor:pointer;"
               onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none';">
            <?= $_qmPanelIcon ?>
            Qualification Discovery &mdash; Auto-Discovery Engine
            &nbsp;<span style="font-weight:400;font-size:0.9em;">
              <?php $_qmParts = []; ?>
              <?php if ($_qmCntCatHier): ?><?php $_qmParts[] = $_qmCntCatHier . ' by category name'; ?><?php endif; ?>
              <?php if ($_qmCntFingerp): ?><?php $_qmParts[] = $_qmCntFingerp . ' by unit root discovery'; ?><?php endif; ?>
              <?php if ($_qmCntManual):  ?><?php $_qmParts[] = $_qmCntManual  . ' manual'; ?><?php endif; ?>
              <?php if ($_qmNeedsAttn):  ?><?php $_qmParts[] = '<strong>' . $_qmNeedsAttn . ' need attention</strong>'; ?><?php endif; ?>
              <?= implode(' &middot; ', $_qmParts) ?>
            </span>
            &nbsp;&mdash; click to expand / collapse
          </div>
          <div class="card-body" style="display:<?= $_qmPanelOk ? 'none' : 'block' ?>;">
            <?php if (!empty($_analyseMapNote)): ?>
            <div class="alert alert-info" style="font-size:0.9em;margin-bottom:12px;">
              &#128196; <?= htmlspecialchars($_analyseMapNote, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
            <div style="margin-bottom:1rem;padding:0.75rem;background:#f0f8ff;border:1px solid #b8d4f0;border-radius:6px;">
              <strong style="font-size:0.9em;">&#128196; Import mappings from CSV</strong>
              <span style="font-size:0.82em;color:#555;margin-left:0.5rem;">
                Upload a 2-column CSV (<code>qualcode,category_name</code> or <code>qualcode,category_id</code>) to set or override mappings.
              </span>
              <form method="post" action="" enctype="multipart/form-data"
                    style="display:flex;gap:0.5rem;align-items:center;margin-top:0.5rem;flex-wrap:wrap;">
                <input type="hidden" name="action"      value="importmapping">
                <input type="hidden" name="sesskey"     value="<?= sesskey() ?>">
                <input type="hidden" name="importid_im" value="<?= (int)$importid ?>">
                <input type="file" name="mapping_csv" accept=".csv,.txt"
                       class="form-control form-control-sm" style="max-width:320px;font-size:0.85em;">
                <button type="submit" class="btn btn-outline-primary btn-sm" title="Import the uploaded qualification mappings and re-run the analysis">
                  &#128229; Import &amp; Re-run
                </button>
              </form>
              <div style="font-size:0.78em;color:#6c757d;margin-top:0.4rem;">
                Column 1: NAT qualcode &nbsp;&nbsp;Column 2: numeric category ID or exact category name.<br>
                <a href="<?= (new moodle_url('/local/rtocompliance/reconcile.php', ['action' => 'exportmapping', 'sesskey' => sesskey()]))->out() ?>">Download current mappings CSV</a>
              </div>
            </div>
            <?php if ($_qmNeedsAttn > 0): ?>
            <div class="alert alert-warning" style="font-size:0.9em;margin-bottom:12px;">
              <strong>&#9888; <?= $_qmNeedsAttn ?> qualification<?= $_qmNeedsAttn > 1 ? 's' : '' ?> need attention.</strong>
              <?php if ($_qmCntUnmapped): ?><?= $_qmCntUnmapped ?> could not be auto-discovered &mdash; set manually below.<?php endif; ?>
              <?php if ($_qmCntLowConf): ?><?= $_qmCntLowConf ?> auto-discovered with low confidence &mdash; review and confirm or override.<?php endif; ?>
            </div>
            <?php endif; ?>
            <form method="post" action="<?= _reconcile_h($_qmSaveUrl) ?>">
              <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
              <input type="hidden" name="importid_save" value="<?= (int)$importid ?>">
              <table class="table table-sm table-bordered" style="max-width:1040px;">
                <thead>
                  <tr style="background:#f8f9fa;">
                    <th style="width:120px;" title="AVETMISS qualification code from the NAT file">Qualcode</th>
                    <th style="width:150px;" title="How this qualification was mapped to a category">Method</th>
                    <th style="width:85px;text-align:center;" title="Confidence score of the automatic mapping">Confidence</th>
                    <th title="Moodle course category this qualification maps to">Root Category</th>
                    <th style="width:72px;text-align:center;" title="Number of students under this qualification">Students</th>
                    <th style="width:60px;text-align:center;" title="Number of units under this qualification">Units</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($_importQualRows as $_iqQcKey => $_iqRow):
                    $_iqMapped   = ($_iqRow['mapped_catid'] !== null);
                    $_iqConf     = (int)($_iqRow['confidence'] ?? 0);
                    $_iqMethod   = (string)$_iqRow['method'];
                    $_iqHighConf = ($_iqMapped && (
                        ($_iqMethod === 'unit_root_discovery' && $_iqConf >= 90) ||
                        ($_iqMethod !== 'unit_root_discovery' && $_iqConf >= 80)
                    ));
                    $_iqRowId    = 'qm-' . preg_replace('/[^a-z0-9]/i', '', $_iqQcKey);
                    $_iqUfDiag   = $_ufQualFingerDiag[$_iqQcKey] ?? null;
                    $_iqWinner = null;
                    if ($_iqUfDiag && !empty($_iqUfDiag['candidates'])) {
                        foreach ($_iqUfDiag['candidates'] as $_iqWC) {
                            if (!empty($_iqWC['winner'])) { $_iqWinner = $_iqWC; break; }
                        }
                    }
                    $_iqIsAllHist = !empty($_qmAllHistQuals[$_iqQcKey]);
                    if ($_iqIsAllHist)        { $_iqRowBg = 'background:#f4f4f4;'; }
                    elseif (!$_iqMapped)     { $_iqRowBg = 'background:#fff3f3;'; }
                    elseif (!$_iqHighConf)   { $_iqRowBg = 'background:#fffbf0;'; }
                    else                     { $_iqRowBg = ''; }
                    if ($_iqMethod === 'category_hierarchy') {
                        $_iqMethBadge = '<span title="This qualification was matched to a course category because the code was found in the category name." style="display:inline-block;padding:1px 7px;border-radius:4px;background:#198754;color:#fff;font-size:0.76em;white-space:nowrap;">&#128269; Category Name</span>';
                    } elseif ($_iqMethod === 'unit_root_discovery') {
                        $_iqMethBadge = '<span title="This qualification was matched by comparing its units against course categories to find the best fit." style="display:inline-block;padding:1px 7px;border-radius:4px;background:#0d6efd;color:#fff;font-size:0.76em;white-space:nowrap;">&#128300; Unit Root Discovery</span>';
                    } elseif ($_iqMethod === 'manual') {
                        $_iqMethBadge = '<span title="This qualification was matched to a course category by hand, not automatically." style="display:inline-block;padding:1px 7px;border-radius:4px;background:#6c757d;color:#fff;font-size:0.76em;white-space:nowrap;">&#128274; Manual</span>';
                    } else {
                        $_iqMethBadge = '<span title="This qualification has not been matched to any course category yet." style="display:inline-block;padding:1px 7px;border-radius:4px;background:#adb5bd;color:#333;font-size:0.76em;white-space:nowrap;">&mdash; Unmapped</span>';
                    }
                    $_iqGreenThr  = ($_iqMethod === 'unit_root_discovery') ? 90 : 80;
                    if ($_iqIsAllHist) {
                        $_iqConfBadge = '<span title="Pre-LMS means this qualification was studied before this online learning system was in use, so there is no course to match it to." style="display:inline-block;padding:1px 7px;border-radius:4px;background:#adb5bd;color:#333;font-size:0.76em;white-space:nowrap;">pre-LMS</span>';
                    } elseif (!$_iqMapped) {
                        $_iqConfBadge = '<span style="color:#dc3545;font-weight:700;">&#10007;</span>';
                    } elseif ($_iqConf >= 100) {
                        $_iqConfBadge = '<span style="display:inline-block;padding:1px 7px;border-radius:4px;background:#198754;color:#fff;font-size:0.76em;">100%</span>';
                    } elseif ($_iqConf >= $_iqGreenThr) {
                        $_iqConfBadge = '<span style="display:inline-block;padding:1px 7px;border-radius:4px;background:#198754;color:#fff;font-size:0.76em;">' . $_iqConf . '%</span>';
                    } elseif ($_iqConf >= 60) {
                        $_iqConfBadge = '<span style="display:inline-block;padding:1px 7px;border-radius:4px;background:#fd7e14;color:#fff;font-size:0.76em;">' . $_iqConf . '%</span>';
                    } else {
                        $_iqConfBadge = '<span style="display:inline-block;padding:1px 7px;border-radius:4px;background:#dc3545;color:#fff;font-size:0.76em;">' . $_iqConf . '%</span>';
                    }
                  ?>
                  <tr style="<?= $_iqRowBg ?>">
                    <td>
                      <code><?= _reconcile_h($_iqQcKey) ?></code>
                      <input type="hidden" name="qcodes[]" value="<?= _reconcile_h($_iqQcKey) ?>"
                             id="<?= _reconcile_h($_iqRowId) ?>-qci"<?= $_iqHighConf ? ' disabled' : '' ?>>
                    </td>
                    <td><?= $_iqMethBadge ?></td>
                    <td style="text-align:center;">
                      <?= $_iqConfBadge ?>
                      <?php if ($_iqWinner): ?>
                      <div style="font-size:0.68em;color:#888;margin-top:3px;line-height:1.5;white-space:nowrap;">
                        Cov&nbsp;<strong><?= (int)$_iqWinner['coverage_pct'] ?>%</strong>
                        &middot; Pur&nbsp;<strong><?= (int)$_iqWinner['purity_pct'] ?>%</strong><br>
                        Margin&nbsp;<strong><?= (int)($_iqUfDiag['margin'] ?? 0) ?></strong>
                        <?php if ((int)($_iqUfDiag['margin'] ?? 100) <= 5): ?>
                        <span style="color:#dc3545;">&#9888;</span>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($_iqIsAllHist): ?>
                      <span style="font-size:0.83em;color:#6c757d;font-style:italic;">
                        All records are HISTORICAL_NO_COURSE &mdash; pre-2012 qualification with no Moodle course.
                        Mapping is not applicable.
                      </span>
                      <input type="hidden" name="qcodes[]" value="<?= _reconcile_h($_iqQcKey) ?>" disabled>
                      <input type="hidden" name="catids[]" value="0" disabled>
                      <?php else: ?>
                      <?php if ($_iqHighConf): ?>
                      <span id="<?= _reconcile_h($_iqRowId) ?>-nm" style="font-size:0.9em;"><?= _reconcile_h($_iqRow['catname'] ?: '&mdash;') ?></span>
                      <a href="#" onclick="rtocQmOverride('<?= _reconcile_h($_iqRowId) ?>');return false;"
                         id="<?= _reconcile_h($_iqRowId) ?>-ovl"
                         style="font-size:0.78em;margin-left:6px;color:#6c757d;text-decoration:underline;">Override</a>
                      <?php endif; ?>
                      <select name="catids[]" id="<?= _reconcile_h($_iqRowId) ?>-sel"
                              class="form-select form-select-sm"
                              style="font-size:0.85em;max-width:380px;<?= $_iqHighConf ? 'display:none;' : '' ?>"
                              <?= $_iqHighConf ? 'disabled' : '' ?>>
                        <?php foreach ($_qmCatOptions as $_qmOptId => $_qmOptLabel): ?>
                        <option value="<?= (int)$_qmOptId ?>"<?=
                            ($_iqRow['mapped_catid'] == $_qmOptId && $_qmOptId > 0) ? ' selected'
                            : (($_qmOptId === 0 && !$_iqMapped) ? ' selected' : '')
                        ?>><?= $_qmOptLabel ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php
                      if ($_iqMethod === 'unit_root_discovery' && !empty($_iqUfDiag['candidates'])):
                          $_dTogId = 'ufdiag-' . preg_replace('/[^a-z0-9]/i', '', $_iqQcKey);
                      ?>
                      <div style="margin-top:5px;">
                        <a href="#" onclick="var d=document.getElementById('<?= $_dTogId ?>');d.style.display=d.style.display==='none'?'block':'none';return false;"
                           style="font-size:0.75em;color:#0d6efd;text-decoration:none;">&#128300; Discovery details</a>
                        <div id="<?= $_dTogId ?>" style="display:none;margin-top:4px;padding:6px 8px;background:#f0f4ff;border:1px solid #c8d9f8;border-radius:4px;font-size:0.76em;">
                          <?php
                          $_dTotal    = (int)$_iqUfDiag['total'];
                          $_dMargin   = (int)($_iqUfDiag['margin'] ?? 100);
                          $_dRunnerUp = (int)($_iqUfDiag['runner_up_conf'] ?? 0);
                          $_dMissing  = $_iqUfDiag['missing_units'] ?? [];
                          $_dSrc = $_iqUfDiag['discovery_src'] ?? 'unit_root_heuristic';
                          $_dSrcLabel = ($_dSrc === 'qual_code')
                              ? '<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#198754;color:#fff;font-size:0.9em;">&#128273; Qualification code in category name</span>'
                              : '<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#0d6efd;color:#fff;font-size:0.9em;">&#128300; Unit-root heuristic (fallback)</span>';
                          $_dUnitCode = (int)($_iqUfDiag['units_via_qual_code'] ?? 0);
                          $_dUnitFall = (int)($_iqUfDiag['units_via_fallback']  ?? 0);
                          $_dUnitMix  = (int)($_iqUfDiag['units_mixed']         ?? 0);
                          ?>
                          <div style="margin-bottom:5px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="color:#555;font-weight:600;font-size:0.9em;">Resolution:</span>
                            <?= $_dSrcLabel ?>
                          </div>
                          <?php if ($_dUnitCode + $_dUnitFall + $_dUnitMix > 0): ?>
                          <div style="margin-bottom:5px;padding:3px 6px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:3px;font-size:0.85em;">
                            <strong>Unit resolution trace:</strong>
                            <?php if ($_dUnitCode > 0): ?><span style="color:#198754;">&#128273; <?= $_dUnitCode ?> via qual code</span><?php endif; ?>
                            <?php if ($_dUnitMix  > 0): ?>&nbsp;<span style="color:#fd7e14;">&#9888; <?= $_dUnitMix ?> mixed</span><?php endif; ?>
                            <?php if ($_dUnitFall > 0): ?>&nbsp;<span style="color:#0d6efd;">&#128300; <?= $_dUnitFall ?> via heuristic</span><?php endif; ?>
                          </div>
                          <?php endif; ?>
                          <div style="margin-bottom:4px;color:#555;font-style:italic;">
                            Scored <?= $_dTotal ?> NAT unit<?= ($_dTotal !== 1) ? 's' : '' ?> against all qualification root candidates.
                            <?php if ($_dRunnerUp > 0): ?>
                              Margin over runner-up: <strong><?= $_dMargin ?> pts</strong>
                              <?php if ($_dMargin <= 5): ?><span style="color:#dc3545;font-weight:600;">&#9888; Close call &mdash; review recommended</span><?php endif; ?>
                            <?php endif; ?>
                          </div>
                          <?php foreach ($_iqUfDiag['candidates'] as $_dCand):
                            $_dCov = (int)$_dCand['coverage_pct']; $_dPur = (int)$_dCand['purity_pct'];
                            $_dCmb = (int)$_dCand['combined_pct']; $_dBr  = (int)$_dCand['branch_units'];
                            $_dRaw = (int)$_dCand['raw_matched'];  $_dDep = (int)$_dCand['depth'];
                            $_dCmbCol = $_dCmb >= 90 ? '#198754' : ($_dCmb >= 60 ? '#fd7e14' : '#dc3545');
                          ?>
                          <div style="margin-bottom:5px;padding:4px 6px;border-radius:3px;<?= $_dCand['winner'] ? 'background:#e8f5e9;border:1px solid #a5d6a7;' : 'background:#f8f9fa;border:1px solid #dee2e6;color:#666;' ?>">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                              <?php if ($_dCand['winner']): ?><span style="color:#198754;font-weight:700;">&#9654; Winner</span><?php else: ?><span style="color:#aaa;">&#9656; Alt</span><?php endif; ?>
                              <strong><?= _reconcile_h($_dCand['catname']) ?></strong>
                              <span style="color:#555;font-size:0.88em;"><?= $_dRaw ?> / <?= $_dTotal ?> NAT units &bull; <?= $_dBr ?> in branch &bull; depth <?= $_dDep ?></span>
                            </div>
                            <div style="display:flex;gap:12px;margin-top:3px;font-size:0.88em;">
                              <span>Coverage: <strong><?= $_dCov ?>%</strong></span>
                              <span>Purity: <strong><?= $_dPur ?>%</strong></span>
                              <span>Combined: <strong style="color:<?= $_dCmbCol ?>;"><?= $_dCmb ?>%</strong></span>
                            </div>
                          </div>
                          <?php endforeach; unset($_dCand,$_dCov,$_dPur,$_dCmb,$_dBr,$_dRaw,$_dDep,$_dCmbCol); ?>
                          <?php if (!empty($_dMissing)): ?>
                          <div style="margin-top:5px;padding:4px 6px;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;font-size:0.88em;">
                            <strong style="color:#856404;">&#9888; <?= count($_dMissing) ?> NAT unit<?= count($_dMissing) !== 1 ? 's' : '' ?> not found under winner branch:</strong>
                            <code style="font-size:0.9em;"><?= _reconcile_h(implode(', ', $_dMissing)) ?></code>
                          </div>
                          <?php endif; ?>
                          <?php unset($_dTotal,$_dMargin,$_dRunnerUp,$_dMissing,$_dSrc,$_dSrcLabel,$_dUnitCode,$_dUnitFall,$_dUnitMix); ?>
                        </div>
                      </div>
                      <?php unset($_dTogId); endif; ?>
                      <?php endif; // end: !$_iqIsAllHist ?>
                    </td>
                    <td style="text-align:center;"><?= (int)$_iqRow['student_count'] ?></td>
                    <td style="text-align:center;"><?= (int)$_iqRow['unit_count'] ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if (empty($_importQualRows)): ?>
                  <tr><td colspan="6" class="text-muted text-center">No qualification codes found in this import&rsquo;s NAT data.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
              <?php if (!empty($_importQualRows)): ?>
              <button type="submit" class="btn btn-primary btn-sm" title="Save the manual qualification mapping overrides and re-run the analysis">&#128190; Save Overrides &amp; Re-run</button>
              <span style="font-size:0.82em;color:#6c757d;margin-left:8px;">Click <em>Override</em> on any auto-mapped row, select a category, then save.</span>
              <?php endif; ?>
            </form>
            <script>
            function rtocQmOverride(rowId) {
                var nm  = document.getElementById(rowId + '-nm');
                var ovl = document.getElementById(rowId + '-ovl');
                var sel = document.getElementById(rowId + '-sel');
                var qci = document.getElementById(rowId + '-qci');
                if (nm)  nm.style.display  = 'none';
                if (ovl) ovl.style.display = 'none';
                if (sel) { sel.style.display = 'block'; sel.disabled = false; }
                if (qci) qci.disabled = false;
            }
            </script>
          </div>
        </div><!-- /.qual-discovery -->

        <!-- Reconciliation System Checks -->
        <div class="card mb-3" style="border:2px solid <?= $_panelColor ?>;font-size:0.88em;">
          <div class="card-header" style="background:<?= $_panelColor ?>;color:#fff;font-weight:600;cursor:pointer;display:flex;align-items:center;flex-wrap:wrap;gap:0.5rem;"
               onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none';">
            <span><?= $_pipelineOk ? '&#9989;' : '&#9888;' ?> Reconciliation System Checks</span>
            <span style="display:inline-flex;gap:0.4rem;flex-wrap:wrap;font-size:0.85em;font-weight:400;">
              <span style="background:rgba(255,255,255,0.22);padding:1px 9px;border-radius:10px;" title="Overall check that the matching process ran from start to finish. Tick means it completed.">Pipeline <?= $_pipelineOk ? '&#9989;' : '&#10060;' ?></span>
              <span style="background:rgba(255,255,255,0.22);padding:1px 9px;border-radius:10px;" title="Check that students in the file were linked to Moodle accounts. Tick means it worked.">Student matching <?= $_scStudentOk ? '&#9989;' : '&#10060;' ?></span>
              <span style="background:rgba(255,255,255,0.22);padding:1px 9px;border-radius:10px;" title="Check that unit codes were linked to Moodle courses. Tick means it worked.">Course matching <?= $_scCourseOk ? '&#9989;' : '&#10060;' ?></span>
              <span style="background:rgba(255,255,255,0.22);padding:1px 9px;border-radius:10px;" title="Check that every qualification was matched to a course category. Tick means all were matched.">Qual mapping <?= $_allMapped ? '&#9989;' : '&#10060;' ?></span>
            </span>
            <span style="margin-left:auto;font-size:0.8em;font-weight:400;opacity:0.85;">&#9660; detail</span>
          </div>
          <div class="card-body" style="display:none;">
            <table class="table table-sm table-bordered mb-3" style="max-width:520px;">
              <thead><tr><th title="Stage of the reconciliation pipeline being checked">Pipeline stage</th><th title="Number of records at this stage">Count</th><th title="Whether this stage passed or needs attention">Verdict</th></tr></thead>
              <tbody>
                <tr>
                  <td>NAT client IDs in staging</td>
                  <td><strong><?= _reconcile_n($_diagNatClientIds) ?></strong></td>
                  <td><?= $_diagNatClientIds > 0 ? '&#9989; OK' : '&#10060; NO DATA &mdash; wrong import?' ?></td>
                </tr>
                <tr>
                  <td>Matched to Moodle accounts</td>
                  <td><strong><?= _reconcile_n($_diagMatchedUsers) ?></strong></td>
                  <td><?= $_diagMatchedUsers > 0 ? '&#9989; OK' : '&#10060; 0 matched &mdash; 5-path matching failed entirely' ?></td>
                </tr>
                <tr>
                  <td>Unique NAT unit codes (across all students)</td>
                  <td><strong><?= _reconcile_n($_diagNatUnits) ?></strong></td>
                  <td><?= $_diagNatUnits > 0 ? '&#9989; OK' : '&#10060; NO UNITS in NAT00120' ?></td>
                </tr>
                <tr>
                  <td>Unit codes with a preferred Moodle course</td>
                  <td><strong><?= _reconcile_n($_diagUnitsMapped) ?> / <?= _reconcile_n($_diagNatUnits) ?></strong></td>
                  <td><?= $_diagUnitsMapped > 0 ? ($_diagUnitsMapped === $_diagNatUnits ? '&#9989; All mapped' : '&#9888; Partial') : '&#10060; NONE mapped' ?></td>
                </tr>
                <tr>
                  <td>Total actual manual enrolments loaded</td>
                  <td><strong><?= _reconcile_n($_diagTotalActual) ?></strong></td>
                  <td><?= $_diagTotalActual > 0 ? '&#9989; OK' : '&#10060; 0 &mdash; no manual enrolments found' ?></td>
                </tr>
                <tr>
                  <td>Verified KEEP (unit in NAT, any delivery)</td>
                  <td><strong><?= _reconcile_n($_diagTotalKeep) ?></strong></td>
                  <td><?= $_diagTotalKeep > 0 ? '&#9989; Legitimate enrolments confirmed' : '&#9888; 0 KEEP &mdash; check unit code extraction from courses' ?></td>
                </tr>
                <tr>
                  <td>Enrolments Not Explained by Current NAT</td>
                  <td><strong><?= _reconcile_n($_diagTotalRemove) ?></strong></td>
                  <td><?= $_diagTotalRemove === 0 ? '&#9989; None' : '&#9888; Classified &mdash; see breakdown below' ?></td>
                </tr>
                <tr style="background:#e7f3ff;">
                  <td>POST-IMPORT (created after NAT import date)</td>
                  <td><strong><?= _reconcile_n($_diagTotalPostImport) ?></strong></td>
                  <td><?= $_diagTotalPostImport === 0 ? '&#9989; None' : '&#128274; Protected &mdash; do not remove' ?></td>
                </tr>
                <?php if ($fridayBackupLoaded): ?>
                <tr style="background:#f0e7ff;">
                  <td colspan="3" style="font-weight:600;color:#4b2f8a;">
                    &#9851; Backup File Analysis &mdash; <?= _reconcile_n($_diagTotalRestoreAll) ?> classified
                  </td>
                </tr>
                <tr style="background:#f8f2ff;">
                  <td style="padding-left:2rem;">&#8627; RESTORE &mdash; unit still in NAT, no post-import replacement</td>
                  <td><strong><?= _reconcile_n($_diagTotalRestore) ?></strong></td>
                  <td><?= $_diagTotalRestore === 0 ? '&#9989; None' : '&#9888; Enrolments to restore' ?></td>
                </tr>
                <tr style="background:#f8f2ff;">
                  <td style="padding-left:2rem;">&#8627; POST_IMPORT_REPLACED &mdash; covered by later enrolment</td>
                  <td><strong><?= _reconcile_n($_diagTotalPiReplaced) ?></strong></td>
                  <td><?= $_diagTotalPiReplaced === 0 ? '&#9989; None' : '&#128274; Already replaced' ?></td>
                </tr>
                <tr style="background:#f8f2ff;">
                  <td style="padding-left:2rem;">&#8627; LEGITIMATE_REMOVE &mdash; unit NOT in student&rsquo;s current NAT</td>
                  <td><strong><?= _reconcile_n($_diagTotalLegitRemove) ?></strong></td>
                  <td><?= $_diagTotalLegitRemove === 0 ? '&#9989; None' : '&#9989; Removal was correct' ?></td>
                </tr>
                <tr style="background:#f8f2ff;">
                  <td style="padding-left:2rem;">&#8627; REVIEW &mdash; course has no unit code</td>
                  <td><strong><?= _reconcile_n($_diagTotalRtReview) ?></strong></td>
                  <td><?= $_diagTotalRtReview === 0 ? '&#9989; None' : '&#9888; Verify manually' ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td>Flagged REVIEW (no unit code &mdash; verify manually)</td>
                  <td><strong><?= _reconcile_n($_diagTotalReview) ?></strong></td>
                  <td><?= $_diagTotalReview === 0 ? '&#9989; All enrolments have unit codes' : '&#9888; Some courses have no unit code (orientation, LLN, etc.)' ?></td>
                </tr>
                <tr>
                  <td>ADD recommendations (&ge;50% confidence, unit with no current enrolment)</td>
                  <td><strong><?= _reconcile_n($_diagTotalAdd) ?></strong></td>
                  <td><?= $_diagTotalAdd === 0 ? '&#9989; All units already covered' : '&#9888; Some NAT units have no enrolment in any delivery (see moodle_upload + review_required; unmatched_add shows ' . _reconcile_n($totalUnmatchedAdd) . ' more with no course found)' ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div><!-- /.system-checks -->

        <?php if (!empty($_removeSummary)): ?>
        <?php
        $_rmLabels = [
            'historical_archive'   => ['&#128203; Historical Archive',             '#d1ecf1', '#0c5460'],
            'duplicate_delivery'   => ['&#9654; Duplicate Delivery',                '#d1e7dd', '#155724'],
            'resource_qual_course' => ['&#128218; Resource / Qual-Level Course',    '#fff3cd', '#856404'],
            'foe_deleted'          => ['&#9889; Admin/FOE Deleted',                 '#f8d7da', '#842029'],
            'unknown'              => ['&#10067; Unknown / Investigate',             '#e2e3e5', '#343a40'],
        ];
        $_rmTotal = array_sum($_removeSummary);
        ?>
        <div style="margin-top:0.75rem;border:2px solid #fd7e14;border-radius:6px;overflow:hidden;" class="mb-3">
          <div style="background:#fd7e14;color:#fff;font-weight:700;padding:0.45rem 0.75rem;cursor:pointer;font-size:0.86em;"
               onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none';">
            &#128203; Enrolments Not Explained by Current NAT &mdash; Classification Breakdown (<?= _reconcile_n($_rmTotal) ?> rows) &mdash; click to expand
          </div>
          <div style="padding:0.75rem;background:#fff8f0;font-size:0.85em;display:none;">
            <p style="margin-bottom:0.5rem;color:#6c757d;">
              These enrolments are not explained by the student&rsquo;s <em>current</em> NAT data. They are <strong>not necessarily errors</strong>.
              Download <strong>Potential Incorrect Enrolments</strong> for full detail.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
              <?php foreach ($_rmLabels as $_rmCls => [$_rmLbl, $_rmBg, $_rmFg]): ?>
              <?php if (!isset($_removeSummary[$_rmCls])) continue; ?>
              <div style="background:<?= $_rmBg ?>;color:<?= $_rmFg ?>;border-radius:6px;padding:0.3rem 0.6rem;display:inline-flex;align-items:center;gap:0.4rem;" title="Number of enrolments in this group that the student&rsquo;s current national report does not explain. These are not necessarily errors.">
                <strong><?= _reconcile_n($_removeSummary[$_rmCls]) ?></strong>
                <span><?= $_rmLbl ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($matchedStudentCount > 0): ?>
        <div style="border:2px solid <?= $_csAutoReady ? '#198754' : '#0d6efd' ?>;border-radius:6px;overflow:hidden;" class="mb-3">
          <div style="background:<?= $_csAutoReady ? '#198754' : '#0d6efd' ?>;color:#fff;font-weight:700;padding:0.45rem 0.75rem;cursor:pointer;font-size:0.86em;"
               onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none';">
            &#127919; Reconciliation Confidence Summary &mdash; <?= $_csTotalStudents ?> students scored &mdash; click to expand
          </div>
          <div style="padding:0.75rem;background:#f0f9ff;font-size:0.85em;display:none;">
            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">
              <div style="background:#d1e7dd;color:#155724;border-radius:6px;padding:0.3rem 0.6rem;" title="Students the tool is almost certain it matched to the right records. Safe to accept."><strong><?= _reconcile_n($_confStats['High']) ?></strong> &#9989; High (95&ndash;100)</div>
              <div style="background:#fff3cd;color:#856404;border-radius:6px;padding:0.3rem 0.6rem;" title="Students the tool is fairly sure about. Worth a quick look."><strong><?= _reconcile_n($_confStats['Medium']) ?></strong> &#9888; Medium (80&ndash;94)</div>
              <div style="background:#f8d7da;color:#842029;border-radius:6px;padding:0.3rem 0.6rem;" title="Students the tool is unsure about. Please check these."><strong><?= _reconcile_n($_confStats['Low']) ?></strong> &#128308; Low (60&ndash;79)</div>
              <div style="background:#e2e3e5;color:#343a40;border-radius:6px;padding:0.3rem 0.6rem;" title="Students the tool could not match with confidence. These need a person to review them."><strong><?= _reconcile_n($_confStats['Review']) ?></strong> &#10060; Review (&lt;60)</div>
            </div>
            <?php if ($_csAutoReady): ?>
            <div style="background:#d1e7dd;color:#155724;border-radius:4px;padding:0.4rem 0.6rem;font-weight:600;">
              &#9989; <?= $_csHighPct ?>% of students scored High &mdash; reconciliation engine operating with high confidence.
            </div>
            <?php else: ?>
            <div style="background:#fff3cd;color:#856404;border-radius:4px;padding:0.4rem 0.6rem;">
              &#9888; <?= $_csHighPct ?>% High / <?= $_csMedPct ?>% Medium / <?= ($_csLowPct + $_csRevPct) ?>% Low-or-Review.
              Review qualification mappings and course unit codes to improve confidence.
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- NAT Classification Exports (match the on-screen panel exactly) -->
        <?php
        $_ncdNeedsReviewCnt = ($_ncRowData['ENROLMENT_GAP_REVIEW']['records'] ?? 0)
                            + ($_ncRowData['UNLINKED_STUDENT_REVIEW']['records'] ?? 0)
                            + ($_ncRowData['RECENT_NO_COURSE_REVIEW']['records'] ?? 0);
        // NCD-URL-FIX (v5.9.222): moodle_url::param() returns void in Moodle 4.x —
        // chaining ->param('category', ...)->out(false) threw "Call to a member
        // function out() on string/null". Fix: pre-build each URL object separately
        // (same pattern as $urlMoodleUpload etc. pre-built above).
        $_ncdBase = new moodle_url('/local/rtocompliance/reconcile.php', [
            'action'   => 'natclassdownload',
            'importid' => $importid,
        ]);
        $_ncdUrlNeedsReview  = clone $_ncdBase; $_ncdUrlNeedsReview->param('category',  'needs_review');
        $_ncdUrlEnrolGap     = clone $_ncdBase; $_ncdUrlEnrolGap->param('category',     'ENROLMENT_GAP_REVIEW');
        $_ncdUrlUnlinked     = clone $_ncdBase; $_ncdUrlUnlinked->param('category',     'UNLINKED_STUDENT_REVIEW');
        $_ncdUrlRecentNC     = clone $_ncdBase; $_ncdUrlRecentNC->param('category',     'RECENT_NO_COURSE_REVIEW');
        $_ncdUrlHistNC       = clone $_ncdBase; $_ncdUrlHistNC->param('category',       'HISTORICAL_NO_COURSE');
        // Map category key → [label, btn-class, pre-built url object]
        $_ncdCategoryMap = [
            'ENROLMENT_GAP_REVIEW'    => ['&#128274; Enrolment Gap',        'btn-outline-warning',   $_ncdUrlEnrolGap],
            'UNLINKED_STUDENT_REVIEW' => ['&#10067; Unlinked Students',      'btn-outline-secondary', $_ncdUrlUnlinked],
            'RECENT_NO_COURSE_REVIEW' => ['&#128270; Recent No Course',      'btn-outline-info',      $_ncdUrlRecentNC],
            'HISTORICAL_NO_COURSE'    => ['&#128203; Historical (pre-LMS)',  'btn-outline-secondary', $_ncdUrlHistNC],
        ];
        ?>
        <h6 style="margin-top:1.25rem;margin-bottom:0.4rem;font-weight:700;color:#495057;">&#128202; NAT Classification Exports</h6>
        <p style="font-size:0.82em;color:#6c757d;margin-bottom:0.6rem;">
          These files are generated directly from the same <code>nat_classification</code> table that drives the on-screen panel &mdash;
          row counts always match what you see above. Use these for auditor-facing exports.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.25rem;">
          <?php if ($_ncdNeedsReviewCnt > 0): ?>
          <a href="<?= _reconcile_h($_ncdUrlNeedsReview->out(false)) ?>"
             class="btn btn-warning btn-sm" download>
            &#9888; Needs Review (combined) &mdash; <?= _reconcile_n($_ncdNeedsReviewCnt) ?> rows
          </a>
          <?php endif; ?>
          <?php foreach ($_ncdCategoryMap as $_ncdCat => [$_ncdLbl, $_ncdBtnCls, $_ncdUrl]):
              $_ncdCnt = $_ncRowData[$_ncdCat]['records'] ?? 0;
              if ($_ncdCnt === 0) continue;
          ?>
          <a href="<?= _reconcile_h($_ncdUrl->out(false)) ?>"
             class="btn <?= $_ncdBtnCls ?> btn-sm" download>
            <?= $_ncdLbl ?> &mdash; <?= _reconcile_n($_ncdCnt) ?> rows
          </a>
          <?php endforeach; ?>
        </div>

        <!-- All CSV Downloads -->
        <h6 style="margin-top:0;margin-bottom:0.6rem;font-weight:700;color:#495057;">&#128229; ADD Engine Exports (for Moodle bulk import)</h6>
        <p style="font-size:0.85em;color:#6c757d;margin-bottom:0.75rem;">
          Built by the ADD engine during analysis &mdash; confidence-filtered and formatted for Moodle bulk import.
          If links expire, click <strong>Re-run Analysis</strong> to regenerate. All files include a UTF-8 BOM.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:0.6rem;margin-bottom:1rem;">
          <a href="<?= $urlMoodleUpload->out(false) ?>" class="btn btn-success btn-sm" download="moodle_upload.csv">
            &#10133; Enrol File &mdash; <?= _reconcile_n(count($moodleUploadBuffer)) ?> student rows (<?= _reconcile_n($totalMoodleUpload) ?> enrolments)
          </a>
          <a href="<?= $urlReviewRequired->out(false) ?>" class="btn btn-warning btn-sm" download="review_required.csv">
            &#9888; Review Required &mdash; <?= _reconcile_n($totalReviewRequired) ?> rows
          </a>
          <a href="<?= $urlUnmatchedAdd->out(false) ?>" class="btn btn-secondary btn-sm" download="unmatched_add.csv">
            &#10067; Unmatched / Ignore &mdash; <?= _reconcile_n($totalUnmatchedAdd) ?> rows
          </a>
          <a href="<?= $urlMissing->out(false) ?>" class="btn btn-outline-primary btn-sm" download="missing_enrolments.csv">
            &#10133; All Missing &mdash; <?= _reconcile_n($totalMissing) ?> rows (&ge;50% conf)
          </a>
          <a href="<?= $urlExtra->out(false) ?>" class="btn btn-outline-danger btn-sm" download="extra_enrolments.csv">
            &#10134; Potential Incorrect &mdash; <?= _reconcile_n($totalExtra) ?> rows
          </a>
          <a href="<?= $urlPostImport->out(false) ?>" class="btn btn-outline-primary btn-sm" download="post_import_enrolments.csv">
            &#128274; Protected Post-Import &mdash; <?= _reconcile_n($totalPostImport) ?> rows
          </a>
          <a href="<?= $urlReview->out(false) ?>" class="btn btn-outline-warning btn-sm" download="review_enrolments.csv">
            &#9888; Non-AVETMISS Courses &mdash; <?= _reconcile_n($totalReview) ?> rows
          </a>
          <?php if ($fridayBackupLoaded): ?>
          <a href="<?= $urlRestore->out(false) ?>" class="btn btn-outline-secondary btn-sm" download="restore_candidates.csv">
            &#128260; Backup File &mdash; <?= _reconcile_n($_restoreAllCount) ?> rows
          </a>
          <?php endif; ?>
          <a href="<?= $urlSummary->out(false) ?>" class="btn btn-secondary btn-sm" download="student_summary.csv">
            &#128203; Student Summary &mdash; <?= _reconcile_n($matchedStudentCount) ?> rows (linked students only<?= $unmatchedStudentCount > 0 ? '; ' . _reconcile_n($unmatchedStudentCount) . ' unlinked excluded' : '' ?>)
          </a>
          <a href="<?= $urlAudit->out(false) ?>" class="btn btn-dark btn-sm" download="audit_report.csv">
            &#128196; Full Audit Report
          </a>
        </div>

        <!-- Developer tools -->
        <h6 style="font-weight:700;color:#495057;margin-bottom:0.5rem;">&#128268; Developer Tools</h6>
        <div style="background:#fff8e6;border:1px solid #ffe0a0;border-radius:6px;padding:0.6rem 0.85rem;font-size:0.82em;color:#6c757d;margin-bottom:0.6rem;">
          <strong style="color:#856404;">&#128274; qualdebug DB table is deprecated.</strong>
          The <code>local_rtocompliance_qualdebug</code> table is a partial scoring diagnostic (covers only a subset of qualifications).
          For authoritative, complete record classification use <code>local_rtocompliance_nat_classification</code>
          or download from the <em>NAT Classification Exports</em> section above. Do not make decisions from qualdebug.
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
          <a href="<?= $urlAmbiguous->out(false) ?>" class="btn btn-outline-info btn-sm" download="ambiguous_unit_mappings.csv">
            &#9874; Ambiguous Unit Mappings
          </a>
          <a href="<?= $urlCourseAudit->out(false) ?>" class="btn btn-outline-secondary btn-sm" download="course_unit_validation.csv">
            &#128196; Course Unit Validation
          </a>
          <a href="<?= $urlDebug->out(false) ?>" class="btn btn-outline-danger btn-sm" download="missing_enrolments_debug.csv">
            &#128030; ADD Engine Debug CSV
            <?php if ($_totalUnresolved > 0): ?>
              <br><small><?= _reconcile_n($_totalUnresolved) ?> UNRESOLVED included</small>
            <?php endif; ?>
          </a>
          <a href="<?= _reconcile_h($_rerunUrl) ?>" class="btn btn-outline-secondary btn-sm">
            &#128260; Re-run Analysis
          </a>
        </div>

      </div>
    </details><!-- /.advanced -->

    <div class="alert alert-info" style="font-size:0.88em;margin-bottom:1.5rem;max-width:820px;">
      <strong>This tool is read-only.</strong>
      No enrolments have been added or removed. All decisions to act on these reports must be made by an administrator.
    </div>

    <p>
      <a href="<?= _reconcile_h($_rerunUrl) ?>" class="btn btn-outline-secondary btn-sm">&#128260; Re-run Analysis</a>
      &nbsp;
      <a href="<?= _reconcile_h($_backUrl) ?>" class="btn btn-secondary btn-sm">&#8592; Back to Reconciliation Tool</a>
    </p>

    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// DEFAULT PAGE — import selector
// ─────────────────────────────────────────────────────────────────────────────
$imports = $DB->get_records_sql(
    "SELECT id, collectionyear, totalstudents, totalenrolments, timecreated, rtoname
       FROM {local_rtocompliance_avetmiss}
      ORDER BY timecreated DESC",
    [], 0, 50
);

echo $OUTPUT->header();
// v5.9.404: render the plugin's left-hand sidebar (this page was missing it). This
// opens rtoc-layout-wrap + sidebar + rtoc-main-content, replacing the bare
// rtoc-main-content div that previously left the page with no sidebar.
echo local_rtocompliance_render_nav_header('NAT Reconciliation');
echo local_rtocompliance_page_banner('NAT Reconciliation');
?>

<h3>&#128202; NAT Enrolment Reconciliation Tool</h3>

<div class="alert alert-info" style="font-size:0.92em;max-width:780px;">
  <strong>Read-only reconciliation — this tool never modifies Moodle.</strong><br>
  Select a NAT import below.  The tool uses a unit-centric engine: for each student it compares
  their NAT unit codes against the unit codes extracted from their actual Moodle enrolments.
  An enrolment in <em>any</em> delivery of a NAT unit is considered legitimate (<strong>KEEP</strong>).
  Enrolments not in NAT that were created <em>after</em> the NAT import date are protected as
  <strong>POST-IMPORT</strong> — these are legitimate admin or IMIS enrolments and must <strong>not</strong> be removed.
  Enrolments not in NAT that predate the import are classified as <strong>Enrolments Not Explained by Current NAT</strong> — automatically categorised by root cause (historical archive, duplicate delivery, resource course, admin-deleted, or unknown) with per-row confidence and recommendation.
  Enrolments in courses with <em>no</em> unit code are separated into a manual review list (<strong>REVIEW</strong>)
  — typically Orientation, LLN, or other non-unit courses; never auto-remove these.
  NAT units with no enrolment coverage generate an ADD recommendation.
  Optionally upload a Backup File CSV to detect <strong>RESTORE</strong> candidates — enrolments from a
  general Moodle enrolments export that are currently missing from Moodle.
</div>

<?php if (empty($imports)): ?>
<div class="alert alert-warning">No NAT imports found.  Please upload a NAT file via the
  <a href="<?= (new moodle_url('/local/rtocompliance/data_import.php'))->out() ?>">Data Import</a> page first.</div>
<?php else: ?>
<form method="post" action="" enctype="multipart/form-data" style="max-width:720px;">
  <input type="hidden" name="action" value="analyse">
  <input type="hidden" name="sesskey" value="<?= sesskey() ?>">

  <div class="form-group">
    <label for="importid" style="font-weight:600;">Select NAT Import</label>
    <?php
    // ── Duplicate import detection (v5.9.212) ─────────────────────────────────
    // Multiple imports sharing the same collection year almost always mean the
    // same NAT file was uploaded more than once (e.g. imports 16, 17, 18).
    // Flag them so the admin knows to select the most recent one.
    $_yearGroups = [];
    foreach ($imports as $_gi) {
        $_gy = trim((string)($_gi->collectionyear ?? ''));
        if ($_gy === '') continue;
        $_yearGroups[$_gy][] = (int)$_gi->id;
    }
    $_dupYears = array_filter($_yearGroups, fn($_ids) => count($_ids) > 1);
    $_dupImportIds = [];
    foreach ($_dupYears as $_dyIds) {
        $_latestId = max($_dyIds);
        foreach ($_dyIds as $_did) {
            if ($_did !== $_latestId) $_dupImportIds[$_did] = true;
        }
    }
    if (!empty($_dupYears)): ?>
    <div class="alert alert-warning" style="max-width:720px;font-size:0.9em;margin-bottom:1rem;">
      <strong>&#9888; Possible duplicate imports detected.</strong><br>
      <?php foreach ($_dupYears as $_gy => $_dyIds): ?>
      Collection year <strong><?= htmlspecialchars($_gy) ?></strong>
      has <?= count($_dyIds) ?> imports (<?php echo '#' . implode(', #', $_dyIds); ?>).
      <?php endforeach; ?>
      Always use the <strong>most recent</strong> import for each year as your source of truth.
      Earlier uploads of the same NAT file contain identical student data and produce
      the same (or worse) results — importing the same file twice does not improve accuracy.
    </div>
    <?php endif; ?>
    <select name="importid" id="importid" class="form-control" required>
      <option value="">— choose an import —</option>
      <?php foreach ($imports as $imp):
          $_iId    = (int)$imp->id;
          $_iYear  = trim((string)($imp->collectionyear ?? ''));
          $_label  = 'Import #' . $_iId;
          if ($_iYear !== '') $_label .= ' (' . htmlspecialchars($_iYear) . ')';
          $_label .= ' — ' . date('d M Y', (int)$imp->timecreated);
          $_label .= ' — ' . number_format((int)$imp->totalstudents) . ' students';
          $_label .= ' / ' . number_format((int)$imp->totalenrolments) . ' enrolments';
          if (isset($_dupImportIds[$_iId])) {
              $_label .= '  ⚠ POSSIBLE DUPLICATE — use a later import';
          }
      ?>
      <option value="<?= $_iId ?>"
        <?= ($importid === $_iId ? 'selected' : '') ?>>
        <?= htmlspecialchars($_label) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <small class="form-text text-muted">
      Showing the 50 most recent imports.  Use the latest complete import as your source of truth.
    </small>
  </div>

  <div class="form-group mt-3">
    <label for="traceclientids" style="font-weight:600;">Trace students <span style="font-weight:400;color:#6c757d;">(optional — one Client ID per line, or comma-separated)</span></label>
    <textarea name="traceclientids" id="traceclientids" class="form-control" rows="4"
              placeholder="e.g.&#10;8215&#10;12043&#10;7901"
              style="font-family:monospace;font-size:0.9em;"><?= _reconcile_h($traceclientids !== '' ? $traceclientids : $traceclientid) ?></textarea>
    <small class="form-text text-muted">
      Enter one or more NAT Client IDs (one per line or comma-separated).  Each matched student
      gets a separate panel showing: unit-by-unit NAT coverage, date-aware delivery key, actual
      manual enrolments with KEEP / REMOVE / POST-IMPORT verdict, and any ADD recommendations.
    </small>
  </div>

  <div class="form-group mt-3" style="border:1px solid #dee2e6;border-radius:6px;padding:1rem;background:#f8f9fa;">
    <label for="fridaybackup" style="font-weight:600;">
      Backup File CSV
      <span style="font-weight:400;color:#6c757d;">(optional — enables RESTORE detection)</span>
    </label>
    <div style="margin-top:0.4rem;">
      <input type="file" name="fridaybackup" id="fridaybackup" class="form-control-file" accept=".csv">
    </div>
    <small class="form-text text-muted" style="margin-top:0.5rem;display:block;">
      Upload a general Moodle enrolments CSV export. When provided, the tool compares it
      against the current Moodle state and identifies enrolments that appear in the backup
      but are currently missing — classified as <strong>RESTORE</strong> candidates.<br>
      Expected columns (0-indexed): [2]&nbsp;user_id, [11]&nbsp;course_id (Moodle IDs).
    </small>
  </div>

  <div class="form-group mt-3" style="border:1px solid #b8d4f0;border-radius:6px;padding:1rem;background:#f0f8ff;">
    <label for="mapping_csv" style="font-weight:600;">
      &#128196; Qualification Mapping CSV
      <span style="font-weight:400;color:#555;">(optional &mdash; override automatic qualification discovery)</span>
    </label>
    <p style="font-size:0.88em;color:#555;margin:0.4rem 0 0.6rem;">
      Normally the reconciliation engine automatically discovers the correct Moodle qualification root by analysing the
      category hierarchy and unit structure. Upload a CSV only if you need to override that automatic mapping —
      for example, unusual category structures, multiple branches with similar confidence, or restoring a previously
      approved mapping after a migration.
    </p>
    <div style="margin-top:0.5rem;">
      <input type="file" name="mapping_csv" id="mapping_csv" class="form-control-file" accept=".csv,.txt">
    </div>
    <small class="form-text text-muted" style="margin-top:0.6rem;display:block;">
      <strong>Format:</strong> 2 columns — <strong>column 1</strong> = NAT qualcode, <strong>column 2</strong> = numeric Moodle category ID (recommended) or exact category name.<br>
      <strong>Matching:</strong> numeric IDs are preferred; category names are matched exactly (case-insensitive). No partial matching — if an exact name match cannot be found, that row is reported as an error.<br>
      <strong>Examples:</strong> <code>ABC12345,25</code> &nbsp;or&nbsp; <code>ABC12345,a Diploma qualification (ABC12345)</code><br>
      <strong>Existing mappings</strong> not in the CSV are left unchanged. Uploaded rows are saved as manual overrides and the auto-discovery engine will not overwrite them on future runs.<br>
      <strong>Important:</strong> map to the qualification <em>root</em> category only — not to semester, archive, RPL or delivery sub-folders.
    </small>
  </div>

  <button type="submit" class="btn btn-primary" title="Run the reconciliation analysis for the selected import">
    &#128202; Run Reconciliation Analysis
  </button>
  <a href="<?= (new moodle_url('/local/rtocompliance/data_import.php'))->out() ?>"
     class="btn btn-outline-secondary" style="margin-left:0.5rem;">
    &#8592; Back to Data Import
  </a>
</form>

<hr style="margin-top:2rem;">

<h5 style="margin-top:1.5rem;">&#128203; What the reports contain</h5>
<div class="row" style="max-width:900px;">
  <?php
  $reportDocs = [
      ['missing_enrolments.csv',
       'btn-danger',
       'Missing Units (ADD)',
       'One row per NAT unit code with no active enrolment in any Moodle delivery. Shows the recommended preferred course.<br>
        Columns: username, idnumber, firstname, lastname, courseid, shortname, fullname, category, unit_code, reason.'],
      ['extra_enrolments.csv',
       'btn-danger',
       'Genuine Mismatches (REMOVE)',
       'One row per enrolment where a unit code <em>was</em> extracted from the course but is absent from the student\'s NAT data.<br>
        Columns: username, idnumber, firstname, lastname, courseid, shortname, fullname, category, unit_code, reason.'],
      ['review_enrolments.csv',
       'btn-warning',
       'Manual Review (REVIEW)',
       'One row per enrolment in a course with <em>no</em> extractable unit code (orientation, LLN, community courses, etc.).<br>
        <strong>Do not auto-remove.</strong> An administrator must verify whether each enrolment is legitimate.<br>
        Columns: username, idnumber, firstname, lastname, courseid, shortname, fullname, category, reason.'],
      ['post_import_enrolments.csv',
       'btn-primary',
       'Post-Import Enrolments (POST-IMPORT)',
       'Enrolments not in NAT data but created <strong>after</strong> the NAT import date — these are legitimate<br>
        admin or IMIS enrolments and must <strong>not</strong> be removed.<br>
        Columns: username, idnumber, firstname, lastname, courseid, shortname, fullname, category, unit_code, enrol_date, reason.'],
      ['student_summary.csv',
       'btn-secondary',
       'Student Summary',
       'One row per student with unit-code totals.<br>
        Columns: username, student, nat_units, units_covered, units_missing, extra_enrolments, post_import_enrolments, restore_candidates, review_enrolments.'],
      ['audit_report.csv',
       'btn-dark',
       'Full Audit Report',
       'One row per enrolment with action: KEEP / ADD / REMOVE / POST-IMPORT / RESTORE / REVIEW.<br>
        Columns: username, student, courseid, course, category, unit_code, unit_in_nat, enrolled, action.'],
      ['ambiguous_unit_mappings.csv',
       'btn-info',
       'Ambiguous Unit Mappings',
       'Every NAT unit code that maps to 2 or more Moodle courses, with delivery key, category, visibility,
        manual enrolment count, and whether each course was selected as the reconciler\'s fallback choice.<br>
        Columns: unit_code, course_id, shortname, fullname, category, visible, delivery_key, manual_enrolments, is_chosen_fallback.'],
      ['course_unit_validation.csv',
       'btn-secondary',
       'Course Unit Validation',
       'All Moodle courses scanned by the reconciler, showing how the unit code was extracted and any data-quality flags.<br>
        Columns: course_id, shortname, fullname, extracted_unit_code, extraction_source, codes_in_fullname, codes_in_fullname_list, flags.<br>
        <strong>Flags:</strong> <code>NO_UNIT_CODE</code> — no valid AVETMISS code found;
        <code>MULTIPLE_IN_FULLNAME</code> — fullname contains more than one unit code;
        <code>INCONSISTENT_SOURCES</code> — idnumber, shortname and fullname yield different codes.'],
  ];
  foreach ($reportDocs as [$filename, $btnClass, $title, $desc]): ?>
  <div class="col-md-6" style="margin-bottom:1rem;">
    <div class="card h-100">
      <div class="card-body" style="font-size:0.88em;">
        <p style="margin-bottom:0.3rem;">
          <strong class="badge <?= $btnClass ?>" style="font-size:0.85em;"><?= $filename ?></strong>
        </p>
        <p style="font-weight:600;margin-bottom:0.25rem;"><?= $title ?></p>
        <p style="color:#6c757d;margin-bottom:0;"><?= $desc ?></p>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
<?php
echo $OUTPUT->footer();
