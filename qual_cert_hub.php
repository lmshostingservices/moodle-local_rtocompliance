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
 * RTO Compliance plugin — qual_cert_hub.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// QUAL-CERT-HUB (v5.9.354) — See every student's completion status and issue
// all pending qualification certificates from one page.
//
// Hub home  → table of active quals with funnel stats + quick-issue actions.
// Qual detail → four tabs: Ready to Issue, Certs Issued, Partially Complete,
//               Autocert Queue.
//
// All cert issuance goes through local_rtocompliance_programmatic_issue_cert().
// Completion detection faithfully reproduces the two-source logic from
// generate_qual_certs.php (SOURCE 1: Moodle course_completions; SOURCE 2:
// plugin outcome records).

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/cert_template.php');
require_once(__DIR__ . '/classes/usi/usi_platform_client.php');

admin_externalpage_setup('local_rtocompliance_qual_cert_hub');
require_login();
$context = context_system::instance();
require_capability('local/rtocompliance:issuecerts', $context);

$qualid = optional_param('qualid', 0, PARAM_INT);
$tab    = optional_param('tab',    'ready', PARAM_ALPHA);
$action = optional_param('action', '',      PARAM_ALPHANUMEXT); // PARAM_ALPHA stripped underscores, so issue_all_pending / scan_missed / retry_autocert / process_queue never fired.

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER: build detection variables for a qualification
// Faithfully mirrors generate_qual_certs.php lines 120–253.
// ═══════════════════════════════════════════════════════════════════════════════
function qch_build_detection_vars(stdClass $qual): array {
    global $DB;
    $dbman  = $DB->get_manager();
    $qbid   = (int)$qual->id;

    // Primary linked courseids (QB direct links).
    $linkedcourseids = $DB->get_fieldset_sql(
        "SELECT DISTINCT courseid FROM {local_rtocompliance_qualunits}
          WHERE qualbuilderid = :qbid AND courseid IS NOT NULL
            AND status = 'active' AND selected = 1",
        ['qbid' => $qbid]
    );

    // Primary + variant courseids (for timestamp lookups and SOURCE 1 WHERE clause).
    $alllinkedcourseids = $linkedcourseids;
    if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
        $variantCourseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT quc.courseid
               FROM {local_rtocompliance_qualunit_courses} quc
               JOIN {local_rtocompliance_qualunits} qu ON qu.id = quc.qualunitid
              WHERE qu.qualbuilderid = :qbid AND qu.selected = 1
                AND qu.status = 'active' AND quc.courseid IS NOT NULL",
            ['qbid' => $qbid]
        );
        if ($variantCourseids) {
            $alllinkedcourseids = array_values(array_unique(
                array_merge($alllinkedcourseids, array_map('intval', $variantCourseids))
            ));
        }
    }

    // Category-tree / course-map (quid, cid) pairs for SOURCE 1 UNION literals.
    $catTreePairs = [];
    $gcq_qualcode = strtoupper(trim((string)$qual->qualificationcode));
    $mapExists    = $dbman->table_exists('local_rtocompliance_course_map');

    // SYNC-BEFORE-CERT: reseed to capture any semester-copies added since last nightly sync.
    if ($mapExists && !empty($gcq_qualcode)) {
        local_rtocompliance_seed_course_map($gcq_qualcode);
    }
    $mapHasRows = $mapExists && !empty($gcq_qualcode)
        && $DB->record_exists('local_rtocompliance_course_map', ['qualcode' => $gcq_qualcode]);

    if ($mapHasRows) {
        $mapRows = $DB->get_records_sql(
            "SELECT cm.courseid, qu.id AS qualunitid
               FROM {local_rtocompliance_course_map} cm
               JOIN {local_rtocompliance_qualunits} qu
                 ON qu.qualbuilderid = :qbid
                AND qu.unitcode      = cm.unitcode
                AND qu.selected      = 1
                AND qu.status        = 'active'
              WHERE cm.qualcode = :qcode",
            ['qbid' => $qbid, 'qcode' => $gcq_qualcode]
        );
        foreach ($mapRows as $mr) {
            $cid = (int)$mr->courseid;
            if (!in_array($cid, $alllinkedcourseids)) {
                $alllinkedcourseids[] = $cid;
            }
            $catTreePairs[] = ['quid' => (int)$mr->qualunitid, 'cid' => $cid];
        }
    } elseif (!empty($gcq_qualcode)) {
        // Legacy fallback: runtime category-tree regex walk.
        $rootcatid  = local_rtocompliance_get_qual_root_category_id($gcq_qualcode);
        $subtreeids = $rootcatid > 0 ? local_rtocompliance_get_category_subtree_ids($rootcatid) : [];
        if ($subtreeids) {
            $gunits = $DB->get_records_sql(
                "SELECT id AS quid, unitcode FROM {local_rtocompliance_qualunits}
                  WHERE qualbuilderid = :qbid AND selected = 1 AND status = 'active'
                    AND unitcode IS NOT NULL AND unitcode != ''",
                ['qbid' => $qbid]
            );
            foreach ($gunits as $gu) {
                $catcourses = local_rtocompliance_get_category_tree_courseids_for_unit(
                    $rootcatid, $gu->unitcode, $subtreeids
                );
                foreach ($catcourses as $cid) {
                    if (!in_array($cid, $alllinkedcourseids)) {
                        $alllinkedcourseids[] = (int)$cid;
                    }
                    $catTreePairs[] = ['quid' => (int)$gu->quid, 'cid' => (int)$cid];
                }
            }
        }
    }

    // Effective linked unit count: units with at least one course via any source.
    if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
        $effectiveQids = $DB->get_fieldset_sql(
            "SELECT DISTINCT qu.id
               FROM {local_rtocompliance_qualunits} qu
               LEFT JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu.id
              WHERE qu.qualbuilderid = :qbid AND qu.selected = 1 AND qu.status = 'active'
                AND (qu.courseid IS NOT NULL OR quc.courseid IS NOT NULL)",
            ['qbid' => $qbid]
        );
    } else {
        $effectiveQids = $DB->get_fieldset_sql(
            "SELECT DISTINCT id FROM {local_rtocompliance_qualunits}
              WHERE qualbuilderid = :qbid AND selected = 1 AND status = 'active'
                AND courseid IS NOT NULL",
            ['qbid' => $qbid]
        );
    }
    if (!empty($catTreePairs)) {
        $ctQids        = array_values(array_unique(array_column($catTreePairs, 'quid')));
        $effectiveQids = array_values(array_unique(
            array_merge($effectiveQids, array_map('intval', $ctQids))
        ));
    }
    $effectiveLinkedUnitCount = count($effectiveQids);

    // numunits: active selected units WITH a unit code (SOURCE 2 denominator).
    $numunits = (int)$DB->count_records_sql(
        "SELECT COUNT(DISTINCT id) FROM {local_rtocompliance_qualunits}
          WHERE qualbuilderid = :qbid AND selected = 1 AND status = 'active'
            AND " . $DB->sql_isnotempty('local_rtocompliance_qualunits', 'unitcode', false, false),
        ['qbid' => $qbid]
    );

    return [
        'qualid'                   => $qbid,
        'linkedcourseids'          => $linkedcourseids,
        'alllinkedcourseids'       => $alllinkedcourseids,
        'catTreePairs'             => $catTreePairs,
        'effectiveLinkedUnitCount' => $effectiveLinkedUnitCount,
        'numunits'                 => $numunits,
        'dbman'                    => $dbman,
        'gcq_qualcode'             => $gcq_qualcode,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER: run two-source completion detection for a qualification.
// Faithfully mirrors generate_qual_certs.php lines 555–673.
// Returns array keyed by Moodle userid; each value has user fields + timecompleted.
// ═══════════════════════════════════════════════════════════════════════════════
function qch_get_completers(stdClass $qual, array $det): array {
    global $DB;
    $qbid                     = $det['qualid'];
    $alllinkedcourseids       = $det['alllinkedcourseids'];
    $catTreePairs             = $det['catTreePairs'];
    $effectiveLinkedUnitCount = $det['effectiveLinkedUnitCount'];
    $numunits                 = $det['numunits'];
    $dbman                    = $det['dbman'];

    $allcompleters = [];

    // SOURCE 1 — Moodle course_completions.
    if ($effectiveLinkedUnitCount > 0) {
        $src1cids = empty($alllinkedcourseids) ? [0] : $alllinkedcourseids;
        list($insql, $inparams) = $DB->get_in_or_equal($src1cids, SQL_PARAMS_NAMED, 'cid');

        $unitcoursesSubq = "SELECT qu.id AS quid, qu.courseid AS cid
                              FROM {local_rtocompliance_qualunits} qu
                             WHERE qu.qualbuilderid = :src1_qbid1
                               AND qu.selected = 1 AND qu.status = 'active'
                               AND qu.courseid IS NOT NULL";
        $src1extra = ['src1_qbid1' => $qbid];

        if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
            $unitcoursesSubq .= "
                 UNION
                 SELECT qu.id AS quid, quc.courseid AS cid
                   FROM {local_rtocompliance_qualunits} qu
                   JOIN {local_rtocompliance_qualunit_courses} quc ON quc.qualunitid = qu.id
                  WHERE qu.qualbuilderid = :src1_qbid2
                    AND qu.selected = 1 AND qu.status = 'active'
                    AND quc.courseid IS NOT NULL";
            $src1extra['src1_qbid2'] = $qbid;
        }

        foreach ($catTreePairs as $pairIdx => $pair) {
            $qk = 'ctq' . $pairIdx;
            $ck = 'ctc' . $pairIdx;
            $unitcoursesSubq   .= "\n             UNION SELECT :$qk AS quid, :$ck AS cid";
            $src1extra[$qk]     = $pair['quid'];
            $src1extra[$ck]     = $pair['cid'];
        }

        $allcompleters = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                    u.suspended,
                    MIN(cc.timecompleted) AS timecompleted
               FROM {user} u
               JOIN {course_completions} cc ON cc.userid = u.id
               JOIN ($unitcoursesSubq) unitcourses ON unitcourses.cid = cc.course
              WHERE cc.course {$insql}
                AND cc.timecompleted IS NOT NULL
                AND cc.timecompleted > 0
                AND u.deleted = 0
              GROUP BY u.id, u.firstname, u.lastname, u.email,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.suspended
             HAVING COUNT(DISTINCT unitcourses.quid) >= :numcourses
              ORDER BY u.lastname, u.firstname",
            array_merge($inparams, $src1extra, ['numcourses' => $effectiveLinkedUnitCount])
        );
    }

    // SOURCE 2 — Plugin outcome records (local_rtocompliance_enrolments).
    if ($numunits > 0
        && $dbman->table_exists('local_rtocompliance_enrolments')
        && $dbman->table_exists('local_rtocompliance_students')
    ) {
        $outcomecompleters = $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.email,
                    u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                    u.suspended,
                    MAX(COALESCE(e.activityenddate, e.timecreated)) AS timecompleted
               FROM {local_rtocompliance_students} s
               JOIN {user} u ON u.id = s.userid AND u.deleted = 0
               JOIN {local_rtocompliance_enrolments} e ON e.studentid = s.id
                    AND e.outcomeidentifier IN ('20','51','60','81')
               JOIN {local_rtocompliance_qualunits} qu ON qu.unitcode = e.unitcode
                    AND qu.qualbuilderid = :qbid
                    AND qu.selected = 1
                    AND qu.status = 'active'
              GROUP BY s.id, u.id, u.firstname, u.lastname, u.email,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.suspended
             HAVING COUNT(DISTINCT qu.id) >= :numunits
             ORDER BY u.lastname, u.firstname",
            ['qbid' => $qbid, 'numunits' => $numunits]
        );

        foreach ($outcomecompleters as $userid => $completer) {
            if (!isset($allcompleters[$userid])) {
                if (empty($completer->timecompleted) || $completer->timecompleted <= 0) {
                    $completer->timecompleted = time();
                }
                $allcompleters[$userid] = $completer;
            }
        }

        uasort($allcompleters, function ($a, $b) {
            $c = strcmp($a->lastname ?? '', $b->lastname ?? '');
            return $c !== 0 ? $c : strcmp($a->firstname ?? '', $b->firstname ?? '');
        });
    }

    return $allcompleters;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER: hub home stats — reads from a 15-minute get_config/set_config cache;
