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
 * RTO Compliance plugin — provider.php.
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

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        // v6.3.14: the AI assistant sends the staff member's question to the lms-labs.com
        // broker, and — when "Let the assistant see this site's data" is on — a short
        // read-only summary of this site with it. That summary can name the ONE student whose
        // page the staff member is viewing, together with whether their USI is verified, so
        // the assistant can explain why a certificate cannot be issued for them. No student
        // list and no other personal field is sent, and nothing is stored at the broker on
        // this plugin's behalf. Turning the setting off removes it entirely.
        $collection->add_external_location_link(
            'lms_labs_assistant',
            [
                'question'  => 'privacy:metadata:assistant:question',
                'sitefacts' => 'privacy:metadata:assistant:sitefacts',
            ],
            'privacy:metadata:assistant'
        );

        // Bug 26: Declare ALL personal data fields stored in the students table.
        // The previous declaration was missing: firstname, lastname, sex, address
        // fields, phone, email — all of which are sensitive AVETMISS personal data.
        $collection->add_database_table(
            'local_rtocompliance_students',
            [
                'userid'              => 'privacy:metadata:students:userid',
                'usi'                 => 'privacy:metadata:students:usi',
                'firstname'           => 'privacy:metadata:students:firstname',
                'lastname'            => 'privacy:metadata:students:lastname',
                'sex'                 => 'privacy:metadata:students:sex',
                'dateofbirth'         => 'privacy:metadata:students:dateofbirth',
                'indigenousstatus'    => 'privacy:metadata:students:indigenousstatus',
                'countryofbirth'      => 'privacy:metadata:students:countryofbirth',
                'disabilityflag'      => 'privacy:metadata:students:disabilityflag',
                'surveycontactphone'  => 'privacy:metadata:students:surveycontactphone',
                'surveycontactemail'  => 'privacy:metadata:students:surveycontactemail',
                'buildingname'        => 'privacy:metadata:students:buildingname',
                'unitno'              => 'privacy:metadata:students:unitno',
                'streetno'            => 'privacy:metadata:students:streetno',
                'streetname'          => 'privacy:metadata:students:streetname',
                'suburb'              => 'privacy:metadata:students:suburb',
                'postcode'            => 'privacy:metadata:students:postcode',
                'statecode'           => 'privacy:metadata:students:statecode',
            ],
            'privacy:metadata:students'
        );

        $collection->add_database_table(
            'local_rtocompliance_enrolments',
            [
                'studentid' => 'privacy:metadata:enrolments:studentid',
                'courseid' => 'privacy:metadata:enrolments:courseid',
                'outcomeidentifier' => 'privacy:metadata:enrolments:outcomeidentifier',
                'activitystartdate' => 'privacy:metadata:enrolments:activitystartdate',
            ],
            'privacy:metadata:enrolments'
        );

        $collection->add_database_table(
            'local_rtocompliance_trainers',
            [
                'userid' => 'privacy:metadata:trainers:userid',
                'taecredential' => 'privacy:metadata:trainers:taecredential',
                'vocationalqualifications' => 'privacy:metadata:trainers:vocationalqualifications',
                'industrycurrency' => 'privacy:metadata:trainers:industrycurrency',
                'cpdhours' => 'privacy:metadata:trainers:cpdhours',
            ],
            'privacy:metadata:trainers'
        );

        $collection->add_database_table(
            'local_rtocompliance_certs',
            [
                'userid' => 'privacy:metadata:certs:userid',
                'certnumber' => 'privacy:metadata:certs:certnumber',
                'certtype' => 'privacy:metadata:certs:certtype',
                'qualificationname' => 'privacy:metadata:certs:qualificationname',
                'issuedate' => 'privacy:metadata:certs:issuedate',
            ],
            'privacy:metadata:certs'
        );

        $collection->add_database_table(
            'local_rtocompliance_surveys',
            [
                'respondentid' => 'privacy:metadata:surveys:respondentid',
                'responses' => 'privacy:metadata:surveys:responses',
                'comments' => 'privacy:metadata:surveys:comments',
            ],
            'privacy:metadata:surveys'
        );

        $collection->add_database_table(
            'local_rtocompliance_log',
            [
                'userid' => 'privacy:metadata:log:userid',
                'action' => 'privacy:metadata:log:action',
                'ipaddress' => 'privacy:metadata:log:ipaddress',
            ],
            'privacy:metadata:log'
        );

        $collection->add_database_table(
            'local_rtocompliance_cricos_students',
            [
                'userid' => 'privacy:metadata:cricos_students:userid',
                'visasubclass' => 'privacy:metadata:cricos_students:visasubclass',
                'passportnumber' => 'privacy:metadata:cricos_students:passportnumber',
                'guardianname' => 'privacy:metadata:cricos_students:guardianname',
            ],
            'privacy:metadata:cricos_students'
        );

        $collection->add_database_table(
            'local_rtocompliance_cricos_coe',
            [
                'cricosstudentid' => 'privacy:metadata:cricos_coe:cricosstudentid',
                'coenumber'       => 'privacy:metadata:cricos_coe:coenumber',
                'coursestartdate' => 'privacy:metadata:cricos_coe:coursestartdate',
            ],
            'privacy:metadata:cricos_coe'
        );

        // Bug 27: Register tables that contain personal data but were missing from
        // the GDPR metadata declaration. These tables store names, contact details,
        // and outcome information that constitute personal data under the Privacy Act.
        $collection->add_database_table(
            'local_rtocompliance_complaints',
            [
                'complainantname'  => 'privacy:metadata:complaints:complainantname',
                'complainantemail' => 'privacy:metadata:complaints:complainantemail',
                'complainantphone' => 'privacy:metadata:complaints:complainantphone',
                'description'      => 'privacy:metadata:complaints:description',
                'resolution'       => 'privacy:metadata:complaints:resolution',
            ],
            'privacy:metadata:complaints'
        );

        $collection->add_database_table(
            'local_rtocompliance_appeals',
            [
                'appellantname'  => 'privacy:metadata:appeals:appellantname',
                'appellantemail' => 'privacy:metadata:appeals:appellantemail',
                'appellantphone' => 'privacy:metadata:appeals:appellantphone',
                'groundsforappeal' => 'privacy:metadata:appeals:groundsforappeal',
                'outcome'        => 'privacy:metadata:appeals:outcome',
            ],
            'privacy:metadata:appeals'
        );

        $collection->add_database_table(
            'local_rtocompliance_rpl',
            [
                'studentid'   => 'privacy:metadata:rpl:studentid',
                'evidence'    => 'privacy:metadata:rpl:evidence',
                'decision'    => 'privacy:metadata:rpl:decision',
                'decisiondate' => 'privacy:metadata:rpl:decisiondate',
            ],
            'privacy:metadata:rpl'
        );

        // RPL-FILE-ERASURE (v5.9.416): declare the file subsystem — RPL evidence and
        // credit-transfer source certificates are stored as files against rpl records.
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $sql = "SELECT DISTINCT userid FROM {local_rtocompliance_students}";
        $userlist->add_from_sql('userid', $sql, []);

        $sql = "SELECT DISTINCT userid FROM {local_rtocompliance_trainers}";
        $userlist->add_from_sql('userid', $sql, []);

        $sql = "SELECT DISTINCT userid FROM {local_rtocompliance_certs}";
        $userlist->add_from_sql('userid', $sql, []);

        $sql = "SELECT DISTINCT respondentid FROM {local_rtocompliance_surveys} WHERE respondentid IS NOT NULL";
        $userlist->add_from_sql('respondentid', $sql, []);

        $sql = "SELECT DISTINCT userid FROM {local_rtocompliance_cricos_students}";
        $userlist->add_from_sql('userid', $sql, []);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $context = \context_system::instance();

        $students = $DB->get_records('local_rtocompliance_students', ['userid' => $user->id]);
        if ($students) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'students'],
                (object) ['students' => array_values($students)]
            );
        }

        $trainers = $DB->get_records('local_rtocompliance_trainers', ['userid' => $user->id]);
        if ($trainers) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'trainers'],
                (object) ['trainers' => array_values($trainers)]
            );
        }

        $certs = $DB->get_records('local_rtocompliance_certs', ['userid' => $user->id]);
        if ($certs) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'certificates'],
                (object) ['certificates' => array_values($certs)]
            );
        }

        $surveys = $DB->get_records('local_rtocompliance_surveys', ['respondentid' => $user->id]);
        if ($surveys) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'surveys'],
                (object) ['surveys' => array_values($surveys)]
            );
        }

        $cricos = $DB->get_records('local_rtocompliance_cricos_students', ['userid' => $user->id]);
        if ($cricos) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'cricos_students'],
                (object) ['cricos_students' => array_values($cricos)]
            );
        }

        // FIX-PRIVACY-MISSING-TABLES (v5.9.274): export enrolments, RPL, complaints,
        // and appeals — all declared in get_metadata() but previously omitted here,
        // meaning a GDPR Subject Access Request would silently miss this data.
        //
        // enrolments + rpl are keyed by studentid (FK → local_rtocompliance_students),
        // not directly by Moodle userid, so look up the student record first.
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $user->id]);
        if ($student) {
            $enrolments = $DB->get_records('local_rtocompliance_enrolments', ['studentid' => $student->id]);
            if ($enrolments) {
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_rtocompliance'), 'enrolments'],
                    (object) ['enrolments' => array_values($enrolments)]
                );
            }
            $rpls = $DB->get_records('local_rtocompliance_rpl', ['studentid' => $student->id]);
            if ($rpls) {
                \core_privacy\local\request\writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_rtocompliance'), 'rpl'],
                    (object) ['rpl' => array_values($rpls)]
                );
            }
        }
        // complaints and appeals link directly via complainantuserid / appellantuserid.
        $complaints = $DB->get_records('local_rtocompliance_complaints', ['complainantuserid' => $user->id]);
        if ($complaints) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'complaints'],
                (object) ['complaints' => array_values($complaints)]
            );
        }
        $appeals = $DB->get_records('local_rtocompliance_appeals', ['appellantuserid' => $user->id]);
        if ($appeals) {
            \core_privacy\local\request\writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_rtocompliance'), 'appeals'],
                (object) ['appeals' => array_values($appeals)]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('local_rtocompliance_log');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        // FIX-PRIVACY-MISSING-TABLES (v5.9.274): delete enrolments and RPL via
        // studentid FK before deleting the student row itself, and delete
        // complaints + appeals via their direct userid columns.
        $student = $DB->get_record('local_rtocompliance_students', ['userid' => $user->id]);
        if ($student) {
            // RPL-FILE-ERASURE (v5.9.416): the RPL/CT evidence and source-certificate
            // files are stored in system-context fileareas keyed by the rpl record id.
            // Deleting only the DB rows left the student's uploaded evidence files in
            // storage on an erasure request — a privacy defect. Purge those files for
            // every rpl record belonging to this student before deleting the rows.
            $rplids = $DB->get_fieldset_select('local_rtocompliance_rpl', 'id', 'studentid = :sid', ['sid' => $student->id]);
            if ($rplids) {
                $fs = get_file_storage();
                $sysctxid = \context_system::instance()->id;
                foreach ($rplids as $rplid) {
                    $fs->delete_area_files($sysctxid, 'local_rtocompliance', 'rpl_evidence', $rplid);
                    $fs->delete_area_files($sysctxid, 'local_rtocompliance', 'ct_sourcecert', $rplid);
                }
            }
            $DB->delete_records('local_rtocompliance_enrolments', ['studentid' => $student->id]);
            $DB->delete_records('local_rtocompliance_rpl',        ['studentid' => $student->id]);
        }
        $DB->delete_records('local_rtocompliance_complaints', ['complainantuserid' => $user->id]);
        $DB->delete_records('local_rtocompliance_appeals',    ['appellantuserid'   => $user->id]);
        $DB->delete_records('local_rtocompliance_students',   ['userid' => $user->id]);
        $DB->delete_records('local_rtocompliance_trainers',   ['userid' => $user->id]);
        $DB->delete_records('local_rtocompliance_certs',      ['userid' => $user->id]);
        $DB->delete_records('local_rtocompliance_surveys',    ['respondentid' => $user->id]);
        $DB->delete_records('local_rtocompliance_log',        ['userid' => $user->id]);
        $DB->delete_records('local_rtocompliance_cricos_students', ['userid' => $user->id]);
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // FIX-PRIVACY-MISSING-TABLES (v5.9.275): enrolments + RPL use a studentid FK,
        // so look up all matching student IDs first, then delete in one IN() query.
        $studentids = $DB->get_fieldset_select(
            'local_rtocompliance_students', 'id', "userid $insql", $params
        );
        if (!empty($studentids)) {
            list($stinsql, $stparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stid');
            // RPL-FILE-ERASURE (v5.9.416): purge RPL/CT evidence + source-certificate
            // files (system-context fileareas keyed by rpl id) before deleting the rows.
            $rplids = $DB->get_fieldset_select('local_rtocompliance_rpl', 'id', "studentid $stinsql", $stparams);
            if ($rplids) {
                $fs = get_file_storage();
                $sysctxid = \context_system::instance()->id;
                foreach ($rplids as $rplid) {
                    $fs->delete_area_files($sysctxid, 'local_rtocompliance', 'rpl_evidence', $rplid);
                    $fs->delete_area_files($sysctxid, 'local_rtocompliance', 'ct_sourcecert', $rplid);
                }
            }
            $DB->delete_records_select('local_rtocompliance_enrolments', "studentid $stinsql", $stparams);
            $DB->delete_records_select('local_rtocompliance_rpl',        "studentid $stinsql", $stparams);
        }
        $DB->delete_records_select('local_rtocompliance_complaints', "complainantuserid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_appeals',    "appellantuserid $insql",   $params);
        $DB->delete_records_select('local_rtocompliance_students',   "userid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_trainers',   "userid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_certs',      "userid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_surveys',    "respondentid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_log',        "userid $insql", $params);
        $DB->delete_records_select('local_rtocompliance_cricos_students', "userid $insql", $params);
    }
}
