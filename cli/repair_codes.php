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
 * RTO Compliance plugin — repair AVETMISS coded fields corrupted before v6.3.33.
 *
 * DRY RUN BY DEFAULT. Nothing is written unless --execute is given.
 *
 * WHAT IT WILL AND WILL NOT DO
 *
 * It repairs only the cases where the student's intended value is KNOWN, not guessed:
 *
 *   --mode=notstated   A country or language of '9999'. The list shipped up to v6.3.32
 *                      labelled 9999 'Not stated', so an operator who chose "not
 *                      stated" stored 9999 - but 9999 is not an identifier in SACC or
 *                      ASCL, so the record cannot be reported. AVETMISS's own
 *                      not-specified value is '@@@@'. The operator's intent is
 *                      unambiguous and is preserved exactly.
 *
 *   --mode=auditlog    A coded field whose CURRENT value is outside the standard, where
 *                      the plugin audit log records the update that put it there and its
 *                      olddata holds a value that IS in the standard. That restores what
 *                      the record held before a silent save overwrote it.
 *
 * It will NOT guess a country from a wrong code. 6103 stored under a label that read
 * 'India' could have been meant as India or genuinely as Macau, and a script that
 * picked one would destroy the evidence needed to get it right. Those records are
 * listed by codelist_audit.php and need a human and an external source - the audit
 * log's olddata, or the NAT00080 file the record was imported from.
 *
 * Reconciliation against a NAT00080 import file is deliberately NOT implemented here.
 * It is the highest-value recovery path on a site that still has its import files, but
 * it depends on fixed-width offsets that differ between AVETMISS releases 7.0 and 8.0,
 * and a wrong offset would write a wrong country into thousands of records while
 * reporting success. It needs to be built against the specific files, with the parse
 * verified before any write.
 *
 * Every change is written to the plugin audit log with the old and new value, so a
 * repair can itself be audited and reversed.
 *
 * Usage:
 *   php cli/repair_codes.php                                    # dry run, all modes
 *   php cli/repair_codes.php --mode=notstated                   # dry run, one mode
 *   php cli/repair_codes.php --mode=notstated --execute          # write
 *   php cli/repair_codes.php --mode=auditlog --limit=50          # dry run, first 50
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_rtocompliance\avetmiss_codes;
use local_rtocompliance\audit_logger;
use local_rtocompliance\local\codelist_audit;

[$options, $unrecognised] = cli_get_params([
    'help'    => false,
    'mode'    => 'all',
    'execute' => false,
    'limit'   => 0,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode("\n  ", $unrecognised)));
}

if ($options['help'] || !in_array($options['mode'], ['all', 'notstated', 'auditlog'], true)) {
    cli_writeln("Repair AVETMISS coded fields corrupted before v6.3.33.\n");
    cli_writeln("  --mode=notstated  map country/language 9999 to AVETMISS '@@@@'");
    cli_writeln("  --mode=auditlog   restore an out-of-standard value from audit-log olddata");
    cli_writeln("  --mode=all        both (default)");
    cli_writeln("  --execute         actually write; omit for a dry run");
    cli_writeln("  --limit=N         stop after N records per mode");
    cli_writeln("\nDry run by default. Every change is written to the plugin audit log.");
    exit(0);
}

$dryrun = !$options['execute'];
$limit  = (int)$options['limit'];
$fields = codelist_audit::get_coded_fields();

cli_heading('AVETMISS code repair — ' . ($dryrun ? 'DRY RUN (nothing will be written)' : 'EXECUTING'));
cli_writeln(codelist_audit::summarise());
cli_writeln('');

/**
 * Apply one field change to one student, or report it in a dry run.
 *
 * @param stdClass $student The student record (needs ->id and the field).
 * @param string $field Column name.
 * @param string $new New value.
 * @param string $why One line for the audit log and the console.
 * @param bool $dryrun
 * @return void
 */
function local_rtocompliance_repair_apply($student, string $field, string $new, string $why, bool $dryrun): void {
    global $DB;

    $old = (string)$student->$field;
    cli_writeln(sprintf('  student %-7d %-20s %-6s -> %-6s  (%s)', $student->id, $field, $old, $new, $why));

    if ($dryrun) {
        return;
    }

    $update = (object)['id' => $student->id, $field => $new, 'timemodified' => time()];
    $DB->update_record('local_rtocompliance_students', $update);

    audit_logger::log(
        audit_logger::ACTION_UPDATE,
        audit_logger::ENTITY_STUDENT,
        (int)$student->id,
        null,
        "v6.3.33 code repair ($field): $why",
        [$field => $old],
        [$field => $new]
    );
}

