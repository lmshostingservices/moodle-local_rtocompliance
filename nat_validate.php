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
 * RTO Compliance plugin — nat_validate.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// AVETMISS Pre-Submission Validation Report  (local_rtocompliance)
//
// READ-ONLY.  Performs NO database writes of any kind.  Safe to run on
// production at any time.
//
// PURPOSE
// -------
// Checks every reportable student and every enrolment against the NCVER /
// AVETMISS edit rules BEFORE the RTO exports their NAT files, so that a
// quarterly submission is not rejected at the collection gateway.  Findings are
// classified as ERROR (will fail / be rejected at NCVER) or WARNING (should be
// reviewed).  The whole finding set can be streamed as CSV via ?export=csv.
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/avetmiss_codes.php');

use local_rtocompliance\avetmiss_codes;

admin_externalpage_setup('local_rtocompliance_natvalidate');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

raise_memory_limit(MEMORY_EXTRA);
\core_php_time_limit::raise(300);

$export = optional_param('export', '', PARAM_ALPHA);

$strtitle = (get_string_manager()->string_exists('natvalidate', 'local_rtocompliance'))
    ? get_string('natvalidate', 'local_rtocompliance')
    : 'AVETMISS Validation';

$PAGE->set_url(new moodle_url('/local/rtocompliance/nat_validate.php'));
$PAGE->set_title($strtitle);
$PAGE->set_heading($strtitle);

// ─── Sensible working-set caps (process with recordsets, not one giant load) ──
const NATVAL_MAX_STUDENTS = 8000;    // reportable students processed
const NATVAL_MAX_ENROL    = 30000;   // enrolments processed
const NATVAL_ROWS_PER_CARD = 100;    // rows shown per finding card

// ─── Validation code sets ────────────────────────────────────────────────────
$sexcodes        = avetmiss_codes::get_sex_codes();               // M / F / @
$indigcodes      = avetmiss_codes::get_indigenous_status_codes(); // 1-4 / @
$langcodes       = avetmiss_codes::get_language_codes();
$countrycodes    = avetmiss_codes::get_country_codes();
$labourcodes     = avetmiss_codes::get_labour_force_status_codes();
$schoolcodes     = avetmiss_codes::get_school_level_codes();
$atschoolcodes   = avetmiss_codes::get_at_school_flag_codes();    // Y / N
$statecodes      = avetmiss_codes::get_state_codes();
$deliverycodes   = avetmiss_codes::get_delivery_mode_nat_codes(); // 10/20/30/40/90
$fundingcodes    = avetmiss_codes::get_funding_source_national_codes();

// AVETMISS 2.3 outcome set that NCVER accepts on a training-activity record.
$validoutcomes = ['20','30','40','51','52','60','61','70','81','82','85','90'];
// Outcomes that represent a FINAL result — these REQUIRE an activity end date.
$finaloutcomes = ['20','30','40','51','52','60','61','81','82'];

// ─── Small helpers ────────────────────────────────────────────────────────────
if (!function_exists('natval_blank')) {
    function natval_blank($v): bool {
        return $v === null || trim((string)$v) === '';
    }
}
if (!function_exists('natval_valid_code')) {
    // A value is valid if it is a key in the supplied code=>label map. The maps
    // already carry the accepted "not stated" placeholders (@, @@, @@@@) for the
    // fields where NCVER permits them, so isset() covers "not stated is valid".
    function natval_valid_code($v, array $codes): bool {
        return isset($codes[trim((string)$v)]);
    }
}
if (!function_exists('natval_fullname')) {
    // Lazily resolve userid -> display name with a static cache so we never fire
    // one query per row. Read-only.
    function natval_fullname($userid): string {
        static $cache = [];
        global $DB;
        $userid = (int)$userid;
        if (!$userid) {
            return '';
        }
        if (array_key_exists($userid, $cache)) {
            return $cache[$userid];
        }
        try {
            $u = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
            $cache[$userid] = $u ? fullname($u) : '';
        } catch (\Throwable $e) {
            $cache[$userid] = '';
        }
        return $cache[$userid];
    }
}

