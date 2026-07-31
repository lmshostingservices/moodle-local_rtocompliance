<?php
// v4.6.101 MULTI-UNIT-SOA — Issue a Statement of Attainment for multiple units.
//
// World-class multi-unit SOA wizard for Australian RTOs. Features:
//   - Searchable student picker with live student info card (USI status, completion count)
//   - Auto-detected eligible units from Moodle course completions (competency-achieved only)
//   - Searchable, sortable, filterable compliance-aware multi-select unit table
//   - Per-unit AQF/ASQA compliance indicators (green/amber/red)
//   - Suggested SOA Groups: one-click select all units from a qualification
//   - Blocking compliance validation before generation
//   - Single SOA PDF listing all selected units with immutable compliance snapshot
//
// Moodle mapping: Category = Qualification, Course = Unit, Completion = Outcome

// DIAG-v5.9.51: Step-by-step breadcrumb logging. Each marker writes to a file in
// /tmp AND to the PHP error log AND to Moodle DB config (fallback).
// Check /tmp/rtoc_soa_*.txt or PHP error log, or run in Moodle DB:
//   SELECT value FROM mdl_config WHERE plugin='local_rtocompliance' AND name='_soa_cp';
// Remove this block once the root cause of the HTTP 500 is identified.
$_soa_log = sys_get_temp_dir() . '/rtoc_soa_' . date('Ymd_His') . '_' . getmypid() . '.txt';
if (!function_exists('_soa_log')) {
    function _soa_log(string $step): void {
        global $_soa_log;
        $line = date('[H:i:s] ') . $step . "\n";
        @file_put_contents($_soa_log, $line, FILE_APPEND);
        error_log('[RTOC-SOA] ' . $step);
    }
}
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err) {
        _soa_log('SHUTDOWN fatal type=' . $err['type'] . ' msg=' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    } else {
        _soa_log('SHUTDOWN normal');
    }
});
_soa_log('START pid=' . getmypid() . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '?'));

