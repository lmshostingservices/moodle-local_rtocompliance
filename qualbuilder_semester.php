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
 * RTO Compliance plugin — qualbuilder_semester.php.
 *
 * Semester Intake Builder. Scans the Moodle category tree for per-semester intake categories
 * (each category that holds unit-code courses), and creates a SEPARATE Draft qualification
 * product for each — qualification code as chosen (auto-suggested from the unit set), streamname
 * = the semester label, its units and that semester's courses linked. Writes only plugin tables.
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

$PAGE->set_url('/local/rtocompliance/qualbuilder_semester.php');
$PAGE->set_title('Semester Intake Builder');
$PAGE->set_heading('Semester Intake Builder');
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');
$PAGE->navbar->add(get_string('qualbuilder', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/qualbuilder.php'));
$PAGE->navbar->add('Semester Intake Builder');

// ── CREATE (POST) ─────────────────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    \core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_HUGE);
    $picks = optional_param_array('pick', [], PARAM_INT);
    $codes = optional_param_array('qual', [], PARAM_ALPHANUMEXT);
    $intakes = [];
    foreach ($picks as $catid) {
        $catid = (int)$catid;
        $intakes[] = ['categoryid' => $catid, 'qualcode' => isset($codes[$catid]) ? $codes[$catid] : ''];
    }
    $res = local_rtocompliance_create_semester_intakes($intakes);
    $c = count($res['created']);
    $s = count($res['skipped']);
    $e = count($res['errors']);
    $msg = $c . ' semester product(s) created'
        . ($s ? ', ' . $s . ' skipped' : '')
        . ($e ? ', ' . $e . ' error(s)' : '')
        . '. Now link courses stay attached automatically; run Sync on Student Results to credit their completions.';
    redirect(new moodle_url('/local/rtocompliance/qualbuilder.php'),
        $msg, null, $e ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
}

// ── SCAN (GET) ────────────────────────────────────────────────────────────────
\core_php_time_limit::raise(300);
raise_memory_limit(MEMORY_HUGE);
$intakes = local_rtocompliance_scan_semester_intakes();

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Semester Intake Builder', null, null, 'qualbuilder');
echo local_rtocompliance_page_banner('Semester Intake Builder',
    'Create a separate qualification product for every semester intake.');

// Fill the available width so collapsing the sidebar actually widens the table (rather than
// leaving empty space to the right). The units column scrolls horizontally within each card.
echo html_writer::start_div('', ['style' => 'max-width:100%;width:100%;']);

echo '<div class="rtoc-dob-sync-bar" style="border-left-color:#6366f1;margin-bottom:18px;">'
    . '<strong style="font-size:16px;">How this works</strong><br>'
    . '<span style="font-size:15px;color:#475569;line-height:1.65;">'
    . 'Each category that holds unit-code courses is one <strong>semester intake</strong>. This creates a '
    . '<strong>separate Draft product per intake</strong> — the semester label becomes the product\'s stream, and the '
    . 'units and courses from that category are attached — the <strong>exact</strong> course list under it, nothing '
    . 'guessed. The <strong>Qualification code and title are read straight from the parent category</strong> (parent = '
    . 'qualification, this sub-category = the semester); where a semester has no coded parent, the code box is blank for '
    . 'you to set once. You can adjust units afterwards with the Recover/variant tools. Writes only to this plugin — no Moodle '
    . 'categories, courses, accounts or enrolments change. Tick the intakes to build.'
    . '</span></div>';

if (empty($intakes)) {
    echo '<div class="alert alert-info" style="font-size:15px;">No categories with unit-code courses were found.</div>';
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo '<form method="post" action="qualbuilder_semester.php">';
echo '<input type="hidden" name="action" value="create">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

// Group the detected sub-categories by their parent QUALIFICATION (Moodle Category),
// so the layout reads the way Moodle is organised: a qualification on the left, its
// versions (sub-categories) nested under it, and each version's units of competency
// (the Moodle courses) alongside.
$groups = [];
foreach ($intakes as $intk) {
    $code = strtoupper(trim((string) ($intk['suggestqual'] ?? '')));
    $name = trim((string) ($intk['suggestqualname'] ?? ''));
    if ($code !== '') {
        // Has a qualification code (from the tree or matched from units) — group by the code.
        $gkey = 'CODE:' . $code;
        if (!isset($groups[$gkey])) {
            $groups[$gkey] = ['code' => $code, 'name' => $name, 'label' => '',
                'parentid' => 0, 'nocode' => false, 'versions' => []];
        }
        if ($name !== '' && $groups[$gkey]['name'] === '') {
            $groups[$gkey]['name'] = $name;
        }
    } else {
        // No qualification code anywhere — group by the real Moodle PARENT category and label the
        // group with that parent's actual title (e.g. "Summer School"), not a generic "Unassigned".
        $parentid   = (int) ($intk['parentid'] ?? 0);
        $parentname = trim((string) ($intk['parentname'] ?? ''));
        $gkey  = 'PARENT:' . ($parentid > 0 ? $parentid : ('c' . $intk['categoryid']));
        $label = $parentname !== '' ? $parentname : (string) ($intk['semester'] ?? 'Category');
        if (!isset($groups[$gkey])) {
            $groups[$gkey] = ['code' => '', 'name' => '', 'label' => $label,
                'parentid' => $parentid, 'nocode' => true, 'versions' => []];
        }
    }
    $groups[$gkey]['versions'][] = $intk;
}
// Coded qualifications first (alphabetically by code), then code-less parent categories (by name).
uasort($groups, function ($a, $b) {
    $an = !empty($a['nocode']); $bn = !empty($b['nocode']);
    if ($an !== $bn) { return $an ? 1 : -1; }
    $ak = $an ? $a['label'] : $a['code'];
    $bk = $bn ? $b['label'] : $b['code'];
    return strcasecmp((string) $ak, (string) $bk);
});

echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:6px 0 6px;">';
echo '<h4 style="margin:0;">' . count($groups) . ' qualification(s), ' . count($intakes) . ' version(s) detected</h4>';
echo '<span style="font-size:14px;color:#64748b;">Tick the versions to build, confirm the qualification code, then create.</span>';
echo '</div>';

// Plain-language legend for the three Moodle concepts this page maps.
echo '<div style="display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px;color:#475569;background:#f8fafc;'
    . 'border:1px solid #e2e8f0;border-radius:8px;padding:9px 14px;margin-bottom:14px;">'
    . '<span><strong style="color:#0f172a;">Qualification</strong> = Moodle <em>parent category</em></span>'
    . '<span><strong style="color:#0f172a;">Version</strong> = Moodle <em>sub-category</em> (e.g. a semester/intake or archive)</span>'
    . '<span><strong style="color:#0f172a;">Units of Competency</strong> = the Moodle <em>courses</em> under that version</span>'
    . '</div>';

foreach ($groups as $g) {
    $isnone = !empty($g['nocode']);

    // The category to link the header to: the parent category shared by these versions (the
    // qualification category for coded groups; the plain parent — e.g. "Summer School" — for
    // code-less groups). Use the most common parent among the versions.
    $pcounts = [];
    foreach ($g['versions'] as $v) {
        $pid = (int) ($v['parentid'] ?? 0);
        if ($pid > 0) {
            $pcounts[$pid] = (isset($pcounts[$pid]) ? $pcounts[$pid] : 0) + 1;
        }
    }
    $headercatid = 0;
    if (!empty($pcounts)) {
        arsort($pcounts);
        $headercatid = (int) key($pcounts);
    }
    $linkopen = $linkclose = '';
    if ($headercatid > 0) {
        $hurl = new moodle_url('/course/management.php', ['categoryid' => $headercatid]);
        $linkopen  = '<a href="' . $hurl->out() . '" target="_blank" rel="noopener" '
            . 'style="color:inherit;text-decoration:underline;" title="Open this Moodle category">';
        $linkclose = '</a>';
    }

    if ($isnone) {
        // Show the real parent category title as the heading — no "Unassigned" song and dance.
        $eyebrow = 'Moodle Category';
        $qhead = '<strong>' . $linkopen . s($g['label']) . $linkclose . '</strong>'
            . ' <span style="font-weight:500;color:#b45309;font-size:12.5px;">— no qualification code detected; set one on each version below to build</span>';
    } else {
        $eyebrow = 'Qualification (Moodle Category)';
        $codehtml = '<strong>' . s($g['code']) . '</strong>'
            . ($g['name'] !== '' ? ' <span style="font-weight:500;color:#334155;">— ' . s($g['name']) . '</span>' : '');
        $qhead = $linkopen . $codehtml . $linkclose;
    }

    echo '<div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 2px rgba(15,23,42,.04);">';
    // Header band — the parent Moodle category (a qualification, or a plain category like a school).
    echo '<div style="background:#eef2ff;border-bottom:1px solid #e0e7ff;padding:11px 16px;display:flex;'
        . 'align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
        . '<div style="font-size:15px;color:#3730a3;" title="This is the Moodle parent category.">'
        . '<span style="font-size:11px;letter-spacing:.5px;text-transform:uppercase;color:#6366f1;font-weight:700;">' . $eyebrow . '</span><br>'
        . $qhead . '</div>'
        . '<span style="font-size:12.5px;color:#64748b;">' . count($g['versions']) . ' version(s)</span>'
        . '</div>';

    echo '<table class="table table-sm table-hover" style="font-size:14px;margin:0;">';
    echo '<thead class="thead-light"><tr>'
        . '<th style="width:34px;"><input type="checkbox" class="rtoc-si-grp"></th>'
        . '<th title="The Moodle sub-category — e.g. a semester intake or archived version of the qualification.">Version (Sub Category)</th>'
        . '<th style="width:170px;" title="The national qualification code this version belongs to. Prefilled from the parent category; adjust if needed.">Qualification code</th>'
        . '<th title="The Moodle courses under this version — each course delivers one unit of competency.">Units of Competency (Moodle Courses)</th>'
        . '</tr></thead><tbody>';

    foreach ($g['versions'] as $intk) {
        $catid   = (int) $intk['categoryid'];
        $suggest = s($intk['suggestqual']);
        // Each unit code links to its Moodle course, so the admin can open and verify it.
        $unitlinks = [];
        foreach (array_slice($intk['units'], 0, 14) as $u) {
            $cid = 0;
            if (!empty($u['courseids']) && is_array($u['courseids'])) {
                $cid = (int) reset($u['courseids']);
            }
            $code = s((string) $u['unitcode']);
            if ($cid > 0) {
                $courl = new moodle_url('/course/view.php', ['id' => $cid]);
                $unitlinks[] = '<a href="' . $courl->out() . '" target="_blank" rel="noopener" '
                    . 'style="color:#4338ca;text-decoration:underline;" title="Open the Moodle course for '
                    . $code . '">' . $code . '</a>';
            } else {
                $unitlinks[] = $code;
            }
        }
        $unitcodes = implode(', ', $unitlinks) . (count($intk['units']) > 14 ? ' …' : '');
        $ucount = (int) $intk['unitcount'];
        $ccount = (int) $intk['coursecount'];

        $catmgmturl = new moodle_url('/course/management.php', ['categoryid' => $catid]);
        $catpath    = (string) ($intk['categorypath'] ?? '');

        echo '<tr>';
        echo '<td><input type="checkbox" class="rtoc-si-cb" name="pick[]" value="' . $catid . '"></td>';
        echo '<td><a href="' . $catmgmturl->out() . '" target="_blank" rel="noopener" '
            . 'style="font-weight:700;text-decoration:underline;" '
            . 'title="Open this Moodle category to verify what it is">'
            . s($intk['semester']) . '</a>';
        if ($catpath !== '') {
            echo '<div style="font-size:11.5px;color:#64748b;margin-top:3px;" title="Where this sub-category sits in Moodle">'
                . '<span style="color:#94a3b8;">in:</span> ' . s($catpath) . '</div>';
        }
        echo '</td>';
        $src  = (string) ($intk['suggestsource'] ?? '');
        $conf = (int) ($intk['inferconfidence'] ?? 0);
        echo '<td><input type="text" name="qual[' . $catid . ']" value="' . $suggest . '" '
            . 'placeholder="e.g. ABC12345" style="text-transform:uppercase;width:150px;" class="form-control form-control-sm">';
        if ($src === 'units' && $suggest !== '') {
            echo '<div style="margin-top:4px;font-size:11px;line-height:1.3;color:#92400e;'
                . 'background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:3px 7px;display:inline-block;" '
                . 'title="No qualification code was found anywhere in this category branch. This code was matched from the units in this category against your own qualification definitions. Please confirm it before building.">'
                . '&#9888; matched from units' . ($conf ? ' &middot; ' . $conf . '%' : '') . ' — confirm</div>';
        }
        echo '</td>';
        echo '<td>'
            . '<div style="font-weight:600;color:#0f172a;">' . $ucount . ' unit' . ($ucount === 1 ? '' : 's')
            . ' <span style="font-weight:400;color:#64748b;">· ' . $ccount . ' course' . ($ccount === 1 ? '' : 's') . '</span></div>'
            . ($unitcodes !== '' ? '<div style="color:#64748b;font-size:12px;margin-top:2px;">' . $unitcodes . '</div>' : '')
            . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

echo '<button type="submit" class="btn btn-primary" style="margin-top:6px;">Create ticked semester products</button> ';
echo html_writer::link(new moodle_url('/local/rtocompliance/qualbuilder.php'), 'Cancel',
    ['class' => 'btn btn-outline-secondary', 'style' => 'margin-top:6px;']);
echo '</form>';

echo '<script>(function () {document.querySelectorAll(".rtoc-si-grp").forEach(function (g) {'
    . 'g.addEventListener("change", function () {var t = g.closest("table"); if (!t) {return;} '
    . 't.querySelectorAll(".rtoc-si-cb").forEach(function (c) {c.checked = g.checked;});});});})();</script>';

echo html_writer::end_div();
echo $OUTPUT->footer();