// falls through to a live computation when the entry is absent or expired.
// Cache key format: local_rtocompliance / hub_stats_{qualid}
// Returns array keys: enrolled, complete, issued, pending, ts, from_cache.
// ═══════════════════════════════════════════════════════════════════════════════
function qch_get_hub_stats(stdClass $q, $dbman_home, bool $forceRefresh = false): array {
    global $DB;
    $cacheKey = 'hub_stats_' . (int)$q->id;
    $ttl      = 15 * 60; // 15 minutes in seconds

    if (!$forceRefresh) {
        $cached = get_config('local_rtocompliance', $cacheKey);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data) && isset($data['ts']) && (time() - (int)$data['ts']) < $ttl) {
                $data['from_cache'] = true;
                return $data;
            }
        }
    }

    $qcode = strtoupper(trim((string)$q->qualificationcode));

    // Enrolled: active enrolments in course_map courses.
    $enrolledCount = 0;
    if ($dbman_home->table_exists('local_rtocompliance_course_map')) {
        $enrolledCount = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
               FROM {user_enrolments} ue
               JOIN {enrol} en ON en.id = ue.enrolid
              WHERE ue.status = 0
                AND en.courseid IN (
                    SELECT courseid FROM {local_rtocompliance_course_map}
                     WHERE qualcode = :qcode
                )",
            ['qcode' => $qcode]
        );
    }

    // Two-source completers (the expensive part).
    $det           = qch_build_detection_vars($q);
    $completers    = qch_get_completers($q, $det);
    $completeCount = count($completers);

    // Issued testamur count.
    $issuedCount = (int)$DB->count_records_sql(
        "SELECT COUNT(DISTINCT userid) FROM {local_rtocompliance_certs}
          WHERE qualificationcode = :qc AND certtype = 'testamur'
            AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)",
        ['qc' => $q->qualificationcode]
    );

    // Pending = completers who have no active testamur.
    $pendingCount = 0;
    if (!empty($completers)) {
        $cIds = array_keys($completers);
        list($uidsql, $uidp) = $DB->get_in_or_equal($cIds, SQL_PARAMS_NAMED, 'hcu');
        $certedIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {local_rtocompliance_certs}
              WHERE qualificationcode = :hqc AND certtype = 'testamur'
                AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)
                AND userid $uidsql",
            array_merge(['hqc' => $q->qualificationcode], $uidp)
        );
        $pendingCount = $completeCount - count($certedIds);
    }

    $data = [
        'enrolled'   => $enrolledCount,
        'complete'   => $completeCount,
        'issued'     => $issuedCount,
        'pending'    => $pendingCount,
        'ts'         => time(),
        'from_cache' => false,
    ];
    set_config($cacheKey, json_encode($data), 'local_rtocompliance');
    return $data;
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPER: issue testamur + record for a batch of Moodle userids.
// Faithfully mirrors generate_qual_certs.php lines 260–462.
// Returns ['issued', 'skipped', 'failed', 'voided', 'messages', 'creditsExhausted'].
// ═══════════════════════════════════════════════════════════════════════════════
function qch_issue_batch(stdClass $qual, array $userids, array $det, int $sendemail, int $forceregen): array {
    global $DB;
    if (empty($userids)) {
        return ['issued' => 0, 'skipped' => 0, 'failed' => 0, 'voided' => 0, 'messages' => [], 'creditsExhausted' => false];
    }

    \core\session\manager::write_close();
    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_HUGE);

    $allunits           = local_rtocompliance_get_qualbuilder_unit_list($qual->id);
    $alllinkedcourseids = $det['alllinkedcourseids'];

    $issued           = 0;
    $skipped          = 0;
    $usiskipped       = 0; // v5.9.383: students skipped because no USI is recorded.
    $failed           = 0;
    $voided           = 0;
    $messages         = [];
    $creditsExhausted = false;

    foreach ($userids as $userid) {
        if ($creditsExhausted) {
            $failed++;
            $uobj = core_user::get_user($userid);
            $messages[] = fullname($uobj ?: (object)['firstname' => '', 'lastname' => (string)$userid])
                . ' — SKIPPED: insufficient credits (not attempted)';
            continue;
        }

        $user = core_user::get_user($userid);
        if (!$user) {
            $failed++;
            continue;
        }

        $studentrec   = $DB->get_record('local_rtocompliance_students', ['userid' => $userid], 'id', IGNORE_MISSING);
        $unitsForCert = [];
        if ($studentrec) {
            $unitsForCert = local_rtocompliance_get_qualbuilder_unit_list_with_outcomes($qual->id, $studentrec->id);
        }
        if (empty($unitsForCert)) {
            $unitsForCert = $allunits;
        }

        // AUTOCERT-STATUS-FIX: track per-student issuance outcome so the
        // autocert queue row gets the correct terminal status. The outer
        // $issued/$failed counters are global; we need per-student values.
        $issuedThisStudent  = 0;
        $failedThisStudent  = 0;
        $usiskipThisStudent = 0;
        $lastErrThisStudent = '';

        foreach (['testamur', 'record'] as $certtype) {
            $existingcert = $DB->get_record_sql(
                "SELECT * FROM {local_rtocompliance_certs}
                  WHERE userid            = :userid
                    AND certtype          = :certtype
                    AND qualificationcode = :qualcode
                    AND status            = 'issued'
                    AND (reissued_at IS NULL OR reissued_at = 0)
                  LIMIT 1",
                ['userid' => $userid, 'certtype' => $certtype, 'qualcode' => $qual->qualificationcode]
            );

            if ($existingcert) {
                if (!$forceregen) {
                    $skipped++;
                    continue;
                }
                $DB->update_record('local_rtocompliance_certs', (object)[
                    'id'           => $existingcert->id,
                    'reissued_at'  => time(),
                    'notes'        => trim(($existingcert->notes ?? '') . "\n[Superseded by force-regenerate — Qual Cert Hub]"),
                    'timemodified' => time(),
                ]);
                if (!empty($existingcert->verifytoken)) {
                    local_rtocompliance_update_registry_status($existingcert->verifytoken, 'superseded');
                }
                $voided++;
            }

            // Earliest criterion completion timestamp across all linked courses.
            $initialTimecompleted = 0;
            if (!empty($alllinkedcourseids)) {
                list($ltcInsql, $ltcParams) = $DB->get_in_or_equal($alllinkedcourseids, SQL_PARAMS_NAMED, 'ltc');
                $ltcParams['ltcuid'] = $userid;
                $ltcTs = (int)$DB->get_field_sql(
                    "SELECT MIN(timecompleted) FROM {course_completion_crit_compl}
                      WHERE userid = :ltcuid AND course $ltcInsql
                        AND timecompleted IS NOT NULL AND timecompleted > 0",
                    $ltcParams
                );
                if ($ltcTs > 0) {
                    $initialTimecompleted = $ltcTs;
                } else {
                    list($ltcInsql2, $ltcParams2) = $DB->get_in_or_equal($alllinkedcourseids, SQL_PARAMS_NAMED, 'ltcf');
                    $ltcParams2['ltcfuid'] = $userid;
                    $initialTimecompleted  = (int)$DB->get_field_sql(
                        "SELECT MIN(timecompleted) FROM {course_completions}
                          WHERE userid = :ltcfuid AND course $ltcInsql2
                            AND timecompleted IS NOT NULL AND timecompleted > 0",
                        $ltcParams2
                    );
                }
            }

            $result = local_rtocompliance_programmatic_issue_cert(
                $userid,
                $certtype,
                $qual->qualificationcode,
                $qual->qualificationname,
                $unitsForCert,
                time(),
                'default',
                $sendemail,
                $initialTimecompleted
            );

            if ($result['ok']) {
                $issued++;
                $issuedThisStudent++;
                $messages[] = fullname($user) . ' — ' . $certtype . ' issued (' . $result['certnumber'] . ')';
                if ($forceregen && $existingcert && !empty($result['certid'])) {
                    $DB->update_record('local_rtocompliance_certs', (object)[
                        'id'             => $result['certid'],
                        'replacement_of' => $existingcert->id,
                        'notes'          => 'Force-regenerated via Qual Cert Hub',
                        'timemodified'   => time(),
                    ]);
                }
            } elseif ($result['error'] === 'INSUFFICIENT_CREDITS') {
                $messages[]         = fullname($user) . ' — SKIPPED: insufficient credits';
                $failed++;
                $failedThisStudent++;
                $lastErrThisStudent = 'insufficient credits';
                $creditsExhausted   = true;
                break; // break inner cert-type loop; outer loop checks flag
            } elseif (!empty($result['skipped']) || ($result['error'] ?? '') === 'NO_USI') {
                // v5.9.383: a Clause-12 USI skip is NOT a failure. Count it
                // separately, surface the reason, and (below) leave the autocert
                // row PENDING so it re-issues automatically once a USI is added.
                $usiskipped++;
                $usiskipThisStudent++;
                $messages[] = fullname($user) . ' — SKIPPED: '
                    . ($result['reason'] ?? 'no USI recorded (Clause 12 requires a USI before issuing)');
            } else {
                $err                = $result['error'] ?? 'unknown error';
                $messages[]         = fullname($user) . ' — FAILED: ' . $err;
                $failed++;
                $failedThisStudent++;
                $lastErrThisStudent = $err;
            }
        }

        // AUTOCERT-STATUS-FIX: set terminal autocert queue status based on
        // the actual per-student issuance outcome.
        //   issued > 0              → complete (at least one cert went out)
        //   failed > 0, no credits  → failed   (attempt made but nothing issued)
        //   creditsExhausted        → leave pending so retry is possible after top-up
        if ($studentrec) {
            $autocertrow = $DB->get_record('local_rtocompliance_autocerts', [
                'studentid'     => $studentrec->id,
                'qualbuilderid' => $qual->id,
                'status'        => 'pending',
            ]);
            if ($autocertrow) {
                if ($issuedThisStudent > 0) {
                    // At least one cert was issued — mark complete.
                    $DB->update_record('local_rtocompliance_autocerts', (object)[
                        'id'           => $autocertrow->id,
                        'status'       => 'complete',
                        'timemodified' => time(),
                        'certsissued'  => ($autocertrow->certsissued ?? 0) + $issuedThisStudent,
                    ]);
                } elseif (!$creditsExhausted && $failedThisStudent > 0) {
                    // Every cert attempt failed (non-credit reason) — mark failed
                    // so the queue shows the problem and an admin can investigate.
                    $DB->update_record('local_rtocompliance_autocerts', (object)[
                        'id'           => $autocertrow->id,
                        'status'       => 'failed',
                        'errormessage' => 'Issuance failed: ' . $lastErrThisStudent,
                        'timemodified' => time(),
                    ]);
                }
                // If $creditsExhausted: row stays 'pending' — retry after top-up.
            }
        }
    }

    return compact('issued', 'skipped', 'usiskipped', 'failed', 'voided', 'messages', 'creditsExhausted');
}