// ─── Finding collector ────────────────────────────────────────────────────────
$findings = [];   // flat list: each = severity, category, ref, field, message
$add = function (string $severity, string $category, string $ref, string $field, string $message) use (&$findings) {
    $findings[] = [
        'severity' => $severity,
        'category' => $category,
        'ref'      => $ref,
        'field'    => $field,
        'message'  => $message,
    ];
};

$studentschecked   = 0;
$enrolmentschecked = 0;
$studentstruncated = false;
$enroltruncated    = false;
$dataerror         = '';   // surfaced non-fatally in the UI

$studenttable   = 'local_rtocompliance_students';
$enrolmenttable = 'local_rtocompliance_enrolments';

$studentmeta = [];   // studentid => ['clientid'=>.., 'userid'=>..]
$reportable  = [];   // studentid => true (has >= 1 enrolment row)

// ─── Pass 0: which students are reportable + lightweight id->clientid map ──────
if ($DB->get_manager()->table_exists($enrolmenttable)) {
    try {
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT studentid FROM {" . $enrolmenttable . "} WHERE studentid > 0"
        );
        foreach ($rs as $row) {
            $reportable[(int)$row->studentid] = true;
        }
        $rs->close();
    } catch (\Throwable $e) {
        $dataerror .= 'Could not read enrolment ownership: ' . $e->getMessage() . ' ';
    }
}

if ($DB->get_manager()->table_exists($studenttable)) {
    try {
        $rs = $DB->get_recordset($studenttable, null, 'id ASC', 'id, clientid, userid', 0, NATVAL_MAX_STUDENTS + 5000);
        foreach ($rs as $s) {
            $studentmeta[(int)$s->id] = ['clientid' => $s->clientid, 'userid' => (int)$s->userid];
        }
        $rs->close();
    } catch (\Throwable $e) {
        $dataerror .= 'Could not read student index: ' . $e->getMessage() . ' ';
    }
}

