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
 * One-off: normalise a stored plugin version left behind by the old numbering.
 *
 * WHY THIS EXISTS
 * ---------------
 * Moodle plugin versions are YYYYMMDDXX — 8 date digits plus a 2-digit counter,
 * 10 digits total. Up to v6.2.91 this plugin's db/upgrade.php used 13-digit
 * savepoints (YYYYMMDD + a 5-digit build counter, e.g. 2026080500663) while
 * version.php declared a correct 10-digit version (e.g. 2026080600).
 *
 * Because 2026080500663 is numerically ~200x larger than 2026080600, any site
 * that ran those upgrade steps ended up with a stored version HIGHER than the one
 * version.php declares. Moodle then refuses to upgrade that plugin ever again:
 *
 *     Downgrade of local_rtocompliance is not supported
 *
 * v6.3.0 renumbers every savepoint to a strictly ascending 10-digit sequence
 * ending at 2026081400, which matches $plugin->version. Nothing in the plugin can
 * repair the stored value on its own, because Moodle compares the versions BEFORE
 * it runs any of the plugin's own code — hence this script.
 *
 * WHAT IT DOES
 * ------------
 * Reads the stored version. If it is 11 digits or more (a legacy 13-digit value),
 * it rewrites it to 2026081300 — one step below the v6.3.0 version — so the normal
 * Moodle upgrade runs and takes it to 2026081400. If the stored value is already
 * 10 digits, it changes nothing and says so.
 *
 * No student, enrolment, certificate or configuration data is touched: this script
 * writes exactly one row of mdl_config_plugins.
 *
 * USAGE
 *   php local/rtocompliance/cli/normalise_version.php            (dry run — shows what it would do)
 *   php local/rtocompliance/cli/normalise_version.php --execute  (applies the change)
 *
 * Then visit Site administration → Notifications, or run:
 *   php admin/cli/upgrade.php
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// The version v6.3.0 declares in version.php.
const RTOC_TARGET_VERSION = 2026081400;
// What a stranded site is reset to: one below the target, so the upgrade runs.
const RTOC_RESET_TO       = 2026081300;

list($options, $unrecognised) = cli_get_params(
    ['execute' => false, 'help' => false],
    ['e' => 'execute', 'h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help']) {
    echo "Normalise the stored local_rtocompliance plugin version.\n\n"
       . "Options:\n"
       . "  -e, --execute   Apply the change (without this it is a dry run).\n"
       . "  -h, --help      Show this help.\n\n";
    exit(0);
}

$component = 'local_rtocompliance';
$stored = $DB->get_field('config_plugins', 'value', ['plugin' => $component, 'name' => 'version']);

if ($stored === false) {
    cli_writeln("No stored version found for {$component} — the plugin is not installed on this site.");
    cli_writeln("Nothing to do: a fresh install will record " . RTOC_TARGET_VERSION . " correctly.");
    exit(0);
}

$storeddigits = strlen((string)(int)$stored);
cli_writeln("Stored version : {$stored}  ({$storeddigits} digits)");
cli_writeln("Target version : " . RTOC_TARGET_VERSION . "  (10 digits, correct Moodle YYYYMMDDXX format)");
cli_writeln('');

if ($storeddigits <= 10) {
    if ((int)$stored >= RTOC_TARGET_VERSION) {
        cli_writeln("Already at or above the target version — nothing to do.");
    } else {
        cli_writeln("Stored version is already in correct 10-digit format and is lower than the");
        cli_writeln("target, so Moodle will upgrade this plugin normally. Nothing to do.");
        cli_writeln("Run: php admin/cli/upgrade.php");
    }
    exit(0);
}

cli_writeln("This site is stranded: the stored version is a legacy 13-digit value, which is");
cli_writeln("numerically higher than every valid 10-digit version, so Moodle will refuse to");
cli_writeln("upgrade the plugin (\"Downgrade of {$component} is not supported\").");
cli_writeln('');

if (empty($options['execute'])) {
    cli_writeln("DRY RUN — nothing changed.");
    cli_writeln("Would set config_plugins.value to " . RTOC_RESET_TO . " for plugin={$component}, name=version.");
    cli_writeln("Re-run with --execute to apply, then run: php admin/cli/upgrade.php");
    exit(0);
}

$DB->set_field('config_plugins', 'value', RTOC_RESET_TO, ['plugin' => $component, 'name' => 'version']);
purge_all_caches();

cli_writeln("Done. Stored version reset to " . RTOC_RESET_TO . " and caches purged.");
cli_writeln("Now run:  php admin/cli/upgrade.php");
cli_writeln("(or visit Site administration → Notifications)");
exit(0);