// ═══════════════════════════════════════════════════════════════════════════════
// ACTION HANDLERS  (all require sesskey)
// ═══════════════════════════════════════════════════════════════════════════════

// ── action=issue — bulk cert issuance for Tab 1 ───────────────────────────────
if ($action === 'issue' && confirm_sesskey() && $qualid > 0) {
    $qual       = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid], '*', MUST_EXIST);
    $userids    = optional_param_array('userids',         [], PARAM_INT);
    $activate   = optional_param_array('activate_userids', [], PARAM_INT);
    $forceregen = optional_param('forceregen', 0, PARAM_INT);
    // EMAIL-TOGGLE-FIX: an unchecked HTML checkbox posts nothing, so default
    // must be 0 (don't send). Defaulting to 1 here would ignore the admin's
    // choice and always send the email when the checkbox is left unticked.
    $sendemail  = optional_param('sendemail',  0, PARAM_INT);

    if (empty($userids)) {
        redirect(
            new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'ready']),
            'No students selected.',
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // NO-CORE-WRITES (v5.9.411): removed the auto-unsuspend of Moodle {user} accounts
    // on batch issuance — the plugin no longer writes the core {user}.suspended field.
    unset($activate);

    $det    = qch_build_detection_vars($qual);
    $result = qch_issue_batch($qual, $userids, $det, $sendemail, $forceregen);

    $modeLabel = $forceregen ? 'Force-regenerated' : 'Issued';
    $usinote   = !empty($result['usiskipped']) ? " Skipped (no USI): {$result['usiskipped']}." : '';
    $summary   = "{$modeLabel}: {$result['issued']} cert(s). Voided: {$result['voided']}. Skipped: {$result['skipped']}.{$usinote} Failed: {$result['failed']}.";
    if (!empty($result['messages'])) {
        $summary .= ' ' . implode('; ', array_slice($result['messages'], 0, 5));
        if (count($result['messages']) > 5) {
            $summary .= ' … and ' . (count($result['messages']) - 5) . ' more.';
        }
    }
    if ($result['creditsExhausted']) {
        $summary .= ' ⚠ Credits exhausted — please top up and retry remaining students.';
    }
    $ntype = $result['failed'] > 0
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS;
    redirect(
        new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'issued']),
        $summary, null, $ntype
    );
}

// ── action=issue_all_pending — one-click bulk issue from hub home row ─────────
if ($action === 'issue_all_pending' && confirm_sesskey() && $qualid > 0) {
    $qual      = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid], '*', MUST_EXIST);
    $sendemail = optional_param('sendemail', 1, PARAM_INT);
    $det       = qch_build_detection_vars($qual);
    $completers = qch_get_completers($qual, $det);

    // Build list of userids without an active testamur.
    if (!empty($completers)) {
        $completerIds = array_keys($completers);
        list($uidsql, $uidparams) = $DB->get_in_or_equal($completerIds, SQL_PARAMS_NAMED, 'cu');
        $issuedIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {local_rtocompliance_certs}
              WHERE qualificationcode = :qc AND certtype = 'testamur'
                AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)
                AND userid $uidsql",
            array_merge(['qc' => $qual->qualificationcode], $uidparams)
        );
        $pending = array_values(array_diff($completerIds, array_map('intval', $issuedIds)));
    } else {
        $pending = [];
    }

    $result  = qch_issue_batch($qual, $pending, $det, $sendemail, 0);
    $usinote2 = !empty($result['usiskipped']) ? " Skipped (no USI): {$result['usiskipped']}." : '';
    $summary = "Issued: {$result['issued']} cert(s). Skipped (already exists): {$result['skipped']}.{$usinote2} Failed: {$result['failed']}.";
    if ($result['creditsExhausted']) {
        $summary .= ' ⚠ Credits exhausted — some students not processed.';
    }
    $ntype = $result['failed'] > 0
        ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS;
    redirect(
        new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'issued']),
        $summary, null, $ntype
    );
}

// ── action=scan_missed — historical catch-up scan ────────────────────────────
if ($action === 'scan_missed' && confirm_sesskey()) {
    $scanQualid = optional_param('scan_qualid', 0, PARAM_INT);
    $quals      = [];
    if ($scanQualid > 0) {
        $q = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $scanQualid], '*', IGNORE_MISSING);
        if ($q) {
            $quals = [$q];
        }
    } else {
        $quals = $DB->get_records_sql(
            "SELECT * FROM {local_rtocompliance_qualbuilder}
              WHERE producttype = 'qualification' AND status = 'active'
              ORDER BY qualificationname ASC"
        );
    }

    $inserted            = 0;
    $dbman_scan          = $DB->get_manager();
    $studentsTableExists = $dbman_scan->table_exists('local_rtocompliance_students');

    foreach ($quals as $q) {
        $det        = qch_build_detection_vars($q);
        $completers = qch_get_completers($q, $det);
        if (empty($completers) || !$studentsTableExists) {
            continue;
        }

        // Batch fetch already-issued testamur holders.
        $completerIds = array_keys($completers);
        list($uidsql2, $uidparams2) = $DB->get_in_or_equal($completerIds, SQL_PARAMS_NAMED, 'scu');
        $certedIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {local_rtocompliance_certs}
              WHERE qualificationcode = :qc2 AND certtype = 'testamur'
                AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)
                AND userid $uidsql2",
            array_merge(['qc2' => $q->qualificationcode], $uidparams2)
        );
        $certedIds = array_map('intval', $certedIds);

        foreach ($completers as $uid => $comp) {
            if (in_array((int)$uid, $certedIds)) {
                continue; // Already has a cert.
            }
            $srec = $DB->get_record('local_rtocompliance_students', ['userid' => $uid], 'id', IGNORE_MISSING);
            if (!$srec) {
                continue;
            }
            $hasQueue = $DB->record_exists('local_rtocompliance_autocerts', [
                'studentid'     => $srec->id,
                'qualbuilderid' => $q->id,
                'status'        => 'pending',
            ]);
            if (!$hasQueue) {
                $row                = new stdClass();
                $row->studentid     = $srec->id;
                $row->qualbuilderid = $q->id;
                $row->certtypes     = 'testamur,record';
                $row->creditcost    = 2;
                $row->status        = 'pending';
                $row->certsissued   = 0;
                $row->timecreated   = time();
                $row->timemodified  = time();
                $DB->insert_record('local_rtocompliance_autocerts', $row);
                $inserted++;
            }
        }
    }

    $plural  = $inserted === 1 ? 'student' : 'students';
    $summary = $inserted > 0
        ? "Scan complete — found {$inserted} more finished {$plural} waiting for a certificate. They now appear as Pending."
        : "Scan complete — no missed completions found. Everyone finished has already been issued or is already pending.";
    $redir   = $scanQualid > 0
        ? new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $scanQualid, 'tab' => 'queue'])
        : new moodle_url('/local/rtocompliance/qual_cert_hub.php');
    redirect($redir, $summary, null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── action=retry_autocert — reset one failed autocert row to pending ──────────
if ($action === 'retry_autocert' && confirm_sesskey() && $qualid > 0) {
    $rowid = required_param('rowid', PARAM_INT);
    $row   = $DB->get_record('local_rtocompliance_autocerts', ['id' => $rowid, 'qualbuilderid' => $qualid], '*', IGNORE_MISSING);
    if ($row && $row->status === 'failed') {
        $DB->update_record('local_rtocompliance_autocerts', (object)[
            'id'           => $row->id,
            'status'       => 'pending',
            'errormessage' => trim(($row->errormessage ?? '') . "\n[Retried by admin on " . userdate(time()) . ']'),
            'timemodified' => time(),
        ]);
        $msg   = 'Autocert entry reset to pending — it will be processed on the next queue run.';
        $ntype = \core\output\notification::NOTIFY_SUCCESS;
    } else {
        $msg   = 'Entry not found or is not in failed status.';
        $ntype = \core\output\notification::NOTIFY_WARNING;
    }
    redirect(
        new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'queue']),
        $msg, null, $ntype
    );
}

