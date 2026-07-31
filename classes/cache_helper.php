<?php
namespace local_rtocompliance;

defined('MOODLE_INTERNAL') || die();

class cache_helper {
    
    private static $table_exists_cache = [];
    
    const METRICS_TTL_SECONDS = 300;
    const PROFILE_CACHE_TTL = 3600;
    
    private static function table_exists($tablename) {
        global $DB;
        
        if (!isset(self::$table_exists_cache[$tablename])) {
            self::$table_exists_cache[$tablename] = $DB->get_manager()->table_exists($tablename);
        }
        return self::$table_exists_cache[$tablename];
    }
    
    public static function get_dashboard_metrics() {
        global $DB;
        
        $cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
        $key = 'dashboard_metrics_v2';
        
        $metrics = $cache->get($key);
        if ($metrics !== false && is_array($metrics)) {
            $age = time() - ($metrics['cached_at'] ?? 0);
            if ($age < self::METRICS_TTL_SECONDS) {
                return $metrics;
            }
        }
        
        if (self::table_exists('local_rtocompliance_metrics')) {
            $rows = $DB->get_records('local_rtocompliance_metrics');
            if (!empty($rows)) {
                $metrics = self::build_metrics_from_table($rows);
                $oldest = PHP_INT_MAX;
                foreach ($rows as $row) {
                    if ($row->timecomputed < $oldest) {
                        $oldest = $row->timecomputed;
                    }
                }
                $metrics['cached_at'] = $oldest;
                
                $hasDirty = false;
                foreach ($rows as $row) {
                    if ($row->dirty) {
                        $hasDirty = true;
                        break;
                    }
                }
                
                if (!$hasDirty) {
                    $cache->set($key, $metrics);
                    return $metrics;
                }
            }
        }
        
        $metrics = self::calculate_dashboard_metrics();
        $cache->set($key, $metrics);
        self::persist_metrics_to_table($metrics);
        return $metrics;
    }
    
    private static function build_metrics_from_table($rows) {
        $metrics = [
            'total_students' => 0,
            'complete_profiles' => 0,
            'missing_usi' => 0,
            'active_enrolments' => 0,
            'completed_enrolments' => 0,
            'active_trainers' => 0,
            'noncompliant_trainers' => 0,
            'issued_certs' => 0,
            'pending_certs' => 0,
            'active_alerts' => 0,
        ];
        
        foreach ($rows as $row) {
            if (isset($metrics[$row->metrickey])) {
                $metrics[$row->metrickey] = (int)$row->metricvalue;
            }
        }
        
        return $metrics;
    }
    
    private static function persist_metrics_to_table($metrics) {
        global $DB;
        
        if (!self::table_exists('local_rtocompliance_metrics')) {
            return;
        }
        
        $now = time();
        $keys = ['total_students', 'complete_profiles', 'missing_usi', 'active_enrolments',
                 'completed_enrolments', 'active_trainers', 'noncompliant_trainers',
                 'issued_certs', 'pending_certs', 'active_alerts'];
        
        foreach ($keys as $key) {
            $value = $metrics[$key] ?? 0;
            $existing = $DB->get_record('local_rtocompliance_metrics', ['metrickey' => $key]);
            
            if ($existing) {
                $existing->metricvalue = $value;
                $existing->timecomputed = $now;
                $existing->dirty = 0;
                $DB->update_record('local_rtocompliance_metrics', $existing);
            } else {
                $record = new \stdClass();
                $record->metrickey = $key;
                $record->metricvalue = $value;
                $record->timecomputed = $now;
                $record->dirty = 0;
                $DB->insert_record('local_rtocompliance_metrics', $record);
            }
        }
    }
    
    public static function mark_metrics_dirty() {
        global $DB;
        
        if (!self::table_exists('local_rtocompliance_metrics')) {
            return;
        }
        
        $DB->execute("UPDATE {local_rtocompliance_metrics} SET dirty = 1");
    }
    
    public static function invalidate_dashboard_metrics() {
        self::mark_metrics_dirty();
    }
    
    private static function calculate_dashboard_metrics() {
        global $DB;
        
        $metrics = [
            'total_students' => 0,
            'complete_profiles' => 0,
            'missing_usi' => 0,
            'active_enrolments' => 0,
            'completed_enrolments' => 0,
            'active_trainers' => 0,
            'noncompliant_trainers' => 0,
            'issued_certs' => 0,
            'pending_certs' => 0,
            'active_alerts' => 0,
            'cached_at' => time(),
        ];
        
        if (self::table_exists('local_rtocompliance_students')) {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN profilecomplete = 1 THEN 1 ELSE 0 END) as complete,
                        SUM(CASE WHEN usi IS NULL OR usi = '' THEN 1 ELSE 0 END) as missing_usi
                    FROM {local_rtocompliance_students}";
            $row = $DB->get_record_sql($sql);
            if ($row) {
                $metrics['total_students'] = (int)$row->total;
                $metrics['complete_profiles'] = (int)$row->complete;
                $metrics['missing_usi'] = (int)$row->missing_usi;
            }
        }
        