$totalchanged = 0;

// ── MODE: notstated ──────────────────────────────────────────────────────────
if (in_array($options['mode'], ['all', 'notstated'], true)) {
    cli_heading('Mode: notstated — 9999 to @@@@', 2);

    $count = 0;
    foreach (['countryofbirth', 'languageathome'] as $field) {
        // 9999 is the only value handled here. Any other out-of-standard code has no
        // knowable intent and is left for a human.
        $rows = $DB->get_records_select(
            'local_rtocompliance_students',
            "$field = :val",
            ['val' => '9999'],
            'id ASC',
            "id, $field"
        );
        foreach ($rows as $row) {
            if ($limit && $count >= $limit) {
                break 2;
            }
            local_rtocompliance_repair_apply(
                $row, $field, '@@@@',
                "9999 was labelled 'Not stated' before v6.3.33 but is not a SACC/ASCL identifier",
                $dryrun
            );
            $count++;
        }
    }
    cli_writeln("  $count record(s)" . ($dryrun ? ' would be changed.' : ' changed.'));
    cli_writeln('');
    $totalchanged += $count;
}

// ── MODE: auditlog ───────────────────────────────────────────────────────────
if (in_array($options['mode'], ['all', 'auditlog'], true)) {
    cli_heading('Mode: auditlog — restore from olddata', 2);

    $valid = [];
    foreach ($fields as $field => $getter) {
        $valid[$field] = avetmiss_codes::$getter();
    }

    // Only student-update rows that actually carry an olddata payload. Ordered
    // oldest first so that when a record was overwritten more than once, the value
    // restored is the one from the EARLIEST corrupting save - i.e. the last value a
    // human is known to have chosen deliberately, not an intermediate bad one.
    $count = 0;
    $rs = $DB->get_recordset_select(
        'local_rtocompliance_audit',
        "entitytype = :et AND action = :ac AND olddata IS NOT NULL AND entityid > 0",
        ['et' => audit_logger::ENTITY_STUDENT, 'ac' => audit_logger::ACTION_UPDATE],
        'timecreated ASC',
        'id, entityid, olddata, newdata, timecreated'
    );

    $done = [];
    foreach ($rs as $logrow) {
        if ($limit && $count >= $limit) {
            break;
        }
        $olddata = json_decode((string)$logrow->olddata, true);
        if (!is_array($olddata)) {
            continue;
        }

        $student = $DB->get_record('local_rtocompliance_students', ['id' => (int)$logrow->entityid]);
        if (!$student) {
            continue;
        }

        foreach ($fields as $field => $unused) {
            $key = $logrow->entityid . ':' . $field;
            if (isset($done[$key])) {
                continue;
            }
            if (!array_key_exists($field, $olddata)) {
                continue;
            }

            $current = (string)$student->$field;
            $was     = (string)$olddata[$field];

            // Repair only where the CURRENT value is broken and the OLD value is good.
            // If the current value is valid it is left alone even when it differs from
            // olddata: it may be a deliberate later correction, and this script must
            // never overwrite a considered edit.
            if ($current === '' || array_key_exists($current, $valid[$field])) {
                continue;
            }
            if ($was === '' || !array_key_exists($was, $valid[$field])) {
                continue;
            }

            local_rtocompliance_repair_apply(
                $student, $field, $was,
                'restored from audit log #' . $logrow->id . ' of ' . userdate($logrow->timecreated, '%Y-%m-%d'),
                $dryrun
            );
            $done[$key] = true;
            $count++;
        }
    }
    $rs->close();

    cli_writeln("  $count record(s)" . ($dryrun ? ' would be changed.' : ' changed.'));
    cli_writeln('');
    $totalchanged += $count;
}

cli_heading('Result', 2);
if ($dryrun) {
    cli_writeln("Dry run complete. $totalchanged change(s) identified. Nothing was written.");
    cli_writeln('Re-run with --execute to apply them.');
} else {
    cli_writeln("$totalchanged change(s) applied and logged.");
    cli_writeln('');
    cli_writeln(codelist_audit::summarise());
    cli_writeln('Records still listed above need a human and an external source; see');
    cli_writeln('Reports > AVETMISS code-list integrity.');
}
exit(0);
