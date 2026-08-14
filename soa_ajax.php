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
 * RTO Compliance plugin — soa_ajax.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// v4.6.101 MULTI-UNIT-SOA — AJAX handler for soa_issue.php.
// Actions: getstudent | getunits | generatesoa

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/soa_compliance_engine.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

use local_rtocompliance\soa_compliance_engine;
use local_rtocompliance\usi\usi_platform_client;

require_login(null, false);
require_capability('local/rtocompliance:issuecerts', context_system::instance());
require_sesskey();

// Release session lock AFTER authentication so require_login() can write
// session data normally (last access time, etc.).  Moving write_close() to
// before require_login() caused Moodle 4.x to redirect to the login page
// because the session appeared invalid, returning HTML instead of JSON and
// breaking every AJAX call on soa_issue.php.
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHANUMEXT);

try {
    switch ($action) {

        // ── getstudent: return student info card data ────────────────────────
        case 'getstudent': {
            $userid = required_param('userid', PARAM_INT);
            $data   = soa_compliance_engine::get_student_summary($userid);
            echo json_encode(['ok' => true, 'data' => $data]);
            break;
        }

        // ── getunits: return eligible units + suggested groups ───────────────
        case 'getunits': {
            $userid   = required_param('userid', PARAM_INT);
            $override = optional_param('override', 0, PARAM_INT);
            $units    = soa_compliance_engine::get_eligible_units($userid, (bool)$override);
            $groups   = soa_compliance_engine::get_suggested_groups($units);
            // Convert stdClass objects to arrays for JSON encoding
            $unitsArr  = array_map(function ($u) { return (array)$u; }, $units);
            $groupsArr = array_map(function ($g) {
                $g['units'] = array_map(function ($u) { return (array)$u; }, $g['units']);
                return $g;
            }, $groups);
            echo json_encode(['ok' => true, 'units' => $unitsArr, 'groups' => $groupsArr]);
            break;
        }

        // ── generatesoa: validate, create cert, save snapshot ────────────────
        case 'generatesoa': {
            global $DB, $USER;

            $userid    = required_param('userid', PARAM_INT);
            $courseids = required_param('courseids', PARAM_RAW); // JSON array of ints  // pipeline-ignore: PARAM_RAW — JSON document, json_decode()'d immediately and rejected if it does not decode
            $audience  = optional_param('audience', 'default', PARAM_ALPHA);
            $qualcode  = optional_param('qualcode', '', PARAM_ALPHANUMEXT);
            $qualname  = optional_param('qualname', '', PARAM_TEXT);
            $notes     = optional_param('notes', '', PARAM_TEXT);
            $bypassval = optional_param('bypass', 0, PARAM_INT);

            $cidArray = json_decode($courseids, true);
            if (!is_array($cidArray) || empty($cidArray)) {
                echo json_encode(['ok' => false, 'error' => 'No units selected']);
                break;
            }
            $cidArray = array_map('intval', $cidArray);

            // Load all eligible units for this student
            $allUnits = soa_compliance_engine::get_eligible_units($userid, (bool)$bypassval);

            // Keep only the ones the admin selected
            $selectedUnits = array_values(
                array_filter($allUnits, function ($u) use ($cidArray) { return in_array((int)$u->courseid, $cidArray, true); })
            );

            if (empty($selectedUnits)) {
                echo json_encode(['ok' => false, 'error' => 'No eligible units match the selection — the student may not have completed those units']);
                break;
            }

            // Compliance gate
            if (!$bypassval) {
                $blockingErrors = [];
                foreach ($selectedUnits as $u) {
                    foreach (($u->compliance['errors'] ?? []) as $err) {
                        $blockingErrors[] = $u->unitcode . ': ' . $err;
                    }
                }
                if (!empty($blockingErrors)) {
                    echo json_encode([
                        'ok'     => false,
                        'error'  => 'Compliance check failed — resolve the following before issuing:',
                        'detail' => $blockingErrors,
                    ]);
                    break;
                }
            }

            // NO-GATE-BYPASS (v5.9.414): the multi-unit SoA path used to insert the
            // certificate directly, skipping the hard compliance gates that
            // local_rtocompliance_programmatic_issue_cert() enforces for every other
            // cert type. Apply them here too — BEFORE consuming credits — so a
            // Statement of Attainment can no longer be issued to a student with no /
            // unverified USI or with mandatory RTO identity unset. These gates are
            // deliberately NOT covered by the compliance $bypass (which only overrides
            // unit-level checks): USI (Clause 12) and RTO identity are non-negotiable.
            $stusi   = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'usi, usiverified');
            $studusi = trim((string)($stusi->usi ?? ''));
            if ($studusi === '') {
                echo json_encode(['ok' => false, 'error' => 'Cannot issue — no USI is recorded for this student. A verified USI is required before issuing a Statement of Attainment (or mark the student USI-exempt).']);
                break;
            }
            if (!local_rtocompliance_usi_is_verified($stusi->usiverified)) {
                echo json_encode(['ok' => false, 'error' => 'Cannot issue — the USI on file has not been verified with the USI Registry. Verify the USI before issuing this Statement of Attainment.']);
                break;
            }
            $missingset = local_rtocompliance_missing_cert_settings();
            if (!empty($missingset)) {
                echo json_encode(['ok' => false, 'error' => 'Cannot issue — required RTO details are not configured: ' . implode(', ', $missingset) . '. Set them in RTO Settings before issuing.']);
                break;
            }

            // Credit deduction (5 credits per certificate)
            $platformclient = new usi_platform_client();
            $creditresult   = $platformclient->consume_credits(
                5, 'certificate', 'local_rtocompliance',
                ['certtype' => 'statement', 'studentid' => $userid, 'unitcount' => count($selectedUnits)]
            );
            if (!$creditresult['ok'] && ($creditresult['error'] ?? '') === 'INSUFFICIENT_CREDITS') {
                echo json_encode([
                    'ok'     => false,
                    'error'  => 'Insufficient credits — current balance: ' . (int)($creditresult['credits'] ?? 0) . '. Each SOA costs 5 credits.',
                    'buyUrl' => $creditresult['buyUrl'] ?? '',
                ]);
                break;
            }

            // Build units JSON (immutable snapshot inline on cert row)
            // SOA-CLEAN-UNITNAME (v5.9.281): strip unit-code prefix from the Moodle
            // course fullname before storage.  Moodle courses are commonly named
            // "ABC12345 Apply knowledge…" or "ABC12345 - Apply knowledge…"; without
            // this strip the rendered line becomes "ABC12345 — ABC12345 Apply
            // knowledge…" (code shown twice).  resolve_payload() has a matching strip
            // as a safety net for certs already in the DB.
            $unitsJson = [];
            foreach ($selectedUnits as $u) {
                $_unitcode = trim((string)($u->unitcode ?? ''));
                $_unitname = trim((string)($u->unittitle ?? ''));
                if ($_unitcode !== '' && $_unitname !== '' && strpos($_unitname, $_unitcode) === 0) {
                    $_unitname = ltrim(substr($_unitname, strlen($_unitcode)), " \t-\xe2\x80\x94\xe2\x80\x93");
                }
                // v5.9.367 SOA-DATE-FIX: derive a Semester/Year value from the actual
                // completion date. The renderer's Record-of-Results/date column reads
                // 'semester' (or 'year'), never 'date' — so without this the column fell
                // back to a semester derived from the cert ISSUE date instead of when the
                // unit was actually completed. Keep 'date' too for any template using it.
                $_cdate = (int) ($u->completiondate ?? 0);
                $_semester = '';
                if ($_cdate > 0) {
                    $_semester = ((int) date('n', $_cdate) <= 6 ? 'Sem 1 ' : 'Sem 2 ') . date('Y', $_cdate);
                }
                $unitsJson[] = [
                    'code'     => $_unitcode,
                    'name'     => $_unitname,
                    'outcome'  => $u->outcomeidentifier,
                    'date'     => $_cdate > 0 ? date('d/m/Y', $_cdate) : '',
                    'semester' => $_semester,
                ];
            }

            // SoA is always 'statement' → ABC-SOA-YYYY-NNNN. v5.9.361.
            $certnumber = local_rtocompliance_generate_cert_number('statement');

            // Resolve cert template
            require_once(__DIR__ . '/classes/cert_template.php');
            $certtmplid = null;
            try {
                $picked = \local_rtocompliance\cert_template::pick_for_audience('statement', $audience);
                if (!$picked && $audience !== 'default') {
                    $picked = \local_rtocompliance\cert_template::pick_for_audience('statement', 'default');
                }
                if ($picked && !empty($picked->id)) {
                    $certtmplid = (int)$picked->id;
                }
            } catch (\Throwable $e) { /* non-fatal */ }

            // Insert cert record
            $cert = new stdClass();
            $cert->userid            = $userid;
            $cert->certnumber        = $certnumber;
            $cert->certtype          = 'statement';
            $cert->qualificationcode = $qualcode;
            $cert->qualificationname = $qualname;
            $cert->units             = json_encode($unitsJson);
            $cert->issuedate         = time();
            $cert->expirydate        = null;
            $cert->verifytoken       = local_rtocompliance_generate_certificate_token();
            $cert->status            = 'issued';
            $cert->issuedby          = $USER->id;
            $cert->notes             = $notes;
            $cert->emailsent         = 0;
            $cert->certtmplid        = $certtmplid;
            // AS-ISSUED INTEGRITY (v5.9.414): snapshot the verified USI and the RTO
            // identity settings at issue time so a later settings change cannot
            // retroactively rewrite this already-issued SoA (matches every other path).
            $cert->usi               = $studusi;
            if (function_exists('local_rtocompliance_cert_issue_snapshot')) {
                $cert->issuesnapshot = local_rtocompliance_cert_issue_snapshot();
            }
            $cert->timecreated       = time();
            $cert->timemodified      = time();
            $cert->id = $DB->insert_record('local_rtocompliance_certs', $cert);

            // Write immutable compliance snapshot
            soa_compliance_engine::save_soa_snapshot($cert->id, $userid, $USER->id, $selectedUnits);

            // REGISTRY-PUBLISH (v5.9.414): publish to the central verification registry
            // (best-effort; no-op when the platform registry isn't configured) so the
            // SoA's QR verifies centrally like every other issued certificate.
            if (function_exists('local_rtocompliance_publish_cert_to_registry')) {
                try {
                    $certpublishuser = $DB->get_record('user', ['id' => $userid]);
                    if ($certpublishuser) {
                        local_rtocompliance_publish_cert_to_registry($cert, $certpublishuser);
                    }
                } catch (\Throwable $e) { /* non-fatal */ }
            }

            // Audit log
            $log = new stdClass();
            $log->action       = 'issue_multi_unit_soa';
            $log->component    = 'certs';
            $log->itemid       = $cert->id;
            $log->userid       = $USER->id;
            $log->targetuserid = $userid;
            $log->details      = json_encode([
                'certnumber' => $certnumber,
                'unitcount'  => count($selectedUnits),
                'units'      => array_column($unitsJson, 'code'),
                'bypass'     => (bool)$bypassval,
            ]);
            $log->ipaddress   = getremoteaddr();
            $log->timecreated = time();
            $DB->insert_record('local_rtocompliance_log', $log);

            echo json_encode([
                'ok'          => true,
                'certnumber'  => $certnumber,
                'certid'      => $cert->id,
                'unitcount'   => count($selectedUnits),
                'viewurl'     => (new moodle_url('/local/rtocompliance/certificates.php'))->out(false),
                'downloadurl' => (new moodle_url('/local/rtocompliance/download_cert.php', ['id' => $cert->id]))->out(false),
            ]);
            break;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . s($action)]);
    }

} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