// ── action=retry_all_failed — reset all failed autocert rows to pending ───────
if ($action === 'retry_all_failed' && confirm_sesskey() && $qualid > 0) {
    $failedRows = $DB->get_records('local_rtocompliance_autocerts', [
        'qualbuilderid' => $qualid,
        'status'        => 'failed',
    ]);
    $resetCount = 0;
    $ts         = userdate(time());
    foreach ($failedRows as $row) {
        $DB->update_record('local_rtocompliance_autocerts', (object)[
            'id'           => $row->id,
            'status'       => 'pending',
            'errormessage' => trim(($row->errormessage ?? '') . "\n[Bulk-retried by admin on " . $ts . ']'),
            'timemodified' => time(),
        ]);
        $resetCount++;
    }
    $plural = $resetCount === 1 ? 'entry' : 'entries';
    redirect(
        new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'queue']),
        "{$resetCount} failed {$plural} reset to pending and queued for reprocessing.",
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── action=refresh_stats — invalidate hub home stats cache ───────────────────
if ($action === 'refresh_stats' && confirm_sesskey()) {
    $refresh_qualid = optional_param('refresh_qualid', 0, PARAM_INT);
    if ($refresh_qualid > 0) {
        unset_config('hub_stats_' . $refresh_qualid, 'local_rtocompliance');
        redirect(
            new moodle_url('/local/rtocompliance/qual_cert_hub.php'),
            'Numbers updated for this qualification.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        // Refresh all: scan plugin config for hub_stats_* keys and delete them.
        $allconfig = get_config('local_rtocompliance');
        foreach ((array)$allconfig as $key => $val) {
            if (strpos($key, 'hub_stats_') === 0) {
                unset_config($key, 'local_rtocompliance');
            }
        }
        redirect(
            new moodle_url('/local/rtocompliance/qual_cert_hub.php'),
            'Numbers updated for all qualifications.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// ── action=process_queue — process pending autocerts for one qual ─────────────
if ($action === 'process_queue' && confirm_sesskey() && $qualid > 0) {
    $qual      = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid], '*', MUST_EXIST);
    $sendemail = optional_param('sendemail', 1, PARAM_INT);
    $det       = qch_build_detection_vars($qual);

    $pendingRows = $DB->get_records_sql(
        "SELECT ac.*, s.userid
           FROM {local_rtocompliance_autocerts} ac
           JOIN {local_rtocompliance_students} s ON s.id = ac.studentid
          WHERE ac.qualbuilderid = :qbid AND ac.status = 'pending'
          ORDER BY ac.timecreated ASC",
        ['qbid' => $qualid]
    );

    $processed   = 0;
    $notComplete = 0;
    foreach ($pendingRows as $row) {
        // Verify the student has actually completed the qualification before issuing.
        if (!local_rtocompliance_check_full_qual_completion($qual->id, (int)$row->userid)) {
            $notComplete++;
            continue;
        }
        $result = qch_issue_batch($qual, [(int)$row->userid], $det, $sendemail, 0);
        if ($result['issued'] > 0) {
            $processed++;
        }
        if ($result['creditsExhausted']) {
            break;
        }
    }

    $summary = "Queue processed — {$processed} student(s) received certificates; {$notComplete} not yet fully complete.";
    redirect(
        new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'queue']),
        $summary, null, \core\output\notification::NOTIFY_SUCCESS
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE SETUP
// ═══════════════════════════════════════════════════════════════════════════════
$pagetitle = 'Qualification Certificate Hub';
$pageurl   = $qualid
    ? new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid])
    : new moodle_url('/local/rtocompliance/qual_cert_hub.php');
$PAGE->set_url($pageurl);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);
$PAGE->requires->css('/local/rtocompliance/styles.css');
$PAGE->add_body_class('path-local-rtocompliance');

echo $OUTPUT->header();
echo local_rtocompliance_render_nav_header(
    $pagetitle,
    get_string('certificates', 'local_rtocompliance'),
    new moodle_url('/local/rtocompliance/certificates.php'),
    'certificates'
);
echo local_rtocompliance_page_banner($pagetitle);

