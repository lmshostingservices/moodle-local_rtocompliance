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
 * RTO Compliance plugin — nominalhours_lookup.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// NOMINAL-HOURS (v5.9.418) — internal nominal-hours lookup endpoint.
//
// Replaces the previous autofill call to https://lms-labs.com/api/moodle/course-info/
// nominal-hours/{code}. The plugin now owns the data: it resolves the unit's nominal
// hours from the local authoritative reference table (local_rtocompliance_nominalhours)
// for the RTO's configured reporting state, falling back to the NCVER nationally-agreed
// value (state='NAT'). No external dependency; auditable provenance is returned.
//
// Returns JSON: {success, nominalHours, source, state, sourceref}.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/rtocompliance:manage', context_system::instance());

header('Content-Type: application/json');

// BATCH MODE (v5.9.421, Nominal Hours Phase 2): the Qualification Builder passes a
// comma-separated list of unit codes so it can populate authoritative nominal hours
// for every unit at once (training.gov.au does not publish nominal hours, so without
// this the per-unit hours — and therefore the qualification total — stay 0). Returns
// {success, hours:{CODE:{nominalHours,source,state,sourceref}}, totalHours, resolved, missing[]}.
$codesparam = (string) optional_param('codes', '', PARAM_TEXT);
if (trim($codesparam) !== '') {
    $codes = array_unique(array_filter(array_map(function ($c) {
        return strtoupper(preg_replace('/\s+/', '', $c));
    }, explode(',', $codesparam))));
    $map = [];
    $missing = [];
    $total = 0;
    $resolved = 0;
    foreach ($codes as $c) {
        if ($c === '') {
            continue;
        }
        $r = local_rtocompliance_lookup_nominalhours($c);
        if ($r === null) {
            $missing[] = $c;
            continue;
        }
        $map[$c] = [
            'nominalHours' => (int) $r['nominalhours'],
            'source'       => 'database',
            'state'        => $r['state'],
            'sourceref'    => $r['sourceref'] ?? '',
        ];
        $total += (int) $r['nominalhours'];
        $resolved++;
    }
    echo json_encode([
        'success'    => true,
        'hours'      => $map,
        'totalHours' => $total,
        'resolved'   => $resolved,
        'missing'    => array_values($missing),
    ]);
    exit;
}

$code = strtoupper(preg_replace('/\s+/', '', (string) optional_param('code', '', PARAM_TEXT)));
if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'No code supplied']);
    exit;
}

$result = local_rtocompliance_lookup_nominalhours($code);
if ($result === null) {
    echo json_encode([
        'success'      => false,
        'nominalHours' => 0,
        'source'       => 'none',
        'message'      => 'No nominal-hours value on file for ' . $code
            . '. Import the NCVER nationally-agreed dataset (or enter manually).',
    ]);
    exit;
}

echo json_encode([
    'success'      => true,
    'nominalHours' => (int) $result['nominalhours'],
    'source'       => 'database',
    'state'        => $result['state'],
    'sourceref'    => $result['sourceref'] ?? '',
]);
exit;