// ─── Pass 1: STUDENT rules (reportable students only) ─────────────────────────
if ($DB->get_manager()->table_exists($studenttable)) {
    try {
        $fields = 'id, userid, clientid, usi, usiverified, dateofbirth, sex, suburb, statecode, '
            . 'postcode, indigenousstatus, languageathome, countryofbirth, labourforcestatus, '
            . 'highestschoollevel, atschoolflag, disabilityflag, disabilitytypes, prioreducationflag, '
            . 'priorachevement1, priorachevement2, priorachevement3, priorachevement4, profilecomplete';
        $rs = $DB->get_recordset($studenttable, null, 'id ASC', $fields);
        foreach ($rs as $s) {
            $sid = (int)$s->id;
            if (empty($reportable[$sid])) {
                continue;   // prospect-only record — do not flag
            }
            if ($studentschecked >= NATVAL_MAX_STUDENTS) {
                $studentstruncated = true;
                break;
            }
            $studentschecked++;

            $clientid = !natval_blank($s->clientid) ? trim($s->clientid) : ('student#' . $sid);
            $name = natval_fullname($s->userid);
            $ref = $clientid . ($name !== '' ? ' (' . $name . ')' : '');

            // USI --------------------------------------------------------------
            $usires = avetmiss_codes::validate_usi($s->usi);
            if (natval_blank($s->usi) || empty($usires['valid'])) {
                $why = natval_blank($s->usi) ? 'is missing' : ('is invalid (' . ($usires['error'] ?? 'bad format') . ')');
                $add('ERROR', 'USI', $ref, 'usi', "USI $why. A valid verified USI is required for every reportable student.");
            } else if ((int)$s->usiverified === 0) {
                $add('WARNING', 'USI', $ref, 'usiverified', 'USI is present and valid but has not been verified with the USI Registry — certificates cannot be issued until verified.');
            }

            // Date of birth ----------------------------------------------------
            // v6.3.10: pre-1970 DOBs are NEGATIVE timestamps and are valid; only
            // 0, a pre-1900 date (clearly bad data) or a future date is an error.
            if (natval_blank($s->dateofbirth) || !is_numeric($s->dateofbirth) || (int)$s->dateofbirth === 0
                    || (int)$s->dateofbirth < -2208988800 || (int)$s->dateofbirth > time()) {
                $add('ERROR', 'Date of birth', $ref, 'dateofbirth', 'Date of birth is empty or invalid — NCVER requires a real date of birth.');
            }

            // Sex (real value required; '@' Not stated is an ERROR here) --------
            $sexval = trim((string)$s->sex);
            if ($sexval !== 'M' && $sexval !== 'F') {
                $add('ERROR', 'Sex', $ref, 'sex', "Sex code '" . ($sexval === '' ? '(blank)' : $sexval) . "' is not a valid gender — a real value (M or F) is required.");
            }

            // Postcode (4 digits unless overseas '99' / placeholder) -----------
            $pc = trim((string)$s->postcode);
            $isoverseas = (trim((string)$s->statecode) === '99');
            $pcplaceholder = ($pc === '' || $pc === '0000' || $pc === '9999');
            if (!$isoverseas && !preg_match('/^\d{4}$/', $pc)) {
                $add('ERROR', 'Postcode', $ref, 'postcode', "Postcode '" . ($pc === '' ? '(blank)' : $pc) . "' is not a valid 4-digit Australian postcode.");
            } else if ($isoverseas && !$pcplaceholder && !preg_match('/^\d{4}$/', $pc)) {
                $add('ERROR', 'Postcode', $ref, 'postcode', "Overseas postcode '" . $pc . "' must be 4 digits or the '0000' placeholder.");
            }

            // State code -------------------------------------------------------
            if (natval_blank($s->statecode) || !natval_valid_code($s->statecode, $statecodes)) {
                $add('ERROR', 'State', $ref, 'statecode', "State code '" . trim((string)$s->statecode) . "' is not a valid AVETMISS state/territory code.");
            }

            // Country of birth -------------------------------------------------
            if (natval_blank($s->countryofbirth) || !natval_valid_code($s->countryofbirth, $countrycodes)) {
                $add('ERROR', 'Country of birth', $ref, 'countryofbirth', "Country of birth '" . trim((string)$s->countryofbirth) . "' is blank or not a valid SACC country code.");
            }

            // Language at home -------------------------------------------------
            if (natval_blank($s->languageathome) || !natval_valid_code($s->languageathome, $langcodes)) {
                $add('ERROR', 'Language', $ref, 'languageathome', "Language spoken at home '" . trim((string)$s->languageathome) . "' is blank or not a valid ASCL language code.");
            }

            // Indigenous status (‘@’ Not stated is permitted) ------------------
            if (!natval_valid_code($s->indigenousstatus, $indigcodes)) {
                $add('ERROR', 'Indigenous status', $ref, 'indigenousstatus', "Indigenous status '" . trim((string)$s->indigenousstatus) . "' is not a valid code (1-4, or @ Not stated).");
            }

            // Labour force status (‘@@’ Not stated permitted) ------------------
            if (!natval_valid_code($s->labourforcestatus, $labourcodes)) {
                $add('ERROR', 'Labour force status', $ref, 'labourforcestatus', "Labour force status '" . trim((string)$s->labourforcestatus) . "' is not a valid AVETMISS code.");
            }

            // Highest school level (‘@@’ Not stated permitted) -----------------
            if (!natval_valid_code($s->highestschoollevel, $schoolcodes)) {
                $add('ERROR', 'School level', $ref, 'highestschoollevel', "Highest school level completed '" . trim((string)$s->highestschoollevel) . "' is not a valid code.");
            }

            // At-school flag (Y/N only) ----------------------------------------
            if (!natval_valid_code($s->atschoolflag, $atschoolcodes)) {
                $add('ERROR', 'At-school flag', $ref, 'atschoolflag', "At-school flag '" . trim((string)$s->atschoolflag) . "' must be Y or N.");
            }

            // Disability flag = Y but no disability-type detail (NAT00090) -----
            $distypes = isset($s->disabilitytypes) ? trim((string)$s->disabilitytypes) : '';
            if (trim((string)$s->disabilityflag) === 'Y' && $distypes === '') {
                $add('WARNING', 'Disability detail', $ref, 'disabilitytypes', 'Disability flag is Yes but no NAT00090 disability-type detail is recorded — capture the disability type(s) before submission.');
            }

            // Prior-education flag = Y but no prior-ed detail (NAT00100) --------
            $haspriored = false;
            foreach (['priorachevement1', 'priorachevement2', 'priorachevement3', 'priorachevement4'] as $pf) {
                if (isset($s->$pf) && !natval_blank($s->$pf)) {
                    $haspriored = true;
                    break;
                }
            }
            if (trim((string)$s->prioreducationflag) === 'Y' && !$haspriored) {
                $add('WARNING', 'Prior education detail', $ref, 'prioreducationflag', 'Prior-education flag is Yes but no NAT00100 prior-achievement detail is recorded — capture the prior qualification(s).');
            }
        }
        $rs->close();
    } catch (\Throwable $e) {
        $dataerror .= 'Student validation aborted: ' . $e->getMessage() . ' ';
    }
}

