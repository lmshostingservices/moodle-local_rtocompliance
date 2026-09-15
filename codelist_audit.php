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
 * RTO Compliance plugin — codelist_audit.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// ─────────────────────────────────────────────────────────────────────────────
// AVETMISS code-list integrity — codelist_audit.php
//
// Lists every student record holding a code the AVETMISS standard does not
// define for that field, and every student holding a code whose meaning changed
// when v6.3.33 replaced the country and language lists with SACC and ASCL.
//
// READ-ONLY. It writes nothing to any table. Repair is cli/repair_codes.php,
// which requires --execute and logs every change.
// ─────────────────────────────────────────────────────────────────────────────

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\local\codelist_audit;

admin_externalpage_setup('local_rtocompliance_codelist_audit');
require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/rtocompliance/codelist_audit.php'));
$PAGE->set_title(get_string('codelist_report', 'local_rtocompliance'));
$PAGE->set_heading(get_string('codelist_report', 'local_rtocompliance'));
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    get_string('codelist_report', 'local_rtocompliance'));
echo html_writer::div(get_string('codelist_report_desc', 'local_rtocompliance'), 'alert alert-info');

$invalid = codelist_audit::get_invalid_counts();

if (!$invalid) {
    echo html_writer::div(get_string('codelist_clean', 'local_rtocompliance'), 'alert alert-success');
} else {
    $affected = codelist_audit::count_affected_students();
    echo html_writer::div(
        get_string('codelist_affected', 'local_rtocompliance', $affected),
        'alert alert-danger'
    );

    $table = new html_table();
    $table->head = [
        get_string('codelist_field', 'local_rtocompliance'),
        get_string('codelist_code', 'local_rtocompliance'),
        get_string('codelist_count', 'local_rtocompliance'),
    ];
    $table->attributes['class'] = 'generaltable';
    foreach ($invalid as $row) {
        $table->data[] = [s($row->field), s($row->code), (int)$row->students];
    }
    echo html_writer::table($table);

    echo html_writer::tag('p', get_string('codelist_repair_hint', 'local_rtocompliance'));
}

// ── Enrolment gaps the plugin refuses to guess at (v6.3.35) ─────────────────
// Delivery mode and outcome identifier are required by the specification and have no
// safe default. Where a value is missing the generator leaves the field blank rather
// than inventing one, which makes the file invalid ON PURPOSE - a blank is detectably
// wrong and gets fixed before lodgement, a fabricated 'YNN' is undetectably wrong and
// gets lodged. This table is where that shows up.
$gaps = codelist_audit::get_enrolment_gaps();
if ($gaps) {
    echo $OUTPUT->heading(get_string('codelist_enrolgaps', 'local_rtocompliance'), 3);
    echo html_writer::div(get_string('codelist_enrolgaps_desc', 'local_rtocompliance'), 'alert alert-warning');

    $t3 = new html_table();
    $t3->head = [
        get_string('codelist_field', 'local_rtocompliance'),
        get_string('codelist_problem', 'local_rtocompliance'),
        get_string('codelist_enrolments', 'local_rtocompliance'),
    ];
    $t3->attributes['class'] = 'generaltable';
    foreach ($gaps as $row) {
        $t3->data[] = [s($row->field), s($row->problem), (int)$row->enrolments];
    }
    echo html_writer::table($t3);
}

// ── Codes whose MEANING changed at v6.3.33 ───────────────────────────────────
// These are the dangerous ones. A record holding, say, language 3402 is still
// VALID after the fix - so it does not appear in the table above - but it used to
// mean Polish and now means Russian. Nothing in the data marks which of the two
// the operator intended; only the audit log or the original NAT import can say.
// The list below is derived from the shipped NCVER files and the previous release's
// arrays, so it cannot drift out of date with either.
$reinterpreted = [];
$previous = [
    'countryofbirth' => __DIR__ . '/db/codelists/superseded/country_pre_6_3_33.txt',
    'languageathome' => __DIR__ . '/db/codelists/superseded/language_pre_6_3_33.txt',
];
$getters = ['countryofbirth' => 'get_country_codes', 'languageathome' => 'get_language_codes'];

foreach ($previous as $field => $path) {
    if (!is_readable($path)) {
        continue;
    }
    $old = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode("\t", $line, 2);
        if (count($parts) === 2) {
            $old[$parts[0]] = $parts[1];
        }
    }
    if (!$old) {
        continue;
    }
    $new = avetmiss_codes::{$getters[$field]}();

    $changed = [];
    foreach ($old as $code => $oldlabel) {
        $code = (string)$code;
        if (array_key_exists($code, $new) && (string)$new[$code] !== (string)$oldlabel) {
            $changed[$code] = [$oldlabel, $new[$code]];
        }
    }
    if (!$changed) {
        continue;
    }

    [$insql, $params] = $DB->get_in_or_equal(array_keys($changed), SQL_PARAMS_NAMED, 'c');
    $counts = $DB->get_records_sql(
        "SELECT $field AS code, COUNT(*) AS students
           FROM {local_rtocompliance_students}
          WHERE $field $insql
          GROUP BY $field",
        $params
    );
    foreach ($counts as $row) {
        $code = (string)$row->code;
        $reinterpreted[] = (object)[
            'field'    => $field,
            'code'     => $code,
            'was'      => $changed[$code][0],
            'now'      => $changed[$code][1],
            'students' => (int)$row->students,
        ];
    }
}

if ($reinterpreted) {
    usort($reinterpreted, fn($a, $b) => $b->students <=> $a->students);

    echo $OUTPUT->heading(get_string('codelist_reinterpreted', 'local_rtocompliance'), 3);
    echo html_writer::div(get_string('codelist_reinterpreted_desc', 'local_rtocompliance'), 'alert alert-warning');

    $t2 = new html_table();
    $t2->head = [
        get_string('codelist_field', 'local_rtocompliance'),
        get_string('codelist_code', 'local_rtocompliance'),
        get_string('codelist_was', 'local_rtocompliance'),
        get_string('codelist_now', 'local_rtocompliance'),
        get_string('codelist_count', 'local_rtocompliance'),
    ];
    $t2->attributes['class'] = 'generaltable';
    foreach ($reinterpreted as $row) {
        $t2->data[] = [s($row->field), s($row->code), s($row->was), s($row->now), $row->students];
    }
    echo html_writer::table($t2);
}

echo $OUTPUT->footer();
