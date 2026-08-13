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

require_once(__DIR__ . '/../../config.php');
require_login();
ini_set('memory_limit', '512M');

require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

// Safe mb_ wrappers — mb_strlen/mb_substr are uncatchable fatals if mbstring missing.
function rtoc_mb_strlen($s) {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
function rtoc_mb_substr($s, $start, $len) {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
}

/**
 * Safely decode the vocationalqualifications field for display.
 *
 * The field is a free-text textarea but may contain JSON objects/arrays
 * if populated via a TGA qualification lookup or data import (e.g.
 * {"code":"BSB50420","title":"Diploma of Business"}).
 * Returns a plain-text string ready for display, or '' on unrecoverable data.
 *
 * @param  string $raw  Raw DB value
 * @return array  ['text' => string, 'error' => bool]
 *   'error' is true only when the value is clearly corrupt/unreadable JSON.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
function rtoc_decode_vocqual(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return ['text' => '', 'error' => false];
    }

    // Only attempt decode if it starts like a JSON value
    if ($raw[0] === '{' || $raw[0] === '[') {
        $decoded = @json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || $decoded === null) {
            // Malformed JSON — the "funny text" case
            return ['text' => '', 'error' => true];
        }

        // Helper to format one qualification object
        $fmt = function ($item): string {
            if (is_string($item)) return trim($item);
            if (!is_array($item))  return '';
            $code  = trim($item['code']     ?? $item['qualcode'] ?? '');
            $title = trim($item['title']    ?? $item['qualname'] ?? $item['name'] ?? '');
            if ($code && $title) return $code . ' — ' . $title;
            return $code ?: $title;
        };

        // Array of quals vs single qual object
        if (isset($decoded[0]) || (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1))) {
            // Indexed array of qualification items
            $parts = array_filter(array_map($fmt, $decoded));
            $text  = implode('; ', $parts);
        } else {
            // Single associative object
            $text = $fmt($decoded);
        }

        if (trim($text) === '') {
            // JSON decoded OK but produced no readable text
            return ['text' => '', 'error' => true];
        }
        return ['text' => $text, 'error' => false];
    }

    // Plain text — return as-is
    return ['text' => $raw, 'error' => false];
}

admin_externalpage_setup('local_rtocompliance_trainers');
require_capability('local/rtocompliance:managetrainers', context_system::instance());

// ── v4.4.20 HARDENING ─────────────────────────────────────────────────────────
// Production sites have debugdisplay=0, so any uncaught exception in the heavy
// stat queries or per-row rendering produces a blank 500 (chrome-error). This
// helper captures exceptions per-query so the page always renders, and the
// captured messages are surfaced to site admins below.
$rtoc_captured_errors = [];
$rtoc_is_admin        = is_siteadmin();

/** Run a count_records_sql safely. Returns int. Captures any exception for admins. */
function rtoc_safe_count($sql, $params, $label) {
    global $DB, $rtoc_captured_errors;
    try {
        return (int)$DB->count_records_sql($sql, $params);
    } catch (\Throwable $e) {
        $rtoc_captured_errors[] = '[' . $label . '] ' . $e->getMessage();
        return 0;
    }
}

// ── Optional schema repair: re-runs critical column-add blocks idempotently.
// Triggered by ?rtocrepair=1&sesskey=... by a site admin. Safe — every
// add_field is guarded by field_exists().
$rtocrepair = optional_param('rtocrepair', 0, PARAM_INT);
if ($rtocrepair && $rtoc_is_admin && confirm_sesskey()) {
    $dbman = $DB->get_manager();
    $repairs = [];
    try {
        $tbl = new xmldb_table('local_rtocompliance_trainers');
        $cols_to_ensure = [
            ['taeexpirydate',          XMLDB_TYPE_INTEGER, '10',  null, null, null, null, 'taedateachieved'],
            ['industrycurrencydate',   XMLDB_TYPE_INTEGER, '10',  null, null, null, null, null],
            ['industrycurrencyevidence', XMLDB_TYPE_CHAR,  '255', null, null, null, null, null],
            ['wwccnumber',             XMLDB_TYPE_CHAR,    '30',  null, null, null, null, null],
            ['policecheckexpiry',      XMLDB_TYPE_INTEGER, '10',  null, null, null, null, null],
            ['policecheckstatus',      XMLDB_TYPE_CHAR,    '20',  null, null, null, null, null],
            ['scopenotes',             XMLDB_TYPE_TEXT,    null,  null, null, null, null, null],
            ['industryexperienceyears',XMLDB_TYPE_INTEGER, '3',   null, null, null, null, null],
            ['llncapability',          XMLDB_TYPE_CHAR,    '100', null, null, null, null, null],
            ['vetcurrencyyears',       XMLDB_TYPE_INTEGER, '3',   null, null, null, null, null],
            ['vetcurrencydate',        XMLDB_TYPE_INTEGER, '10',  null, null, null, null, null],
            ['credentialrole',         XMLDB_TYPE_TEXT,    null,  null, null, null, null, null],
            ['managersignoffdate',     XMLDB_TYPE_INTEGER, '10',  null, null, null, null, null],
        ];
        foreach ($cols_to_ensure as $c) {
            $f = new xmldb_field($c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7]);
            if (!$dbman->field_exists($tbl, $f)) {
                $dbman->add_field($tbl, $f);
                $repairs[] = 'Added column ' . $c[0];
            }
        }
        // OPcache reset if available — fixes stale-bytecode 500s after deploy.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $repairs[] = 'OPcache reset';
        }
    } catch (\Throwable $e) {
        $repairs[] = 'REPAIR ERROR: ' . $e->getMessage();
    }
    redirect(
        new moodle_url('/local/rtocompliance/trainers.php'),
        'Schema repair complete: ' . (empty($repairs) ? 'no changes needed' : implode('; ', $repairs)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$action  = optional_param('action', '', PARAM_ALPHA);
$id      = optional_param('id', 0, PARAM_INT);
$page    = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 25, PARAM_INT);
$status  = optional_param('status', '', PARAM_ALPHA);
// SORT: clickable Name column header.
$sort    = optional_param('sort', 'name', PARAM_ALPHA);
$sortdir = optional_param('sortdir', 'asc', PARAM_ALPHA);
if (!in_array($sort, ['name'], true)) { $sort = 'name'; }
if (!in_array($sortdir, ['asc', 'desc'], true)) { $sortdir = 'asc'; }

// ---- Import Moodle teachers with no RTO profile ----
if ($action === 'importtrainers' && confirm_sesskey()) {
    require_sesskey();

    $unregistered = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
         FROM {user} u
         JOIN {role_assignments} ra ON ra.userid = u.id
         JOIN {role} r ON r.id = ra.roleid
         WHERE r.shortname IN ('editingteacher', 'teacher', 'manager')
           AND u.deleted = 0 AND u.suspended = 0 AND u.id > 1
           AND NOT EXISTS (
               SELECT 1 FROM {local_rtocompliance_trainers} t WHERE t.userid = u.id
           )
         ORDER BY u.lastname, u.firstname"
    );

    $now = time();
    $imported = 0;
    foreach ($unregistered as $teacher) {
        if ($DB->record_exists('local_rtocompliance_trainers', ['userid' => $teacher->id])) {
            continue;
        }
        $rec = new stdClass();
        $rec->userid            = $teacher->id;
        $rec->cpdhours          = 0;
        $rec->wwccstatus        = 'pending';
        $rec->policecheckstatus = 'pending';
        $rec->managersignoff    = 0;
        $rec->compliancestatus  = 'pending';
        $rec->timecreated       = $now;
        $rec->timemodified      = $now;
        $DB->insert_record('local_rtocompliance_trainers', $rec);
        $imported++;
    }

    redirect(
        new moodle_url('/local/rtocompliance/trainers.php'),
        get_string('trainers_imported', 'local_rtocompliance', ['imported' => $imported]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url(new moodle_url('/local/rtocompliance/trainers.php', ['page' => $page, 'perpage' => $perpage, 'status' => $status, 'sort' => $sort, 'sortdir' => $sortdir]));
$PAGE->set_title(get_string('trainers', 'local_rtocompliance'));
$PAGE->set_heading(get_string('trainers', 'local_rtocompliance'));

$PAGE->requires->css('/local/rtocompliance/styles.css');

$PAGE->add_body_class("path-local-rtocompliance");
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(get_string('trainers', 'local_rtocompliance'), null, null, 'trainers');

echo html_writer::start_div('trainers-container');

// ---- Detect Moodle teachers without an RTO compliance profile ----
try {
    $unregistered_teachers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email,
                u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role} r ON r.id = ra.roleid
          WHERE r.shortname IN ('editingteacher', 'teacher', 'manager')
            AND u.deleted = 0 AND u.suspended = 0 AND u.id > 1
            AND NOT EXISTS (
                SELECT 1 FROM {local_rtocompliance_trainers} t WHERE t.userid = u.id
            )
          ORDER BY u.lastname, u.firstname"
    );
} catch (\Throwable $e) {
    $unregistered_teachers = [];
    $rtoc_captured_errors[] = '[unregistered_teachers] ' . $e->getMessage();
}
if (!empty($unregistered_teachers)) {
    $importurl = new moodle_url('/local/rtocompliance/trainers.php', [
        'action'  => 'importtrainers',
        'sesskey' => sesskey(),
    ]);
    $count = count($unregistered_teachers);

    echo html_writer::start_div('alert alert-info');
    echo html_writer::tag('strong',
        $count . ' Moodle teacher' . ($count > 1 ? 's have' : ' has') . ' no RTO compliance profile yet.'
    );
    echo html_writer::tag('p',
        'These Moodle users hold an editing teacher, teacher, or manager role in at least one course '
        . 'but have not been added to the Trainer Register. They are invisible in the trainer list below.'
    );
    echo html_writer::start_tag('ul');
    foreach ($unregistered_teachers as $t) {
        echo html_writer::tag('li', fullname($t) . ' (' . $t->email . ')');
    }
    echo html_writer::end_tag('ul');
    echo html_writer::link(
        $importurl->out(false),
        'Import ' . $count . ' Moodle Teacher' . ($count > 1 ? 's' : '') . ' as RTO Trainer Profile' . ($count > 1 ? 's' : ''),
        ['class' => 'btn btn-info']
    );
    echo html_writer::end_div();
}

echo html_writer::start_div('trainers-header');
echo html_writer::tag('h2', get_string('trainers', 'local_rtocompliance'));
echo html_writer::link(
    new moodle_url('/local/rtocompliance/trainer_edit.php'),
    get_string('add_trainer', 'local_rtocompliance'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_div();

// ── Quick Statistics cards ────────────────────────────────────────────────────
$_now30      = strtotime('+30 days');
$_now        = time();
try { $_total_t = (int)$DB->count_records('local_rtocompliance_trainers'); }
catch (\Throwable $e) { $_total_t = 0; $rtoc_captured_errors[] = '[total] ' . $e->getMessage(); }
// TAE status based on taeexpirydate (not nextreviewdate which is for general credential review)
$_current_t  = rtoc_safe_count(
    "SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential != '' AND taecredential != 'Working Towards' AND (taeexpirydate IS NULL OR taeexpirydate = 0 OR taeexpirydate >= :now)",
    ['now' => $_now], 'tae_current'
);
$_expiring_t = rtoc_safe_count(
    "SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential != '' AND taecredential != 'Working Towards' AND taeexpirydate IS NOT NULL AND taeexpirydate != 0 AND taeexpirydate >= :now AND taeexpirydate < :d30",
    ['now' => $_now, 'd30' => $_now30], 'tae_expiring'
);
$_expired_t  = rtoc_safe_count(
    "SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential != '' AND taecredential != 'Working Towards' AND taeexpirydate IS NOT NULL AND taeexpirydate != 0 AND taeexpirydate < :now",
    ['now' => $_now], 'tae_expired'
);
$_missing_t  = count($unregistered_teachers);
$_tae116_t   = rtoc_safe_count("SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential LIKE :tae", ['tae' => '%TAE40116%'], 'tae116');
$_tae122_t   = rtoc_safe_count("SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential LIKE :tae", ['tae' => '%TAE40122%'], 'tae122');
$_tae110_t   = rtoc_safe_count("SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential LIKE :tae", ['tae' => '%TAE40110%'], 'tae110');
$_notae_t    = rtoc_safe_count("SELECT COUNT(*) FROM {local_rtocompliance_trainers} WHERE taecredential IS NULL OR taecredential = '' OR taecredential = 'Working Towards'", [], 'tae_missing');
try { $_wwcc_t = (int)$DB->count_records('local_rtocompliance_trainers', ['wwccstatus' => 'current']); }
catch (\Throwable $e) { $_wwcc_t = 0; $rtoc_captured_errors[] = '[wwcc] ' . $e->getMessage(); }

echo html_writer::start_div('stats-cards', ['style' => 'margin-bottom: 24px;']);
foreach ([
    ['label' => 'Total Trainer Profiles',         'value' => $_total_t,   'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('users')],
    ['label' => 'TAE Current',                    'value' => $_current_t, 'color' => 'green',  'icon' => local_rtocompliance_stat_icon('check')],
    ['label' => 'TAE Expiring in 30 Days',        'value' => $_expiring_t,'color' => $_expiring_t > 0 ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('clock')],
    ['label' => 'TAE Expired',                    'value' => $_expired_t, 'color' => $_expired_t > 0  ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Missing TAE Credential',         'value' => $_notae_t,   'color' => $_notae_t > 0    ? 'rose'  : 'green', 'icon' => local_rtocompliance_stat_icon('alert')],
    ['label' => 'Moodle Teachers Without Profile','value' => $_missing_t, 'color' => $_missing_t > 0  ? 'amber' : 'green', 'icon' => local_rtocompliance_stat_icon('user')],
    ['label' => 'TAE40116 Qualified',             'value' => $_tae116_t,  'color' => 'purple', 'icon' => local_rtocompliance_stat_icon('book')],
    ['label' => 'TAE40122 Qualified',             'value' => $_tae122_t,  'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('book')],
    ['label' => 'TAE40110 Qualified',             'value' => $_tae110_t,  'color' => 'blue',   'icon' => local_rtocompliance_stat_icon('book')],
    ['label' => 'WWCC Cleared',                   'value' => $_wwcc_t,    'color' => $_wwcc_t > 0 ? 'green' : 'amber', 'icon' => local_rtocompliance_stat_icon('shield')],
] as $s) {
    echo html_writer::start_div('stat-card stat-' . $s['color']);
    echo '<div class="stat-icon-wrap">' . $s['icon'] . '</div>';
    echo html_writer::start_div('stat-info');
    echo html_writer::tag('span', $s['value'], ['class' => 'stat-number']);
    echo html_writer::tag('span', $s['label'],  ['class' => 'stat-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
echo html_writer::start_div('form-inline');
echo html_writer::tag('label', get_string('filterbystatus', 'local_rtocompliance'), ['class' => 'mr-2']);
echo html_writer::start_tag('select', ['name' => 'status', 'class' => 'form-control mr-2', 'onchange' => 'this.form.submit()']);
$allattr = ['value' => ''];
if (empty($status)) {
    $allattr['selected'] = 'selected';
}
echo html_writer::tag('option', get_string('all'), $allattr);

$currentattr = ['value' => 'current'];
if ($status === 'current') {
    $currentattr['selected'] = 'selected';
}
echo html_writer::tag('option', get_string('status_current', 'local_rtocompliance'), $currentattr);

$expiringattr = ['value' => 'expiring'];
if ($status === 'expiring') {
    $expiringattr['selected'] = 'selected';
}
echo html_writer::tag('option', get_string('status_expiring', 'local_rtocompliance'), $expiringattr);

$expiredattr = ['value' => 'expired'];
if ($status === 'expired') {
    $expiredattr['selected'] = 'selected';
}
echo html_writer::tag('option', get_string('status_expired', 'local_rtocompliance'), $expiredattr);

$policyattr = ['value' => 'policyapproved'];
if ($status === 'policyapproved') {
    $policyattr['selected'] = 'selected';
}
echo html_writer::tag('option', 'Credential Policy Approved', $policyattr);
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::end_tag('form');

$sql = "SELECT t.*, u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
        FROM {local_rtocompliance_trainers} t
        JOIN {user} u ON u.id = t.userid";

$params = [];
// FIX: countsql must JOIN {user} to exclude orphaned trainer records (userid
// no longer in the users table). Previously the JOIN was missing, causing the
// paging bar to show inflated page counts whenever trainer records outlive
// their associated Moodle user accounts.
$countsql = "SELECT COUNT(*) FROM {local_rtocompliance_trainers} t
              JOIN {user} u ON u.id = t.userid";

if (!empty($status)) {
    $now = time();
    $thirtydays = strtotime('+30 days');
    
    if ($status === 'current') {
        $sql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND (t.taeexpirydate IS NULL OR t.taeexpirydate = 0 OR t.taeexpirydate >= :now)";
        $countsql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND (t.taeexpirydate IS NULL OR t.taeexpirydate = 0 OR t.taeexpirydate >= :now)";
        $params['now'] = $now;
    } else if ($status === 'expiring') {
        $sql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND t.taeexpirydate IS NOT NULL AND t.taeexpirydate != 0 AND t.taeexpirydate >= :now AND t.taeexpirydate < :thirtydays";
        $countsql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND t.taeexpirydate IS NOT NULL AND t.taeexpirydate != 0 AND t.taeexpirydate >= :now AND t.taeexpirydate < :thirtydays";
        $params['now'] = $now;
        $params['thirtydays'] = $thirtydays;
    } else if ($status === 'expired') {
        $sql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND t.taeexpirydate IS NOT NULL AND t.taeexpirydate != 0 AND t.taeexpirydate < :now";
        $countsql .= " WHERE t.taecredential != '' AND t.taecredential != 'Working Towards' AND t.taeexpirydate IS NOT NULL AND t.taeexpirydate != 0 AND t.taeexpirydate < :now";
        $params['now'] = $now;
    } else if ($status === 'policyapproved') {
        // FIX-TRAINERS-FILTER: "Credential Policy Approved" shows trainers who have been
        // explicitly signed off under the RTO's Credential Policy (managersignoff is set).
        // These trainers may have expired TGA credentials but are still authorised to deliver.
        // FIX-MISSING-TAE-FILTER: Trainers with NO TAE credential (or "Working Towards")
        // must NOT appear under this filter — "Approved" implies they have at least a credential
        // that the RTO has authorised via policy. Missing TAE belongs in the "Missing" view only.
        $sql .= " WHERE t.managersignoff IS NOT NULL AND t.managersignoff != ''"
              . " AND t.taecredential IS NOT NULL AND t.taecredential != '' AND t.taecredential != 'Working Towards'";
        $countsql .= " WHERE t.managersignoff IS NOT NULL AND t.managersignoff != ''"
                   . " AND t.taecredential IS NOT NULL AND t.taecredential != '' AND t.taecredential != 'Working Towards'";
    }
}
$totalcount = rtoc_safe_count($countsql, $params, 'totalcount');

$sortSqlDir = ($sortdir === 'desc') ? 'DESC' : 'ASC';
$sql .= " ORDER BY u.lastname $sortSqlDir, u.firstname $sortSqlDir";

try {
    $trainers = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
} catch (\Throwable $e) {
    $trainers = [];
    $rtoc_captured_errors[] = '[trainers_fetch] ' . $e->getMessage();
}

// ── Surface captured errors to site admins so prod (debugdisplay=0) is debuggable.
if ($rtoc_is_admin && !empty($rtoc_captured_errors)) {
    echo html_writer::start_div('alert alert-danger', ['style' => 'margin-bottom:12px;']);
    echo html_writer::tag('strong', 'Diagnostic info (visible to site admins only):');
    echo html_writer::start_tag('ul', ['style' => 'margin:6px 0 6px 18px;font-family:monospace;font-size:12px;']);
    foreach ($rtoc_captured_errors as $err) {
        echo html_writer::tag('li', s($err));
    }
    echo html_writer::end_tag('ul');
    $repairurl = new moodle_url('/local/rtocompliance/trainers.php',
        ['rtocrepair' => 1, 'sesskey' => sesskey()]);
    echo html_writer::tag('p',
        'If errors mention a missing column, click ' .
        html_writer::link($repairurl->out(false), 'Repair schema',
            ['class' => 'btn btn-sm btn-warning']) .
        ' to add any missing columns and reset OPcache.'
    );
    echo html_writer::end_div();
}

if ($trainers) {
    echo html_writer::tag('div',
        html_writer::tag('strong', 'Note: ') .
        'A TAE credential may show as <strong>Expired</strong> under TGA yet the trainer may still be approved to deliver and assess under your RTO\'s Credential Policy. ' .
        'Always check the <strong>Status under Credential Policy</strong> column and verify against your Credential Policy document before making staffing decisions.',
        ['class' => 'alert alert-info', 'style' => 'margin-bottom: 12px;']
    );
    echo '<div class="rtoc-table-wrapper" style="overflow-x:auto;">';
    echo html_writer::start_tag('table', ['class' => 'trainers-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    // Sortable Name column header.
    $trainerNameNextDir = ($sort === 'name' && $sortdir === 'asc') ? 'desc' : 'asc';
    $trainerNameSortUrl = new moodle_url('/local/rtocompliance/trainers.php', [
        'page' => 0, 'perpage' => $perpage, 'status' => $status,
        'sort' => 'name', 'sortdir' => $trainerNameNextDir,
    ]);
    $trainerNameArrow = ($sort === 'name')
        ? ($sortdir === 'asc'
            ? ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 2L9 8H1z" fill="currentColor"/></svg>'
            : ' <svg style="width:10px;height:10px;vertical-align:middle" viewBox="0 0 10 10"><path d="M5 8L1 2h8z" fill="currentColor"/></svg>')
        : '';
    $trainerNameLink = html_writer::link($trainerNameSortUrl,
        get_string('trainer_name', 'local_rtocompliance') . $trainerNameArrow,
        ['style' => 'white-space:nowrap;text-decoration:none;color:inherit;font-weight:bold']);
    echo html_writer::tag('th', $trainerNameLink, ['class' => 'rtoc-col-trainer-name']);
    echo html_writer::tag('th', 'Role');
    echo html_writer::tag('th', 'TAE Credential');
    echo html_writer::tag('th', 'TAE Achieved');
    echo html_writer::tag('th', 'Status under TGA');
    echo html_writer::tag('th', 'Status under Credential Policy');
    echo html_writer::tag('th', 'Vocational Competency');
    echo html_writer::tag('th', 'Units Being Delivered');
    echo html_writer::tag('th', 'LLN Capability');
    echo html_writer::tag('th', 'VET Currency');
    echo html_writer::tag('th', 'Industry Currency');
    echo html_writer::tag('th', 'CPD Points');
    echo html_writer::tag('th', 'Next Review Date');
    echo html_writer::tag('th', '', ['class' => 'rtoc-sticky-right rtoc-col-actions-hdr']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($trainers as $trainer) {
        try {
        // ========================================================================
        // TAE STATUS CALCULATION - CRITICAL LOGIC (v3.7.42)
        // ========================================================================
        // Status under TGA is calculated from TAE qualification ONLY
        // NOT from nextreviewdate (which is for general credential review)
        // NOT from CPD hours, industry currency, or credential policy
        // 
        // RULE: TAE status = "Current" if:
        //   1. TAE expiry date is NULL (no expiry = Current forever), OR
        //   2. TAE expiry date is in the future
        // ========================================================================
        
        $now = time();
        $thirtydays = strtotime('+30 days');
        $displaystatus = 'current';
        $statusReason = '';
        $statusDebug = [];
        
        // Check if TAE credential exists
        $hasTaeCredential = !empty($trainer->taecredential) && $trainer->taecredential !== 'Working Towards';
        
        // Get TAE expiry date (new field) - if null, TAE never expires
        $taeExpiryDate = isset($trainer->taeexpirydate) ? $trainer->taeexpirydate : null;
        
        // Build debug info
        $statusDebug['tae_credential'] = $trainer->taecredential ?: 'Not set';
        $statusDebug['tae_date_achieved'] = $trainer->taedateachieved ? userdate($trainer->taedateachieved, '%d %b %Y') : 'Not set';
        $statusDebug['tae_expiry_date'] = $taeExpiryDate ? userdate($taeExpiryDate, '%d %b %Y') : 'No expiry (Current forever)';
        $statusDebug['next_review_date'] = $trainer->nextreviewdate ? userdate($trainer->nextreviewdate, '%d %b %Y') : 'Not set';
        $statusDebug['today'] = userdate($now, '%d %b %Y');
        
        // FIX-TAE-VERSION: TAE40110 and TAE40116 are superseded qualifications under ASQA.
        // Only TAE40122 (the 2022 version) is the current accepted standard.
        // Trainers holding superseded versions must display as "Expired" under TGA regardless
        // of any manually-entered expiry date — the qualification version itself is superseded.
        $supersededTaeVersions = ['TAE40110', 'TAE40116'];

        if (!$hasTaeCredential) {
            // No TAE or Working Towards = show as pending/missing
            $displaystatus = 'missing';
            $statusReason = 'No TAE credential recorded (or "Working Towards")';
            $statusDebug['calculation'] = 'NO TAE credential → Status = Missing';
        } else if (in_array($trainer->taecredential, $supersededTaeVersions)) {
            // Superseded TAE version → always Expired under TGA regardless of expiry date
            $displaystatus = 'expired';
            $statusReason = $trainer->taecredential . ' is a superseded qualification (TAE40122 is the current version). '
                          . 'Check the Credential Policy column — the RTO may still authorise delivery under policy.';
            $statusDebug['calculation'] = $trainer->taecredential . ' is superseded → Status = Expired (version-based, not date-based)';
        } else if ($taeExpiryDate === null || $taeExpiryDate === 0) {
            // TAE has no expiry date = CURRENT forever (this is the normal case!)
            $displaystatus = 'current';
            $statusReason = 'TAE has no expiry date (Current indefinitely)';
            $statusDebug['calculation'] = 'TAE expiry = NULL → Status = Current (no expiry)';
        } else if ($taeExpiryDate >= $now) {
            // TAE expiry is in the future = CURRENT
            if ($taeExpiryDate < $thirtydays) {
                $displaystatus = 'expiring';
                $statusReason = 'TAE expires within 30 days (' . userdate($taeExpiryDate, '%d %b %Y') . ')';
                $statusDebug['calculation'] = 'TAE expiry (' . userdate($taeExpiryDate, '%d %b %Y') . ') < 30 days → Status = Expiring';
            } else {
                $displaystatus = 'current';
                $statusReason = 'TAE is current (expires ' . userdate($taeExpiryDate, '%d %b %Y') . ')';
                $statusDebug['calculation'] = 'TAE expiry (' . userdate($taeExpiryDate, '%d %b %Y') . ') > today → Status = Current';
            }
        } else {
            // TAE expiry is in the past = EXPIRED
            $displaystatus = 'expired';
            $statusReason = 'TAE expired on ' . userdate($taeExpiryDate, '%d %b %Y');
            $statusDebug['calculation'] = 'TAE expiry (' . userdate($taeExpiryDate, '%d %b %Y') . ') < today → Status = Expired';
        }
        
        // Build debug tooltip HTML
        $debugTooltip = "STATUS CALCULATION DEBUG\n";
        $debugTooltip .= "========================\n";
        $debugTooltip .= "TAE Credential: " . $statusDebug['tae_credential'] . "\n";
        $debugTooltip .= "TAE Date Achieved: " . $statusDebug['tae_date_achieved'] . "\n";
        $debugTooltip .= "TAE Expiry: " . $statusDebug['tae_expiry_date'] . "\n";
        $debugTooltip .= "Next Review Date: " . $statusDebug['next_review_date'] . "\n";
        $debugTooltip .= "Today: " . $statusDebug['today'] . "\n";
        $debugTooltip .= "------------------------\n";
        $debugTooltip .= "Calculation: " . $statusDebug['calculation'] . "\n";
        $debugTooltip .= "Result: " . strtoupper($displaystatus);
        
        $credentialpolicy = !empty($trainer->managersignoff) ? 'approved' : 'notapproved';
        $currencyActivities = $DB->get_records('local_rtocompliance_trainer_currency', ['trainerid' => $trainer->id]);
        $currencyCount = count($currencyActivities);
        $ongoingCount = 0;
        foreach ($currencyActivities as $act) {
            if (!empty($act->ongoing)) $ongoingCount++;
        }
        
        // Industry Currency — show industrycurrencydate from trainer record
        if (!empty($trainer->industrycurrencydate)) {
            $currencyBadge = html_writer::tag('span',
                userdate($trainer->industrycurrencydate, '%d %b %Y'),
                ['class' => 'badge', 'style' => 'background-color: #28a745; color: white;']);
        } elseif ($currencyCount > 0) {
            // Fallback: still show activity count if no date recorded yet
            $currencyBadge = html_writer::tag('span', $currencyCount . ' activities',
                ['class' => 'badge', 'style' => 'background-color: #6c757d; color: white;']);
        } else {
            $currencyBadge = html_writer::tag('span', 'None', ['class' => 'badge badge-warning', 'style' => 'background-color: #ffc107; color: #212529;']);
        }

        // Compute extra register fields
        $taeAchieved = !empty($trainer->taedateachieved) ? userdate($trainer->taedateachieved, '%d %b %Y') : '-';

        // ── Trainer/Assessor role badges with ASQA practice guide tooltips ────
        // Full descriptions per ASQA Trainer & Assessor Qualifications Practice Guide
        // and Standards for RTOs 2015 (Clauses 1.13–1.16) / 2025 Standard 3.
        static $roleTips = [
            '1A' => "1A — Independent Trainer & Assessor\n\nHolds: TAE40116 or TAE40122 + vocational qualification at or above delivery level + current industry currency.\n\nCan train and assess independently. No supervision required.\n\nASQA ref: Clause 1.13 (RTO Standards 2015) / Standard 3 (2025).",
            '1B' => "1B — Trainer Only (Industry Expert Partnership)\n\nHolds: Full TAE + operates alongside a TAE-qualified assessor.\n\nCan deliver training but cannot assess independently. Requires pairing with a qualified assessor for all summative assessment.\n\nASQA ref: Clause 1.14 (2015) / Standard 3.3 (2025).",
            '1C' => "1C — Working Towards TAE (Vocational Qualification Held)\n\nCurrently completing TAE qualification. Holds relevant vocational qualification.\n\nMust have a supervision and support plan. Training delivery is supervised. Must complete TAE within the agreed timeframe.\n\nASQA ref: Clause 1.14 exception (2015) / Standard 3.4 (2025).",
            '1D' => "1D — Working Towards TAE (Industry Expert, No Independent Vocational Qualification)\n\nCurrently completing TAE. No independent vocational qualification but brings industry expertise.\n\nSupervision and support plan required for all delivery. Closely supervised by TAE-qualified trainer.\n\nASQA ref: Clause 1.14 exception (2015) / Standard 3.4 (2025).",
            '1E' => "1E — Secondary / Tertiary Teaching Qualification\n\nHolds a secondary or tertiary teaching qualification recognised as equivalent to TAE under the Standards.\n\nMust still hold vocational competency at/above the level being delivered and demonstrate current industry currency.\n\nASQA ref: Clause 1.13 equivalent provision (2015) / Standard 3.1 (2025).",
            '2A' => "2A — Industry Expert (Training Support, No TAE)\n\nDoes not hold a TAE qualification. Contributes subject-matter expertise to training delivery only.\n\nMust be paired with a TAE-qualified trainer at all times. Cannot deliver training independently. Supervision plan required.\n\nASQA ref: Clause 1.14 / Standard 3.3 (2025).",
            '2B' => "2B — Industry Expert (Assessment Support)\n\nAssists with assessment activities under the direct supervision of a qualified assessor.\n\nCannot make independent assessment decisions or sign off competency. Provides workplace evidence and observation support only.\n\nASQA ref: Clause 1.15 (2015) / Standard 3.5 (2025).",
            '2C' => "2C — Industry Expert (Assessment Judgement Only)\n\nProvides assessment judgements in their specific area of expertise under the supervision of a qualified assessor.\n\nMust be explicitly approved for this role. Cannot independently determine overall competency outcomes.\n\nASQA ref: Clause 1.15 (2015) / Standard 3.5 (2025).",
            '3A' => "3A — Validator (TAE Qualification Held)\n\nHolds a TAE qualification. Can lead or participate in validation activities.\n\nMeets the validation requirement for at least one TAE-qualified participant per validation panel. Clause 1.9 validation schedule compliance.\n\nASQA ref: Clause 1.9 (2015) / Standard 1.9 (2025).",
            '3B' => "3B — Industry Expert Validator\n\nParticipates in assessment validation as an industry representative.\n\nDoes not hold a TAE qualification. Must be part of a validation panel that includes at least one TAE-qualified validator (3A). Provides industry-currency perspective on assessment quality.\n\nASQA ref: Clause 1.9 (2015) / Standard 1.9 (2025).",
        ];

        $roleDisplay = '-';
        if (!empty($trainer->credentialrole)) {
            $roles = array_filter(array_map('trim', explode(',', $trainer->credentialrole)));
            $roleBadges = [];
            foreach ($roles as $r) {
                $tip = $roleTips[$r] ?? $r;
                $roleBadges[] = html_writer::tag('span', htmlspecialchars($r), [
                    'class'          => 'badge rtoc-role-badge',
                    'style'          => 'background:#7c3aed;color:#fff;margin:1px;',
                    'data-rtoc-tip'  => htmlspecialchars($tip, ENT_QUOTES),
                    'tabindex'       => '0',
                    'aria-label'     => htmlspecialchars($tip, ENT_QUOTES),
                ]);
            }
            $roleDisplay = $roleBadges ? implode(' ', $roleBadges) : '-';
        }

        $vocCompDisplay = '-';
        if (!empty($trainer->vocationalqualifications)) {
            $vocResult = rtoc_decode_vocqual($trainer->vocationalqualifications);
            if ($vocResult['error'] || trim($vocResult['text']) === '') {
                // Corrupt / unreadable data — show a clear actionable message
                $warnIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" '
                    . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
                    . 'style="vertical-align:-2px;margin-right:3px;">'
                    . '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                    . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                $vocCompDisplay = '<span class="rtoc-vocqual-missing" '
                    . 'title="The qualification data stored for this trainer is unreadable. Please open the trainer profile and re-enter the Vocational Qualifications field." '
                    . 'style="color:#b45309;font-size:0.8rem;font-style:italic;cursor:help;">'
                    . $warnIcon . 'Qualification not recorded &mdash; update profile'
                    . '</span>';
            } else {
                $displayText  = $vocResult['text'];
                $vocQualShort = rtoc_mb_strlen($displayText) > 60
                    ? rtoc_mb_substr($displayText, 0, 60) . '…'
                    : $displayText;
                $vocCompDisplay = html_writer::tag('span', htmlspecialchars($vocQualShort),
                    ['title' => htmlspecialchars($displayText), 'style' => 'cursor:help;font-size:0.85rem;']);
            }
        }

        $wwccDisplay = '-';
        if (!empty($trainer->wwccstatus)) {
            $wwccColour = ['current' => '#28a745', 'pending' => '#ffc107', 'expired' => '#dc3545', 'na' => '#6c757d'];
            $wwccText   = ['current' => 'Current', 'pending' => 'Pending', 'expired' => 'Expired', 'na' => 'N/A'];
            $wstatus = $trainer->wwccstatus;
            $col = $wwccColour[$wstatus] ?? '#6c757d';
            $lbl = $wwccText[$wstatus] ?? ucfirst($wstatus);
            $wwccDisplay = html_writer::tag('span', $lbl, ['class' => 'badge', 'style' => 'background:'.$col.';color:'.($wstatus === 'pending' ? '#212529' : '#fff').';']);
            if (!empty($trainer->wwccexpiry)) {
                $wwccDisplay .= html_writer::empty_tag('br') . html_writer::tag('small', userdate($trainer->wwccexpiry, '%d %b %Y'), ['class' => 'text-muted']);
            }
            if (!empty($trainer->wwccnumber)) {
                $wwccDisplay .= html_writer::empty_tag('br') . html_writer::tag('small', 'No. ' . htmlspecialchars($trainer->wwccnumber), ['class' => 'text-muted']);
            }
        }

        $policeDisplay = '-';
        if (!empty($trainer->policecheckstatus)) {
            $pcColour = ['current' => '#28a745', 'pending' => '#ffc107', 'expired' => '#dc3545', 'na' => '#6c757d'];
            $pcText   = ['current' => 'Current', 'pending' => 'Pending', 'expired' => 'Expired', 'na' => 'N/A'];
            $pstatus = $trainer->policecheckstatus;
            $pcol = $pcColour[$pstatus] ?? '#6c757d';
            $plbl = $pcText[$pstatus] ?? ucfirst($pstatus);
            $policeDisplay = html_writer::tag('span', $plbl, ['class' => 'badge', 'style' => 'background:'.$pcol.';color:'.($pstatus === 'pending' ? '#212529' : '#fff').';']);
            if (!empty($trainer->policecheckdate)) {
                $policeDisplay .= html_writer::empty_tag('br') . html_writer::tag('small', 'Done ' . userdate($trainer->policecheckdate, '%d %b %Y'), ['class' => 'text-muted']);
            }
            if (!empty($trainer->policecheckexpiry)) {
                $policeDisplay .= html_writer::empty_tag('br') . html_writer::tag('small', 'Exp ' . userdate($trainer->policecheckexpiry, '%d %b %Y'), ['class' => 'text-muted']);
            }
        }

        $signoffDisplay = html_writer::tag('span', $credentialpolicy === 'approved' ? 'Approved' : 'Pending Review',
            ['class' => 'badge ' . ($credentialpolicy === 'approved' ? 'badge-success' : 'badge-secondary'),
             'title' => $credentialpolicy === 'approved'
                 ? 'Trainer has been reviewed and approved under the RTO\'s Credential Policy'
                 : 'Trainer has not yet been assessed under the Credential Policy — this does not mean they are rejected']);
        if ($credentialpolicy === 'approved' && !empty($trainer->managersignoffdate)) {
            $signoffDisplay .= html_writer::empty_tag('br') . html_writer::tag('small', userdate($trainer->managersignoffdate, '%d %b %Y'), ['class' => 'text-muted']);
        }

        // Units being delivered (truncated for table readability)
        $scopeDisplay = '-';
        if (!empty($trainer->scopenotes)) {
            $scopeShort = rtoc_mb_strlen($trainer->scopenotes) > 60
                ? rtoc_mb_substr($trainer->scopenotes, 0, 60) . '…'
                : $trainer->scopenotes;
            $scopeDisplay = html_writer::tag('span', htmlspecialchars($scopeShort),
                ['title' => htmlspecialchars($trainer->scopenotes), 'style' => 'cursor:help;font-size:0.85rem;']);
        }

        // Industry experience years
        $industryExpDisplay = !empty($trainer->industryexperienceyears)
            ? html_writer::tag('span', $trainer->industryexperienceyears . ' yrs',
                ['class' => 'badge', 'style' => 'background:#0891b2;color:#fff;'])
            : '-';

        // LLN Capability
        $llnDisplay = '-';
        if (!empty($trainer->llncapability) && $trainer->llncapability !== 'na') {
            $llnLabels = [
                'ACSF 1-2'  => 'ACSF 1–2',
                'ACSF 3'    => 'ACSF 3',
                'ACSF 4-5'  => 'ACSF 4–5',
                'qualified' => 'Qualified',
                'trained'   => 'Trained',
            ];
            $llnLabel = $llnLabels[$trainer->llncapability] ?? htmlspecialchars($trainer->llncapability);
            $llnDisplay = html_writer::tag('span', $llnLabel,
                ['class' => 'badge', 'style' => 'background:#7c3aed;color:#fff;']);
        } elseif (!empty($trainer->llncapability) && $trainer->llncapability === 'na') {
            $llnDisplay = html_writer::tag('span', 'N/A', ['class' => 'text-muted']);
        }

        // VET Currency date
        $vetCurrencyDisplay = !empty($trainer->vetcurrencydate)
            ? html_writer::tag('span', userdate($trainer->vetcurrencydate, '%d %b %Y'),
                ['class' => 'badge', 'style' => 'background:#059669;color:#fff;'])
            : '-';

        // Status with debug tooltip - shows WHY the status was calculated
        // FIX-TRAINERS-BADGE: if TAE is expired BUT trainer is Credential Policy approved,
        // use amber (warning) styling rather than red — still expired under TGA, but
        // the credential policy approval means the trainer is authorised to continue.
        $statusClass = 'trainer-status ' . $displaystatus;
        if ($displaystatus === 'expired' && $credentialpolicy === 'approved') {
            $statusClass = 'trainer-status expired-policy';
        } elseif ($displaystatus === 'missing') {
            $statusClass = 'trainer-status expired'; // Style missing as expired for visibility
        }
        $statusLabel = $displaystatus === 'missing' ? 'Missing TAE' : ucfirst($displaystatus);
        if ($displaystatus === 'expired' && $credentialpolicy === 'approved') {
            $statusLabel = 'Expired (Policy OK)';
        }
        
        // Create status badge with debug info icon
        $statusBadge = html_writer::tag('span', $statusLabel, [
            'class' => $statusClass,
            'title' => $debugTooltip,
            'style' => 'cursor: help;'
        ]);
        
        // Add debug info icon with tooltip
        $debugIcon = html_writer::tag('span', ' ⓘ', [
            'class' => 'status-debug-icon',
            'title' => $debugTooltip,
            'style' => 'cursor: help; font-size: 14px; color: #6c757d;'
        ]);
        
        // Add reason text below status for maximum visibility
        $reasonText = html_writer::tag('small', $statusReason, [
            'class' => 'text-muted d-block',
            'style' => 'font-size: 11px; margin-top: 4px; max-width: 200px; word-wrap: break-word;'
        ]);
        
        // ── Build reusable display values ────────────────────────────────────────
        $detailRowId = 'trainer-detail-' . (int)$trainer->id;

        // Truncate TAE credential for table (full text in detail row)
        $taeCredShort = !empty($trainer->taecredential)
            ? (rtoc_mb_strlen($trainer->taecredential) > 30
                ? rtoc_mb_substr($trainer->taecredential, 0, 30) . '…'
                : $trainer->taecredential)
            : '-';
        $taeCredDisplay = !empty($trainer->taecredential)
            ? html_writer::tag('span', $taeCredShort, [
                'title' => htmlspecialchars($trainer->taecredential),
                'style' => 'cursor:help;'
              ])
            : '-';

        // ── Edit / Delete dropdown ────────────────────────────────────────────
        $editUrl      = new moodle_url('/local/rtocompliance/trainer_edit.php', ['id' => $trainer->id]);
        $deleteAction = (new moodle_url('/local/rtocompliance/trainer_edit.php'))->out(false);
        $delFormId    = 'rtoc-del-t-' . (int)$trainer->id;
        // Hidden delete form (stays in the row; JS submits it on confirm).
        $deleteForm   = '<form id="' . $delFormId . '" method="post" action="' . $deleteAction . '"'
            . ' style="display:none;">'
            . '<input type="hidden" name="id"      value="' . (int)$trainer->id . '">'
            . '<input type="hidden" name="delete"  value="1">'
            . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
            . '</form>';
        // Button only — no Bootstrap dropdown wrapper; JS builds and body-appends the menu.
        $actionCell = '<div class="rtoc-trainer-actions">'
            . $deleteForm
            . '<button class="btn btn-sm btn-primary rtoc-action-btn" type="button"'
            . ' data-edit-url="' . htmlspecialchars($editUrl->out(false), ENT_QUOTES) . '"'
            . ' data-del-form="' . $delFormId . '">'
            . 'Edit &#9660;'
            . '</button>'
            . '</div>';

        // ── Row (13 columns per document spec) ───────────────────────────────
        echo html_writer::start_tag('tr', ['class' => 'rtoc-trainer-primary-row']);
        echo html_writer::tag('td',
            html_writer::tag('strong', fullname($trainer)) .
            html_writer::empty_tag('br') .
            html_writer::tag('small', $trainer->email, ['class' => 'text-muted'])
        );
        echo html_writer::tag('td', $roleDisplay);
        echo html_writer::tag('td', $taeCredDisplay);
        echo html_writer::tag('td', $taeAchieved);
        echo html_writer::tag('td', $statusBadge . $debugIcon);
        echo html_writer::tag('td', $signoffDisplay);
        echo html_writer::tag('td', $vocCompDisplay);
        echo html_writer::tag('td', $scopeDisplay);
        echo html_writer::tag('td', $llnDisplay);
        echo html_writer::tag('td', $vetCurrencyDisplay);
        echo html_writer::tag('td', $currencyBadge);
        echo html_writer::tag('td', $trainer->cpdhours . ' pts');
        echo html_writer::tag('td', $trainer->nextreviewdate ? userdate($trainer->nextreviewdate, '%d %b %Y') : '-');
        echo html_writer::tag('td', $actionCell, ['class' => 'rtoc-sticky-right rtoc-actions-cell']);
        echo html_writer::end_tag('tr');
        } catch (\Throwable $e) {
            $rtoc_captured_errors[] = '[row id=' . (int)($trainer->id ?? 0) . '] ' . $e->getMessage();
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td',
                'Row failed to render: ' . s(fullname($trainer) ?: ('id=' . (int)($trainer->id ?? 0))) .
                ($rtoc_is_admin ? ' — ' . s($e->getMessage()) : ''),
                ['colspan' => 14, 'style' => 'background:#fef2f2;color:#b91c1c;']);
            echo html_writer::end_tag('tr');
        }
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo '</div>';

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);
} else {
    echo html_writer::div(
        html_writer::tag('p', 'No trainers have been added yet.') .
        html_writer::tag('p', 'Add trainers to track their TAE credentials, vocational competency, industry currency, and CPD hours.'),
        'no-deadlines'
    );
}

echo html_writer::end_div();

// ── Trainers page JS (action menu + role-badge tooltips) ─────────────────────
// Loaded as an external file (js/trainers.js) to comply with Moodle 4.3+ CSP.
// Moodle's Content-Security-Policy blocks inline <script> blocks that lack a
// server-issued nonce. Same-origin script files (served from /local/rtocompliance/)
// are always allowed by the 'self' directive in Moodle's CSP header.
$_rtoc_trainers_js = (new moodle_url('/local/rtocompliance/js/trainers.js'))->out();
echo '<script src="' . $_rtoc_trainers_js . '"></script>';

// DEAD CODE REMOVED (v4.4.37): the if(false){...} block spanning ~200 lines
// contained Bootstrap's (apostrophe) inside a PHP single-quoted string, which
// caused a PHP parse error — preventing trainers.php from executing any line at all.
// The block was inert (if(false) never runs) and has been deleted entirely.


echo $OUTPUT->footer();