        if (self::table_exists('local_rtocompliance_enrolments')) {
            $sql = "SELECT 
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                    FROM {local_rtocompliance_enrolments}";
            $row = $DB->get_record_sql($sql);
            if ($row) {
                $metrics['active_enrolments'] = (int)$row->active;
                $metrics['completed_enrolments'] = (int)$row->completed;
            }
        }
        
        if (self::table_exists('local_rtocompliance_trainers')) {
            $sql = "SELECT 
                        SUM(CASE WHEN status = 'current' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN compliancestatus = 'noncompliant' THEN 1 ELSE 0 END) as noncompliant
                    FROM {local_rtocompliance_trainers}";
            $row = $DB->get_record_sql($sql);
            if ($row) {
                $metrics['active_trainers'] = (int)$row->active;
                $metrics['noncompliant_trainers'] = (int)$row->noncompliant;
            }
        }
        
        if (self::table_exists('local_rtocompliance_certs')) {
            $metrics['issued_certs'] = $DB->count_records('local_rtocompliance_certs', ['status' => 'issued']);
            
            $sql = "SELECT COUNT(DISTINCT cc.userid) as cnt
                    FROM {course_completions} cc 
                    LEFT JOIN {local_rtocompliance_certs} cert ON cert.userid = cc.userid
                    WHERE cc.timecompleted IS NOT NULL AND cert.id IS NULL";
            $metrics['pending_certs'] = (int)$DB->count_records_sql($sql);
        }
        
        if (self::table_exists('local_rtocompliance_ai_alerts')) {
            $metrics['active_alerts'] = $DB->count_records_select(
                'local_rtocompliance_ai_alerts',
                "status IN ('active', 'new')"
            );
        }
        
        return $metrics;
    }
    
    public static function get_user_certificate_count($userid) {
        global $DB;
        
        $cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
        $key = 'user_certs_' . $userid;
        
        $count = $cache->get($key);
        if ($count !== false) {
            return (int)$count;
        }
        
        if (!self::table_exists('local_rtocompliance_certs')) {
            return 0;
        }
        
        $count = $DB->count_records('local_rtocompliance_certs', ['userid' => $userid, 'status' => 'issued']);
        $cache->set($key, $count);
        return $count;
    }
    
    public static function invalidate_user_certificate_count($userid) {
        $cache = \cache::make('local_rtocompliance', 'dashboard_metrics');
        $cache->delete('user_certs_' . $userid);
    }
    
    public static function get_course_settings($courseid) {
        $cache = \cache::make('local_rtocompliance', 'course_settings');
        $key = 'course_' . $courseid;
        
        $settings = $cache->get($key);
        if ($settings !== false) {
            return $settings ?: null;
        }
        
        global $DB;
        
        if (!self::table_exists('local_rtocompliance_courses')) {
            $cache->set($key, '');
            return null;
        }
        
        $settings = $DB->get_record('local_rtocompliance_courses', ['courseid' => $courseid]);
        $cache->set($key, $settings ?: '');
        return $settings ?: null;
    }
    
    public static function invalidate_course_settings($courseid) {
        $cache = \cache::make('local_rtocompliance', 'course_settings');
        $cache->delete('course_' . $courseid);
    }
    
    public static function get_compliance_summary() {
        $cache = \cache::make('local_rtocompliance', 'compliance_summary');
        $key = 'summary';
        
        $summary = $cache->get($key);
        if ($summary !== false) {
            return $summary;
        }
        
        global $DB;
        
        $critical = 0;
        $high = 0;
        $medium = 0;
        $low = 0;
        
        if (self::table_exists('local_rtocompliance_ai_alerts')) {
            $alertcounts = $DB->get_records_sql(
                "SELECT severity, COUNT(*) as cnt 
                 FROM {local_rtocompliance_ai_alerts} 
                 WHERE status IN ('active', 'new', 'acknowledged') 
                 GROUP BY severity"
            );
            
            foreach ($alertcounts as $row) {
                switch ($row->severity) {
                    case 'critical': $critical = (int)$row->cnt; break;
                    case 'high': $high = (int)$row->cnt; break;
                    case 'medium': $medium = (int)$row->cnt; break;
                    case 'low': $low = (int)$row->cnt; break;
                }
            }
        }
        
        $overallstatus = 'healthy';
        if ($critical > 0) {
            $overallstatus = 'critical';
        } elseif ($high > 0) {
            $overallstatus = 'warning';
        } elseif ($medium > 0) {
            $overallstatus = 'attention';
        }
        
        $summary = [
            'overall_status' => $overallstatus,
            'critical_count' => $critical,
            'high_count' => $high,
            'medium_count' => $medium,
            'low_count' => $low,
            'total_alerts' => $critical + $high + $medium + $low,
            'cached_at' => time(),
        ];
        
        $cache->set($key, $summary);
        return $summary;
    }
    
    public static function invalidate_compliance_summary() {
        $cache = \cache::make('local_rtocompliance', 'compliance_summary');
        $cache->purge();
    }
}
