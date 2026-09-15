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
 * READ-ONLY probe: what kinds of code does this site actually have, and what does the
 * platform API say about each kind?
 *
 * WRITES NOTHING. It does not touch the recognition table and does not classify
 * anything. Run it before deciding whether the National Register lookup needs extra
 * endpoints beyond /api/tga/qualification/.
 *
 * WHY IT EXISTS: qualification codes, SKILL SET codes (AHCSS00023) and state
 * ACCREDITED COURSE codes (22237VIC) all pass the same code pattern and all end up in
 * programcode, but on training.gov.au they are three different kinds of thing. If the
 * platform API only answers for qualifications, the other two come back "not on the
 * register" - which is wrong, because they ARE nationally recognised training. This
 * script says which of those three shapes the site has, and what each endpoint
 * actually returns for a real example of each.
 *
 * @package    local_rtocompliance
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

[$options] = cli_get_params(['help' => false, 'live' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("
READ-ONLY probe of this site's program codes and the platform API.

Writes nothing. Classifies nothing.

Part 1 groups every code the site holds by SHAPE - qualification, skill set,
accredited course, or something else - so we know what actually has to be looked up.

Part 2 (only with --live) asks the platform API about ONE real example of each shape
and prints the raw HTTP status and the first part of the response, so we know which
shapes the current endpoint can and cannot answer for.

Options:
  -h, --help  Show this help.
      --live  Also make the API calls. Without it, part 1 only - no network at all.

Example:
  php cli/probe_register.php
  php cli/probe_register.php --live
");
    exit(0);
}

/**
 * Classify a code by its shape - LAST RESORT ONLY.
 *
 * Qual Builder already records what each product is ('qualification', 'skillset',
 * 'singleunit') and the course map already ties each qual code to its Moodle courses.
 * That is what this script reads. This function is used ONLY for codes the site holds
 * no product record for, and its answer is labelled as a guess, because these are
 * conventions rather than a standard: a shape is a hint about which endpoint to try,
 * never evidence about whether the thing is accredited.
 *
 * @param string $code
 * @return string
 */
function local_rtocompliance_probe_shape(string $code): string {
    $c = strtoupper(trim($code));
    if ($c === '') {
        return 'blank';
    }
    // Accredited course: digits then a state or national suffix, e.g. 22237VIC,
    // 91234NSW, 10904NAT. NAT is not a state but it is the same kind of thing - a
    // nationally accredited course rather than a training package qualification.
    if (preg_match('/^\d{4,6}(VIC|NSW|QLD|SA|WA|TAS|NT|ACT|NAT)$/', $c)) {
        return 'accredited course';
    }
    // Skill set: training package prefix, then SS, then digits. e.g. AHCSS00023.
    if (preg_match('/^[A-Z]{3}SS\d{3,6}$/', $c)) {
        return 'skill set';
    }
    // Qualification: 3 letters + 5-6 digits, e.g. AHC20416, BSB50120.
    if (preg_match('/^[A-Z]{3}\d{5,6}$/', $c)) {
        return 'qualification';
    }
    // Unit of competency: longer alpha prefix then digits, e.g. AHCWRK101, HLTAID011.
    if (preg_match('/^[A-Z]{5,12}\d{3,4}[A-Z]?$/', $c)) {
        return 'unit of competency';
    }
    return 'other / unrecognised shape';
}

cli_heading('Part 1 - what kind of product is each code, per the plugin\'s own map?');

// Read the plugin's own records rather than parsing code formats: Qual Builder holds
// producttype, and the course map holds the qualification-to-course tree.
$codes = $DB->get_fieldset_sql(
    'SELECT qualificationcode FROM {local_rtocompliance_recognition} ORDER BY qualificationcode');

if (!$codes) {
    cli_writeln('The recognition table is empty. Open Program Recognition once, or run');
    cli_writeln('cli/classify_programs.php --dry-run, so the codes get discovered first.');
    exit(0);
}

$bytype = [];
$examples = [];
foreach ($codes as $code) {
    $type = \local_rtocompliance\local\register_lookup::get_product_type($code);
    if ($type === null) {
        // No product record at all. Say so plainly, and note the guess separately so
        // it is never mistaken for something the site actually recorded.
        $type = 'NOT IN QUAL BUILDER (guess: '
            . local_rtocompliance_probe_shape($code) . ')';
    }
    $bytype[$type][] = $code;
}
ksort($bytype);

cli_writeln(sprintf('%d code(s) in total.', count($codes)));
cli_writeln('');
foreach ($bytype as $type => $list) {
    cli_writeln(sprintf('%-48s %4d   e.g. %s', $type, count($list),
        implode(', ', array_slice($list, 0, 5))));
    $examples[$type] = $list[0];
}
cli_writeln('');
cli_writeln('The lookup only asks /api/tga/qualification/<code> today, so anything that');
cli_writeln('is a skill set or a single unit may come back "not on the register" even');
cli_writeln('though it IS nationally recognised training.');

// How much of the site is actually mapped - the tree map is the thing to trust, so
// report how complete it is.
cli_writeln('');
cli_heading('The qualification / course tree map');
try {
    $mapped = (int)$DB->count_records('local_rtocompliance_course_map');
    $mappedquals = (int)$DB->get_field_sql(
        'SELECT COUNT(DISTINCT qualcode) FROM {local_rtocompliance_course_map}');
    $confirmed = (int)$DB->count_records('local_rtocompliance_course_map', ['confirmed' => 1]);
    $bysource = $DB->get_records_sql(
        'SELECT source, COUNT(*) AS n FROM {local_rtocompliance_course_map}
       GROUP BY source ORDER BY source');
    cli_writeln(sprintf('course_map: %d course(s) mapped across %d qualification(s); '
        . '%d confirmed by an admin.', $mapped, $mappedquals, $confirmed));
    foreach ($bysource as $row) {
        cli_writeln(sprintf('   source=%-8s %d', $row->source, $row->n));
    }
    if ($mapped === 0) {
        cli_writeln('EMPTY. Nothing has been seeded from the Moodle Course Map page, so the');
        cli_writeln('tree map cannot be the source of truth on this site yet.');
    }
} catch (\Throwable $e) {
    cli_writeln('course_map is not present on this site: ' . $e->getMessage());
}

// Qual codes the tree map knows about that never made it into recognition, which is
// the gap that discover_codes() had before v6.4.3.
try {
    $missing = $DB->get_fieldset_sql(
        "SELECT DISTINCT cm.qualcode
           FROM {local_rtocompliance_course_map} cm
      LEFT JOIN {local_rtocompliance_recognition} r
             ON r.qualificationcode = UPPER(TRIM(cm.qualcode))
          WHERE cm.qualcode IS NOT NULL AND cm.qualcode <> '' AND r.id IS NULL
       ORDER BY cm.qualcode");
    cli_writeln('');
    if ($missing) {
        cli_writeln(sprintf('IN THE TREE MAP BUT NOT IN PROGRAM RECOGNITION: %d code(s) - %s',
            count($missing), implode(', ', array_slice($missing, 0, 10))));
        cli_writeln('Run the register check once on 6.4.3 or later and these get picked up.');
    } else {
        cli_writeln('Every qualification in the tree map has a recognition row. Good.');
    }
} catch (\Throwable $e) {
    // Table absent; already reported above.
}

// Also report codes that carry students but never reached the table, and blank ones,
// because a blank programcode is invisible to Program Recognition entirely.
$blank = $DB->count_records_select('local_rtocompliance_enrolments',
    "programcode IS NULL OR TRIM(programcode) = ''");
$blankstudents = (int)$DB->get_field_sql(
    "SELECT COUNT(DISTINCT studentid) FROM {local_rtocompliance_enrolments}
      WHERE programcode IS NULL OR TRIM(programcode) = ''");
cli_writeln('');
cli_writeln(sprintf('Enrolments with NO program code: %d, covering %d student(s).',
    $blank, $blankstudents));
if ($blank > 0) {
    cli_writeln('Those are invisible to Program Recognition - there is no code to classify,');
    cli_writeln('so they can be neither recognised nor excluded. Worth understanding before');
    cli_writeln('any NAT export.');
}

if (empty($options['live'])) {
    cli_writeln('');
    cli_writeln('Part 2 skipped. Re-run with --live to ask the platform API about one');
    cli_writeln('example of each shape.');
    exit(0);
}

// -------------------------------------------------------------------- part 2 ----
cli_writeln('');
cli_heading('Part 2 - what does the platform API answer for each shape?');

$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}
$apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
$apikey = function_exists('local_aiconfig_get_apikey')
    ? local_aiconfig_get_apikey()
    : get_config('local_rtocompliance', 'apikey');

if (empty($apikey)) {
    cli_writeln('No platform API key configured - cannot probe. Set it in RTO Settings.');
    exit(1);
}
cli_writeln('Endpoint base: ' . $apiurl);
cli_writeln('');

/**
 * Make one GET and report the raw outcome. No interpretation, no writes.
 *
 * @param string $url
 * @param string $apikey
 * @return void
 */
function local_rtocompliance_probe_get(string $url, string $apikey): void {
    $curl = new \curl();
    $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 30]);
    $curl->setHeader(['X-API-Key: ' . $apikey, 'Content-Type: application/json']);
    $response = $curl->get($url);
    $http = (int)($curl->get_info()['http_code'] ?? 0);

    $path = parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) !== null
        ? '?' . parse_url($url, PHP_URL_QUERY) : '');
    if ($curl->get_errno()) {
        cli_writeln(sprintf('    %-52s NETWORK ERROR: %s', $path, $curl->get_error_msg()));
        return;
    }
    $body = trim((string)$response);
    $short = preg_replace('/\s+/', ' ', substr($body, 0, 240));
    cli_writeln(sprintf('    %-52s HTTP %d', $path, $http));
    cli_writeln('      ' . ($short === '' ? '(empty body)' : $short));
}

foreach ($bytype as $type => $list) {
    $example = $list[0];
    cli_writeln(sprintf('%s  -  example %s', strtoupper($type), $example));

    // What the plugin asks today.
    local_rtocompliance_probe_get(
        $apiurl . '/api/tga/qualification/' . urlencode($example), $apikey);

    // The generic search, which external.php already uses with type=unit. If it
    // answers for skill sets and accredited courses, the fallback is a one-line change.
    local_rtocompliance_probe_get(
        $apiurl . '/api/tga/search?filter=' . urlencode($example), $apikey);

    cli_writeln('');
}

cli_writeln('Nothing was written. No code was classified.');
exit(0);
