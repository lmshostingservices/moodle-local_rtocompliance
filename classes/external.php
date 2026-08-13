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
 * RTO Compliance plugin — external.php.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->libdir/filelib.php");
require_once(__DIR__ . '/audit_logger.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use local_rtocompliance\audit_logger;

class external extends external_api {
    public static function get_student_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID', VALUE_REQUIRED),
        ]);
    }

    public static function get_student($userid) {
        global $DB;

        $params = self::validate_parameters(self::get_student_parameters(), ['userid' => $userid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:viewreports', $context);

        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $params['userid']]);
        if (!$student) {
            throw new \moodle_exception('studentnotfound', 'local_rtocompliance');
        }

        $user = \core_user::get_user($params['userid']);
        if (!$user) {
            $user = new \stdClass();
            $user->firstname = '';
            $user->lastname  = '';
            $user->email     = '';
        }

        return [
            'id' => (int)$student->id,
            'userid' => (int)$student->userid,
            'firstname' => $user->firstname ?? '',
            'lastname' => $user->lastname ?? '',
            'email' => $user->email ?? '',
            'clientid' => $student->clientid ?? '',
            'usi' => $student->usi ?? '',
            'dateofbirth' => (int)($student->dateofbirth ?? 0),
            'countryofbirth' => $student->countryofbirth ?? '',
            'languageathome' => $student->languageathome ?? '',
            'indigenousstatus' => $student->indigenousstatus ?? '',
            'disabilityflag' => $student->disabilityflag ?? 'N',
            'profilecomplete' => (int)($student->profilecomplete ?? 0),
        ];
    }

    public static function get_student_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Student record ID'),
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'firstname' => new external_value(PARAM_TEXT, 'First name'),
            'lastname' => new external_value(PARAM_TEXT, 'Last name'),
            'email' => new external_value(PARAM_TEXT, 'Email address'),
            'clientid' => new external_value(PARAM_TEXT, 'Client ID'),
            'usi' => new external_value(PARAM_TEXT, 'Unique Student Identifier'),
            'dateofbirth' => new external_value(PARAM_INT, 'Date of birth timestamp'),
            'countryofbirth' => new external_value(PARAM_TEXT, 'Country of birth code'),
            'languageathome' => new external_value(PARAM_TEXT, 'Language at home code'),
            'indigenousstatus' => new external_value(PARAM_TEXT, 'Indigenous status code'),
            'disabilityflag' => new external_value(PARAM_TEXT, 'Disability flag'),
            'profilecomplete' => new external_value(PARAM_INT, 'Profile complete flag'),
        ]);
    }

    public static function get_enrolments_parameters() {
        return new external_function_parameters([
            'studentid' => new external_value(PARAM_INT, 'Student record ID', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Moodle user ID', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHA, 'Filter by status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_enrolments($studentid = 0, $userid = 0, $status = '') {
        global $DB;

        $params = self::validate_parameters(self::get_enrolments_parameters(), [
            'studentid' => $studentid,
            'userid' => $userid,
            'status' => $status,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:viewreports', $context);

        $where = [];
        $sqlparams = [];

        if ($params['studentid'] > 0) {
            $where[] = 'e.studentid = ?';
            $sqlparams[] = $params['studentid'];
        } elseif ($params['userid'] > 0) {
            $student = $DB->get_record('local_rtocompliance_students', ['userid' => $params['userid']]);
            if (!$student) {
                return [];
            }
            $where[] = 'e.studentid = ?';
            $sqlparams[] = $student->id;
        }

        if (!empty($params['status'])) {
            $where[] = 'e.status = ?';
            $sqlparams[] = $params['status'];
        }

        $wheresql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT e.*, c.fullname as coursename
                FROM {local_rtocompliance_enrolments} e
                LEFT JOIN {course} c ON c.id = e.courseid
                $wheresql
                ORDER BY e.activitystartdate DESC";

        $enrolments = $DB->get_records_sql($sql, $sqlparams, 0, 100);

        $result = [];
        foreach ($enrolments as $e) {
            $result[] = [
                'id' => (int)$e->id,
                'studentid' => (int)$e->studentid,
                'courseid' => (int)$e->courseid,
                'coursename' => $e->coursename ?? '',
                'programcode' => $e->programcode ?? '',
                'programname' => $e->programname ?? '',
                'unitcode' => $e->unitcode ?? '',
                'unitname' => $e->unitname ?? '',
                'activitystartdate' => (int)($e->activitystartdate ?? 0),
                'activityenddate' => (int)($e->activityenddate ?? 0),
                'outcomeidentifier' => $e->outcomeidentifier ?? '00',
                'deliverymode' => $e->deliverymode ?? '10',
                'fundingsourcenat' => $e->fundingsourcenat ?? '30',
                'status' => $e->status ?? 'active',
            ];
        }

        return $result;
    }

    public static function get_enrolments_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Enrolment record ID'),
                'studentid' => new external_value(PARAM_INT, 'Student record ID'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'programcode' => new external_value(PARAM_TEXT, 'Program/qualification code'),
                'programname' => new external_value(PARAM_TEXT, 'Program name'),
                'unitcode' => new external_value(PARAM_TEXT, 'Unit code'),
                'unitname' => new external_value(PARAM_TEXT, 'Unit name'),
                'activitystartdate' => new external_value(PARAM_INT, 'Activity start timestamp'),
                'activityenddate' => new external_value(PARAM_INT, 'Activity end timestamp'),
                'outcomeidentifier' => new external_value(PARAM_TEXT, 'AVETMISS outcome code'),
                'deliverymode' => new external_value(PARAM_TEXT, 'Delivery mode code'),
                'fundingsourcenat' => new external_value(PARAM_TEXT, 'National funding source code'),
                'status' => new external_value(PARAM_TEXT, 'Enrolment status'),
            ])
        );
    }

    public static function get_compliance_summary_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_compliance_summary() {
        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:viewreports', $context);

        $predictor = new \local_rtocompliance\ai\compliance_predictor();
        $summary = $predictor->get_compliance_summary();

        return [
            'overall_status' => $summary['overall_status'],
            'risk_score' => (int)$summary['risk_score'],
            'total_alerts' => (int)$summary['total_alerts'],
            'critical_count' => (int)$summary['by_severity']['critical'],
            'high_count' => (int)$summary['by_severity']['high'],
            'medium_count' => (int)$summary['by_severity']['medium'],
            'low_count' => (int)$summary['by_severity']['low'],
        ];
    }

    public static function get_compliance_summary_returns() {
        return new external_single_structure([
            'overall_status' => new external_value(PARAM_TEXT, 'Overall compliance status'),
            'risk_score' => new external_value(PARAM_INT, 'Risk score 0-100'),
            'total_alerts' => new external_value(PARAM_INT, 'Total active alerts'),
            'critical_count' => new external_value(PARAM_INT, 'Critical alerts'),
            'high_count' => new external_value(PARAM_INT, 'High priority alerts'),
            'medium_count' => new external_value(PARAM_INT, 'Medium priority alerts'),
            'low_count' => new external_value(PARAM_INT, 'Low priority alerts'),
        ]);
    }

    public static function get_certificates_parameters() {
        return new external_function_parameters([
            'studentid' => new external_value(PARAM_INT, 'Student record ID', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHA, 'Filter by status', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_certificates($studentid = 0, $status = '') {
        global $DB;

        $params = self::validate_parameters(self::get_certificates_parameters(), [
            'studentid' => $studentid,
            'status' => $status,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:viewreports', $context);

        $where = [];
        $sqlparams = [];

        // BUG-1 FIX: Table is local_rtocompliance_certs (not local_rtocompliance_certificates).
        // The certs table uses userid (FK to user.id), not studentid. Join students via userid.
        if ($params['studentid'] > 0) {
            $where[] = 's.id = ?';
            $sqlparams[] = $params['studentid'];
        }

        if (!empty($params['status'])) {
            $where[] = 'c.status = ?';
            $sqlparams[] = $params['status'];
        }

        $wheresql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT c.*, s.id AS studentid, s.usi, u.firstname, u.lastname
                FROM {local_rtocompliance_certs} c
                JOIN {user} u ON u.id = c.userid
                LEFT JOIN {local_rtocompliance_students} s ON s.userid = c.userid
                $wheresql
                ORDER BY c.issuedate DESC";

        $certificates = $DB->get_records_sql($sql, $sqlparams, 0, 100);

        $result = [];
        foreach ($certificates as $cert) {
            $verifytokenurl = !empty($cert->verifytoken)
                ? (new \moodle_url('/local/rtocompliance/verify.php', ['token' => $cert->verifytoken]))->out(false)
                : '';
            $result[] = [
                'id' => (int)$cert->id,
                'studentid' => (int)($cert->studentid ?? 0),
                'studentname' => $cert->firstname . ' ' . $cert->lastname,
                'usi' => $cert->usi ?? '',
                'certificatetype' => $cert->certtype ?? '',
                'certificatenumber' => $cert->certnumber ?? '',
                'qualificationcode' => $cert->qualificationcode ?? '',
                'qualificationname' => $cert->qualificationname ?? '',
                'dateissued' => (int)($cert->issuedate ?? 0),
                'expirydate' => (int)($cert->expirydate ?? 0),
                'status' => $cert->status ?? 'issued',
                'verificationurl' => $verifytokenurl,
            ];
        }

        return $result;
    }

    public static function get_certificates_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Certificate record ID'),
                'studentid' => new external_value(PARAM_INT, 'Student record ID'),
                'studentname' => new external_value(PARAM_TEXT, 'Student full name'),
                'usi' => new external_value(PARAM_TEXT, 'USI'),
                'certificatetype' => new external_value(PARAM_TEXT, 'Certificate type'),
                'certificatenumber' => new external_value(PARAM_TEXT, 'Certificate number'),
                'qualificationcode' => new external_value(PARAM_TEXT, 'Qualification code'),
                'qualificationname' => new external_value(PARAM_TEXT, 'Qualification name'),
                'dateissued' => new external_value(PARAM_INT, 'Date issued timestamp'),
                'expirydate' => new external_value(PARAM_INT, 'Expiry date timestamp'),
                'status' => new external_value(PARAM_TEXT, 'Certificate status'),
                'verificationurl' => new external_value(PARAM_TEXT, 'QR verification URL'),
            ])
        );
    }

    public static function update_enrolment_outcome_parameters() {
        return new external_function_parameters([
            'enrolmentid' => new external_value(PARAM_INT, 'Enrolment record ID', VALUE_REQUIRED),
            'outcomeidentifier' => new external_value(PARAM_TEXT, 'AVETMISS outcome code', VALUE_REQUIRED),
            'activityenddate' => new external_value(PARAM_INT, 'Activity end date timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    public static function update_enrolment_outcome($enrolmentid, $outcomeidentifier, $activityenddate = 0) {
        global $DB, $USER;

        $params = self::validate_parameters(self::update_enrolment_outcome_parameters(), [
            'enrolmentid' => $enrolmentid,
            'outcomeidentifier' => $outcomeidentifier,
            'activityenddate' => $activityenddate,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);

        $enrolment = $DB->get_record('local_rtocompliance_enrolments', ['id' => $params['enrolmentid']]);
        if (!$enrolment) {
            throw new \moodle_exception('enrolmentnotfound', 'local_rtocompliance');
        }

        $validoutcomes = ['00', '20', '30', '40', '51', '52', '53', '54', '60', '61', '70', '81', '82', '85', '90'];
        if (!in_array($params['outcomeidentifier'], $validoutcomes)) {
            throw new \moodle_exception('invalidoutcome', 'local_rtocompliance');
        }

        $update = new \stdClass();
        $update->id = $enrolment->id;
        $update->outcomeidentifier = $params['outcomeidentifier'];
        $update->timemodified = time();

        if ($params['activityenddate'] > 0) {
            $update->activityenddate = $params['activityenddate'];
        }

        $completedoutcomes = ['20', '51', '52', '60', '81', '82'];
        if (in_array($params['outcomeidentifier'], $completedoutcomes)) {
            $update->status = 'completed';
        }

        $DB->update_record('local_rtocompliance_enrolments', $update);

        audit_logger::log_update(
            audit_logger::ENTITY_ENROLMENT,
            $enrolment->id,
            'Outcome updated via REST API: ' . $enrolment->outcomeidentifier . ' -> ' . $params['outcomeidentifier'],
            [
                'enrolment_id' => $enrolment->id,
                'student_id' => $enrolment->studentid,
                'course_id' => $enrolment->courseid ?? null,
                'unit_code' => $enrolment->unitcode ?? '',
                'old_outcome' => $enrolment->outcomeidentifier,
                'old_status' => $enrolment->status ?? '',
            ],
            [
                'new_outcome' => $params['outcomeidentifier'],
                'new_status' => $update->status ?? $enrolment->status,
                'api_caller_user_id' => $USER->id,
                'timestamp' => time(),
            ]
        );

        return ['success' => true, 'message' => 'Enrolment outcome updated successfully'];
    }

    public static function update_enrolment_outcome_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }

    public static function run_compliance_scan_parameters() {
        return new external_function_parameters([]);
    }

    public static function run_compliance_scan() {
        // BUG-EXT-1 FIX: $USER->id used in audit_logger call below but $USER was never declared
        // as a global. Without global $USER, PHP uses the local scope and $USER is null,
        // causing a "Trying to get property of non-object" fatal error on the audit log line.
        global $USER;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);

        $predictor = new \local_rtocompliance\ai\compliance_predictor();
        $alerts = $predictor->run_predictive_analysis();

        $alertsummary = [];
        foreach ($alerts as $alert) {
            $type = is_object($alert) ? ($alert->alerttype ?? 'unknown') : ($alert['alerttype'] ?? 'unknown');
            if (!isset($alertsummary[$type])) {
                $alertsummary[$type] = 0;
            }
            $alertsummary[$type]++;
        }

        audit_logger::log(
            'scan',
            audit_logger::ENTITY_ALERT,
            0,
            null,
            'Compliance scan executed via REST API: ' . count($alerts) . ' alerts generated',
            null,
            [
                'alerts_generated' => count($alerts),
                'alerts_by_type' => $alertsummary,
                'api_caller_user_id' => $USER->id,
                'scan_timestamp' => time(),
            ]
        );

        return [
            'success' => true,
            'alerts_generated' => count($alerts),
            'message' => count($alerts) . ' compliance checks completed',
        ];
    }

    public static function run_compliance_scan_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'alerts_generated' => new external_value(PARAM_INT, 'Number of alerts generated'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }

    public static function tga_search_qualification_parameters() {
        return new external_function_parameters([
            'code' => new external_value(PARAM_TEXT, 'Qualification code to search (e.g. BSB50420)', VALUE_REQUIRED),
        ]);
    }

    public static function tga_search_qualification($code) {
        global $CFG;
        
        $params = self::validate_parameters(self::tga_search_qualification_parameters(), ['code' => $code]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);
        
        $code = strtoupper(trim($params['code']));
        
        // Explicitly include aiconfig lib.php if available
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        
        $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
        // Priority 1: Central Config (recommended for multi-plugin setups).
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = local_aiconfig_get_apikey();
        } else {
            $apikey = get_config('local_rtocompliance', 'apikey');
        }
        
        if (empty($apikey)) {
            return [
                'success' => false,
                'error' => 'API key not configured. Please configure the Platform API key in Plugin Settings.',
                'qualification' => null,
                'units' => [],
            ];
        }
        
        $url = $apiurl . '/api/tga/qualification/' . urlencode($code);

        // BUG-24 FIX: Raw curl_init() bypasses Moodle's proxy, SSL certificate, and
        // redirect configuration (set in Site Administration → HTTP → curl settings).
        // On Moodle sites behind an institutional proxy or with custom CA certificates
        // the raw handle would silently fail or produce SSL errors. Use Moodle's \curl
        // class instead, which reads all of these settings automatically — identical
        // to the fix already applied to the ajax.php tga_qualification action (bug 10).
        $curl = new \curl();
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 30]);
        $curl->setHeader(['X-API-Key: ' . $apikey, 'Content-Type: application/json']);
        $response = $curl->get($url);
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $error    = $curl->get_errno() ? $curl->get_error_msg() : '';

        if ($error) {
            return [
                'success' => false,
                'error' => 'Network error: ' . $error,
                'qualification' => null,
                'units' => [],
            ];
        }

        if ($httpcode !== 200) {
            $data = json_decode($response, true);
            return [
                'success' => false,
                'error' => $data['message'] ?? 'TGA lookup failed (HTTP ' . $httpcode . ')',
                'qualification' => null,
                'units' => [],
            ];
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['qualification'])) {
            return [
                'success' => false,
                'error' => 'Invalid response from TGA API',
                'qualification' => null,
                'units' => [],
            ];
        }
        
        $units = [];
        if (!empty($data['units'])) {
            foreach ($data['units'] as $unit) {
                $unitcode = trim($unit['UnitCode'] ?? $unit['unitCode'] ?? '');
                if (empty($unitcode)) {
                    continue;
                }
                $iscore = !empty($unit['CoreUnit']) || !empty($unit['coreUnit']);
                $nominalhours = (int)($unit['NominalHours'] ?? $unit['nominalHours'] ?? $unit['nominalhours'] ?? 0);
                $units[] = [
                    'unitcode'     => $unitcode,
                    'unitname'     => trim($unit['UnitTitle'] ?? $unit['unitTitle'] ?? ''),
                    'unittype'     => $iscore ? 'core' : 'elective',
                    'nominalhours' => $nominalhours,
                ];
            }
        }
        
        return [
            'success' => true,
            'error' => '',
            'qualification' => [
                'code' => $data['qualification']['Code'] ?? $data['qualification']['code'] ?? $code,
                'title' => $data['qualification']['Title'] ?? $data['qualification']['title'] ?? '',
                'status' => $data['qualification']['CurrencyStatus'] ?? $data['qualification']['currencyStatus'] ?? '',
            ],
            'units' => $units,
        ];
    }

    public static function tga_search_qualification_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'error' => new external_value(PARAM_TEXT, 'Error message if failed'),
            'qualification' => new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Qualification code'),
                'title' => new external_value(PARAM_TEXT, 'Qualification title'),
                'status' => new external_value(PARAM_TEXT, 'Currency status'),
            ], 'Qualification details', VALUE_OPTIONAL),
            'units' => new external_multiple_structure(
                new external_single_structure([
                    'unitcode' => new external_value(PARAM_TEXT, 'Unit code'),
                    'unitname' => new external_value(PARAM_TEXT, 'Unit name'),
                    'unittype' => new external_value(PARAM_TEXT, 'Unit type: core, elective'),
                    'nominalhours' => new external_value(PARAM_INT, 'Nominal hours'),
                ]),
                'List of units',
                VALUE_OPTIONAL
            ),
        ]);
    }

    public static function tga_search_unit_parameters() {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Unit code or search term', VALUE_REQUIRED),
        ]);
    }

    public static function tga_search_unit($query) {
        global $CFG;
        
        $params = self::validate_parameters(self::tga_search_unit_parameters(), ['query' => $query]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);
        
        $query = trim($params['query']);
        
        // Explicitly include aiconfig lib.php if available
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        
        $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';
        // Priority 1: Central Config (recommended for multi-plugin setups).
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = local_aiconfig_get_apikey();
        } else {
            $apikey = get_config('local_rtocompliance', 'apikey');
        }
        
        if (empty($apikey)) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'units' => [],
            ];
        }
        
        $url = $apiurl . '/api/tga/search?filter=' . urlencode($query) . '&type=unit';

        // BUG-24 FIX (part 2): Same raw curl_init() issue as tga_search_qualification above.
        // Replaced with Moodle's \curl class for correct proxy/SSL/redirect handling.
        $curl = new \curl();
        $curl->setopt(['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_TIMEOUT' => 30]);
        $curl->setHeader(['X-API-Key: ' . $apikey, 'Content-Type: application/json']);
        $response = $curl->get($url);
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $error    = $curl->get_errno() ? $curl->get_error_msg() : '';

        if ($error) {
            return [
                'success' => false,
                'error' => 'Network error: ' . $error,
                'units' => [],
            ];
        }

        if ($httpcode !== 200) {
            return [
                'success' => false,
                'error' => 'TGA search failed (HTTP ' . $httpcode . ')',
                'units' => [],
            ];
        }
        
        $data = json_decode($response, true);
        
        $units = [];
        if (!empty($data['results'])) {
            foreach ($data['results'] as $result) {
                $units[] = [
                    'unitcode' => $result['Code'] ?? $result['code'] ?? '',
                    'unitname' => $result['Title'] ?? $result['title'] ?? '',
                    'status' => $result['CurrencyStatus'] ?? $result['currencyStatus'] ?? '',
                ];
            }
        }
        
        return [
            'success' => true,
            'error' => '',
            'units' => $units,
        ];
    }

    public static function tga_search_unit_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'error' => new external_value(PARAM_TEXT, 'Error message if failed'),
            'units' => new external_multiple_structure(
                new external_single_structure([
                    'unitcode' => new external_value(PARAM_TEXT, 'Unit code'),
                    'unitname' => new external_value(PARAM_TEXT, 'Unit name'),
                    'status' => new external_value(PARAM_TEXT, 'Currency status'),
                ]),
                'Search results',
                VALUE_OPTIONAL
            ),
        ]);
    }

    public static function qualbuilder_import_units_parameters() {
        return new external_function_parameters([
            'qualbuilderid' => new external_value(PARAM_INT, 'Qualification builder ID', VALUE_REQUIRED),
            'units' => new external_value(PARAM_RAW, 'JSON array of units to import', VALUE_REQUIRED),
        ]);
    }

    public static function qualbuilder_import_units($qualbuilderid, $units) {
        global $DB, $USER;
        
        $params = self::validate_parameters(self::qualbuilder_import_units_parameters(), [
            'qualbuilderid' => $qualbuilderid,
            'units' => $units,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);
        
        $product = $DB->get_record('local_rtocompliance_qualbuilder', ['id' => $params['qualbuilderid']], '*', MUST_EXIST);
        
        $unitsdata = json_decode($params['units'], true);
        if (!is_array($unitsdata)) {
            return ['success' => false, 'imported' => 0, 'message' => 'Invalid units data'];
        }
        
        $now = time();
        $imported = 0;
        $skipped = 0;
        
        $existingunits = $DB->get_records_menu('local_rtocompliance_qualunits', 
            ['qualbuilderid' => $params['qualbuilderid']], '', 'unitcode, id');
        
        $maxorder = $DB->get_field_sql(
            "SELECT MAX(sequenceorder) FROM {local_rtocompliance_qualunits} WHERE qualbuilderid = ?",
            [$params['qualbuilderid']]
        ) ?: 0;
        
        foreach ($unitsdata as $unit) {
            $unitcode = strtoupper(trim($unit['unitcode'] ?? ''));
            if (empty($unitcode)) continue;
            
            if (isset($existingunits[$unitcode])) {
                $skipped++;
                continue;
            }
            
            $maxorder++;
            
            $record = new \stdClass();
            $record->qualbuilderid = $params['qualbuilderid'];
            $record->unitcode = $unitcode;
            $record->unitname = trim($unit['unitname'] ?? '');
            $record->unittype = $unit['unittype'] ?? 'elective';
            $record->electivegroup = !empty($unit['electivegroup']) ? $unit['electivegroup'] : null;
            $record->nominalhours = !empty($unit['nominalhours']) ? (int)$unit['nominalhours'] : null;
            $record->creditpoints = !empty($unit['creditpoints']) ? (int)$unit['creditpoints'] : 0;
            $record->sequenceorder = $maxorder;
            $record->selected = 1;
            $record->status = 'active';
            $record->timecreated = $now;
            $record->timemodified = $now;
            
            $DB->insert_record('local_rtocompliance_qualunits', $record);
            $imported++;
        }
        
        $DB->set_field('local_rtocompliance_qualbuilder', 'validationpassed', 0, ['id' => $params['qualbuilderid']]);
        $DB->set_field('local_rtocompliance_qualbuilder', 'timemodified', $now, ['id' => $params['qualbuilderid']]);
        
        audit_logger::log_create(
            'qualunit',
            $params['qualbuilderid'],
            "Bulk imported $imported units from TGA",
            ['imported' => $imported, 'skipped' => $skipped]
        );
        
        return [
            'success' => true,
            'imported' => $imported,
            'message' => "Imported $imported units" . ($skipped > 0 ? ", skipped $skipped duplicates" : ''),
        ];
    }

    public static function qualbuilder_import_units_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'imported' => new external_value(PARAM_INT, 'Number of units imported'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }

    // =====================================================================
    // tga_get_builder_data — full builder payload: TGA qual + Moodle hints
    // =====================================================================

    public static function tga_get_builder_data_parameters() {
        return new external_function_parameters([
            'code'       => new external_value(PARAM_ALPHANUMEXT, 'Qualification/SkillSet/Unit code', VALUE_REQUIRED),
            'categoryid' => new external_value(PARAM_INT, 'Already-selected qual root category ID (0 = unknown)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function tga_get_builder_data($code, $categoryid = 0) {
        global $DB;

        $params = self::validate_parameters(self::tga_get_builder_data_parameters(), ['code' => $code, 'categoryid' => $categoryid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);

        $code       = strtoupper(trim($params['code']));
        $categoryid = (int)$params['categoryid'];
        $apiurl = get_config('local_rtocompliance', 'apiurl') ?: 'https://lms-labs.com';

        $url = $apiurl . '/api/tga/qualbuilder/' . urlencode($code);
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $response = $curl->get($url);
        // BUG-EXT-2 FIX: Moodle's \curl class does not expose a public $info property.
        // Accessing $curl->info returns null, making $httpcode always 0 and causing every
        // TGA builder data call to return "TGA API unreachable (HTTP 0)".
        // The correct accessor is get_info(), which returns the curl_getinfo() array.
        $info     = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        $empty = [
            'success'               => false,
            'error'                 => '',
            'qualification'         => '{}',
            'packagingrules'        => '[]',
            'totalunits'            => 0,
            'corerequired'          => 0,
            'electiverequired'      => 0,
            'grouprules'            => '{}',
            'pointsrequired'        => 0,
            'pointssystem'          => 0,
            'corepointsrequired'    => 0,
            'electivepointsrequired'=> 0,
            'units'                 => [],
            'moodlecategories'      => [],
            'moodlecourses'         => [],
            'unitcodemap'           => [],
        ];

        if ($httpcode !== 200 || empty($response)) {
            $empty['error'] = 'TGA API unreachable (HTTP ' . $httpcode . '). Check API URL in plugin settings.';
            return $empty;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['success'])) {
            $empty['error'] = $data['message'] ?? 'Qualification not found on training.gov.au';
            return $empty;
        }

        // Build units array for return
        $unitdata = [];
        foreach ($data['units'] ?? [] as $u) {
            $unitdata[] = [
                'unitcode'     => $u['code'] ?? '',
                'unitname'     => $u['title'] ?? '',
                'iscore'       => !empty($u['isCore']) ? 1 : 0,
                'electivegroup'=> $u['group'] ?: '',
                'grouplabel'   => $u['groupLabel'] ?? '',
                'nominalhours' => (int)($u['nominalHours'] ?? 0),
                'creditpoints' => (int)($u['creditPoints'] ?? 0),
            ];
        }

        $pkg = $data['packagingRules'] ?? null;

        // Moodle categories — full tree, no LIMIT, streamed.
        // JS uses this to build the two-level picker (qual-root → semester-child).
        $catRs = $DB->get_recordset_sql(
            "SELECT id, name, parent, idnumber FROM {course_categories} ORDER BY sortorder ASC"
        );
        $moodlecats   = [];
        $catParentMap = [];   // catid → parent catid
        foreach ($catRs as $cat) {
            $catParentMap[(int)$cat->id] = (int)$cat->parent;
            $moodlecats[] = [
                'id'       => (int)$cat->id,
                'name'     => $cat->name,
                'parent'   => (int)$cat->parent,
                'idnumber' => (string)($cat->idnumber ?? ''),
            ];
        }
        $catRs->close();

        // Helper: walk parent chain to find the depth-0 (root) ancestor of a category.
        // Max 10 hops to guard against cyclic data.
        $getRootCatId = function (int $catid) use ($catParentMap) : int {
            $hops = 0;
            while (isset($catParentMap[$catid]) && $catParentMap[$catid] !== 0 && $hops < 10) {
                $catid = $catParentMap[$catid];
                $hops++;
            }
            return $catid;
        };

        // Moodle courses — scoped to the qual root's category subtree when categoryid is
        // known; otherwise capped at 2000.  This keeps the JSON payload small for large
        // sites (universities, multi-RTO portals) while returning every relevant course
        // for the qualification being edited.
        //
        // Strategy: normalise the passed categoryid to its depth-0 root, then collect all
        // category IDs whose root ancestor matches.  Filter the SQL to that set.
        // When categoryid = 0 (new record, no category selected yet) fall back to LIMIT 2000.
        $moodlecourses = [];
        if ($categoryid > 0) {
            // Build the subtree rooted at $categoryid via BFS.
            //
            // DO NOT use $getRootCatId($categoryid) as the subtree root.  For nested qual
            // roots (e.g. "Diploma a qualification" nested under "Miscellaneous"),
            // $getRootCatId walks all the way to parent=0 and returns "Miscellaneous".
            // Using that as the root collects EVERY category under Miscellaneous, causing
            // the SQL to return courses from all other qualifications on the site.
            //
            // BFS from $categoryid itself is always correct:
            // - Top-level root (parent=0): collects [$categoryid, S1, S2] -- same as before.
            // - Nested root (parent>0):   collects [$categoryid, S1, S2] -- previously broke.
            $subtreeCatIds = [(int)$categoryid];
            $bfsQueue      = [(int)$categoryid];
            while (!empty($bfsQueue)) {
                $bfsCurrent = array_shift($bfsQueue);
                foreach ($catParentMap as $_cid => $_pid) {
                    if ((int)$_pid === $bfsCurrent && !in_array((int)$_cid, $subtreeCatIds, true)) {
                        $subtreeCatIds[] = (int)$_cid;
                        $bfsQueue[]      = (int)$_cid;
                    }
                }
            }
            list($inSql, $inParams) = $DB->get_in_or_equal($subtreeCatIds, SQL_PARAMS_NAMED, 'qbcat');
            $courseRs = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder
                   FROM {course}
                  WHERE id > 1 AND category $inSql
                  ORDER BY sortorder ASC",
                $inParams
            );
        } else {
            // No category known yet — return up to 2000 courses so the admin can search.
            // Even the largest RTO sites rarely exceed 2000 unit courses.
            $courseRs = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder FROM {course} WHERE id > 1 ORDER BY sortorder ASC LIMIT 2000"
            );
        }
        $courseIdnumbers = []; // courseid => idnumber, used only for unitcodemap extraction below
        foreach ($courseRs as $c) {
            $moodlecourses[] = [
                'id'        => (int)$c->id,
                'shortname' => $c->shortname,
                'fullname'  => $c->fullname,
                'category'  => (int)$c->category,
                'sortorder' => (int)$c->sortorder,
                // Scope rootcatid to the passed qual root ($categoryid) when known.
                'rootcatid' => $categoryid > 0 ? $categoryid : $getRootCatId((int)$c->category),
            ];
            $courseIdnumbers[(int)$c->id] = (string)($c->idnumber ?? '');
        }
        $courseRs->close();

        // ── UNIT CODE MAP ────────────────────────────────────────────────────────
        // Pre-compute unitcode → course entries in PHP so the JS does a direct
        // dictionary lookup instead of fragile string-matching tier logic.
        //
        // Sources checked per course (highest confidence first):
        //   1. idnumber — admins often set this to the exact unit code on the RTO
        //   2. shortname — e.g. "ATI 2652" contains codes like "ABC12345" if embedded
        //   3. fullname  — e.g. "ATI 2652 – ABC12345 ABC12345 ABC12345 Plan and..."
        //
        // Pattern: 2–7 uppercase letters + 3–6 digits + optional trailing letter.
        // Minimum 6 characters to filter noise (HTML5, COVID19, etc.).
        // Matches: ABC12345, BSBOPS505, CPCCBC4014, HLTAID011, ABC12345, etc.
        $unitcodemap = [];
        $ucPattern   = '/\b([A-Z]{2,7}[0-9]{3,6}[A-Z]?)\b/';
        foreach ($moodlecourses as $mc) {
            $idn        = $courseIdnumbers[$mc['id']] ?? '';
            $searchText = strtoupper(
                ($idn ? $idn . ' ' : '') . $mc['shortname'] . ' ' . $mc['fullname']
            );
            if (preg_match_all($ucPattern, $searchText, $codeMatches)) {
                foreach (array_unique($codeMatches[1]) as $unitcode) {
                    if (strlen($unitcode) >= 6) {
                        $unitcodemap[] = [
                            'unitcode'  => $unitcode,
                            'courseid'  => $mc['id'],
                            'category'  => $mc['category'],
                            'shortname' => $mc['shortname'],
                        ];
                    }
                }
            }
        }

        // CROSS-PACKAGE-FIX (v5.9.266): Elective units from other training packages
        // (e.g. BSB electives in a TLI qualification) live in a different category
        // tree — the qual-subtree BFS scan above misses them entirely.  Run a
        // site-wide supplement scan limited to courses whose name/idnumber contains
        // a unit code that appears in the TGA data for this qualification.  This
        // keeps the extra payload minimal (only genuinely relevant courses added).
        // Courses added here get rootcatid=0 so the JS knows they are cross-package.
        if ($categoryid > 0 && !empty($unitdata)) {
            $tgaUnitCodes    = array_map(function ($u) { return strtoupper($u['unitcode']); }, $unitdata);
            $subtreeCourseIds = array_column($moodlecourses, 'id');
            $crossRs = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder
                   FROM {course} WHERE id > 1 ORDER BY sortorder ASC LIMIT 3000"
            );
            $crossAdded = [];
            foreach ($crossRs as $cc) {
                if (in_array((int)$cc->id, $subtreeCourseIds, true)) { continue; }
                $idn2       = trim((string)($cc->idnumber ?? ''));
                $srcText    = strtoupper(($idn2 ? $idn2 . ' ' : '') . $cc->shortname . ' ' . $cc->fullname);
                if (!preg_match_all($ucPattern, $srcText, $cm2)) { continue; }
                $matched = [];
                foreach (array_unique($cm2[1]) as $c2) {
                    if (strlen($c2) >= 6 && in_array($c2, $tgaUnitCodes, true)) {
                        $matched[] = $c2;
                    }
                }
                if (empty($matched)) { continue; }
                foreach ($matched as $mc2) {
                    $unitcodemap[] = [
                        'unitcode'  => $mc2,
                        'courseid'  => (int)$cc->id,
                        'category'  => (int)$cc->category,
                        'shortname' => $cc->shortname,
                    ];
                }
                if (!in_array((int)$cc->id, $crossAdded, true)) {
                    $moodlecourses[] = [
                        'id'        => (int)$cc->id,
                        'shortname' => $cc->shortname,
                        'fullname'  => $cc->fullname,
                        'category'  => (int)$cc->category,
                        'sortorder' => (int)$cc->sortorder,
                        'rootcatid' => 0,   // 0 = cross-package; not in this qual's category tree
                    ];
                    $crossAdded[] = (int)$cc->id;
                }
            }
            $crossRs->close();
        }

        // Derive per-section credit point thresholds from unit data when this is a points-based qual.
        // All core units are mandatory, so summing their creditpoints gives the minimum core points
        // required.  Elective points required = total points required minus core points required.
        $pointsrequired = (int)($pkg['pointsRequired'] ?? 0);
        $pointssystem   = !empty($pkg['pointsSystem']) ? 1 : 0;
        $corepointsrequired   = 0;
        $electivepointsrequired = 0;
        if ($pointssystem && $pointsrequired > 0) {
            foreach ($unitdata as $u) {
                if ($u['iscore']) {
                    $corepointsrequired += (int)($u['creditpoints'] ?? 0);
                }
            }
            $electivepointsrequired = max(0, $pointsrequired - $corepointsrequired);
        }

        return [
            'success'               => true,
            'error'                 => '',
            'qualification'         => json_encode($data['qualification'] ?? []),
            'packagingrules'        => json_encode($pkg['rulesText'] ?? []),
            'totalunits'            => (int)($pkg['totalUnits'] ?? 0),
            'corerequired'          => (int)($pkg['coreRequired'] ?? 0),
            'electiverequired'      => (int)($pkg['electiveRequired'] ?? 0),
            'grouprules'            => json_encode($pkg['groupRequirements'] ?? []),
            'pointsrequired'        => $pointsrequired,
            'pointssystem'          => $pointssystem,
            'corepointsrequired'    => $corepointsrequired,
            'electivepointsrequired'=> $electivepointsrequired,
            'units'                 => $unitdata,
            'moodlecategories'      => $moodlecats,
            'moodlecourses'         => $moodlecourses,
            'unitcodemap'           => $unitcodemap,
        ];
    }

    public static function tga_get_builder_data_returns() {
        return new external_single_structure([
            'success'          => new external_value(PARAM_BOOL, 'Success'),
            'error'            => new external_value(PARAM_TEXT, 'Error message'),
            'qualification'    => new external_value(PARAM_RAW, 'Qualification JSON {code,title,type,aqfLevel}'),
            'packagingrules'   => new external_value(PARAM_RAW, 'Rules text JSON array'),
            'totalunits'       => new external_value(PARAM_INT, 'Total units required'),
            'corerequired'     => new external_value(PARAM_INT, 'Core units required'),
            'electiverequired' => new external_value(PARAM_INT, 'Elective units required'),
            'grouprules'       => new external_value(PARAM_RAW, 'Group requirements JSON {A:{min,max},...}'),
            'pointsrequired'        => new external_value(PARAM_INT, 'Credit points required (0 = not a points-based qual)'),
            'pointssystem'          => new external_value(PARAM_INT, '1 = qualification uses credit points system'),
            'corepointsrequired'    => new external_value(PARAM_INT, 'Sum of core unit credit points (minimum core pts)'),
            'electivepointsrequired'=> new external_value(PARAM_INT, 'Elective pts required = pointsrequired - corepointsrequired'),
            'units'            => new external_multiple_structure(
                new external_single_structure([
                    'unitcode'      => new external_value(PARAM_TEXT, 'Unit code'),
                    'unitname'      => new external_value(PARAM_TEXT, 'Unit name'),
                    'iscore'        => new external_value(PARAM_INT, '1=core, 0=elective'),
                    'electivegroup' => new external_value(PARAM_TEXT, 'Group code A-Y or empty'),
                    'grouplabel'    => new external_value(PARAM_TEXT, 'Group label from TGA'),
                    'nominalhours'  => new external_value(PARAM_INT, 'Nominal hours'),
                    'creditpoints'  => new external_value(PARAM_INT, 'Credit point value (0 if not points-based)'),
                ])
            ),
            'moodlecategories' => new external_multiple_structure(
                new external_single_structure([
                    'id'       => new external_value(PARAM_INT,  'Category ID'),
                    'name'     => new external_value(PARAM_TEXT, 'Category name'),
                    'parent'   => new external_value(PARAM_INT,  'Parent category ID (0 = root)'),
                    'idnumber' => new external_value(PARAM_TEXT, 'Category idnumber (qual code on VET roots)'),
                ])
            ),
            'moodlecourses'    => new external_multiple_structure(
                new external_single_structure([
                    'id'        => new external_value(PARAM_INT,  'Course ID'),
                    'shortname' => new external_value(PARAM_TEXT, 'Shortname'),
                    'fullname'  => new external_value(PARAM_TEXT, 'Full name'),
                    'category'  => new external_value(PARAM_INT,  'Immediate parent category ID'),
                    'sortorder' => new external_value(PARAM_INT,  'Moodle sortorder — used to match unit list order to the Manage Courses page'),
                    'rootcatid' => new external_value(PARAM_INT,  'Depth-0 ancestor category ID'),
                ])
            ),
            'unitcodemap'      => new external_multiple_structure(
                new external_single_structure([
                    'unitcode'  => new external_value(PARAM_TEXT, 'VET unit code extracted from course name/idnumber'),
                    'courseid'  => new external_value(PARAM_INT,  'Course ID'),
                    'category'  => new external_value(PARAM_INT,  'Category ID (semester)'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
                ])
            ),
        ]);
    }

    // =====================================================================
    // qualbuilder_auto_build — atomic save: product metadata + all units
    // =====================================================================

    public static function qualbuilder_auto_build_parameters() {
        return new external_function_parameters([
            'qualbuilderid'     => new external_value(PARAM_INT,          'QB record ID (0 = create)', VALUE_DEFAULT, 0),
            'producttype'       => new external_value(PARAM_ALPHA,        'qualification|skillset|singleunit'),
            'qualificationcode' => new external_value(PARAM_ALPHANUMEXT,  'Qualification code'),
            'qualificationname' => new external_value(PARAM_TEXT,         'Qualification name'),
            'aqflevel'          => new external_value(PARAM_INT,          'AQF level 1-10',  VALUE_DEFAULT, 0),
            'categoryid'        => new external_value(PARAM_INT,          'Moodle category', VALUE_DEFAULT, 0),
            'nominalhours'      => new external_value(PARAM_INT,          'Nominal hours',   VALUE_DEFAULT, 0),
            'status'            => new external_value(PARAM_ALPHA,        'draft|active|superseded', VALUE_DEFAULT, 'draft'),
            'totalunits'        => new external_value(PARAM_INT,          'Total units required',  VALUE_DEFAULT, 0),
            'coreunitcount'     => new external_value(PARAM_INT,          'Core units required',   VALUE_DEFAULT, 0),
            'electivecount'     => new external_value(PARAM_INT,          'Elective units required', VALUE_DEFAULT, 0),
            'electiverules'     => new external_value(PARAM_RAW,          'Elective rules JSON', VALUE_DEFAULT, ''),
            'units'             => new external_value(PARAM_RAW,          'Units JSON array', VALUE_DEFAULT, '[]'),
            'streamname'        => new external_value(PARAM_TEXT,         'Stream / variant name', VALUE_DEFAULT, ''),
        ]);
    }

    public static function qualbuilder_auto_build($qualbuilderid, $producttype, $qualificationcode,
        $qualificationname, $aqflevel, $categoryid, $nominalhours, $status,
        $totalunits, $coreunitcount, $electivecount, $electiverules, $units, $streamname = '') {

        global $DB, $USER;

        $params = self::validate_parameters(self::qualbuilder_auto_build_parameters(), [
            'qualbuilderid'     => $qualbuilderid,
            'producttype'       => $producttype,
            'qualificationcode' => $qualificationcode,
            'qualificationname' => $qualificationname,
            'aqflevel'          => $aqflevel,
            'categoryid'        => $categoryid,
            'nominalhours'      => $nominalhours,
            'status'            => $status,
            'totalunits'        => $totalunits,
            'coreunitcount'     => $coreunitcount,
            'electivecount'     => $electivecount,
            'electiverules'     => $electiverules,
            'units'             => $units,
            'streamname'        => $streamname,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);

        $now = time();

        // Build the qualbuilder record
        $record = new \stdClass();
        $record->producttype       = $params['producttype'];
        $record->qualificationcode = strtoupper(trim($params['qualificationcode']));
        $record->qualificationname = trim($params['qualificationname']);
        $record->aqflevel          = $params['aqflevel'] ?: null;
        $record->categoryid        = $params['categoryid'] ?: null;
        $record->nominalhours      = $params['nominalhours'] ?: null;
        $record->status            = $params['status'];
        $record->totalunits        = (int)$params['totalunits'];
        $record->coreunitcount     = (int)$params['coreunitcount'];
        $record->electivecount     = (int)$params['electivecount'];
        $record->electiverules     = !empty($params['electiverules']) ? $params['electiverules'] : null;
        $record->streamname        = trim($params['streamname'] ?? '');
        $record->validationpassed  = 0;
        $record->timemodified      = $now;

        if ($params['qualbuilderid'] > 0) {
            $record->id = $params['qualbuilderid'];
            $DB->update_record('local_rtocompliance_qualbuilder', $record);
            $qualbuilderid = $record->id;
        } else {
            $record->timecreated = $now;
            $record->createdby   = $USER->id;
            $qualbuilderid = $DB->insert_record('local_rtocompliance_qualbuilder', $record);
        }

        // ── Best-practice sync: qualmap + category.idnumber ──────────────────────────
        // Saving a qualbuilder record with a categoryid is an explicit admin declaration
        // that this qualification lives in that Moodle category. Propagate it immediately
        // so both downstream systems are in sync without requiring manual extra steps:
        //
        //   (a) local_rtocompliance_qualmap  → reconciler finds the qual root on the
        //       very next run; no category-name discovery pass needed.
        //   (b) mdl_course_categories.idnumber → SOA engine can identify the
        //       qualification from its category; also powers the reconciler's new
        //       category_idnumber discovery path (highest confidence).
        //
        // Rules: never overwrite a human 'manual' qualmap entry; never overwrite an
        // idnumber that the admin has already set to something else.
        if (!empty($record->categoryid) && !empty($record->qualificationcode)) {
            $qmExist  = $DB->get_record('local_rtocompliance_qualmap',
                ['qualcode' => $record->qualificationcode], 'id,method', IGNORE_MISSING);
            $qmCatName = $DB->get_field('course_categories', 'name',
                ['id' => $record->categoryid]) ?: '';

            if (!$qmExist) {
                $DB->insert_record('local_rtocompliance_qualmap', (object)[
                    'qualcode'     => $record->qualificationcode,
                    'categoryid'   => $record->categoryid,
                    'catname'      => $qmCatName,
                    'confidence'   => 100,
                    'method'       => 'qualbuilder',
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ]);
            } elseif ($qmExist->method !== 'manual') {
                $DB->update_record('local_rtocompliance_qualmap', (object)[
                    'id'           => $qmExist->id,
                    'categoryid'   => $record->categoryid,
                    'catname'      => $qmCatName,
                    'confidence'   => 100,
                    'method'       => 'qualbuilder',
                    'timemodified' => $now,
                ]);
            }

            // Set category.idnumber if currently blank — never overwrite an existing value.
            $catIdn = $DB->get_field('course_categories', 'idnumber', ['id' => $record->categoryid]);
            if ($catIdn === '' || $catIdn === null) {
                $DB->set_field('course_categories', 'idnumber',
                    $record->qualificationcode, ['id' => $record->categoryid]);
            }
        }

        // Replace all units (and their variant course links)
        $unitsdata = json_decode($params['units'], true);
        if (is_array($unitsdata) && !empty($unitsdata)) {
            // Delete variant course links before deleting units (no FK cascade in Moodle).
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_rtocompliance_qualunit_courses')) {
                $oldIds = $DB->get_fieldset_select('local_rtocompliance_qualunits',
                    'id', 'qualbuilderid = :qbid', ['qbid' => $qualbuilderid]);
                if ($oldIds) {
                    list($insql, $inparams) = $DB->get_in_or_equal($oldIds);
                    $DB->delete_records_select('local_rtocompliance_qualunit_courses',
                        "qualunitid $insql", $inparams);
                }
            }
            $DB->delete_records('local_rtocompliance_qualunits', ['qualbuilderid' => $qualbuilderid]);
            $seq = 0;
            foreach ($unitsdata as $u) {
                $unitcode = strtoupper(trim($u['unitcode'] ?? ''));
                if (empty($unitcode)) continue;
                $urec = new \stdClass();
                $urec->qualbuilderid  = $qualbuilderid;
                $urec->unitcode       = $unitcode;
                $urec->unitname       = trim($u['unitname'] ?? '');
                $urec->unittype       = $u['unittype'] ?? 'elective';
                $urec->electivegroup  = !empty($u['electivegroup']) ? $u['electivegroup'] : null;
                $urec->nominalhours   = !empty($u['nominalhours']) ? (int)$u['nominalhours'] : null;
                $urec->courseid       = !empty($u['courseid']) ? (int)$u['courseid'] : null;
                $urec->creditpoints   = !empty($u['creditpoints']) ? (int)$u['creditpoints'] : 0;
                $urec->sequenceorder  = $seq++;
                $urec->selected       = 1;
                $urec->status         = 'active';
                $urec->timecreated    = $now;
                $urec->timemodified   = $now;
                $newUnitId = $DB->insert_record('local_rtocompliance_qualunits', $urec);

                // QB-VARIANTS: save each variant course to qualunit_courses (is_archive=0).
                // These are additional Moodle courses that deliver the same TGA unit
                // (e.g. teacher cohort variants CD / EL / ND for the same unit code).
                // The reconciler watches all of them so students in any variant get their
                // AVETMISS enrolment recorded and their cert fires correctly.
                if ($dbman->table_exists('local_rtocompliance_qualunit_courses') &&
                        !empty($u['variants']) && is_array($u['variants'])) {
                    foreach ($u['variants'] as $variantCourseid) {
                        $variantCourseid = (int)$variantCourseid;
                        if ($variantCourseid <= 0) continue;
                        $quc = new \stdClass();
                        $quc->qualunitid     = $newUnitId;
                        $quc->courseid       = $variantCourseid;
                        $quc->semester_label = '';
                        $quc->is_archive     = 0;
                        $quc->timecreated    = $now;
                        try {
                            $DB->insert_record('local_rtocompliance_qualunit_courses', $quc, false);
                        } catch (\Exception $e) {
                            // Unique constraint hit (duplicate) — skip silently.
                        }
                    }
                }
            }
        }

        audit_logger::log_update(
            'qualbuilder', $qualbuilderid,
            'Smart builder saved: ' . $record->qualificationcode,
            null,
            ['code' => $record->qualificationcode, 'units_count' => count($unitsdata ?? [])]
        );

        return [
            'success'       => true,
            'qualbuilderid' => (int)$qualbuilderid,
            'message'       => 'Qualification saved successfully',
        ];
    }

    public static function qualbuilder_auto_build_returns() {
        return new external_single_structure([
            'success'       => new external_value(PARAM_BOOL, 'Success'),
            'qualbuilderid' => new external_value(PARAM_INT,  'Qualification builder ID'),
            'message'       => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }

    // get_courses_for_category — lightweight endpoint: returns just the Moodle course list
    // for a qualification category subtree.  Called by JS when QB.courses is empty on
    // page load (editing an existing record without reloading TGA) or when the Map All
    // button is clicked before TGA has been fetched this session.
    // Uses the same BFS + rootcatid logic as tga_get_builder_data so pool filtering is identical.
    public static function get_courses_for_category_parameters() {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT,  'Qualification root category ID'),
            'unitcodes'  => new external_value(PARAM_TEXT, 'Comma-separated unit codes to filter cross-package supplement scan (optional)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_courses_for_category($categoryid, $unitcodes = '') {
        global $DB;
        $params = self::validate_parameters(
            self::get_courses_for_category_parameters(),
            ['categoryid' => $categoryid, 'unitcodes' => $unitcodes]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rtocompliance:manage', $context);

        $categoryid = (int)$params['categoryid'];

        // Parse the optional unit-code filter list passed by the JS.
        // When non-empty, the supplement scan is scoped to these codes only,
        // giving the same lean, targeted payload as tga_get_builder_data.
        // When empty (page load before TGA fetch, or old JS), the supplement
        // falls back to adding any course with a recognisable unit-code pattern.
        $filterRaw   = trim($params['unitcodes'] ?? '');
        $filterCodes = [];
        if ($filterRaw !== '') {
            foreach (explode(',', $filterRaw) as $fc) {
                $fc = trim(strtoupper($fc));
                if (strlen($fc) >= 6) { $filterCodes[] = $fc; }
            }
        }

        // Build catParentMap for BFS traversal.
        $catRs = $DB->get_recordset_sql("SELECT id, parent FROM {course_categories}");
        $catParentMap = [];
        foreach ($catRs as $cat) {
            $catParentMap[(int)$cat->id] = (int)$cat->parent;
        }
        $catRs->close();

        $moodlecourses = [];
        if ($categoryid > 0) {
            // BFS from $categoryid — identical to tga_get_builder_data.
            $subtreeCatIds = [(int)$categoryid];
            $bfsQueue      = [(int)$categoryid];
            while (!empty($bfsQueue)) {
                $bfsCurrent = array_shift($bfsQueue);
                foreach ($catParentMap as $_cid => $_pid) {
                    if ((int)$_pid === $bfsCurrent && !in_array((int)$_cid, $subtreeCatIds, true)) {
                        $subtreeCatIds[] = (int)$_cid;
                        $bfsQueue[]      = (int)$_cid;
                    }
                }
            }
            list($inSql, $inParams) = $DB->get_in_or_equal($subtreeCatIds, SQL_PARAMS_NAMED, 'qbcat');
            $courseRs = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder
                   FROM {course}
                  WHERE id > 1 AND category $inSql
                  ORDER BY sortorder ASC",
                $inParams
            );
        } else {
            $courseRs = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder
                   FROM {course} WHERE id > 1 ORDER BY sortorder ASC LIMIT 2000"
            );
        }
        $courseIdnumbers = [];
        foreach ($courseRs as $c) {
            $moodlecourses[] = [
                'id'        => (int)$c->id,
                'shortname' => $c->shortname,
                'fullname'  => $c->fullname,
                'category'  => (int)$c->category,
                'sortorder' => (int)$c->sortorder,
                'rootcatid' => $categoryid > 0 ? $categoryid : 0,
            ];
            $courseIdnumbers[(int)$c->id] = (string)($c->idnumber ?? '');
        }
        $courseRs->close();

        // Build unit-code map — same extraction logic as tga_get_builder_data.
        $unitcodemap = [];
        $ucPattern   = '/\b([A-Z]{2,7}[0-9]{3,6}[A-Z]?)\b/';
        foreach ($moodlecourses as $mc) {
            $idn        = $courseIdnumbers[$mc['id']] ?? '';
            $searchText = strtoupper(
                ($idn ? $idn . ' ' : '') . $mc['shortname'] . ' ' . $mc['fullname']
            );
            if (preg_match_all($ucPattern, $searchText, $codeMatches)) {
                foreach (array_unique($codeMatches[1]) as $unitcode) {
                    if (strlen($unitcode) >= 6) {
                        $unitcodemap[] = [
                            'unitcode'  => $unitcode,
                            'courseid'  => $mc['id'],
                            'category'  => $mc['category'],
                            'shortname' => $mc['shortname'],
                        ];
                    }
                }
            }
        }

        // CROSS-PACKAGE-FIX (v5.9.266 → v5.9.267): site-wide supplement scan.
        // When $filterCodes is populated (JS passed QB.tgaUnits / QB.currentUnits),
        // only courses whose name/idnumber contains one of those exact unit codes are
        // added — identical to the filter in tga_get_builder_data, giving a lean
        // targeted payload.  When $filterCodes is empty (old JS or pre-TGA page load),
        // fall back to accepting any course with a recognisable unit-code pattern so
        // the supplement still works without a filter list.
        // rootcatid=0 marks all supplement courses as cross-package for the JS fallback.
        if ($categoryid > 0) {
            $subtreeCourseIds2 = array_column($moodlecourses, 'id');
            $crossRs2 = $DB->get_recordset_sql(
                "SELECT id, shortname, fullname, category, idnumber, sortorder
                   FROM {course} WHERE id > 1 ORDER BY sortorder ASC LIMIT 3000"
            );
            $crossAdded2 = [];
            foreach ($crossRs2 as $cc2) {
                if (in_array((int)$cc2->id, $subtreeCourseIds2, true)) { continue; }
                $idn3    = trim((string)($cc2->idnumber ?? ''));
                $srcTxt2 = strtoupper(($idn3 ? $idn3 . ' ' : '') . $cc2->shortname . ' ' . $cc2->fullname);
                if (!preg_match_all($ucPattern, $srcTxt2, $cm3)) { continue; }
                $allCodes = [];
                foreach (array_unique($cm3[1]) as $c3) {
                    if (strlen($c3) >= 6) { $allCodes[] = $c3; }
                }
                if (empty($allCodes)) { continue; }

                // Apply filter: if a filter list was supplied, keep only codes on it.
                // If no filter list, keep all codes (unfiltered fallback).
                $matched3 = empty($filterCodes)
                    ? $allCodes
                    : array_values(array_intersect($allCodes, $filterCodes));
                if (empty($matched3)) { continue; }

                foreach ($matched3 as $mc3) {
                    $unitcodemap[] = [
                        'unitcode'  => $mc3,
                        'courseid'  => (int)$cc2->id,
                        'category'  => (int)$cc2->category,
                        'shortname' => $cc2->shortname,
                    ];
                }
                if (!in_array((int)$cc2->id, $crossAdded2, true)) {
                    $moodlecourses[] = [
                        'id'        => (int)$cc2->id,
                        'shortname' => $cc2->shortname,
                        'fullname'  => $cc2->fullname,
                        'category'  => (int)$cc2->category,
                        'sortorder' => (int)$cc2->sortorder,
                        'rootcatid' => 0,
                    ];
                    $crossAdded2[] = (int)$cc2->id;
                }
            }
            $crossRs2->close();
        }

        return ['courses' => $moodlecourses, 'unitcodemap' => $unitcodemap];
    }

    public static function get_courses_for_category_returns() {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id'        => new external_value(PARAM_INT,  'Course ID'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
                    'fullname'  => new external_value(PARAM_TEXT, 'Course fullname'),
                    'category'  => new external_value(PARAM_INT,  'Category ID'),
                    'sortorder' => new external_value(PARAM_INT,  'Moodle sortorder'),
                    'rootcatid' => new external_value(PARAM_INT,  'Root qual category ID'),
                ])
            ),
            'unitcodemap' => new external_multiple_structure(
                new external_single_structure([
                    'unitcode'  => new external_value(PARAM_TEXT, 'VET unit code'),
                    'courseid'  => new external_value(PARAM_INT,  'Course ID'),
                    'category'  => new external_value(PARAM_INT,  'Category ID'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course shortname'),
                ])
            ),
        ]);
    }
}