// ─── Pass 2: ENROLMENT rules ──────────────────────────────────────────────────
if ($DB->get_manager()->table_exists($enrolmenttable)) {
    try {
        $fields = 'id, studentid, programcode, unitcode, unitname, activitystartdate, activityenddate, '
            . 'scheduledhours, outcomeidentifier, deliverymode, fundingsourcenat, vetflag, status, assessmentdate';
        $rs = $DB->get_recordset($enrolmenttable, null, 'id ASC', $fields);
        $now = time();
        foreach ($rs as $en) {
            if ($enrolmentschecked >= NATVAL_MAX_ENROL) {
                $enroltruncated = true;
                break;
            }
            $enrolmentschecked++;

            $unit = !natval_blank($en->unitcode) ? trim($en->unitcode) : '(blank unit)';
            $sidmeta = $studentmeta[(int)$en->studentid] ?? null;
            $cid = ($sidmeta && !natval_blank($sidmeta['clientid'])) ? trim($sidmeta['clientid']) : ('student#' . (int)$en->studentid);
            $ref = $unit . ' / ' . $cid;

            $outcome  = trim((string)$en->outcomeidentifier);
            $start    = (int)$en->activitystartdate;
            $end      = (int)$en->activityenddate;
            $delivery = trim((string)$en->deliverymode);

            // Outcome identifier -----------------------------------------------
            if ($outcome === '' || !in_array($outcome, $validoutcomes, true)) {
                $add('ERROR', 'Outcome identifier', $ref, 'outcomeidentifier', "Outcome identifier '" . ($outcome === '' ? '(blank)' : $outcome) . "' is not a valid AVETMISS outcome code.");
            }

            // Final outcome with no end date -----------------------------------
            if (in_array($outcome, $finaloutcomes, true) && $end <= 0) {
                $add('ERROR', 'End date missing', $ref, 'activityenddate', "Outcome '$outcome' is a final result but there is no activity end date.");
            }

            // Start date missing -----------------------------------------------
            if ($start <= 0) {
                $add('ERROR', 'Start date missing', $ref, 'activitystartdate', 'Activity start date is missing — every training activity record requires a start date.');
            }

            // End before start -------------------------------------------------
            if ($start > 0 && $end > 0 && $end < $start) {
                $add('ERROR', 'Date order', $ref, 'activityenddate', 'Activity end date is earlier than the activity start date.');
            }

            // Program code blank -----------------------------------------------
            if (natval_blank($en->programcode)) {
                $add('ERROR', 'Program code', $ref, 'programcode', 'Program / qualification code is blank.');
            }

            // Unit code blank --------------------------------------------------
            if (natval_blank($en->unitcode)) {
                $add('ERROR', 'Unit code', $ref, 'unitcode', 'Subject / unit code is blank.');
            }

            // Continuing (70) but end date already in the past -----------------
            if ($outcome === '70' && $end > 0 && $end < $now) {
                $add('WARNING', 'Continuing/past end', $ref, 'outcomeidentifier', 'Outcome is 70 (continuing) but the activity end date is in the past — this looks finalised yet is reported as still in progress.');
            }

            // Delivery mode invalid --------------------------------------------
            if (!natval_valid_code($en->deliverymode, $deliverycodes)) {
                $add('WARNING', 'Delivery mode', $ref, 'deliverymode', "Delivery mode '" . $delivery . "' is not a valid national delivery-mode code (10/20/30/40/90).");
            }

            // Funding source (national) invalid --------------------------------
            if (!natval_valid_code($en->fundingsourcenat, $fundingcodes)) {
                $add('WARNING', 'Funding source', $ref, 'fundingsourcenat', "National funding source '" . trim((string)$en->fundingsourcenat) . "' is not a valid code.");
            }

            // Scheduled hours empty for a delivered unit -----------------------
            $hoursempty = ($en->scheduledhours === null || (string)$en->scheduledhours === '' || (int)$en->scheduledhours <= 0);
            $isrplorct  = ($outcome === '51' || $outcome === '60');
            if ($hoursempty && !$isrplorct && $delivery !== '90') {
                $add('WARNING', 'Scheduled hours', $ref, 'scheduledhours', 'Scheduled hours are empty for a delivered unit (not RPL/Credit Transfer) — a nominal hours value is expected.');
            }
        }
        $rs->close();
    } catch (\Throwable $e) {
        $dataerror .= 'Enrolment validation aborted: ' . $e->getMessage() . ' ';
    }
}

