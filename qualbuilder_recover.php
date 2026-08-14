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
 * RTO Compliance plugin — qualbuilder_recover.php.
 *
 * Recover Unmapped Completions. Scans the Moodle completions the sync could not match to a
 * unit, proposes how each unrecognised (superseded / renumbered) unit code maps to a CURRENT
 * unit in a Qual Builder product, and — on confirmation — writes those old→current lines into
 * the plugin's supersededunitmap setting so the next Sync credits the affected completions.
 * Writes ONLY plugin config; touches no Moodle categories, courses, accounts or enrolments.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_qualbuilder');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$PAGE->set_url('/local/rtocompliance/qualbuilder_recover.php');
$PAGE->set_title('Recover Unmapped Completions');
$PAGE->set_heading('Recover Unmapped Completions');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add('Recover Unmapped Completions');

// ── APPLY (POST) ──────────────────────────────────────────────────────────────
if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $pairs = optional_param_array('map', [], PARAM_ALPHANUMEXT); // values "OLD:CURRENT"
    $mappings = [];
    foreach ($pairs as $p) {
        $bits = explode(':', $p, 2);
        if (count($bits) === 2 && $bits[0] !== '' && $bits[1] !== '') {
            $mappings[$bits[0]] = $bits[1];
        }
    }
    $res = local_rtocompliance_recover_apply($mappings);
    $n   = count($res['added']);
    $msg = $n
        ? ($n . ' supersession mapping(s) added. Now run "Sync results from Moodle completions" on Student Results to credit the recovered completions.')
        : 'No new mappings were added (they may already exist).';
    redirect(new moodle_url('/local/rtocompliance/qualbuilder_recover.php'),
        $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── SCAN (GET) ────────────────────────────────────────────────────────────────
\core_php_time_limit::raise(300);
raise_memory_limit(MEMORY_HUGE);
$scan       = local_rtocompliance_recover_scan();
$proposals  = $scan['proposals'];
$unresolved = $scan['unresolved'];

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Recover Unmapped Completions', null, null, 'qualbuilder');
echo local_rtocompliance_page_banner('Recover Unmapped Completions',
    'Map superseded / renumbered unit codes from archived courses to your current units.');

echo html_writer::start_div('', ['style' => 'max-width:1100px;']);

echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#6366f1;margin-bottom:18px;">'
    . '<strong style="font-size:16px;">What this does</strong><br>'
    . '<span style="font-size:15px;color:#475569;line-height:1.65;">'
    . 'The Moodle sync could not credit some completions because the archived course uses an '
    . '<strong>old unit code</strong> (a superseded version like <code>ABC12345</code>, or a renumbered '
    . 'code). This tool proposes how each old code maps to a <strong>current unit that exists in one of your '
    . 'Qual Builder products</strong>, and — once you confirm — records it in the supersededunitmap setting so '
    . 'the reconciler translates it on the next Sync. It only writes that setting; nothing in Moodle changes. '
    . 'Codes with <em>no</em> current unit to map to are listed separately — those need the superseded '
    . 'qualification to be built (Auto-Create) before they can be credited.'
    . '</span></div>';

// ── PROPOSALS ─────────────────────────────────────────────────────────────────
if (!empty($proposals)) {
    echo '<form method="post" action="qualbuilder_recover.php">';
    echo '<input type="hidden" name="action" value="apply">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

    echo '<h4 style="margin:6px 0 4px;">' . count($proposals) . ' old code(s) can be mapped to a current unit</h4>';
    echo '<p style="color:#64748b;font-size:0.86em;margin:0 0 10px;">Version-letter drops are ticked by default. '
        . 'Name matches are proposed for single-unit courses — review each before confirming.</p>';

    echo '<table class="table table-sm table-hover" style="font-size:0.88em;">';
    echo '<thead class="thead-light"><tr>'
        . '<th style="width:34px;"><input type="checkbox" id="rtoc-rc-all"></th>'
        . '<th>Old code</th><th>&rarr; Current unit</th><th>Qualification</th>'
        . '<th>Match</th><th style="text-align:center;">Completions</th><th>Example archived course</th>'
        . '</tr></thead><tbody>';

    foreach ($proposals as $old => $p) {
        $isversion = ($p['conf'] === 'version');
        $badge = $isversion
            ? '<span class="badge badge-success">Version</span>'
            : '<span class="badge badge-warning" title="Matched by unit name on a single-unit course — verify the equivalence">Name &#9888;</span>';
        $checked = $isversion ? ' checked' : '';
        $val = s($old) . ':' . s($p['current']);
        echo '<tr>';
        echo '<td><input type="checkbox" class="rtoc-rc-cb" name="map[]" value="' . $val . '"' . $checked . '></td>';
        echo '<td><code>' . s($old) . '</code></td>';
        echo '<td><code>' . s($p['current']) . '</code> <span style="color:#64748b;">' . s($p['unitname']) . '</span></td>';
        echo '<td><code>' . s($p['qualcode']) . '</code></td>';
        echo '<td>' . $badge . '</td>';
        echo '<td style="text-align:center;font-weight:600;">' . (int)$p['completions'] . '</td>';
        echo '<td style="color:#94a3b8;font-size:0.92em;">' . s($p['examples'][0] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<button type="submit" class="btn btn-primary" style="margin-top:6px;">Add selected mappings</button> ';
    echo html_writer::link(new moodle_url('/local/rtocompliance/qualbuilder_results.php'),
        'Then go to Student Results to Sync',
        ['class' => 'btn btn-outline-primary', 'style' => 'margin-top:6px;']);
    echo '</form>';

    echo '<script>(function () {var a=document.getElementById("rtoc-rc-all");if(!a){return;}'
        . 'a.addEventListener("change",function () {document.querySelectorAll(".rtoc-rc-cb").forEach(function (c) {c.checked=a.checked;});});})();</script>';
} else {
    echo '<div class="alert alert-success">No mappable old codes found — every unmapped completion either already '
        . 'resolves or has no current unit to map to (see below).</div>';
}

// ── UNRESOLVED ────────────────────────────────────────────────────────────────
if (!empty($unresolved)) {
    $totalcompl = array_sum(array_map(function ($u) {
        return (int)$u['completions'];
    }, $unresolved));
    echo '<hr style="margin:26px 0 16px;">';
    echo '<h5 style="color:#b45309;">' . count($unresolved) . ' code(s) have no current unit to map to ('
        . $totalcompl . ' completions)</h5>';
    echo '<p style="color:#94a3b8;font-size:0.86em;max-width:760px;">These unit codes are not in any of your current '
        . 'Qual Builder products — they belong to a <strong>superseded qualification</strong> (e.g. an older Diploma of '
        . 'a qualification that included a unit of competency units). To credit these completions correctly, '
        . 'build that qualification with <a href="' . (new moodle_url('/local/rtocompliance/qualbuilder_autocreate.php'))->out(false)
        . '">Auto-Create</a> (its units then become "current"), then run this recovery again. They are never auto-mapped '
        . 'to an unrelated unit.</p>';
    echo '<table class="table table-sm" style="font-size:0.85em;max-width:760px;"><thead class="thead-light"><tr>'
        . '<th>Old code</th><th style="text-align:center;">Completions</th><th>Example archived course</th></tr></thead><tbody>';
    foreach ($unresolved as $old => $u) {
        echo '<tr><td><code>' . s($old) . '</code></td>'
            . '<td style="text-align:center;">' . (int)$u['completions'] . '</td>'
            . '<td style="color:#94a3b8;">' . s($u['examples'][0] ?? '') . '</td></tr>';
    }
    echo '</tbody></table>';
}

echo html_writer::end_div();
echo $OUTPUT->footer();
