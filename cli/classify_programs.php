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
 * Classify every qualification code against the National Register.
 *
 * Safe to re-run. Only looks at codes with no established state unless --all is
 * given, and never overrides a decision a person has recorded.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_rtocompliance\local\recognition;
use local_rtocompliance\local\register_lookup;

[$options, $unrecognised] = cli_get_params([
    'help'    => false,
    'dry-run' => false,
    'all'     => false,
    'limit'   => 0,
], ['h' => 'help', 'n' => 'dry-run']);

if ($options['help']) {
    cli_writeln("
Classify qualification codes as nationally recognised training.

Asks the National Register about every code this site uses. A code the register
holds is marked RECOGNISED automatically. A code it does not hold is LEFT
UNCLASSIFIED for a person to decide - not marked unaccredited, because failing to
confirm accreditation is not evidence against it.

Nothing is ever excluded from a NAT file by this - the export reports all activity
regardless. Classification drives the USI count and the certificate type, and an
unclassified code raises a warning in AVETMISS Validation so it is seen before
lodgement. Unclassified codes are listed in Reports > Program recognition.

Options:
  -h, --help     Show this help.
  -n, --dry-run  Look everything up and report, write nothing.
      --all      Re-check codes that already have a state. A person's decision is
                 still never overwritten.
      --limit=N  Stop after N codes.

Example:
  php cli/classify_programs.php --dry-run
  php cli/classify_programs.php
");
    exit(0);
}

$dryrun = !empty($options['dry-run']);
$limit  = (int)$options['limit'];

cli_heading('Program recognition — National Register classification');

// Step 1. Make sure nothing is invisible before we start.
$created = register_lookup::discover_codes();
cli_writeln("Discovered codes: $created new qualification code(s) added for classification.");
cli_writeln(recognition::summarise());
cli_writeln('');

if ($dryrun) {
    cli_writeln('DRY RUN — looking up, writing nothing.');
    cli_writeln('');
}

$rows = $DB->get_records_sql(
    'SELECT id, qualificationcode, state FROM {local_rtocompliance_recognition} '
    . (!empty($options['all']) ? '' : 'WHERE state = :unknown ')
    . 'ORDER BY qualificationcode',
    !empty($options['all']) ? [] : ['unknown' => recognition::STATE_UNKNOWN],
    0, $limit > 0 ? $limit : 0);

if (!$rows) {
    cli_writeln('Nothing to classify. Every code already has a state.');
    exit(0);
}

cli_writeln(sprintf('%-22s %-11s %-14s %s', 'CODE', 'REGISTER', 'STATE', 'DETAIL'));
$counts = ['found' => 0, 'notfound' => 0, 'error' => 0, 'recognised' => 0, 'total' => 0];

foreach ($rows as $row) {
    $code = $row->qualificationcode;
    if ($dryrun) {
        $r = register_lookup::lookup($code);
        $state = $r['result'] === register_lookup::RESULT_FOUND
            ? recognition::STATE_RECOGNISED . ' (would set)'
            : $row->state . ' (unchanged)';
        $result = $r['result'];
        $detail = $r['detail'];
    } else {
        $res = register_lookup::classify($code);
        $state  = $res['state'];
        $result = $res['result'];
        $detail = $res['detail'];
        if ($res['state'] === recognition::STATE_RECOGNISED) {
            $counts['recognised']++;
        }
    }
    $counts['total']++;
    if (isset($counts[$result])) {
        $counts[$result]++;
    }
    cli_writeln(sprintf('%-22s %-11s %-14s %s', $code, $result, $state, $detail));
}

cli_writeln('');
cli_writeln(sprintf(
    'Looked up %d code(s): %d on the register, %d not on it, %d could not be checked.',
    $counts['total'], $counts['found'], $counts['notfound'], $counts['error']));

if ($counts['error'] > 0) {
    cli_writeln('');
    cli_writeln('NOTE: codes that could not be checked are LEFT UNCLASSIFIED, not marked '
        . 'unaccredited. Fix the cause (usually a missing Platform API key or no network '
        . 'from this server) and re-run — it is safe to re-run.');
}
if ($counts['notfound'] > 0) {
    cli_writeln('');
    cli_writeln('NOTE: codes the register does not hold are LEFT UNCLASSIFIED for a person '
        . 'to decide. These are usually the RTO\'s own non-accredited short courses. '
        . 'Classify them in Reports > Program recognition — until then they are excluded '
        . 'from NAT files and from USI collection.');
}

cli_writeln('');
cli_writeln(recognition::summarise());
exit(0);