require_once(__DIR__ . '/../../config.php');
_soa_log('config.php loaded');
try { set_config('_soa_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'config_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}

require_once($CFG->libdir . '/adminlib.php');
_soa_log('adminlib.php loaded');
try { set_config('_soa_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'adminlib_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}

require_once(__DIR__ . '/lib.php');
_soa_log('lib.php loaded');
try { set_config('_soa_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'lib_loaded']), 'local_rtocompliance'); } catch (\Throwable $_e) {}
// NOTE: Neither soa_compliance_engine nor usi_platform_client are require_once'd here.
// Both classes live in classes/ and are handled by Moodle's PSR-4 autoloader via the
// use statements below. Explicitly requiring classes/ files on an admin page that loads
// adminlib.php causes "Cannot redeclare class" PHP fatal errors (HTTP 500) on Moodle
// instances with symlinked dirs, because adminlib.php's admin-tree build registers the
// PSR-4 autoloader using a resolved path that differs from the __DIR__-relative path.
// soa_ajax.php does not load adminlib.php and therefore never hits this conflict.
// FIX-SOA-ISSUE-500 v5.2.89: soa_compliance_engine removed.
// FIX-SOA-ISSUE-500 v5.3.8: usi_platform_client removed (same pattern).

use local_rtocompliance\usi\usi_platform_client;

admin_externalpage_setup('local_rtocompliance_soa_issue');
_soa_log('admin_externalpage_setup done');
try { set_config('_soa_cp', json_encode(['t' => date('c'), 'pid' => getmypid(), 'step' => 'setup_done']), 'local_rtocompliance'); } catch (\Throwable $_e) {}
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$PAGE->set_url('/local/rtocompliance/soa_issue.php');
$PAGE->set_title('Issue Multi-Unit Statement of Attainment');
$PAGE->set_heading('Issue Multi-Unit SOA');
$PAGE->add_body_class('path-local-rtocompliance');
$PAGE->requires->css('/local/rtocompliance/styles.css');

// Pre-load users for the autocomplete picker (up to 10 000)
// FIX-SOA-MEMORY (v5.2.38): raise memory before potentially-large user query.
raise_memory_limit(MEMORY_HUGE);
$users = $DB->get_records_sql(
    "SELECT u.id, u.firstname, u.lastname, u.email,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
     FROM   {user} u
     WHERE  u.deleted = 0 AND u.suspended = 0 AND u.id > 1
     ORDER BY u.lastname, u.firstname",
    [], 0, 10000
);
// FIX-STUDENT-SURNAME-FIRST (v4.9.142): surname first so the list is
// scannable alphabetically. Format: "Surname, Firstname (email)"
$useroptions = ['' => ''];
foreach ($users as $user) {
    $useroptions[$user->id] = trim($user->lastname) . ', ' . trim($user->firstname) . ' (' . $user->email . ')';
}

$ajaxurl  = (new moodle_url('/local/rtocompliance/soa_ajax.php'))->out(false);
$sesskey  = sesskey();
$rtoname  = get_config('local_rtocompliance', 'rtoname') ?: '';
$rtocode  = get_config('local_rtocompliance', 'rtocode') ?: '';

echo $OUTPUT->header();

// FIX-SOA-SESSION-500 (v5.2.38): Credit balance must be fetched AFTER
// $OUTPUT->header() because get_credit_balance() internally calls
// \core\session\manager::write_close().  Calling write_close() before
// $OUTPUT->header() prevented Moodle from writing session cookies/data
// and caused a blank HTTP 500 on production.
$credclient  = new usi_platform_client();
try {
    $credbalance = $credclient->get_credit_balance();
} catch (\Throwable $e) {
    $credbalance = ['ok' => false, 'balance' => null, 'unlimited' => false, 'configured' => false];
    debugging('SOA credit balance fetch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}
echo local_rtocompliance_render_nav_header(
    'Issue Multi-Unit SOA',
    'Certificates',
    '/local/rtocompliance/certificates.php',
    'certificates'
);

// ── RTO config warning ────────────────────────────────────────────────────────
if (empty($rtoname) || empty($rtocode)) {
    echo $OUTPUT->notification(
        'Please configure your RTO name and code before issuing certificates. ' .
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'local_rtocompliance_settings']),
            'Configure RTO Settings',
            ['class' => 'btn btn-sm btn-primary ml-2']
        ),
        'warning'
    );
}

// ── Credit balance panel ──────────────────────────────────────────────────────
$balval  = $credbalance['balance']   ?? 0;
$unlim   = $credbalance['unlimited'] ?? false;
$cfgd    = $credbalance['configured'] ?? false;
if ($unlim) {
    $balBadge = '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;">UNLIMITED</span>';
} elseif ($cfgd) {
    $ok = $balval >= 5;
    $bg = $ok ? '#d1fae5' : '#fee2e2';
    $fg = $ok ? '#065f46' : '#991b1b';
    $balBadge = '<span style="background:' . $bg . ';color:' . $fg . ';padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;">' . number_format($balval) . ' credits</span>';
} else {
    $balBadge = '<span style="background:#f3f4f6;color:#6b7280;padding:2px 10px;border-radius:999px;font-size:12px;">Not configured</span>';
}
echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
echo '<div><strong style="color:#0369a1;">Credit Cost:</strong> <span style="color:#374151;">Each SOA costs <strong>5 credits</strong></span></div>';
echo '<div style="display:flex;align-items:center;gap:10px;">Current balance: ' . $balBadge;
if ($cfgd && !$unlim) {
    $buyurl = 'https://lms-labs.com/pricing';
    echo ' <a href="' . s($buyurl) . '" target="_blank" style="font-size:13px;color:#0369a1;text-decoration:underline;">+ Purchase credits</a>';
}
echo '</div></div>';

// ── Main 2-column layout ──────────────────────────────────────────────────────
echo '<div id="rtoc-soa-wrap" style="display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start;max-width:1400px;">';

// ═══════════════════════════════════════════════════════════════════════════════
// LEFT PANEL — Step 1: Student Picker + Step 3: SOA Options + Generate
// ═══════════════════════════════════════════════════════════════════════════════
echo '<div id="rtoc-soa-left">';

// Step 1 card
echo '<div class="rtoc-soa-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:16px;">';
echo '<h3 style="margin:0 0 14px;font-size:1rem;color:#1e3a5f;display:flex;align-items:center;gap:8px;">';
echo '<span style="background:#1e3a5f;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">1</span> Select Student</h3>';

echo '<label style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:6px;">Student</label>';
// Hidden native select — JS typeahead reads its options and writes the value back.
echo '<select id="rtoc-soa-userid" name="userid" style="display:none;" aria-hidden="true">';
foreach ($useroptions as $val => $lbl) {
    echo '<option value="' . s($val) . '">' . s($lbl) . '</option>';
}
echo '</select>';
// Typeahead container — populated by JS below.
echo '<div id="rtoc-picker-wrap" style="position:relative;"></div>';

echo '<div id="rtoc-student-card" style="display:none;margin-top:14px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;"></div>';
echo '</div>'; // step 1 card

// Step 3 card (SOA options — hidden until units loaded)
echo '<div id="rtoc-soa-options-card" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:16px;">';
echo '<h3 style="margin:0 0 14px;font-size:1rem;color:#1e3a5f;display:flex;align-items:center;gap:8px;">';
echo '<span style="background:#1e3a5f;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">3</span> SOA Options</h3>';

echo '<label style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:4px;">Document Type</label>';
echo '<select id="rtoc-soa-doctype" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:10px;box-sizing:border-box;">';
echo '<option value="qualification">Part of Qualification</option>';
echo '<option value="skillset">Part of Skill Set</option>';
echo '<option value="standalone">Standalone Units (no qual/skill set)</option>';
echo '</select>';
echo '<div id="rtoc-soa-ref-fields">';
echo '<label id="rtoc-soa-refcode-label" style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:4px;">Qualification Code</label>';
echo '<input type="text" id="rtoc-soa-qualcode" placeholder="e.g. CHC33021" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:8px;box-sizing:border-box;">';
echo '<label id="rtoc-soa-refname-label" style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:4px;">Qualification Name</label>';
echo '<input type="text" id="rtoc-soa-qualname" placeholder="e.g. Certificate III in Early Childhood Education" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;margin-bottom:8px;box-sizing:border-box;">';
echo '</div>';

echo '<label style="display:block;font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:4px;">Notes (internal)</label>';
echo '<textarea id="rtoc-soa-notes" rows="2" placeholder="Optional internal notes" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;resize:vertical;box-sizing:border-box;"></textarea>';

echo '<div style="margin-top:10px;">';
echo '<label style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:#6b7280;cursor:pointer;">';
echo '<input type="checkbox" id="rtoc-soa-bypass"> Override compliance warnings (not recommended)';
echo '</label></div>';

echo '</div>'; // options card

// Generate button card (hidden until units loaded)
echo '<div id="rtoc-soa-generate-card" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;">';
echo '<div id="rtoc-soa-selection-summary" style="font-size:0.85rem;color:#374151;margin-bottom:12px;padding:8px 10px;background:#f0f9ff;border-radius:5px;border:1px solid #bae6fd;">No units selected</div>';
echo '<div id="rtoc-soa-compliance-summary" style="margin-bottom:12px;"></div>';
echo '<button id="rtoc-soa-generate-btn" class="btn btn-primary" style="width:100%;" disabled>';
echo 'Generate SOA';
echo '</button>';
echo '<div id="rtoc-soa-result" style="margin-top:12px;display:none;"></div>';
echo '</div>'; // generate card

echo '</div>'; // left panel

// ═══════════════════════════════════════════════════════════════════════════════
// RIGHT PANEL — Step 2: Eligible Units Table + Suggested Groups
// ═══════════════════════════════════════════════════════════════════════════════
echo '<div id="rtoc-soa-right">';

// Step 2 placeholder
echo '<div id="rtoc-soa-units-placeholder" class="rtoc-soa-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:40px;text-align:center;color:#9ca3af;">';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin:0 auto 12px;display:block;opacity:0.3;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
echo '<div style="font-size:1rem;font-weight:600;margin-bottom:4px;">Select a student to load eligible units</div>';
echo '<div style="font-size:0.85rem;">Only competency-achieved units will be shown.</div>';
echo '</div>';

// Step 2 panel (hidden until loaded)
echo '<div id="rtoc-soa-units-panel" style="display:none;">';

echo '<div class="rtoc-soa-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:16px;">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">';
echo '<h3 style="margin:0;font-size:1rem;color:#1e3a5f;display:flex;align-items:center;gap:8px;">';
echo '<span style="background:#1e3a5f;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">2</span>';
echo '<span id="rtoc-unit-heading">Eligible Units</span></h3>';
echo '<div style="display:flex;flex-direction:column;gap:8px;width:100%;">';
echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
echo '<input type="text" id="rtoc-unit-search" placeholder="Search unit code, name or TP prefix\u2026" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;flex:1;min-width:180px;">';
echo '<select id="rtoc-unit-filter-group" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;"><option value="">All qualifications</option></select>';
echo '<select id="rtoc-unit-filter-compliance" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;">';
echo '<option value="">All compliance</option>';
echo '<option value="ok">Compliant only</option>';
echo '<option value="warn">Has warnings</option>';
echo '<option value="err">Has errors</option>';
echo '</select>';
echo '</div>';
echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
echo '<select id="rtoc-unit-filter-outcome" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:0.82rem;">';
echo '<option value="">All outcomes</option>';
echo '<option value="20">Competency achieved (20)</option>';
echo '<option value="51">RPL — granted (51)</option>';
echo '<option value="52">Credit transfer (52)</option>';
echo '<option value="8x">Superseded competent (81/82)</option>';
echo '</select>';
echo '<label style="display:flex;align-items:center;gap:5px;font-size:0.82rem;color:#374151;cursor:pointer;white-space:nowrap;">';
echo '<input type="checkbox" id="rtoc-unit-hide-issued"> Hide already on SOA';
echo '</label>';
echo '<label style="font-size:0.82rem;color:#6b7280;white-space:nowrap;">Completed from:</label>';
echo '<input type="date" id="rtoc-unit-date-from" style="padding:4px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:0.81rem;">';
echo '<label style="font-size:0.82rem;color:#6b7280;white-space:nowrap;">to:</label>';
echo '<input type="date" id="rtoc-unit-date-to" style="padding:4px 7px;border:1px solid #d1d5db;border-radius:6px;font-size:0.81rem;">';
echo '<button id="rtoc-unit-clear-filters" class="btn btn-sm btn-secondary" title="Clear all filters" style="white-space:nowrap;">&#10005; Clear filters</button>';
echo '</div>';
echo '</div></div>'; // filter rows + header row

// Unit table
echo '<div style="overflow-x:auto;">';
echo '<table id="rtoc-unit-table" style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
echo '<thead>';
echo '<tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">';
echo '<th style="padding:8px 10px;text-align:left;width:36px;"><input type="checkbox" id="rtoc-select-all" title="Select all visible units" style="cursor:pointer;"></th>';
echo '<th style="padding:8px 10px;text-align:left;cursor:pointer;white-space:nowrap;" data-sort="code">Unit Code <span class="rtoc-sort-icon">↕</span></th>';
echo '<th style="padding:8px 10px;text-align:left;cursor:pointer;" data-sort="title">Unit Title <span class="rtoc-sort-icon">↕</span></th>';
echo '<th style="padding:8px 10px;text-align:left;cursor:pointer;" data-sort="group">Qualification Group <span class="rtoc-sort-icon">↕</span></th>';
echo '<th style="padding:8px 10px;text-align:left;cursor:pointer;white-space:nowrap;" data-sort="date">Completed <span class="rtoc-sort-icon">↕</span></th>';
echo '<th style="padding:8px 10px;text-align:left;cursor:pointer;white-space:nowrap;" data-sort="outcome">Outcome <span class="rtoc-sort-icon">↕</span></th>';
echo '<th style="padding:8px 10px;text-align:left;white-space:nowrap;">Compliance</th>';
echo '</tr></thead>';
echo '<tbody id="rtoc-unit-tbody"></tbody>';
echo '</table>';
echo '</div>'; // overflow-x

// Table actions
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:8px;">';
echo '<div id="rtoc-table-counter" style="font-size:0.82rem;color:#6b7280;"></div>';
echo '<div style="display:flex;gap:6px;">';
echo '<button id="rtoc-select-compliant" class="btn btn-sm btn-secondary">Select all compliant</button>';
echo '<button id="rtoc-deselect-all" class="btn btn-sm btn-secondary">Deselect all</button>';
echo '</div></div>';

echo '</div>'; // card

// ── Suggested SOA Groups ────────────────────────────────────────────────────
echo '<div id="rtoc-soa-groups-panel" class="rtoc-soa-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px;">';
echo '<h3 style="margin:0 0 4px;font-size:1rem;color:#1e3a5f;">Suggested SOA Groups</h3>';
echo '<p style="margin:0 0 12px;font-size:0.82rem;color:#6b7280;">Units grouped by Moodle qualification category. "Select all" also auto-fills the qualification code &amp; name in Step 3.</p>';
echo '<div id="rtoc-groups-list"></div>';
echo '</div>';

echo '</div>'; // units panel

echo '</div>'; // right panel
echo '</div>'; // soa-wrap

// ── Loading spinner ───────────────────────────────────────────────────────────
echo '<div id="rtoc-soa-loading" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.3);z-index:9999;display:none;align-items:center;justify-content:center;">';
echo '<div style="background:#fff;border-radius:8px;padding:24px 32px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,0.18);">';
echo '<div style="font-size:1.1rem;font-weight:600;color:#1e3a5f;margin-bottom:8px;">Generating SOA...</div>';
echo '<div style="color:#6b7280;font-size:0.9rem;">Running compliance checks and building PDF.</div>';
echo '</div></div>';

// ═══════════════════════════════════════════════════════════════════════════════
// JavaScript
// ═══════════════════════════════════════════════════════════════════════════════
$ajaxUrlJs  = json_encode($ajaxurl);
$sesskeyJs  = json_encode($sesskey);

// SOA-NOWDOC-FIX (v5.9.246): The original <<<'HTML' was a PHP nowdoc (single-quoted
// delimiter = no variable interpolation), so {$ajaxUrlJs} and {$sesskeyJs} were
// emitted literally into the JS, making AJAX={} and SESSKEY={} — every fetch()
// failed and units never loaded.
//
// We CANNOT switch to a heredoc (<<<HTML) because the JS contains regex characters
// like ${}  and \\$& that PHP would try to interpolate inside a heredoc, causing a
// parse error. Instead: inject the two runtime values in a tiny separate echo before
// the nowdoc block and reference them from the outer scope, then keep the large JS
// block as a nowdoc so PHP never touches the regex dollar signs.
echo '<script>var _SOA_AJAX=' . $ajaxUrlJs . ';var _SOA_SESSKEY=' . $sesskeyJs . ';</script>' . "\n";

echo <<<'HTML'
<style>
#rtoc-unit-table thead th[data-sort]:hover { background:#eef2ff; }
#rtoc-unit-table tbody tr:hover { background:#f8fafc; }
#rtoc-unit-table tbody tr { border-bottom:1px solid #f3f4f6; }
#rtoc-unit-table tbody td { padding:7px 10px; vertical-align:top; }
.rtoc-comp-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap; }
.rtoc-comp-ok   { background:#d1fae5;color:#065f46; }
.rtoc-comp-warn { background:#fef3c7;color:#92400e; }
.rtoc-comp-err  { background:#fee2e2;color:#991b1b; }
.rtoc-group-chip { display:inline-block;padding:4px 12px;border-radius:999px;font-size:0.8rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;cursor:pointer;transition:background .15s; }
.rtoc-group-chip:hover { background:#dbeafe; }
.rtoc-group-card { border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;overflow:hidden; }
.rtoc-group-card-head { display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;cursor:pointer;user-select:none; }
.rtoc-group-card-head:hover { background:#eef2ff; }
.rtoc-group-card-body { padding:10px 14px;border-top:1px solid #e5e7eb;display:none; }
.rtoc-group-card-body.open { display:block; }
#rtoc-soa-loading { display:none!important; }
#rtoc-soa-loading.active { display:flex!important; }
</style>
<script>
(function(){
  var AJAX     = _SOA_AJAX;
  var SESSKEY  = _SOA_SESSKEY;
  var allUnits = [];
  var allGroups = [];
  var sortCol  = 'code';
  var sortDir  = 1; // 1=asc, -1=desc
  // v4.6.103 FIX-SOA-STUDENT-PICKER — track selected user ID at outer IIFE
  // scope so the generate button handler can reliably read it.  The previous
  // approach relied on reading userSel.value (the hidden native <select>)
  // which is not reliable after the inner IIFE hides the element.
  var selectedUserId = '';

  // ── Helpers ───────────────────────────────────────────────────────────────
  function qs(sel) { return document.querySelector(sel); }
  function qsa(sel) { return Array.from(document.querySelectorAll(sel)); }

  function fmtDate(ts) {
    if (!ts) return '';
    var d = new Date(ts * 1000);
    return ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2) + '/' + d.getFullYear();
  }

  // Training Package prefix from unit code (e.g. HLTAID011 → "HLT")
  function tpPrefix(unitcode) {
    if (!unitcode) return '';
    var m = unitcode.match(/^([A-Z]{2,5})(?=\d)/);
    return m ? m[1] : '';
  }

  // Short coloured chip showing AVETMISS outcome code + label
  var OUTCOME_SHORT = {
    '20': {label:'Competent', bg:'#d1fae5', fg:'#065f46'},
    '51': {label:'RPL', bg:'#dbeafe', fg:'#1e40af'},
    '52': {label:'Credit', bg:'#ede9fe', fg:'#5b21b6'},
    '81': {label:'Superseded', bg:'#f3f4f6', fg:'#374151'},
    '82': {label:'Superseded', bg:'#f3f4f6', fg:'#374151'},
    '53': {label:'RPL denied', bg:'#fee2e2', fg:'#991b1b'},
  };
  function outcomeChip(oc) {
    var info = OUTCOME_SHORT[String(oc)];
    if (!info) return '<span style="font-size:11px;color:#9ca3af;">' + escHtml(oc || '—') + '</span>';
    return '<span style="font-size:11px;font-weight:600;padding:2px 7px;border-radius:999px;background:'+info.bg+';color:'+info.fg+';" title="Outcome '+escHtml(oc)+'">'+escHtml(info.label)+'</span>';
  }

  // Auto-populate Step 3 qual code/name from a group's category data
  function autoPopulateQualFromGroup(catid) {
    var grp = allGroups.find(function(g){ return String(g.categoryid) === String(catid); });
    if (!grp || !grp.units || !grp.units.length) return;
    var sampleUnit = grp.units[0];
    var catidnum   = sampleUnit.categoryidnumber || '';
    var catname    = grp.categoryname || '';
    var qcEl = qs('#rtoc-soa-qualcode');
    var qnEl = qs('#rtoc-soa-qualname');
    // Only auto-fill if the field is currently empty
    if (qcEl && !qcEl.value && catidnum) { qcEl.value = catidnum; qcEl.style.background='#f0fdf4'; setTimeout(function(){ qcEl.style.background=''; }, 1200); }
    if (qnEl && !qnEl.value && catname)  { qnEl.value = catname;  qnEl.style.background='#f0fdf4'; setTimeout(function(){ qnEl.style.background=''; }, 1200); }
    // Reveal Step 3 card if hidden
    var oc = qs('#rtoc-soa-options-card');
    if (oc) oc.style.display = 'block';
  }

  function complianceBadge(unit) {
    var errs  = (unit.compliance && unit.compliance.errors  ) ? unit.compliance.errors   : [];
    var warns = (unit.compliance && unit.compliance.warnings) ? unit.compliance.warnings : [];
    if (errs.length > 0) {
      var tip = errs.concat(warns).join(' | ');
      return '<span class="rtoc-comp-badge rtoc-comp-err" title="'+escHtml(tip)+'">&#10007; Blocked (' + errs.length + ')</span>';
    }
    if (warns.length > 0) {
      var tip2 = warns.join(' | ');
      return '<span class="rtoc-comp-badge rtoc-comp-warn" title="'+escHtml(tip2)+'">&#9888; Warning (' + warns.length + ')</span>';
    }
    return '<span class="rtoc-comp-badge rtoc-comp-ok">&#10003; Compliant</span>';
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function post(params, cb) {
    var fd = new FormData();
    fd.append('sesskey', SESSKEY);
    for (var k in params) fd.append(k, params[k]);
    fetch(AJAX, {method:'POST', body:fd})
      .then(function(r){ return r.json(); })
      .then(cb)
      .catch(function(e){ cb({ok:false, error:String(e)}); });
  }

  // ── Student picker ─────────────────────────────────────────────────────────
  // FIX-STUDENT-PICKER (v4.9.142): Rebuilt as a proper typeahead.
  //   • Surname first display ("Smith, John") — alphabetical by surname
  //   • Live search across surname, firstname and email
  //   • Two-line result rows — name bold/large, email muted below
  //   • Highlighted matching text in results
  //   • Keyboard navigation (↑ ↓ Enter Escape)
  //   • Result count shown at top of dropdown
  //   • × clear button to reset and pick a different student
  var userSel = qs('#rtoc-soa-userid');

  (function() {
    var wrap = qs('#rtoc-picker-wrap');

    // Search input
    var inputWrap = document.createElement('div');
    inputWrap.style.cssText = 'position:relative;';

    var searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Type surname, first name or email\u2026';
    searchInput.autocomplete = 'off';
    searchInput.style.cssText = 'width:100%;padding:7px 32px 7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;box-sizing:border-box;background:#fff;';
    inputWrap.appendChild(searchInput);

    // Clear (×) button — only visible once a student is selected
    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.innerHTML = '&times;';
    clearBtn.title = 'Clear — pick a different student';
    clearBtn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9ca3af;font-size:1.2rem;line-height:1;display:none;padding:2px 4px;';
    inputWrap.appendChild(clearBtn);
    wrap.appendChild(inputWrap);

    // Dropdown panel
    var dropdown = document.createElement('div');
    dropdown.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #d1d5db;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,0.12);max-height:300px;overflow-y:auto;width:100%;display:none;left:0;top:calc(100% + 3px);';
    wrap.appendChild(dropdown);

    // Build option list from hidden native select (labels already "Surname, Firstname (email)")
    var opts = Array.from(userSel.options)
      .filter(function(o){ return o.value !== ''; })
      .map(function(o){
        var label = o.text;
        var emailMatch = label.match(/\(([^)]+)\)$/);
        var email = emailMatch ? emailMatch[1] : '';
        var namePart = emailMatch ? label.slice(0, label.lastIndexOf('(')).trim() : label;
        var commaIdx = namePart.indexOf(',');
        var surname   = commaIdx > -1 ? namePart.slice(0, commaIdx).trim() : namePart;
        var firstname = commaIdx > -1 ? namePart.slice(commaIdx + 1).trim() : '';
        return {val: o.value, label: label, surname: surname, firstname: firstname, email: email};
      });

    var activeIdx = -1;
    var visible   = [];

    function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function hi(s, q) {
      if (!q) return esc(s);
      return esc(s).replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'),
        '<mark style="background:#fef9c3;padding:0;border-radius:2px;font-style:normal;">$1</mark>');
    }

    function showDropdown(q) {
      var q2 = q.toLowerCase().trim();
      visible = opts.filter(function(o){
        return !q2 || o.label.toLowerCase().indexOf(q2) > -1;
      }).slice(0, 80);
      activeIdx = -1;
      render(q2);
      dropdown.style.display = 'block';
    }

    function render(q2) {
      dropdown.innerHTML = '';

      // Result count header
      var hdr = document.createElement('div');
      var count = visible.length;
      hdr.style.cssText = 'padding:5px 12px;font-size:0.73rem;color:#6b7280;background:#f9fafb;border-bottom:1px solid #f0f0f0;border-radius:6px 6px 0 0;letter-spacing:0.02em;';
      hdr.textContent = count === 0 ? 'No students found'
        : count + (count === 80 ? '+' : '') + ' student' + (count !== 1 ? 's' : '') + (q2 ? ' matching \u201c' + q2 + '\u201d' : '');
      dropdown.appendChild(hdr);

      visible.forEach(function(o, idx) {
        var item = document.createElement('div');
        item.style.cssText = 'padding:9px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;';
        item.setAttribute('data-idx', String(idx));
        item.innerHTML =
          '<div style="font-size:0.88rem;line-height:1.3;">' +
            '<strong style="color:#111827;">' + hi(o.surname, q2) + '</strong>' +
            (o.firstname ? '<span style="color:#4b5563;">, ' + hi(o.firstname, q2) + '</span>' : '') +
          '</div>' +
          '<div style="font-size:0.76rem;color:#9ca3af;margin-top:2px;">' + hi(o.email, q2) + '</div>';

        item.addEventListener('mouseenter', function(){ setActive(idx); });
        var _md = false;
        item.addEventListener('mousedown', function(e){ e.preventDefault(); _md = true; selectOpt(o); });
        item.addEventListener('click', function(){ if (_md){ _md=false; return; } selectOpt(o); });
        dropdown.appendChild(item);
      });
    }

    function setActive(idx) {
      activeIdx = idx;
      Array.from(dropdown.querySelectorAll('[data-idx]')).forEach(function(el, i){
        el.style.background = (i === idx) ? '#eff6ff' : '';
      });
    }

    function selectOpt(o) {
      searchInput.value    = o.surname + ', ' + o.firstname + (o.email ? '  \u2014  ' + o.email : '');
      searchInput.readOnly = true;
      searchInput.style.background = '#f0f9ff';
      searchInput.style.borderColor = '#93c5fd';
      clearBtn.style.display = 'block';
      dropdown.style.display = 'none';
      selectedUserId = o.val;
      userSel.value  = o.val;
      onStudentChange(o.val);
    }

    function clearPicker() {
      searchInput.value    = '';
      searchInput.readOnly = false;
      searchInput.style.background  = '#fff';
      searchInput.style.borderColor = '#d1d5db';
      clearBtn.style.display = 'none';
      dropdown.style.display = 'none';
      selectedUserId = '';
      userSel.value  = '';
      // Reset downstream panels
      var sc = qs('#rtoc-student-card');
      if (sc) { sc.style.display='none'; sc.innerHTML=''; }
      var up = qs('#rtoc-soa-units-placeholder');
      if (up) up.style.display = '';
      var upanel = qs('#rtoc-soa-units-panel');
      if (upanel) upanel.style.display = 'none';
      var oc = qs('#rtoc-soa-options-card');
      if (oc) oc.style.display = 'none';
      var gc = qs('#rtoc-soa-generate-card');
      if (gc) gc.style.display = 'none';
      searchInput.focus();
    }

    searchInput.addEventListener('focus', function(){
      if (!searchInput.readOnly) showDropdown(searchInput.value);
    });
    searchInput.addEventListener('input', function(){
      showDropdown(this.value);
    });
    searchInput.addEventListener('blur', function(){
      setTimeout(function(){ dropdown.style.display='none'; }, 200);
    });
    searchInput.addEventListener('keydown', function(e){
      if (dropdown.style.display === 'none' && !searchInput.readOnly) {
        showDropdown(searchInput.value); return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive(Math.min(activeIdx + 1, visible.length - 1));
        var el = dropdown.querySelector('[data-idx="' + activeIdx + '"]');
        if (el) el.scrollIntoView({block:'nearest'});
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(Math.max(activeIdx - 1, 0));
        var el2 = dropdown.querySelector('[data-idx="' + activeIdx + '"]');
        if (el2) el2.scrollIntoView({block:'nearest'});
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIdx >= 0 && visible[activeIdx]) selectOpt(visible[activeIdx]);
      } else if (e.key === 'Escape') {
        dropdown.style.display = 'none';
      }
    });

    clearBtn.addEventListener('mousedown', function(e){ e.preventDefault(); clearPicker(); });
  })();

  function onStudentChange(userid) {
    if (!userid) return;
    loadStudentCard(userid);
    loadUnits(userid);
  }

  // ── Student card ──────────────────────────────────────────────────────────
  function loadStudentCard(userid) {
    var card = qs('#rtoc-student-card');
    card.style.display = 'block';
    card.innerHTML = '<div style="color:#6b7280;font-size:0.82rem;">Loading student info...</div>';
    post({action:'getstudent', userid:userid}, function(r){
      if (!r.ok || r.data.error) {
        card.innerHTML = '<div style="color:#dc2626;font-size:0.82rem;">Student not found</div>';
        return;
      }
      var d = r.data;
      var usiHtml;
      if (!d.usi) {
        usiHtml = '<span style="color:#dc2626;font-weight:600;">&#10007; USI not recorded</span>';
      } else if (!d.usiverified) {
        usiHtml = '<span style="color:#d97706;font-weight:600;">&#9888; USI not verified</span>';
      } else {
        usiHtml = '<span style="color:#059669;font-weight:600;">&#10003; USI verified</span>';
      }
      var activeHtml = d.active
        ? '<span style="color:#059669;">&#10003; Active</span>'
        : '<span style="color:#dc2626;">&#10007; Suspended/deleted</span>';
      card.innerHTML =
        '<div style="font-weight:700;font-size:0.95rem;color:#1e3a5f;margin-bottom:6px;">'+escHtml(d.fullname)+'</div>'+
        '<div style="color:#6b7280;font-size:0.8rem;margin-bottom:2px;">'+escHtml(d.email)+'</div>'+
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-top:8px;font-size:0.82rem;">'+
          '<div>Status: '+activeHtml+'</div>'+
          '<div>USI: '+usiHtml+'</div>'+
          '<div style="color:#374151;">Completed units: <strong>'+d.completedunits+'</strong></div>'+
          '<div style="color:#374151;">Existing SOAs: <strong>'+d.existingsoas+'</strong></div>'+
        '</div>';
    });
  }

  // ── Load eligible units ───────────────────────────────────────────────────
  function loadUnits(userid) {
    qs('#rtoc-soa-units-placeholder').style.display = 'none';
    qs('#rtoc-soa-units-panel').style.display = 'none';
    qs('#rtoc-unit-heading').textContent = 'Loading eligible units...';
    qs('#rtoc-soa-units-panel').style.display = 'block';
    qs('#rtoc-unit-tbody').innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#9ca3af;">Loading...</td></tr>';

    post({action:'getunits', userid:userid}, function(r){
      if (!r.ok) {
        qs('#rtoc-unit-tbody').innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#dc2626;">'+escHtml(r.error)+'</td></tr>';
        return;
      }
      allUnits  = r.units  || [];
      allGroups = r.groups || [];

      qs('#rtoc-soa-options-card').style.display  = 'block';
      qs('#rtoc-soa-generate-card').style.display = 'block';

      populateGroupFilter();
      renderTable();
      renderGroups();
      updateSelectionSummary();
    });
  }

  // ── Group filter dropdown ─────────────────────────────────────────────────
  function populateGroupFilter() {
    var sel = qs('#rtoc-unit-filter-group');
    sel.innerHTML = '<option value="">All qualifications</option>';
    var seen = {};
    allUnits.forEach(function(u){
      if (!seen[u.categoryid]) {
        seen[u.categoryid] = true;
        var o = document.createElement('option');
        o.value = u.categoryid;
        o.textContent = u.categoryname;
        sel.appendChild(o);
      }
    });
  }

  // ── Render table ──────────────────────────────────────────────────────────
  function getFilteredUnits() {
    var q        = (qs('#rtoc-unit-search').value || '').toLowerCase().trim();
    var grp      = qs('#rtoc-unit-filter-group').value;
    var comp     = qs('#rtoc-unit-filter-compliance').value;
    var outcomeF = qs('#rtoc-unit-filter-outcome').value;
    var hideIssued = qs('#rtoc-unit-hide-issued').checked;
    var dateFrom = qs('#rtoc-unit-date-from').value; // 'YYYY-MM-DD' or ''
    var dateTo   = qs('#rtoc-unit-date-to').value;

    var tsFrom = dateFrom ? (new Date(dateFrom).getTime() / 1000) : 0;
    var tsTo   = dateTo   ? (new Date(dateTo + 'T23:59:59').getTime() / 1000) : 0;

    return allUnits.filter(function(u){
      // Text search — unit code, title, and TP prefix
      if (q) {
        var tp = tpPrefix(u.unitcode).toLowerCase();
        if (u.unitcode.toLowerCase().indexOf(q) === -1 &&
            u.unittitle.toLowerCase().indexOf(q) === -1 &&
            tp.indexOf(q) === -1) return false;
      }
      // Qualification group filter
      if (grp && String(u.categoryid) !== String(grp)) return false;
      // Compliance filter
      if (comp === 'ok'   && (!u.compliant || (u.compliance.warnings && u.compliance.warnings.length))) return false;
      if (comp === 'warn' && (!u.compliance.warnings || !u.compliance.warnings.length)) return false;
      if (comp === 'err'  && (!u.compliance.errors   || !u.compliance.errors.length))   return false;
      // Outcome type filter
      if (outcomeF) {
        var oc = String(u.outcomeidentifier || '');
        if (outcomeF === '8x') { if (oc !== '81' && oc !== '82') return false; }
        else { if (oc !== outcomeF) return false; }
      }
      // Hide already-issued
      if (hideIssued && u.already_on_soa) return false;
      // Date range
      if (tsFrom && u.completiondate && u.completiondate < tsFrom) return false;
      if (tsTo   && u.completiondate && u.completiondate > tsTo)   return false;
      return true;
    });
  }

  function renderTable() {
    var filtered = getFilteredUnits();

    // Sort
    filtered.sort(function(a, b){
      var av, bv;
      if (sortCol === 'code')    { av = a.unitcode;          bv = b.unitcode; }
      else if (sortCol === 'title')   { av = a.unittitle;    bv = b.unittitle; }
      else if (sortCol === 'group')   { av = a.categoryname; bv = b.categoryname; }
      else if (sortCol === 'date')    { av = a.completiondate || 0; bv = b.completiondate || 0; }
      else if (sortCol === 'outcome') { av = String(a.outcomeidentifier || ''); bv = String(b.outcomeidentifier || ''); }
      else { av = a.unitcode; bv = b.unitcode; }
      if (av < bv) return -1 * sortDir;
      if (av > bv) return  1 * sortDir;
      return 0;
    });

    // Heading counter shows active filter count as a hint
    var activeFilters = 0;
    if ((qs('#rtoc-unit-search').value || '').trim()) activeFilters++;
    if (qs('#rtoc-unit-filter-group').value)     activeFilters++;
    if (qs('#rtoc-unit-filter-compliance').value) activeFilters++;
    if (qs('#rtoc-unit-filter-outcome').value)   activeFilters++;
    if (qs('#rtoc-unit-hide-issued').checked)    activeFilters++;
    if (qs('#rtoc-unit-date-from').value)        activeFilters++;
    if (qs('#rtoc-unit-date-to').value)          activeFilters++;

    qs('#rtoc-unit-heading').textContent = 'Eligible Units (' + allUnits.length + ')';
    qs('#rtoc-table-counter').textContent =
      'Showing ' + filtered.length + ' of ' + allUnits.length + ' units' +
      (activeFilters ? '  (' + activeFilters + ' filter' + (activeFilters > 1 ? 's' : '') + ' active)' : '');

    var tbody = qs('#rtoc-unit-tbody');
    if (!filtered.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#9ca3af;">No units match the current filters</td></tr>';
      return;
    }

    // Preserve checked state by courseid
    var checked = getCheckedCourseIds();

    tbody.innerHTML = filtered.map(function(u){
      var isChecked = checked[u.courseid] ? 'checked' : '';
      var tp = tpPrefix(u.unitcode);
      var tpBadge = tp
        ? '<span style="font-size:10px;font-family:monospace;background:#f1f5f9;color:#475569;padding:1px 5px;border-radius:3px;margin-right:4px;vertical-align:middle;" title="Training Package: '+escHtml(tp)+'">'+escHtml(tp)+'</span>'
        : '';
      var alreadyHtml = u.already_on_soa
        ? ' <span style="font-size:10px;background:#e0e7ff;color:#4338ca;padding:1px 6px;border-radius:999px;">On existing SOA</span>'
        : '';
      return '<tr data-courseid="'+u.courseid+'" data-compliant="'+(u.compliant?'1':'0')+'" data-haswarn="'+((u.compliance.warnings&&u.compliance.warnings.length)?'1':'0')+'" data-haserr="'+((u.compliance.errors&&u.compliance.errors.length)?'1':'0')+'" data-catid="'+u.categoryid+'">' +
        '<td><input type="checkbox" class="rtoc-unit-cb" data-courseid="'+u.courseid+'" '+isChecked+'></td>'+
        '<td>'+tpBadge+'<strong style="color:#1e3a5f;font-family:monospace;font-size:0.83rem;">'+escHtml(u.unitcode)+'</strong>'+alreadyHtml+'</td>'+
        '<td>'+escHtml(u.unittitle)+'</td>'+
        '<td style="color:#6b7280;font-size:0.8rem;">'+escHtml(u.categoryname)+'</td>'+
        '<td style="white-space:nowrap;color:#6b7280;font-size:0.82rem;">'+fmtDate(u.completiondate)+'</td>'+
        '<td>'+outcomeChip(u.outcomeidentifier)+'</td>'+
        '<td>'+complianceBadge(u)+'</td>'+
        '</tr>';
    }).join('');

    // Bind row checkboxes
    qsa('.rtoc-unit-cb').forEach(function(cb){
      cb.addEventListener('change', updateSelectionSummary);
    });
  }

  function getCheckedCourseIds() {
    var map = {};
    qsa('.rtoc-unit-cb:checked').forEach(function(cb){ map[cb.dataset.courseid] = true; });
    return map;
  }

  // ── Suggested groups ──────────────────────────────────────────────────────
  function renderGroups() {
    var container = qs('#rtoc-groups-list');
    if (!allGroups.length) {
      container.innerHTML = '<div style="color:#9ca3af;font-size:0.85rem;">No qualification groups detected. Ensure courses are placed inside category folders in Moodle.</div>';
      return;
    }
    container.innerHTML = allGroups.map(function(g){
      var compliantCount = g.units.filter(function(u){ return u.compliant; }).length;
      var totalCount = g.units.length;
      // Progress fraction for the group
      var progressPct = totalCount ? Math.round((compliantCount / totalCount) * 100) : 0;
      var progressColor = progressPct === 100 ? '#059669' : progressPct >= 60 ? '#d97706' : '#dc2626';
      // Category ID number (qual code from Moodle category idnumber)
      var catIdNum = (g.units.length && g.units[0].categoryidnumber) ? g.units[0].categoryidnumber : '';
      var catIdBadge = catIdNum
        ? '<span style="font-size:11px;font-family:monospace;background:#eff6ff;color:#1d4ed8;padding:1px 7px;border-radius:3px;margin-left:6px;" title="Moodle category idnumber / qualification code">'+escHtml(catIdNum)+'</span>'
        : '';
      var badge = '<span style="font-size:11px;background:#d1fae5;color:#065f46;padding:1px 8px;border-radius:999px;margin-left:6px;">'+compliantCount+'/'+totalCount+' compliant</span>';
      var warnBadge = (totalCount - compliantCount) > 0
        ? '<span style="font-size:11px;background:#fee2e2;color:#991b1b;padding:1px 8px;border-radius:999px;margin-left:4px;">'+(totalCount-compliantCount)+' blocked</span>'
        : '';
      return '<div class="rtoc-group-card">'+
        '<div class="rtoc-group-card-head" onclick="this.nextElementSibling.classList.toggle(\'open\');this.querySelector(\'.rtoc-chevron\').style.transform=this.nextElementSibling.classList.contains(\'open\')?\'rotate(90deg)\':\'\'">'+
          '<div style="flex:1;min-width:0;">'+
            '<div style="font-weight:600;font-size:0.9rem;color:#1e3a5f;">'+escHtml(g.categoryname)+catIdBadge+badge+warnBadge+'</div>'+
            '<div style="margin-top:5px;background:#e5e7eb;border-radius:999px;height:4px;width:100%;overflow:hidden;">'+
              '<div style="height:4px;border-radius:999px;background:'+progressColor+';width:'+progressPct+'%;transition:width .4s;"></div>'+
            '</div>'+
          '</div>'+
          '<div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:12px;">'+
            '<button class="btn btn-sm btn-secondary rtoc-grp-select-btn" data-catid="'+g.categoryid+'" onclick="event.stopPropagation();selectGroup('+g.categoryid+',false)">Select all</button>'+
            '<button class="btn btn-sm btn-primary rtoc-grp-select-ok-btn" data-catid="'+g.categoryid+'" onclick="event.stopPropagation();selectGroup('+g.categoryid+',true)" title="Select only compliant units in this group">Select compliant</button>'+
            '<svg class="rtoc-chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color:#9ca3af;transition:transform .2s;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>'+
          '</div>'+
        '</div>'+
        '<div class="rtoc-group-card-body">'+
          g.units.map(function(u){
            var tp = tpPrefix(u.unitcode);
            var tpSpan = tp ? '<span style="font-size:10px;font-family:monospace;background:#f1f5f9;color:#475569;padding:1px 4px;border-radius:3px;margin-right:4px;">'+escHtml(tp)+'</span>' : '';
            return '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f3f4f6;">'+
              '<input type="checkbox" class="rtoc-unit-cb-grp" data-courseid="'+u.courseid+'" style="cursor:pointer;" onchange="document.querySelector(\'.rtoc-unit-cb[data-courseid=\\\''+ u.courseid +'\\\']\')?document.querySelector(\'.rtoc-unit-cb[data-courseid=\\\''+ u.courseid +'\\\']\').checked=this.checked:null;updateSelectionSummary();">'+
              tpSpan+
              '<span style="font-family:monospace;font-weight:700;color:#1e3a5f;font-size:0.82rem;">'+escHtml(u.unitcode)+'</span>'+
              outcomeChip(u.outcomeidentifier)+
              '<span style="font-size:0.82rem;color:#374151;flex:1;">'+escHtml(u.unittitle)+'</span>'+
              complianceBadge(u)+
            '</div>';
          }).join('')+
        '</div>'+
      '</div>';
    }).join('');
  }

  window.selectGroup = function(catid, compliantOnly) {
    allUnits.forEach(function(u){
      if (String(u.categoryid) !== String(catid)) return;
      if (compliantOnly && !u.compliant) return;
      var cb = qs('.rtoc-unit-cb[data-courseid="'+u.courseid+'"]');
      if (cb) cb.checked = true;
      var cbg = qs('.rtoc-unit-cb-grp[data-courseid="'+u.courseid+'"]');
      if (cbg) cbg.checked = true;
    });
    // Auto-populate qualification code/name in Step 3 from categoryidnumber
    autoPopulateQualFromGroup(catid);
    updateSelectionSummary();
  };

  // ── Selection summary + compliance gate ───────────────────────────────────
  window.updateSelectionSummary = function() {
    var checked = getCheckedCourseIds();
    var selected = allUnits.filter(function(u){ return checked[u.courseid]; });
    var errCount  = 0;
    var warnCount = 0;
    selected.forEach(function(u){
      if (u.compliance.errors   && u.compliance.errors.length)   errCount++;
      if (u.compliance.warnings && u.compliance.warnings.length) warnCount++;
    });

    var sumEl = qs('#rtoc-soa-selection-summary');
    var bypass = qs('#rtoc-soa-bypass').checked;

    if (!selected.length) {
      sumEl.innerHTML = '<span style="color:#6b7280;">No units selected</span>';
    } else {
      sumEl.innerHTML =
        '<strong style="color:#1e3a5f;">' + selected.length + ' unit' + (selected.length!==1?'s':'') + ' selected</strong>' +
        (errCount  ? ' &nbsp;<span style="color:#dc2626;">&#10007; '+errCount+' blocked</span>' : '') +
        (warnCount ? ' &nbsp;<span style="color:#d97706;">&#9888; '+warnCount+' warning'+(warnCount!==1?'s':'')+'</span>' : '') +
        (!errCount && !warnCount ? ' &nbsp;<span style="color:#059669;">&#10003; All compliant</span>' : '');
    }

    var compSum = qs('#rtoc-soa-compliance-summary');
    if (errCount && !bypass) {
      compSum.innerHTML = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:5px;padding:8px 10px;font-size:0.82rem;color:#991b1b;">'+
        '&#10007; ' + errCount + ' selected unit' + (errCount!==1?'s have':' has') + ' compliance errors. Resolve errors or tick "Override" to proceed.</div>';
    } else if (warnCount) {
      compSum.innerHTML = '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:5px;padding:8px 10px;font-size:0.82rem;color:#92400e;">'+
        '&#9888; ' + warnCount + ' unit'+(warnCount!==1?'s have':' has')+' warnings. Review before generating.</div>';
    } else {
      compSum.innerHTML = '';
    }

    var btn = qs('#rtoc-soa-generate-btn');
    btn.disabled = (!selected.length) || (errCount > 0 && !bypass);
    btn.textContent = selected.length ? 'Generate SOA (' + selected.length + ' unit' + (selected.length!==1?'s':'') + ')' : 'Generate SOA';
  };

  // ── Table sorting ─────────────────────────────────────────────────────────
  qs('#rtoc-unit-table').addEventListener('click', function(e){
    var th = e.target.closest('th[data-sort]');
    if (!th) return;
    var col = th.dataset.sort;
    if (sortCol === col) sortDir = -sortDir;
    else { sortCol = col; sortDir = 1; }
    renderTable();
  });

  // ── Filters ───────────────────────────────────────────────────────────────
  ['#rtoc-unit-search','#rtoc-unit-filter-group','#rtoc-unit-filter-compliance',
   '#rtoc-unit-filter-outcome','#rtoc-unit-date-from','#rtoc-unit-date-to'].forEach(function(sel){
    var el = qs(sel);
    if (el) el.addEventListener('input', renderTable);
    if (el) el.addEventListener('change', renderTable);
  });
  var hideIssuedEl = qs('#rtoc-unit-hide-issued');
  if (hideIssuedEl) hideIssuedEl.addEventListener('change', renderTable);

  // Clear all filters button
  var clearFiltersBtn = qs('#rtoc-unit-clear-filters');
  if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', function(){
      var ids = ['#rtoc-unit-search','#rtoc-unit-filter-group','#rtoc-unit-filter-compliance','#rtoc-unit-filter-outcome','#rtoc-unit-date-from','#rtoc-unit-date-to'];
      ids.forEach(function(sel){ var el = qs(sel); if (el) el.value = ''; });
      var hie = qs('#rtoc-unit-hide-issued');
      if (hie) hie.checked = false;
      renderTable();
    });
  }

  // ── Document type selector — update labels and show/hide ref fields ────────
  var doctype = qs('#rtoc-soa-doctype');
  if (doctype) {
    doctype.addEventListener('change', function(){
      var val = this.value;
      var refFields = qs('#rtoc-soa-ref-fields');
      var codeLabel = qs('#rtoc-soa-refcode-label');
      var nameLabel = qs('#rtoc-soa-refname-label');
      var codeInput = qs('#rtoc-soa-qualcode');
      var nameInput = qs('#rtoc-soa-qualname');
      if (val === 'standalone') {
        if (refFields) refFields.style.display = 'none';
      } else {
        if (refFields) refFields.style.display = '';
        if (val === 'skillset') {
          if (codeLabel) codeLabel.textContent = 'Skill Set Code';
          if (nameLabel) nameLabel.textContent = 'Skill Set Name';
          if (codeInput) codeInput.placeholder = 'e.g. HLTSS00049';
          if (nameInput) nameInput.placeholder = 'e.g. Support Worker Skill Set';
        } else {
          if (codeLabel) codeLabel.textContent = 'Qualification Code';
          if (nameLabel) nameLabel.textContent = 'Qualification Name';
          if (codeInput) codeInput.placeholder = 'e.g. CHC33021';
          if (nameInput) nameInput.placeholder = 'e.g. Certificate III in Early Childhood Education';
        }
      }
    });
  }

  // ── Select all / deselect ─────────────────────────────────────────────────
  qs('#rtoc-select-all').addEventListener('change', function(){
    var on = this.checked;
    qsa('.rtoc-unit-cb').forEach(function(cb){ cb.checked = on; });
    updateSelectionSummary();
  });
  qs('#rtoc-select-compliant').addEventListener('click', function(){
    qsa('.rtoc-unit-cb').forEach(function(cb){
      var tr = cb.closest('tr');
      cb.checked = tr && tr.dataset.haserr === '0';
    });
    updateSelectionSummary();
  });
  qs('#rtoc-deselect-all').addEventListener('click', function(){
    qsa('.rtoc-unit-cb').forEach(function(cb){ cb.checked = false; });
    updateSelectionSummary();
  });

  // Bypass checkbox
  qs('#rtoc-soa-bypass').addEventListener('change', updateSelectionSummary);

  // ── Generate SOA ──────────────────────────────────────────────────────────
  qs('#rtoc-soa-generate-btn').addEventListener('click', function(){
    // Use outer-scope selectedUserId (set by the student picker) as the
    // reliable source; fall back to the hidden native select as a safety net.
    var userid = selectedUserId || qs('#rtoc-soa-userid').value;
    if (!userid) { alert('Please select a student first.'); return; }

    var checked = getCheckedCourseIds();
    var selected = allUnits.filter(function(u){ return checked[u.courseid]; });
    if (!selected.length) { alert('Please select at least one unit.'); return; }

    var courseids = JSON.stringify(selected.map(function(u){ return u.courseid; }));

    var params = {
      action:    'generatesoa',
      userid:    userid,
      courseids: courseids,
      audience:  'default',
      doctype:   qs('#rtoc-soa-doctype').value,
      qualcode:  (qs('#rtoc-soa-doctype').value === 'standalone') ? '' : qs('#rtoc-soa-qualcode').value.trim(),
      qualname:  (qs('#rtoc-soa-doctype').value === 'standalone') ? '' : qs('#rtoc-soa-qualname').value.trim(),
      notes:     qs('#rtoc-soa-notes').value.trim(),
      bypass:    qs('#rtoc-soa-bypass').checked ? 1 : 0,
    };

    var overlay = qs('#rtoc-soa-loading');
    overlay.classList.add('active');
    var btn = qs('#rtoc-soa-generate-btn');
    btn.disabled = true;

    post(params, function(r){
      overlay.classList.remove('active');
      btn.disabled = false;
      var resultEl = qs('#rtoc-soa-result');
      resultEl.style.display = 'block';

      if (r.ok) {
        resultEl.innerHTML =
          '<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:14px;color:#065f46;">'+
          '<strong>&#10003; SOA Issued Successfully!</strong><br>'+
          'Certificate number: <strong>'+escHtml(r.certnumber)+'</strong><br>'+
          r.unitcount+' unit'+(r.unitcount!==1?'s':'')+' listed on SOA.<br><br>'+
          '<a href="'+r.downloadurl+'" class="btn btn-sm btn-primary" target="_blank">Download PDF</a> &nbsp;'+
          '<a href="'+r.viewurl+'" class="btn btn-sm btn-secondary">View All Certificates</a>'+
          '</div>';
        // Clear selection
        qsa('.rtoc-unit-cb').forEach(function(cb){ cb.checked = false; });
        updateSelectionSummary();
        // Reload units to reflect newly issued SOA
        loadUnits(userid);
      } else {
        var detail = '';
        if (r.detail && r.detail.length) {
          detail = '<ul style="margin:6px 0 0;padding-left:16px;">'+r.detail.map(function(d){ return '<li>'+escHtml(d)+'</li>'; }).join('')+'</ul>';
        }
        resultEl.innerHTML =
          '<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:14px;color:#991b1b;">'+
          '<strong>&#10007; Could not generate SOA</strong><br>'+escHtml(r.error)+detail+
          (r.buyUrl ? '<br><br><a href="'+escHtml(r.buyUrl)+'" target="_blank" class="btn btn-sm btn-primary">Purchase Credits</a>' : '')+
          '</div>';
        updateSelectionSummary();
      }
    });
  });

})();
</script>
HTML;

echo $OUTPUT->footer();
