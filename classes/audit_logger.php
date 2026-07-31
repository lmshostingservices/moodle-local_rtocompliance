<?php
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class audit_logger {

    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_VIEW = 'view';
    const ACTION_EXPORT = 'export';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';

    const ENTITY_STUDENT = 'student';
    const ENTITY_ENROLMENT = 'enrolment';
    const ENTITY_TRAINER = 'trainer';
    const ENTITY_CERTIFICATE = 'certificate';
    const ENTITY_NAT_EXPORT = 'nat_export';
    const ENTITY_TAS = 'tas';
    const ENTITY_VALIDATION = 'validation';
    const ENTITY_GOVERNANCE = 'governance';
    const ENTITY_THIRDPARTY = 'thirdparty';
    const ENTITY_DEADLINE = 'deadline';
    const ENTITY_ALERT = 'alert';
    const ENTITY_SURVEY = 'survey';
    const ENTITY_FEE = 'fee';

    public static function log(
        string $action,
        string $entitytype,
        int $entityid = 0,
        ?int $userid = null,
        ?string $description = null,
        ?array $oldata = null,
        ?array $newdata = null,
        ?string $ipaddress = null
    ): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->action = $action;
        $record->entitytype = $entitytype;
        $record->entityid = $entityid;
        $record->userid = $userid ?? ($USER->id ?? 0);
        $record->description = $description;
        $record->olddata = $oldata ? json_encode($oldata) : null;
        $record->newdata = $newdata ? json_encode($newdata) : null;
        $record->ipaddress = $ipaddress ?? self::get_client_ip();
        $record->useragent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $record->timecreated = time();

        return $DB->insert_record('local_rtocompliance_audit', $record);
    }

    public static function log_create(string $entitytype, int $entityid, ?string $description = null, ?array $newdata = null): int {
        return self::log(self::ACTION_CREATE, $entitytype, $entityid, null, $description, null, $newdata);
    }

    public static function log_update(string $entitytype, int $entityid, ?string $description = null, ?array $olddata = null, ?array $newdata = null): int {
        return self::log(self::ACTION_UPDATE, $entitytype, $entityid, null, $description, $olddata, $newdata);
    }

    public static function log_delete(string $entitytype, int $entityid, ?string $description = null, ?array $olddata = null): int {
        return self::log(self::ACTION_DELETE, $entitytype, $entityid, null, $description, $olddata, null);
    }

    public static function log_view(string $entitytype, int $entityid = 0, ?string $description = null): int {
        return self::log(self::ACTION_VIEW, $entitytype, $entityid, null, $description);
    }

    public static function log_export(string $entitytype, int $entityid = 0, ?string $description = null, ?array $exportdata = null): int {
        return self::log(self::ACTION_EXPORT, $entitytype, $entityid, null, $description, null, $exportdata);
    }

    public static function get_logs(array $filters = [], int $limit = 100, int $offset = 0): array {
        global $DB;

        $where = [];
        $params = [];

        if (!empty($filters['entitytype'])) {
            $where[] = 'a.entitytype = ?';
            $params[] = $filters['entitytype'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }

        if (!empty($filters['userid'])) {
            $where[] = 'a.userid = ?';
            $params[] = $filters['userid'];
        }

        if (!empty($filters['entityid'])) {
            $where[] = 'a.entityid = ?';
            $params[] = $filters['entityid'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'a.timecreated >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'a.timecreated <= ?';
            $params[] = $filters['to'];
        }

        $wheresql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT a.*, u.firstname, u.lastname
                FROM {local_rtocompliance_audit} a
                LEFT JOIN {user} u ON u.id = a.userid
                $wheresql
                ORDER BY a.timecreated DESC";

        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    public static function get_entity_history(string $entitytype, int $entityid, int $limit = 50): array {
        return self::get_logs(['entitytype' => $entitytype, 'entityid' => $entityid], $limit);
    }

    public static function get_user_activity(int $userid, int $limit = 100): array {
        return self::get_logs(['userid' => $userid], $limit);
    }

    public static function get_recent_activity(int $limit = 100): array {
        return self::get_logs([], $limit);
    }

    public static function cleanup_old_logs(int $retentiondays = 730): int {
        global $DB;

        $threshold = time() - ($retentiondays * 86400);

        return $DB->delete_records_select('local_rtocompliance_audit', 'timecreated < ?', [$threshold]);
    }

    private static function get_client_ip(): string {
        // Bug D fix: The previous implementation trusted HTTP_CF_CONNECTING_IP,
        // HTTP_X_FORWARDED_FOR, and HTTP_X_REAL_IP unconditionally -- any client
        // can set these headers and forge their IP in the audit trail.
        // Moodle's getremoteaddr() correctly uses REMOTE_ADDR by default and only
        // reads forwarded-for headers when Moodle's own reverse-proxy settings are
        // configured by the site administrator ($CFG->reverseproxy).
        // This makes the recorded IP authoritative and non-spoofable for standard
        // deployments, while still working correctly behind a configured proxy.
        $ip = getremoteaddr('0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function format_action_label(string $action): string {
        $labels = [
            self::ACTION_CREATE => 'Created',
            self::ACTION_UPDATE => 'Updated',
            self::ACTION_DELETE => 'Deleted',
            self::ACTION_VIEW => 'Viewed',
            self::ACTION_EXPORT => 'Exported',
            self::ACTION_APPROVE => 'Approved',
            self::ACTION_REJECT => 'Rejected',
        ];

        return $labels[$action] ?? ucfirst($action);
    }

    public static function format_entity_label(string $entitytype): string {
        $labels = [
            self::ENTITY_STUDENT => 'Student Profile',
            self::ENTITY_ENROLMENT => 'Enrolment',
            self::ENTITY_TRAINER => 'Trainer/Assessor',
            self::ENTITY_CERTIFICATE => 'Certificate',
            self::ENTITY_NAT_EXPORT => 'AVETMISS Export',
            self::ENTITY_TAS => 'Training & Assessment Strategy',
            self::ENTITY_VALIDATION => 'Validation Event',
            self::ENTITY_GOVERNANCE => 'Governance Record',
            self::ENTITY_THIRDPARTY => 'Third-Party Arrangement',
            self::ENTITY_DEADLINE => 'Regulatory Deadline',
            self::ENTITY_ALERT => 'Compliance Alert',
            self::ENTITY_SURVEY => 'QI Survey',
            self::ENTITY_FEE => 'Fee Record',
        ];

        return $labels[$entitytype] ?? ucfirst(str_replace('_', ' ', $entitytype));
    }
}