// ─── Tally ────────────────────────────────────────────────────────────────────
$errorcount   = 0;
$warningcount = 0;
$bycategory   = [];   // category => ['ERROR'=>n,'WARNING'=>n,'rows'=>[...]]
foreach ($findings as $f) {
    if ($f['severity'] === 'ERROR') {
        $errorcount++;
    } else {
        $warningcount++;
    }
    $cat = $f['category'];
    if (!isset($bycategory[$cat])) {
        $bycategory[$cat] = ['ERROR' => 0, 'WARNING' => 0, 'rows' => []];
    }
    $bycategory[$cat][$f['severity']]++;
    $bycategory[$cat]['rows'][] = $f;
}
// Order categories: those containing errors first, then by total count desc.
uasort($bycategory, function ($a, $b) {
    $ae = $a['ERROR'] > 0 ? 1 : 0;
    $be = $b['ERROR'] > 0 ? 1 : 0;
    if ($ae !== $be) {
        return $be - $ae;
    }
    $at = $a['ERROR'] + $a['WARNING'];
    $bt = $b['ERROR'] + $b['WARNING'];
    return $bt - $at;
});

// ─── CSV EXPORT — must happen BEFORE any HTML output ──────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nat_validation_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Severity', 'Category', 'Client ID / Unit', 'Field', 'Message']);
    foreach ($findings as $f) {
        fputcsv($fh, [$f['severity'], $f['category'], $f['ref'], $f['field'], $f['message']]);
    }
    fclose($fh);
    exit;
}

