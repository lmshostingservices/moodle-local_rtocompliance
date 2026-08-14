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
 * Compare the live database against db/install.xml and optionally repair it.
 *
 * install.xml is the definition a FRESH install builds from, so it is the
 * authoritative statement of what this plugin's schema should look like. This
 * script reports every table or column that install.xml defines but the live
 * database does not have, and with --fix will add them.
 *
 * Useful:
 *   - after upgrading from a pre-6.3.0 build (the savepoint renumbering means
 *     historical upgrade steps are now skipped rather than re-run every time)
 *   - when a page throws "Column not found" after a partial or aborted upgrade
 *   - as a pre-flight check before a release
 *
 * It is additive only. It creates missing tables and adds missing columns; it
 * never drops, renames, retypes or reorders anything, and it refuses to add a
 * NOT NULL column with no default to a table that already has rows (there is no
 * safe value to backfill — it reports those for a human to decide).
 *
 * USAGE
 *   php local/rtocompliance/cli/check_schema.php          Report only
 *   php local/rtocompliance/cli/check_schema.php --fix    Apply the additions
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/ddllib.php');
require_once($CFG->dirroot . '/local/rtocompliance/db/upgrade.php');

list($options, $unrecognised) = cli_get_params(
    ['fix' => false, 'help' => false],
    ['f' => 'fix', 'h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Compare the live database against local/rtocompliance/db/install.xml.\n\n"
       . "Options:\n"
       . "  -f, --fix    Create missing tables and add missing columns (additive only).\n"
       . "  -h, --help   Show this help.\n\n";
    exit(0);
}

$dbman = $DB->get_manager();

// ── Report pass: work out what is missing, without changing anything. ────────
$xmldbfile = new xmldb_file($CFG->dirroot . '/local/rtocompliance/db/install.xml');
if (!$xmldbfile->fileExists() || !$xmldbfile->loadXMLStructure()) {
    cli_error('Could not read db/install.xml');
}
$structure = $xmldbfile->getStructure();

$missingtables = [];
$missingfields = [];
foreach ($structure->getTables() as $table) {
    if (!$dbman->table_exists($table)) {
        $missingtables[] = $table->getName();
        continue;
    }
    foreach ($table->getFields() as $field) {
        if (!$dbman->field_exists($table, $field)) {
            $missingfields[] = $table->getName() . '.' . $field->getName();
        }
    }
}

cli_writeln('local_rtocompliance schema check');
cli_writeln('--------------------------------');
cli_writeln('install.xml tables : ' . count($structure->getTables()));
cli_writeln('missing tables     : ' . count($missingtables));
cli_writeln('missing columns    : ' . count($missingfields));
cli_writeln('');

if (!$missingtables && !$missingfields) {
    cli_writeln('The database matches install.xml. Nothing to do.');
    exit(0);
}

foreach ($missingtables as $t) {
    cli_writeln('  MISSING TABLE   ' . $t);
}
foreach ($missingfields as $f) {
    cli_writeln('  MISSING COLUMN  ' . $f);
}
cli_writeln('');

if (empty($options['fix'])) {
    cli_writeln('Report only — nothing changed. Re-run with --fix to add them.');
    exit(0);
}

// ── Repair pass: reuse the exact function the v6.3.0 upgrade step calls. ─────
cli_writeln('Applying...');
$sync = local_rtocompliance_upgrade_sync_schema($dbman);

foreach ($sync['tables'] as $t) {
    cli_writeln('  created table   ' . $t);
}
foreach ($sync['fields'] as $f) {
    cli_writeln('  added column    ' . $f);
}
foreach ($sync['skipped'] as $s) {
    cli_writeln('  SKIPPED         ' . $s);
}

purge_all_caches();
cli_writeln('');
cli_writeln('Done. ' . count($sync['tables']) . ' table(s) created, '
    . count($sync['fields']) . ' column(s) added, '
    . count($sync['skipped']) . ' skipped. Caches purged.');
exit(0);