// ═══════════════════════════════════════════════════════════════════════════════
// HUB HOME — qualification list with funnel stats
// ═══════════════════════════════════════════════════════════════════════════════
if (!$qualid) {

    $dbman_home = $DB->get_manager();

    // Global autocert pending banner.
    if ($dbman_home->table_exists('local_rtocompliance_autocerts')) {
        $totalPending = (int)$DB->count_records('local_rtocompliance_autocerts', ['status' => 'pending']);
        if ($totalPending > 0) {
            echo '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">';
            echo '<span style="font-size:1.4rem;">⏳</span>';
            echo '<div><strong style="color:#92400e;">' . $totalPending . ' student' . ($totalPending === 1 ? '' : 's') . ' finished and waiting for a certificate.</strong>';
            echo ' <span style="color:#78350f;font-size:0.88rem;">Click <strong>Detail</strong> (or <strong>Issue Pending</strong>) on a qualification below to issue theirs.</span></div>';
            echo '</div>';
        }
    }

    // Crosslink to classic generator.
    $gcqurl = (new moodle_url('/local/rtocompliance/generate_qual_certs.php'))->out(false);
    echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
    echo '<span style="color:#374151;font-size:0.88rem;">Prefer the step-by-step issuance wizard?</span>';
    echo '<a href="' . s($gcqurl) . '" class="btn btn-outline-secondary btn-sm">Classic Generator &rarr;</a>';
    echo '</div>';

    // "Refresh All Stats" URL — clears the full cache.
    $refreshAllUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
        'action'         => 'refresh_stats',
        'refresh_qualid' => 0,
        'sesskey'        => sesskey(),
    ]))->out(false);

    echo '<div class="certificates-container">';
    echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:6px;">';
    echo '<h2 style="margin:0;">Qualification Certificate Hub</h2>';
    echo '<a href="' . s($refreshAllUrl) . '" class="btn btn-outline-secondary btn-sm"'
        . ' title="Update the numbers for every qualification now (use after recording new results or completions)">'
        . '↺ Refresh All Stats</a>';
    echo '</div>';
    echo '<p style="color:#6b7280;margin-bottom:14px;">Issue <strong>full qualification certificates (Testamurs)</strong> to students who have finished every unit — one row per active qualification. For a Statement of Attainment covering only some units, use <strong>Issue Multi-Unit SOA</strong>.</p>';

    // DECLUTTER (v5.9.417): removed the multi-paragraph "What is this / How to use it"
    // tutorial card (incl. the cache-jargon paragraph). The one-line intro above plus
    // the self-explanatory funnel columns (Enrolled / Completed / Issued / Pending) and
    // row actions convey the same thing without a lecture on every visit; the full
    // walkthrough lives in the Support centre.

    // v5.9.383: landing search — find a qualification by code/name and filter by
    // category, instead of scrolling the whole list.
    $fq   = optional_param('fq', '', PARAM_RAW_TRIMMED); // pipeline-ignore: PARAM_RAW — free-text payload; sanitised/validated immediately after read, never echoed raw.
    $fcat = optional_param('fcat', 0, PARAM_INT);
    // v6.2.84 CASCADE: parent category -> sub-category -> course, sourced from each
    // qualification's ACTUAL result courses (enrolments.programcode -> courseid -> category),
    // because qualbuilder.categoryid is populated on only a handful of products.
    $rparent = optional_param('rparent', 0, PARAM_INT);
    $rsub    = optional_param('rsub', 0, PARAM_INT);
    $rcourse = optional_param('rcourse', 0, PARAM_INT);
    $hubwhere  = "producttype = 'qualification' AND status = 'active'";
    $hubparams = [];
    if ($fq !== '') {
        $hubwhere .= " AND (" . $DB->sql_like('qualificationcode', ':fq1', false, false)
                  . " OR " . $DB->sql_like('qualificationname', ':fq2', false, false) . ")";
        $hubparams['fq1'] = '%' . $DB->sql_like_escape($fq) . '%';
        $hubparams['fq2'] = '%' . $DB->sql_like_escape($fq) . '%';
    }
    if ($fcat > 0) {
        $hubwhere .= " AND categoryid = :fcat";
        $hubparams['fcat'] = $fcat;
    }
    $quals = $DB->get_records_sql(
        "SELECT * FROM {local_rtocompliance_qualbuilder}
          WHERE $hubwhere
          ORDER BY qualificationname ASC",
        $hubparams
    );

    // v6.2.84 CASCADE DATA — the category tree + course list built from the courses students
    // actually hold results in, plus a map of qualification code -> its result-course ids, so
    // the picker can narrow the qualification list to those delivered in a chosen category/course.
    $resCourses = [];   // courseid => ['name'=>.., 'catpath'=>.., 'catid'=>..]
    $resSubs    = [];   // subid    => ['name'=>.., 'parent'=>..]
    $resParents = [];   // parentid => name
    if ($DB->get_manager()->table_exists('course_categories')) {
        foreach ($DB->get_records_sql(
            "SELECT c.id, c.fullname, cc.id AS catid, cc.name AS catname, cc.parent AS catparent, cc.path AS catpath
               FROM {course} c
               JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id IN (SELECT DISTINCT e.courseid FROM {local_rtocompliance_enrolments} e
                              WHERE e.courseid IS NOT NULL AND e.courseid > 0)
              ORDER BY cc.path, c.fullname") as $co) {
            $catid = (int)$co->catid;
            $resCourses[(int)$co->id] = ['name' => (string)$co->fullname, 'catpath' => (string)$co->catpath, 'catid' => $catid];
            $resSubs[$catid] = ['name' => (string)$co->catname, 'parent' => (int)$co->catparent];
        }
        $resParentIds = [];
        foreach ($resSubs as $sm) { if ((int)$sm['parent'] > 0) { $resParentIds[(int)$sm['parent']] = true; } }
        if (!empty($resParentIds)) {
            list($pin_a, $pin_p) = $DB->get_in_or_equal(array_keys($resParentIds), SQL_PARAMS_NAMED, 'rpp');
            foreach ($DB->get_records_select('course_categories', "id $pin_a", $pin_p, '', 'id, name') as $pc) {
                $resParents[(int)$pc->id] = (string)$pc->name;
            }
        }
    }
    // qualification code (upper) => set of result-course ids it is delivered in.
    $qualCourseIds = [];
    foreach ($DB->get_records_sql(
        "SELECT " . $DB->sql_concat('UPPER(programcode)', "'|'", 'courseid') . " AS k,
                UPPER(programcode) AS pc, courseid
           FROM {local_rtocompliance_enrolments}
          WHERE courseid IS NOT NULL AND courseid > 0 AND programcode IS NOT NULL AND programcode <> ''
       GROUP BY UPPER(programcode), courseid") as $r) {
        $qualCourseIds[(string)$r->pc][(int)$r->courseid] = true;
    }
    // Resolve the chosen parent/sub/course to a concrete set of course ids (most specific first).
    $cascadeActive = ($rcourse > 0 || $rsub > 0 || $rparent > 0);
    $matchCourseIds = [];
    if ($rcourse > 0) {
        if (isset($resCourses[$rcourse])) { $matchCourseIds[$rcourse] = true; }
    } else if ($rsub > 0) {
        foreach ($resCourses as $cid => $cm) {
            if ($cm['catid'] === $rsub || strpos('/' . trim($cm['catpath'], '/') . '/', '/' . $rsub . '/') !== false) {
                $matchCourseIds[$cid] = true;
            }
        }
    } else if ($rparent > 0) {
        foreach ($resCourses as $cid => $cm) {
            if (strpos('/' . trim($cm['catpath'], '/') . '/', '/' . $rparent . '/') !== false) {
                $matchCourseIds[$cid] = true;
            }
        }
    }
    // Narrow the qualification list to those with a result course in the chosen scope.
    if ($cascadeActive) {
        $quals = array_filter($quals, function ($q) use ($qualCourseIds, $matchCourseIds) {
            if (empty($matchCourseIds)) { return false; }
            $set = $qualCourseIds[strtoupper((string)$q->qualificationcode)] ?? [];
            foreach ($set as $cid => $_) { if (isset($matchCourseIds[$cid])) { return true; } }
            return false;
        });
    }

    // Filter bar.
    $hubCats = [];
    if ($DB->get_manager()->table_exists('course_categories')) {
        $hubCats = $DB->get_records_sql(
            "SELECT DISTINCT cc.id, cc.name
               FROM {local_rtocompliance_qualbuilder} qb
               JOIN {course_categories} cc ON cc.id = qb.categoryid
              WHERE qb.producttype = 'qualification' AND qb.status = 'active' AND qb.categoryid > 0
           ORDER BY cc.name ASC");
    }
    echo '<form method="get" action="' . (new moodle_url('/local/rtocompliance/qual_cert_hub.php'))->out(false)
        . '" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;">';
    echo '<input type="text" name="fq" value="' . s($fq) . '" placeholder="Search qualification code or name…" class="form-control" style="max-width:320px;">';
    if (!empty($resCourses)) {
        // Parent category.
        echo '<select id="rtoc-hub-parent" name="rparent" class="form-control" onchange="rtocHubCascade(this,\'parent\')" style="max-width:200px;">';
        echo '<option value="0">All categories</option>';
        foreach ($resParents as $pid => $pname) {
            echo '<option value="' . (int)$pid . '"' . ($rparent == $pid ? ' selected' : '') . '>'
                . s(shorten_text($pname, 30)) . '</option>';
        }
        echo '</select>';
        // Sub-category.
        echo '<select id="rtoc-hub-sub" name="rsub" class="form-control" onchange="rtocHubCascade(this,\'sub\')" style="max-width:200px;">';
        echo '<option value="0" data-parent="0">All sub-categories</option>';
        foreach ($resSubs as $sid => $sm) {
            echo '<option value="' . (int)$sid . '" data-parent="' . (int)$sm['parent'] . '"'
                . ($rsub == $sid ? ' selected' : '') . '>' . s(shorten_text($sm['name'], 30)) . '</option>';
        }
        echo '</select>';
        // Course.
        echo '<select id="rtoc-hub-course" name="rcourse" class="form-control" onchange="rtocHubCascade(this,\'course\')" style="max-width:220px;">';
        echo '<option value="0" data-sub="0" data-catpath="">All courses</option>';
        foreach ($resCourses as $coid => $cm) {
            echo '<option value="' . (int)$coid . '" data-sub="' . (int)$cm['catid'] . '"'
                . ' data-catpath="' . s($cm['catpath']) . '"'
                . ($rcourse == $coid ? ' selected' : '') . '>' . s(shorten_text($cm['name'], 40)) . '</option>';
        }
        echo '</select>';
    } else if (!empty($hubCats)) {
        echo '<select name="fcat" class="form-control" style="max-width:230px;">';
        echo '<option value="0">All categories</option>';
        foreach ($hubCats as $hc) {
            echo '<option value="' . (int)$hc->id . '"' . ($fcat == $hc->id ? ' selected' : '') . '>'
                . s(shorten_text($hc->name, 36)) . '</option>';
        }
        echo '</select>';
    }
    echo '<button type="submit" class="btn btn-primary">Search</button>';
    if ($fq !== '' || $fcat > 0 || $cascadeActive) {
        echo ' <a href="' . (new moodle_url('/local/rtocompliance/qual_cert_hub.php'))->out(false)
            . '" class="btn btn-outline-secondary">Clear</a>';
        echo ' <span style="align-self:center;color:#6b7280;font-size:0.85rem;">' . count($quals) . ' match(es)</span>';
    }
    echo '</form>';
    // v6.2.84 cascade behaviour (mirrors Student Results): keep sub/course showing only the
    // options under the current upstream selection, reset downstream on change, then submit.
    echo <<<'HUBCASCADE'
<script>
(function () {
  function opts(sel){ return Array.prototype.slice.call(sel ? sel.options : []); }
  function applyVisibility(){
    var p = document.getElementById('rtoc-hub-parent');
    var sub = document.getElementById('rtoc-hub-sub');
    var crs = document.getElementById('rtoc-hub-course');
    if (!sub || !crs) return;
    var pv = p ? p.value : '0';
    opts(sub).forEach(function (o){
      var par = o.getAttribute('data-parent') || '0';
      var show = (o.value === '0') || (pv === '0') || (par === pv);
      o.hidden = !show; o.disabled = !show;
    });
    if (sub.selectedOptions.length && sub.selectedOptions[0].hidden) { sub.value = '0'; }
    var sv = sub.value;
    var subParent = {};
    opts(sub).forEach(function (o){ subParent[o.value] = o.getAttribute('data-parent') || '0'; });
    opts(crs).forEach(function (o){
      var cs = o.getAttribute('data-sub') || '0';
      var show;
      if (o.value === '0') { show = true; }
      else if (sv !== '0') { show = (cs === sv); }
      else if (pv !== '0') { show = (subParent[cs] === pv); }
      else { show = true; }
      o.hidden = !show; o.disabled = !show;
    });
    if (crs.selectedOptions.length && crs.selectedOptions[0].hidden) { crs.value = '0'; }
  }
  window.rtocHubCascade = function (el, level){
    if (level === 'parent'){ var s=document.getElementById('rtoc-hub-sub'); var c=document.getElementById('rtoc-hub-course'); if(s)s.value='0'; if(c)c.value='0'; }
    if (level === 'sub'){ var c2=document.getElementById('rtoc-hub-course'); if(c2)c2.value='0'; }
    applyVisibility();
    if (el && el.form) { el.form.submit(); }
  };
  if (document.readyState !== 'loading') { applyVisibility(); }
  else { document.addEventListener('DOMContentLoaded', applyVisibility); }
})();
</script>
HUBCASCADE;

    if (empty($quals)) {
        echo '<div class="no-deadlines"><p>No active qualifications found in the Qualification Builder.</p></div>';
    } else {
        // Explainer card — what each column means, so the funnel (and especially "Queue") is clear.
        echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:16px;">';
        echo '<div style="font-weight:700;color:#1e3a8a;margin-bottom:8px;font-size:15px;">What each column means</div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 22px;font-size:14.5px;color:#334155;line-height:1.5;">'
            . '<div><strong>Qualification</strong> — the national code &amp; title.</div>'
            . '<div><strong>Variant</strong> — the specific version/intake (e.g. a semester like 2026 S1, or an archived year). Same code + different variant = separate row.</div>'
            . '<div><strong>Enrolled</strong> — students currently enrolled in this product\'s courses.</div>'
            . '<div><strong>Complete</strong> — students who have passed every unit.</div>'
            . '<div><strong>Issued</strong> — students who already have their certificate.</div>'
            . '<div><strong>Pending</strong> — completed students who do <em>not</em> have a certificate yet. Click <strong>Issue Pending</strong> to generate them (5 credits each).</div>'
            . '<div><strong>Queue</strong> — students waiting for <em>automatic</em> certificate issue. These are picked up by the autocert process (or click <strong>Process Queue</strong> on the Detail page); a dash means nothing is queued.</div>'
            . '</div>';
        echo '<div style="margin-top:8px;font-size:12px;color:#64748b;">Every certificate — pending or automatic — costs 5 credits (&#8776; A$0.50); nothing is issued for free.</div>';
        echo '</div>';

        // FULL-WIDTH-TABLE (v6.2.37): use the shared .rtoc-table-wrapper so this table fills
        // the width and wraps (no forced min-width, no unnecessary horizontal scrollbar).
        echo '<div class="rtoc-table-wrapper">';
        echo '<table class="generaltable">';
        echo '<thead><tr>';
        echo '<th title="The national qualification code and title">Qualification</th>';
        echo '<th title="The specific version / intake of this qualification — e.g. a semester (2026 S1) or an archived year — from the product’s stream / variant name. Qualifications with the same code but different variants are separate rows.">Variant</th>';
        echo '<th style="text-align:center;" title="Students currently enrolled in this qualification’s courses">Enrolled</th>';
        echo '<th style="text-align:center;" title="Students who have passed every unit in this qualification">Complete</th>';
        echo '<th style="text-align:center;" title="Students who already have their qualification certificate (Testamur)">Issued</th>';
        echo '<th style="text-align:center;" title="Students who have finished but do not have a certificate yet — click Issue Pending to generate them">Pending</th>';
        echo '<th style="text-align:center;" title="Students lined up for automatic certificate issue">Queue</th>';
        echo '<th style="text-align:center;">Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ($quals as $q) {

            // ── Cached stats (complete, pending, issued, enrolled). ──────────
            $stats         = qch_get_hub_stats($q, $dbman_home);
            $enrolledCount = (int)$stats['enrolled'];
            $completeCount = (int)$stats['complete'];
            $issuedCount   = (int)$stats['issued'];
            $pendingCount  = (int)$stats['pending'];

            // Cache-age label shown below the counts.
            $cacheAgeHtml = '';
            if ($stats['from_cache']) {
                $ageMin = max(0, (int)round((time() - (int)$stats['ts']) / 60));
                $cacheAgeHtml = '<br><span style="color:#9ca3af;font-size:0.75rem;">'
                    . 'cached ' . ($ageMin === 0 ? 'just now' : $ageMin . ' min ago')
                    . '</span>';
            }

            // Autocert queue (always live — a single cheap count).
            $queueCount = (int)$DB->count_records('local_rtocompliance_autocerts', [
                'qualbuilderid' => $q->id,
                'status'        => 'pending',
            ]);

            $detailurl   = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $q->id]))->out(false);
            $issueAllUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid'  => $q->id,
                'action'  => 'issue_all_pending',
                'sesskey' => sesskey(),
            ]))->out(false);
            $refreshRowUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'action'         => 'refresh_stats',
                'refresh_qualid' => $q->id,
                'sesskey'        => sesskey(),
            ]))->out(false);

            echo '<tr>';
            echo '<td><strong>' . s($q->qualificationcode) . '</strong><br>'
                . '<span style="color:#6b7280;font-size:0.85rem;">' . s(format_string($q->qualificationname)) . '</span></td>';
            $variant = trim((string) ($q->streamname ?? ''));
            echo '<td>' . ($variant !== ''
                ? '<span style="display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #e0e7ff;border-radius:6px;padding:2px 9px;font-size:0.82rem;font-weight:600;" title="Variant / intake: ' . s($variant) . '">' . s($variant) . '</span>'
                : '<span style="color:#9ca3af;" title="This product has no variant/stream set — it is the base qualification">—</span>') . '</td>';
            echo '<td style="text-align:center;">' . ($enrolledCount ?: '—') . $cacheAgeHtml . '</td>';
            echo '<td style="text-align:center;font-weight:600;">' . $completeCount . $cacheAgeHtml . '</td>';
            echo '<td style="text-align:center;color:#16a34a;font-weight:600;">' . $issuedCount . $cacheAgeHtml . '</td>';

            $pendingStyle = $pendingCount > 0 ? 'color:#dc2626;font-weight:700;' : '';
            echo '<td style="text-align:center;' . $pendingStyle . '">' . $pendingCount . $cacheAgeHtml . '</td>';

            $queueStyle = $queueCount > 0 ? 'color:#d97706;font-weight:600;' : '';
            echo '<td style="text-align:center;' . $queueStyle . '">' . ($queueCount ?: '—') . '</td>';

            echo '<td style="text-align:center;white-space:nowrap;">';
            echo '<a href="' . s($detailurl) . '" class="btn btn-primary btn-sm" style="margin-right:4px;">Detail</a>';
            if ($pendingCount > 0) {
                echo '<a href="' . s($issueAllUrl) . '" class="btn btn-success btn-sm" style="margin-right:4px;"'
                    . ' onclick="return confirm(\'Issue certificates for all ' . (int)$pendingCount . ' pending student(s) of ' . s(addslashes($q->qualificationcode)) . '?\\n\\nEach certificate costs 5 credits (about A$0.50). This will charge up to ' . ((int)$pendingCount * 10) . ' credits (about A$' . number_format((int)$pendingCount * 1.0, 2) . ') in total.\\n\\nContinue?\');"'
                    . '>Issue Pending</a>';
            }
            echo '<a href="' . s($refreshRowUrl) . '" class="btn btn-outline-secondary btn-sm"'
                . ' title="Recompute stats for this qualification now">↺</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Global catch-up scan.
        $scanurl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
            'action'  => 'scan_missed',
            'sesskey' => sesskey(),
        ]))->out(false);
        echo '<div style="margin-top:20px;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">';
        echo '<div><strong>Find students who finished but were missed</strong><br>'
            . '<span style="color:#6b7280;font-size:0.85rem;">Re-checks every active qualification and flags any student who has completed all units but has not been issued a certificate yet, so you can issue theirs.</span></div>';
        echo '<a href="' . s($scanurl) . '" class="btn btn-outline-secondary btn-sm"'
            . ' onclick="return confirm(\'Scan ALL active qualifications? This may take up to 30 seconds.\');"'
            . '>Run Global Scan &rarr;</a>';
        echo '</div>';
    }

    echo '</div>'; // .certificates-container
    echo $OUTPUT->footer();
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// QUAL DETAIL — four tabs for one qualification
// ═══════════════════════════════════════════════════════════════════════════════
$qual = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $qualid], '*', MUST_EXIST);

