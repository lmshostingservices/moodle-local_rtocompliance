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
 * RTO Compliance plugin - privacy provider.
 *
 * PRIVACY-COMPLETE (v6.3.28): the provider previously declared 11 of the plugin's
 * tables and deleted from 8. A seeded-user test showed an erasure request left 28
 * tables still holding the person's data - suitability assessments, declarations,
 * uploaded documents, support notes, fees, SoA snapshots, audit rows and more.
 *
 * Every table is now classified, and the classification decides what happens:
 *
 *   SUBJECT     the person IS the record (their student profile, their certificate,
 *               their complaint). Declared, exported and DELETED on erasure.
 *
 *   FOREIGN KEY reached from a subject record through studentid / trainerid /
 *               suitabilityid. Declared, exported and DELETED with its parent.
 *
 *   AUTHORSHIP  the person merely ACTED on someone else\'s compliance record - the
 *               "created by" stamp on a third-party arrangement, the "approved by" on
 *               a TAS. Declared and exported, but RETAINED: deleting another person\'s
 *               compliance record because the staff member who typed it exercised
 *               erasure would destroy evidence the RTO is legally required to keep
 *               under the Standards for RTOs and the NVETR Act. Retention here rests
 *               on that legal obligation, and the field identifies a staff action
 *               rather than describing the staff member.
 *
 * @package    local_rtocompliance
 * @copyright  2025 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_rtocompliance\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Tables where the person IS the record. Deleted on erasure.
     *
     * @return array table => list of columns holding a Moodle user id
     */
    protected static function subject_tables(): array {
        return [
            'local_rtocompliance_students' => ['userid'],
            'local_rtocompliance_trainers' => ['userid'],
            'local_rtocompliance_certs' => ['userid'],
            'local_rtocompliance_surveys' => ['respondentid'],
            'local_rtocompliance_log' => ['userid', 'targetuserid'],
            'local_rtocompliance_audit' => ['userid'],
            'local_rtocompliance_ai_alerts' => ['targetuserid'],
            'local_rtocompliance_cricos_students' => ['userid'],
            'local_rtocompliance_complaints' => ['complainantuserid'],
            'local_rtocompliance_appeals' => ['appellantuserid'],
            'local_rtocompliance_fees' => ['userid'],
            'local_rtocompliance_supportnotes' => ['userid'],
            'local_rtocompliance_validators' => ['userid'],
            'local_rtocompliance_suitability' => ['userid'],
            'local_rtocompliance_declarations' => ['userid'],
            'local_rtocompliance_soa_snapshot' => ['userid'],
            'local_rtocompliance_student_docs' => ['userid'],
            'local_rtocompliance_enrol_rollback' => ['userid'],
            'local_rtocompliance_foe_pending' => ['userid'],
            'local_rtocompliance_recov_candidate' => ['userid'],
            'local_rtocompliance_recov_action' => ['userid'],
        ];
    }

    /**
     * Tables recording that the person ACTED on someone else\'s compliance record.
     * Declared and exported, but retained on erasure - see the class docblock.
     *
     * @return array table => list of columns holding a Moodle user id
     */
    protected static function authorship_tables(): array {
        return [
            'local_rtocompliance_supervision' => ['createdby'],
            'local_rtocompliance_cricos_scv' => ['approvedby'],
            'local_rtocompliance_cricos_progress' => ['reviewedby'],
            'local_rtocompliance_improvements' => ['createdby'],
            'local_rtocompliance_thirdparty' => ['createdby'],
            'local_rtocompliance_govpersons' => ['createdby'],
            'local_rtocompliance_materialchanges' => ['createdby'],
            'local_rtocompliance_adc' => ['createdby'],
            'local_rtocompliance_insurance' => ['createdby'],
            'local_rtocompliance_transitions' => ['createdby'],
            'local_rtocompliance_validations' => ['createdby'],
            // NOTE: tas.approvedby is a varchar holding the approver's NAME, not a user
            // id - it is declared through metadata_only_columns() instead. Treating it
            // as a user id made PostgreSQL refuse the discovery query outright
            // ("operator does not exist: character varying > integer").
            'local_rtocompliance_tas' => ['createdby'],
            'local_rtocompliance_tas_consult' => ['createdby'],
            'local_rtocompliance_qualbuilder' => ['createdby'],
            'local_rtocompliance_certtmpl' => ['createdby', 'approvedby'],
        ];
    }

    /**
     * Tables reached from a subject record through a foreign key. Deleted with it.
     *
     * @return array table => ['column' => fk column, 'via' => parent table]
     */
    protected static function foreignkey_tables(): array {
        return [
            'local_rtocompliance_enrolments' => ['column' => 'studentid', 'via' => 'local_rtocompliance_students'],
            'local_rtocompliance_usilog' => ['column' => 'studentid', 'via' => 'local_rtocompliance_students'],
            'local_rtocompliance_autocerts' => ['column' => 'studentid', 'via' => 'local_rtocompliance_students'],
            'local_rtocompliance_rpl' => ['column' => 'studentid', 'via' => 'local_rtocompliance_students'],
            'local_rtocompliance_trainer_currency' => ['column' => 'trainerid', 'via' => 'local_rtocompliance_trainers'],
            'local_rtocompliance_trainer_voccomp' => ['column' => 'trainerid', 'via' => 'local_rtocompliance_trainers'],
            'local_rtocompliance_tas_trainers' => ['column' => 'trainerid', 'via' => 'local_rtocompliance_trainers'],
            'local_rtocompliance_suitability_answers' => ['column' => 'suitabilityid', 'via' => 'local_rtocompliance_suitability'],
        ];
    }

    /**
     * Columns that name a person but must not drive discovery or erasure.
     *
     * Two kinds live here:
     *
     *   - the value is not a user id at all. tas.approvedby is a varchar holding the
     *     approver's typed name, so there is nothing to match a user against.
     *   - the value IS a user id, but it is a staff member's stamp on a record that
     *     belongs to SOMEONE ELSE (who issued this student's certificate, who granted
     *     this student's USI exemption, who verified this trainer's currency). Erasing
     *     the staff member must not take the student's certificate with it, and the
     *     record itself is already erased with its own subject.
     *
     * They are declared so the site's privacy registry is honest about them; they are
     * simply not used to find or delete anyone.
     *
     * @return array table => list of columns
     */
    protected static function metadata_only_columns(): array {
        return [
            'local_rtocompliance_tas' => ['approvedby'],
            'local_rtocompliance_students' => ['usiexemptby'],
            'local_rtocompliance_certs' => ['issuedby'],
            'local_rtocompliance_soa_snapshot' => ['issuedby'],
            'local_rtocompliance_complaints' => ['assignedto', 'createdby', 'modifiedby'],
            'local_rtocompliance_appeals' => ['createdby'],
            'local_rtocompliance_adc' => ['submittedby'],
            'local_rtocompliance_fees' => ['createdby'],
            'local_rtocompliance_validators' => ['createdby'],
            'local_rtocompliance_trainer_currency' => ['verifiedby'],
            'local_rtocompliance_trainer_voccomp' => ['verifiedby'],
            'local_rtocompliance_supervision' => ['trainerid'],
            'local_rtocompliance_suitability' => ['trainerid'],
        ];
    }

    /**
     * Extra links from a subject table back to the student record.
     *
     * These tables are already subject tables through their own userid, but they also
     * carry a studentid. A row written with only the studentid set - which the CRICOS,
     * fees and support-note screens can do - would otherwise survive an erasure that
     * matched on userid alone.
     *
     * @return array table => column holding local_rtocompliance_students.id
     */
    protected static function secondary_student_links(): array {
        return [
            'local_rtocompliance_cricos_students' => 'studentid',
            'local_rtocompliance_fees' => 'studentid',
            'local_rtocompliance_supportnotes' => 'studentid',
        ];
    }

    /**
     * File areas holding personal uploads, and the table whose id is the itemid.
     *
     * Every area listed here is purged when the rows of its owning table go. Areas
     * belonging to authorship tables (supervision_evidence, consultation_evidence)
     * are deliberately absent: those records are retained, so their attachments are
     * too. Areas holding no personal data (certificate9b, the certificate-template
     * branding areas) are absent for the same reason they are not declared - they
     * describe a site, not a person.
     *
     * @return array filearea => owning table
     */
    protected static function personal_file_areas(): array {
        return [
            'rpl_evidence' => 'local_rtocompliance_rpl',
            'ct_sourcecert' => 'local_rtocompliance_rpl',
            'student_doc' => 'local_rtocompliance_student_docs',
            'trainer_evidence' => 'local_rtocompliance_trainer_currency',
            'trainer_voccomp_evidence' => 'local_rtocompliance_trainer_voccomp',
        ];
    }

    /**
     * Describe every category of personal data this plugin stores or sends.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        // Version 6.3.14: the AI assistant sends the staff member\'s question to the
        // lms-labs.com broker, and - when "Let the assistant see this site\'s data" is on -
        // a short read-only summary of this site with it. Turning the setting off removes it.
        $collection->add_external_location_link(
            'lms_labs_assistant',
            [
                'question'  => 'privacy:metadata:assistant:question',
                'sitefacts' => 'privacy:metadata:assistant:sitefacts',
            ],
            'privacy:metadata:assistant'
        );

        foreach (self::subject_tables() as $table => $columns) {
            $collection->add_database_table(
                $table, self::field_map($table, $columns), self::summary_key($table));
        }
        foreach (self::authorship_tables() as $table => $columns) {
            $collection->add_database_table(
                $table, self::field_map($table, $columns), self::summary_key($table));
        }
        foreach (self::foreignkey_tables() as $table => $link) {
            $collection->add_database_table(
                $table, self::field_map($table, [$link['column']]), self::summary_key($table));
        }

        // RPL-FILE-ERASURE (v5.9.416): RPL evidence and credit-transfer source
        // certificates are stored as files against rpl records.
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        return $collection;
    }

    /**
     * Build the column => language-string map a table declaration needs.
     *
     * @param string $table
     * @param array $columns
     * @return array
     */
    protected static function field_map(string $table, array $columns): array {
        $short = str_replace('local_rtocompliance_', '', $table);
        $columns = array_unique(array_merge(
            $columns,
            self::metadata_only_columns()[$table] ?? [],
            isset(self::secondary_student_links()[$table])
                ? [self::secondary_student_links()[$table]] : []));
        $map = [];
        foreach ($columns as $column) {
            $map[$column] = 'privacy:metadata:' . $short . ':' . $column;
        }
        return $map;
    }

    /**
     * The language string summarising a table.
     *
     * @param string $table
     * @return string
     */
    protected static function summary_key(string $table): string {
        return 'privacy:metadata:' . str_replace('local_rtocompliance_', '', $table);
    }

    /**
     * This plugin stores its data at system level only.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    /**
     * Everyone who has data in the system context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach (self::subject_tables() as $table => $columns) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $userlist->add_from_sql(
                    $column,
                    "SELECT DISTINCT $column FROM {" . $table . "} WHERE $column IS NOT NULL AND $column > 0",
                    []);
            }
        }
        foreach (self::authorship_tables() as $table => $columns) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $userlist->add_from_sql(
                    $column,
                    "SELECT DISTINCT $column FROM {" . $table . "} WHERE $column IS NOT NULL AND $column > 0",
                    []);
            }
        }
    }

    /**
     * Export everything held about this user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        $context = \context_system::instance();
        $root = get_string('pluginname', 'local_rtocompliance');

        foreach (self::subject_tables() as $table => $columns) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $rows = [];
            foreach ($columns as $column) {
                foreach ($DB->get_records($table, [$column => $user->id]) as $row) {
                    $rows[$row->id] = $row;
                }
            }
            self::export_rows($context, $root, $table, $rows);
        }

        foreach (self::foreignkey_tables() as $table => $link) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $parentids = self::parent_ids_for_user($link['via'], (int) $user->id);
            if (!$parentids) {
                continue;
            }
            list($insql, $params) = $DB->get_in_or_equal($parentids, SQL_PARAMS_NAMED, 'p');
            $rows = $DB->get_records_select($table, $link['column'] . ' ' . $insql, $params);
            self::export_rows($context, $root, $table, $rows);
        }

        // Authorship rows are exported too: the user is entitled to see the records
        // that carry their name, even though those records are retained.
        foreach (self::authorship_tables() as $table => $columns) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $rows = [];
            foreach ($columns as $column) {
                foreach ($DB->get_records($table, [$column => $user->id]) as $row) {
                    $rows[$row->id] = $row;
                }
            }
            self::export_rows($context, $root, $table, $rows, 'recorded_by_this_user');
        }
    }

    /**
     * Write one table\'s rows into the export.
     *
     * @param \context $context
     * @param string $root
     * @param string $table
     * @param array $rows
     * @param string|null $subfolder
     */
    protected static function export_rows(\context $context, string $root, string $table,
            array $rows, ?string $subfolder = null) {
        if (!$rows) {
            return;
        }
        $short = str_replace('local_rtocompliance_', '', $table);
        $path = $subfolder === null ? [$root, $short] : [$root, $subfolder, $short];
        writer::with_context($context)->export_data(
            $path, (object) [$short => array_values($rows)]);

        // Anything the person uploaded against these rows travels with the export.
        $areas = array_keys(array_filter(self::personal_file_areas(), fn($t) => $t === $table));
        foreach ($areas as $area) {
            foreach (array_keys($rows) as $itemid) {
                writer::with_context($context)->export_area_files(
                    array_merge($path, [$area]), 'local_rtocompliance', $area, (int) $itemid);
            }
        }
    }

    /**
     * Ids of a user\'s parent records (student / trainer / suitability) for FK lookups.
     *
     * @param string $parenttable
     * @param int $userid
     * @return array
     */
    protected static function parent_ids_for_user(string $parenttable, int $userid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists($parenttable)) {
            return [];
        }
        return $DB->get_fieldset_select($parenttable, 'id', 'userid = :uid', ['uid' => $userid]);
    }

    /**
     * Erase everything in the system context - every user, every subject table.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        // PRIVACY-COMPLETE (v6.3.28): this previously truncated only the log table.
        self::delete_all_personal_files();
        foreach (array_keys(self::secondary_student_links()) as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $DB->delete_records($table);
            }
        }
        foreach (array_keys(self::foreignkey_tables()) as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $DB->delete_records($table);
            }
        }
        foreach (array_keys(self::subject_tables()) as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $DB->delete_records($table);
            }
        }
    }

    /**
     * Erase one user\'s data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }
        self::delete_for_userids([(int) $contextlist->get_user()->id]);
    }

    /**
     * Erase data for a set of users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userids = array_map('intval', $userlist->get_userids());
        if ($userids) {
            self::delete_for_userids($userids);
        }
    }

    /**
     * The single erasure implementation used by both delete entry points.
     *
     * Order matters: foreign-key children are removed before the parent rows they
     * point at, otherwise the parent ids needed to find them are already gone.
     *
     * @param array $userids
     */
    protected static function delete_for_userids(array $userids) {
        global $DB;
        if (!$userids) {
            return;
        }
        list($usql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        // 1. Parent ids, gathered before anything is deleted.
        $parents = [];
        foreach (['local_rtocompliance_students', 'local_rtocompliance_trainers',
                  'local_rtocompliance_suitability'] as $parenttable) {
            if (!$DB->get_manager()->table_exists($parenttable)) {
                $parents[$parenttable] = [];
                continue;
            }
            $parents[$parenttable] = $DB->get_fieldset_select(
                $parenttable, 'id', "userid $usql", $uparams);
        }

        // 2. Uploaded personal files, found while the rows that own them still exist.
        //    Files attached to a child table are keyed by that child row's own id, so
        //    the ids have to be gathered before step 3 deletes the rows.
        foreach (array_unique(array_values(self::personal_file_areas())) as $owningtable) {
            if (!$DB->get_manager()->table_exists($owningtable)) {
                continue;
            }
            if (isset(self::subject_tables()[$owningtable])) {
                $itemids = $DB->get_fieldset_select($owningtable, 'id', "userid $usql", $uparams);
            } else if (isset(self::foreignkey_tables()[$owningtable])) {
                $link = self::foreignkey_tables()[$owningtable];
                $ids = $parents[$link['via']] ?? [];
                if (!$ids) {
                    continue;
                }
                list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'a');
                $itemids = $DB->get_fieldset_select(
                    $owningtable, 'id', $link['column'] . ' ' . $insql, $inparams);
            } else {
                continue;
            }
            self::delete_files_for_items($owningtable, $itemids);
        }

        // 3. Foreign-key children.
        foreach (self::foreignkey_tables() as $table => $link) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $ids = $parents[$link['via']] ?? [];
            if (!$ids) {
                continue;
            }
            list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'f');
            $DB->delete_records_select($table, $link['column'] . ' ' . $insql, $params);
        }

        // 4. Rows reachable only through the student id, before the student row goes.
        $studentids = $parents['local_rtocompliance_students'] ?? [];
        if ($studentids) {
            list($ssql, $sparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'sl');
            foreach (self::secondary_student_links() as $table => $column) {
                if ($DB->get_manager()->table_exists($table)) {
                    $DB->delete_records_select($table, "$column $ssql", $sparams);
                }
            }
        }

        // 5. Subject rows.
        foreach (self::subject_tables() as $table => $columns) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $DB->delete_records_select($table, "$column $usql", $uparams);
            }
        }

        // 6. Authorship rows are deliberately NOT deleted - see the class docblock.
    }

    /**
     * Purge the file areas attached to a set of rpl record ids.
     *
     * @param array $rplids
     */
    protected static function delete_files_for_items(string $owningtable, array $itemids) {
        global $DB;
        if (!$itemids || !$DB->get_manager()->table_exists($owningtable)) {
            return;
        }
        $areas = array_keys(array_filter(
            self::personal_file_areas(), fn($t) => $t === $owningtable));
        if (!$areas) {
            return;
        }
        $fs = get_file_storage();
        $sysctxid = \context_system::instance()->id;
        foreach ($itemids as $itemid) {
            foreach ($areas as $area) {
                $fs->delete_area_files($sysctxid, 'local_rtocompliance', $area, (int) $itemid);
            }
        }
    }

    /**
     * Purge every personal file area outright, for the whole-context erasure path.
     */
    protected static function delete_all_personal_files() {
        $fs = get_file_storage();
        $sysctxid = \context_system::instance()->id;
        foreach (array_keys(self::personal_file_areas()) as $area) {
            foreach ($fs->get_area_files($sysctxid, 'local_rtocompliance', $area,
                    false, 'id', false) as $file) {
                $file->delete();
            }
        }
    }
}
