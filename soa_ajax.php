<?php
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
            $unitsArr  = array_map(function($u) { return (array)$u; }, $units);
            $groupsArr = array_map(function($g) {
                $g['units'] = array_map(function($u) { return (array)$u; }, $g['units']);
                return $g;
            }, $groups);
            echo json_encode(['ok' => true, 'units' => $unitsArr, 'groups' => $groupsArr]);
            break;
        }

        // ── generatesoa: validate, create cert, save snapshot ────────────────
        case 'generatesoa': {
            global $DB, $USER;

            $userid    = required_param('userid', PARAM_INT);
            $courseids = required_param('courseids', PARAM_RAW); // JSON array of ints
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
                array_filter($allUnits, function($u) use ($cidArray) { return in_array((int)$u->courseid, $cidArray, true); })
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
            // "TLIE5020 Apply knowledge…" or "TLIE5020 - Apply knowledge…"; without
            // this strip the rendered line becomes "TLIE5020 — TLIE5020 Apply
            // knowledge…" (code shown twice).  resolve_payload() has a matching strip
            // as a safety net for certs already in the DB.
            $unitsJson = [];
            foreach ($selectedUnits as $u) {
                $_unitcode = trim((string)($u->unitcode ?? ''));
                $_unitname = trim((string)($u->unittitle ?? ''));
                if ($_unitcode !== '' && $_unitname !== '' && strpos($_unitname, $_unitcode) === 0) {
                    $_unitname = ltrim(substr($_unitname, strlen($_unitcode)), " \t-\xe2\x80\x94\xe2\x80\x93");
                }
                $unitsJson[] = [
                    'code'    => $_unitcode,
                    'name'    => $_unitname,
                    'outcome' => $u->outcomeidentifier,
                    'date'    => date('d/m/Y', $u->completiondate),
                ];
            }

            // Generate unique cert number
            $prefix   = get_config('local_rtocompliance', 'certprefix') ?: 'CERT';
            $year     = date('Y');
            $sequence = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {local_rtocompliance_certs} WHERE certnumber LIKE ?",
                [$prefix . '-' . $year . '-%']
            ) + 1;
            $certnumber = sprintf('%s-%s-%05d', $prefix, $year, $sequence);

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
            $cert->timecreated       = time();
            $cert->timemodified      = time();
            $cert->id = $DB->insert_record('local_rtocompliance_certs', $cert);

            // Write immutable compliance snapshot
            soa_compliance_engine::save_soa_snapshot($cert->id, $userid, $USER->id, $selectedUnits);

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
