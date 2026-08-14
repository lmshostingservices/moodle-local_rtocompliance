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
 * RTO Compliance plugin — nominalhours_import.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// NOMINAL-HOURS (v5.9.418) — import authoritative nominal hours into the reference table.
//
// Accepts the NCVER "nationally agreed nominal hours" tab-delimited file (or any
// CSV/TSV with a unit code column and an hours column), and upserts each row into
// local_rtocompliance_nominalhours. Import as state='NAT' (the national baseline) or a
// specific state override. Read the file as text (NCVER warns leading zeros matter).

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_rtocompliance_nominalhours_import');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:manage', $context);

$PAGE->set_url(new moodle_url('/local/rtocompliance/nominalhours_import.php'));
$PAGE->set_title('Import Nominal Hours');
$PAGE->set_heading('Import Nominal Hours');

$statelist = ['NAT' => 'NAT — NCVER nationally agreed', 'VIC' => 'VIC', 'QLD' => 'QLD', 'NSW' => 'NSW',
    'SA' => 'SA', 'WA' => 'WA', 'TAS' => 'TAS', 'NT' => 'NT', 'ACT' => 'ACT'];

$done = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && confirm_sesskey()) {
    $state = strtoupper(optional_param('state', 'NAT', PARAM_ALPHA));
    if (!isset($statelist[$state])) { $state = 'NAT'; }
    $sourceref = trim(optional_param('sourceref', '', PARAM_TEXT));

    if (!empty($_FILES['datafile']['tmp_name']) && is_uploaded_file($_FILES['datafile']['tmp_name'])) {
        $raw = file_get_contents($_FILES['datafile']['tmp_name']);
        // Normalise line endings; the NCVER file is tab-delimited text.
        $raw = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $lines = explode("\n", $raw);
        $created = 0; $updated = 0; $skipped = 0; $rownum = 0;
        // Detect delimiter from the first non-empty line.
        $delim = "\t";
        foreach ($lines as $l) {
            if (trim($l) === '') { continue; }
            if (substr_count($l, "\t") === 0 && substr_count($l, ',') > 0) { $delim = ','; }
            break;
        }
        foreach ($lines as $line) {
            $rownum++;
            if (trim($line) === '') { continue; }
            $cols = str_getcsv($line, $delim);
            if (count($cols) < 2) { $skipped++; continue; }
            // Find a unit-code-looking column and an hours column.
            $code = strtoupper(preg_replace('/\s+/', '', (string) $cols[0]));
            // A unit code is letters+digits; header rows / non-codes are skipped.
            if (!preg_match('/^[A-Z]{2,10}[0-9]{2,7}[A-Z]?$/', $code)) { $skipped++; continue; }
            // Hours = first purely-numeric column after the code.
            $hours = null;
            for ($i = 1; $i < count($cols); $i++) {
                $v = trim((string) $cols[$i]);
                if ($v !== '' && ctype_digit($v)) { $hours = (int) $v; break; }
            }
            if ($hours === null) { $skipped++; continue; }
            $res = local_rtocompliance_upsert_nominalhours($code, $hours, $state,
                $sourceref !== '' ? $sourceref : ('Import ' . userdate(time(), '%Y-%m-%d')));
            if ($res === 'created') { $created++; } else if ($res === 'updated') { $updated++; } else { $skipped++; }
        }
        $done = "Import complete — $created created, $updated updated, $skipped skipped.";
        local_rtocompliance_log_action('import', 'nominalhours', 0,
            ['state' => $state, 'created' => $created, 'updated' => $updated]);
    } else {
        $done = 'No file was uploaded.';
    }
}

$PAGE->add_body_class('path-local-rtocompliance'); // v5.9.445: scoped CSS needs this on admin_externalpage pages.
echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header('Import Nominal Hours', null, null, 'qualbuilder');
echo local_rtocompliance_page_banner('Import Nominal Hours');

$total = $DB->count_records('local_rtocompliance_nominalhours');
echo html_writer::div(
    'The reference table currently holds <strong>' . (int) $total . '</strong> nominal-hours values. '
    . 'Nominal hours are set per state/territory; the free <strong>NCVER "nationally agreed nominal hours"</strong> '
    . 'tab-delimited file is the national baseline (import it as NAT). Load state files (VIC VPG / WA guide, etc.) as '
    . 'state overrides. These values seed the Qualification Builder unit hours and the AVETMISS scheduled hours.',
    'alert alert-info');

if ($done !== null) {
    echo html_writer::div($done, 'alert alert-success');
}

echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data', 'style' => 'max-width:640px;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group', ['style' => 'margin-bottom:14px;']);
echo html_writer::tag('label', 'Jurisdiction (state) for this import', ['class' => 'form-label', 'style' => 'font-weight:600;']);
echo html_writer::select($statelist, 'state', 'NAT', false, ['class' => 'form-control']);
echo html_writer::tag('small', 'NAT = the NCVER nationally-agreed set (the national baseline). Choose a state to load an override.', ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-bottom:14px;']);
echo html_writer::tag('label', 'Source reference (provenance)', ['class' => 'form-label', 'style' => 'font-weight:600;']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'sourceref', 'class' => 'form-control',
    'placeholder' => 'e.g. NCVER Nationally-agreed 2026Q2']);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-bottom:14px;']);
echo html_writer::tag('label', 'Data file (tab-delimited .txt or .csv: unit code, hours)', ['class' => 'form-label', 'style' => 'font-weight:600;']);
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'datafile', 'class' => 'form-control',
    'accept' => '.txt,.tsv,.csv']);
echo html_writer::tag('small', 'The importer detects tab or comma delimiters, skips header/non-code rows, and upserts one value per unit per jurisdiction. Read leading zeros as text — do not round-trip through Excel.', ['class' => 'form-text text-muted']);
echo html_writer::end_div();

echo html_writer::tag('button', 'Import', ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