// Build detection vars and completers once (reused across all tabs).
$det           = qch_build_detection_vars($qual);
$allcompleters = qch_get_completers($qual, $det);
$qualcode      = $qual->qualificationcode;
$qualname      = format_string($qual->qualificationname);

// Breadcrumb.
$hublink = (new moodle_url('/local/rtocompliance/qual_cert_hub.php'))->out(false);
echo '<div style="margin-bottom:14px;">';
echo '<a href="' . s($hublink) . '" style="color:#6b7280;font-size:0.88rem;">&larr; All Qualifications</a>';
echo '</div>';

// Qual header banner.
echo '<div style="background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 20px;margin-bottom:18px;">';
echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
echo '<span style="font-size:0.7rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#2563eb;background:#dbeafe;padding:2px 8px;border-radius:4px;">Qualification</span>';
echo '<span style="font-size:1rem;font-weight:700;color:#1e3a5f;">' . s($qualcode) . ' — ' . s($qualname) . '</span>';
echo '</div>';
echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
echo '<span title="How many units of this qualification have a Moodle delivery course linked. Completions in those courses count towards the certificate." style="background:#dbeafe;color:#1d4ed8;padding:3px 10px;border-radius:6px;font-size:0.82rem;font-weight:600;">' . $det['effectiveLinkedUnitCount'] . ' linked unit-course(s)</span>';
echo '<span title="Learners who have finished every unit and are ready to be issued a certificate." style="background:#dcfce7;color:#16a34a;padding:3px 10px;border-radius:6px;font-size:0.82rem;font-weight:600;">' . count($allcompleters) . ' completers found</span>';
echo '<span title="How many units in this qualification have a national unit code on record." style="background:#fef9c3;color:#92400e;padding:3px 10px;border-radius:6px;font-size:0.82rem;font-weight:600;">' . $det['numunits'] . ' unit(s) with code</span>';
echo '</div>';
echo '</div>';

// Crosslink to classic generator.
$gcqDetailUrl = (new moodle_url('/local/rtocompliance/generate_qual_certs.php', ['qualid' => $qualid]))->out(false);
echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
echo '<span style="font-size:0.85rem;color:#374151;">💡 <strong>Classic Generator</strong> for step-by-step issuance with individual student selection.</span>';
echo '<a href="' . s($gcqDetailUrl) . '" class="btn btn-outline-secondary btn-sm">Classic Generator &rarr;</a>';
echo '</div>';

// Tab navigation.
$tabs_def  = [
    'ready'   => '✅ Ready to Issue',
    'issued'  => '📄 Certs Issued',
    'partial' => '🔄 Partially Complete',
    'queue'   => '⏳ Autocert Queue',
];
$activeTab = array_key_exists($tab, $tabs_def) ? $tab : 'ready';

echo '<div style="display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:20px;flex-wrap:wrap;">';
foreach ($tabs_def as $tkey => $tlabel) {
    $isActive = $tkey === $activeTab;
    $turl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => $tkey]))->out(false);
    $style = $isActive
        ? 'style="border-radius:6px 6px 0 0;margin-bottom:-2px;border-bottom:2px solid #2563eb;"'
        : 'style="border-radius:6px 6px 0 0;margin-bottom:-2px;border-bottom:2px solid transparent;"';
    $cls = $isActive ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
    echo '<a href="' . s($turl) . '" class="' . $cls . '" ' . $style . '>' . $tlabel . '</a>';
}
echo '</div>';

// v5.9.383: instant client-side filter across the active tab's rows — works on
// every tab (not just Certs Issued), no page reload. Filters on whatever the row
// shows (name, email, cert number, and USI where displayed).
echo '<div style="margin-bottom:14px;">'
    . '<input type="text" id="rtoc-hub-rowfilter" placeholder="Filter this tab — name, email, USI or cert number…" '
    . 'style="width:100%;max-width:440px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;">'
    . '<span id="rtoc-hub-rowfilter-count" style="margin-left:10px;color:#6b7280;font-size:0.82rem;"></span></div>';
echo '<script>document.addEventListener("DOMContentLoaded",function (){'
    . 'var f=document.getElementById("rtoc-hub-rowfilter");'
    . 'var cnt=document.getElementById("rtoc-hub-rowfilter-count");'
    . 'var c=document.querySelector(".certificates-container");'
    . 'if(!f||!c)return;'
    . 'f.addEventListener("input",function (){'
    . 'var q=f.value.toLowerCase().trim();var shown=0,total=0;'
    . 'c.querySelectorAll("tbody tr").forEach(function (tr){total++;'
    . 'var m=(!q||tr.textContent.toLowerCase().indexOf(q)>-1);'
    . 'tr.style.display=m?"":"none";if(m)shown++;});'
    . 'cnt.textContent=q?(shown+" of "+total+" shown"):"";});'
    . '});</script>';

