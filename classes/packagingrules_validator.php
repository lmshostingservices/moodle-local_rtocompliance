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

namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

/**
 * Packaging rules validator.
 *
 * Fetches authoritative packaging rules from the TGA qualbuilder API, then
 * cross-checks the selected units against those rules.  Falls back to the
 * values stored in the qualification product record when the API is
 * unavailable.
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class packagingrules_validator {
    // ── TGA API helpers ──────────────────────────────────────────────────────

    /**
     * Fetch packaging rules from the Node.js TGA qualbuilder API.
     *
     * Returns an associative array with keys:
     *   totalUnits, coreRequired, electiveRequired,
     *   groupRequirements, pointsRequired, pointsSystem,
     *   rulesText, tgaUnits
     *
     * Returns null if the API is unreachable or returns an error.
     *
     * @param  string $qualcode  e.g. 'BSB30120'
     * @return array|null
     */
    private static function fetch_tga_rules(string $qualcode): ?array {
        global $CFG;

        // Allow including the aiconfig helper if it is installed.
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }

        $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = local_aiconfig_get_apikey();
        } else {
            $apikey = get_config('local_rtocompliance', 'apikey');
        }

        if (empty($apikey)) {
            return null;
        }

        // Always request fresh data for validation — the 30-day server-side cache would
        // otherwise serve stale groupRequirements (e.g. {min:1} for all groups) long
        // after the parsing code has been fixed. ?refresh=1 clears the cache key first.
        $url = rtrim($apiurl, '/') . '/api/tga/qualbuilder/' . urlencode(strtoupper(trim($qualcode))) . '?refresh=1';

        \core\session\manager::write_close();
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 20]);
        $curl->setHeader(['X-API-Key: ' . $apikey, 'Content-Type: application/json']);
        $response = $curl->get($url);
        $httpcode = $curl->info['http_code'];
        $curlerr  = $curl->error;

        if ($curlerr || $httpcode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['success']) || empty($data['packagingRules'])) {
            return null;
        }

        $pkg = $data['packagingRules'];
        return [
            'totalUnits'        => (int)($pkg['totalUnits']        ?? 0),
            'coreRequired'      => (int)($pkg['coreRequired']       ?? 0),
            'electiveRequired'  => (int)($pkg['electiveRequired']   ?? 0),
            'groupRequirements' => $pkg['groupRequirements']        ?? [],
            'pointsRequired'    => (int)($pkg['pointsRequired']     ?? 0),
            'pointsSystem'      => !empty($pkg['pointsSystem']),
            'rulesText'         => $pkg['rulesText']                ?? [],
            'tgaUnits'          => $data['units']                   ?? [],
        ];
    }

    // ── Main validate method ─────────────────────────────────────────────────

    public static function validate($product, $units): array {
        $checks   = [];
        $errors   = [];
        $warnings = [];

        // ── Determine packaging requirements ─────────────────────────────────
        // Priority 1: live TGA API (authoritative).
        // Priority 2: values stored in the product record (may be stale).

        $tgaSource = false;
        $tgaRules  = self::fetch_tga_rules($product->qualificationcode);

        if ($tgaRules !== null) {
            $tgaSource       = true;
            $totalRequired   = $tgaRules['totalUnits'];
            $coreRequired    = $tgaRules['coreRequired'];
            $electiveRequired = $tgaRules['electiveRequired'];
            $groupRules      = $tgaRules['groupRequirements'];  // e.g. ['A' => ['min'=>2,'max'=>3], …]
            $pointsSystem    = $tgaRules['pointsSystem'];
            $pointsRequired  = $tgaRules['pointsRequired'];
            $rulesText       = $tgaRules['rulesText'];
            $tgaUnits        = $tgaRules['tgaUnits'];

            // If TGA returned no group rules (text parsing missed them), fall back to
            // the group rules stored in the product record — they were saved when the
            // qualification was last built from TGA via the qualbuilder.
            if (empty($groupRules) && !empty($product->electiverules)) {
                $storedRules = json_decode($product->electiverules, true);
                if (!empty($storedRules['requiredGroups'])) {
                    $groupRules = $storedRules['requiredGroups'];
                }
            }
        } else {
            // Fallback: use DB product record.
            $warnings[] = 'Could not connect to TGA API — using stored packaging rule values (may be outdated).';
            $totalRequired   = (int)($product->totalunits    ?? 0);
            $coreRequired    = (int)($product->coreunitcount ?? 0);
            $electiveRequired = (int)($product->electivecount ?? 0);
            // Derive electiveRequired when DB also stored 0 (the v3.8.71 bug).
            if ($electiveRequired === 0 && $totalRequired > $coreRequired) {
                $electiveRequired = $totalRequired - $coreRequired;
            }
            $groupRules     = [];
            $pointsSystem   = false;
            $pointsRequired = 0;
            $rulesText      = [];
            $tgaUnits       = [];

            // Pull any stored electiverules JSON for group-based / points-based quals.
            $electiverules = !empty($product->electiverules) ? json_decode($product->electiverules, true) : [];
            if (!empty($electiverules['pointsSystem'])) {
                $pointsSystem   = true;
                $pointsRequired = (int)($electiverules['pointsRequired'] ?? 0);
            }
            if (!empty($electiverules['requiredGroups'])) {
                $groupRules = $electiverules['requiredGroups'];
            }
        }

        // ── Last-resort group inference from unit data ───────────────────────
        // If groupRules is still empty but units have electivegroup values, infer
        // the groups from the unit data so they at least appear in the checks table.
        // Uses min:0 (optional) since we can't derive the minimum from unit data alone.
        if (empty($groupRules)) {
            foreach ($units as $u) {
                $grp = strtoupper(trim($u->electivegroup ?? ''));
                if ($grp !== '' && ($u->unittype === 'elective' || $u->unittype === 'imported')) {
                    if (!isset($groupRules[$grp])) {
                        $groupRules[$grp] = ['min' => 0, 'max' => 999];
                    }
                }
            }
        }

        // ── Classify selected units ──────────────────────────────────────────
        $coreunits     = array_filter($units, fn($u) => $u->unittype === 'core');
        $electiveunits = array_filter($units, fn($u) => $u->unittype === 'elective');
        $importedunits = array_filter($units, fn($u) => $u->unittype === 'imported');

        $totalcount    = count($units);
        $corecount     = count($coreunits);
        $electivecount = count($electiveunits);
        $importedcount = count($importedunits);
        $actualElectives = $electivecount + $importedcount;

        // Show data source in a header note.
        if ($tgaSource) {
            $warnings[] = 'Packaging rules sourced live from training.gov.au (TGA).';
        }

        // ── Total units ──────────────────────────────────────────────────────
        if (!$pointsSystem && $totalRequired > 0) {
            $passed = $totalcount >= $totalRequired;
            $checks[] = [
                'name'     => get_string('check_total_units', 'local_rtocompliance'),
                'expected' => '>= ' . $totalRequired,
                'actual'   => $totalcount,
                'passed'   => $passed,
            ];
            if (!$passed) {
                $errors[] = get_string('error_total_units', 'local_rtocompliance', [
                    'expected' => $totalRequired,
                    'actual'   => $totalcount,
                ]);
            }
        }

        // ── Core units ───────────────────────────────────────────────────────
        if ($product->producttype === 'qualification' && !$pointsSystem && $coreRequired > 0) {
            $passed = $corecount >= $coreRequired;
            $checks[] = [
                'name'     => get_string('check_core_units', 'local_rtocompliance'),
                'expected' => $coreRequired,
                'actual'   => $corecount,
                'passed'   => $passed,
            ];
            if (!$passed) {
                $errors[] = get_string('error_core_units', 'local_rtocompliance', [
                    'expected' => $coreRequired,
                    'actual'   => $corecount,
                ]);
            }
        }

        // ── Elective units ───────────────────────────────────────────────────
        // Always show this check when the qualification requires electives, even
        // if electiveRequired is 0 in the DB (that's precisely the bug this fixes).
        if ($product->producttype === 'qualification' && !$pointsSystem) {

            // If we have TGA units available, also check whether the selected electives
            // actually come from the TGA elective pool (unit-level validation).
            $tgaElecPool = [];
            foreach ($tgaUnits as $tu) {
                if (empty($tu['isCore']) && !empty($tu['code'])) {
                    $tgaElecPool[] = strtoupper(trim($tu['code']));
                }
            }
            $tgaElecPoolSize = count($tgaElecPool);

            // Group-based elective requirements (e.g. MEM, UEE quals with Group A/B/C).
            if (!empty($groupRules)) {
                foreach ($groupRules as $grp => $rule) {
                    $min = (int)($rule['min'] ?? 0);
                    $max = isset($rule['max']) ? (int)$rule['max'] : 999;

                    $groupunits = array_filter($units, fn($u) => strtoupper(trim($u->electivegroup ?? '')) === strtoupper($grp));
                    $groupcount = count($groupunits);

                    $passed   = ($min === 0 || $groupcount >= $min) && ($max >= 999 || $groupcount <= $max);
                    if ($min > 0 && $min === $max && $max < 999) {
                        $expected = $min . ' ' . ($min === 1 ? 'unit' : 'units') . ' only';
                    } elseif ($min > 0) {
                        $expected = 'Min ' . $min;
                        if ($max < 999) {
                            $expected .= ', max ' . $max;
                        }
                    } else {
                        $expected = 'optional';
                    }

                    $checks[] = [
                        'name'     => 'Group ' . $grp . ' electives',
                        'expected' => $expected,
                        'actual'   => $groupcount,
                        'passed'   => $passed,
                    ];
                    if (!$passed) {
                        if ($groupcount < $min) {
                            $errors[] = get_string('error_group_minimum', 'local_rtocompliance', [
                                'group'   => $grp,
                                'minimum' => $min,
                                'actual'  => $groupcount,
                            ]);
                        } else {
                            $errors[] = get_string('error_group_maximum', 'local_rtocompliance', [
                                'group'   => $grp,
                                'maximum' => $max,
                                'actual'  => $groupcount,
                            ]);
                        }
                    }
                }

            } else {
                // Simple elective count check (no groups).
                // When electiveRequired > 0 OR TGA has elective units in pool but none selected.
                $showCheck = $electiveRequired > 0 || ($tgaElecPoolSize > 0 && $actualElectives === 0);

                if ($showCheck) {
                    $reqLabel = $electiveRequired > 0 ? '>= ' . $electiveRequired : '>= 1 (TGA pool: ' . $tgaElecPoolSize . ')';
                    $minNeeded = $electiveRequired > 0 ? $electiveRequired : 1;
                    $passed    = $actualElectives >= $minNeeded;

                    $checks[] = [
                        'name'     => get_string('check_elective_units', 'local_rtocompliance'),
                        'expected' => $reqLabel,
                        'actual'   => $actualElectives,
                        'passed'   => $passed,
                    ];
                    if (!$passed) {
                        $errors[] = get_string('error_elective_units', 'local_rtocompliance', [
                            'expected' => $electiveRequired > 0 ? $electiveRequired : 1,
                            'actual'   => $actualElectives,
                        ]);
                    }
                }

                // Validate that selected electives are actually from the TGA elective pool
                // (only when we have TGA data to cross-check against).
                if ($tgaSource && !empty($tgaElecPool)) {
                    $nonPoolUnits = [];
                    foreach ($electiveunits as $eu) {
                        if (!in_array(strtoupper(trim($eu->unitcode)), $tgaElecPool)) {
                            $nonPoolUnits[] = $eu->unitcode;
                        }
                    }
                    if (!empty($nonPoolUnits)) {
                        $warnings[] = 'The following elective units are not in the TGA elective pool for ' .
                                      $product->qualificationcode . ': ' . implode(', ', $nonPoolUnits) .
                                      '. Verify these are valid substitutions per the packaging rules.';
                    }
                }

                // If TGA has a packaging rules text, surface it as informational.
                if ($tgaSource && !empty($rulesText)) {
                    // Surfaced below via warnings if useful.
                }
            }

            // ── Imported unit limit ──────────────────────────────────────────
            // Typically max 2 imported (non-TGA-pool) units are permitted.
            // Only apply this when TGA gives us a pool to compare against.
            if ($tgaSource && !empty($tgaElecPool) && $importedcount > 0) {
                $maxImported = 2; // Standard ASQA rule — max 2 units from outside the listed pool.
                $passed      = $importedcount <= $maxImported;
                $checks[] = [
                    'name'     => get_string('check_imported_limit', 'local_rtocompliance'),
                    'expected' => '<= ' . $maxImported,
                    'actual'   => $importedcount,
                    'passed'   => $passed,
                ];
                if (!$passed) {
                    $errors[] = get_string('error_imported_limit', 'local_rtocompliance', [
                        'maximum' => $maxImported,
                        'actual'  => $importedcount,
                    ]);
                }
            }
        }

        // ── Credit points (MEM/UEE points-based qualifications) ──────────────
        if ($pointsSystem && $pointsRequired > 0) {
            $corepointsrequired = 0;
            $elecpointsrequired = 0;
            if (!$tgaSource) {
                // Pull from stored electiverules JSON.
                $electiverules = !empty($product->electiverules) ? json_decode($product->electiverules, true) : [];
                $corepointsrequired = (int)($electiverules['corePointsRequired']     ?? 0);
                $elecpointsrequired = (int)($electiverules['electivePointsRequired'] ?? 0);
            }

            $totalpoints = array_sum(array_map(fn($u) => (int)($u->creditpoints ?? 0), $units));
            $corepoints  = array_sum(array_map(fn($u) => (int)($u->creditpoints ?? 0), $coreunits));
            $elecpoints  = $totalpoints - $corepoints;

            $passed = $totalpoints >= $pointsRequired;
            $checks[] = [
                'name'     => 'Total credit points',
                'expected' => $pointsRequired . ' pts',
                'actual'   => $totalpoints . ' pts',
                'passed'   => $passed,
            ];
            if (!$passed) {
                $errors[] = 'Total credit points too low: ' . $totalpoints . ' / ' . $pointsRequired . ' required.';
            }

            if ($corepointsrequired > 0) {
                $passed = $corepoints >= $corepointsrequired;
                $checks[] = [
                    'name'     => 'Core credit points',
                    'expected' => '>= ' . $corepointsrequired . ' pts',
                    'actual'   => $corepoints . ' pts',
                    'passed'   => $passed,
                ];
                if (!$passed) {
                    $errors[] = 'Core credit points too low: ' . $corepoints . ' / ' . $corepointsrequired . ' required.';
                }
            }

            if ($elecpointsrequired > 0) {
                $passed = $elecpoints >= $elecpointsrequired;
                $checks[] = [
                    'name'     => 'Elective credit points',
                    'expected' => '>= ' . $elecpointsrequired . ' pts',
                    'actual'   => $elecpoints . ' pts',
                    'passed'   => $passed,
                ];
                if (!$passed) {
                    $errors[] = 'Elective credit points too low: ' . $elecpoints . ' / ' . $elecpointsrequired . ' required.';
                }
            }
        }

        // ── Moodle course link check ─────────────────────────────────────────
        $totalcount_selected = count($units);
        $linkedcount   = 0;
        $unlinkedunits = [];
        foreach ($units as $unit) {
            if (!empty($unit->courseid)) {
                $linkedcount++;
            } else {
                $unlinkedunits[] = $unit->unitcode;
            }
        }

        $checks[] = [
            'name'     => get_string('check_courses_linked', 'local_rtocompliance'),
            'expected' => $totalcount_selected,
            'actual'   => $linkedcount,
            'passed'   => $linkedcount === $totalcount_selected,
        ];
        if ($linkedcount < $totalcount_selected) {
            $warnings[] = get_string('warning_unlinked_units', 'local_rtocompliance', [
                'count' => count($unlinkedunits),
                'units' => implode(', ', array_slice($unlinkedunits, 0, 5)) . (count($unlinkedunits) > 5 ? '…' : ''),
            ]);
        }

        // ── Duplicate unit check ─────────────────────────────────────────────
        $seen       = [];
        $duplicates = [];
        foreach ($units as $unit) {
            $uc = strtoupper(trim($unit->unitcode));
            if (in_array($uc, $seen)) {
                $duplicates[] = $unit->unitcode;
            } else {
                $seen[] = $uc;
            }
        }

        if (!empty($duplicates)) {
            $checks[] = [
                'name'     => get_string('check_no_duplicates', 'local_rtocompliance'),
                'expected' => get_string('no_duplicates', 'local_rtocompliance'),
                'actual'   => count($duplicates) . ' ' . get_string('duplicates_found', 'local_rtocompliance'),
                'passed'   => false,
            ];
            $errors[] = get_string('error_duplicate_units', 'local_rtocompliance', implode(', ', array_unique($duplicates)));
        } else {
            $checks[] = [
                'name'     => get_string('check_no_duplicates', 'local_rtocompliance'),
                'expected' => get_string('no_duplicates', 'local_rtocompliance'),
                'actual'   => get_string('no_duplicates', 'local_rtocompliance'),
                'passed'   => true,
            ];
        }

        return [
            'passed'   => empty($errors),
            'checks'   => $checks,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }
}