// ─── HTML OUTPUT ──────────────────────────────────────────────────────────────
$ready       = ($errorcount === 0);
$csvurl      = new moodle_url('/local/rtocompliance/nat_validate.php', ['export' => 'csv', 'sesskey' => sesskey()]);
$refreshurl  = new moodle_url('/local/rtocompliance/nat_validate.php');

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
// v5.9.404: render the plugin's left-hand sidebar (this page was missing it).
echo local_rtocompliance_render_nav_header($strtitle);
echo local_rtocompliance_page_banner($strtitle);
?>
<style>
.natval-wrap { max-width: 1180px; margin: 0 auto; font-size: 0.95rem; }
.natval-hero {
    border-radius: 16px; padding: 26px 30px; margin: 8px 0 26px;
    color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.12);
    background: <?php echo $ready ? 'linear-gradient(135deg,#0f9d58,#087f43)' : 'linear-gradient(135deg,#d93025,#a5150c)'; ?>;
}
.natval-hero h2 { margin: 0 0 4px; font-size: 1.9rem; font-weight: 700; color:#fff; }
.natval-hero .verdict { font-size: 1.15rem; opacity: .95; }
.natval-stats { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 20px; }
.natval-stat {
    background: rgba(255,255,255,.16); border-radius: 12px; padding: 14px 20px;
    min-width: 120px; backdrop-filter: blur(2px);
}
.natval-stat .n { font-size: 1.7rem; font-weight: 700; line-height: 1; }
.natval-stat .l { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .9; margin-top: 6px; }
.natval-toolbar { margin: 0 0 22px; display: flex; gap: 10px; flex-wrap: wrap; align-items:center; }
.natval-btn {
    display: inline-block; padding: 9px 18px; border-radius: 8px; text-decoration: none;
    font-weight: 600; font-size: .9rem; border: 1px solid transparent;
}
.natval-btn-primary { background: #1a73e8; color: #fff; }
.natval-btn-ghost { background: #fff; color: #1a73e8; border-color: #d2e3fc; }
.natval-note { color:#5f6368; font-size:.86rem; }
.natval-card {
    border: 1px solid #e3e6ea; border-radius: 12px; margin: 0 0 18px;
    overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.natval-card-head {
    display: flex; align-items: center; gap: 12px; padding: 14px 18px;
    background: #f8f9fb; border-bottom: 1px solid #e3e6ea; cursor: pointer;
}
.natval-card-head h3 { margin: 0; font-size: 1.05rem; flex: 1; font-weight: 600; }
.natval-pill {
    display: inline-block; padding: 3px 11px; border-radius: 999px;
    font-size: .78rem; font-weight: 700; white-space: nowrap;
}
.pill-error { background: #fce8e6; color: #c5221f; }
.pill-warn  { background: #fef7e0; color: #b06000; }
.pill-ok    { background: #e6f4ea; color: #137333; }
.natval-table { width: 100%; border-collapse: collapse; font-size: .87rem; }
.natval-table th, .natval-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #eef0f2; vertical-align: top; }
.natval-table th { background: #fbfbfc; font-weight: 600; color: #3c4043; }
.natval-table tr:last-child td { border-bottom: none; }
.sev-tag { font-weight: 700; font-size: .74rem; padding: 2px 8px; border-radius: 6px; }
.sev-ERROR { background: #fce8e6; color: #c5221f; }
.sev-WARNING { background: #fef7e0; color: #b06000; }
.natval-more { padding: 10px 18px; background: #fbfbfc; color: #5f6368; font-size: .84rem; }
.natval-empty { padding: 30px; text-align: center; color: #5f6368; }
.natval-alert { background:#fef7e0; border:1px solid #f0d78a; color:#7a5b00; padding:10px 14px; border-radius:8px; margin-bottom:16px; font-size:.86rem; }
details.natval-card > summary { list-style: none; }
details.natval-card > summary::-webkit-details-marker { display: none; }
</style>

<div class="natval-wrap">

  <div class="natval-hero">
    <h2><?php echo $ready ? 'Ready to submit' : ($errorcount . ' error' . ($errorcount === 1 ? '' : 's') . ' to fix'); ?></h2>
    <div class="verdict">
      <?php
      echo $ready
          ? 'No blocking AVETMISS edit-rule errors were found in the checked records.'
          : 'These records will be rejected by NCVER unless the errors below are corrected before export.';
      ?>
    </div>
    <div class="natval-stats">
      <div class="natval-stat" title="How many student records were looked at in this check."><div class="n"><?php echo $studentschecked; ?></div><div class="l">Students checked</div></div>
      <div class="natval-stat" title="How many course enrolment records were looked at in this check."><div class="n"><?php echo $enrolmentschecked; ?></div><div class="l">Enrolments checked</div></div>
      <div class="natval-stat" title="Serious problems that must be fixed. Records with an error will be rejected by the national reporting system unless corrected."><div class="n"><?php echo $errorcount; ?></div><div class="l">Errors</div></div>
      <div class="natval-stat" title="Possible problems worth checking. Warnings will not stop your report, but may still need a look."><div class="n"><?php echo $warningcount; ?></div><div class="l">Warnings</div></div>
    </div>
  </div>

  <div class="natval-toolbar">
    <a class="natval-btn natval-btn-primary" href="<?php echo $csvurl->out(false); ?>">Export all findings (CSV)</a>
    <a class="natval-btn natval-btn-ghost" href="<?php echo $refreshurl->out(false); ?>">Re-run validation</a>
    <span class="natval-note">Read-only report &mdash; nothing is written to the database.</span>
  </div>

  <?php if ($dataerror !== '') { ?>
    <div class="natval-alert"><strong>Note:</strong> <?php echo s($dataerror); ?></div>
  <?php } ?>
  <?php if ($studentstruncated || $enroltruncated) { ?>
    <div class="natval-alert">
      Working-set cap reached
      <?php if ($studentstruncated) { echo '(first ' . NATVAL_MAX_STUDENTS . ' reportable students shown) '; } ?>
      <?php if ($enroltruncated) { echo '(first ' . NATVAL_MAX_ENROL . ' enrolments shown) '; } ?>
      &mdash; export the CSV for the full run or narrow the data set.
    </div>
  <?php } ?>

  <?php if (empty($findings)) { ?>
    <div class="natval-card"><div class="natval-empty">
      &#10003; No validation findings. Every checked student and enrolment passed the AVETMISS edit rules.
    </div></div>
  <?php } else { ?>
    <?php foreach ($bycategory as $cat => $grp) {
        $rows = $grp['rows'];
        $total = count($rows);
        $shown = min($total, NATVAL_ROWS_PER_CARD);
        $openattr = $grp['ERROR'] > 0 ? ' open' : '';
    ?>
      <details class="natval-card"<?php echo $openattr; ?>>
        <summary class="natval-card-head">
          <h3><?php echo s($cat); ?></h3>
          <?php if ($grp['ERROR'] > 0) { ?>
            <span class="natval-pill pill-error" title="Number of serious problems in this group that must be fixed before the report can be submitted."><?php echo $grp['ERROR']; ?> error<?php echo $grp['ERROR'] === 1 ? '' : 's'; ?></span>
          <?php } ?>
          <?php if ($grp['WARNING'] > 0) { ?>
            <span class="natval-pill pill-warn" title="Number of possible problems in this group worth checking. These will not block the report but may still need attention."><?php echo $grp['WARNING']; ?> warning<?php echo $grp['WARNING'] === 1 ? '' : 's'; ?></span>
          <?php } ?>
        </summary>
        <table class="natval-table">
          <thead>
            <tr><th style="width:90px;" title="Whether the finding blocks submission (Error) or should be reviewed (Warning)">Severity</th><th style="width:230px;" title="Student client identifier, or unit and student the finding relates to">Client ID / Unit</th><th style="width:150px;" title="Data field that triggered this finding">Field</th><th title="Explanation of the issue and what NCVER expects">Message</th></tr>
          </thead>
          <tbody>
          <?php for ($i = 0; $i < $shown; $i++) { $f = $rows[$i]; ?>
            <tr>
              <td><span class="sev-tag sev-<?php echo $f['severity']; ?>"><?php echo $f['severity']; ?></span></td>
              <td><?php echo s($f['ref']); ?></td>
              <td><?php echo s($f['field']); ?></td>
              <td><?php echo s($f['message']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        <?php if ($total > $shown) { ?>
          <div class="natval-more">+<?php echo ($total - $shown); ?> more in this category &mdash; use <strong>Export all findings (CSV)</strong> for the full list.</div>
        <?php } ?>
      </details>
    <?php } ?>
  <?php } ?>

</div>
<?php
echo $OUTPUT->footer();