echo '<div class="certificates-container">';

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 1 — Ready to Issue
// ═══════════════════════════════════════════════════════════════════════════════
if ($activeTab === 'ready') {

    if (empty($allcompleters)) {
        echo '<div class="no-deadlines"><p>No students have completed all units of this qualification yet.</p>';
        $hint = $det['effectiveLinkedUnitCount'] > 0
            ? 'A student appears here once Moodle marks them complete in all linked unit-courses (' . $det['effectiveLinkedUnitCount'] . ' required), OR once the plugin records a competent outcome (Competent/RPL/Credit Transfer) for every unit.'
            : 'A student appears here once the plugin records a competent outcome for every selected unit.';
        echo '<p style="color:#6b7280;font-size:0.87rem;">' . $hint . '</p></div>';
    } else {
        // Separate pending (no cert) from already-issued — batched query.
        $cIds = array_keys($allcompleters);
        list($batchSql, $batchParams) = $DB->get_in_or_equal($cIds, SQL_PARAMS_NAMED, 'bcu');
        $certedUserIds = array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {local_rtocompliance_certs}
              WHERE qualificationcode = :bqc AND certtype = 'testamur'
                AND status = 'issued' AND (reissued_at IS NULL OR reissued_at = 0)
                AND userid $batchSql",
            array_merge(['bqc' => $qualcode], $batchParams)
        ));

        $pendingStudents = [];
        $alreadyIssued   = [];
        foreach ($allcompleters as $uid => $comp) {
            if (in_array((int)$uid, $certedUserIds)) {
                $alreadyIssued[$uid] = $comp;
            } else {
                $pendingStudents[$uid] = $comp;
            }
        }

        if (!empty($pendingStudents)) {
            echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:0.88rem;color:#166534;">';
            echo '<strong>' . count($pendingStudents) . ' student(s)</strong> have completed this qualification but have no active Testamur yet.';
            echo '</div>';

            $issuanceUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid'  => $qualid,
                'action'  => 'issue',
                'sesskey' => sesskey(),
            ]))->out(false);

            echo '<form method="post" action="' . s($issuanceUrl) . '">';

            // Controls bar.
            echo '<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:14px;padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">';
            echo '<label style="display:flex;align-items:center;gap:7px;font-size:0.88rem;cursor:pointer;">';
            echo '<input type="checkbox" id="hub-select-all" checked> <strong>Select / deselect all</strong>';
            echo '</label>';
            echo '<label style="display:flex;align-items:center;gap:7px;font-size:0.88rem;cursor:pointer;">';
            echo '<input type="checkbox" name="sendemail" value="1" checked> Email certificate to student';
            echo '</label>';
            echo '<label style="display:flex;align-items:center;gap:7px;font-size:0.88rem;cursor:pointer;" title="Voids any existing cert and issues a new one">';
            echo '<input type="checkbox" name="forceregen" value="1"> Force-regenerate (void &amp; reissue)';
            echo '</label>';
            echo '<button type="submit" class="btn btn-success"'
                . ' onclick="var n=document.querySelectorAll(\'.hub-student-cb:checked\').length;'
                . 'if(!n){alert(\'Select at least one student first.\');return false;}'
                . 'var cr=n*10;'
                . 'return confirm(\'Issue certificates for \'+n+\' selected student(s)?\\n\\nEach certificate costs 5 credits (about A$0.50); a full qualification issues a Testamur + Record of Results (2 certificates) per student.\\n\\nThis will charge up to \'+cr+\' credits (about A$\'+(cr*0.10).toFixed(2)+\') in total.\\n\\nContinue?\');"'
                . '>Issue Selected Certificates</button>';
            echo '<span style="font-size:12px;color:#64748b;margin-left:2px;">5 credits (&#8776; A$0.50) per certificate</span>';
            echo '</div>';

            echo '<table class="generaltable">';
            echo '<thead><tr>';
            echo '<th style="width:36px;"></th>';
            echo '<th>Student</th>';
            echo '<th>Email</th>';
            echo '<th>Completion Date</th>';
            echo '<th>Account</th>';
            echo '</tr></thead><tbody>';

            foreach ($pendingStudents as $uid => $comp) {
                $isSuspended = !empty($comp->suspended);
                $badge       = $isSuspended
                    ? ' <span title="This learner Moodle account is suspended. Reactivate it in Moodle user admin before issuing a certificate." style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:4px;font-size:0.75rem;font-weight:600;">SUSPENDED</span>'
                    : '';
                // NO-CORE-WRITES (v5.9.411): read-only account status; the plugin no
                // longer unsuspends Moodle accounts (activate in Moodle user admin).
                $activateBox = $isSuspended
                    ? '<span title="This learner Moodle account is suspended. Reactivate it in Moodle before issuing." style="font-size:0.8rem;color:#9ca3af;">Suspended</span>'
                    : '<span title="This learner Moodle account is active and can be issued a certificate." style="font-size:0.8rem;color:#16a34a;">Active</span>';
                $tc    = (int)($comp->timecompleted ?? 0);
                $tcStr = $tc > 0 ? userdate($tc, get_string('strftimedatetimeshort', 'langconfig')) : 'Unknown';

                echo '<tr>';
                echo '<td><input type="checkbox" class="hub-student-cb" name="userids[]" value="' . (int)$uid . '" checked></td>';
                echo '<td>' . s(fullname($comp)) . $badge . '</td>';
                echo '<td>' . s($comp->email ?? '') . '</td>';
                echo '<td>' . $tcStr . '</td>';
                echo '<td>' . $activateBox . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</form>';

        } else {
            echo '<div class="no-deadlines" style="background:#f0fdf4;border-color:#bbf7d0;">';
            echo '<p style="color:#166534;">✅ All completers for this qualification already have a Testamur certificate.</p>';
            echo '</div>';
        }

        // Collapsible already-issued list.
        if (!empty($alreadyIssued)) {
            echo '<details style="margin-top:16px;">';
            echo '<summary style="cursor:pointer;color:#6b7280;font-size:0.87rem;">'
                . count($alreadyIssued) . ' student(s) already have an active Testamur — click to expand</summary>';
            echo '<table class="generaltable" style="margin-top:8px;">';
            echo '<thead><tr><th>Student</th><th>Email</th></tr></thead><tbody>';
            foreach ($alreadyIssued as $uid => $comp) {
                echo '<tr><td>' . s(fullname($comp)) . '</td><td>' . s($comp->email ?? '') . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
    }

    // Select-all JS.
    echo '<script>(function (){';
    echo 'var sa=document.getElementById("hub-select-all");';
    echo 'if(!sa)return;';
    echo 'sa.addEventListener("change",function (){';
    echo '  document.querySelectorAll(".hub-student-cb").forEach(function (c){c.checked=sa.checked;});';
    echo '});';
    echo '})();</script>';
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 2 — Certs Issued  (searchable, paginated)
// ═══════════════════════════════════════════════════════════════════════════════
if ($activeTab === 'issued') {
    $search    = optional_param('q',    '', PARAM_TEXT);
    $qclean    = trim($search);
    $page_num  = optional_param('page', 0,  PARAM_INT);
    $perpage   = 50;

    $likesql   = '';
    $likeparam = [];
    if ($qclean !== '') {
        $likesql = " AND (u.firstname "  . $DB->sql_like('u.firstname',  ':fn', false)
                 . " OR u.lastname "     . $DB->sql_like('u.lastname',   ':ln', false)
                 . " OR u.email "        . $DB->sql_like('u.email',      ':em', false)
                 . " OR c.certnumber "   . $DB->sql_like('c.certnumber', ':cn', false) . ")";
        $esc       = '%' . $DB->sql_like_escape($qclean) . '%';
        $likeparam = ['fn' => $esc, 'ln' => $esc, 'em' => $esc, 'cn' => $esc];
    }

    $baseParams = array_merge(['qc' => $qualcode], $likeparam);
    $totalcerts = (int)$DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_rtocompliance_certs} c JOIN {user} u ON u.id = c.userid
          WHERE c.qualificationcode = :qc AND c.status = 'issued'
            AND (c.reissued_at IS NULL OR c.reissued_at = 0) $likesql",
        $baseParams
    );
    $certrows   = $DB->get_records_sql(
        "SELECT c.id, c.userid, c.certtype, c.certnumber, c.timecreated,
                u.firstname, u.lastname, u.email
           FROM {local_rtocompliance_certs} c
           JOIN {user} u ON u.id = c.userid
          WHERE c.qualificationcode = :qc AND c.status = 'issued'
            AND (c.reissued_at IS NULL OR c.reissued_at = 0) $likesql
          ORDER BY c.timecreated DESC",
        $baseParams, $page_num * $perpage, $perpage
    );

    // Search bar.
    $searchBaseUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', ['qualid' => $qualid, 'tab' => 'issued']))->out(false);
    echo '<form method="get" action="' . s($searchBaseUrl) . '" style="display:flex;gap:8px;margin-bottom:16px;">';
    echo '<input type="hidden" name="qualid" value="' . (int)$qualid . '">';
    echo '<input type="hidden" name="tab" value="issued">';
    echo '<input type="text" name="q" value="' . s($qclean) . '" placeholder="Search by name, email, or cert number…" class="form-control" style="max-width:380px;">';
    echo '<button type="submit" class="btn btn-primary btn-sm">Search</button>';
    if ($qclean !== '') {
        echo '<a href="' . s($searchBaseUrl) . '" class="btn btn-outline-secondary btn-sm">Clear</a>';
    }
    echo '</form>';

    if (empty($certrows)) {
        echo '<div class="no-deadlines"><p>No issued certificates found' . ($qclean ? ' matching &ldquo;' . s($qclean) . '&rdquo;' : '') . '.</p></div>';
    } else {
        echo '<p style="color:#6b7280;font-size:0.88rem;margin-bottom:10px;">Showing ' . count($certrows) . ' of ' . $totalcerts . ' certificate(s).</p>';
        echo '<table class="generaltable"><thead><tr>';
        echo '<th>Student</th><th>Email</th><th>Type</th><th>Cert Number</th><th>Issued</th>';
        echo '</tr></thead><tbody>';
        foreach ($certrows as $row) {
            $certUrl = (new moodle_url('/local/rtocompliance/certificates.php', ['view' => $row->id]))->out(false);
            echo '<tr>';
            echo '<td>' . s($row->firstname . ' ' . $row->lastname) . '</td>';
            echo '<td>' . s($row->email ?? '') . '</td>';
            echo '<td><span title="The kind of certificate. A Testamur is the full qualification certificate; a Statement of Attainment covers only some units." style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:600;">' . s(ucfirst($row->certtype ?? '')) . '</span></td>';
            echo '<td><a href="' . s($certUrl) . '">' . s($row->certnumber ?? '') . '</a></td>';
            echo '<td>' . ($row->timecreated ? userdate((int)$row->timecreated, get_string('strftimedatetimeshort', 'langconfig')) : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($totalcerts > $perpage) {
            $pagingurl = new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid' => $qualid, 'tab' => 'issued', 'q' => $qclean,
            ]);
            echo $OUTPUT->paging_bar($totalcerts, $page_num, $perpage, $pagingurl);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 3 — Partially Complete  (safety cap: 500 enrolled students)
// ═══════════════════════════════════════════════════════════════════════════════
if ($activeTab === 'partial') {
    $partialCap  = 500;
    $maxmissing  = optional_param('maxmissing', 0, PARAM_INT); // 0 = no filter
    $allcids     = $det['alllinkedcourseids'];

    if (empty($allcids)) {
        echo '<div class="no-deadlines"><p>No linked courses found for this qualification — cannot determine partially complete students.</p></div>';
    } else {
        list($cidInsql, $cidParams) = $DB->get_in_or_equal($allcids, SQL_PARAMS_NAMED, 'ec');
        $enrolled = $DB->get_records_sql(
            "SELECT DISTINCT ue.userid FROM {user_enrolments} ue
               JOIN {enrol} en ON en.id = ue.enrolid
              WHERE en.courseid $cidInsql AND ue.status = 0",
            $cidParams,
            0,
            $partialCap + 1  // +1 so we can detect the cap was exceeded
        );

        $cappedAt = count($enrolled) > $partialCap;
        if ($cappedAt) {
            array_pop($enrolled);
            echo '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:0.85rem;color:#92400e;">';
            echo '⚠ More than ' . $partialCap . ' students enrolled — showing first ' . $partialCap . ' only.';
            echo '</div>';
        }

        if (empty($enrolled)) {
            echo '<div class="no-deadlines"><p>No actively enrolled students found in the courses linked to this qualification.</p></div>';
        } else {
            $completerIds = array_keys($allcompleters);
            $numtotal     = max(1, $det['numunits']); // avoid division by zero
            $partials     = [];

            // TASK-52 (v5.9.350): Build a full unitcode→unitname map once so we can
            // derive the missing-unit list for each student without extra DB calls.
            $allQualUnits = local_rtocompliance_get_qualbuilder_unit_list($qual->id);
            $allUnitMap   = []; // unitcode => unitname (preserves QB sequence order)
            foreach ($allQualUnits as $u) {
                if (!empty($u['code'])) {
                    $allUnitMap[$u['code']] = $u['name'] ?? '';
                }
            }

            foreach ($enrolled as $row) {
                $uid = (int)$row->userid;
                if (in_array($uid, $completerIds)) {
                    continue; // Fully complete — skip (shown in Tab 1).
                }
                $doneUnits = local_rtocompliance_get_completed_units_for_qual($qual->id, $uid);
                if (empty($doneUnits)) {
                    continue; // Zero progress — not worth showing.
                }
                $userobj = $DB->get_record('user', ['id' => $uid], 'id,firstname,lastname,email', IGNORE_MISSING);
                if (!$userobj) {
                    continue;
                }
                $pct = min(100, (int)round(100 * count($doneUnits) / $numtotal));

                // Compute missing units: all qual units that are NOT in the done set.
                $doneUnitCodes = array_column($doneUnits, 'code');
                $missingUnits  = [];
                foreach ($allUnitMap as $code => $name) {
                    if (!in_array($code, $doneUnitCodes)) {
                        $missingUnits[] = ['code' => $code, 'name' => $name];
                    }
                }

                $partials[] = [
                    'user'    => $userobj,
                    'done'    => count($doneUnits),
                    'total'   => $numtotal,
                    'percent' => $pct,
                    'missing' => $missingUnits,
                ];
            }

            usort($partials, function ($a, $b) { return $b['percent'] - $a['percent']; });

            // ── Units-remaining filter ────────────────────────────────────────
            $totalBeforeFilter = count($partials);
            if ($maxmissing > 0) {
                $partials = array_values(array_filter($partials, function ($p) use ($maxmissing) {
                    return count($p['missing']) <= $maxmissing;
                }));
            }

            // Filter bar (rendered before the empty-check so it always shows).
            $filterBaseUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid' => $qualid, 'tab' => 'partial',
            ]))->out(false);
            echo '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;'
                . 'padding:10px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">';
            echo '<label style="font-size:0.88rem;font-weight:600;color:#374151;white-space:nowrap;">Filter by units remaining:</label>';
            $filterOptions = [
                0 => 'All students',
                1 => '≤ 1 unit away',
                2 => '≤ 2 units away',
                3 => '≤ 3 units away',
                5 => '≤ 5 units away',
            ];
            foreach ($filterOptions as $val => $label) {
                $active = ((int)$maxmissing === $val);
                $furl   = $val === 0
                    ? s($filterBaseUrl)
                    : s($filterBaseUrl . '&maxmissing=' . $val);
                $cls    = $active ? 'btn btn-primary btn-sm' : 'btn btn-outline-secondary btn-sm';
                echo '<a href="' . $furl . '" class="' . $cls . '">' . $label . '</a>';
            }
            if ($maxmissing > 0) {
                echo '<span style="font-size:0.82rem;color:#6b7280;margin-left:4px;">'
                    . count($partials) . ' of ' . $totalBeforeFilter . ' student(s) shown</span>';
            }
            echo '</div>';

            if (empty($partials)) {
                $msg = $maxmissing > 0
                    ? 'No students match this filter (≤ ' . $maxmissing . ' unit' . ($maxmissing === 1 ? '' : 's') . ' remaining).'
                    : 'No enrolled students have partial progress on this qualification.';
                echo '<div class="no-deadlines"><p>' . $msg . '</p></div>';
            } else {
                echo '<p style="color:#6b7280;font-size:0.88rem;margin-bottom:10px;">'
                    . count($partials) . ' student(s) with partial progress (read-only; sorted highest % first).</p>';
                echo '<table class="generaltable"><thead><tr>';
                echo '<th>Student</th><th>Email</th><th>Progress</th><th>Units Done</th><th>Still Needed</th>';
                echo '</tr></thead><tbody>';
                foreach ($partials as $p) {
                    $pct         = $p['percent'];
                    $col         = $pct >= 75 ? '#16a34a' : ($pct >= 50 ? '#d97706' : '#dc2626');
                    $missingList = $p['missing'];
                    $missingCnt  = count($missingList);
                    echo '<tr>';
                    echo '<td>' . s(fullname($p['user'])) . '</td>';
                    echo '<td>' . s($p['user']->email ?? '') . '</td>';
                    echo '<td style="min-width:160px;">'
                        . '<div style="background:#e5e7eb;border-radius:4px;height:10px;width:120px;overflow:hidden;display:inline-block;vertical-align:middle;">'
                        . '<div style="background:' . $col . ';height:100%;width:' . $pct . '%;"></div>'
                        . '</div>'
                        . ' <span style="font-size:0.78rem;color:#374151;">' . $pct . '%</span>'
                        . '</td>';
                    echo '<td>' . $p['done'] . ' / ' . $p['total'] . '</td>';
                    // Missing-units column: expandable list of unit codes + names.
                    echo '<td style="min-width:200px;">';
                    if (empty($missingList)) {
                        echo '<span style="color:#16a34a;font-size:0.82rem;">✓ All units done</span>';
                    } else {
                        echo '<details style="cursor:pointer;">';
                        echo '<summary style="font-size:0.82rem;color:#dc2626;list-style:none;cursor:pointer;">'
                            . '&#9656; ' . $missingCnt . ' unit' . ($missingCnt === 1 ? '' : 's') . ' still needed'
                            . '</summary>';
                        echo '<ul style="margin:6px 0 2px 0;padding:0 0 0 1.1em;font-size:0.78rem;color:#374151;">';
                        foreach ($missingList as $mu) {
                            echo '<li style="margin:2px 0;">'
                                . '<strong>' . s($mu['code']) . '</strong>'
                                . (!empty($mu['name']) ? ' — ' . s($mu['name']) : '')
                                . '</li>';
                        }
                        echo '</ul>';
                        echo '</details>';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 4 — Autocert Queue
// ═══════════════════════════════════════════════════════════════════════════════
if ($activeTab === 'queue') {
    $dbman_q4 = $DB->get_manager();
    if (!$dbman_q4->table_exists('local_rtocompliance_autocerts')
        || !$dbman_q4->table_exists('local_rtocompliance_students')
    ) {
        echo '<div class="no-deadlines"><p>Autocert tables are not installed — run Moodle upgrade to apply pending database changes.</p></div>';
    } else {
        $queueRows = $DB->get_records_sql(
            "SELECT ac.id, ac.status, ac.certtypes, ac.creditcost, ac.errormessage,
                    ac.timecreated, ac.timemodified,
                    u.id AS userid, u.firstname, u.lastname, u.email
               FROM {local_rtocompliance_autocerts} ac
               JOIN {local_rtocompliance_students} s ON s.id = ac.studentid
               JOIN {user} u ON u.id = s.userid
              WHERE ac.qualbuilderid = :qbid
              ORDER BY ac.status ASC, ac.timecreated DESC",
            ['qbid' => $qualid]
        );

        $pendingQueue = array_filter($queueRows, fn($r) => $r->status === 'pending');

        // Process button + info.
        if (!empty($pendingQueue)) {
            $processUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid'  => $qualid,
                'action'  => 'process_queue',
                'sesskey' => sesskey(),
            ]))->out(false);
            $pqCount = count($pendingQueue);

            echo '<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;margin-bottom:16px;">';
            echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">';
            echo '<div><strong style="color:#92400e;">' . $pqCount . ' pending ' . ($pqCount === 1 ? 'entry' : 'entries') . '</strong> '
                . '<span style="font-size:0.87rem;color:#78350f;">— students who triggered the autocert pipeline but have not yet received certificates.</span></div>';
            echo '<div style="display:flex;align-items:center;gap:10px;">';
            echo '<label style="font-size:0.85rem;display:flex;align-items:center;gap:6px;cursor:pointer;">';
            echo '<input type="checkbox" id="qproc-email" checked> Email on issue</label>';
            echo '<a href="javascript:void(0)" class="btn btn-warning btn-sm" id="qproc-btn"'
                . ' onclick="'
                . 'var url=\'' . s($processUrl) . '&sendemail=1\';'
                . 'var em=document.getElementById(\'qproc-email\');'
                . 'if(em&&!em.checked)url=\'' . s($processUrl) . '&sendemail=0\';'
                . 'if(confirm(\'Re-verify completion and process ' . $pqCount . ' pending queue entr' . ($pqCount === 1 ? 'y' : 'ies') . '?\\n\\nEach certificate issued costs 5 credits (about A$0.50); a full qualification issues 2 certificates per student. This will charge up to ' . ((int)$pqCount * 10) . ' credits (about A$' . number_format((int)$pqCount * 1.0, 2) . ') in total.\\n\\nContinue?\'))window.location.href=url;'
                . '">Process Queue</a>';
            echo '</div></div></div>';
        } else {
            echo '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:0.88rem;color:#166534;">';
            echo '✅ No pending entries in the autocert queue for this qualification.';
            echo '</div>';
        }

        // Per-qual catch-up scan.
        $scanUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
            'action'      => 'scan_missed',
            'scan_qualid' => $qualid,
            'sesskey'     => sesskey(),
        ]))->out(false);
        echo '<div style="padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
        echo '<span style="font-size:0.85rem;color:#374151;">Scan for completers with no certificate and no queue entry (historical catch-up for this qualification).</span>';
        echo '<a href="' . s($scanUrl) . '" class="btn btn-outline-secondary btn-sm"'
            . ' onclick="return confirm(\'Scan ' . s(addslashes($qualcode)) . ' for missed completions?\');">Scan This Qual &rarr;</a>';
        echo '</div>';

        // Retry All Failed button (shown only when there are failed rows).
        $failedQueue = array_filter($queueRows, fn($r) => $r->status === 'failed');
        if (!empty($failedQueue)) {
            $retryAllUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                'qualid'  => $qualid,
                'action'  => 'retry_all_failed',
                'sesskey' => sesskey(),
            ]))->out(false);
            $fqCount = count($failedQueue);
            echo '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">';
            echo '<span style="font-size:0.88rem;color:#991b1b;"><strong>' . $fqCount . ' failed ' . ($fqCount === 1 ? 'entry' : 'entries') . '</strong> — reset to pending so the next queue run will retry them.</span>';
            echo '<a href="javascript:void(0)" class="btn btn-danger btn-sm"'
                . ' onclick="if(confirm(\'Reset all ' . $fqCount . ' failed ' . ($fqCount === 1 ? 'entry' : 'entries') . ' to pending and retry?\'))window.location.href=\'' . s($retryAllUrl) . '\';">'
                . 'Retry All Failed</a>';
            echo '</div>';
        }

        // Full queue table.
        if (empty($queueRows)) {
            echo '<div class="no-deadlines"><p>No autocert queue entries for this qualification yet.</p></div>';
        } else {
            echo '<table class="generaltable"><thead><tr>';
            echo '<th>Student</th><th>Email</th><th>Cert Types</th><th>Status</th><th>Added</th><th>Notes</th><th>Actions</th>';
            echo '</tr></thead><tbody>';
            $statusStyles = [
                'pending'  => 'background:#fffbeb;color:#92400e;',
                'complete' => 'background:#f0fdf4;color:#166534;',
                'failed'   => 'background:#fef2f2;color:#991b1b;',
            ];
            $statusTips = [
                'pending'  => 'Waiting in the queue for the automatic run to issue this certificate.',
                'complete' => 'The certificate was issued successfully by the automatic run.',
                'failed'   => 'The automatic run could not issue this certificate. Use Retry to try again.',
            ];
            foreach ($queueRows as $row) {
                $ss    = $statusStyles[$row->status] ?? 'background:#f3f4f6;color:#374151;';
                $stip  = $statusTips[$row->status] ?? 'The current state of this queued certificate.';
                $badge = '<span title="' . s($stip) . '" style="' . $ss . 'padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:600;">' . ucfirst($row->status) . '</span>';

                // Per-row Retry button (only for failed rows).
                $actionCell = '—';
                if ($row->status === 'failed') {
                    $retryUrl = (new moodle_url('/local/rtocompliance/qual_cert_hub.php', [
                        'qualid'  => $qualid,
                        'action'  => 'retry_autocert',
                        'rowid'   => $row->id,
                        'sesskey' => sesskey(),
                    ]))->out(false);
                    $actionCell = '<a href="' . s($retryUrl) . '" class="btn btn-outline-danger btn-sm"'
                        . ' onclick="return confirm(\'Reset this entry to pending and retry?\');">Retry</a>';
                }

                echo '<tr>';
                echo '<td>' . s(fullname($row)) . '</td>';
                echo '<td>' . s($row->email ?? '') . '</td>';
                echo '<td style="font-size:0.85rem;">' . s($row->certtypes ?? '') . '</td>';
                echo '<td>' . $badge . '</td>';
                echo '<td>' . ($row->timecreated ? userdate((int)$row->timecreated, get_string('strftimedateshort', 'langconfig')) : '—') . '</td>';
                echo '<td style="font-size:0.8rem;color:#6b7280;">' . s($row->errormessage ?? '') . '</td>';
                echo '<td>' . $actionCell . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }
}

echo '</div>'; // .certificates-container
echo $OUTPUT->footer();
